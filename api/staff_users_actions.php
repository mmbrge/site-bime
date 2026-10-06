<?php
// فایل: api/staff_users_actions.php
// مدیریت یکپارچه‌ی حساب‌های کاربری: کاربرانِ داخلیِ پنل (users: ADMIN/OPERATOR/FINANCE/COMPANY_LIAISON/PARSIAN)
// و کاربرانِ شرکت‌ها (company_portal_users) - هر رکورد با type = STAFF | COMPANY مشخص می‌شود.
// این فایل کاملاً جدا از api/user_actions.php است (آن یکی برای «کاربران ربات بله»/پرسنل است).
//  - شماره‌ی موبایل بین همه‌ی کاربران (داخلی و شرکتی) تکراری نمی‌شود
//  - پیام مستقیم: از طریق ربات بله‌ی شرکت‌ها (و برای کاربران داخلی در چت داخلیِ پنل هم ثبت می‌شود)
//  - ویرایشِ همه‌چیز جز نام کاربری، و حذف، فقط با واردکردنِ رمزِ خودِ مدیر
//  - حذفِ نرم: کاربر دیگر وارد نمی‌شود ولی نامش روی همه‌ی کارهایی که کرده باقی می‌ماند
//  - لاگ ورود، و درخواست‌های بازیابی رمز که از ربات بله‌ی شرکت‌ها می‌آید
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_import_schema.php'; imp_ensure($pdo);   // فیلترِ شرکت‌های «بایگانی وارداتی»
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_chat_access.php';
require_once __DIR__ . '/_profile_core.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    echo json_encode(['ok' => false, 'error' => 'این بخش فقط برای مدیر کل است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? ($_GET['action'] ?? '');
$validRoles = ['ADMIN', 'OPERATOR', 'FINANCE', 'COMPANY_LIAISON', 'PARSIAN', 'LIFE'];
require_once __DIR__ . '/_life.php';
life_role_ensure($pdo);   // نقشِ «کاربر بیمه عمر» در ستونِ ENUMِ users.role
$me = intval($_SESSION['user_id']);
$ready = auth_schema_ready($pdo);

function out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

// تاییدِ عملیاتِ حساس با رمزِ خودِ مدیر
// رمزِ جداگانه‌ی ویرایشِ گزارش بازدید (مایگریشن ۰۲۴): $pwd خالی = دست نخورد؛ $clear = برداشتن (برگشت به رمزِ پنل)
function staff_set_report_pw($pdo, $id, $pwd, $clear) {
    try {
        if ($clear) $pdo->prepare("UPDATE users SET report_edit_password_hash = NULL WHERE id = ?")->execute([$id]);
        elseif ($pwd !== '') $pdo->prepare("UPDATE users SET report_edit_password_hash = ? WHERE id = ?")->execute([password_hash($pwd, PASSWORD_DEFAULT), $id]);
        return true;
    } catch (Throwable $e) { return false; }
}
function staff_report_pw_ready($pdo) {
    static $ok = null;
    if ($ok === null) { try { $pdo->query("SELECT report_edit_password_hash FROM users LIMIT 0"); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}
const STAFF_REPORT_PW_MSG = 'برای «رمزِ ویرایشِ گزارش»، مایگریشن migrations/024_report_edit_password.sql را اجرا کنید.';

// تعدادِ دستگاه‌های فعالِ هر کاربر («STAFF:12» => ۲)
function su_devices_alive_counts($pdo) {
    $n = [];
    try {
        $st = $pdo->prepare("SELECT user_type, user_id, COUNT(*) c FROM sec_devices WHERE " . sec_device_alive_sql() . " GROUP BY user_type, user_id");
        $st->execute([sec_idle_minutes($pdo)]);
        foreach ($st->fetchAll() as $r) $n[$r['user_type'] . ':' . $r['user_id']] = intval($r['c']);
    } catch (Throwable $e) {}
    return $n;
}
function require_admin_password($pdo, $me, $pwd) {
    $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $st->execute([$me]);
    $hash = $st->fetchColumn();
    if ($pwd === '' || !$hash || !password_verify($pwd, $hash)) out(['ok' => false, 'error' => 'رمز عبورِ مدیر (رمز خودتان) نادرست است.']);
}

// کاربرِ با دسترسیِ سفارشی (نه مدیر کلِ واقعی): به حسابِ مدیرانِ کل و حسابِ خودش دست نمی‌زند،
// کسی را مدیر کل نمی‌کند و دسترسی‌ها را تغییر نمی‌دهد
$realAdmin = perm_real_role() === 'ADMIN';
if (!$realAdmin) {
    if ($action === 'perm_save') out(['ok' => false, 'error' => 'تعیینِ دسترسی‌ها فقط کارِ مدیر کل است.']);
    if (in_array($action, ['create', 'update', 'update_role', 'delete'], true)) {
        if (($data['role'] ?? '') === 'ADMIN') out(['ok' => false, 'error' => 'فقط مدیر کل می‌تواند کاربرِ «مدیر کل» بسازد یا نقشی را مدیر کل کند.']);
        $tid = intval($data['id'] ?? 0);
        if ($action !== 'create' && ($data['type'] ?? 'STAFF') !== 'COMPANY' && $tid) {
            if ($tid === $me) out(['ok' => false, 'error' => 'حسابِ خودتان را از «ویرایش پروفایل» تغییر دهید.']);
            $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $st->execute([$tid]);
            if ($st->fetchColumn() === 'ADMIN') out(['ok' => false, 'error' => 'حسابِ مدیر کل را فقط مدیر کل تغییر می‌دهد.']);
        }
    }
}

try {
    // ---- دسترسیِ سفارشیِ صفحه به صفحه (api/_perm.php) ----
    if ($action === 'perm_get') {
        perm_ensure($pdo);
        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT id, full_name, role, perm_json FROM users WHERE id = ?");
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) out(['ok' => false, 'error' => 'کاربر یافت نشد.']);
        $cat = [];
        foreach (perm_catalog() as [$g, $items]) {
            $rows = [];
            foreach ($items as $k => [$title, $ops]) $rows[] = ['key' => $k, 'title' => $title, 'ops' => $ops];
            $cat[] = ['group' => $g, 'pages' => $rows];
        }
        $others = [];
        foreach ($pdo->query("SELECT id, full_name, role, perm_json IS NOT NULL AS custom FROM users WHERE id <> " . $id . (auth_schema_ready($pdo) ? " AND COALESCE(is_deleted, 0) = 0" : '') . " ORDER BY full_name")->fetchAll() as $o) {
            if ($o['role'] !== 'ADMIN') $others[] = ['id' => intval($o['id']), 'name' => $o['full_name'], 'role' => $o['role'], 'custom' => !empty($o['custom'])];
        }
        out(['ok' => true, 'user' => ['id' => intval($u['id']), 'name' => $u['full_name'], 'role' => $u['role']],
             'custom' => $u['perm_json'] !== null && $u['perm_json'] !== '', 'perms' => (object)($u['perm_json'] ? perm_sanitize(json_decode($u['perm_json'], true)) : []),
             'role_default' => (object)perm_role_defaults($u['role']), 'catalog' => $cat, 'ops' => PERM_OPS, 'others' => $others]);
    }
    // دسترسیِ کاربرِ دیگر (برای «کپی از کاربر…»)
    if ($action === 'perm_peek') {
        perm_ensure($pdo);
        $st = $pdo->prepare("SELECT role, perm_json FROM users WHERE id = ?");
        $st->execute([intval($data['id'] ?? 0)]);
        $u = $st->fetch();
        if (!$u) out(['ok' => false, 'error' => 'کاربر یافت نشد.']);
        out(['ok' => true, 'perms' => (object)($u['perm_json'] ? perm_sanitize(json_decode($u['perm_json'], true)) : perm_role_defaults($u['role']))]);
    }
    if ($action === 'perm_save') {
        perm_ensure($pdo);
        require_admin_password($pdo, $me, (string)($data['admin_password'] ?? ''));
        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $st->execute([$id]);
        $role = $st->fetchColumn();
        if ($role === false) out(['ok' => false, 'error' => 'کاربر یافت نشد.']);
        if ($role === 'ADMIN') out(['ok' => false, 'error' => 'مدیر کل همیشه به همه‌چیز دسترسی دارد؛ برای محدود کردن، اول نقشش را عوض کنید.']);
        if (($data['mode'] ?? '') !== 'custom') {
            $pdo->prepare("UPDATE users SET perm_json = NULL WHERE id = ?")->execute([$id]);
            out(['ok' => true, 'custom' => false]);
        }
        $perms = perm_sanitize($data['perms'] ?? []);
        $pdo->prepare("UPDATE users SET perm_json = ? WHERE id = ?")->execute([json_encode((object)$perms, JSON_UNESCAPED_UNICODE), $id]);
        out(['ok' => true, 'custom' => true, 'pages' => count($perms)]);
    }

    // ---- دستگاه‌های همزمان: فهرست، سقف، بستن ----
    if (in_array($action, ['devices_get', 'devices_set_max', 'device_end', 'devices_end_all'], true)) {
        sec_ensure($pdo);
        $type = ($data['type'] ?? '') === 'COMPANY' ? 'COMPANY' : 'STAFF';
        $id = intval($data['id'] ?? 0);
        $tbl = $type === 'COMPANY' ? 'company_portal_users' : 'users';
        $st = $pdo->prepare("SELECT * FROM $tbl WHERE id = ?");
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) out(['ok' => false, 'error' => 'کاربر پیدا نشد.']);
        $isAdmin = $type === 'STAFF' && $u['role'] === 'ADMIN';
        if ($action === 'devices_set_max') {
            if ($isAdmin) out(['ok' => false, 'error' => 'مدیر کل محدودیتِ دستگاه ندارد.']);
            require_admin_password($pdo, $me, (string)($data['admin_password'] ?? ''));
            $max = max(1, min(10, intval($data['max'] ?? 1)));
            $pdo->prepare("UPDATE $tbl SET max_devices = ? WHERE id = ?")->execute([$max, $id]);
            $u['max_devices'] = $max;
            // اگر سقف کم شد، دستگاه‌های اضافه (قدیمی‌ترها) همین الان بسته می‌شوند
            $keep = $pdo->prepare("SELECT token FROM sec_devices WHERE user_type = ? AND user_id = ? AND " . sec_device_alive_sql() . " ORDER BY last_seen DESC LIMIT 1");
            $keep->execute([$type, $id, sec_idle_minutes($pdo)]);
            sec_device_enforce($pdo, $type, $id, (string)$keep->fetchColumn());
            sec_event($pdo, 'ADMIN_ACTION', 'سقفِ دستگاه‌های همزمانِ «' . $u['full_name'] . '» = ' . $max);
        }
        if ($action === 'device_end') {
            $pdo->prepare("UPDATE sec_devices SET ended_at = NOW(), end_reason = 'ADMIN' WHERE id = ? AND user_type = ? AND user_id = ? AND ended_at IS NULL")
                ->execute([intval($data['device_id'] ?? 0), $type, $id]);
            sec_event($pdo, 'DEVICE_END', 'یک دستگاهِ «' . $u['full_name'] . '» بسته شد');
        }
        if ($action === 'devices_end_all') {
            $mine = $type === 'STAFF' && $id === $me ? (string)($_SESSION['_sec_dev'] ?? '') : '';
            $pdo->prepare("UPDATE sec_devices SET ended_at = NOW(), end_reason = 'ADMIN' WHERE user_type = ? AND user_id = ? AND ended_at IS NULL AND token <> ?")->execute([$type, $id, $mine]);
            sec_event($pdo, 'DEVICE_END', 'همه‌ی دستگاه‌های «' . $u['full_name'] . '» بسته شد');
        }
        $st = $pdo->prepare("SELECT id, ip, device, user_agent, created_at, last_seen, ended_at, end_reason, token, (" . sec_device_alive_sql() . ") AS alive
                               FROM sec_devices WHERE user_type = ? AND user_id = ? AND (ended_at IS NULL OR ended_at > DATE_SUB(NOW(), INTERVAL 7 DAY))
                              ORDER BY alive DESC, last_seen DESC LIMIT 30");
        $st->execute([sec_idle_minutes($pdo), $type, $id]);
        $mineTok = (string)($_SESSION['_sec_dev'] ?? '');
        $rows = array_map(function ($r) use ($mineTok) {
            return ['id' => intval($r['id']), 'ip' => $r['ip'], 'device' => $r['device'], 'ua' => $r['user_agent'], 'alive' => !empty($r['alive']),
                    'created' => str_replace('.', '/', jalali_dt($r['created_at'])), 'last_seen' => str_replace('.', '/', jalali_dt($r['last_seen'])), 'ended' => $r['ended_at'] ? str_replace('.', '/', jalali_dt($r['ended_at'])) : null,
                    'reason' => $r['end_reason'], 'mine' => $mineTok !== '' && hash_equals($r['token'], $mineTok)];
        }, $st->fetchAll());
        out(['ok' => true, 'name' => $u['full_name'], 'is_admin' => $isAdmin, 'max' => $isAdmin ? 0 : max(1, intval($u['max_devices'] ?? 1)), 'devices' => $rows]);
    }

    if ($action === 'list') {
        perm_ensure($pdo);
        $cols = $ready ? ', personnel_code, bale_chat_id IS NOT NULL AND bot_linked_at IS NOT NULL AS bot_linked, bot_linked_at, is_deleted, deleted_at' : '';
        $where = ($ready && empty($data['include_deleted'])) ? 'WHERE COALESCE(is_deleted, 0) = 0' : '';
        if (staff_report_pw_ready($pdo)) $cols .= ', report_edit_password_hash IS NOT NULL AS has_report_pw';
        chat_access_ensure($pdo);
        try { $pdo->query("SELECT chat_companies FROM users LIMIT 0"); $cols .= ', chat_companies'; } catch (Throwable $e) {}
        $cols .= ', perm_json IS NOT NULL AS perm_custom';
        sec_ensure($pdo);   // ستونِ سقفِ دستگاه و جدولِ دستگاه‌ها
        $cols .= ', max_devices';
        $users = $pdo->query("SELECT id, username, full_name, role, mobile_number, created_at $cols, " . prof_cols($pdo, 'users') . " FROM users $where ORDER BY created_at DESC")->fetchAll();
        $devN = su_devices_alive_counts($pdo);
        foreach ($users as &$u) {
            $u['chat_companies'] = chat_companies_decode($u['chat_companies'] ?? null);
            $u['bot_linked'] = !empty($u['bot_linked']);
            $u['has_report_pw'] = !empty($u['has_report_pw']);
            $u['perm_custom'] = !empty($u['perm_custom']) && $u['role'] !== 'ADMIN';
            $u['presence'] = prof_presence($u); unset($u['seen_ago'], $u['is_online']);
            if ($ready) {
                $st = $pdo->prepare("SELECT created_at, browser, device FROM login_logs WHERE user_type = 'STAFF' AND user_id = ? AND success = 1 AND method <> 'LOGOUT' ORDER BY id DESC LIMIT 1");
                $st->execute([$u['id']]);
                $last = $st->fetch();
                $u['last_login_jalali'] = $last ? jalali_dt($last['created_at']) : null;
            }
        }
        unset($u);
        foreach ($users as &$u) { $u['type'] = 'STAFF'; $u['max_devices'] = $u['role'] === 'ADMIN' ? 0 : max(1, intval($u['max_devices'])); $u['devices_active'] = $devN['STAFF:' . $u['id']] ?? 0; }
        unset($u);

        // کاربرانِ شرکت‌ها
        $cWhere = $ready ? (empty($data['include_deleted']) ? 'WHERE COALESCE(cpu.is_deleted, 0) = 0' : '') : 'WHERE cpu.is_active = 1';
        $cCols = $ready ? ', (cpu.bale_chat_id IS NOT NULL AND cpu.bot_linked_at IS NOT NULL) AS bot_linked, cpu.is_deleted, cpu.deleted_at' : '';
        $cUsers = $pdo->query("SELECT cpu.id, cpu.username, cpu.full_name, cpu.mobile_number, cpu.is_active, cpu.created_at, cpu.max_devices $cCols, " . prof_cols($pdo, 'cpu') . ",
                                      GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR '، ') AS company_names, GROUP_CONCAT(c.id) AS company_ids
                                 FROM company_portal_users cpu
                                 LEFT JOIN company_portal_user_companies cpuc ON cpuc.portal_user_id = cpu.id
                                 LEFT JOIN companies c ON c.id = cpuc.company_id
                                 $cWhere
                                GROUP BY cpu.id ORDER BY cpu.created_at DESC")->fetchAll();
        foreach ($cUsers as &$u) {
            $u['type'] = 'COMPANY';
            $u['role'] = 'COMPANY';
            $u['max_devices'] = max(1, intval($u['max_devices'])); $u['devices_active'] = $devN['COMPANY:' . $u['id']] ?? 0;
            $u['presence'] = prof_presence($u); unset($u['seen_ago'], $u['is_online']);
            $u['bot_linked'] = !empty($u['bot_linked']);
            $u['company_ids'] = $u['company_ids'] ? array_map('intval', explode(',', $u['company_ids'])) : [];
            if ($ready) {
                $st = $pdo->prepare("SELECT created_at FROM login_logs WHERE user_type = 'COMPANY' AND user_id = ? AND success = 1 AND method <> 'LOGOUT' ORDER BY id DESC LIMIT 1");
                $st->execute([$u['id']]);
                $last = $st->fetchColumn();
                $u['last_login_jalali'] = $last ? jalali_dt($last) : null;
            }
        }
        unset($u);
        $companies = $pdo->query("SELECT id, name FROM companies WHERE is_import = 0 ORDER BY name")->fetchAll();

        $pending = 0;
        if ($ready) { auth_purge_old_resets($pdo); $pending = intval($pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'PENDING'")->fetchColumn()); }
        out(['ok' => true, 'users' => array_merge($users, $cUsers), 'companies' => $companies, 'schema_ready' => $ready, 'pending_resets' => $pending, 'me' => $me,
             'profile_ready' => prof_ready($pdo)]);
    }

    // فقط حضور (برای به‌روزرسانیِ زنده‌ی ستونِ «آخرین بازدید» بدونِ بارگذاریِ دوباره‌ی کلِ فهرست)
    if ($action === 'presence') {
        $out = [];
        if (prof_ready($pdo)) {
            foreach ($pdo->query("SELECT id, " . prof_cols($pdo, 'users') . " FROM users")->fetchAll() as $r) $out['STAFF:' . $r['id']] = prof_presence($r);
            foreach ($pdo->query("SELECT id, " . prof_cols($pdo, 'company_portal_users') . " FROM company_portal_users")->fetchAll() as $r) $out['COMPANY:' . $r['id']] = prof_presence($r);
        }
        out(['ok' => true, 'presence' => $out]);
    }

    if ($action === 'create' && ($data['role'] ?? '') === 'COMPANY') {
        // ---- کاربرِ شرکت ----
        $username = trim($data['username'] ?? '');
        $password = (string)($data['password'] ?? '');
        $fullName = trim($data['full_name'] ?? '');
        $companyIds = company_ids_valid($pdo, $data['company_ids'] ?? []);
        $mobileIn = trim($data['mobile_number'] ?? '');
        if ($username === '' || $password === '' || $fullName === '') out(['ok' => false, 'error' => 'نام، نام کاربری و رمز عبور الزامی است.']);
        if (!$companyIds) out(['ok' => false, 'error' => 'حداقل یک شرکت را برای این کاربر انتخاب کنید.']);
        if (strlen($password) < 6) out(['ok' => false, 'error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.']);
        if ($mobileIn !== '' && auth_norm_phone($mobileIn) === '') out(['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۱۲۳۴۵۶۷).']);
        if ($mobileIn !== '' && ($o = auth_phone_owner($pdo, $mobileIn))) out(['ok' => false, 'error' => auth_phone_conflict_msg($o), 'field' => 'mobile']);
        $st = $pdo->prepare("SELECT id FROM company_portal_users WHERE username = ?");
        $st->execute([$username]);
        if ($st->fetchColumn()) out(['ok' => false, 'error' => 'این نام کاربری قبلاً برای کاربر شرکتیِ دیگری استفاده شده.']);
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO company_portal_users (company_id, username, password_hash, full_name, mobile_number) VALUES (?, ?, ?, ?, ?)")
            ->execute([$companyIds[0], $username, password_hash($password, PASSWORD_DEFAULT), $fullName, $mobileIn !== '' ? auth_norm_phone($mobileIn) : null]);
        $newId = $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO company_portal_user_companies (portal_user_id, company_id) VALUES (?, ?)");
        foreach ($companyIds as $cid) $ins->execute([$newId, $cid]);
        $pdo->commit();
        if (!empty($data['avatar'])) prof_set_avatar($pdo, 'COMPANY', $newId, $data['avatar']);
        out(['ok' => true, 'user_id' => $newId]);
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
        if ($mobile !== '' && ($o = auth_phone_owner($pdo, $mobile))) out(['ok' => false, 'error' => auth_phone_conflict_msg($o), 'field' => 'mobile']);
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
        $newId = intval($pdo->lastInsertId());
        if (array_key_exists('chat_companies', $data)) { chat_access_ensure($pdo); try { $pdo->prepare("UPDATE users SET chat_companies = ? WHERE id = ?")->execute([chat_companies_value($data['chat_companies']), $newId]); } catch (Throwable $e) {} }
        if (!empty($data['avatar'])) prof_set_avatar($pdo, 'STAFF', $newId, $data['avatar']);
        $rpw = (string)($data['report_edit_password'] ?? '');
        $warn = null;
        if ($rpw !== '') { if (strlen($rpw) < 4) $warn = 'رمزِ ویرایشِ گزارش باید حداقل ۴ کاراکتر باشد؛ ثبت نشد.'; elseif (!staff_set_report_pw($pdo, $newId, $rpw, false)) $warn = STAFF_REPORT_PW_MSG; }
        out(['ok' => true, 'user_id' => $newId, 'warning' => $warn]);
    }

    // ویرایشِ کامل (نام کاربری عوض نمی‌شود) - با رمزِ مدیر
    if ($action === 'update' && ($data['type'] ?? '') === 'COMPANY') {
        // ---- کاربرِ شرکت (نام کاربری ثابت) ----
        if (!$ready) out(['ok' => false, 'error' => AUTH_SCHEMA_MSG]);
        require_admin_password($pdo, $me, (string)($data['admin_password'] ?? ''));
        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM company_portal_users WHERE id = ? AND COALESCE(is_deleted, 0) = 0");
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) out(['ok' => false, 'error' => 'کاربر یافت نشد.']);
        $fullName = trim($data['full_name'] ?? '');
        $companyIds = company_ids_valid($pdo, $data['company_ids'] ?? []);
        $mobileIn = trim($data['mobile_number'] ?? '');
        $mobile = $mobileIn === '' ? null : auth_norm_phone($mobileIn);
        $password = (string)($data['password'] ?? '');
        if ($fullName === '') out(['ok' => false, 'error' => 'نام الزامی است.']);
        if (!$companyIds) out(['ok' => false, 'error' => 'حداقل یک شرکت را انتخاب کنید.']);
        if ($mobileIn !== '' && !$mobile) out(['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۱۲۳۴۵۶۷).']);
        if ($password !== '' && strlen($password) < 6) out(['ok' => false, 'error' => 'رمز عبورِ جدید باید حداقل ۶ کاراکتر باشد.']);
        $phoneChanged = auth_norm_phone($u['mobile_number']) !== (string)$mobile;
        if ($phoneChanged && $mobile && ($o = auth_phone_owner($pdo, $mobile, 'COMPANY', $id))) out(['ok' => false, 'error' => auth_phone_conflict_msg($o), 'field' => 'mobile']);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE company_portal_users SET full_name = ?, mobile_number = ?, company_id = ? WHERE id = ?")->execute([$fullName, $mobile, $companyIds[0], $id]);
        if ($password !== '') $pdo->prepare("UPDATE company_portal_users SET password_hash = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        $pdo->prepare("DELETE FROM company_portal_user_companies WHERE portal_user_id = ?")->execute([$id]);
        $ins = $pdo->prepare("INSERT INTO company_portal_user_companies (portal_user_id, company_id) VALUES (?, ?)");
        foreach ($companyIds as $cid) $ins->execute([$id, $cid]);
        $pdo->commit();
        if (array_key_exists('avatar', $data) && prof_ready($pdo)) prof_set_avatar($pdo, 'COMPANY', $id, (string)$data['avatar']);
        if ($phoneChanged && !empty($u['bale_chat_id'])) {
            auth_unlink_bot($pdo, 'COMPANY', $id, "⚠️ {$fullName} عزیز، شماره‌ی تماسِ حساب شما در پنل «بیمه با ما» تغییر کرد.\nاتصال این گفتگو قطع شد؛ لطفاً با شماره‌ی جدید دوباره وارد شوید.");
        }
        out(['ok' => true, 'bot_unlinked' => $phoneChanged && !empty($u['bale_chat_id'])]);
    }

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
        $rpw = (string)($data['report_edit_password'] ?? '');
        $rpwClear = !empty($data['report_edit_password_clear']);
        if ($rpw !== '' && strlen($rpw) < 4) out(['ok' => false, 'error' => 'رمزِ ویرایشِ گزارش باید حداقل ۴ کاراکتر باشد.']);
        if (($rpw !== '' || $rpwClear) && !staff_report_pw_ready($pdo)) out(['ok' => false, 'error' => STAFF_REPORT_PW_MSG]);
        if ($id === $me && $role !== 'ADMIN') out(['ok' => false, 'error' => 'نمی‌توانید نقشِ خودتان را از مدیر کل خارج کنید.']);

        $phoneChanged = auth_norm_phone($u['mobile_number']) !== (string)$mobile;
        if ($phoneChanged && $mobile && ($o = auth_phone_owner($pdo, $mobile, 'STAFF', $id))) out(['ok' => false, 'error' => auth_phone_conflict_msg($o), 'field' => 'mobile']);
        $pdo->prepare("UPDATE users SET full_name = ?, role = ?, mobile_number = ?, personnel_code = ? WHERE id = ?")
            ->execute([$fullName, $role, $mobile, trim(p2e_digits($data['personnel_code'] ?? '')) ?: null, $id]);
        if ($password !== '') $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        if (array_key_exists('chat_companies', $data)) { chat_access_ensure($pdo); try { $pdo->prepare("UPDATE users SET chat_companies = ? WHERE id = ?")->execute([chat_companies_value($data['chat_companies']), $id]); } catch (Throwable $e) {} }
        if ($rpw !== '' || $rpwClear) staff_set_report_pw($pdo, $id, $rpw, $rpwClear);
        if (array_key_exists('avatar', $data) && prof_ready($pdo)) prof_set_avatar($pdo, 'STAFF', $id, (string)$data['avatar']);
        // شماره عوض شد: از ربات بیرون می‌آید و باید با شماره‌ی جدید دوباره احراز هویت کند
        if ($phoneChanged && !empty($u['bale_chat_id'])) {
            auth_unlink_bot($pdo, 'STAFF', $id, "⚠️ {$fullName} عزیز، شماره‌ی تماسِ حساب شما در پنل «بیمه با ما» تغییر کرد.\nاتصال این گفتگو قطع شد؛ لطفاً با شماره‌ی جدید دوباره وارد شوید.");
        }
        out(['ok' => true, 'bot_unlinked' => $phoneChanged && !empty($u['bale_chat_id'])]);
    }

    // حذفِ نرم - با رمزِ مدیر
    if ($action === 'delete' && ($data['type'] ?? '') === 'COMPANY') {
        if (!$ready) out(['ok' => false, 'error' => AUTH_SCHEMA_MSG]);
        require_admin_password($pdo, $me, (string)($data['admin_password'] ?? ''));
        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT full_name FROM company_portal_users WHERE id = ? AND COALESCE(is_deleted, 0) = 0");
        $st->execute([$id]);
        $name = $st->fetchColumn();
        if ($name === false) out(['ok' => false, 'error' => 'کاربر یافت نشد.']);
        auth_unlink_bot($pdo, 'COMPANY', $id, 'حساب کاربری شما در پنل «بیمه با ما» غیرفعال شد و این گفتگو قطع شد.');
        $pdo->prepare("UPDATE company_portal_users SET is_active = 0, is_deleted = 1, deleted_at = NOW() WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE phone_otps SET is_used = 1 WHERE user_type = 'COMPANY' AND user_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE password_reset_requests SET status = 'REJECTED', note = 'کاربر حذف شد', handled_by = ?, handled_at = NOW() WHERE user_type = 'COMPANY' AND user_id = ? AND status = 'PENDING'")->execute([$me, $id]);
        try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'DELETE', 'company_portal_users', ?, ?)")->execute([$me, $id, $name]); } catch (Throwable $e) {}
        out(['ok' => true]);
    }

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

    // ---------------- پیام مستقیم ----------------
    // از طریق ربات بله‌ی شرکت‌ها (اگر کاربر با شماره‌اش وارد ربات شده باشد)؛ برای کاربران داخلی
    // در چتِ داخلیِ پنل هم ثبت می‌شود تا حتی بدونِ ربات هم به دستش برسد.
    if ($action === 'send_message') {
        $type = ($data['type'] ?? '') === 'COMPANY' ? 'COMPANY' : 'STAFF';
        $id = intval($data['id'] ?? 0);
        $text = trim((string)($data['message'] ?? ''));
        if ($text === '') out(['ok' => false, 'error' => 'متن پیام خالی است.']);
        if (mb_strlen($text) > 3000) out(['ok' => false, 'error' => 'پیام خیلی طولانی است (حداکثر ۳۰۰۰ نویسه).']);
        $acc = auth_load_account($pdo, $type, $id);
        if (!$acc) out(['ok' => false, 'error' => 'کاربر یافت نشد یا غیرفعال است.']);
        $u = $acc['user'];
        $st = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $st->execute([$me]);
        $myName = (string)$st->fetchColumn();
        $inPanel = false;
        if ($type === 'STAFF') {
            if (intval($id) === $me) out(['ok' => false, 'error' => 'نمی‌توانید به خودتان پیام بدهید.']);
            $pdo->prepare("INSERT INTO staff_chat_messages (from_user_id, to_user_id, message) VALUES (?, ?, ?)")->execute([$me, $id, $text]);
            $inPanel = true;
        }
        $linked = $ready && !empty($u['bale_chat_id']) && !empty($u['bot_linked_at']);
        $sent = $linked ? (bool)cbot_send($pdo, $u['bale_chat_id'], "✉️ پیام از {$myName} (مدیر «بیمه با ما»):\n\n{$text}") : false;
        if (!$sent && !$inPanel) {
            out(['ok' => false, 'error' => $linked ? 'ارسال در ربات بله ممکن نشد؛ کمی بعد دوباره امتحان کنید.'
                                                  : 'این کاربر هنوز با شماره‌اش وارد ربات بله‌ی شرکت‌ها نشده؛ پیام مستقیم فقط از طریق ربات به کاربر شرکتی می‌رسد.']);
        }
        out(['ok' => true, 'bot' => $sent, 'panel' => $inPanel]);
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
        auth_purge_old_resets($pdo);
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

// فقط شناسه‌ی شرکت‌هایی که واقعاً وجود دارند
function company_ids_valid($pdo, $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id FROM companies WHERE id IN ($in) ORDER BY FIELD(id, $in)");
    $st->execute(array_merge($ids, $ids));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function jalali_dt($ts) {
    if (!$ts) return null;
    $t = strtotime($ts);
    return jalali_from_gregorian_ts_dotted($t) . ' ' . date('H:i', $t);
}
