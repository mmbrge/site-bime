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
function ip_doc_files($siteRoot, $rel, $label, $group) {
    $abs = realpath($siteRoot . '/' . ltrim((string)$rel, '/'));
    $root = realpath($siteRoot);
    if (!$abs || strpos($abs, $root . '/') !== 0) return [];
    if (is_file($abs)) return [['label' => $label, 'path' => ip_rel($root, $abs), 'group' => $group]];
    if (!is_dir($abs)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || count($out) >= 80) continue;
        if (!preg_match('/\.(jpe?g|png|webp|gif|pdf)$/i', $f->getFilename()) || preg_match('/\(old\)/u', $f->getFilename())) continue;
        $out[] = ['label' => $label . ' · ' . pathinfo($f->getFilename(), PATHINFO_FILENAME), 'path' => ip_rel($root, $f->getPathname()), 'group' => $group];
    }
    usort($out, function ($a, $b) { return strnatcasecmp($a['path'], $b['path']); });
    return $out;
}

try {
    if ($action !== 'get') $out(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
    $type = ($data['type'] ?? '') === 'P' ? 'P' : 'C';
    $id = intval($data['id'] ?? 0);
    $siteRoot = dirname(__DIR__);
    if (!$id) $out(['ok' => false, 'error' => 'ردیف مشخص نیست.']);

    if ($type === 'C') {
        if (!in_array($role, ['ADMIN', 'COMPANY_LIAISON'], true)) $out(['ok' => false, 'error' => 'به پنلِ صدورِ ردیف‌های شرکتی دسترسی ندارید.']);
        company_ensure_issue_info_cols($pdo); company_ensure_coverage_column($pdo); party_ensure($pdo); companies_extra_ensure($pdo); company_doc_label_ensure($pdo);
        $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.insurer, cr.request_kind, cr.request_text, cr.created_at AS req_at, cr.party_mode AS req_party_mode, cr.party_json AS req_party_json,
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
                            ['party_mode' => $r['req_party_mode'], 'party_json' => $r['req_party_json']]);
        $ins = $pr['insured'] ?: []; $own = $pr['owner'] ?: []; $ben = $pr['beneficiary'] ?: [];
        $plate = company_row_label($r);
        $kind = $r['request_kind'] ?: 'NEW_POLICY';
        $isBody = $r['insurance_type'] === 'BODY';
        $secs = array_values(array_filter([
            ip_sec('بیمه‌گذار', 'fa-user-shield', [ip_f('نام', $ii['insured_name'] ?? '' ?: ($ins['name'] ?? '')), ip_f('کد / شناسه ملی', ($ii['insured_national_id'] ?? '') ?: (($ins['national_id'] ?? '') ?: ($ins['economic_code'] ?? '')), true),
                ip_f('کد اقتصادی', ($ins['kind'] ?? '') === 'COMPANY' ? $r['economic_code'] : '', true), ip_f('تلفن', ($ii['insured_phone'] ?? '') ?: ($ins['phone'] ?? ''), true),
                ip_f('کد پستی', ($ii['insured_postal_code'] ?? '') ?: ($ins['postal_code'] ?? ''), true), ip_f('نشانی', ($ii['insured_address'] ?? '') ?: ($ins['address'] ?? '')), ip_f('نوع مالکیت', $pr['mode_fa'])]),
            ip_sec('مالک', 'fa-user', [ip_f('نام', ($ii['owner_name'] ?? '') ?: (($own['name'] ?? '') ?: ($dd['owner_name'] ?? ''))), ip_f('کد / شناسه ملی', ($ii['owner_national_id'] ?? '') ?: (($own['national_id'] ?? '') ?: ($dd['national_id'] ?? '')), true),
                ip_f('نسبت', $ii['owner_relation'] ?? ''), ip_f('تلفن', ($ii['owner_phone'] ?? '') ?: (($own['phone'] ?? '') ?: ($dd['mobile'] ?? '')), true), ip_f('نشانی', ($ii['owner_address'] ?? '') ?: (($own['address'] ?? '') ?: ($dd['address'] ?? '')))]),
            $ben || !empty($ii['beneficiary']) ? ip_sec('ذینفع', 'fa-hand-holding-heart', [ip_f('نام', ($ii['beneficiary'] ?? '') ?: ($ben['name'] ?? '')), ip_f('کد / شناسه ملی', ($ii['beneficiary_national_id'] ?? '') ?: ($ben['national_id'] ?? ''), true)]) : null,
            ip_sec('خودرو', 'fa-car-side', [ip_f('پلاک', $plate), ip_f('شماره شاسی (VIN)', $r['chassis_no'] ?: ($r['vin'] ?: ($dd['vin'] ?? '')), true), ip_f('شماره موتور', $r['engine_no'] ?: ($dd['engine_no'] ?? ''), true),
                ip_f('نام خودرو', $r['car_name']), ip_f('سیستم', ($ii['car_system'] ?? '') ?: ($dd['car_system'] ?? '')), ip_f('تیپ', ($ii['car_type'] ?? '') ?: ($r['car_type'] ?? '') ?: ($dd['car_tip'] ?? '')),
                ip_f('نوع', $ii['car_kind'] ?? ''), ip_f('مدل (سال ساخت)', ($ii['car_model_year'] ?? '') ?: ($dd['model_year'] ?? ''), true), ip_f('رنگ', ($ii['car_color'] ?? '') ?: ($dd['color'] ?? '')),
                ip_f('کاربری', ($ii['car_usage'] ?? '') ?: ($dd['usage'] ?? '')), ip_f('ظرفیت', ($ii['car_capacity'] ?? '') ?: ($dd['capacity'] ?? ''), true), ip_f('تعداد سیلندر', ($ii['car_cylinders'] ?? '') ?: ($dd['cylinders'] ?? ''), true),
                ip_f('صفر کیلومتر', !empty($r['is_new_vehicle']) ? 'بله' : '')]),
            ip_sec('درخواست', 'fa-clipboard-list', [ip_f('شرکت', $r['company_name']), ip_f('شماره درخواست', ref_code_of($pdo, 'company_requests', (int)$r['request_id']) ?: ('#' . $r['request_id'])), ip_f('تاریخ درخواست', ip_jd($r['req_at']), true),
                ip_f('نوع درخواست', company_request_kind_fa($kind)), ip_f('نوع بیمه', $isBody ? 'بدنه' : 'ثالث'), ip_f('بیمه‌گر', $r['insurer'] === 'IRAN' ? 'ایران' : 'پاسارگاد'),
                ip_f('انقضای بیمه‌نامه‌ی قبلی', ip_jd($r['expiry_date']), true), ip_f('بیمه‌نامه‌ی قبلی / مرجع', ($r['ref_policy_number'] ?? '') ?: ($ii['prev_policy_number'] ?? ''), true), ip_f('بیمه‌گرِ قبلی', $ii['prev_insurer'] ?? ''),
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
            $files = array_merge($files, ip_doc_files($siteRoot, $d['file_path'], $lab, $d['doc_type'] === 'health_inspection' ? 'بازدید' : ($d['doc_type'] === 'letter' ? 'نامه' : 'مدارک')));
        }
        if (!empty($r['issued_file_path'])) $files = array_merge(ip_doc_files($siteRoot, $r['issued_file_path'], 'بیمه‌نامه‌ی صادرشده', 'صدور'), $files);
        $out(['ok' => true, 'type' => 'C', 'id' => $id, 'request_id' => (int)$r['request_id'], 'status' => $r['status'], 'status_fa' => company_plate_status_fa($r['status'], $kind),
              'title' => $plate ?: ($r['chassis_no'] ?: 'ردیف شرکتی'), 'subtitle' => trim($r['company_name'] . ' · ' . ($isBody ? 'بدنه' : 'ثالث') . ($r['car_name'] ? ' · ' . $r['car_name'] : '')),
              'kind' => $kind, 'sections' => $secs, 'files' => $files, 'can_issue' => $role === 'ADMIN' && !in_array($r['status'], ['ISSUED', 'CANCELLED'], true),
              'vehicle_key' => ($pc = plate_core($plate)) ? 'p:' . p2e_digits($pc) : (($v = brd_vin_key_safe($r['chassis_no'] ?: $r['vin'])) ? 'v:' . $v : '')]);
    }

    // P: درخواستِ کارکنان
    if ($role !== 'ADMIN') $out(['ok' => false, 'error' => 'به پنلِ صدورِ کارکنان دسترسی ندارید.']);
    $st = $pdo->prepare("SELECT pc.*, p.full_name, p.national_code, p.mobile_number, p.personnel_code, co.name AS employer FROM policy_cases pc
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
        ip_sec('بیمه‌گذار', 'fa-user-shield', [ip_f('نام', ($ii['insured_name'] ?? '') ?: $g('insured_name')), ip_f('کد ملی', ($ii['insured_national_id'] ?? '') ?: $g('insured_national_id'), true), ip_f('نسبت با پرسنل', $g('insured_relationship')),
            ip_f('تلفن', ($ii['insured_phone'] ?? '') ?: $g('insured_phone'), true), ip_f('کد پستی', ($ii['insured_postal_code'] ?? '') ?: $g('insured_postal_code'), true), ip_f('نشانی', ($ii['insured_address'] ?? '') ?: $g('insured_address'))]),
        ip_sec('مالک', 'fa-user', [ip_f('نام', $ii['owner_name'] ?? ''), ip_f('کد ملی', $ii['owner_national_id'] ?? '', true), ip_f('نسبت', $ii['owner_relation'] ?? ''), ip_f('تلفن', $ii['owner_phone'] ?? '', true)]),
        !empty($ii['beneficiary']) ? ip_sec('ذینفع', 'fa-hand-holding-heart', [ip_f('نام', $ii['beneficiary']), ip_f('کد ملی', $ii['beneficiary_national_id'] ?? '', true)]) : null,
        ip_sec('خودرو', 'fa-car-side', [ip_f('پلاک', $g('plate')), ip_f('شماره شاسی', $g('chassis_num') ?: ($g('vin') ?: ($oc['vin'] ?? '')), true), ip_f('شماره موتور', $g('engine_num') ?: ($oc['engine_no'] ?? ''), true),
            ip_f('سیستم', ($ii['car_system'] ?? '') ?: $g('car_system')), ip_f('تیپ', ($ii['car_type'] ?? '') ?: $g('car_type')), ip_f('مدل', ($ii['car_model_year'] ?? '') ?: $g('car_model_year'), true),
            ip_f('رنگ', ($ii['car_color'] ?? '') ?: $g('car_color')), ip_f('کاربری', ($ii['car_usage'] ?? '') ?: $g('car_usage')), ip_f('ظرفیت', $ii['car_capacity'] ?? '', true), ip_f('تعداد سیلندر', $ii['car_cylinders'] ?? '', true)]),
        ip_sec('درخواست', 'fa-clipboard-list', [ip_f('کد پرونده', $g('unique_code'), true), ip_f('نوع بیمه', $isBody ? 'بدنه' : 'ثالث'), ip_f('تاریخ ثبت', ip_jd($g('created_at')), true),
            $isBody ? ip_f('ارزش خودرو', ip_money($g('car_value') ?: $g('estimated_car_value'))) : ip_f('تعهد مالی', ip_money($g('liability_limit'))),
            $isBody ? ip_f('پوشش‌ها', implode('، ', $covFa)) : null, ip_f('انقضای بیمه‌نامه‌ی قبلی', $g('prev_expiry_date') ?: ($ii['prev_expiry'] ?? ''), true),
            ip_f('بیمه‌نامه‌ی قبلی', ($ii['prev_policy_number'] ?? '') ?: $g('prev_policy_number'), true), ip_f('بیمه‌گرِ قبلی', $ii['prev_insurer'] ?? ''), ip_f('یادداشت صدور', $ii['note'] ?? '')]),
        ($c['status'] ?? '') === 'ISSUED' ? ip_sec('صدور', 'fa-stamp', [ip_f('شماره بیمه‌نامه', $g('policy_number'), true), ip_f('تاریخ صدور', $g('policy_issue_date') ?: ip_jd($g('issued_at')), true), ip_f('حق بیمه', ip_money($g('total_premium')))]) : null,
    ]));
    $files = [];
    if (!empty($c['issued_file_path'])) $files = ip_doc_files($siteRoot, $c['issued_file_path'], 'بیمه‌نامه‌ی صادرشده', 'صدور');
    $req = company_doc_types();
    try { foreach ([['BODY', 'سند', 'بله', 'خود'], ['BODY', 'کارت', 'بله', 'پدر'], [$g('insurance_type'), $g('ownership_choice'), $g('prev_body_insurance'), $g('insured_relationship')]] as $a) $req = get_required_docs_v2(...$a) + $req; } catch (Throwable $e) {}
    foreach ($pdo->query("SELECT * FROM case_documents WHERE case_id = " . intval($id) . " ORDER BY id") as $d) {
        if (($d['status'] ?? '') === 'REJECTED') continue;
        $lab = ($d['doc_label'] ?? '') ?: ($req[$d['doc_key'] ?? ($d['doc_type'] ?? '')] ?? (($d['doc_key'] ?? '') ?: ($d['doc_type'] ?? 'مدرک')));
        $files = array_merge($files, ip_doc_files($siteRoot, $d['file_path'], $lab, 'مدارک'));
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
    $plate = (string)$g('plate');
    $stFa = ['REGISTERED' => 'ثبت شده', 'AWAITING_DOCS' => 'در انتظار بارگذاری مدارک', 'DOCS_PENDING' => 'در انتظار مدارک', 'DOCS_REVIEW' => 'در انتظار تایید مدارک',
             'ISSUING' => 'در حال صدور', 'ISSUED' => 'صادر شده', 'REJECTED' => 'رد شده', 'WITHDRAWN' => 'خارج از فاز عملیاتی'];
    $out(['ok' => true, 'type' => 'P', 'id' => $id, 'status' => $c['status'] ?? '', 'status_fa' => $stFa[$c['status'] ?? ''] ?? ($c['status'] ?? ''),
          'title' => $plate ?: ($c['full_name'] ?: 'پرونده‌ی کارکنان'), 'subtitle' => trim(($c['full_name'] ?? '') . ' · ' . ($isBody ? 'بدنه' : 'ثالث') . ($c['employer'] ? ' · ' . $c['employer'] : '')),
          'sections' => $secs, 'files' => $files, 'can_issue' => !in_array($c['status'] ?? '', ['ISSUED', 'REJECTED', 'WITHDRAWN'], true),
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
