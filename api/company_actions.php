<?php
// فایل: api/company_actions.php
// اکشن‌های پنل ادمین/همکار برای ماژول «شرکت‌ها»: مدیریت شرکت‌ها و کاربرانشان،
// درخواست‌ها، صندوق ورودی مدارک (تگ‌گذاری دستی)، و ثبت صدور نهایی.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require __DIR__ . '/finance_core.php'; // فقط برای fin_split_installments (تابعی محض، بدون وابستگی)

$actor = require_admin_or_liaison();

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

function jd($ts) { return $ts ? jalali_from_gregorian_ts_dotted($ts) : null; }

// مسیر پوشه‌ی «نامه»ی یک درخواست (سطح بالاتر از تفکیک بدنه/ثالث، چون یک نامه
// می‌تواند چند پلاک از هر دو نوع را همزمان پوشش بدهد)
function company_request_letter_folder($siteRoot, $requestCreatedTs, $companyName) {
    [$jy, ] = jalali_from_gregorian_ts($requestCreatedTs);
    $letterDate = jalali_from_gregorian_ts_dotted($requestCreatedTs);
    return company_archive_root($siteRoot) . '/' . $jy . '/' . sanitize_folder_name($companyName) . '/' . $letterDate;
}

// پوشه‌ی یک پلاک را (بر اساس وضعیت فعلی‌اش) محاسبه می‌کند و اگر پوشه‌ی قبلی‌اش
// جای دیگری بود (مثلاً چون insurance_type بعداً عوض شده) آن را به مسیر جدید
// منتقل می‌کند - خودترمیم‌گر، هم برای اولین بار و هم برای اصلاحات بعدی مناسب است.
function ensure_plate_folder($pdo, $siteRoot, $plateId) {
    $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, cr.created_at AS request_created_at, c.name AS company_name
                            FROM company_request_plates crp
                            JOIN company_requests cr ON cr.id = crp.request_id
                            JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch();
    if (!$plate) return null;

    $plateDisplay = company_plate_display($plate['plate_p1'], $plate['plate_p2'], $plate['plate_letter'], $plate['plate_p4']);
    $desired = build_company_plate_folder($siteRoot, strtotime($plate['request_created_at']), $plate['company_name'], $plate['insurance_type'], $plate['expiry_date'], $plateDisplay);

    if ($plate['folder_path'] && $plate['folder_path'] !== $desired && is_dir($plate['folder_path'])) {
        if (!is_dir(dirname($desired))) @mkdir(dirname($desired), 0755, true);
        if (!is_dir($desired)) @rename($plate['folder_path'], $desired);
    }
    if (!is_dir($desired)) @mkdir($desired, 0755, true);
    if ($plate['folder_path'] !== $desired) {
        $pdo->prepare("UPDATE company_request_plates SET folder_path = ? WHERE id = ?")->execute([$desired, $plateId]);
    }
    return $desired;
}

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

    // ---- گروه‌های بله‌ای که ربات دیده (برای انتخابگر «گروه مرتبط با شرکت») ----
    if ($action === 'list_bot_groups') {
        $stmt = $pdo->query("SELECT chat_id, title FROM bot_known_groups ORDER BY last_seen_at DESC LIMIT 200");
        echo json_encode(['ok' => true, 'groups' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- لیست شرکت‌های درخواست‌کننده (با پروفایل کامل + نام شرکت مادر) ----
    if ($action === 'list_companies') {
        $stmt = $pdo->query("SELECT c.*, parent.name AS parent_name FROM companies c
                              LEFT JOIN companies parent ON parent.id = c.parent_id
                              WHERE c.kind IN ('INSURANCE_CLIENT','BOTH') ORDER BY c.name");
        echo json_encode(['ok' => true, 'companies' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ساخت/ویرایش شرکت (create_company بدون id، update_company با id) ----
    if ($action === 'create_company' || $action === 'update_company') {
        $name = trim($data['name'] ?? '');
        if ($name === '') { echo json_encode(['ok' => false, 'error' => 'نام شرکت را وارد کنید.']); exit; }
        $paymentTerms = in_array($data['payment_terms'] ?? '', ['INSTALLMENT', 'CASH_NET30', 'CASH_IMMEDIATE'], true) ? $data['payment_terms'] : null;
        $economicCode = trim($data['economic_code'] ?? '') ?: null;
        $address = trim($data['address'] ?? '') ?: null;
        $phone = trim($data['phone'] ?? '') ?: null;
        $parentId = intval($data['parent_company_id'] ?? 0) ?: null;
        $groupChatId = trim($data['bale_group_chat_id'] ?? '') ?: null;
        $instCount = intval($data['installment_count'] ?? 0) ?: null;
        $offsetMonths = intval($data['first_due_offset_months'] ?? 0);
        $offsetDays = intval($data['first_due_offset_days'] ?? 0);

        if ($action === 'update_company') {
            $companyId = intval($data['id'] ?? 0);
            if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت نامعتبر است.']); exit; }
            if ($parentId === $companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت نمی‌تواند زیرمجموعه‌ی خودش باشد.']); exit; }
            $pdo->prepare("UPDATE companies SET name=?, payment_terms=?, economic_code=?, address=?, phone=?, parent_id=?,
                            bale_group_chat_id=?, installment_count=?, first_due_offset_months=?, first_due_offset_days=? WHERE id=?")
                ->execute([$name, $paymentTerms, $economicCode, $address, $phone, $parentId, $groupChatId, $instCount, $offsetMonths, $offsetDays, $companyId]);
            echo json_encode(['ok' => true, 'company_id' => $companyId]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, kind FROM companies WHERE name = ?");
        $stmt->execute([$name]);
        $existing = $stmt->fetch();
        if ($existing) {
            $newKind = $existing['kind'] === 'PERSONNEL_EMPLOYER' ? 'BOTH' : $existing['kind'];
            $pdo->prepare("UPDATE companies SET kind=?, payment_terms=?, economic_code=?, address=?, phone=?, parent_id=?,
                            bale_group_chat_id=?, installment_count=?, first_due_offset_months=?, first_due_offset_days=? WHERE id=?")
                ->execute([$newKind, $paymentTerms, $economicCode, $address, $phone, $parentId, $groupChatId, $instCount, $offsetMonths, $offsetDays, $existing['id']]);
            echo json_encode(['ok' => true, 'company_id' => $existing['id']]);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO companies (name, kind, payment_terms, economic_code, address, phone, parent_id,
                                bale_group_chat_id, installment_count, first_due_offset_months, first_due_offset_days)
                                VALUES (?, 'INSURANCE_CLIENT', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $paymentTerms, $economicCode, $address, $phone, $parentId, $groupChatId, $instCount, $offsetMonths, $offsetDays]);
        echo json_encode(['ok' => true, 'company_id' => $pdo->lastInsertId()]);
        exit;
    }

    // ---- ساخت حساب کاربری ثبت‌کننده (می‌تواند به چند شرکت همزمان وصل شود) ----
    if ($action === 'create_portal_user') {
        $companyIds = array_values(array_filter(array_map('intval', (array)($data['company_ids'] ?? []))));
        $username = trim($data['username'] ?? '');
        $password = (string)($data['password'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        if (!$companyIds || $username === '' || $password === '' || $fullName === '') {
            echo json_encode(['ok' => false, 'error' => 'همه‌ی فیلدها الزامی هستند و حداقل یک شرکت باید انتخاب شود.']);
            exit;
        }
        if (strlen($password) < 6) {
            echo json_encode(['ok' => false, 'error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']);
            exit;
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO company_portal_users (company_id, username, password_hash, full_name, mobile_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$companyIds[0], $username, password_hash($password, PASSWORD_DEFAULT), $fullName, trim($data['mobile_number'] ?? '') ?: null]);
        $portalUserId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO company_portal_user_companies (portal_user_id, company_id) VALUES (?, ?)");
        foreach ($companyIds as $cid) $stmt->execute([$portalUserId, $cid]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'portal_user_id' => $portalUserId]);
        exit;
    }

    // ---- لیست کاربران ثبت‌کننده (برای مرور در پنل مدیریت) ----
    if ($action === 'list_portal_users') {
        $stmt = $pdo->query("SELECT cpu.id, cpu.username, cpu.full_name, cpu.mobile_number, cpu.is_active,
                                     GROUP_CONCAT(c.name SEPARATOR '، ') AS company_names
                              FROM company_portal_users cpu
                              LEFT JOIN company_portal_user_companies cpuc ON cpuc.portal_user_id = cpu.id
                              LEFT JOIN companies c ON c.id = cpuc.company_id
                              GROUP BY cpu.id ORDER BY cpu.created_at DESC");
        echo json_encode(['ok' => true, 'users' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
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
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['created_at_jalali'] = jd(strtotime($r['created_at'])); $r['updated_at_jalali'] = jd(strtotime($r['updated_at'])); }
        echo json_encode(['ok' => true, 'requests' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- جزئیات یک درخواست (دید ادمین/همکار): مدارک، پلاک‌ها، مدارک کم، اقساط ----
    if ($action === 'request_detail') {
        $requestId = intval($data['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT cr.*, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }
        $request['created_at_jalali'] = jd(strtotime($request['created_at']));
        $request['updated_at_jalali'] = jd(strtotime($request['updated_at']));

        $stmt = $pdo->prepare("SELECT * FROM company_documents WHERE request_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$requestId]);
        $docs = $stmt->fetchAll();
        foreach ($docs as &$d) $d['uploaded_at_jalali'] = jd(strtotime($d['uploaded_at']));

        $stmt = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ?");
        $stmt->execute([$requestId]);
        $plates = $stmt->fetchAll();
        foreach ($plates as &$p) {
            $assignedTypes = array_column(array_filter($docs, fn($d) => $d['plate_id'] == $p['id'] && $d['status'] === 'ASSIGNED'), 'doc_type');
            $p['missing_docs'] = company_plate_missing_docs($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes);
            $p['expiry_date_jalali'] = $p['expiry_date'] ? jd(strtotime($p['expiry_date'])) : null;
            $p['issued_at_jalali'] = $p['issued_at'] ? jd(strtotime($p['issued_at'])) : null;
            $stmtInst = $pdo->prepare("SELECT * FROM company_installments WHERE plate_id = ? ORDER BY inst_number");
            $stmtInst->execute([$p['id']]);
            $p['installments'] = $stmtInst->fetchAll();
        }

        echo json_encode(['ok' => true, 'request' => $request, 'documents' => $docs, 'plates' => $plates], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- صندوق ورودیِ مدارک تخصیص‌نیافته (همه‌ی شرکت‌ها) ----
    if ($action === 'list_inbox') {
        $stmt = $pdo->query("SELECT cd.*, c.name AS company_name FROM company_documents cd
                              JOIN companies c ON c.id = cd.company_id
                              WHERE cd.status = 'UNASSIGNED' ORDER BY cd.uploaded_at ASC LIMIT 300");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) $r['uploaded_at_jalali'] = jd(strtotime($r['uploaded_at']));
        echo json_encode(['ok' => true, 'documents' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- تگ‌گذاری/تاییدِ یک مدرک: پلاک + نوع مدرک + درخواست، سپس جابه‌جاییِ خودکار فایل ----
    // اگر doc.file_kind==='LETTER' است، به پوشه‌ی سطح-نامه (نه پوشه‌ی یک پلاک خاص) می‌رود.
    if ($action === 'assign_document') {
        $docId = intval($data['doc_id'] ?? 0);
        $requestId = intval($data['request_id'] ?? 0);
        $docType = trim($data['doc_type'] ?? '');
        $p1 = trim($data['plate_p1'] ?? ''); $p2 = trim($data['plate_p2'] ?? '');
        $letter = trim($data['plate_letter'] ?? ''); $p4 = trim($data['plate_p4'] ?? '');
        $expiryDate = trim($data['expiry_date'] ?? '') ?: null;
        $insuranceType = in_array($data['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $data['insurance_type'] : null;
        $skipHealth = !empty($data['skip_health_inspection']) ? 1 : 0;

        $stmt = $pdo->prepare("SELECT cd.*, c.name AS company_name FROM company_documents cd JOIN companies c ON c.id = cd.company_id WHERE cd.id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) { echo json_encode(['ok' => false, 'error' => 'مدرک یافت نشد.']); exit; }

        $stmt = $pdo->prepare("SELECT id, created_at FROM company_requests WHERE id = ? AND company_id = ?");
        $stmt->execute([$requestId, $doc['company_id']]);
        $requestRow = $stmt->fetch();
        if (!$requestRow) { echo json_encode(['ok' => false, 'error' => 'درخواست انتخاب‌شده متعلق به این شرکت نیست.']); exit; }

        $pdo->beginTransaction();
        $siteRoot = dirname(__DIR__);
        $absOld = $siteRoot . '/' . $doc['file_path'];

        // ---- حالت «نامه»: به پوشه‌ی سطح-درخواست می‌رود، نه پوشه‌ی یک پلاک ----
        if ($doc['file_kind'] === 'LETTER') {
            $destDir = company_request_letter_folder($siteRoot, strtotime($requestRow['created_at']), $doc['company_name']);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            $newRelPath = $doc['file_path'];
            if (is_file($absOld) && dirname($absOld) !== $destDir) {
                $destPath = unique_dest_path($destDir . '/' . basename($absOld));
                if (@rename($absOld, $destPath)) $newRelPath = ltrim(str_replace($siteRoot, '', $destPath), '/');
            }
            $pdo->prepare("UPDATE company_documents SET request_id = ?, doc_type = 'letter', status = 'ASSIGNED', assigned_by = ?, assigned_at = NOW(), file_path = ? WHERE id = ?")
                ->execute([$requestId, $actor['user_id'], $newRelPath, $docId]);
            $pdo->prepare("UPDATE company_requests SET status = IF(status = 'NEW', 'DOCS_REVIEW', status) WHERE id = ?")->execute([$requestId]);
            $pdo->commit();
            echo json_encode(['ok' => true]);
            exit;
        }

        // ---- حالت عادی: باید به یک پلاکِ مشخص وصل شود ----
        $plateId = intval($data['plate_id'] ?? 0) ?: null;
        $plateDisplay = company_plate_display($p1, $p2, $letter, $p4);
        if (!$plateId && $plateDisplay) {
            $stmt = $pdo->prepare("SELECT id FROM company_request_plates WHERE request_id = ? AND plate_p1 <=> ? AND plate_p2 <=> ? AND plate_letter <=> ? AND plate_p4 <=> ?");
            $stmt->execute([$requestId, $p1, $p2, $letter, $p4]);
            $plateId = $stmt->fetchColumn() ?: null;
        }
        if (!$plateId && $plateDisplay) {
            $stmt = $pdo->prepare("INSERT INTO company_request_plates (request_id, plate_p1, plate_p2, plate_letter, plate_p4, insurance_type, expiry_date, skip_health_inspection) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$requestId, $p1, $p2, $letter, $p4, $insuranceType, $expiryDate, $skipHealth]);
            $plateId = $pdo->lastInsertId();
        } elseif ($plateId && ($insuranceType || $expiryDate || isset($data['skip_health_inspection']))) {
            $pdo->prepare("UPDATE company_request_plates SET insurance_type = COALESCE(?, insurance_type), expiry_date = COALESCE(?, expiry_date), skip_health_inspection = ? WHERE id = ?")
                ->execute([$insuranceType, $expiryDate, $skipHealth, $plateId]);
        }
        if (!$plateId) { $pdo->rollBack(); echo json_encode(['ok' => false, 'error' => 'پلاک را مشخص کنید.']); exit; }

        $plateFolder = ensure_plate_folder($pdo, $siteRoot, $plateId);
        $isHealthDoc = ($docType === 'health_inspection');
        $destDir = $plateFolder . '/' . ($isHealthDoc ? 'بازدید سلامت' : 'مدارک');
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        $newRelPath = $doc['file_path'];
        if (is_file($absOld) && dirname($absOld) !== $destDir) {
            $destPath = unique_dest_path($destDir . '/' . basename($absOld));
            if (@rename($absOld, $destPath)) $newRelPath = ltrim(str_replace($siteRoot, '', $destPath), '/');
        }

        $pdo->prepare("UPDATE company_documents SET request_id = ?, plate_id = ?, doc_type = ?, plate_p1 = ?, plate_p2 = ?, plate_letter = ?, plate_p4 = ?,
                        status = 'ASSIGNED', assigned_by = ?, assigned_at = NOW(), file_path = ? WHERE id = ?")
            ->execute([$requestId, $plateId, $docType ?: null, $p1 ?: null, $p2 ?: null, $letter ?: null, $p4 ?: null, $actor['user_id'], $newRelPath, $docId]);

        $pdo->prepare("UPDATE company_requests SET status = IF(status = 'NEW', 'DOCS_REVIEW', status) WHERE id = ?")->execute([$requestId]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'plate_id' => $plateId]);
        exit;
    }

    // ---- آپلود گزارش بازدید سلامت توسط خودمان (نه شرکت)، مستقیم برای یک پلاک ----
    if ($action === 'upload_health_report') {
        $plateId = intval($data['plate_id'] ?? 0);
        if (!$plateId) { echo json_encode(['ok' => false, 'error' => 'پلاک را مشخص کنید.']); exit; }
        $siteRoot = dirname(__DIR__);
        $plateFolder = ensure_plate_folder($pdo, $siteRoot, $plateId);
        if (!$plateFolder) { echo json_encode(['ok' => false, 'error' => 'پلاک یافت نشد.']); exit; }
        $destDir = $plateFolder . '/بازدید سلامت';
        $saved = company_store_uploaded_file($_FILES['report_file'] ?? [], $destDir, 15728640, true);
        if (!$saved['ok']) { echo json_encode($saved); exit; }

        $stmt = $pdo->prepare("SELECT request_id, cr.company_id FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
        $stmt->execute([$plateId]);
        $row = $stmt->fetch();
        $relPath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status, assigned_by, assigned_at)
                        VALUES (?, ?, ?, NULL, ?, ?, 'SUPPORTING_DOC', 'health_inspection', 'ASSIGNED', ?, NOW())")
            ->execute([$row['company_id'], $row['request_id'], $plateId, $relPath, $saved['orig_name'], $actor['user_id']]);

        echo json_encode(['ok' => true]);
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

        $siteRoot = dirname(__DIR__);
        $baseDir = ensure_plate_folder($pdo, $siteRoot, $plateId);
        if (!$baseDir) { echo json_encode(['ok' => false, 'error' => 'پلاک یافت نشد.']); exit; }

        $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, c.name AS company_name FROM company_request_plates crp
                                JOIN company_requests cr ON cr.id = crp.request_id
                                JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
        $stmt->execute([$plateId]);
        $plate = $stmt->fetch();

        $plateDisplay = company_plate_display($plate['plate_p1'], $plate['plate_p2'], $plate['plate_letter'], $plate['plate_p4']);
        $issuedFolderName = build_company_issued_folder_name($plateDisplay, $plate['company_name'], $policyNumber, $vin);
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

        $issuedAt = time();
        $folderStatus = 'FAILED';
        if (is_dir($issuedDir)) {
            $copied = company_copy_to_shared_sadere($siteRoot, $issuedDir, $issuedAt, $plate['insurance_type'], $issuedFolderName);
            $folderStatus = $copied ? 'TRANSFERRED' : 'FAILED';
        }

        $pdo->prepare("UPDATE company_request_plates SET status = 'ISSUED', policy_number = ?, vin = ?, total_premium = ?,
                        folder_path = ?, issued_file_path = COALESCE(?, issued_file_path), issued_at = FROM_UNIXTIME(?), folder_status = ? WHERE id = ?")
            ->execute([$policyNumber, $vin ?: null, $totalPremium, $issuedDir, $issuedFilePath, $issuedAt, $folderStatus, $plateId]);

        if ($totalPremium) {
            $genResult = company_generate_installments($pdo, $plateId);
        }

        // اگر همه‌ی پلاک‌های این درخواست صادر شده باشند، خودِ درخواست هم ISSUED می‌شود
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'ISSUED') AS issued FROM company_request_plates WHERE request_id = ?");
        $stmt->execute([$plate['request_id']]);
        $counts = $stmt->fetch();
        if ($counts && intval($counts['total']) > 0 && intval($counts['total']) === intval($counts['issued'])) {
            $pdo->prepare("UPDATE company_requests SET status = 'ISSUED' WHERE id = ?")->execute([$plate['request_id']]);
        }

        echo json_encode(['ok' => true, 'folder_status' => $folderStatus]);
        exit;
    }

    // ---- تلاش دستی دوباره برای انتقال/کپیِ پوشه به بایگانی صادره (اگر خودکار شکست خورد) ----
    if ($action === 'retry_folder_transfer') {
        require_admin_only();
        $plateId = intval($data['plate_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ? AND status = 'ISSUED'");
        $stmt->execute([$plateId]);
        $plate = $stmt->fetch();
        if (!$plate || !$plate['folder_path']) { echo json_encode(['ok' => false, 'error' => 'پلاک صادرشده یا پوشه‌اش یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $issuedFolderName = basename($plate['folder_path']);
        $issuedTs = $plate['issued_at'] ? strtotime($plate['issued_at']) : time();
        $copied = company_copy_to_shared_sadere($siteRoot, $plate['folder_path'], $issuedTs, $plate['insurance_type'], $issuedFolderName);
        $pdo->prepare("UPDATE company_request_plates SET folder_status = ? WHERE id = ?")->execute([$copied ? 'TRANSFERRED' : 'FAILED', $plateId]);
        echo json_encode(['ok' => $copied, 'error' => $copied ? null : 'باز هم ناموفق بود؛ مسیر پوشه را روی سرور بررسی کنید.']);
        exit;
    }

    // ---- دانلود دسته‌جمعی (زیپ) همه‌ی بیمه‌نامه‌های صادرشده‌ی یک درخواست ----
    if ($action === 'download_issued_zip') {
        $requestId = intval($_GET['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT plate_p1, plate_p2, plate_letter, plate_p4, issued_file_path FROM company_request_plates WHERE request_id = ? AND issued_file_path IS NOT NULL");
        $stmt->execute([$requestId]);
        $rows = $stmt->fetchAll();
        if (!$rows) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'فایل صادرشده‌ای یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $files = [];
        foreach ($rows as $r) {
            $plateDisplay = company_plate_display($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']);
            $files[] = ['path' => $siteRoot . '/' . $r['issued_file_path'], 'name' => sanitize_folder_name($plateDisplay ?: 'بدون‌پلاک') . '.' . pathinfo($r['issued_file_path'], PATHINFO_EXTENSION)];
        }
        $zipPath = build_zip_from_files($files, "درخواست-{$requestId}.zip");
        if (!$zipPath) { echo json_encode(['ok' => false, 'error' => 'خطا در ساخت فایل زیپ.']); exit; }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="درخواست-' . $requestId . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[company_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
