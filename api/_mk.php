<?php
// فایل: api/_mk.php
// «بازاریابی و فروش»: بازاریابان و کارشناسانِ تماس، انواعِ بیمه‌نامه با کارمزد و پله‌های سهمِ بازاریاب،
// قوانینِ پاداش/تخفیفِ مشتری، سطح‌بندی، حقوقِ ثابت و کفِ فروش (کلی و اختصاصی)، فروش‌ها، درآمدِ ماهانه، PDF و اکسل.
// همه‌ی جدول‌ها خودکار ساخته می‌شوند و بارِ اول با یک پیکربندیِ پیش‌فرض (قابلِ ویرایش) پر می‌شوند.
//
// حق بازاریابیِ هر فروش = حق‌بیمه × درصدِ کارمزدِ آن نوع × سهمِ بازاریاب از کارمزد (طبقِ پله‌ی حق‌بیمه) + مبلغِ ثابتِ پله
// درآمدِ ماه = حقوقِ ثابت (اگر فروش به کف رسید) + جمعِ حق بازاریابی + اضافه‌ی فروشِ بالای کف + پاداشِ حجمِ فروش

require_once __DIR__ . '/_case_helpers.php';

const MK_KINDS = ['MARKETER' => 'بازاریاب', 'CALLER' => 'کارشناس تماس'];
const MK_PAY = ['any' => 'فرقی نمی‌کند', 'cash' => 'نقدی', 'inst' => 'اقساطی'];
const MK_CUST = ['any' => 'فرقی نمی‌کند', 'new' => 'مشتریِ جدید', 'renewal' => 'تمدید / مشتریِ قبلی'];
const MK_REWARD_KINDS = ['percent' => 'درصد تخفیف', 'amount' => 'مبلغ تخفیف', 'gift' => 'هدیه'];
const MK_M = 1000000;   // یک میلیون ریال

function mk_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS mk_people (id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(190) NOT NULL, mobile VARCHAR(20) NOT NULL, kind VARCHAR(12) NOT NULL DEFAULT 'MARKETER',
            national_id VARCHAR(20) NULL, level_id INT NULL, base_salary BIGINT NULL, base_floor BIGINT NULL, target BIGINT NULL, over_pct DECIMAL(6,2) NULL, volume_json TEXT NULL,
            active TINYINT NOT NULL DEFAULT 1, is_deleted TINYINT NOT NULL DEFAULT 0, note TEXT NULL, bale_chat_id BIGINT NULL, bot_linked_at DATETIME NULL, created_at DATETIME NULL, created_by INT NULL,
            KEY k_mobile (mobile), KEY k_chat (bale_chat_id)) $o",
        "CREATE TABLE IF NOT EXISTS mk_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, icon VARCHAR(16) NULL, commission_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
            tiers_json TEXT NULL, min_premium BIGINT NOT NULL DEFAULT 0, max_premium BIGINT NULL, active TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0, note VARCHAR(500) NULL) $o",
        "CREATE TABLE IF NOT EXISTS mk_levels (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, color VARCHAR(10) NULL, image_path VARCHAR(300) NULL, min_sales BIGINT NOT NULL DEFAULT 0,
            max_sales BIGINT NULL, base_salary BIGINT NULL, base_floor BIGINT NULL, target BIGINT NULL, over_pct DECIMAL(6,2) NULL, fee_bonus_pct DECIMAL(6,2) NULL, perks VARCHAR(500) NULL, sort INT NOT NULL DEFAULT 0) $o",
        "CREATE TABLE IF NOT EXISTS mk_rewards (id INT AUTO_INCREMENT PRIMARY KEY, type_id INT NULL, title VARCHAR(190) NOT NULL, min_premium BIGINT NOT NULL DEFAULT 0, max_premium BIGINT NULL,
            pay_mode VARCHAR(10) NOT NULL DEFAULT 'any', customer VARCHAR(10) NOT NULL DEFAULT 'any', min_count INT NOT NULL DEFAULT 1, reward_kind VARCHAR(10) NOT NULL DEFAULT 'percent',
            value DECIMAL(16,2) NOT NULL DEFAULT 0, max_amount BIGINT NULL, gift_text VARCHAR(300) NULL, stackable TINYINT NOT NULL DEFAULT 0, priority INT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1, note VARCHAR(500) NULL) $o",
        "CREATE TABLE IF NOT EXISTS mk_sales (id INT AUTO_INCREMENT PRIMARY KEY, person_id INT NOT NULL, type_id INT NULL, type_name VARCHAR(120) NULL, premium BIGINT NOT NULL,
            commission_pct DECIMAL(6,2) NULL, commission_amount BIGINT NOT NULL DEFAULT 0, share_pct DECIMAL(6,2) NULL, fee_amount BIGINT NOT NULL DEFAULT 0, customer_name VARCHAR(190) NULL,
            customer_mobile VARCHAR(20) NULL, policy_no VARCHAR(60) NULL, note VARCHAR(500) NULL, sale_j CHAR(10) NULL, sale_g DATE NULL, jy INT NOT NULL, jm INT NOT NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'PANEL', status VARCHAR(8) NOT NULL DEFAULT 'OK', created_by_user INT NULL, created_at DATETIME NULL, voided_at DATETIME NULL, voided_by INT NULL,
            void_reason VARCHAR(300) NULL, KEY k_person (person_id, jy, jm), KEY k_month (jy, jm)) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[mk_ensure] ' . $e->getMessage()); } }
    try { if (!intval($pdo->query("SELECT COUNT(*) FROM mk_types")->fetchColumn())) mk_seed($pdo); } catch (Throwable $e) { error_log('[mk_seed] ' . $e->getMessage()); }
}

// ---------------------------------------------------------------------
//  پیکربندیِ پیش‌فرض (مبالغ به ریال؛ از پنل قابلِ ویرایش، حذف و افزودن)
// ---------------------------------------------------------------------
function mk_seed($pdo) {
    $M = MK_M;
    $t = function ($a, $b, $share, $fixed = 0) { return ['from' => $a, 'to' => $b, 'share' => $share, 'fixed' => $fixed]; };
    // [نام، آیکن، کارمزدِ نمایندگی٪، پله‌ها (سهمِ بازاریاب از کارمزد)، حداقلِ حق‌بیمه، توضیح]
    $types = [
        ['بیمه شخص ثالث خودرو', '🚗', 6, [$t(0, 30 * $M, 30), $t(30 * $M, 80 * $M, 35), $t(80 * $M, null, 40)], 5 * $M, 'کارمزدِ ثالث محدود است؛ سهمِ بازاریاب با حق‌بیمه‌ی بالاتر بیشتر می‌شود.'],
        ['بیمه بدنه خودرو', '🛡', 15, [$t(0, 50 * $M, 30), $t(50 * $M, 150 * $M, 35), $t(150 * $M, null, 40, 1 * $M)], 5 * $M, ''],
        ['بیمه موتورسیکلت (ثالث)', '🏍', 6, [$t(0, null, 30)], 1 * $M, ''],
        ['بیمه عمر و سرمایه‌گذاری (سال اول)', '❤️', 30, [$t(0, 50 * $M, 30), $t(50 * $M, 150 * $M, 35), $t(150 * $M, 300 * $M, 40), $t(300 * $M, null, 45, 3 * $M)], 12 * $M, 'حق‌بیمه‌ی سالانه‌ی سالِ اول را وارد کنید.'],
        ['بیمه عمر (تمدید / سال‌های بعد)', '💗', 8, [$t(0, null, 25)], 6 * $M, ''],
        ['بیمه درمان تکمیلی انفرادی و خانواده', '🩺', 8, [$t(0, 50 * $M, 30), $t(50 * $M, null, 35)], 5 * $M, ''],
        ['بیمه آتش‌سوزی منازل', '🏠', 25, [$t(0, 10 * $M, 35), $t(10 * $M, null, 40)], 1 * $M, ''],
        ['بیمه آتش‌سوزی تجاری و صنعتی', '🏭', 15, [$t(0, 100 * $M, 30), $t(100 * $M, null, 35)], 10 * $M, ''],
        ['بیمه مسئولیت (کارفرما، مدنی، حرفه‌ای)', '⚖️', 20, [$t(0, 50 * $M, 30), $t(50 * $M, null, 35)], 3 * $M, ''],
        ['بیمه حوادث انفرادی', '🦺', 25, [$t(0, null, 35)], 1 * $M, ''],
        ['بیمه مسافرتی خارج از کشور', '✈️', 25, [$t(0, null, 35)], 1 * $M, ''],
        ['بیمه پزشکان و پیراپزشکان', '👨‍⚕️', 20, [$t(0, null, 35)], 3 * $M, ''],
        ['بیمه باربری', '📦', 10, [$t(0, null, 30)], 2 * $M, ''],
        ['بیمه تجهیزات الکترونیک و مهندسی', '🔌', 15, [$t(0, null, 30)], 3 * $M, ''],
    ];
    $ids = [];
    $ins = $pdo->prepare("INSERT INTO mk_types (name, icon, commission_pct, tiers_json, min_premium, active, sort, note) VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
    foreach ($types as $k => [$n, $ic, $c, $tiers, $min, $note]) { $ins->execute([$n, $ic, $c, json_encode($tiers), $min, $k + 1, $note ?: null]); $ids[$n] = intval($pdo->lastInsertId()); }
    // قوانینِ پاداش/تخفیف (برگرفته از رویه‌های رایجِ بازار؛ تخفیف از محلِ کارمزد است)
    $r = $pdo->prepare("INSERT INTO mk_rewards (type_id, title, min_premium, max_premium, pay_mode, customer, min_count, reward_kind, value, max_amount, gift_text, stackable, priority, active, note)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
    foreach ([
        ['بیمه بدنه خودرو', 'تخفیفِ پرداختِ نقدی', 0, 400 * $M, 'cash', 'any', 1, 'percent', 10, null, null, 0, 1, ''],
        ['بیمه بدنه خودرو', 'بدنه‌ی بالای ۴۰ میلیون تومان، نقدی', 400 * $M, null, 'cash', 'any', 1, 'percent', 15, 120 * $M, null, 0, 2, ''],
        ['بیمه بدنه خودرو', 'وفاداری (تمدید نزدِ ما)', 0, null, 'any', 'renewal', 1, 'percent', 5, 40 * $M, null, 1, 0, 'روی تخفیف‌های دیگر هم اضافه می‌شود'],
        ['بیمه بدنه خودرو', 'بدنه + ثالث با هم', 0, null, 'any', 'any', 2, 'gift', 0, null, 'بیمه‌ی حوادثِ راننده (یک‌ساله) رایگان', 1, 0, 'تعداد = تعدادِ بیمه‌نامه‌های همزمان'],
        ['بیمه شخص ثالث خودرو', 'تمدیدِ ثالث نزدِ ما', 0, null, 'any', 'renewal', 1, 'gift', 0, null, 'کارت‌هدیه‌ی کارواش / خدماتِ خودرو', 1, 0, ''],
        ['بیمه شخص ثالث خودرو', 'ناوگان (۵ خودرو و بیشتر)', 0, null, 'any', 'any', 5, 'percent', 3, null, null, 0, 1, ''],
        ['بیمه عمر و سرمایه‌گذاری (سال اول)', 'عمر ۵۰ تا ۱۰۰ میلیون تومان در سال', 500 * $M, 1000 * $M, 'any', 'any', 1, 'gift', 0, null, 'بیمه‌ی حوادثِ انفرادی (سرمایه ۵۰۰ میلیون تومان) رایگان', 1, 0, ''],
        ['بیمه عمر و سرمایه‌گذاری (سال اول)', 'عمر ۱۰۰ تا ۲۰۰ میلیون تومان در سال', 1000 * $M, 2000 * $M, 'any', 'any', 1, 'percent', 3, null, 'بیمه‌ی آتش‌سوزیِ منزل (یک‌ساله) رایگان', 0, 1, ''],
        ['بیمه عمر و سرمایه‌گذاری (سال اول)', 'عمر بالای ۲۰۰ میلیون تومان در سال', 2000 * $M, null, 'any', 'any', 1, 'percent', 5, null, 'بیمه‌ی آتش‌سوزیِ منزل + حوادثِ انفرادی رایگان', 0, 2, ''],
        ['بیمه عمر و سرمایه‌گذاری (سال اول)', 'پرداختِ سالانه به‌جای ماهانه', 0, null, 'cash', 'any', 1, 'percent', 2, null, null, 1, 0, 'پرداختِ یکجای حق‌بیمه‌ی سال'],
        ['بیمه آتش‌سوزی منازل', 'پرداختِ نقدی', 0, null, 'cash', 'any', 1, 'percent', 10, null, null, 0, 1, ''],
        ['بیمه درمان تکمیلی انفرادی و خانواده', 'خانواده (۳ نفر و بیشتر)', 0, null, 'any', 'any', 3, 'percent', 5, null, null, 0, 1, ''],
        ['بیمه درمان تکمیلی انفرادی و خانواده', 'گروهی (۱۰ نفر و بیشتر)', 0, null, 'any', 'any', 10, 'percent', 10, null, null, 0, 2, ''],
        ['بیمه مسافرتی خارج از کشور', 'سفرِ گروهی (۴ نفر و بیشتر)', 0, null, 'any', 'any', 4, 'percent', 10, null, null, 0, 1, ''],
        ['بیمه حوادث انفرادی', 'دو نفر و بیشتر', 0, null, 'any', 'any', 2, 'percent', 5, null, null, 0, 1, ''],
        ['بیمه مسئولیت (کارفرما، مدنی، حرفه‌ای)', 'پرداختِ نقدی', 0, null, 'cash', 'any', 1, 'percent', 7, null, null, 0, 1, ''],
        [null, 'مشتریِ جدید با حق‌بیمه‌ی بالای ۱۰ میلیون تومان', 100 * $M, null, 'any', 'new', 1, 'gift', 0, null, 'کارت‌هدیه‌ی ۵۰۰ هزار تومانی', 1, 0, 'برای همه‌ی انواع'],
    ] as $x) {
        $x[0] = $x[0] === null ? null : ($ids[$x[0]] ?? null);
        $r->execute($x);
    }
    // سطح‌ها (بر اساسِ فروشِ ماه)
    $l = $pdo->prepare("INSERT INTO mk_levels (name, color, min_sales, max_sales, base_salary, base_floor, target, over_pct, fee_bonus_pct, perks, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ([
        ['برنزی', '#b45309', 0, 300 * $M, null, null, null, null, 0, 'شروعِ کار', 1],
        ['نقره‌ای', '#64748b', 300 * $M, 600 * $M, null, null, null, null, 5, '۵٪ پاداش روی حق بازاریابی', 2],
        ['طلایی', '#ca8a04', 600 * $M, 1200 * $M, 120 * $M, null, null, 2.5, 10, 'حقوقِ ثابتِ بیشتر و ۱۰٪ پاداش', 3],
        ['پلاتینیوم', '#0e7490', 1200 * $M, 2500 * $M, 150 * $M, null, null, 3, 15, '', 4],
        ['الماس', '#7c3aed', 2500 * $M, null, 200 * $M, null, null, 3.5, 20, '', 5],
    ] as $x) $l->execute($x);
    if (!life_like_setting($pdo, 'mk_settings')) mk_settings_save($pdo, []);
}
function life_like_setting($pdo, $k) {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
function mk_set_raw($pdo, $k, $v) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, (string)$v]);
}

// ---------------------------------------------------------------------
//  تنظیماتِ کلی
// ---------------------------------------------------------------------
function mk_defaults() {
    $M = MK_M;
    return [
        'base_salary' => 100 * $M,      // حقوقِ ثابتِ ماهانه
        'base_floor' => 300 * $M,       // کفِ فروشِ ماه برای گرفتنِ حقوقِ ثابت
        'monthly_target' => 600 * $M,   // هدفِ ماهانه
        'over_pct' => 2,                // درصدِ فروشِ بالای کف که به حقوق اضافه می‌شود
        'level_mode' => 'auto',         // auto: سطح با فروشِ همان ماه | manual: فقط سطحِ تعیین‌شده برای هر نفر
        // پاداشِ حجمِ فروش (روی جمعِ حق بازاریابیِ ماه): از ... تا ... => درصد و/یا مبلغ
        'volume_tiers' => [['from' => 500 * $M, 'to' => 1000 * $M, 'pct' => 10, 'amount' => 0], ['from' => 1000 * $M, 'to' => 2000 * $M, 'pct' => 20, 'amount' => 0],
                           ['from' => 2000 * $M, 'to' => null, 'pct' => 30, 'amount' => 10 * $M]],
        'sale_needs_customer' => 0,     // ثبتِ نامِ مشتری در ربات اجباری باشد؟
        'cap_to_commission' => 1,       // جمعِ تخفیفِ مشتری از کارمزدِ نمایندگی بیشتر نشود
        'report_title' => 'بیمه با ما',
    ];
}
function mk_settings($pdo) {
    $s = json_decode((string)life_like_setting($pdo, 'mk_settings'), true);
    return array_merge(mk_defaults(), is_array($s) ? $s : []);
}
function mk_settings_save($pdo, array $in) {
    $s = mk_settings($pdo);
    foreach (['base_salary', 'base_floor', 'monthly_target'] as $k) if (array_key_exists($k, $in)) $s[$k] = max(0, money_to_int($in[$k]));
    if (array_key_exists('over_pct', $in)) $s['over_pct'] = max(0, min(100, floatval(p2e_digits((string)$in['over_pct']))));
    if (array_key_exists('level_mode', $in)) $s['level_mode'] = $in['level_mode'] === 'manual' ? 'manual' : 'auto';
    if (array_key_exists('sale_needs_customer', $in)) $s['sale_needs_customer'] = !empty($in['sale_needs_customer']) ? 1 : 0;
    if (array_key_exists('cap_to_commission', $in)) $s['cap_to_commission'] = !empty($in['cap_to_commission']) ? 1 : 0;
    if (array_key_exists('report_title', $in)) $s['report_title'] = mb_substr(trim((string)$in['report_title']), 0, 80) ?: 'بیمه با ما';
    if (array_key_exists('volume_tiers', $in)) $s['volume_tiers'] = mk_clean_volume($in['volume_tiers']);
    mk_set_raw($pdo, 'mk_settings', json_encode($s, JSON_UNESCAPED_UNICODE));
    return $s;
}
function mk_clean_volume($rows) {
    $o = [];
    foreach ((array)$rows as $r) {
        $from = money_to_int($r['from'] ?? 0); $to = trim((string)($r['to'] ?? '')) === '' ? null : money_to_int($r['to']);
        $pct = max(0, min(500, floatval(p2e_digits((string)($r['pct'] ?? 0))))); $amt = max(0, money_to_int($r['amount'] ?? 0));
        if (!$pct && !$amt) continue;
        $o[] = ['from' => $from, 'to' => $to, 'pct' => $pct, 'amount' => $amt];
    }
    usort($o, function ($a, $b) { return $a['from'] <=> $b['from']; });
    return $o;
}
function mk_clean_tiers($rows) {
    $o = [];
    foreach ((array)$rows as $r) {
        $o[] = ['from' => money_to_int($r['from'] ?? 0), 'to' => trim((string)($r['to'] ?? '')) === '' ? null : money_to_int($r['to']),
                'share' => max(0, min(100, floatval(p2e_digits((string)($r['share'] ?? 0))))), 'fixed' => max(0, money_to_int($r['fixed'] ?? 0))];
    }
    usort($o, function ($a, $b) { return $a['from'] <=> $b['from']; });
    return $o ?: [['from' => 0, 'to' => null, 'share' => 0, 'fixed' => 0]];
}

// ---------------------------------------------------------------------
//  خواندن
// ---------------------------------------------------------------------
function mk_types($pdo, $activeOnly = false) {
    $o = [];
    foreach ($pdo->query("SELECT * FROM mk_types" . ($activeOnly ? " WHERE active = 1" : '') . " ORDER BY sort, id") as $t) {
        $t['id'] = intval($t['id']); $t['commission_pct'] = floatval($t['commission_pct']); $t['min_premium'] = intval($t['min_premium']);
        $t['max_premium'] = $t['max_premium'] === null ? null : intval($t['max_premium']); $t['active'] = intval($t['active']);
        $t['tiers'] = json_decode((string)$t['tiers_json'], true) ?: [['from' => 0, 'to' => null, 'share' => 0, 'fixed' => 0]];
        unset($t['tiers_json']);
        $o[] = $t;
    }
    return $o;
}
function mk_type($pdo, $id) { foreach (mk_types($pdo) as $t) if ($t['id'] === intval($id)) return $t; return null; }
function mk_levels($pdo) {
    $o = [];
    foreach ($pdo->query("SELECT * FROM mk_levels ORDER BY sort, min_sales, id") as $l) {
        foreach (['id', 'min_sales', 'sort'] as $k) $l[$k] = intval($l[$k]);
        foreach (['max_sales', 'base_salary', 'base_floor', 'target'] as $k) $l[$k] = $l[$k] === null ? null : intval($l[$k]);
        foreach (['over_pct', 'fee_bonus_pct'] as $k) $l[$k] = $l[$k] === null ? null : floatval($l[$k]);
        $o[] = $l;
    }
    return $o;
}
function mk_level_emoji($idx) { return ['🥉', '🥈', '🥇', '💠', '💎', '👑', '🚀'][$idx] ?? '⭐'; }
function mk_person($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM mk_people WHERE id = ? AND is_deleted = 0");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function mk_people($pdo, $all = false) {
    return $pdo->query("SELECT * FROM mk_people WHERE is_deleted = 0" . ($all ? '' : ' AND active = 1') . " ORDER BY full_name")->fetchAll();
}
function mk_by_phone($pdo, $phone) {
    $out = [];
    foreach ($pdo->query("SELECT * FROM mk_people WHERE is_deleted = 0 AND active = 1")->fetchAll() as $p) if (auth_norm_phone($p['mobile']) === $phone) $out[] = $p;
    return $out;
}

// ---------------------------------------------------------------------
//  محاسبه‌ها
// ---------------------------------------------------------------------
function mk_fee(array $type, $premium) {
    $premium = intval($premium);
    $comm = intval(round($premium * $type['commission_pct'] / 100));
    $tier = null;
    foreach ($type['tiers'] as $t) if ($premium >= intval($t['from']) && ($t['to'] === null || $premium < intval($t['to']))) $tier = $t;
    $warn = '';
    if ($premium < $type['min_premium']) $warn = 'حق‌بیمه از حداقلِ این نوع (' . life_fa_money($type['min_premium']) . ' ریال) کمتر است؛ حق بازاریابی تعلق نمی‌گیرد.';
    if ($type['max_premium'] && $premium > $type['max_premium']) $warn = 'حق‌بیمه از سقفِ این نوع (' . life_fa_money($type['max_premium']) . ') بیشتر است؛ با مدیر هماهنگ کنید.';
    $share = ($tier && !$warn) ? floatval($tier['share']) : 0;
    $fee = ($tier && !$warn) ? intval(round($comm * $share / 100)) + intval($tier['fixed'] ?? 0) : 0;
    return ['commission' => $comm, 'share' => $share, 'fee' => $fee, 'tier' => $tier, 'warn' => $warn];
}
function life_fa_money($n) { return strtr(number_format(intval($n)), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function mk_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function mk_now_jm() { [$jy, $jm] = jalali_from_gregorian_ts(time()); return [$jy, $jm]; }
function mk_today_j() { [$jy, $jm, $jd] = jalali_from_gregorian_ts(time()); return sprintf('%04d/%02d/%02d', $jy, $jm, $jd); }
function mk_month_label($jy, $jm) { return jalali_month_name($jm) . ' ' . mk_fa($jy); }

// فروش‌های یک نفر (یا همه) در یک ماه/سال، گروه‌بندی‌شده بر اساسِ نوع
function mk_month_data($pdo, $pid, $jy, $jm = null) {
    $w = "status = 'OK' AND jy = ?"; $a = [intval($jy)];
    if ($jm) { $w .= " AND jm = ?"; $a[] = intval($jm); }
    if ($pid) { $w .= " AND person_id = ?"; $a[] = intval($pid); }
    $st = $pdo->prepare("SELECT type_id, MAX(type_name) AS type_name, COUNT(*) AS n, SUM(premium) AS premium, SUM(fee_amount) AS fee, SUM(commission_amount) AS comm FROM mk_sales WHERE $w GROUP BY type_id ORDER BY premium DESC");
    $st->execute($a);
    $rows = array_map(function ($r) { return ['type_id' => intval($r['type_id']), 'type_name' => $r['type_name'], 'n' => intval($r['n']), 'premium' => intval($r['premium']), 'fee' => intval($r['fee']), 'comm' => intval($r['comm'])]; }, $st->fetchAll());
    return ['rows' => $rows, 'n' => array_sum(array_column($rows, 'n')), 'premium' => array_sum(array_column($rows, 'premium')), 'fee' => array_sum(array_column($rows, 'fee')), 'comm' => array_sum(array_column($rows, 'comm'))];
}
// قوانینِ مؤثرِ یک نفر برای فروشِ $sales: اختصاصیِ فرد > سطح > کلی
function mk_rules($pdo, array $person, $sales) {
    $s = mk_settings($pdo);
    $levels = mk_levels($pdo);
    $level = null; $idx = -1;
    if (!empty($person['level_id'])) foreach ($levels as $i => $l) if ($l['id'] === intval($person['level_id'])) { $level = $l; $idx = $i; }
    if (!$level && $s['level_mode'] === 'auto') foreach ($levels as $i => $l) if ($sales >= $l['min_sales'] && ($l['max_sales'] === null || $sales < $l['max_sales'])) { $level = $l; $idx = $i; }
    $next = null;
    if ($s['level_mode'] === 'auto' && empty($person['level_id'])) foreach ($levels as $i => $l) if ($l['min_sales'] > $sales) { $next = $l + ['emoji' => mk_level_emoji($i)]; break; }
    $pick = function ($k, $sk) use ($person, $level, $s) {
        if (isset($person[$k]) && $person[$k] !== null && $person[$k] !== '') return $person[$k];
        if ($level && $level[$k] !== null) return $level[$k];
        return $s[$sk];
    };
    $vol = json_decode((string)($person['volume_json'] ?? ''), true);
    return [
        'level' => $level ? $level + ['emoji' => mk_level_emoji($idx)] : null, 'next_level' => $next,
        'base_salary' => intval($pick('base_salary', 'base_salary')), 'base_floor' => intval($pick('base_floor', 'base_floor')),
        'target' => intval($pick('target', 'monthly_target')), 'over_pct' => floatval($pick('over_pct', 'over_pct')),
        'fee_bonus_pct' => $level ? floatval($level['fee_bonus_pct'] ?? 0) : 0,
        'volume_tiers' => is_array($vol) && $vol ? $vol : $s['volume_tiers'], 'custom' => (bool)(is_array($vol) && $vol) || $person['base_salary'] !== null || $person['base_floor'] !== null || $person['target'] !== null || $person['over_pct'] !== null,
    ];
}
// درآمدِ کاملِ یک نفر در یک ماه
function mk_income($pdo, array $person, $jy, $jm) {
    $d = mk_month_data($pdo, $person['id'], $jy, $jm);
    $S = $d['premium'];
    $R = mk_rules($pdo, $person, $S);
    $baseOk = $S >= $R['base_floor'] && $R['base_floor'] >= 0;
    $base = $baseOk ? $R['base_salary'] : 0;
    $over = $S > $R['base_floor'] ? intval(round(($S - $R['base_floor']) * $R['over_pct'] / 100)) : 0;
    $vt = null;
    foreach ($R['volume_tiers'] as $t) if ($S >= intval($t['from']) && ($t['to'] === null || $S < intval($t['to']))) $vt = $t;
    $volume = $vt ? intval(round($d['fee'] * floatval($vt['pct']) / 100)) + intval($vt['amount']) : 0;
    $levelBonus = intval(round($d['fee'] * $R['fee_bonus_pct'] / 100));
    $total = $base + $d['fee'] + $over + $volume + $levelBonus;
    $msgs = [];
    if ($baseOk) $msgs[] = '✅ فروشِ شما به کفِ ' . life_fa_money($R['base_floor']) . ' ریال رسید و حقوقِ ثابت (' . life_fa_money($R['base_salary']) . ' ریال) به شما تعلق می‌گیرد.';
    else $msgs[] = '⏳ تا رسیدن به حقوقِ ثابت (' . life_fa_money($R['base_salary']) . ' ریال) ' . life_fa_money($R['base_floor'] - $S) . ' ریال فروشِ دیگر لازم است.';
    if ($R['over_pct'] > 0) $msgs[] = $over > 0 ? '➕ ' . mk_fa($R['over_pct']) . '٪ِ فروشِ بالای کف = ' . life_fa_money($over) . ' ریال به حقوق‌تان اضافه شد.'
        : '➕ از فروشِ ' . life_fa_money($R['base_floor']) . ' به بالا، ' . mk_fa($R['over_pct']) . '٪ِ هر فروش به حقوق‌تان اضافه می‌شود.';
    // پله‌ی بعدیِ پاداشِ حجم
    foreach ($R['volume_tiers'] as $t) if (intval($t['from']) > $S) { $msgs[] = '🚀 با ' . life_fa_money(intval($t['from']) - $S) . ' ریال فروشِ بیشتر، ' . ($t['pct'] ? mk_fa($t['pct']) . '٪ پاداش روی حق بازاریابی' : '') . ($t['amount'] ? ($t['pct'] ? ' + ' : '') . life_fa_money($t['amount']) . ' ریال' : '') . ' می‌گیرید.'; break; }
    if ($R['next_level']) $msgs[] = $R['next_level']['emoji'] . ' تا سطحِ «' . $R['next_level']['name'] . '»: ' . life_fa_money($R['next_level']['min_sales'] - $S) . ' ریال فروش.';
    return ['jy' => intval($jy), 'jm' => intval($jm), 'label' => mk_month_label($jy, $jm), 'data' => $d, 'rules' => $R, 'base' => $base, 'base_ok' => $baseOk, 'over' => $over,
            'volume' => $volume, 'volume_tier' => $vt, 'level_bonus' => $levelBonus, 'total' => $total,
            'target_pct' => $R['target'] ? round($S * 100 / $R['target'], 1) : 0, 'to_target' => max(0, $R['target'] - $S), 'messages' => $msgs];
}

// ثبتِ فروش
function mk_sale_add($pdo, array $person, array $type, $premium, array $extra = []) {
    $premium = intval($premium);
    if ($premium <= 0) return ['error' => 'حق‌بیمه را درست وارد کنید.'];
    $f = mk_fee($type, $premium);
    $saleJ = !empty($extra['sale_j']) ? $extra['sale_j'] : mk_today_j();
    if (!preg_match('/^(1[34]\d{2})\/(\d{2})\/(\d{2})$/', $saleJ, $m)) return ['error' => 'تاریخِ فروش درست نیست.'];
    $g = date('Y-m-d', jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])));
    $pdo->prepare("INSERT INTO mk_sales (person_id, type_id, type_name, premium, commission_pct, commission_amount, share_pct, fee_amount, customer_name, customer_mobile, policy_no, note,
                    sale_j, sale_g, jy, jm, source, status, created_by_user, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OK', ?, NOW())")
        ->execute([$person['id'], $type['id'], $type['name'], $premium, $type['commission_pct'], $f['commission'], $f['share'], $f['fee'],
                   ($extra['customer_name'] ?? '') ?: null, ($extra['customer_mobile'] ?? '') ?: null, ($extra['policy_no'] ?? '') ?: null, ($extra['note'] ?? '') ?: null,
                   $saleJ, $g, intval($m[1]), intval($m[2]), $extra['source'] ?? 'PANEL', $extra['by'] ?? null]);
    return ['id' => intval($pdo->lastInsertId()), 'fee' => $f, 'jy' => intval($m[1]), 'jm' => intval($m[2])];
}
// پیامِ بعد از ثبتِ فروش
function mk_sale_message($pdo, array $person, array $type, $premium, array $fee, $jy, $jm) {
    $inc = mk_income($pdo, $person, $jy, $jm);
    $S = $inc['data']['premium'];
    $t = "✅ فروش ثبت شد.\n" . ($type['icon'] ? $type['icon'] . ' ' : '') . $type['name'] . ' · حق‌بیمه ' . life_fa_money($premium) . " ریال\n"
       . '💰 حق بازاریابیِ شما از این فروش: ' . life_fa_money($fee['fee']) . ' ریال' . ($fee['share'] ? ' (' . mk_fa($fee['share']) . '٪ از کارمزد)' : '')
       . ($fee['warn'] ? "\n⚠️ " . $fee['warn'] : '')
       . "\n\n📈 فروشِ " . $inc['label'] . ': ' . life_fa_money($S) . ' ریال · حق بازاریابی: ' . life_fa_money($inc['data']['fee']) . ' ریال';
    if ($inc['rules']['target']) $t .= "\n🎯 " . mk_fa($inc['target_pct']) . '٪ از هدفِ ماهانه (' . life_fa_money($inc['rules']['target']) . ' ریال)' . ($inc['to_target'] ? "\n   تا هدفِ ماهانه: " . life_fa_money($inc['to_target']) . ' ریال' : "\n   🎉 به هدفِ ماهانه رسیدید!");
    if ($inc['rules']['level']) $t .= "\n" . $inc['rules']['level']['emoji'] . ' سطحِ این ماه: ' . $inc['rules']['level']['name'];
    return $t;
}

// محاسبه‌ی پاداش/تخفیفِ مشتری
function mk_reward_calc($pdo, $typeId, $premium, $pay = 'any', $customer = 'any', $count = 1, $commission = null) {
    $premium = intval($premium); $count = max(1, intval($count));
    $st = $pdo->prepare("SELECT * FROM mk_rewards WHERE active = 1 AND (type_id IS NULL OR type_id = ?) ORDER BY priority DESC, id");
    $st->execute([intval($typeId)]);
    $match = [];
    foreach ($st->fetchAll() as $r) {
        if ($premium < intval($r['min_premium'])) continue;
        if ($r['max_premium'] !== null && $premium >= intval($r['max_premium'])) continue;
        if ($r['pay_mode'] !== 'any' && $r['pay_mode'] !== $pay) continue;
        if ($r['customer'] !== 'any' && $r['customer'] !== $customer) continue;
        if ($count < intval($r['min_count'])) continue;
        $money = 0;
        if ($r['reward_kind'] === 'percent') $money = intval(round($premium * floatval($r['value']) / 100));
        elseif ($r['reward_kind'] === 'amount') $money = intval($r['value']);
        if ($r['max_amount'] !== null && $money > intval($r['max_amount'])) $money = intval($r['max_amount']);
        $match[] = ['id' => intval($r['id']), 'title' => $r['title'], 'kind' => $r['reward_kind'], 'value' => floatval($r['value']), 'money' => $money,
                    'gift' => (string)$r['gift_text'], 'stackable' => intval($r['stackable']), 'priority' => intval($r['priority']), 'note' => (string)$r['note']];
    }
    // همه‌ی قانون‌های قابلِ جمع + بهترینِ قانونِ غیرقابلِ جمع (بیشترین تخفیف، بعد بالاترین اولویت)
    $best = null;
    foreach ($match as $m) if (!$m['stackable'] && (!$best || $m['money'] > $best['money'] || ($m['money'] === $best['money'] && $m['priority'] > $best['priority']))) $best = $m;
    $applied = array_values(array_filter($match, function ($m) use ($best) { return $m['stackable'] || ($best && $m['id'] === $best['id']); }));
    $disc = min($premium, array_sum(array_column($applied, 'money')));
    $capped = 0;
    if ($commission !== null && !empty(mk_settings($pdo)['cap_to_commission']) && $disc > $commission) { $capped = $disc - intval($commission); $disc = intval($commission); }
    $gifts = array_values(array_filter(array_column($applied, 'gift')));
    return ['applied' => $applied, 'skipped' => array_values(array_filter($match, function ($m) use ($applied) { return !in_array($m['id'], array_column($applied, 'id'), true); })),
            'discount' => $disc, 'pct' => $premium ? round($disc * 100 / $premium, 1) : 0, 'final' => $premium - $disc, 'gifts' => $gifts, 'capped' => $capped];
}
function mk_reward_text($type, $premium, array $c, $pay, $cust, $count) {
    $t = "🎁 پاداش و تخفیفِ قابلِ ارائه\n" . ($type['icon'] ? $type['icon'] . ' ' : '') . $type['name'] . "\nحق‌بیمه: " . life_fa_money($premium) . ' ریال · ' . MK_PAY[$pay] . ' · ' . MK_CUST[$cust] . ($count > 1 ? ' · ' . mk_fa($count) . ' فقره/نفر' : '');
    if (!$c['applied']) return $t . "\n\nبا این اطلاعات پاداش یا تخفیفی تعریف نشده است.";
    $t .= "\n";
    foreach ($c['applied'] as $a) {
        $t .= "\n• " . $a['title'] . ': ';
        if ($a['kind'] === 'percent') $t .= mk_fa(rtrim(rtrim(number_format($a['value'], 2), '0'), '.')) . '٪ تخفیف = ' . life_fa_money($a['money']) . ' ریال';
        elseif ($a['kind'] === 'amount') $t .= life_fa_money($a['money']) . ' ریال تخفیف';
        if ($a['gift']) $t .= ($a['kind'] !== 'gift' ? ' + ' : '') . '🎁 ' . $a['gift'];
    }
    if (!empty($c['capped'])) $t .= "\n\n⚠️ جمعِ تخفیف‌ها از کارمزدِ نمایندگی بیشتر بود؛ " . life_fa_money($c['capped']) . ' ریال از آن کم شد.';
    if ($c['discount']) $t .= "\n\n💸 جمعِ تخفیف: " . life_fa_money($c['discount']) . ' ریال (' . mk_fa($c['pct']) . "٪)\n✅ حق‌بیمه‌ی نهایی: " . life_fa_money($c['final']) . ' ریال';
    if ($c['gifts']) $t .= "\n🎁 هدیه: " . implode(' + ', $c['gifts']);
    if ($c['skipped']) $t .= "\n\n(" . mk_fa(count($c['skipped'])) . ' پیشنهادِ دیگر هم هست که با این‌ها جمع نمی‌شود: ' . implode('، ', array_column($c['skipped'], 'title')) . ')';
    return $t;
}

// ---------------------------------------------------------------------
//  گزارشِ PDF (یک نفر یا همه؛ یک ماه یا کلِ سال)
// ---------------------------------------------------------------------
function mk_pdf($pdo, $personId, $jy, $jm = null) {
    require_once __DIR__ . '/_work_service.php';
    $s = mk_settings($pdo);
    $person = $personId ? mk_person($pdo, $personId) : null;
    $period = $jm ? mk_month_label($jy, $jm) : 'سالِ ' . mk_fa($jy);
    [$pdf, $T, $box, $hex, $W] = ws_pdf_kit('گزارش فروش ' . ($person ? $person['full_name'] . ' ' : '') . $period);
    $levels = mk_levels($pdo);
    // سربرگ
    $pdf->LinearGradient(0, 0, $W, 44, $hex('#1e1b4b'), $hex('#0e7490'), [0, 0, 1, 0]);
    $pdf->SetAlpha(0.12); $pdf->SetFillColor(255, 255, 255);
    $pdf->Circle(18, 4, 24, 0, 360, 'F'); $pdf->Circle(192, 46, 28, 0, 360, 'F'); $pdf->Circle(120, -10, 16, 0, 360, 'F');
    $pdf->SetAlpha(1);
    $T(70, 8, 128, 10, $person ? 'کارنامه‌ی فروشِ ' . $person['full_name'] : 'گزارشِ فروشِ بازاریابان', 18, 'B', '#ffffff');
    $T(70, 19, 128, 7, $period . ($person ? ' · ' . (MK_KINDS[$person['kind']] ?? '') : ' · همه‌ی بازاریابان و کارشناسانِ تماس'), 11, '', '#e0e7ff');
    $T(70, 27, 128, 6, $s['report_title'] . ' · صدور ' . mk_fa(mk_today_j()), 9, 'B', '#a5f3fc');
    $box(12, 10, 50, 24, '#ffffff', 4);
    $T(14, 12, 46, 6, $jm ? 'گزارشِ ماهانه' : 'گزارشِ سالانه', 7.5, '', '#64748b', 'C');
    $T(14, 18.5, 46, 8, $period, 12, 'B', '#1e1b4b', 'C');
    $y = 50;
    $card = function ($x, $y, $w, $label, $val, $bg, $fg) use ($T, $box) {
        $box($x, $y, $w, 20, $bg, 4);
        $T($x + 2, $y + 2.5, $w - 4, 6, $label, 8, '', '#475569');
        $T($x + 2, $y + 9.5, $w - 4, 8, $val, 12, 'B', $fg);
    };
    $months = $jm ? [intval($jm)] : range(1, 12);
    if ($person) {
        // کارت‌های خلاصه
        $agg = ['n' => 0, 'premium' => 0, 'fee' => 0, 'total' => 0, 'base' => 0, 'over' => 0, 'volume' => 0, 'lb' => 0];
        $per = [];
        foreach ($months as $m) {
            $inc = mk_income($pdo, $person, $jy, $m);
            if (!$jm && !$inc['data']['n']) continue;
            $per[$m] = $inc;
            $agg['n'] += $inc['data']['n']; $agg['premium'] += $inc['data']['premium']; $agg['fee'] += $inc['data']['fee']; $agg['total'] += $inc['total'];
            $agg['base'] += $inc['base']; $agg['over'] += $inc['over']; $agg['volume'] += $inc['volume']; $agg['lb'] += $inc['level_bonus'];
        }
        $cw = 44.5;
        $card(12, $y, $cw, 'تعدادِ فروش', mk_fa($agg['n']) . ' فقره', '#eef2ff', '#3730a3');
        $card(12 + ($cw + 2) * 1, $y, $cw, 'جمعِ حق‌بیمه (ریال)', life_fa_money($agg['premium']), '#ecfeff', '#0e7490');
        $card(12 + ($cw + 2) * 2, $y, $cw, 'حق بازاریابی (ریال)', life_fa_money($agg['fee']), '#f0fdf4', '#15803d');
        $card(12 + ($cw + 2) * 3, $y, $cw, 'کلِ درآمد (ریال)', life_fa_money($agg['total']), '#fff7ed', '#c2410c');
        $y += 26;
        if ($jm) {
            $inc = $per[intval($jm)];
            $lv = $inc['rules']['level'];
            // سطح و هدف
            $box(12, $y, 186, 26, '#f8fafc', 4, '#e2e8f0');
            if ($lv) {
                $pdf->SetFillColor(...$hex($lv['color'] ?: '#64748b'));
                $pdf->Circle(186, $y + 13, 9, 0, 360, 'F');
                $T(177, $y + 8, 18, 10, mb_substr($lv['name'], 0, 1), 16, 'B', '#ffffff', 'C');
                $img = $lv['image_path'] ? dirname(__DIR__) . '/' . $lv['image_path'] : '';
                if ($img && is_file($img) && preg_match('/\.(png|jpe?g)$/i', $img)) { try { $pdf->Image($img, 177.5, $y + 4.5, 17, 17, '', '', '', true); } catch (Throwable $e) {} }
                $T(130, $y + 4, 44, 7, 'سطحِ این ماه', 8, '', '#64748b');
                $T(130, $y + 11, 44, 9, $lv['name'], 14, 'B', $lv['color'] ?: '#0f172a');
            }
            $tp = min(100, $inc['target_pct']);
            $T(20, $y + 4, 105, 6, 'هدفِ ماهانه: ' . life_fa_money($inc['rules']['target']) . ' ریال · ' . mk_fa($inc['target_pct']) . '٪', 9, 'B', '#0f172a');
            $box(20, $y + 12, 105, 4, '#e2e8f0', 2);
            if ($tp > 0) { $pdf->SetFillColor(...$hex('#16a34a')); $pdf->RoundedRect(20 + 105 - 105 * $tp / 100, $y + 12, 105 * $tp / 100, 4, 2, '1111', 'F'); }
            $T(20, $y + 18, 105, 6, $inc['to_target'] ? 'تا هدف: ' . life_fa_money($inc['to_target']) . ' ریال' : 'به هدفِ ماهانه رسیدید!', 8, '', '#475569');
            $y += 32;
        }
        // جدول بر اساسِ نوع
        $T(12, $y, 186, 7, 'ریزِ فروش بر اساسِ نوعِ بیمه‌نامه', 11, 'B', '#1e1b4b');
        $y += 9;
        $d = $jm ? $per[intval($jm)]['data'] : mk_month_data($pdo, $person['id'], $jy);
        $y = mk_pdf_table($pdf, $T, $box, $y, ['نوعِ بیمه‌نامه', 'تعداد', 'جمعِ حق‌بیمه', 'کارمزد', 'حق بازاریابی'], [70, 16, 36, 30, 34],
            array_merge(array_map(function ($r) { return [$r['type_name'], mk_fa($r['n']), life_fa_money($r['premium']), life_fa_money($r['comm']), life_fa_money($r['fee'])]; }, $d['rows']),
                        [['جمع', mk_fa($d['n']), life_fa_money($d['premium']), life_fa_money($d['comm']), life_fa_money($d['fee'])]]));
        $y += 5;
        // درآمد
        $T(12, $y, 186, 7, $jm ? 'محاسبه‌ی درآمدِ ماه' : 'درآمدِ ماه به ماه', 11, 'B', '#1e1b4b');
        $y += 9;
        if ($jm) {
            $inc = $per[intval($jm)];
            $rows = [['حقوقِ ثابت' . ($inc['base_ok'] ? '' : ' (کفِ فروش ' . life_fa_money($inc['rules']['base_floor']) . ' نرسید)'), life_fa_money($inc['base'])],
                     ['حق بازاریابی', life_fa_money($inc['data']['fee'])],
                     ['اضافه‌ی فروشِ بالای کف (' . mk_fa($inc['rules']['over_pct']) . '٪)', life_fa_money($inc['over'])],
                     ['پاداشِ حجمِ فروش' . ($inc['volume_tier'] ? ' (' . mk_fa($inc['volume_tier']['pct']) . '٪)' : ''), life_fa_money($inc['volume'])],
                     ['پاداشِ سطح' . ($inc['rules']['fee_bonus_pct'] ? ' (' . mk_fa($inc['rules']['fee_bonus_pct']) . '٪)' : ''), life_fa_money($inc['level_bonus'])],
                     ['جمعِ درآمدِ ماه', life_fa_money($inc['total'])]];
            $y = mk_pdf_table($pdf, $T, $box, $y, ['شرح', 'مبلغ (ریال)'], [130, 56], $rows);
            $y += 4;
            foreach ($inc['messages'] as $msg) { $T(12, $y, 186, 6, preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '•', $msg), 8.5, '', '#334155'); $y += 6; }
        } else {
            $rows = [];
            foreach ($per as $m => $inc) $rows[] = [jalali_month_name($m), mk_fa($inc['data']['n']), life_fa_money($inc['data']['premium']), life_fa_money($inc['data']['fee']), life_fa_money($inc['base'] + $inc['over'] + $inc['volume'] + $inc['level_bonus']), life_fa_money($inc['total']), $inc['rules']['level']['name'] ?? '—'];
            $rows[] = ['جمع', mk_fa($agg['n']), life_fa_money($agg['premium']), life_fa_money($agg['fee']), life_fa_money($agg['base'] + $agg['over'] + $agg['volume'] + $agg['lb']), life_fa_money($agg['total']), ''];
            $y = mk_pdf_table($pdf, $T, $box, $y, ['ماه', 'تعداد', 'حق‌بیمه', 'حق بازاریابی', 'حقوق و پاداش', 'کلِ درآمد', 'سطح'], [24, 14, 34, 30, 30, 32, 22], $rows);
        }
        // فهرستِ فروش‌ها
        $st = $pdo->prepare("SELECT * FROM mk_sales WHERE person_id = ? AND status = 'OK' AND jy = ?" . ($jm ? ' AND jm = ' . intval($jm) : '') . " ORDER BY sale_g, id");
        $st->execute([$person['id'], $jy]);
        $sales = $st->fetchAll();
        if ($sales) {
            if ($y > 230) { $pdf->AddPage(); $y = 14; }
            $y += 5;
            $T(12, $y, 186, 7, 'فهرستِ فروش‌ها', 11, 'B', '#1e1b4b');
            $y += 9;
            mk_pdf_table($pdf, $T, $box, $y, ['تاریخ', 'نوع', 'مشتری', 'حق‌بیمه', 'سهم٪', 'حق بازاریابی'], [22, 56, 40, 28, 14, 26],
                array_map(function ($r) { return [mk_fa($r['sale_j']), $r['type_name'], (string)$r['customer_name'] ?: '—', life_fa_money($r['premium']), mk_fa(floatval($r['share_pct'])), life_fa_money($r['fee_amount'])]; }, $sales));
        }
    } else {
        // همه‌ی افراد
        $all = mk_people($pdo, true);
        $tot = mk_month_data($pdo, 0, $jy, $jm);
        $cw = 44.5;
        $card(12, $y, $cw, 'تعدادِ فروش', mk_fa($tot['n']) . ' فقره', '#eef2ff', '#3730a3');
        $card(12 + ($cw + 2), $y, $cw, 'جمعِ حق‌بیمه (ریال)', life_fa_money($tot['premium']), '#ecfeff', '#0e7490');
        $card(12 + ($cw + 2) * 2, $y, $cw, 'جمعِ کارمزد (ریال)', life_fa_money($tot['comm']), '#f5f3ff', '#6d28d9');
        $card(12 + ($cw + 2) * 3, $y, $cw, 'حق بازاریابی (ریال)', life_fa_money($tot['fee']), '#f0fdf4', '#15803d');
        $y += 27;
        $T(12, $y, 186, 7, 'عملکردِ افراد', 11, 'B', '#1e1b4b');
        $y += 9;
        $rows = [];
        $sumInc = 0;
        foreach ($all as $p) {
            if ($jm) { $inc = mk_income($pdo, $p, $jy, $jm); $d = $inc['data']; $income = $inc['total']; $lv = $inc['rules']['level']['name'] ?? '—'; $tg = mk_fa($inc['target_pct']) . '٪'; }
            else { $d = mk_month_data($pdo, $p['id'], $jy); $income = 0; foreach (range(1, 12) as $m) { $x = mk_income($pdo, $p, $jy, $m); if ($x['data']['n'] || $x['base']) $income += $x['total']; } $lv = '—'; $tg = '—'; }
            $sumInc += $income;
            $rows[] = [$p['full_name'], MK_KINDS[$p['kind']] ?? '', mk_fa($d['n']), life_fa_money($d['premium']), life_fa_money($d['fee']), life_fa_money($income), $lv, $tg, $d['premium']];
        }
        usort($rows, function ($a, $b) { return $b[8] <=> $a[8]; });
        $rows = array_map(function ($r) { array_pop($r); return $r; }, $rows);
        $rows[] = ['جمع', '', mk_fa($tot['n']), life_fa_money($tot['premium']), life_fa_money($tot['fee']), life_fa_money($sumInc), '', ''];
        $y = mk_pdf_table($pdf, $T, $box, $y, ['نام', 'نقش', 'تعداد', 'حق‌بیمه', 'حق بازاریابی', 'کلِ درآمد', 'سطح', 'هدف'], [38, 22, 13, 30, 26, 28, 16, 13], $rows);
        $y += 5;
        if ($y > 220) { $pdf->AddPage(); $y = 14; }
        $T(12, $y, 186, 7, 'فروش بر اساسِ نوعِ بیمه‌نامه', 11, 'B', '#1e1b4b');
        $y += 9;
        mk_pdf_table($pdf, $T, $box, $y, ['نوعِ بیمه‌نامه', 'تعداد', 'جمعِ حق‌بیمه', 'کارمزد', 'حق بازاریابی'], [70, 16, 36, 30, 34],
            array_map(function ($r) { return [$r['type_name'], mk_fa($r['n']), life_fa_money($r['premium']), life_fa_money($r['comm']), life_fa_money($r['fee'])]; }, $tot['rows']));
    }
    $dir = dirname(__DIR__) . '/tmp_ocr/mk';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    foreach ((array)glob($dir . '/*.pdf') as $old) if (@filemtime($old) < time() - 3600) @unlink($old);
    $file = $dir . '/' . sanitize_folder_name('گزارش فروش - ' . ($person ? $person['full_name'] . ' - ' : '') . str_replace(' ', '-', $period)) . '.pdf';
    $pdf->Output($file, 'F');
    return $file;
}
// جدولِ ساده‌ی PDF (راست‌به‌چپ، سطرِ آخرِ «جمع» پررنگ)؛ y پایانی را برمی‌گرداند
function mk_pdf_table($pdf, $T, $box, $y, array $head, array $widths, array $rows) {
    $total = array_sum($widths); $x0 = 198;
    $box(198 - $total, $y, $total, 8, '#1e1b4b', 2);
    $x = $x0;
    foreach ($head as $i => $h) { $T(198 - ($x0 - $x) - $widths[$i], $y + 1, $widths[$i] - 2, 6, $h, 8, 'B', '#ffffff', 'C'); $x -= $widths[$i]; }
    $y += 8;
    foreach ($rows as $ri => $r) {
        if ($y > 280) { $pdf->AddPage(); $y = 14; }
        $isSum = $ri === count($rows) - 1 && mb_strpos((string)($r[0] ?? ''), 'جمع') === 0;
        $box(198 - $total, $y, $total, 7, $isSum ? '#e0e7ff' : ($ri % 2 ? '#f8fafc' : '#ffffff'), 0);
        $x = $x0;
        foreach ($r as $i => $c) {
            if (!isset($widths[$i])) break;
            $T(198 - ($x0 - $x) - $widths[$i], $y + 0.5, $widths[$i] - 2, 6, mb_substr((string)$c, 0, 48), $isSum ? 8.5 : 8, $isSum ? 'B' : '', '#0f172a', $i === 0 ? 'R' : 'C');
            $x -= $widths[$i];
        }
        $y += 7;
    }
    return $y;
}
