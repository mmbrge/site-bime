<?php
// فایل: api/_site_slip.php
// «فیشِ ساختِ سامانه»: وقتی فیش‌های داخلِ فایلِ بیمه‌نامه با اقساطِ قرارداد فرق دارد (و تنظیمِ صدور/شرکت «فیش با سامانه» است)،
// اقساط طبقِ قرارداد ثبت می‌شود و برای هر قسط یک PDF ساخته می‌شود: پوشه‌ی «فیش‌های پرداختی» کنارِ بیمه‌نامه، با نامِ «فیش سامانه - قسط n (سررسید).pdf».
// فیش‌های اصلیِ بیمه‌نامه دست نمی‌خورند؛ با ساختِ دوباره‌ی اقساط فقط فیش‌های قبلیِ خودِ سامانه پاک و دوباره ساخته می‌شوند.

const SITE_SLIP_PREFIX = 'فیش سامانه';

function site_slip_table($kind) { return $kind === 'C' ? ['company_installments', 'plate_id'] : ['policy_installments', 'case_id']; }

// پیش از ساختِ دوباره‌ی اقساط: فایل‌های فیشِ سامانه‌ی همین بیمه‌نامه
function site_slip_cleanup($pdo, $kind, $refId) {
    [$t, $fk] = site_slip_table($kind);
    $root = dirname(__DIR__);
    try {
        $st = $pdo->prepare("SELECT slip_json, slip_file FROM `$t` WHERE `$fk` = ? AND slip_file IS NOT NULL");
        $st->execute([intval($refId)]);
        $sadere = false;
        foreach ($st->fetchAll() as $r) {
            $j = json_decode((string)$r['slip_json'], true);
            if (empty($j['site'])) continue;
            $f = $root . '/' . ltrim((string)$r['slip_file'], '/');
            if (mb_strpos(basename($f), SITE_SLIP_PREFIX) !== 0) continue;
            if (is_file($f)) @unlink($f);
            if ($sadere === false) $sadere = (site_slip_context($pdo, $kind, $refId) ?: [])['sadere'] ?? null;
            if ($sadere && is_file($sadere . '/' . basename($f))) @unlink($sadere . '/' . basename($f));
        }
    } catch (Throwable $e) { error_log('[site_slip_cleanup] ' . $e->getMessage()); }
}

// اطلاعاتِ بیمه‌نامه برای فیش + پوشه‌ی ذخیره
function site_slip_context($pdo, $kind, $refId) {
    $root = dirname(__DIR__);
    if ($kind === 'C') {
        $st = $pdo->prepare("SELECT crp.*, cr.company_id, c.name AS company_name, c.national_id AS company_nid FROM company_request_plates crp
                               JOIN company_requests cr ON cr.id = crp.request_id LEFT JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
        $st->execute([intval($refId)]);
        $r = $st->fetch();
        if (!$r) return null;
        $info = json_decode((string)($r['issue_info'] ?? ''), true) ?: [];
        $party = json_decode((string)($r['party_json'] ?? ''), true) ?: [];
        $insured = trim((string)($info['insured_name'] ?? '')) ?: (($r['party_mode'] ?? '') === 'OWNER' ? trim((string)($party['name'] ?? '')) : '') ?: (string)$r['company_name'];
        $nid = trim((string)($info['insured_national_id'] ?? '')) ?: (($r['party_mode'] ?? '') === 'OWNER' ? trim((string)($party['national_id'] ?? '')) : '') ?: (string)($r['company_nid'] ?? '');
        $plate = function_exists('company_row_label') ? company_row_label($r) : '';
        $car = (string)($r['car_name'] ?? '');
        $company = (string)$r['company_name'];
    } else {
        $st = $pdo->prepare("SELECT pc.*, p.full_name AS holder_name, p.national_code AS holder_nid, c.name AS company_name FROM policy_cases pc
                               JOIN persons p ON p.id = pc.person_id LEFT JOIN companies c ON c.id = p.company_id WHERE pc.id = ?");
        $st->execute([intval($refId)]);
        $r = $st->fetch();
        if (!$r) return null;
        $insured = trim((string)($r['insured_name'] ?? '')) ?: (string)$r['holder_name'];
        $nid = trim((string)($r['insured_national_id'] ?? '')) ?: (string)$r['holder_nid'];
        $plate = (string)($r['plate'] ?? '');
        $car = trim(($r['car_system'] ?? '') . ' ' . ($r['car_type'] ?? ''));
        $company = (string)($r['company_name'] ?? '');
    }
    $dir = null;
    if (!empty($r['folder_path']) && is_dir($r['folder_path'])) $dir = $r['folder_path'];
    elseif (!empty($r['issued_file_path']) && is_dir(dirname($root . '/' . $r['issued_file_path']))) $dir = dirname($root . '/' . $r['issued_file_path']);
    // کپیِ «بایگانی صادره» (اگر پوشه‌اش ساخته شده) هم فیش‌های سامانه را می‌گیرد
    $sadere = null;
    if ($dir && function_exists('build_sadere_base_path')) {
        $ts = policy_issue_ts((string)($r['policy_issue_date'] ?? ''), !empty($r['issued_at']) ? strtotime($r['issued_at']) : time());
        $sd = build_sadere_base_path($root, $ts, $r['insurance_type'] ?? '') . '/' . basename($dir);
        if (is_dir($sd) && realpath($sd) !== realpath($dir)) $sadere = $sd . '/فیش‌های پرداختی';
    }
    if (!$dir) $dir = $root . '/Archive/بایگانی/فیش‌های سامانه/' . ($kind === 'C' ? 'شرکتی' : 'کارکنان') . '/' . intval($refId);
    $dir = rtrim($dir, '/') . '/فیش‌های پرداختی';
    return ['row' => $r, 'insured' => $insured, 'nid' => $nid, 'plate' => $plate, 'car' => $car, 'company' => $company,
            'policy' => (string)($r['policy_number'] ?? ''), 'type_fa' => insurance_type_fa($r['insurance_type'] ?? ''), 'dir' => $dir, 'sadere' => $sadere];
}

// بعد از ثبتِ اقساط: برای هر قسطی که فیشِ سامانه دارد و فایلش هنوز ساخته نشده، PDF ساخته می‌شود
function site_slip_make($pdo, $kind, $refId, $planLabel = '') {
    [$t, $fk] = site_slip_table($kind);
    try {
        $st = $pdo->prepare("SELECT id, inst_number, amount, due_jalali, tracking_code, slip_json, slip_file FROM `$t` WHERE `$fk` = ? ORDER BY inst_number");
        $st->execute([intval($refId)]);
        $insts = $st->fetchAll();
        $todo = array_filter($insts, function ($i) { $j = json_decode((string)$i['slip_json'], true); return !empty($j['site']) && empty($i['slip_file']); });
        if (!$todo) return 0;
        $ctx = site_slip_context($pdo, $kind, $refId);
        if (!$ctx) return 0;
        if (!is_dir($ctx['dir']) && !@mkdir($ctx['dir'], 0755, true) && !is_dir($ctx['dir'])) return 0;
        $root = dirname(__DIR__);
        $up = $pdo->prepare("UPDATE `$t` SET slip_file = ?, slip_json = ? WHERE id = ?");
        $n = 0;
        foreach ($todo as $i) {
            $slip = json_decode((string)$i['slip_json'], true);
            $slip['fish'] = $slip['fish'] ?? (string)$i['tracking_code'];
            $due = str_replace('/', '.', p2e_digits((string)$i['due_jalali']));
            $dest = unique_dest_path($ctx['dir'] . '/' . sanitize_folder_name(SITE_SLIP_PREFIX . ' - قسط ' . intval($i['inst_number']) . ' (' . $due . ')') . '.pdf');
            if (!site_slip_pdf($ctx, $i, $insts, $slip, $planLabel, $dest)) continue;
            $up->execute([ltrim(str_replace($root, '', $dest), '/'), json_encode($slip, JSON_UNESCAPED_UNICODE), $i['id']]);
            if ($ctx['sadere'] && (is_dir($ctx['sadere']) || @mkdir($ctx['sadere'], 0755, true))) @copy($dest, $ctx['sadere'] . '/' . basename($dest));
            $n++;
        }
        return $n;
    } catch (Throwable $e) { error_log('[site_slip_make] ' . $e->getMessage()); return 0; }
}

function site_slip_pdf(array $ctx, array $inst, array $all, array $slip, $planLabel, $dest) {
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $e = function ($v) { return htmlspecialchars(fa_digits((string)$v), ENT_QUOTES, 'UTF-8'); };
    $ltr = function ($v) use ($e) { return $v === '' || $v === null ? '—' : '<span dir="ltr">' . $e($v) . '</span>'; };
    $money = function ($v) use ($e) { return $e(number_format((int)$v)); };
    $pdf = new TCPDF('L', 'mm', 'A5', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10); $pdf->SetAutoPageBreak(true, 8);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->setRTL(true); $pdf->SetFont('vazir', '', 9);
    $pdf->AddPage();
    $pdf->SetFillColor(30, 58, 138); $pdf->Rect(0, 0, 210, 24, 'F');
    $pdf->SetFillColor(14, 116, 144); $pdf->Rect(0, 22, 210, 3, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $cnt = count($all);
    $pdf->writeHTMLCell(190, 0, 10, 5, '<b style="font-size:15pt">فیشِ پرداختِ قسطِ بیمه‌نامه</b>&nbsp;&nbsp;<span style="font-size:11pt">قسطِ ' . $e($inst['inst_number']) . ' از ' . $e($cnt) . '</span><br/>'
        . '<span style="font-size:8pt;color:#c7d2fe">ساخته‌شده توسطِ سامانه بر اساسِ اقساطِ قرارداد' . ($planLabel !== '' ? ' (' . $e($planLabel) . ')' : '') . '</span>', 0, 1, false, true, 'R');
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetY(30);
    $cell = function ($k, $v, $raw = false) use ($e) { return '<td width="17%" style="color:#64748b;font-size:8pt;background-color:#f8fafc">' . $k . '</td><td width="33%" style="font-size:9pt"><b>' . ($raw ? $v : $e($v === '' ? '—' : $v)) . '</b></td>'; };
    $rows = [
        [['بیمه‌گذار', $ctx['insured']], ['کد ملی / شناسه', $ltr($ctx['nid']), true]],
        [['شماره بیمه‌نامه', $ltr($ctx['policy']), true], ['نوع بیمه', $ctx['type_fa']]],
        [['پلاک / خودرو', trim($ctx['plate'] . ($ctx['car'] !== '' ? ' · ' . $ctx['car'] : ''))], ['شرکت', $ctx['company']]],
        [['سررسید', (string)$inst['due_jalali']], ['شماره فیش / کد رهگیری', $ltr($slip['fish'] ?? ''), true]],
        [['شناسه پرداخت', $ltr($slip['shenase'] ?? ''), true], ['نوع', $slip['kind'] ?? 'اقساط']],
        [['شماره حساب', $ltr($slip['account'] ?? ''), true], ['بانک', $slip['bank'] ?? '']],
    ];
    $h = '<table cellpadding="4" style="border:1px solid #c7d2fe;">';
    foreach ($rows as $pair) $h .= '<tr>' . $cell(...$pair[0]) . $cell(...$pair[1]) . '</tr>';
    $h .= '<tr><td width="17%" style="color:#64748b;font-size:8pt;background-color:#eef2ff">مبلغ قابلِ پرداخت</td><td width="83%" style="background-color:#eef2ff"><b style="font-size:13pt;color:#1e3a8a">'
        . $money($inst['amount']) . ' ریال</b>&nbsp;&nbsp;<span style="font-size:8pt;color:#475569">(' . $e(fin_num_to_words((int)$inst['amount'])) . ' ریال)</span></td></tr></table><br/>';
    // جدولِ همه‌ی اقساط؛ قسطِ همین فیش پررنگ
    $h .= '<table cellpadding="3" style="font-size:7.5pt;border:1px solid #e2e8f0;"><tr style="background-color:#f1f5f9;font-weight:bold;"><td width="12%">قسط</td><td width="22%">سررسید</td><td width="26%">مبلغ (ریال)</td>'
        . '<td width="12%">قسط</td><td width="28%">سررسید / مبلغ</td></tr>';
    $half = (int)ceil($cnt / 2);
    for ($k = 0; $k < $half; $k++) {
        $a = $all[$k]; $b = $all[$k + $half] ?? null;
        $hi = function ($x) use ($inst) { return $x && intval($x['id']) === intval($inst['id']) ? 'background-color:#fef3c7;font-weight:bold;' : ''; };
        $h .= '<tr><td style="' . $hi($a) . '">' . $e($a['inst_number']) . '</td><td style="' . $hi($a) . '">' . $e($a['due_jalali']) . '</td><td style="' . $hi($a) . '">' . $money($a['amount']) . '</td>'
            . ($b ? '<td style="' . $hi($b) . '">' . $e($b['inst_number']) . '</td><td style="' . $hi($b) . '">' . $e($b['due_jalali']) . ' · ' . $money($b['amount']) . '</td>' : '<td></td><td></td>') . '</tr>';
    }
    $h .= '</table><br/><span style="font-size:7.5pt;color:#64748b">این فیش با اطلاعاتِ اقساطِ قرارداد در سامانه ساخته شده است (نه از فایلِ بیمه‌نامه). تاریخِ ساخت: '
        . $e(jalali_from_gregorian_ts_dotted(time())) . '</span>';
    $pdf->writeHTML($h, true, false, true, false, '');
    $pdf->Output($dest, 'F');
    return is_file($dest);
}
