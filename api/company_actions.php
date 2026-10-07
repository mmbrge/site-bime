<?php
// فایل: api/company_actions.php
// اکشن‌های پنل ادمین/همکار برای ماژول «شرکت‌ها»: مدیریت شرکت‌ها و کاربرانشان،
// درخواست‌ها، صندوق ورودی مدارک (تگ‌گذاری دستی)، و ثبت صدور نهایی.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_company_issue.php';
require __DIR__ . '/finance_core.php'; // فقط برای fin_split_installments (تابعی محض، بدون وابستگی)

$actor = require_admin_or_liaison();
ref_codes_ensure($pdo);   // شناسه‌ی ۵ رقمیِ درخواست‌ها (ستون و مقدارِ ردیف‌های قبلی خودکار)

// اگر مایگریشنِ لازم اجرا نشده باشد، به‌جای «خطای سرور» پیامِ روشن بده
$schemaProblem = company_schema_problem($pdo);
if ($schemaProblem) { echo json_encode(['ok' => false, 'error' => $schemaProblem], JSON_UNESCAPED_UNICODE); exit; }

// ستونِ «پوشش‌های درخواستی» هر ردیف (بدنه) خودکار ساخته می‌شود؛ نیازی به اجرای SQL دستی نیست
company_ensure_coverage_column($pdo);
company_ensure_car_type($pdo);
imp_ensure($pdo);   // «بایگانی وارداتی»: ستون‌های is_import و ... (خودکار)

// اطمینان از وجود پوشه‌ی ریشه‌ی «بایگانی شرکتی» تا همیشه در بایگانی فایل‌ها دیده شود
@mkdir(company_archive_root(dirname(__DIR__)), 0775, true);

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

// فایلی بزرگ‌تر از post_max_size کلِ فرم را خالی می‌کند (حتی action)؛ به‌جای «اکشن نامعتبر» علتِ واقعی گفته شود
if ($action === '' && !$isJson && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST) && empty($_FILES)) {
    $lim = ini_get('post_max_size');
    echo json_encode(['ok' => false, 'error' => 'حجمِ فایل از سقفِ مجازِ سرور (post_max_size = ' . $lim . ') بیشتر است. در cPanel ← Select PHP Version ← Options مقدارِ upload_max_filesize و post_max_size را بیشتر کنید (مثلاً 64M).'], JSON_UNESCAPED_UNICODE);
    exit;
}

function jd($ts) { return $ts ? jalali_from_gregorian_ts_dotted($ts) : null; }

// ثبت دستی و ویرایشِ درخواست‌ها (و ردیف‌ها و صدورشان) فقط کارِ مدیر کل است؛ همکار بیمه با ما فقط می‌بیند
$adminOnly = ['admin_create_request', 'admin_create_full_request', 'set_row_coverages', 'edit_request', 'delete_request', 'admin_add_plate', 'admin_upload_plate_doc', 'upload_combined_pdf', 'update_row',
              'delete_row', 'delete_row_doc', 'set_row_stage', 'preview_letter_rows', 'import_letter_rows', 'mark_issued', 'retry_folder_transfer',
              'create_company', 'update_company', 'delete_company', 'create_portal_user', 'update_portal_user', 'delete_portal_user', 'excel_map_save', 'excel_headers'];
if (in_array($action, $adminOnly, true) && ($actor['role'] ?? '') !== 'ADMIN') {
    echo json_encode(['ok' => false, 'error' => 'ثبت و ویرایش درخواست فقط برای مدیر کل مجاز است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ---- اکسلِ درخواستِ شرکت‌ها: فایلِ نمونه، ستون‌های قابلِ تنظیم و یادگرفتن از فایلِ یک شرکت ----
    if ($action === 'excel_sample') {
        require_once __DIR__ . '/_xlsx_writer.php';
        xlsx_send(company_excel_sample_path(), 'نمونه اکسل درخواست بیمه شرکت‌ها.xlsx');
        exit;
    }
    if ($action === 'excel_map_get') {
        $base = company_excel_column_map_base();
        $custom = company_excel_custom_map();
        $out = [];
        foreach (company_excel_field_labels() as $f => $label) $out[] = ['field' => $f, 'label' => $label, 'builtin' => $base[$f] ?? [], 'custom' => array_values((array)($custom[$f] ?? []))];
        echo json_encode(['ok' => true, 'fields' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'excel_map_save') {
        $labels = company_excel_field_labels();
        $clean = [];
        foreach ((array)($data['map'] ?? []) as $f => $names) {
            if (!isset($labels[$f])) continue;
            $v = array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 80); }, is_array($names) ? $names : preg_split('/[،,\n]+/u', (string)$names)), 'strlen')));
            if ($v) $clean[$f] = array_slice($v, 0, 30);
        }
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('company_excel_map', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([json_encode((object)$clean, JSON_UNESCAPED_UNICODE)]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'excel_headers') {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { echo json_encode(['ok' => false, 'error' => 'فایلِ اکسل را انتخاب کنید.']); exit; }
        $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) { echo json_encode(['ok' => false, 'error' => 'فقط xlsx یا csv.']); exit; }
        $tmp = sys_get_temp_dir() . '/' . uniqid('chdr_') . '.' . $ext;
        @copy($f['tmp_name'], $tmp);
        $r = company_excel_headers($tmp);
        @unlink($tmp);
        if (isset($r['error'])) { echo json_encode(['ok' => false, 'error' => $r['error']], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['ok' => true, 'headers' => $r['headers'], 'labels' => company_excel_field_labels()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- گزارش مالی خلاصه‌ی شرکت‌های درخواست‌کننده (فقط خواندنی - برای ADMIN و همکار) ----
    if ($action === 'finance_summary') {
        $stmt = $pdo->query("
            SELECT c.id, c.name,
                   COALESCE((SELECT SUM(i.total_amount) FROM invoices i WHERE i.company_id = c.id), 0) AS total_invoiced,
                   COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.company_id = c.id), 0) AS total_paid
            FROM companies c
            WHERE c.kind IN ('INSURANCE_CLIENT','BOTH') AND c.is_import = 0
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

    // ---- نمودار فروش + خلاصه‌ی مالی شرکت‌ها، قابل فیلتر بر اساس شرکت و بازه‌ی زمانی
    //      (بر مبنای صورتحساب‌های صادرشده‌ی پرسنلی که به هر شرکت مرتبط‌اند - همان
    //      جدولی که در گزارش مالی شرکت‌ها استفاده می‌شود) ----
    if ($action === 'finance_chart') {
        $companyId = intval($data['company_id'] ?? 0);
        $range = in_array($data['range'] ?? '', ['MONTH', '3M', '6M', 'YEAR', 'ALL'], true) ? $data['range'] : '6M';
        $months = ['MONTH' => 1, '3M' => 3, '6M' => 6, 'YEAR' => 12][$range] ?? null;

        $whereInv = ["1=1"]; $paramsInv = [];
        if ($companyId) { $whereInv[] = "i.company_id = ?"; $paramsInv[] = $companyId; }
        if ($months) { $whereInv[] = "i.created_at >= DATE_SUB(NOW(), INTERVAL $months MONTH)"; }
        $whereInvSql = implode(' AND ', $whereInv);

        // نمودار: جمع مبلغ صورتحساب به تفکیک ماهِ شمسی (نه میلادی - چون یک ماه میلادی
        // می‌تواند بین دو ماه شمسی تقسیم شود)
        $stmt = $pdo->prepare("SELECT created_at, total_amount FROM invoices i WHERE $whereInvSql");
        $stmt->execute($paramsInv);
        $monthly = [];
        foreach ($stmt->fetchAll() as $inv) {
            [$jy, $jm] = jalali_from_gregorian_ts(strtotime($inv['created_at']));
            $key = sprintf('%04d-%02d', $jy, $jm);
            if (!isset($monthly[$key])) $monthly[$key] = ['label' => jalali_month_name($jm) . ' ' . $jy, 'amount' => 0];
            $monthly[$key]['amount'] += intval($inv['total_amount']);
        }
        ksort($monthly);
        $monthly = array_values($monthly);

        // خلاصه: تعداد بیمه‌نامه‌های صادره و جمع حق بیمه (از سطرهای خودِ صورتحساب‌ها)
        $whereLines = ["1=1"]; $paramsLines = [];
        if ($companyId) { $whereLines[] = "il.company_id = ?"; $paramsLines[] = $companyId; }
        if ($months) { $whereLines[] = "i.created_at >= DATE_SUB(NOW(), INTERVAL $months MONTH)"; }
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(il.policy_count, 1)), 0) AS issued_count, COALESCE(SUM(il.total_premium), 0) AS total_premium
            FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id
            WHERE " . implode(' AND ', $whereLines)
        );
        $stmt->execute($paramsLines);
        $summary = $stmt->fetch();

        // بدهی: جمع صورتحساب‌شده منهای جمع دریافتی، در همان بازه/شرکت
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(i.total_amount), 0) FROM invoices i WHERE $whereInvSql");
        $stmt->execute($paramsInv);
        $totalInvoiced = intval($stmt->fetchColumn());

        $wherePay = ["1=1"]; $paramsPay = [];
        if ($companyId) { $wherePay[] = "p.company_id = ?"; $paramsPay[] = $companyId; }
        if ($months) { $wherePay[] = "p.paid_at >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)"; }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE " . implode(' AND ', $wherePay));
        $stmt->execute($paramsPay);
        $totalPaid = intval($stmt->fetchColumn());

        echo json_encode([
            'ok' => true,
            'monthly' => $monthly,
            'issued_count' => intval($summary['issued_count']),
            'total_premium' => intval($summary['total_premium']),
            'total_debt' => max(0, $totalInvoiced - $totalPaid),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  «لیست صدور»: همه‌ی ردیف‌هایی که هنوز صادر نشده‌اند، از همه‌ی شرکت‌ها،
    //  با اطلاعات کامل و وضعیتِ چک‌لیستِ هرکدام. ردیف پس از صدور خودبه‌خود از
    //  این فهرست بیرون می‌رود (چون فقط status <> 'ISSUED' می‌آید).
    // =================================================================
    // ---- داشبورد: (۱) بیمه‌نامه‌های شرکتیِ صادرنشده که انقضایشان نزدیک است (۱۵ روز) یا گذشته
    //                (۲) درخواست‌های بیمه‌ی کارکنان که هنوز صادر نشده‌اند (قدیمی‌ترها اول) ----
    if ($action === 'dashboard_alerts') {
        $days = max(1, min(60, intval($data['days'] ?? 15)));
        $today = date('Y-m-d');
        $limitDate = date('Y-m-d', strtotime("+$days days"));
        $st = $pdo->prepare("SELECT crp.id, crp.request_id, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no, crp.insurance_type,
                                    crp.expiry_date, crp.status, crp.car_name, cr.request_kind, cr.insurer, c.name AS company_name
                               FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                              WHERE crp.status NOT IN ('ISSUED', 'CANCELLED') AND cr.is_import = 0 AND crp.expiry_date IS NOT NULL AND crp.expiry_date <= ?
                                AND crp.expiry_date >= DATE_SUB(?, INTERVAL 60 DAY)
                              ORDER BY crp.expiry_date ASC, crp.id ASC LIMIT 300");
        $st->execute([$limitDate, $today]);
        $company = [];
        foreach ($st->fetchAll() as $r) {
            $left = intval(floor((strtotime($r['expiry_date']) - strtotime($today)) / 86400));
            $company[] = ['id' => intval($r['id']), 'request_id' => intval($r['request_id']), 'company_name' => $r['company_name'],
                          'plate_p1' => $r['plate_p1'], 'plate_p2' => $r['plate_p2'], 'plate_letter' => $r['plate_letter'], 'plate_p4' => $r['plate_p4'],
                          'chassis_no' => $r['chassis_no'], 'car_name' => $r['car_name'], 'insurance_type' => $r['insurance_type'],
                          'insurance_type_fa' => insurance_type_fa($r['insurance_type']), 'insurer' => $r['insurer'],
                          'status' => $r['status'], 'status_fa' => company_plate_status_fa($r['status'], $r['request_kind'] ?? 'NEW_POLICY'),
                          'request_kind_fa' => company_request_kind_fa($r['request_kind'] ?? 'NEW_POLICY'),
                          'expiry_jalali' => jd(strtotime($r['expiry_date'])), 'days_left' => $left];
        }
        $personnel = [];
        if (($actor['role'] ?? '') === 'ADMIN') {
            try {
                $st = $pdo->query("SELECT pc.id, pc.unique_code, pc.insurance_type, pc.status, pc.plate, pc.created_at, pc.insured_name,
                                          per.full_name, per.personnel_code, co.name AS employer
                                     FROM policy_cases pc LEFT JOIN persons per ON per.id = pc.person_id LEFT JOIN companies co ON co.id = per.company_id
                                    WHERE pc.status NOT IN ('ISSUED', 'CANCELLED', 'WITHDRAWN', 'REJECTED')
                                    ORDER BY pc.created_at ASC LIMIT 300");
                foreach ($st->fetchAll() as $r) {
                    $personnel[] = ['id' => intval($r['id']), 'unique_code' => $r['unique_code'], 'name' => $r['full_name'] ?: $r['insured_name'],
                                    'insured_name' => $r['insured_name'], 'personnel_code' => $r['personnel_code'], 'employer' => $r['employer'],
                                    'plate' => $r['plate'], 'insurance_type' => $r['insurance_type'], 'insurance_type_fa' => insurance_type_fa($r['insurance_type']),
                                    'status' => $r['status'], 'created_jalali' => jd(strtotime($r['created_at'])),
                                    'waiting_days' => max(0, intval(floor((time() - strtotime($r['created_at'])) / 86400)))];
                }
            } catch (Throwable $e) {}
        }
        echo json_encode(['ok' => true, 'days' => $days, 'company' => $company, 'personnel' => $personnel, 'show_personnel' => ($actor['role'] ?? '') === 'ADMIN'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'issue_queue') {
        // «لیست صدور» کارِ مدیر است؛ همکار بیمه با ما فقط صادره‌ها را می‌بیند
        if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE); exit; }
        $data = $data + $_GET;   // خروجی اکسل با همان فیلترها از راهِ GET می‌آید
        $isExport = !empty($data['export']);
        company_ensure_issue_info_cols($pdo);
        $src = $data['source'] ?? 'ALL';   // ALL | COMPANY | PERSONNEL
        $out = []; $ready = 0; $waiting = 0;
        $readyFilter = $data['readiness'] ?? '';   // '' | READY | WAITING
        $missingFilter = trim($data['missing_doc'] ?? '');
        $q = trim($data['q'] ?? '');
        $typeF = $data['insurance_type'] ?? '';
        $expFrom = fin_jalali_to_date($data['expiry_from'] ?? '');
        $expTo   = fin_jalali_to_date($data['expiry_to'] ?? '');

        // ردیف‌های «بایگانی وارداتی» صفحه‌ی خودشان را دارند
        $where = ["crp.status <> 'ISSUED'", "crp.status <> 'CANCELLED'", "cr.is_import = 0"];
        $params = [];

        if (!empty($data['company_id']))     { $where[] = "cr.company_id = ?";   $params[] = intval($data['company_id']); }
        if ($typeF)                          { $where[] = "crp.insurance_type = ?"; $params[] = $typeF; }
        if (!empty($data['request_kind']))   { $where[] = "cr.request_kind = ?";  $params[] = $data['request_kind']; }
        if (!empty($data['insurer']))        { $where[] = "cr.insurer = ?";       $params[] = $data['insurer']; }
        // ماهِ ثبتِ درخواست (شمسی)
        $reqMonth = company_month_range($data['req_month'] ?? '');
        if ($reqMonth) { $where[] = "DATE(cr.created_at) BETWEEN ? AND ?"; $params[] = $reqMonth[0]; $params[] = $reqMonth[1]; }
        // مرحله: مقدارهای شرکتی و پرسنلی با هم قاطی نمی‌شوند؛ اگر مرحله‌ی پرسنلی
        // انتخاب شده باشد، هیچ ردیف شرکتی نباید بیاید (و برعکس).
        $stageF = trim($data['stage'] ?? '');
        $companyStages   = ['PENDING', 'READY_FOR_ISSUE', 'WITH_BOSS', 'IN_ISSUANCE'];
        $personnelStages = ['REGISTERED', 'AWAITING_DOCS', 'DOCS_PENDING', 'DOCS_REVIEW', 'ISSUING'];
        if ($stageF !== '') {
            if (in_array($stageF, $companyStages, true)) { $where[] = "crp.status = ?"; $params[] = $stageF; }
            else { $where[] = "1=0"; }
        }

        // بازه‌ی تاریخ انقضا (شمسی داده می‌شود، میلادی مقایسه می‌شود)
        if ($expFrom) { $where[] = "crp.expiry_date >= ?"; $params[] = $expFrom; }
        if ($expTo)   { $where[] = "crp.expiry_date <= ?"; $params[] = $expTo; }
        // انتخابِ سریعِ انقضا: منقضی‌شده / تا ۷، ۱۵ یا ۳۰ روزِ آینده
        $expQuick = (string)($data['expiry_quick'] ?? '');
        if ($expQuick === 'expired') { $where[] = "crp.expiry_date < CURDATE()"; }
        elseif (in_array($expQuick, ['7', '15', '30', '60'], true)) { $where[] = "crp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . intval($expQuick) . " DAY)"; }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(c.name LIKE ? OR crp.chassis_no LIKE ? OR crp.car_name LIKE ? OR crp.ref_policy_number LIKE ?
                         OR CONCAT_WS(' ', crp.plate_p4, crp.plate_letter, crp.plate_p2, crp.plate_p1) LIKE ?
                         OR cr.request_text LIKE ? OR cr.id = ? OR cr.ref_code = ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, intval($q), p2e_digits($q));
        }

        $rows = [];
        if ($src !== 'PERSONNEL') {
            $stmt = $pdo->prepare("
                SELECT crp.*, cr.company_id, cr.insurer, cr.request_kind, cr.request_text, cr.created_at AS request_created_at,
                       cr.letter_file_path, c.name AS company_name, c.phone AS company_phone, c.economic_code, c.address AS company_address
                  FROM company_request_plates crp
                  JOIN company_requests cr ON cr.id = crp.request_id
                  JOIN companies c ON c.id = cr.company_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY crp.expiry_date IS NULL, crp.expiry_date ASC, crp.id ASC
                 LIMIT 1000");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }

        $siteRoot = dirname(__DIR__);
        $docsByPlate = [];
        if ($rows) {
            // مدارکِ تخصیص‌یافته‌ی همه‌ی این ردیف‌ها را یک‌جا می‌گیریم (نه یک کوئری به ازای هر ردیف)
            $ids = array_column($rows, 'id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, plate_id, doc_type, file_path, orig_name, status FROM company_documents WHERE plate_id IN ($ph)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $d) {
                $d['label'] = company_doc_type_label($d['doc_type']);
                $d['is_dir'] = is_dir($siteRoot . '/' . $d['file_path']);
                $docsByPlate[$d['plate_id']][] = $d;
            }
        }

        foreach ($rows as $r) {
            $rowDocs = array_values(array_filter($docsByPlate[$r['id']] ?? [], fn($d) => $d['status'] === 'ASSIGNED'));
            $types = array_values(array_filter(array_column($rowDocs, 'doc_type')));
            $kind = $r['request_kind'] ?? 'NEW_POLICY';

            $r['checklist'] = company_plate_checklist($r['insurance_type'], (bool)$r['skip_health_inspection'], $types, $r['has_prev_body'], $kind, !empty($r['is_new_vehicle']));
            foreach ($r['checklist'] as &$item) {
                $item['docs'] = array_values(array_filter($rowDocs,
                    fn($d) => in_array($d['doc_type'], $item['upload_types'], true) || $d['doc_type'] === $item['key']));
            }
            unset($item);
            $r['missing_docs'] = company_plate_missing_docs($r['insurance_type'], (bool)$r['skip_health_inspection'], $types, $r['has_prev_body'], $kind, !empty($r['is_new_vehicle']));
            $r['is_ready'] = empty($r['missing_docs']);
            $r['all_docs'] = $rowDocs;

            if ($readyFilter === 'READY' && !$r['is_ready']) continue;
            if ($readyFilter === 'WAITING' && $r['is_ready']) continue;
            if ($missingFilter !== '' && !array_key_exists($missingFilter, $r['missing_docs'])) continue;

            $r['is_ready'] ? $ready++ : $waiting++;
            $r['source']              = 'COMPANY';
            $r['source_fa']           = 'شرکتی';
            $r['plate_display']       = company_row_label($r);
            $r['status_fa']           = company_plate_status_fa($r['status'], $kind);
            $r['request_kind_fa']     = company_request_kind_fa($kind);
            $r['insurance_type_fa']   = insurance_type_fa($r['insurance_type']);
            $r['coverages_fa']        = company_coverages_fa($r['selected_coverages'] ?? null);
            $r['expiry_date_jalali']  = $r['expiry_date'] ? jd(strtotime($r['expiry_date'])) : null;
            $r['request_date_jalali'] = jd(strtotime($r['request_created_at']));
            $r['days_to_expiry']      = $r['expiry_date'] ? (int)floor((strtotime($r['expiry_date']) - strtotime('today')) / 86400) : null;
            // ستون‌هایی که فقط برای کارکنان معنی دارند، برای ردیف شرکتی خالی می‌مانند
            $r['insured_name']    = $r['company_name'];
            $r['holder_name']     = null;
            $r['personnel_code']  = null;
            $r['national_id']     = $r['economic_code'];
            $r['period_title']    = null;
            $r['relationship']    = null;
            $r['issue_info']      = issue_info_decode($r['issue_info'] ?? null);
            $out[] = $r;
        }

        // ---------- پرونده‌های صدورِ کارکنان، در همین فهرست ----------
        // بیمه‌ی کارکنان بخشِ «همکارِ شرکت‌ها» نیست؛ فقط مدیر کل آن را می‌بیند.
        if ($src !== 'COMPANY' && ($actor['role'] ?? '') === 'ADMIN') {
            $pw = ["pc.status <> 'ISSUED'", "pc.status <> 'CANCELLED'", "COALESCE(pc.status, '') <> 'WITHDRAWN'"]; $pp = [];
            if ($typeF) { $pw[] = "pc.insurance_type = ?"; $pp[] = $typeF; }
            // «نوع درخواست» برای کارکنان همیشه صدور جدید است؛ اگر فیلترِ دیگری خورده، چیزی نیاید
            if (!empty($data['request_kind']) && $data['request_kind'] !== 'NEW_POLICY') $pw[] = "1=0";
            if (!empty($data['company_id'])) { $pw[] = "per.company_id = ?"; $pp[] = intval($data['company_id']); }
            if ($reqMonth) { $pw[] = "DATE(pc.created_at) BETWEEN ? AND ?"; $pp[] = $reqMonth[0]; $pp[] = $reqMonth[1]; }
            if ($expFrom || $expTo || $expQuick !== '') $pw[] = "1=0";  // پرونده‌ی کارکنان تاریخ انقضا ندارد
            if (!empty($data['insurer']) && $data['insurer'] !== 'PASARGAD') $pw[] = "1=0";   // بیمه‌ی کارکنان همیشه پاسارگاد است
            if ($stageF !== '') {
                if (in_array($stageF, $personnelStages, true)) { $pw[] = "pc.status = ?"; $pp[] = $stageF; }
                else { $pw[] = "1=0"; }
            }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $pw[] = "(per.full_name LIKE ? OR pc.insured_name LIKE ? OR pc.plate LIKE ? OR pc.unique_code LIKE ?
                          OR per.personnel_code LIKE ? OR per.national_code LIKE ? OR co.name LIKE ?)";
                array_push($pp, $like, $like, $like, $like, $like, $like, $like);
            }

            try {
                $period = function_exists('fin_current_period') ? fin_current_period($pdo) : null;
                $stmt = $pdo->prepare("
                    SELECT pc.*, per.full_name AS holder_name, per.national_code AS holder_national_code,
                           per.personnel_code, per.mobile_number, co.name AS company_name, co.phone AS company_phone,
                           i.letter_date, i.max_quota, i.used_quota, i.file_path AS intro_file_path
                      FROM policy_cases pc
                      JOIN persons per ON per.id = pc.person_id
                      LEFT JOIN companies co ON co.id = per.company_id
                      LEFT JOIN introductions i ON i.id = pc.introduction_id
                     WHERE " . implode(' AND ', $pw) . "
                     ORDER BY pc.created_at DESC LIMIT 1000");
                $stmt->execute($pp);
                foreach ($stmt->fetchAll() as $c) {
                    // آماده‌بودنِ پرونده‌ی کارکنان: همه‌ی مدارک تاییدشده، و اگر بدنه است
                    // بازدید سلامتش هم تاییدشده و گزارشش بارگذاری شده باشد
                    $docsSt   = compute_docs_status($pdo, $c);
                    $healthSt = compute_health_status($pdo, $c);
                    $missing = [];
                    if ($docsSt !== 'approved' && $docsSt !== 'not_applicable') {
                        $missing['case_docs'] = [
                            'needs_fix' => 'مدارک رد‌شده (نیاز به اصلاح)', 'partial' => 'مدارک ناقص',
                            'not_started' => 'مدارک ارسال نشده', 'submitted' => 'مدارک در انتظار تایید',
                        ][$docsSt] ?? 'مدارک ناقص';
                    }
                    if ($c['insurance_type'] === 'BODY' && $healthSt !== 'approved' && $healthSt !== 'not_applicable') {
                        $missing['health_inspection'] = [
                            'needs_fix' => 'بازدید سلامت رد شد', 'not_started' => 'بازدید سلامت انجام نشده',
                            'submitted' => 'بازدید سلامت در انتظار تایید',
                        ][$healthSt] ?? 'بازدید سلامت';
                    }
                    if (has_pending_health_report($pdo, $c['id'])) $missing['health_report'] = 'گزارش بازدید بارگذاری نشده';

                    $isReady = empty($missing);
                    if ($readyFilter === 'READY' && !$isReady) continue;
                    if ($readyFilter === 'WAITING' && $isReady) continue;
                    if ($missingFilter !== '' && !array_key_exists($missingFilter, $missing)) continue;
                    $isReady ? $ready++ : $waiting++;

                    $stmtD = $pdo->prepare("SELECT id, doc_key, doc_label, file_path, status FROM case_documents WHERE case_id = ? ORDER BY id DESC");
                    $stmtD->execute([$c['id']]);
                    $caseDocs = [];
                    foreach ($stmtD->fetchAll() as $d) {
                        $caseDocs[] = ['id' => $d['id'], 'doc_type' => $d['doc_key'],
                                       'label' => $d['doc_label'] ?: $d['doc_key'],
                                       'file_path' => $d['file_path'], 'status' => $d['status'], 'is_dir' => false];
                    }

                    $out[] = [
                        'source' => 'PERSONNEL', 'source_fa' => 'کارکنان',
                        'id' => intval($c['id']), 'request_id' => intval($c['introduction_id'] ?? 0),
                        'company_name' => $c['company_name'], 'company_phone' => $c['company_phone'],
                        'economic_code' => null, 'company_address' => null,
                        'insured_name' => $c['insured_name'] ?: $c['holder_name'],
                        'holder_name' => $c['holder_name'],
                        'personnel_code' => $c['personnel_code'],
                        'national_id' => $c['insured_national_id'] ?: $c['holder_national_code'],
                        'relationship' => $c['insured_relationship'] ?? null,
                        'period_title' => $period['title'] ?? null,
                        'plate_display' => $c['plate'] ?: 'بدون‌پلاک',
                        'plate_p1' => null, 'plate_p2' => null, 'plate_letter' => null, 'plate_p4' => null,
                        'chassis_no' => $c['chassis_num'] ?? null, 'engine_no' => $c['engine_num'] ?? null,
                        'is_new_vehicle' => 0, 'car_name' => trim(($c['car_system'] ?? '') . ' ' . ($c['car_type'] ?? '')) ?: null,
                        'car_value' => $c['car_value'] ?? null, 'liability_limit' => null,
                        'ref_policy_number' => null, 'endorsement_request' => null, 'cancellation_reason' => null,
                        'insurance_type' => $c['insurance_type'], 'insurance_type_fa' => insurance_type_fa($c['insurance_type']),
                        'request_kind' => 'NEW_POLICY', 'request_kind_fa' => 'صدور بیمه‌نامه‌ی جدید',
                        'request_text' => null, 'insurer' => 'PASARGAD',
                        'unique_code' => $c['unique_code'] ?? null,
                        'letter_file_path' => $c['intro_file_path'] ?? null,
                        // introductions.letter_date خودش شمسی است (مثلاً 1405-06-10 در ستون DATE)؛ تبدیل نمی‌خواهد
                        'letter_date_jalali' => !empty($c['letter_date']) ? str_replace('-', '.', $c['letter_date']) : null,
                        'quota' => (isset($c['max_quota']) ? (intval($c['used_quota']) . ' از ' . intval($c['max_quota'])) : null),
                        'expiry_date' => null, 'expiry_date_jalali' => null, 'days_to_expiry' => null,
                        'request_created_at' => $c['created_at'], 'request_date_jalali' => jd(strtotime($c['created_at'])),
                        'status' => $c['status'], 'status_fa' => case_status_fa($c['status']),
                        'is_ready' => $isReady, 'missing_docs' => $missing,
                        'checklist' => [], 'all_docs' => $caseDocs,
                        'skip_health_inspection' => 0, 'has_prev_body' => null,
                        // مشخصاتِ کاملِ خودرو و بیمه‌گذار برای پاپ‌آپِ صدور
                        'vin' => $c['vin'] ?? null, 'car_system' => $c['car_system'] ?? null, 'car_type' => $c['car_type'] ?? null,
                        'car_model_year' => $c['car_model_year'] ?? null, 'car_color' => $c['car_color'] ?? null, 'car_usage' => $c['car_usage'] ?? null,
                        'insured_phone' => $c['insured_phone'] ?? null, 'insured_address' => $c['insured_address'] ?? null,
                        'insured_postal_code' => $c['insured_postal_code'] ?? null, 'insured_birth_date' => $c['insured_birth_date'] ?? null,
                        'holder_national_code' => $c['holder_national_code'] ?? null, 'mobile_number' => $c['mobile_number'] ?? null,
                        'ownership_choice' => $c['ownership_choice'] ?? null, 'prev_body_insurance' => $c['prev_body_insurance'] ?? null,
                        'no_claim_years' => $c['no_claim_years'] ?? null, 'liability_limit_case' => $c['liability_limit'] ?? null,
                        'estimated_car_value' => $c['estimated_car_value'] ?? null,
                        'coverages_fa' => function_exists('company_coverages_fa') ? company_coverages_fa($c['selected_coverages'] ?? null) : [],
                        'issue_info' => issue_info_decode($c['issue_info'] ?? null),
                    ];
                }
            } catch (Throwable $e) {
                error_log('[issue_queue personnel] ' . $e->getMessage());
            }
        }

        // ---------- خروجی اکسلِ «در حال صدور» با همین فیلترها ----------
        if ($isExport) {
            require_once __DIR__ . '/_xlsx_writer.php';
            $headers = ['ردیف', 'منبع', 'شرکت / کارفرما', 'بیمه‌گذار', 'پرسنل', 'کد پرسنلی', 'کد ملی / اقتصادی',
                        'پلاک / شاسی', 'شماره شاسی', 'شماره موتور', 'خودرو', 'نوع بیمه', 'نوع درخواست', 'بیمه‌گر',
                        'تاریخ درخواست', 'تاریخ انقضا', 'روز تا انقضا', 'مرحله / وضعیت', 'مدارک', 'کمبودها',
                        'ارزش خودرو (ریال)', 'تعهد مالی (ریال)', 'پوشش‌ها', 'مالک', 'توضیح'];
            $xr = [];
            foreach ($out as $i => $r) {
                $ii = (array)($r['issue_info'] ?? []);
                $xr[] = [
                    $i + 1, $r['source_fa'], $r['company_name'], $r['insured_name'], $r['holder_name'] ?? '', $r['personnel_code'] ?? '',
                    $r['national_id'] ?? '', $r['plate_display'] ?? '', $r['chassis_no'] ?? '', $r['engine_no'] ?? '', $r['car_name'] ?? '',
                    $r['insurance_type_fa'], $r['request_kind_fa'], (($r['insurer'] ?? '') === 'IRAN' ? 'ایران' : 'پاسارگاد'),
                    fa_digits($r['request_date_jalali'] ?? ''), fa_digits($r['expiry_date_jalali'] ?? ''),
                    $r['days_to_expiry'] === null ? '' : $r['days_to_expiry'],
                    $r['source'] === 'PERSONNEL' ? case_status_fa($r['status']) : ($r['status_fa'] ?? ''),
                    $r['is_ready'] ? 'کامل' : 'ناقص', implode('، ', array_values((array)$r['missing_docs'])),
                    $r['car_value'] ?? '', $r['liability_limit'] ?? '', implode('، ', (array)($r['coverages_fa'] ?? [])),
                    $ii['owner_name'] ?? '', $r['endorsement_request'] ?? ($r['cancellation_reason'] ?? ($r['request_text'] ?? '')),
                ];
            }
            $path = xlsx_build($headers, $xr, 'در حال صدور', [0, 16, 20, 21]);
            xlsx_send($path, 'لیست در حال صدور ' . fa_digits(str_replace('/', '-', jd(time()))) . '.xlsx');
            exit;
        }

        echo json_encode(['ok' => true, 'rows' => $out,
                          'counts' => ['total' => count($out), 'ready' => $ready, 'waiting' => $waiting],
                          'doc_types' => company_doc_types()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ذخیره‌ی «اطلاعات صدور» (مالک، بیمه‌گذار، مشخصاتِ کاملِ خودرو) از پاپ‌آپِ صدور ----
    if ($action === 'save_issue_info') {
        if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE); exit; }
        company_ensure_issue_info_cols($pdo);
        $source = ($data['source'] ?? '') === 'PERSONNEL' ? 'PERSONNEL' : 'COMPANY';
        $id = intval($data['id'] ?? 0);
        $in = is_array($data['info'] ?? null) ? $data['info'] : [];
        $info = [];
        foreach (issue_info_fields() as $k => $label) {
            if (!isset($in[$k])) continue;
            $v = trim(mb_substr((string)$in[$k], 0, 500));
            if ($v !== '') $info[$k] = $v;
        }
        $json = $info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null;
        if ($source === 'COMPANY') {
            $pdo->prepare("UPDATE company_request_plates SET issue_info = ?,
                               chassis_no = COALESCE(NULLIF(?, ''), chassis_no), engine_no = COALESCE(NULLIF(?, ''), engine_no), vin = COALESCE(NULLIF(?, ''), vin)
                           WHERE id = ?")
                ->execute([$json, $info['chassis_no'] ?? '', $info['engine_no'] ?? '', $info['vin'] ?? '', $id]);
        } else {
            // برای کارکنان، فیلدهایی که ستونِ خودشان را دارند همان‌جا هم نوشته می‌شوند
            $pdo->prepare("UPDATE policy_cases SET issue_info = ?,
                               insured_name = COALESCE(NULLIF(?, ''), insured_name), insured_national_id = COALESCE(NULLIF(?, ''), insured_national_id),
                               insured_phone = COALESCE(NULLIF(?, ''), insured_phone), insured_address = COALESCE(NULLIF(?, ''), insured_address),
                               insured_postal_code = COALESCE(NULLIF(?, ''), insured_postal_code),
                               car_system = COALESCE(NULLIF(?, ''), car_system), car_type = COALESCE(NULLIF(?, ''), car_type),
                               car_model_year = COALESCE(NULLIF(?, ''), car_model_year), car_color = COALESCE(NULLIF(?, ''), car_color),
                               car_usage = COALESCE(NULLIF(?, ''), car_usage), chassis_num = COALESCE(NULLIF(?, ''), chassis_num),
                               engine_num = COALESCE(NULLIF(?, ''), engine_num), vin = COALESCE(NULLIF(?, ''), vin)
                           WHERE id = ?")
                ->execute([$json, $info['insured_name'] ?? '', $info['insured_national_id'] ?? '', $info['insured_phone'] ?? '',
                           $info['insured_address'] ?? '', $info['insured_postal_code'] ?? '', $info['car_system'] ?? '', $info['car_type'] ?? '',
                           $info['car_model_year'] ?? '', $info['car_color'] ?? '', $info['car_usage'] ?? '', $info['chassis_no'] ?? '',
                           $info['engine_no'] ?? '', $info['vin'] ?? '', $id]);
        }
        echo json_encode(['ok' => true, 'info' => $info], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- درخواست‌هایی که ردیفِ صادرنشده دارند (برای بخش «صدور گروهی» در صفحه‌ی جامعِ صدور) ----
    if ($action === 'group_issue_requests') {
        require_admin_only();
        $w = ["crp.status NOT IN ('ISSUED','CANCELLED')", "cr.is_import = 0"]; $p = [];
        if (!empty($data['company_id'])) { $w[] = "cr.company_id = ?"; $p[] = intval($data['company_id']); }
        if (!empty($data['insurer'])) { $w[] = "cr.insurer = ?"; $p[] = $data['insurer']; }
        $q = trim((string)($data['q'] ?? ''));
        if ($q !== '') { $w[] = "(c.name LIKE ? OR cr.id = ? OR cr.ref_code = ? OR cr.request_text LIKE ?)"; array_push($p, "%$q%", intval(p2e_digits($q)), p2e_digits($q), "%$q%"); }
        $st = $pdo->prepare("SELECT cr.id, cr.company_id, cr.insurer, cr.request_kind, cr.created_at, c.name AS company_name,
                                    COUNT(*) AS open_rows,
                                    SUM(crp.status IN ('READY_FOR_ISSUE','WITH_BOSS','IN_ISSUANCE')) AS ready_rows,
                                    SUM(crp.insurance_type = 'BODY') AS body_rows, SUM(crp.insurance_type = 'THIRDPARTY') AS third_rows,
                                    MIN(crp.expiry_date) AS nearest_expiry
                               FROM company_request_plates crp
                               JOIN company_requests cr ON cr.id = crp.request_id
                               JOIN companies c ON c.id = cr.company_id
                              WHERE " . implode(' AND ', $w) . "
                              GROUP BY cr.id ORDER BY MIN(crp.expiry_date) IS NULL, MIN(crp.expiry_date) ASC, cr.id DESC LIMIT 300");
        $st->execute($p);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $r['created_jalali'] = jd(strtotime($r['created_at']));
            $r['nearest_expiry_jalali'] = $r['nearest_expiry'] ? jd(strtotime($r['nearest_expiry'])) : null;
            $r['days_to_expiry'] = $r['nearest_expiry'] ? (int)floor((strtotime($r['nearest_expiry']) - strtotime('today')) / 86400) : null;
            $r['request_kind_fa'] = company_request_kind_fa($r['request_kind'] ?? 'NEW_POLICY');
            $rows[] = $r;
        }
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  «صادره‌ها»: یک جدولِ یکپارچه از بیمه‌نامه‌های صادرشده‌ی شرکتی *و* پرسنلی.
    //  با source می‌شود فقط شرکتی، فقط پرسنلی، یا هر دو را دید. همه‌ی اطلاعاتی
    //  که داریم - از جمله چیزهایی که از خودِ فایل بیمه‌نامه شناسایی شده - می‌آید.
    //  همین اکشن خروجی اکسل هم می‌دهد (export=1).
    // =================================================================
    if ($action === 'issued_list' || ($_GET['action'] ?? '') === 'issued_list') {
        $data = $data + $_GET;   // خروجی اکسل (GET) هم همه‌ی فیلترها را داشته باشد، از جمله شرکت
        company_ensure_issue_info_cols($pdo);
        require_once __DIR__ . '/_endorse.php';
        endo_ensure($pdo);   // ستون‌های «الحاقیه» (تعداد، حق بیمه‌ی نهایی، فسخ)
        $endoF = (string)($data['endo'] ?? '');   // '' | 'has' | 'cancelled' | 'none'
        $expFromI = fin_jalali_to_date($data['expiry_from'] ?? '');
        $expToI = fin_jalali_to_date($data['expiry_to'] ?? '');
        $insurerF = $data['insurer'] ?? '';
        $src = $data['source'] ?? ($_GET['source'] ?? 'ALL');       // ALL | COMPANY | PERSONNEL
        $q   = trim($data['q'] ?? ($_GET['q'] ?? ''));
        $from = fin_jalali_to_date($data['issued_from'] ?? ($_GET['issued_from'] ?? ''));
        $to   = fin_jalali_to_date($data['issued_to'] ?? ($_GET['issued_to'] ?? ''));
        $typeF = $data['insurance_type'] ?? ($_GET['insurance_type'] ?? '');
        $kindF = $data['request_kind'] ?? ($_GET['request_kind'] ?? '');
        $isExport = !empty($data['export']) || !empty($_GET['export']);

        $rows = [];

        // ---------- صادره‌های شرکتی ----------
        if ($src !== 'PERSONNEL') {
            $w = ["crp.status = 'ISSUED'"]; $p = [];
            if ($from)  { $w[] = "DATE(crp.issued_at) >= ?"; $p[] = $from; }
            if ($to)    { $w[] = "DATE(crp.issued_at) <= ?"; $p[] = $to; }
            if ($typeF) { $w[] = "crp.insurance_type = ?";   $p[] = $typeF; }
            if ($kindF) { $w[] = "cr.request_kind = ?";      $p[] = $kindF; }
            if (!empty($data['company_id'])) { $w[] = "cr.company_id = ?"; $p[] = intval($data['company_id']); }
            if ($expFromI) { $w[] = "crp.expiry_date >= ?"; $p[] = $expFromI; }
            if ($expToI)   { $w[] = "crp.expiry_date <= ?"; $p[] = $expToI; }
            if ($insurerF) { $w[] = "cr.insurer = ?"; $p[] = $insurerF; }
            // برچسبِ «بایگانی وارداتی»: '' = همه، 1 = فقط وارداتی، 0 = بدونِ وارداتی
            $impF = (string)($data['import'] ?? '');
            if ($impF === '1') $w[] = "cr.is_import = 1"; elseif ($impF === '0') $w[] = "cr.is_import = 0";
            $stmt = $pdo->prepare("
                SELECT crp.*, cr.request_kind, cr.insurer, cr.created_at AS request_created_at, cr.request_text, cr.is_import,
                       c.name AS company_name, c.economic_code, c.phone AS company_phone
                  FROM company_request_plates crp
                  JOIN company_requests cr ON cr.id = crp.request_id
                  JOIN companies c ON c.id = cr.company_id
                 WHERE " . implode(' AND ', $w) . " ORDER BY crp.issued_at DESC LIMIT 5000");
            $stmt->execute($p);
            foreach ($stmt->fetchAll() as $r) {
                $kind = $r['request_kind'] ?? 'NEW_POLICY';
                $rows[] = [
                    'source' => 'COMPANY', 'source_fa' => !empty($r['is_import']) ? 'وارداتی' : 'شرکتی',
                    'is_import' => !empty($r['is_import']) ? 1 : 0, 'tag' => !empty($r['is_import']) ? 'بایگانی وارداتی' : null,
                    'row_id' => intval($r['id']), 'request_id' => intval($r['request_id']),
                    'holder' => $r['company_name'], 'insured_name' => $r['company_name'],
                    'national_id' => $r['economic_code'], 'phone' => $r['company_phone'],
                    'company_name' => $r['company_name'],
                    'plate' => company_row_label($r), 'chassis_no' => $r['chassis_no'], 'engine_no' => $r['engine_no'],
                    'insurance_type' => $r['insurance_type'], 'insurance_type_fa' => insurance_type_fa($r['insurance_type']),
                    'request_kind' => $kind, 'request_kind_fa' => company_request_kind_fa($kind),
                    'request_text' => $r['request_text'], 'ref_policy_number' => $r['ref_policy_number'],
                    'endorsement_request' => $r['endorsement_request'], 'cancellation_reason' => $r['cancellation_reason'],
                    'policy_number' => $r['policy_number'], 'vin' => $r['vin'],
                    'car_name' => $r['car_name'], 'car_system' => null, 'car_type' => null,
                    'car_model_year' => null, 'car_color' => null, 'car_usage' => null,
                    'car_value' => $r['car_value'], 'liability_limit' => $r['liability_limit'],
                    'coverages_fa' => company_coverages_fa($r['selected_coverages'] ?? null),
                    'total_premium' => $r['total_premium'], 'insurer' => $r['insurer'],
                    'request_date' => $r['request_created_at'], 'request_date_jalali' => jd(strtotime($r['request_created_at'])),
                    'expiry_date_jalali' => $r['expiry_date'] ? jd(strtotime($r['expiry_date'])) : null,
                    'issued_at' => $r['issued_at'], 'issued_at_jalali' => $r['issued_at'] ? jd(strtotime($r['issued_at'])) : null,
                    'policy_issue_date' => $r['policy_issue_date'] ?? null,
                    'status_fa' => company_plate_status_fa('ISSUED', $kind),
                    'folder_status' => $r['folder_status'], 'issued_file_path' => $r['issued_file_path'],
                    'issue_info' => issue_info_decode($r['issue_info'] ?? null),
                    'endo_count' => (int)($r['endo_count'] ?? 0), 'final_premium' => $r['final_premium'] !== null ? (int)$r['final_premium'] : null,
                    'is_cancelled' => (int)($r['is_cancelled'] ?? 0), 'cancelled_on' => $r['cancelled_on'] ?? null,
                ];
            }
        }

        // ---------- صادره‌های پرسنلی ----------
        // مثل فهرست صدور: بیمه‌ی کارکنان را فقط مدیر کل می‌بیند.
        if ($src !== 'COMPANY' && ($actor['role'] ?? '') === 'ADMIN' && (string)($data['import'] ?? '') !== '1') {
            $w = ["pc.status = 'ISSUED'"]; $p = [];
            if ($from)  { $w[] = "DATE(pc.issued_at) >= ?"; $p[] = $from; }
            if ($to)    { $w[] = "DATE(pc.issued_at) <= ?"; $p[] = $to; }
            if ($typeF) { $w[] = "pc.insurance_type = ?";   $p[] = $typeF; }
            if (!empty($data['company_id'])) { $w[] = "per.company_id = ?"; $p[] = intval($data['company_id']); }
            // نوع درخواست برای پرسنلی همیشه «صدور بیمه‌نامه‌ی جدید» است
            if ($kindF && $kindF !== 'NEW_POLICY') { $w[] = "1=0"; }
            if ($expFromI || $expToI) { $w[] = "1=0"; }                       // انقضای قبلی برای کارکنان ثبت نمی‌شود
            if ($insurerF && $insurerF !== 'PASARGAD') { $w[] = "1=0"; }
            try {
                $stmt = $pdo->prepare("
                    SELECT pc.*, per.full_name AS holder_name, per.national_code AS holder_nid, per.mobile_number, per.personnel_code AS holder_pcode,
                           co.name AS employer_name, it.letter_date AS intro_letter_date, it.created_at AS intro_created_at
                      FROM policy_cases pc
                      LEFT JOIN persons per ON per.id = pc.person_id
                      LEFT JOIN introductions it ON it.id = pc.introduction_id
                      LEFT JOIN companies co ON co.id = per.company_id
                     WHERE " . implode(' AND ', $w) . " ORDER BY pc.issued_at DESC LIMIT 5000");
                $stmt->execute($p);
                foreach ($stmt->fetchAll() as $r) {
                    $rows[] = [
                        'source' => 'PERSONNEL', 'source_fa' => 'کارکنان',
                        'row_id' => intval($r['id']), 'request_id' => intval($r['introduction_id'] ?? 0),
                        'holder' => $r['holder_name'], 'insured_name' => $r['insured_name'] ?: $r['holder_name'],
                        'national_id' => $r['insured_national_id'] ?: $r['holder_nid'], 'phone' => $r['ocr_phone'] ?: $r['mobile_number'],
                        'company_name' => $r['employer_name'],
                        'plate' => $r['plate'], 'chassis_no' => $r['chassis_num'], 'engine_no' => $r['engine_num'],
                        'insurance_type' => $r['insurance_type'], 'insurance_type_fa' => insurance_type_fa($r['insurance_type']),
                        'request_kind' => 'NEW_POLICY', 'request_kind_fa' => 'صدور بیمه‌نامه‌ی جدید',
                        'request_text' => null, 'ref_policy_number' => null,
                        'endorsement_request' => null, 'cancellation_reason' => null,
                        'policy_number' => $r['policy_number'], 'vin' => $r['vin'],
                        'car_name' => trim(($r['car_system'] ?? '') . ' ' . ($r['car_type'] ?? '')) ?: null,
                        'car_system' => $r['car_system'], 'car_type' => $r['car_type'],
                        'car_model_year' => $r['car_model_year'], 'car_color' => $r['car_color'], 'car_usage' => $r['car_usage'],
                        'car_value' => $r['car_value'], 'liability_limit' => null,
                        'total_premium' => $r['total_premium'], 'insurer' => 'PASARGAD',
                        'request_date' => $r['created_at'], 'request_date_jalali' => jd(strtotime($r['created_at'])),
                        'expiry_date_jalali' => null,
                        'issued_at' => $r['issued_at'], 'issued_at_jalali' => $r['issued_at'] ? jd(strtotime($r['issued_at'])) : null,
                        'policy_issue_date' => $r['policy_issue_date'] ?? null,
                        'status_fa' => 'صادر شد',
                        'folder_status' => null, 'issued_file_path' => $r['issued_file_path'],
                        'unique_code' => $r['unique_code'] ?? null, 'central_unique_code' => $r['central_unique_code'] ?? null,
                        'policy_issue_date' => $r['policy_issue_date'] ?? null,
                        // معرفی‌نامه‌ی این بیمه‌نامه (تاریخِ نامه و ماهش) و کد پرسنلی
                        'personnel_code' => $r['holder_pcode'] ?? null,
                        'intro_letter_j' => (!empty($r['intro_letter_date']) && preg_match('/^(1[34]\d\d)-(\d{1,2})-(\d{1,2})/', $r['intro_letter_date'], $lm)) ? sprintf('%04d/%02d/%02d', $lm[1], $lm[2], $lm[3]) : null,
                        'intro_month_fa' => $r['introduction_id'] ? (function () use ($r) { [$y, $m] = intro_letter_month(['letter_date' => $r['intro_letter_date'], 'created_at' => $r['intro_created_at']]); return jalali_month_name($m) . ' ' . $y; })() : null,
                        'issue_info' => issue_info_decode($r['issue_info'] ?? null),
                        'endo_count' => (int)($r['endo_count'] ?? 0), 'final_premium' => isset($r['final_premium']) && $r['final_premium'] !== null ? (int)$r['final_premium'] : null,
                        'is_cancelled' => (int)($r['is_cancelled'] ?? 0), 'cancelled_on' => $r['cancelled_on'] ?? null,
                    ];
                }
            } catch (Throwable $e) { /* اگر ستونی نبود، دست‌کم شرکتی‌ها نمایش داده شوند */ }
        }

        // ---------- جستجوی آزاد روی همه‌ی ستون‌ها ----------
        if ($q !== '') {
            $needle = mb_strtolower(p2e_digits($q));
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                foreach ($r as $v) {
                    if ($v === null || is_array($v)) continue;
                    if (mb_strpos(mb_strtolower(p2e_digits((string)$v)), $needle) !== false) return true;
                }
                return false;
            }));
        }

        usort($rows, fn($a, $b) => strcmp((string)$b['issued_at'], (string)$a['issued_at']));
        // فیلترِ الحاقیه: دارای الحاقیه / فسخ‌شده / بدونِ الحاقیه
        if ($endoF === 'has') $rows = array_values(array_filter($rows, fn($r) => !empty($r['endo_count'])));
        elseif ($endoF === 'cancelled') $rows = array_values(array_filter($rows, fn($r) => !empty($r['is_cancelled'])));
        elseif ($endoF === 'none') $rows = array_values(array_filter($rows, fn($r) => empty($r['endo_count'])));

        // ---------- شمارش‌ها روی همین فیلترها ----------
        $counts = ['total' => count($rows), 'company' => 0, 'personnel' => 0, 'body' => 0, 'third' => 0, 'premium' => 0, 'import' => 0];
        foreach ($rows as $r) {
            $r['source'] === 'COMPANY' ? $counts['company']++ : $counts['personnel']++;
            if (!empty($r['is_import'])) $counts['import']++;
            $r['insurance_type'] === 'BODY' ? $counts['body']++ : $counts['third']++;
            // حق بیمه‌ی نهایی (با الحاقیه‌ها) اگر الحاقیه دارد
            $counts['premium'] += $r['final_premium'] !== null && !empty($r['endo_count']) ? (int)$r['final_premium'] : intval($r['total_premium']);
        }

        // ---------- خروجی اکسل ----------
        if ($isExport) {
            require_once __DIR__ . '/_xlsx_writer.php';
            $headers = ['ردیف', 'منبع', 'نوع درخواست', 'شرکت / کارفرما', 'بیمه‌گذار', 'کد ملی / اقتصادی', 'تلفن',
                        'پلاک', 'شماره شاسی', 'شماره موتور', 'نوع بیمه', 'بیمه‌گر', 'شماره بیمه‌نامه',
                        'شماره بیمه‌نامه مرجع', 'خودرو', 'سیستم', 'تیپ', 'مدل', 'رنگ', 'کاربری', 'VIN',
                        'ارزش خودرو (ریال)', 'تعهد مالی (ریال)', 'حق بیمه (ریال)', 'تعداد الحاقیه', 'حق بیمه نهایی (ریال)', 'فسخ',
                        'تاریخ درخواست', 'تاریخ انقضا', 'تاریخ صدور', 'وضعیت', 'توضیح درخواست',
                        'کد پرسنلی', 'تاریخ صدور معرفی‌نامه', 'ماه معرفی‌نامه'];
            $out = [];
            foreach ($rows as $i => $r) {
                $out[] = [
                    $i + 1, $r['source_fa'], $r['request_kind_fa'], $r['company_name'], $r['insured_name'],
                    $r['national_id'], $r['phone'], $r['plate'], $r['chassis_no'], $r['engine_no'],
                    $r['insurance_type_fa'], ($r['insurer'] === 'IRAN' ? 'ایران' : 'پاسارگاد'),
                    $r['policy_number'], $r['ref_policy_number'], $r['car_name'], $r['car_system'], $r['car_type'],
                    $r['car_model_year'], $r['car_color'], $r['car_usage'], $r['vin'],
                    $r['car_value'], $r['liability_limit'], $r['total_premium'],
                    $r['endo_count'] ?: '', !empty($r['endo_count']) ? $r['final_premium'] : $r['total_premium'], !empty($r['is_cancelled']) ? 'فسخ ' . fa_digits((string)$r['cancelled_on']) : '',
                    fa_digits($r['request_date_jalali']), fa_digits($r['expiry_date_jalali']), fa_digits(($r['policy_issue_date'] ?? '') ?: $r['issued_at_jalali']), $r['status_fa'],
                    $r['endorsement_request'] ?: ($r['cancellation_reason'] ?: $r['request_text']),
                    $r['personnel_code'] ?? '', fa_digits($r['intro_letter_j'] ?? ''), $r['intro_month_fa'] ?? '',
                ];
            }
            $rangeFa = ($data['issued_from'] ?? ($_GET['issued_from'] ?? '')) ?: 'ابتدا';
            $rangeTo = ($data['issued_to'] ?? ($_GET['issued_to'] ?? '')) ?: 'امروز';
            $path = xlsx_build($headers, $out, 'صادره‌ها', [0, 21, 22, 23, 25]);
            // اسمِ فایل نمی‌تواند «/» داشته باشد؛ تاریخ‌ها با خط تیره و رقم فارسی
            $rangeFa = fa_digits(str_replace('/', '-', $rangeFa)); $rangeTo = fa_digits(str_replace('/', '-', $rangeTo));
            xlsx_send($path, 'بیمه‌نامه‌های صادره ' . $rangeFa . ' تا ' . $rangeTo . '.xlsx');
            exit;
        }

        echo json_encode(['ok' => true, 'rows' => $rows, 'counts' => $counts], JSON_UNESCAPED_UNICODE);
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
        if (!function_exists('fin_contracts_ensure')) require_once __DIR__ . '/finance_core.php';
        fin_contracts_ensure($pdo);
        $stmt = $pdo->query("SELECT c.*, parent.name AS parent_name, fc.name AS contract_name, fc.contract_key FROM companies c
                              LEFT JOIN companies parent ON parent.id = c.parent_id LEFT JOIN fin_contracts fc ON fc.id = c.contract_id
                              WHERE c.kind IN ('INSURANCE_CLIENT','BOTH') AND c.is_import = 0 ORDER BY c.name");
        $companiesList = $stmt->fetchAll();
        // آمار برای کارت‌های صفحه‌ی شرکت‌ها: کاربرانِ پنل، درخواست‌ها، درخواست‌های باز، صادره‌ها
        $stat = ['users' => [], 'req' => [], 'open' => [], 'issued' => []];
        try {
            foreach ($pdo->query("SELECT company_id, COUNT(*) n FROM company_portal_user_companies GROUP BY company_id") as $r) $stat['users'][(int)$r['company_id']] = (int)$r['n'];
            foreach ($pdo->query("SELECT company_id, COUNT(*) n, SUM(status NOT IN ('ISSUED','CANCELLED')) o FROM company_requests GROUP BY company_id") as $r) { $stat['req'][(int)$r['company_id']] = (int)$r['n']; $stat['open'][(int)$r['company_id']] = (int)$r['o']; }
            foreach ($pdo->query("SELECT cr.company_id, COUNT(*) n FROM company_request_plates p JOIN company_requests cr ON cr.id = p.request_id WHERE p.status = 'ISSUED' GROUP BY cr.company_id") as $r) $stat['issued'][(int)$r['company_id']] = (int)$r['n'];
        } catch (Throwable $e) {}
        foreach ($companiesList as &$c) {
            $c['created_at_jalali'] = $c['created_at'] ? jd(strtotime($c['created_at'])) : null;
            $cid = (int)$c['id'];
            $c['users_count'] = $stat['users'][$cid] ?? 0; $c['requests_count'] = $stat['req'][$cid] ?? 0;
            $c['open_requests'] = $stat['open'][$cid] ?? 0; $c['issued_count'] = $stat['issued'][$cid] ?? 0;
        }
        echo json_encode(['ok' => true, 'companies' => $companiesList], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ورودِ شرکت‌ها از اکسل: فایلِ نمونه، راهنمای ستون‌ها، پیش‌نمایش و ثبت ----
    if ($action === 'company_import_sample' || ($_GET['action'] ?? '') === 'company_import_sample') {
        require_admin_only();
        require_once __DIR__ . '/_xlsx_writer.php';
        $cols = company_import_columns();
        $headers = array_map(fn($c) => $c[0] . ($c[2] ? ' *' : ''), array_values($cols));
        $rows = [array_map(fn($c) => $c[4], array_values($cols)), array_map(fn($c) => $c[5], array_values($cols))];
        $path = xlsx_build($headers, $rows, 'شرکت‌ها');
        xlsx_send($path, 'نمونه ورود شرکت‌ها.xlsx');
        exit;
    }
    if ($action === 'company_import_columns') {
        $out = [];
        foreach (company_import_columns() as $k => $c) $out[] = ['key' => $k, 'title' => $c[0], 'aliases' => $c[1], 'required' => $c[2], 'note' => $c[3], 'example' => $c[4] !== '' ? $c[4] : $c[5]];
        echo json_encode(['ok' => true, 'columns' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_POST['action'] ?? '') === 'company_import') {
        require_admin_only();
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { echo json_encode(['ok' => false, 'error' => 'فایلی دریافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) { echo json_encode(['ok' => false, 'error' => 'فقط فایلِ اکسل (xlsx) یا CSV.'], JSON_UNESCAPED_UNICODE); exit; }
        $tmp = dirname(__DIR__) . '/tmp_ocr/' . uniqid('cimport_') . '.' . $ext;
        if (!is_dir(dirname($tmp))) @mkdir(dirname($tmp), 0777, true);
        move_uploaded_file($_FILES['file']['tmp_name'], $tmp);
        $parsed = company_import_parse($pdo, $tmp);
        @unlink($tmp);
        if (!empty($parsed['error'])) { echo json_encode(['ok' => false] + $parsed, JSON_UNESCAPED_UNICODE); exit; }
        $sum = ['new' => 0, 'update' => 0, 'error' => 0, 'dup' => 0];
        foreach ($parsed['rows'] as $r) $sum[$r['status']]++;
        if (empty($_POST['commit'])) { echo json_encode(['ok' => true, 'preview' => true, 'summary' => $sum] + $parsed, JSON_UNESCAPED_UNICODE); exit; }
        $res = company_import_commit($pdo, $parsed['rows'], !empty($_POST['update_existing']));
        echo json_encode($res + ['summary' => $sum], JSON_UNESCAPED_UNICODE);
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
        $allowedInsurers = in_array($data['allowed_insurers'] ?? '', ['PASARGAD', 'IRAN', 'BOTH'], true) ? $data['allowed_insurers'] : 'BOTH';
        // قراردادِ پرداخت: نقد/اقساط و تعدادِ قسط از قرارداد می‌آید (ستون‌های قدیمی هم برای سازگاری هم‌شکل می‌شوند)
        if (!function_exists('fin_contracts_ensure')) require_once __DIR__ . '/finance_core.php';
        fin_contracts_ensure($pdo);
        $contractGiven = array_key_exists('contract_id', $data);
        $contractId = intval($data['contract_id'] ?? 0) ?: null;
        if ($contractId) {
            $ct = fin_contract($pdo, $contractId);
            if (!$ct) { echo json_encode(['ok' => false, 'error' => 'قرارداد پیدا نشد.']); exit; }
            $paymentTerms = $ct['pay_type'] === 'CASH' ? 'CASH_IMMEDIATE' : 'INSTALLMENT';
            $instCount = $ct['pay_type'] === 'CASH' ? 1 : intval($ct['installment_count']);
            $offsetMonths = intval($ct['first_due_months']); $offsetDays = intval($ct['first_due_days']);
        }

        if ($action === 'update_company') {
            $companyId = intval($data['id'] ?? 0);
            if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت نامعتبر است.']); exit; }
            if ($parentId === $companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت نمی‌تواند زیرمجموعه‌ی خودش باشد.']); exit; }
            $pdo->prepare("UPDATE companies SET name=?, payment_terms=?, economic_code=?, address=?, phone=?, parent_id=?,
                            bale_group_chat_id=?, allowed_insurers=?, installment_count=?, first_due_offset_months=?, first_due_offset_days=? WHERE id=?")
                ->execute([$name, $paymentTerms, $economicCode, $address, $phone, $parentId, $groupChatId, $allowedInsurers, $instCount, $offsetMonths, $offsetDays, $companyId]);
            if ($contractGiven) $pdo->prepare("UPDATE companies SET contract_id = ? WHERE id = ?")->execute([$contractId, $companyId]);
            echo json_encode(['ok' => true, 'company_id' => $companyId]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, kind FROM companies WHERE name = ?");
        $stmt->execute([$name]);
        $existing = $stmt->fetch();
        if ($existing) {
            $newKind = $existing['kind'] === 'PERSONNEL_EMPLOYER' ? 'BOTH' : $existing['kind'];
            $pdo->prepare("UPDATE companies SET kind=?, payment_terms=?, economic_code=?, address=?, phone=?, parent_id=?,
                            bale_group_chat_id=?, allowed_insurers=?, installment_count=?, first_due_offset_months=?, first_due_offset_days=? WHERE id=?")
                ->execute([$newKind, $paymentTerms, $economicCode, $address, $phone, $parentId, $groupChatId, $allowedInsurers, $instCount, $offsetMonths, $offsetDays, $existing['id']]);
            if ($contractGiven) $pdo->prepare("UPDATE companies SET contract_id = ? WHERE id = ?")->execute([$contractId, $existing['id']]);
            echo json_encode(['ok' => true, 'company_id' => $existing['id']]);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO companies (name, kind, payment_terms, economic_code, address, phone, parent_id,
                                bale_group_chat_id, allowed_insurers, installment_count, first_due_offset_months, first_due_offset_days)
                                VALUES (?, 'INSURANCE_CLIENT', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $paymentTerms, $economicCode, $address, $phone, $parentId, $groupChatId, $allowedInsurers, $instCount, $offsetMonths, $offsetDays]);
        $newId = $pdo->lastInsertId();
        if ($contractId) $pdo->prepare("UPDATE companies SET contract_id = ? WHERE id = ?")->execute([$contractId, $newId]);
        echo json_encode(['ok' => true, 'company_id' => $newId]);
        exit;
    }

    // ---- ساخت حساب کاربری ثبت‌کننده (می‌تواند به چند شرکت همزمان وصل شود) ----
    if ($action === 'create_portal_user') {
        require_admin_only(); // مدیریت حساب‌های ورودِ پنل شرکتی فقط دست مدیر کل است
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
        $mobileIn = trim($data['mobile_number'] ?? '');
        if ($mobileIn !== '' && auth_norm_phone($mobileIn) === '') { echo json_encode(['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۱۲۳۴۵۶۷).']); exit; }
        if ($mobileIn !== '' && ($o = auth_phone_owner($pdo, $mobileIn))) { echo json_encode(['ok' => false, 'error' => auth_phone_conflict_msg($o)], JSON_UNESCAPED_UNICODE); exit; }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO company_portal_users (company_id, username, password_hash, full_name, mobile_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$companyIds[0], $username, password_hash($password, PASSWORD_DEFAULT), $fullName, $mobileIn !== '' ? auth_norm_phone($mobileIn) : null]);
        $portalUserId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO company_portal_user_companies (portal_user_id, company_id) VALUES (?, ?)");
        foreach ($companyIds as $cid) $stmt->execute([$portalUserId, $cid]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'portal_user_id' => $portalUserId]);
        exit;
    }

    // ---- ویرایش حساب کاربری یک ثبت‌کننده (رمز عبور اختیاری - فقط اگر پر شود تغییر می‌کند) ----
    if ($action === 'update_portal_user') {
        require_admin_only(); // مدیریت حساب‌های ورودِ پنل شرکتی فقط دست مدیر کل است
        $id = intval($data['id'] ?? 0);
        $companyIds = array_values(array_filter(array_map('intval', (array)($data['company_ids'] ?? []))));
        $username = trim($data['username'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        $password = (string)($data['password'] ?? '');
        if (!$id || !$companyIds || $username === '' || $fullName === '') {
            echo json_encode(['ok' => false, 'error' => 'همه‌ی فیلدها الزامی هستند و حداقل یک شرکت باید انتخاب شود.']); exit;
        }
        if ($password !== '' && strlen($password) < 6) {
            echo json_encode(['ok' => false, 'error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']); exit;
        }
        $mobileIn = trim($data['mobile_number'] ?? '');
        $mobile = $mobileIn === '' ? null : auth_norm_phone($mobileIn);
        if ($mobileIn !== '' && !$mobile) { echo json_encode(['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۱۲۳۴۵۶۷).']); exit; }
        $stOld = $pdo->prepare("SELECT * FROM company_portal_users WHERE id = ?");
        $stOld->execute([$id]);
        $oldUser = $stOld->fetch();
        if ($mobile && auth_norm_phone($oldUser['mobile_number'] ?? '') !== $mobile && ($o = auth_phone_owner($pdo, $mobile, 'COMPANY', $id))) {
            echo json_encode(['ok' => false, 'error' => auth_phone_conflict_msg($o)], JSON_UNESCAPED_UNICODE); exit;
        }

        $pdo->beginTransaction();
        if ($password !== '') {
            $pdo->prepare("UPDATE company_portal_users SET username = ?, full_name = ?, mobile_number = ?, password_hash = ? WHERE id = ?")
                ->execute([$username, $fullName, $mobile, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $pdo->prepare("UPDATE company_portal_users SET username = ?, full_name = ?, mobile_number = ? WHERE id = ?")
                ->execute([$username, $fullName, $mobile, $id]);
        }
        $pdo->prepare("DELETE FROM company_portal_user_companies WHERE portal_user_id = ?")->execute([$id]);
        $stmt = $pdo->prepare("INSERT INTO company_portal_user_companies (portal_user_id, company_id) VALUES (?, ?)");
        foreach ($companyIds as $cid) $stmt->execute([$id, $cid]);
        $pdo->commit();
        // شماره عوض شد: از ربات بیرون می‌آید و باید با شماره‌ی جدید دوباره وارد شود
        $unlinked = false;
        if ($oldUser && !empty($oldUser['bale_chat_id']) && auth_norm_phone($oldUser['mobile_number']) !== (string)$mobile) {
            auth_unlink_bot($pdo, 'COMPANY', $id, "⚠️ {$fullName} عزیز، شماره‌ی تماسِ حساب شما در پنل «بیمه با ما» تغییر کرد.\nاتصال این گفتگو قطع شد؛ لطفاً با شماره‌ی جدید دوباره وارد شوید.");
            $unlinked = true;
        }
        echo json_encode(['ok' => true, 'bot_unlinked' => $unlinked]);
        exit;
    }

    // ---- حذف حساب کاربری یک ثبت‌کننده ----
    if ($action === 'delete_portal_user') {
        require_admin_only(); // مدیریت حساب‌های ورودِ پنل شرکتی فقط دست مدیر کل است
        $id = intval($data['id'] ?? 0);
        // حذفِ نرم: دیگر وارد نمی‌شود، ولی نامش روی درخواست‌ها و پیام‌هایی که فرستاده باقی می‌ماند
        if (auth_schema_ready($pdo)) {
            auth_unlink_bot($pdo, 'COMPANY', $id, 'حساب کاربری شما در پنل «بیمه با ما» غیرفعال شد و این گفتگو قطع شد.');
            $pdo->prepare("UPDATE company_portal_users SET is_active = 0, is_deleted = 1, deleted_at = NOW() WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE password_reset_requests SET status = 'REJECTED', note = 'کاربر حذف شد', handled_at = NOW() WHERE user_type = 'COMPANY' AND user_id = ? AND status = 'PENDING'")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE company_portal_users SET is_active = 0 WHERE id = ?")->execute([$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- حذف کاملِ یک شرکت (و به‌تبع آن، طبق FK: درخواست‌ها/پلاک‌ها/مدارک/مالی/کاربران
    //      ثبت‌کننده‌ی همان شرکت هم پاک می‌شوند - این یک عملیات غیرقابل‌بازگشت است).
    //      اگر همین شرکت به‌عنوان کارفرمای پرسنل (BOTH) هم پرسنلی وصل داشته باشد، برای
    //      جلوگیری از حذف ناخواسته‌ی اطلاعات پرسنلی، حذف را انجام نمی‌دهیم ----
    if ($action === 'delete_company') {
        if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل می‌تواند شرکت را حذف کند.']); exit; }
        $id = intval($data['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM persons WHERE company_id = ?");
        $stmt->execute([$id]);
        if (intval($stmt->fetchColumn()) > 0) {
            echo json_encode(['ok' => false, 'error' => 'این شرکت به‌عنوان کارفرمای پرسنل هم استفاده شده و پرسنلی دارد؛ برای جلوگیری از حذف ناخواسته‌ی اطلاعات پرسنلی، حذف نشد.']); exit;
        }
        $pdo->prepare("DELETE FROM companies WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- لیست کاربران ثبت‌کننده (برای مرور در پنل مدیریت) ----
    if ($action === 'list_portal_users') {
        require_admin_only(); // همکار شرکت‌ها نباید حتی فهرست حساب‌های ورود را ببیند
        $ready = auth_schema_ready($pdo);
        $stmt = $pdo->query("SELECT cpu.id, cpu.username, cpu.full_name, cpu.mobile_number, cpu.is_active,
                                     " . ($ready ? "(cpu.bale_chat_id IS NOT NULL AND cpu.bot_linked_at IS NOT NULL) AS bot_linked," : "0 AS bot_linked,") . "
                                     GROUP_CONCAT(c.name SEPARATOR '، ') AS company_names,
                                     GROUP_CONCAT(c.id) AS company_ids
                              FROM company_portal_users cpu
                              LEFT JOIN company_portal_user_companies cpuc ON cpuc.portal_user_id = cpu.id
                              LEFT JOIN companies c ON c.id = cpuc.company_id
                              " . ($ready ? "WHERE COALESCE(cpu.is_deleted, 0) = 0" : "WHERE cpu.is_active = 1") . "
                              GROUP BY cpu.id, cpu.username, cpu.full_name, cpu.mobile_number, cpu.is_active, cpu.created_at
                              ORDER BY cpu.created_at DESC");
        echo json_encode(['ok' => true, 'users' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- لیست درخواست‌ها (همه‌ی شرکت‌ها یا فیلترشده) ----
    if ($action === 'list_requests') {
        $statusFilter = $data['status'] ?? '';
        $sql = "SELECT cr.*, c.name AS company_name,
                       (SELECT COUNT(*) FROM company_documents cd WHERE cd.request_id = cr.id AND cd.status = 'UNASSIGNED') AS pending_docs_count,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type = 'BODY') AS body_count,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type <> 'BODY') AS third_count,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type = 'BODY' AND crp.status = 'ISSUED') AS body_issued,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type <> 'BODY' AND crp.status = 'ISSUED') AS third_issued
                FROM company_requests cr JOIN companies c ON c.id = cr.company_id";
        // فیلترها: وضعیت، شرکت، ماهِ ثبت (شمسی)، نوعِ درخواست، بیمه‌گر و جستجو (شماره‌ی درخواست / شرکت / پلاک / شاسی / بیمه‌گذار)
        $w = ["cr.is_import = 0"]; $params = [];   // «بایگانی وارداتی» صفحه‌ی خودش را دارد
        if ($statusFilter !== '') { $w[] = "cr.status = ?"; $params[] = $statusFilter; }
        if (!empty($data['company_id'])) { $w[] = "cr.company_id = ?"; $params[] = intval($data['company_id']); }
        if (!empty($data['request_kind'])) { $w[] = "cr.request_kind = ?"; $params[] = (string)$data['request_kind']; }
        if (!empty($data['insurer'])) { $w[] = "cr.insurer = ?"; $params[] = (string)$data['insurer']; }
        $mr = company_month_range($data['month'] ?? '');
        if ($mr) { $w[] = "DATE(cr.created_at) BETWEEN ? AND ?"; $params[] = $mr[0]; $params[] = $mr[1]; }
        $q = trim(p2e_digits((string)($data['q'] ?? '')));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $w[] = "(cr.id = ? OR cr.ref_code = ? OR c.name LIKE ? OR EXISTS (SELECT 1 FROM company_request_plates x WHERE x.request_id = cr.id AND
                     (CONCAT_WS(' ', x.plate_p4, x.plate_letter, x.plate_p2, x.plate_p1) LIKE ? OR CONCAT(x.plate_p4, x.plate_letter, x.plate_p2, x.plate_p1) LIKE ?
                      OR x.chassis_no LIKE ? OR x.policy_number LIKE ? OR x.car_name LIKE ?)))";
            array_push($params, intval($q), $q, $like, $like, str_replace(' ', '', $like), $like, $like, $like);
        }
        if ($w) $sql .= " WHERE " . implode(' AND ', $w);
        $sql .= " ORDER BY cr.created_at DESC LIMIT " . (count($w) > 1 ? 1000 : 300);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        // مرحله‌ی ردیف‌های هر درخواست (چند ردیف در هر مرحله)
        $stages = [];
        if ($rows) {
            $ids = implode(',', array_map(fn($r) => intval($r['id']), $rows));
            foreach ($pdo->query("SELECT request_id, status, COUNT(*) n FROM company_request_plates WHERE request_id IN ($ids) GROUP BY request_id, status")->fetchAll() as $s)
                $stages[intval($s['request_id'])][$s['status']] = intval($s['n']);
        }
        $sum = ['requests' => count($rows), 'rows' => 0, 'issued' => 0, 'stages' => []];
        foreach ($rows as &$r) {
            $r['created_at_jalali'] = jd(strtotime($r['created_at']));
            $r['updated_at_jalali'] = jd(strtotime($r['updated_at']));
            foreach (['pending_docs_count', 'body_count', 'third_count', 'body_issued', 'third_issued'] as $k) $r[$k] = intval($r[$k]);
            $r['request_kind_fa'] = company_request_kind_fa($r['request_kind'] ?? 'NEW_POLICY');
            $r['requested_counts_fa'] = company_requested_counts_fa($r['requested_counts'] ?? null);
            $r['row_stages'] = [];
            foreach ($stages[intval($r['id'])] ?? [] as $st => $n) {
                $r['row_stages'][] = ['status' => $st, 'status_fa' => company_plate_status_fa($st), 'n' => $n];
                $sum['rows'] += $n; if ($st === 'ISSUED') $sum['issued'] += $n;
                $sum['stages'][$st] = ($sum['stages'][$st] ?? 0) + $n;
            }
        }
        unset($r);
        $stageList = [];
        foreach ($sum['stages'] as $st => $n) $stageList[] = ['status' => $st, 'status_fa' => company_plate_status_fa($st), 'n' => $n];
        $sum['stages'] = $stageList;
        $companies = $pdo->query("SELECT id, name FROM companies WHERE is_import = 0 ORDER BY name")->fetchAll();
        echo json_encode(['ok' => true, 'requests' => $rows, 'summary' => $sum, 'companies' => $companies], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ثبت دستیِ یک درخواست برای یک شرکت، توسط خودمان (بدون اینکه ثبت‌کننده‌ی
    //      شرکت لازم باشد چیزی بفرستد) - submitted_by=NULL می‌ماند و چون my_requests
    //      فقط بر اساس company_id فیلتر می‌کند، همین باعث می‌شود در پنل خودِ شرکت هم دیده شود ----
    if ($action === 'admin_create_request') {
        $companyId = intval($data['company_id'] ?? 0);
        if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت را انتخاب کنید.']); exit; }
        $requestText = trim($data['request_text'] ?? '');
        $insurer = in_array($data['insurer'] ?? '', ['PASARGAD', 'IRAN'], true) ? $data['insurer'] : 'PASARGAD';

        $stmt = $pdo->prepare("SELECT allowed_insurers FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $allowedInsurers = $stmt->fetchColumn();
        if (!$allowedInsurers) { echo json_encode(['ok' => false, 'error' => 'شرکت یافت نشد.']); exit; }
        if ($allowedInsurers !== 'BOTH' && $allowedInsurers !== $insurer) {
            echo json_encode(['ok' => false, 'error' => 'این شرکت فقط مجاز به درخواست بیمه ' . ($allowedInsurers === 'IRAN' ? 'ایران' : 'پاسارگاد') . ' است.']); exit;
        }

        $kind = company_valid_kind($data['request_kind'] ?? 'NEW_POLICY');
        $cnt = company_normalize_requested_counts($data['requested_counts'] ?? null);
        $stmt = $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, request_kind, requested_counts, status) VALUES (?, NULL, ?, ?, ?, ?, 'NEW')");
        $stmt->execute([$companyId, $requestText ?: null, $insurer, $kind, $cnt ? json_encode($cnt) : null]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['ok' => true, 'request_id' => $newId, 'ref_code' => ref_code_of($pdo, 'company_requests', $newId)]);
        exit;
    }

    // ---- اطلاعاتِ فرمِ «ثبت کامل درخواست دستی»: شرکت‌ها، پوشش‌ها، سقف‌های تعهد، مدارک لازم هر نوع،
    //      و (اگر شرکت داده شود) آخرین پوشش‌ها و تعهد مالیِ درخواست‌شده‌ی همان شرکت به‌عنوان پیش‌فرض ----
    if ($action === 'admin_manual_form') {
        $companyId = intval($data['company_id'] ?? 0);
        if ($companyId) {
            echo json_encode(['ok' => true, 'defaults' => company_last_request_defaults($pdo, $companyId)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $companies = $pdo->query("SELECT id, name, allowed_insurers FROM companies WHERE is_import = 0 ORDER BY name")->fetchAll();
        $checklists = [];
        foreach (['NEW_POLICY', 'ENDORSEMENT', 'CANCELLATION'] as $k) {
            foreach (['THIRDPARTY', 'BODY'] as $t) {
                foreach ([0, 1] as $skip) {
                    foreach (['YES', 'NO'] as $prev) {
                        $checklists["$k|$t|$skip|$prev"] = company_plate_checklist($t, (bool)$skip, [], $prev, $k);
                    }
                }
            }
        }
        $docTypes = [];
        foreach (company_doc_types() as $key => $v) $docTypes[$key] = company_doc_type_label($key);
        echo json_encode(['ok' => true, 'companies' => $companies, 'coverage_options' => body_coverage_options(),
                          'liability_options' => liability_limit_options(), 'checklists' => $checklists,
                          'doc_types' => $docTypes, 'kinds' => company_request_kinds(),
                          'cancellation_reasons' => company_cancellation_reasons()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ثبت کاملِ یک درخواست شرکتی توسط خودمان، یک‌جا: شرکت، نوع، ردیف‌ها (پلاک یا شاسی)، تعهد مالیِ ثالث
    //      (برای همه یا تک‌تک)، ارزش خودرو و پوشش‌های بدنه (پیش‌فرض برای همه + متفاوت برای هر پلاک).
    //      نامه اختیاری است؛ مدارک و نامه بعد از همین، با admin_upload_plate_doc بارگذاری می‌شوند.
    //      پوشه‌ی روزِ درخواست داخل پوشه‌ی همان شرکت و پوشه‌ی هر ردیف همین حالا ساخته می‌شود. ----
    if ($action === 'admin_create_full_request') {
        $companyId = intval($data['company_id'] ?? 0);
        $st = $pdo->prepare("SELECT id, name, allowed_insurers FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $company = $st->fetch();
        if (!$company) { echo json_encode(['ok' => false, 'error' => 'شرکت را انتخاب کنید.']); exit; }
        $insurer = in_array($data['insurer'] ?? '', ['PASARGAD', 'IRAN'], true) ? $data['insurer'] : 'PASARGAD';
        if ($company['allowed_insurers'] !== 'BOTH' && $company['allowed_insurers'] !== $insurer) {
            echo json_encode(['ok' => false, 'error' => 'این شرکت فقط مجاز به درخواست بیمه ' . ($company['allowed_insurers'] === 'IRAN' ? 'ایران' : 'پاسارگاد') . ' است.'], JSON_UNESCAPED_UNICODE); exit;
        }
        $kind = company_valid_kind($data['request_kind'] ?? 'NEW_POLICY');
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        if (!$rows) { echo json_encode(['ok' => false, 'error' => 'حداقل یک خودرو (پلاک یا شماره شاسی) وارد کنید.']); exit; }

        $defaultLiability = company_parse_money($data['default_liability'] ?? '');
        $defaultCov = company_clean_coverages($data['default_coverages'] ?? null);
        $clean = []; $errors = []; $classes = [];
        $counts = ['THIRDPARTY' => 0, 'BODY' => 0, 'ENDORSEMENT' => 0, 'CANCELLATION' => 0];
        foreach ($rows as $i => $r) {
            $n = $i + 1;
            $p1 = trim(p2e_digits($r['plate_p1'] ?? '')); $p2 = trim(p2e_digits($r['plate_p2'] ?? ''));
            $letter = trim($r['plate_letter'] ?? ''); $p4 = trim(p2e_digits($r['plate_p4'] ?? ''));
            $chassis = trim(p2e_digits($r['chassis_no'] ?? ''));
            $hasPlate = $p1 !== '' || $p2 !== '' || $letter !== '' || $p4 !== '';
            if ($hasPlate && ($p1 === '' || $p2 === '' || $letter === '' || $p4 === '')) { $errors[] = "ردیف $n: پلاک ناقص است."; continue; }
            if (!$hasPlate && $chassis === '') { $errors[] = "ردیف $n: پلاک یا شماره شاسی را وارد کنید."; continue; }
            $type = in_array($r['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $r['insurance_type'] : null;
            if (!$type) { $errors[] = "ردیف $n: نوع بیمه (ثالث/بدنه) را انتخاب کنید."; continue; }
            $expiry = trim($r['expiry_date'] ?? '') ?: null;
            if ($expiry && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) $expiry = fin_jalali_to_date($expiry);
            $isNew = !empty($r['is_new_vehicle']) ? 1 : 0;
            if ($isNew) $expiry = null;   // صفرکیلومتر بیمه‌نامه‌ی قبلی ندارد
            if ($isNew && !$hasPlate && $chassis === '') { $errors[] = "ردیف $n (صفرکیلومتر): شماره شاسی را وارد کنید."; continue; }

            $liability = null; $carValue = null; $cov = null;
            if ($kind === 'NEW_POLICY') {
                if ($type === 'THIRDPARTY') {
                    $liability = company_parse_money($r['liability_limit'] ?? '') ?: $defaultLiability;
                    if (!$liability) { $errors[] = "ردیف $n (ثالث): تعهد مالی را مشخص کنید."; continue; }
                } else {
                    $carValue = company_parse_money($r['car_value'] ?? '');
                    if (!$carValue) { $errors[] = "ردیف $n (بدنه): ارزش خودرو را وارد کنید."; continue; }
                    $cov = (isset($r['coverages']) && $r['coverages'] !== null && $r['coverages'] !== '') ? company_clean_coverages($r['coverages']) : $defaultCov;
                    if ($cov === null) { $errors[] = "ردیف $n (بدنه): پوشش‌های درخواستی را انتخاب کنید (یا «فقط پوشش پایه»)."; continue; }
                }
                $counts[$type]++;
            } else {
                $counts[$kind]++;
                if (trim($r['ref_policy_number'] ?? '') === '') { $errors[] = "ردیف $n: شماره بیمه‌نامه‌ی فعلی را وارد کنید."; continue; }
            }
            $clean[] = [$p1 ?: null, $p2 ?: null, $letter ?: null, $p4 ?: null, $chassis ?: null,
                        trim(p2e_digits($r['engine_no'] ?? '')) ?: null, $isNew, $carValue, $liability,
                        trim(p2e_digits($r['ref_policy_number'] ?? '')) ?: null, trim($r['endorsement_request'] ?? '') ?: null,
                        trim($r['cancellation_reason'] ?? '') ?: null, $type, $expiry,
                        ($isNew || !empty($r['skip_health_inspection'])) ? 1 : 0,
                        trim($r['car_name'] ?? '') ?: null, mb_substr(trim($r['car_type'] ?? ''), 0, 120) ?: null,
                        $type === 'BODY' && in_array($r['has_prev_body'] ?? '', ['YES', 'NO'], true) ? $r['has_prev_body'] : null,
                        trim($r['row_note'] ?? '') ?: null, $cov];
            $classes[] = in_array($r['vehicle_class'] ?? '', ['LIGHT', 'HEAVY'], true) ? $r['vehicle_class'] : null;
        }
        if ($errors) { echo json_encode(['ok' => false, 'error' => implode("\n", $errors)], JSON_UNESCAPED_UNICODE); exit; }

        company_ensure_car_type($pdo);
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, request_kind, requested_counts, status) VALUES (?, NULL, ?, ?, ?, ?, 'NEW')")
            ->execute([$companyId, trim($data['request_text'] ?? '') ?: null, $insurer, $kind, json_encode(company_normalize_requested_counts($counts))]);
        $requestId = (int)$pdo->lastInsertId();
        ref_code_of($pdo, 'company_requests', $requestId);
        $ins = $pdo->prepare("INSERT INTO company_request_plates
            (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle,
             car_value, liability_limit, ref_policy_number, endorsement_request, cancellation_reason,
             insurance_type, expiry_date, skip_health_inspection, car_name, car_type, has_prev_body, row_note, selected_coverages)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $plateIds = [];
        foreach ($clean as $vals) { $ins->execute(array_merge([$requestId], $vals)); $plateIds[] = (int)$pdo->lastInsertId(); }
        $pdo->commit();
        // سواری/سنگین (برای انتخابِ خودکارِ نوعِ گزارش بازدید) - اگر مایگریشن ۰۲۰ اجرا نشده باشد بی‌صدا رد می‌شود
        foreach ($plateIds as $i => $pid) {
            if (empty($classes[$i])) continue;
            try { $pdo->prepare("UPDATE company_request_plates SET vehicle_class = ? WHERE id = ?")->execute([$classes[$i], $pid]); } catch (Throwable $e) { break; }
        }

        // پوشه‌ی روزِ درخواست (داخل پوشه‌ی شرکت) و پوشه‌ی هر ردیف
        $siteRoot = dirname(__DIR__);
        $st = $pdo->prepare("SELECT created_at FROM company_requests WHERE id = ?");
        $st->execute([$requestId]);
        @mkdir(company_request_date_folder($siteRoot, strtotime($st->fetchColumn()), $company['name']), 0755, true);
        foreach ($plateIds as $pid) { ensure_plate_folder($pdo, $siteRoot, $pid); company_sync_plate_status($pdo, $pid); }

        echo json_encode(['ok' => true, 'request_id' => $requestId, 'plate_ids' => $plateIds], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ویرایش متن/بیمه‌گرِ یک درخواست موجود ----
    if ($action === 'edit_request') {
        $requestId = intval($data['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT cr.*, c.allowed_insurers FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $requestText = trim($data['request_text'] ?? '');
        $insurer = in_array($data['insurer'] ?? '', ['PASARGAD', 'IRAN'], true) ? $data['insurer'] : $req['insurer'];
        if ($req['allowed_insurers'] !== 'BOTH' && $req['allowed_insurers'] !== $insurer) {
            echo json_encode(['ok' => false, 'error' => 'این شرکت فقط مجاز به درخواست بیمه ' . ($req['allowed_insurers'] === 'IRAN' ? 'ایران' : 'پاسارگاد') . ' است.']); exit;
        }

        $kind = isset($data['request_kind']) ? company_valid_kind($data['request_kind']) : ($req['request_kind'] ?? 'NEW_POLICY');
        $pdo->prepare("UPDATE company_requests SET request_text = ?, insurer = ?, request_kind = ? WHERE id = ?")
            ->execute([$requestText ?: null, $insurer, $kind, $requestId]);
        if (array_key_exists('requested_counts', $data)) {
            $cnt = company_normalize_requested_counts($data['requested_counts']);
            $pdo->prepare("UPDATE company_requests SET requested_counts = ? WHERE id = ?")->execute([$cnt ? json_encode($cnt) : null, $requestId]);
        }
        // نوعِ درخواست در نامِ پوشه‌ها هست، پس اگر عوض شد پوشه‌ی ردیف‌ها هم باید جابه‌جا شود
        $stmt = $pdo->prepare("SELECT id FROM company_request_plates WHERE request_id = ? AND status <> 'ISSUED'");
        $stmt->execute([$requestId]);
        foreach ($stmt->fetchAll() as $row) ensure_plate_folder($pdo, dirname(__DIR__), $row['id']);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- حذف کاملِ یک درخواست (پلاک‌ها/مدارک/اقساط/پرداخت‌هایش هم طبق FK پاک می‌شوند) ----
    if ($action === 'delete_request') {
        if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل می‌تواند درخواست را حذف کند.']); exit; }
        $requestId = intval($data['request_id'] ?? 0);
        $pdo->prepare("DELETE FROM company_requests WHERE id = ?")->execute([$requestId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- افزودن دستیِ یک پلاک به یک درخواست (بدون نیاز به مدرک) - برای وقتی که
    //      خودمان می‌خواهیم از قبل ردیف پلاک را بسازیم و بعداً مدارکش را تگ کنیم ----
    if ($action === 'admin_add_plate') {
        $requestId = intval($data['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM company_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $p1 = trim($data['plate_p1'] ?? ''); $p2 = trim($data['plate_p2'] ?? '');
        $letter = trim($data['plate_letter'] ?? ''); $p4 = trim($data['plate_p4'] ?? '');
        $chassis = trim($data['chassis_no'] ?? '');
        $insuranceType = in_array($data['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $data['insurance_type'] : null;
        $expiryDate = trim($data['expiry_date'] ?? '') ?: null;
        if ($expiryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) $expiryDate = fin_jalali_to_date($expiryDate);
        $isNew = !empty($data['is_new_vehicle']) ? 1 : 0;
        $skipHealth = ($isNew || !empty($data['skip_health_inspection'])) ? 1 : 0;
        if ($isNew) $expiryDate = null;   // صفرکیلومتر بیمه‌نامه‌ی قبلی ندارد
        // ردیف یا پلاک دارد یا شماره شاسی (لیفتراک و خودروی صفرکیلومتر پلاک ندارند)
        if ($p1 === '' && $p2 === '' && $letter === '' && $p4 === '' && $chassis === '') {
            echo json_encode(['ok' => false, 'error' => $isNew ? 'برای خودروی صفرکیلومتر شماره شاسی را وارد کنید.' : 'یا پلاک را کامل وارد کنید یا شماره شاسی را.']); exit;
        }
        company_ensure_car_type($pdo);
        $stmt = $pdo->prepare("INSERT INTO company_request_plates
            (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle,
             car_value, liability_limit, ref_policy_number, endorsement_request, cancellation_reason,
             insurance_type, expiry_date, skip_health_inspection, car_name, car_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$requestId, $p1 ?: null, $p2 ?: null, $letter ?: null, $p4 ?: null,
                        $chassis ?: null, trim($data['engine_no'] ?? '') ?: null, $isNew,
                        company_parse_money($data['car_value'] ?? ''), company_parse_money($data['liability_limit'] ?? ''),
                        trim($data['ref_policy_number'] ?? '') ?: null,
                        trim($data['endorsement_request'] ?? '') ?: null,
                        trim($data['cancellation_reason'] ?? '') ?: null,
                        $insuranceType, $expiryDate, $skipHealth, trim($data['car_name'] ?? '') ?: null, mb_substr(trim($data['car_type'] ?? ''), 0, 120) ?: null]);
        $newPlateId = $pdo->lastInsertId();
        ensure_plate_folder($pdo, dirname(__DIR__), $newPlateId);
        company_sync_plate_status($pdo, $newPlateId);
        echo json_encode(['ok' => true, 'plate_id' => $newPlateId]);
        exit;
    }

    // ---- بارگذاری مستقیمِ یک مدرک توسط خودمان برای یک پلاک (یا سطح-نامه، اگر پلاک
    //      مشخص نشود) - چون خودمان مدرک را وارد می‌کنیم، نیازی به مرحله‌ی تگ‌گذاری
    //      جداگانه (assign_document) نیست، مستقیم ASSIGNED و در پوشه‌ی درست ثبت می‌شود ----
    if ($action === 'admin_upload_plate_doc') {
        $requestId = intval($data['request_id'] ?? 0);
        $plateId = intval($data['plate_id'] ?? 0) ?: null;
        $docType = trim($data['doc_type'] ?? '') ?: null;

        $stmt = $pdo->prepare("SELECT cr.*, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
        $stmt->execute([$requestId]);
        $requestRow = $stmt->fetch();
        if (!$requestRow) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $isHealthDoc = ($docType === 'health_inspection');
        // مدرکِ یک ردیف اول داخل پوشه‌ی همان ردیف می‌نشیند و بعد company_place_document
        // نام‌گذاریِ استاندارد «(نوع مدرک) پلاک» را رویش اعمال می‌کند؛ نامه/مدرکِ بدون
        // ردیف داخل پوشه‌ی سطحِ تاریخِ درخواست می‌ماند.
        if ($plateId) {
            $destDir = ensure_plate_folder($pdo, $siteRoot, $plateId);
            if (!$destDir) { echo json_encode(['ok' => false, 'error' => 'پلاک یافت نشد.']); exit; }
        } else {
            $destDir = company_request_letter_folder($siteRoot, strtotime($requestRow['created_at']), $requestRow['company_name']);
        }

        $saved = company_store_uploaded_file($_FILES['file'] ?? [], $destDir, 31457280, $isHealthDoc, false);
        if (!$saved['ok']) { echo json_encode($saved); exit; }
        $relPath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');

        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, file_path, orig_name, file_kind, doc_type, status, assigned_by, assigned_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'ASSIGNED', ?, NOW())")
            ->execute([$requestRow['company_id'], $requestId, $plateId, $relPath, $saved['orig_name'],
                       $plateId ? 'SUPPORTING_DOC' : 'LETTER', $plateId ? $docType : 'letter', $actor['user_id']]);
        $docId = $pdo->lastInsertId();

        if ($plateId) {
            $relPath = company_place_document($pdo, $siteRoot, $docId, $plateId, $docType);
            company_sync_plate_status($pdo, $plateId);
        }

        $pdo->prepare("UPDATE company_requests SET status = IF(status = 'NEW', 'DOCS_REVIEW', status) WHERE id = ?")->execute([$requestId]);
        if (!$plateId) {
            $abs = $siteRoot . '/' . $relPath;
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'pdf';
            $placed = company_place_letter($pdo, $siteRoot, $requestId, $abs, $ext);
            if ($placed) {
                $relPath = $placed;
                $pdo->prepare("UPDATE company_documents SET file_path = ? WHERE id = ?")->execute([$relPath, $docId]);
            }
            $pdo->prepare("UPDATE company_requests SET letter_file_path = ? WHERE id = ?")->execute([$relPath, $requestId]);
        }

        echo json_encode(['ok' => true, 'doc_id' => $docId, 'file_path' => $relPath], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- مدارکِ تخصیص‌نیافته‌ی یک درخواست مشخص (برای دکمه‌ی «بررسی مدارک» روی هر ردیف) ----
    if ($action === 'list_request_inbox') {
        $requestId = intval($data['request_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT cd.*, c.name AS company_name FROM company_documents cd
                                JOIN companies c ON c.id = cd.company_id
                                WHERE cd.request_id = ? AND cd.status = 'UNASSIGNED' ORDER BY cd.uploaded_at ASC, cd.id ASC");
        $stmt->execute([$requestId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) $r['uploaded_at_jalali'] = jd(strtotime($r['uploaded_at']));
        echo json_encode(['ok' => true, 'documents' => $rows], JSON_UNESCAPED_UNICODE);
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
        $reqKind = $request['request_kind'] ?? 'NEW_POLICY';
        $request['request_kind_fa'] = company_request_kind_fa($reqKind);
        $request['requested_counts_fa'] = company_requested_counts_fa($request['requested_counts'] ?? null);
        $request['requested_counts_obj'] = json_decode((string)($request['requested_counts'] ?? ''), true) ?: null;

        $stmt = $pdo->prepare("SELECT * FROM company_documents WHERE request_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$requestId]);
        $docs = $stmt->fetchAll();
        $siteRootForDocs = dirname(__DIR__);
        foreach ($docs as &$d) {
            $d['uploaded_at_jalali'] = jd(strtotime($d['uploaded_at']));
            $d['is_dir'] = is_dir($siteRootForDocs . '/' . $d['file_path']);
        }

        $stmt = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ? ORDER BY id");
        $stmt->execute([$requestId]);
        $plates = $stmt->fetchAll();
        foreach ($plates as &$p) {
            $rowDocs = array_values(array_filter($docs, fn($d) => $d['plate_id'] == $p['id'] && $d['status'] === 'ASSIGNED'));
            $assignedTypes = array_values(array_filter(array_column($rowDocs, 'doc_type')));
            $p['plate_display'] = company_row_label($p);
            $p['coverages_fa'] = company_coverages_fa($p['selected_coverages'] ?? null);
            $p['status_fa'] = company_plate_status_fa($p['status'], $reqKind);
            // چک‌لیستِ کاملِ همین ردیف: هر آیتم با تیک/ضربدر، اجباری یا اختیاری، و
            // مدرکِ متناظرش (اگر موجود است) تا در جدول قابل باز کردن باشد
            $p['checklist'] = company_plate_checklist($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes, $p['has_prev_body'] ?? null, $reqKind, !empty($p['is_new_vehicle']));
            foreach ($p['checklist'] as &$item) {
                $item['docs'] = array_values(array_map(
                    fn($d) => ['id' => $d['id'], 'file_path' => $d['file_path'], 'doc_type' => $d['doc_type'],
                               'label' => company_doc_type_label($d['doc_type']), 'is_dir' => !empty($d['is_dir'])],
                    array_filter($rowDocs, fn($d) => in_array($d['doc_type'], $item['upload_types'], true)
                                                     || $d['doc_type'] === $item['key'])
                ));
            }
            unset($item);
            $p['present_labels'] = company_plate_present_labels($assignedTypes);
            $p['missing_docs'] = company_plate_missing_docs($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes, $p['has_prev_body'] ?? null, $reqKind, !empty($p['is_new_vehicle']));
            $p['expiry_date_jalali'] = $p['expiry_date'] ? jd(strtotime($p['expiry_date'])) : null;
            $p['issued_at_jalali'] = $p['issued_at'] ? jd(strtotime($p['issued_at'])) : null;
            $stmtInst = $pdo->prepare("SELECT * FROM company_installments WHERE plate_id = ? ORDER BY inst_number");
            $stmtInst->execute([$p['id']]);
            $p['installments'] = $stmtInst->fetchAll();
        }
        // بدون unset، حلقه‌ی بعدیِ «foreach ($plates as $p)» ردیفِ آخر را با ردیفِ ماقبل آخر رونویسی می‌کرد
        // (ردیف آخرِ هر درخواستِ چندردیفی در جزئیات، تکراریِ ردیف قبلی دیده می‌شد)
        unset($p);

        foreach ($docs as &$d) $d['doc_type_label'] = company_doc_type_label($d['doc_type']);
        unset($d);

        // شمارشِ خواسته‌شده در صفحه‌ی درخواست: چند بدنه و چند ثالث درخواست شده و چند تا صادر شده
        $counts = ['body' => 0, 'third' => 0, 'body_issued' => 0, 'third_issued' => 0];
        foreach ($plates as $p) {
            $k = $p['insurance_type'] === 'BODY' ? 'body' : 'third';
            $counts[$k]++;
            if ($p['status'] === 'ISSUED') $counts[$k . '_issued']++;
        }

        echo json_encode(['ok' => true, 'request' => $request, 'documents' => $docs, 'plates' => $plates,
                          'counts' => $counts, 'doc_types' => company_doc_types(),
                          'coverage_options' => body_coverage_options(), 'liability_options' => liability_limit_options(),
                          'request_kind' => $reqKind, 'cancellation_reasons' => company_cancellation_reasons()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- صندوق ورودیِ مدارک تخصیص‌نیافته (همه‌ی شرکت‌ها) ----
    if ($action === 'list_inbox') {
        $stmt = $pdo->query("SELECT cd.*, c.name AS company_name FROM company_documents cd
                              JOIN companies c ON c.id = cd.company_id
                              WHERE cd.status = 'UNASSIGNED' ORDER BY cd.uploaded_at ASC, cd.id ASC LIMIT 300");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) $r['uploaded_at_jalali'] = jd(strtotime($r['uploaded_at']));
        echo json_encode(['ok' => true, 'documents' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- PDFِ چند مدرکی: هر صفحه یک مدرکِ «تخصیص‌نیافته»ی جدا، تا تک‌تک بررسی و نوع‌گذاری شود ----
    // split_document: یک مدرکِ موجود (صندوق ورودی یا مدرکِ یک ردیف) صفحه‌به‌صفحه جدا می‌شود
    if ($action === 'split_document') {
        require_once __DIR__ . '/_pdf_split.php';
        $r = company_split_document($pdo, dirname(__DIR__), intval($data['doc_id'] ?? 0), true);
        echo json_encode($r ?: ['ok' => false, 'error' => 'جدا کردن ممکن نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // doc_pages: شمارِ صفحه‌های یک مدرک (برای نمایشِ دکمه‌ی «جدا کردنِ صفحه‌ها» در پنجره‌ی تگ‌گذاری)
    if ($action === 'doc_pages') {
        require_once __DIR__ . '/_pdf_split.php';
        $st = $pdo->prepare("SELECT file_path FROM company_documents WHERE id = ?");
        $st->execute([intval($data['doc_id'] ?? 0)]);
        $fp = $st->fetchColumn();
        $n = $fp ? pdf_page_count($pdo, dirname(__DIR__) . '/' . ltrim($fp, '/')) : null;
        echo json_encode(['ok' => true, 'pages' => $n]);
        exit;
    }
    // upload_combined_pdf: کارشناس یک PDFِ ترکیبی برای یک درخواست (و اختیاری یک ردیف) بارگذاری می‌کند
    if ($action === 'upload_combined_pdf') {
        require_once __DIR__ . '/_pdf_split.php';
        $requestId = intval($data['request_id'] ?? 0);
        $plateId = intval($data['plate_id'] ?? 0) ?: null;
        $stmt = $pdo->prepare("SELECT cr.id, cr.company_id, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
        $stmt->execute([$requestId]);
        $rq = $stmt->fetch();
        if (!$rq) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }
        if ($plateId) {
            $st = $pdo->prepare("SELECT id FROM company_request_plates WHERE id = ? AND request_id = ?");
            $st->execute([$plateId, $requestId]);
            if (!$st->fetchColumn()) $plateId = null;
        }
        $f = $_FILES['file'] ?? [];
        if (strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION)) !== 'pdf') { echo json_encode(['ok' => false, 'error' => 'فقط فایلِ PDF.']); exit; }
        $siteRoot = dirname(__DIR__);
        $saved = company_store_uploaded_file($f, company_unassigned_temp_path($siteRoot, $rq['company_name']), 41943040);
        if (!$saved['ok']) { echo json_encode($saved, JSON_UNESCAPED_UNICODE); exit; }
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, file_path, orig_name, file_kind, doc_type, status) VALUES (?, ?, ?, ?, ?, 'SUPPORTING_DOC', NULL, 'UNASSIGNED')")
            ->execute([$rq['company_id'], $requestId, $plateId, ltrim(str_replace($siteRoot, '', $saved['path']), '/'), $saved['orig_name']]);
        $docId = intval($pdo->lastInsertId());
        $pdo->prepare("UPDATE company_requests SET status = IF(status = 'NEW', 'DOCS_REVIEW', status) WHERE id = ?")->execute([$requestId]);
        $sp = company_split_document($pdo, $siteRoot, $docId);
        if ($sp && empty($sp['ok'])) { echo json_encode(['ok' => true, 'pages' => 1, 'note' => $sp['error'] ?? ''], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['ok' => true, 'pages' => $sp ? intval($sp['pages']) : 1], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- رد کردن یک مدرکِ ارسالیِ شرکت: فایل حذف می‌شود تا طرف دوباره و درست
    //      بفرستد. دلیلِ رد به‌صورت پیام در چتِ همان شرکت ثبت می‌شود تا ببیند چرا. ----
    if ($action === 'reject_document') {
        $docId = intval($data['doc_id'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        $stmt = $pdo->prepare("SELECT cd.*, c.name AS company_name FROM company_documents cd
                                JOIN companies c ON c.id = cd.company_id WHERE cd.id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) { echo json_encode(['ok' => false, 'error' => 'مدرک یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $abs = $siteRoot . '/' . $doc['file_path'];
        if (is_file($abs)) @unlink($abs);
        $pdo->prepare("DELETE FROM company_documents WHERE id = ?")->execute([$docId]);
        if ($doc['plate_id']) company_sync_plate_status($pdo, $doc['plate_id']);

        $note = 'مدرک «' . ($doc['orig_name'] ?: company_doc_type_label($doc['doc_type'])) . '» تایید نشد و حذف شد.'
              . ($reason !== '' ? ' دلیل: ' . $reason : '') . ' لطفاً دوباره و درست ارسال کنید.';
        $pdo->prepare("INSERT INTO company_chat_messages (company_id, sender_type, sender_user_id, message) VALUES (?, 'ADMIN', ?, ?)")
            ->execute([$doc['company_id'], $actor['user_id'], $note]);

        echo json_encode(['ok' => true]);
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
        // تاریخ انقضا شمسی وارد می‌شود (با رقم فارسی یا لاتین)؛ پیش از ذخیره میلادی می‌شود
        $expiryDate = trim($data['expiry_date'] ?? '') ?: null;
        if ($expiryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) $expiryDate = fin_jalali_to_date($expiryDate);
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
        // (چه شرکت خودش به‌عنوانِ نامه فرستاده باشد، چه ما در تگ‌گذاری نوعش را «نامه» بزنیم)
        if ($doc['file_kind'] === 'LETTER' || $docType === 'letter') {
            // نوعِ نامه و تعدادِ درخواستی از هر نوع (ثالث، بدنه، الحاقیه‌ی تغییر اطلاعات، فسخ).
            // اگر تعدادی داده شد، نوعِ درخواست هم از روی همان تعیین می‌شود.
            $counts = company_normalize_requested_counts($data['requested_counts'] ?? null);
            $kindNote = null;
            if ($counts) {
                // نوعِ درخواست فقط وقتی از روی تعدادها عوض می‌شود که هنوز ردیفی ندارد؛ وگرنه
                // پوشه‌های بایگانی و چک‌لیستِ ردیف‌های موجود جابه‌جا می‌شدند. تعدادها همیشه ذخیره می‌شوند.
                $stmtN = $pdo->prepare("SELECT COUNT(*) FROM company_request_plates WHERE request_id = ?");
                $stmtN->execute([$requestId]);
                $stmtK = $pdo->prepare("SELECT request_kind FROM company_requests WHERE id = ?");
                $stmtK->execute([$requestId]);
                $curKind = $stmtK->fetchColumn() ?: 'NEW_POLICY';
                $newKind = company_kind_from_counts($counts, $curKind);
                if (intval($stmtN->fetchColumn()) > 0 && $newKind !== $curKind) {
                    $kindNote = 'تعدادها ذخیره شد، ولی چون این درخواست از قبل ردیف دارد نوعش («' . company_request_kind_fa($curKind)
                              . '») عوض نشد. اگر لازم است، نوع را از ویرایشِ درخواست تغییر دهید.';
                    $newKind = $curKind;
                }
                $pdo->prepare("UPDATE company_requests SET requested_counts = ?, request_kind = ? WHERE id = ?")
                    ->execute([json_encode($counts), $newKind, $requestId]);
            }
            $ext = strtolower(pathinfo($absOld, PATHINFO_EXTENSION)) ?: 'pdf';
            $newRelPath = company_place_letter($pdo, $siteRoot, $requestId, $absOld, $ext) ?: $doc['file_path'];
            $pdo->prepare("UPDATE company_documents SET request_id = ?, plate_id = NULL, file_kind = 'LETTER', doc_type = 'letter', status = 'ASSIGNED', assigned_by = ?, assigned_at = NOW(), file_path = ? WHERE id = ?")
                ->execute([$requestId, $actor['user_id'], $newRelPath, $docId]);
            $pdo->prepare("UPDATE company_requests SET letter_file_path = ?, status = IF(status = 'NEW', 'DOCS_REVIEW', status) WHERE id = ?")
                ->execute([$newRelPath, $requestId]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'is_letter' => true, 'requested_counts_fa' => company_requested_counts_fa($counts),
                              'note' => $kindNote], JSON_UNESCAPED_UNICODE);
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

        // ابتدا رکورد را به ردیفِ انتخاب‌شده وصل کن، بعد فایل را با نام‌گذاریِ
        // استاندارد «(نوع مدرک) پلاک» داخل پوشه‌ی همان ردیف بایگانی کن
        $pdo->prepare("UPDATE company_documents SET request_id = ?, plate_id = ?, doc_type = ?, plate_p1 = ?, plate_p2 = ?, plate_letter = ?, plate_p4 = ?,
                        status = 'ASSIGNED', assigned_by = ?, assigned_at = NOW() WHERE id = ?")
            ->execute([$requestId, $plateId, $docType ?: null, $p1 ?: null, $p2 ?: null, $letter ?: null, $p4 ?: null, $actor['user_id'], $docId]);
        $newRelPath = company_place_document($pdo, $siteRoot, $docId, $plateId, $docType);

        $pdo->prepare("UPDATE company_requests SET status = IF(status = 'NEW', 'DOCS_REVIEW', status) WHERE id = ?")->execute([$requestId]);

        $pdo->commit();
        // وضعیت ردیف بر اساس کامل‌شدنِ چک‌لیست به‌طور خودکار بروز می‌شود
        $rowStatus = company_sync_plate_status($pdo, $plateId);
        echo json_encode(['ok' => true, 'plate_id' => $plateId, 'file_path' => $newRelPath, 'row_status' => $rowStatus], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- آپلودِ هدفمندِ یک مدرک مستقیم روی یک ردیف (دکمه‌ی آپلودِ همان خانه‌ی
    //      چک‌لیست). چون پلاک و نوع مدرک از قبل مشخص است، نیازی به تگ‌گذاریِ
    //      جداگانه نیست: بلافاصله ASSIGNED می‌شود، نام‌گذاری استاندارد می‌گیرد،
    //      داخل پوشه‌ی همان ردیف می‌نشیند و وضعیت ردیف بروز می‌شود. ----
    if (isset($_FILES['doc_file']) && ($_POST['action'] ?? '') === 'upload_row_doc') {
        $plateId = intval($_POST['plate_id'] ?? 0);
        $docType = trim($_POST['doc_type'] ?? '');
        if (!$plateId || !array_key_exists($docType, company_doc_types())) {
            echo json_encode(['ok' => false, 'error' => 'ردیف یا نوع مدرک مشخص نیست.']); exit;
        }

        $stmt = $pdo->prepare("SELECT crp.id, crp.request_id, cr.company_id FROM company_request_plates crp
                                JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
        $stmt->execute([$plateId]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['ok' => false, 'error' => 'ردیف یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $plateFolder = ensure_plate_folder($pdo, $siteRoot, $plateId);
        if (!$plateFolder) { echo json_encode(['ok' => false, 'error' => 'پوشه‌ی این ردیف ساخته نشد.']); exit; }

        // «بازدید سلامت» می‌تواند زیپِ چندفایلی باشد و باید به‌صورت پوشه باز شود
        $isHealth = ($docType === 'health_inspection');
        $saved = company_store_uploaded_file($_FILES['doc_file'], $plateFolder, 31457280, $isHealth, false);
        if (!$saved['ok']) { echo json_encode($saved); exit; }

        $relPath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status, assigned_by, assigned_at)
                        VALUES (?, ?, ?, NULL, ?, ?, 'SUPPORTING_DOC', ?, 'ASSIGNED', ?, NOW())")
            ->execute([$row['company_id'], $row['request_id'], $plateId, $relPath, $saved['orig_name'], $docType, $actor['user_id']]);
        $docId = $pdo->lastInsertId();

        $newRel = company_place_document($pdo, $siteRoot, $docId, $plateId, $docType);
        $rowStatus = company_sync_plate_status($pdo, $plateId);
        echo json_encode(['ok' => true, 'doc_id' => $docId, 'file_path' => $newRel, 'row_status' => $rowStatus], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- حذف یک مدرکِ بارگزاری‌شده‌ی یک ردیف (اگر اشتباه آپلود شده بود) ----
    if ($action === 'delete_row_doc') {
        // حذف، مثل حذفِ خودِ درخواست، فقط دستِ مدیر کل است
        if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل می‌تواند مدرک را حذف کند.']); exit; }
        $docId = intval($data['doc_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, plate_id, file_path FROM company_documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) { echo json_encode(['ok' => false, 'error' => 'مدرک یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $abs = $siteRoot . '/' . $doc['file_path'];
        if (is_file($abs)) @unlink($abs);
        $pdo->prepare("DELETE FROM company_documents WHERE id = ?")->execute([$docId]);
        if ($doc['plate_id']) company_sync_plate_status($pdo, $doc['plate_id']);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- تغییر مرحله‌ی دستیِ یک ردیف: «ارسال برای رئیس» / «در حال صدور» / «لغو» ----
    if ($action === 'set_row_stage') {
        $plateId = intval($data['plate_id'] ?? 0);
        $stage = $data['stage'] ?? '';
        if (!in_array($stage, ['PENDING', 'READY_FOR_ISSUE', 'WITH_BOSS', 'IN_ISSUANCE', 'CANCELLED'], true)) {
            echo json_encode(['ok' => false, 'error' => 'مرحله‌ی نامعتبر.']); exit;
        }
        $stmt = $pdo->prepare("SELECT status FROM company_request_plates WHERE id = ?");
        $stmt->execute([$plateId]);
        $current = $stmt->fetchColumn();
        if ($current === false) { echo json_encode(['ok' => false, 'error' => 'ردیف یافت نشد.']); exit; }
        if ($current === 'ISSUED') { echo json_encode(['ok' => false, 'error' => 'این ردیف صادر شده و مرحله‌اش قابل تغییر نیست.']); exit; }

        $pdo->prepare("UPDATE company_request_plates SET status = ? WHERE id = ?")->execute([$stage, $plateId]);
        // اگر به دو مرحله‌ی خودکار برگشتیم، بگذار چک‌لیست خودش تصمیم بگیرد
        if (in_array($stage, ['PENDING', 'READY_FOR_ISSUE'], true)) $stage = company_sync_plate_status($pdo, $plateId);
        echo json_encode(['ok' => true, 'status' => $stage, 'status_fa' => company_plate_status_fa($stage)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ویرایش مشخصاتِ یک ردیف (نوع بیمه، انقضا، بیمه بدنه قبل، نیاز به بازدید، خودرو) ----
    if ($action === 'update_row') {
        $plateId = intval($data['plate_id'] ?? 0);
        if (!$plateId) { echo json_encode(['ok' => false, 'error' => 'ردیف مشخص نیست.']); exit; }
        $insuranceType = in_array($data['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $data['insurance_type'] : null;
        $hasPrevBody = in_array($data['has_prev_body'] ?? '', ['YES', 'NO'], true) ? $data['has_prev_body'] : null;
        $expiry = trim($data['expiry_date'] ?? '') ?: null;
        if ($expiry && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) $expiry = fin_jalali_to_date($expiry);
        $isNewRow = !empty($data['is_new_vehicle']);
        company_ensure_car_type($pdo);
        $pdo->prepare("UPDATE company_request_plates SET car_type = ? WHERE id = ?")->execute([mb_substr(trim($data['car_type'] ?? ''), 0, 120) ?: null, $plateId]);
        // صفرکیلومتر: بدونِ تاریخِ انقضا و بدونِ بازدیدِ سلامت
        if ($isNewRow) { $pdo->prepare("UPDATE company_request_plates SET expiry_date = NULL WHERE id = ?")->execute([$plateId]); $expiry = null; $data['skip_health_inspection'] = 1; }

        $pdo->prepare("UPDATE company_request_plates SET
                          plate_p1 = COALESCE(?, plate_p1), plate_p2 = COALESCE(?, plate_p2),
                          plate_letter = COALESCE(?, plate_letter), plate_p4 = COALESCE(?, plate_p4),
                          insurance_type = COALESCE(?, insurance_type), expiry_date = COALESCE(?, expiry_date),
                          has_prev_body = ?, skip_health_inspection = ?, car_name = ?, row_note = ?
                       WHERE id = ?")
            ->execute([
                trim($data['plate_p1'] ?? '') ?: null, trim($data['plate_p2'] ?? '') ?: null,
                trim($data['plate_letter'] ?? '') ?: null, trim($data['plate_p4'] ?? '') ?: null,
                $insuranceType, $expiry, $hasPrevBody, !empty($data['skip_health_inspection']) ? 1 : 0,
                trim($data['car_name'] ?? '') ?: null, trim($data['row_note'] ?? '') ?: null, $plateId,
            ]);
        // شناسه‌های خودروی بدونِ پلاک و مبالغ، جدا بروز می‌شوند (رشته‌ی خالی یعنی «پاک کن»)
        $pdo->prepare("UPDATE company_request_plates SET chassis_no = ?, engine_no = ?, is_new_vehicle = ?,
                          car_value = ?, liability_limit = ?, ref_policy_number = ?,
                          endorsement_request = ?, cancellation_reason = ? WHERE id = ?")
            ->execute([trim($data['chassis_no'] ?? '') ?: null, trim($data['engine_no'] ?? '') ?: null,
                       !empty($data['is_new_vehicle']) ? 1 : 0,
                       company_parse_money($data['car_value'] ?? ''), company_parse_money($data['liability_limit'] ?? ''),
                       trim($data['ref_policy_number'] ?? '') ?: null,
                       trim($data['endorsement_request'] ?? '') ?: null,
                       trim($data['cancellation_reason'] ?? '') ?: null,
                       $plateId]);

        if (array_key_exists('coverages', $data)) {
            $pdo->prepare("UPDATE company_request_plates SET selected_coverages = ? WHERE id = ?")
                ->execute([$data['coverages'] === null || $data['coverages'] === '' ? null : company_clean_coverages($data['coverages']), $plateId]);
        }

        // نوع بیمه/انقضا در نامِ پوشه‌ی ردیف هست، پس پوشه هم باید هم‌نام شود
        ensure_plate_folder($pdo, dirname(__DIR__), $plateId);
        $status = company_sync_plate_status($pdo, $plateId);
        echo json_encode(['ok' => true, 'status' => $status], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- تغییر پوشش‌های درخواستیِ بدنه‌ی یک ردیف (coverages: آرایه‌ی پوشش‌ها، 'none' = فقط پایه، null = نامشخص) ----
    if ($action === 'set_row_coverages') {
        $plateId = intval($data['plate_id'] ?? 0);
        $cov = ($data['coverages'] ?? null) === null ? null : company_clean_coverages($data['coverages']);
        $pdo->prepare("UPDATE company_request_plates SET selected_coverages = ? WHERE id = ?")->execute([$cov, $plateId]);
        echo json_encode(['ok' => true, 'coverages_fa' => company_coverages_fa($cov)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- حذف یک ردیف ----
    if ($action === 'delete_row') {
        // حذف، مثل حذفِ خودِ درخواست، فقط دستِ مدیر کل است
        if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل می‌تواند ردیف را حذف کند.']); exit; }
        $plateId = intval($data['plate_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM company_request_plates WHERE id = ?");
        $stmt->execute([$plateId]);
        $st = $stmt->fetchColumn();
        if ($st === false) { echo json_encode(['ok' => false, 'error' => 'ردیف یافت نشد.']); exit; }
        if ($st === 'ISSUED') { echo json_encode(['ok' => false, 'error' => 'ردیفِ صادرشده قابل حذف نیست.']); exit; }
        $pdo->prepare("DELETE FROM company_request_plates WHERE id = ?")->execute([$plateId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- پیش‌نمایشِ ردیف‌های استخراج‌شده از نامه (بدون درج در دیتابیس) ----
    //      ورودی: raw (متنِ JSON یا خط‌به‌خطِ خروجیِ هوش مصنوعی) یا فایل import_file
    if ($action === 'preview_letter_rows' || ($_POST['action'] ?? '') === 'preview_letter_rows') {
        $parsed = company_read_import_input($data, $_FILES['import_file'] ?? null);
        if (isset($parsed['error'])) { echo json_encode(['ok' => false, 'error' => $parsed['error']], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['ok' => true, 'rows' => $parsed['rows'], 'errors' => $parsed['errors'],
                          'source' => $parsed['source'], 'counts_fa' => $parsed['counts_fa'] ?? ''], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- درجِ نهاییِ ردیف‌ها روی یک درخواست (بعد از دیدنِ پیش‌نمایش) ----
    if ($action === 'import_letter_rows' || ($_POST['action'] ?? '') === 'import_letter_rows') {
        company_ensure_issue_info_cols($pdo);
        $requestId = intval($data['request_id'] ?? 0);
        $replace = !empty($data['replace_existing']);

        $stmt = $pdo->prepare("SELECT id FROM company_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $parsed = company_read_import_input($data, $_FILES['import_file'] ?? null);
        if (isset($parsed['error'])) { echo json_encode(['ok' => false, 'error' => $parsed['error']], JSON_UNESCAPED_UNICODE); exit; }
        if (!$parsed['rows']) {
            echo json_encode(['ok' => false, 'error' => 'هیچ ردیفِ قابل‌استفاده‌ای شناسایی نشد.', 'errors' => $parsed['errors']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $raw = $parsed['raw'];

        $pdo->beginTransaction();
        $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM company_request_plates WHERE request_id = ?" . ($replace ? " AND status = 'ISSUED'" : ''));
        $stmtCnt->execute([$requestId]);
        $hadRows = intval($stmtCnt->fetchColumn()) > 0;
        if ($replace) {
            // فقط ردیف‌هایی که هنوز صادر نشده‌اند پاک می‌شوند؛ ردیفِ صادرشده هرگز حذف نمی‌شود
            $pdo->prepare("DELETE FROM company_request_plates WHERE request_id = ? AND status <> 'ISSUED'")->execute([$requestId]);
        }

        // ردیفِ تکراری با پلاک *یا* شماره شاسی تشخیص داده می‌شود (ردیف‌های بدون پلاک
        // فقط شاسی دارند و بدون این شرط همه‌شان تکراری حساب می‌شدند)
        $find = $pdo->prepare("SELECT id FROM company_request_plates WHERE request_id = ?
                                AND plate_p1 <=> ? AND plate_p2 <=> ? AND plate_letter <=> ? AND plate_p4 <=> ?
                                AND chassis_no <=> ? AND insurance_type <=> ?");
        $ins = $pdo->prepare("INSERT INTO company_request_plates
                              (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no,
                               is_new_vehicle, car_value, liability_limit, ref_policy_number, endorsement_request,
                               cancellation_reason, insurance_type, expiry_date,
                               skip_health_inspection, has_prev_body, car_name, car_type, row_note, issue_info)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $created = 0; $skipped = 0; $newIds = [];
        foreach ($parsed['rows'] as $r) {
            $find->execute([$requestId, $r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4'],
                            $r['chassis_no'], $r['insurance_type']]);
            if ($find->fetchColumn()) { $skipped++; continue; } // ردیفِ تکراری دوباره ساخته نمی‌شود
            $ins->execute([$requestId, $r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4'],
                           $r['chassis_no'], $r['engine_no'], $r['is_new_vehicle'], $r['car_value'], $r['liability_limit'],
                           $r['ref_policy_number'], $r['endorsement_request'], $r['cancellation_reason'],
                           $r['insurance_type'], $r['expiry_date'], $r['skip_health_inspection'],
                           $r['has_prev_body'], $r['car_name'], $r['car_type'] ?? null, $r['row_note'], $r['issue_info'] ?? null]);
            $newIds[] = $pdo->lastInsertId();
            $created++;
        }
        // متنِ مرجعِ استخراج‌شده نگه داشته می‌شود تا بعداً بشود با خودِ نامه مقایسه کرد
        $pdo->prepare("UPDATE company_requests SET letter_parsed_json = ?, status = IF(status = 'NEW', 'DOCS_PENDING', status) WHERE id = ?")
            ->execute([mb_substr($raw, 0, 60000), $requestId]);
        // تعدادِ درخواستی از هر نوع (از متن یا شمرده‌شده از ردیف‌ها). نوعِ درخواست فقط وقتی از روی
        // تعدادها تعیین می‌شود که درخواست پیش از این ردیفی نداشته، تا پوشه‌های موجود جابه‌جا نشوند.
        if (!empty($parsed['counts'])) {
            $stmtK = $pdo->prepare("SELECT request_kind FROM company_requests WHERE id = ?");
            $stmtK->execute([$requestId]);
            $curKind = $stmtK->fetchColumn() ?: 'NEW_POLICY';
            $newKind = $hadRows ? $curKind : company_kind_from_counts($parsed['counts'], $curKind);
            $pdo->prepare("UPDATE company_requests SET requested_counts = ?, request_kind = ? WHERE id = ?")
                ->execute([json_encode($parsed['counts']), $newKind, $requestId]);
        }
        $pdo->commit();

        // پوشه‌ی هر ردیفِ تازه ساخته می‌شود و وضعیتش بر اساس چک‌لیست تعیین می‌گردد
        $siteRoot = dirname(__DIR__);
        foreach ($newIds as $pid) {
            ensure_plate_folder($pdo, $siteRoot, $pid);
            company_sync_plate_status($pdo, $pid);
        }

        echo json_encode(['ok' => true, 'created' => $created, 'duplicates' => $skipped,
                          'errors' => $parsed['errors'], 'counts_fa' => $parsed['counts_fa'] ?? ''], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- فهرست متنیِ ریزِ درخواست (همان قالبی که کارفرما خواسته) ----
    if ($action === 'request_rows_text') {
        $requestId = intval($data['request_id'] ?? ($_GET['request_id'] ?? 0));
        $text = company_request_rows_text_report($pdo, $requestId);
        if ($text === null) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }
        echo json_encode(['ok' => true, 'text' => $text], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- مرحله‌ی ۱ از صدور: آپلود فایل بیمه‌نامه + OCR + اعتبارسنجی (همان روالِ
    //      «اعتبارسنجی بیمه‌نامه»ی بخش پرسنلی). اگر پلاک یا نوع بیمه‌ی فایل با این
    //      ردیف نخواند، اجازه نمی‌دهد بنشیند و خطای صریح می‌دهد. ----
    if (isset($_FILES['policy_file']) && ($_POST['action'] ?? '') === 'ocr_preview_company_policy') {
        require_admin_only();
        $plateId = intval($_POST['plate_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, cr.request_kind, c.name AS company_name FROM company_request_plates crp
                                JOIN company_requests cr ON cr.id = crp.request_id
                                JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
        $stmt->execute([$plateId]);
        $plate = $stmt->fetch();
        if (!$plate) { echo json_encode(['ok' => false, 'error' => 'ردیف یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        // مسیرِ OCR باید کاملاً انگلیسی و بی‌فاصله باشد (اسکریپت پایتون مسیر فارسی را باز نمی‌کند)
        $tempDir = $siteRoot . '/tmp_ocr';
        if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['policy_file']['name'], PATHINFO_EXTENSION)) ?: 'pdf';
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            echo json_encode(['ok' => false, 'error' => 'فقط فایل PDF یا عکس مجاز است.']); exit;
        }
        $tempPath = $tempDir . '/' . uniqid('cpolicy_') . '.' . $ext;
        if (!move_uploaded_file($_FILES['policy_file']['tmp_name'], $tempPath)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در دریافت فایل.']); exit;
        }
        // فایلِ موقتِ تلاش قبلی (اگر مانده بود) پاک می‌شود
        if ($plate['pending_policy_temp_path'] && is_file($plate['pending_policy_temp_path'])) {
            @unlink($plate['pending_policy_temp_path']);
        }

        $ocrData = null; $ocrDebug = null; $single = null;
        // ۱) همان الگوریتمِ صدورِ گروهی (جای متن در PDF): همه‌ی فیلدها + جدا کردنِ صفحه‌های فیشِ اقساط
        $lay = policy_layout_extract($pdo, $tempPath);
        if ($lay && $lay['data']) { $ocrData = $lay['data']; $single = $lay['single']; }
        // ۲) فایلِ اسکن‌شده یا عکس: موتورِ تشخیصِ قبلی
        if (!$ocrData && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $ocrResult = run_document_ocr_verbose($tempPath);
            $ocrData = $ocrResult['data'];
            $ocrDebug = $ocrResult['debug'] ?: ($lay['debug'] ?? null);
        }
        if ($ocrData) $ocrData = policy_data_aliases($ocrData);   // نام‌های قدیمی هم پر می‌شوند تا هر دو شکل کار کند

        // ---- اعتبارسنجی: فایل باید مربوط به همین ردیف باشد ----
        $expectedPlate = company_row_label($plate);
        $kind = $plate['request_kind'] ?? 'NEW_POLICY';
        if ($ocrData && ($ocrData['ins_type'] ?? '') !== 'ناشناخته' && ($ocrData['ins_type'] ?? '') !== 'معرفی‌نامه') {
            if (!empty($ocrData['plate']) && $expectedPlate && plate_core($ocrData['plate']) !== plate_core($expectedPlate)) {
                @unlink($tempPath); if ($single) cbundle_rrmdir($single['dir']);
                echo json_encode(['ok' => false, 'error' => "⚠️ پلاک این ردیف «{$expectedPlate}» است، ولی پلاک شناسایی‌شده از فایل «{$ocrData['plate']}» است. این بیمه‌نامه مربوط به این ردیف نیست."]);
                exit;
            }
            // فایلِ الحاقیه/فسخ نوعش «بدنه/ثالث» خوانده نمی‌شود، پس فقط پلاک ملاک است
            $expectedTypeFa = insurance_type_fa($plate['insurance_type']);
            if ($kind === 'NEW_POLICY' && $ocrData['ins_type'] !== $expectedTypeFa) {
                @unlink($tempPath); if ($single) cbundle_rrmdir($single['dir']);
                echo json_encode(['ok' => false, 'error' => "⚠️ این ردیف «{$expectedTypeFa}» است، ولی فایلی که بارگذاری کردید «{$ocrData['ins_type']}» تشخیص داده شد. فایل درست را بارگذاری کنید."]);
                exit;
            }
        }

        $store = $ocrData;
        if ($store && $single) $store['_single'] = $single;
        $pdo->prepare("UPDATE company_request_plates SET pending_policy_temp_path = ?, pending_policy_orig_name = ?, ocr_extracted_data = ? WHERE id = ?")
            ->execute([$tempPath, $_FILES['policy_file']['name'], $store ? json_encode($store, JSON_UNESCAPED_UNICODE) : null, $plateId]);

        echo json_encode(['ok' => true, 'ocr' => $ocrData, 'ocr_used' => (bool)$ocrData, 'ocr_debug' => $ocrDebug,
                          'source' => $single ? 'layout' : ($ocrData ? 'engine' : null), 'layout_debug' => $single ? null : ($lay['debug'] ?? null),
                          'receipts' => $single['receipts'] ?? [], 'missing' => $single['missing'] ?? [],
                          'policy_pages' => $single['policy_pages'] ?? null, 'pages' => $single['pages'] ?? null,
                          'expected_plate' => $expectedPlate], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- مرحله‌ی ۲ از صدور: ثبت صدور نهاییِ یک ردیف (فقط مدیر کل) ----
    //  پوشه‌ی ردیف به نامِ نهاییِ بیمه‌نامه («پلاک - شرکت - شماره بیمه‌نامه - VIN») تغییر
    //  نام می‌دهد، فایل بیمه‌نامه با همان نام‌گذاریِ پرسنلی داخلش ذخیره می‌شود، و یک کپیِ
    //  کاملِ پوشه به «بایگانی صادره»ی همان ماه و همان نوع بیمه می‌رود.
    // =================================================================
    //  صدورِ گروهی: فایلِ خروجیِ سایتِ بیمه‌گر (چند بیمه‌نامه در یک PDF)
    //   ۱) bundle_analyze : جدا کردنِ صفحه‌های بیمه‌نامه، شناسایی، پیدا کردنِ ردیف و آمادگی
    //   ۲) bundle_issue   : صدور و بایگانیِ ردیف‌های انتخاب‌شده
    // =================================================================
    if ($action === 'bundle_analyze') {
        require_admin_only();
        @set_time_limit(600);
        $requestId = intval($data['request_id'] ?? 0);
        $importScope = !empty($data['import']);   // «بایگانی وارداتی»: همه‌ی ردیف‌های وارداتی
        if (!$requestId && !$importScope) { echo json_encode(['ok' => false, 'error' => 'درخواست مشخص نیست.']); exit; }
        if (empty($_FILES['bundle']['tmp_name']) || !is_uploaded_file($_FILES['bundle']['tmp_name'])) {
            $code = $_FILES['bundle']['error'] ?? null;
            echo json_encode(['ok' => false, 'error' => $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? 'حجمِ فایل بیش از سقفِ مجازِ سرور است (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). در cPanel ← Select PHP Version ← Options آن را بیشتر کنید (مثلاً 64M).' : 'فایلی دریافت نشد.'], JSON_UNESCAPED_UNICODE); exit;
        }
        $name = (string)$_FILES['bundle']['name'];
        $head = (string)@file_get_contents($_FILES['bundle']['tmp_name'], false, null, 0, 5);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf' || $head !== '%PDF-') {
            echo json_encode(['ok' => false, 'error' => 'فایل باید PDF باشد (همان فایلِ خروجیِ سایتِ بیمه‌گر).'], JSON_UNESCAPED_UNICODE); exit;
        }
        $token = bin2hex(random_bytes(8));
        $dir = cbundle_root() . '/b_' . $token;
        @mkdir($dir, 0777, true);
        $src = $dir . '/source.pdf';
        if (!move_uploaded_file($_FILES['bundle']['tmp_name'], $src)) { echo json_encode(['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.']); exit; }
        $res = cbundle_run($pdo, $src, $dir . '/pages');
        if (empty($res['ok'])) {
            error_log('[bundle_analyze] ' . ($res['error'] ?? '') . ' ' . ($res['debug'] ?? ''));
            cbundle_rrmdir($dir);
            echo json_encode(['ok' => false, 'error' => ($res['error'] ?? 'پردازشِ فایل ممکن نشد.')], JSON_UNESCAPED_UNICODE); exit;
        }
        $policies = $res['policies'] ?? [];
        // صفحه‌هایی که متن ندارند (اسکن) با موتورِ OCR خوانده می‌شوند
        $ocrCount = 0;
        foreach ($policies as &$p) {
            if (!empty($p['has_text']) && is_array($p['data'])) continue;
            if ($ocrCount >= 30) { $p['ocr_error'] = 'تعدادِ صفحه‌های اسکن‌شده زیاد است (حداکثر ۳۰)'; continue; }
            $ocrCount++;
            $o = run_document_ocr_verbose($dir . '/pages/' . $p['file']);
            if ($o['data']) {
                $p['data'] = policy_data_clean($o['data'] + ['premium' => '']);
                if (empty($p['data']['policy_num']) && !empty($p['data']['policy_number'])) $p['data']['policy_num'] = $p['data']['policy_number'];
                if (empty($p['data']['premium']) && !empty($p['data']['total_premium'])) $p['data']['premium'] = $p['data']['total_premium'];
                $p['via_ocr'] = true;
                $p['missing'] = [];
                foreach (['plate' => 'پلاک', 'insured_name' => 'نام بیمه‌گذار', 'premium' => 'حق بیمه', 'policy_num' => 'شماره بیمه‌نامه'] as $fk => $fl) {
                    if (empty($p['data'][$fk]) && !($fk === 'plate' && !empty($p['data']['vin']))) $p['missing'][] = $fl;
                }
            }
            else $p['ocr_error'] = $o['debug'] ?: 'متنی خوانده نشد';
        }
        unset($p);
        $items = cbundle_match($pdo, $requestId, $policies, $importScope);
        if ($items === null) { cbundle_rrmdir($dir); echo json_encode(['ok' => false, 'error' => 'درخواست پیدا نشد.']); exit; }
        $valid = array_values(array_filter($items, fn($it) => $it['state'] !== 'unknown'));
        if (!$valid) {
            cbundle_rrmdir($dir);
            echo json_encode(['ok' => false, 'error' => 'در این فایل هیچ بیمه‌نامه‌ی معتبری شناسایی نشد (' . intval($res['pages']) . ' صفحه بررسی شد). مطمئن شوید همان فایلِ خروجیِ بیمه‌نامه‌هاست.',
                              'pages' => intval($res['pages'])], JSON_UNESCAPED_UNICODE); exit;
        }
        $meta = ['request_id' => $requestId, 'import' => $importScope ? 1 : 0, 'user_id' => intval($actor['id'] ?? 0), 'created' => time(), 'name' => $name, 'pages' => intval($res['pages']),
                 'policies' => $policies, 'items' => $items];
        file_put_contents($dir . '/analysis.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
        $cnt = array_count_values(array_map(fn($it) => $it['state'], $items));
        echo json_encode(['ok' => true, 'token' => $token, 'pages' => intval($res['pages']), 'policies' => count($items), 'counts' => $cnt, 'items' => $items,
                          'split' => $res['split'] ?? ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'bundle_recheck') {   // بعد از انجامِ مراحلِ باقی‌مانده: بررسیِ دوباره بدونِ بارگذاریِ مجدد
        require_admin_only();
        $token = preg_replace('/[^a-f0-9]/', '', (string)($data['token'] ?? ''));
        $dir = cbundle_root() . '/b_' . $token;
        $meta = $token && is_file($dir . '/analysis.json') ? json_decode((string)file_get_contents($dir . '/analysis.json'), true) : null;
        if (!$meta || empty($meta['policies'])) { echo json_encode(['ok' => false, 'error' => 'نتیجه‌ی بررسیِ فایل پیدا نشد (منقضی شده)؛ فایل را دوباره بارگذاری کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        $items = cbundle_match($pdo, intval($meta['request_id']), $meta['policies'], !empty($meta['import']));
        if ($items === null) { echo json_encode(['ok' => false, 'error' => 'درخواست پیدا نشد.']); exit; }
        $meta['items'] = $items;
        file_put_contents($dir . '/analysis.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
        $cnt = array_count_values(array_map(fn($it) => $it['state'], $items));
        echo json_encode(['ok' => true, 'token' => $token, 'pages' => intval($meta['pages'] ?? 0), 'policies' => count($items), 'counts' => $cnt, 'items' => $items,
                          'name' => $meta['name'] ?? ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'bundle_page') {   // پیش‌نمایشِ صفحه‌ی جداشده‌ی یک بیمه‌نامه
        require_admin_only();
        $token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? ''));
        $file = basename((string)($_GET['file'] ?? ''));
        $path = cbundle_root() . '/b_' . $token . '/pages/' . $file;
        if (!$token || !preg_match('/^(?:(?:pol|seg|stm)_\d{3}|rcp_\d{3}_\d{2})\.pdf$/', $file) || !is_file($path)) { http_response_code(404); exit; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    if ($action === 'bundle_issue') {
        require_admin_only();
        @set_time_limit(600);
        $token = preg_replace('/[^a-f0-9]/', '', (string)($data['token'] ?? ''));
        $dir = cbundle_root() . '/b_' . $token;
        $meta = $token && is_file($dir . '/analysis.json') ? json_decode((string)file_get_contents($dir . '/analysis.json'), true) : null;
        if (!$meta) { echo json_encode(['ok' => false, 'error' => 'نتیجه‌ی بررسیِ فایل پیدا نشد (منقضی شده)؛ فایل را دوباره بارگذاری کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        $picks = is_array($data['items'] ?? null) ? $data['items'] : [];
        $results = []; $done = 0;
        foreach ($picks as $pk) {
            $i = intval($pk['i'] ?? -1);
            $it = $meta['items'][$i] ?? null;
            if (!$it || empty($it['row']['id'])) { $results[] = ['i' => $i, 'ok' => false, 'error' => 'ردیف نامعتبر.']; continue; }
            $plateId = intval($it['row']['id']);
            // دوباره از دیتابیس: شاید در این فاصله تغییر کرده باشد
            $st = $pdo->prepare("SELECT status FROM company_request_plates WHERE id = ?");
            $st->execute([$plateId]);
            $status = $st->fetchColumn();
            if ($status === 'ISSUED') { $results[] = ['i' => $i, 'ok' => false, 'error' => 'قبلاً صادر شده.']; continue; }
            if (!in_array($status, ['READY_FOR_ISSUE', 'WITH_BOSS', 'IN_ISSUANCE'], true)) { $results[] = ['i' => $i, 'ok' => false, 'error' => 'هنوز به مرحله‌ی صدور نرسیده.']; continue; }
            if (in_array($it['state'], ['mismatch', 'duplicate', 'unknown', 'no_match'], true)) { $results[] = ['i' => $i, 'ok' => false, 'error' => $it['message'] ?: 'قابلِ صدور نیست.']; continue; }
            // ویرایش‌های کاربر روی فرمِ کارت، روی داده‌ی خوانده‌شده می‌نشیند
            if (is_array($pk['fields'] ?? null)) {
                foreach ($pk['fields'] as $fk => $fv) {
                    $fk = preg_replace('/[^a-z_]/', '', (string)$fk);
                    if ($fk === '' || !is_scalar($fv) || in_array($fk, ['plate', 'ins_type', 'ins_company'], true)) continue;
                    $it['data'][$fk] = trim((string)$fv);
                }
            }
            $policyNumber = trim((string)($pk['policy_number'] ?? ($it['data']['policy_num'] ?? '')));
            if ($policyNumber === '') { $results[] = ['i' => $i, 'ok' => false, 'error' => 'شماره‌ی بیمه‌نامه خالی است.']; continue; }
            $vin = trim((string)($pk['vin'] ?? ($it['data']['vin'] ?? '')));
            $premium = money_to_int($pk['total_premium'] ?? ($it['data']['premium'] ?? ''));
            $srcName = !empty($pk['with_payments']) ? $it['seg_file'] : $it['file'];
            $src = $dir . '/pages/' . basename($srcName);
            if (!is_file($src)) { $results[] = ['i' => $i, 'ok' => false, 'error' => 'فایلِ این بیمه‌نامه پیدا نشد.']; continue; }
            $work = dirname(__DIR__) . '/tmp_ocr/' . uniqid('cbundle_') . '.pdf';
            @copy($src, $work);
            $pdo->prepare("UPDATE company_request_plates SET ocr_extracted_data = ? WHERE id = ?")->execute([json_encode($it['data'], JSON_UNESCAPED_UNICODE), $plateId]);
            $r = company_issue_plate($pdo, $plateId, $policyNumber, $vin, $premium, $work, 'policy.pdf', $it['data']['issue_date'] ?? null);
            if (is_file($work)) @unlink($work);
            if (!empty($r['ok'])) {
                $done++;
                // خانه‌های خالیِ ردیف (موتور، نام خودرو) از روی بیمه‌نامه پر می‌شوند؛ مقدارِ موجود دست نمی‌خورد
                company_fill_from_policy($pdo, $plateId, $it['data'] ?? []);
                company_apply_policy_fields($pdo, $plateId, array_map(fn($v) => is_scalar($v) ? (string)$v : '', array_merge((array)($it['data'] ?? []), ['policy_num' => $policyNumber, 'premium' => (string)$premium, 'vin' => $vin])));
                // صفحه‌های فیشِ اقساط روی اقساطِ همین بیمه‌نامه؛ اقساط از تاریخِ صدورِ داخلِ بیمه‌نامه
                company_attach_policy_extras($pdo, $plateId, $dir . '/pages', $it['receipts'] ?? [], $it['statement_file'] ?? null, $it['data']['issue_date'] ?? '');
            }
            $results[] = ['i' => $i, 'plate_id' => $plateId] + $r;
        }
        // اگر هیچ بیمه‌نامه‌ی صادرنشده‌ای (آماده یا منتظرِ تکمیلِ مراحل) در فایل نماند، فایل‌های موقت پاک می‌شوند
        $left = 0;
        foreach ($meta['items'] as $it) if (!empty($it['row']['id']) && in_array($it['state'], ['ready', 'not_ready'], true)) {
            $st = $pdo->prepare("SELECT status FROM company_request_plates WHERE id = ?");
            $st->execute([intval($it['row']['id'])]);
            if ($st->fetchColumn() !== 'ISSUED') $left++;
        }
        if (!$left) cbundle_rrmdir($dir);
        echo json_encode(['ok' => true, 'issued' => $done, 'results' => $results, 'left' => $left], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'mark_issued') {
        require_admin_only();
        $plateId = intval($data['plate_id'] ?? 0);
        $policyNumber = trim($data['policy_number'] ?? '');
        $vin = trim($data['vin'] ?? '');
        $totalPremium = money_to_int($data['total_premium'] ?? '');
        if (!$plateId || $policyNumber === '') { echo json_encode(['ok' => false, 'error' => 'شماره‌ی بیمه‌نامه الزامی است.']); exit; }
        // فایلِ بیمه‌نامه: فایلِ موقتِ مرحله‌ی اعتبارسنجی، یا آپلودِ مستقیم
        $src = null; $srcName = null;
        $st = $pdo->prepare("SELECT pending_policy_temp_path, pending_policy_orig_name, ocr_extracted_data FROM company_request_plates WHERE id = ?");
        $st->execute([$plateId]);
        $pend = $st->fetch();
        $single = null;
        $ocrSaved = $pend ? (json_decode((string)$pend['ocr_extracted_data'], true) ?: []) : [];
        if (!empty($ocrSaved['_single']['dir']) && is_dir($ocrSaved['_single']['dir'])) $single = $ocrSaved['_single'];
        if ($pend && $pend['pending_policy_temp_path'] && is_file($pend['pending_policy_temp_path'])) {
            $src = $pend['pending_policy_temp_path']; $srcName = $pend['pending_policy_orig_name'] ?: 'policy.pdf';
        } elseif (!empty($_FILES['issued_file']['tmp_name']) && is_uploaded_file($_FILES['issued_file']['tmp_name'])) {
            $tmpDir = dirname(__DIR__) . '/tmp_ocr';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['issued_file']['name'], PATHINFO_EXTENSION)) ?: 'pdf';
            $tmp = $tmpDir . '/' . uniqid('cissued_') . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
            if (move_uploaded_file($_FILES['issued_file']['tmp_name'], $tmp)) {
                $src = $tmp; $srcName = $_FILES['issued_file']['name'];
                // فایلِ مستقیم (بی‌مرحله‌ی شناسایی) هم از همان الگوریتم می‌گذرد تا فیش‌ها و اعلامیه‌اش جدا شوند
                // (پوشه‌ی جداسازیِ تلاشِ قبلی مالِ فایلِ دیگری است)
                if ($single) { cbundle_rrmdir($single['dir']); $single = null; }
                if ($ext === 'pdf') {
                    $lay = policy_layout_extract($pdo, $tmp);
                    if ($lay && !empty($lay['single'])) {
                        $single = $lay['single'];
                        if (empty($ocrSaved)) $ocrSaved = policy_data_aliases($lay['data']);
                    }
                }
            }
        }
        // اگر فایل فیش یا اعلامیه‌ی اقساط هم داشت، فقط صفحه‌های خودِ بیمه‌نامه به‌عنوانِ فایلِ بیمه‌نامه بایگانی می‌شود
        if ($src && $single && (!empty($single['receipts']) || !empty($single['statement'])) && is_file($single['dir'] . '/' . $single['pol'])) {
            $polCopy = dirname(__DIR__) . '/tmp_ocr/' . uniqid('cpol_') . '.pdf';
            if (@copy($single['dir'] . '/' . $single['pol'], $polCopy)) { @unlink($src); $src = $polCopy; $srcName = 'policy.pdf'; }
        }
        $issueDateIn = trim(p2e_digits((string)($data['issue_date'] ?? ''))) ?: ($ocrSaved['issue_date'] ?? '');
        $res = company_issue_plate($pdo, $plateId, $policyNumber, $vin, $totalPremium, $src, $srcName, $issueDateIn);
        if (!empty($res['ok'])) {
            $issueDate = trim(p2e_digits((string)($data['issue_date'] ?? ''))) ?: ($ocrSaved['issue_date'] ?? '');
            company_attach_policy_extras($pdo, $plateId, $single['dir'] ?? '', $single['receipts'] ?? [], $single['statement'] ?? null, $issueDate);
            company_fill_from_policy($pdo, $plateId, $ocrSaved);
            // همه‌ی فیلدهای فرمِ صدور (همان‌طور که کارشناس ویرایش کرده)
            $fields = json_decode((string)($data['fields'] ?? ''), true);
            if (is_array($fields)) company_apply_policy_fields($pdo, $plateId, array_map(fn($v) => is_scalar($v) ? (string)$v : '', $fields));
            $res['receipts'] = count($single['receipts'] ?? []);
        }
        if ($single) cbundle_rrmdir($single['dir']);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  مالی شرکتی: همان منطق دو-مرحله‌ای «اول دریافت از شرکت، بعد پرداخت
    //  به پاسارگاد»ی مالی پرسنلی، ولی روی company_installments و با بایگانی
    //  فیش داخل خودِ پوشه‌ی همان درخواست (نه یک آرشیو مالی سراسری جدا)
    // =================================================================

    // ---- اقساط یک درخواست (همه‌ی پلاک‌هایش) + مانده‌ی دریافتی/تسویه‌شده ----
    if ($action === 'list_company_installments') {
        $requestId = intval($data['request_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT ci.*, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4,
                   COALESCE((SELECT SUM(cpa.amount) FROM company_payment_allocations cpa
                             JOIN company_payments cp ON cp.id = cpa.payment_id
                             WHERE cpa.installment_id = ci.id AND cp.target = 'US'), 0) AS collected,
                   COALESCE((SELECT SUM(cpa.amount) FROM company_payment_allocations cpa
                             JOIN company_payments cp ON cp.id = cpa.payment_id
                             WHERE cpa.installment_id = ci.id AND cp.target = 'PASARGAD'), 0) AS settled_amount
            FROM company_installments ci
            JOIN company_request_plates crp ON crp.id = ci.plate_id
            WHERE crp.request_id = ?
            ORDER BY ci.due_date ASC
        ");
        $stmt->execute([$requestId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['collected'] = intval($r['collected']);
            $r['settled_amount'] = intval($r['settled_amount']);
            $r['plate_display'] = company_row_label($r);
        }
        echo json_encode(['ok' => true, 'installments' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ثبت دریافت از شرکت (مرحله‌ی اول) + فیش، تخصیص خودکار به اقساط این درخواست ----
    if (isset($_FILES['company_receipts']) || ($_POST['action'] ?? '') === 'create_company_payment') {
        $requestId = intval($_POST['request_id'] ?? 0);
        $amount = intval(money_to_int($_POST['amount'] ?? '0'));
        $method = in_array($_POST['method'] ?? '', ['TRANSFER','CHEQUE','CASH','PAYROLL'], true) ? $_POST['method'] : 'TRANSFER';
        if (!$requestId || $amount <= 0) { echo json_encode(['ok' => false, 'error' => 'درخواست و مبلغ الزامی است.']); exit; }

        $stmt = $pdo->prepare("SELECT cr.created_at, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) { echo json_encode(['ok' => false, 'error' => 'درخواست یافت نشد.']); exit; }

        $siteRoot = dirname(__DIR__);
        $receipts = [];
        if (!empty($_FILES['company_receipts'])) {
            $dir = company_request_finance_folder($siteRoot, strtotime($req['created_at']), $req['company_name'], 'US');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $files = $_FILES['company_receipts'];
            $n = is_array($files['name']) ? count($files['name']) : 0;
            for ($i = 0; $i < $n; $i++) {
                if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
                $base = sanitize_folder_name(p2e_digits(trim($_POST['paid_jalali'] ?? date('Y-m-d'))) . ' - ' . trim($_POST['reference_no'] ?? 'فیش') . ' - ' . number_format($amount));
                $dest = unique_dest_path($dir . '/' . $base . '.' . $ext);
                if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                    if (in_array($ext, ['jpg','jpeg','png','webp'])) compress_image_if_needed($dest);
                    $receipts[] = ltrim(str_replace($siteRoot, '', $dest), '/');
                }
            }
        }

        $pdo->prepare("INSERT INTO company_payments (request_id, target, amount, method, paid_jalali, paid_at, reference_no, receipts, note, created_by)
                       VALUES (?, 'US', ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$requestId, $amount, $method, p2e_digits(trim($_POST['paid_jalali'] ?? '')),
                       fin_jalali_to_date($_POST['paid_jalali'] ?? ''),
                       trim($_POST['reference_no'] ?? ''), $receipts ? json_encode($receipts, JSON_UNESCAPED_UNICODE) : null,
                       trim($_POST['note'] ?? ''), $actor['user_id']]);
        $paymentId = $pdo->lastInsertId();

        // تخصیص خودکار به اقساطِ باز همین درخواست، به ترتیب سررسید
        $stmt = $pdo->prepare("
            SELECT ci.id, ci.amount, COALESCE((SELECT SUM(cpa.amount) FROM company_payment_allocations cpa
                   JOIN company_payments cp ON cp.id = cpa.payment_id
                   WHERE cpa.installment_id = ci.id AND cp.target = 'US'), 0) AS collected
            FROM company_installments ci JOIN company_request_plates crp ON crp.id = ci.plate_id
            WHERE crp.request_id = ? ORDER BY ci.due_date ASC");
        $stmt->execute([$requestId]);
        $openInstallments = $stmt->fetchAll();

        $remaining = $amount;
        $allocStmt = $pdo->prepare("INSERT INTO company_payment_allocations (payment_id, installment_id, amount) VALUES (?, ?, ?)");
        foreach ($openInstallments as $inst) {
            if ($remaining <= 0) break;
            $due = intval($inst['amount']) - intval($inst['collected']);
            if ($due <= 0) continue;
            $alloc = min($due, $remaining);
            $allocStmt->execute([$paymentId, $inst['id'], $alloc]);
            $remaining -= $alloc;
        }

        echo json_encode(['ok' => true, 'payment_id' => $paymentId, 'allocated' => $amount - $remaining, 'unallocated' => $remaining], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ثبت تسویه با پاسارگاد (مرحله‌ی دوم) - فقط اقساطِ کاملاً وصول‌شده (طبق همون قانون مالی پرسنلی) ----
    if (isset($_FILES['company_pasargad_receipts']) || ($_POST['action'] ?? '') === 'settle_company_pasargad') {
        $ids = array_map('intval', json_decode($_POST['installment_ids'] ?? '[]', true) ?: []);
        if (!$ids) { echo json_encode(['ok' => false, 'error' => 'قسطی انتخاب نشده است.']); exit; }

        $collectRule = fin_settings($pdo)['rule_collect_before_pay'] === '1';
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT ci.id, ci.amount, ci.plate_id, crp.request_id,
                   COALESCE((SELECT SUM(cpa.amount) FROM company_payment_allocations cpa
                             JOIN company_payments cp ON cp.id = cpa.payment_id
                             WHERE cpa.installment_id = ci.id AND cp.target = 'US'), 0) AS collected
            FROM company_installments ci JOIN company_request_plates crp ON crp.id = ci.plate_id
            WHERE ci.id IN ($place) AND ci.settled_to_pasargad = 0");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        $ok = []; $blocked = 0; $total = 0; $requestId = null;
        foreach ($rows as $r) {
            if ($collectRule && intval($r['collected']) < intval($r['amount'])) { $blocked++; continue; }
            $ok[] = $r['id']; $total += intval($r['amount']); $requestId = $r['request_id'];
        }
        if (!$ok) { echo json_encode(['ok' => false, 'error' => 'هیچ قسطی قابل تسویه نبود (قانون «اول دریافت، بعد پرداخت» فعال است).']); exit; }

        $stmt = $pdo->prepare("SELECT cr.created_at, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();

        $siteRoot = dirname(__DIR__);
        $receipts = [];
        if (!empty($_FILES['company_pasargad_receipts'])) {
            $dir = company_request_finance_folder($siteRoot, strtotime($req['created_at']), $req['company_name'], 'PASARGAD');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $files = $_FILES['company_pasargad_receipts'];
            $n = is_array($files['name']) ? count($files['name']) : 0;
            for ($i = 0; $i < $n; $i++) {
                if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
                $base = sanitize_folder_name(p2e_digits(trim($_POST['paid_jalali'] ?? date('Y-m-d'))) . ' - ' . trim($_POST['reference_no'] ?? 'فیش') . ' - ' . number_format($total));
                $dest = unique_dest_path($dir . '/' . $base . '.' . $ext);
                if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                    if (in_array($ext, ['jpg','jpeg','png','webp'])) compress_image_if_needed($dest);
                    $receipts[] = ltrim(str_replace($siteRoot, '', $dest), '/');
                }
            }
        }

        $pdo->prepare("INSERT INTO company_payments (request_id, target, amount, method, paid_jalali, paid_at, reference_no, receipts, note, created_by)
                       VALUES (?, 'PASARGAD', ?, 'TRANSFER', ?, ?, ?, ?, ?, ?)")
            ->execute([$requestId, $total, p2e_digits(trim($_POST['paid_jalali'] ?? '')),
                       fin_jalali_to_date($_POST['paid_jalali'] ?? ''),
                       trim($_POST['reference_no'] ?? ''), $receipts ? json_encode($receipts, JSON_UNESCAPED_UNICODE) : null,
                       trim($_POST['note'] ?? ''), $actor['user_id']]);
        $paymentId = $pdo->lastInsertId();

        $insLine = $pdo->prepare("INSERT INTO company_payment_allocations (payment_id, installment_id, amount) VALUES (?, ?, ?)");
        $mark = $pdo->prepare("UPDATE company_installments SET settled_to_pasargad = 1 WHERE id = ?");
        foreach ($rows as $r) {
            if (!in_array($r['id'], $ok, true)) continue;
            $insLine->execute([$paymentId, $r['id'], intval($r['amount'])]);
            $mark->execute([$r['id']]);
        }

        echo json_encode(['ok' => true, 'settled' => count($ok), 'blocked' => $blocked, 'total' => $total], JSON_UNESCAPED_UNICODE);
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
            $plateDisplay = company_row_label($r);
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

    // ---- دانلود زیپِ یک پوشه‌ی مدرکِ چندفایلی (مثلاً پوشه‌ی استخراج‌شده‌ی گزارش بازدید) ----
    if ($action === 'download_folder_zip') {
        $docId = intval($_GET['doc_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT file_path, orig_name FROM company_documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        $siteRoot = dirname(__DIR__);
        $absPath = $doc ? $siteRoot . '/' . $doc['file_path'] : null;
        if (!$doc || !is_dir($absPath)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'پوشه یافت نشد.']); exit; }

        $zipPath = company_zip_folder($absPath);
        if (!$zipPath) { echo json_encode(['ok' => false, 'error' => 'خطا در ساخت فایل زیپ.']); exit; }
        $downloadName = sanitize_folder_name(pathinfo($doc['orig_name'] ?: 'گزارش', PATHINFO_FILENAME)) . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
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
