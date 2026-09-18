<?php
// فایل: api/user_actions.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($data['action'] ?? '');

function get_bot_token($pdo) {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bot_token'");
    return $stmt->fetchColumn() ?: null;
}

function bale_log($label, $data) {
    $logFile = dirname(__DIR__) . '/queue/bale_debug.log';
    $line = '[' . date('Y-m-d H:i:s') . "] $label: " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE)) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function bale_send($chat_id, $text, $token) {
    if (!$token || !$chat_id) return null;
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/sendMessage");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $chat_id, 'text' => $text], JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    curl_close($ch);
    bale_log('user_direct_sendMessage', ['chat_id' => $chat_id, 'response' => $res]);
    $decoded = json_decode($res, true);
    return (is_array($decoded) && !empty($decoded['ok'])) ? $decoded['result'] : null;
}

// ارسال فایل/عکس به کاربر از طریق بله (multipart, بدون curl json)
function bale_send_file($chat_id, $filePath, $isImage, $token) {
    if (!$token || !$chat_id) return null;
    $method = $isImage ? 'sendPhoto' : 'sendDocument';
    $field = $isImage ? 'photo' : 'document';
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/" . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $chat_id, $field => new CURLFile($filePath)]);
    $res = curl_exec($ch);
    curl_close($ch);
    bale_log('user_direct_sendFile', ['chat_id' => $chat_id, 'response' => $res]);
    $decoded = json_decode($res, true);
    return (is_array($decoded) && !empty($decoded['ok'])) ? $decoded['result'] : null;
}

try {
    // ۰. آپلود و ارسال فایل/عکس (درخواست multipart، نه JSON)
    if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'send_file') {
        $personId = intval($_POST['person_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT bale_chat_id FROM persons WHERE id = ?");
        $stmt->execute([$personId]);
        $chatId = $stmt->fetchColumn();
        if (!$chatId) { echo json_encode(['ok' => false, 'error' => 'این کاربر چت بله متصلی ندارد.']); exit; }

        $tmpDir = dirname(__DIR__) . '/queue/attachments';
        if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_FILES['file']['name']);
        $destPath = $tmpDir . '/' . $safeName;
        move_uploaded_file($_FILES['file']['tmp_name'], $destPath);
        compress_image_if_needed($destPath);

        $isImage = in_array(strtolower($ext), ['jpg','jpeg','png','webp','gif']);
        bale_send_file($chatId, $destPath, $isImage, get_bot_token($pdo));

        $stmt = $pdo->prepare("INSERT INTO messages (person_id, sender_type, message_text, file_path) VALUES (?, 'ADMIN', '[فایل ارسال شد]', ?)");
        $stmt->execute([$personId, 'queue/attachments/' . $safeName]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ۱. لیست کاربران (فقط کسانی که واقعاً با ربات چت کرده‌اند - یعنی bale_chat_id دارند)
    if ($action === 'list' || $action === 'search') {
        $q = trim($_GET['q'] ?? ($data['q'] ?? ''));
        $sql = "
            SELECT p.id, p.full_name, p.national_code, p.personnel_code, p.mobile_number,
                   p.bale_chat_id, p.conversation_state, c.name AS company_name, p.created_at
            FROM persons p
            LEFT JOIN companies c ON p.company_id = c.id
            WHERE p.bale_chat_id IS NOT NULL
        ";
        $params = [];
        if ($q !== '') {
            // شماره موبایل را در هر دو فرمت 09xxxxxxxxx و 989xxxxxxxxx قابل جستجو می‌کنیم
            $digits = preg_replace('/\D/', '', $q);
            $mobileVariants = [];
            if ($digits !== '') {
                if (substr($digits, 0, 2) === '98') $mobileVariants[] = '0' . substr($digits, 2);
                elseif (substr($digits, 0, 1) === '0') $mobileVariants[] = '98' . substr($digits, 1);
                $mobileVariants[] = $digits;
            }
            $conditions = ["p.full_name LIKE ?", "p.national_code LIKE ?", "p.personnel_code LIKE ?"];
            $params = ["%$q%", "%$q%", "%$q%"];
            foreach (array_unique($mobileVariants) as $mv) {
                $conditions[] = "p.mobile_number LIKE ?";
                $params[] = "%$mv%";
            }
            $sql .= " AND (" . implode(" OR ", $conditions) . ")";
        }
        $sql .= " ORDER BY p.created_at DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // ۲. تاریخچه پیام‌های مستقیم با یک کاربر (جدول messages)
    if ($action === 'history') {
        $personId = intval($_GET['person_id'] ?? ($data['person_id'] ?? 0));
        $stmt = $pdo->prepare("SELECT * FROM messages WHERE person_id = ? ORDER BY created_at ASC");
        $stmt->execute([$personId]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // ۳. ارسال پیام مستقیم از پنل به یک کاربر (خارج از چارچوب تیکت)
    if ($action === 'send_message') {
        $personId = intval($data['person_id'] ?? 0);
        $messageText = trim($data['message'] ?? '');
        if (!$personId || $messageText === '') {
            echo json_encode(['ok' => false, 'error' => 'متن پیام یا شناسه کاربر نامعتبر است.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT bale_chat_id, full_name FROM persons WHERE id = ?");
        $stmt->execute([$personId]);
        $person = $stmt->fetch();
        $chatId = $person['bale_chat_id'] ?? null;

        // مقصد پیام: 'app' یعنی در رشته‌ی «ارتباط با کارشناس» مینی‌اپ ثبت شود (نه پیام مستقیم
        // در ربات)؛ 'bot' یعنی همان رفتار قبلی - پیام مستقیم در چت بله
        $dest = $data['dest'] ?? 'bot';

        if ($dest === 'app') {
            require_once __DIR__ . '/_case_helpers.php';
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE person_id = ? AND status = 'OPEN' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$personId]);
            $ticketId = $stmt->fetchColumn();
            if (!$ticketId) {
                $stmt = $pdo->prepare("INSERT INTO tickets (chat_id, person_id, sender_name, last_message_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$chatId, $personId, $person['full_name'] ?? null]);
                $ticketId = $pdo->lastInsertId();
            }
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message, is_read) VALUES (?, 'ADMIN', ?, 0)")
                ->execute([$ticketId, $messageText]);
            $pdo->prepare("UPDATE tickets SET last_message_at = NOW(), status = 'OPEN' WHERE id = ?")->execute([$ticketId]);
            push_bot_notice($pdo, $personId, $chatId, "💬 پیام جدید از کارشناس دارید. برای مشاهده و پاسخ، به بخش «ارتباط با کارشناس» در اپ مراجعه کنید.", get_bot_token($pdo));
            echo json_encode(['ok' => true, 'ticket_id' => $ticketId]);
            exit;
        }

        if (!$chatId) {
            echo json_encode(['ok' => false, 'error' => 'این کاربر هنوز چت بله متصلی ندارد.']);
            exit;
        }

        $sent = bale_send($chatId, $messageText, get_bot_token($pdo));

        $stmt = $pdo->prepare("INSERT INTO messages (person_id, sender_type, message_text, tg_message_id) VALUES (?, 'ADMIN', ?, ?)");
        $stmt->execute([$personId, $messageText, $sent['message_id'] ?? null]);

        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // ۴. ویرایش یک پیامِ ادمین (هم در دیتابیس، هم درجا در بله برای کاربر)
    if ($action === 'edit_message') {
        $msgId = intval($data['message_id'] ?? 0);
        $newText = trim($data['message'] ?? '');
        if (!$msgId || $newText === '') { echo json_encode(['ok' => false, 'error' => 'ورودی نامعتبر است.']); exit; }

        $stmt = $pdo->prepare("SELECT m.tg_message_id, p.bale_chat_id FROM messages m JOIN persons p ON m.person_id = p.id WHERE m.id = ? AND m.sender_type = 'ADMIN'");
        $stmt->execute([$msgId]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['ok' => false, 'error' => 'پیام یافت نشد یا قابل ویرایش نیست.']); exit; }

        if ($row['tg_message_id'] && $row['bale_chat_id']) {
            $token = get_bot_token($pdo);
            $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/editMessageText");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $row['bale_chat_id'], 'message_id' => $row['tg_message_id'], 'text' => $newText], JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch); curl_close($ch);
        }

        $pdo->prepare("UPDATE messages SET message_text = ? WHERE id = ?")->execute([$newText, $msgId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ۵. حذف یک پیامِ ادمین (هم در دیتابیس، هم درجا در بله برای کاربر)
    if ($action === 'delete_message') {
        $msgId = intval($data['message_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT m.tg_message_id, p.bale_chat_id FROM messages m JOIN persons p ON m.person_id = p.id WHERE m.id = ? AND m.sender_type = 'ADMIN'");
        $stmt->execute([$msgId]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['ok' => false, 'error' => 'پیام یافت نشد یا قابل حذف نیست.']); exit; }

        if ($row['tg_message_id'] && $row['bale_chat_id']) {
            $token = get_bot_token($pdo);
            $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/deleteMessage");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $row['bale_chat_id'], 'message_id' => $row['tg_message_id']], JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch); curl_close($ch);
        }

        $pdo->prepare("UPDATE messages SET is_deleted = 1, message_text = '[پیام حذف شد]' WHERE id = ?")->execute([$msgId]);
        echo json_encode(['ok' => true]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس: ' . $e->getMessage()]);
}
