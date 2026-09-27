<?php
// فایل: api/notifications_feed.php
// فیدِ اعلان‌های پنلِ داخلی (مدیر، اپراتور، مالی، همکار بیمه با ما): هر چیزِ تازه‌ای که
// از آخرین بررسی تا حالا اتفاق افتاده.
//
// عمداً جدولِ جدیدی برای رویدادها ساخته نشده؛ همه‌چیز از روی created_at خودِ جدول‌های
// موجود خوانده می‌شود تا نه مهاجرتِ تازه‌ای لازم باشد و نه داده‌ی اضافه انباشته شود.
// کلاینت آخرین زمانِ دیده‌شده را می‌فرستد و همان را دوباره می‌گیرد.
//
// هر نقش فقط اعلان‌های بخشی را می‌گیرد که به آن دسترسی دارد:
//   مدیر کل             -> همه
//   همکار بیمه با ما    -> شرکت‌ها + پیام داخلی (نه پرونده‌ها و گفتگوهای بیمه‌ی کارکنان)
//   اپراتور / مالی      -> پرونده‌های کارکنان + گفتگوها + پیام داخلی (نه ماژول شرکت‌ها)
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$userId = intval($_SESSION['user_id']);
$role = $_SESSION['role'] ?? '';
$seesCompanies = in_array($role, ['ADMIN', 'COMPANY_LIAISON'], true);
$seesPersonnel = $role !== 'COMPANY_LIAISON';

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$since = trim($data['since'] ?? '');

// اولین بار: فقط از همین لحظه به بعد، تا انبوهی از اعلانِ قدیمی نریزد
if ($since === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
    echo json_encode(['ok' => true, 'now' => date('Y-m-d H:i:s'), 'events' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = date('Y-m-d H:i:s');
$events = [];
$add = function ($type, $title, $body, $at, $tab = null, $extra = []) use (&$events) {
    $events[] = array_merge(['type' => $type, 'title' => $title, 'body' => $body,
                             'at' => $at, 'at_jalali' => jalali_from_gregorian_ts_dotted(strtotime($at)), 'tab' => $tab], $extra);
};

try {
    if ($seesCompanies) {
        // درخواست‌های تازه‌ی شرکت‌ها
        $stmt = $pdo->prepare("SELECT cr.id, cr.created_at, c.name AS company_name FROM company_requests cr
                                JOIN companies c ON c.id = cr.company_id
                                WHERE cr.created_at > ? ORDER BY cr.created_at DESC LIMIT 20");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $r) {
            $add('company_request', 'درخواست جدید شرکتی', $r['company_name'] . ' یک درخواست تازه ثبت کرد.',
                 $r['created_at'], 'companies-requests', ['request_id' => intval($r['id'])]);
        }

        // مدارکِ تازه‌ای که شرکت‌ها فرستاده‌اند
        $stmt = $pdo->prepare("SELECT cd.id, cd.request_id, cd.orig_name, cd.doc_type, cd.uploaded_at, c.name AS company_name
                                FROM company_documents cd JOIN companies c ON c.id = cd.company_id
                                WHERE cd.uploaded_at > ? AND cd.uploaded_by IS NOT NULL
                                ORDER BY cd.uploaded_at DESC LIMIT 20");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $r) {
            $add('company_doc', 'مدرک جدید از شرکت',
                 $r['company_name'] . ' یک «' . company_doc_type_label($r['doc_type']) . '» فرستاد.',
                 $r['uploaded_at'], 'companies-inbox', ['request_id' => intval($r['request_id'])]);
        }

        // پیام‌های تازه‌ی شرکت‌ها در چت
        $stmt = $pdo->prepare("SELECT ccm.id, ccm.company_id, ccm.message, ccm.created_at, c.name AS company_name
                                FROM company_chat_messages ccm JOIN companies c ON c.id = ccm.company_id
                                WHERE ccm.created_at > ? AND ccm.sender_type = 'COMPANY'
                                ORDER BY ccm.created_at DESC LIMIT 20");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $r) {
            $add('company_chat', 'پیام جدید از ' . $r['company_name'],
                 mb_substr((string)($r['message'] ?: 'یک فایل فرستاد'), 0, 90), $r['created_at'], 'companies-requests',
                 ['company_id' => intval($r['company_id'])]);
        }
    }
} catch (Throwable $e) { error_log('[notifications_feed companies] ' . $e->getMessage()); }

if ($seesPersonnel) {
    // پرونده‌های تازه‌ی بیمه‌ی کارکنان
    try {
        $stmt = $pdo->prepare("SELECT pc.id, pc.plate, pc.insurance_type, pc.created_at, p.full_name
                                FROM policy_cases pc LEFT JOIN persons p ON p.id = pc.person_id
                                WHERE pc.created_at > ? ORDER BY pc.created_at DESC LIMIT 20");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $r) {
            $add('case', 'درخواست جدید بیمه کارکنان',
                 ($r['full_name'] ?: 'کاربر') . ' - ' . insurance_type_fa($r['insurance_type']) . ' ' . ($r['plate'] ?: ''),
                 $r['created_at'], 'records', ['case_id' => intval($r['id'])]);
        }
    } catch (Throwable $e) { /* اگر ستونی نبود، بقیه‌ی اعلان‌ها نباید بخوابند */ }

    // پیام‌های تازه‌ی مشتری‌ها در گفتگوها
    try {
        $stmt = $pdo->prepare("SELECT tm.id, tm.message, tm.created_at FROM ticket_messages tm
                                WHERE tm.created_at > ? AND tm.sender_type <> 'ADMIN' ORDER BY tm.created_at DESC LIMIT 10");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $r) {
            $add('ticket', 'پیام جدید در گفتگوها', mb_substr((string)($r['message'] ?: 'پیام تازه'), 0, 90), $r['created_at'], 'tickets');
        }
    } catch (Throwable $e) { /* ... */ }
}

// پیام‌های داخلیِ تازه برای خودم (همه‌ی نقش‌ها). تبِ پیام داخلی فقط برای غیرِ همکار هست.
try {
    $stmt = $pdo->prepare("SELECT scm.id, scm.message, scm.created_at, u.full_name
                            FROM staff_chat_messages scm LEFT JOIN users u ON u.id = scm.from_user_id
                            WHERE scm.created_at > ? AND scm.to_user_id = ? ORDER BY scm.created_at DESC LIMIT 20");
    $stmt->execute([$since, $userId]);
    foreach ($stmt->fetchAll() as $r) {
        $add('staff_chat', 'پیام داخلی از ' . ($r['full_name'] ?: 'همکار'),
             mb_substr((string)($r['message'] ?: 'یک فایل فرستاد'), 0, 90), $r['created_at'], $seesPersonnel ? 'tickets' : null);
    }
} catch (Throwable $e) { /* ... */ }

usort($events, fn($a, $b) => strcmp($a['at'], $b['at']));
echo json_encode(['ok' => true, 'now' => $now, 'events' => array_slice($events, -25)], JSON_UNESCAPED_UNICODE);
