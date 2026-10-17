<?php
// فایل: api/trash_actions.php
// «تنظیمات ← حذف‌شده‌ها» (فقط مدیر کلِ واقعی): فهرستِ موارد «حذفِ کامل»، برگرداندن با همه‌ی جزئیات، پاک کردنِ دائمی (با رمز). منطق: api/_trash.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_trash.php';

function tout($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id']) || perm_real_role() !== 'ADMIN') tout(['ok' => false, 'error' => 'فقط مدیر کل.']);
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($data['action'] ?? '');
$uid = intval($_SESSION['user_id']);
$siteRoot = dirname(__DIR__);

try {
    if ($action === 'list') tout(['ok' => true, 'items' => trash_list($pdo, $siteRoot)]);
    if ($action === 'restore' || $action === 'purge') {
        $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([$uid]);
        if (!password_verify((string)($data['password'] ?? ''), (string)$st->fetchColumn())) tout(['ok' => false, 'error' => 'رمزِ عبور درست نیست.']);
        @set_time_limit(300);
        tout($action === 'restore' ? trash_restore($pdo, $siteRoot, (string)($data['name'] ?? ''), $uid) : trash_purge($pdo, $siteRoot, (string)($data['name'] ?? ''), $uid));
    }
    tout(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[trash_actions] ' . $e->getMessage());
    tout(['ok' => false, 'error' => 'خطا: ' . $e->getMessage()]);
}
