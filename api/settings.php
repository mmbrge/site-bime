<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

// «همکار شرکت‌ها» (COMPANY_LIAISON) فقط به شرکت‌ها، بایگانی و مالی دسترسی دارد؛
// منوی این بخش برایش پنهان است و اینجا هم مستقیم جلویش گرفته می‌شود.
if (($_SESSION['role'] ?? '') === 'COMPANY_LIAISON') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $data['action'] ?? '';
    // تغییرِ توکن ربات و تنظیمات فقط کارِ مدیر است (صفحه‌ی تنظیمات هم فقط برای مدیر است)
    if (($_SESSION['role'] ?? '') !== 'ADMIN') {
        echo json_encode(['ok' => false, 'error' => 'فقط مدیر دسترسی دارد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // عملیات ذخیره توکن ربات
    if ($action === 'save_token') {
        $token = trim($data['token'] ?? '');
        if (empty($token)) {
            echo json_encode(['ok' => false, 'error' => 'توکن نمی‌تواند خالی باشد.']);
            exit;
        }
        
        try {
            // آپدیت یا ایجاد رکورد توکن در دیتابیس
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('bot_token', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$token, $token]);
            
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            error_log('[settings] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'خطا در دیتابیس.']);
        }
        exit;
    }

    // ذخیره‌ی توکن ربات جداگانه‌ی «درخواست‌های شرکتی» (فاز ۲ - ربات/مینی‌اپ شرکت‌ها)
    if ($action === 'save_company_bot_token') {
        $token = trim($data['token'] ?? '');
        if (empty($token)) {
            echo json_encode(['ok' => false, 'error' => 'توکن نمی‌تواند خالی باشد.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('company_bot_token', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$token, $token]);
            // وب‌هوکِ ربات روی api/company_bot_webhook.php همین سایت تنظیم می‌شود، و نام کاربریِ
            // ربات هم ذخیره می‌شود تا صفحه‌ی ورود بتواند لینکِ مستقیمِ ربات را نشان بدهد
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
            $hookUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/company_bot_webhook.php';
            $call = function ($method, $payload) use ($token) {
                $ch = curl_init("https://tapi.bale.ai/bot{$token}/{$method}");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
                                        CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
                $r = json_decode((string)curl_exec($ch), true);
                curl_close($ch);
                return is_array($r) ? $r : null;
            };
            $hook = $call('setWebhook', ['url' => $hookUrl]);
            $me = $call('getMe', []);
            if (!empty($me['result']['username'])) {
                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('company_bot_username', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute([$me['result']['username'], $me['result']['username']]);
            }
            echo json_encode(['ok' => true, 'webhook' => !empty($hook['ok']), 'webhook_url' => $hookUrl,
                              'bot_username' => $me['result']['username'] ?? null], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            error_log('[settings] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'خطا در دیتابیس.']);
        }
        exit;
    }
}
?>