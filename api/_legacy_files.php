<?php
// فایل: api/_legacy_files.php
// «ورودِ بایگانی‌های قبلی» — بخشِ فایل‌ها:
//   ۱) فایل‌ها در پوشه‌ی «موقت/ورود بایگانی» (با File Manager/FTP یا ZIP) گذاشته می‌شوند
//   ۲) اسکن (تکه‌تکه): برای هر فایل از نامِ خودش و نامِ پوشه‌هایش — و اگر لازم شد از متنِ PDF — شماره‌ی بیمه‌نامه،
//      شاسی یا پلاک پیدا و به بیمه‌نامه‌ی صادرشده‌ی سایت (شرکتی/کارکنان) وصل می‌شود؛ نوعِ هر فایل از نامش
//   ۳) ادغام: پوشه‌هایی که با نامِ خودشان (مثلِ بایگانیِ سایت) به یک بیمه‌نامه وصل شدند «کامل»اند و با یک دکمه
//      به پوشه‌ی همان بیمه‌نامه در بایگانی و «بایگانی صادره» کپی می‌شوند؛ بقیه دستی
// نیاز: _legacy.php و وابستگی‌هایش

function legacy_staging_info($siteRoot) {
    $root = legacy_staging_dir($siteRoot);
    $dirs = [];
    foreach ((array)scandir($root) as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') continue;
        $p = $root . '/' . $n;
        $dirs[] = ['name' => $n, 'is_dir' => is_dir($p), 'size' => is_file($p) ? filesize($p) : null];
    }
    return ['root' => ltrim(str_replace($siteRoot, '', $root), '/'), 'items' => $dirs];
}

function legacy_stage_zip($siteRoot, $tmp, $name) {
    $root = legacy_staging_dir($siteRoot);
    $dir = $root . '/' . sanitize_folder_name(preg_replace('/\.zip$/i', '', $name) ?: 'zip') . ' - ' . date('His');
    @mkdir($dir, 0775, true);
    $n = function_exists('company_extract_zip_to_dir') ? company_extract_zip_to_dir($tmp, $dir) : false;
    if ($n === false) return ['ok' => false, 'error' => 'فایلِ ZIP باز نشد.'];
    return ['ok' => true, 'folder' => basename($dir), 'files' => $n];
}

// ---- نشانه‌ها از نام ----
function legacy_tokens($text) {
    $t = str_replace(['∕', '_', '\\'], ['/', '/', '/'], p2e_digits(str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$text)));
    $out = ['policies' => [], 'vins' => [], 'plates' => []];
    if (preg_match_all('#(\d{1,2}/\d{3,6}/\d{3,6}-\d{1,3}/1[34]\d{2}/\d{1,7})#', $t, $m)) $out['policies'] = array_values(array_unique($m[1]));
    if (preg_match_all('/(?<![A-Z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Z0-9])/', strtoupper($t), $m))
        $out['vins'] = array_values(array_filter(array_unique($m[1]), fn($v) => preg_match('/[A-Z]/', $v) && preg_match('/\d/', $v)));
    if (preg_match_all('/(\d{2})\s*ایران\s*-?\s*(\d{3})\s*([آ-ی])\s*(\d{2})/u', $t, $m, PREG_SET_ORDER))
        foreach ($m as $x) $out['plates'][] = $x[1] . $x[2] . $x[3] . $x[4];
    return $out;
}

// نوعِ فایل از نامش (و نامِ پوشه‌اش)
function legacy_file_type($name, $dir = '') {
    $n = str_replace(['ي', 'ك', "\xE2\x80\x8C"], ['ی', 'ک', ' '], mb_strtolower($name));
    $d = str_replace(['ي', 'ك', "\xE2\x80\x8C"], ['ی', 'ک', ' '], mb_strtolower($dir));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (preg_match('/اطلاعات درخواستی|desktop\.ini|thumbs\.db/u', $n)) return 'skip';
    if (preg_match('/الحاق/u', $n)) return 'endorsement';
    if (preg_match('/فاکتور|invoice/u', $n)) return 'sales_invoice';
    if (preg_match('/گزارش\s*(بازدید|کارشناس)/u', $n)) return 'health_report';
    if (preg_match('/عکس.*بازدید|بازدید.*عکس/u', $d) || preg_match('/^\d{2}\s*-\s*(نمای|مواضع)/u', $n)) return 'health_inspection';
    if (preg_match('/بدنه\s*قبل/u', $n)) return 'prev_body_policy';
    if (preg_match('/ثالث\s*قبل/u', $n)) return 'prev_third_policy';
    if (preg_match('/کارت.*(پشت|back)/u', $n)) return 'car_card_back';
    if (preg_match('/کارت/u', $n)) return 'car_card_front';
    if (preg_match('/(^|[^\p{L}])سند([^\p{L}]|$)/u', $n)) return 'ownership_doc';
    if (preg_match('/معرفی\s*نامه/u', $n)) return 'intro';
    if (preg_match('/فیش|اعلامیه|اقساط/u', $n . ' ' . $d)) return 'receipt';
    if ($ext === 'pdf' && legacy_tokens($name)['policies']) return 'policy';
    return 'other';
}
function legacy_type_fa($t) {
    return ['policy' => 'بیمه‌نامه', 'endorsement' => 'الحاقیه', 'sales_invoice' => 'فاکتور فروش', 'health_report' => 'گزارش بازدید', 'health_inspection' => 'عکس بازدید',
            'prev_body_policy' => 'بیمه بدنه قبل', 'prev_third_policy' => 'بیمه ثالث قبل', 'car_card_back' => 'کارت ماشین پشت', 'car_card_front' => 'کارت ماشین رو',
            'ownership_doc' => 'سند', 'intro' => 'معرفی‌نامه', 'receipt' => 'فیش/اعلامیه اقساط', 'other' => 'سایر مدارک', 'skip' => 'نادیده'][$t] ?? $t;
}

// همه‌ی بیمه‌نامه‌های صادرشده (شرکتی و کارکنان) با شماره، شاسی و پلاک — برای پیدا کردنِ صاحبِ هر فایل
function legacy_policy_maps($pdo) {
    $maps = ['key' => [], 'vin' => [], 'plate' => [], 'info' => []];
    $add = function ($src, $id, $pol, $vin, $plateCore, $label) use (&$maps) {
        $t = $src . ':' . $id;
        $maps['info'][$t] = ['source' => $src, 'ref_id' => (int)$id, 'policy' => $pol, 'label' => $label];
        if (($k = endo_policy_key($pol)) !== '') $maps['key'][$k] = $t;
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$vin));
        if (strlen($v) >= 8) $maps['vin'][$v][] = $t;
        if ($plateCore) $maps['plate'][$plateCore][] = $t;
    };
    foreach ($pdo->query("SELECT crp.id, crp.policy_number, crp.import_policy_number, crp.vin, crp.chassis_no, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, c.name
                            FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                           WHERE crp.status = 'ISSUED' AND cr.request_kind = 'NEW_POLICY'") as $r) {
        $core = $r['plate_p2'] ? $r['plate_p1'] . $r['plate_p2'] . $r['plate_letter'] . $r['plate_p4'] : '';
        $add('C', $r['id'], $r['policy_number'] ?: $r['import_policy_number'], $r['vin'] ?: $r['chassis_no'], $core,
             (company_plate_display($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) ?: $r['chassis_no']) . ' · ' . $r['name']);
    }
    foreach ($pdo->query("SELECT id, policy_number, vin, chassis_num, plate, insured_name FROM policy_cases WHERE status = 'ISSUED'") as $r) {
        $core = preg_replace('/[\s\-ایرن]+/u', '', str_replace('ایران', '', (string)$r['plate']));
        $core = preg_match('/(\d{2})ایران\s*-\s*(\d{3})\s*(\S)\s*(\d{2})/u', (string)$r['plate'], $m) ? $m[1] . $m[2] . $m[3] . $m[4] : '';
        $add('P', $r['id'], $r['policy_number'], $r['vin'] ?: $r['chassis_num'], $core, ($r['plate'] ?: $r['vin']) . ' · ' . $r['insured_name']);
    }
    return $maps;
}

// صاحبِ یک مجموعه نشانه => 'C:12' یا null (و دلیل)
function legacy_match($maps, array $tok) {
    foreach ($tok['policies'] ?? [] as $p) { $k = endo_policy_key($p); if (isset($maps['key'][$k])) return [$maps['key'][$k], 'policy']; }
    foreach ($tok['vins'] ?? [] as $v) { $c = $maps['vin'][$v] ?? []; if (count(array_unique($c)) === 1) return [$c[0], 'vin']; }
    foreach ($tok['plates'] ?? [] as $p) { $c = $maps['plate'][$p] ?? []; if (count(array_unique($c)) === 1) return [$c[0], 'plate']; }
    return [null, null];
}

function legacy_scan_path($siteRoot, $id) {
    return preg_match('/^[a-f0-9]{16}$/', (string)$id) ? legacy_tmp_dir($siteRoot) . '/s_' . $id . '.json' : null;
}

// اسکنِ تکه‌تکه: بارِ اول فهرستِ فایل‌ها ساخته می‌شود؛ هر بار $limit فایل بررسی می‌شود
function legacy_scan($pdo, $siteRoot, $scanId, $sub, $offset, $limit) {
    $root = legacy_staging_dir($siteRoot);
    if ($scanId === '') {
        $base = $root . ($sub !== '' ? '/' . str_replace(['..', '\\'], '', trim($sub, '/')) : '');
        if (!is_dir($base)) return ['ok' => false, 'error' => 'پوشه پیدا نشد.'];
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'], true)) continue;
            $files[] = ['rel' => ltrim(substr($f->getPathname(), strlen($root)), '/'), 'size' => $f->getSize()];
            if (count($files) >= 60000) break;
        }
        usort($files, fn($a, $b) => strcmp($a['rel'], $b['rel']));
        $scanId = bin2hex(random_bytes(8));
        $state = ['sub' => $sub, 'files' => $files, 'done' => 0, 'merged' => []];
        file_put_contents(legacy_scan_path($siteRoot, $scanId), json_encode($state, JSON_UNESCAPED_UNICODE));
        return ['ok' => true, 'scan' => $scanId, 'total' => count($files), 'done' => 0];
    }
    $sp = legacy_scan_path($siteRoot, $scanId);
    if (!$sp || !is_file($sp)) return ['ok' => false, 'error' => 'اسکن پیدا نشد؛ دوباره شروع کنید.'];
    $state = json_decode((string)file_get_contents($sp), true);
    $maps = legacy_policy_maps($pdo);
    $chunk = array_slice($state['files'], $offset, $limit, true);
    $needText = [];
    foreach ($chunk as $i => $f) {
        $dirTok = legacy_tokens(dirname($f['rel']));
        $nameTok = legacy_tokens(basename($f['rel']));
        // پوشه: عمیق‌ترین پوشه‌ای که نامش به یک بیمه‌نامه می‌خورد، ریشه‌ی گروه است
        $parts = explode('/', dirname($f['rel']));
        $grpRoot = null; $grpT = null; $grpBy = null;
        for ($k = count($parts); $k >= 1; $k--) {
            $seg = $parts[$k - 1];
            if ($seg === '.' || $seg === '') continue;
            [$t, $by] = legacy_match($maps, legacy_tokens($seg));
            if ($t) { $grpRoot = implode('/', array_slice($parts, 0, $k)); $grpT = $t; $grpBy = $by; break; }
        }
        [$ft, $fby] = legacy_match($maps, $nameTok);
        $state['files'][$i]['type'] = legacy_file_type(basename($f['rel']), dirname($f['rel']));
        $state['files'][$i]['grp'] = $grpRoot; $state['files'][$i]['grp_t'] = $grpT; $state['files'][$i]['grp_by'] = $grpBy;
        $state['files'][$i]['t'] = $ft ?: $grpT; $state['files'][$i]['by'] = $ft ? $fby : $grpBy;
        $state['files'][$i]['pol_name'] = (bool)$nameTok['policies'];
        // PDFهایی که صاحب یا نوعشان از نام معلوم نشد: متنِ صفحه‌های اول خوانده می‌شود
        if ((!$state['files'][$i]['t'] || $state['files'][$i]['type'] === 'other') && strtolower(pathinfo($f['rel'], PATHINFO_EXTENSION)) === 'pdf') $needText[$i] = $root . '/' . $f['rel'];
    }
    // متنِ PDFهایی که از نامشان پیدا نشد
    if ($needText) {
        $lf = legacy_tmp_dir($siteRoot) . '/l_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($lf, json_encode(array_values($needText), JSON_UNESCAPED_UNICODE));
        $r = endo_py($pdo, 'legacy_scan.py', [$lf], 240);
        @unlink($lf);
        foreach ($needText as $i => $abs) {
            $info = $r['files'][$abs] ?? null;
            if (!$info) continue;
            $plates = [];
            foreach ((array)$info['plates'] as $pl) if (preg_match('/(\d{2})ایران\s*-?\s*(\d{3})\s*(\S)\s*(\d{2})/u', $pl, $m)) $plates[] = $m[1] . $m[2] . $m[3] . $m[4];
            [$t, $by] = legacy_match($maps, ['policies' => $info['policies'], 'vins' => $info['vins'], 'plates' => $plates]);
            if ($t && !$state['files'][$i]['t']) { $state['files'][$i]['t'] = $t; $state['files'][$i]['by'] = 'text-' . $by; }
            if ($info['kind'] === 'policy' && in_array($state['files'][$i]['type'], ['other', 'policy'], true)) $state['files'][$i]['type'] = 'policy';
            if ($info['kind'] === 'endorse') $state['files'][$i]['type'] = 'endorsement';
            if ($info['kind'] === 'statement') $state['files'][$i]['type'] = 'receipt';
        }
    }
    $state['done'] = max($state['done'], $offset + count($chunk));
    file_put_contents($sp, json_encode($state, JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'scan' => $scanId, 'total' => count($state['files']), 'done' => $state['done'], 'finished' => $state['done'] >= count($state['files'])];
}

// گروه‌ها: (۱) پوشه‌هایی که خودشان به بیمه‌نامه خوردند — «کامل/مثلِ بایگانیِ سایت» اگر با شماره‌ی بیمه‌نامه
// (۲) فایل‌های تکی که خودشان پیدا شدند (به تفکیکِ پوشه و بیمه‌نامه) (۳) پیدا نشده‌ها
function legacy_scan_result($pdo, $siteRoot, $scanId) {
    $sp = legacy_scan_path($siteRoot, $scanId);
    if (!$sp || !is_file($sp)) return ['ok' => false, 'error' => 'اسکن پیدا نشد.'];
    $state = json_decode((string)file_get_contents($sp), true);
    $maps = legacy_policy_maps($pdo);
    $groups = []; $unmatched = [];
    foreach ($state['files'] as $i => $f) {
        if (!array_key_exists('t', $f)) continue;   // هنوز اسکن نشده
        if (($f['type'] ?? '') === 'skip') continue;
        if (!empty($state['merged'][$i])) continue;
        if ($f['grp'] && $f['grp_t'] && (!$f['t'] || $f['t'] === $f['grp_t'])) { $gk = 'D|' . $f['grp']; $t = $f['grp_t']; $by = $f['grp_by']; }
        elseif ($f['t']) { $gk = 'F|' . dirname($f['rel']) . '|' . $f['t']; $t = $f['t']; $by = $f['by']; }
        else { $unmatched[] = ['i' => $i, 'rel' => $f['rel'], 'type' => $f['type'] ?? 'other', 'type_fa' => legacy_type_fa($f['type'] ?? 'other'), 'size' => $f['size']]; continue; }
        if (!isset($groups[$gk])) {
            $info = $maps['info'][$t] ?? ['label' => $t, 'policy' => ''];
            $groups[$gk] = ['key' => $gk, 'target' => $t, 'by' => $by, 'by_fa' => ['policy' => 'شماره بیمه‌نامه', 'vin' => 'شاسی', 'plate' => 'پلاک', 'text-policy' => 'شماره بیمه‌نامه (متن PDF)', 'text-vin' => 'شاسی (متن PDF)', 'text-plate' => 'پلاک (متن PDF)'][$by] ?? $by,
                            'folder' => $gk[0] === 'D' ? $f['grp'] : dirname($f['rel']), 'label' => $info['label'], 'policy' => $info['policy'],
                            'complete' => $gk[0] === 'D' && $by === 'policy', 'files' => [], 'size' => 0];
        }
        $groups[$gk]['files'][] = ['i' => $i, 'rel' => $f['rel'], 'type' => $f['type'] ?? 'other', 'type_fa' => legacy_type_fa($f['type'] ?? 'other')];
        $groups[$gk]['size'] += (int)$f['size'];
    }
    $groups = array_values($groups);
    usort($groups, fn($a, $b) => ($b['complete'] <=> $a['complete']) ?: strcmp($a['folder'], $b['folder']));
    return ['ok' => true, 'total' => count($state['files']), 'done' => $state['done'], 'merged' => count($state['merged']),
            'groups' => $groups, 'unmatched' => array_slice($unmatched, 0, 3000), 'unmatched_count' => count($unmatched),
            'complete_count' => count(array_filter($groups, fn($g) => $g['complete']))];
}

function legacy_find_targets($pdo, $q) {
    $maps = legacy_policy_maps($pdo);
    $qk = endo_policy_key($q); $qn = mb_strtolower(trim(p2e_digits($q)));
    $out = [];
    foreach ($maps['info'] as $t => $i) {
        if (($qk !== '' && strlen($qk) >= 4 && strpos(endo_policy_key($i['policy']), $qk) !== false) || ($qn !== '' && mb_strpos(mb_strtolower(p2e_digits($i['label'])), $qn) !== false))
            $out[] = ['target' => $t, 'label' => $i['label'], 'policy' => $i['policy']];
        if (count($out) >= 30) break;
    }
    return $out;
}

// پوشه‌ی مقصد + نام‌گذاری برای یک بیمه‌نامه
function legacy_target_ctx($pdo, $siteRoot, $t) {
    [$src, $id] = explode(':', $t) + [null, 0];
    $id = (int)$id;
    if ($src === 'C') {
        $st = $pdo->prepare("SELECT crp.*, cr.request_kind, cr.company_id, c.name AS company_name FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        $dir = ($r['folder_path'] && is_dir($r['folder_path'])) ? $r['folder_path'] : ensure_plate_folder($pdo, $siteRoot, $id);
        $label = company_row_label($r);
        $ts = policy_issue_ts((string)$r['policy_issue_date'], strtotime((string)$r['issued_at']) ?: time());
        return ['src' => 'C', 'id' => $id, 'row' => $r, 'dir' => $dir, 'plate' => plate_for_filename($label), 'insured' => $r['company_name'],
                'policy' => $r['policy_number'], 'vin' => $r['vin'] ?: $r['chassis_no'], 'sadere' => company_kind_goes_to_sadere($r['request_kind']) && $dir ? build_sadere_base_path($siteRoot, $ts, $r['insurance_type']) . '/' . basename($dir) : null];
    }
    if ($src === 'P') {
        $st = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        $dir = ($r['folder_path'] && is_dir($r['folder_path'])) ? $r['folder_path'] : legacy_personnel_folders($pdo, $siteRoot, $id, null);
        $ts = policy_issue_ts((string)$r['policy_issue_date'], strtotime((string)$r['issued_at']) ?: time());
        return ['src' => 'P', 'id' => $id, 'row' => $r, 'dir' => $dir, 'plate' => plate_for_filename($r['plate'] ?: ($r['vin'] ?: 'بدون‌پلاک')), 'insured' => $r['insured_name'],
                'policy' => $r['policy_number'], 'vin' => $r['vin'], 'sadere' => $dir ? build_sadere_base_path($siteRoot, $ts, $r['insurance_type']) . '/' . basename($dir) : null];
    }
    return null;
}

// کپیِ یک فایل به پوشه‌ی بیمه‌نامه با نام‌گذاریِ سایت و ثبتش (مدرکِ شرکتی / مدرکِ کارکنان / فایلِ بیمه‌نامه)
function legacy_place_file($pdo, $siteRoot, array $ctx, $abs, $type, $relInGroup = '') {
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'pdf';
    $orig = basename($abs);
    $dir = $ctx['dir'];
    // زیرپوشه‌های داخلِ گروه (مثلِ «عکس‌های بازدید») همان‌طور می‌مانند
    $subDir = trim(dirname($relInGroup), './');
    if ($subDir !== '') { $dir .= '/' . $subDir; if (!is_dir($dir)) @mkdir($dir, 0775, true); }
    $siteLike = preg_match('/^\(.+\)/u', $orig) || mb_strpos($orig, (string)$ctx['plate']) !== false;
    if ($type === 'policy') {
        $name = build_final_policy_filename($ctx['plate'], $ctx['insured'], policy_number_for_filename($ctx['policy']), $ctx['vin'], $ext);
    } elseif ($siteLike || $subDir !== '' || in_array($type, ['health_inspection', 'receipt', 'other', 'intro'], true)) {
        $name = $orig;
    } else {
        $name = sanitize_folder_name('(' . legacy_type_fa($type) . ') ' . $ctx['plate']) . '.' . $ext;
    }
    $dest = $dir . '/' . $name;
    $skipped = is_file($dest) && filesize($dest) === filesize($abs);   // قبلاً همین فایل آمده (ادغامِ دوباره)
    if (!$skipped) {
        $dest = unique_dest_path($dest);
        if (!@copy($abs, $dest)) return ['ok' => false];
    }
    $rel = ltrim(str_replace($siteRoot, '', $dest), '/');
    if ($skipped) {
        $q = $ctx['src'] === 'C' ? "SELECT COUNT(*) FROM company_documents WHERE plate_id = ? AND file_path = ?" : "SELECT COUNT(*) FROM case_documents WHERE case_id = ? AND file_path = ?";
        $chk = $pdo->prepare($q);
        $chk->execute([$ctx['id'], $rel]);
        if ($type === 'policy' || (int)$chk->fetchColumn()) return ['ok' => true, 'dest' => $dest, 'skipped' => true];
    }
    $docTypes = company_doc_types();
    if ($ctx['src'] === 'C') {
        if ($type === 'policy') {
            $pdo->prepare("UPDATE company_request_plates SET issued_file_path = COALESCE(issued_file_path, ?) WHERE id = ?")->execute([$rel, $ctx['id']]);
        } elseif (isset($docTypes[$type]) && $subDir === '') {
            $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, file_path, orig_name, file_kind, doc_type, status, assigned_at) VALUES (?, ?, ?, ?, ?, 'SUPPORTING_DOC', ?, 'ASSIGNED', NOW())")
                ->execute([$ctx['row']['company_id'] ?? null, $ctx['row']['request_id'], $ctx['id'], $rel, $orig, $type]);
        }
    } else {
        if ($type === 'policy') {
            $pdo->prepare("UPDATE policy_cases SET issued_file_path = COALESCE(NULLIF(issued_file_path, ''), ?) WHERE id = ?")->execute([$rel, $ctx['id']]);
        } elseif ($subDir === '' && !in_array($type, ['receipt', 'intro', 'endorsement', 'health_inspection'], true)) {
            $key = ['prev_body_policy' => 'prev_body_insurance', 'prev_third_policy' => 'prev_third_insurance'][$type] ?? $type;
            $pdo->prepare("INSERT INTO case_documents (case_id, doc_key, doc_label, file_path, status) VALUES (?, ?, ?, ?, 'APPROVED')")
                ->execute([$ctx['id'], $key, legacy_type_fa($type), $rel]);
        }
    }
    return ['ok' => true, 'dest' => $dest, 'skipped' => $skipped];
}

// ادغام: گروه‌های انتخاب‌شده (یا همه‌ی «کامل»ها) + تعیینِ دستیِ فایل‌های تکی ([i => [target, type]])
function legacy_merge($pdo, $siteRoot, $scanId, array $groupKeys, $allComplete, array $manual, $userId) {
    $sp = legacy_scan_path($siteRoot, $scanId);
    if (!$sp || !is_file($sp)) return ['ok' => false, 'error' => 'اسکن پیدا نشد.'];
    $res = legacy_scan_result($pdo, $siteRoot, $scanId);
    $state = json_decode((string)file_get_contents($sp), true);
    $root = legacy_staging_dir($siteRoot);
    $want = array_flip($groupKeys);
    $done = ['files' => 0, 'skipped' => 0, 'failed' => 0, 'groups' => 0, 'targets' => []];
    $ctxCache = [];
    $ctxOf = function ($t) use (&$ctxCache, $pdo, $siteRoot) { return $ctxCache[$t] ?? ($ctxCache[$t] = legacy_target_ctx($pdo, $siteRoot, $t)); };
    $jobs = [];
    foreach ($res['groups'] as $g) {
        if (!isset($want[$g['key']]) && !($allComplete && $g['complete'])) continue;
        $done['groups']++;
        foreach ($g['files'] as $f) $jobs[] = [$f['i'], $g['target'], $f['type'], $g['key'][0] === 'D' ? ltrim(substr($f['rel'], strlen($g['folder'])), '/') : basename($f['rel'])];
    }
    foreach ($manual as $i => $m) {
        if (empty($m['target'])) continue;
        $jobs[] = [(int)$i, (string)$m['target'], (string)($m['type'] ?? 'other'), basename($state['files'][(int)$i]['rel'] ?? '')];
    }
    foreach ($jobs as [$i, $t, $type, $relIn]) {
        if (!empty($state['merged'][$i]) || !isset($state['files'][$i])) continue;
        $ctx = $ctxOf($t);
        if (!$ctx || !$ctx['dir']) { $done['failed']++; continue; }
        try {
            $r = legacy_place_file($pdo, $siteRoot, $ctx, $root . '/' . $state['files'][$i]['rel'], $type, $relIn);
        } catch (Throwable $e) {
            error_log('[legacy_merge] ' . $state['files'][$i]['rel'] . ': ' . $e->getMessage());
            $r = ['ok' => false];
        }
        if (empty($r['ok'])) { $done['failed']++; continue; }
        $state['merged'][$i] = $t;
        !empty($r['skipped']) ? $done['skipped']++ : $done['files']++;
        $done['targets'][$t] = true;
    }
    // «بایگانی صادره» و فایلِ اطلاعاتِ هر بیمه‌نامه‌ای که فایل گرفت به‌روز می‌شود
    foreach (array_keys($done['targets']) as $t) {
        $ctx = $ctxOf($t);
        if (!$ctx) continue;
        if ($ctx['src'] === 'P') write_case_info_file($pdo, $ctx['dir'], $ctx['id']);
        if ($ctx['sadere']) { if (!is_dir($ctx['sadere'])) @mkdir($ctx['sadere'], 0775, true); copy_dir_recursive($ctx['dir'], $ctx['sadere']); }
        if ($ctx['src'] === 'C') company_sync_plate_status($pdo, $ctx['id']);
    }
    file_put_contents($sp, json_encode($state, JSON_UNESCAPED_UNICODE));
    $done['targets'] = count($done['targets']);
    return ['ok' => true] + $done;
}
