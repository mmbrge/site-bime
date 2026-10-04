<?php
// فایل: api/alive.php
// «هنوز کار می‌کنم»: idle-guard.js وقتی کاربر واقعاً با صفحه کار کرده (کلیک، تایپ، اسکرول، ...) این‌جا را با سرتیترِ
// X-User-Activity: 1 صدا می‌زند تا شمارشِ خروجِ خودکار از نو شروع شود. بدونِ آن سرتیتر فقط زمانِ باقی‌مانده را برمی‌گرداند.
// ?ctx=company برای پنلِ شرکت‌ها
if (($_GET['ctx'] ?? '') === 'company') session_name('bime_company_portal');
session_start();
require '../config/db.php';   // sec_boot → sec_session_guard: اگر زمان گذشته باشد همین‌جا 401 برمی‌گردد
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$company = session_name() === 'bime_company_portal';
$uid = $company ? intval($_SESSION['company_user_id'] ?? 0) : intval($_SESSION['user_id'] ?? 0);
if (!$uid) {
    http_response_code(401);
    header('X-Session-Expired: 1');
    echo json_encode(['ok' => false, 'session_expired' => true, 'error' => 'نشست به پایان رسیده است.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$s = sec_settings($pdo);
$idle = intval(round(max(0.25, floatval($s['sec_idle_hours'] ?? 3)) * 3600));
$last = intval($_SESSION['_sec_act'] ?? time());
session_write_close();
echo json_encode(['ok' => true, 'idle_seconds' => $idle, 'remaining' => max(0, $idle - (time() - $last))]);
