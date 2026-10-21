<?php
// فایل: api/company_portal_actions.php
// اکشن‌های پنل ثبت‌کننده‌ی شرکت: لیست درخواست‌ها، ثبت درخواست جدید، آپلود مدرک تدریجی.
// یک کاربر می‌تواند به چند شرکت دسترسی داشته باشد؛ اگر فقط یکی دارد، همه‌جا قفل
// روی همان می‌ماند، وگرنه هر عملیات باید company_id معتبر (از میان شرکت‌های خودش) بدهد.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_dl_name.php';   // نامِ درستِ فایل‌های دانلودی (بدونِ نویسه‌های نامرئی و «(ماه)»)
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';

$session = require_company_portal_session($pdo);
ref_codes_ensure($pdo);
company_defaults_ensure($pdo);   // پیش‌فرضِ پوشش‌ها و تعهدِ شرکت (برای ردیف‌هایی که فرم نداده)
$allowedCompanyIds = $session['company_ids'];

// اگر مایگریشنِ لازم اجرا نشده باشد، به‌جای «خطای سرور» پیامِ روشن بده
$schemaProblem = company_schema_problem($pdo);
if ($schemaProblem) { echo json_encode(['ok' => false, 'error' => $schemaProblem], JSON_UNESCAPED_UNICODE); exit; }
company_ensure_coverage_column($pdo);
company_ensure_car_type($pdo);

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
    // ---- اکسلِ خودروها: فایلِ نمونه و پیش‌نمایشِ ردیف‌ها (همان ستون‌هایی که در تنظیماتِ «بیمه با ما» تعریف شده) ----
    if ($action === 'excel_sample') {
        require_once __DIR__ . '/finance_core.php';
        require_once __DIR__ . '/_xlsx_writer.php';
        $p = company_excel_sample_path();
        header_remove('Content-Type');
        xlsx_send($p, 'نمونه اکسل درخواست بیمه.xlsx');
        exit;
    }
    if ($action === 'preview_excel_rows') {
        require_once __DIR__ . '/finance_core.php';
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'error' => 'فایلِ اکسل را انتخاب کنید.']); exit; }
        $ext = upload_ext($f['name'] ?? '');
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) { echo json_encode(['ok' => false, 'error' => 'فقط فایلِ اکسل (xlsx) یا csv.']); exit; }
        $parsed = company_read_import_input([], $f);
        if (isset($parsed['error'])) { echo json_encode(['ok' => false, 'error' => $parsed['error']], JSON_UNESCAPED_UNICODE); exit; }
        $rows = array_map(function ($r) { return ['p1' => $r['plate_p1'], 'p2' => $r['plate_p2'], 'letter' => $r['plate_letter'], 'p4' => $r['plate_p4'], 'chassis_no' => $r['chassis_no'], 'engine_no' => $r['engine_no'],
            'is_new_vehicle' => $r['is_new_vehicle'], 'car_name' => $r['car_name'], 'car_type' => $r['car_type'] ?? null, 'car_value' => $r['car_value'], 'liability_limit' => $r['liability_limit'],
            'insurance_type' => $r['insurance_type'], 'expiry_j' => $r['expiry_date_jalali'] ? str_replace('.', '/', $r['expiry_date_jalali']) : '', 'ref_policy_number' => $r['ref_policy_number'],
            'endorsement_request' => $r['endorsement_request'], 'cancellation_reason' => $r['cancellation_reason'], 'note' => $r['row_note']]; }, $parsed['rows']);
        echo json_encode(['ok' => true, 'rows' => $rows, 'errors' => $parsed['errors']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- تکراری: هر ردیف (شاسی/موتور/پلاک + نوعِ بیمه) با بیمه‌نامه‌های صادرشده و درخواست‌های باز ----
    if ($action === 'dup_check') {
        require_once __DIR__ . '/_dup.php';
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : (json_decode((string)($data['rows'] ?? '[]'), true) ?: []);
        $out = [];
        foreach (array_slice($rows, 0, 300) as $i => $r) {
            $types = ($r['type'] ?? '') === 'BOTH' ? ['THIRDPARTY', 'BODY'] : [$r['type'] ?? 'THIRDPARTY'];
            $list = [];
            foreach ($types as $t) $list = array_merge($list, dup_find($pdo, ['vin' => $r['chassis_no'] ?? '', 'engine' => $r['engine_no'] ?? '', 'p1' => $r['p1'] ?? '', 'p2' => $r['p2'] ?? '',
                                                                              'letter' => $r['letter'] ?? '', 'p4' => $r['p4'] ?? '', 'type' => $t]));
            $out[$i] = dup_for_company($list, $allowedCompanyIds);
        }
        echo json_encode(['ok' => true, 'dups' => (object)$out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- صفر کیلومتر (فاکتور فروش): پیش‌فرض‌ها، اکسل، PDFِ فاکتورها و خواندنِ هر صفحه ----
    if ($action === 'zk_defaults') {
        $companyId = resolve_company_id($allowedCompanyIds, $data['company_id'] ?? null) ?: ($allowedCompanyIds[0] ?? 0);
        echo json_encode(['ok' => true, 'defaults' => company_last_request_defaults($pdo, $companyId), 'coverage_options' => body_coverage_options(),
                          'liability_options' => liability_limit_options()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'zk_parse_excel') {
        require_once __DIR__ . '/_zerokm.php';
        $f = $_FILES['file'] ?? null;
        $ext = upload_ext($f['name'] ?? '');
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !in_array($ext, ['xlsx', 'xls', 'csv'], true)) { echo json_encode(['ok' => false, 'error' => 'فایلِ اکسل (xlsx) یا csv را انتخاب کنید.']); exit; }
        $tmp = sys_get_temp_dir() . '/' . uniqid('zk_') . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $tmp)) { echo json_encode(['ok' => false, 'error' => 'فایل دریافت نشد.']); exit; }
        $r = zk_parse_excel($tmp);
        @unlink($tmp);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'zk_upload_pdf') {
        require_once __DIR__ . '/_zerokm.php';
        $r = pdf_split_job_create($pdo, dirname(__DIR__), $_FILES['file'] ?? [], zk_owner($session['company_user_id']));
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'zk_read_page') {
        require_once __DIR__ . '/_zerokm.php';
        session_write_close();
        $r = zk_read_page($pdo, dirname(__DIR__), (string)($data['token'] ?? ''), zk_owner($session['company_user_id']), intval($data['n'] ?? 0));
        $d = $r['data'] ?? [];
        echo json_encode(['ok' => !empty($r['ok']), 'error' => $r['error'] ?? null, 'kind' => $r['kind'] ?? null, 'kind_fa' => $r['kind_fa'] ?? null, 'status' => $r['status'] ?? null,
                          'missing' => $r['missing'] ?? [], 'data' => array_intersect_key($d, array_flip(['vin', 'engine_no', 'engine_raw', 'cab_no', 'owner_name', 'national_id', 'usage', 'car_system', 'car_tip', 'model_year', 'color', 'capacity', 'doc_no', 'doc_date', 'mobile', 'address']))], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'zk_page') {   // پیش‌نمایشِ یک صفحه‌ی فاکتور
        require_once __DIR__ . '/_zerokm.php';
        $p = pdf_split_job_page_path(dirname(__DIR__), (string)($_GET['token'] ?? ''), zk_owner($session['company_user_id']), intval($_GET['n'] ?? 0), ($_GET['kind'] ?? '') === 'png' ? 'png' : 'pdf');
        if (!$p) { http_response_code(404); exit; }
        header_remove('Content-Type');
        header('Content-Type: ' . (substr($p, -3) === 'png' ? 'image/png' : 'application/pdf'));
        header('Content-Disposition: inline; filename="page.' . substr($p, -3) . '"');
        readfile($p);
        exit;
    }

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
            $searchSql = " AND (cr.request_text LIKE ? OR c.name LIKE ? OR cr.id = ? OR cr.ref_code = ? OR EXISTS (
                              SELECT 1 FROM company_request_plates crp WHERE crp.request_id = cr.id AND (
                                  crp.chassis_no LIKE ? OR crp.car_name LIKE ? OR crp.policy_number LIKE ?
                                  OR CONCAT_WS(' ', crp.plate_p4, crp.plate_letter, crp.plate_p2, crp.plate_p1) LIKE ?)))";
            $searchParams = [$like, $like, intval(p2e_digits($q)), p2e_digits($q), $like, $like, '%' . search_text_norm($q) . '%', $like];   // شماره‌ی بیمه‌نامه با «∕»/رقمِ فارسی هم
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
            $r['requested_counts_fa'] = company_requested_counts_fa($r['requested_counts'] ?? null);
        }
        echo json_encode(['ok' => true, 'requests' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- جزئیات یک درخواست + مدارک و پلاک‌های آن (فقط اگر متعلق به یکی از شرکت‌های خودش باشد) ----
    if ($action === 'request_detail') {
        $requestId = intval($data['request_id'] ?? 0);
        companies_extra_ensure($pdo);
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM company_requests WHERE id = ? AND company_id IN ($placeholders)");
        $stmt->execute(array_merge([$requestId], $allowedCompanyIds));
        $request = $stmt->fetch();
        if (!$request) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }
        party_ensure($pdo);
        $partyCompany = party_company_row($pdo, $request['company_id']);
        $request['created_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($request['created_at']));
        $request['updated_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($request['updated_at']));
        $reqKind = $request['request_kind'] ?? 'NEW_POLICY';
        $request['request_kind_fa'] = company_request_kind_fa($reqKind);
        $request['requested_counts_fa'] = company_requested_counts_fa($request['requested_counts'] ?? null);

        // file_path هم لازم است، وگرنه لینکِ «مدارک ارسالی» در پنل شرکت به
        // ../undefined می‌خورد و کاربر نمی‌تواند فایلی که خودش فرستاده را ببیند
        company_doc_label_ensure($pdo);
        $stmt = $pdo->prepare("SELECT id, orig_name, file_path, file_kind, doc_type, doc_label, plate_id, status, uploaded_at FROM company_documents WHERE request_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$requestId]);
        $docs = $stmt->fetchAll();
        $siteRoot = dirname(__DIR__);
        foreach ($docs as &$d) {
            $d['doc_type_label'] = company_doc_display_label($d);
            $d['is_dir'] = is_dir($siteRoot . '/' . $d['file_path']);
            $d['uploaded_at_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($d['uploaded_at']));
        }
        unset($d);

        $stmt = $pdo->prepare("SELECT id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle,
                                       car_value, liability_limit, ref_policy_number, endorsement_request, cancellation_reason,
                                       insurance_type, status, expiry_date,
                                       skip_health_inspection, has_prev_body, car_name, car_type, row_note, policy_number, issued_at,
                                       issued_file_path, issued_file_path IS NOT NULL AS has_issued_file, selected_coverages, dup_of_source, dup_of_id,
                                       party_mode, party_json, issue_info, vehicle_kind
                                FROM company_request_plates WHERE request_id = ? ORDER BY id");
        require_once __DIR__ . '/_dup.php';
        dup_ensure($pdo);
        $stmt->execute([$requestId]);
        $plates = $stmt->fetchAll();
        // ردیفِ «تکراری»: بیمه‌نامه‌ی قبلیِ همین خودرو (اگر مالِ همین شرکت است، با دانلود)
        foreach ($plates as &$p) {
            $p['dup'] = null;
            if (!empty($p['dup_of_id'])) {
                $p['dup'] = ['source' => $p['dup_of_source']];
                if ($p['dup_of_source'] === 'C') {
                    $st2 = $pdo->prepare("SELECT crp.id, crp.policy_number, crp.issued_at, crp.end_date_j, crp.insurance_type, cr.company_id FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
                    $st2->execute([$p['dup_of_id']]);
                    $q2 = $st2->fetch();
                    if ($q2 && in_array(intval($q2['company_id']), $allowedCompanyIds, true))
                        $p['dup'] += ['plate_id' => intval($q2['id']), 'policy_number' => $q2['policy_number'], 'end_j' => $q2['end_date_j'], 'issued_j' => $q2['issued_at'] ? jalali_from_gregorian_ts_dotted(strtotime($q2['issued_at'])) : null];
                    elseif ($q2) $p['dup'] += ['end_j' => $q2['end_date_j']]; // بیمه‌نامه‌ی شرکت/شخصِ دیگر: فقط تاریخِ اعتبار
                }
            }
        }
        unset($p);
        // همان چک‌لیستی که ما در پنل خودمان می‌بینیم، عیناً برای ثبت‌کننده‌ی شرکت هم
        // نمایش داده می‌شود تا هر دو طرف بدانند هر ردیف چه کم دارد و خودش بتواند
        // همان‌جا آپلود کند (خواسته‌ی صریح کارفرما: «هم اون بتونه انجام بده هم من»)
        foreach ($plates as &$p) {
            $rowDocs = array_values(array_filter($docs, fn($d) => $d['plate_id'] == $p['id'] && $d['status'] === 'ASSIGNED'));
            $assignedTypes = array_values(array_filter(array_column($rowDocs, 'doc_type')));
            $p['plate_display'] = company_row_label($p);
            $p['coverages_fa'] = company_coverages_fa($p['selected_coverages'] ?? null);
            $p['status_fa'] = company_plate_status_fa($p['status'], $reqKind);
            $p['checklist'] = company_plate_checklist($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes, $p['has_prev_body'], $reqKind, !empty($p['is_new_vehicle']));
            foreach ($p['checklist'] as &$item) {
                $item['docs'] = array_values(array_map(
                    fn($d) => ['id' => $d['id'], 'file_path' => $d['file_path'], 'doc_type' => $d['doc_type'],
                               'label' => company_doc_display_label($d), 'is_dir' => !empty($d['is_dir'])],
                    array_filter($rowDocs, fn($d) => in_array($d['doc_type'], $item['upload_types'], true) || $d['doc_type'] === $item['key'])
                ));
            }
            unset($item);
            if (portal_company_opts($pdo, $request['company_id'])['custom']) $p['checklist'] = company_checklist_add_other($p['checklist'], $rowDocs);
            $p['present_labels'] = company_plate_present_labels($assignedTypes);
            $p['missing_docs'] = company_plate_missing_docs($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes, $p['has_prev_body'], $reqKind, !empty($p['is_new_vehicle']));
            $p['report_pending'] = company_plate_report_pending($p['insurance_type'], $assignedTypes, $reqKind);
            if ($p['report_pending'] && $p['status'] === 'READY_FOR_ISSUE') $p['status_fa'] = 'آماده‌ی صدور (گزارش بازدید)';
            // مالک / بیمه‌گذار / ذینفع (شرکت هم می‌تواند برای هر ردیف یا همه‌ی ردیف‌ها تعیین کند)
            $pr = party_resolve($p, $partyCompany, $request);
            $p['party'] = ['mode' => $pr['mode'], 'mode_fa' => $pr['mode_fa'], 'explicit' => $pr['explicit'], 'other' => $pr['other'],
                           'owner' => $pr['owner']['name'] ?? '', 'insured' => $pr['insured']['name'] ?? '', 'beneficiary' => $pr['beneficiary']['name'] ?? '', 'mismatch' => $pr['mismatch'],
                           'doc_owner' => $pr['doc_owner']['name'] ?? ''];
            unset($p['party_json'], $p['issue_info']);
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

        $request['party_default'] = ['mode' => $request['party_mode'] ?? null, 'party' => party_clean($request['party_json'] ?? null)];
        unset($request['party_json']);
        echo json_encode(['ok' => true, 'request' => $request, 'documents' => $docs, 'plates' => $plates, 'upload_opts' => portal_company_opts($pdo, $request['company_id']),
                          'counts' => $counts, 'doc_types' => company_doc_types(), 'party_modes' => PARTY_MODES,
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
        $docLabel = $docType === 'other' ? company_doc_label_clean($_POST['doc_label'] ?? '') : '';
        if ($docLabel !== '' && !portal_company_opts($pdo, $row['company_id'])['custom']) {
            $pdo->prepare("DELETE FROM company_documents WHERE id = ?")->execute([$docId]); @unlink($siteRoot . '/' . $relPath);
            echo json_encode(['ok' => false, 'error' => 'افزودنِ «مدرکِ دیگر» با نامِ دلخواه برای این شرکت فعال نیست.'], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($docLabel !== '') { company_doc_label_ensure($pdo); $pdo->prepare("UPDATE company_documents SET doc_label = ? WHERE id = ?")->execute([$docLabel, $docId]); }

        $newRel = company_place_document($pdo, $siteRoot, $docId, $plateId, $docType);
        $rowStatus = company_sync_plate_status($pdo, $plateId);
        echo json_encode(['ok' => true, 'doc_id' => $docId, 'file_path' => $newRel, 'row_status' => $rowStatus], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- مالک / بیمه‌گذار / ذینفع: شرکت برای یک ردیف یا همه‌ی ردیف‌های درخواستش تعیین می‌کند
    //      (ردیفی که صادر شده یا در حالِ صدور است دیگر عوض نمی‌شود؛ باید به کارشناس پیام بدهند) ----
    if ($action === 'set_party') {
        party_ensure($pdo);
        $mode = party_valid_mode($data['mode'] ?? '');
        if (!$mode) { echo json_encode(['ok' => false, 'error' => 'نوعِ مالکیت را انتخاب کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        $requestId = intval($data['request_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $st = $pdo->prepare("SELECT id FROM company_requests WHERE id = ? AND company_id IN ($placeholders)");
        $st->execute(array_merge([$requestId], $allowedCompanyIds));
        if (!$st->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }
        $party = !empty($data['keep_party']) ? false : ($data['party'] ?? null);
        $st = $pdo->prepare("SELECT id, status FROM company_request_plates WHERE request_id = ?");
        $st->execute([$requestId]);
        $want = !empty($data['all']) ? null : array_map('intval', (array)($data['plate_ids'] ?? []));
        $ids = []; $locked = 0;
        foreach ($st->fetchAll() as $r) {
            if ($want !== null && !in_array(intval($r['id']), $want, true)) continue;
            if (in_array($r['status'], ['ISSUED', 'IN_ISSUANCE'], true)) { $locked++; continue; }
            $ids[] = intval($r['id']);
        }
        if (!empty($data['all'])) party_set_request_default($pdo, $requestId, $mode, $party === false ? null : $party);
        $n = party_set_rows($pdo, $ids, $mode, $party);
        echo json_encode(['ok' => true, 'updated' => $n, 'locked' => $locked], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- فیدِ اعلان‌های پنل شرکت: پیام تازه از ما، و بیمه‌نامه‌ی تازه صادرشده ----
    if ($action === 'notifications_feed') {
        $since = trim($data['since'] ?? '');
        $ph = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        // شمارِ پیام‌های خوانده‌نشده‌ی ما (برای عددِ روی آیکن گفتگو) - در هر پاسخ، حتی بارِ اول
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company_chat_messages WHERE sender_type = 'ADMIN' AND is_read = 0 AND company_id IN ($ph)");
        $stmt->execute($allowedCompanyIds);
        $unreadChat = intval($stmt->fetchColumn());

        // رویدادهای بازه‌ی (since, until] برای شرکت‌های همین کاربر
        $collect = function ($since, $until) use ($pdo, $ph, $allowedCompanyIds) {
            $events = [];
            $stmt = $pdo->prepare("SELECT message, created_at FROM company_chat_messages
                                    WHERE created_at > ? AND created_at <= ? AND sender_type = 'ADMIN' AND company_id IN ($ph)
                                    ORDER BY created_at DESC LIMIT 20");
            $stmt->execute(array_merge([$since, $until], $allowedCompanyIds));
            foreach ($stmt->fetchAll() as $r) {
                $events[] = ['type' => 'chat', 'title' => 'پیام جدید از بیمه با ما',
                             'body' => mb_substr((string)($r['message'] ?: 'یک فایل فرستاد'), 0, 90), 'at' => $r['created_at']];
            }
            $stmt = $pdo->prepare("SELECT crp.policy_number, crp.insurance_type, crp.issued_at,
                                          crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no
                                     FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                                    WHERE crp.issued_at > ? AND crp.issued_at <= ? AND cr.company_id IN ($ph)
                                    ORDER BY crp.issued_at DESC LIMIT 20");
            $stmt->execute(array_merge([$since, $until], $allowedCompanyIds));
            foreach ($stmt->fetchAll() as $r) {
                $events[] = ['type' => 'issued', 'title' => 'بیمه‌نامه صادر شد',
                             'body' => insurance_type_fa($r['insurance_type']) . ' ' . company_row_label($r)
                                       . ($r['policy_number'] ? ' - شماره ' . $r['policy_number'] : ''),
                             'at' => $r['issued_at']];
            }
            // نامه‌های تازه در «اتوماسیون نامه‌ها»
            require_once __DIR__ . '/_lt.php';
            foreach (lt_portal_events($pdo, $since, $until, array_map('intval', $allowedCompanyIds)) as $e) $events[] = $e;
            usort($events, fn($a, $b) => strcmp($a['at'], $b['at']));
            return $events;
        };

        // حالتِ ماندگار (مایگریشن ۰۱۲): فهرستِ اعلان‌ها در دیتابیس، مشترک بین مرورگرهای همین کاربر
        if (notif_tables_ready($pdo)) {
            $uid = intval($session['company_user_id']);
            $act = $data['notif_action'] ?? '';
            if ($act !== '') notif_apply_action($pdo, 'PORTAL', $uid, $act, $data['id'] ?? 0);
            notif_sync($pdo, 'PORTAL', $uid, $collect);
            $afterId = array_key_exists('after_id', $data) ? $data['after_id'] : null;
            echo json_encode(['ok' => true, 'persisted' => true, 'unread_chat' => $unreadChat]
                             + notif_list($pdo, 'PORTAL', $uid, $afterId), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // حالتِ قدیمی (مایگریشن ۰۱۲ هنوز اجرا نشده)
        if ($since === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            echo json_encode(['ok' => true, 'now' => date('Y-m-d H:i:s'), 'events' => [], 'unread_chat' => $unreadChat], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $now = date('Y-m-d H:i:s');
        echo json_encode(['ok' => true, 'now' => $now, 'events' => array_slice($collect($since, $now), -25),
                          'unread_chat' => $unreadChat], JSON_UNESCAPED_UNICODE);
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
        $ext = upload_ext($abs) ?: 'pdf';
        $name = sanitize_folder_name(name_join([insurance_type_fa($row['insurance_type']), plate_for_filename($plateDisplay) ?: 'بدون پلاک',
                 policy_number_for_filename($row['policy_number'])])) . '.' . $ext;
        header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/octet-stream'));
        header(dl_disposition('attachment', $name, 'policy'));
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
        exit;
    }

    // ---- دانلودِ دسته‌جمعیِ همه‌ی بیمه‌نامه‌های صادرشده‌ی یک درخواست (تا همین لحظه) ----
    // فقط خودِ فایل‌های بیمه‌نامه داخل زیپ می‌روند (نه مدارک). زیپ در پوشه‌ی موقتِ سیستم
    // ساخته می‌شود و بلافاصله بعد از فرستادن پاک می‌شود - در بایگانی ما چیزی نمی‌ماند.
    // ---- دانلودِ گروهیِ بیمه‌نامه‌ها از چند درخواست (یا همه): هر درخواست یک پوشه داخلِ ZIP ----
    if (($_GET['action'] ?? '') === 'download_issued_multi') {
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $idsIn = (string)($_GET['ids'] ?? '');
        $ids = $idsIn === 'all' ? [] : array_values(array_filter(array_map('intval', explode(',', $idsIn))));
        if ($idsIn !== 'all' && !$ids) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'درخواستی انتخاب نشده.'], JSON_UNESCAPED_UNICODE); exit; }
        $sql = "SELECT crp.issued_file_path, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no, crp.policy_number, crp.insurance_type, cr.id AS rid, cr.ref_code
                  FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                 WHERE cr.company_id IN ($placeholders) AND crp.status = 'ISSUED' AND crp.issued_file_path IS NOT NULL"
             . ($ids ? " AND cr.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")" : '') . " ORDER BY cr.id, crp.id LIMIT 2000";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($allowedCompanyIds, $ids));
        $siteRoot = dirname(__DIR__);
        $files = [];
        foreach ($stmt->fetchAll() as $row) {
            $abs = $siteRoot . '/' . $row['issued_file_path'];
            if (!is_file($abs)) continue;
            $ext = upload_ext($abs) ?: 'pdf';
            $dir = sanitize_folder_name('درخواست ' . ($row['ref_code'] ?: $row['rid']));
            $base = sanitize_folder_name(name_join([insurance_type_fa($row['insurance_type']), plate_for_filename(company_row_label($row)), policy_number_for_filename($row['policy_number'])]));
            $name = $dir . '/' . $base . '.' . $ext;
            for ($n = 2; isset($files[$name]); $n++) $name = $dir . '/' . $base . " ($n)." . $ext;
            $files[$name] = $abs;
        }
        if (!$files) { http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<meta charset="utf-8"><body dir="rtl" style="font-family:tahoma;padding:40px">بیمه‌نامه‌ی صادرشده‌ای برای دانلود نیست.</body>'; exit; }
        if (!class_exists('ZipArchive')) { echo json_encode(['ok' => false, 'error' => 'ساخت زیپ روی سرور ممکن نیست.'], JSON_UNESCAPED_UNICODE); exit; }
        @set_time_limit(300);
        $tmp = tempnam(sys_get_temp_dir(), 'pzip_');
        register_shutdown_function(function () use ($tmp) { if (is_file($tmp)) @unlink($tmp); });
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $used = [];
        foreach ($files as $name => $abs) $zip->addFile($abs, dl_zip_path($name, $used));
        $zip->close();
        $zipName = str_replace(['"', '/', '\\'], ['', '-', '-'], 'بیمه‌نامه‌های صادره - ' . count($files) . ' فایل - ' . jalali_from_gregorian_ts_dotted(time()) . '.zip');
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"policies.zip\"; filename*=UTF-8''" . rawurlencode(dl_name($zipName)));
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    if (($action === 'download_issued_zip') || ($_GET['action'] ?? '') === 'download_issued_zip') {
        $requestId = intval($data['request_id'] ?? ($_GET['request_id'] ?? 0));
        $placeholders = implode(',', array_fill(0, count($allowedCompanyIds), '?'));
        $stmt = $pdo->prepare("SELECT cr.id, cr.created_at, c.name AS company_name FROM company_requests cr
                                 JOIN companies c ON c.id = cr.company_id
                                WHERE cr.id = ? AND cr.company_id IN ($placeholders)");
        $stmt->execute(array_merge([$requestId], $allowedCompanyIds));
        $req = $stmt->fetch();
        if (!$req) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $pdo->prepare("SELECT issued_file_path, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, policy_number, insurance_type
                                 FROM company_request_plates WHERE request_id = ? AND status = 'ISSUED' AND issued_file_path IS NOT NULL ORDER BY id");
        $stmt->execute([$requestId]);
        $siteRoot = dirname(__DIR__);
        $files = [];
        foreach ($stmt->fetchAll() as $row) {
            $abs = $siteRoot . '/' . $row['issued_file_path'];
            if (!is_file($abs)) continue;
            $ext = upload_ext($abs) ?: 'pdf';
            $base = sanitize_folder_name(name_join([insurance_type_fa($row['insurance_type']), plate_for_filename(company_row_label($row)),
                     policy_number_for_filename($row['policy_number'])]));
            $name = $base . '.' . $ext;
            for ($n = 2; isset($files[$name]); $n++) $name = $base . " ($n)." . $ext;
            $files[$name] = $abs;
        }
        if (!$files) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'هنوز بیمه‌نامه‌ی صادرشده‌ای برای این درخواست نیست.'], JSON_UNESCAPED_UNICODE); exit; }
        if (!class_exists('ZipArchive')) { echo json_encode(['ok' => false, 'error' => 'ساخت زیپ روی سرور ممکن نیست.'], JSON_UNESCAPED_UNICODE); exit; }

        $tmp = tempnam(sys_get_temp_dir(), 'pzip_');
        register_shutdown_function(function () use ($tmp) { if (is_file($tmp)) @unlink($tmp); }); // حتی اگر دانلود نیمه‌کاره قطع شد
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $used = [];   // نام‌های داخلِ ZIP (برای جلوگیری از نامِ تکراری)
        foreach ($files as $name => $abs) $zip->addFile($abs, dl_zip_path($name, $used));
        $zip->close();

        $zipName = 'بیمه‌نامه‌های صادره - ' . $req['company_name'] . ' - درخواست ' . $req['id'] . ' - '
                 . jalali_from_gregorian_ts_dotted(time()) . '.zip';
        $zipName = str_replace(['"', '/', '\\'], ['', '-', '-'], $zipName);
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"policies.zip\"; filename*=UTF-8''" . rawurlencode(dl_name($zipName)));
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
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

        // نوعِ درخواست: «صدور بیمه‌نامه‌ی جدید» یا «الحاقیه». الحاقیه خودش دو جور است -
        // تغییر اطلاعات بیمه‌نامه یا فسخ - و ثبت‌کننده تعدادِ هرکدام را می‌گوید. نوعی که روی
        // درخواست ذخیره می‌شود از همین تعدادها (و اگر نبود، از ردیف‌ها) درمی‌آید.
        $kind = company_valid_kind($data['request_kind'] ?? 'NEW_POLICY');
        $counts = company_normalize_requested_counts($data['requested_counts'] ?? null);
        if ($kind !== 'NEW_POLICY') {
            // فقط تعدادهای مربوط به الحاقیه معنا دارند
            if ($counts) { $counts['THIRDPARTY'] = 0; $counts['BODY'] = 0; $counts = array_sum($counts) ? $counts : null; }
            $kind = company_kind_from_counts($counts, '');
            if ($kind === '') {
                $rowsIn = json_decode($data['plates'] ?? '[]', true) ?: [];
                $hasEndorse = (bool)array_filter($rowsIn, fn($r) => trim($r['endorsement_request'] ?? '') !== '');
                $hasCancel  = (bool)array_filter($rowsIn, fn($r) => trim($r['cancellation_reason'] ?? '') !== '');
                $kind = (!$hasEndorse && $hasCancel) ? 'CANCELLATION' : 'ENDORSEMENT';
            }
        } elseif ($counts) {
            $counts['ENDORSEMENT'] = 0; $counts['CANCELLATION'] = 0; $counts = array_sum($counts) ? $counts : null;
        }
        // درخواستِ تکراری ثبت نمی‌شود: خودرویی که برای همین نوعِ بیمه بیمه‌نامه‌ی فعال یا درخواستِ در جریان دارد
        if ($kind === 'NEW_POLICY') {
            require_once __DIR__ . '/_dup.php';
            $dupMsgs = [];
            foreach ((json_decode($data['plates'] ?? '[]', true) ?: []) as $p) {
                $t = in_array($p['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $p['insurance_type'] : null;
                if (!$t) continue;
                $hits = array_filter(dup_find($pdo, ['vin' => $p['chassis_no'] ?? '', 'engine' => $p['engine_no'] ?? '', 'p1' => $p['p1'] ?? '', 'p2' => $p['p2'] ?? '', 'letter' => $p['letter'] ?? '', 'p4' => $p['p4'] ?? '', 'type' => $t]),
                                     function ($m) { return $m['state'] !== 'history'; });
                if ($hits) {
                    $lab = trim(($p['p2'] ?? '') . ' ' . ($p['letter'] ?? '') . ' ' . ($p['p4'] ?? '') . ' ' . ($p['p1'] ?? '')) ?: ($p['chassis_no'] ?? '') ?: ($p['engine_no'] ?? '');
                    $m = array_values($hits)[0];
                    $dupMsgs[] = '«' . $lab . '» (' . ($t === 'BODY' ? 'بدنه' : 'ثالث') . '): ' . ($m['state'] === 'open' ? 'درخواستِ دیگری برای همین خودرو در جریان است' : 'بیمه‌نامه‌ی فعال دارد' . ($m['end_j'] ? ' تا ' . strtr($m['end_j'], ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']) : ''));
                }
            }
            if ($dupMsgs) { echo json_encode(['ok' => false, 'dup' => true, 'error' => "این خودروها تکراری‌اند و ثبت نشدند:\n" . implode("\n", array_slice($dupMsgs, 0, 15)) . (count($dupMsgs) > 15 ? "\n…" : '') . "\nآن‌ها را از فهرست حذف کنید."], JSON_UNESCAPED_UNICODE); exit; }
        }
        $stmt = $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, request_kind, requested_counts, status) VALUES (?, ?, ?, ?, ?, ?, 'NEW')");
        $stmt->execute([$companyId, $session['company_user_id'], $requestText, $insurer, $kind,
                        $counts ? json_encode($counts) : null]);
        $requestId = $pdo->lastInsertId();
        ref_code_of($pdo, 'company_requests', $requestId);
        // «مالک / بیمه‌گذار» کلیِ این درخواست (پیش‌فرضِ همه‌ی ردیف‌ها)
        party_ensure($pdo);
        $partyDefMode = party_valid_mode($data['party_mode'] ?? '');
        $partyDef = is_string($data['party'] ?? null) ? json_decode($data['party'], true) : ($data['party'] ?? null);
        if ($partyDefMode) party_set_request_default($pdo, $requestId, $partyDefMode, $partyDef);

        // پلاک‌های اولیه‌ی این درخواست (اختیاری، چند تا مجاز؛ برای هر پلاک هم نوع بیمه‌ی
        // درخواستی‌اش - ثالث/بدنه - مشخص می‌شود؛ اگر «هردو» خواسته شده بود، سمتِ کلاینت
        // از قبل آن را به دو ردیفِ جدا تبدیل کرده است)
        $plates = json_decode($data['plates'] ?? '[]', true);
        if (is_array($plates)) {
            // ردیف می‌تواند با پلاک باشد یا - برای لیفتراک و خودروی صفرکیلومتر - فقط با
            // شماره شاسی. ارزش خودرو (بدنه) و سقف تعهد مالی (ثالث) هم همین‌جا گرفته می‌شود.
            company_ensure_car_type($pdo);
            $insPlate = $pdo->prepare("INSERT INTO company_request_plates
                (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle,
                 car_value, liability_limit, ref_policy_number, endorsement_request, cancellation_reason,
                 insurance_type, skip_health_inspection, car_name, car_type, expiry_date, row_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $zkToken = preg_replace('/[^a-f0-9]/', '', (string)($data['zk_token'] ?? ''));
            $zkUsed = [];
            foreach ($plates as $p) {
                // صفر کیلومتر از فاکتور: شماره موتور و شاسی بدونِ . * - و فاصله
                if (!empty($p['zk'])) { $p['chassis_no'] = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(p2e_digits((string)($p['chassis_no'] ?? '')))); $p['engine_no'] = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(p2e_digits((string)($p['engine_no'] ?? '')))); }
                $pp1 = trim($p['p1'] ?? ''); $pp2 = trim($p['p2'] ?? '');
                $pletter = trim($p['letter'] ?? ''); $pp4 = trim($p['p4'] ?? '');
                $chassis = trim($p['chassis_no'] ?? '');
                if ($pp1 === '' && $pp2 === '' && $pletter === '' && $pp4 === '' && $chassis === '' && trim((string)($p['engine_no'] ?? '')) === '') continue;
                $pInsType = in_array($p['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $p['insurance_type'] : null;
                $isNew = !empty($p['is_new_vehicle']) ? 1 : 0;
                $pExp = null;
                if (!$isNew && trim((string)($p['expiry_j'] ?? '')) !== '') { require_once __DIR__ . '/finance_core.php'; $pExp = fin_jalali_to_date(p2e_digits(trim($p['expiry_j']))) ?: null; }
                $insPlate->execute([$requestId, $pp1 ?: null, $pp2 ?: null, $pletter ?: null, $pp4 ?: null,
                                    $chassis ?: null, trim($p['engine_no'] ?? '') ?: null, $isNew,
                                    company_parse_money($p['car_value'] ?? ''), company_parse_money($p['liability_limit'] ?? ''),
                                    trim($p['ref_policy_number'] ?? '') ?: null,
                                    trim($p['endorsement_request'] ?? '') ?: null,
                                    trim($p['cancellation_reason'] ?? '') ?: null,
                                    $pInsType, $isNew ? 1 : 0, mb_substr(trim((string)($p['car_name'] ?? '')), 0, 190) ?: null, mb_substr(trim((string)($p['car_type'] ?? '')), 0, 120) ?: null,
                                    $pExp, mb_substr(trim((string)($p['note'] ?? '')), 0, 500) ?: null]);
                $newPlateId = intval($pdo->lastInsertId());
                // مالک / بیمه‌گذار / ذینفع: جدا برای همین ردیف یا طبقِ انتخابِ کلیِ فرم
                party_apply_from_input($pdo, $newPlateId, ['party_mode' => $p['party_mode'] ?? '', 'party' => $p['party'] ?? null], $partyDefMode, $partyDef);
                company_set_vehicle_kind($pdo, $newPlateId, $p['vehicle_kind'] ?? '');   // موتورسیکلت
                // پوشش‌های بدنه‌ی همین ردیف (جزئی) یا پیش‌فرضِ کلیِ فرم
                if ($pInsType === 'BODY' && array_key_exists('coverages', $p) && $p['coverages'] !== null && $p['coverages'] !== '') {
                    $cov = company_clean_coverages($p['coverages']);
                    if ($cov !== null) $pdo->prepare("UPDATE company_request_plates SET selected_coverages = ? WHERE id = ?")->execute([$cov, $newPlateId]);
                }
                // تعهد/پوششی که فرم نداده: پیش‌فرضِ تعریف‌شده برای همین شرکت
                if ($kind === 'NEW_POLICY') company_apply_defaults_to_rows($pdo, $requestId, [$newPlateId]);
                if ($zkToken && !empty($p['zk_page'])) {
                    require_once __DIR__ . '/_zerokm.php';
                    $n = intval($p['zk_page']);
                    zk_attach_page($pdo, dirname(__DIR__), $zkToken, zk_owner($session['company_user_id']), $n, $newPlateId, $companyId, $requestId, $session['company_user_id'],
                                   ['owner_name' => trim((string)($p['owner_name'] ?? '')), 'national_id' => trim((string)($p['owner_national_id'] ?? ''))]);
                    $zkUsed[$n] = true;
                } elseif (!empty($p['zk'])) {
                    require_once __DIR__ . '/_zerokm.php';
                    vdoc_apply_to_row($pdo, $newPlateId, ['owner_name' => trim((string)($p['owner_name'] ?? '')), 'national_id' => trim((string)($p['owner_national_id'] ?? ''))]);
                }
            }
            // فاکتورهایی که با هیچ خودرویی تطبیق نخوردند => صندوقِ مدارک (با نتیجه‌ی شناسایی) برای بررسیِ کارشناس
            if ($zkToken) {
                require_once __DIR__ . '/_zerokm.php';
                $job = pdf_split_job_info(dirname(__DIR__), $zkToken, zk_owner($session['company_user_id']));
                if ($job) for ($n = 1; $n <= intval($job['pages']); $n++) {
                    if (empty($zkUsed[$n])) zk_attach_page($pdo, dirname(__DIR__), $zkToken, zk_owner($session['company_user_id']), $n, null, $companyId, $requestId, $session['company_user_id']);
                }
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

        cbot_notify_staff($pdo, ['ADMIN', 'COMPANY_LIAISON'], "🆕 درخواست جدید از «" . ($companyRow['name'] ?? '') . "» (#" . $requestId . ")",
            ['inline_keyboard' => [[['text' => 'مشاهده', 'callback_data' => 'lreq:' . $requestId]]]]);
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
        if ($suggestedType !== 'letter' && !portal_company_opts($pdo, $companyId)['general']) {
            @unlink($saved['path']);
            echo json_encode(['ok' => false, 'error' => 'برای این شرکت، مدارک فقط از چک‌لیستِ هر خودرو (بعد از ساختِ ردیف‌ها) بارگذاری می‌شود.'], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($suggestedType === 'mixed') $suggestedType = null;   // «چند مدرک در یک PDF»: نوعِ هر صفحه را کارشناس تعیین می‌کند
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
            $ext = upload_ext($saved['path']) ?: 'pdf';
            $placed = company_place_letter($pdo, $siteRoot, $requestId, $saved['path'], $ext);
            if ($placed) {
                $relPath = $placed;
                $pdo->prepare("UPDATE company_documents SET file_path = ? WHERE id = ?")->execute([$relPath, $docId]);
            }
            $pdo->prepare("UPDATE company_requests SET letter_file_path = ? WHERE id = ?")->execute([$relPath, $requestId]);
        }
        // PDFِ چندصفحه‌ای (چند مدرک در یک فایل): هر صفحه یک مدرکِ جدا می‌شود تا جدا بررسی و نوع‌گذاری شود
        $pages = 1;
        if (!$isLetter) {
            require_once __DIR__ . '/_pdf_split.php';
            $sp = company_split_document($pdo, $siteRoot, $docId);
            if ($sp && !empty($sp['ok'])) $pages = intval($sp['pages']);
            // شناساییِ خودکارِ هر صفحه (فاکتور فروش، سند، کارت، بیمه‌نامه‌ی قبلی) => ردیف یا صندوقِ مدارک
            require_once __DIR__ . '/_vdoc.php';
            vdoc_queue($pdo, $sp && !empty($sp['ok']) ? $sp['ids'] : [$docId]);
        }
        cbot_notify_staff($pdo, ['ADMIN', 'COMPANY_LIAISON'], "📎 مدرکِ تازه از «{$companyName}» برای درخواست #{$requestId}: " . ($isLetter ? 'نامه‌ی درخواست' : company_doc_type_label($suggestedType))
            . ($pages > 1 ? " (PDFِ {$pages} صفحه‌ای؛ هر صفحه جدا برای تگ‌گذاری)" : ''),
            ['inline_keyboard' => [[['text' => 'مشاهده', 'callback_data' => 'lreq:' . $requestId]]]]);

        echo json_encode(['ok' => true, 'pages' => $pages, 'ai_queued' => !$isLetter]);
        exit;
    }

    // ---- شناساییِ خودکارِ مدارکِ آپلودشده: هر بار یک صفحه (پنل شرکت تا تمام شدن تکرار می‌کند) ----
    if ($action === 'doc_ai_step') {
        require_once __DIR__ . '/_vdoc.php';
        if (function_exists('sess_release')) sess_release(); else session_write_close();
        $r = vdoc_step($pdo, dirname(__DIR__), $allowedCompanyIds, 1);
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- پیام‌رسانِ جدید (همان هسته‌ی پنل): گفتگوی شرکت با «بیمه با ما» ----
    if (in_array($action, ['chat_open', 'chat_poll', 'chat_send', 'chat_edit', 'chat_delete', 'chat_set_ref', 'chat_unread', 'chat_ping', 'chat_offline', 'chat_me', 'chat_set_avatar', 'chat_hide', 'chat_clear', 'chat_remove', 'chat_typing', 'chat_react'], true)) {
        require_once __DIR__ . '/_chat_core.php';
        $in = $data;
        if (isset($in['p']) && is_string($in['p'])) { $dec = json_decode((string)base64_decode($in['p'], true), true); if (is_array($dec)) $in = $dec + $in; }
        $actor = ['kind' => 'COMPANY', 'id' => intval($session['company_user_id']), 'company_ids' => $allowedCompanyIds, 'name' => $session['full_name'] ?? 'کاربر شرکت'];
        if (!in_array($action, ['chat_unread', 'chat_ping', 'chat_offline', 'chat_me', 'chat_set_avatar'], true)) {
            $companyId = resolve_company_id($allowedCompanyIds, $in['company_id'] ?? null);
            if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت انتخاب‌شده معتبر نیست.']); exit; }
            $in['key'] = 'C:' . intval($companyId);
        }
        echo json_encode(chat_dispatch($pdo, $actor, $action, $in, $_FILES), JSON_UNESCAPED_UNICODE);
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
        require_once __DIR__ . '/_chat_state.php';
        if (!chat_company_can_send($pdo, $companyId)) { echo json_encode(['ok' => false, 'error' => CHAT_CLOSED_CLIENT_MSG], JSON_UNESCAPED_UNICODE); exit; }

        $pdo->prepare("INSERT INTO company_chat_messages (company_id, sender_type, sender_portal_user_id, message, file_path) VALUES (?, 'COMPANY', ?, ?, ?)")
            ->execute([$companyId, $session['company_user_id'], $message ?: null, $filePath]);
        $stCn = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $stCn->execute([$companyId]);
        cbot_notify_staff($pdo, ['ADMIN', 'COMPANY_LIAISON'], "💬 پیام تازه از «" . $stCn->fetchColumn() . "» ({$session['full_name']}):\n" . ($message ?: '📎 یک فایل فرستاد'),
            ['inline_keyboard' => [[['text' => '↩️ پاسخ', 'callback_data' => 'lchat:' . $companyId]]]]);
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
            // پیام فقط از دیدِ دو طرف پنهان می‌شود؛ مدیر کل در بایگانیِ گفتگوها آن را با نامِ حذف‌کننده می‌بیند
            require_once __DIR__ . '/_chat_access.php';
            chat_access_ensure($pdo);
            $pdo->prepare("UPDATE company_chat_messages SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")
                ->execute([mb_substr(($session['full_name'] ?? 'کاربر شرکت') . ' (کاربر شرکت)', 0, 160), $messageId]);
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

// پنلِ شرکت: «مدارکِ کلی» و «مدرکِ دیگر با نامِ دلخواه» برای این شرکت مجاز است؟ (تنظیماتِ شرکت در پنلِ ما؛ پیش‌فرض هر دو بله)
function portal_company_opts($pdo, $companyId) {
    static $c = [];
    $companyId = intval($companyId);
    if (isset($c[$companyId])) return $c[$companyId];
    $o = ['general' => true, 'custom' => true];
    try {
        companies_extra_ensure($pdo);
        $st = $pdo->prepare("SELECT portal_general_docs, portal_custom_docs FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        if ($r = $st->fetch()) $o = ['general' => (int)$r['portal_general_docs'] !== 0, 'custom' => (int)$r['portal_custom_docs'] !== 0];
    } catch (Throwable $e) {}
    return $c[$companyId] = $o;
}
