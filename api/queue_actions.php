<?php
// فایل: api/queue_actions.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($data['action'] ?? '');

function clean_path_string($str) {
    return trim(preg_replace('/[\\\\\\/*?:"<>|\r\n\t]/', '', (string)$str));
}

// معادل PHP همان استخراج «هسته‌ی پلاک» (بدون فاصله/ایران) برای تطبیق دو فرمت مختلف نمایش پلاک
function plate_core_php($text) {
    $text = str_replace([' ', 'ایران'], '', (string)$text);
    if (preg_match('/(\d{2})([آ-ی])(\d{3})/u', $text, $m)) {
        return $m[1] . $m[2] . $m[3];
    }
    return null;
}

// نگاشت نوع فارسیِ استخراج‌شده به Enum دیتابیس (فقط برای «ثالث» و «بدنه»)
function map_insurance_enum($ins_type_fa) {
    if ($ins_type_fa === 'بدنه') return 'BODY';
    if ($ins_type_fa === 'ثالث') return 'THIRDPARTY';
    return null;
}

// ---- کمکی‌های تماس با API بله (با لاگ کامل برای عیب‌یابی واقعی) ----
function bale_log($label, $data) {
    $logFile = dirname(__DIR__) . '/queue/bale_debug.log';
    $line = '[' . date('Y-m-d H:i:s') . "] $label: " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE)) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function bale_api_call($method, $payload, $token) {
    if (!$token) { bale_log("$method skipped", 'no bot token'); return null; }
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/" . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    bale_log($method, ['payload' => $payload, 'response' => $res, 'curl_error' => $curlErr ?: null]);
    $decoded = json_decode($res, true);
    return is_array($decoded) ? $decoded : null;
}

function get_bot_token($pdo) {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bot_token'");
    return $stmt->fetchColumn() ?: null;
}

// ویرایش پیام «دریافت شد» و جایگزینی آن با متن نهایی؛ اگر ویرایش به هر دلیلی
// شکست خورد (مثلاً پیام خیلی قدیمی شده)، به‌جای آن پیام را حذف و دوباره ارسال می‌کند
function bale_replace_message($chat_id, $old_message_id, $reply_to_message_id, $new_text, $token) {
    if ($old_message_id) {
        $editResult = bale_api_call('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $old_message_id,
            'text' => $new_text,
        ], $token);
        if (!empty($editResult['ok'])) return; // موفق بود، کار تمام است
    }
    // نتوانستیم ویرایش کنیم (یا اصلاً پیامی برای ویرایش نداشتیم) -> حذف + ارسال دوباره
    if ($old_message_id) {
        bale_api_call('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $old_message_id], $token);
    }
    bale_api_call('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $new_text,
        'reply_to_message_id' => $reply_to_message_id,
    ], $token);
}


// یک درخواست باز (غیرلغوشده/غیرصادرشده) برای این شخص پیدا می‌کند تا مدرک
// جدید به آن پیوست شود. اگر enum مشخصی خواسته شده باشد، اول با همان نوع
// جست‌وجو می‌کند؛ در غیر این صورت جدیدترین درخواست باز از هر نوعی را برمی‌گرداند
// (مثلاً برای «کارت ماشین» که خودش نوع بیمه مستقلی نیست).
function find_open_request($pdo, $person_id, $enum_type = null) {
    if ($enum_type) {
        $stmt = $pdo->prepare("SELECT id FROM insurance_requests WHERE person_id = ? AND insurance_type = ? AND status NOT IN ('ISSUED','CANCELLED') ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person_id, $enum_type]);
        $id = $stmt->fetchColumn();
        if ($id) return $id;
    }
    $stmt = $pdo->prepare("SELECT id FROM insurance_requests WHERE person_id = ? AND status NOT IN ('ISSUED','CANCELLED') ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$person_id]);
    $id = $stmt->fetchColumn();
    return $id ?: null;
}

try {
    // ۱. لیست کردن فایل‌های صف برای نمایش در پنل
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT * FROM processing_queue ORDER BY uploaded_at DESC");
        $records = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'data' => $records]);
        exit;
    }

    // ۲. رد کردن فایل (حذف فیزیکی و حذف از صف)
    if ($action === 'reject') {
        $qid = intval($data['queue_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT file_path FROM processing_queue WHERE id = ?");
        $stmt->execute([$qid]);
        $filePath = $stmt->fetchColumn();

        if ($filePath && file_exists("../" . $filePath)) {
            unlink("../" . $filePath); // حذف فایل فیزیکی
        }

        $pdo->prepare("DELETE FROM processing_queue WHERE id = ?")->execute([$qid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ۳. تایید نهایی و انتقال به پرونده اصلی کاربر
    if ($action === 'approve') {
        $qid = intval($data['queue_id'] ?? 0);
        $ins_type     = trim($data['ins_type'] ?? 'ناشناخته');
        $full_name    = trim($data['insured_name'] ?? 'نامشخص');
        $national_id  = trim($data['national_id'] ?? '');
        $personnel_id = trim($data['personnel_id'] ?? '-');
        $company_name = trim($data['company_name'] ?? '');
        $letter_date  = trim($data['letter_date'] ?? '');

        // فیلدهای تکمیلی بیمه‌نامه (بدنه/ثالث پاسارگاد) - هرکدام نبود خالی می‌ماند
        $extraFields = [
            'policy_num'      => trim($data['policy_num'] ?? ''),
            'unique_code'     => trim($data['unique_code'] ?? ''),
            'phone'           => trim($data['phone'] ?? ''),
            'plate'           => trim($data['plate'] ?? ''),
            'vin'             => trim($data['vin'] ?? ''),
            'chassis_num'     => trim($data['chassis_num'] ?? ''),
            'engine_num'      => trim($data['engine_num'] ?? ''),
            'issue_date'      => trim($data['issue_date'] ?? ''),
            'model_year'      => trim($data['model_year'] ?? ''),
            'car_system'      => trim($data['car_system'] ?? ''),
            'car_type'        => trim($data['car_type'] ?? ''),
            'car_color'       => trim($data['car_color'] ?? ''),
            'car_value'       => trim($data['car_value'] ?? ''),
            'total_premium'   => trim($data['total_premium'] ?? ''),
            'renewal_status'  => trim($data['renewal_status'] ?? ''),
            'prev_policy'     => trim($data['prev_policy'] ?? ''),
            'coverages'       => $data['coverages'] ?? new stdClass(),
        ];

        if (empty($national_id)) {
            echo json_encode(['ok' => false, 'error' => 'کد ملی برای تایید و بایگانی الزامی است.']);
            exit;
        }

        $pdo->beginTransaction();

        // بررسی مسیر فایل فعلی در صف + مشخصات فرستنده (برای اتصال چت به شخص و ریپلای در بله)
        $stmt = $pdo->prepare("SELECT file_path, uploaded_by, tg_message_id, tg_notify_message_id FROM processing_queue WHERE id = ?");
        $stmt->execute([$qid]);
        $queueRow = $stmt->fetch();
        $currentFilePath = $queueRow['file_path'] ?? null;
        $senderChatId = $queueRow['uploaded_by'] ?? null;
        $tgMessageId = $queueRow['tg_message_id'] ?? null;
        $tgNotifyMessageId = $queueRow['tg_notify_message_id'] ?? null;

        if (!$currentFilePath || !file_exists("../" . $currentFilePath)) {
            throw new Exception("فایل فیزیکی در سرور یافت نشد.");
        }

        // ثبت یا آپدیت پرسنل
        // نکته‌ی مهم: senderChatId همان آیدی چتِ «گروه کارکنان» است (جایی که معرفی‌نامه
        // آپلود شده)، نه چت خصوصی خودِ فرد. پس نباید در bale_chat_id ذخیره شود؛
        // bale_chat_id فقط وقتی ست می‌شود که خودِ شخص با کد ملی‌اش در ربات /start بزند.
        $stmt = $pdo->prepare("SELECT id FROM persons WHERE national_code = ?");
        $stmt->execute([$national_id]);
        $person_id = $stmt->fetchColumn();

        if (!$person_id) {
            $stmt = $pdo->prepare("INSERT INTO persons (national_code, personnel_code, full_name) VALUES (?, ?, ?)");
            $stmt->execute([$national_id, $personnel_id, $full_name]);
            $person_id = $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare("UPDATE persons SET full_name = ? WHERE id = ?");
            $stmt->execute([$full_name, $person_id]);
        }

        // ریشه‌ی سایت (برای همه‌ی مسیرهای بایگانی/موقت جدید)
        $siteRoot = dirname(__DIR__);
        $archiveRootDir = archive_root($siteRoot); // فقط برای «سایر مدارک» fallback هنوز استفاده می‌شود
        $archivedOnlyNote = null;

        if ($ins_type === 'معرفی‌نامه') {
            // ---- معرفی‌نامه پرسنلی -> ایجاد پوشه‌ی شخص (بایگانی کسر از حقوق) + پرونده جدید ----

            // ثبت/اتصال شرکت (قبل از ساخت پوشه، تا نامِ شرکت در پوشه درست باشد)
            if ($company_name !== '') {
                $stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
                $stmt->execute([$company_name]);
                $company_id = $stmt->fetchColumn();
                if (!$company_id) {
                    $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
                    $stmt->execute([$company_name]);
                    $company_id = $pdo->lastInsertId();
                }
                $pdo->prepare("UPDATE persons SET company_id = ? WHERE id = ?")->execute([$company_id, $person_id]);
            }

            // letter_date به شکل جلالی «۱۴۰۵/۱۲/۰۶» می‌آید؛ فقط جداکننده را برای ستون DATE عوض می‌کنیم
            // رقم فارسی/عربی را لاتین می‌کنیم وگرنه ستون DATE آن را رد می‌کند
            $ld = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
                              ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'], $letter_date);
            $letter_date_sql = preg_match('/^(\d{4})\D+(\d{1,2})\D+(\d{1,2})$/', $ld, $ldm)
                ? sprintf('%04d-%02d-%02d', $ldm[1], $ldm[2], $ldm[3]) : null;

            // سقف سهمیه از تنظیمات پنل خوانده می‌شود (پیش‌فرض ۴، ولی قابل تغییر است)
            $quotaStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_intro_quota'");
            $defaultQuota = intval($quotaStmt->fetchColumn() ?: 4);

            $stmt = $pdo->prepare("INSERT INTO introductions (person_id, file_path, letter_date, max_quota, group_chat_id) VALUES (?, '', ?, ?, ?)");
            $stmt->execute([$person_id, $letter_date_sql, $defaultQuota, $senderChatId]);
            $intro_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO insurance_requests (person_id, introduction_id, status) VALUES (?, ?, 'NEW')");
            $stmt->execute([$person_id, $intro_id]);

            // پوشه‌ی «بایگانی کسر از حقوق» را می‌سازد (پویا: نام همیشه با آخرین اطلاعات هماهنگ است)
            $introFolder = sync_intro_folder($pdo, $siteRoot, $intro_id);
            $introDocFolder = $introFolder . '/معرفی‌نامه';

            $ext = pathinfo($currentFilePath, PATHINFO_EXTENSION);
            $introFileName = build_intro_folder_name($full_name, $national_id, $personnel_id, $company_name) . '.' . $ext;
            $finalDiskPath = unique_dest_path($introDocFolder . '/' . $introFileName);
            rename("../" . $currentFilePath, $finalDiskPath);
            $dbPath = ltrim(str_replace($siteRoot, '', $finalDiskPath), '/');

            $pdo->prepare("UPDATE introductions SET file_path = ? WHERE id = ?")->execute([$dbPath, $intro_id]);

        } else {
            // ---- فایل بیمه‌نامه‌ی نهایی (بدنه/ثالث) که از گروه کارکنان رسیده ----
            // سعی می‌کنیم آن را به یک «پرونده‌ی صدور» (policy_cases) که در حال تکمیل
            // مدارک یا در حال صدور است، وصل کنیم (بر اساس پلاک یا کد ملی).
            $plate = $extraFields['plate'];
            $matchedCase = null;

            if ($plate !== '') {
                $plateCore = plate_core_php($plate);
                if ($plateCore) {
                    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE status IN ('DOCS_REVIEW','ISSUING') AND plate IS NOT NULL");
                    $stmt->execute();
                    foreach ($stmt->fetchAll() as $c) {
                        if (plate_core_php($c['plate']) === $plateCore) { $matchedCase = $c; break; }
                    }
                }
            }
            if (!$matchedCase && $national_id !== '') {
                $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE status IN ('DOCS_REVIEW','ISSUING') AND insured_national_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$national_id]);
                $matchedCase = $stmt->fetch() ?: null;
            }

            if ($matchedCase && has_pending_health_report($pdo, $matchedCase['id'])) {
                // ⚠️ این پلاک بازدید سلامتِ تاییدشده دارد ولی هنوز گزارش کارشناس آپلود نشده.
                // فایل را گم نمی‌کنیم؛ در پوشه‌ی موقتِ همان پلاک نگه می‌داریم و هشدار می‌دهیم.
                $tempPlateFolder = build_temp_plate_path($siteRoot, time(), $matchedCase['insured_national_id'], $matchedCase['plate']);
                if (!file_exists($tempPlateFolder)) mkdir($tempPlateFolder, 0777, true);
                $ext = pathinfo($currentFilePath, PATHINFO_EXTENSION);
                $pendingPolicyPath = unique_dest_path($tempPlateFolder . '/بیمه‌نامه (در انتظار گزارش بازدید).' . $ext);
                rename("../" . $currentFilePath, $pendingPolicyPath);

                $botTokenStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bot_token'");
                $botToken = $botTokenStmt->fetchColumn();
                $warnMsg = "⚠️ برای پلاک «{$matchedCase['plate']}» بازدید سلامت تایید شده ولی فایل «گزارش کارشناس» هنوز از پنل آپلود نشده.\nلطفاً ابتدا گزارش را از تب «بازدید سلامت» آپلود کنید، سپس دوباره فایل بیمه‌نامه را همین‌جا ارسال کنید.";
                $ch = curl_init("https://tapi.bale.ai/bot" . $botToken . "/sendMessage");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $senderChatId, 'text' => $warnMsg, 'reply_to_message_id' => $tgMessageId], JSON_UNESCAPED_UNICODE));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_exec($ch); curl_close($ch);

                $archivedOnlyNote = 'فایل به‌دلیل نبودِ گزارش بازدید سلامت، موقتاً بایگانی نشد و در پنل به کارشناس هشدار داده شد.';

            } elseif ($matchedCase) {
                $caseFolder = resolve_case_folder($pdo, $siteRoot, $matchedCase);
                if (!file_exists($caseFolder)) mkdir($caseFolder, 0777, true);

                // شماره بیمه‌نامه با همان کاراکتر استاندارد (∕) به‌جای اسلش، برای سازگاری با ویندوز
                $policyNumForName = str_replace('/', '∕', $extraFields['policy_num']);

                $ext = pathinfo($currentFilePath, PATHINFO_EXTENSION);
                $finalFileName = build_final_policy_filename($plate ?: $matchedCase['plate'], $matchedCase['insured_name'] ?: $full_name, $policyNumForName, $extraFields['vin'], $ext);
                $finalDiskPath = unique_dest_path($caseFolder . '/' . $finalFileName);
                rename("../" . $currentFilePath, $finalDiskPath);

                // پوشه‌ی پرونده را هم به همین نام کامل تغییر می‌دهیم (طبق فرمت مصوب)
                $renamedFolderName = build_case_folder_name_issued($plate ?: $matchedCase['plate'], $matchedCase['insured_name'] ?: $full_name, $policyNumForName, $extraFields['vin']);
                $renamedFolderPath = dirname($caseFolder) . '/' . $renamedFolderName;
                if ($renamedFolderPath !== $caseFolder) {
                    @rename($caseFolder, $renamedFolderPath);
                    // مسیر فایل بیمه‌نامه هم چون داخل پوشه بود، دنبال پوشه جابه‌جا شد
                    $finalDiskPath = str_replace($caseFolder, $renamedFolderPath, $finalDiskPath);
                    $caseFolder = $renamedFolderPath;
                }
                $dbPath = ltrim(str_replace($siteRoot, '', $finalDiskPath), '/');

                $pdo->prepare("UPDATE policy_cases SET status = 'ISSUED', plate = COALESCE(plate, ?), folder_path = ?, issued_file_path = ? WHERE id = ?")
                    ->execute([$plate, $caseFolder, $dbPath, $matchedCase['id']]);
                notify_case_stage($pdo, $matchedCase['id'], 'بیمه‌نامه صادر شده ✅');
                $pdo->prepare("UPDATE introductions SET used_quota = used_quota + 1 WHERE id = ?")->execute([$matchedCase['introduction_id']]);

                // کپی (نه انتقال) پوشه‌ی کامل پرونده به «بایگانی صادره»، دسته‌بندی‌شده بر اساس تاریخ صدور و نوع بیمه
                $sadereBase = build_sadere_base_path($siteRoot, time(), $matchedCase['insurance_type']);
                copy_dir_recursive($caseFolder, $sadereBase . '/' . basename($caseFolder));

                // تعداد صادره‌ی معرفی‌نامه تغییر کرد -> نام پوشه‌ی معرفی‌نامه را هم بروز می‌کنیم
                sync_intro_folder($pdo, $siteRoot, $matchedCase['introduction_id']);

                // اطلاع به مشتری (هم پیام بله، هم ثبت در مرکز اعلان‌های اپ)
                $personChat = $pdo->prepare("SELECT bale_chat_id FROM persons WHERE id = ?");
                $personChat->execute([$matchedCase['person_id']]);
                $custChatId = $personChat->fetchColumn();
                $msg = "🎉 بیمه‌نامه‌ی شما با موفقیت صادر شد!\nشماره پرونده: " . $matchedCase['unique_code'] . "\nمی‌توانید فایل آن را از «بیمه‌نامه‌های من» دریافت کنید.";
                notify_customer_app($pdo, $matchedCase['person_id'], $custChatId, "بیمه‌نامه صادر شد", $msg, 'success', $matchedCase['id']);
            } else {
                // هیچ پرونده‌ی صدورِ در حال انجامی پیدا نشد -> فقط بایگانی می‌شود
                $genericFolder = $archiveRootDir . '/سایر مدارک/' . clean_path_string($full_name ?: 'نامشخص');
                if (!file_exists($genericFolder)) mkdir($genericFolder, 0777, true);
                $ext = pathinfo($currentFilePath, PATHINFO_EXTENSION);
                $finalDiskPath = $genericFolder . '/' . time() . '_' . clean_path_string($ins_type) . '.' . $ext;
                rename("../" . $currentFilePath, $finalDiskPath);
                $archivedOnlyNote = 'هیچ پرونده‌ی صدور در حال انجامی برای این پلاک/کد ملی یافت نشد؛ فایل فقط در «سایر مدارک» بایگانی شد.';
            }
        }

        // حذف فایل از صف پردازش
        $pdo->prepare("DELETE FROM processing_queue WHERE id = ?")->execute([$qid]);

        $pdo->commit();

        // ---- جایگزینی پیام «دریافت شد» با پیام تفصیلی «ثبت شد» در بله (فقط برای معرفی‌نامه) ----
        // این بخش عمداً در try/catch جدا قرار گرفته: اگر تماس با بله به هر دلیلی
        // (قطعی شبکه، توکن نامعتبر و ...) شکست بخورد، نباید باعث شود پاسخ به
        // پنل «خطا» نشان دهد، چون ایجاد پرونده در دیتابیس از قبل با موفقیت انجام و commit شده است.
        if ($ins_type === 'معرفی‌نامه' && $senderChatId) {
            try {
                $bot_token = get_bot_token($pdo);
                $text = render_intro_status_message($pdo, $intro_id);
                // اولین‌بار: پیام «دریافت شد» قبلی را با این پیام کامل جایگزین می‌کنیم و شناسه‌اش را ذخیره می‌کنیم
                // تا مراحل بعدی (مراجعه پرسنل و ...) همین پیام را ویرایش کنند، نه پیام جدید بفرستند
                bale_replace_message($senderChatId, $tgNotifyMessageId, $tgMessageId, $text, $bot_token);
                // شناسه‌ی پیامِ نهایی را برای ویرایش‌های بعدی ذخیره می‌کنیم؛ اگر ویرایش موفق بود همان
                // $tgNotifyMessageId معتبر است، وگرنه پیام تازه‌ای فرستاده شده که شناسه‌اش را نمی‌دانیم
                // (در آن حالت نادر، دفعه‌ی بعد دوباره پیام تازه می‌فرستد که قابل قبول است)
                if ($tgNotifyMessageId) {
                    $pdo->prepare("UPDATE introductions SET status_message_id = ? WHERE id = ?")->execute([$tgNotifyMessageId, $intro_id]);
                }
            } catch (Exception $notifyErr) {
                bale_log('notify_exception', $notifyErr->getMessage());
            }
        }

        echo json_encode([
            'ok' => true,
            'message' => 'فایل با موفقیت تایید و بایگانی شد.' . ($archivedOnlyNote ? ' (' . $archivedOnlyNote . ')' : ''),
        ]);
        exit;
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[queue_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
