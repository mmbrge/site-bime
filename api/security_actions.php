<?php
// فایل: api/security_actions.php
// «تنظیمات ← سپر امنیتی» (فقط مدیر کل): امتیاز و بررسی‌های امنیتی، رخدادها با فیلتر و خروجیِ اکسل، IPهای مسدود،
// نشست‌های فعال با خروجِ اجباری، تنظیماتِ خروجِ خودکار/قفلِ ورود و اتصالِ امنِ وب‌هوکِ ربات‌ها.
session_start();
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';

function sout($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) sout(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
if (($_SESSION['role'] ?? '') !== 'ADMIN' || (function_exists('perm_is_elevated') && perm_is_elevated())) {
    sec_event($pdo, 'PERM_DENIED', 'سپر امنیتی');
    sout(['ok' => false, 'error' => 'سپر امنیتی فقط برای مدیر کل است.']);
}
sec_ensure($pdo);
$me = intval($_SESSION['user_id']);
session_write_close();   // بررسی‌های طولانی (آزمایشِ زنده، رمزهای ضعیف) بقیه‌ی درخواست‌های همین کاربر را معطل نکنند
$raw = json_decode(file_get_contents('php://input'), true) ?: [];
$data = $raw + $_POST + $_GET;
$action = (string)($data['action'] ?? '');

function sec_jdt($ts) {
    if (!$ts) return '';
    $t = is_numeric($ts) ? intval($ts) : strtotime((string)$ts);
    [$y, $m, $d] = jalali_from_gregorian_ts($t);
    return fa_digits(sprintf('%04d/%02d/%02d', $y, $m, $d) . ' ' . date('H:i', $t));
}
function sec_root() { return dirname(__DIR__); }
function sec_save_setting($pdo, $k, $v) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, (string)$v]);
}
function sec_setting_raw($pdo, $k) {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? '' : (string)$v;
}
function sec_https() { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); }
function sec_base_url() {
    return (sec_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname(dirname((string)$_SERVER['SCRIPT_NAME']))), '/');
}
// IPهای خودِ سرور (آزمایشِ زنده از همین‌ها می‌آید؛ مسدودکردنشان آزمایش را خراب می‌کند)
function sec_server_ips() {
    $ips = [(string)($_SERVER['SERVER_ADDR'] ?? '')];
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP)) $ips[] = (string)@gethostbyname($host); else $ips[] = $host;
    return array_values(array_unique(array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, ['127.0.0.1', '::1'], true))));
}
// درخواست به خودِ سایت، بدونِ کوکی (مثلِ یک غریبه)
function sec_probe($url) {
    if (!function_exists('curl_init')) return ['code' => 0, 'body' => ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
                            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'BimeSecurityShield/1.0']);
    $body = (string)curl_exec($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    return ['code' => $code, 'body' => substr($body, 0, 2000)];
}
function sec_path_url($rel) { return sec_base_url() . '/' . implode('/', array_map('rawurlencode', explode('/', $rel))); }

// ---------------- بررسی‌های امنیتی ----------------
function sec_checks($pdo) {
    $s = sec_settings($pdo);
    $root = sec_root();
    $c = [];
    $add = function ($key, $group, $title, $status, $detail, $fix = '', $weight = 2) use (&$c) {
        $c[] = compact('key', 'group', 'title', 'status', 'detail', 'fix', 'weight');
    };
    // اتصال و سرور
    $add('https', 'سرور', 'اتصالِ امن (HTTPS)', sec_https() ? 'ok' : 'bad',
         sec_https() ? 'پنل با HTTPS باز شده و اطلاعات رمزنگاری‌شده جابه‌جا می‌شود.' : 'پنل با http باز شده؛ رمز و اطلاعات در مسیر قابلِ شنود است.',
         'در cPanel بخشِ SSL/TLS Status گواهیِ رایگان (AutoSSL) را فعال کنید و سایت را همیشه با https باز کنید.', 4);
    $pv = PHP_VERSION;
    $add('php', 'سرور', 'نسخه‌ی PHP', version_compare($pv, '8.1', '>=') ? 'ok' : 'warn',
         'نسخه‌ی فعلی: ' . $pv . (version_compare($pv, '8.1', '<') ? ' (پشتیبانیِ امنیتیِ این نسخه تمام شده)' : ''),
         'وقتی فرصت شد از cPanel بخشِ MultiPHP Manager نسخه را بالاتر ببرید (قبلش با پشتیبانِ سایت هماهنگ کنید).', 1);
    $de = strtolower((string)get_cfg_var('display_errors'));
    $add('display_errors', 'سرور', 'نمایشِ خطاهای PHP به کاربر', 'ok',
         in_array($de, ['1', 'on', 'stdout'], true) ? 'در php.ini روشن است ولی سپرِ امنیتی روی همه‌ی صفحه‌ها خاموشش می‌کند.' : 'خاموش است؛ جزئیاتِ فنی به بیرون درز نمی‌کند.', '', 1);
    $idle = max(0.25, floatval($s['sec_idle_hours'] ?? 3)) * 3600;
    $gc = intval(ini_get('session.gc_maxlifetime'));
    $add('gc', 'نشست', 'عمرِ نشست در سرور در برابرِ زمانِ خروجِ خودکار', $gc >= $idle ? 'ok' : 'warn',
         'session.gc_maxlifetime = ' . fa_digits($gc) . ' ثانیه، خروجِ خودکار بعد از ' . fa_digits($idle) . ' ثانیه.' . ($gc < $idle ? ' ممکن است سرور زودتر از موعد نشست را پاک کند و کاربر وسطِ کار خارج شود.' : ''),
         'در cPanel بخشِ MultiPHP INI Editor مقدارِ session.gc_maxlifetime را حداقل ' . fa_digits(intval($idle) + 600) . ' بگذارید.', 1);
    $add('cookie', 'نشست', 'کوکیِ نشستِ امن (HttpOnly / SameSite)', 'ok', 'کوکی از دسترسِ جاوااسکریپت خارج است و از سایتِ دیگر فرستاده نمی‌شود' . (sec_https() ? ' و فقط روی HTTPS می‌رود.' : '.'), '', 2);
    $add('idle', 'نشست', 'خروجِ خودکار بعد از عدمِ فعالیت', 'ok', 'کاربری که ' . fa_digits(rtrim(rtrim(number_format($idle / 3600, 2), '0'), '.')) . ' ساعت با پنل کار نکند خودکار خارج می‌شود؛ کسی که کار می‌کند داخل می‌ماند.', '', 2);
    $add('lock', 'ورود', 'قفلِ ورود بعد از تلاش‌های ناموفق', 'ok', 'بعد از ' . fa_digits(max(3, intval($s['sec_max_attempts'] ?? 5))) . ' تلاشِ ناموفق، ورود ' . fa_digits(max(1, intval($s['sec_lock_minutes'] ?? 15))) . ' دقیقه قفل می‌شود.', '', 3);
    // ربات‌ها
    foreach (['company' => ['company_bot_token', 'company_bot_hook_key', 'ربات بله‌ی شرکت‌ها'], 'staff' => ['bot_token', 'bot_hook_key', 'ربات بله‌ی پرسنل/مشتریان'], 'life' => ['life_bot_token', 'life_bot_hook_key', 'ربات بله‌ی بیمه عمر']] as $w => [$tk, $hk, $label]) {
        $hasTok = sec_setting_raw($pdo, $tk) !== '';
        if (!$hasTok) { $add('hook_' . $w, 'ربات‌ها', 'وب‌هوکِ امنِ ' . $label, 'ok', 'توکنِ این ربات تنظیم نشده است.', '', 0); continue; }
        $hasKey = ($s[$hk] ?? '') !== '';
        $add('hook_' . $w, 'ربات‌ها', 'وب‌هوکِ امنِ ' . $label, $hasKey ? 'ok' : 'bad',
             $hasKey ? 'ربات فقط پیام‌هایی را می‌پذیرد که کلیدِ مخفی دارند.' : 'هر کسی آدرسِ وب‌هوک را بداند می‌تواند پیامِ جعلی بفرستد (مثلاً حسابِ بله‌ی خودش را به جای کاربرِ دیگری وصل کند و کدِ ورود بگیرد).',
             'دکمه‌ی «اتصالِ امنِ وب‌هوکِ ربات‌ها» را در همین صفحه بزنید.', 4);
    }
    // فایل‌ها و پوشه‌ها
    $guards = ['Archive/.htaccess' => 'بایگانی', 'Archive/_guard.php' => 'نگهبانِ بایگانی', 'config/.htaccess' => 'تنظیمات', 'uploads/.htaccess' => 'آپلودها',
               'queue/.htaccess' => 'صفِ پردازش', 'tmp_ocr/.htaccess' => 'موقتِ OCR', 'migrations/.htaccess' => 'مایگریشن‌ها', 'report_assets/.htaccess' => 'فایل‌های گزارش', 'موقت/.htaccess' => 'موقت', 'api/.htaccess' => 'کتابخانه‌های API', 'api/py/.htaccess' => 'اسکریپت‌های پایتون'];
    $miss = [];
    foreach ($guards as $f => $l) if (!is_file($root . '/' . $f)) $miss[] = $l;
    $add('htaccess', 'فایل‌ها', 'محافظِ پوشه‌ها (اجرانشدنِ فایلِ آپلودی، بسته‌بودنِ پوشه‌های داخلی)', $miss ? 'bad' : 'ok',
         $miss ? 'محافظ در این پوشه‌ها نیست: ' . implode('، ', $miss) : 'همه‌ی پوشه‌های حساس محافظ دارند. برای اطمینانِ کامل «آزمایشِ زنده» را بزنید.',
         'فایل‌های .htaccess همین بسته را در همان پوشه‌ها روی هاست بگذارید.', 4);
    $left = [];
    foreach (['phpinfo.php', 'info.php', 'test.php', 'i.php', '_login_test.php', 'adminer.php', 'shell.php', 'dump.sql', 'backup.sql', 'db.sql', 'site.zip', 'backup.zip', 'public_html.zip'] as $f) {
        if (is_file($root . '/' . $f) || is_file($root . '/api/' . $f)) $left[] = $f;
    }
    foreach (glob($root . '/*.{sql,zip,tar,gz,bak}', GLOB_BRACE) ?: [] as $f) { $b = basename($f); if (!in_array($b, $left, true)) $left[] = $b; }
    $add('leftovers', 'فایل‌ها', 'فایل‌های اضافه/آزمایشی در ریشه‌ی سایت', $left ? 'bad' : 'ok',
         $left ? 'این فایل‌ها از بیرون قابلِ دسترسی‌اند: ' . implode('، ', $left) : 'فایلِ آزمایشی یا پشتیبانِ بی‌محافظ در ریشه‌ی سایت نیست.',
         'این فایل‌ها را از File Manager هاست پاک کنید (یا به پوشه‌ی backup ببرید).', 3);
    $add('git', 'فایل‌ها', 'پوشه‌ی .git روی هاست', is_dir($root . '/.git') ? 'warn' : 'ok',
         is_dir($root . '/.git') ? 'پوشه‌ی .git روی هاست است؛ اگر از بیرون باز شود کلِ کدِ سایت قابلِ دانلود است.' : 'پوشه‌ی .git روی هاست نیست.',
         'پوشه‌ی .git را از هاست پاک کنید (برای کارکردِ سایت لازم نیست).', 2);
    $add('backup', 'فایل‌ها', 'پوشه‌ی پشتیبان‌ها', !is_dir($root . '/backup') || is_file($root . '/backup/.htaccess') ? 'ok' : 'bad',
         !is_dir($root . '/backup') ? 'هنوز پشتیبانی ساخته نشده.' : (is_file($root . '/backup/.htaccess') ? 'پوشه‌ی پشتیبان‌ها از بیرون بسته است.' : 'پوشه‌ی پشتیبان‌ها محافظ ندارد!'),
         'یک پشتیبانِ تازه از «تنظیمات» بسازید تا محافظِ پوشه خودکار ساخته شود.', 3);
    // کاربران
    $admins = $pdo->query("SELECT username FROM users WHERE role = 'ADMIN' AND COALESCE(is_deleted, 0) = 0")->fetchAll(PDO::FETCH_COLUMN);
    $weakName = array_values(array_filter($admins, fn($u) => in_array(strtolower($u), ['admin', 'administrator', 'root', 'test', 'user', 'manager'], true)));
    $add('admin_name', 'کاربران', 'نام کاربریِ قابلِ حدس برای مدیر کل', $weakName ? 'warn' : 'ok',
         $weakName ? 'نام کاربریِ مدیر: ' . implode('، ', $weakName) . ' (اولین چیزی که مهاجم امتحان می‌کند)' : 'نام کاربریِ مدیرها قابلِ حدس نیست.',
         'یک مدیرِ کلِ تازه با نام کاربریِ غیرمعمول بسازید و حسابِ قبلی را حذف کنید.', 1);
    $weak = json_decode(sec_setting_raw($pdo, 'sec_weak_scan'), true);
    if (is_array($weak)) {
        $add('weak', 'کاربران', 'رمزهای ضعیف', $weak['list'] ? 'bad' : 'ok',
             ($weak['list'] ? 'این حساب‌ها رمزِ ساده/قابلِ حدس دارند: ' . implode('، ', array_map(fn($x) => $x['name'] . ' (' . $x['why'] . ')', $weak['list'])) : 'هیچ رمزِ ضعیفی پیدا نشد.') . ' · آخرین بررسی: ' . sec_jdt($weak['at']),
             'به این کاربران بگویید رمزشان را عوض کنند (یا از «کاربران» رمزِ تازه بگذارید).', 3);
    } else {
        $add('weak', 'کاربران', 'رمزهای ضعیف', 'warn', 'هنوز بررسی نشده است.', 'دکمه‌ی «بررسیِ رمزهای ضعیف» را بزنید.', 3);
    }
    try {
        $noMob = intval($pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(is_deleted, 0) = 0 AND (mobile_number IS NULL OR mobile_number = '')")->fetchColumn());
        $add('mobile', 'کاربران', 'کاربرانِ بدونِ شماره موبایل', $noMob ? 'warn' : 'ok',
             $noMob ? fa_digits($noMob) . ' کاربرِ پنل شماره موبایل ندارد (ورود با کدِ یک‌بارمصرف و بازیابیِ رمز برایش کار نمی‌کند).' : 'همه‌ی کاربران شماره موبایل دارند.',
             'از «کاربران» برای این افراد شماره موبایل ثبت کنید.', 1);
    } catch (Throwable $e) {}
    $crit = intval($pdo->query("SELECT COUNT(*) FROM security_events WHERE severity = 'critical' AND at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn());
    $add('recent', 'رخدادها', 'رخدادهای بحرانیِ ۲۴ ساعتِ گذشته', $crit ? 'bad' : 'ok',
         $crit ? fa_digits($crit) . ' رخدادِ بحرانی (آپلودِ فایلِ خطرناک یا پیامِ جعلی به ربات) ثبت شده؛ جزئیات در جدولِ رخدادها.' : 'رخدادِ بحرانی‌ای ثبت نشده.',
         'رخدادها را ببینید و اگر از یک IP تکرار شده، آن IP را مسدود کنید.', 2);
    $live = json_decode(sec_setting_raw($pdo, 'sec_live_test'), true);
    if (is_array($live)) {
        $add('live', 'فایل‌ها', 'آزمایشِ زنده از بیرون', $live['bad'] ? 'bad' : ($live['unknown'] ? 'warn' : 'ok'),
             implode(' · ', array_map(fn($x) => ($x['ok'] === true ? '✓ ' : ($x['ok'] === false ? '✗ ' : '؟ ')) . $x['title'], $live['items'])) . ' · ' . sec_jdt($live['at']),
             $live['bad'] ? 'مواردِ ✗ را ببینید؛ معمولاً یعنی فایلِ .htaccess آن پوشه روی هاست نیست یا هاست آن را نمی‌خواند.' : '', 3);
    } else {
        $add('live', 'فایل‌ها', 'آزمایشِ زنده از بیرون', 'warn', 'هنوز انجام نشده است.', 'دکمه‌ی «آزمایشِ زنده» را بزنید.', 3);
    }
    $tot = 0; $got = 0;
    foreach ($c as $x) { $tot += $x['weight']; $got += $x['status'] === 'ok' ? $x['weight'] : ($x['status'] === 'warn' ? $x['weight'] / 2 : 0); }
    return ['checks' => $c, 'score' => $tot ? intval(round($got * 100 / $tot)) : 100];
}

function sec_stats($pdo) {
    $by = function ($interval) use ($pdo) {
        $o = [];
        foreach ($pdo->query("SELECT type, COUNT(*) n FROM security_events WHERE at > DATE_SUB(NOW(), INTERVAL $interval) GROUP BY type")->fetchAll() as $r) $o[$r['type']] = intval($r['n']);
        return $o;
    };
    $days = [];
    $rows = $pdo->query("SELECT DATE(at) d, severity, COUNT(*) n FROM security_events WHERE at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(at), severity")->fetchAll();
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        [$jy, $jm, $jd] = jalali_from_gregorian_ts(strtotime($d));
        $days[$d] = ['label' => fa_digits($jm . '/' . $jd), 'info' => 0, 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
    }
    foreach ($rows as $r) if (isset($days[$r['d']][$r['severity']])) $days[$r['d']][$r['severity']] = intval($r['n']);
    $ips = $pdo->query("SELECT e.ip, COUNT(*) n, SUM(e.severity IN ('high','critical')) hi, MAX(e.at) last, (SELECT COUNT(*) FROM sec_blocked_ips b WHERE b.ip = e.ip AND (b.until_at IS NULL OR b.until_at > NOW())) blocked
                        FROM security_events e WHERE e.at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND e.type NOT IN ('IDLE_LOGOUT', 'ADMIN_ACTION', 'FORCED_LOGOUT') AND e.ip <> ''
                        GROUP BY e.ip ORDER BY hi DESC, n DESC LIMIT 8")->fetchAll();
    foreach ($ips as &$r) { $r['last_fa'] = sec_jdt($r['last']); $r['blocked'] = intval($r['blocked']) > 0; $r['n'] = intval($r['n']); $r['hi'] = intval($r['hi']); }
    return ['h24' => $by('1 DAY'), 'd7' => $by('7 DAY'), 'days' => array_values($days), 'top_ips' => $ips];
}

function sec_events_query($pdo, $data, $limit, $offset) {
    $w = ['1=1']; $a = [];
    if (!empty($data['type'])) { $w[] = 'type = ?'; $a[] = (string)$data['type']; }
    if (!empty($data['severity'])) { $w[] = 'severity = ?'; $a[] = (string)$data['severity']; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (empty($data[$k])) continue;
        $ts = parse_jalali_date_string(p2e_digits((string)$data[$k]));
        if ($ts) { $w[] = "at $op ?"; $a[] = date('Y-m-d', $ts) . ($k === 'from' ? ' 00:00:00' : ' 23:59:59'); }
    }
    if (trim((string)($data['q'] ?? '')) !== '') {
        $q = '%' . trim(p2e_digits((string)$data['q'])) . '%';
        $w[] = '(user_name LIKE ? OR ip LIKE ? OR detail LIKE ? OR path LIKE ?)';
        array_push($a, $q, $q, $q, $q);
    }
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) FROM security_events WHERE $where");
    $st->execute($a);
    $total = intval($st->fetchColumn());
    $st = $pdo->prepare("SELECT * FROM security_events WHERE $where ORDER BY id DESC LIMIT " . intval($limit) . " OFFSET " . intval($offset));
    $st->execute($a);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['type_fa'] = SEC_EVENT_FA[$r['type']] ?? $r['type'];
        $r['at_fa'] = sec_jdt($r['at']);
        $r['device'] = sec_ua_short((string)$r['user_agent']);
    }
    return [$total, $rows];
}
function sec_ua_short($ua) {
    if ($ua === '') return '';
    $os = preg_match('/Android/i', $ua) ? 'اندروید' : (preg_match('/iPhone|iPad/i', $ua) ? 'آیفون' : (preg_match('/Windows/i', $ua) ? 'ویندوز' : (preg_match('/Mac OS/i', $ua) ? 'مک' : (preg_match('/Linux/i', $ua) ? 'لینوکس' : ''))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : (preg_match('/curl|python|wget|Go-http|bot|spider/i', $ua) ? 'ربات/اسکریپت' : '')))));
    return trim($br . ($os ? ' · ' . $os : '')) ?: mb_substr($ua, 0, 40);
}

function sec_sessions($pdo) {
    $online = 150;
    $out = [];
    $hasPres = true;
    try { $pdo->query("SELECT last_seen_at FROM users LIMIT 0"); } catch (Throwable $e) { $hasPres = false; }
    $pres = $hasPres ? ", last_seen_at, TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS ago, (last_seen_at >= NOW() - INTERVAL $online SECOND AND (last_offline_at IS NULL OR last_offline_at < last_seen_at)) AS online" : ", NULL last_seen_at, NULL ago, 0 online";
    $lists = [
        'STAFF' => "SELECT id, full_name, username, role $pres FROM users WHERE COALESCE(is_deleted, 0) = 0",
        'COMPANY' => "SELECT id, full_name, username, 'COMPANY' role $pres FROM company_portal_users WHERE is_active = 1 AND COALESCE(is_deleted, 0) = 0",
    ];
    $roleFa = ['ADMIN' => 'مدیر کل', 'OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی', 'COMPANY_LIAISON' => 'کارمند بیمه با ما', 'PARSIAN' => 'کارمند بیمه با ما (پارسیان)', 'LIFE' => 'کاربر بیمه عمر', 'COMPANY' => 'کاربرِ شرکت'];
    foreach ($lists as $type => $q) {
        try { $rows = $pdo->query($q)->fetchAll(); } catch (Throwable $e) { continue; }
        foreach ($rows as $r) {
            $ll = null;
            try {
                $st = $pdo->prepare("SELECT created_at, ip, browser, os, device, method FROM login_logs WHERE user_type = ? AND user_id = ? AND success = 1 AND method <> 'LOGOUT' ORDER BY id DESC LIMIT 1");
                $st->execute([$type, $r['id']]);
                $ll = $st->fetch() ?: null;
            } catch (Throwable $e) {}
            $out[] = ['type' => $type, 'id' => intval($r['id']), 'name' => $r['full_name'] ?: $r['username'], 'username' => $r['username'], 'role_fa' => $roleFa[$r['role']] ?? $r['role'],
                      'online' => !empty($r['online']), 'ago' => $r['ago'] === null ? null : intval($r['ago']), 'last_seen_fa' => $r['last_seen_at'] ? sec_jdt($r['last_seen_at']) : '',
                      'last_login_fa' => $ll ? sec_jdt($ll['created_at']) : '', 'last_ip' => $ll['ip'] ?? '', 'device' => $ll ? trim(implode(' · ', array_filter([$ll['browser'], $ll['os'], $ll['device']]))) : '',
                      'method' => $ll['method'] ?? '', 'me' => $type === 'STAFF' && intval($r['id']) === intval($_SESSION['user_id'])];
        }
    }
    usort($out, fn($a, $b) => [$b['online'], -($a['ago'] ?? PHP_INT_MAX)] <=> [$a['online'], -($b['ago'] ?? PHP_INT_MAX)]);
    return $out;
}

switch ($action) {
    case 'overview':
        $s = sec_settings($pdo);
        $chk = sec_checks($pdo);
        $settings = [];
        foreach (SEC_DEFAULTS as $k => $v) $settings[$k] = $s[$k] ?? $v;
        sout(['ok' => true, 'score' => $chk['score'], 'checks' => $chk['checks'], 'stats' => sec_stats($pdo), 'settings' => $settings, 'types' => SEC_EVENT_FA,
              'my_ip' => sec_ip(), 'server_ips' => sec_server_ips(), 'blocked_count' => intval($pdo->query("SELECT COUNT(*) FROM sec_blocked_ips WHERE until_at IS NULL OR until_at > NOW()")->fetchColumn()),
              'total_events' => intval($pdo->query("SELECT COUNT(*) FROM security_events")->fetchColumn()), 'now_fa' => sec_jdt(time())]);

    case 'events':
        $page = max(1, intval($data['page'] ?? 1));
        [$total, $rows] = sec_events_query($pdo, $data, 50, ($page - 1) * 50);
        sout(['ok' => true, 'total' => $total, 'page' => $page, 'pages' => max(1, intval(ceil($total / 50))), 'rows' => $rows]);

    case 'events_export':
        [$total, $rows] = sec_events_query($pdo, $data, 20000, 0);
        require_once __DIR__ . '/_xlsx_writer.php';
        $sev = ['info' => 'اطلاع', 'low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد', 'critical' => 'بحرانی'];
        $xr = [];
        foreach ($rows as $i => $r) {
            $xr[] = [$i + 1, $r['at_fa'], $r['type_fa'], $sev[$r['severity']] ?? $r['severity'], $r['user_name'] ?: '—', $r['user_type'] === 'COMPANY' ? 'کاربرِ شرکت' : ($r['user_type'] === 'STAFF' ? 'پنل' : '—'),
                     $r['ip'], $r['device'], $r['path'], $r['detail']];
        }
        sec_event($pdo, 'ADMIN_ACTION', 'خروجیِ اکسلِ رخدادهای امنیتی (' . count($rows) . ' ردیف)');
        while (ob_get_level()) ob_end_clean();
        xlsx_send(xlsx_build(['ردیف', 'زمان', 'رخداد', 'اهمیت', 'کاربر', 'نوعِ کاربر', 'IP', 'مرورگر/دستگاه', 'آدرس', 'جزئیات'], $xr, 'رخدادهای امنیتی'),
                  'رخدادهای امنیتی ' . jalali_from_gregorian_ts_dotted(time()) . '.xlsx');
        exit;

    case 'sessions':
        sout(['ok' => true, 'rows' => sec_sessions($pdo)]);

    case 'force_logout':
        $type = ($data['type'] ?? '') === 'COMPANY' ? 'COMPANY' : 'STAFF';
        $t = $type === 'COMPANY' ? 'company_portal_users' : 'users';
        if (!empty($data['all'])) {
            $pdo->exec("UPDATE users SET sess_epoch = sess_epoch + 1 WHERE id <> " . $me);
            $pdo->exec("UPDATE company_portal_users SET sess_epoch = sess_epoch + 1");
            try { $pdo->exec("UPDATE users SET last_offline_at = NOW() WHERE id <> $me"); $pdo->exec("UPDATE company_portal_users SET last_offline_at = NOW()"); } catch (Throwable $e) {}
            sec_event($pdo, 'ADMIN_ACTION', 'خروجِ اجباریِ همه‌ی کاربران (جز خودِ مدیر)');
            sout(['ok' => true, 'msg' => 'همه‌ی کاربران (جز شما) حداکثر تا ۱۰ ثانیه‌ی دیگر از پنل خارج می‌شوند.']);
        }
        $id = intval($data['id'] ?? 0);
        if (!$id) sout(['ok' => false, 'error' => 'کاربر مشخص نیست.']);
        if ($type === 'STAFF' && $id === $me) sout(['ok' => false, 'error' => 'برای خروجِ خودتان از دکمه‌ی خروج استفاده کنید.']);
        $st = $pdo->prepare("SELECT COALESCE(full_name, username) FROM $t WHERE id = ?");
        $st->execute([$id]);
        $name = (string)$st->fetchColumn();
        $pdo->prepare("UPDATE $t SET sess_epoch = sess_epoch + 1 WHERE id = ?")->execute([$id]);
        try { $pdo->prepare("UPDATE $t SET last_offline_at = NOW() WHERE id = ?")->execute([$id]); } catch (Throwable $e) {}
        sec_event($pdo, 'ADMIN_ACTION', 'خروجِ اجباریِ «' . $name . '»');
        sout(['ok' => true, 'msg' => '«' . $name . '» حداکثر تا ۱۰ ثانیه‌ی دیگر از پنل خارج می‌شود.']);

    case 'blocked':
        $rows = $pdo->query("SELECT b.*, u.full_name by_name FROM sec_blocked_ips b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.created_at DESC")->fetchAll();
        foreach ($rows as &$r) {
            $r['active'] = $r['until_at'] === null || strtotime($r['until_at']) > time();
            $r['until_fa'] = $r['until_at'] ? sec_jdt($r['until_at']) : 'همیشه';
            $r['created_fa'] = sec_jdt($r['created_at']);
        }
        sout(['ok' => true, 'rows' => $rows, 'my_ip' => sec_ip()]);

    case 'block_ip':
        $ip = trim((string)($data['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) sout(['ok' => false, 'error' => 'IP معتبر نیست.']);
        if ($ip === sec_ip()) sout(['ok' => false, 'error' => 'این IPِ خودِ شماست؛ مسدودش نمی‌کنیم تا خودتان قفل نشوید.']);
        if (in_array($ip, sec_server_ips(), true)) sout(['ok' => false, 'error' => 'این IPِ خودِ سرورِ سایت است (آزمایشِ زنده از همین‌جا انجام می‌شود)؛ مسدود نمی‌شود.']);
        $hours = max(0, intval($data['hours'] ?? 0));
        $pdo->prepare("REPLACE INTO sec_blocked_ips (ip, reason, until_at, created_at, created_by) VALUES (?, ?, ?, NOW(), ?)")
            ->execute([$ip, mb_substr(trim((string)($data['reason'] ?? '')), 0, 300), $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : null, $me]);
        sec_event($pdo, 'ADMIN_ACTION', 'مسدودکردنِ IP ' . $ip . ($hours ? ' برای ' . $hours . ' ساعت' : ' (همیشه)'));
        sout(['ok' => true]);

    case 'unblock_ip':
        $ip = trim((string)($data['ip'] ?? ''));
        $pdo->prepare("DELETE FROM sec_blocked_ips WHERE ip = ?")->execute([$ip]);
        sec_event($pdo, 'ADMIN_ACTION', 'برداشتنِ مسدودیِ IP ' . $ip);
        sout(['ok' => true]);

    case 'save_settings':
        $in = (array)($data['settings'] ?? []);
        $clean = [
            'sec_idle_hours' => max(0.25, min(24, floatval(p2e_digits((string)($in['sec_idle_hours'] ?? 3))))),
            'sec_max_attempts' => max(3, min(20, intval(p2e_digits((string)($in['sec_max_attempts'] ?? 5))))),
            'sec_lock_minutes' => max(1, min(1440, intval(p2e_digits((string)($in['sec_lock_minutes'] ?? 15))))),
            'sec_notify' => !empty($in['sec_notify']) ? 1 : 0,
            'sec_waf' => !empty($in['sec_waf']) ? 1 : 0,
        ];
        foreach ($clean as $k => $v) sec_save_setting($pdo, $k, $v);
        sec_event($pdo, 'ADMIN_ACTION', 'تنظیماتِ سپر: خروجِ خودکار ' . $clean['sec_idle_hours'] . ' ساعت، قفل بعد از ' . $clean['sec_max_attempts'] . ' تلاش برای ' . $clean['sec_lock_minutes'] . ' دقیقه، اعلان ' . ($clean['sec_notify'] ? 'روشن' : 'خاموش') . '، پایشِ ورودی ' . ($clean['sec_waf'] ? 'روشن' : 'خاموش'));
        sout(['ok' => true]);

    case 'fix_webhooks':
        $res = [];
        foreach (['company' => ['company_bot_token', 'ربات شرکت‌ها'], 'staff' => ['bot_token', 'ربات پرسنل/مشتریان'], 'life' => ['life_bot_token', 'ربات بیمه عمر']] as $w => [$tk, $label]) {
            if (sec_setting_raw($pdo, $tk) === '') { $res[] = ['label' => $label, 'ok' => null, 'msg' => 'توکن تنظیم نشده']; continue; }
            $r = sec_set_webhook($pdo, $w);
            $res[] = ['label' => $label, 'ok' => !empty($r['ok']), 'msg' => !empty($r['ok']) ? 'وصل شد: ' . $r['url'] : ($r['error'] ?? 'خطا')];
        }
        sec_event($pdo, 'ADMIN_ACTION', 'اتصالِ امنِ وب‌هوکِ ربات‌ها: ' . implode(' · ', array_map(fn($x) => $x['label'] . ' ' . ($x['ok'] ? '✓' : ($x['ok'] === null ? '—' : '✗')), $res)));
        sout(['ok' => true, 'results' => $res]);

    case 'weak_scan':
        @set_time_limit(120);
        $common = ['123456', '12345678', '123456789', '1234', '12345', '111111', '123123', 'password', 'admin123', 'qwerty', '1qaz2wsx', 'bime123'];
        $list = [];
        $sources = ['STAFF' => "SELECT id, username, full_name, mobile_number AS mobile, password_hash FROM users WHERE COALESCE(is_deleted, 0) = 0",
                    'COMPANY' => "SELECT id, username, full_name, mobile_number AS mobile, password_hash FROM company_portal_users WHERE is_active = 1 AND COALESCE(is_deleted, 0) = 0"];
        foreach ($sources as $type => $q) {
            try { $rows = $pdo->query($q)->fetchAll(); } catch (Throwable $e) { continue; }
            foreach ($rows as $u) {
                if (empty($u['password_hash'])) continue;
                $tries = [(string)$u['username'] => 'همان نام کاربری'];
                $mob = preg_replace('/\D/', '', p2e_digits((string)($u['mobile'] ?? '')));
                if ($mob !== '') { $tries[$mob] = 'همان شماره موبایل'; $tries[ltrim($mob, '0')] = 'همان شماره موبایل'; $tries[substr($mob, -6)] = 'شش رقمِ آخرِ موبایل'; }
                foreach ($common as $p) $tries[$p] = 'رمزِ رایج';
                foreach ($tries as $p => $why) {
                    if ($p === '') continue;
                    if (password_verify((string)$p, $u['password_hash'])) { $list[] = ['name' => ($u['full_name'] ?: $u['username']) . ($type === 'COMPANY' ? ' (شرکت)' : ''), 'why' => $why]; break; }
                }
            }
        }
        sec_save_setting($pdo, 'sec_weak_scan', json_encode(['at' => time(), 'list' => $list], JSON_UNESCAPED_UNICODE));
        sec_event($pdo, 'ADMIN_ACTION', 'بررسیِ رمزهای ضعیف: ' . count($list) . ' مورد');
        sout(['ok' => true, 'count' => count($list), 'list' => $list]);

    case 'live_test':
        // از بیرون (بدونِ ورود) چند آدرس را امتحان می‌کند: اجرانشدنِ فایلِ php در پوشه‌های آپلود، بسته‌بودنِ بایگانی و پوشه‌های داخلی
        $root = sec_root();
        $tag = bin2hex(random_bytes(4));
        $mk = [];
        $items = [];
        $probeExec = function ($dir, $title) use ($root, $tag, &$mk, &$items) {
            if (!is_dir($root . '/' . $dir)) return;
            $f = $dir . '/_sectest_' . $tag . '.php';
            if (@file_put_contents($root . '/' . $f, '<?php echo "SEC" . "EXEC";') === false) { $items[] = ['title' => $title, 'ok' => null, 'detail' => 'نوشتنِ فایلِ آزمایشی ممکن نشد']; return; }
            $mk[] = $root . '/' . $f;
            $r = sec_probe(sec_path_url($f));
            $exec = strpos($r['body'], 'SECEXEC') !== false;
            $items[] = ['title' => $title, 'ok' => $r['code'] === 0 ? null : !$exec, 'detail' => $r['code'] === 0 ? 'سایت از داخلِ سرور در دسترس نبود' : ($exec ? 'فایلِ php اجرا شد! (HTTP ' . $r['code'] . ')' : 'اجرا نشد (HTTP ' . $r['code'] . ')')];
        };
        $probeExec('uploads', 'اجرانشدنِ php در پوشه‌ی آپلودها');
        $probeExec('queue', 'اجرانشدنِ php در صفِ پردازش');
        $probeExec('tmp_ocr', 'اجرانشدنِ php در موقتِ OCR');
        $probeExec('Archive', 'اجرانشدنِ php در بایگانی');
        // بایگانی بدونِ ورود باز نشود
        $f = 'Archive/_sectest_' . $tag . '.txt';
        if (@file_put_contents($root . '/' . $f, 'SECFILE') !== false) {
            $mk[] = $root . '/' . $f;
            $r = sec_probe(sec_path_url($f));
            $open = strpos($r['body'], 'SECFILE') !== false;
            $items[] = ['title' => 'بسته‌بودنِ فایل‌های بایگانی بدونِ ورود', 'ok' => $r['code'] === 0 ? null : !$open,
                        'detail' => $r['code'] === 0 ? 'سایت در دسترس نبود' : ($open ? 'فایل بدونِ ورود دانلود شد! (mod_rewrite روشن نیست یا .htaccess بایگانی خوانده نمی‌شود)' : 'بسته است (HTTP ' . $r['code'] . ')')];
        }
        foreach (['config/db.local.php' => 'پوشه‌ی config', 'api/_security.php' => 'کتابخانه‌های API (فایل‌های _)', 'migrations/' => 'پوشه‌ی migrations', 'report_assets/parser_runner.py' => 'فایل‌های گزارش (کدِ پایتون)', '.git/HEAD' => 'پوشه‌ی .git', 'backup/' => 'پوشه‌ی پشتیبان‌ها'] as $p => $title) {
            $exists = file_exists($root . '/' . rtrim($p, '/'));
            if (!$exists) continue;
            $r = sec_probe(sec_path_url($p));
            $items[] = ['title' => 'بسته‌بودنِ ' . $title, 'ok' => $r['code'] === 0 ? null : ($r['code'] >= 400 || ($r['code'] === 200 && trim($r['body']) === '' && substr($p, -4) === '.php')),
                        'detail' => $r['code'] === 0 ? 'سایت در دسترس نبود' : 'HTTP ' . $r['code']];
        }
        $r = sec_probe(sec_path_url('uploads/'));
        $items[] = ['title' => 'بسته‌بودنِ فهرستِ فایل‌های پوشه‌ها', 'ok' => $r['code'] === 0 ? null : stripos($r['body'], 'Index of') === false, 'detail' => $r['code'] === 0 ? 'سایت در دسترس نبود' : 'HTTP ' . $r['code']];
        foreach ($mk as $f) @unlink($f);
        // ردِ همین آزمایش در رخدادها نماند (نسخه‌های قبلی، «دسترسیِ بدونِ ورود به فایلِ بایگانی» برای فایل‌های آزمایشی ثبت می‌کردند)
        try { $pdo->exec("DELETE FROM security_events WHERE type = 'FILE_DENIED' AND (detail LIKE '%\\_sectest\\_%' OR path LIKE '%\\_sectest\\_%')"); } catch (Throwable $e) {}
        $bad = count(array_filter($items, fn($x) => $x['ok'] === false));
        $unknown = count(array_filter($items, fn($x) => $x['ok'] === null));
        sec_save_setting($pdo, 'sec_live_test', json_encode(['at' => time(), 'items' => $items, 'bad' => $bad, 'unknown' => $unknown], JSON_UNESCAPED_UNICODE));
        sec_event($pdo, 'ADMIN_ACTION', 'آزمایشِ زنده‌ی امنیت: ' . $bad . ' مشکل');
        sout(['ok' => true, 'items' => $items, 'bad' => $bad]);

    case 'clear_old':
        $days = max(30, intval($data['days'] ?? 180));
        $n = $pdo->exec("DELETE FROM security_events WHERE at < DATE_SUB(NOW(), INTERVAL $days DAY)");
        sec_event($pdo, 'ADMIN_ACTION', "پاک‌کردنِ رخدادهای قدیمی‌تر از $days روز ($n ردیف)");
        sout(['ok' => true, 'deleted' => intval($n)]);
}
sout(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
