<?php
// فایل: api/ollama_worker.php
// نقطه‌ی اتصالِ برنامه‌ی ویندوزیِ «سیستمِ داخلی (Ollama)» (tools/ollama-worker/worker.ps1) — بدونِ ورود به پنل، فقط با کلیدِ مخصوص
//   POST ?action=next     ← {models, host, version, ollama}: اعلامِ آنلاین بودن + گرفتنِ کارِ بعدی (تا ۲۰ ثانیه منتظر می‌ماند)
//   GET  ?action=payload&id=N ← متنِ کامل درخواست (همان بدنه‌ی /api/chatِ Ollama، عیناً)
//   POST ?action=done     ← {id, ok, text, error, ms, tokens}: جوابِ Ollama
// کلید در هدرِ X-Worker-Token (تنظیمات ← پردازش فایل هوشمند ← سیستمِ داخلی)
ini_set('display_errors', '0');
require '../config/db.php';
require_once __DIR__ . '/_ollama.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$c = ol_cfg($pdo);
$tok = (string)($_SERVER['HTTP_X_WORKER_TOKEN'] ?? '');
if (!$c['enabled'] || $c['token'] === '' || !hash_equals($c['token'], $tok)) { http_response_code(403); $out(['ok' => false, 'error' => 'کلیدِ برنامه‌ی ویندوزی درست نیست یا سیستمِ داخلی در تنظیمات خاموش است.']); }
ol_ensure($pdo);
$action = (string)($_GET['action'] ?? '');
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];

if ($action === 'next' || $action === 'ping') {
    @set_time_limit(60);
    ol_heartbeat($pdo, $in);
    if ($action === 'ping') $out(['ok' => true, 'server_time' => date('c')]);
    // تا ۲۰ ثانیه منتظرِ کار (کمتر درخواست به هاست)
    $until = time() + 20;
    do {
        $j = ol_claim($pdo);
        if ($j) $out(['ok' => true, 'job' => ['id' => intval($j['id']), 'purpose' => $j['purpose'], 'model' => $j['model']]]);
        usleep(1500000);
    } while (time() < $until);
    $out(['ok' => true, 'job' => null]);
}
if ($action === 'payload') {
    $p = ol_payload($pdo, intval($_GET['id'] ?? 0));
    if ($p === null) { http_response_code(404); $out(['ok' => false, 'error' => 'کار پیدا نشد.']); }
    echo $p;
    exit;
}
if ($action === 'done') {
    @set_time_limit(280); @ignore_user_abort(true);
    $n = ol_complete($pdo, dirname(__DIR__), intval($in['id'] ?? 0), !empty($in['ok']), (string)($in['text'] ?? ''), (string)($in['error'] ?? ''), intval($in['ms'] ?? 0), intval($in['tokens'] ?? 0));
    $out(['ok' => true, 'resumed' => $n]);
}
$out(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
