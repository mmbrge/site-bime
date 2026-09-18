<?php
// فایل: api/company_chat_actions.php
// چت ما (ادمین/همکار شرکت‌ها) با ثبت‌کننده‌های یک شرکت - یک گفتگوی مشترک به ازای هر شرکت.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';

$actor = require_admin_or_liaison();

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

try {
    // ---- لیست شرکت‌ها + آخرین پیام + تعداد نخوانده ----
    if ($action === 'list_conversations') {
        $stmt = $pdo->query("
            SELECT c.id, c.name,
                   (SELECT message FROM company_chat_messages WHERE company_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM company_chat_messages WHERE company_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_at,
                   (SELECT COUNT(*) FROM company_chat_messages WHERE company_id = c.id AND sender_type = 'COMPANY' AND is_read = 0) AS unread
            FROM companies c WHERE c.kind IN ('INSURANCE_CLIENT','BOTH')
            ORDER BY last_at IS NULL, last_at DESC
        ");
        echo json_encode(['ok' => true, 'conversations' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- پیام‌های گفتگو با یک شرکت مشخص ----
    if ($action === 'get_messages') {
        $companyId = intval($data['company_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT ccm.*, cpu.full_name AS sender_portal_name FROM company_chat_messages ccm
                                LEFT JOIN company_portal_users cpu ON cpu.id = ccm.sender_portal_user_id
                                WHERE ccm.company_id = ? ORDER BY ccm.created_at ASC");
        $stmt->execute([$companyId]);
        $pdo->prepare("UPDATE company_chat_messages SET is_read = 1 WHERE company_id = ? AND sender_type = 'COMPANY' AND is_read = 0")->execute([$companyId]);
        echo json_encode(['ok' => true, 'messages' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ارسال پیام به یک شرکت ----
    if ($action === 'send_message') {
        $companyId = intval($data['company_id'] ?? 0);
        $message = trim($data['message'] ?? '');
        if (!$companyId) { echo json_encode(['ok' => false, 'error' => 'شرکت مشخص نیست.']); exit; }

        $filePath = null;
        if (!empty($_FILES['file'])) {
            $siteRoot = dirname(__DIR__);
            $stmtName = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
            $stmtName->execute([$companyId]);
            $companyName = $stmtName->fetchColumn() ?: 'نامشخص';
            $destDir = temp_archive_root($siteRoot) . '/چت شرکت‌ها/' . sanitize_folder_name($companyName);
            $saved = company_store_uploaded_file($_FILES['file'], $destDir);
            if (!$saved['ok']) { echo json_encode($saved); exit; }
            $filePath = ltrim(str_replace($siteRoot, '', $saved['path']), '/');
        }
        if ($message === '' && !$filePath) { echo json_encode(['ok' => false, 'error' => 'پیام یا فایلی ارسال نشد.']); exit; }

        $pdo->prepare("INSERT INTO company_chat_messages (company_id, sender_type, sender_user_id, message, file_path) VALUES (?, 'ADMIN', ?, ?, ?)")
            ->execute([$companyId, $actor['user_id'], $message ?: null, $filePath]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[company_chat_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
