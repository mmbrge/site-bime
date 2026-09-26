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

// اگر مایگریشنِ لازم اجرا نشده باشد، به‌جای «خطای سرور» پیامِ روشن بده
$schemaProblem = company_schema_problem($pdo);
if ($schemaProblem) { echo json_encode(['ok' => false, 'error' => $schemaProblem], JSON_UNESCAPED_UNICODE); exit; }

// اطمینان از وجود پوشه‌ی ریشه‌ی «بایگانی شرکتی» تا همیشه در بایگانی فایل‌ها دیده شود
@mkdir(company_archive_root(dirname(__DIR__)), 0775, true);

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

function jd($ts) { return $ts ? jalali_from_gregorian_ts_dotted($ts) : null; }

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

    // ---- فیدِ اعلان‌ها: هر چیزِ تازه‌ای که از آخرین بررسی تا حالا اتفاق افتاده ----
    //  عمداً جدولِ جدیدی برای رویدادها ساخته نشده؛ همه‌چیز از روی created_at خودِ
    //  جدول‌های موجود خوانده می‌شود تا نه مهاجرتِ تازه‌ای لازم باشد و نه داده‌ی اضافه
    //  انباشته شود. کلاینت آخرین زمانِ دیده‌شده را می‌فرستد و همان را دوباره می‌گیرد.
    if ($action === 'notifications_feed') {
        $since = trim($data['since'] ?? '');
        // اولین بار: فقط از همین لحظه به بعد، تا انبوهی از اعلانِ قدیمی نریزد
        if ($since === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            echo json_encode(['ok' => true, 'now' => date('Y-m-d H:i:s'), 'events' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $now = date('Y-m-d H:i:s');
        $events = [];

        $add = function ($type, $title, $body, $at, $tab = null, $extra = []) use (&$events) {
            $events[] = array_merge(['type' => $type, 'title' => $title, 'body' => $body,
                                     'at' => $at, 'at_jalali' => jd(strtotime($at)), 'tab' => $tab], $extra);
        };

        // درخواست‌های تازه‌ی شرکت‌ها
        $stmt = $pdo->prepare("SELECT cr.id, cr.created_at, c.name AS company_name FROM company_requests cr
                                JOIN companies c ON c.id = cr.company_id
                                WHERE cr.created_at > ? ORDER BY cr.created_at DESC LIMIT 20");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $r) {
            $add('company_request', 'درخواست جدید شرکتی', $r['company_name'] . ' یک درخواست تازه ثبت کرد.',
                 $r['created_at'], 'companies-requests', ['request_id' => intval($r['id'])]);
        }

        // مدارکِ تازه‌ای که شرکت‌ها فرستاده‌اند و هنوز تگ نخورده‌اند
        $stmt = $pdo->prepare("SELECT cd.id, cd.request_id, cd.orig_name, cd.doc_type, cd.uploaded_at, c.name AS company_name
                                FROM company_documents cd JOIN companies c ON c.id = cd.company_id
                                WHERE cd.uploaded_at > ? AND cd.uploaded_by IS NOT NULL
                                ORDER BY cd.uploaded_at DESC LIMIT 20");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $r) {
            $add('company_doc', 'مدرک جدید از شرکت',
                 $r['company_name'] . ' یک «' . company_doc_type_label($r['doc_type']) . '» فرستاد.',
                 $r['uploaded_at'], 'companies-inbox', ['request_id' => intval($r['request_id'])]);
        }

        // پیام‌های تازه‌ی شرکت‌ها در چت
        $stmt = $pdo->prepare("SELECT ccm.id, ccm.company_id, ccm.message, ccm.created_at, c.name AS company_name
                                FROM company_chat_messages ccm JOIN companies c ON c.id = ccm.company_id
                                WHERE ccm.created_at > ? AND ccm.sender_type = 'COMPANY'
                                ORDER BY ccm.created_at DESC LIMIT 20");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $r) {
            $add('company_chat', 'پیام جدید از ' . $r['company_name'],
                 mb_substr((string)($r['message'] ?: 'یک فایل فرستاد'), 0, 90), $r['created_at'], 'companies-requests',
                 ['company_id' => intval($r['company_id'])]);
        }

        // پرونده‌های تازه‌ی بیمه‌ی کارکنان
        try {
            $stmt = $pdo->prepare("SELECT pc.id, pc.plate, pc.insurance_type, pc.created_at, p.full_name
                                    FROM policy_cases pc LEFT JOIN persons p ON p.id = pc.person_id
                                    WHERE pc.created_at > ? ORDER BY pc.created_at DESC LIMIT 20");
            $stmt->execute([$since]);
            foreach ($stmt->fetchAll() as $r) {
                $add('case', 'درخواست جدید بیمه کارکنان',
                     ($r['full_name'] ?: 'کاربر') . ' - ' . insurance_type_fa($r['insurance_type']) . ' ' . ($r['plate'] ?: ''),
                     $r['created_at'], 'records', ['case_id' => intval($r['id'])]);
            }
        } catch (Throwable $e) { /* اگر ستونی نبود، بقیه‌ی اعلان‌ها نباید بخوابند */ }

        // پیام‌های داخلیِ تازه برای خودم
        try {
            $stmt = $pdo->prepare("SELECT scm.id, scm.message, scm.created_at, u.full_name
                                    FROM staff_chat_messages scm LEFT JOIN users u ON u.id = scm.from_user_id
                                    WHERE scm.created_at > ? AND scm.to_user_id = ? ORDER BY scm.created_at DESC LIMIT 20");
            $stmt->execute([$since, $actor['user_id']]);
            foreach ($stmt->fetchAll() as $r) {
                $add('staff_chat', 'پیام داخلی از ' . ($r['full_name'] ?: 'همکار'),
                     mb_substr((string)($r['message'] ?: 'یک فایل فرستاد'), 0, 90), $r['created_at'], 'tickets');
            }
        } catch (Throwable $e) { /* ... */ }

        // تیکت‌های تازه‌ی مشتری‌ها
        try {
            $stmt = $pdo->prepare("SELECT tm.id, tm.message, tm.created_at FROM ticket_messages tm
                                    WHERE tm.created_at > ? AND tm.sender_type <> 'ADMIN' ORDER BY tm.created_at DESC LIMIT 10");
            $stmt->execute([$since]);
            foreach ($stmt->fetchAll() as $r) {
                $add('ticket', 'پیام جدید در گفتگوها', mb_substr((string)($r['message'] ?: 'پیام تازه'), 0, 90), $r['created_at'], 'tickets');
            }
        } catch (Throwable $e) { /* ... */ }

        usort($events, fn($a, $b) => strcmp($a['at'], $b['at']));
        echo json_encode(['ok' => true, 'now' => $now, 'events' => array_slice($events, -25)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  «لیست صدور»: همه‌ی ردیف‌هایی که هنوز صادر نشده‌اند، از همه‌ی شرکت‌ها،
    //  با اطلاعات کامل و وضعیتِ چک‌لیستِ هرکدام. ردیف پس از صدور خودبه‌خود از
    //  این فهرست بیرون می‌رود (چون فقط status <> 'ISSUED' می‌آید).
    // =================================================================
    if ($action === 'issue_queue') {
        $src = $data['source'] ?? 'ALL';   // ALL | COMPANY | PERSONNEL
        $out = []; $ready = 0; $waiting = 0;
        $readyFilter = $data['readiness'] ?? '';   // '' | READY | WAITING
        $missingFilter = trim($data['missing_doc'] ?? '');
        $q = trim($data['q'] ?? '');
        $typeF = $data['insurance_type'] ?? '';
        $expFrom = fin_jalali_to_date($data['expiry_from'] ?? '');
        $expTo   = fin_jalali_to_date($data['expiry_to'] ?? '');

        $where = ["crp.status <> 'ISSUED'", "crp.status <> 'CANCELLED'"];
        $params = [];

        if (!empty($data['company_id']))     { $where[] = "cr.company_id = ?";   $params[] = intval($data['company_id']); }
        if ($typeF)                          { $where[] = "crp.insurance_type = ?"; $params[] = $typeF; }
        if (!empty($data['request_kind']))   { $where[] = "cr.request_kind = ?";  $params[] = $data['request_kind']; }
        if (!empty($data['insurer']))        { $where[] = "cr.insurer = ?";       $params[] = $data['insurer']; }
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

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(c.name LIKE ? OR crp.chassis_no LIKE ? OR crp.car_name LIKE ? OR crp.ref_policy_number LIKE ?
                         OR CONCAT_WS(' ', crp.plate_p4, crp.plate_letter, crp.plate_p2, crp.plate_p1) LIKE ?
                         OR cr.request_text LIKE ? OR cr.id = ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, intval($q));
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

            $r['checklist'] = company_plate_checklist($r['insurance_type'], (bool)$r['skip_health_inspection'], $types, $r['has_prev_body'], $kind);
            foreach ($r['checklist'] as &$item) {
                $item['docs'] = array_values(array_filter($rowDocs,
                    fn($d) => in_array($d['doc_type'], $item['upload_types'], true) || $d['doc_type'] === $item['key']));
            }
            unset($item);
            $r['missing_docs'] = company_plate_missing_docs($r['insurance_type'], (bool)$r['skip_health_inspection'], $types, $r['has_prev_body'], $kind);
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
            $out[] = $r;
        }

        // ---------- پرونده‌های صدورِ کارکنان، در همین فهرست ----------
        // بیمه‌ی کارکنان بخشِ «همکارِ شرکت‌ها» نیست؛ فقط مدیر کل آن را می‌بیند.
        if ($src !== 'COMPANY' && ($actor['role'] ?? '') === 'ADMIN') {
            $pw = ["pc.status <> 'ISSUED'", "pc.status <> 'CANCELLED'"]; $pp = [];
            if ($typeF) { $pw[] = "pc.insurance_type = ?"; $pp[] = $typeF; }
            // «نوع درخواست» برای کارکنان همیشه صدور جدید است؛ اگر فیلترِ دیگری خورده، چیزی نیاید
            if (!empty($data['request_kind']) && $data['request_kind'] !== 'NEW_POLICY') $pw[] = "1=0";
            if (!empty($data['company_id'])) { $pw[] = "per.company_id = ?"; $pp[] = intval($data['company_id']); }
            if ($expFrom || $expTo) $pw[] = "1=0";  // پرونده‌ی کارکنان تاریخ انقضا ندارد
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
                    ];
                }
            } catch (Throwable $e) {
                error_log('[issue_queue personnel] ' . $e->getMessage());
            }
        }

        echo json_encode(['ok' => true, 'rows' => $out,
                          'counts' => ['total' => count($out), 'ready' => $ready, 'waiting' => $waiting],
                          'doc_types' => company_doc_types()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  «صادره‌ها»: یک جدولِ یکپارچه از بیمه‌نامه‌های صادرشده‌ی شرکتی *و* پرسنلی.
    //  با source می‌شود فقط شرکتی، فقط پرسنلی، یا هر دو را دید. همه‌ی اطلاعاتی
    //  که داریم - از جمله چیزهایی که از خودِ فایل بیمه‌نامه شناسایی شده - می‌آید.
    //  همین اکشن خروجی اکسل هم می‌دهد (export=1).
    // =================================================================
    if ($action === 'issued_list' || ($_GET['action'] ?? '') === 'issued_list') {
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
            $stmt = $pdo->prepare("
                SELECT crp.*, cr.request_kind, cr.insurer, cr.created_at AS request_created_at, cr.request_text,
                       c.name AS company_name, c.economic_code, c.phone AS company_phone
                  FROM company_request_plates crp
                  JOIN company_requests cr ON cr.id = crp.request_id
                  JOIN companies c ON c.id = cr.company_id
                 WHERE " . implode(' AND ', $w) . " ORDER BY crp.issued_at DESC LIMIT 5000");
            $stmt->execute($p);
            foreach ($stmt->fetchAll() as $r) {
                $kind = $r['request_kind'] ?? 'NEW_POLICY';
                $rows[] = [
                    'source' => 'COMPANY', 'source_fa' => 'شرکتی',
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
                    'total_premium' => $r['total_premium'], 'insurer' => $r['insurer'],
                    'request_date' => $r['request_created_at'], 'request_date_jalali' => jd(strtotime($r['request_created_at'])),
                    'expiry_date_jalali' => $r['expiry_date'] ? jd(strtotime($r['expiry_date'])) : null,
                    'issued_at' => $r['issued_at'], 'issued_at_jalali' => $r['issued_at'] ? jd(strtotime($r['issued_at'])) : null,
                    'status_fa' => company_plate_status_fa('ISSUED', $kind),
                    'folder_status' => $r['folder_status'], 'issued_file_path' => $r['issued_file_path'],
                ];
            }
        }

        // ---------- صادره‌های پرسنلی ----------
        // مثل فهرست صدور: بیمه‌ی کارکنان را فقط مدیر کل می‌بیند.
        if ($src !== 'COMPANY' && ($actor['role'] ?? '') === 'ADMIN') {
            $w = ["pc.status = 'ISSUED'"]; $p = [];
            if ($from)  { $w[] = "DATE(pc.issued_at) >= ?"; $p[] = $from; }
            if ($to)    { $w[] = "DATE(pc.issued_at) <= ?"; $p[] = $to; }
            if ($typeF) { $w[] = "pc.insurance_type = ?";   $p[] = $typeF; }
            // نوع درخواست برای پرسنلی همیشه «صدور بیمه‌نامه‌ی جدید» است
            if ($kindF && $kindF !== 'NEW_POLICY') { $w[] = "1=0"; }
            try {
                $stmt = $pdo->prepare("
                    SELECT pc.*, per.full_name AS holder_name, per.national_id AS holder_nid, per.mobile_number,
                           co.name AS employer_name
                      FROM policy_cases pc
                      LEFT JOIN persons per ON per.id = pc.person_id
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
                        'status_fa' => 'صادر شد',
                        'folder_status' => null, 'issued_file_path' => $r['issued_file_path'],
                        'unique_code' => $r['unique_code'] ?? null, 'central_unique_code' => $r['central_unique_code'] ?? null,
                        'policy_issue_date' => $r['policy_issue_date'] ?? null,
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

        // ---------- شمارش‌ها روی همین فیلترها ----------
        $counts = ['total' => count($rows), 'company' => 0, 'personnel' => 0, 'body' => 0, 'third' => 0, 'premium' => 0];
        foreach ($rows as $r) {
            $r['source'] === 'COMPANY' ? $counts['company']++ : $counts['personnel']++;
            $r['insurance_type'] === 'BODY' ? $counts['body']++ : $counts['third']++;
            $counts['premium'] += intval($r['total_premium']);
        }

        // ---------- خروجی اکسل ----------
        if ($isExport) {
            require_once __DIR__ . '/_xlsx_writer.php';
            $headers = ['ردیف', 'منبع', 'نوع درخواست', 'شرکت / کارفرما', 'بیمه‌گذار', 'کد ملی / اقتصادی', 'تلفن',
                        'پلاک', 'شماره شاسی', 'شماره موتور', 'نوع بیمه', 'بیمه‌گر', 'شماره بیمه‌نامه',
                        'شماره بیمه‌نامه مرجع', 'خودرو', 'سیستم', 'تیپ', 'مدل', 'رنگ', 'کاربری', 'VIN',
                        'ارزش خودرو (ریال)', 'تعهد مالی (ریال)', 'حق بیمه (ریال)',
                        'تاریخ درخواست', 'تاریخ انقضا', 'تاریخ صدور', 'وضعیت', 'توضیح درخواست'];
            $out = [];
            foreach ($rows as $i => $r) {
                $out[] = [
                    $i + 1, $r['source_fa'], $r['request_kind_fa'], $r['company_name'], $r['insured_name'],
                    $r['national_id'], $r['phone'], $r['plate'], $r['chassis_no'], $r['engine_no'],
                    $r['insurance_type_fa'], ($r['insurer'] === 'IRAN' ? 'ایران' : 'پاسارگاد'),
                    $r['policy_number'], $r['ref_policy_number'], $r['car_name'], $r['car_system'], $r['car_type'],
                    $r['car_model_year'], $r['car_color'], $r['car_usage'], $r['vin'],
                    $r['car_value'], $r['liability_limit'], $r['total_premium'],
                    fa_digits($r['request_date_jalali']), fa_digits($r['expiry_date_jalali']), fa_digits($r['issued_at_jalali']), $r['status_fa'],
                    $r['endorsement_request'] ?: ($r['cancellation_reason'] ?: $r['request_text']),
                ];
            }
            $rangeFa = ($data['issued_from'] ?? ($_GET['issued_from'] ?? '')) ?: 'ابتدا';
            $rangeTo = ($data['issued_to'] ?? ($_GET['issued_to'] ?? '')) ?: 'امروز';
            $path = xlsx_build($headers, $out, 'صادره‌ها', [0, 21, 22, 23]);
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
        $stmt = $pdo->query("SELECT c.*, parent.name AS parent_name FROM companies c
                              LEFT JOIN companies parent ON parent.id = c.parent_id
                              WHERE c.kind IN ('INSURANCE_CLIENT','BOTH') ORDER BY c.name");
        $companiesList = $stmt->fetchAll();
        foreach ($companiesList as &$c) { $c['created_at_jalali'] = $c['created_at'] ? jd(strtotime($c['created_at'])) : null; }
        echo json_encode(['ok' => true, 'companies' => $companiesList], JSON_UNESCAPED_UNICODE);
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

        if ($action === 'update_company') {
            $companyId = intval($data['id'] ?? 0);
            if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت نامعتبر است.']); exit; }
            if ($parentId === $companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت نمی‌تواند زیرمجموعه‌ی خودش باشد.']); exit; }
            $pdo->prepare("UPDATE companies SET name=?, payment_terms=?, economic_code=?, address=?, phone=?, parent_id=?,
                            bale_group_chat_id=?, allowed_insurers=?, installment_count=?, first_due_offset_months=?, first_due_offset_days=? WHERE id=?")
                ->execute([$name, $paymentTerms, $economicCode, $address, $phone, $parentId, $groupChatId, $allowedInsurers, $instCount, $offsetMonths, $offsetDays, $companyId]);
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
            echo json_encode(['ok' => true, 'company_id' => $existing['id']]);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO companies (name, kind, payment_terms, economic_code, address, phone, parent_id,
                                bale_group_chat_id, allowed_insurers, installment_count, first_due_offset_months, first_due_offset_days)
                                VALUES (?, 'INSURANCE_CLIENT', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $paymentTerms, $economicCode, $address, $phone, $parentId, $groupChatId, $allowedInsurers, $instCount, $offsetMonths, $offsetDays]);
        echo json_encode(['ok' => true, 'company_id' => $pdo->lastInsertId()]);
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

        $pdo->beginTransaction();
        if ($password !== '') {
            $pdo->prepare("UPDATE company_portal_users SET username = ?, full_name = ?, mobile_number = ?, password_hash = ? WHERE id = ?")
                ->execute([$username, $fullName, trim($data['mobile_number'] ?? '') ?: null, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $pdo->prepare("UPDATE company_portal_users SET username = ?, full_name = ?, mobile_number = ? WHERE id = ?")
                ->execute([$username, $fullName, trim($data['mobile_number'] ?? '') ?: null, $id]);
        }
        $pdo->prepare("DELETE FROM company_portal_user_companies WHERE portal_user_id = ?")->execute([$id]);
        $stmt = $pdo->prepare("INSERT INTO company_portal_user_companies (portal_user_id, company_id) VALUES (?, ?)");
        foreach ($companyIds as $cid) $stmt->execute([$id, $cid]);
        $pdo->commit();

        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- حذف حساب کاربری یک ثبت‌کننده ----
    if ($action === 'delete_portal_user') {
        require_admin_only(); // مدیریت حساب‌های ورودِ پنل شرکتی فقط دست مدیر کل است
        $id = intval($data['id'] ?? 0);
        $pdo->prepare("DELETE FROM company_portal_users WHERE id = ?")->execute([$id]);
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
        $stmt = $pdo->query("SELECT cpu.id, cpu.username, cpu.full_name, cpu.mobile_number, cpu.is_active,
                                     GROUP_CONCAT(c.name SEPARATOR '، ') AS company_names,
                                     GROUP_CONCAT(c.id) AS company_ids
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
        $sql = "SELECT cr.*, c.name AS company_name,
                       (SELECT COUNT(*) FROM company_documents cd WHERE cd.request_id = cr.id AND cd.status = 'UNASSIGNED') AS pending_docs_count,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type = 'BODY') AS body_count,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type <> 'BODY') AS third_count,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type = 'BODY' AND crp.status = 'ISSUED') AS body_issued,
                       (SELECT COUNT(*) FROM company_request_plates crp WHERE crp.request_id = cr.id AND crp.insurance_type <> 'BODY' AND crp.status = 'ISSUED') AS third_issued
                FROM company_requests cr JOIN companies c ON c.id = cr.company_id";
        $params = [];
        if ($statusFilter !== '') { $sql .= " WHERE cr.status = ?"; $params[] = $statusFilter; }
        $sql .= " ORDER BY cr.created_at DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['created_at_jalali'] = jd(strtotime($r['created_at']));
            $r['updated_at_jalali'] = jd(strtotime($r['updated_at']));
            foreach (['pending_docs_count', 'body_count', 'third_count', 'body_issued', 'third_issued'] as $k) $r[$k] = intval($r[$k]);
            $r['request_kind_fa'] = company_request_kind_fa($r['request_kind'] ?? 'NEW_POLICY');
        }
        echo json_encode(['ok' => true, 'requests' => $rows], JSON_UNESCAPED_UNICODE);
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
        $stmt = $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, request_kind, status) VALUES (?, NULL, ?, ?, ?, 'NEW')");
        $stmt->execute([$companyId, $requestText ?: null, $insurer, $kind]);
        echo json_encode(['ok' => true, 'request_id' => $pdo->lastInsertId()]);
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
        // ردیف یا پلاک دارد یا شماره شاسی (لیفتراک و خودروی صفرکیلومتر پلاک ندارند)
        if ($p1 === '' && $p2 === '' && $letter === '' && $p4 === '' && $chassis === '') {
            echo json_encode(['ok' => false, 'error' => 'یا پلاک را کامل وارد کنید یا شماره شاسی را.']); exit;
        }

        $stmt = $pdo->prepare("INSERT INTO company_request_plates
            (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle,
             car_value, liability_limit, ref_policy_number, endorsement_request, cancellation_reason,
             insurance_type, expiry_date, skip_health_inspection, car_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$requestId, $p1 ?: null, $p2 ?: null, $letter ?: null, $p4 ?: null,
                        $chassis ?: null, trim($data['engine_no'] ?? '') ?: null, $isNew,
                        company_parse_money($data['car_value'] ?? ''), company_parse_money($data['liability_limit'] ?? ''),
                        trim($data['ref_policy_number'] ?? '') ?: null,
                        trim($data['endorsement_request'] ?? '') ?: null,
                        trim($data['cancellation_reason'] ?? '') ?: null,
                        $insuranceType, $expiryDate, $skipHealth, trim($data['car_name'] ?? '') ?: null]);
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
                                WHERE cd.request_id = ? AND cd.status = 'UNASSIGNED' ORDER BY cd.uploaded_at ASC");
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
            $p['status_fa'] = company_plate_status_fa($p['status'], $reqKind);
            // چک‌لیستِ کاملِ همین ردیف: هر آیتم با تیک/ضربدر، اجباری یا اختیاری، و
            // مدرکِ متناظرش (اگر موجود است) تا در جدول قابل باز کردن باشد
            $p['checklist'] = company_plate_checklist($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes, $p['has_prev_body'] ?? null, $reqKind);
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
            $p['missing_docs'] = company_plate_missing_docs($p['insurance_type'], (bool)$p['skip_health_inspection'], $assignedTypes, $p['has_prev_body'] ?? null, $reqKind);
            $p['expiry_date_jalali'] = $p['expiry_date'] ? jd(strtotime($p['expiry_date'])) : null;
            $p['issued_at_jalali'] = $p['issued_at'] ? jd(strtotime($p['issued_at'])) : null;
            $stmtInst = $pdo->prepare("SELECT * FROM company_installments WHERE plate_id = ? ORDER BY inst_number");
            $stmtInst->execute([$p['id']]);
            $p['installments'] = $stmtInst->fetchAll();
        }

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
                          'request_kind' => $reqKind, 'cancellation_reasons' => company_cancellation_reasons()], JSON_UNESCAPED_UNICODE);
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
        if ($doc['file_kind'] === 'LETTER') {
            $ext = strtolower(pathinfo($absOld, PATHINFO_EXTENSION)) ?: 'pdf';
            $newRelPath = company_place_letter($pdo, $siteRoot, $requestId, $absOld, $ext) ?: $doc['file_path'];
            $pdo->prepare("UPDATE company_documents SET request_id = ?, doc_type = 'letter', status = 'ASSIGNED', assigned_by = ?, assigned_at = NOW(), file_path = ? WHERE id = ?")
                ->execute([$requestId, $actor['user_id'], $newRelPath, $docId]);
            $pdo->prepare("UPDATE company_requests SET letter_file_path = ?, status = IF(status = 'NEW', 'DOCS_REVIEW', status) WHERE id = ?")
                ->execute([$newRelPath, $requestId]);
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

        // نوع بیمه/انقضا در نامِ پوشه‌ی ردیف هست، پس پوشه هم باید هم‌نام شود
        ensure_plate_folder($pdo, dirname(__DIR__), $plateId);
        $status = company_sync_plate_status($pdo, $plateId);
        echo json_encode(['ok' => true, 'status' => $status], JSON_UNESCAPED_UNICODE);
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
                          'source' => $parsed['source']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- درجِ نهاییِ ردیف‌ها روی یک درخواست (بعد از دیدنِ پیش‌نمایش) ----
    if ($action === 'import_letter_rows' || ($_POST['action'] ?? '') === 'import_letter_rows') {
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
                               skip_health_inspection, has_prev_body, car_name, row_note)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $created = 0; $skipped = 0; $newIds = [];
        foreach ($parsed['rows'] as $r) {
            $find->execute([$requestId, $r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4'],
                            $r['chassis_no'], $r['insurance_type']]);
            if ($find->fetchColumn()) { $skipped++; continue; } // ردیفِ تکراری دوباره ساخته نمی‌شود
            $ins->execute([$requestId, $r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4'],
                           $r['chassis_no'], $r['engine_no'], $r['is_new_vehicle'], $r['car_value'], $r['liability_limit'],
                           $r['ref_policy_number'], $r['endorsement_request'], $r['cancellation_reason'],
                           $r['insurance_type'], $r['expiry_date'], $r['skip_health_inspection'],
                           $r['has_prev_body'], $r['car_name'], $r['row_note']]);
            $newIds[] = $pdo->lastInsertId();
            $created++;
        }
        // متنِ مرجعِ استخراج‌شده نگه داشته می‌شود تا بعداً بشود با خودِ نامه مقایسه کرد
        $pdo->prepare("UPDATE company_requests SET letter_parsed_json = ?, status = IF(status = 'NEW', 'DOCS_PENDING', status) WHERE id = ?")
            ->execute([mb_substr($raw, 0, 60000), $requestId]);
        $pdo->commit();

        // پوشه‌ی هر ردیفِ تازه ساخته می‌شود و وضعیتش بر اساس چک‌لیست تعیین می‌گردد
        $siteRoot = dirname(__DIR__);
        foreach ($newIds as $pid) {
            ensure_plate_folder($pdo, $siteRoot, $pid);
            company_sync_plate_status($pdo, $pid);
        }

        echo json_encode(['ok' => true, 'created' => $created, 'duplicates' => $skipped,
                          'errors' => $parsed['errors']], JSON_UNESCAPED_UNICODE);
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

        $ocrData = null; $ocrDebug = null;
        if ($ext === 'pdf') {
            $ocrResult = run_document_ocr_verbose($tempPath);
            $ocrData = $ocrResult['data'];
            $ocrDebug = $ocrResult['debug'];
        } else {
            $ocrDebug = 'فقط فایل PDF قابل شناسایی خودکار است (این فایل ' . strtoupper($ext) . ' بود).';
        }

        // ---- اعتبارسنجی: فایل باید مربوط به همین ردیف باشد ----
        $expectedPlate = company_row_label($plate);
        $kind = $plate['request_kind'] ?? 'NEW_POLICY';
        if ($ocrData && ($ocrData['ins_type'] ?? '') !== 'ناشناخته' && ($ocrData['ins_type'] ?? '') !== 'معرفی‌نامه') {
            if (!empty($ocrData['plate']) && $expectedPlate && plate_core($ocrData['plate']) !== plate_core($expectedPlate)) {
                @unlink($tempPath);
                echo json_encode(['ok' => false, 'error' => "⚠️ پلاک این ردیف «{$expectedPlate}» است، ولی پلاک شناسایی‌شده از فایل «{$ocrData['plate']}» است. این بیمه‌نامه مربوط به این ردیف نیست."]);
                exit;
            }
            // فایلِ الحاقیه/فسخ نوعش «بدنه/ثالث» خوانده نمی‌شود، پس فقط پلاک ملاک است
            $expectedTypeFa = insurance_type_fa($plate['insurance_type']);
            if ($kind === 'NEW_POLICY' && $ocrData['ins_type'] !== $expectedTypeFa) {
                @unlink($tempPath);
                echo json_encode(['ok' => false, 'error' => "⚠️ این ردیف «{$expectedTypeFa}» است، ولی فایلی که بارگذاری کردید «{$ocrData['ins_type']}» تشخیص داده شد. فایل درست را بارگذاری کنید."]);
                exit;
            }
        }

        $pdo->prepare("UPDATE company_request_plates SET pending_policy_temp_path = ?, pending_policy_orig_name = ?, ocr_extracted_data = ? WHERE id = ?")
            ->execute([$tempPath, $_FILES['policy_file']['name'], $ocrData ? json_encode($ocrData, JSON_UNESCAPED_UNICODE) : null, $plateId]);

        echo json_encode(['ok' => true, 'ocr' => $ocrData, 'ocr_used' => (bool)$ocrData, 'ocr_debug' => $ocrDebug,
                          'expected_plate' => $expectedPlate], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- مرحله‌ی ۲ از صدور: ثبت صدور نهاییِ یک ردیف (فقط مدیر کل) ----
    //  پوشه‌ی ردیف به نامِ نهاییِ بیمه‌نامه («پلاک - شرکت - شماره بیمه‌نامه - VIN») تغییر
    //  نام می‌دهد، فایل بیمه‌نامه با همان نام‌گذاریِ پرسنلی داخلش ذخیره می‌شود، و یک کپیِ
    //  کاملِ پوشه به «بایگانی صادره»ی همان ماه و همان نوع بیمه می‌رود.
    if ($action === 'mark_issued') {
        require_admin_only();
        $plateId = intval($data['plate_id'] ?? 0);
        $policyNumber = trim($data['policy_number'] ?? '');
        $vin = trim($data['vin'] ?? '');
        $totalPremium = !empty($data['total_premium']) ? intval(preg_replace('/\D/', '', (string)$data['total_premium'])) : null;
        if (!$plateId || $policyNumber === '') { echo json_encode(['ok' => false, 'error' => 'شماره‌ی بیمه‌نامه الزامی است.']); exit; }

        $siteRoot = dirname(__DIR__);
        $baseDir = ensure_plate_folder($pdo, $siteRoot, $plateId);
        if (!$baseDir) { echo json_encode(['ok' => false, 'error' => 'ردیف یافت نشد.']); exit; }

        $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, cr.request_kind, c.name AS company_name FROM company_request_plates crp
                                JOIN company_requests cr ON cr.id = crp.request_id
                                JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
        $stmt->execute([$plateId]);
        $plate = $stmt->fetch();
        $kind = $plate['request_kind'] ?? 'NEW_POLICY';

        $plateDisplay = company_row_label($plate);
        // همان قاعده‌ی نام‌گذاری پرسنلی: پلاک بدون خط‌تیره‌ی داخلی، و «/» شماره‌ی
        // بیمه‌نامه با «∕» جایگزین می‌شود (چون در نام فایل/پوشه‌ی ویندوز مجاز نیست)
        $plateForName = plate_for_filename($plateDisplay);
        $policyNumForName = policy_number_for_filename($policyNumber);
        $issuedFolderName = company_issued_folder_name_for_kind($kind, $plate['insurance_type'], $plateDisplay,
                                                                 $plate['company_name'], $policyNumber, $vin);
        $issuedDir = dirname($baseDir) . '/' . $issuedFolderName;

        // ابتدا پوشه‌ی قبل از صدور (با مدارک تگ‌گذاری‌شده‌ی احتمالی) به نام نهایی تغییر
        // نام می‌دهد و فقط بعد از آن فایل بیمه‌نامه داخلش ذخیره می‌شود - وگرنه چون پوشه‌ی
        // مقصد از قبل غیرخالی شده، rename روی آن شکست می‌خورد
        if (is_dir($baseDir) && $baseDir !== $issuedDir) {
            if (!is_dir(dirname($issuedDir))) @mkdir(dirname($issuedDir), 0755, true);
            if (@rename($baseDir, $issuedDir)) {
                company_rewrite_doc_paths($pdo, $siteRoot, $plateId, $baseDir, $issuedDir);
            }
        }
        if (!is_dir($issuedDir)) @mkdir($issuedDir, 0755, true);

        // ---- فایل بیمه‌نامه: اگر مرحله‌ی اعتبارسنجی انجام شده باشد از فایل موقتِ همان
        //      استفاده می‌شود، وگرنه آپلود مستقیم هم پذیرفته می‌شود ----
        $issuedFilePath = null;
        $pendingTemp = $plate['pending_policy_temp_path'] ?? null;
        if ($pendingTemp && is_file($pendingTemp)) {
            $ext = strtolower(pathinfo($plate['pending_policy_orig_name'] ?: 'policy.pdf', PATHINFO_EXTENSION)) ?: 'pdf';
            $finalName = build_final_policy_filename($plateForName, $plate['company_name'], $policyNumForName, $vin, $ext);
            $dest = unique_dest_path($issuedDir . '/' . $finalName);
            if (@rename($pendingTemp, $dest)) {
                $issuedFilePath = ltrim(str_replace($siteRoot, '', $dest), '/');
            }
        } elseif (!empty($_FILES['issued_file'])) {
            $saved = company_store_uploaded_file($_FILES['issued_file'], $issuedDir);
            if ($saved['ok']) {
                $ext = strtolower(pathinfo($saved['path'], PATHINFO_EXTENSION)) ?: 'pdf';
                $finalName = build_final_policy_filename($plateForName, $plate['company_name'], $policyNumForName, $vin, $ext);
                $dest = unique_dest_path($issuedDir . '/' . $finalName);
                if (@rename($saved['path'], $dest)) $saved['path'] = $dest;
                $issuedFilePath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
            }
        }

        $issuedAt = time();
        // الحاقیه و فسخ فقط در بایگانی شرکتی می‌مانند و به «بایگانی صادره» نمی‌روند؛
        // بایگانی صادره مخصوصِ بیمه‌نامه‌های صادرشده است (خواسته‌ی صریح کارفرما).
        $folderStatus = 'FAILED';
        if (!company_kind_goes_to_sadere($kind)) {
            $folderStatus = is_dir($issuedDir) ? 'TRANSFERRED' : 'FAILED';
        } elseif (is_dir($issuedDir)) {
            $copied = company_copy_to_shared_sadere($siteRoot, $issuedDir, $issuedAt, $plate['insurance_type'], $issuedFolderName);
            $folderStatus = $copied ? 'TRANSFERRED' : 'FAILED';
        }

        $pdo->prepare("UPDATE company_request_plates SET status = 'ISSUED', policy_number = ?, vin = ?, total_premium = ?,
                        folder_path = ?, issued_file_path = COALESCE(?, issued_file_path), issued_at = FROM_UNIXTIME(?), folder_status = ?,
                        pending_policy_temp_path = NULL, pending_policy_orig_name = NULL WHERE id = ?")
            ->execute([$policyNumber, $vin ?: null, $totalPremium, $issuedDir, $issuedFilePath, $issuedAt, $folderStatus, $plateId]);

        // مالیِ خودِ این بیمه‌نامه بلافاصله شکل می‌گیرد: ردیف‌های اقساط طبق قسط‌بندیِ
        // همان شرکت و «فرمول ماموت» ساخته می‌شوند
        $installmentCount = 0;
        if ($totalPremium) {
            $genResult = company_generate_installments($pdo, $plateId);
            $installmentCount = $genResult['count'] ?? 0;
        }

        // اگر همه‌ی ردیف‌های این درخواست صادر شده باشند، خودِ درخواست هم ISSUED می‌شود
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'ISSUED') AS issued FROM company_request_plates WHERE request_id = ?");
        $stmt->execute([$plate['request_id']]);
        $counts = $stmt->fetch();
        if ($counts && intval($counts['total']) > 0 && intval($counts['total']) === intval($counts['issued'])) {
            $pdo->prepare("UPDATE company_requests SET status = 'ISSUED' WHERE id = ?")->execute([$plate['request_id']]);
        }

        echo json_encode(['ok' => true, 'folder_status' => $folderStatus, 'installments' => $installmentCount,
                          'issued_folder' => $issuedFolderName], JSON_UNESCAPED_UNICODE);
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
        $amount = intval(preg_replace('/\D/', '', $_POST['amount'] ?? '0'));
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
                $base = sanitize_folder_name(trim($_POST['paid_jalali'] ?? date('Y-m-d')) . ' - ' . trim($_POST['reference_no'] ?? 'فیش') . ' - ' . number_format($amount));
                $dest = unique_dest_path($dir . '/' . $base . '.' . $ext);
                if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                    if (in_array($ext, ['jpg','jpeg','png','webp'])) compress_image_if_needed($dest);
                    $receipts[] = ltrim(str_replace($siteRoot, '', $dest), '/');
                }
            }
        }

        $pdo->prepare("INSERT INTO company_payments (request_id, target, amount, method, paid_jalali, paid_at, reference_no, receipts, note, created_by)
                       VALUES (?, 'US', ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$requestId, $amount, $method, trim($_POST['paid_jalali'] ?? ''),
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
                $base = sanitize_folder_name(trim($_POST['paid_jalali'] ?? date('Y-m-d')) . ' - ' . trim($_POST['reference_no'] ?? 'فیش') . ' - ' . number_format($total));
                $dest = unique_dest_path($dir . '/' . $base . '.' . $ext);
                if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                    if (in_array($ext, ['jpg','jpeg','png','webp'])) compress_image_if_needed($dest);
                    $receipts[] = ltrim(str_replace($siteRoot, '', $dest), '/');
                }
            }
        }

        $pdo->prepare("INSERT INTO company_payments (request_id, target, amount, method, paid_jalali, paid_at, reference_no, receipts, note, created_by)
                       VALUES (?, 'PASARGAD', ?, 'TRANSFER', ?, ?, ?, ?, ?, ?)")
            ->execute([$requestId, $total, trim($_POST['paid_jalali'] ?? ''),
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
