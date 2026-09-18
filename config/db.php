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
