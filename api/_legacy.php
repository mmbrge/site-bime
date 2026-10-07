<?php
// فایل: api/_legacy.php
// «ورودِ بایگانی‌های قبلی»: بیمه‌نامه‌هایی که پیش از سایت صادر شده‌اند، از روی خروجیِ اکسلِ پاسارگاد (بدنه، ثالث و الحاقیه‌ها)
// مستقیم «صادرشده» ثبت می‌شوند — بدونِ مرحله‌ی مدارک — و فایل‌هایشان (پوشه‌های مرتب و نامرتب) بعداً ادغام می‌شود.
//   - شرکتی: هر «بیمه‌گذار + تاریخِ صدور» یک درخواست (مثلِ یک نامه)، با همان پوشه‌بندیِ بایگانیِ شرکتی و «بایگانی صادره»
//   - کارکنان: شخص + معرفی‌نامه (با سهمیه) + پرونده‌ی صادرشده، با همان پوشه‌بندیِ «بایگانی کسر از حقوق» و «بایگانی صادره»
//   - تاریخ‌ها (پوشه‌ی ماه، تاریخِ ثبت) از تاریخِ صدورِ خودِ بیمه‌نامه
//   - مالی به انتخاب: فقط حق بیمه (پیش‌فرض، بدونِ قسط) یا اقساطِ کامل
//   - الحاقیه‌ها روی بیمه‌نامه‌ها ثبت می‌شوند (حق بیمه‌ی نهایی؛ بدونِ اثر روی اقساط)
//   - هر ورود یک «دسته» است و کاملاً برگشت‌پذیر
// نیاز: _case_helpers.php، _company_helpers.php، finance_core.php، _import_archive.php، _company_issue.php، _endorse.php

function legacy_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS legacy_batches (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NULL, files TEXT NULL, options TEXT NULL, counts TEXT NULL,
        created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, rolled_back TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS legacy_items (
        id INT AUTO_INCREMENT PRIMARY KEY, batch_id INT NOT NULL, kind CHAR(1) NOT NULL, ref_id BIGINT NULL, request_id BIGINT NULL,
        person_id BIGINT NULL, intro_id BIGINT NULL, created_person TINYINT NOT NULL DEFAULT 0, created_intro TINYINT NOT NULL DEFAULT 0,
        created_company INT NULL, policy_key VARCHAR(60) NULL, extra TEXT NULL, KEY k_b (batch_id), KEY k_p (policy_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([['company_requests', 'legacy_batch_id'], ['company_request_plates', 'legacy_batch_id'], ['policy_cases', 'legacy_batch_id']] as [$t, $c]) {
        try { if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` INT NULL"); } catch (Throwable $e) {}
    }
    if (function_exists('endo_ensure')) endo_ensure($pdo);
}

function legacy_tmp_dir($siteRoot) {
    $d = $siteRoot . '/tmp_ocr/legacy';
    if (!is_dir($d)) { @mkdir($d, 0775, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
    foreach ((array)glob($d . '/*') as $old) if (is_file($old) && basename($old) !== '.htaccess' && @filemtime($old) < time() - 3 * 86400) @unlink($old);
    return $d;
}

// پوشه‌ای که فایل‌های بایگانیِ قبلی (پوشه‌های مرتب و نامرتب، یا ZIP) در آن گذاشته می‌شود
function legacy_staging_dir($siteRoot) {
    $d = (function_exists('temp_archive_root') ? dirname(temp_archive_root($siteRoot)) : $siteRoot . '/موقت') . '/ورود بایگانی';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    return $d;
}

function legacy_norm($s) {
    $s = str_replace(['ي', 'ك', "\xE2\x80\x8C", 'ـ'], ['ی', 'ک', ' ', ''], (string)$s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return $s;
}
function legacy_norm_key($s) { return function_exists('company_normalize_header') ? company_normalize_header(legacy_norm($s)) : preg_replace('/\s+/u', '', mb_strtolower(legacy_norm($s))); }

// نوعِ فایلِ اکسل: «policy» (بدنه/ثالث) یا «endorse» (الحاقیه‌ها)
function legacy_file_kind($table) {
    foreach (array_slice((array)$table, 0, 8) as $row) {
        $keys = array_map('legacy_norm_key', (array)$row);
        // اکسلِ صدورِ بدنه هم ستونِ «نوع الحاقیه» و «حق بیمه اضافی» دارد؛ ستون‌های مخصوصِ خروجیِ الحاقیه‌ها ملاک‌اند
        foreach (['علت الحاقیه', 'تاریخ اثر', 'حق بیمه الحاقیه کل', 'حق بیمه برگشتی', 'حق بیمه برگشتی به عدد'] as $h) {
            if (in_array(legacy_norm_key($h), $keys, true)) return 'endorse';
        }
        if (in_array(legacy_norm_key('شماره بیمه‌نامه'), $keys, true) || in_array(legacy_norm_key('شماره بیمه نامه'), $keys, true)) return 'policy';
    }
    return null;
}

// اکسلِ الحاقیه‌های پاسارگاد => [[policy, endorse_no, kind, gross, net, tax, issue_date, effect_date, end_date, reason, description, type, line]]
function legacy_parse_endorsements($table) {
    $hdrIdx = -1; $cols = [];
    $want = ['policy' => ['شماره بیمه‌نامه', 'شماره بیمه نامه'], 'no' => ['شماره الحاقیه'], 'kind' => ['نوع الحاقیه'], 'reason' => ['علت الحاقیه'],
             'desc' => ['شرح الحاقیه'], 'issue' => ['تاریخ صدور'], 'effect' => ['تاریخ اثر'], 'end' => ['تاریخ انقضا'], 'type' => ['نوع بیمه نامه'],
             'add' => ['حق بیمه اضافی'], 'ref' => ['حق بیمه برگشتی به عدد', 'حق بیمه برگشتی'], 'net_add' => ['حق بیمه پرداختی اضافی'],
             'net_ref' => ['حق بیمه پرداختی برگشتی'], 'tax_add' => ['مالیات اضافی'], 'tax_ref' => ['مالیات برگشتی'], 'total' => ['حق بیمه الحاقیه کل']];
    $lookup = [];
    foreach ($want as $k => $names) foreach ($names as $n) $lookup[legacy_norm_key($n)] = $k;
    foreach (array_slice($table, 0, 8, true) as $i => $row) {
        $found = [];
        foreach ((array)$row as $c => $cell) {
            $k = $lookup[legacy_norm_key($cell)] ?? null;
            // ستون‌های تکراری (نوع الحاقیه با عدد، حق بیمه اضافی دوباره): اولین ستونی که مقدارِ متنی/مبلغی دارد نگه داشته می‌شود
            if ($k && !in_array($k, $found, true)) $found[$c] = $k;
        }
        if (count($found) >= 5) { $hdrIdx = $i; $cols = $found; break; }
    }
    if ($hdrIdx < 0) return ['error' => 'سرستون‌های اکسلِ الحاقیه‌ها پیدا نشد.'];
    $num = function ($v) { $v = function_exists('p2e_digits') ? p2e_digits((string)$v) : (string)$v; return (int)preg_replace('/[^\d]/', '', $v); };
    $out = [];
    foreach (array_slice($table, $hdrIdx + 1, null, true) as $i => $row) {
        $r = [];
        foreach ($cols as $c => $k) $r[$k] = trim((string)($row[$c] ?? ''));
        $pol = trim(function_exists('p2e_digits') ? p2e_digits($r['policy'] ?? '') : ($r['policy'] ?? ''));
        if ($pol === '' || $pol === '-') continue;
        $kRaw = legacy_norm($r['kind'] ?? '');
        $kind = mb_strpos($kRaw, 'فسخ') !== false ? 'CANCELLATION' : (mb_strpos($kRaw, 'برگشت') !== false ? 'REFUND'
              : (mb_strpos($kRaw, 'اضاف') !== false ? 'ADDITIONAL' : (mb_strpos($kRaw, 'اصلاح') !== false ? 'CORRECTIVE' : '')));
        $add = $num($r['add'] ?? 0); $ref = $num($r['ref'] ?? 0);
        $gross = $kind === 'ADDITIONAL' ? $add : (in_array($kind, ['REFUND', 'CANCELLATION'], true) ? $ref : 0);
        $out[] = ['line' => $i + 1, 'policy' => $pol, 'key' => endo_policy_key($pol), 'endorse_no' => trim($r['no'] ?? ''), 'kind' => $kind,
                  'kind_raw' => $kRaw, 'gross' => $gross, 'net' => $kind === 'ADDITIONAL' ? $num($r['net_add'] ?? 0) : $num($r['net_ref'] ?? 0),
                  'tax' => abs($kind === 'ADDITIONAL' ? $num($r['tax_add'] ?? 0) : $num($r['tax_ref'] ?? 0)),
                  'issue_date' => endo_jdate($r['issue'] ?? ''), 'effect_date' => endo_jdate($r['effect'] ?? '') ?: endo_jdate($r['issue'] ?? ''),
                  'end_date' => endo_jdate($r['end'] ?? ''), 'reason' => legacy_norm($r['reason'] ?? ''),
                  'description' => trim(legacy_norm($r['desc'] ?? ''), ' -'), 'type' => legacy_norm($r['type'] ?? ''), 'ok' => $kind !== ''];
    }
    return ['rows' => $out];
}

// شرکتِ موجود (نه وارداتی) با شناسه‌ی ملی/کد اقتصادی یا نامِ یکسان
function legacy_company_suggest($pdo, $name, $nid = '') {
    $all = $pdo->query("SELECT id, name, economic_code FROM companies WHERE COALESCE(is_import,0) = 0 ORDER BY id")->fetchAll();
    $nid = preg_replace('/\D/', '', (string)$nid);
    if ($nid !== '') foreach ($all as $c) if (preg_replace('/\D/', '', (string)$c['economic_code']) === $nid) return (int)$c['id'];
    $k = legacy_norm_key($name);
    if ($k === '') return null;
    foreach ($all as $c) if (legacy_norm_key($c['name']) === $k) return (int)$c['id'];
    // نامِ کوتاه‌تر داخلِ نامِ بلندتر («گروه ماموت» ⊂ «شرکت گروه ماموت»)
    foreach ($all as $c) { $ck = legacy_norm_key($c['name']); if (mb_strlen($ck) >= 4 && (mb_strpos($k, $ck) !== false || mb_strpos($ck, $k) !== false)) return (int)$c['id']; }
    return null;
}

// ---------------------------------------------------------------------
//  پیش‌نمایش
// ---------------------------------------------------------------------
// $files: [['path' => ..., 'name' => ...]] => ذخیره در فایلِ موقت (توکن) و خروجیِ پیش‌نمایش
function legacy_preview($pdo, $siteRoot, array $files) {
    legacy_ensure($pdo);
    $policies = []; $endorses = []; $fileInfo = []; $errors = [];
    foreach ($files as $f) {
        $table = fin_read_spreadsheet($f['path']);
        if (!$table) { $errors[] = '«' . $f['name'] . '» خوانده نشد.'; continue; }
        $kind = legacy_file_kind($table);
        if ($kind === 'endorse') {
            $r = legacy_parse_endorsements($table);
            if (isset($r['error'])) { $errors[] = '«' . $f['name'] . '»: ' . $r['error']; continue; }
            foreach ($r['rows'] as $x) { $x['file'] = $f['name']; $endorses[] = $x; }
            $fileInfo[] = ['name' => $f['name'], 'kind' => 'endorse', 'kind_fa' => 'الحاقیه‌ها', 'rows' => count($r['rows'])];
        } elseif ($kind === 'policy') {
            $r = imp_parse_excel($pdo, $f['path']);
            if (isset($r['error'])) { $errors[] = '«' . $f['name'] . '»: ' . $r['error']; continue; }
            foreach ($r['rows'] as $x) { $x['file'] = $f['name']; $policies[] = $x; }
            $fileInfo[] = ['name' => $f['name'], 'kind' => 'policy', 'kind_fa' => 'بیمه‌نامه‌ها (بدنه/ثالث)', 'rows' => count($r['rows'])];
        } else {
            $errors[] = '«' . $f['name'] . '»: نوعِ فایل شناخته نشد (نه خروجیِ بیمه‌نامه‌ها، نه الحاقیه‌ها).';
        }
    }
    // الحاقیه‌ها به تفکیکِ بیمه‌نامه
    $endoBy = [];
    foreach ($endorses as $e) $endoBy[$e['key']][] = $e;

    $rows = []; $companies = []; $employers = []; $seenKey = [];
    foreach ($policies as $i => $p) {
        $raw = $p['raw'] ?? [];
        $pf = $p['pf'] ?? [];
        $key = endo_policy_key($p['policy']);
        $kindRaw = legacy_norm($raw['insured_kind'] ?? '');
        $isPerson = mb_strpos($kindRaw, 'حقیقی') !== false;
        // کارکنان: بیمه‌گذارِ حقیقی؛ دارنده‌ی معرفی‌نامه = پرداخت‌کننده (اگر حقیقی است)، وگرنه خودِ بیمه‌گذار
        $insName = $isPerson ? (trim(($raw['first_name'] ?? '') . ' ' . ($raw['last_name'] ?? '')) ?: $p['insured']) : $p['insured'];
        $insNid = preg_replace('/\D/', '', (string)($raw['nid'] ?? $p['insured_id']));
        $payerName = trim(legacy_norm(($raw['payer_name'] ?? '') . ' ' . ($raw['payer_last'] ?? '')));
        $payerNid = preg_replace('/\D/', '', (string)($raw['payer_nid'] ?? ''));
        $holderName = $isPerson && $payerNid !== '' && strlen($payerNid) === 10 ? ($payerName ?: $insName) : $insName;
        $holderNid = $isPerson && strlen($payerNid) === 10 ? $payerNid : $insNid;
        $contract = legacy_norm($raw['contract_party'] ?? '');
        $dupNote = $p['dup'] === 'system' ? 'در سایت هست' : ($p['dup'] === 'import' ? 'در «بایگانی وارداتی» هست' : ($p['dup'] === 'file' ? 'تکراری در فایل' : ''));
        $ends = $endoBy[$key] ?? [];
        $endoTotal = 0;
        foreach ($ends as $e) $endoTotal += endo_sign($e['kind']) * (int)$e['gross'];
        $row = [
            'i' => $i, 'line' => $p['line'], 'file' => $p['file'], 'ok' => (bool)$p['ok'],
            'errors' => $p['dup'] ? [] : $p['errors'], 'warnings' => $p['warnings'], 'dup' => $p['dup'], 'dup_fa' => $dupNote,
            'target' => $isPerson ? 'P' : 'C', 'insured_kind' => $kindRaw, 'insured' => $insName, 'insured_id' => $insNid,
            'holder' => $holderName, 'holder_nid' => $holderNid, 'relation' => ($holderNid === $insNid || $holderNid === '') ? 'خودم' : 'نامشخص',
            'contract' => $contract, 'fin_pattern' => legacy_norm($raw['fin_pattern'] ?? ''), 'pcode' => preg_replace('/\D/', '', (string)($raw['pcode'] ?? ($raw['payer_pcode'] ?? ''))),
            'type' => $p['type'], 'type_fa' => $p['type_fa'], 'plate' => $p['plate'], 'plate_text' => $pf['plate'] ?? '', 'vin' => $p['vin'],
            'policy' => $p['policy'], 'key' => $key, 'issue' => $p['issue'], 'end' => $pf['end_date'] ?? '', 'start' => $pf['start_date'] ?? '',
            'premium' => (int)$p['premium'], 'zero_km' => (int)$p['zero_km'], 'car_name' => $p['car_name'], 'pf' => $pf,
            'endo_count' => count($ends), 'endo_total' => $endoTotal, 'final_premium' => (int)$p['premium'] + $endoTotal,
            'cancelled' => (bool)array_filter($ends, fn($e) => $e['kind'] === 'CANCELLATION'),
        ];
        if ($row['dup'] === 'file') { $row['ok'] = false; }
        $seenKey[$key] = true;
        if ($row['target'] === 'C') {
            $ck = legacy_norm_key($insName);
            if (!isset($companies[$ck])) $companies[$ck] = ['key' => $ck, 'name' => $insName, 'nid' => $insNid, 'count' => 0, 'suggest' => legacy_company_suggest($pdo, $insName, $insNid)];
            $companies[$ck]['count']++;
            $row['company_key'] = $ck;
        }
        $ek = legacy_norm_key($contract ?: 'بدون طرف قرارداد');
        if (!isset($employers[$ek])) $employers[$ek] = ['key' => $ek, 'name' => $contract ?: 'بدون طرف قرارداد', 'count' => 0, 'suggest' => $contract ? legacy_company_suggest($pdo, $contract) : null];
        $employers[$ek]['count']++;
        $row['employer_key'] = $ek;
        $rows[] = $row;
    }
    // الحاقیه‌هایی که بیمه‌نامه‌شان در این فایل‌ها نیست: اگر در سایت هست وصل می‌شوند، وگرنه «خارج از سایت»
    $orphans = [];
    foreach ($endoBy as $k => $list) {
        if (isset($seenKey[$k])) continue;
        $m = endo_find_policies($pdo, $list[0]['policy'], 1);
        foreach ($list as $e) $orphans[] = $e + ['site' => $m ? ($m[0]['source_fa'] . ' · ' . $m[0]['label']) : null];
    }
    $data = ['files' => $fileInfo, 'errors' => $errors, 'rows' => $rows, 'endorsements' => $endorses, 'orphans' => $orphans,
             'companies' => array_values($companies), 'employers' => array_values($employers)];
    $tok = bin2hex(random_bytes(10));
    file_put_contents(legacy_tmp_dir($siteRoot) . '/p_' . $tok . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    $sum = ['policies' => count($rows), 'new' => count(array_filter($rows, fn($r) => $r['ok'] && !$r['dup'])), 'exists' => count(array_filter($rows, fn($r) => in_array($r['dup'], ['system', 'import'], true))),
            'bad' => count(array_filter($rows, fn($r) => !$r['ok'] && !in_array($r['dup'], ['system', 'import'], true))), 'company' => count(array_filter($rows, fn($r) => $r['target'] === 'C')),
            'personnel' => count(array_filter($rows, fn($r) => $r['target'] === 'P')), 'endorsements' => count($endorses), 'orphans' => count($orphans)];
    return ['ok' => true, 'token' => $tok, 'summary' => $sum] + $data;
}

function legacy_load_preview($siteRoot, $tok) {
    if (!preg_match('/^[a-f0-9]{20}$/', (string)$tok)) return null;
    $p = legacy_tmp_dir($siteRoot) . '/p_' . $tok . '.json';
    return is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
}

// ---------------------------------------------------------------------
//  ثبت
// ---------------------------------------------------------------------
// $choice: rows => [i => ['target' => C|P, 'skip' => 0|1]], companies => [key => company_id|'new'], employers => [key => company_id|'new'|''],
//          finance => PREMIUM|INSTALLMENTS, quota => n, title
function legacy_commit($pdo, $siteRoot, $tok, array $choice, $userId) {
    legacy_ensure($pdo);
    $data = legacy_load_preview($siteRoot, $tok);
    if (!$data) return ['ok' => false, 'error' => 'زمانِ پیش‌نمایش گذشته؛ فایل‌ها را دوباره بفرستید.'];
    $fin = ($choice['finance'] ?? 'PREMIUM') === 'INSTALLMENTS' ? 'INSTALLMENTS' : 'PREMIUM';
    $noInst = $fin === 'PREMIUM' ? 1 : 0;
    $quotaDefault = max(1, (int)($choice['quota'] ?? 0) ?: (int)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_intro_quota'")->fetchColumn() ?: 4));
    $rowsCh = (array)($choice['rows'] ?? []);
    $sel = [];
    foreach ($data['rows'] as $r) {
        $c = $rowsCh[$r['i']] ?? [];
        if (!empty($c['skip']) || !$r['ok'] || $r['dup']) continue;
        $r['target'] = in_array($c['target'] ?? '', ['C', 'P'], true) ? $c['target'] : $r['target'];
        if ($r['target'] === 'P' && strlen((string)$r['holder_nid']) !== 10) { $r['target'] = 'C'; $r['_note'] = 'کد ملیِ ده‌رقمی نداشت؛ شرکتی ثبت شد.'; }
        $sel[] = $r;
    }
    if (!$sel && !($data['endorsements'] ?? [])) return ['ok' => false, 'error' => 'ردیفِ تازه‌ای برای ثبت نیست.'];

    $pdo->prepare("INSERT INTO legacy_batches (title, files, options, created_by) VALUES (?, ?, ?, ?)")
        ->execute([mb_substr((string)($choice['title'] ?? ''), 0, 250) ?: 'ورودِ بایگانیِ قبلی', json_encode(array_column($data['files'], 'name'), JSON_UNESCAPED_UNICODE),
                   json_encode(['finance' => $fin, 'quota' => $quotaDefault], JSON_UNESCAPED_UNICODE), $userId ?: null]);
    $batch = (int)$pdo->lastInsertId();
    $item = $pdo->prepare("INSERT INTO legacy_items (batch_id, kind, ref_id, request_id, person_id, intro_id, created_person, created_intro, created_company, policy_key, extra) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $done = ['company' => 0, 'personnel' => 0, 'requests' => 0, 'persons' => 0, 'intros' => 0, 'companies' => 0, 'endorsements' => 0, 'endo_skipped' => 0, 'errors' => []];
    $created = []; // policy_key => [source, ref_id]

    // ---- شرکت‌ها (نگاشت) ----
    $companyIds = [];
    $resolveCompany = function ($key, $name, $nid, $map) use ($pdo, &$companyIds, &$done, $batch, $item) {
        if (isset($companyIds[$key])) return $companyIds[$key];
        $v = $map[$key] ?? '';
        // طرفِ قراردادی که همان شرکتِ بیمه‌گذارِ همین ورود است (حتی با فاصله‌گذاریِ متفاوت) دوباره ساخته نمی‌شود
        if ($v === '' && strpos($key, 'emp:') === 0 && isset($companyIds[substr($key, 4)])) return $companyIds[$key] = $companyIds[substr($key, 4)];
        if ($v !== '' && $v !== 'new' && (int)$v > 0) return $companyIds[$key] = (int)$v;
        $sug = legacy_company_suggest($pdo, $name, $nid);
        if ($v === '' && $sug) return $companyIds[$key] = $sug;
        $pdo->prepare("INSERT INTO companies (name, kind, economic_code) VALUES (?, 'INSURANCE_CLIENT', ?)")->execute([mb_substr($name, 0, 190), $nid ?: null]);
        $id = (int)$pdo->lastInsertId();
        $item->execute([$batch, 'O', $id, null, null, null, 0, 0, $id, null, null]);
        $done['companies']++;
        return $companyIds[$key] = $id;
    };
    $compMap = (array)($choice['companies'] ?? []);
    $empMap = (array)($choice['employers'] ?? []);
    $GLOBALS['CIS_LEGACY'] = true;

    // ---- شرکتی: هر «شرکت + تاریخِ صدور» یک درخواست ----
    $reqOf = [];
    foreach ($sel as $r) {
        if ($r['target'] !== 'C') continue;
        try {
            $cid = $resolveCompany($r['company_key'] ?? legacy_norm_key($r['insured']), $r['insured'], $r['insured_id'], $compMap);
            $gk = $cid . '|' . $r['issue'];
            if (!isset($reqOf[$gk])) {
                $ts = policy_issue_ts($r['issue'], time());
                $pdo->prepare("INSERT INTO company_requests (company_id, request_text, insurer, request_kind, status, created_at, legacy_batch_id) VALUES (?, ?, 'PASARGAD', 'NEW_POLICY', 'ISSUED', FROM_UNIXTIME(?), ?)")
                    ->execute([$cid, 'بایگانیِ قبلی — صدور ' . $r['issue'], $ts, $batch]);
                $reqOf[$gk] = (int)$pdo->lastInsertId();
                $done['requests']++;
            }
            $p = $r['plate'] ?: ['p1' => null, 'p2' => null, 'letter' => null, 'p4' => null];
            $pf = $r['pf'];
            $info = array_filter([
                'insured_name' => $pf['insured_name'] ?? '', 'insured_national_id' => $pf['national_id'] ?? '', 'insured_phone' => $pf['phone'] ?? '',
                'insured_address' => $pf['address'] ?? '', 'owner_name' => $pf['owner_name'] ?? '', 'car_system' => $pf['car_system'] ?? '', 'car_type' => $pf['car_tip'] ?? '',
                'car_kind' => $pf['car_kind'] ?? '', 'car_model_year' => $pf['model_year'] ?? '', 'car_color' => $pf['color'] ?? '', 'car_usage' => $pf['usage'] ?? '',
                'chassis_no' => $r['vin'], 'engine_no' => $pf['engine_no'] ?? '', 'vin' => $r['vin'], 'prev_insurer' => $pf['prev_insurer'] ?? '',
                'prev_policy_number' => $pf['prev_policy'] ?? '', 'no_claim_years' => $pf['no_claim_years'] ?? '', 'central_no' => $pf['central_no'] ?? '',
                'start_date' => $pf['start_date'] ?? '', 'end_date' => $pf['end_date'] ?? '', 'contract_party' => $r['contract'],
            ], 'strlen');
            $exp = !empty($pf['end_date']) ? fin_jalali_to_date($pf['end_date']) : null;
            $pdo->prepare("INSERT INTO company_request_plates (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle, car_value, liability_limit,
                              insurance_type, expiry_date, status, skip_health_inspection, car_name, issue_info, no_installments, legacy_batch_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', 1, ?, ?, ?, ?)")
                ->execute([$reqOf[$gk], $p['p1'], $p['p2'], $p['letter'] ? mb_substr($p['letter'], 0, 5) : null, $p['p4'], $r['vin'] ?: null, ($pf['engine_no'] ?? '') ?: null,
                           $r['zero_km'], (int)($pf['car_value'] ?? 0) ?: null, (int)($pf['liability'] ?? 0) ?: null, $r['type'], $exp,
                           $r['car_name'] !== '' ? mb_substr($r['car_name'], 0, 100) : null, $info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null, $noInst, $batch]);
            $plateId = (int)$pdo->lastInsertId();
            $iss = company_issue_plate($pdo, $plateId, $r['policy'], $r['vin'] ?: null, $r['premium'] ?: null, null, null, $r['issue']);
            if (empty($iss['ok'])) throw new RuntimeException($iss['error'] ?? 'ثبتِ صدور نشد');
            $item->execute([$batch, 'C', $plateId, $reqOf[$gk], null, null, 0, 0, null, $r['key'], null]);
            $created[$r['key']] = ['C', $plateId];
            $done['company']++;
        } catch (Throwable $e) { $done['errors'][] = 'ردیفِ ' . $r['line'] . ' (' . $r['policy'] . '): ' . $e->getMessage(); }
    }
    $pdo->prepare("UPDATE company_requests cr SET status = 'ISSUED' WHERE legacy_batch_id = ?")->execute([$batch]);

    // ---- کارکنان: شخص + معرفی‌نامه (سهمیه) + پرونده‌ی صادرشده ----
    $byPerson = [];
    foreach ($sel as $r) if ($r['target'] === 'P') $byPerson[$r['holder_nid']][] = $r;
    foreach ($byPerson as $nid => $list) {
        try {
            usort($list, fn($a, $b) => strcmp($a['issue'], $b['issue']));
            $first = $list[0];
            $empId = null;
            $ek = $first['employer_key'] ?? '';
            if (($empMap[$ek] ?? '') !== 'none') {
                $empName = $first['contract'] ?: '';
                if ($empName !== '' || (int)($empMap[$ek] ?? 0) > 0) $empId = $resolveCompany('emp:' . $ek, $empName ?: 'بدون طرف قرارداد', '', ['emp:' . $ek => $empMap[$ek] ?? '']);
            }
            $st = $pdo->prepare("SELECT id FROM persons WHERE national_code = ?");
            $st->execute([$nid]);
            $personId = (int)$st->fetchColumn();
            $createdPerson = 0;
            if (!$personId) {
                $pdo->prepare("INSERT INTO persons (national_code, personnel_code, full_name, company_id, mobile_number) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$nid, $first['pcode'] ?: null, $first['holder'], $empId, preg_match('/^09\d{9}$/', $first['pf']['phone'] ?? '') ? $first['pf']['phone'] : null]);
                $personId = (int)$pdo->lastInsertId(); $createdPerson = 1; $done['persons']++;
            } elseif ($empId) {
                $pdo->prepare("UPDATE persons SET company_id = COALESCE(company_id, ?) WHERE id = ?")->execute([$empId, $personId]);
            }
            // معرفی‌نامه: یکی برای همه‌ی بیمه‌نامه‌های همین شخص در این ورود؛ تاریخش = اولین تاریخِ صدور
            [$jy, $jm, $jd] = array_map('intval', explode('/', $first['issue']));
            $quota = max($quotaDefault, count($list));
            $pdo->prepare("INSERT INTO introductions (person_id, file_path, letter_date, max_quota, used_quota, created_at) VALUES (?, 'بایگانی_قبلی', ?, ?, ?, FROM_UNIXTIME(?))")
                ->execute([$personId, sprintf('%04d-%02d-%02d', $jy, $jm, $jd), $quota, count($list), policy_issue_ts($first['issue'], time())]);
            $introId = (int)$pdo->lastInsertId(); $done['intros']++;
            foreach ($list as $k => $r) {
                $pf = $r['pf'];
                $ts = policy_issue_ts($r['issue'], time());
                $plate = $r['plate'] ? company_plate_display($r['plate']['p1'], $r['plate']['p2'], $r['plate']['letter'], $r['plate']['p4']) : null;
                $pdo->prepare("INSERT INTO policy_cases (introduction_id, person_id, insurance_type, unique_code, status, insured_relationship, insured_name, insured_national_id,
                                  insured_phone, insured_address, plate, ownership_choice, policy_number, vin, chassis_num, engine_num, total_premium, central_unique_code,
                                  car_system, car_type, car_model_year, car_color, car_usage, policy_issue_date, car_value, liability_limit, issued_at, created_at, updated_at,
                                  installments_generated, no_installments, legacy_batch_id)
                               VALUES (?, ?, ?, '', 'ISSUED', ?, ?, ?, ?, ?, ?, 'کارت ماشین', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?, ?, ?)")
                    ->execute([$introId, $personId, $r['type'], mb_substr($r['relation'], 0, 20), mb_substr($r['insured'], 0, 100), strlen($r['insured_id']) === 10 ? $r['insured_id'] : null,
                               preg_match('/^0\d{10}$/', $pf['phone'] ?? '') ? $pf['phone'] : null, $pf['address'] ?? null, $plate, $r['policy'], $r['vin'] ?: null, $r['vin'] ?: null,
                               ($pf['engine_no'] ?? '') ?: null, (string)$r['premium'], ($pf['central_no'] ?? '') ?: null, ($pf['car_system'] ?? '') ?: null, ($pf['car_tip'] ?? '') ?: null,
                               ($pf['model_year'] ?? '') ?: null, ($pf['color'] ?? '') ?: null, ($pf['usage'] ?? '') ?: null, $r['issue'], ($pf['car_value'] ?? '') ?: null,
                               (int)($pf['liability'] ?? 0) ?: null, $ts, $ts, $ts, $noInst ? 1 : 0, $noInst, $batch]);
                $caseId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE policy_cases SET unique_code = ? WHERE id = ?")->execute([generate_case_unique_code($caseId), $caseId]);
                // «اطلاعات صدور» (تاریخ شروع/انقضا، شماره مرکزی و ...) مثلِ صدورِ عادی؛ مغایرت‌گیری هم از همین می‌خواند
                $ii = policy_fields_to_issue_info($pf + ['vin' => $r['vin']]);
                if ($ii) $pdo->prepare("UPDATE policy_cases SET issue_info = ? WHERE id = ?")->execute([json_encode($ii, JSON_UNESCAPED_UNICODE), $caseId]);
                legacy_personnel_folders($pdo, $siteRoot, $caseId, null);
                if (!$noInst) { try { fin_generate_installments($pdo, $caseId); } catch (Throwable $e) {} }
                $item->execute([$batch, 'P', $caseId, null, $personId, $introId, $k === 0 ? $createdPerson : 0, $k === 0 ? 1 : 0, null, $r['key'], null]);
                $created[$r['key']] = ['P', $caseId];
                $done['personnel']++;
            }
            sync_intro_folder($pdo, $siteRoot, $introId);
        } catch (Throwable $e) { $done['errors'][] = 'کد ملیِ ' . $nid . ': ' . $e->getMessage(); }
    }
    unset($GLOBALS['CIS_LEGACY']);

    // ---- الحاقیه‌ها: روی بیمه‌نامه‌های همین ورود یا بیمه‌نامه‌های موجود در سایت؛ بدونِ اثر روی اقساط ----
    foreach ((array)($data['endorsements'] ?? []) as $e) {
        if (!$e['ok']) continue;
        $ref = $created[$e['key']] ?? null;
        if (!$ref) { $m = endo_find_policies($pdo, $e['policy'], 1); $ref = $m ? [$m[0]['source'], $m[0]['ref_id']] : ['X', null]; }
        $res = endo_create($pdo, $siteRoot, ['source' => $ref[0], 'ref_id' => $ref[1], 'policy_number' => $e['policy'], 'endorse_no' => $e['endorse_no'], 'kind' => $e['kind'],
                                             'issue_date' => $e['issue_date'], 'effect_date' => $e['effect_date'], 'new_end_date' => $e['kind'] === 'CORRECTIVE' ? '' : $e['end_date'],
                                             'gross' => $e['gross'], 'net' => $e['net'], 'tax' => $e['tax'], 'reason' => $e['reason'], 'description' => $e['description'],
                                             'finance_mode' => 'NONE', 'origin' => 'LEGACY', 'batch_id' => $batch, 'insurance_type' => $e['type']], null, $userId);
        if (!empty($res['ok'])) $done['endorsements']++; else $done['endo_skipped']++;
    }
    $pdo->prepare("UPDATE legacy_batches SET counts = ? WHERE id = ?")->execute([json_encode($done, JSON_UNESCAPED_UNICODE), $batch]);
    @unlink(legacy_tmp_dir($siteRoot) . '/p_' . $tok . '.json');
    return ['ok' => true, 'batch_id' => $batch] + $done;
}

// پوشه‌ی پرونده‌ی کارکنان (همان نام‌گذاریِ صدور: «پلاک - نام - شماره بیمه‌نامه - VIN») + فایلِ اطلاعات + کپی به «بایگانی صادره»
function legacy_personnel_folders($pdo, $siteRoot, $caseId, $policyFile = null) {
    $st = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $st->execute([$caseId]);
    $case = $st->fetch();
    if (!$case) return null;
    $plateForName = plate_for_filename($case['plate'] ?: ($case['vin'] ?: 'بدون‌پلاک'));
    $polForName = policy_number_for_filename($case['policy_number']);
    $name = build_case_folder_name_issued($plateForName, $case['insured_name'], $polForName, $case['vin']);
    $folder = resolve_case_folder($pdo, $siteRoot, $case);
    $target = dirname($folder) . '/' . $name;
    if (is_dir($folder) && $folder !== $target) { @rename($folder, $target); }
    if (!is_dir($target)) @mkdir($target, 0777, true);
    $pdo->prepare("UPDATE policy_cases SET folder_path = ? WHERE id = ?")->execute([$target, $caseId]);
    if ($policyFile && is_file($policyFile)) {
        $ext = strtolower(pathinfo($policyFile, PATHINFO_EXTENSION)) ?: 'pdf';
        $dest = unique_dest_path($target . '/' . build_final_policy_filename($plateForName, $case['insured_name'], $polForName, $case['vin'], $ext));
        if (@copy($policyFile, $dest)) $pdo->prepare("UPDATE policy_cases SET issued_file_path = ? WHERE id = ?")->execute([ltrim(str_replace($siteRoot, '', $dest), '/'), $caseId]);
    }
    write_case_info_file($pdo, $target, $caseId);
    $sadere = build_sadere_base_path($siteRoot, policy_issue_ts($case['policy_issue_date'], strtotime((string)$case['issued_at']) ?: time()), $case['insurance_type']) . '/' . $name;
    if (!is_dir($sadere)) @mkdir($sadere, 0777, true);
    copy_dir_recursive($target, $sadere);
    return $target;
}

// ---------------------------------------------------------------------
//  دسته‌ها و برگرداندن
// ---------------------------------------------------------------------
function legacy_batches($pdo) {
    legacy_ensure($pdo);
    $rows = $pdo->query("SELECT b.*, u.full_name AS creator,
                                (SELECT COUNT(*) FROM legacy_items i WHERE i.batch_id = b.id AND i.kind = 'C') AS n_company,
                                (SELECT COUNT(*) FROM legacy_items i WHERE i.batch_id = b.id AND i.kind = 'P') AS n_personnel,
                                (SELECT COUNT(*) FROM policy_endorsements e WHERE e.batch_id = b.id) AS n_endo
                           FROM legacy_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 200")->fetchAll();
    foreach ($rows as &$r) {
        $r['created_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($r['created_at']));
        $r['counts'] = json_decode((string)$r['counts'], true) ?: [];
        $r['options'] = json_decode((string)$r['options'], true) ?: [];
        $r['files'] = json_decode((string)$r['files'], true) ?: [];
    }
    return $rows;
}

// حذفِ کاملِ یک دسته: الحاقیه‌ها، ردیف‌ها و درخواست‌های شرکتی، پرونده‌ها، معرفی‌نامه‌ها و اشخاصِ تازه، شرکت‌های تازه، و پوشه‌ها
function legacy_rollback($pdo, $siteRoot, $batchId) {
    legacy_ensure($pdo);
    $b = $pdo->prepare("SELECT * FROM legacy_batches WHERE id = ?");
    $b->execute([$batchId]);
    if (!$b->fetch()) return ['ok' => false, 'error' => 'دسته پیدا نشد.'];
    $n = ['company' => 0, 'personnel' => 0, 'endorsements' => 0];
    foreach ($pdo->query("SELECT id FROM policy_endorsements WHERE batch_id = " . intval($batchId))->fetchAll(PDO::FETCH_COLUMN) as $eid) { endo_delete($pdo, (int)$eid, false); $n['endorsements']++; }
    $items = $pdo->prepare("SELECT * FROM legacy_items WHERE batch_id = ? ORDER BY id DESC");
    $items->execute([$batchId]);
    $items = $items->fetchAll();
    // پوشه‌های خالی‌شده‌ی بالادست (شرکت/ماه/تاریخ/نوع) هم پاک می‌شوند، تا ریشه‌ی بایگانی
    $root = rtrim(archive_root($siteRoot), '/');
    $rm = function ($dir) use ($root) {
        if (!$dir || !is_dir($dir)) return;
        archive_rrmdir($dir);
        $p = dirname($dir);
        while (strlen($p) > strlen($root) && strpos($p, $root . '/') === 0 && is_dir($p) && count((array)@scandir($p)) <= 2) { @rmdir($p); $p = dirname($p); }
    };
    $sadereDirs = function ($folder) use ($siteRoot) {
        $out = [];
        if (!$folder) return $out;
        foreach ((array)glob(archive_root($siteRoot) . '/بایگانی صادره/*/*/*/' . basename($folder), GLOB_ONLYDIR) as $d) $out[] = $d;
        return $out;
    };
    foreach ($items as $it) {
        if ($it['kind'] === 'C') {
            $r = $pdo->query("SELECT folder_path FROM company_request_plates WHERE id = " . intval($it['ref_id']))->fetch();
            if ($r) {
                foreach ($sadereDirs($r['folder_path']) as $d) $rm($d);
                $rm($r['folder_path']);
                $pdo->exec("DELETE FROM company_installments WHERE plate_id = " . intval($it['ref_id']));
                $pdo->exec("DELETE FROM company_documents WHERE plate_id = " . intval($it['ref_id']));
                $pdo->exec("DELETE FROM company_request_plates WHERE id = " . intval($it['ref_id']));
                $n['company']++;
            }
        } elseif ($it['kind'] === 'P') {
            $r = $pdo->query("SELECT folder_path, introduction_id FROM policy_cases WHERE id = " . intval($it['ref_id']))->fetch();
            if ($r) {
                foreach ($sadereDirs($r['folder_path']) as $d) $rm($d);
                $rm($r['folder_path']);
                $pdo->exec("DELETE FROM policy_installments WHERE case_id = " . intval($it['ref_id']));
                $pdo->exec("DELETE FROM case_documents WHERE case_id = " . intval($it['ref_id']));
                $pdo->exec("DELETE FROM policy_cases WHERE id = " . intval($it['ref_id']));
                $n['personnel']++;
            }
        }
    }
    foreach ($items as $it) {
        if ($it['kind'] === 'P' && $it['created_intro'] && $it['intro_id']) {
            $f = $pdo->query("SELECT folder_path FROM introductions WHERE id = " . intval($it['intro_id']))->fetchColumn();
            $rm($f);
            $pdo->exec("DELETE FROM introductions WHERE id = " . intval($it['intro_id']));
        }
        if ($it['kind'] === 'P' && $it['created_person'] && $it['person_id']) {
            if (!(int)$pdo->query("SELECT COUNT(*) FROM policy_cases WHERE person_id = " . intval($it['person_id']))->fetchColumn())
                $pdo->exec("DELETE FROM persons WHERE id = " . intval($it['person_id']));
        }
    }
    // درخواست‌های خالی‌شده و شرکت‌هایی که همین دسته ساخته بود (اگر چیزِ دیگری ندارند)
    $pdo->exec("DELETE FROM company_requests WHERE legacy_batch_id = " . intval($batchId) . " AND NOT EXISTS (SELECT 1 FROM company_request_plates p WHERE p.request_id = company_requests.id)");
    foreach ($items as $it) {
        if ($it['kind'] === 'O' && $it['created_company']) {
            $cid = intval($it['created_company']);
            if (!(int)$pdo->query("SELECT COUNT(*) FROM company_requests WHERE company_id = $cid")->fetchColumn() && !(int)$pdo->query("SELECT COUNT(*) FROM persons WHERE company_id = $cid")->fetchColumn())
                $pdo->exec("DELETE FROM companies WHERE id = $cid");
        }
    }
    $pdo->prepare("DELETE FROM legacy_items WHERE batch_id = ?")->execute([$batchId]);
    $pdo->prepare("UPDATE legacy_batches SET rolled_back = 1 WHERE id = ?")->execute([$batchId]);
    return ['ok' => true] + $n;
}
