<?php
// فایل: api/_img_fix.php
// «صاف کردنِ هوشمندِ مدرک» با GD (بدونِ ابزارِ بیرونی): عکس یا اسکنی که برعکس (۱۸۰°)، خوابیده (۹۰°/۲۷۰°) یا کمی کج است
// را راست می‌کند و حاشیه‌ی اضافه‌ی دورِ مدرک را می‌بُرد تا OCR و هوشِ مصنوعی اطلاعات را درست بخوانند.
//   imgfix_analyze($gd)        → ['rot' => 0|90|180|270 (ساعتگرد), 'skew' => درجه, 'crop' => [x,y,w,h]|null, 'conf' => ...]
//   imgfix_bytes($bytes, $opt) → ['bytes' => JPEG, 'changed' => bool, 'info' => analyze] یا null
//   imgfix_file($path)         → همان روی فایلِ عکس (برای آپلودِ تازه؛ فایل فقط وقتی عوض می‌شود که تغییری لازم باشد)
// روش: نقاطِ «لبه‌ی جوهر» در عکسِ کوچک‌شده؛ زاویه‌ای که تصویرِ این نقاط روی محورِ عمودی بیشترین تمرکز (خطوطِ متن) را دارد = کجی؛
// مقایسه‌ی تمرکزِ سطری و ستونی = خوابیده بودن (جهتِ ۹۰/۲۷۰ با جای خطِ کرسی؛ اگر اشتباه بود، مقایسه‌ی OCR در _doc_smart.php آن را برمی‌گرداند).

function imgfix_small($im, $max = 900) {
    $w = imagesx($im); $h = imagesy($im);
    $k = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $s = imagecreatetruecolor($nw, $nh);
    imagefill($s, 0, 0, imagecolorallocate($s, 255, 255, 255));
    imagecopyresampled($s, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return [$s, $k];
}
function imgfix_gray($im) {
    $w = imagesx($im); $h = imagesy($im); $g = [];
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($im, $x, $y);
        $g[] = ((($c >> 16) & 255) * 299 + (($c >> 8) & 255) * 587 + ($c & 255) * 114) / 1000 | 0;
    }
    return $g;
}
function imgfix_otsu(array $g) {
    $hist = array_fill(0, 256, 0); foreach ($g as $v) $hist[$v]++;
    $n = count($g); $sum = 0; for ($i = 0; $i < 256; $i++) $sum += $i * $hist[$i];
    $sB = 0; $wB = 0; $best = 0; $thr = 128;
    for ($t = 0; $t < 256; $t++) {
        $wB += $hist[$t]; if (!$wB) continue;
        $wF = $n - $wB; if (!$wF) break;
        $sB += $t * $hist[$t];
        $mB = $sB / $wB; $mF = ($sum - $sB) / $wF;
        $v = $wB * $wF * ($mB - $mF) * ($mB - $mF);
        if ($v > $best) { $best = $v; $thr = $t; }
    }
    return $thr;
}
// تمرکزِ تصویرِ نقاط روی یک محور (۱ = پخشِ یکنواخت؛ بزرگ‌تر = خطوطِ واضح‌تر)
function imgfix_score(array $xs, array $ys, $deg, $cols = false) {
    $a = deg2rad($deg); $c = cos($a); $s = sin($a);
    $bins = []; $n = count($xs);
    if (!$n) return 0;
    for ($i = 0; $i < $n; $i++) {
        $p = $cols ? (int)round($xs[$i] * $c + $ys[$i] * $s) : (int)round($ys[$i] * $c - $xs[$i] * $s);
        $bins[$p] = ($bins[$p] ?? 0) + 1;
    }
    $sq = 0; foreach ($bins as $v) $sq += $v * $v;
    $len = max($bins ? max(array_keys($bins)) - min(array_keys($bins)) + 1 : 1, 1);
    return $sq * $len / ($n * $n);
}
function imgfix_best_angle(array $xs, array $ys, $cols, $range = 12.0) {
    $best = -1; $ang = 0.0;
    for ($d = -$range; $d <= $range + 1e-9; $d += 0.5) { $sc = imgfix_score($xs, $ys, $d, $cols); if ($sc > $best) { $best = $sc; $ang = $d; } }
    $c0 = $ang;
    for ($d = $c0 - 0.4; $d <= $c0 + 0.4 + 1e-9; $d += 0.1) { $sc = imgfix_score($xs, $ys, $d, $cols); if ($sc > $best) { $best = $sc; $ang = $d; } }
    return [round($ang, 1), $best];
}
// چرخاندنِ ساعتگردِ آرایه‌ی خاکستری (۹۰/۱۸۰/۲۷۰)
function imgfix_rot_gray(array $g, $w, $h, $rot) {
    if (!$rot) return [$g, $w, $h];
    $o = [];
    if ($rot === 180) return [array_reverse($g), $w, $h];
    if ($rot === 90) { for ($y = 0; $y < $w; $y++) for ($x = 0; $x < $h; $x++) $o[] = $g[($h - 1 - $x) * $w + $y]; return [$o, $h, $w]; }
    for ($y = 0; $y < $w; $y++) for ($x = 0; $x < $h; $x++) $o[] = $g[$x * $w + ($w - 1 - $y)];
    return [$o, $h, $w];
}
// جای خطِ کرسی در سطرهای متن: میانگینِ (ردیفِ پررنگ‌ترین − بالای سطر)/ارتفاعِ سطر. راست ≈ ۰٫۵۵–۰٫۸، برعکس ≈ ۰٫۲–۰٫۴۵
function imgfix_baseline(array $g, $w, $h, $thr) {
    $row = array_fill(0, $h, 0);
    for ($y = 0; $y < $h; $y++) { $o = $y * $w; $n = 0; for ($x = 0; $x < $w; $x++) if ($g[$o + $x] < $thr) $n++; $row[$y] = $n; }
    $mx = max($row) ?: 1; $min = max(2, $mx * 0.04);
    $vals = []; $wts = [];
    for ($y = 0; $y < $h;) {
        if ($row[$y] < $min) { $y++; continue; }
        $t = $y; while ($y < $h && $row[$y] >= $min) $y++;
        $b = $y - 1; $lh = $b - $t + 1;
        if ($lh < 6 || $lh > $h * 0.12) continue;
        $pk = $t; for ($i = $t; $i <= $b; $i++) if ($row[$i] > $row[$pk]) $pk = $i;
        // پهنای سطر: باید سطرِ متن باشد نه خط یا قاب
        if ($row[$pk] > $w * 0.85) continue;
        $vals[] = ($pk - $t + 0.5) / $lh; $wts[] = array_sum(array_slice($row, $t, $lh));
    }
    if (count($vals) < 3) return null;
    $sw = array_sum($wts) ?: 1; $m = 0; foreach ($vals as $i => $v) $m += $v * $wts[$i] / $sw;
    $agree = 0; foreach ($vals as $i => $v) if (($v > 0.5) === ($m > 0.5)) $agree += $wts[$i] / $sw;
    return ['pos' => $m, 'agree' => $agree, 'lines' => count($vals)];
}

function imgfix_analyze($im) {
    [$sm, $k] = imgfix_small($im, 900);
    $w = imagesx($sm); $h = imagesy($sm);
    $g = imgfix_gray($sm); imagedestroy($sm);
    $thr = imgfix_otsu($g);
    // حاشیه: رنگِ زمینه از دورِ عکس؛ هر ردیف/ستونی که چیزِ متفاوتی دارد جزوِ مدرک است
    $crop = null;
    $bord = []; for ($x = 0; $x < $w; $x += 3) { $bord[] = $g[$x]; $bord[] = $g[($h - 1) * $w + $x]; } for ($y = 0; $y < $h; $y += 3) { $bord[] = $g[$y * $w]; $bord[] = $g[$y * $w + $w - 1]; }
    sort($bord); $bg = $bord[(int)(count($bord) / 2)];
    $rc = array_fill(0, $h, 0); $cc = array_fill(0, $w, 0);
    for ($y = 0; $y < $h; $y++) { $o = $y * $w; for ($x = 0; $x < $w; $x++) if (abs($g[$o + $x] - $bg) > 45) { $rc[$y]++; $cc[$x]++; } }
    $fr = function ($a, $lim) { $n = count($a); $s = 0; while ($s < $n && $a[$s] < $lim) $s++; $e = $n - 1; while ($e > $s && $a[$e] < $lim) $e--; return [$s, $e]; };
    [$y0, $y1] = $fr($rc, max(2, $w * 0.012)); [$x0, $x1] = $fr($cc, max(2, $h * 0.012));
    if ($x1 > $x0 && $y1 > $y0) {
        $pad = (int)round(max($w, $h) * 0.015);
        $x0 = max(0, $x0 - $pad); $y0 = max(0, $y0 - $pad); $x1 = min($w - 1, $x1 + $pad); $y1 = min($h - 1, $y1 + $pad);
        $cw = $x1 - $x0 + 1; $ch = $y1 - $y0 + 1;
        if ($cw * $ch < $w * $h * 0.88 && $cw > $w * 0.25 && $ch > $h * 0.25) $crop = [(int)($x0 / $k), (int)($y0 / $k), (int)ceil($cw / $k), (int)ceil($ch / $k)];
    }
    // نقاطِ لبه‌ی جوهر (نه لکه‌های بزرگِ تیره) داخلِ کادرِ مدرک
    $xs = []; $ys = [];
    $cx0 = $crop ? (int)($crop[0] * $k) : 0; $cy0 = $crop ? (int)($crop[1] * $k) : 0;
    $cx1 = $crop ? min($w - 2, (int)(($crop[0] + $crop[2]) * $k)) : $w - 2; $cy1 = $crop ? min($h - 2, (int)(($crop[1] + $crop[3]) * $k)) : $h - 2;
    for ($y = max(1, $cy0); $y <= $cy1; $y++) { $o = $y * $w; for ($x = max(1, $cx0); $x <= $cx1; $x++) {
        $v = $g[$o + $x];
        if ($v < $thr && ($g[$o + $x + 1] >= $thr || $g[$o + $x + $w] >= $thr || $g[$o + $x - 1] >= $thr || $g[$o + $x - $w] >= $thr)) { $xs[] = $x; $ys[] = $y; }
    } }
    $n = count($xs);
    $res = ['rot' => 0, 'skew' => 0.0, 'crop' => $crop, 'conf' => 0, 'points' => $n, 'w' => imagesx($im), 'h' => imagesy($im)];
    if ($n < 400) return $res;   // تقریباً خالی (یا فقط عکس): دست نمی‌زنیم
    if ($n > 40000) { $step = $n / 40000; $nx = []; $ny = []; for ($i = 0; $i < $n; $i += $step) { $nx[] = $xs[(int)$i]; $ny[] = $ys[(int)$i]; } $xs = $nx; $ys = $ny; }
    [$aR, $sR] = imgfix_best_angle($xs, $ys, false);
    [$aC, $sC] = imgfix_best_angle($xs, $ys, true);
    $side = $sC > $sR * 1.3 && $n >= 1500;     // خطوطِ متن عمودی‌اند: مدرک خوابیده است
    $skew = $side ? $aC : $aR;
    $res['skew'] = abs($skew) >= 0.4 ? $skew : 0.0;
    $res['scores'] = [round($sR, 2), round($sC, 2)];
    // بالا/پایین: روی نسخه‌ی صاف‌شده (کجیِ کم اثری ندارد)، برای حالتِ خوابیده هر دو جهت
    $cands = $side ? [90, 270] : [0, 180];
    [$gg, $ww, $hh] = imgfix_rot_gray($g, $w, $h, $cands[0]);
    $bl = imgfix_baseline($gg, $ww, $hh, $thr);
    $rot = $cands[0]; $conf = 0;
    if ($bl) {
        $conf = round(abs($bl['pos'] - 0.5) * $bl['agree'] * 2, 2);
        if ($bl['pos'] < 0.5) $rot = $cands[1];
        $res['baseline'] = $bl;
    }
    // برعکس بودن (۱۸۰°) از شکلِ سطرها قابلِ اطمینان نیست: آن را مقایسه‌ی متنِ OCRِ دو جهت تعیین می‌کند (ds_try_flip در _doc_smart.php)
    if (!$side) $rot = 0;
    $res['rot'] = $rot; $res['conf'] = $conf; $res['side'] = $side;
    return $res;
}

// اعمال: بُرش ← چرخشِ ۹۰/۱۸۰/۲۷۰ ← صاف کردنِ کجی (زمینه‌ی سفید)
function imgfix_apply($im, array $a, array $opt = []) {
    $out = $im;
    if (!empty($a['crop']) && ($opt['crop'] ?? true)) {
        [$x, $y, $w, $h] = $a['crop'];
        $c = imagecrop($out, ['x' => $x, 'y' => $y, 'width' => min($w, imagesx($out) - $x), 'height' => min($h, imagesy($out) - $y)]);
        if ($c) $out = $c;
    }
    $deg = (int)($a['rot'] ?? 0);
    // imagerotate پادساعتگرد است؛ rot ساعتگردِ لازم برای راست شدن است
    if ($deg) { $r = imagerotate($out, -$deg, 0); if ($r) { if ($out !== $im) imagedestroy($out); $out = $r; } }
    $sk = (float)($a['skew'] ?? 0);
    if ($sk && ($opt['deskew'] ?? true)) {
        $wht = imagecolorallocate($out, 255, 255, 255);
        // زاویه در مختصاتِ تصویر (y رو به پایین) ساعتگرد اندازه گرفته شده: پادساعتگرد برگردانده می‌شود
        $r = imagerotate($out, $sk, $wht);
        if ($r) {
            // گوشه‌های سفیدِ اضافه‌ی حاصل از چرخش بریده می‌شوند
            $ow = imagesx($out); $oh = imagesy($out); $rad = deg2rad(abs($sk));
            $cw = (int)floor($ow * cos($rad) - $oh * sin($rad)); $ch = (int)floor($oh * cos($rad) - $ow * sin($rad));
            if ($cw > $ow * 0.8 && $ch > $oh * 0.8) { $c = imagecrop($r, ['x' => (int)((imagesx($r) - $cw) / 2), 'y' => (int)((imagesy($r) - $ch) / 2), 'width' => $cw, 'height' => $ch]); if ($c) { imagedestroy($r); $r = $c; } }
            if ($out !== $im) imagedestroy($out); $out = $r;
        }
    }
    return $out;
}
function imgfix_needed(array $a, array $opt = []) {
    return !empty($a['rot']) || (($opt['deskew'] ?? true) && abs((float)$a['skew']) >= 0.4) || (($opt['crop'] ?? true) && !empty($a['crop']));
}

function imgfix_bytes($bytes, array $opt = []) {
    if (!function_exists('imagecreatefromstring')) return null;
    $im = @imagecreatefromstring((string)$bytes);
    if (!$im) return null;
    try {
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        $a = imgfix_analyze($im);
        if (!imgfix_needed($a, $opt)) { imagedestroy($im); return ['bytes' => (string)$bytes, 'changed' => false, 'info' => $a]; }
        $o = imgfix_apply($im, $a, $opt);
        ob_start(); imagejpeg($o, null, (int)($opt['quality'] ?? 90)); $jpg = (string)ob_get_clean();
        if ($o !== $im) imagedestroy($o); imagedestroy($im);
        return $jpg !== '' ? ['bytes' => $jpg, 'changed' => true, 'info' => $a, 'mime' => 'image/jpeg'] : null;
    } catch (Throwable $e) { error_log('[imgfix] ' . $e->getMessage()); return null; }
}
// چرخاندنِ ساده‌ی بایت‌های عکس (برای امتحانِ جهتِ دیگر وقتی OCR متنِ درستی نمی‌دهد)
function imgfix_rotate_bytes($bytes, $rot) {
    $im = @imagecreatefromstring((string)$bytes); if (!$im) return null;
    $r = imagerotate($im, -(int)$rot, 0); imagedestroy($im); if (!$r) return null;
    ob_start(); imagejpeg($r, null, 90); $o = (string)ob_get_clean(); imagedestroy($r);
    return $o !== '' ? $o : null;
}

// روی فایلِ عکسِ تازه آپلودشده (jpg/png/webp): فقط اگر لازم باشد بازنویسی می‌شود (همان پسوند)
function imgfix_file($path, array $opt = []) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !is_file($path) || filesize($path) > 25 * 1048576) return ['ok' => true];
    $im = $ext === 'png' ? @imagecreatefrompng($path) : ($ext === 'webp' ? (function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null) : @imagecreatefromjpeg($path));
    if (!$im) return ['ok' => true];
    try {
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        $a = imgfix_analyze($im);
        if (!imgfix_needed($a, $opt)) { imagedestroy($im); return ['ok' => true, 'info' => $a]; }
        $o = imgfix_apply($im, $a, $opt);
        $tmp = $path . '.fix';
        $ok = $ext === 'png' ? imagepng($o, $tmp, 9) : ($ext === 'webp' && function_exists('imagewebp') ? imagewebp($o, $tmp, 90) : imagejpeg($o, $tmp, 90));
        if ($o !== $im) imagedestroy($o); imagedestroy($im);
        if ($ok && @getimagesize($tmp)) { @rename($tmp, $path); return ['ok' => true, 'changed' => true, 'info' => $a]; }
        @unlink($tmp);
    } catch (Throwable $e) { error_log('[imgfix_file] ' . $e->getMessage()); @unlink($path . '.fix'); }
    return ['ok' => true];
}
