<?php
// فایل: api/staff_chat_actions.php
// چت مستقیم بین کاربران داخلیِ پنل (هر کسی که وارد پنل شده، با هر کاربر دیگری).
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}
$myId = intval($_SESSION['user_id']);

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

try {
    // ---- لیست همکاران + آخرین پیام + تعداد نخوانده ----
    if ($action === 'list_conversations') {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.role,
                   (SELECT message FROM staff_chat_messages
                    WHERE (from_user_id = u.id AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = u.id)
                    ORDER BY created_at DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM staff_chat_messages
                    WHERE (from_user_id = u.id AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = u.id)
                    ORDER BY created_at DESC LIMIT 1) AS last_at,
                   (SELECT COUNT(*) FROM staff_chat_messages WHERE from_user_id = u.id AND to_user_id = ? AND is_read = 0) AS unread
            FROM users u WHERE u.id != ? ORDER BY last_at IS NULL, last_at DESC
        ");
        $stmt->execute([$myId, $myId, $myId, $myId, $myId, $myId]);
        echo json_encode(['ok' => true, 'conversations' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- پیام‌های یک گفتگو با یک همکار مشخص ----
    if ($action === 'get_messages') {
        $withUserId = intval($data['with_user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM staff_chat_messages
                                WHERE (from_user_id = ? AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = ?)
                                ORDER BY created_at ASC");
        $stmt->execute([$myId, $withUserId, $withUserId, $myId]);
        $pdo->prepare("UPDATE staff_chat_messages SET is_read = 1 WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0")->execute([$withUserId, $myId]);
        echo json_encode(['ok' => true, 'messages' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ارسال پیام (متن و/یا فایل) ----
    if ($action === 'send_message') {
        $toUserId = intval($data['to_user_id'] ?? 0);
        $message = trim($data['message'] ?? '');
        if (!$toUserId) { echo json_encode(['ok' => false, 'error' => 'مخاطب مشخص نیست.']); exit; }

        $filePath = null;
        if (!empty($_FILES['file'])) {
            $siteRoot = dirname(__DIR__);
            $destDir = temp_archive_root($siteRoot) . '/چت داخلی';
            $saved = company_store_uploaded_file($_FILES['file'], $destDir);
            if (!$saved['ok']) { echo json_encode($saved); exit; }
            $filePath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        }
        if ($message === '' && !$filePath) { echo json_encode(['ok' => false, 'error' => 'پیام یا فایلی ارسال نشد.']); exit; }

        $pdo->prepare("INSERT INTO staff_chat_messages (from_user_id, to_user_id, message, file_path) VALUES (?, ?, ?, ?)")
            ->execute([$myId, $toUserId, $message ?: null, $filePath]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[staff_chat_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
