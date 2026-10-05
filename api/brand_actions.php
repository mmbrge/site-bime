<?php
// فایل: api/brand_actions.php
// «تنظیمات ← لوگو و فاوآیکن»: دیدن، آپلود و حذف (تغییر فقط با مدیر کل)
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_brand.php';

function bout($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) bout(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
$state = function () use ($pdo) {
    $b = brand_get($pdo);
    return ['ok' => true, 'logo' => $b['logo'] ? $b['logo'] + ['url' => brand_url($pdo, 'logo')] : null,
            'favicon' => $b['favicon'] ? $b['favicon'] + ['url' => brand_url($pdo, 'favicon')] : null];
};
if ($action === 'get') bout($state());
if (perm_real_role() !== 'ADMIN') bout(['ok' => false, 'error' => 'تغییرِ لوگو و فاوآیکن فقط کارِ مدیر کل است.']);
try {
    if ($action === 'upload') {
        $kind = ($data['kind'] ?? '') === 'favicon' ? 'favicon' : 'logo';
        $r = brand_store($pdo, $kind, $_FILES['file'] ?? []);
        if (empty($r['ok'])) bout($r);
        bout($state());
    }
    if ($action === 'remove') {
        brand_remove($pdo, ($data['kind'] ?? '') === 'favicon' ? 'favicon' : 'logo');
        bout($state());
    }
    bout(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[brand_actions] ' . $e->getMessage());
    bout(['ok' => false, 'error' => 'خطای سرور.']);
}
