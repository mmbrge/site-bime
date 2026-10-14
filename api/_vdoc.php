<?php
// فایل: api/_vdoc.php
// شناساییِ خودکارِ مدارکِ خودرو (فاکتور/سند فروش، سند مالکیت، کارت خودرو، بیمه‌نامه‌ی قبلی) با api/py/vehicle_doc_reader.py
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

// اجرای خواننده روی یک فایل (PDFِ تک‌صفحه یا عکس). مسیرِ فارسی برای پایتونِ هاست مشکل دارد => کپیِ موقتِ لاتین
function vdoc_run($pdo, $abs, $page = 1, $timeout = 280) {
    if (!function_exists('proc_open')) return ['ok' => false, 'error' => 'اجرای پایتون روی این سرور مجاز نیست.'];
    if (!is_file($abs)) return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    if (!function_exists('pdf_split_python')) require_once __DIR__ . '/_pdf_split.php';
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'bmp'], true)) return ['ok' => false, 'error' => 'این نوع فایل خوانده نمی‌شود.'];
    $dir = dirname(__DIR__) . '/tmp_ocr';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $dir . '/vd_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!@copy($abs, $tmp)) return ['ok' => false, 'error' => 'آماده‌سازیِ فایل ممکن نشد.'];
    @set_time_limit($timeout + 30);
    $home = getenv('HOME');
    if (!$home && preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m)) $home = $m[1];
    $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => $home ?: sys_get_temp_dir(), 'PYTHONWARNINGS' => 'ignore',
            'OMP_NUM_THREADS' => '2', 'FLAGS_allocator_strategy' => 'auto_growth'];
    $proc = @proc_open([pdf_split_python($pdo), __DIR__ . '/py/vehicle_doc_reader.py', $tmp, (string)intval($page)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) { @unlink($tmp); return ['ok' => false, 'error' => 'اجرای پایتون ممکن نشد.']; }
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $t0 = time();
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) break;
        if (time() - $t0 > $timeout) { proc_terminate($proc); break; }
        usleep(150000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    @unlink($tmp);
    $line = trim((string)substr($out, (int)strrpos("\n" . trim($out), "\n")));
    $j = json_decode($line, true);
    if (is_array($j)) return $j;
    return ['ok' => false, 'error' => time() - $t0 > $timeout ? 'خواندنِ فایل بیش از حد طول کشید.' : 'خروجیِ خواننده خوانده نشد.', 'debug' => mb_substr(trim($err ?: $out), -300)];
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
function vdoc_apply_to_row($pdo, $plateId, array $d) {
    if (!function_exists('company_ensure_issue_info_cols')) require_once __DIR__ . '/_company_helpers.php';
    company_ensure_issue_info_cols($pdo);
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
    $st->execute([$plateId]);
    $r = $st->fetch();
    if (!$r) return;
    $vin = vdoc_clean_code($d['vin'] ?? '');
    $carName = trim(($d['car_system'] ?? '') . ' ' . ($d['car_tip'] ?? '')) ?: ($d['usage'] ?? '');
    $pdo->prepare("UPDATE company_request_plates SET chassis_no = COALESCE(NULLIF(chassis_no, ''), NULLIF(?, '')), vin = COALESCE(NULLIF(vin, ''), NULLIF(?, '')),
                   engine_no = COALESCE(NULLIF(engine_no, ''), NULLIF(?, '')), car_name = COALESCE(NULLIF(car_name, ''), NULLIF(?, '')), car_type = COALESCE(NULLIF(car_type, ''), NULLIF(?, '')) WHERE id = ?")
        ->execute([$vin, strlen($vin) === 17 ? $vin : '', vdoc_clean_code($d['engine_no'] ?? ''), mb_substr($carName, 0, 100), mb_substr((string)($d['usage'] ?? ''), 0, 120), $plateId]);
    $info = issue_info_decode($r['issue_info'] ?? null);
    $map = ['owner_name' => 'owner_name', 'national_id' => 'owner_national_id', 'mobile' => 'owner_phone', 'address' => 'owner_address',
            'car_system' => 'car_system', 'car_tip' => 'car_type', 'usage' => 'car_kind', 'model_year' => 'car_model_year', 'color' => 'car_color',
            'capacity' => 'car_capacity', 'cylinders' => 'car_cylinders', 'engine_no' => 'engine_no', 'vin' => 'vin'];
    $ch = false;
    foreach ($map as $from => $to) {
        $v = trim((string)($d[$from] ?? ''));
        if ($v !== '' && trim((string)($info[$to] ?? '')) === '') { $info[$to] = mb_substr($v, 0, 250); $ch = true; }
    }
    if ($ch) $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), $plateId]);
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
function vdoc_process_company_doc($pdo, $siteRoot, $docId) {
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
    if (!$doc || $doc['status'] !== 'UNASSIGNED') return $save('NONE', ['ok' => false, 'error' => 'مدرک دیگر در صندوق نیست.']);
    $abs = $siteRoot . '/' . ltrim($doc['file_path'], '/');
    $res = vdoc_run($pdo, $abs);
    if (empty($res['ok'])) return $save('FAIL', $res);
    $d = $res['data'] ?? [];
    $kind = $res['kind'] ?? 'unknown';
    if ($kind === 'unknown' || (empty($d['vin']) && empty($d['engine_no']))) return $save($kind === 'unknown' && empty($d) ? 'NONE' : 'PARTIAL', $res);

    $rows = vdoc_match_row($pdo, intval($doc['company_id']), $doc['request_id'] ? intval($doc['request_id']) : null, $d);
    $created = false;
    if (!$rows && $doc['request_id'] && in_array($kind, ['sales_invoice', 'ownership_doc'], true) && ($res['status'] ?? '') === 'full') {
        // فاکتور/سندِ کامل که هنوز ردیفی ندارد => ردیفِ تازه (صفر کیلومتر برای فاکتورِ فروش: بازدید لازم نیست)
        $isNew = $kind === 'sales_invoice' ? 1 : 0;
        $vin = vdoc_clean_code($d['vin'] ?? '');
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
    vdoc_apply_to_row($pdo, intval($first['id']), $d);
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
        vdoc_apply_to_row($pdo, intval($r2['id']), $d);
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
    $w = "ai_status = 'PENDING' AND status = 'UNASSIGNED'"; $a = [];
    if (is_array($companyIds)) { if (!$companyIds) return ['done' => [], 'pending' => 0]; $w .= " AND company_id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")"; $a = array_values(array_map('intval', $companyIds)); }
    $done = [];
    for ($i = 0; $i < $max; $i++) {
        $st = $pdo->prepare("SELECT id FROM company_documents WHERE $w ORDER BY id LIMIT 1");
        $st->execute($a);
        $id = $st->fetchColumn();
        if (!$id) break;
        $r = vdoc_process_company_doc($pdo, $siteRoot, intval($id));
        if (!empty($r['skipped'])) continue;
        $done[] = ['doc_id' => intval($id), 'status' => $r['status'] ?? 'FAIL', 'kind_fa' => $r['result']['kind_fa'] ?? '', 'vin' => $r['result']['data']['vin'] ?? '',
                   'owner' => $r['result']['data']['owner_name'] ?? '', 'missing' => $r['result']['missing'] ?? []];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM company_documents WHERE ai_status IN ('PENDING', 'RUNNING') AND status = 'UNASSIGNED'" . (is_array($companyIds) ? " AND company_id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")" : ''));
    $st->execute($a);
    return ['done' => $done, 'pending' => intval($st->fetchColumn())];
}

// مدرکِ تازه‌ی شرکت را در صفِ شناسایی می‌گذارد (فقط مدارکِ پشتیبان؛ نامه نه)
function vdoc_queue($pdo, array $docIds) {
    vdoc_ensure($pdo);
    $docIds = array_values(array_filter(array_map('intval', $docIds)));
    if (!$docIds) return;
    $pdo->prepare("UPDATE company_documents SET ai_status = 'PENDING', ai_at = NOW() WHERE id IN (" . implode(',', array_fill(0, count($docIds), '?')) . ") AND status = 'UNASSIGNED' AND file_kind = 'SUPPORTING_DOC'")
        ->execute($docIds);
}
