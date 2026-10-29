<?php
// فایل: api/_sanhab_shot.php
// «اسکرینِ سایتِ سنهاب»: عکسی با همان چیدمانِ صفحه‌ی «استعلام اطلاعات بیمه خودرو کشور»ِ سامانه (فرمِ جستجو، سرتیترِ نتیجه،
// جدولِ آبی، زیرجدولِ سبزِ الحاقیه‌ها و قرمزِ خسارت‌ها) از روی نتیجه‌ی ذخیره‌شده‌ی استعلام. مثلِ اسکرینِ واقعی از پایین بریده می‌شود؛
// ردیف‌های اول و دوم همیشه کامل می‌آیند و بقیه تا جایی که جا شود. (فونت و متنِ فارسی: pb_font / pb_gd_text در api/_pbody.php)
require_once __DIR__ . '/_pbody.php';

function sh_shot_png(array $r, array $sel, $dest) {
    if (!function_exists('imagettftext')) return false;
    [$res, $rows] = sh_export_rows($r, $sel);
    $q = $res['q'] ?? [];
    $F = pb_font(); $FB = pb_font(true);
    $W = 1890;
    $L = function ($t) { return pb_gd_text((string)$t); };
    $tw = function ($t, $size, $font) use ($L) { $bb = imagettfbbox($size, 0, $font, $L($t)); return abs($bb[2] - $bb[0]); };
    // شکستنِ متن به چند خط در عرضِ داده‌شده
    $wrap = function ($t, $size, $font, $maxw) use ($tw) {
        $t = trim(preg_replace('/\s+/u', ' ', (string)$t));
        if ($t === '' || $tw($t, $size, $font) <= $maxw) return [$t];
        $out = []; $cur = '';
        foreach (explode(' ', $t) as $w) { $try = $cur === '' ? $w : "$cur $w"; if ($cur !== '' && $tw($try, $size, $font) > $maxw) { $out[] = $cur; $cur = $w; } else $cur = $try; }
        if ($cur !== '') $out[] = $cur;
        return $out;
    };

    // ستون‌ها (راست به چپ): [عنوان، عرض]
    $mainCols = [['انتخاب PDF چاپ', 37], ['ردیف', 30], ['کد یکتای بیمه نامه', 80], ['نوع بیمه نامه', 72], ['شرکت بیمه', 46], ['نام بیمه گذار', 133], ['شماره بیمه نامه', 225],
                 ['شماره بیمه نامه س. ق', 152], ['نام خودرو', 61], ['شماره پلاک خودرو', 74], ['شماره موتور', 61], ['شماره شاسی', 114], ['شماره VIN', 120], ['تاریخ صدور', 69],
                 ['تاریخ شروع', 68], ['تاریخ انقضا', 66], ['سابقه عدم خسارت مالی (سال)', 57], ['سابقه عدم خسارت جانی (سال)', 57], ['سابقه عدم خسارت سرنشین (سال)', 54],
                 ['درصد تخفیف خسارت مالی', 46], ['درصد تخفیف خسارت جانی', 46], ['درصد تخفیف خسارت سرنشین', 54], ['سابقه عدم خسارت بدنه', 57], ['جریمه صندوق خسارت', 79]];
    $endCols = [['ردیف', 97], ['نام بیمه گذار', 79], ['شماره پلاک', 76], ['VIN', 138], ['شماره شاسی', 132], ['شماره موتور', 71], ['سابقه عدم خسارت جانی(سال)', 170],
                ['سابقه عدم خسارت مالی(سال)', 160], ['سابقه عدم خسارت سرنشین(سال)', 182], ['سابقه عدم خسارت بدنه(سال)', 155], ['درصد تخفیف خسارت مالی', 134],
                ['درصد تخفیف خسارت جانی', 136], ['درصد تخفیف خسارت سرنشین', 153], ['تاریخ صدور', 87], ['نوع الحاقیه', 84]];
    $lossCols = [['ردیف', 90], ['خسارت پرداختی', 422], ['تاریخ اعلام', 331], ['تاریخ خسارت پرداختی', 519], ['نوع خسارت پرداختی', 492]];
    $val = function ($x, $k) { $v = trim((string)($x[$k] ?? '')); return $v; };
    $mainVals = function ($x) use ($val) {
        return ['', $val($x, 'radif'), $val($x, 'uniqueCode'), $val($x, 'policyType'), $val($x, 'company'), $val($x, 'insuredName'), $val($x, 'policyNo'), $val($x, 'prevPolicyNo'),
                $val($x, 'vehicleName'), $val($x, 'plate'), $val($x, 'motorNo'), $val($x, 'chassisNo'), $val($x, 'vin'), $val($x, 'issueDate'), $val($x, 'startDate'), $val($x, 'endDate'),
                $val($x, 'noLossFinYr'), $val($x, 'noLossLifeYr'), $val($x, 'noLossPassYr'), $val($x, 'discFinPct'), $val($x, 'discLifePct'), $val($x, 'discPassPct'), $val($x, 'noLossBodyYr'), $val($x, 'penaltyCost')];
    };
    $endVals = function ($e) use ($val) {
        return [$val($e, 'radif'), $val($e, 'insuredName'), $val($e, 'plate'), $val($e, 'vin'), $val($e, 'chassisNo'), $val($e, 'motorNo'), $val($e, 'noLossLifeYr'), $val($e, 'noLossFinYr'),
                $val($e, 'noLossPassYr'), $val($e, 'noLossBodyYr'), $val($e, 'discFinPct'), $val($e, 'discLifePct'), $val($e, 'discPassPct'), $val($e, 'issueDate'), $val($e, 'endorsementType')];
    };
    $lossVals = function ($l) use ($val) { return [$val($l, 'radif'), $val($l, 'paidAmount'), $val($l, 'declareDate'), $val($l, 'paidDate'), $val($l, 'lossType')]; };

    // اندازه‌ی هر بلوکِ ردیف (ردیفِ اصلی + الحاقیه‌ها + خسارت‌ها) برای این‌که دو ردیفِ اول همیشه جا شوند
    $mainH = function ($x) use ($mainCols, $mainVals, $wrap, $F) {
        $n = 1; foreach ($mainVals($x) as $i => $v) $n = max($n, count($wrap($v, 9, $F, $mainCols[$i][1] - 6)));
        return max(34, $n * 15 + 6);
    };
    $blockH = function ($x) use ($mainH) {
        $h = $mainH($x) + 6;
        if (!empty($x['endorsements'])) $h += 38 + 38 * count($x['endorsements']) + 4;
        if (!empty($x['losses'])) $h += 24 + 23 * count($x['losses']) + 4;
        return $h + 22;
    };
    $top = 448;
    $need = $top; foreach (array_slice($rows, 0, 2) as $x) $need += $blockH($x);
    $H = max(935, $need + 8);

    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    $col = function ($hex) use ($im) { return imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))); };
    $white = $col('#ffffff'); $ink = $col('#222222'); $grey = $col('#555555');
    $rtext = function ($t, $right, $y, $size, $c, $font = null) use ($im, $F, $tw, $L) { $font = $font ?: $F; imagettftext($im, $size, 0, (int)($right - $tw($t, $size, $font)), (int)$y, $c, $font, $L($t)); };
    $ctext = function ($t, $x1, $x2, $y, $size, $c, $font = null) use ($im, $F, $tw, $L) { $font = $font ?: $F; imagettftext($im, $size, 0, (int)(($x1 + $x2) / 2 - $tw($t, $size, $font) / 2), (int)$y, $c, $font, $L($t)); };
    $box = function ($x1, $y1, $x2, $y2, $fill, $border) use ($im) { imagefilledrectangle($im, $x1, $y1, $x2, $y2, $fill); imagerectangle($im, $x1, $y1, $x2, $y2, $border); };

    // ---- زمینه و عنوانِ صفحه ----
    imagefill($im, 0, 0, $col('#eeeeee'));
    imagefilledrectangle($im, 0, 0, $W, 40, $col('#f5f5f5'));
    imageline($im, 0, 40, $W, 40, $col('#d9d9d9'));
    $ctext('استعلام اطلاعات بیمه خودرو کشور', 0, $W, 27, 11.5, $col('#444444'), $FB);

    // ---- کارتِ فرمِ جستجو ----
    $box(17, 52, $W - 7, 262, $white, $col('#e3e3e3'));
    $lbl = $col('#333333'); $bd = $col('#9a9a9a');
    $radio = function ($cx, $cy, $on) use ($im, $col) {
        imagefilledellipse($im, $cx, $cy, 13, 13, $col('#8a8a8a')); imagefilledellipse($im, $cx, $cy, 11, 11, $col('#f4f4f4'));
        if ($on) imagefilledellipse($im, $cx, $cy, 6, 6, $col('#555555'));
    };
    $damage = (string)($q['plcy'] ?? '0') === '1';
    $rtext('نوع جستجو:', 1792, 87, 9.5, $lbl);
    $radio(1707, 83, !$damage); $rtext('بیمه نامه', 1692, 88, 9.5, $lbl);
    $radio(1606, 83, $damage); $rtext('زیاندیده', 1591, 88, 9.5, $lbl);
    $select = function ($x1, $x2, $y, $v) use ($box, $im, $col, $rtext, $white, $bd, $ink) {
        $box($x1, $y, $x2, $y + 19, $white, $bd);
        $box($x1, $y, $x1 + 16, $y + 19, $col('#e9e9e9'), $bd);
        imagefilledpolygon($im, [$x1 + 4, $y + 8, $x1 + 12, $y + 8, $x1 + 8, $y + 13], 3, $col('#333333'));
        $rtext($v, $x2 - 4, $y + 15, 9.5, $ink);
    };
    $stype = SH_TYPES[(string)($q['type'] ?? '')] ?? 'شماره شاسی';
    $rtext('جستجو بر اساس:', 1825, 127, 9.5, $lbl); $select(1535, 1727, 113, $stype);
    $rtext('رشته:', 1427, 127, 9.5, $lbl); $select(1200, 1392, 113, 'همه موارد');
    $rtext('شرکت:', 1118, 127, 9.5, $lbl); $select(886, 1077, 113, 'همه موارد');
    $rtext('مورد جستجو:', 1830, 154, 9.5, $lbl);
    $box(1537, 139, 1727, 160, $white, $col('#bdbdbd'));
    $rtext((string)($r['query_text'] ?? ''), 1723, 155, 9.5, $ink);
    $rtext('حداقل 5 کاراکتر', 1727, 177, 9.5, $lbl);
    // کپچا
    $code = (string)random_int(1000, 9999);
    $box(1226, 138, 1393, 184, $col('#e4ebd9'), $col('#b9c4a6'));
    for ($i = 0; $i < 6; $i++) imageline($im, random_int(1228, 1390), random_int(140, 182), random_int(1228, 1390), random_int(140, 182), $col('#c9d3b8'));
    $cx = 1282;
    foreach (str_split($code) as $ch) { imagettftext($im, 21, random_int(-12, 12), $cx, 173, $col('#2a2a2a'), $FB, $ch); $cx += 19; }
    $blue = $col('#3b78c4');
    imagesetthickness($im, 2); imagearc($im, 1216, 174, 13, 13, 30, 300, $blue); imagesetthickness($im, 1);
    imagefilledpolygon($im, [1219, 165, 1225, 169, 1218, 172], 3, $blue);
    $rtext('کد بالا را وارد کنید', 1393, 202, 9.5, $lbl);
    $box(1259, 216, 1393, 238, $white, $col('#bdbdbd'));
    $rtext($code, 1389, 232, 10, $ink);
    // دکمه‌ی استعلام
    $box(941, 153, 1077, 178, $col('#e8e8d8'), $col('#4d4d4d'));
    imagerectangle($im, 943, 155, 1075, 176, $col('#b8b8a0'));
    $ctext('استعلام', 941, 1077, 171, 10, $ink);

    // ---- نتیجه: کادرِ خط‌چین، سرتیتر ----
    imageline($im, 17, 285, $W - 7, 285, $col('#d9d9d9'));
    $dash = $col('#9fbf86');
    imagesetstyle($im, [$dash, $dash, $dash, $white, $white]);
    imagefilledrectangle($im, 18, 310, $W - 10, $H, $white);
    imagerectangle($im, 18, 310, $W - 10, $H + 5, IMG_COLOR_STYLED);
    imagefilledrectangle($im, 1783, 320, 1873, 347, $col('#f47b20'));
    $ctext('دانلود PDF', 1783, 1873, 339, 10, $white);
    $box(1527, 321, 1665, 346, $col('#eef6e4'), $col('#a6c48c'));
    $ctext('چاپ', 1527, 1665, 339, 10, $ink);
    $rtext('تعداد رکوردهای یافت شده: ' . count($rows) . ' مورد', 1503, 339, 10.5, $ink);
    $who = trim((string)($res['sanhab']['inquirerName'] ?? '')) ?: trim((string)($res['sanhab']['inquirer'] ?? ''));
    if ($who !== '') $rtext('نام کاربری استعلام کننده: ' . $who, 960, 339, 10.5, $ink);
    if (!function_exists('jalali_from_gregorian_ts_dotted')) require_once __DIR__ . '/_case_helpers.php';
    $at = (string)($r['created_at'] ?? date('Y-m-d H:i:s'));
    $jd = function_exists('jdate_short') ? jdate_short(substr($at, 0, 10)) : substr($at, 0, 10);
    $rtext('تاریخ استعلام: ' . $jd . ' ' . substr($at, 11, 5), 230, 339, 10.5, $ink);
    imagefilledrectangle($im, 22, 351, $W - 12, 352, $col('#2c5aa0'));

    // ---- جدولِ اصلی ----
    $right = $W - 11;
    $hdrBg = $col('#3e6ab3'); $hdrLine = $col('#6f92cc'); $cell = $col('#d5dbe6');
    $drawHead = function ($cols, $y1, $y2, $bg, $line, $tc, $size, $font, $x1 = null) use ($im, $right, $wrap, $ctext) {
        $x = $right;
        imagefilledrectangle($im, $x1 ?? 22, $y1, $right, $y2, $bg);
        foreach ($cols as [$t, $w]) {
            $lines = $wrap($t, $size, $font, $w - 6); $n = count($lines);
            $yy = (int)(($y1 + $y2) / 2 - ($n * 15) / 2 + 12);
            foreach ($lines as $i => $ln) $ctext($ln, $x - $w, $x, $yy + $i * 15, $size, $tc, $font);
            $x -= $w;
            if ($x > ($x1 ?? 22)) imageline($im, $x, $y1, $x, $y2, $line);
        }
    };
    $drawHead($mainCols, 356, 436, $hdrBg, $hdrLine, $white, 8.6, $FB);
    imagefilledpolygon($im, [541, 393, 549, 393, 545, 399], 3, $white);   // فلشِ مرتب‌سازیِ «تاریخ شروع»

    $y = $top;
    foreach ($rows as $ri => $x) {
        if ($y > $H) break;
        // ردیفِ اصلی
        $h = $mainH($x);
        imagefilledrectangle($im, 22, $y - 12, $right, $y - 12 + $h, $white);
        imagerectangle($im, 22, $y - 12, $right, $y - 12 + $h, $cell);
        $xx = $right;
        foreach ($mainVals($x) as $i => $v) {
            $w = $mainCols[$i][1];
            if ($i === 0) { imagefilledrectangle($im, $xx - $w / 2 - 7, $y - 12 + $h / 2 - 7, $xx - $w / 2 + 7, $y - 12 + $h / 2 + 7, $col('#3a5ea8')); imagerectangle($im, $xx - $w / 2 - 7, $y - 12 + $h / 2 - 7, $xx - $w / 2 + 7, $y - 12 + $h / 2 + 7, $col('#24427a')); }
            else {
                $lines = $wrap($v, 9, $F, $w - 6); $n = count($lines);
                $yy = (int)($y - 12 + $h / 2 - ($n * 15) / 2 + 12);
                foreach ($lines as $k => $ln) $ctext($ln, $xx - $w, $xx, $yy + $k * 15, 9, $ink, ($i === 5 || $i === 4) ? $F : $F);
            }
            $xx -= $w;
            imageline($im, $xx, $y - 12, $xx, $y - 12 + $h, $cell);
        }
        $y += $h + 6;
        // الحاقیه‌ها (زیرجدولِ سبز)
        if (!empty($x['endorsements'])) {
            $drawHead($endCols, $y - 12, $y + 26, $col('#93d96f'), $col('#7cc257'), $col('#1f2d16'), 8.8, $F, 26);
            $y += 38;
            foreach ($x['endorsements'] as $e) {
                imagefilledrectangle($im, 26, $y - 12, $right, $y + 26, $white);
                imagerectangle($im, 26, $y - 12, $right, $y + 26, $col('#c8c8c8'));
                $xx = $right;
                foreach ($endVals($e) as $i => $v) {
                    $w = $endCols[$i][1];
                    $lines = $wrap($v, 8.8, $F, $w - 8);
                    foreach (array_slice($lines, 0, 2) as $k => $ln) $rtext($ln, $xx - 6, $y + 13 - (count($lines) > 1 ? 7 : 0) + $k * 14, 8.8, $grey);
                    $xx -= $w;
                    if ($xx > 26) imageline($im, $xx, $y - 12, $xx, $y + 26, $col('#c8c8c8'));
                }
                $y += 38;
            }
            $y += 4;
        }
        // خسارت‌ها (زیرجدولِ قرمز)
        if (!empty($x['losses'])) {
            $drawHead($lossCols, $y - 12, $y + 12, $col('#f48a8a'), $col('#e06f6f'), $col('#3a1010'), 9, $F, 26);
            $y += 24;
            foreach ($x['losses'] as $l) {
                imagefilledrectangle($im, 26, $y - 12, $right, $y + 11, $white);
                imagerectangle($im, 26, $y - 12, $right, $y + 11, $col('#c8c8c8'));
                $xx = $right;
                foreach ($lossVals($l) as $i => $v) {
                    $w = $lossCols[$i][1];
                    $rtext($v, $xx - 6, $y + 5, 9, $grey);
                    $xx -= $w;
                    if ($xx > 26) imageline($im, $xx, $y - 12, $xx, $y + 11, $col('#c8c8c8'));
                }
                $y += 23;
            }
            $y += 4;
        }
        $y += 22;
    }
    if (!$rows) $ctext('رکوردی یافت نشد', 22, $right, 470, 10.5, $grey);
    $ok = imagepng($im, $dest, 6);
    imagedestroy($im);
    return $ok && is_file($dest);
}
