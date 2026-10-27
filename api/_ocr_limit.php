<?php
// فایل: api/_ocr_limit.php
// سقفِ روزانه‌ی «پردازش فایل» در استعلامات (تعدادِ صفحه برای هر کاربر در روز) — از «کاربران ← دسترسی‌ها» تنظیم می‌شود.
//   users.ocr_daily_limit: NULL = بدونِ سقف، ۰ = بدونِ سقف، n = حداکثر n صفحه در روز؛ مدیر کل سقف ندارد.

function ocrl_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'ocr_daily_limit'")->fetch()) $pdo->exec("ALTER TABLE users ADD COLUMN ocr_daily_limit INT NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS inq_ocr_usage (user_id INT NOT NULL, day DATE NOT NULL, pages INT NOT NULL DEFAULT 0, PRIMARY KEY (user_id, day))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('[ocr limit schema] ' . $e->getMessage()); }
}
function ocrl_limit($pdo, $uid) {
    ocrl_ensure($pdo);
    try { $st = $pdo->prepare("SELECT ocr_daily_limit FROM users WHERE id = ?"); $st->execute([intval($uid)]); $v = $st->fetchColumn(); return $v === null || $v === false ? 0 : max(0, intval($v)); }
    catch (Throwable $e) { return 0; }
}
function ocrl_used($pdo, $uid) {
    ocrl_ensure($pdo);
    try { $st = $pdo->prepare("SELECT pages FROM inq_ocr_usage WHERE user_id = ? AND day = CURDATE()"); $st->execute([intval($uid)]); return intval($st->fetchColumn()); }
    catch (Throwable $e) { return 0; }
}
function ocrl_add($pdo, $uid, $n = 1) {
    try { $pdo->prepare("INSERT INTO inq_ocr_usage (user_id, day, pages) VALUES (?, CURDATE(), ?) ON DUPLICATE KEY UPDATE pages = pages + VALUES(pages)")->execute([intval($uid), intval($n)]); } catch (Throwable $e) {}
}
function ocrl_set($pdo, $uid, $v) {
    ocrl_ensure($pdo);
    $v = trim(p2e_digits((string)$v));
    $pdo->prepare("UPDATE users SET ocr_daily_limit = ? WHERE id = ?")->execute([$v === '' ? null : max(0, intval(preg_replace('/\D/', '', $v))), intval($uid)]);
}
