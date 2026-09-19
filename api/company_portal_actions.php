<?php
// فایل: api/company_portal_actions.php
// اکشن‌های پنل ثبت‌کننده‌ی شرکت: لیست درخواست‌ها، ثبت درخواست جدید، آپلود مدرک تدریجی.
// یک کاربر می‌تواند به چند شرکت دسترسی داشته باشد؛ اگر فقط یکی دارد، همه‌جا قفل
// روی همان می‌ماند، وگرنه هر عملیات باید company_id معتبر (از میان شرکت‌های خودش) بدهد.
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';

$session = require_company_portal_session($pdo);
$allowedCompanyIds = $session['company_ids'];

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

// company_id درخواست‌شده را نسبت به فهرست شرکت‌های مجازِ همین کاربر معتبر می‌کند؛
// اگر کاربر فقط یک شرکت دارد، همیشه همان برگردانده می‌شود (نیازی به ارسالش از کلاینت نیست)
function resolve_company_id($allowedIds, $requested) {
    if (count($allowedIds) === 1) return $allowedIds[0];
    $requested = intval($requested);
    return in_array($requested, $allowedIds, true) ? $requested : null;
}

try {
    // ---- شرکت‌هایی که این کاربر به آن‌ها دسترسی دارد (برای پرکردن انتخابگر) ----
    if ($action === 'my_companies') {
        echo json_encode(['ok' => true, 'companies' => $session['companies']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- لیست درخواست‌های شرکت‌های این کاربر ----
    if ($action === 'my_requests') {
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT cr.*, c.name AS company_name FROM company_requests cr
                                JOIN companies c ON c.id = cr.company_id
                                WHERE cr.company_id IN ($placeholders) ORDER BY cr.created_at DESC");
        $stmt->execute($allowedCompanyIds);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['created_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($r['created_at']));
            $r['updated_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($r['updated_at']));
        }
        echo json_encode(['ok' => true, 'requests' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- جزئیات یک درخواست + مدارک و پلاک‌های آن (فقط اگر متعلق به یکی از شرکت‌های خودش باشد) ----
    if ($action === 'request_detail') {
        $requestId = intval($data['request_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM company_requests WHERE id = ? AND company_id IN ($placeholders)");
        $stmt->execute(array_merge([$requestId], $allowedCompanyIds));
        $request = $stmt->fetch();
        if (!$request) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }
        $request['created_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($request['created_at']));
        $request['updated_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($request['updated_at']));

        // file_path هم لازم است، وگرنه لینکِ «مدارک ارسالی» در پنل شرکت به
        // ../undefined می‌خورد و کاربر نمی‌تواند فایلی که خودش فرستاده را ببیند
        $stmt = $pdo->prepare("SELECT id, orig_name, file_path, file_kind, doc_type, status, uploaded_at FROM company_documents WHERE request_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$requestId]);
        $docs = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT id, plate_p1, plate_p2, plate_letter, plate_p4, insurance_type, status, expiry_date,
                                       issued_file_path IS NOT NULL AS has_issued_file
                                FROM company_request_plates WHERE request_id = ?");
        $stmt->execute([$requestId]);
        $plates = $stmt->fetchAll();
        foreach ($plates as &$p) {
            if ($p['expiry_date']) $p['expiry_date_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($p['expiry_date']));
        }

        echo json_encode(['ok' => true, 'request' => $request, 'documents' => $docs, 'plates' => $plates], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ثبت درخواست جدید (متن + نامه‌ی اختیاری) ----
    if ($action === 'submit_request') {
        $companyId = resolve_company_id($allowedCompanyIds, $data['company_id'] ?? null);
        if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت انتخاب‌شده معتبر نیست.']); exit; }

        $requestText = trim($data['request_text'] ?? '');
        $insurer = ($data['insurer'] ?? 'PASARGAD') === 'IRAN' ? 'IRAN' : 'PASARGAD';
        if ($requestText === '' && empty($_FILES['letter_file'])) {
            echo json_encode(['ok' => false, 'error' => 'متن درخواست یا فایل نامه را وارد کنید.']);
            exit;
        }

        // بیمه‌گر انتخاب‌شده باید طبق تنظیمات همین شرکت مجاز باشد
        $stmtAllowed = $pdo->prepare("SELECT allowed_insurers, name FROM companies WHERE id = ?");
        $stmtAllowed->execute([$companyId]);
        $companyRow = $stmtAllowed->fetch();
        $allowedInsurers = $companyRow['allowed_insurers'] ?? 'BOTH';
        if ($allowedInsurers !== 'BOTH' && $allowedInsurers !== $insurer) {
            echo json_encode(['ok' => false, 'error' => 'این شرکت فقط مجاز به درخواست بیمه ' . ($allowedInsurers === 'IRAN' ? 'ایران' : 'پاسارگاد') . ' است.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, status) VALUES (?, ?, ?, ?, 'NEW')");
        $stmt->execute([$companyId, $session['company_user_id'], $requestText, $insurer]);
        $requestId = $pdo->lastInsertId();

        // پلاک‌های اولیه‌ی این درخواست (اختیاری، چند تا مجاز؛ برای هر پلاک هم نوع بیمه‌ی
        // درخواستی‌اش - ثالث/بدنه - مشخص می‌شود؛ اگر «هردو» خواسته شده بود، سمتِ کلاینت
        // از قبل آن را به دو ردیفِ جدا تبدیل کرده است)
        $plates = json_decode($data['plates'] ?? '[]', true);
        if (is_array($plates)) {
            $insPlate = $pdo->prepare("INSERT INTO company_request_plates (request_id, plate_p1, plate_p2, plate_letter, plate_p4, insurance_type) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($plates as $p) {
                $pp1 = trim($p['p1'] ?? ''); $pp2 = trim($p['p2'] ?? '');
                $pletter = trim($p['letter'] ?? ''); $pp4 = trim($p['p4'] ?? '');
                if ($pp1 === '' && $pp2 === '' && $pletter === '' && $pp4 === '') continue;
                $pInsType = in_array($p['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $p['insurance_type'] : null;
                $insPlate->execute([$requestId, $pp1 ?: null, $pp2 ?: null, $pletter ?: null, $pp4 ?: null, $pInsType]);
            }
        }

        if (!empty($_FILES['letter_file'])) {
            $siteRoot = dirname(__DIR__);
            $companyName = $companyRow['name'] ?? 'نامشخص';
            $tmpDir = company_unassigned_temp_path($siteRoot, $companyName);
            $saved = company_store_uploaded_file($_FILES['letter_file'], $tmpDir);
            if ($saved['ok']) {
                $relPath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
                $pdo->prepare("UPDATE company_requests SET letter_file_path = ? WHERE id = ?")->execute([$relPath, $requestId]);
                $pdo->prepare("INSERT INTO company_documents (company_id, request_id, uploaded_by, file_path, orig_name, file_kind, status) VALUES (?, ?, ?, ?, ?, 'LETTER', 'UNASSIGNED')")
                    ->execute([$companyId, $requestId, $session['company_user_id'], $relPath, $saved['orig_name']]);
            }
        }

        echo json_encode(['ok' => true, 'request_id' => $requestId]);
        exit;
    }

    // ---- آپلود تدریجیِ مدرک به یک درخواست موجود (می‌تواند چند روز طول بکشد) ----
    // اگر ثبت‌کننده خودش می‌داند مدرک برای کدام پلاک/چه نوعی است می‌تواند به‌عنوان
    // «پیشنهاد» بفرستد؛ همچنان status=UNASSIGNED می‌ماند تا ما تایید کنیم (assign_document
    // در پنل ادمین با همین مقادیرِ پیشنهادی از قبل پر می‌شود، فقط تایید لازم است).
    if ($action === 'upload_document') {
        $requestId = intval($data['request_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT company_id FROM company_requests WHERE id = ? AND company_id IN ($placeholders)");
        $stmt->execute(array_merge([$requestId], $allowedCompanyIds));
        $companyId = $stmt->fetchColumn();
        if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $stmtName = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $stmtName->execute([$companyId]);
        $companyName = $stmtName->fetchColumn() ?: 'نامشخص';
        $tmpDir = company_unassigned_temp_path($siteRoot, $companyName);
        $saved = company_store_uploaded_file($_FILES['file'] ?? [], $tmpDir, 15728640, true);
        if (!$saved['ok']) { echo json_encode($saved); exit; }

        $relPath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        $suggestedType = trim($data['doc_type'] ?? '') ?: null;
        $p1 = trim($data['plate_p1'] ?? '') ?: null; $p2 = trim($data['plate_p2'] ?? '') ?: null;
        $letter = trim($data['plate_letter'] ?? '') ?: null; $p4 = trim($data['plate_p4'] ?? '') ?: null;

        // اگر درخواست بدون نامه ثبت شده بود، بعداً هم می‌شود نامه‌اش را همین‌جا اضافه کرد
        $isLetter = ($suggestedType === 'letter');
        $fileKind = $isLetter ? 'LETTER' : 'SUPPORTING_DOC';
        $docType = $isLetter ? null : $suggestedType;

        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, uploaded_by, file_path, orig_name, file_kind, doc_type, plate_p1, plate_p2, plate_letter, plate_p4, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'UNASSIGNED')")
            ->execute([$companyId, $requestId, $session['company_user_id'], $relPath, $saved['orig_name'], $fileKind, $docType, $p1, $p2, $letter, $p4]);

        if ($isLetter) {
            $pdo->prepare("UPDATE company_requests SET letter_file_path = ? WHERE id = ? AND letter_file_path IS NULL")->execute([$relPath, $requestId]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- چت با بیمه با ما (یک گفتگوی مشترک به ازای هر شرکت) ----
    if ($action === 'chat_get_messages') {
        $companyId = resolve_company_id($allowedCompanyIds, $data['company_id'] ?? null);
        if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت انتخاب‌شده معتبر نیست.']); exit; }
        $stmt = $pdo->prepare("SELECT * FROM company_chat_messages WHERE company_id = ? ORDER BY created_at ASC");
        $stmt->execute([$companyId]);
        $pdo->prepare("UPDATE company_chat_messages SET is_read = 1 WHERE company_id = ? AND sender_type = 'ADMIN' AND is_read = 0")->execute([$companyId]);
        echo json_encode(['ok' => true, 'messages' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'chat_send_message') {
        $companyId = resolve_company_id($allowedCompanyIds, $data['company_id'] ?? null);
        if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت انتخاب‌شده معتبر نیست.']); exit; }
        $message = trim($data['message'] ?? '');

        $filePath = null;
        if (!empty($_FILES['file'])) {
            $siteRoot = dirname(__DIR__);
            $stmtName = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
            $stmtName->execute([$companyId]);
            $companyName = $stmtName->fetchColumn() ?: 'نامشخص';
            $destDir = temp_archive_root($siteRoot) . '/چت شرکت‌ها/' . sanitize_folder_name($companyName);
            $saved = company_store_uploaded_file($_FILES['file'], $destDir);
            if (!$saved['ok']) { echo json_encode($saved); exit; }
            $filePath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        }
        if ($message === '' && !$filePath) { echo json_encode(['ok' => false, 'error' => 'پیام یا فایلی ارسال نشد.']); exit; }

        $pdo->prepare("INSERT INTO company_chat_messages (company_id, sender_type, sender_portal_user_id, message, file_path) VALUES (?, 'COMPANY', ?, ?, ?)")
            ->execute([$companyId, $session['company_user_id'], $message ?: null, $filePath]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- ویرایش/حذف پیام: فقط پیام‌های سمتِ خودِ شرکت و فقط داخل شرکت‌های مجازِ
    //      همین کاربر (نه پیام‌های ما، نه پیام شرکت دیگر) ----
    if ($action === 'chat_edit_message' || $action === 'chat_delete_message') {
        $messageId = intval($data['message_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM company_chat_messages
                                WHERE id = ? AND sender_type = 'COMPANY' AND company_id IN ($placeholders)");
        $stmt->execute(array_merge([$messageId], $allowedCompanyIds));
        if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'این پیام قابل تغییر نیست.']); exit; }

        if ($action === 'chat_delete_message') {
            $pdo->prepare("DELETE FROM company_chat_messages WHERE id = ?")->execute([$messageId]);
        } else {
            $message = trim($data['message'] ?? '');
            if ($message === '') { echo json_encode(['ok' => false, 'error' => 'متن پیام خالی است.']); exit; }
            $pdo->prepare("UPDATE company_chat_messages SET message = ? WHERE id = ?")->execute([$message, $messageId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[company_portal_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
