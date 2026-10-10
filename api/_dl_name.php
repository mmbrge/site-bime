<?php
// فایل: api/_dl_name.php
// نامِ فایل‌ها و پوشه‌هایی که از بایگانی دانلود می‌شوند (فایلِ تکی و داخلِ ZIP):
//  - نویسه‌های نامرئیِ جهت (LRM/RLM/…) که در نام‌گذاریِ بایگانی کنارِ «-» هست حذف می‌شوند؛ مرورگر و ویندوز آن‌ها را «_» می‌کنند
//    («حسنی _- 0311…» => «حسنی - 0311…»)
//  - «(مهر) » ابتدای نامِ پوشه‌های بایگانیِ کسر از حقوق در دانلود نمی‌آید
if (!function_exists('dl_name')) {
    function dl_name($s) {
        $s = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}]/u', '', (string)$s);
        $s = preg_replace('/^\((?:فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند)\)\s*/u', '', $s);
        $s = preg_replace('/ {2,}/u', ' ', $s);
        return trim($s);
    }
    // مسیرِ داخلِ ZIP: هر بخش جدا تمیز می‌شود؛ اگر دو مسیر یکی شدند، به دومی «(۲)» اضافه می‌شود
    function dl_zip_path($rel, array &$used = null) {
        $parts = array_map('dl_name', explode('/', str_replace('\\', '/', (string)$rel)));
        $p = implode('/', $parts);
        if ($used !== null) {
            $base = $p; $i = 2;
            while (isset($used[$p])) { $dot = strrpos($base, '.'); $p = $dot !== false && $dot > strrpos($base, '/') ? substr($base, 0, $dot) . " ($i)" . substr($base, $dot) : "$base ($i)"; $i++; }
            $used[$p] = true;
        }
        return $p;
    }
    // سربرگِ دانلود با نامِ فارسیِ درست (نامِ لاتینِ جایگزین برای مرورگرهای قدیمی)
    function dl_disposition($type, $name, $fallback = 'file') {
        $name = dl_name($name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $fb = preg_replace('/[^A-Za-z0-9._-]/', '', $fallback) ?: 'file';
        if ($ext !== '' && strpos($fb, '.') === false) $fb .= '.' . $ext;
        return 'Content-Disposition: ' . $type . '; filename="' . $fb . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
