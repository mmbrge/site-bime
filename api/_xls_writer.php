<?php
// فایل: api/_xls_writer.php
// ساختِ فایلِ اکسلِ قدیمی (.xls / BIFF8 در ظرفِ OLE2) بدونِ کتابخانه — برای فایل‌های «ورودی به پاسارگاد» که بیمه‌گر .xls می‌خواهد.
// xls_build([['name' => 'Sheet1', 'rows' => [[سرستون‌ها...], [..], ...]], ...]) => مسیرِ فایلِ موقت (یا null)
// رشته => سلولِ متنی (کدِ ملی و شبا با صفرِ اولشان می‌مانند)؛ int/float => عدد.

function xls_rec($type, $data) {
    $out = '';
    $first = true;
    do {   // داده‌ی بیش از ۸۲۲۴ بایت => رکوردهای CONTINUE
        $chunk = substr($data, 0, 8224); $data = (string)substr($data, 8224);
        $out .= pack('vv', $first ? $type : 0x003C, strlen($chunk)) . $chunk;
        $first = false;
    } while ($data !== '');
    return $out;
}
function xls_u16($s) { return mb_convert_encoding((string)$s, 'UTF-16LE', 'UTF-8'); }

function xls_build(array $sheets) {
    $sheets = array_values($sheets);
    if (!$sheets) return null;
    // ---- جدولِ رشته‌های مشترک (SST) ----
    $sst = []; $sstList = []; $total = 0;
    $cells = [];
    foreach ($sheets as $si => $sh) {
        $rows = array_slice(array_values((array)($sh['rows'] ?? [])), 0, 65535);
        $cells[$si] = '';
        $maxC = 0;
        foreach ($rows as $r => $row) {
            foreach (array_values((array)$row) as $c => $v) {
                if ($c > 255) break;
                if ($v === null || $v === '') continue;
                if (is_int($v) || is_float($v)) { $cells[$si] .= xls_rec(0x0203, pack('vvv', $r, $c, 15) . pack('e', (float)$v)); }
                else {
                    $s = mb_substr((string)$v, 0, 4000);
                    if (!isset($sst[$s])) { $sst[$s] = count($sstList); $sstList[] = $s; }
                    $total++;
                    $cells[$si] .= xls_rec(0x00FD, pack('vvvV', $r, $c, 15, $sst[$s]));
                }
                $maxC = max($maxC, $c + 1);
            }
        }
        $sheets[$si]['_nrows'] = count($rows); $sheets[$si]['_ncols'] = $maxC;
    }

    // ---- رکوردهای سراسریِ کتاب ----
    $font = function () { $n = xls_u16('Arial'); return xls_rec(0x0031, pack('vvvvvCCCC', 200, 0, 0x7FFF, 400, 0, 0, 0, 0, 0) . pack('CC', 5, 1) . $n); };
    $g = xls_rec(0x0809, pack('vvvvVV', 0x0600, 0x0005, 0x0DBB, 0x07CC, 0, 6))
       . xls_rec(0x00E1, pack('v', 0x04B0)) . xls_rec(0x00E2, '') . xls_rec(0x005C, str_pad(pack('vC', 4, 1) . xls_u16('bime'), 112, ' '))
       . xls_rec(0x0042, pack('v', 0x04B0)) . xls_rec(0x0161, pack('v', 0)) . xls_rec(0x003D, pack('vvvvvvvvv', 0, 0, 0x4000, 0x2000, 0x0038, 0, 0, 1, 0x0258))
       . xls_rec(0x0040, pack('v', 0)) . xls_rec(0x008D, pack('v', 0)) . xls_rec(0x0022, pack('v', 0)) . xls_rec(0x000E, pack('v', 1)) . xls_rec(0x00DA, pack('v', 0))
       . $font() . $font() . $font() . $font() . $font();
    // ۱۵ XFِ سبک + ۱ XFِ سلول (اندیسِ ۱۵)
    for ($i = 0; $i < 15; $i++) $g .= xls_rec(0x00E0, pack('vvvCCCCVVv', $i === 0 ? 0 : 1, 0, 0xFFF5, 0x20, 0, 0, $i === 0 ? 0 : 0xF4, 0, 0, 0x20C0));
    $g .= xls_rec(0x00E0, pack('vvvCCCCVVv', 0, 0, 0x0001, 0x20, 0, 0, 0, 0, 0, 0x20C0));
    $g .= xls_rec(0x0293, pack('vCC', 0x8000, 0, 0xFF));
    // BOUNDSHEET با جای‌نگهدارِ مکانِ شیت
    $bsPos = [];
    foreach ($sheets as $si => $sh) {
        $name = mb_substr(preg_replace('~[\[\]:*?/\\\\]~u', ' ', (string)($sh['name'] ?? ('Sheet' . ($si + 1)))) ?: ('Sheet' . ($si + 1)), 0, 31);
        $bsPos[$si] = strlen($g) + 4;
        $g .= xls_rec(0x0085, pack('VCC', 0, 0, 0) . pack('CC', mb_strlen($name), 1) . xls_u16($name));
    }
    // SST (+ EXTSST): رشته‌ها فقط سرِ مرزِ رشته به CONTINUE شکسته می‌شوند
    $sstStart = strlen($g);
    $recs = []; $cur = pack('VV', $total, count($sstList)); $ext = []; $absBase = $sstStart;
    $recOffsets = []; // مکانِ شروعِ هر رکورد نسبت به ابتدای SST
    $pos = 0; // مکانِ شروعِ رکوردِ فعلی نسبت به sstStart
    foreach ($sstList as $i => $s) {
        $str = pack('vC', mb_strlen($s, 'UTF-8') > 0 ? strlen(xls_u16($s)) / 2 : 0, 1) . xls_u16($s);
        if (strlen($cur) + strlen($str) > 8224 && $cur !== '') { $recs[] = $cur; $pos += 4 + strlen($cur); $cur = ''; }
        if ($i % 8 === 0) $ext[] = [$absBase + $pos + 4 + strlen($cur), 4 + strlen($cur)];
        $cur .= $str;
    }
    $recs[] = $cur;
    foreach ($recs as $k => $r) $g .= pack('vv', $k === 0 ? 0x00FC : 0x003C, strlen($r)) . $r;
    $e = pack('v', 8); foreach ($ext as [$ib, $cb]) $e .= pack('Vvv', $ib, $cb, 0);
    $g .= xls_rec(0x00FF, $e);
    $g .= xls_rec(0x000A, '');

    // ---- شیت‌ها ----
    $stream = $g;
    foreach ($sheets as $si => $sh) {
        $off = strlen($stream);
        $stream = substr_replace($stream, pack('V', $off), $bsPos[$si], 4);
        $rtl = !empty($sh['rtl']) ? 0x0040 : 0;
        $stream .= xls_rec(0x0809, pack('vvvvVV', 0x0600, 0x0010, 0x0DBB, 0x07CC, 0, 6))
                 . xls_rec(0x0200, pack('VVvvv', 0, max(1, $sh['_nrows']), 0, max(1, $sh['_ncols']), 0))
                 . $cells[$si]
                 . xls_rec(0x023E, pack('vvvvvvvV', 0x06B6 | $rtl, 0, 0, 0x40, 0, 0, 0, 0))
                 . xls_rec(0x000A, '');
    }
    if (strlen($stream) < 4096) $stream = str_pad($stream, 4096, "\0");   // زیرِ ۴۰۹۶ بایت باید در mini-stream باشد؛ با پرکردن ساده‌تر است

    // ---- ظرفِ OLE2 (سکتورهای ۵۱۲ بایتی) ----
    $nWb = (int)ceil(strlen($stream) / 512);
    $nFat = 1;
    while ((int)ceil(($nWb + 1 + $nFat) / 128) > $nFat) $nFat++;
    if ($nFat > 109) return null;   // بیش از ~۷ مگابایت (برای این فایل‌ها پیش نمی‌آید)
    $dirSec = $nWb; $fatStart = $nWb + 1;
    $fat = [];
    for ($i = 0; $i < $nWb; $i++) $fat[] = $i === $nWb - 1 ? 0xFFFFFFFE : $i + 1;
    $fat[] = 0xFFFFFFFE;                                    // سکتورِ دایرکتوری
    for ($i = 0; $i < $nFat; $i++) $fat[] = 0xFFFFFFFD;     // خودِ FAT
    while (count($fat) < $nFat * 128) $fat[] = 0xFFFFFFFF;
    $hdr = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16) . pack('vvvvv', 0x003E, 0x0003, 0xFFFE, 9, 6) . str_repeat("\0", 6)
         . pack('VVVVVVVVV', 0, $nFat, $dirSec, 0, 4096, 0xFFFFFFFE, 0, 0xFFFFFFFE, 0);
    for ($i = 0; $i < 109; $i++) $hdr .= pack('V', $i < $nFat ? $fatStart + $i : 0xFFFFFFFF);
    $entry = function ($name, $type, $child, $start, $size) {
        $n = $name === '' ? '' : xls_u16($name) . "\0\0";
        return str_pad($n, 64, "\0") . pack('vCC', strlen($n), $type, 1) . pack('VVV', 0xFFFFFFFF, 0xFFFFFFFF, $child)
             . str_repeat("\0", 16) . pack('V', 0) . str_repeat("\0", 16) . pack('VVV', $start, $size, 0);
    };
    $dir = $entry('Root Entry', 5, 1, 0xFFFFFFFE, 0) . $entry('Workbook', 2, 0xFFFFFFFF, 0, strlen($stream));
    $empty = str_repeat("\0", 64) . pack('vCC', 0, 0, 0) . pack('VVV', 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF) . str_repeat("\0", 48);
    $dir .= $empty . $empty;
    $bin = $hdr . str_pad($stream, $nWb * 512, "\0") . $dir;
    foreach ($fat as $v) $bin .= pack('V', $v);
    $path = tempnam(sys_get_temp_dir(), 'xls');
    return file_put_contents($path, $bin) !== false ? $path : null;
}

function xls_send($path, $downloadName) {
    if (!$path || !is_file($path)) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => 'ساختِ فایل ممکن نشد.'], JSON_UNESCAPED_UNICODE); return; }
    header('Content-Type: application/vnd.ms-excel');
    $name = str_replace(['"', '/', '\\'], ['', '-', '-'], $downloadName);
    header("Content-Disposition: attachment; filename=\"report.xls\"; filename*=UTF-8''" . rawurlencode(function_exists('dl_name') ? dl_name($name) : $name));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
}
