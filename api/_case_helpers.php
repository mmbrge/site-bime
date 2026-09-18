<?php
// فایل: api/_case_helpers.php
// قوانین نام‌گذاری پوشه‌ها و فایل‌ها برای سیستم «صدور بیمه‌نامه».
// این فایل فقط تابع دارد و DB باز نمی‌کند؛ در هر اسکریپتی که لازم شود include می‌شود.

function jalali_from_gregorian_ts($ts) {
    $gy = intval(date('Y', $ts)); $gm = intval(date('n', $ts)); $gd = intval(date('j', $ts));
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
    return [$jy, $jm, $jd];
}

function jalali_from_gregorian_ts_dotted($ts) {
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($ts);
    return sprintf('%04d.%02d.%02d', $jy, $jm, $jd);
}

function jalali_month_name($jm) {
    $names = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',
              7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
    return $names[$jm] ?? 'نامشخص';
}

function sanitize_folder_name($str) {
    $str = trim((string)$str);
    return preg_replace('/[<>:"\/\\\\|?*]/', '_', $str);
}

// =====================================================================
//  ریشه‌های اصلی روی دیسک: Archive/بایگانی (+ مالی، فعلاً بدون استفاده) و موقت/
// =====================================================================
function archive_root($siteRoot) { return $siteRoot . '/Archive/بایگانی'; }
function finance_root($siteRoot) { return $siteRoot . '/Archive/مالی'; }
function temp_archive_root($siteRoot) { return $siteRoot . '/موقت/موقت بایگانی'; }
function temp_finance_root($siteRoot) { return $siteRoot . '/موقت/موقت مالی'; }

// جداکننده‌ی اجزای نام در همه‌ی نام‌گذاری‌ها: فاصله + LRM (U+200E) + خط‌تیره + فاصله.
// نکته‌ی مهم: کاراکتر LRM جهتِ متن را در همان نقطه «قفل» می‌کند؛ بدون آن، ویندوز در نام‌های
// مخلوطِ فارسی/لاتین (مثل شماره‌ی بیمه‌نامه کنار نام فارسی) اجزا را جابه‌جا نشان می‌دهد و
// کاراکتر ∕ به‌شکل مربعِ کوچک دیده می‌شود. این دقیقاً همان روشی است که ابزار دسکتاپی
// (context_processor.py) استفاده می‌کند و در ویندوز درست می‌نشیند.
const NAME_SEP = " \u{200E}- ";

function name_join(array $parts) {
    $clean = array_filter($parts, fn($p) => $p !== null && trim((string)$p) !== '');
    return implode(" \u{200E}- ", array_map(fn($p) => trim((string)$p), $clean));
}

// نام پوشه‌ی معرفی‌نامه: «نام - کدملی - کدپرسنلی - شرکت» (بدون شمارنده - برای نام فایل خودِ معرفی‌نامه)
function build_intro_folder_name($fullName, $nationalCode, $personnelCode, $companyName) {
    return sanitize_folder_name(name_join([$fullName, $nationalCode, $personnelCode, $companyName]));
}

// همان، به‌علاوه‌ی شمارنده‌ی «تعداد بیمه‌نامه صادره» در انتها - برای نامِ خودِ پوشه (پویا و همیشه بروز)
function build_intro_folder_name_with_count($fullName, $nationalCode, $personnelCode, $companyName, $issuedCount) {
    return name_join([build_intro_folder_name($fullName, $nationalCode, $personnelCode, $companyName), intval($issuedCount)]);
}

// مسیر کامل پوشه‌ی معرفی‌نامه در «بایگانی کسر از حقوق»: .../{سال}/{ماه}/{پوشه شخص - تعداد صادره}
function build_kasr_base_path($siteRoot, $createdTimestamp, $fullName, $nationalCode, $personnelCode, $companyName, $issuedCount) {
    [$jy, $jm, ] = jalali_from_gregorian_ts($createdTimestamp);
    $monthName = jalali_month_name($jm);
    $folderName = build_intro_folder_name_with_count($fullName, $nationalCode, $personnelCode, $companyName, $issuedCount);
    return archive_root($siteRoot) . '/بایگانی کسر از حقوق/' . $jy . '/' . $monthName . '/' . $folderName;
}

// پوشه‌ی هر «پرونده/درخواست» پیش از صدور: «پلاک - نام بیمه‌گذار»
// (پس از صدور با نام کاملِ بیمه‌نامه تغییر نام می‌دهد - نگاه کنید به build_case_folder_name_issued)
function build_case_folder_name($plate, $insuredName = null) {
    return sanitize_folder_name(name_join([$plate ?: 'بدون‌پلاک', $insuredName]));
}

// پوشه‌ی پرونده پس از صدور: «پلاک - نام - شماره بیمه‌نامه - VIN»
function build_case_folder_name_issued($plate, $insuredName, $policyNum, $vin) {
    return sanitize_folder_name(name_join([$plate, $insuredName, $policyNum, $vin]));
}

// طبق توافق: شماره‌ی بیمه‌نامه معمولاً با «/» معمولی نوشته می‌شود، ولی چون این کاراکتر در نام
// پوشه/فایل ویندوز مجاز نیست، پیش از استفاده در نام‌گذاری باید با «∕» (U+2215 DIVISION SLASH -
// یک کاراکتر یونیکدِ کاملاً متفاوت، نه یک اسلش معمولی) جایگزین شود. نکته‌ی مهم: این تبدیل باید
// حتماً *قبل* از عبور از sanitize_folder_name انجام شود، چون آن تابع اسلش معمولی را با _ (زیرخط)
// جایگزین می‌کند که باعث از دست رفتن ساختار خوانای شماره‌ی بیمه‌نامه می‌شود.
function policy_number_for_filename($policyNum) {
    if (!$policyNum) return $policyNum;
    return str_replace('/', '∕', trim($policyNum));
}

// طبق توافق: در نام‌گذاریِ پوشه/فایل بیمه‌نامه‌ی صادرشده، پلاک بدون خط‌تیره‌ی داخلیِ خودش نوشته
// می‌شود (چون آن خط‌تیره با خط‌تیره‌ی جداکننده‌ی بین اجزای نام قاطی و گیج‌کننده می‌شود) - یعنی
// «68ایران - 711 د 16» در این نام‌گذاری خاص می‌شود «68ایران 711 د 16»
function plate_for_filename($plate) {
    if (!$plate) return $plate;
    return preg_replace('/\s*-\s*/', ' ', trim($plate));
}

// «هسته»ی پلاک را برای مقایسه‌ی دو پلاک با فرمت‌های نوشتاریِ متفاوت (با/بدون خط‌تیره، با/بدون
// فاصله) استخراج می‌کند - فقط رقم‌ها و حرف را نگه می‌دارد، «ایران» را حذف می‌کند
function plate_core($plate) {
    if (!$plate) return '';
    return str_replace(['ایران', ' ', '-', "\xE2\x80\x8E", "\xE2\x80\x8F"], '', $plate);
}

// اجرای پایپ‌لاین OCR/استخراج پایتون روی یک فایل PDF (همان الگویی که قبلاً برای معرفی‌نامه
// ساخته شده بود، حالا برای فایل بیمه‌نامه‌ی نهایی هم استفاده می‌شود) و برگرداندن آرایه‌ی PHP
// از نتیجه (یا null در صورت هر نوع خطا - مثلاً نبودِ محیط پایتون، یا PDF غیرقابل‌خواندن)
// اجرای پایپ‌لاین OCR/استخراج پایتون روی یک فایل PDF (همان الگویی که قبلاً برای معرفی‌نامه
// ساخته شده بود، حالا برای فایل بیمه‌نامه‌ی نهایی هم استفاده می‌شود).
// خروجی: آرایه‌ی ['data' => آرایه‌ی استخراج‌شده یا null, 'debug' => توضیح خطا برای عیب‌یابی]
// نکته: به‌جای برگرداندنِ خالیِ null، دلیل شکست هم برگردانده می‌شود تا وقتی چیزی شناسایی نشد
// بتوان فهمید مشکل از کجاست (نبودِ محیط پایتون؟ PDF اسکن‌شده؟ خطای اسکریپت؟)
function run_document_ocr_verbose($absFilePath) {
    $pythonEnv = '/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3';
    $scriptPath = '/home/besiteir/ocr-paddle/test_pipeline.py';

    if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        return ['data' => null, 'debug' => 'تابع shell_exec روی این سرور غیرفعال است.'];
    }
    if (!file_exists($pythonEnv)) return ['data' => null, 'debug' => "محیط پایتون پیدا نشد: {$pythonEnv}"];
    if (!file_exists($scriptPath)) return ['data' => null, 'debug' => "اسکریپت OCR پیدا نشد: {$scriptPath}"];
    if (!file_exists($absFilePath)) return ['data' => null, 'debug' => "فایل ورودی پیدا نشد: {$absFilePath}"];

    $command = "export PYTHONIOENCODING=utf8; " . escapeshellarg($pythonEnv) . " " . escapeshellarg($scriptPath) . " " . escapeshellarg($absFilePath) . " 2>&1";
    $output = shell_exec($command);
    if ($output === null || trim($output) === '') {
        return ['data' => null, 'debug' => 'اسکریپت پایتون هیچ خروجی‌ای برنگرداند.'];
    }
    if (!preg_match('/\{.*\}/s', $output, $matches)) {
        return ['data' => null, 'debug' => 'خروجی اسکریپت JSON نبود: ' . mb_substr(trim($output), 0, 300)];
    }
    $result = json_decode($matches[0], true);
    if (!$result) return ['data' => null, 'debug' => 'خروجی JSON قابل تجزیه نبود.'];
    if (empty($result['ok'])) {
        return ['data' => null, 'debug' => 'اسکریپت خطا داد: ' . ($result['error'] ?? 'نامشخص')];
    }
    $extracted = $result['extracted'] ?? null;
    // اگر خودِ اسکریپت گفته نتوانسته استخراج کند (PDF اسکن‌شده/فرمت نامعتبر)، آن را شکست حساب می‌کنیم
    if (is_array($extracted) && isset($extracted['error'])) {
        return ['data' => null, 'debug' => $extracted['error']];
    }
    return ['data' => $extracted, 'debug' => null];
}

// نسخه‌ی ساده برای سازگاری با فراخوانی‌های قبلی
function run_document_ocr($absFilePath) {
    $r = run_document_ocr_verbose($absFilePath);
    return $r['data'];
}

// مسیر پایه‌ی «بایگانی صادره»: .../{سال صدور}/{ماه صدور}/{بدنه|ثالث}
function build_sadere_base_path($siteRoot, $issuedTimestamp, $insuranceTypeEnum) {
    [$jy, $jm, ] = jalali_from_gregorian_ts($issuedTimestamp);
    $monthName = jalali_month_name($jm);
    return archive_root($siteRoot) . '/بایگانی صادره/' . $jy . '/' . $monthName . '/' . insurance_type_fa($insuranceTypeEnum);
}

// مسیر پوشه‌ی موقتِ مربوط به یک شخص (بر اساس کد ملی)، پیش از تایید نهایی
function build_temp_person_path($siteRoot, $timestamp, $nationalId) {
    [$jy, $jm, ] = jalali_from_gregorian_ts($timestamp);
    $monthName = jalali_month_name($jm);
    return temp_archive_root($siteRoot) . '/' . $jy . '/' . $monthName . '/' . sanitize_folder_name($nationalId ?: 'نامشخص');
}

// زیرپوشه‌ی موقتِ مخصوص یک پلاک/درخواست، زیر پوشه‌ی موقتِ شخص
function build_temp_plate_path($siteRoot, $timestamp, $nationalId, $plate) {
    return build_temp_person_path($siteRoot, $timestamp, $nationalId) . '/' . sanitize_folder_name($plate ?: 'بدون‌پلاک');
}

// حذف بازگشتیِ پوشه‌های خالی، از یک مسیر مشخص به سمت بالا (تا سقفِ ریشه‌ی موقت)
function cleanup_empty_dirs_upward($path, $stopAt) {
    $stopAt = rtrim($stopAt, '/');
    while ($path && strpos($path, $stopAt) === 0 && $path !== $stopAt) {
        if (!is_dir($path)) { $path = dirname($path); continue; }
        $entries = array_diff(scandir($path), ['.', '..']);
        if (count($entries) > 0) break;
        @rmdir($path);
        $path = dirname($path);
    }
}

// =====================================================================
//  نام‌گذاری فایل هر مدرک - پشتیبانی از تمام انواع مدرکِ مرتبط با پلاک یا شخص
// =====================================================================
const PLATE_BASED_DOC_KEYS = [
    'prev_body_insurance', 'prev_third_insurance', 'car_card_front', 'car_card_back',
    'ownership_doc', 'plate_history',
];
const PERSON_BASED_DOC_KEYS = [
    'amlak_certificate', 'national_card_front', 'national_card_back',
    'license_front', 'license_back', 'birth_cert_self', 'birth_cert_self2',
    'birth_cert_relative', 'birth_cert_relative2', 'identity_doc',
    'holder_birth_cert_p1', 'holder_birth_cert_relation', 'insured_id_doc',
];

function build_doc_filename($docKey, $docLabel, $plate, $insuredName, $nationalId, $ext) {
    $plateOrTemp = $plate ?: 'بدون‌پلاک';
    $nameOrTemp = $insuredName ?: 'نامشخص';
    $nidOrTemp = $nationalId ?: 'نامشخص';

    if (in_array($docKey, PLATE_BASED_DOC_KEYS)) {
        $base = "({$docLabel}) {$plateOrTemp}";
    } else {
        $base = "{$nameOrTemp} - {$nidOrTemp} - ({$docLabel})";
    }
    return sanitize_folder_name($base) . '.' . $ext;
}

// نام‌گذاری فایل نهاییِ بیمه‌نامه‌ی صادرشده
function build_final_policy_filename($plate, $insuredName, $policyNum, $vin, $ext) {
    $base = name_join([$plate, $insuredName, $policyNum, $vin]) ?: ('بیمه‌نامه_' . time());
    return sanitize_folder_name($base) . '.' . $ext;
}

function generate_case_unique_code($caseId) {
    return 'PC' . str_pad($caseId, 6, '0', STR_PAD_LEFT);
}

// =====================================================================
//  کمکی‌های عمومی تماس با بله (برای پیام‌های وضعیت در گروه، خارج از بافت وب‌هوک)
// =====================================================================
function bale_curl_call($method, $payload, $token) {
    if (!$token) return null;
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/" . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($res, true);
    return (is_array($decoded) && !empty($decoded['ok'])) ? $decoded['result'] : null;
}

function bale_get_token($pdo) {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bot_token'");
    return $stmt->fetchColumn() ?: null;
}

// متن کامل و همیشه‌به‌روزِ اطلاعات یک معرفی‌نامه، برای پیام گروه
function render_intro_status_message($pdo, $introId) {
    $stmt = $pdo->prepare("
        SELECT i.*, p.full_name, p.national_code, p.personnel_code, c.name AS company_name
        FROM introductions i JOIN persons p ON i.person_id = p.id LEFT JOIN companies c ON p.company_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$introId]);
    $intro = $stmt->fetch();
    if (!$intro) return null;

    $msg  = "✅ اطلاعات معرفی‌نامه با موفقیت شناسایی و ثبت شد \n";
    $msg .= "👤 نام: " . ($intro['full_name'] ?: '-') . "\n";
    $msg .= "💳 کد ملی: " . ($intro['national_code'] ?: '-') . "\n";
    $msg .= "🔢 کد پرسنلی: " . ($intro['personnel_code'] ?: '-') . "\n";
    $msg .= "🏢 شرکت: " . ($intro['company_name'] ?: '-') . "\n";
    $msg .= "📅 تاریخ صدور: " . ($intro['letter_date'] ? str_replace('-', '/', $intro['letter_date']) : '-') . "\n";
    $msg .= "📄 تعداد بیمه‌نامه صادر شده: " . get_issued_counts_label($pdo, $introId);

    // به‌جای یک «وضعیت» کلی و گمراه‌کننده برای کل معرفی‌نامه، وضعیت هر بیمه‌نامه جداگانه نشان داده می‌شود
    $stmt = $pdo->prepare("SELECT insurance_type, plate, status FROM policy_cases WHERE introduction_id = ? ORDER BY created_at ASC");
    $stmt->execute([$introId]);
    $cases = $stmt->fetchAll();
    if ($cases) {
        $caseLabels = ['REGISTERED' => 'در حال تکمیل اطلاعات', 'DOCS_PENDING' => 'در انتظار اصلاح',
                       'DOCS_REVIEW' => 'در انتظار تایید مدارک', 'ISSUING' => 'در حال صدور',
                       'ISSUED' => 'صادر شده ✅', 'REJECTED' => 'رد شده'];
        $msg .= "\n\n📋 بیمه‌نامه‌های ثبت‌شده:";
        foreach ($cases as $c) {
            $msg .= "\n• " . insurance_type_fa($c['insurance_type']) . ' - ' . ($c['plate'] ?: 'بدون پلاک') . ' - وضعیت: ' . ($caseLabels[$c['status']] ?? $c['status']);
        }
    }

    return $msg;
}

// همیشه همان یک پیامِ معرفی‌نامه در گروه را با آخرین وضعیت ویرایش می‌کند
// (نه اینکه هر بار پیام جدید بفرستد) - اگر هنوز پیامی نبوده، پیام تازه می‌فرستد و ثبتش می‌کند
function sync_intro_group_message($pdo, $introId, $bot_token = null) {
    $stmt = $pdo->prepare("SELECT group_chat_id, status_message_id FROM introductions WHERE id = ?");
    $stmt->execute([$introId]);
    $row = $stmt->fetch();
    if (!$row || !$row['group_chat_id']) return;

    $text = render_intro_status_message($pdo, $introId);
    if (!$text) return;
    $bot_token = $bot_token ?: bale_get_token($pdo);

    $newMsgId = send_or_edit_group_status($row['group_chat_id'], $row['status_message_id'], $text, $bot_token);
    $pdo->prepare("UPDATE introductions SET status_message_id = ? WHERE id = ?")->execute([$newMsgId, $introId]);
}

function get_site_url($pdo) {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_url'");
    return rtrim($stmt->fetchColumn() ?: '', '/');
}

// اطلاع‌رسانی فعالیت‌های سامانه به مدیر، در صورتی که آیدی عددی‌اش در تنظیمات ثبت شده باشد
// (و خودِ او هم قبلاً ربات را استارت کرده باشد؛ در غیر این صورت بله اجازه‌ی ارسال نمی‌دهد و بی‌صدا رد می‌شویم)
function notify_admin($pdo, $text, $bot_token = null) {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'admin_bale_chat_id'");
    $adminChatId = $stmt->fetchColumn();
    if (!$adminChatId) return;
    $bot_token = $bot_token ?: bale_get_token($pdo);
    try { bale_curl_call('sendMessage', ['chat_id' => $adminChatId, 'text' => $text], $bot_token); } catch (Exception $e) {}
}

// اطلاع‌رسانیِ رویدادهای مهم به مشتری - هم به‌صورت پیام بله (مثل قبل)، هم با ثبت در
// «مرکز اعلان‌های اپ» (خوانده‌شده/نشده) تا داخل مینی‌اپ هم قابل مشاهده باشد
// اعلان به کاربر: طبق تصمیم نهایی، دیگر پیام مستقیم در چتِ ربات فرستاده نمی‌شود (چون آن تجربه
// پراکنده و غیرمتمرکز بود). اعلان‌ها *فقط* در برگه‌ی اعلان‌های اپ ثبت می‌شوند (نه داخل چت
// پشتیبانی - تا آن رشته صرفاً مخصوص گفتگوی واقعی با کارشناس بماند)، و در ربات فقط یک
// یادآوریِ کوتاه می‌رود که پیشِ ارسالش، یادآوریِ قبلی حذف می‌شود تا چت شلوغ نشود.
function notify_customer_app($pdo, $personId, $chatId, $title, $message, $type = 'info', $relatedCaseId = null, $bot_token = null) {
    if (!$personId) return;

    $pdo->prepare("INSERT INTO app_notifications (person_id, title, message, type, related_case_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$personId, $title, $message, $type, $relatedCaseId]);

    push_bot_notice($pdo, $personId, $chatId, "🔔 اعلان جدید دارید. برای مشاهده، به بخش «اعلان‌ها» در اپ مراجعه کنید.", $bot_token);
}

// یادآوریِ کوتاه در چتِ ربات، با حذف یادآوریِ قبلی. برای هر دو نوع (اعلان و پیام کارشناس)
// استفاده می‌شود تا هیچ‌وقت بیش از یک یادآوری در چت باقی نماند.
function push_bot_notice($pdo, $personId, $chatId, $text, $bot_token = null) {
    if (!$chatId || !$personId) return;
    $bot_token = $bot_token ?: bale_get_token($pdo);
    if (!$bot_token) return;
    try {
        $stmt = $pdo->prepare("SELECT last_notice_msg_id FROM persons WHERE id = ?");
        $stmt->execute([$personId]);
        $prevMsgId = $stmt->fetchColumn();
        if ($prevMsgId) {
            bale_curl_call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $prevMsgId], $bot_token);
        }
        $resp = bale_curl_call('sendMessage', ['chat_id' => $chatId, 'text' => $text], $bot_token);
        $newMsgId = $resp['message_id'] ?? null;
        $pdo->prepare("UPDATE persons SET last_notice_msg_id = ? WHERE id = ?")->execute([$newMsgId, $personId]);
    } catch (Exception $e) {}
}

// پوشه‌ی بازدیدِ آزاد (بدون پرونده‌ی بیمه‌ی مشخص) داخل پوشه‌ی معرفی‌نامه‌ی همان شخص
function build_free_inspection_folder($pdo, $siteRoot, $introId, $plate) {
    $introFolder = sync_intro_folder($pdo, $siteRoot, $introId);
    $folder = $introFolder . '/بازدید آزاد - ' . sanitize_folder_name($plate ?: 'بدون‌پلاک');
    if (!is_dir($folder)) @mkdir($folder, 0777, true);
    return $folder;
}

// ساخت یک توکن تصادفی امن برای ورود یک‌بارمصرف به مینی‌اپ (بازدید سلامت)
function create_webapp_session($pdo, $personId, $caseId, $mode, $freePlate, $ttlMinutes = 60) {
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO webapp_sessions (token, person_id, case_id, mode, free_plate, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))");
    $stmt->execute([$token, $personId, $caseId, $mode, $freePlate, $ttlMinutes]);
    return $token;
}

// توکن ورود به «اپ اصلی» (منو) - چون منو بارها در طول روز نمایش داده می‌شود، به‌جای ساختن
// توکن تازه هر بار، یک توکن معتبرِ موجود (در صورت وجود) را دوباره استفاده می‌کنیم
function get_or_create_main_app_token($pdo, $personId) {
    $stmt = $pdo->prepare("SELECT token FROM webapp_sessions WHERE person_id = ? AND mode = 'main' AND expires_at > DATE_ADD(NOW(), INTERVAL 1 DAY) ORDER BY expires_at DESC LIMIT 1");
    $stmt->execute([$personId]);
    $existing = $stmt->fetchColumn();
    if ($existing) return $existing;
    return create_webapp_session($pdo, $personId, null, 'main', null, 60 * 24 * 14); // ۱۴ روز
}

// دکمه‌ی همیشگیِ کنار جعبه‌ی تایپ پیام در بله (مثل دکمه‌ی «مجانی بخوان» در بات‌های دیگر) که با یک لمس
// مستقیماً صفحه‌ی اصلیِ مینی‌اپ را باز می‌کند - کاملاً جدا از دکمه‌های inline زیر پیام‌هاست، فقط یک‌بار
// برای هر چت لازم است تنظیم شود (تا وقتی تغییر نکند، ثابت می‌ماند)
function set_chat_menu_button($pdo, $chatId, $personId, $bot_token = null) {
    $siteUrl = get_site_url($pdo);
    if (!$siteUrl || !$personId) return;
    $token = get_or_create_main_app_token($pdo, $personId);
    $bot_token = $bot_token ?: bale_get_token($pdo);
    try {
        bale_curl_call('setChatMenuButton', [
            'chat_id' => $chatId,
            'menu_button' => [
                'type' => 'web_app',
                'text' => '🚀 باز کردن',
                'web_app' => ['url' => $siteUrl . '/webapp/app/?t=' . $token],
            ],
        ], $bot_token);
    } catch (Exception $e) {}
}

// اعتبارسنجی initData که خودِ بله هنگام باز شدنِ مینی‌اپ (مثلاً از طریق دکمه‌ی ثابتِ بات‌فادر،
// بدون توکنِ اختصاصیِ ما) به‌صورت امضاشده در اختیار صفحه می‌گذارد. الگوریتم دقیقاً مطابق مستندات
// رسمی است: کلید مخفی = HMAC-SHA256(bot_token, "WebAppData")، سپس هش داده‌ها با همان کلید.
// در صورت معتبر بودن، شناسه‌ی چتِ کاربر (همان bale_chat_id) را برمی‌گرداند، وگرنه null.
function validate_bale_init_data($initData, $botToken) {
    if (!$initData || !$botToken) return null;
    parse_str($initData, $data);
    if (empty($data['hash']) || empty($data['user'])) return null;
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data);
    $pairs = [];
    foreach ($data as $k => $v) $pairs[] = "{$k}={$v}";
    $dataCheckString = implode("\n", $pairs);

    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
    if (!hash_equals($computedHash, $hash)) return null;

    $user = json_decode($data['user'], true);
    return $user['id'] ?? null;
}

// پیام وضعیت یک پرونده را در گروهِ همان معرفی‌نامه می‌فرستد یا (اگر قبلاً فرستاده) ویرایش می‌کند
function notify_case_stage($pdo, $caseId, $stageLabel, $bot_token = null) {
    $stmt = $pdo->prepare("
        SELECT pc.*, p.full_name AS holder_name, i.group_chat_id
        FROM policy_cases pc
        JOIN persons p ON pc.person_id = p.id
        JOIN introductions i ON pc.introduction_id = i.id
        WHERE pc.id = ?
    ");
    $stmt->execute([$caseId]);
    $row = $stmt->fetch();
    if (!$row || !$row['group_chat_id']) return;

    $bot_token = $bot_token ?: bale_get_token($pdo);
    $text = "📄 " . ($row['insured_name'] ?: $row['holder_name']) . " - بیمه " . insurance_type_fa($row['insurance_type']) .
            " - پلاک: " . ($row['plate'] ?: '-') . "\nشناسه: {$row['unique_code']}\nوضعیت: {$stageLabel}";

    $newMsgId = send_or_edit_group_status($row['group_chat_id'], $row['status_message_id'], $text, $bot_token);
    $pdo->prepare("UPDATE policy_cases SET status_message_id = ?, status_chat_id = ? WHERE id = ?")
        ->execute([$newMsgId, $row['group_chat_id'], $caseId]);
}

// ارسال یا ویرایش پیام وضعیت یک پرونده در گروه؛ اگر messageId داده شود ویرایش می‌کند وگرنه پیام تازه می‌فرستد
function send_or_edit_group_status($chatId, $messageId, $text, $bot_token) {
    if (!$chatId) return null;
    if ($messageId) {
        $r = bale_curl_call('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text], $bot_token);
        if ($r) return $messageId;
    }
    $sent = bale_curl_call('sendMessage', ['chat_id' => $chatId, 'text' => $text], $bot_token);
    return $sent['message_id'] ?? null;
}

// تبدیل ارقام فارسی/عربی به انگلیسی، برای اینکه ورودی کاربر همیشه قابل پردازش باشد
function p2e_digits($str) {
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($arabic, $english, str_replace($persian, $english, (string)$str));
}

// =====================================================================
//  کاهش حجم عکس‌های ورودی بزرگ (بدون افت محسوس کیفیت) برای مدیریت فضای سرور
// =====================================================================
function compress_image_if_needed($absolutePath, $maxBytes = 1048576) {
    if (!function_exists('imagecreatefromjpeg') || !file_exists($absolutePath)) return;
    if (filesize($absolutePath) <= $maxBytes) return;

    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) return; // مدارک PDF و غیره دست‌نخورده می‌مانند

    try {
        $img = null;
        if ($ext === 'jpg' || $ext === 'jpeg') { $img = @imagecreatefromjpeg($absolutePath); }
        elseif ($ext === 'png') { $img = @imagecreatefrompng($absolutePath); }
        elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) { $img = @imagecreatefromwebp($absolutePath); }
        if (!$img) return;

        // اگر ابعاد خیلی بزرگ بود (مثلاً عکس ۱۲ مگاپیکسلی)، کمی کوچک می‌کنیم؛
        // این افت کیفیت محسوسی برای نمایش/چاپ مدارک ایجاد نمی‌کند
        $w = imagesx($img); $h = imagesy($img);
        $maxDim = 2000;
        if ($w > $maxDim || $h > $maxDim) {
            $ratio = min($maxDim / $w, $maxDim / $h);
            $newImg = imagecreatetruecolor((int)($w * $ratio), (int)($h * $ratio));
            imagecopyresampled($newImg, $img, 0, 0, 0, 0, (int)($w * $ratio), (int)($h * $ratio), $w, $h);
            imagedestroy($img);
            $img = $newImg;
        }

        // با کیفیت بالا (۸۵) ذخیره می‌کنیم؛ اگر هنوز حجم بالا بود، پلکانی کیفیت را کم می‌کنیم
        foreach ([85, 75, 65, 55] as $quality) {
            imagejpeg($img, $absolutePath . '.tmp', $quality);
            if (filesize($absolutePath . '.tmp') <= $maxBytes) break;
        }
        imagedestroy($img);
        rename($absolutePath . '.tmp', $absolutePath);
    } catch (Exception $e) { /* اگر GD موجود نبود یا خطایی رخ داد، فایل اصلی دست‌نخورده باقی می‌ماند */ }
}

function insurance_type_fa($enumType) {
    return $enumType === 'BODY' ? 'بدنه' : 'ثالث';
}

// =====================================================================
//  سقف تعهد مالی بیمه ثالث (ریال) - دقیقاً مطابق فهرستی که در پنل پاسارگاد است
// =====================================================================
function liability_limit_options() {
    return [700000000, 800000000, 1000000000, 2000000000, 2500000000, 3000000000,
            4000000000, 5000000000, 6000000000, 7000000000, 8000000000, 9000000000, 10000000000];
}

function coverage_descriptions_fa() {
    return [
        'مالی'  => 'خسارت واردشده به اموال شخص ثالث (خودرو، اموال، ساختمان و ...) در اثر تصادف را جبران می‌کند.',
        'جانی'  => 'دیه یا ارش ناشی از صدمه، نقص عضو، ازکارافتادگی یا فوت شخص ثالث در اثر حادثه را پوشش می‌دهد.',
        'حوادث راننده' => 'خسارت جانی خودِ راننده‌ی مقصر حادثه را که تعهد جانی معمول شخص ثالث شامل آن نمی‌شود، جبران می‌کند.',
    ];
}

// =====================================================================
//  پوشش‌های تکمیلی بیمه بدنه پاسارگاد (طبق لیست موجود در بیمه‌نامه‌های نمونه)
//  کلید => [برچسب، توضیح کوتاه، لیست گزینه‌های زیرمجموعه در صورت وجود]
// =====================================================================
function body_coverage_options() {
    return [
        'war_damage' => [
            'label' => 'پوشش خسارت ناشی از جنگ',
            'desc'  => 'خسارت وارده به خودرو در اثر عملیات جنگی، طبق شرایط پیوست بیمه‌نامه را پوشش می‌دهد.',
            'tiers' => null,
        ],
        'price_fluctuation' => [
            'label' => 'پوشش نوسان قیمت (افزایش ارزش خودرو)',
            'desc'  => 'در صورت افزایش قیمت روز خودرو نسبت به ارزش بیمه‌شده، این پوشش مابه‌التفاوت را جبران می‌کند.',
            'tiers' => ['50' => '۵۰ درصد افزایش ارزش', '100' => '۱۰۰ درصد افزایش ارزش'],
        ],
        'paint_acid' => [
            'label' => 'پاشیدگی رنگ، مواد اسیدی، شیمیایی',
            'desc'  => 'خسارت ناشی از پاشیده‌شدن رنگ یا مواد اسیدی/شیمیایی روی بدنه‌ی خودرو را پوشش می‌دهد.',
            'tiers' => null,
        ],
        'glass_break' => [
            'label' => 'شکست شیشه بدون برخورد اشیاء',
            'desc'  => 'شکستن شیشه‌ی خودرو بدون برخورد جسم خارجی (مثلاً بر اثر تغییر دما) را پوشش می‌دهد.',
            'tiers' => null,
        ],
        'natural_disaster' => [
            'label' => 'سیل، زلزله، آتشفشان، تگرگ، طوفان و بهمن',
            'desc'  => 'خسارت ناشی از بلایای طبیعی به خودرو را جبران می‌کند.',
            'tiers' => null,
        ],
        'parts_theft' => [
            'label' => 'سرقت لوازم و قطعات',
            'desc'  => 'در صورت سرقت لوازم و قطعات خودرو (نه کل خودرو)، تا سقف تعیین‌شده از سرمایه‌ی بیمه غرامت پرداخت می‌شود.',
            'tiers' => ['10' => 'تا ۱۰٪ سرمایه بیمه‌شده', '15' => 'تا ۱۵٪ سرمایه بیمه‌شده'],
        ],
        'transport_cost' => [
            'label' => 'هزینه ایاب‌وذهاب در مدت تعمیر خودرو',
            'desc'  => 'در مدتی که خودرو برای تعمیرِ خسارت در تعمیرگاه است، هزینه‌ی رفت‌وآمد جایگزین پرداخت می‌شود.',
            'tiers' => ['750000' => '۷۵۰,۰۰۰ ریال', '1200000' => '۱,۲۰۰,۰۰۰ ریال', '1500000' => '۱,۵۰۰,۰۰۰ ریال'],
        ],
        'depreciation_waiver' => [
            'label' => 'پوشش حذف استهلاک (به‌غیر از باتری و لاستیک)',
            'desc'  => 'در پرداخت خسارت، کسر استهلاک قطعات تعویضی (به‌جز باتری و لاستیک) اعمال نمی‌شود.',
            'tiers' => null,
        ],
        'deductible_adjust' => [
            'label' => 'پوشش تعدیل فرانشیز خسارت‌های جزئی',
            'desc'  => 'فرانشیز (سهم پرداختی بیمه‌گذار) در خسارت‌های جزئی را کاهش یا تعدیل می‌کند.',
            'tiers' => null,
        ],
    ];
}

// =====================================================================
//  فهرست مدارک لازم - نسخه‌ی هوشمند که بر اساس انتخاب کاربر (سند/کارت ماشین،
//  سابقه‌ی بیمه بدنه، و نسبت بیمه‌گذار) فهرست را می‌سازد
// =====================================================================
// وضعیت «بازدید مدارک» یک پرونده را از روی مدارک واقعاً ارسال‌شده محاسبه می‌کند (نه از یک فیلد ثابت)
// خروجی: not_applicable | not_started | partial | submitted | needs_fix | approved
function compute_docs_status($pdo, $case) {
    $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
    if (!$required) return 'not_applicable';
    $stmt = $pdo->prepare("SELECT doc_key, status FROM case_documents WHERE case_id = ? ORDER BY id DESC");
    $stmt->execute([$case['id']]);
    $latestByKey = [];
    foreach ($stmt->fetchAll() as $r) if (!isset($latestByKey[$r['doc_key']])) $latestByKey[$r['doc_key']] = $r['status'];

    $hasRejected = false; $missingCount = 0; $allApproved = true;
    foreach (array_keys($required) as $key) {
        $st = $latestByKey[$key] ?? null;
        if ($st === 'REJECTED') $hasRejected = true;
        if (!$st) $missingCount++;
        if ($st !== 'APPROVED') $allApproved = false;
    }
    if ($hasRejected) return 'needs_fix';
    if ($allApproved) return 'approved';
    if ($missingCount === count($required)) return 'not_started';
    if ($missingCount > 0) return 'partial';
    return 'submitted';
}

// وضعیت «بازدید سلامت خودرو» یک پرونده (فقط برای بدنه معنا دارد)
function compute_health_status($pdo, $case) {
    if ($case['insurance_type'] !== 'BODY') return 'not_applicable';
    $stmt = $pdo->prepare("SELECT status FROM health_inspections WHERE case_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$case['id']]);
    $st = $stmt->fetchColumn();
    if (!$st) return 'not_started';
    if ($st === 'REJECTED') return 'needs_fix';
    if ($st === 'APPROVED') return 'approved';
    return 'submitted';
}

// دلیل ردِ آخرین بازدید سلامت (اگر وضعیت needs_fix باشد)
function get_latest_health_reject_reason($pdo, $caseId) {
    $stmt = $pdo->prepare("SELECT reject_reason FROM health_inspections WHERE case_id = ? AND status = 'REJECTED' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$caseId]);
    return $stmt->fetchColumn() ?: null;
}

// بعد از هر آپلود مدرک یا ثبت بازدید سلامت، بررسی می‌کند که آیا پرونده کامل شده و باید
// خودکار به «در انتظار بررسی کارشناس» برود یا نه
function maybe_advance_case_status($pdo, $caseId, $bot_token = null) {
    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case || !in_array($case['status'], ['AWAITING_DOCS', 'DOCS_PENDING'], true)) return;

    $docsStatus = compute_docs_status($pdo, $case);
    $healthStatus = compute_health_status($pdo, $case);
    if ($docsStatus === 'needs_fix' || $healthStatus === 'needs_fix') return;

    $docsOk = in_array($docsStatus, ['submitted', 'approved'], true);
    $healthOk = in_array($healthStatus, ['not_applicable', 'submitted', 'approved'], true);
    if ($docsOk && $healthOk) {
        $pdo->prepare("UPDATE policy_cases SET status = 'DOCS_REVIEW' WHERE id = ?")->execute([$caseId]);
        $stmt = $pdo->prepare("SELECT pc.*, p.bale_chat_id FROM policy_cases pc JOIN persons p ON pc.person_id = p.id WHERE pc.id = ?");
        $stmt->execute([$caseId]);
        $full = $stmt->fetch();
        if ($full) {
            $plateDisplay = $full['plate'] ?: 'بدون پلاک';
            notify_customer_app($pdo, $full['person_id'], $full['bale_chat_id'], 'مدارک تکمیل شد',
                "✅ همه‌ی مدارک/بازدیدِ مربوط به بیمه‌نامه‌ی " . insurance_type_fa($full['insurance_type']) . " پلاک {$plateDisplay} تکمیل شد و برای بررسی نزد کارشناس ارسال شد.", 'success', $caseId, $bot_token);
            notify_admin($pdo, "📋 مدارک/بازدید پرونده {$full['unique_code']} تکمیل شد و آماده‌ی بررسی است.", $bot_token);
        }
    }
}

function get_required_docs_v2($insuranceType, $ownershipChoice, $prevInsuranceAnswer, $relationship) {
    $docs = [];

    if ($insuranceType === 'BODY' && $prevInsuranceAnswer === 'بله') {
        $docs['prev_body_insurance'] = 'بیمه بدنه قبل';
    }

    $docs['identity_doc'] = 'کارت ملی یا گواهینامه (بیمه‌گذار)';

    if ($ownershipChoice === 'سند') {
        $docs['ownership_doc'] = 'سند مالکیت';
    } else {
        $docs['car_card_front'] = 'کارت ماشین رو';
        $docs['car_card_back'] = 'کارت ماشین پشت';
    }

    if ($relationship && $relationship !== 'خودم') {
        $docs['holder_birth_cert_p1'] = 'شناسنامه دارنده معرفی‌نامه - صفحه اول';
        $docs['holder_birth_cert_relation'] = 'شناسنامه دارنده معرفی‌نامه - صفحه مربوط به ' . $relationship;
        $docs['insured_id_doc'] = 'کارت ملی یا شناسنامه بیمه‌گذار (نسبت: ' . $relationship . ')';
    }

    return $docs;
}

function relationship_options() {
    return ['خودم', 'پدر', 'مادر', 'همسر', 'فرزند'];
}

// پروفایل هر بیمه‌گذار جداگانه ذخیره می‌شود (خودِ شخص در برابر هر یک از بستگان با کد ملی خودشان)
// تا اطلاعات یک نفر (مثلاً خودِ کارمند) هرگز به‌جای شخص دیگری (مثلاً مادر) پیشنهاد داده نشود
function get_profile_key($case) {
    return ($case['insured_relationship'] === 'خودم') ? 'خودم' : ('nid_' . $case['insured_national_id']);
}

// عبارت خطابی مناسب بر اساس نسبت بیمه‌گذار، برای لحن دوستانه و شخصی‌سازی‌شده در پیام‌های ربات
function relationship_addressee($relationship, $possessive = true) {
    if (!$relationship || $relationship === 'خودم') return $possessive ? 'خودتان' : 'شما';
    $map = ['پدر' => 'پدرتان', 'مادر' => 'مادرتان', 'همسر' => 'همسرتان', 'فرزند' => 'فرزندتان'];
    return $map[$relationship] ?? ($relationship . ' شما');
}

// =====================================================================
//  وضعیت زنده‌ی معرفی‌نامه/پرونده‌ها - همیشه از policy_cases واقعی محاسبه می‌شود
//  (نه از یک ستون ثابت که بعد از ثبت هرگز بروزرسانی نمی‌شد)
// =====================================================================
function get_intro_status_label($pdo, $introduction_id) {
    $stmt = $pdo->prepare("SELECT status FROM policy_cases WHERE introduction_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$introduction_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) {
        $stmt = $pdo->prepare("SELECT personnel_visited_notified FROM introductions WHERE id = ?");
        $stmt->execute([$introduction_id]);
        return $stmt->fetchColumn() ? 'مرحله مراجعه پرسنل' : 'در انتظار مراجعه پرسنل';
    }

    $priority = ['DOCS_REVIEW' => 4, 'ISSUING' => 3, 'REGISTERED' => 2, 'REJECTED' => 1, 'ISSUED' => 0];
    $labels = ['DOCS_REVIEW' => 'در انتظار تایید مدارک', 'ISSUING' => 'در حال صدور',
               'REGISTERED' => 'در حال تکمیل اطلاعات توسط پرسنل', 'REJECTED' => 'مدرک نیاز به اصلاح دارد',
               'ISSUED' => 'بیمه‌نامه صادر شده'];

    // اگر همه‌ی پرونده‌ها صادر شده باشند
    if (count(array_unique($rows)) === 1 && $rows[0] === 'ISSUED') return 'همه بیمه‌نامه‌ها صادر شده';

    // در غیر این صورت، وضعیتِ «فعال‌ترین» پرونده (کاری که هنوز در جریان است) را نشان بده
    usort($rows, fn($a, $b) => ($priority[$b] ?? 0) <=> ($priority[$a] ?? 0));
    foreach ($rows as $r) {
        if ($r !== 'ISSUED') return $labels[$r] ?? $r;
    }
    return 'بیمه‌نامه صادر شده';
}

// تعداد بیمه‌نامه‌های صادرشده، به تفکیک نوع (مثلاً «۲ فقره ثالث / ۱ فقره بدنه»)
function get_issued_counts_label($pdo, $introduction_id) {
    $stmt = $pdo->prepare("SELECT insurance_type, COUNT(*) c FROM policy_cases WHERE introduction_id = ? AND status = 'ISSUED' GROUP BY insurance_type");
    $stmt->execute([$introduction_id]);
    $rows = $stmt->fetchAll();
    if (!$rows) return '۰ فقره';
    $typeFa = ['BODY' => 'بدنه', 'THIRDPARTY' => 'ثالث'];
    $labels = [];
    foreach ($rows as $r) $labels[] = $r['c'] . ' فقره ' . ($typeFa[$r['insurance_type']] ?? $r['insurance_type']);
    return implode(' / ', $labels);
}

// تفکیک تعداد صادرشده به «برای خودش» در برابر «برای بستگان درجه یک»
// (برای رعایت سقف مشترک معرفی‌نامه و شفافیت گزارش‌گیری کارشناس)
function get_issued_counts_by_relationship($pdo, $introduction_id) {
    $stmt = $pdo->prepare("SELECT insured_relationship, COUNT(*) c FROM policy_cases WHERE introduction_id = ? AND status = 'ISSUED' GROUP BY insured_relationship");
    $stmt->execute([$introduction_id]);
    $rows = $stmt->fetchAll();
    $self = 0; $relatives = 0;
    foreach ($rows as $r) {
        if ($r['insured_relationship'] === 'خودم') $self += intval($r['c']);
        else $relatives += intval($r['c']);
    }
    return ['self' => $self, 'relatives' => $relatives, 'total' => $self + $relatives];
}

// =====================================================================
//  بازدید سلامت خودرو - ۱۴ مرحله‌ی ثابت، با نام‌گذاری بر اساس پلاک و تاریخ تایید
// =====================================================================
// تصویر راهنمای هر مرحله‌ی بازدید سلامت را در پوشه‌ی Image (روت سایت) پیدا می‌کند - نام فایل باید
// دقیقاً شماره‌ی مرحله باشد، مثلاً برای مرحله‌ی ۱۰: Image/10.png (یا jpg/jpeg/webp)
function find_health_guide_image($stepKey) {
    $dir = dirname(__DIR__) . '/Image';
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $path = "{$dir}/{$stepKey}.{$ext}";
        if (file_exists($path)) return $path;
    }
    return null;
}

function health_inspection_steps() {
    return [
        '1'  => ['label' => 'نمای جلوی خودرو',                 'required' => true,  'multi' => false],
        '2'  => ['label' => 'نمای جلو راست ۴۵ درجه',            'required' => true,  'multi' => false],
        '3'  => ['label' => 'نمای جلو چپ ۴۵ درجه',              'required' => true,  'multi' => false],
        '4'  => ['label' => 'نمای عقب خودرو',                   'required' => true,  'multi' => false],
        '5'  => ['label' => 'نمای عقب راست ۴۵ درجه',            'required' => true,  'multi' => false],
        '6'  => ['label' => 'نمای عقب چپ ۴۵ درجه',              'required' => true,  'multi' => false],
        '7'  => ['label' => 'نمای جلو با کاپوت باز',            'required' => true,  'multi' => false],
        '8'  => ['label' => 'نمای عقب با صندوق باز',            'required' => true,  'multi' => false],
        '9'  => ['label' => 'نمای داخل کابین با سوییچ باز',     'required' => true,  'multi' => false],
        '10' => ['label' => 'نمای سقف خودرو',                   'required' => true,  'multi' => false],
        '11' => ['label' => 'محل لاستیک زاپاس',                 'required' => true,  'multi' => false],
        '12' => ['label' => 'محل شماره شاسی حک‌شده',            'required' => true,  'multi' => false],
        '13' => ['label' => 'سانروف',                           'required' => false, 'multi' => false],
        '14' => ['label' => 'مواضع آسیب‌دیده',                  'required' => false, 'multi' => true],
    ];
}
const HEALTH_STEP_SECONDS = 90;      // زمان مجاز هر مرحله (۱.۵ دقیقه)
const HEALTH_MAX_ATTEMPTS_PER_DAY = 2;
function health_total_seconds() {
    // مجموع زمان کل = تعداد مراحل × زمان هر مرحله (همیشه هماهنگ با HEALTH_STEP_SECONDS)
    return count(health_inspection_steps()) * HEALTH_STEP_SECONDS;
}

// نام هر عکسِ مرحله، طبق برچسبِ همان مرحله + پلاک (نام موقتِ حین جمع‌آوری؛ همین نام در بایگانی هم می‌ماند)
function health_step_filename($stepLabel, $plate, $ext, $subIndex = null) {
    $label = $subIndex ? "{$stepLabel} {$subIndex}" : $stepLabel;
    return sanitize_folder_name("({$label}) " . ($plate ?: 'بدون‌پلاک')) . '.' . $ext;
}

// پوشه‌ی نهاییِ عکس‌های یک بازدیدِ تاییدشده، داخل پوشه‌ی همان درخواست/پلاک
function build_health_photos_folder_name($approvedJalaliDate, $plate) {
    return sanitize_folder_name("({$approvedJalaliDate})(عکس‌های بازدید خودرو) " . ($plate ?: 'بدون‌پلاک'));
}
// نام فایل گزارش کارشناس برای همان بازدید
function build_health_report_filename($approvedJalaliDate, $plate, $ext) {
    return sanitize_folder_name("({$approvedJalaliDate})(گزارش بازدید خودرو) " . ($plate ?: 'بدون‌پلاک')) . '.' . $ext;
}

// =====================================================================
//  «بایگانی کسر از حقوق» / پوشه‌ی هر پرونده - ساخت، همگام‌سازیِ نام (پویا)، و انتقال نهایی مدارک
// =====================================================================
function get_issued_count_for_intro($pdo, $introId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM policy_cases WHERE introduction_id = ? AND status = 'ISSUED'");
    $stmt->execute([$introId]);
    return intval($stmt->fetchColumn());
}

// پوشه‌ی معرفی‌نامه را می‌سازد یا (اگر اطلاعات عوض شده) rename می‌کند تا همیشه
// با آخرین کد پرسنلی/تعداد صادره همگام بماند - این تابع را هر بار قبل از کار با
// پوشه‌ی یک معرفی‌نامه صدا می‌زنیم
function sync_intro_folder($pdo, $siteRoot, $introId) {
    $stmt = $pdo->prepare("
        SELECT i.*, p.full_name, p.national_code, p.personnel_code, c.name AS company_name
        FROM introductions i JOIN persons p ON i.person_id = p.id LEFT JOIN companies c ON p.company_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$introId]);
    $intro = $stmt->fetch();
    if (!$intro) return null;

    $issuedCount = get_issued_count_for_intro($pdo, $introId);
    $desiredPath = build_kasr_base_path($siteRoot, strtotime($intro['created_at']), $intro['full_name'], $intro['national_code'], $intro['personnel_code'], $intro['company_name'], $issuedCount);

    $currentPath = $intro['folder_path'];
    if ($currentPath && $currentPath !== $desiredPath && is_dir($currentPath)) {
        if (!is_dir(dirname($desiredPath))) @mkdir(dirname($desiredPath), 0777, true);
        @rename($currentPath, $desiredPath);
    } elseif (!is_dir($desiredPath)) {
        @mkdir($desiredPath, 0777, true);
    }

    $pdo->prepare("UPDATE introductions SET folder_path = ? WHERE id = ?")->execute([$desiredPath, $introId]);
    if (!is_dir($desiredPath . '/معرفی‌نامه')) @mkdir($desiredPath . '/معرفی‌نامه', 0777, true);

    return $desiredPath;
}

// مسیر پوشه‌ی یک پرونده (پلاک) را برمی‌گرداند؛ اگر هنوز ثبت نشده، بر اساس اطلاعات فعلی می‌سازد
function resolve_case_folder($pdo, $siteRoot, $case) {
    if (!empty($case['folder_path']) && is_dir($case['folder_path'])) return $case['folder_path'];
    $introFolder = sync_intro_folder($pdo, $siteRoot, $case['introduction_id']);
    return $introFolder . '/' . build_case_folder_name($case['plate'], $case['insured_name']);
}

// ساخت/به‌روزرسانی فایل متنیِ «اطلاعات درخواستی» داخل پوشه‌ی هر درخواست، با تفکیک
// اطلاعات شخص، بیمه‌گذار، خودرو و بیمه‌نامه‌ی درخواستی
function write_case_info_file($pdo, $caseFolder, $caseId) {
    if (!$caseFolder || !is_dir($caseFolder)) return null;

    $stmt = $pdo->prepare("
        SELECT pc.*, p.full_name AS holder_name, p.national_code AS holder_national_code,
               p.personnel_code AS holder_personnel_code, p.mobile_number AS holder_mobile,
               c.name AS company_name
        FROM policy_cases pc
        JOIN persons p ON pc.person_id = p.id
        LEFT JOIN companies c ON p.company_id = c.id
        WHERE pc.id = ?
    ");
    $stmt->execute([$caseId]);
    $cs = $stmt->fetch();
    if (!$cs) return null;

    $nl = "\r\n";   // برای اینکه در Notepad ویندوز درست نمایش داده شود
    $v = fn($x) => ($x === null || $x === '') ? '-' : $x;
    $money = fn($x) => $x ? number_format((int)$x) : '-';

    $t  = "════════════════════════════════════════" . $nl;
    $t .= "            اطلاعات درخواستی" . $nl;
    $t .= "════════════════════════════════════════" . $nl . $nl;

    $t .= "■ اطلاعات شخص (دارنده‌ی معرفی‌نامه)" . $nl;
    $t .= "  نام و نام خانوادگی : " . $v($cs['holder_name']) . $nl;
    $t .= "  کد ملی             : " . $v($cs['holder_national_code']) . $nl;
    $t .= "  کد پرسنلی          : " . $v($cs['holder_personnel_code']) . $nl;
    $t .= "  شماره موبایل       : " . $v($cs['holder_mobile']) . $nl;
    $t .= "  شرکت               : " . $v($cs['company_name']) . $nl . $nl;

    $t .= "■ اطلاعات بیمه‌گذار" . $nl;
    $t .= "  نام و نام خانوادگی : " . $v($cs['insured_name']) . $nl;
    $t .= "  کد ملی             : " . $v($cs['insured_national_id']) . $nl;
    $t .= "  تاریخ تولد         : " . $v($cs['insured_birth_date']) . $nl;
    $t .= "  شماره موبایل       : " . $v($cs['insured_phone']) . $nl;
    $t .= "  کد پستی            : " . $v($cs['insured_postal_code']) . $nl;
    $t .= "  نسبت با پرسنل      : " . $v($cs['insured_relationship']) . $nl;
    $t .= "  آدرس               : " . $v($cs['insured_address']) . $nl . $nl;

    $t .= "■ اطلاعات خودرو" . $nl;
    $t .= "  پلاک               : " . $v($cs['plate']) . $nl;
    $t .= "  مدرک مالکیت        : " . $v($cs['ownership_choice']) . $nl;
    $t .= "  سیستم / تیپ        : " . $v($cs['car_system']) . ($cs['car_type'] ? ' / ' . $cs['car_type'] : '') . $nl;
    $t .= "  مدل (سال ساخت)     : " . $v($cs['car_model_year']) . $nl;
    $t .= "  رنگ                : " . $v($cs['car_color']) . $nl;
    $t .= "  شماره شاسی         : " . $v($cs['chassis_num']) . $nl;
    $t .= "  شماره موتور        : " . $v($cs['engine_num']) . $nl;
    $t .= "  VIN                : " . $v($cs['vin']) . $nl . $nl;

    $t .= "■ اطلاعات بیمه‌نامه‌ی درخواستی" . $nl;
    $t .= "  نوع بیمه           : " . insurance_type_fa($cs['insurance_type']) . $nl;
    $t .= "  شناسه‌ی درخواست     : " . $v($cs['unique_code']) . $nl;
    if ($cs['insurance_type'] === 'THIRDPARTY') {
        $t .= "  سقف تعهد مالی      : " . ($cs['liability_limit'] ? $money($cs['liability_limit'] / 10) . " تومان" : '-') . $nl;
    } else {
        $t .= "  بیمه بدنه‌ی قبلی    : " . $v($cs['prev_body_insurance']) . $nl;
        $t .= "  خسارت بیمه‌ی قبلی   : " . $v($cs['prev_body_claim']) . $nl;
        $t .= "  سابقه بدون خسارت   : " . ($cs['no_claim_years'] !== null ? $cs['no_claim_years'] . " سال" : '-') . $nl;
        $t .= "  ارزش خودرو         : " . ($cs['estimated_car_value'] ? $money($cs['estimated_car_value']) . " تومان" : '-') . $nl;
        if (!empty($cs['selected_coverages'])) {
            $cov = json_decode($cs['selected_coverages'], true);
            if (is_array($cov) && $cov) {
                $labels = [];
                $opts = body_coverage_options();
                foreach ($cov as $k => $tier) {
                    $nm = $opts[$k]['label'] ?? $k;
                    $labels[] = ($tier && $tier !== true && $tier !== '1') ? "{$nm} ({$tier})" : $nm;
                }
                $t .= "  پوشش‌های تکمیلی    : " . implode('، ', $labels) . $nl;
            }
        }
    }
    if ($cs['status'] === 'ISSUED') {
        $t .= $nl . "■ اطلاعات صدور" . $nl;
        $t .= "  شماره بیمه‌نامه     : " . $v($cs['policy_number']) . $nl;
        $t .= "  کد یکتا بیمه مرکزی : " . $v($cs['central_unique_code']) . $nl;
        $t .= "  حق بیمه کل         : " . ($cs['total_premium'] ? $money($cs['total_premium']) . " ریال" : '-') . $nl;
        $t .= "  تاریخ صدور         : " . $v($cs['policy_issue_date']) . $nl;
    }

    $t .= $nl . "────────────────────────────────────────" . $nl;
    $t .= "آخرین به‌روزرسانی: " . jalali_from_gregorian_ts_dotted(time()) . $nl;

    $path = $caseFolder . '/اطلاعات درخواستی.txt';
    // BOM برای اینکه Notepad ویندوز فارسی را درست بخواند
    @file_put_contents($path, "\xEF\xBB\xBF" . $t);
    return $path;
}

// حذف بازگشتیِ فایل تکراری با افزودن شماره در صورت وجودِ هم‌نام
function unique_dest_path($destPath) {
    if (!file_exists($destPath)) return $destPath;
    $ext = pathinfo($destPath, PATHINFO_EXTENSION);
    $base = pathinfo($destPath, PATHINFO_FILENAME);
    $dir = dirname($destPath);
    $counter = 1;
    do { $candidate = "{$dir}/{$base}_{$counter}.{$ext}"; $counter++; } while (file_exists($candidate));
    return $candidate;
}

// وقتی همه‌ی مدارک یک پرونده تایید شد (ورود به مرحله‌ی «در حال صدور»)، فایل‌های موقت
// را با نام‌گذاری صحیح به پوشه‌ی دائمی («بایگانی کسر از حقوق») منتقل می‌کند
function finalize_case_documents_to_archive($pdo, $siteRoot, $caseId) {
    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case) return null;

    $introFolder = sync_intro_folder($pdo, $siteRoot, $case['introduction_id']);
    $caseFolder = $introFolder . '/' . build_case_folder_name($case['plate'], $case['insured_name']);
    if (!is_dir($caseFolder)) @mkdir($caseFolder, 0777, true);

    $stmt = $pdo->prepare("SELECT * FROM case_documents WHERE case_id = ? AND status IN ('PENDING','APPROVED')");
    $stmt->execute([$caseId]);
    $tempDirsTouched = [];

    foreach ($stmt->fetchAll() as $doc) {
        if (!$doc['file_path']) continue;
        $absOld = $siteRoot . '/' . $doc['file_path'];
        if (!file_exists($absOld)) continue;
        $tempDirsTouched[] = dirname($absOld);

        $ext = pathinfo($absOld, PATHINFO_EXTENSION) ?: 'jpg';
        $newName = build_doc_filename($doc['doc_key'], $doc['doc_label'], $case['plate'], $case['insured_name'], $case['insured_national_id'], $ext);
        $absNew = unique_dest_path($caseFolder . '/' . $newName);
        @rename($absOld, $absNew);

        $newRel = ltrim(str_replace($siteRoot, '', $absNew), '/');
        $pdo->prepare("UPDATE case_documents SET file_path = ? WHERE id = ?")->execute([$newRel, $doc['id']]);
    }

    $pdo->prepare("UPDATE policy_cases SET folder_path = ? WHERE id = ?")->execute([$caseFolder, $caseId]);

    // فایل متنیِ خلاصه‌ی اطلاعات درخواست، کنار مدارک
    write_case_info_file($pdo, $caseFolder, $caseId);

    foreach (array_unique($tempDirsTouched) as $d) cleanup_empty_dirs_upward($d, temp_archive_root($siteRoot));

    return $caseFolder;
}

// آیا این پرونده بازدیدِ سلامتِ تاییدشده‌ای دارد که هنوز فایل گزارش کارشناس برایش آپلود نشده؟
function has_pending_health_report($pdo, $caseId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM health_inspections WHERE case_id = ? AND status = 'APPROVED' AND (report_file_path IS NULL OR report_file_path = '')");
    $stmt->execute([$caseId]);
    return intval($stmt->fetchColumn()) > 0;
}

// کپیِ بازگشتیِ یک پوشه (برای «بایگانی صادره» که باید نسخه‌ی مستقل/کپی باشد، نه انتقال)
function copy_dir_recursive($src, $dst) {
    if (!is_dir($dst)) @mkdir($dst, 0777, true);
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item; $d = $dst . '/' . $item;
        if (is_dir($s)) copy_dir_recursive($s, $d);
        else @copy($s, $d);
    }
}

// =====================================================================
//  ابزار ساخت فایل ZIP موقت (برای دانلود، سپس حذف - در بایگانی نگه داشته نمی‌شود)
// =====================================================================
function build_zip_from_files($files, $zipDisplayName) {
    // $files: آرایه‌ای از ['path' => مسیر مطلق روی دیسک, 'name' => نامی که داخل زیپ نشان داده شود]
    $tmpDir = sys_get_temp_dir();
    $zipPath = $tmpDir . '/' . uniqid('zip_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) return null;
    foreach ($files as $f) {
        if (file_exists($f['path'])) $zip->addFile($f['path'], $f['name']);
    }
    $zip->close();
    return $zipPath;
}

// =====================================================================
//  تبدیل جلالی به میلادی (برعکسِ jalali_from_gregorian_ts) - برای تفسیر
//  بازه‌ی تاریخ ورودی کارشناس در فایل‌منیجر بایگانی
// =====================================================================
function jalali_to_gregorian_ts($jy, $jm, $jd) {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd;
    $days += ($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186;
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $g_d_m = [0, 31, ((($gy % 4 == 0) && ($gy % 100 != 0)) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    for ($i = 1; $i <= 12; $i++) {
        if ($gd <= $g_d_m[$i]) { $gm = $i; break; }
        $gd -= $g_d_m[$i];
    }
    return mktime(0, 0, 0, $gm, $gd, $gy);
}

// یک رشته‌ی «۱۴۰۵/۰۶/۱۰» را به تایم‌استمپ میلادی تبدیل می‌کند
function parse_jalali_date_string($str) {
    if (!preg_match('/^(\d{4})\/(\d{1,2})(?:\/(\d{1,2}))?$/', trim($str), $m)) return null;
    $jy = intval($m[1]); $jm = intval($m[2]); $jd = isset($m[3]) ? intval($m[3]) : 1;
    return jalali_to_gregorian_ts($jy, $jm, $jd);
}

function format_file_size($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
