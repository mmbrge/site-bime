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
if (in_array($action, ['edit', 'change_status', 'create_manual', 'create_manual_request', 'create_case_for_intro'], true)
    && ($_SESSION['role'] ?? '') !== 'ADMIN') {
    echo json_encode(['ok' => false, 'error' => 'ثبت و ویرایش درخواست فقط برای مدیر کل مجاز است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

        $pdo->beginTransaction();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        // همه‌ی جدول‌های داده‌ای (کاربران پنل و تنظیمات سیستم مثل توکن بات دست‌نخورده می‌مانند)
        foreach ([
            'messages', 'ticket_messages', 'tickets', 'documents', 'case_documents',
            'health_inspections', 'health_attempts', 'policy_cases', 'insurance_requests',
            'introductions', 'person_vehicles', 'bot_sessions', 'persons', 'companies',
            'processing_queue', 'audit_logs', 'app_notifications', 'webapp_sessions',
            // ماژول شرکت‌ها: درخواست‌ها/پلاک‌ها/مدارک، مالی شرکتی، چت داخلی و شرکتی،
            // و حساب‌های کاربری ثبت‌کننده‌ی شرکت‌ها - قبلاً پاک نمی‌شدند
            'company_payment_allocations', 'company_payments', 'company_installments',
            'company_documents', 'company_request_plates', 'company_requests',
            'company_portal_user_companies',
            'staff_chat_messages', 'company_chat_messages', 'bot_known_groups',
            // مالی پرسنلی: اقساط، دریافتی‌ها و تخصیص‌هایشان، چک‌ها، صورتحساب‌ها،
            // تسویه‌های پاسارگاد، مغایرت‌گیری و دوره‌های صورتحساب - این‌ها هم قبلاً
            // در «پاک کردن اطلاعات» جا مانده بودند، پس بدهی/بستانکاریِ پرونده‌های
            // پاک‌شده به‌صورت یتیم در گزارش‌های مالی باقی می‌ماند
            'payment_allocations', 'payments', 'policy_installments',
            'invoice_lines', 'invoices', 'cheques',
            'pasargad_settlement_lines', 'pasargad_settlements',
            'reconciliations', 'billing_periods',
            // وضعیت گفتگوی ربات و کدهای یک‌بارمصرف ورود
            'conversation_state', 'login_otps',
            // تاریخچه‌ی بررسیِ مدارک/بازدیدها و اعلان‌ها (زنگوله‌ی پنل‌ها و اعلان‌های مدیر در ربات)
            'review_log', 'user_notifications', 'notification_cursors', 'admin_notifications',
            // ورود و کاربران: لاگ ورود و خروج، کدهای ورود با بله، درخواست‌های بازیابی رمز
            // و وضعیتِ گفتگوی ربات شرکت‌ها
            'login_logs', 'phone_otps', 'password_reset_requests', 'company_bot_state',
        ] as $table) {
            try { $pdo->exec("TRUNCATE TABLE `$table`;"); } catch (Exception $e) { /* اگر جدولی وجود نداشت، رد شو */ }
        }
        // کاربرانِ شرکت‌ها با DELETE (نه TRUNCATE) پاک می‌شوند تا شماره‌ی شناسه از اول شروع نشود؛
        // وگرنه نشستِ بازِ یک کاربرِ پاک‌شده روی کاربرِ تازه‌ای با همان شناسه می‌نشست
        try { $pdo->exec("DELETE FROM `company_portal_users`;"); } catch (Exception $e) { /* ... */ }
        // کاربران داخلی «همکار» (اپراتور/مالی/همکار شرکت‌ها) هم پاک می‌شوند؛ فقط
        // حساب‌های ADMIN دست‌نخورده می‌مانند تا کسی از پنل بیرون نماند (مدیرِ حذف‌شده هم پاک می‌شود)
        try { $pdo->exec("DELETE FROM `users` WHERE `role` <> 'ADMIN';"); } catch (Exception $e) { /* ... */ }
        try { $pdo->exec("DELETE FROM `users` WHERE COALESCE(`is_deleted`, 0) = 1 AND `id` <> " . intval($userId) . ";"); } catch (Exception $e) { /* ستون هنوز نیست */ }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        if ($pdo->inTransaction()) $pdo->commit();   // TRUNCATE در MySQL خودش commit می‌کند

        // خودِ پاکسازی ثبت می‌شود تا معلوم باشد چه کسی و کِی انجام داده
        try { $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'RESET_ALL', 'system', 0, 'پاکسازی کامل اطلاعات')")->execute([$userId]); } catch (Exception $e) {}

        // پاکسازی کامل فایل‌ها: بایگانی، پرونده‌های موقت، صف انتظار و لاگ‌ها
        function rrmdir_contents($dir) {
            if (!is_dir($dir)) return;
            foreach (scandir($dir) as $item) {
                if ($item === '.' || $item === '..' || $item === '.gitkeep') continue;
                $path = $dir . '/' . $item;
                if (is_dir($path)) { rrmdir_contents($path); @rmdir($path); }
                else { @unlink($path); }
            }
        }
        $siteRoot = dirname(__DIR__);
        // کلِ بایگانی (همه‌ی زیرپوشه‌ها: صادره، کسر از حقوق، شرکتی، شرکت‌ها، چت‌ها، مدارک دستی، ...)،
        // بایگانی و موقتِ مالی، همه‌ی موقت‌ها، صف OCR و فایل‌های رسیده از ربات و مینی‌اپ.
        // قالب‌های Word صورتحساب (tmpl_invoice) تنظیمات‌اند و دست‌نخورده می‌مانند.
        foreach (['/Archive/بایگانی', '/Archive/مالی', '/موقت', '/بایگانی', '/tmp_ocr', '/tmp_recon',
                  '/queue/pending', '/queue/done', '/queue/case_uploads', '/queue/attachments'] as $rel) {
            rrmdir_contents($siteRoot . $rel);
        }
        // پوشه‌های اصلیِ بایگانی دوباره ساخته می‌شوند تا بایگانی فایل‌ها خالی ولی مرتب دیده شود
        foreach ([archive_root($siteRoot) . '/بایگانی صادره', archive_root($siteRoot) . '/بایگانی کسر از حقوق',
                  archive_root($siteRoot) . '/سایر مدارک', archive_root($siteRoot) . '/بایگانی شرکتی',
                  temp_archive_root($siteRoot), temp_finance_root($siteRoot), finance_root($siteRoot)] as $dir) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($siteRoot . '/queue/bale_debug.log', '');

        echo json_encode(['ok' => true, 'message' => 'همه‌ی اطلاعات، پرونده‌ها، بایگانی، چت‌ها، لاگ‌های ورود و خروج و اعلان‌ها کاملاً پاک شدند. سایت به حالت اولیه بازگشت.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[record_actions] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس.']);
}
?>