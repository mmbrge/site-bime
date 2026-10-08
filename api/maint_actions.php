<?php
// فایل: api/maint_actions.php
// «حالتِ به‌روزرسانی» (فقط مدیر کل): بستنِ سایت برای همه جز مدیر کل، فوری یا با شمارشِ معکوس، با پیام و زمانِ تقریبیِ بازگشت.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? ($_GET['action'] ?? 'get');
$set = function ($k, $v) use ($pdo) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, (string)$v]);
};
$state = function () use ($pdo) {
    $v = [];
    foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'maint\\_%'")->fetchAll() as $r) $v[$r['setting_key']] = $r['setting_value'];
    $at = intval($v['maint_at'] ?? 0);
    $on = ($v['maint_on'] ?? '0') === '1';
    // چند کاربرِ غیرِمدیر همین الان در پنل‌ها فعال‌اند (برای اینکه مدیر بداند چه کسانی بیرون می‌روند)
    $active = 0;
    try { $active = intval($pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'ADMIN' AND last_seen_at >= NOW() - INTERVAL 2 MINUTE")->fetchColumn())
                  + intval($pdo->query("SELECT COUNT(*) FROM company_portal_users WHERE last_seen_at >= NOW() - INTERVAL 2 MINUTE")->fetchColumn()); } catch (Throwable $e) {}
    return ['ok' => true, 'on' => $on && ($at <= 0 || $at <= time()), 'scheduled' => $on && $at > time(), 'seconds_left' => $on && $at > time() ? $at - time() : 0,
            'msg' => (string)($v['maint_msg'] ?? ''), 'until' => (string)($v['maint_until'] ?? ''), 'since' => (string)($v['maint_since'] ?? ''), 'active_users' => $active];
};
if ($action === 'get') $out($state());
if ($action === 'save') {
    $on = !empty($data['on']);
    $mins = max(0, min(240, intval($data['delay_minutes'] ?? 0)));
    $set('maint_msg', mb_substr(trim((string)($data['msg'] ?? '')), 0, 600));
    $set('maint_until', mb_substr(trim((string)($data['until'] ?? '')), 0, 60));
    $set('maint_on', $on ? '1' : '0');
    $set('maint_at', $on && $mins ? time() + $mins * 60 : 0);
    $set('maint_since', $on ? jalali_from_gregorian_ts_dotted(time() + $mins * 60) . ' ' . date('H:i', time() + $mins * 60) : '');
    try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'MAINTENANCE', 'system', 0, ?)")
              ->execute([intval($_SESSION['user_id']), $on ? ('بستنِ سایت برای به‌روزرسانی' . ($mins ? ' (' . $mins . ' دقیقه‌ی دیگر)' : '')) : 'بازکردنِ سایت']); } catch (Throwable $e) {}
    $out($state());
}
$out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
