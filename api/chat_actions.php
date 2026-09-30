<?php
// فایل: api/chat_actions.php
// پیام‌رسانِ یکپارچه برای همه‌ی کاربرانِ پنل (مدیر، صدور، مالی، همکاران بیمه با ما و پنل پارسیان).
// منطق در api/_chat_core.php است؛ اینجا فقط کاربرِ پنل شناسایی می‌شود.
// بدنه‌ی JSON به‌صورت base64 هم پذیرفته می‌شود (فایروالِ هاست روی متنِ پیام‌ها حساس نشود).
session_start();
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_chat_core.php';

function chat_out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) chat_out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
if (isset($data['p']) && is_string($data['p'])) {
    $dec = json_decode((string)base64_decode($data['p'], true), true);
    if (is_array($dec)) $data = $dec + ['action' => $data['action'] ?? ''];
}
$action = $data['action'] ?? ($_GET['action'] ?? '');

try {
    $st = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
    $st->execute([intval($_SESSION['user_id'])]);
    $u = $st->fetch();
    if (!$u) chat_out(['ok' => false, 'error' => 'کاربر پیدا نشد.']);
    $actor = ['kind' => 'STAFF', 'id' => intval($u['id']), 'role' => $u['role'], 'name' => $u['full_name']];
    chat_out(chat_dispatch($pdo, $actor, $action, $data, $_FILES));
} catch (Throwable $e) {
    error_log('[chat_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    chat_out(['ok' => false, 'error' => 'خطای سرور.']);
}
