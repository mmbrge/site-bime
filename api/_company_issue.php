<?php
// فایل: api/_company_issue.php
// صدورِ ردیف‌های شرکتی (تکی و گروهی)
//  - company_issue_plate : صدورِ نهاییِ یک ردیف + بایگانی (همان روالِ قبلیِ mark_issued): پوشه‌ی ردیف به نامِ نهایی
//    عوض می‌شود، فایلِ بیمه‌نامه با نام‌گذاریِ استاندارد داخلش می‌نشیند، کپی به «بایگانی صادره»، اقساط، خبر به شرکت
//  - صدورِ گروهی از فایلِ خروجیِ سایتِ بیمه‌گر (چند بیمه‌نامه در یک PDF): جدا کردنِ صفحه‌ها (api/py/policy_bundle.py)،
//    شناسایی با همان منطقِ موتورِ تشخیص، پیدا کردنِ ردیفِ هر بیمه‌نامه و بررسیِ آمادگیِ صدور

function company_issue_plate($pdo, $plateId, $policyNumber, $vin, $totalPremium, $srcFile = null, $srcOrigName = null) {
    $siteRoot = dirname(__DIR__);
    $baseDir = ensure_plate_folder($pdo, $siteRoot, $plateId);
    if (!$baseDir) return ['ok' => false, 'error' => 'ردیف یافت نشد.'];

    $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, cr.request_kind, c.name AS company_name FROM company_request_plates crp
                            JOIN company_requests cr ON cr.id = crp.request_id
                            JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch();
    if (!$plate) return ['ok' => false, 'error' => 'ردیف یافت نشد.'];
    $kind = $plate['request_kind'] ?? 'NEW_POLICY';

    $plateDisplay = company_row_label($plate);
    // همان قاعده‌ی نام‌گذاری پرسنلی: پلاک بدون خط‌تیره‌ی داخلی، و «/» شماره‌ی بیمه‌نامه با «∕»
    $plateForName = plate_for_filename($plateDisplay);
    $policyNumForName = policy_number_for_filename($policyNumber);
    $issuedFolderName = company_issued_folder_name_for_kind($kind, $plate['insurance_type'], $plateDisplay, $plate['company_name'], $policyNumber, $vin);
    $issuedDir = dirname($baseDir) . '/' . $issuedFolderName;

    // اول پوشه‌ی قبل از صدور (با مدارکش) به نامِ نهایی، بعد فایلِ بیمه‌نامه داخلش
    if (is_dir($baseDir) && $baseDir !== $issuedDir) {
        if (!is_dir(dirname($issuedDir))) @mkdir(dirname($issuedDir), 0755, true);
        if (@rename($baseDir, $issuedDir)) company_rewrite_doc_paths($pdo, $siteRoot, $plateId, $baseDir, $issuedDir);
    }
    if (!is_dir($issuedDir)) @mkdir($issuedDir, 0755, true);

    $issuedFilePath = null;
    if ($srcFile && is_file($srcFile)) {
        $ext = strtolower(pathinfo($srcOrigName ?: $srcFile, PATHINFO_EXTENSION)) ?: 'pdf';
        $finalName = build_final_policy_filename($plateForName, $plate['company_name'], $policyNumForName, $vin, $ext);
        $dest = unique_dest_path($issuedDir . '/' . $finalName);
        $moved = @rename($srcFile, $dest);
        if (!$moved && @copy($srcFile, $dest)) { @unlink($srcFile); $moved = true; }
        if ($moved) $issuedFilePath = ltrim(str_replace($siteRoot, '', $dest), '/');
    }

    $issuedAt = time();
    // الحاقیه و فسخ فقط در بایگانی شرکتی می‌مانند (به «بایگانی صادره» نمی‌روند)
    $folderStatus = 'FAILED';
    if (!company_kind_goes_to_sadere($kind)) {
        $folderStatus = is_dir($issuedDir) ? 'TRANSFERRED' : 'FAILED';
    } elseif (is_dir($issuedDir)) {
        $folderStatus = company_copy_to_shared_sadere($siteRoot, $issuedDir, $issuedAt, $plate['insurance_type'], $issuedFolderName) ? 'TRANSFERRED' : 'FAILED';
    }

    $pdo->prepare("UPDATE company_request_plates SET status = 'ISSUED', policy_number = ?, vin = ?, total_premium = ?,
                    folder_path = ?, issued_file_path = COALESCE(?, issued_file_path), issued_at = FROM_UNIXTIME(?), folder_status = ?,
                    pending_policy_temp_path = NULL, pending_policy_orig_name = NULL WHERE id = ?")
        ->execute([$policyNumber, $vin ?: null, $totalPremium, $issuedDir, $issuedFilePath, $issuedAt, $folderStatus, $plateId]);

    // اقساطِ همین بیمه‌نامه طبقِ قسط‌بندیِ شرکت
    $installmentCount = 0;
    if ($totalPremium) {
        $genResult = company_generate_installments($pdo, $plateId);
        $installmentCount = $genResult['count'] ?? 0;
    }
    // همه‌ی ردیف‌ها صادر شدند => خودِ درخواست هم ISSUED
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'ISSUED') AS issued FROM company_request_plates WHERE request_id = ?");
    $stmt->execute([$plate['request_id']]);
    $counts = $stmt->fetch();
    if ($counts && intval($counts['total']) > 0 && intval($counts['total']) === intval($counts['issued'])) {
        $pdo->prepare("UPDATE company_requests SET status = 'ISSUED' WHERE id = ?")->execute([$plate['request_id']]);
    }
    // خبر به کاربرانِ همان شرکت در ربات بله
    try {
        if (function_exists('cbot_notify_company')) {
            cbot_notify_company($pdo, intval($plate['company_id']),
                "✅ بیمه‌نامه‌ی " . insurance_type_fa($plate['insurance_type']) . " - " . company_row_label($plate) . " صادر شد"
                . ($policyNumber ? " (شماره " . $policyNumber . ")" : '') . ".\nدرخواست #" . $plate['request_id'],
                ['inline_keyboard' => [[['text' => '📥 دریافت بیمه‌نامه', 'callback_data' => 'pol:' . $plateId]]]]);
        }
    } catch (Throwable $e) {}

    return ['ok' => true, 'folder_status' => $folderStatus, 'installments' => $installmentCount, 'issued_folder' => $issuedFolderName,
            'issued_file' => $issuedFilePath];
}

// ---------------------------------------------------------------------
//  صدورِ گروهی
// ---------------------------------------------------------------------
function cbundle_python($pdo) {
    $def = '/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3';
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'report_python_path'");
        $st->execute();
        $v = trim((string)$st->fetchColumn());
        if ($v !== '') return $v;
    } catch (Throwable $e) {}
    return is_file($def) ? $def : 'python3';
}
function cbundle_root() {
    $d = dirname(__DIR__) . '/tmp_ocr/bundles';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    // بسته‌های رهاشده‌ی قدیمی‌تر از یک روز پاک می‌شوند
    foreach ((array)glob($d . '/b_*', GLOB_ONLYDIR) as $old) if (@filemtime($old) < time() - 86400) cbundle_rrmdir($old);
    return $d;
}
function cbundle_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach ((array)scandir($dir) as $f) { if ($f === '.' || $f === '..') continue; $p = $dir . '/' . $f; is_dir($p) ? cbundle_rrmdir($p) : @unlink($p); }
    @rmdir($dir);
}
function cbundle_run($pdo, $pdf, $outDir) {
    $py = cbundle_python($pdo);
    $script = __DIR__ . '/py/policy_bundle.py';
    if (!function_exists('proc_open')) return ['ok' => false, 'error' => 'اجرای پایتون روی این سرور مجاز نیست (proc_open غیرفعال است).'];
    $home = getenv('HOME');
    if (!$home && preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m)) $home = $m[1];
    $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $home ?: sys_get_temp_dir(), 'PYTHONWARNINGS' => 'ignore'];
    $proc = @proc_open([$py, $script, $pdf, $outDir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'اجرای پایتون ممکن نشد.'];
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $start = time();
    while (true) {
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        $s = proc_get_status($proc);
        if (!$s['running']) break;
        if (time() - $start > 420) { proc_terminate($proc, 9); return ['ok' => false, 'error' => 'پردازشِ فایل بیش از حد طول کشید.']; }
        usleep(80000);
    }
    $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $line = trim((string)substr($out, strrpos("\n" . trim($out), "\n")));
    $j = json_decode($line, true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'خروجیِ پردازشگرِ فایل خوانده نشد.', 'debug' => mb_substr(trim($err ?: $out), -800)];
    return $j;
}
function cbundle_norm_vin($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); }
function cbundle_vin_match($a, $b) {
    $a = cbundle_norm_vin($a); $b = cbundle_norm_vin($b);
    if (strlen($a) < 6 || strlen($b) < 6) return false;
    if ($a === $b) return true;
    $n = min(strlen($a), strlen($b));
    return $n >= 8 && substr($a, -8) === substr($b, -8);
}
// بیمه‌نامه‌ای «معتبر» است که نوعش (بدنه/ثالث) یا شماره‌اش و یکی از شناسه‌های خودرو (پلاک/شاسی) خوانده شده باشد
function cbundle_is_policy($d) {
    if (!is_array($d)) return false;
    $t = $d['ins_type'] ?? '';
    if ($t === 'معرفی‌نامه') return false;
    $known = in_array($t, ['بدنه', 'ثالث'], true) || trim((string)($d['policy_num'] ?? '')) !== '';
    return $known && (trim((string)($d['plate'] ?? '')) !== '' || trim((string)($d['vin'] ?? '')) !== '' || trim((string)($d['policy_num'] ?? '')) !== '');
}

// نتیجه‌ی شناسایی => ردیفِ مربوط + وضعیتِ آمادگی
// تاریخِ میلادیِ دیتابیس ← «1405/04/13»
function cbundle_jdate($ymd) {
    $ts = strtotime((string)$ymd);
    if (!$ts) return '';
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($ts);
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

// مغایرت‌های بیمه‌نامه‌ی خوانده‌شده با ردیفِ درخواست، فیلد به فیلد.
// level: hard = احتمالاً بیمه‌نامه‌ی اشتباه / اطلاعاتِ غلط (پلاک، شاسی، نوع)؛ soft = بهتر است بررسی شود
function cbundle_diffs($row, $d) {
    $out = [];
    $add = function ($field, $label, $rv, $pv, $level) use (&$out) { $out[] = ['field' => $field, 'label' => $label, 'row' => (string)$rv, 'policy' => (string)$pv, 'level' => $level]; };
    $norm = function ($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); };
    $rowPlate = company_plate_display($row['plate_p1'] ?? '', $row['plate_p2'] ?? '', $row['plate_letter'] ?? '', $row['plate_p4'] ?? '');
    $polPlate = (string)($d['plate'] ?? '');
    if ($rowPlate && $polPlate && plate_core($rowPlate) !== plate_core($polPlate)) $add('plate', 'پلاک', $rowPlate, $polPlate, 'hard');
    elseif ($rowPlate && !$polPlate) $add('plate', 'پلاک', $rowPlate, 'در بیمه‌نامه خوانده نشد', 'soft');
    $rowVin = trim((string)($row['chassis_no'] ?: ($row['vin'] ?? '')));
    if ($rowVin !== '' && !empty($d['vin']) && !cbundle_vin_match($d['vin'], $rowVin)) $add('vin', 'شماره شاسی', $rowVin, $d['vin'], 'hard');
    $ft = insurance_type_fa($row['insurance_type'] ?? '');
    if (in_array($d['ins_type'] ?? '', ['بدنه', 'ثالث'], true) && in_array($ft, ['بدنه', 'ثالث'], true) && $ft !== $d['ins_type']) $add('type', 'نوع بیمه', $ft, $d['ins_type'], 'hard');
    if (!empty($row['engine_no']) && !empty($d['engine_no']) && $norm($row['engine_no']) !== $norm($d['engine_no'])) $add('engine', 'شماره موتور', $row['engine_no'], $d['engine_no'], 'soft');
    if (($row['insurance_type'] ?? '') === 'BODY' && intval($row['car_value'] ?? 0) > 0 && !empty($d['car_value']) && intval($row['car_value']) !== intval($d['car_value']))
        $add('car_value', 'ارزش خودرو (ریال)', number_format((float)$row['car_value']), number_format((float)$d['car_value']), 'soft');
    if (($row['insurance_type'] ?? '') === 'THIRDPARTY' && intval($row['liability_limit'] ?? 0) > 0 && !empty($d['liability']) && intval($row['liability_limit']) !== intval($d['liability']))
        $add('liability', 'تعهد مالی (ریال)', number_format((float)$row['liability_limit']), number_format((float)$d['liability']), 'soft');
    if (!empty($row['expiry_date']) && !empty($d['prev_expiry']) && preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', p2e_digits($d['prev_expiry']), $m)) {
        $pe = date('Y-m-d', jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])));
        if ($pe !== substr((string)$row['expiry_date'], 0, 10)) $add('expiry', 'انقضای بیمه‌نامه‌ی قبلی', cbundle_jdate($row['expiry_date']), $d['prev_expiry'], 'soft');
    }
    // نامِ بیمه‌گذار با نامِ شرکتِ درخواست‌دهنده (بی‌توجه به «شرکت»، فاصله و ی/ک)
    $nm = function ($v) { $v = str_replace(['ي', 'ك', "\u{200C}", 'ـ'], ['ی', 'ک', '', ''], (string)$v); return preg_replace('/\s+|شرکت|سهامی|خاص|عام|[()\-.]/u', '', $v); };
    $comp = $nm($row['company_name'] ?? ''); $ins = $nm($d['insured_name'] ?? '');
    if ($comp !== '' && $ins !== '' && mb_strpos($comp, $ins) === false && mb_strpos($ins, $comp) === false)
        $add('insured', 'بیمه‌گذار', $row['company_name'], $d['insured_name'], 'soft');
    return $out;
}

function cbundle_match($pdo, $requestId, array $policies) {
    $st = $pdo->prepare("SELECT cr.id, cr.company_id FROM company_requests cr WHERE cr.id = ?");
    $st->execute([$requestId]);
    $req = $st->fetch();
    if (!$req) return null;
    $st = $pdo->prepare("SELECT crp.*, cr.request_kind, cr.company_id, c.name AS company_name
                           FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                          WHERE crp.status <> 'CANCELLED' AND (cr.id = ? OR cr.company_id = ?)");
    $st->execute([$requestId, $req['company_id']]);
    $rows = $st->fetchAll();
    $used = [];
    $items = [];
    foreach ($policies as $i => $p) {
        $d = $p['data'] ?? null;
        $it = ['i' => $i, 'page' => intval($p['page']) + 1, 'end' => intval($p['end']) + 1,
               'policy_end' => intval($p['policy_end'] ?? $p['page']) + 1, 'file' => $p['file'], 'seg_file' => $p['seg_file'],
               'via_ocr' => !empty($p['via_ocr']), 'data' => $d, 'state' => 'unknown', 'message' => '', 'row' => null,
               'missing_fields' => array_values((array)($p['missing'] ?? [])), 'diffs' => []];
        if (!cbundle_is_policy($d)) {
            $it['message'] = !empty($p['ocr_error']) ? 'خوانده نشد: ' . $p['ocr_error'] : 'این صفحه بیمه‌نامه‌ی معتبر تشخیص داده نشد (نوع، شماره یا پلاک/شاسی خوانده نشد).';
            $items[] = $it; continue;
        }
        $pc = plate_core($d['plate'] ?? '');
        $best = null; $bestScore = -1;
        foreach ($rows as $r) {
            $rp = company_plate_display($r['plate_p1'] ?? '', $r['plate_p2'] ?? '', $r['plate_letter'] ?? '', $r['plate_p4'] ?? '');
            $plateHit = $pc !== '' && $rp && plate_core($rp) === $pc;
            $vinHit = !empty($d['vin']) && (cbundle_vin_match($d['vin'], $r['chassis_no']) || cbundle_vin_match($d['vin'], $r['vin']));
            if (!$plateHit && !$vinHit) continue;
            $score = ($plateHit ? 4 : 0) + ($vinHit ? 4 : 0) + (intval($r['request_id']) === intval($requestId) ? 3 : 0)
                   + (($d['ins_type'] ?? '') === insurance_type_fa($r['insurance_type']) ? 2 : 0) + ($r['status'] !== 'ISSUED' ? 1 : 0) - (isset($used[$r['id']]) ? 6 : 0);
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
        }
        if (!$best) { $it['state'] = 'no_match'; $it['message'] = 'برای این بیمه‌نامه هیچ ردیفِ درخواستی (با همین پلاک/شاسی) در درخواست‌های این شرکت پیدا نشد.'; $items[] = $it; continue; }
        $kind = $best['request_kind'] ?? 'NEW_POLICY';
        $it['row'] = ['id' => intval($best['id']), 'request_id' => intval($best['request_id']), 'other_request' => intval($best['request_id']) !== intval($requestId),
                      'label' => company_row_label($best), 'plate_p1' => $best['plate_p1'], 'plate_p2' => $best['plate_p2'], 'plate_letter' => $best['plate_letter'],
                      'plate_p4' => $best['plate_p4'], 'chassis_no' => $best['chassis_no'], 'insurance_type' => $best['insurance_type'],
                      'insurance_type_fa' => insurance_type_fa($best['insurance_type']), 'status' => $best['status'],
                      'status_fa' => company_plate_status_fa($best['status'], $kind), 'kind' => $kind, 'policy_number' => $best['policy_number'],
                      'car_name' => $best['car_name'], 'total_premium' => $best['total_premium'], 'engine_no' => $best['engine_no'],
                      'car_value' => $best['car_value'], 'liability_limit' => $best['liability_limit'], 'company_name' => $best['company_name'],
                      'expiry_date' => $best['expiry_date'], 'expiry_date_jalali' => !empty($best['expiry_date']) ? cbundle_jdate($best['expiry_date']) : ''];
        $it['diffs'] = cbundle_diffs($best, $d);
        if (isset($used[$best['id']])) { $it['state'] = 'duplicate'; $it['message'] = 'این ردیف در همین فایل یک بیمه‌نامه‌ی دیگر هم دارد (صفحه‌ی ' . $used[$best['id']] . ').'; $items[] = $it; continue; }
        $used[$best['id']] = $it['page'];
        if ($kind === 'NEW_POLICY' && in_array($d['ins_type'] ?? '', ['بدنه', 'ثالث'], true) && $d['ins_type'] !== insurance_type_fa($best['insurance_type'])) {
            $it['state'] = 'mismatch'; $it['message'] = "این ردیف «" . insurance_type_fa($best['insurance_type']) . "» است ولی بیمه‌نامه «{$d['ins_type']}» خوانده شد.";
        } elseif ($best['status'] === 'ISSUED') {
            $it['state'] = 'issued'; $it['message'] = 'این ردیف قبلاً صادر شده' . ($best['policy_number'] ? ' (شماره ' . $best['policy_number'] . ')' : '') . '.';
        } elseif (in_array($best['status'], ['READY_FOR_ISSUE', 'WITH_BOSS', 'IN_ISSUANCE'], true)) {
            $it['state'] = 'ready';
        } else {
            // مرحله‌های باقی‌مانده: مدارکِ کم
            $ds = $pdo->prepare("SELECT doc_type FROM company_documents WHERE plate_id = ? AND status = 'ASSIGNED'");
            $ds->execute([$best['id']]);
            $missing = company_plate_missing_docs($best['insurance_type'], (bool)$best['skip_health_inspection'], array_filter(array_column($ds->fetchAll(), 'doc_type')),
                                                  $best['has_prev_body'], $kind);
            $it['state'] = 'not_ready';
            $it['missing'] = array_values($missing);
            $it['message'] = 'هنوز به مرحله‌ی صدور نرسیده (مرحله‌ی فعلی: ' . company_plate_status_fa($best['status'], $kind) . ')'
                           . ($missing ? '؛ کم دارد: ' . implode('، ', $missing) : '؛ مدارک کامل است، ردیف را به «آماده‌ی صدور» بفرستید')
                           . '. اول مراحلِ باقی‌مانده را انجام دهید.';
        }
        $items[] = $it;
    }
    return $items;
}
