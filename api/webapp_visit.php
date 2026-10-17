<?php
// فایل: api/webapp_visit.php
// بک‌اندِ «بارگزاری مدارک» (مدارکِ درخواست + بازدیدِ سلامتِ خودرو برای بدنه)
// این صفحه در مرورگر معمولیِ داخل بله باز می‌شود (نه WebView مینی‌اپ)، پس دوربین واقعی کار می‌کند.
// بیرون از ساعتِ کاری (پنل ← تنظیمات ← مینی‌اپ کاربران) صفحه باز می‌شود ولی ارسالِ مدرک بسته است.
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_case_doc_upload.php';   // عکس/گالری/PDF برای مدارک + «چند مدرک در یک PDF»
require_once __DIR__ . '/_health_admin.php';      // حالتِ نوبتی: فقط بازدیدِ سلامت حضوری است
require_once __DIR__ . '/_order_hours.php';       // ساعتِ کاری: بیرون از آن ارسالِ مدرک بسته است
require_once __DIR__ . '/_vehicle_catalog.php';

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

function wv_jdt($dt) {
    if (!$dt) return '';
    [$y, $m, $d] = jalali_from_gregorian_ts(strtotime($dt));
    return oh_fa(sprintf('%04d/%02d/%02d', $y, $m, $d) . ' ' . date('H:i', strtotime($dt)));
}
function wv_case_for_person($pdo, $caseId, $person) {
    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ? AND person_id = ? AND COALESCE(status, '') <> 'WITHDRAWN'");
    $stmt->execute([intval($caseId), $person['id']]);
    return $stmt->fetch() ?: null;
}
function wv_required($case) {
    return get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
}
// مدارکِ لازمِ یک پرونده با آخرین وضعیتِ هر کدام (برای صفحه‌ی ارسال و «مدارک بارگزاری شده»)
function wv_docs_list($pdo, $case) {
    $stmt = $pdo->prepare("SELECT id, doc_key, status, reject_reason, uploaded_at, reviewed_at, file_path FROM case_documents WHERE case_id = ? ORDER BY id DESC");
    $stmt->execute([$case['id']]);
    $latest = [];
    foreach ($stmt->fetchAll() as $r) if (!isset($latest[$r['doc_key']])) $latest[$r['doc_key']] = $r;
    $list = [];
    foreach (wv_required($case) as $key => $label) {
        $r = $latest[$key] ?? null;
        $ext = $r ? strtolower(pathinfo((string)$r['file_path'], PATHINFO_EXTENSION)) : '';
        $list[] = ['key' => $key, 'label' => $label, 'required' => true, 'status' => $r['status'] ?? null, 'reject_reason' => $r['reject_reason'] ?? null,
                   'doc_id' => $r ? intval($r['id']) : null, 'is_pdf' => $ext === 'pdf', 'uploaded_j' => $r ? wv_jdt($r['uploaded_at']) : '', 'reviewed_j' => $r ? wv_jdt($r['reviewed_at']) : ''];
    }
    return $list;
}
// خلاصه‌ی پرونده برای کارت‌های مینی‌اپ
function wv_case_info($pdo, $c) {
    $kind = $c['vehicle_kind'] ?? '';
    return [
        'id' => intval($c['id']), 'unique_code' => $c['unique_code'], 'insurance_type' => $c['insurance_type'], 'type_fa' => $c['insurance_type'] === 'BODY' ? 'بیمه بدنه' : 'بیمه ثالث',
        'plate' => $c['plate'], 'no_plate' => !empty($c['no_plate']), 'chassis_num' => $c['chassis_num'] ?? '',
        'kind_fa' => VC_KINDS[$kind]['fa'] ?? '', 'kind_icon' => VC_KINDS[$kind]['icon'] ?? '🚗',
        'car_type' => $c['car_type'] ?? '', 'car_system' => $c['car_system'] ?? '',
        'insured_name' => $c['insured_name'], 'insured_relationship' => $c['insured_relationship'],
        'liability_limit' => $c['liability_limit'] !== null ? intval($c['liability_limit']) : null,
        'estimated_car_value' => $c['estimated_car_value'] !== null ? intval($c['estimated_car_value']) : null,
        'ownership_choice' => $c['ownership_choice'], 'created_j' => wv_jdt($c['created_at']), 'case_status' => $c['status'],
    ];
}

try {
    // =====================================================================
    if ($action === 'init') {
        $token = $_GET['token'] ?? '';
        $person = resolve_visit_person($pdo, $token);
        if ($person) {
            echo json_encode(['ok' => true, 'is_guest' => false, 'person_name' => $person['full_name'], 'national_code' => $person['national_code'], 'token' => $token, 'hours' => oh_status($pdo)], JSON_UNESCAPED_UNICODE);
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
            $stage = case_user_stage($pdo, $c, $docsStatus, $healthStatus);
            $docs = wv_docs_list($pdo, $c);
            $result[] = wv_case_info($pdo, $c) + [
                'created_at' => $c['created_at'],
                'docs_status' => $docsStatus, 'docs_status_fa' => DOC_STATUS_FA[$docsStatus],
                'health_status' => $healthStatus, 'health_status_fa' => DOC_STATUS_FA[$healthStatus],
                'health_reject_reason' => $healthRejectReason,
                'stage' => $stage, 'stage_fa' => CASE_USER_STAGE_FA[$stage],
                'docs' => $docs, 'docs_total' => count($docs), 'docs_sent' => count(array_filter($docs, function ($d) { return in_array($d['status'], ['PENDING', 'APPROVED'], true); })),
                // «در انتظار بارگزاری»: مدرکی مانده/رد شده، یا بازدیدِ سلامتِ بدنه هنوز انجام نشده
                'awaiting' => $stage !== 'issued' && (in_array($docsStatus, ['not_started', 'partial', 'needs_fix'], true) || in_array($healthStatus, ['not_started', 'needs_fix'], true)),
                // حالتِ نوبتی: بازدیدِ سلامتِ این درخواست حضوری است (مدارک مثلِ قبل از همین‌جا)
                'appointment' => in_array($healthStatus, ['not_started', 'needs_fix'], true) ? hadm_case_appt_info($pdo, $c) : null,
            ];
        }
        $apptMode = !hadm_self_enabled($pdo);
        echo json_encode(['ok' => true, 'data' => $result, 'appt_mode' => $apptMode, 'appt_hint' => $apptMode ? HADM_DOCS_HINT : '', 'hours' => oh_status($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =====================================================================
    if ($action === 'case_docs') {
        $person = resolve_visit_person($pdo, $_GET['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $case = wv_case_for_person($pdo, $_GET['case_id'] ?? 0, $person);
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        $docs = wv_docs_list($pdo, $case);
        // «سند یا کارتِ ماشین» تا وقتی مدرکِ مالکیتی ارسال نشده قابلِ تغییر است
        $ownLocked = false;
        foreach ($docs as $d) if (in_array($d['key'], ['ownership_doc', 'car_card_front', 'car_card_back'], true) && in_array($d['status'], ['PENDING', 'APPROVED'], true)) $ownLocked = true;
        $docsStatus = compute_docs_status($pdo, $case); $healthStatus = compute_health_status($pdo, $case);
        $stage = case_user_stage($pdo, $case, $docsStatus, $healthStatus);
        echo json_encode(['ok' => true, 'docs' => $docs, 'plate' => $case['plate'], 'unique_code' => $case['unique_code'], 'case' => wv_case_info($pdo, $case),
                          'needs_ownership' => !$case['ownership_choice'], 'ownership_choice' => $case['ownership_choice'], 'ownership_locked' => $ownLocked,
                          'docs_status' => $docsStatus, 'health_status' => $healthStatus, 'stage' => $stage, 'stage_fa' => CASE_USER_STAGE_FA[$stage],
                          'hours' => oh_status($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // انتخابِ «سند مالکیت» یا «کارت ماشین» (پیش از ارسالِ مدارکِ مالکیت)
    if ($action === 'set_ownership') {
        $person = resolve_visit_person($pdo, $jsonBody['token'] ?? '');
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'نشست نامعتبر است.']); exit; }
        $case = wv_case_for_person($pdo, $jsonBody['case_id'] ?? 0, $person);
        if (!$case) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        $choice = ($jsonBody['choice'] ?? '') === 'سند' ? 'سند' : 'کارت ماشین';
        $st = $pdo->prepare("SELECT COUNT(*) FROM case_documents WHERE case_id = ? AND doc_key IN ('ownership_doc','car_card_front','car_card_back') AND status IN ('PENDING','APPROVED')");
        $st->execute([$case['id']]);
        if ((int)$st->fetchColumn() > 0 && $choice !== $case['ownership_choice']) { echo json_encode(['ok' => false, 'error' => 'مدرکِ مالکیت ارسال شده و نوعِ آن دیگر قابلِ تغییر نیست.'], JSON_UNESCAPED_UNICODE); exit; }
        $pdo->prepare("UPDATE policy_cases SET ownership_choice = ? WHERE id = ?")->execute([$choice, $case['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // نمایشِ مدرکِ ارسال‌شده‌ی خودِ کاربر (فقط پرونده‌های خودش)
    if ($action === 'doc_file') {
        $person = resolve_visit_person($pdo, $_GET['token'] ?? '');
        if (!$person) { http_response_code(403); exit; }
        $st = $pdo->prepare("SELECT cd.file_path FROM case_documents cd JOIN policy_cases pc ON pc.id = cd.case_id WHERE cd.id = ? AND pc.person_id = ?");
        $st->execute([intval($_GET['id'] ?? 0), $person['id']]);
        $rel = (string)$st->fetchColumn();
        $root = realpath(dirname(__DIR__));
        $abs = $rel !== '' ? realpath($root . '/' . ltrim($rel, '/')) : false;
        if (!$abs || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) { http_response_code(404); exit; }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
        while (ob_get_level() > 0) ob_end_clean();
        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="doc-' . intval($_GET['id']) . '.' . $ext . '"');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
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
            $hs = oh_status($pdo);
            if (!$hs['open']) { echo json_encode(oh_closed_payload($hs), JSON_UNESCAPED_UNICODE); exit; }
            $docKey = $_POST['doc_key'] ?? '';
            if (in_array($docKey, ['ownership_doc', 'car_card_front', 'car_card_back'], true) && !$case['ownership_choice']) { echo json_encode(['ok' => false, 'error' => 'ابتدا مشخص کنید سندِ مالکیت دارید یا کارتِ ماشین.'], JSON_UNESCAPED_UNICODE); exit; }
            $required = get_required_docs_v2($case['insurance_type'], $case['ownership_choice'], $case['prev_body_insurance'], $case['insured_relationship']);
            if (!isset($required[$docKey])) { echo json_encode(['ok' => false, 'error' => 'مورد انتخابی نامعتبر است.']); exit; }
            if (case_doc_locked($pdo, $caseId, $docKey)) { echo json_encode(['ok' => false, 'error' => 'این مدرک ارسال یا تایید شده و تا مشخص شدنِ نتیجه قابل تغییر نیست.']); exit; }
            // عکس (دوربین/گالری) یا PDF (تک‌صفحه یا چندصفحه) — نوعِ واقعیِ فایل از محتوایش تشخیص داده می‌شود
            $chk = case_doc_check_upload($_FILES['file'] ?? null);
            if (!$chk['ok']) { echo json_encode($chk, JSON_UNESCAPED_UNICODE); exit; }
            $r = case_doc_store_user($pdo, dirname(__DIR__), $case, $person, $docKey, $required[$docKey], $_FILES['file']['tmp_name'], $chk['ext'], true);
            if (!$r['ok']) { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

            maybe_advance_case_status($pdo, $caseId);
            echo json_encode(['ok' => true, 'doc_id' => $r['doc_id'] ?? null]);
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
        if ($action !== 'split_page') { $hs = oh_status($pdo); if (!$hs['open']) { echo json_encode(oh_closed_payload($hs), JSON_UNESCAPED_UNICODE); exit; } }
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
        // «بازدیدِ آزاد» (بدونِ درخواست) حذف شده؛ بازدیدِ سلامت فقط روی درخواستِ بدنه‌ی خودِ کاربر
        if (!$person) { echo json_encode(['ok' => false, 'error' => 'برای بازدید روی یک درخواست مشخص باید وارد شده باشید.']); exit; }
        $hs = oh_status($pdo);
        if (!$hs['open']) { echo json_encode(oh_closed_payload($hs), JSON_UNESCAPED_UNICODE); exit; }
        $caseId = intval($jsonBody['case_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM policy_cases WHERE id = ? AND person_id = ? AND COALESCE(status, '') <> 'WITHDRAWN'");
        $stmt->execute([$caseId, $person['id']]);
        if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'پرونده یافت نشد.']); exit; }
        $healthToken = create_webapp_session($pdo, $person['id'], $caseId, 'case', null);
        echo json_encode(['ok' => true, 'health_token' => $healthToken]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[webapp_visit] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'خطای سرور.']);
}
