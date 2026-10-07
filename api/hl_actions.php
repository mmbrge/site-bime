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
    'bootstrap' => 'hl-dash:view|hl-contracts:view|hl-pay:view|hl-settle:view|hl-settings:view', 'dash' => 'hl-dash:view',
    'contracts_list' => 'hl-contracts:view|hl-dash:view', 'contract_get' => 'hl-contracts:view|hl-pay:view', 'contract_save' => 'hl-contracts:create|hl-contracts:edit', 'contract_delete' => 'hl-contracts:delete',
    'inst_list' => 'hl-contracts:view|hl-dash:view',
    'pay_list' => 'hl-pay:view', 'pay_save' => 'hl-pay:create', 'pay_update' => 'hl-pay:edit', 'pay_void' => 'hl-pay:delete', 'pay_file' => 'hl-pay:view',
    'settle_list' => 'hl-settle:view', 'settle_save' => 'hl-settle:create', 'settle_void' => 'hl-settle:delete', 'settle_file' => 'hl-settle:view',
    'export' => 'hl-dash:export|hl-contracts:export|hl-pay:export', 'settings_get' => 'hl-settings:view', 'settings_save' => 'hl-settings:edit',
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
            'commission_pct' => floatval($c['commission_pct']), 'status' => $c['status'], 'contact_name' => $c['contact_name'], 'contact_mobile' => $c['contact_mobile'], 'note' => $c['note']];
}
function hl_p_out(array $p, array $methods) {
    return ['id' => intval($p['id']), 'contract_id' => intval($p['contract_id']), 'company_name' => $p['company_name'] ?? '', 'policy_no' => $p['policy_no'] ?? '', 'pay_j' => $p['pay_j'], 'amount' => intval($p['amount']),
            'method' => $p['method'], 'method_fa' => $methods[$p['method']] ?? $p['method'], 'payee' => $p['payee'], 'payee_fa' => HL_PAYEE[$p['payee']] ?? '', 'ref_no' => $p['ref_no'], 'bank' => $p['bank'],
            'cheque_no' => $p['cheque_no'], 'cheque_due_j' => $p['cheque_due_j'], 'cheque_status' => $p['cheque_status'], 'cheque_status_fa' => $p['cheque_status'] ? (HL_CHQ[$p['cheque_status']] ?? '') : '',
            'has_file' => !empty($p['file_path']), 'note' => $p['note'], 'status' => $p['status'], 'void_reason' => $p['void_reason'], 'by' => $p['by_name'] ?? '', 'created_at_j' => hl_dt_j($p['created_at']),
            'allocated' => isset($p['allocated']) ? intval($p['allocated']) : null];
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
    $contracts = $pdo->query("SELECT id, company_name, title, policy_no, status FROM hl_contracts WHERE is_deleted = 0 ORDER BY company_name, id")->fetchAll();
    $out(['ok' => true, 'perms' => $perms, 'today_j' => hl_today_j(), 'jy' => $jyNow, 'years' => $years, 'settings' => $S, 'payees' => HL_PAYEE, 'statuses' => HL_STATUS, 'chq' => HL_CHQ,
          'companies' => array_map(function ($c) { return ['id' => intval($c['id']), 'name' => $c['name']]; }, $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll()),
          'contracts' => array_map(function ($c) { return ['id' => intval($c['id']), 'company_name' => $c['company_name'], 'title' => $c['title'], 'policy_no' => $c['policy_no'], 'status' => $c['status']]; }, $contracts)]);

case 'dash':
    $jy = intval($data['jy'] ?? 0) ?: $jyNow;
    $out(['ok' => true] + hl_dashboard($pdo, $jy, intval($data['contract_id'] ?? 0), trim((string)($data['company'] ?? ''))));

case 'contracts_list':
    $w = ["is_deleted = 0"]; $p = [];
    if (!empty($data['status']) && isset(HL_STATUS[$data['status']])) { $w[] = "status = ?"; $p[] = $data['status']; }
    $q = trim(p2e_digits((string)($data['q'] ?? '')));
    if ($q !== '') { $w[] = "(company_name LIKE ? OR title LIKE ? OR policy_no LIKE ? OR contact_name LIKE ?)"; $lk = "%$q%"; array_push($p, $lk, $lk, $lk, $lk); }
    $st = $pdo->prepare("SELECT * FROM hl_contracts WHERE " . implode(' AND ', $w) . " ORDER BY status = 'ACTIVE' DESC, company_name, id DESC");
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
    $out(['ok' => true, 'contract' => hl_c_out($c), 'sum' => hl_contract_summary($pdo, $c), 'installments' => hl_installments($pdo, $c['id']),
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
    // اقساط
    $rows = [];
    foreach ((array)($data['installments'] ?? []) as $i => $r) {
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
    // قسطی که پرداخت دارد پاک نمی‌شود
    $keep = array_filter(array_column($rows, 'id'));
    $old = $pdo->prepare("SELECT i.id, i.seq, (SELECT COUNT(*) FROM hl_alloc a JOIN hl_payments p ON p.id = a.payment_id WHERE a.installment_id = i.id AND p.status = 'OK') n FROM hl_installments i WHERE i.contract_id = ?");
    $old->execute([$id]);
    foreach ($old->fetchAll() as $o) if (!in_array(intval($o['id']), $keep, true)) {
        if (intval($o['n'])) { $pdo->rollBack(); throw new RuntimeException('قسطِ ' . hl_fa($o['seq']) . ' پرداخت دارد و حذف نمی‌شود؛ اول دریافت‌هایش را باطل کنید.'); }
        $pdo->prepare("DELETE FROM hl_installments WHERE id = ?")->execute([$o['id']]);
    }
    $up = $pdo->prepare("UPDATE hl_installments SET seq = ?, due_j = ?, due_g = ?, amount = ?, payee = ?, note = ? WHERE id = ? AND contract_id = ?");
    $ins = $pdo->prepare("INSERT INTO hl_installments (contract_id, seq, due_j, due_g, amount, payee, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $k => $r) {
        if ($r['id']) $up->execute([$k + 1, $r['due_j'], $r['due_g'], $r['amount'], $r['payee'], $r['note'], $r['id'], $id]);
        else $ins->execute([$id, $k + 1, $r['due_j'], $r['due_g'], $r['amount'], $r['payee'], $r['note']]);
    }
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
    $st = $pdo->prepare("SELECT x.*, c.company_name, c.policy_no, u.full_name by_name FROM hl_settlements x LEFT JOIN hl_contracts c ON c.id = x.contract_id LEFT JOIN users u ON u.id = x.created_by WHERE " . implode(' AND ', $w) . " ORDER BY x.settle_g DESC, x.id DESC LIMIT 1000");
    $st->execute($p);
    $rows = array_map(function ($r) use ($S) { return ['id' => intval($r['id']), 'settle_j' => $r['settle_j'], 'amount' => intval($r['amount']), 'method' => $r['method'], 'method_fa' => $S['methods'][$r['method']] ?? $r['method'],
        'ref_no' => $r['ref_no'], 'contract_id' => $r['contract_id'] ? intval($r['contract_id']) : null, 'company_name' => $r['company_name'], 'policy_no' => $r['policy_no'], 'note' => $r['note'], 'has_file' => !empty($r['file_path']),
        'status' => $r['status'], 'by' => $r['by_name'], 'created_at_j' => hl_dt_j($r['created_at'])]; }, $st->fetchAll());
    $k = hl_dashboard($pdo, $jyNow)['kpi'];
    $out(['ok' => true, 'rows' => $rows, 'paid_us' => $k['paid_us'], 'settled' => $k['settled'], 'owed' => $k['owed_insurer']]);

case 'pay_save':
    $c = hl_contract_row($pdo, $data['contract_id'] ?? 0);
    [$pj, $pg] = hl_jdate($data['pay_j'] ?? '');
    if (!$pj) throw new RuntimeException('تاریخِ دریافت را درست وارد کنید (مثلاً ۱۴۰۵/۰۷/۱۵).');
    $amt = money_to_int($data['amount'] ?? 0);
    if ($amt <= 0) throw new RuntimeException('مبلغ را وارد کنید.');
    $method = array_key_exists($data['method'] ?? '', $S['methods']) ? $data['method'] : 'transfer';
    $payee = ($data['payee'] ?? '') === 'INSURER' ? 'INSURER' : 'US';
    [$cdj, $cdg] = hl_jdate($data['cheque_due_j'] ?? '');
    $t = function ($k, $n) use ($data) { $v = trim(p2e_digits((string)($data[$k] ?? ''))); return $v === '' ? null : mb_substr($v, 0, $n); };
    $file = !empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE ? hl_store_file($_FILES['file'], 'دریافت‌ها/' . preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $c['company_name']), 'رسید ' . $pj) : null;
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO hl_payments (contract_id, pay_j, pay_g, amount, method, payee, ref_no, bank, cheque_no, cheque_due_j, cheque_due_g, cheque_status, file_path, note, created_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$c['id'], $pj, $pg, $amt, $method, $payee, $t('ref_no', 80), $t('bank', 80), $method === 'cheque' ? $t('cheque_no', 40) : null, $method === 'cheque' ? $cdj : null, $method === 'cheque' ? $cdg : null,
                   $method === 'cheque' ? 'PENDING' : null, $file, $t('note', 500), $uid]);
    $pid = intval($pdo->lastInsertId());
    $left = hl_allocate($pdo, $pid, $c['id'], $amt, $payee, is_array($data['alloc'] ?? null) ? $data['alloc'] : (is_string($data['alloc'] ?? null) ? (json_decode($data['alloc'], true) ?: []) : []));
    $pdo->commit();
    $out(['ok' => true, 'id' => $pid, 'unallocated' => $left]);

case 'pay_update':
    $st = $pdo->prepare("SELECT * FROM hl_payments WHERE id = ?"); $st->execute([intval($data['id'] ?? 0)]); $p = $st->fetch();
    if (!$p || $p['status'] !== 'OK') throw new RuntimeException('دریافت پیدا نشد یا باطل شده است.');
    $t = function ($k, $n) use ($data) { $v = trim(p2e_digits((string)($data[$k] ?? ''))); return $v === '' ? null : mb_substr($v, 0, $n); };
    $cs = isset(HL_CHQ[$data['cheque_status'] ?? '']) ? $data['cheque_status'] : $p['cheque_status'];
    $pdo->prepare("UPDATE hl_payments SET ref_no = ?, bank = ?, note = ?, cheque_status = ? WHERE id = ?")->execute([$t('ref_no', 80), $t('bank', 80), $t('note', 500), $p['method'] === 'cheque' ? $cs : null, $p['id']]);
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
    if (!$sj) throw new RuntimeException('تاریخِ پرداخت را درست وارد کنید.');
    $amt = money_to_int($data['amount'] ?? 0);
    if ($amt <= 0) throw new RuntimeException('مبلغ را وارد کنید.');
    $cid = intval($data['contract_id'] ?? 0) ?: null;
    if ($cid) hl_contract_row($pdo, $cid);
    $file = !empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE ? hl_store_file($_FILES['file'], 'پرداخت به بیمه‌گر', 'پرداخت ' . $sj) : null;
    $pdo->prepare("INSERT INTO hl_settlements (settle_j, settle_g, amount, method, ref_no, contract_id, note, file_path, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$sj, $sg, $amt, array_key_exists($data['method'] ?? '', $S['methods']) ? $data['method'] : 'transfer', mb_substr(trim(p2e_digits((string)($data['ref_no'] ?? ''))), 0, 80) ?: null, $cid,
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
