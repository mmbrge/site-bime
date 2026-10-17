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
    return array_map(fn($r) => ['grp' => $r['grp'], 'n' => (int)$r['n'], 'has_rate' => (int)$r['has_rate']], $st->fetchAll(PDO::FETCH_ASSOC));
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
