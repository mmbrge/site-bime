<?php
// پارسرِ پیشنهادِ پاسارگاد (سواری / وانت / استیشن) — نسخه‌ی PHPِ template_car.py
// هر فایلِ پارسر یک تابع برمی‌گرداند: function (string $raw_text, array $checkboxes): array
require_once dirname(__DIR__, 2) . '/api/_policy_reader.php';

return function ($raw_text, $checkboxes = []) {
    $t = pdft_nfkc((string)$raw_text);
    $t = strtr($t, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    $t = strtr($t, ['ي' => 'ی', 'ك' => 'ک', 'ﺹ' => 'ص', '،' => ',']);
    $out = [];

    if (pb_m('(\d{2})\s*ایران\s*(\d{2})\s*([آ-یA-Za-zصع])\s*(\d{3})', $t, $m1)) {
        $out += ['plate_part1' => $m1[2], 'plate_letter' => $m1[3], 'plate_part2' => $m1[4], 'plate_part3' => $m1[1]];
    } elseif (pb_m('(\d{3})\s*([آ-یA-Za-zصع])\s*(\d{2})\s*ایران\s*(\d{2})', $t, $m2)) {
        $out += ['plate_part1' => $m2[3], 'plate_letter' => $m2[2], 'plate_part2' => $m2[1], 'plate_part3' => $m2[4]];
    }

    $cands = [];
    foreach (pb_all('\b[A-Za-z0-9\-]{6,30}\b', $t) as $m) {
        $w = $m[0];
        if (pb_m('^\d{10,11}$', $w) || pb_m('^(13|14)\d{2}$', $w) || pb_m('^\d{4}$', $w)) continue;
        $cands[] = strtoupper($w);
    }
    $cands = array_values(array_unique($cands));
    $chassis = null; $motor = null;
    foreach ($cands as $c) if (strlen($c) === 17) { $chassis = $c; break; }
    if ($chassis === null) foreach ($cands as $c) if (strlen($c) >= 13 && strlen($c) <= 17) { $chassis = $c; break; }
    $rem = array_values(array_filter($cands, function ($c) use ($chassis) { return $c !== $chassis; }));
    if ($rem) { $rem = pb_sort($rem, function ($c) { return -strlen($c); }); $motor = $rem[0]; }
    if ($chassis !== null) { $out['shasi'] = $chassis; $out['chassis_no'] = $chassis; }
    if ($motor !== null) { $out['motor'] = $motor; $out['engine_no'] = $motor; }

    $year = null;
    if (!empty($out['motor']) && pb_m('\b(13[4-9]\d|14[0-1]\d)\s*\n\s*' . preg_quote($out['motor'], '~'), $t, $ym, 'i')) $year = $ym[1];
    if ($year === null && pb_m('\b(13[4-9]\d|14[0-1]\d)\b(?!\s*/)', $t, $ym)) $year = $ym[1];
    if ($year !== null) { $out['sal_sakht'] = $year; $out['year'] = $year; }

    if (pb_m('(سفید|مشکی|خاکستری|نقره\s*ای|نوک\s*مدادی|قرمز|آبی|سرمه\s*ای|قهوه\s*ای|زرد|سبز)\s*(روغنی|متالیک|صدفی)?', $t, $m)) { $out['rang'] = $out['color'] = pb_ustrip($m[0]); }
    if (pb_m('(شخصی|آژانس|کرایه|تاکسی|آموزش|دولتی|عمومی|بارکش)', $t, $m)) { $out['mordes'] = $out['usage'] = pb_ustrip($m[0]); }

    $val = '';
    if (pb_m('(سواری|وانت|استیشن|ون|پیکاپ|شاسی\s*بلند)[ \t]*\n[ \t]*([^\n]{2,40})', $t, $mk) && !pb_m('(نوع|سیستم|تیپ|ظرفیت|شماره)', $mk[2])) {
        $model = pb_ustrip($mk[2]);
        $toks = array_map(function ($m) { return $m[0]; }, pb_all('[A-Za-z0-9\-]+|[^\sA-Za-z0-9\-]+', $model));
        $fa = array_values(array_filter($toks, function ($x) { return !pb_m('^[A-Za-z0-9\-]+$', $x); }));
        $en = array_values(array_filter($toks, function ($x) { return pb_m('^[A-Za-z0-9\-]+$', $x); }));
        $en = array_values(array_filter($en, function ($x) use ($out) { return strlen($x) < 8 && !in_array(strtoupper($x), [$out['shasi'] ?? '', $out['motor'] ?? ''], true); }));
        $val = pb_ustrip(implode(' ', array_merge([$mk[1]], $fa, $en)));
    }
    if ($val === '') {
        $brands = '(پژو|پراید|سمند|تیبا|دنا|تارا|شاهین|کوییک|ساینا|رانا|ام\s*وی\s*ام|MVM|چری|جک|لیفان|تویوتا|هیوندای|کیا|رنو|بنز|بی\s*ام\s*و|نیسان|پارس|TU5|LX)';
        if (pb_m('(سواری|وانت)?[ \t]*' . $brands . '[A-Za-z0-9 \tآ-ی]{0,15}', $t, $mt, 'i')) {
            $val = pb_ustrip($mt[0]);
            $val = pb_ustrip(pb_sub('(رنگ|مدل|سال|ظرفیت|سیستم|تیپ|نوع|وسیله|شماره).*', '', $val));
        }
    }
    if ($val !== '') { $out['noecar'] = $out['vehicle_type'] = pb_sub('\s+', ' ', $val); }

    if (pb_m('(?:سیلندر|تعداد\s*سیلندر)\s*[:：\n]?\s*(\d)', $t, $m)) $out['cylinder_count'] = $m[1];

    $vals = [];
    $clean = str_replace([',', '،', '.'], '', $t);
    foreach (pb_all('\d{7,15}', $clean) as $m) if (substr($m[0], -3) === '000') $vals[] = (int)$m[0];
    if ($vals) { $out['arzesh'] = $out['insured_value'] = (string)max($vals); }

    $ids11 = pb_all('\b(10\d{9}|14\d{9})\b', $t);
    if ($ids11) $out['national_id'] = $ids11[0][1];
    else { $ids10 = pb_all('\b([1-9]\d{9})\b', $t); if ($ids10) $out['national_id'] = $ids10[0][1]; }

    if (pb_m('\b(0[1-9]\d{9})\b', $t, $m)) $out['phone_bimeg'] = $m[1];

    $am = null;
    if (preg_match(pb_rx('(استان\s+[آ-ی\s،,-]+(?:\s*پلاک\s*\d+|\s*طبقه\s*\d+)?)(?=\s*کد\s*پستی|\s*تلفن|\n)'), $t, $am, PREG_OFFSET_CAPTURE) !== 1)
        if (preg_match(pb_rx('(استان\s+.*?)(?=\s*کد\s*پستی|\s*تلفن|021|\n\s*\n)'), $t, $am, PREG_OFFSET_CAPTURE) !== 1) $am = null;
    if ($am) {
        $addr = pb_trim(pb_ustrip(str_replace('کد پستی', '', $am[1][0])), ' ،,-:');
        $startB = $am[1][1];
        $ls = strrpos(substr($t, 0, $startB), "\n");
        $ls = $ls === false ? 0 : $ls + 1;
        $prefix = substr($t, $ls, $startB - $ls);
        $tail = array_map(function ($m) { return $m[0]; }, pb_all('\d+|[آ-ی]+', $prefix));
        if ($tail && count($tail) <= 8) {
            $rev = array_reverse($tail);
            $tailTxt = $rev[0] . (count($rev) > 1 ? ', ' . implode(' ', array_slice($rev, 1)) : '');
            $addr = pb_ustrip($addr . ' ' . $tailTxt);
        }
        $out['addres_bimeg'] = pb_sub('\s*,\s*', '، ', $addr);
    }

    if (pb_m('نام\s*و\s*نام\s*خانوادگی\s*[:\n]*([آ-ی\s]+?)(?=\s*کد\s*ملی)', $t, $nm) || pb_m('([آ-ی\s]+?)(?:[:\n]*کد\s*ملی)', $t, $nm)) {
        $n = pb_ustrip($nm[1]);
        $n = pb_ustrip(pb_sub('(مشخصات\s*متقاضی|ذینفع|نشانی|تلفن|شغل|بیمه\s*گزار|نام\s*و\s*نام\s*خانوادگی)', '', $n));
        if (mb_strlen($n) > 3) $out['name'] = $n;
    }
    return array_filter($out, function ($v) { return $v !== '' && $v !== null; });
};
