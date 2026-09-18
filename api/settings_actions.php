<?php
// فایل: api/settings_actions.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($data['action'] ?? '');

require_once __DIR__ . '/otp_core.php';

try {
    // =================================================================
    //  ورود دومرحله‌ای (فقط مدیر)
    // =================================================================
    if ($action === 'get_otp') {
        if (($_SESSION['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر دسترسی دارد.']); exit; }
        $users = $pdo->query("SELECT id, username, full_name, role, mobile_number, otp_enabled FROM users ORDER BY id")->fetchAll();
        echo json_encode(['ok' => true, 'settings' => otp_settings($pdo), 'users' => $users], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_otp') {
        if (($_SESSION['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر دسترسی دارد.']); exit; }
        otp_set($pdo, 'otp_enabled', !empty($data['otp_enabled']) ? '1' : '0');
        otp_set($pdo, 'safir_api_key', trim($data['safir_api_key'] ?? ''));
        otp_set($pdo, 'safir_bot_id', preg_replace('/\D/', '', $data['safir_bot_id'] ?? ''));
        otp_set($pdo, 'otp_ttl_seconds', (string)max(60, min(600, intval($data['otp_ttl_seconds'] ?? 120))));
        otp_set($pdo, 'otp_max_attempts', (string)max(3, min(10, intval($data['otp_max_attempts'] ?? 5))));
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'save_user_otp') {
        if (($_SESSION['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر دسترسی دارد.']); exit; }
        $uid = intval($data['user_id'] ?? 0);
        if (!$uid) { echo json_encode(['ok' => false, 'error' => 'کاربر نامعتبر.']); exit; }

        if (array_key_exists('mobile_number', $data)) {
            $raw = trim($data['mobile_number']);
            if ($raw === '') {
                // شماره پاک شد؛ ورود دومرحله‌ای این کاربر هم خاموش می‌شود تا قفل نشود
                $pdo->prepare("UPDATE users SET mobile_number = NULL, otp_enabled = 0 WHERE id = ?")->execute([$uid]);
            } else {
                $norm = otp_normalize_phone($raw);
                if (!otp_phone_is_valid($norm)) { echo json_encode(['ok' => false, 'error' => 'شماره موبایل معتبر نیست.']); exit; }
                $pdo->prepare("UPDATE users SET mobile_number = ? WHERE id = ?")->execute(['0' . substr($norm, 2), $uid]);
            }
        }
        if (array_key_exists('otp_enabled', $data)) {
            $on = !empty($data['otp_enabled']);
            if ($on) {
                $stmt = $pdo->prepare("SELECT mobile_number FROM users WHERE id = ?");
                $stmt->execute([$uid]);
                $m = otp_normalize_phone($stmt->fetchColumn());
                if (!otp_phone_is_valid($m)) { echo json_encode(['ok' => false, 'error' => 'ابتدا شماره موبایل معتبر ثبت کنید.']); exit; }
            }
            $pdo->prepare("UPDATE users SET otp_enabled = ? WHERE id = ?")->execute([$on ? 1 : 0, $uid]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ارسال کد آزمایشی به خودِ کاربر جاری
    if ($action === 'test_otp') {
        if (($_SESSION['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر دسترسی دارد.']); exit; }
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $me = $stmt->fetch();
        $res = otp_issue($pdo, $me, $_SERVER['REMOTE_ADDR'] ?? null);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'get_quota') {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_intro_quota'");
        $val = $stmt->fetchColumn();
        echo json_encode(['ok' => true, 'quota' => intval($val ?: 4)]);
        exit;
    }

    if ($action === 'save_quota') {
        $quota = intval($data['quota'] ?? 4);
        if ($quota < 1) $quota = 1;
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('default_intro_quota', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$quota, $quota]);
        echo json_encode(['ok' => true, 'quota' => $quota]);
        exit;
    }

    if ($action === 'get_site_url') {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'site_url'");
        echo json_encode(['ok' => true, 'site_url' => $stmt->fetchColumn() ?: '']);
        exit;
    }

    if ($action === 'save_site_url') {
        $url = rtrim(trim($data['site_url'] ?? ''), '/');
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('site_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$url, $url]);
        echo json_encode(['ok' => true, 'site_url' => $url]);
        exit;
    }

    if ($action === 'get_admin_chat_id') {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'admin_bale_chat_id'");
        echo json_encode(['ok' => true, 'admin_chat_id' => $stmt->fetchColumn() ?: '']);
        exit;
    }

    if ($action === 'save_admin_chat_id') {
        $id = trim($data['admin_chat_id'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('admin_bale_chat_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$id, $id]);
        echo json_encode(['ok' => true, 'admin_chat_id' => $id]);
        exit;
    }

    // تنظیمات استعلام حق بیمه (ثالث) - قابل فعال/غیرفعال‌سازی و درصد قابل تغییر
    if ($action === 'get_premium_settings') {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN
            ('premium_group_discount_enabled','premium_group_discount_percent','premium_excess_discount_enabled','premium_excess_discount_percent','premium_zero_km_discount_enabled','premium_zero_km_discount_percent')");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        echo json_encode([
            'ok' => true,
            'group_discount_enabled' => ($rows['premium_group_discount_enabled'] ?? '1') === '1',
            'group_discount_percent' => floatval($rows['premium_group_discount_percent'] ?? 2.5),
            'excess_discount_enabled' => ($rows['premium_excess_discount_enabled'] ?? '1') === '1',
            'excess_discount_percent' => floatval($rows['premium_excess_discount_percent'] ?? 15),
            'zero_km_discount_enabled' => ($rows['premium_zero_km_discount_enabled'] ?? '1') === '1',
            'zero_km_discount_percent' => floatval($rows['premium_zero_km_discount_percent'] ?? 5),
        ]);
        exit;
    }

    if ($action === 'save_premium_settings') {
        $pairs = [
            'premium_group_discount_enabled' => !empty($data['group_discount_enabled']) ? '1' : '0',
            'premium_group_discount_percent' => floatval($data['group_discount_percent'] ?? 2.5),
            'premium_excess_discount_enabled' => !empty($data['excess_discount_enabled']) ? '1' : '0',
            'premium_excess_discount_percent' => floatval($data['excess_discount_percent'] ?? 15),
            'premium_zero_km_discount_enabled' => !empty($data['zero_km_discount_enabled']) ? '1' : '0',
            'premium_zero_km_discount_percent' => floatval($data['zero_km_discount_percent'] ?? 5),
        ];
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($pairs as $k => $v) { $stmt->execute([$k, $v, $v]); }
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس: ' . $e->getMessage()]);
}
