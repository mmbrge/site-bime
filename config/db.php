<?php
// تنظیمات اتصال به دیتابیس
$host = 'localhost';
$dbname = 'besiteir_dbm';
$username = 'besiteir_dbmu';

// رمز عبور دیتابیس داخل گیت ذخیره نمی‌شود (اطلاعات حساس).
// آن را در فایل config/db.local.php (که در .gitignore قرار دارد) تعریف کنید،
// یا با متغیر محیطی DB_PASSWORD روی سرور ست کنید.
// نمونه: config/db.example.php را کپی کرده و به db.local.php تغییر نام دهید.
$localConfigFile = __DIR__ . '/db.local.php';
if (file_exists($localConfigFile)) {
    require $localConfigFile; // باید $password را مقداردهی کند
} else {
    $password = getenv('DB_PASSWORD') ?: '';
}

if ($password === '') {
    die('❌ رمز دیتابیس تنظیم نشده. فایل config/db.local.php را بر اساس config/db.example.php بسازید.');
}

try {
    // ایجاد اتصال ایمن با PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);

    // تنظیمات خطاگیری و امنیت
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // جزئیات خطا فقط در لاگ سرور ثبت می‌شود، نه در خروجی عمومی
    error_log('DB connection failed: ' . $e->getMessage());
    die('❌ خطا در اتصال به دیتابیس.');
}

// نقشِ «کاربر پارسیان» فقط به ماژولِ گزارش بازدید دسترسی دارد: هر API یا صفحه‌ی پنل که نشستش را
// قبل از این فایل باز کرده (همه‌ی بخش‌های پنلِ داخلی) برایش بسته است. اینجا هیچ نشستی باز نمی‌شود تا
// نشستِ جداگانه‌ی پنل شرکت‌ها و وب‌اپ دست نخورد.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
    if (($_SESSION['role'] ?? '') === 'PARSIAN' && session_name() !== 'bime_company_portal') {
        $__vrScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $__vrAllowed = ['/dashboard.php', '/index.php', '/logout.php', '/api/visit_reports.php', '/api/otp_login.php'];
        $__vrOk = false;
        foreach ($__vrAllowed as $__a) if (substr($__vrScript, -strlen($__a)) === $__a) { $__vrOk = true; break; }
        if (!$__vrOk) {
            if (strpos($__vrScript, '/api/') !== false) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'این بخش برای «کاربر پارسیان» در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
            } else {
                header('Location: ' . (strpos($__vrScript, '/company-portal/') !== false || strpos($__vrScript, '/webapp/') !== false ? '../dashboard.php' : 'dashboard.php'));
            }
            exit;
        }
    }
}
