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
        if (function_exists('pc_origin_ensure')) pc_origin_ensure($pdo);
        $st = $pdo->prepare("SELECT pc.*, p.full_name AS holder_name, p.national_code AS holder_nid, c.name AS company_name FROM policy_cases pc
                               JOIN persons p ON p.id = pc.person_id LEFT JOIN companies c ON c.id = COALESCE(pc.origin_company_id, p.company_id) WHERE pc.id = ?");
        $st->execute([intval($refId)]);
        $r = $st->fetch();
        if (!$r) return null;
        $insured = trim((string)($r['insured_name'] ?? '')) ?: (string)$r['holder_name'];
        $nid = trim((string)($r['insured_national_id'] ?? '')) ?: (string)$r['holder_nid'];
        $plate = (string)($r['plate'] ?? '');
        $car = car_join([($r['car_system'] ?? ''), ($r['car_type'] ?? '')]);
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

// بعد از ثبتِ اقساط: برای هر قسطی که فیشِ سامانه دارد و فایلش هنوز ساخته نشده، PDF ساخته می‌شود.
// ساختِ PDFها (TCPDF، چند ثانیه برای هر قسط) در درخواستِ وب بعد از فرستادنِ پاسخ انجام می‌شود تا «ثبتِ صدور» منتظرش نماند؛
// تا ساخته شوند، قسط «فیشِ سامانه (در حالِ ساخت)» نشان داده می‌شود
function site_slip_make($pdo, $kind, $refId, $planLabel = '') {
    if (PHP_SAPI !== 'cli' && empty($GLOBALS['SITE_SLIP_NOW'])) { site_slip_later($pdo, $kind, $refId, $planLabel); return 0; }
    return site_slip_make_now($pdo, $kind, $refId, $planLabel);
}
function site_slip_later($pdo, $kind, $refId, $planLabel) {
    static $queue = null;
    $first = $queue === null;
    if ($first) $queue = [];
    $queue[$kind . ':' . intval($refId)] = [$kind, intval($refId), $planLabel];
    if (!$first) return;
    register_shutdown_function(function () use ($pdo, &$queue) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true);
        @set_time_limit(600);
        foreach ($queue as [$k, $id, $label]) {
            // یک بیمه‌نامه هم‌زمان دو بار ساخته نشود (مثلاً دو کلیکِ پشتِ سرِ هم)
            $lock = @fopen(rtrim(sys_get_temp_dir(), '/') . '/bime_slip_' . $k . '_' . $id . '.lock', 'c');
            if ($lock) @flock($lock, LOCK_EX);
            try { site_slip_make_now($pdo, $k, $id, $label); } catch (Throwable $e) { error_log('[site_slip_later] ' . $e->getMessage()); }
            if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
    });
}
// چند قسط هنوز فیشِ سامانه‌ی ساخته‌نشده دارند؟ (برای «در حالِ ساخت» در پنل)
function site_slip_pending($pdo, $kind, $refId) {
    [$t, $fk] = site_slip_table($kind);
    try {
        $st = $pdo->prepare("SELECT slip_json FROM `$t` WHERE `$fk` = ? AND (slip_file IS NULL OR slip_file = '')");
        $st->execute([intval($refId)]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) { $j = json_decode((string)$j, true); if (!empty($j['site'])) $n++; }
        return $n;
    } catch (Throwable $e) { return 0; }
}
function site_slip_make_now($pdo, $kind, $refId, $planLabel = '') {
    [$t, $fk] = site_slip_table($kind);
    try {
        $st = $pdo->prepare("SELECT id, inst_number, amount, due_jalali, tracking_code, slip_json, slip_file FROM `$t` WHERE `$fk` = ? ORDER BY inst_number");
        $st->execute([intval($refId)]);
        $insts = $st->fetchAll();
        $todo = array_filter($insts, function ($i) { $j = json_decode((string)$i['slip_json'], true); return !empty($j['site']) && empty($i['slip_file']); });
        if (!$todo) return 0;
        site_slip_move_originals($pdo, $kind, $refId);   // فیش‌های بیمه‌نامه => «فیش‌های قدیمی»
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
    $money = function ($v) use ($e) { return $e(number_format((int)$v)); };
    $cnt = count($all);
    $fish = (string)($slip['fish'] ?? '');
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 8, 10); $pdf->SetAutoPageBreak(true, 8);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->setRTL(true); $pdf->SetFont('vazir', '', 9);
    $pdf->AddPage();
    // دو نسخه‌ی هم‌شکل (پرداخت‌کننده / بایگانی) با خطِ برش، مثلِ «سند دریافت»ِ بیمه‌گر و فیش‌های بانکی (همه با جدول، برای راست‌چینِ درست)
    $kv = function ($k, $v, $ltr = false) use ($e) {
        return '<td width="18%" style="color:#64748b;font-size:7.5pt;background-color:#f8fafc;border-bottom:0.3px solid #e2e8f0">' . $k . '</td>'
             . '<td width="32%" style="font-size:8.5pt;border-bottom:0.3px solid #e2e8f0"><b' . ($ltr ? ' dir="ltr"' : '') . '>' . $e($v === '' || $v === null ? '—' : $v) . '</b></td>';
    };
    $copy = function ($title) use ($e, $money, $kv, $ctx, $inst, $cnt, $slip, $fish, $planLabel) {
        $rows = [
            [['بیمه‌گذار / پرداخت‌کننده', $ctx['insured']], ['کد ملی / شناسه', $ctx['nid'], true]],
            [['شماره بیمه‌نامه', $ctx['policy'], true], ['شرکت', $ctx['company']]],
            [['پلاک / خودرو', trim($ctx['plate'] . ($ctx['car'] !== '' ? ' · ' . $ctx['car'] : ''))], ['نوعِ پرداخت', $slip['kind'] ?? 'اقساط']],
            [['شناسه پرداخت', $slip['shenase'] ?? '', true], ['شماره حساب', $slip['account'] ?? '', true]],
            [['بانک / شعبه', $slip['bank'] ?? ''], ['قرارداد', $planLabel]],
        ];
        $info = '';
        foreach ($rows as $pr) $info .= '<tr>' . $kv(...$pr[0]) . $kv(...$pr[1]) . '</tr>';
        return '<table cellpadding="0" cellspacing="0" style="border:0.6px solid #c7d2fe;">'
            . '<tr><td style="background-color:#1e3a8a;color:#ffffff;" width="100%"><table cellpadding="5"><tr>'
            . '<td width="66%"><b style="font-size:14pt">سندِ دریافت · فیشِ پرداختِ قسط</b><br/><span style="font-size:8.5pt;color:#c7d2fe">بیمه‌نامه‌ی ' . $e($ctx['type_fa']) . ' · قسطِ ' . $e($inst['inst_number']) . ' از ' . $e($cnt) . '</span></td>'
            . '<td width="34%" style="background-color:#ffffff;color:#1e3a8a;text-align:center"><span style="font-size:7pt;color:#64748b">' . $e($title) . '</span><br/><b style="font-size:11pt">شماره فیش</b><br/><b style="font-size:12pt" dir="ltr">' . $e($fish ?: '—') . '</b></td>'
            . '</tr></table></td></tr>'
            . '<tr><td style="background-color:#0ea5e9;font-size:2pt"> </td></tr>'
            . '<tr><td><table cellpadding="6"><tr>'
            . '<td width="62%" style="background-color:#eef2ff;text-align:center"><span style="font-size:8pt;color:#475569">مبلغِ قابلِ پرداخت</span><br/><b style="font-size:17pt;color:#1e3a8a"><span dir="ltr">' . $money($inst['amount']) . '</span> ریال</b><br/><span style="font-size:7pt;color:#64748b">' . $e(fin_num_to_words((int)$inst['amount'])) . ' ریال</span></td>'
            . '<td width="38%" style="background-color:#fef3c7;text-align:center"><span style="font-size:8pt;color:#92400e">مهلتِ پرداخت (سررسید)</span><br/><b style="font-size:15pt;color:#92400e">' . $e($inst['due_jalali']) . '</b></td>'
            . '</tr></table></td></tr>'
            . '<tr><td><table cellpadding="3">' . $info . '</table></td></tr>'
            . '<tr><td><table cellpadding="4"><tr><td style="font-size:7pt;color:#64748b">این فیش طبقِ اقساطِ قراردادِ ثبت‌شده در سامانه ساخته شده است. پس از پرداخت، تصویرِ رسیدِ بانکی را با شماره‌ی فیش برای کارشناس بفرستید. تاریخِ ساخت: ' . $e(jalali_from_gregorian_ts_dotted(time())) . '</td></tr></table></td></tr>'
            . '</table>';
    };
    $h = $copy('نسخه‌ی پرداخت‌کننده')
       . '<table cellpadding="4"><tr><td style="text-align:center;color:#94a3b8;font-size:7pt;border-bottom:0.4px dashed #94a3b8">- - - محلِ برش - - -</td></tr></table><br/>'
       . $copy('نسخه‌ی بایگانی') . '<br/>';
    // جدولِ همه‌ی اقساط؛ قسطِ همین فیش پررنگ
    $h .= '<b style="font-size:8pt;color:#334155">جدولِ اقساطِ این بیمه‌نامه</b><table cellpadding="2.2" style="font-size:7pt;border:0.3px solid #e2e8f0;"><tr style="background-color:#f1f5f9;font-weight:bold;"><td width="8%">قسط</td><td width="17%">سررسید</td><td width="25%">مبلغ (ریال)</td><td width="8%">قسط</td><td width="17%">سررسید</td><td width="25%">مبلغ (ریال)</td></tr>';
    $half = (int)ceil($cnt / 2);
    $hi = function ($x) use ($inst) { return $x && intval($x['id']) === intval($inst['id']) ? 'background-color:#fef3c7;font-weight:bold;' : ''; };
    for ($k = 0; $k < $half; $k++) {
        $a = $all[$k]; $b = $all[$k + $half] ?? null;
        $h .= '<tr><td style="' . $hi($a) . '">' . $e($a['inst_number']) . '</td><td style="' . $hi($a) . '">' . $e($a['due_jalali']) . '</td><td style="' . $hi($a) . '">' . $money($a['amount']) . '</td>'
            . ($b ? '<td style="' . $hi($b) . '">' . $e($b['inst_number']) . '</td><td style="' . $hi($b) . '">' . $e($b['due_jalali']) . '</td><td style="' . $hi($b) . '">' . $money($b['amount']) . '</td>' : '<td></td><td></td><td></td>') . '</tr>';
    }
    $h .= '</table>';
    $pdf->writeHTML($h, true, false, true, false, '');
    $pdf->Output($dest, 'F');
    return is_file($dest);
}

// فیش‌های داخلِ فایلِ بیمه‌نامه وقتی با اقساطِ قرارداد یکی نیستند: به پوشه‌ی «فیش‌های قدیمی» (کنارِ «فیش‌های پرداختی») با نامِ «فیش قدیمی (سررسید)»
// می‌روند تا با فیش‌های سامانه قاطی نشوند؛ مسیرِ تازه در receipts_json نوشته می‌شود (کپیِ «بایگانی صادره» هم همین‌طور)
function site_slip_move_originals($pdo, $kind, $refId) {
    $t = $kind === 'C' ? 'company_request_plates' : 'policy_cases';
    $root = dirname(__DIR__);
    try {
        $st = $pdo->prepare("SELECT receipts_json FROM `$t` WHERE id = ?");
        $st->execute([intval($refId)]);
        $rc = json_decode((string)$st->fetchColumn(), true);
        if (!is_array($rc) || !$rc) return 0;
        $ctx = site_slip_context($pdo, $kind, $refId);
        $n = 0;
        foreach ($rc as &$r) {
            $f = (string)($r['file'] ?? '');
            if ($f === '' || mb_strpos($f, '/فیش‌های قدیمی/') !== false) continue;
            $abs = $root . '/' . ltrim($f, '/');
            if (!is_file($abs)) continue;
            $oldDir = dirname(dirname($abs)) . '/فیش‌های قدیمی';
            if (basename(dirname($abs)) !== 'فیش‌های پرداختی') $oldDir = dirname($abs) . '/فیش‌های قدیمی';
            if (!is_dir($oldDir) && !@mkdir($oldDir, 0755, true) && !is_dir($oldDir)) continue;
            $due = !empty($r['date']) ? str_replace('/', '.', p2e_digits((string)$r['date'])) : ('قسط ' . intval($r['n'] ?? 0));
            $dest = unique_dest_path($oldDir . '/' . sanitize_folder_name('فیش قدیمی (' . $due . ')') . '.pdf');
            if (!@rename($abs, $dest)) continue;
            // کپیِ «بایگانی صادره»
            if (!empty($ctx['sadere'])) {
                $sOld = $ctx['sadere'] . '/' . basename($abs);
                if (is_file($sOld)) { $sd = dirname($ctx['sadere']) . '/فیش‌های قدیمی'; if (is_dir($sd) || @mkdir($sd, 0755, true)) @rename($sOld, $sd . '/' . basename($dest)); }
            }
            $r['file'] = ltrim(str_replace($root, '', $dest), '/');
            $r['old'] = 1;
            $n++;
        }
        unset($r);
        if ($n) $pdo->prepare("UPDATE `$t` SET receipts_json = ? WHERE id = ?")->execute([json_encode($rc, JSON_UNESCAPED_UNICODE), intval($refId)]);
        return $n;
    } catch (Throwable $e) { error_log('[site_slip_move_originals] ' . $e->getMessage()); return 0; }
}
