<?php
// فایل: api/company_portal_auth.php
// ورود/خروج ثبت‌کننده‌ی شرکت درخواست‌کننده (پنل جدا، سشن جدا از پنل ادمین)
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';

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
        if ($lockMin = sec_login_locked($pdo, $username)) {
            echo json_encode(['ok' => false, 'error' => "به دلیلِ تلاش‌های ناموفقِ زیاد، ورود برای {$lockMin} دقیقه قفل شد."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM company_portal_users WHERE username = ? AND is_active = 1" . (auth_schema_ready($pdo) ? " AND COALESCE(is_deleted, 0) = 0" : ''));
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            if ($user) auth_log_login($pdo, 'COMPANY', $user, 'PASSWORD', false, 'رمز اشتباه');
            $msg = 'نام کاربری یا رمز عبور نادرست است.';
            sec_login_failed($pdo, $username, 'ورودِ کارشناسِ شرکت');
            if (!$user) {   // شاید همکارِ بیمه باشد که درگاهِ اشتباه را انتخاب کرده
                try {
                    $st = $pdo->prepare("SELECT password_hash FROM users WHERE username = ?" . (auth_schema_ready($pdo) ? " AND COALESCE(is_deleted, 0) = 0" : ''));
                    $st->execute([$username]);
                    $h = $st->fetchColumn();
                    if ($h && password_verify($password, $h)) $msg = 'این حساب «همکارِ بیمه» است؛ بالای فرم «همکارِ بیمه» را انتخاب کنید.';
                } catch (Throwable $e) {}
            }
            echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
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
        auth_log_login($pdo, 'COMPANY', $user, 'PASSWORD', true);
        sec_on_login($pdo, 'COMPANY', $user['id'], $user['full_name']);
        echo json_encode(['ok' => true, 'full_name' => $user['full_name'], 'companies' => $companies]);
        exit;
    }

    if ($action === 'logout') {
        if (!empty($_SESSION['company_user_id'])) {
            $st = $pdo->prepare("SELECT id, full_name, username FROM company_portal_users WHERE id = ?");
            $st->execute([intval($_SESSION['company_user_id'])]);
            if ($u = $st->fetch()) auth_log_login($pdo, 'COMPANY', $u, 'LOGOUT', true);
            require_once __DIR__ . '/_profile_core.php';
            presence_off($pdo, 'COMPANY', intval($_SESSION['company_user_id']));   // «آخرین بازدید» همین لحظه
            if (function_exists('sec_device_end_current')) sec_device_end_current($pdo, 'LOGOUT');
        }
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
