<?php
// فایل: api/_bot_identity.php
// ورود به ربات بله‌ی کارکنان با «کد ملی + شماره‌ی خودِ کاربر»:
//  - هر شماره فقط به یک کد ملی وصل می‌شود (persons.bot_phone). با آن شماره دیگر نمی‌شود روی کد ملیِ دیگری رفت.
//  - هر کد ملی هم فقط با همان شماره‌ی وصل‌شده وارد می‌شود (تا مدیر از «کاربران» آزادش نکند).
//  - کاربر می‌تواند از حسابش خارج شود و دوباره (با همان کد ملی) وارد شود.
//  - فقط شماره‌ی «مدیر کل» (users.role = ADMIN) روی هر کد ملی‌ای سوییچ می‌کند؛ شماره‌اش به هیچ کد ملی‌ای قفل نمی‌شود.
// bot_chat_meta: آخرین شماره‌ی تأییدشده‌ی هر چت (برای سوییچِ سریعِ مدیر و نمایش در پنل).

function botid_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        foreach (['bot_phone' => "VARCHAR(20) NULL", 'bot_linked_at' => "DATETIME NULL", 'bot_logout_at' => "DATETIME NULL"] as $c => $def) {
            if (!$pdo->query("SHOW COLUMNS FROM persons LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE persons ADD COLUMN `$c` $def");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_chat_meta (chat_id VARCHAR(40) NOT NULL PRIMARY KEY, phone VARCHAR(20) NULL, is_admin TINYINT NOT NULL DEFAULT 0,
                    person_id INT NULL, sender_name VARCHAR(190) NULL, displaced_chat VARCHAR(40) NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$pdo->query("SHOW COLUMNS FROM bot_chat_meta LIKE 'displaced_chat'")->fetch()) $pdo->exec("ALTER TABLE bot_chat_meta ADD COLUMN displaced_chat VARCHAR(40) NULL");
        // یک بار: کسانی که همین الان به ربات وصل‌اند، به شماره‌ی فعلی‌شان قفل می‌شوند (شماره‌ی مدیر هرگز)
        $flag = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'botid_backfill'")->fetchColumn();
        if (!$flag) {
            $admins = botid_admin_phones($pdo);
            $st = $pdo->query("SELECT id, mobile_number FROM persons WHERE bale_chat_id IS NOT NULL AND bale_chat_id <> '' AND mobile_number IS NOT NULL AND (bot_phone IS NULL OR bot_phone = '')");
            $up = $pdo->prepare("UPDATE persons SET bot_phone = ?, bot_linked_at = COALESCE(bot_linked_at, NOW()) WHERE id = ?");
            $seen = [];
            foreach ($st->fetchAll() as $r) {
                $ph = botid_norm_phone($r['mobile_number']);
                if ($ph === '' || in_array($ph, $admins, true) || isset($seen[$ph])) continue;   // شماره‌ای که روی دو نفر است قفل نمی‌شود
                $seen[$ph] = true;
                $up->execute([$ph, $r['id']]);
            }
            $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('botid_backfill', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        }
    } catch (Throwable $e) { error_log('[botid_ensure] ' . $e->getMessage()); }
}

// «+98 912 …»، «98912…»، «912…» و «0912…» همه => «0912…»
function botid_norm_phone($p) {
    $d = preg_replace('/\D/', '', strtr((string)$p, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
    if (strpos($d, '0098') === 0) $d = substr($d, 4);
    elseif (strpos($d, '98') === 0 && strlen($d) === 12) $d = substr($d, 2);
    if (strlen($d) === 10 && $d[0] === '9') $d = '0' . $d;
    return $d;
}
function botid_mask_nc($nc) { $nc = (string)$nc; return strlen($nc) >= 4 ? str_repeat('•', max(0, strlen($nc) - 4)) . substr($nc, -4) : $nc; }

// شماره‌های مدیر کل (فقط این شماره‌ها روی همه سوییچ می‌کنند)
function botid_admin_phones($pdo) {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        $sql = "SELECT mobile_number FROM users WHERE role = 'ADMIN' AND mobile_number IS NOT NULL AND mobile_number <> ''";
        try { $rows = $pdo->query($sql . " AND COALESCE(is_deleted, 0) = 0")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
        foreach ($rows as $m) { $n = botid_norm_phone($m); if ($n !== '') $c[] = $n; }
    } catch (Throwable $e) {}
    return $c;
}
function botid_is_admin_phone($pdo, $phone) { $p = botid_norm_phone($phone); return $p !== '' && in_array($p, botid_admin_phones($pdo), true); }

// آیا این شماره می‌تواند روی این کد ملی وارد شود؟  ['ok' => bool, 'admin' => bool, 'error' => متن]
function botid_check($pdo, $nationalCode, $phone) {
    botid_ensure($pdo);
    $ph = botid_norm_phone($phone);
    if ($ph === '') return ['ok' => false, 'admin' => false, 'error' => 'شماره‌ی تماس دریافت نشد؛ دوباره با دکمه‌ی «اشتراک‌گذاری شماره» بفرستید.'];
    if (botid_is_admin_phone($pdo, $ph)) return ['ok' => true, 'admin' => true];
    $st = $pdo->prepare("SELECT national_code FROM persons WHERE bot_phone = ? AND national_code <> ? LIMIT 1");
    $st->execute([$ph, $nationalCode]);
    if ($other = $st->fetchColumn()) {
        return ['ok' => false, 'admin' => false, 'error' => "⛔ این شماره قبلاً به کد ملیِ دیگری (" . botid_mask_nc($other) . ") متصل شده است و با هر شماره فقط می‌شود وارد یک کد ملی شد.\nاگر اشتباهی پیش آمده، با کارشناس تماس بگیرید تا اتصال را آزاد کند."];
    }
    $st = $pdo->prepare("SELECT bot_phone FROM persons WHERE national_code = ?");
    $st->execute([$nationalCode]);
    $bound = (string)$st->fetchColumn();
    if ($bound !== '' && $bound !== $ph) {
        return ['ok' => false, 'admin' => false, 'error' => "⛔ این کد ملی به شماره‌ی دیگری (…" . substr($bound, -4) . ") متصل است و فقط با همان شماره وارد می‌شود.\nاگر شماره‌تان عوض شده، با کارشناس تماس بگیرید تا اتصال را آزاد کند."];
    }
    return ['ok' => true, 'admin' => false];
}

// جداکردنِ چت از شخصِ فعلی‌اش. اگر مدیر کل روی کسی رفته بود که خودش در ربات وارد بود، چتِ خودِ آن شخص برمی‌گردد.
function botid_release($pdo, $chatId, $markLogout) {
    $st = $pdo->prepare("SELECT is_admin, displaced_chat FROM bot_chat_meta WHERE chat_id = ?");
    $st->execute([(string)$chatId]);
    $meta = $st->fetch() ?: ['is_admin' => 0, 'displaced_chat' => null];
    $st = $pdo->prepare("SELECT id FROM persons WHERE bale_chat_id = ?");
    $st->execute([$chatId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $pid) {
        $back = null;
        $dc = (string)($meta['displaced_chat'] ?? '');
        if ($dc !== '') {
            $q = $pdo->prepare("SELECT COUNT(*) FROM bot_chat_meta m WHERE m.chat_id = ? AND m.person_id = ? AND NOT EXISTS (SELECT 1 FROM persons p WHERE p.bale_chat_id = m.chat_id)");
            $q->execute([$dc, $pid]);
            if ($q->fetchColumn()) $back = $dc;
        }
        $pdo->prepare("UPDATE persons SET bale_chat_id = ?, conversation_state = 'START', active_case_id = NULL, flow_temp = NULL"
                      . ($markLogout && empty($meta['is_admin']) ? ", bot_logout_at = NOW()" : "") . " WHERE id = ?")->execute([$back, $pid]);
    }
    $pdo->prepare("UPDATE bot_chat_meta SET person_id = NULL, displaced_chat = NULL, updated_at = NOW() WHERE chat_id = ?")->execute([(string)$chatId]);
    return count($ids);
}

// اتصالِ چت به شخص (بعد از botid_check موفق)
function botid_link($pdo, $chatId, $personId, $phone, $isAdmin, $senderName = null) {
    botid_ensure($pdo);
    $ph = botid_norm_phone($phone);
    $st = $pdo->prepare("SELECT id FROM persons WHERE bale_chat_id = ? AND id <> ?");
    $st->execute([$chatId, $personId]);
    if ($st->fetchColumn()) botid_release($pdo, $chatId, false);
    $displaced = null;
    if ($isAdmin) {
        // شخص خودش در ربات وارد است => چتش کنار می‌رود و با خروج/سوییچِ مدیر برمی‌گردد
        $st = $pdo->prepare("SELECT bale_chat_id FROM persons WHERE id = ?");
        $st->execute([$personId]);
        $cur = (string)$st->fetchColumn();
        if ($cur !== '' && $cur !== (string)$chatId) $displaced = $cur;
        // شماره‌ی مدیر روی شخص قفل نمی‌شود و جای موبایلِ او را هم نمی‌گیرد
        $pdo->prepare("UPDATE persons SET bale_chat_id = ?, bot_linked_at = NOW() WHERE id = ?")->execute([$chatId, $personId]);
    } else {
        $pdo->prepare("UPDATE persons SET bale_chat_id = ?, bot_phone = COALESCE(NULLIF(bot_phone, ''), ?), mobile_number = COALESCE(NULLIF(mobile_number, ''), ?), bot_linked_at = NOW() WHERE id = ?")
            ->execute([$chatId, $ph, $ph, $personId]);
    }
    $pdo->prepare("INSERT INTO bot_chat_meta (chat_id, phone, is_admin, person_id, sender_name, displaced_chat, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE phone = VALUES(phone), is_admin = VALUES(is_admin), person_id = VALUES(person_id), sender_name = COALESCE(VALUES(sender_name), sender_name),
                   displaced_chat = VALUES(displaced_chat), updated_at = NOW()")
        ->execute([(string)$chatId, $ph, $isAdmin ? 1 : 0, $personId, $senderName, $displaced]);
    return $displaced;
}

// خروج از حساب در ربات (اتصالِ شماره به کد ملی می‌ماند؛ فقط چت جدا می‌شود)
function botid_logout($pdo, $chatId) {
    botid_ensure($pdo);
    $n = botid_release($pdo, $chatId, true);
    try { $pdo->prepare("DELETE FROM bot_sessions WHERE chat_id = ?")->execute([$chatId]); } catch (Throwable $e) {}
    return $n;
}

// چتِ مدیر کل (شماره‌ی تأییدشده‌اش هنوز شماره‌ی مدیر است) => سوییچ بدونِ اشتراکِ دوباره‌ی شماره
function botid_chat_admin_phone($pdo, $chatId) {
    botid_ensure($pdo);
    $st = $pdo->prepare("SELECT phone FROM bot_chat_meta WHERE chat_id = ? AND is_admin = 1");
    $st->execute([(string)$chatId]);
    $ph = (string)$st->fetchColumn();
    return $ph !== '' && botid_is_admin_phone($pdo, $ph) ? $ph : null;
}

// آخرین شماره‌ی تأییدشده‌ی این چت (برای «بررسی مجدد وضعیت» - تا شماره‌ی مدیر با موبایلِ شخص عوض نشود)
function botid_chat_phone($pdo, $chatId) {
    botid_ensure($pdo);
    $st = $pdo->prepare("SELECT phone FROM bot_chat_meta WHERE chat_id = ?");
    $st->execute([(string)$chatId]);
    $ph = (string)$st->fetchColumn();
    return $ph !== '' ? $ph : null;
}
