<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

// «همکار شرکت‌ها» (COMPANY_LIAISON) فقط به شرکت‌ها، بایگانی و مالی دسترسی دارد؛
// منوی این بخش برایش پنهان است و اینجا هم مستقیم جلویش گرفته می‌شود.
if (($_SESSION['role'] ?? '') === 'COMPANY_LIAISON') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? ($_GET['action'] ?? '');
// فرم‌هایی که فایل دارند (ثبت دستی با فایل معرفی‌نامه) multipart می‌آیند، نه JSON
if (!$data && !empty($_POST)) { $data = $_POST; $action = $_POST['action'] ?? $action; }
$userId = $_SESSION['user_id'];

// ثبت دستی و ویرایشِ درخواست‌ها فقط کارِ مدیر کل است؛ بقیه‌ی نقش‌ها فقط می‌بینند
if (in_array($action, ['edit', 'change_status', 'create_manual', 'create_manual_request', 'create_case_for_intro', 'intro_detect', 'create_intro'], true)
    && ($_SESSION['role'] ?? '') !== 'ADMIN') {
    echo json_encode(['ok' => false, 'error' => 'ثبت و ویرایش درخواست فقط برای مدیر کل مجاز است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ---- ثبت دستیِ معرفی‌نامه: ۱) تشخیصِ خودکار از روی فایل ----
    // فایل با همان الگوریتمِ صفِ پردازش خوانده می‌شود؛ اگر معرفی‌نامه نبود رد می‌شود، اگر بود
    // اطلاعاتش برگردانده و خودِ فایل موقتاً نگه داشته می‌شود تا با «ثبت» به بایگانی برود.
    if ($action === 'intro_detect') {
        $siteRoot = dirname(__DIR__);
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { echo json_encode(['ok' => false, 'error' => 'فایلی دریافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) { echo json_encode(['ok' => false, 'error' => 'فقط PDF یا عکس (JPG/PNG).'], JSON_UNESCAPED_UNICODE); exit; }
        if ($f['size'] > 15 * 1048576) { echo json_encode(['ok' => false, 'error' => 'حجم فایل بیشتر از ۱۵ مگابایت است.'], JSON_UNESCAPED_UNICODE); exit; }
        $dir = temp_archive_root($siteRoot) . '/معرفی‌نامه‌های دستی';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        // پاک کردنِ فایل‌های موقتِ رهاشده (بیش از یک روز)
        foreach (glob($dir . '/*') ?: [] as $old) if (is_file($old) && filemtime($old) < time() - 86400) @unlink($old);
        $token = bin2hex(random_bytes(12));
        $dest = $dir . '/' . $token . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dest)) { echo json_encode(['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.'], JSON_UNESCAPED_UNICODE); exit; }

        $ocr = run_document_ocr_verbose($dest);
        $x = $ocr['data'];
        if (!$x) {
            // خواندن ممکن نشد (مثلاً اسکنِ بی‌کیفیت یا نبودِ موتورِ OCR): فایل نگه داشته می‌شود و دستی ادامه می‌دهید
            if ($ocr['debug']) error_log('[intro_detect] ' . $ocr['debug']);
            echo json_encode(['ok' => true, 'token' => $token . '.' . $ext, 'name' => $f['name'], 'detected' => false,
                              'warning' => 'فایل پیوست شد ولی خواندنِ خودکارش ممکن نشد (کیفیتِ اسکن یا موتورِ تشخیص)؛ اطلاعات را دستی وارد کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (($x['ins_type'] ?? '') !== 'معرفی‌نامه') {
            @unlink($dest);
            $kind = ($x['ins_type'] ?? '') && $x['ins_type'] !== 'ناشناخته' ? ' (به نظر «' . $x['ins_type'] . '» است)' : '';
            echo json_encode(['ok' => false, 'not_intro' => true, 'error' => 'این فایل معرفی‌نامه نیست' . $kind . '. لطفاً فایلِ معرفی‌نامه را بارگذاری کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $fields = ['full_name' => trim($x['insured_name'] ?? ''), 'national_code' => p2e_digits(trim($x['national_id'] ?? '')),
                   'personnel_code' => p2e_digits(trim($x['personnel_id'] ?? '')), 'company_name' => trim($x['company_name'] ?? ''),
                   'letter_date' => p2e_digits(trim($x['letter_date'] ?? ''))];
        // اگر این کد ملی از قبل در سامانه هست، موبایلش را هم پیشنهاد می‌دهیم
        if (preg_match('/^\d{10}$/', $fields['national_code'])) {
            $st = $pdo->prepare("SELECT mobile_number, personnel_code FROM persons WHERE national_code = ?");
            $st->execute([$fields['national_code']]);
            if ($p = $st->fetch()) { $fields['mobile'] = (string)$p['mobile_number']; if ($fields['personnel_code'] === '') $fields['personnel_code'] = (string)$p['personnel_code']; }
        }
        echo json_encode(['ok' => true, 'token' => $token . '.' . $ext, 'name' => $f['name'], 'detected' => true, 'fields' => $fields], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ثبت دستیِ معرفی‌نامه: ۲) ثبت (فقط معرفی‌نامه؛ درخواست‌ها از «لیست صدور» ثبت می‌شوند) ----
    if ($action === 'create_intro') {
        $siteRoot = dirname(__DIR__);
        $fullName = trim($data['full_name'] ?? '');
        $nationalCode = preg_replace('/\D/', '', p2e_digits((string)($data['national_code'] ?? '')));
        $personnelCode = trim(p2e_digits($data['personnel_code'] ?? ''));
        $companyName = trim($data['company_name'] ?? '');
        $mobile = preg_replace('/\D/', '', p2e_digits($data['mobile'] ?? ''));
        $ld = p2e_digits(trim($data['letter_date'] ?? ''));
        $letterDate = preg_match('/^(1[34]\d\d)\D+(\d{1,2})\D+(\d{1,2})$/', $ld, $m) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        $quota = intval(p2e_digits($data['max_quota'] ?? 0));
        $token = basename((string)($data['file_token'] ?? ''));
        if (mb_strlen($fullName) < 3) { echo json_encode(['ok' => false, 'error' => 'نام و نام خانوادگیِ پرسنل را وارد کنید.', 'field' => 'full_name'], JSON_UNESCAPED_UNICODE); exit; }
        if (strlen($nationalCode) !== 10) { echo json_encode(['ok' => false, 'error' => 'کد ملی باید ۱۰ رقم باشد.', 'field' => 'national_code'], JSON_UNESCAPED_UNICODE); exit; }
        if ($ld !== '' && !$letterDate) { echo json_encode(['ok' => false, 'error' => 'تاریخ معرفی‌نامه را به شکل ۱۴۰۵/۰۷/۰۱ وارد کنید.', 'field' => 'letter_date'], JSON_UNESCAPED_UNICODE); exit; }
        if ($mobile !== '' && !preg_match('/^09\d{9}$/', $mobile)) { echo json_encode(['ok' => false, 'error' => 'موبایل باید ۱۱ رقم و با ۰۹ شروع شود.', 'field' => 'mobile'], JSON_UNESCAPED_UNICODE); exit; }
        $tmpFile = null;
        if ($token !== '' && preg_match('/^[a-f0-9]{24}\.(pdf|jpg|jpeg|png|webp)$/', $token)) {
            $tmpFile = temp_archive_root($siteRoot) . '/معرفی‌نامه‌های دستی/' . $token;
            if (!is_file($tmpFile)) $tmpFile = null;
        }
        if ($quota <= 0) { $q = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_intro_quota'"); $quota = intval($q->fetchColumn() ?: 4); }

        $pdo->beginTransaction();
        $companyId = null;
        if ($companyName !== '') {
            $st = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
            $st->execute([$companyName]);
            $companyId = $st->fetchColumn() ?: null;
            if (!$companyId) { $pdo->prepare("INSERT INTO companies (name) VALUES (?)")->execute([$companyName]); $companyId = $pdo->lastInsertId(); }
        }
        $st = $pdo->prepare("SELECT id FROM persons WHERE national_code = ?");
        $st->execute([$nationalCode]);
        $personId = intval($st->fetchColumn());
        if (!$personId) {
            $pdo->prepare("INSERT INTO persons (national_code, personnel_code, full_name, company_id, mobile_number) VALUES (?, ?, ?, ?, ?)")
                ->execute([$nationalCode, $personnelCode, $fullName, $companyId, $mobile ?: null]);
            $personId = intval($pdo->lastInsertId());
        } else {
            $pdo->prepare("UPDATE persons SET full_name = ?, personnel_code = COALESCE(NULLIF(?, ''), personnel_code), company_id = COALESCE(?, company_id), mobile_number = COALESCE(NULLIF(?, ''), mobile_number) WHERE id = ?")
                ->execute([$fullName, $personnelCode, $companyId, $mobile, $personId]);
        }
        $pdo->prepare("INSERT INTO introductions (person_id, file_path, letter_date, max_quota) VALUES (?, 'ثبت_دستی', ?, ?)")->execute([$personId, $letterDate, $quota]);
        $introId = intval($pdo->lastInsertId());
        $pdo->prepare("INSERT INTO insurance_requests (person_id, introduction_id, insurance_type, status) VALUES (?, ?, NULL, 'NEW')")->execute([$personId, $introId]);
        $pdo->commit();

        // پوشه‌ی «بایگانی کسر از حقوق» + فایلِ معرفی‌نامه (همان فایلی که بالا بارگذاری و تشخیص داده شد)
        $introFolder = sync_intro_folder($pdo, $siteRoot, $introId);
        $filed = false;
        if ($tmpFile && $introFolder) {
            $dir = $introFolder . '/معرفی‌نامه';
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $ext = pathinfo($tmpFile, PATHINFO_EXTENSION);
            $st = $pdo->prepare("SELECT c.name FROM companies c WHERE c.id = (SELECT company_id FROM persons WHERE id = ?)");
            $st->execute([$personId]);
            $dest = unique_dest_path($dir . '/' . build_intro_folder_name($fullName, $nationalCode, $personnelCode, $st->fetchColumn() ?: $companyName) . '.' . $ext);
            if (@rename($tmpFile, $dest)) {
                $pdo->prepare("UPDATE introductions SET file_path = ? WHERE id = ?")->execute([ltrim(str_replace($siteRoot, '', $dest), '/'), $introId]);
                $filed = true;
            }
        }
        echo json_encode(['ok' => true, 'intro_id' => $introId, 'filed' => $filed], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ۱. آمار داشبورد ----
    if ($action === 'stats') {
        $total = $pdo->query("SELECT COUNT(*) FROM insurance_requests WHERE status != 'DELETED'")->fetchColumn();
        $new = $pdo->query("SELECT COUNT(*) FROM insurance_requests WHERE status = 'NEW'")->fetchColumn();
        $pending = $pdo->query("SELECT COUNT(*) FROM insurance_requests WHERE status IN ('WAITING_FOR_DOCUMENTS', 'DOCUMENTS_REVIEW', 'در انتظار انتخاب')")->fetchColumn();
        $issued = $pdo->query("SELECT COUNT(*) FROM insurance_requests WHERE status = 'ISSUED'")->fetchColumn();
        echo json_encode(['ok' => true, 'stats' => ['total' => intval($total), 'new' => intval($new), 'pending' => intval($pending), 'issued' => intval($issued)]]);
        exit;
    }

    // ---- ۲. حذفِ کاملِ یک معرفی‌نامه (با همه‌ی درخواست‌هایش) - فقط مدیر کل ----
    // قبلاً فقط وضعیت را CANCELLED می‌کرد ولی فهرست اصلاً وضعیت را فیلتر نمی‌کرد، پس
    // «حذف» هیچ اثری نداشت. حالا واقعاً حذف می‌شود؛ اگر یکی از درخواست‌هایش صادر شده
    // باشد، هیچ‌چیز حذف نمی‌شود و پیام می‌دهد.
    if ($action === 'delete') {
        if (($_SESSION['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل می‌تواند حذف کند.'], JSON_UNESCAPED_UNICODE); exit; }
        $id = intval($data['id'] ?? 0);   // شناسه‌ی insurance_requests
        $stmt = $pdo->prepare("SELECT introduction_id FROM insurance_requests WHERE id = ?");
        $stmt->execute([$id]);
        $introId = intval($stmt->fetchColumn());
        if (!$introId) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.'], JSON_UNESCAPED_UNICODE); exit; }

        $stmt = $pdo->prepare("SELECT id, status, is_invoiced FROM policy_cases WHERE introduction_id = ?");
        $stmt->execute([$introId]);
        $cases = $stmt->fetchAll();
        foreach ($cases as $c) {
            if ($c['status'] === 'ISSUED' || !empty($c['is_invoiced'])) {
                echo json_encode(['ok' => false, 'error' => 'این معرفی‌نامه بیمه‌نامه‌ی صادرشده دارد و حذف نمی‌شود. فقط درخواست‌های صادرنشده را تک‌تک حذف کنید.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        // درخواستی که مدرکِ تاییدشده دارد حذف نمی‌شود و فقط از فاز عملیاتی خارج می‌شود؛
        // در این صورت خودِ معرفی‌نامه هم برای سابقه می‌ماند (پرونده به آن وابسته است).
        $kept = 0;
        foreach ($cases as $c) {
            if ($c['status'] === 'WITHDRAWN') { $kept++; continue; }
            $r = delete_policy_case($pdo, dirname(__DIR__), intval($c['id']), $userId);
            if (!$r['ok']) { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }
            if (($r['mode'] ?? '') === 'withdrawn') $kept++;
        }
        if ($kept > 0) {
            $done = count($cases) - $kept;
            echo json_encode(['ok' => true, 'mode' => 'withdrawn', 'message' =>
                fa_digits($kept) . ' درخواستِ این معرفی‌نامه مدرکِ تاییدشده داشت و فقط از فاز عملیاتی خارج شد' .
                ($done ? '، ' . fa_digits($done) . ' درخواستِ دیگر کامل حذف شد' : '') .
                '. خودِ معرفی‌نامه برای سابقه باقی ماند.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->prepare("DELETE FROM insurance_requests WHERE introduction_id = ?")->execute([$introId]);
        $pdo->prepare("DELETE FROM introductions WHERE id = ?")->execute([$introId]);
        try {
            $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'DELETE', 'introductions', ?, 'DELETED')")
                ->execute([$userId, $introId]);
        } catch (Exception $e) {}
        echo json_encode(['ok' => true, 'message' => 'معرفی‌نامه و درخواست‌هایش حذف شد.'], JSON_UNESCAPED_UNICODE);
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

    // ---- ۵. ثبت دستی معرفی‌نامه‌ی جدید (نوع بیمه اینجا مشخص نمی‌شود - آن مالِ هر
    //      پرونده/درخواست جداگانه است، از طریق create_case_for_intro) ----
    if ($action === 'create_manual') {
        $full_name = trim($data['full_name'] ?? '');
        $national_code = trim($data['national_code'] ?? '');
        $personnel_code = trim($data['personnel_code'] ?? '');
        $company_name = trim($data['company_name'] ?? 'ماموت');

        if (empty($full_name) || empty($national_code)) {
            echo json_encode(['ok' => false, 'error' => 'نام و کد ملی الزامی است.']);
            exit;
        }

        // اگر قبلاً برای همین کد ملی پرونده‌ای ثبت شده، دوباره از صفر معرفی‌نامه نسازیم
        // (احتمالاً اشتباه اپراتور است - معرفی‌نامه‌ی موجود را باز کند و پرونده اضافه کند)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM policy_cases pc
            JOIN persons p ON pc.person_id = p.id
            WHERE p.national_code = ?
        ");
        $stmt->execute([$national_code]);
        if (intval($stmt->fetchColumn()) > 0) {
            echo json_encode(['ok' => false, 'error' => 'قبلاً برای این کد ملی پرونده ثبت شده است. از داخل معرفی‌نامه‌ی همین شخص، پرونده‌ی جدید اضافه کنید.']);
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

        // ثبت درخواست نهایی (برای کارتابل داخلی پنل) - نوع هنوز نامشخص است، بعداً برای
        // هر پرونده‌ی جداگانه مشخص می‌شود
        $stmt = $pdo->prepare("INSERT INTO insurance_requests (person_id, introduction_id, insurance_type, status) VALUES (?, ?, NULL, 'NEW')");
        $stmt->execute([$person_id, $intro_id]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'intro_id' => $intro_id, 'person_id' => $person_id]);
        exit;
    }

    // ---- ۵الف. ثبت دستیِ کاملِ یک درخواست در یک مرحله ----
    // همان لحظه‌ی ثبت، اطلاعات درخواست هم گرفته می‌شود (مثل فرم درخواست شرکت‌ها) و پرونده
    // با همه‌ی فیلدهایش ساخته می‌شود؛ بعد در همان پنجره چک‌لیستِ مدارکِ لازم نشان داده
    // می‌شود تا مدارک تک‌تک بارگذاری شوند (نام‌گذاری و بایگانی مثل مسیر عادی انجام می‌شود).
    // اگر intro_id داده شود، درخواست به همان معرفی‌نامه اضافه می‌شود (بخش پرسنل لازم نیست).
    if ($action === 'create_manual_request') {
        $siteRoot = dirname(__DIR__);
        $introId = intval($data['intro_id'] ?? 0);
        $insuranceType = in_array($data['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $data['insurance_type'] : null;
        $relationship = trim($data['relationship'] ?? 'خودم');
        $plate = trim(p2e_digits($data['plate'] ?? ''));
        $ownership = ($data['ownership_choice'] ?? '') === 'سند' ? 'سند' : 'کارت ماشین';
        $nid10 = function ($v) { $d = preg_replace('/\D/', '', p2e_digits((string)$v)); return $d === '' ? '' : str_pad($d, 10, '0', STR_PAD_LEFT); };

        if (!$insuranceType) { echo json_encode(['ok' => false, 'error' => 'نوع بیمه (ثالث یا بدنه) را انتخاب کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        if (!in_array($relationship, relationship_options(), true)) { echo json_encode(['ok' => false, 'error' => 'نسبت بیمه‌گذار نامعتبر است.'], JSON_UNESCAPED_UNICODE); exit; }
        if (!preg_match('/^\d{2}ایران - \d{3} \S+ \d{2}$/u', $plate)) { echo json_encode(['ok' => false, 'error' => 'پلاک را کامل وارد کنید (همه‌ی خانه‌ها).'], JSON_UNESCAPED_UNICODE); exit; }

        $pdo->beginTransaction();
        try {
            $note = null;
            if ($introId) {
                $stmt = $pdo->prepare("SELECT i.*, p.full_name, p.national_code FROM introductions i JOIN persons p ON p.id = i.person_id WHERE i.id = ?");
                $stmt->execute([$introId]);
                $intro = $stmt->fetch();
                if (!$intro) throw new RuntimeException('معرفی‌نامه یافت نشد.');
                $personId = intval($intro['person_id']);
                $holderName = $intro['full_name']; $holderNid = $intro['national_code'];
            } else {
                $fullName = trim($data['full_name'] ?? '');
                $nationalCode = $nid10($data['national_code'] ?? '');
                $personnelCode = trim(p2e_digits($data['personnel_code'] ?? ''));
                $companyName = trim($data['company_name'] ?? '');
                $mobile = preg_replace('/\D/', '', p2e_digits($data['mobile'] ?? ''));
                if (mb_strlen($fullName) < 3 || strlen($nationalCode) !== 10) throw new RuntimeException('نام و کد ملیِ ۱۰ رقمیِ پرسنل الزامی است.');

                $companyId = null;
                if ($companyName !== '') {
                    $stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
                    $stmt->execute([$companyName]);
                    $companyId = $stmt->fetchColumn() ?: null;
                    if (!$companyId) { $pdo->prepare("INSERT INTO companies (name) VALUES (?)")->execute([$companyName]); $companyId = $pdo->lastInsertId(); }
                }
                $stmt = $pdo->prepare("SELECT id FROM persons WHERE national_code = ?");
                $stmt->execute([$nationalCode]);
                $personId = intval($stmt->fetchColumn());
                if (!$personId) {
                    $pdo->prepare("INSERT INTO persons (national_code, personnel_code, full_name, company_id, mobile_number) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$nationalCode, $personnelCode, $fullName, $companyId, $mobile ?: null]);
                    $personId = intval($pdo->lastInsertId());
                } else {
                    $pdo->prepare("UPDATE persons SET full_name = ?, personnel_code = COALESCE(NULLIF(?, ''), personnel_code), company_id = COALESCE(?, company_id), mobile_number = COALESCE(NULLIF(?, ''), mobile_number) WHERE id = ?")
                        ->execute([$fullName, $personnelCode, $companyId, $mobile, $personId]);
                }
                $holderName = $fullName; $holderNid = $nationalCode;

                // اگر همین شخص معرفی‌نامه‌ای با سهمیه‌ی خالی دارد، درخواست به همان اضافه می‌شود
                $stmt = $pdo->prepare("SELECT * FROM introductions WHERE person_id = ? AND COALESCE(used_quota, 0) < COALESCE(max_quota, 4) ORDER BY id DESC LIMIT 1");
                $stmt->execute([$personId]);
                $intro = $stmt->fetch();
                if ($intro) {
                    $introId = intval($intro['id']);
                    $note = 'این شخص از قبل معرفی‌نامه داشت؛ درخواست به همان معرفی‌نامه اضافه شد.';
                } else {
                    $quota = intval(p2e_digits($data['max_quota'] ?? 0));
                    if ($quota <= 0) {
                        $q = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_intro_quota'");
                        $quota = intval($q->fetchColumn() ?: 4);
                    }
                    // تاریخ معرفی‌نامه در این جدول به‌صورت شمسی نگه داشته می‌شود (مثل مسیر صف)
                    $ld = p2e_digits(trim($data['letter_date'] ?? ''));
                    $letterDate = preg_match('/^(\d{4})\D+(\d{1,2})\D+(\d{1,2})$/', $ld, $m) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
                    $pdo->prepare("INSERT INTO introductions (person_id, file_path, letter_date, max_quota) VALUES (?, 'ثبت_دستی', ?, ?)")
                        ->execute([$personId, $letterDate, $quota]);
                    $introId = intval($pdo->lastInsertId());
                    $pdo->prepare("INSERT INTO insurance_requests (person_id, introduction_id, insurance_type, status) VALUES (?, ?, NULL, 'NEW')")
                        ->execute([$personId, $introId]);
                    $intro = ['used_quota' => 0, 'max_quota' => $quota];

                    // فایل معرفی‌نامه (اختیاری) - با همان نام‌گذاری و پوشه‌ی مسیرِ عادی
                    if (!empty($_FILES['intro_file']['tmp_name']) && is_uploaded_file($_FILES['intro_file']['tmp_name'])) {
                        $introFolder = sync_intro_folder($pdo, $siteRoot, $introId);
                        $dir = $introFolder . '/معرفی‌نامه';
                        if (!is_dir($dir)) @mkdir($dir, 0777, true);
                        $ext = strtolower(pathinfo($_FILES['intro_file']['name'], PATHINFO_EXTENSION)) ?: 'pdf';
                        $dest = unique_dest_path($dir . '/' . build_intro_folder_name($fullName, $nationalCode, $personnelCode, $companyName) . '.' . $ext);
                        if (move_uploaded_file($_FILES['intro_file']['tmp_name'], $dest)) {
                            $pdo->prepare("UPDATE introductions SET file_path = ? WHERE id = ?")->execute([ltrim(str_replace($siteRoot, '', $dest), '/'), $introId]);
                        }
                    }
                }
            }
            if (intval($intro['used_quota'] ?? 0) >= intval($intro['max_quota'] ?: 4)) throw new RuntimeException('سقف صدور بیمه‌نامه با این معرفی‌نامه تکمیل شده است.');

            // بیمه‌گذار: خودِ پرسنل یا یکی از بستگان
            if ($relationship === 'خودم') { $insuredName = $holderName; $insuredNid = $holderNid; }
            else {
                $insuredName = trim($data['insured_name'] ?? '');
                $insuredNid = $nid10($data['insured_national_id'] ?? '');
                if (mb_strlen($insuredName) < 3 || strlen($insuredNid) !== 10) throw new RuntimeException('نام و کد ملیِ ۱۰ رقمیِ بیمه‌گذار (' . $relationship . ') الزامی است.');
            }
            // اطلاعاتِ بیمه‌گذار - همان قواعدِ ربات/مینی‌اپ
            $bd = trim(p2e_digits($data['insured_birth_date'] ?? ''));
            $bd = preg_match('/^(1[34]\d{2})\D+(\d{1,2})\D+(\d{1,2})$/', $bd, $m) && $m[2] >= 1 && $m[2] <= 12 && $m[3] >= 1 && $m[3] <= 31
                ? sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]) : null;
            $phone = preg_replace('/\D/', '', p2e_digits($data['insured_phone'] ?? ''));
            $postal = preg_replace('/\D/', '', p2e_digits($data['insured_postal_code'] ?? ''));
            $address = trim($data['insured_address'] ?? '');
            if (!$bd) throw new RuntimeException('تاریخ تولد بیمه‌گذار را درست وارد کنید (مثل ۱۳۷۰/۰۵/۱۲).');
            if (!preg_match('/^09\d{9}$/', $phone)) throw new RuntimeException('موبایل بیمه‌گذار باید ۱۱ رقم و با ۰۹ شروع شود.');
            if (strlen($postal) !== 10) throw new RuntimeException('کد پستی باید دقیقاً ۱۰ رقم باشد.');
            if (mb_strlen($address) < 10) throw new RuntimeException('آدرس بیمه‌گذار را کامل‌تر وارد کنید.');

            $liability = null; $carValue = null; $prevBody = null; $prevClaim = null; $noClaimYears = null; $coverages = null;
            if ($insuranceType === 'THIRDPARTY') {
                $liability = money_to_int($data['liability_limit'] ?? '');
                if (!in_array($liability, liability_limit_options(), true)) throw new RuntimeException('سقف تعهد مالیِ بیمه‌ی ثالث را انتخاب کنید.');
            } else {
                $prevBody = ($data['prev_body_insurance'] ?? '') === 'بله' ? 'بله' : 'خیر';
                if ($prevBody === 'بله') {
                    $prevClaim = ($data['prev_body_claim'] ?? '') === 'بله' ? 'بله' : 'خیر';
                    if ($prevClaim === 'خیر') {
                        $noClaimYears = intval(p2e_digits((string)($data['no_claim_years'] ?? '')));
                        if ($noClaimYears < 0 || $noClaimYears > 50 || trim((string)($data['no_claim_years'] ?? '')) === '') throw new RuntimeException('تعداد سال‌های عدم خسارت را وارد کنید.');
                    }
                }
                $cov = json_decode((string)($data['coverages'] ?? '{}'), true);
                $opts = body_coverage_options(); $clean = [];
                foreach ((is_array($cov) ? $cov : []) as $k => $v) {
                    if (!isset($opts[$k])) continue;
                    $clean[$k] = $opts[$k]['tiers'] ? (isset($opts[$k]['tiers'][(string)$v]) ? (string)$v : array_key_first($opts[$k]['tiers'])) : true;
                }
                $coverages = json_encode((object)$clean, JSON_UNESCAPED_UNICODE);
                // ۰ یعنی «محاسبه‌ی خودکار توسط کارشناس» (مثل مینی‌اپ)
                $carValue = !empty($data['car_value_auto']) && $data['car_value_auto'] !== '0' ? 0 : money_to_int($data['car_value'] ?? '');
                if ($carValue === null || $carValue < 0 || (empty($data['car_value_auto']) && $carValue <= 0)) throw new RuntimeException('ارزش خودرو را وارد کنید یا «محاسبه توسط کارشناس» را بزنید.');
            }

            $pdo->prepare("INSERT INTO policy_cases (introduction_id, person_id, insurance_type, unique_code, status, insured_relationship, insured_name,
                                insured_national_id, plate, ownership_choice, liability_limit, estimated_car_value, prev_body_insurance,
                                prev_body_claim, no_claim_years, selected_coverages,
                                insured_birth_date, insured_phone, insured_postal_code, insured_address)
                           VALUES (?, ?, ?, '', 'AWAITING_DOCS', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$introId, $personId, $insuranceType, $relationship, $insuredName, $insuredNid, $plate, $ownership,
                           $liability, $carValue, $prevBody, $prevClaim, $noClaimYears, $coverages, $bd, $phone, $postal, $address]);
            $caseId = intval($pdo->lastInsertId());
            $uniqueCode = generate_case_unique_code($caseId);
            $pdo->prepare("UPDATE policy_cases SET unique_code = ? WHERE id = ?")->execute([$uniqueCode, $caseId]);
            $pdo->prepare("UPDATE introductions SET used_quota = COALESCE(used_quota, 0) + 1 WHERE id = ?")->execute([$introId]);
            $stmt = $pdo->prepare("SELECT id FROM person_vehicles WHERE person_id = ? AND plate = ?");
            $stmt->execute([$personId, $plate]);
            if (!$stmt->fetchColumn()) $pdo->prepare("INSERT INTO person_vehicles (person_id, plate) VALUES (?, ?)")->execute([$personId, $plate]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'خطا در ثبت درخواست.';
            if (!($e instanceof RuntimeException)) error_log('[create_manual_request] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // مدارکی که همان‌جا انتخاب شده‌اند: با همان نام‌گذاریِ مسیرِ عادی ذخیره و (چون خودمان دیده‌ایم) تایید می‌شوند
        $required = get_required_docs_v2($insuranceType, $ownership, $prevBody, $relationship);
        $docErrors = []; $uploaded = 0;
        foreach ($required as $key => $label) {
            if (empty($_FILES['doc_' . $key]['name'])) continue;
            $r = admin_store_case_doc($pdo, $siteRoot, $caseId, $key, $label, $_FILES['doc_' . $key], $userId);
            $r['ok'] ? $uploaded++ : $docErrors[] = $r['error'];
        }
        // «سایر مدارک» با نامی که کارشناس نوشته
        $otherNames = $data['other_names'] ?? [];
        if (!empty($_FILES['other_files']['name']) && is_array($_FILES['other_files']['name'])) {
            foreach ($_FILES['other_files']['name'] as $i => $nm) {
                if ($nm === '') continue;
                $label = trim((string)($otherNames[$i] ?? '')) ?: 'سایر مدارک';
                $file = ['name' => $nm, 'tmp_name' => $_FILES['other_files']['tmp_name'][$i], 'error' => $_FILES['other_files']['error'][$i]];
                $r = admin_store_case_doc($pdo, $siteRoot, $caseId, 'other', mb_substr($label, 0, 120), $file, $userId);
                $r['ok'] ? $uploaded++ : $docErrors[] = $r['error'];
            }
        }
        $st = $pdo->prepare("SELECT status FROM policy_cases WHERE id = ?");
        $st->execute([$caseId]);

        echo json_encode(['ok' => true, 'case_id' => $caseId, 'intro_id' => $introId, 'unique_code' => $uniqueCode,
                          'required_docs' => $required, 'uploaded' => $uploaded, 'doc_errors' => $docErrors,
                          'status' => $st->fetchColumn(), 'note' => $note], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // گزینه‌های فرمِ ثبتِ دستی (پوشش‌های بدنه، سقف‌های تعهد) - همان فهرستِ ربات
    if ($action === 'manual_form_options') {
        echo json_encode(['ok' => true, 'coverages' => body_coverage_options(), 'liability' => liability_limit_options(),
                          'relationships' => relationship_options()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- ۵ب. افزودن یک پرونده‌ی جدید (با نوع مشخص) به یک معرفی‌نامه‌ی موجود - هم برای
    //      معرفی‌نامه‌های ثبتِ دستی و هم برای معرفی‌نامه‌های عادی که می‌خواهیم دستی یک
    //      پرونده‌ی دیگر (مثلاً هم ثالث هم بدنه) رویشان اضافه کنیم؛ دقیقاً هم‌شکل با
    //      start_case در webapp_order.php ----
    if ($action === 'create_case_for_intro') {
        $introId = intval($data['introduction_id'] ?? 0);
        $insuranceType = in_array($data['insurance_type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $data['insurance_type'] : null;
        if (!$introId || !$insuranceType) {
            echo json_encode(['ok' => false, 'error' => 'معرفی‌نامه و نوع بیمه الزامی است.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM introductions WHERE id = ?");
        $stmt->execute([$introId]);
        $intro = $stmt->fetch();
        if (!$intro) { echo json_encode(['ok' => false, 'error' => 'معرفی‌نامه یافت نشد.']); exit; }
        if (intval($intro['used_quota']) >= intval($intro['max_quota'] ?: 4)) {
            echo json_encode(['ok' => false, 'error' => 'سقف صدور بیمه‌نامه با این معرفی‌نامه تکمیل شده است.']); exit;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO policy_cases (introduction_id, person_id, insurance_type, unique_code) VALUES (?, ?, ?, '')");
        $stmt->execute([$introId, $intro['person_id'], $insuranceType]);
        $caseId = $pdo->lastInsertId();
        $pdo->prepare("UPDATE policy_cases SET unique_code = ? WHERE id = ?")->execute([generate_case_unique_code($caseId), $caseId]);
        $pdo->prepare("UPDATE introductions SET used_quota = used_quota + 1 WHERE id = ?")->execute([$introId]);
        $pdo->commit();

        echo json_encode(['ok' => true, 'case_id' => $caseId]);
        exit;
    }

    // ---- ۶. پاکسازی کل سیستم ----
    if ($action === 'reset_all') {
        if ($_SESSION['role'] !== 'ADMIN') {
            echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل دسترسی دارد.']);
            exit;
        }
        $password = trim($data['password'] ?? '');
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $adminHash = $stmt->fetchColumn();
        if ($password === '' || !$adminHash || !password_verify($password, $adminHash)) {
            echo json_encode(['ok' => false, 'error' => 'رمز عبور نادرست است.']);
            exit;
        }

        // حالت‌ها: all = همه‌چیز؛ only = فقط بخش‌های انتخاب‌شده؛ except = همه به‌جز بخش‌های انتخاب‌شده
        require_once __DIR__ . '/_backup_core.php';
        $sections = bk_reset_sections();
        $mode = in_array($data['mode'] ?? 'all', ['all', 'only', 'except'], true) ? ($data['mode'] ?? 'all') : 'all';
        $picked = array_values(array_intersect(array_keys($sections), (array)($data['sections'] ?? [])));
        if ($mode !== 'all' && !$picked) { echo json_encode(['ok' => false, 'error' => 'حداقل یک بخش انتخاب کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        $targets = $mode === 'all' ? array_keys($sections) : ($mode === 'only' ? $picked : array_values(array_diff(array_keys($sections), $picked)));
        if (!$targets) { echo json_encode(['ok' => false, 'error' => 'همه‌ی بخش‌ها مستثنا شده‌اند؛ چیزی برای حذف نمی‌ماند.'], JSON_UNESCAPED_UNICODE); exit; }
        $full = count($targets) === count($sections);

        // ۱) پیش از هر حذفی، بکاپ کامل (همه‌ی جدول‌های دیتابیس + همه‌ی فایل‌ها و اکسل‌ها) در پوشه‌ی
        //    backup/<تاریخ امروز>/ ساخته و بررسی می‌شود؛ اگر بکاپ ناموفق باشد هیچ چیزی پاک نمی‌شود.
        //    این بکاپ از «تنظیمات سیستم ← پشتیبان‌گیری» دوباره ایمپورت می‌شود و همه‌چیز را برمی‌گرداند.
        $labels = array_map(fn($k) => $sections[$k]['label'], $targets);
        try {
            $backup = bk_create($pdo, 'full', 'before-reset', 'بکاپ خودکار پیش از حذف اطلاعات: ' . ($full ? 'همه' : implode('، ', $labels)));
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'بکاپ پیش از حذف ساخته نشد، پس هیچ اطلاعاتی پاک نشد: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ۲) حذفِ جدول‌ها و پوشه‌های همان بخش‌ها (تنظیمات هیچ‌وقت پاک نمی‌شوند؛ حساب‌های مدیر کل می‌مانند)
        bk_reset_run($pdo, $targets, $full, $userId);
        if ($pdo->inTransaction()) $pdo->commit();

        // خودِ پاکسازی ثبت می‌شود تا معلوم باشد چه کسی، کِی و چه بخش‌هایی را پاک کرده
        try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'RESET_ALL', 'system', 0, ?)")
                  ->execute([$userId, $full ? 'پاکسازی کامل اطلاعات' : 'حذف اطلاعات: ' . implode('، ', $labels)]); } catch (Exception $e) {}

        $msg = $full ? 'همه‌ی اطلاعات (پرونده‌ها، شرکت‌ها، مالی، گزارش‌های بازدید، بایگانی، چت‌ها، لاگ‌ها و اعلان‌ها) کاملاً پاک شد. '
                     : 'این بخش‌ها پاک شد: ' . implode('، ', $labels) . '. بقیه‌ی بخش‌ها دست‌نخورده ماند. ';
        echo json_encode(['ok' => true, 'backup' => $backup['rel'], 'full' => $full, 'sections' => $targets,
            'message' => $msg . 'پیش از حذف، بکاپ کامل در پوشه‌ی backup/' . $backup['rel'] . ' ذخیره شد و از «تنظیمات سیستم ← پشتیبان‌گیری» قابل بازگرداندن است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- بخش‌های «حذف اطلاعات» با تعدادِ ردیف‌های هرکدام (برای پنجره‌ی انتخاب) ----
    if ($action === 'reset_sections') {
        if ($_SESSION['role'] !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'فقط مدیر کل دسترسی دارد.']); exit; }
        require_once __DIR__ . '/_backup_core.php';
        $byTable = bk_reset_section_tables($pdo);
        $out = [];
        foreach (bk_reset_sections() as $k => $sec) {
            $rows = 0;
            foreach ($byTable[$k] as $t) { try { $rows += intval($pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $t) . "`")->fetchColumn()); } catch (Throwable $e) {} }
            if ($k === 'users') { try { $rows = intval($pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'ADMIN'")->fetchColumn()); } catch (Throwable $e) {} }
            $out[] = ['key' => $k, 'label' => $sec['label'], 'desc' => $sec['desc'], 'rows' => $rows];
        }
        echo json_encode(['ok' => true, 'sections' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[record_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس.']);
}
?>