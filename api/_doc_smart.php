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
const DS_TAIL = "\nهمه‌ی ارقام را با رقمِ لاتین (0-9) بنویس و متنِ فارسی را فارسی نگه دار. هر فیلدی که در متن نیست را نگذار. هیچ مقداری را حدس نزن و از خودت نساز. فقط و فقط یک شیءِ JSON برگردان (بدونِ هیچ توضیح و بدونِ ```).";
const DS_PLATE = 'پلاک در چهار فیلدِ جدا: plate_two (دو رقمِ سمتِ چپِ پلاک)، plate_letter (حرفِ فارسیِ پلاک)، plate_three (سه رقم)، plate_iran (دو رقمِ کنارِ کلمه‌ی «ایران»). پلاکی که به شکلِ «994ط59 78» یا «۷۹۸ع۸۱-۱۱» نوشته شده یعنی plate_three=994، plate_letter=ط، plate_two=59، plate_iran=78؛ هیچ‌وقت کدِ ایران را با دو رقمِ اول جابه‌جا نکن';
const DS_CODE = 'در شماره شاسی (vin) و شماره موتور (engine_no) همه‌ی نویسه‌های اضافه مثلِ * - / . _ | و فاصله را حذف کن و فقط حروفِ لاتین و ارقام را پشتِ سرِ هم برگردان (مثلاً «MC13.48-50*260117005757*» ← «MC134850260117005757»)';

// نوع => [برچسب, پرامپتِ پیش‌فرض, فیلدهای قابلِ نمایش]
function ds_types() {
    $veh = ['chassis_no' => 'شماره شاسی', 'vin' => 'VIN', 'engine_no' => 'شماره موتور', 'cab_no' => 'شماره اتاق', 'plate' => 'پلاک',
            'owner_name' => 'مالک / خریدار', 'national_id' => 'کد / شناسه ملیِ مالک', 'father_name' => 'نام پدر', 'mobile' => 'موبایل', 'phone' => 'تلفن',
            'postal_code' => 'کد پستی', 'address' => 'نشانی', 'car_system' => 'سیستم', 'car_tip' => 'تیپ', 'car_kind' => 'نوع وسیله', 'usage' => 'کاربری',
            'car_name' => 'نام خودرو', 'model_year' => 'مدل', 'color' => 'رنگ', 'fuel' => 'سوخت', 'capacity' => 'ظرفیت', 'cylinders' => 'سیلندر',
            'axles' => 'محور', 'wheels' => 'چرخ', 'doc_no' => 'شماره سند / فاکتور', 'doc_date' => 'تاریخ سند', 'seller' => 'فروشنده', 'economic_code' => 'کد اقتصادی',
            'policy_number' => 'شماره بیمه‌نامه', 'insurer' => 'بیمه‌گر', 'policy_type' => 'نوع بیمه', 'start_date' => 'شروع', 'end_date' => 'انقضا',
            'liability' => 'تعهد مالی', 'car_value' => 'ارزش خودرو', 'premium' => 'حق بیمه'];
    // فیلدهای مشترکِ مدارکِ خودرو (هر کدام جدا)
    $car = 'شماره‌ها هر کدام جدا: chassis_no (شماره شاسی همان‌طور که نوشته شده)، vin (فقط شماره‌ی ۱۷ نویسه‌ایِ VIN اگر جدا نوشته شده؛ اگر فقط یک «شماره شاسی» هست و ۱۷ نویسه است، همان را در هر دو بنویس)، engine_no (شماره موتور)، cab_no (شماره اتاق، اگر هست). '
         . 'مشخصاتِ خودرو هر کدام جدا: car_system (سیستم، مثلِ پژو)، car_tip (تیپ، مثلِ پارس TU5)، car_kind (نوعِ وسیله، مثلِ سواری/کامیونت/کامیون)، usage (کاربری، مثلِ شخصی/باری)، model_year (مدل / سال ساخت)، color (رنگ)، fuel (سوخت)، capacity (ظرفیت)، cylinders (تعداد سیلندر)، axles (تعداد محور)، wheels (تعداد چرخ). '
         . 'مالک هر کدام جدا: owner_name (نام و نام خانوادگیِ مالک یا نامِ شرکت)، national_id (کد ملیِ ۱۰ رقمی یا شناسه‌ی ملیِ ۱۱ رقمیِ مالک)، father_name (نام پدر)، mobile (موبایل)، phone (تلفن ثابت)، postal_code (کد پستی)، address (نشانی). ';
    return [
        'car_card' => ['کارت خودرو', "پیامِ کاربر متنِ OCRِ کارتِ یک خودروی ایرانی (روی و/یا پشتِ کارت) است. این فیلدها را استخراج کن: " . DS_PLATE . "؛ " . $car . DS_CODE . "." . DS_TAIL, $veh],
        'ownership_doc' => ['سند مالکیت (برگ سبز)', "پیامِ کاربر متنِ OCRِ سندِ مالکیتِ یک خودروی ایرانی (برگ سبز) است. این فیلدها را استخراج کن: " . DS_PLATE . "؛ " . $car
            . "doc_no (شماره سند)، doc_date (تاریخِ سند، شمسی به شکلِ YYYY/MM/DD). " . DS_CODE . "." . DS_TAIL, $veh],
        'sales_invoice' => ['سند / فاکتور فروش', "پیامِ کاربر متنِ OCRِ سند فروش یا فاکتور فروشِ یک وسیله‌ی نقلیه (از کارخانه یا نمایندگی) است. این فیلدها را استخراج کن: " . $car
            . "در اینجا «مالک» همان خریدار است. doc_no (شماره سند / فاکتور)، doc_date (تاریخ، شمسی به شکلِ YYYY/MM/DD)، seller (فروشنده)، economic_code (کد اقتصادیِ فروشنده)، car_value (مبلغِ فاکتور به ریال، فقط رقم).\n"
            . "مهم: " . DS_CODE . "؛ شماره اتاق (cab_no) را هم همین‌طور پاک‌سازی کن. بعد از پاک‌سازی خروجی را برگردان." . DS_TAIL, $veh],
        'policy' => ['بیمه‌نامه‌ی خودرو', "پیامِ کاربر متنِ OCRِ یک بیمه‌نامه‌ی خودروی ایرانی (شخص ثالث یا بدنه) است. این فیلدها را استخراج کن: policy_number (شماره بیمه‌نامه)، insurer (نامِ بیمه‌گر)، policy_type (برای شخص ثالث \"THIRDPARTY\" و برای بدنه \"BODY\")،"
            . " start_date و end_date (تاریخِ شروع و انقضا، شمسی به شکلِ YYYY/MM/DD)؛ بیمه‌گذار در owner_name و national_id؛ " . DS_PLATE . "؛ " . $car
            . " liability (تعهدِ مالی به ریال، فقط رقم)، car_value (ارزشِ خودرو به ریال)، premium (حق بیمه به ریال). " . DS_CODE . "." . DS_TAIL, $veh],
        'letter' => ['نامه‌ی درخواستِ بیمه (ردیف‌ها)', DS_LETTER, ['rows' => 'ردیف‌ها']],
        'personnel' => ['معرفی‌نامه / بیمه‌نامه‌ی پرسنلی', "پیامِ کاربر متنِ OCRِ یک مدرکِ پرسنلی است: یا معرفی‌نامه‌ی پرسنلی، یا یک بیمه‌نامه. این فیلدها را استخراج کن: ins_type (برای معرفی‌نامه «معرفی‌نامه»، وگرنه نوعِ بیمه‌نامه مثلِ «بدنه» یا «ثالث»)،"
            . " insured_name (نام و نام خانوادگیِ شخص، بدونِ «آقای/خانم»)، national_id (کد ملی، ۱۰ رقم)، personnel_id (شماره پرسنلی)، company_name (نامِ شرکتِ محلِ کار، بدونِ کلمه‌ی «شرکت»)، letter_date (تاریخِ نامه، شمسی به شکلِ YYYY/MM/DD)، letter_no (شماره نامه)،"
            . " ins_company (بیمه‌گر)، policy_num (شماره بیمه‌نامه)، vin (شماره شاسی، بدونِ نویسه‌های اضافه)، plate (پلاک همان‌طور که نوشته شده)، premium (حق بیمه به ریال)، start_date و end_date (شروع و انقضا، شمسی)." . DS_TAIL,
            ['ins_type' => 'نوع', 'insured_name' => 'نام شخص', 'national_id' => 'کد ملی', 'personnel_id' => 'شماره پرسنلی', 'company_name' => 'شرکت', 'letter_date' => 'تاریخ نامه', 'letter_no' => 'شماره نامه',
             'ins_company' => 'بیمه‌گر', 'policy_num' => 'شماره بیمه‌نامه', 'vin' => 'شاسی', 'plate' => 'پلاک', 'premium' => 'حق بیمه', 'start_date' => 'شروع', 'end_date' => 'انقضا']],
        'generic' => ['متنِ آزاد (همه‌ی اطلاعاتِ مهم)', "پیامِ کاربر متنِ OCRِ هر نوع مدرکی است. همه‌ی مقدارهای مهم و برچسب‌دار را به شکلِ یک شیءِ JSONِ ساده برگردان که کلیدهایش برچسب‌های کوتاهِ فارسی دقیقاً مثلِ متن باشند (مثلاً «شماره سند»، «نام مالک»)، و مقدارها متن. ارقام لاتین." . DS_TAIL, []],
    ];
}

// نامه‌ی درخواست ← ردیف‌ها (هم برای «خواندنِ هوشمندِ نامه» و هم متنِ آماده برای هوشِ مصنوعیِ بیرونی)
const DS_LETTER = "پیامِ کاربر متنِ OCRِ یک نامه‌ی درخواستِ بیمه‌ی خودرو است (فارسی). ممکن است اشتباهِ OCR داشته باشد، ترتیبِ کلمه‌ها و ارقامِ راست‌به‌چپ به‌هم ریخته باشد و جدول‌ها به خط‌های پشتِ سرِ هم تبدیل شده باشند. "
    . "اول سرستون‌های جدول را پیدا کن (مثلِ ردیف، شماره پلاک، سری، نوع خودرو، شماره شاسی، مدل، ترموکینگ، شماره بیمه‌نامه‌ی قبلی، تاریخ اتمام بیمه‌نامه، ارزش سرمایه، قیمت به ریال، شماره پلاک قدیم/جدید) و هر خطِ جدول را با همان ترتیب به یک ردیف تبدیل کن؛ هیچ ردیفی را جا نینداز و چیزی از خودت نساز.\n"
    . "خروجی دقیقاً به این شکل: {\"letter\": {\"requester\": نامِ شرکتِ درخواست‌کننده (نویسنده‌ی نامه یا صاحبِ خودروها), \"number\": شماره نامه, \"date\": تاریخِ نامه (YYYY/MM/DD), \"counts\": {\"ثالث\": 0, \"بدنه\": 0, \"تغییر اطلاعات\": 0, \"فسخ\": 0}, \"note\": خواسته‌های کلیِ نامه مثلِ پوشش‌ها و تخفیف‌ها}, \"rows\": [ ... ]}.\n"
    . "هر ردیف این فیلدها را (فقط آن‌هایی که در نامه هست) دارد:\n"
    . "• request: «صدور» (بیمه‌ی جدید یا تمدید)، «تغییر اطلاعات» یا «فسخ».\n"
    . "• insurance_type: «ثالث»، «بدنه» یا «هردو» (از موضوع و متنِ نامه؛ اگر کلِ نامه درباره‌ی بیمه‌ی بدنه است، همه‌ی ردیف‌ها بدنه).\n"
    . "• پلاک در چهار فیلد: plate_two (دو رقمِ سمتِ چپ)، plate_letter (حرفِ فارسی)، plate_three (سه رقم)، plate_iran (کدِ ایران). «۳۱۹ع۵۷-۴۴» یعنی three=319، letter=ع، two=57، iran=44. «۷۸۴ق ۷۲ ایران۳۸» یعنی three=784، letter=ق، two=72، iran=38. "
    . "اگر جدول ستونِ جدای «سری» دارد، عددِ آن ستون plate_iran است و ستونِ «شماره پلاک» (مثلِ «۲۹۷ع۵۷») سه رقم، حرف و دو رقم را دارد. اگر «پلاکِ جدید» و «پلاکِ قدیم» هر دو هست، پلاکِ جدید را در فیلدهای پلاک و پلاکِ قدیم را در old_plate بنویس؛ «*****» یا «----» یعنی خالی.\n"
    . "• موتورسیکلت: moto_city (سه رقمِ کدِ شهر) و moto_number (پنج رقم)؛ خودروی بی‌پلاک (صفر کیلومتر/لیفتراک): فیلدهای پلاک خالی و شماره شاسی پر.\n"
    . "• شماره‌ها هر کدام جدا: chassis_no (شماره شاسی همان‌طور که نوشته شده، بدونِ * - / . و فاصله)، vin (فقط اگر VINِ ۱۷ نویسه‌ای جدا آمده)، engine_no (شماره موتور).\n"
    . "• خودرو: car_name (نوعِ خودرو همان‌طور که نوشته شده، مثلِ «پیلسان»، «یخچالدار ۱۲ چرخ»)، car_system، car_tip، car_kind (سواری/کامیونت/کامیون/...)، usage، model_year (مدل، مثلِ 1405 یا 1387)، color، capacity، cylinders؛ تجهیزاتِ اضافه مثلِ ترموکینگ/یخچال/لیفت‌بک در extras (متن).\n"
    . "• بیمه: expiry_date (تاریخِ اتمامِ بیمه‌نامه‌ی فعلی، شمسی YYYY/MM/DD)، prev_policy_number (شماره بیمه‌نامه‌ی قبلی)، prev_insurer، car_value (ارزشِ کلِ خودرو/سرمایه به ریال، فقط رقم؛ «۲۰۰/۰۰۰/۰۰۰/۰۰۰» یعنی 200000000000)، liability_limit (تعهدِ مالیِ ثالث به ریال)، "
    . "has_prev_body («بله»/«خیر»؛ اگر نامه می‌گوید خودروها «فاقدِ بیمه‌نامه‌ی بدنه» هستند یا ستونِ بیمه‌نامه‌ی قبلی خالی است و نامه درخواستِ بدنه است، «خیر»؛ اگر تمدید است «بله»)، health_inspection («لازم»/«لازم نیست»).\n"
    . "• اشخاص (اگر در نامه آمده): owner_name، owner_national_id، owner_phone، owner_address (مالکِ خودرو)؛ insured_name، insured_national_id (بیمه‌گذار، اگر با مالک فرق دارد).\n"
    . "• الحاقیه/فسخ: policy_number، change_request یا cancellation_reason؛ هر توضیحِ دیگرِ همان ردیف در note.\n"
    . "همه‌ی ارقام را لاتین (0-9) بنویس، متنِ فارسی را فارسی نگه دار، هیچ مقداری را حدس نزن و فیلدی که در نامه نیست را نگذار. فقط و فقط یک شیءِ JSON برگردان (بدونِ هیچ توضیح و بدونِ ```).";

const DS_CLASSIFY = 'پیامِ کاربر متنِ OCRِ یک مدرکِ اسکن‌شده است. نوعِ آن را تشخیص بده و فقط یک شیءِ JSON به شکلِ {"kind": K} برگردان که K یکی از این‌ها باشد: "car_card" (کارت خودرو)، "ownership_doc" (سند مالکیت / برگ سبز)، "sales_invoice" (سند / فاکتور فروشِ وسیله‌ی نقلیه)، "policy" (بیمه‌نامه‌ی خودرو)، "letter" (نامه‌ی درخواستِ صدور/تمدید/فسخِ بیمه برای چند خودرو)، "personnel" (معرفی‌نامه‌ی پرسنلی)، "unknown" (هیچ‌کدام).';

// نوعِ مدرکِ سایت ← نوعِ پرامپت
function ds_type_of_doc($docType) {
    $m = ['car_card_front' => 'car_card', 'car_card_back' => 'car_card', 'new_car_card' => 'car_card', 'car_card_or_title' => '', 'ownership_doc' => 'ownership_doc', 'new_ownership_doc' => 'ownership_doc',
          'sales_invoice' => 'sales_invoice', 'prev_third_policy' => 'policy', 'prev_body_policy' => 'policy', 'policy_doc' => 'policy', 'letter' => 'letter'];
    return $m[(string)$docType] ?? '';
}
// پرامپتِ نهایی: پرامپتِ اختصاصیِ همان شرکت (اگر تنظیم شده) ← پرامپتِ کلیِ تنظیمات ← پیش‌فرض
function ds_prompt($pdo, $type, $companyId = 0) {
    $t = ds_types();
    if (!isset($t[$type])) return '';
    $cfg = api_cfg($pdo)['smart_ocr'] ?? [];
    $cp = $companyId ? (string)($cfg['company_prompts'][(string)intval($companyId)][$type] ?? '') : '';
    if (trim($cp) !== '') return $cp;
    $c = (string)($cfg['prompts'][$type] ?? '');
    return trim($c) !== '' ? $c : $t[$type][1];
}
// شرکت‌هایی که پرامپتِ اختصاصی دارند: [company_id => [نوع‌ها]]
function ds_company_prompt_map($pdo) {
    $m = [];
    foreach ((array)((api_cfg($pdo)['smart_ocr'] ?? [])['company_prompts'] ?? []) as $cid => $list) { $k = array_keys(array_filter((array)$list, function ($v) { return trim((string)$v) !== ''; })); if ($k) $m[(int)$cid] = $k; }
    return $m;
}

// متنِ تمیز برای فرستادن: فقط متنِ همان فایل، با سقفِ طول
function ds_clean_text($t) {
    $t = str_replace(["\r\n", "\r"], "\n", (string)$t);
    $t = preg_replace('/[^\P{C}\n\t]/u', '', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return mb_substr(trim($t), 0, DS_MAX_TEXT);
}

// JSON از پاسخِ هوشِ مصنوعی، حتی اگر داخلِ ```json بود، ویرگولِ اضافه داشت یا وسطش بریده شده بود (پاسخِ ناتمام)
function ds_json_extract($txt) {
    $t = trim(str_replace(["\u{201C}", "\u{201D}", "\u{FEFF}"], ['"', '"', ''], (string)$txt));
    $t = preg_replace('/^```[a-zA-Z]*\s*|\s*```\s*$/m', '', $t);
    $s = strpos($t, '{');
    if ($s === false) return null;
    $t = substr($t, $s);
    $fix = function ($x) { return preg_replace('/,\s*([}\]])/', '$1', $x); };
    // ۱) تا آخرین «}» (و چند «}» قبل‌تر)
    $end = strlen($t);
    for ($i = 0; $i < 12; $i++) {
        $p = strrpos(substr($t, 0, $end), '}');
        if ($p === false) break;
        $c = substr($t, 0, $p + 1);
        foreach ([$c, $fix($c)] as $x) { $j = json_decode($x, true); if (is_array($j)) return $j; }
        $end = $p;
    }
    // ۲) پاسخِ بریده‌شده: عنصرِ ناتمامِ آخر حذف و پرانتزها بسته می‌شوند
    $stack = []; $inStr = false; $esc = false; $lastSafe = 0;
    for ($i = 0, $n = strlen($t); $i < $n; $i++) {
        $ch = $t[$i];
        if ($inStr) { if ($esc) $esc = false; elseif ($ch === '\\') $esc = true; elseif ($ch === '"') $inStr = false; continue; }
        if ($ch === '"') { $inStr = true; continue; }
        if ($ch === '{' || $ch === '[') $stack[] = $ch;
        elseif ($ch === '}' || $ch === ']') { array_pop($stack); $lastSafe = $i + 1; }
        elseif ($ch === ',') $lastSafe = $i;
    }
    $c = rtrim(substr($t, 0, $lastSafe ?: strlen($t)), ", \n\r\t");
    // پرانتزهای بازِ تا این نقطه
    $stack = []; $inStr = false; $esc = false;
    for ($i = 0, $n = strlen($c); $i < $n; $i++) {
        $ch = $c[$i];
        if ($inStr) { if ($esc) $esc = false; elseif ($ch === '\\') $esc = true; elseif ($ch === '"') $inStr = false; continue; }
        if ($ch === '"') $inStr = true; elseif ($ch === '{' || $ch === '[') $stack[] = $ch; elseif ($ch === '}' || $ch === ']') array_pop($stack);
    }
    if ($inStr) return null;
    while ($stack) $c .= array_pop($stack) === '{' ? '}' : ']';
    $j = json_decode($fix($c), true);
    return is_array($j) ? $j : null;
}

// یک فراخوانیِ متنی به هوشِ مصنوعی (بدونِ ابزار، بدونِ تاریخچه) => آرایه‌ی JSON یا خطا
//   اول با response_format=json_object (اگر سرویس قبول نکرد، بدونِ آن)؛ پاسخِ خالی/بریده (finish_reason=length) با سقفِ بیشتر دوباره
function ds_ai_json($pdo, $system, $text, $maxTokens = 4096) {
    if (!vdoc_ai_ready($pdo)) return ['ok' => false, 'error' => 'سرویسِ هوشِ مصنوعی تنظیم نشده (تنظیمات ← کلیدهای API ← خواندنِ اسکن / GapGPT).'];
    $text = ds_clean_text($text);
    if (mb_strlen($text) < 8) return ['ok' => false, 'error' => 'متنی از فایل خوانده نشد.'];
    $c = vdoc_ai_conf($pdo);
    $maxTokens = max(1500, (int)$maxTokens);
    $useFmt = true; $t0 = microtime(true); $last = '';
    for ($try = 0; $try < 3; $try++) {
        $body = ['model' => $c['model'], 'temperature' => 0, 'max_tokens' => $maxTokens, 'messages' => [
            ['role' => 'system', 'content' => $system . "\nخروجی فقط یک شیءِ JSONِ معتبر (با کلیدها و رشته‌های داخلِ \"\") باشد؛ بدونِ ``` و بدونِ هیچ متنِ دیگر."],
            ['role' => 'user', 'content' => $text],
        ]];
        if ($useFmt) $body['response_format'] = ['type' => 'json_object'];
        $res = sms_http('POST', $c['base'] . '/chat/completions', json_encode($body, JSON_UNESCAPED_UNICODE),
                        ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $c['token']], max(90, $c['timeout']));
        $ms = intval((microtime(true) - $t0) * 1000);
        if ($res['error'] !== '' || $res['http'] < 200 || $res['http'] >= 300) {
            $j = json_decode((string)$res['body'], true);
            $msg = is_array($j) ? (string)($j['error']['message'] ?? ($j['message'] ?? '')) : '';
            if ($useFmt && $res['error'] === '' && $res['http'] >= 400 && $res['http'] < 500) { $useFmt = false; continue; }   // سرویس response_format را نمی‌شناسد
            return ['ok' => false, 'ms' => $ms, 'error' => 'هوشِ مصنوعی پاسخ نداد' . ($res['error'] !== '' ? ': ' . $res['error'] : ' (HTTP ' . $res['http'] . ($msg !== '' ? ' — ' . mb_substr($msg, 0, 160) : '') . ')')];
        }
        $j = json_decode((string)$res['body'], true);
        $txt = $j['choices'][0]['message']['content'] ?? '';
        if (is_array($txt)) $txt = implode('', array_map(function ($p) { return (string)($p['text'] ?? ''); }, $txt));
        $last = (string)$txt;
        $fin = (string)($j['choices'][0]['finish_reason'] ?? '');
        $out = ds_json_extract($last);
        if (is_array($out) && ($fin !== 'length' || $try === 2)) return ['ok' => true, 'ms' => $ms, 'json' => $out, 'truncated' => $fin === 'length'];
        if (is_array($out) && $fin === 'length' && $maxTokens >= 16000) return ['ok' => true, 'ms' => $ms, 'json' => $out, 'truncated' => true];
        // پاسخِ خالی یا ناتمام (مدل‌های استدلالی بخشی از سقف را صرفِ فکر می‌کنند): سقفِ بیشتر
        $maxTokens = min(16000, $maxTokens * 2);
    }
    error_log('[ds_ai_json] invalid: ' . mb_substr($last, 0, 300));
    return ['ok' => false, 'ms' => intval((microtime(true) - $t0) * 1000), 'error' => 'پاسخِ هوشِ مصنوعی JSONِ قابلِ استفاده نبود' . ($last === '' ? ' (پاسخ خالی بود؛ شاید مدل/کلید محدودیت دارد)' : '') . '.'];
}

// تشخیصِ نوع (اگر معلوم نیست)
function ds_classify($pdo, $text) {
    $r = ds_ai_json($pdo, DS_CLASSIFY, mb_substr((string)$text, 0, 6000), 1500);
    $k = $r['ok'] ? (string)($r['json']['kind'] ?? '') : '';
    return isset(ds_types()[$k]) ? $k : 'unknown';
}

// نامه ← JSONِ ردیف‌ها (همان قالبِ «ورود ردیف‌ها از نامه»؛ پیش‌نمایش و ثبت با همان مسیرِ قبلی)
function ds_letter_rows($pdo, $text, $companyId = 0) {
    $r = ds_ai_json($pdo, ds_prompt($pdo, 'letter', $companyId), $text, 12000);
    if (!$r['ok']) return $r;
    $j = $r['json'];
    $rows = array_values(array_filter((array)($j['rows'] ?? []), 'is_array'));
    // فقط فیلدهای شناخته‌شده‌ی قالب، متنِ ساده و کوتاه
    $keys = ['request', 'plate_two', 'plate_letter', 'plate_three', 'plate_iran', 'old_plate', 'moto_city', 'moto_number', 'insurance_type', 'expiry_date', 'car_name', 'car_type', 'car_system', 'car_tip',
             'car_kind', 'usage', 'model_year', 'color', 'capacity', 'cylinders', 'extras', 'chassis_no', 'vin', 'engine_no', 'prev_policy_number', 'prev_insurer', 'liability_limit', 'car_value',
             'has_prev_body', 'health_inspection', 'policy_number', 'change_request', 'cancellation_reason', 'owner_name', 'owner_national_id', 'owner_phone', 'owner_address',
             'insured_name', 'insured_national_id', 'note'];
    $clean = [];
    foreach (array_slice($rows, 0, 500) as $x) {
        $o = [];
        foreach ($keys as $k) if (isset($x[$k]) && is_scalar($x[$k]) && trim((string)$x[$k]) !== '') $o[$k] = mb_substr(trim(strip_tags((string)$x[$k])), 0, 300);
        if ($o) $clean[] = $o;
    }
    $counts = [];
    foreach ((array)($j['letter']['counts'] ?? []) as $k => $v) if (in_array($k, ['ثالث', 'بدنه', 'تغییر اطلاعات', 'فسخ'], true) && is_numeric($v)) $counts[$k] = max(0, intval($v));
    $out = ['rows' => $clean];
    $L = [];
    if ($counts) $L['counts'] = $counts;
    foreach (['requester', 'number', 'date', 'note'] as $k) if (isset($j['letter'][$k]) && is_scalar($j['letter'][$k]) && trim((string)$j['letter'][$k]) !== '') $L[$k] = mb_substr(trim(strip_tags((string)$j['letter'][$k])), 0, 600);
    if ($L) $out['letter'] = $L;
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
function ds_extract($pdo, $type, $text, $companyId = 0) {
    $types = ds_types();
    if ($type === 'auto' || $type === '' || !isset($types[$type])) {
        $type = ds_classify($pdo, $text);
        if ($type === 'unknown') $type = 'generic';
    }
    if ($type === 'letter') {
        $r = ds_letter_rows($pdo, $text, $companyId);
        if (!$r['ok']) return $r + ['type' => $type];
        $fields = ['تعدادِ ردیف' => (string)$r['count']];
        foreach ($r['counts'] as $k => $v) $fields['تعدادِ درخواستیِ ' . $k] = (string)$v;
        return ['ok' => true, 'type' => $type, 'type_fa' => $types[$type][0], 'data' => ['rows' => $r['rows'], 'counts' => $r['counts']], 'fields' => $fields, 'raw' => $r['raw']];
    }
    $r = ds_ai_json($pdo, ds_prompt($pdo, $type, $companyId), $text, $type === 'generic' ? 4000 : 3000);
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
function ds_doc_result($pdo, $text, $docType = '', $companyId = 0) {
    $type = ds_type_of_doc($docType) ?: 'auto';
    $x = ds_extract($pdo, $type, $text, $companyId);
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
function ds_smart_file($pdo, $abs, $page = 1, $docType = '', $companyId = 0) {
    @set_time_limit(300);
    $t = ds_page_text($pdo, $abs, $page);
    if (empty($t['ok'])) return ['ok' => false, 'scan' => true, 'engines' => ['ocr'], 'error' => $t['error'] ?? 'متنی خوانده نشد.'];
    $r = ds_doc_result($pdo, $t['text'], $docType, $companyId);
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
