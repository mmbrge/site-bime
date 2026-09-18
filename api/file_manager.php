<?php
// فایل: api/file_manager.php
session_start();
require '../config/db.php';
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

// امن‌سازی مسیر: مسیر درخواستی هرگز نباید از ریشه‌ی بایگانی بیرون بزند
function safe_resolve($archiveRoot, $relative) {
    $relative = str_replace(['..'], '', (string)$relative);
    $target = realpath($archiveRoot . '/' . ltrim($relative, '/'));
    if ($target === false) return null;
    if (strpos($target, $archiveRoot) !== 0) return null;
    return $target;
}

function icon_for_file($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) return 'image';
    if ($ext === 'pdf') return 'pdf';
    return 'file';
}

try {
    // ۱. لیست محتوای یک پوشه (فقط یک سطح، برای مرور تدریجی)
    if ($action === 'list') {
        $relPath = $_GET['path'] ?? '';
        $dir = $relPath === '' ? $archiveRoot : safe_resolve($archiveRoot, $relPath);
        if (!$dir || !is_dir($dir)) { echo json_encode(['ok' => false, 'error' => 'مسیر یافت نشد.']); exit; }

        $items = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir . '/' . $entry;
            $relChild = trim(str_replace($archiveRoot, '', $full), '/');
            if (is_dir($full)) {
                // تعداد فایل‌های داخل پوشه (فقط شمارش سطحی-بازگشتی سبک، برای نمایش خلاصه)
                $fileCount = 0;
                $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
                foreach ($rii as $f) if ($f->isFile()) $fileCount++;
                $items[] = [
                    'type' => 'dir', 'name' => $entry, 'path' => $relChild,
                    'modified' => date('Y-m-d H:i:s', filemtime($full)),
                    'created' => date('Y-m-d H:i:s', filectime($full)),
                    'file_count' => $fileCount,
                ];
            } else {
                $items[] = [
                    'type' => 'file', 'name' => $entry, 'path' => $relChild,
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

        echo json_encode(['ok' => true, 'path' => $relPath, 'items' => $items]);
        exit;
    }

    // ۲. دانلود یک فایل مشخص
    if ($action === 'download') {
        $target = safe_resolve($archiveRoot, $_GET['path'] ?? '');
        if (!$target || !is_file($target)) { http_response_code(404); exit; }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($target) . '"');
        header('Content-Length: ' . filesize($target));
        readfile($target);
        exit;
    }

    // ۳. دانلود یک پوشه به‌صورت ZIP (موقت، بعد از دانلود حذف می‌شود)
    if ($action === 'zip_folder') {
        $target = safe_resolve($archiveRoot, $_GET['path'] ?? '');
        if (!$target || !is_dir($target)) { http_response_code(404); exit; }

        $tmpDir = sys_get_temp_dir();
        $zipPath = $tmpDir . '/' . uniqid('archive_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $localName = substr($file->getPathname(), strlen($target) + 1);
                $zip->addFile($file->getPathname(), $localName);
            }
        }
        $zip->close();

        $zipName = basename($target) . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
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

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archiveRoot, FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getMTime() >= $fromTs && $file->getMTime() < $toTs) {
                $localName = substr($file->getPathname(), strlen($archiveRoot) + 1);
                $zip->addFile($file->getPathname(), $localName);
                $count++;
            }
        }
        $zip->close();

        if ($count === 0) { @unlink($zipPath); echo json_encode(['ok' => false, 'error' => 'فایلی در این بازه‌ی زمانی یافت نشد.']); exit; }

        $zipName = 'بایگانی ' . ($fromStr ?: 'ابتدا') . ' تا ' . ($toStr ?: 'امروز') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
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
            if (mb_stripos($item->getFilename(), $q) !== false) {
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
    echo json_encode(['ok' => false, 'error' => 'خطا: ' . $e->getMessage()]);
}
