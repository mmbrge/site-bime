<?php
// فایل: api/maint_status.php
// وضعیتِ «حالتِ به‌روزرسانی» برای همه (حتی بدونِ ورود): صفحه‌ی به‌روزرسانی هر ۳۰ ثانیه می‌پرسد تمام شد یا نه،
// و پنل/پورتال/وب‌اپ هر دقیقه می‌پرسند تا اگر بسته شدنِ سایت زمان‌بندی شده، شمارشِ معکوس نشان بدهند.
if (isset($_COOKIE['bime_company_portal']) && !isset($_COOKIE[session_name()])) session_name('bime_company_portal');
@session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require '../config/db.php';
$m = sec_maint_state($pdo);
$admin = sec_maint_is_admin();
echo json_encode(['ok' => true, 'on' => $m['on'], 'scheduled' => $m['scheduled'], 'blocked' => $m['on'] && !$admin, 'admin' => $admin,
                  'seconds_left' => $m['scheduled'] ? max(0, $m['at'] - time()) : 0, 'msg' => $m['msg'], 'until' => $m['until']], JSON_UNESCAPED_UNICODE);
