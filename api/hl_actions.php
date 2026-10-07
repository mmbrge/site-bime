<?php
// فایل: api/hl_actions.php
// API «مالیِ درمان تکمیلی»: داشبورد، قراردادها و اقساط، دریافت‌ها (به ما / مستقیم به بیمه‌گر)، پرداخت به پاسارگاد، معوقات، اکسل و تنظیمات.
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_hl.php';
require_once __DIR__ . '/_xlsx_writer.php';

$out = function ($a) { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$fail = function ($m) use ($out) { $out(['ok' => false, 'error' => $m]); };
if (empty($_SESSION['user_id']) || session_name() === 'bime_company_portal') { http_response_code(401); $fail('ابتدا وارد پنل شوید.'); }
$uid = intval($_SESSION['user_id']);

function hl_perm_set($pdo) {
    static $p = null;
    if ($p !== null) return $p;
    if (perm_real_role() === 'ADMIN') return $p = 'ALL';
    $c = perm_user_custom($pdo, $_SESSION['user_id']);
    return $p = ($c !== null ? $c : perm_role_defaults(perm_real_role()));
}
function hl_can($pdo, $spec) { $p = hl_perm_set($pdo); return $p === 'ALL' || perm_allows($p, $spec); }

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
$need = [
    'bootstrap' => 'hl-dash:view|hl-contracts:view|hl-pay:view|hl-settle:view|hl-settings:view|hl-ledger:view|hl-members:view|hl-receipts:view', 'dash' => 'hl-dash:view',
    'contracts_list' => 'hl-contracts:view|hl-dash:view', 'contract_get' => 'hl-contracts:view|hl-pay:view|hl-ledger:view|hl-receipts:view|hl-members:view', 'contract_save' => 'hl-contracts:create|hl-contracts:edit', 'contract_delete' => 'hl-contracts:delete',
    'inst_list' => 'hl-contracts:view|hl-dash:view',
    'pay_list' => 'hl-pay:view', 'pay_save' => 'hl-pay:create', 'pay_update' => 'hl-pay:edit', 'pay_void' => 'hl-pay:delete', 'pay_file' => 'hl-pay:view',
    'settle_list' => 'hl-settle:view', 'settle_save' => 'hl-settle:create', 'settle_void' => 'hl-settle:delete', 'settle_file' => 'hl-settle:view',
    'export' => 'hl-dash:export|hl-contracts:export|hl-pay:export|hl-ledger:export', 'settings_get' => 'hl-settings:view', 'settings_save' => 'hl-settings:edit',
    // دفترِ ماهانه
    'ledger' => 'hl-ledger:view', 'ledger_export' => 'hl-ledger:export', 'ledger_import_preview' => 'hl-ledger:create', 'ledger_import_commit' => 'hl-ledger:create',
    'bill_suggest' => 'hl-ledger:view', 'bill_save' => 'hl-ledger:create|hl-ledger:edit', 'bill_delete' => 'hl-ledger:delete', 'bill_generate' => 'hl-ledger:create',
    'sample' => 'hl-ledger:view|hl-members:view|hl-contracts:view',
    // الحاقیه‌ها و بردرو
    'end_save' => 'hl-contracts:edit', 'end_delete' => 'hl-contracts:edit', 'end_file' => 'hl-contracts:view',
    'bordereau_preview' => 'hl-contracts:create', 'bordereau_commit' => 'hl-contracts:create',
    // بیمه‌شدگان
    'members_list' => 'hl-members:view', 'members_import_preview' => 'hl-members:create', 'members_import_commit' => 'hl-members:create', 'member_save' => 'hl-members:edit|hl-members:create',
    'member_delete' => 'hl-members:delete', 'members_export' => 'hl-members:export',
    // صندوقِ رسیدهای ربات
    'inbox_list' => 'hl-receipts:view', 'inbox_file' => 'hl-receipts:view', 'inbox_assign' => 'hl-receipts:edit', 'inbox_attach' => 'hl-receipts:edit', 'inbox_reject' => 'hl-receipts:delete', 'inbox_restore' => 'hl-receipts:edit',
    'inbox_upload' => 'hl-receipts:edit', 'bot_groups' => 'hl-settings:view',
];
if (!isset($need[$action])) $fail('درخواست نامعتبر است.');
if (!hl_can($pdo, $need[$action])) $fail('شما به این بخش دسترسی ندارید. از مدیر کل بخواهید دسترسی‌تان را تنظیم کند.');
hl_ensure($pdo);
$S = hl_settings($pdo);
[$jyNow] = jalali_from_gregorian_ts(time());

function hl_contract_row($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM hl_contracts WHERE id = ? AND is_deleted = 0");
    $st->execute([intval($id)]);
    $c = $st->fetch();
    if (!$c) throw new RuntimeException('قرارداد پیدا نشد.');
    return $c;
}
function hl_c_out(array $c) {
    return ['id' => intval($c['id']), 'company_id' => $c['company_id'] ? intval($c['company_id']) : null, 'company_name' => $c['company_name'], 'title' => $c['title'], 'policy_no' => $c['policy_no'],
            'insurer' => $c['insurer'], 'start_j' => $c['start_j'], 'end_j' => $c['end_j'], 'insured_count' => intval($c['insured_count']), 'premium' => intval($c['premium']),
            'commission_pct' => floatval($c['commission_pct']), 'status' => $c['status'], 'contact_name' => $c['contact_name'], 'contact_mobile' => $c['contact_mobile'], 'note' => $c['note'],
            'line' => $c['line'] ?? 'درمان', 'plan' => $c['plan'] ?? null, 'issue_j' => $c['issue_j'] ?? null, 'unit_premium' => intval($c['unit_premium'] ?? 0), 'billing' => $c['billing'] ?? 'INSTALL',
            'prev_policy_no' => $c['prev_policy_no'] ?? null, 'holder_code' => $c['holder_code'] ?? null, 'coverage' => $c['coverage'] ?? null];
}
function hl_p_out(array $p, array $methods) {
    return ['id' => intval($p['id']), 'contract_id' => intval($p['contract_id']), 'company_name' => $p['company_name'] ?? '', 'policy_no' => $p['policy_no'] ?? '', 'pay_j' => $p['pay_j'], 'amount' => intval($p['amount']),
            'method' => $p['method'], 'method_fa' => $methods[$p['method']] ?? $p['method'], 'payee' => $p['payee'], 'payee_fa' => HL_PAYEE[$p['payee']] ?? '', 'ref_no' => $p['ref_no'], 'bank' => $p['bank'],
            'cheque_no' => $p['cheque_no'], 'cheque_due_j' => $p['cheque_due_j'], 'cheque_status' => $p['cheque_status'], 'cheque_status_fa' => $p['cheque_status'] ? (HL_CHQ[$p['cheque_status']] ?? '') : '',
            'has_file' => !empty($p['file_path']), 'note' => $p['note'], 'status' => $p['status'], 'void_reason' => $p['void_reason'], 'by' => $p['by_name'] ?? '', 'created_at_j' => hl_dt_j($p['created_at']),
            'allocated' => isset($p['allocated']) ? intval($p['allocated']) : null, 'doc_type' => $p['doc_type'] ?? null];
}
// فایلِ آپلودشده‌ی فرم را بررسی و به یک مسیرِ موقت می‌برد (بعد hl_payment_create آن را به پوشه‌ی قرارداد می‌برد)
function hl_take_upload($key = 'file') {
    if (empty($_FILES[$key]) || ($_FILES[$key]['error'] ?? 4) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$key];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('فایل دریافت نشد.');
    if ($f['size'] > 15 * 1024 * 1024) throw new RuntimeException('حجمِ فایل بیشتر از ۱۵ مگابایت است.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) throw new RuntimeException('رسید باید PDF یا تصویر باشد.');
    $tmp = hl_root() . '/.upload'; if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
    $dest = $tmp . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dest)) throw new RuntimeException('ذخیره‌ی فایل ناموفق بود.');
    return $dest;
}
// فایلِ ورودیِ اکسل برای پیش‌نمایشِ ورود => [مسیر, پسوند]
function hl_take_sheet($key = 'file') {
    if (empty($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$key]['tmp_name'])) throw new RuntimeException('فایلِ اکسل را انتخاب کنید.');
    $ext = strtolower(pathinfo((string)$_FILES[$key]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('فقط فایلِ xlsx یا csv (اکسلِ ۲۰۰۷ به بعد).');
    if ($_FILES[$key]['size'] > 20 * 1024 * 1024) throw new RuntimeException('حجمِ فایل زیاد است.');
    return [$_FILES[$key]['tmp_name'], $ext];
}
function hl_tmp_save($kind, array $data) {
    $tok = bin2hex(random_bytes(10));
    file_put_contents(sys_get_temp_dir() . "/hl_{$kind}_{$tok}.json", json_encode(['uid' => intval($_SESSION['user_id']), 'data' => $data], JSON_UNESCAPED_UNICODE));
    return $tok;
}
function hl_tmp_load($kind, $tok) {
    $f = sys_get_temp_dir() . "/hl_{$kind}_" . preg_replace('/[^a-f0-9]/', '', (string)$tok) . '.json';
    $j = is_file($f) ? json_decode(file_get_contents($f), true) : null;
    if (!$j || intval($j['uid']) !== intval($_SESSION['user_id'])) throw new RuntimeException('پیش‌نمایش منقضی شده؛ فایل را دوباره انتخاب کنید.');
    @unlink($f);
    return $j['data'];
}
function hl_send_file($abs, $name) {
    if (!$abs) { http_response_code(404); echo 'فایل پیدا نشد.'; exit; }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Disposition: inline; filename=\"file.$ext\"; filename*=UTF-8''" . rawurlencode($name . '.' . $ext));
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

try {
switch ($action) {
case 'bootstrap':
    $perms = [];
    foreach (perm_pages() as $k => $v) if (strpos($k, 'hl-') === 0) { $p = hl_perm_set($pdo); $perms[$k] = $p === 'ALL' ? $v[1] : array_values(array_intersect($v[1], (array)($p[$k] ?? []))); }
    $years = array_map('intval', $pdo->query("SELECT DISTINCT LEFT(due_j, 4) FROM hl_installments WHERE due_j IS NOT NULL ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array($jyNow, $years, true)) $years[] = $jyNow;
    rsort($years);
    $contracts = $pdo->query("SELECT id, company_name, title, policy_no, status, line, plan, unit_premium, billing FROM hl_contracts WHERE is_deleted = 0 ORDER BY company_name, line = 'درمان' DESC, line, policy_no, plan, id")->fetchAll();
    $out(['ok' => true, 'perms' => $perms, 'today_j' => hl_today_j(), 'jy' => $jyNow, 'years' => $years, 'settings' => $S, 'payees' => HL_PAYEE, 'statuses' => HL_STATUS, 'chq' => HL_CHQ, 'end_kinds' => HL_END_KIND,
          'inbox_new' => intval($pdo->query("SELECT COUNT(*) FROM hl_inbox WHERE status = 'NEW'")->fetchColumn()),
          'companies' => array_map(function ($c) { return ['id' => intval($c['id']), 'name' => $c['name']]; }, $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll()),
          'contracts' => array_map(function ($c) { return ['id' => intval($c['id']), 'company_name' => $c['company_name'], 'title' => $c['title'], 'policy_no' => $c['policy_no'], 'status' => $c['status'],
              'line' => $c['line'], 'plan' => $c['plan'], 'unit_premium' => intval($c['unit_premium']), 'billing' => $c['billing']]; }, $contracts)]);

case 'dash':
    $jy = intval($data['jy'] ?? 0) ?: $jyNow;
    $out(['ok' => true] + hl_dashboard($pdo, $jy, intval($data['contract_id'] ?? 0), trim((string)($data['company'] ?? ''))));

case 'contracts_list':
    $w = ["is_deleted = 0"]; $p = [];
    if (!empty($data['status']) && isset(HL_STATUS[$data['status']])) { $w[] = "status = ?"; $p[] = $data['status']; }
    $q = trim(p2e_digits((string)($data['q'] ?? '')));
    if ($q !== '') { $w[] = "(company_name LIKE ? OR title LIKE ? OR policy_no LIKE ? OR contact_name LIKE ? OR plan LIKE ?)"; $lk = "%$q%"; array_push($p, $lk, $lk, $lk, $lk, $lk); }
    if (!empty($data['line'])) { $w[] = "line = ?"; $p[] = (string)$data['line']; }
    $st = $pdo->prepare("SELECT * FROM hl_contracts WHERE " . implode(' AND ', $w) . " ORDER BY status = 'ACTIVE' DESC, company_name, line = 'درمان' DESC, line, policy_no, plan, id DESC");
    $st->execute($p);
    $rows = [];
    foreach ($st->fetchAll() as $c) $rows[] = hl_c_out($c) + ['sum' => hl_contract_summary($pdo, $c)];
    $out(['ok' => true, 'rows' => $rows]);

case 'contract_get':
    $c = hl_contract_row($pdo, $data['id'] ?? 0);
    $pays = $pdo->prepare("SELECT p.*, u.full_name by_name, (SELECT COALESCE(SUM(a.amount), 0) FROM hl_alloc a WHERE a.payment_id = p.id) allocated FROM hl_payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.contract_id = ? ORDER BY p.pay_g DESC, p.id DESC");
    $pays->execute([$c['id']]);
    $alloc = $pdo->prepare("SELECT a.installment_id, a.payment_id, a.amount FROM hl_alloc a JOIN hl_payments p ON p.id = a.payment_id WHERE p.contract_id = ? AND p.status = 'OK'");
    $alloc->execute([$c['id']]);
    // صورت‌حسابِ بیمه‌نامه: حق‌بیمه + الحاقیه‌ها (همه‌ی طرح‌های همین شماره‌ی بیمه‌نامه)، صورتحساب‌شده، وصول، پرداخت‌شده به بیمه‌گر
    $sib = [$c['id']];
    if ($c['policy_no']) { $st = $pdo->prepare("SELECT id FROM hl_contracts WHERE is_deleted = 0 AND REPLACE(policy_no,' ','') = ?"); $st->execute([preg_replace('/\s+/', '', $c['policy_no'])]); $sib = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) ?: [$c['id']]; }
    $in = implode(',', $sib);
    $ends = $pdo->query("SELECT e.*, u.full_name by_name FROM hl_endorsements e LEFT JOIN users u ON u.id = e.created_by WHERE e.is_deleted = 0 AND e.contract_id IN ($in) ORDER BY e.issue_g, e.id")->fetchAll();
    $stmt = ['premium' => intval($pdo->query("SELECT COALESCE(SUM(premium),0) FROM hl_contracts WHERE id IN ($in)")->fetchColumn()), 'add' => 0, 'refund' => 0];
    foreach ($ends as $e) { if (intval($e['amount']) >= 0) $stmt['add'] += intval($e['amount']); else $stmt['refund'] += intval($e['amount']); }
    $stmt['final'] = $stmt['premium'] + $stmt['add'] + $stmt['refund'];
    $stmt['billed'] = intval($pdo->query("SELECT COALESCE(SUM(amount),0) FROM hl_installments WHERE contract_id IN ($in)")->fetchColumn());
    $stmt['received'] = intval($pdo->query("SELECT COALESCE(SUM(amount),0) FROM hl_payments WHERE status = 'OK' AND contract_id IN ($in)")->fetchColumn());
    $stmt['direct'] = intval($pdo->query("SELECT COALESCE(SUM(amount),0) FROM hl_payments WHERE status = 'OK' AND payee = 'INSURER' AND contract_id IN ($in)")->fetchColumn());
    $stmt['remitted'] = intval($pdo->query("SELECT COALESCE(SUM(amount),0) FROM hl_settlements WHERE status = 'OK' AND contract_id IN ($in)")->fetchColumn());
    $stmt['paid_insurer'] = $stmt['direct'] + $stmt['remitted'];
    $stmt['insurer_balance'] = $stmt['final'] - $stmt['paid_insurer'];
    $stmt['plans'] = count($sib);
    $stmt['members'] = intval($pdo->query("SELECT COUNT(*) FROM hl_members WHERE contract_id = " . intval($c['id']))->fetchColumn());
    $stmt['members_active'] = hl_members_active($pdo, [$c['id']], date('Y-m-d'));
    $out(['ok' => true, 'contract' => hl_c_out($c), 'sum' => hl_contract_summary($pdo, $c), 'installments' => hl_installments($pdo, $c['id']), 'statement' => $stmt,
          'endorsements' => array_map(function ($e) { return ['id' => intval($e['id']), 'contract_id' => intval($e['contract_id']), 'end_no' => $e['end_no'], 'kind' => $e['kind'], 'kind_fa' => HL_END_KIND[$e['kind']] ?? $e['kind'], 'issue_j' => $e['issue_j'],
              'effect_j' => $e['effect_j'], 'amount' => intval($e['amount']), 'headcount_delta' => $e['headcount_delta'] !== null ? intval($e['headcount_delta']) : null, 'note' => $e['note'], 'has_file' => !empty($e['file_path']), 'by' => $e['by_name']]; }, $ends),
          'payments' => array_map(function ($p) use ($S) { return hl_p_out($p, $S['methods']); }, $pays->fetchAll()),
          'alloc' => array_map(function ($a) { return ['installment_id' => intval($a['installment_id']), 'payment_id' => intval($a['payment_id']), 'amount' => intval($a['amount'])]; }, $alloc->fetchAll())]);

case 'contract_save':
    $id = intval($data['id'] ?? 0);
    if ($id && !hl_can($pdo, 'hl-contracts:edit')) throw new RuntimeException('ویرایشِ قرارداد دسترسیِ «ویرایش» می‌خواهد.');
    if (!$id && !hl_can($pdo, 'hl-contracts:create')) throw new RuntimeException('ثبتِ قرارداد دسترسیِ «ایجاد» می‌خواهد.');
    $name = mb_substr(trim((string)($data['company_name'] ?? '')), 0, 190);
    if ($name === '') throw new RuntimeException('نامِ شرکت را بنویسید.');
    $cid = null;
    $st = $pdo->prepare("SELECT id FROM companies WHERE name = ?"); $st->execute([$name]); if ($x = $st->fetchColumn()) $cid = intval($x);
    [$sj, $sg] = hl_jdate($data['start_j'] ?? ''); [$ej, $eg] = hl_jdate($data['end_j'] ?? '');
    $t = function ($k, $n) use ($data) { $v = trim((string)($data[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };
    $f = ['company_id' => $cid, 'company_name' => $name, 'title' => $t('title', 190), 'policy_no' => $t('policy_no', 80) ? p2e_digits($t('policy_no', 80)) : null, 'insurer' => $t('insurer', 60) ?: $S['default_insurer'],
          'start_j' => $sj, 'start_g' => $sg, 'end_j' => $ej, 'end_g' => $eg, 'insured_count' => max(0, intval(p2e_digits((string)($data['insured_count'] ?? 0)))), 'premium' => max(0, money_to_int($data['premium'] ?? 0)),
          'commission_pct' => max(0, min(100, floatval(p2e_digits((string)($data['commission_pct'] ?? 0))))), 'status' => isset(HL_STATUS[$data['status'] ?? '']) ? $data['status'] : 'ACTIVE',
          'contact_name' => $t('contact_name', 190), 'contact_mobile' => $t('contact_mobile', 20) ? p2e_digits($t('contact_mobile', 20)) : null, 'note' => $t('note', 3000)];
    [$ij, $ig] = hl_jdate($data['issue_j'] ?? '');
    $f += ['line' => in_array($data['line'] ?? '', $S['lines'], true) ? $data['line'] : ($t('line', 30) ?: 'درمان'), 'plan' => $t('plan', 120), 'issue_j' => $ij, 'issue_g' => $ig,
           'unit_premium' => max(0, money_to_int($data['unit_premium'] ?? 0) ?: 0), 'billing' => ($data['billing'] ?? '') === 'MONTHLY' ? 'MONTHLY' : 'INSTALL',
           'prev_policy_no' => $t('prev_policy_no', 80) ? p2e_digits($t('prev_policy_no', 80)) : null, 'holder_code' => $t('holder_code', 40)];
    $touchInst = array_key_exists('installments', $data) && $f['billing'] === 'INSTALL';
    // اقساط
    $rows = [];
    foreach ($touchInst ? (array)$data['installments'] : [] as $i => $r) {
        if (($r['kind'] ?? 'INST') === 'BILL') continue;
        [$dj, $dg] = hl_jdate($r['due_j'] ?? '');
        $amt = money_to_int($r['amount'] ?? 0);
        if (!$dj && !$amt) continue;
        if (!$dj) throw new RuntimeException('تاریخِ سررسیدِ قسطِ ' . hl_fa($i + 1) . ' نامعتبر است.');
        if ($amt <= 0) throw new RuntimeException('مبلغِ قسطِ ' . hl_fa($i + 1) . ' را وارد کنید.');
        $rows[] = ['id' => intval($r['id'] ?? 0), 'due_j' => $dj, 'due_g' => $dg, 'amount' => $amt, 'payee' => ($r['payee'] ?? '') === 'INSURER' ? 'INSURER' : 'US', 'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 300) ?: null];
    }
    usort($rows, function ($a, $b) { return strcmp($a['due_g'], $b['due_g']); });
    $pdo->beginTransaction();
    if ($id) {
        hl_contract_row($pdo, $id);
        $set = []; $vals = [];
        foreach ($f as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
        $vals[] = $uid; $vals[] = $id;
        $pdo->prepare("UPDATE hl_contracts SET " . implode(', ', $set) . ", updated_by = ?, updated_at = NOW() WHERE id = ?")->execute($vals);
    } else {
        $cols = array_keys($f);
        $pdo->prepare("INSERT INTO hl_contracts (" . implode(', ', $cols) . ", created_by, created_at) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", ?, NOW())")->execute(array_merge(array_values($f), [$uid]));
        $id = intval($pdo->lastInsertId());
    }
    if (!$touchInst) { $pdo->commit(); $out(['ok' => true, 'id' => $id]); }
    // قسطی که پرداخت دارد پاک نمی‌شود (صورتحساب‌های ماهانه از «دفترِ ماهانه» مدیریت می‌شوند و این‌جا دست نمی‌خورند)
    $keep = array_filter(array_column($rows, 'id'));
    $old = $pdo->prepare("SELECT i.id, i.seq, (SELECT COUNT(*) FROM hl_alloc a JOIN hl_payments p ON p.id = a.payment_id WHERE a.installment_id = i.id AND p.status = 'OK') n FROM hl_installments i WHERE i.contract_id = ? AND i.kind = 'INST'");
    $old->execute([$id]);
    foreach ($old->fetchAll() as $o) if (!in_array(intval($o['id']), $keep, true)) {
        if (intval($o['n'])) { $pdo->rollBack(); throw new RuntimeException('قسطِ ' . hl_fa($o['seq']) . ' پرداخت دارد و حذف نمی‌شود؛ اول دریافت‌هایش را باطل کنید.'); }
        $pdo->prepare("DELETE FROM hl_installments WHERE id = ?")->execute([$o['id']]);
    }
    $up = $pdo->prepare("UPDATE hl_installments SET seq = ?, due_j = ?, due_g = ?, amount = ?, payee = ?, note = ? WHERE id = ? AND contract_id = ? AND kind = 'INST'");
    $ins = $pdo->prepare("INSERT INTO hl_installments (contract_id, seq, due_j, due_g, amount, payee, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $k => $r) {
        if ($r['id']) $up->execute([$k + 1, $r['due_j'], $r['due_g'], $r['amount'], $r['payee'], $r['note'], $r['id'], $id]);
        else $ins->execute([$id, $k + 1, $r['due_j'], $r['due_g'], $r['amount'], $r['payee'], $r['note']]);
    }
    hl_resequence($pdo, $id);
    $pdo->commit();
    if (function_exists('sec_event')) sec_event($pdo, 'ADMIN_ACTION', 'قراردادِ درمان تکمیلی «' . $name . '» ذخیره شد (' . hl_fa(count($rows)) . ' قسط)');
    $out(['ok' => true, 'id' => $id]);

case 'contract_delete':
    $c = hl_contract_row($pdo, $data['id'] ?? 0);
    $st = $pdo->prepare("SELECT COUNT(*) FROM hl_payments WHERE contract_id = ? AND status = 'OK'"); $st->execute([$c['id']]);
    if (intval($st->fetchColumn()) && empty($data['force'])) throw new RuntimeException('این قرارداد دریافتِ ثبت‌شده دارد. اگر مطمئنید، دوباره با تأیید حذف کنید.');
    $pdo->prepare("UPDATE hl_contracts SET is_deleted = 1, updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([$uid, $c['id']]);
    if (function_exists('sec_event')) sec_event($pdo, 'ADMIN_ACTION', 'حذفِ قراردادِ درمان تکمیلی «' . $c['company_name'] . '»');
    $out(['ok' => true]);

case 'inst_list':
    $w = ["1"]; $p = [];
    $f = (string)($data['filter'] ?? 'open');
    $rows = hl_installments($pdo, intval($data['contract_id'] ?? 0) ?: null);
    if ($f === 'overdue') $rows = array_filter($rows, function ($r) { return $r['status'] === 'OVERDUE'; });
    elseif ($f === 'open') $rows = array_filter($rows, function ($r) { return $r['remaining'] > 0; });
    elseif ($f === 'paid') $rows = array_filter($rows, function ($r) { return $r['status'] === 'PAID'; });
    $out(['ok' => true, 'rows' => array_values($rows)]);

case 'pay_list':
case 'settle_list':
    $isPay = $action === 'pay_list';
    $w = [$isPay ? "1" : "1"]; $p = [];
    $tbl = $isPay ? 'hl_payments' : 'hl_settlements'; $dc = $isPay ? 'pay_g' : 'settle_g';
    [, $fg] = hl_jdate($data['from_j'] ?? ''); [, $tg] = hl_jdate($data['to_j'] ?? '');
    if ($fg) { $w[] = "x.$dc >= ?"; $p[] = $fg; }
    if ($tg) { $w[] = "x.$dc <= ?"; $p[] = $tg; }
    if (empty($data['show_void'])) $w[] = "x.status = 'OK'";
    if (intval($data['contract_id'] ?? 0)) $w[] = "x.contract_id = " . intval($data['contract_id']);
    if ($isPay) {
        if (!empty($data['payee']) && isset(HL_PAYEE[$data['payee']])) { $w[] = "x.payee = ?"; $p[] = $data['payee']; }
        if (!empty($data['method'])) { $w[] = "x.method = ?"; $p[] = (string)$data['method']; }
        $q = trim(p2e_digits((string)($data['q'] ?? '')));
        if ($q !== '') { $w[] = "(c.company_name LIKE ? OR x.ref_no LIKE ? OR x.cheque_no LIKE ? OR c.policy_no LIKE ?)"; $lk = "%$q%"; array_push($p, $lk, $lk, $lk, $lk); }
        $st = $pdo->prepare("SELECT x.*, c.company_name, c.policy_no, u.full_name by_name, (SELECT COALESCE(SUM(a.amount), 0) FROM hl_alloc a WHERE a.payment_id = x.id) allocated
                               FROM hl_payments x JOIN hl_contracts c ON c.id = x.contract_id LEFT JOIN users u ON u.id = x.created_by WHERE c.is_deleted = 0 AND " . implode(' AND ', $w) . " ORDER BY x.pay_g DESC, x.id DESC LIMIT 1000");
        $st->execute($p);
        $rows = array_map(function ($r) use ($S) { return hl_p_out($r, $S['methods']); }, $st->fetchAll());
        $tot = ['US' => 0, 'INSURER' => 0]; foreach ($rows as $r) if ($r['status'] === 'OK') $tot[$r['payee']] += $r['amount'];
        $out(['ok' => true, 'rows' => $rows, 'totals' => $tot]);
    }
    $st = $pdo->prepare("SELECT x.*, c.company_name, c.policy_no, c.plan, c.line, u.full_name by_name, i.period_j, i.seq FROM hl_settlements x LEFT JOIN hl_contracts c ON c.id = x.contract_id LEFT JOIN users u ON u.id = x.created_by
                           LEFT JOIN hl_installments i ON i.id = x.installment_id WHERE " . implode(' AND ', $w) . " ORDER BY x.settle_g DESC, x.id DESC LIMIT 1000");
    $st->execute($p);
    $rows = array_map(function ($r) use ($S) { return ['id' => intval($r['id']), 'settle_j' => $r['settle_j'], 'amount' => intval($r['amount']), 'method' => $r['method'], 'method_fa' => $S['methods'][$r['method']] ?? $r['method'],
        'ref_no' => $r['ref_no'], 'contract_id' => $r['contract_id'] ? intval($r['contract_id']) : null, 'company_name' => $r['company_name'], 'policy_no' => $r['policy_no'], 'note' => $r['note'], 'has_file' => !empty($r['file_path']),
        'status' => $r['status'], 'by' => $r['by_name'], 'created_at_j' => hl_dt_j($r['created_at']), 'handover_j' => $r['handover_j'], 'plan' => $r['plan'], 'line' => $r['line'],
        'bill' => $r['period_j'] ? hl_period_fa($r['period_j']) : ($r['seq'] ? 'قسط ' . hl_fa($r['seq']) : '')]; }, $st->fetchAll());
    $k = hl_dashboard($pdo, $jyNow)['kpi'];
    $out(['ok' => true, 'rows' => $rows, 'paid_us' => $k['paid_us'], 'settled' => $k['settled'], 'owed' => $k['owed_insurer']]);

case 'pay_save':
    $c = hl_contract_row($pdo, $data['contract_id'] ?? 0);
    $tmp = hl_take_upload('file');
    $pdo->beginTransaction();
    try { $r = hl_payment_create($pdo, $c, $data, $tmp, $uid, $S); }
    catch (Throwable $e) { if ($tmp && is_file($tmp)) @unlink($tmp); throw $e; }
    $pdo->commit();
    $out(['ok' => true, 'id' => $r['id'], 'unallocated' => $r['unallocated']]);

case 'pay_update':
    $st = $pdo->prepare("SELECT * FROM hl_payments WHERE id = ?"); $st->execute([intval($data['id'] ?? 0)]); $p = $st->fetch();
    if (!$p || $p['status'] !== 'OK') throw new RuntimeException('دریافت پیدا نشد یا باطل شده است.');
    $t = function ($k, $n) use ($data) { $v = trim(p2e_digits((string)($data[$k] ?? ''))); return $v === '' ? null : mb_substr($v, 0, $n); };
    $cs = isset(HL_CHQ[$data['cheque_status'] ?? '']) ? $data['cheque_status'] : $p['cheque_status'];
    $pdo->prepare("UPDATE hl_payments SET ref_no = ?, bank = ?, note = ?, cheque_status = ?, doc_type = ? WHERE id = ?")->execute([$t('ref_no', 80), $t('bank', 80), $t('note', 500), $p['method'] === 'cheque' ? $cs : null, array_key_exists('doc_type', $data) ? $t('doc_type', 40) : $p['doc_type'], $p['id']]);
    // چکِ برگشتی: دریافت باطل و اقساط دوباره باز می‌شوند
    if ($p['method'] === 'cheque' && $cs === 'BOUNCED') {
        $pdo->prepare("UPDATE hl_payments SET status = 'VOID', void_reason = 'چک برگشتی', voided_by = ?, voided_at = NOW() WHERE id = ?")->execute([$uid, $p['id']]);
        $pdo->prepare("DELETE FROM hl_alloc WHERE payment_id = ?")->execute([$p['id']]);
    }
    $out(['ok' => true]);

case 'pay_void':
case 'settle_void':
    $tbl = $action === 'pay_void' ? 'hl_payments' : 'hl_settlements';
    $id = intval($data['id'] ?? 0);
    $reason = mb_substr(trim((string)($data['reason'] ?? '')), 0, 300);
    if ($tbl === 'hl_payments') {
        $pdo->prepare("UPDATE hl_payments SET status = 'VOID', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ? AND status = 'OK'")->execute([$reason ?: null, $uid, $id]);
        $pdo->prepare("DELETE FROM hl_alloc WHERE payment_id = ?")->execute([$id]);
    } else $pdo->prepare("UPDATE hl_settlements SET status = 'VOID', voided_by = ?, voided_at = NOW() WHERE id = ? AND status = 'OK'")->execute([$uid, $id]);
    if (function_exists('sec_event')) sec_event($pdo, 'ADMIN_ACTION', ($tbl === 'hl_payments' ? 'ابطالِ دریافتِ درمان تکمیلی #' : 'ابطالِ پرداخت به بیمه‌گر #') . $id . ($reason ? ' - ' . $reason : ''));
    $out(['ok' => true]);

case 'pay_file':
case 'settle_file':
    $tbl = $action === 'pay_file' ? 'hl_payments' : 'hl_settlements';
    $st = $pdo->prepare("SELECT file_path FROM $tbl WHERE id = ?"); $st->execute([intval($data['id'] ?? 0)]);
    hl_send_file(hl_abs($st->fetchColumn()), 'رسید');

case 'settle_save':
    [$sj, $sg] = hl_jdate($data['settle_j'] ?? '');
    if (!$sj) throw new RuntimeException('تاریخِ واریز را درست وارد کنید.');
    [$hj, $hg] = hl_jdate($data['handover_j'] ?? '');
    $amt = money_to_int($data['amount'] ?? 0);
    if ($amt <= 0) throw new RuntimeException('مبلغ را وارد کنید.');
    $cid = intval($data['contract_id'] ?? 0) ?: null;
    $c = $cid ? hl_contract_row($pdo, $cid) : null;
    $iid = intval($data['installment_id'] ?? 0) ?: null;
    if ($iid && $cid) { $st = $pdo->prepare("SELECT COUNT(*) FROM hl_installments WHERE id = ? AND contract_id = ?"); $st->execute([$iid, $cid]); if (!intval($st->fetchColumn())) $iid = null; } else $iid = null;
    $tmp = hl_take_upload('file'); $file = null;
    if ($tmp) {
        $dir = $c ? hl_contract_dir($c, 'واریز به بیمه‌گر') : hl_root() . '/پرداخت به بیمه‌گر';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $dest = hl_unique_path($dir, 'واریز به ' . hl_clean_name($S['insurer_label']) . ' - ' . str_replace('/', '-', $sj) . ' - ' . number_format($amt) . ' ریال', pathinfo($tmp, PATHINFO_EXTENSION));
        @rename($tmp, $dest); $file = hl_rel($dest);
    }
    $pdo->prepare("INSERT INTO hl_settlements (settle_j, settle_g, amount, method, ref_no, contract_id, installment_id, handover_j, handover_g, note, file_path, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$sj, $sg, $amt, array_key_exists($data['method'] ?? '', $S['methods']) ? $data['method'] : 'transfer', mb_substr(trim(p2e_digits((string)($data['ref_no'] ?? ''))), 0, 80) ?: null, $cid, $iid, $hj, $hg,
                   mb_substr(trim((string)($data['note'] ?? '')), 0, 500) ?: null, $file, $uid]);
    $out(['ok' => true, 'id' => intval($pdo->lastInsertId())]);

case 'export':
    $kind = (string)($data['kind'] ?? 'overdue');
    $pl = function ($p) { return HL_PAYEE[$p] ?? $p; };
    $stFa = ['PAID' => 'پرداخت‌شده', 'PARTIAL' => 'نیمه‌پرداخت', 'OVERDUE' => 'معوق', 'DUE' => 'سررسیدنشده'];
    if ($kind === 'payments') {
        $st = $pdo->query("SELECT x.*, c.company_name, c.policy_no, u.full_name by_name FROM hl_payments x JOIN hl_contracts c ON c.id = x.contract_id LEFT JOIN users u ON u.id = x.created_by WHERE c.is_deleted = 0 ORDER BY x.pay_g, x.id");
        $rows = array_map(function ($r) use ($S, $pl) { return [$r['pay_j'], $r['company_name'], $r['policy_no'], intval($r['amount']), $S['methods'][$r['method']] ?? $r['method'], $pl($r['payee']), $r['ref_no'], $r['bank'], $r['cheque_no'], $r['cheque_due_j'],
            $r['cheque_status'] ? HL_CHQ[$r['cheque_status']] ?? '' : '', $r['status'] === 'OK' ? 'معتبر' : 'باطل' . ($r['void_reason'] ? ' (' . $r['void_reason'] . ')' : ''), $r['by_name'], hl_dt_j($r['created_at']), $r['note']]; }, $st->fetchAll());
        xlsx_send(xlsx_build(['تاریخ', 'شرکت', 'شماره بیمه‌نامه', 'مبلغ (ریال)', 'روش', 'دریافت‌کننده', 'شماره پیگیری', 'بانک', 'شماره چک', 'سررسید چک', 'وضعیت چک', 'وضعیت', 'ثبت‌کننده', 'زمان ثبت', 'توضیح'], $rows, 'دریافت‌ها', [3]), 'دریافت‌های درمان تکمیلی - ' . hl_today_j() . '.xlsx');
        exit;
    }
    if ($kind === 'settlements') {
        $st = $pdo->query("SELECT x.*, c.company_name FROM hl_settlements x LEFT JOIN hl_contracts c ON c.id = x.contract_id ORDER BY x.settle_g, x.id");
        $rows = array_map(function ($r) use ($S) { return [$r['settle_j'], intval($r['amount']), $S['methods'][$r['method']] ?? $r['method'], $r['ref_no'], $r['company_name'] ?: 'کلی', $r['status'] === 'OK' ? 'معتبر' : 'باطل', $r['note']]; }, $st->fetchAll());
        xlsx_send(xlsx_build(['تاریخ', 'مبلغ (ریال)', 'روش', 'شماره پیگیری', 'قرارداد', 'وضعیت', 'توضیح'], $rows, 'پرداخت به بیمه‌گر', [1]), 'پرداخت به بیمه‌گر - ' . hl_today_j() . '.xlsx');
        exit;
    }
    if ($kind === 'contracts') {
        $rows = [];
        foreach ($pdo->query("SELECT * FROM hl_contracts WHERE is_deleted = 0 ORDER BY company_name") as $c) { $s = hl_contract_summary($pdo, $c);
            $rows[] = [$c['company_name'], $c['title'], $c['policy_no'], $c['insurer'], $c['start_j'], $c['end_j'], intval($c['insured_count']), intval($c['premium']), floatval($c['commission_pct']), HL_STATUS[$c['status']] ?? '',
                       $s['installments'], $s['total'], $s['paid'], $s['paid_us'], $s['paid_insurer'], $s['remaining'], $s['overdue'], $s['next_due_j']]; }
        xlsx_send(xlsx_build(['شرکت', 'عنوان', 'شماره بیمه‌نامه', 'بیمه‌گر', 'شروع', 'پایان', 'تعداد بیمه‌شده', 'حق‌بیمه (ریال)', 'کارمزد٪', 'وضعیت', 'تعداد اقساط', 'جمع اقساط', 'پرداخت‌شده', 'وصول به ما', 'وصول به بیمه‌گر', 'مانده', 'معوق', 'سررسید بعدی'],
            $rows, 'قراردادها', [6, 7, 8, 10, 11, 12, 13, 14, 15, 16]), 'قراردادهای درمان تکمیلی - ' . hl_today_j() . '.xlsx');
        exit;
    }
    $rows = hl_installments($pdo, intval($data['contract_id'] ?? 0) ?: null);
    if ($kind === 'overdue') $rows = array_filter($rows, function ($r) { return $r['status'] === 'OVERDUE'; });
    $rows = array_map(function ($r) use ($pl, $stFa) { return [$r['company_name'], $r['policy_no'], hl_fa($r['seq']), $r['due_j'], $r['amount'], $r['paid'], $r['remaining'], $pl($r['payee']), $stFa[$r['status']] ?? '', $r['days_late'] ?: '', $r['note']]; }, $rows);
    xlsx_send(xlsx_build(['شرکت', 'شماره بیمه‌نامه', 'قسط', 'سررسید', 'مبلغ (ریال)', 'پرداخت‌شده', 'مانده', 'پرداخت به', 'وضعیت', 'روزِ تأخیر', 'توضیح'], array_values($rows), $kind === 'overdue' ? 'معوقات' : 'اقساط', [4, 5, 6, 9]),
              ($kind === 'overdue' ? 'اقساط معوق درمان تکمیلی - ' : 'اقساط درمان تکمیلی - ') . hl_today_j() . '.xlsx');
    exit;

// =====================================================================
//  دفترِ ماهانه‌ی صورتحساب‌ها
// =====================================================================
case 'ledger':
    $out(['ok' => true] + hl_ledger($pdo, ['line_group' => (string)($data['line_group'] ?? ''), 'company' => trim((string)($data['company'] ?? '')), 'contract_id' => intval($data['contract_id'] ?? 0),
                                          'status' => (string)($data['status'] ?? ''), 'only' => (string)($data['only'] ?? '')]));

case 'ledger_export':
    $f = ['line_group' => (string)($data['line_group'] ?? ''), 'company' => trim((string)($data['company'] ?? '')), 'contract_id' => intval($data['contract_id'] ?? 0), 'status' => (string)($data['status'] ?? ''), 'only' => (string)($data['only'] ?? '')];
    xlsx_send(hl_ledger_xlsx($pdo, $f), 'دفتر ماهانه درمان تکمیلی' . ($f['company'] !== '' ? ' - ' . $f['company'] : '') . ' - ' . hl_today_j() . '.xlsx');
    exit;

case 'ledger_import_preview':
    [$path, $ext] = hl_take_sheet();
    $r = hl_ledger_parse($pdo, $path, $ext);
    if (isset($r['error'])) throw new RuntimeException($r['error']);
    $out(['ok' => true, 'token' => hl_tmp_save('ledger', $r), 'blocks' => hl_ledger_preview($pdo, $r), 'warnings' => array_slice($r['warnings'], 0, 50)]);

case 'ledger_import_commit':
    $parsed = hl_tmp_load('ledger', $data['token'] ?? '');
    $pick = is_array($data['pick'] ?? null) ? $data['pick'] : (json_decode((string)($data['pick'] ?? ''), true) ?: []);
    $pdo->beginTransaction();
    $r = hl_ledger_commit($pdo, $parsed, $pick, $uid);
    $pdo->commit();
    if (function_exists('sec_event')) sec_event($pdo, 'ADMIN_ACTION', 'ورودِ دفترِ ماهانه‌ی درمان تکمیلی از اکسل: ' . hl_fa($r['bills']) . ' صورتحساب، ' . hl_fa($r['payments']) . ' وصول، ' . hl_fa($r['remits']) . ' واریز');
    $out(['ok' => true, 'result' => $r]);

case 'bill_suggest':
    $c = hl_contract_row($pdo, $data['contract_id'] ?? 0);
    $p = trim(p2e_digits((string)($data['period_j'] ?? '')));
    $out(['ok' => true, 'suggest' => hl_bill_suggest($pdo, $c, preg_match('/^1[34]\d{2}\/\d{2}$/', $p) ? $p : null)]);

case 'bill_save':
    $c = hl_contract_row($pdo, $data['contract_id'] ?? 0);
    if (intval($data['id'] ?? 0) && !hl_can($pdo, 'hl-ledger:edit')) throw new RuntimeException('ویرایشِ صورتحساب دسترسیِ «ویرایش» می‌خواهد.');
    if (!intval($data['id'] ?? 0) && !hl_can($pdo, 'hl-ledger:create')) throw new RuntimeException('ثبتِ صورتحساب دسترسیِ «ثبت» می‌خواهد.');
    $pdo->beginTransaction();
    $id = hl_bill_save($pdo, $c, $data);
    if ($c['billing'] !== 'MONTHLY') $pdo->prepare("UPDATE hl_contracts SET billing = 'MONTHLY' WHERE id = ?")->execute([$c['id']]);
    $pdo->commit();
    $out(['ok' => true, 'id' => $id]);

case 'bill_delete':
    $st = $pdo->prepare("SELECT i.* FROM hl_installments i JOIN hl_contracts c ON c.id = i.contract_id WHERE i.id = ? AND c.is_deleted = 0"); $st->execute([intval($data['id'] ?? 0)]); $b = $st->fetch();
    if (!$b) throw new RuntimeException('صورتحساب پیدا نشد.');
    $n = intval($pdo->query("SELECT COUNT(*) FROM hl_alloc a JOIN hl_payments p ON p.id = a.payment_id AND p.status = 'OK' WHERE a.installment_id = " . intval($b['id']))->fetchColumn());
    if ($n) throw new RuntimeException('این صورتحساب وصول دارد؛ اول دریافت‌هایش را باطل کنید.');
    $pdo->prepare("DELETE FROM hl_installments WHERE id = ?")->execute([$b['id']]);
    $pdo->prepare("UPDATE hl_settlements SET installment_id = NULL WHERE installment_id = ?")->execute([$b['id']]);
    hl_resequence($pdo, $b['contract_id']);
    $out(['ok' => true]);

// صدورِ گروهیِ صورتحساب‌های یک ماه برای همه‌ی قراردادهای فعالِ ماهانه (تعدادِ پرسنل از بیمه‌شدگان یا ماهِ قبل)
case 'bill_generate':
    $p = trim(p2e_digits((string)($data['period_j'] ?? '')));
    if (!preg_match('/^1[34]\d{2}\/\d{2}$/', $p)) throw new RuntimeException('ماه را انتخاب کنید.');
    $ids = array_filter(array_map('intval', (array)($data['contract_ids'] ?? [])));
    $w = "is_deleted = 0 AND status = 'ACTIVE' AND billing = 'MONTHLY'" . ($ids ? " AND id IN (" . implode(',', $ids) . ")" : '');
    $made = 0; $skip = [];
    $pdo->beginTransaction();
    foreach ($pdo->query("SELECT * FROM hl_contracts WHERE $w")->fetchAll() as $c) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM hl_installments WHERE contract_id = ? AND period_j = ?"); $st->execute([$c['id'], $p]);
        if (intval($st->fetchColumn())) { $skip[] = $c['company_name'] . ' (موجود)'; continue; }
        $sg = hl_bill_suggest($pdo, $c, $p);
        if ($sg['amount'] <= 0) { $skip[] = $c['company_name'] . ($c['plan'] ? ' - ' . $c['plan'] : '') . ' (سرانه یا تعداد نامشخص)'; continue; }
        hl_bill_save($pdo, $c, ['period_j' => $p, 'headcount' => $sg['headcount'], 'unit_premium' => $sg['unit_premium'], 'amount' => $sg['amount'], 'letter_no' => '', 'letter_j' => '']);
        $made++;
    }
    $pdo->commit();
    $out(['ok' => true, 'made' => $made, 'skipped' => $skip]);

case 'sample':
    $k = (string)($data['kind'] ?? 'ledger');
    if ($k === 'members') {
        $h = ['ردیف', 'کد رایانه', 'نام', 'نام خانوادگی', 'تاریخ تولد', 'شماره شناسنامه', 'کد ملی', 'نام پدر', 'ملیت', 'شماره تماس', 'شماره همکاری', 'کد نسبت', 'جنسیت', 'وضعیت', 'وضعیت تکفل', 'بیمه شده اصلی', 'کد ملی بیمه شده اصلی', 'تاریخ شروع پوشش', 'تاریخ پایان پوشش', 'حق بیمه', 'حق بیمه ماهانه', 'کد گروه بیمه شدگان', 'شماره قرارداد'];
        $r = [[1, '', 'علی', 'نمونه', '1366/09/20', '43', '0012345678', 'محمد', 'ایران', '09120000000', '', 'اصلی', 'مرد', 'فعال', 'اصلی کارمند', 'علی نمونه', '0012345678', '1405/08/01', '1406/08/01', 105600000, 8800000, '1', '21/30054/5304-0/1405/100'],
              [2, '', 'مریم', 'نمونه', '1370/01/01', '0012345679', '0012345679', 'حسن', 'ایران', '-', '', 'همسر', 'زن', 'فعال', 'تحت تکفل', 'علی نمونه', '0012345678', '1405/08/01', '1406/08/01', 105600000, 8800000, '1', '21/30054/5304-0/1405/100']];
        xlsx_send(xlsx_build($h, $r, 'بیمه‌شدگان', [0, 19, 20]), 'نمونه - لیست بیمه‌شدگان درمان تکمیلی.xlsx'); exit;
    }
    $h = ['بیمه گذار', 'نوع بیمه', 'زیرمجموعه', 'شماره بیمه نامه', 'تاریخ صدور', 'شروع قرارداد', 'حق بیمه کل', 'حق بیمه ماهانه', 'ماه', 'نامه صورتحساب', 'تاریخ نامه', 'مبلغ صورتحساب', 'تعداد پرسنل', 'مبلغ واریزی', 'تاریخ وصول', 'محل واریز', 'اسناد واریزی', 'شماره چک', 'مبلغ واریز به پاسارگاد', 'تاریخ تحویل', 'تاریخ واریز به پاسارگاد'];
    $r = [['شرکت نمونه', 'درمان', 'طرح 1', '21/30054/5304-0/1405/100', '1405/08/10', '1405/08/01', '', 8800000, 'آبان', '4627', '1405/09/18', 1003200000, 114, 1003200000, '1405/10/09', $S['us_label'], 'چک', '1030/342887', 1003200000, '1405/10/15', '1405/10/16'],
          ['', '', '', '', '', '', '', '', 'آذر', '4700', '1405/10/18', 1020800000, 116, 1020800000, '1405/11/02', 'حواله به ' . $S['insurer_label'], 'فیش واریزی', '', '', '', ''],
          ['', '', '', '', '', '', '', '', 'دی', '', '', 1020800000, 116, '', '', '', '', '', '', '', '']];
    xlsx_send(xlsx_build($h, $r, 'دفتر ماهانه', [6, 7, 11, 12, 13, 18]), 'نمونه - دفتر ماهانه درمان تکمیلی.xlsx'); exit;

// =====================================================================
//  الحاقیه‌ها و بردرو
// =====================================================================
case 'end_save':
    $c = hl_contract_row($pdo, $data['contract_id'] ?? 0);
    $kind = isset(HL_END_KIND[$data['kind'] ?? '']) ? $data['kind'] : 'ADD';
    [$ij, $ig] = hl_jdate($data['issue_j'] ?? ''); [$ej, $eg] = hl_jdate($data['effect_j'] ?? '');
    if (!$ij) throw new RuntimeException('تاریخِ صدورِ الحاقیه را وارد کنید.');
    $amt = abs(money_to_int($data['amount'] ?? 0) ?: 0);
    if ($kind === 'REFUND') $amt = -$amt; elseif ($kind === 'FIX') $amt = intval(p2e_digits((string)($data['amount_signed'] ?? 0))) ?: 0;
    $hd = trim(p2e_digits((string)($data['headcount_delta'] ?? ''))); $hd = $hd === '' ? null : intval($hd);
    $tmp = hl_take_upload('file'); $file = null;
    if ($tmp) { $dest = hl_unique_path(hl_contract_dir($c, 'الحاقیه‌ها'), HL_END_KIND[$kind] . ' ' . hl_clean_name(p2e_digits((string)($data['end_no'] ?? ''))) . ' - ' . str_replace('/', '-', $ij), pathinfo($tmp, PATHINFO_EXTENSION)); @rename($tmp, $dest); $file = hl_rel($dest); }
    $id = intval($data['id'] ?? 0);
    if ($id) $pdo->prepare("UPDATE hl_endorsements SET end_no = ?, kind = ?, issue_j = ?, issue_g = ?, effect_j = ?, effect_g = ?, amount = ?, headcount_delta = ?, note = ?" . ($file ? ", file_path = " . $pdo->quote($file) : '') . " WHERE id = ? AND contract_id = ?")
        ->execute([mb_substr(p2e_digits(trim((string)($data['end_no'] ?? ''))), 0, 20) ?: null, $kind, $ij, $ig, $ej, $eg, $amt, $hd, mb_substr(trim((string)($data['note'] ?? '')), 0, 500) ?: null, $id, $c['id']]);
    else $pdo->prepare("INSERT INTO hl_endorsements (contract_id, end_no, kind, issue_j, issue_g, effect_j, effect_g, amount, headcount_delta, note, file_path, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$c['id'], mb_substr(p2e_digits(trim((string)($data['end_no'] ?? ''))), 0, 20) ?: null, $kind, $ij, $ig, $ej, $eg, $amt, $hd, mb_substr(trim((string)($data['note'] ?? '')), 0, 500) ?: null, $file, $uid]);
    $out(['ok' => true]);

case 'end_delete':
    $pdo->prepare("UPDATE hl_endorsements SET is_deleted = 1 WHERE id = ?")->execute([intval($data['id'] ?? 0)]);
    $out(['ok' => true]);

case 'end_file':
    $st = $pdo->prepare("SELECT file_path FROM hl_endorsements WHERE id = ?"); $st->execute([intval($data['id'] ?? 0)]);
    hl_send_file(hl_abs($st->fetchColumn()), 'الحاقیه');

case 'bordereau_preview':
    [$path, $ext] = hl_take_sheet();
    $items = hl_bordereau_parse($path, $ext);
    if (!$items) throw new RuntimeException('در این فایل ستونِ «شماره بیمه نامه» پیدا نشد؛ اکسلِ بردروی بیمه‌نامه را بدهید.');
    $prev = array_map(function ($it) use ($pdo) {
        $m = []; foreach ($it['plans'] ?: [['plan' => '', 'unit' => 0]] as $p) { $c = hl_match_contract($pdo, ['policy_no' => $it['policy_no'], 'plan' => $p['plan'], 'unit' => $p['unit']]); $m[] = ['plan' => $p['plan'], 'unit' => $p['unit'], 'match' => $c ? intval($c['id']) : null]; }
        return ['policy_no' => $it['policy_no'], 'company' => $it['company'], 'line' => $it['line'], 'start_j' => $it['start_j'], 'end_j' => $it['end_j'], 'premium' => $it['premium'], 'insured_count' => $it['insured_count'], 'plans' => $m];
    }, $items);
    $out(['ok' => true, 'token' => hl_tmp_save('bdx', $items), 'items' => $prev]);

case 'bordereau_commit':
    $items = hl_tmp_load('bdx', $data['token'] ?? '');
    $pdo->beginTransaction();
    $r = hl_bordereau_commit($pdo, $items, $uid);
    $pdo->commit();
    $out(['ok' => true, 'result' => $r]);

// =====================================================================
//  بیمه‌شدگان
// =====================================================================
case 'members_list':
case 'members_export':
    $w = ["c.is_deleted = 0"]; $p = [];
    if (intval($data['contract_id'] ?? 0)) { $w[] = "m.contract_id = ?"; $p[] = intval($data['contract_id']); }
    $q = trim(p2e_digits((string)($data['q'] ?? '')));
    if ($q !== '') { $w[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.nid LIKE ? OR m.main_name LIKE ? OR m.mobile LIKE ? OR m.comp_code LIKE ?)"; $lk = "%$q%"; array_push($p, $lk, $lk, $lk, $lk, $lk, $lk); }
    $state = (string)($data['state'] ?? '');
    if ($state === 'active') $w[] = "(m.end_g IS NULL OR m.end_g > CURDATE()) AND (m.start_g IS NULL OR m.start_g <= CURDATE())";
    elseif ($state === 'ended') $w[] = "m.end_g IS NOT NULL AND m.end_g <= CURDATE()";
    $st = $pdo->prepare("SELECT m.*, c.company_name, c.policy_no, c.plan c_plan FROM hl_members m JOIN hl_contracts c ON c.id = m.contract_id WHERE " . implode(' AND ', $w) . " ORDER BY c.company_name, m.main_nid, m.relation <> 'اصلی', m.last_name LIMIT 5000");
    $st->execute($p);
    $rows = $st->fetchAll();
    $today = date('Y-m-d');
    if ($action === 'members_export') {
        $x = array_map(function ($m) { return [$m['company_name'], $m['policy_no'], $m['c_plan'], $m['comp_code'], $m['first_name'], $m['last_name'], $m['nid'], $m['birth_j'], $m['father'], $m['relation'], $m['gender'], $m['dependency'], $m['main_name'], $m['main_nid'],
            $m['mobile'], $m['start_j'], $m['end_j'], $m['status'], $m['premium'] !== null ? intval($m['premium']) : '', $m['monthly'] !== null ? intval($m['monthly']) : '', $m['sheba'], $m['bank']]; }, $rows);
        xlsx_send(xlsx_build(['بیمه گذار', 'شماره بیمه نامه', 'طرح', 'کد رایانه', 'نام', 'نام خانوادگی', 'کد ملی', 'تاریخ تولد', 'نام پدر', 'نسبت', 'جنسیت', 'وضعیت تکفل', 'بیمه‌شده‌ی اصلی', 'کد ملی اصلی', 'تلفن', 'شروع پوشش', 'پایان پوشش', 'وضعیت', 'حق بیمه', 'حق بیمه ماهانه', 'شبا', 'بانک'],
            $x, 'بیمه‌شدگان', [18, 19]), 'بیمه‌شدگان درمان تکمیلی - ' . hl_today_j() . '.xlsx');
        exit;
    }
    $out(['ok' => true, 'rows' => array_map(function ($m) use ($today) { return ['id' => intval($m['id']), 'contract_id' => intval($m['contract_id']), 'company_name' => $m['company_name'], 'policy_no' => $m['policy_no'], 'plan' => $m['c_plan'],
        'comp_code' => $m['comp_code'], 'first_name' => $m['first_name'], 'last_name' => $m['last_name'], 'nid' => $m['nid'], 'birth_j' => $m['birth_j'], 'father' => $m['father'], 'relation' => $m['relation'], 'gender' => $m['gender'],
        'dependency' => $m['dependency'], 'main_name' => $m['main_name'], 'main_nid' => $m['main_nid'], 'mobile' => $m['mobile'], 'start_j' => $m['start_j'], 'end_j' => $m['end_j'], 'status' => $m['status'],
        'premium' => $m['premium'] !== null ? intval($m['premium']) : null, 'monthly' => $m['monthly'] !== null ? intval($m['monthly']) : null, 'sheba' => $m['sheba'], 'bank' => $m['bank'],
        'active' => (!$m['end_g'] || $m['end_g'] > $today) && (!$m['start_g'] || $m['start_g'] <= $today)]; }, $rows),
        'total' => count($rows)]);

case 'members_import_preview':
    [$path, $ext] = hl_take_sheet();
    $rows = hl_members_parse($path, $ext);
    if (!$rows) throw new RuntimeException('ردیفی پیدا نشد؛ سرستونِ «کد ملی» در فایل لازم است.');
    $cid = intval($data['contract_id'] ?? 0);
    if (!$cid && !empty($rows[0]['policy_no'])) { $c = hl_match_contract($pdo, ['policy_no' => $rows[0]['policy_no'], 'plan' => $rows[0]['plan'] ?? '', 'unit' => 0]); if ($c) $cid = intval($c['id']); }
    $pol = array_values(array_unique(array_filter(array_column($rows, 'policy_no'))));
    $out(['ok' => true, 'token' => hl_tmp_save('mem', $rows), 'count' => count($rows), 'contract_id' => $cid, 'policies' => $pol,
          'active' => count(array_filter($rows, function ($r) { [, $eg] = hl_jdate_loose($r['end_j'] ?? ''); return !$eg || $eg > date('Y-m-d'); })),
          'sample' => array_slice(array_map(function ($r) { return ['name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), 'nid' => $r['nid'] ?? '', 'relation' => $r['relation'] ?? '', 'plan' => $r['plan'] ?? '', 'start_j' => $r['start_j'] ?? '', 'end_j' => $r['end_j'] ?? '']; }, $rows), 0, 8)]);

case 'members_import_commit':
    $rows = hl_tmp_load('mem', $data['token'] ?? '');
    $c = hl_contract_row($pdo, $data['contract_id'] ?? 0);
    $pdo->beginTransaction();
    $r = hl_members_commit($pdo, $c, $rows);
    $pdo->commit();
    $r['missing'] = array_slice($r['missing'], 0, 60);
    $out(['ok' => true, 'result' => $r]);

case 'member_save':
    $c = hl_contract_row($pdo, $data['contract_id'] ?? 0);
    $id = intval($data['id'] ?? 0);
    $nid = trim(p2e_digits((string)($data['nid'] ?? '')));
    if ($nid === '' && trim((string)($data['comp_code'] ?? '')) === '') throw new RuntimeException('کد ملی را وارد کنید.');
    $m = [];
    foreach (['comp_code', 'first_name', 'last_name', 'birth_j', 'nid', 'father', 'mobile', 'relation', 'gender', 'status', 'dependency', 'main_name', 'main_nid', 'start_j', 'end_j', 'plan', 'sheba', 'bank', 'monthly', 'premium'] as $k) $m[$k] = $data[$k] ?? '';
    if ($id) { $st = $pdo->prepare("SELECT mkey FROM hl_members WHERE id = ?"); $st->execute([$id]); $k0 = $st->fetchColumn(); if ($k0 && $k0 !== ($nid !== '' ? $nid : 'c' . $m['comp_code'])) $pdo->prepare("UPDATE hl_members SET mkey = ? WHERE id = ?")->execute([$nid !== '' ? $nid : 'c' . $m['comp_code'], $id]); }
    $m['nid'] = $nid; $m['premium'] = money_to_int($m['premium']); $m['monthly'] = money_to_int($m['monthly']);
    $pdo->beginTransaction();
    hl_members_commit($pdo, $c, [$m]);
    $pdo->commit();
    $out(['ok' => true]);

case 'member_delete':
    $pdo->prepare("DELETE FROM hl_members WHERE id = ?")->execute([intval($data['id'] ?? 0)]);
    $out(['ok' => true]);

// =====================================================================
//  صندوقِ رسیدهای دریافتی (گروه‌های ربات / بارگذاریِ دستی)
// =====================================================================
case 'inbox_list':
    $status = in_array($data['status'] ?? 'NEW', ['NEW', 'DONE', 'REJECT', 'ALL'], true) ? ($data['status'] ?? 'NEW') : 'NEW';
    $w = [$status === 'ALL' ? '1' : "x.status = " . $pdo->quote($status)]; $p = [];
    $q = trim(p2e_digits((string)($data['q'] ?? '')));
    if ($q !== '') { $w[] = "(x.chat_title LIKE ? OR x.sender LIKE ? OR x.caption LIKE ? OR c.company_name LIKE ?)"; $lk = "%$q%"; array_push($p, $lk, $lk, $lk, $lk); }
    if (!empty($data['chat_id'])) { $w[] = "x.chat_id = ?"; $p[] = (string)$data['chat_id']; }
    $st = $pdo->prepare("SELECT x.*, c.company_name, c.policy_no, c.plan, i.period_j, py.amount pay_amount, py.pay_j, u.full_name by_name FROM hl_inbox x LEFT JOIN hl_contracts c ON c.id = x.contract_id LEFT JOIN hl_installments i ON i.id = x.installment_id
                           LEFT JOIN hl_payments py ON py.id = x.payment_id LEFT JOIN users u ON u.id = x.handled_by WHERE " . implode(' AND ', $w) . " ORDER BY x.status = 'NEW' DESC, x.received_at " . ($status === 'NEW' ? 'ASC' : 'DESC') . " LIMIT 500");
    $st->execute($p);
    $cnt = []; foreach ($pdo->query("SELECT status, COUNT(*) n FROM hl_inbox GROUP BY status") as $r) $cnt[$r['status']] = intval($r['n']);
    $out(['ok' => true, 'counts' => $cnt, 'rows' => array_map(function ($r) { $ext = strtolower(pathinfo((string)$r['file_path'], PATHINFO_EXTENSION));
        return ['id' => intval($r['id']), 'source' => $r['source'], 'chat_id' => $r['chat_id'], 'chat_title' => $r['chat_title'], 'sender' => $r['sender'], 'caption' => $r['caption'], 'received_at_j' => hl_dt_j($r['received_at']),
                'file_name' => basename((string)$r['file_path']), 'is_pdf' => $ext === 'pdf', 'status' => $r['status'], 'note' => $r['note'], 'contract_id' => $r['contract_id'] ? intval($r['contract_id']) : null,
                'company_name' => $r['company_name'], 'policy_no' => $r['policy_no'], 'plan' => $r['plan'], 'bill' => $r['period_j'] ? hl_period_fa($r['period_j']) : '', 'pay_amount' => $r['pay_amount'] !== null ? intval($r['pay_amount']) : null,
                'pay_j' => $r['pay_j'], 'by' => $r['by_name'], 'handled_at_j' => hl_dt_j($r['handled_at'])]; }, $st->fetchAll())]);

case 'inbox_file':
    $st = $pdo->prepare("SELECT x.file_path, p.file_path pf FROM hl_inbox x LEFT JOIN hl_payments p ON p.id = x.payment_id WHERE x.id = ?"); $st->execute([intval($data['id'] ?? 0)]); $r = $st->fetch();
    hl_send_file($r ? (hl_abs($r['pf']) ?: hl_abs($r['file_path'])) : null, 'رسید');

case 'inbox_assign':
    $st = $pdo->prepare("SELECT * FROM hl_inbox WHERE id = ? AND status = 'NEW'"); $st->execute([intval($data['id'] ?? 0)]); $x = $st->fetch();
    if (!$x) throw new RuntimeException('این رسید پیدا نشد یا قبلاً ثبت شده است.');
    $c = hl_contract_row($pdo, $data['contract_id'] ?? 0);
    $abs = hl_abs($x['file_path']);
    $pdo->beginTransaction();
    $data['inbox_id'] = $x['id'];
    if (trim((string)($data['note'] ?? '')) === '' && $x['chat_title']) $data['note'] = 'رسیدِ گروهِ «' . $x['chat_title'] . '»' . ($x['sender'] ? ' - فرستنده: ' . $x['sender'] : '');
    $r = hl_payment_create($pdo, $c, $data, $abs, $uid, $S);
    $pdo->prepare("UPDATE hl_inbox SET status = 'DONE', contract_id = ?, installment_id = ?, payment_id = ?, file_path = COALESCE(?, file_path), handled_by = ?, handled_at = NOW() WHERE id = ?")
        ->execute([$c['id'], intval($data['bill_id'] ?? 0) ?: null, $r['id'], $r['file'], $uid, $x['id']]);
    $pdo->commit();
    $out(['ok' => true, 'payment_id' => $r['id'], 'unallocated' => $r['unallocated']]);

// رسید مربوط به دریافتی است که قبلاً (دستی یا از اکسل) ثبت شده: فقط فایل با نامِ درست به پوشه‌ی قرارداد می‌رود و به همان دریافت وصل می‌شود
case 'inbox_attach':
    $st = $pdo->prepare("SELECT * FROM hl_inbox WHERE id = ? AND status = 'NEW'"); $st->execute([intval($data['id'] ?? 0)]); $x = $st->fetch();
    if (!$x) throw new RuntimeException('این رسید پیدا نشد یا قبلاً ثبت شده است.');
    $st = $pdo->prepare("SELECT p.*, (SELECT i.period_j FROM hl_alloc a JOIN hl_installments i ON i.id = a.installment_id WHERE a.payment_id = p.id ORDER BY i.period_j LIMIT 1) period_j FROM hl_payments p WHERE p.id = ? AND p.status = 'OK'");
    $st->execute([intval($data['payment_id'] ?? 0)]); $py = $st->fetch();
    if (!$py) throw new RuntimeException('دریافتِ انتخاب‌شده پیدا نشد.');
    $c = hl_contract_row($pdo, $py['contract_id']);
    $abs = hl_abs($x['file_path']);
    if (!$abs) throw new RuntimeException('فایلِ رسید پیدا نشد.');
    $base = 'رسید ' . hl_clean_name($c['company_name']) . ($py['period_j'] ? ' - ' . hl_period_fa($py['period_j']) : '') . ' - پرداخت ' . str_replace('/', '-', $py['pay_j']) . ' - ' . number_format(intval($py['amount'])) . ' ریال';
    $dest = hl_unique_path(hl_contract_dir($c, 'رسیدهای دریافتی'), p2e_digits($base), strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'jpg');
    if (!@rename($abs, $dest)) throw new RuntimeException('انتقالِ فایل ناموفق بود.');
    $rel = hl_rel($dest);
    if (!$py['file_path']) $pdo->prepare("UPDATE hl_payments SET file_path = ?, inbox_id = ? WHERE id = ?")->execute([$rel, $x['id'], $py['id']]);
    $pdo->prepare("UPDATE hl_inbox SET status = 'DONE', contract_id = ?, payment_id = ?, file_path = ?, note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?")
        ->execute([$c['id'], $py['id'], $rel, mb_substr(trim((string)($data['note'] ?? '')), 0, 500) ?: 'پیوست به دریافتِ ثبت‌شده', $uid, $x['id']]);
    $out(['ok' => true]);

case 'inbox_reject':
    $pdo->prepare("UPDATE hl_inbox SET status = 'REJECT', note = ?, handled_by = ?, handled_at = NOW() WHERE id = ? AND status = 'NEW'")->execute([mb_substr(trim((string)($data['note'] ?? '')), 0, 500) ?: null, $uid, intval($data['id'] ?? 0)]);
    $out(['ok' => true]);

case 'inbox_restore':
    $pdo->prepare("UPDATE hl_inbox SET status = 'NEW', handled_by = NULL, handled_at = NULL WHERE id = ? AND status = 'REJECT'")->execute([intval($data['id'] ?? 0)]);
    $out(['ok' => true]);

case 'inbox_upload':
    $n = 0;
    $files = $_FILES['files'] ?? null;
    if (!$files || !is_array($files['name'])) throw new RuntimeException('فایلی انتخاب نشده.');
    foreach ($files['name'] as $i => $nm) {
        if (($files['error'][$i] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i])) continue;
        $ext = strtolower(pathinfo((string)$nm, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) || $files['size'][$i] > 15 * 1024 * 1024) continue;
        $tmp = hl_root() . '/.upload'; if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $dest = $tmp . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], $dest)) continue;
        if (hl_inbox_add($pdo, $dest, ['source' => 'UPLOAD', 'chat_title' => 'بارگذاریِ دستی', 'sender' => $_SESSION['full_name'] ?? '', 'caption' => mb_substr((string)$nm, 0, 190)])) $n++;
    }
    if (!$n) throw new RuntimeException('هیچ فایلِ معتبری (PDF یا تصویر تا ۱۵ مگابایت) دریافت نشد.');
    $out(['ok' => true, 'count' => $n]);

case 'bot_groups':
    $g = [];
    try { foreach ($pdo->query("SELECT chat_id, title, last_seen_at FROM cbot_groups ORDER BY last_seen_at DESC LIMIT 100") as $r) $g[] = ['chat_id' => $r['chat_id'], 'title' => $r['title'], 'seen_j' => hl_dt_j($r['last_seen_at'])]; } catch (Throwable $e) {}
    $out(['ok' => true, 'groups' => $g]);

case 'settings_get':
    $out(['ok' => true, 'settings' => $S]);

case 'settings_save':
    $s = hl_settings_save($pdo, (array)($data['settings'] ?? []));
    $out(['ok' => true, 'settings' => $s]);
}
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[hl_actions] ' . $e->getMessage());
    $fail('خطای پایگاهِ داده. دوباره تلاش کنید.');
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $fail($e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[hl_actions] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $fail('خطای داخلی. دوباره تلاش کنید.');
}
