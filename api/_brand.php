<?php
// فایل: api/_brand.php
// لوگو و فاوآیکنِ سایت (از «تنظیمات»): فایل‌ها در uploads/brand، نام و نسخه در system_settings.
// لوگو جای «مربع + بیمه با ما»ی سربرگ پنل می‌نشیند؛ فاوآیکن در همه‌ی صفحه‌ها.

const BRAND_DIR = 'uploads/brand';
const BRAND_TYPES = [
    'logo'    => ['svg' => 'image/svg+xml', 'png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'],
    'favicon' => ['ico' => 'image/x-icon', 'png' => 'image/png', 'svg' => 'image/svg+xml'],
];

function brand_root() { return dirname(__DIR__); }

// {logo: {file, v, w, h} | null, favicon: {file, v} | null}
function brand_get($pdo, $fresh = false) {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = ['logo' => null, 'favicon' => null];
    try {
        $st = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('brand_logo', 'brand_favicon')");
        foreach ($st->fetchAll() as $r) {
            $k = $r['setting_key'] === 'brand_logo' ? 'logo' : 'favicon';
            $v = json_decode((string)$r['setting_value'], true);
            if (is_array($v) && !empty($v['file']) && is_file(brand_root() . '/' . BRAND_DIR . '/' . basename($v['file']))) $cache[$k] = $v;
        }
    } catch (Throwable $e) {}
    return $cache;
}
function brand_set($pdo, $kind, $val) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute(['brand_' . $kind, $val === null ? '' : json_encode($val, JSON_UNESCAPED_UNICODE)]);
}

// آدرسِ فایل نسبت به صفحه (prefix: '' برای صفحه‌های ریشه، '../' برای company-portal، '/' برای مسیرِ مطلق)
function brand_url($pdo, $kind, $prefix = '') {
    $b = brand_get($pdo)[$kind];
    return $b ? $prefix . BRAND_DIR . '/' . rawurlencode(basename($b['file'])) . '?v=' . intval($b['v']) : null;
}
function brand_favicon_tag($pdo, $prefix = '') {
    $b = brand_get($pdo)['favicon'];
    if (!$b) return '';
    $ext = strtolower(pathinfo($b['file'], PATHINFO_EXTENSION));
    $type = BRAND_TYPES['favicon'][$ext] ?? 'image/png';
    $u = htmlspecialchars(brand_url($pdo, 'favicon', $prefix), ENT_QUOTES);
    return '<link rel="icon" type="' . $type . '" href="' . $u . '">' . ($ext === 'png' ? '<link rel="apple-touch-icon" href="' . $u . '">' : '');
}

// SVG فقط وقتی پذیرفته می‌شود که اسکریپت، رویداد، لینکِ جاوااسکریپت یا محتوای بیرونی نداشته باشد
function brand_svg_safe($svg) {
    if (!preg_match('/<svg[\s>]/i', $svg)) return 'فایل SVG معتبر نیست.';
    $bad = ['/<script/i', '/\son[a-z]+\s*=/i', '/javascript\s*:/i', '/<foreignObject/i', '/<iframe|<embed|<object/i', '/<!ENTITY/i', '/(?:xlink:)?href\s*=\s*["\']\s*(?:https?:|\/\/)/i'];
    foreach ($bad as $re) if (preg_match($re, $svg)) return 'این SVG کد یا لینکِ بیرونی دارد و برای امنیت پذیرفته نمی‌شود؛ آن را بدونِ اسکریپت ذخیره کنید (یا PNG بفرستید).';
    return null;
}

// ذخیره‌ی فایلِ آپلودشده؛ ['ok' => true, 'brand' => ...] یا ['ok' => false, 'error' => ...]
function brand_store($pdo, $kind, array $f) {
    if (!isset(BRAND_TYPES[$kind])) return ['ok' => false, 'error' => 'نوع نامعتبر است.'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return ['ok' => false, 'error' => 'فایلی دریافت نشد.'];
    if ($f['size'] > 2 * 1024 * 1024) return ['ok' => false, 'error' => 'حجمِ فایل باید کمتر از ۲ مگابایت باشد.'];
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $allowed = BRAND_TYPES[$kind];
    if (!isset($allowed[$ext])) return ['ok' => false, 'error' => 'فقط این قالب‌ها: ' . implode('، ', array_map('strtoupper', array_keys($allowed)))];
    $bin = file_get_contents($f['tmp_name']);
    $meta = ['w' => 0, 'h' => 0];
    if ($ext === 'svg') {
        if ($err = brand_svg_safe($bin)) return ['ok' => false, 'error' => $err];
        // نسبتِ طول به عرض از viewBox یا width/height
        if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $bin, $m)) $meta = ['w' => (float)$m[1], 'h' => (float)$m[2]];
    } elseif ($ext === 'ico') {
        if (substr($bin, 0, 4) !== "\x00\x00\x01\x00") return ['ok' => false, 'error' => 'فایلِ ICO معتبر نیست.'];
    } else {
        $info = @getimagesizefromstring($bin);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/webp', 'image/jpeg'], true)) return ['ok' => false, 'error' => 'فایلِ تصویر معتبر نیست.'];
        $meta = ['w' => intval($info[0]), 'h' => intval($info[1])];
    }
    $dir = brand_root() . '/' . BRAND_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['ok' => false, 'error' => 'پوشه‌ی uploads/brand ساخته نشد (دسترسیِ نوشتن را بررسی کنید).'];
    brand_protect_dir($dir);
    $old = brand_get($pdo)[$kind];
    $name = $kind . '-' . date('YmdHis') . '.' . $ext;
    if (file_put_contents($dir . '/' . $name, $bin) === false) return ['ok' => false, 'error' => 'فایل ذخیره نشد.'];
    if ($old && basename($old['file']) !== $name) @unlink($dir . '/' . basename($old['file']));
    $val = ['file' => $name, 'v' => time(), 'w' => $meta['w'], 'h' => $meta['h']];
    brand_set($pdo, $kind, $val);
    brand_get($pdo, true);
    return ['ok' => true, 'brand' => $val];
}
function brand_remove($pdo, $kind) {
    $old = brand_get($pdo)[$kind] ?? null;
    if ($old) @unlink(brand_root() . '/' . BRAND_DIR . '/' . basename($old['file']));
    brand_set($pdo, $kind, null);
    brand_get($pdo, true);
}
// پوشه‌ی لوگو: اسکریپت اجرا نشود و SVG حتی اگر مستقیم باز شود کدی اجرا نکند
function brand_protect_dir($dir) {
    $h = $dir . '/.htaccess';
    if (is_file($h)) return;
    @file_put_contents($h, "# لوگو و فاوآیکن: فقط تصویر\nOptions -Indexes\n<FilesMatch \"(?i)\\.(php\\d?|phtml|phar|pht|cgi|pl|py|sh|htaccess)(\\.|\$)\">\n    Require all denied\n</FilesMatch>\n"
        . "<IfModule mod_headers.c>\n    <FilesMatch \"(?i)\\.svg\$\">\n        Header set Content-Security-Policy \"default-src 'none'; style-src 'unsafe-inline'; img-src data:\"\n        Header set X-Content-Type-Options \"nosniff\"\n    </FilesMatch>\n</IfModule>\n");
}
