<?php
// فایل: api/ticket_actions.php
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
$userId = $_SESSION['user_id'];

function get_bot_token($pdo) {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bot_token'");
    return $stmt->fetchColumn() ?: null;
}

function bale_log($label, $data) {
    $logFile = dirname(__DIR__) . '/queue/bale_debug.log';
    $line = '[' . date('Y-m-d H:i:s') . "] $label: " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE)) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function bale_send($chat_id, $text, $token, $reply_to = null) {
    if (!$token || !$chat_id) return null;
    $payload = ['chat_id' => $chat_id, 'text' => $text];
    if ($reply_to) $payload['reply_to_message_id'] = $reply_to;
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/sendMessage");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    curl_close($ch);
    bale_log('ticket_reply_sendMessage', ['payload' => $payload, 'response' => $res]);
    $decoded = json_decode($res, true);
    return (is_array($decoded) && !empty($decoded['ok'])) ? $decoded['result'] : null;
}

function bale_send_ticket_file($chat_id, $filePath, $isImage, $token) {
    if (!$token || !$chat_id) return null;
    $method = $isImage ? 'sendPhoto' : 'sendDocument';
    $field = $isImage ? 'photo' : 'document';
    $ch = curl_init("https://tapi.bale.ai/bot" . $token . "/" . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $chat_id, $field => new CURLFile($filePath)]);
    $res = curl_exec($ch);
    curl_close($ch);
    bale_log('ticket_reply_sendFile', ['chat_id' => $chat_id, 'response' => $res]);
    $decoded = json_decode($res, true);
    return (is_array($decoded) && !empty($decoded['ok'])) ? $decoded['result'] : null;
}

try {
    // ۰. آپلود و ارسال فایل/عکس در یک تیکت (درخواست multipart، نه JSON)
    if (isset($_FILES['file']) && ($_POST['action'] ?? '') === 'send_file') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT chat_id FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $chatId = $stmt->fetchColumn();
        if (!$chatId) { echo json_encode(['ok' => false, 'error' => 'تیکت یافت نشد.']); exit; }

        $tmpDir = dirname(__DIR__) . '/queue/attachments';
        if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_FILES['file']['name']);
        $destPath = $tmpDir . '/' . $safeName;
        move_uploaded_file($_FILES['file']['tmp_name'], $destPath);
        compress_image_if_needed($destPath);

        $isImage = in_array(strtolower($ext), ['jpg','jpeg','png','webp','gif']);
        bale_send_ticket_file($chatId, $destPath, $isImage, get_bot_token($pdo));

        $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, admin_id, message, file_path) VALUES (?, 'ADMIN', ?, '[فایل ارسال شد]', ?)");
        $stmt->execute([$ticketId, $userId, 'queue/attachments/' . $safeName]);
        $pdo->prepare("UPDATE tickets SET last_message_at = NOW() WHERE id = ?")->execute([$ticketId]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ۱. لیست تیکت‌ها (باز اول، بعد بسته؛ همراه تعداد نخوانده و آخرین پیام)
    if ($action === 'list') {
        $stmt = $pdo->query("
            SELECT
                t.id, t.chat_id, t.person_id, t.sender_name, t.sender_username, t.subject,
                t.status, t.created_at, t.last_message_at,
                p.full_name, p.national_code,
                (SELECT message FROM ticket_messages WHERE ticket_id = t.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id AND sender_type = 'CUSTOMER' AND is_read = 0) AS unread_count
            FROM tickets t
            LEFT JOIN persons p ON t.person_id = p.id
            ORDER BY (t.status = 'OPEN') DESC, t.last_message_at DESC
        ");
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // ۲. پیام‌های یک تیکت + علامت‌گذاری به‌عنوان خوانده‌شده
    if ($action === 'messages') {
        $ticketId = intval($_GET['ticket_id'] ?? ($data['ticket_id'] ?? 0));
        $stmt = $pdo->prepare("SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC");
        $stmt->execute([$ticketId]);
        $messages = $stmt->fetchAll();

        $pdo->prepare("UPDATE ticket_messages SET is_read = 1 WHERE ticket_id = ? AND sender_type = 'CUSTOMER'")->execute([$ticketId]);

        $stmt = $pdo->prepare("SELECT customer_typing_at FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $typingAt = $stmt->fetchColumn();
        $customerTyping = $typingAt && (time() - strtotime($typingAt)) < 6;

        echo json_encode(['ok' => true, 'data' => $messages, 'customer_typing' => $customerTyping]);
        exit;
    }

    // کارشناس در حال تایپ است - برای نمایش «در حال نوشتن...» سمت کاربر
    if ($action === 'typing') {
        $ticketId = intval($_GET['ticket_id'] ?? ($data['ticket_id'] ?? 0));
        $pdo->prepare("UPDATE tickets SET admin_typing_at = NOW() WHERE id = ?")->execute([$ticketId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ویرایش پیامِ کارشناس در رشته‌ی تیکت (معادل همان قابلیتی که برای چت ربات وجود دارد)
    if ($action === 'edit_ticket_message') {
        $msgId = intval($data['message_id'] ?? 0);
        $newText = trim($data['message'] ?? '');
        if (!$msgId || $newText === '') { echo json_encode(['ok' => false, 'error' => 'ورودی نامعتبر.']); exit; }
        $pdo->prepare("UPDATE ticket_messages SET message = ? WHERE id = ? AND sender_type = 'ADMIN'")->execute([$newText, $msgId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // حذف پیامِ کارشناس در رشته‌ی تیکت
    if ($action === 'delete_ticket_message') {
        $msgId = intval($data['message_id'] ?? 0);
        if (!$msgId) { echo json_encode(['ok' => false, 'error' => 'ورودی نامعتبر.']); exit; }
        $pdo->prepare("DELETE FROM ticket_messages WHERE id = ? AND sender_type = 'ADMIN'")->execute([$msgId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ۳. پاسخ کارشناس
    if ($action === 'reply') {
        $ticketId = intval($data['ticket_id'] ?? 0);
        $messageText = trim($data['message'] ?? '');
        if (!$ticketId || $messageText === '') {
            echo json_encode(['ok' => false, 'error' => 'متن پیام یا شناسه تیکت نامعتبر است.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT chat_id, status FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            echo json_encode(['ok' => false, 'error' => 'تیکت یافت نشد.']);
            exit;
        }

        // آخرین پیام مشتری برای ریپلای در بله (اختیاری، تجربه‌ی بهتر)
        $stmt = $pdo->prepare("SELECT tg_message_id FROM ticket_messages WHERE ticket_id = ? AND sender_type = 'CUSTOMER' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$ticketId]);
        $lastCustomerMsgId = $stmt->fetchColumn();

        $bot_token = get_bot_token($pdo);

        // طبق تصمیم نهایی: متنِ کاملِ پاسخ کارشناس دیگر مستقیم در چتِ ربات فرستاده نمی‌شود.
        // پیام فقط در همین تیکت (بخش «ارتباط با کارشناس» در اپ) ثبت می‌شود و در ربات صرفاً یک
        // اعلانِ کوتاه می‌رود - آن هم پس از حذف اعلانِ قبلی، تا چت ربات شلوغ نشود.
        $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, admin_id, message, is_read) VALUES (?, 'ADMIN', ?, ?, 0)");
        $stmt->execute([$ticketId, $userId, $messageText]);

        $pdo->prepare("UPDATE tickets SET last_message_at = NOW(), status = 'OPEN' WHERE id = ?")->execute([$ticketId]);

        // ارسال یادآوری کوتاه در ربات (با حذف یادآوری قبلی) - متن مخصوص «پیام کارشناس»
        $stmt = $pdo->prepare("SELECT person_id FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $personId = $stmt->fetchColumn();
        push_bot_notice($pdo, $personId, $ticket['chat_id'], "💬 پیام جدید از کارشناس دارید. برای مشاهده و پاسخ، به بخش «ارتباط با کارشناس» در اپ مراجعه کنید.", $bot_token);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ۴. بستن تیکت (و بازگرداندن خودکار کاربر از حالت «در گفتگو» به منوی مناسب،
    //    تا کاربر مجبور نباشد خودش دوباره «پایان گفتگو» را بزند)
    if ($action === 'close') {
        $ticketId = intval($data['ticket_id'] ?? 0);
        $pdo->prepare("UPDATE tickets SET status = 'CLOSED' WHERE id = ?")->execute([$ticketId]);

        $stmt = $pdo->prepare("SELECT chat_id, person_id FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        if ($ticket && $ticket['chat_id']) {
            $bot_token = get_bot_token($pdo);
            $chatId = $ticket['chat_id'];
            $keyboard = null; // اگر کاربر شناسایی‌شده باشد، منوی مناسب را برایش می‌فرستیم

            if ($ticket['person_id']) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM introductions WHERE person_id = ?");
                $stmt->execute([$ticket['person_id']]);
                $hasIntro = $stmt->fetchColumn() > 0;
                $newState = $hasIntro ? 'MAIN_MENU' : 'LIMITED_MENU';
                $pdo->prepare("UPDATE persons SET conversation_state = ? WHERE id = ?")->execute([$newState, $ticket['person_id']]);
                $keyboard = $newState === 'MAIN_MENU'
                    ? ['keyboard' => [['📝 ثبت درخواست بیمه'], ['🚗 بازدید سلامت خودرو'], ['💬 ارتباط با کارشناس'], ['ℹ️ راهنما']], 'resize_keyboard' => true]
                    : ['keyboard' => [['💬 ارتباط با کارشناس'], ['🔄 بررسی مجدد وضعیت'], ['ℹ️ راهنما']], 'resize_keyboard' => true];
            }

            $payload = ['chat_id' => $chatId, 'text' => "این گفتگوی پشتیبانی توسط کارشناس بسته شد ✅\nبه منوی اصلی بازگشتید."];
            if ($keyboard) $payload['reply_markup'] = $keyboard;
            $ch = curl_init("https://tapi.bale.ai/bot" . $bot_token . "/sendMessage");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch); curl_close($ch);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

} catch (Exception $e) {
    error_log('[ticket_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس.']);
}
