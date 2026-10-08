<?php
// تنظیمات اتصال به دیتابیس
$host = 'localhost';
$dbname = 'besiteir_dbm';
$username = 'besiteir_dbmu';

// رمز عبور دیتابیس داخل گیت ذخیره نمی‌شود (اطلاعات حساس).
// آن را در فایل config/db.local.php (که در .gitignore قرار دارد) تعریف کنید،
// یا با متغیر محیطی DB_PASSWORD روی سرور ست کنید.
// نمونه: config/db.example.php را کپی کرده و به db.local.php تغییر نام دهید.
// صفحه‌های خطای اختصاصی (errors/*.html) برای خطای دیتابیس و خطاهای مرگبارِ PHP
require_once dirname(__DIR__) . '/api/_errors.php';

$localConfigFile = __DIR__ . '/db.local.php';
if (file_exists($localConfigFile)) {
    require $localConfigFile; // باید $password را مقداردهی کند
} else {
    $password = getenv('DB_PASSWORD') ?: '';
}

if ($password === '') {
    error_log('DB password not configured (config/db.local.php)');
    site_error_page(500, 'تنظیماتِ دیتابیس کامل نیست (config/db.local.php).');
    exit;
}

// همه‌ی ساعت‌های سایت (ثبت و نمایش) به وقتِ ایران و بر اساسِ ساعتِ سرور: PHP و MySQL هر دو روی ایران
// (ایران از ۱۴۰۱ ساعتِ تابستانی ندارد؛ همیشه +03:30)
date_default_timezone_set('Asia/Tehran');
// نام‌های فارسیِ فایل: basename()/pathinfo() در PHP 7.4 با locale غیر UTF-8 حروفِ چندبایتیِ اولِ نام را می‌اندازد
// («گزارش.pdf» => «.pdf»)؛ فقط دسته‌ی حروف (LC_CTYPE) روی UTF-8 می‌رود و ممیزِ اعداد دست نمی‌خورد
@setlocale(LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8', 'en_US.utf8', 'UTF-8');

try {
    // ایجاد اتصال ایمن با PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);

    // تنظیمات خطاگیری و امنیت
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // از PHP 8.1 به بعد اعدادِ دیتابیس (INT/FLOAT) به‌صورتِ عدد برمی‌گردند ولی در PHP 7.4 متن بودند؛
    // این تنظیم رفتار را در همه‌ی نسخه‌ها یکسان (مثلِ 7.4) نگه می‌دارد تا بعد از ارتقای PHP هیچ مقایسه/خروجی‌ای عوض نشود
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    // NOW() و CURRENT_TIMESTAMPِ دیتابیس هم مثلِ date()ِ PHP به وقتِ ایران
    try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}

} catch (PDOException $e) {
    // جزئیات خطا فقط در لاگ سرور ثبت می‌شود، نه در خروجی عمومی
    error_log('DB connection failed: ' . $e->getMessage());
    site_error_page(503, 'ارتباط با دیتابیس برقرار نیست؛ چند لحظه بعد دوباره امتحان کنید.');
    exit;
}

// نقشِ «کاربر پارسیان» فقط به ماژولِ گزارش بازدید دسترسی دارد: هر API یا صفحه‌ی پنل که نشستش را
// قبل از این فایل باز کرده (همه‌ی بخش‌های پنلِ داخلی) برایش بسته است. اینجا هیچ نشستی باز نمی‌شود تا
// نشستِ جداگانه‌ی پنل شرکت‌ها و وب‌اپ دست نخورد.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
    if (in_array($_SESSION['role'] ?? '', ['PARSIAN', 'LIFE'], true) && session_name() !== 'bime_company_portal') {
        $__vrScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        // «کاربر بیمه عمر» (LIFE) همین محدودیت را دارد ولی به‌جای گزارشِ بازدید، فقط بخشِ بیمه عمر و بایگانیِ خودش
        $__isLife = ($_SESSION['role'] ?? '') === 'LIFE';
        $__vrAllowed = ['/dashboard.php', '/index.php', '/logout.php', $__isLife ? '/api/life_actions.php' : '/api/visit_reports.php', '/api/otp_login.php', '/api/chat_actions.php', '/api/work_actions.php',
                        // امکاناتِ رفاهی (اگر مدیر برایش روشن کرده)، اعلان‌های پاپ‌آپ و بررسیِ نشست برای خروجِ خودکار
                        '/api/comfort_actions.php', '/api/announce_actions.php', '/api/alive.php', '/api/tools_actions.php'];
        if ($__isLife) $__vrAllowed[] = '/Archive/_guard.php';   // فقط پوشه‌ی «بایگانی بیمه عمر» (در خودِ _guard.php)
        $__vrOk = false;
        foreach ($__vrAllowed as $__a) if (substr($__vrScript, -strlen($__a)) === $__a) { $__vrOk = true; break; }
        // اگر مدیر کل برایش دسترسیِ سفارشی تعیین کرده، همان دسترسی‌ها (api/_perm.php) تعیین‌کننده است
        if (!$__vrOk) {
            try {
                $__st = $pdo->prepare("SELECT perm_json FROM users WHERE id = ?");
                $__st->execute([intval($_SESSION['user_id'] ?? 0)]);
                $__pj = $__st->fetchColumn();
                if ($__pj !== false && $__pj !== null && $__pj !== '') {
                    require_once dirname(__DIR__) . '/api/_perm.php';
                    $__vrOk = isset(perm_api_map()[basename($__vrScript, '.php')]);   // فقط APIهایی که دسترسیِ سفارشی کنترلشان می‌کند
                }
            } catch (Throwable $e) {}
        }
        if (!$__vrOk) {
            if (strpos($__vrScript, '/api/') !== false) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'این بخش برای «' . ($__isLife ? 'کاربر بیمه عمر' : 'کاربر پارسیان') . '» در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
            } else {
                header('Location: ' . (strpos($__vrScript, '/company-portal/') !== false || strpos($__vrScript, '/webapp/') !== false ? '../dashboard.php' : 'dashboard.php'));
            }
            exit;
        }
    }
}

// سپرِ امنیتی: سرتیترها، CSRF، آپلودِ خطرناک، IPِ مسدود، کلیدِ وب‌هوک، خروجِ خودکار بعد از عدمِ فعالیت (api/_security.php)
require_once dirname(__DIR__) . "/api/_security.php";
sec_boot($pdo);
