<?php
// فایل: api/premium_actions.php
// بک‌اندِ استعلام حق بیمه‌ی ثالث - بدون نیاز به لاگین (کاربر مهمان هم می‌تواند استعلام بگیرد).
// هر استعلام در premium_inquiries ثبت می‌شود (پنل → مرکز صدور → استعلام‌ها) تا کارشناس جزئیات را ببیند،
// برای کاربر (حتی واردنشده، از راهِ شناسه‌ی بله) پیام بفرستد و «استعلامِ دقیق‌تر» را پاسخ دهد.
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/premium_calc.php';
require_once __DIR__ . '/_vehicle_catalog.php';

$jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($jsonBody['action'] ?? '');

// درصدِ سابقه‌ی هر مسیر (ثالث یا حوادث راننده) از روی «درصدِ تخفیفِ عدمِ خسارتِ بیمه‌نامه‌ی قبل» و «خسارت‌های سالِ قبل».
// همان قاعده‌ی تأییدشده‌ی قبلی: رشدِ +۵٪ فقط وقتی که کلِ بیمه‌نامه (هر دو مسیر) خسارت نداشته باشد؛ مسیرِ خسارت‌دیده
// با جدولِ شیفت کم می‌شود و مسیرِ سالمِ بیمه‌نامه‌ی خسارت‌دار درصدِ قبلی‌اش را نگه می‌دارد.
function resolve_track_percent($priorPercent, $maliCount, $badaniCount, $anyDamageOnPolicy) {
    $priorPercent = max(0, min(70, intval($priorPercent)));
    if ($maliCount > 0 || $badaniCount > 0) return calc_bonus_malus_percent($priorPercent, intval($maliCount), intval($badaniCount));
    if ($anyDamageOnPolicy) return $priorPercent;
    return min(70, $priorPercent + 5);
}

function pq_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS premium_inquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        person_id INT NULL,
        bale_user_id BIGINT NULL,
        user_name VARCHAR(190) NULL,
        vehicle_kind VARCHAR(10) NULL,
        vehicle_group VARCHAR(120) NULL,
        vehicle_title VARCHAR(190) NULL,
        category_code TINYINT NULL,
        inputs_json MEDIUMTEXT NULL,
        result_json MEDIUMTEXT NULL,
        grand_total BIGINT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'DONE',
        user_note VARCHAR(500) NULL,
        accurate_at DATETIME NULL,
        reply_text TEXT NULL,
        replied_at DATETIME NULL,
        replied_by INT NULL,
        KEY k_created (created_at), KEY k_status (status), KEY k_person (person_id)
    ) DEFAULT CHARSET=utf8mb4");
}

// کاربرِ این استعلام: از توکنِ اپ (وارد شده) یا از initDataِ امضاشده‌ی بله (مهمان)
function pq_who($pdo, $token, $initData) {
    $w = ['person_id' => null, 'bale_user_id' => null, 'name' => null];
    if ($token) {
        $st = $pdo->prepare("SELECT p.id, p.full_name, p.bale_chat_id FROM webapp_sessions s JOIN persons p ON s.person_id = p.id WHERE s.token = ? AND s.mode = 'main' AND s.expires_at > NOW()");
        $st->execute([$token]);
        if ($p = $st->fetch()) { $w['person_id'] = (int)$p['id']; $w['name'] = $p['full_name'] ?: null; $w['bale_user_id'] = $p['bale_chat_id'] ?: null; }
    }
    if ($initData) {
        $uid = validate_bale_init_data($initData, bale_get_token($pdo));
        if ($uid) {
            $w['bale_user_id'] = $w['bale_user_id'] ?: $uid;
            parse_str($initData, $d);
            $u = json_decode((string)($d['user'] ?? ''), true) ?: [];
            if (!$w['name']) $w['name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: (!empty($u['username']) ? '@' . $u['username'] : null);
            if (!$w['person_id']) {
                $st = $pdo->prepare("SELECT id, full_name FROM persons WHERE bale_chat_id = ? LIMIT 1");
                $st->execute([$uid]);
                if ($p = $st->fetch()) { $w['person_id'] = (int)$p['id']; $w['name'] = $p['full_name'] ?: $w['name']; }
            }
        }
    }
    return $w;
}

function pq_claims_fa($m, $b) {
    $m = intval($m); $b = intval($b);
    if (!$m && !$b) return 'بدون خسارت';
    $p = [];
    if ($b) $p[] = ($b >= 3 ? '۳ مورد و بیشتر' : $b) . ' خسارت بدنی';
    if ($m) $p[] = ($m >= 3 ? '۳ مورد و بیشتر' : $m) . ' خسارت مالی';
    return implode(' و ', $p);
}

try {
    pq_ensure($pdo);

    if ($action === 'init') {
        $who = pq_who($pdo, $_GET['token'] ?? '', $_GET['init_data'] ?? '');
        $cats = [];
        foreach (premium_vehicle_categories() as $code => $c) $cats[] = ['code' => $code, 'name' => $c['name']];
        echo json_encode([
            'ok' => true, 'person_name' => $who['name'], 'logged_in' => (bool)$who['person_id'] && !empty($_GET['token']),
            'categories' => $cats, 'liability_tiers' => premium_liability_tiers(), 'kinds' => vc_kinds_list(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'calculate') {
        $data = $jsonBody;
        $who = pq_who($pdo, $data['token'] ?? '', $data['init_data'] ?? '');
        // استعلام از پنل (استعلامات ← استعلامِ حق بیمه): نامِ کارمندِ واردشده
        if (!empty($data['panel']) && !$who['name'] && isset($_COOKIE[session_name()])) {
            @session_start(['read_and_close' => true]);
            if (!empty($_SESSION['user_id'])) $who['name'] = trim((string)($_SESSION['full_name'] ?? 'کارمند')) . ' (پنل)';
        }

        // وسیله‌ی نقلیه: از فهرستِ پنل (نوع ← گروه ← مدل)
        $model = !empty($data['model_id']) ? vc_get($pdo, intval($data['model_id'])) : null;
        $catCode = $model ? ($model['premium_cat'] !== null ? intval($model['premium_cat']) : null) : (intval($data['category_code'] ?? 0) ?: null);
        $tier = intval($data['liability_tier'] ?? 0);
        $tpMali = intval($data['tp_mali'] ?? 0); $tpBadani = intval($data['tp_badani'] ?? 0);
        $drMali = intval($data['dr_mali'] ?? 0); $drBadani = intval($data['dr_badani'] ?? 0);
        $anyDamage = ($tpMali > 0 || $tpBadani > 0 || $drMali > 0 || $drBadani > 0);
        $inputs = [
            'vehicle_kind' => $model['kind'] ?? null, 'vehicle_kind_fa' => VC_KINDS[$model['kind'] ?? '']['fa'] ?? null,
            'vehicle_group' => $model['grp'] ?? null, 'vehicle_title' => $model['title'] ?? null,
            'model_year' => intval($data['model_year'] ?? 0), 'liability_tier' => $tier,
            'prev_start' => (string)($data['prev_start_jalali'] ?? ''), 'prev_expiry' => (string)($data['prev_expiry_jalali'] ?? ''),
            'tp_prev_percent' => intval($data['tp_prev_percent'] ?? 0), 'dr_prev_percent' => intval($data['dr_prev_percent'] ?? 0),
            'tp_claims' => pq_claims_fa($tpMali, $tpBadani), 'dr_claims' => pq_claims_fa($drMali, $drBadani),
            'tp_mali' => $tpMali, 'tp_badani' => $tpBadani, 'dr_mali' => $drMali, 'dr_badani' => $drBadani,
        ];
        $log = function ($status, $result, $total) use ($pdo, $who, $inputs, $catCode) {
            $pdo->prepare("INSERT INTO premium_inquiries (person_id, bale_user_id, user_name, vehicle_kind, vehicle_group, vehicle_title, category_code, inputs_json, result_json, grand_total, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$who['person_id'], $who['bale_user_id'], $who['name'] ? mb_substr($who['name'], 0, 190) : null, $inputs['vehicle_kind'], $inputs['vehicle_group'],
                           $inputs['vehicle_title'] ? mb_substr($inputs['vehicle_title'], 0, 190) : null, $catCode, json_encode($inputs, JSON_UNESCAPED_UNICODE),
                           $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null, $total, $status]);
            return (int)$pdo->lastInsertId();
        };

        if (!$model && !$catCode) { echo json_encode(['ok' => false, 'error' => 'لطفاً نوع و مدلِ وسیله‌ی نقلیه را انتخاب کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        if (!in_array($tier, premium_liability_tiers(), true)) { echo json_encode(['ok' => false, 'error' => 'لطفاً سقف تعهد مالی را انتخاب کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        // وسیله‌ای که استعلامِ آنلاین ندارد (بارکشِ سنگین، اتوکار، ماشین‌آلات و ...): ثبت و ارجاع به کارشناس
        if (!$catCode) {
            $id = $log('NO_RATE', null, null);
            echo json_encode(['ok' => false, 'no_rate' => true, 'inquiry_id' => $id,
                'error' => 'استعلامِ آنلاینِ این نوع وسیله فعال نیست؛ «درخواستِ استعلام از کارشناس» را بزنید تا کارشناسان ما مبلغ را برایتان محاسبه کنند.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $daysLate = 0;
        $exp = (string)($data['prev_expiry_jalali'] ?? '');
        if (!preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $exp)) { echo json_encode(['ok' => false, 'error' => 'تاریخِ انقضای بیمه‌نامه‌ی قبلی را از روی تقویم انتخاب کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        $start = (string)($data['prev_start_jalali'] ?? '');
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $start, $s) && preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $exp, $e)
            && jalali_to_gregorian_ts(intval($s[1]), intval($s[2]), intval($s[3])) >= jalali_to_gregorian_ts(intval($e[1]), intval($e[2]), intval($e[3]))) {
            echo json_encode(['ok' => false, 'error' => 'تاریخِ شروعِ بیمه‌نامه‌ی قبلی باید پیش از تاریخِ انقضای آن باشد.'], JSON_UNESCAPED_UNICODE); exit;
        }
        $daysLate = days_since_previous_policy_expired($exp);
        $daysUntil = days_until_previous_policy_expires($exp);
        if ($daysUntil !== null && $daysUntil > 30) {
            $parts = explode('/', $exp);
            $earliestDate = format_jalali_from_ts(jalali_to_gregorian_ts(intval($parts[0]), intval($parts[1]), intval($parts[2])) - 30 * 86400);
            echo json_encode(['ok' => false, 'error' => "تا تاریخ انقضای بیمه‌نامه‌ی شما بیشتر از ۳۰ روز مانده است. لطفاً حداقل از تاریخ {$earliestDate} مراجعه نمایید."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tpPercent = resolve_track_percent($data['tp_prev_percent'] ?? 0, $tpMali, $tpBadani, $anyDamage);
        $drPercent = resolve_track_percent($data['dr_prev_percent'] ?? 0, $drMali, $drBadani, $anyDamage);

        $settingsRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN
            ('premium_group_discount_enabled','premium_group_discount_percent','premium_excess_discount_enabled','premium_excess_discount_percent')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $result = calculate_thirdparty_premium([
            'category_code' => $catCode, 'liability_tier' => $tier, 'model_year' => intval($data['model_year'] ?? 0),
            'thirdparty_percent' => $tpPercent, 'driver_percent' => $drPercent, 'days_late' => $daysLate,
            'group_discount_enabled' => ($settingsRows['premium_group_discount_enabled'] ?? '1') === '1',
            'group_discount_percent' => floatval($settingsRows['premium_group_discount_percent'] ?? 2.5),
            'excess_discount_enabled' => ($settingsRows['premium_excess_discount_enabled'] ?? '1') === '1',
            'excess_discount_percent' => floatval($settingsRows['premium_excess_discount_percent'] ?? 15),
            'zero_km_discount_enabled' => false, 'zero_km_discount_percent' => 0,
        ]);
        if ($result['ok']) {
            $result['thirdparty_percent'] = $tpPercent;
            $result['driver_percent'] = $drPercent;
            $result['days_late'] = $daysLate;
            $result['inquiry_id'] = $log('DONE', $result, intval($result['grand_total']));
            $result['user_name'] = $who['name'];
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // «این استعلام را دقیق‌تر می‌خواهم»: برای کارشناس ثبت می‌شود؛ کاربرِ واردشده در گفتگوی پشتیبانی هم پیامش را می‌فرستد
    if ($action === 'request_accurate') {
        $id = intval($jsonBody['inquiry_id'] ?? 0);
        $who = pq_who($pdo, $jsonBody['token'] ?? '', $jsonBody['init_data'] ?? '');
        $st = $pdo->prepare("SELECT * FROM premium_inquiries WHERE id = ?");
        $st->execute([$id]);
        $q = $st->fetch();
        if (!$q) { echo json_encode(['ok' => false, 'error' => 'استعلام پیدا نشد.'], JSON_UNESCAPED_UNICODE); exit; }
        // فقط همان کسی که استعلام گرفته (یا استعلامِ بی‌صاحب، در همان دقایق)
        $own = ($q['person_id'] && $who['person_id'] && (int)$q['person_id'] === $who['person_id']) || ($q['bale_user_id'] && $who['bale_user_id'] && (string)$q['bale_user_id'] === (string)$who['bale_user_id'])
            || (!$q['person_id'] && !$q['bale_user_id'] && strtotime($q['created_at']) > time() - 3600);
        if (!$own) { echo json_encode(['ok' => false, 'error' => 'این استعلام متعلق به شما نیست.'], JSON_UNESCAPED_UNICODE); exit; }
        $note = mb_substr(trim((string)($jsonBody['note'] ?? '')), 0, 500);
        $pdo->prepare("UPDATE premium_inquiries SET status = 'ACCURATE', user_note = ?, accurate_at = NOW(),
                         person_id = COALESCE(person_id, ?), bale_user_id = COALESCE(bale_user_id, ?), user_name = COALESCE(user_name, ?) WHERE id = ?")
            ->execute([$note ?: null, $who['person_id'], $who['bale_user_id'], $who['name'], $id]);
        $inp = json_decode((string)$q['inputs_json'], true) ?: [];
        $summary = "🔎 درخواستِ استعلامِ دقیقِ ثالث (شماره‌ی " . $id . ")\n"
            . '🚗 ' . trim(($inp['vehicle_kind_fa'] ?? '') . ' · ' . ($inp['vehicle_group'] ?? '') . ' · ' . ($inp['vehicle_title'] ?? ''), ' ·') . "\n"
            . (!empty($inp['model_year']) ? '📅 سال ساخت: ' . $inp['model_year'] . "\n" : '')
            . (!empty($inp['liability_tier']) ? '🛡 تعهد مالی: ' . $inp['liability_tier'] . " میلیون تومان\n" : '')
            . (!empty($inp['prev_expiry']) ? '⏳ بیمه‌نامه‌ی قبلی: ' . ($inp['prev_start'] ?? '') . ' تا ' . $inp['prev_expiry'] . "\n" : '')
            . (isset($inp['tp_prev_percent']) ? '🏷 تخفیف ثالث: ' . $inp['tp_prev_percent'] . '٪ · راننده: ' . ($inp['dr_prev_percent'] ?? 0) . "٪\n" : '')
            . (!empty($inp['tp_claims']) ? '⚠️ خسارت ثالث: ' . $inp['tp_claims'] . ' · راننده: ' . ($inp['dr_claims'] ?? '') . "\n" : '')
            . ($q['grand_total'] ? '💰 مبلغِ استعلامِ آنلاین: ' . number_format((int)$q['grand_total']) . " ریال\n" : "💰 استعلامِ آنلاین ندارد\n")
            . ($note ? '📝 توضیح: ' . $note : '');
        $openSupport = false;
        if ($who['person_id'] && !empty($jsonBody['token'])) {
            try {
                require_once __DIR__ . '/_company_helpers.php';
                require_once __DIR__ . '/_auth_helpers.php';
                require_once __DIR__ . '/_chat_core.php';
                if (chat_ready($pdo)) {
                    $r = chat_send($pdo, ['kind' => 'PERSON', 'id' => $who['person_id'], 'name' => $who['name']], 'P', $who['person_id'], $summary, null, null, null);
                    $openSupport = !empty($r['ok']);
                }
            } catch (Throwable $e) { error_log('[premium accurate chat] ' . $e->getMessage()); }
        }
        try { notify_admin($pdo, "🔎 درخواستِ استعلامِ دقیق · " . ($who['name'] ?: 'کاربرِ مهمان') . " · شماره‌ی {$id}"); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'open_support' => $openSupport], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[premium_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
