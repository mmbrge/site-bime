<?php
// فایل: api/_vdoc_reader.php
// خواننده‌ی مدارکِ خودرو (سند فروش، سند مالکیت، کارت خودرو، بیمه‌نامه) با PHP — جایگزینِ api/py/vehicle_doc_reader.py
// فقط PDFهای «متنی» (لایه‌ی متن دارند) خوانده می‌شوند؛ عکس و PDFِ اسکن‌شده با PHP قابلِ خواندن نیست (OCR نیاز دارد) و
// خروجیِ «خوانده نشد» برمی‌گرداند تا کارشناس دستی وارد کند. قاعده‌های استخراج همان نسخه‌ی پایتونی است.
//   vdr_read($path, $pageNo = 1) => ['ok'=>true, 'kind', 'kind_fa', 'status', 'data', 'missing', 'engines'] | ['ok'=>false, 'error'=>...]
require_once __DIR__ . '/_policy_reader.php';

const VDR_KIND_FA = ['sales_invoice' => 'فاکتور فروش (سند فروش کارخانه)', 'ownership_doc' => 'سند مالکیت', 'policy' => 'بیمه‌نامه', 'car_card' => 'کارت خودرو', 'unknown' => 'نامشخص'];
const VDR_NFKC = true;   // حروفِ «نمایشیِ» PDF به حروفِ عادی (نسخه‌ی پایتونی این را نداشت)

function vdr_norm($s) {
    $s = (string)$s;
    if (VDR_NFKC) $s = pdft_nfkc($s);
    $s = strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                    'ي' => 'ی', 'ك' => 'ک', 'ـ' => '', "\u{200C}" => ' ', "\u{200F}" => '', "\u{200E}" => '']);
    return pb_ustrip(pb_sub('[ \t]+', ' ', $s));
}
function vdr_clean_code($s) { return strtoupper(pb_sub('[^A-Za-z0-9]', '', vdr_norm($s))); }
function vdr_valid_nid($s) {
    $s = pb_sub('\D', '', (string)$s);
    if (strlen($s) !== 10 || count(array_unique(str_split($s))) === 1) return false;
    $c = 0; for ($i = 0; $i < 9; $i++) $c += (int)$s[$i] * (10 - $i);
    $c %= 11;
    return (int)$s[9] === ($c < 2 ? $c : 11 - $c);
}
function vdr_is_vin($t, $strict = true) {
    $t = vdr_clean_code($t);
    $ok = $strict ? pb_full('(?<![A-Z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Z0-9])', $t) : pb_full('[A-Z0-9]{17}', $t);
    return strlen($t) === 17 && $ok && pb_m('[A-Z]', $t) && pb_m('\d', $t);
}

function vdr_to_rows(array $boxes, $tight = false) {
    $bs = pb_sort(array_values(array_filter($boxes, function ($b) { return vdr_norm($b['t']) !== ''; })), function ($b) { return ($b['y0'] + $b['y1']) / 2; });
    $rows = [];
    foreach ($bs as $b) {
        $cy = ($b['y0'] + $b['y1']) / 2; $h = max(8.0, $b['y1'] - $b['y0']);
        $placed = false;
        foreach ($rows as &$r) {
            if (abs($r['cy'] - $cy) < ($tight ? 0.35 : 0.55) * max($h, $r['h'])) {
                $r['items'][] = $b;
                $r['cy'] = ($r['cy'] * (count($r['items']) - 1) + $cy) / count($r['items']);
                $placed = true; break;
            }
        }
        unset($r);
        if (!$placed) $rows[] = ['cy' => $cy, 'h' => $h, 'items' => [$b]];
    }
    $out = [];
    foreach ($rows as $r) {
        $items = pb_sort($r['items'], function ($b) { return -(($b['src'] ?? '') === 'tesseract' ? $b['x0'] : $b['x1']); });
        $out[] = ['y' => $r['cy'], 'text' => implode('  ', array_map(function ($b) { return vdr_norm($b['t']); }, $items)), 'items' => $items];
    }
    return $out;
}

const VDR_LABELS = [
    'usage' => 'نوع\s*کاربری', 'car_system' => 'سیستم', 'car_tip' => 'تیپ', 'model_year' => '(?<!نوع\s)مدل', 'fuel' => 'نوع\s*سوخت',
    'color' => 'رنگ', 'capacity' => 'ظرفیت', 'cylinders' => 'تعداد\s*سیلندر', 'axles' => 'تعداد\s*محور', 'wheels' => 'تعداد\s*چرخ',
    'engine_no' => 'شماره\s*موتو[رد]', 'chassis_no' => 'شماره\s*شاس[یي]', 'cab_no' => 'شماره\s*اتاق', 'cyl_volume' => 'حجم\s*سیلندر',
    'maker_country' => 'کشور\s*سازنده', 'vin_label' => 'شماره\s*شناسا[یئ][یي]|VIN',
    'owner_name' => 'نام\s*و\s*نام\s*خانواد\s*[گک]\s*[یي](?:\s*[/|]\s*سازمان)?(?:\s*[/|]\s*ش\s*ر\s*ک\s*ت)?|نام\s*سازمان|نام\s*ش\s*ر\s*ک\s*ت(?!\s*(?:بیمه|یا))',
    'father_name' => 'نام\s*پدر', 'birth_date' => 'تاریخ\s*تولد\s*(?:/\s*ثبت)?|تاریخ\s*تاسیس', 'id_no' => 'شماره\s*شناسنامه\s*(?:/\s*ثبت)?|شماره\s*ثبت',
    'national_id' => '(?:شماره|کد|شناسه)\s*ملی', 'postal_code' => 'کد\s*پستی', 'mobile' => 'تلفن\s*همراه', 'phone' => 'تلفن\s*ثابت',
    'address' => 'آدرس(?:\s*محل\s*سکونت)?(?:\s*\(مالک\s*(?:حقیقی|حقوقی)\))?|نشانی', 'economic_code' => 'کد\s*اقتصادی',
    'issue_place' => 'محل\s*صدور\s*(?:/\s*ثبت)?|محل\s*ثبت', 'doc_no' => 'شماره\s*سند', 'doc_date' => '^\s*تاریخ\s*:',
];

function vdr_label_rx() {
    static $rx = null;
    if ($rx === null) { $p = []; foreach (VDR_LABELS as $k => $v) $p[] = '(?P<' . $k . '>' . $v . ')'; $rx = implode('|', $p); }
    return $rx;
}

// همه‌ی برچسب‌های یک متن: [[key, startChar, endChar], ...] (مثلِ finditer + lastgroup)
function vdr_label_iter($text) {
    $o = [];
    if (!preg_match_all(pb_rx(vdr_label_rx()), $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $o;
    foreach ($mm as $m) {
        $key = null;
        foreach (array_keys(VDR_LABELS) as $k) if (isset($m[$k]) && $m[$k][1] >= 0 && $m[$k][0] !== '') { $key = $k; break; }
        if ($key === null) continue;
        $sb = $m[0][1]; $eb = $sb + strlen($m[0][0]);
        $o[] = [$key, mb_strlen(substr($text, 0, $sb)), mb_strlen(substr($text, 0, $eb))];
    }
    return $o;
}

function vdr_label_hits(array $atoms) {
    $hits = [];
    foreach (vdr_to_rows($atoms, true) as $r) {
        $items = $r['items'];
        $text = ''; $spans = [];
        foreach ($items as $i => $b) {
            $t = vdr_norm($b['t']);
            if ($text !== '') $text .= ' ';
            $a = mb_strlen($text);
            $spans[] = [$a, $a + mb_strlen($t), $i];
            $text .= $t;
        }
        foreach (vdr_label_iter($text) as [$key, $ms, $me]) {
            $idx = [];
            foreach ($spans as [$a, $e, $i]) if ($a < $me && $e > $ms) $idx[] = $i;
            if (!$idx) continue;
            $bs = array_map(function ($i) use ($items) { return $items[$i]; }, $idx);
            $last = null; foreach ($spans as $sp) if ($sp[2] === end($idx)) $last = $sp;
            $rest = pb_trim(pb_ustrip(mb_substr($text, $me, $last[1] - $me)), ' :|.،-');
            $ids = []; foreach ($bs as $b) $ids[$b['i']] = true;
            $hits[] = ['key' => $key, 'x0' => min(array_column($bs, 'x0')), 'x1' => max(array_column($bs, 'x1')), 'y0' => min(array_column($bs, 'y0')), 'y1' => max(array_column($bs, 'y1')), 'rest' => $rest, 'ids' => $ids];
        }
    }
    return $hits;
}

function vdr_kv_geo(array $atoms, $pageW) {
    $hits = vdr_label_hits($atoms);
    if (!$hits) return [];
    $labIds = [];
    foreach ($hits as &$h) { $labIds += $h['ids']; $h['cy'] = ($h['y0'] + $h['y1']) / 2; $h['lh'] = max(10.0, $h['y1'] - $h['y0']); $h['vals'] = []; }
    unset($h);
    foreach ($atoms as $b) {
        if (isset($labIds[$b['i']]) || vdr_norm($b['t']) === '') continue;
        $cy = ($b['y0'] + $b['y1']) / 2;
        $cands = [];
        foreach ($hits as $k => $h) if ($h['x0'] >= $b['x1'] - 4 && $b['x1'] >= $h['x0'] - 0.55 * $pageW && abs($cy - $h['cy']) < 1.6 * $h['lh']) $cands[] = $k;
        if (!$cands) continue;
        $dmin = min(array_map(function ($k) use ($hits, $cy) { return abs($cy - $hits[$k]['cy']); }, $cands));
        $near = array_values(array_filter($cands, function ($k) use ($hits, $cy, $dmin) { return abs($cy - $hits[$k]['cy']) <= $dmin + 0.45 * $hits[$k]['lh']; }));
        $best = $near[0];
        foreach ($near as $k) if ($hits[$k]['x0'] < $hits[$best]['x0']) $best = $k;
        $hits[$best]['vals'][] = $b;
    }
    $out = [];
    foreach ($hits as $h) {
        $parts = [];
        if (mb_strlen(pb_sub('[\s:|.]', '', $h['rest'])) >= 1) $parts[] = $h['rest'];
        if ($h['vals']) foreach (vdr_to_rows($h['vals']) as $r) $parts[] = $r['text'];
        $v = pb_trim(pb_ustrip(implode(' ', $parts)), ' :|.،-');
        $v = pb_ustrip(pb_sub('^[:\s|]+', '', $v));
        if ($v !== '') $out[$h['key']][] = [$v, $h['cy']];
    }
    return $out;
}

function vdr_first($vals, $rx = null, $clean = null) {
    foreach ((array)$vals as $v) {
        if ($rx !== null) {
            if (!pb_m($rx, $v, $m)) continue;
            $v = count($m) > 1 ? $m[1] : $m[0];
        }
        $v = $clean ? $clean($v) : pb_ustrip($v);
        if ($v !== '' && $v !== null) return $v;
    }
    return '';
}

function vdr_fdate($s) {
    return pb_m('(1[34]\d\d)\s*[/.\-]\s*(\d{1,2})\s*[/.\-]\s*(\d{1,2})', vdr_norm($s), $m) ? sprintf('%s/%02d/%02d', $m[1], (int)$m[2], (int)$m[3]) : '';
}

function vdr_classify($text) {
    $t = vdr_norm($text);
    $sales = count(pb_all('سند\s*فروش|فاکتور\s*فروش|صورت\s*حساب\s*فروش|PILSAN|کارخانه|شماره\s*اتاق|نظریه\s*کارشناس\s*تصادفات|شماره\s*پروانه\s*گمرکی|نامبرده\s*زیر\s*واگذار', $t, 'i'));
    $own = count(pb_all('مالکیت\s*وسیله|سند\s*رسمی|پلیس\s*راهنمایی|واحد\s*شماره\s*گذاری|شماره\s*انتظامی|اثر\s*انگشت|نام\s*سازمان|تاریخ\s*تاسیس|مسئول\s*بررسی\s*مدارک|مشخصات\s*وسیله\s*\S*\s*:', $t));
    $pol = count(pb_all('بیمه\s*نامه|حق\s*بیمه|تعهدات\s*بیمه\s*گر|بیمه\s*گذار|خسارت|تخفیف', $t));
    if (pb_m('کارت\s*(?:خودرو|ماشین|مشخصات\s*خودرو)|VEHICLE\s*CARD', $t, $m, 'i') && max($sales, $own, $pol) < 2) return 'car_card';
    $best = [$sales, 'sales_invoice'];
    foreach ([[$own, 'ownership_doc'], [$pol, 'policy']] as $c) if ($c[0] > $best[0] || ($c[0] === $best[0] && strcmp($c[1], $best[1]) > 0)) $best = $c;
    return ($best[0] >= 2 || ($best[0] === 1 && $best[1] !== 'policy')) ? $best[1] : 'unknown';
}

function vdr_latin_tokens(array $boxes) {
    $toks = [];
    foreach ($boxes as $b) {
        $t = vdr_norm($b['t']);
        if (pb_full('(?:[A-Z0-9]\s+){10,}[A-Z0-9]', $t)) $toks[] = str_replace(' ', '', $t);
        foreach (pb_all('[A-Za-z0-9][A-Za-z0-9.*\-/]{4,40}[A-Za-z0-9*]', $t) as $m) $toks[] = $m[0];
    }
    return $toks;
}

function vdr_similar($a, $b) {
    if ($a === '' || $b === '' || $a === null || $b === null) return false;
    if ($a === $b || substr($a, -8) === substr($b, -8)) return true;
    if (abs(strlen($a) - strlen($b)) > 2) return false;
    $diff = 0; for ($i = 0, $n = min(strlen($a), strlen($b)); $i < $n; $i++) if ($a[$i] !== $b[$i]) $diff++;
    return $diff + abs(strlen($a) - strlen($b)) <= 3;
}

function vdr_fix_ocr_code($t) {
    if (pb_full('([A-Z]{3})([0-9O]{6})', $t, $m) || pb_full('([A-Z]{2,4})([0-9O]{4,10})', $t, $m)) return $m[1] . str_replace('O', '0', $m[2]);
    return $t;
}

const VDR_OWNER_KEYS = ['owner_name', 'father_name', 'birth_date', 'id_no', 'national_id', 'postal_code', 'mobile', 'phone', 'address', 'issue_place'];

function vdr_extract(array $atoms, array $boxes, array $alltextLines, $alltext, $pageW) {
    $kvg = vdr_kv_geo($atoms, $pageW);
    $ownerY = null;
    foreach (vdr_to_rows($atoms, true) as $r) if (pb_m('مشخصات\s*مال[کك]', $r['text'])) { $ownerY = $r['y']; break; }
    $dang = array_values(array_filter($atoms, function ($b) { return pb_m('دا?ن[گک]', vdr_norm($b['t'])); }));
    if ($ownerY === null && $dang) $ownerY = min(array_column($dang, 'y0')) - 3 * max(10.0, $dang[0]['y1'] - $dang[0]['y0']);
    if ($ownerY === null && count($kvg['owner_name'] ?? []) > 1) $ownerY = max(array_map(function ($x) { return $x[1]; }, $kvg['owner_name'])) - 10;
    $kv = [];
    foreach ($kvg as $k => $vs) {
        if (in_array($k, VDR_OWNER_KEYS, true) && $ownerY !== null) {
            $f = array_values(array_filter($vs, function ($x) use ($ownerY) { return $x[1] > $ownerY - 10; }));
            $vs = $f ?: ($k !== 'national_id' ? [] : $vs);
        }
        $kv[$k] = array_map(function ($x) { return $x[0]; }, $vs);
    }
    $d = [];
    $lat = vdr_latin_tokens(pb_sort($boxes, function ($b) { return ($b['src'] ?? '') === 'rapid' ? 0 : 1; }));
    $vins = [];
    foreach (array_merge($kv['chassis_no'] ?? [], $kv['vin_label'] ?? []) as $v) foreach (pb_all('[A-Z0-9][A-Z0-9 ]{15,40}', $v) as $m) if (vdr_is_vin(vdr_clean_code($m[0]), false)) $vins[] = vdr_clean_code($m[0]);
    if (!$vins) foreach ($lat as $x) if (vdr_is_vin($x)) $vins[] = vdr_clean_code($x);
    if (!$vins) {
        $singles = array_values(array_filter($boxes, function ($b) { return pb_full('[A-Za-z0-9]', vdr_norm($b['t'])); }));
        foreach (vdr_to_rows($singles) as $r) {
            $seq = implode('', array_map(function ($b) { return strtoupper(vdr_norm($b['t'])); }, pb_sort($r['items'], function ($b) { return $b['x0']; })));
            for ($k = 0; $k < strlen($seq) - 16; $k++) if (vdr_is_vin(substr($seq, $k, 17), false)) { $vins[] = substr($seq, $k, 17); break; }
        }
    }
    if ($vins) {
        $fixed = [];
        foreach ($vins as $x) { $y = str_replace('O', '0', $x); $fixed[] = (!vdr_is_vin($x) && vdr_is_vin($y)) ? $y : $x; }
        $strict = array_values(array_filter($fixed, 'vdr_is_vin'));
        $pool = $strict ?: $fixed;
        $cnt = array_count_values($pool); arsort($cnt);
        $d['vin'] = (string)array_key_first($cnt);
    }
    foreach ($kv['chassis_no'] ?? [] as $v) {
        foreach (pb_all('[A-Za-z0-9][A-Za-z0-9\-]{6,24}', $v) as $m) {
            $c = vdr_clean_code($m[0]);
            if (strlen($c) >= 8 && strlen($c) <= 22 && pb_m('[A-Z]', $c) && pb_m('\d', $c) && $c !== ($d['vin'] ?? null)) { $d['chassis_no'] = $c; break; }
        }
        if (!empty($d['chassis_no'])) break;
    }
    $engOk = function ($x) use (&$d) {
        $c = vdr_clean_code($x);
        return strlen($c) >= 6 && strlen($c) <= 30 && pb_m('\d', $c) && pb_m('[A-Z]', $c) && !vdr_similar($c, $d['vin'] ?? '')
            && !vdr_is_vin($c) && !pb_full('[A-Z]{2,4}[0-9O]{5,8}', $c) && !pb_full('\d+CC', $c) && !pb_full('S\d{2,8}', $c);
    };
    $engs = [];
    foreach ($kv['engine_no'] ?? [] as $v) foreach (pb_all('[A-Za-z0-9*][A-Za-z0-9.*\-/,]{4,40}[A-Za-z0-9*]?', $v) as $m) if ($engOk($m[0]) && vdr_clean_code($m[0]) !== ($d['chassis_no'] ?? null)) $engs[] = $m[0];
    $latEng = array_values(array_filter($lat, function ($x) use ($engOk) { return $engOk($x) && !pb_full('\d{2}/\d+-\d+-\d+=\d+', $x); }));
    if ($engs && $latEng) {
        $e0 = vdr_clean_code($engs[0]);
        $ends = function ($a, $b) { return $b === '' || substr($a, -strlen($b)) === $b; };
        $better = array_values(array_filter($latEng, function ($x) use ($e0, $ends) { $c = vdr_clean_code($x); return $c !== $e0 && ($ends($c, substr($e0, -10)) || $ends($e0, substr($c, -10))) && strlen($c) >= strlen($e0); }));
        if ($better) { usort($better, function ($a, $b) { return strlen(vdr_clean_code($b)) <=> strlen(vdr_clean_code($a)); }); $engs = [$better[0]]; }
    }
    if (!$engs && $latEng) {
        $bestE = $latEng[0]; foreach ($latEng as $x) if (strlen(vdr_clean_code($x)) > strlen(vdr_clean_code($bestE))) $bestE = $x;
        $engs = [$bestE];
        $tail = substr(vdr_clean_code($bestE), -8);
        $any = false; foreach ($kv['engine_no'] ?? [] as $v) if (strpos(vdr_clean_code($v), $tail) !== false) $any = true;
        if (!$any) $d['engine_guess'] = 1;
    }
    if (!$engs) foreach ($kv['engine_no'] ?? [] as $v) { foreach (pb_all('(?<!\d)\d{5,12}(?!\d)', $v) as $m) if (!vdr_similar($m[0], $d['vin'] ?? '')) { $engs[] = $m[0]; break; } }
    if ($engs) { $d['engine_raw'] = vdr_norm($engs[0]); $d['engine_no'] = vdr_clean_code($engs[0]); }
    $nids = [];
    foreach ($kv['national_id'] ?? [] as $v) foreach (pb_all('\d[\d\s\-]{8,14}\d', $v) as $m) { $x = pb_sub('\D', '', $m[0]); if (vdr_valid_nid($x) || pb_full('1\d{10}', $x)) $nids[] = $x; }
    if ($nids) $d['national_id'] = $nids[0];
    $cut = function ($s) { return pb_trim(pb_ustrip(pb_sub('\s{3,}.*$', '', $s)), ' :|-،.'); };
    foreach (['usage', 'car_system', 'fuel', 'color', 'maker_country', 'father_name', 'issue_place'] as $k) {
        $v = vdr_first($kv[$k] ?? [], null, $cut);
        $v = pb_sub('[^آ-ی0-9\s\-/()]', ' ', $v);
        $v = pb_sub('(?<![\d/])\b\d{1,2}\b(?![\d/])', ' ', $v);
        $v = pb_trim(pb_ustrip(pb_sub('\s+', ' ', $v)), ' -/');
        if ($v !== '' && mb_strlen($v) >= 2 && mb_strlen($v) <= 40 && count(pb_all('[آ-ی]', $v)) >= 2) $d[$k] = $v;
    }
    $v = vdr_first($kv['car_tip'] ?? [], '([A-Za-z0-9][A-Za-z0-9/\-]{1,14}|[آ-ی][آ-ی ]{1,20})');
    if ($v !== '') $d['car_tip'] = trim($v, '/ ');
    $shorts = array_map(function ($b) { return str_replace(' ', '', vdr_norm($b['t'])); }, pb_sort($boxes, function ($b) { return ($b['src'] ?? '') === 'rapid' ? 0 : 1; }));
    if (empty($d['car_tip'])) foreach ($shorts as $x) if (pb_full('/[A-Z0-9]{2,6}|[A-Z]\d[A-Z]{1,2}', $x)) { $d['car_tip'] = trim($x, '/'); break; }
    $v = vdr_first($kv['model_year'] ?? [], '(1[34]\d\d|20\d\d|19\d\d)');
    if ($v === '') foreach ($shorts as $x) if (pb_full('13[5-9]\d|14[0-2]\d|20[0-3]\d', $x)) { $v = $x; break; }
    if ($v !== '') $d['model_year'] = $v;
    foreach ([['cylinders', '(?<!\d)(\d{1,2})(?!\d)'], ['axles', '(?<!\d)(\d)(?!\d)'], ['wheels', '(?<!\d)(\d{1,2})(?!\d)']] as [$k, $rx]) { $v = vdr_first($kv[$k] ?? [], $rx); if ($v !== '') $d[$k] = $v; }
    $v = vdr_first($kv['capacity'] ?? [], null, $cut);
    if (preg_match(pb_rx('(\d{1,3})\s*(?:تن|نفر)(?:[^\d]{0,20}(\d{1,2})\s*نفر)?'), (string)$v, $m, PREG_OFFSET_CAPTURE)) {
        $st = mb_strlen(substr((string)$v, 0, $m[0][1])); $ln = mb_strlen($m[0][0]);
        $win = mb_substr((string)$v, $st, $ln + 3);
        $g2 = isset($m[2]) && $m[2][1] >= 0 ? $m[2][0] : '';
        $d['capacity'] = $m[1][0] . (mb_strpos($win, 'تن') !== false ? ' تن' : ' نفر') . ($g2 !== '' ? ' با ' . $g2 . ' نفر' : '');
    }
    if (pb_m('(\d{3,5})\s*CC|CC\s*(\d{3,5})', $alltext, $m, 'i')) $d['cyl_volume'] = pb_g($m, 1) ?? pb_g($m, 2);
    $cabs = [];
    foreach (array_merge($kv['cab_no'] ?? [], $lat) as $x) if (pb_full('[A-Z]{3}[0-9O]{6}', vdr_clean_code($x))) $cabs[] = vdr_fix_ocr_code(vdr_clean_code($x));
    if ($cabs) $d['cab_no'] = $cabs[0];
    $v = '';
    foreach ($alltextLines as $ln) {
        if (pb_m('([آ-یA-Za-z][آ-یA-Za-z\s/]{3,90}?)\s*\(?\s*(\d)\s*دا?ن[گک]\s*\)?', $ln, $m)) {
            $v = $m[1] . ' (' . $m[2] . ' دانگ)';
            $parts = preg_split(pb_rx('شر\s*کت\s*:|سازمان\s*/?\s*ش\s*ر\s*ک\s*ت\s*:?'), $v);
            $v = end($parts);
            break;
        }
    }
    if ($v === '' && $dang) {
        $b0 = $dang[0]; $h0 = max(10.0, $b0['y1'] - $b0['y0']); $cy0 = ($b0['y0'] + $b0['y1']) / 2;
        $labs = array_filter(vdr_label_hits($atoms), function ($h) use ($cy0, $h0, $b0) { return abs(($h['y0'] + $h['y1']) / 2 - $cy0) < 1.6 * $h0 && $h['x0'] > $b0['x1']; });
        $right = $labs ? min(array_column($labs, 'x0')) : $pageW * 0.9;
        $near = array_values(array_filter($atoms, function ($b) use ($b0, $cy0, $h0, $right) { return $b['i'] !== $b0['i'] && abs(($b['y0'] + $b['y1']) / 2 - $cy0) < 0.9 * $h0 && $b0['x1'] - 4 <= $b['x0'] && $b['x1'] <= $right + 4; }));
        if ($near) $v = implode(' ', array_map(function ($r) { return $r['text']; }, vdr_to_rows($near))) . ' (6 دانگ)';
    }
    if ($v === '') $v = vdr_first(array_values(array_filter($kv['owner_name'] ?? [], function ($x) { return !pb_m('سمت|مدیر', $x); })), null, function ($s) { return pb_trim(pb_ustrip($s), ' :|-'); });
    if ($v !== '') {
        if (pb_m('\(?\s*(\d)\s*دا?ن[گک]\s*\)?', $v, $sh)) { $d['owner_share'] = $sh[1]; $v = mb_substr($v, 0, mb_strpos($v, $sh[0])); }
        $v = pb_sub('[^آ-ی\s]', ' ', $v);
        $v = pb_ustrip(pb_sub('\s+', ' ', $v));
        $v = pb_ustrip(pb_sub('^(?:(?:سازمان|ش\s*ر\s*ک\s*ت|نام\s*و\s*نام\s*خانواد\s*[گک]\s*[یي])\s*)+', '', $v));
        if (mb_strlen($v) >= 3 && mb_strlen($v) <= 90) $d['owner_name'] = $v;
    }
    $v = vdr_first($kv['birth_date'] ?? [], null, 'vdr_fdate'); if ($v !== '') $d['birth_date'] = $v;
    foreach (array_slice($alltextLines, 0, 8) as $x) { $f = vdr_fdate($x); if ($f !== '') { $d['doc_date'] = $f; break; } }
    $v = vdr_first($kv['id_no'] ?? [], '(?<!\d)(\d{5,12})(?!\d)'); if ($v !== '') $d['id_no'] = $v;
    if (empty($d['national_id']) && pb_full('1\d{10}', $d['id_no'] ?? '')) $d['national_id'] = $d['id_no'];
    $v = vdr_first($kv['postal_code'] ?? [], '(?<!\d)(\d{10})(?!\d)'); if ($v !== '') $d['postal_code'] = $v;
    $v = vdr_first($kv['mobile'] ?? [], '(?<!\d)(0?9\d{9})(?!\d)'); if ($v !== '') $d['mobile'] = $v[0] === '0' ? $v : '0' . $v;
    $v = vdr_first($kv['phone'] ?? [], '(?<!\d)(0\d{9,10})(?!\d)'); if ($v !== '') $d['phone'] = $v;
    $v = vdr_first($kv['economic_code'] ?? [], '(?<!\d)(\d{10,14})(?!\d)'); if ($v !== '') $d['economic_code'] = $v;
    if (pb_m('(?<![A-Z0-9])(S-\d{3,8})', $alltext, $m)) $d['doc_no'] = $m[1];
    $addr = [];
    foreach ($kv['address'] ?? [] as $a) if (count(pb_all('[آ-ی]', $a)) > 20) $addr[] = $cut($a);
    if (!$addr && $ownerY !== null) {
        $rows = vdr_to_rows($atoms);
        foreach ($rows as $i => $r) if ($r['y'] > $ownerY && pb_m('آدرس|نشانی', $r['text']) && isset($rows[$i + 1]) && count(pb_all('[آ-ی]', $rows[$i + 1]['text'])) > 15) { $addr = [$rows[$i + 1]['text']]; break; }
    }
    if ($addr) { $a = pb_sub('[^آ-ی0-9\s,،.\-()/]', ' ', $addr[0]); $d['address'] = mb_substr(pb_trim(pb_ustrip(pb_sub('\s+', ' ', $a)), ' ,،.'), 0, 250); }
    if (pb_m('(\d{2})\s*[|\-]?\s*(\d{3})\s*([آ-ی])\s*(\d{2})|(\d{2})\s*([آ-ی])\s*(\d{3})\s*[|\-]?\s*(\d{2})', $alltext, $m)) {
        $d['plate'] = pb_g($m, 1) !== null ? sprintf('%s%s%s - %s', $m[1], $m[3], $m[2], $m[4]) : sprintf('%s%s%s - %s', $m[5], $m[6], $m[7], $m[8]);
    }
    if (!empty($d['car_system']) || !empty($d['car_tip'])) $d['car_name'] = trim(implode(' ', array_filter([$d['car_system'] ?? '', $d['car_tip'] ?? ''], 'strlen')));
    return $d;
}

const VDR_REQUIRED = [
    'sales_invoice' => [['vin', 'شماره شاسی'], ['engine_no', 'شماره موتور'], ['owner_name', 'نامِ خریدار'], ['national_id', 'کد ملیِ خریدار']],
    'ownership_doc' => [['vin', 'شماره شاسی'], ['engine_no', 'شماره موتور'], ['owner_name', 'نامِ مالک']],
    'car_card' => [['vin', 'شماره شاسی'], ['plate', 'پلاک']],
    'policy' => [['vin', 'شماره شاسی']],
];

function vdr_read($path, $pageNo = 1) {
    $h = (string)@file_get_contents($path, false, null, 0, 5);
    if ($h !== '%PDF-') return ['ok' => false, 'scan' => true, 'error' => 'عکس با سایت خوانده نمی‌شود (پردازشِ تصویر غیرفعال است)؛ اطلاعات را دستی وارد کنید.', 'engines' => []];
    try {
        $doc = PdfDoc::open($path);
        $pt = new PdfText($doc);
        $pi = max(0, min($doc->pageCount() - 1, $pageNo - 1));
        $dct = $pt->dict($pi);
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خواندنِ فایل ممکن نشد: ' . mb_substr($e->getMessage(), 0, 150)]; }
    $k = 300 / 72.0;
    $spans = []; $i = 0;
    foreach ($dct['blocks'] as $b) foreach ($b['lines'] as $l) foreach ($l['spans'] as $sp) {
        $t = pb_ustrip($sp['text']);
        if ($t === '') continue;
        $spans[] = ['t' => $t, 'x0' => $sp['bbox'][0] * $k, 'y0' => $sp['bbox'][1] * $k, 'x1' => $sp['bbox'][2] * $k, 'y1' => $sp['bbox'][3] * $k, 'src' => 'text', 'i' => $i++];
    }
    if (mb_strlen(implode('', array_column($spans, 't'))) <= 80) return ['ok' => false, 'scan' => true, 'error' => 'این صفحه اسکن/عکس است و متن ندارد؛ با سایت خوانده نمی‌شود — اطلاعات را دستی وارد کنید.', 'engines' => []];
    $pageW = $dct['width'] * $k;
    $rows = vdr_to_rows($spans);
    $lines = array_map(function ($r) { return $r['text']; }, $rows);
    $alltext = implode("\n", $lines) . "\n";
    $data = vdr_extract($spans, $spans, $lines, $alltext, $pageW);
    $kind = vdr_classify($alltext);
    if ($kind === 'unknown' && !empty($data['vin']) && !empty($data['engine_no'])) $kind = (!empty($data['cab_no']) || !empty($data['owner_share'])) ? 'sales_invoice' : (!empty($data['plate']) ? 'ownership_doc' : 'unknown');
    if ($kind === 'policy') $data['policy_type'] = (pb_m('بدنه', $alltext) && !pb_m('شخص\s*ثالث', $alltext)) ? 'BODY' : 'THIRDPARTY';
    $req = VDR_REQUIRED[$kind] ?? [];
    $missing = [];
    foreach ($req as [$kk, $fa]) if (empty($data[$kk]) && !($kk === 'vin' && !empty($data['chassis_no']))) $missing[] = $fa;
    if (!empty($data['engine_guess']) && !in_array('شماره موتور', $missing, true)) $missing[] = 'شماره موتور (حدسی؛ تأیید شود)';
    if ($kind === 'ownership_doc' && empty($data['plate'])) $missing[] = 'پلاک';
    $ident = !empty($data['vin']) || !empty($data['chassis_no']) || !empty($data['engine_no']);
    $status = ($req && !$missing) ? 'full' : (($ident || $kind !== 'unknown') ? 'partial' : 'none');
    return ['ok' => true, 'kind' => $kind, 'kind_fa' => VDR_KIND_FA[$kind], 'status' => $status, 'data' => $data, 'missing' => $missing, 'engines' => ['text'], 'barcodes' => []];
}
