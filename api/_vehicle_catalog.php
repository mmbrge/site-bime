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
