<?php
// فایل: api/footer_actions.php
// «تنظیمات ← فوتر (توسعه‌دهنده و نسخه)»: خواندن و ذخیره (فقط مدیر کل)
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_footer.php';

function fout($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) fout(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
if (perm_real_role() !== 'ADMIN') fout(['ok' => false, 'error' => 'تنظیماتِ فوتر فقط کارِ مدیر کل است.']);
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
try {
    if ($action === 'get') fout(['ok' => true, 'panel' => footer_get($pdo, 'panel'), 'login' => footer_get($pdo, 'login'),
                                 'defaults' => ['panel' => footer_defaults('panel'), 'login' => footer_defaults('login')]]);
    if ($action === 'save') fout(['ok' => true] + footer_save($pdo, $data));
    fout(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[footer_actions] ' . $e->getMessage());
    fout(['ok' => false, 'error' => 'خطای سرور.']);
}
