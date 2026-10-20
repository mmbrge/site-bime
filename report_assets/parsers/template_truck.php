<?php
// پارسرِ خودروهای سنگین (کامیون، کشنده، یدک) — نسخه‌ی PHPِ template_truck.py (پایه: template_car.php)
require_once dirname(__DIR__, 2) . '/api/_policy_reader.php';

return function ($raw_text, $checkboxes = []) {
    static $car = null;
    if ($car === null) { try { $car = include __DIR__ . '/template_car.php'; } catch (Throwable $e) { $car = false; } }
    $out = $car ? ($car($raw_text, $checkboxes) ?: []) : [];

    $t = strtr((string)$raw_text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                                   '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                                   '،' => ',', 'ﻉ' => 'ع', 'ي' => 'ی', 'ك' => 'ک']);
    $t = pb_sub('\(cid:\d+\)', ' ', $t);
    $t = pdft_nfkc($t);

    if (pb_m('(\d{2})\s*ایران\s*(\d{2})\s*([a-zA-Zآ-یع])\s*(\d{3})', $t, $m)) {
        $out['plate_part3'] = $m[1]; $out['plate_part1'] = $m[2]; $out['plate_letter'] = $m[3]; $out['plate_part2'] = $m[4];
    } elseif (pb_m('(\d{2})\s*([a-zA-Zآ-یع])\s*(\d{3})\s*ایران\s*(\d{2})', $t, $m)) {
        $out['plate_part1'] = $m[1]; $out['plate_letter'] = $m[2]; $out['plate_part2'] = $m[3]; $out['plate_part3'] = $m[4];
    }

    $vc = array_values(array_filter(array_map(function ($m) { return $m[1]; }, pb_all('(?<![A-Za-z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Za-z0-9])', $t, 'i')), function ($c) { return pb_m('[A-Za-z]', $c) && pb_m('[0-9]', $c); }));
    if ($vc) {
        $out['shasi'] = strtoupper($vc[0]);
        $t = str_replace($vc[0], ' ', $t);
    }

    $ec = array_map(function ($m) { return $m[1]; }, pb_all('(?<![A-Za-z0-9])([A-Z0-9\-]{6,30})(?![A-Za-z0-9])', $t, 'i'));
    $valid = array_values(array_filter($ec, function ($c) { return !(ctype_digit($c) && in_array(strlen($c), [10, 11], true) && $c[0] === '0') && !pb_m('^(13|14)\d{2}$', $c); }));
    if ($valid) {
        $alpha = array_values(array_filter($valid, function ($c) { return pb_m('[A-Za-z]', $c); }));
        $pool = $alpha ?: $valid;
        $best = $pool[0]; foreach ($pool as $c) if (strlen($c) > strlen($best)) $best = $c;
        $out['motor'] = strtoupper($best);
    }

    if (pb_m('\b(13[4-9]\d|14[0-1]\d)\b', $t, $m)) $out['sal_sakht'] = $m[1];
    if (pb_m('(سفید|مشکی|قرمز|آبی|نقره\s*ای|نوک\s*مدادی|خاکستری|سبز|زرد|نارنجی|قهوه\s*ای|کرم|بژ|زرشکی|سربی|دلفینی)(?:\s*(روغنی|متالیک|صدفی))?', $t, $m)) $out['rang'] = pb_ustrip($m[0]);
    if (pb_m('(تریلرکش|کشنده|کامیون|کامیونت|وانت|مینی\s*بوس|اتوبوس|تراکتور|کمپرسی|بارکش)', $t, $m)) $out['noecar'] = pb_ustrip($m[1]);
    if (pb_m('(بارکش|مسافربر|شخصی|عمومی|کشاورزی|راهسازی|صنعتی|آموزشی)', $t, $m)) $out['mordes'] = pb_ustrip($m[1]);
    if (pb_m('(\d{1,3})\s*تن', $t, $m)) $out['zarfiat'] = $m[1] . ' تن';
    if (pb_m('سیلندر\s*[:\s]*(\d+)|(\d+)\s*(?:تعداد\s*)?سیلندر', $t, $m)) $out['cylinder_count'] = pb_g($m, 1) ?? pb_g($m, 2);
    if (pb_m('(پیلسان|اسکانیا|ولوو|بنز|فاو|سیبا|دانگ\s*فنگ|هوو|الوند|آمیکو|ایسوزو|کاویان|هیوندای|رنو|شاکمان|دیما|داف|ماک|ایویکو|فوتون|جک|کاما)[ \t]*([A-Za-z0-9\-]+)?', $t, $m)) $out['brand'] = pb_ustrip($m[0]);

    $vals = [];
    $clean = str_replace([',', '،', '.'], '', $t);
    foreach (pb_all('\d{7,15}', $clean) as $m) if (substr($m[0], -3) === '000') $vals[] = (int)$m[0];
    if ($vals) $out['arzesh'] = (string)max($vals);

    if (pb_m('(?!0\d)(\d{10,11})(?!\d)', $t, $m)) $out['national_id'] = $m[1];
    if (pb_m('(09\d{9}|0\d{7,10})(?!\d)', $t, $m)) $out['phone_bimeg'] = $m[1];
    if (pb_m('([^\n:]+?)\s*:\s*کد\s*ملی', $t, $nm) || pb_m('(?:نام\s*و\s*نام\s*خانوادگی|اینجانب)\s*[:\s]*([^\n\d]+?)(?:\n|\r|کد|با|:|ملی)', $t, $nm)) {
        $cl = pb_ustrip(str_replace(['کد ملی'], '', str_replace(':', '', $nm[1])));
        if (mb_strlen($cl) > 2) $out['name'] = pb_ustrip(pb_sub('(?:نام|متقاضی|بیمه|گزار|مشخصات|اینجانب)', '', $cl));
    }
    if (pb_m('(استان\s+.*?)(?:کد\s*پستی|تلفن|شغل|نام\s*و\s*نام)', $t, $m, 's')) {
        $addr = pb_ustrip(str_replace(["\n", '-'], ' ', $m[1]));
        $out['addres_bimeg'] = pb_sub('\s+', ' ', $addr);
    }

    foreach ([['shasi', 'chassis_no', 'shasisa'], ['motor', 'engine_no', 'motorsa'], ['sal_sakht', 'year', 'sal_sakhtsa'], ['rang', 'color', 'rangsa'],
              ['mordes', 'usage', 'mordessa'], ['noecar', 'vehicle_type', 'noecarsa'], ['arzesh', 'insured_value', 'arzeshsa'], ['brand', 'tip'], ['zarfiat', 'capacity']] as $group) {
        $val = '';
        foreach ($group as $k) if (!empty($out[$k])) { $val = $out[$k]; break; }
        if ($val !== '') foreach ($group as $k) $out[$k] = $val;
    }
    foreach (['lastikka' => 'وضعیت\s*لاستیک\s*[:：]?\s*([^\n]+)', 'yadaknk' => 'نوع\s*یدک\s*[:：]?\s*([^\n]+)', 'pelakk' => 'شماره\s*شهربانی\s*[:：]?\s*([^\n]+)',
              'salsayk' => 'سال\s*ساخت\s*یدک\s*[:：]?\s*(\d{4})', 'rangyk' => 'رنگ\s*یدک\s*[:：]?\s*([^\n]+)', 'termoshk' => 'ترموکینگ\s*[:：]?\s*([^\n]+)',
              'shasiyk' => 'شاسی\s*یدک\s*[:：]?\s*([A-Za-z0-9-]+)', 'arzeshyakchal' => 'ارزش\s*یخچال[^\d]*([\d,]+)', 'arzeshyadak' => 'ارزش\s*یدک[^\d]*([\d,]+)'] as $key => $pat) {
        if (pb_m($pat, $t, $m, 'i')) $out[$key] = strpos($key, 'arzesh') !== false ? str_replace(',', '', pb_ustrip($m[1])) : pb_ustrip($m[1]);
    }
    return array_filter($out, function ($v) { return $v !== '' && $v !== null; });
};
