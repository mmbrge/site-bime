<?php
// فایل: api/_keepalive.php
// «نگه‌دارِ خودکارِ نشست‌ها» بدونِ وابستگی به cron: یک درخواستِ پس‌زمینه روی همین سرور هر چند دقیقه همه‌ی نشست‌های
// واردشده‌ی پاسارگاد (استعلام‌ها، پیش‌نویس‌ها/سایتِ بیمه‌گر) و سنهاب را با یک درخواستِ سبک زنده نگه می‌دارد؛ بعد از حدودِ ۱۰۰ ثانیه
// درخواستِ بعدی را خودش صدا می‌زند و تمام می‌شود (زنجیره). خارج از «ساعتِ کاریِ پنل» زنجیره می‌ایستد.
// اگر زنجیره به هر دلیلی بایستد (ریستارتِ هاست، محدودیتِ پردازش)، هر ضربانِ پنلِ باز، همگام‌سازیِ هر افزونه یا cron دوباره راهش می‌اندازد.
// ورود دوباره کپچا می‌خواهد و خودکار ممکن نیست؛ پس کارِ اصلی این است که نشست اصلاً منقضی نشود.
//   ka_kick($pdo)      : اگر زنجیره زنده نیست راهش بینداز (سبک؛ بدونِ معطلی)
//   ka_run_step($pdo)  : یک حلقه از زنجیره (در api/keepalive.php)
//   ka_info($pdo)      : وضعیت برای صفحه‌ی تنظیمات
require_once __DIR__ . '/_inquiry.php';

function ka_enabled($pdo) { return inq_get_setting($pdo, 'inq_keep_forever') !== '0' && inq_get_setting($pdo, 'inq_ka_on') !== '0'; }
function ka_interval($pdo) { $m = intval(inq_get_setting($pdo, 'inq_ka_min')); return max(60, min(900, ($m ?: 3) * 60)); }
function ka_file() { return inq_dir() . '/keepalive.json'; }
function ka_state() { $c = is_file(ka_file()) ? json_decode((string)@file_get_contents(ka_file()), true) : null; return is_array($c) ? $c : []; }
function ka_save(array $s) { @file_put_contents(ka_file(), json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX); }

// نشانیِ پوشه‌ی api همین سایت (از درخواستِ فعلی) برای اینکه زنجیره خودش را صدا بزند
function ka_remember_base($pdo) {
    if (empty($_SERVER['HTTP_HOST'])) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']);
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'))), '/');
    if (substr($dir, -4) !== '/api') return;   // فقط از اسکریپت‌های داخلِ api/
    $b = ($https ? 'https' : 'http') . '://' . $host . $dir;
    if (inq_get_setting($pdo, 'inq_ka_base') !== $b) inq_set_setting($pdo, 'inq_ka_base', $b);
}

// درخواستِ «بزن و برو» به همین سرور (جواب منتظر نمی‌ماند؛ آن طرف ignore_user_abort دارد)
function ka_fire($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 2500, CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_NOSIGNAL => 1,
                                CURLOPT_FOLLOWLOCATION => true, CURLOPT_HEADER => false, CURLOPT_USERAGENT => 'BimeBama-KeepAlive']);
        curl_exec($ch); $e = curl_errno($ch); curl_close($ch);
        return $e === 0 || $e === 28;   // ۲۸ = مهلت تمام شد (طبیعی: آن طرف هنوز کار می‌کند)
    }
    $p = parse_url($url); if (!$p || empty($p['host'])) return false;
    $ssl = ($p['scheme'] ?? 'http') === 'https';
    $fp = @fsockopen(($ssl ? 'ssl://' : '') . $p['host'], $p['port'] ?? ($ssl ? 443 : 80), $en, $es, 3);
    if (!$fp) return false;
    fwrite($fp, 'GET ' . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '') . " HTTP/1.1\r\nHost: {$p['host']}\r\nUser-Agent: BimeBama-KeepAlive\r\nConnection: close\r\n\r\n");
    usleep(300000); fclose($fp);
    return true;
}
function ka_url($pdo) {
    $b = inq_get_setting($pdo, 'inq_ka_base'); $k = inq_get_setting($pdo, 'inq_cron_key');
    if ($k === '') { $k = bin2hex(random_bytes(16)); inq_set_setting($pdo, 'inq_cron_key', $k); }
    return $b !== '' ? $b . '/keepalive.php?key=' . $k . '&t=' . time() : '';
}

// زنجیره زنده است؟ اگر نه (و الان ساعتِ کاری است) راهش بینداز. بیشتر از هر ۲۰ ثانیه یک بار کاری نمی‌کند.
function ka_kick($pdo) {
    try {
        if (!ka_enabled($pdo)) return false;
        ka_remember_base($pdo);
        $s = ka_state();
        if (time() - intval($s['beat'] ?? 0) < 150 || time() - intval($s['kick'] ?? 0) < 20) return false;
        require_once __DIR__ . '/_order_hours.php';
        if (!oh_panel_open($pdo)) return false;
        $u = ka_url($pdo); if ($u === '') return false;
        $s['kick'] = time(); ka_save($s);
        return ka_fire($u);
    } catch (Throwable $e) { error_log('[ka_kick] ' . $e->getMessage()); return false; }
}

// زنده نگه داشتنِ همه‌ی نشست‌ها (همان کارِ ضربانِ پنل): هر نشست یک درخواستِ سبک به یک صفحه‌ی واقعیِ سامانه
function ka_ping_all($pdo) {
    $r = ['psg' => 'skip', 'sanhab' => [], 'proxy' => null];
    try { $r['psg'] = inq_px_status($pdo, true); } catch (Throwable $e) { $r['psg'] = 'error'; }
    if (is_file(__DIR__ . '/_sanhab.php')) {
        require_once __DIR__ . '/_sanhab.php';
        try { for ($sl = 0; $sl < sh_pool_size(); $sl++) { $GLOBALS['SH_SLOT'] = $sl; $r['sanhab'][] = sh_status($pdo, true); } } catch (Throwable $e) { $r['sanhab'][] = 'error'; }
        $GLOBALS['SH_SLOT'] = 0;
    }
    try { require_once __DIR__ . '/_psg_lov.php'; $r['proxy'] = psgl_ping_proxy(); } catch (Throwable $e) { $r['proxy'] = 'error'; }
    // وضعیتِ تک‌تکِ نشست‌های پاسارگاد
    $r['slots'] = [];
    for ($sl = 0; $sl < px_pool_size(); $sl++) $r['slots'][] = px_slot_pending($sl) ? 'captcha' : (px_slot_state($sl) ?: '?');
    return $r;
}

// یک حلقه از زنجیره: حدودِ ۱۰۰ ثانیه، با فاصله‌ی تنظیم‌شده ضربان می‌زند، بعد حلقه‌ی بعد را صدا می‌زند
function ka_run_step($pdo) {
    $lf = @fopen(inq_dir() . '/keepalive.lock', 'c');
    if (!$lf || !flock($lf, LOCK_EX | LOCK_NB)) { if ($lf) fclose($lf); return 'busy'; }   // حلقه‌ی دیگری زنده است
    $next = true; $why = '';
    try {
        require_once __DIR__ . '/_order_hours.php';
        $t0 = time();
        while (true) {
            $s = ka_state();
            if (!ka_enabled($pdo)) { $next = false; $why = 'off'; break; }
            if (!oh_panel_open($pdo)) { $next = false; $why = 'hours'; break; }   // خارج از ساعتِ کاری: می‌ایستد (با فعالیتِ بعدی دوباره راه می‌افتد)
            $s['beat'] = time(); $s['mode'] = 'run';
            if (time() - intval($s['ping'] ?? 0) >= ka_interval($pdo) - 5) {
                $s['res'] = ka_ping_all($pdo); $s['ping'] = time();
                $s['pings'] = intval($s['pings'] ?? 0) + 1;
            }
            ka_save($s);
            if (time() - $t0 >= 100) break;
            sleep(min(20, max(1, 100 - (time() - $t0))));
        }
        if (!$next) { $s = ka_state(); $s['mode'] = $why; $s['beat'] = time(); ka_save($s); }
    } catch (Throwable $e) {
        error_log('[ka_step] ' . $e->getMessage());
        $s = ka_state(); $s['err'] = mb_substr($e->getMessage(), 0, 200); $s['err_at'] = time(); ka_save($s);
    } finally { flock($lf, LOCK_UN); fclose($lf); }
    if ($next) {
        $u = ka_url($pdo);
        if ($u === '' || !ka_fire($u)) { $s = ka_state(); $s['err'] = 'صدا زدنِ حلقه‌ی بعد ممکن نشد (شاید هاست درخواست به خودش را می‌بندد)'; $s['err_at'] = time(); ka_save($s); }
    }
    return $next ? 'next' : $why;
}

function ka_info($pdo) {
    $s = ka_state();
    $alive = time() - intval($s['beat'] ?? 0) < 150;
    return ['enabled' => ka_enabled($pdo), 'alive' => $alive, 'mode' => $alive ? ($s['mode'] ?? 'run') : (($s['mode'] ?? '') === 'hours' ? 'hours' : 'stopped'),
            'beat_ago' => isset($s['beat']) ? time() - intval($s['beat']) : null, 'ping_ago' => isset($s['ping']) ? time() - intval($s['ping']) : null,
            'pings' => intval($s['pings'] ?? 0), 'interval_min' => intdiv(ka_interval($pdo), 60), 'res' => $s['res'] ?? null,
            'err' => (isset($s['err_at']) && time() - intval($s['err_at']) < 3600) ? (string)($s['err'] ?? '') : '', 'base' => inq_get_setting($pdo, 'inq_ka_base')];
}
