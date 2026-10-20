<?php
// پارسرِ پیشنهادِ بیمه بدنه‌ی پارسیان — نسخه‌ی PHPِ template_parsian.py (با چک‌باکس‌های PDF)
require_once dirname(__DIR__, 2) . '/api/_policy_reader.php';

return function ($raw_text, $checkboxes = []) {
    $clean = function ($s) {
        if ($s === null || $s === '') return '';
        $s = strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                                '،' => ',', 'ي' => 'ی', 'ك' => 'ک', 'ͯ' => 'ی', 'ͬ' => 'ی', 'ͷ' => 'ک', 'ͺ' => 'ک', 'ͽ' => 'گ', 'Ĺ' => '', 'ﻼ' => 'لا', 'ﻻ' => 'لا']);
        $s = pb_sub('\(cid:\d+\)', ' ', $s);
        return pdft_nfkc($s);
    };
    $out = ['document_type' => 'پیشنهاد بیمه بدنه پارسیان', 'name' => '', 'national_id' => '', 'phone_number' => '', 'addres_bimeg' => 'تهران',
            'inspector' => 'مینو آقاجانی', 'start_date' => '', 'end_date' => '', 'policy_number' => 'پیشنهاد (فاقد شماره)', 'plate_formatted' => '',
            'vehicle_type' => '', 'manufacture_year' => '', 'color' => '', 'chassis_no' => '', 'engine_no' => '', 'cylinder_count' => '4', 'usage' => '', 'insured_value' => ''];
    $t = $clean($raw_text);

    $dates = array_map(function ($m) { return $m[0]; }, pb_all('\d{4}/\d{2}/\d{2}', $t));
    if ($dates) { $dates = array_values(array_unique($dates)); sort($dates, SORT_STRING); $out['start_date'] = $dates[0]; if (count($dates) > 1) $out['end_date'] = end($dates); }

    if (pb_m('(\d{2,3})\s*([a-zA-Zآ-یهعﻉعون])\s*(\d{2,3})\s*(?:ایران|ايران)\s*(\d{2})', $t, $mp)) {
        [$pA, $letter, $pB, $pIran] = [$mp[1], $mp[2], $mp[3], $mp[4]];
        $p1 = (strlen($pA) === 3 && strlen($pB) === 2) ? $pB : $pA;
        $p2 = (strlen($pA) === 3 && strlen($pB) === 2) ? $pA : $pB;
        $out['plate_formatted'] = $pIran . 'ایران - ' . $p1 . ' ' . $letter . ' ' . $p2;
        $out['plate_part1'] = $p1; $out['plate_letter'] = $letter; $out['plate_part2'] = $p2; $out['plate_part3'] = $pIran;
    }

    $vc = array_values(array_filter(array_map(function ($m) { return $m[1]; }, pb_all('(?<![A-Za-z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Za-z0-9])', $t, 'i')), function ($c) { return pb_m('[A-Za-z]', $c) && pb_m('[0-9]', $c); }));
    if ($vc) { $out['chassis_no'] = strtoupper($vc[0]); $out['vin'] = $out['chassis_no']; }

    if (pb_m('شماره\s*موتور\s*:?\s*([A-Za-z0-9][A-Za-z0-9\-]{4,30})(?![A-Za-z0-9])', $t, $me) && pb_m('\d', $me[1]) && strtoupper($me[1]) !== ($out['chassis_no'] ?? null)) {
        $out['engine_no'] = strtoupper($me[1]);
    } else {
        $src = ($out['chassis_no'] ?? '') !== '' ? str_replace($out['chassis_no'], '', $t) : $t;
        $ec = array_map(function ($m) { return $m[1]; }, pb_all('(?<![A-Za-z0-9])([A-Z0-9\-]{6,30})(?![A-Za-z0-9])', $src, 'i'));
        $valid = array_values(array_filter($ec, function ($c) { return !(ctype_digit($c) && in_array(strlen($c), [10, 11], true) && $c[0] === '0') && !pb_m('^(13|14)\d{2}$', $c); }));
        $mixed = array_values(array_filter($valid, function ($c) { return pb_m('[A-Za-z]', $c) && pb_m('\d', $c); }));
        $pool = $mixed ?: $valid;
        if ($pool) { $best = $pool[0]; foreach ($pool as $c) if (strlen($c) > strlen($best)) $best = $c; $out['engine_no'] = strtoupper($best); }
    }

    if (pb_m('(?<!\d)1[34]\d{2}\s*\n\s*(\d{10})(?!\d)', $t, $mn) || pb_m('(?<!\d)(\d{10})(?!\d)', $t, $mn)) $out['national_id'] = $mn[1];
    if (pb_m('(?:سرکار\s*خانم|جناب\s*آقای)\s+([\s\S]+?)\s*کد', $t, $mm)) $out['name'] = pb_ustrip(pb_sub('\s+', ' ', $mm[1]));
    elseif (pb_m('نام\s*[:\s]*(?:سرکار\s*خانم|جناب\s*آقای)?\s*([آ-یa-zA-Z\s]{3,40}?)\s*(?:کد|شماره\s*شناسنامه)', $t, $mm)) $out['name'] = pb_ustrip($mm[1]);
    if (pb_m('(09\d{9})', $t, $mph)) $out['phone_number'] = $mph[1];

    if ($out['national_id'] !== '') {
        if (pb_m(preg_quote($out['national_id'], '~') . '\s*\n([\s\S]*?)(?=\b\d{10}\b|\b09\d{9}\b|شماره مشتری)', $t, $ma)) {
            $addr = pb_ustrip(pb_sub('\s+', ' ', $ma[1]));
            $addr = pb_ustrip(pb_sub('[,،\-]+$', '', $addr));
            if (mb_strlen($addr) > 3) $out['addres_bimeg'] = $addr;
        }
    }

    $system = '';
    if (pb_m('نوع\s*و\s*سیستم\s*[:\s]*([^\n:]+)', $t, $ms)) $system = pb_ustrip($ms[1]);
    $out['system'] = $system;
    $tip = ''; $tipEn = '';
    if (pb_m('تیپ\s*:?\s*([\s\S]{1,120}?)(?=تعداد\s*سیلندر|رنگ\s*:|ظرفیت|شماره|سال\s*ساخت|$)', $t, $mt)) {
        $fa = [];
        foreach (explode("\n", $mt[1]) as $line) {
            $line = pb_ustrip($line);
            if ($line === '') continue;
            if (pb_m('[A-Za-z]', $line) && !pb_m('[\x{0600}-\x{06FF}]', $line)) { if ($tipEn === '') $tipEn = $line; continue; }
            $line = pb_sub('^\)(.+)\($', '($1)', $line);
            $fa[] = $line;
        }
        $tip = pb_ustrip(pb_sub('\s+', ' ', implode(' ', $fa)));
    }
    $out['tip'] = $tip;
    if ($tipEn !== '') $out['tip_en'] = $tipEn;
    $out['vehicle_type'] = ($tip !== '' && $system !== '' && mb_strpos($tip, $system) === false) ? pb_ustrip($system . ' ' . $tip) : ($tip !== '' ? $tip : $system);

    $boxes = [];
    foreach ((array)$checkboxes as $b) if (!empty($b['checked'])) $boxes[] = ['label' => $clean($b['label'] ?? ''), 'line' => $clean($b['line'] ?? '')];
    $answer = function ($linePat, $choices) use ($boxes) {
        foreach ($boxes as $b) {
            if (!pb_m($linePat, $b['line'])) continue;
            $label = $b['label'];
            if ($choices) { foreach ($choices as $c) if (mb_strpos($label, $c) !== false) return $c; continue; }
            if (mb_substr($label, -mb_strlen('نداشته است')) === 'نداشته است') return 'نداشته است';
            if (mb_substr($label, -mb_strlen('داشته است')) === 'داشته است') return 'داشته است';
            $label = trim(pb_ustrip(pb_sub('^.*(?:نوع\s*پلاک|مورد\s*استفاده[^:]*|محل\s*پارک\s*خودرو|نحوه\s*پرداخت(?:\s*حق\s*بیمه)?)\s*:?\s*', '', $label)), ' :');
            $first = pb_ustrip(preg_split(pb_rx('\s*\)'), $label)[0]);
            return $first !== '' ? $first : $label;
        }
        return '';
    };
    $plateType = $answer('نوع\s*پلاک', ['شخصی', 'عمومی', 'دولتی', 'نظامی']);
    if ($plateType !== '') $out['plate_type'] = $plateType;
    $usage = $answer('مورد\s*استفاده', null);
    $out['usage'] = $usage !== '' ? $usage : ($plateType !== '' ? $plateType : ($system === 'سواری' ? 'شخصی' : $system));
    foreach ([['is_owner', 'مالک\s*خودرو\s*هستید'], ['prior_accident', 'سابقه\s*تصادف'], ['drives_self', 'خودتان\s*انجام'], ['other_drivers', 'افراد\s*دیگری'],
              ['prev_body_insurance', 'قبلا\s*بیمه\s*نامه\s*بدنه'], ['body_claims', 'بدنه\s*خسارت'], ['third_claims', 'شخص\s*ثالث\s*خسارت'],
              ['parking', 'محل\s*پارک'], ['payment_method', 'نحوه\s*پرداخت']] as [$key, $pat]) {
        $ans = $answer($pat, null);
        if ($ans !== '') $out[$key] = $ans;
    }

    if (pb_m('تعداد\s*سیلندر\s*:\s*رنگ\s*:\s*\n*(\d+)\s*([^\n\d]+)', $t, $mc)) { $out['cylinder_count'] = pb_ustrip($mc[1]); $out['color'] = pb_ustrip($mc[2]); }
    else {
        if (pb_m('(سفید|مشکی|قرمز|آبی|نقره\s*ای|نوک\s*مدادی|خاکستری|سبز|زرد)(?:\s*(روغنی|متالیک|صدفی))?', $t, $mcol)) $out['color'] = pb_ustrip($mcol[0]);
        if (pb_m('سیلندر(?:[^0-9]{0,20})?(\d+)', $t, $mcy)) $out['cylinder_count'] = $mcy[1];
    }
    if (pb_m('(\d{1,3})\s*نفر', $t, $mcap)) $out['capacity'] = $mcap[1];
    if (pb_m('سال\s*ساخت\s*:\s*(13[4-9]\d|14[0-1]\d)', $t, $my)) $out['manufacture_year'] = $my[1];
    if ($out['national_id'] !== '') {
        $q = preg_quote($out['national_id'], '~');
        if (pb_m('(1[34]\d{2})\s*\n\s*' . $q, $t, $mb)) $out['birth_year'] = $mb[1];
        if (pb_m($q . '\s*\n\s*(\d{10})(?!\d)', $t, $mpo)) $out['postal_code'] = $mpo[1];
    }
    if (pb_m('ارزش\s*خودرو:\s*سایر:\s*([\d,]{7,})', $t, $mv) || pb_m('([\d,]{7,})\s*ریال', $t, $mv)) $out['insured_value'] = pb_ustrip(str_replace(',', '', $mv[1]));

    $out['shasi'] = $out['chassis_no']; $out['motor'] = $out['engine_no']; $out['sal_sakht'] = $out['manufacture_year']; $out['year'] = $out['manufacture_year'];
    $out['rang'] = $out['color']; $out['mordes'] = $out['usage']; $out['noecar'] = $out['vehicle_type']; $out['arzesh'] = $out['insured_value'];
    $out['zarfiat'] = $out['capacity'] ?? '';
    foreach ([['phone_bimeg', 'phone_number', 'phone'], ['addres_bimeg', 'address'], ['national_id', 'kod_meli'], ['name', 'bime_gozar'], ['inspector', 'بازدید کننده']] as $group) {
        $val = '';
        foreach ($group as $k) if (!empty($out[$k])) { $val = $out[$k]; break; }
        if ($val !== '') foreach ($group as $k) $out[$k] = $val;
    }
    return $out;
};
