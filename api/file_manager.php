<?php
// فایل: api/file_manager.php
session_start();
require_once __DIR__ . '/_dl_name.php';   // نامِ درستِ فایل‌های دانلودی (بدونِ نویسه‌های نامرئی و «(ماه)»)
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require __DIR__ . '/_case_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

$archiveRoot = realpath(dirname(__DIR__) . '/Archive/بایگانی');
if (!$archiveRoot) {
    @mkdir(dirname(__DIR__) . '/Archive/بایگانی', 0777, true);
    $archiveRoot = realpath(dirname(__DIR__) . '/Archive/بایگانی');
}

$action = $_GET['action'] ?? '';

// «بایگانی بیمه عمر» فقط برای کسی که دسترسیِ «بایگانی بیمه عمر» دارد (مدیر کل همیشه)
$fmLifeOk = true;
if (perm_real_role() !== 'ADMIN') {
    $__pp = perm_user_custom($pdo, $_SESSION['user_id']);
    if ($__pp === null) $__pp = perm_role_defaults(perm_real_role());
    $fmLifeOk = perm_allows($__pp, 'life-archive:view');
}
// «بایگانی نامه‌ها» (شاملِ نامه‌های محرمانه) فقط برای مدیر کل؛ بقیه از بخشِ نامه‌ها
$fmLettersOk = perm_real_role() === 'ADMIN';
// دسترسیِ پوشه‌به‌پوشه‌ی همین کاربر (از «کاربران ← دسترسی‌ها ← پوشه‌های بایگانی»)
$fmAcl = perm_fm_acl($pdo);
function fm_rel($archiveRoot, $abs) { return trim(str_replace('\\', '/', substr($abs, strlen($archiveRoot))), '/'); }
function fm_level($archiveRoot, $abs) { return perm_fm_level($GLOBALS['fmAcl'] ?? null, fm_rel($archiveRoot, $abs)); }
function fm_hidden($archiveRoot, $abs) {
    $acl = $GLOBALS['fmAcl'] ?? null;
    if ($acl !== null && $abs !== $archiveRoot) {
        $rel = fm_rel($archiveRoot, $abs);
        if (is_dir($abs) ? !perm_fm_visible($acl, $rel) : perm_fm_level($acl, $rel) === 'none') return true;
    }
    // «بایگانی حذف شده‌ها» (پوشه‌ی درخواست‌های حذف‌شده) فقط برای مدیر کل
    $dl = $archiveRoot . '/بایگانی حذف شده‌ها';
    if (empty($GLOBALS['fmLettersOk']) && ($abs === $dl || strpos($abs, $dl . '/') === 0)) return true;
    $lt = $archiveRoot . '/بایگانی نامه‌ها';
    if (empty($GLOBALS['fmLettersOk']) && ($abs === $lt || strpos($abs, $lt . '/') === 0)) return true;
    if (!empty($GLOBALS['fmLifeOk'])) return false;
    $life = $archiveRoot . '/بایگانی بیمه عمر';
    return $abs === $life || strpos($abs, $life . '/') === 0;
}

// امن‌سازی مسیر: مسیر درخواستی هرگز نباید از ریشه‌ی بایگانی بیرون بزند
function safe_resolve($archiveRoot, $relative) {
    $relative = str_replace(['..'], '', (string)$relative);
    $target = realpath($archiveRoot . '/' . ltrim($relative, '/'));
    if ($target === false) return null;
    if ($target !== $archiveRoot && strpos($target, $archiveRoot . DIRECTORY_SEPARATOR) !== 0) return null;   // فقط داخلِ خودِ بایگانی (نه پوشه‌ی هم‌نامِ کناری)
    if (fm_hidden($archiveRoot, $target)) return null;
    return $target;
}

function icon_for_file($filename) {
    $ext = upload_ext($filename);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) return 'image';
    if ($ext === 'pdf') return 'pdf';
    return 'file';
}

try {
    // «پوشه‌های بی‌درخواست»: پوشه‌ی درخواست/ردیفی که دیگر هیچ درخواست، ردیف یا مدرکِ زنده‌ای به آن اشاره نمی‌کند
    // (مثلاً درخواستی که حذف شده). فقط مدیر کل؛ پیش‌نمایش و بعد انتقال به «بایگانی حذف شده‌ها» (هیچ فایلی پاک نمی‌شود) و حذفِ پوشه‌های خالی.
    if ($action === 'orphan_scan' || $action === 'orphan_move') {
        header('Content-Type: application/json; charset=utf-8');
        if (perm_real_role() !== 'ADMIN' || perm_is_elevated()) { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل.']); exit; }
        require_once __DIR__ . '/_deleted_archive.php';
        @set_time_limit(300);
        if (function_exists('sess_release')) sess_release(); else session_write_close();
        $dry = $action === 'orphan_scan';
        if (!$dry && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { echo json_encode(['ok' => false, 'error' => 'درخواست نامعتبر.']); exit; }
        $r = delarc_migrate($pdo, dirname(__DIR__), 240.0, $dry);
        if (!$dry) $r['pruned'] = delarc_prune_all(dirname(__DIR__), $pdo);
        echo json_encode(['ok' => true, 'complete' => !empty($r['ok']), 'count' => $dry ? count($r['list'] ?? []) : intval($r['moved']), 'list' => $dry ? array_slice($r['list'] ?? [], 0, 500) : [], 'pruned' => $r['pruned'] ?? 0,
                          'note' => empty($r['ok']) && !$dry && intval($r['moved']) === 0 ? 'پایگاهِ داده هیچ درخواستی نشان نداد؛ برای احتیاط کاری انجام نشد.' : ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // «مرتب‌سازیِ بایگانی بر اساسِ بیمه‌گر» (بایگانی پاسارگاد / بایگانی ایران)؛ فقط مدیر کل، اول پیش‌نمایش
    if ($action === 'arch_split_scan' || $action === 'arch_split_run') {
        header('Content-Type: application/json; charset=utf-8');
        if (perm_real_role() !== 'ADMIN' || perm_is_elevated()) { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل.']); exit; }
        require_once __DIR__ . '/_arch_split.php';
        @set_time_limit(0);
        if (function_exists('sess_release')) sess_release(); else session_write_close();
        if ($action === 'arch_split_run' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { echo json_encode(['ok' => false, 'error' => 'درخواست نامعتبر.']); exit; }
        $r = $action === 'arch_split_scan' ? arch_split_plan($pdo, dirname(__DIR__)) : arch_split_run($pdo, dirname(__DIR__));
        if ($action === 'arch_split_run' && !empty($r['ok'])) {
            try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'UPDATE', 'archive', 0, ?)")
                      ->execute([$_SESSION['user_id'] ?? null, 'arch_split: ' . json_encode(['moved' => $r['moved'], 'iran' => $r['iran'], 'db' => $r['db_rows']])]); } catch (Throwable $e) {}
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    // ۱. لیست محتوای یک پوشه (فقط یک سطح، برای مرور تدریجی)
    if ($action === 'list') {
        $relPath = $_GET['path'] ?? '';
        $dir = $relPath === '' ? $archiveRoot : safe_resolve($archiveRoot, $relPath);
        if (!$dir || !is_dir($dir)) { echo json_encode(['ok' => false, 'error' => 'مسیر یافت نشد.']); exit; }

        $items = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir . '/' . $entry;
            if (fm_hidden($archiveRoot, $full)) continue;
            $relChild = trim(str_replace($archiveRoot, '', $full), '/');
            if (is_dir($full)) {
                // تعداد فایل‌های داخل پوشه (فقط شمارش سطحی-بازگشتی سبک، برای نمایش خلاصه)
                $fileCount = 0;
                $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
                foreach ($rii as $f) if ($f->isFile() && ($fmAcl === null || !fm_hidden($archiveRoot, $f->getPathname()))) $fileCount++;
                $items[] = [
                    'type' => 'dir', 'name' => $entry, 'path' => $relChild, 'can_dl' => fm_level($archiveRoot, $full) === 'download', 'pass' => $fmAcl !== null && fm_level($archiveRoot, $full) === 'none',
                    'modified' => date('Y-m-d H:i:s', filemtime($full)),
                    'created' => date('Y-m-d H:i:s', filectime($full)),
                    'file_count' => $fileCount,
                ];
            } else {
                $items[] = [
                    'type' => 'file', 'name' => $entry, 'path' => $relChild, 'can_dl' => fm_level($archiveRoot, $full) === 'download',
                    'size' => format_file_size(filesize($full)), 'icon' => icon_for_file($entry),
                    'modified' => date('Y-m-d H:i:s', filemtime($full)),
                    'created' => date('Y-m-d H:i:s', filectime($full)),
                    'download_url' => 'api/file_manager.php?action=download&path=' . urlencode($relChild),
                ];
            }
        }
        // پوشه‌ها اول، بعد فایل‌ها؛ هرکدام الفبایی
        usort($items, function ($a, $b) {
            if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
            return strcmp($a['name'], $b['name']);
        });

        echo json_encode(['ok' => true, 'path' => $relPath, 'items' => $items, 'level' => $relPath === '' && $fmAcl !== null ? ($fmAcl[''] ?? 'download') : fm_level($archiveRoot, $dir), 'restricted' => $fmAcl !== null]);
        exit;
    }

    // ۲. دانلود یک فایل مشخص
    if ($action === 'download') {
        $target = safe_resolve($archiveRoot, $_GET['path'] ?? '');
        if (!$target || !is_file($target)) { http_response_code(404); exit; }
        $inline = !empty($_GET['inline']);
        // «فقط دیدن»: باز کردن در مرورگر بله، دانلود نه
        if (!$inline && fm_level($archiveRoot, $target) !== 'download') { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'شما فقط اجازه‌ی دیدنِ این پوشه را دارید (دانلود ممکن نیست).'; exit; }
        if (!empty($_GET['exact']) && !$inline) dl_send_exact_zip($target);   // دانلود با نامِ دقیق (ZIP)
        $ext = upload_ext($target);
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'txt' => 'text/plain; charset=utf-8'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline && $mime !== 'application/octet-stream' ? 'inline' : 'attachment') . "; filename=\"file.$ext\"; filename*=UTF-8''" . rawurlencode(dl_name(basename($target))));
        header('Content-Length: ' . filesize($target));
        readfile($target);
        exit;
    }

    // ۳. دانلود یک پوشه به‌صورت ZIP (موقت، بعد از دانلود حذف می‌شود)
    if ($action === 'zip_folder') {
        $target = safe_resolve($archiveRoot, $_GET['path'] ?? '');
        if (!$target || !is_dir($target)) { http_response_code(404); exit; }
        if (fm_level($archiveRoot, $target) !== 'download') { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'اجازه‌ی دانلودِ این پوشه را ندارید.'; exit; }

        $tmpDir = sys_get_temp_dir();
        $zipPath = $tmpDir . '/' . uniqid('archive_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $used = [];   // نام‌های داخلِ ZIP (برای جلوگیری از نامِ تکراری)
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isFile() && !fm_hidden($archiveRoot, $file->getPathname()) && fm_level($archiveRoot, $file->getPathname()) === 'download') {
                $localName = substr($file->getPathname(), strlen($target) + 1);
                $zip->addFile($file->getPathname(), dl_zip_path($localName, $used));
            }
        }
        $zip->close();

        $zipName = basename($target) . '.zip';
        header('Content-Type: application/zip');
        header(dl_disposition('attachment', $zipName, 'archive.zip'));
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    // ۴. دانلود کل بایگانی (یا زیرمجموعه‌ای از آن) در یک بازه‌ی زمانی، به‌صورت ZIP
    if ($action === 'zip_range') {
        $fromStr = $_GET['from'] ?? '';
        $toStr = $_GET['to'] ?? '';
        $fromTs = $fromStr ? parse_jalali_date_string($fromStr) : 0;
        $toTs = $toStr ? (parse_jalali_date_string($toStr) + 86400) : time() + 86400; // شامل انتهای روز پایانی

        $tmpDir = sys_get_temp_dir();
        $zipPath = $tmpDir . '/' . uniqid('archive_range_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $used = [];   // نام‌های داخلِ ZIP (برای جلوگیری از نامِ تکراری)

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archiveRoot, FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($rii as $file) {
            if ($file->isFile() && !fm_hidden($archiveRoot, $file->getPathname()) && fm_level($archiveRoot, $file->getPathname()) === 'download' && $file->getMTime() >= $fromTs && $file->getMTime() < $toTs) {
                $localName = substr($file->getPathname(), strlen($archiveRoot) + 1);
                $zip->addFile($file->getPathname(), dl_zip_path($localName, $used));
                $count++;
            }
        }
        $zip->close();

        if ($count === 0) { @unlink($zipPath); echo json_encode(['ok' => false, 'error' => 'فایلی در این بازه‌ی زمانی یافت نشد.']); exit; }

        $zipName = 'بایگانی ' . ($fromStr ?: 'ابتدا') . ' تا ' . ($toStr ?: 'امروز') . '.zip';
        header('Content-Type: application/zip');
        header(dl_disposition('attachment', $zipName, 'archive.zip'));
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    // ۵. جستجوی نام پوشه/فایل در کل بایگانی (برای پیدا کردن سریع یک شخص/پلاک/شرکت)
    if ($action === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) { echo json_encode(['ok' => false, 'error' => 'حداقل ۲ حرف وارد کنید.']); exit; }

        $results = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archiveRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($rii as $item) {
            if (mb_stripos($item->getFilename(), $q) !== false && !fm_hidden($archiveRoot, $item->getPathname())) {
                $rel = trim(str_replace($archiveRoot, '', $item->getPathname()), '/');
                $results[] = [
                    'type' => $item->isDir() ? 'dir' : 'file',
                    'name' => $item->getFilename(),
                    'path' => $item->isDir() ? $rel : dirname($rel), // برای فایل، مسیر پوشه‌ی حاوی آن را برمی‌گردانیم
                ];
                if (count($results) >= 50) break; // سقف نتایج برای جلوگیری از سنگینی
            }
        }
        echo json_encode(['ok' => true, 'data' => $results]);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    error_log('[file_manager] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطا در پردازش درخواست.']);
}
