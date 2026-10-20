<?php
// فایل: api/_proposal_reader.php
// خواندنِ «پرسش‌نامه و پیشنهادِ بیمه‌نامه» و «پیش‌نویسِ بیمه‌نامه»ِ پاسارگاد با PHP (جایگزینِ api/py/proposal_reader.py)
//   prp_read($absPdf) => ['ok'=>true, 'kind'=>'proposal|draft|policy', 'data'=>[...], 'missing'=>[...]] | ['ok'=>false, 'error'=>...]
require_once __DIR__ . '/_policy_reader.php';

function prp_honor_split($name) {
    $name = trim(pb_ustrip(pb_sub('\s+', ' ', (string)$name)), ' -:');
    $g = '';
    if (pb_m('^(جناب\s*آقای|جناب\s*آقا|آقای|سرکار\s*خانم|خانم|شرکت)\s*', $name, $m)) {
        $h = $m[1];
        $g = mb_strpos($h, 'خانم') !== false ? 'F' : (mb_strpos($h, 'آقا') !== false ? 'M' : '');
        $name = pb_ustrip(substr($name, strlen($m[0])));
    }
    return [$name, $g];
}

const PRP_COVERS = [['آتش\s*سوزی.{0,12}سرقت\s*کلی', 'آتش‌سوزی، سرقت کلی و حادثه (پایه)'],
    ['سرقت\s*لوازم', 'سرقت لوازم و قطعات'],
    ['سیل|زلزله|بلایای', 'بلایای طبیعی (سیل، زلزله، تگرگ، طوفان...)'],
    ['شکست\s*شیشه', 'شکست شیشه'],
    ['نوسان\s*قیمت', 'نوسان قیمت'],
    ['ایاب\s*و\s*ذهاب', 'هزینه ایاب و ذهاب'],
    ['ترانزیت', 'ترانزیت (خارج از کشور)'],
    ['مواد\s*شیمیایی|اسید', 'پاشیدن رنگ/اسید/مواد شیمیایی'],
    ['کشیدن\s*رنگ|خط\s*و\s*خش', 'کشیدن رنگ']];

function prp_covers_clean($text) {
    $found = [];
    foreach (PRP_COVERS as [$rx, $name]) if (pb_m($rx, (string)$text) && !in_array($name, $found, true)) $found[] = $name;
    return implode('، ', $found);
}

function prp_other_names($text, $skip) {
    $res = [];
    foreach (pb_all('(جناب\s*آقای|سرکار\s*خانم)\s*([آ-ی\s]{3,40}?)(?=[-\d():،,]|$|\n)', $text) as $m) {
        $nm = pb_ustrip(pb_sub('\s+', ' ', $m[2]));
        $seen = array_map(function ($r) { return $r[0]; }, $res);
        if ($nm !== '' && $nm !== $skip && !in_array($nm, $seen, true)) $res[] = [$nm, mb_strpos($m[1], 'خانم') !== false ? 'F' : 'M'];
    }
    return $res;
}

function prp_rows_by(array $spans, $rx) {
    $labels = array_values(array_filter($spans, function ($s) use ($rx) { return pb_m($rx, $s['t']); }));
    if (!$labels) return [null, []];
    $lab = $labels[0];
    $row = array_values(array_filter($spans, function ($s) use ($lab) { return $s['i'] !== $lab['i'] && pb_same_row($s, $lab, 0.3); }));
    return [$lab, $row];
}

function prp_band(array $row, $w, $lo, $hi, $allowLabel = false) {
    $res = [];
    foreach ($row as $s) {
        $c = ($s['x0'] + $s['x1']) / 2 / $w;
        if ($lo <= $c && $c < $hi && ($allowLabel || mb_strpos($s['t'], ':') === false)) $res[] = $s;
    }
    $res = pb_sort($res, function ($s) { return -$s['x1']; });
    return pb_ustrip(implode(' ', array_map(function ($s) { return $s['t']; }, $res)));
}

function prp_clean($v) {
    $v = trim(pb_ustrip(pb_sub('\s+', ' ', (string)$v)), ' -:');
    return pb_full('[-\s.]*', $v) ? '' : $v;
}

function prp_parse_proposal(array $sp, $w, $text) {
    $t = pb_norm($text);
    $head = mb_substr($t, 0, 300);
    $d = ['ins_type' => mb_strpos($head, 'بدنه') !== false ? 'بدنه' : (mb_strpos($head, 'ثالث') !== false ? 'ثالث' : '')];

    [$lab, $row] = prp_rows_by($sp, 'کد\s*ملی');
    if ($lab) {
        $all = array_merge($row, [$lab]);
        $nm = prp_band($all, $w, 0.55, 0.95);
        [$d['insured_name'], $d['insured_gender']] = prp_honor_split($nm);
        foreach ($all as $s) {
            if (pb_m('(?<!\d)(\d{10})(?!\d)', $s['t'], $m) && empty($d['national_id'])) $d['national_id'] = $m[1];
            if (empty($d['birth_date'])) { $ds = pb_dates_in($s['t']); if ($ds) $d['birth_date'] = $ds[0]; }
        }
        $d['job'] = prp_clean(prp_band($row, $w, 0.0, 0.2));
    }
    [$lab, $row] = prp_rows_by($sp, 'نشانی');
    if ($lab) {
        $d['address'] = prp_clean(prp_band($row, $w, 0.3, 0.92));
        foreach ($row as $s) if (pb_m('(?<!\d)(\d{10})(?!\d)', $s['t'], $m)) $d['postal_code'] = $m[1];
    }
    [$lab, $row] = prp_rows_by($sp, '^\s*:?\s*تلفن');
    if ($lab) {
        foreach ($row as $s) { $ph = pb_norm_phone($s['t']); if ($ph !== '' && empty($d['phone'])) $d['phone'] = $ph; }
        $own = array_values(array_filter($row, function ($s) { return in_array($s['t'], ['بلی', 'بله', 'خیر'], true); }));
        if ($own) $d['insured_is_owner'] = in_array($own[0]['t'], ['بلی', 'بله'], true);
        $ben = prp_band($row, $w, 0.0, 0.3);
        if ($ben !== '' && !pb_m('\d{4}', $ben)) [$d['beneficiary'], $d['beneficiary_gender']] = prp_honor_split($ben);
    }
    if (!array_key_exists('insured_is_owner', $d) && pb_m('مالک\s*وسیله\s*نقلیه\s*می\s*باشد\??\s*(بلی|بله|خیر)', $t, $m)) $d['insured_is_owner'] = $m[1] !== 'خیر';

    [$lab, $row] = prp_rows_by($sp, 'شماره\s*پلاک');
    if ($lab) {
        $pl = prp_band($row, $w, 0.68, 0.9);
        $d['plate'] = pb_m('\d', $pl) ? pb_parse_plate($pl) : '';
        $d['car_kind'] = prp_clean(prp_band($row, $w, 0.45, 0.68));
        $d['car_system'] = prp_clean(prp_band($row, $w, 0.25, 0.45));
        $d['car_tip'] = prp_clean(prp_band($row, $w, 0.0, 0.25));
    }
    [$lab, $row] = prp_rows_by($sp, 'سال\s*ساخت');
    if ($lab) {
        $my = prp_clean(prp_band($row, $w, 0.68, 0.95));
        $d['model_year'] = pb_full('(1[34]\d{2}|19\d{2}|20\d{2})', $my) ? $my : '';
        $d['color'] = prp_clean(prp_band($row, $w, 0.45, 0.68));
        $d['capacity'] = prp_clean(prp_band($row, $w, 0.25, 0.45));
        $d['usage'] = prp_clean(prp_band($row, $w, 0.0, 0.25));
    }
    [$lab, $row] = prp_rows_by($sp, 'شماره\s*موتور');
    if ($lab) {
        $d['engine_no'] = pb_sub('[^A-Za-z0-9]', '', prp_band($row, $w, 0.65, 0.95));
        $d['vin'] = pb_sub('[^A-Za-z0-9]', '', prp_band($row, $w, 0.4, 0.65));
        $cy = prp_clean(prp_band($row, $w, 0.0, 0.25));
        $d['cylinders'] = pb_full('\d{1,2}', $cy) ? $cy : '';
    }

    [$lab, $row] = prp_rows_by($sp, 'بیمه\s*نامه\s*سال\s*قبل');
    if ($lab) {
        $pn = prp_clean(prp_band($row, $w, 0.62, 0.95));
        $d['prev_policy'] = pb_m('\d{3}', $pn) ? $pn : '';
        $ds = []; foreach ($row as $s) $ds = array_merge($ds, pb_dates_in($s['t']));
        $d['prev_expiry'] = $ds ? $ds[0] : '';
        $pi = prp_clean(prp_band($row, $w, 0.0, 0.25));
        $d['prev_insurer'] = ($pi !== '' && !pb_m('\d', $pi)) ? $pi : '';
    }
    if (pb_m('دارای\s*\.*\s*(\d+)\s*\.*\s*مرتبه\s*خسارت', $t, $m)) $d['prev_claims'] = $m[1];

    $top = null; $bot = null;
    foreach ($sp as $s) { if ($top === null && mb_strpos($s['t'], 'خطرهای اصلی') !== false) $top = $s; if ($bot === null && mb_strpos($s['t'], 'لیست وسایل اضافی') !== false) $bot = $s; }
    if ($top && $bot) {
        $cv = [];
        foreach (pb_sort($sp, function ($s) { return $s['y0']; }) as $s) if ($top['y1'] < $s['y0'] && $s['y0'] < $bot['y0'] && mb_strlen(pb_ustrip($s['t'])) > 3) $cv[] = pb_ustrip($s['t']);
        $c = prp_covers_clean(implode(' ', $cv));
        $d['covers'] = $c !== '' ? $c : implode('، ', $cv);
    }

    if (pb_m('([\d,]{9,})\s*اینجانب\s*(.+?)\s*با\s*ت[آا]یید', $t, $m) || pb_m('اینجانب\s*(.+?)\s*با\s*ت[آا]یید[\s\S]{0,300}?([\d,]{9,})', $t, $m)) {
        [$a, $b] = [$m[1], $m[2]];
        [$amount, $applicant] = pb_m('^[\d,]+$', $a) ? [$a, $b] : [$b, $a];
        $d['car_value'] = str_replace(',', '', $amount);
        $d['applicant'] = prp_honor_split($applicant)[0];
    }
    if (pb_m('(\d{1,3})\s*به\s*مدت\s*(1[34]\d{2}/\d{1,2}/\d{1,2})', $t, $m) || pb_m('از\s*تاریخ\s*(1[34]\d{2}/\d{1,2}/\d{1,2})[\s\S]{0,40}?به\s*مدت\s*(\d{1,3})', $t, $m)) {
        [$g1, $g2] = [$m[1], $m[2]];
        [$days, $start] = strpos($g2, '/') !== false ? [$g1, $g2] : [$g2, $g1];
        $sd = pb_dates_in($start);
        $d['start_date'] = $sd ? $sd[0] : '';
        $d['duration_days'] = $days;
    }
    $ds = pb_dates_in(mb_substr($t, -400));
    if ($ds) $d['proposal_date'] = end($ds);

    $d = array_filter($d, function ($v) { return $v !== '' && $v !== null; });
    $missing = [];
    foreach ([['insured_name', 'نام بیمه‌گزار'], ['national_id', 'کد ملی'], ['vin', 'شماره شاسی'], ['car_value', 'ارزش خودرو']] as [$k, $fa]) if (empty($d[$k])) $missing[] = $fa;
    return [$d, $missing];
}

function prp_read($abs) {
    try {
        $doc = PdfDoc::open($abs);
        $pt = new PdfText($doc);
        $sp = pb_page_spans($pt, 0, $w, $h);
        $text = $pt->text(0);
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'فایل PDF باز نشد.', 'debug' => mb_substr($e->getMessage(), 0, 200)]; }
    $t = pb_norm($text);
    if (mb_strlen(pb_ustrip($t)) < 30) return ['ok' => false, 'error' => 'متنِ فایل خوانده نشد (اسکن است)؛ فایلِ PDFِ خودِ سایتِ بیمه‌گر را بارگذاری کنید.'];
    if (pb_m('پرسش\s*نامه\s*و\s*پیشنهاد|پیشنهاد\s*بیمه', mb_substr($t, 0, 600))) {
        [$data, $missing] = prp_parse_proposal($sp, $w, $text);
        return ['ok' => true, 'kind' => 'proposal', 'data' => $data, 'missing' => $missing];
    }
    if (pb_m('بیمه\s*نامه', mb_substr($t, 0, 1500))) {
        [$data, $missing] = pb_extract_policy($sp, $w, $h, $text);
        $data = array_filter($data, function ($v) { return $v !== '' && $v !== null && $v !== 'ناشناخته'; });
        if (!empty($data['insured_name'])) [$data['insured_name'], $data['insured_gender']] = prp_honor_split($data['insured_name']);
        if (!empty($data['covers'])) { $c = prp_covers_clean($data['covers']); if ($c !== '') $data['covers'] = $c; }
        $ben = prp_other_names($t, $data['insured_name'] ?? '');
        if ($ben && mb_strpos($t, 'ذینفع') !== false) [$data['beneficiary'], $data['beneficiary_gender']] = $ben[0];
        return ['ok' => true, 'kind' => empty($data['policy_num']) ? 'draft' : 'policy', 'data' => $data, 'missing' => array_values(array_filter($missing, function ($m) { return $m !== 'شماره بیمه‌نامه'; }))];
    }
    return ['ok' => false, 'error' => 'این فایل پیشنهاد یا پیش‌نویسِ بیمه‌نامه نیست.'];
}
