<?php
// فایل: api/_heic.php
// عکس‌های HEIC/HEIF (آیفون) در همه‌ی بخش‌های سایت پذیرفته می‌شوند: همان لحظه‌ی رسیدنِ درخواست (config/db.php)
// هر فایلِ HEIC در $_FILES به PNG تبدیل و محتوای همان فایلِ موقت با PNG جایگزین می‌شود (نام ‎.png و نوع image/png).
// پس همه‌ی کدهای بارگذاری بدونِ تغییر، یک PNGِ معمولی می‌بینند و همان PNG در بایگانی ذخیره می‌شود.
// تبدیل به ترتیب با: Imagick (اگر HEIC را بشناسد)، پایتون + pillow-heif (همان پایتونِ OCR)، یا heif-convert / ImageMagick.

function heic_is_file($name, $type, $path) {
    if (preg_match('/\.(heic|heif|hif)$/i', (string)$name)) return true;
    if (preg_match('#^image/hei[cf](-sequence)?$#i', (string)$type)) return true;
    // بعضی گوشی‌ها پسوند را jpg می‌گذارند ولی محتوا HEIC است: امضای «ftypheic / ftypmif1 / ...»
    $h = @file_get_contents($path, false, null, 4, 8);
    return is_string($h) && preg_match('/^ftyp(heic|heix|hevc|hevx|heim|heis|mif1|msf1)$/', $h);
}

function heic_python($pdo) {
    $def = '/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3';
    try {
        if ($pdo) {
            $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'report_python_path'");
            $st->execute();
            $v = trim((string)$st->fetchColumn());
            if ($v !== '') return $v;
        }
    } catch (Throwable $e) {}
    return is_file($def) ? $def : 'python3';
}

// $src (HEIC) => $dest (PNG). خروجی: true/false
function heic_to_png($pdo, $src, $dest) {
    // ۱) Imagick
    if (class_exists('Imagick')) {
        try {
            if (in_array('HEIC', Imagick::queryFormats('HEI*'), true)) {
                $im = new Imagick($src);
                if (method_exists($im, 'autoOrient')) $im->autoOrient();
                $im->setImageFormat('png');
                $ok = $im->writeImage($dest);
                $im->clear();
                if ($ok && is_file($dest) && filesize($dest) > 0) return true;
            }
        } catch (Throwable $e) {}
    }
    $canProc = function_exists('proc_open');
    // ۲) پایتون + pillow-heif
    if ($canProc) {
        $home = getenv('HOME');
        if (!$home && preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m)) $home = $m[1];
        $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $home ?: sys_get_temp_dir(), 'PYTHONWARNINGS' => 'ignore'];
        $proc = @proc_open([heic_python($pdo), __DIR__ . '/py/heic_convert.py', $src, $dest], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (is_resource($proc)) {
            $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
            $j = json_decode(trim((string)substr($out, strrpos("\n" . trim($out), "\n"))), true);
            if (!empty($j['ok']) && is_file($dest) && filesize($dest) > 0) return true;
        }
    }
    // ۳) ابزارهای خطِ فرمان
    if ($canProc) {
        foreach ([['heif-convert', $src, $dest], ['magick', $src, $dest], ['convert', $src, $dest]] as $cmd) {
            $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) continue;
            stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
            if (is_file($dest) && filesize($dest) > 0) return true;
        }
    }
    return false;
}

// همه‌ی فایل‌های HEIC در $_FILES (یک‌تایی یا آرایه‌ای مثل files[]) => PNG
function heic_normalize_uploads($pdo) {
    if (empty($_FILES)) return;
    $fix = function (&$name, &$type, &$tmp, &$err, &$size) use ($pdo) {
        if (!is_string($tmp) || $tmp === '' || (int)$err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) return;
        if (!heic_is_file($name, $type, $tmp)) return;
        $png = $tmp . '.png';
        if (!heic_to_png($pdo, $tmp, $png)) {
            error_log('[heic] تبدیل نشد: ' . $name);
            return;   // تبدیل نشد: فایل همان‌طور می‌ماند (کدِ بارگذاری خودش تصمیم می‌گیرد)
        }
        // محتوای همان فایلِ موقتِ آپلودشده با PNG عوض می‌شود تا move_uploaded_file همچنان کار کند
        if (@file_put_contents($tmp, (string)file_get_contents($png)) !== false) {
            $name = preg_replace('/\.(heic|heif|hif|jpe?g)$/i', '', (string)$name) . '.png';
            $type = 'image/png';
            clearstatcache(true, $tmp);
            $size = filesize($tmp);
        }
        @unlink($png);
    };
    foreach ($_FILES as $k => &$f) {
        if (!isset($f['tmp_name'])) continue;
        if (is_array($f['tmp_name'])) {
            foreach (array_keys($f['tmp_name']) as $i) {
                if (is_array($f['tmp_name'][$i])) continue;
                $fix($f['name'][$i], $f['type'][$i], $f['tmp_name'][$i], $f['error'][$i], $f['size'][$i]);
            }
        } else {
            $fix($f['name'], $f['type'], $f['tmp_name'], $f['error'], $f['size']);
        }
    }
    unset($f);
}

// فایلی که از راهِ دیگری (مثلاً دانلود از ربات بله) روی دیسک ذخیره شده: اگر HEIC است کنارش PNG ساخته و اصلی پاک می‌شود.
// خروجی: مسیرِ نهایی (PNG، یا همان مسیر اگر HEIC نبود / تبدیل نشد)
function heic_fix_saved_file($pdo, $abs) {
    if (!is_file($abs) || !heic_is_file($abs, '', $abs)) return $abs;
    $png = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $abs) . '.png';
    if (is_file($png)) $png = preg_replace('/\.png$/', '_' . mt_rand(1000, 9999) . '.png', $png);
    if (!heic_to_png($pdo, $abs, $png)) return $abs;
    @unlink($abs);
    return $png;
}
