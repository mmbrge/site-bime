<?php
// فایل: api/work_actions.php
// «کارکرد من» (هر کاربر برای خودش) و «کارکرد پرسنل» (مدیر کل یا کسی که دسترسیِ «کارکرد پرسنل» دارد، برای همه)
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require_once __DIR__ . '/_work.php';
require_once __DIR__ . '/_auth_helpers.php';

function wout($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id'])) wout(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
$me = intval($_SESSION['user_id']);
wk_ensure($pdo);

$raw = json_decode(file_get_contents('php://input'), true) ?: [];
$data = $raw + $_POST + $_GET;
$action = (string)($data['action'] ?? '');

// مدیر: مدیر کل، یا کاربرِ سفارشی که «کارکرد پرسنل» را دارد
$customPerms = perm_real_role() === 'ADMIN' ? null : perm_user_custom($pdo, $me);
$isManager = perm_real_role() === 'ADMIN' || ($customPerms && in_array('view', $customPerms['staff-work'] ?? [], true));
$canManage = perm_real_role() === 'ADMIN' || ($customPerms && in_array('edit', $customPerms['staff-work'] ?? [], true));

// کاربری که داده‌اش خوانده/نوشته می‌شود: خودش، یا (برای مدیر) هر کاربری
$uid = intval($data['user_id'] ?? 0) ?: $me;
if ($uid !== $me && !$isManager && strpos($action, 'svc_') !== 0) wout(['ok' => false, 'error' => 'فقط کارکردِ خودتان را می‌بینید.']);
$writeOther = $uid !== $me;
if ($writeOther && in_array($action, ['save_day', 'leave_add', 'leave_delete', 'profile_save'], true) && !$canManage) wout(['ok' => false, 'error' => 'اجازه‌ی تغییرِ کارکردِ دیگران را ندارید.']);

function wk_user($pdo, $id) {
    $st = $pdo->prepare("SELECT id, full_name, role, username FROM users WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function wk_month_args($data) {
    [$cy, $cm] = jalali_from_gregorian_ts(time());
    $jy = intval(p2e_digits((string)($data['jy'] ?? ''))) ?: $cy;
    $jm = intval(p2e_digits((string)($data['jm'] ?? ''))) ?: $cm;
    return [max(1300, min(1500, $jy)), max(1, min(12, $jm))];
}
function wk_range_args($data) {
    $from = wk_j2g($data['from'] ?? '') ?: null;
    $to = wk_j2g($data['to'] ?? '') ?: null;
    if (!$from || !$to) {
        [$jy, $jm] = wk_month_args($data);
        [$from, $to] = wk_month_range($jy, $jm);
    }
    if ($from > $to) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 400) $to = date('Y-m-d', strtotime($from . ' +400 days'));
    return [$from, $to];
}
function wk_leave_row($pdo, $L) {
    return ['id' => intval($L['id']), 'kind' => $L['kind'], 'from' => wk_g2j($L['date_from']), 'to' => wk_g2j($L['date_to']),
            'time_from' => $L['time_from'] ? substr($L['time_from'], 0, 5) : null, 'time_to' => $L['time_to'] ? substr($L['time_to'], 0, 5) : null,
            'minutes' => intval($L['minutes']), 'minutes_fa' => wk_leave_fa($pdo, intval($L['minutes'])), 'reason' => (string)$L['reason'], 'status' => $L['status'],
            'decide_note' => (string)$L['decide_note'], 'created_at' => $L['created_at']];
}

try {
    if ($action === 'settings_get') {
        wout(['ok' => true, 'settings' => wk_settings($pdo), 'is_manager' => $isManager, 'can_manage' => $canManage, 'types' => WK_TYPES, 'moods' => WK_MOODS]);
    }
    if ($action === 'settings_save') {
        if (!$canManage) wout(['ok' => false, 'error' => 'تنظیماتِ کارکرد و مرخصی فقط برای مدیر کل است.']);
        wout(['ok' => true, 'settings' => wk_settings_save($pdo, (array)($data['settings'] ?? []))]);
    }

    // ---- یک ماه: روزها، خلاصه، مرخصی‌ها و مانده ----
    if ($action === 'month') {
        [$jy, $jm] = wk_month_args($data);
        [$from, $to] = wk_month_range($jy, $jm);
        $days = wk_days($pdo, $uid, $from, $to);
        // سرویسِ رفت‌وآمدِ هر روز (برای نشانِ تقویم)
        require_once __DIR__ . '/_work_service.php';
        $svcRows = ws_user_days($pdo, $uid, $from, $to);
        foreach ($days as $g => &$D) { $D['svc_go'] = isset($svcRows[$g]) ? intval($svcRows[$g]['go_service_id']) : 0; $D['svc_back'] = isset($svcRows[$g]) ? intval($svcRows[$g]['back_service_id']) : 0; }
        unset($D);
        $st = $pdo->prepare("SELECT * FROM work_leaves WHERE user_id = ? AND date_to >= ? AND date_from <= ? ORDER BY date_from DESC, id DESC");
        $st->execute([$uid, $from, $to]);
        $leaves = array_map(function ($L) use ($pdo) { return wk_leave_row($pdo, $L); }, $st->fetchAll());
        $u = wk_user($pdo, $uid);
        wout(['ok' => true, 'jy' => $jy, 'jm' => $jm, 'month_name' => jalali_month_name($jm), 'days' => array_values($days), 'summary' => wk_summary($pdo, $days),
              'balance' => wk_balance($pdo, $uid, $jy, $jm), 'balance_now' => wk_balance($pdo, $uid), 'leaves' => $leaves, 'settings' => wk_settings($pdo),
              'user' => $u ? ['id' => intval($u['id']), 'name' => $u['full_name'], 'role' => $u['role']] : null, 'is_me' => $uid === $me,
              'is_manager' => $isManager, 'can_manage' => $canManage, 'today' => wk_g2j(wk_today())]);
    }

    // ---- یک روز: داده‌ی ثبت‌شده + خطِ زمانیِ کارها ----
    if ($action === 'day') {
        $g = wk_j2g($data['date'] ?? '') ?: wk_today();
        $d = wk_days($pdo, $uid, $g, $g)[$g];
        // سرویسِ رفت‌وآمدِ همین روز + سرویس‌هایی که این نفر می‌تواند انتخاب کند
        require_once __DIR__ . '/_work_service.php';
        $sr = ws_user_days($pdo, $uid, $g, $g)[$g] ?? null;
        $allowed = ws_allowed($pdo, $uid);
        $svcList = [];
        foreach ($allowed as $s) $svcList[$s['id']] = ['id' => $s['id'], 'name' => $s['name'], 'price_go' => $s['price_go'], 'price_back' => $s['price_back']];
        foreach (['go_service_id', 'back_service_id'] as $c) if ($sr && $sr[$c] && !isset($svcList[intval($sr[$c])]) && ($x = ws_service($pdo, $sr[$c]))) $svcList[$x['id']] = ['id' => $x['id'], 'name' => $x['name'], 'price_go' => $x['price_go'], 'price_back' => $x['price_back']];
        wout(['ok' => true, 'day' => $d, 'timeline' => wk_timeline($pdo, $uid, $g),
              'svc' => ['go' => $sr ? intval($sr['go_service_id']) : 0, 'back' => $sr ? intval($sr['back_service_id']) : 0,
                        'amount_go' => $sr ? intval($sr['amount_go']) : 0, 'amount_back' => $sr ? intval($sr['amount_back']) : 0],
              'svc_services' => array_values($svcList), 'svc_default' => ws_default_for($pdo, $uid, $allowed)]);
    }

    if ($action === 'save_day') {
        $g = wk_j2g($data['date'] ?? '');
        if (!$g) wout(['ok' => false, 'error' => 'تاریخ نامعتبر است.']);
        if ($g > wk_today() && !in_array((string)($data['day_type'] ?? ''), ['LEAVE', 'OFF', 'MISSION', 'REMOTE'], true) && !$canManage)
            wout(['ok' => false, 'error' => 'برای روزهای آینده فقط نوعِ روز (مرخصی، مأموریت، دورکاری) قابلِ ثبت است.']);
        $in = wk_time($data['check_in'] ?? ''); $outT = wk_time($data['check_out'] ?? '');
        if ($in && $outT && $outT <= $in) wout(['ok' => false, 'error' => 'ساعتِ خروج باید بعد از ساعتِ ورود باشد.']);
        $type = (string)($data['day_type'] ?? '');
        if (!isset(WK_TYPES[$type])) $type = 'WORK';
        $mood = intval($data['mood'] ?? 0);
        $tasks = [];
        foreach ((array)($data['tasks'] ?? []) as $t) {
            $title = trim(mb_substr((string)($t['title'] ?? ''), 0, 300));
            if ($title === '') continue;
            $tasks[] = ['from' => wk_time($t['from'] ?? ''), 'to' => wk_time($t['to'] ?? ''), 'title' => $title];
            if (count($tasks) >= 60) break;
        }
        // سرویسِ رفت‌وآمدِ همین روز (اگر فرستاده شده)؛ اول این ذخیره می‌شود تا اگر سرویس مجاز نبود چیزی نیمه‌کاره نماند
        if (isset($data['svc']) && is_array($data['svc'])) {
            require_once __DIR__ . '/_work_service.php';
            try { ws_days_save($pdo, $uid, [['date' => $g, 'go' => intval($data['svc']['go'] ?? 0), 'back' => intval($data['svc']['back'] ?? 0)]], $me); }
            catch (RuntimeException $e) { wout(['ok' => false, 'error' => $e->getMessage()]); }
        }
        $pdo->prepare("INSERT INTO work_days (user_id, wdate, check_in, check_out, day_type, mood, note, tasks, updated_at, updated_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                       ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), check_out = VALUES(check_out), day_type = VALUES(day_type), mood = VALUES(mood),
                                               note = VALUES(note), tasks = VALUES(tasks), updated_at = NOW(), updated_by = VALUES(updated_by)")
            ->execute([$uid, $g, $in ?: null, $outT ?: null, $type, ($mood >= 1 && $mood <= 5) ? $mood : null, trim(mb_substr((string)($data['note'] ?? ''), 0, 4000)),
                       $tasks ? json_encode($tasks, JSON_UNESCAPED_UNICODE) : null, $me]);
        wout(['ok' => true, 'day' => wk_days($pdo, $uid, $g, $g)[$g]]);
    }

    // ---- مرخصی ----
    if ($action === 'leave_add') {
        $kind = ($data['kind'] ?? '') === 'HOUR' ? 'HOUR' : 'DAY';
        $from = wk_j2g($data['date_from'] ?? '');
        $to = $kind === 'HOUR' ? $from : (wk_j2g($data['date_to'] ?? '') ?: $from);
        if (!$from) wout(['ok' => false, 'error' => 'تاریخِ مرخصی را درست وارد کنید (مثل ۱۴۰۵/۰۷/۱۲).']);
        if ($to < $from) wout(['ok' => false, 'error' => 'تاریخِ پایان قبل از شروع است.']);
        $tf = $kind === 'HOUR' ? wk_time($data['time_from'] ?? '') : null;
        $tt = $kind === 'HOUR' ? wk_time($data['time_to'] ?? '') : null;
        if ($kind === 'HOUR' && (!$tf || !$tt || $tt <= $tf)) wout(['ok' => false, 'error' => 'ساعتِ شروع و پایانِ مرخصیِ ساعتی را درست وارد کنید.']);
        $min = wk_leave_minutes($pdo, $kind, $from, $to, $tf, $tt);
        if ($min <= 0) wout(['ok' => false, 'error' => 'این بازه روزِ کاری ندارد (همه تعطیل‌اند).']);
        // هم‌پوشانی با مرخصیِ دیگر
        $st = $pdo->prepare("SELECT kind, date_from, date_to, time_from, time_to FROM work_leaves WHERE user_id = ? AND status <> 'REJECTED' AND date_to >= ? AND date_from <= ?");
        $st->execute([$uid, $from, $to]);
        foreach ($st->fetchAll() as $o) {
            if ($o['kind'] === 'DAY' || $kind === 'DAY') wout(['ok' => false, 'error' => 'در این تاریخ مرخصیِ دیگری ثبت شده است.']);
            if ($tf < substr($o['time_to'], 0, 5) && $tt > substr($o['time_from'], 0, 5)) wout(['ok' => false, 'error' => 'با مرخصیِ ساعتیِ دیگری در همین روز هم‌پوشانی دارد.']);
        }
        $status = (wk_settings($pdo)['work_leave_approval'] === '1' && !$writeOther && perm_real_role() !== 'ADMIN') ? 'PENDING' : 'APPROVED';
        $pdo->prepare("INSERT INTO work_leaves (user_id, kind, date_from, date_to, time_from, time_to, minutes, reason, status, created_at, created_by, decided_by, decided_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)")
            ->execute([$uid, $kind, $from, $to, $tf, $tt, $min, trim(mb_substr((string)($data['reason'] ?? ''), 0, 500)), $status, $me,
                       $status === 'APPROVED' ? $me : null, $status === 'APPROVED' ? date('Y-m-d H:i:s') : null]);
        if ($status === 'PENDING') {
            $u = wk_user($pdo, $uid);
            cbot_notify_staff($pdo, ['ADMIN'], "🗓 درخواستِ مرخصی از {$u['full_name']}: " . ($kind === 'HOUR' ? wk_g2j($from) . " ساعت $tf تا $tt" : wk_g2j($from) . ($to !== $from ? ' تا ' . wk_g2j($to) : '')) . "\nبرای تأیید به «اطلاعات پرسنلی ← کارکرد پرسنل» بروید.");
        }
        wout(['ok' => true, 'status' => $status, 'minutes' => $min, 'minutes_fa' => wk_leave_fa($pdo, $min), 'balance' => wk_balance($pdo, $uid)]);
    }
    if ($action === 'leave_delete') {
        $st = $pdo->prepare("SELECT * FROM work_leaves WHERE id = ? AND user_id = ?");
        $st->execute([intval($data['id'] ?? 0), $uid]);
        $L = $st->fetch();
        if (!$L) wout(['ok' => false, 'error' => 'مرخصی پیدا نشد.']);
        if (!$canManage && $L['status'] === 'APPROVED' && wk_settings($pdo)['work_leave_approval'] === '1')
            wout(['ok' => false, 'error' => 'مرخصیِ تأییدشده را فقط مدیر می‌تواند حذف کند.']);
        $pdo->prepare("DELETE FROM work_leaves WHERE id = ?")->execute([$L['id']]);
        wout(['ok' => true, 'balance' => wk_balance($pdo, $uid)]);
    }
    if ($action === 'leave_decide') {
        if (!$canManage) wout(['ok' => false, 'error' => 'فقط مدیر می‌تواند مرخصی را تأیید یا رد کند.']);
        $status = ($data['status'] ?? '') === 'REJECTED' ? 'REJECTED' : 'APPROVED';
        $pdo->prepare("UPDATE work_leaves SET status = ?, decided_by = ?, decided_at = NOW(), decide_note = ? WHERE id = ?")
            ->execute([$status, $me, trim(mb_substr((string)($data['note'] ?? ''), 0, 300)), intval($data['id'] ?? 0)]);
        wout(['ok' => true]);
    }
    if ($action === 'pending_leaves') {
        if (!$isManager) wout(['ok' => false, 'error' => 'دسترسی ندارید.']);
        $rows = $pdo->query("SELECT l.*, u.full_name FROM work_leaves l JOIN users u ON u.id = l.user_id WHERE l.status = 'PENDING' ORDER BY l.date_from")->fetchAll();
        wout(['ok' => true, 'rows' => array_map(function ($L) use ($pdo) { return wk_leave_row($pdo, $L) + ['user_id' => intval($L['user_id']), 'name' => $L['full_name']]; }, $rows)]);
    }

    // ---- مانده‌ی مرخصیِ اولِ کار (کاربر یک بار وارد می‌کند؛ محاسبه از اولِ همین ماه ادامه پیدا می‌کند) ----
    if ($action === 'profile_save') {
        $s = wk_settings($pdo);
        $daysIn = floatval(p2e_digits((string)($data['days'] ?? '0')));
        $hoursIn = (string)($data['hours'] ?? '0');
        $hm = wk_time($hoursIn);
        $hourMin = $hm ? wk_minutes_between('00:00', $hm) : intval(round(floatval(p2e_digits($hoursIn)) * 60));
        $min = intval(round($daysIn * floatval($s['work_leave_day_hours']) * 60)) + $hourMin;
        if (!empty($data['negative'])) $min = -$min;
        [$cy, $cm] = jalali_from_gregorian_ts(time());
        $jy = intval($data['jy'] ?? 0) ?: $cy; $jm = intval($data['jm'] ?? 0) ?: $cm;
        $pdo->prepare("INSERT INTO work_profiles (user_id, opening_minutes, opening_jy, opening_jm, set_at, set_by) VALUES (?, ?, ?, ?, NOW(), ?)
                       ON DUPLICATE KEY UPDATE opening_minutes = VALUES(opening_minutes), opening_jy = VALUES(opening_jy), opening_jm = VALUES(opening_jm), set_at = NOW(), set_by = VALUES(set_by)")
            ->execute([$uid, $min, $jy, $jm, $me]);
        wout(['ok' => true, 'balance' => wk_balance($pdo, $uid)]);
    }

    // ---- گزارشِ هر بازه (روز به روز) ----
    if ($action === 'report' || $action === 'export') {
        [$from, $to] = wk_range_args($data);
        $all = $isManager && !empty($data['all']);
        $s = wk_settings($pdo);
        $std = intval(round(floatval($s['work_day_hours']) * 60));
        $dowFa = [0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه'];
        if ($all) {
            $users = $pdo->query("SELECT id, full_name, role FROM users" . (auth_schema_ready($pdo) ? " WHERE COALESCE(is_deleted, 0) = 0" : '') . " ORDER BY full_name")->fetchAll();
            $rows = [];
            foreach ($users as $u) {
                $days = wk_days($pdo, intval($u['id']), $from, $to);
                $sm = wk_summary($pdo, $days);
                $b = wk_balance($pdo, intval($u['id']));
                $rows[] = ['user_id' => intval($u['id']), 'name' => $u['full_name'], 'role' => $u['role'], 'present' => $sm['present'], 'work_days' => $sm['work_days'],
                           'worked' => $sm['worked'], 'worked_fa' => wk_dur_fa($sm['worked']), 'over' => $sm['over'], 'over_fa' => wk_dur_fa($sm['over']),
                           'leave_min' => $sm['leave_min'], 'leave_fa' => wk_leave_fa($pdo, $sm['leave_min']), 'mood_avg' => $sm['mood_avg'], 'acts' => $sm['acts'],
                           'remote' => $sm['remote'], 'mission' => $sm['mission'], 'balance_fa' => $b['balance_fa'], 'balance' => $b['balance']];
            }
            if ($action === 'report') wout(['ok' => true, 'all' => true, 'from' => wk_g2j($from), 'to' => wk_g2j($to), 'rows' => $rows]);
            require_once __DIR__ . '/_xlsx_writer.php';
            $x = array_map(function ($r) { return [$r['name'], $r['present'], $r['work_days'], $r['worked_fa'], $r['over_fa'], $r['leave_fa'], $r['remote'], $r['mission'],
                                                   $r['mood_avg'] !== null ? $r['mood_avg'] : '', $r['acts'], $r['balance_fa']]; }, $rows);
            xlsx_send(xlsx_build(['نام', 'روزهای حضور', 'روزهای کاری', 'ساعت کارکرد', 'اضافه/کسر کار', 'مرخصی', 'دورکاری', 'مأموریت', 'میانگین حال', 'کارهای ثبت‌شده در پنل', 'مانده مرخصی'],
                                 $x, 'کارکرد پرسنل', [1, 2, 6, 7, 9]), 'کارکرد پرسنل ' . str_replace('/', '-', wk_g2j($from)) . ' تا ' . str_replace('/', '-', wk_g2j($to)) . '.xlsx');
            exit;
        }
        $days = wk_days($pdo, $uid, $from, $to);
        $sm = wk_summary($pdo, $days);
        $u = wk_user($pdo, $uid);
        if ($action === 'report') {
            wout(['ok' => true, 'from' => wk_g2j($from), 'to' => wk_g2j($to), 'days' => array_values($days), 'summary' => $sm + ['worked_fa' => wk_dur_fa($sm['worked']), 'over_fa' => wk_dur_fa($sm['over']), 'leave_fa' => wk_leave_fa($pdo, $sm['leave_min'])],
                  'balance' => wk_balance($pdo, $uid), 'user' => ['id' => $uid, 'name' => $u['full_name'] ?? '']]);
        }
        require_once __DIR__ . '/_xlsx_writer.php';
        $x = [];
        foreach ($days as $D) {
            $tasks = implode(' | ', array_map(function ($t) { return trim(($t['from'] ? $t['from'] . ($t['to'] ? '-' . $t['to'] : '') . ' ' : '') . $t['title']); }, $D['tasks']));
            $lv = implode('، ', array_map(function ($L) use ($pdo) { return ($L['kind'] === 'HOUR' ? 'ساعتی ' . $L['from'] . '-' . $L['to'] : 'روزانه') . ($L['status'] === 'PENDING' ? ' (در انتظار)' : ''); }, $D['leaves']));
            $x[] = [$D['jdate'], $dowFa[$D['dow']], WK_TYPES[$D['day_type']] ?? ($D['off'] ? 'تعطیل' : ''), $D['check_in'] ?: '', $D['check_out'] ?: '', $D['worked'] ? wk_dur_fa($D['worked']) : '',
                    ($D['worked'] && !$D['off']) ? wk_dur_fa($D['worked'] + $D['leave_min'] - $std) : ($D['worked'] ? wk_dur_fa($D['worked']) : ''),
                    $lv, $D['mood'] ? WK_MOODS[$D['mood']] : '', $D['acts'] ?: '', $tasks, $D['note']];
        }
        $x[] = ['جمع', '', 'حضور: ' . $sm['present'] . ' روز', '', '', wk_dur_fa($sm['worked']), wk_dur_fa($sm['over']), wk_leave_fa($pdo, $sm['leave_min']),
                $sm['mood_avg'] !== null ? $sm['mood_avg'] : '', $sm['acts'], '', 'مانده‌ی مرخصی: ' . wk_balance($pdo, $uid)['balance_fa']];
        xlsx_send(xlsx_build(['تاریخ', 'روز', 'نوع', 'ورود', 'خروج', 'مدت کارکرد', 'اضافه/کسر کار', 'مرخصی', 'حال', 'کارها در پنل', 'شرح کارها', 'توضیحات'], $x, 'کارکرد', [9]),
                  'کارکرد ' . ($u['full_name'] ?? '') . ' ' . str_replace('/', '-', wk_g2j($from)) . ' تا ' . str_replace('/', '-', wk_g2j($to)) . '.xlsx');
        exit;
    }

    // ---- نمای کلیِ همه‌ی پرسنل در یک ماه (برای مدیر) ----
    if ($action === 'staff_overview') {
        if (!$isManager) wout(['ok' => false, 'error' => 'دسترسی ندارید.']);
        [$jy, $jm] = wk_month_args($data);
        [$from, $to] = wk_month_range($jy, $jm);
        $users = $pdo->query("SELECT id, full_name, role FROM users" . (auth_schema_ready($pdo) ? " WHERE COALESCE(is_deleted, 0) = 0" : '') . " ORDER BY full_name")->fetchAll();
        $today = wk_today();
        $rows = [];
        foreach ($users as $u) {
            $id = intval($u['id']);
            $days = wk_days($pdo, $id, $from, $to);
            $sm = wk_summary($pdo, $days);
            $b = wk_balance($pdo, $id, $jy, $jm);
            $t = wk_days($pdo, $id, $today, $today)[$today];
            $rows[] = ['id' => $id, 'name' => $u['full_name'], 'role' => $u['role'], 'present' => $sm['present'], 'work_days' => $sm['work_days'], 'worked' => $sm['worked'],
                       'worked_fa' => wk_dur_fa($sm['worked']), 'over_fa' => wk_dur_fa($sm['over']), 'over' => $sm['over'], 'leave_fa' => wk_leave_fa($pdo, $sm['leave_min']),
                       'mood_avg' => $sm['mood_avg'], 'acts' => $sm['acts'], 'balance_fa' => $b['balance_fa'], 'balance' => $b['balance'], 'pending' => $b['pending'],
                       'today_in' => $t['check_in'], 'today_out' => $t['live'] ? null : $t['check_out'], 'today_live' => $t['live'], 'today_type' => $t['day_type']];
        }
        $pending = intval($pdo->query("SELECT COUNT(*) FROM work_leaves WHERE status = 'PENDING'")->fetchColumn());
        wout(['ok' => true, 'jy' => $jy, 'jm' => $jm, 'month_name' => jalali_month_name($jm), 'rows' => $rows, 'pending' => $pending]);
    }

    // ---- سرویسِ رفت‌وآمد: هر نفر برای خودش (کارکرد من)؛ «گزارش سرویس‌ها» برای دیدن/ویرایشِ همه؛ تعریفِ سرویس فقط مدیر کل ----
    if (strpos($action, 'svc_') === 0) {
        require_once __DIR__ . '/_work_service.php';
        ws_ensure($pdo);
        $isAdmin = perm_real_role() === 'ADMIN';
        $svcView = $isAdmin || ($customPerms && in_array('view', $customPerms['service-report'] ?? [], true));
        $svcEdit = $isAdmin || ($customPerms && in_array('edit', $customPerms['service-report'] ?? [], true));
        $writes = ['svc_days_save', 'svc_pay_save', 'svc_pay_delete'];
        if ($uid !== $me) {
            if (!$svcView) wout(['ok' => false, 'error' => 'فقط سرویسِ خودتان را می‌بینید.']);
            if (in_array($action, $writes, true) && !$svcEdit) wout(['ok' => false, 'error' => 'اجازه‌ی تغییرِ سرویسِ دیگران را ندارید.']);
        }
        if (in_array($action, ['svc_save', 'svc_delete'], true) && !$isAdmin) wout(['ok' => false, 'error' => 'تعریف و اختصاصِ سرویس فقط کارِ مدیر کل است.']);
        if (in_array($action, ['svc_list', 'svc_overview'], true) && !$svcView) wout(['ok' => false, 'error' => 'به «گزارش سرویس‌ها» دسترسی ندارید.']);
        $money = function ($v) { return max(0, intval(money_to_int(p2e_digits((string)$v)))); };
        $staff = function () use ($pdo) {
            return $pdo->query("SELECT id, full_name, role FROM users" . (auth_schema_ready($pdo) ? " WHERE COALESCE(is_deleted, 0) = 0" : '') . " ORDER BY full_name")->fetchAll();
        };
        $monthOut = function ($uid, $jy, $jm) use ($pdo, $me, $svcEdit, $isAdmin) {
            $u = wk_user($pdo, $uid);
            return ['ok' => true, 'can_edit' => $uid === $me || $svcEdit, 'is_admin' => $isAdmin, 'is_me' => $uid === $me,
                    'user' => ['id' => $uid, 'name' => $u['full_name'] ?? '']] + ws_month($pdo, $uid, $jy, $jm);
        };

        if ($action === 'svc_month') {
            [$jy, $jm] = wk_month_args($data);
            wout($monthOut($uid, $jy, $jm));
        }
        if ($action === 'svc_list') {
            wout(['ok' => true, 'services' => ws_services($pdo), 'is_admin' => $isAdmin, 'can_edit' => $svcEdit,
                  'users' => array_map(function ($u) { return ['id' => intval($u['id']), 'name' => $u['full_name'], 'role' => $u['role']]; }, $staff())]);
        }
        if ($action === 'svc_overview') {
            [$jy, $jm] = wk_month_args($data);
            $rows = ws_overview($pdo, $jy, $jm, $staff());
            wout(['ok' => true, 'jy' => $jy, 'jm' => $jm, 'month_name' => jalali_month_name($jm), 'rows' => $rows, 'can_edit' => $svcEdit, 'is_admin' => $isAdmin]);
        }
        if ($action === 'svc_save') {
            $s = (array)($data['service'] ?? []);
            $name = trim(mb_substr((string)($s['name'] ?? ''), 0, 120));
            if ($name === '') wout(['ok' => false, 'error' => 'نامِ سرویس را وارد کنید.']);
            $users = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($s['users'] ?? []))))));
            $vals = [$name, trim(mb_substr((string)($s['driver'] ?? ''), 0, 120)) ?: null, trim(p2e_digits(mb_substr((string)($s['phone'] ?? ''), 0, 30))) ?: null,
                     trim(mb_substr((string)($s['car'] ?? ''), 0, 160)) ?: null, $money($s['price_go'] ?? 0), $money($s['price_back'] ?? ($s['price_go'] ?? 0)),
                     !empty($s['is_default']) ? 1 : 0, !isset($s['is_active']) || !empty($s['is_active']) ? 1 : 0, $users !== '' ? $users : null,
                     trim(mb_substr((string)($s['note'] ?? ''), 0, 500)) ?: null];
            $id = intval($s['id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE work_services SET name=?, driver=?, phone=?, car=?, price_go=?, price_back=?, is_default=?, is_active=?, assigned_users=?, note=? WHERE id=?")->execute([...$vals, $id]);
            } else {
                // اولین سرویس خودبه‌خود پیش‌فرض می‌شود
                if (!intval($pdo->query("SELECT COUNT(*) FROM work_services WHERE is_active = 1")->fetchColumn())) $vals[6] = 1;
                $pdo->prepare("INSERT INTO work_services (name, driver, phone, car, price_go, price_back, is_default, is_active, assigned_users, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute($vals);
                $id = intval($pdo->lastInsertId());
            }
            if ($vals[6]) $pdo->prepare("UPDATE work_services SET is_default = 0 WHERE id <> ?")->execute([$id]);
            wout(['ok' => true, 'id' => $id, 'services' => ws_services($pdo)]);
        }
        if ($action === 'svc_delete') {
            $id = intval($data['id'] ?? 0);
            $st = $pdo->prepare("SELECT COUNT(*) FROM work_service_days WHERE go_service_id = ? OR back_service_id = ?");
            $st->execute([$id, $id]);
            if (intval($st->fetchColumn())) {
                // سابقه دارد: فقط غیرفعال می‌شود تا رسیدهای ماه‌های قبل درست بمانند
                $pdo->prepare("UPDATE work_services SET is_active = 0, is_default = 0 WHERE id = ?")->execute([$id]);
                wout(['ok' => true, 'deactivated' => true, 'services' => ws_services($pdo)]);
            }
            $pdo->prepare("DELETE FROM work_services WHERE id = ?")->execute([$id]);
            wout(['ok' => true, 'services' => ws_services($pdo)]);
        }
        if ($action === 'svc_days_save') {
            try { $n = ws_days_save($pdo, $uid, (array)($data['days'] ?? []), $me, !empty($data['reprice']) && $svcEdit); }
            catch (RuntimeException $e) { wout(['ok' => false, 'error' => $e->getMessage()]); }
            [$jy, $jm] = wk_month_args($data);
            wout(['saved' => $n] + $monthOut($uid, $jy, $jm));
        }
        if ($action === 'svc_pay_save') {
            [$jy, $jm] = wk_month_args($data);
            $sid = intval($data['service_id'] ?? 0);
            if (!ws_service($pdo, $sid)) wout(['ok' => false, 'error' => 'سرویس پیدا نشد.']);
            $pd = wk_j2g($data['paid_date'] ?? '');
            if (!$pd) wout(['ok' => false, 'error' => 'تاریخِ پرداخت را درست وارد کنید (مثل ۱۴۰۵/۰۸/۰۱).']);
            $pdo->prepare("INSERT INTO work_service_pays (user_id, service_id, jy, jm, amount, paid_date, method, ref, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                           ON DUPLICATE KEY UPDATE amount = VALUES(amount), paid_date = VALUES(paid_date), method = VALUES(method), ref = VALUES(ref), note = VALUES(note)")
                ->execute([$uid, $sid, $jy, $jm, $money($data['amount'] ?? 0), $pd, trim(mb_substr((string)($data['method'] ?? ''), 0, 60)) ?: null,
                           trim(p2e_digits(mb_substr((string)($data['ref'] ?? ''), 0, 120))) ?: null, trim(mb_substr((string)($data['note'] ?? ''), 0, 500)) ?: null, $me]);
            wout($monthOut($uid, $jy, $jm));
        }
        if ($action === 'svc_pay_delete') {
            [$jy, $jm] = wk_month_args($data);
            $pdo->prepare("DELETE FROM work_service_pays WHERE user_id = ? AND service_id = ? AND jy = ? AND jm = ?")->execute([$uid, intval($data['service_id'] ?? 0), $jy, $jm]);
            wout($monthOut($uid, $jy, $jm));
        }
        if ($action === 'svc_receipt') {
            [$jy, $jm] = wk_month_args($data);
            $sid = intval($data['service_id'] ?? 0);
            $svc = ws_service($pdo, $sid);
            if (!$svc) wout(['ok' => false, 'error' => 'سرویس پیدا نشد.']);
            $u = wk_user($pdo, $uid);
            $bin = ws_receipt_pdf($pdo, $uid, $sid, $jy, $jm);
            $fname = 'رسید سرویس ' . $svc['name'] . ' - ' . ($u['full_name'] ?? '') . ' - ' . jalali_month_name($jm) . ' ' . $jy . '.pdf';
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/pdf');
            header("Content-Disposition: " . (!empty($data['download']) ? 'attachment' : 'inline') . "; filename=\"service-receipt-$jy-$jm.pdf\"; filename*=UTF-8''" . rawurlencode($fname));
            header('Content-Length: ' . strlen($bin));
            echo $bin;
            exit;
        }
    }

    wout(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[work_actions] ' . $e->getMessage());
    wout(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
