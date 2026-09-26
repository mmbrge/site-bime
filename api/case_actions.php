<?php
// فایل: api/case_actions.php
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

function get_bot_token($pdo) {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bot_token'");
    return $stmt->fetchColumn() ?: null;
}

function notify_customer($pdo, $chatId, $text) {
    if (!$chatId) return;
    $token = get_bot_token($pdo);
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/sendMessage");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $chatId, 'text' => $text], JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch); curl_close($ch);
}

// تاییدِ یک مدرک + بررسیِ اینکه آیا با تاییدِ همین یکی، همه‌ی مدارکِ لازمِ پرونده کامل
// شده‌اند (که در این صورت پرونده وارد «در حال صدور» می‌شود و مدارک بایگانی می‌شوند) -
// دقیقاً هم‌منطقِ approve_doc، برای استفاده‌ی مشترک با آپلود مستقیم ادمین
function approve_case_document_and_maybe_finalize($pdo, $docId) {
    $pdo->prepare("UPDATE case_documents SET status = 'APPROVED', reviewed_at = NOW() WHERE id = ?")->execute([$docId]);
    $stmt = $pdo->prepare("SELECT case_id FROM case_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $caseId = $stmt->fetchColumn();
    if (!$caseId) return;

    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case) return;

    $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
    $stmt = $pdo->prepare("SELECT doc_key FROM case_documents WHERE case_id = ? AND status = 'APPROVED'");
    $stmt->execute([$caseId]);
    $approvedKeys = array_column($stmt->fetchAll(), 'doc_key');

    if (count(array_diff(array_keys($required), $approvedKeys)) === 0) {
        $pdo->prepare("UPDATE policy_cases SET status = 'ISSUING' WHERE id = ?")->execute([$caseId]);
        finalize_case_documents_to_archive($pdo, dirname(__DIR__), $caseId);
    }
}

try {
    // ۱. لیست پرونده‌های صدور
    if ($action === 'list') {
        $introFilter = intval($_GET['introduction_id'] ?? 0);
        $sql = "
            SELECT pc.*, p.full_name AS holder_name, p.national_code AS holder_national_code,
                   p.bale_chat_id,
                   (SELECT COUNT(*) FROM case_documents cd WHERE cd.case_id = pc.id) AS docs_total,
                   (SELECT COUNT(*) FROM case_documents cd WHERE cd.case_id = pc.id AND cd.status = 'APPROVED') AS docs_approved,
                   (SELECT COUNT(*) FROM case_documents cd WHERE cd.case_id = pc.id AND cd.status = 'PENDING') AS docs_pending,
                   (SELECT COUNT(*) FROM case_documents cd WHERE cd.case_id = pc.id AND cd.status = 'REJECTED') AS docs_rejected
            FROM policy_cases pc
            JOIN persons p ON pc.person_id = p.id
        ";
        $params = [];
        if ($introFilter) { $sql .= " WHERE pc.introduction_id = ?"; $params[] = $introFilter; }
        $sql .= " ORDER BY (pc.status = 'DOCS_REVIEW') DESC, pc.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // ۲. مدارک یک پرونده + اطلاعات معرفی‌نامه‌ی مرتبط
    if ($action === 'detail') {
        $caseId = intval($_GET['case_id'] ?? ($data['case_id'] ?? 0));
        $stmt = $pdo->prepare("
            SELECT pc.*, p.full_name AS holder_name, p.national_code AS holder_national_code,
                   p.personnel_code AS holder_personnel_code, c.name AS company_name,
                   i.letter_date, i.max_quota, i.used_quota
            FROM policy_cases pc
            JOIN persons p ON pc.person_id = p.id
            LEFT JOIN companies c ON p.company_id = c.id
            JOIN introductions i ON pc.introduction_id = i.id
            WHERE pc.id = ?
        ");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM case_documents WHERE case_id = ? ORDER BY uploaded_at ASC");
        $stmt->execute([$caseId]);
        $docs = $stmt->fetchAll();

        // برچسب فارسیِ پوشش‌های بدنه هم فرستاده می‌شود تا پنل به‌جای کلیدهای انگلیسی
        // (price_fluctuation و ...) نام خوانای فارسی را نمایش دهد
        $coverageLabels = [];
        foreach (body_coverage_options() as $key => $opt) { $coverageLabels[$key] = $opt['label']; }
        $requiredDocs = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
        echo json_encode(['ok' => true, 'case' => $case, 'documents' => $docs, 'coverage_labels' => $coverageLabels, 'required_docs' => $requiredDocs], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۳. تایید یک مدرک
    if ($action === 'approve_all_docs') {
        $caseId = intval($data['case_id'] ?? 0);
        $pdo->prepare("UPDATE case_documents SET status = 'APPROVED', reviewed_at = NOW() WHERE case_id = ? AND status = 'PENDING'")->execute([$caseId]);

        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if ($case) {
            $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
            $stmt = $pdo->prepare("SELECT doc_key FROM case_documents WHERE case_id = ? AND status = 'APPROVED'");
            $stmt->execute([$caseId]);
            $approvedKeys = array_column($stmt->fetchAll(), 'doc_key');
            if (count(array_diff(array_keys($required), $approvedKeys)) === 0) {
                $pdo->prepare("UPDATE policy_cases SET status = 'ISSUING' WHERE id = ?")->execute([$caseId]);
                finalize_case_documents_to_archive($pdo, dirname(__DIR__), $caseId);
                $stmt = $pdo->prepare("SELECT bale_chat_id FROM persons WHERE id = ?");
                $stmt->execute([$case['person_id']]);
                notify_customer_app($pdo, $case['person_id'], $stmt->fetchColumn(),
                    "مدارک تایید شد", "✅ مدارک مربوط به صدور بیمه " . insurance_type_fa($case['insurance_type']) . " پلاک " . ($case['plate'] ?: 'بدون پلاک') . " تایید شد و پرونده وارد مرحله‌ی «در حال صدور» شد.",
                    'success', $caseId);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'approve_doc') {
        $docId = intval($data['doc_id'] ?? 0);
        $pdo->prepare("UPDATE case_documents SET status = 'APPROVED', reviewed_at = NOW() WHERE id = ?")->execute([$docId]);

        // اگر همه‌ی مدارک لازم تایید شده باشند، پرونده وارد مرحله‌ی «در حال صدور» می‌شود
        $stmt = $pdo->prepare("SELECT case_id FROM case_documents WHERE id = ?");
        $stmt->execute([$docId]);
        $caseId = $stmt->fetchColumn();
        if ($caseId) {
            $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
            $stmt->execute([$caseId]);
            $case = $stmt->fetch();
            $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);

            $stmt = $pdo->prepare("SELECT doc_key FROM case_documents WHERE case_id = ? AND status = 'APPROVED'");
            $stmt->execute([$caseId]);
            $approvedKeys = array_column($stmt->fetchAll(), 'doc_key');

            $allApproved = count(array_diff(array_keys($required), $approvedKeys)) === 0;
            if ($allApproved) {
                $pdo->prepare("UPDATE policy_cases SET status = 'ISSUING' WHERE id = ?")->execute([$caseId]);
                finalize_case_documents_to_archive($pdo, dirname(__DIR__), $caseId);
                $stmt = $pdo->prepare("SELECT bale_chat_id FROM persons WHERE id = ?");
                $stmt->execute([$case['person_id']]);
                notify_customer_app($pdo, $case['person_id'], $stmt->fetchColumn(),
                    "مدارک تایید شد", "✅ مدارک مربوط به صدور بیمه " . insurance_type_fa($case['insurance_type']) . " پلاک " . ($case['plate'] ?: 'بدون پلاک') . " تایید شد و پرونده وارد مرحله‌ی «در حال صدور» شد.",
                    'success', $caseId);
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ۴. رد یک مدرک (با ذکر دلیل) - کاربر خودکار به حالت اصلاح مدرک هدایت می‌شود
    if ($action === 'reject_doc') {
        $docId = intval($data['doc_id'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        if ($reason === '') { echo json_encode(['ok' => false, 'error' => 'ذکر دلیل رد الزامی است.']); exit; }

        $stmt = $pdo->prepare("SELECT case_id, doc_label FROM case_documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) { echo json_encode(['ok' => false, 'error' => 'مدرک یافت نشد.']); exit; }

        $pdo->prepare("UPDATE case_documents SET status = 'REJECTED', reject_reason = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$reason, $docId]);

        $stmt = $pdo->prepare("SELECT pc.person_id, pc.plate, pc.insurance_type, p.bale_chat_id FROM policy_cases pc JOIN persons p ON pc.person_id = p.id WHERE pc.id = ?");
        $stmt->execute([$doc['case_id']]);
        $row = $stmt->fetch();

        if ($row) {
            $pdo->prepare("UPDATE persons SET conversation_state = 'COLLECTING_DOCS', active_case_id = ? WHERE id = ?")
                ->execute([$doc['case_id'], $row['person_id']]);
            // در اعلان، حتماً نوع بیمه و پلاکِ همان درخواست ذکر می‌شود تا کاربری که چند درخواست
            // همزمان دارد بداند این پیام مربوط به کدام یکی است
            $ctx = "بیمه " . insurance_type_fa($row['insurance_type']) . " پلاک " . ($row['plate'] ?: 'بدون پلاک');
            notify_customer_app($pdo, $row['person_id'], $row['bale_chat_id'], "مدرک رد شد", "❌ «{$doc['doc_label']}» مربوط به {$ctx} رد شد.\nعلت: {$reason}", 'error', $doc['case_id']);

            // دکمه‌ی شیشه‌ای برای ارسال دوباره‌ی همین مورد، مستقیماً کنار همین پیام
            $token = get_bot_token($pdo);
            $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/sendMessage");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'chat_id' => $row['bale_chat_id'],
                'text' => "برای ارسال دوباره‌ی «{$doc['doc_label']}» دکمه‌ی زیر را بزنید:",
                'reply_markup' => ['inline_keyboard' => [[['text' => '📤 ارسال دوباره', 'callback_data' => 'redoc:' . $doc['case_id']]]]],
            ], JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch); curl_close($ch);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ۵. ثبت دستی فیلدهای نام‌گذاری (پلاک، نام بیمه‌گذار و ...) توسط کارشناس
    if ($action === 'request_fix') {
        $caseId = intval($data['case_id'] ?? 0);
        $fields = $data['fields'] ?? ($data['field'] ? [$data['field']] : []);
        $note = trim($data['note'] ?? '');
        $fieldLabels = [
            'plate' => 'پلاک خودرو', 'insured_name' => 'نام بیمه‌گذار', 'insured_national_id' => 'کد ملی بیمه‌گذار',
            'insured_birth_date' => 'تاریخ تولد', 'insured_address' => 'آدرس', 'insured_phone' => 'شماره موبایل',
            'insured_postal_code' => 'کد پستی',
        ];
        $fields = array_values(array_intersect($fields, array_keys($fieldLabels)));
        if (!$fields) { echo json_encode(['ok' => false, 'error' => 'حداقل یک فیلد معتبر انتخاب کنید.']); exit; }

        $stmt = $pdo->prepare("SELECT pc.*, p.bale_chat_id FROM policy_cases pc JOIN persons p ON pc.person_id = p.id WHERE pc.id = ?");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if (!$case || !$case['bale_chat_id']) { echo json_encode(['ok' => false, 'error' => 'کاربر چت بله متصلی ندارد.']); exit; }

        $labelsList = array_map(fn($f) => $fieldLabels[$f], $fields);
        $pdo->prepare("UPDATE policy_cases SET status = 'DOCS_PENDING', last_status_note = ? WHERE id = ?")->execute([implode('، ', $labelsList) . ($note ? ": {$note}" : ''), $caseId]);
        $pdo->prepare("UPDATE persons SET conversation_state = 'FIXING_FIELD', active_case_id = ?, flow_temp = ? WHERE id = ?")
            ->execute([$caseId, json_encode(['fix_fields' => $fields, 'fix_labels' => $labelsList, 'fix_index' => 0], JSON_UNESCAPED_UNICODE), $case['person_id']]);

        $msg = "⚠️ نیاز به اصلاح دارید:\n• " . implode("\n• ", $labelsList) . "\n";
        if ($note) $msg .= "توضیح کارشناس: {$note}\n";
        $msg .= "لطفاً مقادیر صحیح را یکی‌یکی ارسال کنید. شروع با: {$labelsList[0]}";
        notify_customer_app($pdo, $case['person_id'], $case['bale_chat_id'], "نیاز به اصلاح اطلاعات", $msg, 'warning', $caseId);

        echo json_encode(['ok' => true]);
        exit;
    }

    // دانلود زیپ عکس‌های یک بازدید سلامت (موقت؛ بعد از دانلود از سرور حذف می‌شود)
    if ($action === 'download_health_zip') {
        $inspectionId = intval($_GET['inspection_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT hi.*, pc.plate FROM health_inspections hi JOIN policy_cases pc ON hi.case_id = pc.id WHERE hi.id = ?");
        $stmt->execute([$inspectionId]);
        $insp = $stmt->fetch();
        if (!$insp) { http_response_code(404); exit; }

        $photos = json_decode($insp['photos'] ?: '{}', true);
        $files = [];
        foreach ($photos as $stepKey => $relPath) {
            $abs = dirname(__DIR__) . '/' . $relPath;
            $files[] = ['path' => $abs, 'name' => basename($relPath)];
        }
        $zipName = sanitize_folder_name(($insp['plate'] ?: 'بدون‌پلاک') . ' - بازدید سلامت ' . $insp['inspection_number']) . '.zip';
        $zipPath = build_zip_from_files($files, $zipName);
        if (!$zipPath) { http_response_code(500); exit; }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath); // زیپ موقت است؛ در بایگانی نگه داشته نمی‌شود
        exit;
    }

    // دانلود زیپ مدارک یک پرونده (همه یا انتخابی)
    if ($action === 'download_docs_zip') {
        $caseId = intval($_GET['case_id'] ?? 0);
        $docIds = isset($_GET['doc_ids']) && $_GET['doc_ids'] !== '' ? array_map('intval', explode(',', $_GET['doc_ids'])) : null;

        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if (!$case) { http_response_code(404); exit; }

        $sql = "SELECT * FROM case_documents WHERE case_id = ?";
        $params = [$caseId];
        if ($docIds) {
            $sql .= " AND id IN (" . implode(',', array_fill(0, count($docIds), '?')) . ")";
            $params = array_merge($params, $docIds);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll();

        $files = [];
        foreach ($docs as $d) {
            if (!$d['file_path']) continue;
            $abs = dirname(__DIR__) . '/' . $d['file_path'];
            $ext = pathinfo($abs, PATHINFO_EXTENSION);
            $files[] = ['path' => $abs, 'name' => sanitize_folder_name($d['doc_label']) . '.' . $ext];
        }
        $zipName = sanitize_folder_name(($case['plate'] ?: 'بدون‌پلاک') . ' - عکس‌های مدارک') . '.zip';
        $zipPath = build_zip_from_files($files, $zipName);
        if (!$zipPath) { http_response_code(500); exit; }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    // آپلود فایل گزارش بازدید سلامت توسط کارشناس (جدا از عکس‌های خودِ کاربر)
    if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'upload_health_report') {
        $inspectionId = intval($_POST['inspection_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT hi.*, pc.plate, pc.folder_path FROM health_inspections hi JOIN policy_cases pc ON hi.case_id = pc.id WHERE hi.id = ?");
        $stmt->execute([$inspectionId]);
        $insp = $stmt->fetch();
        if (!$insp) { echo json_encode(['ok' => false, 'error' => 'بازدید یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $stmt2 = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
        $stmt2->execute([$insp['case_id']]);
        $fullCase = $stmt2->fetch();
        $caseFolder = resolve_case_folder($pdo, $siteRoot, $fullCase);

        $approvedDate = $insp['approved_jalali_date'] ?: jalali_from_gregorian_ts_dotted(time());
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $destPath = unique_dest_path($caseFolder . '/' . build_health_report_filename($approvedDate, $insp['plate'], $ext));
        move_uploaded_file($_FILES['file']['tmp_name'], $destPath);
        $relPath = ltrim(str_replace($siteRoot, '', $destPath), '/');

        $pdo->prepare("UPDATE health_inspections SET report_file_path = ? WHERE id = ?")->execute([$relPath, $inspectionId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ۶. ثبت دستی فیلدهای نام‌گذاری (پلاک، نام بیمه‌گذار و ...) توسط کارشناس
    if ($action === 'health_list') {
        $stmt = $pdo->query("
            SELECT hi.*,
                   COALESCE(pc.unique_code, CONCAT('آزاد-', hi.id)) AS unique_code,
                   COALESCE(pc.plate, hi.free_plate) AS plate,
                   COALESCE(p1.full_name, p2.full_name) AS holder_name,
                   (hi.case_id IS NULL) AS is_free
            FROM health_inspections hi
            LEFT JOIN policy_cases pc ON hi.case_id = pc.id
            LEFT JOIN persons p1 ON pc.person_id = p1.id
            LEFT JOIN persons p2 ON hi.person_id = p2.id
            ORDER BY (hi.status = 'PENDING') DESC, hi.created_at DESC
        ");
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'approve_health') {
        $id = intval($data['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT hi.*, COALESCE(pc.plate, hi.free_plate) AS plate FROM health_inspections hi LEFT JOIN policy_cases pc ON hi.case_id = pc.id WHERE hi.id = ?");
        $stmt->execute([$id]);
        $insp = $stmt->fetch();
        if (!$insp) { echo json_encode(['ok' => false, 'error' => 'بازدید یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $approvedDate = jalali_from_gregorian_ts_dotted(time());
        $newFolderName = build_health_photos_folder_name($approvedDate, $insp['plate']);
        $newFolderPath = dirname($insp['photos_folder_path']) . '/' . $newFolderName;

        if ($insp['photos_folder_path'] && is_dir($insp['photos_folder_path']) && $insp['photos_folder_path'] !== $newFolderPath) {
            @rename($insp['photos_folder_path'], $newFolderPath);
            // مسیرهای عکس‌های ذخیره‌شده در JSON را هم با پوشه‌ی جدید هماهنگ می‌کنیم
            $photos = json_decode($insp['photos'] ?: '{}', true);
            $newPhotos = [];
            foreach ($photos as $k => $relPath) {
                $newPhotos[$k] = str_replace(basename($insp['photos_folder_path']), $newFolderName, $relPath);
            }
            $pdo->prepare("UPDATE health_inspections SET photos = ?, photos_folder_path = ?, approved_jalali_date = ?, status = 'APPROVED', reviewed_at = NOW() WHERE id = ?")
                ->execute([json_encode($newPhotos, JSON_UNESCAPED_UNICODE), $newFolderPath, $approvedDate, $id]);
        } else {
            $pdo->prepare("UPDATE health_inspections SET approved_jalali_date = ?, status = 'APPROVED', reviewed_at = NOW() WHERE id = ?")
                ->execute([$approvedDate, $id]);
        }

        $stmt = $pdo->prepare("SELECT COALESCE(pc.person_id, hi.person_id) AS person_id, p.bale_chat_id, hi.inspection_number, COALESCE(pc.plate, hi.plate) AS plate, pc.insurance_type FROM health_inspections hi LEFT JOIN policy_cases pc ON hi.case_id = pc.id JOIN persons p ON p.id = COALESCE(pc.person_id, hi.person_id) WHERE hi.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $ctx = $row['insurance_type'] ? ("بیمه " . insurance_type_fa($row['insurance_type']) . " پلاک " . ($row['plate'] ?: 'بدون پلاک')) : ("پلاک " . ($row['plate'] ?: 'بدون پلاک'));
            notify_customer_app($pdo, $row['person_id'], $row['bale_chat_id'], "بازدید سلامت تایید شد", "✅ بازدید سلامت مربوط به {$ctx} تایید شد.", 'success');
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reject_health') {
        $id = intval($data['id'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        $pdo->prepare("UPDATE health_inspections SET status = 'REJECTED', reject_reason = ?, reviewed_at = NOW() WHERE id = ?")->execute([$reason, $id]);
        $stmt = $pdo->prepare("SELECT COALESCE(pc.person_id, hi.person_id) AS person_id, p.bale_chat_id, hi.inspection_number, COALESCE(pc.plate, hi.plate) AS plate, pc.insurance_type FROM health_inspections hi LEFT JOIN policy_cases pc ON hi.case_id = pc.id JOIN persons p ON p.id = COALESCE(pc.person_id, hi.person_id) WHERE hi.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $ctx = $row['insurance_type'] ? ("بیمه " . insurance_type_fa($row['insurance_type']) . " پلاک " . ($row['plate'] ?: 'بدون پلاک')) : ("پلاک " . ($row['plate'] ?: 'بدون پلاک'));
            notify_customer_app($pdo, $row['person_id'], $row['bale_chat_id'], "بازدید سلامت رد شد", "❌ بازدید سلامت مربوط به {$ctx} رد شد.\nعلت: {$reason}\nلطفاً از منوی «بازدید سلامت خودرو» دوباره ارسال کنید.", 'error');
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ۶. ثبت دستی فیلدهای نام‌گذاری (پلاک، نام بیمه‌گذار و ...) توسط کارشناس
    if ($action === 'set_naming') {
        $caseId = intval($data['case_id'] ?? 0);
        $plate = trim($data['plate'] ?? '');
        $insuredName = trim($data['insured_name'] ?? '');
        $insuredNationalId = trim($data['insured_national_id'] ?? '');
        // این دو مورد فقط برای پرونده‌های ثبتِ دستی است، تا مدارکِ لازم (get_required_docs_v2)
        // درست محاسبه شوند - در بقیه‌ی حالت‌ها این دو قبلاً توسط خودِ شخص در ویزارد پر شده‌اند
        $ownershipChoice = in_array($data['ownership_choice'] ?? '', ['کارت ماشین', 'سند'], true) ? $data['ownership_choice'] : '';
        $prevBodyInsurance = in_array($data['prev_body_insurance'] ?? '', ['بله', 'خیر'], true) ? $data['prev_body_insurance'] : '';

        $fields = [];
        if ($plate !== '') $fields['plate'] = $plate;
        if ($insuredName !== '') $fields['insured_name'] = $insuredName;
        if ($insuredNationalId !== '') $fields['insured_national_id'] = $insuredNationalId;

        $sets = []; $params = [];
        if ($plate !== '') { $sets[] = 'plate = ?'; $params[] = $plate; }
        if ($insuredName !== '') { $sets[] = 'insured_name = ?'; $params[] = $insuredName; }
        if ($insuredNationalId !== '') { $sets[] = 'insured_national_id = ?'; $params[] = $insuredNationalId; }
        if ($ownershipChoice !== '') { $sets[] = 'ownership_choice = ?'; $params[] = $ownershipChoice; }
        if ($prevBodyInsurance !== '') { $sets[] = 'prev_body_insurance = ?'; $params[] = $prevBodyInsurance; }
        $sets[] = 'naming_fields = ?'; $params[] = json_encode($fields, JSON_UNESCAPED_UNICODE);
        $params[] = $caseId;

        $pdo->prepare("UPDATE policy_cases SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- بارگذاری مستقیمِ یک مدرک توسط خودمان (ادمین) برای پرونده‌ای که خودمان دستی
    //      ثبت کرده‌ایم - چون خودمان مدرک را می‌بینیم و وارد می‌کنیم، نیازی به مرحله‌ی
    //      تایید جداگانه نیست و مستقیم APPROVED ثبت می‌شود (هم‌شکل با approve_doc) ----
    if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'admin_upload_case_doc') {
        $caseId = intval($_POST['case_id'] ?? 0);
        $docKey = trim($_POST['doc_key'] ?? '') ?: 'other';
        $docLabel = trim($_POST['doc_label'] ?? '') ?: $docKey;

        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            echo json_encode(['ok' => false, 'error' => 'فایل ارسال نشد.']); exit;
        }

        $siteRoot = dirname(__DIR__);
        $tmpDir = temp_archive_root($siteRoot) . '/مدارک دستی ادمین';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $destPath = $tmpDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل.']); exit;
        }
        $relPath = ltrim(str_replace($siteRoot, '', $destPath), '/');

        $pdo->prepare("INSERT INTO case_documents (case_id, doc_key, doc_label, file_path, status) VALUES (?, ?, ?, ?, 'PENDING')")
            ->execute([$caseId, $docKey, $docLabel, $relPath]);
        $docId = $pdo->lastInsertId();
        approve_case_document_and_maybe_finalize($pdo, $docId);

        echo json_encode(['ok' => true, 'doc_id' => $docId]);
        exit;
    }

    // ۷الف. مرحله‌ی اول: آپلود فایل بیمه‌نامه + اجرای OCR + نمایش فیلدهای شناسایی‌شده برای
    // ویرایش (هنوز چیزی صادر/آرشیو نمی‌شود - فقط شناسایی و آماده‌سازی برای تایید نهایی)
    if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'ocr_preview_policy') {
        $caseId = intval($_POST['case_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }

        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM case_documents WHERE case_id = ? AND status != 'APPROVED'");
        $stmt2->execute([$caseId]);
        if (intval($stmt2->fetchColumn()) > 0) {
            echo json_encode(['ok' => false, 'error' => 'هنوز همه‌ی مدارک این پرونده تایید نشده‌اند.']); exit;
        }
        if (has_pending_health_report($pdo, $caseId)) {
            echo json_encode(['ok' => false, 'error' => 'ابتدا باید گزارش کارشناسِ بازدید سلامت را بارگذاری کنید.']); exit;
        }

        $siteRoot = dirname(__DIR__);
        // نکته‌ی مهم (باگ واقعی رفع‌شده): مسیر «موقت بایگانی» شامل حروف فارسی و فاصله است و
        // اسکریپت پایتون نمی‌تواند چنین مسیری را باز کند. برای همین فایلِ موقتِ OCR در یک مسیر
        // کاملاً انگلیسی و بدون فاصله ساخته می‌شود.
        $tempDir = $siteRoot . '/tmp_ocr';
        if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) ?: 'pdf';
        $tempPath = $tempDir . '/' . uniqid('policy_') . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $tempPath)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در دریافت فایل.']); exit;
        }

        // اگر قبلاً یک فایل موقتِ دیگر برای همین پرونده مانده بود (مثلاً از یک تلاش ناتمام قبلی)، پاکش می‌کنیم
        if ($case['pending_policy_temp_path'] && file_exists($case['pending_policy_temp_path'])) {
            @unlink($case['pending_policy_temp_path']);
        }

        $ocrData = null; $ocrDebug = null;
        if (strtolower($ext) === 'pdf') {
            $ocrResult = run_document_ocr_verbose($tempPath);
            $ocrData = $ocrResult['data'];
            $ocrDebug = $ocrResult['debug'];
        } else {
            $ocrDebug = 'فقط فایل PDF قابل شناسایی خودکار است (این فایل ' . strtoupper($ext) . ' بود).';
        }

        $pdo->prepare("UPDATE policy_cases SET pending_policy_temp_path = ?, pending_policy_orig_name = ?, ocr_extracted_data = ? WHERE id = ?")
            ->execute([$tempPath, $_FILES['file']['name'], $ocrData ? json_encode($ocrData, JSON_UNESCAPED_UNICODE) : null, $caseId]);

        echo json_encode(['ok' => true, 'ocr' => $ocrData, 'ocr_used' => (bool)$ocrData, 'ocr_debug' => $ocrDebug], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۷ب. مرحله‌ی دوم: تایید نهایی - ابتدا پلاک، سپس نوع بیمه را با اطلاعات خودِ پرونده چک
    // می‌کند؛ اگر هر دو مطابقت داشت، آرشیو/نام‌گذاری/صدور نهایی انجام می‌شود
    if ($action === 'confirm_issue_policy') {
        $caseId = intval($data['case_id'] ?? 0);
        $policyNumber = trim($data['policy_number'] ?? '');
        $vin = trim($data['vin'] ?? '');
        $chassisNum = trim($data['chassis_num'] ?? '');
        $engineNum = trim($data['engine_num'] ?? '');
        $totalPremium = !empty($data['total_premium']) ? intval(preg_replace('/\D/', '', $data['total_premium'])) : null;
        $centralUniqueCode = trim($data['unique_code'] ?? '');
        $carSystem = trim($data['car_system'] ?? '');
        $carType = trim($data['car_type'] ?? '');
        $carModelYear = trim($data['model_year'] ?? '');
        $carColor = trim($data['car_color'] ?? '');
        $carUsage = trim($data['car_usage'] ?? '');
        $ocrPhone = trim($data['phone'] ?? '');
        // تاریخ صدورِ روی بیمه‌نامه شمسی است؛ رقمش را لاتین ذخیره می‌کنیم تا همه‌جا یکدست باشد (نمایش با رقم فارسی است)
        $policyIssueDate = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
                                       ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
                                       trim($data['issue_date'] ?? ''));
        $carValue = !empty($data['car_value']) ? intval(preg_replace('/\D/', '', $data['car_value'])) : null;

        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        if (!$case['pending_policy_temp_path'] || !file_exists($case['pending_policy_temp_path'])) {
            echo json_encode(['ok' => false, 'error' => 'ابتدا باید فایل بیمه‌نامه را بارگذاری و شناسایی کنید.']); exit;
        }

        $siteRoot = dirname(__DIR__);
        $tempPath = $case['pending_policy_temp_path'];
        $ocrData = $case['ocr_extracted_data'] ? json_decode($case['ocr_extracted_data'], true) : null;
        $nameMismatch = false;

        // بررسی مغایرت‌ها بر مبنای مقادیرِ خودِ OCR (نه ورودی احتمالاً ویرایش‌شده‌ی ادمین) انجام
        // می‌شود - چون هدف این چک، تشخیصِ «فایل اشتباه» است، نه صرفاً چک کردن تایپ نهایی
        if ($ocrData && ($ocrData['ins_type'] ?? '') !== 'ناشناخته' && $ocrData['ins_type'] !== 'معرفی‌نامه') {
            // بررسی ۱ (الزامی، اول از همه طبق ترتیب خواسته‌شده): پلاک
            if (!empty($ocrData['plate']) && !empty($case['plate']) && plate_core($ocrData['plate']) !== plate_core($case['plate'])) {
                echo json_encode(['ok' => false, 'error' => "⚠️ پلاک این پرونده «{$case['plate']}» است، ولی پلاک شناسایی‌شده از فایل «{$ocrData['plate']}» است. لطفاً فایل درست را بارگذاری کنید."]);
                exit;
            }
            // بررسی ۲ (الزامی): نوع بیمه
            $expectedTypeFa = insurance_type_fa($case['insurance_type']);
            if ($ocrData['ins_type'] !== $expectedTypeFa) {
                echo json_encode(['ok' => false, 'error' => "⚠️ این پرونده «{$expectedTypeFa}» است، ولی فایلی که بارگذاری کردید «{$ocrData['ins_type']}» تشخیص داده شد. لطفاً فایل درست را بارگذاری کنید."]);
                exit;
            }
            // بررسی ۳ (فقط هشدار): کد ملی
            if (!empty($ocrData['national_id']) && !empty($case['insured_national_id']) && $ocrData['national_id'] !== $case['insured_national_id']) {
                $nameMismatch = true;
            }
        }

        // طبق توافق: پلاک بدون خط‌تیره‌ی داخلی، و شماره‌ی بیمه‌نامه با «∕» به‌جای «/» در نام‌گذاری استفاده می‌شود
        $issuedTs = time();
        $plateForName = plate_for_filename($case['plate']);
        $policyNumForName = policy_number_for_filename($policyNumber);
        $sadereBase = build_sadere_base_path($siteRoot, $issuedTs, $case['insurance_type']);
        $caseFolderName = build_case_folder_name_issued($plateForName, $case['insured_name'], $policyNumForName, $vin);

        // ------------------------------------------------------------------
        //  مرحله ۱: پوشه‌ی همین درخواست (که داخل پوشه‌ی معرفی‌نامه است) ابتدا به نامِ نهاییِ
        //  بیمه‌نامه‌ی صادره تغییر نام می‌دهد - یعنی «پلاک - نام - شماره بیمه‌نامه - VIN».
        //  سپس همان پوشه (نه کل پوشه‌ی معرفی‌نامه) به «بایگانی صادره» کپی می‌شود.
        // ------------------------------------------------------------------
        $caseFolder = resolve_case_folder($pdo, $siteRoot, $case);
        if ($caseFolder && is_dir($caseFolder)) {
            $renamedCaseFolder = dirname($caseFolder) . '/' . $caseFolderName;
            if ($caseFolder !== $renamedCaseFolder) {
                if (@rename($caseFolder, $renamedCaseFolder)) {
                    // مسیرهای ذخیره‌شده‌ی مدارک هم باید با نام جدیدِ پوشه هماهنگ شوند
                    $oldRel = ltrim(str_replace($siteRoot, '', $caseFolder), '/');
                    $newRel = ltrim(str_replace($siteRoot, '', $renamedCaseFolder), '/');
                    $pdo->prepare("UPDATE case_documents SET file_path = REPLACE(file_path, ?, ?) WHERE case_id = ?")
                        ->execute([$oldRel, $newRel, $caseId]);
                    $pdo->prepare("UPDATE health_inspections SET photos_folder_path = REPLACE(photos_folder_path, ?, ?), photos = REPLACE(photos, ?, ?) WHERE case_id = ?")
                        ->execute([$caseFolder, $renamedCaseFolder, $oldRel, $newRel, $caseId]);
                    $caseFolder = $renamedCaseFolder;
                }
            }
            $pdo->prepare("UPDATE policy_cases SET folder_path = ? WHERE id = ?")->execute([$caseFolder, $caseId]);
        }

        // مرحله ۲: فایل بیمه‌نامه‌ی نهایی ابتدا داخل خودِ پوشه‌ی درخواست ذخیره می‌شود
        $ext = pathinfo($case['pending_policy_orig_name'] ?: 'file.pdf', PATHINFO_EXTENSION) ?: 'pdf';
        $finalName = build_final_policy_filename($plateForName, $case['insured_name'], $policyNumForName, $vin, $ext);
        if (!$caseFolder || !is_dir($caseFolder)) {
            echo json_encode(['ok' => false, 'error' => 'پوشه‌ی این درخواست پیدا نشد.']); exit;
        }
        $finalDest = unique_dest_path($caseFolder . '/' . $finalName);
        if (!@rename($tempPath, $finalDest)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل بیمه‌نامه.']); exit;
        }
        $relPath = ltrim(str_replace($siteRoot, '', $finalDest), '/');

        // اطلاعات صدور هم باید در فایل متنی ثبت شود؛ ابتدا فیلدهای صدور را ذخیره می‌کنیم تا
        // نسخه‌ی نوشته‌شده کامل باشد، سپس فایل را بازنویسی می‌کنیم
        $pdo->prepare("UPDATE policy_cases SET policy_number = ?, vin = ?, chassis_num = ?, engine_num = ?, total_premium = ?, central_unique_code = ?, car_system = ?, car_type = ?, car_model_year = ?, car_color = ?, car_usage = ?, policy_issue_date = ?, car_value = ?, status = 'ISSUED' WHERE id = ?")
            ->execute([$policyNumber ?: null, $vin ?: null, $chassisNum ?: null, $engineNum ?: null, $totalPremium, $centralUniqueCode ?: null,
                       $carSystem ?: null, $carType ?: null, $carModelYear ?: null, $carColor ?: null, $carUsage ?: null, $policyIssueDate ?: null, $carValue, $caseId]);
        write_case_info_file($pdo, $caseFolder, $caseId);

        // مرحله ۳: کل پوشه‌ی همین درخواست (شامل مدارک، عکس‌های بازدید و فایل بیمه‌نامه)
        // به «بایگانی صادره» کپی می‌شود - نه کل پوشه‌ی معرفی‌نامه
        $sadereFolder = $sadereBase . '/' . $caseFolderName;
        if (!is_dir($sadereFolder) && !@mkdir($sadereFolder, 0777, true) && !is_dir($sadereFolder)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در آماده‌سازی پوشه‌ی بایگانی صادره.']); exit;
        }
        copy_dir_recursive($caseFolder, $sadereFolder);

        $pdo->prepare("UPDATE policy_cases SET status = 'ISSUED', issued_file_path = ?, policy_number = ?, vin = ?, chassis_num = ?, engine_num = ?, total_premium = ?, central_unique_code = ?, car_system = ?, car_type = ?, car_model_year = ?, car_color = ?, car_usage = ?, ocr_phone = ?, policy_issue_date = ?, car_value = ?, issued_at = NOW(), pending_policy_temp_path = NULL, pending_policy_orig_name = NULL WHERE id = ?")
            ->execute([$relPath, $policyNumber ?: null, $vin ?: null, $chassisNum ?: null, $engineNum ?: null, $totalPremium, $centralUniqueCode ?: null,
                       $carSystem ?: null, $carType ?: null, $carModelYear ?: null, $carColor ?: null, $carUsage ?: null, $ocrPhone ?: null, $policyIssueDate ?: null, $carValue, $caseId]);

        // تولید خودکار اقساط بلافاصله پس از صدور (بدهی ما به پاسارگاد از همین لحظه شروع می‌شود).
        // اگر پرداخت مستقیم نقدی باشد، اقساطی ساخته نمی‌شود.
        if (empty($case['is_direct_payment'])) {
            try {
                require_once __DIR__ . '/finance_core.php';
                fin_generate_installments($pdo, $caseId);
            } catch (Exception $e) { /* نبودِ ماژول مالی نباید جلوی صدور را بگیرد */ }
        }

        // پیام وضعیت معرفی‌نامه در گروه، و نام پوشه‌ی «بایگانی کسر از حقوق» (شامل شمارنده‌ی
        // تعداد صادره در انتهای نامش) باید بلافاصله با آخرین وضعیت هماهنگ شوند
        sync_intro_group_message($pdo, $case['introduction_id']);
        $newIntroFolder = sync_intro_folder($pdo, $siteRoot, $case['introduction_id']);

        // نام پوشه‌ی معرفی‌نامه شاملِ شمارنده‌ی صادره است و همین حالا عوض شد؛ پس مسیرهای
        // ذخیره‌شده‌ی این پرونده باید با مسیر جدید هماهنگ شوند
        if ($newIntroFolder) {
            $updatedCaseFolder = $newIntroFolder . '/' . $caseFolderName;
            if ($updatedCaseFolder !== $caseFolder) {
                $oldRel2 = ltrim(str_replace($siteRoot, '', $caseFolder), '/');
                $newRel2 = ltrim(str_replace($siteRoot, '', $updatedCaseFolder), '/');
                $relPath = str_replace($oldRel2, $newRel2, $relPath);
                $pdo->prepare("UPDATE policy_cases SET folder_path = ?, issued_file_path = ? WHERE id = ?")
                    ->execute([$updatedCaseFolder, $relPath, $caseId]);
                $pdo->prepare("UPDATE case_documents SET file_path = REPLACE(file_path, ?, ?) WHERE case_id = ?")
                    ->execute([$oldRel2, $newRel2, $caseId]);
                $pdo->prepare("UPDATE health_inspections SET photos_folder_path = REPLACE(photos_folder_path, ?, ?), photos = REPLACE(photos, ?, ?) WHERE case_id = ?")
                    ->execute([$caseFolder, $updatedCaseFolder, $oldRel2, $newRel2, $caseId]);
            }
        }

        $stmt = $pdo->prepare("SELECT pc.person_id, p.bale_chat_id FROM policy_cases pc JOIN persons p ON pc.person_id = p.id WHERE pc.id = ?");
        $stmt->execute([$caseId]);
        $row = $stmt->fetch();
        if ($row) {
            // طبق درخواست: به‌جای شناسه‌ی داخلی پرونده، پلاک و نوع بیمه در پیام ذکر می‌شود
            $plateDisplay = $case['plate'] ?: 'بدون پلاک';
            notify_customer_app($pdo, $row['person_id'], $row['bale_chat_id'], 'بیمه‌نامه صادر شد',
                "🎉 بیمه‌نامه‌ی " . insurance_type_fa($case['insurance_type']) . " پلاک {$plateDisplay} شما با موفقیت صادر شد" . ($policyNumber ? " (شماره: {$policyNumber})" : '') . ".", 'success', $caseId);
        }

        echo json_encode(['ok' => true, 'file_path' => $relPath, 'name_mismatch' => $nameMismatch]);
        exit;
    }

} catch (Exception $e) {
    error_log('[case_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس.']);
}
