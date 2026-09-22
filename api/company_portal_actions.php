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

// اگر مایگریشنِ لازم اجرا نشده باشد، به‌جای «خطای سرور» پیامِ روشن بده
$schemaProblem = company_schema_problem($pdo);
if ($schemaProblem) { echo json_encode(['ok' => false, 'error' => $schemaProblem], JSON_UNESCAPED_UNICODE); exit; }

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
        // جستجو روی متن درخواست، نام شرکت، شماره‌ی درخواست، و پلاک/شماره شاسیِ ردیف‌ها
        $q = trim($data['q'] ?? ($_GET['q'] ?? ''));
        $searchSql = ''; $searchParams = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $searchSql = " AND (cr.request_text LIKE ? OR c.name LIKE ? OR cr.id = ? OR EXISTS (
                              SELECT 1 FROM company_request_plates crp WHERE crp.request_id = cr.id AND (
                                  crp.chassis_no LIKE ? OR crp.car_name LIKE ? OR crp.policy_number LIKE ?
                                  OR CONCAT_WS(' ', crp.plate_p4, crp.plate_letter, crp.plate_p2, crp.plate_p1) LIKE ?)))";
            $searchParams = [$like, $like, intval($q), $like, $like, $like, $like];
        }
        $stmt = $pdo->prepare("SELECT cr.*, c.name AS company_name,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type = 'BODY') AS body_count,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type <> 'BODY') AS third_count,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type = 'BODY' AND crp.status = 'ISSUED') AS body_issued,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type <> 'BODY' AND crp.status = 'ISSUED') AS third_issued
                                FROM company_requests cr
                                JOIN companies c ON c.id = cr.company_id
                                WHERE cr.company_id IN ($placeholders) $searchSql ORDER BY cr.created_at DESC");
        $stmt->execute(array_merge($allowedCompanyIds, $searchParams));
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['created_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($r['created_at']));
            $r['updated_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($r['updated_at']));
            foreach (['body_count', 'third_count', 'body_issued', 'third_issued'] as $k) $r[$k] = intval($r[$k]);
            $r['request_kind_fa'] = company_request_kind_fa($r['request_kind'] ?? 'NEW_POLICY');
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
        $reqKind = $request['request_kind'] ?? 'NEW_POLICY';
        $request['request_kind_fa'] = company_request_kind_fa($reqKind);

        // file_path هم لازم است، وگرنه لینکِ «مدارک ارسالی» در پنل شرکت به
        // ../undefined می‌خورد و کاربر نمی‌تواند فایلی که خودش فرستاده را ببیند
        $stmt = $pdo->prepare("SELECT id, orig_name, file_path, file_kind, doc_type, plate_id, status, uploaded_at FROM company_documents WHERE request_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$requestId]);
        $docs = $stmt->fetchAll();
        $siteRoot = dirname(__DIR__);
        foreach ($docs as &$d) {
            $d['doc_type_label'] = company_doc_type_label($d['doc_type']);
            $d['is_dir'] = is_dir($siteRoot . '/' . $d['file_path']);
            $d['uploaded_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($d['uploaded_at']));
        }
        unset($d);

        $stmt = $pdo->prepare("SELECT id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle,
                                       car_value, liability_limit, ref_policy_number, endorsement_request, cancellation_reason,
                                       insurance_type, status, expiry_date,
                                       skip_health_inspection, has_prev_body, car_name, row_note, policy_number, issued_at,
                                       issued_file_path, issued_file_path IS NOT NULL AS has_issued_file
                                FROM company_request_plates WHERE request_id = ? ORDER BY id");
        $stmt->execute([$requestId]);
        $plates = $stmt->fetchAll();
        // همان چک‌لیستی که ما در پنل خودمان می‌بینیم، عیناً برای ثبت‌کننده‌ی شرکت هم
        // نمایش داده می‌شود تا هر دو طرف بدانند هر ردیف چه کم دارد و خودش بتواند
        // همان‌جا آپلود کند (خواسته‌ی صریح کارفرما: «هم اون بتونه انجام بده هم من»)
        foreach ($plates as &$p) {
            $rowDocs = array_values(array_filter($docs, fn($d) => $d['plate_id'] == $p['id'] && $d['status'] === 'ASSIGNED'));
            $assignedTypes = array_values(array_filter(array_column($rowDocs, 'doc_type')));
            $p['plate_display'] = company_row_label($p);
            $p['status_fa'] = company_plate_status_fa($p['status'], $reqKind);
            $p['checklist'] = company_plate_checklist($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes, $p['has_prev_body'], $reqKind);
            foreach ($p['checklist'] as &$item) {
                $item['docs'] = array_values(array_map(
                    fn($d) => ['id' => $d['id'], 'file_path' => $d['file_path'], 'doc_type' => $d['doc_type'],
                               'label' => company_doc_type_label($d['doc_type']), 'is_dir' => !empty($d['is_dir'])],
                    array_filter($rowDocs, fn($d) => in_array($d['doc_type'], $item['upload_types'], true) || $d['doc_type'] === $item['key'])
                ));
            }
            unset($item);
            $p['present_labels'] = company_plate_present_labels($assignedTypes);
            $p['missing_docs'] = company_plate_missing_docs($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes, $p['has_prev_body'], $reqKind);
            if ($p['expiry_date']) $p['expiry_date_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($p['expiry_date']));
            if ($p['issued_at']) $p['issued_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($p['issued_at']));
        }
        unset($p);

        $counts = ['body' => 0, 'third' => 0, 'body_issued' => 0, 'third_issued' => 0];
        foreach ($plates as $p) {
            $k = $p['insurance_type'] === 'BODY' ? 'body' : 'third';
            $counts[$k]++;
            if ($p['status'] === 'ISSUED') $counts[$k . '_issued']++;
        }

        echo json_encode(['ok' => true, 'request' => $request, 'documents' => $docs, 'plates' => $plates,
                          'counts' => $counts, 'doc_types' => company_doc_types(),
                          'request_kind' => $reqKind], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- آپلود هدفمندِ یک مدرک روی یک ردیف، از دکمه‌ی همان خانه‌ی چک‌لیست.
    //      چون پلاک و نوع مدرک مشخص است، مستقیم ASSIGNED می‌شود، نام‌گذاری
    //      استاندارد «(نوع مدرک) پلاک» می‌گیرد و داخل پوشه‌ی همان ردیف می‌نشیند. ----
    if (isset($_FILES['doc_file']) && ($_POST['action'] ?? '') === 'upload_row_doc') {
        $plateId = intval($_POST['plate_id'] ?? 0);
        $docType = trim($_POST['doc_type'] ?? '');
        if (!$plateId || !array_key_exists($docType, company_doc_types())) {
            echo json_encode(['ok' => false, 'error' => 'ردیف یا نوع مدرک مشخص نیست.']); exit;
        }
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT crp.id, crp.request_id, cr.company_id FROM company_request_plates crp
                                JOIN company_requests cr ON cr.id = crp.request_id
                                WHERE crp.id = ? AND cr.company_id IN ($placeholders)");
        $stmt->execute(array_merge([$plateId], $allowedCompanyIds));
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['ok' => false, 'error' => 'این ردیف متعلق به شرکت شما نیست.']); exit; }

        $siteRoot = dirname(__DIR__);
        $plateFolder = ensure_plate_folder($pdo, $siteRoot, $plateId);
        if (!$plateFolder) { echo json_encode(['ok' => false, 'error' => 'پوشه‌ی این ردیف ساخته نشد.']); exit; }

        $isHealth = ($docType === 'health_inspection');
        $saved = company_store_uploaded_file($_FILES['doc_file'], $plateFolder, 31457280, $isHealth, false);
        if (!$saved['ok']) { echo json_encode($saved); exit; }

        $relPath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'SUPPORTING_DOC', ?, 'ASSIGNED')")
            ->execute([$row['company_id'], $row['request_id'], $plateId, $session['company_user_id'], $relPath, $saved['orig_name'], $docType]);
        $docId = $pdo->lastInsertId();

        $newRel = company_place_document($pdo, $siteRoot, $docId, $plateId, $docType);
        $rowStatus = company_sync_plate_status($pdo, $plateId);
        echo json_encode(['ok' => true, 'doc_id' => $docId, 'file_path' => $newRel, 'row_status' => $rowStatus], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- فیدِ اعلان‌های پنل شرکت: پیام تازه از ما، و بیمه‌نامه‌ی تازه صادرشده ----
    if ($action === 'notifications_feed') {
        $since = trim($data['since'] ?? '');
        if ($since === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            echo json_encode(['ok' => true, 'now' => date('Y-m-d H:i:s'), 'events' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ph = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $events = [];

        $stmt = $pdo->prepare("SELECT message, created_at FROM company_chat_messages
                                WHERE created_at > ? AND sender_type = 'ADMIN' AND company_id IN ($ph)
                                ORDER BY created_at DESC LIMIT 20");
        $stmt->execute(array_merge([$since], $allowedCompanyIds));
        foreach ($stmt->fetchAll() as $r) {
            $events[] = ['type' => 'chat', 'title' => 'پیام جدید از بیمه با ما',
                         'body' => mb_substr((string)($r['message'] ?: 'یک فایل فرستاد'), 0, 90), 'at' => $r['created_at']];
        }

        $stmt = $pdo->prepare("SELECT crp.policy_number, crp.insurance_type, crp.issued_at,
                                      crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no
                                 FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                                WHERE crp.issued_at > ? AND cr.company_id IN ($ph)
                                ORDER BY crp.issued_at DESC LIMIT 20");
        $stmt->execute(array_merge([$since], $allowedCompanyIds));
        foreach ($stmt->fetchAll() as $r) {
            $events[] = ['type' => 'issued', 'title' => 'بیمه‌نامه صادر شد',
                         'body' => insurance_type_fa($r['insurance_type']) . ' ' . company_row_label($r)
                                   . ($r['policy_number'] ? ' - شماره ' . $r['policy_number'] : ''),
                         'at' => $r['issued_at']];
        }

        usort($events, fn($a, $b) => strcmp($a['at'], $b['at']));
        echo json_encode(['ok' => true, 'now' => date('Y-m-d H:i:s'), 'events' => array_slice($events, -25)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- فهرست متنیِ ریزِ یک درخواست (همان قالبی که ما هم می‌بینیم) ----
    if ($action === 'request_rows_text') {
        $requestId = intval($data['request_id'] ?? ($_GET['request_id'] ?? 0));
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM company_requests WHERE id = ? AND company_id IN ($placeholders)");
        $stmt->execute(array_merge([$requestId], $allowedCompanyIds));
        if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }
        echo json_encode(['ok' => true, 'text' => company_request_rows_text_report($pdo, $requestId)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- دانلود فایل بیمه‌نامه‌ی صادرشده‌ی یک ردیف (فقط ردیف‌های شرکت‌های خودش) ----
    if (($action === 'download_issued_policy') || ($_GET['action'] ?? '') === 'download_issued_policy') {
        $plateId = intval($data['plate_id'] ?? ($_GET['plate_id'] ?? 0));
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT crp.issued_file_path, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4,
                                      crp.policy_number, crp.insurance_type
                                 FROM company_request_plates crp
                                 JOIN company_requests cr ON cr.id = crp.request_id
                                WHERE crp.id = ? AND cr.company_id IN ($placeholders) AND crp.status = 'ISSUED'");
        $stmt->execute(array_merge([$plateId], $allowedCompanyIds));
        $row = $stmt->fetch();
        $siteRoot = dirname(__DIR__);
        $abs = ($row && $row['issued_file_path']) ? $siteRoot . '/' . $row['issued_file_path'] : null;
        if (!$abs || !is_file($abs)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'فایل بیمه‌نامه یافت نشد.']); exit; }

        $plateDisplay = company_row_label($row);
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'pdf';
        $name = sanitize_folder_name(insurance_type_fa($row['insurance_type']) . ' - ' . ($plateDisplay ?: 'بدون پلاک')
                 . ($row['policy_number'] ? ' - ' . policy_number_for_filename($row['policy_number']) : '')) . '.' . $ext;
        header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
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

        // نوعِ درخواست: صدور بیمه‌نامه‌ی جدید، صدور الحاقیه، یا فسخ بیمه‌نامه
        $kind = company_valid_kind($data['request_kind'] ?? 'NEW_POLICY');
        $stmt = $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, request_kind, status) VALUES (?, ?, ?, ?, ?, 'NEW')");
        $stmt->execute([$companyId, $session['company_user_id'], $requestText, $insurer, $kind]);
        $requestId = $pdo->lastInsertId();

        // پلاک‌های اولیه‌ی این درخواست (اختیاری، چند تا مجاز؛ برای هر پلاک هم نوع بیمه‌ی
        // درخواستی‌اش - ثالث/بدنه - مشخص می‌شود؛ اگر «هردو» خواسته شده بود، سمتِ کلاینت
        // از قبل آن را به دو ردیفِ جدا تبدیل کرده است)
        $plates = json_decode($data['plates'] ?? '[]', true);
        if (is_array($plates)) {
            // ردیف می‌تواند با پلاک باشد یا - برای لیفتراک و خودروی صفرکیلومتر - فقط با
            // شماره شاسی. ارزش خودرو (بدنه) و سقف تعهد مالی (ثالث) هم همین‌جا گرفته می‌شود.
            $insPlate = $pdo->prepare("INSERT INTO company_request_plates
                (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle,
                 car_value, liability_limit, ref_policy_number, endorsement_request, cancellation_reason,
                 insurance_type, skip_health_inspection)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($plates as $p) {
                $pp1 = trim($p['p1'] ?? ''); $pp2 = trim($p['p2'] ?? '');
                $pletter = trim($p['letter'] ?? ''); $pp4 = trim($p['p4'] ?? '');
                $chassis = trim($p['chassis_no'] ?? '');
                if ($pp1 === '' && $pp2 === '' && $pletter === '' && $pp4 === '' && $chassis === '') continue;
                $pInsType = in_array($p['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $p['insurance_type'] : null;
                $isNew = !empty($p['is_new_vehicle']) ? 1 : 0;
                $insPlate->execute([$requestId, $pp1 ?: null, $pp2 ?: null, $pletter ?: null, $pp4 ?: null,
                                    $chassis ?: null, trim($p['engine_no'] ?? '') ?: null, $isNew,
                                    company_parse_money($p['car_value'] ?? ''), company_parse_money($p['liability_limit'] ?? ''),
                                    trim($p['ref_policy_number'] ?? '') ?: null,
                                    trim($p['endorsement_request'] ?? '') ?: null,
                                    trim($p['cancellation_reason'] ?? '') ?: null,
                                    $pInsType, $isNew ? 1 : 0]);
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

        // وقتی خودِ ثبت‌کننده صریحاً «نامه‌ی درخواست» را انتخاب کرده، ابهامی نیست و
        // نیازی به تگ‌گذاری ندارد: همان‌جا ASSIGNED می‌شود و مستقیم سرِ جای نامه‌ی
        // همین درخواست می‌نشیند (داخل پوشه‌ی هر نوع بیمه‌ی این درخواست).
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, uploaded_by, file_path, orig_name, file_kind, doc_type, plate_p1, plate_p2, plate_letter, plate_p4, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$companyId, $requestId, $session['company_user_id'], $relPath, $saved['orig_name'], $fileKind,
                       $isLetter ? 'letter' : $docType, $p1, $p2, $letter, $p4, $isLetter ? 'ASSIGNED' : 'UNASSIGNED']);
        $docId = $pdo->lastInsertId();

        if ($isLetter) {
            $ext = strtolower(pathinfo($saved['path'], PATHINFO_EXTENSION)) ?: 'pdf';
            $placed = company_place_letter($pdo, $siteRoot, $requestId, $saved['path'], $ext);
            if ($placed) {
                $relPath = $placed;
                $pdo->prepare("UPDATE company_documents SET file_path = ? WHERE id = ?")->execute([$relPath, $docId]);
            }
            $pdo->prepare("UPDATE company_requests SET letter_file_path = ? WHERE id = ?")->execute([$relPath, $requestId]);
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
