<?php
// فایل: api/staff_tasks_actions.php
// «کارهای پرسنل»: مدیر کل (یا کسی که دسترسیِ «کارهای پرسنل» دارد) برای کارمندان کار تعریف می‌کند — شرح، زمانِ شروع، مهلت،
// یادآوری و اولویت؛ هر کاربر در «کارهای من» کارهای خودش را می‌بیند، وضعیت (در حالِ انجام / انجام شد) و توضیح ثبت می‌کند.
// همه‌ی تغییرها در تاریخچه‌ی هر کار (st_task_log) می‌ماند؛ کارِ تازه در زنگوله‌ی اعلان‌های همان کاربر.
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';

function sto($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) sto(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
$me = intval($_SESSION['user_id']);
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
$custom = perm_real_role() === 'ADMIN' ? null : perm_user_custom($pdo, $me);
$isManager = perm_real_role() === 'ADMIN' || ($custom && array_intersect(['view', 'create', 'edit'], $custom['staff-tasks'] ?? []));
$canEdit = perm_real_role() === 'ADMIN' || ($custom && array_intersect(['create', 'edit'], $custom['staff-tasks'] ?? []));
if (function_exists('sess_release')) sess_release();

const ST_STATUS = ['OPEN' => 'در انتظارِ انجام', 'DOING' => 'در حالِ انجام', 'DONE' => 'انجام شد', 'CANCELLED' => 'لغو شد'];
const ST_PRIO = [1 => 'کم', 2 => 'معمولی', 3 => 'مهم', 4 => 'فوری'];

function st_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec("CREATE TABLE IF NOT EXISTS st_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(250) NOT NULL, detail TEXT NULL, assignee_id INT NOT NULL, created_by INT NULL,
        start_at DATETIME NULL, due_at DATETIME NULL, remind_at DATETIME NULL, reminded TINYINT(1) NOT NULL DEFAULT 0, priority TINYINT NOT NULL DEFAULT 2,
        status VARCHAR(10) NOT NULL DEFAULT 'OPEN', done_at DATETIME NULL, done_note TEXT NULL, seen_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
        KEY idx_assignee (assignee_id, status), KEY idx_due (due_at))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS st_task_log (
        id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NULL, act VARCHAR(20) NOT NULL, note TEXT NULL, at DATETIME NOT NULL, KEY idx_task (task_id))$T");
}
st_ensure($pdo);

function st_jdt($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    [$y, $m, $d] = jalali_from_gregorian_ts($ts);
    return sprintf('%04d/%02d/%02d', $y, $m, $d) . (date('H:i', $ts) !== '00:00' ? ' ' . date('H:i', $ts) : '');
}
// «1405/08/12» + «14:30» => DATETIME
function st_parse_dt($date, $time = '') {
    $date = trim(p2e_digits((string)$date));
    if ($date === '' || !preg_match('/^(1[34]\d{2})\D(\d{1,2})\D(\d{1,2})$/', $date, $m)) return null;
    $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]));
    $t = preg_match('/^(\d{1,2}):(\d{2})$/', trim(p2e_digits((string)$time)), $tm) ? sprintf('%02d:%02d:00', min(23, $tm[1]), min(59, $tm[2])) : '00:00:00';
    return date('Y-m-d', $ts) . ' ' . $t;
}
function st_out(array $r) {
    $now = time();
    $over = $r['due_at'] && !in_array($r['status'], ['DONE', 'CANCELLED'], true) && strtotime($r['due_at']) < $now;
    return ['id' => intval($r['id']), 'title' => $r['title'], 'detail' => $r['detail'], 'assignee_id' => intval($r['assignee_id']), 'assignee' => $r['assignee_name'] ?? '',
            'creator' => $r['creator_name'] ?? '', 'status' => $r['status'], 'status_fa' => ST_STATUS[$r['status']] ?? $r['status'], 'priority' => intval($r['priority']),
            'priority_fa' => ST_PRIO[intval($r['priority'])] ?? '', 'start_j' => st_jdt($r['start_at']), 'due_j' => st_jdt($r['due_at']), 'remind_j' => st_jdt($r['remind_at']),
            'due_at' => $r['due_at'], 'remind_at' => $r['remind_at'], 'reminded' => (bool)$r['reminded'], 'done_j' => st_jdt($r['done_at']), 'done_note' => $r['done_note'],
            'overdue' => $over, 'seen' => !empty($r['seen_at']), 'created_j' => st_jdt($r['created_at'])];
}
function st_log($pdo, $taskId, $uid, $act, $note = null) {
    $pdo->prepare("INSERT INTO st_task_log (task_id, user_id, act, note, at) VALUES (?, ?, ?, ?, NOW())")->execute([$taskId, $uid, $act, $note !== null ? mb_substr((string)$note, 0, 2000) : null]);
}
function st_notify($pdo, $uid, $title, $body) {
    if (!function_exists('notif_tables_ready') || !notif_tables_ready($pdo)) return;
    try { $pdo->prepare("INSERT INTO user_notifications (scope, user_id, type, title, body, tab, created_at) VALUES ('STAFF', ?, 'task', ?, ?, 'my-tasks', NOW())")->execute([$uid, mb_substr($title, 0, 200), mb_substr($body, 0, 1000)]); }
    catch (Throwable $e) { error_log('[st_notify] ' . $e->getMessage()); }
}
$SEL = "SELECT t.*, ua.full_name AS assignee_name, uc.full_name AS creator_name FROM st_tasks t LEFT JOIN users ua ON ua.id = t.assignee_id LEFT JOIN users uc ON uc.id = t.created_by";

// ---------------- کارهای من ----------------
if ($action === 'my_list') {
    $st = $pdo->prepare("$SEL WHERE t.assignee_id = ? AND (t.status IN ('OPEN', 'DOING') OR t.updated_at >= NOW() - INTERVAL 30 DAY OR t.created_at >= NOW() - INTERVAL 30 DAY)
                          ORDER BY FIELD(t.status, 'DOING', 'OPEN', 'DONE', 'CANCELLED'), t.due_at IS NULL, t.due_at, t.priority DESC, t.id DESC LIMIT 300");
    $st->execute([$me]);
    $items = array_map('st_out', $st->fetchAll());
    $pdo->prepare("UPDATE st_tasks SET seen_at = NOW() WHERE assignee_id = ? AND seen_at IS NULL")->execute([$me]);
    sto(['ok' => true, 'items' => $items, 'is_manager' => (bool)$isManager]);
}
// شمارشِ باز/دیرشده + یادآوری‌های سررسیده (برای نشانِ منو و پیامِ یادآوری؛ هر یادآوری یک بار)
if ($action === 'my_ping') {
    $st = $pdo->prepare("SELECT SUM(status IN ('OPEN','DOING')) open_n, SUM(status IN ('OPEN','DOING') AND due_at < NOW()) over_n, SUM(seen_at IS NULL AND status IN ('OPEN','DOING')) new_n FROM st_tasks WHERE assignee_id = ?");
    $st->execute([$me]);
    $c = $st->fetch() ?: [];
    $st = $pdo->prepare("$SEL WHERE t.assignee_id = ? AND t.status IN ('OPEN','DOING') AND t.reminded = 0 AND t.remind_at IS NOT NULL AND t.remind_at <= NOW() LIMIT 5");
    $st->execute([$me]);
    $due = array_map('st_out', $st->fetchAll());
    if ($due) $pdo->exec("UPDATE st_tasks SET reminded = 1 WHERE id IN (" . implode(',', array_map(function ($x) { return intval($x['id']); }, $due)) . ")");
    sto(['ok' => true, 'open' => intval($c['open_n'] ?? 0), 'overdue' => intval($c['over_n'] ?? 0), 'new' => intval($c['new_n'] ?? 0), 'remind' => $due]);
}
if ($action === 'my_status') {
    $id = intval($data['id'] ?? 0);
    $stt = (string)($data['status'] ?? '');
    if (!in_array($stt, ['OPEN', 'DOING', 'DONE'], true)) sto(['ok' => false, 'error' => 'وضعیت نامعتبر است.']);
    $st = $pdo->prepare("SELECT * FROM st_tasks WHERE id = ?"); $st->execute([$id]); $t = $st->fetch();
    if (!$t || (intval($t['assignee_id']) !== $me && !$canEdit)) sto(['ok' => false, 'error' => 'کار پیدا نشد.']);
    if ($t['status'] === 'CANCELLED') sto(['ok' => false, 'error' => 'این کار لغو شده است.']);
    $note = trim((string)($data['note'] ?? ''));
    $pdo->prepare("UPDATE st_tasks SET status = ?, done_at = " . ($stt === 'DONE' ? 'NOW()' : 'NULL') . ", done_note = COALESCE(NULLIF(?, ''), done_note), updated_at = NOW() WHERE id = ?")->execute([$stt, $note, $id]);
    st_log($pdo, $id, $me, $stt, $note ?: null);
    if ($stt === 'DONE' && intval($t['created_by']) && intval($t['created_by']) !== $me) st_notify($pdo, intval($t['created_by']), '✅ کار انجام شد: ' . $t['title'], ($_SESSION['full_name'] ?? '') . ($note ? ' — ' . $note : ''));
    sto(['ok' => true]);
}
if ($action === 'note') {
    $id = intval($data['id'] ?? 0); $note = trim((string)($data['note'] ?? ''));
    $st = $pdo->prepare("SELECT * FROM st_tasks WHERE id = ?"); $st->execute([$id]); $t = $st->fetch();
    if (!$t || (intval($t['assignee_id']) !== $me && !$isManager) || $note === '') sto(['ok' => false, 'error' => 'یادداشت ثبت نشد.']);
    st_log($pdo, $id, $me, 'NOTE', $note);
    $to = intval($t['assignee_id']) === $me ? intval($t['created_by']) : intval($t['assignee_id']);
    if ($to && $to !== $me) st_notify($pdo, $to, '💬 یادداشت روی کار: ' . $t['title'], $note);
    sto(['ok' => true]);
}
if ($action === 'log') {
    $id = intval($data['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM st_tasks WHERE id = ?"); $st->execute([$id]); $t = $st->fetch();
    if (!$t || (intval($t['assignee_id']) !== $me && !$isManager)) sto(['ok' => false, 'error' => 'کار پیدا نشد.']);
    $st = $pdo->prepare("SELECT l.*, u.full_name FROM st_task_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.task_id = ? ORDER BY l.id");
    $st->execute([$id]);
    $fa = ['CREATE' => 'تعریف شد', 'EDIT' => 'ویرایش شد', 'OPEN' => 'در انتظارِ انجام', 'DOING' => 'شروع شد', 'DONE' => 'انجام شد', 'CANCELLED' => 'لغو شد', 'NOTE' => 'یادداشت'];
    sto(['ok' => true, 'log' => array_map(function ($l) use ($fa) { return ['act' => $fa[$l['act']] ?? $l['act'], 'note' => $l['note'], 'by' => $l['full_name'] ?? '', 'at' => st_jdt($l['at'])]; }, $st->fetchAll())]);
}

// ---------------- مدیریتِ کارهای پرسنل ----------------
if (!$isManager) sto(['ok' => false, 'error' => 'مدیریتِ کارهای پرسنل فقط برای مدیر کل (یا دسترسیِ «کارهای پرسنل») است.']);
if ($action === 'admin_list') {
    $w = ['1=1']; $a = [];
    if (intval($data['user_id'] ?? 0)) { $w[] = 't.assignee_id = ?'; $a[] = intval($data['user_id']); }
    $f = (string)($data['filter'] ?? 'open');
    if ($f === 'open') $w[] = "t.status IN ('OPEN','DOING')";
    elseif ($f === 'overdue') $w[] = "t.status IN ('OPEN','DOING') AND t.due_at < NOW()";
    elseif ($f === 'done') $w[] = "t.status = 'DONE'";
    $q = trim((string)($data['q'] ?? ''));
    if ($q !== '') { $w[] = '(t.title LIKE ? OR t.detail LIKE ?)'; $a[] = "%$q%"; $a[] = "%$q%"; }
    $st = $pdo->prepare("$SEL WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(t.status, 'DOING', 'OPEN', 'DONE', 'CANCELLED'), t.due_at IS NULL, t.due_at, t.id DESC LIMIT 500");
    $st->execute($a);
    $items = array_map('st_out', $st->fetchAll());
    // خلاصه‌ی هر کاربر
    $users = [];
    foreach ($pdo->query("SELECT u.id, u.full_name, u.role,
                                 (SELECT COUNT(*) FROM st_tasks t WHERE t.assignee_id = u.id AND t.status IN ('OPEN','DOING')) open_n,
                                 (SELECT COUNT(*) FROM st_tasks t WHERE t.assignee_id = u.id AND t.status IN ('OPEN','DOING') AND t.due_at < NOW()) over_n,
                                 (SELECT COUNT(*) FROM st_tasks t WHERE t.assignee_id = u.id AND t.status = 'DONE' AND t.done_at >= NOW() - INTERVAL 30 DAY) done_n,
                                 (SELECT MAX(at) FROM work_activity w WHERE w.user_id = u.id) last_act
                            FROM users u WHERE COALESCE(u.is_deleted, 0) = 0 ORDER BY u.full_name") as $u) {
        $users[] = ['id' => intval($u['id']), 'name' => $u['full_name'], 'role' => $u['role'], 'open' => intval($u['open_n']), 'overdue' => intval($u['over_n']), 'done30' => intval($u['done_n']),
                    'last_act' => $u['last_act'] ? st_jdt($u['last_act']) : ''];
    }
    sto(['ok' => true, 'items' => $items, 'users' => $users, 'can_edit' => (bool)$canEdit]);
}
if (!$canEdit) sto(['ok' => false, 'error' => 'اجازه‌ی تعریف/ویرایشِ کار ندارید.']);
if ($action === 'admin_save') {
    $id = intval($data['id'] ?? 0);
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') sto(['ok' => false, 'error' => 'عنوانِ کار را بنویسید.']);
    $detail = trim((string)($data['detail'] ?? '')) ?: null;
    $start = st_parse_dt($data['start_date'] ?? '', $data['start_time'] ?? '');
    $due = st_parse_dt($data['due_date'] ?? '', $data['due_time'] ?? '');
    $rem = st_parse_dt($data['remind_date'] ?? '', $data['remind_time'] ?? '');
    if (!$rem && $due && !empty($data['remind_before'])) $rem = date('Y-m-d H:i:s', strtotime($due) - 60 * max(0, intval($data['remind_before'])));
    $prio = max(1, min(4, intval($data['priority'] ?? 2)));
    if ($id) {
        $pdo->prepare("UPDATE st_tasks SET title = ?, detail = ?, start_at = ?, due_at = ?, remind_at = ?, reminded = IF(remind_at <=> ?, reminded, 0), priority = ?, updated_at = NOW() WHERE id = ?")
            ->execute([mb_substr($title, 0, 250), $detail, $start, $due, $rem, $rem, $prio, $id]);
        if (!empty($data['assignee_id'])) $pdo->prepare("UPDATE st_tasks SET assignee_id = ? WHERE id = ?")->execute([intval($data['assignee_id']), $id]);
        st_log($pdo, $id, $me, 'EDIT');
        sto(['ok' => true, 'ids' => [$id]]);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['assignees'] ?? [])))));
    if (!$ids) sto(['ok' => false, 'error' => 'دست‌کم یک نفر را انتخاب کنید.']);
    $ins = $pdo->prepare("INSERT INTO st_tasks (title, detail, assignee_id, created_by, start_at, due_at, remind_at, priority, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'OPEN', NOW(), NOW())");
    $out = [];
    foreach ($ids as $u) {
        $ins->execute([mb_substr($title, 0, 250), $detail, $u, $me, $start, $due, $rem, $prio]);
        $tid = intval($pdo->lastInsertId()); $out[] = $tid;
        st_log($pdo, $tid, $me, 'CREATE');
        st_notify($pdo, $u, '📌 کارِ تازه: ' . $title, trim(($due ? 'مهلت: ' . st_jdt($due) . ' — ' : '') . ($detail ? mb_substr($detail, 0, 300) : '')));
    }
    sto(['ok' => true, 'ids' => $out]);
}
if ($action === 'admin_cancel' || $action === 'admin_delete') {
    $id = intval($data['id'] ?? 0);
    if ($action === 'admin_delete') { $pdo->prepare("DELETE FROM st_tasks WHERE id = ?")->execute([$id]); $pdo->prepare("DELETE FROM st_task_log WHERE task_id = ?")->execute([$id]); }
    else { $pdo->prepare("UPDATE st_tasks SET status = 'CANCELLED', updated_at = NOW() WHERE id = ?")->execute([$id]); st_log($pdo, $id, $me, 'CANCELLED'); }
    sto(['ok' => true]);
}
sto(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
