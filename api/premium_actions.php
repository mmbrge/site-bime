<?php
// فایل: api/premium_actions.php
// بک‌اندِ استعلام حق بیمه‌ی ثالث - بدون نیاز به لاگین (کاربر مهمان هم می‌تواند استعلام بگیرد)
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/premium_calc.php';

$jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($jsonBody['action'] ?? '');

// محاسبه‌ی درصد سابقه‌ی هر مسیر (ثالث یا حوادث راننده) از روی «سال‌های بدون خسارت» و «خسارت‌های امسال»
// طبق تایید مهدی: اگر اصلاً بیمه‌نامه‌ی قبلی نداشته (نه صفر سال، بلکه هیچ سابقه‌ای) => 0٪
// اگر سابقه دارد (حتی ۰ سال بدون خسارت، یعنی صفر شده) => حداقل ۵٪
//
// نکته‌ی مهمی که با نمونه‌ی واقعی کشف شد: رشد سالانه‌ی «+۵٪» فقط وقتی اعمال می‌شود که *کل*
// بیمه‌نامه (هم مسیر ثالث هم مسیر حوادث راننده) امسال هیچ خسارتی نداشته باشد. اگر حتی یکی از
// دو مسیر خسارت داشته باشد، مسیر «سالم» دیگر رشد نمی‌گیرد و درصد قبلی‌اش را بدون تغییر حفظ
// می‌کند؛ فقط مسیر خسارت‌دیده با جدول جریمه/شیفت کم می‌شود.
function resolve_track_percent($hasPrevPolicy, $years, $maliCount, $badaniCount, $anyDamageOnPolicy) {
    if (!$hasPrevPolicy) return 0;
    // نکته‌ی مهم: «سال‌های قبلی» بدون هیچ آفستی محاسبه می‌شود (years×5) - این درصدِ «پیش از این
    // تمدید» است. رشد +۵٪ فقط یک‌بار و فقط برای تمدیدِ کاملاً پاکِ همین چرخه اضافه می‌شود؛
    // اضافه‌کردنش به خودِ این خط (قبلاً به اشتباه) باعث می‌شد برای سال=۰ عدد ۱۰٪ به‌جای ۵٪
    // به‌دست بیاید (چون +۵ دوبار اعمال می‌شد)
    $priorPercent = min(70, intval($years) * 5);
    if ($maliCount > 0 || $badaniCount > 0) {
        return calc_bonus_malus_percent($priorPercent, intval($maliCount), intval($badaniCount));
    }
    if ($anyDamageOnPolicy) return $priorPercent; // مسیر سالم، ولی چون مسیر دیگر خسارت داشته، رشد نمی‌گیرد
    return min(70, $priorPercent + 5); // تمدید کاملاً پاک: رشد عادی نردبان
}

try {
    if ($action === 'init') {
        // اطلاعات پایه‌ی مورد نیاز فرم: رده‌های خودرو + سطوح تعهد + وضعیت لاگین (اگر توکن معتبر بود)
        $token = $_GET['token'] ?? '';
        $personName = null;
        if ($token) {
            $stmt = $pdo->prepare("SELECT p.full_name FROM webapp_sessions s JOIN persons p ON s.person_id = p.id WHERE s.token = ? AND s.mode = 'main' AND s.expires_at > NOW()");
            $stmt->execute([$token]);
            $personName = $stmt->fetchColumn() ?: null;
        }
        $cats = [];
        foreach (premium_vehicle_categories() as $code => $c) { $cats[] = ['code' => $code, 'name' => $c['name']]; }
        echo json_encode([
            'ok' => true, 'person_name' => $personName, 'categories' => $cats,
            'liability_tiers' => premium_liability_tiers(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'calculate') {
        $data = $jsonBody;

        $hasPrevPolicy = !empty($data['has_prev_policy']);

        $tpMali = intval($data['tp_mali'] ?? 0); $tpBadani = intval($data['tp_badani'] ?? 0);
        $drMali = intval($data['dr_mali'] ?? 0); $drBadani = intval($data['dr_badani'] ?? 0);
        $anyDamage = ($tpMali > 0 || $tpBadani > 0 || $drMali > 0 || $drBadani > 0);

        $daysLate = 0;
        if ($hasPrevPolicy) {
            $tpPercent = resolve_track_percent($hasPrevPolicy, $data['tp_years'] ?? 0, $tpMali, $tpBadani, $anyDamage);
            $drPercent = resolve_track_percent($hasPrevPolicy, $data['dr_years'] ?? 0, $drMali, $drBadani, $anyDamage);

            // روزهای تاخیر تمدید (فقط اگر بیمه‌نامه‌ی قبلی داشته و منقضی شده باشد)
            if (!empty($data['prev_expiry_jalali'])) {
                $daysLate = days_since_previous_policy_expired($data['prev_expiry_jalali']);

                // اگر بیش از ۳۰ روز به انقضای بیمه‌نامه‌ی قبلی مانده، هنوز زود است برای استعلام
                $daysUntil = days_until_previous_policy_expires($data['prev_expiry_jalali']);
                if ($daysUntil !== null && $daysUntil > 30) {
                    $parts = explode('/', $data['prev_expiry_jalali']);
                    $earliestTs = jalali_to_gregorian_ts(intval($parts[0]), intval($parts[1]), intval($parts[2])) - (30 * 86400);
                    $earliestDate = format_jalali_from_ts($earliestTs);
                    echo json_encode(['ok' => false, 'error' => "تا تاریخ انقضای بیمه‌نامه‌ی شما بیشتر از ۳۰ روز مانده است. لطفاً حداقل از تاریخ {$earliestDate} مراجعه نمایید."], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        } else {
            // خودروی صفر کیلومتر: هیچ سابقه‌ی «عدم خسارتی» ندارد، پس بونوس-مالوسش دقیقاً ۰٪ است؛
            // «تخفیف صفر کیلومتر» یک مزیت کاملاً جدا و مستقل است (قابل‌تنظیم در پنل، پیش‌فرض ۵٪)
            // که در calculate_thirdparty_premium به‌صورت مرحله‌ی جداگانه اعمال می‌شود.
            // اگر تاریخ خرید قبل از امروز باشد، به همان نسبت سهم صندوق تعلق می‌گیرد (روی مبلغ
            // هنوز بدون تخفیف صفر کیلومتر - تایید شده دقیق).
            $tpPercent = 0;
            $drPercent = 0;
            if (!empty($data['purchase_date_jalali'])) {
                $daysLate = days_since_previous_policy_expired($data['purchase_date_jalali']);
            }
        }

        // تنظیمات پنل
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN
            ('premium_group_discount_enabled','premium_group_discount_percent','premium_excess_discount_enabled','premium_excess_discount_percent','premium_zero_km_discount_enabled','premium_zero_km_discount_percent')");
        $settingsRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $result = calculate_thirdparty_premium([
            'category_code' => intval($data['category_code'] ?? 0),
            'liability_tier' => intval($data['liability_tier'] ?? 0),
            'model_year' => intval($data['model_year'] ?? 0),
            'thirdparty_percent' => $tpPercent,
            'driver_percent' => $drPercent,
            'days_late' => $daysLate,
            'group_discount_enabled' => ($settingsRows['premium_group_discount_enabled'] ?? '1') === '1',
            'group_discount_percent' => floatval($settingsRows['premium_group_discount_percent'] ?? 2.5),
            'excess_discount_enabled' => ($settingsRows['premium_excess_discount_enabled'] ?? '1') === '1',
            'excess_discount_percent' => floatval($settingsRows['premium_excess_discount_percent'] ?? 15),
            'zero_km_discount_enabled' => !$hasPrevPolicy && (($settingsRows['premium_zero_km_discount_enabled'] ?? '1') === '1'),
            'zero_km_discount_percent' => floatval($settingsRows['premium_zero_km_discount_percent'] ?? 5),
        ]);

        if ($result['ok']) {
            $result['thirdparty_percent'] = $tpPercent;
            $result['driver_percent'] = $drPercent;
            $result['days_late'] = $daysLate;
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[premium_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
