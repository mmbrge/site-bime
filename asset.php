<?php
// فایل: asset.php
// چند فایلِ JS/CSSِ سایت در یک پاسخ، فشرده (gzip) و با کشِ یک‌ساله (نسخه در آدرس است) — api/_assets.php
//   asset.php?f=a.js,b.js&v=…
require_once __DIR__ . '/api/_assets.php';

$list = array_values(array_filter(array_map('asset_ok_path', explode(',', (string)($_GET['f'] ?? '')))));
if (!$list || count($list) > 80) { http_response_code(404); exit; }
$exts = array_unique(array_map(function ($f) { return pathinfo($f, PATHINFO_EXTENSION); }, $list));
if (count($exts) !== 1) { http_response_code(400); exit; }
$isCss = $exts[0] === 'css';

$root = __DIR__;
$sig = '';
foreach ($list as $f) {
    $p = $root . '/' . $f;
    if (!is_file($p)) { http_response_code(404); exit; }
    $sig .= $f . ':' . asset_fhash($p) . ';';
}
$etag = '"' . md5($sig) . '"';
header('Content-Type: ' . ($isCss ? 'text/css' : 'application/javascript') . '; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
header('Vary: Accept-Encoding');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }

// نسخه‌ی فشرده یک بار ساخته و در cache/assets نگه داشته می‌شود
$gz = function_exists('gzencode') && stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false && !ini_get('zlib.output_compression');
$dir = $root . '/cache/assets';
$key = $dir . '/' . md5($sig) . ($isCss ? '.css' : '.js');
$build = function () use ($list, $root, $isCss) {
    $out = '';
    foreach ($list as $f) $out .= ($isCss ? "/* $f */\n" : "/* $f */\n") . file_get_contents($root . '/' . $f) . ($isCss ? "\n" : "\n;\n");
    return $out;
};
if (!is_file($key . '.gz')) {
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"); }
    $body = $build();
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($key . '.tmp', $body, LOCK_EX); @rename($key . '.tmp', $key);
        if (function_exists('gzencode')) { @file_put_contents($key . '.gz.tmp', gzencode($body, 7), LOCK_EX); @rename($key . '.gz.tmp', $key . '.gz'); }
        // نسخه‌های قدیمیِ بی‌استفاده (بیش از ۲۰ روز) پاک می‌شوند
        if (mt_rand(1, 40) === 1) foreach ((array)glob($dir . '/*') as $old) if (substr($old, -9) !== '.htaccess') if (@filemtime($old) < time() - 20 * 86400) @unlink($old);
    }
}
if ($gz && is_file($key . '.gz')) { header('Content-Encoding: gzip'); header('Content-Length: ' . filesize($key . '.gz')); readfile($key . '.gz'); exit; }
if (is_file($key)) { header('Content-Length: ' . filesize($key)); readfile($key); exit; }
$body = isset($body) ? $body : $build();
if ($gz) { $z = gzencode($body, 6); header('Content-Encoding: gzip'); header('Content-Length: ' . strlen($z)); echo $z; exit; }
echo $body;
