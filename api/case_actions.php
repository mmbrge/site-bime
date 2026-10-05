<?php
// فایل: api/case_actions.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/finance_core.php';     // سررسیدِ اقساط و ستون‌های فیشِ اقساط
require_once __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_company_issue.php';   // خواندنِ فایلِ بیمه‌نامه با الگوریتمِ جای متن + جدا کردنِ فیش‌ها

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

// «همکار شرکت‌ها» (COMPANY_LIAISON) فقط به شرکت‌ها، بایگانی و مالی دسترسی دارد؛
// منوی این بخش برایش پنهان است و اینجا هم مستقیم جلویش گرفته می‌شود.
if (($_SESSION['role'] ?? '') === 'COMPANY_LIAISON') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
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

try {
    // ---- حذف یک درخواست (پرونده) - فقط مدیر کل؛ صادرشده‌ها حذف نمی‌شوند ----
    if ($action === 'delete_case') {
        if (($_SESSION['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل می‌تواند درخواست را حذف کند.'], JSON_UNESCAPED_UNICODE); exit; }
        $res = delete_policy_case($pdo, dirname(__DIR__), intval($data['case_id'] ?? 0), $_SESSION['user_id']);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- بارگذاریِ مستقیمِ مدرک و تکمیل/ویرایشِ دستیِ اطلاعاتِ درخواست فقط برای مدیر کل ----
    // (فیلدهای نام‌گذاریِ هنگامِ صدور - پلاک و نام بیمه‌گذار - برای کارشناسِ صدور آزاد می‌ماند)
    if (($_SESSION['role'] ?? '') !== 'ADMIN'
        && (($_POST['action'] ?? '') === 'admin_upload_case_doc'
            || ($action === 'set_naming' && (($data['insured_national_id'] ?? '') !== '' || ($data['ownership_choice'] ?? '') !== '' || ($data['prev_body_insurance'] ?? '') !== '')))) {
        echo json_encode(['ok' => false, 'error' => 'ثبت و ویرایش درخواست فقط برای مدیر کل مجاز است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- درخواستِ «خارج از فاز عملیاتی» فقط قابلِ دیدن است؛ هیچ کارِ عملیاتی روی آن انجام نمی‌شود ----
    $mutating = ['approve_all_docs', 'approve_doc', 'reject_doc', 'request_fix', 'review_health_photo', 'approve_health',
                 'reject_health', 'set_naming', 'confirm_issue_policy', 'admin_upload_case_doc', 'ocr_preview_policy', 'upload_health_report'];
    $postAction = $_POST['action'] ?? '';
    $guardAction = in_array($action, $mutating, true) ? $action : (in_array($postAction, $mutating, true) ? $postAction : '');
    if ($guardAction !== '') {
        $gCase = 0;
        if (!empty($data['case_id']) || !empty($_POST['case_id'])) {
            $gCase = intval($data['case_id'] ?? $_POST['case_id']);
        } elseif (!empty($data['doc_id'])) {
            $st = $pdo->prepare("SELECT case_id FROM case_documents WHERE id = ?");
            $st->execute([intval($data['doc_id'])]);
            $gCase = intval($st->fetchColumn());
        } elseif ((!empty($data['id']) || !empty($_POST['inspection_id'])) && in_array($guardAction, ['review_health_photo', 'approve_health', 'reject_health', 'upload_health_report'], true)) {
            $st = $pdo->prepare("SELECT case_id FROM health_inspections WHERE id = ?");
            $st->execute([intval($data['id'] ?? $_POST['inspection_id'])]);
            $gCase = intval($st->fetchColumn());
        }
        if ($gCase) {
            $st = $pdo->prepare("SELECT status FROM policy_cases WHERE id = ?");
            $st->execute([$gCase]);
            if ($st->fetchColumn() === 'WITHDRAWN') {
                echo json_encode(['ok' => false, 'error' => 'این درخواست از فاز عملیاتی خارج شده و فقط برای سابقه نگه داشته می‌شود.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

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
        $sql .= " ORDER BY (pc.status = 'WITHDRAWN') ASC, (pc.status = 'DOCS_REVIEW') DESC, pc.created_at DESC";
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
                   p.personnel_code AS holder_personnel_code, p.mobile_number AS holder_mobile, c.name AS company_name,
                   i.letter_date, i.max_quota, i.used_quota, i.file_path AS intro_file_path
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
        // اطلاعاتِ تکمیلیِ شرکتِ کارفرما (هر ستونی که در این نصب موجود باشد)
        $company = null;
        try {
            $st = $pdo->prepare("SELECT co.* FROM companies co JOIN persons p ON p.company_id = co.id WHERE p.id = ?");
            $st->execute([$case['person_id']]);
            if ($co = $st->fetch()) $company = array_intersect_key($co, array_flip(['name', 'phone', 'economic_code', 'national_id', 'address', 'postal_code']));
        } catch (Throwable $e) {}
        $case['letter_date_fa'] = $case['letter_date'] ? str_replace('-', '.', $case['letter_date']) : null;
        // آخرین بازدیدِ سلامت (برای بدنه) با عکس‌ها و گزارش - برای دیدن در همان پنجره‌ی صدور
        $health = null;
        if ($case['insurance_type'] === 'BODY') {
            $st = $pdo->prepare("SELECT * FROM health_inspections WHERE case_id = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$caseId]);
            if ($hi = $st->fetch()) {
                $steps = health_inspection_steps();
                $ph = json_decode($hi['photos'] ?: '{}', true) ?: [];
                $list = [];
                foreach (health_photo_reviews($hi) as $k => $rv) {
                    $parts = explode('-', (string)$k);
                    $list[] = ['label' => ($steps[$parts[0]]['label'] ?? $k) . (isset($parts[1]) ? ' ' . $parts[1] : ''), 'status' => $rv['status'], 'note' => $rv['note'],
                               'path' => ltrim(str_replace(dirname(__DIR__), '', health_abs_path(dirname(__DIR__), $ph[$k])), '/')];
                }
                $health = ['id' => intval($hi['id']), 'number' => intval($hi['inspection_number']), 'status' => $hi['status'],
                           'approved_jalali' => $hi['approved_jalali_date'], 'report' => $hi['report_file_path'], 'photos' => $list];
            }
        }
        echo json_encode(['ok' => true, 'case' => $case, 'documents' => $docs, 'coverage_labels' => $coverageLabels, 'required_docs' => $requiredDocs,
                          'company' => $company, 'health' => $health], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۳. تایید یک مدرک
    if ($action === 'approve_all_docs') {
        $caseId = intval($data['case_id'] ?? 0);
        $st = $pdo->prepare("SELECT id FROM case_documents WHERE case_id = ? AND status = 'PENDING'");
        $st->execute([$caseId]);
        $stage = null;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $docId) $stage = approve_case_document_and_maybe_finalize($pdo, intval($docId), $_SESSION['user_id']);
        echo json_encode(['ok' => true, 'stage' => $stage]);
        exit;
    }

    if ($action === 'approve_doc') {
        // اگر همه‌ی مدارکِ لازم تایید شده باشند، مدارک بایگانی می‌شوند و (اگر بازدید هم آماده است) پرونده «در حال صدور» می‌شود
        $stage = approve_case_document_and_maybe_finalize($pdo, intval($data['doc_id'] ?? 0), $_SESSION['user_id']);
        echo json_encode(['ok' => true, 'stage' => $stage]);
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
        $st = $pdo->prepare("SELECT doc_key FROM case_documents WHERE id = ?");
        $st->execute([$docId]);
        review_log($pdo, 'CASE_DOC', 'REJECTED', ['case_id' => $doc['case_id'], 'key' => $st->fetchColumn(), 'label' => $doc['doc_label'], 'note' => $reason, 'user_id' => $_SESSION['user_id']]);

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
        // LEFT JOIN: بازدیدِ «آزاد» (بدون پرونده) هم باید دانلود شود - قبلاً 404 می‌داد
        $stmt = $pdo->prepare("SELECT hi.*, COALESCE(pc.plate, hi.free_plate) AS plate FROM health_inspections hi LEFT JOIN policy_cases pc ON hi.case_id = pc.id WHERE hi.id = ?");
        $stmt->execute([$inspectionId]);
        $insp = $stmt->fetch();
        if (!$insp) { http_response_code(404); exit; }

        $photos = json_decode($insp['photos'] ?: '{}', true);
        $files = [];
        foreach ($photos as $stepKey => $relPath) {
            $abs = health_abs_path(dirname(__DIR__), $relPath);
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
        // وقتی همه‌ی عکس‌ها تایید شده‌اند، همین بارگذاری = تاییدِ نهاییِ بازدید (و ورود به صدور)
        echo json_encode(health_attach_report_and_finalize($pdo, dirname(__DIR__), intval($_POST['inspection_id'] ?? 0), $_FILES['file'], $_SESSION['user_id']), JSON_UNESCAPED_UNICODE);
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
            WHERE pc.id IS NULL OR COALESCE(pc.status, '') <> 'WITHDRAWN'
            ORDER BY (hi.status = 'PENDING') DESC, hi.created_at DESC
        ");
        $rows = $stmt->fetchAll();
        // شمارِ عکس‌های تاییدشده / ردشده / در انتظارِ هر بازدید (برای کارتِ فهرست)
        foreach ($rows as &$r) {
            $c = ['APPROVED' => 0, 'REJECTED' => 0, 'PENDING' => 0];
            foreach (health_photo_reviews($r) as $rv) $c[$rv['status']]++;
            $r['review_counts'] = $c;
        }
        unset($r);
        echo json_encode(['ok' => true, 'data' => $rows, 'per_photo' => health_review_columns_ready($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- صفحه‌ی «بازدیدهای تاییدشده»: هر درخواست یک ردیف ----
    //   کارکنان: پرونده‌هایی که مدارکشان کامل تایید شده یا بازدیدِ سلامتشان تاییدِ نهایی شده
    //   شرکت‌ها / آزاد: بازدیدهای سلامتِ تاییدشده‌ای که به پرونده‌ی کارکنان وصل نیستند؛ اگر پلاک
    //   در درخواست‌های شرکت‌ها باشد «شرکت‌ها» حساب می‌شود، وگرنه «آزاد»
    if ($action === 'approved_reviews') {
        $rows = [];
        $stmt = $pdo->query("SELECT pc.*, p.full_name AS holder_name, p.national_code AS holder_nid, p.personnel_code, co.name AS employer_name,
                                    (SELECT MAX(cd.reviewed_at) FROM case_documents cd WHERE cd.case_id = pc.id AND cd.status = 'APPROVED') AS docs_approved_at,
                                    (SELECT COUNT(*) FROM case_documents cd WHERE cd.case_id = pc.id AND cd.status = 'APPROVED') AS docs_approved,
                                    (SELECT COUNT(*) FROM case_documents cd WHERE cd.case_id = pc.id AND cd.status = 'PENDING') AS docs_pending
                               FROM policy_cases pc JOIN persons p ON p.id = pc.person_id LEFT JOIN companies co ON co.id = p.company_id
                              WHERE COALESCE(pc.status, '') <> 'WITHDRAWN'
                              ORDER BY pc.id DESC LIMIT 3000");
        $hiSt = $pdo->prepare("SELECT id, status, reviewed_at, approved_jalali_date, report_file_path FROM health_inspections WHERE case_id = ? ORDER BY id DESC LIMIT 1");
        $rejSt = review_log_ready($pdo) ? $pdo->prepare("SELECT COUNT(*) FROM review_log WHERE case_id = ? AND decision = 'REJECTED'") : null;
        foreach ($stmt->fetchAll() as $c) {
            $docsOk = case_docs_all_approved($pdo, $c);
            $hiSt->execute([$c['id']]);
            $hi = $hiSt->fetch() ?: null;
            $healthOk = $c['insurance_type'] !== 'BODY' || ($hi && $hi['status'] === 'APPROVED');
            if (!$docsOk && !($hi && $hi['status'] === 'APPROVED')) continue;
            $required = get_required_docs_v2($c['insurance_type'], $c['ownership_choice'], $c['prev_body_insurance'], $c['insured_relationship']);
            $lastAt = max((string)$c['docs_approved_at'], (string)($hi['status'] ?? '') === 'APPROVED' ? (string)$hi['reviewed_at'] : '');
            $rejCount = 0;
            if ($rejSt) { $rejSt->execute([$c['id']]); $rejCount = intval($rejSt->fetchColumn()); }
            else {
                $r = $pdo->prepare("SELECT COUNT(*) FROM case_documents WHERE case_id = ? AND reject_reason IS NOT NULL AND reject_reason <> ''");
                $r->execute([$c['id']]); $rejCount = intval($r->fetchColumn());
            }
            $rows[] = ['source' => 'PERSONNEL', 'source_fa' => 'کارکنان', 'id' => intval($c['id']), 'unique_code' => $c['unique_code'],
                       'holder_name' => $c['holder_name'], 'insured_name' => $c['insured_name'] ?: $c['holder_name'],
                       'national_id' => $c['insured_national_id'] ?: $c['holder_nid'], 'personnel_code' => $c['personnel_code'],
                       'company_name' => $c['employer_name'], 'insurance_type' => $c['insurance_type'], 'plate' => $c['plate'],
                       'case_status' => $c['status'], 'case_status_fa' => case_status_fa($c['status']),
                       'docs_ok' => $docsOk, 'docs_approved' => intval($c['docs_approved']), 'docs_required' => count($required),
                       'health_status' => $c['insurance_type'] === 'BODY' ? ($hi['status'] ?? 'NONE') : 'NA',
                       'complete' => $docsOk && $healthOk, 'rejections' => $rejCount,
                       'approved_at' => $lastAt ?: null, 'approved_at_jalali' => $lastAt ? jalali_from_gregorian_ts_dotted(strtotime($lastAt)) : null,
                       'created_at_jalali' => $c['created_at'] ? jalali_from_gregorian_ts_dotted(strtotime($c['created_at'])) : null];
        }

        // پلاک‌های درخواست‌های شرکت‌ها (برای تشخیصِ بازدیدهای آزادِ مربوط به شرکت‌ها)
        $companyPlates = [];
        try {
            foreach ($pdo->query("SELECT crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, c.name FROM company_request_plates crp
                                    JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                                   WHERE crp.plate_p1 IS NOT NULL AND crp.plate_p1 <> ''")->fetchAll() as $cp) {
                $companyPlates["{$cp['plate_p1']}ایران - {$cp['plate_p2']} {$cp['plate_letter']} {$cp['plate_p4']}"] = $cp['name'];
            }
        } catch (Throwable $e) { /* ماژول شرکت‌ها نصب نیست */ }
        $stmt = $pdo->query("SELECT hi.*, p.full_name, p.national_code FROM health_inspections hi LEFT JOIN persons p ON p.id = hi.person_id
                              WHERE hi.case_id IS NULL AND hi.status = 'APPROVED' ORDER BY hi.id DESC LIMIT 2000");
        foreach ($stmt->fetchAll() as $h) {
            $co = $companyPlates[$h['free_plate'] ?? ''] ?? null;
            $rej = 0;
            if ($rejSt) { $r = $pdo->prepare("SELECT COUNT(*) FROM review_log WHERE inspection_id = ? AND decision = 'REJECTED'"); $r->execute([$h['id']]); $rej = intval($r->fetchColumn()); }
            $rows[] = ['source' => $co ? 'COMPANY' : 'FREE', 'source_fa' => $co ? 'شرکت‌ها' : 'بازدید آزاد', 'id' => intval($h['id']),
                       'unique_code' => 'بازدید-' . $h['id'], 'holder_name' => $h['full_name'], 'insured_name' => $h['full_name'],
                       'national_id' => strpos((string)$h['national_code'], 'GUEST') === 0 ? null : $h['national_code'], 'personnel_code' => null,
                       'company_name' => $co, 'insurance_type' => null, 'plate' => $h['free_plate'],
                       'case_status' => null, 'case_status_fa' => 'بازدید تاییدشده', 'docs_ok' => null, 'docs_approved' => 0, 'docs_required' => 0,
                       'health_status' => 'APPROVED', 'complete' => true, 'rejections' => $rej,
                       'approved_at' => $h['reviewed_at'], 'approved_at_jalali' => $h['approved_jalali_date'] ?: ($h['reviewed_at'] ? jalali_from_gregorian_ts_dotted(strtotime($h['reviewed_at'])) : null),
                       'created_at_jalali' => $h['created_at'] ? jalali_from_gregorian_ts_dotted(strtotime($h['created_at'])) : null];
        }
        usort($rows, fn($a, $b) => strcmp((string)$b['approved_at'], (string)$a['approved_at']));
        echo json_encode(['ok' => true, 'rows' => $rows, 'history' => review_log_ready($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- جزئیاتِ یک ردیفِ «بازدیدهای تاییدشده»: همه‌ی اطلاعات، مدارک، عکس‌ها و تاریخچه‌ی رد/تایید ----
    if ($action === 'approved_review_detail') {
        $src = $_GET['source'] ?? 'PERSONNEL';
        $id = intval($_GET['id'] ?? 0);
        $siteRoot = dirname(__DIR__);
        $steps = health_inspection_steps();
        $users = [];
        foreach ($pdo->query("SELECT id, full_name FROM users")->fetchAll() as $u) $users[$u['id']] = $u['full_name'];
        $fmt = fn($ts) => $ts ? jalali_from_gregorian_ts_dotted(strtotime($ts)) . ' ' . date('H:i', strtotime($ts)) : null;
        $inspPack = function ($hi) use ($steps, $siteRoot, $fmt, $users) {
            $photos = json_decode($hi['photos'] ?: '{}', true) ?: [];
            $list = [];
            foreach (health_photo_reviews($hi) as $k => $rv) {
                $parts = explode('-', (string)$k);
                $list[] = ['key' => (string)$k, 'label' => ($steps[$parts[0]]['label'] ?? $k) . (isset($parts[1]) ? ' ' . $parts[1] : ''),
                           'path' => ltrim(str_replace($siteRoot, '', health_abs_path($siteRoot, $photos[$k])), '/'),
                           'status' => $rv['status'], 'note' => $rv['note'],
                           'at' => $fmt(json_decode($hi['photo_reviews'] ?? '{}', true)[$k]['at'] ?? null),
                           'by' => $users[json_decode($hi['photo_reviews'] ?? '{}', true)[$k]['by'] ?? 0] ?? null];
            }
            return ['id' => intval($hi['id']), 'number' => intval($hi['inspection_number']), 'status' => $hi['status'],
                    'approved_jalali' => $hi['approved_jalali_date'], 'reviewed_at' => $fmt($hi['reviewed_at']), 'created_at' => $fmt($hi['created_at']),
                    'report' => $hi['report_file_path'], 'reject_reason' => $hi['reject_reason'], 'photos' => $list];
        };
        $history = [];
        $logRows = function ($where, $arg) use ($pdo, $fmt, $users, &$history) {
            if (!review_log_ready($pdo)) return;
            $st = $pdo->prepare("SELECT * FROM review_log WHERE $where ORDER BY id ASC");
            $st->execute([$arg]);
            foreach ($st->fetchAll() as $r) {
                $history[] = ['at' => $fmt($r['created_at']), 'entity' => $r['entity'], 'label' => $r['item_label'], 'decision' => $r['decision'],
                              'note' => $r['note'], 'by' => $r['user_id'] ? ($users[$r['user_id']] ?? 'کارشناس') : 'کاربر'];
            }
        };

        if ($src === 'PERSONNEL') {
            $st = $pdo->prepare("SELECT pc.*, p.full_name AS holder_name, p.national_code AS holder_nid, p.personnel_code, p.mobile_number AS holder_mobile,
                                        co.name AS employer_name, i.letter_date, i.max_quota, i.used_quota, i.file_path AS intro_file
                                   FROM policy_cases pc JOIN persons p ON p.id = pc.person_id LEFT JOIN companies co ON co.id = p.company_id
                                   LEFT JOIN introductions i ON i.id = pc.introduction_id WHERE pc.id = ?");
            $st->execute([$id]);
            $case = $st->fetch();
            if (!$case) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }
            $st = $pdo->prepare("SELECT * FROM case_documents WHERE case_id = ? ORDER BY id ASC");
            $st->execute([$id]);
            $docs = array_map(fn($d) => ['label' => $d['doc_label'], 'key' => $d['doc_key'], 'status' => $d['status'], 'path' => $d['file_path'],
                                          'reject_reason' => $d['reject_reason'], 'uploaded_at' => $fmt($d['uploaded_at']), 'reviewed_at' => $fmt($d['reviewed_at'])], $st->fetchAll());
            $st = $pdo->prepare("SELECT * FROM health_inspections WHERE case_id = ? ORDER BY id ASC");
            $st->execute([$id]);
            $insps = array_map($inspPack, $st->fetchAll());
            $logRows('case_id = ?', $id);
            // بدونِ جدولِ تاریخچه: دست‌کم علتِ ردهای فعلی
            if (!review_log_ready($pdo)) {
                foreach ($docs as $d) if ($d['reject_reason']) $history[] = ['at' => $d['reviewed_at'], 'entity' => 'CASE_DOC', 'label' => $d['label'], 'decision' => 'REJECTED', 'note' => $d['reject_reason'], 'by' => null];
            }
            $cov = json_decode($case['selected_coverages'] ?? '', true);
            $opts = body_coverage_options(); $covFa = [];
            foreach ((is_array($cov) ? $cov : []) as $k => $v) $covFa[] = ($opts[$k]['label'] ?? $k) . (($opts[$k]['tiers'][(string)$v] ?? null) ? ' (' . $opts[$k]['tiers'][(string)$v] . ')' : '');
            $case['coverages_fa'] = $covFa;
            $case['status_fa'] = case_status_fa($case['status']);
            $case['letter_date_fa'] = $case['letter_date'] ? str_replace('-', '.', $case['letter_date']) : null;
            $case['created_at_fa'] = $fmt($case['created_at']);
            echo json_encode(['ok' => true, 'source' => $src, 'case' => $case, 'docs' => $docs, 'inspections' => $insps, 'history' => $history], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $st = $pdo->prepare("SELECT hi.*, p.full_name, p.national_code, p.mobile_number FROM health_inspections hi LEFT JOIN persons p ON p.id = hi.person_id WHERE hi.id = ?");
        $st->execute([$id]);
        $hi = $st->fetch();
        if (!$hi) { echo json_encode(['ok' => false, 'error' => 'بازدید یافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }
        $logRows('inspection_id = ?', $id);
        echo json_encode(['ok' => true, 'source' => $src, 'person' => ['name' => $hi['full_name'], 'national_code' => $hi['national_code'], 'mobile' => $hi['mobile_number'], 'plate' => $hi['free_plate']],
                          'inspections' => [$inspPack($hi)], 'docs' => [], 'history' => $history], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- جزئیاتِ یک بازدید برای نمایشگرِ بزرگِ عکس‌ها (بررسیِ تک‌تک) ----
    if ($action === 'health_detail') {
        $id = intval($_GET['id'] ?? ($data['id'] ?? 0));
        $stmt = $pdo->prepare("SELECT hi.*, COALESCE(pc.plate, hi.free_plate) AS plate, COALESCE(pc.unique_code, CONCAT('آزاد-', hi.id)) AS unique_code,
                                      COALESCE(p1.full_name, p2.full_name) AS holder_name, pc.insurance_type
                                 FROM health_inspections hi
                                 LEFT JOIN policy_cases pc ON hi.case_id = pc.id
                                 LEFT JOIN persons p1 ON pc.person_id = p1.id
                                 LEFT JOIN persons p2 ON hi.person_id = p2.id
                                WHERE hi.id = ?");
        $stmt->execute([$id]);
        $insp = $stmt->fetch();
        if (!$insp) { echo json_encode(['ok' => false, 'error' => 'بازدید یافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }
        $steps = health_inspection_steps();
        $photos = json_decode($insp['photos'] ?: '{}', true) ?: [];
        $list = [];
        foreach (health_photo_reviews($insp) as $k => $rv) {
            $parts = explode('-', (string)$k);
            $label = ($steps[$parts[0]]['label'] ?? $k) . (isset($parts[1]) ? ' ' . $parts[1] : '');
            $list[] = ['key' => (string)$k, 'label' => $label, 'path' => ltrim(str_replace(dirname(__DIR__), '', health_abs_path(dirname(__DIR__), $photos[$k])), '/'),
                       'status' => $rv['status'], 'note' => $rv['note']];
        }
        // به ترتیبِ مراحل
        $order = array_flip(array_map('strval', array_keys($steps)));
        usort($list, function ($a, $b) use ($order) {
            [$sa, $ia] = array_pad(explode('-', $a['key']), 2, 0); [$sb, $ib] = array_pad(explode('-', $b['key']), 2, 0);
            return (($order[$sa] ?? 99) <=> ($order[$sb] ?? 99)) ?: (intval($ia) <=> intval($ib));
        });
        echo json_encode(['ok' => true, 'per_photo' => health_review_columns_ready($pdo),
                          'inspection' => ['id' => intval($insp['id']), 'number' => intval($insp['inspection_number']), 'status' => $insp['status'],
                                           'plate' => $insp['plate'], 'holder_name' => $insp['holder_name'], 'unique_code' => $insp['unique_code'],
                                           'insurance_type' => $insp['insurance_type'], 'reject_reason' => $insp['reject_reason'],
                                           'report_file_path' => $insp['report_file_path']],
                          'photos' => $list], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- تصمیمِ کارشناس برای یک عکس: تایید / رد / برگرداندن به «در انتظار» + توضیح ----
    if ($action === 'review_health_photo') {
        if (!health_review_columns_ready($pdo)) {
            echo json_encode(['ok' => false, 'error' => 'مایگریشن اجرا نشده است: لطفاً فایل migrations/013_health_photo_review.sql را در phpMyAdmin اجرا کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $res = health_review_photo($pdo, dirname(__DIR__), intval($data['id'] ?? 0), (string)($data['key'] ?? ''),
                                   (string)($data['decision'] ?? ''), (string)($data['note'] ?? ''), $_SESSION['user_id'] ?? null);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'approve_health') {
        $id = intval($data['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT hi.*, COALESCE(pc.plate, hi.free_plate) AS plate FROM health_inspections hi LEFT JOIN policy_cases pc ON hi.case_id = pc.id WHERE hi.id = ?");
        $stmt->execute([$id]);
        $insp = $stmt->fetch();
        if (!$insp) { echo json_encode(['ok' => false, 'error' => 'بازدید یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $finalSt = health_supports_photos_approved($pdo) ? 'PHOTOS_APPROVED' : 'APPROVED';
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
            $pdo->prepare("UPDATE health_inspections SET photos = ?, photos_folder_path = ?, approved_jalali_date = ?, status = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([json_encode($newPhotos, JSON_UNESCAPED_UNICODE), $newFolderPath, $approvedDate, $finalSt, $id]);
        } else {
            $pdo->prepare("UPDATE health_inspections SET approved_jalali_date = ?, status = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$approvedDate, $finalSt, $id]);
        }
        review_log($pdo, 'HEALTH', 'APPROVED', ['case_id' => $insp['case_id'], 'inspection_id' => $id, 'label' => 'همه‌ی عکس‌ها', 'user_id' => $_SESSION['user_id']]);
        // تاییدِ نهایی بعد از بارگذاریِ فایلِ گزارش است (upload_health_report)
        if ($finalSt === 'PHOTOS_APPROVED') { echo json_encode(['ok' => true, 'status' => $finalSt, 'needs_report' => true], JSON_UNESCAPED_UNICODE); exit; }
        if ($insp['case_id']) case_try_advance_to_issuing($pdo, $siteRoot, intval($insp['case_id']));

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
        $st = $pdo->prepare("SELECT case_id FROM health_inspections WHERE id = ?");
        $st->execute([$id]);
        review_log($pdo, 'HEALTH', 'REJECTED', ['case_id' => $st->fetchColumn() ?: null, 'inspection_id' => $id, 'label' => 'کل بازدید', 'note' => $reason, 'user_id' => $_SESSION['user_id']]);
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
        // «سایر مدارک»: نامی که کارشناس نوشته، نامِ مدرک در بایگانی هم می‌شود
        if ($docKey === 'other') {
            $custom = trim($_POST['other_name'] ?? '');
            if ($custom === '') { echo json_encode(['ok' => false, 'error' => 'نام مدرک را بنویسید.'], JSON_UNESCAPED_UNICODE); exit; }
            $docLabel = mb_substr($custom, 0, 120);
        }
        $stmt = $pdo->prepare("SELECT id FROM policy_cases WHERE id = ?");
        $stmt->execute([$caseId]);
        if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        echo json_encode(admin_store_case_doc($pdo, dirname(__DIR__), $caseId, $docKey, $docLabel, $_FILES['file'], $_SESSION['user_id']), JSON_UNESCAPED_UNICODE);
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

        $ocrData = null; $ocrDebug = null; $single = null;
        // ۱) همان الگوریتمِ صدورِ گروهی (جای متن در PDF): همه‌ی فیلدها + جدا کردنِ صفحه‌های فیشِ اقساط
        $lay = policy_layout_extract($pdo, $tempPath);
        if ($lay && $lay['data']) { $ocrData = $lay['data']; $single = $lay['single']; }
        if (!$ocrData && in_array(strtolower($ext), ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {   // اسکن یا عکس: موتورِ قبلی
            $ocrResult = run_document_ocr_verbose($tempPath);
            $ocrData = $ocrResult['data'];
            $ocrDebug = $ocrResult['debug'] ?: ($lay['debug'] ?? null);
        } elseif (!$ocrData) {
            $ocrDebug = 'فقط فایل PDF قابل شناسایی خودکار است (این فایل ' . strtoupper($ext) . ' بود).';
        }
        if ($ocrData) $ocrData = policy_data_aliases($ocrData);
        $store = $ocrData;
        if ($store && $single) $store['_single'] = $single;

        $pdo->prepare("UPDATE policy_cases SET pending_policy_temp_path = ?, pending_policy_orig_name = ?, ocr_extracted_data = ? WHERE id = ?")
            ->execute([$tempPath, $_FILES['file']['name'], $store ? json_encode($store, JSON_UNESCAPED_UNICODE) : null, $caseId]);

        echo json_encode(['ok' => true, 'ocr' => $ocrData, 'ocr_used' => (bool)$ocrData, 'ocr_debug' => $ocrDebug,
                          'source' => $single ? 'layout' : ($ocrData ? 'engine' : null), 'layout_debug' => $single ? null : ($lay['debug'] ?? null),
                          'receipts' => $single['receipts'] ?? [], 'missing' => $single['missing'] ?? []], JSON_UNESCAPED_UNICODE);
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
        $totalPremium = money_to_int($data['total_premium'] ?? '');
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
        $carValue = money_to_int($data['car_value'] ?? '') ?: null;

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
        // ماهِ «بایگانی صادره» از تاریخ صدورِ داخلِ بیمه‌نامه (نه روزِ ثبت در سایت)
        $issuedTs = policy_issue_ts($policyIssueDate, time());
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
        // درخواستی که هنوز هیچ مدرکی رویش بارگذاری نشده پوشه ندارد؛ همین حالا ساخته می‌شود
        if ($caseFolder && !is_dir($caseFolder)) @mkdir($caseFolder, 0777, true);
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

        // اگر فایل صفحه‌های فیش یا اعلامیه‌ی اقساط هم داشت، فقط صفحه‌های خودِ بیمه‌نامه به‌عنوانِ فایلِ بیمه‌نامه ذخیره می‌شود
        $single = (!empty($ocrData['_single']['dir']) && is_dir($ocrData['_single']['dir'])) ? $ocrData['_single'] : null;
        if ($single && (!empty($single['receipts']) || !empty($single['statement'])) && is_file($single['dir'] . '/' . $single['pol'])) {
            if (@copy($single['dir'] . '/' . $single['pol'], $tempPath . '.pol')) { @unlink($tempPath); @rename($tempPath . '.pol', $tempPath); }
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
        // همه‌ی فیلدهای فرمِ صدور (همان‌طور که کارشناس ویرایش کرده) در «اطلاعات صدور»ِ پرونده
        if (is_array($data['fields'] ?? null)) {
            try {
                company_ensure_issue_info_cols($pdo);
                $q = $pdo->prepare("SELECT issue_info FROM policy_cases WHERE id = ?"); $q->execute([$caseId]);
                $info = array_merge(issue_info_decode($q->fetchColumn()), policy_fields_to_issue_info(array_map(fn($v) => is_scalar($v) ? (string)$v : '', $data['fields'])));
                $pdo->prepare("UPDATE policy_cases SET issue_info = ? WHERE id = ?")->execute([$info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null, $caseId]);
            } catch (Throwable $e) { error_log('[confirm_issue_policy fields] ' . $e->getMessage()); }
        }
        // فیش‌های اقساط (صفحه‌های جداشده از فایل) کنارِ بیمه‌نامه در پوشه‌ی «فیش‌های اقساط»
        $storedRc = ['receipts' => [], 'statement' => null];
        if ($single) {
            fin_ensure_receipt_cols($pdo);
            $storedRc = policy_store_receipts($siteRoot, $caseFolder, $single['dir'], $single['receipts'] ?? [], $single['statement'] ?? null);
            cbundle_rrmdir($single['dir']);
        }

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

        // sync_intro_folder پوشه‌ی این بیمه‌نامه را در ماهِ درستش گذاشت (ماهِ معرفی‌نامه یا، اگر صدور در ماهِ
        // دیگری بود، پوشه‌ی معرفی‌نامه در ماهِ صدور) و همه‌ی مسیرهای ذخیره‌شده را هم اصلاح کرد
        $relBefore = $relPath;
        $cur = $pdo->prepare("SELECT issued_file_path FROM policy_cases WHERE id = ?");
        $cur->execute([$caseId]);
        $relPath = $cur->fetchColumn() ?: $relPath;
        if ($storedRc['receipts'] || $storedRc['statement']) {
            // اگر پوشه جابه‌جا شد، مسیرِ فیش‌ها هم با همان پوشه هماهنگ می‌شود
            $oldDir = dirname($relBefore); $newDir = dirname($relPath);
            $fix = fn($p) => ($p && $oldDir !== $newDir && strpos($p, $oldDir . '/') === 0) ? $newDir . substr($p, strlen($oldDir)) : $p;
            foreach ($storedRc['receipts'] as &$rc) $rc['file'] = $fix($rc['file']);
            unset($rc);
            try {
                $pdo->prepare("UPDATE policy_cases SET receipts_json = ?, statement_file = ? WHERE id = ?")
                    ->execute([$storedRc['receipts'] ? json_encode($storedRc['receipts'], JSON_UNESCAPED_UNICODE) : null, $fix($storedRc['statement']), $caseId]);
                // اقساط دوباره با فیش‌های همین بیمه‌نامه مغایرت‌گیری و ساخته می‌شوند (اولویت با فیش)
                if (empty($case['is_direct_payment']) && $storedRc['receipts']) {
                    require_once __DIR__ . '/finance_core.php';
                    fin_generate_installments($pdo, $caseId);
                }
            } catch (Throwable $e) { error_log('[case receipts] ' . $e->getMessage()); }
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
