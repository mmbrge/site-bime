<?php
// فایل: api/party_actions.php
// «پرونده‌ی اشخاص و شرکت‌ها»: فهرست، پرونده‌ی هر شخصِ حقیقی/حقوقی (آخرین اطلاعات، بیمه‌نامه‌ها به‌عنوانِ بیمه‌گذار/مالک،
// درخواست‌های شرکت، پیوند به پرونده‌ی پرسنل)، ویرایشِ اطلاعات. منطق: api/_parties.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require __DIR__ . '/_parties.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
if (($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'دسترسی به «پرونده‌ی اشخاص و شرکت‌ها» ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']);
parties_ensure($pdo);
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
$uid = intval($_SESSION['user_id']);

// یک‌بار: شرکت‌های تعریف‌شده و پرسنل (با کد/شناسه‌ی ملی) هم در دفتر (بدونِ بازنویسیِ اطلاعاتِ موجود)
function parties_seed($pdo) {
    $flag = 'parties_seed_v1';
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $st->execute([$flag]); if ($st->fetchColumn()) return; } catch (Throwable $e) { return; }
    $have = array_flip($pdo->query("SELECT nid FROM parties")->fetchAll(PDO::FETCH_COLUMN));
    try {
        foreach ($pdo->query("SELECT name, national_id, economic_code, phone, address, postal_code, register_number FROM companies WHERE COALESCE(is_import, 0) = 0") as $c) {
            $nid = parties_nid($c['national_id']) ?: parties_nid($c['economic_code']);
            if ($nid === '' || isset($have[$nid])) continue;
            parties_save($pdo, $nid, ['name' => $c['name'], 'phone' => $c['phone'], 'address' => $c['address'], 'postal_code' => $c['postal_code'], 'economic_code' => $c['economic_code'], 'register_no' => $c['register_number']], 'company');
            $have[$nid] = 1;
        }
        foreach ($pdo->query("SELECT national_code, full_name, mobile_number FROM persons WHERE national_code IS NOT NULL AND national_code <> ''") as $p) {
            $nid = parties_nid($p['national_code']);
            if ($nid === '' || isset($have[$nid])) continue;
            parties_save($pdo, $nid, ['name' => $p['full_name'], 'mobile' => $p['mobile_number']], 'personnel');
            $have[$nid] = 1;
        }
        // بیمه‌گذار/مالکِ ردیف‌های صادرشده
        foreach ($pdo->query("SELECT issue_info FROM company_request_plates WHERE status = 'ISSUED' AND issue_info IS NOT NULL ORDER BY id") as $r) {
            $ii = json_decode((string)$r['issue_info'], true) ?: [];
            foreach (['insured', 'owner'] as $role) {
                $nid = parties_nid($ii[$role . '_national_id'] ?? '');
                if ($nid === '' || isset($have[$nid])) continue;
                parties_record_issue_info($pdo, array_intersect_key($ii, array_flip([$role . '_national_id', $role . '_name', $role . '_phone', $role . '_address', $role . '_postal_code'])), 'issue');
                $have[$nid] = 1;
            }
        }
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$flag, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { error_log('[parties_seed] ' . $e->getMessage()); }
}

// بیمه‌نامه‌هایی که این کد/شناسه بیمه‌گذار یا مالکِ آن‌هاست (شرکتی و کارکنان)
function parties_policies($pdo, $nid) {
    $out = [];
    $q = '"' . $nid . '"';
    try {
        $st = $pdo->prepare("SELECT crp.id, crp.request_id, crp.status, crp.insurance_type, crp.policy_number, crp.policy_issue_date, crp.issued_at, crp.expiry_date, crp.issue_info,
                                    crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no, crp.car_name, c.name AS company_name
                               FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                              WHERE crp.issue_info LIKE ? OR crp.issue_info LIKE ? ORDER BY crp.id DESC LIMIT 200");
        $st->execute(['%"insured_national_id":' . $q . '%', '%"owner_national_id":' . $q . '%']);
        foreach ($st->fetchAll() as $r) {
            $ii = json_decode((string)$r['issue_info'], true) ?: [];
            $roles = [];
            if (parties_nid($ii['insured_national_id'] ?? '') === $nid) $roles[] = 'بیمه‌گذار';
            if (parties_nid($ii['owner_national_id'] ?? '') === $nid) $roles[] = 'مالک';
            $out[] = ['src' => 'C', 'id' => (int)$r['id'], 'request_id' => (int)$r['request_id'], 'status' => $r['status'], 'status_fa' => company_plate_status_fa($r['status']),
                      'type' => $r['insurance_type'] === 'BODY' ? 'بدنه' : 'ثالث', 'policy' => $r['policy_number'], 'issue_date' => $r['policy_issue_date'] ?: ($r['issued_at'] ? jalali_from_gregorian_ts_dotted(strtotime($r['issued_at'])) : ''),
                      'plate' => company_row_label($r), 'car' => $r['car_name'], 'company' => $r['company_name'], 'roles' => $roles];
        }
    } catch (Throwable $e) { error_log('[parties_policies C] ' . $e->getMessage()); }
    if (strlen($nid) === 10) {
        try {
            $st = $pdo->prepare("SELECT pc.id, pc.status, pc.insurance_type, pc.policy_number, pc.policy_issue_date, pc.issued_at, pc.plate, pc.car_system, pc.car_type, pc.insured_relationship, p.full_name AS holder
                                   FROM policy_cases pc LEFT JOIN persons p ON p.id = pc.person_id WHERE pc.insured_national_id = ? OR p.national_code = ? ORDER BY pc.id DESC LIMIT 200");
            $st->execute([$nid, $nid]);
            foreach ($st->fetchAll() as $r) $out[] = ['src' => 'P', 'id' => (int)$r['id'], 'status' => $r['status'], 'status_fa' => $r['status'] === 'ISSUED' ? 'صادر شده' : 'در جریان',
                'type' => $r['insurance_type'] === 'BODY' ? 'بدنه' : 'ثالث', 'policy' => $r['policy_number'], 'issue_date' => $r['policy_issue_date'] ?: ($r['issued_at'] ? jalali_from_gregorian_ts_dotted(strtotime($r['issued_at'])) : ''),
                'plate' => $r['plate'], 'car' => trim($r['car_system'] . ' ' . $r['car_type']), 'company' => 'کارکنان · ' . $r['holder'], 'roles' => [$r['insured_relationship'] && $r['insured_relationship'] !== 'خودم' ? 'بیمه‌گذار (' . $r['insured_relationship'] . ')' : 'بیمه‌گذار / پرسنل']];
        } catch (Throwable $e) { error_log('[parties_policies P] ' . $e->getMessage()); }
    }
    return $out;
}

try {
    if ($action === 'list') {
        parties_seed($pdo);
        $w = ['1=1']; $p = [];
        $kind = (string)($data['kind'] ?? '');
        if (in_array($kind, ['PERSON', 'COMPANY'], true)) { $w[] = 'kind = ?'; $p[] = $kind; }
        $q = trim(p2e_digits((string)($data['q'] ?? '')));
        if ($q !== '') { $w[] = '(name LIKE ? OR nid LIKE ? OR phone LIKE ? OR mobile LIKE ? OR economic_code LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
        $st = $pdo->prepare("SELECT * FROM parties WHERE " . implode(' AND ', $w) . " ORDER BY updated_at DESC, id DESC LIMIT 500");
        $st->execute($p);
        $rows = array_map(function ($r) { return ['nid' => $r['nid'], 'kind' => $r['kind'], 'name' => $r['name'], 'phone' => $r['phone'] ?: $r['mobile'], 'address' => $r['address'],
                                                   'postal_code' => $r['postal_code'], 'source' => $r['source'], 'updated_at' => $r['updated_at'] ? jalali_from_gregorian_ts_dotted(strtotime($r['updated_at'])) : '']; }, $st->fetchAll());
        $cnt = $pdo->query("SELECT kind, COUNT(*) n FROM parties GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out(['ok' => true, 'rows' => $rows, 'counts' => ['PERSON' => (int)($cnt['PERSON'] ?? 0), 'COMPANY' => (int)($cnt['COMPANY'] ?? 0)]]);
    }
    if ($action === 'detail') {
        $nid = parties_nid($data['nid'] ?? '');
        if ($nid === '') $out(['ok' => false, 'error' => 'کد ملیِ ۱۰ رقمی یا شناسه‌ی ملیِ ۱۱ رقمی را بنویسید.']);
        $p = parties_get($pdo, $nid);
        $st = $pdo->prepare("SELECT note, created_at FROM parties WHERE nid = ?"); $st->execute([$nid]); $extra = $st->fetch() ?: [];
        $info = $p ? parties_public($p) : ['nid' => $nid, 'kind' => parties_kind($nid)];
        $info['note'] = $extra['note'] ?? '';
        $info['updated_at'] = $p && $p['updated_at'] ? jalali_from_gregorian_ts_dotted(strtotime($p['updated_at'])) : '';
        // درخواست‌های شرکت (اگر شرکتِ تعریف‌شده است)
        $reqs = [];
        if (!empty($info['company_id'])) {
            $st = $pdo->prepare("SELECT cr.id, cr.ref_code, cr.request_kind, cr.status, cr.created_at, (SELECT COUNT(*) FROM company_request_plates x WHERE x.request_id = cr.id) AS n,
                                        (SELECT COUNT(*) FROM company_request_plates x WHERE x.request_id = cr.id AND x.status = 'ISSUED') AS issued
                                   FROM company_requests cr WHERE cr.company_id = ? ORDER BY cr.id DESC LIMIT 100");
            $st->execute([$info['company_id']]);
            $reqs = array_map(function ($r) { return ['id' => (int)$r['id'], 'ref' => $r['ref_code'], 'kind' => company_request_kind_fa($r['request_kind'] ?? 'NEW_POLICY'), 'date' => jalali_from_gregorian_ts_dotted(strtotime($r['created_at'])),
                                                      'rows' => (int)$r['n'], 'issued' => (int)$r['issued']]; }, $st->fetchAll());
        }
        // استعلام‌های انجام‌شده
        $inq = [];
        try {
            $st = $pdo->prepare("SELECT id, kind, status, notice, created_at FROM inq_requests WHERE national_id = ? ORDER BY id DESC LIMIT 20");
            $st->execute([$nid]);
            $inq = array_map(function ($r) { return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'status' => $r['status'], 'notice' => mb_substr((string)$r['notice'], 0, 160), 'date' => $r['created_at'] ? jalali_from_gregorian_ts_dotted(strtotime($r['created_at'])) : '']; }, $st->fetchAll());
        } catch (Throwable $e) {}
        $out(['ok' => true, 'party' => $info, 'policies' => parties_policies($pdo, $nid), 'requests' => $reqs, 'inquiries' => $inq]);
    }
    if ($action === 'save') {
        $nid = parties_nid($data['nid'] ?? '');
        if ($nid === '') $out(['ok' => false, 'error' => 'کد/شناسه‌ی ملی نامعتبر است.']);
        $d = is_array($data['info'] ?? null) ? $data['info'] : [];
        parties_save($pdo, $nid, $d, 'manual', $uid);
        if (array_key_exists('note', $d)) $pdo->prepare("UPDATE parties SET note = ? WHERE nid = ?")->execute([mb_substr(trim((string)$d['note']), 0, 2000) ?: null, $nid]);
        $out(['ok' => true, 'party' => parties_public(parties_get($pdo, $nid))]);
    }
    // ---- مشتریانِ شخصی (اشخاص و شرکت‌های بی‌قرارداد): نمونه‌ی اکسل، ورود از اکسل، فهرست ----
    if ($action === 'pc_sample') {
        require_once __DIR__ . '/_xlsx_writer.php';
        $path = xlsx_build(['نوع (حقیقی / حقوقی)', 'نام و نام خانوادگی / نام شرکت *', 'کد ملی / شناسه ملی', 'موبایل', 'تلفن', 'نشانی', 'کد پستی', 'توضیح'],
                           [['حقیقی', 'علی رضایی', '0012345678', '09121234567', '', 'تهران، خیابان ...', '', ''], ['حقوقی', 'شرکت نمونه آریا', '10101234567', '', '02188888888', 'تهران، ...', '1234567890', '']],
                           'مشتریان شخصی');
        xlsx_send($path, 'نمونه ورود مشتریان شخصی.xlsx');
        exit;
    }
    if ($action === 'pc_import') {
        $f = $_FILES['file'] ?? null;
        if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) $out(['ok' => false, 'error' => 'فایلِ اکسل دریافت نشد.']);
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) $out(['ok' => false, 'error' => 'فقط xlsx، xls یا csv.']);
        require_once __DIR__ . '/_xlsx_writer.php';
        $sheets = xlsx_read_sheets($f['tmp_name'], $ext);
        $rows = $sheets ? reset($sheets) : [];
        if (!$rows) $out(['ok' => false, 'error' => 'ردیفی در فایل پیدا نشد.']);
        $head = array_map(function ($h) { return trim(str_replace(['ي', 'ك', '*'], ['ی', 'ک', ''], (string)$h)); }, array_shift($rows));
        $col = function (array $keys) use ($head) { foreach ($head as $i => $h) foreach ($keys as $k) if ($h !== '' && mb_strpos($h, $k) !== false) return $i; return null; };
        $c = ['kind' => $col(['نوع']), 'name' => $col(['نام']), 'nid' => $col(['کد ملی', 'شناسه', 'کدملی']), 'mobile' => $col(['موبایل', 'همراه']), 'phone' => $col(['تلفن']),
              'address' => $col(['نشانی', 'آدرس']), 'postal' => $col(['پستی']), 'note' => $col(['توضیح'])];
        if ($c['name'] === null) $out(['ok' => false, 'error' => 'ستونِ «نام» پیدا نشد؛ از فایلِ نمونه استفاده کنید.']);
        $v = function ($r, $k) use ($c) { return $c[$k] === null ? '' : trim((string)($r[$c[$k]] ?? '')); };
        $made = 0; $upd = 0; $bad = [];
        foreach ($rows as $i => $r) {
            $name = $v($r, 'name');
            if ($name === '') continue;
            $nid = preg_replace('/\D/', '', p2e_digits($v($r, 'nid')));
            if ($nid !== '' && strlen($nid) < 10 && strlen($nid) >= 8) $nid = str_pad($nid, 10, '0', STR_PAD_LEFT);   // صفرهای اولِ کد ملی که اکسل می‌اندازد
            $legal = mb_strpos($v($r, 'kind'), 'حقوق') !== false || strlen($nid) === 11;
            $st = $pdo->prepare("SELECT id FROM companies WHERE customer_kind = 'PERSONAL' AND ((? <> '' AND national_id = ?) OR name = ?) LIMIT 1");
            $st->execute([$nid, $nid, $name]);
            $was = (int)$st->fetchColumn();
            $res = personal_customer_upsert($pdo, ['name' => $name, 'national_id' => $nid, 'kind' => $legal ? 'LEGAL' : 'PERSON', 'mobile' => $v($r, 'mobile'), 'address' => $v($r, 'address')]);
            if (empty($res['ok'])) { $bad[] = 'ردیفِ ' . ($i + 2) . ': ' . ($res['error'] ?? 'خطا'); continue; }
            $id = (int)$res['id'];
            $ph = trim(p2e_digits($v($r, 'phone'))); $ad = $v($r, 'address');
            if ($ph !== '') $pdo->prepare("UPDATE companies SET phone = ? WHERE id = ?")->execute([$ph, $id]);
            if ($ad !== '') $pdo->prepare("UPDATE companies SET address = ? WHERE id = ?")->execute([$ad, $id]);
            if ($nid !== '') parties_save($pdo, $nid, ['name' => $name, 'mobile' => $v($r, 'mobile'), 'phone' => $ph, 'address' => $ad, 'postal_code' => preg_replace('/\D/', '', p2e_digits($v($r, 'postal'))), 'note' => $v($r, 'note')], 'import', $uid);
            $was ? $upd++ : $made++;
        }
        $out(['ok' => true, 'made' => $made, 'updated' => $upd, 'errors' => array_slice($bad, 0, 20)]);
    }
    if ($action === 'pc_list') $out(['ok' => true, 'customers' => personal_customers_list($pdo)]);
    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[party_actions] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 200)]);
}
