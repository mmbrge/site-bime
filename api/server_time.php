<?php
// فایل: api/server_time.php
// ساعتِ دقیقِ سرور (میلی‌ثانیه) برای iran-time.js؛ همه‌ی ساعت‌های پنل بر اساسِ همین است، نه ساعتِ سیستمِ کاربر
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
echo json_encode(['ok' => true, 'ms' => (int)round(microtime(true) * 1000)]);
