<?php
// فایل: api/legacy_actions.php
// «ورودِ بایگانی‌های قبلی»: اکسل‌های پاسارگاد (بیمه‌نامه‌ها و الحاقیه‌ها) => پیش‌نمایش => ثبت به‌عنوانِ صادرشده؛
// پوشه‌های فایل (مرتب/نامرتب) => اسکن => ادغام در بایگانی؛ فهرستِ دسته‌ها و برگرداندن.  منطق: api/_legacy.php و api/_legacy_files.php
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
require __DIR__ . '/_legacy_files.php';

$actor = require_admin_or_liaison();
if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'ورودِ بایگانی‌های قبلی فقط کارِ مدیر کل است.'], JSON_UNESCAPED_UNICODE); exit; }
@set_time_limit(600);
ignore_user_abort(true);
company_ensure_coverage_column($pdo);
company_ensure_issue_info_cols($pdo);
imp_ensure($pdo);
legacy_ensure($pdo);
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$data = $data + $_GET;
$action = $data['action'] ?? '';
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$siteRoot = dirname(__DIR__);

try {
    // ۱) اکسل‌ها (یک یا چند فایل؛ بیمه‌نامه‌ها و الحاقیه‌ها خودکار تشخیص داده می‌شوند) => پیش‌نمایش
    if ($action === 'preview') {
        $files = [];
        $tmp = legacy_tmp_dir($siteRoot);
        $up = $_FILES['files'] ?? null;
        if ($up && is_array($up['name'])) {
            foreach ($up['name'] as $k => $name) {
                if (($up['error'][$k] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$k])) continue;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) continue;
                $dest = $tmp . '/x_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($up['tmp_name'][$k], $dest)) $files[] = ['path' => $dest, 'name' => $name];
            }
        }
        if (!$files) $out(['ok' => false, 'error' => 'فایلِ اکسلی دریافت نشد (xlsx/xls/csv).']);
        $r = legacy_preview($pdo, $siteRoot, $files);
        foreach ($files as $f) @unlink($f['path']);
        $r['company_list'] = $pdo->query("SELECT id, name FROM companies WHERE COALESCE(is_import,0) = 0 ORDER BY name")->fetchAll();
        $r['default_quota'] = (int)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_intro_quota'")->fetchColumn() ?: 4);
        $out($r);
    }

    // ۲) ثبت
    if ($action === 'commit') {
        $out(legacy_commit($pdo, $siteRoot, (string)($data['token'] ?? ''), (array)($data['choice'] ?? []), $actor['user_id']));
    }

    if ($action === 'batches') $out(['ok' => true, 'batches' => legacy_batches($pdo)]);

    if ($action === 'rollback') $out(legacy_rollback($pdo, $siteRoot, intval($data['batch_id'] ?? 0)));

    // ---- پوشه‌های فایل ----
    if ($action === 'staging') $out(['ok' => true] + legacy_staging_info($siteRoot));

    // آپلودِ ZIP (برای حجم‌های کوچک‌تر؛ حجمِ بزرگ با File Manager/FTP در همان پوشه)
    if ($action === 'upload_zip') {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'فایل دریافت نشد (شاید از سقفِ آپلودِ سرور بزرگ‌تر است؛ با File Manager در پوشه‌ی «ورود بایگانی» بگذارید).']);
        $out(legacy_stage_zip($siteRoot, $f['tmp_name'], $f['name']));
    }

    // ۳) اسکنِ پوشه (تکه‌تکه، برای حجمِ زیاد) => پیدا کردنِ بیمه‌نامه‌ی هر پوشه/فایل
    if ($action === 'scan') {
        $out(legacy_scan($pdo, $siteRoot, (string)($data['scan'] ?? ''), (string)($data['sub'] ?? ''), intval($data['offset'] ?? 0), max(20, min(400, intval($data['limit'] ?? 150)))));
    }
    if ($action === 'scan_result') $out(legacy_scan_result($pdo, $siteRoot, (string)($data['scan'] ?? '')));

    // ۴) ادغام: گروه‌های انتخاب‌شده (یا «همه‌ی کامل‌ها»)؛ فایل‌های تکی با تعیینِ دستیِ بیمه‌نامه و نوع
    if ($action === 'merge') {
        $out(legacy_merge($pdo, $siteRoot, (string)($data['scan'] ?? ''), (array)($data['groups'] ?? []), !empty($data['all_complete']), (array)($data['manual'] ?? []), $actor['user_id']));
    }
    if ($action === 'find_policy') {
        $q = trim((string)($data['q'] ?? ''));
        $out(['ok' => true, 'matches' => $q !== '' ? legacy_find_targets($pdo, $q) : []]);
    }

    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    unset($GLOBALS['CIS_LEGACY']);
    error_log('[legacy_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
