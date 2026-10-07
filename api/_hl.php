<?php
// فایل: api/_hl.php
// «مالیِ درمان تکمیلی»: قراردادهای درمانِ گروهیِ هر شرکت با اقساط (سررسید، مبلغ و این‌که هر قسط به «بیمه با ما» پرداخت می‌شود یا مستقیم
// به بیمه‌گر/پاسارگاد)، دریافت‌ها با هر روش (نقد، کارت، حواله، کارتخوان، چک، درگاه، تهاتر…) و تخصیص به اقساط،
// پرداخت‌های ما به پاسارگاد (تسویه‌ی وجوهِ دریافتی)، داشبورد و نمودار، معوقات، اکسل. همه‌ی جدول‌ها خودکار ساخته می‌شوند.

require_once __DIR__ . '/_case_helpers.php';

const HL_PAYEE = ['US' => 'بیمه با ما', 'INSURER' => 'بیمه‌گر (پاسارگاد)'];
const HL_STATUS = ['ACTIVE' => 'فعال', 'ENDED' => 'پایان‌یافته', 'CANCELLED' => 'فسخ‌شده'];
const HL_CHQ = ['PENDING' => 'در جریان', 'CLEARED' => 'وصول شد', 'BOUNCED' => 'برگشتی'];

function hl_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS hl_contracts (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NULL, company_name VARCHAR(190) NOT NULL, title VARCHAR(190) NULL, policy_no VARCHAR(80) NULL,
            insurer VARCHAR(60) NOT NULL DEFAULT 'پاسارگاد', start_j CHAR(10) NULL, start_g DATE NULL, end_j CHAR(10) NULL, end_g DATE NULL, insured_count INT NOT NULL DEFAULT 0,
            premium BIGINT NOT NULL DEFAULT 0, commission_pct DECIMAL(6,2) NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'ACTIVE', contact_name VARCHAR(190) NULL,
            contact_mobile VARCHAR(20) NULL, note TEXT NULL, created_by INT NULL, created_at DATETIME NULL, updated_by INT NULL, updated_at DATETIME NULL, is_deleted TINYINT NOT NULL DEFAULT 0,
            KEY k_company (company_id)) $o",
        "CREATE TABLE IF NOT EXISTS hl_installments (id INT AUTO_INCREMENT PRIMARY KEY, contract_id INT NOT NULL, seq INT NOT NULL DEFAULT 1, due_j CHAR(10) NULL, due_g DATE NULL,
            amount BIGINT NOT NULL DEFAULT 0, payee VARCHAR(8) NOT NULL DEFAULT 'US', note VARCHAR(300) NULL, KEY k_contract (contract_id), KEY k_due (due_g)) $o",
        "CREATE TABLE IF NOT EXISTS hl_payments (id INT AUTO_INCREMENT PRIMARY KEY, contract_id INT NOT NULL, pay_j CHAR(10) NULL, pay_g DATE NULL, amount BIGINT NOT NULL DEFAULT 0,
            method VARCHAR(12) NOT NULL DEFAULT 'transfer', payee VARCHAR(8) NOT NULL DEFAULT 'US', ref_no VARCHAR(80) NULL, bank VARCHAR(80) NULL, cheque_no VARCHAR(40) NULL,
            cheque_due_j CHAR(10) NULL, cheque_due_g DATE NULL, cheque_status VARCHAR(10) NULL, file_path VARCHAR(400) NULL, note VARCHAR(500) NULL, status VARCHAR(6) NOT NULL DEFAULT 'OK',
            void_reason VARCHAR(300) NULL, created_by INT NULL, created_at DATETIME NULL, voided_by INT NULL, voided_at DATETIME NULL, KEY k_contract (contract_id), KEY k_date (pay_g)) $o",
        "CREATE TABLE IF NOT EXISTS hl_alloc (id INT AUTO_INCREMENT PRIMARY KEY, payment_id INT NOT NULL, installment_id INT NOT NULL, amount BIGINT NOT NULL DEFAULT 0,
            KEY k_pay (payment_id), KEY k_inst (installment_id)) $o",
        "CREATE TABLE IF NOT EXISTS hl_settlements (id INT AUTO_INCREMENT PRIMARY KEY, settle_j CHAR(10) NULL, settle_g DATE NULL, amount BIGINT NOT NULL DEFAULT 0, method VARCHAR(12) NOT NULL DEFAULT 'transfer',
            ref_no VARCHAR(80) NULL, contract_id INT NULL, note VARCHAR(500) NULL, file_path VARCHAR(400) NULL, status VARCHAR(6) NOT NULL DEFAULT 'OK', created_by INT NULL, created_at DATETIME NULL,
            voided_by INT NULL, voided_at DATETIME NULL, KEY k_date (settle_g)) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[hl_ensure] ' . $e->getMessage()); } }
}

function hl_defaults() {
    return [
        'methods' => ['cash' => 'نقدی', 'card' => 'کارت‌به‌کارت', 'transfer' => 'حواله / ساتنا / پایا', 'pos' => 'کارتخوان', 'cheque' => 'چک', 'online' => 'درگاهِ اینترنتی', 'offset' => 'تهاتر', 'other' => 'سایر'],
        'insurers' => ['پاسارگاد', 'ایران', 'دانا', 'آسیا', 'البرز'],
        'default_payee' => 'US', 'default_insurer' => 'پاسارگاد', 'default_commission' => 0, 'due_soon_days' => 30,
        'insurer_label' => 'پاسارگاد', 'report_title' => 'مالیِ درمان تکمیلی - بیمه با ما',
    ];
}
function hl_settings($pdo) {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'hl_settings'");
    $st->execute();
    $s = json_decode((string)$st->fetchColumn(), true);
    $d = hl_defaults();
    $s = array_merge($d, is_array($s) ? $s : []);
    if (!is_array($s['methods']) || !$s['methods']) $s['methods'] = $d['methods'];
    return $s;
}
function hl_settings_save($pdo, array $in) {
    $s = hl_settings($pdo);
    if (isset($in['methods']) && is_array($in['methods'])) {
        $m = [];
        foreach ($in['methods'] as $k => $v) { $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$k)); $v = mb_substr(trim((string)$v), 0, 40); if ($k !== '' && $v !== '') $m[$k] = $v; }
        if (!isset($m['cheque'])) $m['cheque'] = 'چک';
        if ($m) $s['methods'] = $m;
    }
    if (isset($in['insurers'])) $s['insurers'] = array_values(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 60); }, (array)$in['insurers']))) ?: ['پاسارگاد'];
    if (isset($in['default_payee'])) $s['default_payee'] = $in['default_payee'] === 'INSURER' ? 'INSURER' : 'US';
    foreach (['default_insurer' => 60, 'insurer_label' => 40, 'report_title' => 120] as $k => $n) if (isset($in[$k])) $s[$k] = mb_substr(trim((string)$in[$k]), 0, $n) ?: hl_defaults()[$k];
    if (isset($in['default_commission'])) $s['default_commission'] = max(0, min(100, floatval(p2e_digits((string)$in['default_commission']))));
    if (isset($in['due_soon_days'])) $s['due_soon_days'] = max(1, min(180, intval(p2e_digits((string)$in['due_soon_days']))));
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('hl_settings', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
    return $s;
}

function hl_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function hl_today_j() { [$y, $m, $d] = jalali_from_gregorian_ts(time()); return sprintf('%04d/%02d/%02d', $y, $m, $d); }
function hl_jdate($v) {
    $s = p2e_digits(trim((string)$v));
    if (!preg_match('/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $s, $m) || intval($m[2]) < 1 || intval($m[2]) > 12 || intval($m[3]) < 1 || intval($m[3]) > 31) return [null, null];
    $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]));
    return [sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]), $ts ? date('Y-m-d', $ts) : null];
}
// افزودنِ n ماهِ شمسی به یک تاریخِ شمسی (روزِ ۳۱ در ماه‌های ۳۰ روزه => ۳۰، اسفند => ۲۹)
function hl_add_months_j($j, $n) {
    [$y, $m, $d] = array_map('intval', explode('/', $j));
    $m += $n;
    while ($m > 12) { $m -= 12; $y++; }
    while ($m < 1) { $m += 12; $y--; }
    $max = $m <= 6 ? 31 : ($m <= 11 ? 30 : 29);
    return sprintf('%04d/%02d/%02d', $y, $m, min($d, $max));
}
function hl_g2j($g) { if (!$g) return ''; [$y, $m, $d] = jalali_from_gregorian_ts(strtotime($g . ' 12:00:00')); return sprintf('%04d/%02d/%02d', $y, $m, $d); }
function hl_dt_j($dt) { if (!$dt) return ''; $ts = strtotime($dt); [$y, $m, $d] = jalali_from_gregorian_ts($ts); return sprintf('%04d/%02d/%02d %s', $y, $m, $d, date('H:i', $ts)); }
function hl_root() { return finance_root(dirname(__DIR__)) . '/درمان تکمیلی'; }
function hl_rel($abs) { $r = dirname(__DIR__) . '/'; return strpos($abs, $r) === 0 ? substr($abs, strlen($r)) : $abs; }
function hl_abs($rel) {
    if (!$rel) return null;
    $abs = realpath(dirname(__DIR__) . '/' . $rel);
    $root = realpath(dirname(__DIR__) . '/Archive');
    return ($abs && $root && strpos($abs, $root . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}
function hl_store_file(array $f, $sub, $prefix) {
    if (($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('فایل دریافت نشد.');
    if ($f['size'] > 15 * 1024 * 1024) throw new RuntimeException('حجمِ فایل بیشتر از ۱۵ مگابایت است.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) throw new RuntimeException('رسید باید PDF یا تصویر باشد.');
    $dir = hl_root() . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $prefix)) . ' - ' . date('YmdHis') . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('ذخیره‌ی فایل ناموفق بود.');
    return hl_rel($dir . '/' . $name);
}

// ---------------------------------------------------------------------
//  محاسبات
// ---------------------------------------------------------------------
// اقساطِ یک یا چند قرارداد با پرداخت‌شده/مانده/وضعیت
function hl_installments($pdo, $contractId = null, $where = '', array $params = []) {
    $w = "c.is_deleted = 0" . ($contractId ? " AND i.contract_id = " . intval($contractId) : '') . ($where ? " AND $where" : '');
    $st = $pdo->prepare("SELECT i.*, c.company_name, c.title, c.policy_no, c.status AS c_status,
                                COALESCE((SELECT SUM(a.amount) FROM hl_alloc a JOIN hl_payments p ON p.id = a.payment_id WHERE a.installment_id = i.id AND p.status = 'OK'), 0) AS paid
                           FROM hl_installments i JOIN hl_contracts c ON c.id = i.contract_id WHERE $w ORDER BY i.due_g, i.contract_id, i.seq");
    $st->execute($params);
    $today = date('Y-m-d');
    $o = [];
    foreach ($st->fetchAll() as $r) {
        $amt = intval($r['amount']); $paid = min($amt, intval($r['paid'])); $rem = $amt - $paid;
        $status = $rem <= 0 ? 'PAID' : ($r['due_g'] && $r['due_g'] < $today ? 'OVERDUE' : ($paid > 0 ? 'PARTIAL' : 'DUE'));
        $o[] = ['id' => intval($r['id']), 'contract_id' => intval($r['contract_id']), 'seq' => intval($r['seq']), 'due_j' => $r['due_j'], 'due_g' => $r['due_g'], 'amount' => $amt, 'paid' => $paid,
                'remaining' => $rem, 'payee' => $r['payee'], 'note' => $r['note'], 'status' => $status, 'company_name' => $r['company_name'], 'title' => $r['title'], 'policy_no' => $r['policy_no'],
                'days_late' => $status === 'OVERDUE' ? intval((strtotime($today) - strtotime($r['due_g'])) / 86400) : 0];
    }
    return $o;
}
function hl_contract_summary($pdo, array $c) {
    $inst = hl_installments($pdo, $c['id']);
    $sum = ['installments' => count($inst), 'total' => 0, 'paid' => 0, 'remaining' => 0, 'overdue' => 0, 'overdue_n' => 0, 'next_due_j' => null, 'next_amount' => 0, 'paid_us' => 0, 'paid_insurer' => 0];
    foreach ($inst as $i) {
        $sum['total'] += $i['amount']; $sum['paid'] += $i['paid']; $sum['remaining'] += $i['remaining'];
        if ($i['status'] === 'OVERDUE') { $sum['overdue'] += $i['remaining']; $sum['overdue_n']++; }
        if ($i['remaining'] > 0 && !$sum['next_due_j'] && $i['status'] !== 'OVERDUE') { $sum['next_due_j'] = $i['due_j']; $sum['next_amount'] = $i['remaining']; }
    }
    $st = $pdo->prepare("SELECT payee, SUM(amount) s FROM hl_payments WHERE contract_id = ? AND status = 'OK' GROUP BY payee");
    $st->execute([$c['id']]);
    foreach ($st->fetchAll() as $r) $sum[$r['payee'] === 'INSURER' ? 'paid_insurer' : 'paid_us'] = intval($r['s']);
    $sum['pct'] = $sum['total'] ? round($sum['paid'] * 100 / $sum['total']) : 0;
    return $sum;
}
// تخصیصِ یک دریافت به اقساط: اول اقساطِ همان «دریافت‌کننده»، قدیمی‌ترین سررسید اول؛ اگر چیزی ماند، بقیه‌ی اقساط
function hl_allocate($pdo, $paymentId, $contractId, $amount, $payee, array $manual = []) {
    $left = intval($amount);
    $inst = array_values(array_filter(hl_installments($pdo, $contractId), function ($i) { return $i['remaining'] > 0; }));
    $ins = $pdo->prepare("INSERT INTO hl_alloc (payment_id, installment_id, amount) VALUES (?, ?, ?)");
    if ($manual) {
        $byId = []; foreach ($inst as $i) $byId[$i['id']] = $i;
        foreach ($manual as $m) {
            $iid = intval($m['installment_id'] ?? 0); $a = min($left, money_to_int($m['amount'] ?? 0), $byId[$iid]['remaining'] ?? 0);
            if ($a > 0) { $ins->execute([$paymentId, $iid, $a]); $left -= $a; }
        }
        return $left;
    }
    usort($inst, function ($a, $b) use ($payee) { $pa = $a['payee'] === $payee ? 0 : 1; $pb = $b['payee'] === $payee ? 0 : 1; return $pa <=> $pb ?: strcmp((string)$a['due_g'], (string)$b['due_g']) ?: $a['seq'] <=> $b['seq']; });
    foreach ($inst as $i) {
        if ($left <= 0) break;
        $a = min($left, $i['remaining']);
        $ins->execute([$paymentId, $i['id'], $a]); $left -= $a;
    }
    return $left;   // اضافه‌پرداخت (بستانکار)
}
// داشبورد
function hl_dashboard($pdo, $jy, $contractId = 0, $company = '') {
    $s = hl_settings($pdo);
    $cw = "c.is_deleted = 0" . ($contractId ? " AND c.id = " . intval($contractId) : '');
    $cp = [];
    if ($company !== '') { $cw .= " AND c.company_name = ?"; $cp[] = $company; }
    $st = $pdo->prepare("SELECT c.* FROM hl_contracts c WHERE $cw");
    $st->execute($cp);
    $contracts = $st->fetchAll();
    $ids = array_map(function ($c) { return intval($c['id']); }, $contracts) ?: [0];
    $in = implode(',', $ids);
    $inst = hl_installments($pdo, null, "c.id IN ($in)");
    $today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+' . intval($s['due_soon_days']) . ' days'));
    $k = ['contracts' => count($contracts), 'active' => 0, 'insured' => 0, 'premium' => 0, 'inst_total' => 0, 'paid' => 0, 'remaining' => 0, 'overdue' => 0, 'overdue_n' => 0,
          'soon' => 0, 'soon_n' => 0, 'due_to_date' => 0, 'paid_us' => 0, 'paid_insurer' => 0, 'settled' => 0, 'cheques_pending' => 0, 'cheques_pending_n' => 0, 'commission' => 0];
    foreach ($contracts as $c) { if ($c['status'] === 'ACTIVE') $k['active']++; $k['insured'] += intval($c['insured_count']); $k['premium'] += intval($c['premium']); $k['commission'] += intval(round(intval($c['premium']) * floatval($c['commission_pct']) / 100)); }
    $overdueRows = []; $soonRows = []; $byCompany = [];
    foreach ($inst as $i) {
        $k['inst_total'] += $i['amount']; $k['paid'] += $i['paid']; $k['remaining'] += $i['remaining'];
        if ($i['due_g'] && $i['due_g'] <= $today) $k['due_to_date'] += $i['amount'];
        if ($i['status'] === 'OVERDUE') { $k['overdue'] += $i['remaining']; $k['overdue_n']++; $overdueRows[] = $i; $byCompany[$i['company_name']] = ($byCompany[$i['company_name']] ?? 0) + $i['remaining']; }
        elseif ($i['remaining'] > 0 && $i['due_g'] && $i['due_g'] <= $soon) { $k['soon'] += $i['remaining']; $k['soon_n']++; $soonRows[] = $i; }
    }
    $st = $pdo->query("SELECT payee, SUM(amount) s FROM hl_payments WHERE status = 'OK' AND contract_id IN ($in) GROUP BY payee");
    foreach ($st->fetchAll() as $r) $k[$r['payee'] === 'INSURER' ? 'paid_insurer' : 'paid_us'] = intval($r['s']);
    $st = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) s FROM hl_payments WHERE status = 'OK' AND method = 'cheque' AND COALESCE(cheque_status, 'PENDING') = 'PENDING' AND contract_id IN ($in)");
    $r = $st->fetch(); $k['cheques_pending'] = intval($r['s']); $k['cheques_pending_n'] = intval($r['n']);
    $k['settled'] = intval($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM hl_settlements WHERE status = 'OK'" . ($contractId ? " AND contract_id = " . intval($contractId) : ($company !== '' ? " AND contract_id IN ($in)" : '')))->fetchColumn());
    $k['owed_insurer'] = $k['paid_us'] - $k['settled'];
    $k['collect_pct'] = $k['due_to_date'] ? round(min($k['paid'], $k['due_to_date']) * 100 / $k['due_to_date']) : 0;
    // ماه به ماهِ سال: سررسید، وصول به ما، وصول به بیمه‌گر، پرداخت به بیمه‌گر
    $ms = date('Y-m-d', jalali_to_gregorian_ts($jy, 1, 1)); $me = date('Y-m-d', jalali_to_gregorian_ts($jy + 1, 1, 1));
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[$m] = ['m' => $m, 'label' => jalali_month_name($m), 'due' => 0, 'us' => 0, 'insurer' => 0, 'settled' => 0];
    foreach ($inst as $i) if ($i['due_g'] >= $ms && $i['due_g'] < $me) { $mm = intval(substr($i['due_j'], 5, 2)); if (isset($months[$mm])) $months[$mm]['due'] += $i['amount']; }
    $st = $pdo->prepare("SELECT pay_j, payee, amount FROM hl_payments WHERE status = 'OK' AND pay_g >= ? AND pay_g < ? AND contract_id IN ($in)");
    $st->execute([$ms, $me]);
    foreach ($st->fetchAll() as $p) { $mm = intval(substr($p['pay_j'], 5, 2)); if (isset($months[$mm])) $months[$mm][$p['payee'] === 'INSURER' ? 'insurer' : 'us'] += intval($p['amount']); }
    $st = $pdo->prepare("SELECT settle_j, amount FROM hl_settlements WHERE status = 'OK' AND settle_g >= ? AND settle_g < ?" . ($contractId ? " AND contract_id = " . intval($contractId) : ''));
    $st->execute([$ms, $me]);
    foreach ($st->fetchAll() as $p) { $mm = intval(substr($p['settle_j'], 5, 2)); if (isset($months[$mm])) $months[$mm]['settled'] += intval($p['amount']); }
    usort($overdueRows, function ($a, $b) { return $b['days_late'] <=> $a['days_late']; });
    arsort($byCompany);
    $top = []; foreach (array_slice($byCompany, 0, 8, true) as $n => $v) $top[] = ['name' => $n, 'amount' => $v];
    return ['kpi' => $k, 'months' => array_values($months), 'overdue' => array_slice($overdueRows, 0, 200), 'soon' => array_slice($soonRows, 0, 100), 'by_company' => $top];
}
