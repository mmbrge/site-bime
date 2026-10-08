<?php
// فایل: api/edit_lock.php
// «چه کسی همین الان این را باز کرده؟» — برای کار کردنِ هم‌زمانِ چند همکار روی یک ردیف/پرونده/بیمه‌نامه:
// هر پنجره‌ی ویرایش که عنصری با data-lock="کلید" دارد (edit-lock.js) هر ۱۵ ثانیه حضورش را اعلام می‌کند و اگر همکارِ دیگری
// همان را باز کرده باشد، هشدار می‌بیند تا ویرایش‌ها روی هم نیفتد. چیزی قفل نمی‌شود؛ فقط خبر می‌دهد.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false]);
$uid = intval($_SESSION['user_id']);
session_write_close();
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$clean = function ($a) { return array_values(array_unique(array_filter(array_map(function ($k) { return preg_match('/^[A-Za-z_]{1,20}:[A-Za-z0-9_.-]{1,40}$/', (string)$k) ? (string)$k : ''; }, (array)$a)))); };
$keys = array_slice($clean($data['keys'] ?? []), 0, 30);
$release = array_slice($clean($data['release'] ?? []), 0, 60);
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS edit_locks (lock_key VARCHAR(64) NOT NULL, user_id INT NOT NULL, seen_at DATETIME NOT NULL,
                PRIMARY KEY (lock_key, user_id), KEY k_seen (seen_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($release) {
        $pdo->prepare("DELETE FROM edit_locks WHERE user_id = ? AND lock_key IN (" . implode(',', array_fill(0, count($release), '?')) . ")")->execute(array_merge([$uid], $release));
    }
    $others = [];
    if ($keys) {
        $ins = $pdo->prepare("INSERT INTO edit_locks (lock_key, user_id, seen_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE seen_at = NOW()");
        foreach ($keys as $k) $ins->execute([$k, $uid]);
        $st = $pdo->prepare("SELECT l.lock_key, u.full_name, TIMESTAMPDIFF(SECOND, l.seen_at, NOW()) AS ago FROM edit_locks l JOIN users u ON u.id = l.user_id
                              WHERE l.user_id <> ? AND l.seen_at >= NOW() - INTERVAL 45 SECOND AND l.lock_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
        $st->execute(array_merge([$uid], $keys));
        foreach ($st->fetchAll() as $r) $others[$r['lock_key']][] = $r['full_name'];
    }
    if (mt_rand(1, 50) === 1) $pdo->exec("DELETE FROM edit_locks WHERE seen_at < NOW() - INTERVAL 1 DAY");
    $out(['ok' => true, 'others' => (object)$others]);
} catch (Throwable $e) {
    $out(['ok' => false]);
}
