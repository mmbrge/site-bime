<?php
// فایل: api/_work_track.php
// ساختِ جدول‌های «کارکرد» و ثبتِ خودکارِ حضور در پنل و کارهای انجام‌شده. هیچ فایلِ دیگری را include نمی‌کند
// (از perm_gate در همه‌ی APIها صدا زده می‌شود؛ نباید با include شدنِ کمکی‌ها تداخل ایجاد کند).

function wk_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $opt = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sql = [
        "CREATE TABLE IF NOT EXISTS work_days (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, wdate DATE NOT NULL,
            check_in TIME NULL, check_out TIME NULL, day_type VARCHAR(16) NOT NULL DEFAULT 'WORK', mood TINYINT NULL, note TEXT NULL,
            tasks MEDIUMTEXT NULL, updated_at DATETIME NULL, updated_by INT NULL, UNIQUE KEY uq_user_day (user_id, wdate)) $opt",
        "CREATE TABLE IF NOT EXISTS work_presence (user_id INT NOT NULL, wdate DATE NOT NULL, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL,
            hits INT NOT NULL DEFAULT 1, PRIMARY KEY (user_id, wdate)) $opt",
        "CREATE TABLE IF NOT EXISTS work_activity (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, at DATETIME NOT NULL, wdate DATE NOT NULL,
            page VARCHAR(40) NULL, action VARCHAR(60) NULL, op VARCHAR(10) NULL, label VARCHAR(190) NULL, ref VARCHAR(190) NULL, KEY k_user_day (user_id, wdate)) $opt",
        "CREATE TABLE IF NOT EXISTS work_leaves (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, kind VARCHAR(8) NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL,
            time_from TIME NULL, time_to TIME NULL, minutes INT NOT NULL DEFAULT 0, reason VARCHAR(500) NULL, status VARCHAR(12) NOT NULL DEFAULT 'APPROVED',
            created_at DATETIME NULL, created_by INT NULL, decided_by INT NULL, decided_at DATETIME NULL, decide_note VARCHAR(300) NULL, KEY k_user (user_id, date_from)) $opt",
        "CREATE TABLE IF NOT EXISTS work_profiles (user_id INT PRIMARY KEY, opening_minutes INT NULL, opening_jy SMALLINT NULL, opening_jm TINYINT NULL,
            set_at DATETIME NULL, set_by INT NULL) $opt",
    ];
    foreach ($sql as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[wk_ensure] ' . $e->getMessage()); } }
    // تعدادِ هر کار (صدورِ گروهی = چند فقره در یک سطر)
    try {
        if (!$pdo->query("SHOW COLUMNS FROM work_activity LIKE 'qty'")->fetch())
            $pdo->exec("ALTER TABLE work_activity ADD COLUMN qty INT NOT NULL DEFAULT 1");
    } catch (Throwable $e) { error_log('[wk_ensure qty] ' . $e->getMessage()); }
}

// ---------------- ثبتِ خودکار: حضور در پنل و کارهای انجام‌شده (از perm_gate) ----------------
function wk_touch_presence($pdo, $uid) {
    try {
        $pdo->prepare("INSERT INTO work_presence (user_id, wdate, first_seen, last_seen, hits) VALUES (?, CURDATE(), NOW(), NOW(), 1)
                       ON DUPLICATE KEY UPDATE last_seen = NOW(), hits = hits + 1")->execute([$uid]);
    } catch (Throwable $e) {
        wk_ensure($pdo);
        try { $pdo->prepare("INSERT IGNORE INTO work_presence (user_id, wdate, first_seen, last_seen, hits) VALUES (?, CURDATE(), NOW(), NOW(), 1)")->execute([$uid]); } catch (Throwable $e2) {}
    }
}
function wk_log_activity($pdo, $uid, $page, $action, $op, $label, $ref, $qty = 1) {
    $args = [$uid, mb_substr((string)$page, 0, 40), mb_substr((string)$action, 0, 60), $op, mb_substr((string)$label, 0, 190), $ref !== '' ? mb_substr((string)$ref, 0, 190) : null, max(1, intval($qty))];
    $sql = "INSERT INTO work_activity (user_id, at, wdate, page, action, op, label, ref, qty) VALUES (?, NOW(), CURDATE(), ?, ?, ?, ?, ?, ?)";
    try { $pdo->prepare($sql)->execute($args); }
    catch (Throwable $e) {
        wk_ensure($pdo);   // جدول یا ستونِ تعداد هنوز ساخته نشده
        try { $pdo->prepare($sql)->execute($args); } catch (Throwable $e2) {}
    }
}

