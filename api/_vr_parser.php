<?php
// فایل: api/_vr_parser.php
// استخراجِ فیلدهای گزارشِ بازدید از PDFِ پیشنهاد/بیمه‌نامه با PHP (جایگزینِ report_assets/parser_runner.py؛ همان خروجی)
//   vrp_run($pdfAbs, $parserAbs = null) => ['ok'=>true, 'data', 'method', 'parser_used', 'parser_error', 'raw_len', 'raw_preview', 'truncated', 'checked']
// پارسرِ اختصاصی: فایلِ PHP در report_assets/parsers که یک تابع برمی‌گرداند: function ($raw_text, $checkboxes): array
// (مسیرِ قدیمیِ .py خودکار به همان نام با .php نگاشته می‌شود)
require_once __DIR__ . '/_policy_reader.php';

const VRP_GLYPH_FIX = ['ͬ' => 'ی', 'ͯ' => 'ی', 'ͷ' => 'ک', 'ͺ' => 'ک', 'ͽ' => 'گ', 'Ĺ' => '', 'ﻼ' => 'لا', 'ﻻ' => 'لا', 'ي' => 'ی', 'ك' => 'ک'];
const VRP_STEMS = ['بل' => 'بلی', 'شخص' => 'شخصی', 'عموم' => 'عمومی', 'دولت' => 'دولتی', 'قسط' => 'قسطی', 'اختصاص' => 'اختصاصی',
                   'رانندگ' => 'رانندگی', 'نظام' => 'نظامی', 'اصل' => 'اصلی', 'اضاف' => 'اضافی'];

function vrp_words_text(array $ws) {
    $parts = []; $prevX0 = null;
    foreach (pb_sort($ws, function ($w) { return -$w[0]; }) as $w) {
        $t = strtr($w[4], VRP_GLYPH_FIX);
        if ($parts && $prevX0 !== null && -1.0 < $prevX0 - $w[2] && $prevX0 - $w[2] < 0.3) $parts[count($parts) - 1] .= $t;
        else $parts[] = $t;
        $prevX0 = $w[0];
    }
    return pb_ustrip(pb_sub('\s+', ' ', implode(' ', $parts)));
}

function vrp_stem_last($label) {
    $ws = explode(' ', $label);
    $ws[count($ws) - 1] = VRP_STEMS[end($ws)] ?? end($ws);
    return implode(' ', $ws);
}

// چک‌باکس‌ها: مربع‌های کوچکِ کادردار؛ اگر مربعِ توپُرِ کوچک‌تری داخلش باشد «علامت‌خورده» است
function vrp_checkboxes(PdfDoc $doc, PdfText $pt) {
    $out = [];
    for ($pno = 0, $n = $doc->pageCount(); $pno < $n; $pno++) {
        $frames = []; $marks = [];
        foreach ($pt->drawings($pno) as $g) {
            [$x0, $y0, $x1, $y1] = $g['rect'];
            $w = $x1 - $x0; $h = $y1 - $y0;
            if (!($w >= 3 && $w <= 12 && $h >= 3 && $h <= 12) || abs($w - $h) > 1.2) continue;
            if ($g['fill'] !== null && strpos($g['type'], 'f') !== false) $marks[] = $g['rect'];
            if ($g['color'] !== null && strpos($g['type'], 's') !== false) $frames[] = $g['rect'];
        }
        if (!$frames) continue;
        $words = array_values(array_filter($pt->words($pno), function ($w) { return pb_ustrip($w[4]) !== ''; }));
        foreach ($frames as $fr) {
            $fw = $fr[2] - $fr[0];
            $checked = false;
            foreach ($marks as $m) if (($m[2] - $m[0]) < $fw - 1 && $fr[0] - 0.5 <= $m[0] && $m[2] <= $fr[2] + 0.5 && $fr[1] - 0.5 <= $m[1] && $m[3] <= $fr[3] + 0.5) { $checked = true; break; }
            $yc = ($fr[1] + $fr[3]) / 2;
            $line = pb_sort(array_values(array_filter($words, function ($w) use ($yc) { return $w[1] - 2 <= $yc && $yc <= $w[3] + 2; })), function ($w) { return $w[0]; });
            $right = array_values(array_filter($line, function ($w) use ($fr) { return $w[0] >= $fr[2] - 1; }));
            $nxs = [];
            foreach ($frames as $f) if (abs(($f[1] + $f[3]) / 2 - $yc) < 3 && $f[0] > $fr[2]) $nxs[] = $f[0];
            $nxt = $nxs ? min($nxs) : 1e9;
            $label = []; $last = $fr[2];
            foreach ($right as $w) { if ($w[0] > $nxt || $w[0] - $last > 12) break; $label[] = $w; $last = $w[2]; }
            $out[] = ['page' => $pno, 'x' => round($fr[0], 1), 'y' => round($fr[1], 1), 'checked' => $checked,
                      'label' => vrp_stem_last(trim(vrp_words_text($label), ' :')), 'line' => vrp_words_text($right)];
        }
    }
    return $out;
}

function vrp_fix_digits($s) {
    if ($s === null || $s === '') return '';
    $s = strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '،' => ',']);
    return strtr(pdft_nfkc($s), ['ي' => 'ی', 'ك' => 'ک']);
}

// پارسرِ عمومی (parse_text_to_fields ِ برنامه‌ی ویندوزی)
function vrp_generic($raw) {
    $d = ['year' => '', 'plate_part1' => '', 'plate_letter' => '', 'plate_part2' => '', 'plate_part3' => '', 'chassis_no' => '', 'engine_no' => '', 'insured_value' => '',
          'name' => '', 'national_id' => '', 'phone_bimeg' => '', 'color' => '', 'usage' => '', 'vehicle_type' => '', 'brand' => '', 'addres_bimeg' => ''];
    if (pb_ustrip((string)$raw) === '') return $d;
    $tn = pb_sub('[ \t]+', ' ', vrp_fix_digits($raw));
    $noDates = pb_sub('\d{4}\s*[/.-]\s*\d{1,2}\s*[/.-]\s*\d{1,2}', '', $tn);
    if (pb_m('(?<!\d)(13[4-9]\d|14\d{2})(?!\d)', $noDates, $m)) $d['year'] = $m[1];
    $found = false;
    if (pb_m('(\d{2})\s*ایران\s*(\d{2})\s*([a-zA-Zآ-یﻉعو])\s*(\d{3})', $tn, $m)) { $d['plate_part3'] = $m[1]; $d['plate_part1'] = $m[2]; $d['plate_letter'] = $m[3]; $d['plate_part2'] = $m[4]; $found = true; }
    elseif (pb_m('(\d{2})\s*([a-zA-Zآ-یﻉعو])\s*(\d{3})\s*ایران\s*(\d{2})', $tn, $m)) { $d['plate_part1'] = $m[1]; $d['plate_letter'] = $m[2]; $d['plate_part2'] = $m[3]; $d['plate_part3'] = $m[4]; $found = true; }
    if (!$found) {
        foreach (explode("\n", $tn) as $line) {
            if (mb_strpos($line, 'پلاک') !== false || mb_strpos($line, 'پالک') !== false) {
                $lc = pb_sub('[^\d\sایرانآ-یa-zA-Z]', '', $line);
                $nums = array_map(function ($x) { return $x[0]; }, pb_all('\d+', $lc));
                $letter = pb_m('[a-zA-Zآ-یﻉعو]', $lc, $lm) ? $lm[0] : '';
                if (count($nums) >= 3) {
                    $d['plate_part1'] = strlen($nums[0]) <= 2 ? $nums[0] : '';
                    $d['plate_letter'] = $letter;
                    $d['plate_part2'] = $nums[1] ?? '';
                    $d['plate_part3'] = end($nums);
                }
                break;
            }
        }
    }
    $chassis = null;
    if (pb_m('[A-Za-z0-9]{17}', $tn, $m)) $chassis = strtoupper($m[0]);
    elseif (pb_m('[A-Za-z0-9]{13,17}', $tn, $m)) $chassis = strtoupper($m[0]);
    if ($chassis !== null) {
        $d['chassis_no'] = $chassis;
        $p = strpos($tn, $chassis);
        $wo = $p === false ? $tn : substr($tn, 0, $p) . substr($tn, $p + strlen($chassis));
    } else $wo = $tn;
    $cands = array_map(function ($m) { return $m[0]; }, pb_all('\b[A-Za-z0-9\-]{6,30}\b', $wo));
    $filtered = array_values(array_filter($cands, function ($x) { return !pb_m('^\d{10,11}$', $x) && !pb_m('^(13|14)\d{2}$', $x); }));
    if ($filtered) {
        $alnum = array_values(array_filter($filtered, function ($x) { return !pb_m('^[\d\-]+$', $x); }));
        if ($alnum) { $alnum = pb_sort($alnum, function ($x) { return strlen($x); }, true); $d['engine_no'] = strtoupper($alnum[0]); }
        else {
            $pure = array_values(array_filter($filtered, function ($x) { return pb_m('^[\d\-]+$', $x); }));
            if ($pure) { $pure = pb_sort($pure, function ($x) { $dg = pb_sub('[^\d]', '', $x); return [strlen($x), $dg === '' ? 0 : (float)$dg]; }, true); $d['engine_no'] = $pure[0]; }
        }
    }
    if (pb_m('مبلغ\s*([\d,]+)\s*ریال', $tn, $m)) $d['insured_value'] = str_replace(',', '', $m[1]);
    elseif (pb_m('([1-9][0-9]{0,2}(?:,[0-9]{3}){2,})', $tn, $m)) $d['insured_value'] = str_replace(',', '', $m[1]);
    elseif (pb_m('ارزش\s+(?:وسیله\s+نقلیه|خودرو)[\s:]*([\d,]+)', $tn, $m)) $d['insured_value'] = str_replace(',', '', $m[1]);
    else { $big = array_map(function ($x) { return $x[0]; }, pb_all('[1-9]\d{8,}', $tn)); if ($big) { $b = $big[0]; foreach ($big as $x) if ((float)$x > (float)$b) $b = $x; $d['insured_value'] = $b; } }
    if (pb_m('اینجانب\s+(.*?)\s+با\s*[تت]?[اأآ]?[یي]?[یي]?د?', $tn, $m)) $d['name'] = pb_ustrip($m[1]);
    elseif (pb_m('([آ-یa-zA-Z\s]+)\s*:\s*کد\s*ملی', $tn, $m)) $d['name'] = pb_ustrip($m[1]);
    if (pb_m('(?<!\d)(\d{10,11})(?!\d)', $tn, $m)) $d['national_id'] = $m[1];
    if (pb_m('(0?9\d{2}[-\s]?\d{3}[-\s]?\d{4})', $tn, $m)) $d['phone_bimeg'] = str_replace(['-', ' '], '', $m[1]);
    elseif (pb_m('(?<!\d)(0\d{7,10})(?!\d)', $tn, $m)) $d['phone_bimeg'] = $m[1];
    if (pb_m('رنگ[\s:|]*\s*((?:(?!\bظرفیت\b|\bمورد\s+استفاده\b|\bشماره\b|\bسیلندر\b|\bموتور\b|\bشاسی\b)[\s\S])*?)(?=\s*(?:ظرفیت|مورد\s+استفاده|شماره|سیلندر|موتور|شاسی|\z))', $tn, $m) && pb_ustrip($m[1]) !== '') $d['color'] = pb_ustrip($m[1]);
    elseif (pb_m('(سفید|مشکی|نوک مدادی|نقره ای|سورمه ای|قرمز|آبی|خاکستری|طلایی|قهوه ای|سبز)\s*(متالیک|روغنی|صدفی)?', $tn, $m)) $d['color'] = pb_ustrip($m[0]);
    if (pb_m('مورد\s+استفاده[\s:|]*\s*((?:(?!\bشماره\b|\bموتور\b|\bشاسی\b|\bسیلندر\b|\bرنگ\b|\bظرفیت\b)[\s\S])*?)(?=\s*(?:شماره|موتور|شاسی|سیلندر|رنگ|ظرفیت|\z))', $tn, $m) && pb_ustrip($m[1]) !== '') $d['usage'] = pb_ustrip($m[1]);
    elseif (pb_m('(شخصی|بارکش|مسافربر|تاکسی|آژانس|آموزشی|کاربری\s+[آ-ی]+)', $tn, $m)) $d['usage'] = pb_ustrip($m[1]);
    $brands = '(سوزوکی|ام وی ام|MVM|چری|فونیکس|جک|لیفان|تیبا|ساینا|کوییک|تارا|شاهین|دنا|رانا|پیکان|میتسوبیشی|نیسان|مزدا|ماموت|پژو|پراید|سمند|تویوتا|هیوندای|کیا|رنو|بنز|بی ام و|دانگ فنگ|اسکانیا|ولوو|ایسوزو|کوماتسو|فاو|آمیکو)';
    $vt = '(سواری|کامیون|کامیونت|کشنده|وانت|موتورسیکلت|یدک|کفی|ون|مینی بوس|اتوبوس)';
    if (pb_m('(' . $vt . '\s+' . $brands . '(?:\s+[آ-یA-Za-z0-9]+){0,2})', $tn, $m)) $d['vehicle_type'] = pb_ustrip($m[1]);
    elseif (pb_m($brands . '(?:\s+(?!مدل|رنگ|شماره|سال|ظرفیت|مورد|شاسی|موتور)[آ-یA-Za-z0-9]+){0,2}', $tn, $m)) $d['vehicle_type'] = pb_ustrip($m[0]);
    elseif (pb_m('(خودرو\s+)?' . $vt, $tn, $m)) $d['vehicle_type'] = pb_ustrip($m[0]);
    if (pb_m('(استان\s+[آ-ی\s،,-]+(?:پلاک\s+\d+|واحد\s+\d+|طبقه\s+[آ-ی\d]+)?)', $tn, $m)) $d['addres_bimeg'] = pb_ustrip($m[1]);
    return $d;
}

function vrp_score($d) { $n = 0; foreach ((array)$d as $v) if (pb_ustrip((string)$v) !== '') $n++; return $n; }

function vrp_clean($d) {
    $o = [];
    foreach ((array)$d as $k => $v) {
        if ($v === null) continue;
        if (is_array($v)) { $isList = array_keys($v) === range(0, count($v) - 1); $v = $isList ? implode('، ', array_map('strval', $v)) : json_encode($v, JSON_UNESCAPED_UNICODE); }
        elseif (is_bool($v)) $v = $v ? 'True' : 'False';
        $s = pb_ustrip((string)$v);
        if (in_array(mb_strtolower($s), ['nan', 'none', 'null'], true)) $s = '';
        $o[(string)$k] = $s;
    }
    return $o;
}

// پارسرِ اختصاصی => callable یا خطا (مسیرِ .py به همان نام با .php)
function vrp_load_custom($path) {
    $p = preg_replace('/\.py$/i', '.php', (string)$path);
    if (!is_file($p)) throw new RuntimeException('فایلِ پارسر (' . basename($p) . ') پیدا نشد.');
    $fn = (static function ($__f) { return include $__f; })($p);
    if (!is_callable($fn)) throw new RuntimeException('فایلِ پارسر باید یک تابع برگرداند: return function ($raw_text, $checkboxes) {...};');
    return $fn;
}

// مقدارِ بریده‌شده در خودِ PDF (متن تا لبه‌ی خطِ عمودیِ خانه چسبیده است)
function vrp_detect_truncated(PdfDoc $doc, PdfText $pt, array $data) {
    $vals = [];
    foreach (['engine_no', 'motor', 'chassis_no', 'shasi', 'vin'] as $k) { $v = pb_ustrip((string)($data[$k] ?? '')); if (mb_strlen($v) >= 6) $vals[$k] = $v; }
    if (!$vals) return [];
    $out = [];
    for ($p = 0, $n = $doc->pageCount(); $p < $n; $p++) {
        $vl = [];
        foreach ($pt->drawings($p) as $dr) {
            foreach ($dr['segs'] as $sg) {
                if ($sg[0] === 'l' && abs($sg[1][0] - $sg[2][0]) < 0.6) $vl[] = [$sg[1][0], min($sg[1][1], $sg[2][1]), max($sg[1][1], $sg[2][1])];
                elseif ($sg[0] === 're') { $r = $sg[1]; $vl[] = [$r[0], $r[1], $r[3]]; $vl[] = [$r[2], $r[1], $r[3]]; }
            }
        }
        if (!$vl) continue;
        foreach ($pt->words($p) as $w) {
            $txt = strtoupper(pb_ustrip($w[4]));
            foreach ($vals as $k => $v) {
                if (in_array($k, $out, true) || $txt !== strtoupper($v)) continue;
                $cy = ($w[1] + $w[3]) / 2;
                foreach ($vl as [$lx, $ly0, $ly1]) if ($ly0 - 2 <= $cy && $cy <= $ly1 + 2 && ((0 <= $w[0] - $lx && $w[0] - $lx <= 1.5) || (0 <= $lx - $w[2] && $lx - $w[2] <= 1.5))) { $out[] = $k; break; }
            }
        }
    }
    return $out;
}

function vrp_run($pdfAbs, $parserAbs = null) {
    if (!is_file($pdfAbs)) return ['ok' => false, 'error' => 'فایل PDF پیدا نشد.'];
    try {
        $doc = PdfDoc::open($pdfAbs);
        $pt = new PdfText($doc);
        $parts = [];
        for ($i = 0, $n = $doc->pageCount(); $i < $n; $i++) $parts[] = $pt->text($i);
        $raw = implode("\n", $parts);
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'PDF خوانده نشد: ' . mb_substr($e->getMessage(), 0, 200)]; }
    if (pb_ustrip($raw) === '') return ['ok' => false, 'error' => 'متنی در این PDF پیدا نشد (احتمالاً اسکن تصویری است).', 'details' => []];
    $method = 'PHP';
    try { $checkboxes = vrp_checkboxes($doc, $pt); } catch (Throwable $e) { $checkboxes = []; }
    $data = []; $used = 'generic'; $perr = null;
    if ($parserAbs) {
        try { $fn = vrp_load_custom($parserAbs); $data = vrp_clean($fn($raw, $checkboxes) ?: []); $used = 'custom'; }
        catch (Throwable $e) { $perr = get_class($e) . ': ' . $e->getMessage(); $data = []; }
    }
    if (vrp_score($data) === 0) { $data = vrp_clean(vrp_generic($raw)); $used = 'generic'; }
    else {
        try {
            $added = 0;
            foreach (vrp_clean(vrp_generic($raw)) as $k => $v) if ($v !== '' && pb_ustrip((string)($data[$k] ?? '')) === '') { $data[$k] = $v; $added++; }
            if ($added) $used = 'custom+generic';
        } catch (Throwable $e) {}
    }
    try { $trunc = vrp_detect_truncated($doc, $pt, $data); } catch (Throwable $e) { $trunc = []; }
    $checked = [];
    foreach ($checkboxes as $c) if ($c['checked']) $checked[] = mb_strlen($c['label']) < 12 ? $c['line'] . ' ← ' . $c['label'] : $c['label'];
    return ['ok' => true, 'data' => array_filter($data, function ($v) { return $v !== ''; }), 'method' => $method, 'parser_used' => $used, 'parser_error' => $perr,
            'raw_len' => mb_strlen($raw), 'raw_preview' => mb_substr($raw, 0, 3000), 'truncated' => $trunc, 'checked' => $checked];
}
