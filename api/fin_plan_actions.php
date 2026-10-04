<?php
// فایل: api/fin_plan_actions.php
// قراردادهای مالی (تعریف و فهرست) و «روشِ پرداختِ» هر بیمه‌نامه (ردیفِ شرکتی / پرونده‌ی کارکنان):
// پیش‌فرض از قراردادِ شرکت یا تنظیماتِ کارکنان، قابلِ تغییر برای همان بیمه‌نامه؛ مغایرت با فیش‌های فایلِ بیمه‌نامه.
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/finance_core.php';
require_once __DIR__ . '/_company_helpers.php';

function fpo($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) fpo(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['ADMIN', 'OPERATOR', 'FINANCE', 'COMPANY_LIAISON'], true)) fpo(['ok' => false, 'error' => 'دسترسی ندارید.']);
$isAdmin = $role === 'ADMIN';
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
fin_contracts_ensure($pdo);

function fp_contract_out($c) {
    $p = fin_plan_from_contract($c, 'contract');
    return ['id' => intval($c['id']), 'contract_no' => $c['contract_no'], 'contract_key' => $c['contract_key'], 'name' => $c['name'], 'pay_type' => $c['pay_type'],
            'installment_count' => intval($c['installment_count']), 'first_due_months' => intval($c['first_due_months']), 'first_due_days' => intval($c['first_due_days']),
            'interval_months' => intval($c['interval_months']), 'split_method' => $c['split_method'], 'note' => $c['note'], 'is_active' => intval($c['is_active']),
            'label' => fin_plan_label($p)];
}
// ردیفِ هدف: C = ردیفِ شرکتی، P = پرونده‌ی کارکنان
function fp_target($pdo, $kind, $id) {
    if ($kind === 'C') {
        $st = $pdo->prepare("SELECT crp.*, cr.company_id, c.name AS company_name FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                             JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
    } else {
        $st = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    }
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function fp_installments($pdo, $kind, $id) {
    $st = $kind === 'C'
        ? $pdo->prepare("SELECT inst_number, amount, due_jalali, slip_json, slip_file, (SELECT COUNT(*) FROM company_payment_allocations a WHERE a.installment_id = ci.id) AS paid_n,
                                settled_to_pasargad FROM company_installments ci WHERE plate_id = ? ORDER BY inst_number")
        : $pdo->prepare("SELECT inst_number, amount, due_jalali, slip_json, slip_file, (SELECT COUNT(*) FROM payment_allocations a WHERE a.installment_id = pi.id) AS paid_n,
                                settled_to_pasargad FROM policy_installments pi WHERE case_id = ? ORDER BY inst_number");
    $st->execute([intval($id)]);
    return array_map(fn($r) => ['n' => intval($r['inst_number']), 'amount' => intval($r['amount']), 'due' => $r['due_jalali'], 'slip' => json_decode((string)$r['slip_json'], true) ?: null,
                                'slip_file' => $r['slip_file'], 'touched' => intval($r['paid_n']) > 0 || !empty($r['settled_to_pasargad'])], $st->fetchAll());
}
function fp_state($pdo, $kind, $t) {
    $id = intval($t['id']);
    $def = fin_plan_default($pdo, $kind, $t);
    $plan = fin_plan_resolve($pdo, $kind, $t);
    $custom = json_decode((string)($t['pay_plan_json'] ?? ''), true) ?: null;
    $detected = fin_detected_contract_key($t['ocr_extracted_data'] ?? null, $t['policy_number'] ?? '');
    $ocr = json_decode((string)($t['ocr_extracted_data'] ?? ''), true) ?: [];
    $insts = $t['status'] === 'ISSUED' ? fp_installments($pdo, $kind, $id) : [];
    // پیش‌نمایش (پیش از صدور، یا برای مقایسه): همان محاسبه با حق بیمه‌ی فعلی
    $premium = money_to_int($t['total_premium'] ?? '') ?: money_to_int($ocr['premium'] ?? '');
    $issue = fin_parse_jalali($t['policy_issue_date'] ?? '') ?: (fin_parse_jalali($ocr['issue_date'] ?? '') ?: jalali_from_gregorian_ts(time()));
    $preview = $premium ? fin_plan_build($premium, $issue, $plan, $t['receipts_json'] ?? null, $detected) : null;
    // اگر قراردادِ شرکت/تنظیمات بعد از ساختِ اقساط عوض شده باشد، اقساطِ فعلی با محاسبه‌ی امروز یکی نیست
    $stale = false;
    if ($insts && $preview) {
        $sig = fn($rows) => implode('|', array_map(fn($r) => intval($r['amount']) . '@' . str_replace('.', '/', (string)$r['due']), $rows));
        $stale = $sig($insts) !== $sig($preview['rows']);
    }
    return ['ok' => true, 'kind' => $kind, 'id' => $id, 'issued' => $t['status'] === 'ISSUED', 'default' => $def + ['label' => fin_plan_label($def)],
            'plan' => $plan + ['label' => fin_plan_label($plan)], 'custom' => $custom, 'detected_key' => $detected, 'detected_no' => $ocr['contract_no'] ?? null,
            'detected_contract' => ($dc = fin_contract_by_key($pdo, $detected)) ? fp_contract_out($dc) : null,
            'check' => $preview ? $preview['check'] : (json_decode((string)($t['pay_check_json'] ?? ''), true) ?: null), 'stale' => $stale,
            'any_touched' => (bool)array_filter($insts, fn($r) => $r['touched']),
            'preview' => $preview ? $preview['rows'] : [], 'installments' => $insts, 'premium' => $premium,
            'company_name' => $t['company_name'] ?? null, 'contracts' => array_map('fp_contract_out', fin_contracts($pdo, true))];
}

// ---------------- قراردادها ----------------
if ($action === 'contracts') {
    $s = fin_settings($pdo);
    $co = $pdo->query("SELECT contract_id, COUNT(*) n FROM companies WHERE contract_id IS NOT NULL GROUP BY contract_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    fpo(['ok' => true, 'contracts' => array_map(fn($c) => fp_contract_out($c) + ['companies' => intval($co[$c['id']] ?? 0)], fin_contracts($pdo)),
         'personnel_contract_id' => intval($s['personnel_contract_id'] ?? 0), 'personnel_use_contract' => ($s['personnel_use_contract'] ?? '1') === '1',
         'personnel_settings' => ['count' => intval($s['default_installments'] ?? 9), 'method' => $s['installment_method'] ?? 'mamut'], 'can_edit' => $isAdmin]);
}
if (in_array($action, ['contract_save', 'contract_delete', 'personnel_contract_save'], true) && !$isAdmin) fpo(['ok' => false, 'error' => 'تعریفِ قرارداد فقط کارِ مدیر کل است.']);
if ($action === 'contract_save') {
    $c = (array)($data['contract'] ?? []);
    $name = trim((string)($c['name'] ?? ''));
    if ($name === '') fpo(['ok' => false, 'error' => 'نامِ قرارداد را وارد کنید.']);
    $no = trim(p2e_digits((string)($c['contract_no'] ?? '')));
    $key = fin_contract_key($no);
    if ($no !== '' && !$key) fpo(['ok' => false, 'error' => 'شماره‌ی قرارداد باید با عدد شروع شود (مثلاً 5051/30000/1404).']);
    $cash = ($c['pay_type'] ?? '') === 'CASH';
    $vals = [$no ?: null, $key, $name, $cash ? 'CASH' : 'INSTALLMENT', $cash ? 1 : max(1, min(60, intval(p2e_digits((string)($c['installment_count'] ?? 1))))),
             max(0, min(36, intval(p2e_digits((string)($c['first_due_months'] ?? 0))))), max(0, min(365, intval(p2e_digits((string)($c['first_due_days'] ?? 0))))),
             max(1, min(12, intval(p2e_digits((string)($c['interval_months'] ?? 1))))), ($c['split_method'] ?? 'mamut') === 'even' ? 'even' : 'mamut',
             mb_substr(trim((string)($c['note'] ?? '')), 0, 500) ?: null, !isset($c['is_active']) || !empty($c['is_active']) ? 1 : 0];
    if ($key) {
        $dup = $pdo->prepare("SELECT id FROM fin_contracts WHERE contract_key = ? AND id <> ? AND is_active = 1");
        $dup->execute([$key, intval($c['id'] ?? 0)]);
        if ($dup->fetchColumn()) fpo(['ok' => false, 'error' => "قراردادِ فعالِ دیگری با کلیدِ {$key} وجود دارد."]);
    }
    if (!empty($c['id'])) {
        $pdo->prepare("UPDATE fin_contracts SET contract_no=?, contract_key=?, name=?, pay_type=?, installment_count=?, first_due_months=?, first_due_days=?, interval_months=?, split_method=?, note=?, is_active=? WHERE id=?")
            ->execute([...$vals, intval($c['id'])]);
        $id = intval($c['id']);
    } else {
        $pdo->prepare("INSERT INTO fin_contracts (contract_no, contract_key, name, pay_type, installment_count, first_due_months, first_due_days, interval_months, split_method, note, is_active, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute($vals);
        $id = intval($pdo->lastInsertId());
    }
    // شرکت‌هایی که این قرارداد را دارند: ستون‌های قدیمی هم هم‌شکل می‌شوند (نمایش و سازگاری)
    $pdo->prepare("UPDATE companies SET payment_terms = ?, installment_count = ?, first_due_offset_months = ?, first_due_offset_days = ? WHERE contract_id = ?")
        ->execute([$cash ? 'CASH_IMMEDIATE' : 'INSTALLMENT', $vals[4], $vals[5], $vals[6], $id]);
    fpo(['ok' => true, 'id' => $id]);
}
if ($action === 'contract_delete') {
    $id = intval($data['id'] ?? 0);
    $n = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE contract_id = ?");
    $n->execute([$id]);
    if (intval($n->fetchColumn())) fpo(['ok' => false, 'error' => 'این قرارداد به شرکت‌هایی وصل است؛ اول قراردادِ آن شرکت‌ها را عوض کنید یا قرارداد را «غیرفعال» کنید.']);
    if ($id === intval(fin_settings($pdo)['personnel_contract_id'] ?? 0)) fpo(['ok' => false, 'error' => 'این قراردادِ کارکنان است؛ اول قراردادِ دیگری برای کارکنان انتخاب کنید.']);
    $pdo->prepare("DELETE FROM fin_contracts WHERE id = ?")->execute([$id]);
    fpo(['ok' => true]);
}
if ($action === 'personnel_contract_save') {
    $cid = intval($data['contract_id'] ?? 0);
    if ($cid && !fin_contract($pdo, $cid)) fpo(['ok' => false, 'error' => 'قرارداد پیدا نشد.']);
    fin_set($pdo, 'personnel_contract_id', (string)$cid);
    fin_set($pdo, 'personnel_use_contract', !empty($data['use_contract']) && $cid ? '1' : '0');
    fpo(['ok' => true]);
}

// ---------------- روشِ پرداختِ یک بیمه‌نامه ----------------
if (in_array($action, ['plan_get', 'plan_save', 'plan_reset', 'plan_regen'], true)) {
    $kind = ($data['kind'] ?? '') === 'C' ? 'C' : 'P';
    $t = fp_target($pdo, $kind, $data['id'] ?? 0);
    if (!$t) fpo(['ok' => false, 'error' => 'بیمه‌نامه پیدا نشد.']);
    if ($action === 'plan_get') fpo(fp_state($pdo, $kind, $t));
    $table = $kind === 'C' ? 'company_request_plates' : 'policy_cases';
    if ($action === 'plan_regen') {
        if ($t['status'] !== 'ISSUED') fpo(['ok' => false, 'error' => 'بیمه‌نامه هنوز صادر نشده است.']);
    } elseif ($action === 'plan_reset') {
        $pdo->prepare("UPDATE $table SET pay_plan_json = NULL WHERE id = ?")->execute([$t['id']]);
    } else {
        $p = (array)($data['plan'] ?? []);
        $mode = in_array($p['mode'] ?? '', ['default', 'contract', 'custom'], true) ? $p['mode'] : 'default';
        $prefer = in_array($p['prefer'] ?? '', ['slips', 'formula'], true) ? $p['prefer'] : 'auto';
        $j = ['mode' => $mode, 'prefer' => $prefer];
        if ($mode === 'contract') {
            if (!fin_contract($pdo, intval($p['contract_id'] ?? 0))) fpo(['ok' => false, 'error' => 'قرارداد را انتخاب کنید.']);
            $j['contract_id'] = intval($p['contract_id']);
        } elseif ($mode === 'custom') {
            $j += ['type' => ($p['type'] ?? '') === 'CASH' ? 'CASH' : 'INSTALLMENT', 'count' => max(1, min(60, intval(p2e_digits((string)($p['count'] ?? 1))))),
                   'first_m' => max(0, min(36, intval(p2e_digits((string)($p['first_m'] ?? 0))))), 'first_d' => max(0, min(365, intval(p2e_digits((string)($p['first_d'] ?? 0))))),
                   'method' => ($p['method'] ?? 'mamut') === 'even' ? 'even' : 'mamut'];
        }
        $pdo->prepare("UPDATE $table SET pay_plan_json = ? WHERE id = ?")->execute([$mode === 'default' && $prefer === 'auto' ? null : json_encode($j, JSON_UNESCAPED_UNICODE), $t['id']]);
    }
    // بیمه‌نامه‌ی صادرشده: اقساط با روشِ تازه دوباره ساخته می‌شود (اگر هنوز دریافت/تسویه‌ای روی‌شان نیست)
    $msg = null;
    if ($t['status'] === 'ISSUED') {
        $res = $kind === 'C' ? company_generate_installments($pdo, $t['id']) : fin_generate_installments($pdo, $t['id']);
        if (empty($res['ok'])) $msg = $res['error'] ?? 'اقساط دوباره ساخته نشد.';
    }
    $t = fp_target($pdo, $kind, $t['id']);
    fpo(fp_state($pdo, $kind, $t) + ['saved' => true, 'warning' => $msg]);
}

fpo(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
