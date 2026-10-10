<?php
// فایل: api/_dl_name.php
// نامِ فایل‌ها و پوشه‌هایی که از بایگانی دانلود می‌شوند:
//  - داخلِ ZIP: دقیقاً همان نامِ بایگانی («پلاک ‎- نام ‎- 1∕49357∕… ‎- VIN»، با نویسه‌ی نامرئیِ LRM کنارِ «-» و «∕» به‌جای «/»)
//  - دانلودِ تکیِ فایل/ZIP: مرورگرها (کروم، فایرفاکس، اج) نویسه‌های نامرئیِ جهت را در نامِ فایلِ دانلودی «_» می‌کنند
//    («58 _- عباس»)؛ پس فقط همان‌ها حذف می‌شوند («58 - عباس») و «∕» می‌ماند
//  - «(مهر) » ابتدای نامِ پوشه‌های بایگانیِ کسر از حقوق در دانلود نمی‌آید
if (!function_exists('dl_name')) {
    // نامِ یک بخش (بدونِ «/» در خودش: «/» به «∕» و نویسه‌های غیرمجازِ ویندوز به «_»)
    function dl_part($s) {
        $s = preg_replace('/^\((?:فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند)\)\s*/u', '', trim((string)$s));
        $s = preg_replace('/[<>:"\\\\|?*\x00-\x1F]/u', '_', str_replace('/', '∕', $s));
        return trim(preg_replace('/ {2,}/u', ' ', $s));
    }
    // نامِ دانلودِ تکی (سربرگِ Content-Disposition)
    function dl_name($s) {
        $s = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}]/u', '', (string)$s);
        return dl_part($s);
    }
    // مسیرِ داخلِ ZIP: هر بخش با همان نامِ بایگانی (LRM می‌ماند؛ ویندوز و WinRAR/7-Zip درست نشانش می‌دهند)؛
    // اگر دو مسیر یکی شدند، به دومی «(۲)» اضافه می‌شود
    function dl_zip_path($rel, array &$used = null) {
        $parts = array_map('dl_part', explode('/', str_replace('\\', '/', (string)$rel)));
        $p = implode('/', $parts);
        if ($used !== null) {
            $base = $p; $i = 2;
            while (isset($used[$p])) { $dot = strrpos($base, '.'); $p = $dot !== false && $dot > strrpos($base, '/') ? substr($base, 0, $dot) . " ($i)" . substr($base, $dot) : "$base ($i)"; $i++; }
            $used[$p] = true;
        }
        return $p;
    }
    // «دانلود با نامِ دقیق»: مرورگرها نویسه‌ی نامرئیِ LRM را در نامِ فایلِ دانلودی «_» می‌کنند (و بدونِ آن ویندوز «∕» را مربع
    // نشان می‌دهد)؛ داخلِ ZIP نام دست نمی‌خورد. پس همان یک فایل داخلِ یک ZIP با نامِ دقیقِ بایگانی فرستاده می‌شود.
    function dl_send_exact_zip($abs) {
        if (!is_file($abs) || !class_exists('ZipArchive')) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'فایل پیدا نشد.'; exit; }
        $tmp = tempnam(sys_get_temp_dir(), 'exz');
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit; }
        $entry = dl_zip_path(basename($abs));
        $z->addFile($abs, $entry);
        $z->close();
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('X-Content-Type-Options: nosniff');
        header(dl_disposition('attachment', pathinfo(basename($abs), PATHINFO_FILENAME) . '.zip', 'file.zip'));
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
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
