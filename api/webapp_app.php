<?php
// فایل: api/webapp_app.php
// بک‌اندِ اپ اصلی یکپارچه (منو، بیمه‌نامه‌های من، پلاک‌های من، ارتباط با کارشناس، ورود به بازدید سلامت)
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';

// نکته‌ی مهم: فرانت‌اند برای اکشن‌های POST بدنه را به‌صورت JSON خام می‌فرستد
// (Content-Type: application/json)، نه فرم معمولی؛ پس PHP آن را در $_POST پر نمی‌کند
// و باید خودمان از php://input بخوانیم. این را یک‌بار در ابتدای فایل انجام می‌دهیم
// و در همه‌ی اکشن‌ها از همین $jsonBody استفاده می‌کنیم (نه خواندن دوباره‌ی php://input).
$jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($jsonBody['action'] ?? '');

function resolve_main_session($pdo, $token, $initData = null) {
    if ($token) {
        $stmt = $pdo->prepare("SELECT * FROM webapp_sessions WHERE token = ? AND mode = 'main' AND expires_at > NOW()");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        if ($session) {
            $stmt = $pdo->prepare("SELECT * FROM persons WHERE id = ?");
            $stmt->execute([$session['person_id']]);
            $person = $stmt->fetch();
            if ($person) return $person;
        }
    }
    // اگر توکنِ اختصاصیِ ما نبود (مثلاً ورود از طریق دکمه‌ی ثابتِ بات‌فادر در بله)، از initData
    // امضاشده‌ی خودِ بله برای شناسایی کاربر استفاده می‌کنیم
    if ($initData) {
        $botToken = bale_get_token($pdo);
        $chatId = validate_bale_init_data($initData, $botToken);
        if ($chatId) {
            $stmt = $pdo->prepare("SELECT * FROM persons WHERE bale_chat_id = ?");
            $stmt->execute([$chatId]);
            $person = $stmt->fetch();
            if ($person) return $person;
        }
    }
    return null;
}

try {
    // =====================================================================
    if ($action === 'init') {
        $token = $_GET['token'] ?? '';
        $initData = $_GET['init_data'] ?? '';
        $person = resolve_main_session($pdo, $token, $initData);
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر یا منقضی است. لطفاً دوباره از ربات وارد شوید.']); exit; }

        // اگر از طریق initData (نه توکنِ ما) وارد شده، یک توکنِ ما هم برایش می‌سازیم تا لینک‌های
        // داخلیِ اپ (رفتن به ثبت‌درخواست، بازدید سلامت و ...) هم کار کنند
        $ourToken = $token;
        if (!$ourToken) $ourToken = get_or_create_main_app_token($pdo, $person['id']);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM policy_cases WHERE person_id = ?");
        $stmt->execute([$person['id']]);
        $policiesCount = intval($stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM person_vehicles WHERE person_id = ?");
        $stmt->execute([$person['id']]);
        $vehiclesCount = intval($stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM policy_cases WHERE person_id = ? AND insurance_type = 'BODY' AND status IN ('DOCS_REVIEW','ISSUING','ISSUED')");
        $stmt->execute([$person['id']]);
        $healthEligibleCount = intval($stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM app_notifications WHERE person_id = ? AND is_read = 0");
        $stmt->execute([$person['id']]);
        $unreadCount = intval($stmt->fetchColumn());

        // آیا آخرین پیام تیکت پشتیبانیِ باز، از طرف کارشناس بوده (یعنی کاربر هنوز ندیده)؟
        $hasUnreadSupportReply = false;
        $stmt = $pdo->prepare("SELECT id FROM tickets WHERE person_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person['id']]);
        $lastTicketId = $stmt->fetchColumn();
        if ($lastTicketId) {
            $stmt = $pdo->prepare("SELECT sender_type FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$lastTicketId]);
            $hasUnreadSupportReply = ($stmt->fetchColumn() === 'ADMIN');
        }

        echo json_encode([
            'ok' => true,
            'resolved_token' => $ourToken,
            'person_name' => $person['full_name'],
            'national_code' => $person['national_code'],
            'policies_count' => $policiesCount,
            'vehicles_count' => $vehiclesCount,
            'health_eligible_count' => $healthEligibleCount,
            'unread_notifications' => $unreadCount,
            'has_unread_support_reply' => $hasUnreadSupportReply,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    if ($action === 'notifications') {
        $token = $_GET['token'] ?? '';
        $person = resolve_main_session($pdo, $token);
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM app_notifications WHERE person_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$person['id']]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'mark_notifications_read') {
        $person = resolve_main_session($pdo, $jsonBody['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $pdo->prepare("UPDATE app_notifications SET is_read = 1 WHERE person_id = ?")->execute([$person['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // =====================================================================
    if ($action === 'my_policies') {
        $token = $_GET['token'] ?? '';
        $person = resolve_main_session($pdo, $token);
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE person_id = ? ORDER BY created_at DESC");
        $stmt->execute([$person['id']]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // اقساط یک بیمه‌نامه‌ی صادرشده، برای نمایش به خودِ کاربر
    if ($action === 'my_installments') {
        $person = resolve_main_session($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $caseId = intval($_GET['case_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT id, plate, insurance_type, total_premium FROM policy_cases WHERE id = ? AND person_id = ?");
        $stmt->execute([$caseId, $person['id']]);
        $case = $stmt->fetch();
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'بیمه‌نامه یافت نشد.']); exit; }

        require_once __DIR__ . '/finance_core.php';
        $rows = fin_case_installments($pdo, $caseId);
        $list = array_map(fn($r) => [
            'inst_number' => $r['inst_number'],
            'amount'      => intval($r['amount']),
            'paid'        => $r['paid'],
            'remaining'   => $r['remaining'],
            'due_jalali'  => $r['due_jalali'],
            'status'      => $r['pay_status'],
        ], $rows);

        echo json_encode([
            'ok' => true, 'plate' => $case['plate'], 'insurance_type' => $case['insurance_type'],
            'total_premium' => intval($case['total_premium']), 'data' => $list,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'download_policy') {
        $token = $_GET['token'] ?? '';
        $person = resolve_main_session($pdo, $token);
        if (!$person) { http_response_code(403); exit; }
        $caseId = intval($_GET['case_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT issued_file_path FROM policy_cases WHERE id = ? AND person_id = ? AND status = 'ISSUED'");
        $stmt->execute([$caseId, $person['id']]);
        $path = $stmt->fetchColumn();
        if (!$path) { http_response_code(404); exit; }
        $abs = dirname(__DIR__) . '/' . $path;
        if (!file_exists($abs)) { http_response_code(404); exit; }

        // نکته‌ی مهم: نام فایل‌ها فارسی است و شامل کاراکترهای ویژه (مثل ∕ و LRM). اگر همان را
        // مستقیم در هدر بگذاریم، بعضی مرورگرها هدر را نامعتبر می‌بینند و دانلود انجام نمی‌شود.
        // پس یک نام ساده‌ی لاتین به‌عنوان fallback می‌دهیم و نام واقعی را با filename* (RFC 5987).
        $realName = basename($abs);
        $ext = pathinfo($abs, PATHINFO_EXTENSION) ?: 'pdf';
        $asciiName = 'policy-' . $caseId . '.' . $ext;

        // پاک‌کردن هر خروجیِ احتمالیِ قبلی (هدر JSON بالای فایل) تا فایل سالم برسد
        while (ob_get_level() > 0) { ob_end_clean(); }
        header_remove('Content-Type');

        $mime = strtolower($ext) === 'pdf' ? 'application/pdf' : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header("Content-Disposition: attachment; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($realName));
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
        exit;
    }

    // =====================================================================
    if ($action === 'my_vehicles') {
        $token = $_GET['token'] ?? '';
        $person = resolve_main_session($pdo, $token);
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM person_vehicles WHERE person_id = ? ORDER BY created_at DESC");
        $stmt->execute([$person['id']]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'add_vehicle') {
        $data = $jsonBody;
        $person = resolve_main_session($pdo, $data['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $region = preg_replace('/\D/', '', $data['region'] ?? '');
        $suffix = preg_replace('/\D/', '', $data['suffix'] ?? '');
        $prefix = preg_replace('/\D/', '', $data['prefix'] ?? '');
        $letter = trim($data['letter'] ?? '');
        if (strlen($region) !== 2 || strlen($suffix) !== 3 || strlen($prefix) !== 2 || $letter === '') {
            echo json_encode(['ok' => false, 'error' => 'پلاک وارد‌شده کامل نیست.']); exit;
        }
        $plate = "{$region}ایران - {$suffix} {$letter} {$prefix}";

        $stmt = $pdo->prepare("SELECT id FROM person_vehicles WHERE person_id = ? AND plate = ?");
        $stmt->execute([$person['id'], $plate]);
        if (!$stmt->fetchColumn()) {
            $pdo->prepare("INSERT INTO person_vehicles (person_id, plate) VALUES (?, ?)")->execute([$person['id'], $plate]);
        }
        echo json_encode(['ok' => true, 'plate' => $plate]);
        exit;
    }

    if ($action === 'delete_vehicle') {
        $data = $jsonBody;
        $person = resolve_main_session($pdo, $data['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $vehicleId = intval($data['vehicle_id'] ?? 0);
        $pdo->prepare("DELETE FROM person_vehicles WHERE id = ? AND person_id = ?")->execute([$vehicleId, $person['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // =====================================================================
    //  بازدید سلامت: لیست پرونده‌های واجدشرایط + ساخت توکن ورود برای هرکدام
    // =====================================================================
    if ($action === 'health_case_list') {
        $token = $_GET['token'] ?? '';
        $person = resolve_main_session($pdo, $token);
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $stmt = $pdo->prepare("SELECT id, unique_code, plate FROM policy_cases WHERE person_id = ? AND insurance_type = 'BODY' AND status IN ('DOCS_REVIEW','ISSUING','ISSUED') ORDER BY created_at DESC");
        $stmt->execute([$person['id']]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'start_health_session') {
        $data = $jsonBody;
        $person = resolve_main_session($pdo, $data['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $mode = ($data['mode'] ?? 'case') === 'free' ? 'free' : 'case';
        $caseId = $mode === 'case' ? intval($data['case_id'] ?? 0) : null;
        $freePlate = $mode === 'free' ? trim($data['plate'] ?? '') : null;

        if ($mode === 'case' && $caseId) {
            $stmt = $pdo->prepare("SELECT id FROM policy_cases WHERE id = ? AND person_id = ?");
            $stmt->execute([$caseId, $person['id']]);
            if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        }
        if ($mode === 'free' && !$freePlate) { echo json_encode(['ok' => false, 'error' => 'پلاک را وارد کنید.']); exit; }

        $healthToken = create_webapp_session($pdo, $person['id'], $caseId, $mode, $freePlate);
        echo json_encode(['ok' => true, 'health_token' => $healthToken]);
        exit;
    }

    // =====================================================================
    //  ارتباط با کارشناس (تیکت پشتیبانی)
    // =====================================================================
    if ($action === 'support_messages') {
        $token = $_GET['token'] ?? '';
        $person = resolve_main_session($pdo, $token);
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $stmt = $pdo->prepare("SELECT id, admin_typing_at FROM tickets WHERE person_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person['id']]);
        $ticket = $stmt->fetch();
        if (!$ticket) { echo json_encode(['ok' => true, 'ticket_id' => null, 'data' => [], 'admin_typing' => false]); exit; }
        $ticketId = $ticket['id'];

        $stmt = $pdo->prepare("SELECT id, sender_type, message AS message_text, file_path, is_read, created_at FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC");
        $stmt->execute([$ticketId]);
        $rows = $stmt->fetchAll();

        // حالا که کاربر پیام‌ها را می‌بیند، پیام‌های کارشناس را «خوانده‌شده» علامت بزن تا در پنل هم مشخص شود
        $pdo->prepare("UPDATE ticket_messages SET is_read = 1 WHERE ticket_id = ? AND sender_type = 'ADMIN'")->execute([$ticketId]);

        // آیا کارشناس همین چند ثانیه‌ی اخیر در حال تایپ بوده؟ (برای نشان‌دادن «در حال نوشتن...»)
        $adminTyping = $ticket['admin_typing_at'] && (time() - strtotime($ticket['admin_typing_at'])) < 6;

        echo json_encode(['ok' => true, 'ticket_id' => $ticketId, 'data' => $rows, 'admin_typing' => $adminTyping], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // مشتری در حال تایپ است - برای نمایش «در حال نوشتن...» سمت پنل
    if ($action === 'support_typing') {
        $token = $_GET['token'] ?? ($jsonBody['token'] ?? '');
        $person = resolve_main_session($pdo, $token);
        if (!$person) { echo json_encode(['ok' => false]); exit; }
        $stmt = $pdo->prepare("SELECT id FROM tickets WHERE person_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person['id']]);
        $ticketId = $stmt->fetchColumn();
        if ($ticketId) $pdo->prepare("UPDATE tickets SET customer_typing_at = NOW() WHERE id = ?")->execute([$ticketId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'support_send') {
        $data = $jsonBody;
        $person = resolve_main_session($pdo, $data['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $text = trim($data['message'] ?? '');
        if ($text === '') { echo json_encode(['ok' => false, 'error' => 'متن پیام خالی است.']); exit; }

        $stmt = $pdo->prepare("SELECT id FROM tickets WHERE person_id = ? AND status = 'OPEN' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person['id']]);
        $ticketId = $stmt->fetchColumn();
        if (!$ticketId) {
            $stmt = $pdo->prepare("INSERT INTO tickets (chat_id, person_id, sender_name, last_message_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$person['bale_chat_id'], $person['id'], $person['full_name']]);
            $ticketId = $pdo->lastInsertId();
            notify_admin($pdo, "💬 تیکت پشتیبانی جدید از طرف {$person['full_name']} (از طریق اپ).");
        }

        // پیام‌های مشتری به‌طور پیش‌فرض «خوانده‌نشده» ثبت می‌شوند تا در پنل مدیریت مشخص باشد کدام پیام‌ها هنوز دیده نشده‌اند
        $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message, is_read) VALUES (?, 'CUSTOMER', ?, 0)");
        $stmt->execute([$ticketId, $text]);
        $pdo->prepare("UPDATE tickets SET last_message_at = NOW(), customer_typing_at = NULL WHERE id = ?")->execute([$ticketId]);

        // اگر ادمین آیدی عددی ثبت کرده، برایش هم اطلاع می‌رود
        notify_admin($pdo, "💬 پیام جدید از {$person['full_name']}:\n{$text}");

        echo json_encode(['ok' => true, 'ticket_id' => $ticketId]);
        exit;
    }

    // ارسال عکس/فایل از طرف مشتری در چت پشتیبانی
    if ($action === 'support_send_file') {
        $person = resolve_main_session($pdo, $_POST['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
            echo json_encode(['ok' => false, 'error' => 'فایلی دریافت نشد.']); exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM tickets WHERE person_id = ? AND status = 'OPEN' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$person['id']]);
        $ticketId = $stmt->fetchColumn();
        if (!$ticketId) {
            $stmt = $pdo->prepare("INSERT INTO tickets (chat_id, person_id, sender_name, last_message_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$person['bale_chat_id'], $person['id'], $person['full_name']]);
            $ticketId = $pdo->lastInsertId();
        }

        $siteRoot = dirname(__DIR__);
        $destDir = $siteRoot . '/موقت/موقت بایگانی/support/' . $ticketId;
        if (!is_dir($destDir) && !@mkdir($destDir, 0777, true) && !is_dir($destDir)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در آماده‌سازی پوشه‌ی ذخیره‌سازی.']); exit;
        }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        $destPath = unique_dest_path($destDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext);
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
            echo json_encode(['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل.']); exit;
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) compress_image_if_needed($destPath);
        $relPath = ltrim(str_replace($siteRoot, '', $destPath), '/');

        $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message, file_path, is_read) VALUES (?, 'CUSTOMER', '', ?, 0)");
        $stmt->execute([$ticketId, $relPath]);
        $pdo->prepare("UPDATE tickets SET last_message_at = NOW(), customer_typing_at = NULL WHERE id = ?")->execute([$ticketId]);
        notify_admin($pdo, "📎 فایل جدید از {$person['full_name']} در چت پشتیبانی ارسال شد.");

        echo json_encode(['ok' => true, 'ticket_id' => $ticketId]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
