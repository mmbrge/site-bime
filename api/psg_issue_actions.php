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
              'jobs' => array_map('psgi_job_out', $st->fetchAll()), 'insured' => $row['_insured_name'], 'insured_nid' => $row['_insured_nid'], 'car' => $row['car_name'],
              'presets' => array_map(function ($p) { return ['id' => $p['id'], 'name' => $p['name']]; }, psgdp_list($pdo, $kind)),
              'auto_preset' => ($ap = psgdp_resolve($pdo, $row['company_id'], $kind, ($data['mode'] ?? '') === 'group' ? 'group' : 'single')) ? ['id' => $ap['id'], 'name' => $ap['name']] : null]);
    }
    if ($action === 'jobs') {
        $rid = intval($data['request_id'] ?? 0);
        $st = $pdo->prepare("SELECT j.* FROM psg_issue_jobs j WHERE j.request_id = ? AND j.id IN (SELECT MAX(id) FROM psg_issue_jobs WHERE request_id = ? GROUP BY plate_id)");
        $st->execute([$rid, $rid]);
        $out(['ok' => true, 'jobs' => array_map('psgi_job_out', $st->fetchAll())]);
    }
    // ساختِ پیش‌نویس از بخشِ «پیش‌نویس‌ها» (بدونِ ردیفِ درخواست)
    if ($action === 'create_manual') {
        $row = psgi_manual_row($pdo, (array)($data['row'] ?? []));
        if ($row['chassis_no'] === '' && $row['engine_no'] === '' && $row['plate_p2'] === '') $out(['ok' => false, 'error' => 'دست‌کم شاسی، موتور یا پلاک را بنویسید.']);
        $out(psgi_prepare_row($pdo, $row, ['sample' => (string)($data['sample'] ?? ''), 'preset' => (string)($data['preset'] ?? ''), 'mode' => 'single'], $actor));
    }
    if ($action === 'companies') {
        $st = $pdo->query("SELECT id, name FROM companies WHERE COALESCE(is_import, 0) = 0 ORDER BY name LIMIT 2000");
        $out(['ok' => true, 'companies' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'prepare') $out(psgi_prepare($pdo, intval($data['plate_id'] ?? 0), ['sample' => (string)($data['sample'] ?? ''), 'again' => !empty($data['again']), 'preset' => (string)($data['preset'] ?? ''), 'mode' => (string)($data['mode'] ?? 'single')], $actor));
    // پیش‌فرض‌های پیش‌نویس (بی‌نهایت) + نسبت‌دادن به شرکت/قرارداد و حالتِ تکی/گروهی
    if ($action === 'dp_list') {
        $cs = []; try { $cs = $pdo->query("SELECT id, name FROM companies WHERE COALESCE(is_import, 0) = 0 ORDER BY name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        $out(['ok' => true, 'presets' => psgdp_list($pdo), 'companies' => $cs, 'contracts' => psgf_contracts($pdo)]);
    }
    if ($action === 'dp_save') $out(psgdp_save($pdo, (string)($data['id'] ?? ''), (string)($data['name'] ?? ''), (string)($data['kind'] ?? 'third'), (array)($data['values'] ?? []), (array)($data['targets'] ?? []), $actor['name']));
    if ($action === 'dp_delete') $out(psgdp_delete($pdo, (string)($data['id'] ?? '')));
    // خانه‌های یک پیش‌نویسِ موجود (برای ساختنِ پیش‌فرض از روی آن)
    if ($action === 'dp_from_draft') {
        $kind = ($data['kind'] ?? '') === 'body' ? 'body' : 'third';
        $no = trim(p2e_digits((string)($data['draft_no'] ?? '')));
        $lk = inq_lock(60); if (!$lk) $out(['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است.']);
        try {
            $c = new PsgIssue(); $v = $c->rowAction($kind, $no, 'editCommand');
            if (!empty($v['need_login'])) $out(['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید.']);
            if (empty($v['ok']) || !$c->isForm($v['html'])) $out(['ok' => false, 'error' => $v['error'] ?? 'پیش‌نویس باز نشد.']);
            $f = $c->formFields($v['html']); $lab = $c->labels($v['html']); $rows = [];
            foreach ($f as $k => $val) { if (psgi_party_key($k) || in_array($k, PSGI_ROW_KEYS, true) || preg_match('/^(token|entity|autoScroll|filesId|filesHidden|javax|editform_SUBMIT)|j_id_jsp_/', $k) || psgi_s($val) === '' || psgi_s($val) === ';HIJRI') continue;
                $rows[] = ['key' => $k, 'label' => PSGI_FIELD_FA[$k] ?? ($lab[$k] ?? $lab['editform:' . $k] ?? $k), 'value' => $val]; }
            $out(['ok' => true, 'fields' => $rows]);
        } finally { inq_unlock($lk); }
    }
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
        // پیش‌فرض‌های استعلام (بی‌نهایت؛ هر کدام فقط خانه‌هایی که انتخاب شده‌اند)
        if ($action === 'prem_presets') $out(['ok' => true, 'presets' => psgp_presets($pdo)]);
        if ($action === 'prem_preset_save') $out(psgp_preset_save($pdo, (string)($data['id'] ?? ''), (string)($data['name'] ?? ''), (array)($data['values'] ?? []), $actor['name']));
        if ($action === 'prem_preset_delete') $out(psgp_preset_delete($pdo, (string)($data['id'] ?? '')));
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
    // فهرستِ انتخاب (LOV) — همان پنجره‌ی مربعِ فلش‌دار: باز کردن، جستجو با خانه‌های خودِ فهرست، صفحه‌ی بعد/قبل
    if ($action === 'lov') {
        $op = in_array($data['op'] ?? '', ['open', 'search', 'next', 'prev'], true) ? $data['op'] : 'open';
        $vals = [];
        foreach ((array)($data['values'] ?? []) as $k => $v) if (is_string($k) && preg_match('/^[A-Za-z0-9_:.\-]{1,140}$/', $k)) $vals[$k] = (string)$v;
        $out(psgl_run($pdo, $actor['id'], (string)($data['lov'] ?? ''), $op, $vals));
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
