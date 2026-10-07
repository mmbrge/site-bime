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
if (in_array($action, ['read', 'preview', 'create', 'delete', 'credit_paid'], true) && !$canWrite) $out(['ok' => false, 'error' => 'ثبتِ الحاقیه فقط کارِ مدیر کل است.']);

$siteRoot = dirname(__DIR__);
$tmpRoot = $siteRoot . '/tmp_ocr/endorse';
if (!is_dir($tmpRoot)) { @mkdir($tmpRoot, 0775, true); @file_put_contents($tmpRoot . '/.htaccess', "Require all denied\nDeny from all\n"); }
foreach ((array)glob($tmpRoot . '/e_*.pdf') as $old) if (@filemtime($old) < time() - 86400) @unlink($old);
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
