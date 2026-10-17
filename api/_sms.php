<?php
// فایل: api/_sms.php
// پنل‌های پیامک (چند سرویس‌دهنده؛ یکی فعال + یکی پشتیبان) و کدِ یک‌بارمصرف (OTP) با لاگِ کامل.
//  - تنظیمات در system_settings: sms_provider، sms_fallback، sms_cfg (JSON برای هر پنل)، sms_otp_text، otp_*
//  - هر کدِ ارسالی یک ردیف در otp_codes: زمان، شماره، پنل، موفق/ناموفق بودنِ ارسال، پاسخِ پنل، تعدادِ تلاشِ اشتباه، تأیید، انقضا
//  - کد هیچ‌وقت خام ذخیره نمی‌شود (فقط هشِ نمک‌دار)
// پنل‌ها: کاوه‌نگار، SMS.ir، ملی‌پیامک، IPPanel (فراز اس‌ام‌اس و …)، قاصدک، و وب‌سرویسِ دلخواه (HTTP)

// fields: [کلید, برچسب, نوع (text|secret|select), راهنما]
const SMS_PROVIDERS = [
    'kavenegar' => ['fa' => 'کاوه‌نگار', 'site' => 'kavenegar.com',
        'fields' => [['api_key', 'API Key', 'secret', 'پنل ← تنظیمات حساب ← API Key'], ['template', 'نامِ الگوی Verify (حالتِ الگو)', 'text', 'نامِ الگویی که در «اعتبارسنجی» ساخته‌اید، مثلاً mammut-otp'],
                     ['sender', 'شماره‌ی خط (حالتِ متنِ ساده)', 'text', 'مثلاً 10004346']],
        'pattern_var' => '%token', 'pattern_note' => 'در کاوه‌نگار: «اعتبارسنجی ← الگوی جدید»؛ متغیرِ کد %token است.'],
    'smsir' => ['fa' => 'SMS.ir (اس‌ام‌اس دات آی‌آر)', 'site' => 'sms.ir',
        'fields' => [['api_key', 'کلیدِ API (X-API-KEY)', 'secret', 'پنل ← برنامه‌نویسان ← لیست کلیدهای API'], ['template_id', 'شناسه‌ی قالب (حالتِ الگو)', 'text', 'عددِ «شناسه‌ی قالب» در «ارسال سریع»'],
                     ['param_name', 'نامِ پارامترِ کد در قالب', 'text', 'پیش‌فرض CODE'], ['line_number', 'شماره‌ی خط (حالتِ متنِ ساده)', 'text', 'مثلاً 30007732000000']],
        'pattern_var' => '#CODE#', 'pattern_note' => 'در SMS.ir: «ارسال سریع ← قالب جدید»؛ پارامتر را CODE بگذارید (#CODE#).'],
    'melipayamak' => ['fa' => 'ملی پیامک', 'site' => 'melipayamak.com',
        'fields' => [['username', 'نامِ کاربری پنل', 'text', ''], ['password', 'رمز / کلیدِ API', 'secret', 'رمزِ وب‌سرویس یا API Key پنل'],
                     ['body_id', 'کدِ متن (bodyId) برای خدماتی', 'text', 'عددِ «کد متن» در «وب‌سرویس خدماتی»'], ['sender', 'شماره‌ی خط (حالتِ متنِ ساده)', 'text', '']],
        'pattern_var' => '{0}', 'pattern_note' => 'در ملی‌پیامک: «وب‌سرویس خدماتی ← متن جدید»؛ جای کد {0} است.'],
    'ippanel' => ['fa' => 'IPPanel / فراز اس‌ام‌اس (و پنل‌های هم‌خانواده)', 'site' => 'ippanel.com',
        'fields' => [['api_key', 'کلیدِ دسترسی (apikey)', 'secret', 'پنل ← توسعه‌دهندگان ← کلید دسترسی'], ['pattern_code', 'کدِ پترن (حالتِ الگو)', 'text', 'کدِ پترنِ تأییدشده'],
                     ['var_name', 'نامِ متغیرِ کد در پترن', 'text', 'پیش‌فرض code'], ['sender', 'شماره‌ی خط', 'text', 'مثلاً +983000505']],
        'pattern_var' => '%code%', 'pattern_note' => 'در IPPanel/فراز: «پترن‌ها ← پترن جدید»؛ متغیر را code بگذارید (%code%).'],
    'ghasedak' => ['fa' => 'قاصدک', 'site' => 'ghasedak.me',
        'fields' => [['api_key', 'کلیدِ API (apikey)', 'secret', ''], ['template', 'نامِ قالب (حالتِ الگو)', 'text', 'نامِ قالبِ اعتبارسنجی'],
                     ['line_number', 'شماره‌ی خط (حالتِ متنِ ساده)', 'text', '']],
        'pattern_var' => '%param1%', 'pattern_note' => 'در قاصدک: «قالب‌ها ← قالب جدید»؛ جای کد %param1% است.'],
    'custom' => ['fa' => 'وب‌سرویسِ دلخواه (HTTP)', 'site' => '',
        'fields' => [['url', 'آدرسِ وب‌سرویس', 'text', 'جاینگهدارها: {phone} {phone98} {code} {text} — مثلاً https://api.example.com/send?to={phone}&msg={text}'],
                     ['method', 'روش', 'select:GET,POST_JSON,POST_FORM', ''], ['body', 'بدنه‌ی درخواست (برای POST)', 'textarea', 'مثلاً {"to":"{phone}","text":"{text}"}'],
                     ['headers', 'هدرها (هر خط یک هدر)', 'textarea', 'مثلاً Authorization: Bearer xxx'], ['success_word', 'کلمه‌ی نشانه‌ی موفقیت در پاسخ (اختیاری)', 'text', 'خالی = هر پاسخِ 2xx موفق است']],
        'pattern_var' => '{code}', 'pattern_note' => 'هر پنلی که API دارد را با همین گزینه وصل کنید؛ {text} متنِ کاملِ پیامک و {code} فقط خودِ کد است.'],
];

const SMS_DEFAULT_OTP_TEXT = "{name} عزیز\nکد تایید ورود به دستیار بیمه ماموت: {code}\nاعتبار: {ttl} دقیقه\nاین کد را در اختیار هیچ‌کس قرار ندهید.";

function sms_get($pdo, $key, $def = null) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $def : $v;
    } catch (Throwable $e) { return $def; }
}
function sms_set($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, (string)$value]);
}

// همه‌ی تنظیماتِ پیامک و OTP (با مقدارهای پیش‌فرض)
function sms_settings($pdo) {
    $cfg = json_decode((string)sms_get($pdo, 'sms_cfg', '{}'), true) ?: [];
    return [
        'provider' => (string)sms_get($pdo, 'sms_provider', ''),
        'fallback' => (string)sms_get($pdo, 'sms_fallback', ''),
        'cfg' => $cfg,
        'otp_text' => (string)sms_get($pdo, 'sms_otp_text', SMS_DEFAULT_OTP_TEXT),
        'otp_bot' => sms_get($pdo, 'otp_bot_enabled', '0') === '1',
        'otp_ttl' => max(1, min(15, intval(sms_get($pdo, 'otp_ttl_min', 2)))),
        'otp_len' => max(4, min(6, intval(sms_get($pdo, 'otp_len', 5)))),
        'otp_attempts' => max(2, min(10, intval(sms_get($pdo, 'otp_max_attempts', 5)))),
        'otp_resend' => max(30, min(600, intval(sms_get($pdo, 'otp_resend_sec', 60)))),
    ];
}

function sms_mask_phone($p) {
    $p = (string)$p;
    return strlen($p) >= 11 ? substr($p, 0, 4) . '****' . substr($p, -4) : $p;
}
function sms_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function sms_norm_phone($p) {
    $p = strtr((string)$p, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    $p = preg_replace('/\D/', '', $p);
    if (strpos($p, '0098') === 0) $p = substr($p, 4);
    elseif (strlen($p) === 12 && strpos($p, '98') === 0) $p = substr($p, 2);
    if (strlen($p) === 10 && $p[0] === '9') $p = '0' . $p;
    return preg_match('/^09\d{9}$/', $p) ? $p : '';
}

// ---------------------------------------------------------------------
//  ارسال
// ---------------------------------------------------------------------
function sms_http($method, $url, $body = null, $headers = [], $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '' : $body);
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $code, 'body' => $resp === false ? '' : (string)$resp, 'error' => $err];
}

// یک پیامک با یک پنل. $code داده شود و حالتِ پنل «الگو» باشد = ارسالِ الگو (سریع، بدونِ «لغو۱۱»)؛ وگرنه متنِ ساده.
// خروجی: ok، status (متنِ کوتاه)، ref (شناسه‌ی پیام در پنل)، raw (پاسخِ خام، کوتاه‌شده)، http
function sms_send_with($provider, array $c, $phone, $text, $code = null) {
    $mode = ($c['mode'] ?? 'pattern') === 'text' || $code === null ? 'text' : 'pattern';
    $p98 = '+98' . substr($phone, 1);
    $res = ['http' => 0, 'body' => '', 'error' => ''];
    $ok = false; $ref = ''; $status = '';
    $j = function () use (&$res) { return json_decode($res['body'], true) ?: []; };
    switch ($provider) {
        case 'kavenegar':
            $key = rawurlencode(trim($c['api_key'] ?? ''));
            if ($mode === 'pattern') {
                if (trim($c['template'] ?? '') === '') return ['ok' => false, 'status' => 'نامِ الگو تنظیم نشده است.'];
                $res = sms_http('GET', "https://api.kavenegar.com/v1/$key/verify/lookup.json?" . http_build_query(['receptor' => $phone, 'token' => $code, 'template' => trim($c['template'])]));
            } else {
                $res = sms_http('POST', "https://api.kavenegar.com/v1/$key/sms/send.json", http_build_query(['receptor' => $phone, 'sender' => trim($c['sender'] ?? ''), 'message' => $text]),
                                ['Content-Type: application/x-www-form-urlencoded']);
            }
            $d = $j(); $st = (int)($d['return']['status'] ?? 0);
            $ok = $st === 200; $status = (string)($d['return']['message'] ?? '');
            $ref = (string)($d['entries'][0]['messageid'] ?? '');
            break;
        case 'smsir':
            $h = ['Content-Type: application/json', 'Accept: text/plain', 'X-API-KEY: ' . trim($c['api_key'] ?? '')];
            if ($mode === 'pattern') {
                if (!intval($c['template_id'] ?? 0)) return ['ok' => false, 'status' => 'شناسه‌ی قالب تنظیم نشده است.'];
                $res = sms_http('POST', 'https://api.sms.ir/v1/send/verify', json_encode(['mobile' => $phone, 'templateId' => intval($c['template_id']),
                    'parameters' => [['name' => trim($c['param_name'] ?? '') ?: 'CODE', 'value' => (string)$code]]]), $h);
            } else {
                $res = sms_http('POST', 'https://api.sms.ir/v1/send/bulk', json_encode(['lineNumber' => (int)preg_replace('/\D/', '', $c['line_number'] ?? ''), 'messageText' => $text, 'mobiles' => [$phone]]), $h);
            }
            $d = $j(); $ok = (int)($d['status'] ?? 0) === 1; $status = (string)($d['message'] ?? '');
            $ref = (string)($d['data']['messageId'] ?? ($d['data']['messageIds'][0] ?? ''));
            break;
        case 'melipayamak':
            $base = ['username' => trim($c['username'] ?? ''), 'password' => trim($c['password'] ?? ''), 'to' => $phone];
            if ($mode === 'pattern') {
                if (!intval($c['body_id'] ?? 0)) return ['ok' => false, 'status' => 'کدِ متن (bodyId) تنظیم نشده است.'];
                $res = sms_http('POST', 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', http_build_query($base + ['text' => (string)$code, 'bodyId' => intval($c['body_id'])]),
                                ['Content-Type: application/x-www-form-urlencoded']);
            } else {
                $res = sms_http('POST', 'https://rest.payamak-panel.com/api/SendSMS/SendSMS', http_build_query($base + ['from' => trim($c['sender'] ?? ''), 'text' => $text, 'isFlash' => 'false']),
                                ['Content-Type: application/x-www-form-urlencoded']);
            }
            $d = $j(); $val = (string)($d['Value'] ?? '');
            $ok = (int)($d['RetStatus'] ?? 0) === 1 && strlen($val) > 3; $status = (string)($d['StrRetStatus'] ?? '') . ($ok ? '' : ' (' . $val . ')');
            $ref = $ok ? $val : '';
            break;
        case 'ippanel':
            $h = ['Content-Type: application/json', 'apikey: ' . trim($c['api_key'] ?? '')];
            $sender = trim($c['sender'] ?? '');
            if ($mode === 'pattern') {
                if (trim($c['pattern_code'] ?? '') === '') return ['ok' => false, 'status' => 'کدِ پترن تنظیم نشده است.'];
                $res = sms_http('POST', 'https://api2.ippanel.com/api/v1/sms/pattern/normal/send', json_encode(['code' => trim($c['pattern_code']), 'sender' => $sender, 'recipient' => $p98,
                    'variable' => [(trim($c['var_name'] ?? '') ?: 'code') => (string)$code]]), $h);
            } else {
                $res = sms_http('POST', 'https://api2.ippanel.com/api/v1/sms/send/webservice/single', json_encode(['recipient' => [$p98], 'sender' => $sender, 'message' => $text]), $h);
            }
            $d = $j(); $ok = strtoupper((string)($d['status'] ?? '')) === 'OK' || (int)($d['code'] ?? 0) === 200;
            $status = (string)($d['error_message'] ?? ($d['message'] ?? ($d['status'] ?? '')));
            $ref = (string)($d['data']['message_id'] ?? ($d['data']['bulk_id'] ?? ''));
            break;
        case 'ghasedak':
            $h = ['Content-Type: application/x-www-form-urlencoded', 'apikey: ' . trim($c['api_key'] ?? '')];
            if ($mode === 'pattern') {
                if (trim($c['template'] ?? '') === '') return ['ok' => false, 'status' => 'نامِ قالب تنظیم نشده است.'];
                $res = sms_http('POST', 'https://api.ghasedak.me/v2/verification/send/simple', http_build_query(['receptor' => $phone, 'type' => 1, 'template' => trim($c['template']), 'param1' => (string)$code]), $h);
            } else {
                $res = sms_http('POST', 'https://api.ghasedak.me/v2/sms/send/simple', http_build_query(['message' => $text, 'receptor' => $phone, 'linenumber' => trim($c['line_number'] ?? '')]), $h);
            }
            $d = $j(); $ok = (int)($d['result']['code'] ?? 0) === 200; $status = (string)($d['result']['message'] ?? '');
            $ref = (string)(is_array($d['items'] ?? null) ? ($d['items'][0] ?? '') : '');
            break;
        case 'custom':
            $url = trim($c['url'] ?? '');
            if (!preg_match('~^https?://~i', $url)) return ['ok' => false, 'status' => 'آدرسِ وب‌سرویس تنظیم نشده است.'];
            $rep = function ($s, $enc) use ($phone, $p98, $code, $text) {
                $f = $enc === 'url' ? 'rawurlencode' : ($enc === 'json' ? function ($v) { return substr(json_encode((string)$v, JSON_UNESCAPED_UNICODE), 1, -1); } : function ($v) { return $v; });
                return strtr($s, ['{phone}' => $f($phone), '{phone98}' => $f($p98), '{code}' => $f((string)$code), '{text}' => $f($text)]);
            };
            $m = $c['method'] ?? 'GET';
            $hs = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($c['headers'] ?? '')))));
            if ($m === 'POST_JSON') { $hs[] = 'Content-Type: application/json'; $res = sms_http('POST', $rep($url, 'url'), $rep((string)($c['body'] ?? ''), 'json'), $hs); }
            elseif ($m === 'POST_FORM') { $hs[] = 'Content-Type: application/x-www-form-urlencoded'; $res = sms_http('POST', $rep($url, 'url'), $rep((string)($c['body'] ?? ''), 'url'), $hs); }
            else $res = sms_http('GET', $rep($url, 'url'), null, $hs);
            $w = trim($c['success_word'] ?? '');
            $ok = $res['http'] >= 200 && $res['http'] < 300 && ($w === '' || mb_stripos($res['body'], $w) !== false);
            $status = 'HTTP ' . $res['http'];
            break;
        default:
            return ['ok' => false, 'status' => 'پنلِ پیامک انتخاب نشده است.'];
    }
    if ($res['error'] !== '') $status = 'خطای اتصال: ' . $res['error'];
    elseif ($status === '') $status = 'HTTP ' . $res['http'];
    return ['ok' => $ok, 'status' => mb_substr($status, 0, 250), 'ref' => mb_substr($ref, 0, 80), 'raw' => mb_substr($res['body'], 0, 500), 'http' => $res['http'], 'mode' => $mode];
}

// ارسال با پنلِ فعال؛ اگر ناموفق بود و پنلِ پشتیبان تنظیم شده، با پشتیبان
function sms_send($pdo, $phone, $text, $code = null) {
    $s = sms_settings($pdo);
    $phone = sms_norm_phone($phone);
    if ($phone === '') return ['ok' => false, 'status' => 'شماره‌ی موبایل نامعتبر است.', 'provider' => ''];
    if ($s['provider'] === '' || !isset(SMS_PROVIDERS[$s['provider']])) return ['ok' => false, 'status' => 'پنلِ پیامک در تنظیمات انتخاب نشده است.', 'provider' => ''];
    $tries = [$s['provider']];
    if ($s['fallback'] !== '' && $s['fallback'] !== $s['provider'] && isset(SMS_PROVIDERS[$s['fallback']])) $tries[] = $s['fallback'];
    $r = null; $notes = [];
    foreach ($tries as $pv) {
        $t0 = microtime(true);
        $r = sms_send_with($pv, (array)($s['cfg'][$pv] ?? []), $phone, $text, $code);
        $r['provider'] = $pv; $r['ms'] = (int)round((microtime(true) - $t0) * 1000);
        if ($r['ok']) break;
        $notes[] = (SMS_PROVIDERS[$pv]['fa'] ?? $pv) . ': ' . $r['status'];
    }
    if (!$r['ok'] && count($notes) > 1) $r['status'] = implode(' | ', $notes);
    elseif ($r['ok'] && $notes) $r['status'] = 'با پنلِ پشتیبان ارسال شد (' . implode(' | ', $notes) . ')';
    return $r;
}

// ---------------------------------------------------------------------
//  کدِ یک‌بارمصرف (OTP) + لاگ
// ---------------------------------------------------------------------
function otp_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS otp_codes (
        id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, purpose VARCHAR(20) NOT NULL DEFAULT 'BOT_LOGIN',
        person_id BIGINT NULL, national_code VARCHAR(10) NULL, full_name VARCHAR(190) NULL, phone VARCHAR(20) NOT NULL, phone_source VARCHAR(10) NULL,
        chat_id VARCHAR(40) NULL, code_salt VARCHAR(32) NOT NULL, code_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'SENT', attempts INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 5, verified_at DATETIME NULL, last_try_at DATETIME NULL,
        provider VARCHAR(20) NULL, send_ok TINYINT(1) NOT NULL DEFAULT 0, send_status VARCHAR(255) NULL, send_ref VARCHAR(80) NULL, send_ms INT NULL,
        KEY k_person (person_id), KEY k_nid (national_code), KEY k_phone (phone), KEY k_chat (chat_id), KEY k_created (created_at)) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

const OTP_STATUS_FA = ['SENT' => 'ارسال شد · منتظرِ کد', 'FAILED' => 'ارسالِ پیامک ناموفق', 'VERIFIED' => 'تأیید شد', 'EXPIRED' => 'منقضی شد',
                       'LOCKED' => 'قفل (تلاشِ اشتباهِ زیاد)', 'REPLACED' => 'کدِ تازه جایش فرستاده شد', 'CANCELED' => 'انصراف'];

// ساخت و ارسالِ کد. $p = [purpose, person_id, national_code, full_name, phone, phone_source, chat_id]
// خروجی: ok + id + ttl + masked؛ یا ok=false + error (+ wait ثانیه تا ارسالِ دوباره) + id (اگر ردیف ساخته شد ولی ارسال نشد)
function otp_issue($pdo, array $p) {
    otp_ensure($pdo);
    $s = sms_settings($pdo);
    $phone = sms_norm_phone($p['phone'] ?? '');
    if ($phone === '') return ['ok' => false, 'error' => 'شماره‌ی موبایل نامعتبر است.'];
    $purpose = (string)($p['purpose'] ?? 'BOT_LOGIN');
    // فاصله‌ی بینِ دو ارسال برای همان شماره
    $st = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM otp_codes WHERE phone = ? AND purpose = ? AND send_ok = 1 ORDER BY id DESC LIMIT 1");
    $st->execute([$phone, $purpose]);
    $ago = $st->fetchColumn();
    if ($ago !== false && (int)$ago < $s['otp_resend']) return ['ok' => false, 'wait' => $s['otp_resend'] - (int)$ago, 'error' => 'کمی صبر کنید و دوباره درخواست کنید.'];
    // سقفِ روزانه برای هر شماره (جلوی هدر رفتنِ اعتبارِ پیامک)
    $st = $pdo->prepare("SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $st->execute([$phone]);
    if ((int)$st->fetchColumn() >= 10) return ['ok' => false, 'error' => 'تعدادِ کدهای ارسالی به این شماره در ۲۴ ساعتِ گذشته زیاد بوده است؛ بعداً تلاش کنید یا با پشتیبانی تماس بگیرید.'];

    $pdo->prepare("UPDATE otp_codes SET status = 'REPLACED' WHERE status = 'SENT' AND purpose = ? AND (phone = ? OR (chat_id IS NOT NULL AND chat_id = ?))")
        ->execute([$purpose, $phone, (string)($p['chat_id'] ?? '')]);
    $code = '';
    for ($i = 0; $i < $s['otp_len']; $i++) $code .= (string)random_int($i === 0 ? 1 : 0, 9);
    $salt = bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO otp_codes (purpose, person_id, national_code, full_name, phone, phone_source, chat_id, code_salt, code_hash, expires_at, max_attempts, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, 'SENT')")
        ->execute([$purpose, $p['person_id'] ?: null, $p['national_code'] ?? null, mb_substr((string)($p['full_name'] ?? ''), 0, 190) ?: null, $phone, $p['phone_source'] ?? null,
                   isset($p['chat_id']) ? (string)$p['chat_id'] : null, $salt, hash('sha256', $salt . $code), $s['otp_ttl'], $s['otp_attempts']]);
    $id = (int)$pdo->lastInsertId();
    $name = trim((string)($p['full_name'] ?? '')) ?: 'کاربر';
    $text = strtr($s['otp_text'], ['{name}' => $name, '{code}' => $code, '{ttl}' => $s['otp_ttl']]);
    $r = sms_send($pdo, $phone, $text, $code);
    $pdo->prepare("UPDATE otp_codes SET provider = ?, send_ok = ?, send_status = ?, send_ref = ?, send_ms = ?, status = IF(?, status, 'FAILED') WHERE id = ?")
        ->execute([$r['provider'] ?? null, $r['ok'] ? 1 : 0, mb_substr((string)($r['status'] ?? ''), 0, 255), $r['ref'] ?? null, $r['ms'] ?? null, $r['ok'] ? 1 : 0, $id]);
    if (!$r['ok']) return ['ok' => false, 'id' => $id, 'send_failed' => true, 'error' => 'ارسالِ پیامک ناموفق بود.', 'detail' => $r['status'] ?? ''];
    return ['ok' => true, 'id' => $id, 'ttl' => $s['otp_ttl'], 'len' => $s['otp_len'], 'masked' => sms_mask_phone($phone), 'phone' => $phone];
}

// بررسیِ کد. خروجی: ok=true (تأیید شد) یا ok=false + reason (WRONG | EXPIRED | LOCKED | GONE) + left (تلاش‌های باقی‌مانده)
function otp_verify($pdo, $id, $code) {
    otp_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM otp_codes WHERE id = ?");
    $st->execute([intval($id)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['status'] !== 'SENT') return ['ok' => false, 'reason' => $r && $r['status'] === 'LOCKED' ? 'LOCKED' : 'GONE'];
    if (strtotime($r['expires_at']) < time()) {
        $pdo->prepare("UPDATE otp_codes SET status = 'EXPIRED' WHERE id = ?")->execute([$r['id']]);
        return ['ok' => false, 'reason' => 'EXPIRED'];
    }
    $code = preg_replace('/\D/', '', sms_norm_digits((string)$code));
    if ($code !== '' && hash_equals($r['code_hash'], hash('sha256', $r['code_salt'] . $code))) {
        $pdo->prepare("UPDATE otp_codes SET status = 'VERIFIED', verified_at = NOW(), last_try_at = NOW() WHERE id = ?")->execute([$r['id']]);
        return ['ok' => true, 'row' => $r];
    }
    $att = (int)$r['attempts'] + 1;
    $locked = $att >= (int)$r['max_attempts'];
    $pdo->prepare("UPDATE otp_codes SET attempts = ?, last_try_at = NOW(), status = ? WHERE id = ?")->execute([$att, $locked ? 'LOCKED' : 'SENT', $r['id']]);
    return ['ok' => false, 'reason' => $locked ? 'LOCKED' : 'WRONG', 'left' => max(0, (int)$r['max_attempts'] - $att)];
}
function sms_norm_digits($s) {
    return strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                              '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
}
function otp_cancel($pdo, $id) {
    otp_ensure($pdo);
    $pdo->prepare("UPDATE otp_codes SET status = 'CANCELED' WHERE id = ? AND status = 'SENT'")->execute([intval($id)]);
}

// لاگ برای پنل (بانک مخاطبین): وضعیت، تلاش‌ها، اعتبارِ باقی‌مانده
function otp_log_rows($pdo, $where, array $args, $limit = 50) {
    otp_ensure($pdo);
    $st = $pdo->prepare("SELECT id, created_at, purpose, person_id, national_code, full_name, phone, phone_source, status, attempts, max_attempts, verified_at, last_try_at, expires_at,
                                provider, send_ok, send_status, send_ref, send_ms, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS left_sec
                         FROM otp_codes WHERE $where ORDER BY id DESC LIMIT " . intval($limit));
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        if ($r['status'] === 'SENT' && (int)$r['left_sec'] <= 0) $r['status'] = 'EXPIRED';
        $r['status_fa'] = OTP_STATUS_FA[$r['status']] ?? $r['status'];
        $r['left_sec'] = $r['status'] === 'SENT' ? max(0, (int)$r['left_sec']) : 0;
        $r['provider_fa'] = SMS_PROVIDERS[$r['provider']]['fa'] ?? ($r['provider'] ?: '—');
        $r['phone_masked'] = sms_mask_phone($r['phone']);
        foreach (['created_at' => 'created_j', 'verified_at' => 'verified_j', 'last_try_at' => 'last_try_j'] as $src => $dst) {
            $r[$dst] = '';
            if (!empty($r[$src]) && function_exists('jalali_from_gregorian_ts')) {
                $ts = strtotime($r[$src]); [$y, $m, $d] = jalali_from_gregorian_ts($ts);
                $r[$dst] = sprintf('%04d/%02d/%02d %s', $y, $m, $d, date('H:i:s', $ts));
            }
        }
    }
    unset($r);
    return $rows;
}
