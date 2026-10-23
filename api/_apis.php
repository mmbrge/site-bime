<?php
// فایل: api/_apis.php
// وب‌سرویس‌های بیرونی (فعلاً s.api.ir): کلیدِ دسترسی و تنظیماتِ هر سرویس جدا در system_settings (api_services، JSON).
//   sapi        : کلیدِ مشترکِ s.api.ir و آدرسِ پایه (اگر سرویسی کلیدِ خودش را نداشت، از همین استفاده می‌شود)
//   sapi_otp    : «پیامکی OTP» — ارسالِ کد با خطِ خدماتیِ s.api.ir (بدونِ نیاز به پنلِ پیامک)
//   sapi_company: «استعلامِ شخصِ حقوقی» — اطلاعاتِ ثبتیِ شرکت با شناسه‌ی ملی
// پاسخِ همه‌ی سرویس‌ها: {data, success, code, message}
require_once __DIR__ . '/_sms.php';   // sms_get / sms_set / sms_http / sms_norm_phone

// fields: [کلید, برچسب, نوع (text|secret|select:مقدار=برچسب,…), راهنما]
const API_SERVICES = [
    'sapi' => ['fa' => 's.api.ir — کلیدِ مشترک', 'icon' => 'fa-key', 'site' => 's.api.ir',
        'desc' => 'کلیدی که در پنلِ s.api.ir ساخته‌اید (Bearer). اگر برای سرویسی کلیدِ جدا نگذارید، همین کلید استفاده می‌شود.',
        'fields' => [['token', 'کلیدِ دسترسی (Bearer Token)', 'secret', 'پنلِ s.api.ir ← کلیدها؛ بدونِ کلمه‌ی Bearer وارد کنید'],
                     ['base_url', 'آدرسِ پایه', 'text', 'پیش‌فرض https://s.api.ir'],
                     ['timeout', 'مهلتِ پاسخ (ثانیه)', 'text', 'پیش‌فرض ۱۵']]],
    'sapi_otp' => ['fa' => 'پیامکی OTP (s.api.ir)', 'icon' => 'fa-comment-sms', 'site' => 's.api.ir', 'endpoint' => '/api/sw1/SmsOTP',
        'desc' => 'ارسالِ کدِ تأیید به همه‌ی شماره‌ها با خطِ خدماتیِ s.api.ir؛ نیازی به خرید پنل یا خطِ خدماتیِ جدا نیست. متنِ پیامک را s.api.ir بر اساسِ «قالب» می‌سازد.',
        'fields' => [['token', 'کلیدِ اختصاصی (اختیاری)', 'secret', 'خالی = کلیدِ مشترکِ s.api.ir'],
                     ['template', 'قالبِ پیامک', 'select:1=کد تأیید,0=کد ورود,2=رمز,3=رمز ورود,4=با نامِ کسب‌وکار (بعد از احرازِ سطح ۱)', 'قالب‌های ۰ تا ۳ آماده‌اند؛ برای قالبِ ۴ (نامِ کسب‌وکار در پیامک) در s.api.ir احرازِ سطح ۱ با اینماد یا تیکت لازم است']]],
    'sapi_company' => ['fa' => 'استعلامِ شخصِ حقوقی (s.api.ir)', 'icon' => 'fa-building-circle-check', 'site' => 's.api.ir', 'endpoint' => '/api/sw1/CompanyInfo',
        'desc' => 'با شناسه‌ی ملیِ شرکت، اطلاعاتِ ثبتی (نام، نوع، شماره و تاریخِ ثبت، آدرس، کدپستی، استان و شهر، فعال بودن) خوانده و در «تعریفِ شرکت» نشانده می‌شود.',
        'fields' => [['token', 'کلیدِ اختصاصی (اختیاری)', 'secret', 'خالی = کلیدِ مشترکِ s.api.ir']]],
    'ai_vision' => ['fa' => 'خواندنِ اسکن (هوش مصنوعی)', 'icon' => 'fa-wand-magic-sparkles', 'site' => 'OpenAI-compatible',
        'desc' => 'مدارکِ اسکن‌شده (عکس یا PDFِ بدونِ متن، مثلِ سند فروشِ کارخانه) با یک مدلِ تصویری خوانده می‌شوند: شاسی، موتور، مشخصاتِ خودرو و مالک. هر سرویسی که API سازگار با OpenAI (chat/completions) و مدلِ تصویری دارد کار می‌کند. بدونِ این تنظیم، اسکن‌ها مثلِ قبل دستی وارد می‌شوند.',
        'fields' => [['provider', 'سرویس‌دهنده', 'select:custom=سرویسِ دلخواه (آدرس و کلیدِ همین‌جا),gapgpt=GapGPT (کلیدِ کارتِ GapGPT)', 'با GapGPT آدرس و کلید از کارتِ «GapGPT» پایین‌تر خوانده می‌شود'],
                     ['base_url', 'آدرسِ API', 'text', 'مثلاً https://api.openai.com/v1 یا آدرسِ سرویسِ داخلیِ سازگار؛ تا پیش از /chat/completions'],
                     ['token', 'کلیدِ API', 'secret', 'کلیدِ همان سرویس (بدونِ Bearer)'],
                     ['model', 'مدل', 'text', 'مدلِ تصویری، مثلاً gpt-4o-mini یا gpt-4o'],
                     ['timeout', 'مهلتِ پاسخ (ثانیه)', 'text', 'پیش‌فرض ۶۰'],
                     ['enabled', 'وضعیت', 'select:1=فعال,0=غیرفعال', '']]],
    'gapgpt' => ['fa' => 'GapGPT (هوش مصنوعی)', 'icon' => 'fa-robot', 'site' => 'gapgpt.app', 'endpoint' => '/v1/chat/completions · /v1/systemone',
        'desc' => 'سرویسِ ایرانیِ GapGPT: هم chat/completions سازگار با OpenAI (برای خواندنِ اسکن‌ها، اگر در کارتِ «خواندنِ اسکن» سرویس‌دهنده را GapGPT بگذارید) و هم systemone (پرسش‌های ساختاریافته از یک متن: بله/خیر، انتخاب، امتیاز). با «آزمایشِ systemone» نمونه‌ی ورودی را بفرستید و خروجی را ببینید.',
        'fields' => [['token', 'کلیدِ API', 'secret', 'کلیدی که با sk- شروع می‌شود (بدونِ Bearer)'],
                     ['base_url', 'آدرسِ پایه', 'text', 'پیش‌فرض https://api.gapgpt.app/v1'],
                     ['model', 'مدلِ تصویری (اسکن‌ها)', 'text', 'مثلاً gpt-4o یا gpt-4o-mini'],
                     ['model_systemone', 'مدلِ systemone', 'text', 'پیش‌فرض typesafe/jev-1.13'],
                     ['timeout', 'مهلتِ پاسخ (ثانیه)', 'text', 'پیش‌فرض ۶۰']]],
];

// تنظیماتِ GapGPT: آدرس، کلید، مدل‌ها
function gapgpt_conf($pdo) {
    $c = api_cfg($pdo)['gapgpt'] ?? [];
    return ['base' => rtrim(trim((string)($c['base_url'] ?? '')) ?: 'https://api.gapgpt.app/v1', '/'), 'token' => preg_replace('/^Bearer\s+/i', '', trim((string)($c['token'] ?? ''))),
            'model' => trim((string)($c['model'] ?? '')) ?: 'gpt-4o-mini', 'model_systemone' => trim((string)($c['model_systemone'] ?? '')) ?: 'typesafe/jev-1.13',
            'timeout' => max(10, min(180, intval($c['timeout'] ?? 60) ?: 60))];
}
// systemone: پرسش‌های ساختاریافته از یک «state» (مثلاً متنِ نامه) — questions: {key: {type: noul|choice|score, instructions, criteria}}
// خروجی: ok، http، data (پاسخِ JSON)، raw، error، ms
function gapgpt_systemone($pdo, array $state, array $questions, $model = null) {
    $c = gapgpt_conf($pdo);
    if ($c['token'] === '') return ['ok' => false, 'http' => 0, 'error' => 'کلیدِ GapGPT در «تنظیمات ← کلیدهای API» وارد نشده است.', 'data' => null, 'raw' => ''];
    $t0 = microtime(true);
    $body = json_encode(['model' => $model ?: $c['model_systemone'], 'state' => $state, 'questions' => $questions], JSON_UNESCAPED_UNICODE);
    $r = sms_http('POST', $c['base'] . '/systemone', $body, ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $c['token']], $c['timeout']);
    $d = json_decode((string)$r['body'], true);
    $ok = $r['error'] === '' && $r['http'] >= 200 && $r['http'] < 300 && is_array($d);
    $err = $ok ? '' : ($r['error'] !== '' ? 'خطای اتصال: ' . $r['error'] : 'پاسخِ ناموفق (HTTP ' . $r['http'] . ')' . (is_array($d) && !empty($d['error']) ? ': ' . (is_array($d['error']) ? ($d['error']['message'] ?? json_encode($d['error'], JSON_UNESCAPED_UNICODE)) : $d['error']) : ''));
    return ['ok' => $ok, 'http' => $r['http'], 'data' => $d, 'raw' => mb_substr((string)$r['body'], 0, 6000), 'error' => $err, 'sent' => $body, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

function api_cfg($pdo) { return json_decode((string)sms_get($pdo, 'api_services', '{}'), true) ?: []; }
function api_save_cfg($pdo, array $cfg) { sms_set($pdo, 'api_services', json_encode($cfg, JSON_UNESCAPED_UNICODE)); }

// کلید و آدرس و مهلتِ نهاییِ یک سرویس (کلیدِ اختصاصی، وگرنه مشترک)
function sapi_conf($pdo, $service) {
    $c = api_cfg($pdo);
    $base = rtrim(trim((string)($c['sapi']['base_url'] ?? '')) ?: 'https://s.api.ir', '/');
    $token = trim((string)($c[$service]['token'] ?? '')) ?: trim((string)($c['sapi']['token'] ?? ''));
    $token = preg_replace('/^Bearer\s+/i', '', $token);
    return ['base' => $base, 'token' => $token, 'timeout' => max(5, min(60, intval($c['sapi']['timeout'] ?? 15) ?: 15)), 'svc' => (array)($c[$service] ?? [])];
}
function sapi_ready($pdo, $service) { return sapi_conf($pdo, $service)['token'] !== ''; }

// یک فراخوانیِ POST/JSON. خروجی: ok (HTTP 2xx و success)، http، data، message، code، raw، error
function sapi_call($pdo, $service, $path, array $body) {
    $k = sapi_conf($pdo, $service);
    if ($k['token'] === '') return ['ok' => false, 'http' => 0, 'error' => 'کلیدِ دسترسیِ s.api.ir در «تنظیمات ← کلیدهای API» وارد نشده است.', 'data' => null, 'message' => '', 'raw' => ''];
    if (!function_exists('curl_init')) return ['ok' => false, 'http' => 0, 'error' => 'افزونه‌ی curl روی سرور فعال نیست.', 'data' => null, 'message' => '', 'raw' => ''];
    $t0 = microtime(true);
    $res = sms_http('POST', $k['base'] . $path, json_encode($body, JSON_UNESCAPED_UNICODE),
                    ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $k['token']], $k['timeout']);
    $d = json_decode((string)$res['body'], true);
    $msg = is_array($d) ? trim((string)($d['message'] ?? '')) : '';
    $ok = $res['http'] >= 200 && $res['http'] < 300 && is_array($d) && !empty($d['success']);
    $err = '';
    if (!$ok) {
        if ($res['error'] !== '') $err = 'خطای اتصال: ' . $res['error'];
        elseif ($res['http'] === 401 || $res['http'] === 403) $err = 'کلیدِ دسترسی نامعتبر است یا اجازه‌ی این سرویس را ندارد (HTTP ' . $res['http'] . ').';
        elseif ($res['http'] === 402) $err = 'اعتبارِ حسابِ s.api.ir کافی نیست.';
        elseif ($msg !== '') $err = $msg;
        else $err = 'پاسخِ ناموفق از s.api.ir (HTTP ' . $res['http'] . ').';
    }
    return ['ok' => $ok, 'http' => $res['http'], 'data' => is_array($d) ? ($d['data'] ?? null) : null, 'message' => $msg, 'code' => is_array($d) ? ($d['code'] ?? null) : null,
            'raw' => mb_substr((string)$res['body'], 0, 600), 'error' => $err, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

// ارسالِ کد با «پیامکی OTP»؛ خروجی هم‌شکلِ sms_send (برای لاگِ otp_codes)
// $template = null یعنی قالبِ ذخیره‌شده در تنظیمات (برای آزمایشِ قالب‌ها پیش از ذخیره، قالب داده می‌شود)
function sapi_send_otp($pdo, $phone, $code, $template = null) {
    $phone = sms_norm_phone($phone);
    if ($phone === '') return ['ok' => false, 'status' => 'شماره‌ی موبایل نامعتبر است.', 'provider' => 'sapi_otp'];
    $tpl = $template !== null && $template !== '' ? $template : (sapi_conf($pdo, 'sapi_otp')['svc']['template'] ?? '1');
    $tpl = max(0, min(4, intval($tpl)));
    $body = ['code' => (string)$code, 'mobile' => $phone, 'template' => $tpl];
    $r = sapi_call($pdo, 'sapi_otp', '/api/sw1/SmsOTP', $body);
    return ['ok' => $r['ok'], 'status' => mb_substr($r['ok'] ? ($r['message'] ?: 'ارسال شد') : $r['error'], 0, 250), 'ref' => '', 'raw' => $r['raw'],
            'http' => $r['http'], 'ms' => $r['ms'] ?? null, 'provider' => 'sapi_otp', 'template' => $tpl, 'sent' => json_encode($body, JSON_UNESCAPED_UNICODE)];
}

// استعلامِ شخصِ حقوقی. خروجی: ok + company (فیلدهای آماده‌ی فرمِ شرکت) + raw
function sapi_company_info($pdo, $nationalId) {
    $nid = preg_replace('/\D/', '', sms_norm_digits((string)$nationalId));
    if (strlen($nid) !== 11) return ['ok' => false, 'error' => 'شناسه‌ی ملیِ شرکت باید ۱۱ رقم باشد.'];
    $r = sapi_call($pdo, 'sapi_company', '/api/sw1/CompanyInfo', ['nationalID' => $nid]);
    if (!$r['ok'] || !is_array($r['data'])) return ['ok' => false, 'error' => $r['ok'] ? 'اطلاعاتی برای این شناسه‌ی ملی پیدا نشد.' : $r['error'], 'raw' => $r['raw']];
    $d = $r['data'];
    $s = function ($k) use ($d) { return trim(preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك'], ['ی', 'ک'], (string)($d[$k] ?? '')))); };
    return ['ok' => true, 'raw' => $r['raw'], 'company' => [
        'name' => $s('name'), 'company_type' => $s('companyType'), 'national_id' => preg_replace('/\D/', '', (string)($d['nationalID'] ?? $nid)) ?: $nid,
        'register_number' => $s('registerNumber'), 'register_date' => $s('registerDate'), 'active' => isset($d['active']) ? (bool)$d['active'] : null,
        'address' => $s('address'), 'postal_code' => preg_replace('/\D/', '', (string)($d['postalCode'] ?? '')), 'province' => $s('province'), 'city' => $s('city'),
        'end_date' => $s('endDate'),
    ]];
}

// آزمایشِ کلید با سرویسِ رایگانِ Echo (sandbox)
function sapi_echo($pdo, $name = 'تست') {
    return sapi_call($pdo, 'sapi', '/api/Sandbox/Echo', ['name' => (string)$name]);
}
