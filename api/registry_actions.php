<?php
// فایل: api/registry_actions.php
// «پرونده‌ی خودروها و اشخاص» در فرم‌های ثبتِ درخواست (پنلِ ما): جستجوی خودرو/شخصِ موجود و انتقالِ مدارکش به درخواستِ تازه
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php';
perm_gate($pdo, __FILE__);
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require __DIR__ . '/finance_core.php';
require_once __DIR__ . '/_import_archive.php';
require_once __DIR__ . '/_bordereau.php';
require_once __DIR__ . '/_vehicles.php';
require_once __DIR__ . '/_registry_link.php';
if (is_file(__DIR__ . '/_inquiry.php')) require_once __DIR__ . '/_inquiry.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['ADMIN', 'COMPANY_LIAISON'], true)) $out(['ok' => false, 'error' => 'دسترسی ندارید.']);
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST;
$action = (string)($data['action'] ?? '');
$siteRoot = dirname(__DIR__);

try {
    if ($action === 'lookup') {
        $res = ['ok' => true, 'vehicle' => null, 'person' => null, 'suggest' => []];
        if (array_filter([$data['plate'] ?? '', $data['p1'] ?? '', $data['p2'] ?? '', $data['vin'] ?? '', $data['engine'] ?? ''], function ($v) { return trim((string)$v) !== ''; })) {
            company_ensure_issue_info_cols($pdo); imp_ensure($pdo); brd_ensure($pdo);
            $res['vehicle'] = reg_lookup_vehicle($pdo, $data, $role === 'ADMIN' ? [] : ['company_ids' => array_map('intval', $pdo->query("SELECT id FROM companies")->fetchAll(PDO::FETCH_COLUMN))]);
        }
        if (trim((string)($data['nid'] ?? '')) !== '' || trim((string)($data['name'] ?? '')) !== '') {
            if ($role !== 'ADMIN') $out($res);
            $p = reg_lookup_person($pdo, $data['nid'] ?? '', $data['name'] ?? '');
            $res['person'] = $p['person']; $res['suggest'] = $p['suggest'];
        }
        $out($res);
    }
    if ($action === 'attach') {
        if ($role !== 'ADMIN') $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
        $docs = is_array($data['docs'] ?? null) ? $data['docs'] : (json_decode((string)($data['docs'] ?? '[]'), true) ?: []);
        $plateId = intval($data['plate_id'] ?? 0); $caseId = intval($data['case_id'] ?? 0);
        if (!$plateId && !$caseId) $out(['ok' => false, 'error' => 'ردیف مشخص نیست.']);
        $done = 0; $errs = [];
        foreach (array_slice($docs, 0, 12) as $d) {
            $r = $plateId ? reg_attach_to_plate($pdo, $siteRoot, $plateId, (string)($d['src'] ?? ''), intval($d['id'] ?? 0), (string)($d['type'] ?? ''), null, intval($_SESSION['user_id']))
                          : reg_attach_to_case($pdo, $siteRoot, $caseId, (string)($d['src'] ?? ''), intval($d['id'] ?? 0), (string)($d['type'] ?? ''), intval($_SESSION['user_id']));
            if (!empty($r['ok'])) $done++; else $errs[] = $r['error'] ?? 'خطا';
        }
        if ($plateId && $done) company_sync_plate_status($pdo, $plateId);
        $out(['ok' => true, 'attached' => $done, 'errors' => $errs]);
    }
    $out(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[registry_actions] ' . $action . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
