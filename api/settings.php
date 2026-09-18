<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $data['action'] ?? '';
    
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
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            error_log('[settings] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'خطا در دیتابیس.']);
        }
        exit;
    }
}
?>