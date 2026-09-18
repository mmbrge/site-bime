<?php
// فایل: api/bot_status.php
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';

// دریافت وضعیت توسط داشبورد
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bot_last_ping', 'bot_enabled', 'bot_token')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $lastPing = intval($settings['bot_last_ping'] ?? 0);
        $isEnabled = ($settings['bot_enabled'] ?? '1') === '1';
        $isOnline = (time() - $lastPing) <= 25; // اگر در ۲۵ ثانیه اخیر پینگ داده باشد

        echo json_encode([
            'ok' => true,
            'is_online' => $isOnline,
            'is_enabled' => $isEnabled,
            'has_token' => !empty($settings['bot_token'])
        ]);
    } catch (Exception $e) {
        error_log('[bot_status GET] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
    }
    exit;
}

// ثبت پینگ از سمت ربات یا تغییر وضعیت از سمت پنل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    try {
        // ۱. درخواست پینگ ربات به سرور
        if ($action === 'ping') {
            // بروزرسانی آخرین زمان فعالیت
            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'bot_last_ping'");
            $stmt->execute([time()]);
            
            // استخراج وضعیت فعلی (توکن دیگر در پاسخ برگردانده نمی‌شود - اطلاعات حساس)
            $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bot_enabled', 'bot_token')");
            $settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);

            echo json_encode([
                'ok' => true,
                'is_enabled' => ($settings['bot_enabled'] ?? '1') === '1',
                'has_token' => !empty($settings['bot_token'])
            ]);
            exit;
        }

        // ۲. درخواست روشن/خاموش کردن ربات از پنل
        if ($action === 'toggle_power') {
            session_start();
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
                exit;
            }
            $newState = !empty($data['enabled']) ? '1' : '0';
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('bot_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$newState, $newState]);

            echo json_encode(['ok' => true, 'is_enabled' => ($newState === '1')]);
            exit;
        }
    } catch (Exception $e) {
        error_log('[bot_status POST] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
    }
    exit;
}
?>