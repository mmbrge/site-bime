<?php
// فایل: api/_vehicle_catalog.php
// فهرستِ انواعِ وسیله‌ی نقلیه (نوع ← گروه ← مدل) برای مینی‌اپ (ثبتِ درخواست و استعلام) و پنل.
// بارِ اول از api/data/vehicle_catalog.json (فهرستِ رسمیِ بیمه‌گر) پر می‌شود و بعد از پنل قابلِ ویرایش است.
//   premium_cat: رده‌ی موتورِ استعلامِ ثالث (api/premium_calc.php)؛ خالی = استعلامِ آنلاین ندارد (ارجاع به کارشناس)

const VC_KINDS = [
    'CAR'   => ['fa' => 'سواری',               'icon' => '🚗', 'plate' => 'car'],
    'TRUCK' => ['fa' => 'بارکش',               'icon' => '🚚', 'plate' => 'car'],
    'MOTO'  => ['fa' => 'موتورسیکلت',          'icon' => '🏍', 'plate' => 'moto'],
    'AGRI'  => ['fa' => 'کشاورزی و راهسازی',   'icon' => '🚜', 'plate' => 'agri'],
    'BUS'   => ['fa' => 'اتوکار',              'icon' => '🚌', 'plate' => 'car'],
];

function vc_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_catalog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(10) NOT NULL,
        grp VARCHAR(120) NOT NULL,
        title VARCHAR(190) NOT NULL,
        cylinders SMALLINT NULL,
        seats SMALLINT NULL,
        tonnage DECIMAL(7,2) NULL,
        code VARCHAR(20) NULL,
        premium_cat TINYINT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY k_kind (kind, grp), KEY k_title (title)
    ) DEFAULT CHARSET=utf8mb4");
    if (!(int)$pdo->query("SELECT COUNT(*) FROM vehicle_catalog")->fetchColumn()) vc_seed($pdo);
    vc_split_pride($pdo);
}

// گروهِ جدا و رده‌ی نرخِ «پراید / پیکان» (رده‌ی ۱ در premium_calc.php) - نرخش با بقیه‌ی ۴ سیلندرها فرق دارد
const VC_PRIDE_GRP = '۴ سیلندر (پراید و پیکان)';

// خانواده‌ی پراید (پراید، سایپا ۱۱۱/۱۳۱/۱۳۲/۱۴۱، صبا، نسیم) و پیکان (پیکان، هیلمن) - «کیا پیکانتو» جزوش نیست
function vc_is_pride($title) {
    $t = vc_norm($title);
    if (mb_strpos($t, 'پیکانتو') !== false) return false;
    return (bool)preg_match('/پراید|پیکان|^سایپا\s*(111|131|132|141)(\D|$)|^(صبا|نسیم|هیلمن)(\s|$)/u', $t);
}

// یک بار روی فهرستِ موجود: مدل‌های پراید و پیکان که هنوز در گروهِ «۴ سیلندر» هستند به گروهِ جدا با رده‌ی ۱ می‌روند،
// و «کیا پیکانتو» که اشتباهی رده‌ی ۱ گرفته بود رده‌ی درستش (۴ سیلندر = ۲، ۳ سیلندر = ۳) را می‌گیرد.
// فقط ردیف‌هایی که هنوز همان مقدارِ اولیه را دارند عوض می‌شوند؛ تغییرِ دستیِ پنل دست نمی‌خورد.
function vc_split_pride($pdo) {
    try {
        $f = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'vc_pride_split_v1'")->fetchColumn();
        if ($f === '1') return;
        $up = $pdo->prepare("UPDATE vehicle_catalog SET grp = ?, premium_cat = 1 WHERE id = ?");
        foreach ($pdo->query("SELECT id, title FROM vehicle_catalog WHERE kind = 'CAR' AND grp = '۴ سیلندر'")->fetchAll(PDO::FETCH_ASSOC) as $r)
            if (vc_is_pride($r['title'])) $up->execute([VC_PRIDE_GRP, $r['id']]);
        $fix = $pdo->prepare("UPDATE vehicle_catalog SET premium_cat = ? WHERE id = ?");
        foreach ($pdo->query("SELECT id, title, cylinders FROM vehicle_catalog WHERE kind = 'CAR' AND premium_cat = 1")->fetchAll(PDO::FETCH_ASSOC) as $r)
            if (mb_strpos(vc_norm($r['title']), 'پیکانتو') !== false) $fix->execute([(int)$r['cylinders'] === 3 ? 3 : 2, $r['id']]);
        $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('vc_pride_split_v1', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
    } catch (Throwable $e) { error_log('[vc_split_pride] ' . $e->getMessage()); }
}

function vc_seed($pdo) {
    $f = __DIR__ . '/data/vehicle_catalog.json';
    $rows = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($rows)) return 0;
    $st = $pdo->prepare("INSERT INTO vehicle_catalog (kind, grp, title, cylinders, seats, tonnage, code, premium_cat) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $n = 0;
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        if (!isset(VC_KINDS[$r[0]]) || trim((string)$r[2]) === '') continue;
        $st->execute([$r[0], mb_substr($r[1], 0, 120), mb_substr($r[2], 0, 190), $r[3], $r[4], $r[5], $r[6] ?: null, $r[7]]);
        $n++;
    }
    $pdo->commit();
    return $n;
}

function vc_norm($s) {
    $s = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], (string)$s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function vc_kinds_list() {
    $out = [];
    foreach (VC_KINDS as $k => $v) $out[] = ['key' => $k, 'fa' => $v['fa'], 'icon' => $v['icon'], 'plate' => $v['plate']];
    return $out;
}

// گروه‌های یک نوع (با تعدادِ مدل و اینکه استعلامِ آنلاین دارد یا نه)
function vc_groups($pdo, $kind) {
    vc_ensure($pdo);
    $st = $pdo->prepare("SELECT grp, COUNT(*) n, MAX(premium_cat IS NOT NULL) has_rate FROM vehicle_catalog WHERE kind = ? AND active = 1 GROUP BY grp ORDER BY MIN(id)");
    $st->execute([$kind]);
    $g = array_map(fn($r) => ['grp' => $r['grp'], 'n' => (int)$r['n'], 'has_rate' => (int)$r['has_rate']], $st->fetchAll(PDO::FETCH_ASSOC));
    // «۴ سیلندر (پراید و پیکان)» درست بعد از «۴ سیلندر» بیاید
    $pi = array_search(VC_PRIDE_GRP, array_column($g, 'grp'), true);
    $fi = array_search('۴ سیلندر', array_column($g, 'grp'), true);
    if ($pi !== false && $fi !== false && $pi !== $fi + 1) { $row = array_splice($g, $pi, 1)[0]; $fi = array_search('۴ سیلندر', array_column($g, 'grp'), true); array_splice($g, $fi + 1, 0, [$row]); }
    return $g;
}

// مدل‌ها (در یک گروه، یا جستجو در همه‌ی گروه‌های یک نوع)
function vc_models($pdo, $kind, $grp = '', $q = '', $limit = 600) {
    vc_ensure($pdo);
    $w = ['active = 1', 'kind = ?']; $a = [$kind];
    if ($grp !== '') { $w[] = 'grp = ?'; $a[] = $grp; }
    $q = vc_norm($q);
    if ($q !== '') {
        foreach (array_slice(preg_split('/\s+/u', $q), 0, 5) as $t) {
            $t = str_replace(['%', '_'], ['\\%', '\\_'], $t);
            $w[] = "(REPLACE(REPLACE(title, 'ي', 'ی'), 'ك', 'ک') LIKE ? OR code = ?)"; $a[] = '%' . $t . '%'; $a[] = $t;
        }
    }
    $st = $pdo->prepare("SELECT id, grp, title, cylinders, seats, tonnage, premium_cat FROM vehicle_catalog WHERE " . implode(' AND ', $w) . " ORDER BY grp, title LIMIT " . intval($limit));
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// «برند» (یا نوعِ کلی برای کامیون/اتوبوس/ماشین‌آلات) از روی نامِ مدل - برای گروه‌بندیِ نتیجه‌ها در مینی‌اپ:
// سواری و موتورسیکلت بر اساسِ برند (پژو، تویوتا، ب ام و، …)؛ بارکش، اتوکار و ماشین‌آلات بر اساسِ نوع (کامیون، وانت، اتوبوس، لودر، …)
function vc_brand($kind, $title) {
    $t = preg_split('/\s+/u', vc_norm(preg_replace('/[-–_\/()]+/u', ' ', (string)$title)));
    $t = array_values(array_filter($t, fn($x) => $x !== ''));
    if ($kind === 'CAR' || $kind === 'MOTO') {
        // پیشوندهای کلی کنار گذاشته می‌شوند تا برند بماند
        while ($t && in_array($t[0], ['موتورسیکلت', 'سواری', 'خودرو', 'خودروی'], true)) array_shift($t);
        if (count($t) >= 2 && $t[0] === 'موتور' && $t[1] === 'سیکلت') { array_shift($t); array_shift($t); }
    }
    if (!$t) return 'سایر';
    $three = ['ب ام و', 'بی ام و', 'ام وی ام', 'کی ام سی', 'جی ام سی', 'بی وای دی', 'جی ای سی', 'ام وی آگوستا', 'کی تی ام', 'تی وی اس'];
    $two = ['سانگ یانگ', 'دانگ فنگ', 'دنگ فنگ', 'گریت وال', 'فولکس واگن', 'لند روور', 'آلفا رومئو', 'مینی کوپر', 'رولز رویس', 'استون مارتین', 'ایران خودرو', 'ایران دوچرخ',
            'ام جی', 'کی وی', 'مرسدس بنز', 'میدل باس', 'میدل بوس', 'بیل مکانیکی', 'نیمه یدک', 'دامپ تراک'];
    $alias = ['مرسدس بنز' => 'بنز', 'مرسدس' => 'بنز', 'هیوندائی' => 'هیوندای', 'فولکس' => 'فولکس واگن', 'سه' => 'سه‌چرخ', 'دنگ فنگ' => 'دانگ فنگ', 'تریلی' => 'تریلر'];
    $p3 = count($t) >= 3 ? "$t[0] $t[1] $t[2]" : '';
    $p2 = count($t) >= 2 ? "$t[0] $t[1]" : '';
    if ($p3 !== '' && in_array($p3, $three, true)) $b = $p3;
    elseif ($p2 !== '' && in_array($p2, $two, true)) $b = $p2;
    elseif ($t[0] === 'مینی' && $p2 !== '') $b = $p2;                       // مینی بوس، مینی لودر، …
    elseif (in_array($p2, ['کامیون کشنده', 'وانت مسقف'], true)) $b = $t[0];
    else $b = $t[0];
    if (preg_match('/^(\p{Arabic}+)[A-Za-z0-9].*$/u', $b, $m)) $b = $m[1];   // «اسکانیاP410»، «پیاجیوPiaggio» → نامِ فارسی
    if ($kind === 'MOTO' && in_array($b, ['تک', 'دو', 'انژکتوری'], true)) $b = 'سایر';
    return $alias[$b] ?? $b;
}

// جستجوی مدل در کلِ یک نوع (بدونِ انتخابِ گروه): q خالی و brand خالی = فهرستِ برندها؛ brand = همه‌ی مدل‌های همان برند؛ q = جستجو در نام/کد/برند
function vc_search($pdo, $kind, $q = '', $brand = '', $limit = 600) {
    vc_ensure($pdo);
    $st = $pdo->prepare("SELECT id, grp, title, cylinders, seats, tonnage, premium_cat, code FROM vehicle_catalog WHERE kind = ? AND active = 1 ORDER BY title");
    $st->execute([$kind]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $fa2en = fn($x) => strtr($x, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                                  '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    $low = fn($x) => mb_strtolower($fa2en(vc_norm($x)));
    $count = [];
    foreach ($rows as &$r) {
        $r['brand'] = vc_brand($kind, $r['title']); $count[$r['brand']] = ($count[$r['brand']] ?? 0) + 1;
        // سایپا ۱۳۱، صبا، … با «پراید» و هیلمن با «پیکان» هم پیدا می‌شوند
        $r['alias'] = $r['grp'] === VC_PRIDE_GRP ? (preg_match('/پیکان|هیلمن/u', $r['title']) ? 'پیکان' : 'پراید') : '';
    }
    unset($r);
    $q = $low($q); $brand = vc_norm($brand);
    if ($q === '' && $brand === '') {
        arsort($count);
        $out = [];
        foreach ($count as $b => $n) $out[] = ['brand' => $b, 'n' => $n];
        return ['brands' => $out];
    }
    if ($brand === '' && isset($count[vc_norm($q)])) $brand = vc_norm($q);   // متنِ جستجو دقیقاً نامِ یک برند است
    $tokens = $q === '' || $brand !== '' ? [] : array_slice(preg_split('/\s+/u', $q), 0, 5);
    $res = [];
    foreach ($rows as $r) {
        if ($brand !== '' && $r['brand'] !== $brand && $r['alias'] !== $brand) continue;
        if ($tokens) {
            $hay = $low($r['title'] . ' ' . $r['brand'] . ($r['alias'] !== '' ? ' ' . $r['alias'] : ''));
            $hayNs = str_replace(' ', '', $hay);
            $ok = true;
            foreach ($tokens as $tk) if (mb_strpos($hay, $tk) === false && mb_strpos($hayNs, $tk) === false && (string)$r['code'] !== $tk) { $ok = false; break; }
            if (!$ok) continue;
        }
        $res[] = $r;
    }
    // گروه‌بندی: برندهای پرمدل‌تر بالاتر، داخلِ هر برند به ترتیبِ نام
    usort($res, fn($a, $b) => [$count[$b['brand']], $a['brand'], $a['title']] <=> [$count[$a['brand']], $b['brand'], $b['title']]);
    return ['models' => array_slice($res, 0, $limit), 'total' => count($res)];
}

function vc_get($pdo, $id) {
    vc_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM vehicle_catalog WHERE id = ?");
    $st->execute([intval($id)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// شرحِ کوتاهِ مدل (برای زیرِ نام در فهرست)
function vc_sub($r) {
    $p = [];
    if (!empty($r['cylinders'])) $p[] = $r['cylinders'] . ' سیلندر';
    if (!empty($r['tonnage']) && (float)$r['tonnage'] > 0) $p[] = rtrim(rtrim((string)$r['tonnage'], '0'), '.') . ' تن';
    if (!empty($r['seats'])) $p[] = $r['seats'] . ' نفر';
    return implode(' · ', $p);
}

// ---------------------------------------------------------------------
//  ورودِ تیپ‌های تازه از «بایگانیِ بردرو» (brd_rows): هر خودرویی که در بردرو هست ولی در فهرست نیست، بر اساسِ نوع
//  (سواری/بارکش/موتورسیکلت/کشاورزی و راهسازی/اتوکار) و سیلندر/ظرفیت در گروهِ درست می‌نشیند؛ تکراری ثبت نمی‌شود.
// ---------------------------------------------------------------------
// کلیدِ مقایسه‌ی نام‌ها: بی‌فاصله، بی‌خط‌تیره و پرانتز، ارقامِ لاتین، حروفِ کوچک (پژو ۴۰۵ GLX = پژو405-glx)
function vc_key($s) {
    $s = mb_strtolower(vc_norm(strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                                                   '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', 'آ' => 'ا', 'ة' => 'ه'])));
    // تیپ‌هایی که به فارسی هجی شده‌اند (پژو ۴۰۵ جی ال ایکس = پژو 405 GLX)
    static $trim = null;
    if ($trim === null) {
        $trim = ['ای ال ایکس' => 'elx', 'جی ال ایکس' => 'glx', 'اس ال ایکس' => 'slx', 'جی ال آی' => 'gli', 'جی ال ای' => 'gli', 'جی ال' => 'gl', 'ال ایکس' => 'lx',
                 'اس ای' => 'se', 'جی تی' => 'gt', 'اس یو وی' => 'suv', 'ای ام' => 'am', 'تی یو' => 'tu', 'آر دی' => 'rd', 'ار دی' => 'rd'];
        uksort($trim, fn($x, $y) => mb_strlen($y) <=> mb_strlen($x));
    }
    foreach ($trim as $f => $t) $s = preg_replace('/(^|\s)' . preg_quote(str_replace('آ', 'ا', $f), '/') . '(?=\s|$)/u', '$1' . $t, $s);
    return preg_replace('/[\s\-–_\/\\\\().,،*"\'«»]+/u', '', $s);
}
// نوعِ وسیله از «نوع وسیله نقلیه»، «مورد استفاده» و نامِ خودرو => [kind, مطمئن؟]
function vc_kind_guess($kindTxt, $usage, $name) {
    $all = vc_norm($kindTxt . ' ' . $usage . ' ' . $name);
    $k = vc_norm($kindTxt) ?: $all;
    $rules = [
        'MOTO'  => '/موتور\s*سیکلت|موتورسیکلت|^موتور(\s|$)|سه\s*چرخ/u',
        'AGRI'  => '/تراکتور|کمباین|لودر|بیل(\s|$)|بیل\s*مکانیکی|گریدر|بولدوزر|غلتک|لیفتراک|جرثقیل|ماشین\s*آلات|ماشین‌آلات|کشاورزی|راهسازی|راه\s*سازی|فینیشر|دریل|تیلر/u',
        'BUS'   => '/اتوبوس|مینی\s*بوس|میدل\s*باس|میدل\s*بوس|اتوکار|(^|\s)ون(\s|$)|مسافربری\s*عمومی/u',
        'TRUCK' => '/وانت|کامیون|کامیونت|کشنده|تریلی|تریلر|یدک|بارکش|باربری|باری(\s|$)|کمپرسی|میکسر|تانکر|یخچال|کفی|بونکر|تراک/u',
        'CAR'   => '/سواری|استیشن|هاچ\s*بک|سدان|جیپ|آمبولانس|شاسی\s*بلند|کوپه|کراس\s*اوور/u',
    ];
    foreach ($rules as $kind => $re) if (preg_match($re, $k)) return [$kind, true];
    foreach ($rules as $kind => $re) if (preg_match($re, $all)) return [$kind, true];
    return ['CAR', false];   // نامشخص: سواری (در پیش‌نمایش «حدسی» علامت می‌خورد تا کارشناس عوضش کند)
}
// «ظرفیت»: نفر => سرنشین، تن/کیلو => تناژ
function vc_parse_capacity($cap) {
    $c = vc_norm(strtr((string)$cap, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.', '/' => '.']));
    $o = ['seats' => null, 'tonnage' => null];
    if (preg_match('/(\d+(?:\.\d+)?)\s*(تن|ton)/u', $c, $m)) $o['tonnage'] = (float)$m[1];
    elseif (preg_match('/(\d+(?:\.\d+)?)\s*(کیلو|kg)/u', $c, $m)) $o['tonnage'] = round((float)$m[1] / 1000, 2);
    if (preg_match('/(\d+)\s*(نفر|سرنشین)/u', $c, $m)) $o['seats'] = (int)$m[1];
    return $o;
}
// گروهِ مدلِ تازه در یک نوع: از سیلندر/سرنشین/تناژ، و اگر معلوم نبود از مدل‌های هم‌نامِ همان نوع (پرتکرارترین گروه)
function vc_group_guess($pdo, $kind, $title, $cyl, $seats, $ton) {
    $t = vc_norm($title);
    $byName = function () use ($pdo, $kind, $t) {
        $w = array_slice(preg_split('/\s+/u', $t), 0, 2);
        while ($w) {
            $st = $pdo->prepare("SELECT grp, COUNT(*) n FROM vehicle_catalog WHERE kind = ? AND REPLACE(REPLACE(title, 'ي', 'ی'), 'ك', 'ک') LIKE ? GROUP BY grp ORDER BY n DESC LIMIT 1");
            $st->execute([$kind, str_replace(['%', '_'], ['\\%', '\\_'], implode(' ', $w)) . '%']);
            if ($g = $st->fetchColumn()) return $g;
            array_pop($w);
        }
        return null;
    };
    if ($kind === 'CAR') {
        if (vc_is_pride($t)) return VC_PRIDE_GRP;
        if ($cyl) return $cyl < 4 ? 'کمتر از ۴ سیلندر' : ($cyl == 4 ? '۴ سیلندر' : 'بیش از ۴ سیلندر');
        return $byName() ?: '۴ سیلندر';
    }
    if ($kind === 'MOTO') return $cyl ? ($cyl >= 2 ? 'دو سیلندر و بیشتر' : 'یک سیلندر') : ($byName() ?: 'یک سیلندر');
    if ($kind === 'TRUCK') {
        if (preg_match('/یدک|تریلر|تریلی|کفی/u', $t) && !preg_match('/کشنده/u', $t)) return 'یدک و نیمه‌یدک';
        if ($ton) return $ton <= 1 ? 'تا ۱ تن' : ($ton <= 2 ? 'تا ۲ تن' : ($ton <= 5 ? 'تا ۵ تن' : 'بیش از ۵ تن'));
        if (preg_match('/کامیون(?!ت)|کشنده|کمپرسی|میکسر|تانکر/u', $t)) return 'بیش از ۵ تن';
        return $byName() ?: 'سایر';
    }
    if ($kind === 'BUS') {
        if ($seats) return $seats <= 27 ? 'ون، مینی‌بوس و میدل‌باس (تا ۲۷ نفر)' : 'اتوبوس (بیش از ۲۷ نفر)';
        if (preg_match('/مینی|میدل|(^|\s)ون(\s|$)/u', $t)) return 'ون، مینی‌بوس و میدل‌باس (تا ۲۷ نفر)';
        if (preg_match('/اتوبوس/u', $t)) return 'اتوبوس (بیش از ۲۷ نفر)';
        return $byName() ?: 'سایر';
    }
    if (preg_match('/تراکتور|کمباین|تیلر|کشاورزی/u', $t)) return 'ماشین‌آلات کشاورزی';
    if (preg_match('/لودر|بیل|گریدر|بولدوزر|غلتک|فینیشر|راهسازی/u', $t)) return 'ماشین‌آلات راهسازی';
    return $byName() ?: 'سایر ماشین‌آلات';
}
// رده‌ی استعلامِ گروه (فقط وقتی همه‌ی مدل‌های گروه یک رده دارند)
function vc_group_rate($pdo, $kind, $grp) {
    $st = $pdo->prepare("SELECT MIN(premium_cat) mn, MAX(premium_cat) mx, SUM(premium_cat IS NULL) nn, COUNT(*) n FROM vehicle_catalog WHERE kind = ? AND grp = ?");
    $st->execute([$kind, $grp]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r && (int)$r['n'] && !(int)$r['nn'] && $r['mn'] === $r['mx'] ? (int)$r['mn'] : null;
}
// مدل‌های بردرو که در فهرست نیستند (پیش‌نمایش): [title, kind, sure, grp, cylinders, seats, tonnage, count, sample]
function vc_bordereau_candidates($pdo) {
    vc_ensure($pdo);
    try { $rows = $pdo->query("SELECT car_name, car_system, car_tip, car_kind, car_usage, data_json FROM brd_rows")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return ['ok' => false, 'error' => 'بایگانیِ بردرو هنوز خالی است.']; }
    $have = [];
    foreach ($pdo->query("SELECT title FROM vehicle_catalog") as $r) $have[vc_key($r['title'])] = 1;
    $out = []; $dupe = 0; $empty = 0;
    foreach ($rows as $r) {
        $pf = json_decode((string)$r['data_json'], true) ?: [];
        $name = vc_norm($r['car_name'] ?? ''); $sys = vc_norm($r['car_system'] ?? ''); $tip = vc_norm($r['car_tip'] ?? '');
        $title = $name !== '' ? $name : vc_norm($sys . ' ' . $tip);
        if ($title !== '' && $tip !== '' && mb_strpos(vc_key($title), vc_key($tip)) === false) $title = vc_norm($title . ' ' . $tip);
        if ($title === '' || mb_strlen($title) < 2) { $empty++; continue; }
        $key = vc_key($title);
        if (isset($have[$key])) { $dupe++; continue; }
        [$kind, $sure] = vc_kind_guess($r['car_kind'] ?? ($pf['car_kind'] ?? ''), $r['car_usage'] ?? ($pf['usage'] ?? ''), $title);
        $cap = vc_parse_capacity($pf['capacity'] ?? '');
        $cyl = (int)preg_replace('/\D/', '', strtr((string)($pf['cylinders'] ?? ''), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])) ?: null;
        if ($cyl !== null && $cyl > 64) $cyl = null;
        if (isset($out[$key])) {
            // همان مدل در چند ردیف: ردیفی که نوع/سیلندر/ظرفیت را دارد برنده است
            $o = &$out[$key]; $o['count']++;
            if ($sure && !$o['sure']) { $o['kind'] = $kind; $o['sure'] = true; $o['src'] = trim(implode(' · ', array_filter([$r['car_kind'] ?? '', $r['car_usage'] ?? '', $pf['capacity'] ?? '']))); }
            $o['cylinders'] = $o['cylinders'] ?: $cyl; $o['seats'] = $o['seats'] ?: $cap['seats']; $o['tonnage'] = $o['tonnage'] ?: $cap['tonnage'];
            $o['grp'] = vc_group_guess($pdo, $o['kind'], $o['title'], $o['cylinders'], $o['seats'], $o['tonnage']);
            unset($o);
            continue;
        }
        $out[$key] = ['title' => mb_substr($title, 0, 190), 'kind' => $kind, 'sure' => $sure, 'grp' => vc_group_guess($pdo, $kind, $title, $cyl, $cap['seats'], $cap['tonnage']),
                      'cylinders' => $cyl, 'seats' => $cap['seats'], 'tonnage' => $cap['tonnage'], 'count' => 1,
                      'src' => trim(implode(' · ', array_filter([$r['car_kind'] ?? '', $r['car_usage'] ?? '', $pf['capacity'] ?? ''])))];
    }
    $list = array_values($out);
    usort($list, fn($a, $b) => [$a['kind'], $a['grp'], $a['title']] <=> [$b['kind'], $b['grp'], $b['title']]);
    return ['ok' => true, 'items' => $list, 'existing' => $dupe, 'rows' => count($rows), 'no_name' => $empty];
}
// ثبتِ مدل‌های انتخاب‌شده (گروه دوباره از روی نوعِ نهایی حساب می‌شود؛ تکراری دوباره بررسی می‌شود)
function vc_insert_items($pdo, array $items) {
    vc_ensure($pdo);
    $have = [];
    foreach ($pdo->query("SELECT title FROM vehicle_catalog") as $r) $have[vc_key($r['title'])] = 1;
    $ins = $pdo->prepare("INSERT INTO vehicle_catalog (kind, grp, title, cylinders, seats, tonnage, premium_cat, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
    $add = 0; $skip = 0; $rates = []; $byKind = [];
    foreach (array_slice($items, 0, 5000) as $it) {
        $title = mb_substr(vc_norm($it['title'] ?? ''), 0, 190); $kind = (string)($it['kind'] ?? '');
        if ($title === '' || !isset(VC_KINDS[$kind])) { $skip++; continue; }
        $key = vc_key($title);
        if (isset($have[$key])) { $skip++; continue; }
        $num = fn($k, $max) => isset($it[$k]) && is_numeric($it[$k]) && $it[$k] > 0 && $it[$k] <= $max ? 0 + $it[$k] : null;
        $cyl = $num('cylinders', 64); $seats = $num('seats', 999); $ton = $num('tonnage', 9999);
        $grp = vc_group_guess($pdo, $kind, $title, $cyl, $seats, $ton);
        if (!array_key_exists("$kind|$grp", $rates)) $rates["$kind|$grp"] = vc_group_rate($pdo, $kind, $grp);
        $ins->execute([$kind, mb_substr($grp, 0, 120), $title, $cyl, $seats, $ton, $rates["$kind|$grp"]]);
        $have[$key] = 1; $add++;
        $byKind[VC_KINDS[$kind]['fa']] = ($byKind[VC_KINDS[$kind]['fa']] ?? 0) + 1;
    }
    return ['ok' => true, 'added' => $add, 'skipped' => $skip, 'by_kind' => $byKind];
}
