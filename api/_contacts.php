<?php
// فایل: api/_contacts.php
// «بانک مخاطبین»: چند شماره برای هر شخص + پیامِ همگانی در ربات.
//  - person_phones: همه‌ی شماره‌هایی که برای یک کد ملی آمده (اکسل، ویرایشِ پنل، ربات)؛ persons.mobile_number = شماره‌ی پیش‌فرض
//    (همان شماره‌ای که کدِ تأیید برایش پیامک می‌شود). شماره‌ی تازه پیش‌فرض می‌شود و قبلی‌ها در فهرست می‌مانند.
//  - contact_broadcasts / contact_broadcast_recipients: پیامِ همگانی به کسانی که در ربات هستند و گزارشِ ارسالِ هر نفر

// جدول‌های تازه با همان collationِ جدولِ persons ساخته می‌شوند تا JOIN/UNION روی شماره و کد ملی خطای «Illegal mix of collations» ندهد
function ct_table_charset($pdo) {
    static $cs = null;
    if ($cs !== null) return $cs;
    $cs = 'DEFAULT CHARSET=utf8mb4';
    try {
        $c = (string)$pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'national_code'")->fetchColumn();
        if (preg_match('/^(utf8mb4|utf8|utf8mb3)_[a-z0-9_]+$/', $c)) $cs = 'DEFAULT CHARSET=' . explode('_', $c)[0] . ' COLLATE=' . $c;
    } catch (Throwable $e) {}
    return $cs;
}

function ct_phones_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS person_phones (
        id INT AUTO_INCREMENT PRIMARY KEY, person_id BIGINT NOT NULL, phone VARCHAR(20) NOT NULL, source VARCHAR(12) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, made_default_at DATETIME NULL,
        UNIQUE KEY u_person_phone (person_id, phone), KEY k_phone (phone)) " . ct_table_charset($pdo));
    // یک بار: شماره‌ی فعلیِ همه‌ی اشخاص وارد فهرست می‌شود (پیش‌فرض)
    try {
        $f = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'person_phones_seeded'")->fetchColumn();
        if ($f !== '1') {
            $pdo->exec("INSERT IGNORE INTO person_phones (person_id, phone, source, made_default_at)
                        SELECT id, mobile_number, 'BASE', COALESCE(created_at, NOW()) FROM persons WHERE mobile_number IS NOT NULL AND mobile_number <> ''");
            $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('person_phones_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        }
    } catch (Throwable $e) { error_log('[person_phones seed] ' . $e->getMessage()); }
}

// شماره را به فهرستِ شخص اضافه می‌کند؛ $default = true: پیش‌فرض هم می‌شود (شماره‌ی قبلی در فهرست می‌ماند)
function ct_phone_add($pdo, $personId, $phone, $source, $default = true) {
    ct_phones_ensure($pdo);
    $personId = (int)$personId;
    if (!$personId || $phone === '') return false;
    // شماره‌ی پیش‌فرضِ فعلی هم حتماً در فهرست باشد (ممکن است از جای دیگری عوض شده باشد)
    $cur = (string)$pdo->query("SELECT mobile_number FROM persons WHERE id = " . $personId)->fetchColumn();
    if ($cur !== '' && $cur !== $phone) $pdo->prepare("INSERT IGNORE INTO person_phones (person_id, phone, source, made_default_at) VALUES (?, ?, 'BASE', NOW())")->execute([$personId, $cur]);
    $pdo->prepare("INSERT IGNORE INTO person_phones (person_id, phone, source) VALUES (?, ?, ?)")->execute([$personId, $phone, $source]);
    if ($default) {
        // اگر همین الان هم پیش‌فرض بوده، زمانِ پیش‌فرض شدنش دست نمی‌خورد
        $pdo->prepare("UPDATE person_phones SET made_default_at = NOW() WHERE person_id = ? AND phone = ?" . ($cur === $phone ? " AND made_default_at IS NULL" : ""))->execute([$personId, $phone]);
        if ($cur !== $phone) $pdo->prepare("UPDATE persons SET mobile_number = ? WHERE id = ?")->execute([$phone, $personId]);
    }
    return true;
}

function ct_phone_list($pdo, $personId) {
    ct_phones_ensure($pdo);
    $personId = (int)$personId;
    $cur = (string)$pdo->query("SELECT mobile_number FROM persons WHERE id = " . $personId)->fetchColumn();
    if ($cur !== '') $pdo->prepare("INSERT IGNORE INTO person_phones (person_id, phone, source, made_default_at) VALUES (?, ?, 'BASE', NOW())")->execute([$personId, $cur]);
    $st = $pdo->prepare("SELECT id, phone, source, created_at, made_default_at FROM person_phones WHERE person_id = ? ORDER BY (phone = ?) DESC, COALESCE(made_default_at, created_at) DESC");
    $st->execute([$personId, $cur]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['is_default'] = $r['phone'] === $cur;
        $r['source_fa'] = ['BASE' => 'شماره‌ی قبلیِ سایت', 'IMPORT' => 'ورود از اکسل', 'PANEL' => 'ثبت در پنل', 'BOT' => 'ربات (کدِ تأیید)'][$r['source']] ?? (string)$r['source'];
        $r['created_j'] = $r['created_at'] && function_exists('jalali_from_gregorian_ts') ? vsprintf('%04d/%02d/%02d', jalali_from_gregorian_ts(strtotime($r['created_at']))) : '';
    }
    unset($r);
    return $rows;
}

// ---------------- پیامِ همگانی در ربات ----------------
function ct_bc_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_broadcasts (
        id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by INT NULL, text TEXT NOT NULL, filters_json TEXT NULL,
        total INT NOT NULL DEFAULT 0, sent INT NOT NULL DEFAULT 0, failed INT NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'RUNNING', finished_at DATETIME NULL) " . ct_table_charset($pdo));
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_broadcast_recipients (
        id INT AUTO_INCREMENT PRIMARY KEY, broadcast_id INT NOT NULL, person_id BIGINT NULL, chat_id VARCHAR(40) NOT NULL, full_name VARCHAR(190) NULL,
        national_code VARCHAR(10) NULL, company VARCHAR(190) NULL, status VARCHAR(8) NOT NULL DEFAULT 'PENDING', error VARCHAR(255) NULL, message_id VARCHAR(40) NULL,
        sent_at DATETIME NULL, KEY k_bc (broadcast_id, status)) " . ct_table_charset($pdo));
}

// ارسال با رباتِ کارکنان؛ برخلافِ bale_curl_call، متنِ خطای بله را هم برمی‌گرداند (برای گزارش)
function ct_bot_send($token, $chatId, $text) {
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text], JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $d = json_decode((string)$res, true);
    if (is_array($d) && !empty($d['ok'])) return ['ok' => true, 'message_id' => (string)($d['result']['message_id'] ?? '')];
    $desc = is_array($d) ? (string)($d['description'] ?? '') : '';
    if ($desc === '' && $err !== '') $desc = 'خطای اتصال: ' . $err;
    if (stripos($desc, 'block') !== false) $desc = 'کاربر ربات را مسدود کرده (' . $desc . ')';
    elseif (stripos($desc, 'chat not found') !== false) $desc = 'گفتگو با ربات پیدا نشد (' . $desc . ')';
    return ['ok' => false, 'error' => mb_substr($desc ?: 'ارسال نشد', 0, 250)];
}
