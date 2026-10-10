<?php
// فایل: api/_life.php
// «بیمه عمر»: بیمه‌نامه‌ها و اقساط، ورودِ اکسلِ بیمه‌گر (گزارشِ اقساطِ وصول‌نشده) با پیش‌نمایش و مغایرت‌گیری،
// پرداخت‌ها و رسید، یادداشتِ تماس، تلفن‌ها، بایگانی، داشبورد و خروجیِ اکسل.
// همه‌ی جدول‌ها خودکار ساخته می‌شوند (نیازی به مایگریشن نیست).
//
// مفهوم‌ها:
//  - هر قسط: مبلغ، سررسید، «وصول طبقِ اکسلِ بیمه‌گر» (excel_collected) و «پرداخت‌های ثبت‌شده‌ی ما» (paid_amount).
//    پرداختِ مؤثر = بزرگ‌ترینِ این دو (هر دو یک پول را نشان می‌دهند). cleared=1 یعنی «تسویه نزدِ بیمه‌گر»:
//    قسطی که در بازه‌ی گزارشِ تازه بوده ولی دیگر در فهرستِ وصول‌نشده‌ها نیامده (یعنی پرداخت شده).
//  - وضعیت: PAID (پرداخت‌شده)، PARTIAL (بخشی)، OVERDUE (معوق: سررسید گذشته و پرداخت‌نشده)، DUE (سررسید نشده).

require_once __DIR__ . '/_case_helpers.php';

const LIFE_PAY_METHODS = ['کارت به کارت', 'واریز به حساب', 'درگاه / پرداخت اینترنتی', 'نقدی', 'چک', 'سایر'];
const LIFE_CALL_RESULTS = ['پاسخ داد', 'پاسخ نداد', 'خاموش / در دسترس نبود', 'قولِ پرداخت داد', 'پرداخت کرد', 'شماره اشتباه', 'تماس بعداً', 'انصراف / عدم تمایل', 'سایر'];
// PAID: پرداخت به بیمه‌گر هم انجام شده (طبقِ اکسلِ بیمه‌گر، «تسویه نزدِ بیمه‌گر»، یا پرداختِ نهایی وقتی مرحله‌ی بیمه‌گر خاموش است)
// PAID_US: بیمه‌گذار به ما پرداخت کرده و نوبتِ پرداخت به بیمه‌گر است — دیگر معوق نیست
const LIFE_STATUS_FA = ['PAID' => 'پرداخت‌شده', 'PAID_US' => 'پرداخت‌شده به ما · منتظرِ پرداخت به بیمه‌گر', 'PARTIAL' => 'بخشی پرداخت‌شده', 'OVERDUE' => 'معوق', 'DUE' => 'سررسید نشده'];
// تعدادِ قسط در سال بر اساسِ روشِ پرداخت (برای برآوردِ حق‌بیمه‌ی سالانه)
const LIFE_PER_YEAR = ['ماهانه' => 12, 'دوماهه' => 6, 'دو ماهه' => 6, 'سه ماهه' => 4, 'سه‌ماهه' => 4, 'چهار ماهه' => 3, 'شش ماهه' => 2, 'شش‌ماهه' => 2, 'سالانه' => 1, 'یکجا' => 1];

// ستون‌های اکسل: کلید => [عنوانِ فارسی، نام‌های سرستون (هر کدام پیدا شد)، اجباری؟]
function life_default_colmap() {
    return [
        'policy_no' => ['شماره بیمه‌نامه', ['شماره بیمه نامه', 'شماره بیمه‌نامه', 'شماره بیمهنامه'], true],
        'holder_name' => ['بیمه‌گذار', ['بیمه گزار', 'بیمه گذار', 'بیمه‌گذار', 'نام بیمه گزار'], true],
        'holder_nid' => ['کد ملی بیمه‌گذار', ['کد ملی بیمه گزار', 'کد ملی بیمه گذار', 'کدملی بیمه گزار'], false],
        'holder_mobile' => ['موبایل بیمه‌گذار', ['موبایل بیمه گزار', 'موبایل بیمه گذار', 'تلفن همراه', 'موبایل'], false],
        'insured_name' => ['بیمه‌شده‌ی اصلی', ['بیمه شده اصلی', 'بیمه‌شده اصلی', 'بیمه شده'], false],
        'insured_nid' => ['کد ملی بیمه‌شده', ['کد ملی بیمه شده اصلی', 'کد ملی بیمه شده'], false],
        'agent' => ['نماینده', ['نماینده'], false],
        'branch' => ['شعبه', ['شعبه'], false],
        'contract_name' => ['قرارداد', ['قرارداد'], false],
        'variant' => ['واریانت / طرح', ['واریانت', 'طرح'], false],
        'issue' => ['تاریخ صدور', ['تاریخ صدور'], false],
        'start' => ['تاریخ شروع', ['تاریخ شروع'], false],
        'end' => ['تاریخ پایان', ['تاریخ پایان', 'تاریخ انقضا'], false],
        'field_name' => ['رشته', ['رشته', 'نوع بیمه'], false],
        'duration' => ['مدت بیمه‌نامه (سال)', ['مدت بیمه نامه', 'مدت بیمه‌نامه', 'مدت'], false],
        'policy_year' => ['سال بیمه‌ای', ['سال بیمه ای', 'سال بیمه‌ای'], false],
        'pay_method' => ['روش پرداخت', ['روش پرداخت حق بیمه', 'روش پرداخت', 'نحوه پرداخت'], false],
        'policy_status' => ['وضعیت بیمه‌نامه', ['وضعیت بیمه نامه', 'وضعیت'], false],
        'channel' => ['کانال فروش', ['کانال فروش'], false],
        'pay_id' => ['شناسه واریز', ['شناسه واریز', 'شناسه پرداخت'], false],
        'internal_no' => ['شماره داخلی قسط', ['شماره داخلی قسط', 'کد قسط'], false],
        'inst_no' => ['شماره قسط', ['شماره قسط', 'قسط'], true],
        'due' => ['تاریخ سررسید', ['تاریخ سررسید', 'سررسید'], true],
        'amount' => ['مبلغ قسط', ['مبلغ قسط', 'مبلغ'], true],
        'collected' => ['مبلغ وصول‌شده', ['مبلغ وصول شده', 'مبلغ وصول‌شده', 'وصول شده'], false],
        'debt' => ['مبلغ بدهی', ['مبلغ بدهی', 'بدهی', 'مانده'], false],
    ];
}
// ستون‌های اکسل با تغییراتِ تنظیمات (نام‌های سرستونِ هر کلید قابلِ ویرایش است)
function life_colmap($pdo) {
    $map = life_default_colmap();
    $saved = json_decode((string)life_setting($pdo, 'life_colmap', ''), true);
    if (is_array($saved)) foreach ($saved as $k => $names) {
        if (!isset($map[$k]) || !is_array($names)) continue;
        $names = array_values(array_filter(array_map(function ($s) { return trim((string)$s); }, $names), 'strlen'));
        if ($names) $map[$k][1] = $names;
    }
    return $map;
}
function life_setting($pdo, $key, $def = null) {
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $st->execute([$key]); $v = $st->fetchColumn(); return $v === false ? $def : $v; }
    catch (Throwable $e) { return $def; }
}
function life_setting_set($pdo, $key, $val) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, (string)$val]);
}

// نقشِ «کاربر بیمه عمر» (LIFE) در ستونِ ENUMِ users.role (مایگریشن ۰۱۸ فقط نقش‌های قبلی را دارد)
function life_role_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if (!$c || stripos($c['Type'], 'enum(') !== 0 || stripos($c['Type'], "'LIFE'") !== false) return;
        preg_match_all("/'((?:[^']|'')*)'/", $c['Type'], $m);
        $vals = array_unique(array_merge($m[1], ['LIFE']));
        $pdo->exec("ALTER TABLE users MODIFY role ENUM(" . implode(',', array_map(function ($v) use ($pdo) { return $pdo->quote($v); }, $vals)) . ") NOT NULL DEFAULT " . $pdo->quote($c['Default'] ?: 'OPERATOR'));
    } catch (Throwable $e) { error_log('[life_role_ensure] ' . $e->getMessage()); }
}

// ---------------------------------------------------------------------
//  جدول‌ها
// ---------------------------------------------------------------------
function life_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS life_policies (id INT AUTO_INCREMENT PRIMARY KEY, policy_no VARCHAR(60) NOT NULL, holder_name VARCHAR(190) NULL, holder_nid VARCHAR(20) NULL,
            holder_mobile VARCHAR(30) NULL, phones VARCHAR(400) NULL, insured_name VARCHAR(190) NULL, insured_nid VARCHAR(20) NULL, agent VARCHAR(190) NULL, branch VARCHAR(190) NULL,
            contract_name VARCHAR(190) NULL, variant VARCHAR(190) NULL, field_name VARCHAR(120) NULL, issue_j CHAR(10) NULL, issue_g DATE NULL, start_j CHAR(10) NULL, end_j CHAR(10) NULL,
            duration INT NULL, policy_year INT NULL, pay_method VARCHAR(40) NULL, policy_status VARCHAR(80) NULL, channel VARCHAR(120) NULL, pay_id VARCHAR(40) NULL,
            extra_json MEDIUMTEXT NULL, note TEXT NULL, archive_dir VARCHAR(600) NULL, import_id INT NULL, created_at DATETIME NULL, created_by INT NULL, updated_at DATETIME NULL,
            UNIQUE KEY uq_policy (policy_no), KEY k_nid (holder_nid), KEY k_issue (issue_g)) $o",
        "CREATE TABLE IF NOT EXISTS life_installments (id INT AUTO_INCREMENT PRIMARY KEY, policy_id INT NOT NULL, inst_no INT NOT NULL, internal_no VARCHAR(30) NULL, pay_id VARCHAR(40) NULL,
            due_j CHAR(10) NULL, due_g DATE NULL, amount BIGINT NOT NULL DEFAULT 0, excel_collected BIGINT NOT NULL DEFAULT 0, excel_debt BIGINT NULL, paid_amount BIGINT NOT NULL DEFAULT 0,
            cleared TINYINT NOT NULL DEFAULT 0, cleared_note VARCHAR(190) NULL, last_import INT NULL, note TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uq_inst (policy_id, inst_no), KEY k_due (due_g), KEY k_internal (internal_no)) $o",
        "CREATE TABLE IF NOT EXISTS life_payments (id INT AUTO_INCREMENT PRIMARY KEY, installment_id INT NOT NULL, policy_id INT NOT NULL, amount BIGINT NOT NULL, paid_j CHAR(10) NULL,
            paid_g DATE NULL, method VARCHAR(60) NULL, ref_no VARCHAR(80) NULL, note VARCHAR(500) NULL, file_path VARCHAR(600) NULL, created_by INT NULL, created_at DATETIME NULL,
            voided_at DATETIME NULL, voided_by INT NULL, KEY k_inst (installment_id), KEY k_pol (policy_id)) $o",
        "CREATE TABLE IF NOT EXISTS life_notes (id INT AUTO_INCREMENT PRIMARY KEY, policy_id INT NOT NULL, installment_id INT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'call', at DATETIME NOT NULL,
            phone VARCHAR(30) NULL, result VARCHAR(60) NULL, next_j CHAR(10) NULL, next_g DATE NULL, text TEXT NULL, created_by INT NULL, KEY k_pol (policy_id), KEY k_next (next_g)) $o",
        "CREATE TABLE IF NOT EXISTS life_imports (id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(255) NULL, created_by INT NULL, created_at DATETIME NULL, range_from CHAR(10) NULL,
            range_to CHAR(10) NULL, stats_json TEXT NULL) $o",
        // چه کسانی این بیمه‌نامه را پیگیری می‌کنند (auto=1: خودکار با ثبتِ تماس/پرداخت؛ 0: تعیینِ دستی)
        "CREATE TABLE IF NOT EXISTS life_followers (policy_id INT NOT NULL, user_id INT NOT NULL, auto TINYINT NOT NULL DEFAULT 1, added_by INT NULL, added_at DATETIME NULL,
            PRIMARY KEY (policy_id, user_id), KEY k_user (user_id)) $o",
        "CREATE TABLE IF NOT EXISTS life_templates (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, file_path VARCHAR(600) NULL, footer_text TEXT NULL,
            is_default TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL, created_by INT NULL) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[life_ensure] ' . $e->getMessage()); } }
    // پرداختِ «نهایی» (وقتی مرحله‌ی پرداخت به بیمه‌گر خاموش است) و جمعِ آن روی قسط
    // kind: 'receipt' = قالبِ رسیدِ پرداخت، 'slip' = قالبِ Wordِ «فیشِ پرداخت» (پیش از پرداخت)
    foreach ([['life_payments', 'is_final', "TINYINT NOT NULL DEFAULT 0"], ['life_installments', 'paid_final', "BIGINT NOT NULL DEFAULT 0"], ['life_templates', 'kind', "VARCHAR(10) NOT NULL DEFAULT 'receipt'"]] as [$t, $c, $def]) {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM $t LIKE '$c'")->fetch();
            if (!$has) $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def");
        } catch (Throwable $e) { error_log('[life_ensure] ' . $e->getMessage()); }
    }
    // قالبِ پیش‌فرضِ داخلیِ رسید (بدونِ فایل) همیشه هست
    try {
        if (!intval($pdo->query("SELECT COUNT(*) FROM life_templates WHERE file_path IS NULL AND kind = 'receipt'")->fetchColumn())) {
            $pdo->prepare("INSERT INTO life_templates (name, file_path, footer_text, is_default, created_at) VALUES (?, NULL, ?, 1, NOW())")
                ->execute(['قالبِ پیش‌فرضِ سامانه', 'بدین‌وسیله دریافتِ مبلغِ فوق بابتِ قسطِ بیمه‌نامه‌ی عمرِ یادشده تأیید می‌گردد. این رسید صرفاً پس از تأییدِ واریز در حسابِ بیمه‌گر معتبر است.']);
        }
    } catch (Throwable $e) {}
}

// ---------------------------------------------------------------------
//  کمکی‌ها
// ---------------------------------------------------------------------
function life_root() { return dirname(__DIR__); }
function life_archive_root() { return archive_root(life_root()) . '/بایگانی بیمه عمر'; }
function life_rel($abs) { return ltrim(str_replace(life_root(), '', (string)$abs), '/'); }
function life_norm_h($h) {
    $h = mb_strtolower(trim((string)$h));
    $h = str_replace(['ي', 'ك', 'ـ', "\xE2\x80\x8C", "\xE2\x80\x8E", "\xE2\x80\x8F", 'ۀ', 'ة'], ['ی', 'ک', '', '', '', '', 'ه', 'ه'], $h);
    return preg_replace('/[\s\-_:\.\/]+/u', '', $h);
}
function life_clean($v) { return trim(preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$v))); }
function life_int($v) { $n = preg_replace('/[^\d\-]/', '', p2e_digits((string)$v)); return ($n === '' || $n === '-') ? 0 : intval($n); }
function life_nid($v) { $d = preg_replace('/\D/', '', p2e_digits((string)$v)); return $d === '' ? '' : (strlen($d) < 10 && strlen($d) >= 8 ? str_pad($d, 10, '0', STR_PAD_LEFT) : $d); }
function life_mobile($v) {
    $d = preg_replace('/\D/', '', p2e_digits((string)$v));
    if ($d === '') return '';
    if (strlen($d) === 12 && strpos($d, '98') === 0) $d = '0' . substr($d, 2);
    if (strlen($d) === 10 && $d[0] === '9') $d = '0' . $d;
    return $d;
}
// تاریخِ شمسی (۱۴۰۵/۰۳/۰۴ یا سریالِ اکسل نیست چون متن است) => [«1405/03/04»، «2026-05-25»]
function life_jdate($v) {
    $s = p2e_digits(trim((string)$v));
    if (!preg_match('/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $s, $m)) return [null, null];
    $j = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]));
    return [$j, $ts ? date('Y-m-d', $ts) : null];
}
function life_g2j($g) {
    if (!$g) return '';
    [$jy, $jm, $jd] = jalali_from_gregorian_ts(strtotime($g . ' 12:00:00'));
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}
function life_today_j() { [$jy, $jm, $jd] = jalali_from_gregorian_ts(time()); return sprintf('%04d/%02d/%02d', $jy, $jm, $jd); }
function life_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function life_money($n) { return life_fa(number_format(intval($n))); }
function life_per_year($method) {
    $m = life_clean($method);
    foreach (LIFE_PER_YEAR as $k => $n) if ($m === $k || mb_strpos($m, $k) !== false) return $n;
    return 12;
}
// عبارتِ SQL برای پرداختِ مؤثر و مانده‌ی هر قسط (i = life_installments)
function life_sql_paid($a = 'i') { return "(CASE WHEN $a.cleared = 1 THEN $a.amount ELSE LEAST($a.amount, GREATEST($a.excel_collected, $a.paid_amount)) END)"; }
function life_sql_rem($a = 'i') { return "($a.amount - " . life_sql_paid($a) . ")"; }
function life_inst_status(array $i, $today = null) {
    $today = $today ?: date('Y-m-d');
    $amt = intval($i['amount']);
    $paid = !empty($i['cleared']) ? $amt : min($amt, max(intval($i['excel_collected']), intval($i['paid_amount'])));
    $atInsurer = !empty($i['cleared']) ? $amt : max(intval($i['excel_collected']), intval($i['paid_final'] ?? 0));
    if ($amt > 0 && $atInsurer >= $amt) return 'PAID';
    if ($paid >= $amt && $amt > 0) return 'PAID_US';
    if ($paid > 0) return 'PARTIAL';
    if ($i['due_g'] && $i['due_g'] < $today) return 'OVERDUE';
    return 'DUE';
}

// ---------------------------------------------------------------------
//  خواندنِ اکسل: همه‌ی شیت‌ها (شیتِ اقساط + شیتِ «شرایط جستجو» برای بازه‌ی سررسیدِ گزارش)
// ---------------------------------------------------------------------
function life_read_xlsx_all($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $rows = [];
        if (($h = fopen($path, 'r')) !== false) {
            $first = fgets($h); rewind($h);
            if (substr((string)$first, 0, 3) === "\xEF\xBB\xBF") fseek($h, 3);
            while (($r = fgetcsv($h, 0, ',')) !== false) $rows[] = $r;
            fclose($h);
        }
        return ['CSV' => $rows];
    }
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) {
        foreach ($m[1] as $si) { preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm); $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'); }
    }
    // نامِ شیت‌ها از workbook.xml و مسیرشان از rels
    $names = [];
    $wb = (string)$zip->getFromName('xl/workbook.xml');
    $rels = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
    $relMap = [];
    if (preg_match_all('/<Relationship\b[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER)) foreach ($rm as $r) $relMap[$r[1]] = $r[2];
    if (preg_match_all('/<sheet\b[^>]*name="([^"]*)"[^>]*r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER)) {
        foreach ($sm as $s) {
            $t = $relMap[$s[2]] ?? '';
            if ($t === '') continue;
            $t = ltrim(str_replace('../', '', $t), '/');
            if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
            $names[html_entity_decode($s[1], ENT_QUOTES | ENT_XML1, 'UTF-8')] = $t;
        }
    }
    if (!$names) for ($i = 0; $i < $zip->numFiles; $i++) { $n = $zip->getNameIndex($i); if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) $names[basename($n, '.xml')] = $n; }
    $out = [];
    foreach ($names as $name => $file) {
        $xml = $zip->getFromName($file);
        if ($xml === false) continue;
        $rows = [];
        if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $rmx)) {
            foreach ($rmx[1] as $rowXml) {
                $cells = [];
                if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm, PREG_SET_ORDER)) {
                    foreach ($cm as $cell) {
                        $attr = $cell[1]; $inner = $cell[2] ?? '';
                        $col = count($cells);
                        if (preg_match('/r="([A-Z]+)\d+"/', $attr, $rr)) { $col = 0; for ($k = 0; $k < strlen($rr[1]); $k++) $col = $col * 26 + (ord($rr[1][$k]) - 64); $col--; }
                        $val = '';
                        if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) $val = $vm[1];
                        elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm2)) $val = $tm2[1];
                        $val = strpos($attr, 't="s"') !== false ? ($shared[intval($val)] ?? '') : html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $cells[$col] = $val;
                    }
                }
                if ($cells) { $mx = max(array_keys($cells)); $norm = []; for ($k = 0; $k <= $mx; $k++) $norm[$k] = $cells[$k] ?? ''; $rows[] = $norm; }
            }
        }
        $out[$name] = $rows;
    }
    $zip->close();
    return $out;
}

// سرستون‌های یک فایل (برای تنظیماتِ ستون‌ها: «از روی یک فایلِ نمونه»)
function life_file_headers($path) {
    foreach (life_read_xlsx_all($path) as $name => $rows) {
        foreach (array_slice($rows, 0, 12) as $row) {
            $vals = array_values(array_filter(array_map('life_clean', $row), 'strlen'));
            if (count($vals) >= 4) return ['sheet' => $name, 'headers' => $vals];
        }
    }
    return ['sheet' => null, 'headers' => []];
}

// خواندنِ ردیف‌های اقساط => ['rows' => [...], 'range' => [from, to], 'missing' => [...]] یا ['error' => ...]
function life_parse_file($pdo, $path) {
    $sheets = life_read_xlsx_all($path);
    if (!$sheets) return ['error' => 'فایل خوانده نشد (فقط xlsx یا csv).'];
    $map = life_colmap($pdo);
    $lookup = [];
    foreach ($map as $k => [$fa, $names]) foreach ($names as $n) $lookup[life_norm_h($n)] = $k;
    $best = null;
    foreach ($sheets as $sname => $rows) {
        foreach (array_slice($rows, 0, 15, true) as $ri => $row) {
            $cols = [];
            foreach ((array)$row as $c => $cell) { $nh = life_norm_h($cell); if ($nh !== '' && isset($lookup[$nh]) && !in_array($lookup[$nh], $cols, true)) $cols[$c] = $lookup[$nh]; }
            if (count($cols) >= 4 && (!$best || count($cols) > count($best['cols']))) $best = ['sheet' => $sname, 'hi' => $ri, 'cols' => $cols];
        }
    }
    if (!$best) return ['error' => 'سرستون‌های اقساط پیدا نشد. از «تنظیمات ← ستون‌های اکسل» نام سرستون‌های این فایل را تعریف کنید.'];
    $missing = [];
    foreach ($map as $k => [$fa, $names, $req]) if ($req && !in_array($k, $best['cols'], true)) $missing[] = $fa;
    if ($missing) return ['error' => 'این ستون‌ها در فایل پیدا نشد: ' . implode('، ', $missing) . ' (از «تنظیمات ← ستون‌های اکسل» نامشان را اضافه کنید).'];
    $headersRaw = $sheets[$best['sheet']][$best['hi']];
    $rows = [];
    foreach (array_slice($sheets[$best['sheet']], $best['hi'] + 1) as $line => $row) {
        $r = [];
        foreach ($best['cols'] as $c => $k) $r[$k] = $row[$c] ?? '';
        $pno = life_clean($r['policy_no'] ?? '');
        if ($pno === '') continue;
        // همه‌ی ستون‌های خامِ فایل (برای «اطلاعاتِ کامل» و خروجی)
        $raw = [];
        foreach ($headersRaw as $c => $h) { $h = life_clean($h); if ($h !== '' && isset($row[$c]) && trim((string)$row[$c]) !== '') $raw[$h] = life_clean($row[$c]); }
        [$issueJ, $issueG] = life_jdate($r['issue'] ?? '');
        [$dueJ, $dueG] = life_jdate($r['due'] ?? '');
        $rows[] = [
            'line' => $best['hi'] + 2 + $line, 'policy_no' => $pno, 'holder_name' => life_clean($r['holder_name'] ?? ''), 'holder_nid' => life_nid($r['holder_nid'] ?? ''),
            'holder_mobile' => life_mobile($r['holder_mobile'] ?? ''), 'insured_name' => life_clean($r['insured_name'] ?? ''), 'insured_nid' => life_nid($r['insured_nid'] ?? ''),
            'agent' => life_clean($r['agent'] ?? ''), 'branch' => life_clean($r['branch'] ?? ''), 'contract_name' => life_clean($r['contract_name'] ?? ''),
            'variant' => life_clean($r['variant'] ?? ''), 'field_name' => life_clean($r['field_name'] ?? ''), 'issue_j' => $issueJ, 'issue_g' => $issueG,
            'start_j' => life_jdate($r['start'] ?? '')[0], 'end_j' => life_jdate($r['end'] ?? '')[0], 'duration' => life_int($r['duration'] ?? '') ?: null,
            'policy_year' => life_int($r['policy_year'] ?? '') ?: null, 'pay_method' => life_clean($r['pay_method'] ?? ''), 'policy_status' => life_clean($r['policy_status'] ?? ''),
            'channel' => life_clean($r['channel'] ?? ''), 'pay_id' => preg_replace('/\D/', '', p2e_digits((string)($r['pay_id'] ?? ''))),
            'internal_no' => preg_replace('/\D/', '', p2e_digits((string)($r['internal_no'] ?? ''))), 'inst_no' => life_int($r['inst_no'] ?? ''),
            'due_j' => $dueJ, 'due_g' => $dueG, 'amount' => life_int($r['amount'] ?? ''), 'collected' => life_int($r['collected'] ?? ''),
            'debt' => trim((string)($r['debt'] ?? '')) === '' ? null : life_int($r['debt']), 'raw' => $raw,
        ];
    }
    if (!$rows) return ['error' => 'هیچ ردیفِ قسطی در فایل پیدا نشد.'];
    // بازه‌ی سررسیدِ گزارش («تاریخ سررسید از / تا» در شیتِ شرایطِ جستجو)
    $range = [null, null];
    foreach ($sheets as $sname => $srows) {
        foreach (array_slice($srows, 0, 20) as $row) {
            foreach ((array)$row as $c => $cell) {
                $h = life_norm_h($cell);
                if ($h !== 'تاریخسررسیداز' && $h !== 'تاریخسررسیدتا') continue;
                for ($k = $c + 1; $k < $c + 4; $k++) { $j = life_jdate($row[$k] ?? '')[0]; if ($j) { $range[$h === 'تاریخسررسیداز' ? 0 : 1] = $j; break; } }
            }
        }
    }
    return ['rows' => $rows, 'range' => $range, 'sheet' => $best['sheet']];
}

// ---------------------------------------------------------------------
//  بایگانی: بایگانی بیمه عمر / {سال صدور} / {ماه صدور} / «{شماره بیمه‌نامه} - {نام} - {کد ملی}» / «قسط ۲ - 1405.03.04» / ...
// ---------------------------------------------------------------------
function life_policy_dir(array $p) {
    $jy = $jm = null;
    if (!empty($p['issue_j']) && preg_match('/^(1[34]\d{2})\/(\d{2})/', $p['issue_j'], $m)) { $jy = intval($m[1]); $jm = intval($m[2]); }
    $month = $jy ? $jy . '/' . sprintf('%02d', $jm) . ' ' . jalali_month_name($jm) : 'بدون تاریخ صدور';
    // همان قاعده‌ی بقیه‌ی بایگانی: «/» شماره‌ی بیمه‌نامه => «∕» و جداکننده‌ی « ‎- » (با LRM)
    $name = sanitize_folder_name(name_join([policy_number_for_filename((string)$p['policy_no']), $p['holder_name'] ?: 'نامشخص', $p['holder_nid'] ?: null]));
    $dir = life_archive_root() . '/' . ($jy ? $jy . '/' . sprintf('%02d', $jm) . ' ' . jalali_month_name($jm) : $month) . '/' . $name;
    // پوشه‌ای که با نام‌گذاریِ قدیم («1-49357-…  - نام - کدملی») ساخته شده، یک بار به نامِ تازه می‌رود
    if (!is_dir($dir)) {
        $old = dirname($dir) . '/' . preg_replace('/[<>:"\/\\\\|?*]/', '_', trim(str_replace('/', '-', (string)$p['policy_no']) . ' - ' . ($p['holder_name'] ?: 'نامشخص') . ($p['holder_nid'] ? ' - ' . $p['holder_nid'] : '')));
        if ($old !== $dir && is_dir($old)) life_move_dir($old, $dir);
    }
    return $dir;
}
// جابه‌جاییِ پوشه‌ی یک بیمه‌نامه + اصلاحِ مسیرِ فایل‌های ثبت‌شده‌ی پرداخت‌ها
function life_move_dir($old, $new) {
    if (!is_dir(dirname($new))) @mkdir(dirname($new), 0775, true);
    if (!@rename($old, $new)) return false;
    try {
        if (!empty($GLOBALS['pdo'])) {
            $o = life_rel($old) . '/'; $n = life_rel($new) . '/';
            $GLOBALS['pdo']->prepare("UPDATE life_payments SET file_path = CONCAT(?, SUBSTRING(file_path, ?)) WHERE file_path LIKE ?")
                ->execute([$n, mb_strlen($o) + 1, str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $o) . '%']);
        }
    } catch (Throwable $e) { error_log('[life_move_dir] ' . $e->getMessage()); }
    return true;
}
function life_inst_dir(array $p, array $i) {
    return life_policy_dir($p) . '/' . sanitize_folder_name('قسط ' . intval($i['inst_no']) . ($i['due_j'] ? ' - ' . str_replace('/', '.', $i['due_j']) : ''));
}
function life_mkdir($dir) { if (!is_dir($dir)) @mkdir($dir, 0775, true); return is_dir($dir); }
function life_user_name($pdo, $uid) {
    static $c = [];
    if (!$uid) return '';
    if (!isset($c[$uid])) { try { $st = $pdo->prepare("SELECT full_name FROM users WHERE id = ?"); $st->execute([$uid]); $c[$uid] = (string)$st->fetchColumn(); } catch (Throwable $e) { $c[$uid] = ''; } }
    return $c[$uid];
}
// فایلِ متنیِ اطلاعاتِ بیمه‌نامه و هر قسط (هر بار که چیزی عوض می‌شود دوباره نوشته می‌شود)
function life_write_archive($pdo, $policyId) {
    $p = life_policy_row($pdo, $policyId);
    if (!$p) return;
    $dir = life_policy_dir($p);
    if (!life_mkdir($dir)) return;
    if ($p['archive_dir'] !== life_rel($dir)) { $pdo->prepare("UPDATE life_policies SET archive_dir = ? WHERE id = ?")->execute([life_rel($dir), $policyId]); }
    $L = [];
    $L[] = 'بیمه‌نامه‌ی عمر ' . $p['policy_no'];
    $L[] = str_repeat('=', 40);
    foreach ([['بیمه‌گذار', $p['holder_name']], ['کد ملی', $p['holder_nid']], ['موبایل', $p['holder_mobile']], ['تلفن‌های دیگر', $p['phones']], ['بیمه‌شده‌ی اصلی', $p['insured_name']],
              ['کد ملی بیمه‌شده', $p['insured_nid']], ['تاریخ صدور', $p['issue_j']], ['شروع / پایان', trim($p['start_j'] . ' تا ' . $p['end_j'], ' تا')], ['مدت (سال)', $p['duration']],
              ['روش پرداخت', $p['pay_method']], ['وضعیت', $p['policy_status']], ['رشته / طرح', trim($p['field_name'] . ' · ' . $p['variant'], ' ·')], ['شناسه واریز', $p['pay_id']],
              ['نماینده', $p['agent']], ['شعبه', $p['branch']]] as [$k, $v]) if ((string)$v !== '') $L[] = $k . ': ' . $v;
    $notes = $pdo->prepare("SELECT * FROM life_notes WHERE policy_id = ? ORDER BY at");
    $notes->execute([$policyId]);
    $allNotes = $notes->fetchAll();
    $L[] = ''; $L[] = 'یادداشت‌ها و تماس‌ها:';
    foreach ($allNotes as $n) if (!$n['installment_id']) $L[] = '- ' . life_note_line($pdo, $n);
    file_put_contents($dir . '/اطلاعات بیمه‌نامه.txt', "\xEF\xBB\xBF" . implode("\r\n", $L));
    // هر قسط
    $st = $pdo->prepare("SELECT * FROM life_installments WHERE policy_id = ? ORDER BY inst_no");
    $st->execute([$policyId]);
    $pay = $pdo->prepare("SELECT * FROM life_payments WHERE installment_id = ? ORDER BY paid_g, id");
    foreach ($st->fetchAll() as $i) {
        $idir = life_inst_dir($p, $i);
        life_mkdir($idir);
        $s = life_inst_status($i);
        $T = ['قسط ' . $i['inst_no'] . ' · بیمه‌نامه ' . $p['policy_no'] . ' · ' . $p['holder_name'], str_repeat('=', 40),
              'سررسید: ' . $i['due_j'], 'مبلغ قسط: ' . number_format($i['amount']) . ' ریال', 'وضعیت: ' . LIFE_STATUS_FA[$s],
              'وصول طبق اکسلِ بیمه‌گر: ' . number_format($i['excel_collected']) . ' ریال', 'پرداخت‌های ثبت‌شده: ' . number_format($i['paid_amount']) . ' ریال'];
        if ($i['cleared']) $T[] = 'تسویه نزدِ بیمه‌گر: ' . ($i['cleared_note'] ?: 'بله');
        if ($i['internal_no']) $T[] = 'شماره داخلی قسط: ' . $i['internal_no'];
        $pay->execute([$i['id']]);
        $T[] = ''; $T[] = 'پرداخت‌ها:';
        foreach ($pay->fetchAll() as $py) $T[] = '- ' . $py['paid_j'] . ' · ' . number_format($py['amount']) . ' ریال · ' . $py['method'] . ($py['ref_no'] ? ' · پیگیری ' . $py['ref_no'] : '')
            . ($py['note'] ? ' · ' . $py['note'] : '') . ' · ثبت: ' . life_user_name($pdo, $py['created_by']) . ($py['voided_at'] ? ' (باطل‌شده)' : '');
        $T[] = ''; $T[] = 'یادداشت‌ها و تماس‌های این قسط:';
        foreach ($allNotes as $n) if (intval($n['installment_id']) === intval($i['id'])) $T[] = '- ' . life_note_line($pdo, $n);
        file_put_contents($idir . '/اطلاعات قسط.txt', "\xEF\xBB\xBF" . implode("\r\n", $T));
    }
}
function life_note_line($pdo, array $n) {
    [$jy, $jm, $jd] = jalali_from_gregorian_ts(strtotime($n['at']));
    return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', strtotime($n['at']))) . ' · ' . ($n['kind'] === 'call' ? 'تماس' : ($n['kind'] === 'sys' ? 'سیستم' : 'یادداشت'))
         . ($n['phone'] ? ' با ' . $n['phone'] : '') . ($n['result'] ? ' · ' . $n['result'] : '') . ($n['text'] ? ' · ' . $n['text'] : '')
         . ($n['next_j'] ? ' · پیگیریِ بعدی ' . $n['next_j'] : '') . ($n['created_by'] ? ' · ' . life_user_name($pdo, $n['created_by']) : '');
}
function life_policy_row($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM life_policies WHERE id = ?");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function life_sys_note($pdo, $policyId, $text, $uid, $instId = null) {
    $pdo->prepare("INSERT INTO life_notes (policy_id, installment_id, kind, at, text, created_by) VALUES (?, ?, 'sys', NOW(), ?, ?)")->execute([$policyId, $instId, $text, $uid ?: null]);
}
function life_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) { if ($f === '.' || $f === '..') continue; $p = $dir . '/' . $f; is_dir($p) ? life_rrmdir($p) : @unlink($p); }
    @rmdir($dir);
}
// کاربری که تماس/پرداخت/یادداشت ثبت می‌کند خودکار «پیگیری‌کننده»ی آن بیمه‌نامه می‌شود
function life_follow($pdo, $policyId, $uid, $auto = 1, $by = null) {
    if (!$uid) return;
    $pdo->prepare("INSERT INTO life_followers (policy_id, user_id, auto, added_by, added_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE auto = LEAST(auto, VALUES(auto))")
        ->execute([intval($policyId), intval($uid), $auto ? 1 : 0, $by ?: $uid]);
}
function life_followers($pdo, $policyId) {
    $st = $pdo->prepare("SELECT f.user_id, f.auto, u.full_name FROM life_followers f JOIN users u ON u.id = f.user_id WHERE f.policy_id = ? ORDER BY f.auto, f.added_at");
    $st->execute([intval($policyId)]);
    return array_map(function ($r) { return ['id' => intval($r['user_id']), 'name' => $r['full_name'], 'auto' => intval($r['auto'])]; }, $st->fetchAll());
}
// حق‌بیمه‌ی کلِ یک سال = مبلغِ قسط × تعدادِ قسط در سال (بر اساسِ روشِ پرداخت)
function life_annual($amount, $method) { return intval($amount) * life_per_year($method); }

// مبلغِ پرداخت‌های ثبت‌شده‌ی یک قسط (کش در paid_amount)
function life_refresh_paid($pdo, $instId) {
    $pdo->prepare("UPDATE life_installments SET paid_amount = (SELECT COALESCE(SUM(amount), 0) FROM life_payments WHERE installment_id = ? AND voided_at IS NULL),
                          paid_final = (SELECT COALESCE(SUM(amount), 0) FROM life_payments WHERE installment_id = ? AND voided_at IS NULL AND is_final = 1), updated_at = NOW() WHERE id = ?")
        ->execute([$instId, $instId, $instId]);
}
// «مرحله‌ی پرداخت به بیمه‌گر» خاموش است؟ (تنظیماتِ بیمه عمر) — از لحظه‌ی خاموش‌کردن به بعد، پرداختِ ثبت‌شده آخرین مرحله است
function life_insurer_step_off($pdo) { return life_setting($pdo, 'life_skip_insurer_step', '0') === '1'; }
// شرطِ SQL: قسطی که به ما پرداخت شده ولی هنوز نزدِ بیمه‌گر تسویه نشده
function life_sql_await($a = 'i') { return "($a.cleared = 0 AND $a.amount > 0 AND GREATEST($a.excel_collected, $a.paid_amount) >= $a.amount AND GREATEST($a.excel_collected, $a.paid_final) < $a.amount)"; }

require_once __DIR__ . '/_life_import.php';
require_once __DIR__ . '/_life_query.php';
require_once __DIR__ . '/_life_receipt.php';
require_once __DIR__ . '/_life_payslip.php';
