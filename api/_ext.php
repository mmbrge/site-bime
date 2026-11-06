<?php
// فایل: api/_ext.php
// «افزونه‌ها»: دستیارِ بیمه باما (یکی‌شده‌ی افزونه‌ی اقساطِ پاسارگاد + درگاهِ آتیِ بیمه ایران)
//   - هر کاربرِ پنل برای هر بخش (inst = اقساط، ati = درگاهِ آتی) نام‌کاربریِ جدا دارد؛ بدونِ آن، آن بخش در افزونه بسته است
//   - هر نصبِ افزونه یک «دستگاه» با توکنِ خودش است (فقط هشِ توکن نگه داشته می‌شود)
//   - همه‌ی کارهای افزونه با نامِ کاربرِ پنل در ext_logs ثبت می‌شود
//   - قراردادهای اقساط همان «مالی ← قراردادها»ی سایت است؛ قراردادِ هر پیش‌نویس از ردیفِ درخواستِ وصل‌شده‌اش
//   - بسته‌ی افزونه از پوشه‌ی extension/bimebama ساخته می‌شود و نشانیِ همین سایت داخلش نوشته می‌شود
const EXT_MODULES = ['inst' => 'اقساطِ پاسارگاد', 'ati' => 'درگاهِ آتیِ بیمه ایران'];
const EXT_DIR_REL = '/extension/bimebama';

function ext_ensure($pdo) {
    static $done = false; if ($done) return; $done = true;
    foreach ([
        "CREATE TABLE IF NOT EXISTS ext_accounts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(10) NOT NULL, username VARCHAR(64) NOT NULL, uname_key VARCHAR(64) NOT NULL,
            is_blocked TINYINT(1) NOT NULL DEFAULT 0, allowed_json TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY u_mod_name (module, uname_key), UNIQUE KEY u_user_mod (user_id, module)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ext_devices (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL, device_key VARCHAR(64) NULL, login_name VARCHAR(64) NULL,
            version VARCHAR(20) NULL, ip VARCHAR(45) NULL, bridge_online TINYINT(1) NOT NULL DEFAULT 0, tunnel TINYINT(1) NOT NULL DEFAULT 0, vpn_ip VARCHAR(45) NULL,
            last_seen DATETIME NULL, created_at DATETIME NULL, revoked TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY u_tok (token_hash), KEY k_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ext_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, device_id INT NULL, module VARCHAR(10) NULL, event VARCHAR(120) NOT NULL, detail TEXT NULL,
            ip VARCHAR(45) NULL, version VARCHAR(20) NULL, created_at DATETIME NOT NULL, KEY k_user (user_id, created_at), KEY k_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ext_notifs (id INT AUTO_INCREMENT PRIMARY KEY, module VARCHAR(10) NOT NULL DEFAULT 'all', user_id INT NULL, title VARCHAR(190) NOT NULL, message TEXT NULL, created_at DATETIME NOT NULL)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[ext_ensure] ' . $e->getMessage()); } }
}

// ---------- نام‌کاربری ----------
function ext_key($name) {
    $s = strtr(trim((string)$name), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']);
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{200C}\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}\s_\-\.]+/u', '', $s);
    return mb_substr(mb_strtolower($s, 'UTF-8'), 0, 64);
}
function ext_clean($v, $max = 190) { return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', (string)$v)), 0, $max); }

function ext_accounts_of($pdo, $userId) {
    ext_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM ext_accounts WHERE user_id = ?"); $st->execute([intval($userId)]);
    $o = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) { $a['allowed'] = json_decode((string)$a['allowed_json'], true) ?: []; $o[$a['module']] = $a; }
    return $o;
}
// ذخیره/حذفِ نام‌کاربریِ یک بخش برای یک کاربر (خالی = حذف = آن بخش برایش بسته)
function ext_account_save($pdo, $userId, $module, $username, $blocked = false, $allowed = null) {
    ext_ensure($pdo);
    if (!isset(EXT_MODULES[$module])) return ['ok' => false, 'error' => 'بخشِ نامعتبر.'];
    $userId = intval($userId);
    $username = ext_clean($username, 64); $key = ext_key($username);
    if ($username === '' || $key === '') { $pdo->prepare("DELETE FROM ext_accounts WHERE user_id = ? AND module = ?")->execute([$userId, $module]); return ['ok' => true, 'removed' => true]; }
    if (mb_strlen($key) < 2) return ['ok' => false, 'error' => 'نام‌کاربری خیلی کوتاه است.'];
    // یک نام‌کاربری (در هر دو بخش) فقط مالِ یک نفر است
    $st = $pdo->prepare("SELECT a.user_id, u.full_name FROM ext_accounts a LEFT JOIN users u ON u.id = a.user_id WHERE a.uname_key = ? AND a.user_id <> ? LIMIT 1");
    $st->execute([$key, $userId]);
    if ($o = $st->fetch()) return ['ok' => false, 'error' => 'نام‌کاربریِ «' . $username . '» مالِ ' . ($o['full_name'] ?: 'کاربرِ دیگری') . ' است.'];
    $al = is_array($allowed) ? array_values(array_unique(array_map(function ($x) { return ext_clean($x, 40); }, $allowed))) : null;
    $pdo->prepare("INSERT INTO ext_accounts (user_id, module, username, uname_key, is_blocked, allowed_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE username = VALUES(username), uname_key = VALUES(uname_key), is_blocked = VALUES(is_blocked), allowed_json = COALESCE(VALUES(allowed_json), allowed_json), updated_at = NOW()")
        ->execute([$userId, $module, $username, $key, $blocked ? 1 : 0, $al === null ? null : json_encode($al, JSON_UNESCAPED_UNICODE)]);
    if ($al === null) {} // دست‌نخورده
    return ['ok' => true];
}

// ---------- تنظیمات ----------
function ext_default_sites() {
    return [['id' => 'site_sales', 'title' => 'سامانه فروش / شخص ثالث', 'url' => 'http://sales.iic.local/views/secured/release.html', 'color' => '#0284c7'],
            ['id' => 'site_hull', 'title' => 'سامانه صدور بیمه بدنه', 'url' => 'http://hullissu.iic.local/iic-cas-body/login', 'color' => '#10b981']];
}
function ext_settings($pdo) {
    $s = [];
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ext_settings'"); $st->execute(); $s = json_decode((string)$st->fetchColumn(), true) ?: []; } catch (Throwable $e) {}
    $ati = (array)($s['ati'] ?? []); $inst = (array)($s['inst'] ?? []);
    return [
        'ati' => ['conn' => (string)($ati['conn'] ?? 'Auto_Internet'), 'vpn_user' => (string)($ati['vpn_user'] ?? ''), 'vpn_pass' => (string)($ati['vpn_pass'] ?? ''),
                  'routes' => array_values((array)($ati['routes'] ?? ['10.0.0.0/8', '172.16.0.0/12', '20.0.0.0/8'])), 'sites' => array_values((array)($ati['sites'] ?? ext_default_sites()))],
        'inst' => ['default_account' => (string)($inst['default_account'] ?? '5366595106'), 'accounts' => (array)($inst['accounts'] ?? []), 'contracts' => array_values(array_map('intval', (array)($inst['contracts'] ?? []))),
                   'date_basis' => (array)($inst['date_basis'] ?? [])],
        'changelog' => (string)($s['changelog'] ?? ''),
    ];
}
function ext_settings_save($pdo, array $d) {
    $s = ext_settings($pdo);
    if (isset($d['ati']) && is_array($d['ati'])) {
        $a = $d['ati'];
        if (isset($a['conn'])) { $c = ext_clean($a['conn'], 64); if (preg_match('/^[\w\- \.]{1,64}$/u', $c)) $s['ati']['conn'] = $c; }
        if (isset($a['vpn_user'])) $s['ati']['vpn_user'] = ext_clean($a['vpn_user'], 120);
        if (isset($a['vpn_pass']) && $a['vpn_pass'] !== '••••••') $s['ati']['vpn_pass'] = mb_substr((string)$a['vpn_pass'], 0, 120);
        if (isset($a['routes'])) $s['ati']['routes'] = array_values(array_filter(array_map('trim', is_array($a['routes']) ? $a['routes'] : preg_split('/[\s,]+/', (string)$a['routes'])), function ($r) { return (bool)preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $r); }));
        if (isset($a['sites']) && is_array($a['sites'])) {
            $sites = [];
            foreach ($a['sites'] as $x) {
                $url = trim((string)($x['url'] ?? '')); if (!preg_match('#^https?://#i', $url)) continue;
                $sites[] = ['id' => preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($x['id'] ?? '')) ?: 'site_' . bin2hex(random_bytes(3)), 'title' => ext_clean($x['title'] ?? '', 80) ?: $url,
                            'url' => mb_substr($url, 0, 300), 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($x['color'] ?? '')) ? $x['color'] : '#0284c7'];
            }
            $s['ati']['sites'] = $sites;
        }
    }
    if (isset($d['inst']) && is_array($d['inst'])) {
        $i = $d['inst'];
        if (isset($i['default_account'])) $s['inst']['default_account'] = preg_replace('/\D/', '', p2e_digits_ext((string)$i['default_account']));
        if (isset($i['accounts']) && is_array($i['accounts'])) { $acc = []; foreach ($i['accounts'] as $cid => $n) { $n = preg_replace('/\D/', '', p2e_digits_ext((string)$n)); if ($n !== '') $acc[(string)intval($cid)] = $n; } $s['inst']['accounts'] = $acc; }
        if (isset($i['contracts'])) $s['inst']['contracts'] = array_values(array_filter(array_map('intval', (array)$i['contracts'])));
        if (isset($i['date_basis']) && is_array($i['date_basis'])) { $db = []; foreach ($i['date_basis'] as $cid => $v) if (in_array($v, ['issue', 'today', 'both'], true)) $db[(string)intval($cid)] = $v; $s['inst']['date_basis'] = $db; }
    }
    if (isset($d['changelog'])) $s['changelog'] = ext_clean($d['changelog'], 1000);
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('ext_settings', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'settings' => ext_settings_public($pdo)];
}
function ext_settings_public($pdo) { $s = ext_settings($pdo); $s['ati']['vpn_pass'] = $s['ati']['vpn_pass'] !== '' ? '••••••' : ''; return $s; }
function p2e_digits_ext($s) { return strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']); }

// ---------- قراردادهای اقساط (از «مالی ← قراردادها»ی سایت) به شکلِ افزونه ----------
function ext_contracts($pdo, array $allowed = []) {
    if (!function_exists('fin_contracts_ensure')) require_once __DIR__ . '/finance_core.php';
    try { fin_contracts_ensure($pdo); $rows = $pdo->query("SELECT * FROM fin_contracts WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $rows = []; }
    $s = ext_settings($pdo);
    $psgAcc = [];
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'psgf_settings'"); $st->execute(); $psgAcc = (array)((json_decode((string)$st->fetchColumn(), true) ?: [])['accounts'] ?? []); } catch (Throwable $e) {}
    $out = [];
    foreach ($rows as $c) {
        $id = (int)$c['id'];
        if ($s['inst']['contracts'] && !in_array($id, $s['inst']['contracts'], true)) continue;
        $cid = 'fc_' . $id;
        if ($allowed && !in_array($cid, $allowed, true)) continue;
        $cash = ($c['pay_type'] ?? '') === 'CASH';
        $m = (int)$c['first_due_months']; $dd = (int)$c['first_due_days'];
        $acc = $s['inst']['accounts'][(string)$id] ?? (isset($psgAcc[(string)$id]['desc']) ? preg_replace('/\D/', '', (string)$psgAcc[(string)$id]['desc']) : '') ?: $s['inst']['default_account'];
        $out[] = ['id' => $cid, 'name' => (string)$c['name'] . ($c['contract_no'] ? ' (' . $c['contract_no'] . ')' : ''), 'isCash' => $cash,
                  'numInstallments' => $cash ? 1 : max(1, (int)$c['installment_count']),
                  'firstDueType' => $cash ? 'same_day' : ($m > 0 ? 'months' : ($dd > 0 ? 'days' : 'same_day')), 'firstDueValue' => $m > 0 ? $m : $dd,
                  'intervalUnit' => 'month', 'intervalValue' => max(1, (int)($c['interval_months'] ?? 1)), 'bankAccount' => (string)$acc,
                  'dateBasis' => $s['inst']['date_basis'][(string)$id] ?? 'issue'];
    }
    return $out;
}
// قراردادِ پیش‌نویس: همان منطقِ psgf_resolve (ردیفِ سایت ← موافقت‌نامه‌ی پیش‌نویس ← پیش‌فرضِ رشته)
function ext_contract_for_draft($pdo, $no) {
    require_once __DIR__ . '/_psg_tools.php';
    $r = psgf_resolve($pdo, $no);
    if (!$r) return null;
    $r['contract_id'] = 'fc_' . $r['contract_id'];
    return $r;
}

// ---------- دستگاه‌ها و لاگ ----------
function ext_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) if (!empty($_SERVER[$k])) { $ip = trim(explode(',', (string)$_SERVER[$k])[0]); if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip; }
    return '';
}
function ext_device_by_token($pdo, $token) {
    ext_ensure($pdo);
    $token = preg_replace('/[^A-Za-z0-9]/', '', (string)$token);
    if (strlen($token) < 20) return null;
    $st = $pdo->prepare("SELECT d.*, u.full_name, COALESCE(u.is_deleted, 0) AS u_deleted FROM ext_devices d JOIN users u ON u.id = d.user_id WHERE d.token_hash = ? AND d.revoked = 0");
    $st->execute([hash('sha256', $token)]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    return $d && !(int)$d['u_deleted'] ? $d : null;
}
function ext_log($pdo, $userId, $deviceId, $module, $event, $detail = null, $version = null) {
    try {
        $pdo->prepare("INSERT INTO ext_logs (user_id, device_id, module, event, detail, ip, version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$userId ?: null, $deviceId ?: null, $module, ext_clean($event, 120), $detail === null ? null : mb_substr(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 4000), ext_ip(), $version ? substr(preg_replace('/[^0-9A-Za-z.\-]/', '', (string)$version), 0, 20) : null]);
    } catch (Throwable $e) { error_log('[ext_log] ' . $e->getMessage()); }
}

// ---------- بسته‌ی دانلود ----------
function ext_dir() { return dirname(__DIR__) . EXT_DIR_REL; }
// نسخه = نسخه‌ی manifest (مثلاً 5.1.0) + شماره‌ی ساخت از تازه‌ترین تغییرِ فایل‌های افزونه (ساعت از ۱۴۰۴/۱۰/۱۱)؛
// با هر بار آپلودِ فایل‌های تازه‌ی افزونه، نسخه خودش بالا می‌رود و افزونه‌های نصب‌شده روی همان پوشه بروز می‌شوند
function ext_base_version() { $m = json_decode((string)@file_get_contents(ext_dir() . '/manifest.json'), true); return (string)($m['version'] ?? '0'); }
function ext_version() {
    static $v = null;
    if ($v !== null) return $v;
    $base = ext_base_version();
    if (!preg_match('/^\d+\.\d+\.\d+$/', $base)) return $v = $base;
    $mt = 0;
    try { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ext_dir(), FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $mt = max($mt, $f->getMTime()); } catch (Throwable $e) {}
    $build = $mt ? max(1, min(65535, intdiv($mt - 1767225600, 3600))) : 0;   // 2026-01-01
    return $v = $base . '.' . $build;
}
function ext_id() {
    $m = json_decode((string)@file_get_contents(ext_dir() . '/manifest.json'), true);
    $k = base64_decode((string)($m['key'] ?? ''));
    return $k ? strtr(substr(hash('sha256', $k), 0, 32), '0123456789abcdef', 'abcdefghijklmnop') : '';
}
// نشانیِ همین سایت (پوشه‌ای که api/ در آن است)
function ext_origin() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $base = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $base;
}
// ZIP در پوشه‌ی موقت: فایل‌های افزونه در ریشه با نشانیِ سایت در فایل‌ها (و نصابِ ویندوز کنارِ manifest)
function ext_build_package() {
    if (!class_exists('ZipArchive')) return null;
    $dir = ext_dir(); if (!is_file($dir . '/manifest.json')) return null;
    $origin = ext_origin();
    $rep = ['__BB_SERVER_PATTERN__' => preg_replace('#^(https?://[^/]+).*$#', '$1', $origin) . '/*', '__BB_SERVER__' => $origin, '__BB_EXT_ID__' => ext_id()];
    // فایل‌ها در ریشه‌ی ZIP (بدونِ پوشه‌ی میانی): «Extract All»ِ ویندوز پوشه‌ای هم‌نامِ ZIP می‌سازد که manifest.json مستقیم داخلش است
    // (با پوشه‌ی میانی دو پوشه‌ی تو‌در‌تو ساخته می‌شد و کروم خطای «Manifest file is missing» می‌داد)
    $root = '';
    $tmp = tempnam(sys_get_temp_dir(), 'bbext'); @unlink($tmp); $tmp .= '.zip';
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
        if ($rel === '' || $rel[0] === '.' || strpos($rel, '/.') !== false) continue;
        $data = (string)file_get_contents($f->getPathname());
        if ($rel === 'manifest.json') $data = preg_replace('/"version"\s*:\s*"[^"]*"/', '"version": "' . ext_version() . '"', $data, 1);
        if (preg_match('/\.(js|json|html|bat)$/i', $rel)) $data = strtr($data, $rep);
        $z->addFromString($root . $rel, $data);
    }
    $z->close();
    return $tmp;
}
function ext_send_package() {
    $p = ext_build_package();
    if (!$p) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => 'ساختِ بسته ممکن نشد (ZipArchive).'], JSON_UNESCAPED_UNICODE); return; }
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="bimebama-assistant-v' . ext_version() . '.zip"');
    header('Content-Length: ' . filesize($p));
    header('Cache-Control: no-store');
    readfile($p); @unlink($p);
}

// ---------- پاسخِ افزونه (ورود/همگام‌سازی) ----------
function ext_payload($pdo, $userId, $fullName) {
    $acc = ext_accounts_of($pdo, $userId);
    $s = ext_settings($pdo);
    $mods = []; foreach (EXT_MODULES as $m => $_) $mods[$m] = ['allowed' => isset($acc[$m]), 'blocked' => !empty($acc[$m]['is_blocked']), 'username' => $acc[$m]['username'] ?? ''];
    $instOk = $mods['inst']['allowed'] && !$mods['inst']['blocked'];
    $atiOk = $mods['ati']['allowed'] && !$mods['ati']['blocked'];
    $sites = [];
    if ($atiOk) { $al = $acc['ati']['allowed'] ?: []; foreach ($s['ati']['sites'] as $x) if (!$al || in_array($x['id'], $al, true)) $sites[] = $x; }
    $notifs = [];
    try {
        $st = $pdo->prepare("SELECT id, module, title, message, created_at FROM ext_notifs WHERE (user_id IS NULL OR user_id = ?) ORDER BY id DESC LIMIT 20");
        $st->execute([intval($userId)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) if ($n['module'] === 'all' || !empty($mods[$n['module']]['allowed'])) $notifs[] = ['id' => 'n' . $n['id'], 'title' => $n['title'], 'message' => (string)$n['message']];
    } catch (Throwable $e) {}
    $loginName = $acc['inst']['username'] ?? ($acc['ati']['username'] ?? '');
    return ['ok' => true, 'user' => ['name' => $loginName, 'display' => (string)$fullName], 'modules' => $mods,
            'contracts' => $instOk ? ext_contracts($pdo, $acc['inst']['allowed'] ?: []) : [],
            'sites' => $sites,
            'vpnConfig' => $atiOk ? ['connectionName' => $s['ati']['conn'], 'username' => $s['ati']['vpn_user'], 'password' => $s['ati']['vpn_pass'], 'routes' => $s['ati']['routes']] : ['connectionName' => $s['ati']['conn']],
            'notifications' => $notifs, 'latestUpdate' => ['version' => ext_version(), 'changelog' => $s['changelog']]];
}
