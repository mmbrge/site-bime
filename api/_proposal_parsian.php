<?php
// فایل: api/_proposal_parsian.php
// خواندنِ «پیشنهاد بیمه بدنه/ثالثِ خودرو»ِ بیمه پارسیان از روی جای هر نویسه در PDF.
// در این فرم برچسب‌ها («نام:»، «کد ملی:»، «شماره پلاک:» ...) و مقدارها در یک ردیف‌اند و مقدار سمتِ چپِ برچسب می‌آید؛
// بسته به سازنده‌ی PDF، چند برچسب در یک تکه به هم چسبیده‌اند یا برچسب و مقدار در یک تکه‌اند، پس کار در سطحِ نویسه انجام می‌شود:
// مقدارِ هر برچسب = نویسه‌های همان ردیف بینِ لبه‌ی چپِ برچسب و لبه‌ی راستِ برچسبِ بعدی (سمتِ چپ).
// خروجی هم‌کلیدِ prp_parse_proposal (api/_proposal_reader.php) تا فرم‌های ثبت و صدور بدونِ تغییر پر شوند.

function prs_is_parsian($text) {
    $t = mb_substr((string)$text, 0, 2500);
    return mb_strpos($t, 'پارسیان') !== false || stripos((string)$text, 'parsianinsurance') !== false;
}

// نویسه‌ها با کادر: [['c','x0','x1','yc','h','ln','k'], ...] (ln: شماره‌ی خط در PdfText، k: ترتیبِ منطقی)
function prs_chars(PdfText $pt, $idx) {
    $d = $pt->dict($idx);
    $o = []; $ln = 0; $k = 0;
    foreach ($d['blocks'] as $b) foreach ($b['lines'] as $l) {
        $ln++;
        foreach ($l['spans'] as $s) foreach ($s['chars'] ?? [] as $ch) {
            $c = pdf_norm_text($ch['c']);
            if ($c === '') continue;
            $bb = $ch['bbox'];
            $o[] = ['c' => $c, 'x0' => $bb[0], 'x1' => max($bb[2], $bb[0]), 'yc' => ($bb[1] + $bb[3]) / 2, 'h' => max(1, $bb[3] - $bb[1]), 'ln' => $ln, 'k' => $k++];
        }
    }
    return [$o, $d['width'], $d['height']];
}

// ردیف‌ها بر اساسِ مرکزِ عمودی
function prs_rows(array $chars) {
    usort($chars, function ($a, $b) { return $a['yc'] <=> $b['yc']; });
    $rows = []; $cur = []; $y = null;
    foreach ($chars as $c) {
        if ($y !== null && abs($c['yc'] - $y) > 3.2) { $rows[] = $cur; $cur = []; $y = null; }
        $cur[] = $c; $y = $y === null ? $c['yc'] : ($y * (count($cur) - 1) + $c['yc']) / count($cur);
    }
    if ($cur) $rows[] = $cur;
    return $rows;
}

// متنِ منطقیِ چند نویسه: هر خط جدا (به ترتیبِ منطقی)، خط‌ها از راست به چپ
function prs_join(array $chars) {
    // ترتیبِ دیداری (راست به چپ) و بعد هر تکه‌ی لاتین/عدد (با فاصله و / , . - میانش) چپ‌به‌راست:
    // «پژو» + «207i MT» => «پژو 207i MT»، «945ل42» و «1405/07/30» درست می‌مانند
    usort($chars, function ($a, $b) { return $b['x1'] <=> $a['x1'] ?: $a['k'] <=> $b['k']; });
    $tok = []; $last = null;
    foreach ($chars as $c) {
        if ($last !== null && $last['x0'] - $c['x1'] > $c['h'] * 0.28 && $c['c'] !== ' ' && $last['c'] !== ' ') $tok[] = ' ';
        $tok[] = $c['c']; $last = $c;
    }
    $isL = function ($u) { return (bool)preg_match('/^[A-Za-z0-9]+$/', $u); };
    $isN = function ($u) { return (bool)preg_match('/^[\s\/.,\-\x{066C}\x{066B}]+$/u', $u); };
    $out = []; $n = count($tok); $i = 0;
    while ($i < $n) {
        if (!$isL($tok[$i])) { $out[] = $tok[$i]; $i++; continue; }
        $j = $i; $end = $i;
        while ($j < $n && ($isL($tok[$j]) || $isN($tok[$j]))) { if ($isL($tok[$j])) $end = $j; $j++; }
        for ($q = $end; $q >= $i; $q--) $out[] = $tok[$q];
        $i = $end + 1;
    }
    return trim(preg_replace('/\s+/u', ' ', implode('', $out)));
}

// برچسب‌های فرم (به ترتیبِ اولویتِ تطبیق: بلندترها اول تا «تلفن» داخلِ «تلفن همراه» را نگیرد)
const PRS_LABELS = [
    ['mobile', 'تلفن\s*همراه'], ['phone', 'تلفن'],
    ['id_no', 'شماره\s*شناسنامه\s*/?\s*ثبت'], ['agent', 'نمایندگی\s*/?\s*کارگزار'], ['name', 'نام'],
    ['birth', 'تاریخ\s*تولد\s*/?\s*تاسیس'], ['nid', 'کد\s*ملی'],
    ['address', 'نشانی'], ['customer', 'شماره\s*مشتری'], ['postal', 'کد\s*پستی'], ['agent_code', 'کد'],
    ['kind', 'نوع\s*و\s*سیستم'], ['tip', 'تیپ'], ['capacity', 'ظرفیت'],
    ['plate_type', 'نوع\s*پلاک'], ['other', 'سایر'],
    ['plate', 'شماره\s*پلاک'], ['cyl', 'تعداد\s*سیلندر'], ['color', 'رنگ'],
    ['engine', 'شماره\s*موتور'], ['chassis', 'شماره\s*شاسی'], ['year', 'سال\s*ساخت'],
    ['trailer', 'ارزش\s*یدک[^:]{0,45}'], ['value', 'ارزش\s*خودرو'],
    ['contract', 'عنوان\s*قرارداد'],
];

// رشته‌ی دیداریِ راست‌به‌چپِ یک ردیف (برای پیدا کردنِ برچسب‌های فارسی) + نگاشتِ جای هر نویسه در رشته => نویسه
function prs_visual(array $row) {
    $vis = $row;
    usort($vis, function ($a, $b) { return $b['x1'] <=> $a['x1'] ?: $a['k'] <=> $b['k']; });
    $str = ''; $map = []; $last = null;
    foreach ($vis as $i => $c) {
        if ($last !== null && $last['x0'] - $c['x1'] > $c['h'] * 0.28 && substr($str, -1) !== ' ') $str .= ' ';
        foreach (preg_split('//u', $c['c'], -1, PREG_SPLIT_NO_EMPTY) as $u) { $map[mb_strlen($str)] = $i; $str .= $u; }
        $last = $c;
    }
    return [$vis, $str, $map];
}

// برچسب‌های یک ردیف: [[key, x0, x1, [k...]], ...] از راست به چپ
function prs_row_labels(array $row) {
    [$vis, $str, $map] = prs_visual($row);
    $used = array_fill(0, mb_strlen($str), false);
    $out = [];
    foreach (PRS_LABELS as [$key, $rx]) {
        // برچسب باید با «:» همراه باشد (پیش یا پس از آن، بسته به سازنده‌ی PDF)
        if (!preg_match_all('~(?<![\p{Arabic}])(' . $rx . ')(?![\p{Arabic}])\s*:|:\s*(' . $rx . ')(?![\p{Arabic}])~u', $str, $mm, PREG_OFFSET_CAPTURE)) continue;
        foreach ($mm[0] as $j => $m) {
            $g = ($mm[1][$j][1] ?? -1) >= 0 && $mm[1][$j][0] !== '' ? $mm[1][$j] : $mm[2][$j];
            $st = mb_strlen(substr($str, 0, $g[1])); $ln = mb_strlen($g[0]);
            $free = true; for ($q = $st; $q < $st + $ln; $q++) if ($used[$q]) { $free = false; break; }
            if (!$free) continue;
            // «:» ِ خودِ برچسب هم مصرف می‌شود
            $ms = mb_strlen(substr($str, 0, $m[1])); $ml = mb_strlen($m[0]);
            for ($q = $ms; $q < $ms + $ml; $q++) $used[$q] = true;
            $xs = [];
            for ($q = $ms; $q < $ms + $ml; $q++) if (isset($map[$q])) $xs[] = $vis[$map[$q]];
            $out[] = [$key, min(array_map(function ($c) { return $c['x0']; }, $xs)), max(array_map(function ($c) { return $c['x1']; }, $xs)), array_map(function ($c) { return $c['k']; }, $xs)];
        }
    }
    usort($out, function ($a, $b) { return $b[2] <=> $a[2]; });
    return $out;
}

function prs_parse(PdfText $pt, $idx, $text) {
    [$chars, $w] = prs_chars($pt, $idx);
    $t = pb_norm($text);
    $vals = [];
    foreach (prs_rows($chars) as $row) {
        $labs = prs_row_labels($row);
        if (!$labs) continue;
        $labK = [];
        foreach ($labs as $l) foreach ($l[3] as $k) $labK[$k] = true;
        foreach ($labs as $i => $l) {
            $left = isset($labs[$i + 1]) ? $labs[$i + 1][2] : -1;
            $sel = array_values(array_filter($row, function ($c) use ($l, $left, $labK) {
                $cx = ($c['x0'] + $c['x1']) / 2;
                return !isset($labK[$c['k']]) && $cx < $l[1] + 0.5 && $cx > $left - 0.5;
            }));
            $v = $sel ? trim(prs_join($sel), " :\t") : '';
            if ($v !== '' && !isset($vals[$l[0]])) $vals[$l[0]] = $v;
        }
    }
    $d = ['ins_company' => 'پارسیان'];
    $head = mb_substr($t, 0, 300);
    $d['ins_type'] = mb_strpos($head, 'بدنه') !== false ? 'بدنه' : (mb_strpos($head, 'ثالث') !== false ? 'ثالث' : '');
    $dig = function ($v) { return preg_replace('/\D/', '', (string)$v); };
    if (!empty($vals['name'])) {
        $nm = trim(preg_replace('/\s*کد\s*:?\s*\d*\s*$/u', '', preg_replace('/\s*\d{5,}\s*/u', ' ', $vals['name'])));
        if ($nm !== '' && preg_match('/\p{Arabic}/u', $nm)) [$d['insured_name'], $d['insured_gender']] = prp_honor_split($nm);
        if (preg_match('/(\d{5,})/', $vals['name'], $m)) $d['customer_no'] = $m[1];
    }
    if (preg_match('/^\d{10}$/', $dig($vals['nid'] ?? ''))) $d['national_id'] = $dig($vals['nid']);
    if (!empty($vals['id_no']) && preg_match('/(\d{1,12})/', $vals['id_no'], $m)) $d['id_no'] = $m[1];
    if (!empty($vals['birth'])) { $ds = pb_dates_in($vals['birth']); $d['birth_date'] = $ds ? $ds[0] : (preg_match('/\b(1[34]\d{2})\b/', $vals['birth'], $m) ? $m[1] : ''); }
    if (!empty($vals['address']) && preg_match('/\p{Arabic}{2}/u', $vals['address'])) $d['address'] = prp_clean($vals['address']);
    if (empty($d['customer_no']) && preg_match('/^\d{5,}$/', $dig($vals['customer'] ?? ''))) $d['customer_no'] = $dig($vals['customer']);
    if (preg_match('/^\d{10}$/', $dig($vals['postal'] ?? ''))) $d['postal_code'] = $dig($vals['postal']);
    foreach (['mobile', 'phone'] as $k) if (empty($d['phone']) && !empty($vals[$k])) { $ph = pb_norm_phone($vals[$k]); if ($ph !== '') $d['phone'] = $ph; }
    if (!empty($vals['kind'])) $d['car_kind'] = prp_clean($vals['kind']);
    if (!empty($vals['tip'])) { $d['car_tip'] = prp_clean($vals['tip']); $d['car_name'] = $d['car_tip']; }
    if (!empty($vals['capacity']) && preg_match('/(\d{1,3})/', $vals['capacity'], $m)) $d['capacity'] = $m[1];
    elseif (preg_match('/(?<!\d)(\d{1,2})\s*نفر|نفر\s*(\d{1,2})(?!\d)/u', $t, $m)) $d['capacity'] = $m[1] !== '' ? $m[1] : $m[2];
    if (!empty($vals['plate']) && preg_match('/\d/', $vals['plate'])) { $p = pb_parse_plate($vals['plate']); if ($p !== '') $d['plate'] = $p; }
    if (!empty($vals['cyl']) && preg_match('/^\d{1,2}$/', $dig($vals['cyl']))) $d['cylinders'] = $dig($vals['cyl']);
    if (!empty($vals['color']) && preg_match('/\p{Arabic}/u', $vals['color'])) $d['color'] = prp_clean(preg_replace('/\d+/', '', $vals['color']));
    $an = function ($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$v)); };
    if (!empty($vals['engine']) && strlen($an($vals['engine'])) >= 5) $d['engine_no'] = $an($vals['engine']);
    if (!empty($vals['chassis']) && strlen($an($vals['chassis'])) >= 5) { $d['vin'] = $an($vals['chassis']); $d['chassis_no'] = $d['vin']; }
    if (!empty($vals['year']) && preg_match('/\b(1[34]\d{2}|19\d{2}|20\d{2})\b/', $vals['year'], $m)) $d['model_year'] = $m[1];
    if (!empty($vals['value'])) { $v = $dig($vals['value']); if (strlen($v) >= 6) $d['car_value'] = $v; }
    if (!empty($vals['contract'])) $d['contract_title'] = prp_clean($vals['contract']);
    // مدت و تاریخ‌ها: «خودرو با مشخصات زیر به مدت 365 روز از ساعت 24 مورخ 1405/07/30 تا ساعت 24 مورخ 1406/07/30»
    $ds = pb_dates_in(mb_substr($t, 0, 900));
    if ($ds) { $d['start_date'] = $ds[0]; if (isset($ds[1])) $d['end_date'] = $ds[1]; }
    if (!empty($d['start_date']) && !empty($d['end_date']) && function_exists('jalali_to_gregorian_ts')) {
        $a = array_map('intval', explode('/', $d['start_date'])); $b = array_map('intval', explode('/', $d['end_date']));
        $days = (int)round((jalali_to_gregorian_ts($b[0], $b[1], $b[2]) - jalali_to_gregorian_ts($a[0], $a[1], $a[2])) / 86400);
        if ($days > 0 && $days <= 400) $d['duration_days'] = (string)$days;
    }
    // ذینفع: «... لطفا نام مالک خودرو که منبعد در این بیمه نامه ذینفع خواهد شد را ذکر کنید : واسپاری پایا اعتبار»
    $ben = prs_after($chars, 'ذکر\s*کنید');
    if ($ben !== '' && preg_match('/\p{Arabic}{2}/u', $ben)) [$d['beneficiary'], $d['beneficiary_gender']] = prp_honor_split($ben);
    $d = array_filter($d, function ($v) { return $v !== '' && $v !== null; });
    $missing = [];
    foreach ([['insured_name', 'نام بیمه‌گزار'], ['national_id', 'کد ملی'], ['vin', 'شماره شاسی'], ['car_value', 'ارزش خودرو']] as [$k, $fa]) if (empty($d[$k])) $missing[] = $fa;
    return [$d, $missing];
}

// مقدارِ همان ردیف سمتِ چپِ یک عبارت (برای جمله‌ها، نه برچسب‌های «:»دار)
function prs_after(array $chars, $rx) {
    foreach (prs_rows($chars) as $row) {
        [$vis, $str, $map] = prs_visual($row);
        if (!preg_match('~' . $rx . '\s*:?~u', $str, $m, PREG_OFFSET_CAPTURE)) continue;
        $end = mb_strlen(substr($str, 0, $m[0][1] + strlen($m[0][0]))) - 1;
        $edge = $vis[$map[$end] ?? 0]['x0'];
        $sel = array_values(array_filter($row, function ($c) use ($edge) { return ($c['x0'] + $c['x1']) / 2 < $edge - 0.5; }));
        // تا جای خالیِ بزرگ (ستونِ بعدی) فقط نزدیک‌ترین گروه
        usort($sel, function ($a, $b) { return $b['x1'] <=> $a['x1']; });
        $grp = []; $last = null;
        foreach ($sel as $c) { if ($last !== null && $last['x0'] - $c['x1'] > 40) break; $grp[] = $c; $last = $c; }
        return $grp ? trim(prs_join($grp), " :") : '';
    }
    return '';
}
