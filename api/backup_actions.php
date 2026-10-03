<?php
// فایل: api/backup_actions.php
// «تنظیمات سیستم ← پشتیبان‌گیری، خروجی و ورود اطلاعات» - فقط مدیر کل
//   list            : فهرست بکاپ‌های پوشه‌ی backup (هر روز یک پوشه)
//   create          : ساخت بکاپ تازه (کامل یا فقط دیتابیس) - با رمز مدیر
//   download        : دانلود یک بکاپ (GET)
//   restore         : بازیابی از یکی از بکاپ‌های روی هاست - با رمز مدیر
//   upload_restore  : آپلود فایل ZIP و بازیابی از آن - با رمز مدیر (multipart)
//   delete          : حذف یک بکاپ - با رمز مدیر
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => 'دسترسی ندارید.']); exit; }
ini_set('display_errors', '0');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require_once __DIR__ . '/_backup_core.php';

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $_GET['action'] ?? ($data['action'] ?? '');
$userId = intval($_SESSION['user_id']);

function bk_json($arr) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

if (($_SESSION['role'] ?? '') !== 'ADMIN') bk_json(['ok' => false, 'error' => 'فقط مدیر کل به پشتیبان‌گیری دسترسی دارد.']);

function bk_check_password($pdo, $userId, $password) {
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    return $password !== '' && $hash && password_verify($password, $hash);
}

function bk_user_name($pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(full_name,''), username) FROM users WHERE id = ?");
        $st->execute([$userId]);
        return (string)$st->fetchColumn();
    } catch (Throwable $e) { return ''; }
}

function bk_ini_bytes($v) {
    $v = trim((string)$v); $n = (float)$v; $u = strtolower(substr($v, -1));
    if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
    return (int)$n;
}

try {
    if ($action === 'list') {
        $free = @disk_free_space(bk_site_root());
        bk_json(['ok' => true, 'data' => bk_list(),
                 'upload_limit' => min(bk_ini_bytes(ini_get('upload_max_filesize')), bk_ini_bytes(ini_get('post_max_size'))),
                 'free_space' => $free === false ? null : $free,
                 'zip' => class_exists('ZipArchive')]);
    }

    if ($action === 'download') {
        $path = bk_resolve($_GET['file'] ?? '');
        if (!$path) { http_response_code(404); bk_json(['ok' => false, 'error' => 'فایل بکاپ پیدا نشد.']); }
        @set_time_limit(0);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('X-Content-Type-Options: nosniff');
        $fh = fopen($path, 'rb');
        while (!feof($fh)) { echo fread($fh, 1048576); flush(); }
        fclose($fh);
        exit;
    }

    $password = trim($data['password'] ?? '');
    if (!bk_check_password($pdo, $userId, $password)) bk_json(['ok' => false, 'error' => 'رمز عبور نادرست است.']);

    if ($action === 'create') {
        $mode = ($data['mode'] ?? 'full') === 'db' ? 'db' : 'full';
        $note = mb_substr(trim($data['note'] ?? ''), 0, 200);
        $res = bk_create($pdo, $mode, 'manual', $note, bk_user_name($pdo, $userId));
        try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'BACKUP', 'system', 0, ?)")->execute([$userId, $res['rel']]); } catch (Throwable $e) {}
        bk_json(['ok' => true, 'file' => $res['rel'], 'size' => $res['size'], 'rows' => $res['manifest']['rows'], 'files' => $res['manifest']['files']]);
    }

    if ($action === 'delete') {
        $path = bk_resolve($data['file'] ?? '');
        if (!$path) bk_json(['ok' => false, 'error' => 'فایل بکاپ پیدا نشد.']);
        @unlink($path);
        $dir = dirname($path);
        if (count(scandir($dir)) <= 2) @rmdir($dir);
        bk_json(['ok' => true]);
    }

    if ($action === 'restore' || $action === 'upload_restore') {
        if ($action === 'restore') {
            $path = bk_resolve($data['file'] ?? '');
            if (!$path) bk_json(['ok' => false, 'error' => 'فایل بکاپ پیدا نشد.']);
        } else {
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
                bk_json(['ok' => false, 'error' => in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'حجم فایل از سقف آپلود هاست بیشتر است؛ فایل را با File Manager هاست در پوشه‌ی backup بگذارید تا در فهرست بیاید و از همان‌جا بازیابی کنید.'
                    : 'فایلی آپلود نشد.']);
            }
            if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'zip') bk_json(['ok' => false, 'error' => 'فقط فایل ZIP بکاپ پذیرفته می‌شود.']);
            $dir = bk_prepare_root() . '/' . bk_today_folder();
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $path = $dir . '/uploaded_' . date('Hi') . '_' . bin2hex(random_bytes(4)) . '.zip';
            if (!move_uploaded_file($f['tmp_name'], $path)) bk_json(['ok' => false, 'error' => 'ذخیره‌ی فایل آپلودشده ممکن نشد.']);
            if (!bk_read_manifest($path)) { @unlink($path); bk_json(['ok' => false, 'error' => 'این فایل، بکاپِ همین سامانه نیست.']); }
        }

        // پیش از بازیابی، از وضعیت فعلی یک بکاپ کامل گرفته می‌شود تا اگر اشتباهی شد برگشت‌پذیر باشد
        $safety = null;
        if (!isset($data['safety']) || !empty($data['safety']) && $data['safety'] !== '0') {
            $safety = bk_create($pdo, 'full', 'before-restore', 'پیش از بازیابی ' . basename($path), bk_user_name($pdo, $userId));
        }
        $res = bk_restore($pdo, $path, !isset($data['with_files']) || ($data['with_files'] && $data['with_files'] !== '0'));
        try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'RESTORE', 'system', 0, ?)")->execute([$userId, basename($path)]); } catch (Throwable $e) {}
        // اگر کاربرِ فعلی در بکاپ نبود، نشست بسته می‌شود
        $still = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'ADMIN'");
        $still->execute([$userId]);
        $relogin = !$still->fetchColumn();
        if ($relogin) { $_SESSION = []; @session_destroy(); }
        bk_json(['ok' => true, 'result' => $res, 'safety' => $safety ? $safety['rel'] : null, 'relogin' => $relogin]);
    }

    bk_json(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[backup_actions] ' . $e->getMessage());
    bk_json(['ok' => false, 'error' => $e->getMessage()]);
}
