<?php
// فایل: brand_icon.php
// فاوآیکنِ تنظیم‌شده برای صفحه‌های ثابت (مینی‌اپ و بازدید)؛ اگر تنظیم نشده باشد چیزی برنمی‌گرداند
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/api/_brand.php';
$b = brand_get($pdo)['favicon'];
$path = $b ? __DIR__ . '/' . BRAND_DIR . '/' . basename($b['file']) : null;
if (!$path || !is_file($path)) { http_response_code(404); exit; }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . (BRAND_TYPES['favicon'][$ext] ?? 'image/png'));
header('X-Content-Type-Options: nosniff');
if ($ext === 'svg') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($path));
readfile($path);
