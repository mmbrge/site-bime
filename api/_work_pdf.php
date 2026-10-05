<?php
// فایل: api/_work_pdf.php
// گزارشِ PDFِ کارکرد (ماهانه یا هر بازه): سربرگ، کارت‌های خلاصه، جدولِ روز به روز، جمع‌ها (موظفی، کارکرد،
// اضافه‌کار/کسرکار، مرخصی، تأخیر، تعجیل، غیبت، ...)، فهرستِ مرخصی‌ها، شرحِ کارهای هر روز و جای امضا.
// برای چند نفر (مدیر: همه‌ی پرسنل) هر نفر از صفحه‌ی تازه شروع می‌شود.

require_once __DIR__ . '/_work.php';
require_once __DIR__ . '/_work_service.php';   // ws_pdf_kit, ws_fa

const WK_MOOD_FA = [1 => 'خیلی بد', 2 => 'بد', 3 => 'معمولی', 4 => 'خوب', 5 => 'عالی'];

// همه‌ی عددهای خلاصه‌ی یک نفر در یک بازه
function wk_pdf_stats($pdo, $uid, $from, $to) {
    $s = wk_settings($pdo);
    $std = intval(round(floatval($s['work_day_hours']) * 60));
    $days = wk_days($pdo, $uid, $from, $to);
    $sm = wk_summary($pdo, $days);
    $today = wk_today();
    $st = ['required' => 0, 'late_n' => 0, 'late_min' => 0, 'early_n' => 0, 'early_min' => 0, 'absent' => 0, 'over_plus' => 0, 'over_minus' => 0,
           'leave_day_n' => 0, 'leave_hour_min' => 0, 'leave_hour_n' => 0, 'off_work' => 0];
    foreach ($days as $g => $D) {
        $hasDayLeave = false;
        foreach ($D['leaves'] as $L) {
            if ($L['kind'] === 'DAY') $hasDayLeave = true;
            else { $st['leave_hour_min'] += $L['minutes']; $st['leave_hour_n']++; }
        }
        if ($hasDayLeave && !$D['off']) $st['leave_day_n']++;
        if ($D['off']) { if ($D['worked'] > 0) $st['off_work'] += $D['worked']; continue; }
        $st['required'] += $std;
        if ($g > $today) continue;
        $special = in_array($D['day_type'], ['LEAVE', 'REMOTE', 'MISSION', 'OFF'], true) || $hasDayLeave;
        if (!$D['check_in'] && !$special && $g < $today) $st['absent']++;
        if ($D['check_in'] && !$special) {
            $late = wk_minutes_between($s['work_start'], $D['check_in']);
            if ($late > 0) { $st['late_n']++; $st['late_min'] += $late; }
            if ($D['check_out'] && empty($D['live'])) {
                $early = wk_minutes_between($D['check_out'], $s['work_end']);
                if ($early > 0) { $st['early_n']++; $st['early_min'] += $early; }
            }
        }
        if ($D['worked'] > 0 && empty($D['live'])) {
            $d = $D['worked'] + $D['leave_min'] - $std;
            if ($d > 0) $st['over_plus'] += $d; else $st['over_minus'] += -$d;
        }
    }
    $st['over_plus'] += $st['off_work'];
    return [$days, $sm, $st];
}

function wk_pdf($pdo, array $uids, $from, $to) {
    $s = wk_settings($pdo);
    [$fjy, $fjm, $fjd] = jalali_from_gregorian_ts(strtotime($from . ' 12:00:00'));
    [$tjy, $tjm] = jalali_from_gregorian_ts(strtotime($to . ' 12:00:00'));
    $fullMonth = $fjd === 1 && $fjy === $tjy && $fjm === $tjm && wk_month_range($fjy, $fjm)[1] === $to;
    $period = $fullMonth ? jalali_month_name($fjm) . ' ' . ws_fa($fjy) : 'از ' . ws_fa(wk_g2j($from)) . ' تا ' . ws_fa(wk_g2j($to));
    [$pdf, $T, $box, $hex, $W] = ws_pdf_kit('گزارش کارکرد ' . $period);
    $first = true;
    $dowFa = [6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه'];
    $dur = function ($m) { return ws_fa(wk_dur_fa($m)); };
    // متنِ چندخطیِ راست‌به‌چپ؛ ارتفاعِ استفاده‌شده را برمی‌گرداند
    $ML = function ($x, $y, $w, $text, $size = 8, $style = '', $color = '#334155', $lh = 4.2) use ($pdf, $W, $hex) {
        $pdf->setRTL(true);
        $pdf->SetFont('vazir', $style, $size);
        $pdf->SetTextColor(...$hex($color));
        $pdf->SetXY($W - ($x + $w), $y);
        $pdf->MultiCell($w, $lh, "\u{200F}" . $text, 0, 'R', false, 1, '', '', true, 0, false, true, 0, 'T');
        $h = $pdf->GetY() - $y;
        $pdf->setRTL(false);
        return $h;
    };
    $hgt = function ($w, $text, $size = 8, $style = '', $lh = 4.2) use ($pdf) {
        $pdf->setRTL(true); $pdf->SetFont('vazir', $style, $size);
        $n = $pdf->getNumLines("\u{200F}" . $text, $w);
        $pdf->setRTL(false);
        return max(1, $n) * $lh;
    };
    $footer = function ($name) use ($T, $pdf) {
        $T(12, 289, 186, 4, 'گزارشِ کارکرد · ' . $name . ' · بیمه با ما · صفحه‌ی ' . ws_fa($pdf->getPage()), 6.5, '', '#94a3b8', 'C');
    };

    foreach ($uids as $uid) {
        $uid = intval($uid);
        $u = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
        $u->execute([$uid]);
        $u = $u->fetch();
        if (!$u) continue;
        if (!$first) $pdf->AddPage();
        $first = false;
        $name = (string)$u['full_name'];
        $roleFa = ['ADMIN' => 'مدیر کل', 'OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی', 'COMPANY_LIAISON' => 'کارمند بیمه با ما',
                   'PARSIAN' => 'کارمند بیمه با ما (پارسیان)'][$u['role']] ?? 'همکار';
        [$days, $sm, $st] = wk_pdf_stats($pdo, $uid, $from, $to);
        $bal = wk_balance($pdo, $uid, $tjy, $tjm);
        $auto = wk_auto_tasks($pdo, $uid, $from, $to);
        $lv = $pdo->prepare("SELECT * FROM work_leaves WHERE user_id = ? AND date_to >= ? AND date_from <= ? ORDER BY date_from, id");
        $lv->execute([$uid, $from, $to]);
        $leaves = $lv->fetchAll();

        // ---- سربرگ ----
        $pdf->LinearGradient(0, 0, $W, 40, $hex('#1e1b4b'), $hex('#0f766e'), [0, 0, 1, 0]);
        $pdf->SetAlpha(0.12); $pdf->SetFillColor(255, 255, 255);
        $pdf->Circle(16, 4, 24, 0, 360, 'F'); $pdf->Circle(192, 44, 28, 0, 360, 'F'); $pdf->Circle(128, -10, 16, 0, 360, 'F');
        $pdf->SetAlpha(1);
        $T(70, 7, 128, 10, 'گزارشِ کارکرد', 20, 'B', '#ffffff');
        $T(70, 18.5, 128, 7, $name . ' · ' . $roleFa, 12, 'B', '#e0e7ff');
        $T(70, 27, 128, 6, 'بازه: ' . $period . ' · ساعتِ کاری ' . ws_fa($s['work_start']) . ' تا ' . ws_fa($s['work_end']) . ' · موظفیِ روزانه ' . ws_fa($s['work_day_hours']) . ' ساعت', 8.5, '', '#a5f3fc');
        $box(12, 9, 52, 22, '#ffffff', 4);
        $T(14, 11, 48, 6, 'شماره‌ی گزارش', 7.5, '', '#64748b', 'C');
        $T(14, 16.5, 48, 6, ws_fa(sprintf('WK-%d-%04d%02d', $uid, $fjy, $fjm)), 10, 'B', '#1e1b4b', 'C');
        $T(14, 23, 48, 6, 'صدور: ' . ws_fa(wk_g2j(date('Y-m-d'))) . ' · ' . ws_fa(date('H:i')), 7.5, '', '#475569', 'C');

        // ---- کارت‌های خلاصه ----
        $y = 46;
        $cards = [
            ['روزهای حضور', ws_fa($sm['present']) . ' از ' . ws_fa($sm['work_days']) . ' روزِ کاری', '#eef2ff', '#4338ca'],
            ['ساعتِ کارکرد', $dur($sm['worked']) . ' ساعت', '#ecfeff', '#0e7490'],
            ['اضافه / کسرِ خالص', ($sm['over'] < 0 ? 'کسر ' : 'اضافه ') . $dur(abs($sm['over'])), $sm['over'] < 0 ? '#fff1f2' : '#f0fdf4', $sm['over'] < 0 ? '#be123c' : '#15803d'],
            ['مرخصیِ این بازه', wk_leave_fa($pdo, $sm['leave_min']), '#fff7ed', '#c2410c'],
        ];
        $cw = (186 - 3 * 3) / 4;
        foreach ($cards as $i => $c) {
            $x = 12 + (3 - $i) * ($cw + 3);
            $box($x, $y, $cw, 17, $c[2], 3);
            $T($x + 3, $y + 2, $cw - 6, 5, $c[0], 7.5, '', '#64748b');
            $T($x + 3, $y + 8, $cw - 6, 7, $c[1], mb_strlen($c[1]) > 24 ? 8 : 10, 'B', $c[3]);
        }

        // ---- جدولِ روزها ----
        $cols = [['روز', 9], ['تاریخ', 20], ['روزِ هفته', 17], ['نوع', 17], ['ورود', 14], ['خروج', 14], ['کارکرد', 15], ['اضافه/کسر', 17], ['مرخصی', 15], ['حال', 13], ['کارها', 35]];
        $tx = function ($idx) use ($cols) { $x = 198; for ($i = 0; $i <= $idx; $i++) $x -= $cols[$i][1]; return $x; };
        $head = function ($y) use ($box, $T, $cols, $tx) {
            $box(12, $y, 186, 7, '#1e293b', 2);
            foreach ($cols as $i => $c) $T($tx($i), $y, $c[1], 7, $c[0], 7.5, 'B', '#ffffff', 'C');
            return $y + 7.6;
        };
        $y = $head(68);
        $tStart = $y;
        $rh = count($days) > 31 ? 4.6 : (count($days) > 28 ? 4.75 : 5);
        $std = intval(round(floatval($s['work_day_hours']) * 60));
        $k = 0;
        foreach ($days as $g => $D) {
            if ($y + $rh > 280) {
                $pdf->SetDrawColor(...$hex('#e2e8f0')); $pdf->SetLineWidth(0.3); $pdf->Rect(12, $tStart, 186, $y - $tStart, 'D');
                $footer($name); $pdf->AddPage(); $y = $head(14); $tStart = $y;
            }
            $type = $D['day_type'] ? (WK_TYPES[$D['day_type']] ?? '') : ($D['off'] ? 'تعطیل' : '');
            $absent = !$D['off'] && !$D['check_in'] && !$D['leaves'] && !in_array($D['day_type'], ['LEAVE', 'REMOTE', 'MISSION', 'OFF'], true) && $g < wk_today();
            if ($absent) $type = 'غیبت';
            $bg = $D['off'] ? '#fff1f2' : ($absent ? '#fef2f2' : ($type === 'مرخصی' ? '#fffbeb' : ($k % 2 ? '#f5f7ff' : '#ffffff')));
            $pdf->SetFillColor(...$hex($bg)); $pdf->Rect(12, $y, 186, $rh, 'F');
            $red = $D['off'] ? '#e11d48' : '#334155';
            $T($tx(0), $y, $cols[0][1], $rh, ws_fa(intval(substr($D['jdate'], 8, 2))), 7.5, 'B', $red, 'C');
            $T($tx(1), $y, $cols[1][1], $rh, ws_fa($D['jdate']), 7, '', '#475569', 'C');
            $T($tx(2), $y, $cols[2][1], $rh, $dowFa[$D['dow']], 7, '', $red, 'C');
            $typeClr = ['حضوری' => '#047857', 'دورکاری' => '#0369a1', 'مأموریت' => '#7c3aed', 'مرخصی' => '#b45309', 'غیبت' => '#dc2626', 'تعطیل' => '#e11d48'][$type] ?? '#64748b';
            $T($tx(3), $y, $cols[3][1], $rh, $type ?: '—', 7, 'B', $type ? $typeClr : '#cbd5e1', 'C');
            $T($tx(4), $y, $cols[4][1], $rh, $D['check_in'] ? ws_fa($D['check_in']) : '—', 7.5, '', $D['check_in'] ? '#0f172a' : '#cbd5e1', 'C');
            $T($tx(5), $y, $cols[5][1], $rh, $D['check_out'] ? ws_fa($D['check_out']) : '—', 7.5, '', $D['check_out'] ? '#0f172a' : '#cbd5e1', 'C');
            $T($tx(6), $y, $cols[6][1], $rh, $D['worked'] ? $dur($D['worked']) : '—', 7.5, $D['worked'] ? 'B' : '', $D['worked'] ? '#0f172a' : '#cbd5e1', 'C');
            if ($D['worked'] && empty($D['live'])) {
                $d = $D['off'] ? $D['worked'] : $D['worked'] + $D['leave_min'] - $std;
                $T($tx(7), $y, $cols[7][1], $rh, ($d < 0 ? '−' : '+') . $dur(abs($d)), 7.5, 'B', $d < 0 ? '#be123c' : '#15803d', 'C');
            } else $T($tx(7), $y, $cols[7][1], $rh, '—', 7.5, '', '#cbd5e1', 'C');
            $T($tx(8), $y, $cols[8][1], $rh, $D['leave_min'] ? $dur($D['leave_min']) : '—', 7.5, '', $D['leave_min'] ? '#b45309' : '#cbd5e1', 'C');
            $T($tx(9), $y, $cols[9][1], $rh, $D['mood'] ? (WK_MOOD_FA[$D['mood']] ?? '') : '—', 6.8, '', $D['mood'] ? '#475569' : '#cbd5e1', 'C');
            $tasks = $D['tasks'] ?: ($auto[$g] ?? []);
            $tt = '';
            if ($tasks) {
                $t0 = $tasks[0]['title'];
                if (count($tasks) > 1) $t0 = ws_fa(count($tasks)) . ' کار · ' . $t0;
                $tt = mb_strlen($t0) > 30 ? mb_substr($t0, 0, 29) . '…' : $t0;
            }
            $T($tx(10) + 1, $y, $cols[10][1] - 2, $rh, $tt ?: '—', 6.5, '', $tt ? '#475569' : '#cbd5e1', 'R');
            $y += $rh; $k++;
        }
        $pdf->SetDrawColor(...$hex('#e2e8f0')); $pdf->SetLineWidth(0.3); $pdf->Rect(12, $tStart, 186, $y - $tStart, 'D');

        // ---- جمع‌ها ----
        $tot = [
            ['ساعتِ موظفی', $dur($st['required']), '#334155'],
            ['ساعتِ کارکرد', $dur($sm['worked']), '#0e7490'],
            ['اضافه‌کار', $dur($st['over_plus']), '#15803d'],
            ['کسرِ کار', $dur($st['over_minus']), '#be123c'],
            ['خالصِ اضافه/کسر', ($sm['over'] < 0 ? '−' : '+') . $dur(abs($sm['over'])), $sm['over'] < 0 ? '#be123c' : '#15803d'],
            ['کارکرد در تعطیلی', $dur($st['off_work']), '#7c3aed'],
            ['مرخصیِ روزانه', ws_fa($st['leave_day_n']) . ' روز', '#b45309'],
            ['مرخصیِ ساعتی', ws_fa($st['leave_hour_n']) . ' بار · ' . $dur($st['leave_hour_min']), '#b45309'],
            ['مانده‌ی مرخصی', $bal['balance_fa'], $bal['balance'] < 0 ? '#be123c' : '#047857'],
            ['تأخیر در ورود', ws_fa($st['late_n']) . ' بار · ' . $dur($st['late_min']), '#dc2626'],
            ['تعجیل در خروج', ws_fa($st['early_n']) . ' بار · ' . $dur($st['early_min']), '#ea580c'],
            ['غیبت', ws_fa($st['absent']) . ' روز', '#dc2626'],
            ['دورکاری / مأموریت', ws_fa($sm['remote']) . ' / ' . ws_fa($sm['mission']) . ' روز', '#0369a1'],
            ['میانگینِ حال', $sm['mood_avg'] ? (WK_MOOD_FA[intval(round($sm['mood_avg']))] ?? '') . ' (' . ws_fa($sm['mood_avg']) . ' از ۵)' : '—', '#4338ca'],
            ['کارهای ثبت‌شده در پنل', ws_fa($sm['acts']) . ' مورد', '#4338ca'],
            ['روزهای کاریِ بازه', ws_fa($sm['work_days']) . ' روز', '#334155'],
            ['تعطیلاتِ بازه', ws_fa(count(array_filter($days, function ($D) { return $D['off']; }))) . ' روز', '#e11d48'],
            ['میانگینِ کارکردِ روزانه', $sm['present'] ? $dur(intdiv($sm['worked'], max(1, $sm['present']))) : '—', '#0e7490'],
        ];
        $per = 6; $gw = (186 - ($per - 1) * 2.5) / $per; $gh = 12.5;
        $need = 10 + ceil(count($tot) / $per) * ($gh + 2.5);
        $y += 4;
        if ($y + $need > 282) { $footer($name); $pdf->AddPage(); $y = 14; }
        $T(12, $y, 186, 7, 'جمع‌بندیِ ' . $period, 11, 'B', '#1e1b4b');
        $y += 8;
        foreach ($tot as $i => $c) {
            $row = intdiv($i, $per); $col = $i % $per;
            $x = 198 - ($col + 1) * $gw - $col * 2.5;
            $yy = $y + $row * ($gh + 2.5);
            $box($x, $yy, $gw, $gh, '#f8fafc', 2.5, '#e2e8f0');
            $pdf->SetFillColor(...$hex($c[2])); $pdf->Rect($x + $gw - 1.6, $yy + 2.5, 1.2, $gh - 5, 'F');
            $T($x + 1.5, $yy + 1.3, $gw - 4.5, 5, $c[0], 6.6, '', '#64748b');
            $T($x + 1.5, $yy + 6, $gw - 4.5, 6, $c[1], mb_strlen($c[1]) > 16 ? 6.8 : 8.5, 'B', $c[2]);
        }
        $y += ceil(count($tot) / $per) * ($gh + 2.5) + 2;

        // ---- مرخصی‌ها ----
        if ($leaves) {
            $need = 16 + count($leaves) * 5.2;
            if ($y + min($need, 40) > 282) { $footer($name); $pdf->AddPage(); $y = 14; }
            $T(12, $y, 186, 7, 'مرخصی‌های این بازه', 11, 'B', '#1e1b4b');
            $y += 8;
            $lc = [['نوع', 22], ['از', 26], ['تا', 26], ['ساعت', 30], ['مدت', 30], ['وضعیت', 22], ['علت', 30]];
            $lx = function ($idx) use ($lc) { $x = 198; for ($i = 0; $i <= $idx; $i++) $x -= $lc[$i][1]; return $x; };
            $box(12, $y, 186, 6.5, '#78350f', 2);
            foreach ($lc as $i => $c) $T($lx($i), $y, $c[1], 6.5, $c[0], 7.5, 'B', '#ffffff', 'C');
            $y += 7;
            foreach ($leaves as $i => $L) {
                if ($y + 5.2 > 282) { $footer($name); $pdf->AddPage(); $y = 14; }
                $pdf->SetFillColor(...$hex($i % 2 ? '#fffbeb' : '#ffffff')); $pdf->Rect(12, $y, 186, 5.2, 'F');
                $stFa = ['APPROVED' => ['تأیید شده', '#047857'], 'PENDING' => ['در انتظار', '#b45309'], 'REJECTED' => ['رد شده', '#dc2626']][$L['status']] ?? [$L['status'], '#475569'];
                $vals = [$L['kind'] === 'HOUR' ? 'ساعتی' : 'روزانه', ws_fa(wk_g2j($L['date_from'])), ws_fa(wk_g2j($L['date_to'])),
                         $L['kind'] === 'HOUR' ? ws_fa(substr((string)$L['time_from'], 0, 5) . ' تا ' . substr((string)$L['time_to'], 0, 5)) : '—',
                         wk_leave_fa($pdo, intval($L['minutes'])), $stFa[0], mb_substr((string)$L['reason'], 0, 26)];
                foreach ($vals as $j => $v) $T($lx($j), $y, $lc[$j][1], 5.2, $v !== '' ? $v : '—', 7, $j === 5 ? 'B' : '', $j === 5 ? $stFa[1] : '#334155', 'C');
                $y += 5.2;
            }
            $y += 3;
        }

        // ---- شرحِ کارهای روزانه ----
        $detail = [];
        foreach ($days as $g => $D) {
            $tasks = $D['tasks']; $isAuto = false;
            if (!$tasks && !empty($auto[$g])) { $tasks = $auto[$g]; $isAuto = true; }
            if ($tasks || trim((string)$D['note']) !== '') $detail[$g] = [$D, $tasks, $isAuto];
        }
        if ($detail) {
            if ($y + 30 > 282) { $footer($name); $pdf->AddPage(); $y = 14; }
            $T(12, $y, 186, 7, 'شرحِ کارهای روزانه', 11, 'B', '#1e1b4b');
            $T(12, $y, 186, 7, 'کارهایی که در «کارکرد» ثبت شده؛ اگر روزی شرح ننوشته باشد، کارهای ثبت‌شده‌ی پنل آمده است', 6.8, '', '#94a3b8', 'L');
            $y += 8.5;
            foreach ($detail as $g => [$D, $tasks, $isAuto]) {
                $lines = array_map(function ($t) {
                    $tm = $t['from'] ? ws_fa($t['from']) . ($t['to'] ? ' تا ' . ws_fa($t['to']) : '') . ' — ' : '';
                    return '• ' . $tm . $t['title'];
                }, $tasks);
                $txt = implode("\n", $lines);
                $note = trim((string)$D['note']);
                $h = 6.5 + array_sum(array_map(function ($l) use ($hgt) { return $hgt(176, $l, 7.5); }, $lines)) + ($note !== '' ? $hgt(176, 'توضیحات: ' . $note, 7.2, '', 4) + 1 : 0) + 2;
                if ($y + $h > 282) { $footer($name); $pdf->AddPage(); $y = 14; }
                $pdf->SetFillColor(...$hex('#eef2ff')); $pdf->RoundedRect(12, $y, 186, 6, 2.5, '1100', 'F');
                $T(16, $y, 120, 6, $dowFa[$D['dow']] . ' ' . ws_fa($D['jdate']) . ($D['check_in'] ? ' · ' . ws_fa($D['check_in']) . ' تا ' . ws_fa($D['check_out'] ?: '…') : ''), 8, 'B', '#312e81');
                $T(16, $y, 178, 6, ($D['worked'] ? 'کارکرد ' . $dur($D['worked']) : '') . ($isAuto ? ($D['worked'] ? ' · ' : '') . 'از کارهای ثبت‌شده‌ی پنل' : ''), 7, '', '#64748b', 'L');
                $yy = $y + 7;
                if ($txt !== '') $yy += $ML(16, $yy, 176, $txt, 7.5, '', '#334155');
                if ($note !== '') $yy += 0.5 + $ML(16, $yy + 0.5, 176, 'توضیحات: ' . $note, 7.2, '', '#64748b', 4);
                // قاب بعد از متن کشیده می‌شود تا اندازه‌اش دقیقاً به اندازه‌ی متن باشد
                $h = $yy - $y + 2;
                $pdf->SetDrawColor(...$hex('#e2e8f0')); $pdf->SetLineWidth(0.25);
                $pdf->RoundedRect(12, $y, 186, $h, 2.5, '1111', 'D');
                $y += $h + 2;
            }
        }

        // ---- امضا ----
        if ($y + 24 > 286) { $footer($name); $pdf->AddPage(); $y = 14; }
        $y += 2;
        foreach ([['همکار: ' . $name, 105], ['مدیر / تأییدکننده', 12]] as [$lbl, $x]) {
            $pdf->SetDrawColor(...$hex('#cbd5e1')); $pdf->SetLineWidth(0.3);
            $pdf->SetLineStyle(['dash' => '1,1']);
            $pdf->RoundedRect($x, $y, 93, 20, 3, '1111', 'D');
            $pdf->SetLineStyle(['dash' => 0]);
            $T($x + 4, $y + 2, 85, 6, $lbl . ' — امضا', 8, 'B', '#475569');
        }
        $footer($name);
    }
    if ($first) $T(12, 60, 186, 10, 'کاربری برای گزارش پیدا نشد.', 12, 'B', '#be123c', 'C');
    return $pdf->Output('', 'S');
}
