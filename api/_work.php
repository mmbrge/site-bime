<?php
// فایل: api/_work.php
// «کارکرد من» و «کارکرد پرسنل»: روزهای کاری، ساعتِ ورود و خروج (خودکار از ورود/خروجِ پنل و حضور، قابلِ ویرایش)،
// حال‌وهوای روز، شرحِ کار، کارهایی که در پنل انجام شده، مرخصیِ روزانه و ساعتی و مانده‌ی مرخصی.
// همه‌ی جدول‌ها خودکار ساخته می‌شوند (نیازی به مایگریشن نیست).

require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_work_track.php';   // ساختِ جدول‌ها و ثبتِ خودکارِ حضور/کارها

// ---------------- تنظیمات ----------------
const WK_DEFAULTS = ['work_leave_days_month' => '2.5', 'work_leave_day_hours' => '8', 'work_day_hours' => '8', 'work_offdays' => '5',
                     'work_leave_approval' => '0', 'work_holidays' => '', 'work_start' => '08:00', 'work_end' => '16:00'];
function wk_settings($pdo) {
    static $s = null;
    if ($s !== null) return $s;
    $s = WK_DEFAULTS;
    try {
        $st = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'work\\_%'");
        foreach ($st->fetchAll() as $r) if (array_key_exists($r['setting_key'], $s)) $s[$r['setting_key']] = (string)$r['setting_value'];
    } catch (Throwable $e) {}
    return $s;
}
function wk_settings_save($pdo, array $in) {
    $s = wk_settings($pdo);
    $clean = [
        'work_leave_days_month' => (string)max(0, min(31, round(floatval(p2e_digits((string)($in['work_leave_days_month'] ?? $s['work_leave_days_month']))), 2))),
        'work_leave_day_hours' => (string)max(1, min(24, round(floatval(p2e_digits((string)($in['work_leave_day_hours'] ?? $s['work_leave_day_hours']))), 2))),
        'work_day_hours' => (string)max(1, min(24, round(floatval(p2e_digits((string)($in['work_day_hours'] ?? $s['work_day_hours']))), 2))),
        'work_offdays' => implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($in['work_offdays'] ?? explode(',', $s['work_offdays']))), function ($d) { return $d >= 0 && $d <= 6; })))),
        'work_leave_approval' => !empty($in['work_leave_approval']) ? '1' : '0',
        'work_holidays' => implode("\n", wk_parse_holidays((string)($in['work_holidays'] ?? $s['work_holidays']))),
        'work_start' => wk_time((string)($in['work_start'] ?? $s['work_start'])) ?: '08:00',
        'work_end' => wk_time((string)($in['work_end'] ?? $s['work_end'])) ?: '16:00',
    ];
    $st = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($clean as $k => $v) $st->execute([$k, $v]);
    return $clean;
}
function wk_parse_holidays($txt) {
    $out = [];
    foreach (preg_split('/[\s,،]+/u', p2e_digits($txt)) as $t) {
        if (preg_match('/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $t, $m)) $out[] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

// ---------------- تاریخ و ساعت ----------------
function wk_time($t) {
    $t = trim(p2e_digits((string)$t));
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) return '';
    $h = intval($m[1]); $i = intval($m[2]);
    if ($h > 23 || $i > 59) return '';
    return sprintf('%02d:%02d', $h, $i);
}
// «1405/07/12» → «2026-10-04»
function wk_j2g($j) {
    if (!preg_match('/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', trim(p2e_digits((string)$j)), $m)) return null;
    $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]));
    return $ts ? date('Y-m-d', $ts) : null;
}
function wk_g2j($g) {
    $ts = is_numeric($g) ? intval($g) : strtotime((string)$g . ' 12:00:00');
    if (!$ts) return '';
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($ts);
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}
function wk_month_len($jy, $jm) {
    if ($jm <= 6) return 31;
    if ($jm <= 11) return 30;
    $g = wk_j2g(sprintf('%04d/12/30', $jy));
    return ($g && wk_g2j($g) === sprintf('%04d/12/30', $jy)) ? 30 : 29;
}
function wk_month_range($jy, $jm) {
    return [wk_j2g(sprintf('%04d/%02d/01', $jy, $jm)), wk_j2g(sprintf('%04d/%02d/%02d', $jy, $jm, wk_month_len($jy, $jm)))];
}
function wk_today() { return date('Y-m-d'); }
function wk_minutes_between($a, $b) {   // «08:10», «16:40» → دقیقه
    if (!$a || !$b) return 0;
    [$h1, $m1] = array_map('intval', explode(':', $a)); [$h2, $m2] = array_map('intval', explode(':', $b));
    $d = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
    return $d > 0 ? $d : 0;
}
function wk_dur_fa($min) {
    $min = intval($min);
    $neg = $min < 0; $min = abs($min);
    return ($neg ? '-' : '') . sprintf('%d:%02d', intdiv($min, 60), $min % 60);
}
// روزِ تعطیل: جمعه (یا هر روزی که در تنظیمات آمده) یا تعطیلِ رسمیِ ثبت‌شده
function wk_is_off($pdo, $gdate) {
    $s = wk_settings($pdo);
    $dow = intval(date('w', strtotime($gdate . ' 12:00:00')));   // 0=یکشنبه ... 5=جمعه ، 6=شنبه
    $off = array_map('intval', array_filter(explode(',', $s['work_offdays']), 'strlen'));
    if (in_array($dow, $off, true)) return true;
    return in_array(wk_g2j($gdate), wk_parse_holidays($s['work_holidays']), true);
}
// مرخصیِ روزانه: فقط روزهای کاری شمرده می‌شود
function wk_leave_minutes($pdo, $kind, $from, $to, $tFrom = null, $tTo = null) {
    $s = wk_settings($pdo);
    if ($kind === 'HOUR') return wk_minutes_between($tFrom, $tTo);
    $n = 0;
    for ($d = strtotime($from . ' 12:00:00'), $e = strtotime($to . ' 12:00:00'); $d <= $e && $n < 400; $d += 86400) {
        if (!wk_is_off($pdo, date('Y-m-d', $d))) $n++;
    }
    return intval(round($n * floatval($s['work_leave_day_hours']) * 60));
}
// «۲ روز و ۳:۳۰ ساعت» (هر روزِ مرخصی = ساعتِ تنظیمات، پیش‌فرض ۸)
function wk_leave_fa($pdo, $min) {
    $dayMin = max(60, intval(round(floatval(wk_settings($pdo)['work_leave_day_hours']) * 60)));
    $neg = $min < 0; $min = abs(intval($min));
    $d = intdiv($min, $dayMin); $r = $min % $dayMin;
    $parts = [];
    if ($d) $parts[] = $d . ' روز';
    if ($r) $parts[] = wk_dur_fa($r) . ' ساعت';
    return strtr(($neg ? 'منفی ' : '') . ($parts ? implode(' و ', $parts) : '0'), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

// ---------------- خواندنِ روزها ----------------
// برای یک کاربر و یک بازه: هر روز ← ساعتِ ورود/خروجِ خودکار و دستی، مدت، حال‌وهوا، مرخصی‌ها، تعدادِ کارها
function wk_days($pdo, $uid, $from, $to) {
    wk_ensure($pdo);
    $s = wk_settings($pdo);
    $days = [];
    for ($d = strtotime($from . ' 12:00:00'), $e = strtotime($to . ' 12:00:00'); $d <= $e; $d += 86400) {
        $g = date('Y-m-d', $d);
        $days[$g] = ['date' => $g, 'jdate' => wk_g2j($g), 'dow' => intval(date('w', $d)), 'off' => wk_is_off($pdo, $g), 'auto_in' => null, 'auto_out' => null,
                     'check_in' => null, 'check_out' => null, 'edited_in' => false, 'edited_out' => false, 'day_type' => null, 'mood' => null, 'note' => '',
                     'tasks' => [], 'acts' => 0, 'leaves' => [], 'leave_min' => 0, 'worked' => 0];
    }
    $q = function ($sql, $args) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); } catch (Throwable $e) { return []; } };
    foreach ($q("SELECT DATE(created_at) d, MIN(TIME(created_at)) t FROM login_logs WHERE user_type = 'STAFF' AND user_id = ? AND success = 1 AND method <> 'LOGOUT' AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)", [$uid, "$from 00:00:00", "$to 23:59:59"]) as $r)
        if (isset($days[$r['d']])) $days[$r['d']]['auto_in'] = substr($r['t'], 0, 5);
    foreach ($q("SELECT DATE(created_at) d, MAX(TIME(created_at)) t FROM login_logs WHERE user_type = 'STAFF' AND user_id = ? AND success = 1 AND method = 'LOGOUT' AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)", [$uid, "$from 00:00:00", "$to 23:59:59"]) as $r)
        if (isset($days[$r['d']])) $days[$r['d']]['auto_out'] = substr($r['t'], 0, 5);
    foreach ($q("SELECT wdate, TIME(first_seen) f, TIME(last_seen) l FROM work_presence WHERE user_id = ? AND wdate BETWEEN ? AND ?", [$uid, $from, $to]) as $r) {
        if (!isset($days[$r['wdate']])) continue;
        $D = &$days[$r['wdate']];
        $f = substr($r['f'], 0, 5); $l = substr($r['l'], 0, 5);
        if (!$D['auto_in'] || $f < $D['auto_in']) $D['auto_in'] = $f;
        if (!$D['auto_out'] || $l > $D['auto_out']) $D['auto_out'] = $l;
        unset($D);
    }
    foreach ($q("SELECT wdate, SUM(qty) n, MAX(TIME(at)) l FROM work_activity WHERE user_id = ? AND wdate BETWEEN ? AND ? GROUP BY wdate", [$uid, $from, $to]) as $r) {
        if (!isset($days[$r['wdate']])) continue;
        $days[$r['wdate']]['acts'] = intval($r['n']);
        $l = substr($r['l'], 0, 5);
        if (!$days[$r['wdate']]['auto_out'] || $l > $days[$r['wdate']]['auto_out']) $days[$r['wdate']]['auto_out'] = $l;
    }
    foreach ($q("SELECT * FROM work_days WHERE user_id = ? AND wdate BETWEEN ? AND ?", [$uid, $from, $to]) as $r) {
        if (!isset($days[$r['wdate']])) continue;
        $D = &$days[$r['wdate']];
        if ($r['check_in']) { $D['check_in'] = substr($r['check_in'], 0, 5); $D['edited_in'] = true; }
        if ($r['check_out']) { $D['check_out'] = substr($r['check_out'], 0, 5); $D['edited_out'] = true; }
        $D['day_type'] = $r['day_type']; $D['mood'] = $r['mood'] !== null ? intval($r['mood']) : null; $D['note'] = (string)$r['note'];
        $D['tasks'] = json_decode((string)$r['tasks'], true) ?: [];
        unset($D);
    }
    foreach ($q("SELECT * FROM work_leaves WHERE user_id = ? AND status <> 'REJECTED' AND date_to >= ? AND date_from <= ? ORDER BY date_from", [$uid, $from, $to]) as $L) {
        for ($d = strtotime(max($from, $L['date_from']) . ' 12:00:00'), $e = strtotime(min($to, $L['date_to']) . ' 12:00:00'); $d <= $e; $d += 86400) {
            $g = date('Y-m-d', $d);
            if (!isset($days[$g])) continue;
            $m = $L['kind'] === 'HOUR' ? intval($L['minutes']) : ($days[$g]['off'] ? 0 : intval(round(floatval($s['work_leave_day_hours']) * 60)));
            $days[$g]['leaves'][] = ['id' => intval($L['id']), 'kind' => $L['kind'], 'status' => $L['status'], 'minutes' => $m,
                                     'from' => $L['time_from'] ? substr($L['time_from'], 0, 5) : null, 'to' => $L['time_to'] ? substr($L['time_to'], 0, 5) : null];
            $days[$g]['leave_min'] += $m;
        }
    }
    $today = wk_today(); $now = date('H:i');
    foreach ($days as $g => &$D) {
        if (!$D['check_in']) $D['check_in'] = $D['auto_in'];
        if (!$D['check_out']) $D['check_out'] = $D['auto_out'];
        if ($D['check_in'] && $D['check_out'] === $D['check_in'] && !$D['edited_out']) $D['check_out'] = $g === $today ? null : $D['check_out'];
        $out = $D['check_out'] ?: ($g === $today && $D['check_in'] ? $now : null);
        $D['worked'] = wk_minutes_between($D['check_in'], $out);
        $D['live'] = $g === $today && $D['check_in'] && !$D['edited_out'];
        if (!$D['day_type']) {
            $fullLeave = false;
            foreach ($D['leaves'] as $L) if ($L['kind'] === 'DAY') $fullLeave = true;
            $D['day_type'] = $fullLeave ? 'LEAVE' : ($D['check_in'] ? 'WORK' : ($D['off'] ? 'OFF' : ''));
        }
    }
    unset($D);
    return $days;
}

// خلاصه‌ی یک بازه (برای کارت‌ها، گزارش و خروجی)
function wk_summary($pdo, array $days) {
    $s = wk_settings($pdo);
    $std = intval(round(floatval($s['work_day_hours']) * 60));
    $sum = ['present' => 0, 'worked' => 0, 'leave_min' => 0, 'leave_days' => 0, 'work_days' => 0, 'mood_sum' => 0, 'mood_n' => 0, 'acts' => 0, 'over' => 0, 'remote' => 0, 'mission' => 0];
    foreach ($days as $D) {
        if (!$D['off']) $sum['work_days']++;
        if ($D['worked'] > 0 || $D['check_in'] || in_array($D['day_type'], ['REMOTE', 'MISSION'], true)) $sum['present']++;
        $sum['worked'] += $D['worked'];
        $sum['leave_min'] += $D['leave_min'];
        if ($D['day_type'] === 'LEAVE') $sum['leave_days']++;
        if ($D['day_type'] === 'REMOTE') $sum['remote']++;
        if ($D['day_type'] === 'MISSION') $sum['mission']++;
        if ($D['mood']) { $sum['mood_sum'] += $D['mood']; $sum['mood_n']++; }
        $sum['acts'] += $D['acts'];
        if (!empty($D['live'])) continue;   // امروز هنوز تمام نشده؛ در اضافه/کسرِ کار حساب نمی‌شود
        if ($D['worked'] > 0 && !$D['off']) $sum['over'] += $D['worked'] + $D['leave_min'] - $std;
        elseif ($D['worked'] > 0 && $D['off']) $sum['over'] += $D['worked'];
    }
    $sum['mood_avg'] = $sum['mood_n'] ? round($sum['mood_sum'] / $sum['mood_n'], 1) : null;
    return $sum;
}

// ---------------- مانده‌ی مرخصی ----------------
// مانده = مانده‌ی اولِ ماهِ شروع (اگر کاربر وارد کرده) + سهمِ ماهانه برای هر ماه از آن ماه تا ماهِ مورد نظر − مرخصی‌های ثبت‌شده (به‌جز ردشده‌ها)
function wk_balance($pdo, $uid, $uptoJy = null, $uptoJm = null) {
    wk_ensure($pdo);
    $s = wk_settings($pdo);
    [$cy, $cm] = jalali_from_gregorian_ts(time());
    $uy = $uptoJy ?: $cy; $um = $uptoJm ?: $cm;
    $st = $pdo->prepare("SELECT * FROM work_profiles WHERE user_id = ?");
    $st->execute([$uid]);
    $p = $st->fetch() ?: null;
    if ($p && $p['opening_jy']) { $sy = intval($p['opening_jy']); $sm = intval($p['opening_jm']); $open = intval($p['opening_minutes']); }
    else {
        $first = null;
        try {
            $q = $pdo->prepare("SELECT MIN(d) FROM (SELECT MIN(date_from) d FROM work_leaves WHERE user_id = ? UNION ALL SELECT MIN(wdate) FROM work_days WHERE user_id = ? UNION ALL SELECT MIN(wdate) FROM work_presence WHERE user_id = ?) x");
            $q->execute([$uid, $uid, $uid]);
            $first = $q->fetchColumn();
        } catch (Throwable $e) {}
        [$sy, $sm] = $first ? jalali_from_gregorian_ts(strtotime($first . ' 12:00:00')) : [$cy, $cm];
        $open = 0;
    }
    $months = ($uy - $sy) * 12 + ($um - $sm) + 1;
    if ($months < 0) $months = 0;
    $accrual = intval(round(floatval($s['work_leave_days_month']) * floatval($s['work_leave_day_hours']) * 60));
    $startG = wk_j2g(sprintf('%04d/%02d/01', $sy, $sm));
    $endG = wk_month_range($uy, $um)[1];
    $used = 0; $pending = 0;
    try {
        $q = $pdo->prepare("SELECT status, SUM(minutes) m FROM work_leaves WHERE user_id = ? AND status <> 'REJECTED' AND date_from >= ? AND date_from <= ? GROUP BY status");
        $q->execute([$uid, $startG, $endG]);
        foreach ($q->fetchAll() as $r) { $used += intval($r['m']); if ($r['status'] === 'PENDING') $pending += intval($r['m']); }
    } catch (Throwable $e) {}
    $bal = $open + $months * $accrual - $used;
    return ['opening_set' => (bool)($p && $p['opening_jy']), 'opening_minutes' => $open, 'start' => sprintf('%04d/%02d', $sy, $sm), 'months' => $months,
            'accrual' => $accrual, 'used' => $used, 'pending' => $pending, 'balance' => $bal,
            'balance_fa' => wk_leave_fa($pdo, $bal), 'used_fa' => wk_leave_fa($pdo, $used), 'accrual_fa' => wk_leave_fa($pdo, $accrual)];
}

// ---------------- خطِ زمانیِ یک روز ----------------
function wk_timeline($pdo, $uid, $g) {
    wk_ensure($pdo);
    $out = [];
    try {
        $st = $pdo->prepare("SELECT method, TIME(created_at) t, device, browser FROM login_logs WHERE user_type = 'STAFF' AND user_id = ? AND success = 1 AND DATE(created_at) = ? ORDER BY created_at");
        $st->execute([$uid, $g]);
        foreach ($st->fetchAll() as $r) $out[] = ['t' => substr($r['t'], 0, 5), 'kind' => $r['method'] === 'LOGOUT' ? 'logout' : 'login',
            'label' => $r['method'] === 'LOGOUT' ? 'خروج از پنل' : ('ورود به پنل' . ($r['method'] === 'OTP' ? ' (کد بله)' : '')), 'ref' => trim(($r['device'] ?? '') . ' ' . ($r['browser'] ?? ''))];
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT TIME(first_seen) f, TIME(last_seen) l FROM work_presence WHERE user_id = ? AND wdate = ?");
        $st->execute([$uid, $g]);
        if ($r = $st->fetch()) {
            $out[] = ['t' => substr($r['f'], 0, 5), 'kind' => 'seen', 'label' => 'اولین فعالیت در پنل', 'ref' => ''];
            if ($r['l'] !== $r['f']) $out[] = ['t' => substr($r['l'], 0, 5), 'kind' => 'seen', 'label' => 'آخرین فعالیت در پنل', 'ref' => ''];
        }
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT TIME(at) t, page, op, label, ref, qty FROM work_activity WHERE user_id = ? AND wdate = ? ORDER BY at");
        $st->execute([$uid, $g]);
        foreach ($st->fetchAll() as $r) $out[] = ['t' => substr($r['t'], 0, 5), 'kind' => $r['op'] ?: 'edit', 'label' => wk_count_title($r['label'], intval($r['qty']), $r['op']), 'ref' => (string)$r['ref'], 'page' => $r['page']];
    } catch (Throwable $e) {}
    usort($out, function ($a, $b) { return strcmp($a['t'], $b['t']); });
    // ورود و خروجِ چندباره: فقط اولین ورود و آخرین خروجِ روز (تعدادِ بقیه کنارش)
    $logins = array_values(array_filter($out, function ($e) { return $e['kind'] === 'login'; }));
    $logouts = array_values(array_filter($out, function ($e) { return $e['kind'] === 'logout'; }));
    $keepIn = $logins ? $logins[0] : null; $keepOut = $logouts ? $logouts[count($logouts) - 1] : null;
    $res = [];
    foreach ($out as $e) {
        if ($e['kind'] === 'login') { if ($e !== $keepIn) continue; if (count($logins) > 1) $e['ref'] = trim($e['ref'] . ' · ' . wk_fa_num(count($logins)) . ' بار ورود در طولِ روز', ' ·'); }
        if ($e['kind'] === 'logout') { if ($e !== $keepOut) continue; if (count($logouts) > 1) $e['ref'] = trim($e['ref'] . ' · ' . wk_fa_num(count($logouts)) . ' بار خروج در طولِ روز', ' ·'); }
        $res[] = $e;
    }
    return $res;
}

function wk_fa_num($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
// «صدورِ گزارشِ بازدید» × ۵ → «صدورِ ۵ فقره گزارشِ بازدید»؛ عنوانی که این شکل را ندارد → «… (۵ بار)»
function wk_count_title($label, $n, $op = '') {
    $label = trim((string)$label);
    if ($n <= 1) return $label;
    $parts = preg_split('/\s+/u', $label, 2);
    if ($op !== 'export' && count($parts) === 2 && preg_match('/\x{0650}$/u', $parts[0]))
        return preg_replace('/\x{0650}$/u', '', $parts[0]) . ' ' . wk_fa_num($n) . ' فقره ' . $parts[1];
    return $label . ' (' . wk_fa_num($n) . ' بار)';
}

// کارهای خودکارِ هر روز، جمع‌بندی‌شده (برای «از کارهای پنل» و گزارش‌ها):
// اولین ورودِ روز، کارهای هم‌نوع با تعداد (ثالث و بدنه جدا)، آخرین خروجِ روز. خروجی: [تاریخ => [[from, to, title, auto], ...]]
function wk_auto_tasks($pdo, $uid, $from, $to) {
    wk_ensure($pdo);
    $out = [];
    $q = function ($sql, $args) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); } catch (Throwable $e) { return []; } };
    $in = []; $outT = [];
    foreach ($q("SELECT DATE(created_at) d, MIN(TIME(created_at)) f, MAX(TIME(created_at)) l, SUM(method <> 'LOGOUT') ni, SUM(method = 'LOGOUT') no,
                        MIN(CASE WHEN method <> 'LOGOUT' THEN TIME(created_at) END) fi, MAX(CASE WHEN method = 'LOGOUT' THEN TIME(created_at) END) lo
                   FROM login_logs WHERE user_type = 'STAFF' AND user_id = ? AND success = 1 AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)",
                [$uid, "$from 00:00:00", "$to 23:59:59"]) as $r) {
        if ($r['fi']) $in[$r['d']] = substr($r['fi'], 0, 5);
        if ($r['lo']) $outT[$r['d']] = substr($r['lo'], 0, 5);
    }
    foreach ($q("SELECT wdate, TIME(first_seen) f FROM work_presence WHERE user_id = ? AND wdate BETWEEN ? AND ?", [$uid, $from, $to]) as $r)
        if (!isset($in[$r['wdate']]) || substr($r['f'], 0, 5) < $in[$r['wdate']]) $in[$r['wdate']] = substr($r['f'], 0, 5);
    $acts = [];
    foreach ($q("SELECT wdate, TIME(at) t, op, label, ref, qty FROM work_activity WHERE user_id = ? AND wdate BETWEEN ? AND ? ORDER BY at", [$uid, $from, $to]) as $r) {
        // عنوان‌های قدیمیِ صدور با عنوانِ تازه یکی می‌شوند تا با هم شمرده شوند
        $k = strtr((string)$r['label'], ['ثبتِ صدورِ بیمه‌نامه‌ی شرکتی' => 'صدورِ بیمه‌نامه‌ی شرکتی', 'صدورِ گروهی از فایلِ بیمه‌گر' => 'صدورِ بیمه‌نامه‌ی شرکتی']);
        if (!isset($acts[$r['wdate']][$k])) $acts[$r['wdate']][$k] = ['from' => substr($r['t'], 0, 5), 'to' => '', 'n' => 0, 'op' => $r['op'], 'refs' => []];
        $A = &$acts[$r['wdate']][$k];
        $A['n'] += max(1, intval($r['qty']));
        $A['to'] = substr($r['t'], 0, 5);
        if ($r['ref'] !== null && $r['ref'] !== '') $A['refs'][$r['ref']] = true;
        unset($A);
    }
    $dates = array_unique(array_merge(array_keys($in), array_keys($outT), array_keys($acts)));
    sort($dates);
    foreach ($dates as $d) {
        $L = [];
        if (isset($in[$d])) $L[] = ['from' => $in[$d], 'to' => '', 'title' => 'ورود به پنل', 'auto' => 'in'];
        foreach ($acts[$d] ?? [] as $label => $A) {
            $title = wk_count_title($label, $A['n'], $A['op']);
            $refs = array_keys($A['refs']);
            if ($A['n'] === 1 && $refs) $title .= ' (' . $refs[0] . ')';   // کارِ تکی: مشخصاتش؛ چندتایی: فقط تعداد
            $L[] = ['from' => $A['from'], 'to' => $A['to'] !== $A['from'] ? $A['to'] : '', 'title' => $title, 'auto' => 'a' . substr(md5($label), 0, 10)];
        }
        if (isset($outT[$d])) $L[] = ['from' => $outT[$d], 'to' => '', 'title' => 'خروج از پنل', 'auto' => 'out'];
        $out[$d] = $L;
    }
    return $out;
}

const WK_TYPES = ['WORK' => 'حضوری', 'REMOTE' => 'دورکاری', 'MISSION' => 'مأموریت', 'LEAVE' => 'مرخصی', 'OFF' => 'تعطیل', 'ABSENT' => 'غیبت'];
const WK_MOODS = [1 => '😞', 2 => '😕', 3 => '😐', 4 => '🙂', 5 => '😄'];
