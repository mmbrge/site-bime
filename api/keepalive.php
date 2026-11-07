<?php
// فایل: api/keepalive.php
// یک حلقه از «نگه‌دارِ خودکارِ نشست‌ها» (api/_keepalive.php). فقط با کلیدِ cronِ استعلامات؛ بدونِ نشستِ پنل.
// جواب همان لحظه برمی‌گردد و کار در پس‌زمینه ادامه می‌یابد؛ آخرِ هر حلقه، حلقه‌ی بعد را خودش صدا می‌زند.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require '../config/db.php';
require_once __DIR__ . '/_keepalive.php';

inq_ensure($pdo);
$key = inq_get_setting($pdo, 'inq_cron_key');
if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo '{"ok":false}'; exit; }

@ignore_user_abort(true);
@set_time_limit(180);
// جوابِ فوری به صدا زننده (زنجیره منتظر نمی‌ماند)
$body = '{"ok":true}';
if (function_exists('fastcgi_finish_request')) { echo $body; fastcgi_finish_request(); }
else {
    header('Connection: close'); header('Content-Length: ' . strlen($body));
    echo $body; while (ob_get_level() > 0) @ob_end_flush(); @flush();
}
if (function_exists('session_write_close')) @session_write_close();
ka_run_step($pdo);
