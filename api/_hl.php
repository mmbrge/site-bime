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
        // الحاقیه‌های بیمه‌نامه (اضافی / برگشتی / اصلاحی) - همان «جزئیاتِ تغییراتِ بیمه‌نامه» در صورت‌حسابِ بیمه‌گر
        "CREATE TABLE IF NOT EXISTS hl_endorsements (id INT AUTO_INCREMENT PRIMARY KEY, contract_id INT NOT NULL, end_no VARCHAR(20) NULL, kind VARCHAR(8) NOT NULL DEFAULT 'ADD',
            issue_j CHAR(10) NULL, issue_g DATE NULL, effect_j CHAR(10) NULL, effect_g DATE NULL, amount BIGINT NOT NULL DEFAULT 0, headcount_delta INT NULL, note VARCHAR(500) NULL,
            file_path VARCHAR(400) NULL, created_by INT NULL, created_at DATETIME NULL, is_deleted TINYINT NOT NULL DEFAULT 0, KEY k_contract (contract_id)) $o",
        // بیمه‌شدگانِ هر قرارداد (از اکسلِ «لیست بیمه‌شدگان» بیمه‌گر)
        "CREATE TABLE IF NOT EXISTS hl_members (id INT AUTO_INCREMENT PRIMARY KEY, contract_id INT NOT NULL, mkey VARCHAR(40) NOT NULL, comp_code VARCHAR(30) NULL, first_name VARCHAR(80) NULL,
            last_name VARCHAR(120) NULL, birth_j CHAR(10) NULL, ssn_no VARCHAR(20) NULL, nid VARCHAR(20) NULL, father VARCHAR(80) NULL, nationality VARCHAR(40) NULL, mobile VARCHAR(20) NULL,
            coop_no VARCHAR(30) NULL, relation VARCHAR(30) NULL, gender VARCHAR(10) NULL, status VARCHAR(30) NULL, dependency VARCHAR(40) NULL, main_name VARCHAR(190) NULL, main_nid VARCHAR(20) NULL,
            start_j CHAR(10) NULL, start_g DATE NULL, end_j CHAR(10) NULL, end_g DATE NULL, premium BIGINT NULL, monthly BIGINT NULL, plan VARCHAR(60) NULL, sheba VARCHAR(40) NULL, bank VARCHAR(60) NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY u_member (contract_id, mkey), KEY k_nid (nid)) $o",
        // رسیدهای رسیده از گروه‌های ربات (یا بارگذاریِ دستی) که هنوز به قرارداد/ماه وصل نشده‌اند
        "CREATE TABLE IF NOT EXISTS hl_inbox (id INT AUTO_INCREMENT PRIMARY KEY, source VARCHAR(8) NOT NULL DEFAULT 'BOT', chat_id VARCHAR(40) NULL, chat_title VARCHAR(190) NULL,
            sender VARCHAR(190) NULL, sender_id VARCHAR(40) NULL, message_id VARCHAR(40) NULL, caption TEXT NULL, file_path VARCHAR(400) NULL, received_at DATETIME NULL,
            status VARCHAR(8) NOT NULL DEFAULT 'NEW', contract_id INT NULL, installment_id INT NULL, payment_id INT NULL, note VARCHAR(500) NULL, handled_by INT NULL, handled_at DATETIME NULL,
            KEY k_status (status), KEY k_chat (chat_id, message_id)) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[hl_ensure] ' . $e->getMessage()); } }
    // ستون‌های اضافه‌شده بعد از نسخه‌ی اول (دفترِ ماهانه، الحاقیه، رسیدِ ربات)
    $add = [
        'hl_contracts' => ['line' => "VARCHAR(30) NOT NULL DEFAULT 'درمان'", 'plan' => 'VARCHAR(120) NULL', 'issue_j' => 'CHAR(10) NULL', 'issue_g' => 'DATE NULL',
            'unit_premium' => 'BIGINT NOT NULL DEFAULT 0', 'billing' => "VARCHAR(8) NOT NULL DEFAULT 'INSTALL'", 'prev_policy_no' => 'VARCHAR(80) NULL', 'holder_code' => 'VARCHAR(40) NULL',
            'coverage' => 'MEDIUMTEXT NULL'],
        'hl_installments' => ['kind' => "VARCHAR(6) NOT NULL DEFAULT 'INST'", 'period_j' => 'CHAR(7) NULL', 'letter_no' => 'VARCHAR(40) NULL', 'letter_j' => 'CHAR(10) NULL', 'letter_g' => 'DATE NULL',
            'headcount' => 'INT NULL', 'unit_premium' => 'BIGINT NULL'],
        'hl_payments' => ['doc_type' => 'VARCHAR(40) NULL', 'inbox_id' => 'INT NULL'],
        'hl_settlements' => ['installment_id' => 'INT NULL', 'handover_j' => 'CHAR(10) NULL', 'handover_g' => 'DATE NULL'],
    ];
    foreach ($add as $t => $cols) {
        try { $have = array_flip($pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { continue; }
        foreach ($cols as $c => $def) if (!isset($have[$c])) { try { $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def"); } catch (Throwable $e) { error_log('[hl_ensure] ' . $e->getMessage()); } }
    }
}

function hl_defaults() {
    return [
        'methods' => ['cash' => 'نقدی', 'card' => 'کارت‌به‌کارت', 'transfer' => 'حواله / ساتنا / پایا', 'pos' => 'کارتخوان', 'cheque' => 'چک', 'online' => 'درگاهِ اینترنتی', 'offset' => 'تهاتر', 'other' => 'سایر'],
        'insurers' => ['پاسارگاد', 'ایران', 'دانا', 'آسیا', 'البرز'],
        'default_payee' => 'US', 'default_insurer' => 'پاسارگاد', 'default_commission' => 0, 'due_soon_days' => 30,
        'insurer_label' => 'پاسارگاد', 'report_title' => 'مالیِ درمان تکمیلی - بیمه با ما',
        'us_label' => 'بیمه با ما',                         // در اکسلِ دفتر: «محل واریز»
        'lines' => ['درمان', 'عمر', 'حادثه'],                 // نوعِ بیمه‌ی قراردادها
        'doc_types' => ['چک', 'فیش واریزی', 'اکسل واریزی', 'حواله', 'کارت‌به‌کارت', 'سایر'],   // «اسناد واریزی»
        'bill_due_days' => 15,                              // مهلتِ پرداختِ صورتحساب بعد از تاریخِ نامه
        'remit_due_days' => 20,                             // مهلتِ واریزِ وجهِ دریافتی به بیمه‌گر
        'handover_label' => 'تاریخ تحویل به مالی',
        'receipt_groups' => [],                             // گروه‌های بله که رسیدِ پرداختِ درمان در آن‌ها فرستاده می‌شود: [['chat_id' => ..., 'title' => ...]]
        'receipt_ack' => 1,                                 // ربات زیرِ هر عکس «دریافت شد» بنویسد
        'receipt_notify' => 1,                              // کاربرانِ درمان از رسیدِ تازه باخبر شوند (اعلانِ پنل)
        'round_tolerance' => 1000,                          // اختلافِ گردکردن (ریال) که صورتحساب را «وصول‌شده» حساب می‌کند و به ماهِ بعد نمی‌رود
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
    foreach (['bill_due_days' => [0, 120], 'remit_due_days' => [0, 180], 'round_tolerance' => [0, 1000000]] as $k => [$a, $b]) if (isset($in[$k])) $s[$k] = max($a, min($b, intval(p2e_digits((string)$in[$k]))));
    foreach (['us_label' => 40, 'handover_label' => 60] as $k => $n) if (isset($in[$k])) $s[$k] = mb_substr(trim((string)$in[$k]), 0, $n) ?: hl_defaults()[$k];
    foreach (['lines', 'doc_types'] as $k) if (isset($in[$k])) { $v = array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 40); }, (array)$in[$k])))); $s[$k] = $v ?: hl_defaults()[$k]; }
    foreach (['receipt_ack', 'receipt_notify'] as $k) if (isset($in[$k])) $s[$k] = empty($in[$k]) ? 0 : 1;
    if (isset($in['receipt_groups'])) {
        $g = [];
        foreach ((array)$in['receipt_groups'] as $x) { $id = preg_replace('/[^0-9\-]/', '', p2e_digits((string)($x['chat_id'] ?? ''))); if ($id !== '' && !isset($g[$id])) $g[$id] = ['chat_id' => $id, 'title' => mb_substr(trim((string)($x['title'] ?? '')), 0, 120)]; }
        $s['receipt_groups'] = array_values($g);
    }
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
    $st = $pdo->prepare("SELECT i.*, c.company_name, c.title, c.policy_no, c.status AS c_status, c.plan, c.line,
                                COALESCE((SELECT SUM(a.amount) FROM hl_alloc a JOIN hl_payments p ON p.id = a.payment_id WHERE a.installment_id = i.id AND p.status = 'OK'), 0) AS paid
                           FROM hl_installments i JOIN hl_contracts c ON c.id = i.contract_id WHERE $w ORDER BY COALESCE(i.period_j, LEFT(i.due_j, 7)), i.due_g, i.contract_id, i.seq");
    $st->execute($params);
    $today = date('Y-m-d');
    static $tol = null; if ($tol === null) $tol = intval(hl_settings($pdo)['round_tolerance'] ?? 0);
    $o = [];
    foreach ($st->fetchAll() as $r) {
        $amt = intval($r['amount']); $paid = min($amt, intval($r['paid'])); $rem = $amt - $paid;
        if ($rem > 0 && $paid > 0 && $rem <= $tol) $rem = 0;   // اختلافِ گردکردن
        $status = $rem <= 0 ? 'PAID' : ($r['due_g'] && $r['due_g'] < $today ? 'OVERDUE' : ($paid > 0 ? 'PARTIAL' : 'DUE'));
        $o[] = ['id' => intval($r['id']), 'contract_id' => intval($r['contract_id']), 'seq' => intval($r['seq']), 'due_j' => $r['due_j'], 'due_g' => $r['due_g'], 'amount' => $amt, 'paid' => $paid,
                'remaining' => $rem, 'payee' => $r['payee'], 'note' => $r['note'], 'status' => $status, 'company_name' => $r['company_name'], 'title' => $r['title'], 'policy_no' => $r['policy_no'],
                'kind' => $r['kind'] ?? 'INST', 'period_j' => $r['period_j'] ?? null, 'period_fa' => hl_period_fa($r['period_j'] ?? null), 'letter_no' => $r['letter_no'] ?? null, 'letter_j' => $r['letter_j'] ?? null,
                'headcount' => isset($r['headcount']) ? intval($r['headcount']) : null, 'unit_premium' => isset($r['unit_premium']) ? intval($r['unit_premium']) : null, 'plan' => $r['plan'] ?? null, 'line' => $r['line'] ?? null,
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
// $preferId: صورتحساب/قسطی که این دریافت «بابتِ» آن است؛ اول همان پر می‌شود و اضافه به بعدی‌ها می‌رود (مثلِ واریزِ دو ماه با هم)
function hl_allocate($pdo, $paymentId, $contractId, $amount, $payee, array $manual = [], $preferId = 0) {
    $left = intval($amount);
    $inst = array_values(array_filter(hl_installments($pdo, $contractId), function ($i) { return $i['remaining'] > 0; }));
    $ins = $pdo->prepare("INSERT INTO hl_alloc (payment_id, installment_id, amount) VALUES (?, ?, ?)");
    if ($preferId && !$manual) {
        $pos = null;
        foreach ($inst as $k => $i) if ($i['id'] === intval($preferId)) { $pos = $k; break; }
        if ($pos !== null) {
            // از همان ماه به بعد (به ترتیبِ دوره)، بعد ماه‌های قبلی
            $order = array_merge(array_slice($inst, $pos), array_slice($inst, 0, $pos));
            $tol = intval(hl_settings($pdo)['round_tolerance'] ?? 0);
            foreach ($order as $k => $i) {
                if ($left <= 0 || ($k > 0 && $left <= $tol)) break;   // خرده‌ی گردکردن به ماهِ بعد نمی‌رود (بستانکار می‌ماند)
                $a = min($left, $i['remaining']); $ins->execute([$paymentId, $i['id'], $a]); $left -= $a;
            }
            return $left;
        }
    }
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

// =====================================================================
//  دفترِ ماهانه‌ی صورتحساب‌ها (همان اکسلِ «ماموت درمان / ماموت عمر و حادثه»)
//  هر قرارداد = یک بیمه‌نامه (یا یک طرح از آن)؛ هر ماه یک «صورتحساب» (قسط با kind=BILL): نامه، تاریخِ نامه، تعدادِ پرسنل × حق‌بیمه‌ی سرانه.
//  وصول‌ها همان دریافت‌ها هستند که به صورتحساب‌ها تخصیص می‌خورند؛ واریز به پاسارگاد همان «پرداخت به بیمه‌گر» با تاریخِ تحویل.
// =====================================================================
const HL_END_KIND = ['ADD' => 'الحاقیه‌ی اضافی', 'REFUND' => 'الحاقیه‌ی برگشتی', 'FIX' => 'الحاقیه‌ی اصلاحی'];

function hl_period_fa($p) {
    if (!$p || !preg_match('/^(\d{4})\/(\d{2})$/', $p, $m)) return '';
    return jalali_month_name(intval($m[2])) . ' ' . hl_fa($m[1]);
}
function hl_period_next($p, $n = 1) { [$y, $m] = array_map('intval', explode('/', $p)); $m += $n; while ($m > 12) { $m -= 12; $y++; } while ($m < 1) { $m += 12; $y--; } return sprintf('%04d/%02d', $y, $m); }
function hl_period_first_g($p) { [$y, $m] = array_map('intval', explode('/', $p)); $ts = jalali_to_gregorian_ts($y, $m, 1); return $ts ? date('Y-m-d', $ts) : null; }
// عدد از سلولِ اکسل (۱۰۰۳۲۰۰۰۰۰، «۱,۰۰۳,۲۰۰,۰۰۰»، 1.0032E9)
function hl_num($v) {
    $v = trim(p2e_digits((string)$v));
    if ($v === '' || $v === '-') return 0;
    if (preg_match('/^-?\d+(\.\d+)?(E[+-]?\d+)?$/i', $v)) return intval(round(floatval($v)));
    $neg = strpos($v, '-') === 0;
    $d = preg_replace('/\D/', '', $v);
    return $d === '' ? 0 : ($neg ? -1 : 1) * intval($d);
}
// تاریخِ «شل» از اکسل: ۱۴۰۵/۰۷/۰۱، 1405/0701، 14050701، 1405-7-1
function hl_jdate_loose($v) {
    $s = trim(p2e_digits((string)$v));
    if ($s === '') return [null, null];
    $r = hl_jdate($s);
    if ($r[0]) return $r;
    $d = preg_replace('/\D/', '', $s);
    if (strlen($d) === 8) return hl_jdate(substr($d, 0, 4) . '/' . substr($d, 4, 2) . '/' . substr($d, 6, 2));
    if (preg_match('/^(1[34]\d{2})\D+(\d{1,2})(\d{2})$/', $s, $m)) return hl_jdate("$m[1]/$m[2]/$m[3]");
    return [null, null];
}
// یکسان‌سازیِ متن برای تطبیق (ي/ك عربی، نیم‌فاصله، فاصله‌ها)
function hl_norm($s) {
    $s = str_replace(['ي', 'ى', 'ك', 'ة', 'ۀ', 'أ', 'إ', "\xE2\x80\x8C", "\xE2\x80\x8F", "\xE2\x80\x8E"], ['ی', 'ی', 'ک', 'ه', 'ه', 'ا', 'ا', '', '', ''], p2e_digits((string)$s));
    return mb_strtolower(preg_replace('/\s+/u', '', $s));
}
function hl_month_index($label) {
    $n = hl_norm($label);
    foreach (['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'] as $i => $m) if ($n === hl_norm($m)) return $i + 1;
    if (preg_match('/^(\d{1,2})$/', $n, $mm) && intval($mm[1]) >= 1 && intval($mm[1]) <= 12) return intval($mm[1]);
    return 0;
}
// کلیدِ طرح: «طرح 1»، «طرح ۱(1)» و «1» یکی‌اند
function hl_plan_key($s) { $n = hl_norm($s); if (preg_match('/\d+/', $n, $m)) return 'p' . intval($m[0]); return $n; }
function hl_clean_name($s) { return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', (string)$s))); }
// پوشه‌ی هر قرارداد: مالی/درمان تکمیلی/{شرکت}/{نوع} {شماره‌ی بیمه‌نامه}{ - طرح}
function hl_contract_dir(array $c, $sub = '') {
    $pn = hl_clean_name(str_replace('/', '-', (string)$c['policy_no']));
    $name = hl_clean_name(($c['line'] ?? 'درمان') . ($pn !== '' ? ' ' . $pn : ' قرارداد ' . $c['id']) . (!empty($c['plan']) ? ' - ' . $c['plan'] : ''));
    $dir = hl_root() . '/' . (hl_clean_name($c['company_name']) ?: 'بدون نام') . '/' . $name . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
function hl_inbox_dir() { $d = hl_root() . '/موقت - رسیدهای دریافتی/' . str_replace('/', '-', substr(hl_today_j(), 0, 7)); if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function hl_unique_path($dir, $base, $ext) {
    $p = $dir . '/' . $base . '.' . $ext; $i = 2;
    while (file_exists($p)) $p = $dir . '/' . $base . ' (' . hl_fa($i++) . ').' . $ext;
    return $p;
}

// صورتحسابِ پیشنهادیِ ماهِ بعد برای یک قرارداد (دوره، تعدادِ پرسنل، سرانه، مبلغ)
function hl_bill_suggest($pdo, array $c, $period = null) {
    $st = $pdo->prepare("SELECT period_j, headcount, unit_premium FROM hl_installments WHERE contract_id = ? AND kind = 'BILL' AND period_j IS NOT NULL ORDER BY period_j DESC, id DESC LIMIT 1");
    $st->execute([$c['id']]);
    $last = $st->fetch();
    if (!$period) $period = $last ? hl_period_next($last['period_j']) : ($c['start_j'] ? substr($c['start_j'], 0, 7) : substr(hl_today_j(), 0, 7));
    $unit = intval($c['unit_premium']) ?: ($last ? intval($last['unit_premium']) : 0);
    $hc = hl_members_active($pdo, [$c['id']], hl_period_first_g($period));
    $src = 'members';
    if (!$hc && $last) { $hc = intval($last['headcount']); $src = 'last'; }
    if (!$hc) { $hc = intval($c['insured_count']); $src = 'contract'; }
    return ['period_j' => $period, 'period_fa' => hl_period_fa($period), 'headcount' => $hc, 'headcount_src' => $src, 'unit_premium' => $unit, 'amount' => $hc * $unit];
}
function hl_bill_due($pdo, $letterJ, $period) {
    $S = hl_settings($pdo);
    [$lj, $lg] = hl_jdate($letterJ ?: '');
    if ($lg) $g = date('Y-m-d', strtotime($lg . ' +' . intval($S['bill_due_days']) . ' days'));
    else { $f = hl_period_first_g(hl_period_next($period)); $g = $f ? date('Y-m-d', strtotime($f . ' +' . intval($S['bill_due_days']) . ' days')) : date('Y-m-d'); }
    return [hl_g2j($g), $g];
}
// ثبت/ویرایشِ یک صورتحسابِ ماهانه
function hl_bill_save($pdo, array $c, array $d) {
    $period = p2e_digits(trim((string)($d['period_j'] ?? '')));
    if (preg_match('/^(1[34]\d{2})[\/\-.](\d{1,2})/', $period, $m)) $period = sprintf('%04d/%02d', $m[1], $m[2]); else throw new RuntimeException('ماهِ صورتحساب را انتخاب کنید.');
    [$lj, $lg] = hl_jdate_loose($d['letter_j'] ?? '');
    if (trim((string)($d['letter_j'] ?? '')) !== '' && !$lj) throw new RuntimeException('تاریخِ نامه نامعتبر است.');
    $hc = max(0, intval(p2e_digits((string)($d['headcount'] ?? 0))));
    $unit = max(0, money_to_int($d['unit_premium'] ?? 0) ?: 0);
    $amt = money_to_int($d['amount'] ?? 0) ?: $hc * $unit;
    if ($amt <= 0) throw new RuntimeException('مبلغِ صورتحساب (یا تعدادِ پرسنل و حق‌بیمه‌ی سرانه) را وارد کنید.');
    [$dj, $dg] = hl_bill_due($pdo, $lj, $period);
    $payee = ($d['payee'] ?? '') === 'INSURER' ? 'INSURER' : (($d['payee'] ?? '') === 'US' ? 'US' : hl_settings($pdo)['default_payee']);
    $letter = mb_substr(trim(p2e_digits((string)($d['letter_no'] ?? ''))), 0, 40) ?: null;
    $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 300) ?: null;
    $id = intval($d['id'] ?? 0);
    if ($id) {
        $st = $pdo->prepare("SELECT * FROM hl_installments WHERE id = ? AND contract_id = ?"); $st->execute([$id, $c['id']]);
        if (!$st->fetch()) throw new RuntimeException('صورتحساب پیدا نشد.');
        $paid = intval($pdo->query("SELECT COALESCE(SUM(a.amount),0) FROM hl_alloc a JOIN hl_payments p ON p.id = a.payment_id AND p.status = 'OK' WHERE a.installment_id = " . $id)->fetchColumn());
        if ($amt < $paid) throw new RuntimeException('مبلغِ صورتحساب از وصول‌شده‌اش (' . hl_fa(number_format($paid)) . ') کمتر نمی‌شود.');
        $pdo->prepare("UPDATE hl_installments SET kind = 'BILL', period_j = ?, letter_no = ?, letter_j = ?, letter_g = ?, headcount = ?, unit_premium = ?, amount = ?, due_j = ?, due_g = ?, payee = ?, note = ? WHERE id = ?")
            ->execute([$period, $letter, $lj, $lg, $hc ?: null, $unit ?: null, $amt, $dj, $dg, $payee, $note, $id]);
    } else {
        $seq = intval($pdo->query("SELECT COALESCE(MAX(seq),0)+1 FROM hl_installments WHERE contract_id = " . intval($c['id']))->fetchColumn());
        $pdo->prepare("INSERT INTO hl_installments (contract_id, seq, kind, period_j, letter_no, letter_j, letter_g, headcount, unit_premium, amount, due_j, due_g, payee, note) VALUES (?, ?, 'BILL', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$c['id'], $seq, $period, $letter, $lj, $lg, $hc ?: null, $unit ?: null, $amt, $dj, $dg, $payee, $note]);
        $id = intval($pdo->lastInsertId());
    }
    hl_resequence($pdo, $c['id']);
    return $id;
}
function hl_resequence($pdo, $cid) {
    $st = $pdo->prepare("SELECT id FROM hl_installments WHERE contract_id = ? ORDER BY COALESCE(period_j, LEFT(due_j, 7)), due_g, id"); $st->execute([$cid]);
    $up = $pdo->prepare("UPDATE hl_installments SET seq = ? WHERE id = ?");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k => $id) $up->execute([$k + 1, $id]);
}

// بیمه‌شدگانِ فعال در یک تاریخ (برای پیشنهادِ «تعدادِ پرسنل»)
function hl_members_active($pdo, array $cids, $dateG) {
    $cids = array_filter(array_map('intval', $cids));
    if (!$cids || !$dateG) return 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM hl_members WHERE contract_id IN (" . implode(',', $cids) . ") AND (start_g IS NULL OR start_g <= ?) AND (end_g IS NULL OR end_g > ?)");
    $st->execute([$dateG, $dateG]);
    return intval($st->fetchColumn());
}

// دفترِ ماهانه: برای هر قرارداد، ردیفِ هر ماه با وصول‌ها و واریز به بیمه‌گر
//  $f: line_group (health|other|''), company, contract_id, status, only (overdue|unremitted|open|'')
function hl_ledger($pdo, array $f = []) {
    $S = hl_settings($pdo);
    $w = ["c.is_deleted = 0"]; $p = [];
    if (($f['line_group'] ?? '') === 'health') $w[] = "c.line = 'درمان'";
    elseif (($f['line_group'] ?? '') === 'other') $w[] = "c.line <> 'درمان'";
    elseif (!empty($f['line'])) { $w[] = "c.line = ?"; $p[] = (string)$f['line']; }
    if (!empty($f['company'])) { $w[] = "c.company_name = ?"; $p[] = (string)$f['company']; }
    if (!empty($f['contract_id'])) { $w[] = "c.id = ?"; $p[] = intval($f['contract_id']); }
    if (!empty($f['status']) && isset(HL_STATUS[$f['status']])) { $w[] = "c.status = ?"; $p[] = $f['status']; }
    $st = $pdo->prepare("SELECT c.* FROM hl_contracts c WHERE " . implode(' AND ', $w) . " ORDER BY c.company_name, c.line = 'درمان' DESC, c.line, c.policy_no, c.plan, c.id");
    $st->execute($p);
    $contracts = $st->fetchAll();
    if (!$contracts) return ['contracts' => [], 'totals' => []];
    $ids = implode(',', array_map(function ($c) { return intval($c['id']); }, $contracts));
    $inst = [];
    foreach (hl_installments($pdo, null, "c.id IN ($ids)") as $i) $inst[$i['contract_id']][] = $i;
    $alloc = [];
    foreach ($pdo->query("SELECT a.installment_id, a.amount alloc, p.* FROM hl_alloc a JOIN hl_payments p ON p.id = a.payment_id WHERE p.status = 'OK' AND p.contract_id IN ($ids) ORDER BY p.pay_g, p.id") as $r) $alloc[$r['installment_id']][] = $r;
    $sett = [];
    foreach ($pdo->query("SELECT * FROM hl_settlements WHERE status = 'OK' AND contract_id IN ($ids) ORDER BY settle_g, id") as $r) $sett[$r['contract_id']][] = $r;
    $unalloc = [];
    foreach ($pdo->query("SELECT p.contract_id, SUM(p.amount - COALESCE((SELECT SUM(a.amount) FROM hl_alloc a WHERE a.payment_id = p.id), 0)) s FROM hl_payments p WHERE p.status = 'OK' AND p.contract_id IN ($ids) GROUP BY p.contract_id") as $r) $unalloc[$r['contract_id']] = intval($r['s']);
    $ends = [];
    foreach ($pdo->query("SELECT contract_id, SUM(amount) s FROM hl_endorsements WHERE is_deleted = 0 AND contract_id IN ($ids) GROUP BY contract_id") as $r) $ends[$r['contract_id']] = intval($r['s']);
    $today = date('Y-m-d'); $remitDays = intval($S['remit_due_days']);
    $T = ['billed' => 0, 'received' => 0, 'received_us' => 0, 'received_ins' => 0, 'remaining' => 0, 'overdue' => 0, 'remitted' => 0, 'to_remit' => 0, 'remit_late' => 0, 'bills' => 0, 'headcount' => 0];
    $out = [];
    foreach ($contracts as $c) {
        $rows = [];
        foreach ($inst[$c['id']] ?? [] as $i) {
            $rc = []; $us = 0; $ins = 0; $lastPay = null;
            foreach ($alloc[$i['id']] ?? [] as $a) {
                $rc[] = ['payment_id' => intval($a['id']), 'pay_j' => $a['pay_j'], 'amount' => intval($a['alloc']), 'payment_total' => intval($a['amount']), 'payee' => $a['payee'], 'method' => $a['method'],
                         'method_fa' => $S['methods'][$a['method']] ?? $a['method'], 'doc_type' => $a['doc_type'], 'cheque_no' => $a['cheque_no'], 'cheque_status' => $a['cheque_status'], 'ref_no' => $a['ref_no'], 'has_file' => !empty($a['file_path'])];
                if ($a['payee'] === 'INSURER') $ins += intval($a['alloc']); else $us += intval($a['alloc']);
                $lastPay = $a['pay_g'];
            }
            $rows[] = $i + ['receipts' => $rc, 'received' => $us + $ins, 'received_us' => $us, 'received_ins' => $ins, 'last_pay_g' => $lastPay, 'remitted' => 0, 'remits' => [], 'need' => $us];
        }
        // پخشِ واریزهای به بیمه‌گر روی ماه‌ها: از ماهِ مشخص‌شده به بعد، بعد از ابتدا (واریزِ چندماهه هم درست تقسیم می‌شود)
        $extraRemit = 0;
        $pos = []; foreach ($rows as $k => $r) $pos[$r['id']] = $k;
        $ss = $sett[$c['id']] ?? [];
        usort($ss, function ($a, $b) use ($pos) { $pa = isset($pos[$a['installment_id']]) ? $pos[$a['installment_id']] : 9999; $pb = isset($pos[$b['installment_id']]) ? $pos[$b['installment_id']] : 9999; return $pa <=> $pb ?: strcmp((string)$a['settle_g'], (string)$b['settle_g']); });
        foreach ($ss as $s) {
            $left = intval($s['amount']);
            $start = isset($pos[$s['installment_id']]) ? $pos[$s['installment_id']] : 0;
            $order = $rows ? array_merge(range($start, count($rows) - 1), $start > 0 ? range(0, $start - 1) : []) : [];
            foreach ($order as $k) {
                if ($left <= 0 || !isset($rows[$k])) continue;
                $room = $rows[$k]['need'] - $rows[$k]['remitted'];
                if ($room <= 0) continue;
                $a = min($left, $room);
                $rows[$k]['remitted'] += $a; $left -= $a;
                $rows[$k]['remits'][] = ['id' => intval($s['id']), 'settle_j' => $s['settle_j'], 'handover_j' => $s['handover_j'], 'amount' => $a, 'total' => intval($s['amount']), 'ref_no' => $s['ref_no'], 'has_file' => !empty($s['file_path'])];
            }
            $extraRemit += $left;
        }
        $cs = ['billed' => 0, 'received' => 0, 'received_us' => 0, 'received_ins' => 0, 'remaining' => 0, 'overdue' => 0, 'remitted' => 0, 'to_remit' => 0, 'remit_late' => 0, 'bills' => count($rows), 'last_headcount' => 0];
        foreach ($rows as &$r) {
            $r['to_remit'] = max(0, $r['need'] - $r['remitted']);
            $r['remit_status'] = $r['need'] <= 0 ? ($r['received_ins'] > 0 ? 'DIRECT' : 'NA') : ($r['to_remit'] <= 0 ? 'DONE' : ($r['remitted'] > 0 ? 'PARTIAL' : 'PENDING'));
            $r['remit_late'] = $r['to_remit'] > 0 && $r['last_pay_g'] && strtotime($r['last_pay_g'] . " +$remitDays days") < strtotime($today);
            unset($r['need']);
            $cs['billed'] += $r['amount']; $cs['received'] += $r['received']; $cs['received_us'] += $r['received_us']; $cs['received_ins'] += $r['received_ins'];
            $cs['remaining'] += $r['remaining']; if ($r['status'] === 'OVERDUE') $cs['overdue'] += $r['remaining'];
            $cs['remitted'] += $r['remitted']; $cs['to_remit'] += $r['to_remit']; if ($r['remit_late']) $cs['remit_late'] += $r['to_remit'];
            if ($r['headcount']) $cs['last_headcount'] = $r['headcount'];
        }
        unset($r);
        $cs['unallocated'] = $unalloc[$c['id']] ?? 0;
        $cs['extra_remit'] = $extraRemit;
        $cs['endorse'] = $ends[$c['id']] ?? 0;
        $cs['final_premium'] = intval($c['premium']) + $cs['endorse'];
        $only = (string)($f['only'] ?? '');
        if ($only === 'overdue') $rows = array_values(array_filter($rows, function ($r) { return $r['status'] === 'OVERDUE'; }));
        elseif ($only === 'unremitted') $rows = array_values(array_filter($rows, function ($r) { return $r['to_remit'] > 0; }));
        elseif ($only === 'open') $rows = array_values(array_filter($rows, function ($r) { return $r['remaining'] > 0 || $r['to_remit'] > 0; }));
        if ($only !== '' && !$rows) continue;
        foreach ($T as $k => $v) if (isset($cs[$k])) $T[$k] += $cs[$k];
        $T['headcount'] += $cs['last_headcount'];
        $out[] = ['contract' => ['id' => intval($c['id']), 'company_name' => $c['company_name'], 'line' => $c['line'], 'plan' => $c['plan'], 'policy_no' => $c['policy_no'], 'title' => $c['title'],
                  'insurer' => $c['insurer'], 'issue_j' => $c['issue_j'], 'start_j' => $c['start_j'], 'end_j' => $c['end_j'], 'premium' => intval($c['premium']), 'unit_premium' => intval($c['unit_premium']),
                  'billing' => $c['billing'], 'status' => $c['status'], 'insured_count' => intval($c['insured_count'])], 'sum' => $cs, 'rows' => $rows];
    }
    return ['contracts' => $out, 'totals' => $T];
}

// اکسلِ دفتر با همان چیدمانِ فایلِ خودِ شرکت: شیتِ «درمان» و شیتِ «عمر و حادثه» + «خلاصه»
function hl_ledger_xlsx($pdo, array $f = []) {
    $S = hl_settings($pdo);
    $L = hl_ledger($pdo, $f);
    $payee = function ($p) use ($S) { return $p === 'INSURER' ? 'حواله به ' . $S['insurer_label'] : $S['us_label']; };
    $hdr = ['بیمه گذار', 'نوع بیمه', 'زیرمجموعه / طرح', 'شماره بیمه نامه', 'تاریخ صدور', 'شروع قرارداد', 'حق بیمه کل', 'حق بیمه ماهانه (سرانه)', 'ماه', 'نامه صورتحساب', 'تاریخ نامه', 'مبلغ صورتحساب',
            'تعداد پرسنل', 'مبلغ واریزی', 'تاریخ وصول', 'محل واریز', 'اسناد واریزی', 'شماره چک', 'مبلغ واریز به ' . $S['insurer_label'], $S['handover_label'], 'تاریخ واریز به ' . $S['insurer_label'], 'مانده‌ی صورتحساب', 'وضعیت'];
    $stFa = ['PAID' => 'وصول شد', 'PARTIAL' => 'نیمه‌وصول', 'OVERDUE' => 'معوق', 'DUE' => 'در انتظار'];
    $groups = ['درمان' => [], 'عمر و حادثه' => []];
    foreach ($L['contracts'] as $b) $groups[$b['contract']['line'] === 'درمان' ? 'درمان' : 'عمر و حادثه'][] = $b;
    $sheets = [];
    foreach ($groups as $gname => $blocks) {
        if (!$blocks && $gname !== 'درمان') continue;
        $rows = []; $merges = []; $shade = []; $alt = false;
        foreach ($blocks as $b) {
            $c = $b['contract']; $start = count($rows);
            foreach ($b['rows'] as $r) {
                $n = max(1, count($r['receipts']), count($r['remits']));
                $r0 = count($rows);
                for ($k = 0; $k < $n; $k++) {
                    $rc = $r['receipts'][$k] ?? null; $rm = $r['remits'][$k] ?? null;
                    $rows[] = [$c['company_name'], $c['line'], $c['plan'], $c['policy_no'], $c['issue_j'], $c['start_j'], $c['premium'] ?: '', $c['unit_premium'] ?: '',
                        $k ? '' : ($r['period_fa'] ? hl_strip_fa_digits($r['period_fa']) : $r['due_j']), $k ? '' : $r['letter_no'], $k ? '' : $r['letter_j'], $k ? '' : $r['amount'], $k ? '' : $r['headcount'],
                        $rc ? $rc['amount'] : '', $rc ? $rc['pay_j'] : '', $rc ? $payee($rc['payee']) : '', $rc ? ($rc['doc_type'] ?: $rc['method_fa']) : '', $rc ? $rc['cheque_no'] : '',
                        $rm ? $rm['amount'] : '', $rm ? $rm['handover_j'] : '', $rm ? $rm['settle_j'] : '', $k ? '' : $r['remaining'], $k ? '' : ($stFa[$r['status']] ?? '')];
                    if ($alt) $shade[] = count($rows) - 1;
                }
                if ($n > 1) foreach ([8, 9, 10, 11, 12, 21, 22] as $col) $merges[] = [$r0, $col, $r0 + $n - 1, $col];
            }
            if (!$b['rows']) { $rows[] = [$c['company_name'], $c['line'], $c['plan'], $c['policy_no'], $c['issue_j'], $c['start_j'], $c['premium'] ?: '', $c['unit_premium'] ?: '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'بدونِ صورتحساب']; if ($alt) $shade[] = count($rows) - 1; }
            $end = count($rows) - 1;
            if ($end > $start) for ($col = 0; $col <= 7; $col++) $merges[] = [$start, $col, $end, $col];
            // جمعِ هر قرارداد
            $s = $b['sum'];
            $rows[] = ['', '', '', '', '', '', '', '', 'جمع', '', '', $s['billed'], '', $s['received'], '', '', '', '', $s['remitted'], '', '', $s['remaining'], ''];
            $shade[] = count($rows) - 1;
            $rows[] = array_fill(0, count($hdr), '');
            $alt = !$alt;
        }
        $sheets[] = ['name' => $gname, 'title' => 'دفترِ ماهانه‌ی ' . $gname . ' - ' . $S['report_title'] . ' - ' . hl_today_j(), 'headers' => $hdr, 'rows' => $rows, 'numeric' => [6, 7, 11, 12, 13, 18, 21], 'merges' => $merges, 'shade' => $shade];
    }
    // خلاصه
    $sum = [];
    foreach ($L['contracts'] as $b) { $c = $b['contract']; $s = $b['sum'];
        $sum[] = [$c['company_name'], $c['line'], $c['plan'], $c['policy_no'], $s['bills'], $s['last_headcount'], $s['final_premium'], $s['billed'], $s['received'], $s['received_us'], $s['received_ins'], $s['remaining'], $s['overdue'], $s['remitted'], $s['to_remit'], $s['remit_late']]; }
    $sheets[] = ['name' => 'خلاصه', 'title' => 'خلاصه‌ی قراردادها - ' . hl_today_j(), 'headers' => ['بیمه گذار', 'نوع بیمه', 'طرح', 'شماره بیمه نامه', 'تعداد صورتحساب', 'پرسنلِ آخرین ماه', 'حق‌بیمه‌ی نهایی (با الحاقیه)', 'جمع صورتحساب‌ها',
        'جمع وصول', 'وصول به ' . $S['us_label'], 'وصول مستقیم به ' . $S['insurer_label'], 'مانده‌ی وصول', 'معوق', 'واریزشده به ' . $S['insurer_label'], 'مانده‌ی واریز به ' . $S['insurer_label'], 'واریزِ دیرکرد'],
        'rows' => $sum, 'numeric' => [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]];
    return xlsx_build_book($sheets);
}
function hl_strip_fa_digits($s) { return p2e_digits($s); }

// ---------------------------------------------------------------------
//  ورود از اکسلِ دفترِ خودِ شرکت (هر بلوک = یک بیمه‌نامه/طرح، ردیف‌ها = ماه‌ها)
// ---------------------------------------------------------------------
const HL_LEDGER_COLS = [
    'remit_j' => ['تاریخواریزبهپاسارگاد', 'تاریخواریزبهبیمهگر'],
    'handover_j' => ['تاریختحویل'],
    'company' => ['بیمهگذار'], 'line' => ['نوعبیمه'], 'plan' => ['زیرمجموعه', 'طرح'], 'policy_no' => ['شمارهبیمهنامه', 'شمارهقرارداد'], 'issue_j' => ['تاریخصدور'],
    'start_j' => ['شروعقراداد', 'شروعقرارداد', 'تاریخشروع'], 'premium' => ['حقبیمهکل'], 'unit' => ['حقبیمهماهانه', 'حقبیمهسرانه', 'حقبیمهنفر'], 'month' => ['ماه'],
    'letter_no' => ['نامهصورتحساب', 'شمارهنامه', 'نامه'], 'letter_j' => ['تاریخنامه'], 'amount' => ['مبلغصورتحساب'], 'headcount' => ['تعدادپرسنل', 'پرسنل', 'تعداد'],
    'paid' => ['مبلغواریزی'], 'paid_j' => ['تاریخوصول', 'تاریخواریز'], 'place' => ['محلواریز'], 'doc' => ['اسنادواریزی', 'سندواریزی'], 'cheque' => ['شمارهچک'],
    'remit' => ['مبلغواریزبهپاسارگاد', 'مبلغواریزبهبیمهگر'],
];
function hl_ledger_header_map(array $row) {
    $map = []; $taken = [];
    $norm = array_map(function ($h) { return preg_replace('/[^\p{L}\p{N}]/u', '', hl_norm($h)); }, $row);
    // اول عنوان‌های دقیق، بعد پیشوندها (مثلاً «نامه» و «تاریخ» تنها در شیتِ عمر)
    foreach ([true, false] as $exact) foreach (HL_LEDGER_COLS as $key => $alts) {
        if (isset($map[$key])) continue;
        foreach ($norm as $i => $h) {
            if ($h === '' || isset($taken[$i])) continue;
            $ok = false;
            foreach ($alts as $a) if ($exact ? $h === $a : strpos($h, $a) === 0) { $ok = true; break; }
            if ($ok) { $map[$key] = $i; $taken[$i] = 1; break; }
        }
    }
    // «مبلغ واریزی»ِ دوم (بعد از شماره‌ی چک) = واریز به بیمه‌گر
    if (!isset($map['remit']) && isset($map['paid'])) foreach ($norm as $i => $h) if ($i > $map['paid'] && strpos($h, 'مبلغواریز') === 0 && !isset($taken[$i])) { $map['remit'] = $i; $taken[$i] = 1; break; }
    // «تاریخ» تنها بعد از «نامه» = تاریخِ نامه
    if (!isset($map['letter_j']) && isset($map['letter_no'])) foreach ($norm as $i => $h) if ($i === $map['letter_no'] + 1 && $h === 'تاریخ' && !isset($taken[$i])) { $map['letter_j'] = $i; $taken[$i] = 1; }
    return $map;
}
function hl_ledger_parse($pdo, $path, $ext = null) {
    $sheets = xlsx_read_sheets($path, $ext);
    if (!$sheets) return ['error' => 'فایل خوانده نشد. فایلِ xlsx یا csv بدهید.'];
    $blocks = []; $warn = [];
    foreach ($sheets as $sname => $rows) {
        $hi = null; $map = [];
        foreach (array_slice($rows, 0, 15, true) as $i => $r) { $m = hl_ledger_header_map($r); if (isset($m['month']) && (isset($m['amount']) || isset($m['paid']))) { $hi = $i; $map = $m; break; } }
        if ($hi === null) continue;
        $ci = null; $prevM = 0; $y = 0; $sm = 0;
        $g = function ($r, $k) use ($map) { return isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : ''; };
        foreach (array_slice($rows, $hi + 1) as $ri => $r) {
            $co = $g($r, 'company'); $pno = $g($r, 'policy_no');
            if ($co !== '' || $pno !== '') {
                [$sj] = hl_jdate_loose($g($r, 'start_j')); [$ij] = hl_jdate_loose($g($r, 'issue_j'));
                $line = $g($r, 'line') ?: (mb_strpos($sname, 'عمر') !== false ? 'عمر' : 'درمان');
                $blocks[] = ['sheet' => $sname, 'company' => $co ?: ($ci !== null ? $blocks[$ci]['company'] : ''), 'line' => trim($line), 'plan' => $g($r, 'plan'), 'policy_no' => p2e_digits($pno), 'issue_j' => $ij, 'start_j' => $sj,
                        'premium' => hl_num($g($r, 'premium')), 'unit' => hl_num($g($r, 'unit')), 'months' => []];
                $ci = count($blocks) - 1;
                $prevM = 0; $y = $sj ? intval(substr($sj, 0, 4)) : 0;
                $sm = $sj ? intval(substr($sj, 5, 2)) : 0;
            }
            if ($ci === null) continue;
            $mi = hl_month_index($g($r, 'month'));
            if (!$mi) continue;
            $cont = $mi === $prevM;   // ادامه‌ی همان ماه (واریزِ دوم و …)
            if (!$cont) {
                if (!$y) { [$lj0] = hl_jdate_loose($g($r, 'letter_j')); $y = $lj0 ? intval(substr($lj0, 0, 4)) - ($mi > intval(substr($lj0, 5, 2)) ? 1 : 0) : intval(substr(hl_today_j(), 0, 4)); }
                elseif ($prevM && $mi < $prevM) $y++;
                elseif (!$prevM && $sm && $mi < $sm) $y++;
                $prevM = $mi;
            }
            $period = sprintf('%04d/%02d', $y, $mi);
            $bad = [];
            $dt = function ($k) use ($g, $r, &$bad) { $v = $g($r, $k); if ($v === '') return null; [$j] = hl_jdate_loose($v); if (!$j) $bad[] = $v; return $j; };
            $row = ['period_j' => $period, 'cont' => $cont, 'letter_no' => p2e_digits($g($r, 'letter_no')), 'letter_j' => $dt('letter_j'), 'amount' => hl_num($g($r, 'amount')), 'headcount' => intval(hl_num($g($r, 'headcount'))),
                    'paid' => hl_num($g($r, 'paid')), 'paid_j' => $dt('paid_j'), 'place' => $g($r, 'place'), 'doc' => $g($r, 'doc'), 'cheque' => p2e_digits($g($r, 'cheque')),
                    'remit' => hl_num($g($r, 'remit')), 'handover_j' => $dt('handover_j'), 'remit_j' => $dt('remit_j'), 'line' => $hi + $ri + 2];
            if ($bad) $warn[] = 'شیتِ «' . $sname . '»، ' . hl_period_fa($period) . ' (' . $blocks[$ci]['company'] . '): تاریخِ ناخوانا «' . implode('، ', $bad) . '»';
            if (!$row['amount'] && !$row['paid'] && !$row['remit'] && $row['letter_no'] === '') continue;
            // وصولِ بدونِ تاریخ/محل (مثلاً دو ماه با یک چک): همان تاریخ و محلِ ماهِ قبل
            $pv = $blocks[$ci]['months'] ? end($blocks[$ci]['months']) : null;
            if ($row['paid'] > 0 && $pv && $pv['paid'] > 0) {
                if (!$row['paid_j'] && $pv['paid_j']) { $row['paid_j'] = $pv['paid_j']; $warn[] = $blocks[$ci]['company'] . ' - ' . hl_period_fa($period) . ': تاریخِ وصول خالی بود؛ تاریخِ ماهِ قبل (' . hl_fa($pv['paid_j']) . ') گذاشته شد.'; }
                if ($row['place'] === '' && $pv['place'] !== '') { $row['place'] = $pv['place']; if ($row['doc'] === '') $row['doc'] = $pv['doc']; }
            }
            $blocks[$ci]['months'][] = $row;
        }
    }
    $blocks = array_values(array_filter($blocks, function ($b) { return $b['months'] || $b['policy_no'] !== ''; }));
    // چند بلوک با یک شماره‌ی بیمه‌نامه و بدونِ نامِ طرح => «طرح ۱»، «طرح ۲» به ترتیب (همان کدِ گروهِ بردروی بیمه‌گر)
    $byPol = [];
    foreach ($blocks as $k => $b) if ($b['policy_no'] !== '') $byPol[preg_replace('/\s+/', '', $b['policy_no'])][] = $k;
    foreach ($byPol as $ks) if (count($ks) > 1) foreach ($ks as $n => $k) if ($blocks[$k]['plan'] === '') $blocks[$k]['plan'] = 'طرح ' . ($n + 1);
    if (!$blocks) return ['error' => 'هیچ بلوکِ قراردادی پیدا نشد. سرستون‌ها باید مثلِ دفترِ ماهانه باشند (بیمه گذار، شماره بیمه نامه، ماه، مبلغ صورتحساب، …).'];
    return ['blocks' => $blocks, 'warnings' => $warn];
}
// پیدا کردنِ قراردادِ موجود برای یک بلوک: شماره‌ی بیمه‌نامه + (طرح یا سرانه)
function hl_match_contract($pdo, array $b) {
    if ($b['policy_no'] === '') return null;
    $st = $pdo->prepare("SELECT * FROM hl_contracts WHERE is_deleted = 0 AND REPLACE(policy_no, ' ', '') = ?");
    $st->execute([preg_replace('/\s+/', '', $b['policy_no'])]);
    $all = $st->fetchAll();
    if (!$all) return null;
    if (count($all) === 1 && (!$b['unit'] || !intval($all[0]['unit_premium']) || intval($all[0]['unit_premium']) === $b['unit'])) return $all[0];
    foreach ($all as $c) if ($b['plan'] !== '' && $c['plan'] && hl_plan_key($c['plan']) === hl_plan_key($b['plan'])) return $c;
    foreach ($all as $c) if ($b['unit'] && intval($c['unit_premium']) === $b['unit']) return $c;
    return null;
}
function hl_ledger_preview($pdo, array $parsed) {
    $out = [];
    foreach ($parsed['blocks'] as $k => $b) {
        $c = hl_match_contract($pdo, $b);
        $exist = [];
        if ($c) { $st = $pdo->prepare("SELECT period_j FROM hl_installments WHERE contract_id = ? AND period_j IS NOT NULL"); $st->execute([$c['id']]); $exist = array_flip($st->fetchAll(PDO::FETCH_COLUMN)); }
        $nb = 0; $np = 0; $ns = 0; $sb = 0;
        foreach ($b['months'] as $m) { if (!$m['cont'] && ($m['amount'] || $m['letter_no'] !== '')) { if (isset($exist[$m['period_j']])) $sb++; else $nb++; } if ($m['paid'] > 0) $np++; if ($m['remit'] > 0) $ns++; }
        $out[] = ['i' => $k, 'sheet' => $b['sheet'], 'company' => $b['company'], 'line' => $b['line'], 'plan' => $b['plan'], 'policy_no' => $b['policy_no'], 'unit' => $b['unit'], 'start_j' => $b['start_j'],
                  'months' => count($b['months']), 'first' => $b['months'] ? hl_period_fa($b['months'][0]['period_j']) : '', 'last' => $b['months'] ? hl_period_fa(end($b['months'])['period_j']) : '',
                  'match' => $c ? ['id' => intval($c['id']), 'label' => $c['company_name'] . ' - ' . $c['policy_no'] . ($c['plan'] ? ' (' . $c['plan'] . ')' : '')] : null,
                  'new_bills' => $nb, 'existing_bills' => $sb, 'payments' => $np, 'remits' => $ns,
                  'billed' => array_sum(array_column($b['months'], 'amount')), 'paid' => array_sum(array_column($b['months'], 'paid')), 'remitted' => array_sum(array_column($b['months'], 'remit'))];
    }
    return $out;
}
// ثبتِ نهایی: قرارداد (یا به‌روزرسانی)، صورتحساب‌ها، وصول‌ها (با تخصیص به همان ماه) و واریزها به بیمه‌گر - تکراری‌ها رد می‌شوند
function hl_ledger_commit($pdo, array $parsed, array $pick, $uid) {
    $S = hl_settings($pdo);
    $res = ['contracts_new' => 0, 'contracts_upd' => 0, 'bills' => 0, 'bills_upd' => 0, 'payments' => 0, 'remits' => 0, 'skipped' => 0];
    foreach ($parsed['blocks'] as $k => $b) {
        if (isset($pick[$k]) && empty($pick[$k])) continue;
        $c = hl_match_contract($pdo, $b);
        $line = in_array($b['line'], $S['lines'], true) ? $b['line'] : (mb_strpos($b['line'], 'عمر') !== false ? 'عمر' : (mb_strpos($b['line'], 'حادثه') !== false ? 'حادثه' : ($b['line'] ?: 'درمان')));
        if (!$c) {
            $cid = null; $st = $pdo->prepare("SELECT id, name FROM companies"); $st->execute();
            foreach ($st->fetchAll() as $co) if (hl_norm($co['name']) === hl_norm($b['company'])) { $cid = intval($co['id']); break; }
            [$sj, $sg] = hl_jdate($b['start_j'] ?: ''); [$ij, $ig] = hl_jdate($b['issue_j'] ?: '');
            $ej = $sj ? (intval(substr($sj, 0, 4)) + 1) . substr($sj, 4) : null; [$ej, $eg] = hl_jdate($ej ?: '');
            $pdo->prepare("INSERT INTO hl_contracts (company_id, company_name, title, policy_no, insurer, line, plan, issue_j, issue_g, start_j, start_g, end_j, end_g, premium, unit_premium, billing, status, created_by, created_at)
                           VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'MONTHLY', 'ACTIVE', ?, NOW())")
                ->execute([$cid, mb_substr($b['company'] ?: 'بدون نام', 0, 190), $b['policy_no'] ?: null, $S['default_insurer'], $line, $b['plan'] ?: null, $ij, $ig, $sj, $sg, $ej, $eg, $b['premium'], $b['unit'], $uid]);
            $c = hl_contract_by_id($pdo, intval($pdo->lastInsertId()));
            $res['contracts_new']++;
        } else {
            $set = []; $v = [];
            if (!intval($c['unit_premium']) && $b['unit']) { $set[] = 'unit_premium = ?'; $v[] = $b['unit']; }
            if (!$c['plan'] && $b['plan'] !== '') { $set[] = 'plan = ?'; $v[] = $b['plan']; }
            if (!intval($c['premium']) && $b['premium']) { $set[] = 'premium = ?'; $v[] = $b['premium']; }
            if ($c['billing'] !== 'MONTHLY') $set[] = "billing = 'MONTHLY'";
            if ($set) { $v[] = $c['id']; $pdo->prepare("UPDATE hl_contracts SET " . implode(', ', $set) . " WHERE id = ?")->execute($v); $res['contracts_upd']++; $c = hl_contract_by_id($pdo, $c['id']); }
        }
        // ۱) صورتحساب‌ها
        $billId = [];
        $st = $pdo->prepare("SELECT id, period_j, letter_no, letter_j, headcount, amount FROM hl_installments WHERE contract_id = ? AND period_j IS NOT NULL ORDER BY id"); $st->execute([$c['id']]);
        foreach ($st->fetchAll() as $e) if (!isset($billId[$e['period_j']])) $billId[$e['period_j']] = $e;
        foreach ($b['months'] as $m) {
            if ($m['cont'] || (!$m['amount'] && $m['letter_no'] === '')) continue;
            if (isset($billId[$m['period_j']])) {
                $e = $billId[$m['period_j']];
                $set = []; $v = [];
                if (!$e['letter_no'] && $m['letter_no'] !== '') { $set[] = 'letter_no = ?'; $v[] = $m['letter_no']; }
                if (!$e['letter_j'] && $m['letter_j']) { [$lj, $lg] = hl_jdate($m['letter_j']); $set[] = 'letter_j = ?, letter_g = ?'; $v[] = $lj; $v[] = $lg; }
                if (!$e['headcount'] && $m['headcount']) { $set[] = 'headcount = ?'; $v[] = $m['headcount']; }
                if ($set) { $v[] = $e['id']; $pdo->prepare("UPDATE hl_installments SET " . implode(', ', $set) . " WHERE id = ?")->execute($v); $res['bills_upd']++; }
                continue;
            }
            $amt = $m['amount'] ?: ($m['headcount'] * intval($c['unit_premium']));
            if ($amt <= 0) continue;
            $id = hl_bill_save($pdo, $c, ['period_j' => $m['period_j'], 'letter_no' => $m['letter_no'], 'letter_j' => $m['letter_j'], 'headcount' => $m['headcount'], 'unit_premium' => $m['headcount'] ? intdiv($amt, max(1, $m['headcount'])) : $c['unit_premium'],
                                          'amount' => $amt, 'payee' => 'US']);
            $billId[$m['period_j']] = ['id' => $id];
            $res['bills']++;
        }
        // ۲) وصول‌ها
        foreach ($b['months'] as $m) {
            if ($m['paid'] <= 0) continue;
            $pj = $m['paid_j'] ?: ($m['letter_j'] ?: null);
            if (!$pj) { $f = hl_period_first_g(hl_period_next($m['period_j'])); $pj = $f ? hl_g2j($f) : hl_today_j(); }
            [$pj, $pg] = hl_jdate($pj);
            $payee = (mb_strpos(hl_norm($m['place']), hl_norm($S['insurer_label'])) !== false || mb_strpos(hl_norm($m['place']), 'پاسارگاد') !== false) ? 'INSURER' : 'US';
            $docN = hl_norm($m['doc']);
            $method = mb_strpos($docN, 'چک') !== false ? 'cheque' : 'transfer';
            $dup = $pdo->prepare("SELECT COUNT(*) FROM hl_payments WHERE contract_id = ? AND status = 'OK' AND pay_j = ? AND amount = ?"); $dup->execute([$c['id'], $pj, $m['paid']]);
            if (intval($dup->fetchColumn())) { $res['skipped']++; continue; }
            $pdo->prepare("INSERT INTO hl_payments (contract_id, pay_j, pay_g, amount, method, payee, doc_type, cheque_no, cheque_status, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$c['id'], $pj, $pg, $m['paid'], $method, $payee, mb_substr($m['doc'], 0, 40) ?: null, $m['cheque'] ?: null, $method === 'cheque' ? 'CLEARED' : null,
                           'از اکسلِ دفتر' . ($m['place'] !== '' ? ' - محل واریز: ' . mb_substr($m['place'], 0, 80) : ''), $uid]);
            $pid = intval($pdo->lastInsertId());
            hl_allocate($pdo, $pid, $c['id'], $m['paid'], $payee, [], intval($billId[$m['period_j']]['id'] ?? 0));
            $res['payments']++;
        }
        // ۳) واریز به بیمه‌گر
        foreach ($b['months'] as $m) {
            if ($m['remit'] <= 0) continue;
            $sj = $m['remit_j'] ?: ($m['handover_j'] ?: $m['paid_j']);
            if (!$sj) { $res['skipped']++; continue; }
            [$sj, $sg] = hl_jdate($sj); [$hj, $hg] = hl_jdate($m['handover_j'] ?: '');
            $dup = $pdo->prepare("SELECT COUNT(*) FROM hl_settlements WHERE contract_id = ? AND status = 'OK' AND settle_j = ? AND amount = ?"); $dup->execute([$c['id'], $sj, $m['remit']]);
            if (intval($dup->fetchColumn())) { $res['skipped']++; continue; }
            $pdo->prepare("INSERT INTO hl_settlements (settle_j, settle_g, amount, method, contract_id, installment_id, handover_j, handover_g, note, created_by, created_at) VALUES (?, ?, ?, 'transfer', ?, ?, ?, ?, 'از اکسلِ دفتر', ?, NOW())")
                ->execute([$sj, $sg, $m['remit'], $c['id'], intval($billId[$m['period_j']]['id'] ?? 0) ?: null, $hj, $hg, $uid]);
            $res['remits']++;
        }
    }
    return $res;
}
function hl_contract_by_id($pdo, $id) { $st = $pdo->prepare("SELECT * FROM hl_contracts WHERE id = ?"); $st->execute([intval($id)]); return $st->fetch(); }

// ---------------------------------------------------------------------
//  بردروی بیمه‌نامه (اکسلِ بیمه‌گر): ساخت/تکمیلِ قرارداد و طرح‌ها با حق‌بیمه‌ی سرانه
// ---------------------------------------------------------------------
function hl_bordereau_parse($path, $ext = null) {
    $sheets = xlsx_read_sheets($path, $ext);
    $out = [];
    foreach ($sheets as $rows) {
        if (count($rows) < 2) continue;
        $h = array_map(function ($x) { return preg_replace('/[^\p{L}\p{N}]/u', '', hl_norm($x)); }, $rows[0]);
        $ix = function ($names) use ($h) { foreach ((array)$names as $n) { $k = array_search($n, $h, true); if ($k !== false) return $k; } return null; };
        $pi = $ix('شمارهبیمهنامه');
        if ($pi === null) continue;
        $gi = $ix('کدگروهبیمهشدگان');
        foreach (array_slice($rows, 1) as $r) {
            $pno = trim(p2e_digits((string)($r[$pi] ?? '')));
            if ($pno === '') continue;
            $v = function ($names) use ($ix, $r) { $k = $ix($names); return $k === null ? '' : trim((string)($r[$k] ?? '')); };
            $prod = $v('ناممحصول');
            $line = mb_strpos(hl_norm($prod), 'عمر') !== false ? 'عمر' : (mb_strpos(hl_norm($prod), 'حادثه') !== false ? 'حادثه' : 'درمان');
            $plans = [];
            if ($gi !== null) for ($k = 0; $k < 40; $k++) {
                $base = $gi + $k * 5; $code = trim((string)($r[$base] ?? ''));
                if ($code === '') break;
                $plans[] = ['plan' => $code, 'unit' => hl_num($r[$base + 3] ?? 0), 'coverage' => trim((string)($r[$base + 4] ?? ''))];
            }
            [$sj] = hl_jdate_loose($v('تاریخشروع')); [$ej] = hl_jdate_loose($v('تاریخپایان')); [$ij] = hl_jdate_loose($v('تاریخصدور'));
            $out[] = ['policy_no' => $pno, 'prev_policy_no' => p2e_digits($v('شمارهبیمهنامهقبلی')), 'company' => $v(['بیمهگذارحقوقی', 'نامبیمهگذار', 'بیمهگذار']), 'holder_code' => p2e_digits($v('کدبیمهگذار')),
                      'issue_j' => $ij, 'start_j' => $sj, 'end_j' => $ej, 'line' => $line, 'product' => $prod, 'insured_count' => intval(hl_num($v('تعدادبیمهشدگاناعلامی'))),
                      'premium' => hl_num($v(['حقبیمهصادره', 'حقبیمهجاریخالص', 'حقبیمهپرداختی'])), 'agent' => $v('نامنمایندهپیشنهاددهنده'), 'plans' => $plans];
        }
    }
    return $out;
}
function hl_bordereau_commit($pdo, array $items, $uid) {
    $S = hl_settings($pdo); $n = ['new' => 0, 'upd' => 0];
    foreach ($items as $it) {
        $plans = $it['plans'] ?: [['plan' => '', 'unit' => 0, 'coverage' => '']];
        $cid = null; $st = $pdo->query("SELECT id, name FROM companies");
        $it['company'] = str_replace(['ي', 'ك'], ['ی', 'ک'], $it['company']);
        foreach ($st->fetchAll() as $co) if (hl_norm($co['name']) === hl_norm($it['company'])) { $cid = intval($co['id']); $it['company'] = $co['name']; break; }
        [$sj, $sg] = hl_jdate($it['start_j'] ?: ''); [$ej, $eg] = hl_jdate($it['end_j'] ?: ''); [$ij, $ig] = hl_jdate($it['issue_j'] ?: '');
        foreach ($plans as $k => $p) {
            $c = hl_match_contract($pdo, ['policy_no' => $it['policy_no'], 'plan' => $p['plan'], 'unit' => $p['unit']]);
            if (!$c && $k === 0) { // قراردادِ بدونِ طرحِ همین بیمه‌نامه را همان طرحِ اول می‌کنیم
                $st = $pdo->prepare("SELECT * FROM hl_contracts WHERE is_deleted = 0 AND REPLACE(policy_no,' ','') = ? AND (plan IS NULL OR plan = '') LIMIT 1"); $st->execute([preg_replace('/\s+/', '', $it['policy_no'])]); $c = $st->fetch() ?: null;
            }
            $f = ['company_id' => $cid, 'company_name' => mb_substr($it['company'] ?: 'بدون نام', 0, 190), 'policy_no' => $it['policy_no'], 'prev_policy_no' => $it['prev_policy_no'] ?: null, 'holder_code' => $it['holder_code'] ?: null,
                  'line' => $it['line'], 'plan' => $p['plan'] ?: null, 'issue_j' => $ij, 'issue_g' => $ig, 'start_j' => $sj, 'start_g' => $sg, 'end_j' => $ej, 'end_g' => $eg, 'unit_premium' => $p['unit'], 'coverage' => $p['coverage'] ?: null,
                  'billing' => 'MONTHLY'];
            if ($k === 0) { $f['premium'] = $it['premium']; $f['insured_count'] = $it['insured_count']; }
            if ($c) {
                $set = []; $v = [];
                unset($f['company_name']); if (!$cid) unset($f['company_id']);   // نامِ ثبت‌شده‌ی قرارداد عوض نمی‌شود
                foreach ($f as $a => $b) { if ($b === null || $b === '' || $b === 0) continue; $set[] = "$a = ?"; $v[] = $b; }
                $v[] = $uid; $v[] = $c['id'];
                $pdo->prepare("UPDATE hl_contracts SET " . implode(', ', $set) . ", updated_by = ?, updated_at = NOW() WHERE id = ?")->execute($v);
                $n['upd']++;
            } else {
                $f['insurer'] = $S['default_insurer']; $f['status'] = 'ACTIVE'; $f['title'] = $it['product'] ? mb_substr($it['product'], 0, 190) : null;
                if ($k > 0) $f['note'] = 'حق‌بیمه‌ی کلِ بیمه‌نامه روی طرحِ اول ثبت شده است.';
                $cols = array_keys($f);
                $pdo->prepare("INSERT INTO hl_contracts (" . implode(', ', $cols) . ", created_by, created_at) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", ?, NOW())")->execute(array_merge(array_values($f), [$uid]));
                $n['new']++;
            }
        }
    }
    return $n;
}

// ---------------------------------------------------------------------
//  بیمه‌شدگان (اکسلِ «لیست بیمه‌شدگان»)
// ---------------------------------------------------------------------
const HL_MEMBER_COLS = [
    'comp_code' => ['کدرایانه'], 'first_name' => ['نام'], 'last_name' => ['نامخانوادگی'], 'birth_j' => ['تاریختولد'], 'ssn_no' => ['شمارهشناسنامه'], 'nid' => ['کدملی'],
    'father' => ['نامپدر'], 'nationality' => ['ملیت'], 'mobile' => ['شمارهتماس', 'موبایل', 'تلفنهمراه'], 'coop_no' => ['شمارههمکاری', 'کدپرسنلی'], 'relation' => ['کدنسبت', 'نسبت'],
    'gender' => ['جنسیت'], 'status' => ['وضعیت'], 'dependency' => ['وضعیتتکفل'], 'main_name' => ['بیمهشدهاصلی'], 'main_nid' => ['کدملیبیمهشدهاصلی'],
    'start_j' => ['تاریخشروعپوشش'], 'end_j' => ['تاریخپایانپوشش'], 'premium' => ['حقبیمه'], 'monthly' => ['حقبیمهماهانه'], 'plan' => ['کدگروهبیمهشدگان'],
    'policy_no' => ['شمارهقرارداد', 'شمارهبیمهنامه'], 'sheba' => ['شمارهشبا'], 'bank' => ['بانک'],
];
function hl_members_parse($path, $ext = null) {
    foreach (xlsx_read_sheets($path, $ext) as $rows) {
        foreach (array_slice($rows, 0, 10, true) as $hi => $r) {
            $h = array_map(function ($x) { return preg_replace('/[^\p{L}\p{N}]/u', '', hl_norm($x)); }, $r);
            if (!in_array('کدملی', $h, true)) continue;
            $map = [];
            foreach (HL_MEMBER_COLS as $k => $alts) foreach ($alts as $a) { $i = array_search($a, $h, true); if ($i !== false && !in_array($i, $map, true)) { $map[$k] = $i; break; } }
            $out = [];
            foreach (array_slice($rows, $hi + 1) as $row) {
                $m = [];
                foreach ($map as $k => $i) $m[$k] = trim(p2e_digits((string)($row[$i] ?? '')));
                foreach (['first_name', 'last_name', 'father', 'main_name', 'relation', 'gender', 'status', 'dependency', 'nationality'] as $k) if (isset($m[$k])) $m[$k] = str_replace(['ي', 'ك'], ['ی', 'ک'], $m[$k]);
                if (($m['nid'] ?? '') === '' && ($m['comp_code'] ?? '') === '') continue;
                if (($m['mobile'] ?? '') === '-') $m['mobile'] = '';
                $m['premium'] = isset($m['premium']) ? hl_num($m['premium']) : null; $m['monthly'] = isset($m['monthly']) ? hl_num($m['monthly']) : null;
                $out[] = $m;
            }
            return $out;
        }
    }
    return [];
}
// $contractId: قراردادِ انتخاب‌شده؛ ردیف‌ها بر اساسِ «کد گروه بیمه‌شدگان» به طرحِ هم‌نامِ همان بیمه‌نامه می‌روند
function hl_members_commit($pdo, array $c, array $rows) {
    $st = $pdo->prepare("SELECT id, plan FROM hl_contracts WHERE is_deleted = 0 AND REPLACE(policy_no,' ','') = ? AND policy_no <> ''"); $st->execute([preg_replace('/\s+/', '', (string)$c['policy_no'])]);
    $plans = []; foreach ($st->fetchAll() as $x) if ($x['plan']) $plans[hl_plan_key($x['plan'])] = intval($x['id']);
    $sibs = array_values(array_unique(array_merge([intval($c['id'])], array_values($plans))));
    $find = $pdo->prepare("SELECT id, contract_id FROM hl_members WHERE contract_id IN (" . implode(',', $sibs) . ") AND mkey = ? LIMIT 1");
    $cols = ['comp_code', 'first_name', 'last_name', 'birth_j', 'ssn_no', 'nid', 'father', 'nationality', 'mobile', 'coop_no', 'relation', 'gender', 'status', 'dependency', 'main_name', 'main_nid', 'start_j', 'start_g', 'end_j', 'end_g', 'premium', 'monthly', 'plan', 'sheba', 'bank'];
    $n = ['new' => 0, 'upd' => 0, 'by_contract' => []]; $seen = [];
    foreach ($rows as $m) {
        $key = ($m['nid'] ?? '') !== '' ? $m['nid'] : 'c' . ($m['comp_code'] ?? '');
        $target = intval($c['id']);
        if (!empty($m['plan']) && isset($plans[hl_plan_key($m['plan'])])) $target = $plans[hl_plan_key($m['plan'])];
        [$sj, $sg] = hl_jdate_loose($m['start_j'] ?? ''); [$ej, $eg] = hl_jdate_loose($m['end_j'] ?? ''); [$bj] = hl_jdate_loose($m['birth_j'] ?? '');
        $m['start_j'] = $sj; $m['start_g'] = $sg; $m['end_j'] = $ej; $m['end_g'] = $eg; $m['birth_j'] = $bj;
        $vals = []; foreach ($cols as $k) { $v = $m[$k] ?? null; $vals[] = ($v === '' ? null : (is_string($v) ? mb_substr($v, 0, 190) : $v)); }
        $find->execute([$key]); $ex = $find->fetch();
        if ($ex) {
            $pdo->prepare("UPDATE hl_members SET contract_id = ?, " . implode(', ', array_map(function ($k) { return "$k = ?"; }, $cols)) . ", updated_at = NOW() WHERE id = ?")->execute(array_merge([$target], $vals, [$ex['id']]));
            $n['upd']++;
        } else {
            $pdo->prepare("INSERT INTO hl_members (contract_id, mkey, " . implode(', ', $cols) . ", created_at, updated_at) VALUES (?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ", NOW(), NOW())")->execute(array_merge([$target, $key], $vals));
            $n['new']++;
        }
        $n['by_contract'][$target] = ($n['by_contract'][$target] ?? 0) + 1;
        $seen[$key] = 1;
    }
    // بیمه‌شدگانی که در این فایل نیستند (فقط گزارش)
    $st = $pdo->query("SELECT mkey, first_name, last_name FROM hl_members WHERE contract_id IN (" . implode(',', $sibs) . ")");
    $n['missing'] = [];
    foreach ($st->fetchAll() as $x) if (!isset($seen[$x['mkey']])) $n['missing'][] = trim($x['first_name'] . ' ' . $x['last_name']);
    // تعدادِ بیمه‌شده‌ی قراردادها
    foreach ($sibs as $sid) $pdo->prepare("UPDATE hl_contracts SET insured_count = (SELECT COUNT(*) FROM hl_members WHERE contract_id = ? AND (end_g IS NULL OR end_g > CURDATE())) WHERE id = ? AND (SELECT COUNT(*) FROM hl_members WHERE contract_id = ?) > 0")->execute([$sid, $sid, $sid]);
    return $n;
}

// ---------------------------------------------------------------------
//  صندوقِ رسیدها (عکس‌هایی که در گروه‌های تعیین‌شده برای ربات فرستاده می‌شوند)
// ---------------------------------------------------------------------
function hl_is_receipt_group($pdo, $chatId) {
    foreach ((array)hl_settings($pdo)['receipt_groups'] as $g) if ((string)$g['chat_id'] === (string)$chatId) return $g;
    return null;
}
// فایلِ ذخیره‌شده‌ی ربات را با نامِ «تاریخِ دریافت» به پوشه‌ی موقت می‌برد و ردیفِ صندوق را می‌سازد
function hl_inbox_add($pdo, $tmpAbs, array $meta) {
    hl_ensure($pdo);
    $ext = strtolower(pathinfo($tmpAbs, PATHINFO_EXTENSION)) ?: 'jpg';
    [$y, $m, $d] = jalali_from_gregorian_ts(time());
    $base = sprintf('رسید %04d-%02d-%02d ساعت %s', $y, $m, $d, date('H-i-s')) . (!empty($meta['chat_title']) ? ' - ' . hl_clean_name(mb_substr($meta['chat_title'], 0, 50)) : '');
    $dest = hl_unique_path(hl_inbox_dir(), $base, $ext);
    if (!@rename($tmpAbs, $dest)) { if (!@copy($tmpAbs, $dest)) return 0; @unlink($tmpAbs); }
    $pdo->prepare("INSERT INTO hl_inbox (source, chat_id, chat_title, sender, sender_id, message_id, caption, file_path, received_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'NEW')")
        ->execute([$meta['source'] ?? 'BOT', $meta['chat_id'] ?? null, mb_substr((string)($meta['chat_title'] ?? ''), 0, 190) ?: null, mb_substr((string)($meta['sender'] ?? ''), 0, 190) ?: null,
                   $meta['sender_id'] ?? null, $meta['message_id'] ?? null, mb_substr((string)($meta['caption'] ?? ''), 0, 2000) ?: null, hl_rel($dest)]);
    return intval($pdo->lastInsertId());
}
// اعلانِ پنل برای کاربرانی که به «رسیدهای دریافتی» دسترسی دارند
function hl_staff_events($pdo, $since, $until, $userId) {
    $out = [];
    try {
        require_once __DIR__ . '/_perm.php';
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?"); $st->execute([$userId]); $role = $st->fetchColumn();
        if ($role !== 'ADMIN') { $p = perm_user_custom($pdo, $userId); if ($p === null || !perm_allows($p, 'hl-receipts:view')) return []; }
        if (!intval(hl_settings($pdo)['receipt_notify'])) return [];
        $st = $pdo->prepare("SELECT COUNT(*) n, MAX(received_at) at, MAX(chat_title) t FROM hl_inbox WHERE status = 'NEW' AND received_at > ? AND received_at <= ?");
        $st->execute([$since, $until]);
        $r = $st->fetch();
        if ($r && intval($r['n'])) $out[] = ['type' => 'hl_receipt', 'title' => hl_fa($r['n']) . ' رسیدِ پرداختِ تازه برای درمان تکمیلی', 'body' => 'از گروهِ «' . ($r['t'] ?: 'ربات') . '» - برای ثبتِ اطلاعاتِ پرداخت باز کنید.', 'at' => $r['at'], 'tab' => 'hl-receipts'];
    } catch (Throwable $e) {}
    return $out;
}
// ثبتِ یک دریافت (مشترک بینِ فرمِ دریافت و صندوقِ رسیدها)؛ $fileAbs: فایلی که باید به پوشه‌ی قرارداد برود
function hl_payment_create($pdo, array $c, array $d, $fileAbs, $uid, array $S) {
    [$pj, $pg] = hl_jdate($d['pay_j'] ?? '');
    if (!$pj) throw new RuntimeException('تاریخِ پرداخت را درست وارد کنید (مثلاً ۱۴۰۵/۰۷/۱۵).');
    $amt = money_to_int($d['amount'] ?? 0);
    if ($amt <= 0) throw new RuntimeException('مبلغ را وارد کنید.');
    $method = array_key_exists($d['method'] ?? '', $S['methods']) ? $d['method'] : 'transfer';
    $payee = ($d['payee'] ?? '') === 'INSURER' ? 'INSURER' : 'US';
    [$cdj, $cdg] = hl_jdate($d['cheque_due_j'] ?? '');
    $t = function ($k, $n) use ($d) { $v = trim(p2e_digits((string)($d[$k] ?? ''))); return $v === '' ? null : mb_substr($v, 0, $n); };
    $billId = intval($d['bill_id'] ?? 0);
    $bill = null;
    if ($billId) { $st = $pdo->prepare("SELECT * FROM hl_installments WHERE id = ? AND contract_id = ?"); $st->execute([$billId, $c['id']]); $bill = $st->fetch() ?: null; if (!$bill) $billId = 0; }
    $rel = null;
    if ($fileAbs && is_file($fileAbs)) {
        $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION)) ?: 'jpg';
        $label = $bill ? ($bill['period_j'] ? hl_period_fa($bill['period_j']) : 'قسط ' . hl_fa($bill['seq'])) : '';
        $base = 'رسید ' . hl_clean_name($c['company_name']) . ($label !== '' ? ' - ' . $label : '') . ' - پرداخت ' . str_replace('/', '-', $pj) . ' - ' . number_format($amt) . ' ریال';
        $dest = hl_unique_path(hl_contract_dir($c, 'رسیدهای دریافتی'), p2e_digits($base), $ext);
        if (!@rename($fileAbs, $dest)) { if (!@copy($fileAbs, $dest)) throw new RuntimeException('انتقالِ فایلِ رسید ناموفق بود.'); @unlink($fileAbs); }
        $rel = hl_rel($dest);
    }
    $pdo->prepare("INSERT INTO hl_payments (contract_id, pay_j, pay_g, amount, method, payee, ref_no, bank, cheque_no, cheque_due_j, cheque_due_g, cheque_status, file_path, note, doc_type, inbox_id, created_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$c['id'], $pj, $pg, $amt, $method, $payee, $t('ref_no', 80), $t('bank', 80), $method === 'cheque' ? $t('cheque_no', 40) : null, $method === 'cheque' ? $cdj : null, $method === 'cheque' ? $cdg : null,
                   $method === 'cheque' ? 'PENDING' : null, $rel, $t('note', 500), $t('doc_type', 40), intval($d['inbox_id'] ?? 0) ?: null, $uid]);
    $pid = intval($pdo->lastInsertId());
    $manual = is_array($d['alloc'] ?? null) ? $d['alloc'] : (is_string($d['alloc'] ?? null) ? (json_decode($d['alloc'], true) ?: []) : []);
    $left = hl_allocate($pdo, $pid, $c['id'], $amt, $payee, $manual, $billId);
    return ['id' => $pid, 'unallocated' => $left, 'file' => $rel];
}
