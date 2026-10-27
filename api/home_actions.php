<?php
// فایل: api/home_actions.php
// «میز کار من»: داشبوردِ شخصیِ هر کاربرِ پنل (همه‌ی نقش‌ها به‌جز کاربرانِ شرکت).
// فقط داده‌ی خودِ کاربر خوانده می‌شود: کارهای ثبت‌شده در «کارکرد من» (work_activity)، حضور، مرخصی،
// و شمارِ کارهای منتظر فقط در بخش‌هایی که کاربر اجازه‌ی دیدنش را دارد. چیزی نوشته نمی‌شود.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_work.php';
require_once __DIR__ . '/_name_honor.php';

function hout($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) hout(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
$uid = intval($_SESSION['user_id']);
$role = perm_real_role();
$action = (string)($_GET['action'] ?? 'summary');

// بخش‌هایی که کاربر می‌بیند: مدیر کل همه؛ دسترسیِ سفارشی همان؛ وگرنه پیش‌فرضِ نقش
$perms = $role === 'ADMIN' ? null : (perm_user_custom($pdo, $uid) ?? perm_role_defaults($role));
$can = function ($page) use ($perms) { return $perms === null || in_array('view', (array)($perms[$page] ?? []), true); };
$one = function ($sql, $args = []) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($args); return intval($st->fetchColumn()); } catch (Throwable $e) { return 0; } };

try {
    if ($action !== 'summary') hout(['ok' => false, 'error' => 'عملیات نامعتبر.']);
    wk_ensure($pdo);
    $u = $pdo->prepare("SELECT full_name, prof_title FROM users WHERE id = ?");
    try { $u->execute([$uid]); $u = $u->fetch() ?: []; } catch (Throwable $e) { $u = ['full_name' => $_SESSION['full_name'] ?? '']; }

    // ---- ۳۰ روزِ اخیر: تعدادِ کارها و ساعتِ حضور (همان محاسبه‌ی «کارکرد من») ----
    $days = max(7, min(90, intval($_GET['days'] ?? 30)));
    $to = wk_today(); $from = date('Y-m-d', strtotime("-" . ($days - 1) . " days", strtotime($to . ' 12:00:00')));
    $series = [];
    foreach (wk_days($pdo, $uid, $from, $to) as $g => $D) {
        $series[] = ['d' => $g, 'j' => $D['jdate'], 'acts' => intval($D['acts']), 'worked' => intval($D['worked']), 'off' => (bool)$D['off'], 'type' => $D['day_type'], 'in' => $D['check_in']];
    }
    $todayRow = end($series) ?: [];
    // روزهای پیاپیِ فعال (روزهای تعطیل زنجیره را نمی‌شکنند)
    $streak = 0;
    for ($i = count($series) - 1; $i >= 0; $i--) {
        $r = $series[$i];
        if ($r['acts'] > 0 || $r['worked'] > 0 || in_array($r['type'], ['REMOTE', 'MISSION'], true)) { $streak++; continue; }
        if ($r['off'] || $i === count($series) - 1) continue;   // تعطیل، یا امروز که هنوز شروع نشده
        break;
    }

    // ---- ماهِ جاری (شمسی) ----
    [$jy, $jm] = jalali_from_gregorian_ts(time());
    [$mFrom, $mTo] = wk_month_range($jy, $jm);
    $mDays = wk_days($pdo, $uid, $mFrom, min($mTo, $to));
    $mSum = wk_summary($pdo, $mDays);
    $bal = wk_balance($pdo, $uid);

    // ---- پرتکرارترین کارها و آخرین کارها ----
    $top = [];
    try {
        $st = $pdo->prepare("SELECT label, SUM(qty) n FROM work_activity WHERE user_id = ? AND wdate BETWEEN ? AND ? GROUP BY label ORDER BY n DESC LIMIT 6");
        $st->execute([$uid, $from, $to]);
        foreach ($st->fetchAll() as $r) $top[] = ['label' => $r['label'], 'n' => intval($r['n'])];
    } catch (Throwable $e) {}
    // بخش‌هایی که کاربر بیشتر در آن‌ها کار کرده (برای چیدنِ دکمه‌های سریع)
    $pages = [];
    try {
        $st = $pdo->prepare("SELECT page, SUM(qty) n FROM work_activity WHERE user_id = ? AND wdate >= ? AND page IS NOT NULL GROUP BY page ORDER BY n DESC LIMIT 8");
        $st->execute([$uid, date('Y-m-d', strtotime('-60 days'))]);
        foreach ($st->fetchAll() as $r) $pages[] = ['page' => $r['page'], 'n' => intval($r['n'])];
    } catch (Throwable $e) {}
    $recent = [];
    try {
        $st = $pdo->prepare("SELECT label, ref, at, qty FROM work_activity WHERE user_id = ? ORDER BY id DESC LIMIT 40");
        $st->execute([$uid]);
        $prev = null;
        foreach ($st->fetchAll() as $r) {
            $ts = strtotime($r['at']);
            // همان کار روی همان مورد در همان دقیقه یک سطر حساب می‌شود
            $sig = $r['label'] . '|' . $r['ref'] . '|' . date('Y-m-d H:i', $ts);
            if ($sig === $prev) continue;
            $prev = $sig;
            if (count($recent) >= 8) break;
            $recent[] = ['label' => $r['label'], 'ref' => $r['ref'], 'qty' => intval($r['qty']), 'date' => wk_g2j(date('Y-m-d', $ts)), 'time' => date('H:i', $ts), 'today' => date('Y-m-d', $ts) === $to];
        }
    } catch (Throwable $e) {}

    // ---- کارهای منتظر (فقط بخش‌های مجاز) ----
    $pending = [];
    $add = function ($tab, $icon, $label, $n, $tone) use (&$pending) { if ($n > 0) $pending[] = ['tab' => $tab, 'icon' => $icon, 'label' => $label, 'n' => $n, 'tone' => $tone]; };
    if ($can('health')) {
        $add('health', 'fa-file-circle-check', 'مدارکِ کارکنان منتظرِ تأیید', $one("SELECT COUNT(*) FROM policy_cases WHERE status = 'DOCS_REVIEW'"), 'amber');
        $add('health', 'fa-car-burst', 'بازدیدِ سلامتِ منتظرِ بررسی', $one("SELECT COUNT(*) FROM health_inspections WHERE status = 'PENDING'"), 'rose');
        $add('health', 'fa-file-arrow-up', 'بازدیدِ تأییدشده بدونِ گزارشِ کارشناس', $one("SELECT COUNT(*) FROM health_inspections WHERE status = 'PHOTOS_APPROVED'"), 'violet');
    }
    if ($can('issue-queue')) $add('issue-queue', 'fa-list-check', 'درخواستِ کارکنان در حالِ صدور', $one("SELECT COUNT(*) FROM policy_cases WHERE status = 'ISSUING'"), 'emerald');
    if ($can('companies-inbox')) $add('companies-inbox', 'fa-inbox', 'مدرکِ شرکتیِ تخصیص‌نیافته', $one("SELECT COUNT(*) FROM company_documents WHERE status = 'UNASSIGNED'"), 'indigo');
    if ($can('life-pay') || $can('life-dash')) {
        $add($can('life-pay') ? 'life-pay' : 'life-dash', 'fa-heart-pulse', 'قسطِ بیمه عمرِ سررسیدِ هفت روزِ آینده', $one("SELECT COUNT(*) FROM life_installments WHERE cleared = 0 AND paid_amount < amount AND due_g BETWEEN ? AND ?", [$to, date('Y-m-d', strtotime('+7 days'))]), 'pink');
    }
    if ($can('staff-work')) $add('staff-work', 'fa-user-clock', 'درخواستِ مرخصیِ همکاران', $one("SELECT COUNT(*) FROM work_leaves WHERE status = 'PENDING' AND user_id <> ?", [$uid]), 'sky');
    // «کارهای من» (کارهای پرسنل): کارهای باز و عقب‌افتاده‌ی خودم، و برای مدیرِ کارها عقب‌افتاده‌های پرسنل
    $myTasks = [];
    try {
        if ($pdo->query("SHOW TABLES LIKE 'st_tasks'")->fetch()) {
            $now = date('Y-m-d H:i:s');
            $add('my-tasks', 'fa-triangle-exclamation', 'کارِ عقب‌افتاده‌ی من (مهلت گذشته)', $one("SELECT COUNT(*) FROM st_tasks WHERE assignee_id = ? AND status IN ('OPEN', 'DOING') AND due_at IS NOT NULL AND due_at < ?", [$uid, $now]), 'rose');
            $add('my-tasks', 'fa-list-check', 'کارهای من (باز و در حالِ انجام)', $one("SELECT COUNT(*) FROM st_tasks WHERE assignee_id = ? AND status IN ('OPEN', 'DOING')", [$uid]), 'indigo');
            if ($can('staff-tasks')) $add('staff-tasks', 'fa-people-group', 'کارهای عقب‌افتاده‌ی پرسنل', $one("SELECT COUNT(*) FROM st_tasks WHERE status IN ('OPEN', 'DOING') AND due_at IS NOT NULL AND due_at < ? AND assignee_id <> ?", [$now, $uid]), 'amber');
            $st = $pdo->prepare("SELECT id, title, status, priority, due_at FROM st_tasks WHERE assignee_id = ? AND status IN ('OPEN', 'DOING') ORDER BY (due_at IS NULL), due_at, priority DESC, id LIMIT 4");
            $st->execute([$uid]);
            foreach ($st->fetchAll() as $t) {
                $ts = $t['due_at'] ? strtotime($t['due_at']) : 0;
                $myTasks[] = ['id' => intval($t['id']), 'title' => $t['title'], 'doing' => $t['status'] === 'DOING', 'priority' => intval($t['priority']),
                              'due' => $ts ? wk_g2j(date('Y-m-d', $ts)) . (date('H:i', $ts) !== '00:00' ? ' ' . date('H:i', $ts) : '') : '', 'late' => $ts && $ts < time()];
            }
        }
    } catch (Throwable $e) {}
    $add('my-work', 'fa-hourglass-half', 'مرخصیِ من در انتظارِ تأیید', $one("SELECT COUNT(*) FROM work_leaves WHERE status = 'PENDING' AND user_id = ?", [$uid]), 'slate');
    if ($can('tickets')) {
        try {
            require_once __DIR__ . '/_company_helpers.php';
            require_once __DIR__ . '/_auth_helpers.php';
            require_once __DIR__ . '/_chat_core.php';
            $n = chat_ready($pdo) ? chat_unread_total($pdo, ['kind' => 'STAFF', 'id' => $uid, 'role' => $role, 'name' => $u['full_name'] ?? '']) : 0;
            $add('tickets', 'fa-comments', 'پیامِ خوانده‌نشده', $n, 'blue');
        } catch (Throwable $e) {}
    }

    hout(['ok' => true,
        'me' => ['name' => honor_name($pdo, 'S', $uid, (string)($u['full_name'] ?? '')), 'first' => trim(explode(' ', trim((string)($u['full_name'] ?? '')))[0] ?? ''), 'title' => (string)($u['prof_title'] ?? '')],
        'today' => ['j' => wk_g2j($to), 'acts' => intval($todayRow['acts'] ?? 0), 'worked' => intval($todayRow['worked'] ?? 0), 'in' => $todayRow['in'] ?? null, 'hour' => intval(date('G'))],
        'month' => ['title' => jalali_month_name($jm) . ' ' . $jy, 'acts' => intval($mSum['acts']), 'worked' => intval($mSum['worked']), 'present' => intval($mSum['present']), 'work_days' => intval($mSum['work_days'])],
        'leave' => ['balance' => intval($bal['balance']), 'balance_fa' => $bal['balance_fa']],
        'allowed' => $perms === null ? null : array_values(array_keys(array_filter($perms, function ($ops) { return in_array('view', (array)$ops, true); }))),
        'streak' => $streak, 'series' => $series, 'top' => $top, 'pages' => $pages, 'recent' => $recent, 'pending' => $pending, 'my_tasks' => $myTasks]);
} catch (Throwable $e) {
    error_log('[home_actions] ' . $e->getMessage());
    hout(['ok' => false, 'error' => 'خطای سرور.']);
}
