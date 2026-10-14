<?php
// فایل: api/settings_actions.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)

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
$action = $_GET['action'] ?? ($data['action'] ?? '');


// تغییرِ هر تنظیمی فقط کارِ مدیر است (قبلاً save_quota / save_site_url / save_admin_chat_id /
// save_premium_settings برای هر کاربرِ واردشده‌ای باز بود)
if (strpos($action, 'save_') === 0 && ($_SESSION['role'] ?? '') !== 'ADMIN') {
    echo json_encode(['ok' => false, 'error' => 'فقط مدیر دسترسی دارد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
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

    // تعدادِ صفحه‌های فایلِ بیمه‌نامه هنگامِ صدور (پیش‌فرض ۱)؛ بقیه‌ی صفحه‌ها فایلی جدا کنارِ بیمه‌نامه می‌شوند
    if ($action === 'get_policy_pages') {
        require_once __DIR__ . '/_pdf_split.php';
        echo json_encode(['ok' => true, 'pages' => policy_pages_setting($pdo)]);
        exit;
    }

    if ($action === 'save_policy_pages') {
        $n = max(1, min(20, intval($data['pages'] ?? 1)));
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('policy_file_pages', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string)$n]);
        echo json_encode(['ok' => true, 'pages' => $n]);
        exit;
    }

    // «پیش‌نیازها»: فایلِ آزمایشی (داخلِ ZIP) با همان الگوی نامِ بایگانی تا نمایشِ «∕» در ویندوز امتحان شود
    if ($action === 'prereq_test_zip') {
        if (!class_exists('ZipArchive')) { echo json_encode(['ok' => false, 'error' => 'ZipArchive روی سرور فعال نیست.'], JSON_UNESCAPED_UNICODE); exit; }
        $sep = " \u{200E}- ";
        $name = '25ایران 982 ل 87' . $sep . 'نمونه' . $sep . '1∕49357∕5051-0∕1405∕354' . $sep . 'IRFC0000000000000.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'prq');
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) { echo json_encode(['ok' => false, 'error' => 'ساختِ ZIP ممکن نشد.'], JSON_UNESCAPED_UNICODE); exit; }
        $z->addFromString($name, "این فایل فقط برای امتحانِ نمایشِ نامِ بیمه‌نامه‌ها در ویندوز است.\r\n");
        $z->close();
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="name-test.zip"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, no-store');
        readfile($tmp);
        @unlink($tmp);
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
    error_log('[settings_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس.']);
}
