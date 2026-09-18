<?php
// فایل: api/company_actions.php
// اکشن‌های پنل ادمین/همکار برای ماژول «شرکت‌ها»: مدیریت شرکت‌ها و کاربرانشان،
// درخواست‌ها، صندوق ورودی مدارک (تگ‌گذاری دستی)، و ثبت صدور نهایی.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';

$actor = require_admin_or_liaison();

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

try {
    // ---- گزارش مالی خلاصه‌ی شرکت‌های درخواست‌کننده (فقط خواندنی - برای ADMIN و همکار) ----
    if ($action === 'finance_summary') {
        $stmt = $pdo->query("
            SELECT c.id, c.name,
                   COALESCE((SELECT SUM(i.total_amount) FROM invoices i WHERE i.company_id = c.id), 0) AS total_invoiced,
                   COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.company_id = c.id), 0) AS total_paid
            FROM companies c
            WHERE c.kind IN ('INSURANCE_CLIENT','BOTH')
            ORDER BY c.name
        ");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['total_invoiced'] = intval($row['total_invoiced']);
            $row['total_paid'] = intval($row['total_paid']);
            $row['outstanding'] = $row['total_invoiced'] - $row['total_paid'];
        }
        echo json_encode(['ok' => true, 'companies' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- لیست شرکت‌های درخواست‌کننده ----
    if ($action === 'list_companies') {
        $stmt = $pdo->query("SELECT id, name, kind, payment_terms FROM companies WHERE kind IN ('INSURANCE_CLIENT','BOTH') ORDER BY name");
        echo json_encode(['ok' => true, 'companies' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ساخت شرکت جدید (یا ارتقای شرکت موجود به INSURANCE_CLIENT) ----
    if ($action === 'create_company') {
        $name = trim($data['name'] ?? '');
        $paymentTerms = in_array($data['payment_terms'] ?? '', ['INSTALLMENT', 'CASH_NET30', 'CASH_IMMEDIATE'], true) ? $data['payment_terms'] : null;
        if ($name === '') { echo json_encode(['ok' => false, 'error' => 'نام شرکت را وارد کنید.']); exit; }

        $stmt = $pdo->prepare("SELECT id, kind FROM companies WHERE name = ?");
        $stmt->execute([$name]);
        $existing = $stmt->fetch();
        if ($existing) {
            $newKind = $existing['kind'] === 'PERSONNEL_EMPLOYER' ? 'BOTH' : $existing['kind'];
            $pdo->prepare("UPDATE companies SET kind = ?, payment_terms = ? WHERE id = ?")->execute([$newKind, $paymentTerms, $existing['id']]);
            echo json_encode(['ok' => true, 'company_id' => $existing['id']]);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO companies (name, kind, payment_terms) VALUES (?, 'INSURANCE_CLIENT', ?)");
        $stmt->execute([$name, $paymentTerms]);
        echo json_encode(['ok' => true, 'company_id' => $pdo->lastInsertId()]);
        exit;
    }

    // ---- ساخت حساب کاربری ثبت‌کننده برای یک شرکت ----
    if ($action === 'create_portal_user') {
        $companyId = intval($data['company_id'] ?? 0);
        $username = trim($data['username'] ?? '');
        $password = (string)($data['password'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        if (!$companyId || $username === '' || $password === '' || $fullName === '') {
            echo json_encode(['ok' => false, 'error' => 'همه‌ی فیلدها الزامی هستند.']);
            exit;
        }
        if (strlen($password) < 6) {
            echo json_encode(['ok' => false, 'error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO company_portal_users (company_id, username, password_hash, full_name, mobile_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$companyId, $username, password_hash($password, PASSWORD_DEFAULT), $fullName, trim($data['mobile_number'] ?? '') ?: null]);
        echo json_encode(['ok' => true, 'portal_user_id' => $pdo->lastInsertId()]);
        exit;
    }

    // ---- لیست درخواست‌ها (همه‌ی شرکت‌ها یا فیلترشده) ----
    if ($action === 'list_requests') {
        $statusFilter = $data['status'] ?? '';
        $sql = "SELECT cr.*, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id";
        $params = [];
        if ($statusFilter !== '') { $sql .= " WHERE cr.status = ?"; $params[] = $statusFilter; }
        $sql .= " ORDER BY cr.created_at DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'requests' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- جزئیات یک درخواست (دید ادمین/همکار) ----
    if ($action === 'request_detail') {
        $requestId = intval($data['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT cr.*, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM company_documents WHERE request_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$requestId]);
        $docs = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ?");
        $stmt->execute([$requestId]);
        $plates = $stmt->fetchAll();

        echo json_encode(['ok' => true, 'request' => $request, 'documents' => $docs, 'plates' => $plates], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- صندوق ورودیِ مدارک تخصیص‌نیافته (همه‌ی شرکت‌ها) ----
    if ($action === 'list_inbox') {
        $stmt = $pdo->query("SELECT cd.*, c.name AS company_name FROM company_documents cd
                              JOIN companies c ON c.id = cd.company_id
                              WHERE cd.status = 'UNASSIGNED' ORDER BY cd.uploaded_at ASC LIMIT 300");
        echo json_encode(['ok' => true, 'documents' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- تگ‌گذاری دستی یک مدرک: پلاک + نوع مدرک + درخواست، سپس جابه‌جاییِ خودکار فایل ----
    if ($action === 'assign_document') {
        $docId = intval($data['doc_id'] ?? 0);
        $requestId = intval($data['request_id'] ?? 0);
        $docType = trim($data['doc_type'] ?? '');
        $p1 = trim($data['plate_p1'] ?? ''); $p2 = trim($data['plate_p2'] ?? '');
        $letter = trim($data['plate_letter'] ?? ''); $p4 = trim($data['plate_p4'] ?? '');
        $expiryDate = trim($data['expiry_date'] ?? '') ?: null;
        $insuranceType = in_array($data['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $data['insurance_type'] : null;

        $stmt = $pdo->prepare("SELECT cd.*, c.name AS company_name FROM company_documents cd JOIN companies c ON c.id = cd.company_id WHERE cd.id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) { echo json_encode(['ok' => false, 'error' => 'مدرک یافت نشد.']); exit; }

        $stmt = $pdo->prepare("SELECT id, created_at FROM company_requests WHERE id = ? AND company_id = ?");
        $stmt->execute([$requestId, $doc['company_id']]);
        $requestRow = $stmt->fetch();
        if (!$requestRow) { echo json_encode(['ok' => false, 'error' => 'درخواست انتخاب‌شده متعلق به این شرکت نیست.']); exit; }

        $pdo->beginTransaction();

        // اگر پلاکی وارد شده، رکورد پلاکِ همان درخواست را پیدا یا ایجاد کن
        $plateId = null;
        $plateDisplay = company_plate_display($p1, $p2, $letter, $p4);
        if ($plateDisplay) {
            $stmt = $pdo->prepare("SELECT id FROM company_request_plates WHERE request_id = ? AND plate_p1 <=> ? AND plate_p2 <=> ? AND plate_letter <=> ? AND plate_p4 <=> ?");
            $stmt->execute([$requestId, $p1, $p2, $letter, $p4]);
            $plateId = $stmt->fetchColumn();
            if (!$plateId) {
                $stmt = $pdo->prepare("INSERT INTO company_request_plates (request_id, plate_p1, plate_p2, plate_letter, plate_p4, insurance_type, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$requestId, $p1, $p2, $letter, $p4, $insuranceType, $expiryDate]);
                $plateId = $pdo->lastInsertId();
            } elseif ($insuranceType || $expiryDate) {
                $pdo->prepare("UPDATE company_request_plates SET insurance_type = COALESCE(?, insurance_type), expiry_date = COALESCE(?, expiry_date) WHERE id = ?")
                    ->execute([$insuranceType, $expiryDate, $plateId]);
            }
        }

        // انتقال فیزیکی فایل از پوشه‌ی موقت به پوشه‌ی دائمیِ درخواست/پلاک
        $siteRoot = dirname(__DIR__);
        $absOld = $siteRoot . '/' . $doc['file_path'];
        $destDir = build_company_request_folder($siteRoot, strtotime($requestRow['created_at']), $doc['company_name'], $requestId, $plateDisplay);
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        $newRelPath = $doc['file_path'];
        if (is_file($absOld) && dirname($absOld) !== $destDir) {
            $destPath = unique_dest_path($destDir . '/' . basename($absOld));
            if (@rename($absOld, $destPath)) {
                $newRelPath = ltrim(str_replace($siteRoot, '', $destPath), '/');
            }
        }

        $pdo->prepare("UPDATE company_documents SET request_id = ?, doc_type = ?, plate_p1 = ?, plate_p2 = ?, plate_letter = ?, plate_p4 = ?,
                        status = 'ASSIGNED', assigned_by = ?, assigned_at = NOW(), file_path = ? WHERE id = ?")
            ->execute([$requestId, $docType ?: null, $p1 ?: null, $p2 ?: null, $letter ?: null, $p4 ?: null, $actor['user_id'], $newRelPath, $docId]);

        $pdo->prepare("UPDATE company_requests SET status = IF(status = 'NEW', 'DOCS_REVIEW', status) WHERE id = ?")->execute([$requestId]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'plate_id' => $plateId]);
        exit;
    }

    // ---- ثبت صدور نهایی یک پلاک (فقط مدیر کل) ----
    if ($action === 'mark_issued') {
        require_admin_only();
        $plateId = intval($data['plate_id'] ?? 0);
        $policyNumber = trim($data['policy_number'] ?? '');
        $vin = trim($data['vin'] ?? '');
        $totalPremium = intval($data['total_premium'] ?? 0) ?: null;
        if (!$plateId || $policyNumber === '') { echo json_encode(['ok' => false, 'error' => 'شماره‌ی بیمه‌نامه الزامی است.']); exit; }

        $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, cr.created_at AS request_created_at, c.name AS company_name FROM company_request_plates crp
                                JOIN company_requests cr ON cr.id = crp.request_id
                                JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
        $stmt->execute([$plateId]);
        $plate = $stmt->fetch();
        if (!$plate) { echo json_encode(['ok' => false, 'error' => 'پلاک یافت نشد.']); exit; }

        $plateDisplay = company_plate_display($plate['plate_p1'], $plate['plate_p2'], $plate['plate_letter'], $plate['plate_p4']);
        $siteRoot = dirname(__DIR__);
        $baseDir = build_company_request_folder($siteRoot, strtotime($plate['request_created_at']), $plate['company_name'], $plate['request_id'], $plateDisplay);
        $issuedFolderName = build_company_issued_folder_name($plateDisplay, $policyNumber, $vin);
        $issuedDir = dirname($baseDir) . '/' . $issuedFolderName;

        // ابتدا پوشه‌ی قبل از صدور (با مدارک تگ‌گذاری‌شده‌ی احتمالی) را به نام نهایی تغییر نام بده،
        // و فقط بعد از آن فایل بیمه‌نامه‌ی صادرشده را داخلش ذخیره کن - وگرنه چون پوشه‌ی مقصد از
        // قبل با این فایل غیرخالی شده، rename روی آن (وقتی مدارک قبلی هم وجود دارند) شکست می‌خورد
        if (is_dir($baseDir) && $baseDir !== $issuedDir) {
            if (!is_dir(dirname($issuedDir))) @mkdir(dirname($issuedDir), 0755, true);
            @rename($baseDir, $issuedDir);
        }

        $issuedFilePath = null;
        if (!empty($_FILES['issued_file'])) {
            $saved = company_store_uploaded_file($_FILES['issued_file'], $issuedDir);
            if ($saved['ok']) $issuedFilePath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        }

        $pdo->prepare("UPDATE company_request_plates SET status = 'ISSUED', policy_number = ?, vin = ?, total_premium = ?,
                        folder_path = ?, issued_file_path = COALESCE(?, issued_file_path), issued_at = NOW() WHERE id = ?")
            ->execute([$policyNumber, $vin ?: null, $totalPremium, $issuedDir, $issuedFilePath, $plateId]);

        // اگر همه‌ی پلاک‌های این درخواست صادر شده باشند، خودِ درخواست هم ISSUED می‌شود
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'ISSUED') AS issued FROM company_request_plates WHERE request_id = ?");
        $stmt->execute([$plate['request_id']]);
        $counts = $stmt->fetch();
        if ($counts && intval($counts['total']) > 0 && intval($counts['total']) === intval($counts['issued'])) {
            $pdo->prepare("UPDATE company_requests SET status = 'ISSUED' WHERE id = ?")->execute([$plate['request_id']]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[company_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
