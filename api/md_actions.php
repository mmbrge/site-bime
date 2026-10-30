<?php
// فایل: api/md_actions.php
// API «درمان و عمرِ گروهی» (api/_md.php): شرکت‌ها و قانونِ درمانِ هر کدام، قراردادهای سالانه با طرح‌ها و سقفِ پوشش‌ها، ورودِ سقف‌ها از اکسل.
session_start();
require_once __DIR__ . '/_dl_name.php';
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_md.php';
require_once __DIR__ . '/_md_bill.php';
require_once __DIR__ . '/_xlsx_writer.php';

$out = function ($a) { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$fail = function ($m) use ($out) { $out(['ok' => false, 'error' => $m]); };
if (empty($_SESSION['user_id']) || session_name() === 'bime_company_portal') { http_response_code(401); $fail('ابتدا وارد پنل شوید.'); }

function md_perm_set($pdo) {
    static $p = null;
    if ($p !== null) return $p;
    if (perm_real_role() === 'ADMIN') return $p = 'ALL';
    $c = perm_user_custom($pdo, $_SESSION['user_id']);
    return $p = ($c !== null ? $c : perm_role_defaults(perm_real_role()));
}
function md_can($pdo, $spec) { $p = md_perm_set($pdo); return $p === 'ALL' || perm_allows($p, $spec); }

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
const MD_PAGES = ['md-dash', 'md-contracts', 'md-companies', 'md-members', 'md-payroll', 'md-bills', 'md-pay', 'md-archive', 'md-settings'];
// فهرستِ دسترسیِ هر عمل در api/_perm.php («md_actions») است تا یک‌جا نگه داشته شود
$need = perm_api_map()['md_actions']['actions'] ?? [];
// بارگذاری در پوشه‌ی موقت (توکن)؛ پیوست بعد از «ذخیره‌ی قرارداد» به بایگانی می‌رود
function md_upload_tmp(array $exts, $maxMb) {
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('فایل دریافت نشد.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, $exts, true)) throw new RuntimeException('نوعِ فایل مجاز نیست (' . implode('، ', $exts) . ').');
    if ($f['size'] > $maxMb * 1048576) throw new RuntimeException('فایل بیش از ' . $maxMb . ' مگابایت است.');
    $head = (string)@file_get_contents($f['tmp_name'], false, null, 0, 8);
    $okSig = ['pdf' => ['%PDF'], 'jpg' => ["\xFF\xD8"], 'png' => ["\x89PNG"], 'webp' => ['RIFF'], 'xlsx' => ['PK'], 'docx' => ['PK'], 'xls' => ["\xD0\xCF"], 'doc' => ["\xD0\xCF"]];
    if (isset($okSig[$ext])) { $ok = false; foreach ($okSig[$ext] as $sg) if (strpos($head, $sg) === 0) $ok = true; if (!$ok) throw new RuntimeException('محتوای فایل با پسوندش جور نیست.'); }
    $tok = bin2hex(random_bytes(10)); $dir = md_tmp_dir();
    if (!move_uploaded_file($f['tmp_name'], "$dir/$tok.$ext")) throw new RuntimeException('ذخیره‌ی موقتِ فایل ممکن نشد.');
    file_put_contents("$dir/$tok.ext", $ext);
    return ['token' => $tok, 'ext' => $ext, 'name' => mb_substr(basename((string)$f['name']), 0, 200), 'path' => "$dir/$tok.$ext", 'size' => (int)$f['size']];
}
if (!isset($need[$action])) $fail('درخواست نامعتبر است.');
if (!md_can($pdo, $need[$action])) $fail('شما به این بخش دسترسی ندارید. از مدیر کل بخواهید دسترسی‌تان را تنظیم کند.');
md_ensure($pdo);
md_bill_ensure($pdo);

try {
    if ($action === 'bootstrap') {
        $perms = [];
        foreach (MD_PAGES as $p) foreach (['view', 'create', 'edit', 'delete', 'export'] as $op) if (md_can($pdo, "$p:$op")) $perms[$p][] = $op;
        $by = md_companies($pdo);
        $stats = ['contracts' => 0, 'health' => 0, 'life' => 0, 'companies_health' => 0, 'companies_life' => 0, 'companies' => count($by), 'inherited' => 0];
        foreach ($pdo->query("SELECT kind, COUNT(*) n FROM md_contracts WHERE is_deleted = 0 AND status = 'ACTIVE' GROUP BY kind") as $r) { $stats['contracts'] += (int)$r['n']; $stats[strtolower($r['kind'])] = (int)$r['n']; }
        foreach ($by as $id => $c) { $e = md_effective($by, $id); if ($e['health_mode'] !== 'none') $stats['companies_health']++; if ($e['life_mode'] !== 'none') $stats['companies_life']++; if ($e['inherited']) $stats['inherited']++; }
        $out(['ok' => true, 'perms' => $perms, 'kinds' => MD_KINDS, 'modes' => MD_MODES, 'rel' => MD_REL, 'premium_modes' => MD_PREMIUM_MODES, 'status' => MD_STATUS, 'stats' => $stats,
              'insurers' => array_values(array_unique(array_filter(array_merge(['پاسارگاد'], $pdo->query("SELECT DISTINCT insurer FROM md_contracts WHERE is_deleted = 0")->fetchAll(PDO::FETCH_COLUMN)))))]);
    }

    // ---------------- شرکت‌ها ----------------
    if ($action === 'companies') {
        $by = md_companies($pdo);
        $names = []; foreach ($pdo->query("SELECT id, kind, title, policy_no FROM md_contracts WHERE is_deleted = 0") as $c) $names[(int)$c['id']] = ['kind' => $c['kind'], 'label' => $c['title'] ?: $c['policy_no'] ?: ('قرارداد #' . $c['id'])];
        $plans = []; foreach ($pdo->query("SELECT id, name FROM md_plans WHERE is_deleted = 0") as $p) $plans[(int)$p['id']] = $p['name'];
        $rows = [];
        foreach ($by as $id => $c) {
            $e = md_effective($by, $id);
            $kids = 0; foreach ($by as $x) if ((int)$x['parent_id'] === $id) $kids++;
            $rows[] = ['id' => $id, 'name' => $c['name'], 'parent_id' => $c['parent_id'] ? (int)$c['parent_id'] : null, 'parent_name' => $c['parent_id'] && isset($by[(int)$c['parent_id']]) ? $by[(int)$c['parent_id']]['name'] : '',
                       'children' => $kids, 'own_rule' => (int)($c['own_rule'] ?? 0) === 1, 'configured' => $c['updated_at'] !== null,
                       'cfg' => ['health_mode' => $c['health_mode'] ?: 'none', 'health_contract_id' => $c['health_contract_id'] ? (int)$c['health_contract_id'] : null, 'health_plan_id' => $c['health_plan_id'] ? (int)$c['health_plan_id'] : null,
                                 'life_mode' => $c['life_mode'] ?: 'none', 'life_contract_id' => $c['life_contract_id'] ? (int)$c['life_contract_id'] : null, 'life_plan_id' => $c['life_plan_id'] ? (int)$c['life_plan_id'] : null,
                                 'portal_on' => (int)($c['portal_on'] ?? 0) === 1, 'note' => (string)$c['note'],
                                 'share_mode' => $c['share_mode'] ?: 'contract', 'share_main' => $c['share_main'] === null ? null : (int)$c['share_main'], 'share_family' => $c['share_family'] === null ? null : (int)$c['share_family'],
                                 'life_share_mode' => $c['life_share_mode'] ?: 'contract', 'life_share' => $c['life_share'] === null ? null : (int)$c['life_share'], 'rates' => json_decode((string)$c['rates'], true) ?: (object)[]],
                       'eff' => $e + ['source_name' => $by[$e['source_id']]['name'] ?? '', 'health_contract' => $names[$e['health_contract_id']]['label'] ?? '', 'life_contract' => $names[$e['life_contract_id']]['label'] ?? '',
                                     'health_plan' => $plans[$e['health_plan_id']] ?? '', 'life_plan' => $plans[$e['life_plan_id']] ?? '']];
        }
        $out(['ok' => true, 'companies' => $rows]);
    }
    if ($action === 'company_save') {
        md_company_save($pdo, (int)($data['company_id'] ?? 0), (array)($data['cfg'] ?? []));
        $out(['ok' => true]);
    }

    // ---------------- قراردادها ----------------
    if ($action === 'contracts') $out(['ok' => true, 'contracts' => md_contracts_list($pdo, $data)]);
    if ($action === 'contract_get') {
        $c = md_contract_get($pdo, (int)($data['id'] ?? 0));
        if (!$c) $fail('قرارداد پیدا نشد.');
        $out(['ok' => true, 'contract' => $c]);
    }
    if ($action === 'contract_save') {
        $in = is_array($data['contract'] ?? null) ? $data['contract'] : [];
        if (!empty($in['id']) && !md_can($pdo, 'md-contracts:edit')) $fail('اجازه‌ی ویرایشِ قرارداد را ندارید.');
        if (empty($in['id']) && !md_can($pdo, 'md-contracts:create')) $fail('اجازه‌ی ثبتِ قرارداد را ندارید.');
        $id = md_contract_save($pdo, $in);
        $out(['ok' => true, 'id' => $id, 'contract' => md_contract_get($pdo, $id)]);
    }
    if ($action === 'contract_delete') { md_contract_delete($pdo, (int)($data['id'] ?? 0)); $out(['ok' => true]); }

    // ---------------- سقفِ پوشش‌ها از اکسل: پیش‌نمایش (ستون‌ها) و خواندن با نگاشتِ ستون‌ها ----------------
    if ($action === 'cov_import_preview' || $action === 'cov_import_parse') {
        $tok = preg_replace('/[^a-f0-9]/', '', (string)($data['token'] ?? ''));
        $dir = sys_get_temp_dir() . '/md_cov'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if ($action === 'cov_import_preview') {
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $fail('فایل دریافت نشد.');
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) $fail('فقط اکسل (xlsx / xls) یا csv.');
            if ($f['size'] > 15 * 1048576) $fail('فایل بیش از ۱۵ مگابایت است.');
            $tok = bin2hex(random_bytes(10));
            if (!move_uploaded_file($f['tmp_name'], "$dir/$tok.$ext")) $fail('ذخیره‌ی موقتِ فایل ممکن نشد.');
            file_put_contents("$dir/$tok.ext", $ext);
        }
        $ext = (string)@file_get_contents("$dir/$tok.ext");
        $path = "$dir/$tok.$ext";
        if ($tok === '' || !is_file($path)) $fail('فایل پیدا نشد؛ دوباره بارگذاری کنید.');
        $sheets = xlsx_read_sheets($path, $ext);
        if (!$sheets) $fail('اکسل خوانده نشد.');
        $names = array_keys($sheets);
        $sheet = in_array($data['sheet'] ?? '', $names, true) ? $data['sheet'] : $names[0];
        $rows = $sheets[$sheet];
        $det = md_cov_detect($rows);
        if ($action === 'cov_import_preview') $out(['ok' => true, 'token' => $tok, 'sheets' => $names, 'sheet' => $sheet, 'detect' => $det, 'sample' => array_slice($rows, 0, 25)]);
        $map = ['header_row' => (int)($data['map']['header_row'] ?? $det['header_row'])];
        foreach (['name', 'cap', 'franchise', 'plan', 'note'] as $k) { $v = $data['map'][$k] ?? null; $map[$k] = ($v === '' || $v === null) ? null : (int)$v; }
        if ($map['name'] === null) $fail('ستونِ «نامِ پوشش» را انتخاب کنید.');
        $list = md_cov_rows($rows, $map);
        if (!$list) $fail('ردیفی برای ورود پیدا نشد.');
        $out(['ok' => true, 'rows' => $list]);
    }

    // ---------------- کتابخانه‌ی نامِ پوشش‌ها ----------------
    if ($action === 'cov_lib') $out(['ok' => true, 'items' => $pdo->query("SELECT name FROM md_cov_lib ORDER BY sort, id")->fetchAll(PDO::FETCH_COLUMN)]);
    if ($action === 'cov_lib_save') {
        $items = array_values(array_unique(array_filter(array_map(function ($v) { return mb_substr(trim((string)$v), 0, 250); }, (array)($data['items'] ?? [])), 'strlen')));
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM md_cov_lib");
        $st = $pdo->prepare("INSERT INTO md_cov_lib (name, sort) VALUES (?, ?)");
        foreach ($items as $i => $n) $st->execute([$n, $i]);
        $pdo->commit();
        md_log($pdo, 'COVLIB_SAVE', 'cfg', count($items) . ' پوشش');
        $out(['ok' => true]);
    }
    // ---------------- پیوست‌ها و خواندنِ نامه‌ی بیمه‌گر ----------------
    if ($action === 'file_tmp') {
        $u = md_upload_tmp(['pdf', 'jpg', 'png', 'webp', 'xlsx', 'xls', 'docx', 'doc'], 25);
        $out(['ok' => true, 'token' => $u['token'], 'name' => $u['name'], 'size' => $u['size']]);
    }
    if ($action === 'cov_ai_read') {
        @set_time_limit(240);
        $u = md_upload_tmp(['pdf', 'jpg', 'png', 'webp'], 15);
        $r = md_ai_cov_read($pdo, $u['path'], $u['ext']);
        md_log($pdo, 'COV_AI_READ', 'cov', $u['name'] . ' · ' . count($r['coverages']) . ' ردیف');
        $out(['ok' => true, 'token' => $u['token'], 'name' => $u['name'], 'read' => $r]);
    }
    if ($action === 'file_get') {
        $f = md_file_path($pdo, (int)($data['id'] ?? 0));
        if (!$f) { http_response_code(404); $fail('فایل پیدا نشد.'); }
        $ext = strtolower(pathinfo($f['abs'], PATHINFO_EXTENSION));
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        $fn = basename($f['abs']);
        header('Content-Disposition: ' . (isset($types[$ext]) && empty($data['dl']) ? 'inline' : 'attachment') . "; filename=\"file.$ext\"; filename*=UTF-8''" . rawurlencode($fn));
        header('Content-Length: ' . filesize($f['abs']));
        readfile($f['abs']); exit;
    }
    if ($action === 'file_delete') {
        $f = md_file_path($pdo, (int)($data['id'] ?? 0));
        $id = (int)($data['id'] ?? 0);
        $pdo->prepare("UPDATE md_files SET is_deleted = 1 WHERE id = ?")->execute([$id]);
        if ($f) @unlink($f['abs']);
        md_log($pdo, 'FILE_DELETE', 'ct:' . ($f['row']['contract_id'] ?? 0), $f['row']['name'] ?? ('#' . $id));
        $out(['ok' => true]);
    }
    // ======================= بیمه‌شدگان =======================
    $sendFile = function ($abs, $name, $inline = false) use ($fail) {
        if (!$abs || !is_file($abs)) { http_response_code(404); $fail('فایل پیدا نشد.'); }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream')); header('X-Content-Type-Options: nosniff');
        $isView = $inline && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
        header('Content-Disposition: ' . ($isView ? 'inline' : 'attachment') . "; filename=\"file.$ext\"; filename*=UTF-8''" . rawurlencode(dl_name($name ?: basename($abs))));
        header('Content-Length: ' . filesize($abs)); readfile($abs); exit;
    };
    $sendBytes = function ($bytes, $name, $inline) {
        header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"file.pdf\"; filename*=UTF-8''" . rawurlencode(dl_name($name)));
        header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
    };
    $period = function ($k = 'period') use ($data, $fail) { $p = (string)($data[$k] ?? ''); if (!md_period_ok($p)) $fail('ماه را انتخاب کنید.'); return $p; };
    if ($action === 'members') $out(['ok' => true] + md_members_list($pdo, $data));
    if ($action === 'members_export') {
        $data['limit'] = 5000; $r = md_members_list($pdo, $data);
        $rows = array_map(function ($m) { return [$m['company'], MD_KINDS[$m['kind']], $m['nid'], $m['name'], $m['rel_fa'], $m['main_nid'], $m['plan'], $m['father'], $m['birth_j'], $m['personnel_no'], $m['mobile'], $m['start_j'] ?: $m['start_p'], $m['end_j'] ?: (string)$m['end_p']]; }, $r['items']);
        xlsx_send(xlsx_build(['شرکت', 'نوع', 'کد ملی', 'نام', 'نسبت', 'کد ملی اصلی', 'طرح', 'نام پدر', 'تاریخ تولد', 'کد پرسنلی', 'موبایل', 'شروع', 'پایان'], $rows, 'بیمه‌شدگان'), 'بیمه‌شدگان درمان و عمر ' . str_replace('/', '-', $r['period']));
        exit;
    }
    if ($action === 'member_save') {
        $in = (array)($data['member'] ?? []); $id = (int)($in['id'] ?? 0);
        $nid = md_nid($in['nid'] ?? ''); if ($id === 0 && $nid === '') $fail('کدِ ملی را وارد کنید.');
        $rel = max(1, min(5, (int)($in['rel'] ?? 1))); $main = $rel === 1 ? $nid : md_nid($in['main_nid'] ?? '');
        if ($id === 0 && $rel > 1 && $main === '') $fail('کدِ ملیِ بیمه‌شده‌ی اصلی را وارد کنید.');
        $fromP = md_period_ok($in['from_p'] ?? '') ? $in['from_p'] : md_period_now();
        $fields = ['first_name' => md_norm($in['first_name'] ?? ''), 'last_name' => md_norm($in['last_name'] ?? ''), 'father' => md_norm($in['father'] ?? ''),
                   'gender' => ($in['gender'] ?? '') === '' ? null : (int)$in['gender'], 'birth_j' => md_jdate_loose($in['birth_j'] ?? ''), 'id_no' => preg_replace('/\D/', '', md_digits((string)($in['id_no'] ?? ''))),
                   'mobile' => preg_replace('/\D/', '', md_digits((string)($in['mobile'] ?? ''))), 'sheba' => md_sheba($in['sheba'] ?? ''), 'bank' => md_norm($in['bank'] ?? ''), 'personnel_no' => md_norm($in['personnel_no'] ?? ''),
                   'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 400)];
        $bill = ['plan_id' => (int)($in['plan_id'] ?? 0) ?: null, 'rel' => $rel, 'main_nid' => $main ?: null, 'life_amount' => trim((string)($in['life_amount'] ?? '')) === '' ? null : md_money($in['life_amount'])];
        if ($id) {
            if ($bill['main_nid'] === null) unset($bill['main_nid']);
            $nid2 = md_member_set($pdo, $id, $fields + $bill, $fromP);
            md_log($pdo, 'MEMBER_EDIT', 'mem:' . $id, $nid . ' از ' . md_period_fa($fromP));
            $out(['ok' => true, 'id' => $nid2]);
        }
        $cid = (int)($in['company_id'] ?? 0); $kind = isset(MD_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'HEALTH';
        if (!$cid) $fail('شرکت را انتخاب کنید.');
        $ex = md_active($pdo, $cid, $kind, $fromP);
        if (isset($ex[$nid])) $fail('این کدِ ملی در این شرکت از قبل فعال است.');
        $else = md_active_elsewhere($pdo, $nid, $kind, $fromP, $cid);
        $startJ = md_jdate_loose($in['start_j'] ?? '') ?: null;
        $pdo->beginTransaction();
        if ($else) {
            md_member_end($pdo, null, (int)$else['id'], $fromP, $startJ);
            $pdo->prepare("INSERT INTO md_transfers (kind, nid, name, from_company_id, to_company_id, period, date_j, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$kind, $nid, trim($fields['first_name'] . ' ' . $fields['last_name']), (int)$else['company_id'], $cid, $fromP, $startJ]);
        }
        $mid = md_member_insert($pdo, null, $cid, $kind, $fields + $bill + ['nid' => $nid, 'main_nid' => $main, 'start_j' => $startJ], $fromP);
        $pdo->commit();
        if ($rel === 1) { try { md_person_link($pdo, $nid, trim($fields['first_name'] . ' ' . $fields['last_name']), $cid, $startJ ?: $fromP . '/01', $fields['mobile'], $fields['personnel_no']); } catch (Throwable $e) {} }
        md_log($pdo, 'MEMBER_ADD', 'mem:' . $mid, $nid . ($else ? ' (انتقال)' : ''));
        $out(['ok' => true, 'id' => $mid, 'transfer' => (bool)$else]);
    }
    if ($action === 'member_end') {
        $id = (int)($data['id'] ?? 0); $p = $period('end_p');
        $withFamily = !empty($data['family']);
        $st = $pdo->prepare("SELECT * FROM md_members WHERE id = ?"); $st->execute([$id]); $m = $st->fetch();
        if (!$m) $fail('عضو پیدا نشد.');
        $ids = [$id];
        if ($withFamily && (int)$m['rel'] === 1) { $q = $pdo->prepare("SELECT id FROM md_members WHERE company_id = ? AND kind = ? AND main_nid = ? AND id <> ? AND (end_p IS NULL OR end_p > ?)"); $q->execute([(int)$m['company_id'], $m['kind'], $m['nid'], $id, $p]); $ids = array_merge($ids, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))); }
        foreach ($ids as $x) md_member_end($pdo, null, $x, $p, md_jdate_loose($data['end_j'] ?? '') ?: null);
        md_log($pdo, 'MEMBER_END', 'mem:' . $id, $m['nid'] . ' از ' . md_period_fa($p) . ' (' . count($ids) . ' نفر)');
        $out(['ok' => true, 'n' => count($ids)]);
    }
    if ($action === 'person_history') $out(['ok' => true] + md_person_history($pdo, (string)($data['nid'] ?? '')));
    if ($action === 'person_open') {
        $nid = md_nid($data['nid'] ?? ''); if ($nid === '') $fail('کدِ ملی نامعتبر است.');
        $p = md_person_find($pdo, $nid);
        if (!$p) {
            if (!md_can($pdo, 'md-members:edit') && !md_can($pdo, 'md-payroll:edit')) $fail('این شخص هنوز پرونده ندارد.');
            $q = $pdo->prepare("SELECT * FROM md_members WHERE nid = ? ORDER BY (end_p IS NULL) DESC, start_p DESC LIMIT 1"); $q->execute([$nid]); $m = $q->fetch();
            $pdo->prepare("INSERT INTO persons (national_code, full_name, company_id, mobile_number, personnel_code) VALUES (?, ?, ?, ?, ?)")
                ->execute([$nid, $m ? trim($m['first_name'] . ' ' . $m['last_name']) : null, $m ? (int)$m['company_id'] : null, $m && $m['mobile'] ? $m['mobile'] : null, $m && $m['personnel_no'] ? $m['personnel_no'] : null]);
            $out(['ok' => true, 'person_id' => (int)$pdo->lastInsertId(), 'created' => true]);
        }
        $out(['ok' => true, 'person_id' => (int)$p['id']]);
    }
    // ======================= لیست‌ها =======================
    if ($action === 'lists') $out(['ok' => true, 'lists' => md_lists_list($pdo, $data)]);
    if ($action === 'list_upload') {
        $u = md_upload_tmp(['xlsx', 'xls', 'csv'], 20);
        try { $id = md_list_create($pdo, (int)($data['company_id'] ?? 0), (string)($data['kind'] ?? 'HEALTH'), (string)($data['type'] ?? ''), $period(), $u['path'], $u['name'], $u['ext'], $data['sheet'] ?? null); }
        finally { @unlink($u['path']); @unlink(md_tmp_dir() . '/' . $u['token'] . '.ext'); }
        $out(['ok' => true, 'id' => $id, 'list' => md_list_out($pdo, md_list_get($pdo, $id), true)]);
    }
    if ($action === 'list_get') { $L = md_list_get($pdo, (int)($data['id'] ?? 0)); if (!$L) $fail('لیست پیدا نشد.'); $out(['ok' => true, 'list' => md_list_out($pdo, $L, true)]); }
    if ($action === 'list_apply') { $n = md_list_apply($pdo, (int)($data['id'] ?? 0)); $out(['ok' => true, 'counts' => $n, 'list' => md_list_out($pdo, md_list_get($pdo, (int)$data['id']))]); }
    if ($action === 'list_void') { md_list_void($pdo, (int)($data['id'] ?? 0), (string)($data['reason'] ?? '')); $out(['ok' => true]); }
    if ($action === 'list_file') { $L = md_list_get($pdo, (int)($data['id'] ?? 0)); $sendFile($L ? md_abs_path($L['file_rel']) : null, $L['file_name'] ?? ''); }
    if ($action === 'pas_export') {
        $r = md_pas_export($pdo, (int)($data['id'] ?? 0), in_array($data['part'] ?? '', ['add', 'remove', 'bank'], true) ? $data['part'] : 'add', (string)($data['fmt'] ?? 'xlsx'));
        if ($r['fmt'] === 'xls') { require_once __DIR__ . '/_xls_writer.php'; xls_send($r['path'], $r['name']); } else xlsx_send($r['path'], $r['name']);
        exit;
    }
    if ($action === 'pas_mark') {
        $col = ($data['part'] ?? '') === 'remove' ? 'pas_rem' : 'pas_add'; $v = in_array($data['status'] ?? '', ['NONE', 'EXPORTED', 'DONE'], true) ? $data['status'] : 'DONE';
        $pdo->prepare("UPDATE md_lists SET $col = ? WHERE id = ?")->execute([$v, (int)($data['id'] ?? 0)]);
        md_log($pdo, 'PAS_MARK', 'list:' . (int)$data['id'], $col . ' = ' . $v);
        $out(['ok' => true]);
    }
    if ($action === 'pas_overview') $out(['ok' => true, 'rows' => md_pas_overview($pdo, $period(), (string)($data['kind'] ?? ''))]);
    // ======================= سند حقوق =======================
    if ($action === 'payroll_preview') {
        $u = md_upload_tmp(['xlsx', 'xls'], 25);
        $out(['ok' => true, 'token' => $u['token'], 'name' => $u['name'], 'preview' => md_payroll_preview($pdo, $period(), $u['path'], $u['ext'])]);
    }
    if ($action === 'payroll_commit') {
        $tok = preg_replace('/[^a-f0-9]/', '', (string)($data['token'] ?? '')); $t = md_tmp_dir();
        $ext = preg_replace('/[^a-z0-9]/', '', (string)@file_get_contents("$t/$tok.ext"));
        if ($tok === '' || !is_file("$t/$tok.$ext")) $fail('فایل پیدا نشد؛ دوباره بارگذاری کنید.');
        $res = md_payroll_commit($pdo, $period(), "$t/$tok.$ext", $ext, mb_substr((string)($data['name'] ?? 'سند حقوق'), 0, 200), (array)($data['map'] ?? []));
        @unlink("$t/$tok.$ext"); @unlink("$t/$tok.ext");
        $out(['ok' => true, 'result' => $res]);
    }
    if ($action === 'payrolls') {
        $names = md_company_names($pdo);
        $rows = $pdo->query("SELECT * FROM md_payrolls ORDER BY id DESC LIMIT 200")->fetchAll();
        $out(['ok' => true, 'items' => array_map(function ($p) { return ['id' => (int)$p['id'], 'period' => $p['period'], 'period_fa' => md_period_fa($p['period']), 'file_name' => (string)$p['file_name'], 'result' => json_decode((string)$p['result'], true), 'created_name' => (string)$p['created_name'], 'created_at' => (string)$p['created_at']]; }, $rows)]);
    }
    if ($action === 'payroll_file') { $st = $pdo->prepare("SELECT * FROM md_payrolls WHERE id = ?"); $st->execute([(int)($data['id'] ?? 0)]); $p = $st->fetch(); $sendFile($p ? md_abs_path($p['file_rel']) : null, $p['file_name'] ?? ''); }
    if ($action === 'recons') $out(['ok' => true, 'items' => md_recon_list($pdo, $data)]);
    if ($action === 'recon_get') { $st = $pdo->prepare("SELECT * FROM md_recon WHERE id = ?"); $st->execute([(int)($data['id'] ?? 0)]); $R = $st->fetch(); if (!$R) $fail('پیدا نشد.'); $out(['ok' => true, 'recon' => md_recon_out($pdo, $R, true)]); }
    if ($action === 'recon_recompute') { md_recon_compute($pdo, (int)($data['id'] ?? 0)); $out(['ok' => true]); }
    if ($action === 'recon_resolve') { md_recon_resolve($pdo, (int)($data['id'] ?? 0), array_map('strval', (array)($data['keys'] ?? [])), (string)($data['res'] ?? 'OK'), (string)($data['note'] ?? '')); $out(['ok' => true]); }
    if ($action === 'recon_fix') { md_recon_fix($pdo, (int)($data['id'] ?? 0), (string)($data['key'] ?? ''), (string)($data['what'] ?? '')); $out(['ok' => true]); }
    if ($action === 'recon_confirm') { md_recon_confirm($pdo, (int)($data['id'] ?? 0), !empty($data['accept_rest']), (string)($data['note'] ?? '')); $out(['ok' => true]); }
    if ($action === 'recon_nopayroll') { md_recon_nopayroll($pdo, (int)($data['company_id'] ?? 0), $period(), (string)($data['note'] ?? '')); $out(['ok' => true]); }
    if ($action === 'recon_void') { md_recon_void($pdo, (int)($data['id'] ?? 0), (string)($data['reason'] ?? '')); $out(['ok' => true]); }
    if ($action === 'recon_file') { $st = $pdo->prepare("SELECT * FROM md_recon WHERE id = ?"); $st->execute([(int)($data['id'] ?? 0)]); $R = $st->fetch(); $sendFile($R ? md_abs_path($R['file_rel']) : null, $R ? basename((string)$R['file_rel']) : ''); }
    // ======================= صورت‌حساب‌ها =======================
    if ($action === 'bill_board') $out(['ok' => true, 'rows' => md_bill_board($pdo, $period())]);
    if ($action === 'bills') $out(['ok' => true, 'items' => md_bills_list($pdo, $data)]);
    if ($action === 'bill_create') {
        $made = []; $errs = [];
        foreach ((array)($data['items'] ?? [['company_id' => $data['company_id'] ?? 0, 'kind' => $data['kind'] ?? 'HEALTH']]) as $it) {
            try { $made[] = md_bill_create($pdo, (int)($it['company_id'] ?? 0), (string)($it['kind'] ?? 'HEALTH'), $period(), $data['issue_j'] ?? null, (string)($data['note'] ?? '')); }
            catch (RuntimeException $e) { $errs[] = (md_company_names($pdo)[(int)($it['company_id'] ?? 0)] ?? '') . ': ' . $e->getMessage(); }
        }
        if (!$made && $errs) $fail(implode("\n", $errs));
        $out(['ok' => true, 'ids' => $made, 'errors' => $errs]);
    }
    if ($action === 'bill_void') { md_bill_void($pdo, (int)($data['id'] ?? 0), (string)($data['reason'] ?? '')); $out(['ok' => true]); }
    if ($action === 'bill_pdf') { $r = md_bill_pdf($pdo, (int)($data['id'] ?? 0)); $sendBytes($r['bytes'], $r['name'], empty($data['dl'])); }
    if ($action === 'cbills') $out(['ok' => true, 'items' => md_cbills_list($pdo, $data)]);
    if ($action === 'cbill_create') { $id = md_cbill_create($pdo, (string)($data['kind'] ?? 'HEALTH'), $period(), (array)($data['company_ids'] ?? []), (string)($data['title'] ?? ''), (int)($data['to_company_id'] ?? 0)); $out(['ok' => true, 'id' => $id]); }
    if ($action === 'cbill_pdf') { $r = md_cbill_pdf($pdo, (int)($data['id'] ?? 0)); $sendBytes($r['bytes'], $r['name'], empty($data['dl'])); }
    if ($action === 'cbill_void') {
        if (trim((string)($data['reason'] ?? '')) === '') $fail('دلیلِ ابطال را بنویسید.');
        $pdo->prepare("UPDATE md_cbills SET status = 'VOID', void_reason = ? WHERE id = ?")->execute([mb_substr(trim($data['reason']), 0, 400), (int)($data['id'] ?? 0)]);
        md_log($pdo, 'CBILL_VOID', 'cbill:' . (int)$data['id'], (string)$data['reason']); $out(['ok' => true]);
    }
    // ======================= دریافت‌ها =======================
    if ($action === 'payments') $out(['ok' => true, 'items' => md_payments_list($pdo, $data)]);
    if ($action === 'payment_save') { $id = md_payment_save($pdo, (array)($data['payment'] ?? [])); $out(['ok' => true, 'id' => $id]); }
    if ($action === 'payment_void') { md_payment_void($pdo, (int)($data['id'] ?? 0), (string)($data['reason'] ?? '')); $out(['ok' => true]); }
    if ($action === 'payment_file') { $st = $pdo->prepare("SELECT file_rel FROM md_payments WHERE id = ?"); $st->execute([(int)($data['id'] ?? 0)]); $r = $st->fetchColumn(); $sendFile($r ? md_abs_path($r) : null, $r ? basename($r) : '', true); }
    if ($action === 'settles') $out(['ok' => true] + md_settle_list($pdo));
    if ($action === 'settle_save') { $id = md_settle_save($pdo, (array)($data['settle'] ?? [])); $out(['ok' => true, 'id' => $id]); }
    if ($action === 'settle_void') { $pdo->prepare("UPDATE md_settle SET status = 'VOID' WHERE id = ?")->execute([(int)($data['id'] ?? 0)]); md_log($pdo, 'SETTLE_VOID', 'settle:' . (int)$data['id'], (string)($data['reason'] ?? '')); $out(['ok' => true]); }
    if ($action === 'settle_file') { $st = $pdo->prepare("SELECT file_rel FROM md_settle WHERE id = ?"); $st->execute([(int)($data['id'] ?? 0)]); $r = $st->fetchColumn(); $sendFile($r ? md_abs_path($r) : null, $r ? basename($r) : '', true); }
    if ($action === 'inbox') $out(['ok' => true, 'items' => md_inbox_list($pdo, (string)($data['status'] ?? 'NEW'))]);
    if ($action === 'inbox_file') { $st = $pdo->prepare("SELECT file_rel FROM md_inbox WHERE id = ?"); $st->execute([(int)($data['id'] ?? 0)]); $r = $st->fetchColumn(); $sendFile($r ? md_abs_path($r) : null, $r ? basename($r) : '', true); }
    if ($action === 'inbox_upload') {
        $u = md_upload_tmp(['pdf', 'jpg', 'png', 'webp'], 15);
        $id = md_inbox_add($pdo, $u['path'], ['source' => 'UPLOAD', 'chat_title' => 'بارگذاریِ دستی', 'sender' => md_user_name(), 'caption' => $u['name']]);
        @unlink(md_tmp_dir() . '/' . $u['token'] . '.ext');
        $out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'inbox_ignore') { $pdo->prepare("UPDATE md_inbox SET status = ?, note = ? WHERE id = ?")->execute([empty($data['restore']) ? 'IGNORED' : 'NEW', mb_substr((string)($data['note'] ?? ''), 0, 300) ?: null, (int)($data['id'] ?? 0)]); $out(['ok' => true]); }
    // ======================= بایگانی و خروجی =======================
    if ($action === 'archive_ls') $out(['ok' => true, 'items' => md_archive_ls((string)($data['path'] ?? '')), 'path' => trim((string)($data['path'] ?? ''), '/')]);
    if ($action === 'archive_get') { $rel = trim(str_replace('\\', '/', (string)($data['path'] ?? '')), '/'); if ($rel === '' || strpos($rel, '..') !== false) $fail('مسیرِ نامعتبر.'); $sendFile(md_archive_root() . '/' . $rel, basename($rel), !empty($data['view'])); }
    if ($action === 'export_catalog') $out(['ok' => true, 'catalog' => md_export_catalog()]);
    if ($action === 'export') {
        $r = md_export_rows($pdo, $data);
        $label = ['members' => 'بیمه‌شدگان', 'bills' => 'صورت‌حساب‌ها', 'payments' => 'دریافت‌ها'][$r['type']];
        xlsx_send(xlsx_build($r['headers'], $r['rows'], mb_substr($label, 0, 31), $r['numeric']), 'درمان و عمر - ' . $label . ' ' . str_replace('/', '-', $r['from']) . ' تا ' . str_replace('/', '-', $r['to']));
        exit;
    }
    if ($action === 'headcount') $out(['ok' => true] + md_headcount_table($pdo, $period('from'), $period('to'), ($data['kind'] ?? '') === 'LIFE' ? 'LIFE' : 'HEALTH'));
    if ($action === 'company_book') {
        $p = $period(); $cid = (int)($data['company_id'] ?? 0);
        $path = md_company_book($pdo, $p, $cid);
        // نسخه‌ای در بایگانی
        $names = md_company_names($pdo);
        $dir = $cid ? md_company_dir($names[$cid] ?? '', $p) : md_archive_root() . '/_اکسل کلی';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @copy($path, md_unique($dir, ($cid ? 'اکسل ' . ($names[$cid] ?? '') : 'اکسل کلی همه شرکت‌ها') . ' - ' . str_replace('/', '-', $p), 'xlsx'));
        xlsx_send($path, ($cid ? 'درمان و عمر ' . ($names[$cid] ?? '') : 'درمان و عمر - همه شرکت‌ها') . ' ' . str_replace('/', '-', $p));
        exit;
    }
    if ($action === 'dashboard') $out(['ok' => true] + md_dashboard($pdo, (string)($data['period'] ?? '')));
    // ======================= تنظیمات =======================
    if ($action === 'settings') {
        $S = md_settings($pdo);
        $out(['ok' => true, 'settings' => array_merge($S, ['letterhead' => (bool)$S['letterhead'], 'signature' => (bool)$S['signature']]), 'list_types' => MD_LIST_TYPES, 'rel_codes' => MD_REL_CODES,
              'recon_types' => MD_RECON_TYPES, 'recon_status' => MD_RECON_STATUS, 'payees' => MD_PAYEES, 'pas_status' => MD_PAS_STATUS, 'period' => md_period_now(), 'today' => md_today_j()]);
    }
    if ($action === 'settings_save') { md_settings_save($pdo, (array)($data['settings'] ?? [])); md_log($pdo, 'SETTINGS', 'cfg', ''); $out(['ok' => true]); }
    if ($action === 'settings_upload') {
        $which = ($data['which'] ?? '') === 'signature' ? 'signature' : 'letterhead';
        $S = md_settings($pdo);
        if (!empty($data['remove'])) { $S[$which] = ''; md_settings_store($pdo, $S); $out(['ok' => true]); }
        $u = md_upload_tmp(['jpg', 'png'], 8);
        $dir = md_archive_root() . '/_تنظیمات'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $dest = $dir . '/' . ($which === 'signature' ? 'امضا' : 'سربرگ') . '.' . $u['ext'];
        foreach (['jpg', 'png'] as $e) @unlink($dir . '/' . ($which === 'signature' ? 'امضا' : 'سربرگ') . '.' . $e);
        if (!@rename($u['path'], $dest)) $fail('ذخیره ممکن نشد.');
        @unlink(md_tmp_dir() . '/' . $u['token'] . '.ext');
        $S[$which] = md_rel_path($dest); md_settings_store($pdo, $S);
        $out(['ok' => true]);
    }
    if ($action === 'settings_image') { $S = md_settings($pdo); $w = ($data['which'] ?? '') === 'signature' ? 'signature' : 'letterhead'; $sendFile($S[$w] ? md_abs_path($S[$w]) : null, $w, true); }
    if ($action === 'log') {
        $st = $pdo->query("SELECT at, user_name, action, ref, note FROM md_log ORDER BY id DESC LIMIT 200");
        $out(['ok' => true, 'items' => $st->fetchAll()]);
    }
} catch (RuntimeException $e) { $fail($e->getMessage()); }
catch (Throwable $e) { error_log('[md_actions ' . $action . '] ' . $e->getMessage() . ' @' . $e->getLine()); $fail('خطای سرور؛ دوباره امتحان کنید.'); }
$fail('درخواست نامعتبر است.');
