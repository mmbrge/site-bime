<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? ($_GET['action'] ?? '');
$userId = $_SESSION['user_id'];

try {
    // ---- ۱. آمار داشبورد ----
    if ($action === 'stats') {
        $total = $pdo->query("SELECT COUNT(*) FROM insurance_requests WHERE status != 'DELETED'")->fetchColumn();
        $new = $pdo->query("SELECT COUNT(*) FROM insurance_requests WHERE status = 'NEW'")->fetchColumn();
        $pending = $pdo->query("SELECT COUNT(*) FROM insurance_requests WHERE status IN ('WAITING_FOR_DOCUMENTS', 'DOCUMENTS_REVIEW', 'در انتظار انتخاب')")->fetchColumn();
        $issued = $pdo->query("SELECT COUNT(*) FROM insurance_requests WHERE status = 'ISSUED'")->fetchColumn();
        echo json_encode(['ok' => true, 'stats' => ['total' => intval($total), 'new' => intval($new), 'pending' => intval($pending), 'issued' => intval($issued)]]);
        exit;
    }

    // ---- ۲. حذف پرونده ----
    if ($action === 'delete') {
        $id = intval($data['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE insurance_requests SET status = 'CANCELLED' WHERE id = ?"); // در دیتابیس شما وضعیت لغو شده CANCELLED است
        $stmt->execute([$id]);

        try {
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'DELETE', 'insurance_requests', ?, 'CANCELLED')");
            $stmtLog->execute([$userId, $id]);
        } catch (Exception $e) {}

        echo json_encode(['ok' => true, 'message' => 'پرونده با موفقیت حذف (لغو) شد.']);
        exit;
    }

    // ---- ۳. ویرایش کامل پرونده ----
    if ($action === 'edit') {
        $id = intval($data['id'] ?? 0);
        $fullName = trim($data['full_name'] ?? '');
        $nationalCode = trim($data['national_code'] ?? '');
        $personnelCode = trim($data['personnel_code'] ?? '');
        $insuranceType = trim($data['insurance_type'] ?? 'در انتظار انتخاب');
        $status = trim($data['status'] ?? 'NEW'); 

        if (!$id || empty($fullName) || empty($nationalCode)) {
            echo json_encode(['ok' => false, 'error' => 'اطلاعات ضروری ناقص است.']);
            exit;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT person_id FROM insurance_requests WHERE id = ?");
        $stmt->execute([$id]);
        $personId = $stmt->fetchColumn();

        if ($personId) {
            $stmtPerson = $pdo->prepare("UPDATE persons SET full_name = ?, national_code = ?, personnel_code = ? WHERE id = ?");
            $stmtPerson->execute([$fullName, $nationalCode, $personnelCode, $personId]);
        }

        $stmtReq = $pdo->prepare("UPDATE insurance_requests SET insurance_type = ?, status = ? WHERE id = ?");
        $stmtReq->execute([$insuranceType, $status, $id]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'اطلاعات با موفقیت بروزرسانی شد.']);
        exit;
    }

    // ---- ۴. تغییر وضعیت سریع (منوی راست‌کلیک) ----
    if ($action === 'change_status') {
        $id = intval($data['id'] ?? 0);
        $status = trim($data['status'] ?? '');
        
        if (!$id || empty($status)) {
            echo json_encode(['ok' => false, 'error' => 'داده‌های ارسالی ناقص است.']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE insurance_requests SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        
        echo json_encode(['ok' => true, 'message' => 'وضعیت با موفقیت تغییر کرد.']);
        exit;
    }

    // ---- ۵. ثبت دستی پرونده جدید ----
    if ($action === 'create_manual') {
        $full_name = trim($data['full_name'] ?? '');
        $national_code = trim($data['national_code'] ?? '');
        $personnel_code = trim($data['personnel_code'] ?? '');
        $company_name = trim($data['company_name'] ?? 'ماموت');
        $insurance_type = trim($data['insurance_type'] ?? 'ثالث');
        
        if (empty($full_name) || empty($national_code)) {
            echo json_encode(['ok' => false, 'error' => 'نام و کد ملی الزامی است.']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        // ثبت شرکت
        $company_id = null;
        if (!empty($company_name)) {
            $stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
            $stmt->execute([$company_name]);
            $company_id = $stmt->fetchColumn();
            if (!$company_id) {
                $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
                $stmt->execute([$company_name]);
                $company_id = $pdo->lastInsertId();
            }
        }
        
        // ثبت یا آپدیت پرسنل
        $stmt = $pdo->prepare("SELECT id FROM persons WHERE national_code = ?");
        $stmt->execute([$national_code]);
        $person_id = $stmt->fetchColumn();
        
        if (!$person_id) {
            $stmt = $pdo->prepare("INSERT INTO persons (national_code, personnel_code, full_name, company_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$national_code, $personnel_code, $full_name, $company_id]);
            $person_id = $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare("UPDATE persons SET full_name = ?, personnel_code = ?, company_id = ? WHERE id = ?");
            $stmt->execute([$full_name, $personnel_code, $company_id, $person_id]);
        }
        
        // ثبت معرفی‌نامه با دیتای پیش‌فرض دستی
        $stmt = $pdo->prepare("INSERT INTO introductions (person_id, file_path) VALUES (?, 'ثبت_دستی')");
        $stmt->execute([$person_id]);
        $intro_id = $pdo->lastInsertId();
        
        // تنظیم Enum دیتابیس
        $enum_type = 'THIRDPARTY';
        if ($insurance_type == 'بدنه') $enum_type = 'BODY';
        if ($insurance_type == 'الحاقیه') $enum_type = 'ENDORSEMENT';
        
        // ثبت درخواست نهایی
        $stmt = $pdo->prepare("INSERT INTO insurance_requests (person_id, introduction_id, insurance_type, status) VALUES (?, ?, ?, 'NEW')");
        $stmt->execute([$person_id, $intro_id, $enum_type]);
        
        $pdo->commit();
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- ۶. پاکسازی کل سیستم ----
    if ($action === 'reset_all') {
        if ($_SESSION['role'] !== 'ADMIN') {
            echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل دسترسی دارد.']);
            exit;
        }
        $password = trim($data['password'] ?? '');
        if ($password !== '9876') {
            echo json_encode(['ok' => false, 'error' => 'رمز عبور نادرست است.']);
            exit;
        }

        $pdo->beginTransaction();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        // همه‌ی جدول‌های داده‌ای (کاربران پنل و تنظیمات سیستم مثل توکن بات دست‌نخورده می‌مانند)
        foreach ([
            'messages', 'ticket_messages', 'tickets', 'documents', 'case_documents',
            'health_inspections', 'health_attempts', 'policy_cases', 'insurance_requests',
            'introductions', 'person_vehicles', 'bot_sessions', 'persons', 'companies',
            'processing_queue', 'audit_logs', 'app_notifications', 'webapp_sessions',
        ] as $table) {
            try { $pdo->exec("TRUNCATE TABLE `$table`;"); } catch (Exception $e) { /* اگر جدولی وجود نداشت، رد شو */ }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        $pdo->commit();

        // پاکسازی کامل فایل‌ها: بایگانی، پرونده‌های موقت، صف انتظار و لاگ‌ها
        function rrmdir_contents($dir) {
            if (!is_dir($dir)) return;
            foreach (scandir($dir) as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $dir . '/' . $item;
                if (is_dir($path)) { rrmdir_contents($path); @rmdir($path); }
                else { @unlink($path); }
            }
        }
        $siteRoot = dirname(__DIR__);
        foreach (['/Archive/بایگانی/بایگانی کسر از حقوق', '/Archive/بایگانی/بایگانی صادره', '/Archive/بایگانی/سایر مدارک',
                  '/موقت/موقت بایگانی', '/queue/pending', '/queue/case_uploads', '/queue/attachments'] as $rel) {
            rrmdir_contents($siteRoot . $rel);
        }
        @file_put_contents($siteRoot . '/queue/bale_debug.log', '');

        echo json_encode(['ok' => true, 'message' => 'همه‌ی اطلاعات، پرونده‌ها، بایگانی و چت‌های ذخیره‌شده کاملاً پاک شدند. سایت به حالت اولیه بازگشت.']);
        exit;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس: ' . $e->getMessage()]);
}
?>