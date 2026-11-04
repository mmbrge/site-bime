<?php
// فایل: api/psg_drafts_actions.php
// «سایت بیمه‌گر / پاسارگاد / پیش‌نویس‌ها»: فهرست (از دیتابیسِ خودمان) و بروزرسانی از سامانه‌ی پاسارگاد (گام‌به‌گام).
// منطق: api/_psg_drafts.php — فقط خواندن؛ هیچ تغییری در سامانه‌ی پاسارگاد داده نمی‌شود.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_inquiry.php';
require_once __DIR__ . '/_psg_drafts.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
// مدیر کل، یا کسی که در «دسترسی‌ها» «پاسارگاد: پیش‌نویس‌ها» را دارد (perm_gate نقش را موقتاً بالا می‌برد)
if (($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'دسترسی به «پیش‌نویس‌های پاسارگاد» ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']);
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
$actor = ['id' => intval($_SESSION['user_id']), 'name' => (string)($_SESSION['full_name'] ?? '')];
if (function_exists('sess_release')) sess_release(); else session_write_close();
psgd_ensure($pdo);
$kind = isset(PSGD_KINDS[$data['kind'] ?? '']) ? $data['kind'] : 'third';

try {
    if ($action === 'list') {
        $r = psgd_list($pdo, $kind, !empty($data['stale']));
        $st = $pdo->prepare("SELECT id FROM psg_draft_syncs WHERE kind = ? AND status = 'RUNNING' AND started_at > NOW() - INTERVAL 2 HOUR ORDER BY id DESC LIMIT 1");
        $st->execute([$kind]);
        $out(['ok' => true, 'kind' => $kind, 'rows' => $r['rows'], 'last_sync' => $r['last_sync'], 'running' => (int)$st->fetchColumn() ?: null]);
    }
    if ($action === 'sync_start') $out(psgd_sync_start($pdo, $kind, (string)($data['from'] ?? '1403/01/01'), $actor));
    if ($action === 'sync_step') $out(psgd_sync_step($pdo, intval($data['id'] ?? 0)));
    if ($action === 'sync_stop') {
        $s = psgd_sync_get($pdo, intval($data['id'] ?? 0));
        if ($s && $s['status'] === 'RUNNING') psgd_sync_finish($pdo, $s['kind'], (int)$s['id'], 'STOPPED', 'متوقف شد (' . $actor['name'] . ').');
        $out(['ok' => true]);
    }
    $out(['ok' => false, 'error' => 'درخواست نامعتبر.']);
} catch (Throwable $e) {
    error_log('[psg_drafts] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . mb_substr($e->getMessage(), 0, 200)]);
}
