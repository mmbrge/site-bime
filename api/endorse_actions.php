<?php
// فایل: api/endorse_actions.php
// «الحاقیه‌ها»: خواندنِ فایلِ الحاقیه، پیدا کردنِ بیمه‌نامه، پیش‌نمایشِ اثرِ مالی، ثبت، حذف و فهرست.
// منطق در api/_endorse.php است.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require __DIR__ . '/finance_core.php';
require_once __DIR__ . '/_company_issue.php';
require __DIR__ . '/_endorse.php';

$actor = require_admin_or_liaison();
endo_ensure($pdo);
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$data = $data + $_GET;
$action = $data['action'] ?? '';
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
// ثبت و حذف: مدیر کل (یا کاربری که دسترسیِ سفارشی‌اش اجازه داده و perm_gate بالا آن را بررسی کرده)
$canWrite = ($actor['role'] ?? '') === 'ADMIN';
if (in_array($action, ['read', 'preview', 'create', 'delete', 'credit_paid', 'final_read', 'final_attach', 'bundle_analyze', 'bundle_commit',
                         'final_bundle_analyze', 'final_bundle_commit'], true) && !$canWrite) $out(['ok' => false, 'error' => 'ثبتِ الحاقیه فقط کارِ مدیر کل است.']);

$siteRoot = dirname(__DIR__);
endo_backfill_copies($pdo, $siteRoot);
$tmpRoot = $siteRoot . '/tmp_ocr/endorse';
if (!is_dir($tmpRoot)) { @mkdir($tmpRoot, 0775, true); @file_put_contents($tmpRoot . '/.htaccess', "Require all denied\nDeny from all\n"); }
foreach ((array)glob($tmpRoot . '/[ef]_*.{pdf,json}', GLOB_BRACE) as $old) if (@filemtime($old) < time() - 86400) @unlink($old);
foreach ((array)glob($tmpRoot . '/[bg]_*', GLOB_ONLYDIR) as $old) if (@filemtime($old) < time() - 86400) endo_rrmdir($old);
$finalPath = function ($tok) use ($tmpRoot) { return preg_match('/^[a-f0-9]{20}$/', (string)$tok) ? $tmpRoot . '/f_' . $tok . '.pdf' : null; };
$batchDir = function ($tok, $p = 'b') use ($tmpRoot) { return preg_match('/^[a-f0-9]{20}$/', (string)$tok) ? $tmpRoot . '/' . $p . '_' . $tok : null; };
// فایلِ آپلودشده (PDF) => مسیرِ موقت؛ خطا => null
$takePdf = function ($f, $dest) {
    if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return null;
    if (strncmp((string)@file_get_contents($f['tmp_name'], false, null, 0, 4), '%PDF', 4) !== 0) return null;
    return move_uploaded_file($f['tmp_name'], $dest) ? $dest : null;
};
$tmpPath = function ($tok) use ($tmpRoot) { return preg_match('/^[a-f0-9]{20}$/', (string)$tok) ? $tmpRoot . '/e_' . $tok . '.pdf' : null; };

// اطلاعاتِ یک بیمه‌نامه برای فرم: حق بیمه، نهایی، اقساط (باز/پرداخت‌شده) و الحاقیه‌های قبلی
$policyInfo = function ($source, $refId) use ($pdo) {
    $pol = endo_load_policy($pdo, $source, $refId);
    if (!$pol) return null;
    $insts = endo_installments($pdo, $source, $refId);
    $open = array_filter($insts, fn($i) => $i['open']);
    return $pol['info'] + [
        'installments' => $insts, 'open_count' => count($open), 'open_remaining' => array_sum(array_column($open, 'remaining')),
        'paid_total' => array_sum(array_column($insts, 'paid')), 'no_installments' => (int)($pol['row']['no_installments'] ?? 0),
        'endorsements' => endo_list($pdo, ['ref' => [$source, $refId]])['rows'],
    ];
};

try {
    // ۱) آپلودِ فایلِ الحاقیه => خواندن + بیمه‌نامه‌های هم‌شماره
    if ($action === 'read') {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'فایل دریافت نشد.']);
        if (strncmp((string)@file_get_contents($f['tmp_name'], false, null, 0, 4), '%PDF', 4) !== 0) $out(['ok' => false, 'error' => 'فایلِ الحاقیه باید PDF باشد.']);
        $tok = bin2hex(random_bytes(10));
        $p = $tmpPath($tok);
        if (!move_uploaded_file($f['tmp_name'], $p)) $out(['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.']);
        $r = endo_read_pdf($pdo, $p);
        $d = $r['data'] ?? [];
        // بیمه‌نامه: اول همانی که از ردیفِ درخواست آمده، بعد شماره‌ی داخلِ فایل
        $matches = [];
        $ref = trim((string)($data['ref_policy'] ?? ''));
        foreach (array_unique(array_filter([$d['policy_num'] ?? '', $ref])) as $pn) foreach (endo_find_policies($pdo, $pn) as $m) $matches[$m['source'] . $m['ref_id']] = $m;
        $matches = array_values($matches);
        $first = $matches ? $policyInfo($matches[0]['source'], $matches[0]['ref_id']) : null;
        $warn = [];
        if ($ref !== '' && !empty($d['policy_num']) && endo_policy_key($ref) !== endo_policy_key($d['policy_num'])) $warn[] = 'شماره‌ی بیمه‌نامه‌ی داخلِ فایل (' . $d['policy_num'] . ') با شماره‌ی ثبت‌شده در درخواست (' . $ref . ') یکی نیست.';
        $out(['ok' => true, 'token' => $tok, 'read_ok' => !empty($r['ok']), 'read_error' => $r['ok'] ?? false ? null : ($r['error'] ?? null),
              'data' => $d, 'statement' => $r['statement'] ?? [], 'missing' => $r['missing'] ?? [], 'matches' => $matches, 'policy' => $first, 'warnings' => $warn]);
    }

    if ($action === 'policy_search') {
        $q = trim((string)($data['q'] ?? ''));
        $out(['ok' => true, 'matches' => $q !== '' ? endo_find_policies($pdo, $q, 20) : []]);
    }

    if ($action === 'policy_info') {
        $info = $policyInfo((string)($data['source'] ?? ''), intval($data['ref_id'] ?? 0));
        $out($info ? ['ok' => true, 'policy' => $info] : ['ok' => false, 'error' => 'بیمه‌نامه پیدا نشد.']);
    }

    // ۲) پیش‌نمایشِ اثرِ مالی (بدونِ ثبت)
    if ($action === 'preview') {
        $kind = strtoupper((string)($data['kind'] ?? ''));
        $plan = endo_plan_finance($pdo, (string)($data['source'] ?? 'X'), intval($data['ref_id'] ?? 0), $kind,
                                  intval(preg_replace('/\D/', '', (string)($data['gross'] ?? 0))), strtoupper((string)($data['finance_mode'] ?? 'NONE')),
                                  ['effect_date' => $data['effect_date'] ?? '', 'count' => $data['finance_opt']['count'] ?? 1,
                                   'first_due' => $data['finance_opt']['first_due'] ?? '', 'schedule' => $data['finance_opt']['schedule'] ?? []]);
        $out(['ok' => true, 'plan' => $plan]);
    }

    // ۳) ثبت (+ اگر از ردیفِ درخواستِ الحاقیه/فسخ آمده، همان ردیف هم «صادر» می‌شود)
    if ($action === 'create') {
        $src = $tmpPath($data['token'] ?? '');
        if ($src && !is_file($src)) $src = null;
        $reqPlateId = intval($data['request_plate_id'] ?? 0);
        $reqRow = null;
        if ($reqPlateId) {
            $st = $pdo->prepare("SELECT crp.*, cr.request_kind FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
            $st->execute([$reqPlateId]);
            $reqRow = $st->fetch();
            if (!$reqRow || !in_array($reqRow['request_kind'], ['ENDORSEMENT', 'CANCELLATION'], true)) $out(['ok' => false, 'error' => 'ردیفِ درخواستِ الحاقیه/فسخ پیدا نشد.']);
        }
        // یک نسخه برای پوشه‌ی بیمه‌نامه و یک نسخه برای ردیفِ درخواست؛ اگر بیمه‌نامه‌ی اصلی پوشه‌ی بایگانی ندارد،
        // الحاقیه به همان فایلِ ردیفِ درخواست (بایگانیِ شرکتی) اشاره می‌کند و نسخه‌ی موقت ساخته نمی‌شود
        $copyForRow = null;
        if ($src && $reqRow) {
            $copyForRow = $src . '.row.pdf'; @copy($src, $copyForRow);
            $pol = in_array($data['source'] ?? '', ['C', 'P'], true) ? endo_load_policy($pdo, $data['source'], intval($data['ref_id'] ?? 0)) : null;
            $tmpArch = function_exists('temp_archive_root') ? temp_archive_root($siteRoot) : '';
            if (!$pol || ($tmpArch !== '' && strpos(endo_policy_folder($pdo, $siteRoot, $data['source'], $pol['row']), $tmpArch) === 0)) { @unlink($src); $src = null; }
        }
        $in = $data;
        $in['origin'] = $reqRow ? 'REQUEST' : 'MANUAL';
        $res = endo_create($pdo, $siteRoot, $in, $src, $actor['user_id']);
        if (empty($res['ok'])) { if ($copyForRow) @unlink($copyForRow); $out($res); }
        // بیمه‌نامه‌ی نهایی (اگر در همان پنجره داده شد)
        $fp = $finalPath($data['final_token'] ?? '');
        if ($fp && is_file($fp)) {
            $fd = json_decode((string)@file_get_contents(substr($fp, 0, -4) . '.json'), true);
            $res['final'] = endo_attach_final($pdo, $siteRoot, $res['id'], $fp, is_array($fd) ? $fd : null, true, true);
            @unlink(substr($fp, 0, -4) . '.json');
        }
        if ($reqRow) {
            $iss = company_issue_plate($pdo, $reqPlateId, trim((string)($data['policy_number'] ?? '')) ?: ($reqRow['ref_policy_number'] ?? ''), $reqRow['vin'] ?? null,
                                       abs(intval(preg_replace('/\D/', '', (string)($data['gross'] ?? 0)))) ?: null, $copyForRow, 'الحاقیه.pdf', $data['issue_date'] ?? null);
            if ($copyForRow && is_file($copyForRow)) @unlink($copyForRow);
            $pdo->prepare("UPDATE policy_endorsements SET request_plate_id = ?, file_path = COALESCE(file_path, ?) WHERE id = ?")
                ->execute([$reqPlateId, $iss['issued_file'] ?? null, $res['id']]);
            $res['request_issue'] = $iss;
        }
        $out($res);
    }

    // ---- بیمه‌نامه‌ی نهایی (بعد از الحاقیه) ----
    // خواندن (پیش از ثبت، در پنجره‌ی الحاقیه): تغییرها نسبت به اطلاعاتِ فعلیِ بیمه‌نامه
    if ($action === 'final_read') {
        $tok = bin2hex(random_bytes(10));
        $p = $takePdf($_FILES['file'] ?? null, $finalPath($tok));
        if (!$p) $out(['ok' => false, 'error' => 'فایلِ بیمه‌نامه‌ی نهایی باید PDF باشد.']);
        $rd = endo_read_policy_file($pdo, $p, (string)($data['policy_number'] ?? ''));
        $d = !empty($rd['ok']) ? (array)$rd['data'] : [];
        @file_put_contents(substr($p, 0, -4) . '.json', json_encode($d, JSON_UNESCAPED_UNICODE));
        $src = (string)($data['source'] ?? ''); $ref = intval($data['ref_id'] ?? 0);
        $diffs = $d ? endo_final_diffs(endo_final_baseline($pdo, $src, $ref), $d) : [];
        $warn = [];
        if (!empty($data['policy_number']) && !empty($d['policy_num']) && endo_policy_key($d['policy_num']) !== endo_policy_key($data['policy_number']))
            $warn[] = 'شماره‌ی بیمه‌نامه‌ی این فایل (' . $d['policy_num'] . ') با بیمه‌نامه‌ی الحاقیه (' . $data['policy_number'] . ') یکی نیست.';
        $out(['ok' => true, 'token' => $tok, 'read_ok' => (bool)$d, 'read_error' => $d ? null : ($rd['error'] ?? 'خوانده نشد.'), 'data' => $d, 'diffs' => $diffs, 'warnings' => $warn]);
    }
    // وصل کردنِ بیمه‌نامه‌ی نهایی به الحاقیه‌ای که قبلاً ثبت شده
    if ($action === 'final_attach') {
        $tok = bin2hex(random_bytes(10));
        $p = $takePdf($_FILES['file'] ?? null, $finalPath($tok));
        if (!$p) $out(['ok' => false, 'error' => 'فایلِ بیمه‌نامه‌ی نهایی باید PDF باشد.']);
        $r = endo_attach_final($pdo, $siteRoot, intval($data['id'] ?? 0), $p, null, true, !empty($data['force']));
        if (is_file($p)) @unlink($p);
        $out($r);
    }

    // ---- صدورِ گروهیِ الحاقیه: اکسلِ بیمه‌گر و/یا PDFِ گروهی ----
    if ($action === 'bundle_analyze') {
        $tok = bin2hex(random_bytes(10));
        $dir = $batchDir($tok);
        @mkdir($dir, 0775, true);
        $excel = []; $pdfItems = []; $files = []; $pages = 0; $errs = [];
        $xf = $_FILES['excel'] ?? null;
        if ($xf && ($xf['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file($xf['tmp_name'])) {
            $ext = strtolower(pathinfo((string)$xf['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'csv'], true)) { endo_rrmdir($dir); $out(['ok' => false, 'error' => 'اکسل باید xlsx یا csv باشد.']); }
            $xp = $dir . '/list.' . $ext;
            move_uploaded_file($xf['tmp_name'], $xp);
            $excel = endo_excel_rows($xp, $ext);
            $files[] = (string)$xf['name'];
            if (!$excel) $errs[] = 'در اکسل ردیفِ الحاقیه پیدا نشد (ستون‌های «شماره بیمه‌نامه» و «شماره الحاقیه» لازم است).';
        }
        $pf = $_FILES['pdf'] ?? null;
        if ($pf && ($pf['error'] ?? 4) === UPLOAD_ERR_OK) {
            $pp = $takePdf($pf, $dir . '/all.pdf');
            if (!$pp) { endo_rrmdir($dir); $out(['ok' => false, 'error' => 'فایلِ الحاقیه‌های گروهی باید PDF باشد.']); }
            $files[] = (string)$pf['name'];
            $r = endo_bundle_read($pdo, $pp, $dir);
            if (empty($r['ok'])) $errs[] = 'فایلِ PDF: ' . ($r['error'] ?? 'خوانده نشد.');
            else { $pdfItems = (array)$r['items']; $pages = (int)$r['pages']; }
            @unlink($pp);
        }
        if (!$excel && !$pdfItems) { endo_rrmdir($dir); $out(['ok' => false, 'error' => $errs ? implode(' ', $errs) : 'اکسل یا فایلِ PDFِ الحاقیه‌ها را بدهید.']); }
        $items = endo_merge_sources($excel, $pdfItems);
        $dupQ = $pdo->prepare("SELECT id FROM policy_endorsements WHERE policy_key = ? AND endorse_no = ? LIMIT 1");
        foreach ($items as $i => &$it) {
            $it['i'] = $i;
            $it['matches'] = endo_find_policies($pdo, $it['policy_number']);
            $it['sel'] = $it['matches'] ? $it['matches'][0]['source'] . ':' . $it['matches'][0]['ref_id'] : 'X:0';
            $dupQ->execute([endo_policy_key($it['policy_number']), $it['endorse_no']]);
            $it['duplicate'] = (int)$dupQ->fetchColumn() ?: null;
            $it['missing'] = array_keys(array_filter(['kind' => !$it['kind'], 'gross' => $it['kind'] !== 'CORRECTIVE' && !(int)$it['gross'], 'policy_number' => endo_policy_key($it['policy_number']) === '']));
            $it['state'] = $it['duplicate'] ? 'dup' : ($it['missing'] ? 'incomplete' : ($it['matches'] ? 'ok' : 'nomatch'));
        }
        unset($it);
        @file_put_contents($dir . '/meta.json', json_encode(['items' => $items, 'files' => $files, 'user' => $actor['user_id'], 'at' => time()], JSON_UNESCAPED_UNICODE));
        $cnt = array_count_values(array_column($items, 'state'));
        $out(['ok' => true, 'token' => $tok, 'items' => $items, 'counts' => $cnt, 'pages' => $pages, 'excel_rows' => count($excel), 'pdf_items' => count($pdfItems),
              'warnings' => $errs, 'modes' => endo_modes_fa()]);
    }
    if ($action === 'bundle_commit') {
        $dir = $batchDir($data['token'] ?? '');
        $meta = $dir ? json_decode((string)@file_get_contents($dir . '/meta.json'), true) : null;
        if (!$meta) $out(['ok' => false, 'error' => 'این بسته پیدا نشد یا منقضی شده؛ دوباره فایل‌ها را بدهید.']);
        $defA = strtoupper((string)($data['mode_add'] ?? 'SPREAD')); $defR = strtoupper((string)($data['mode_ref'] ?? 'LAST'));
        $rowsIn = [];
        foreach ((array)($data['rows'] ?? []) as $r) $rowsIn[(int)($r['i'] ?? -1)] = $r;
        $pdo->prepare("INSERT INTO endorsement_batches (name, files, total, created_by) VALUES (?, ?, ?, ?)")
            ->execute([mb_substr(trim((string)($data['name'] ?? '')), 0, 250) ?: null, mb_substr(implode('، ', (array)$meta['files']), 0, 500), count($meta['items']), $actor['user_id']]);
        $batchId = (int)$pdo->lastInsertId();
        $results = []; $done = 0;
        foreach ($meta['items'] as $it) {
            $i = (int)$it['i'];
            $r = $rowsIn[$i] ?? null;
            if (!$r || empty($r['include'])) continue;
            $sel = (string)($r['sel'] ?? $it['sel']);
            [$src, $ref] = array_pad(explode(':', $sel), 2, 0);
            $kind = strtoupper((string)($r['kind'] ?? $it['kind']));
            $mode = strtoupper((string)($r['finance_mode'] ?? ''));
            if ($mode === '' || $mode === 'DEFAULT') $mode = $kind === 'ADDITIONAL' ? $defA : (in_array($kind, ['REFUND', 'CANCELLATION'], true) ? $defR : 'NONE');
            if ($kind === 'CORRECTIVE' || $src === 'X') $mode = 'NONE';
            $st = array_values(array_filter((array)$it['statement'], fn($x) => !empty($x['amount']) && !empty($x['due'])));
            $in = ['source' => $src, 'ref_id' => (int)$ref, 'policy_number' => $it['policy_number'], 'endorse_no' => $it['endorse_no'], 'kind' => $kind,
                   'issue_date' => $it['issue_date'], 'effect_date' => $it['effect_date'] ?: $it['issue_date'], 'new_end_date' => $it['new_end_date'],
                   'gross' => $it['gross'], 'net' => $it['net'], 'tax' => $it['tax'], 'central_no' => $it['central_no'], 'insurance_type' => $it['insurance_type'],
                   'reason' => $it['reason'], 'description' => $it['full_description'], 'finance_mode' => $mode,
                   'finance_opt' => ['count' => max(1, count($st)), 'first_due' => $st[0]['due'] ?? '', 'schedule' => $mode === 'SEPARATE' ? array_map(fn($x) => ['amount' => $x['amount'], 'due' => $x['due']], $st) : []],
                   'origin' => 'BATCH', 'batch_id' => $batchId, 'data' => ['changes' => $it['changes'], 'statement' => $it['statement'], 'excel_row' => $it['excel_row'], 'pages' => $it['pages']]];
            $file = !empty($it['file']) && is_file($dir . '/' . basename($it['file'])) ? $dir . '/' . basename($it['file']) : null;
            $res = endo_create($pdo, $siteRoot, $in, $file, $actor['user_id']);
            $row = ['i' => $i, 'policy_number' => $it['policy_number'], 'endorse_no' => $it['endorse_no'], 'kind' => $kind, 'ok' => !empty($res['ok']),
                    'error' => $res['error'] ?? null, 'id' => $res['id'] ?? null, 'file_path' => $res['file_path'] ?? null, 'source' => $src,
                    'finance_mode' => $mode, 'finance_fa' => !empty($res['ok']) ? endo_plan_text((array)$res['plan'], $mode) : null, 'final_premium' => $res['final_premium'] ?? null];
            if (!empty($res['ok'])) $done++;
            $results[] = $row;
        }
        $pdo->prepare("UPDATE endorsement_batches SET created_count = ? WHERE id = ?")->execute([$done, $batchId]);
        endo_rrmdir($dir);
        $out(['ok' => true, 'batch_id' => $batchId, 'done' => $done, 'results' => $results]);
    }

    // ---- بیمه‌نامه‌های نهایی (گروهی): یک PDF با چند بیمه‌نامه => هر کدام به الحاقیه‌ی همان بیمه‌نامه ----
    if ($action === 'final_bundle_analyze') {
        $tok = bin2hex(random_bytes(10));
        $dir = $batchDir($tok, 'g');
        @mkdir($dir, 0775, true);
        $pp = $takePdf($_FILES['pdf'] ?? null, $dir . '/all.pdf');
        if (!$pp) { endo_rrmdir($dir); $out(['ok' => false, 'error' => 'فایلِ بیمه‌نامه‌های نهایی باید PDF باشد.']); }
        $r = endo_py($pdo, 'policy_bundle.py', [$pp, $dir], 900);
        @unlink($pp);
        if (empty($r['ok'])) { endo_rrmdir($dir); $out(['ok' => false, 'error' => $r['error'] ?? 'فایل خوانده نشد.']); }
        $items = [];
        foreach ((array)$r['policies'] as $i => $p) {
            $d = (array)($p['data'] ?? []);
            $cands = endo_for_final($pdo, $d['policy_num'] ?? '');
            $e = $cands[0] ?? null;
            $diffs = $e ? endo_final_diffs(endo_final_baseline($pdo, $e['source'], (int)$e['ref_id'], (int)$e['id']), $d) : [];
            $items[] = ['i' => $i, 'page' => (int)$p['page'] + 1, 'end' => (int)($p['policy_end'] ?? $p['page']) + 1, 'file' => $p['file'], 'data' => $d,
                        'policy_number' => $d['policy_num'] ?? '', 'plate' => $d['plate'] ?? '', 'insured' => $d['insured_name'] ?? '',
                        'endo' => $e ? ['id' => (int)$e['id'], 'endorse_no' => $e['endorse_no'], 'kind_fa' => endo_kind_fa($e['kind']), 'issue_date' => $e['issue_date'],
                                        'has_final' => (bool)$e['final_file_path']] : null,
                        'candidates' => array_map(fn($c) => ['id' => (int)$c['id'], 'endorse_no' => $c['endorse_no'], 'kind_fa' => endo_kind_fa($c['kind']), 'has_final' => (bool)$c['final_file_path']], $cands),
                        'diffs' => $diffs, 'state' => !$e ? 'noendo' : ($e['final_file_path'] ? 'has_final' : 'ok')];
        }
        @file_put_contents($dir . '/meta.json', json_encode(['items' => $items], JSON_UNESCAPED_UNICODE));
        $out(['ok' => true, 'token' => $tok, 'items' => $items, 'pages' => (int)($r['pages'] ?? 0), 'counts' => array_count_values(array_column($items, 'state'))]);
    }
    if ($action === 'final_bundle_commit') {
        $dir = $batchDir($data['token'] ?? '', 'g');
        $meta = $dir ? json_decode((string)@file_get_contents($dir . '/meta.json'), true) : null;
        if (!$meta) $out(['ok' => false, 'error' => 'این بسته پیدا نشد یا منقضی شده؛ دوباره فایل را بدهید.']);
        $rowsIn = [];
        foreach ((array)($data['rows'] ?? []) as $r) $rowsIn[(int)($r['i'] ?? -1)] = $r;
        $results = []; $done = 0;
        foreach ($meta['items'] as $it) {
            $r = $rowsIn[(int)$it['i']] ?? null;
            if (!$r || empty($r['include']) || !(int)($r['endo_id'] ?? 0)) continue;
            $file = $dir . '/' . basename((string)$it['file']);
            $res = endo_attach_final($pdo, $siteRoot, (int)$r['endo_id'], $file, (array)$it['data'], true, false);
            if (!empty($res['ok'])) $done++;
            $results[] = ['i' => (int)$it['i'], 'policy_number' => $it['policy_number'], 'endo_id' => (int)$r['endo_id'], 'ok' => !empty($res['ok']),
                          'error' => $res['error'] ?? null, 'diffs' => $res['diffs'] ?? [], 'final_file_path' => $res['final_file_path'] ?? null];
        }
        endo_rrmdir($dir);
        $out(['ok' => true, 'done' => $done, 'results' => $results]);
    }

    if ($action === 'delete') {
        $out(endo_delete($pdo, intval($data['id'] ?? 0)));
    }

    // بستانکاریِ استردادِ نقدی پرداخت شد / نشد
    if ($action === 'credit_paid') {
        $pdo->prepare("UPDATE policy_endorsements SET credit_paid = ? WHERE id = ?")->execute([!empty($data['paid']) ? 1 : 0, intval($data['id'] ?? 0)]);
        $out(['ok' => true]);
    }

    if ($action === 'list' || $action === 'export') {
        $f = ['kind' => $data['kind'] ?? '', 'source' => $data['source'] ?? '', 'from' => $data['from'] ?? '', 'to' => $data['to'] ?? '',
              'company_id' => $data['company_id'] ?? '', 'q' => $data['q'] ?? ''];
        if (!empty($data['ref_source']) && !empty($data['ref_id'])) $f['ref'] = [$data['ref_source'], intval($data['ref_id'])];
        $res = endo_list($pdo, $f);
        if ($action === 'list') $out(['ok' => true] + $res + ['kinds' => endo_kinds(), 'modes' => endo_modes_fa()]);
        $headers = ['ردیف', 'منبع', 'بیمه‌گذار', 'پلاک/شاسی', 'شماره بیمه‌نامه', 'شماره الحاقیه', 'نوع', 'تاریخ صدور', 'تاریخ اثر', 'تاریخ پایان جدید',
                    'مبلغ (با مالیات)', 'اثر بر حق بیمه', 'حق بیمه اصلی', 'حق بیمه نهایی', 'اثر مالی', 'بستانکار', 'شرح'];
        $rows = [];
        foreach ($res['rows'] as $i => $r) {
            $rows[] = [$i + 1, $r['source_fa'], $r['insured'], $r['label'], $r['policy_number'], $r['endorse_no'], $r['kind_fa'], $r['issue_date'], $r['effect_date'],
                       $r['new_end_date'], $r['gross'], $r['signed'], $r['premium'], $r['final_premium'], $r['finance_fa'], $r['credit'], $r['description']];
        }
        fin_send_excel('الحاقیه‌ها', $headers, $rows, [10, 11, 12, 13, 15]);
        exit;
    }

    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[endorse_actions] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
