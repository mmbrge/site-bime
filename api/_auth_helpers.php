<?php
// فایل: api/_auth_helpers.php
// کمکی‌های مشترکِ ورود: شماره تلفن، پیدا کردنِ کاربر با شماره (کاربران پنل و کاربران شرکت‌ها)،
// کدِ ۵ رقمیِ ورود که با ربات بله‌ی شرکت‌ها فرستاده می‌شود، لاگِ ورود و اتصال/قطعِ ربات.
// (مایگریشن ۰۱۶ لازم است؛ auth_schema_ready اگر اجرا نشده باشد false برمی‌گرداند.)

function auth_schema_ready($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query("SELECT bale_chat_id, is_deleted, personnel_code FROM users LIMIT 1");
        $pdo->query("SELECT 1 FROM phone_otps LIMIT 1");
        $pdo->query("SELECT 1 FROM login_logs LIMIT 1");
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}
const AUTH_SCHEMA_MSG = 'برای این بخش، مایگریشن migrations/016_users_bot_login.sql را در phpMyAdmin اجرا کنید.';

// هر شکلی از شماره (۰۹۱۲...، +۹۸۹۱۲...، ۹۸۹۱۲...، ۹۱۲...، با ارقام فارسی/عربی و فاصله) => 09xxxxxxxxx
// دکمه‌ی «اشتراک‌گذاری شماره» در بله شماره را با +98 می‌فرستد؛ در پنل با 09 ثبت می‌شود.
function auth_norm_phone($p) {
    $p = strtr((string)$p, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    $p = preg_replace('/\D/', '', $p);
    if (strpos($p, '0098') === 0) $p = substr($p, 4);
    elseif (strlen($p) === 12 && strpos($p, '98') === 0) $p = substr($p, 2);
    if (strlen($p) === 10 && $p[0] === '9') $p = '0' . $p;
    return preg_match('/^09\d{9}$/', $p) ? $p : '';
}

function auth_role_fa($type, $role = null) {
    if ($type === 'COMPANY') return 'کاربر شرکت';
    return ['ADMIN' => 'مدیر کل', 'OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی',
            'COMPANY_LIAISON' => 'همکار بیمه با ما'][$role] ?? 'کاربر پنل';
}

// همه‌ی حساب‌هایی که با این شماره ثبت شده‌اند (کاربر حذف‌شده یا غیرفعال حساب نمی‌شود)
function auth_accounts_by_phone($pdo, $phone) {
    $phone = auth_norm_phone($phone);
    if ($phone === '') return [];
    $out = [];
    foreach ($pdo->query("SELECT id, username, full_name, role, mobile_number, bale_chat_id, bot_linked_at FROM users WHERE COALESCE(is_deleted, 0) = 0")->fetchAll() as $u) {
        if (auth_norm_phone($u['mobile_number']) === $phone) $out[] = ['type' => 'STAFF', 'user' => $u];
    }
    foreach ($pdo->query("SELECT id, username, full_name, mobile_number, bale_chat_id, bot_linked_at FROM company_portal_users WHERE is_active = 1 AND COALESCE(is_deleted, 0) = 0")->fetchAll() as $u) {
        if (auth_norm_phone($u['mobile_number']) === $phone) { $u['role'] = null; $out[] = ['type' => 'COMPANY', 'user' => $u]; }
    }
    return $out;
}

function auth_account_key($a) { return $a['type'] . ':' . $a['user']['id']; }
function auth_account_linked($a) { return !empty($a['user']['bale_chat_id']) && !empty($a['user']['bot_linked_at']); }

function auth_load_account($pdo, $type, $id) {
    if ($type === 'STAFF') {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND COALESCE(is_deleted, 0) = 0");
    } else {
        $st = $pdo->prepare("SELECT * FROM company_portal_users WHERE id = ? AND is_active = 1 AND COALESCE(is_deleted, 0) = 0");
    }
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ? ['type' => $type, 'user' => $u] : null;
}

// ---------------- ربات بله‌ی شرکت‌ها ----------------
function cbot_token($pdo) {
    static $t = null;
    if ($t !== null) return $t;
    try { $t = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_bot_token'")->fetchColumn(); }
    catch (Throwable $e) { $t = ''; }
    return $t;
}
// آدرسِ API بله (متغیرِ محیطیِ CBOT_API_BASE فقط برای تستِ محلی است و روی سرور تنظیم نمی‌شود)
function cbot_base() { return rtrim(getenv('CBOT_API_BASE') ?: 'https://tapi.bale.ai', '/'); }
function cbot_api($pdo, $method, $payload, $multipart = false) {
    $token = cbot_token($pdo);
    if (!$token) return null;
    $ch = curl_init(cbot_base() . "/bot{$token}/{$method}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $multipart ? 60 : 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if ($multipart) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$res, true);
    return (is_array($d) && !empty($d['ok'])) ? ($d['result'] ?? true) : null;
}
function cbot_send($pdo, $chatId, $text, $markup = null) {
    if (!$chatId) return null;
    $p = ['chat_id' => $chatId, 'text' => $text];
    if ($markup) $p['reply_markup'] = $markup;
    return cbot_api($pdo, 'sendMessage', $p);
}
function cbot_send_file($pdo, $chatId, $absPath, $caption = '', $fileName = null) {
    if (!$chatId || !is_file($absPath)) return null;
    $f = new CURLFile($absPath, null, $fileName ?: basename($absPath));
    return cbot_api($pdo, 'sendDocument', ['chat_id' => $chatId, 'document' => $f, 'caption' => $caption], true);
}

// اعلان به همه‌ی کاربرانِ متصل به ربات: کاربرانِ یک شرکت، یا کاربرانِ پنل با نقش‌های مشخص
function cbot_notify_company($pdo, $companyId, $text, $markup = null) {
    if (!auth_schema_ready($pdo) || !cbot_token($pdo)) return;
    try {
        $st = $pdo->prepare("SELECT DISTINCT cpu.bale_chat_id FROM company_portal_users cpu
                               JOIN company_portal_user_companies cpuc ON cpuc.portal_user_id = cpu.id
                              WHERE cpuc.company_id = ? AND cpu.is_active = 1 AND COALESCE(cpu.is_deleted, 0) = 0
                                AND cpu.bale_chat_id IS NOT NULL AND cpu.bot_linked_at IS NOT NULL");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chat) cbot_send($pdo, $chat, $text, $markup);
    } catch (Throwable $e) { error_log('[cbot_notify_company] ' . $e->getMessage()); }
}
function cbot_notify_staff($pdo, $roles, $text, $markup = null) {
    if (!auth_schema_ready($pdo) || !cbot_token($pdo)) return;
    try {
        $in = implode(',', array_fill(0, count($roles), '?'));
        $st = $pdo->prepare("SELECT bale_chat_id FROM users WHERE role IN ($in) AND COALESCE(is_deleted, 0) = 0
                               AND bale_chat_id IS NOT NULL AND bot_linked_at IS NOT NULL");
        $st->execute($roles);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chat) cbot_send($pdo, $chat, $text, $markup);
    } catch (Throwable $e) { error_log('[cbot_notify_staff] ' . $e->getMessage()); }
}

// قطعِ اتصالِ یک حساب از ربات (مثلاً وقتی شماره‌اش در پنل عوض شد یا حذف شد) + پیام به همان گفتگو
function auth_unlink_bot($pdo, $type, $id, $message = null) {
    if (!auth_schema_ready($pdo)) return;
    $table = $type === 'STAFF' ? 'users' : 'company_portal_users';
    $st = $pdo->prepare("SELECT bale_chat_id FROM $table WHERE id = ?");
    $st->execute([$id]);
    $chat = $st->fetchColumn();
    $pdo->prepare("UPDATE $table SET bale_chat_id = NULL, bot_linked_at = NULL WHERE id = ?")->execute([$id]);
    if ($chat) {
        try { $pdo->prepare("DELETE FROM company_bot_state WHERE chat_id = ?")->execute([$chat]); } catch (Throwable $e) {}
        if ($message) cbot_send($pdo, $chat, $message, ['keyboard' => [[['text' => '📱 ورود با شماره تلفن', 'request_contact' => true]]], 'resize_keyboard' => true]);
    }
}

// ---------------- کدِ ۵ رقمیِ ورود ----------------
const OTP5_TTL = 120;
function otp5_issue($pdo, $acc, $ip = null) {
    $u = $acc['user'];
    if (!auth_account_linked($acc)) return ['ok' => false, 'error' => 'ابتدا در ربات بله با شماره‌ی خود وارد شوید.'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM phone_otps WHERE user_type = ? AND user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $st->execute([$acc['type'], $u['id']]);
    if (intval($st->fetchColumn()) >= 4) return ['ok' => false, 'error' => 'تعداد درخواستِ کد زیاد بود؛ چند دقیقه‌ی دیگر دوباره امتحان کنید.'];

    $pdo->prepare("UPDATE phone_otps SET is_used = 1 WHERE user_type = ? AND user_id = ? AND is_used = 0")->execute([$acc['type'], $u['id']]);
    $code = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $text = "🔐 کد ورود به پنل «بیمه با ما»:\n\n{$code}\n\nاین کد تا " . OTP5_TTL / 60 . " دقیقه معتبر است. اگر شما درخواست نداده‌اید، این پیام را نادیده بگیرید و کد را به کسی ندهید.";
    if (!cbot_send($pdo, $u['bale_chat_id'], $text)) {
        return ['ok' => false, 'error' => 'ارسال کد با ربات بله ممکن نشد. دوباره تلاش کنید.'];
    }
    $pdo->prepare("INSERT INTO phone_otps (user_type, user_id, code_hash, ip, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))")
        ->execute([$acc['type'], $u['id'], password_hash($code, PASSWORD_DEFAULT), $ip, OTP5_TTL]);
    return ['ok' => true, 'ttl' => OTP5_TTL];
}
function otp5_verify($pdo, $acc, $code) {
    $code = auth_digits($code);
    $st = $pdo->prepare("SELECT * FROM phone_otps WHERE user_type = ? AND user_id = ? AND is_used = 0 ORDER BY id DESC LIMIT 1");
    $st->execute([$acc['type'], $acc['user']['id']]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'error' => 'کدی برای شما فرستاده نشده یا منقضی شده؛ دوباره «ارسال کد» را بزنید.', 'expired' => true];
    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare("UPDATE phone_otps SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'کد منقضی شده است؛ کد جدید بگیرید.', 'expired' => true];
    }
    if (intval($row['attempts']) >= 5) {
        $pdo->prepare("UPDATE phone_otps SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'تلاشِ ناموفق زیاد بود؛ کد جدید بگیرید.', 'expired' => true];
    }
    if (!password_verify($code, $row['code_hash'])) {
        $pdo->prepare("UPDATE phone_otps SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        $left = 4 - intval($row['attempts']);
        return ['ok' => false, 'error' => 'کد اشتباه است.' . ($left > 0 ? ' (' . auth_fa($left) . ' تلاشِ دیگر)' : '')];
    }
    $pdo->prepare("UPDATE phone_otps SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
    return ['ok' => true];
}
function auth_digits($s) {
    return preg_replace('/\D/', '', strtr((string)$s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
}
function auth_fa($s) { return strtr((string)$s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }

// ---------------- لاگ ورود ----------------
function auth_parse_ua($ua) {
    $ua = (string)$ua;
    $browser = 'نامشخص';
    foreach ([['Edg/', 'Edge'], ['OPR/', 'Opera'], ['SamsungBrowser', 'Samsung Internet'], ['Firefox/', 'Firefox'],
              ['CriOS', 'Chrome (iOS)'], ['FxiOS', 'Firefox (iOS)'], ['Chrome/', 'Chrome'], ['Safari/', 'Safari']] as [$k, $n]) {
        if (stripos($ua, $k) !== false) { $browser = $n; break; }
    }
    if (preg_match('/(?:Edg|OPR|Firefox|Chrome|CriOS|Version)\/(\d+)/', $ua, $m)) $browser .= ' ' . $m[1];
    if (stripos($ua, 'Bale') !== false) $browser = 'بله (داخل برنامه)';
    $os = 'نامشخص';
    if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) $os = 'Windows ' . (['10.0' => '10/11', '6.3' => '8.1', '6.1' => '7'][$m[1]] ?? $m[1]);
    elseif (preg_match('/Android ([\d.]+)/', $ua, $m)) $os = 'Android ' . $m[1];
    elseif (preg_match('/(iPhone|iPad).*OS ([\d_]+)/', $ua, $m)) $os = 'iOS ' . str_replace('_', '.', $m[2]);
    elseif (stripos($ua, 'Mac OS X') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    $device = 'کامپیوتر';
    if (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) $device = 'تبلت';
    elseif (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false) $device = 'موبایل';
    if (preg_match('/Android [\d.]+; ([^;)]+?)(?: Build|\))/', $ua, $m) && !preg_match('/^(K|wv)$/', trim($m[1]))) $device .= ' (' . trim($m[1]) . ')';
    elseif (stripos($ua, 'iPhone') !== false) $device .= ' (iPhone)';
    return ['browser' => mb_substr($browser, 0, 60), 'os' => mb_substr($os, 0, 60), 'device' => mb_substr($device, 0, 60)];
}
function auth_log_login($pdo, $type, $user, $method, $success = true, $note = null) {
    if (!auth_schema_ready($pdo)) return;
    try {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $p = auth_parse_ua($ua);
        $pdo->prepare("INSERT INTO login_logs (user_type, user_id, user_name, role, method, success, note, ip, browser, os, device, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$type, $user['id'] ?? null, $user['full_name'] ?? ($user['username'] ?? null),
                       $type === 'STAFF' ? ($user['role'] ?? null) : 'COMPANY', $method, $success ? 1 : 0, $note,
                       $_SERVER['REMOTE_ADDR'] ?? null, $p['browser'], $p['os'], $p['device'], mb_substr($ua, 0, 400)]);
    } catch (Throwable $e) { error_log('[auth_log_login] ' . $e->getMessage()); }
}

// کاربرِ پنلی که حذف شده، دیگر نباید با نشستِ قبلی‌اش هم کار کند
function auth_staff_is_active($pdo, $userId) {
    if (!auth_schema_ready($pdo)) return true;
    $st = $pdo->prepare("SELECT COALESCE(is_deleted, 0) FROM users WHERE id = ?");
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return $v !== false && intval($v) === 0;
}
