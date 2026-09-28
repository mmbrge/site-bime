<?php
// فایل: api/staff_users_actions.php
// مدیریت حساب‌های کاربریِ داخلیِ خودِ پنل (users: ADMIN/OPERATOR/FINANCE/COMPANY_LIAISON).
// این فایل کاملاً جدا از api/user_actions.php است (آن یکی برای «کاربران ربات بله»/پرسنل است).
//  - ویرایشِ همه‌چیز جز نام کاربری، و حذف، فقط با واردکردنِ رمزِ خودِ مدیر
//  - حذفِ نرم: کاربر دیگر وارد نمی‌شود ولی نامش روی همه‌ی کارهایی که کرده باقی می‌ماند
//  - لاگ ورود، و درخواست‌های بازیابی رمز که از ربات بله‌ی شرکت‌ها می‌آید
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_auth_helpers.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    echo json_encode(['ok' => false, 'error' => 'این بخش فقط برای مدیر کل است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? ($_GET['action'] ?? '');
$validRoles = ['ADMIN', 'OPERATOR', 'FINANCE', 'COMPANY_LIAISON'];
$me = intval($_SESSION['user_id']);
$ready = auth_schema_ready($pdo);

function out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

// تاییدِ عملیاتِ حساس با رمزِ خودِ مدیر
function require_admin_password($pdo, $me, $pwd) {
    $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $st->execute([$me]);
    $hash = $st->fetchColumn();
    if ($pwd === '' || !$hash || !password_verify($pwd, $hash)) out(['ok' => false, 'error' => 'رمز عبورِ مدیر (رمز خودتان) نادرست است.']);
}

try {
    if ($action === 'list') {
        $cols = $ready ? ', personnel_code, bale_chat_id IS NOT NULL AND bot_linked_at IS NOT NULL AS bot_linked, bot_linked_at, is_deleted, deleted_at' : '';
        $where = ($ready && empty($data['include_deleted'])) ? 'WHERE COALESCE(is_deleted, 0) = 0' : '';
        $users = $pdo->query("SELECT id, username, full_name, role, mobile_number, created_at $cols FROM users $where ORDER BY created_at DESC")->fetchAll();
        foreach ($users as &$u) {
            $u['bot_linked'] = !empty($u['bot_linked']);
            if ($ready) {
                $st = $pdo->prepare("SELECT created_at, browser, device FROM login_logs WHERE user_type = 'STAFF' AND user_id = ? AND success = 1 ORDER BY id DESC LIMIT 1");
                $st->execute([$u['id']]);
                $last = $st->fetch();
                $u['last_login_jalali'] = $last ? jalali_dt($last['created_at']) : null;
            }
        }
        unset($u);
        $pending = 0;
        if ($ready) $pending = intval($pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'PENDING'")->fetchColumn());
        out(['ok' => true, 'users' => $users, 'schema_ready' => $ready, 'pending_resets' => $pending, 'me' => $me]);
    }

    if ($action === 'create') {
        $username = trim($data['username'] ?? '');
        $password = (string)($data['password'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        $role = in_array($data['role'] ?? '', $validRoles, true) ? $data['role'] : 'OPERATOR';
        $mobile = trim($data['mobile_number'] ?? '');
        if ($username === '' || $password === '' || $fullName === '') out(['ok' => false, 'error' => 'نام، نام کاربری و رمز عبور الزامی است.']);
        if (strlen($password) < 6) out(['ok' => false, 'error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']);
        if ($mobile !== '' && auth_norm_phone($mobile) === '') out(['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۱۲۳۴۵۶۷).']);
        $st = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$username]);
        if ($st->fetchColumn()) out(['ok' => false, 'error' => 'این نام کاربری قبلاً استفاده شده.']);
        $mobile = $mobile !== '' ? auth_norm_phone($mobile) : null;
        if ($ready) {
            $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, mobile_number, personnel_code) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $mobile, trim(p2e_digits($data['personnel_code'] ?? '')) ?: null]);
        } else {
            $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, mobile_number) VALUES (?, ?, ?, ?, ?)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $mobile]);
        }
        out(['ok' => true, 'user_id' => $pdo->lastInsertId()]);
    }

    // ویرایشِ کامل (نام کاربری عوض نمی‌شود) - با رمزِ مدیر
    if ($action === 'update') {
        if (!$ready) out(['ok' => false, 'error' => AUTH_SCHEMA_MSG]);
        require_admin_password($pdo, $me, (string)($data['admin_password'] ?? ''));
        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND COALESCE(is_deleted, 0) = 0");
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) out(['ok' => false, 'error' => 'کاربر یافت نشد.']);
        $fullName = trim($data['full_name'] ?? '');
        $role = in_array($data['role'] ?? '', $validRoles, true) ? $data['role'] : null;
        $mobileIn = trim($data['mobile_number'] ?? '');
        $mobile = $mobileIn === '' ? null : auth_norm_phone($mobileIn);
        $password = (string)($data['password'] ?? '');
        if ($fullName === '' || !$role) out(['ok' => false, 'error' => 'نام و نقش الزامی است.']);
        if ($mobileIn !== '' && !$mobile) out(['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۱۲۳۴۵۶۷).']);
        if ($password !== '' && strlen($password) < 6) out(['ok' => false, 'error' => 'رمز عبورِ جدید باید حداقل ۶ کاراکتر باشد.']);
        if ($id === $me && $role !== 'ADMIN') out(['ok' => false, 'error' => 'نمی‌توانید نقشِ خودتان را از مدیر کل خارج کنید.']);

        $phoneChanged = auth_norm_phone($u['mobile_number']) !== (string)$mobile;
        $pdo->prepare("UPDATE users SET full_name = ?, role = ?, mobile_number = ?, personnel_code = ? WHERE id = ?")
            ->execute([$fullName, $role, $mobile, trim(p2e_digits($data['personnel_code'] ?? '')) ?: null, $id]);
        if ($password !== '') $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        // شماره عوض شد: از ربات بیرون می‌آید و باید با شماره‌ی جدید دوباره احراز هویت کند
        if ($phoneChanged && !empty($u['bale_chat_id'])) {
            auth_unlink_bot($pdo, 'STAFF', $id, "⚠️ {$fullName} عزیز، شماره‌ی تماسِ حساب شما در پنل «بیمه با ما» تغییر کرد.\nاتصال این گفتگو قطع شد؛ لطفاً با شماره‌ی جدید دوباره وارد شوید.");
        }
        out(['ok' => true, 'bot_unlinked' => $phoneChanged && !empty($u['bale_chat_id'])]);
    }

    // حذفِ نرم - با رمزِ مدیر
    if ($action === 'delete') {
        if (!$ready) out(['ok' => false, 'error' => AUTH_SCHEMA_MSG]);
        require_admin_password($pdo, $me, (string)($data['admin_password'] ?? ''));
        $id = intval($data['id'] ?? 0);
        if ($id === $me) out(['ok' => false, 'error' => 'نمی‌توانید حساب خودتان را حذف کنید.']);
        $st = $pdo->prepare("SELECT full_name FROM users WHERE id = ? AND COALESCE(is_deleted, 0) = 0");
        $st->execute([$id]);
        $name = $st->fetchColumn();
        if ($name === false) out(['ok' => false, 'error' => 'کاربر یافت نشد.']);
        auth_unlink_bot($pdo, 'STAFF', $id, "حساب کاربری شما در پنل «بیمه با ما» غیرفعال شد و این گفتگو قطع شد.");
        $pdo->prepare("UPDATE users SET is_deleted = 1, deleted_at = NOW() WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE phone_otps SET is_used = 1 WHERE user_type = 'STAFF' AND user_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE password_reset_requests SET status = 'REJECTED', note = 'کاربر حذف شد', handled_by = ?, handled_at = NOW() WHERE user_type = 'STAFF' AND user_id = ? AND status = 'PENDING'")->execute([$me, $id]);
        try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'DELETE', 'users', ?, ?)")->execute([$me, $id, $name]); } catch (Throwable $e) {}
        out(['ok' => true]);
    }

    // سازگاری با نسخه‌ی قبلی
    if ($action === 'update_role') {
        $userId = intval($data['id'] ?? 0);
        $role = in_array($data['role'] ?? '', $validRoles, true) ? $data['role'] : null;
        if (!$userId || !$role) out(['ok' => false, 'error' => 'ورودی نامعتبر است.']);
        if ($userId === $me && $role !== 'ADMIN') out(['ok' => false, 'error' => 'نمی‌توانید نقش خودتان را از مدیر کل خارج کنید.']);
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $userId]);
        out(['ok' => true]);
    }

    // ---------------- لاگ ورود ----------------
    if ($action === 'login_logs') {
        if (!$ready) out(['ok' => false, 'error' => AUTH_SCHEMA_MSG]);
        $rows = $pdo->query("SELECT * FROM login_logs ORDER BY id DESC LIMIT 2000")->fetchAll();
        foreach ($rows as &$r) {
            $r['at_jalali'] = jalali_dt($r['created_at']);
            $r['role_fa'] = $r['user_type'] === 'COMPANY' ? 'کاربر شرکت' : auth_role_fa('STAFF', $r['role']);
            unset($r['user_agent']);
        }
        unset($r);
        out(['ok' => true, 'rows' => $rows]);
    }

    // ---------------- درخواست‌های بازیابی رمز ----------------
    if ($action === 'reset_requests') {
        if (!$ready) out(['ok' => false, 'error' => AUTH_SCHEMA_MSG]);
        $rows = $pdo->query("SELECT r.*, COALESCE(u.full_name, cpu.full_name) AS full_name, COALESCE(u.username, cpu.username) AS username,
                                    COALESCE(u.mobile_number, cpu.mobile_number) AS mobile_number, u.role, h.full_name AS handled_by_name
                               FROM password_reset_requests r
                               LEFT JOIN users u ON r.user_type = 'STAFF' AND u.id = r.user_id
                               LEFT JOIN company_portal_users cpu ON r.user_type = 'COMPANY' AND cpu.id = r.user_id
                               LEFT JOIN users h ON h.id = r.handled_by
                              ORDER BY (r.status = 'PENDING') DESC, r.id DESC LIMIT 300")->fetchAll();
        foreach ($rows as &$r) {
            $r['role_fa'] = auth_role_fa($r['user_type'], $r['role']);
            $r['requested_jalali'] = jalali_dt($r['requested_at']);
            $r['handled_jalali'] = $r['handled_at'] ? jalali_dt($r['handled_at']) : null;
        }
        unset($r);
        out(['ok' => true, 'rows' => $rows]);
    }

    if ($action === 'handle_reset') {
        if (!$ready) out(['ok' => false, 'error' => AUTH_SCHEMA_MSG]);
        $id = intval($data['id'] ?? 0);
        $approve = !empty($data['approve']);
        $st = $pdo->prepare("SELECT * FROM password_reset_requests WHERE id = ? AND status = 'PENDING'");
        $st->execute([$id]);
        $req = $st->fetch();
        if (!$req) out(['ok' => false, 'error' => 'این درخواست پیدا نشد یا قبلاً بررسی شده.']);
        $acc = auth_load_account($pdo, $req['user_type'], $req['user_id']);
        if (!$acc) out(['ok' => false, 'error' => 'حسابِ این درخواست دیگر وجود ندارد.']);
        $u = $acc['user'];
        $table = $req['user_type'] === 'STAFF' ? 'users' : 'company_portal_users';
        if ($approve) {
            $pwd = (string)($data['new_password'] ?? '');
            if (strlen($pwd) < 6) out(['ok' => false, 'error' => 'رمزِ جدید باید حداقل ۶ کاراکتر باشد.']);
            $pdo->prepare("UPDATE $table SET password_hash = ? WHERE id = ?")->execute([password_hash($pwd, PASSWORD_DEFAULT), $u['id']]);
            $pdo->prepare("UPDATE password_reset_requests SET status = 'APPROVED', handled_by = ?, handled_at = NOW() WHERE id = ?")->execute([$me, $id]);
            $sent = cbot_send($pdo, $u['bale_chat_id'], "✅ {$u['full_name']} عزیز، درخواست بازیابی رمز شما تایید شد.\n\n👤 نام کاربری: {$u['username']}\n🔑 رمز جدید: {$pwd}\n\nبعد از ورود، این پیام را پاک کنید.");
            out(['ok' => true, 'sent' => (bool)$sent]);
        }
        $note = trim($data['note'] ?? '');
        $pdo->prepare("UPDATE password_reset_requests SET status = 'REJECTED', note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?")->execute([$note ?: null, $me, $id]);
        $sent = cbot_send($pdo, $u['bale_chat_id'], "❌ {$u['full_name']} عزیز، درخواست بازیابی رمز شما رد شد." . ($note ? "\nتوضیح: {$note}" : '') . "\nبرای پیگیری با مدیر سامانه تماس بگیرید.");
        out(['ok' => true, 'sent' => (bool)$sent]);
    }

    out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[staff_users_actions] ' . $e->getMessage());
    out(['ok' => false, 'error' => 'خطای سرور.']);
}

function jalali_dt($ts) {
    if (!$ts) return null;
    $t = strtotime($ts);
    return jalali_from_gregorian_ts_dotted($t) . ' ' . date('H:i', $t);
}
