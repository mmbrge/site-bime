<?php
// فایل: api/webapp_client_log.php
// گزارشِ خطای مینی‌اپ از خودِ گوشیِ کاربر (webapp/diag.js) - برای پیدا کردنِ مشکلاتی که فقط داخلِ WebViewِ بله پیش می‌آیند.
// در «تنظیمات ← مینی‌اپِ کاربران ← گزارشِ خطاهای مینی‌اپ» دیده می‌شود (api/miniapp_admin.php → client_errors).
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';

try {
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 20000) { echo json_encode(['ok' => false]); exit; }
    $in = json_decode($raw, true) ?: [];
    // همین CREATE در api/miniapp_admin.php (client_errors) هم هست
    $pdo->exec("CREATE TABLE IF NOT EXISTS webapp_client_errors (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        person_id INT NULL, page VARCHAR(190) NULL, ua VARCHAR(400) NULL, errors_text TEXT NULL, info_json TEXT NULL, ip VARCHAR(45) NULL,
        KEY k_created (created_at)) DEFAULT CHARSET=utf8mb4");
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    // جلوی پرشدنِ جدول: حداکثر ۳۰ گزارش در ساعت از هر IP
    $st = $pdo->prepare("SELECT COUNT(*) FROM webapp_client_errors WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 30) { echo json_encode(['ok' => true]); exit; }

    $personId = null;
    $token = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['token'] ?? '')), 0, 100);
    if ($token !== '') {
        $st = $pdo->prepare("SELECT person_id FROM webapp_sessions WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        $personId = $st->fetchColumn() ?: null;
    }
    $errs = array_slice(array_map(function ($s) { return mb_substr((string)$s, 0, 600); }, (array)($in['errors'] ?? [])), 0, 20);
    $info = is_array($in['info'] ?? null) ? $in['info'] : [];
    foreach ($info as $k => $v) $info[$k] = is_scalar($v) ? mb_substr((string)$v, 0, 300) : '';
    $ins = $pdo->prepare("INSERT INTO webapp_client_errors (person_id, page, ua, errors_text, info_json, ip) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->execute([$personId, mb_substr((string)($in['page'] ?? ''), 0, 190), mb_substr((string)($info['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 400),
                   implode("\n", $errs), json_encode($info, JSON_UNESCAPED_UNICODE), $ip]);
    // فقط ۳۰ روزِ اخیر نگه داشته می‌شود
    if (mt_rand(1, 20) === 1) $pdo->exec("DELETE FROM webapp_client_errors WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false]);
}
