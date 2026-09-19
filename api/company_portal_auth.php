<?php
// فایل: api/company_portal_auth.php
// ورود/خروج ثبت‌کننده‌ی شرکت درخواست‌کننده (پنل جدا، سشن جدا از پنل ادمین)
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';

company_portal_session_start();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? ($_GET['action'] ?? '');

try {
    if ($action === 'login') {
        $username = trim($data['username'] ?? '');
        $password = (string)($data['password'] ?? '');
        if ($username === '' || $password === '') {
            echo json_encode(['ok' => false, 'error' => 'نام کاربری و رمز عبور را وارد کنید.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM company_portal_users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'نام کاربری یا رمز عبور نادرست است.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT c.id, c.name, c.allowed_insurers FROM company_portal_user_companies cpuc
                                JOIN companies c ON c.id = cpuc.company_id
                                WHERE cpuc.portal_user_id = ? ORDER BY c.name");
        $stmt->execute([$user['id']]);
        $companies = $stmt->fetchAll();
        if (!$companies) {
            echo json_encode(['ok' => false, 'error' => 'این حساب کاربری به هیچ شرکتی وصل نیست. با ما تماس بگیرید.']);
            exit;
        }

        session_regenerate_id(true); // جلوگیری از تثبیت نشست
        $_SESSION['company_user_id'] = $user['id'];
        $_SESSION['company_user_full_name'] = $user['full_name'];
        echo json_encode(['ok' => true, 'full_name' => $user['full_name'], 'companies' => $companies]);
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[company_portal_auth] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
