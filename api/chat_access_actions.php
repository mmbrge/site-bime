<?php
// فایل: api/chat_access_actions.php
// «دسترسیِ گفتگو» (فقط مدیر کل): هر همکار با کدام همکاران و کدام شرکت‌ها می‌تواند گفتگو کند.
//  - همکاران: users.chat_staff (با همه / فقط این‌ها / همه به‌جز این‌ها) - گفتگو فقط وقتی باز است که هر دو طرف اجازه بدهند
//  - شرکت‌ها: users.chat_companies (طبقِ نقش / همه / هیچ / فقط این‌ها) + نقش‌هایی که پیش‌فرض با شرکت‌ها گفتگو می‌کنند
// ستون‌ها خودکار ساخته می‌شوند (api/_chat_access.php).
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_chat_access.php';
require_once __DIR__ . '/_chat_core.php';

function cao($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) cao(['ok' => false, 'error' => 'ابتدا وارد شوید.']);
if (($_SESSION['role'] ?? '') !== 'ADMIN') cao(['ok' => false, 'error' => 'تعیینِ دسترسیِ گفتگو فقط با مدیر کل است.']);
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($data['action'] ?? ($_GET['action'] ?? ''));

try {
    chat_access_ensure($pdo);
    if ($action === 'get') {
        $del = '';
        try { $pdo->query("SELECT is_deleted FROM users LIMIT 0"); $del = " WHERE COALESCE(is_deleted, 0) = 0"; } catch (Throwable $e) {}
        $pc = function_exists('prof_cols') ? ', ' . prof_cols($pdo, 'users') : '';
        $users = [];
        foreach ($pdo->query("SELECT id, full_name, username, role, chat_staff, chat_companies$pc FROM users$del ORDER BY role = 'ADMIN' DESC, full_name")->fetchAll() as $u) {
            $users[] = ['id' => intval($u['id']), 'name' => $u['full_name'], 'username' => $u['username'], 'role' => $u['role'], 'role_fa' => chat_role_fa($u['role']),
                        'avatar' => $u['avatar'] ?? null, 'staff' => chat_staff_rule_decode($u['chat_staff'] ?? null), 'companies' => chat_companies_decode($u['chat_companies'] ?? null)];
        }
        $companies = [];
        try { $companies = $pdo->query("SELECT id, name FROM companies WHERE COALESCE(is_import, 0) = 0 ORDER BY name")->fetchAll(); }
        catch (Throwable $e) { $companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(); }
        // جفت‌هایی که بسته‌اند (برای نمایشِ خلاصه)
        $blocked = [];
        foreach ($users as $u) $blocked[$u['id']] = chat_staff_blocked_ids($pdo, $u['id']);
        cao(['ok' => true, 'users' => $users, 'companies' => array_map(function ($c) { return ['id' => intval($c['id']), 'name' => $c['name']]; }, $companies),
             'company_roles' => chat_company_roles($pdo), 'company_role_options' => CHAT_COMPANY_ROLE_OPTIONS, 'blocked' => (object)$blocked]);
    }
    if ($action === 'save') {
        $uid = intval($data['user_id'] ?? 0);
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $st->execute([$uid]);
        $role = $st->fetchColumn();
        if ($role === false) cao(['ok' => false, 'error' => 'کاربر پیدا نشد.']);
        if ($role === 'ADMIN') cao(['ok' => false, 'error' => 'مدیر کل همیشه با همه گفتگو می‌کند.']);
        $s = (array)($data['staff'] ?? []);
        $ids = array_values(array_filter(array_map('intval', (array)($s['ids'] ?? [])), function ($x) use ($uid) { return $x && $x !== $uid; }));
        $pdo->prepare("UPDATE users SET chat_staff = ? WHERE id = ?")->execute([chat_staff_rule_value((string)($s['mode'] ?? 'ALL'), $ids), $uid]);
        if (isset($data['companies'])) {
            $c = (array)$data['companies'];
            $mode = (string)($c['mode'] ?? 'ROLE');
            $val = $mode === 'LIST' ? chat_companies_value((array)($c['ids'] ?? [])) : chat_companies_value($mode);
            $pdo->prepare("UPDATE users SET chat_companies = ? WHERE id = ?")->execute([$val, $uid]);
        }
        chat_staff_rules($pdo, true);
        cao(['ok' => true, 'blocked' => chat_staff_blocked_ids($pdo, $uid)]);
    }
    if ($action === 'save_company_roles') {
        chat_set_company_roles($pdo, (array)($data['roles'] ?? []));
        cao(['ok' => true]);
    }
    cao(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[chat_access_actions] ' . $e->getMessage());
    cao(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
