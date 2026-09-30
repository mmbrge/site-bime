<?php
session_start();
// خروج هم در «لاگ ورود و خروج» ثبت می‌شود
if (!empty($_SESSION['user_id'])) {
    try {
        require 'config/db.php';
        require_once __DIR__ . '/api/_auth_helpers.php';
        $st = $pdo->prepare("SELECT id, full_name, username, role FROM users WHERE id = ?");
        $st->execute([intval($_SESSION['user_id'])]);
        if ($u = $st->fetch()) auth_log_login($pdo, 'STAFF', $u, 'LOGOUT', true);
        // «آخرین بازدید» همین لحظه ثبت شود و دیگر آنلاین نشان داده نشود
        require_once __DIR__ . '/api/_profile_core.php';
        presence_off($pdo, 'STAFF', intval($_SESSION['user_id']));
    } catch (Throwable $e) {}
}
session_unset();
session_destroy();
header("Location: index.php");
exit;
?>