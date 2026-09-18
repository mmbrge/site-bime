<?php
// فایل: api/finance_actions.php
// بک‌اند بخش حسابداری: دوره‌ها، اقساط، داشبورد، تنظیمات
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'دسترسی ندارید.']); exit; }

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/finance_core.php';

$jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($jsonBody['action'] ?? '');
$data = $jsonBody;

// فقط ADMIN و FINANCE به این بخش دسترسی دارند
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['ADMIN', 'FINANCE'], true)) {
    echo json_encode(['ok' => false, 'error' => 'شما به بخش مالی دسترسی ندارید.']); exit;
}
$isAdmin = ($role === 'ADMIN');

try {
    // =================================================================
    //  فهرست دوره‌ها و شرکت‌ها (برای پرکردن فیلترها)
    // =================================================================
    if ($action === 'bootstrap') {
        fin_auto_close_periods($pdo);
        $current = fin_current_period($pdo);
        $periods = $pdo->query("SELECT id, title, status, starts_at, ends_at FROM billing_periods ORDER BY jalali_year DESC, jalali_month DESC")->fetchAll();
        $companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
        echo json_encode([
            'ok' => true, 'periods' => $periods, 'companies' => $companies,
            'current_period_id' => $current['id'] ?? null,
            'settings' => fin_settings($pdo),
            'is_admin' => $isAdmin,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  اقساط با فیلتر
    // =================================================================
    if ($action === 'installments') {
        $where = ["pc.status = 'ISSUED'"];
        $params = [];
        if (!empty($_GET['period_id'])) { $where[] = "pi.period_id = ?"; $params[] = intval($_GET['period_id']); }
        if (!empty($_GET['company_id'])) { $where[] = "p.company_id = ?"; $params[] = intval($_GET['company_id']); }
        if (!empty($_GET['q'])) {
            $where[] = "(pc.insured_name LIKE ? OR pc.plate LIKE ? OR pc.policy_number LIKE ? OR p.full_name LIKE ?)";
            $like = '%' . $_GET['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql = "
            SELECT pi.id, pi.case_id, pi.inst_number, pi.amount, pi.due_jalali, pi.settled_to_pasargad,
                   pc.plate, pc.insurance_type, pc.policy_number, pc.insured_name, pc.total_premium,
                   p.full_name AS holder_name, c.name AS company_name, c.id AS company_id,
                   bp.title AS period_title,
                   COALESCE(SUM(pa.amount), 0) AS paid
            FROM policy_installments pi
            JOIN policy_cases pc ON pi.case_id = pc.id
            JOIN persons p ON pc.person_id = p.id
            LEFT JOIN companies c ON p.company_id = c.id
            LEFT JOIN billing_periods bp ON pi.period_id = bp.id
            LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY pi.id
            ORDER BY pi.due_date ASC, pc.plate ASC, pi.inst_number ASC
            LIMIT 1000
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $filterStatus = $_GET['status'] ?? '';
        $out = [];
        foreach ($rows as $r) {
            $paid = intval($r['paid']);
            $amount = intval($r['amount']);
            $remaining = max(0, $amount - $paid);
            $st = $remaining <= 0 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'UNPAID');
            if ($filterStatus && $st !== $filterStatus) continue;
            $r['paid'] = $paid; $r['remaining'] = $remaining; $r['pay_status'] = $st;
            $out[] = $r;
        }
        echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  داشبورد مالی
    // =================================================================
    if ($action === 'dashboard') {
        $periodId = intval($_GET['period_id'] ?? 0);
        $pw = $periodId ? "AND pi.period_id = " . $periodId : "";

        // بدهی ما به پاسارگاد و دریافتی از شرکت‌ها
        $agg = $pdo->query("
            SELECT COALESCE(SUM(pi.amount),0) AS total_debt,
                   COALESCE(SUM(alloc.paid),0) AS total_collected,
                   COALESCE(SUM(CASE WHEN pi.settled_to_pasargad = 1 THEN pi.amount ELSE 0 END),0) AS settled_pasargad,
                   COUNT(*) AS inst_count
            FROM policy_installments pi
            LEFT JOIN (SELECT installment_id, SUM(amount) AS paid FROM payment_allocations GROUP BY installment_id) alloc
                   ON alloc.installment_id = pi.id
            WHERE 1=1 $pw
        ")->fetch();

        // اقساط سررسیدگذشته و پرداخت‌نشده
        $overdue = $pdo->query("
            SELECT pi.id, pi.inst_number, pi.amount, pi.due_jalali, pc.plate, pc.insured_name,
                   c.name AS company_name, COALESCE(SUM(pa.amount),0) AS paid
            FROM policy_installments pi
            JOIN policy_cases pc ON pi.case_id = pc.id
            JOIN persons p ON pc.person_id = p.id
            LEFT JOIN companies c ON p.company_id = c.id
            LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
            WHERE pi.due_date < CURDATE() $pw
            GROUP BY pi.id
            HAVING paid < pi.amount
            ORDER BY pi.due_date ASC
            LIMIT 100
        ")->fetchAll();

        // وضعیت هر شرکت
        $companies = $pdo->query("
            SELECT c.id, c.name,
                   COUNT(DISTINCT pc.id) AS policy_count,
                   COALESCE(SUM(pi.amount),0) AS total_amount,
                   COALESCE(SUM(alloc.paid),0) AS paid_amount
            FROM policy_installments pi
            JOIN policy_cases pc ON pi.case_id = pc.id
            JOIN persons p ON pc.person_id = p.id
            JOIN companies c ON p.company_id = c.id
            LEFT JOIN (SELECT installment_id, SUM(amount) AS paid FROM payment_allocations GROUP BY installment_id) alloc
                   ON alloc.installment_id = pi.id
            WHERE 1=1 $pw
            GROUP BY c.id
            ORDER BY total_amount DESC
        ")->fetchAll();

        echo json_encode([
            'ok' => true,
            'summary' => [
                'total_debt'       => intval($agg['total_debt']),
                'total_collected'  => intval($agg['total_collected']),
                'remaining'        => intval($agg['total_debt']) - intval($agg['total_collected']),
                'settled_pasargad' => intval($agg['settled_pasargad']),
                'inst_count'       => intval($agg['inst_count']),
            ],
            'overdue' => $overdue,
            'companies' => $companies,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  تولید/بازتولید اقساط یک پرونده (دستی)
    // =================================================================
    if ($action === 'regenerate_installments') {
        $caseId = intval($data['case_id'] ?? 0);
        $count = !empty($data['count']) ? intval($data['count']) : null;
        $method = $data['method'] ?? null;
        $res = fin_generate_installments($pdo, $caseId, $count, $method);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  تنظیمات مالی (فقط ADMIN)
    // =================================================================
    if ($action === 'get_settings') {
        $s = fin_settings($pdo);
        $tpl = $pdo->query("SELECT kind, docx_path, uploaded_at FROM invoice_templates WHERE is_active = 1")->fetchAll();
        echo json_encode(['ok' => true, 'settings' => $s, 'templates' => $tpl], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_settings') {
        if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'فقط مدیر می‌تواند تنظیمات را تغییر دهد.']); exit; }
        $map = [
            'period_cutoff_day'       => max(1, min(31, intval($data['cutoff'] ?? 25))),
            'default_installments'    => max(1, min(36, intval($data['inst_count'] ?? 9))),
            'installment_method'      => in_array($data['method'] ?? '', ['easy','mamut'], true) ? $data['method'] : 'mamut',
            'rule_sequential_payment' => !empty($data['rule_seq']) ? '1' : '0',
            'rule_collect_before_pay' => !empty($data['rule_collect']) ? '1' : '0',
            'invoice_prefix'          => trim($data['inv_prefix'] ?? 'SM49357'),
        ];
        foreach ($map as $k => $v) fin_set($pdo, $k, (string)$v);
        echo json_encode(['ok' => true]);
        exit;
    }

    // =================================================================
    //  آپلود قالب Word صورتحساب (فقط ADMIN)
    // =================================================================
    if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'upload_template') {
        if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'فقط مدیر می‌تواند قالب را تغییر دهد.']); exit; }
        $kind = ($_POST['kind'] ?? '') === 'SUMMARY' ? 'SUMMARY' : 'DETAILED';
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'docx') { echo json_encode(['ok' => false, 'error' => 'فقط فایل docx پذیرفته می‌شود.']); exit; }

        $siteRoot = dirname(__DIR__);
        $dir = $siteRoot . '/tmpl_invoice';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $dest = unique_dest_path($dir . '/' . $kind . '_' . time() . '.docx');
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل.']); exit;
        }
        $rel = ltrim(str_replace($siteRoot, '', $dest), '/');
        $pdo->prepare("UPDATE invoice_templates SET is_active = 0 WHERE kind = ?")->execute([$kind]);
        $pdo->prepare("INSERT INTO invoice_templates (kind, title, docx_path, is_active) VALUES (?, ?, ?, 1)")
            ->execute([$kind, $_FILES['file']['name'], $rel]);
        echo json_encode(['ok' => true, 'path' => $rel], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  خروجی اکسل اقساط
    //  نکته: کتابخانه‌ی اکسل روی این هاست نصب نیست، پس از فرمت SpreadsheetML (XML اکسل)
    //  استفاده می‌کنیم که بدون هیچ وابستگی ساخته می‌شود و اکسل آن را با فرمت‌بندی کامل
    //  (راست‌چین، فونت B Nazanin، سربرگ بولد) باز می‌کند.
    // =================================================================
    if ($action === 'export_installments') {
        $where = ["pc.status = 'ISSUED'"];
        $params = [];
        if (!empty($_GET['period_id'])) { $where[] = "pi.period_id = ?"; $params[] = intval($_GET['period_id']); }
        if (!empty($_GET['company_id'])) { $where[] = "p.company_id = ?"; $params[] = intval($_GET['company_id']); }
        if (!empty($_GET['q'])) {
            $where[] = "(pc.insured_name LIKE ? OR pc.plate LIKE ? OR pc.policy_number LIKE ? OR p.full_name LIKE ?)";
            $like = '%' . $_GET['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $stmt = $pdo->prepare("
            SELECT pi.inst_number, pi.amount, pi.due_jalali, pi.settled_to_pasargad,
                   pc.plate, pc.insurance_type, pc.policy_number, pc.insured_name,
                   p.full_name AS holder_name, p.national_code, p.personnel_code,
                   c.name AS company_name, bp.title AS period_title,
                   COALESCE(SUM(pa.amount), 0) AS paid
            FROM policy_installments pi
            JOIN policy_cases pc ON pi.case_id = pc.id
            JOIN persons p ON pc.person_id = p.id
            LEFT JOIN companies c ON p.company_id = c.id
            LEFT JOIN billing_periods bp ON pi.period_id = bp.id
            LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY pi.id
            ORDER BY c.name ASC, pc.plate ASC, pi.inst_number ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $filterStatus = $_GET['status'] ?? '';
        $headers = ['ردیف','دوره','شرکت','بیمه‌گذار','کد ملی','کد پرسنلی','پلاک','نوع بیمه','شماره بیمه‌نامه',
                    'شماره قسط','سررسید','مبلغ قسط (ریال)','پرداخت‌شده','مانده','وضعیت','تسویه با پاسارگاد'];
        $out = [];
        $i = 1;
        foreach ($rows as $r) {
            $paid = intval($r['paid']); $amount = intval($r['amount']);
            $remaining = max(0, $amount - $paid);
            $st = $remaining <= 0 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'UNPAID');
            if ($filterStatus && $st !== $filterStatus) continue;
            $stFa = ['PAID' => 'تسویه‌شده', 'PARTIAL' => 'ناقص', 'UNPAID' => 'پرداخت‌نشده'][$st];
            $out[] = [$i++, $r['period_title'], $r['company_name'], $r['insured_name'] ?: $r['holder_name'],
                      $r['national_code'], $r['personnel_code'], $r['plate'],
                      insurance_type_fa($r['insurance_type']), $r['policy_number'],
                      $r['inst_number'], $r['due_jalali'], $amount, $paid, $remaining, $stFa,
                      $r['settled_to_pasargad'] ? 'بله' : 'خیر'];
        }

        fin_send_excel('گزارش-اقساط', $headers, $out, [11, 12, 13]);
        exit;
    }

    // =================================================================
    //  مغایرت‌گیری با اکسل پاسارگاد
    //  اکسل را می‌خوانیم و شماره‌ی بیمه‌نامه/حق بیمه را با پنل تطبیق می‌دهیم.
    // =================================================================
    if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'reconcile') {
        $periodId = intval($_POST['period_id'] ?? 0);
        if (!$periodId) { echo json_encode(['ok' => false, 'error' => 'دوره انتخاب نشده است.']); exit; }

        $siteRoot = dirname(__DIR__);
        $dir = $siteRoot . '/tmp_recon';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION)) ?: 'xlsx';
        $tmp = $dir . '/rec_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در دریافت فایل.']); exit;
        }

        $excelRows = fin_read_spreadsheet($tmp);
        if ($excelRows === null) {
            @unlink($tmp);
            echo json_encode(['ok' => false, 'error' => 'خواندن فایل ممکن نشد. لطفاً فایل را به فرمت CSV ذخیره و دوباره تلاش کنید.']); exit;
        }

        // شناسایی ستون‌های شماره بیمه‌نامه و حق بیمه از روی سربرگ
        $header = array_shift($excelRows) ?: [];
        $colPolicy = $colPremium = $colPlate = $colName = null;
        foreach ($header as $i => $h) {
            $h = trim(preg_replace('/\s+/u', ' ', (string)$h));
            if ($colPolicy === null && preg_match('/بیمه\s*نامه|شماره\s*بیمه/u', $h) && !preg_match('/حق/u', $h)) $colPolicy = $i;
            if ($colPremium === null && preg_match('/حق\s*بیمه|مبلغ|صافی/u', $h)) $colPremium = $i;
            if ($colPlate === null && preg_match('/پلاک|انتظامی/u', $h)) $colPlate = $i;
            if ($colName === null && preg_match('/بیمه\s*گذار|نام/u', $h)) $colName = $i;
        }
        if ($colPolicy === null) {
            @unlink($tmp);
            echo json_encode(['ok' => false, 'error' => 'ستون «شماره بیمه‌نامه» در فایل پیدا نشد.']); exit;
        }

        // فهرست پنل برای همین دوره
        $stmt = $pdo->prepare("
            SELECT DISTINCT pc.id, pc.policy_number, pc.plate, pc.insured_name, pc.total_premium, c.name AS company_name
            FROM policy_installments pi
            JOIN policy_cases pc ON pi.case_id = pc.id
            JOIN persons p ON pc.person_id = p.id
            LEFT JOIN companies c ON p.company_id = c.id
            WHERE pi.period_id = ? AND pc.status = 'ISSUED'
        ");
        $stmt->execute([$periodId]);
        $panel = [];
        foreach ($stmt->fetchAll() as $r) {
            $panel[fin_norm_policy($r['policy_number'])] = $r;
        }

        $matched = []; $onlyExcel = []; $mismatched = [];
        $seen = [];
        foreach ($excelRows as $row) {
            $pn = fin_norm_policy($row[$colPolicy] ?? '');
            if ($pn === '') continue;
            $seen[$pn] = true;
            $prem = $colPremium !== null ? intval(preg_replace('/\D/', '', (string)($row[$colPremium] ?? 0))) : 0;
            if (!isset($panel[$pn])) {
                $onlyExcel[] = [
                    'policy_number' => (string)($row[$colPolicy] ?? ''),
                    'premium' => $prem,
                    'plate' => $colPlate !== null ? (string)($row[$colPlate] ?? '') : '',
                    'name'  => $colName !== null ? (string)($row[$colName] ?? '') : '',
                ];
                continue;
            }
            $p = $panel[$pn];
            $panelPrem = intval($p['total_premium']);
            if ($prem > 0 && $panelPrem > 0 && $prem !== $panelPrem) {
                $mismatched[] = ['policy_number' => $p['policy_number'], 'plate' => $p['plate'],
                                 'name' => $p['insured_name'], 'company' => $p['company_name'],
                                 'panel_premium' => $panelPrem, 'excel_premium' => $prem,
                                 'diff' => $prem - $panelPrem];
            } else {
                $matched[] = ['policy_number' => $p['policy_number'], 'plate' => $p['plate'],
                              'name' => $p['insured_name'], 'company' => $p['company_name'], 'premium' => $panelPrem];
            }
        }

        $onlyPanel = [];
        foreach ($panel as $pn => $p) {
            if (!isset($seen[$pn])) {
                $onlyPanel[] = ['policy_number' => $p['policy_number'], 'plate' => $p['plate'],
                                'name' => $p['insured_name'], 'company' => $p['company_name'],
                                'premium' => intval($p['total_premium'])];
            }
        }

        $result = ['matched' => $matched, 'only_excel' => $onlyExcel, 'only_panel' => $onlyPanel, 'mismatched' => $mismatched];
        $rel = ltrim(str_replace($siteRoot, '', $tmp), '/');
        $status = (count($onlyExcel) + count($onlyPanel) + count($mismatched)) === 0 ? 'RESOLVED' : 'PENDING';
        $pdo->prepare("INSERT INTO reconciliations (period_id, file_path, result, matched, only_excel, only_panel, mismatched, status, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$periodId, $rel, json_encode($result, JSON_UNESCAPED_UNICODE),
                       count($matched), count($onlyExcel), count($onlyPanel), count($mismatched), $status, $_SESSION['user_id']]);

        echo json_encode(['ok' => true, 'status' => $status, 'result' => $result,
                          'counts' => ['matched' => count($matched), 'only_excel' => count($onlyExcel),
                                       'only_panel' => count($onlyPanel), 'mismatched' => count($mismatched)]],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    // آخرین وضعیت مغایرت‌گیری یک دوره (برای قفل/بازکردن صدور صورتحساب)
    if ($action === 'reconcile_status') {
        $periodId = intval($_GET['period_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM reconciliations WHERE period_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$periodId]);
        $r = $stmt->fetch();
        echo json_encode(['ok' => true, 'data' => $r ?: null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تایید دستی مغایرت (با ثبت دلیل) تا صدور صورتحساب باز شود
    if ($action === 'override_reconcile') {
        $id = intval($data['id'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        if ($reason === '') { echo json_encode(['ok' => false, 'error' => 'ثبت دلیل الزامی است.']); exit; }
        $pdo->prepare("UPDATE reconciliations SET status = 'OVERRIDDEN', override_reason = ? WHERE id = ?")
            ->execute([$reason, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // =================================================================
    //  پیش‌نمایش صورتحساب (پیش از صدور) - شامل بررسی قفلِ مغایرت‌گیری
    // =================================================================
    if ($action === 'invoice_preview') {
        $periodId = intval($_GET['period_id'] ?? 0);
        $kind = fin_kind($_GET['kind'] ?? 'DETAILED');
        // نوع «تلفیقی» (PERSONNEL) مثل تجمیعی روی کل دوره است و نام شرکت دستی وارد می‌شود
        $companyId = $kind === 'DETAILED' ? intval($_GET['company_id'] ?? 0) : null;
        if (!$periodId) { echo json_encode(['ok' => false, 'error' => 'دوره انتخاب نشده است.']); exit; }
        if ($kind === 'DETAILED' && !$companyId) { echo json_encode(['ok' => false, 'error' => 'برای صورتحساب تفکیکی، شرکت را انتخاب کنید.']); exit; }

        // قفل مغایرت‌گیری: تا وضعیت RESOLVED یا OVERRIDDEN نباشد، صدور مجاز نیست
        $rec = $pdo->prepare("SELECT * FROM reconciliations WHERE period_id = ? ORDER BY id DESC LIMIT 1");
        $rec->execute([$periodId]);
        $recRow = $rec->fetch();
        $recOk = $recRow && in_array($recRow['status'], ['RESOLVED', 'OVERRIDDEN'], true);

        $rows = fin_collect_invoice_rows($pdo, $periodId, $companyId);

        if ($kind === 'SUMMARY') {
            $byCompany = [];
            foreach ($rows as $r) {
                $cid = $r['company_id'] ?: 0;
                if (!isset($byCompany[$cid])) $byCompany[$cid] = ['company_name' => $r['company_name'] ?: 'بدون شرکت', 'policy_count' => 0, 'total_premium' => 0, 'monthly_amount' => 0];
                $byCompany[$cid]['policy_count']++;
                $byCompany[$cid]['total_premium'] += intval($r['total_premium']);
                $byCompany[$cid]['monthly_amount'] += intval($r['monthly_amount']);
            }
            $lines = array_values($byCompany);
        } elseif ($kind === 'PERSONNEL') {
            // هر بیمه‌نامه یک ردیف، ولی فقط اطلاعات پرسنلی و مبلغ
            $lines = array_map(fn($r) => [
                'case_id' => $r['case_id'], 'person_name' => $r['insured_name'] ?: $r['holder_name'],
                'personnel_code' => $r['personnel_code'], 'national_code' => $r['national_code'],
                'insurance_type' => insurance_type_fa($r['insurance_type']),
                'total_premium' => intval($r['total_premium']),
                'monthly_amount' => intval($r['monthly_amount']),
            ], $rows);
        } else {
            $lines = array_map(fn($r) => [
                'case_id' => $r['case_id'], 'person_name' => $r['insured_name'] ?: $r['holder_name'],
                'national_code' => $r['national_code'], 'personnel_code' => $r['personnel_code'],
                'plate' => $r['plate'], 'insurance_type' => insurance_type_fa($r['insurance_type']),
                'policy_number' => $r['policy_number'], 'total_premium' => intval($r['total_premium']),
                'monthly_amount' => intval($r['monthly_amount']),
            ], $rows);
        }

        $total = array_sum(array_map(fn($l) => $l['total_premium'], $lines));
        $monthly = array_sum(array_map(fn($l) => $l['monthly_amount'], $lines));

        echo json_encode([
            'ok' => true, 'kind' => $kind, 'lines' => $lines,
            'total' => $total, 'monthly' => $monthly, 'count' => count($lines),
            'reconcile_ok' => $recOk,
            'reconcile' => $recRow ? ['id' => $recRow['id'], 'status' => $recRow['status'],
                                      'only_excel' => intval($recRow['only_excel']),
                                      'only_panel' => intval($recRow['only_panel']),
                                      'mismatched' => intval($recRow['mismatched'])] : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  صدور صورتحساب
    // =================================================================
    if ($action === 'create_invoice') {
        $periodId = intval($data['period_id'] ?? 0);
        $kind = fin_kind($data['kind'] ?? 'DETAILED');
        $companyId = $kind === 'DETAILED' ? intval($data['company_id'] ?? 0) : null;
        // در انواع تجمیعی و تلفیقی، نام شرکتِ مخاطبِ نامه دستی وارد می‌شود
        $manualCompany = trim($data['manual_company'] ?? '');
        if (!$periodId) { echo json_encode(['ok' => false, 'error' => 'دوره انتخاب نشده است.']); exit; }
        if ($kind === 'DETAILED' && !$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت انتخاب نشده است.']); exit; }

        $rec = $pdo->prepare("SELECT status FROM reconciliations WHERE period_id = ? ORDER BY id DESC LIMIT 1");
        $rec->execute([$periodId]);
        $recStatus = $rec->fetchColumn();
        if (!in_array($recStatus, ['RESOLVED', 'OVERRIDDEN'], true)) {
            echo json_encode(['ok' => false, 'error' => 'ابتدا باید مغایرت‌گیری این دوره انجام و تایید شود.']); exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM billing_periods WHERE id = ?");
        $stmt->execute([$periodId]);
        $period = $stmt->fetch();
        if (!$period) { echo json_encode(['ok' => false, 'error' => 'دوره یافت نشد.']); exit; }

        $companyName = null;
        if ($companyId) {
            $stmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $companyName = $stmt->fetchColumn();
        } elseif ($manualCompany !== '') {
            $companyName = $manualCompany;
        }

        $rows = fin_collect_invoice_rows($pdo, $periodId, $companyId);
        if (!$rows) { echo json_encode(['ok' => false, 'error' => 'برای این انتخاب، بیمه‌نامه‌ای وجود ندارد.']); exit; }

        $invoiceNo = fin_next_invoice_no($pdo);
        $siteRoot = dirname(__DIR__);
        $folder = fin_invoice_folder($siteRoot, $period, $companyName, $invoiceNo);
        if (!is_dir($folder) && !@mkdir($folder, 0777, true) && !is_dir($folder)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در ساخت پوشه‌ی بایگانی مالی.']); exit;
        }

        $total = 0;
        $pdo->prepare("INSERT INTO invoices (invoice_no, kind, company_id, period_id, total_amount, folder_path, manual_company, created_by)
                       VALUES (?, ?, ?, ?, 0, ?, ?, ?)")
            ->execute([$invoiceNo, $kind, $companyId, $periodId, $folder, $manualCompany ?: null, $_SESSION['user_id']]);
        $invoiceId = $pdo->lastInsertId();

        $insLine = $pdo->prepare("INSERT INTO invoice_lines
            (invoice_id, case_id, company_id, person_name, national_code, personnel_code, plate, insurance_type, policy_number, policy_count, total_premium, monthly_amount)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

        if ($kind === 'SUMMARY') {
            $byCompany = [];
            foreach ($rows as $r) {
                $cid = $r['company_id'] ?: 0;
                if (!isset($byCompany[$cid])) $byCompany[$cid] = ['name' => $r['company_name'] ?: 'بدون شرکت', 'count' => 0, 'premium' => 0, 'monthly' => 0];
                $byCompany[$cid]['count']++;
                $byCompany[$cid]['premium'] += intval($r['total_premium']);
                $byCompany[$cid]['monthly'] += intval($r['monthly_amount']);
            }
            foreach ($byCompany as $cid => $g) {
                $insLine->execute([$invoiceId, null, $cid ?: null, $g['name'], null, null, null, null, null, $g['count'], $g['premium'], $g['monthly']]);
                $total += $g['premium'];
            }
        } else {
            // DETAILED و PERSONNEL هر دو یک ردیف به‌ازای هر بیمه‌نامه دارند؛ تفاوتشان فقط
            // در ستون‌هایی است که هنگام ساخت جدول Word نمایش داده می‌شود
            foreach ($rows as $r) {
                $insLine->execute([$invoiceId, $r['case_id'], $r['company_id'],
                    $r['insured_name'] ?: $r['holder_name'], $r['national_code'], $r['personnel_code'],
                    $r['plate'], insurance_type_fa($r['insurance_type']), $r['policy_number'],
                    null, intval($r['total_premium']), intval($r['monthly_amount'])]);
                $total += intval($r['total_premium']);
            }
        }

        $pdo->prepare("UPDATE invoices SET total_amount = ? WHERE id = ?")->execute([$total, $invoiceId]);

        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();

        // ساخت خروجی Word و PDF از قالب
        $gen = fin_render_invoice_files($pdo, $invoice, $folder, $companyName, $period);
        if (!empty($gen['docx'])) $pdo->prepare("UPDATE invoices SET docx_path = ? WHERE id = ?")->execute([$gen['docx'], $invoiceId]);
        if (!empty($gen['pdf']))  $pdo->prepare("UPDATE invoices SET pdf_path = ? WHERE id = ?")->execute([$gen['pdf'], $invoiceId]);

        $lines = $pdo->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ?");
        $lines->execute([$invoiceId]);
        fin_write_invoice_info($folder, $invoice, $lines->fetchAll(), $companyName, $period['title']);

        echo json_encode(['ok' => true, 'invoice_no' => $invoiceNo, 'invoice_id' => $invoiceId,
                          'total' => $total, 'folder' => $folder, 'generated' => $gen], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // فهرست صورتحساب‌ها
    if ($action === 'invoices') {
        $where = []; $params = [];
        if (!empty($_GET['period_id']))  { $where[] = "i.period_id = ?";  $params[] = intval($_GET['period_id']); }
        if (!empty($_GET['company_id'])) { $where[] = "i.company_id = ?"; $params[] = intval($_GET['company_id']); }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = $pdo->prepare("
            SELECT i.*, COALESCE(c.name, i.manual_company) AS company_name, bp.title AS period_title,
                   (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = i.id) AS paid_amount,
                   (SELECT COUNT(*) FROM invoice_lines WHERE invoice_id = i.id) AS line_count
            FROM invoices i
            LEFT JOIN companies c ON i.company_id = c.id
            LEFT JOIN billing_periods bp ON i.period_id = bp.id
            $w ORDER BY i.id DESC LIMIT 300
        ");
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // جزئیات یک صورتحساب
    if ($action === 'invoice_detail') {
        $id = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT i.*, COALESCE(c.name, i.manual_company) AS company_name, bp.title AS period_title
                               FROM invoices i LEFT JOIN companies c ON i.company_id = c.id
                               LEFT JOIN billing_periods bp ON i.period_id = bp.id WHERE i.id = ?");
        $stmt->execute([$id]);
        $inv = $stmt->fetch();
        if (!$inv) { echo json_encode(['ok' => false, 'error' => 'صورتحساب یافت نشد.']); exit; }
        $lines = $pdo->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY id");
        $lines->execute([$id]);
        $pays = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY id DESC");
        $pays->execute([$id]);
        echo json_encode(['ok' => true, 'invoice' => $inv, 'lines' => $lines->fetchAll(), 'payments' => $pays->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  ثبت دریافت از شرکت + تخصیص به اقساط
    // =================================================================
    if (isset($_FILES['receipts']) || ($_POST['action'] ?? '') === 'create_payment') {
        $companyId = intval($_POST['company_id'] ?? 0);
        $invoiceId = !empty($_POST['invoice_id']) ? intval($_POST['invoice_id']) : null;
        $amount = intval(preg_replace('/\D/', '', $_POST['amount'] ?? '0'));
        $method = in_array($_POST['method'] ?? '', ['TRANSFER','CHEQUE','CASH','PAYROLL'], true) ? $_POST['method'] : 'TRANSFER';
        if (!$companyId || $amount <= 0) { echo json_encode(['ok' => false, 'error' => 'شرکت و مبلغ الزامی است.']); exit; }

        $siteRoot = dirname(__DIR__);
        // چک: اگر روش چک است، ابتدا رکورد چک ساخته می‌شود
        $chequeId = null;
        if ($method === 'CHEQUE') {
            $pdo->prepare("INSERT INTO cheques (company_id, cheque_no, bank_name, amount, due_jalali, due_date, status, note)
                           VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?)")
                ->execute([$companyId, trim($_POST['cheque_no'] ?? ''), trim($_POST['bank_name'] ?? ''), $amount,
                           trim($_POST['cheque_due'] ?? ''), fin_jalali_to_date($_POST['cheque_due'] ?? ''), trim($_POST['note'] ?? '')]);
            $chequeId = $pdo->lastInsertId();
        }

        // فیش‌ها
        $receipts = [];
        if (!empty($_FILES['receipts'])) {
            $dir = finance_archive_root($siteRoot) . '/فیش‌ها/' . date('Y');
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $files = $_FILES['receipts'];
            $n = is_array($files['name']) ? count($files['name']) : 0;
            for ($i = 0; $i < $n; $i++) {
                if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
                $base = sanitize_folder_name(($_POST['paid_jalali'] ?? date('Y-m-d')) . ' - ' . ($_POST['reference_no'] ?? 'فیش') . ' - ' . number_format($amount));
                $dest = unique_dest_path($dir . '/' . $base . '.' . $ext);
                if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                    if (in_array($ext, ['jpg','jpeg','png','webp'])) compress_image_if_needed($dest);
                    $receipts[] = ltrim(str_replace($siteRoot, '', $dest), '/');
                }
            }
        }

        $pdo->prepare("INSERT INTO payments (company_id, invoice_id, amount, method, paid_jalali, paid_at, reference_no, cheque_id, receipts, note, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$companyId, $invoiceId, $amount, $method,
                       trim($_POST['paid_jalali'] ?? ''), fin_jalali_to_date($_POST['paid_jalali'] ?? ''),
                       trim($_POST['reference_no'] ?? ''), $chequeId,
                       $receipts ? json_encode($receipts, JSON_UNESCAPED_UNICODE) : null,
                       trim($_POST['note'] ?? ''), $_SESSION['user_id']]);
        $paymentId = $pdo->lastInsertId();

        // تخصیص: اگر دستی داده شده از آن استفاده کن، وگرنه خودکار به ترتیب سررسید
        $allocated = 0;
        $manual = json_decode($_POST['allocations'] ?? '[]', true);
        if (is_array($manual) && $manual) {
            $ins = $pdo->prepare("INSERT INTO payment_allocations (payment_id, installment_id, amount) VALUES (?, ?, ?)");
            foreach ($manual as $a) {
                $iid = intval($a['installment_id'] ?? 0); $amt = intval($a['amount'] ?? 0);
                if ($iid && $amt > 0) { $ins->execute([$paymentId, $iid, $amt]); $allocated += $amt; }
            }
        } else {
            // پرونده‌های مربوط به این شرکت (و در صورت وجود، همان صورتحساب)
            if ($invoiceId) {
                $stmt = $pdo->prepare("SELECT DISTINCT case_id FROM invoice_lines WHERE invoice_id = ? AND case_id IS NOT NULL");
                $stmt->execute([$invoiceId]);
            } else {
                $stmt = $pdo->prepare("SELECT pc.id AS case_id FROM policy_cases pc JOIN persons p ON pc.person_id = p.id
                                       WHERE p.company_id = ? AND pc.status = 'ISSUED'");
                $stmt->execute([$companyId]);
            }
            $caseIds = array_column($stmt->fetchAll(), 'case_id');
            if ($caseIds) $allocated = fin_auto_allocate($pdo, $paymentId, $caseIds, $amount);
        }

        echo json_encode(['ok' => true, 'payment_id' => $paymentId, 'allocated' => $allocated,
                          'unallocated' => max(0, $amount - $allocated)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // فهرست دریافت‌ها
    if ($action === 'payments') {
        $where = []; $params = [];
        if (!empty($_GET['company_id'])) { $where[] = "pm.company_id = ?"; $params[] = intval($_GET['company_id']); }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = $pdo->prepare("
            SELECT pm.*, c.name AS company_name, i.invoice_no,
                   (SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE payment_id = pm.id) AS allocated
            FROM payments pm
            LEFT JOIN companies c ON pm.company_id = c.id
            LEFT JOIN invoices i ON pm.invoice_id = i.id
            $w ORDER BY pm.id DESC LIMIT 300");
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // فهرست چک‌ها
    if ($action === 'cheques') {
        $stmt = $pdo->query("SELECT ch.*, c.name AS company_name FROM cheques ch
                             LEFT JOIN companies c ON ch.company_id = c.id ORDER BY ch.due_date ASC, ch.id DESC LIMIT 300");
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'set_cheque_status') {
        $id = intval($data['id'] ?? 0);
        $st = in_array($data['status'] ?? '', ['PENDING','CLEARED','BOUNCED'], true) ? $data['status'] : 'PENDING';
        $pdo->prepare("UPDATE cheques SET status = ? WHERE id = ?")->execute([$st, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // =================================================================
    //  تسویه با پاسارگاد
    //  فهرست اقساطی که از شرکت دریافت شده ولی هنوز به پاسارگاد پرداخت نشده
    // =================================================================
    if ($action === 'pasargad_pending') {
        $where = ["pi.settled_to_pasargad = 0", "pc.status = 'ISSUED'"];
        $params = [];
        if (!empty($_GET['period_id']))  { $where[] = "pi.period_id = ?"; $params[] = intval($_GET['period_id']); }
        if (!empty($_GET['company_id'])) { $where[] = "p.company_id = ?"; $params[] = intval($_GET['company_id']); }

        $stmt = $pdo->prepare("
            SELECT pi.id, pi.inst_number, pi.amount, pi.due_jalali,
                   pc.plate, pc.policy_number, pc.insured_name, pc.insurance_type,
                   c.name AS company_name, bp.title AS period_title,
                   COALESCE(SUM(pa.amount),0) AS paid
            FROM policy_installments pi
            JOIN policy_cases pc ON pi.case_id = pc.id
            JOIN persons p ON pc.person_id = p.id
            LEFT JOIN companies c ON p.company_id = c.id
            LEFT JOIN billing_periods bp ON pi.period_id = bp.id
            LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY pi.id
            ORDER BY pi.due_date ASC
            LIMIT 500");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $collectRule = fin_settings($pdo)['rule_collect_before_pay'] === '1';
        $out = [];
        foreach ($rows as $r) {
            $paid = intval($r['paid']);
            $r['paid'] = $paid;
            $r['fully_collected'] = $paid >= intval($r['amount']);
            // با قانون «اول دریافت، بعد پرداخت»، فقط اقساطِ کاملاً وصول‌شده قابل تسویه‌اند
            $r['can_settle'] = $collectRule ? $r['fully_collected'] : true;
            $out[] = $r;
        }
        echo json_encode(['ok' => true, 'data' => $out, 'rule_collect_before_pay' => $collectRule], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'settle_pasargad') {
        $ids = array_map('intval', $data['installment_ids'] ?? []);
        if (!$ids) { echo json_encode(['ok' => false, 'error' => 'قسطی انتخاب نشده است.']); exit; }

        $collectRule = fin_settings($pdo)['rule_collect_before_pay'] === '1';
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT pi.id, pi.amount, COALESCE(SUM(pa.amount),0) AS paid
            FROM policy_installments pi
            LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
            WHERE pi.id IN ($place) AND pi.settled_to_pasargad = 0
            GROUP BY pi.id");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        $ok = []; $blocked = 0; $total = 0;
        foreach ($rows as $r) {
            if ($collectRule && intval($r['paid']) < intval($r['amount'])) { $blocked++; continue; }
            $ok[] = $r['id']; $total += intval($r['amount']);
        }
        if (!$ok) { echo json_encode(['ok' => false, 'error' => 'هیچ قسطی قابل تسویه نبود (قانون «اول دریافت، بعد پرداخت» فعال است).']); exit; }

        $pdo->prepare("INSERT INTO pasargad_settlements (period_id, company_id, invoice_id, amount, paid_jalali, reference_no, note, created_by)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute([!empty($data['period_id']) ? intval($data['period_id']) : null,
                       !empty($data['company_id']) ? intval($data['company_id']) : null,
                       null, $total, trim($data['paid_jalali'] ?? ''), trim($data['reference_no'] ?? ''),
                       trim($data['note'] ?? ''), $_SESSION['user_id']]);
        $sid = $pdo->lastInsertId();

        $insLine = $pdo->prepare("INSERT INTO pasargad_settlement_lines (settlement_id, installment_id, amount) VALUES (?,?,?)");
        $mark = $pdo->prepare("UPDATE policy_installments SET settled_to_pasargad = 1 WHERE id = ?");
        foreach ($rows as $r) {
            if (!in_array($r['id'], $ok, true)) continue;
            $insLine->execute([$sid, $r['id'], intval($r['amount'])]);
            $mark->execute([$r['id']]);
        }

        echo json_encode(['ok' => true, 'settled' => count($ok), 'blocked' => $blocked, 'total' => $total], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'pasargad_history') {
        $stmt = $pdo->query("
            SELECT ps.*, c.name AS company_name, bp.title AS period_title,
                   (SELECT COUNT(*) FROM pasargad_settlement_lines WHERE settlement_id = ps.id) AS line_count
            FROM pasargad_settlements ps
            LEFT JOIN companies c ON ps.company_id = c.id
            LEFT JOIN billing_periods bp ON ps.period_id = bp.id
            ORDER BY ps.id DESC LIMIT 200");
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
