<?php
// فایل: api/life_cron.php
// اجرای یادآوری‌های ربات بیمه عمر با کرونِ هاست (مثلاً هر ساعت):
//   curl -s "https://دامنه/api/life_cron.php?key=کلید"   (کلید در «بیمه عمر ← تنظیمات ← ربات بله» نمایش داده می‌شود)
// بدونِ کرون هم یادآوری‌ها با اولین استفاده از ربات یا پنل در هر ساعت فرستاده می‌شوند.
require '../config/db.php';
require_once __DIR__ . '/_life_bot.php';
header('Content-Type: application/json; charset=utf-8');
lbot_ensure($pdo);
$s = lbot_settings($pdo);
if (!hash_equals((string)$s['cron_key'], (string)($_GET['key'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
@set_time_limit(300);
echo json_encode(['ok' => true] + lbot_run_reminders($pdo, !empty($_GET['force'])), JSON_UNESCAPED_UNICODE);
