<?php
// فایل: api/_doc_rules.php
// مرحله‌ی «قاعده» در پردازشِ هوشمندِ مدارکِ خودرو: پیش از هوشِ مصنوعی، فیلدها با الگوهای ثابتِ هر نوع مدرک از متنِ OCR
// بیرون کشیده می‌شوند (لحظه‌ای و رایگان). فقط فیلدهایی که پیدا نشدند یا معتبر نبودند به هوشِ مصنوعی (و بعد به «بررسی با عکس») می‌روند.
//   ds_rx_classify($text)        ← نوعِ مدرک از روی کلمه‌های کلیدی (یا 'unknown')
//   ds_rx_extract($type, $text)  ← کلیدهای خامِ همان شکلِ پاسخِ هوشِ مصنوعی (برای vdoc_ai_normalize)
//   ds_rx_needed($type, $data, $text) ← فیلدهای لازمی که هنوز نیست یا معتبر نیست: [کلید => برچسب]
//   ds_valid_*                   ← کنترلِ درستی: VIN، کد ملی (رقمِ کنترل)، پلاک، سال، کد پستی، موبایل، تاریخ
// نیاز: p2e_digits (api/_case_helpers.php)

const DS_PLATE_LETTERS = ['الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'د', 'ز', 'س', 'ش', 'ص', 'ط', 'ظ', 'ع', 'ف', 'ق', 'ک', 'گ', 'ل', 'م', 'ن', 'و', 'ه', 'ی', 'ژ'];

// متنِ یکدست: ارقامِ لاتین، ی/ک فارسی، نیم‌فاصله = فاصله، دونقطه‌ی یکسان
function ds_rx_text($t) {
    $t = p2e_digits(strtr((string)$t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                                       'ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', 'ۀ' => 'ه', "\u{200C}" => ' ', "\u{200F}" => '', "\u{200E}" => '', '：' => ':', '﹕' => ':', "\t" => ' ', "\r" => '']));
    return preg_replace('/[ \x{00A0}]+/u', ' ', $t);
}

// کنترلِ درستی
function ds_valid_vin($v) { return (bool)preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', (string)$v) && preg_match('/[A-Z]/', $v) && preg_match('/\d/', $v); }
function ds_valid_chassis($v) { return strlen((string)$v) >= 6 && preg_match('/^[A-Z0-9]+$/', (string)$v) && preg_match('/\d/', $v); }
function ds_valid_engine($v) { return strlen((string)$v) >= 5 && preg_match('/^[A-Z0-9]+$/', (string)$v); }
// کد ملیِ ۱۰ رقمی با رقمِ کنترل؛ شناسه‌ی ملیِ ۱۱ رقمی (شرکت) فقط طول
function ds_valid_nid($v) {
    $v = (string)$v;
    if (preg_match('/^\d{11}$/', $v)) return !preg_match('/^0+$/', $v);
    if (!preg_match('/^\d{10}$/', $v) || preg_match('/^(\d)\1{9}$/', $v)) return false;
    $s = 0;
    for ($i = 0; $i < 9; $i++) $s += intval($v[$i]) * (10 - $i);
    $r = $s % 11; $c = intval($v[9]);
    return $r < 2 ? $c === $r : $c === 11 - $r;
}
function ds_valid_year($v) { $y = intval($v); return ($y >= 1300 && $y <= 1415) || ($y >= 1950 && $y <= 2035); }
function ds_valid_jdate($v) { return (bool)preg_match('/^1[34]\d\d\/(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/', (string)$v); }
function ds_valid_plate($pp) {
    return is_array($pp) && preg_match('/^\d{2}$/', (string)($pp['two'] ?? '')) && preg_match('/^\d{3}$/', (string)($pp['three'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['iran'] ?? ''))
        && in_array((string)($pp['letter'] ?? ''), DS_PLATE_LETTERS, true);
}

// مقدارِ یک برچسب: «برچسب: مقدار» تا برچسبِ بعدی یا آخرِ خط؛ اگر خط خالی بود، خطِ بعد (وقتی خودش برچسب ندارد)
function ds_rx_label($text, array $labels, $stopRe) {
    $lines = explode("\n", $text);
    foreach ($labels as $lab) {
        foreach ($lines as $i => $line) {
            if (!preg_match('~(?:^|[\s/\(\-|])(?:' . $lab . ')\s*[:\-]?\s*:?\s*(.*)$~u', $line, $m)) continue;
            $v = $m[1];
            if (preg_match('~' . $stopRe . '~u', $v, $s, PREG_OFFSET_CAPTURE) && $s[0][1] > 0) $v = substr($v, 0, $s[0][1]);
            $v = trim($v, " \t:-|");
            if ($v === '' && isset($lines[$i + 1]) && strpos($lines[$i + 1], ':') === false && mb_strlen(trim($lines[$i + 1])) <= 80) $v = trim($lines[$i + 1], " \t:-|");
            if ($v !== '') return $v;
        }
    }
    return '';
}
// برچسب‌هایی که پایانِ مقدارِ قبلی‌اند (چند جفت در یک خط: «نوع: کامیون سیستم: دیما»)
function ds_rx_stops() {
    return '\s(?:سیستم|سبستم|تیپ|نیپ|مدل|رنگ|ظرفیت|نوع سوخت|تعداد سیلندر|تعداد پیوندی|تعداد محور|تعداد چرخ|شماره موتور|شماره شاسی|شماره اتاق|حجم سیلندر|کشور سازنده|'
         . 'کد پستی|کدپستی|تلفن همراه|تلفن ثابت|نام پدر|تاریخ تولد|شماره ملی|کد ملی|شماره ثبت|کد اقتصادی|محل ثبت|تاریخ تاسیس|کاربری|نوع کاربری|شماره مسلسل|VIN)\s*:';
}
function ds_rx_digits($v, $min, $max) {
    if (preg_match('/(?<!\d)(\d{' . $min . ',' . $max . '})(?!\d)/', str_replace([' ', '-', '_', '/'], '', (string)$v), $m)) return $m[1];
    return '';
}
function ds_rx_code($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$v)); }

// شماره موتور: کلمه‌های فارسی (مثلِ «با احتساب انفا ۲») جزوِ شماره نیستند؛ از اولین تکه‌ی دارای حرفِ لاتین تا آخر
function ds_rx_engine($v) {
    $tok = preg_split('/\s+/u', trim(preg_replace('/[\p{Arabic}]+/u', ' ', (string)$v)), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($tok as $i => $x) if (preg_match('/[A-Za-z]/', $x)) { $tok = array_slice($tok, $i); break; }
    return ds_rx_code(implode('', $tok));
}
// پلاک: «۲۷ ب ۷۵۲» + «ایران ۳۸» (کارت) یا «۷۵۲ ب ۲۷» / «38ایران - 752 ب 27»؛ ترجیحاً نزدیکِ «شماره پلاک»
function ds_rx_plate($text) {
    $L = implode('|', DS_PLATE_LETTERS);
    $zones = [];
    if (preg_match('/شماره\s*پلاک|شماره\s*انتظامی/u', $text, $m, PREG_OFFSET_CAPTURE)) $zones[] = substr($text, $m[0][1], 160);
    $zones[] = $text;
    foreach ($zones as $z) {
        $flat = preg_replace('/\s+/u', ' ', $z);
        if (!preg_match('/(?<!\d)(\d{2,3})\s*(' . $L . ')\s*(\d{2,3})(?!\d)/u', $flat, $m, PREG_OFFSET_CAPTURE)) continue;
        [$a, $letter, $b] = [$m[1][0], $m[2][0], $m[3][0]];
        if (strlen($a) === 2 && strlen($b) === 3) { $two = $a; $three = $b; } elseif (strlen($a) === 3 && strlen($b) === 2) { $two = $b; $three = $a; } else continue;
        $iran = '';
        if (preg_match('/ایران\s*[:\-]?\s*(\d{2})(?!\d)/u', $flat, $x)) $iran = $x[1];
        elseif (preg_match('/(?<!\d)(\d{2})\s*\(?\s*ایران/u', $flat, $x)) $iran = $x[1];
        if ($iran === '') continue;
        return ['plate_two' => $two, 'plate_letter' => $letter, 'plate_three' => $three, 'plate_iran' => $iran];
    }
    return [];
}
// VIN در هر جای متن (حتی با فاصله بینِ نویسه‌ها: «N B G H T 4 X 2 ...»)
function ds_rx_vin_scan($text) {
    $t = preg_replace_callback('/(?:[A-Z0-9] ){10,}[A-Z0-9]/', function ($m) { return str_replace(' ', '', $m[0]); }, strtoupper($text));
    if (preg_match_all('/(?<![A-Z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Z0-9])/', $t, $m)) foreach ($m[1] as $v) if (ds_valid_vin($v)) return $v;
    return '';
}

function ds_rx_classify($text) {
    $t = ds_rx_text($text);
    if (preg_match('/سند\s*فروش\s*وسیله|فاکتور\s*فروش|صورتحساب\s*فروش\s*(?:خودرو|وسیله)/u', $t)) return 'sales_invoice';
    if (preg_match('/مشخصات\s*خریدار/u', $t) && preg_match('/مشخصات\s*مالک/u', $t)) return 'ownership_doc';
    if (preg_match('/بیمه\s*نامه/u', $t) && preg_match('/بیمه\s*گذار|مبلغ\s*(?:مورد\s*)?بیمه|حق\s*بیمه/u', $t)) return 'policy';
    $veh = preg_match('/شاسی|VIN|موتور|پلاک/u', $t);
    if (preg_match('/کارت\s*(?:هوشمند\s*)?ملی/u', $t) && !$veh) return 'national_card';
    if (!$veh && preg_match('/شماره\s*ملی/u', $t) && preg_match('/نام\s*پدر/u', $t) && preg_match('/پایان\s*اعتبار|تاریخ\s*تولد/u', $t)) return 'national_card';
    if ($veh && preg_match('/کارت\s*شناسایی\s*وسیله|کاربری|شماره\s*مسلسل|سبستم|سیستم|نام\s*مالک/u', $t)) return 'car_card';
    return 'unknown';
}

// استخراج با قاعده => کلیدهای خام (فقط مقدارهای معتبر)
function ds_rx_extract($type, $text) {
    $t = ds_rx_text($text);
    $stop = ds_rx_stops();
    $g = function (array $labels, $zone = null) use (&$t, $stop) { return ds_rx_label($zone ?? $t, $labels, $stop); };
    $o = [];
    if ($type === 'national_card') {
        if (($v = ds_rx_digits($g(['شماره\s*ملی', 'کد\s*ملی']), 10, 10)) !== '' && ds_valid_nid($v)) $o['national_id'] = $v;
        if (($v = $g(['نام(?!\s*(?:خانوادگی|پدر))'])) !== '' && preg_match('/^[\p{Arabic}\s]{2,40}$/u', $v)) $o['first_name'] = $v;
        if (($v = $g(['نام\s*خانوادگی'])) !== '' && preg_match('/^[\p{Arabic}\s]{2,40}$/u', $v)) $o['last_name'] = $v;
        if (($v = $g(['نام\s*پدر'])) !== '' && preg_match('/^[\p{Arabic}\s]{2,30}$/u', $v)) $o['father_name'] = $v;
        foreach (['birth_date' => ['تاریخ\s*تولد'], 'card_expiry' => ['پایان\s*اعتبار', 'تاریخ\s*انقضا']] as $k => $labs)
            if (preg_match('/(1[34]\d\d)\D{1,3}(\d{1,2})\D{1,3}(\d{1,2})/', $g($labs), $m)) $o[$k] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
        return $o;
    }
    // ناحیه‌ی مالک: فاکتور ← بعد از «مشخصات مالک»؛ سند ← بینِ «مشخصات مالک» و «مشخصات خریدار» (بخشِ خریدار خوانده نمی‌شود)
    $own = $t;
    if (in_array($type, ['sales_invoice', 'ownership_doc'], true) && preg_match('/مشخصات\s*مالک/u', $t, $m, PREG_OFFSET_CAPTURE)) {
        $own = substr($t, $m[0][1]);
        if (preg_match('/مشخصات\s*خریدار/u', $own, $x, PREG_OFFSET_CAPTURE)) $own = substr($own, 0, $x[0][1]);
    }
    $veh = $t;
    if ($type === 'ownership_doc' && preg_match('/مشخصات\s*خریدار/u', $t, $x, PREG_OFFSET_CAPTURE)) $veh = substr($t, 0, $x[0][1]);
    // شماره‌ها
    $ch = ds_rx_code($g(['شماره\s*شاسی'], $veh));
    if (ds_valid_chassis($ch)) $o['chassis_no'] = $ch;
    $vin = ds_rx_code($g(['شناسه\s*وسیله\s*نقلیه\s*\(?\s*VIN\s*\)?', 'VIN', 'شماره\s*VIN'], $veh));
    if (!ds_valid_vin($vin)) $vin = ds_rx_vin_scan($veh);
    if (ds_valid_vin($vin)) $o['vin'] = $vin;
    if (empty($o['chassis_no']) && !empty($o['vin'])) $o['chassis_no'] = $o['vin'];
    $eng = ds_rx_engine($g(['شماره\s*موتور'], $veh));
    if (ds_valid_engine($eng)) $o['engine_no'] = $eng;
    // خودرو
    foreach (['usage' => ['نوع\s*کاربری', 'کاربری'], 'car_system' => ['سیستم', 'سبستم'], 'car_tip' => ['تیپ', 'نیپ', 'تبپ'], 'color' => ['رنگ'], 'fuel' => ['نوع\s*سوخت'],
              'capacity' => ['ظرفیت'], 'country' => ['کشور\s*سازنده']] as $k => $labs) {
        $v = trim($g($labs, $veh));
        if ($v !== '' && $v !== '-' && mb_strlen($v) <= 60 && preg_match('/[\p{Arabic}A-Za-z0-9]/u', $v)) $o[$k] = $v;
    }
    if (!empty($o['capacity'])) $o['capacity'] = trim(preg_replace('/^(?:جمعاً|جمعا|ج)\s*/u', '', preg_replace('/\s*با\s*احتساب.*$/u', '', $o['capacity'])));
    $mv = $g(['مدل'], $veh);
    if ($mv !== '') {
        $around = $mv;
        if (preg_match('/مدل\s*:?[^\n]*\n([^\n]{0,20})/u', $veh, $nx)) $around .= ' ' . $nx[1];
        if (preg_match('/(?<!\d)(1[34]\d\d|19\d\d|20\d\d)(?!\d)/', $around, $y) && ds_valid_year($y[1])) $o['model_year'] = $y[1];
        $rest = trim(preg_replace('/(?<!\d)(1[34]\d\d|19\d\d|20\d\d)(?!\d)/', '', $mv));
        if (empty($o['car_tip']) && $rest !== '' && preg_match('/[A-Za-z]/', $rest) && mb_strlen($rest) <= 30) $o['car_tip'] = $rest;   // «مدل: C7H(6*4)»
    }
    foreach (['cylinders' => ['تعداد\s*سیلندر', 'تعداد\s*سلیندر', 'تعداد\s*پیوندی'], 'axles' => ['تعداد\s*محور'], 'wheels' => ['تعداد\s*چرخ']] as $k => $labs)
        if (($v = ds_rx_digits($g($labs, $veh), 1, 2)) !== '' && intval($v) > 0 && intval($v) <= 24) $o[$k] = $v;
    $cab = ds_rx_code($g(['شماره\s*اتاق'], $veh));
    if (strlen($cab) >= 4) $o['cab_no'] = $cab;
    if ($type === 'car_card' && ($v = ds_rx_digits($g(['شماره\s*مسلسل']), 6, 14)) !== '') $o['card_serial'] = $v;
    if ($type === 'ownership_doc' && preg_match('/^\s*نوع\s*:\s*([^\n]+?)(?=\s(?:سیستم|سبستم)\s*:|$)/mu', $veh, $m)) $o['car_kind'] = trim($m[1], " -");
    // پلاک
    if ($type !== 'sales_invoice' || preg_match('/شماره\s*پلاک\s*:?\s*\S/u', $t)) $o += ds_rx_plate($t);
    // مالک
    if ($type !== 'policy') {
        $name = $g(['نام\s*و\s*نام\s*خانوادگی\s*/\s*سازمان\s*/\s*شرکت', 'نام\s*مالک', 'نام\s*سازمان', 'نام\s*شرکت'], $own);
        if ($name === '' && $type === 'ownership_doc') $name = $g(['نام\s*و\s*نام\s*خانوادگی'], $own);
        $name = trim(preg_replace('/[\(\[]?\s*\d+\s*دانگ\s*[\)\]]?/u', '', $name), " -:");
        if ($name !== '' && preg_match('/^[\p{Arabic}\s\.\-\(\)]{3,120}$/u', $name)) $o['owner_name'] = $name;
        $nid = ds_rx_digits($g(['شماره\s*ملی\s*/\s*شماره\s*شناسه\s*ملی', 'شماره\s*شناسه\s*ملی', 'شناسه\s*ملی', 'شماره\s*ملی', 'کد\s*ملی'], $own), 10, 11);
        if ($nid !== '' && ds_valid_nid($nid)) $o['national_id'] = $nid;
        if (($v = ds_rx_digits($g(['کد\s*پستی', 'کدپستی'], $own), 10, 10)) !== '') $o['postal_code'] = $v;
        if (preg_match('/(?<!\d)(09\d{9})(?!\d)/', str_replace([' ', '-'], '', $g(['تلفن\s*همراه', 'موبایل'], $own) ?: $own), $m)) $o['mobile'] = $m[1];
        if (($v = ds_rx_digits($g(['تلفن\s*ثابت'], $own), 8, 11)) !== '' && strpos($v, '09') !== 0) $o['phone'] = $v;
        if (($v = $g(['نام\s*پدر'], $own)) !== '' && preg_match('/^[\p{Arabic}\s]{2,30}$/u', $v)) $o['father_name'] = $v;
        if (preg_match('/(1[34]\d\d)\D{1,3}(\d{1,2})\D{1,3}(\d{1,2})/', $g(['تاریخ\s*تولد\s*/\s*ثبت', 'تاریخ\s*تولد', 'تاریخ\s*تاسیس'], $own), $m)) $o['birth_date'] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
        foreach (['آدرس\s*محل\s*سکونت\s*\(?\s*مالک\s*حقوقی\s*\)?', 'آدرس\s*محل\s*سکونت\s*\(?\s*مالک\s*حقیقی\s*\)?', 'آدرس', 'نشانی'] as $lab) {
            $v = $g([$lab], $own);
            if ($v !== '' && preg_match_all('/\p{Arabic}/u', $v) >= 6) { $o['address'] = mb_substr($v, 0, 300); break; }
        }
    }
    // سند/فاکتور
    if (in_array($type, ['sales_invoice', 'ownership_doc'], true)) {
        if (($v = $g(['شماره\s*سند', 'شماره'])) !== '' && preg_match('/^[A-Za-z0-9\-\/]{3,30}$/', str_replace(' ', '', $v))) $o['doc_no'] = str_replace(' ', '', $v);
        if (preg_match('/(?:^|\n)\s*تاریخ\s*:?\s*(1[34]\d\d)\D{1,3}(\d{1,2})\D{1,3}(\d{1,2})/u', $t, $m)) $o['doc_date'] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
        if ($type === 'sales_invoice' && ($v = ds_rx_digits($g(['شماره\s*پرونده']), 6, 14)) !== '') $o['file_no'] = $v;
    }
    // بیمه‌نامه
    if ($type === 'policy') {
        $pn = $g(['شماره\s*بیمه\s*نامه']);
        if (preg_match('/(\d[\d\/\-]{5,40}\d)/', str_replace(' ', '', $pn), $m)) $o['policy_number'] = $m[1];
        if (preg_match('/شرکت\s*بیمه\s*([\p{Arabic}]{2,20})/u', $t, $m) && !in_array($m[1], ['گذار', 'گر'], true)) $o['insurer'] = 'بیمه ' . $m[1];
        $o['policy_type'] = preg_match('/بدنه/u', $t) ? 'BODY' : (preg_match('/شخص\s*ثالث|ثالث/u', $t) ? 'THIRDPARTY' : null);
        if ($o['policy_type'] === null) unset($o['policy_type']);
        if (preg_match('/از\s*ساعت[^\n]{0,40}?(1[34]\d\d\/\d{1,2}\/\d{1,2})[^\n]{0,60}?تا[^\n]{0,40}?(1[34]\d\d\/\d{1,2}\/\d{1,2})/u', $t, $m)) { $o['start_date'] = $m[1]; $o['end_date'] = $m[2]; }
        if (preg_match('/(1[34]\d\d)\D{1,3}(\d{1,2})\D{1,3}(\d{1,2})/', $g(['تاریخ\s*صدور']), $m)) $o['issue_date'] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
        if (($v = ds_rx_digits($g(['کد\s*رایانه', 'کد\s*یکتا']), 5, 20)) !== '') $o['unique_code'] = $v;
        $cv = $g(['جمع\s*مبلغ\s*بیمه\s*شده\s*\(?\s*به\s*عدد\s*\)?', 'مبلغ\s*مورد\s*بیمه', 'سرمایه\s*بیمه']);
        if (($v = preg_replace('/\D/', '', $cv)) !== '' && strlen($v) >= 6) $o['car_value'] = $v;
        if (preg_match('/(\d{1,2})\s*سال\s*تخفیف\s*عدم\s*خسارت\s*\(?\s*(\d{1,2})\s*درصد/u', $t, $m)) { $o['no_claim_years'] = $m[1]; $o['no_claim_percent'] = $m[2]; }
    }
    return array_filter($o, function ($v) { return $v !== '' && $v !== null; });
}

// فیلدهای لازمِ هر مدرک (کارتِ ماشین: فقط فیلدهای همان رویی که در متن هست) که هنوز نیست یا معتبر نیست
function ds_rx_needed($type, array $d, $text = '') {
    $t = ds_rx_text($text);
    $L = ['vin' => 'شماره شاسی / VIN', 'engine_no' => 'شماره موتور', 'owner_name' => 'نام مالک', 'national_id' => 'کد / شناسه ملی', 'plate' => 'پلاک', 'usage' => 'کاربری',
          'car_system' => 'سیستم', 'car_tip' => 'تیپ', 'model_year' => 'مدل', 'policy_number' => 'شماره بیمه‌نامه', 'insurer' => 'بیمه‌گر', 'end_date' => 'تاریخ انقضا',
          'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'birth_date' => 'تاریخ تولد'];
    $need = [];
    if ($type === 'car_card') {
        $front = preg_match('/(?:نام\s*مالک|شماره\s*پلاک|شناسه\s*ملی|VIN)\s*:/u', $t);
        $back = preg_match('/(?:کاربری|سیستم|سبستم|تیپ|نیپ|شماره\s*موتور|شماره\s*مسلسل)\s*:/u', $t);
        if (!$front && !$back) $front = $back = true;
        if ($front) $need = array_merge($need, ['vin', 'owner_name', 'national_id', 'plate']);
        if ($back) $need = array_merge($need, ['engine_no', 'vin', 'usage', 'car_system', 'model_year']);
    } elseif ($type === 'sales_invoice') $need = ['vin', 'engine_no', 'owner_name', 'national_id', 'car_system', 'model_year'];
    elseif ($type === 'ownership_doc') $need = ['vin', 'engine_no', 'owner_name', 'car_system', 'model_year'];
    elseif ($type === 'policy') $need = ['policy_number', 'insurer', 'end_date', 'vin'];
    elseif ($type === 'national_card') $need = ['national_id', 'first_name', 'last_name', 'birth_date'];
    $out = [];
    foreach (array_unique($need) as $k) {
        $v = $k === 'vin' ? (($d['vin'] ?? '') ?: ($d['chassis_no'] ?? '')) : ($d[$k] ?? '');
        if ($k === 'plate') $ok = ds_valid_plate($d['plate_parts'] ?? null);
        elseif ($k === 'vin') $ok = $v !== '' && (strlen($v) === 17 ? ds_valid_vin($v) : ds_valid_chassis($v));
        elseif ($k === 'engine_no') $ok = ds_valid_engine((string)$v);
        elseif ($k === 'national_id') $ok = ds_valid_nid((string)$v);
        elseif ($k === 'model_year') $ok = ds_valid_year($v);
        elseif (in_array($k, ['end_date', 'birth_date'], true)) $ok = ds_valid_jdate((string)$v);
        elseif ($k === 'first_name') $ok = trim((string)$v) !== '' || trim((string)($d['owner_name'] ?? '')) !== '';
        elseif ($k === 'last_name') $ok = trim((string)$v) !== '' || preg_match('/\s/u', trim((string)($d['owner_name'] ?? '')));
        else $ok = trim((string)$v) !== '';
        if (!$ok) $out[$k] = $L[$k] ?? $k;
    }
    return $out;
}
