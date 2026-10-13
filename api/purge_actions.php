<?php
// فایل: api/purge_actions.php
// «حذفِ کامل» (فقط مدیر کلِ واقعی، با رمزِ ورودِ خودش): معرفی‌نامه، درخواستِ کارکنان، درخواستِ شرکتی.
// preview: پیش از حذف نشان می‌دهد چه چیزهایی حذف می‌شوند. منطق در api/_purge.php.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_purge.php';

function pout($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user_id']) || perm_real_role() !== 'ADMIN') pout(['ok' => false, 'error' => 'حذفِ کامل فقط برای مدیر کل است.']);
$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($data['action'] ?? '');
$uid = intval($_SESSION['user_id']);
$kind = (string)($data['kind'] ?? '');
$id = intval($data['id'] ?? 0);
$siteRoot = dirname(__DIR__);
if (!in_array($kind, ['case', 'intro', 'creq'], true) || $id <= 0) pout(['ok' => false, 'error' => 'مورد نامعتبر است.']);

$count = function ($sql, $args) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($args); return intval($st->fetchColumn()); } catch (Throwable $e) { return 0; } };

try {
    if ($action === 'preview') {
        $out = ['ok' => true];
        if ($kind === 'case' || $kind === 'intro') {
            $where = $kind === 'case' ? 'pc.id = ?' : 'pc.introduction_id = ?';
            $out['title'] = $kind === 'case'
                ? (string)$pdo->query("SELECT CONCAT(COALESCE(unique_code, id), ' - ', COALESCE(insured_name, '')) FROM policy_cases WHERE id = " . $id)->fetchColumn()
                : (string)$pdo->query("SELECT CONCAT('معرفی‌نامه‌ی ', p.full_name) FROM introductions i JOIN persons p ON p.id = i.person_id WHERE i.id = " . $id)->fetchColumn();
            if ($out['title'] === '') pout(['ok' => false, 'error' => 'مورد یافت نشد.']);
            $out['cases'] = $count("SELECT COUNT(*) FROM policy_cases pc WHERE $where", [$id]);
            $out['issued'] = $count("SELECT COUNT(*) FROM policy_cases pc WHERE $where AND pc.status = 'ISSUED'", [$id]);
            $out['documents'] = $count("SELECT COUNT(*) FROM case_documents d JOIN policy_cases pc ON pc.id = d.case_id WHERE $where", [$id]);
            $out['installments'] = $count("SELECT COUNT(*) FROM policy_installments i JOIN policy_cases pc ON pc.id = i.case_id WHERE $where", [$id]);
            $out['paid'] = $count("SELECT COALESCE(SUM(a.amount), 0) FROM payment_allocations a JOIN policy_installments i ON i.id = a.installment_id JOIN policy_cases pc ON pc.id = i.case_id WHERE $where", [$id]);
            $out['invoiced'] = $count("SELECT COUNT(*) FROM policy_cases pc WHERE $where AND pc.is_invoiced = 1", [$id]);
        } else {
            $out['title'] = (string)$pdo->query("SELECT CONCAT(COALESCE(cr.ref_code, CONCAT('#', cr.id)), ' - ', COALESCE(c.name, '')) FROM company_requests cr LEFT JOIN companies c ON c.id = cr.company_id WHERE cr.id = " . $id)->fetchColumn();
            if ($out['title'] === '') pout(['ok' => false, 'error' => 'مورد یافت نشد.']);
            $out['cases'] = $count("SELECT COUNT(*) FROM company_request_plates WHERE request_id = ?", [$id]);
            $out['issued'] = $count("SELECT COUNT(*) FROM company_request_plates WHERE request_id = ? AND status = 'ISSUED'", [$id]);
            $out['documents'] = $count("SELECT COUNT(*) FROM company_documents WHERE request_id = ?", [$id]);
            $out['installments'] = $count("SELECT COUNT(*) FROM company_installments ci JOIN company_request_plates p ON p.id = ci.plate_id WHERE p.request_id = ?", [$id]);
            $out['paid'] = $count("SELECT COALESCE(SUM(a.amount), 0) FROM company_payment_allocations a JOIN company_installments ci ON ci.id = a.installment_id JOIN company_request_plates p ON p.id = ci.plate_id WHERE p.request_id = ?", [$id]);
            $out['invoiced'] = 0;
        }
        pout($out);
    }

    if ($action === 'purge') {
        $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([$uid]);
        if (!password_verify((string)($data['password'] ?? ''), (string)$st->fetchColumn())) pout(['ok' => false, 'error' => 'رمزِ عبور درست نیست.']);
        @set_time_limit(300);
        if ($kind === 'case') pout(purge_policy_case($pdo, $siteRoot, $id, $uid));
        if ($kind === 'intro') pout(purge_introduction($pdo, $siteRoot, $id, $uid));
        pout(purge_company_request($pdo, $siteRoot, $id, $uid));
    }
    pout(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[purge_actions] ' . $e->getMessage());
    pout(['ok' => false, 'error' => 'خطا: ' . $e->getMessage()]);
}
