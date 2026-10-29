<?php
// فایل: api/_xls_reader.php
// خواندنِ فایل‌های قدیمیِ اکسل (.xls، فرمتِ BIFF8 داخلِ OLE2) بدونِ کتابخانه‌ی بیرونی؛ فایل‌های بیمه‌گرها (پاسارگاد و…) اغلب همین‌اند.
// خروجی مثلِ xlsx_read_sheets: [نامِ شیت => [ردیف => [ستون => مقدار]]]؛ ردیف‌های کاملاً خالی حذف می‌شوند.
// پشتیبانی: رشته‌های مشترک (SST با CONTINUE)، LABEL، NUMBER، RK، MULRK، FORMULA (عدد/رشته/بولی)، BOOLERR.

function xls_read_sheets($path) {
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 512 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return [];
    $wb = xls_ole_stream($data, ['Workbook', 'Book']);
    if ($wb === null) return [];
    return xls_biff_parse($wb);
}

// ---------------- OLE2 (Compound File): پیدا کردنِ جریانِ «Workbook» ----------------
function xls_ole_stream($d, array $names) {
    $u16 = function ($o) use ($d) { return unpack('v', substr($d, $o, 2))[1]; };
    $u32 = function ($o) use ($d) { return unpack('V', substr($d, $o, 4))[1]; };
    $ss = 1 << $u16(0x1E); $mss = 1 << $u16(0x20);
    $nFat = $u32(0x2C); $dirStart = $u32(0x30); $cutoff = $u32(0x38);
    $miniFatStart = $u32(0x3C); $nMiniFat = $u32(0x40); $difStart = $u32(0x44); $nDif = $u32(0x48);
    $sec = function ($n) use ($d, $ss) { return substr($d, 512 + $n * $ss, $ss); };
    // جدولِ FAT از DIFAT (۱۰۹ خانه‌ی سرآیند + زنجیره‌ی DIFAT)
    $fatSecs = [];
    for ($i = 0; $i < 109 && count($fatSecs) < $nFat; $i++) { $v = $u32(0x4C + $i * 4); if ($v < 0xFFFFFFFA) $fatSecs[] = $v; }
    $dif = $difStart; $guard = 0;
    while (count($fatSecs) < $nFat && $dif < 0xFFFFFFFA && $guard++ < 10000) {
        $s = $sec($dif); $per = $ss / 4 - 1;
        for ($i = 0; $i < $per && count($fatSecs) < $nFat; $i++) { $v = unpack('V', substr($s, $i * 4, 4))[1]; if ($v < 0xFFFFFFFA) $fatSecs[] = $v; }
        $dif = unpack('V', substr($s, $per * 4, 4))[1];
    }
    $fat = [];
    foreach ($fatSecs as $fs) $fat = array_merge($fat, array_values(unpack('V*', $sec($fs))));
    $chain = function ($start, array $table) { $out = []; $n = $start; $g = 0; while ($n < 0xFFFFFFFA && isset($table[$n]) && $g++ < 2000000) { $out[] = $n; $n = $table[$n]; } return $out; };
    $read = function ($start) use ($chain, $fat, $sec) { $s = ''; foreach ($chain($start, $fat) as $n) $s .= $sec($n); return $s; };
    $dir = $read($dirStart);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $nl = unpack('v', substr($dir, $o + 0x40, 2))[1];
        $name = $nl >= 2 ? mb_convert_encoding(substr($dir, $o, $nl - 2), 'UTF-8', 'UTF-16LE') : '';
        $entries[] = ['name' => $name, 'type' => ord($dir[$o + 0x42]), 'start' => unpack('V', substr($dir, $o + 0x74, 4))[1], 'size' => unpack('V', substr($dir, $o + 0x78, 4))[1]];
    }
    $root = $entries[0] ?? null;
    foreach ($names as $want) foreach ($entries as $e) {
        if ($e['type'] !== 2 || strcasecmp($e['name'], $want) !== 0) continue;
        if ($e['size'] >= $cutoff) return substr($read($e['start']), 0, $e['size']);
        // جریانِ کوچک: از mini stream (داخلِ Root Entry) با Mini FAT
        if (!$root) return null;
        $mini = $read($root['start']);
        $mfat = []; foreach ($chain($miniFatStart, $fat) as $n) $mfat = array_merge($mfat, array_values(unpack('V*', $sec($n))));
        $s = ''; foreach ($chain($e['start'], $mfat) as $n) $s .= substr($mini, $n * $mss, $mss);
        return substr($s, 0, $e['size']);
    }
    return null;
}

// ---------------- BIFF8 ----------------
function xls_biff_parse($wb) {
    $len = strlen($wb); $pos = 0;
    $sheets = []; $sst = [];
    // رکوردها: [نوع، داده، شروع]
    $recAt = function ($p) use ($wb, $len) {
        if ($p + 4 > $len) return null;
        $h = unpack('vt/vl', substr($wb, $p, 4));
        return [$h['t'], substr($wb, $p + 4, $h['l']), $p + 4 + $h['l']];
    };
    // بخشِ workbook: BOUNDSHEET و SST
    while (($r = $recAt($pos)) !== null) {
        [$t, $body, $next] = $r;
        if ($t === 0x85) {   // BOUNDSHEET
            $off = unpack('V', substr($body, 0, 4))[1]; $kind = ord($body[5]);
            $cch = ord($body[6]); $hi = ord($body[7]) & 1;
            $nm = $hi ? mb_convert_encoding(substr($body, 8, $cch * 2), 'UTF-8', 'UTF-16LE') : xls_latin(substr($body, 8, $cch));
            if ($kind === 0) $sheets[] = ['name' => $nm, 'off' => $off];
        } elseif ($t === 0xFC) {   // SST + CONTINUEها
            $chunks = [$body]; $p2 = $next;
            while (($c = $recAt($p2)) !== null && $c[0] === 0x3C) { $chunks[] = $c[1]; $p2 = $c[2]; }
            $sst = xls_sst($chunks); $next = $p2;
        } elseif ($t === 0x0A) { $pos = $next; break; }   // EOF بخشِ workbook
        $pos = $next;
    }
    $out = [];
    foreach ($sheets as $sh) {
        $rows = []; $p = $sh['off']; $first = true; $pendStr = null;
        while (($r = $recAt($p)) !== null) {
            [$t, $b, $next] = $r; $p = $next;
            if ($first) { $first = false; if ($t !== 0x809) break; continue; }   // BOF
            if ($t === 0x0A) break;   // EOF شیت
            if ($t === 0xFD && strlen($b) >= 10) { $h = unpack('vr/vc/vx/Vi', $b); $rows[$h['r']][$h['c']] = $sst[$h['i']] ?? ''; }
            elseif ($t === 0x203 && strlen($b) >= 14) { $h = unpack('vr/vc', $b); $rows[$h['r']][$h['c']] = xls_num(unpack('e', substr($b, 6, 8))[1]); }
            elseif ($t === 0x27E && strlen($b) >= 10) { $h = unpack('vr/vc/vx/Vk', $b); $rows[$h['r']][$h['c']] = xls_num(xls_rk($h['k'])); }
            elseif ($t === 0xBD && strlen($b) >= 6) {   // MULRK
                $row = unpack('v', substr($b, 0, 2))[1]; $c0 = unpack('v', substr($b, 2, 2))[1]; $n = intdiv(strlen($b) - 6, 6);
                for ($i = 0; $i < $n; $i++) $rows[$row][$c0 + $i] = xls_num(xls_rk(unpack('V', substr($b, 4 + $i * 6 + 2, 4))[1]));
            }
            elseif ($t === 0x204 && strlen($b) >= 9) {   // LABEL
                $h = unpack('vr/vc/vx/vn', $b); $hi = ord($b[8]) & 1;
                $rows[$h['r']][$h['c']] = $hi ? mb_convert_encoding(substr($b, 9, $h['n'] * 2), 'UTF-8', 'UTF-16LE') : xls_latin(substr($b, 9, $h['n']));
            }
            elseif ($t === 0x06 && strlen($b) >= 14) {   // FORMULA: نتیجه‌ی ذخیره‌شده
                $h = unpack('vr/vc', $b); $res = substr($b, 6, 8);
                if (substr($res, 6, 2) === "\xFF\xFF") {
                    $k = ord($res[0]);
                    if ($k === 0) $pendStr = [$h['r'], $h['c']];                     // رشته در رکوردِ STRING بعدی
                    elseif ($k === 1) $rows[$h['r']][$h['c']] = ord($res[2]) ? 'TRUE' : 'FALSE';
                    else $rows[$h['r']][$h['c']] = '';
                } else $rows[$h['r']][$h['c']] = xls_num(unpack('e', $res)[1]);
            }
            elseif ($t === 0x207 && $pendStr && strlen($b) >= 3) {   // STRING (نتیجه‌ی فرمول)
                $n = unpack('v', $b)[1]; $hi = ord($b[2]) & 1;
                $rows[$pendStr[0]][$pendStr[1]] = $hi ? mb_convert_encoding(substr($b, 3, $n * 2), 'UTF-8', 'UTF-16LE') : xls_latin(substr($b, 3, $n));
                $pendStr = null;
            }
            elseif ($t === 0x205 && strlen($b) >= 8) { $h = unpack('vr/vc', $b); $rows[$h['r']][$h['c']] = ord($b[7]) ? '' : (ord($b[6]) ? 'TRUE' : 'FALSE'); }
        }
        // به شکلِ آرایه‌ی پشتِ سرِ هم (مثلِ xlsx_read_sheets)، بدونِ ردیف‌های خالی
        ksort($rows); $grid = [];
        foreach ($rows as $cells) {
            if (!array_filter($cells, function ($v) { return trim((string)$v) !== ''; })) continue;
            $mx = max(array_keys($cells)); $line = [];
            for ($c = 0; $c <= $mx; $c++) $line[] = isset($cells[$c]) ? (string)$cells[$c] : '';
            $grid[] = $line;
        }
        $name = $sh['name']; $k = 2; while (isset($out[$name])) $name = $sh['name'] . ' (' . $k++ . ')';
        $out[$name] = $grid;
    }
    return $out;
}

// جدولِ رشته‌های مشترک؛ رشته‌ای که وسطش CONTINUE آمده، در تکه‌ی بعد با یک بایتِ پرچمِ تازه ادامه پیدا می‌کند
function xls_sst(array $chunks) {
    $ci = 0; $p = 8; $buf = $chunks[0]; $out = [];
    $total = unpack('V', substr($buf, 4, 4))[1];
    $need = function ($n) use (&$ci, &$p, &$buf, $chunks) {   // n بایتِ ساده (بدونِ پرچم) از تکه‌های پشتِ سرِ هم
        $s = '';
        while ($n > 0) {
            if ($p >= strlen($buf)) { if (!isset($chunks[$ci + 1])) break; $ci++; $buf = $chunks[$ci]; $p = 0; }
            $take = min($n, strlen($buf) - $p); $s .= substr($buf, $p, $take); $p += $take; $n -= $take;
        }
        return $s;
    };
    for ($i = 0; $i < $total; $i++) {
        if ($p >= strlen($buf)) { if (!isset($chunks[$ci + 1])) break; $ci++; $buf = $chunks[$ci]; $p = 0; }
        $hdr = $need(3); if (strlen($hdr) < 3) break;
        $cch = unpack('v', $hdr)[1]; $fl = ord($hdr[2]);
        $rich = ($fl & 8) ? unpack('v', $need(2))[1] : 0;
        $ext = ($fl & 4) ? unpack('V', $need(4))[1] : 0;
        $hi = $fl & 1; $str = ''; $left = $cch;
        while ($left > 0) {
            if ($p >= strlen($buf)) {   // رشته به تکه‌ی بعد رفت: بایتِ پرچمِ تازه
                if (!isset($chunks[$ci + 1])) break;
                $ci++; $buf = $chunks[$ci]; $p = 0; $hi = ord($buf[0]) & 1; $p = 1;
            }
            $avail = intdiv(strlen($buf) - $p, $hi ? 2 : 1); $n = min($left, $avail);
            if ($n <= 0) { $p = strlen($buf); continue; }
            $raw = substr($buf, $p, $n * ($hi ? 2 : 1)); $p += strlen($raw);
            $str .= $hi ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : xls_latin($raw);
            $left -= $n;
        }
        if ($rich) $need($rich * 4);
        if ($ext) $need($ext);
        $out[] = $str;
    }
    return $out;
}
function xls_latin($s) { return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1'); }
function xls_rk($rk) {
    if ($rk & 2) { $v = $rk >> 2; if ($v & 0x20000000) $v -= 0x40000000; }   // عددِ صحیحِ ۳۰ بیتیِ علامت‌دار
    else $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
    return ($rk & 1) ? $v / 100 : $v;
}
function xls_num($v) {
    if (is_float($v) && is_finite($v) && floor($v) == $v && abs($v) < 1e15) return (string)(int)$v;
    return (string)$v;
}
