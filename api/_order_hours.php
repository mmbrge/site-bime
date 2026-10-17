<?php
// فایل: api/_order_hours.php
// ساعتِ کاریِ «ثبتِ درخواستِ آنلاین» در مینی‌اپ (برای هر روزِ هفته جدا، از پنل → تنظیمات → مینی‌اپ کاربران).
// بیرون از این ساعت‌ها ثبتِ درخواستِ تازه و بارگذاریِ مدارک بسته است؛ بیمه‌نامه‌های من، پلاک‌های من،
// ارتباط با کارشناس و دیدنِ وضعیتِ مدارک باز می‌ماند.
//   system_settings.order_hours_json = {"enabled":1,"days":[{"on":1,"from":"08:00","to":"16:00"}, ... (۷ روز، شنبه تا جمعه)]}

const OH_DAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

function oh_default() {
    $d = [];
    for ($i = 0; $i < 7; $i++) $d[] = ['on' => $i < 5 ? 1 : ($i === 5 ? 1 : 0), 'from' => '08:00', 'to' => $i === 5 ? '12:00' : '16:00'];
    return ['enabled' => 0, 'days' => $d, 'message' => ''];
}

function oh_get($pdo) {
    $cfg = oh_default();
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'order_hours_json'");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) {
            $cfg['enabled'] = !empty($j['enabled']) ? 1 : 0;
            $cfg['message'] = mb_substr(trim((string)($j['message'] ?? '')), 0, 400);
            for ($i = 0; $i < 7; $i++) if (isset($j['days'][$i]) && is_array($j['days'][$i])) {
                $x = $j['days'][$i];
                $cfg['days'][$i] = ['on' => !empty($x['on']) ? 1 : 0, 'from' => oh_time($x['from'] ?? '08:00'), 'to' => oh_time($x['to'] ?? '16:00')];
            }
        }
    } catch (Throwable $e) {}
    return $cfg;
}

function oh_time($t) {
    $t = strtr(trim((string)$t), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
    if (!preg_match('/^(\d{1,2}):?(\d{2})?$/', $t, $m)) return '08:00';
    return sprintf('%02d:%02d', min(23, intval($m[1])), min(59, intval($m[2] ?? 0)));
}

function oh_save($pdo, array $in) {
    $cfg = oh_default();
    $cfg['enabled'] = !empty($in['enabled']) ? 1 : 0;
    $cfg['message'] = mb_substr(trim((string)($in['message'] ?? '')), 0, 400);
    for ($i = 0; $i < 7; $i++) {
        $x = (array)($in['days'][$i] ?? []);
        $cfg['days'][$i] = ['on' => !empty($x['on']) ? 1 : 0, 'from' => oh_time($x['from'] ?? '08:00'), 'to' => oh_time($x['to'] ?? '16:00')];
    }
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('order_hours_json', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
    return $cfg;
}

function oh_fa($s) { return strtr((string)$s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
function oh_hm($t) { [$h, $m] = array_map('intval', explode(':', $t)); return oh_fa($h . ':' . sprintf('%02d', $m)); }

// وضعیتِ همین لحظه (به وقتِ ایران): open، فهرستِ هفته و زمانِ بازشدنِ بعدی
function oh_status($pdo) {
    $cfg = oh_get($pdo);
    $now = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $wd = (intval($now->format('w')) + 1) % 7;   // ۰ = شنبه
    $mins = intval($now->format('G')) * 60 + intval($now->format('i'));
    $toMin = function ($t) { [$h, $m] = array_map('intval', explode(':', $t)); return $h * 60 + $m; };
    $week = [];
    foreach ($cfg['days'] as $i => $d) $week[] = ['day' => OH_DAYS[$i], 'hours' => $d['on'] ? (oh_hm($d['from']) . ' تا ' . oh_hm($d['to'])) : 'تعطیل', 'off' => !$d['on'], 'today' => $i === $wd];
    if (!$cfg['enabled']) return ['enabled' => false, 'open' => true, 'week' => $week, 'next' => '', 'message' => ''];
    $today = $cfg['days'][$wd];
    $open = $today['on'] && $mins >= $toMin($today['from']) && $mins < $toMin($today['to']);
    $next = '';
    if (!$open) {
        for ($k = 0; $k < 8; $k++) {
            $i = ($wd + $k) % 7; $d = $cfg['days'][$i];
            if (!$d['on']) continue;
            if ($k === 0 && $mins >= $toMin($d['from'])) continue;
            $label = $k === 0 ? 'امروز' : ($k === 1 ? 'فردا (' . OH_DAYS[$i] . ')' : OH_DAYS[$i]);
            $next = $label . ' از ساعت ' . oh_hm($d['from']) . ' تا ' . oh_hm($d['to']);
            break;
        }
    }
    return ['enabled' => true, 'open' => $open, 'week' => $week, 'next' => $next,
            'message' => $cfg['message'] ?: 'ساعتِ کاریِ ثبتِ درخواستِ آنلاین به پایان رسیده است. ثبتِ درخواستِ بیمه و بارگذاریِ مدارک فقط در ساعاتِ کاری امکان‌پذیر است؛ بیمه‌نامه‌های من، پلاک‌های من و ارتباط با کارشناس همیشه فعال‌اند.'];
}

// پاسخِ JSONِ «بسته است» برای اکشن‌های ثبت/بارگذاری
function oh_closed_payload($st) {
    return ['ok' => false, 'closed' => true, 'hours' => $st, 'error' => 'ساعتِ کاریِ ثبتِ درخواستِ آنلاین به پایان رسیده است' . ($st['next'] ? ' — زمانِ بعدی: ' . $st['next'] : '') . '.'];
}
