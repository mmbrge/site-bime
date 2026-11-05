<?php
// فایل: api/_psg_tools.php
// ابزارهای «سایتِ بیمه‌گر / پاسارگاد» که هم موتورِ پیش‌نویس (psg_issue) و هم نوارِ بالای پروکسی (psgx) از آن استفاده می‌کنند:
//   - پیش‌فرض‌های پیش‌نویس (بی‌نهایت؛ هر کدام فقط خانه‌هایی که انتخاب شده‌اند) با نسبت‌دادن به شرکت/قرارداد و حالتِ تکی/گروهی
//   - تنظیماتِ مالی: قراردادِ پیش‌فرضِ هر حالت (ثالث/بدنه)، حسابِ پاسارگادِ هر قرارداد، ثبتِ نهاییِ خودکار
//   - پر کردنِ فرمِ سامانه از روی ردیفِ درخواست (همان نگاشتِ موتورِ پیش‌نویس)
require_once __DIR__ . '/_inquiry.php';

function psgt_get($pdo, $key) {
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $st->execute([$key]); $j = json_decode((string)$st->fetchColumn(), true); }
    catch (Throwable $e) { $j = null; }
    return is_array($j) ? $j : [];
}
function psgt_put($pdo, $key, array $v) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}
function psgt_clean_values(array $values) {
    $clean = [];
    foreach ($values as $k => $v) if (is_string($k) && preg_match('/^[A-Za-z0-9_:.\-]{1,140}$/', $k) && strpos($k, 'javax.faces') !== 0 && strpos($k, 'j_id_jsp_') === false && !in_array($k, ['token', 'editform_SUBMIT', 'editform:_idcl', 'autoScroll'], true))
        $clean[$k] = is_array($v) ? array_values(array_map('strval', $v)) : (string)$v;
    return $clean;
}

// ---------- پیش‌فرض‌های پیش‌نویس ----------
// [{id, name, kind: third|body, values: {field: value}, targets: [{t: all|company|contract, id, mode: any|single|group}], by, at}]
function psgdp_list($pdo, $kind = null) {
    $L = array_values(psgt_get($pdo, 'psgd_presets'));
    if ($kind !== null) $L = array_values(array_filter($L, function ($p) use ($kind) { return ($p['kind'] ?? 'third') === $kind; }));
    return $L;
}
function psgdp_get($pdo, $id) { foreach (psgdp_list($pdo) as $p) if (($p['id'] ?? '') === (string)$id) return $p; return null; }
function psgdp_save($pdo, $id, $name, $kind, array $values, array $targets, $by) {
    $name = trim(mb_substr((string)$name, 0, 80));
    if ($name === '') return ['ok' => false, 'error' => 'نامِ پیش‌فرض را بنویسید.'];
    $kind = $kind === 'body' ? 'body' : 'third';
    $clean = psgt_clean_values($values);
    // خانه‌های مخصوصِ ردیف (پلاک، شاسی، موتور، …) و بیمه‌گذار هرگز در پیش‌فرض نمی‌مانند
    if (function_exists('psgi_party_key')) foreach (array_keys($clean) as $k) if (psgi_party_key($k) || (defined('PSGI_ROW_KEYS') && in_array($k, PSGI_ROW_KEYS, true))) unset($clean[$k]);
    if (!$clean) return ['ok' => false, 'error' => 'هیچ خانه‌ای برای این پیش‌فرض انتخاب نشده.'];
    $tg = [];
    foreach ($targets as $t) {
        $tt = in_array($t['t'] ?? '', ['all', 'company', 'contract'], true) ? $t['t'] : 'all';
        $mode = in_array($t['mode'] ?? '', ['single', 'group'], true) ? $t['mode'] : 'any';
        $tid = $tt === 'all' ? 0 : intval($t['id'] ?? 0);
        if ($tt !== 'all' && !$tid) continue;
        $tg[$tt . ':' . $tid . ':' . $mode] = ['t' => $tt, 'id' => $tid, 'mode' => $mode];
    }
    $list = psgdp_list($pdo); $found = false;
    foreach ($list as &$p) if ($id !== '' && ($p['id'] ?? '') === $id) { $p = ['id' => $id, 'name' => $name, 'kind' => $kind, 'values' => $clean, 'targets' => array_values($tg), 'by' => $by, 'at' => date('Y-m-d H:i')]; $found = true; }
    unset($p);
    if (!$found) $list[] = ['id' => $id !== '' ? $id : bin2hex(random_bytes(5)), 'name' => $name, 'kind' => $kind, 'values' => $clean, 'targets' => array_values($tg), 'by' => $by, 'at' => date('Y-m-d H:i')];
    // یک هدف (شرکت/قرارداد + حالت) فقط یک پیش‌فرض دارد: هدفِ تکراری از پیش‌فرض‌های دیگرِ همین رشته برداشته می‌شود
    $myId = $found ? $id : $list[count($list) - 1]['id'];
    foreach ($list as &$p) if (($p['id'] ?? '') !== $myId && ($p['kind'] ?? 'third') === $kind)
        $p['targets'] = array_values(array_filter((array)($p['targets'] ?? []), function ($t) use ($tg) { return !isset($tg[($t['t'] ?? '') . ':' . intval($t['id'] ?? 0) . ':' . ($t['mode'] ?? 'any')]) || ($t['t'] ?? '') === 'all'; }));
    unset($p);
    psgt_put($pdo, 'psgd_presets', array_values($list));
    return ['ok' => true, 'id' => $myId, 'presets' => psgdp_list($pdo)];
}
function psgdp_delete($pdo, $id) {
    psgt_put($pdo, 'psgd_presets', array_values(array_filter(psgdp_list($pdo), function ($p) use ($id) { return ($p['id'] ?? '') !== $id; })));
    return ['ok' => true, 'presets' => psgdp_list($pdo)];
}
// پیش‌فرضِ خودکار برای یک ردیف: شرکت+حالت > شرکت > قرارداد+حالت > قرارداد > همه+حالت > همه
function psgdp_resolve($pdo, $companyId, $kind, $mode) {
    $companyId = intval($companyId); $contract = 0;
    if ($companyId) { try { $q = $pdo->prepare("SELECT contract_id FROM companies WHERE id = ?"); $q->execute([$companyId]); $contract = intval($q->fetchColumn()); } catch (Throwable $e) {} }
    $best = null; $bestScore = -1;
    foreach (psgdp_list($pdo, $kind === 'body' ? 'body' : 'third') as $p) foreach ((array)($p['targets'] ?? []) as $t) {
        $tm = $t['mode'] ?? 'any';
        if ($tm !== 'any' && $tm !== $mode) continue;
        $tt = $t['t'] ?? 'all'; $tid = intval($t['id'] ?? 0);
        if ($tt === 'company' && $tid !== $companyId) continue;
        if ($tt === 'contract' && (!$contract || $tid !== $contract)) continue;
        $score = ['company' => 4, 'contract' => 2, 'all' => 0][$tt] + ($tm === $mode ? 1 : 0);
        if ($score > $bestScore) { $best = $p; $bestScore = $score; }
    }
    return $best;
}

// ---------- مالی ----------
function psgf_settings($pdo) {
    $s = psgt_get($pdo, 'psgf_settings');
    return ['default' => ['third' => intval($s['default']['third'] ?? 0), 'body' => intval($s['default']['body'] ?? 0)],
            'accounts' => (array)($s['accounts'] ?? []), 'final_save' => !empty($s['final_save']), 'auto_run' => !empty($s['auto_run'])];
}
function psgf_save($pdo, array $d) {
    $s = psgf_settings($pdo);
    if (isset($d['default'])) foreach (['third', 'body'] as $k) if (isset($d['default'][$k])) $s['default'][$k] = intval($d['default'][$k]);
    if (isset($d['account'])) {
        $cid = (string)intval($d['account']['contract'] ?? 0);
        $id = preg_replace('/[^0-9]/', '', (string)($d['account']['id'] ?? ''));
        if ($id === '') unset($s['accounts'][$cid]);
        else $s['accounts'][$cid] = ['id' => $id, 'desc' => mb_substr(trim((string)($d['account']['desc'] ?? '')), 0, 80)];
    }
    foreach (['final_save', 'auto_run'] as $k) if (isset($d[$k])) $s[$k] = !empty($d[$k]);
    psgt_put($pdo, 'psgf_settings', $s);
    return ['ok' => true, 'settings' => psgf_settings($pdo)];
}
function psgf_contracts($pdo) {
    try {
        if (function_exists('fin_contracts_ensure')) fin_contracts_ensure($pdo);
        return $pdo->query("SELECT id, contract_no, name, pay_type, installment_count, first_due_months, first_due_days, interval_months, split_method FROM fin_contracts WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
// پیش‌نویس/بیمه‌نامه ← ردیفِ سایت و قراردادِ شرکتش
function psgt_link_by_no($pdo, $no) {
    $no = trim((string)$no);
    if ($no === '') return null;
    try {
        $st = $pdo->prepare("SELECT p.id, p.request_id, p.insurance_type, p.car_name, r.company_id, c.name AS company_name, c.contract_id FROM company_request_plates p JOIN company_requests r ON r.id = p.request_id
                             LEFT JOIN companies c ON c.id = r.company_id WHERE p.psg_draft_no = ? OR p.psg_policy_no = ? ORDER BY p.id DESC LIMIT 1");
        $st->execute([$no, $no]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['plate' => (int)$r['id'], 'request' => (int)$r['request_id'], 'company_id' => (int)$r['company_id'], 'company' => $r['company_name'], 'contract_id' => (int)$r['contract_id'], 'car' => $r['car_name'], 'kind' => $r['insurance_type'] === 'BODY' ? 'body' : 'third'] : null;
    } catch (Throwable $e) { return null; }
}

// ---------- پر کردن از ردیفِ درخواست ----------
function psgt_row_search($pdo, $q, $kind = '') {
    $q = trim(p2e_digits((string)$q));
    if (mb_strlen($q) < 2) return [];
    $w = []; $p = [];
    $cl = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));
    if (ctype_digit($q) && strlen($q) <= 7) { $w[] = 'p.request_id = ?'; $p[] = intval($q); }
    if (strlen($cl) >= 4) { foreach (['p.chassis_no', 'p.vin', 'p.engine_no'] as $c) { $w[] = "REPLACE(UPPER(COALESCE($c, '')), ' ', '') LIKE ?"; $p[] = '%' . $cl . '%'; } }
    $w[] = "CONCAT(COALESCE(p.plate_p2, ''), COALESCE(p.plate_letter, ''), COALESCE(p.plate_p4, ''), COALESCE(p.plate_p1, '')) LIKE ?"; $p[] = '%' . str_replace(' ', '', $q) . '%';
    $w[] = 'c.name LIKE ?'; $p[] = '%' . $q . '%';
    $w[] = 'p.car_name LIKE ?'; $p[] = '%' . $q . '%';
    $tw = $kind === 'body' ? " AND p.insurance_type = 'BODY'" : ($kind === 'third' ? " AND p.insurance_type = 'THIRDPARTY'" : '');
    try {
        $st = $pdo->prepare("SELECT p.id, p.request_id, p.insurance_type, p.status, p.car_name, p.plate_p1, p.plate_p2, p.plate_letter, p.plate_p4, p.chassis_no, p.engine_no, p.psg_draft_no, c.name AS company_name
                             FROM company_request_plates p JOIN company_requests r ON r.id = p.request_id LEFT JOIN companies c ON c.id = r.company_id
                             WHERE COALESCE(r.insurer, 'PASARGAD') <> 'IRAN' AND p.status NOT IN ('CANCELLED') $tw AND (" . implode(' OR ', $w) . ") ORDER BY (p.status = 'ISSUED') ASC, p.id DESC LIMIT 25");
        $st->execute($p);
        return array_map(function ($r) {
            return ['id' => (int)$r['id'], 'request' => (int)$r['request_id'], 'kind' => $r['insurance_type'] === 'BODY' ? 'body' : 'third', 'status' => $r['status'], 'car' => $r['car_name'],
                    'plate' => trim($r['plate_p2'] . ' ' . $r['plate_letter'] . ' ' . $r['plate_p4'] . ($r['plate_p1'] !== '' && $r['plate_p1'] !== null ? ' - ' . $r['plate_p1'] : '')), 'chassis' => $r['chassis_no'], 'engine' => $r['engine_no'],
                    'company' => $r['company_name'], 'draft' => $r['psg_draft_no']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { error_log('[psgt_row_search] ' . $e->getMessage()); return []; }
}
// خانه‌های فرمِ همین صفحه از روی ردیف (بدونِ هیچ درخواستی به پاسارگاد؛ فقط با HTMLِ صفحه برای گزینه‌ها)
function psgt_row_fill($pdo, $plateId, $html) {
    require_once __DIR__ . '/_psg_issue.php';
    $row = psgi_row($pdo, $plateId);
    if (!$row) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    $kind = psgi_kind_of($row['insurance_type']);
    $c = new PsgIssue();
    $ro = psgi_row_overrides($c, (string)$html, $row, $kind);
    $ov = $ro['ov'];
    if ($ro['prev'] !== '' && strpos((string)$html, 'name="shomarehBimehnamehGhabli"') !== false) $ov['shomarehBimehnamehGhabli'] = $ro['prev'];
    $preset = psgdp_resolve($pdo, $row['company_id'], $kind, 'single');
    return ['ok' => true, 'fields' => $ov, 'miss' => $ro['miss'], 'kind' => $kind, 'insured' => ['nid' => $row['_insured_nid'], 'name' => $row['_insured_name'], 'legal' => strlen((string)$row['_insured_nid']) === 11],
            'preset' => $preset ? ['id' => $preset['id'], 'name' => $preset['name']] : null, 'row' => ['id' => (int)$row['id'], 'request' => (int)$row['request_id'], 'car' => $row['car_name'], 'company' => $row['company_name']]];
}
