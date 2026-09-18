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
        $stmt = $pdo->prepare("SELECT cpu.*, c.name AS company_name FROM company_portal_users cpu
                                JOIN companies c ON c.id = cpu.company_id
                                WHERE cpu.username = ? AND cpu.is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            echo json_encode(['ok' => false, 'error' => 'نام کاربری یا رمز عبور نادرست است.']);
            exit;
        }
        session_regenerate_id(true); // جلوگیری از تثبیت نشست
        $_SESSION['company_user_id'] = $user['id'];
        $_SESSION['company_id'] = $user['company_id'];
        $_SESSION['company_user_full_name'] = $user['full_name'];
        $_SESSION['company_name'] = $user['company_name'];
        echo json_encode(['ok' => true, 'full_name' => $user['full_name'], 'company_name' => $user['company_name']]);
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'me') {
        if (empty($_SESSION['company_user_id'])) {
            echo json_encode(['ok' => true, 'logged_in' => false]);
            exit;
        }
        echo json_encode([
            'ok' => true, 'logged_in' => true,
            'full_name' => $_SESSION['company_user_full_name'] ?? '',
            'company_name' => $_SESSION['company_name'] ?? '',
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[company_portal_auth] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
