<?php
// فایل: api/_sanhab.php
// «استعلامِ سنهاب» (سامانه‌ی بیمه مرکزی): سوابقِ بیمه‌نامه‌ها (ثالث / بدنه / حوادث)، الحاقیه‌ها و خسارت‌ها
// با پلاک، شماره‌ی بیمه‌نامه، کد ملی، شاسی، موتور یا VIN. منطقِ سامانه همان ابزارِ استعلام (SanhabRunner) است؛ تفاوت‌ها:
//   - نام کاربری و رمزِ سنهاب در «استعلامات ← تنظیمات» (فقط مدیر کل، رمزنگاری‌شده)؛ بقیه فقط کپچا می‌زنند:
//     کپچای ورود (وقتی نشست بسته است) و کپچای خودِ هر استعلام؛
//   - نشستِ سنهاب یکی است و کپچای استعلام به همان نشست بسته است، پس در هر لحظه یک نفر «نوبت» دارد (تا ۹۰ ثانیه)؛
//   - هر استعلام یک ردیف در inq_requests (kind = sanhab، query_text، و ref برای استعلام از داخلِ فرم‌ها).
// جدول‌ها و ستون‌ها خودکار ساخته می‌شوند (sh_ensure).
require_once __DIR__ . '/_inquiry.php';

const SH_TYPES = ['4' => 'پلاک ملی (خودرو)', '8' => 'پلاک ملی (موتورسیکلت)', '45' => 'پلاک غیرملی', '7' => 'شماره بیمه‌نامه', '3' => 'کد ملی بیمه‌گذار',
                  '5' => 'شماره VIN', '1' => 'شماره موتور', '2' => 'شماره شاسی', '6' => 'شماره موتور و شاسی'];
const SH_PLCY = ['0' => 'بیمه‌نامه (ثالث / بدنه / حوادث)', '1' => 'سوابقِ خسارت'];
const SH_HOLD_TTL = 90;
const SH_LOGIN_CAPTCHA_TTL = 300;

function sh_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    inq_ensure($pdo);
    foreach (['query_text' => 'VARCHAR(190) NULL', 'ref_type' => 'VARCHAR(16) NULL', 'ref_id' => 'INT NULL'] as $c => $def) {
        try { if (!$pdo->query("SHOW COLUMNS FROM inq_requests LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE inq_requests ADD COLUMN `$c` $def"); }
        catch (Throwable $e) { error_log('[sh_ensure] ' . $e->getMessage()); }
    }
    try { if (!$pdo->query("SHOW INDEX FROM inq_requests WHERE Key_name = 'idx_ref'")->fetch()) $pdo->exec("ALTER TABLE inq_requests ADD INDEX idx_ref (ref_type, ref_id), ADD INDEX idx_query (query_text)"); }
    catch (Throwable $e) { error_log('[sh_ensure idx] ' . $e->getMessage()); }
}
function sh_creds($pdo) { return ['user' => inq_get_setting($pdo, 'inq_sh_user'), 'pass' => inq_dec(inq_get_setting($pdo, 'inq_sh_pass'))]; }
function sh_enabled($pdo) { return inq_get_setting($pdo, 'inq_sh_enabled') === '1'; }

// ---- قفلِ نشست (جدا از پاسارگاد) ----
function sh_lock($waitSec = 40) {
    $fh = @fopen(inq_dir('sanhab') . '/session.lock', 'c');
    if (!$fh) return null;
    $t0 = time();
    while (!flock($fh, LOCK_EX | LOCK_NB)) {
        if (time() - $t0 >= $waitSec) { fclose($fh); return null; }
        usleep(400000);
    }
    return $fh;
}
function sh_mark($s) { @file_put_contents(inq_dir('sanhab') . '/status.json', json_encode(['t' => time(), 's' => $s])); }
function sh_touch() { @file_put_contents(inq_dir('sanhab') . '/activity.txt', (string)time()); }
function sh_last_activity() { $f = inq_dir('sanhab') . '/activity.txt'; return is_file($f) ? intval(@file_get_contents($f)) : 0; }

// ---- «نوبتِ» کپچای استعلام: کپچا به نشستِ مشترک بسته است؛ تا نوبتِ کسی تمام نشده، کپچای دیگری گرفته نمی‌شود ----
function sh_hold_get() {
    $f = inq_dir('sanhab') . '/hold.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($c) && time() - intval($c['t'] ?? 0) < SH_HOLD_TTL ? $c : null;
}
function sh_hold_take($key, $name) {
    $h = sh_hold_get();
    // نوبتِ پنجره‌ی دیگرِ همین کاربر را خودش می‌تواند بگیرد
    $same = function ($a, $b) { $x = explode(':', (string)$a); $y = explode(':', (string)$b); return count($x) >= 2 && count($y) >= 2 && $x[0] === $y[0] && $x[1] === $y[1]; };
    if ($h && $h['k'] !== $key && !$same($h['k'], $key)) {
        $w = max(5, SH_HOLD_TTL - (time() - intval($h['t'])));
        return ['ok' => false, 'busy' => true, 'wait' => $w,
                'error' => 'سامانه‌ی سنهاب همین الان در اختیارِ ' . ($h['n'] !== '' ? '«' . $h['n'] . '»' : 'کاربرِ دیگری') . ' است؛ حدودِ ' . $w . ' ثانیه‌ی دیگر دوباره بزنید.'];
    }
    @file_put_contents(inq_dir('sanhab') . '/hold.json', json_encode(['k' => $key, 'n' => (string)$name, 't' => time()], JSON_UNESCAPED_UNICODE));
    return ['ok' => true];
}
function sh_hold_release($key) {
    $h = sh_hold_get();
    if ($h && $h['k'] === $key) @unlink(inq_dir('sanhab') . '/hold.json');
}

// ---- کپچای ورود (مشترک برای همه؛ مثلِ پاسارگاد) ----
function sh_login_captcha_pending() {
    $f = inq_dir('sanhab') . '/login_captcha.json';
    if (!is_file($f)) return false;
    $c = json_decode((string)@file_get_contents($f), true);
    return is_array($c) && !empty($c['image']) && time() - intval($c['t'] ?? 0) < SH_LOGIN_CAPTCHA_TTL;
}
function sh_login_captcha($pdo, $refresh = false) {
    $f = inq_dir('sanhab') . '/login_captcha.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!$refresh && is_array($c) && !empty($c['image']) && time() - intval($c['t'] ?? 0) < SH_LOGIN_CAPTCHA_TTL) return ['ok' => true, 'image' => $c['image']];
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'افزونه‌ی curl روی سرور فعال نیست.'];
    $lk = sh_lock(25);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه‌ی سنهاب مشغولِ استعلامِ دیگری است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    try { $r = (new InqSanhab())->getLoginCaptcha(); } finally { inq_unlock($lk); }
    if (!empty($r['loggedIn'])) { @unlink($f); sh_mark('logged_in'); return ['ok' => true, 'logged_in' => true]; }
    if (!empty($r['image'])) { @file_put_contents($f, json_encode(['t' => time(), 'image' => $r['image']])); sh_mark('logged_out'); return ['ok' => true, 'image' => $r['image']]; }
    return ['ok' => false, 'error' => $r['message'] ?? 'تصویرِ امنیتیِ ورودِ سنهاب دریافت نشد.'];
}
function sh_login($pdo, $text) {
    $cr = sh_creds($pdo);
    if ($cr['user'] === '' || $cr['pass'] === '') return ['ok' => false, 'error' => 'نام کاربری و رمزِ سنهاب هنوز در «استعلامات ← تنظیمات» وارد نشده؛ به مدیر کل اطلاع دهید.'];
    $text = trim(inq_digits_keep($text));
    if ($text === '') return ['ok' => false, 'error' => 'متنِ تصویرِ امنیتی را بنویسید.'];
    $lk = sh_lock(25);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه‌ی سنهاب مشغول است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    try { $r = (new InqSanhab())->login($cr['user'], $cr['pass'], $text); } finally { inq_unlock($lk); }
    @unlink(inq_dir('sanhab') . '/login_captcha.json');
    if (!empty($r['ok'])) { sh_mark('logged_in'); sh_touch(); return ['ok' => true]; }
    $n = sh_login_captcha($pdo, true);
    return ['ok' => false, 'error' => $r['message'] ?? 'کد اشتباه بود؛ دوباره بزنید.', 'image' => $n['image'] ?? null];
}
// پیام‌ها برای کاربرانِ غیرِ مدیر کل (پنل): از «ورود/نشست/نام کاربری» و نامِ سامانه و کاربرِ دیگر چیزی گفته نمی‌شود؛
// کاربر فقط «کدِ امنیتی» می‌بیند (مثلِ یک سرویسِ استعلام). کپچای ورود هم برایش فقط «مرحله‌ی اولِ کدِ امنیتی» است.
function sh_user_safe($r) {
    if (!is_array($r)) return $r;
    $fix = function ($m) use ($r) {
        $m = (string)$m;
        if ($m === '') return $m;
        if (!empty($r['need_login']) || preg_match('/نشست|کدِ تصویرِ ورود|کد تصویر ورود/u', $m)) return 'برای ادامه، کدِ امنیتیِ نمایش‌داده‌شده را بزنید.';
        if (preg_match('/در اختیارِ.*حدودِ\s*(\d+)\s*ثانیه/u', $m, $x)) return 'سرویسِ استعلام چند لحظه مشغول است؛ حدودِ ' . $x[1] . ' ثانیه‌ی دیگر دوباره بزنید.';
        if (preg_match('/نام کاربری|رمز/u', $m)) return preg_match('/وارد نشده|تنظیمات/u', $m) ? 'سرویسِ استعلام هنوز راه‌اندازی نشده؛ به مدیر کل اطلاع دهید.' : 'کدِ امنیتی پذیرفته نشد؛ کدِ جدید را بزنید.';
        if (preg_match('/مشغول/u', $m)) return 'سرویسِ استعلام مشغول است؛ چند ثانیه‌ی دیگر دوباره بزنید.';
        return str_replace(['سامانه‌ی سنهاب', 'سامانه سنهاب', 'سایتِ سنهاب', 'از سنهاب دریافت نشد'], ['سرویسِ استعلام', 'سرویسِ استعلام', 'سرویسِ استعلام', 'دریافت نشد'], $m);
    };
    foreach (['error', 'message', 'notice'] as $k) if (isset($r[$k]) && is_string($r[$k])) $r[$k] = $fix($r[$k]);
    return $r;
}
// ارقامِ فارسی => لاتین (بقیه‌ی نویسه‌ها می‌مانند؛ برای کپچا)
function inq_digits_keep($s) {
    return strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                              '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
}
function sh_need_login($pdo) {
    sh_mark('logged_out');
    $c = sh_login_captcha($pdo);
    return ['ok' => false, 'need_login' => true, 'login_image' => $c['image'] ?? null, 'error' => $c['ok'] ? 'نشستِ سنهاب بسته است؛ کدِ تصویرِ ورود را بزنید.' : ($c['error'] ?? 'نشستِ سنهاب بسته است.')];
}
// وضعیتِ اتصال (با کشِ کوتاه؛ وقتی نوبتِ کسی باز است یا کپچای ورود منتظر است، به سامانه درخواستی نمی‌رود)
function sh_status($pdo, $fresh = false) {
    if (sh_login_captcha_pending()) return 'logged_out';
    $f = inq_dir('sanhab') . '/status.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (sh_hold_get()) return is_array($c) ? $c['s'] : 'logged_in';
    if (!$fresh && is_array($c) && time() - intval($c['t'] ?? 0) < 60) return $c['s'];
    $cr = sh_creds($pdo);
    if ($cr['user'] === '' || !function_exists('curl_init')) return 'not_set';
    $lk = sh_lock(5);
    if (!$lk) return is_array($c) ? $c['s'] : 'busy';
    try { $s = (new InqSanhab())->status(); } finally { inq_unlock($lk); }
    sh_mark($s);
    return $s;
}

// =====================================================================
//  ورودی‌ها، نتیجه و ثبت
// =====================================================================
function sh_up($s) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', inq_digits_keep($s))); }
// «۲۱ ایران - ۱۲۳ ب ۱۱» یا «۱۱ ب ۱۲۳ ایران ۲۱» => اجزای پلاک (Plk1 = ایران، Plk2 = سه‌رقمی، plk3 = حرف، Plk4 = دورقمیِ چپ)
function sh_parse_plate($str) {
    $s = trim(str_replace(['ي', 'ك'], ['ی', 'ک'], inq_digits_keep($str)));
    $L = '([\x{0600}-\x{06FF}A-Za-z]{1,3})';
    $fix = function ($l) { $l = trim($l); return in_array($l, ['آ', 'ا', 'الف'], true) ? 'الف' : $l; };
    // قالبِ سایت: «۲۱ایران - ۱۲۳ ب ۱۱»
    if (preg_match('/(\d{2})\s*ایران\s*-?\s*(\d{3})\s*' . $L . '\s*(\d{2})(?!\d)/u', $s, $m)) return ['plk1' => $m[1], 'plk2' => $m[2], 'plk3' => $fix($m[3]), 'plk4' => $m[4]];
    // قالبِ سنهاب و نامه‌ها: «۹۹۴ط۵۹ ۷۸»، «۹۹۴ط۵۹-۷۸»، «۹۹۴ ط ۵۹ ایران ۷۸» (سه‌رقمی، حرف، دورقمی، کدِ ایران)
    if (preg_match('/(?<!\d)(\d{3})\s*' . $L . '\s*(\d{2})\s*(?:[-\/|_]|\s)+\s*(?:ایران\s*)?(\d{2})(?!\d)/u', $s, $m) && $m[2] !== 'ایران') return ['plk1' => $m[4], 'plk2' => $m[1], 'plk3' => $fix($m[2]), 'plk4' => $m[3]];
    // «۱۱ ب ۱۲۳ ایران ۲۱» یا «۱۱ ب ۱۲۳ - ۲۱»
    if (preg_match('/(?<!\d)(\d{2})\s*' . $L . '\s*(\d{3})\s*(?:ایران|-|\s)\s*(?:ایران\s*)?(\d{2})(?!\d)/u', $s, $m) && $m[2] !== 'ایران') return ['plk1' => $m[4], 'plk2' => $m[3], 'plk3' => $fix($m[2]), 'plk4' => $m[1]];
    if (!preg_match_all('/\d+/', $s, $nm) || count($nm[0]) < 3 || !preg_match('/[\x{0600}-\x{06FF}A-Za-z]+/u', preg_replace('/ایران/u', ' ', $s), $lm)) return null;
    $nums = $nm[0];
    $letter = $lm[0] === 'آ' ? 'الف' : $lm[0];
    $p1 = $p2 = $p4 = '';
    if (strlen($nums[0]) === 2 && strlen($nums[1]) === 3 && strlen($nums[2]) === 2) {
        $ip = mb_strpos($s, 'ایران');
        if ($ip !== false && $ip < mb_strpos($s, $nums[1])) { $p1 = $nums[0]; $p2 = $nums[1]; $p4 = $nums[2]; }
        else { $p4 = $nums[0]; $p2 = $nums[1]; $p1 = $nums[2]; }
    } else {
        foreach ($nums as $n) {
            if (strlen($n) === 3 && $p2 === '') $p2 = $n;
            elseif (strlen($n) === 2 && $p1 === '') $p1 = $n;
            elseif (strlen($n) === 2 && $p4 === '') $p4 = $n;
        }
    }
    return ['plk1' => $p1, 'plk2' => $p2, 'plk3' => $letter, 'plk4' => $p4];
}
// ورودیِ یک استعلام => [ok, type, plcy, params, label, nid] یا [ok=false, error]
function sh_validate(array $in) {
    $type = (string)($in['type'] ?? ($in['typeId'] ?? '4'));
    $plcy = (string)($in['plcy'] ?? ($in['plcyType'] ?? '0'));
    if (!isset(SH_TYPES[$type])) return ['ok' => false, 'error' => 'نوعِ جستجو نامعتبر است.'];
    if (!isset(SH_PLCY[$plcy])) $plcy = '0';
    $g = function ($k) use (&$in) { return trim(inq_digits_keep((string)($in[$k] ?? ''))); };
    $p = []; $label = ''; $nid = null;
    if ($type === '4') {
        if (!empty($in['plate']) && empty($in['plk2'])) { $pp = sh_parse_plate($in['plate']); if ($pp) $in = $pp + $in; }
        $p1 = preg_replace('/\D/', '', $g('plk1')); $p2 = preg_replace('/\D/', '', $g('plk2')); $p4 = preg_replace('/\D/', '', $g('plk4'));
        $l = trim((string)($in['plk3'] ?? '')); if ($l === 'آ') $l = 'الف';
        if (strlen($p1) !== 2 || strlen($p2) !== 3 || strlen($p4) !== 2 || $l === '') return ['ok' => false, 'error' => 'پلاک را کامل وارد کنید (۲ رقمِ چپ، حرف، ۳ رقمِ وسط و ۲ رقمِ ایران).', 'field' => 'plate'];
        $p = ['plk1' => $p1, 'plk2' => $p2, 'plk3' => $l, 'plk4' => $p4];
        $label = "{$p1}ایران - {$p2} {$l} {$p4}";
    } elseif ($type === '8' || $type === '45') {
        $a = preg_replace('/\D/', '', $g('plk2m')); $b = preg_replace('/\D/', '', $g('plk1m'));
        if ($a === '' && $b === '' && !empty($in['plate']) && preg_match('/(\d{3})\D+(\d{5})/', inq_digits_keep($in['plate']), $mm)) { $a = $mm[1]; $b = $mm[2]; }
        if ($a === '' || $b === '') return ['ok' => false, 'error' => 'هر دو بخشِ پلاک را وارد کنید.', 'field' => 'plate'];
        $p = ['plk1m' => $b, 'plk2m' => $a];
        $label = $a . '-' . $b;
    } elseif ($type === '6') {
        $e = sh_up($in['txtInput'] ?? ''); $c = sh_up($in['txtInput2'] ?? '');
        if ($e === '' || $c === '') return ['ok' => false, 'error' => 'شماره‌ی موتور و شاسی هر دو لازم است.', 'field' => 'txtInput'];
        $p = ['txtInput' => $e, 'txtInput2' => $c];
        $label = 'موتور ' . $e . ' · شاسی ' . $c;
    } else {
        $v = in_array($type, ['1', '2', '5'], true) ? sh_up($in['txtInput'] ?? '') : preg_replace('/\s+/u', '', $g('txtInput'));
        if ($type === '3') {
            $v = preg_replace('/\D/', '', $v);
            if (strlen($v) >= 8 && strlen($v) < 10) $v = str_pad($v, 10, '0', STR_PAD_LEFT);
            if (!(strlen($v) === 10 && inq_nid_ok($v)) && strlen($v) !== 11) return ['ok' => false, 'error' => 'کد ملی (۱۰ رقم) یا شناسه‌ی ملی (۱۱ رقم) نامعتبر است.', 'field' => 'txtInput'];
            $nid = $v;
        }
        if ($v === '') return ['ok' => false, 'error' => 'مقدارِ جستجو را وارد کنید.', 'field' => 'txtInput'];
        $p = ['txtInput' => $v];
        $label = $v;
    }
    return ['ok' => true, 'type' => $type, 'plcy' => $plcy, 'params' => $p, 'label' => mb_substr($label, 0, 190), 'nid' => $nid];
}

function sh_int($v) { $d = preg_replace('/[^\d]/', '', inq_digits_keep((string)$v)); return $d === '' ? null : intval($d); }
// نوعِ خسارت (متنِ سنهاب) => مالی / بدنی (جانی) / حوادث راننده
function sh_loss_kind($t) {
    $t = (string)$t;
    if (preg_match('/راننده|سرنشین/u', $t)) return 'driver';
    if (preg_match('/جان|بدن|فوت|دیه|نقص|درمان/u', $t)) return 'jani';
    return 'mali';
}
// خلاصه‌ی قابلِ استفاده‌ی نتیجه (از جدیدترین بیمه‌نامه = ردیفِ اول)
function sh_brief(array $res) {
    $rows = $res['rows'] ?? [];
    $f = $rows[0] ?? [];
    $ls = $res['latestSummary'] ?? [];
    $cnt = ['mali' => 0, 'jani' => 0, 'driver' => 0];
    foreach (($f['losses'] ?? []) as $l) $cnt[sh_loss_kind($l['lossType'] ?? '')]++;
    $d = function ($k) use ($f) { return trim(inq_digits_keep((string)($f[$k] ?? ''))); };
    return ['found' => !empty($rows), 'count' => count($rows), 'has_loss' => !empty($ls['hasLoss']), 'loss_status' => $ls['lossStatus'] ?? '', 'loss_types' => $ls['lossTypes'] ?? '',
            'loss_counts' => $cnt, 'older_losses' => $ls['olderHistoryLosses'] ?? '', 'summary_text' => $ls['summaryText'] ?? '',
            'tp_disc' => sh_int($f['discFinPct'] ?? ''), 'life_disc' => sh_int($f['discLifePct'] ?? ''), 'dr_disc' => sh_int($f['discPassPct'] ?? ''),
            'no_loss_fin' => sh_int($f['noLossFinYr'] ?? ''), 'no_loss_life' => sh_int($f['noLossLifeYr'] ?? ''), 'no_loss_pass' => sh_int($f['noLossPassYr'] ?? ''), 'no_loss_body' => sh_int($f['noLossBodyYr'] ?? ''),
            'insurer' => inq_fa_norm($f['company'] ?? ''), 'policy_type' => inq_fa_norm($f['policyType'] ?? ''), 'policy_no' => $d('policyNo'), 'prev_policy_no' => $d('prevPolicyNo'),
            'unique_code' => $d('uniqueCode'), 'insured' => inq_fa_norm($f['insuredName'] ?? ''), 'vehicle' => inq_fa_norm($f['vehicleName'] ?? ''), 'plate' => inq_fa_norm($f['plate'] ?? ''),
            'engine' => $d('motorNo'), 'chassis' => $d('chassisNo'), 'vin' => $d('vin'), 'issue_date' => $d('issueDate'), 'start_date' => $d('startDate'), 'end_date' => $d('endDate'),
            'penalty' => $d('penaltyCost'), 'inquirer' => $res['inquirerName'] ?? '', 'inquiry_date' => inq_digits_keep((string)($res['inquiryDate'] ?? '')),
            'plate_parts' => ($pp = sh_parse_plate((string)($f['plate'] ?? ''))) ? ['p1' => $pp['plk1'], 'p2' => $pp['plk2'], 'letter' => $pp['plk3'], 'p4' => $pp['plk4']] : null,
            'plate_fa' => $pp ? "{$pp['plk1']}ایران - {$pp['plk2']} {$pp['plk3']} {$pp['plk4']}" : inq_fa_norm($f['plate'] ?? '')];
}
// پلاکِ یک ردیفِ سنهاب به قالبِ سایت («۷۸ایران - ۹۹۴ ط ۵۹»)
function sh_plate_fa($v) { $pp = sh_parse_plate((string)$v); return $pp ? "{$pp['plk1']}ایران - {$pp['plk2']} {$pp['plk3']} {$pp['plk4']}" : inq_fa_norm((string)$v); }

// ---- خروجیِ سوابق (اکسل و PDFِ «خروجیِ سایت») — sel: اندیسِ ردیف‌های انتخاب‌شده (خالی = همه) ----
function sh_export_rows(array $r, array $sel) {
    $res = json_decode((string)($r['result_json'] ?? ''), true) ?: [];
    $rows = $res['sanhab']['rows'] ?? [];
    if ($sel) $rows = array_values(array_filter($rows, function ($x, $i) use ($sel) { return in_array($i, $sel, true); }, ARRAY_FILTER_USE_BOTH));
    return [$res, $rows];
}
function sh_loss_text(array $row) {
    return implode(' | ', array_map(function ($l) { return trim(($l['lossType'] ?? '') . ' ' . ($l['paidAmount'] ?? '') . ' ریال · پرداخت ' . ($l['paidDate'] ?? '')); }, $row['losses'] ?? []));
}
function sh_export_xlsx(array $r, array $sel) {
    require_once __DIR__ . '/_xlsx_writer.php';
    [$res, $rows] = sh_export_rows($r, $sel);
    $out = [];
    foreach ($rows as $i => $x) {
        $rs = $x['rowSummary'] ?? [];
        $out[] = [$x['radif'] ?? ($i + 1), $rs['lossStatus'] ?? '', $x['policyType'] ?? '', $x['company'] ?? '', $x['insuredName'] ?? '', inq_digits_keep($x['policyNo'] ?? ''), inq_digits_keep($x['prevPolicyNo'] ?? ''),
                  $x['vehicleName'] ?? '', sh_plate_fa($x['plate'] ?? ''), $x['chassisNo'] ?? '', $x['vin'] ?? '', $x['motorNo'] ?? '', inq_digits_keep($x['issueDate'] ?? ''), inq_digits_keep($x['startDate'] ?? ''),
                  inq_digits_keep($x['endDate'] ?? ''), ($x['discFinPct'] ?? '0') . '٪', ($x['discLifePct'] ?? '0') . '٪', ($x['discPassPct'] ?? '0') . '٪', $x['noLossFinYr'] ?? '', $x['noLossLifeYr'] ?? '',
                  $x['noLossPassYr'] ?? '', count($x['endorsements'] ?? []), sh_loss_text($x), $x['uniqueCode'] ?? ''];
    }
    return xlsx_build(['ردیف', 'وضعیتِ خسارت', 'نوع', 'بیمه‌گر', 'بیمه‌گذار', 'شماره بیمه‌نامه', 'بیمه‌نامه‌ی قبل', 'خودرو', 'پلاک', 'شاسی', 'VIN', 'موتور', 'صدور', 'شروع', 'انقضا',
                       'تخفیف ثالث', 'تخفیف جانی', 'تخفیف حوادث راننده', 'سال عدم خسارت مالی', 'سال عدم خسارت جانی', 'سال عدم خسارت سرنشین', 'تعداد الحاقیه', 'خسارت‌ها', 'کد یکتا'], $out, 'سوابق سنهاب', [0]);
}
// PDFِ «خروجیِ سایت»: افقی، فونتِ وزیر، خلاصه‌ی نتیجه + جدولِ کامل با زیرجدولِ الحاقیه‌ها و خسارت‌ها
function sh_export_pdf(array $r, array $sel, $dest) {
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    [$res, $rows] = sh_export_rows($r, $sel);
    $b = sh_brief(['rows' => $rows ?: ($res['sanhab']['rows'] ?? []), 'latestSummary' => $res['sanhab']['latestSummary'] ?? []] + ($res['sanhab'] ?? []));
    if ($rows) { $first = $rows[0]; $b['has_loss'] = !empty($first['rowSummary']['hasLoss']); }
    $fa = function ($v) { return strtr((string)$v, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); };
    $e = function ($v) use ($fa) { $v = trim((string)$v); return $v === '' ? '—' : htmlspecialchars($fa($v), ENT_QUOTES, 'UTF-8'); };
    $el = function ($v) { $v = trim((string)$v); return $v === '' ? '—' : htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('بیمه با ما'); $pdf->SetTitle('خروجیِ سایت — سوابقِ سنهاب');
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(true); $pdf->setFooterFont(['vazir', '', 7]); $pdf->setFooterMargin(6);
    $pdf->SetMargins(8, 8, 8); $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->setRTL(true); $pdf->SetFont('vazir', '', 8);
    $pdf->AddPage();
    $ok = !$b['has_loss'];
    $col = $ok ? '#059669' : '#e11d48';
    $h = '<table cellpadding="5" style="width:100%"><tr>
        <td width="62%" style="background-color:#0c4a6e;color:#ffffff"><span style="font-size:7.5pt;color:#bae6fd">خروجیِ سایت — بیمه با ما</span><br><b style="font-size:13pt">سوابقِ بیمه‌نامه از سامانه‌ی سنهاب (بیمه مرکزی)</b><br>
            <span style="font-size:8pt">جستجو: ' . $e(($r['query_text'] ?? '')) . ' · تاریخِ استعلام: ' . $e(jdate_short($r['created_at'] ?? date('Y-m-d')) . ' ' . substr((string)($r['created_at'] ?? ''), 11, 5)) . ' · ' . $e(count($rows)) . ' بیمه‌نامه</span></td>
        <td width="38%" style="background-color:' . $col . ';color:#ffffff;text-align:center"><b style="font-size:16pt">' . ($ok ? 'عدمِ خسارت' : 'خسارت دارد') . '</b><br>
            <span style="font-size:8.5pt">' . ($ok ? 'جدیدترین بیمه‌نامه بدونِ خسارت است' : $e($b['loss_types'])) . '</span></td></tr></table>';
    $kv = function ($k, $v) use ($e) { return '<td style="background-color:#f8fafc;border:0.3px solid #e2e8f0"><span style="color:#64748b;font-size:7pt">' . $k . '</span><br><b style="font-size:9pt">' . $e($v) . '</b></td>'; };
    $h .= '<table cellpadding="4" style="width:100%;margin-top:4px"><tr>' . $kv('تخفیفِ ثالث (مالی/جانی)', $b['tp_disc'] === null ? '' : $b['tp_disc'] . '٪') . $kv('تخفیفِ حوادثِ راننده', $b['dr_disc'] === null ? '' : $b['dr_disc'] . '٪')
        . $kv('بیمه‌گر', $b['insurer']) . $kv('شماره بیمه‌نامه', $b['policy_no']) . $kv('شروع / انقضا', $b['start_date'] . ' تا ' . $b['end_date']) . $kv('بیمه‌گذار', $b['insured']) . '</tr><tr>'
        . $kv('خودرو', $b['vehicle']) . $kv('پلاک', $b['plate_fa']) . $kv('شاسی / VIN', $b['chassis'] ?: $b['vin']) . $kv('موتور', $b['engine']) . $kv('سال‌های عدمِ خسارت (مالی/جانی)', ($b['no_loss_fin'] ?? '—') . ' / ' . ($b['no_loss_life'] ?? '—'))
        . $kv('خسارتِ سال‌های قبل', $b['older_losses']) . '</tr></table>';
    $th = function ($t, $w) { return '<th width="' . $w . '" style="background-color:#0f172a;color:#ffffff;font-size:7pt;text-align:center"><b>' . $t . '</b></th>'; };
    $h .= '<br><table cellpadding="3" style="width:100%;border-collapse:collapse"><thead><tr>' . $th('#', '3%') . $th('وضعیتِ خسارت', '9%') . $th('نوع', '5%') . $th('بیمه‌گر', '8%') . $th('بیمه‌گذار', '10%')
        . $th('شماره بیمه‌نامه', '9%') . $th('بیمه‌نامه‌ی قبل', '8%') . $th('خودرو', '8%') . $th('پلاک', '8%') . $th('شاسی / VIN', '10%') . $th('شروع', '5.5%') . $th('انقضا', '5.5%')
        . $th('تخفیف ثالث/جانی/راننده', '7%') . $th('عدم خسارت (سال)', '4%') . '</tr></thead><tbody>';
    foreach ($rows as $i => $x) {
        $rs = $x['rowSummary'] ?? [];
        $bg = $i % 2 ? '#f8fafc' : '#ffffff';
        $st = !empty($rs['hasLoss']) ? '<span style="color:#be123c"><b>' . $e($rs['lossStatus'] ?? '') . '</b></span>' : '<span style="color:#047857"><b>عدمِ خسارت</b></span>';
        $td = function ($v, $w, $ltr = false) use ($bg) { return '<td width="' . $w . '" style="background-color:' . $bg . ';border-bottom:0.3px solid #e2e8f0;font-size:7pt;text-align:center"' . ($ltr ? ' dir="ltr"' : '') . '>' . $v . '</td>'; };
        $h .= '<tr nobr="true">' . $td($e($x['radif'] ?? ($i + 1)), '3%') . $td($st, '9%') . $td($e($x['policyType'] ?? ''), '5%') . $td('<b>' . $e($x['company'] ?? '') . '</b>', '8%') . $td($e($x['insuredName'] ?? ''), '10%')
            . $td($e($x['policyNo'] ?? ''), '9%') . $td($e($x['prevPolicyNo'] ?? ''), '8%') . $td($e($x['vehicleName'] ?? ''), '8%') . $td($e(sh_plate_fa($x['plate'] ?? '')), '8%')
            . $td($el(($x['chassisNo'] ?? '') ?: ($x['vin'] ?? '')), '10%', true) . $td($e($x['startDate'] ?? ''), '5.5%') . $td($e($x['endDate'] ?? ''), '5.5%')
            . $td($e((($x['discFinPct'] ?? '') ?: '0') . '٪ / ' . (($x['discLifePct'] ?? '') ?: '0') . '٪ / ' . (($x['discPassPct'] ?? '') ?: '0') . '٪'), '7%') . $td($e(($x['noLossFinYr'] ?? '') . '/' . ($x['noLossLifeYr'] ?? '')), '4%') . '</tr>';
        foreach (($x['endorsements'] ?? []) as $en)
            $h .= '<tr nobr="true"><td width="3%"></td><td colspan="13" width="97%" style="background-color:#ecfdf5;font-size:6.8pt;color:#065f46">الحاقیه: <b>' . $e($en['endorsementType'] ?? '') . '</b> · تاریخ ' . $e($en['issueDate'] ?? '')
                . ' · بیمه‌گذار ' . $e($en['insuredName'] ?? '') . ' · تخفیف مالی/جانی/سرنشین ' . $e(($en['discFinPct'] ?? '0') . '٪ / ' . ($en['discLifePct'] ?? '0') . '٪ / ' . ($en['discPassPct'] ?? '0') . '٪') . '</td></tr>';
        foreach (($x['losses'] ?? []) as $l)
            $h .= '<tr nobr="true"><td width="3%"></td><td colspan="13" width="97%" style="background-color:#fff1f2;font-size:6.8pt;color:#9f1239">خسارت: <b>' . $e($l['lossType'] ?? '') . '</b> · مبلغِ پرداختی ' . $e($l['paidAmount'] ?? '')
                . ' ریال · اعلام ' . $e($l['declareDate'] ?? '') . ' · پرداخت ' . $e($l['paidDate'] ?? '') . '</td></tr>';
    }
    if (!$rows) $h .= '<tr><td colspan="14" style="text-align:center;color:#94a3b8">سابقه‌ای نیست.</td></tr>';
    $h .= '</tbody></table><p style="font-size:6.5pt;color:#94a3b8">این گزارش از روی نتیجه‌ی استعلامِ سامانه‌ی سنهاب ساخته شده است' . (!empty($res['sanhab']['inquirerName']) ? ' · ' . $e($res['sanhab']['inquirerName']) : '') . '.</p>';
    $pdf->writeHTML($h, true, false, true, false, '');
    $pdf->Output($dest, 'F');
    return is_file($dest);
}
// خروجیِ یک استعلامِ سنهاب برای مرورگر (همه‌ی کاربرانِ مجاز جزئیات را می‌بینند؛ صفحه‌ی خامِ سامانه فقط مدیر کل)
function sh_out(array $r, $rows = true) {
    $o = inq_out($r, false);
    $res = json_decode((string)($r['result_json'] ?? ''), true) ?: [];
    $q = $res['q'] ?? [];
    $o['query'] = $r['query_text'] ?? '';
    $o['sh_type'] = (string)($q['type'] ?? ''); $o['sh_type_fa'] = SH_TYPES[$o['sh_type']] ?? '';
    $o['sh_plcy'] = (string)($q['plcy'] ?? '0'); $o['sh_plcy_fa'] = SH_PLCY[$o['sh_plcy']] ?? '';
    $o['sh_params'] = $q['params'] ?? null;
    $o['ref_type'] = $r['ref_type'] ?? null; $o['ref_id'] = isset($r['ref_id']) ? intval($r['ref_id']) : null;
    $o['sanhab'] = isset($res['sanhab']) ? sh_brief($res['sanhab']) : null;
    if ($rows && isset($res['sanhab'])) $o['sanhab_rows'] = $res['sanhab']['rows'] ?? [];
    return $o;
}

// سقفِ روزانه‌ی سنهاب برای هر کاربر (۰ = بدونِ سقف؛ مدیر کل سقف ندارد)
function sh_user_used_today($pdo, $type, $id) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM inq_requests WHERE kind = 'sanhab' AND status <> 'QUEUED' AND user_type = ? AND user_id = ? AND created_at >= CURDATE()");
    $st->execute([$type, intval($id)]);
    return intval($st->fetchColumn());
}

// کپچای استعلام (نوعِ جستجو هم همان‌جا انتخاب می‌شود). key = نوبتِ همین پنجره‌ی کاربر
function sh_inq_captcha($pdo, $type, $plcy, $key, $name, $reselect = false) {
    sh_ensure($pdo);
    if (!sh_enabled($pdo)) return ['ok' => false, 'error' => 'استعلامِ سنهاب در تنظیمات خاموش است.'];
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'افزونه‌ی curl روی سرور فعال نیست.'];
    $type = isset(SH_TYPES[(string)$type]) ? (string)$type : '4';
    $plcy = isset(SH_PLCY[(string)$plcy]) ? (string)$plcy : '0';
    $h = sh_hold_take($key, $name);
    if (!$h['ok']) return $h;
    if (sh_login_captcha_pending()) return sh_need_login($pdo);
    $lk = sh_lock(30);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه‌ی سنهاب مشغول است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    $need = false;
    try {
        $sh = new InqSanhab();
        $cur = $sh->currentSelection();
        $r = (!$reselect && $cur['ready'] && $cur['type'] === $type && $cur['plcy'] === $plcy) ? $sh->refreshInqCaptcha() : $sh->selectSearchType($type, $plcy, true);
        $need = ($r['error'] ?? '') === 'need_sanhab_login';
    } catch (Throwable $e) {
        $r = ['ok' => false, 'message' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)];
    } finally { inq_unlock($lk); }
    if ($need) return sh_need_login($pdo);
    if (empty($r['ok']) || empty($r['captchaImage'])) return ['ok' => false, 'error' => $r['message'] ?? 'تصویرِ امنیتیِ استعلام از سنهاب دریافت نشد؛ دوباره بزنید.'];
    sh_mark('logged_in'); sh_touch();
    return ['ok' => true, 'image' => $r['captchaImage'], 'type' => $type, 'plcy' => $plcy];
}

// اجرای یک استعلام با کپچای زده‌شده. ctx: source, ref_type, ref_id, row_id (ردیفِ گروهی)، actor
function sh_search($pdo, array $in, $key, array $ctx = []) {
    sh_ensure($pdo);
    if (!sh_enabled($pdo)) return ['ok' => false, 'error' => 'استعلامِ سنهاب در تنظیمات خاموش است.'];
    $actor = $ctx['actor'] ?? inq_actor_from_session();
    $row = null;
    if (!empty($ctx['row_id'])) {
        $st = $pdo->prepare("SELECT * FROM inq_requests WHERE id = ? AND kind = 'sanhab' AND status = 'QUEUED'");
        $st->execute([intval($ctx['row_id'])]);
        $row = $st->fetch();
        if (!$row) return ['ok' => false, 'error' => 'این ردیف دیگر در صف نیست.'];
        $q = (json_decode((string)$row['result_json'], true) ?: [])['q'] ?? [];
        $in = array_merge(['type' => $q['type'] ?? '4', 'plcy' => $q['plcy'] ?? '0'], (array)($q['params'] ?? []), ['captcha' => $in['captcha'] ?? '']);
    }
    $v = sh_validate($in);
    if (!$v['ok']) return $v;
    $cap = trim(inq_digits_keep((string)($in['captcha'] ?? '')));
    if ($cap === '') return ['ok' => false, 'error' => 'کدِ تصویرِ امنیتی را بنویسید.', 'field' => 'captcha'];
    if (in_array($actor[0], ['STAFF', 'COMPANY'], true) && !$row && ($lim = (int)inq_limit_value(inq_get_setting($pdo, 'inq_sh_daily_limit'))) > 0) {
        $li = inq_user_limit_info($pdo, $actor[0], $actor[1]);
        if (!$li['admin'] && sh_user_used_today($pdo, $actor[0], $actor[1]) >= $lim)
            return ['ok' => false, 'limit' => 'user', 'error' => 'سقفِ استعلامِ سنهابِ امروزِ شما (' . $lim . ' استعلام) پر شده است؛ برای افزایش به مدیر کل اطلاع دهید.'];
    }
    $h = sh_hold_take($key, $actor[2]);
    if (!$h['ok']) return $h;
    $lk = sh_lock(40);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه‌ی سنهاب مشغول است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    $t0 = microtime(true);
    $need = false;
    try {
        @set_time_limit(180);
        $r = (new InqSanhab())->executeInquiry($v['params'] + ['typeId' => $v['type'], 'plcyType' => $v['plcy'], 'captcha' => $cap]);
        $need = ($r['error'] ?? '') === 'need_sanhab_login';
    } catch (Throwable $e) {
        $r = ['ok' => false, 'message' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)];
    } finally { inq_unlock($lk); }
    if ($need) return sh_need_login($pdo);
    sh_mark('logged_in'); sh_touch();
    $next = $r['captchaImage'] ?? null;
    if (empty($r['ok']) && !empty($r['invalidCap'])) return ['ok' => false, 'invalid_captcha' => true, 'error' => $r['message'] ?? 'کدِ امنیتی اشتباه بود.', 'image' => $next];
    // نشستِ استعلام هنوز آماده نبود (کپچا گرفته نشده بود): ثبت نمی‌شود؛ اول کدِ امنیتی
    if (empty($r['ok']) && mb_strpos((string)($r['message'] ?? ''), 'آماده نیست') !== false) return ['ok' => false, 'need_captcha' => true, 'error' => 'اول «دریافتِ کدِ امنیتی» را بزنید.', 'image' => $next];

    $ms = (int)round((microtime(true) - $t0) * 1000);
    if (!empty($r['ok'])) {
        unset($r['captchaImage']);
        $b = sh_brief($r);
        $status = 'OK'; $notice = $b['summary_text'] ?: $b['loss_status'];
        $person = $b['insured'] !== '' ? mb_substr($b['insured'], 0, 190) : null;
        $res = ['q' => ['type' => $v['type'], 'plcy' => $v['plcy'], 'params' => $v['params']], 'sanhab' => $r];
    } else {
        $msg = (string)($r['message'] ?? 'نتیجه‌ای دریافت نشد.');
        $status = mb_strpos($msg, 'یافت نشد') !== false ? 'WARN' : 'FAIL';
        $notice = $msg; $person = null;
        $res = ['q' => ['type' => $v['type'], 'plcy' => $v['plcy'], 'params' => $v['params']], 'sanhab' => ['rows' => [], 'latestSummary' => ['lossStatus' => $msg]], 'not_found' => $status === 'WARN'];
    }
    $json = json_encode($res, JSON_UNESCAPED_UNICODE);
    if ($row) {
        $pdo->prepare("UPDATE inq_requests SET status = ?, notice = ?, person_name = ?, result_json = ?, started_at = NOW(), finished_at = NOW(), ms = ?, user_type = ?, user_id = ?, user_name = ? WHERE id = ?")
            ->execute([$status, mb_substr($notice, 0, 600), $person, $json, $ms, $actor[0], $actor[1] ?: null, mb_substr((string)$actor[2], 0, 190), intval($row['id'])]);
        $id = intval($row['id']);
        $pdo->exec("UPDATE inq_batches b SET status = 'DONE', finished_at = NOW() WHERE id = " . intval($row['batch_id']) . " AND status = 'RUNNING' AND NOT EXISTS (SELECT 1 FROM inq_requests r WHERE r.batch_id = b.id AND r.status IN ('QUEUED', 'RUNNING'))");
    } else {
        $refType = isset($ctx['ref_type']) && preg_match('/^[a-z_]{1,16}$/', (string)$ctx['ref_type']) ? (string)$ctx['ref_type'] : null;
        $pdo->prepare("INSERT INTO inq_requests (kind, national_id, status, notice, person_name, result_json, source, user_type, user_id, user_name, created_at, started_at, finished_at, ms, query_text, ref_type, ref_id)
                       VALUES ('sanhab', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), ?, ?, ?, ?)")
            ->execute([$v['nid'], $status, mb_substr($notice, 0, 600), $person, $json, in_array($ctx['source'] ?? '', ['form', 'panel', 'premium'], true) ? $ctx['source'] : 'panel',
                       $actor[0], $actor[1] ?: null, mb_substr((string)$actor[2], 0, 190), $ms, $v['label'], $refType, $refType ? intval($ctx['ref_id'] ?? 0) : null]);
        $id = intval($pdo->lastInsertId());
    }
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE id = ?");
    $st->execute([$id]);
    return ['ok' => true, 'item' => sh_out($st->fetch()), 'image' => $next];
}

// آخرین نتیجه‌ی سنهاب برای یک ردیف (ref) یا همان پلاک/شاسی/... (برای نمایش کنارِ دکمه، بدونِ استعلامِ دوباره)
function sh_last($pdo, $refType, $refId, array $cands = []) {
    sh_ensure($pdo);
    $labels = [];
    foreach ($cands as $c) { $v = sh_validate((array)$c); if ($v['ok']) $labels[] = $v['label']; }
    $w = []; $a = [];
    if ($refType && $refId) { $w[] = '(ref_type = ? AND ref_id = ?)'; $a[] = (string)$refType; $a[] = intval($refId); }
    if ($labels) { $w[] = 'query_text IN (' . implode(',', array_fill(0, count($labels), '?')) . ')'; $a = array_merge($a, $labels); }
    if (!$w) return null;
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE kind = 'sanhab' AND status IN ('OK', 'WARN') AND (" . implode(' OR ', $w) . ") ORDER BY id DESC LIMIT 1");
    $st->execute($a);
    $r = $st->fetch();
    return $r ? sh_out($r) : null;
}

// فایلِ PDFِ بیمه‌نامه از سنهاب (pu = کدِ یکتای بیمه‌نامه‌ها، با کاما)
function sh_pdf($pdo, $typ, $pu) {
    $lk = sh_lock(30);
    if (!$lk) return null;
    try { return (new InqSanhab())->streamPdf($typ === '2' ? '2' : '1', preg_replace('/[^0-9A-Za-z,\-]/', '', (string)$pu)); }
    finally { inq_unlock($lk); }
}

// ---- استعلامِ گروهی: ردیف‌ها در صف می‌مانند و کاربر برای هر ردیف کپچا می‌زند (کپچای بعدی خودکار می‌آید) ----
function sh_batch_create($pdo, $type, $plcy, $title, array $rows, $fileName = '') {
    sh_ensure($pdo);
    $actor = inq_actor_from_session();
    $pdo->prepare("INSERT INTO inq_batches (title, kind, file_name, total, status, user_id, user_name, created_at) VALUES (?, 'sanhab', ?, 0, 'RUNNING', ?, ?, NOW())")
        ->execute([mb_substr($title ?: 'استعلامِ گروهیِ سنهاب', 0, 190), mb_substr($fileName, 0, 255), $actor[1] ?: null, mb_substr($actor[2], 0, 190)]);
    $bid = intval($pdo->lastInsertId());
    $ok = 0; $bad = [];
    $ins = $pdo->prepare("INSERT INTO inq_requests (kind, national_id, status, result_json, source, batch_id, row_no, user_type, user_id, user_name, created_at, query_text)
                          VALUES ('sanhab', ?, 'QUEUED', ?, 'group', ?, ?, ?, ?, ?, NOW(), ?)");
    foreach (array_values($rows) as $i => $r) {
        $v = sh_validate(['type' => $type, 'plcy' => $plcy] + (array)$r);
        if (!$v['ok']) { $bad[] = ['row' => $i + 1, 'error' => $v['error']]; continue; }
        $ins->execute([$v['nid'], json_encode(['q' => ['type' => $v['type'], 'plcy' => $v['plcy'], 'params' => $v['params']]], JSON_UNESCAPED_UNICODE), $bid, $i + 1,
                       $actor[0], $actor[1] ?: null, mb_substr($actor[2], 0, 190), $v['label']]);
        $ok++;
    }
    $pdo->prepare("UPDATE inq_batches SET total = ? WHERE id = ?")->execute([$ok, $bid]);
    if (!$ok) $pdo->prepare("UPDATE inq_batches SET status = 'DONE', finished_at = NOW() WHERE id = ?")->execute([$bid]);
    return ['ok' => true, 'batch_id' => $bid, 'queued' => $ok, 'invalid' => $bad];
}
function sh_batch_next($pdo, $bid) {
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE batch_id = ? AND kind = 'sanhab' AND status = 'QUEUED' ORDER BY row_no, id LIMIT 1");
    $st->execute([intval($bid)]);
    $r = $st->fetch();
    return $r ? sh_out($r, false) : null;
}
// ستون‌های اکسل => ورودی‌ها (پلاک: ۴ ستون «ایران، سه‌رقمی، حرف، دورقمی» یا یک ستونِ کامل؛ بقیه: ستونِ اول؛ موتور و شاسی: دو ستون)
function sh_excel_rows($path, $type) {
    if (!function_exists('fin_read_spreadsheet')) require_once __DIR__ . '/finance_core.php';
    $t = fin_read_spreadsheet($path);
    if (!$t) return ['ok' => false, 'error' => 'فایل خوانده نشد (xlsx یا csv).'];
    $t = array_values($t);
    $first = implode(' ', array_map('strval', (array)($t[0] ?? [])));
    $start = preg_match('/ایران|پلاک|حرف|شماره|ملی|بیمه|مقدار|VIN|شاسی|موتور/ui', $first) ? 1 : 0;
    $out = [];
    foreach (array_slice($t, $start) as $row) {
        $row = array_values(array_map(function ($x) { return trim((string)$x); }, (array)$row));
        if (!array_filter($row, 'strlen')) continue;
        if ($type === '4') {
            if (count($row) >= 4 && ($row[1] ?? '') !== '' && ($row[2] ?? '') !== '') $r = ['plk1' => $row[0], 'plk2' => $row[1], 'plk3' => $row[2], 'plk4' => $row[3]];
            else { $pp = sh_parse_plate($row[0] ?? ''); if (!$pp) continue; $r = $pp; }
        } elseif ($type === '8' || $type === '45') {
            $r = ($row[1] ?? '') !== '' ? ['plk2m' => $row[0], 'plk1m' => $row[1]] : ['plate' => $row[0]];
        } elseif ($type === '6') {
            $r = ['txtInput' => $row[0] ?? '', 'txtInput2' => $row[1] ?? ''];
        } else {
            if (($row[0] ?? '') === '') continue;
            $r = ['txtInput' => $row[0]];
        }
        $out[] = $r;
        if (count($out) >= 1000) break;
    }
    return $out ? ['ok' => true, 'rows' => $out] : ['ok' => false, 'error' => 'هیچ ردیفی پیدا نشد.'];
}

// صفحه‌ی آخرِ سامانه (فقط مدیر کل؛ در iframeِ sandbox)
function sh_live_html() { return (new InqSanhab())->getLiveHtml(); }

// =====================================================================
//  موتورِ سامانه‌ی سنهاب (همان SanhabRunnerِ ابزار؛ فقط محلِ ذخیره‌ی نشست عوض شده)
// =====================================================================
class InqSanhab
{
    private $ssoBase  = 'https://sanhabssoi.centinsur.ir';
    private $netBase  = 'https://sanhabnet.centinsur.ir';
    private $loginUrl = 'https://sanhabssoi.centinsur.ir/account/login?fromsite=http%3a%2f%2fsanhabnet.centinsur.ir&returnUrl=%2finq%2fpolicyinq.aspx';
    private $postUrl  = 'https://sanhabssoi.centinsur.ir/account';
    private $inqUrl   = 'https://sanhabnet.centinsur.ir/inq/policyinq.aspx';
    private $cookieFile;
    private $stateFile;
    private $previewFile;

    public function __construct()
    {
        $dir = inq_dir('sanhab');
        $this->cookieFile  = $dir . '/session.cookies';
        $this->stateFile   = $dir . '/state.json';
        $this->previewFile = $dir . '/live_page.html';
    }

    private function saveState($data)
    {
        $old = $this->loadState();
        $merged = array_merge($old, $data);
        @file_put_contents($this->stateFile, json_encode($merged, JSON_UNESCAPED_UNICODE));
    }

    private function loadState()
    {
        if (!is_file($this->stateFile)) return array();
        $raw = @file_get_contents($this->stateFile);
        $d = $raw ? json_decode($raw, true) : null;
        return is_array($d) ? $d : array();
    }

    // نوعِ جستجوی انتخاب‌شده‌ی فعلیِ نشست (برای این‌که برای کپچای تازه، نوع دوباره انتخاب نشود)
    public function currentSelection()
    {
        $s = $this->loadState();
        return array('type' => isset($s['selectedType']) ? (string)$s['selectedType'] : '', 'plcy' => isset($s['plcyType']) ? (string)$s['plcyType'] : '0',
                     'ready' => !empty($s['inqFields']['__VIEWSTATE']));
    }

    public function getLiveHtml()
    {
        if (!is_file($this->previewFile)) {
            return '<h3 style="font-family:tahoma;direction:rtl">هنوز صفحه‌ای از سنهاب ذخیره نشده است.</h3>';
        }
        $html = (string)@file_get_contents($this->previewFile);
        $baseHref = strpos($html, 'LoginCaptcha') !== false ? 'https://sanhabssoi.centinsur.ir/' : 'https://sanhabnet.centinsur.ir/inq/';
        if (stripos($html, '<head>') !== false) {
            $html = preg_replace('/<head>/i', '<head><base href="' . $baseHref . '">', $html, 1);
        } else {
            $html = '<!doctype html><html dir="rtl"><head><meta charset="utf-8"><base href="' . $baseHref . '"></head><body>' . $html . '</body></html>';
        }
        return $html;
    }

    private function http($method, $url, $fields = array(), $referer = '', $urlEncoded = true, $extraHeaders = array())
    {
        $ch = curl_init($url);
        $headers = array_merge(array(
            'Accept: */*',
            'Accept-Language: en-GB,en;q=0.9,fa-IR;q=0.8,fa;q=0.7',
            'Cache-Control: max-age=0',
            'Connection: keep-alive',
        ), $extraHeaders);

        if ($method === 'POST') {
            $parsed = parse_url($url);
            if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                $headers[] = 'Origin: ' . $parsed['scheme'] . '://' . $parsed['host'];
            }
            if ($urlEncoded) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
            }
        }

        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_TIMEOUT        => 55,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        );

        if ($referer !== '') {
            $opts[CURLOPT_REFERER] = $referer;
        }

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $urlEncoded ? http_build_query($fields) : $fields;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $res = array(
            'body'        => $body === false ? '' : (string)$body,
            'code'        => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'url'         => $effectiveUrl,
            'contentType' => $contentType,
            'error'       => curl_error($ch),
        );
        curl_close($ch);
        @chmod($this->cookieFile, 0600);
        return $res;
    }

    public function extractAllInputs($html)
    {
        $f = array();
        if (!preg_match_all('/<input\b([^>]*)>/is', $html, $matches)) {
            return $f;
        }
        foreach ($matches[1] as $attrs) {
            if (preg_match('/\bdisabled\b/i', $attrs)) continue;
            if (!preg_match('/\bname=["\']([^"\']+)["\']/i', $attrs, $nm)) continue;
            $name = html_entity_decode($nm[1], ENT_QUOTES, 'UTF-8');
            $type = preg_match('/\btype=["\']([^"\']+)["\']/i', $attrs, $tm) ? strtolower($tm[1]) : 'text';
            if (in_array($type, array('submit', 'button', 'image', 'reset', 'file'), true)) continue;
            if (in_array($type, array('checkbox', 'radio'), true) && !preg_match('/\bchecked\b/i', $attrs)) continue;
            $val = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $attrs, $vm) ? html_entity_decode($vm[1], ENT_QUOTES, 'UTF-8') : '';
            $f[$name] = $val;
        }
        return $f;
    }

    private function isLoginPage($html, $url = '')
    {
        if ($html === '') return true;
        if (strpos($html, 'LoginCaptcha') !== false) return true;
        if (strpos($html, 'name="CaptchaCode"') !== false && strpos($html, 'name="Password"') !== false) return true;
        if ($url !== '' && stripos($url, '/account/login') !== false) return true;
        return false;
    }

    private function followSsoRedirectFormIfNeeded($res)
    {
        $html = $res['body'];
        $curUrl = $res['url'];
        for ($i = 0; $i < 3; $i++) {
            if ($this->isLoginPage($html, $curUrl)) break;
            if (preg_match('/<form\b[^>]*\baction=["\']([^"\']+)["\'][^>]*>(.*?)<\/form>/is', $html, $fm)) {
                $action = html_entity_decode($fm[1], ENT_QUOTES, 'UTF-8');
                $formInner = $fm[2];
                if (stripos($curUrl, 'policyinq.aspx') !== false or stripos($action, 'policyinq.aspx') !== false) {
                    if (strpos($formInner, '__VIEWSTATE') !== false) break;
                }
                if (strpos($html, 'document.forms[0].submit') !== false or stripos($action, 'sanhabnet') !== false or stripos($action, 'signin') !== false) {
                    if (strpos($action, 'http') !== 0) {
                        $p = parse_url($curUrl);
                        $action = $p['scheme'] . '://' . $p['host'] . (strpos($action, '/') === 0 ? '' : '/') . $action;
                    }
                    $inputs = $this->extractAllInputs($formInner);
                    $nextRes = $this->http('POST', $action, $inputs, $curUrl, true);
                    $html = $nextRes['body'];
                    $curUrl = $nextRes['url'];
                    $res = $nextRes;
                    continue;
                }
            }
            break;
        }
        return $res;
    }

    private function parseCallbackResponse($rawBody, &$storedFields)
    {
        $pipe = chr(124);
        $data = $rawBody;
        if (preg_match('/^(\d+)' . preg_quote($pipe, '/') . '/s', $rawBody, $m)) {
            $len = (int)$m[1];
            $sepPos = strpos($rawBody, $pipe);
            if ($sepPos !== false && strlen($rawBody) >= $sepPos + 1 + $len) {
                $ev = substr($rawBody, $sepPos + 1, $len);
                if ($ev !== '' && is_array($storedFields)) {
                    $storedFields['__EVENTVALIDATION'] = $ev;
                }
                $data = substr($rawBody, $sepPos + 1 + $len);
            }
        } elseif (strpos($rawBody, 's') === 0) {
            $data = substr($rawBody, 1);
        }

        if (strpos($data, 'DX') !== false or strpos($data, '\'result\'') !== false) {
            $data = str_replace(
                array('\\\'', '\\"', '\\/', '\\r', '\\n', '\\t'),
                array("'", '"', '/', "\r", "\n", "\t"),
                $data
            );
        }
        return $data;
    }

    private function ensureInqPageLoaded($forceGet = false)
    {
        $state = $this->loadState();
        if (!$forceGet && !empty($state['inqFields']['__VIEWSTATE'])) {
            return array(
                'ok'     => true,
                'fields' => $state['inqFields'],
                'html'   => is_file($this->previewFile) ? (string)@file_get_contents($this->previewFile) : '',
            );
        }

        $r = $this->http('GET', $this->inqUrl);
        $r = $this->followSsoRedirectFormIfNeeded($r);
        if ($this->isLoginPage($r['body'], $r['url'])) {
            return array('ok' => false, 'error' => 'need_sanhab_login');
        }
        @file_put_contents($this->previewFile, $r['body']);
        $fields = $this->extractAllInputs($r['body']);
        $this->saveState(array(
            'inqFields'  => $fields,
            'loggedInAt' => time(),
        ));
        return array('ok' => true, 'fields' => $fields, 'html' => $r['body']);
    }

    public function status()
    {
        if (!is_file($this->cookieFile)) return 'logged_out';
        $state = $this->loadState();
        if (!empty($state['inqFields']['__VIEWSTATE']) && !empty($state['loggedInAt']) && (time() - (int)$state['loggedInAt'] < 1200)) {
            return 'logged_in';
        }
        $r = $this->http('GET', $this->inqUrl);
        $r = $this->followSsoRedirectFormIfNeeded($r);
        if ($r['body'] !== '') {
            @file_put_contents($this->previewFile, $r['body']);
            if (!$this->isLoginPage($r['body'], $r['url'])) {
                $this->saveState(array(
                    'inqFields'  => $this->extractAllInputs($r['body']),
                    'loggedInAt' => time(),
                ));
                return 'logged_in';
            }
        }
        return 'logged_out';
    }

    public function getLoginCaptcha()
    {
        $r = $this->http('GET', $this->loginUrl);
        if ($r['body'] === '') {
            return array('ok' => false, 'message' => 'عدم ارتباط با سرور سنهاب (' . $r['error'] . ')');
        }
        @file_put_contents($this->previewFile, $r['body']);

        if (!$this->isLoginPage($r['body'], $r['url'])) {
            $this->saveState(array(
                'inqFields'  => $this->extractAllInputs($r['body']),
                'loggedInAt' => time(),
            ));
            return array('ok' => true, 'loggedIn' => true);
        }

        $inputs = $this->extractAllInputs($r['body']);
        $captchaSrc = '';
        if (preg_match('/<img\b[^>]*\bid=["\']LoginCaptcha_CaptchaImage["\'][^>]*\bsrc=["\']([^"\']+)["\']/is', $r['body'], $cm)) {
            $captchaSrc = html_entity_decode($cm[1], ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<img\b[^>]*\bsrc=["\']([^"\']*BotDetectCaptcha\.ashx[^"\']+)["\']/is', $r['body'], $cm)) {
            $captchaSrc = html_entity_decode($cm[1], ENT_QUOTES, 'UTF-8');
        }
        if ($captchaSrc === '' && !empty($inputs['BDC_VCID_LoginCaptcha'])) {
            $captchaSrc = '/BotDetectCaptcha.ashx?get=image&c=LoginCaptcha&t=' . urlencode($inputs['BDC_VCID_LoginCaptcha']);
        }

        $base64Img = '';
        if ($captchaSrc !== '') {
            $fullCapUrl = strpos($captchaSrc, 'http') === 0
                ? $captchaSrc
                : $this->ssoBase . (strpos($captchaSrc, '/') === 0 ? '' : '/') . $captchaSrc;

            $capRes = $this->http('GET', $fullCapUrl, array(), $this->loginUrl, true, array(
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            ));
            if ($capRes['code'] === 200 && $capRes['body'] !== '') {
                $mime = 'image/jpeg';
                if (stripos($capRes['contentType'], 'png') !== false) $mime = 'image/png';
                elseif (stripos($capRes['contentType'], 'gif') !== false) $mime = 'image/gif';
                $base64Img = 'data:' . $mime . ';base64,' . base64_encode($capRes['body']);
            }
        }

        $this->saveState(array(
            'loginFields'  => $inputs,
            'loginReferer' => $r['url'] ? $r['url'] : $this->loginUrl,
            'updatedAt'    => time(),
        ));

        return array(
            'ok'       => true,
            'loggedIn' => false,
            'image'    => $base64Img,
        );
    }

    public function login($username, $password, $captcha)
    {
        $state = $this->loadState();
        $fields = isset($state['loginFields']) && is_array($state['loginFields']) ? $state['loginFields'] : array();
        $referer = !empty($state['loginReferer']) ? $state['loginReferer'] : $this->loginUrl;

        if (empty($fields['__RequestVerificationToken'])) {
            return array('ok' => false, 'message' => 'توکن صفحه ورود منقضی شده است؛ لطفاً کپچای جدید را وارد کنید');
        }

        $postFields = $fields;
        if (empty($postFields['FromSite'])) $postFields['FromSite'] = 'http://sanhabnet.centinsur.ir';
        if (empty($postFields['ReturnUrl'])) $postFields['ReturnUrl'] = '/inq/policyinq.aspx';
        if (empty($postFields['IsAgent'])) $postFields['IsAgent'] = 'False';
        if (empty($postFields['ComeFromLogOff'])) $postFields['ComeFromLogOff'] = 'False';

        $postFields['UserName']    = trim((string)$username);
        $postFields['Password']    = (string)$password;
        $postFields['CaptchaCode'] = strtoupper(trim((string)$captcha));

        $r = $this->http('POST', $this->postUrl, $postFields, $referer, true);
        $r = $this->followSsoRedirectFormIfNeeded($r);

        if ($this->isLoginPage($r['body'], $r['url'])) {
            $errMsg = 'نام کاربری، رمز عبور یا کد امنیتی (کپچا) اشتباه است';
            if (preg_match('/class=["\'][^"\']*validation-summary-errors[^"\']*["\'][^>]*>(.*?)<\/div>/is', $r['body'], $em)) {
                $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($em[1])));
                if ($clean !== '') $errMsg = $clean;
            } elseif (preg_match('/class=["\'][^"\']*field-validation-error[^"\']*["\'][^>]*>(.*?)<\//is', $r['body'], $fm)) {
                $clean = trim(strip_tags($fm[1]));
                if ($clean !== '') $errMsg = $clean;
            }
            return array('ok' => false, 'message' => $errMsg);
        }

        $rInq = $this->http('GET', $this->inqUrl, array(), $r['url']);
        $rInq = $this->followSsoRedirectFormIfNeeded($rInq);
        if ($rInq['body'] !== '') {
            @file_put_contents($this->previewFile, $rInq['body']);
            $this->saveState(array(
                'inqFields'  => $this->extractAllInputs($rInq['body']),
                'loggedInAt' => time(),
            ));
        }
        if ($this->isLoginPage($rInq['body'], $rInq['url'])) {
            return array('ok' => false, 'message' => 'ورود انجام نشد؛ لطفاً مجدداً تلاش کنید');
        }

        return array(
            'ok'      => true,
            'message' => 'ورود به سامانه سنهاب با موفقیت انجام شد',
        );
    }

    private function fetchInqCaptchaBase64($decodedText)
    {
        $src = '';
        if (preg_match('/(\/DXB\.axd\?DXCache=[a-zA-Z0-9\-_]+)/i', $decodedText, $m)) {
            $src = $m[1];
        } elseif (preg_match('/(DXB\.axd\?DXCache=[a-zA-Z0-9\-_]+)/i', $decodedText, $m)) {
            $src = '/' . $m[1];
        } elseif (preg_match('/[\'"]result[\'"]\s*:\s*[\'"]([a-zA-Z0-9\-_\/.?=&]+)[\'"]/i', $decodedText, $m)) {
            $resVal = trim($m[1]);
            if ($resVal !== '') {
                if (stripos($resVal, 'DXB.axd') !== false) {
                    $src = strpos($resVal, '/') === 0 ? $resVal : '/' . $resVal;
                } else {
                    $src = '/DXB.axd?DXCache=' . $resVal;
                }
            }
        }

        if ($src === '') return '';

        $fullUrl = strpos($src, 'http') === 0
            ? $src
            : $this->netBase . (strpos($src, '/') === 0 ? '' : '/') . $src;

        $capRes = $this->http('GET', $fullUrl, array(), $this->inqUrl, true, array(
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ));
        if ($capRes['code'] === 200 && $capRes['body'] !== '') {
            $mime = 'image/png';
            if (stripos($capRes['contentType'], 'jpeg') !== false or stripos($capRes['contentType'], 'jpg') !== false) {
                $mime = 'image/jpeg';
            }
            return 'data:' . $mime . ';base64,' . base64_encode($capRes['body']);
        }
        return '';
    }

    private function getTypeLabel($typeId)
    {
        $map = array(
            '7'  => 'شماره بیمه نامه',
            '3'  => 'کد ملی',
            '1'  => 'شماره موتور',
            '2'  => 'شماره شاسی',
            '4'  => 'پلاک ملی-غیر موتورسیکلت',
            '8'  => 'پلاک ملی-موتورسیکلت',
            '45' => 'پلاک غیرملی',
            '5'  => 'VIN',
            '6'  => 'شماره موتور و شاسی',
        );
        return isset($map[(string)$typeId]) ? $map[(string)$typeId] : 'شماره بیمه نامه';
    }

    private function cleanTypeSpecificFields(&$f, $typeId)
    {
        $typeStr = (string)$typeId;
        if ($typeStr === '4') {
            unset(
                $f['ctl00$Main$policyinq$CallbackPanel1$txtInput'],
                $f['ctl00_Main_policyinq_CallbackPanel1_plk1m_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk1m'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk1m$CVS'],
                $f['ctl00_Main_policyinq_CallbackPanel1_plk2m_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk2m'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk2m$CVS']
            );
        } elseif ($typeStr === '8' or $typeStr === '45') {
            unset(
                $f['ctl00$Main$policyinq$CallbackPanel1$txtInput'],
                $f['ctl00_Main_policyinq_CallbackPanel1_Plk1_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk1'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk1$CVS'],
                $f['ctl00_Main_policyinq_CallbackPanel1_Plk2_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk2'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk2$CVS'],
                $f['ctl00_Main_policyinq_CallbackPanel1_plk3_VI'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk3'],
                $f['ctl00_Main_policyinq_CallbackPanel1_plk3_DDDWS'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk3$DDD$L'],
                $f['ctl00_Main_policyinq_CallbackPanel1_Plk4_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk4'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk4$CVS']
            );
        } else {
            unset(
                $f['ctl00_Main_policyinq_CallbackPanel1_Plk1_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk1'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk1$CVS'],
                $f['ctl00_Main_policyinq_CallbackPanel1_Plk2_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk2'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk2$CVS'],
                $f['ctl00_Main_policyinq_CallbackPanel1_plk3_VI'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk3'],
                $f['ctl00_Main_policyinq_CallbackPanel1_plk3_DDDWS'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk3$DDD$L'],
                $f['ctl00_Main_policyinq_CallbackPanel1_Plk4_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk4'],
                $f['ctl00$Main$policyinq$CallbackPanel1$Plk4$CVS'],
                $f['ctl00_Main_policyinq_CallbackPanel1_plk1m_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk1m'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk1m$CVS'],
                $f['ctl00_Main_policyinq_CallbackPanel1_plk2m_Raw'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk2m'],
                $f['ctl00$Main$policyinq$CallbackPanel1$plk2m$CVS']
            );
        }
    }

    private function buildBaseCallbackFields($storedFields, $typeId, $plcyType = '0')
    {
        $typeStr = (string)$typeId;
        $typeLabel = $this->getTypeLabel($typeStr);
        $f = $storedFields;

        $f['__EVENTTARGET']   = '';
        $f['__EVENTARGUMENT'] = '';
        $f['ctl00$Main$policyinq$CallbackPanel1$plcytype']     = (string)$plcyType;
        $f['ctl00$Main$policyinq$CallbackPanel1$plcytype$RB0'] = $plcyType === '0' ? 'C' : 'U';
        $f['ctl00$Main$policyinq$CallbackPanel1$plcytype$RB1'] = $plcyType === '1' ? 'C' : 'U';

        $f['ctl00_Main_policyinq_CallbackPanel1_cmbTyp_VI']     = $typeStr;
        $f['ctl00$Main$policyinq$CallbackPanel1$cmbTyp']        = $typeLabel;
        $f['ctl00_Main_policyinq_CallbackPanel1_cmbTyp_DDDWS']  = '0:0:-1:-10000:-10000:0:-10000:-10000:1:0:0:0';
        $f['ctl00_Main_policyinq_CallbackPanel1_cmbTyp_DDD_LDeletedItems']  = '';
        $f['ctl00_Main_policyinq_CallbackPanel1_cmbTyp_DDD_LInsertedItems'] = '';
        $f['ctl00_Main_policyinq_CallbackPanel1_cmbTyp_DDD_LCustomCallback']= '';
        $f['ctl00$Main$policyinq$CallbackPanel1$cmbTyp$DDD$L']  = $typeStr;

        $f['ctl00_Main_policyinq_CallbackPanel1_cmbFld_VI']     = '-1';
        $f['ctl00$Main$policyinq$CallbackPanel1$cmbFld']        = 'همه موارد';
        $f['ctl00_Main_policyinq_CallbackPanel1_cmbFld_DDDWS']  = '0:0:-1:-10000:-10000:0:-10000:-10000:1:0:0:0';
        $f['ctl00$Main$policyinq$CallbackPanel1$cmbFld$DDD$L']  = '-1';

        $f['ctl00_Main_policyinq_CallbackPanel1_cmbCmpCod_VI']    = '-1';
        $f['ctl00$Main$policyinq$CallbackPanel1$cmbCmpCod']       = ' همه موارد ';
        $f['ctl00_Main_policyinq_CallbackPanel1_cmbCmpCod_DDDWS'] = '0:0:-1:-10000:-10000:0:-10000:-10000:1:0:0:0';
        $f['ctl00$Main$policyinq$CallbackPanel1$cmbCmpCod$DDD$L'] = '-1';

        if (!isset($f['DXScript'])) {
            $f['DXScript'] = '1_157,1_89,1_149,1_88,1_108,1_110,1_115,1_107,1_86,1_141,1_139,1_102,1_100,1_99,1_125,1_111';
        }
        return $f;
    }

    public function selectSearchType($typeId, $plcyType = '0', $waitTwoSec = true)
    {
        $init = $this->ensureInqPageLoaded(false);
        if (empty($init['ok'])) return $init;

        $baseFields = $init['fields'];
        $f = $this->buildBaseCallbackFields($baseFields, $typeId, $plcyType);
        $evBefore = isset($f['__EVENTVALIDATION']) ? $f['__EVENTVALIDATION'] : '';
        unset($f['__EVENTVALIDATION']);
        $f['__CALLBACKID']      = 'ctl00$Main$policyinq$CallbackPanel1';
        $f['__CALLBACKPARAM']   = 'c0:cmp';
        $f['__EVENTVALIDATION'] = $evBefore;

        $r = $this->http('POST', $this->inqUrl, $f, $this->inqUrl, true);
        if ($this->isLoginPage($r['body'], $r['url'])) {
            $initRetry = $this->ensureInqPageLoaded(true);
            if (empty($initRetry['ok'])) return $initRetry;
            $baseFields = $initRetry['fields'];
            $f = $this->buildBaseCallbackFields($baseFields, $typeId, $plcyType);
            $evBefore = isset($f['__EVENTVALIDATION']) ? $f['__EVENTVALIDATION'] : '';
            unset($f['__EVENTVALIDATION']);
            $f['__CALLBACKID']      = 'ctl00$Main$policyinq$CallbackPanel1';
            $f['__CALLBACKPARAM']   = 'c0:cmp';
            $f['__EVENTVALIDATION'] = $evBefore;
            $r = $this->http('POST', $this->inqUrl, $f, $this->inqUrl, true);
            if ($this->isLoginPage($r['body'], $r['url'])) {
                return array('ok' => false, 'error' => 'need_sanhab_login');
            }
        }

        $decodedHtml = $this->parseCallbackResponse($r['body'], $baseFields);
        @file_put_contents($this->previewFile, $decodedHtml);

        $newInputs = $this->extractAllInputs($decodedHtml);
        $mergedFields = array_merge($baseFields, $newInputs);
        if (!empty($baseFields['__VIEWSTATE'])) {
            $mergedFields['__VIEWSTATE'] = $baseFields['__VIEWSTATE'];
        }
        if (!empty($baseFields['__EVENTVALIDATION'])) {
            $mergedFields['__EVENTVALIDATION'] = $baseFields['__EVENTVALIDATION'];
        }
        $mergedFields['ctl00_Main_policyinq_CallbackPanel1_cmbTyp_VI']    = (string)$typeId;
        $mergedFields['ctl00$Main$policyinq$CallbackPanel1$cmbTyp']       = $this->getTypeLabel($typeId);
        $mergedFields['ctl00$Main$policyinq$CallbackPanel1$cmbTyp$DDD$L'] = (string)$typeId;
        $this->cleanTypeSpecificFields($mergedFields, $typeId);

        if ($waitTwoSec) {
            sleep(2);
        }

        $capImg = $this->fetchInqCaptchaBase64($decodedHtml);
        if ($capImg === '') {
            $capImg = $this->fetchInqCaptchaBase64($init['html']);
        }

        $this->saveState(array(
            'inqFields'    => $mergedFields,
            'selectedType' => (string)$typeId,
            'plcyType'     => (string)$plcyType,
            'loggedInAt'   => time(),
        ));

        return array(
            'ok'           => true,
            'selectedType' => (string)$typeId,
            'typeLabel'    => $this->getTypeLabel($typeId),
            'captchaImage' => $capImg,
        );
    }

    public function refreshInqCaptcha()
    {
        $state = $this->loadState();
        $typeId   = isset($state['selectedType']) ? (string)$state['selectedType'] : '4';
        $plcyType = isset($state['plcyType']) ? (string)$state['plcyType'] : '0';

        if (empty($state['inqFields']['__VIEWSTATE'])) {
            return $this->selectSearchType($typeId, $plcyType, false);
        }

        $baseFields = $state['inqFields'];
        $f = $this->buildBaseCallbackFields($baseFields, $typeId, $plcyType);
        $this->cleanTypeSpecificFields($f, $typeId);

        $evBefore = isset($f['__EVENTVALIDATION']) ? $f['__EVENTVALIDATION'] : '';
        unset($f['__EVENTVALIDATION']);
        $f['__CALLBACKID']      = 'ctl00$Main$policyinq$CallbackPanel1$Captcha1';
        $f['__CALLBACKPARAM']   = 'c0:R';
        $f['__EVENTVALIDATION'] = $evBefore;

        $r = $this->http('POST', $this->inqUrl, $f, $this->inqUrl, true);
        if ($this->isLoginPage($r['body'], $r['url'])) {
            return array('ok' => false, 'error' => 'need_sanhab_login');
        }

        $decoded = $this->parseCallbackResponse($r['body'], $baseFields);
        if (!empty($baseFields['__EVENTVALIDATION'])) {
            $state['inqFields']['__EVENTVALIDATION'] = $baseFields['__EVENTVALIDATION'];
            $state['loggedInAt'] = time();
            $this->saveState($state);
        }

        $capImg = $this->fetchInqCaptchaBase64($decoded);
        if ($capImg === '') {
            return $this->selectSearchType($typeId, $plcyType, false);
        }

        return array('ok' => true, 'captchaImage' => $capImg);
    }

    // استخراج جداول الحاقیه (#b3ff91) و خسارت (#fe8a8a) از داخل ردیف پیش‌نمایش DXPRow{i}
    private function parsePreviewRowDetails($decodedHtml, $rowIndex)
    {
        $endorsements = array();
        $losses = array();

        $startId = 'ctl00_Main_policyinq_CallbackPanel1_gv_pr' . (int)$rowIndex . '_divEDRS';
        $pos = strpos($decodedHtml, $startId);
        if ($pos === false) {
            return array('endorsements' => $endorsements, 'losses' => $losses);
        }

        // برش تا شروع ردیف بعدی جدول اصلی یا انتهای جدول اصلی
        $nextDataRowPos = strpos($decodedHtml, 'ctl00_Main_policyinq_CallbackPanel1_gv_DXDataRow', $pos);
        $endGridPos     = strpos($decodedHtml, 'ctl00_Main_policyinq_CallbackPanel1_gv_IADD', $pos);
        $endPos = strlen($decodedHtml);
        if ($nextDataRowPos !== false && $nextDataRowPos < $endPos) $endPos = $nextDataRowPos;
        if ($endGridPos !== false && $endGridPos < $endPos) $endPos = $endGridPos;

        $slice = substr($decodedHtml, $pos, $endPos - $pos);
        if (strpos($slice, 'display: none') !== false && strpos($slice, '<table') === false) {
            return array('endorsements' => $endorsements, 'losses' => $losses);
        }

        if (!preg_match_all('/<table\b[^>]*class=["\'][^"\']*tb[^"\']*["\'][^>]*>(.*?)<\/table>/is', $slice, $tblMatches)) {
            return array('endorsements' => $endorsements, 'losses' => $losses);
        }

        foreach ($tblMatches[1] as $tblInner) {
            if (!preg_match_all('/<tr\b([^>]*)>(.*?)<\/tr>/is', $tblInner, $trMatches, PREG_SET_ORDER)) {
                continue;
            }
            if (count($trMatches) < 2) continue;

            $headerHtml = $trMatches[0][1] . ' ' . $trMatches[0][2];
            $isEndorsement = (stripos($headerHtml, 'b3ff91') !== false or strpos($headerHtml, 'نوع الحاقیه') !== false);
            $isLoss        = (stripos($headerHtml, 'fe8a8a') !== false or strpos($headerHtml, 'خسارت پرداختی') !== false);

            for ($r = 1; $r < count($trMatches); $r++) {
                $rowHtml = $trMatches[$r][2];
                if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowHtml, $tdMatches)) continue;
                $cells = array();
                foreach ($tdMatches[1] as $cHtml) {
                    $t = html_entity_decode(strip_tags($cHtml), ENT_QUOTES, 'UTF-8');
                    $t = str_replace("\xC2\xA0", ' ', $t);
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $t));
                }

                if ($isEndorsement && count($cells) >= 14) {
                    $endorsements[] = array(
                        'radif'           => isset($cells[0]) ? $cells[0] : '',
                        'insuredName'     => isset($cells[1]) ? $cells[1] : '',
                        'plate'           => isset($cells[2]) ? $cells[2] : '',
                        'vin'             => isset($cells[3]) ? $cells[3] : '',
                        'chassisNo'       => isset($cells[4]) ? $cells[4] : '',
                        'motorNo'         => isset($cells[5]) ? $cells[5] : '',
                        'noLossLifeYr'    => isset($cells[6]) ? $cells[6] : '',
                        'noLossFinYr'     => isset($cells[7]) ? $cells[7] : '',
                        'noLossPassYr'    => isset($cells[8]) ? $cells[8] : '',
                        'noLossBodyYr'    => isset($cells[9]) ? $cells[9] : '',
                        'discFinPct'      => isset($cells[10]) ? $cells[10] : '',
                        'discLifePct'     => isset($cells[11]) ? $cells[11] : '',
                        'discPassPct'     => isset($cells[12]) ? $cells[12] : '',
                        'issueDate'       => isset($cells[13]) ? $cells[13] : '',
                        'endorsementType' => isset($cells[14]) ? $cells[14] : 'الحاقیه',
                    );
                } elseif ($isLoss && count($cells) >= 4) {
                    $lType = isset($cells[4]) ? trim($cells[4]) : '';
                    if ($lType === '' or $lType === '-') {
                        $lType = 'ثبت‌شده (مبلغ: ' . (isset($cells[1]) ? $cells[1] : '—') . ')';
                    }
                    $losses[] = array(
                        'radif'       => isset($cells[0]) ? $cells[0] : '',
                        'paidAmount'  => isset($cells[1]) ? $cells[1] : '',
                        'declareDate' => isset($cells[2]) ? $cells[2] : '',
                        'paidDate'    => isset($cells[3]) ? $cells[3] : '',
                        'lossType'    => $lType,
                    );
                }
            }
        }

        return array('endorsements' => $endorsements, 'losses' => $losses);
    }

    // محاسبه فیلدهای نتیجه عدم خسارت و تخفیف‌ها برای یک ردیف بیمه‌نامه
    private function buildPolicyRowSummary($row)
    {
        $losses = isset($row['losses']) && is_array($row['losses']) ? $row['losses'] : array();
        $hasLoss = !empty($losses);
        $lossTypesArr = array();
        foreach ($losses as $l) {
            $lt = isset($l['lossType']) && trim($l['lossType']) !== '' ? trim($l['lossType']) : 'خسارت';
            $lossTypesArr[] = $lt;
        }
        $lossTypesStr = !empty($lossTypesArr) ? implode(' ، ', $lossTypesArr) : 'ندارد';

        $discThirdVal  = isset($row['discFinPct']) && trim($row['discFinPct']) !== '' ? trim($row['discFinPct']) : '0';
        $discDriverVal = isset($row['discPassPct']) && trim($row['discPassPct']) !== '' ? trim($row['discPassPct']) : '0';

        $discThirdText  = $discThirdVal . ' درصد';
        $discDriverText = $discDriverVal . ' درصد';

        $statusLabel = $hasLoss ? ('خسارت دارد - (' . $lossTypesStr . ')') : 'عدم خسارت';
        $fullText    = $statusLabel . ' - تخفیف ثالث (' . $discThirdText . ') تخفیف حوادث راننده (' . $discDriverText . ')';

        return array(
            'hasLoss'                => $hasLoss,
            'lossStatus'             => $statusLabel,
            'lossTypes'              => $lossTypesStr,
            'thirdPartyDiscount'     => $discThirdText,
            'driverAccidentDiscount' => $discDriverText,
            'summaryText'            => $fullText,
        );
    }

    // استخراج تمام ردیف‌های بیمه‌نامه + الحاقیه‌ها و خسارت‌های هر ردیف
    private function parseSanhabGridRows($decodedHtml)
    {
        $rows = array();
        if (!preg_match_all('/<tr\b[^>]*\bid=["\']ctl00_Main_policyinq_CallbackPanel1_gv_DXDataRow(\d+)["\'][^>]*>(.*?)<\/tr>/is', $decodedHtml, $trMatches, PREG_SET_ORDER)) {
            return $rows;
        }

        foreach ($trMatches as $tr) {
            $rowIndex = (int)$tr[1];
            $rowInner = $tr[2];
            if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowInner, $tdMatches)) continue;
            $cells = array();
            foreach ($tdMatches[1] as $tdContent) {
                $txt = html_entity_decode(strip_tags($tdContent), ENT_QUOTES, 'UTF-8');
                $txt = str_replace("\xC2\xA0", ' ', $txt);
                $cells[] = trim(preg_replace('/\s+/u', ' ', $txt));
            }
            if (count($cells) >= 16) {
                $details = $this->parsePreviewRowDetails($decodedHtml, $rowIndex);
                $rowItem = array(
                    'rowIndex'     => $rowIndex,
                    'radif'        => isset($cells[1]) ? $cells[1] : '',
                    'uniqueCode'   => isset($cells[2]) ? $cells[2] : '',
                    'policyType'   => isset($cells[3]) ? $cells[3] : '',
                    'company'      => isset($cells[4]) ? $cells[4] : '',
                    'insuredName'  => isset($cells[5]) ? $cells[5] : '',
                    'policyNo'     => isset($cells[6]) ? $cells[6] : '',
                    'prevPolicyNo' => isset($cells[7]) ? $cells[7] : '',
                    'vehicleName'  => isset($cells[8]) ? $cells[8] : '',
                    'plate'        => isset($cells[9]) ? $cells[9] : '',
                    'motorNo'      => isset($cells[10]) ? $cells[10] : '',
                    'chassisNo'    => isset($cells[11]) ? $cells[11] : '',
                    'vin'          => isset($cells[12]) ? $cells[12] : '',
                    'issueDate'    => isset($cells[13]) ? $cells[13] : '',
                    'startDate'    => isset($cells[14]) ? $cells[14] : '',
                    'endDate'      => isset($cells[15]) ? $cells[15] : '',
                    'noLossFinYr'  => isset($cells[16]) ? $cells[16] : '',
                    'noLossLifeYr' => isset($cells[17]) ? $cells[17] : '',
                    'noLossPassYr' => isset($cells[18]) ? $cells[18] : '',
                    'discFinPct'   => isset($cells[19]) ? $cells[19] : '',
                    'discLifePct'  => isset($cells[20]) ? $cells[20] : '',
                    'discPassPct'  => isset($cells[21]) ? $cells[21] : '',
                    'noLossBodyYr' => isset($cells[22]) ? $cells[22] : '',
                    'penaltyCost'  => isset($cells[23]) ? $cells[23] : '',
                    'endorsements' => $details['endorsements'],
                    'losses'       => $details['losses'],
                );
                $rowItem['rowSummary'] = $this->buildPolicyRowSummary($rowItem);
                $rows[] = $rowItem;
            }
        }
        return $rows;
    }

    public function executeInquiry($params)
    {
        $typeId   = isset($params['typeId']) ? (string)$params['typeId'] : '4';
        $plcyType = isset($params['plcyType']) ? (string)$params['plcyType'] : '0';
        $captcha  = isset($params['captcha']) ? trim((string)$params['captcha']) : '';

        $state = $this->loadState();
        if (empty($state['inqFields']['__VIEWSTATE'])) {
            return array('ok' => false, 'message' => 'نشست استعلام آماده نیست؛ لطفاً روی دکمه ↻ کپچای جدید کلیک کنید');
        }

        // اگر در حالت گروهی نوع جستجوی ردیف جدید با ردیف قبلی فرق داشت، ابتدا نوع جدید را انتخاب کن
        if (!empty($state['selectedType']) && (string)$state['selectedType'] !== $typeId) {
            $selRes = $this->selectSearchType($typeId, $plcyType, true);
            if (empty($selRes['ok'])) return $selRes;
            $state = $this->loadState();
        }

        $baseFields = $state['inqFields'];
        $f = $this->buildBaseCallbackFields($baseFields, $typeId, $plcyType);
        $this->cleanTypeSpecificFields($f, $typeId);

        if ($typeId === '4') {
            $plk1 = isset($params['plk1']) ? trim((string)$params['plk1']) : '';
            $plk2 = isset($params['plk2']) ? trim((string)$params['plk2']) : '';
            $plk3 = isset($params['plk3']) ? trim((string)$params['plk3']) : '';
            $plk4 = isset($params['plk4']) ? trim((string)$params['plk4']) : '';

            $f['ctl00_Main_policyinq_CallbackPanel1_Plk1_Raw']   = $plk1;
            $f['ctl00$Main$policyinq$CallbackPanel1$Plk1']       = $plk1;
            $f['ctl00$Main$policyinq$CallbackPanel1$Plk1$CVS']   = '';
            $f['ctl00_Main_policyinq_CallbackPanel1_Plk2_Raw']   = $plk2;
            $f['ctl00$Main$policyinq$CallbackPanel1$Plk2']       = $plk2;
            $f['ctl00$Main$policyinq$CallbackPanel1$Plk2$CVS']   = '';
            $f['ctl00_Main_policyinq_CallbackPanel1_plk3_VI']    = $plk3;
            $f['ctl00$Main$policyinq$CallbackPanel1$plk3']       = $plk3;
            $f['ctl00_Main_policyinq_CallbackPanel1_plk3_DDDWS'] = '0:0:12000:829:207:1:70:157:1:0:0:0';
            $f['ctl00$Main$policyinq$CallbackPanel1$plk3$DDD$L'] = $plk3;
            $f['ctl00_Main_policyinq_CallbackPanel1_Plk4_Raw']   = $plk4;
            $f['ctl00$Main$policyinq$CallbackPanel1$Plk4']       = $plk4;
            $f['ctl00$Main$policyinq$CallbackPanel1$Plk4$CVS']   = '';
        } elseif ($typeId === '8' or $typeId === '45') {
            $plk1m = isset($params['plk1m']) ? trim((string)$params['plk1m']) : '';
            $plk2m = isset($params['plk2m']) ? trim((string)$params['plk2m']) : '';
            $f['ctl00_Main_policyinq_CallbackPanel1_plk1m_Raw']  = $plk1m;
            $f['ctl00$Main$policyinq$CallbackPanel1$plk1m']      = $plk1m;
            $f['ctl00$Main$policyinq$CallbackPanel1$plk1m$CVS']  = '';
            $f['ctl00_Main_policyinq_CallbackPanel1_plk2m_Raw']  = $plk2m;
            $f['ctl00$Main$policyinq$CallbackPanel1$plk2m']      = $plk2m;
            $f['ctl00$Main$policyinq$CallbackPanel1$plk2m$CVS']  = '';
        } elseif ($typeId === '6') {
            $txt1 = isset($params['txtInput']) ? trim((string)$params['txtInput']) : '';
            $txt2 = isset($params['txtInput2']) ? trim((string)$params['txtInput2']) : '';
            $f['ctl00$Main$policyinq$CallbackPanel1$txtInput']   = $txt1;
            $f['ctl00$Main$policyinq$CallbackPanel1$text1']      = $txt1;
            $f['ctl00$Main$policyinq$CallbackPanel1$text2']      = $txt2;
        } else {
            $txt1 = isset($params['txtInput']) ? trim((string)$params['txtInput']) : '';
            $f['ctl00$Main$policyinq$CallbackPanel1$txtInput']   = $txt1;
        }

        $f['ctl00$Main$policyinq$CallbackPanel1$Captcha1$TB']     = $captcha;
        $f['ctl00$Main$policyinq$CallbackPanel1$Captcha1$TB$CVS'] = '';
        $f['ctl00$Main$policyinq$CallbackPanel1$gv$DXSelInput']   = '';
        $f['ctl00$Main$policyinq$CallbackPanel1$gv$DXKVInput']    = '[]';

        $pipe = chr(124);
        $evBefore = isset($f['__EVENTVALIDATION']) ? $f['__EVENTVALIDATION'] : '';
        unset($f['__EVENTVALIDATION']);
        if ($plcyType === '1') {
            $f['__CALLBACKID']    = 'ctl00$Main$policyinq$CallbackPanel1$gvlosthrd';
            $f['__CALLBACKPARAM'] = 'c0:KV' . $pipe . '2;[];GB' . $pipe . '20;14' . $pipe . 'CUSTOMCALLBACK0' . $pipe . ';';
        } else {
            $f['__CALLBACKID']    = 'ctl00$Main$policyinq$CallbackPanel1$gv';
            $f['__CALLBACKPARAM'] = 'c0:KV' . $pipe . '2;[];GB' . $pipe . '19;14' . $pipe . 'CUSTOMCALLBACK0' . $pipe . ';';
        }
        $f['__EVENTVALIDATION'] = $evBefore;

        $r = $this->http('POST', $this->inqUrl, $f, $this->inqUrl, true);
        if ($this->isLoginPage($r['body'], $r['url'])) {
            return array('ok' => false, 'error' => 'need_sanhab_login');
        }

        $decoded = $this->parseCallbackResponse($r['body'], $baseFields);
        @file_put_contents($this->previewFile, $decoded);

        $updatedInputs = $this->extractAllInputs($decoded);
        if (!empty($updatedInputs['ctl00$Main$policyinq$CallbackPanel1$gv$CallbackState'])) {
            $baseFields['ctl00$Main$policyinq$CallbackPanel1$gv$CallbackState'] = $updatedInputs['ctl00$Main$policyinq$CallbackPanel1$gv$CallbackState'];
        }
        if (!empty($updatedInputs['ctl00$Main$policyinq$CallbackPanel1$gv$DXKVInput'])) {
            $baseFields['ctl00$Main$policyinq$CallbackPanel1$gv$DXKVInput'] = $updatedInputs['ctl00$Main$policyinq$CallbackPanel1$gv$DXKVInput'];
        }
        $state['inqFields']  = $baseFields;
        $state['loggedInAt'] = time();
        $this->saveState($state);

        $cpAbc = '';
        if (preg_match('/[\'"]cp_(?:los)?abc[\'"]\s*:\s*[\'"]([^\'"]+)[\'"]/i', $decoded, $m)) {
            $cpAbc = strtolower(trim($m[1]));
        }
        $cpFnm = '';
        if (preg_match('/[\'"]cp_(?:los)?fnm[\'"]\s*:\s*[\'"]([^\'"]+)[\'"]/u', $decoded, $m)) {
            $cpFnm = trim($m[1]);
        }
        $cpDte = '';
        if (preg_match('/[\'"]cp_(?:los)?dte[\'"]\s*:\s*[\'"]([^\'"]+)[\'"]/u', $decoded, $m)) {
            $cpDte = trim($m[1]);
        }

        $lblErr = '';
        if (preg_match('/id=["\']ctl00_Main_policyinq_CallbackPanel1_lblerr["\'][^>]*>(.*?)<\/span>/is', $decoded, $em)) {
            $lblErr = trim(strip_tags($em[1]));
        }

        $rows = $this->parseSanhabGridRows($decoded);

        $newCapRes  = $this->refreshInqCaptcha();
        $nextCapImg = isset($newCapRes['captchaImage']) ? $newCapRes['captchaImage'] : '';

        if ($cpAbc === 'err' or ($lblErr !== '' && empty($rows))) {
            $errMsg = $lblErr !== '' ? $lblErr : 'کد امنیتی را صحیح وارد کنید';
            return array(
                'ok'           => false,
                'invalidCap'   => ($cpAbc === 'err' or strpos($errMsg, 'امنیتی') !== false),
                'message'      => $errMsg,
                'captchaImage' => $nextCapImg,
            );
        }

        if (empty($rows)) {
            return array(
                'ok'           => false,
                'invalidCap'   => false,
                'message'      => 'نتیجه‌ای یافت نشد (هیچ بیمه‌نامه‌ای با این مشخصات ثبت نشده است)',
                'captchaImage' => $nextCapImg,
            );
        }

        // نتیجه عدم خسارت بر اساس جدیدترین بیمه‌نامه خودرو (ردیف اول = rows[0])
        $latestSummary = $rows[0]['rowSummary'];

        // بررسی خسارت در کل سوابق نیز جهت اطلاع تکمیلی
        $olderLossNotes = array();
        for ($idx = 1; $idx < count($rows); $idx++) {
            if (!empty($rows[$idx]['losses'])) {
                foreach ($rows[$idx]['losses'] as $ol) {
                    $olderLossNotes[] = 'ردیف ' . ($idx + 1) . ' (' . $ol['lossType'] . ' در تاریخ ' . $ol['paidDate'] . ')';
                }
            }
        }
        $latestSummary['olderHistoryLosses'] = !empty($olderLossNotes) ? implode(' | ', $olderLossNotes) : 'ندارد';

        return array(
            'ok'            => true,
            'count'         => count($rows),
            'inquirerName'  => $cpFnm !== '' ? $cpFnm : 'کاربر سنهاب',
            'inquiryDate'   => $cpDte !== '' ? $cpDte : date('Y/m/d H:i'),
            'latestSummary' => $latestSummary,
            'rows'          => $rows,
            'captchaImage'  => $nextCapImg,
        );
    }

    public function streamPdf($typ, $pu)
    {
        $url = $this->netBase . '/inq/PolicyINQ_Pdf.aspx?typ=' . urlencode((string)$typ) . '&pu=' . urlencode((string)$pu);
        return $this->http('GET', $url, array(), $this->inqUrl);
    }
}
