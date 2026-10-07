<?php
// فایل: api/_import_archive.php
// «بایگانی وارداتی»: خواندنِ خروجیِ اکسلِ بیمه‌گر (پاسارگاد - بدنه و ثالث)، ساختنِ ردیف‌ها و پوشه‌ها،
// و فهرستِ ردیف‌ها با وضعیت و کمبودها. ردیف‌ها همان company_request_plates هستند (با درخواستِ is_import=1)،
// پس بارگذاریِ مدرک، ساختِ گزارش بازدید، ثبتِ صدور و صدورِ گروهی همان روالِ شرکتی را طی می‌کنند.
// نیاز: _case_helpers.php، _company_helpers.php و finance_core.php پیش از این فایل.

// ستون‌های اکسلِ بیمه‌گر => کلیدِ داخلی (نام‌ها با company_normalize_header یکدست می‌شوند: فاصله، نیم‌فاصله، ی/ک)
function imp_excel_columns() {
    return [
        'policy_num' => ['شماره بیمه‌نامه', 'شماره بیمه نامه'], 'ins_type_raw' => ['نوع بیمه نامه', 'نوع بیمه‌نامه'],
        'insured' => ['بیمه گذار', 'بیمه‌گذار'], 'insured_legal' => ['بیمه گذار حقوقی'], 'first_name' => ['نام بیمه گذار'], 'last_name' => ['نام خانوادگی بیمه گذار'],
        'insured_kind' => ['نوع بیمه گذار: حقیقی-حقوقی', 'نوع بیمه گذار'], 'legal_id' => ['شناسه ملی حقوقی'], 'nid' => ['کد ملی بیمه گذار'],
        'economic_code' => ['کد اقتصادی حقوقی'], 'mobile' => ['موبایل'], 'tel' => ['تلفن'], 'address' => ['آدرس', 'نشانی'], 'postal_code' => ['کد پستی'],
        'city' => ['شهر'], 'province' => ['استان'], 'issue_date' => ['تاریخ صدور'], 'start_date' => ['تاریخ شروع'], 'end_date' => ['تاریخ انقضا'],
        'plate_raw' => ['شماره انتظامی'], 'plate_kind' => ['نوع پلاک'], 'vin' => ['شماره شاسی'], 'engine_no' => ['شماره موتور'],
        'car_name' => ['نام وسیله نقلیه'], 'car_tip' => ['تیپ وسیله نقلیه'], 'car_system' => ['سیستم خودرو'], 'car_kind' => ['نوع وسیله نقلیه'],
        'model_year' => ['سال ساخت وسیله نقلیه'], 'color' => ['رنگ وسیله نقلیه'], 'usage' => ['مورد استفاده وسیله نقلیه'], 'capacity' => ['ظرفیت وسیله نقلیه'],
        'cylinders' => ['تعداد سیلندر'], 'cargo' => ['اتاق بار'], 'premium' => ['حق بیمه به عدد', 'حق بیمه'], 'central_no' => ['شماره بیمه مرکزی'],
        'contract_party' => ['طرف قرارداد'], 'contract_no' => ['شماره قرارداد'], 'prev_insurer' => ['بیمه گر قبلی'], 'prev_policy' => ['شماره بیمه نامه قبلی'],
        'prev_expiry' => ['تاریخ انقضای قبلی'], 'zero_km' => ['صفر کیلومتر؟', 'صفر کیلومتر'], 'car_value' => ['ارزش وسیله نقلیه به عدد', 'ارزش وسیله نقلیه'],
        'total_value' => ['سرمایه کل'], 'trailer_value' => ['ارزش یدک'], 'extras_value' => ['ارزش وسایل اضافی'], 'covers' => ['نوع پوشش اضافی'],
        'no_claim_years' => ['تعداد سال عدم خسارت'], 'liability' => ['تعهد مالی'], 'diya' => ['تعهد جانی'], 'driver_cover' => ['تعهد سرنشین'],
        'prev_claims' => ['وضعیت خسارت ثالث بیمه نامه قبلی'], 'ncd' => ['درصد تخفیف عدم خسارت ثالث'], 'owner_name' => ['مالک', 'نام مالک'],
        'customer_no' => ['شماره مشتری'],
        // ورودِ بایگانیِ قبلی (api/_legacy.php): پرداخت‌کننده، الگوی مالی، کد پرسنلی
        'payer_name' => ['نام پرداخت کننده'], 'payer_last' => ['نام خانوادگی پرداخت کننده'], 'payer_nid' => ['کد ملی پرداخت کننده'],
        'payer_pcode' => ['کد پرسنلی پرداخت کننده'], 'fin_pattern' => ['الگوی مالی'], 'pcode' => ['کد پرسنلی'],
        'birth_date' => ['تاریخ تولد بیمه گذار'], 'endorse_no_col' => ['شماره الحاقیه'],
    ];
}

// مقدارِ خالیِ خروجیِ بیمه‌گر: «-»، «----- - -(-)»، «0» برای متن
function imp_clean($v) {
    $v = trim(str_replace(['ي', 'ك', "\xC2\xA0"], ['ی', 'ک', ' '], (string)$v));
    if ($v === '' || preg_match('/^[\s\-–_()0.]*$/u', $v) && !preg_match('/[1-9]/', $v)) return '';
    return preg_replace('/\s+/u', ' ', $v);
}
function imp_int($v) { $n = preg_replace('/[^\d]/', '', p2e_digits((string)$v)); return $n === '' ? 0 : intval($n); }
function imp_jdate($v) {
    $v = p2e_digits((string)$v);
    if (!preg_match('/(1[34]\d{2})\D+(\d{1,2})\D+(\d{1,2})/', $v, $m)) return '';
    if ($m[2] < 1 || $m[2] > 12 || $m[3] < 1 || $m[3] > 31) return '';
    return sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
}

// «987ع41 - ایران - 21(ایران)» => [p1=21, p2=987, letter=ع, p4=41] ؛ بدونِ پلاک => null
function imp_parse_plate($raw) {
    $s = p2e_digits(str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$raw));
    if (preg_match('/(\d{3})\s*([^\d\s\-()]{1,4})\s*(\d{2})\s*-\s*ایران\s*-\s*(\d{2})/u', $s, $m)) {
        return ['p1' => $m[4], 'p2' => $m[1], 'letter' => $m[2], 'p4' => $m[3]];
    }
    // شکل‌های دیگر (۲۱ ایران ۹۸۷ ع ۴۱ و ...) با همان تجزیه‌گرِ ردیف‌های شرکتی
    $p = function_exists('company_parse_plate_text') ? company_parse_plate_text($s) : null;
    return $p && !empty($p['p2']) ? $p : null;
}

// خواندنِ اکسل => ['rows' => [...], 'unknown' => [...]] یا ['error' => ...]
function imp_parse_excel($pdo, $path) {
    $table = fin_read_spreadsheet($path);
    if (!$table || count($table) < 2) return ['error' => 'فایل خوانده نشد یا ردیفی ندارد.'];
    $lookup = [];
    foreach (imp_excel_columns() as $k => $names) foreach ($names as $n) $lookup[company_normalize_header($n)] = $k;
    $headerIdx = -1; $cols = [];
    foreach ($table as $i => $row) {
        $found = [];
        foreach ((array)$row as $c => $cell) {
            $nh = company_normalize_header($cell);
            if ($nh !== '' && isset($lookup[$nh]) && !in_array($lookup[$nh], $found, true)) $found[$c] = $lookup[$nh];
        }
        if (count($found) >= 4) { $headerIdx = $i; $cols = $found; break; }
        if ($i > 10) break;
    }
    if ($headerIdx < 0 || !in_array('policy_num', $cols, true)) {
        return ['error' => 'سرستون‌های خروجیِ بیمه‌گر پیدا نشد (حداقل «شماره بیمه‌نامه»، «نوع بیمه نامه»، «شماره انتظامی» یا «شماره شاسی» و «تاریخ صدور» لازم است).'];
    }

    // تکراری‌ها: هم در «بایگانی وارداتی» و هم بیمه‌نامه‌هایی که خودِ سامانه صادر کرده
    $existsImp = $pdo->prepare("SELECT crp.id, crp.status FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                                 WHERE crp.import_policy_number = ? AND cr.is_import = 1 LIMIT 1");
    $existsSys = $pdo->prepare("SELECT crp.id FROM company_request_plates crp WHERE crp.policy_number = ? LIMIT 1");
    $existsCase = null;
    try { $existsCase = $pdo->prepare("SELECT id FROM policy_cases WHERE policy_number = ? LIMIT 1"); } catch (Throwable $e) {}

    $out = []; $seen = [];
    foreach (array_slice($table, $headerIdx + 1) as $li => $cells) {
        $r = [];
        foreach ($cols as $c => $k) $r[$k] = imp_clean($cells[$c] ?? '');
        if (!array_filter($r, 'strlen')) continue;
        $line = $headerIdx + $li + 2;
        $errors = []; $warn = [];

        $type = mb_strpos($r['ins_type_raw'] ?? '', 'بدنه') !== false ? 'BODY' : (mb_strpos($r['ins_type_raw'] ?? '', 'ثالث') !== false ? 'THIRDPARTY' : null);
        if (!$type) $errors[] = 'نوعِ بیمه‌نامه (بدنه/ثالث) شناخته نشد' . (!empty($r['ins_type_raw']) ? ': ' . $r['ins_type_raw'] : '') . '.';
        $plate = !empty($r['plate_raw']) ? imp_parse_plate($r['plate_raw']) : null;
        $vin = strtoupper(preg_replace('/\s+/', '', p2e_digits($r['vin'] ?? '')));
        if (!$plate && $vin === '') $errors[] = 'نه پلاک دارد نه شماره شاسی.';
        if (!$plate && !empty($r['plate_raw']) && ($r['plate_kind'] ?? '') !== '' && mb_strpos($r['plate_kind'], 'فاقد') === false) $warn[] = 'پلاک خوانده نشد: ' . $r['plate_raw'];
        $policy = trim(p2e_digits($r['policy_num'] ?? ''));
        if ($policy === '') $errors[] = 'شماره بیمه‌نامه خالی است.';
        $issue = imp_jdate($r['issue_date'] ?? '');
        if ($issue === '') $errors[] = 'تاریخ صدور خالی یا نامعتبر است.';

        // بیمه‌گذار: حقوقی => نامِ شرکت، حقیقی => نام و نام خانوادگی
        $insured = $r['insured'] ?? '';
        if ($insured === '') $insured = $r['insured_legal'] ?? '';
        if ($insured === '') $insured = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        if ($insured === '') { $insured = 'بیمه‌گذارِ نامشخص'; $warn[] = 'نامِ بیمه‌گذار خالی است.'; }
        $insuredId = p2e_digits($r['legal_id'] ?? '') ?: p2e_digits($r['nid'] ?? '');

        $prevExp = imp_jdate($r['prev_expiry'] ?? '');
        $start = imp_jdate($r['start_date'] ?? '');
        $hasPrev = ($r['prev_policy'] ?? '') !== '' || ($r['prev_insurer'] ?? '') !== '';
        $zero = preg_match('/بل(ی|ه)|yes/ui', $r['zero_km'] ?? '') ? 1 : 0;
        $carName = ($r['car_name'] ?? '') !== '' ? $r['car_name'] : trim(($r['car_system'] ?? '') . ' ' . ($r['car_tip'] ?? ''));

        // داده‌ی کامل با همان کلیدهای فرمِ صدور (برای پیش‌پر کردنِ «ثبت صدور»)
        $pf = array_filter([
            'policy_num' => $policy, 'premium' => (string)(imp_int($r['premium'] ?? '') ?: ''), 'issue_date' => $issue, 'start_date' => $start,
            'end_date' => imp_jdate($r['end_date'] ?? ''), 'central_no' => p2e_digits($r['central_no'] ?? ''),
            'ins_type' => $type === 'BODY' ? 'بدنه' : ($type ? 'ثالث' : ''), 'ins_company' => 'پاسارگاد',
            'car_value' => (string)(imp_int($r['car_value'] ?? '') ?: ''), 'total_value' => (string)(imp_int($r['total_value'] ?? '') ?: ''),
            'trailer_value' => (string)(imp_int($r['trailer_value'] ?? '') ?: ''), 'extras_value' => (string)(imp_int($r['extras_value'] ?? '') ?: ''),
            'liability' => (string)(imp_int($r['liability'] ?? '') ?: ''), 'diya' => (string)(imp_int($r['diya'] ?? '') ?: ''),
            'driver_cover' => (string)(imp_int($r['driver_cover'] ?? '') ?: ''), 'ncd' => $r['ncd'] ?? '', 'covers' => $r['covers'] ?? '',
            'insured_name' => $insured, 'national_id' => $insuredId, 'phone' => p2e_digits($r['mobile'] ?? '') ?: p2e_digits($r['tel'] ?? ''),
            'postal_code' => p2e_digits($r['postal_code'] ?? ''), 'address' => preg_replace('/^[\s\-،]+|[\s\-،]+$/u', '', implode('، ', array_filter([$r['province'] ?? '', $r['city'] ?? '']))
                . (($r['address'] ?? '') !== '' ? ' - ' . $r['address'] : '')),
            'plate' => $plate ? company_plate_display($plate['p1'], $plate['p2'], $plate['letter'], $plate['p4']) : '',
            'vin' => $vin, 'engine_no' => strtoupper(p2e_digits($r['engine_no'] ?? '')), 'car_kind' => $r['car_kind'] ?? '', 'car_system' => $r['car_system'] ?? '',
            'car_tip' => $r['car_tip'] ?? '', 'car_name' => $carName, 'model_year' => p2e_digits($r['model_year'] ?? ''), 'color' => $r['color'] ?? '',
            'usage' => $r['usage'] ?? '', 'capacity' => p2e_digits($r['capacity'] ?? ''), 'cylinders' => p2e_digits($r['cylinders'] ?? ''), 'cargo' => $r['cargo'] ?? '',
            'prev_insurer' => $r['prev_insurer'] ?? '', 'prev_policy' => p2e_digits($r['prev_policy'] ?? ''), 'prev_expiry' => $prevExp,
            'prev_claims' => $r['prev_claims'] ?? '', 'no_claim_years' => p2e_digits($r['no_claim_years'] ?? ''), 'owner_name' => $r['owner_name'] ?? '',
            'contract_party' => $r['contract_party'] ?? '', 'contract_no' => p2e_digits($r['contract_no'] ?? ''), 'insured_kind' => $r['insured_kind'] ?? '',
            'economic_code' => p2e_digits($r['economic_code'] ?? ''), 'customer_no' => p2e_digits($r['customer_no'] ?? ''), 'zero_km' => $zero ? 'بله' : '',
        ], fn($v) => $v !== '' && $v !== null);

        // تکراری
        $dup = null;
        if ($policy !== '') {
            if (isset($seen[$policy])) { $dup = 'file'; $errors[] = 'همین بیمه‌نامه در ردیفِ ' . fa_digits($seen[$policy]) . ' همین فایل هم آمده.'; }
            else {
                $existsImp->execute([$policy]);
                if ($e = $existsImp->fetch()) { $dup = 'import'; $errors[] = 'این بیمه‌نامه قبلاً وارد شده (ردیف #' . fa_digits($e['id']) . ').'; }
                else {
                    $existsSys->execute([$policy]);
                    $hit = $existsSys->fetchColumn();
                    if (!$hit && $existsCase) { $existsCase->execute([$policy]); $hit = $existsCase->fetchColumn() ? 'case' : false; }
                    if ($hit) { $dup = 'system'; $errors[] = 'این بیمه‌نامه را خودِ سامانه صادر کرده و در «صادره‌ها» هست.'; }
                }
            }
            if (!isset($seen[$policy])) $seen[$policy] = $line;
        }

        [$jy, $jm] = $issue ? array_map('intval', explode('/', $issue)) : [0, 0];
        $out[] = [
            'line' => $line, 'ok' => !$errors, 'errors' => $errors, 'warnings' => $warn, 'dup' => $dup,
            'type' => $type, 'type_fa' => $type ? insurance_type_fa($type) : '', 'plate' => $plate, 'vin' => $vin, 'policy' => $policy, 'issue' => $issue,
            'month' => $issue ? sprintf('%04d-%02d', $jy, $jm) : '', 'month_fa' => $issue ? jalali_month_name($jm) . ' ' . $jy : '',
            'insured' => $insured, 'insured_id' => $insuredId, 'contract_party' => $r['contract_party'] ?? '', 'car_name' => $carName,
            'premium' => imp_int($r['premium'] ?? ''), 'has_prev_body' => $type === 'BODY' ? ($hasPrev ? 'YES' : 'NO') : null,
            'expiry' => $prevExp ?: ($zero ? '' : $start), 'zero_km' => $zero, 'pf' => $pf, 'raw' => $r,
        ];
    }
    if (!$out) return ['error' => 'زیرِ سرستون‌ها ردیفی پیدا نشد.'];
    return ['rows' => $out];
}

// «شرکتِ وارداتی» (بیمه‌گذار): با شناسه/کد ملی یا نامِ یکسان، فقط میانِ وارداتی‌ها
function imp_find_or_create_insured($pdo, $name, $nid, $phone, $address) {
    if ($nid !== '') {
        $st = $pdo->prepare("SELECT id FROM companies WHERE is_import = 1 AND economic_code = ? LIMIT 1");
        $st->execute([$nid]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    $nk = company_normalize_header($name);
    foreach ($pdo->query("SELECT id, name FROM companies WHERE is_import = 1") as $c) if (company_normalize_header($c['name']) === $nk) return (int)$c['id'];
    $pdo->prepare("INSERT INTO companies (name, kind, economic_code, phone, address, is_import) VALUES (?, 'INSURANCE_CLIENT', ?, ?, ?, 1)")
        ->execute([mb_substr($name, 0, 190), $nid ?: null, $phone ? mb_substr($phone, 0, 30) : null, $address ?: null]);
    return (int)$pdo->lastInsertId();
}

// ثبت: برای هر (بیمه‌گذار، ماهِ صدور) یک «درخواست» و زیرش ردیف‌ها؛ پوشه‌ها همان لحظه ساخته می‌شوند
// $opts: group_by = insured|contract ؛ skip = [line => 1] (بدنه‌هایی که بازدید نمی‌خواهند)
function imp_commit($pdo, array $rows, $fileName, array $opts, $userId) {
    $siteRoot = dirname(__DIR__);
    $groupBy = ($opts['group_by'] ?? '') === 'contract' ? 'contract' : 'insured';
    $skip = (array)($opts['skip'] ?? []);
    $only = isset($opts['lines']) && is_array($opts['lines']) ? array_flip(array_map('intval', $opts['lines'])) : null;
    $ok = array_values(array_filter($rows, fn($r) => $r['ok'] && ($only === null || isset($only[$r['line']]))));
    if (!$ok) return ['ok' => false, 'error' => 'ردیفِ قابلِ ثبتی نیست (همه تکراری یا ناقص‌اند).'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO import_batches (file_name, rows_total, rows_created, rows_skipped, group_by, created_by) VALUES (?, ?, 0, 0, ?, ?)")
            ->execute([mb_substr((string)$fileName, 0, 250), count($rows), $groupBy, $userId ?: null]);
        $batchId = (int)$pdo->lastInsertId();
        $reqIds = []; $plateIds = [];
        $insReq = $pdo->prepare("INSERT INTO company_requests (company_id, request_text, insurer, request_kind, status, is_import, import_batch_id, import_month)
                                 VALUES (?, ?, 'PASARGAD', 'NEW_POLICY', 'DOCS_PENDING', 1, ?, ?)");
        $insRow = $pdo->prepare("INSERT INTO company_request_plates (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle,
                                    car_value, liability_limit, insurance_type, expiry_date, status, skip_health_inspection, has_prev_body, car_name, issue_info,
                                    import_policy_number, import_issue_date, import_json)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, ?, ?, ?, ?, ?, ?)");
        foreach ($ok as $r) {
            $pf = $r['pf'];
            $owner = $groupBy === 'contract' && $r['contract_party'] !== '' ? $r['contract_party'] : $r['insured'];
            $ownerId = $groupBy === 'contract' && $r['contract_party'] !== '' ? '' : $r['insured_id'];
            $cid = imp_find_or_create_insured($pdo, $owner, $ownerId, $pf['phone'] ?? '', $pf['address'] ?? '');
            $key = $cid . '|' . $r['month'];
            if (!isset($reqIds[$key])) {
                $insReq->execute([$cid, 'ورود از اکسلِ بیمه‌گر: ' . $fileName, $batchId, $r['month'] ?: null]);
                $reqIds[$key] = (int)$pdo->lastInsertId();
            }
            $p = $r['plate'] ?: ['p1' => null, 'p2' => null, 'letter' => null, 'p4' => null];
            $info = array_filter([
                'insured_name' => $pf['insured_name'] ?? '', 'insured_national_id' => $pf['national_id'] ?? '', 'insured_phone' => $pf['phone'] ?? '',
                'insured_postal_code' => $pf['postal_code'] ?? '', 'insured_address' => $pf['address'] ?? '', 'owner_name' => $pf['owner_name'] ?? '',
                'car_system' => $pf['car_system'] ?? '', 'car_type' => $pf['car_tip'] ?? '', 'car_kind' => $pf['car_kind'] ?? '', 'car_model_year' => $pf['model_year'] ?? '',
                'car_color' => $pf['color'] ?? '', 'car_usage' => $pf['usage'] ?? '', 'car_capacity' => $pf['capacity'] ?? '', 'car_cylinders' => $pf['cylinders'] ?? '',
                'chassis_no' => $r['vin'], 'engine_no' => $pf['engine_no'] ?? '', 'vin' => $r['vin'], 'prev_insurer' => $pf['prev_insurer'] ?? '',
                'prev_policy_number' => $pf['prev_policy'] ?? '', 'no_claim_years' => $pf['no_claim_years'] ?? '',
            ], 'strlen');
            $exp = $r['expiry'] ? fin_jalali_to_date($r['expiry']) : null;
            $insRow->execute([
                $reqIds[$key], $p['p1'], $p['p2'], $p['letter'] ? mb_substr($p['letter'], 0, 5) : null, $p['p4'], $r['vin'] ?: null,
                ($pf['engine_no'] ?? '') ?: null, $r['zero_km'], intval($pf['car_value'] ?? 0) ?: null, intval($pf['liability'] ?? 0) ?: null,
                $r['type'], $exp, ($r['type'] === 'BODY' && !empty($skip[$r['line']])) ? 1 : 0, $r['has_prev_body'],
                $r['car_name'] !== '' ? mb_substr($r['car_name'], 0, 100) : null, $info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null,
                $r['policy'], $r['issue'], json_encode($pf, JSON_UNESCAPED_UNICODE),
            ]);
            $plateIds[] = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE import_batches SET rows_created = ?, rows_skipped = ? WHERE id = ?")->execute([count($plateIds), count($rows) - count($plateIds), $batchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[imp_commit] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'ثبت در دیتابیس ممکن نشد: ' . $e->getMessage()];
    }
    // پوشه‌ها و وضعیتِ اولیه (بیرون از تراکنش؛ کارِ دیسک)
    @mkdir(imp_archive_root($siteRoot), 0775, true);
    foreach ($plateIds as $id) { ensure_plate_folder($pdo, $siteRoot, $id); company_sync_plate_status($pdo, $id); }
    return ['ok' => true, 'batch_id' => $batchId, 'created' => count($plateIds), 'requests' => count($reqIds), 'skipped' => count($rows) - count($plateIds)];
}

function imp_month_fa($m) {
    return preg_match('/^(1[34]\d{2})-(\d{2})$/', (string)$m, $x) ? jalali_month_name(intval($x[2])) . ' ' . $x[1] : '';
}

// فهرستِ ردیف‌ها با چک‌لیست، کمبودها و وضعیت
function imp_list($pdo, array $f) {
    $w = ["cr.is_import = 1", "crp.status <> 'CANCELLED'"]; $p = [];
    if (!empty($f['company_id'])) { $w[] = "cr.company_id = ?"; $p[] = intval($f['company_id']); }
    if (!empty($f['batch_id']))   { $w[] = "cr.import_batch_id = ?"; $p[] = intval($f['batch_id']); }
    if (!empty($f['month']))      { $w[] = "cr.import_month = ?"; $p[] = (string)$f['month']; }
    if (!empty($f['type']) && in_array($f['type'], ['BODY', 'THIRDPARTY'], true)) { $w[] = "crp.insurance_type = ?"; $p[] = $f['type']; }
    // فیلترِ وضعیت بعد از شمارش اعمال می‌شود تا کارت‌های آمار همه‌ی وضعیت‌ها را نشان دهند
    $st = (string)($f['status'] ?? '');
    if (($f['inspection'] ?? '') === 'skip') $w[] = "crp.insurance_type = 'BODY' AND crp.skip_health_inspection = 1";
    if (($f['inspection'] ?? '') === 'need') $w[] = "crp.insurance_type = 'BODY' AND crp.skip_health_inspection = 0";
    if (!empty($f['ids']) && is_array($f['ids'])) { $ids = array_filter(array_map('intval', $f['ids'])); if ($ids) $w[] = 'crp.id IN (' . implode(',', $ids) . ')'; }
    $q = trim(p2e_digits((string)($f['q'] ?? '')));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $w[] = "(c.name LIKE ? OR crp.chassis_no LIKE ? OR crp.car_name LIKE ? OR crp.import_policy_number LIKE ? OR crp.policy_number LIKE ?
                 OR CONCAT(crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.plate_p1) LIKE ? OR CONCAT_WS(' ', crp.plate_p4, crp.plate_letter, crp.plate_p2, crp.plate_p1) LIKE ?)";
        array_push($p, $like, $like, $like, $like, $like, '%' . preg_replace('/\s+/u', '', $q) . '%', $like);
    }
    $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, cr.import_batch_id, cr.import_month, cr.created_at AS request_created_at, c.name AS insured_name, c.economic_code
                             FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                            WHERE " . implode(' AND ', $w) . " ORDER BY cr.import_month DESC, c.name, crp.import_issue_date, crp.id LIMIT 3000");
    $stmt->execute($p);
    $rows = $stmt->fetchAll();
    $docs = [];
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => intval($r['id']), $rows));
        $siteRoot = dirname(__DIR__);
        foreach ($pdo->query("SELECT id, plate_id, doc_type, file_path, orig_name, status FROM company_documents WHERE plate_id IN ($ids) AND status = 'ASSIGNED' ORDER BY id") as $d) {
            $d['label'] = company_doc_type_label($d['doc_type']);
            $d['is_dir'] = is_dir($siteRoot . '/' . $d['file_path']);
            $docs[(int)$d['plate_id']][] = $d;
        }
    }
    $missF = (string)($f['missing'] ?? '');
    $out = [];
    $cnt = ['total' => 0, 'pending' => 0, 'ready' => 0, 'issued' => 0, 'body' => 0, 'third' => 0, 'no_inspection' => 0];
    foreach ($rows as $r) {
        $rd = $docs[(int)$r['id']] ?? [];
        $types = array_values(array_filter(array_column($rd, 'doc_type')));
        $check = company_plate_checklist($r['insurance_type'], (bool)$r['skip_health_inspection'], $types, $r['has_prev_body'], 'NEW_POLICY', !empty($r['is_new_vehicle']));
        foreach ($check as &$it) $it['docs'] = array_values(array_filter($rd, fn($d) => in_array($d['doc_type'], $it['upload_types'], true) || $d['doc_type'] === $it['key']));
        unset($it);
        $missDocs = company_plate_missing_docs($r['insurance_type'], (bool)$r['skip_health_inspection'], $types, $r['has_prev_body'], 'NEW_POLICY', !empty($r['is_new_vehicle']));
        $missInfo = imp_missing_info($r);
        if ($missF === 'info' && !$missInfo) continue;
        if ($missF !== '' && $missF !== 'info' && !isset($missDocs[$missF])) continue;
        $pf = json_decode((string)$r['import_json'], true) ?: [];
        $issued = $r['status'] === 'ISSUED';
        $state = $issued ? 'ISSUED' : (in_array($r['status'], ['READY_FOR_ISSUE', 'WITH_BOSS', 'IN_ISSUANCE'], true) ? 'READY' : 'PENDING');
        $cnt['total']++; $cnt[strtolower($state)]++;
        $r['insurance_type'] === 'BODY' ? $cnt['body']++ : $cnt['third']++;
        if ($r['insurance_type'] === 'BODY' && $r['skip_health_inspection']) $cnt['no_inspection']++;
        if ($st !== '' && !($st === $state || ($st === 'OPEN' && !$issued))) continue;
        $out[] = [
            'id' => (int)$r['id'], 'request_id' => (int)$r['request_id'], 'company_id' => (int)$r['company_id'], 'batch_id' => (int)$r['import_batch_id'],
            'insured_name' => $r['insured_name'], 'insured_id' => $r['economic_code'], 'month' => $r['import_month'], 'month_fa' => imp_month_fa($r['import_month']),
            'plate_p1' => $r['plate_p1'], 'plate_p2' => $r['plate_p2'], 'plate_letter' => $r['plate_letter'], 'plate_p4' => $r['plate_p4'],
            'chassis_no' => $r['chassis_no'], 'engine_no' => $r['engine_no'], 'label' => company_row_label($r), 'car_name' => $r['car_name'],
            'insurance_type' => $r['insurance_type'], 'insurance_type_fa' => insurance_type_fa($r['insurance_type']),
            'skip_health_inspection' => (int)$r['skip_health_inspection'], 'has_prev_body' => $r['has_prev_body'], 'is_new_vehicle' => (int)$r['is_new_vehicle'],
            'car_value' => $r['car_value'], 'liability_limit' => $r['liability_limit'],
            'import_policy_number' => $r['import_policy_number'], 'import_issue_date' => $r['import_issue_date'],
            'expiry_date' => $r['expiry_date'], 'expiry_jalali' => $r['expiry_date'] ? jalali_from_gregorian_ts_dotted(strtotime($r['expiry_date'])) : null,
            'status' => $r['status'], 'state' => $state, 'status_fa' => $issued ? 'صادر و بایگانی شد' : ($state === 'READY' ? 'آماده‌ی صدور' : 'منتظر مدارک / اطلاعات'),
            'policy_number' => $r['policy_number'], 'total_premium' => $r['total_premium'], 'issued_file_path' => $r['issued_file_path'],
            'folder_status' => $r['folder_status'], 'folder_path' => $r['folder_path'] ? ltrim(str_replace(dirname(__DIR__), '', $r['folder_path']), '/') : null,
            'checklist' => $check, 'missing_docs' => (object)$missDocs, 'missing_info' => (object)$missInfo, 'docs' => $rd, 'pf' => $pf,
        ];
    }
    return ['rows' => $out, 'counts' => $cnt];
}
