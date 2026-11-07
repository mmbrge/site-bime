<?php
// فایل: api/ext_api.php
// وب‌سرویسِ «دستیار بیمه باما» (افزونه‌ی کروم). بدونِ نشستِ پنل؛ ورود با نام‌کاربری‌ای که مدیر برای هر بخش تعریف کرده،
// بعد از آن هر درخواست با توکنِ همان دستگاه. منطق: api/_ext.php
//   login {username, deviceId, version}  → توکن + بخش‌ها + قراردادها/سامانه‌ها/اعلان‌ها
//   sync {token, version, bridgeOnline, tunnelConnected, vpnIp} → همان بسته (یا needsLogin اگر دسترسی برداشته شد)
//   log {token, module, event, detail} · contract_for {token, no} · logout {token}
//   latest (GET، عمومی: فقط شماره‌ی نسخه) · package?token= (GET: ZIPِ افزونه برای بروزرسانیِ خودکار)
ini_set('display_errors', '0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_ext.php';

$out = function ($a, $code = 200) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$action = (string)($_GET['action'] ?? '');
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;
ext_ensure($pdo);

try {
    if ($action === 'latest') $out(['ok' => true, 'version' => ext_version()]);

    // ورود: نام‌کاربریِ یکی از بخش‌ها (اقساط یا آتی) → همه‌ی بخش‌هایی که همان کاربر نام‌کاربری دارد
    if ($action === 'login') {
        $name = ext_clean($body['username'] ?? '', 64); $key = ext_key($name);
        if ($key === '') $out(['ok' => false, 'message' => 'نام کاربری را وارد کنید.']);
        // جلوی حدس زدن: بیش از ۱۰ ورودِ ناموفق از یک IP در ۱۰ دقیقه
        $ip = ext_ip();
        $st = $pdo->prepare("SELECT COUNT(*) FROM ext_logs WHERE ip = ? AND event = 'ورودِ ناموفق' AND created_at > NOW() - INTERVAL 10 MINUTE"); $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 10) $out(['ok' => false, 'message' => 'تلاشِ ناموفقِ زیاد؛ ده دقیقه‌ی دیگر دوباره امتحان کنید.']);
        $st = $pdo->prepare("SELECT a.user_id, u.full_name, COALESCE(u.is_deleted, 0) AS del FROM ext_accounts a JOIN users u ON u.id = a.user_id WHERE a.uname_key = ? LIMIT 1");
        $st->execute([$key]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || (int)$u['del']) { ext_log($pdo, null, null, null, 'ورودِ ناموفق', ['username' => $name], $body['version'] ?? null); $out(['ok' => false, 'message' => 'این نام کاربری در پنل تعریف نشده است؛ از مدیر بخواهید در «دسترسی‌ها»ی شما نام‌کاربریِ افزونه را تعریف کند.']); }
        $tok = bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO ext_devices (user_id, token_hash, device_key, login_name, version, ip, last_seen, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([(int)$u['user_id'], hash('sha256', $tok), ext_clean($body['deviceId'] ?? '', 64), $name, ext_clean($body['version'] ?? '', 20), $ip]);
        $did = (int)$pdo->lastInsertId();
        // همان دستگاه با توکنِ قدیمی => کنار گذاشته می‌شود
        if (($dk = ext_clean($body['deviceId'] ?? '', 64)) !== '') $pdo->prepare("UPDATE ext_devices SET revoked = 1 WHERE user_id = ? AND device_key = ? AND id <> ?")->execute([(int)$u['user_id'], $dk, $did]);
        ext_log($pdo, (int)$u['user_id'], $did, null, 'ورود به افزونه', ['username' => $name], $body['version'] ?? null);
        $out(['token' => $tok] + ext_payload($pdo, (int)$u['user_id'], $u['full_name']));
    }

    if ($action === 'package') {
        $d = ext_device_by_token($pdo, $_GET['token'] ?? '');
        if (!$d || !ext_accounts_of($pdo, $d['user_id'])) { header('Content-Type: application/json; charset=utf-8'); $out(['ok' => false, 'error' => 'دسترسی ندارید.'], 403); }
        ext_log($pdo, (int)$d['user_id'], (int)$d['id'], null, 'دریافتِ بسته‌ی بروزرسانی', ['version' => ext_version()]);
        ext_send_package();
        exit;
    }

    // بقیه با توکن
    $d = ext_device_by_token($pdo, $body['token'] ?? '');
    if (!$d) $out(['ok' => false, 'needsLogin' => true, 'message' => 'نشستِ افزونه معتبر نیست؛ دوباره وارد شوید.']);
    $uid = (int)$d['user_id'];
    $acc = ext_accounts_of($pdo, $uid);
    if (!$acc) {
        $pdo->prepare("UPDATE ext_devices SET revoked = 1 WHERE id = ?")->execute([$d['id']]);
        $out(['ok' => false, 'needsLogin' => true, 'message' => 'نام‌کاربریِ افزونه‌ی شما در پنل برداشته شده است.']);
    }

    if ($action === 'sync') {
        // هر افزونه‌ی روشن (هر دقیقه) نگه‌دارِ نشست‌های پاسارگاد را اگر ایستاده راه می‌اندازد
        try { require_once __DIR__ . '/_keepalive.php'; ka_kick($pdo); } catch (Throwable $e) {}
        $pdo->prepare("UPDATE ext_devices SET last_seen = NOW(), version = ?, ip = ?, bridge_online = ?, tunnel = ?, vpn_ip = ? WHERE id = ?")
            ->execute([ext_clean($body['version'] ?? '', 20), ext_ip(), !empty($body['bridgeOnline']) ? 1 : 0, !empty($body['tunnelConnected']) ? 1 : 0, ext_clean($body['vpnIp'] ?? '', 45), $d['id']]);
        // نسخه‌ی برنامه‌ی زمینه و مسیرِ سامانه‌ها (نشانی‌هایی که از DNSِ VPN گرفته)؛ هر تغییر در فعالیت‌های همان کاربر ثبت می‌شود
        if (isset($body['bridgeVersion']) || isset($body['siteRoutes'])) {
            $bv = ext_clean($body['bridgeVersion'] ?? '', 20);
            $sr = isset($body['siteRoutes']) ? ext_clean_routes($body['siteRoutes']) : null;
            $prev = $pdo->prepare("SELECT bridge_ver, site_routes FROM ext_devices WHERE id = ?"); $prev->execute([$d['id']]); $pv = $prev->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->prepare("UPDATE ext_devices SET bridge_ver = IF(? = '', bridge_ver, ?), site_routes = IF(? IS NULL OR ? = '', site_routes, ?) WHERE id = ?")->execute([$bv, $bv, $sr, $sr, $sr, $d['id']]);
            if (isset($body['bridgeAdmin'])) $pdo->prepare("UPDATE ext_devices SET bridge_admin = ? WHERE id = ?")->execute([!empty($body['bridgeAdmin']) ? 1 : 0, $d['id']]);
            if ($sr !== null && $sr !== '' && $sr !== (string)($pv['site_routes'] ?? '')) ext_log($pdo, $uid, (int)$d['id'], 'ati', 'مسیرِ سامانه‌ها', ['مسیرها' => str_replace('=', ' ← ', $sr), 'برنامه‌ی زمینه' => $bv], $body['version'] ?? null);
            if ($bv !== '' && $bv !== (string)($pv['bridge_ver'] ?? '') && !empty($pv['bridge_ver'])) ext_log($pdo, $uid, (int)$d['id'], 'ati', 'بروزرسانیِ برنامه‌ی زمینه', ['از' => (string)$pv['bridge_ver'], 'به' => $bv], $body['version'] ?? null);
        }
        $out(ext_payload($pdo, $uid, $d['full_name']));
    }
    if ($action === 'log') {
        $m = isset(EXT_MODULES[$body['module'] ?? '']) ? $body['module'] : null;
        if ($m && empty($acc[$m])) $out(['ok' => false, 'error' => 'این بخش برای شما فعال نیست.']);
        $det = is_array($body['detail'] ?? null) ? array_slice($body['detail'], 0, 30, true) : [];
        ext_log($pdo, $uid, (int)$d['id'], $m, (string)($body['event'] ?? 'کار'), $det, $body['version'] ?? null);
        $out(['ok' => true]);
    }
    if ($action === 'contract_for') {
        if (empty($acc['inst']) || !empty($acc['inst']['is_blocked'])) $out(['ok' => false]);
        $no = trim(p2e_digits_ext((string)($body['no'] ?? '')));
        if (!preg_match('#^\d+/\d+/(?:[\d\-]+/)?1[34]\d{2}/\d+$#', $no)) $out(['ok' => false]);
        $c = ext_contract_for_draft($pdo, $no);
        if (!$c) $out(['ok' => true, 'contract_id' => null]);
        $allowed = $acc['inst']['allowed'] ?: [];
        if ($allowed && !in_array($c['contract_id'], $allowed, true)) $out(['ok' => true, 'contract_id' => null]);
        $out(['ok' => true] + $c);
    }
    if ($action === 'logout') {
        $pdo->prepare("UPDATE ext_devices SET revoked = 1 WHERE id = ?")->execute([$d['id']]);
        ext_log($pdo, $uid, (int)$d['id'], null, 'خروج از افزونه');
        $out(['ok' => true]);
    }
    $out(['ok' => false, 'error' => 'درخواست نامعتبر.'], 400);
} catch (Throwable $e) {
    error_log('[ext_api] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور.'], 500);
}
