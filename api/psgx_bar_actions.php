<?php
// فایل: api/psgx_bar_actions.php
// نوارِ بالای «سایتِ بیمه‌گر» (psgx/bar.js داخلِ صفحه‌های خودِ سامانه‌ی پاسارگاد از راهِ پروکسی):
//   ctx: قراردادها، تنظیماتِ مالی، پیش‌فرض‌های پیش‌نویس، ردیفِ سایتِ وصل به همین پیش‌نویس
//   row_search / row_fill: پیدا کردنِ ردیفِ درخواست و خانه‌های فرمِ همین صفحه از روی آن (هیچ درخواستی به پاسارگاد نمی‌رود)
//   fin_save: قراردادِ پیش‌فرضِ هر حالت، حسابِ پاسارگادِ هر قرارداد، ثبتِ نهاییِ خودکار
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_psg_tools.php';
if (is_file(__DIR__ . '/finance_core.php')) require_once __DIR__ . '/finance_core.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
if (($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'دسترسی به «سایتِ بیمه‌گر» ندارید.']);
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
$actor = ['id' => intval($_SESSION['user_id']), 'name' => (string)($_SESSION['full_name'] ?? '')];
$canEdit = perm_real_role() === 'ADMIN' || perm_current_can($pdo, 'psg-site', 'edit');
if (function_exists('sess_release')) sess_release(); else session_write_close();

try {
    if ($action === 'ctx') {
        $no = trim(p2e_digits((string)($data['no'] ?? '')));
        $kind = ($data['kind'] ?? '') === 'body' ? 'body' : 'third';
        $out(['ok' => true, 'contracts' => psgf_contracts($pdo), 'fin' => psgf_settings($pdo), 'link' => ($lk = $no !== '' ? psgt_link_by_no($pdo, $no) : null), 'resolved' => $no !== '' ? psgf_resolve($pdo, $no, $kind, isset($data['agreement']) ? (string)$data['agreement'] : null, $lk) : null,
              'presets' => array_map(function ($p) { return ['id' => $p['id'], 'name' => $p['name'], 'kind' => $p['kind'] ?? 'third', 'values' => $p['values']]; }, psgdp_list($pdo)),
              'can_edit' => $canEdit, 'now' => time()]);
    }
    if ($action === 'row_search') $out(['ok' => true, 'rows' => psgt_row_search($pdo, (string)($data['q'] ?? ''), (string)($data['kind'] ?? ''))]);
    if ($action === 'row_fill') {
        $html = (string)($data['html'] ?? '');
        if (strlen($html) > 6 * 1048576) $out(['ok' => false, 'error' => 'صفحه بیش از حد بزرگ است.']);
        $out(psgt_row_fill($pdo, intval($data['plate_id'] ?? 0), $html));
    }
    // اقساطِ قرارداد برای همین حق‌بیمه (همان موتورِ مالیِ سایت) ← گام‌های فرمِ اقساطِ پاسارگاد (قسط‌های پشتِ‌سرِهم با مبلغِ برابر یک گام)
    if ($action === 'fin_plan') {
        $cid = intval($data['contract_id'] ?? 0);
        $c = null; foreach (psgf_contracts($pdo) as $x) if ((int)$x['id'] === $cid) $c = $x;
        if (!$c) $out(['ok' => false, 'error' => 'قرارداد پیدا نشد.']);
        $total = intval(preg_replace('/\D/', '', p2e_digits((string)($data['total'] ?? ''))));
        if ($total <= 0) $out(['ok' => false, 'error' => 'مبلغِ مانده صفر است.']);
        $d = array_map('intval', explode('/', preg_replace('#[^0-9/]#', '', p2e_digits((string)($data['issue'] ?? '')))));
        if (count($d) < 3 || $d[0] < 1300) { [$y, $m, $dd] = jalali_from_gregorian_ts(time()); $d = [$y, $m, $dd]; }
        $plan = fin_plan_from_contract($c, 'psgx');
        $sch = fin_plan_schedule($total, $d[0], $d[1], $d[2], $plan);
        $steps = [];
        foreach ($sch as $i => $r) {
            $last = $steps ? count($steps) - 1 : -1;
            if ($last >= 0 && $steps[$last]['amount'] === (int)$r['amount']) { $steps[$last]['count']++; continue; }
            $steps[] = ['count' => 1, 'amount' => (int)$r['amount'], 'due' => $r['due'], 'interval' => (int)$plan['interval']];
        }
        $out(['ok' => true, 'plan' => fin_plan_label($plan), 'schedule' => $sch, 'steps' => $steps, 'account' => psgf_settings($pdo)['accounts'][(string)$cid] ?? null]);
    }
    if ($action === 'fin_save') {
        if (!$canEdit) $out(['ok' => false, 'error' => 'فقط کسی که «ویرایشِ» سایتِ بیمه‌گر را دارد.']);
        $out(psgf_save($pdo, $data));
    }
    $out(['ok' => false, 'error' => 'درخواست نامعتبر.']);
} catch (Throwable $e) {
    error_log('[psgx_bar] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . mb_substr($e->getMessage(), 0, 200)]);
}
