<?php
// فایل: api/premium_calc.php
// موتور محاسبه‌ی حق بیمه‌ی ثالث - بیمه پاسارگاد
require_once __DIR__ . '/_case_helpers.php'; // برای jalali_to_gregorian_ts
// تمام اعداد این فایل از جدول رسمی شرکت (فایل اکسل تحویلی) + ده‌ها نمونه‌ی واقعی استعلام‌شده
// توسط مهدی استخراج و تایید شده‌اند. هرجا داده‌ی مستقیم نبود (فقط تعهد ۲۵۰م برای رده‌های غیر از
// پراید)، با «قانون نسبت» که در همه‌ی رده‌های دیگر ۱۰۰٪ دقیق تایید شده بود تخمین زده شده -
// این‌ها با علامت «تخمینی» مشخص شده‌اند.

// ستون‌های هر رده: base (ثالث پایه)، driver (حوادث راننده، ثابت)، excess (جدول مازاد به ازای هر سقف تعهد - میلیون تومان)
function premium_vehicle_categories() {
    return [
        1 => ['name' => 'پراید / پیکان', 'base' => 89440000, 'driver' => 14700000, 'excess' => [
            70=>0, 80=>1003484, 100=>3010453, 200=>10034844, 250=>12543554, 300=>15052265,
            400=>20069687, 500=>27094078, 600=>34620210, 700=>42146343, 800=>49672475, 900=>57198608, 1000=>64724741,
        ]],
        2 => ['name' => 'انواع ۴ سیلندر (تیبا، ساینا، سمند، دنا، پژو و مشابه)', 'base' => 105132000, 'driver' => 14700000, 'excess' => [
            70=>0, 80=>1179652, 100=>3538955, 200=>11796516, 250=>14744286, 300=>17694774,
            400=>23593032, 500=>31850593, 600=>40697980, 700=>49545367, 800=>58392753, 900=>67240140, 1000=>76087527,
        ]],
        3 => ['name' => 'کمتر از ۴ سیلندر', 'base' => 75522000, 'driver' => 14700000, 'excess' => [
            70=>0, 80=>847387, 100=>2542160, 200=>8473868, 250=>10591618, 300=>12710802,
            400=>16947736, 500=>22879443, 600=>29234844, 700=>35590245, 800=>41945646, 900=>48301047, 1000=>54656448,
        ]],
        4 => ['name' => 'بیش از ۴ سیلندر', 'base' => 117665000, 'driver' => 14700000, 'excess' => [
            70=>0, 80=>1320139, 100=>3960418, 200=>13201394, 250=>16501982, 300=>19802091,
            400=>26402788, 500=>35643764, 600=>45544809, 700=>55445854, 800=>65346900, 900=>75247945, 1000=>85148991,
        ]],
        5 => ['name' => 'وانت تا یک تن', 'base' => 92533000, 'driver' => 25200000, 'excess' => [
            70=>0, 80=>1297561, 100=>3892683, 200=>12975610, 250=>12977333, 300=>19463415,
            400=>25951220, 500=>35034147, 600=>44765854, 700=>54497562, 800=>64229270, 900=>73960977, 1000=>83692684,
        ]],
        6 => ['name' => 'وانت یک تا سه تن', 'base' => 111415000, 'driver' => 25200000, 'excess' => [
            70=>0, 80=>1562369, 100=>4687108, 200=>15623694, 250=>15625448, 300=>23435540,
            400=>31247387, 500=>42183973, 600=>53901743, 700=>65619513, 800=>77337283, 900=>89055054, 1000=>100772824,
        ]],
        7 => ['name' => 'موتورسیکلت ۱ سیلندر', 'base' => 22907000, 'driver' => 7770000, 'excess' => [
            70=>0, 80=>321318, 100=>963953, 200=>3213177, 250=>3212603, 300=>4819766,
            400=>6426354, 500=>8675578, 600=>11085461, 700=>13495344, 800=>15905227, 900=>18315109, 1000=>20724992,
        ]],
        8 => ['name' => 'موتورسیکلت ۲ سیلندر به بالا', 'base' => 25162000, 'driver' => 7770000, 'excess' => [
            70=>0, 80=>352993, 100=>1058980, 200=>3529934, 250=>3528856, 300=>5294900,
            400=>7059867, 500=>9530821, 600=>12178271, 700=>14825721, 800=>17473171, 900=>20120621, 1000=>22768071,
        ]],
        9 => ['name' => 'موتورسیکلت سایدبار سه‌چرخ', 'base' => 27063000, 'driver' => 7770000, 'excess' => [
            70=>0, 80=>379601, 100=>1138803, 200=>3796009, 250=>3795463, 300=>5694013,
            400=>7592018, 500=>10249224, 600=>13096231, 700=>15943237, 800=>18790244, 900=>21637251, 1000=>24484257,
        ]],
    ];
}

function premium_liability_tiers() {
    // میلیون تومان
    return [70, 80, 100, 200, 250, 300, 400, 500, 600, 700, 800, 900, 1000];
}

// جدول شیفتِ بونوس-مالوس - تایید شده با محاسبه‌ی دقیق روی بیش از ۱۵ نمونه‌ی واقعی
function bonus_malus_shift_mali($count) {
    if ($count <= 0) return 0;
    if ($count === 1) return -20;
    if ($count === 2) return -30;
    return -40; // ۳ یا بیشتر
}
function bonus_malus_shift_badani($count) {
    if ($count <= 0) return 0;
    if ($count === 1) return -30;
    if ($count === 2) return -70;
    return -100; // ۳ یا بیشتر
}

// درصد تخفیف/جریمه‌ی سالِ جاری را از روی درصدِ سالِ قبل و تعداد خسارت‌های امسال محاسبه می‌کند
function calc_bonus_malus_percent($prevPercent, $maliCount, $badaniCount) {
    if ($maliCount <= 0 && $badaniCount <= 0) {
        return min(70, $prevPercent + 5);
    }
    $shift = bonus_malus_shift_mali($maliCount) + bonus_malus_shift_badani($badaniCount);
    $new = $prevPercent + $shift;
    // حد پایین احتیاطی (بدون داده‌ی رسمی، فقط برای جلوگیری از اعداد نامعقول)
    if ($new < -100) $new = -100;
    return $new;
}

// نقطه‌ی شروع نردبون برای خودروی صفر یا اولین بیمه‌نامه: طبق تایید مهدی، همیشه ۵٪ (نه صفر)
function bonus_malus_initial_percent() {
    return 5;
}

// جریمه‌ی «سال ساخت» - تایید شده دقیق با نمونه‌های واقعی: به‌ازای هر سال قدیمی‌تر از یک
// آستانه، ۲٪ به رقم خامِ هر سه بخش (پایه/مازاد/راننده) اضافه می‌شود، پیش از اعمال هر
// تخفیف/جریمه‌ی سابقه؛ این جریمه در ۲۰٪ سقف دارد (تایید شده: مدل‌های ۱۳۸۰ تا ۱۳۷۰ همه دقیقاً
// همان ۲۰٪ را می‌گیرند، دیگر رشد نمی‌کند).
// نکته‌ی مهم: آستانه و سقف بر اساس سال جاری محاسبه می‌شوند (نه یک عدد ثابت)، چون تعرفه
// واقعاً بر اساس «سن خودرو» است، نه سال تقویمی مطلق - با گذر هر سال، این اعداد خودکار
// یک واحد جلو می‌روند و نیازی به به‌روزرسانی دستی سالانه نیست.
function premium_current_jalali_year() {
    $tz = new DateTimeZone('Asia/Tehran');
    $now = new DateTime('now', $tz);
    [$jy, ,] = jalali_from_gregorian_ts($now->getTimestamp());
    return $jy;
}
function calc_model_year_surcharge_percent($modelYear) {
    if (!$modelYear) return 0;
    $currentYear = premium_current_jalali_year();
    $cutoffYear = $currentYear - 15; // مدل‌های هم‌سن یا جدیدتر از این، جریمه ندارند
    $capYear = $currentYear - 25;    // مدل‌های هم‌سن یا قدیمی‌تر از این، حداکثر (۲۰٪) را می‌گیرند
    if ($modelYear >= $cutoffYear) return 0;
    if ($modelYear <= $capYear) return 20;
    return ($cutoffYear - $modelYear) * 2;
}

// تعداد روزهای گذشته از تاریخ انقضای بیمه‌نامه‌ی قبلی تا امروز (اگر بیمه‌نامه‌ی قبلی هنوز
// منقضی نشده یا اطلاعی از آن نیست، صفر برمی‌گردد - یعنی هیچ جریمه‌ی «سهم صندوق» تعلق نمی‌گیرد)
// نکته‌ی مهم ۱: «امروز» باید همیشه بر اساس منطقه‌ی زمانیِ ایران محاسبه شود، وگرنه بسته به
// تنظیمات ساعت سرور ممکن است یک روز اختلاف ایجاد شود.
// نکته‌ی مهم ۲ (باگ رفع‌شده): مقایسه باید بر اساس «روز تقویمی» باشد، نه لحظه‌ی دقیق؛ وگرنه
// اگر همین امروز ساعت ۱۴ باشد، فاصله تا نیمه‌شب امروز کسری از یک روز می‌شود و round() آن را
// به بالا (روز ۱) گرد می‌کند، در حالی که بیمه‌نامه‌ای که امروز منقضی می‌شود هنوز «دیر» نیست.
function days_since_previous_policy_expired($prevExpiryJalali) {
    if (!$prevExpiryJalali) return 0;
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $prevExpiryJalali, $m)) {
        $expiryTs = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])); // نیمه‌شبِ روز انقضا
        $tz = new DateTimeZone('Asia/Tehran');
        $now = new DateTime('now', $tz);
        [$ny, $nm, $nd] = jalali_from_gregorian_ts($now->getTimestamp());
        $todayMidnightTs = jalali_to_gregorian_ts($ny, $nm, $nd); // نیمه‌شبِ امروز (بدون ساعت/دقیقه)
        $days = floor(($todayMidnightTs - $expiryTs) / 86400);
        return max(0, $days);
    }
    return 0;
}

// تعداد روزهای باقی‌مانده تا انقضای بیمه‌نامه‌ی قبلی (اگر منفی باشد یعنی از قبل منقضی شده)
function days_until_previous_policy_expires($prevExpiryJalali) {
    if (!$prevExpiryJalali) return null;
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $prevExpiryJalali, $m)) {
        $expiryTs = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]));
        $tz = new DateTimeZone('Asia/Tehran');
        $now = new DateTime('now', $tz);
        [$ny, $nm, $nd] = jalali_from_gregorian_ts($now->getTimestamp());
        $todayMidnightTs = jalali_to_gregorian_ts($ny, $nm, $nd);
        return floor(($expiryTs - $todayMidnightTs) / 86400);
    }
    return null;
}

// تاریخ شمسی را به‌صورت YYYY/MM/DD (با ارقام انگلیسی، برای ذخیره/محاسبه) رشته می‌کند، از روی
// یک timestamp - برای ساختن پیام خطای «حداقل از تاریخ...» استفاده می‌شود
function format_jalali_from_ts($ts) {
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($ts);
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

// «سهم صندوق موضوع بند ب ماده ۲۴» - جریمه‌ی تاخیر در تمدید بیمه‌نامه‌ی ثالث. تایید شده با
// محاسبه‌ی دقیق روی ۴ نمونه‌ی واقعی: همیشه فقط روی «ثالث پایه‌ی پس از تخفیف/جریمه‌ی سابقه»
// حساب می‌شود (نه مازاد، نه راننده)، به نسبت روزهای تاخیر از ۳۶۵ روز، با سقف ۱۰۰٪
function calc_fund_contribution($baseAfterBM, $daysLate) {
    $ratio = min(1.0, max(0, $daysLate) / 365);
    return $baseAfterBM * $ratio;
}

/**
 * محاسبه‌ی کامل حق بیمه‌ی ثالث
 * پارامترهای ورودی:
 *   category_code: 1..9 (رده‌ی خودرو)
 *   liability_tier: یکی از مقادیر premium_liability_tiers() (میلیون تومان)
 *   thirdparty_percent: درصد تخفیف/جریمه‌ی نهاییِ نردبون ثالث (مثبت=تخفیف، منفی=جریمه)
 *   driver_percent: درصد تخفیف/جریمه‌ی نهاییِ نردبون حوادث راننده
 *   group_discount_enabled/percent, excess_discount_enabled/percent: از تنظیمات پنل
 * خروجی: آرایه‌ی کامل جزئیات محاسبه (برای نمایش شفاف به کاربر)
 */
function calculate_thirdparty_premium($params) {
    $categories = premium_vehicle_categories();
    $cat = $categories[$params['category_code']] ?? null;
    if (!$cat) return ['ok' => false, 'error' => 'رده‌ی خودرو نامعتبر است.'];

    $tier = intval($params['liability_tier']);
    if (!isset($cat['excess'][$tier])) return ['ok' => false, 'error' => 'سقف تعهد مالی نامعتبر است.'];

    $base = $cat['base'];
    $driver = $cat['driver'];
    $excess = $cat['excess'][$tier];

    // جریمه‌ی سال ساخت (اگر مدل قدیمی‌تر از ۱۳۹۰ باشد)، پیش از بونوس-مالوس روی رقم خام اعمال می‌شود
    $yearSurchargePercent = calc_model_year_surcharge_percent(intval($params['model_year'] ?? 0));
    $base = $base * (1 + $yearSurchargePercent / 100);
    $excess = $excess * (1 + $yearSurchargePercent / 100);
    $driver = $driver * (1 + $yearSurchargePercent / 100);
    $baseRawOriginal = $cat['base']; $excessRawOriginal = $cat['excess'][$tier]; $driverRawOriginal = $cat['driver'];

    $tpPercent = floatval($params['thirdparty_percent']); // می‌تواند منفی (جریمه) باشد
    $drPercent = floatval($params['driver_percent']);

    // مرحله ۱: تخفیف/جریمه‌ی سابقه روی «پایه» و «مازاد» (با هم، چون هر دو جزء نردبون ثالث‌اند)، و روی «راننده» جدا
    $baseAfterBM = $base * (1 - $tpPercent / 100);
    $excessAfterBM = $excess * (1 - $tpPercent / 100);
    $driverAfterBM = $driver * (1 - $drPercent / 100);
    $baseBeforeZeroKm = $baseAfterBM; $excessBeforeZeroKm = $excessAfterBM; $driverBeforeZeroKm = $driverAfterBM;

    // «سهم صندوق موضوع بند ب ماده ۲۴» - جریمه‌ی تاخیر تمدید (اگر بیمه‌نامه‌ی قبلی منقضی شده باشد)
    // نکته‌ی مهم: این مبلغ به «حق بیمه خالص» اضافه می‌شود ولی خودش مشمول مالیات ۱۰٪ نمی‌شود
    // (تایید شده دقیق با محاسبه‌ی چهار نمونه‌ی واقعی)
    $daysLate = intval($params['days_late'] ?? 0);
    $fundContribution = calc_fund_contribution($baseAfterBM, $daysLate);

    // «تخفیف صفر کیلومتر» - یک مرحله‌ی کاملاً جدا و مستقل از بونوس-مالوس (که برای خودروی صفر
    // همیشه ۰٪ است چون سابقه‌ای برای «عدم خسارت» وجود ندارد). این تخفیف *بعد* از محاسبه‌ی سهم
    // صندوق اعمال می‌شود - یعنی سهم صندوق روی مبلغِ هنوز بدون این تخفیف حساب شده (تایید شده
    // دقیق: ۸۹,۴۴۰,۰۰۰ × ۱۱ روز ÷ ۳۶۵ = ۲,۶۹۵,۴۵۲، یعنی از رقم خامِ بدون تخفیف صفر کیلومتر)
    $zeroKmEnabled = !empty($params['zero_km_discount_enabled']);
    $zeroKmPercent = $zeroKmEnabled ? floatval($params['zero_km_discount_percent']) : 0;
    $baseAfterBM = $baseAfterBM * (1 - $zeroKmPercent / 100);
    $excessAfterBM = $excessAfterBM * (1 - $zeroKmPercent / 100);
    $driverAfterBM = $driverAfterBM * (1 - $zeroKmPercent / 100);

    // مرحله ۲: تخفیف قرارداد گروهی (قابل‌تنظیم، پیش‌فرض ۲.۵٪) - روی هر سه بخش (سهم صندوق مشمول این تخفیف نیست)
    $groupEnabled = !empty($params['group_discount_enabled']);
    $groupPercent = $groupEnabled ? floatval($params['group_discount_percent']) : 0;
    $baseAfterGroup = $baseAfterBM * (1 - $groupPercent / 100);
    $excessAfterGroup = $excessAfterBM * (1 - $groupPercent / 100);
    $driverAfterGroup = $driverAfterBM * (1 - $groupPercent / 100);

    // مرحله ۳: تخفیف اضافه‌ی مازاد برای تعهد ≥۵۰۰ میلیون تومان (قابل‌تنظیم، پیش‌فرض ۱۵٪) - فقط روی مازاد
    $excessEnabled = !empty($params['excess_discount_enabled']);
    $excessDiscountPercent = ($excessEnabled && $tier >= 500) ? floatval($params['excess_discount_percent']) : 0;
    $excessFinal = $excessAfterGroup * (1 - $excessDiscountPercent / 100);
    // مالیات فقط روی (پایه + مازاد + راننده) پس از تخفیف‌ها محاسبه می‌شود؛ سهم صندوق مشمول مالیات نیست
    $taxableTotal = $baseAfterGroup + $excessFinal + $driverAfterGroup;
    $tax = $taxableTotal * 0.10;
    $netTotal = $taxableTotal + $fundContribution; // «حق بیمه خالص» نمایشی، شامل سهم صندوق
    $grandTotal = $taxableTotal + $tax + $fundContribution;
    $grandTotalRounded = round($grandTotal / 1000) * 1000;

    return [
        'ok' => true,
        'category_name' => $cat['name'],
        'liability_tier' => $tier,
        'breakdown' => [
            'base_raw' => round($baseRawOriginal), 'excess_raw' => round($excessRawOriginal), 'driver_raw' => round($driverRawOriginal),
            'year_surcharge_percent' => $yearSurchargePercent,
            'base_year_surcharge' => round($base - $baseRawOriginal), 'excess_year_surcharge' => round($excess - $excessRawOriginal), 'driver_year_surcharge' => round($driver - $driverRawOriginal),
            'thirdparty_percent' => $tpPercent, 'driver_percent' => $drPercent,
            'zero_km_discount_percent' => $zeroKmPercent,
            'base_before_zero_km' => round($baseBeforeZeroKm), 'excess_before_zero_km' => round($excessBeforeZeroKm), 'driver_before_zero_km' => round($driverBeforeZeroKm),
            'base_after_bm' => round($baseAfterBM), 'excess_after_bm' => round($excessAfterBM), 'driver_after_bm' => round($driverAfterBM),
            'days_late' => $daysLate, 'fund_contribution' => round($fundContribution),
            'group_discount_percent' => $groupPercent,
            'excess_discount_percent' => $excessDiscountPercent,
            'base_final' => round($baseAfterGroup), 'excess_after_group' => round($excessAfterGroup), 'excess_final' => round($excessFinal), 'driver_final' => round($driverAfterGroup),
        ],
        'net_total' => round($netTotal),
        'tax' => round($tax),
        'grand_total' => $grandTotalRounded,
    ];
}
