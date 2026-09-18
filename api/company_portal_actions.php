<?php
// فایل: api/company_portal_actions.php
// اکشن‌های پنل ثبت‌کننده‌ی شرکت: لیست درخواست‌ها، ثبت درخواست جدید، آپلود مدرک تدریجی.
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';

$session = require_company_portal_session();
$companyId = intval($session['company_id']);

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

try {
    // ---- لیست درخواست‌های همین شرکت ----
    if ($action === 'my_requests') {
        $stmt = $pdo->prepare("SELECT id, request_text, insurer, status, created_at FROM company_requests WHERE company_id = ? ORDER BY created_at DESC");
        $stmt->execute([$companyId]);
        echo json_encode(['ok' => true, 'requests' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- جزئیات یک درخواست + مدارک آن (فقط اگر متعلق به همین شرکت باشد) ----
    if ($action === 'request_detail') {
        $requestId = intval($data['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM company_requests WHERE id = ? AND company_id = ?");
        $stmt->execute([$requestId, $companyId]);
        $request = $stmt->fetch();
        if (!$request) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $stmt = $pdo->prepare("SELECT id, orig_name, file_kind, doc_type, status, uploaded_at FROM company_documents WHERE request_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$requestId]);
        $docs = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT id, plate_p1, plate_p2, plate_letter, plate_p4, insurance_type, status, expiry_date FROM company_request_plates WHERE request_id = ?");
        $stmt->execute([$requestId]);
        $plates = $stmt->fetchAll();

        echo json_encode(['ok' => true, 'request' => $request, 'documents' => $docs, 'plates' => $plates], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ثبت درخواست جدید (متن + نامه‌ی اختیاری) ----
    if ($action === 'submit_request') {
        $requestText = trim($data['request_text'] ?? '');
        $insurer = ($data['insurer'] ?? 'PASARGAD') === 'IRAN' ? 'IRAN' : 'PASARGAD';
        if ($requestText === '' && empty($_FILES['letter_file'])) {
            echo json_encode(['ok' => false, 'error' => 'متن درخواست یا فایل نامه را وارد کنید.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, status) VALUES (?, ?, ?, ?, 'NEW')");
        $stmt->execute([$companyId, $session['company_user_id'], $requestText, $insurer]);
        $requestId = $pdo->lastInsertId();

        if (!empty($_FILES['letter_file'])) {
            $siteRoot = dirname(__DIR__);
            $stmtName = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
            $stmtName->execute([$companyId]);
            $companyName = $stmtName->fetchColumn() ?: 'نامشخص';
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
    if ($action === 'upload_document') {
        $requestId = intval($data['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM company_requests WHERE id = ? AND company_id = ?");
        $stmt->execute([$requestId, $companyId]);
        if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $stmtName = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $stmtName->execute([$companyId]);
        $companyName = $stmtName->fetchColumn() ?: 'نامشخص';
        $tmpDir = company_unassigned_temp_path($siteRoot, $companyName);
        $saved = company_store_uploaded_file($_FILES['file'] ?? [], $tmpDir);
        if (!$saved['ok']) { echo json_encode($saved); exit; }

        $relPath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, uploaded_by, file_path, orig_name, file_kind, status) VALUES (?, ?, ?, ?, ?, 'SUPPORTING_DOC', 'UNASSIGNED')")
            ->execute([$companyId, $requestId, $session['company_user_id'], $relPath, $saved['orig_name']]);

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[company_portal_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
