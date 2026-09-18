<?php
// فایل: api/otp_core.php
// ورود دومرحله‌ای پنل با کد یک‌بارمصرف از طریق بله (سرویس سفیر)

// =====================================================================
//  تنظیمات
// =====================================================================
function otp_settings($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $keys = ['safir_api_key', 'safir_bot_id', 'otp_enabled', 'otp_ttl_seconds', 'otp_max_attempts'];
    $place = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($place)");
    $stmt->execute($keys);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = array_merge([
        'safir_api_key' => '', 'safir_bot_id' => '', 'otp_enabled' => '0',
        'otp_ttl_seconds' => '120', 'otp_max_attempts' => '5',
    ], $rows ?: []);
    return $cache;
}

function otp_set($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$key, $value, $value]);
}

// =====================================================================
//  نرمال‌سازی شماره موبایل به فرمتی که سفیر می‌خواهد: 989XXXXXXXXX
//  ورودی ممکن است 09xxxxxxxxx یا +989xxxxxxxxx یا با ارقام فارسی باشد.
// =====================================================================
function otp_normalize_phone($phone) {
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $p = str_replace($fa, $en, (string)$phone);
    $p = preg_replace('/\D/', '', $p);

    if (strpos($p, '0098') === 0) $p = substr($p, 4);
    if (strpos($p, '98') === 0 && strlen($p) === 12) return $p;
    if (strpos($p, '0') === 0 && strlen($p) === 11) return '98' . substr($p, 1);
    if (strlen($p) === 10 && strpos($p, '9') === 0) return '98' . $p;
    return $p;
}

function otp_phone_is_valid($normalized) {
    return (bool)preg_match('/^989\d{9}$/', $normalized);
}

// نمایش ماسک‌شده برای کاربر: 0912***4567
function otp_mask_phone($normalized) {
    if (!otp_phone_is_valid($normalized)) return '---';
    $local = '0' . substr($normalized, 2);           // 09xxxxxxxxx
    return substr($local, 0, 4) . '***' . substr($local, -4);
}

// =====================================================================
//  ارسال کد از طریق سرویس سفیر بله
//  خروجی: ['ok' => bool, 'message_id' => ?string, 'error' => ?string]
// =====================================================================
function otp_send_via_safir($pdo, $phoneNormalized, $code, $requestId) {
    $s = otp_settings($pdo);
    if (empty($s['safir_api_key']) || empty($s['safir_bot_id'])) {
        return ['ok' => false, 'error' => 'کلید سرویس سفیر یا شناسه‌ی بات تنظیم نشده است.'];
    }

    $payload = [
        'request_id'   => $requestId,
        'bot_id'       => intval($s['safir_bot_id']),
        'phone_number' => $phoneNormalized,
        'message_data' => ['otp_message' => ['otp' => (string)$code]],
    ];

    $ch = curl_init('https://safir.bale.ai/api/v3/send_message');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'api-access-key: ' . $s['safir_api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok' => false, 'error' => 'خطای شبکه در ارسال کد: ' . $err];

    $decoded = json_decode($res, true);
    // پاسخ موفق طبق مستندات، فقط شامل message_id است
    if ($http >= 200 && $http < 300 && is_array($decoded) && !empty($decoded['message_id'])) {
        return ['ok' => true, 'message_id' => $decoded['message_id']];
    }

    $msg = is_array($decoded)
        ? ($decoded['error'] ?? $decoded['message'] ?? $decoded['description'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE))
        : substr((string)$res, 0, 200);
    return ['ok' => false, 'error' => "ارسال کد ناموفق بود (کد $http): " . $msg];
}

// =====================================================================
//  ساخت و ارسال کد برای یک کاربر پنل
// =====================================================================
function otp_issue($pdo, $user, $ip = null) {
    $s = otp_settings($pdo);
    $phone = otp_normalize_phone($user['mobile_number'] ?? '');
    if (!otp_phone_is_valid($phone)) {
        return ['ok' => false, 'error' => 'شماره موبایل این کاربر ثبت یا معتبر نیست. با مدیر سیستم تماس بگیرید.'];
    }

    // محدودیت نرخ: حداکثر ۳ ارسال در ۱۰ دقیقه‌ی گذشته برای همین کاربر
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_otps WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute([$user['id']]);
    if (intval($stmt->fetchColumn()) >= 3) {
        return ['ok' => false, 'error' => 'تعداد درخواست‌های کد بیش از حد مجاز است. چند دقیقه صبر کنید.'];
    }

    // کدهای قبلیِ همین کاربر باطل می‌شوند تا فقط آخرین کد معتبر باشد
    $pdo->prepare("UPDATE login_otps SET is_used = 1 WHERE user_id = ? AND is_used = 0")->execute([$user['id']]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $requestId = bin2hex(random_bytes(12));
    $ttl = max(60, intval($s['otp_ttl_seconds']));

    $send = otp_send_via_safir($pdo, $phone, $code, $requestId);
    if (!$send['ok']) return $send;

    $pdo->prepare("INSERT INTO login_otps (user_id, code_hash, phone_number, request_id, message_id, ip, expires_at)
                   VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))")
        ->execute([$user['id'], password_hash($code, PASSWORD_DEFAULT), $phone,
                   $requestId, $send['message_id'] ?? null, $ip, $ttl]);

    return ['ok' => true, 'masked' => otp_mask_phone($phone), 'ttl' => $ttl];
}

// =====================================================================
//  بررسی کد واردشده
// =====================================================================
function otp_verify($pdo, $userId, $inputCode) {
    $s = otp_settings($pdo);
    $maxAttempts = max(3, intval($s['otp_max_attempts']));

    $stmt = $pdo->prepare("SELECT * FROM login_otps WHERE user_id = ? AND is_used = 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return ['ok' => false, 'error' => 'کدی برای شما ارسال نشده یا منقضی شده است. دوباره تلاش کنید.'];

    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare("UPDATE login_otps SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'کد منقضی شده است. کد جدید بگیرید.'];
    }

    if (intval($row['attempts']) >= $maxAttempts) {
        $pdo->prepare("UPDATE login_otps SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'تعداد تلاش‌های ناموفق زیاد بود. کد جدید بگیرید.'];
    }

    // ارقام فارسی را هم بپذیر
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $inputCode = preg_replace('/\D/', '', str_replace($fa, ['0','1','2','3','4','5','6','7','8','9'], (string)$inputCode));

    if (!password_verify($inputCode, $row['code_hash'])) {
        $pdo->prepare("UPDATE login_otps SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        $left = $maxAttempts - intval($row['attempts']) - 1;
        return ['ok' => false, 'error' => 'کد وارد‌شده اشتباه است.' . ($left > 0 ? " ($left تلاش باقی مانده)" : '')];
    }

    $pdo->prepare("UPDATE login_otps SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
    return ['ok' => true];
}

// آیا برای این کاربر باید کد فرستاده شود؟
function otp_required($pdo, $user) {
    $s = otp_settings($pdo);
    if ($s['otp_enabled'] !== '1') return false;           // به‌صورت کلی خاموش است
    if (empty($user['otp_enabled'])) return false;          // برای این کاربر خاموش است
    return otp_phone_is_valid(otp_normalize_phone($user['mobile_number'] ?? ''));
}
