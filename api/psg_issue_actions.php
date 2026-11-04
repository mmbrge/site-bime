<?php
// فایل: api/psg_issue_actions.php
// «پنلِ صدورِ پاسارگاد»: آماده‌سازی ← (تأییدِ کاربر) ← ثبتِ پیش‌نویس در سامانه‌ی پاسارگاد از روی ردیفِ درخواست، بارگذاریِ مدارک، اصلاح.
// منطق: api/_psg_issue.php — هر ثبت فقط با تأییدِ صریحِ کاربر در پنجره‌ی پیش‌نمایش انجام می‌شود.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_psg_issue.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
if (($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'دسترسی به «ساختِ پیش‌نویسِ پاسارگاد» ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']);
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
$actor = ['id' => intval($_SESSION['user_id']), 'name' => (string)($_SESSION['full_name'] ?? '')];
if (function_exists('sess_release')) sess_release(); else session_write_close();
psgi_ensure($pdo);

try {
    if ($action === 'suggest') {
        $row = psgi_row($pdo, intval($data['plate_id'] ?? 0));
        if (!$row) $out(['ok' => false, 'error' => 'ردیف پیدا نشد.']);
        $kind = psgi_kind_of($row['insurance_type']);
        $tpl = psgi_template_get($pdo, $row['company_id'], $kind, $row['car_name']);
        $st = $pdo->prepare("SELECT * FROM psg_issue_jobs WHERE plate_id = ? ORDER BY id DESC LIMIT 8");
        $st->execute([$row['id']]);
        $out(['ok' => true, 'kind' => $kind, 'kind_fa' => PSGI_KINDS[$kind]['label'], 'insurer' => $row['insurer'] ?: 'PASARGAD', 'draft_no' => $row['psg_draft_no'] ?? null,
              'sample' => psgi_suggest_sample($pdo, $row, $kind), 'template' => $tpl ? ['source' => $tpl['source'], 'at' => psgd_jdt($tpl['at']), 'count' => count($tpl['fields']), 'car' => $tpl['car_key'] !== ''] : null,
              'jobs' => array_map('psgi_job_out', $st->fetchAll()), 'insured' => $row['_insured_name'], 'insured_nid' => $row['_insured_nid'], 'car' => $row['car_name']]);
    }
    if ($action === 'jobs') {
        $rid = intval($data['request_id'] ?? 0);
        $st = $pdo->prepare("SELECT j.* FROM psg_issue_jobs j WHERE j.request_id = ? AND j.id IN (SELECT MAX(id) FROM psg_issue_jobs WHERE request_id = ? GROUP BY plate_id)");
        $st->execute([$rid, $rid]);
        $out(['ok' => true, 'jobs' => array_map('psgi_job_out', $st->fetchAll())]);
    }
    if ($action === 'prepare') $out(psgi_prepare($pdo, intval($data['plate_id'] ?? 0), ['sample' => (string)($data['sample'] ?? ''), 'again' => !empty($data['again'])], $actor));
    if ($action === 'submit') {
        $r = psgi_submit($pdo, intval($data['job_id'] ?? 0), ['edits' => (array)($data['edits'] ?? []), 'exclude' => array_map('strval', (array)($data['exclude'] ?? []))], $actor);
        if (!empty($r['ok']) && !empty($data['upload_docs']) && !empty($r['job']['draft_no'])) $r['upload'] = psgi_upload_docs($pdo, intval($data['job_id']));
        $out($r);
    }
    if ($action === 'upload_docs') $out(psgi_upload_docs($pdo, intval($data['job_id'] ?? 0), array_map('intval', (array)($data['doc_ids'] ?? []))));
    // اصلاحِ پیش‌نویس در پاسارگاد (هم‌زمان روی ردیفِ سایت فقط شماره/وضعیت ثبت می‌ماند)
    if ($action === 'restore_cargo') {
        $j = psgi_job($pdo, intval($data['job_id'] ?? 0));
        if (!$j || !$j['draft_no'] || !$j['cargo_orig'] || !$j['cargo_used']) $out(['ok' => false, 'error' => 'برای این پیش‌نویس نوعِ اتاقِ بار عوض نشده بود.']);
        $r = psgi_edit($pdo, $j['kind'], $j['draft_no'], ['otaghBar' => $j['cargo_orig']]);
        if (!empty($r['ok'])) $pdo->prepare("UPDATE psg_issue_jobs SET cargo_used = NULL, message = 'اتاقِ بار به نوعِ اصلی برگشت', updated_at = NOW() WHERE id = ?")->execute([$j['id']]);
        $out($r);
    }
    if ($action === 'edit') {
        $kind = isset(PSGI_KINDS[$data['kind'] ?? '']) ? $data['kind'] : 'third';
        $ov = [];
        foreach ((array)($data['fields'] ?? []) as $k => $v) if (is_string($k) && preg_match('/^[A-Za-z0-9_:.\-]{2,120}$/', $k)) $ov[$k] = (string)$v;
        $out(psgi_edit($pdo, $kind, trim(p2e_digits((string)($data['draft_no'] ?? ''))), $ov));
    }
    // ---- استعلامِ حق بیمه‌ی بدنه‌ی پاسارگاد (فقط استعلام؛ چیزی ثبت نمی‌شود) ----
    if (strpos($action, 'prem_') === 0) {
        require_once __DIR__ . '/_psg_premium.php';
        if ($action === 'prem_boot') $out(psgp_boot($pdo, $actor['id']));
        if ($action === 'prem_lov') $out(psgp_lov($pdo, $actor['id'], (string)($data['lov'] ?? ''), (string)($data['q'] ?? '')));
        if ($action === 'prem_calc') {
            $vals = [];
            foreach ((array)($data['values'] ?? []) as $k => $v) if (is_string($k) && preg_match('/^[A-Za-z0-9_:.\-]{1,140}$/', $k)) $vals[$k] = is_bool($v) ? $v : (is_array($v) ? array_map('strval', $v) : (string)$v);
            $out(psgp_calc($pdo, $actor['id'], $vals, !empty($data['print'])));
        }
        if ($action === 'prem_pdf') {
            $tok = preg_replace('/[^a-f0-9]/', '', (string)($data['t'] ?? ''));
            $f = psgp_dir() . '/p_' . intval($actor['id']) . '_' . $tok . '.pdf';
            if ($tok === '' || !is_file($f)) { http_response_code(404); $out(['ok' => false, 'error' => 'فایل پیدا نشد.']); }
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . (!empty($data['dl']) ? 'attachment' : 'inline') . '; filename="estelam-badaneh.pdf"');
            header('Content-Length: ' . filesize($f));
            readfile($f); exit;
        }
    }
    if ($action === 'template_clear') {
        $pdo->prepare("DELETE FROM psg_issue_templates WHERE company_id = ? AND kind = ?")->execute([intval($data['company_id'] ?? 0), isset(PSGI_KINDS[$data['kind'] ?? '']) ? $data['kind'] : 'third']);
        $out(['ok' => true]);
    }
    $out(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[psg_issue_actions] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]);
}
