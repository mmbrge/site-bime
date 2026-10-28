<?php
// فایل: api/issue_panel_actions.php
// «پنلِ صدور»: همه‌ی اطلاعاتِ لازم برای صدورِ یک ردیفِ شرکتی (C) یا یک درخواستِ کارکنان (P) در یک جا —
// بیمه‌گذار، مالک، ذینفع، مشخصاتِ کاملِ خودرو، اطلاعاتِ درخواست و همه‌ی مدارک (فقط خواندنی؛ صدور از همان دکمه‌های قبلی)
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php';
perm_gate($pdo, __FILE__);
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_party.php';
require __DIR__ . '/_import_archive.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
$role = (string)($_SESSION['role'] ?? '');
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');

// یک خانه‌ی قابلِ کپی: [برچسب, مقدار, ltr?]؛ خانه‌های خالی حذف می‌شوند
function ip_f($label, $value, $ltr = false) {
    $v = is_scalar($value) ? trim((string)$value) : '';
    return $v === '' ? null : ['label' => $label, 'value' => $v, 'ltr' => (bool)$ltr];
}
function ip_sec($title, $icon, array $fields) {
    $fields = array_values(array_filter($fields));
    return $fields ? ['title' => $title, 'icon' => $icon, 'fields' => $fields] : null;
}
function ip_money($v) { $v = intval($v); return $v > 0 ? number_format($v) . ' ریال' : ''; }
function ip_jd($d) { return $d ? jalali_from_gregorian_ts_dotted(strtotime($d)) : ''; }
function ip_rel($siteRoot, $p) { return $p ? ltrim(str_replace($siteRoot, '', (string)$p), '/') : ''; }
// همه‌ی فایل‌های یک مدرکِ پوشه‌ای (بازدید) به‌صورتِ فایل‌های جدا
//   $extra: کلیدهای اضافه‌ی هر فایل (doc_id، doc_type، ext = قابلِ «استخراج اطلاعات»)
function ip_doc_files($siteRoot, $rel, $label, $group, array $extra = []) {
    $abs = realpath($siteRoot . '/' . ltrim((string)$rel, '/'));
    $root = realpath($siteRoot);
    if (!$abs || strpos($abs, $root . '/') !== 0) return [];
    if (is_file($abs)) return [['label' => $label, 'path' => ip_rel($root, $abs), 'group' => $group] + $extra];
    if (!is_dir($abs)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || count($out) >= 80) continue;
        if (!preg_match('/\.(jpe?g|png|webp|gif|pdf)$/i', $f->getFilename()) || preg_match('/\(old\)/u', $f->getFilename())) continue;
        $out[] = ['label' => $label . ' · ' . pathinfo($f->getFilename(), PATHINFO_FILENAME), 'path' => ip_rel($root, $f->getPathname()), 'group' => $group] + $extra;
    }
    usort($out, function ($a, $b) { return strnatcasecmp($a['path'], $b['path']); });
    return $out;
}

try {
    if (!in_array($action, ['get', 'doc_text', 'extract', 'dmg_upload', 'pb_apply'], true)) $out(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
    $type = ($data['type'] ?? '') === 'P' ? 'P' : 'C';
    $id = intval($data['id'] ?? 0);
    $siteRoot = dirname(__DIR__);
    if (!$id) $out(['ok' => false, 'error' => 'ردیف مشخص نیست.']);

    if ($type === 'C') {
        if (!in_array($role, ['ADMIN', 'COMPANY_LIAISON'], true)) $out(['ok' => false, 'error' => 'به پنلِ صدورِ ردیف‌های شرکتی دسترسی ندارید.']);
        company_ensure_issue_info_cols($pdo); company_ensure_coverage_column($pdo); party_ensure($pdo); companies_extra_ensure($pdo); company_doc_label_ensure($pdo);
        $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.insurer, cr.request_kind, cr.request_text, cr.created_at AS req_at, cr.party_mode AS req_party_mode, cr.party_json AS req_party_json, cr.insured_json AS req_insured_json,
                                    c.name AS company_name, c.economic_code, c.national_id AS company_nid, c.phone AS company_phone, c.address AS company_address, c.postal_code AS company_postal
                               FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) $out(['ok' => false, 'error' => 'ردیف پیدا نشد.']);
        $ii = issue_info_decode($r['issue_info']);
        $docs = $pdo->prepare("SELECT * FROM company_documents WHERE (plate_id = ? OR (request_id = ? AND plate_id IS NULL AND doc_type = 'letter')) AND status IN ('ASSIGNED', 'PENDING') ORDER BY plate_id IS NULL DESC, id");
        $docs->execute([$id, $r['request_id']]);
        $docs = $docs->fetchAll();
        // داده‌ای که از کارت/سند/فاکتورِ ردیف خوانده شده
        $dd = [];
        foreach ($docs as $d) {
            $aj = !empty($d['ai_json']) ? json_decode($d['ai_json'], true) : null;
            if (is_array($aj) && !empty($aj['data'])) foreach ((array)$aj['data'] as $k => $v) if (is_scalar($v) && trim((string)$v) !== '' && !isset($dd[$k])) $dd[$k] = trim((string)$v);
        }
        $pr = party_resolve($r, ['name' => $r['company_name'], 'national_id' => $r['company_nid'], 'economic_code' => $r['economic_code'], 'phone' => $r['company_phone'], 'address' => $r['company_address']],
                            ['party_mode' => $r['req_party_mode'], 'party_json' => $r['req_party_json'], 'insured_json' => $r['req_insured_json'] ?? null]);
        $ins = $pr['insured'] ?: []; $own = $pr['owner'] ?: []; $ben = $pr['beneficiary'] ?: [];
        $plate = company_row_label($r);
        $kind = $r['request_kind'] ?: 'NEW_POLICY';
        $isBody = $r['insurance_type'] === 'BODY';
        $secs = array_values(array_filter([
            ip_sec('بیمه‌گذار', 'fa-user-shield', [ip_f('نام', $ii['insured_name'] ?? '' ?: ($ins['name'] ?? '')), ip_f('کد / شناسه ملی', ($ii['insured_national_id'] ?? '') ?: (($ins['national_id'] ?? '') ?: ($ins['economic_code'] ?? '')), true),
                ip_f('کد اقتصادی', ($ins['kind'] ?? '') === 'COMPANY' ? $r['economic_code'] : '', true), ip_f('تلفن', ($ii['insured_phone'] ?? '') ?: ($ins['phone'] ?? ''), true),
                ip_f('کد پستی', ($ii['insured_postal_code'] ?? '') ?: ($ins['postal_code'] ?? ''), true), ip_f('نشانی', ($ii['insured_address'] ?? '') ?: ($ins['address'] ?? '')), ip_f('نوع مالکیت', $pr['mode_fa'])]),
            ip_sec('مالک', 'fa-user', [ip_f('نام', ($ii['owner_name'] ?? '') ?: (($own['name'] ?? '') ?: ($dd['owner_name'] ?? ''))), ip_f('کد / شناسه ملی', ($ii['owner_national_id'] ?? '') ?: (($own['national_id'] ?? '') ?: ($dd['national_id'] ?? '')), true),
                ip_f('نسبت', $ii['owner_relation'] ?? ''), ip_f('تلفن', ($ii['owner_phone'] ?? '') ?: (($own['phone'] ?? '') ?: ($dd['mobile'] ?? '')), true), ip_f('نشانی', ($ii['owner_address'] ?? '') ?: (($own['address'] ?? '') ?: ($dd['address'] ?? ''))),
                ip_f('کد پستی', ($ii['owner_postal_code'] ?? '') ?: (($own['postal_code'] ?? '') ?: ($dd['postal_code'] ?? '')), true), ip_f('نام پدر', ($ii['owner_father'] ?? '') ?: ($dd['father_name'] ?? '')),
                ip_f('تاریخ تولد / ثبت', ($ii['owner_birth_date'] ?? '') ?: ($dd['birth_date'] ?? ''), true)]),
            $ben || !empty($ii['beneficiary']) ? ip_sec('ذینفع', 'fa-hand-holding-heart', [ip_f('نام', ($ii['beneficiary'] ?? '') ?: ($ben['name'] ?? '')), ip_f('کد / شناسه ملی', ($ii['beneficiary_national_id'] ?? '') ?: ($ben['national_id'] ?? ''), true)]) : null,
            ip_sec('خودرو', 'fa-car-side', [ip_f('پلاک', $plate), ip_f('شماره شاسی (VIN)', $r['chassis_no'] ?: ($r['vin'] ?: ($dd['vin'] ?? '')), true), ip_f('شماره موتور', $r['engine_no'] ?: ($dd['engine_no'] ?? ''), true),
                ip_f('نام خودرو', $r['car_name']), ip_f('سیستم', ($ii['car_system'] ?? '') ?: ($dd['car_system'] ?? '')), ip_f('تیپ', ($r['car_type'] ?? '') ?: (($ii['car_type'] ?? '') ?: ($dd['car_tip'] ?? ''))),
                ip_f('نوع', $ii['car_kind'] ?? ''), ip_f('مدل (سال ساخت)', ($ii['car_model_year'] ?? '') ?: ($dd['model_year'] ?? ''), true), ip_f('رنگ', ($ii['car_color'] ?? '') ?: ($dd['color'] ?? '')),
                ip_f('کاربری', ($ii['car_usage'] ?? '') ?: ($dd['usage'] ?? '')), ip_f('ظرفیت', ($ii['car_capacity'] ?? '') ?: ($dd['capacity'] ?? ''), true), ip_f('تعداد سیلندر', ($ii['car_cylinders'] ?? '') ?: ($dd['cylinders'] ?? ''), true),
                ip_f('نوع سوخت', ($ii['car_fuel'] ?? '') ?: ($dd['fuel'] ?? '')), ip_f('تعداد محور', ($ii['car_axles'] ?? '') ?: ($dd['axles'] ?? ''), true), ip_f('تعداد چرخ', ($ii['car_wheels'] ?? '') ?: ($dd['wheels'] ?? ''), true),
                ip_f('شماره اتاق', ($ii['cab_no'] ?? '') ?: ($dd['cab_no'] ?? ''), true), ip_f('شماره مسلسل کارت', ($ii['card_serial'] ?? '') ?: ($dd['card_serial'] ?? ''), true),
                ip_f('صفر کیلومتر', !empty($r['is_new_vehicle']) ? 'بله' : '')]),
            ip_sec('درخواست', 'fa-clipboard-list', [ip_f('شرکت', $r['company_name']), ip_f('شماره درخواست', ref_code_of($pdo, 'company_requests', (int)$r['request_id']) ?: ('#' . $r['request_id'])), ip_f('تاریخ درخواست', ip_jd($r['req_at']), true),
                ip_f('نوع درخواست', company_request_kind_fa($kind)), ip_f('نوع بیمه', $isBody ? 'بدنه' : 'ثالث'), ip_f('بیمه‌گر', $r['insurer'] === 'IRAN' ? 'ایران' : 'پاسارگاد'),
                ip_f('انقضای بیمه‌نامه‌ی قبلی', ip_jd($r['expiry_date']), true), ip_f('بیمه‌نامه‌ی قبلی / مرجع', ($r['ref_policy_number'] ?? '') ?: ($ii['prev_policy_number'] ?? ''), true), ip_f('بیمه‌گرِ قبلی', $ii['prev_insurer'] ?? ''), ip_f('انقضای بیمه‌نامه‌ی قبلی (از بیمه‌نامه)', $ii['prev_expiry'] ?? '', true),
                ip_f('تخفیف عدم خسارت', trim((($ii['no_claim_years'] ?? '') !== '' ? $ii['no_claim_years'] . ' سال' : '') . (($ii['prev_no_claim_percent'] ?? '') !== '' ? ' (' . $ii['prev_no_claim_percent'] . '٪)' : ''))),
                ip_f('مبلغ بیمه‌شده‌ی قبلی', ip_money($ii['prev_car_value'] ?? '')), ip_f('پوشش‌های بیمه‌نامه‌ی قبلی', $ii['prev_covers'] ?? ''),
                $isBody ? ip_f('ارزش خودرو', ip_money($r['car_value'])) : ip_f('تعهد مالی', ip_money($r['liability_limit'])),
                $isBody ? ip_f('پوشش‌ها', implode('، ', company_coverages_fa($r['selected_coverages'] ?? null))) : null,
                $isBody ? ip_f('بیمه بدنه قبل', ($r['has_prev_body'] ?? '') === 'YES' ? 'دارد' : (($r['has_prev_body'] ?? '') === 'NO' ? 'ندارد' : '')) : null,
                ip_f('خواسته‌ی الحاقیه', $r['endorsement_request'] ?? ''), ip_f('دلیل فسخ', $r['cancellation_reason'] ?? ''), ip_f('توضیحِ ردیف', $r['row_note'] ?? ''), ip_f('متنِ درخواست', $r['request_text'] ?? ''),
                ip_f('یادداشت صدور', $ii['note'] ?? '')]),
            $r['status'] === 'ISSUED' ? ip_sec('صدور', 'fa-stamp', [ip_f('شماره بیمه‌نامه', $r['policy_number'], true), ip_f('تاریخ صدور', $r['policy_issue_date'] ?: ip_jd($r['issued_at']), true), ip_f('حق بیمه', ip_money($r['total_premium']))]) : null,
        ]));
        $files = [];
        foreach ($docs as $d) {
            $lab = $d['doc_type'] === 'letter' ? 'نامه‌ی درخواست' : company_doc_display_label($d);
            $files = array_merge($files, ip_doc_files($siteRoot, $d['file_path'], $lab, $d['doc_type'] === 'health_inspection' ? 'بازدید' : ($d['doc_type'] === 'letter' ? 'نامه' : 'مدارک'),
                                                     ['doc_id' => (int)$d['id'], 'doc_type' => (string)$d['doc_type'], 'ext' => !in_array($d['doc_type'], ['letter', 'health_inspection', 'health_report', 'damage_inquiry'], true)]));
        }
        if (!empty($r['issued_file_path'])) $files = array_merge(ip_doc_files($siteRoot, $r['issued_file_path'], 'بیمه‌نامه‌ی صادرشده', 'صدور', ['doc_type' => 'policy_doc', 'issued' => 1, 'ext' => true]), $files);
        if ($action === 'dmg_upload') ip_dmg_upload($pdo, 'C', $id, $out);
        if ($action === 'pb_apply') ip_pb_apply($pdo, 'C', $id, $data, $files, $out);
        if ($action !== 'get') ip_extract_action($pdo, $siteRoot, 'C', $id, $files, intval($r['company_id']), $data, $out);
        $out(['ok' => true, 'type' => 'C', 'id' => $id, 'request_id' => (int)$r['request_id'], 'status' => $r['status'], 'status_fa' => company_plate_status_fa($r['status'], $kind),
              'title' => $plate ?: ($r['chassis_no'] ?: 'ردیف شرکتی'), 'subtitle' => trim($r['company_name'] . ' · ' . ($isBody ? 'بدنه' : 'ثالث') . ($r['car_name'] ? ' · ' . $r['car_name'] : '')),
              'kind' => $kind, 'sections' => $secs, 'files' => $files, 'can_issue' => $role === 'ADMIN' && !in_array($r['status'], ['ISSUED', 'CANCELLED'], true), 'can_extract' => ip_can_extract($pdo),
              'damage' => ip_dmg_info($pdo, 'C', $id),
              // استعلامِ سنهاب از داخلِ پنل (ثالث): پلاک/شاسی/موتور و شماره بیمه‌نامه‌ی قبلیِ همین ردیف
              'sanhab' => ['third' => !$isBody, 'ref' => ['type' => 'crp', 'id' => $id], 'prefill' => [
                  'plate' => $r['plate_letter'] === 'موتور' ? null : ['p1' => $r['plate_p1'], 'p2' => $r['plate_p2'], 'letter' => $r['plate_letter'], 'p4' => $r['plate_p4']],
                  'moto' => $r['plate_letter'] === 'موتور' ? ['city' => $r['plate_p1'], 'num' => $r['plate_p2']] : null,
                  'vin' => $r['vin'] ?: '', 'chassis' => $r['chassis_no'] ?: '', 'engine' => $r['engine_no'] ?: '',
                  'nid' => preg_replace('/\D/', '', (string)(($ii['insured_national_id'] ?? '') ?: ($ins['national_id'] ?? ''))), 'policy_no' => ($r['ref_policy_number'] ?? '') ?: ($ii['prev_policy_number'] ?? '')]],
              'vehicle_key' => ($pc = plate_core($plate)) ? 'p:' . p2e_digits($pc) : (($v = brd_vin_key_safe($r['chassis_no'] ?: $r['vin'])) ? 'v:' . $v : '')]);
    }

    // P: درخواستِ کارکنان
    if ($role !== 'ADMIN') $out(['ok' => false, 'error' => 'به پنلِ صدورِ کارکنان دسترسی ندارید.']);
    $st = $pdo->prepare("SELECT pc.*, p.full_name, p.national_code, p.mobile_number, p.personnel_code, p.company_id AS person_company_id, co.name AS employer FROM policy_cases pc
                           LEFT JOIN persons p ON p.id = pc.person_id LEFT JOIN companies co ON co.id = p.company_id WHERE pc.id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) $out(['ok' => false, 'error' => 'پرونده پیدا نشد.']);
    $ii = issue_info_decode($c['issue_info'] ?? null);
    $oc = json_decode((string)($c['ocr_extracted_data'] ?? ''), true) ?: [];
    $isBody = ($c['insurance_type'] ?? '') === 'BODY';
    $g = function ($k) use ($c) { return isset($c[$k]) ? $c[$k] : ''; };
    $covFa = function_exists('company_coverages_fa') ? company_coverages_fa($c['selected_coverages'] ?? null) : [];
    $secs = array_values(array_filter([
        ip_sec('پرسنل', 'fa-id-badge', [ip_f('نام', $c['full_name']), ip_f('کد ملی', $c['national_code'], true), ip_f('کد پرسنلی', $c['personnel_code'] ?? '', true), ip_f('موبایل', $c['mobile_number'], true), ip_f('شرکتِ کارفرما', $c['employer'])]),
        ip_sec('بیمه‌گذار', 'fa-user-shield', [ip_f('نام', $g('insured_name') ?: ($ii['insured_name'] ?? '')), ip_f('کد ملی', $g('insured_national_id') ?: ($ii['insured_national_id'] ?? ''), true), ip_f('نسبت با پرسنل', $g('insured_relationship')),
            ip_f('تلفن', $g('insured_phone') ?: ($ii['insured_phone'] ?? ''), true), ip_f('کد پستی', $g('insured_postal_code') ?: ($ii['insured_postal_code'] ?? ''), true), ip_f('نشانی', $g('insured_address') ?: ($ii['insured_address'] ?? ''))]),
        ip_sec('مالک', 'fa-user', [ip_f('نام', $ii['owner_name'] ?? ''), ip_f('کد ملی', $ii['owner_national_id'] ?? '', true), ip_f('نسبت', $ii['owner_relation'] ?? ''), ip_f('تلفن', $ii['owner_phone'] ?? '', true)]),
        !empty($ii['beneficiary']) ? ip_sec('ذینفع', 'fa-hand-holding-heart', [ip_f('نام', $ii['beneficiary']), ip_f('کد ملی', $ii['beneficiary_national_id'] ?? '', true)]) : null,
        ip_sec('خودرو', 'fa-car-side', [ip_f('پلاک', $g('plate')), ip_f('شماره شاسی', $g('chassis_num') ?: ($g('vin') ?: ($oc['vin'] ?? '')), true), ip_f('شماره موتور', $g('engine_num') ?: ($oc['engine_no'] ?? ''), true),
            ip_f('سیستم', $g('car_system') ?: ($ii['car_system'] ?? '')), ip_f('تیپ', $g('car_type') ?: ($ii['car_type'] ?? '')), ip_f('مدل', $g('car_model_year') ?: ($ii['car_model_year'] ?? ''), true),
            ip_f('رنگ', $g('car_color') ?: ($ii['car_color'] ?? '')), ip_f('کاربری', $g('car_usage') ?: ($ii['car_usage'] ?? '')), ip_f('ظرفیت', $ii['car_capacity'] ?? '', true), ip_f('تعداد سیلندر', $ii['car_cylinders'] ?? '', true)]),
        ip_sec('درخواست', 'fa-clipboard-list', [ip_f('کد پرونده', $g('unique_code'), true), ip_f('نوع بیمه', $isBody ? 'بدنه' : 'ثالث'), ip_f('تاریخ ثبت', ip_jd($g('created_at')), true),
            $isBody ? ip_f('ارزش خودرو', ip_money($g('car_value') ?: $g('estimated_car_value'))) : ip_f('تعهد مالی', ip_money($g('liability_limit'))),
            $isBody ? ip_f('پوشش‌ها', implode('، ', $covFa)) : null, ip_f('انقضای بیمه‌نامه‌ی قبلی', $g('prev_expiry_date') ?: ($ii['prev_expiry'] ?? ''), true),
            ip_f('بیمه‌نامه‌ی قبلی', ($ii['prev_policy_number'] ?? '') ?: $g('prev_policy_number'), true), ip_f('بیمه‌گرِ قبلی', $ii['prev_insurer'] ?? ''), ip_f('انقضای بیمه‌نامه‌ی قبلی (از بیمه‌نامه)', $ii['prev_expiry'] ?? '', true),
                ip_f('تخفیف عدم خسارت', trim((($ii['no_claim_years'] ?? '') !== '' ? $ii['no_claim_years'] . ' سال' : '') . (($ii['prev_no_claim_percent'] ?? '') !== '' ? ' (' . $ii['prev_no_claim_percent'] . '٪)' : ''))),
                ip_f('مبلغ بیمه‌شده‌ی قبلی', ip_money($ii['prev_car_value'] ?? '')), ip_f('پوشش‌های بیمه‌نامه‌ی قبلی', $ii['prev_covers'] ?? ''), ip_f('یادداشت صدور', $ii['note'] ?? '')]),
        ($c['status'] ?? '') === 'ISSUED' ? ip_sec('صدور', 'fa-stamp', [ip_f('شماره بیمه‌نامه', $g('policy_number'), true), ip_f('تاریخ صدور', $g('policy_issue_date') ?: ip_jd($g('issued_at')), true), ip_f('حق بیمه', ip_money($g('total_premium')))]) : null,
    ]));
    $files = [];
    if (!empty($c['issued_file_path'])) $files = ip_doc_files($siteRoot, $c['issued_file_path'], 'بیمه‌نامه‌ی صادرشده', 'صدور', ['doc_type' => 'policy_doc', 'issued' => 1, 'ext' => true]);
    $req = company_doc_types();
    try { foreach ([['BODY', 'سند', 'بله', 'خود'], ['BODY', 'کارت', 'بله', 'پدر'], [$g('insurance_type'), $g('ownership_choice'), $g('prev_body_insurance'), $g('insured_relationship')]] as $a) $req = get_required_docs_v2(...$a) + $req; } catch (Throwable $e) {}
    foreach ($pdo->query("SELECT * FROM case_documents WHERE case_id = " . intval($id) . " ORDER BY id") as $d) {
        if (($d['status'] ?? '') === 'REJECTED') continue;
        $lab = ($d['doc_label'] ?? '') ?: ($req[$d['doc_key'] ?? ($d['doc_type'] ?? '')] ?? (($d['doc_key'] ?? '') ?: ($d['doc_type'] ?? 'مدرک')));
        $files = array_merge($files, ip_doc_files($siteRoot, $d['file_path'], $lab, 'مدارک', ['doc_type' => (string)(($d['doc_key'] ?? '') ?: ($d['doc_type'] ?? '')), 'ext' => true]));
    }
    try {
        $h = $pdo->prepare("SELECT photos FROM health_inspections WHERE case_id = ? ORDER BY id DESC LIMIT 1");
        $h->execute([$id]);
        if (($ph = $h->fetchColumn()) && function_exists('health_abs_path')) {
            $steps = health_inspection_steps();
            foreach ((json_decode($ph ?: '{}', true) ?: []) as $k => $rel) {
                $parts = explode('-', (string)$k);
                $files = array_merge($files, ip_doc_files($siteRoot, ip_rel($siteRoot, health_abs_path($siteRoot, $rel)), ($steps[$parts[0]]['label'] ?? $k) . (isset($parts[1]) ? ' ' . $parts[1] : ''), 'بازدید'));
            }
        }
    } catch (Throwable $e) {}
    if ($action === 'dmg_upload') ip_dmg_upload($pdo, 'P', $id, $out);
    if ($action === 'pb_apply') ip_pb_apply($pdo, 'P', $id, $data, [], $out);
    if ($action !== 'get') ip_extract_action($pdo, $siteRoot, 'P', $id, $files, intval($c['person_company_id'] ?? 0), $data, $out);
    $plate = (string)$g('plate');
    $stFa = ['REGISTERED' => 'ثبت شده', 'AWAITING_DOCS' => 'در انتظار بارگذاری مدارک', 'DOCS_PENDING' => 'در انتظار مدارک', 'DOCS_REVIEW' => 'در انتظار تایید مدارک',
             'ISSUING' => 'در حال صدور', 'ISSUED' => 'صادر شده', 'REJECTED' => 'رد شده', 'WITHDRAWN' => 'خارج از فاز عملیاتی'];
    $out(['ok' => true, 'type' => 'P', 'id' => $id, 'status' => $c['status'] ?? '', 'status_fa' => $stFa[$c['status'] ?? ''] ?? ($c['status'] ?? ''),
          'title' => $plate ?: ($c['full_name'] ?: 'پرونده‌ی کارکنان'), 'subtitle' => trim(($c['full_name'] ?? '') . ' · ' . ($isBody ? 'بدنه' : 'ثالث') . ($c['employer'] ? ' · ' . $c['employer'] : '')),
          'sections' => $secs, 'files' => $files, 'can_issue' => !in_array($c['status'] ?? '', ['ISSUED', 'REJECTED', 'WITHDRAWN'], true), 'can_extract' => ip_can_extract($pdo),
          'damage' => ip_dmg_info($pdo, 'P', $id),
          'sanhab' => ['third' => !$isBody, 'ref' => ['type' => 'case', 'id' => $id], 'prefill' => ['plate' => $plate, 'vin' => $g('vin') ?: '', 'chassis' => $g('chassis_num') ?: '', 'engine' => $g('engine_num') ?: '',
                       'nid' => preg_replace('/\D/', '', (string)($g('insured_national_id') ?: ($c['national_code'] ?? ''))), 'policy_no' => ($ii['prev_policy_number'] ?? '') ?: $g('prev_policy_number')]],
          'vehicle_key' => ($pc = plate_core($plate)) ? 'p:' . p2e_digits($pc) : (($v = brd_vin_key_safe($g('chassis_num') ?: $g('vin'))) ? 'v:' . $v : '')]);
} catch (Throwable $e) {
    error_log('[issue_panel] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}

// کلیدِ شاسی (مثلِ پرونده‌ی خودروها) بدونِ وابستگی به فایلِ بردرو
function brd_vin_key_safe($v) {
    $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v)));
    return strlen($v) >= 6 ? $v : '';
}

// ---------------- «استخراج اطلاعات» از یک مدرکِ پنلِ صدور ----------------
// doc_text: متنِ یک صفحه (متنِ خودِ PDF یا OCR با موتورهای سروری)؛ extract: متن ← پرامپتِ همان نوعِ مدرک (اختصاصیِ شرکت یا کلی) ← فقط خانه‌های خالیِ ردیف
function ip_can_extract($pdo) {
    require_once __DIR__ . '/_vdoc_ai.php';
    if (!vdoc_ai_ready($pdo)) return false;
    if (perm_real_role() === 'ADMIN') return true;
    $p = perm_user_custom($pdo, $_SESSION['user_id'] ?? 0);
    return $p === null || perm_allows($p, 'issue-queue:edit|companies-requests:edit|cases:edit');
}
function ip_extract_action($pdo, $siteRoot, $type, $id, array $files, $companyId, array $data, callable $out) {
    require_once __DIR__ . '/_vdoc.php';
    require_once __DIR__ . '/_doc_smart.php';
    $f = null;
    foreach ($files as $x) if ($x['path'] === (string)($data['path'] ?? '')) { $f = $x; break; }
    if (!$f || empty($f['ext'])) $out(['ok' => false, 'error' => 'این فایل برای استخراجِ اطلاعات در دسترس نیست.']);
    $abs = $siteRoot . '/' . $f['path'];
    @set_time_limit(600);
    if (($data['action'] ?? '') === 'doc_text') {
        $pages = 1;
        if (upload_ext($abs) === 'pdf') { try { require_once __DIR__ . '/_pdf_text.php'; $pages = max(1, PdfDoc::open($abs)->pageCount()); } catch (Throwable $e) {} }
        $page = max(1, min($pages, intval($data['page'] ?? 1)));
        $t = ds_page_text($pdo, $abs, $page);
        if (empty($t['ok'])) $out(['ok' => false, 'error' => $t['error'] ?? 'متنی خوانده نشد.', 'pages' => $pages]);
        $out(['ok' => true, 'pages' => $pages, 'page' => $page, 'engine' => $t['engine'] ?? '', 'text_verbatim' => (string)$t['text']]);
    }
    if (!vdoc_ai_ready($pdo)) $out(['ok' => false, 'error' => 'سرویسِ هوشِ مصنوعی (تنظیمات ← کلیدهای API) تنظیم نشده.']);
    $text = trim((string)($data['text'] ?? ''));
    if ($text === '') $out(['ok' => false, 'error' => 'متنی برای استخراج نیست.']);
    $types = ds_types();
    $want = (string)($data['doc_kind'] ?? '');
    $kind = isset($types[$want]) && $want !== 'letter' ? $want : (ds_type_of_doc($f['doc_type'] ?? '') ?: 'auto');
    if ($kind === 'letter') $kind = 'auto';
    $x = ds_extract($pdo, $kind, mb_substr($text, 0, DS_MAX_TEXT), $companyId);
    if (empty($x['ok'])) $out(['ok' => false, 'error' => $x['error'] ?? 'استخراج نشد.']);
    $applied = [];
    if ($x['type'] !== 'letter' && $x['type'] !== 'generic') {
        $applied = $type === 'C' ? ip_apply_company_row($pdo, $id, $x, $f) : ip_apply_case($pdo, $id, $x, $f);
    }
    $out(['ok' => true, 'type' => $x['type'], 'type_fa' => $x['type_fa'] ?? '', 'fields_verbatim' => (array)($x['fields'] ?? []), 'applied_verbatim' => $applied]);
}
// استعلامِ بدنه‌ی پاسارگاد (آخرین بیمه‌نامه) => خانه‌های خالیِ ردیف: شاسی، موتور، VIN، سیستم، پلاک، بیمه‌نامه‌ی قبلی، بیمه‌گر و انقضای قبلی
function ip_pb_apply($pdo, $type, $id, array $data, array $files, callable $out) {
    if (!ip_can_edit_row($pdo)) $out(['ok' => false, 'error' => 'به ویرایشِ این ردیف دسترسی ندارید.']);
    require_once __DIR__ . '/_vdoc.php';
    $r = is_array($data['row'] ?? null) ? $data['row'] : [];
    $g = function ($k) use ($r) { return trim((string)($r[$k] ?? '')); };
    $pp = is_array($r['plate_parts'] ?? null) ? $r['plate_parts'] : null;
    $d = ['chassis_no' => $g('chassis'), 'vin' => $g('vin'), 'engine_no' => $g('engine'), 'car_system' => $g('car_system'), 'policy_number' => $g('print_no'),
          'insurer' => $g('company'), 'end_date' => $g('end_date')];
    if ($pp && !empty($pp['letter'])) $d['plate_parts'] = ['letter' => (string)$pp['letter'], 'three' => (string)($pp['p2'] ?? ''), 'two' => (string)($pp['p4'] ?? ''), 'iran' => (string)($pp['p1'] ?? '')];
    $x = ['type' => 'policy', 'data' => array_filter($d, function ($v) { return $v !== '' && $v !== null; })];
    $applied = $type === 'C' ? ip_apply_company_row($pdo, $id, $x, ['issued' => false]) : ip_apply_case($pdo, $id, $x, ['issued' => false]);
    $out(['ok' => true, 'applied_verbatim' => $applied]);
}
function ip_can_edit_row($pdo) {
    if (perm_real_role() === 'ADMIN') return true;
    $p = perm_user_custom($pdo, $_SESSION['user_id'] ?? 0);
    return $p !== null && perm_allows($p, 'issue-queue:edit|companies-requests:edit|cases:edit');
}
// برچسبِ خانه‌هایی که پر شدند (برای نمایش)
function ip_fill_labels() {
    return (function_exists('vdoc_fill_labels') ? vdoc_fill_labels() : []) + ['chassis_no' => 'شماره شاسی', 'chassis_num' => 'شماره شاسی', 'vin' => 'VIN', 'engine_no' => 'شماره موتور', 'engine_num' => 'شماره موتور', 'car_name' => 'نام خودرو',
            'plate_p2' => 'پلاک', 'is_new_vehicle' => 'صفر کیلومتر', 'party_mode' => 'نقشِ مالک / ذینفع', 'owner_name' => 'نام مالک', 'owner_national_id' => 'کد ملیِ مالک',
            'owner_phone' => 'تلفنِ مالک', 'owner_address' => 'نشانیِ مالک', 'car_system' => 'سیستم', 'car_type' => 'تیپ', 'car_kind' => 'نوع', 'car_usage' => 'کاربری',
            'car_model_year' => 'مدل', 'car_color' => 'رنگ', 'car_capacity' => 'ظرفیت', 'car_cylinders' => 'تعداد سیلندر', 'prev_policy_number' => 'بیمه‌نامه‌ی قبلی',
            'prev_insurer' => 'بیمه‌گرِ قبلی', 'prev_expiry' => 'انقضای بیمه‌نامه‌ی قبلی', 'insured_name' => 'نامِ بیمه‌گذار', 'insured_national_id' => 'کد ملیِ بیمه‌گذار'];
}
function ip_diff(array $before, array $after) {
    $lab = ip_fill_labels(); $o = [];
    foreach ($after as $k => $v) {
        if (!isset($lab[$k]) || !is_scalar($v) || trim((string)$v) === '' || (string)$v === '0') continue;
        if (trim((string)($before[$k] ?? '')) !== '' && (string)($before[$k] ?? '') !== '0') continue;
        $o[$lab[$k]] = $k === 'is_new_vehicle' ? 'بله' : ($k === 'party_mode' ? (PARTY_MODES_SHORT[$v] ?? (string)$v) : (string)$v);
    }
    return $o;
}
// ردیفِ شرکتی: همان قاعده‌ی مدارکِ ردیف (vdoc_apply_to_row) — هیچ مقدارِ موجودی عوض نمی‌شود
function ip_apply_company_row($pdo, $id, array $x, array $f) {
    $snap = function () use ($pdo, $id) {
        $s = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?"); $s->execute([$id]);
        $r = $s->fetch() ?: []; $ii = issue_info_decode($r['issue_info'] ?? null); unset($r['issue_info']);
        return $ii + $r;
    };
    $d = (array)$x['data'];
    $before = $snap();
    if (in_array($x['type'], ['car_card', 'ownership_doc', 'sales_invoice', 'policy'], true)) {
        // بیمه‌نامه: فقط مشخصاتِ خودرو (بیمه‌گذارِ بیمه‌نامه نقشِ مالکِ ردیف را عوض نکند)
        $dv = $x['type'] === 'policy' ? array_diff_key($d, array_flip(['owner_name', 'national_id', 'mobile', 'address', 'postal_code'])) : $d;
        vdoc_apply_to_row($pdo, $id, $dv, $x['type']);
        if ($x['type'] === 'policy' && empty($f['issued'])) {
            $s = $pdo->prepare("SELECT issue_info FROM company_request_plates WHERE id = ?"); $s->execute([$id]);
            $ii = issue_info_decode($s->fetchColumn()); $ch = false;
            foreach (['policy_number' => 'prev_policy_number', 'insurer' => 'prev_insurer', 'end_date' => 'prev_expiry'] as $from => $to)
                if (trim((string)($d[$from] ?? '')) !== '' && trim((string)($ii[$to] ?? '')) === '') { $ii[$to] = mb_substr(trim((string)$d[$from]), 0, 120); $ch = true; }
            if ($ch) $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([json_encode($ii, JSON_UNESCAPED_UNICODE), $id]);
        }
        // نتیجه روی خودِ مدرک هم ذخیره شود (پنل و پرونده‌ی خودرو از آن می‌خوانند)
        if (!empty($f['doc_id'])) {
            $res = !empty($x['result']) ? $x['result'] : ['ok' => true, 'kind' => $x['type'], 'data' => $d];
            $res['by'] = 'issue_panel';
            try {
                vdoc_ensure($pdo);
                $pdo->prepare("UPDATE company_documents SET ai_json = ?, ai_at = NOW() WHERE id = ?")->execute([json_encode($res, JSON_UNESCAPED_UNICODE), intval($f['doc_id'])]);
            } catch (Throwable $e) { error_log('[issue_panel extract] ' . $e->getMessage()); }
        }
    }
    return ip_diff($before, $snap());
}
// درخواستِ کارکنان: اطلاعاتِ صدور (issue_info) و شاسی/موتور — فقط خانه‌های خالی
function ip_apply_case($pdo, $id, array $x, array $f) {
    $snap = function () use ($pdo, $id) {
        $s = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?"); $s->execute([$id]);
        $r = $s->fetch() ?: []; $ii = issue_info_decode($r['issue_info'] ?? null);
        return $ii + ['chassis_num' => $r['chassis_num'] ?? '', 'engine_num' => $r['engine_num'] ?? '', 'vin' => $r['vin'] ?? ''];
    };
    $d = (array)$x['data'];
    $before = $snap();
    $map = ['car_system' => 'car_system', 'car_tip' => 'car_type', 'model_year' => 'car_model_year', 'color' => 'car_color', 'usage' => 'car_usage', 'capacity' => 'car_capacity', 'cylinders' => 'car_cylinders'];
    if (in_array($x['type'], ['car_card', 'ownership_doc', 'sales_invoice'], true)) $map += ['owner_name' => 'owner_name', 'national_id' => 'owner_national_id', 'mobile' => 'owner_phone', 'address' => 'owner_address'];
    if ($x['type'] === 'policy' && empty($f['issued'])) $map += ['policy_number' => 'prev_policy_number', 'insurer' => 'prev_insurer', 'end_date' => 'prev_expiry'];
    if ($x['type'] === 'personnel') $map = ['insured_name' => 'insured_name', 'national_id' => 'insured_national_id'];
    $s = $pdo->prepare("SELECT issue_info FROM policy_cases WHERE id = ?"); $s->execute([$id]);
    $ii = issue_info_decode($s->fetchColumn()); $ch = false;
    foreach ($map as $from => $to)
        if (isset($d[$from]) && is_scalar($d[$from]) && trim((string)$d[$from]) !== '' && trim((string)($ii[$to] ?? '')) === '') { $ii[$to] = mb_substr(trim((string)$d[$from]), 0, 250); $ch = true; }
    if ($ch) $pdo->prepare("UPDATE policy_cases SET issue_info = ? WHERE id = ?")->execute([json_encode($ii, JSON_UNESCAPED_UNICODE), $id]);
    if ($x['type'] !== 'personnel') {
        $code = function ($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); };
        $vin = $code($d['vin'] ?? ''); $eng = $code($d['engine_no'] ?? '');
        try {
            $pdo->prepare("UPDATE policy_cases SET chassis_num = COALESCE(NULLIF(chassis_num, ''), NULLIF(?, '')), engine_num = COALESCE(NULLIF(engine_num, ''), NULLIF(?, '')),
                           vin = COALESCE(NULLIF(vin, ''), NULLIF(?, '')) WHERE id = ?")->execute([$vin, $eng, strlen($vin) === 17 ? $vin : '', $id]);
        } catch (Throwable $e) { error_log('[issue_panel extract] ' . $e->getMessage()); }
    }
    return ip_diff($before, $snap());
}

// ---------------- «استعلامِ خسارت» در پنلِ صدور ----------------
function ip_dmg_info($pdo, $type, $id) {
    require_once __DIR__ . '/_damage_inq.php';
    $t = dmg_target($pdo, $type, $id);
    if (!$t) return null;
    $canEdit = perm_real_role() === 'ADMIN' || (($p = perm_user_custom($pdo, $_SESSION['user_id'] ?? 0)) === null) || perm_allows($p, 'issue-queue:edit|companies-requests:edit|cases:edit');
    return ['applicable' => $t['applicable'], 'why' => $t['why'], 'body' => $t['body'], 'label' => dmg_label($t['body']), 'files' => dmg_files($pdo, $type, $id),
            'stamp' => dmg_has_stamp(), 'can_edit' => $canEdit, 'save' => ['type' => $type, 'id' => intval($id)]];
}
function ip_dmg_upload($pdo, $type, $id, callable $out) {
    require_once __DIR__ . '/_damage_inq.php';
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'فایل دریافت نشد.']);
    if ($f['size'] > 25 * 1048576) $out(['ok' => false, 'error' => 'حجمِ فایل حداکثر ۲۵ مگابایت.']);
    $ext = upload_ext($f['name'] ?? '');
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) $out(['ok' => false, 'error' => 'فقط PDF یا عکس (jpg/png/webp).']);
    $tmp = dirname(__DIR__) . '/tmp_ocr/' . uniqid('dmg_') . '.' . $ext;
    if (!is_dir(dirname($tmp))) @mkdir(dirname($tmp), 0777, true);
    if (!move_uploaded_file($f['tmp_name'], $tmp)) $out(['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.']);
    $r = dmg_save($pdo, $type, $id, $tmp, $_SESSION['user_id'] ?? null);
    if (is_file($tmp)) @unlink($tmp);
    $out($r);
}
