<?php
// فایل: api/api_admin.php
// پنل (فقط مدیر کل): «تنظیمات ← کلیدهای API» — کلیدِ دسترسی و تنظیماتِ هر وب‌سرویسِ بیرونی (api/_apis.php) و آزمایشِ هر کدام
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start();
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_apis.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? '');
const API_MASK = '••••••';

// کلیدها کامل به مرورگر فرستاده نمی‌شوند (فقط ۴ نویسه‌ی آخر)
function aa_masked(array $cfg) {
    foreach (API_SERVICES as $k => $sv) foreach ($sv['fields'] as $f)
        if ($f[2] === 'secret' && !empty($cfg[$k][$f[0]])) $cfg[$k][$f[0]] = API_MASK . mb_substr((string)$cfg[$k][$f[0]], -4);
    return $cfg;
}
function aa_public($pdo) {
    $cfg = api_cfg($pdo);
    $list = [];
    foreach (API_SERVICES as $k => $sv) $list[] = ['key' => $k, 'fa' => $sv['fa'], 'icon' => $sv['icon'], 'desc' => $sv['desc'], 'endpoint' => $sv['endpoint'] ?? '',
                                                 'fields' => $sv['fields'], 'ready' => $k === 'sapi' ? sapi_conf($pdo, 'sapi')['token'] !== '' : ($k === 'gapgpt' ? gapgpt_conf($pdo)['token'] !== ''
                                                           : ($k === 'ai_vision' ? (function () use ($pdo) { require_once __DIR__ . '/_vdoc_ai.php'; return vdoc_ai_conf($pdo)['token'] !== ''; })() : sapi_ready($pdo, $k))),
                                                 'own_key' => trim((string)($cfg[$k]['token'] ?? '')) !== ''];
    return ['services' => $list, 'cfg' => aa_masked($cfg), 'curl' => function_exists('curl_init'),
            'usage' => ['otp_bot' => otp_bot_channel($pdo), 'panel_otp' => panel_otp_channel($pdo)]];
}

try {
    if ($action === 'get') $out(['ok' => true] + aa_public($pdo));

    if ($action === 'save') {
        $old = api_cfg($pdo);
        $cfg = $old;
        foreach ((array)($in['cfg'] ?? []) as $k => $c) {
            if (!isset(API_SERVICES[$k]) || !is_array($c)) continue;
            $row = (array)($old[$k] ?? []);
            foreach (API_SERVICES[$k]['fields'] as $f) {
                if (!array_key_exists($f[0], $c)) continue;
                $v = trim((string)$c[$f[0]]);
                if ($f[2] === 'secret') {
                    if (strpos($v, API_MASK) === 0) continue;   // دست‌نخورده = همان قبلی
                    $v = preg_replace('/^Bearer\s+/i', '', $v);
                }
                if ($f[0] === 'base_url' && $v !== '' && !preg_match('~^https?://~i', $v)) $out(['ok' => false, 'error' => 'آدرسِ پایه باید با https:// شروع شود.']);
                $row[$f[0]] = mb_substr($v, 0, $f[2] === 'secret' ? 2000 : 300);
            }
            $cfg[$k] = $row;
        }
        api_save_cfg($pdo, $cfg);
        $out(['ok' => true] + aa_public($pdo));
    }

    // آزمایش‌ها (با تنظیماتِ ذخیره‌شده)
    if ($action === 'test') {
        $svc = (string)($in['service'] ?? '');
        if ($svc === 'sapi') {
            $r = sapi_echo($pdo, 'بیمه با ما');
            $out(['ok' => true, 'result' => ['ok' => $r['ok'], 'status' => $r['ok'] ? 'کلید درست است ✓' . (is_array($r['data']) && !empty($r['data']['greeting']) ? ' — پاسخ: ' . $r['data']['greeting'] : '') : $r['error'],
                                             'http' => $r['http'], 'raw' => $r['raw'], 'ms' => $r['ms'] ?? null]]);
        }
        if ($svc === 'sapi_otp') {
            $phone = sms_norm_phone($in['phone'] ?? '');
            if ($phone === '') $out(['ok' => false, 'error' => 'شماره‌ی موبایل را درست وارد کنید (۰۹…).']);
            $code = (string)random_int(10000, 99999);
            $r = sapi_send_otp($pdo, $phone, $code, isset($in['template']) ? (string)$in['template'] : null);
            $tf = isset($r['template']) ? ' با قالبِ ' . $r['template'] : '';
            $out(['ok' => true, 'result' => ['ok' => $r['ok'], 'status' => $r['ok'] ? 'کدِ ' . $code . $tf . ' فرستاده شد.' : $r['status'] . $tf, 'http' => $r['http'] ?? 0,
                                             'raw' => $r['raw'] ?? '', 'sent' => $r['sent'] ?? '', 'ms' => $r['ms'] ?? null]]);
        }
        if ($svc === 'ai_vision') {
            require_once __DIR__ . '/_vdoc_ai.php';
            if (!vdoc_ai_ready($pdo)) $out(['ok' => true, 'result' => ['ok' => false, 'status' => 'آدرس و کلیدِ API را وارد و ذخیره کنید.']]);
            $c = vdoc_ai_conf($pdo); $t0 = microtime(true);
            $r = sms_http('POST', $c['base'] . '/chat/completions', json_encode(['model' => $c['model'], 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'Reply with: ok']]]),
                          ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']], $c['timeout']);
            $ok = $r['error'] === '' && $r['http'] >= 200 && $r['http'] < 300;
            $out(['ok' => true, 'result' => ['ok' => $ok, 'status' => $ok ? 'اتصال برقرار است ✓ (مدل ' . $c['model'] . ')' : ($r['error'] !== '' ? 'خطای اتصال: ' . $r['error'] : 'پاسخِ ناموفق (HTTP ' . $r['http'] . ')'),
                                             'http' => $r['http'], 'raw' => mb_substr((string)$r['body'], 0, 600), 'ms' => (int)round((microtime(true) - $t0) * 1000)]]);
        }
        if ($svc === 'gapgpt') {
            $c = gapgpt_conf($pdo);
            if ($c['token'] === '') $out(['ok' => true, 'result' => ['ok' => false, 'status' => 'کلیدِ GapGPT را وارد و ذخیره کنید.']]);
            if (($in['mode'] ?? '') === 'systemone') {
                $j = json_decode((string)($in['payload'] ?? ''), true);
                if (!is_array($j) || !isset($j['questions']) || !is_array($j['questions'])) $out(['ok' => false, 'error' => 'ورودی باید JSON با «state» و «questions» باشد.']);
                $r = gapgpt_systemone($pdo, (array)($j['state'] ?? []), $j['questions'], trim((string)($j['model'] ?? '')) ?: null);
                $out(['ok' => true, 'result' => ['ok' => $r['ok'], 'status' => $r['ok'] ? 'systemone پاسخ داد ✓' : $r['error'], 'http' => $r['http'], 'raw' => $r['raw'], 'sent' => $r['sent'] ?? '', 'ms' => $r['ms'] ?? null]]);
            }
            $t0 = microtime(true);
            $r = sms_http('POST', $c['base'] . '/chat/completions', json_encode(['model' => $c['model'], 'max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'Reply with: ok']]]),
                          ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']], $c['timeout']);
            $ok = $r['error'] === '' && $r['http'] >= 200 && $r['http'] < 300;
            $out(['ok' => true, 'result' => ['ok' => $ok, 'status' => $ok ? 'اتصال به GapGPT برقرار است ✓ (مدل ' . $c['model'] . ')' : ($r['error'] !== '' ? 'خطای اتصال: ' . $r['error'] : 'پاسخِ ناموفق (HTTP ' . $r['http'] . ')'),
                                             'http' => $r['http'], 'raw' => mb_substr((string)$r['body'], 0, 1500), 'ms' => (int)round((microtime(true) - $t0) * 1000)]]);
        }
        if ($svc === 'sapi_company') {
            $r = sapi_company_info($pdo, $in['national_id'] ?? '');
            $out(['ok' => true, 'result' => ['ok' => $r['ok'], 'status' => $r['ok'] ? ($r['company']['name'] . ' — ' . $r['company']['company_type']) : $r['error'], 'company' => $r['company'] ?? null, 'raw' => $r['raw'] ?? '']]);
        }
        $out(['ok' => false, 'error' => 'سرویس نامعتبر.']);
    }

    $out(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[api_admin] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
