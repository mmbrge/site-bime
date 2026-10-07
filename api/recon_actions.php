<?php
// فایل: api/recon_actions.php
// «مغایرت‌گیری با پاسارگاد»: بارگذاریِ خروجیِ دوره‌ای => فهرستِ منطبق/مغایر/فقط پاسارگاد/فقط سایت => تصمیم و اصلاح.  منطق: api/_recon.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require __DIR__ . '/finance_core.php';
require __DIR__ . '/_import_archive.php';
require_once __DIR__ . '/_company_issue.php';
require __DIR__ . '/_endorse.php';
require __DIR__ . '/_legacy.php';
require __DIR__ . '/_recon.php';

$actor = require_admin_or_liaison();
@set_time_limit(600);
company_ensure_coverage_column($pdo);
company_ensure_issue_info_cols($pdo);
imp_ensure($pdo);
legacy_ensure($pdo);
recon_ensure($pdo);
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$data = $data + $_GET;
$action = $data['action'] ?? '';
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$siteRoot = dirname(__DIR__);
$isAdmin = ($actor['role'] ?? '') === 'ADMIN';

try {
    if ($action === 'run') {
        $files = [];
        $tmp = legacy_tmp_dir($siteRoot);
        $up = $_FILES['files'] ?? null;
        if ($up && is_array($up['name'])) {
            foreach ($up['name'] as $k => $name) {
                if (($up['error'][$k] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$k])) continue;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) continue;
                $dest = $tmp . '/r_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($up['tmp_name'][$k], $dest)) $files[] = ['path' => $dest, 'name' => $name];
            }
        }
        if (!$files) $out(['ok' => false, 'error' => 'فایلِ اکسلی دریافت نشد (xlsx/xls/csv).']);
        $r = recon_run($pdo, $siteRoot, $files, trim((string)($data['title'] ?? '')), $actor['user_id']);
        foreach ($files as $f) @unlink($f['path']);
        $out($r);
    }
    if ($action === 'runs') $out(['ok' => true, 'runs' => recon_runs($pdo)]);
    if ($action === 'items' || $action === 'export') {
        $runId = intval($data['run_id'] ?? 0);
        $f = ['category' => in_array($data['category'] ?? '', ['MATCH', 'DIFF', 'ONLY_P', 'ONLY_S'], true) ? $data['category'] : '',
              'status' => in_array($data['status'] ?? '', ['OPEN', 'RESOLVED'], true) ? $data['status'] : '', 'field' => (string)($data['field'] ?? ''), 'q' => (string)($data['q'] ?? '')];
        $res = recon_items($pdo, $runId, $f);
        if ($action === 'items') $out(['ok' => true] + $res);
        $choiceFa = ['SITE' => 'سایت درست است', 'PASARGAD' => 'پاسارگاد اعمال شد', 'CUSTOM' => 'اصلاحِ دستی'];
        $rows = []; $n = 0;
        foreach ($res['rows'] as $r) {
            $base = [$r['category_fa'], $r['policy_number'], $r['type_fa'], $r['issue_date'], $r['label'], $r['insured'], $r['status'] === 'OPEN' ? 'باز' : 'بررسی‌شده', $r['note']];
            if (!$r['diffs']) { $rows[] = array_merge([++$n], $base, ['', '', '', '']); continue; }
            foreach ($r['diffs'] as $d) $rows[] = array_merge([++$n], $base, [$d['label'], (string)$d['site'], (string)$d['pas'], $choiceFa[$d['res'] ?? ''] ?? '']);
        }
        fin_send_excel('مغایرت پاسارگاد', ['ردیف', 'وضعیت', 'شماره بیمه‌نامه', 'نوع', 'تاریخ صدور', 'پلاک/شاسی', 'بیمه‌گذار', 'بررسی', 'توضیح', 'فیلد', 'سایت', 'پاسارگاد', 'تصمیم'], $rows, []);
        exit;
    }
    if ($action === 'resolve') {
        $choice = in_array($data['choice'] ?? '', ['SITE', 'PASARGAD', 'CUSTOM'], true) ? $data['choice'] : '';
        if ($choice === '') $out(['ok' => false, 'error' => 'تصمیم نامعتبر.']);
        $fields = (array)($data['fields'] ?? [$data['field'] ?? '']);
        $last = ['ok' => false, 'error' => 'فیلدی انتخاب نشده.'];
        foreach ($fields as $fld) {
            if ($fld === '') continue;
            $last = recon_resolve($pdo, $siteRoot, intval($data['item_id'] ?? 0), (string)$fld, $choice, (string)($data['value'] ?? ''), (string)($data['note'] ?? ''), $actor['user_id']);
            if (empty($last['ok'])) break;
        }
        $out($last);
    }
    if ($action === 'mark') $out(recon_mark($pdo, intval($data['item_id'] ?? 0), (string)($data['status'] ?? 'RESOLVED'), (string)($data['note'] ?? ''), $actor['user_id']));
    if ($action === 'import') {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'ورود به سایت فقط کارِ مدیر کل است.']);
        $out(recon_import($pdo, $siteRoot, (array)($data['item_ids'] ?? []), ['finance' => $data['finance'] ?? 'PREMIUM', 'quota' => $data['quota'] ?? 0], $actor['user_id']));
    }
    if ($action === 'delete_run') {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
        $out(recon_delete_run($pdo, intval($data['run_id'] ?? 0)));
    }
    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    unset($GLOBALS['CIS_LEGACY']);
    error_log('[recon_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
