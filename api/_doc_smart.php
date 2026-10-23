<?php
// فایل: api/_doc_smart.php
// «خواندنِ هوشمند»ِ نامه و مدارکِ شرکت‌ها (دکمه‌ی دستیِ کارشناس در پنل):
//   ۱) مرورگر صفحه‌ها را OCR می‌کند (smart-ocr.js → smart_ocr_page = همان موتورهای «پردازش فایل هوشمند»)
//   ۲) فقط همان متن (نه هیچ اطلاعاتِ دیگری از سایت) به هوشِ مصنوعی داده می‌شود تا JSONِ ساختاریافته برگرداند
//   ۳) JSON از همان مسیرهای قبلی رد می‌شود: نامه ← پیش‌نمایشِ «ورود ردیف‌ها از نامه» (کارشناس تأیید می‌کند)،
//      مدرک ← vdoc_ai_normalize و vdoc_process_company_doc (فقط خانه‌های خالیِ ردیف پر می‌شود)
// سدِ هوشِ مصنوعی: هیچ ابزار/دسترسی‌ای به دیتابیس یا فایل‌ها ندارد؛ فقط متنی که اینجا ساخته می‌شود را می‌بیند
// و خروجی‌اش هم مستقیم در دیتابیس نوشته نمی‌شود (اول اعتبارسنجی و پاک‌سازی).
require_once __DIR__ . '/_vdoc_ai.php';

const DS_MAX_TEXT = 60000;

const DS_LETTER_PROMPT = <<<TXT
The user message is the OCR text of an Iranian insurance request letter (Persian, may contain OCR mistakes and tables flattened into lines).
Reply with ONE JSON object only (no prose, no code fence) shaped like {"letter": {"counts": {"ثالث": 0, "بدنه": 0, "تغییر اطلاعات": 0, "فسخ": 0}}, "rows": [ ... ]}.
In "letter.counts" write how many of each kind the letter requests. In "rows" make ONE row per vehicle with:
request ("صدور", "تغییر اطلاعات" or "فسخ"); plate in four separate fields: plate_two (the two digits at the left of the plate), plate_letter (the Persian letter), plate_three (the three digits), plate_iran (the two digits of the Iran code, next to the word «ایران»).
IMPORTANT: in letters a plate is usually written like «۷۹۸ع۸۱-۱۱» or «994ط59 78» which means plate_three=798, plate_letter=ع, plate_two=81 and the number after the dash/space plate_iran=11; or like «۸۱ ع ۷۹۸ ایران ۱۱» which is the same plate. Never swap the Iran code with the first two digits.
For a motorcycle write moto_city (three upper digits, city code) and moto_number (five lower digits), e.g. «۵۸۳-۷۶۲۲۴» → moto_city=583, moto_number=76224, and leave the car plate fields empty.
If a vehicle has no plate, leave the plate fields empty and write chassis_no.
Other fields: insurance_type ("ثالث", "بدنه" or "هردو"), expiry_date (expiry of the current policy, Jalali like 1405/07/30), car_name, model_year, chassis_no, engine_no, prev_policy_number, prev_insurer;
only for third party: liability_limit (Rials); only for body: car_value (Rials), has_prev_body ("بله"/"خیر"), health_inspection ("لازم"/"لازم نیست");
for change/cancel: policy_number, change_request or cancellation_reason; and note for anything else.
Write all digits as Latin 0-9. Omit any field that is not in the text. Never guess or invent values.
TXT;

const DS_DOC_PROMPT = <<<TXT
The user message is the OCR text of ONE scanned Iranian vehicle document page (Persian, may contain OCR mistakes). Identify it and extract fields.
Reply with ONE JSON object only, no prose.
kind: "sales_invoice" (سند/فاکتور فروش وسیله نقلیه از کارخانه یا نمایندگی), "ownership_doc" (سند مالکیت / برگ سبز), "car_card" (کارت خودرو), "policy" (بیمه‌نامه) or "unknown".
Fields (omit when not present; digits as Latin 0-9; keep Persian text in Persian):
vin (شماره شاسی / VIN, Latin letters and digits only), engine_no (شماره موتور, Latin letters and digits only), owner_name (نام خریدار / مالک; for a company the company name),
national_id (کد ملی or شناسه ملی of that owner, 10 or 11 digits), car_system, car_tip, usage, car_name, model_year, color, fuel, capacity, cylinders, axles, wheels, cab_no,
plate (as "NNایران - NNN X NN"), mobile, phone, postal_code, address, doc_no, doc_date (Jalali YYYY/MM/DD), economic_code, seller.
Never guess or invent values.
TXT;

// متنِ تمیز برای فرستادن: فقط متنِ همان فایل، با سقفِ طول
function ds_clean_text($t) {
    $t = str_replace(["\r\n", "\r"], "\n", (string)$t);
    $t = preg_replace('/[^\P{C}\n\t]/u', '', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return mb_substr(trim($t), 0, DS_MAX_TEXT);
}

// یک فراخوانیِ متنی به هوشِ مصنوعی (بدونِ ابزار، بدونِ تاریخچه) => آرایه‌ی JSON یا خطا
function ds_ai_json($pdo, $system, $text, $maxTokens = 4096) {
    if (!vdoc_ai_ready($pdo)) return ['ok' => false, 'error' => 'سرویسِ هوشِ مصنوعی تنظیم نشده (تنظیمات ← کلیدهای API ← خواندنِ اسکن / GapGPT).'];
    $text = ds_clean_text($text);
    if (mb_strlen($text) < 8) return ['ok' => false, 'error' => 'متنی از فایل خوانده نشد.'];
    $c = vdoc_ai_conf($pdo);
    $body = ['model' => $c['model'], 'temperature' => 0, 'max_tokens' => $maxTokens, 'messages' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $text],
    ]];
    $t0 = microtime(true);
    $res = sms_http('POST', $c['base'] . '/chat/completions', json_encode($body, JSON_UNESCAPED_UNICODE),
                    ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $c['token']], max(60, $c['timeout']));
    $ms = intval((microtime(true) - $t0) * 1000);
    if ($res['error'] !== '' || $res['http'] < 200 || $res['http'] >= 300) {
        $j = json_decode((string)$res['body'], true);
        $msg = is_array($j) ? (string)($j['error']['message'] ?? ($j['message'] ?? '')) : '';
        return ['ok' => false, 'ms' => $ms, 'error' => 'هوشِ مصنوعی پاسخ نداد' . ($res['error'] !== '' ? ': ' . $res['error'] : ' (HTTP ' . $res['http'] . ($msg !== '' ? ' — ' . mb_substr($msg, 0, 160) : '') . ')')];
    }
    $j = json_decode((string)$res['body'], true);
    $txt = $j['choices'][0]['message']['content'] ?? '';
    if (is_array($txt)) $txt = implode('', array_map(function ($p) { return (string)($p['text'] ?? ''); }, $txt));
    $out = preg_match('/\{[\s\S]*\}/u', (string)$txt, $m) ? json_decode($m[0], true) : null;
    if (!is_array($out)) return ['ok' => false, 'ms' => $ms, 'error' => 'پاسخِ هوشِ مصنوعی JSONِ قابلِ استفاده نبود.'];
    return ['ok' => true, 'ms' => $ms, 'json' => $out];
}

// نامه => JSONِ ردیف‌ها (همان قالبِ «ورود ردیف‌ها از نامه»؛ پیش‌نمایش و ثبت با همان مسیرِ قبلی)
function ds_letter_rows($pdo, $text) {
    $r = ds_ai_json($pdo, DS_LETTER_PROMPT, $text, 8000);
    if (!$r['ok']) return $r;
    $j = $r['json'];
    $rows = array_values(array_filter((array)($j['rows'] ?? []), 'is_array'));
    // فقط فیلدهای شناخته‌شده‌ی قالب، متنِ ساده و کوتاه
    $keys = ['request', 'plate_two', 'plate_letter', 'plate_three', 'plate_iran', 'moto_city', 'moto_number', 'insurance_type', 'expiry_date', 'car_name', 'car_type', 'model_year',
             'chassis_no', 'engine_no', 'prev_policy_number', 'prev_insurer', 'liability_limit', 'car_value', 'has_prev_body', 'health_inspection', 'policy_number', 'change_request',
             'cancellation_reason', 'note'];
    $clean = [];
    foreach (array_slice($rows, 0, 500) as $x) {
        $o = [];
        foreach ($keys as $k) if (isset($x[$k]) && is_scalar($x[$k]) && trim((string)$x[$k]) !== '') $o[$k] = mb_substr(trim(strip_tags((string)$x[$k])), 0, 300);
        if ($o) $clean[] = $o;
    }
    $counts = [];
    foreach ((array)($j['letter']['counts'] ?? []) as $k => $v) if (in_array($k, ['ثالث', 'بدنه', 'تغییر اطلاعات', 'فسخ'], true) && is_numeric($v)) $counts[$k] = max(0, intval($v));
    $out = ['rows' => $clean];
    if ($counts) $out['letter'] = ['counts' => $counts];
    return ['ok' => true, 'ms' => $r['ms'], 'raw' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'count' => count($clean)];
}

// مدرک => همان ساختارِ خواننده (vdoc_ai_normalize)؛ با برچسبِ موتور
function ds_doc_result($pdo, $text) {
    $r = ds_ai_json($pdo, DS_DOC_PROMPT, $text, 1200);
    if (!$r['ok']) return ['ok' => false, 'scan' => true, 'engines' => ['ocr', 'ai'], 'error' => $r['error']];
    $n = vdoc_ai_normalize($r['json']);
    $n['engines'] = ['ocr', 'ai'];
    $n['smart'] = 1;
    return $n;
}
