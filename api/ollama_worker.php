<?php
// فایل: api/ollama_worker.php
// نقطه‌ی اتصالِ برنامه‌ی ویندوزیِ «سیستمِ داخلی (Ollama)» (tools/ollama-worker/worker.ps1) — بدونِ ورود به پنل، فقط با کلیدِ مخصوص
//   POST ?action=next     ← {models, host, version, ollama}: اعلامِ آنلاین بودن + گرفتنِ کارِ بعدی (تا ۲۰ ثانیه منتظر می‌ماند)
//   GET  ?action=payload&id=N ← متنِ کامل درخواست (همان بدنه‌ی /api/chatِ Ollama، عیناً)
//   POST ?action=done     ← {id, ok, text, error, ms, tokens}: جوابِ Ollama
// کلید در هدرِ X-Worker-Token (تنظیمات ← پردازش فایل هوشمند ← سیستمِ داخلی)؛ هر سیستم (کامپیوتر) کلیدِ خودش را دارد و با همان شناخته می‌شود
ini_set('display_errors', '0');
require '../config/db.php';
require_once __DIR__ . '/_ollama.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$c = ol_cfg($pdo);
$action = (string)($_GET['action'] ?? '');
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$node = ol_node_by_token($pdo, (string)($_SERVER['HTTP_X_WORKER_TOKEN'] ?? ''));
// تلاشِ ردشده در تنظیمات دیده می‌شود (کلیدِ قدیمی / خاموش)؛ code برای check.ps1
$reject = function ($code, $msg) use ($pdo, $in, $out, $node) {
    sms_set($pdo, 'ollama_reject', json_encode(['at' => time(), 'code' => $code, 'ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), 'host' => mb_substr((string)($in['host'] ?? ''), 0, 80),
                                                  'node' => $node['name'] ?? ''], JSON_UNESCAPED_UNICODE));
    http_response_code(403);
    $out(['ok' => false, 'code' => $code, 'error' => $msg]);
};
if (!$node) $reject('bad_token', 'کلیدِ برنامه‌ی ویندوزی درست نیست (کلیدِ تازه ساخته شده یا این سیستم حذف شده)؛ برنامه را از تنظیمات دوباره دانلود و نصب کنید.');
if (!$c['enabled'] || !$node['enabled']) {
    ol_heartbeat($pdo, $node['id'], $in + ['blocked' => !$c['enabled'] ? 'disabled' : 'node_disabled']);   // «وصل است ولی خاموش»
    $reject(!$c['enabled'] ? 'disabled' : 'node_disabled', !$c['enabled'] ? 'سیستمِ داخلی در تنظیماتِ سایت خاموش است.' : 'این سیستم («' . $node['name'] . '») در تنظیماتِ سایت خاموش است.');
}
$me = $node['id'];
ol_ensure($pdo);

if ($action === 'next' || $action === 'ping') {
    @set_time_limit(60);
    ol_heartbeat($pdo, $me, $in);
    if ($action === 'ping') $out(['ok' => true, 'server_time' => date('c'), 'node' => $node['name']]);
    // تا ۲۰ ثانیه منتظرِ کار (کمتر درخواست به هاست)
    $until = time() + 20;
    do {
        $j = ol_claim($pdo, $me);
        if ($j) $out(['ok' => true, 'job' => ['id' => intval($j['id']), 'purpose' => $j['purpose'], 'model' => $j['model']]]);
        usleep(1500000);
    } while (time() < $until);
    $out(['ok' => true, 'job' => null]);
}
if ($action === 'payload') {
    $p = ol_payload($pdo, intval($_GET['id'] ?? 0), $me);
    if ($p === null) { http_response_code(404); $out(['ok' => false, 'error' => 'کار پیدا نشد.']); }
    echo $p;
    exit;
}
if ($action === 'done') {
    @set_time_limit(280); @ignore_user_abort(true);
    $n = ol_complete($pdo, dirname(__DIR__), intval($in['id'] ?? 0), !empty($in['ok']), (string)($in['text'] ?? ''), (string)($in['error'] ?? ''), intval($in['ms'] ?? 0), intval($in['tokens'] ?? 0), $me);
    $out(['ok' => true, 'resumed' => $n]);
}
$out(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
