<?php
// فایل: api/_security.php
// «سپر امنیتی»: از انتهای config/db.php روی همه‌ی درخواست‌ها اجرا می‌شود. هیچ فایلِ دیگری را include نمی‌کند
// (همه‌ی توابع با پیشوندِ sec_ هستند تا با کدِ دیگر تداخل نکنند).
//  - سرتیترهای امنیتی، خاموش‌کردنِ نمایشِ خطا، کوکیِ نشستِ HttpOnly/SameSite
//  - مسدودسازیِ IP، جلوگیری از درخواستِ جعلی از سایتِ دیگر (CSRF با بررسیِ Origin)
//  - ردِ آپلودِ فایل‌های اجرایی (php و ...)، بررسیِ کلیدِ وب‌هوکِ ربات‌ها
//  - خروجِ خودکار بعد از عدمِ فعالیت (پیش‌فرض ۳ ساعت) و خروجِ اجباری توسطِ مدیر
//  - محدودیتِ تلاشِ ناموفقِ ورود، و ثبتِ همه‌ی رخدادها در security_events برای گزارشِ «سپر امنیتی»

const SEC_DEFAULTS = ['sec_idle_hours' => '3', 'sec_max_attempts' => '5', 'sec_lock_minutes' => '15', 'sec_notify' => '1', 'sec_waf' => '1'];
const SEC_BAD_EXT = '/\.(php\d?|phtml|phar|pht|phps|pgif|shtml|cgi|pl|py|sh|bash|asp|aspx|jsp|jspx|exe|bat|cmd|com|scr|htaccess|htpasswd|ini|svg|svgz|html?|xhtml|js|mjs)(\.|$)/i';
const SEC_EVENT_FA = [
    'LOGIN_FAIL' => 'ورودِ ناموفق', 'LOGIN_LOCK' => 'قفلِ ورود (تلاشِ زیاد)', 'LOGIN_BLOCKED' => 'تلاشِ ورود در زمانِ قفل', 'NEW_IP' => 'ورود از IP تازه',
    'IDLE_LOGOUT' => 'خروجِ خودکار (عدمِ فعالیت)', 'FORCED_LOGOUT' => 'خروجِ اجباری توسطِ مدیر', 'CSRF_BLOCK' => 'درخواستِ جعلی از سایتِ دیگر',
    'UPLOAD_BLOCK' => 'آپلودِ فایلِ خطرناک', 'WEBHOOK_FORGED' => 'پیامِ جعلی به ربات', 'IP_BLOCKED' => 'درخواست از IPِ مسدود', 'SUSPICIOUS_INPUT' => 'ورودیِ مشکوک',
    'PERM_DENIED' => 'تلاش برای دسترسیِ غیرمجاز', 'FILE_DENIED' => 'دسترسیِ بدونِ ورود به فایلِ بایگانی', 'ADMIN_ACTION' => 'تغییرِ تنظیماتِ امنیتی',
];

function sec_ip() { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function sec_is_api() { return strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/api/') !== false; }
function sec_script() { return basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''))); }

function sec_settings($pdo) {
    static $s = null;
    if ($s !== null) return $s;
    $s = SEC_DEFAULTS;
    try {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'sec\\_%' OR setting_key IN ('company_bot_hook_key', 'bot_hook_key')")->fetchAll() as $r) {
            $s[$r['setting_key']] = (string)$r['setting_value'];
        }
    } catch (Throwable $e) {}
    return $s;
}

function sec_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $opt = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS security_events (id BIGINT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, type VARCHAR(30) NOT NULL, severity VARCHAR(10) NOT NULL,
            user_type VARCHAR(10) NULL, user_id INT NULL, user_name VARCHAR(120) NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(300) NULL, path VARCHAR(300) NULL,
            detail VARCHAR(1000) NULL, KEY k_at (at), KEY k_type (type, at), KEY k_ip (ip, at)) $opt",
        "CREATE TABLE IF NOT EXISTS sec_blocked_ips (ip VARCHAR(45) PRIMARY KEY, reason VARCHAR(300) NULL, until_at DATETIME NULL, created_at DATETIME NULL, created_by INT NULL) $opt",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[sec_ensure] ' . $e->getMessage()); } }
    foreach (['users', 'company_portal_users'] as $t) {
        try { $pdo->query("SELECT sess_epoch FROM $t LIMIT 0"); }
        catch (Throwable $e) { try { $pdo->exec("ALTER TABLE $t ADD COLUMN sess_epoch INT NOT NULL DEFAULT 0"); } catch (Throwable $e2) {} }
    }
}

// چه کسی درخواست را فرستاده (از روی نشست)
function sec_actor() {
    if (session_status() !== PHP_SESSION_ACTIVE) return [null, null, null];
    if (session_name() === 'bime_company_portal') {
        return !empty($_SESSION['company_user_id']) ? ['COMPANY', intval($_SESSION['company_user_id']), (string)($_SESSION['company_user_full_name'] ?? '')] : [null, null, null];
    }
    return !empty($_SESSION['user_id']) ? ['STAFF', intval($_SESSION['user_id']), (string)($_SESSION['full_name'] ?? '')] : [null, null, null];
}

const SEC_SEV_OF = ['LOGIN_FAIL' => 'low', 'LOGIN_LOCK' => 'high', 'LOGIN_BLOCKED' => 'medium', 'NEW_IP' => 'info', 'IDLE_LOGOUT' => 'info', 'FORCED_LOGOUT' => 'info',
                    'CSRF_BLOCK' => 'high', 'UPLOAD_BLOCK' => 'critical', 'WEBHOOK_FORGED' => 'critical', 'IP_BLOCKED' => 'medium', 'SUSPICIOUS_INPUT' => 'medium',
                    'PERM_DENIED' => 'medium', 'FILE_DENIED' => 'medium', 'ADMIN_ACTION' => 'info'];

function sec_event($pdo, $type, $detail = '', $userName = null, $severity = null) {
    sec_ensure($pdo);
    [$ut, $uid, $un] = sec_actor();
    $sev = $severity ?: (SEC_SEV_OF[$type] ?? 'medium');
    try {
        $pdo->prepare("INSERT INTO security_events (at, type, severity, user_type, user_id, user_name, ip, user_agent, path, detail) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$type, $sev, $ut, $uid, mb_substr((string)($userName ?? $un), 0, 120), sec_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
                       mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 300), mb_substr((string)$detail, 0, 1000)]);
    } catch (Throwable $e) { error_log('[sec_event] ' . $e->getMessage()); }
    if (in_array($sev, ['high', 'critical'], true)) sec_notify($pdo, $type, $detail);
    if (mt_rand(1, 500) === 1) { try { $pdo->exec("DELETE FROM security_events WHERE at < DATE_SUB(NOW(), INTERVAL 365 DAY)"); } catch (Throwable $e) {} }
}

// اعلانِ رخدادِ مهم به مدیرانِ کل در ربات بله‌ی شرکت‌ها (هر نوع حداکثر هر ۱۰ دقیقه یک بار)
function sec_notify($pdo, $type, $detail) {
    if ((sec_settings($pdo)['sec_notify'] ?? '1') !== '1' || !function_exists('curl_init')) return;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM security_events WHERE type = ? AND at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $st->execute([$type]);
        if (intval($st->fetchColumn()) > 1) return;
        $token = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_bot_token'")->fetchColumn();
        if ($token === '') return;
        $chats = $pdo->query("SELECT bale_chat_id FROM users WHERE role = 'ADMIN' AND bale_chat_id IS NOT NULL AND bot_linked_at IS NOT NULL AND COALESCE(is_deleted, 0) = 0")->fetchAll(PDO::FETCH_COLUMN);
        $text = "🛡 سپر امنیتی: " . (SEC_EVENT_FA[$type] ?? $type) . "\n" . mb_substr((string)$detail, 0, 300) . "\nIP: " . sec_ip() . "\nجزئیات در «تنظیمات ← سپر امنیتی».";
        $base = rtrim(getenv('CBOT_API_BASE') ?: 'https://tapi.bale.ai', '/');
        foreach ($chats as $c) {
            $ch = curl_init($base . "/bot{$token}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
                                    CURLOPT_POSTFIELDS => json_encode(['chat_id' => $c, 'text' => $text], JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
            curl_exec($ch); curl_close($ch);
        }
    } catch (Throwable $e) {}
}

function sec_deny($code, $msg, array $extraHeaders = []) {
    if (!headers_sent()) {
        http_response_code($code);
        foreach ($extraHeaders as $h) header($h);
    }
    if (sec_is_api() || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg, 'security' => true, 'session_expired' => (bool)preg_grep('/^X-Session-Expired:/', $extraHeaders), 'forced' => in_array('X-Session-Expired: forced', $extraHeaders, true)], JSON_UNESCAPED_UNICODE);
    } else {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>دسترسی مسدود</title><body style="font-family:tahoma;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
           . '<div style="text-align:center;max-width:420px;padding:24px;border:1px solid #334155;border-radius:18px;background:#1e293b"><div style="font-size:42px">🛡</div><h3>'
           . htmlspecialchars($msg) . '</h3><p style="font-size:12px;color:#94a3b8">اگر فکر می‌کنید اشتباه شده، با مدیر سامانه تماس بگیرید.</p></div></body></html>';
    }
    exit;
}

// نشستِ کاربر را می‌بندد (برای خروجِ خودکار یا اجباری) و همان لحظه «آفلاین» نشانش می‌دهد
function sec_kill_session($pdo = null) {
    [$ut, $uid] = sec_actor();
    if ($pdo && $uid) {
        try { $pdo->prepare("UPDATE " . ($ut === 'COMPANY' ? 'company_portal_users' : 'users') . " SET last_offline_at = NOW() WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {}
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_regenerate_id(true);
        @session_destroy();
    }
}

// کوکیِ نشست: HttpOnly + SameSite=Lax (+ Secure روی HTTPS) - برای هر شناسه‌ی نشست یک بار
function sec_cookie() {
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent() || ($_SESSION['_sec_ck'] ?? '') === session_id()) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), ['expires' => 0, 'path' => $p['path'] ?: '/', 'domain' => $p['domain'] ?? '', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    $_SESSION['_sec_ck'] = session_id();
}

// خروجِ خودکار بعد از عدمِ فعالیت + خروجِ اجباری. «فعالیت» = بارگذاریِ صفحه، یا درخواستی که سرتیترِ X-User-Activity دارد
// (اسکریپتِ idle-guard.js فقط وقتی کاربر واقعاً با صفحه کار کرده آن را می‌فرستد؛ نظرسنجی‌های پس‌زمینه حساب نمی‌شوند)
function sec_session_guard($pdo) {
    static $done = [];
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    sec_cookie();
    $ctx = session_name() === 'bime_company_portal' ? 'COMPANY' : 'STAFF';
    if (isset($done[$ctx])) return;
    $uid = $ctx === 'COMPANY' ? intval($_SESSION['company_user_id'] ?? 0) : intval($_SESSION['user_id'] ?? 0);
    if (!$uid) return;
    $done[$ctx] = true;
    $s = sec_settings($pdo);
    $idle = max(0.25, floatval($s['sec_idle_hours'] ?? 3)) * 3600;
    $now = time();
    $page = in_array(sec_script(), ['dashboard.php'], true) || ($ctx === 'COMPANY' && sec_script() === 'index.php');
    $active = $page || ($_SERVER['HTTP_X_USER_ACTIVITY'] ?? '') === '1';
    if (empty($_SESSION['_sec_act'])) $_SESSION['_sec_act'] = $now;
    $loginPage = $ctx === 'COMPANY' ? '../index.php?idle=1' : 'index.php?idle=1';
    if ($now - intval($_SESSION['_sec_act']) > $idle) {
        $name = $ctx === 'COMPANY' ? ($_SESSION['company_user_full_name'] ?? '') : ($_SESSION['full_name'] ?? '');
        sec_event($pdo, 'IDLE_LOGOUT', 'بعد از ' . round(($now - intval($_SESSION['_sec_act'])) / 60) . ' دقیقه بدونِ فعالیت', $name);
        sec_kill_session($pdo);
        if (!sec_is_api() && !headers_sent()) { header('Location: ' . $loginPage); exit; }
        sec_deny(401, 'به دلیلِ عدمِ فعالیت از پنل خارج شدید؛ دوباره وارد شوید.', ['X-Session-Expired: 1']);
    }
    if ($active) $_SESSION['_sec_act'] = $now;
    // خروجِ اجباری: مدیر عددِ sess_epoch کاربر را بالا می‌برد (هر ۱۰ ثانیه یک بار بررسی می‌شود)
    if ($now - intval($_SESSION['_sec_ep_chk'] ?? 0) >= 10) {
        $_SESSION['_sec_ep_chk'] = $now;
        try {
            $st = $pdo->prepare("SELECT sess_epoch FROM " . ($ctx === 'COMPANY' ? 'company_portal_users' : 'users') . " WHERE id = ?");
            $st->execute([$uid]);
            $ep = $st->fetchColumn();
            if ($ep !== false) {
                if (!isset($_SESSION['_sec_epoch'])) $_SESSION['_sec_epoch'] = intval($ep);
                elseif (intval($ep) !== intval($_SESSION['_sec_epoch'])) {
                    sec_event($pdo, 'FORCED_LOGOUT', 'نشست توسطِ مدیر بسته شد');
                    sec_kill_session($pdo);
                    $forcedPage = str_replace('idle=1', 'forced=1', $loginPage);
                    if (!sec_is_api() && !headers_sent()) { header('Location: ' . $forcedPage); exit; }
                    sec_deny(401, 'مدیر نشستِ شما را بست؛ دوباره وارد شوید.', ['X-Session-Expired: forced']);
                }
            }
        } catch (Throwable $e) {}
    }
}

// هنگامِ ورودِ موفق: شروعِ شمارشِ فعالیت و ثبتِ نسخه‌ی نشست
function sec_on_login($pdo, $type, $uid, $name = '') {
    $_SESSION['_sec_act'] = time();
    $_SESSION['_sec_ep_chk'] = time();
    try {
        sec_ensure($pdo);
        $st = $pdo->prepare("SELECT sess_epoch FROM " . ($type === 'COMPANY' ? 'company_portal_users' : 'users') . " WHERE id = ?");
        $st->execute([intval($uid)]);
        $_SESSION['_sec_epoch'] = intval($st->fetchColumn());
        // ورود از IP‌ای که قبلاً از آن وارد نشده
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE user_type = ? AND user_id = ? AND success = 1 AND method <> 'LOGOUT' AND ip = ? AND created_at < DATE_SUB(NOW(), INTERVAL 5 SECOND)");
        $st->execute([$type, intval($uid), sec_ip()]);
        $c = $pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE user_type = ? AND user_id = ? AND success = 1 AND method <> 'LOGOUT'");
        $c->execute([$type, intval($uid)]);
        if (intval($st->fetchColumn()) === 0 && intval($c->fetchColumn()) > 1) sec_event($pdo, 'NEW_IP', 'اولین ورود از این IP', $name);
    } catch (Throwable $e) {}
}

// ---------------- محدودیتِ تلاشِ ناموفقِ ورود ----------------
// قفل برای همان نام کاربری (از هر IP)، و برای IP فقط با سه برابرِ حد (چون همه‌ی همکارانِ یک دفتر معمولاً یک IP دارند)
function sec_fail_counts($pdo, $username, $mins) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM security_events WHERE type = 'LOGIN_FAIL' AND at > DATE_SUB(NOW(), INTERVAL ? MINUTE) AND user_name = ?");
    $st->execute([intval($mins), mb_substr((string)$username, 0, 120)]);
    $byUser = $st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM security_events WHERE type = 'LOGIN_FAIL' AND at > DATE_SUB(NOW(), INTERVAL ? MINUTE) AND ip = ?");
    $st->execute([intval($mins), sec_ip()]);
    return [intval($byUser), intval($st->fetchColumn())];
}
function sec_login_locked($pdo, $username) {
    $s = sec_settings($pdo);
    $max = max(3, intval($s['sec_max_attempts'] ?? 5)); $mins = max(1, intval($s['sec_lock_minutes'] ?? 15));
    try {
        sec_ensure($pdo);
        [$byUser, $byIp] = sec_fail_counts($pdo, $username, $mins);
        if ($byUser >= $max || $byIp >= $max * 3) {
            sec_event($pdo, 'LOGIN_BLOCKED', 'نام کاربری: ' . $username, $username);
            return $mins;
        }
    } catch (Throwable $e) {}
    return 0;
}
function sec_login_failed($pdo, $username, $where = 'پنل') {
    sec_event($pdo, 'LOGIN_FAIL', $where . ' · نام کاربری: ' . $username, $username);
    $s = sec_settings($pdo);
    $max = max(3, intval($s['sec_max_attempts'] ?? 5)); $mins = max(1, intval($s['sec_lock_minutes'] ?? 15));
    try {
        [$byUser, $byIp] = sec_fail_counts($pdo, $username, $mins);
        if ($byUser === $max) sec_event($pdo, 'LOGIN_LOCK', "{$max} تلاشِ ناموفق برای «{$username}»؛ ورود {$mins} دقیقه قفل شد", $username);
        elseif ($byIp === $max * 3) sec_event($pdo, 'LOGIN_LOCK', ($max * 3) . " تلاشِ ناموفق از این IP (نام‌های کاربریِ مختلف)؛ ورود از این IP {$mins} دقیقه قفل شد", $username);
    } catch (Throwable $e) {}
}

// ---------------- اجرا روی همه‌ی درخواست‌ها ----------------
function sec_boot($pdo) {
    static $ran = false;
    if ($ran || PHP_SAPI === 'cli') return;
    $ran = true;
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    $script = sec_script();
    $isDownloadish = in_array($script, ['_guard.php'], true);
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), camera=(self), microphone=(self)');
        header_remove('X-Powered-By');
    }
    sec_cookie();
    // IPِ مسدود
    try {
        $st = $pdo->prepare("SELECT reason FROM sec_blocked_ips WHERE ip = ? AND (until_at IS NULL OR until_at > NOW())");
        $st->execute([sec_ip()]);
        $r = $st->fetchColumn();
        if ($r !== false) {
            if (mt_rand(1, 10) === 1) sec_event($pdo, 'IP_BLOCKED', (string)$r);
            sec_deny(403, 'دسترسی از این آدرس (IP) مسدود شده است.');
        }
    } catch (Throwable $e) {}
    $s = sec_settings($pdo);
    // وب‌هوکِ ربات‌ها: اگر کلید تعیین شده، فقط درخواستِ دارای همان کلید پذیرفته می‌شود
    $hookKey = ['company_bot_webhook.php' => 'company_bot_hook_key', 'bale_webhook.php' => 'bot_hook_key'][$script] ?? null;
    if ($hookKey) {
        $k = (string)($s[$hookKey] ?? '');
        if ($k !== '' && !hash_equals($k, (string)($_GET['k'] ?? ''))) {
            sec_event($pdo, 'WEBHOOK_FORGED', 'درخواستِ بدونِ کلیدِ درست به ' . $script);
            http_response_code(403);
            exit;
        }
        return;   // بقیه‌ی بررسی‌ها برای وب‌هوک لازم نیست
    }
    // درخواستِ جعلی از سایتِ دیگر: POST با Origin‌ای غیر از همین سایت
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($origin !== '') {
            $oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
            if ($origin === 'null' || ($oh !== '' && $host !== '' && $oh !== $host)) {
                sec_event($pdo, 'CSRF_BLOCK', 'Origin: ' . $origin . ' → ' . ($_SERVER['REQUEST_URI'] ?? ''));
                sec_deny(403, 'درخواست از سایتِ دیگری فرستاده شده بود و رد شد.');
            }
        }
    }
    // آپلودِ فایلِ اجرایی/خطرناک (php، html، svg، ...) - فایلِ پارسرِ پایتون فقط برای مدیرِ کل در تنظیماتِ گزارش
    if (!empty($_FILES)) {
        $names = [];
        array_walk_recursive($_FILES, function ($v, $k) use (&$names) { if ($k === 'name' && is_string($v) && $v !== '') $names[] = $v; });
        $adminPy = $script === 'visit_reports.php' && session_status() === PHP_SESSION_ACTIVE && ($_SESSION['role'] ?? '') === 'ADMIN';
        // لوگوی SVG فقط برای مدیرِ کل در «تنظیمات ← لوگو و فاوآیکن» (محتوایش آنجا بررسی می‌شود که اسکریپت نداشته باشد)
        $adminSvg = $script === 'brand_actions.php' && session_status() === PHP_SESSION_ACTIVE && ($_SESSION['role'] ?? '') === 'ADMIN';
        foreach ($names as $n) {
            $b = basename(str_replace('\\', '/', $n));
            if ($adminPy && preg_match('/^[^.]+\.py$/i', $b)) continue;
            if ($adminSvg && preg_match('/^[^.]+\.svg$/i', $b)) continue;
            if ($b === '' || $b[0] === '.' || preg_match(SEC_BAD_EXT, $b) || preg_match('/[<>"\x00-\x1f]/', $b)) {   // < > " در نامِ فایل در ویندوز هم مجاز نیست
                sec_event($pdo, 'UPLOAD_BLOCK', 'فایل: ' . $b . ' · ' . $script);
                sec_deny(400, 'این نوع فایل برای بارگذاری مجاز نیست (' . $b . ').');
            }
        }
    }
    // ورودیِ مشکوک (فقط ثبت، نه مسدودسازی): تزریقِ SQL، XSS، پیمایشِ مسیر در پارامترهای آدرس و فرم
    if (($s['sec_waf'] ?? '1') === '1' && !$isDownloadish) {
        $vals = [];
        array_walk_recursive($_GET, function ($v) use (&$vals) { if (is_string($v)) $vals[] = $v; });
        if (empty($_SESSION['user_id'])) array_walk_recursive($_POST, function ($v) use (&$vals) { if (is_string($v) && strlen($v) < 2000) $vals[] = $v; });
        foreach ($vals as $v) {
            $d = strtolower(urldecode($v));
            if (preg_match('/union\s+(all\s+)?select|information_schema|sleep\s*\(\s*\d|benchmark\s*\(|<\s*script|javascript:|onerror\s*=|\.\.\/\.\.\/|\/etc\/passwd|load_file\s*\(|into\s+outfile/i', $d)) {
                sec_event($pdo, 'SUSPICIOUS_INPUT', mb_substr($v, 0, 200));
                break;
            }
        }
    }
    sec_session_guard($pdo);
    if (sec_is_api() && !in_array($script, SEC_XSS_SKIP, true)) ob_start('sec_xss_filter', 4194304);   // فایل‌های بزرگ تکه‌تکه و دست‌نخورده رد می‌شوند
}

// ---------------- خنثی‌سازیِ کدِ مخرب (XSS) در خروجیِ APIها ----------------
// متن‌هایی که مشتری‌ها از ربات/مینی‌اپ/پنلِ شرکت‌ها می‌فرستند (نام، نشانی، توضیح، نامِ فایل، ...) در پنل نمایش داده می‌شوند.
// اگر در آن‌ها < > " ' باشد، در خروجیِ JSON با نویسه‌ی هم‌شکلِ بی‌خطر (＜ ＞ ＂ ’) عوض می‌شوند تا مرورگر آن را کد حساب نکند.
// فقط پاسخ‌هایی که واقعاً چنین نویسه‌ای دارند دوباره ساخته می‌شوند؛ بقیه دست‌نخورده می‌روند.
const SEC_XSS_SKIP = ['comfort_actions.php', 'chat_actions.php', 'settings.php', 'visit_report_settings.php', 'visit_reports.php', 'security_actions.php', 'file_manager.php', 'alive.php'];
const SEC_XSS_KEEP_KEY = '/(^|_)(html|svg|json|url|link|href|path|token|regex|pattern|template|markup|code_src|src|data_uri|b64|base64)$/i';

function sec_xss_clean($v, $key = '') {
    if (is_string($v)) {
        if (!preg_match('/[<>"\']/', $v) || ($key !== '' && preg_match(SEC_XSS_KEEP_KEY, $key))) return $v;
        $t = ltrim($v);
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[') && json_decode($v) !== null) return $v;   // متنِ JSON (مثلاً تنظیماتِ ذخیره‌شده)
        return strtr($v, ['<' => '＜', '>' => '＞', '"' => '＂', "'" => '’']);
    }
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = sec_xss_clean($x, is_string($k) ? $k : $key); return $v; }
    if (is_object($v)) { foreach (get_object_vars($v) as $k => $x) $v->$k = sec_xss_clean($x, (string)$k); return $v; }
    return $v;
}

function sec_xss_filter($buf, $phase = 0) {
    // فقط پاسخِ کامل و JSON (فایل، PDF، اکسل و خروجیِ تکه‌تکه دست نمی‌خورد)
    if (!($phase & PHP_OUTPUT_HANDLER_FINAL) && !($phase & PHP_OUTPUT_HANDLER_END)) return false;
    if ($buf === '') return $buf;
    $c = $buf[0];
    if ($c !== '{' && $c !== '[') return $buf;
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'json') === false && stripos($h, 'text/plain') === false && stripos($h, 'text/html') === false) return $buf;
        if (stripos($h, 'content-disposition:') === 0) return $buf;
    }
    // فقط وقتی یکی از این نویسه‌ها داخلِ یک مقدارِ متنی هست
    if (!preg_match('/[<>\']|\\\\"|\\\\u003[ce]|\\\\u0027|\\\\u0022/i', $buf)) return $buf;
    $d = json_decode($buf);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) return $buf;
    $enc = json_encode(sec_xss_clean($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    return $enc === false ? $buf : $enc;
}

// ---------------- وب‌هوکِ امن برای ربات‌ها ----------------
// آدرسِ وب‌هوک یک کلیدِ تصادفی می‌گیرد (?k=...) و از آن به بعد فقط درخواستِ دارای همان کلید پذیرفته می‌شود.
// کلید فقط وقتی ذخیره می‌شود که بله وب‌هوکِ تازه را پذیرفته باشد (تا ربات قطع نشود).
// $which: company (ربات شرکت‌ها، company_bot_webhook.php) | staff (ربات کارکنان، bale_webhook.php)
function sec_set_webhook($pdo, $which, $token = null) {
    $tokKey = $which === 'company' ? 'company_bot_token' : 'bot_token';
    $hookKey = $which === 'company' ? 'company_bot_hook_key' : 'bot_hook_key';
    $file = $which === 'company' ? 'company_bot_webhook.php' : 'bale_webhook.php';
    if ($token === null) {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$tokKey]);
        $token = (string)$st->fetchColumn();
    }
    if ($token === '') return ['ok' => false, 'error' => 'توکنِ ربات تنظیم نشده است.'];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $key = bin2hex(random_bytes(20));
    $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/') . '/' . $file . '?k=' . $key;
    $base = rtrim(getenv('CBOT_API_BASE') ?: 'https://tapi.bale.ai', '/');
    $ch = curl_init($base . "/bot{$token}/setWebhook");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
                            CURLOPT_POSTFIELDS => json_encode(['url' => $url]), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($r['ok'])) return ['ok' => false, 'error' => 'بله وب‌هوک را نپذیرفت' . (!empty($r['description']) ? ': ' . $r['description'] : '') . '.', 'url' => preg_replace('/\?k=.*/', '', $url)];
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$hookKey, $key]);
    return ['ok' => true, 'url' => preg_replace('/\?k=.*/', '', $url)];
}
