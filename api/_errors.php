<?php
// فایل: api/_errors.php
// صفحه‌های خطای اختصاصی (errors/*.html): وقتی دیتابیس وصل نمی‌شود (۵۰۳) یا PHP خطای مرگبار می‌دهد (۵۰۰).
// برای APIها (و درخواست‌هایی که JSON می‌خواهند) به‌جای صفحه، همان پاسخِ JSONِ همیشگی برمی‌گردد.
// خطاهایی که خودِ وب‌سرور می‌دهد (۴۰۴، ۵۰۴، ۵۰۵ و ...) با ErrorDocument در .htaccessِ ریشه به همین صفحه‌ها می‌رسند.

if (!function_exists('site_error_page')) {
    function site_error_wants_json() {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        return strpos($script, '/api/') !== false
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false
            || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
    function site_error_page($code, $msg = '') {
        $code = intval($code) ?: 500;
        if (PHP_SAPI === 'cli') { fwrite(STDERR, "[$code] $msg\n"); return; }
        if (site_error_wants_json()) {
            if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'error' => $msg !== '' ? $msg : 'خطای سرور؛ چند لحظه بعد دوباره امتحان کنید.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $file = dirname(__DIR__) . '/errors/' . $code . '.html';
        if (!is_file($file)) $file = dirname(__DIR__) . '/errors/500.html';
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            if (in_array($code, [502, 503, 504], true)) header('Retry-After: 30');
            if (is_file($file)) { readfile($file); return; }
        } else {
            // بخشی از صفحه قبلاً فرستاده شده: صفحه‌ی خطا جایگزینش می‌شود
            echo '<script>location.replace("/errors/' . $code . '.html")</script>';
            return;
        }
        echo '<!doctype html><meta charset="utf-8"><title>خطا</title><p style="font-family:tahoma;text-align:center;margin-top:20vh">خطای ' . $code . ' — چند لحظه بعد دوباره امتحان کنید.</p>';
    }
    // خطای مرگبارِ PHP (حافظه، تابعِ ناموجود، خطای نحوی در فایلِ include‌شده و ...) => صفحه‌ی ۵۰۰ به‌جای صفحه‌ی سفید
    function site_error_on_shutdown() {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        if (site_error_wants_json()) {
            if (headers_sent()) return;   // بخشی از JSON رفته؛ دست نمی‌زنیم
            while (ob_get_level()) @ob_end_clean();
            site_error_page(500, 'خطای سرور؛ چند لحظه بعد دوباره امتحان کنید.');
            return;
        }
        while (ob_get_level()) @ob_end_clean();
        site_error_page(500);
    }
    if (PHP_SAPI !== 'cli') register_shutdown_function('site_error_on_shutdown');
}
