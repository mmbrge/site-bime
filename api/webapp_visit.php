<?php
// فایل: api/webapp_visit.php
// بک‌اندِ «هاب بازدید» یکپارچه (بازدید مدارک + بازدید سلامت خودرو)
// این صفحه در مرورگر معمولیِ داخل بله باز می‌شود (نه WebView مینی‌اپ)، پس دوربین واقعی کار می‌کند.
// کاربرهای مهمان (بدون توکن) هم می‌توانند بازدید آزاد ثبت کنند.
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_case_doc_upload.php';   // عکس/گالری/PDF برای مدارک + «چند مدرک در یک PDF»

$jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($jsonBody['action'] ?? '');

// توکن معمولیِ «اپ اصلی» (mode='main') را می‌پذیرد؛ اگر توکن نامعتبر/خالی بود، یعنی کاربر مهمان است
function resolve_visit_person($pdo, $token) {
    if (!$token) return null;
    $stmt = $pdo->prepare("SELECT * FROM webapp_sessions WHERE token = ? AND mode = 'main' AND expires_at > NOW()");
    $stmt->execute([$token]);
    $session = $stmt->fetch();
    if (!$session) return null;
    $stmt = $pdo->prepare("SELECT * FROM persons WHERE id = ?");
    $stmt->execute([$session['person_id']]);
    return $stmt->fetch() ?: null;
}

const DOC_STATUS_FA = [
    'not_applicable' => null,
    'not_started' => 'هنوز ارسال نکرده‌اید',
    'partial' => 'ناقص ارسال شده',
    'submitted' => 'ارسال شده، در انتظار بررسی',
    'needs_fix' => 'نیاز به اصلاح دارد',
    'approved' => 'تایید شده',
];

try {
    // =====================================================================
    if ($action === 'init') {
        $token = $_GET['token'] ?? '';
        $person = resolve_visit_person($pdo, $token);
        if ($person) {
            echo json_encode(['ok' => true, 'is_guest' => false, 'person_name' => $person['full_name'], 'national_code' => $person['national_code'], 'token' => $token], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => true, 'is_guest' => true], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // =====================================================================
    if ($action === 'my_requests') {
        $person = resolve_visit_person($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE person_id = ? AND COALESCE(status, '') <> 'WITHDRAWN' ORDER BY created_at DESC");
        $stmt->execute([$person['id']]);
        $cases = $stmt->fetchAll();

        $result = [];
        foreach ($cases as $c) {
            if ($c['status'] === 'REGISTERED') continue; // هنوز ویزارد تمام نشده، ربطی به این‌جا ندارد
            $docsStatus = compute_docs_status($pdo, $c);
            $healthStatus = compute_health_status($pdo, $c);
            $healthRejectReason = $healthStatus === 'needs_fix' ? get_latest_health_reject_reason($pdo, $c['id']) : null;
            $result[] = [
                'id' => $c['id'], 'unique_code' => $c['unique_code'], 'insurance_type' => $c['insurance_type'],
                'plate' => $c['plate'], 'created_at' => $c['created_at'], 'case_status' => $c['status'],
                'insured_name' => $c['insured_name'], 'liability_limit' => $c['liability_limit'],
                'estimated_car_value' => $c['estimated_car_value'],
                'docs_status' => $docsStatus, 'docs_status_fa' => DOC_STATUS_FA[$docsStatus],
                'health_status' => $healthStatus, 'health_status_fa' => DOC_STATUS_FA[$healthStatus],
                'health_reject_reason' => $healthRejectReason,
            ];
        }
        echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    if ($action === 'case_docs') {
        $person = resolve_visit_person($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $caseId = intval($_GET['case_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ? AND person_id = ? AND COALESCE(status, '') <> 'WITHDRAWN'");
        $stmt->execute([$caseId, $person['id']]);
        $case = $stmt->fetch();
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }

        $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
        $stmt = $pdo->prepare("SELECT doc_key, status, reject_reason FROM case_documents WHERE case_id = ? ORDER BY id DESC");
        $stmt->execute([$caseId]);
        $latestByKey = [];
        foreach ($stmt->fetchAll() as $r) if (!isset($latestByKey[$r['doc_key']])) $latestByKey[$r['doc_key']] = $r;

        $list = [];
        foreach ($required as $key => $label) {
            $row = $latestByKey[$key] ?? null;
            $list[] = ['key' => $key, 'label' => $label, 'required' => true, 'status' => $row['status'] ?? null, 'reject_reason' => $row['reject_reason'] ?? null];
        }
        echo json_encode(['ok' => true, 'docs' => $list, 'plate' => $case['plate'], 'unique_code' => $case['unique_code']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // فهرست همه‌ی بازدیدهای سلامتِ این شخص (چه زیر پرونده‌ها و چه بازدیدهای آزاد)
    if ($action === 'all_inspections') {
        $person = resolve_visit_person($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }

        $stmt = $pdo->prepare("
            SELECT hi.id, hi.inspection_number, hi.status, hi.reject_reason, hi.created_at,
                   COALESCE(pc.plate, hi.free_plate) AS plate,
                   pc.unique_code, pc.insurance_type
            FROM health_inspections hi
            LEFT JOIN policy_cases pc ON hi.case_id = pc.id
            WHERE (pc.person_id = ? OR hi.person_id = ?)
            ORDER BY hi.created_at DESC
        ");
        $stmt->execute([$person['id'], $person['id']]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upload_doc') {
        try {
            $person = resolve_visit_person($pdo, $_POST['token'] ?? '');
            if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
            $caseId = intval($_POST['case_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ? AND person_id = ? AND COALESCE(status, '') <> 'WITHDRAWN'");
            $stmt->execute([$caseId, $person['id']]);
            $case = $stmt->fetch();
            if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
            $docKey = $_POST['doc_key'] ?? '';
            $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
            if (!isset($required[$docKey])) { echo json_encode(['ok' => false, 'error' => 'مورد انتخابی نامعتبر است.']); exit; }
            if (case_doc_locked($pdo, $caseId, $docKey)) { echo json_encode(['ok' => false, 'error' => 'این مدرک ارسال یا تایید شده و تا مشخص شدنِ نتیجه قابل تغییر نیست.']); exit; }
            // عکس (دوربین/گالری) یا PDF (تک‌صفحه یا چندصفحه) — نوعِ واقعیِ فایل از محتوایش تشخیص داده می‌شود
            $chk = case_doc_check_upload($_FILES['file'] ?? null);
            if (!$chk['ok']) { echo json_encode($chk, JSON_UNESCAPED_UNICODE); exit; }
            $r = case_doc_store_user($pdo, dirname(__DIR__), $case, $person, $docKey, $required[$docKey], $_FILES['file']['tmp_name'], $chk['ext'], true);
            if (!$r['ok']) { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

            maybe_advance_case_status($pdo, $caseId);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            error_log('[webapp_visit upload_doc] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
        }
        exit;
    }

    // =====================================================================
    //  «چند مدرک در یک PDF»: صفحه‌ها جدا و پیش‌نمایش می‌شوند؛ کاربر نوعِ هر صفحه را انتخاب می‌کند
    // =====================================================================
    if (in_array($action, ['split_upload', 'split_page', 'split_assign'], true)) {
        $tok = $_POST['token'] ?? ($_GET['token'] ?? ($jsonBody['token'] ?? ''));
        $person = resolve_visit_person($pdo, $tok);
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $siteRoot = dirname(__DIR__);
        if ($action === 'split_page') { case_doc_split_serve($siteRoot, $person, $_GET['job'] ?? '', intval($_GET['n'] ?? 0), $_GET['kind'] ?? 'png'); exit; }
        $caseId = intval($_POST['case_id'] ?? ($jsonBody['case_id'] ?? 0));
        $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ? AND person_id = ? AND COALESCE(status, '') <> 'WITHDRAWN'");
        $stmt->execute([$caseId, $person['id']]);
        $case = $stmt->fetch();
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        if ($action === 'split_upload') { echo json_encode(case_doc_split_upload($pdo, $siteRoot, $person, $_FILES['file'] ?? null), JSON_UNESCAPED_UNICODE); exit; }
        $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
        $r = case_doc_split_assign($pdo, $siteRoot, $case, $person, $jsonBody['job'] ?? '', $jsonBody['items'] ?? [], $required);
        if (!empty($r['ok'])) maybe_advance_case_status($pdo, $caseId);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    //  ورود به بازدید سلامت خودرو (برای پرونده‌ی مشخص یا آزاد/مهمان)
    // =====================================================================
    if ($action === 'start_health') {
        $token = $jsonBody['token'] ?? '';
        $person = resolve_visit_person($pdo, $token);
        $mode = ($jsonBody['mode'] ?? 'case') === 'free' ? 'free' : 'case';

        if ($mode === 'case') {
            if (!$person) { echo json_encode(['ok' => false, 'error' => 'برای بازدید روی یک درخواست مشخص باید وارد شده باشید.']); exit; }
            $caseId = intval($jsonBody['case_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id FROM policy_cases WHERE id = ? AND person_id = ? AND COALESCE(status, '') <> 'WITHDRAWN'");
            $stmt->execute([$caseId, $person['id']]);
            if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
            $healthToken = create_webapp_session($pdo, $person['id'], $caseId, 'case', null);
        } else {
            $plate = trim($jsonBody['plate'] ?? '');
            if (!preg_match('/^\d{2}ایران - \d{3} \S+ \d{2}$/u', $plate)) { echo json_encode(['ok' => false, 'error' => 'پلاک نامعتبر است.']); exit; }
            if ($person) {
                $healthToken = create_webapp_session($pdo, $person['id'], null, 'free', $plate);
            } else {
                // کاربر مهمان: بدون person_id هم بازدید آزاد ثبت می‌شود (در پنل به‌عنوان «مهمان» دیده می‌شود)
                $guestName = trim($jsonBody['guest_name'] ?? '') ?: 'کاربر مهمان';
                $guestNid = 'GUEST-' . bin2hex(random_bytes(6));
                $stmt = $pdo->prepare("INSERT INTO persons (full_name, national_code, conversation_state) VALUES (?, ?, 'GUEST')");
                $stmt->execute([$guestName, $guestNid]);
                $guestPersonId = $pdo->lastInsertId();
                $healthToken = create_webapp_session($pdo, $guestPersonId, null, 'free', $plate);
            }
        }
        echo json_encode(['ok' => true, 'health_token' => $healthToken]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[webapp_visit] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
