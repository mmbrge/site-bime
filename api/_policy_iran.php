<?php
// فایل: api/_policy_iran.php
// خواندنِ بیمه‌نامه‌های «بیمه ایران» (ثالث، بدنه، پیشنهادِ صادرشده) و الحاقیه‌هایشان از روی جای متن در PDF.
// چیدمانِ بیمه ایران با پاسارگاد فرق دارد: برچسب‌ها در ستون‌های ثابت‌اند و مقدار در همان ردیف سمتِ چپِ برچسب است؛
// در پیشنهاد/الحاقیه چند برچسب در یک تکه به هم چسبیده‌اند («:نام بیمه گذار:کد ملی:کدپستی») و مقدارها به همان ترتیب از راست به چپ می‌آیند.
// خروجی هم‌شکلِ pb_extract_layout (همان کلیدها) تا بقیه‌ی مسیرِ صدور بدونِ تغییر کار کند.

function pi_is_iran($raw) {
    $t = function_exists('pb_norm') ? pb_norm((string)$raw) : (string)$raw;   // متنِ خام حروفِ «نمایشی» (ﺑﯿﻤﻪ) دارد
    return (mb_strpos($t, '10103858742') !== false || mb_strpos($t, 'بیمه ایران') !== false) && mb_strpos($t, 'پاسارگاد') === false && stripos($t, 'pasargad') === false;
}
function pi_cy($s) { return ($s['y0'] + $s['y1']) / 2; }
function pi_row($a, $b, $tol = 6.5) { return abs(pi_cy($a) - pi_cy($b)) <= $tol; }
function pi_digits($s) { return strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']); }
function pi_clean($v) { return preg_replace('/^[\s\-،,.]+|[\s\-،,.]+$/u', '', preg_replace('/\s+/u', ' ', str_replace(':', ' ', (string)$v))); }
// اولین تاریخِ هم‌ردیف با برچسب (سمتِ چپ): «از ساعت : روز00 1405/07/24»، «زمان صدور 12:55 1405/07/23»
function pi_rowdate(array $sp, $rx) {
    foreach ($sp as $lb) {
        if (!preg_match('~' . $rx . '~u', $lb['t'])) continue;
        if (($own = pi_date(preg_replace('~' . $rx . '~u', '', $lb['t'], 1))) !== '') return $own;
        $row = array_values(array_filter($sp, function ($v) use ($lb) { return $v['i'] !== $lb['i'] && pi_row($v, $lb) && $v['x1'] <= $lb['x0'] + 3 && $lb['x0'] - $v['x1'] < 260; }));
        usort($row, function ($a, $b) { return $b['x1'] <=> $a['x1']; });
        foreach ($row as $v) if (($dt = pi_date($v['t'])) !== '') return $dt;
    }
    return '';
}
function pi_money($v) { $v = preg_replace('/[^\d]/', '', pi_digits($v)); return $v === '' ? 0 : (int)$v; }

// برچسب‌های شناخته‌شده (برای اینکه مقدار با برچسبِ کناری اشتباه نشود)
const PI_LABEL_RX = '^:?\s*(نام(\s*بیمه\s*گ[زذ]ار|\s*مالک|\s*ذینفع|\s*سیستم|\s*کاربر.*|\s*بیمه)?|شخصیت|کد\s*ملی.*|شناسه\s*ملی.*|تلفن.*|نشانی|کد\s*پستی.*|کدپستی|نوع(\s*وسیله.*|\s*الحاقیه.*|\s*بیمه.*)?|سیستم|تیپ|مورد\s*استفاده|رنگ|سال\s*ساخت|ظرفیت|یدک|سیلندر|شماره(\s*موتور|\s*شاسی|\s*انتظامی|\s*بیمه\s*نامه.*|\s*الحاقیه.*)?|VIN|تعهد.*|بیمه\s*گر|خروج\s*از|شعبه|واحد\s*صدور|پروفایل|کاربر\s*صدور|کارگزاری|زمان\s*صدور|آدرس|ارزش.*|سرمایه.*|کد\s*اقتصادی.*|کد\s*یکتا.*|تاریخ.*|شغل|کارگذار|مدت|درصد.*|تعداد.*)\s*:?$';

// برچسب‌هایی که در یک تکه به هم چسبیده‌اند: «:الف:ب:ج» => [الف، ب، ج] (ترتیب از راست به چپ = ترتیبِ مقدارها)
function pi_label_parts($t) {
    $parts = array_values(array_filter(array_map('trim', explode(':', (string)$t)), 'strlen'));
    return $parts;
}
// مقدارِ یک برچسب. $rx: الگوی برچسب (بدونِ «:»)
//   - تکه‌ای که فقط همان برچسب است: نزدیک‌ترین مقدارِ سمتِ چپ در همان ردیف (و چسبیده‌های بعدی تا برچسبِ دیگر)
//   - برچسب + مقدار در یک تکه («: شماره الحاقیه4»، «:سال ساخت1404»): باقی‌مانده‌ی همان تکه
//   - چند برچسبِ چسبیده: مقدارِ هم‌ردیف به همان ترتیب
function pi_val(array $sp, $rx, array $o = []) {
    $nth = $o['nth'] ?? 0; $seen = 0;
    $tol = $o['tol'] ?? 6.5;
    foreach ($sp as $lb) {
        $t = $lb['t'];
        if (!preg_match('~(?:^|:)\s*(' . $rx . ')\s*(?::|$)|(?:^|:)\s*(' . $rx . ')(?=[\d\s(])(.+)$|^(.+?)\s*:\s*(' . $rx . ')\s*$~u', $t, $m)) continue;
        if ($seen++ < $nth) continue;
        $parts = pi_label_parts($t);
        // برچسب و مقدار در یک تکه
        if (count($parts) === 1) {
            $rest = trim(preg_replace('~' . $rx . '~u', '', $parts[0], 1));
            if ($rest !== '' && !preg_match('~' . PI_LABEL_RX . '~u', $parts[0])) return pi_clean($rest);
            if ($rest !== '' && empty($o['label_only'])) {
                // «: شماره الحاقیه4» — برچسب در فهرست هست ولی چیزی پس از آن چسبیده
                if (preg_match('~^(' . $rx . ')(?=[\d\s(])\s*(.+)$~u', $parts[0], $mm) && trim($mm[2]) !== '') return pi_clean($mm[2]);
            }
        }
        // ترتیبِ برچسبِ ما در تکه‌ی چندبرچسبی
        $idx = 0;
        if (count($parts) > 1) { foreach ($parts as $k => $p) if (preg_match('~^' . $rx . '$~u', trim($p))) { $idx = $k; break; } }
        $row = [];
        foreach ($sp as $v) {
            if ($v['i'] === $lb['i'] || !pi_row($v, $lb, $tol)) continue;
            if (count($parts) > 1 ? $v['x0'] >= $lb['x1'] - 2 : $v['x1'] > $lb['x0'] + 3) continue;
            if (!empty($o['maxdx']) && $lb['x0'] - $v['x1'] > $o['maxdx']) continue;
            $row[] = $v;
        }
        usort($row, function ($a, $b) { return $b['x1'] <=> $a['x1']; });
        // تا برچسبِ بعدی (فقط برچسب‌های تکی)
        $vals = [];
        foreach ($row as $v) {
            $isLab = preg_match('~' . PI_LABEL_RX . '~u', $v['t']) || (mb_strpos($v['t'], ':') !== false && preg_match('~^:|:$~u', trim($v['t'])));
            if ($isLab) { if (count($parts) > 1) continue; break; }
            if (preg_match('~^\s*ریال\s*$~u', $v['t'])) continue;
            $vals[] = $v['t'];
        }
        if (!$vals) continue;
        if (count($parts) > 1) return isset($vals[$idx]) ? pi_clean($vals[$idx]) : '';
        return pi_clean($o['join'] ?? false ? implode(' ', $vals) : $vals[0]);
    }
    return '';
}
// خطِ دومِ نشانی (زیرِ همان مقدار)
function pi_addr(array $sp, $rx, $nth = 0) {
    $seen = 0;
    foreach ($sp as $lb) {
        if (!preg_match('~^:?\s*' . $rx . '\s*:?$~u', $lb['t'])) continue;
        if ($seen++ < $nth) continue;
        $vs = array_values(array_filter($sp, function ($v) use ($lb) {
            return $v['x1'] <= $lb['x0'] + 3 && $v['x1'] > $lb['x0'] - 90 && $v['y0'] > $lb['y0'] - 8 && $v['y0'] < $lb['y1'] + 14 && !preg_match('~' . PI_LABEL_RX . '~u', $v['t']);
        }));
        usort($vs, function ($a, $b) { return $a['y0'] <=> $b['y0']; });
        return pi_clean(implode(' ', array_map(function ($v) { return $v['t']; }, $vs)));
    }
    return '';
}
function pi_name($v) {
    $v = pi_clean($v);
    $v = preg_replace('~^(آقای|خانم|آقا|شرکت\s+سهامی\s+خاص)\s+~u', '', $v);
    return trim(preg_replace('/\s+/u', ' ', $v));
}
function pi_plate($t) {
    $t = pi_digits(str_replace(['-', ':', 'ـ'], ' ', (string)$t));
    if (preg_match('~(\d{2})\s*ایران\s*(\d{2})\s*([آ-ی])\s*(\d{3})~u', $t, $m)) return $m[1] . 'ایران ' . $m[4] . ' ' . $m[3] . ' ' . $m[2];     // «70 ایران77د242»
    if (preg_match('~(\d{2})\s*ایران\s*(\d{3})\s*([آ-ی])\s*(\d{2})~u', $t, $m)) return $m[1] . 'ایران ' . $m[2] . ' ' . $m[3] . ' ' . $m[4];     // «66ایران267ع57»
    return '';
}
// نوعِ وسیله و سیستم گاهی در یک تکه‌اند («سواری استیشن8 آ ایکس»)
function pi_split_kind($v) {
    foreach (['سواری استیشن', 'سواری هاچ بک', 'سواری سدان', 'سواری', 'وانت دوکابین', 'وانت', 'کامیونت', 'کامیون', 'مینی بوس', 'میدل باس', 'اتوبوس', 'موتورسیکلت', 'تریلی', 'کشنده', 'لیفتراک', 'جرثقیل', 'آمبولانس'] as $k) {
        if (mb_strpos($v, $k) === 0) return [$k, trim(mb_substr($v, mb_strlen($k)))];
    }
    return [$v, ''];
}
function pi_vin($v) { $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', pi_digits($v))); return preg_match('/^[A-Z0-9]{5,30}$/', $v) ? $v : ''; }
function pi_date($v) { return preg_match('~(1[34]\d{2}/\d{1,2}/\d{1,2})~', pi_digits($v), $m) ? $m[1] : ''; }

// خروجی: آرایه‌ی فیلدها، یا [] اگر چیدمانِ بیمه ایران تشخیص داده نشد
function pi_extract(array $sp, $w, $h, $raw) {
    if (!$sp || !pi_is_iran($raw)) return [];
    usort($sp, function ($a, $b) { return abs($a['y0'] - $b['y0']) > 2 ? $a['y0'] <=> $b['y0'] : $b['x1'] <=> $a['x1']; });   // از بالا به پایین
    $all = pi_digits(implode("\n", array_map(function ($s) { return $s['t']; }, $sp)));
    $head = implode(' ', array_map(function ($s) { return $s['t']; }, array_filter($sp, function ($s) use ($h) { return $s['y0'] < $h * 0.08; })));
    $d = ['ins_company' => 'ایران'];
    $isBody = mb_strpos($head, 'بدنه') !== false || mb_strpos($all, 'ارزش وسیله نقلیه') !== false;
    $d['ins_type'] = $isBody ? 'بدنه' : 'ثالث';
    $d['is_proposal'] = mb_strpos($head, 'پیشنهاد بیمه نامه') !== false;
    $d['is_endorsement'] = mb_strpos($head, 'الحاقیه') !== false;

    // شماره‌ی بیمه‌نامه: ثالث ۶ بخشی (1405/5/1/245/6293/634)، بدنه ۵ بخشی (1405/6/245/6293/97) — اولین در بالای صفحه، نه «بیمه‌نامه‌ی قبل»
    $rx = $isBody ? '1[34]\d{2}/\d{1,2}/\d{1,4}/\d{1,5}/\d{1,6}(?!/)' : '1[34]\d{2}/\d{1,2}/\d{1,2}/\d{1,4}/\d{1,5}/\d{1,6}';
    $cands = [];
    foreach ($sp as $s) {
        if (preg_match('~قبل~u', $s['t'])) continue;
        if (preg_match_all('~(?<![\d/])(' . $rx . ')(?![\d/])~', pi_digits($s['t']), $mm)) foreach ($mm[1] as $pn) $cands[] = [$s['y0'], $pn];
    }
    usort($cands, function ($a, $b) { return $a[0] <=> $b[0]; });
    $d['policy_num'] = $cands ? $cands[0][1] : '';

    // بیمه‌گذار و مالک
    $nameRx = 'نام\s*بیمه\s*گ[زذ]ار|نام\s*بیمه';
    $ins = pi_val($sp, $nameRx);
    if ($ins === '') $ins = pi_val($sp, 'نام', ['maxdx' => 150]);
    $d['insured_name'] = pi_name($ins);
    $own = pi_val($sp, 'نام\s*مالک');
    if ($own === '' && !$isBody) $own = pi_val($sp, 'نام', ['nth' => 1, 'maxdx' => 150]);
    if ($own !== '') $d['owner_name'] = pi_name($own);
    $nid = pi_digits(pi_val($sp, 'کد\s*ملی(\s*بیمه\s*گ[زذ]ار)?|شناسه\s*ملی(\s*بیمه\s*گ[زذ]ار)?'));
    if (preg_match('~(\d{10,11})~', $nid, $m)) $d['national_id'] = $m[1];
    $ph = pi_digits(pi_val($sp, 'تلفن\s*همراه(\s*بیمه\s*گ[زذ]ار)?') ?: pi_val($sp, 'تلفن(\s*تماس|\s*ثابت\s*بیمه\s*گ[زذ]ار)?'));
    if (preg_match('~(0?9\d{9}|0\d{10})~', $ph, $m)) $d['phone'] = strlen($m[1]) === 10 ? '0' . $m[1] : $m[1];
    $pc = pi_digits(pi_val($sp, 'کد\s*پستی(\s*بیمه\s*گ[زذ]ار)?|کدپستی'));
    if (preg_match('~(?<!\d)(\d{10})(?!\d)~', $pc, $m) && $m[1] !== ($d['national_id'] ?? '')) $d['postal_code'] = $m[1];
    $ad = pi_addr($sp, 'نشانی');
    if ($ad !== '') $d['address'] = $ad;

    // مشخصاتِ خودرو
    $kind = pi_val($sp, 'نوع\s*وسیله(\s*نقلیه)?');
    $sys = pi_val($sp, 'نام\s*سیستم|سیستم');
    if ($kind !== '') { [$k2, $rest] = pi_split_kind($kind); $kind = $k2; if ($sys === '' && $rest !== '') $sys = $rest; elseif ($rest !== '' && mb_strpos($sys, $rest) === false) $sys = trim($rest . ' ' . $sys); }
    $d['car_kind'] = $kind;
    $d['car_system'] = $sys;
    $d['car_tip'] = pi_val($sp, 'تیپ');
    $d['usage'] = pi_val($sp, 'مورد\s*استفاده');
    $d['color'] = pi_val($sp, 'رنگ');
    $my = pi_digits(pi_val($sp, 'سال\s*ساخت'));
    if (preg_match('~(1[34]\d{2}|19\d{2}|20\d{2})~', $my, $m)) $d['model_year'] = $m[1];
    $d['capacity'] = pi_val($sp, 'ظرفیت');
    $cy = pi_digits(pi_val($sp, 'سیلندر'));
    if (preg_match('~^\d{1,2}$~', $cy)) $d['cylinders'] = $cy;
    $d['engine_no'] = pi_vin(pi_val($sp, 'شماره\s*موتور'));
    $d['chassis_no'] = pi_vin(pi_val($sp, 'شماره\s*شاسی'));
    $d['vin'] = pi_vin(pi_val($sp, 'VIN'));
    if ($d['vin'] === '' && preg_match('~(?<![A-Z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Z0-9])~', $all, $m)) $d['vin'] = $m[1];
    // پلاک: «شماره انتظامی» (یا در متن)؛ «نامشخص» = صفر کیلومتر
    $pl = pi_val($sp, 'شماره\s*انتظامی');
    $d['plate'] = pi_plate($pl);
    if ($d['plate'] === '') foreach ($sp as $s) if (($p = pi_plate($s['t'])) !== '') { $d['plate'] = $p; break; }
    if ($d['plate'] === '') {
        // پلاکِ تکه‌تکه‌ی الحاقیه/پیشنهادِ ثالث: «- 55 ایران» «611» «66» «ع»
        foreach ($sp as $s) if (preg_match('~^-?\s*(\d{2})\s*ایران$~u', pi_digits(trim($s['t'])), $m)) {
            $near = array_values(array_filter($sp, function ($v) use ($s) { return $v['i'] !== $s['i'] && abs(pi_cy($v) - pi_cy($s)) < 7 && $v['x1'] < $s['x0'] + 2 && $s['x0'] - $v['x1'] < 70; }));
            usort($near, function ($a, $b) { return $b['x1'] <=> $a['x1']; });
            $tx = implode(' ', array_map(function ($v) { return pi_digits($v['t']); }, $near));
            if (preg_match('~(\d{3})\s*([آ-ی])\s*(\d{2})~u', $tx, $q)) $d['plate'] = $m[1] . 'ایران ' . $q[1] . ' ' . $q[2] . ' ' . $q[3];
            break;
        }
    }

    // تاریخ‌ها
    if ($isBody) {
        $d['start_date'] = pi_rowdate($sp, 'شروع\s*بیمه\s*نامه');
        $d['end_date'] = pi_rowdate($sp, 'پایان\s*بیمه\s*نامه');
    } else {
        $d['start_date'] = pi_rowdate($sp, '^\s*از\s*ساعت\s*$');
        $d['end_date'] = pi_rowdate($sp, '^\s*تا\s*ساعت\s*$');
    }
    if ($d['start_date'] === '' || $d['end_date'] === '') {
        // پیشنهاد/الحاقیه: «...از ساعت ... تا ساعت» با دو تاریخ در یک ردیف
        foreach ($sp as $s) if (preg_match('~از\s*ساعت~u', $s['t'])) {
            $ds = [];
            foreach ($sp as $v) if (pi_row($v, $s) && ($dt = pi_date($v['t'])) !== '') $ds[$v['x0']] = $dt;
            krsort($ds); $ds = array_values($ds);
            if (count($ds) >= 2) { $d['start_date'] = $d['start_date'] ?: $ds[0]; $d['end_date'] = $d['end_date'] ?: $ds[1]; break; }
        }
    }
    $d['issue_date'] = pi_rowdate($sp, '^:?\s*(زمان\s*صدور|تاریخ\s*و\s*زمان\s*صدور)\s*:?$');
    if ($d['issue_date'] === '' && preg_match('~تاریخ\s*صدور\s*(1[34]\d{2}/\d{1,2}/\d{1,2})~u', $all, $m)) $d['issue_date'] = $m[1];

    // حق بیمه
    $prem = 0;
    foreach ($sp as $s) {
        if (!preg_match('~جمع\s*کل\s*=?|کل\s*حق\s*بیمه\s*قابل\s*پرداخت|جمع\s*حق\s*بیمه\s*قابل\s*پرداخت|مبلغ\s*قابل\s*پرداخت~u', $s['t'])) continue;
        foreach ($sp as $v) if (pi_row($v, $s, 8) && $v['x1'] <= $s['x0'] + 3 && preg_match('~\d{1,3}(,\d{3}){2,}~', pi_digits($v['t']), $mm)) $prem = max($prem, pi_money($mm[0]));
        if ($prem) break;
    }
    if (!$prem && preg_match('~(\d{1,3}(?:,\d{3}){2,})\s*جمع\s*کل~u', $all, $m)) $prem = pi_money($m[1]);
    if ($prem) $d['premium'] = (string)$prem;

    if ($isBody) {
        $cv = pi_money(pi_val($sp, 'ارزش\s*وسیله\s*نقلیه'));
        if (!$cv) $cv = pi_money(pi_val($sp, 'سرمایه\s*بیمه\s*نامه'));
        if ($cv >= 1000000) $d['car_value'] = (string)$cv;
        // پوشش‌های تکمیلیِ دارای مبلغ (نام + مبلغ در یک تکه)
        $y0 = null; $y1 = null;
        foreach ($sp as $s) { if (preg_match('~پوشش\s*های\s*تکمیلی~u', $s['t']) && mb_strpos($s['t'], 'جمع') === false) $y0 = $y0 === null ? $s['y0'] : min($y0, $s['y0']); if (preg_match('~جمع\s*حق\s*بیمه\s*پوشش~u', $s['t'])) $y1 = $s['y0']; }
        if ($y0 !== null) {
            $covers = [];
            foreach ($sp as $s) {
                if ($s['y0'] <= $y0 || ($y1 !== null && $s['y0'] >= $y1) || $s['x1'] < $w * 0.55) continue;
                if (!preg_match('~^(.*?[آ-ی\)].*?)\s*([\d,]+)\s*$~u', pi_digits($s['t']), $m)) continue;
                if (pi_money($m[2]) <= 0) continue;
                $nm = trim(preg_replace('~\(\s*\)|\s+~u', ' ', $m[1]));
                if (preg_match('~تخفیف|مالیات|عوارض~u', $nm)) continue;
                $covers[] = $nm;
            }
            if ($covers) $d['covers'] = implode('؛ ', $covers);
        }
        if (preg_match('~تخفیف\s*عدم\s*خسارت\s*بدنه\s*بابت~u', $all)) {
            foreach ($sp as $s) if (preg_match('~تخفیف\s*عدم\s*خسارت\s*بدنه~u', $s['t'])) {
                $row = []; foreach ($sp as $v) if (pi_row($v, $s) && $v['x1'] < $s['x0'] + 2 && $s['x0'] - $v['x1'] < 120) $row[] = pi_digits($v['t']);
                if (preg_match('~(\d{1,2})~', implode(' ', $row), $m)) $d['ncd'] = 'عدم خسارت بدنه: ' . $m[1] . ' سال';
                break;
            }
        }
    } else {
        $fin = pi_money(pi_val($sp, 'تعهد\s*مالی|تعهدات\s*مالی'));
        $life = pi_money(pi_val($sp, 'تعهد\s*جانی|تعهدات\s*جانی'));
        $drv = pi_money(pi_val($sp, 'تعهد\s*راننده'));
        if ($fin >= 1000000) $d['liability'] = (string)$fin;
        if ($life >= 1000000) $d['diya'] = (string)$life;
        if ($drv >= 1000000) $d['driver_cover'] = (string)$drv;
        // «14,000,000,000:تعهدات مالی» (مقدار چسبیده به برچسب)
        if (empty($d['liability']) && preg_match('~([\d,]{9,})\s*:\s*(?:تعهدات|پوشش)\s*مالی~u', $all, $m)) $d['liability'] = (string)pi_money($m[1]);
        if (empty($d['diya']) && preg_match('~([\d,]{9,})\s*:\s*پوشش\s*جانی~u', $all, $m)) $d['diya'] = (string)pi_money($m[1]);
        if (empty($d['driver_cover']) && preg_match('~([\d,]{9,})\s*:\s*پوشش\s*حوادث\s*راننده~u', $all, $m)) $d['driver_cover'] = (string)pi_money($m[1]);
    }

    // بیمه‌گر و بیمه‌نامه‌ی قبلی
    $pi = pi_val($sp, 'شرکت\s*بیمه\s*گر\s*قبلی|بیمه\s*گر');
    if ($pi !== '') {
        if (preg_match('~^(.*?)(1[34]\d{2}/[\d/]+)~u', pi_digits($pi), $m)) { $pi = trim($m[1]); $d['prev_policy'] = $m[2]; }
        if (preg_match('~فاقد~u', $pi)) $pi = '';
        $d['prev_insurer'] = trim(preg_replace('~^(شرکت\s+)?(سهامی\s+)?~u', '', $pi));
    }
    if (empty($d['prev_policy'])) { $pp = pi_digits(pi_val($sp, 'شماره\s*بیمه\s*نامه\s*قبل')); if (preg_match('~(1[34]\d{2}/[\d/]+)~', $pp, $m)) $d['prev_policy'] = $m[1]; }

    // کدِ یکتای بیمه مرکزی (۱۱ رقم)
    $cu = pi_digits(pi_val($sp, 'کد\s*یکتا(ی)?(\s*در)?\s*بیمه\s*مرکزی|کد\s*یکتا'));
    if (preg_match('~(?<!\d)(\d{11})(?!\d)~', $cu, $m)) $d['central_no'] = $m[1];
    if (empty($d['central_no']) && !$isBody) {
        foreach ($sp as $s) {
            if (preg_match('~^(1\d{10})$~', pi_digits(trim($s['t'])), $m) && $m[1] !== '10103858742' && $m[1] !== ($d['national_id'] ?? '')) { $d['central_no'] = $m[1]; break; }
        }
    }
    $d['car_name'] = trim(implode(' ', array_filter([$d['car_system'] ?? '', $d['car_tip'] ?? ''], 'strlen')));
    foreach ($d as $k => $v) if ($v === '' || $v === null) unset($d[$k]);
    return $d;
}

// ---- الحاقیه‌ی بیمه ایران (برای api/_endorse_reader.php): همان کلیدهای er_read ----
function pi_endorsement(array $sp, $raw) {
    $raw = function_exists('pb_norm') ? pb_norm((string)$raw) : (string)$raw;
    if (!pi_is_iran($raw) || mb_strpos($raw, 'الحاقیه') === false) return null;
    usort($sp, function ($a, $b) { return abs($a['y0'] - $b['y0']) > 2 ? $a['y0'] <=> $b['y0'] : $b['x1'] <=> $a['x1']; });
    $t = pi_digits((string)$raw);
    $d = [];
    $isBody = mb_strpos($t, 'بیمه نامه بدنه') !== false;
    $rx = $isBody ? '1[34]\d{2}/\d{1,2}/\d{1,4}/\d{1,5}/\d{1,6}' : '1[34]\d{2}/\d{1,2}/\d{1,2}/\d{1,4}/\d{1,5}/\d{1,6}';
    $pn = pi_val($sp, 'شماره\s*بیمه\s*نامه');
    $d['policy_num'] = preg_match('~(' . $rx . ')~', pi_digits($pn), $m) || preg_match('~(' . $rx . ')~', $t, $m) ? $m[1] : '';
    // شماره‌ی الحاقیه: «: شماره الحاقیه4» یا ستونِ «شماره الحاقیه:تاریخ درخواست» با مقدارِ «001سعیداگاه»
    $no = '';
    if (preg_match('~شماره\s*الحاقیه\s*:?\s*(\d{1,3})(?!\d)~u', $t, $m)) $no = $m[1];
    if ($no === '') { $v = pi_val($sp, 'شماره\s*الحاقیه'); if (preg_match('~^(\d{1,3})~', pi_digits($v), $m)) $no = $m[1]; }
    $d['endorse_no'] = $no !== '' ? (string)intval($no) : '';
    $type = '';
    if (preg_match('~نوع\s*الحاقیه\s*:\s*([^\n:]+)~u', $t, $m)) $type = trim($m[1]);
    if ($type === '') $type = pi_val($sp, 'نوع\s*الحاقیه');
    $gross = 0;
    foreach (['جمع\s*کل\s*اضافی', 'مبلغ\s*کلی\s*حق\s*بیمه\s*الحاقی', 'قابل\s*پرداخت\s*به\s*بیمه\s*گر', 'جمع\s*کل\s*برگشتی', 'قابل\s*پرداخت\s*به\s*بیمه\s*گذار'] as $lab) {
        if (preg_match('~(\d{1,3}(?:,\d{3})+|\d{4,})\s*:\s*' . $lab . '|' . $lab . '\s*:?\s*(?:ریال)?\s*(\d{1,3}(?:,\d{3})+|\d{4,})~u', $t, $m)) { $gross = pi_money($m[1] ?: $m[2]); if ($gross) break; }
        $v = pi_money(pi_val($sp, $lab)); if ($v) { $gross = $v; break; }
    }
    $refund = preg_match('~برگشتی|قابل\s*پرداخت\s*به\s*بیمه\s*گذار|استرداد~u', $t) && !preg_match('~جمع\s*کل\s*اضافی~u', $t);
    if (preg_match('~فسخ|ابطال~u', $type)) $kind = 'CANCELLATION';
    elseif ($gross > 0) $kind = $refund ? 'REFUND' : 'ADDITIONAL';
    else $kind = 'CORRECTIVE';
    if ($isBody && preg_match('~نوع\s*بیمه\s*نامه\s*:?\s*(اضافی|برگشتی)~u', $t, $m) && $gross > 0) $kind = $m[1] === 'برگشتی' ? 'REFUND' : 'ADDITIONAL';
    $d['kind'] = $kind;
    $d['kind_fa'] = ['CANCELLATION' => 'فسخ (برگشتی)', 'REFUND' => 'برگشتی', 'ADDITIONAL' => 'اضافی', 'CORRECTIVE' => 'اصلاحی'][$kind];
    $d['insurance_type'] = $isBody ? 'بدنه' : 'ثالث';
    $iss = pi_val($sp, 'تاریخ\s*صدور\s*الحاقیه|تاریخ\s*صدور\s*و\s*شروع|تاریخ\s*و\s*زمان\s*صدور');
    $d['issue_date'] = pi_date($iss);
    $eff = pi_date(pi_val($sp, 'تاریخ\s*تاثیر\s*الحاقیه'));
    $d['effect_date'] = $eff !== '' ? $eff : $d['issue_date'];
    $ends = []; if (preg_match_all('~(1[34]\d{2}/\d{1,2}/\d{1,2})~', $t, $mm)) $ends = $mm[1];
    $d['new_end_date'] = $kind === 'CANCELLATION' ? $d['effect_date'] : (pi_date(pi_val($sp, 'تا\s*ساعت')) ?: '');
    $d['gross'] = $gross;
    $tax = 0;
    if (preg_match('~(\d{1,3}(?:,\d{3})+)\s*:\s*مالیات\s*اضافی|مالیات\s*اضافی\s*:?\s*ریال?\s*(\d{1,3}(?:,\d{3})+)~u', $t, $m)) $tax = pi_money($m[1] ?: $m[2]);
    if (!$tax) $tax = pi_money(pi_val($sp, 'مالیات\s*بر\s*ارزش\s*افزوده'));
    $d['tax'] = $tax; $d['levy'] = 0;
    $d['net'] = $gross && $tax && $gross > $tax ? $gross - $tax : 0;
    $cu = pi_digits(pi_val($sp, 'کد\s*یکتای\s*بیمه\s*مرکزی'));
    $d['central_no'] = preg_match('~(\d{11})~', $cu, $m) ? $m[1] : '';
    $d['insured'] = pi_name(pi_val($sp, 'نام\s*بیمه\s*گ[زذ]ار'));
    $d['description'] = $type;
    $changes = [];
    foreach (preg_split('~\n~u', $t) as $line) {
        if (preg_match('~تغییر\s*می\s*یابد|تغییر\s*می\s*کند|اضافه\s*می\s*شود|حذف\s*می\s*شود|صحیح\s*است~u', $line) && mb_strlen($line) > 12) $changes[] = trim($line);
    }
    $d['changes'] = array_slice(array_values(array_unique($changes)), 0, 30);
    $missing = [];
    foreach (['policy_num', 'kind', 'issue_date', 'gross'] as $k) if (empty($d[$k])) $missing[] = $k;
    if ($kind === 'CORRECTIVE') $missing = array_values(array_diff($missing, ['gross']));
    return ['ok' => true, 'data' => $d, 'statement' => [], 'missing' => $missing, 'insurer' => 'IRAN'];
}
