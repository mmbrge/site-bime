<?php
// فایل: api/import_actions.php
// «بایگانی وارداتی»: ورودِ اکسلِ بیمه‌گر، فهرستِ ردیف‌ها، ویرایشِ اطلاعات، بازدید لازم/نالازم، حذف و خروجیِ اکسل.
// بارگذاریِ مدرک، ثبتِ صدور و صدورِ گروهی با همان اکشن‌های company_actions.php انجام می‌شود.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require __DIR__ . '/finance_core.php';
require __DIR__ . '/_import_archive.php';

$actor = require_admin_or_liaison();
if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'دسترسی به «بایگانی وارداتی» ندارید.'], JSON_UNESCAPED_UNICODE); exit; }
$schemaProblem = company_schema_problem($pdo);
if ($schemaProblem) { echo json_encode(['ok' => false, 'error' => $schemaProblem], JSON_UNESCAPED_UNICODE); exit; }
company_ensure_coverage_column($pdo);
company_ensure_issue_info_cols($pdo);
imp_ensure($pdo);
@mkdir(imp_archive_root(dirname(__DIR__)), 0775, true);

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$data = $data + $_GET;
$action = $data['action'] ?? '';
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$tmpRoot = dirname(__DIR__) . '/tmp_ocr/imports';

try {
    // ---- فیلترها و آمار ----
    if ($action === 'meta') {
        $batches = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM company_request_plates p JOIN company_requests r ON r.id = p.request_id WHERE r.import_batch_id = b.id) AS n
                                  FROM import_batches b ORDER BY b.id DESC LIMIT 200")->fetchAll();
        foreach ($batches as &$b) $b['created_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($b['created_at']));
        unset($b);
        $insured = $pdo->query("SELECT c.id, c.name, COUNT(p.id) AS n FROM companies c JOIN company_requests r ON r.company_id = c.id JOIN company_request_plates p ON p.request_id = r.id
                                 WHERE c.is_import = 1 AND r.is_import = 1 GROUP BY c.id ORDER BY c.name")->fetchAll();
        $months = [];
        foreach ($pdo->query("SELECT import_month AS m, COUNT(*) AS n FROM company_requests WHERE is_import = 1 AND import_month IS NOT NULL GROUP BY import_month ORDER BY import_month DESC") as $m)
            $months[] = ['month' => $m['m'], 'label' => imp_month_fa($m['m']), 'n' => (int)$m['n']];
        $out(['ok' => true, 'batches' => $batches, 'insured' => $insured, 'months' => $months, 'doc_types' => company_doc_types()]);
    }

    if ($action === 'list') {
        $r = imp_list($pdo, $data);
        $out(['ok' => true] + $r);
    }

    // ---- پیش‌نمایشِ اکسل (چیزی ثبت نمی‌شود) ----
    if ($action === 'preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'فایلِ اکسل دریافت نشد.']);
        $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) $out(['ok' => false, 'error' => 'فایل باید اکسل (xlsx) یا CSV باشد.']);
        if (($f['size'] ?? 0) > 25 * 1048576) $out(['ok' => false, 'error' => 'حجمِ فایل بیش از ۲۵ مگابایت است.']);
        @mkdir($tmpRoot, 0775, true);
        foreach (glob($tmpRoot . '/*') ?: [] as $old) if (is_file($old) && filemtime($old) < time() - 86400) @unlink($old);
        $token = bin2hex(random_bytes(8));
        $src = $tmpRoot . '/' . $token . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $src)) $out(['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.']);
        $res = imp_parse_excel($pdo, $src);
        @unlink($src);
        if (isset($res['error'])) $out(['ok' => false, 'error' => $res['error']]);
        file_put_contents($tmpRoot . '/' . $token . '.json', json_encode(['name' => $f['name'], 'rows' => $res['rows'], 'user' => $actor['user_id'] ?? null], JSON_UNESCAPED_UNICODE));
        $ok = count(array_filter($res['rows'], fn($r) => $r['ok']));
        $out(['ok' => true, 'token' => $token, 'name' => $f['name'], 'rows' => $res['rows'], 'counts' => ['total' => count($res['rows']), 'ok' => $ok, 'bad' => count($res['rows']) - $ok]]);
    }

    if ($action === 'commit') {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($data['token'] ?? ''));
        $file = $tmpRoot . '/' . $token . '.json';
        $meta = $token && is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!$meta) $out(['ok' => false, 'error' => 'پیش‌نمایش منقضی شده؛ فایل را دوباره انتخاب کنید.']);
        $res = imp_commit($pdo, $meta['rows'], $meta['name'] ?? '', ['group_by' => $data['group_by'] ?? 'insured', 'skip' => (array)($data['skip'] ?? []),
                          'lines' => isset($data['lines']) ? (array)$data['lines'] : null], intval($actor['user_id'] ?? 0));
        if (!empty($res['ok'])) @unlink($file);
        $out($res);
    }

    // ---- ویرایشِ اطلاعاتِ یک ردیف (پیش از صدور) ----
    if ($action === 'update_row') {
        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT crp.* FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ? AND cr.is_import = 1");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) $out(['ok' => false, 'error' => 'ردیف پیدا نشد.']);
        if ($row['status'] === 'ISSUED') $out(['ok' => false, 'error' => 'این ردیف صادر و بایگانی شده و دیگر ویرایش نمی‌شود.']);
        $s = fn($k) => trim(p2e_digits((string)($data[$k] ?? '')));
        $type = in_array($data['insurance_type'] ?? '', ['BODY', 'THIRDPARTY'], true) ? $data['insurance_type'] : $row['insurance_type'];
        $issue = imp_jdate($data['import_issue_date'] ?? '') ?: null;
        $expJ = imp_jdate($data['expiry'] ?? '');
        $pf = json_decode((string)$row['import_json'], true) ?: [];
        foreach (['policy_num' => 'import_policy_number', 'issue_date' => 'import_issue_date', 'premium' => 'premium', 'insured_name' => 'insured_name',
                  'national_id' => 'national_id', 'phone' => 'phone', 'address' => 'address', 'prev_insurer' => 'prev_insurer', 'prev_policy' => 'prev_policy',
                  'engine_no' => 'engine_no', 'model_year' => 'model_year', 'color' => 'color', 'usage' => 'usage', 'owner_name' => 'owner_name'] as $pk => $dk) {
            if (!array_key_exists($dk, $data)) continue;
            $v = $dk === 'import_issue_date' ? (string)$issue : ($dk === 'premium' ? (string)(imp_int($data[$dk]) ?: '') : trim((string)$data[$dk]));
            if ($v === '') unset($pf[$pk]); else $pf[$pk] = $v;
        }
        if ($expJ) $pf['prev_expiry'] = $expJ;
        $pf['ins_type'] = insurance_type_fa($type);
        $pdo->prepare("UPDATE company_request_plates SET plate_p1 = ?, plate_p2 = ?, plate_letter = ?, plate_p4 = ?, chassis_no = ?, engine_no = ?, insurance_type = ?,
                          car_name = ?, car_value = ?, liability_limit = ?, has_prev_body = ?, skip_health_inspection = ?, expiry_date = COALESCE(?, expiry_date),
                          import_policy_number = ?, import_issue_date = ?, import_json = ?, row_note = ? WHERE id = ?")
            ->execute([$s('plate_p1') ?: null, $s('plate_p2') ?: null, trim((string)($data['plate_letter'] ?? '')) ?: null, $s('plate_p4') ?: null,
                       strtoupper($s('chassis_no')) ?: null, strtoupper($s('engine_no')) ?: null, $type, trim((string)($data['car_name'] ?? '')) ?: null,
                       imp_int($data['car_value'] ?? '') ?: null, imp_int($data['liability_limit'] ?? '') ?: null,
                       $type === 'BODY' && in_array($data['has_prev_body'] ?? '', ['YES', 'NO'], true) ? $data['has_prev_body'] : ($type === 'BODY' ? $row['has_prev_body'] : null),
                       $type === 'BODY' && !empty($data['skip_health_inspection']) ? 1 : 0, $expJ ? fin_jalali_to_date($expJ) : null,
                       $s('import_policy_number') ?: null, $issue, json_encode($pf, JSON_UNESCAPED_UNICODE), trim((string)($data['row_note'] ?? '')) ?: null, $id]);
        // اطلاعاتِ صدور هم هم‌گام شود
        $info = issue_info_decode($row['issue_info']);
        foreach (['insured_name' => 'insured_name', 'national_id' => 'insured_national_id', 'phone' => 'insured_phone', 'address' => 'insured_address',
                  'prev_insurer' => 'prev_insurer', 'prev_policy' => 'prev_policy_number', 'model_year' => 'car_model_year', 'color' => 'car_color',
                  'usage' => 'car_usage', 'owner_name' => 'owner_name'] as $pk => $ik) { if (isset($pf[$pk])) $info[$ik] = $pf[$pk]; else unset($info[$ik]); }
        if ($s('chassis_no') !== '') { $info['chassis_no'] = strtoupper($s('chassis_no')); $info['vin'] = $info['chassis_no']; }
        if ($s('engine_no') !== '') $info['engine_no'] = strtoupper($s('engine_no'));
        $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([$info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null, $id]);
        ensure_plate_folder($pdo, dirname(__DIR__), $id);
        $status = company_sync_plate_status($pdo, $id);
        $out(['ok' => true, 'status' => $status]);
    }

    // ---- «نیاز به بازدید ندارد» برای چند ردیفِ بدنه با هم ----
    if ($action === 'bulk_skip') {
        $ids = array_values(array_filter(array_map('intval', (array)($data['ids'] ?? []))));
        if (!$ids) $out(['ok' => false, 'error' => 'ردیفی انتخاب نشده.']);
        $on = !empty($data['on']) ? 1 : 0;
        $in = implode(',', $ids);
        $pdo->exec("UPDATE company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id SET crp.skip_health_inspection = $on
                     WHERE crp.id IN ($in) AND cr.is_import = 1 AND crp.insurance_type = 'BODY' AND crp.status <> 'ISSUED'");
        foreach ($ids as $id) company_sync_plate_status($pdo, $id);
        $out(['ok' => true]);
    }

    // ---- حذفِ ردیف‌های صادرنشده (مدارکِ بارگذاری‌شده هم پاک می‌شوند) ----
    if ($action === 'delete_rows') {
        $ids = array_values(array_filter(array_map('intval', (array)($data['ids'] ?? []))));
        if (!$ids) $out(['ok' => false, 'error' => 'ردیفی انتخاب نشده.']);
        $siteRoot = dirname(__DIR__);
        $in = implode(',', $ids);
        $rows = $pdo->query("SELECT crp.id, crp.request_id, crp.folder_path, crp.status FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                              WHERE crp.id IN ($in) AND cr.is_import = 1")->fetchAll();
        $done = 0; $skipped = 0; $reqs = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'ISSUED') { $skipped++; continue; }
            $d = $pdo->prepare("SELECT file_path FROM company_documents WHERE plate_id = ?");
            $d->execute([$r['id']]);
            foreach ($d->fetchAll(PDO::FETCH_COLUMN) as $fp) { $abs = $siteRoot . '/' . $fp; if (is_file($abs)) @unlink($abs); elseif (is_dir($abs)) cbundle_rrmdir_safe($abs); }
            $pdo->prepare("DELETE FROM company_documents WHERE plate_id = ?")->execute([$r['id']]);
            $pdo->prepare("DELETE FROM company_request_plates WHERE id = ?")->execute([$r['id']]);
            if ($r['folder_path'] && is_dir($r['folder_path'])) cleanup_empty_dirs_upward($r['folder_path'], imp_archive_root($siteRoot));
            $reqs[(int)$r['request_id']] = true; $done++;
        }
        // درخواستِ خالی‌شده هم برود
        foreach (array_keys($reqs) as $rid) {
            $n = $pdo->prepare("SELECT COUNT(*) FROM company_request_plates WHERE request_id = ?");
            $n->execute([$rid]);
            if (!(int)$n->fetchColumn()) $pdo->prepare("DELETE FROM company_requests WHERE id = ? AND is_import = 1")->execute([$rid]);
        }
        $out(['ok' => true, 'deleted' => $done, 'skipped' => $skipped]);
    }

    // ---- خروجیِ اکسل: وضعیت و کمبودهای هر ردیف ----
    if ($action === 'export') {
        $r = imp_list($pdo, $data);
        require_once __DIR__ . '/_xlsx_writer.php';
        $headers = ['ردیف', 'بیمه‌گذار', 'ماه صدور', 'نوع', 'پلاک / شاسی', 'شماره شاسی', 'خودرو', 'شماره بیمه‌نامه', 'تاریخ صدور', 'حق بیمه (ریال)',
                    'بازدید', 'مدارکِ موجود', 'کمبودِ مدارک', 'کمبودِ اطلاعات', 'وضعیت', 'پوشه'];
        $xr = [];
        foreach ($r['rows'] as $i => $x) {
            $have = array_values(array_unique(array_map(fn($d) => $d['label'], $x['docs'])));
            $xr[] = [$i + 1, $x['insured_name'], fa_digits($x['month_fa']), $x['insurance_type_fa'], $x['label'], $x['chassis_no'] ?? '', $x['car_name'] ?? '',
                     $x['import_policy_number'] ?? '', fa_digits($x['import_issue_date'] ?? ''), intval($x['pf']['premium'] ?? 0) ?: '',
                     $x['insurance_type'] === 'BODY' ? ($x['skip_health_inspection'] ? 'لازم نیست' : 'لازم') : '—',
                     implode('، ', $have), implode('، ', array_values((array)$x['missing_docs'])), implode('، ', array_values((array)$x['missing_info'])), $x['status_fa'], $x['folder_path'] ?? ''];
        }
        $path = xlsx_build($headers, $xr, 'بایگانی وارداتی', [0, 9]);
        header_remove('Content-Type');
        xlsx_send($path, 'بایگانی وارداتی ' . fa_digits(str_replace('/', '-', jalali_from_gregorian_ts_dotted(time()))) . '.xlsx');
        exit;
    }

    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[import_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}

function cbundle_rrmdir_safe($dir) {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) { $p = $dir . '/' . $f; is_dir($p) ? cbundle_rrmdir_safe($p) : @unlink($p); }
    @rmdir($dir);
}
