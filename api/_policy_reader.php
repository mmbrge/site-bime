<?php
// فایل: api/_policy_reader.php
// خواننده‌ی بیمه‌نامه‌های بیمه‌گر با PHP (جایگزینِ api/py/policy_bundle.py؛ همان قاعده‌ها، خط به خط)
//   pb_bundle($pdf, $outDir)  «صدور گروهی»: فایلِ چند بیمه‌نامه‌ای را تکه‌تکه و هر بیمه‌نامه را شناسایی می‌کند (خروجی همان JSONِ قبلی)
//   pb_extract_policy($text, $pageIdx, PdfText) شناساییِ یک صفحه‌ی بیمه‌نامه
// متن با api/_pdf_text.php خوانده می‌شود (همان چیدمانِ PyMuPDF).
require_once __DIR__ . '/_pdf_text.php';

// عبارتِ باقاعده با یونیکد (مثلِ re ِ پایتون: \d و \s و \w یونیکدی)
function pb_rx($p, $f = '') { return '~(*UCP)' . str_replace('~', '\~', $p) . '~u' . $f; }
function pb_m($p, $s, &$m = null, $f = '') { return preg_match(pb_rx($p, $f), (string)$s, $m) === 1; }
function pb_full($p, $s, &$m = null, $f = '') { return preg_match(pb_rx('^(?:' . $p . ')$', 'D' . $f), (string)$s, $m) === 1; }
function pb_sub($p, $r, $s, $f = '') { return (string)preg_replace(pb_rx($p, $f), $r, (string)$s); }
function pb_all($p, $s, $f = '') { preg_match_all(pb_rx($p, $f), (string)$s, $m, PREG_SET_ORDER); return $m; }
function pb_g($m, $i) { return isset($m[$i]) && $m[$i] !== '' ? $m[$i] : null; }   // گروهِ اختیاری (None در پایتون)
function pb_strip($s, $chars = " \t\n\r\0\x0B") { return trim((string)$s, $chars); }
// trim با نویسه‌های چندبایتی (trim ِ PHP بایتی است و «،» یا «–» را خراب می‌کند)
function pb_trim($s, $chars) {
    $c = preg_quote($chars, '~');
    return (string)preg_replace('~^[' . $c . ']+|[' . $c . ']+$~u', '', (string)$s);
}
function pb_ustrip($s) { return (string)preg_replace('~^[\s\x{00A0}]+|[\s\x{00A0}]+$~u', '', (string)$s); }   // strip() ِ یونیکدی
// مرتب‌سازیِ پایدار (PHP 7.4 پایدار نیست)
function pb_sort(array $a, callable $key, $desc = false) {
    $i = 0; $t = [];
    foreach ($a as $v) $t[] = [$key($v), $i++, $v];
    usort($t, function ($x, $y) use ($desc) { $c = $x[0] <=> $y[0]; if ($desc) $c = -$c; return $c ?: $x[1] <=> $y[1]; });
    return array_map(function ($x) { return $x[2]; }, $t);
}

function pb_norm($t) { return pdf_norm_text((string)$t); }

// شماره‌ی بیمه‌نامه‌ی پاسارگاد از متنِ کامل: شماره‌ای که پشتِ «سال قبل / بیمه‌نامه‌ی قبلی» آمده کنار می‌رود؛ از بقیه سالِ تازه‌تر
function pb_pick_policy_num($tn) {
    $best = ''; $by = -1;
    if (!preg_match_all('~\d+/\d+/\d+-\d+/(\d{4})/\d+~u', (string)$tn, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return '';
    foreach ($mm as $m) {
        $before = mb_strcut((string)$tn, max(0, $m[0][1] - 120), min(120, $m[0][1]), "UTF-8");
        $line = mb_substr($before, max(0, mb_strrpos($before, "\n") === false ? 0 : mb_strrpos($before, "\n")));
        if (pb_m('سال\s*قبل|نامه\s*قبل', $line)) continue;
        $y = (int)$m[1][0];
        if ($y > $by) { $by = $y; $best = $m[0][0]; }
    }
    if ($best === '' && preg_match('~\d+/\d+/\d+-\d+/\d+/\d+~u', (string)$tn, $m)) $best = $m[0];
    return $best;
}

// ---- همان منطقِ context_processor.extract_insurance_data ----
function pb_extract_insurance_data($raw) {
    $tn = pb_norm($raw);
    $d = ['ins_company' => 'ناشناخته', 'ins_type' => 'ناشناخته', 'policy_num' => '', 'insured_name' => '', 'vin' => '',
          'plate' => '', 'national_id' => '', 'personnel_id' => '', 'company_name' => '', 'premium' => ''];
    if (mb_strpos($tn, 'شماره پرسنلی') !== false && mb_strpos($tn, 'شاغل در') !== false) { $d['ins_type'] = 'معرفی‌نامه'; return $d; }
    $head = mb_substr($tn, 0, 800);
    if (mb_strpos(mb_strtolower($tn), 'pasargadinsurance') !== false || mb_strpos($head, 'پاسارگاد') !== false) $d['ins_company'] = 'پاسارگاد';
    elseif (mb_strpos($tn, '10103858742') !== false || mb_strpos($head, 'بیمه ایران') !== false) $d['ins_company'] = 'ایران';
    if (mb_strpos($tn, 'ثالث') !== false || mb_strpos($tn, 'ثالت') !== false || mb_strpos($tn, 'تعهد جانی') !== false || mb_strpos($tn, 'حوادث راننده') !== false) $d['ins_type'] = 'ثالث';
    elseif (mb_strpos($tn, 'بدنه') !== false || mb_strpos($tn, 'ارزش وسیله نقلیه') !== false) $d['ins_type'] = 'بدنه';

    $pn = '';
    $co = $d['ins_company']; $ty = $d['ins_type'];
    if ($co === 'ایران' && $ty === 'ثالث') { if (pb_m('(\d{4}/\d{1,2}/\d{1,2}/\d+/\d+/\d+)', $tn, $m)) $pn = $m[1]; }
    elseif ($co === 'ایران' && $ty === 'بدنه') { if (pb_m('(\d{4}/\d+/\d+/\d+/\d+)', $tn, $m)) $pn = $m[1]; }
    elseif ($co === 'پاسارگاد' && $ty === 'ثالث') {
        if (pb_m('([\d/]+-)\s*:\s*شماره\s*بیمه\s*نامه[\s\n]*([\d/]+)', $tn, $m)) $pn = $m[1] . $m[2];
        if (!$pn) $pn = pb_pick_policy_num($tn);
    } elseif ($co === 'پاسارگاد' && $ty === 'بدنه') { $pn = pb_pick_policy_num($tn); }
    if (!$pn && pb_m('شماره\s*بیمه\s*نامه\s*[:\n]*\s*([A-Za-z0-9/]+(?:-[A-Za-z0-9/]+)?)', $tn, $m)) $pn = $m[1];
    $d['policy_num'] = str_replace(' ', '', $pn);

    $in = '';
    if ($co === 'ایران' && $ty === 'ثالث') { if (pb_m('نام\s*([آ-یa-zA-Z\s]+?)\s*شخصیت', $tn, $m)) $in = pb_ustrip($m[1]); }
    elseif ($co === 'ایران' && $ty === 'بدنه') { if (pb_m('نام\s*بیمه\s*گ[ذز]ار\s*[:\n]+([آ-یa-zA-Z\s]+?)\s*(?:\n|\d{10,11}|کد)', $tn, $m)) $in = pb_ustrip($m[1]); }
    elseif ($co === 'پاسارگاد' && $ty === 'بدنه') { if (pb_m('(?:بیمه\s*گ[ذز]ار|شماره\s*بیمه\s*نامه)[\s\S]{1,150}?([آ-یa-zA-Z\s]+?)\s*\d{10,11}', $tn, $m)) $in = pb_ustrip($m[1]); }
    elseif ($co === 'پاسارگاد' && $ty === 'ثالث') { if (pb_m('بیمه\s*گ[ذز]ار\s*[:\n]*\s*([آ-یa-zA-Z0-9\s]+?)\s*(?:\n|کد\s*ملی|\d{10,11})', $tn, $m)) $in = pb_ustrip($m[1]); }
    if (!$in && pb_m('نام\s*بیمه\s*گ[ذز]ار\s*[:\n]+([آ-یa-zA-Z\s]{3,40}?)\s*(?:\n|\d)', $tn, $m)) $in = pb_ustrip($m[1]);
    if ($in !== '') {
        $prev = null;
        while ($prev !== $in) {
            $prev = $in;
            $in = pb_ustrip(pb_sub('^(?:جناب\s*آقا[یي]|سرکار\s*خانم|آقا[یي]\s*[/\\\\-]\s*خانم|آقا[یي]|خانم|شرکت|شماره\s*بیمه\s*نامه|بیمه\s*گ[ذز]ار|نام|جناب)\s*', '', $in));
        }
        $in = pb_ustrip(pb_sub('\s+', ' ', $in));
    }
    $d['insured_name'] = $in;

    if (pb_m('(?:VIN|شماره\s*شاسی)\s*[:\n]*\s*([A-Za-z0-9]{5,17})', $tn, $m, 'i') || pb_m('([A-Za-z0-9]{5,17})\s*[:\n]*\s*(?:شماره\s*شاسی|VIN)', $tn, $m, 'i')) $d['vin'] = $m[1];
    elseif (pb_m('\b(?=[A-Za-z0-9]*[A-Za-z])(?=[A-Za-z0-9]*\d)[A-Za-z0-9]{17}\b', $tn, $m)) $d['vin'] = $m[0];

    if (!($co === 'ایران' && $ty === 'ثالث')) {
        $pt = str_replace("\n", ' ', $tn);
        if (pb_m('(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})[^\d]{0,10}ایران\s*(\d{2})', $pt, $m)) $d['plate'] = "{$m[4]}ایران {$m[3]} {$m[2]} {$m[1]}";
        elseif (pb_m('(\d{3})\s*([آ-یa-zA-Z])\s*(\d{2})[^\d]{0,10}ایران\s*(\d{2})', $pt, $m)) $d['plate'] = "{$m[4]}ایران {$m[1]} {$m[2]} {$m[3]}";
        elseif (pb_m('(\d{2})[^\d]{0,10}ایران\s*(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})', $pt, $m)) $d['plate'] = "{$m[1]}ایران {$m[4]} {$m[3]} {$m[2]}";
        elseif (pb_m('(\d{2})\s*ایران\s*(\d{2})\s*([آ-یa-zA-Z])\s*(\d{3})', $pt, $m)) $d['plate'] = "{$m[1]}ایران {$m[4]} {$m[3]} {$m[2]}";
        elseif (pb_m('(\d{2})\s*ایران\s*(\d{3})\s*([آ-یa-zA-Z])\s*(\d{2})(?!\d)', $pt, $m)) $d['plate'] = "{$m[1]}ایران {$m[2]} {$m[3]} {$m[4]}";
        elseif (($mp = pb_parse_moto_plate($pt)) !== '') $d['plate'] = $mp;
    }

    $best = 0;
    $lbl = '(?:حق\s*بیمه(?:\s*(?:قابل\s*پرداخت|کل|نهایی|پرداختی))?|جمع\s*کل|مبلغ\s*قابل\s*پرداخت)';
    foreach ([$lbl . '[^\d\n]{0,25}([\d,]{5,})', '([\d,]{5,})[^\S\n]*:?[^\S\n]*' . $lbl] as $pat) {
        foreach (pb_all($pat, $tn) as $m) {
            $v = str_replace(',', '', $m[1]);
            if ($v === '' || !ctype_digit($v)) continue;
            $v = (int)$v;
            if ($v >= 10000 && $v <= 1000000000000 && $v > $best) $best = $v;
        }
    }
    if ($best) $d['premium'] = (string)$best;
    return $d;
}

const PB_DATE_RX = '(1[34]\d{2})/(\d{1,2})/(\d{1,2})';

function pb_is_label($sp) { return mb_strpos($sp['t'], ':') !== false; }

function pb_same_row($a, $b, $ratio = 0.4) {
    $top = max($a['y0'], $b['y0']); $bot = min($a['y1'], $b['y1']);
    $h = min($a['y1'] - $a['y0'], $b['y1'] - $b['y0']) ?: 1;
    return ($bot - $top) / $h >= $ratio;
}

function pb_find_labels($spans, $pattern) {
    $o = [];
    foreach ($spans as $sp) if (pb_is_label($sp) && pb_m($pattern, $sp['t'])) $o[] = $sp;
    return $o;
}

function pb_row_values($spans, $label, $leftOnly = false) {
    $o = [];
    foreach ($spans as $sp) {
        if ($sp['i'] === $label['i'] || !pb_same_row($sp, $label)) continue;
        if (pb_is_label($sp) && !pb_m('\d{3}', $sp['t'])) continue;
        if ($leftOnly && ($sp['x0'] + $sp['x1']) / 2 > $label['x0'] + 2) continue;
        $o[] = $sp;
    }
    return pb_sort($o, function ($sp) { return -$sp['x1']; });
}

function pb_inline_kv($spans, $label) {
    foreach ($spans as $sp) {
        $t = $sp['t'];
        if (mb_strpos($t, ':') === false) continue;
        if (pb_m('^\s*(?:' . $label . ')\s*:\s*(.+?)\s*$', $t, $m) || pb_m('^\s*(.+?)\s*:\s*(?:' . $label . ')\s*$', $t, $m)) {
            $v = trim($m[1], ' -:');
            if ($v !== '' && mb_strpos($v, ':') === false && !pb_full('[-\s.]*', $v)) return $v;
        }
    }
    return '';
}

function pb_dates_in($text) {
    $o = [];
    foreach (pb_all(PB_DATE_RX, $text) as $m) $o[] = sprintf('%s/%02d/%02d', $m[1], (int)$m[2], (int)$m[3]);
    return $o;
}

function pb_row_dates($spans, $label) {
    $ds = pb_dates_in($label['t']);
    if ($ds) return $ds;
    $o = [];
    foreach (pb_row_values($spans, $label) as $v) {
        if (mb_strpos($v['t'], ':') !== false && pb_m('تاریخ|مدت\s*بیمه|سررسید|مورخ', $v['t'])) continue;
        $o = array_merge($o, pb_dates_in($v['t']));
    }
    return $o;
}

function pb_clean_label_text($t) { return mb_strpos($t, ':') !== false ? pb_sub('[^:]*:', ' ', $t) : $t; }

function pb_money_values($text) {
    $o = [];
    foreach (pb_all('(?<![\d/])(\d{1,3}(?:[,٬.]\d{3})+|\d{5,})(?![\d/])', $text) as $m) {
        $v = pb_sub('[,٬.]', '', $m[1]);
        if (ctype_digit($v)) $o[] = (int)$v;
    }
    return $o;
}

const PB_FA_NUM = ['صفر' => 0, 'یک' => 1, 'دو' => 2, 'سه' => 3, 'چهار' => 4, 'پنج' => 5, 'شش' => 6, 'شیش' => 6, 'هفت' => 7, 'هشت' => 8, 'نه' => 9, 'ده' => 10,
    'یازده' => 11, 'دوازده' => 12, 'سیزده' => 13, 'چهارده' => 14, 'پانزده' => 15, 'پونزده' => 15, 'شانزده' => 16, 'هفده' => 17, 'هجده' => 18, 'هیجده' => 18,
    'نوزده' => 19, 'بیست' => 20, 'سی' => 30, 'چهل' => 40, 'پنجاه' => 50, 'شصت' => 60, 'هفتاد' => 70, 'هشتاد' => 80, 'نود' => 90,
    'صد' => 100, 'یکصد' => 100, 'دویست' => 200, 'سیصد' => 300, 'چهارصد' => 400, 'پانصد' => 500, 'ششصد' => 600, 'هفتصد' => 700, 'هشتصد' => 800, 'نهصد' => 900];
const PB_FA_SCALE = ['هزار' => 1000, 'میلیون' => 1000000, 'ملیون' => 1000000, 'میلیارد' => 1000000000, 'ملیارد' => 1000000000, 'بیلیون' => 1000000000];

function pb_fa_words_to_int($text) {
    $toks = [];
    foreach (preg_split(pb_rx('[\s\x{200c}]+'), pb_sub('ریال|تومان|حداکثر|تا', ' ', (string)$text)) as $t) {
        if ($t === '' || $t === 'و') continue;
        if (!isset(PB_FA_NUM[$t]) && !isset(PB_FA_SCALE[$t]) && mb_substr($t, -1) === 'و') {
            $tt = mb_substr($t, 0, -1);
            if (isset(PB_FA_NUM[$tt]) || isset(PB_FA_SCALE[$tt])) $t = $tt;
        }
        $toks[] = $t;
    }
    $total = 0; $cur = 0; $seen = false;
    foreach ($toks as $t) {
        if (pb_full('\d+', $t)) { $cur += (int)$t; $seen = true; }
        elseif (isset(PB_FA_NUM[$t])) { $cur += PB_FA_NUM[$t]; $seen = true; }
        elseif (isset(PB_FA_SCALE[$t])) { $total += ($cur ?: 1) * PB_FA_SCALE[$t]; $cur = 0; $seen = true; }
        elseif ($seen) break;
    }
    return $seen ? $total + $cur : 0;
}

function pb_amount_in($text) {
    $vals = array_values(array_filter(pb_money_values($text), function ($v) { return $v >= 1000; }));
    return $vals ? max($vals) : pb_fa_words_to_int($text);
}

function pb_norm_phone($t) {
    $t = pb_sub('[\s\-()]', '', (string)$t);
    return pb_m('(?<!\d)(0\d{7,10})(?!\d)', $t, $m) ? $m[1] : '';
}

function pb_parse_plate($text) {
    $t = str_replace(["\u{200e}", "\u{200f}"], '', (string)$text);
    $L = '([آ-یa-zA-Z]|الف)';
    if (pb_m('(\d{2})\s*ایران\s*(\d{2})\s*' . $L . '\s*(\d{3})', $t, $m)) return "{$m[1]}ایران {$m[4]} {$m[3]} {$m[2]}";
    if (pb_m('(\d{2})\s*' . $L . '\s*(\d{3})[^\d]{0,10}ایران\s*(\d{2})', $t, $m)) return "{$m[4]}ایران {$m[3]} {$m[2]} {$m[1]}";
    if (pb_m('(\d{3})\s*' . $L . '\s*(\d{2})[^\d]{0,10}ایران\s*(\d{2})', $t, $m)) return "{$m[4]}ایران {$m[1]} {$m[2]} {$m[3]}";
    if (pb_m('(\d{2})[^\d]{0,10}ایران\s*(\d{2})\s*' . $L . '\s*(\d{3})', $t, $m)) return "{$m[1]}ایران {$m[4]} {$m[3]} {$m[2]}";
    if (pb_m('(\d{2})\s*ایران\s*(\d{3})\s*' . $L . '\s*(\d{2})(?!\d)', $t, $m)) return "{$m[1]}ایران {$m[2]} {$m[3]} {$m[4]}";
    return pb_parse_moto_plate($t);
}

function pb_parse_moto_plate($t) {
    $t = str_replace(["\u{200e}", "\u{200f}"], '', (string)$t);
    if (!pb_m('(?<!\d)(\d{3})\s{0,3}ایران\s{0,3}(\d{5})(?!\d)', $t, $m) && !pb_m('(?<!\d)(\d{5})\s{0,3}ایران\s{0,3}(\d{3})(?!\d)', $t, $m)) return '';
    [$a, $b] = [$m[1], $m[2]];
    [$city, $num] = strlen($a) === 3 ? [$a, $b] : [$b, $a];
    return $city . '-' . $num;
}

function pb_footer_counter($spans, $pageH) {
    foreach ($spans as $sp) {
        if ($sp['y0'] < $pageH * 0.88) continue;
        if (pb_full('(\d{1,3})\s*از\s*(\d{1,3})', $sp['t'], $m) || pb_full('صفحه\s*(\d{1,3})\s*از\s*(\d{1,3})', $sp['t'], $m)) {
            $a = (int)$m[1]; $b = (int)$m[2];
            return [min($a, $b), max($a, $b)];
        }
    }
    return null;
}

function pb_is_payment_page($text) {
    return pb_m('سند\s*دریافت|اعلامیه\s*آخرین\s*وضعیت\s*حق\s*بیمه|رسید\s*پرداخت|فیش\s*واریز|پرداخت\s*الکترونیک', mb_substr(pb_norm($text), 0, 600));
}
const PB_RECEIPT_RX = 'سند\s*دریافت|رسید\s*پرداخت|فیش\s*واریز|پرداخت\s*الکترونیک';

function pb_receipt_info($text) {
    $t = pb_norm($text);
    $info = [];
    $ds = pb_dates_in($t);
    if ($ds) $info['date'] = $ds[0];
    $amts = [];
    foreach (pb_all('(?<!\d)(\d{1,3}(?:[,٬]\d{3})+)(?!\d)', $t) as $m) { $a = (int)str_replace([',', '٬'], '', $m[1]); if ($a >= 1000 && $a <= 1000000000000) $amts[] = $a; }
    if ($amts) $info['amount'] = max($amts);
    if (pb_m('شماره\s*فیش\s*:?\s*(\d{10,24})', $t, $m) || pb_m('(\d{10,24})\s*:?\s*کد\s*شناسه', $t, $m) || pb_m('(?<!\d)(0{2,}\d{12,20})(?!\d)', $t, $m)) $info['fish'] = $m[1];
    $longs = array_map(function ($m) { return $m[1]; }, pb_all('(?<!\d)(\d{14,24})(?!\d)', $t));
    $cnt = array_count_values($longs);
    $rep = array_values(array_filter($longs, function ($x) use ($cnt) { return $cnt[$x] >= 2; }));
    $info['shenase'] = $rep ? $rep[0] : ($info['fish'] ?? null);
    foreach (explode("\n", $t) as $line) {
        $ln = pb_ustrip($line);
        if (pb_full('\d{8,13}', $ln) && !pb_m('کد\s*پستی|ملی|تلفن', $line)) { $info['account'] = $ln; break; }
    }
    if (pb_m('شعبه\s*([^\n\-–]{2,40}?)\s*[-–]\s*(بانک\s*[آ-ی]+)', $t, $mb) || pb_m('(بانک\s*[آ-ی]+)\s*[-–]\s*شعبه\s*([^\n]{2,40})', $t, $mb)) {
        $g1 = pb_ustrip($mb[1]); $g2 = pb_ustrip($mb[2]);
        [$bank, $branch] = mb_strpos($g2, 'بانک') === 0 ? [$g2, $g1] : [$g1, $g2];
        $code = pb_m('\((\d+/\d+)\)', $t, $cm) ? $cm[1] : null;
        $info['bank'] = pb_sub('\s+', ' ', $bank) . ' - شعبه ' . pb_trim(pb_ustrip(pb_sub('\s+', ' ', $branch)), ' -–') . ($code && mb_strpos($branch, $code) === false ? ' (' . $code . ')' : '');
    }
    if (pb_m('شماره\s*ملی\s*:?\s*(\d{10,11})', $t, $mn) || pb_m('(\d{10,11})\s*:?\s*شماره\s*ملی', $t, $mn)) $info['payer_nid'] = $mn[1];
    $lines = array_values(array_filter(array_map('pb_ustrip', explode("\n", $t)), function ($x) { return $x !== ''; }));
    foreach ($lines as $k => $ln) {
        if (pb_m('ریال$', $ln) && !pb_m('\d', $ln) && $k + 1 < count($lines)) {
            $nxt = $lines[$k + 1];
            if (!pb_m('\d', $nxt) && mb_strlen($nxt) > 3) $info['payer'] = $nxt;
            break;
        }
    }
    return $info;
}

function pb_statement_rows($text) {
    $t = pb_norm($text);
    $lines = array_map('pb_ustrip', explode("\n", $t));
    $rows = [];
    foreach ($lines as $k => $ln) {
        if (!pb_full('\d{14,24}', $ln)) continue;
        $row = ['fish' => $ln];
        foreach (array_slice($lines, $k + 1, 15) as $nxt) {
            if (pb_full('\d{14,24}', $nxt)) break;
            if (!isset($row['due'])) { $d = pb_dates_in($nxt); if ($d) $row['due'] = $d[0]; }
            if (!isset($row['amount']) && pb_full('(\d{1,3}(?:[,٬]\d{3})+)', $nxt, $a)) $row['amount'] = (int)str_replace([',', '٬'], '', $a[1]);
            if (!isset($row['kind']) && pb_full('(اقساط|قسط|نقد|نقدی|چک|سفته|حواله|واریز\s*نقدی(?:\s*به\s*حساب)?)', $nxt, $kd)) {
                $w = $kd[1];
                $row['kind'] = mb_strpos($w, 'نقد') !== false ? 'نقد' : (in_array($w, ['اقساط', 'قسط'], true) ? 'اقساط' : $w);
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

function pb_contract_info(array $texts) {
    foreach ($texts as $tx) {
        $t = pb_norm($tx);
        if (pb_m('(\d{3,6}/\d{2,7}/1[34]\d\d)\s*:?\s*\.?\s*شماره\s*قرارداد', $t, $m) || pb_m('شماره\s*قرارداد\s*:?\s*(\d{3,6}/\d{2,7}/1[34]\d\d)', $t, $m)) return [$m[1], explode('/', $m[1])[0]];
    }
    return [null, null];
}

function pb_contract_key_from_policy($pn) {
    return pb_m('\d+/\d+/(\d{3,6})\s*-', pb_norm((string)$pn), $m) ? $m[1] : null;
}

const PB_BAD_TEXT = ':|شماره|قرارداد|ریال|تعهد|خسارت|بیمه\s*نامه|مبلغ|حداکثر|\d+/\d+';

// شناسایی با جای متن (همان extract_layout)
function pb_extract_layout(array $sp, $pageW, $pageH) {
    if (!$sp) return [];
    $d = [];
    $alltext = implode("\n", array_map(function ($s) { return $s['t']; }, $sp));
    if (mb_strpos(mb_strtolower($alltext), 'pasargad') !== false || mb_strpos($alltext, 'پاسارگاد') !== false) $d['ins_company'] = 'پاسارگاد';
    elseif (mb_strpos($alltext, 'بیمه ایران') !== false || mb_strpos(mb_strtolower($alltext), 'iraninsurance') !== false) $d['ins_company'] = 'ایران';
    $heads = pb_sort(array_filter($sp, function ($s) use ($pageH) { return $s['y0'] < $pageH * 0.2 && mb_strpos($s['t'], 'بیمه') !== false; }), function ($s) { return -$s['size']; });
    foreach (array_slice($heads, 0, 3) as $h) {
        if (mb_strpos($h['t'], 'ثالث') !== false) { $d['ins_type'] = 'ثالث'; break; }
        if (mb_strpos($h['t'], 'بدنه') !== false) { $d['ins_type'] = 'بدنه'; break; }
    }

    // شماره‌ی بیمه‌نامه: همه‌ی نامزدها؛ نامزدی که در ردیفِ «بیمه‌نامه‌ی سال قبل» است کنار می‌رود (در ثالث شماره‌ی قبلی هم با همین الگو
    // در صفحه هست و گاهی زودتر از شماره‌ی اصلی می‌آید). اگر باز چند نامزد ماند: نزدیک‌ترین به برچسبِ «شماره بیمه‌نامه»، وگرنه سالِ تازه‌تر.
    $pnRx = '\d{1,2}/\d{4,6}/\d{3,5}-\d{1,3}/\d{4}/\d+';
    $prevLabels = pb_find_labels($sp, 'سال\s*قبل|بیمه\s*نامه\s*قبل(?:ی)?');
    $isPrevRow = function ($s) use ($prevLabels) {
        if (pb_m('سال\s*قبل|نامه\s*قبل', $s['t'])) return true;
        foreach ($prevLabels as $lb) if ($s['i'] === $lb['i'] || pb_same_row($s, $lb)) return true;
        return false;
    };
    $cands = [];
    foreach ($sp as $s) foreach (pb_all($pnRx, $s['t']) as $mm) $cands[] = ['n' => $mm[0], 's' => $s, 'prev' => $isPrevRow($s)];
    $main = array_values(array_filter($cands, function ($c) { return !$c['prev']; }));
    if ($main) {
        $pnLabels = array_values(array_filter(pb_find_labels($sp, 'شماره\s*بیمه\s*نامه'), function ($lb) { return !pb_m('سال\s*قبل|قبل', $lb['t']); }));
        $year = function ($n) { return pb_m('/(1[34]\d\d)/\d+$', $n, $y) ? (int)$y[1] : 0; };
        usort($main, function ($a, $b) use ($pnLabels, $year) {
            $near = function ($c) use ($pnLabels) {
                foreach ($pnLabels as $lb) if ($c['s']['i'] === $lb['i'] || pb_same_row($c['s'], $lb)) return 0;
                return 1;
            };
            return [$near($a), -$year($a['n'])] <=> [$near($b), -$year($b['n'])];
        });
        $d['policy_num'] = $main[0]['n'];
    } elseif (count($cands) === 1 && !$prevLabels) $d['policy_num'] = $cands[0]['n'];
    if (empty($d['policy_num'])) {
        foreach (pb_find_labels($sp, 'شماره\s*بیمه\s*نامه') as $lb) {
            foreach (pb_row_values($sp, $lb) as $v) {
                if (mb_strpos($v['t'], 'سال قبل') !== false) continue;
                if (pb_m('[\dA-Za-z][\dA-Za-z/\-]{6,}', pb_clean_label_text($v['t']), $m) && !pb_full(PB_DATE_RX, $m[0])) { $d['policy_num'] = $m[0]; break; }
            }
            if (!empty($d['policy_num'])) break;
        }
    }

    foreach (pb_find_labels($sp, 'شماره\s*(?:پلاک|انتظامی)') as $lb) {
        foreach (pb_row_values($sp, $lb) as $v) { $pl = pb_parse_plate($v['t']); if ($pl !== '') { $d['plate'] = $pl; break; } }
        if (!empty($d['plate'])) break;
    }
    if (empty($d['plate'])) foreach ($sp as $s) { $pl = pb_parse_plate($s['t']); if ($pl !== '') { $d['plate'] = $pl; break; } }

    foreach (pb_find_labels($sp, '^:?\s*(?:نام\s*)?بیمه\s*گ[ذز]ار\s*:?\s*$|^:?\s*(?:نام\s*)?بیمه\s*گ[ذز]ار\s*:') as $lb) {
        $parts = [];
        foreach (pb_row_values($sp, $lb) as $v) {
            $t = pb_clean_label_text($v['t']);
            if (pb_m('(?<!\d)(\d{10,11})(?!\d)', $t, $nid) && empty($d['national_id'])) $d['national_id'] = $nid[1];
            $name = pb_ustrip(pb_sub('[\d:/\-]+', ' ', $t));
            if ($name !== '' && pb_m('[آ-ی]{2,}', $name)) $parts[] = $name;
            if ($parts) break;
        }
        if ($parts) { $d['insured_name'] = pb_ustrip(pb_sub('\s+', ' ', $parts[0])); break; }
    }
    if (empty($d['national_id'])) {
        foreach (pb_find_labels($sp, '(?:کد|شناسه)\s*ملی') as $lb) {
            foreach (pb_row_values($sp, $lb) as $v) if (pb_m('(?<!\d)(\d{10,11})(?!\d)', $v['t'], $m)) { $d['national_id'] = $m[1]; break; }
            if (!empty($d['national_id'])) break;
        }
    }

    foreach (['مبلغ\s*قابل\s*پرداخت', 'حق\s*بیمه\s*(?:قابل\s*پرداخت|نهایی)', 'جمع\s*(?:کل\s*)?حق\s*بیمه', '^\s*:?\s*جمع\s*کل\s*:?\s*$', 'حق\s*بیمه\s*کل'] as $prx) {
        foreach (pb_find_labels($sp, $prx) as $lb) {
            $vals = pb_money_values(pb_clean_label_text($lb['t']));
            foreach (pb_row_values($sp, $lb) as $v) $vals = array_merge($vals, pb_money_values($v['t']));
            $vals = array_values(array_filter($vals, function ($x) { return $x >= 10000 && $x <= 10000000000000; }));
            if ($vals) { $d['premium'] = (string)max($vals); break; }
        }
        if (!empty($d['premium'])) break;
    }

    foreach ($sp as $s_) {
        if (pb_m('^\s*سیستم\s*:\s*(.+?)\s*تیپ\s*:\s*(.+?)\s*$', $s_['t'], $m)) {
            $sysv = trim($m[1], ' -'); $tipv = trim($m[2], ' -');
            if (pb_m('^(.*?[^\d\s])\s*(\d+)$', $sysv, $mm) && !pb_m('\d', $tipv)) { $sysv = $mm[1]; $tipv = $tipv . ' ' . $mm[2]; }
            if ($sysv !== '' && mb_strpos($sysv, ':') === false) $d['car_system'] = $sysv;
            if ($tipv !== '' && mb_strpos($tipv, ':') === false) $d['car_tip'] = $tipv;
            break;
        }
    }
    foreach ([['car_kind', 'نوع'], ['car_system', 'سیستم'], ['car_tip', 'تیپ'], ['model_year', 'مدل'], ['color', 'رنگ'],
              ['usage', 'مورد\s*استفاده|کاربری'], ['engine_no', 'شماره\s*موتور'], ['chassis', 'شماره\s*شاسی'],
              ['capacity', 'ظرفیت'], ['cylinders', 'تعداد\s*سیلندر']] as [$key, $lab]) {
        $v = pb_inline_kv($sp, $lab);
        if ($v !== '' && !(in_array($key, ['car_system', 'car_tip'], true) && !empty($d[$key]))) $d[$key] = $v;
    }
    if (!empty($d['chassis'])) {
        $tok = pb_sub('[^A-Za-z0-9]', '', $d['chassis']);
        unset($d['chassis']);
        if ($tok !== '') $d['chassis_no'] = strtoupper($tok);
    } else unset($d['chassis']);
    if ((pb_m('VIN\s*[:：]?\s*([A-Za-z0-9]{17})(?![A-Za-z0-9])', $alltext, $mv) || pb_m('(?<![A-Za-z0-9])([A-Za-z0-9]{17})\s*[:：]\s*VIN', $alltext, $mv))
        && pb_m('[A-Za-z]', $mv[1]) && pb_m('\d', $mv[1])) $d['vin'] = strtoupper($mv[1]);
    if (!empty($d['engine_no'])) { $e = strtoupper(pb_sub('[^A-Za-z0-9]', '', $d['engine_no'])); if ($e !== '') $d['engine_no'] = $e; }   // پایتون: «X or d.pop()» مقدارِ قبلی را نگه می‌داشت
    if (!empty($d['model_year'])) { if (pb_m('(1[34]\d{2}|19\d{2}|20\d{2})', $d['model_year'], $m)) $d['model_year'] = $m[1]; else unset($d['model_year']); }
    $inlineCar = !empty($d['car_system']) || !empty($d['car_tip']);
    if ($inlineCar) $d['car_name'] = trim(implode(' ', array_filter([$d['car_system'] ?? '', $d['car_tip'] ?? ''], 'strlen')));
    if (empty($d['national_id']) && pb_m('(?<!\d)(\d{10})\s*:\s*کد\s*ملی', $alltext, $m)) $d['national_id'] = $m[1];

    foreach (pb_find_labels($sp, 'شماره\s*موتور.*شماره\s*شاسی|شماره\s*شاسی.*شماره\s*موتور') as $lb) {
        $toks = [];
        foreach (pb_row_values($sp, $lb) as $v) if (pb_m('\d', $v['t'])) $toks[] = strtoupper(pb_sub('[^A-Za-z0-9]', '', $v['t']));
        $toks = array_values(array_filter($toks, function ($t) { return strlen($t) >= 4; }));
        $fe = mb_strpos($lb['t'], 'موتور'); $fs = mb_strpos($lb['t'], 'شاسی');
        $firstEngine = ($fe === false ? -1 : $fe) < ($fs === false ? -1 : $fs);
        if (count($toks) >= 2) {
            [$eng, $ch] = $firstEngine ? [$toks[0], $toks[1]] : [$toks[1], $toks[0]];
            if (empty($d['engine_no'])) $d['engine_no'] = $eng;
            if (empty($d['chassis_no'])) $d['chassis_no'] = $ch;
        }
        break;
    }
    foreach (pb_find_labels($sp, 'شماره\s*(?:شاسی|موتور)|VIN') as $lb) {
        $lt = $lb['t'];
        $isVin = pb_m('VIN', $lt) && !pb_m('شاسی|موتور', $lt);
        $isCh = mb_strpos($lt, 'شاسی') !== false && mb_strpos($lt, 'موتور') === false && !$isVin;
        foreach (pb_row_values($sp, $lb) as $v) {
            foreach (pb_all('[A-Za-z0-9][A-Za-z0-9.*\-/]{4,40}[A-Za-z0-9*]', $v['t']) as $xm) {
                $tok = pb_sub('[^A-Za-z0-9]', '', $xm[0]);
                if (strlen($tok) < 6 || strlen($tok) > 30) continue;
                if (!pb_m('\d', $tok)) continue;
                $tok = strtoupper($tok);
                $vinlike = strlen($tok) === 17 && pb_m('[A-Z]', $tok);
                if ($isVin) { if ($vinlike && empty($d['vin'])) $d['vin'] = $tok; }
                elseif ($isCh) { if (empty($d['chassis_no']) && $tok !== ($d['engine_no'] ?? null)) $d['chassis_no'] = $tok; }
                elseif ($vinlike && empty($d['chassis_no']) && $tok !== ($d['vin'] ?? null)) $d['chassis_no'] = $tok;
                elseif (empty($d['engine_no']) && $tok !== ($d['vin'] ?? null) && $tok !== ($d['chassis_no'] ?? null)) $d['engine_no'] = $tok;
            }
        }
        if (!empty($d['chassis_no']) && !empty($d['engine_no']) && !empty($d['vin'])) break;
    }
    if (empty($d['vin']) && !empty($d['chassis_no']) && strlen($d['chassis_no']) === 17 && pb_m('[A-Z]', $d['chassis_no'])) $d['vin'] = $d['chassis_no'];

    if (!$inlineCar) {
        foreach (pb_find_labels($sp, 'سیستم') as $lb) {
            $near = $pageW * 0.35;
            $vals = [];
            foreach (pb_row_values($sp, $lb) as $v) if (!pb_full('[-\s]*', $v['t']) && abs($v['x1'] - $lb['x0']) < $near && !pb_m(PB_BAD_TEXT, $v['t'])) $vals[] = $v['t'];
            if ($vals) {
                if (count($vals) >= 3) { $d['car_kind'] = $vals[0]; $d['car_system'] = $vals[1]; $d['car_tip'] = $vals[2]; }
                elseif (count($vals) === 2) { $d['car_system'] = $vals[0]; $d['car_tip'] = $vals[1]; }
                else $d['car_system'] = $vals[0];
                $d['car_name'] = trim(implode(' ', array_filter([$d['car_system'] ?? '', $d['car_tip'] ?? ''], 'strlen')));
                break;
            }
        }
    }
    if (empty($d['model_year'])) {
        foreach (pb_find_labels($sp, '^:?\s*مدل\s*:?$|:\s*مدل') as $lb) {
            foreach (pb_row_values($sp, $lb) as $v) if (pb_m('(?<!\d)(1[34]\d{2}|19\d{2}|20\d{2})(?!\d)', $v['t'], $m)) { $d['model_year'] = $m[1]; break; }
            if (!empty($d['model_year'])) break;
        }
    }
    if (empty($d['color'])) {
        foreach (pb_find_labels($sp, 'رنگ') as $lb) {
            $vals = pb_row_values($sp, $lb);
            if ($vals) { $d['color'] = $vals[0]['t']; if (count($vals) > 1) $d['usage'] = $vals[1]['t']; }
            break;
        }
    }

    foreach (pb_find_labels($sp, 'ارزش\s*وسیله\s*نقلیه') as $lb) {
        $vals = []; foreach (pb_row_values($sp, $lb, true) as $v) $vals = array_merge($vals, pb_money_values($v['t']));
        if ($vals) { $d['car_value'] = (string)max($vals); break; }
    }
    foreach (pb_find_labels($sp, 'تعهد(?:ات)?\s*مالی') as $lb) {
        $vals = []; foreach (pb_row_values($sp, $lb) as $v) $vals = array_merge($vals, pb_money_values($v['t']));
        if ($vals) { $d['liability'] = (string)max($vals); break; }
    }

    foreach (pb_find_labels($sp, 'مدت\s*بیمه|تاریخ\s*(?:شروع|پایان)') as $lb) {
        $ds = pb_dates_in($lb['t']);
        foreach (pb_row_values($sp, $lb) as $v) $ds = array_merge($ds, pb_dates_in($v['t']));
        $ds = array_values(array_unique($ds)); sort($ds, SORT_STRING);
        if (count($ds) >= 2) { $d['start_date'] = $ds[0]; $d['end_date'] = end($ds); break; }
        if (count($ds) === 1 && empty($d['start_date'])) $d['start_date'] = $ds[0];
    }
    foreach (pb_find_labels($sp, 'تاریخ\s*صدور') as $lb) { $ds = pb_row_dates($sp, $lb); if ($ds) { $d['issue_date'] = $ds[0]; break; } }
    foreach (pb_find_labels($sp, 'تاریخ\s*انقضا') as $lb) { $ds = pb_row_dates($sp, $lb); if ($ds) { $d['prev_expiry'] = $ds[0]; break; } }
    foreach (pb_find_labels($sp, 'بیمه\s*نامه\s*سال\s*قبل') as $lb) {
        $own = pb_ustrip(pb_sub(':?\s*بیمه\s*نامه\s*سال\s*قبل\s*:?', ' ', $lb['t']));
        if (pb_m('\d[\d/\-]{6,}\d', $own, $m)) {
            $d['prev_policy'] = $m[0];
            $ins = trim(pb_ustrip(str_replace('سهامی', ' ', pb_sub('[\d/\-]+', ' ', $own))), ' -:');
            if ($ins !== '') $d['prev_insurer'] = pb_sub('\s+', ' ', $ins);
            break;
        }
        foreach (pb_row_values($sp, $lb) as $v) {
            if (mb_strpos($v['t'], ':') !== false && pb_m('شماره\s*بیمه\s*نامه|تاریخ|واحد', $v['t'])) continue;
            $t = $v['t'];
            if (!empty($d['policy_num']) && mb_strpos($t, $d['policy_num']) !== false) continue;
            if (pb_m('\d[\d/\-]{6,}\d', $t, $m)) {
                $d['prev_policy'] = $m[0];
                $ins = trim(pb_sub('[\d/\-]+', ' ', $t), ' -');
                if ($ins !== '') $d['prev_insurer'] = pb_sub('\s+', ' ', $ins);
                break;
            }
        }
        break;
    }
    // بیمه‌نامه‌ی قبلی هیچ‌وقت همان شماره‌ی خودِ بیمه‌نامه نیست
    if (!empty($d['prev_policy']) && !empty($d['policy_num']) && str_replace(' ', '', $d['prev_policy']) === str_replace(' ', '', $d['policy_num'])) {
        $other = array_values(array_filter($cands ?? [], function ($c) use ($d) { return $c['prev'] && $c['n'] !== $d['policy_num']; }));
        if ($other) $d['prev_policy'] = $other[0]['n']; else unset($d['prev_policy'], $d['prev_insurer']);
    }
    foreach (pb_find_labels($sp, 'شماره\s*بیمه\s*مرکزی|کد\s*یکتا') as $lb) {
        if (pb_m('\d{8,}', $lb['t'], $m)) { $d['central_no'] = $m[0]; break; }
        foreach (pb_row_values($sp, $lb) as $v) if (pb_m('\d{8,}', $v['t'], $m)) { $d['central_no'] = $m[0]; break; }
        break;
    }

    foreach ([['capacity', '^:?\s*ظرفیت\s*:?$'], ['cylinders', '^:?\s*تعداد\s*سیلندر\s*:?$'], ['cargo', '^:?\s*اتاق\s*بار\s*:?$']] as [$key, $lab]) {
        if (!empty($d[$key])) continue;
        foreach (pb_find_labels($sp, $lab) as $lb) {
            $vals = array_values(array_filter(pb_row_values($sp, $lb, true), function ($v) { return !pb_full('[-\s.]*', $v['t']); }));
            if ($vals) { $d[$key] = pb_ustrip($vals[0]['t']); break; }
        }
    }
    if (empty($d['cargo'])) { $v = pb_inline_kv($sp, 'اتاق\s*بار'); if ($v !== '') $d['cargo'] = $v; }
    if (!empty($d['capacity']) && pb_full('\s*(تن|نفر|کیلوگرم)\s*(\d+(?:[.,]\d+)?)\s*', $d['capacity'], $m)) $d['capacity'] = $m[2] . ' ' . $m[1];
    if (!empty($d['cylinders'])) { if (pb_m('\d+', $d['cylinders'], $m)) $d['cylinders'] = $m[0]; else unset($d['cylinders']); }

    // اطلاعاتِ بیمه‌گذار (تلفن، نشانی، کد پستی)
    $ins = array_values(array_filter($sp, function ($s) { return pb_m('بیمه\s*گ[ذز]ار\s*:|:\s*بیمه\s*گ[ذز]ار', $s['t']) && !pb_m('پیشنهاد', $s['t']); }));
    if ($ins) {
        $y0 = min(array_map(function ($s) { return $s['y0']; }, $ins));
        $block = array_values(array_filter($sp, function ($s) use ($y0) { return $y0 - 4 <= $s['y0'] && $s['y0'] <= $y0 + 62 && !pb_m('آدرس|واحد\s*صدور|دورنگار|ذینفع', $s['t']); }));
        $bset = []; foreach ($block as $s) $bset[$s['i']] = true;
        foreach ($block as $s) {
            if ((pb_m('([0-9][0-9\-\s()]{7,}[0-9])\s*:\s*تلفن', $s['t'], $m) || pb_m('تلفن\s*:\s*([0-9][0-9\-\s()]{7,}[0-9])', $s['t'], $m)) && pb_norm_phone($m[1]) !== '') { $d['phone'] = pb_norm_phone($m[1]); break; }
        }
        if (empty($d['phone'])) {
            foreach (array_filter($block, function ($s) { return pb_full('\s*:?\s*تلفن\s*:?\s*', $s['t']); }) as $lb) {
                foreach (pb_row_values($sp, $lb) as $v) if (isset($bset[$v['i']]) && pb_norm_phone($v['t']) !== '') { $d['phone'] = pb_norm_phone($v['t']); break; }
                if (!empty($d['phone'])) break;
            }
        }
        foreach (array_filter($block, function ($s) { return pb_m('کد\s*پستی', $s['t']); }) as $lb) {
            $vals = [];
            if (pb_m('(?<!\d)(\d{10})(?!\d)', $lb['t'], $m)) $vals = [$m[1]];
            else foreach (pb_row_values($sp, $lb) as $v) if (pb_m('(?<!\d)(\d{10})(?!\d)', $v['t'], $mm)) $vals[] = $mm[1];
            if ($vals) { $d['postal_code'] = $vals[0]; break; }
        }
        foreach (array_filter($block, function ($s) { return pb_m('نشانی', $s['t']); }) as $lb) {
            $parts = [];
            $t = $lb['t'];
            if (pb_m('نشانی\s*:\s*\S', $t)) {
                $pp = preg_split(pb_rx('نشانی\s*:'), $t, 2);
                $parts = [pb_ustrip($pp[1] ?? ''), pb_ustrip($pp[0])];
            } else {
                $row = array_values(array_filter(pb_row_values($sp, $lb, true), function ($v) { return pb_m('[آ-ی]{3,}', $v['t']) && mb_strpos($v['t'], ':') === false; }));
                if ($row) {
                    $first = $row[0];
                    $parts[] = $first['t'];
                    foreach ($sp as $v) {
                        if ($v['i'] !== $first['i'] && 4 < $v['y0'] - $first['y0'] && $v['y0'] - $first['y0'] < 20 && abs($v['x1'] - $first['x1']) < 8 && mb_strpos($v['t'], ':') === false
                            && pb_m('[آ-ی]', $v['t']) && pb_norm_phone($v['t']) === '') $parts[] = $v['t'];
                    }
                }
            }
            $parts = array_map(function ($line) {
                if (mb_strpos($line, '-') !== false) {
                    $segs = [];
                    foreach (explode('-', $line) as $x) { $x = pb_trim($x, ' ،,'); if ($x !== '') $segs[] = pb_sub('^(\d+)\s+(پلاک|طبقه|واحد|کوچه|خیابان|بلوک|ورودی)$', '$2 $1', $x); }
                    return implode('، ', array_reverse($segs));
                }
                return $line;
            }, $parts);
            $addr = implode(' ', array_filter($parts, 'strlen'));
            if (pb_m('^(.*?)\s*(استان\s.*)$', $addr, $m) && pb_ustrip($m[1]) !== '') $addr = $m[2] . ' ' . $m[1];
            $addr = pb_trim(pb_ustrip(pb_sub('\s+', ' ', $addr)), ' ،,-');
            if (mb_strlen($addr) >= 8) { $d['address'] = $addr; break; }
        }
    }

    $commit = function ($rx) use ($sp) {
        foreach (array_filter($sp, function ($s) use ($rx) { return pb_m($rx, $s['t']); }) as $lb) {
            $vals = [];
            foreach ($sp as $v) if ($v['i'] !== $lb['i'] && $v['x1'] < $lb['x0'] + 2 && pb_same_row($v, $lb, 0.2) && !pb_m('^\s*ریال\s*$', $v['t'])) $vals[] = pb_amount_in($v['t']);
            $vals = array_values(array_filter($vals, function ($v) { return $v >= 1000000 && $v <= 1000000000000; }));
            $own = pb_amount_in(pb_sub($rx, ' ', $lb['t']));
            if ($own >= 1000000 && $own <= 1000000000000) $vals[] = $own;
            if ($vals) return (string)max($vals);
        }
        return '';
    };
    if (empty($d['liability'])) $d['liability'] = $commit('خسارت\s*مالی|تعهد(?:ات)?\s*مالی');
    $d['diya'] = $commit('خسارت\s*بدنی|دیه');
    $d['driver_cover'] = $commit('حوادث\s*راننده\s*مسبب');

    $disc = [];
    foreach (array_filter($sp, function ($s) { return pb_full('\s*تخفیف\s*(ثالث|حوادث\s*راننده)\s*', $s['t']); }) as $lb) {
        $row = [];
        foreach ($sp as $v) if ($v['i'] !== $lb['i'] && pb_same_row($v, $lb, 0.3) && $v['x1'] < $lb['x0'] + 2 && $v['x0'] > $lb['x0'] - 160) $row[] = $v['t'];
        $row = implode(' ', $row);
        $pct = pb_m('(\d{1,3})\s*%', $row, $pm) ? $pm[1] : null;
        $yrs = pb_m('سال\s*(\d{1,2})|(\d{1,2})\s*سال', $row, $ym) ? (pb_g($ym, 1) ?? pb_g($ym, 2)) : null;
        if ($pct !== null || $yrs !== null) {
            $name = pb_ustrip(pb_sub('\s*تخفیف\s*', '', $lb['t']));
            $disc[] = $name . ': ' . ($yrs ? $yrs . ' سال' : '') . ($pct !== null ? ' (' . $pct . '%)' : '');
        }
    }
    if ($disc) $d['ncd'] = implode('، ', $disc);
    foreach (pb_find_labels($sp, 'وضعیت\s*بیمه\s*نامه\s*قبلی') as $lb) {
        $st = [];
        foreach ($sp as $v) if (pb_m('خسارت', $v['t']) && $v['y0'] - $lb['y0'] >= 0 && $v['y0'] - $lb['y0'] < 50 && abs($v['x1'] - $lb['x1']) < 10 && mb_strpos($v['t'], ':') === false) $st[] = $v['t'];
        if ($st) $d['prev_claims'] = implode('، ', $st);
        break;
    }

    foreach ([['total_value', '^:?\s*جمع\s*:?$'], ['trailer_value', 'ارزش\s*یدک'], ['extras_value', 'ارزش\s*وسایل\s*اضافی']] as [$key, $lab]) {
        foreach (pb_find_labels($sp, $lab) as $lb) {
            $vals = []; foreach (pb_row_values($sp, $lb, true) as $v) $vals = array_merge($vals, pb_money_values($v['t']));
            if ($vals && max($vals) > 0) $d[$key] = (string)max($vals);
            break;
        }
    }
    $sh = array_values(array_filter($sp, function ($s) { return pb_m('شرح\s*\(?\s*حق\s*بیمه', $s['t']); }));
    if ($sh) {
        $y0 = $sh[0]['y0'];
        $ends = []; foreach ($sp as $s) if ($s['y0'] > $y0 && pb_m('مبلغ\s*قابل\s*پرداخت', $s['t'])) $ends[] = $s['y0'];
        $end = ($ends ? min($ends) : $y0 + 260) - 3;
        $covers = [];
        $in = pb_sort(array_filter($sp, function ($s) use ($y0, $end) { return $y0 < $s['y0'] && $s['y0'] < $end; }), function ($s) { return $s['y0']; });
        foreach ($in as $s) {
            if (pb_m('تخفیف|تشدید|مالیات|عوارض|کسر|اضافه\s*هزار', $s['t'])) continue;
            $name = str_replace('.', ' ', str_replace('ریال', ' ', pb_sub('-?\d{1,3}(?:[,٬.]\d{3})+|-?\d{4,}', ' ', $s['t'])));
            $name = pb_sub('%?\d+%?|[()]', ' ', $name);
            $name = pb_trim(pb_ustrip(pb_sub('\s+', ' ', $name)), ' ،,-');
            $name = pb_sub('\s+تا$', '', $name);
            if (pb_m('[آ-ی]{3,}', $name) && $name !== 'ریال') $covers[] = $name;
        }
        if ($covers) $d['covers'] = implode('؛ ', $covers);
    }
    return $d;
}

function pb_clean_fields(array $d) {
    foreach (['car_kind', 'car_system', 'car_tip', 'car_name', 'color', 'usage', 'capacity', 'cargo', 'prev_insurer', 'insured_name'] as $k) {
        $v = pb_ustrip((string)($d[$k] ?? ''));
        if ($v === '') continue;
        $bad = mb_strlen($v) > 45 || ($k !== 'insured_name' && pb_m(PB_BAD_TEXT, $v)) || ($k === 'insured_name' && pb_m('\d{3}|:', $v));
        if ($k === 'prev_insurer') $bad = mb_strlen($v) > 40 || mb_strpos($v, ':') !== false || pb_m('\d', $v);
        if ($bad) $d[$k] = '';
    }
    $pi = trim(pb_ustrip(pb_sub('\s+', ' ', str_replace('شرکت', ' ', (string)($d['prev_insurer'] ?? '')))), ' -');
    if ($pi !== '') {
        if (in_array($pi, ['سهامی بیمه', 'سهامی بیمه ایران', 'بیمه ایران سهامی'], true)) $pi = 'بیمه ایران';
        $d['prev_insurer'] = pb_ustrip(pb_sub('^سهامی\s+', '', $pi));
    }
    if (!empty($d['model_year']) && !pb_full('(1[34]\d{2}|19\d{2}|20\d{2})', (string)$d['model_year'])) $d['model_year'] = '';
    if (empty($d['car_name']) || empty($d['car_system'])) {
        $n = trim(implode(' ', array_filter([$d['car_system'] ?? '', $d['car_tip'] ?? ''], 'strlen')));
        $d['car_name'] = $n !== '' ? $n : ($d['car_name'] ?? '');
    }
    foreach (['engine_no', 'vin', 'chassis_no'] as $k) {
        if (!empty($d[$k]) && !pb_full('[A-Za-z0-9]{5,30}', (string)$d[$k])) $d[$k] = substr(pb_sub('[^A-Za-z0-9]', '', (string)$d[$k]), 0, 30);
    }
    return $d;
}

const PB_CRITICAL = [['plate', 'پلاک'], ['insured_name', 'نام بیمه‌گذار'], ['premium', 'حق بیمه'], ['policy_num', 'شماره بیمه‌نامه']];

// شناسایی با جای متن + تکمیل با قواعدِ متنی؛ $spans و ابعادِ صفحه از PdfText
function pb_extract_policy(array $spans, $pageW, $pageH, $raw) {
    $base = pb_extract_insurance_data($raw);
    try { $lay = pb_extract_layout($spans, $pageW, $pageH); } catch (Throwable $e) { $lay = []; }
    foreach ($lay as $k => $v) if ($v !== '' && $v !== null && $v !== []) $base[$k] = $v;
    if (empty($base['premium'])) {
        $t = pb_norm($raw);
        if (pb_m('قابل\s*پرداخت[\s\S]{0,200}?(?:ریال\s*([\d,]{6,})|([\d,]{6,})\s*ریال)', $t, $m)) $base['premium'] = str_replace(',', '', pb_g($m, 1) ?? pb_g($m, 2));
    }
    $base = pb_clean_fields($base);
    $missing = [];
    foreach (PB_CRITICAL as [$k, $fa]) if (empty($base[$k])) $missing[] = $fa;
    if (in_array('پلاک', $missing, true) && !empty($base['vin'])) $missing = array_values(array_diff($missing, ['پلاک']));
    return [$base, $missing];
}

// تکه‌های یک صفحه با شماره (برای مقایسه‌ی «همان تکه»)
function pb_page_spans(PdfText $t, $idx, &$w = null, &$h = null) {
    $d = $t->dict($idx);
    $w = $d['width']; $h = $d['height'];
    $o = []; $i = 0;
    foreach ($d['blocks'] as $b) foreach ($b['lines'] as $l) foreach ($l['spans'] as $sp) {
        $tx = pb_ustrip(pb_norm($sp['text']));
        if ($tx === '') continue;
        $o[] = ['t' => $tx, 'x0' => $sp['bbox'][0], 'y0' => $sp['bbox'][1], 'x1' => $sp['bbox'][2], 'y1' => $sp['bbox'][3], 'size' => $sp['size'], 'i' => $i++];
    }
    return $o;
}

function pb_jaccard(array $a, array $b) {
    if (!$a || !$b) return 0.0;
    $u = count($a + $b);
    return $u ? count(array_intersect_key($a, $b)) / $u : 0.0;
}

function pb_word_set($t) {
    $o = [];
    foreach (preg_split(pb_rx('\s+'), pb_norm($t), -1, PREG_SPLIT_NO_EMPTY) as $w) $o[$w] = true;
    return $o;
}

// «صدور گروهی» (همان main ِ policy_bundle.py)؛ خروجی آرایه‌ی همان JSON
// یک صفحه: متن، شمارنده‌ی پایینِ صفحه و ابعاد
function pb_bundle_page($pt, $i, &$sp) {
    try {
        $sp = pb_page_spans($pt, $i, $w, $h);
        return ['text' => $pt->text($i), 'counter' => pb_footer_counter($sp, $h), 'dim' => [$w, $h]];
    } catch (Throwable $e) { $sp = null; return ['text' => '', 'counter' => null, 'dim' => [595, 842]]; }
}
// جای شروعِ هر بیمه‌نامه (شمارنده‌ی «۱ از n» پایینِ صفحه، شباهتِ متن به صفحه‌ی اول، یا هر صفحه اگر همه اسکن‌اند)
function pb_bundle_starts(array $texts, array $counters, $n) {
    $words = array_map('pb_word_set', $texts);
    $textPages = count(array_filter($texts, function ($t) { return mb_strlen(pb_ustrip($t)) > 30; }));
    $starts = []; $sims = array_fill(0, $n, null); $split = 'similarity';
    $withCounter = count(array_filter($counters));
    if ($textPages === 0) { $starts = range(0, $n - 1); $split = 'scan'; }
    elseif ($withCounter >= max(2, intval($n * 0.6)) && $counters[0] && $counters[0][0] === 1) {
        $split = 'footer';
        foreach ($counters as $i => $c) if ($c && $c[0] === 1) $starts[] = $i;
        if (!in_array(0, $starts, true)) array_unshift($starts, 0);
    } else {
        $tpl = $words[0];
        for ($i = 0; $i < $n; $i++) {
            $s = $i === 0 ? 1.0 : pb_jaccard($tpl, $words[$i]);
            $sims[$i] = round($s, 3);
            if ($i === 0 || $s > 0.25) $starts[] = $i;
        }
    }
    return [$starts, $sims, $split, $textPages];
}
// بیمه‌نامه‌ی k‌ام (صفحه‌ی شروع تا پیش از بیمه‌نامه‌ی بعدی): فایل‌ها، اطلاعات، فیش‌ها و اعلامیه
function pb_bundle_policy($doc, $pt, array $starts, $k, $n, $split, array $texts, array $dims, array $counters, array $sims, $outdir, array $spanCache = []) {
    $i = $starts[$k];
    $end = isset($starts[$k + 1]) ? $starts[$k + 1] - 1 : $n - 1;
    if ($split === 'scan') $end = $i;
    $pend = $i;
    while ($pend + 1 <= $end && !pb_is_payment_page($texts[$pend + 1])) $pend++;
    $hasText = mb_strlen(pb_ustrip($texts[$i])) > 30;
    $data = null; $missing = [];
    if ($hasText) {
        $sp = $spanCache[$i] ?? pb_page_spans($pt, $i);
        [$data, $missing] = pb_extract_policy($sp, $dims[$i][0], $dims[$i][1], $texts[$i]);
    }
    $fn = sprintf('pol_%03d.pdf', $i + 1); $sfn = sprintf('seg_%03d.pdf', $i + 1);
    try {
        $w1 = new PdfWriter(); for ($p = $i; $p <= $pend; $p++) $w1->addPage($doc, $p); $w1->save($outdir . '/' . $fn);
        $w2 = new PdfWriter(); for ($p = $i; $p <= $end; $p++) $w2->addPage($doc, $p); $w2->save($outdir . '/' . $sfn);
    } catch (Throwable $e) { return ['__error' => 'جدا کردنِ صفحه‌ها ممکن نشد.', 'debug' => mb_substr($e->getMessage(), 0, 300)]; }
    $receipts = []; $statement = null; $stmPages = [];
    for ($j = $pend + 1; $j <= $end; $j++) {
        $tj = mb_substr(pb_norm($texts[$j]), 0, 600);
        if (pb_m(PB_RECEIPT_RX, $tj)) {
            $info = pb_receipt_info($texts[$j]);
            $rfn = sprintf('rcp_%03d_%02d.pdf', $i + 1, count($receipts) + 1);
            try { $w3 = new PdfWriter(); $w3->addPage($doc, $j); $w3->save($outdir . '/' . $rfn); } catch (Throwable $e) { continue; }
            $info['page'] = $j; $info['file'] = $rfn;
            $receipts[] = $info;
        } elseif (pb_m('اعلامیه\s*آخرین\s*وضعیت', $tj)) $stmPages[] = $j;
    }
    if ($stmPages) {
        $statement = sprintf('stm_%03d.pdf', $i + 1);
        try { $w4 = new PdfWriter(); foreach ($stmPages as $j) $w4->addPage($doc, $j); $w4->save($outdir . '/' . $statement); } catch (Throwable $e) { $statement = null; }
    }
    $receipts = pb_sort($receipts, function ($r) { return [$r['date'] ?? '9999', $r['page']]; });
    $stRows = [];
    for ($j = $pend + 1; $j <= $end; $j++) if (pb_m('اعلامیه\s*آخرین\s*وضعیت', mb_substr(pb_norm($texts[$j]), 0, 600))) $stRows = array_merge($stRows, pb_statement_rows($texts[$j]));
    foreach ($receipts as &$r) {
        $hit = null;
        foreach ($stRows as $x) if (!empty($x['fish']) && $x['fish'] === ($r['fish'] ?? null)) { $hit = $x; break; }
        if (!$hit) foreach ($stRows as $x) if (($x['due'] ?? null) === ($r['date'] ?? null) && ($x['amount'] ?? null) === ($r['amount'] ?? null)) { $hit = $x; break; }
        if ($hit && !empty($hit['kind'])) $r['kind'] = $hit['kind'];
    }
    unset($r);
    $kinds = array_values(array_unique(array_filter(array_map(function ($r) { return $r['kind'] ?? ''; }, $receipts), 'strlen')));
    sort($kinds, SORT_STRING);
    if ($data !== null) {
        [$cno, $ckey] = pb_contract_info(array_slice($texts, $i, $pend - $i + 1));
        $pkey = pb_contract_key_from_policy($data['policy_num'] ?? '');
        if ($cno) $data['contract_no'] = $cno;
        if ($ckey || $pkey) $data['contract_key'] = $ckey ?: $pkey;
        if ($pkey) $data['contract_key_policy'] = $pkey;
        if ($receipts) {
            $data['pay_kind'] = $kinds ? implode('، ', $kinds) : (count($receipts) === 1 ? 'نقد' : 'اقساط');
            $data['installments_count'] = (string)count($receipts);
        }
    }
    if ($data !== null && empty($data['issue_date'])) {
        for ($j = $i + 1; $j <= $pend; $j++) {
            if (pb_m('تاریخ\s*صدور[^\d]{0,40}(1[34]\d{2}/\d{1,2}/\d{1,2})', pb_norm($texts[$j]), $m)) { $data['issue_date'] = pb_dates_in($m[1])[0]; break; }
        }
    }
    return ['page' => $i, 'end' => $end, 'policy_end' => $pend, 'has_text' => $hasText, 'file' => $fn, 'seg_file' => $sfn,
                   'similarity' => $sims[$i] ?? null, 'counter' => $counters[$i], 'data' => $data, 'missing' => $missing,
                   'receipts' => $receipts, 'statement_file' => $statement];
}

function pb_bundle($src, $outdir) {
    try { $doc = PdfDoc::open($src); } catch (Throwable $e) { return ['ok' => false, 'error' => 'فایل PDF باز نشد (خراب است یا PDF نیست).', 'debug' => mb_substr($e->getMessage(), 0, 300)]; }
    @set_time_limit(600);
    $n = $doc->pageCount();
    if ($n === 0) return ['ok' => false, 'error' => 'فایل هیچ صفحه‌ای ندارد.'];
    if (!is_dir($outdir)) @mkdir($outdir, 0755, true);
    $pt = new PdfText($doc);
    $texts = []; $counters = []; $spanCache = []; $dims = [];
    for ($i = 0; $i < $n; $i++) {
        $pg = pb_bundle_page($pt, $i, $sp);
        $texts[] = $pg['text']; $counters[] = $pg['counter']; $dims[$i] = $pg['dim'];
        if ($i < 400 && $sp !== null) $spanCache[$i] = $sp;
    }
    [$starts, $sims, $split, $textPages] = pb_bundle_starts($texts, $counters, $n);
    $policies = [];
    foreach ($starts as $k => $i) {
        $p = pb_bundle_policy($doc, $pt, $starts, $k, $n, $split, $texts, $dims, $counters, $sims, $outdir, $spanCache);
        if (isset($p['__error'])) return ['ok' => false, 'error' => $p['__error'], 'debug' => $p['debug'] ?? ''];
        $policies[] = $p;
    }
    return ['ok' => true, 'pages' => $n, 'text_pages' => $textPages, 'split' => $split, 'policies' => $policies];
}

// =====================================================================
//  نسخه‌ی مرحله‌ای (فایل‌های بزرگ): هر فراخوانی چند ثانیه کار می‌کند و وضعیت در پوشه‌ی کار ذخیره می‌شود
//  مراحل: text (خواندنِ صفحه‌ها) → pol (جدا کردنِ بیمه‌نامه‌ها و فیش‌ها) → done
// =====================================================================
function pb_bundle_job_state($dir) { $s = json_decode((string)@file_get_contents($dir . '/job.json'), true); return is_array($s) ? $s : null; }
function pb_bundle_job_save($dir, array $s) { file_put_contents($dir . '/job.json', json_encode($s, JSON_UNESCAPED_UNICODE)); }
function pb_bundle_job_init($src, $dir) {
    try { $doc = PdfDoc::open($src); } catch (Throwable $e) { return ['ok' => false, 'error' => 'فایل PDF باز نشد (خراب است یا PDF نیست).']; }
    $n = $doc->pageCount();
    if ($n === 0) return ['ok' => false, 'error' => 'فایل هیچ صفحه‌ای ندارد.'];
    $s = ['phase' => 'text', 'n' => $n, 'next' => 0, 'texts' => [], 'counters' => [], 'dims' => [], 'policies' => []];
    pb_bundle_job_save($dir, $s);
    return ['ok' => true, 'pages' => $n];
}
function pb_bundle_job_step($src, $dir, $budget = 15) {
    $s = pb_bundle_job_state($dir);
    if (!$s) return ['ok' => false, 'error' => 'کارِ این فایل پیدا نشد؛ دوباره بارگذاری کنید.'];
    if ($s['phase'] === 'done') return ['ok' => true] + $s;
    $t0 = microtime(true);
    try { $doc = PdfDoc::open($src); } catch (Throwable $e) { return ['ok' => false, 'error' => 'فایل PDF باز نشد.']; }
    $pt = new PdfText($doc);
    $outdir = $dir . '/pages';
    if (!is_dir($outdir)) @mkdir($outdir, 0755, true);
    if ($s['phase'] === 'text') {
        $first = true;   // هر فراخوانی دست‌کم یک صفحه جلو می‌رود
        while ($s['next'] < $s['n'] && ($first || microtime(true) - $t0 < $budget)) {
            $first = false;
            $pg = pb_bundle_page($pt, $s['next'], $sp);
            $s['texts'][] = $pg['text']; $s['counters'][] = $pg['counter']; $s['dims'][] = $pg['dim'];
            $s['next']++;
        }
        if ($s['next'] >= $s['n']) {
            [$starts, $sims, $split, $textPages] = pb_bundle_starts($s['texts'], $s['counters'], $s['n']);
            $s['starts'] = $starts; $s['sims'] = $sims; $s['split'] = $split; $s['text_pages'] = $textPages;
            $s['phase'] = 'pol'; $s['next'] = 0;
        }
    }
    if ($s['phase'] === 'pol') {
        $first = true;
        while ($s['next'] < count($s['starts']) && ($first || microtime(true) - $t0 < $budget)) {
            $first = false;
            $p = pb_bundle_policy($doc, $pt, $s['starts'], $s['next'], $s['n'], $s['split'], $s['texts'], $s['dims'], $s['counters'], $s['sims'], $outdir);
            if (isset($p['__error'])) return ['ok' => false, 'error' => $p['__error']];
            $s['policies'][] = $p;
            $s['next']++;
        }
        if ($s['next'] >= count($s['starts'])) $s['phase'] = 'done';
    }
    pb_bundle_job_save($dir, $s);
    return ['ok' => true] + $s;
}
