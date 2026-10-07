<?php
// فایل: Archive/_guard.php
// نگهبانِ بایگانی: هر فایلِ داخلِ Archive (با قانونِ .htaccess همین پوشه) از این‌جا فرستاده می‌شود و فقط به
//   - کاربرِ واردشده‌ی پنل (همه‌ی نقش‌ها)، یا
//   - کاربرِ واردشده‌ی پنلِ شرکت‌ها، فقط برای فایل‌های شرکت(های) خودش
// بدونِ ورود، فایلِ مشتری‌ها از بیرون قابلِ دانلود نیست.
$siteRoot = dirname(__DIR__);
$uri = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
$pos = strpos($uri, '/Archive/');
if ($pos === false) { http_response_code(404); exit; }
$rel = substr($uri, $pos + 1);
$root = realpath(__DIR__);
$abs = realpath($siteRoot . '/' . $rel);
if (!$abs || !$root || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs) || basename($abs) === '_guard.php' || basename($abs)[0] === '.') {
    http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'فایل پیدا نشد.'; exit;
}

// ۱) نشستِ پنلِ داخلی
$allowed = false;
if (!empty($_COOKIE[session_name()])) {
    session_start();
    require $siteRoot . '/config/db.php';   // سپرِ امنیتی (خروجِ خودکار، IPِ مسدود، ...) هم همین‌جا اجرا می‌شود
    $allowed = !empty($_SESSION['user_id']);
    // بایگانیِ بیمه عمر: فقط با دسترسیِ «بایگانی بیمه عمر» یا «پرداخت‌های اقساط عمر»؛ «کاربر بیمه عمر» هم فقط همین پوشه را می‌بیند
    if ($allowed) {
        $__life = mb_strpos($rel, 'Archive/بایگانی/بایگانی بیمه عمر/') === 0;
        $__role = $_SESSION['role'] ?? '';
        // بایگانیِ نامه‌ها: مستقیم فقط مدیر کل (بقیه از داخلِ بخشِ نامه‌ها، با بررسیِ محرمانگی)
        if (mb_strpos($rel, 'Archive/بایگانی/بایگانی نامه‌ها/') === 0 && $__role !== 'ADMIN') $allowed = false;
        elseif ($__role === 'LIFE' && !$__life) $allowed = false;
        elseif ($__life && $__role !== 'ADMIN') {
            require_once $siteRoot . '/api/_perm.php';
            $__pp = perm_user_custom($pdo, $_SESSION['user_id']);
            if ($__pp === null) $__pp = perm_role_defaults($__role);
            $allowed = perm_allows($__pp, 'life-archive:view|life-pay:view');
        }
    }
    session_write_close();
}
// ۲) نشستِ پنلِ شرکت‌ها: فقط پوشه‌هایی که نامِ شرکتِ خودش در مسیرشان است
if (!$allowed && !empty($_COOKIE['bime_company_portal']) && mb_strpos($rel, 'Archive/بایگانی/بایگانی نامه‌ها/') !== 0) {
    session_name('bime_company_portal');
    session_start();
    if (!isset($pdo)) require $siteRoot . '/config/db.php';
    if (function_exists('sec_session_guard')) sec_session_guard($pdo);
    $cu = intval($_SESSION['company_user_id'] ?? 0);
    session_write_close();
    if ($cu) {
        try {
            $st = $pdo->prepare("SELECT c.name FROM company_portal_user_companies x JOIN companies c ON c.id = x.company_id
                                 JOIN company_portal_users u ON u.id = x.portal_user_id WHERE x.portal_user_id = ? AND u.is_active = 1");
            $st->execute([$cu]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $name = trim((string)$name);
                if ($name === '') continue;
                $clean = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $name));
                if (mb_strpos($rel, $name) !== false || ($clean !== '' && mb_strpos($rel, $clean) !== false)) { $allowed = true; break; }
            }
        } catch (Throwable $e) {}
    }
}
if (!$allowed) {
    if (!isset($pdo)) require $siteRoot . '/config/db.php';
    // فایل‌های «آزمایشِ زنده»ی خودِ سپرِ امنیت (Archive/_sectest_xxxxxxxx) رخدادِ امنیتی نیستند
    if (function_exists('sec_event') && !preg_match('/^_sectest_[0-9a-f]{8}\.(txt|php)$/', basename($rel))) sec_event($pdo, 'FILE_DENIED', $rel);
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>دسترسی ندارید</title><body style="font-family:tahoma;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh;margin:0"><div style="text-align:center"><div style="font-size:40px">🔒</div><h3>برای دیدنِ این فایل اول وارد پنل شوید.</h3><a href="' . str_repeat('../', max(0, substr_count($rel, '/'))) . 'index.php" style="color:#38bdf8">ورود به پنل</a></div></body></html>';
    exit;
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
          'zip' => 'application/zip', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'txt' => 'text/plain; charset=utf-8',
          'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'webm' => 'audio/webm', 'mp4' => 'video/mp4'];
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'txt', 'mp3', 'ogg', 'm4a', 'wav', 'webm', 'mp4'], true);
while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"file.{$ext}\"; filename*=UTF-8''" . rawurlencode(basename($abs)));
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=300');
readfile($abs);
