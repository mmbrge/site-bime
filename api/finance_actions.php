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
require_once __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_fin_ledger.php';

// فیلترهای مشترکِ داشبورد/اقساط/اکسل (دوره با شناسه‌ی billing_periods هم پذیرفته می‌شود)
function fin_request_filters($pdo, array $src) {
    $f = [];
    foreach (['source', 'company_id', 'year', 'from', 'to', 'date_field', 'insurance_type', 'status', 'pasargad', 'invoiced', 'q', 'period'] as $k) {
        if (isset($src[$k]) && $src[$k] !== '') $f[$k] = is_string($src[$k]) ? trim($src[$k]) : $src[$k];
    }
    if (!empty($src['period_id'])) {
        $st = $pdo->prepare("SELECT jalali_year, jalali_month FROM billing_periods WHERE id = ?");
        $st->execute([intval($src['period_id'])]);
        if ($bp = $st->fetch()) $f['period'] = sprintf('%04d-%02d', $bp['jalali_year'], $bp['jalali_month']);
    }
    return $f;
}

$jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($jsonBody['action'] ?? '');
$data = $jsonBody;

// ADMIN، FINANCE و COMPANY_LIAISON به این بخش دسترسی دارند. همکار شرکت‌ها طبق
// خواسته‌ی کارفرما می‌تواند همه‌ی رکوردهای مالی را ببیند و مدیریت کند و برای
// همه‌ی آن‌ها پرداختی ثبت کند - ولی مثل FINANCE به تنظیمات و قالب صورتحساب
// (که فقط با $isAdmin باز می‌شوند) دسترسی ندارد.
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['ADMIN', 'FINANCE', 'COMPANY_LIAISON'], true)) {
    echo json_encode(['ok' => false, 'error' => 'شما به بخش مالی دسترسی ندارید.']); exit;
}
$isAdmin = ($role === 'ADMIN');
try { fin_ensure_schema($pdo); } catch (Throwable $e) { error_log('[finance schema] ' . $e->getMessage()); }

try {
    // =================================================================
    //  مرکز اقساط و پرداخت (دریافت از بیمه‌گذار / پرداخت به بیمه‌گر)
    // =================================================================
    if ($action === 'ledger_meta') {
        fin_ledger_ensure($pdo);
        echo json_encode(['ok' => true, 'methods' => FIN_METHODS, 'insurers' => FIN_INSURERS, 'is_admin' => $isAdmin,
                          'rule_collect_before_pay' => (fin_settings($pdo)['rule_collect_before_pay'] ?? '0') === '1',
                          'companies' => $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'ledger_installments') {
        fin_ledger_ensure($pdo);
        $src = $data + $_GET;
        $f = fin_request_filters($pdo, $src);
        foreach (['stage', 'insurer', 'insurer_status', 'min_delay', 'request_id'] as $k) if (isset($src[$k]) && $src[$k] !== '') $f[$k] = $src[$k];
        $rows = fin_ledger_rows($pdo, $f);
        if (!empty($src['export'])) {
            require_once __DIR__ . '/_xlsx_writer.php';
            $stageFa = ['US' => 'بدهکار به ما', 'INSURER' => 'بدهکار به بیمه‌گر', 'SETTLED' => 'تسویه‌ی نهایی'];
            $headers = ['ردیف', 'کد رهگیری', 'منبع', 'شرکت', 'بیمه‌گذار', 'پرسنل', 'کد پرسنلی', 'کد ملی', 'پلاک', 'شماره بیمه‌نامه', 'نوع بیمه', 'بیمه‌گر',
                        'قسط', 'تاریخ صدور', 'سررسید', 'مبلغ قسط', 'دریافت‌شده', 'مانده‌ی دریافت', 'پرداخت به بیمه‌گر', 'مانده‌ی بیمه‌گر', 'تأخیر (روز)', 'مرحله', 'صورتحساب', 'ثبت‌کننده‌ی دریافت', 'ثبت‌کننده‌ی پرداخت به بیمه‌گر'];
            $regs = fin_ledger_registrars($pdo);
            $x = [];
            foreach ($rows as $i => $r) {
                $x[] = [$i + 1, $r['tracking_code'], $r['source'] === 'C' ? 'شرکتی' : 'کارکنان', $r['company_name'], $r['insured'], $r['source'] === 'P' ? $r['holder_name'] : '',
                        $r['personnel_code'], $r['national_code'], $r['plate'], $r['policy_number'], insurance_type_fa($r['insurance_type']), $r['insurer_fa'],
                        $r['inst_number'], fa_digits($r['issue_jalali']), fa_digits($r['due_jalali']), $r['amount'], $r['paid'], $r['remaining'],
                        $r['paid_insurer'], $r['insurer_remaining'], $r['delay_days'], $stageFa[$r['stage']] ?? '', $r['is_invoiced'] ? 'دارد' : 'ندارد',
                        implode('، ', $regs[$r['source']][$r['id']]['IN'] ?? []), implode('، ', $regs[$r['source']][$r['id']]['OUT'] ?? [])];
            }
            $path = xlsx_build($headers, $x, 'اقساط', [0, 15, 16, 17, 18, 19, 20]);
            xlsx_send($path, 'مرکز اقساط ' . fa_digits(str_replace('.', '-', jalali_from_gregorian_ts_dotted(time()))) . '.xlsx');
            exit;
        }
        $lim = max(100, min(5000, intval($src['limit'] ?? 1500)));
        echo json_encode(['ok' => true, 'summary' => fin_ledger_summary($rows), 'total_rows' => count($rows), 'rows' => array_slice($rows, 0, $lim)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_POST['action'] ?? '') === 'ledger_register') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        $res = fin_ledger_register($pdo, $_POST, is_array($items) ? $items : [], intval($_SESSION['user_id']));
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    // =================================================================
    //  پیگیری: کدِ رهگیریِ قسط، شناسه‌ی درخواست (۵/۶ رقمی)، شماره‌ی پرونده‌ی کارکنان یا شماره‌ی بیمه‌نامه
    // =================================================================
    if ($action === 'track') {
        ref_codes_ensure($pdo);
        fin_ledger_ensure($pdo);
        $raw = p2e_digits((string)($data['q'] ?? ($_GET['q'] ?? '')));
        $codes = array_slice(array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,،;]+/u', $raw)), 'strlen'))), 0, 50);
        if (!$codes) { echo json_encode(['ok' => false, 'error' => 'کدِ رهگیری یا شناسه‌ی درخواست را وارد کنید.']); exit; }
        $all = fin_ledger_rows($pdo, []);
        $byTrack = []; $byCase = []; $byPlate = [];
        foreach ($all as $r) {
            if ($r['tracking_code'] !== null && $r['tracking_code'] !== '') $byTrack[(string)$r['tracking_code']] = $r;
            if ($r['source'] === 'P') $byCase[$r['ref_id']][] = $r; else $byPlate[$r['ref_id']][] = $r;
        }
        $instSum = function (array $rows) {
            $s = ['count' => count($rows), 'amount' => 0, 'paid' => 0, 'remaining' => 0, 'paid_insurer' => 0, 'insurer_remaining' => 0];
            foreach ($rows as $r) foreach (['amount', 'paid', 'remaining', 'paid_insurer', 'insurer_remaining'] as $k) $s[$k] += $r[$k];
            return $s;
        };
        $jd = fn($ts) => $ts ? str_replace('.', '/', jalali_from_gregorian_ts_dotted(strtotime($ts))) : '';
        $companyReq = function ($req) use ($pdo, $byPlate, $instSum, $jd) {
            $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ? ORDER BY id");
            $st->execute([$req['id']]);
            $rows = []; $inst = [];
            foreach ($st->fetchAll() as $p) {
                $rows[] = ['id' => (int)$p['id'], 'plate' => company_row_label($p), 'insurance_type' => $p['insurance_type'], 'status' => $p['status'],
                           'status_fa' => company_plate_status_fa($p['status'], $req['request_kind'] ?? 'NEW_POLICY'), 'policy_number' => $p['policy_number'] ?? '',
                           'premium' => (int)money_to_int($p['total_premium'] ?? 0), 'expiry_jalali' => $p['expiry_date'] ? $jd($p['expiry_date']) : '',
                           'car_name' => $p['car_name'] ?? '', 'chassis_no' => $p['chassis_no'] ?? ''];
                foreach ($byPlate[(int)$p['id']] ?? [] as $i) $inst[] = $i;
            }
            return ['type' => 'company_request', 'id' => (int)$req['id'], 'ref_code' => $req['ref_code'], 'company_name' => $req['company_name'],
                    'insurer' => $req['insurer'], 'request_kind_fa' => company_request_kind_fa($req['request_kind'] ?? 'NEW_POLICY'), 'status' => $req['status'],
                    'created_jalali' => $jd($req['created_at']), 'requested_counts_fa' => company_requested_counts_fa($req['requested_counts'] ?? null),
                    'request_text' => $req['request_text'], 'rows' => $rows, 'installments' => $inst, 'inst_summary' => $instSum($inst)];
        };
        $personCase = function ($c) use ($byCase, $instSum, $jd) {
            $inst = $byCase[(int)$c['id']] ?? [];
            return ['type' => 'personnel_case', 'id' => (int)$c['id'], 'ref_code' => $c['ref_code'], 'unique_code' => $c['unique_code'],
                    'insured' => $c['insured_name'] ?: $c['full_name'], 'holder_name' => $c['full_name'], 'personnel_code' => $c['personnel_code'],
                    'company_name' => $c['company_name'], 'plate' => $c['plate'], 'insurance_type' => $c['insurance_type'], 'status' => $c['status'],
                    'status_fa' => case_status_fa($c['status']), 'policy_number' => $c['policy_number'], 'premium' => (int)money_to_int($c['total_premium'] ?? 0),
                    'created_jalali' => $jd($c['created_at'] ?? null), 'issued_jalali' => $jd($c['issued_at'] ?? null),
                    'installments' => $inst, 'inst_summary' => $instSum($inst)];
        };
        $caseSql = "SELECT pc.*, per.full_name, per.personnel_code, co.name AS company_name FROM policy_cases pc JOIN persons per ON per.id = pc.person_id
                    LEFT JOIN companies co ON co.id = per.company_id WHERE ";
        $reqSql = "SELECT cr.*, c.name AS company_name FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE ";
        $out = [];
        foreach ($codes as $code) {
            $m = []; $seen = [];
            if (isset($byTrack[$code])) {
                $r = $byTrack[$code];
                $r['history'] = fin_ledger_history($pdo, $r['source'], $r['id']);
                $m[] = ['type' => 'installment', 'row' => $r];
            }
            $st = $pdo->prepare($reqSql . "cr.ref_code = ?"); $st->execute([$code]);
            foreach ($st->fetchAll() as $req) { $m[] = $companyReq($req); $seen['C' . $req['id']] = 1; }
            $st = $pdo->prepare($caseSql . "(pc.ref_code = ? OR UPPER(pc.unique_code) = UPPER(?))"); $st->execute([$code, $code]);
            foreach ($st->fetchAll() as $c) { $m[] = $personCase($c); $seen['P' . $c['id']] = 1; }
            // شماره‌ی بیمه‌نامه
            if (!$m && strlen($code) >= 5) {
                $st = $pdo->prepare($reqSql . "cr.id IN (SELECT request_id FROM company_request_plates WHERE policy_number = ?)"); $st->execute([$code]);
                foreach ($st->fetchAll() as $req) if (empty($seen['C' . $req['id']])) $m[] = $companyReq($req);
                $st = $pdo->prepare($caseSql . "pc.policy_number = ?"); $st->execute([$code]);
                foreach ($st->fetchAll() as $c) if (empty($seen['P' . $c['id']])) $m[] = $personCase($c);
            }
            $out[] = ['code' => $code, 'matches' => $m];
        }
        echo json_encode(['ok' => true, 'results' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'ledger_history') {
        $src = ($data['source'] ?? ($_GET['source'] ?? '')) === 'C' ? 'C' : 'P';
        $id = intval($data['id'] ?? ($_GET['id'] ?? 0));
        echo json_encode(['ok' => true, 'history' => fin_ledger_history($pdo, $src, $id)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'ledger_list') {
        $src = $data + $_GET;
        $rows = fin_ledger_list($pdo, $src);
        $sum = ['IN' => 0, 'OUT' => 0, 'cheque_pending' => 0, 'count' => count($rows)];
        foreach ($rows as $r) { if ($r['status'] === 'ACTIVE') $sum[$r['direction']] += $r['amount']; if ($r['cheque_no'] && ($r['cheque_status'] ?? '') === 'PENDING' && $r['status'] === 'ACTIVE') $sum['cheque_pending'] += $r['amount']; }
        if (!empty($src['export'])) {
            require_once __DIR__ . '/_xlsx_writer.php';
            $headers = ['ردیف', 'نوع', 'تاریخ', 'طرف حساب', 'بیمه‌گر', 'مبلغ (ریال)', 'روش', 'شماره پیگیری', 'شماره چک', 'بانک', 'سررسید چک', 'وضعیت چک', 'تعداد قسط', 'توضیح', 'ثبت‌کننده', 'زمان ثبت', 'وضعیت', 'باطل‌کننده'];
            $chq = ['PENDING' => 'در انتظار وصول', 'CLEARED' => 'وصول شد', 'BOUNCED' => 'برگشتی'];
            $x = [];
            foreach ($rows as $i => $r) {
                $x[] = [$i + 1, $r['direction'] === 'IN' ? 'دریافت از بیمه‌گذار' : 'پرداخت به بیمه‌گر', fa_digits($r['paid_jalali']), $r['party'], $r['insurer_fa'],
                        $r['amount'], $r['method_fa'], $r['reference_no'], $r['cheque_no'], $r['cheque_bank'], fa_digits($r['cheque_due_jalali'] ?? ''),
                        $chq[$r['cheque_status'] ?? ''] ?? '', $r['items_count'], $r['note'], $r['created_by_name'] ?? '',
                        !empty($r['created_at']) ? fa_digits(str_replace('.', '/', jalali_from_gregorian_ts_dotted(strtotime($r['created_at']))) . ' ' . date('H:i', strtotime($r['created_at']))) : '', $r['status'] === 'VOID' ? 'باطل' : 'فعال', $r['voided_by_name'] ?? ''];
            }
            $path = xlsx_build($headers, $x, 'دریافت و پرداخت', [0, 5, 12]);
            xlsx_send($path, 'دریافت‌ها و پرداخت‌ها ' . fa_digits(str_replace('.', '-', jalali_from_gregorian_ts_dotted(time()))) . '.xlsx');
            exit;
        }
        echo json_encode(['ok' => true, 'rows' => array_slice($rows, 0, 1500), 'summary' => $sum], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'ledger_receipt') {
        fin_ledger_ensure($pdo);
        $id = intval($data['id'] ?? ($_GET['id'] ?? 0));
        $st = $pdo->prepare("SELECT * FROM fin_receipts WHERE id = ?");
        $st->execute([$id]);
        $rc = $st->fetch();
        if (!$rc) { echo json_encode(['ok' => false, 'error' => 'سند پیدا نشد.']); exit; }
        $rc['files'] = json_decode((string)$rc['files'], true) ?: [];
        $rc['method_fa'] = fin_method_fa($rc['method']);
        $rc['insurer_fa'] = $rc['insurer'] ? fin_insurer_fa($rc['insurer']) : '';
        $rc['created_by_name'] = fin_user_name($pdo, $rc['created_by']);
        $rc['voided_by_name'] = fin_user_name($pdo, $rc['voided_by'] ?? '');
        $rc['created_at_j'] = $rc['created_at'] ? str_replace('.', '/', jalali_from_gregorian_ts_dotted(strtotime($rc['created_at']))) . ' ' . date('H:i', strtotime($rc['created_at'])) : '';
        $ln = $pdo->prepare("SELECT * FROM fin_receipt_lines WHERE receipt_id = ?");
        $ln->execute([$id]);
        $lines = [];
        $byKey = [];
        foreach (fin_unified_installments($pdo, []) as $u) $byKey[$u['source'] . $u['id']] = $u;
        foreach ($ln->fetchAll() as $l) {
            $u = $byKey[$l['source'] . $l['installment_id']] ?? null;
            $lines[] = ['source' => $l['source'], 'installment_id' => (int)$l['installment_id'], 'amount' => (int)$l['amount'],
                        'insured' => $u['insured'] ?? '', 'company_name' => $u['company_name'] ?? '', 'plate' => $u['plate'] ?? '',
                        'policy_number' => $u['policy_number'] ?? '', 'inst_number' => $u['inst_number'] ?? '', 'tracking_code' => $u['tracking_code'] ?? ''];
        }
        echo json_encode(['ok' => true, 'receipt' => $rc, 'lines' => $lines], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'ledger_void') {
        if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل می‌تواند سند را باطل کند.']); exit; }
        echo json_encode(fin_ledger_void($pdo, intval($data['id'] ?? 0), (string)($data['kind'] ?? 'R'), intval($_SESSION['user_id'])), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'ledger_cheque_status') {
        fin_ledger_ensure($pdo);
        $id = intval($data['id'] ?? 0);
        $stv = in_array($data['status'] ?? '', ['PENDING', 'CLEARED', 'BOUNCED'], true) ? $data['status'] : 'PENDING';
        if (($data['kind'] ?? 'R') === 'R') {
            $pdo->prepare("UPDATE fin_receipts SET cheque_status = ? WHERE id = ?")->execute([$stv, $id]);
            $lc = $pdo->prepare("SELECT legacy_cheque_id FROM fin_receipts WHERE id = ?");
            $lc->execute([$id]);
            if ($cid = $lc->fetchColumn()) $pdo->prepare("UPDATE cheques SET status = ? WHERE id = ?")->execute([$stv, $cid]);
        } else {
            // چکِ ثبت‌شده با روشِ قبلی (از رویِ شناسه‌ی دریافت)
            $pdo->prepare("UPDATE cheques SET status = ? WHERE id = (SELECT cheque_id FROM payments WHERE id = ?)")->execute([$stv, $id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // =================================================================
    //  فهرست دوره‌ها و شرکت‌ها (برای پرکردن فیلترها)
    // =================================================================
    if ($action === 'bootstrap') {
        fin_ensure_schema($pdo);
        // اقساطِ جاافتاده (پرسنلی و شرکتی) هوشمند ساخته/اصلاح می‌شوند
        $synced = fin_sync_installments($pdo);
        fin_auto_close_periods($pdo);
        $current = fin_current_period($pdo);
        $periods = $pdo->query("SELECT id, title, status, starts_at, ends_at FROM billing_periods ORDER BY jalali_year DESC, jalali_month DESC")->fetchAll();
        $companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
        echo json_encode([
            'ok' => true, 'periods' => $periods, 'companies' => $companies,
            'current_period_id' => $current['id'] ?? null,
            'settings' => fin_settings($pdo),
            'is_admin' => $isAdmin,
            'synced' => $synced,
            'column_pool' => fin_invoice_column_pool(),
            'default_columns' => ['SUMMARY' => fin_invoice_default_columns('SUMMARY'), 'DETAILED' => fin_invoice_default_columns('DETAILED'), 'PERSONNEL' => fin_invoice_default_columns('PERSONNEL')],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  داشبورد تحلیلی: کارت‌ها، نمودارها و هشدارها با فیلترهای کامل (پرسنلی + شرکتی)
    // =================================================================
    if ($action === 'analytics') {
        $f = fin_request_filters($pdo, $_GET);
        $rows = fin_unified_installments($pdo, $f);
        $today = date('Y-m-d');
        $k = ['premium' => 0, 'policies' => 0, 'inst_amount' => 0, 'collected' => 0, 'remaining' => 0,
              'overdue' => 0, 'overdue_count' => 0, 'upcoming' => 0, 'upcoming_count' => 0,
              'settled' => 0, 'pasargad_due' => 0, 'companies' => 0, 'inst_count' => count($rows)];
        $policies = []; $companies = []; $byPeriod = []; $byDue = []; $status = ['PAID' => 0, 'PARTIAL' => 0, 'OPEN' => 0, 'OVERDUE' => 0];
        $bySource = ['P' => ['premium' => 0, 'count' => 0, 'remaining' => 0], 'C' => ['premium' => 0, 'count' => 0, 'remaining' => 0]];
        $byType = [];
        foreach ($rows as $r) {
            $pk = $r['source'] . $r['ref_id'];
            $k['inst_amount'] += $r['amount']; $k['collected'] += min($r['paid'], $r['amount']); $k['remaining'] += $r['remaining'];
            if ($r['overdue']) { $k['overdue'] += $r['remaining']; $k['overdue_count']++; }
            if ($r['upcoming']) { $k['upcoming'] += $r['remaining']; $k['upcoming_count']++; }
            if ($r['settled']) $k['settled'] += $r['amount'];
            // دریافت‌شده ولی هنوز به پاسارگاد تسویه‌نشده
            if (!$r['settled']) $k['pasargad_due'] += min($r['paid'], $r['amount']);

            if ($r['pay_status'] === 'PAID') $status['PAID'] += $r['amount'];
            elseif ($r['overdue']) $status['OVERDUE'] += $r['remaining'];
            elseif ($r['pay_status'] === 'PARTIAL') $status['PARTIAL'] += $r['remaining'];
            else $status['OPEN'] += $r['remaining'];

            $cn = $r['company_name'];
            if (!isset($companies[$cn])) $companies[$cn] = ['name' => $cn, 'amount' => 0, 'paid' => 0, 'remaining' => 0, 'overdue' => 0, 'policies' => []];
            $companies[$cn]['amount'] += $r['amount']; $companies[$cn]['paid'] += min($r['paid'], $r['amount']);
            $companies[$cn]['remaining'] += $r['remaining']; if ($r['overdue']) $companies[$cn]['overdue'] += $r['remaining'];
            $companies[$cn]['policies'][$pk] = 1;

            $dm = substr($r['due_jalali'], 0, 7);
            if (!isset($byDue[$dm])) $byDue[$dm] = ['due' => 0, 'paid' => 0, 'remaining' => 0, 'settled' => 0];
            $byDue[$dm]['due'] += $r['amount']; $byDue[$dm]['paid'] += min($r['paid'], $r['amount']);
            $byDue[$dm]['remaining'] += $r['remaining']; if ($r['settled']) $byDue[$dm]['settled'] += $r['amount'];

            if (!isset($policies[$pk])) {
                $policies[$pk] = 1;
                $k['premium'] += $r['premium']; $k['policies']++;
                $bySource[$r['source']]['premium'] += $r['premium']; $bySource[$r['source']]['count']++;
                $per = $r['period'];
                if (!isset($byPeriod[$per])) $byPeriod[$per] = ['premium_p' => 0, 'premium_c' => 0, 'count_p' => 0, 'count_c' => 0];
                $byPeriod[$per]['premium_' . strtolower($r['source'])] += $r['premium'];
                $byPeriod[$per]['count_' . strtolower($r['source'])]++;
                $t = insurance_type_fa($r['insurance_type']) ?: 'نامشخص';
                if (!isset($byType[$t])) $byType[$t] = ['premium' => 0, 'count' => 0];
                $byType[$t]['premium'] += $r['premium']; $byType[$t]['count']++;
            }
            $bySource[$r['source']]['remaining'] += $r['remaining'];
        }
        $k['companies'] = count($companies);
        ksort($byPeriod); ksort($byDue);
        $companies = array_values(array_map(function ($c) { $c['policies'] = count($c['policies']); return $c; }, $companies));
        usort($companies, function ($a, $b) { return $b['amount'] <=> $a['amount']; });

        $alertRow = function ($r) {
            return ['source' => $r['source'], 'company_name' => $r['company_name'], 'insured' => $r['insured'], 'plate' => $r['plate'],
                    'inst_number' => $r['inst_number'], 'due_jalali' => $r['due_jalali'], 'amount' => $r['amount'],
                    'paid' => $r['paid'], 'remaining' => $r['remaining'], 'tracking_code' => $r['tracking_code'], 'policy_number' => $r['policy_number']];
        };
        $overdue = []; $upcoming = []; $unpaid = [];
        foreach ($rows as $r) {
            if ($r['overdue'] && count($overdue) < 200) $overdue[] = $alertRow($r);
            if ($r['upcoming'] && count($upcoming) < 200) $upcoming[] = $alertRow($r);
            if ($r['paid'] <= 0 && $r['remaining'] > 0 && count($unpaid) < 200) $unpaid[] = $alertRow($r);
        }
        try { $k['invoices'] = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE COALESCE(status,'ISSUED') <> 'INACTIVE'")->fetchColumn(); } catch (Throwable $e) { $k['invoices'] = 0; }

        echo json_encode(['ok' => true, 'kpi' => $k, 'status' => $status, 'by_period' => $byPeriod, 'by_due' => $byDue,
            'companies' => array_slice($companies, 0, 50), 'by_source' => $bySource, 'by_type' => $byType,
            'alerts' => ['overdue' => $overdue, 'upcoming' => $upcoming, 'unpaid' => $unpaid], 'today' => $today], JSON_UNESCAPED_UNICODE);
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
        $res = fin_generate_installments($pdo, $caseId, $count, $method, $isAdmin && !empty($data['force']));
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
            'due_mode'                => ($data['due_mode'] ?? 'issue') === 'period15' ? 'period15' : 'issue',
            'allow_multiple_invoices' => !empty($data['allow_multi']) ? '1' : '0',
            'inv_full_policy'         => !empty($data['inv_full_policy']) ? '1' : '0',
            'inv_company_subtotal'    => !empty($data['inv_subtotal']) ? '1' : '0',
            'inv_merge_company'       => !empty($data['inv_merge']) ? '1' : '0',
        ];
        foreach (['SUMMARY', 'DETAILED', 'PERSONNEL'] as $kd) {
            if (isset($data['inv_cols'][$kd])) $map['inv_cols_' . $kd] = json_encode(fin_invoice_columns($kd, $data['inv_cols'][$kd]));
        }
        foreach ($map as $k => $v) fin_set($pdo, $k, (string)$v);
        echo json_encode(['ok' => true]);
        exit;
    }

    // =================================================================
    //  مدیریتِ قالب‌های صورتحساب (مثلِ قالب‌های گزارش بازدید):
    //  فهرست + نسخه‌های قبلی، بررسیِ کدها، پیش‌نمایش با داده‌ی نمونه، دانلود، قالبِ آماده، فعال‌سازی/حذف
    // =================================================================
    if (in_array($action, ['tpl_list', 'tpl_activate', 'tpl_delete', 'tpl_download', 'tpl_preview', 'tpl_preview_file', 'tpl_starter'], true)) {
        if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'فقط مدیر به قالب‌های صورتحساب دسترسی دارد.']); exit; }
        $siteRoot = dirname(__DIR__);
        $getTpl = function ($id) use ($pdo) {
            $st = $pdo->prepare("SELECT * FROM invoice_templates WHERE id = ?");
            $st->execute([intval($id)]);
            return $st->fetch();
        };
        $sendFile = function ($abs, $name, $type) {
            header_remove('Content-Type');
            header('Content-Type: ' . $type);
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
            header('Content-Length: ' . filesize($abs));
            readfile($abs);
            exit;
        };
        $previewDir = $siteRoot . '/tmp_ocr/inv_preview';

        if ($action === 'tpl_list') {
            $all = $pdo->query("SELECT id, kind, title, docx_path, uploaded_at, is_active FROM invoice_templates ORDER BY id DESC")->fetchAll();
            $kinds = [];
            foreach (['SUMMARY', 'PERSONNEL', 'DETAILED'] as $k) $kinds[$k] = ['kind' => $k, 'kind_fa' => fin_kind_fa($k), 'active' => null, 'history' => []];
            foreach ($all as $t) {
                $k = fin_kind($t['kind']);
                $abs = $siteRoot . '/' . $t['docx_path'];
                $t['exists'] = is_file($abs);
                $t['size'] = $t['exists'] ? filesize($abs) : 0;
                $t['uploaded_jalali'] = $t['uploaded_at'] ? jalali_from_gregorian_ts_dotted(strtotime($t['uploaded_at'])) : ($t['exists'] ? jalali_from_gregorian_ts_dotted(filemtime($abs)) : '');
                if ((string)$t['is_active'] === '1' && !$kinds[$k]['active']) {
                    $t['analysis'] = $t['exists'] ? fin_tpl_analyze($abs) : ['ok' => false, 'error' => 'فایلِ قالب روی سرور پیدا نشد.'];
                    $kinds[$k]['active'] = $t;
                } elseif (count($kinds[$k]['history']) < 10) {
                    $kinds[$k]['history'][] = $t;
                }
            }
            $catalog = [];
            foreach (fin_invoice_var_catalog() as $code => $desc) $catalog[] = ['code' => $code, 'desc' => $desc];
            echo json_encode(['ok' => true, 'kinds' => array_values($kinds), 'catalog' => $catalog, 'legacy' => fin_invoice_legacy_codes(),
                              'pdf_converter' => fin_pdf_converter_available()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'tpl_activate') {
            $t = $getTpl($data['id'] ?? 0);
            if (!$t) { echo json_encode(['ok' => false, 'error' => 'قالب پیدا نشد.']); exit; }
            if (!is_file($siteRoot . '/' . $t['docx_path'])) { echo json_encode(['ok' => false, 'error' => 'فایلِ این نسخه روی سرور نیست.']); exit; }
            $pdo->prepare("UPDATE invoice_templates SET is_active = 0 WHERE kind = ?")->execute([$t['kind']]);
            $pdo->prepare("UPDATE invoice_templates SET is_active = 1 WHERE id = ?")->execute([$t['id']]);
            echo json_encode(['ok' => true]);
            exit;
        }
        if ($action === 'tpl_delete') {
            $t = $getTpl($data['id'] ?? 0);
            if (!$t) { echo json_encode(['ok' => false, 'error' => 'قالب پیدا نشد.']); exit; }
            // فایلی که صورتحساب‌های قبلی از آن ساخته شده‌اند فقط از فهرست حذف می‌شود؛ خودِ صورتحساب‌ها فایلِ خودشان را دارند
            $pdo->prepare("DELETE FROM invoice_templates WHERE id = ?")->execute([$t['id']]);
            $abs = $siteRoot . '/' . $t['docx_path'];
            if (is_file($abs) && strpos(realpath($abs), realpath($siteRoot . '/tmpl_invoice')) === 0) @unlink($abs);
            echo json_encode(['ok' => true, 'was_active' => (string)$t['is_active'] === '1']);
            exit;
        }
        if ($action === 'tpl_download') {
            $t = $getTpl($_GET['id'] ?? 0);
            $abs = $t ? $siteRoot . '/' . $t['docx_path'] : '';
            if (!$t || !is_file($abs)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'قالب پیدا نشد.']); exit; }
            $name = 'قالب صورتحساب ' . preg_replace('/\s*\(.*\)\s*/u', '', fin_kind_fa($t['kind'])) . '.docx';
            $sendFile($abs, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }
        if ($action === 'tpl_starter') {
            $kind = fin_kind($_GET['kind'] ?? '');
            if (!is_dir($previewDir)) @mkdir($previewDir, 0777, true);
            $tmp = $previewDir . '/starter_' . $kind . '_' . bin2hex(random_bytes(4)) . '.docx';
            if (!fin_tpl_starter_docx($kind, $tmp)) { echo json_encode(['ok' => false, 'error' => 'ساختِ قالب ممکن نشد.']); exit; }
            register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
            $sendFile($tmp, 'قالب آماده ' . preg_replace('/\s*\(.*\)\s*/u', '', fin_kind_fa($kind)) . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }
        if ($action === 'tpl_preview') {
            // نسخه‌ی مشخص (id) یا قالبِ فعالِ یک نوع (kind)
            $t = !empty($data['id']) ? $getTpl($data['id']) : null;
            if (!$t && !empty($data['kind'])) {
                $st = $pdo->prepare("SELECT * FROM invoice_templates WHERE kind = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
                $st->execute([fin_kind($data['kind'])]);
                $t = $st->fetch();
            }
            if (!$t || !is_file($siteRoot . '/' . $t['docx_path'])) { echo json_encode(['ok' => false, 'error' => 'قالبی برای پیش‌نمایش پیدا نشد.']); exit; }
            // پیش‌نمایش‌های قدیمی (بیش از یک ساعت) پاک می‌شوند
            if (is_dir($previewDir)) foreach (glob($previewDir . '/p_*') ?: [] as $old) {
                if (is_dir($old) && filemtime($old) < time() - 3600) { array_map('unlink', glob($old . '/*') ?: []); @rmdir($old); }
            }
            $token = bin2hex(random_bytes(8));
            $dir = $previewDir . '/p_' . $token;
            @mkdir($dir, 0777, true);
            if (!is_file($previewDir . '/.htaccess')) @file_put_contents($previewDir . '/.htaccess', "Require all denied\nDeny from all\n");
            $r = fin_tpl_render_preview($pdo, fin_kind($t['kind']), $siteRoot . '/' . $t['docx_path'], $dir);
            if (empty($r['ok'])) { echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'خطا'], JSON_UNESCAPED_UNICODE); exit; }
            echo json_encode(['ok' => true, 'token' => $token, 'html' => $r['html'], 'has_pdf' => (bool)$r['pdf'], 'vars' => $r['vars'],
                              'analysis' => fin_tpl_analyze($siteRoot . '/' . $t['docx_path'])], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'tpl_preview_file') {
            $token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? ''));
            $fmt = ($_GET['fmt'] ?? '') === 'pdf' ? 'pdf' : 'docx';
            $abs = $previewDir . '/p_' . $token . '/preview.' . $fmt;
            if (!$token || !is_file($abs)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'پیش‌نمایش منقضی شده؛ دوباره بسازید.']); exit; }
            if ($fmt === 'pdf') {
                header_remove('Content-Type');
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="preview.pdf"');
                header('Content-Length: ' . filesize($abs));
                readfile($abs);
                exit;
            }
            $sendFile($abs, 'پیش‌نمایش صورتحساب.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }
    }

    // =================================================================
    //  آپلود قالب Word صورتحساب (فقط ADMIN)
    // =================================================================
    if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'upload_template') {
        if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'فقط مدیر می‌تواند قالب را تغییر دهد.']); exit; }
        // هر سه نوع (تجمیعی، تلفیقی، تفکیکی) قالب جداگانه دارند؛ قبلاً قالب «تلفیقی» اشتباهاً به‌جای «تفکیکی» ذخیره می‌شد
        $kind = fin_kind($_POST['kind'] ?? '');
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'docx') { echo json_encode(['ok' => false, 'error' => 'فقط فایل docx پذیرفته می‌شود.']); exit; }

        $siteRoot = dirname(__DIR__);
        $dir = $siteRoot . '/tmpl_invoice';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $dest = unique_dest_path($dir . '/' . $kind . '_' . time() . '.docx');
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل.']); exit;
        }
        $chk = fin_tpl_analyze($dest);
        if (empty($chk['ok'])) { @unlink($dest); echo json_encode(['ok' => false, 'error' => $chk['error'] ?? 'فایلِ Word معتبر نیست.'], JSON_UNESCAPED_UNICODE); exit; }
        $rel = ltrim(str_replace($siteRoot, '', $dest), '/');
        $pdo->prepare("UPDATE invoice_templates SET is_active = 0 WHERE kind = ?")->execute([$kind]);
        $pdo->prepare("INSERT INTO invoice_templates (kind, title, docx_path, is_active) VALUES (?, ?, ?, 1)")
            ->execute([$kind, $_FILES['file']['name'], $rel]);
        echo json_encode(['ok' => true, 'path' => $rel, 'analysis' => fin_tpl_analyze($dest)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =================================================================
    //  خروجی اکسل اقساط
    //  نکته: کتابخانه‌ی اکسل روی این هاست نصب نیست، پس از فرمت SpreadsheetML (XML اکسل)
    //  استفاده می‌کنیم که بدون هیچ وابستگی ساخته می‌شود و اکسل آن را با فرمت‌بندی کامل
    //  (راست‌چین، فونت B Nazanin، سربرگ بولد) باز می‌کند.
    // =================================================================
    if ($action === 'export_installments') {
        $rows = fin_unified_installments($pdo, fin_request_filters($pdo, $_GET));
        $headers = ['ردیف','منبع','دوره','شرکت','بیمه‌گذار / پرسنل','کد ملی','کد پرسنلی','پلاک','نوع بیمه','شماره بیمه‌نامه',
                    'کد رهگیری','شماره قسط','سررسید','مبلغ قسط (ریال)','دریافت‌شده','مانده','وضعیت','تسویه با پاسارگاد'];
        $stFa = ['PAID' => 'تسویه‌شده', 'PARTIAL' => 'ناقص', 'UNPAID' => 'پرداخت‌نشده'];
        $out = []; $i = 1;
        foreach ($rows as $r) {
            [$py, $pm] = array_map('intval', explode('-', $r['period']));
            $out[] = [$i++, $r['source'] === 'C' ? 'شرکتی' : 'پرسنلی', fin_month_name($pm) . ' ' . $py, $r['company_name'], $r['insured'],
                      $r['national_code'], $r['personnel_code'], $r['plate'], insurance_type_fa($r['insurance_type']), $r['policy_number'],
                      $r['tracking_code'], $r['inst_number'], $r['due_jalali'], $r['amount'], $r['paid'], $r['remaining'],
                      ($r['overdue'] ? 'معوق - ' : '') . $stFa[$r['pay_status']], $r['settled'] ? 'بله' : 'خیر'];
        }
        fin_send_excel('گزارش-اقساط', $headers, $out, [13, 14, 15]);
        exit;
    }

    // =================================================================
    //  «بانک جامع اطلاعات و اقساط کل» (هم‌شکل با اکسل جامع ماموت): هر بیمه‌نامه یک ردیف،
    //  با همه‌ی اقساطش در ستون‌های کنار هم (مبلغ و وضعیت)، جمع دریافتی، مانده و تسویه با پاسارگاد
    // =================================================================
    if ($action === 'export_master') {
        $rows = fin_unified_installments($pdo, fin_request_filters($pdo, $_GET));
        $pol = []; $maxN = 0;
        foreach ($rows as $r) {
            $key = $r['source'] . $r['ref_id'];
            if (!isset($pol[$key])) $pol[$key] = ['r' => $r, 'inst' => [], 'paid' => 0, 'rem' => 0, 'settled' => 0, 'sum' => 0];
            $pol[$key]['inst'][$r['inst_number']] = $r;
            $pol[$key]['paid'] += min($r['paid'], $r['amount']); $pol[$key]['rem'] += $r['remaining'];
            $pol[$key]['sum'] += $r['amount']; if ($r['settled']) $pol[$key]['settled'] += $r['amount'];
            $maxN = max($maxN, $r['inst_number']);
        }
        uasort($pol, function ($a, $b) { return [$a['r']['company_name'], $a['r']['insured'], $a['r']['policy_number']] <=> [$b['r']['company_name'], $b['r']['insured'], $b['r']['policy_number']]; });
        $headers = ['ردیف','منبع','دوره','شرکت','بیمه‌گذار / پرسنل','کد ملی','کد پرسنلی','پلاک','نوع بیمه','شماره بیمه‌نامه','تاریخ صدور','حق بیمه','تعداد اقساط'];
        for ($n = 1; $n <= $maxN; $n++) { $headers[] = "قسط $n"; $headers[] = "سررسید $n"; $headers[] = "وضعیت $n"; }
        array_push($headers, 'جمع اقساط', 'دریافت‌شده', 'مانده', 'معوق', 'تسویه‌شده با پاسارگاد', 'وضعیت صورتحساب');
        $numeric = [11];
        for ($n = 1; $n <= $maxN; $n++) $numeric[] = 13 + ($n - 1) * 3;
        $base = 13 + $maxN * 3;
        array_push($numeric, $base, $base + 1, $base + 2, $base + 3, $base + 4);
        $out = []; $i = 1;
        foreach ($pol as $p) {
            $r = $p['r'];
            [$py, $pm] = array_map('intval', explode('-', $r['period']));
            $line = [$i++, $r['source'] === 'C' ? 'شرکتی' : 'پرسنلی', fin_month_name($pm) . ' ' . $py, $r['company_name'], $r['insured'],
                     $r['national_code'], $r['personnel_code'], $r['plate'], insurance_type_fa($r['insurance_type']), $r['policy_number'],
                     $r['issue_jalali'], $r['premium'], count($p['inst'])];
            $overdue = 0;
            for ($n = 1; $n <= $maxN; $n++) {
                $x = $p['inst'][$n] ?? null;
                if (!$x) { array_push($line, '', '', ''); continue; }
                if ($x['overdue']) $overdue += $x['remaining'];
                $st = $x['pay_status'] === 'PAID' ? 'پرداخت‌شده' : ($x['overdue'] ? 'معوق' : ($x['pay_status'] === 'PARTIAL' ? 'ناقص' : 'باز'));
                if ($x['settled']) $st .= ' / پاسارگاد✓';
                array_push($line, $x['amount'], $x['due_jalali'], $st);
            }
            array_push($line, $p['sum'], $p['paid'], $p['rem'], $overdue, $p['settled'],
                       $r['source'] === 'C' ? '—' : ($r['is_invoiced'] ? 'دارای صورتحساب' : 'بدون صورتحساب'));
            $out[] = $line;
        }
        fin_send_excel('بانک جامع اطلاعات و اقساط کل', $headers, $out, $numeric);
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

        $mode = fin_invoice_mode($pdo, $_GET['mode'] ?? 'uninvoiced');
        if (is_array($mode)) { echo json_encode($mode, JSON_UNESCAPED_UNICODE); exit; }
        $rows = fin_collect_invoice_rows($pdo, $periodId, $companyId, $mode);
        $alreadyInvoiced = count(array_filter($rows, function ($r) { return (int)$r['is_invoiced'] === 1; }));

        if ($kind === 'SUMMARY') {
            $byCompany = [];
            foreach ($rows as $r) {
                $cid = $r['company_id'] ?: 0;
                if (!isset($byCompany[$cid])) $byCompany[$cid] = ['company_name' => $r['company_name'] ?: 'بدون شرکت', 'policy_count' => 0, 'third_count' => 0, 'body_count' => 0, 'total_premium' => 0, 'monthly_amount' => 0];
                $byCompany[$cid]['policy_count']++;
                $byCompany[$cid][fin_is_body($r['insurance_type']) ? 'body_count' : 'third_count']++;
                $byCompany[$cid]['total_premium'] += intval($r['total_premium']);
                $byCompany[$cid]['monthly_amount'] += intval($r['monthly_amount']);
            }
            $lines = array_values($byCompany);
        } elseif ($kind === 'PERSONNEL') {
            // هر بیمه‌نامه یک ردیف، شامل نام شرکت (چون این نوع روی کل دوره/همه‌ی
            // شرکت‌هاست - هم‌شکل با «نوع ۳» برنامه‌ی قدیمی که ستون شرکت را هم داشت)
            $lines = array_map(fn($r) => [
                'case_id' => $r['case_id'], 'person_name' => $r['insured_name'] ?: $r['holder_name'],
                'company_name' => $r['company_name'],
                'personnel_code' => $r['personnel_code'], 'national_code' => $r['national_code'],
                'insurance_type' => insurance_type_fa($r['insurance_type']),
                'plate' => $r['plate'], 'policy_number' => $r['policy_number'], 'issue_date' => $r['issue_date'],
                'is_invoiced' => (int)$r['is_invoiced'],
                'total_premium' => intval($r['total_premium']),
                'monthly_amount' => intval($r['monthly_amount']),
            ], $rows);
        } else {
            $lines = array_map(fn($r) => [
                'case_id' => $r['case_id'], 'person_name' => $r['insured_name'] ?: $r['holder_name'],
                'national_code' => $r['national_code'], 'personnel_code' => $r['personnel_code'],
                'plate' => $r['plate'], 'insurance_type' => insurance_type_fa($r['insurance_type']),
                'policy_number' => $r['policy_number'], 'total_premium' => intval($r['total_premium']),
                'monthly_amount' => intval($r['monthly_amount']), 'company_name' => $r['company_name'],
                'issue_date' => $r['issue_date'], 'is_invoiced' => (int)$r['is_invoiced'],
            ], $rows);
        }

        $total = array_sum(array_map(fn($l) => $l['total_premium'], $lines));
        $monthly = array_sum(array_map(fn($l) => $l['monthly_amount'], $lines));

        echo json_encode([
            'ok' => true, 'kind' => $kind, 'lines' => $lines,
            'total' => $total, 'monthly' => $monthly, 'count' => count($lines),
            'policies' => count($rows), 'already_invoiced' => $alreadyInvoiced, 'mode' => $mode,
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

        $mode = fin_invoice_mode($pdo, $data['mode'] ?? 'uninvoiced');
        if (is_array($mode)) { echo json_encode($mode, JSON_UNESCAPED_UNICODE); exit; }
        $rows = fin_collect_invoice_rows($pdo, $periodId, $companyId, $mode);
        if (!$rows) { echo json_encode(['ok' => false, 'error' => 'برای این انتخاب، بیمه‌نامه‌ای وجود ندارد.']); exit; }
        // هم‌شکل با ماموت: صدور دوباره برای بیمه‌نامه‌هایی که صورتحساب دارند، تایید صریح می‌خواهد
        $already = count(array_filter($rows, function ($r) { return (int)$r['is_invoiced'] === 1; }));
        if ($already && empty($data['confirm_reinvoice'])) {
            echo json_encode(['ok' => false, 'need_confirm' => true, 'already_invoiced' => $already,
                              'error' => "$already بیمه‌نامه از این فهرست قبلاً صورتحساب دارند."], JSON_UNESCAPED_UNICODE); exit;
        }
        $s = fin_settings($pdo);
        $options = [
            'columns' => fin_invoice_columns($kind, $data['columns'] ?? (json_decode((string)($s['inv_cols_' . $kind] ?? ''), true) ?: [])),
            'full_policy' => isset($data['full_policy']) ? !empty($data['full_policy']) : (($s['inv_full_policy'] ?? '1') === '1'),
            'company_subtotal' => isset($data['company_subtotal']) ? !empty($data['company_subtotal']) : (($s['inv_company_subtotal'] ?? '0') === '1'),
            'merge_company' => isset($data['merge_company']) ? !empty($data['merge_company']) : (($s['inv_merge_company'] ?? '0') === '1'),
            'mode' => $mode,
        ];

        $invoiceNo = fin_next_invoice_no($pdo);
        $siteRoot = dirname(__DIR__);
        $folder = fin_invoice_folder($siteRoot, $period, $companyName, $invoiceNo);
        if (!is_dir($folder) && !@mkdir($folder, 0777, true) && !is_dir($folder)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در ساخت پوشه‌ی بایگانی مالی.']); exit;
        }

        $total = 0;
        // شماره‌ی تکراری میان صورتحساب‌های فعال مجاز نیست
        $dup = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no = ? AND COALESCE(status,'ISSUED') <> 'INACTIVE'");
        $dup->execute([$invoiceNo]);
        if ($dup->fetchColumn()) { echo json_encode(['ok' => false, 'error' => "شماره‌ی صورتحساب $invoiceNo قبلاً برای صورتحساب فعال دیگری ثبت شده است؛ شمارنده را در تنظیمات مالی بررسی کنید."], JSON_UNESCAPED_UNICODE); exit; }

        $pdo->prepare("INSERT INTO invoices (invoice_no, kind, company_id, period_id, total_amount, folder_path, manual_company, created_by, status, options_json)
                       VALUES (?, ?, ?, ?, 0, ?, ?, ?, 'ISSUED', ?)")
            ->execute([$invoiceNo, $kind, $companyId, $periodId, $folder, $manualCompany ?: null, $_SESSION['user_id'], json_encode($options, JSON_UNESCAPED_UNICODE)]);
        $invoiceId = $pdo->lastInsertId();

        $insLine = $pdo->prepare("INSERT INTO invoice_lines
            (invoice_id, case_id, company_id, person_name, national_code, personnel_code, plate, insurance_type, policy_number, policy_count, total_premium, monthly_amount, issue_date, third_count, body_count)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        if ($kind === 'SUMMARY') {
            $byCompany = [];
            foreach ($rows as $r) {
                $cid = $r['company_id'] ?: 0;
                if (!isset($byCompany[$cid])) $byCompany[$cid] = ['name' => $r['company_name'] ?: 'بدون شرکت', 'count' => 0, 'premium' => 0, 'monthly' => 0, 'third' => 0, 'body' => 0];
                $byCompany[$cid]['count']++;
                $byCompany[$cid][fin_is_body($r['insurance_type']) ? 'body' : 'third']++;
                $byCompany[$cid]['premium'] += intval($r['total_premium']);
                $byCompany[$cid]['monthly'] += intval($r['monthly_amount']);
            }
            foreach ($byCompany as $cid => $g) {
                $insLine->execute([$invoiceId, null, $cid ?: null, $g['name'], null, null, null, null, null, $g['count'], $g['premium'], $g['monthly'], null, $g['third'], $g['body']]);
                $total += $g['premium'];
            }
        } else {
            // DETAILED و PERSONNEL هر دو یک ردیف به‌ازای هر بیمه‌نامه دارند؛ تفاوتشان فقط
            // در ستون‌هایی است که هنگام ساخت جدول Word نمایش داده می‌شود
            foreach ($rows as $r) {
                $insLine->execute([$invoiceId, $r['case_id'], $r['company_id'],
                    $r['insured_name'] ?: $r['holder_name'], $r['national_code'], $r['personnel_code'],
                    $r['plate'], insurance_type_fa($r['insurance_type']), $r['policy_number'],
                    null, intval($r['total_premium']), intval($r['monthly_amount']), $r['issue_date'], null, null]);
                $total += intval($r['total_premium']);
            }
        }

        $pdo->prepare("UPDATE invoices SET total_amount = ? WHERE id = ?")->execute([$total, $invoiceId]);

        // هم‌شکل با برنامه‌ی قدیمی: پرونده‌هایی که وارد این صورتحساب شدند، علامت
        // می‌خورند تا دفعه‌ی بعد (در هیچ نوع صورتحسابی) دوباره صورتحساب نشوند
        $caseIds = array_values(array_unique(array_filter(array_column($rows, 'case_id'))));
        if ($caseIds) {
            $ph = implode(',', array_fill(0, count($caseIds), '?'));
            $pdo->prepare("UPDATE policy_cases SET is_invoiced = 1, invoiced_invoice_id = ? WHERE id IN ($ph)")
                ->execute(array_merge([$invoiceId], $caseIds));
        }

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
        if (($_GET['status'] ?? '') === 'ISSUED')   $where[] = "COALESCE(i.status,'ISSUED') <> 'INACTIVE'";
        if (($_GET['status'] ?? '') === 'INACTIVE') $where[] = "i.status = 'INACTIVE'";
        if (!empty($_GET['q'])) { $where[] = "(i.invoice_no LIKE ? OR c.name LIKE ? OR i.manual_company LIKE ?)"; $lk = '%' . p2e_digits(trim($_GET['q'])) . '%'; array_push($params, $lk, $lk, $lk); }
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

    // =================================================================
    //  غیرفعال‌سازی صورتحساب (هم‌شکل با deactivate_invoice ماموت) - برگشت‌ناپذیر:
    //   ۱) Word/PDF به زیرپوشه‌ی «صورت حساب های غیر فعال» همان پوشه منتقل می‌شوند
    //   ۲) وضعیت «غیرفعال» می‌شود
    //   ۳) بیمه‌نامه‌هایش آزاد می‌شوند (مگر صورتحساب فعال دیگری هم داشته باشند) تا دوباره صورتحساب شوند
    // =================================================================
    if ($action === 'deactivate_invoice') {
        if (!$isAdmin && $role !== 'FINANCE') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر یا مالی می‌تواند صورتحساب را غیرفعال کند.']); exit; }
        $password = trim($data['password'] ?? '');
        $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([$_SESSION['user_id']]);
        $hash = $st->fetchColumn();
        if ($password === '' || !$hash || !password_verify($password, $hash)) { echo json_encode(['ok' => false, 'error' => 'رمز عبور نادرست است.']); exit; }

        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $st->execute([$id]);
        $inv = $st->fetch();
        if (!$inv) { echo json_encode(['ok' => false, 'error' => 'صورتحساب یافت نشد.']); exit; }
        if (($inv['status'] ?? '') === 'INACTIVE') { echo json_encode(['ok' => false, 'error' => 'این صورتحساب قبلاً غیرفعال شده است.']); exit; }
        $pay = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ?");
        $pay->execute([$id]);
        if ($pay->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'برای این صورتحساب دریافتی ثبت شده؛ اول دریافتی‌ها را از آن جدا کنید.']); exit; }

        $siteRoot = dirname(__DIR__);
        $moved = [];
        $newPaths = [];
        foreach (['docx_path', 'pdf_path'] as $col) {
            if (empty($inv[$col])) continue;
            $src = $siteRoot . '/' . $inv[$col];
            if (!is_file($src)) continue;
            $destDir = dirname($src) . '/صورت حساب های غیر فعال';
            if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
            $ext = pathinfo($src, PATHINFO_EXTENSION);
            $dest = unique_dest_path($destDir . '/' . $inv['invoice_no'] . '.' . $ext);
            if (!@rename($src, $dest)) {
                foreach ($moved as [$a, $b]) @rename($b, $a);
                echo json_encode(['ok' => false, 'error' => 'انتقال فایل‌های صورتحساب ممکن نشد.']); exit;
            }
            $moved[] = [$src, $dest];
            $newPaths[$col] = ltrim(str_replace($siteRoot, '', $dest), '/');
        }
        $pdo->prepare("UPDATE invoices SET status = 'INACTIVE', deactivated_at = NOW(), docx_path = ?, pdf_path = ? WHERE id = ?")
            ->execute([$newPaths['docx_path'] ?? $inv['docx_path'], $newPaths['pdf_path'] ?? $inv['pdf_path'], $id]);

        // آزادسازی بیمه‌نامه‌ها
        $cs = $pdo->prepare("SELECT DISTINCT case_id FROM invoice_lines WHERE invoice_id = ? AND case_id IS NOT NULL");
        $cs->execute([$id]);
        $caseIds = $cs->fetchAll(PDO::FETCH_COLUMN);
        if ($inv['kind'] === 'SUMMARY') {
            // صورتحساب تجمیعی ردیفِ پرونده ندارد؛ پرونده‌هایی که با همین صورتحساب علامت خورده‌اند
            $cs = $pdo->prepare("SELECT id FROM policy_cases WHERE invoiced_invoice_id = ?");
            $cs->execute([$id]);
            $caseIds = array_merge($caseIds, $cs->fetchAll(PDO::FETCH_COLUMN));
        }
        $freed = 0; $still = 0;
        $other = $pdo->prepare("SELECT MAX(i.id) FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id
                                WHERE il.case_id = ? AND i.id <> ? AND COALESCE(i.status,'ISSUED') <> 'INACTIVE'");
        foreach (array_unique($caseIds) as $cid) {
            $other->execute([$cid, $id]);
            $keep = $other->fetchColumn();
            if ($keep) { $pdo->prepare("UPDATE policy_cases SET is_invoiced = 1, invoiced_invoice_id = ? WHERE id = ?")->execute([$keep, $cid]); $still++; }
            else { $pdo->prepare("UPDATE policy_cases SET is_invoiced = 0, invoiced_invoice_id = NULL WHERE id = ?")->execute([$cid]); $freed++; }
        }
        try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'INVOICE_DEACTIVATE', 'invoices', ?, ?)")->execute([$_SESSION['user_id'], $id, $inv['invoice_no']]); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'freed' => $freed, 'still_invoiced' => $still, 'files' => count($moved)], JSON_UNESCAPED_UNICODE);
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
        $lines = $pdo->prepare("SELECT il.*, comp.name AS company_name FROM invoice_lines il
                                 LEFT JOIN companies comp ON comp.id = il.company_id WHERE il.invoice_id = ? ORDER BY il.id");
        $lines->execute([$id]);
        $pays = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY id DESC");
        $pays->execute([$id]);
        echo json_encode(['ok' => true, 'invoice' => $inv, 'lines' => $lines->fetchAll(), 'payments' => $pays->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[finance_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
