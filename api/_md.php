<?php
// فایل: api/_md.php
// «درمان و عمرِ گروهی» (بازنویسیِ بخشِ درمان): قراردادهای سالانه‌ی درمانِ تکمیلی و عمر و حوادثِ شرکت‌ها، طرح‌ها
// (حق‌بیمه‌ی ماهانه یکسان یا جدا برای اصلی/همسر/فرزند/والدین، سهمِ شرکت و شخص) و سقفِ پوشش‌های هر طرح؛
// تنظیماتِ درمانِ هر شرکت (فقط بیمه‌شده‌ی اصلی / با خانواده) و پیروی از قانونِ شرکتِ مادر تا وقتی قانونِ مستقل تعریف نشده.
// زیرمجموعه بودن فقط روی محاسبه و اقساطِ درمان اثر دارد (بایگانیِ خودرو جداست). همه‌ی جدول‌ها خودکار ساخته می‌شوند.

const MD_KINDS = ['HEALTH' => 'درمانِ تکمیلی', 'LIFE' => 'عمر و حوادث'];
const MD_MODES = ['none' => 'ندارد', 'main' => 'فقط بیمه‌شده‌ی اصلی', 'family' => 'بیمه‌شده‌ی اصلی و خانواده'];
const MD_REL = ['main' => 'اصلی', 'spouse' => 'همسر', 'child' => 'فرزند', 'parent' => 'پدر / مادر'];
const MD_PREMIUM_MODES = ['FLAT' => 'برای همه یکسان', 'REL' => 'جدا برای اصلی / همسر / فرزند / والدین'];
const MD_STATUS = ['ACTIVE' => 'فعال', 'ENDED' => 'پایان‌یافته'];
// پوشش‌های رایجِ درمانِ تکمیلی (برای افزودنِ سریع؛ قابلِ ویرایش در تنظیمات)
const MD_COV_DEFAULT = ['بستری، جراحی و اعمالِ جراحیِ مهم', 'زایمان (طبیعی و سزارین)', 'هزینه‌های پاراکلینیکی (سونوگرافی، ماموگرافی، اسکن، MRI…)', 'آزمایش‌ها، رادیولوژی و پاتولوژی',
    'ویزیت، دارو و خدماتِ سرپایی', 'دندانپزشکی', 'عینک و لنز', 'جراحیِ مجازِ سرپایی', 'شکستگی و گچ‌گیری', 'آمبولانس', 'فیزیوتراپی', 'لیزیک و اعمالِ مربوط به چشم', 'سمعک', 'نازایی و ناباروری'];

function md_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        // تنظیماتِ درمانِ هر شرکت؛ own_rule = 0 یعنی پیروی از شرکتِ مادر (اگر دارد)
        "CREATE TABLE IF NOT EXISTS md_company_cfg (company_id INT NOT NULL PRIMARY KEY, own_rule TINYINT NOT NULL DEFAULT 0,
            health_mode VARCHAR(8) NOT NULL DEFAULT 'none', health_contract_id INT NULL, health_plan_id INT NULL,
            life_mode VARCHAR(8) NOT NULL DEFAULT 'none', life_contract_id INT NULL, life_plan_id INT NULL,
            portal_on TINYINT NOT NULL DEFAULT 0, note VARCHAR(500) NULL, updated_by INT NULL, updated_at DATETIME NULL) $o",
        "CREATE TABLE IF NOT EXISTS md_contracts (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(8) NOT NULL DEFAULT 'HEALTH', title VARCHAR(190) NULL,
            insurer VARCHAR(80) NOT NULL DEFAULT 'پاسارگاد', policy_no VARCHAR(80) NULL, holder_company_id INT NULL,
            start_j CHAR(10) NULL, start_g DATE NULL, end_j CHAR(10) NULL, end_g DATE NULL,
            premium_mode VARCHAR(4) NOT NULL DEFAULT 'FLAT', share_main_pct DECIMAL(6,2) NOT NULL DEFAULT 100, share_family_pct DECIMAL(6,2) NOT NULL DEFAULT 100,
            vat_pct DECIMAL(6,2) NOT NULL DEFAULT 0, status VARCHAR(8) NOT NULL DEFAULT 'ACTIVE', note TEXT NULL,
            created_by INT NULL, created_at DATETIME NULL, updated_by INT NULL, updated_at DATETIME NULL, is_deleted TINYINT NOT NULL DEFAULT 0,
            KEY k_holder (holder_company_id), KEY k_kind (kind, status)) $o",
        // طرح‌ها: حق‌بیمه‌ی ماهانه (یکسان یا جدا برای هر نسبت)، مبلغ/سرمایه‌ی عمر و حوادث
        "CREATE TABLE IF NOT EXISTS md_plans (id INT AUTO_INCREMENT PRIMARY KEY, contract_id INT NOT NULL, name VARCHAR(120) NOT NULL, code VARCHAR(30) NULL,
            p_flat BIGINT NOT NULL DEFAULT 0, p_main BIGINT NOT NULL DEFAULT 0, p_spouse BIGINT NOT NULL DEFAULT 0, p_child BIGINT NOT NULL DEFAULT 0, p_parent BIGINT NOT NULL DEFAULT 0,
            life_capital BIGINT NOT NULL DEFAULT 0, variable_amount TINYINT NOT NULL DEFAULT 0, sort INT NOT NULL DEFAULT 0, note VARCHAR(500) NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0, KEY k_contract (contract_id)) $o",
        // سقفِ پوشش‌های هر طرح
        "CREATE TABLE IF NOT EXISTS md_coverages (id INT AUTO_INCREMENT PRIMARY KEY, plan_id INT NOT NULL, name VARCHAR(250) NOT NULL, cap BIGINT NULL,
            franchise VARCHAR(60) NULL, note VARCHAR(400) NULL, sort INT NOT NULL DEFAULT 0, KEY k_plan (plan_id)) $o",
        "CREATE TABLE IF NOT EXISTS md_cov_lib (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(250) NOT NULL, sort INT NOT NULL DEFAULT 0, UNIQUE KEY u_name (name)) $o",
        "CREATE TABLE IF NOT EXISTS md_log (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, user_id INT NULL, user_name VARCHAR(190) NULL, action VARCHAR(40) NOT NULL,
            ref VARCHAR(60) NULL, note VARCHAR(600) NULL, KEY k_ref (ref), KEY k_at (at)) $o",
        // پیوست‌های قرارداد/طرح (نامه‌ی اعلامِ تعهدات و حق‌بیمه‌ی بیمه‌گر و…) در «بایگانی درمان و عمر»
        "CREATE TABLE IF NOT EXISTS md_files (id INT AUTO_INCREMENT PRIMARY KEY, contract_id INT NOT NULL, plan_id INT NULL, name VARCHAR(250) NOT NULL, rel VARCHAR(600) NOT NULL,
            size INT NOT NULL DEFAULT 0, uploaded_by INT NULL, uploaded_at DATETIME NULL, is_deleted TINYINT NOT NULL DEFAULT 0, KEY k_contract (contract_id), KEY k_plan (plan_id)) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[md_ensure] ' . $e->getMessage()); } }
    // ستون‌های تازه (روی جدول‌های ساخته‌شده‌ی قبلی هم اضافه می‌شوند؛ داده‌ها دست نمی‌خورند)
    foreach ([
        // «بدون سقف» و سقفِ مشترکِ چند ردیف (مثلاً پاراکلینیکی + آزمایش + فیزیوتراپی با یک سقف)
        ['md_coverages', 'unlimited', 'TINYINT NOT NULL DEFAULT 0'], ['md_coverages', 'cap_group', 'VARCHAR(30) NULL'],
        // شرایطِ طرح از نامه‌ی بیمه‌گر: فرانشیزِ کلی، تعدادِ نفراتِ مبنا، شماره و تاریخِ نامه، تعهداتِ تکمیلی
        ['md_plans', 'franchise_pct', 'DECIMAL(6,2) NULL'], ['md_plans', 'headcount', 'INT NULL'], ['md_plans', 'letter_no', 'VARCHAR(60) NULL'],
        ['md_plans', 'letter_j', 'CHAR(10) NULL'], ['md_plans', 'terms', 'TEXT NULL'],
    ] as [$t, $col, $def]) {
        try { if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$col` $def"); }
        catch (Throwable $e) { error_log('[md_ensure col] ' . $e->getMessage()); }
    }
    try {
        if (!(int)$pdo->query("SELECT COUNT(*) FROM md_cov_lib")->fetchColumn()) {
            $st = $pdo->prepare("INSERT IGNORE INTO md_cov_lib (name, sort) VALUES (?, ?)");
            foreach (MD_COV_DEFAULT as $i => $n) $st->execute([$n, $i]);
        }
    } catch (Throwable $e) {}
}

function md_log($pdo, $action, $ref, $note = '') {
    try {
        $pdo->prepare("INSERT INTO md_log (at, user_id, user_name, action, ref, note) VALUES (NOW(), ?, ?, ?, ?, ?)")
            ->execute([$_SESSION['user_id'] ?? null, mb_substr((string)($_SESSION['full_name'] ?? ''), 0, 190), $action, $ref, mb_substr((string)$note, 0, 600)]);
    } catch (Throwable $e) {}
}

// تاریخِ شمسی (۱۴۰۵/۰۷/۰۱ یا ۱۴۰۵-۷-۱، با رقمِ فارسی) => [jalali «۱۴۰۵/۰۷/۰۱» با رقمِ لاتین، gregorian Y-m-d] یا [null, null]
function md_jdate($s) {
    $s = strtr(trim((string)$s), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    if (!preg_match('~^(1[34]\d\d)[/\-.](\d{1,2})[/\-.](\d{1,2})$~', $s, $m)) return [null, null];
    $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return [null, null];
    if (!function_exists('jalali_to_gregorian_ts')) require_once __DIR__ . '/_case_helpers.php';
    return [sprintf('%04d/%02d/%02d', $y, $mo, $d), date('Y-m-d', jalali_to_gregorian_ts($y, $mo, $d))];
}
function md_money($v) { return (int)preg_replace('/[^\d\-]/', '', strtr((string)$v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', ',' => '', '٬' => ''])); }
function md_pct($v) { $v = (float)strtr((string)$v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.', '/' => '.']); return max(0, min(100, $v)); }

// ---------------- شرکت‌ها و قانونِ مؤثر ----------------
function md_companies($pdo) {
    md_ensure($pdo);
    $rows = $pdo->query("SELECT c.id, c.name, c.parent_id, c.kind, g.own_rule, g.health_mode, g.health_contract_id, g.health_plan_id, g.life_mode, g.life_contract_id, g.life_plan_id,
                                g.portal_on, g.note, g.updated_at
                         FROM companies c LEFT JOIN md_company_cfg g ON g.company_id = c.id ORDER BY c.name")->fetchAll();
    $by = []; foreach ($rows as $r) $by[(int)$r['id']] = $r;
    return $by;
}
// قانونِ درمانِ مؤثرِ یک شرکت: خودش (اگر قانونِ مستقل دارد یا شرکتِ مادر ندارد) یا همان شرکتِ مادر (زنجیره‌ای)
//   => [source_id (شرکتی که قانون از آن است), inherited (bool), health_mode, health_contract_id, health_plan_id, life_mode, ...]
function md_effective(array $by, $companyId) {
    $id = (int)$companyId; $seen = [];
    while (isset($by[$id]) && !isset($seen[$id])) {
        $seen[$id] = 1; $c = $by[$id];
        $parent = (int)($c['parent_id'] ?? 0);
        if ((int)($c['own_rule'] ?? 0) === 1 || !$parent || !isset($by[$parent])) {
            return ['source_id' => $id, 'inherited' => $id !== (int)$companyId,
                    'health_mode' => $c['health_mode'] ?: 'none', 'health_contract_id' => $c['health_contract_id'] ? (int)$c['health_contract_id'] : null, 'health_plan_id' => $c['health_plan_id'] ? (int)$c['health_plan_id'] : null,
                    'life_mode' => $c['life_mode'] ?: 'none', 'life_contract_id' => $c['life_contract_id'] ? (int)$c['life_contract_id'] : null, 'life_plan_id' => $c['life_plan_id'] ? (int)$c['life_plan_id'] : null];
        }
        $id = $parent;
    }
    return ['source_id' => (int)$companyId, 'inherited' => false, 'health_mode' => 'none', 'health_contract_id' => null, 'health_plan_id' => null, 'life_mode' => 'none', 'life_contract_id' => null, 'life_plan_id' => null];
}
function md_company_save($pdo, $companyId, array $in) {
    md_ensure($pdo);
    $companyId = (int)$companyId;
    $st = $pdo->prepare("SELECT id, parent_id FROM companies WHERE id = ?"); $st->execute([$companyId]);
    $co = $st->fetch();
    if (!$co) throw new RuntimeException('شرکت پیدا نشد.');
    $mode = function ($v) { return isset(MD_MODES[$v]) ? $v : 'none'; };
    $cid = function ($v, $kind) use ($pdo) {
        $v = (int)$v; if (!$v) return null;
        $q = $pdo->prepare("SELECT kind FROM md_contracts WHERE id = ? AND is_deleted = 0"); $q->execute([$v]);
        $k = $q->fetchColumn();
        if ($k !== $kind) throw new RuntimeException('قراردادِ انتخاب‌شده برای ' . MD_KINDS[$kind] . ' نیست.');
        return $v;
    };
    $pid = function ($v, $contract) use ($pdo) {
        $v = (int)$v; if (!$v || !$contract) return null;
        $q = $pdo->prepare("SELECT id FROM md_plans WHERE id = ? AND contract_id = ? AND is_deleted = 0"); $q->execute([$v, $contract]);
        return $q->fetchColumn() ? $v : null;
    };
    $own = !empty($in['own_rule']) || !(int)$co['parent_id'] ? 1 : 0;
    $hc = $cid($in['health_contract_id'] ?? 0, 'HEALTH'); $lc = $cid($in['life_contract_id'] ?? 0, 'LIFE');
    $row = [$own, $mode($in['health_mode'] ?? 'none'), $hc, $pid($in['health_plan_id'] ?? 0, $hc), $mode($in['life_mode'] ?? 'none'), $lc, $pid($in['life_plan_id'] ?? 0, $lc),
            !empty($in['portal_on']) ? 1 : 0, mb_substr(trim((string)($in['note'] ?? '')), 0, 500) ?: null, $_SESSION['user_id'] ?? null];
    $pdo->prepare("INSERT INTO md_company_cfg (company_id, own_rule, health_mode, health_contract_id, health_plan_id, life_mode, life_contract_id, life_plan_id, portal_on, note, updated_by, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE own_rule = VALUES(own_rule), health_mode = VALUES(health_mode), health_contract_id = VALUES(health_contract_id), health_plan_id = VALUES(health_plan_id),
                       life_mode = VALUES(life_mode), life_contract_id = VALUES(life_contract_id), life_plan_id = VALUES(life_plan_id), portal_on = VALUES(portal_on), note = VALUES(note),
                       updated_by = VALUES(updated_by), updated_at = NOW()")
        ->execute(array_merge([$companyId], $row));
    md_log($pdo, 'COMPANY_SAVE', 'co:' . $companyId, ($own ? 'قانونِ مستقل' : 'پیروی از شرکتِ مادر') . ' · درمان: ' . MD_MODES[$row[1]] . ' · عمر: ' . MD_MODES[$row[4]]);
}

// ---------------- قراردادها ----------------
function md_contract_out($pdo, array $c, $withPlans = true) {
    $o = ['id' => (int)$c['id'], 'kind' => $c['kind'], 'kind_fa' => MD_KINDS[$c['kind']] ?? $c['kind'], 'title' => (string)$c['title'], 'insurer' => (string)$c['insurer'],
          'policy_no' => (string)$c['policy_no'], 'holder_company_id' => $c['holder_company_id'] ? (int)$c['holder_company_id'] : null, 'holder_name' => (string)($c['holder_name'] ?? ''),
          'start_j' => (string)$c['start_j'], 'end_j' => (string)$c['end_j'], 'premium_mode' => $c['premium_mode'], 'share_main_pct' => (float)$c['share_main_pct'],
          'share_family_pct' => (float)$c['share_family_pct'], 'vat_pct' => (float)$c['vat_pct'], 'status' => $c['status'], 'status_fa' => MD_STATUS[$c['status']] ?? $c['status'],
          'note' => (string)$c['note'], 'updated_at' => (string)($c['updated_at'] ?: $c['created_at'])];
    if ($withPlans) $o['plans'] = md_plans($pdo, (int)$c['id']);
    return $o;
}
function md_plans($pdo, $contractId) {
    $st = $pdo->prepare("SELECT * FROM md_plans WHERE contract_id = ? AND is_deleted = 0 ORDER BY sort, id"); $st->execute([$contractId]);
    $plans = $st->fetchAll();
    if (!$plans) return [];
    $ids = array_map(function ($p) { return (int)$p['id']; }, $plans);
    $cov = [];
    foreach ($pdo->query("SELECT * FROM md_coverages WHERE plan_id IN (" . implode(',', $ids) . ") ORDER BY sort, id") as $c)
        $cov[(int)$c['plan_id']][] = ['id' => (int)$c['id'], 'name' => $c['name'], 'cap' => $c['cap'] === null ? null : (int)$c['cap'], 'unlimited' => (int)($c['unlimited'] ?? 0) === 1,
                                      'cap_group' => (string)($c['cap_group'] ?? ''), 'franchise' => (string)$c['franchise'], 'note' => (string)$c['note']];
    $files = [];
    foreach ($pdo->query("SELECT id, plan_id, name, size, uploaded_at FROM md_files WHERE is_deleted = 0 AND plan_id IN (" . implode(',', $ids) . ") ORDER BY id") as $f)
        $files[(int)$f['plan_id']][] = ['id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'at' => (string)$f['uploaded_at']];
    return array_map(function ($p) use ($cov, $files) {
        return ['id' => (int)$p['id'], 'name' => $p['name'], 'code' => (string)$p['code'], 'p_flat' => (int)$p['p_flat'], 'p_main' => (int)$p['p_main'], 'p_spouse' => (int)$p['p_spouse'],
                'p_child' => (int)$p['p_child'], 'p_parent' => (int)$p['p_parent'], 'life_capital' => (int)$p['life_capital'], 'variable_amount' => (bool)$p['variable_amount'],
                'note' => (string)$p['note'], 'franchise_pct' => $p['franchise_pct'] === null ? '' : (float)$p['franchise_pct'], 'headcount' => $p['headcount'] === null ? '' : (int)$p['headcount'],
                'letter_no' => (string)$p['letter_no'], 'letter_j' => (string)$p['letter_j'], 'terms' => (string)$p['terms'],
                'coverages' => $cov[(int)$p['id']] ?? [], 'files' => $files[(int)$p['id']] ?? []];
    }, $plans);
}
function md_contracts_list($pdo, array $f = []) {
    md_ensure($pdo);
    $w = ['c.is_deleted = 0']; $a = [];
    if (!empty($f['kind']) && isset(MD_KINDS[$f['kind']])) { $w[] = 'c.kind = ?'; $a[] = $f['kind']; }
    if (!empty($f['status']) && isset(MD_STATUS[$f['status']])) { $w[] = 'c.status = ?'; $a[] = $f['status']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(c.title LIKE ? OR c.policy_no LIKE ? OR c.insurer LIKE ? OR co.name LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%", "%$q%"); }
    $st = $pdo->prepare("SELECT c.*, co.name AS holder_name FROM md_contracts c LEFT JOIN companies co ON co.id = c.holder_company_id WHERE " . implode(' AND ', $w) . " ORDER BY c.status = 'ACTIVE' DESC, c.start_g DESC, c.id DESC");
    $st->execute($a);
    $out = [];
    // شرکت‌هایی که از هر قرارداد استفاده می‌کنند (با پیروی از شرکتِ مادر)
    $by = md_companies($pdo); $uses = [];
    foreach ($by as $id => $c) { $e = md_effective($by, $id); foreach (['health', 'life'] as $k) if ($e[$k . '_contract_id'] && $e[$k . '_mode'] !== 'none') $uses[$e[$k . '_contract_id']][] = $c['name']; }
    foreach ($st->fetchAll() as $c) {
        $o = md_contract_out($pdo, $c);
        $o['companies'] = array_values(array_unique($uses[(int)$c['id']] ?? []));
        $out[] = $o;
    }
    return $out;
}
function md_contract_get($pdo, $id) {
    md_ensure($pdo);
    $st = $pdo->prepare("SELECT c.*, co.name AS holder_name FROM md_contracts c LEFT JOIN companies co ON co.id = c.holder_company_id WHERE c.id = ? AND c.is_deleted = 0"); $st->execute([(int)$id]);
    $c = $st->fetch();
    return $c ? md_contract_out($pdo, $c) : null;
}
// ذخیره‌ی قرارداد با طرح‌ها و پوشش‌هایش (یک‌جا، در یک تراکنش). طرح/پوششی که در ورودی نیست حذف می‌شود.
function md_contract_save($pdo, array $in) {
    md_ensure($pdo);
    $id = (int)($in['id'] ?? 0);
    $kind = isset(MD_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'HEALTH';
    [$sj, $sg] = md_jdate($in['start_j'] ?? ''); [$ej, $eg] = md_jdate($in['end_j'] ?? '');
    if (!$sj || !$ej) throw new RuntimeException('تاریخِ شروع و پایانِ قرارداد را درست وارد کنید (مثلاً ۱۴۰۵/۰۸/۰۱).');
    if ($eg <= $sg) throw new RuntimeException('تاریخِ پایان باید بعد از تاریخِ شروع باشد.');
    $holder = (int)($in['holder_company_id'] ?? 0) ?: null;
    if ($holder) { $q = $pdo->prepare("SELECT id FROM companies WHERE id = ?"); $q->execute([$holder]); if (!$q->fetchColumn()) throw new RuntimeException('شرکتِ بیمه‌گذار پیدا نشد.'); }
    $plans = is_array($in['plans'] ?? null) ? $in['plans'] : [];
    if (!$plans) throw new RuntimeException('دستِ کم یک طرح تعریف کنید.');
    $pm = ($in['premium_mode'] ?? '') === 'REL' ? 'REL' : 'FLAT';
    $vals = [$kind, mb_substr(trim((string)($in['title'] ?? '')), 0, 190) ?: null, mb_substr(trim((string)($in['insurer'] ?? '')), 0, 80) ?: 'پاسارگاد',
             mb_substr(trim((string)($in['policy_no'] ?? '')), 0, 80) ?: null, $holder, $sj, $sg, $ej, $eg, $pm,
             md_pct($in['share_main_pct'] ?? 100), md_pct($in['share_family_pct'] ?? 100), md_pct($in['vat_pct'] ?? 0),
             ($in['status'] ?? '') === 'ENDED' ? 'ENDED' : 'ACTIVE', trim((string)($in['note'] ?? '')) ?: null];
    $uid = $_SESSION['user_id'] ?? null;
    $pdo->beginTransaction();
    try {
        if ($id) {
            $q = $pdo->prepare("SELECT id FROM md_contracts WHERE id = ? AND is_deleted = 0"); $q->execute([$id]);
            if (!$q->fetchColumn()) throw new RuntimeException('قرارداد پیدا نشد.');
            $pdo->prepare("UPDATE md_contracts SET kind = ?, title = ?, insurer = ?, policy_no = ?, holder_company_id = ?, start_j = ?, start_g = ?, end_j = ?, end_g = ?, premium_mode = ?,
                           share_main_pct = ?, share_family_pct = ?, vat_pct = ?, status = ?, note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")->execute(array_merge($vals, [$uid, $id]));
        } else {
            $pdo->prepare("INSERT INTO md_contracts (kind, title, insurer, policy_no, holder_company_id, start_j, start_g, end_j, end_g, premium_mode, share_main_pct, share_family_pct, vat_pct, status, note, created_by, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute(array_merge($vals, [$uid]));
            $id = (int)$pdo->lastInsertId();
        }
        $keep = []; $pending = [];
        foreach (array_values($plans) as $i => $p) {
            $name = mb_substr(trim((string)($p['name'] ?? '')), 0, 120);
            if ($name === '') throw new RuntimeException('نامِ طرحِ ردیفِ ' . ($i + 1) . ' خالی است.');
            $pv = [$name, mb_substr(trim((string)($p['code'] ?? '')), 0, 30) ?: null, md_money($p['p_flat'] ?? 0), md_money($p['p_main'] ?? 0), md_money($p['p_spouse'] ?? 0),
                   md_money($p['p_child'] ?? 0), md_money($p['p_parent'] ?? 0), md_money($p['life_capital'] ?? 0), !empty($p['variable_amount']) ? 1 : 0, $i, mb_substr(trim((string)($p['note'] ?? '')), 0, 500) ?: null,
                   trim((string)($p['franchise_pct'] ?? '')) === '' ? null : md_pct($p['franchise_pct']), md_money($p['headcount'] ?? 0) ?: null,
                   mb_substr(trim((string)($p['letter_no'] ?? '')), 0, 60) ?: null, md_jdate($p['letter_j'] ?? '')[0], trim((string)($p['terms'] ?? '')) ?: null];
            $pid = (int)($p['id'] ?? 0);
            if ($pid) {
                $q = $pdo->prepare("SELECT id FROM md_plans WHERE id = ? AND contract_id = ?"); $q->execute([$pid, $id]);
                if (!$q->fetchColumn()) $pid = 0;
            }
            if ($pid) $pdo->prepare("UPDATE md_plans SET name = ?, code = ?, p_flat = ?, p_main = ?, p_spouse = ?, p_child = ?, p_parent = ?, life_capital = ?, variable_amount = ?, sort = ?, note = ?,
                                     franchise_pct = ?, headcount = ?, letter_no = ?, letter_j = ?, terms = ?, is_deleted = 0 WHERE id = ?")
                          ->execute(array_merge($pv, [$pid]));
            else { $pdo->prepare("INSERT INTO md_plans (contract_id, name, code, p_flat, p_main, p_spouse, p_child, p_parent, life_capital, variable_amount, sort, note, franchise_pct, headcount, letter_no, letter_j, terms)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                       ->execute(array_merge([$id], $pv)); $pid = (int)$pdo->lastInsertId(); }
            $keep[] = $pid;
            foreach ((array)($p['pending_files'] ?? []) as $pf) if (is_array($pf)) $pending[] = [$pid, $name, (string)($pf['token'] ?? ''), (string)($pf['name'] ?? '')];
            // پوشش‌ها: همان فهرست جایگزین می‌شود
            $pdo->prepare("DELETE FROM md_coverages WHERE plan_id = ?")->execute([$pid]);
            $ins = $pdo->prepare("INSERT INTO md_coverages (plan_id, name, cap, unlimited, cap_group, franchise, note, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach (md_cov_normalize(is_array($p['coverages'] ?? null) ? $p['coverages'] : []) as $j => $c)
                $ins->execute([$pid, $c['name'], $c['cap'], $c['unlimited'], $c['cap_group'], $c['franchise'], $c['note'], $j]);
        }
        // طرح‌هایی که حذف شدند (اگر شرکتی رویشان بود، از تنظیماتِ آن شرکت برداشته می‌شوند)
        $gone = $pdo->prepare("SELECT id FROM md_plans WHERE contract_id = ? AND is_deleted = 0 AND id NOT IN (" . implode(',', $keep) . ")"); $gone->execute([$id]);
        foreach ($gone->fetchAll(PDO::FETCH_COLUMN) as $g) {
            $pdo->prepare("UPDATE md_plans SET is_deleted = 1 WHERE id = ?")->execute([$g]);
            $pdo->prepare("UPDATE md_company_cfg SET health_plan_id = IF(health_plan_id = ?, NULL, health_plan_id), life_plan_id = IF(life_plan_id = ?, NULL, life_plan_id)")->execute([$g, $g]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    // پیوست‌های تازه (بعد از ثبتِ قرارداد): از پوشه‌ی موقت به «بایگانی درمان و عمر»
    foreach ($pending as [$pid, $pname, $tok, $fname]) { try { md_file_attach($pdo, $id, $pid, $tok, $fname, $vals[1] ?: '', $pname); } catch (Throwable $e) { error_log('[md attach] ' . $e->getMessage()); } }
    md_log($pdo, 'CONTRACT_SAVE', 'ct:' . $id, MD_KINDS[$kind] . ' · ' . ($vals[1] ?: $vals[3] ?: '') . ' · ' . count($plans) . ' طرح');
    return $id;
}
function md_contract_delete($pdo, $id) {
    md_ensure($pdo);
    $st = $pdo->prepare("SELECT title, policy_no FROM md_contracts WHERE id = ? AND is_deleted = 0"); $st->execute([(int)$id]);
    $c = $st->fetch();
    if (!$c) throw new RuntimeException('قرارداد پیدا نشد.');
    $pdo->prepare("UPDATE md_contracts SET is_deleted = 1, updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([$_SESSION['user_id'] ?? null, (int)$id]);
    $pdo->prepare("UPDATE md_company_cfg SET health_contract_id = IF(health_contract_id = ?, NULL, health_contract_id), health_plan_id = IF(health_contract_id IS NULL, NULL, health_plan_id),
                   life_contract_id = IF(life_contract_id = ?, NULL, life_contract_id), life_plan_id = IF(life_contract_id IS NULL, NULL, life_plan_id)")->execute([(int)$id, (int)$id]);
    md_log($pdo, 'CONTRACT_DELETE', 'ct:' . (int)$id, ($c['title'] ?: $c['policy_no']) ?: '');
}

// ---------------- ورودِ سقفِ پوشش‌ها از اکسل ----------------
// سطرِ عنوان و ستون‌های «پوشش / سقف / فرانشیز / طرح» خودکار پیدا می‌شوند؛ کاربر در پیش‌نمایش می‌تواند ستون‌ها را عوض کند
function md_cov_detect(array $rows) {
    $hdr = 0; $best = -1;
    foreach (array_slice($rows, 0, 15, true) as $i => $r) {
        $s = 0; foreach ($r as $c) { $c = (string)$c; if (preg_match('/پوشش|تعهد|شرح|خدمات|سقف|مبلغ|فرانشیز|طرح/u', $c)) $s++; }
        if ($s > $best) { $best = $s; $hdr = $i; }
    }
    $h = array_map(function ($c) { return trim((string)$c); }, $rows[$hdr] ?? []);
    $find = function ($re) use ($h) { foreach ($h as $i => $c) if (preg_match($re, $c)) return $i; return null; };
    return ['header_row' => $hdr, 'headers' => $h,
            'name' => $find('/پوشش|شرح|خدمات|عنوان|تعهدات/u'), 'cap' => $find('/سقف|مبلغ|تعهد(?!ات)/u'), 'franchise' => $find('/فرانشیز/u'), 'plan' => $find('/^طرح|نام طرح|گروه/u'), 'note' => $find('/توضیح|ملاحظات/u')];
}
function md_cov_rows(array $rows, array $map) {
    $out = [];
    foreach ($rows as $i => $r) {
        if ($i <= (int)$map['header_row']) continue;
        $name = isset($map['name']) && $map['name'] !== null && $map['name'] !== '' ? trim((string)($r[(int)$map['name']] ?? '')) : '';
        if ($name === '' || preg_match('/^(جمع|ردیف)$/u', $name)) continue;
        $g = function ($k) use ($r, $map) { return isset($map[$k]) && $map[$k] !== null && $map[$k] !== '' ? trim((string)($r[(int)$map[$k]] ?? '')) : ''; };
        $cap = $g('cap'); $unl = md_is_unlimited($cap);
        $out[] = ['plan' => $g('plan'), 'name' => mb_substr($name, 0, 250), 'cap' => ($cap === '' || $unl) ? null : md_money($cap), 'unlimited' => $unl, 'cap_group' => '',
                  'franchise' => mb_substr($g('franchise'), 0, 60), 'note' => mb_substr($g('note'), 0, 400)];
    }
    return $out;
}

// «بدون سقف» / «نامحدود» / «کامل» در ستونِ سقف
function md_is_unlimited($v) { return (bool)preg_match('/بدون\s*سقف|نامحدود|بی\s*سقف|unlimited/iu', (string)$v); }

// پوشش‌های یک طرح پیش از ذخیره: ردیف‌های خالی حذف؛ «بدون سقف» = سقفِ خالی؛ ردیف‌های یک «سقفِ مشترک» همه یک سقف می‌گیرند
// (اولین سقفِ واردشده در آن گروه، یا «بدون سقف» اگر اولین ردیفِ تعیین‌شده‌ی گروه چنین است)
function md_cov_normalize(array $list) {
    $rows = [];
    foreach (array_values($list) as $c) {
        if (!is_array($c)) continue;
        $cn = mb_substr(trim((string)($c['name'] ?? '')), 0, 250);
        if ($cn === '') continue;
        $capRaw = trim((string)($c['cap'] ?? ''));
        $unl = !empty($c['unlimited']) && $c['unlimited'] !== 'false' || md_is_unlimited($capRaw);
        $rows[] = ['name' => $cn, 'cap' => ($unl || $capRaw === '') ? null : md_money($capRaw), 'unlimited' => $unl ? 1 : 0,
                   'cap_group' => mb_substr(trim((string)($c['cap_group'] ?? '')), 0, 30) ?: null,
                   'franchise' => mb_substr(trim((string)($c['franchise'] ?? '')), 0, 60) ?: null, 'note' => mb_substr(trim((string)($c['note'] ?? '')), 0, 400) ?: null];
    }
    $grp = [];
    foreach ($rows as $r) if ($r['cap_group'] !== null && !isset($grp[$r['cap_group']]) && ($r['cap'] !== null || $r['unlimited'])) $grp[$r['cap_group']] = [$r['cap'], $r['unlimited']];
    foreach ($rows as &$r) if ($r['cap_group'] !== null && isset($grp[$r['cap_group']])) [$r['cap'], $r['unlimited']] = $grp[$r['cap_group']];
    unset($r);
    return $rows;
}

// ---------------- پیوست‌ها (بایگانی درمان و عمر / قراردادها / ...) ----------------
function md_tmp_dir() { $d = sys_get_temp_dir() . '/md_cov'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function md_archive_root() {
    if (!function_exists('archive_root')) require_once __DIR__ . '/_case_helpers.php';
    return archive_root(dirname(__DIR__)) . '/بایگانی درمان و عمر';
}
function md_safe_name($s) { $s = trim(preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u', ' ', (string)$s)); return mb_substr(preg_replace('/\s+/u', ' ', $s), 0, 80) ?: 'فایل'; }
// فایلِ موقت (توکن) => پوشه‌ی قرارداد در بایگانی + ردیفِ md_files
function md_file_attach($pdo, $contractId, $planId, $token, $name, $contractTitle = '', $planName = '') {
    $tok = preg_replace('/[^a-f0-9]/', '', (string)$token);
    $dir = md_tmp_dir();
    $ext = preg_replace('/[^a-z0-9]/', '', strtolower((string)@file_get_contents("$dir/$tok.ext")));
    $src = "$dir/$tok.$ext";
    if ($tok === '' || $ext === '' || !is_file($src)) throw new RuntimeException('فایلِ پیوست پیدا نشد؛ دوباره بارگذاری کنید.');
    $rel = 'قراردادها/' . md_safe_name('قرارداد ' . (int)$contractId . ($contractTitle !== '' ? ' - ' . $contractTitle : ''));
    $abs = md_archive_root() . '/' . $rel;
    if (!is_dir($abs) && !@mkdir($abs, 0775, true)) throw new RuntimeException('پوشه‌ی بایگانی ساخته نشد.');
    $base = md_safe_name(($planName !== '' ? $planName . ' - ' : '') . pathinfo((string)$name, PATHINFO_FILENAME));
    $fn = "$base.$ext"; $n = 2;
    while (is_file("$abs/$fn")) $fn = $base . ' (' . $n++ . ").$ext";
    if (!@rename($src, "$abs/$fn") && !(@copy($src, "$abs/$fn") && @unlink($src))) throw new RuntimeException('انتقالِ پیوست به بایگانی ممکن نشد.');
    @unlink("$dir/$tok.ext");
    $pdo->prepare("INSERT INTO md_files (contract_id, plan_id, name, rel, size, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
        ->execute([(int)$contractId, $planId ? (int)$planId : null, mb_substr((string)$name ?: $fn, 0, 250), "$rel/$fn", (int)@filesize("$abs/$fn"), $_SESSION['user_id'] ?? null]);
    md_log($pdo, 'FILE_ADD', 'ct:' . (int)$contractId, $fn);
    return (int)$pdo->lastInsertId();
}
function md_file_path($pdo, $id) {
    $st = $pdo->prepare("SELECT f.* FROM md_files f JOIN md_contracts c ON c.id = f.contract_id AND c.is_deleted = 0 WHERE f.id = ? AND f.is_deleted = 0"); $st->execute([(int)$id]);
    $f = $st->fetch();
    if (!$f || strpos($f['rel'], '..') !== false) return null;
    $abs = md_archive_root() . '/' . $f['rel'];
    return is_file($abs) ? ['row' => $f, 'abs' => $abs] : null;
}

// ---------------- خواندنِ نامه‌ی بیمه‌گر (عکس / PDF) با هوشِ مصنوعیِ تصویری ----------------
// همان سرویسی که برای «خواندنِ اسکن (هوش مصنوعی)» در «تنظیمات ← کلیدهای API» تنظیم شده
const MD_AI_COV_PROMPT = <<<TXT
The image(s) are an Iranian insurer's letter (e.g. Pasargad / پاسارگاد) announcing ONE group supplementary-health plan: a table «جدول میزان تعهدات سالیانه و حق بیمه ماهیانه هر نفر» with
columns row number (ردیف), coverage description (شرح پوشش بیمه‌ای) and yearly cap per person (تعهدات, Rial). Read it carefully and reply with ONE JSON object only (no prose, no markdown):
{"plan_name": plan label if written anywhere, e.g. handwritten «طرح ۲» → "طرح 2" (else ""),
 "insurer": "", "holder": insured company / group name (بیمه‌گذار / «شرکت گروه ...»), "letter_no": "", "letter_date": Jalali YYYY/MM/DD,
 "headcount": number of insured persons mentioned in the text (e.g. «۷۷۵۱ نفر») or null,
 "monthly_premium": monthly premium per person (حق بیمه ماهیانه هر نفر) in Rial digits or null, "franchise_pct": general franchise percent (e.g. «فرانشیز ۱۰٪» → 10) or null,
 "coverages": [{"row": table row number, "name": the full coverage description in Persian as printed, "cap": cap in Rial digits or null, "unlimited": true when the cap cell says «بدون سقف»,
               "shared_with": null, or — when ONE cap cell is merged/spans several rows — the row number of the FIRST row of that span (put the cap on that first row; the other rows get cap null and shared_with = that first row),
               "note": short condition written inside the row (e.g. «با احتساب ردیف ۱») or ""}],
 "terms": [every extra condition / commitment written under or around the table, one Persian sentence each, keeping amounts and counts]}
Rules: Latin digits; amounts in Rial WITHOUT separators (۲۵۰,۰۰۰,۰۰۰ → 250000000); keep table order; do not invent rows; ignore stamps, signatures and the insurer's address footer.
TXT;
function md_ai_cov_read($pdo, $path, $ext) {
    require_once __DIR__ . '/_vdoc_ai.php';
    if (!vdoc_ai_ready($pdo)) throw new RuntimeException('سرویسِ «خواندنِ اسکن (هوش مصنوعی)» در «تنظیمات ← کلیدهای API» تنظیم یا روشن نیست؛ پوشش‌ها را دستی یا از اکسل وارد کنید.');
    $c = vdoc_ai_conf($pdo);
    require_once __DIR__ . '/_ollama.php';
    $parts = [['type' => 'text', 'text' => MD_AI_COV_PROMPT]];
    $pages = 1;
    if ($ext === 'pdf') {
        require_once __DIR__ . '/_pdf.php';
        try { $pages = min(4, max(1, PdfDoc::open($path)->pageCount())); } catch (Throwable $e) { throw new RuntimeException('PDF خوانده نشد.'); }
    }
    for ($i = 1; $i <= $pages; $i++) {
        $img = vdoc_ai_page_image($path, $i);
        if (!$img) continue;
        $b64 = function_exists('ol_image_b64') ? ol_image_b64($img['bytes'], 2200) : base64_encode($img['bytes']);
        $sig = substr((string)base64_decode(substr($b64, 0, 16)), 0, 4);
        $mime = $sig === "\x89PNG" ? 'image/png' : ($sig === 'RIFF' ? 'image/webp' : 'image/jpeg');
        $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64]];
    }
    // PDFِ متنی (نه اسکن): متنِ صفحه‌ها به‌جای عکس
    if (count($parts) < 2 && $ext === 'pdf') {
        require_once __DIR__ . '/_pdf_text.php';
        $txt = trim(implode("\n\n", pdfx_text($path, 4)));
        if ($txt === '') throw new RuntimeException('از این PDF نه عکس بیرون آمد نه متن؛ عکسِ نامه (JPG/PNG) را بفرستید.');
        $parts[0]['text'] .= "\n\nThe letter text (extracted from a PDF, table cells may be out of order):\n" . mb_substr($txt, 0, 12000);
    }
    if (count($parts) < 2 && $ext !== 'pdf') throw new RuntimeException('عکس خوانده نشد.');
    $res = null; $j = null;
    foreach ([0, 1, 2] as $v) {   // بعضی مدل‌ها temperature/max_tokens را نمی‌پذیرند (HTTP 400)
        $body = ['model' => $c['model'], 'messages' => [['role' => 'user', 'content' => count($parts) > 1 ? $parts : $parts[0]['text']]]];
        if ($v === 0) { $body['temperature'] = 0; $body['max_tokens'] = 4000; } elseif ($v === 1) $body['max_completion_tokens'] = 4000;
        $res = sms_http('POST', $c['base'] . '/chat/completions', json_encode($body, JSON_UNESCAPED_UNICODE),
                        ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $c['token']], max(90, (int)$c['timeout']));
        $j = json_decode((string)$res['body'], true);
        if ($res['error'] === '' && $res['http'] >= 200 && $res['http'] < 300) break;
        if ($res['http'] !== 400 && $res['http'] !== 422) break;
    }
    if ($res['error'] !== '' || $res['http'] < 200 || $res['http'] >= 300) {
        $msg = is_array($j) ? (string)($j['error']['message'] ?? ($j['message'] ?? '')) : '';
        throw new RuntimeException('سرویسِ هوشِ مصنوعی پاسخ نداد' . ($res['error'] !== '' ? ': ' . $res['error'] : ' (HTTP ' . $res['http'] . ($msg !== '' ? ' — ' . mb_substr($msg, 0, 160) : '') . ')'));
    }
    $txt = $j['choices'][0]['message']['content'] ?? '';
    if (is_array($txt)) $txt = implode('', array_map(function ($p) { return (string)($p['text'] ?? ''); }, $txt));
    $o = preg_match('/\{[\s\S]*\}/u', (string)$txt, $m) ? json_decode($m[0], true) : null;
    if (!is_array($o)) throw new RuntimeException('پاسخِ هوشِ مصنوعی قابلِ استفاده نبود؛ دوباره امتحان کنید یا دستی وارد کنید.');
    return md_ai_cov_normalize($o);
}
// پاسخِ هوشِ مصنوعی => ساختارِ ویرایشگرِ طرح (سقفِ مشترک = «ردیف N»)
function md_ai_cov_normalize(array $o) {
    $d = function ($v) { return strtr((string)$v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']); };
    $num = function ($v) use ($d) { $v = preg_replace('/[^0-9]/', '', $d($v)); return $v === '' ? null : (int)$v; };
    $t = function ($v, $n) use ($d) { return mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$v))), 0, $n); };
    $rows = []; $byRow = [];
    foreach (array_values(is_array($o['coverages'] ?? null) ? $o['coverages'] : []) as $i => $c) {
        if (!is_array($c) || ($name = $t($c['name'] ?? '', 250)) === '') continue;
        $rn = $num($c['row'] ?? '') ?: ($i + 1);
        $unl = !empty($c['unlimited']) || md_is_unlimited($c['cap'] ?? '');
        $rows[] = ['row' => $rn, 'name' => $name, 'cap' => $unl ? null : $num($c['cap'] ?? ''), 'unlimited' => $unl, 'shared' => $num($c['shared_with'] ?? ''), 'note' => $t($c['note'] ?? '', 400)];
        $byRow[$rn] = true;
    }
    // ردیف‌های یک سقفِ مشترک: برچسبِ «ردیف N» (N = اولین ردیفِ آن سقف)
    $lead = [];
    foreach ($rows as $r) if ($r['shared'] && $r['shared'] !== $r['row'] && isset($byRow[$r['shared']])) $lead[$r['shared']] = true;
    $cov = array_map(function ($r) use ($lead) {
        $g = ($r['shared'] && $r['shared'] !== $r['row']) ? $r['shared'] : (isset($lead[$r['row']]) ? $r['row'] : null);
        return ['name' => $r['name'], 'cap' => $r['cap'], 'unlimited' => $r['unlimited'], 'cap_group' => $g ? 'ردیف ' . strtr((string)$g, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']) : '', 'franchise' => '', 'note' => $r['note']];
    }, $rows);
    $terms = array_values(array_filter(array_map(function ($x) use ($t) { return $t($x, 600); }, is_array($o['terms'] ?? null) ? $o['terms'] : [(string)($o['terms'] ?? '')]), 'strlen'));
    $fp = $d($o['franchise_pct'] ?? '');
    return ['plan_name' => $t($o['plan_name'] ?? '', 120), 'insurer' => $t($o['insurer'] ?? '', 80), 'holder' => $t($o['holder'] ?? '', 190),
            'letter_no' => $t($d($o['letter_no'] ?? ''), 60), 'letter_j' => md_jdate($o['letter_date'] ?? '')[0] ?: '', 'headcount' => $num($o['headcount'] ?? ''),
            'monthly_premium' => $num($o['monthly_premium'] ?? ''), 'franchise_pct' => is_numeric($fp) ? (float)$fp : null, 'coverages' => $cov, 'terms' => $terms];
}
