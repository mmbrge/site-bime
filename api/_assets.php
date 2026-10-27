<?php
// فایل: api/_assets.php
// بارگذاریِ سریع‌ترِ صفحه‌ها: چند فایلِ JS پشتِ سرِ هم در یک درخواست (asset.php)، فشرده (gzip) و با کشِ یک‌ساله‌ی مرورگر.
// نسخه از روی زمانِ آخرین تغییرِ خودِ فایل‌ها ساخته می‌شود، پس با عوض شدنِ هر فایل، مرورگر خودکار نسخه‌ی تازه را می‌گیرد.

// فقط فایل‌های .js/.css داخلِ سایت (نه api، config، بایگانی و پوشه‌های خصوصی)
function asset_ok_path($rel) {
    $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false || !preg_match('~^[A-Za-z0-9_\-./]+\.(js|css)$~', $rel)) return false;
    if (preg_match('~^(api|config|Archive|queue|tmp_ocr|tmp_recon|tmpl_invoice|uploads|backup|cache|migrations|report_assets|_trash|_recon_files)/~i', $rel)) return false;
    return $rel;
}

// <script src="asset.php?f=a.js,b.js&v=…"> برای چند فایل (ترتیب حفظ می‌شود). اگر کتابخانه‌ی zlib نباشد، همان تگ‌های جدا.
function asset_js(array $files, $attr = '', $base = '') {
    $root = dirname(__DIR__);
    $ok = []; $sig = '';
    foreach ($files as $f) {
        $f = asset_ok_path($f);
        if (!$f || !is_file($root . '/' . $f)) continue;
        $ok[] = $f;
        $sig .= $f . ':' . filemtime($root . '/' . $f) . ':' . filesize($root . '/' . $f) . ';';
    }
    if (!$ok) return '';
    $v = substr(md5($sig), 0, 10);
    $attr = $attr !== '' ? ' ' . $attr : '';
    if (!function_exists('gzencode')) return implode('', array_map(function ($f) use ($v, $attr, $base) { return '<script src="' . $base . htmlspecialchars($f) . '?v=' . $v . '"' . $attr . '></script>'; }, $ok));
    return '<script src="' . $base . 'asset.php?f=' . htmlspecialchars(implode(',', $ok)) . '&amp;v=' . $v . '"' . $attr . '></script>';
}
// <link rel="stylesheet"> برای CSSهایی که آدرسِ نسبی (url(...)) ندارند
function asset_css(array $files, $base = '') {
    $root = dirname(__DIR__);
    $ok = []; $sig = '';
    foreach ($files as $f) { $f = asset_ok_path($f); if (!$f || !is_file($root . '/' . $f)) continue; $ok[] = $f; $sig .= $f . ':' . filemtime($root . '/' . $f) . ':' . filesize($root . '/' . $f) . ';'; }
    if (!$ok) return '';
    $v = substr(md5($sig), 0, 10);
    if (!function_exists('gzencode')) return implode('', array_map(function ($f) use ($v, $base) { return '<link rel="stylesheet" href="' . $base . htmlspecialchars($f) . '?v=' . $v . '">'; }, $ok));
    return '<link rel="stylesheet" href="' . $base . 'asset.php?f=' . htmlspecialchars(implode(',', $ok)) . '&amp;v=' . $v . '">';
}
// یک فایل با نسخه‌ی خودکار (برای جاهایی که بسته نمی‌شوند، مثل defer)
function asset_v($rel) {
    $root = dirname(__DIR__);
    $p = $root . '/' . ltrim($rel, '/');
    return htmlspecialchars($rel) . '?v=' . (is_file($p) ? substr(md5(filemtime($p) . ':' . filesize($p)), 0, 10) : '0');
}

// خروجیِ HTMLِ صفحه فشرده شود (اگر سرور خودش فشرده نمی‌کند)
function asset_gzip_page() {
    if (headers_sent() || ini_get('zlib.output_compression') || !function_exists('ob_gzhandler')) return;
    if (stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') === false) return;
    @ob_start('ob_gzhandler');
}

// پاسخِ JSONِ فهرست‌های بزرگ فشرده فرستاده شود (فقط برای اکشن‌هایی که فایل دانلود نمی‌دهند)
function asset_gzip_json() {
    if (headers_sent() || ini_get('zlib.output_compression') || !function_exists('ob_gzhandler')) return;
    if (stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') === false) return;
    @ob_start('ob_gzhandler');
}

// آدرسِ بسته (برای prefetch در صفحه‌ی ورود: تا رمز تایپ می‌شود، اسکریپتِ اصلیِ پنل در کشِ مرورگر بنشیند)
function asset_url(array $files) {
    $html = asset_js($files);
    return preg_match('/src="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}
