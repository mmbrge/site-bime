<?php
// فایل: api/_doc_smart.php
// «پردازشِ هوشمند»: OCR ← پرامپتِ مخصوصِ هر نوع مدرک ← اطلاعاتِ ساختاریافته ← سایت خودش جای‌گذاری می‌کند.
//   - هر نوع مدرک (کارت خودرو، سند مالکیت، فاکتور فروش، بیمه‌نامه، نامه‌ی درخواست، معرفی‌نامه/بیمه‌نامه‌ی پرسنلی، متنِ آزاد)
//     پرامپتِ خودش را دارد که می‌گوید از متن چه فیلدهایی لازم است؛ پرامپت‌ها در «استعلامات ← تنظیمات ← پردازش فایل هوشمند» قابلِ ویرایش‌اند.
//   - اگر نوع معلوم نیست، اول یک پرامپتِ کوتاه نوع را تشخیص می‌دهد.
//   - ds_smart_file: OCR روی هاست (متنِ خودِ PDF اگر دارد؛ وگرنه i2pdf / هوشِ مصنوعیِ تصویری / OCR.space به ترتیبِ تنظیمات) + استخراج
//     — برای پردازشِ خودکارِ مدارکِ شرکت‌ها، ثبتِ دستیِ درخواست، و مدارکِ پرسنلی (معرفی‌نامه/بیمه‌نامه‌ی اسکن‌شده).
// سدِ هوشِ مصنوعی: هیچ ابزار/دسترسی‌ای به دیتابیس یا فایل‌ها ندارد؛ فقط متنِ همان یک فایل را می‌بیند
// و خروجی‌اش مستقیم در دیتابیس نوشته نمی‌شود (اول اعتبارسنجی و پاک‌سازی، و فقط خانه‌های خالی پر می‌شوند).
require_once __DIR__ . '/_vdoc_ai.php';

const DS_MAX_TEXT = 60000;
const DS_TAIL = "\nWrite all digits as Latin 0-9. Keep Persian text in Persian. Omit any field that is not in the text. Never guess or invent values. Reply with ONE JSON object only (no prose, no code fence).";
const DS_PLATE = 'plate in four fields: plate_two (two digits at the left of the plate), plate_letter (the Persian letter), plate_three (three digits), plate_iran (two digits next to «ایران»); a plate written like «994ط59 78» or «۷۹۸ع۸۱-۱۱» means plate_three=994, plate_letter=ط, plate_two=59, plate_iran=78 — never swap the Iran code with the first two digits';

// نوع => [برچسب, پرامپتِ پیش‌فرض, فیلدهای قابلِ نمایش]
function ds_types() {
    $veh = ['vin' => 'شماره شاسی / VIN', 'engine_no' => 'شماره موتور', 'plate' => 'پلاک', 'owner_name' => 'مالک / خریدار', 'national_id' => 'کد / شناسه ملی',
            'car_system' => 'سیستم', 'car_tip' => 'تیپ', 'usage' => 'نوع کاربری', 'car_name' => 'نام خودرو', 'model_year' => 'مدل', 'color' => 'رنگ', 'fuel' => 'سوخت',
            'capacity' => 'ظرفیت', 'cylinders' => 'سیلندر', 'axles' => 'محور', 'wheels' => 'چرخ', 'cab_no' => 'شماره اتاق', 'mobile' => 'موبایل', 'phone' => 'تلفن',
            'postal_code' => 'کد پستی', 'address' => 'نشانی', 'doc_no' => 'شماره سند', 'doc_date' => 'تاریخ سند', 'economic_code' => 'کد اقتصادی', 'seller' => 'فروشنده',
            'policy_number' => 'شماره بیمه‌نامه', 'insurer' => 'بیمه‌گر', 'policy_type' => 'نوع بیمه', 'start_date' => 'شروع', 'end_date' => 'انقضا',
            'liability' => 'تعهد مالی', 'car_value' => 'ارزش خودرو', 'premium' => 'حق بیمه'];
    return [
        'car_card' => ['کارت خودرو', "The user message is the OCR text of an Iranian vehicle card (کارت خودرو, front and/or back). Extract: " . DS_PLATE
            . "; vin (شماره شاسی / VIN, Latin letters and digits only), engine_no (شماره موتور, Latin letters and digits only), owner_name (نام مالک), national_id (کد ملی / شناسه ملی مالک),"
            . " car_system (سیستم), car_tip (تیپ), usage (نوع / کاربری), model_year (مدل), color (رنگ), fuel (سوخت), capacity (ظرفیت), cylinders (تعداد سیلندر), axles (تعداد محور), wheels (تعداد چرخ)." . DS_TAIL, $veh],
        'ownership_doc' => ['سند مالکیت (برگ سبز)', "The user message is the OCR text of an Iranian vehicle ownership document (سند مالکیت / برگ سبز). Extract: owner_name (نام مالک; for a company the company name), national_id (کد ملی / شناسه ملی مالک), "
            . DS_PLATE . "; vin (شماره شاسی), engine_no (شماره موتور), car_system, car_tip, usage, model_year, color, fuel, capacity, cylinders, axles, wheels, doc_no (شماره سند), doc_date (تاریخ, Jalali YYYY/MM/DD), address (نشانی مالک), postal_code, mobile." . DS_TAIL, $veh],
        'sales_invoice' => ['سند / فاکتور فروش', "The user message is the OCR text of an Iranian vehicle sales document (سند فروش / فاکتور فروش از کارخانه یا نمایندگی). Extract: vin (شماره شاسی), engine_no (شماره موتور; drop * . - and spaces), cab_no (شماره اتاق),"
            . " owner_name (نام خریدار / مالک; for a company the company name), national_id (کد ملی / شناسه ملی خریدار, 10 or 11 digits), car_system (سیستم), car_tip (تیپ), usage (نوع کاربری), model_year (مدل), color, fuel, capacity, cylinders, axles, wheels,"
            . " doc_no (شماره سند / فاکتور), doc_date (تاریخ, Jalali YYYY/MM/DD), seller (فروشنده), economic_code (کد اقتصادیِ فروشنده), address (نشانی خریدار), postal_code (کد پستی خریدار), mobile (تلفن همراه), phone." . DS_TAIL, $veh],
        'policy' => ['بیمه‌نامه‌ی خودرو', "The user message is the OCR text of an Iranian vehicle insurance policy (بیمه‌نامه ثالث یا بدنه). Extract: policy_number (شماره بیمه‌نامه), insurer (نام بیمه‌گر), policy_type (\"THIRDPARTY\" for شخص ثالث or \"BODY\" for بدنه),"
            . " start_date and end_date (Jalali YYYY/MM/DD), owner_name (بیمه‌گذار / مالک), national_id, vin (شماره شاسی), engine_no (شماره موتور), " . DS_PLATE . "; car_system, car_tip, model_year, liability (تعهد مالی, Rials, digits only), car_value (ارزش خودرو, Rials), premium (حق بیمه, Rials)." . DS_TAIL, $veh],
        'letter' => ['نامه‌ی درخواستِ بیمه (ردیف‌ها)', "The user message is the OCR text of an Iranian insurance request letter (Persian, may contain OCR mistakes and tables flattened into lines).\n"
            . "Return {\"letter\": {\"counts\": {\"ثالث\": 0, \"بدنه\": 0, \"تغییر اطلاعات\": 0, \"فسخ\": 0}}, \"rows\": [ ... ]}. In \"letter.counts\" write how many of each kind the letter requests. In \"rows\" make ONE row per vehicle with:\n"
            . "request (\"صدور\", \"تغییر اطلاعات\" or \"فسخ\"); " . DS_PLATE . ".\nFor a motorcycle write moto_city (three upper digits) and moto_number (five lower digits) and leave the car plate fields empty. If a vehicle has no plate, leave the plate fields empty and write chassis_no.\n"
            . "Other fields: insurance_type (\"ثالث\", \"بدنه\" or \"هردو\"), expiry_date (Jalali like 1405/07/30), car_name, model_year, chassis_no, engine_no, prev_policy_number, prev_insurer; only for third party: liability_limit (Rials); only for body: car_value (Rials), has_prev_body (\"بله\"/\"خیر\"), health_inspection (\"لازم\"/\"لازم نیست\"); for change/cancel: policy_number, change_request or cancellation_reason; and note for anything else." . DS_TAIL,
            ['rows' => 'ردیف‌ها']],
        'personnel' => ['معرفی‌نامه / بیمه‌نامه‌ی پرسنلی', "The user message is the OCR text of an Iranian staff document: either a personnel introduction letter (معرفی‌نامه) or an insurance policy. Extract: ins_type (\"معرفی‌نامه\" for an introduction letter, otherwise the policy kind e.g. \"ثالث\" or \"بدنه\"),"
            . " insured_name (نام و نام خانوادگیِ شخص, without آقای/خانم), national_id (کد ملی, 10 digits), personnel_id (شماره پرسنلی), company_name (نام شرکتِ محلِ کار; without the word شرکت), letter_date (Jalali YYYY/MM/DD), letter_no (شماره نامه),"
            . " ins_company (بیمه‌گر), policy_num (شماره بیمه‌نامه), vin (شماره شاسی), plate (as written), premium (Rials), start_date, end_date." . DS_TAIL,
            ['ins_type' => 'نوع', 'insured_name' => 'نام شخص', 'national_id' => 'کد ملی', 'personnel_id' => 'شماره پرسنلی', 'company_name' => 'شرکت', 'letter_date' => 'تاریخ نامه', 'letter_no' => 'شماره نامه',
             'ins_company' => 'بیمه‌گر', 'policy_num' => 'شماره بیمه‌نامه', 'vin' => 'شاسی', 'plate' => 'پلاک', 'premium' => 'حق بیمه', 'start_date' => 'شروع', 'end_date' => 'انقضا']],
        'generic' => ['متنِ آزاد (همه‌ی اطلاعاتِ مهم)', "The user message is OCR text of any document. Extract every important labeled value as a flat JSON object whose keys are short Persian labels exactly as they appear (e.g. \"شماره سند\", \"تاریخ\", \"نام\") and whose values are the values. Also add \"نوع سند\" with a short Persian description of the document." . DS_TAIL, []],
    ];
}
const DS_CLASSIFY = 'The user message is OCR text of one scanned document. Classify it. Reply with ONE JSON object only: {"kind": K} where K is one of: "car_card" (کارت خودرو), "ownership_doc" (سند مالکیت / برگ سبز), "sales_invoice" (سند / فاکتور فروش وسیله نقلیه), "policy" (بیمه‌نامه‌ی خودرو), "letter" (نامه‌ی درخواستِ صدور/تمدید/فسخِ بیمه برای چند خودرو), "personnel" (معرفی‌نامه‌ی پرسنلی), "unknown".';

// نوعِ مدرکِ سایت ← نوعِ پرامپت
function ds_type_of_doc($docType) {
    $m = ['car_card_front' => 'car_card', 'car_card_back' => 'car_card', 'new_car_card' => 'car_card', 'car_card_or_title' => '', 'ownership_doc' => 'ownership_doc', 'new_ownership_doc' => 'ownership_doc',
          'sales_invoice' => 'sales_invoice', 'prev_third_policy' => 'policy', 'prev_body_policy' => 'policy', 'policy_doc' => 'policy', 'letter' => 'letter'];
    return $m[(string)$docType] ?? '';
}
function ds_prompt($pdo, $type) {
    $t = ds_types();
    if (!isset($t[$type])) return '';
    $c = (api_cfg($pdo)['smart_ocr'] ?? [])['prompts'][$type] ?? '';
    return trim((string)$c) !== '' ? (string)$c : $t[$type][1];
}

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

// تشخیصِ نوع (اگر معلوم نیست)
function ds_classify($pdo, $text) {
    $r = ds_ai_json($pdo, DS_CLASSIFY, mb_substr((string)$text, 0, 6000), 60);
    $k = $r['ok'] ? (string)($r['json']['kind'] ?? '') : '';
    return isset(ds_types()[$k]) ? $k : 'unknown';
}

// نامه ← JSONِ ردیف‌ها (همان قالبِ «ورود ردیف‌ها از نامه»؛ پیش‌نمایش و ثبت با همان مسیرِ قبلی)
function ds_letter_rows($pdo, $text) {
    $r = ds_ai_json($pdo, ds_prompt($pdo, 'letter'), $text, 8000);
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
    return ['ok' => true, 'ms' => $r['ms'], 'raw' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'count' => count($clean), 'rows' => $clean, 'counts' => $counts];
}

// مدرکِ پرسنلی ← همان کلیدهای خواننده‌ی معرفی‌نامه/بیمه‌نامه (run_document_ocr_verbose)
function ds_personnel_data(array $o) {
    $txt = function ($v) { return trim(preg_replace('/\s+/u', ' ', p2e_digits(str_replace(['ي', 'ك'], ['ی', 'ک'], strip_tags((string)$v))))); };
    $d = [];
    foreach (['ins_type', 'insured_name', 'company_name', 'ins_company', 'plate', 'letter_no', 'policy_num'] as $k) if (isset($o[$k]) && is_scalar($o[$k]) && ($v = $txt($o[$k])) !== '') $d[$k] = mb_substr($v, 0, 190);
    foreach (['national_id', 'personnel_id', 'premium'] as $k) if (isset($o[$k]) && ($v = preg_replace('/\D/', '', p2e_digits((string)$o[$k]))) !== '') $d[$k] = $v;
    if (isset($d['national_id']) && strlen($d['national_id']) < 10) $d['national_id'] = str_pad($d['national_id'], 10, '0', STR_PAD_LEFT);
    if (isset($o['vin']) && ($v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$o['vin'])))) !== '') $d['vin'] = $v;
    foreach (['letter_date', 'start_date', 'end_date'] as $k) if (!empty($o[$k]) && preg_match('/(1[34]\d\d)\D+(\d{1,2})\D+(\d{1,2})/', p2e_digits((string)$o[$k]), $m)) $d[$k] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    if (function_exists('intro_clean_fields')) $d = intro_clean_fields($d);
    return $d;
}

// استخراج با پرامپتِ نوع: type = نوعِ پرامپت یا 'auto'
//   خروجی: ok, type, type_fa, data (کلیدهای سایت), fields ([برچسب => مقدار] برای نمایش), result (برای مدارکِ خودرو: همان شکلِ vdr_read)
function ds_extract($pdo, $type, $text) {
    $types = ds_types();
    if ($type === 'auto' || $type === '' || !isset($types[$type])) {
        $type = ds_classify($pdo, $text);
        if ($type === 'unknown') $type = 'generic';
    }
    if ($type === 'letter') {
        $r = ds_letter_rows($pdo, $text);
        if (!$r['ok']) return $r + ['type' => $type];
        $fields = ['تعدادِ ردیف' => (string)$r['count']];
        foreach ($r['counts'] as $k => $v) $fields['تعدادِ درخواستیِ ' . $k] = (string)$v;
        return ['ok' => true, 'type' => $type, 'type_fa' => $types[$type][0], 'data' => ['rows' => $r['rows'], 'counts' => $r['counts']], 'fields' => $fields, 'raw' => $r['raw']];
    }
    $r = ds_ai_json($pdo, ds_prompt($pdo, $type), $text, $type === 'generic' ? 3000 : 1500);
    if (!$r['ok']) return $r + ['type' => $type];
    $o = $r['json'];
    $labels = $types[$type][2];
    if ($type === 'generic') {
        $d = [];
        foreach ($o as $k => $v) if (is_scalar($v) && trim((string)$v) !== '') $d[mb_substr(strip_tags((string)$k), 0, 80)] = mb_substr(trim(strip_tags((string)$v)), 0, 500);
        return ['ok' => true, 'type' => $type, 'type_fa' => $types[$type][0], 'data' => $d, 'fields' => $d];
    }
    if ($type === 'personnel') {
        $d = ds_personnel_data($o);
    } else {
        $o['kind'] = $type;
        $res = vdoc_ai_normalize($o);
        $res['engines'] = ['ocr', 'ai']; $res['smart'] = 1;
        $d = $res['data'];
    }
    $fields = [];
    foreach ($labels as $k => $l) if (isset($d[$k]) && is_scalar($d[$k]) && $d[$k] !== '') $fields[$l] = $k === 'policy_type' ? ($d[$k] === 'BODY' ? 'بدنه' : 'ثالث') : (string)$d[$k];
    $out = ['ok' => true, 'type' => $type, 'type_fa' => $types[$type][0], 'data' => $d, 'fields' => $fields, 'ms' => $r['ms']];
    if (isset($res)) $out['result'] = $res;
    return $out;
}

// مدرکِ خودرو (صندوقِ مدارک / ثبتِ دستی) ← همان شکلِ vdr_read؛ $docType = پیشنهادِ نوع (اختیاری)
function ds_doc_result($pdo, $text, $docType = '') {
    $type = ds_type_of_doc($docType) ?: 'auto';
    $x = ds_extract($pdo, $type, $text);
    if (!$x['ok']) return ['ok' => false, 'scan' => true, 'engines' => ['ocr', 'ai'], 'error' => $x['error'] ?? 'استخراج نشد.'];
    if (empty($x['result'])) return ['ok' => false, 'scan' => true, 'engines' => ['ocr', 'ai'], 'error' => 'این فایل مدرکِ خودرو شناخته نشد (' . ($x['type_fa'] ?? '') . ').', 'smart_type' => $x['type']];
    return $x['result'];
}

// متنِ یک صفحه‌ی فایل روی هاست: متنِ خودِ PDF (اگر دارد) وگرنه OCR با موتورهای سروری
function ds_page_text($pdo, $abs, $page = 1) {
    if (upload_ext($abs) === 'pdf') {
        try {
            require_once __DIR__ . '/_pdf_text.php';
            $doc = PdfDoc::open($abs); $pt = new PdfText($doc);
            $t = $doc->pageCount() >= $page ? (string)$pt->text($page - 1) : '';
            if (mb_strlen(preg_replace('/\s+/u', '', $t)) >= 40) return ['ok' => true, 'text' => $t, 'engine' => 'text'];
        } catch (Throwable $e) {}
    }
    require_once __DIR__ . '/_smart_ocr.php';
    return so_file_text($pdo, $abs, $page);
}
// همه‌ی کار روی هاست: متن ← نوع ← استخراج (برای مدارکِ خودرو؛ شکلِ vdr_read)
function ds_smart_file($pdo, $abs, $page = 1, $docType = '') {
    @set_time_limit(300);
    $t = ds_page_text($pdo, $abs, $page);
    if (empty($t['ok'])) return ['ok' => false, 'scan' => true, 'engines' => ['ocr'], 'error' => $t['error'] ?? 'متنی خوانده نشد.'];
    $r = ds_doc_result($pdo, $t['text'], $docType);
    $r['ocr_engine'] = $t['engine'] ?? '';
    return $r;
}
// پردازشِ هوشمند روشن و موتورِ لازم آماده است؟ (تنظیمات ← پردازش فایل هوشمند ← «اگر متنِ PDF خوانده نشد…»)
function ds_smart_available($pdo) {
    if (!vdoc_ai_ready($pdo)) return false;
    require_once __DIR__ . '/_smart_ocr.php';
    if (!so_cfg($pdo)['auto']) return false;
    $e = so_engines($pdo);
    return !empty($e['i2pdf']) || !empty($e['ai']) || !empty($e['ocrspace']);
}
