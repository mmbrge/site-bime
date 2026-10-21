<?php
// فایل: api/_bordereau.php
// «بردروی پاسارگاد»: اکسلِ بردروی بیمه‌گر (همان خروجیِ «بایگانی وارداتی») فقط برای فهرست، تمدید و پرونده‌ی خودرو/اشخاص.
//   - به مالی (اقساط، صورتحساب) و «صادره‌ها» وصل نیست؛ جدول‌های جدا: brd_batches، brd_rows، brd_endorsements، brd_notes
//   - هر بیمه‌نامه یک ردیف (کلید: شماره بیمه‌نامه)؛ آپلودِ دوباره همان ردیف را به‌روز می‌کند (الحاقیه‌ها و پیگیری‌ها می‌مانند)
//   - شرکت با شناسه‌ی ملی / کد اقتصادی / نام، و شخص با کد ملی (persons) شناخته می‌شود
//   - برای هر ردیف پوشه در بایگانی: بایگانی بردرو / سالِ صدور / ماه / گروه (شرکت یا بیمه‌گذار) / «پلاک - گروه - بیمه‌نامه - VIN»
// نیاز: _case_helpers.php، _company_helpers.php، finance_core.php و _import_archive.php پیش از این فایل.

function brd_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS brd_batches (id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(255) NULL, rows_total INT NOT NULL DEFAULT 0,
            rows_new INT NOT NULL DEFAULT 0, rows_updated INT NOT NULL DEFAULT 0, rows_bad INT NOT NULL DEFAULT 0, created_by INT NULL, created_by_name VARCHAR(190) NULL,
            created_at DATETIME NOT NULL)$T",
        "CREATE TABLE IF NOT EXISTS brd_rows (
            id INT AUTO_INCREMENT PRIMARY KEY, batch_id INT NULL, policy_number VARCHAR(80) NOT NULL, insurance_type VARCHAR(12) NULL,
            insured VARCHAR(190) NULL, insured_id VARCHAR(20) NULL, insured_kind VARCHAR(40) NULL, company_id INT NULL, person_id INT NULL,
            group_name VARCHAR(190) NULL, contract_party VARCHAR(190) NULL, contract_no VARCHAR(60) NULL,
            plate_p1 VARCHAR(4) NULL, plate_p2 VARCHAR(5) NULL, plate_letter VARCHAR(8) NULL, plate_p4 VARCHAR(4) NULL, plate_core VARCHAR(30) NULL,
            vin VARCHAR(40) NULL, engine_no VARCHAR(40) NULL, car_name VARCHAR(150) NULL, car_system VARCHAR(100) NULL, car_tip VARCHAR(100) NULL, car_kind VARCHAR(100) NULL,
            model_year VARCHAR(10) NULL, color VARCHAR(60) NULL, car_usage VARCHAR(100) NULL,
            issue_date VARCHAR(10) NULL, start_date VARCHAR(10) NULL, end_date VARCHAR(10) NULL, end_g DATE NULL,
            premium BIGINT NULL, car_value BIGINT NULL, liability BIGINT NULL, phone VARCHAR(40) NULL, postal_code VARCHAR(12) NULL, address VARCHAR(600) NULL,
            owner_name VARCHAR(190) NULL, prev_insurer VARCHAR(100) NULL, prev_policy VARCHAR(80) NULL, central_no VARCHAR(60) NULL,
            data_json MEDIUMTEXT NULL, raw_json MEDIUMTEXT NULL, folder_path VARCHAR(600) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'ACTIVE', created_at DATETIME NOT NULL, updated_at DATETIME NULL,
            UNIQUE KEY uq_policy (policy_number), KEY idx_plate (plate_core), KEY idx_vin (vin), KEY idx_company (company_id), KEY idx_person (person_id),
            KEY idx_insured (insured_id), KEY idx_end (end_g))$T",
        "CREATE TABLE IF NOT EXISTS brd_endorsements (id INT AUTO_INCREMENT PRIMARY KEY, row_id INT NOT NULL, kind VARCHAR(12) NOT NULL, endorse_no VARCHAR(60) NULL,
            date_j VARCHAR(10) NULL, amount BIGINT NULL, new_end_date VARCHAR(10) NULL, note VARCHAR(600) NULL, file_path VARCHAR(600) NULL,
            created_by INT NULL, created_by_name VARCHAR(190) NULL, created_at DATETIME NOT NULL, KEY idx_row (row_id))$T",
        "CREATE TABLE IF NOT EXISTS brd_notes (id INT AUTO_INCREMENT PRIMARY KEY, row_id INT NOT NULL, channel VARCHAR(20) NOT NULL, note VARCHAR(600) NULL,
            created_by INT NULL, created_by_name VARCHAR(190) NULL, created_at DATETIME NOT NULL, KEY idx_row (row_id))$T",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[brd_ensure] ' . $e->getMessage()); } }
    // الحاقیه‌ی «تغییرِ اطلاعات»: مقدارهای تازه‌ی ردیف (پلاک، ارزش، تعهد، پوشش‌ها، بیمه‌گذار، …)؛ اطلاعاتِ اولیه‌ی ردیف دست نمی‌خورد
    try { if (!$pdo->query("SHOW COLUMNS FROM brd_endorsements LIKE 'changes_json'")->fetch()) $pdo->exec("ALTER TABLE brd_endorsements ADD COLUMN changes_json TEXT NULL"); }
    catch (Throwable $e) { error_log('[brd_ensure changes] ' . $e->getMessage()); }
}

// فیلدهایی که الحاقیه می‌تواند عوض کند => برچسب
const BRD_CHANGE_FIELDS = ['plate_p1' => 'پلاک (ایران)', 'plate_p2' => 'پلاک (سه رقم)', 'plate_letter' => 'پلاک (حرف)', 'plate_p4' => 'پلاک (دو رقم)', 'vin' => 'شماره شاسی',
    'engine_no' => 'شماره موتور', 'car_name' => 'خودرو', 'model_year' => 'مدل', 'color' => 'رنگ', 'car_usage' => 'کاربری', 'insured' => 'بیمه‌گذار', 'insured_id' => 'کد / شناسه ملی',
    'insured_kind' => 'حقیقی / حقوقی', 'owner_name' => 'مالک', 'phone' => 'تلفن', 'postal_code' => 'کد پستی', 'address' => 'نشانی', 'car_value' => 'ارزش خودرو', 'liability' => 'تعهد مالی',
    'coverages' => 'پوشش‌ها'];

// متنِ «نوع پوشش اضافی»ِ اکسلِ پاسارگاد => کلیدهای پوشش‌های خودمان (همان selected_coverages)
function brd_cov_from_text($t) {
    $t = str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], (string)$t);
    if (trim($t) === '') return null;
    $map = ['war_damage' => '/جنگ/u', 'price_fluctuation' => '/نوسان|افزایش\s*(ارزش|قیمت)/u', 'paint_acid' => '/رنگ|اسید|شیمیایی/u', 'glass_break' => '/شیشه/u',
            'natural_disaster' => '/سیل|زلزله|آتشفشان|تگرگ|طوفان|بهمن|بلایای\s*طبیعی/u', 'parts_theft' => '/سرقت\s*(لوازم|قطعات|درجا)/u', 'transport_cost' => '/ایاب|ذهاب/u',
            'depreciation_waiver' => '/استهلاک/u', 'deductible_adjust' => '/فرانشیز/u'];
    $opts = function_exists('body_coverage_options') ? body_coverage_options() : [];
    foreach ($opts as $k => $o) if (!isset($map[$k])) $map[$k] = '/' . preg_quote(mb_substr(preg_replace('/\s*\(.*$/u', '', $o['label']), 0, 14), '/') . '/u';
    $out = [];
    $segs = preg_split('/\s*[،,؛;|\n]+\s*|\s+-\s+/u', $t) ?: [$t];
    foreach ($map as $k => $re) {
        if (!isset($opts[$k]) || !preg_match($re, $t)) continue;
        $tiers = $opts[$k]['tiers'];
        if ($tiers) {
            $v = array_key_first($tiers);
            // سطحِ پوشش (درصد/مبلغ) فقط از همان تکه‌ای که نامِ پوشش در آن آمده
            $seg = ''; foreach ($segs as $sg) if (preg_match($re, $sg)) { $seg = p2e_digits(str_replace(',', '', $sg)); break; }
            foreach (array_keys($tiers) as $tk) if ($seg !== '' && preg_match('/(?<!\d)' . preg_quote((string)$tk, '/') . '(?!\d)/', $seg)) { $v = (string)$tk; break; }
            $out[$k] = $v;
        } else $out[$k] = true;
    }
    return $out;
}
// «حقیقی/حقوقی» از ستونِ اکسل یا طولِ شناسه
function brd_kind_norm($kind, $id = '') {
    $k = str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$kind);
    if (mb_strpos($k, 'حقوقی') !== false) return 'LEGAL';
    if (mb_strpos($k, 'حقیقی') !== false) return 'PERSON';
    $d = preg_replace('/\D/', '', p2e_digits((string)$id));
    return strlen($d) === 11 ? 'LEGAL' : (strlen($d) === 10 ? 'PERSON' : '');
}

const BRD_ENDORSE_KINDS = ['ADD' => 'اضافی (افزایشِ حق بیمه)', 'REFUND' => 'برگشتی (کاهشِ حق بیمه)', 'CHANGE' => 'تغییرِ مشخصات', 'EXTEND' => 'تغییرِ مدت / تمدید', 'CANCEL' => 'فسخ'];
const BRD_CHANNELS = ['phone' => 'تماس تلفنی', 'sms' => 'پیامک', 'bot' => 'ربات / پیام', 'letter' => 'نامه', 'visit' => 'حضوری', 'other' => 'سایر',
                      'declined' => 'تمدید نمی‌کند', 'renewed_out' => 'جای دیگر تمدید کرد', 'renewed' => 'تمدید شد'];

function brd_archive_root($siteRoot) { return archive_root($siteRoot) . '/بایگانی بردرو'; }
function brd_norm($s) { return company_normalize_header((string)$s); }
function brd_vin_key($v) { $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); return strlen($v) >= 6 ? $v : ''; }
function brd_plate_core($p1, $p2, $letter, $p4) {
    $d = company_plate_display($p1, $p2, $letter, $p4);
    return $d ? p2e_digits(plate_core($d)) : '';
}

// خواندنِ اکسل با همان تجزیه‌گرِ «بایگانی وارداتی»؛ تکراری بودن با صادره‌ها/وارداتی‌ها این‌جا خطا نیست
function brd_parse($pdo, $path) {
    $p = imp_parse_excel($pdo, $path);
    if (isset($p['error'])) return $p;
    $have = [];
    $st = $pdo->query("SELECT policy_number FROM brd_rows");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pn) $have[(string)$pn] = 1;
    foreach ($p['rows'] as &$r) {
        if (in_array($r['dup'], ['import', 'system'], true)) { array_pop($r['errors']); $r['ok'] = !$r['errors']; }
        $r['exists'] = isset($have[(string)$r['policy']]);
    }
    unset($r);
    return $p;
}

// شرکتِ ما (نه وارداتی) با شناسه‌ی ملی، کد اقتصادی یا نامِ یکسان
function brd_company_index($pdo) {
    $idx = ['id' => [], 'name' => []];
    try {
        foreach ($pdo->query("SELECT id, name, economic_code, national_id FROM companies WHERE COALESCE(is_import, 0) = 0") as $c) {
            foreach ([$c['national_id'], $c['economic_code']] as $k) { $k = preg_replace('/\D/', '', p2e_digits((string)$k)); if (strlen($k) >= 8) $idx['id'][$k] = (int)$c['id']; }
            $idx['name'][brd_norm($c['name'])] = (int)$c['id'];
        }
    } catch (Throwable $e) {}
    return $idx;
}
function brd_match_company(array $idx, array $pf) {
    foreach ([$pf['national_id'] ?? '', $pf['economic_code'] ?? ''] as $k) { $k = preg_replace('/\D/', '', (string)$k); if (strlen($k) >= 8 && isset($idx['id'][$k])) return $idx['id'][$k]; }
    foreach ([$pf['contract_party'] ?? '', $pf['insured_name'] ?? ''] as $n) { $n = brd_norm($n); if ($n !== '' && isset($idx['name'][$n])) return $idx['name'][$n]; }
    return null;
}

// پوشه‌ی بایگانی: بایگانی بردرو / سال / ماه / گروه / «پلاک - گروه - بیمه‌نامه - VIN» (همان نام‌گذاریِ صادره‌ها)
function brd_folder_for($siteRoot, array $r) {
    $issue = (string)($r['issue_date'] ?? '');
    if (preg_match('/^(1[34]\d{2})\/(\d{2})/', $issue, $m)) { $jy = intval($m[1]); $jm = intval($m[2]); }
    else { [$jy, $jm, ] = jalali_from_gregorian_ts(time()); }
    $plate = company_plate_display($r['plate_p1'] ?? '', $r['plate_p2'] ?? '', $r['plate_letter'] ?? '', $r['plate_p4'] ?? '') ?: '';
    $group = $r['group_name'] ?: ($r['insured'] ?: 'نامشخص');
    return brd_archive_root($siteRoot) . '/' . $jy . '/' . jalali_month_name($jm) . '/' . sanitize_folder_name($group)
         . '/' . sanitize_folder_name('(' . insurance_type_fa($r['insurance_type'] ?? '') . ') ' . build_company_issued_folder_name($plate, $group, $r['policy_number'], $r['vin'] ?? ''));
}

// ثبت/به‌روزرسانیِ ردیف‌ها؛ $lines: فقط همین خط‌ها (اختیاری)
function brd_commit($pdo, array $rows, $fileName, array $actor, $lines = null) {
    $siteRoot = dirname(__DIR__);
    $only = is_array($lines) ? array_flip(array_map('intval', $lines)) : null;
    $ok = array_values(array_filter($rows, function ($r) use ($only) { return $r['ok'] && ($only === null || isset($only[$r['line']])); }));
    if (!$ok) return ['ok' => false, 'error' => 'ردیفِ قابلِ ثبتی نیست (همه ناقص‌اند).'];
    $cidx = brd_company_index($pdo);
    $pst = $pdo->prepare("SELECT id FROM persons WHERE national_code = ? LIMIT 1");
    $pdo->prepare("INSERT INTO brd_batches (file_name, rows_total, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, NOW())")
        ->execute([mb_substr((string)$fileName, 0, 250), count($rows), $actor['id'] ?: null, mb_substr((string)$actor['name'], 0, 190)]);
    $bid = (int)$pdo->lastInsertId();
    $find = $pdo->prepare("SELECT id, folder_path FROM brd_rows WHERE policy_number = ?");
    $cols = ['batch_id', 'insurance_type', 'insured', 'insured_id', 'insured_kind', 'company_id', 'person_id', 'group_name', 'contract_party', 'contract_no',
             'plate_p1', 'plate_p2', 'plate_letter', 'plate_p4', 'plate_core', 'vin', 'engine_no', 'car_name', 'car_system', 'car_tip', 'car_kind', 'model_year', 'color', 'car_usage',
             'issue_date', 'start_date', 'end_date', 'end_g', 'premium', 'car_value', 'liability', 'phone', 'postal_code', 'address', 'owner_name', 'prev_insurer', 'prev_policy',
             'central_no', 'data_json', 'raw_json'];
    $ins = $pdo->prepare("INSERT INTO brd_rows (policy_number, " . implode(', ', $cols) . ", status, created_at) VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", 'ACTIVE', NOW())");
    $upd = $pdo->prepare("UPDATE brd_rows SET " . implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols)) . ", updated_at = NOW() WHERE id = ?");
    $new = 0; $updated = 0; $ids = [];
    foreach ($ok as $r) {
        $pf = $r['pf']; $p = $r['plate'] ?: ['p1' => null, 'p2' => null, 'letter' => null, 'p4' => null];
        $cid = brd_match_company($cidx, $pf);
        $nid = preg_replace('/\D/', '', (string)($pf['national_id'] ?? ''));
        $pid = null;
        if (strlen($nid) === 10) { $pst->execute([$nid]); $pid = $pst->fetchColumn() ?: null; }
        $group = $cid ? null : (($pf['contract_party'] ?? '') !== '' ? $pf['contract_party'] : $r['insured']);
        if ($cid) { try { $g = $pdo->prepare("SELECT name FROM companies WHERE id = ?"); $g->execute([$cid]); $group = $g->fetchColumn() ?: $group; } catch (Throwable $e) {} }
        $end = imp_jdate($pf['end_date'] ?? '');
        $vals = [$bid, $r['type'], mb_substr($r['insured'], 0, 190), $r['insured_id'] ?: null, mb_substr((string)($pf['insured_kind'] ?? ''), 0, 40) ?: null, $cid, $pid,
                 $group ? mb_substr($group, 0, 190) : null, mb_substr((string)($pf['contract_party'] ?? ''), 0, 190) ?: null, mb_substr((string)($pf['contract_no'] ?? ''), 0, 60) ?: null,
                 $p['p1'], $p['p2'], $p['letter'] ? mb_substr($p['letter'], 0, 8) : null, $p['p4'], brd_plate_core($p['p1'], $p['p2'], $p['letter'], $p['p4']) ?: null,
                 $r['vin'] ?: null, mb_substr((string)($pf['engine_no'] ?? ''), 0, 40) ?: null, mb_substr($r['car_name'], 0, 150) ?: null,
                 mb_substr((string)($pf['car_system'] ?? ''), 0, 100) ?: null, mb_substr((string)($pf['car_tip'] ?? ''), 0, 100) ?: null, mb_substr((string)($pf['car_kind'] ?? ''), 0, 100) ?: null,
                 mb_substr((string)($pf['model_year'] ?? ''), 0, 10) ?: null, mb_substr((string)($pf['color'] ?? ''), 0, 60) ?: null, mb_substr((string)($pf['usage'] ?? ''), 0, 100) ?: null,
                 $r['issue'] ?: null, imp_jdate($pf['start_date'] ?? '') ?: null, $end ?: null, $end ? fin_jalali_to_date($end) : null,
                 intval($pf['premium'] ?? 0) ?: null, intval($pf['car_value'] ?? 0) ?: null, intval($pf['liability'] ?? 0) ?: null,
                 mb_substr((string)($pf['phone'] ?? ''), 0, 40) ?: null, mb_substr((string)($pf['postal_code'] ?? ''), 0, 12) ?: null, mb_substr((string)($pf['address'] ?? ''), 0, 600) ?: null,
                 mb_substr((string)($pf['owner_name'] ?? ''), 0, 190) ?: null, mb_substr((string)($pf['prev_insurer'] ?? ''), 0, 100) ?: null, mb_substr((string)($pf['prev_policy'] ?? ''), 0, 80) ?: null,
                 mb_substr((string)($pf['central_no'] ?? ''), 0, 60) ?: null, json_encode($pf, JSON_UNESCAPED_UNICODE), json_encode($r['raw'], JSON_UNESCAPED_UNICODE)];
        $find->execute([$r['policy']]);
        $old = $find->fetch();
        if ($old) { $upd->execute(array_merge($vals, [$old['id']])); $id = (int)$old['id']; $updated++; }
        else { $ins->execute(array_merge([$r['policy']], $vals)); $id = (int)$pdo->lastInsertId(); $new++; }
        $ids[] = $id;
    }
    $pdo->prepare("UPDATE brd_batches SET rows_new = ?, rows_updated = ?, rows_bad = ? WHERE id = ?")->execute([$new, $updated, count($rows) - count($ok), $bid]);
    // پوشه‌ها (کارِ دیسک، بعد از ثبت)
    foreach ($ids as $id) brd_ensure_folder($pdo, $siteRoot, $id);
    return ['ok' => true, 'batch_id' => $bid, 'new' => $new, 'updated' => $updated, 'skipped' => count($rows) - count($ok)];
}
function brd_ensure_folder($pdo, $siteRoot, $id) {
    $st = $pdo->prepare("SELECT * FROM brd_rows WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $want = brd_folder_for($siteRoot, $r);
    if ($r['folder_path'] && $r['folder_path'] !== $want && is_dir($r['folder_path']) && !is_dir($want)) {
        @mkdir(dirname($want), 0755, true);
        @rename($r['folder_path'], $want);
    }
    if (!is_dir($want)) @mkdir($want, 0755, true);
    if ($r['folder_path'] !== $want) $pdo->prepare("UPDATE brd_rows SET folder_path = ? WHERE id = ?")->execute([$want, $id]);
    return $want;
}

// همه‌ی ردیف‌ها با روزِ مانده، تمدیدشده، الحاقیه‌ها و آخرین پیگیری؛ $f: company_id، ids، person_id، plate_core، vin
function brd_rows($pdo, array $f = []) {
    brd_ensure($pdo);
    $w = ['1 = 1']; $p = [];
    if (!empty($f['company_id'])) { $w[] = 'r.company_id = ?'; $p[] = intval($f['company_id']); }
    if (!empty($f['person_id'])) { $w[] = '(r.person_id = ? OR r.insured_id = ?)'; $p[] = intval($f['person_id']); $p[] = (string)($f['person_nid'] ?? '-'); }
    if (!empty($f['ids'])) { $ids = array_filter(array_map('intval', (array)$f['ids'])); $w[] = $ids ? 'r.id IN (' . implode(',', $ids) . ')' : '0'; }
    if (isset($f['vehicle'])) {
        $vw = [];
        if (!empty($f['vehicle']['plate'])) { $vw[] = 'r.plate_core = ?'; $p[] = $f['vehicle']['plate']; }
        if (!empty($f['vehicle']['vin'])) { $vw[] = 'r.vin = ?'; $p[] = $f['vehicle']['vin']; }
        $w[] = $vw ? '(' . implode(' OR ', $vw) . ')' : '0';
    }
    $st = $pdo->prepare("SELECT r.*, c.name AS company_name, per.full_name AS person_name FROM brd_rows r LEFT JOIN companies c ON c.id = r.company_id
                          LEFT JOIN persons per ON per.id = r.person_id WHERE " . implode(' AND ', $w) . " ORDER BY r.end_g, r.id LIMIT 20000");
    $st->execute($p);
    $rows = $st->fetchAll();
    if (!$rows) return [];
    $ids = implode(',', array_map(function ($r) { return (int)$r['id']; }, $rows));
    $endo = []; $notes = [];
    foreach ($pdo->query("SELECT * FROM brd_endorsements WHERE row_id IN ($ids) ORDER BY id") as $e) $endo[(int)$e['row_id']][] = $e;
    foreach ($pdo->query("SELECT row_id, channel, note, created_at, created_by_name FROM brd_notes WHERE row_id IN ($ids) ORDER BY id") as $n) $notes[(int)$n['row_id']][] = $n;
    // تمدیدشده: بیمه‌نامه‌ی تازه‌ترِ همان نوع برای همان پلاک/شاسی در بردرو یا صادره‌های خودِ سامانه
    $later = [];
    foreach ($pdo->query("SELECT insurance_type, plate_core, vin, issue_date, policy_number FROM brd_rows WHERE status <> 'CANCELLED'") as $o) {
        foreach (array_filter(['p' . $o['plate_core'], $o['vin'] ? 'v' . $o['vin'] : '']) as $k) if ($k !== 'p') $later[$o['insurance_type'] . '|' . $k][] = ['issue' => $o['issue_date'], 'policy' => $o['policy_number'], 'src' => 'بردرو'];
    }
    try {
        foreach ($pdo->query("SELECT insurance_type, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, policy_issue_date, issued_at, policy_number FROM company_request_plates WHERE status = 'ISSUED'") as $o) {
            $pc = brd_plate_core($o['plate_p1'], $o['plate_p2'], $o['plate_letter'], $o['plate_p4']); $vk = brd_vin_key($o['chassis_no']);
            $iss = imp_jdate($o['policy_issue_date']) ?: ($o['issued_at'] ? jalali_from_gregorian_ts_dotted(strtotime($o['issued_at'])) : '');
            foreach (array_filter([$pc ? 'p' . $pc : '', $vk ? 'v' . $vk : '']) as $k) $later[$o['insurance_type'] . '|' . $k][] = ['issue' => imp_jdate($iss), 'policy' => $o['policy_number'], 'src' => 'صادره‌ی سامانه'];
        }
        foreach ($pdo->query("SELECT insurance_type, plate, chassis_num, vin, policy_issue_date, issued_at, policy_number FROM policy_cases WHERE status = 'ISSUED'") as $o) {
            $pc = $o['plate'] ? p2e_digits(plate_core($o['plate'])) : ''; $vk = brd_vin_key($o['chassis_num'] ?: $o['vin']);
            $iss = imp_jdate($o['policy_issue_date']) ?: ($o['issued_at'] ? jalali_from_gregorian_ts_dotted(strtotime($o['issued_at'])) : '');
            foreach (array_filter([$pc ? 'p' . $pc : '', $vk ? 'v' . $vk : '']) as $k) $later[$o['insurance_type'] . '|' . $k][] = ['issue' => imp_jdate($iss), 'policy' => $o['policy_number'], 'src' => 'صادره‌ی کارکنان'];
        }
    } catch (Throwable $e) { error_log('[brd later] ' . $e->getMessage()); }
    $today = strtotime(date('Y-m-d'));
    $jn = function ($j) { return preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', (string)$j, $m) ? intval($m[1] . $m[2] . $m[3]) : 0; };
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $es = $endo[$id] ?? [];
        $end = $r['end_date']; $premium = (int)$r['premium']; $cancel = $r['status'] === 'CANCELLED';
        // پوشش‌ها از متنِ اکسل؛ الحاقیه‌های «تغییرِ اطلاعات» به ترتیب روی ردیف اعمال می‌شوند و اطلاعاتِ اولیه کنارش می‌ماند
        $dj = json_decode((string)$r['data_json'], true) ?: [];
        $r['coverages'] = brd_cov_from_text($dj['covers'] ?? '');
        $orig = [];
        foreach ($es as $e) {
            $ch = json_decode((string)($e['changes_json'] ?? ''), true);
            if (!is_array($ch)) continue;
            foreach ($ch as $f => $v) {
                if (!array_key_exists($f, BRD_CHANGE_FIELDS)) continue;
                if (!array_key_exists($f, $orig)) $orig[$f] = $r[$f] ?? null;
                $r[$f] = $v;
            }
        }
        if (array_intersect(array_keys($orig), ['plate_p1', 'plate_p2', 'plate_letter', 'plate_p4'])) $r['plate_core'] = brd_plate_core($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) ?: $r['plate_core'];
        foreach ($es as $e) {
            if ($e['new_end_date']) $end = $e['new_end_date'];
            if ($e['kind'] === 'ADD') $premium += abs((int)$e['amount']);
            if ($e['kind'] === 'REFUND' || $e['kind'] === 'CANCEL') $premium -= abs((int)$e['amount']);
        }
        $endTs = $end ? jalali_to_gregorian_ts(...array_map('intval', explode('/', $end))) : 0;
        $renewed = null;
        if (!$cancel && $end) {
            $win = $jn($end) - 400;   // از حدودِ ۴ ماه پیش از انقضا
            foreach (array_filter([$r['plate_core'] ? 'p' . $r['plate_core'] : '', $r['vin'] ? 'v' . $r['vin'] : '']) as $k) foreach ($later[$r['insurance_type'] . '|' . $k] ?? [] as $o) {
                if ((string)$o['policy'] === (string)$r['policy_number'] || !$o['issue']) continue;
                if ($jn($o['issue']) > $jn($r['issue_date']) && $jn($o['issue']) >= $win) { $renewed = $o; break 2; }
            }
        }
        $ns = $notes[$id] ?? [];
        $last = $ns ? end($ns) : null;
        $state = $cancel ? 'cancelled' : ($renewed || ($last && $last['channel'] === 'renewed') ? 'renewed' : ($last && in_array($last['channel'], ['declined', 'renewed_out'], true) ? 'closed' : ($last ? 'notified' : 'none')));
        $plate = company_plate_display($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) ?: '';
        $kindN = brd_kind_norm($r['insured_kind'], $r['insured_id']);
        $origFa = [];
        foreach ($orig as $f => $v) {
            if (in_array($f, ['plate_p1', 'plate_p2', 'plate_letter', 'plate_p4'], true)) continue;
            $origFa[] = ['field' => $f, 'label' => BRD_CHANGE_FIELDS[$f], 'from' => $f === 'coverages' ? implode('، ', company_coverages_fa(json_encode($v ?: new stdClass()))) : (string)$v,
                         'to' => $f === 'coverages' ? implode('، ', company_coverages_fa(json_encode($r[$f] ?: new stdClass()))) : (string)$r[$f]];
        }
        if (array_intersect(array_keys($orig), ['plate_p1', 'plate_p2', 'plate_letter', 'plate_p4']))
            $origFa[] = ['field' => 'plate', 'label' => 'پلاک', 'from' => company_plate_display($orig['plate_p1'] ?? $r['plate_p1'], $orig['plate_p2'] ?? $r['plate_p2'], $orig['plate_letter'] ?? $r['plate_letter'], $orig['plate_p4'] ?? $r['plate_p4']), 'to' => $plate];
        $out[] = [
            'plate_p1' => $r['plate_p1'], 'plate_p2' => $r['plate_p2'], 'plate_letter' => $r['plate_letter'], 'plate_p4' => $r['plate_p4'],
            'insured_kind_n' => $kindN, 'insured_kind_fa' => $kindN === 'LEGAL' ? 'حقوقی' : ($kindN === 'PERSON' ? 'حقیقی' : ''),
            'economic_code' => $dj['economic_code'] ?? '', 'covers_text' => $dj['covers'] ?? '', 'coverages' => $r['coverages'],
            'coverages_fa' => $r['coverages'] === null ? [] : company_coverages_fa(json_encode($r['coverages'] ?: new stdClass())),
            'prev_expiry' => $dj['prev_expiry'] ?? '', 'no_claim_years' => $dj['no_claim_years'] ?? '', 'prev_claims' => $dj['prev_claims'] ?? '', 'diya' => (int)($dj['diya'] ?? 0),
            'driver_cover' => (int)($dj['driver_cover'] ?? 0), 'ncd' => $dj['ncd'] ?? '', 'capacity' => $dj['capacity'] ?? '', 'cylinders' => $dj['cylinders'] ?? '', 'customer_no' => $dj['customer_no'] ?? '',
            'endorsed' => (bool)$orig, 'changes' => $origFa,
            'id' => $id, 'policy' => $r['policy_number'], 'type' => $r['insurance_type'], 'type_fa' => insurance_type_fa($r['insurance_type']),
            'insured' => $r['insured'], 'insured_id' => $r['insured_id'], 'insured_kind' => $r['insured_kind'], 'company_id' => $r['company_id'] ? (int)$r['company_id'] : null,
            'company_name' => $r['company_name'], 'person_id' => $r['person_id'] ? (int)$r['person_id'] : null, 'person_name' => $r['person_name'],
            'group' => $r['company_name'] ?: ($r['group_name'] ?: $r['insured']), 'contract_party' => $r['contract_party'], 'contract_no' => $r['contract_no'],
            'plate' => $plate, 'plate_core' => $r['plate_core'], 'vin' => $r['vin'], 'engine' => $r['engine_no'], 'car' => $r['car_name'], 'car_system' => $r['car_system'],
            'car_tip' => $r['car_tip'], 'car_kind' => $r['car_kind'], 'model_year' => $r['model_year'], 'color' => $r['color'], 'usage' => $r['car_usage'],
            'issue_date' => $r['issue_date'], 'start_date' => $r['start_date'], 'end_date' => $end, 'end_orig' => $r['end_date'],
            'days_left' => $endTs ? (int)floor(($endTs - $today) / 86400) : null, 'premium' => $premium, 'premium_orig' => (int)$r['premium'],
            'car_value' => (int)$r['car_value'], 'liability' => (int)$r['liability'], 'phone' => $r['phone'], 'postal_code' => $r['postal_code'], 'address' => $r['address'],
            'owner_name' => $r['owner_name'], 'prev_insurer' => $r['prev_insurer'], 'prev_policy' => $r['prev_policy'], 'central_no' => $r['central_no'],
            'status' => $r['status'], 'state' => $state,
            'state_fa' => ['cancelled' => 'فسخ‌شده', 'renewed' => 'تمدید شد', 'closed' => $last ? (BRD_CHANNELS[$last['channel']] ?? 'بسته') : 'بسته', 'notified' => 'اعلام شد', 'none' => 'اعلام نشده'][$state],
            'renewed_by' => $renewed, 'endorsements' => array_map(function ($e) {
                $ch = json_decode((string)($e['changes_json'] ?? ''), true) ?: [];
                return ['id' => (int)$e['id'], 'kind' => $e['kind'], 'kind_fa' => BRD_ENDORSE_KINDS[$e['kind']] ?? $e['kind'], 'no' => $e['endorse_no'], 'date' => $e['date_j'],
                        'changes' => array_values(array_map(function ($f, $v) { return ['label' => BRD_CHANGE_FIELDS[$f] ?? $f, 'to' => $f === 'coverages' ? implode('، ', company_coverages_fa(json_encode($v ?: new stdClass()))) : (string)$v]; }, array_keys($ch), $ch)),
                        'amount' => (int)$e['amount'], 'new_end' => $e['new_end_date'], 'note' => $e['note'], 'file' => $e['file_path'] ? ltrim(str_replace(dirname(__DIR__), '', $e['file_path']), '/') : null,
                        'by' => $e['created_by_name'], 'at' => jalali_from_gregorian_ts_dotted(strtotime($e['created_at']))];
            }, $es),
            'notes' => array_map(function ($n) { return ['channel' => $n['channel'], 'channel_fa' => BRD_CHANNELS[$n['channel']] ?? $n['channel'], 'note' => $n['note'], 'by' => $n['created_by_name'],
                                                     'at' => jalali_from_gregorian_ts_dotted(strtotime($n['created_at']))]; }, $ns),
            'folder' => $r['folder_path'] ? ltrim(str_replace(dirname(__DIR__), '', $r['folder_path']), '/') : null,
        ];
    }
    return $out;
}

// الحاقیه: اثر روی حق بیمه/انقضای همین فهرست (به مالی نمی‌رود)؛ فسخ => ردیف از فهرستِ تمدید بیرون می‌رود
function brd_add_endorsement($pdo, $rowId, array $d, array $actor, $file = null) {
    $kind = isset(BRD_ENDORSE_KINDS[$d['kind'] ?? '']) ? $d['kind'] : null;
    if (!$kind) return ['ok' => false, 'error' => 'نوعِ الحاقیه را انتخاب کنید.'];
    $st = $pdo->prepare("SELECT * FROM brd_rows WHERE id = ?");
    $st->execute([intval($rowId)]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    $date = imp_jdate($d['date'] ?? '') ?: null;
    $newEnd = imp_jdate($d['new_end'] ?? '') ?: null;
    $amount = intval(preg_replace('/\D/', '', p2e_digits((string)($d['amount'] ?? '')))) ?: null;
    $path = null;
    if ($file && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) return ['ok' => false, 'error' => 'فایلِ الحاقیه باید PDF یا عکس باشد.'];
        $dir = brd_ensure_folder($pdo, dirname(__DIR__), $row['id']) ?: brd_archive_root(dirname(__DIR__));
        $name = sanitize_folder_name('الحاقیه ' . (BRD_ENDORSE_KINDS[$kind] ?? '') . ' ' . ($d['no'] ?? '') . ' ' . str_replace('/', '.', (string)$date)) . '.' . $ext;
        $path = $dir . '/' . $name;
        $i = 1; while (is_file($path)) $path = $dir . '/' . pathinfo($name, PATHINFO_FILENAME) . ' (' . (++$i) . ').' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $path)) return ['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.'];
    }
    // تغییرِ اطلاعات: فقط فیلدهای مجاز و فقط اگر با مقدارِ فعلی فرق دارند
    $changes = [];
    $chIn = is_array($d['changes'] ?? null) ? $d['changes'] : (json_decode((string)($d['changes'] ?? ''), true) ?: []);
    foreach ($chIn as $f => $v) {
        if (!array_key_exists($f, BRD_CHANGE_FIELDS)) continue;
        if ($f === 'coverages') { $c = $v === 'none' ? '{}' : company_clean_coverages($v); if ($c !== null) $changes[$f] = json_decode($c, true) ?: []; continue; }
        $v = trim((string)$v);
        if (in_array($f, ['car_value', 'liability'], true)) $v = (string)intval(preg_replace('/\D/', '', p2e_digits($v)));
        if (in_array($f, ['plate_p1', 'plate_p2', 'plate_p4', 'vin', 'engine_no', 'model_year', 'insured_id', 'phone', 'postal_code'], true)) $v = strtoupper(p2e_digits($v));
        if ($v === '' || $v === '0') continue;
        $changes[$f] = mb_substr($v, 0, 190);
    }
    if ($kind === 'CHANGE' && !$changes && !$newEnd && !$amount) return ['ok' => false, 'error' => 'برای «تغییرِ اطلاعات» حداقل یک مقدارِ تازه بنویسید.'];
    $pdo->prepare("INSERT INTO brd_endorsements (row_id, kind, endorse_no, date_j, amount, new_end_date, note, file_path, created_by, created_by_name, created_at, changes_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)")
        ->execute([$row['id'], $kind, mb_substr(p2e_digits((string)($d['no'] ?? '')), 0, 60) ?: null, $date, $amount, $newEnd, mb_substr(trim((string)($d['note'] ?? '')), 0, 600) ?: null,
                   $path, $actor['id'] ?: null, mb_substr((string)$actor['name'], 0, 190), $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null]);
    if ($kind === 'CANCEL') $pdo->prepare("UPDATE brd_rows SET status = 'CANCELLED', updated_at = NOW() WHERE id = ?")->execute([$row['id']]);
    return ['ok' => true];
}

// ستون‌های خروجی (اکسل/PDF)
function brd_export_cols() {
    return [['#', null], ['گروه / شرکت', 'group'], ['بیمه‌گذار', 'insured'], ['حقیقی/حقوقی', 'insured_kind_fa'], ['کد / شناسه ملی', 'insured_id'], ['پلاک', 'plate'], ['شماره شاسی', 'vin'], ['شماره موتور', 'engine'],
            ['خودرو', 'car'], ['مدل', 'model_year'], ['رنگ', 'color'], ['کاربری', 'usage'], ['نوع بیمه', 'type_fa'], ['شماره بیمه‌نامه', 'policy'], ['صدور', 'issue_date'],
            ['شروع', 'start_date'], ['انقضا', 'end_date'], ['روزِ مانده', 'days_left'], ['حق بیمه (ریال)', 'premium'], ['ارزش خودرو (ریال)', 'car_value'], ['تعهد مالی (ریال)', 'liability'], ['پوشش‌ها', '_cov'], ['بیمه‌نامه‌ی قبلی', 'prev_policy'],
            ['تلفن', 'phone'], ['مالک', 'owner_name'], ['طرف قرارداد', 'contract_party'], ['وضعیتِ تمدید', 'state_fa'], ['الحاقیه‌ها', '_endo']];
}
function brd_export_xlsx(array $rows, $title) {
    require_once __DIR__ . '/_xlsx_writer.php';
    $cols = brd_export_cols();
    $headers = array_map(function ($c) { return $c[0]; }, $cols);
    $xr = [];
    foreach (array_values($rows) as $i => $r) {
        $line = [];
        foreach ($cols as $c) {
            if ($c[1] === null) $line[] = $i + 1;
            elseif ($c[1] === '_endo') $line[] = implode(' | ', array_map(function ($e) { return $e['kind_fa'] . ($e['no'] ? ' ' . $e['no'] : '') . ($e['date'] ? ' ' . fa_digits($e['date']) : ''); }, $r['endorsements']));
            elseif ($c[1] === '_cov') $line[] = implode('، ', $r['coverages_fa'] ?? []) ?: ($r['covers_text'] ?? '');
            elseif (in_array($c[1], ['issue_date', 'start_date', 'end_date'], true)) $line[] = fa_digits((string)$r[$c[1]]);
            else $line[] = $r[$c[1]] ?? '';
        }
        $xr[] = $line;
    }
    return xlsx_build($headers, $xr, mb_substr($title, 0, 28), [0, 16, 17, 18, 19]);
}
// PDFِ زیبا (افقی): سربرگِ رنگی، جدولِ راه‌راه، جمعِ حق بیمه
function brd_export_pdf(array $rows, $title, $dest) {
    if (!defined('K_PATH_CACHE')) { $d = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($d, 0775, true); define('K_PATH_CACHE', $d); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(8, 8, 8); $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->setRTL(true); $pdf->SetFont('vazir', '', 8);
    $pdf->AddPage();
    $sum = 0; foreach ($rows as $r) $sum += (int)$r['premium'];
    $e = function ($s) { return htmlspecialchars(fa_digits((string)$s), ENT_QUOTES, 'UTF-8'); };
    $today = jalali_from_gregorian_ts_dotted(time());
    $h = '<table cellpadding="6" style="background-color:#1e3a8a;color:#ffffff;"><tr><td width="70%"><b style="font-size:15pt">' . $e($title) . '</b><br/><span style="font-size:9pt;color:#c7d2fe">فهرستِ بیمه‌نامه‌ها و سررسیدِ تمدید · بردروی پاسارگاد</span></td>'
       . '<td width="30%" style="text-align:left;font-size:9pt">تاریخِ گزارش: ' . $e($today) . '<br/>تعداد: ' . $e(count($rows)) . ' بیمه‌نامه<br/>جمعِ حق بیمه: ' . $e(number_format($sum)) . ' ریال</td></tr></table><br/>';
    $cols = [['#', 4], ['پلاک / شاسی', 13], ['خودرو', 12], ['بیمه‌گذار', 14], ['نوع', 5], ['شماره بیمه‌نامه', 15], ['صدور', 7], ['انقضا', 7], ['مانده', 5], ['حق بیمه (ریال)', 10], ['وضعیت', 8]];
    $h .= '<table cellpadding="3" style="font-size:7.5pt;border-collapse:collapse;"><thead><tr style="background-color:#e0e7ff;color:#1e1b4b;font-weight:bold;">';
    foreach ($cols as $c) $h .= '<th width="' . $c[1] . '%" style="border-bottom:1px solid #6366f1;">' . $c[0] . '</th>';
    $h .= '</tr></thead>';
    foreach (array_values($rows) as $i => $r) {
        $dl = $r['days_left'];
        $dlCol = $dl === null ? '#64748b' : ($dl < 0 ? '#b91c1c' : ($dl <= 30 ? '#c2410c' : '#047857'));
        $bg = $i % 2 ? '#f8fafc' : '#ffffff';
        $h .= '<tr style="background-color:' . $bg . ';">'
            . '<td width="4%">' . $e($i + 1) . '</td><td width="13%">' . $e($r['plate'] ?: $r['vin']) . '</td><td width="12%">' . $e(trim($r['car'] . ' ' . $r['model_year'])) . '</td>'
            . '<td width="14%">' . $e($r['insured']) . '</td><td width="5%">' . $e($r['type_fa']) . '</td><td width="15%">' . $e($r['policy']) . '</td>'
            . '<td width="7%">' . $e($r['issue_date']) . '</td><td width="7%"><b>' . $e($r['end_date']) . '</b></td>'
            . '<td width="5%" style="color:' . $dlCol . ';">' . ($dl === null ? '' : $e($dl)) . '</td><td width="10%">' . $e($r['premium'] ? number_format($r['premium']) : '') . '</td>'
            . '<td width="8%">' . $e($r['state_fa']) . '</td></tr>';
    }
    $h .= '</table>';
    $pdf->writeHTML($h, true, false, true, false, '');
    $pdf->Output($dest, 'F');
    return is_file($dest);
}
