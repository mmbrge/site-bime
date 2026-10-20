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
$isAdmin = recon_sees_all();   // نقشِ واقعی (نه نقشِ موقتِ دسترسیِ سفارشی)
$uid = intval($actor['user_id']);
// هر کاربر فقط مغایرت‌گیری‌های خودش را می‌بیند؛ مدیر کل همه را
$need = function ($runId) use ($pdo, $uid, $out) {
    $r = recon_run_for($pdo, $runId, $uid);
    if (!$r) $out(['ok' => false, 'error' => 'این مغایرت‌گیری پیدا نشد یا مالِ شما نیست.']);
    return $r;
};

try {
    if ($action === 'run') {
        $files = [];
        $tmp = legacy_tmp_dir($siteRoot);
        $up = $_FILES['files'] ?? null;
        if ($up && is_array($up['name'])) {
            foreach ($up['name'] as $k => $name) {
                if (($up['error'][$k] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$k])) continue;
                $ext = upload_ext($name);
                if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) continue;
                $dest = $tmp . '/r_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($up['tmp_name'][$k], $dest)) $files[] = ['path' => $dest, 'name' => $name];
            }
        }
        if (!$files) $out(['ok' => false, 'error' => 'فایلِ اکسلی دریافت نشد (xlsx/xls/csv).']);
        // mode: '' (اگر فایل مالِ مغایرت‌گیریِ قبلیِ بی‌فایل باشد می‌پرسد) | new (تازه) | attach (وصل به run_id، تصمیم‌ها می‌ماند)
        $mode = (string)($data['mode'] ?? '');
        if ($mode === 'attach') {
            $need(intval($data['run_id'] ?? 0));
            $xf = array_values(array_filter($files, fn($f) => upload_ext($f['name']) === 'xlsx'));
            $r = $xf ? recon_attach_src($pdo, $siteRoot, intval($data['run_id'] ?? 0), $xf) : ['ok' => false, 'error' => 'برای خروجیِ اکسلِ بیمه‌گر فایل باید xlsx باشد.'];
        } else $r = recon_run($pdo, $siteRoot, $files, trim((string)($data['title'] ?? '')), $actor['user_id'], $mode === 'new' ? 'new' : '');
        foreach ($files as $f) @unlink($f['path']);
        $out($r);
    }
    if ($action === 'attach_src') {
        $files = [];
        $tmp = legacy_tmp_dir($siteRoot);
        $up = $_FILES['files'] ?? null;
        if ($up && is_array($up['name'])) {
            foreach ($up['name'] as $k => $name) {
                if (($up['error'][$k] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$k])) continue;
                if (upload_ext($name) !== 'xlsx') continue;
                $dest = $tmp . '/r_' . bin2hex(random_bytes(6)) . '.xlsx';
                if (move_uploaded_file($up['tmp_name'][$k], $dest)) $files[] = ['path' => $dest, 'name' => $name];
            }
        }
        if (!$files) $out(['ok' => false, 'error' => 'فایلِ xlsx دریافت نشد (خروجیِ اصلاح‌شده فقط برای xlsx ساخته می‌شود).']);
        $need(intval($data['run_id'] ?? 0));
        $r = recon_attach_src($pdo, $siteRoot, intval($data['run_id'] ?? 0), $files);
        foreach ($files as $f) @unlink($f['path']);
        $out($r);
    }
    if ($action === 'runs') $out(['ok' => true] + recon_runs($pdo, $data, $uid));
    if ($action === 'items' || $action === 'export') {
        $runId = intval($data['run_id'] ?? 0);
        $run = $need($runId);
        // مغایرت‌گیری‌های قبلی هم پوشه‌ی بایگانی می‌گیرند (بعد از پاسخ)
        if ($action === 'items' && (!$run['archive_dir'] || $run['archive_dirty'])) recon_archive_later($pdo, $siteRoot, $runId);
        $f = ['category' => in_array($data['category'] ?? '', ['MATCH', 'DIFF', 'ONLY_P', 'ONLY_S'], true) ? $data['category'] : '',
              'status' => in_array($data['status'] ?? '', ['OPEN', 'RESOLVED'], true) ? $data['status'] : '', 'field' => (string)($data['field'] ?? ''), 'q' => (string)($data['q'] ?? '')];
        if ($action === 'items') $out(['ok' => true, 'archive' => $run['archive_dir'] ? recon_archive_rel($run['archive_dir']) : ''] + recon_items($pdo, $runId, $f));
        [$hdr, $rows] = recon_export_table($pdo, $runId, $f);
        fin_send_excel(name_join(['فهرستِ مغایرت‌ها', trim((string)$run['title']) ?: 'مغایرت پاسارگاد']), $hdr, $rows, []);
        exit;
    }
    if ($action === 'resolve') {
        $choice = in_array($data['choice'] ?? '', ['SITE', 'PASARGAD', 'CUSTOM'], true) ? $data['choice'] : '';
        if ($choice === '') $out(['ok' => false, 'error' => 'تصمیم نامعتبر.']);
        $rid = recon_item_run_id($pdo, intval($data['item_id'] ?? 0));
        $need($rid);
        $fields = (array)($data['fields'] ?? [$data['field'] ?? '']);
        $last = ['ok' => false, 'error' => 'فیلدی انتخاب نشده.'];
        foreach ($fields as $fld) {
            if ($fld === '') continue;
            $last = recon_resolve($pdo, $siteRoot, intval($data['item_id'] ?? 0), (string)$fld, $choice, (string)($data['value'] ?? ''), (string)($data['note'] ?? ''), $actor['user_id']);
            if (empty($last['ok'])) break;
        }
        recon_archive_later($pdo, $siteRoot, $rid);
        $out($last);
    }
    if ($action === 'mark') {
        $rid = recon_item_run_id($pdo, intval($data['item_id'] ?? 0));
        $need($rid);
        $r = recon_mark($pdo, intval($data['item_id'] ?? 0), (string)($data['status'] ?? 'RESOLVED'), (string)($data['note'] ?? ''), $actor['user_id']);
        recon_archive_later($pdo, $siteRoot, $rid);
        $out($r);
    }
    if ($action === 'import') {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'ورود به سایت فقط کارِ مدیر کل است.']);
        $ids = (array)($data['item_ids'] ?? []);
        $r = recon_import($pdo, $siteRoot, $ids, ['finance' => $data['finance'] ?? 'PREMIUM', 'quota' => $data['quota'] ?? 0], $actor['user_id']);
        foreach (array_unique(array_map(fn($i) => recon_item_run_id($pdo, $i), $ids)) as $rid) recon_archive_later($pdo, $siteRoot, $rid);
        $out($r);
    }
    if ($action === 'delete_run') {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
        $need(intval($data['run_id'] ?? 0));
        $out(recon_delete_run($pdo, intval($data['run_id'] ?? 0), $siteRoot));
    }
    // خروجیِ اصلاح‌شده‌ی اکسلِ بیمه‌گر: corrected_preview = فهرستِ تغییرات؛ corrected = دانلود (نام: «نوع - عنوانِ مغایرت‌گیری»)
    if ($action === 'corrected_preview' || $action === 'corrected') $need(intval($data['run_id'] ?? 0));
    if ($action === 'corrected_preview') $out(recon_corrected($pdo, $siteRoot, intval($data['run_id'] ?? 0), intval($data['idx'] ?? 0), true));
    if ($action === 'corrected') {
        $r = recon_corrected($pdo, $siteRoot, intval($data['run_id'] ?? 0), intval($data['idx'] ?? 0), false);
        if (empty($r['ok'])) $out($r);
        require_once __DIR__ . '/_dl_name.php';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"recon.xlsx\"; filename*=UTF-8''" . rawurlencode(dl_name(str_replace(['/', '\\', '"'], ['-', '-', ''], $r['name']))));
        header('Content-Length: ' . filesize($r['path']));
        readfile($r['path']);
        @unlink($r['path']);
        exit;
    }
    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    unset($GLOBALS['CIS_LEGACY']);
    error_log('[recon_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
