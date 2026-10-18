<?php
// فایل: api/sms_admin.php
// پنل (فقط مدیر کل): تنظیماتِ پنل‌های پیامک (فعال + پشتیبان)، متنِ پیامکِ OTP، ورود با کدِ تأیید در ربات، ارسالِ آزمایشی، لاگِ کدها
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start();
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_sms.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? '');
const SMS_MASK = '••••••';

// فیلدهای محرمانه (کلید/رمز) کامل به مرورگر فرستاده نمی‌شوند
function sa_secret_keys($pv) {
    $k = [];
    foreach (SMS_PROVIDERS[$pv]['fields'] as $f) if ($f[2] === 'secret') $k[] = $f[0];
    return $k;
}
function sa_mask_cfg(array $cfg) {
    foreach ($cfg as $pv => &$c) {
        if (!isset(SMS_PROVIDERS[$pv]) || !is_array($c)) continue;
        foreach (sa_secret_keys($pv) as $k) if (!empty($c[$k])) $c[$k] = SMS_MASK . mb_substr((string)$c[$k], -4);
    }
    unset($c);
    return $cfg;
}
// متنِ پیشنهادیِ الگو برای ثبت در پنلِ پیامک
function sa_pattern_text($pv) {
    $v = SMS_PROVIDERS[$pv]['pattern_var'];
    return "کد تایید ورود به دستیار بیمه ماموت: $v\nاین کد را در اختیار هیچ‌کس قرار ندهید.";
}

try {
    if ($action === 'get') {
        $s = sms_settings($pdo);
        $prov = [];
        foreach (SMS_PROVIDERS as $k => $p) $prov[] = ['key' => $k, 'fa' => $p['fa'], 'site' => $p['site'], 'fields' => $p['fields'], 'pattern_note' => $p['pattern_note'], 'pattern_text' => sa_pattern_text($k)];
        $s['cfg'] = sa_mask_cfg($s['cfg']);
        $out(['ok' => true, 'settings' => $s, 'providers' => $prov, 'default_otp_text' => SMS_DEFAULT_OTP_TEXT, 'curl' => function_exists('curl_init')]);
    }

    if ($action === 'save') {
        $old = sms_settings($pdo)['cfg'];
        $cfg = [];
        foreach ((array)($in['cfg'] ?? []) as $pv => $c) {
            if (!isset(SMS_PROVIDERS[$pv]) || !is_array($c)) continue;
            $row = ['mode' => ($c['mode'] ?? 'pattern') === 'text' ? 'text' : 'pattern'];
            foreach (SMS_PROVIDERS[$pv]['fields'] as $f) {
                $v = trim((string)($c[$f[0]] ?? ''));
                if ($f[2] === 'secret' && strpos($v, SMS_MASK) === 0) $v = (string)($old[$pv][$f[0]] ?? '');   // دست‌نخورده = همان قبلی
                $row[$f[0]] = mb_substr($v, 0, $f[2] === 'textarea' ? 2000 : 300);
            }
            $cfg[$pv] = $row;
        }
        foreach ($old as $pv => $c) if (!isset($cfg[$pv])) $cfg[$pv] = $c;
        $pv = (string)($in['provider'] ?? '');
        $fb = (string)($in['fallback'] ?? '');
        sms_set($pdo, 'sms_cfg', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        sms_set($pdo, 'sms_provider', isset(SMS_PROVIDERS[$pv]) ? $pv : '');
        sms_set($pdo, 'sms_fallback', isset(SMS_PROVIDERS[$fb]) && $fb !== $pv ? $fb : '');
        $txt = trim((string)($in['otp_text'] ?? ''));
        if ($txt !== '' && strpos($txt, '{code}') === false) $out(['ok' => false, 'error' => 'متنِ پیامک باید {code} (جای کد) را داشته باشد.']);
        sms_set($pdo, 'sms_otp_text', mb_substr($txt ?: SMS_DEFAULT_OTP_TEXT, 0, 500));
        // راهِ ارسالِ کد: ورودِ کارکنان در ربات (off|sms|sapi) و ورود به پنل با کد (bot|sms|sapi)
        $botCh = in_array($in['otp_bot_channel'] ?? '', ['off', 'sms', 'sapi'], true) ? $in['otp_bot_channel'] : (!empty($in['otp_bot']) ? 'sms' : 'off');
        $panCh = in_array($in['panel_otp_channel'] ?? '', ['bot', 'sms', 'sapi'], true) ? $in['panel_otp_channel'] : panel_otp_channel($pdo);
        if (($botCh === 'sms' || $panCh === 'sms') && !isset(SMS_PROVIDERS[$pv])) $out(['ok' => false, 'error' => 'برای ارسالِ کد با پنلِ پیامک، اول یک پنلِ پیامک انتخاب و تنظیم کنید.']);
        if ($botCh === 'sapi' || $panCh === 'sapi') {
            require_once __DIR__ . '/_apis.php';
            if (!sapi_ready($pdo, 'sapi_otp')) $out(['ok' => false, 'error' => 'برای «پیامکی OTP» اول کلیدِ s.api.ir را در «تنظیمات ← کلیدهای API» وارد کنید.']);
        }
        sms_set($pdo, 'otp_bot_channel', $botCh);
        sms_set($pdo, 'otp_bot_enabled', $botCh !== 'off' ? '1' : '0');
        sms_set($pdo, 'panel_otp_channel', $panCh);
        sms_set($pdo, 'otp_ttl_min', max(1, min(15, intval($in['otp_ttl'] ?? 2))));
        sms_set($pdo, 'otp_len', max(4, min(6, intval($in['otp_len'] ?? 5))));
        sms_set($pdo, 'otp_max_attempts', max(2, min(10, intval($in['otp_attempts'] ?? 5))));
        sms_set($pdo, 'otp_resend_sec', max(30, min(600, intval($in['otp_resend'] ?? 60))));
        $s = sms_settings($pdo);
        $s['cfg'] = sa_mask_cfg($s['cfg']);
        $out(['ok' => true, 'settings' => $s]);
    }

    // ارسالِ آزمایشی با یک پنلِ مشخص (تنظیماتِ ذخیره‌شده) - پاسخِ خامِ پنل هم برگردانده می‌شود
    if ($action === 'test') {
        $pv = (string)($in['provider'] ?? '');
        if (!isset(SMS_PROVIDERS[$pv])) $out(['ok' => false, 'error' => 'پنل را انتخاب کنید.']);
        $phone = sms_norm_phone($in['phone'] ?? '');
        if ($phone === '') $out(['ok' => false, 'error' => 'شماره‌ی موبایل را درست وارد کنید (۰۹…).']);
        if (!function_exists('curl_init')) $out(['ok' => false, 'error' => 'افزونه‌ی curl روی سرور فعال نیست.']);
        $s = sms_settings($pdo);
        $code = (string)random_int(10000, 99999);
        $text = strtr($s['otp_text'], ['{name}' => 'مدیر', '{code}' => $code, '{ttl}' => $s['otp_ttl']]);
        $t0 = microtime(true);
        $r = sms_send_with($pv, (array)($s['cfg'][$pv] ?? []), $phone, $text, $code);
        $r['ms'] = (int)round((microtime(true) - $t0) * 1000);
        $r['code'] = $code;
        $out(['ok' => true, 'result' => $r]);
    }

    // لاگِ کدها (همه‌ی اشخاص) - همان در «بانک مخاطبین» برای هر شخص جدا هم هست
    if ($action === 'logs') {
        $w = ['1=1']; $a = [];
        $st = (string)($in['status'] ?? '');
        if ($st === 'FAILED') $w[] = 'send_ok = 0';
        elseif (isset(OTP_STATUS_FA[$st])) { $w[] = 'status = ?'; $a[] = $st; }
        $q = trim(sms_norm_digits((string)($in['q'] ?? '')));
        if ($q !== '') { $w[] = '(phone LIKE ? OR national_code LIKE ? OR full_name LIKE ?)'; $l = '%' . $q . '%'; array_push($a, $l, $l, $l); }
        $rows = otp_log_rows($pdo, implode(' AND ', $w), $a, 100);
        $stats = $pdo->query("SELECT COUNT(*) n, SUM(send_ok = 1) sent, SUM(send_ok = 0) failed, SUM(status = 'VERIFIED') verified FROM otp_codes WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetch(PDO::FETCH_ASSOC);
        $out(['ok' => true, 'rows' => $rows, 'stats' => $stats]);
    }

    $out(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[sms_admin] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
