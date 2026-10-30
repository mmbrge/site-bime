<?php
// فایل: api/md_actions.php
// API «درمان و عمرِ گروهی» (api/_md.php): شرکت‌ها و قانونِ درمانِ هر کدام، قراردادهای سالانه با طرح‌ها و سقفِ پوشش‌ها، ورودِ سقف‌ها از اکسل.
session_start();
require_once __DIR__ . '/_dl_name.php';
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_md.php';
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
const MD_PAGES = ['md-dash', 'md-contracts', 'md-companies', 'md-settings'];
$need = [
    'bootstrap' => 'md-dash:view|md-contracts:view|md-companies:view|md-settings:view',
    'companies' => 'md-companies:view|md-contracts:view|md-dash:view', 'company_save' => 'md-companies:edit',
    'contracts' => 'md-contracts:view|md-companies:view|md-dash:view', 'contract_get' => 'md-contracts:view|md-companies:view',
    'contract_save' => 'md-contracts:create|md-contracts:edit', 'contract_delete' => 'md-contracts:delete',
    'cov_import_preview' => 'md-contracts:create|md-contracts:edit', 'cov_import_parse' => 'md-contracts:create|md-contracts:edit',
    'cov_lib' => 'md-contracts:view|md-settings:view', 'cov_lib_save' => 'md-settings:edit',
    'log' => 'md-dash:view|md-settings:view',
];
if (!isset($need[$action])) $fail('درخواست نامعتبر است.');
if (!md_can($pdo, $need[$action])) $fail('شما به این بخش دسترسی ندارید. از مدیر کل بخواهید دسترسی‌تان را تنظیم کند.');
md_ensure($pdo);

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
                                 'portal_on' => (int)($c['portal_on'] ?? 0) === 1, 'note' => (string)$c['note']],
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
    if ($action === 'log') {
        $st = $pdo->query("SELECT at, user_name, action, ref, note FROM md_log ORDER BY id DESC LIMIT 200");
        $out(['ok' => true, 'items' => $st->fetchAll()]);
    }
} catch (RuntimeException $e) { $fail($e->getMessage()); }
catch (Throwable $e) { error_log('[md_actions ' . $action . '] ' . $e->getMessage() . ' @' . $e->getLine()); $fail('خطای سرور؛ دوباره امتحان کنید.'); }
$fail('درخواست نامعتبر است.');
