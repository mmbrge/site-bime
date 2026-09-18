<?php
// فایل: api/staff_users_actions.php
// مدیریت حساب‌های کاربری داخلیِ خودِ پنل (users: ADMIN/OPERATOR/FINANCE/COMPANY_LIAISON).
// این فایل کاملاً جدا از api/user_actions.php است (آن یکی برای «کاربران ربات بله»/پرسنل است).
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    echo json_encode(['ok' => false, 'error' => 'این بخش فقط برای مدیر کل است.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? ($_GET['action'] ?? '');
$validRoles = ['ADMIN', 'OPERATOR', 'FINANCE', 'COMPANY_LIAISON'];

try {
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, username, full_name, role, mobile_number, created_at FROM users ORDER BY created_at DESC");
        echo json_encode(['ok' => true, 'users' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create') {
        $username = trim($data['username'] ?? '');
        $password = (string)($data['password'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        $role = in_array($data['role'] ?? '', $validRoles, true) ? $data['role'] : 'OPERATOR';
        if ($username === '' || $password === '' || $fullName === '') {
            echo json_encode(['ok' => false, 'error' => 'همه‌ی فیلدها الزامی هستند.']);
            exit;
        }
        if (strlen($password) < 6) {
            echo json_encode(['ok' => false, 'error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'این نام کاربری قبلاً استفاده شده.']); exit; }

        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, mobile_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, trim($data['mobile_number'] ?? '') ?: null]);
        echo json_encode(['ok' => true, 'user_id' => $pdo->lastInsertId()]);
        exit;
    }

    // تغییر نقش یک کاربر (نه رمز عبورش)
    if ($action === 'update_role') {
        $userId = intval($data['id'] ?? 0);
        $role = in_array($data['role'] ?? '', $validRoles, true) ? $data['role'] : null;
        if (!$userId || !$role) { echo json_encode(['ok' => false, 'error' => 'ورودی نامعتبر است.']); exit; }
        if ($userId === intval($_SESSION['user_id']) && $role !== 'ADMIN') {
            echo json_encode(['ok' => false, 'error' => 'نمی‌توانید نقش خودتان را از مدیر کل خارج کنید.']);
            exit;
        }
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // بازنشانی رمز عبور یک کاربر (توسط مدیر کل)
    if ($action === 'reset_password') {
        $userId = intval($data['id'] ?? 0);
        $password = (string)($data['password'] ?? '');
        if (!$userId || strlen($password) < 6) {
            echo json_encode(['ok' => false, 'error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']);
            exit;
        }
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[staff_users_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
