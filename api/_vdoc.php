<?php
// فایل: api/_vdoc.php
// شناساییِ خودکارِ مدارکِ خودرو (فاکتور/سند فروش، سند مالکیت، کارت خودرو، بیمه‌نامه‌ی قبلی) با api/_vdoc_reader.php (فقط PDFِ متنی)
//  - مدارکِ تازه‌ی شرکت‌ها (هر صفحه‌ی PDF جدا) با ai_status = 'PENDING' صف می‌شوند و یکی‌یکی (هر درخواست یک صفحه) خوانده می‌شوند
//  - شناخته‌شده و هم‌خوان با یک ردیف (شاسی/موتور) => خودکار روی همان ردیف می‌نشیند (AUTO)
//  - فاکتور/سندِ کامل که ردیفی ندارد => ردیفِ تازه ساخته می‌شود و مدرک رویش می‌نشیند (AUTO_NEW)
//  - ناقص => در صندوقِ مدارک با پیشنهاد می‌ماند تا کارشناس کاملش کند (PARTIAL)؛ شناخته‌نشده => صندوقِ مدارک (NONE)
// ستون‌ها خودکار ساخته می‌شوند؛ مدارکِ قدیمی (ai_status خالی) دست نمی‌خورند.

function vdoc_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM company_documents")->fetchAll(), 'Field');
        if (!in_array('ai_status', $cols, true)) $pdo->exec("ALTER TABLE company_documents ADD COLUMN ai_status VARCHAR(12) NULL, ADD COLUMN ai_json MEDIUMTEXT NULL, ADD COLUMN ai_at DATETIME NULL");
    } catch (Throwable $e) { error_log('[vdoc_ensure] ' . $e->getMessage()); }
}

// شماره موتور/شاسی: فقط حروف و ارقامِ لاتین، بزرگ (. * - / و فاصله پاک می‌شوند)
function vdoc_clean_code($s) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$s)));
}
// همان پاک‌سازی در SQL (برای مقایسه با ستون‌های ردیف)
function vdoc_sql_clean($col) {
    return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col, ''), '-', ''), '.', ''), '*', ''), ' ', ''), '/', ''), '_', ''))";
}

// اجرای خواننده روی یک فایل (PHP: api/_vdoc_reader.php). فقط PDFِ متنی خوانده می‌شود؛ عکس/اسکن => «خوانده نشد» (ورودِ دستی)
//   $opt['smart']: true = اگر کامل خوانده نشد «پردازشِ هوشمند» (OCR + پرامپتِ نوعِ مدرک)، false = هرگز،
//                  null (پیش‌فرض) = فقط برای اسکن/عکس و اگر در تنظیمات روشن و موتور آماده باشد؛ $opt['hint'] = نوعِ پیشنهادیِ مدرک
function vdoc_run($pdo, $abs, $page = 1, $timeout = 280, array $opt = []) {
    if (!is_file($abs)) return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    $ext = upload_ext($abs);
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'bmp'], true)) return ['ok' => false, 'error' => 'این نوع فایل خوانده نمی‌شود.'];
    require_once __DIR__ . '/_vdoc_reader.php';
    try { $r = vdr_read($abs, intval($page) ?: 1); }
    catch (Throwable $e) { error_log('[vdoc_run] ' . $e->getMessage()); $r = ['ok' => false, 'error' => 'خواندنِ فایل ممکن نشد.', 'scan' => $ext !== 'pdf']; }
    $smart = array_key_exists('smart', $opt) ? $opt['smart'] : null;
    $full = !empty($r['ok']) && ($r['status'] ?? '') === 'full';
    if ($pdo && !$full && $smart !== false && ($smart === true || empty($r['ok']) || !empty($r['scan']))) {
        try {
            require_once __DIR__ . '/_doc_smart.php';
            if (ds_smart_available($pdo)) {
                $s = ds_smart_file($pdo, $abs, intval($page) ?: 1, (string)($opt['hint'] ?? ''));
                $rank = ['full' => 3, 'partial' => 2, 'none' => 1];
                if (!empty($s['ok']) && (empty($r['ok']) || ($rank[$s['status'] ?? 'none'] ?? 0) >= ($rank[$r['status'] ?? 'none'] ?? 0))) {
                    if (!empty($r['ok'])) $s['data'] += array_diff_key((array)$r['data'], $s['data']);   // جاهای خالی از خواننده‌ی متنی
                    return $s;
                }
            }
        } catch (Throwable $e) { error_log('[vdoc smart] ' . $e->getMessage()); }
    }
    // اسکن/عکس (بی‌متن): اگر سرویسِ «خواندنِ اسکن (هوش مصنوعی)» تنظیم شده، با همان خوانده می‌شود
    if (empty($r['ok']) && !empty($r['scan']) && $pdo) {
        try { require_once __DIR__ . '/_vdoc_ai.php'; if (vdoc_ai_ready($pdo)) return vdoc_ai_read($pdo, $abs, intval($page) ?: 1); }
        catch (Throwable $e) { error_log('[vdoc_ai] ' . $e->getMessage()); }
    }
    return $r;
}

// چند فایلِ تک‌صفحه‌ای => [i => نتیجه]
function vdoc_run_many($pdo, array $files, $timeout = 420) {
    $out = [];
    foreach ($files as $i => $f) $out[$i] = vdoc_run($pdo, $f);
    return $out;
}

// ردیفِ هم‌خوان با شاسی/موتورِ خوانده‌شده (در همان درخواست؛ اگر درخواست مشخص نیست، درخواست‌های بازِ همان شرکت)
function vdoc_match_row($pdo, $companyId, $requestId, array $d) {
    $vin = vdoc_clean_code($d['vin'] ?? ($d['chassis_no'] ?? ''));
    $eng = vdoc_clean_code($d['engine_no'] ?? '');
    if (strlen($vin) < 6 && strlen($eng) < 6) return null;
    $w = []; $a = [];
    if (strlen($vin) >= 6) { $w[] = vdoc_sql_clean('crp.chassis_no') . " = ?"; $a[] = $vin; $w[] = vdoc_sql_clean('crp.vin') . " = ?"; $a[] = $vin; }
    if (strlen($eng) >= 6) { $w[] = vdoc_sql_clean('crp.engine_no') . " = ?"; $a[] = $eng; }
    $scope = $requestId ? "crp.request_id = ?" : "cr.company_id = ? AND cr.status NOT IN ('ISSUED', 'CANCELLED')";
    $st = $pdo->prepare("SELECT crp.* FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                          WHERE $scope AND crp.status NOT IN ('ISSUED', 'CANCELLED') AND (" . implode(' OR ', $w) . ") ORDER BY crp.id LIMIT 4");
    $st->execute(array_merge([$requestId ?: $companyId], $a));
    $rows = $st->fetchAll();
    return $rows ?: null;   // ممکن است ثالث و بدنه‌ی یک خودرو دو ردیف باشند
}

// نوعِ مدرک در چک‌لیستِ ردیف
function vdoc_doc_type($kind, array $d, array $row) {
    if ($kind === 'sales_invoice') return 'sales_invoice';
    if ($kind === 'ownership_doc') return 'ownership_doc';
    if ($kind === 'car_card') return 'car_card_front';
    if ($kind === 'policy') return ($d['policy_type'] ?? '') === 'BODY' ? 'prev_body_policy' : 'prev_third_policy';
    return null;
}

// اطلاعاتِ خوانده‌شده روی ردیف: فقط خانه‌های خالی پر می‌شوند (چیزی که ثبت شده عوض نمی‌شود)
function vdoc_apply_to_row($pdo, $plateId, array $d, $kind = '') {
    if (!function_exists('company_ensure_issue_info_cols')) require_once __DIR__ . '/_company_helpers.php';
    company_ensure_issue_info_cols($pdo);
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
    $st->execute([$plateId]);
    $r = $st->fetch();
    if (!$r) return;
    // «شماره شاسی»ِ مدرک در شاسیِ ردیف؛ VIN (۱۷ رقمی) در VIN — هیچ مقدارِ موجودی عوض نمی‌شود
    $chassis = vdoc_clean_code(($d['chassis_no'] ?? '') ?: ($d['vin'] ?? ''));
    $vin = vdoc_clean_code($d['vin'] ?? '');
    $carName = trim(($d['car_system'] ?? '') . ' ' . ($d['car_tip'] ?? '')) ?: ($d['usage'] ?? '');
    $pdo->prepare("UPDATE company_request_plates SET chassis_no = COALESCE(NULLIF(chassis_no, ''), NULLIF(?, '')), vin = COALESCE(NULLIF(vin, ''), NULLIF(?, '')),
                   engine_no = COALESCE(NULLIF(engine_no, ''), NULLIF(?, '')), car_name = COALESCE(NULLIF(car_name, ''), NULLIF(?, '')), car_type = COALESCE(NULLIF(car_type, ''), NULLIF(?, '')) WHERE id = ?")
        ->execute([$chassis, strlen($vin) === 17 ? $vin : '', vdoc_clean_code($d['engine_no'] ?? ''), mb_substr($carName, 0, 100), mb_substr((string)($d['usage'] ?? ''), 0, 120), $plateId]);
    $info = issue_info_decode($r['issue_info'] ?? null);
    $map = ['owner_name' => 'owner_name', 'national_id' => 'owner_national_id', 'mobile' => 'owner_phone', 'address' => 'owner_address',
            'car_system' => 'car_system', 'car_tip' => 'car_type', 'usage' => 'car_kind', 'model_year' => 'car_model_year', 'color' => 'car_color',
            'capacity' => 'car_capacity', 'cylinders' => 'car_cylinders', 'engine_no' => 'engine_no', 'vin' => 'vin', 'chassis_no' => 'chassis_no'];
    $ch = false;
    foreach ($map as $from => $to) {
        $v = trim((string)($d[$from] ?? ''));
        if ($v !== '' && trim((string)($info[$to] ?? '')) === '') { $info[$to] = mb_substr($v, 0, 250); $ch = true; }
    }
    if ($ch) $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), $plateId]);
    if (($r['status'] ?? '') === 'ISSUED') return;   // ردیفِ صادرشده: فقط خانه‌های خالیِ اطلاعات (بالا)؛ پلاک/نقش‌ها دست نمی‌خورد
    $hasPlate = trim((string)($r['plate_p2'] ?? '')) !== '' || trim((string)($r['plate_p1'] ?? '')) !== '';
    // پلاکِ خوانده‌شده از بارکدِ سند/کارت: فقط وقتی ردیف پلاک ندارد و حرفِ پلاک هم شناخته شده
    $pp = $d['plate_parts'] ?? null;
    if (!$hasPlate && is_array($pp) && !empty($pp['letter']) && preg_match('/^\d{3}$/', (string)($pp['three'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['two'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['iran'] ?? ''))) {
        // plate_p4 = دو رقمِ اول، plate_p2 = سه رقم، plate_p1 = کدِ ایران (مثلِ فرم‌های ثبت)
        $pdo->prepare("UPDATE company_request_plates SET plate_p4 = ?, plate_letter = ?, plate_p2 = ?, plate_p1 = ? WHERE id = ? AND COALESCE(plate_p2, '') = '' AND COALESCE(plate_p1, '') = ''")
            ->execute([$pp['two'], $pp['letter'], $pp['three'], $pp['iran'], $plateId]);
        $hasPlate = true;
    }
    // فاکتورِ فروش و بی‌پلاک = صفرکیلومتر (بازدیدِ سلامت لازم نیست)
    if ($kind === 'sales_invoice' && !$hasPlate && empty($r['is_new_vehicle'])) {
        $pdo->prepare("UPDATE company_request_plates SET is_new_vehicle = 1, skip_health_inspection = 1 WHERE id = ?")->execute([$plateId]);
    }
    // مالکِ مدرک با شرکت فرق دارد => نقشِ «ذینفع» یا «مالک = بیمه‌گذار» طبقِ تنظیمِ شرکت (فقط اگر نقشِ ردیف/درخواست تعیین نشده)
    try { vdoc_owner_party($pdo, $r, $d); } catch (Throwable $e) { error_log('[vdoc_owner_party] ' . $e->getMessage()); }
}

// مالکِ مدرک ≠ شرکت؟ (نام ساده‌شده یا شناسه‌ی ملی = کد اقتصادی) => null اگر اطلاعات کافی نیست
function vdoc_owner_differs(array $d, array $company) {
    $name = trim((string)($d['owner_name'] ?? '')); $nid = preg_replace('/\D/', '', (string)($d['national_id'] ?? ''));
    if (($name === '' && $nid === '') || trim((string)($company['name'] ?? '')) === '') return null;
    $eco = preg_replace('/\D/', '', (string)($company['economic_code'] ?? ''));
    if ($nid !== '' && $eco !== '' && $nid === $eco) return false;
    if (!function_exists('company_name_key')) require_once __DIR__ . '/_case_helpers.php';
    $a = company_name_key($name); $b = company_name_key($company['name']);
    if ($a !== '' && $b !== '' && (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false)) return false;
    return $name !== '' || $nid !== '' ? true : null;
}

function vdoc_owner_party($pdo, array $row, array $d) {
    if (!function_exists('party_ensure')) require_once __DIR__ . '/_party.php';
    party_ensure($pdo);
    if (party_valid_mode($row['party_mode'] ?? '')) return;          // نقشِ ردیف را کاربر تعیین کرده
    $st = $pdo->prepare("SELECT cr.party_mode, c.* FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
    $st->execute([intval($row['request_id'])]);
    $c = $st->fetch();
    if (!$c) return;
    $reqMode = party_valid_mode($c['party_mode'] ?? '');
    if ($reqMode && $reqMode !== 'COMPANY') return;                  // پیش‌فرضِ درخواست «به نامِ دیگری» است
    if (vdoc_owner_differs($d, $c) !== true) return;
    $mode = ($c['other_owner_mode'] ?? '') === 'OWNER' ? 'OWNER' : 'BENEFICIARY';
    party_set_rows($pdo, [intval($row['id'])], $mode, ['name' => $d['owner_name'] ?? '', 'national_id' => $d['national_id'] ?? '', 'phone' => $d['mobile'] ?? '',
                                                       'address' => $d['address'] ?? '', 'postal_code' => $d['postal_code'] ?? '']);
}

// نوعِ بیمه‌ی ردیفِ تازه: اگر درخواست فقط یک نوع خواسته (تعدادها یا ردیف‌های موجود)
function vdoc_request_type($pdo, $requestId) {
    $st = $pdo->prepare("SELECT requested_counts FROM company_requests WHERE id = ?");
    $st->execute([$requestId]);
    $c = json_decode((string)$st->fetchColumn(), true) ?: [];
    $t = intval($c['THIRDPARTY'] ?? 0); $b = intval($c['BODY'] ?? 0);
    if ($t && !$b) return 'THIRDPARTY';
    if ($b && !$t) return 'BODY';
    $st = $pdo->prepare("SELECT DISTINCT insurance_type FROM company_request_plates WHERE request_id = ? AND insurance_type IS NOT NULL");
    $st->execute([$requestId]);
    $types = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($types) === 1 ? $types[0] : null;
}

// پردازشِ یک مدرکِ صف‌شده
// مدارکِ خودرو که اطلاعاتشان روی ردیف می‌نشیند (کارت، سند، فاکتورِ فروش)؛ وقتی مستقیم روی یک ردیف بارگذاری می‌شوند هم خوانده می‌شوند
function vdoc_vehicle_types() { return ['car_card_front', 'car_card_back', 'ownership_doc', 'sales_invoice', 'new_car_card', 'new_ownership_doc', 'car_card_or_title']; }

// \$pre: نتیجه‌ی آماده (مثلاً «خواندنِ هوشمند» = OCR + هوشِ مصنوعی در api/_doc_smart.php) به‌جای خواندنِ دوباره‌ی فایل
function vdoc_process_company_doc($pdo, $siteRoot, $docId, $pre = null) {
    vdoc_ensure($pdo);
    // برداشتنِ اتمی از صف (دو درخواستِ هم‌زمان یک مدرک را دو بار نمی‌خوانند)
    $up = $pdo->prepare("UPDATE company_documents SET ai_status = 'RUNNING', ai_at = NOW() WHERE id = ? AND ai_status = 'PENDING'");
    $up->execute([$docId]);
    if (!$up->rowCount()) return ['ok' => false, 'skipped' => true];
    $st = $pdo->prepare("SELECT cd.*, c.name AS company_name FROM company_documents cd JOIN companies c ON c.id = cd.company_id WHERE cd.id = ?");
    $st->execute([$docId]);
    $doc = $st->fetch();
    $save = function ($status, $res) use ($pdo, $docId) {
        $pdo->prepare("UPDATE company_documents SET ai_status = ?, ai_json = ?, ai_at = NOW() WHERE id = ?")->execute([$status, json_encode($res, JSON_UNESCAPED_UNICODE), $docId]);
        return ['ok' => true, 'status' => $status, 'result' => $res];
    };
    $onRow = $doc && $doc['status'] === 'ASSIGNED' && $doc['plate_id'] && in_array($doc['doc_type'], vdoc_vehicle_types(), true);
    if (!$doc || ($doc['status'] !== 'UNASSIGNED' && !$onRow)) return $save('NONE', ['ok' => false, 'error' => 'مدرک دیگر در صندوق نیست.']);
    $abs = $siteRoot . '/' . ltrim($doc['file_path'], '/');
    // «پردازشِ هوشمند»ِ این شرکت (تعریفِ شرکت ← خواندنِ مدارک = ۲): OCR + پرامپتِ نوعِ مدرک
    $mode = 1;
    try { $m = $pdo->prepare("SELECT doc_read FROM companies WHERE id = ?"); $m->execute([$doc['company_id']]); $mode = intval($m->fetchColumn()); } catch (Throwable $e) {}
    $res = is_array($pre) ? $pre : vdoc_run($pdo, $abs, 1, 280, ['smart' => $mode === 2 ? true : false, 'hint' => (string)($doc['doc_type'] ?? '')]);
    if (empty($res['ok'])) return $save('FAIL', $res);
    $d = $res['data'] ?? [];
    // مدرکی که از قبل روی یک ردیف است: فقط اطلاعاتش روی همان ردیف می‌نشیند (خانه‌های خالی)
    if ($onRow) {
        if (!empty($d)) vdoc_apply_to_row($pdo, intval($doc['plate_id']), $d, $res['kind'] ?? '');
        $res['plate_id'] = intval($doc['plate_id']);
        return $save(empty($d) ? 'NONE' : (($res['status'] ?? '') === 'full' ? 'AUTO' : 'PARTIAL'), $res);
    }
    $kind = $res['kind'] ?? 'unknown';
    if ($kind === 'unknown' || (empty($d['vin']) && empty($d['chassis_no']) && empty($d['engine_no']))) return $save($kind === 'unknown' && empty($d) ? 'NONE' : 'PARTIAL', $res);

    $rows = vdoc_match_row($pdo, intval($doc['company_id']), $doc['request_id'] ? intval($doc['request_id']) : null, $d);
    $created = false;
    if (!$rows && $doc['request_id'] && in_array($kind, ['sales_invoice', 'ownership_doc'], true) && ($res['status'] ?? '') === 'full') {
        // فاکتور/سندِ کامل که هنوز ردیفی ندارد => ردیفِ تازه (صفر کیلومتر برای فاکتورِ فروش: بازدید لازم نیست)
        $isNew = $kind === 'sales_invoice' ? 1 : 0;
        $vin = vdoc_clean_code(($d['vin'] ?? '') ?: ($d['chassis_no'] ?? ''));
        $type = vdoc_request_type($pdo, intval($doc['request_id']));
        $pdo->prepare("INSERT INTO company_request_plates (request_id, chassis_no, vin, engine_no, is_new_vehicle, insurance_type, skip_health_inspection, car_name, row_note)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$doc['request_id'], $vin ?: null, strlen($vin) === 17 ? $vin : null, vdoc_clean_code($d['engine_no'] ?? '') ?: null, $isNew, $type, $isNew,
                       mb_substr(trim(($d['car_system'] ?? '') . ' ' . ($d['car_tip'] ?? '')) ?: ($d['usage'] ?? ''), 0, 100) ?: null,
                       $type ? null : 'ساخته‌شده از روی ' . ($res['kind_fa'] ?? 'مدرک') . '؛ نوعِ بیمه را مشخص کنید']);
        $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
        $st->execute([intval($pdo->lastInsertId())]);
        $rows = [$st->fetch()];
        $created = true;
    }
    if (!$rows) return $save('PARTIAL', $res);
    if (!function_exists('company_place_document')) require_once __DIR__ . '/_company_helpers.php';
    // روی ردیفِ اول می‌نشیند؛ اگر ثالث و بدنه‌ی همین خودرو دو ردیف‌اند، برای ردیفِ دوم یک کپی
    $first = $rows[0];
    $type = vdoc_doc_type($kind, $d, $first);
    $pdo->prepare("UPDATE company_documents SET request_id = ?, plate_id = ?, doc_type = ?, status = 'ASSIGNED', assigned_at = NOW() WHERE id = ?")
        ->execute([$first['request_id'], $first['id'], $type, $docId]);
    $newRel = company_place_document($pdo, $siteRoot, $docId, intval($first['id']), $type);
    vdoc_apply_to_row($pdo, intval($first['id']), $d, $kind);
    company_sync_plate_status($pdo, intval($first['id']));
    foreach (array_slice($rows, 1) as $r2) {
        $src = $siteRoot . '/' . ltrim((string)$newRel, '/');
        if (!is_file($src)) break;
        $dest = dirname($src) . '/' . pathinfo($src, PATHINFO_FILENAME) . '_' . $r2['id'] . '.' . pathinfo($src, PATHINFO_EXTENSION);
        if (!@copy($src, $dest)) continue;
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status, assigned_at, ai_status)
                       VALUES (?, ?, ?, ?, ?, ?, 'SUPPORTING_DOC', ?, 'ASSIGNED', NOW(), 'AUTO')")
            ->execute([$doc['company_id'], $r2['request_id'], $r2['id'], $doc['uploaded_by'], ltrim(str_replace($siteRoot, '', $dest), '/'), $doc['orig_name'], $type]);
        $d2 = intval($pdo->lastInsertId());
        company_place_document($pdo, $siteRoot, $d2, intval($r2['id']), $type);
        vdoc_apply_to_row($pdo, intval($r2['id']), $d, $kind);
        company_sync_plate_status($pdo, intval($r2['id']));
    }
    $res['plate_id'] = intval($first['id']);
    $res['row_created'] = $created;
    return $save($created ? 'AUTO_NEW' : 'AUTO', $res);
}

// صف را جلو می‌برد: حداکثر $max مدرک (پیش‌فرض یکی، چون هر صفحه با OCR چند ثانیه طول می‌کشد)
function vdoc_step($pdo, $siteRoot, $companyIds = null, $max = 1) {
    vdoc_ensure($pdo);
    // «در حال خواندن»ِ ماندگار (قطعِ درخواست) دوباره به صف برمی‌گردد
    try { $pdo->exec("UPDATE company_documents SET ai_status = 'PENDING' WHERE ai_status = 'RUNNING' AND ai_at < (NOW() - INTERVAL 10 MINUTE)"); } catch (Throwable $e) {}
    $w = "ai_status = 'PENDING' AND (status = 'UNASSIGNED' OR (status = 'ASSIGNED' AND plate_id IS NOT NULL))"; $a = [];
    if (is_array($companyIds)) { if (!$companyIds) return ['done' => [], 'pending' => 0]; $w .= " AND company_id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")"; $a = array_values(array_map('intval', $companyIds)); }
    $done = [];
    for ($i = 0; $i < $max; $i++) {
        $st = $pdo->prepare("SELECT id FROM company_documents WHERE $w ORDER BY id LIMIT 1");
        $st->execute($a);
        $id = $st->fetchColumn();
        if (!$id) break;
        $r = vdoc_process_company_doc($pdo, $siteRoot, intval($id));
        if (!empty($r['skipped'])) continue;
        $done[] = ['doc_id' => intval($id), 'status' => $r['status'] ?? 'FAIL', 'plate_id' => $r['result']['plate_id'] ?? null, 'kind_fa' => $r['result']['kind_fa'] ?? '', 'vin' => $r['result']['data']['vin'] ?? '',
                   'owner' => $r['result']['data']['owner_name'] ?? '', 'missing' => $r['result']['missing'] ?? []];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM company_documents WHERE ai_status IN ('PENDING', 'RUNNING') AND (status = 'UNASSIGNED' OR (status = 'ASSIGNED' AND plate_id IS NOT NULL))" . (is_array($companyIds) ? " AND company_id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")" : ''));
    $st->execute($a);
    return ['done' => $done, 'pending' => intval($st->fetchColumn())];
}

// مدرکِ تازه‌ی شرکت را در صفِ شناسایی می‌گذارد (فقط مدارکِ پشتیبان؛ نامه نه)
//   شرکتی که در تعریفش «خواندنِ خودکارِ مدارک» خاموش است: مدارکش فقط بارگذاری می‌شود (مگر $force = درخواستِ دستیِ کارشناس)
function vdoc_queue($pdo, array $docIds, $force = false) {
    vdoc_ensure($pdo);
    $docIds = array_values(array_filter(array_map('intval', $docIds)));
    if (!$docIds) return 0;
    if (!$force) {
        try {
            $off = $pdo->query("SELECT d.id FROM company_documents d JOIN companies c ON c.id = d.company_id WHERE c.doc_read = 0 AND d.id IN (" . implode(',', $docIds) . ")")->fetchAll(PDO::FETCH_COLUMN);
            if ($off) $docIds = array_values(array_diff($docIds, array_map('intval', $off)));
        } catch (Throwable $e) {}   // ستون هنوز ساخته نشده => همه خوانده می‌شوند (مثلِ قبل)
        if (!$docIds) return 0;
    }
    $vt = vdoc_vehicle_types();
    $pdo->prepare("UPDATE company_documents SET ai_status = 'PENDING', ai_at = NOW() WHERE id IN (" . implode(',', array_fill(0, count($docIds), '?')) . ") AND file_kind = 'SUPPORTING_DOC'
                     AND (status = 'UNASSIGNED' OR (status = 'ASSIGNED' AND plate_id IS NOT NULL AND doc_type IN (" . implode(',', array_fill(0, count($vt), '?')) . ")))")
        ->execute(array_merge($docIds, $vt));
    return count($docIds);
}

// =====================================================================
//  ثبتِ دستیِ درخواستِ شرکتی از پنل: فاکتور/سند/کارت (یک یا چند فایل، هر فایل چند صفحه) صفحه‌به‌صفحه خوانده می‌شود؛
//  پنل از روی نتیجه برای هر خودرو یک ردیف می‌سازد و بعد از ثبت، هر صفحه روی ردیفِ خودش بایگانی و اطلاعاتش روی ردیف می‌نشیند.
//  کارِ جداسازی با مالکِ 'md'+شناسه‌ی کاربر نگه داشته می‌شود (api/_pdf_split.php)
// =====================================================================
function vdoc_job_read($pdo, $siteRoot, $token, $owner, $n) {
    if (!function_exists('pdf_split_job_page_path')) require_once __DIR__ . '/_pdf_split.php';
    $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
    if (!$p) return ['ok' => false, 'error' => 'صفحه پیدا نشد (شاید زمانش گذشته؛ دوباره بارگذاری کنید).'];
    $cache = dirname($p) . sprintf('/r%03d.json', $n);
    if (is_file($cache)) { $j = json_decode((string)file_get_contents($cache), true); if (is_array($j)) return $j; }
    $r = vdoc_run($pdo, $p);
    if (!empty($r['ok'])) @file_put_contents($cache, json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r;
}

// چند صفحه از یک فایلِ بارگذاری‌شده با هم (نتیجه‌ی قبلی از کش) => [n => نتیجه]
function vdoc_job_read_many($pdo, $siteRoot, $token, $owner, array $ns) {
    if (!function_exists('pdf_split_job_page_path')) require_once __DIR__ . '/_pdf_split.php';
    $out = []; $todo = [];
    foreach (array_slice(array_unique(array_map('intval', $ns)), 0, 6) as $n) {
        $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
        if (!$p) { $out[$n] = ['ok' => false, 'error' => 'صفحه پیدا نشد (شاید زمانش گذشته؛ دوباره بارگذاری کنید).']; continue; }
        $cache = dirname($p) . sprintf('/r%03d.json', $n);
        if (is_file($cache) && is_array($j = json_decode((string)file_get_contents($cache), true))) { $out[$n] = $j; continue; }
        $todo[$n] = $p;
    }
    if ($todo) {
        foreach (vdoc_run_many($pdo, $todo) as $n => $r) {
            $out[$n] = $r;
            if (!empty($r['ok'])) @file_put_contents(dirname($todo[$n]) . sprintf('/r%03d.json', $n), json_encode($r, JSON_UNESCAPED_UNICODE));
        }
    }
    ksort($out);
    return $out;
}

// صفحه‌ی n را روی ردیف بایگانی می‌کند (نوعِ مدرک از نتیجه‌ی شناسایی یا $docType) و اطلاعاتش را روی ردیف می‌نشاند
function vdoc_job_attach($pdo, $siteRoot, $token, $owner, $n, $plateId, $userId, $docType = '') {
    if (!function_exists('pdf_split_job_page_path')) require_once __DIR__ . '/_pdf_split.php';
    if (!function_exists('company_place_document')) require_once __DIR__ . '/_company_helpers.php';
    $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
    if (!$p) return ['ok' => false, 'error' => 'صفحه پیدا نشد.'];
    $st = $pdo->prepare("SELECT crp.*, cr.company_id, c.name AS company_name FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
    $st->execute([intval($plateId)]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    $res = json_decode((string)@file_get_contents(dirname($p) . sprintf('/r%03d.json', $n)), true) ?: [];
    $d = (array)($res['data'] ?? []);
    $type = $docType !== '' && array_key_exists($docType, company_doc_types()) ? $docType : (vdoc_doc_type($res['kind'] ?? '', $d, $row) ?: 'other');
    $tmpDir = company_unassigned_temp_path($siteRoot, $row['company_name'] ?: 'نامشخص');
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $label = company_doc_type_label($type);
    $dest = unique_dest_path($tmpDir . '/' . pdf_split_safe_name($label . ' - صفحه ' . intval($n)) . '.pdf');
    if (!@copy($p, $dest)) return ['ok' => false, 'error' => 'کپیِ صفحه ممکن نشد.'];
    vdoc_ensure($pdo);
    $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status, assigned_by, assigned_at, ai_status, ai_json)
                   VALUES (?, ?, ?, NULL, ?, ?, 'SUPPORTING_DOC', ?, 'ASSIGNED', ?, NOW(), ?, ?)")
        ->execute([$row['company_id'], $row['request_id'], $row['id'], ltrim(str_replace($siteRoot, '', $dest), '/'), $label . ' - صفحه ' . intval($n) . '.pdf', $type, $userId,
                   $res ? 'AUTO' : null, $res ? json_encode($res, JSON_UNESCAPED_UNICODE) : null]);
    $docId = intval($pdo->lastInsertId());
    company_place_document($pdo, $siteRoot, $docId, intval($row['id']), $type);
    if ($d) vdoc_apply_to_row($pdo, intval($row['id']), $d, $res['kind'] ?? '');
    company_sync_plate_status($pdo, intval($row['id']));
    return ['ok' => true, 'doc_id' => $docId, 'doc_type' => $type];
}
