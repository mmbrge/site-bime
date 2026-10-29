<?php
// فایل: api/inquiry_actions.php
// پنل ← «استعلامات»: استعلامِ تکی (شخص حقیقی، شخص + کد پستی، کد پستی، شخص حقوقی، سنهاب)، استعلامِ گروهی با اکسل،
// تاریخچه و خروجی، و تنظیمات (فقط مدیر کل: نام کاربری/رمزِ پاسارگاد و سنهاب، نگه‌داشتنِ نشست، صف و کش).
// استعلام از داخلِ فرم‌ها (ثبتِ درخواست، کد پستی، تعریفِ شرکت، سنهابِ ردیف‌های ثالث) هم از همین‌جا می‌آید (source = form).
// منطق: api/_inquiry.php و api/_sanhab.php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_inquiry.php';
require_once __DIR__ . '/_sanhab.php';
require_once __DIR__ . '/_pbody.php';   // استعلامِ بدنه‌ی بیمه مرکزی و خسارت (پاسارگاد)
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };

// ---- نگه‌داشتنِ نشستِ پاسارگاد + اجرای صفِ گروهی در پس‌زمینه (cron، با کلید؛ بدونِ ورود به پنل) ----
if (($_GET['action'] ?? '') === 'keepalive') {
    inq_ensure($pdo);
    $key = inq_get_setting($pdo, 'inq_cron_key');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) { http_response_code(403); $out(['ok' => false]); }
    @set_time_limit(280); @ignore_user_abort(true);
    // فقط تا «inq_keep_minutes» دقیقه بعد از آخرین استعلام/ورود نشست باز نگه داشته می‌شود؛ بعد از آن رها می‌شود تا خودش بسته شود
    $keep = max(5, intval(inq_get_setting($pdo, 'inq_keep_minutes')));
    $idle = time() - inq_last_activity();
    $pending = intval($pdo->query("SELECT COUNT(*) FROM inq_requests r JOIN inq_batches b ON b.id = r.batch_id WHERE b.status = 'RUNNING' AND r.status = 'QUEUED' AND r.kind <> 'sanhab'")->fetchColumn());
    // نشستِ سنهاب هم (اگر در همین مدت استعلامی شده) باز نگه داشته می‌شود
    $sh = time() - sh_last_activity() <= $keep * 60 ? sh_status($pdo, true) : 'idle';
    if ($idle > $keep * 60 && !$pending) $out(['ok' => true, 'state' => 'idle', 'idle_min' => intdiv($idle, 60), 'sanhab' => $sh]);
    $s = inq_px_status($pdo, true);
    $ran = 0; $t0 = time();
    if ($s === 'logged_in') while (time() - $t0 < 200) { $r = inq_batch_tick($pdo); if (!$r['ran']) break; $ran++; }
    $out(['ok' => true, 'state' => $s, 'ran' => $ran, 'sanhab' => $sh]);
}

session_start();
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$in = $in + $_POST + $_GET;
$action = (string)($in['action'] ?? '');
$uid = intval($_SESSION['user_id']);
inq_ensure($pdo);
sh_ensure($pdo);

$isAdmin = perm_real_role() === 'ADMIN';
$custom = $isAdmin ? null : perm_user_custom($pdo, $uid);
// دسترسیِ هر بخش: مدیر کل همه؛ کاربرِ سفارشی طبقِ «دسترسی‌ها»؛ بقیه فقط استعلامِ داخلِ فرم‌ها
$can = function ($what) use ($isAdmin, $custom) {
    if ($isAdmin) return true;
    $forms = 'records:create|records:edit|cases:edit|companies-requests:create|companies-requests:edit|issue-queue:edit';
    $spec = ['single' => 'inq-single:create', 'single_view' => 'inq-single:view|inq-single:create', 'group' => 'inq-group:create', 'group_view' => 'inq-group:view|inq-group:create',
             'legal' => 'inq-legal:create', 'legal_group' => 'inq-legal-group:create', 'legal_group_view' => 'inq-legal-group:view|inq-legal-group:create',
             'sanhab' => 'inq-sanhab:create', 'sanhab_group' => 'inq-sanhab-group:create', 'sanhab_group_view' => 'inq-sanhab-group:view|inq-sanhab-group:create',
             'premium' => 'inq-premium:view|inq-premium:create', 'ocr' => 'inq-ocr:create|inq-ocr:view',
             'export' => 'inq-history:export|inq-group:export|inq-legal-group:export|inq-sanhab-group:export|inq-pbody-group:export', 'history' => 'inq-history:view', 'delete' => 'inq-history:delete',
             'pbody' => 'inq-pbody:create', 'pbody_form' => 'inq-pbody:create|' . $forms,
             'pbody_group' => 'inq-pbody-group:create', 'pbody_group_view' => 'inq-pbody-group:view|inq-pbody-group:create',
             'form' => 'inq-single:create|' . $forms, 'sanhab_form' => 'inq-sanhab:create|' . $forms, 'legal_form' => 'inq-legal:create|companies-manage:create|companies-manage:edit'][$what] ?? '';
    // کاربرِ بدونِ دسترسیِ سفارشی: فقط استعلام‌های داخلِ فرم‌ها (همان قاعده‌ی قبلی)
    if ($custom === null) return in_array($what, ['form', 'sanhab_form', 'pbody_form'], true);
    return perm_allows($custom, $spec);
};
$need = function ($what) use ($can, $out) { if (!$can($what)) $out(['ok' => false, 'error' => 'به این بخشِ استعلامات دسترسی ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']); };
$mine = function ($row) use ($isAdmin, $uid, $can) { return $isAdmin || $can('history') || (($row['user_type'] ?? '') === 'STAFF' && intval($row['user_id']) === $uid); };

if ($action === 'boot') {
    $st = $isAdmin ? inq_px_status($pdo) : null;
    // شخصِ حقوقی (شناسه‌ی ملیِ شرکت) دیگر استعلام نمی‌شود؛ فقط در تاریخچه می‌ماند
    // انواعِ استعلامِ تکی (شخصِ حقیقی / حقوقی / سنهاب) به تفکیکِ دسترسی
    $kinds = [];
    foreach (INQ_KINDS as $k => $fa) if ($k !== 'ocr' && $k !== 'pdamage' && ($k === 'legal' ? $can('legal') : ($k === 'sanhab' ? $can('sanhab') : ($k === 'pbody' ? $can('pbody') : $can('single'))))) $kinds[$k] = $fa;
    $gkinds = [];
    foreach (INQ_KINDS as $k => $fa) if ($k !== 'ocr' && ($k === 'legal' ? $can('legal_group_view') : ($k === 'sanhab' ? $can('sanhab_group_view') : (in_array($k, ['pbody', 'pdamage'], true) ? $can('pbody_group_view') : $can('group_view'))))) $gkinds[$k] = $fa;
    $o = ['ok' => true, 'is_admin' => $isAdmin, 'kinds' => $kinds, 'group_kinds' => $gkinds, 'kinds_all' => INQ_KINDS, 'status' => $st,
          'sh_status' => $isAdmin ? sh_status($pdo) : null, 'sh_ready' => sh_enabled($pdo) && sh_creds($pdo)['user'] !== '', 'sh_types' => array_map(null, array_map('strval', array_keys(SH_TYPES)), array_values(SH_TYPES)), 'sh_plcy' => SH_PLCY,
          'can' => ['single' => $can('single'), 'group' => $can('group'), 'group_view' => (bool)$gkinds, 'export' => $can('export'), 'history' => $can('history'), 'delete' => $can('delete'), 'form' => $can('form'),
                    'legal' => $can('legal'), 'legal_group' => $can('legal_group'), 'sanhab' => $can('sanhab'), 'sanhab_group' => $can('sanhab_group'), 'premium' => $can('premium'), 'sanhab_form' => $can('sanhab_form'),
                    'ocr' => $can('ocr'), 'pbody' => $can('pbody'), 'pbody_form' => $can('pbody_form'), 'pbody_group' => $can('pbody_group')],
          'pb_ready' => pb_enabled($pdo) && inq_creds($pdo)['user'] !== '', 'pb_types' => PB_TYPES, 'pb_ins' => PB_INS,
          'ocr_engines' => (function () use ($pdo) { require_once __DIR__ . '/_smart_ocr.php'; return so_engines($pdo); })(),
          'ocr_order' => so_order($pdo), 'ocr_types' => (function () { require_once __DIR__ . '/_doc_smart.php'; return array_map(function ($x) { return $x[0]; }, ds_types()); })(), 'ocr_format' => so_cfg($pdo)['format'], 'ocr_conc' => so_cfg($pdo)['conc'],
          'sapi_ready' => (function () use ($pdo) { require_once __DIR__ . '/_apis.php'; return sapi_ready($pdo, 'sapi_company'); })()];
    $q = $pdo->query("SELECT COUNT(*) n, SUM(status = 'OK') ok, SUM(status = 'FAIL') fail, SUM(status IN ('QUEUED', 'RUNNING')) q FROM inq_requests WHERE created_at >= CURDATE()")->fetch();
    $o['today'] = ['total' => intval($q['n']), 'ok' => intval($q['ok']), 'fail' => intval($q['fail']), 'queue' => intval($q['q'])];
    $mq = inq_user_limit_info($pdo, 'STAFF', $uid);
    $o['my_quota'] = ['limit' => $mq['limit'], 'used' => $mq['used'], 'nid_limit' => (int)inq_limit_value(inq_get_setting($pdo, 'inq_nid_daily_limit'))];
    $out($o);
}

// ---- استعلامِ تکی / داخلِ فرم ----
if ($action === 'create') {
    $kind = (string)($in['kind'] ?? '');
    $src = ($in['source'] ?? '') === 'form' ? 'form' : 'panel';
    if ($kind === 'legal') $need($src === 'form' ? 'legal_form' : 'legal');
    else $need($src === 'form' ? 'form' : 'single');
    $r = inq_create($pdo, $kind, $in, $src, null, null, null, empty($in['fresh']) || !$isAdmin);
    if (!$r['ok']) $out($r);
    $row = inq_get($pdo, $r['id']);
    $out(['ok' => true, 'id' => $r['id'], 'cached' => $r['cached'], 'item' => inq_out($row, $isAdmin)]);
}
if ($action === 'exec') {
    $row = inq_get($pdo, $in['id'] ?? 0);
    if (!$row || !$mine($row)) $out(['ok' => false, 'error' => 'استعلام پیدا نشد.']);
    // شخصِ حقوقی: کاربر به‌جای کپچای پاسارگاد «استعلام از سرویسِ API» را زد
    if (!empty($in['via_api']) && $row['kind'] === 'legal' && $row['status'] === 'QUEUED')
        $pdo->prepare("UPDATE inq_requests SET result_json = ? WHERE id = ?")->execute([json_encode(['via_api' => 1] + (json_decode((string)$row['result_json'], true) ?: []), JSON_UNESCAPED_UNICODE), intval($row['id'])]);
    $r = $row['status'] === 'QUEUED' ? inq_execute($pdo, intval($row['id'])) : [];
    $o = ['ok' => true, 'item' => inq_out(inq_get($pdo, $row['id']), $isAdmin)];
    // نشستِ سامانه بسته بود: تصویرِ امنیتی برای همین کاربر (زیرِ فیلدها) و بعد از واردکردنش همین استعلام دوباره اجرا می‌شود
    if (!empty($r['need_captcha'])) {
        $c = inq_captcha_get($pdo); $o['need_captcha'] = true; $o['captcha'] = $c['image'] ?? null; if (!$c['ok']) $o['captcha_error'] = $c['error'] ?? '';
        if ($row['kind'] === 'legal') { require_once __DIR__ . '/_apis.php'; $o['api_alt'] = sapi_ready($pdo, 'sapi_company'); }
    }
    $out($o);
}
// ---- کپچا (هر کاربری که استعلام می‌زند) ----
if ($action === 'captcha' || $action === 'captcha_login') {
    if (!$can('form') && !$can('single') && !$can('group') && !$can('legal') && !$can('legal_form') && !$can('legal_group')) $out(['ok' => false, 'error' => 'دسترسی ندارید.']);
    if ($action === 'captcha') $out(inq_captcha_get($pdo, !empty($in['refresh'])));
    $out(inq_captcha_login($pdo, $in['captcha'] ?? ''));
}
if ($action === 'get') {
    $row = inq_get($pdo, $in['id'] ?? 0);
    if (!$row || !$mine($row)) $out(['ok' => false, 'error' => 'استعلام پیدا نشد.']);
    if ($row['kind'] === 'ocr') { require_once __DIR__ . '/_smart_ocr.php'; [$pg, $lg] = so_pages_of($row); $out(['ok' => true, 'item' => inq_out($row, false) + ['query' => $row['query_text'], 'pages' => $pg, 'lang' => $lg]]); }
    $out(['ok' => true, 'item' => $row['kind'] === 'sanhab' ? sh_out($row) : (in_array($row['kind'], ['pbody', 'pdamage'], true) ? pb_out($row) + ['step_pages' => null] : inq_out($row, $isAdmin))]);
}
// صفحه‌ی واقعیِ سامانه در هر گام (فقط مدیر کل؛ بدونِ اجرای اسکریپت)
if ($action === 'step_page') {
    if (!$isAdmin) { http_response_code(403); exit; }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src https: data:; style-src 'unsafe-inline' https:; font-src https: data:");
    header('X-Frame-Options: SAMEORIGIN');
    echo inq_step_html($in['id'] ?? 0, $in['n'] ?? 1);
    exit;
}

// ---- تاریخچه ----
function inq_hist_where($pdo, array $in, $all, $uid) {
    $w = ['1=1']; $a = [];
    if (!$all) { $w[] = "user_type = 'STAFF' AND user_id = ?"; $a[] = $uid; }
    if (!empty($in['kind']) && isset(INQ_KINDS[$in['kind']])) { $w[] = 'kind = ?'; $a[] = $in['kind']; }
    if (!empty($in['status']) && isset(INQ_ST_FA[$in['status']])) { $w[] = 'status = ?'; $a[] = $in['status']; }
    if (!empty($in['source'])) { $w[] = 'source = ?'; $a[] = (string)$in['source']; }
    if (!empty($in['batch_id'])) { $w[] = 'batch_id = ?'; $a[] = intval($in['batch_id']); }
    if (($q = trim((string)($in['q'] ?? ''))) !== '') {
        $qd = inq_digits($q);
        $w[] = '(person_name LIKE ? OR father_name LIKE ? OR user_name LIKE ? OR query_text LIKE ?' . ($qd !== '' ? ' OR national_id LIKE ? OR postal LIKE ?' : '') . ')';
        array_push($a, "%$q%", "%$q%", "%$q%", "%$q%"); if ($qd !== '') array_push($a, "%$qd%", "%$qd%");
    }
    return [implode(' AND ', $w), $a];
}
if ($action === 'history') {
    $all = $isAdmin || $can('history');
    [$w, $a] = inq_hist_where($pdo, $in, $all, $uid);
    $page = max(1, intval($in['page'] ?? 1)); $per = 50;
    $st = $pdo->prepare("SELECT COUNT(*) FROM inq_requests WHERE $w"); $st->execute($a); $total = intval($st->fetchColumn());
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($a);
    $ocrInfo = function ($r) {   // پردازش فایل: تعدادِ صفحه، موتورها، فایل‌های i2pdf
        if ($r['kind'] !== 'ocr') return [];
        require_once __DIR__ . '/_smart_ocr.php';
        [$pg] = so_pages_of($r);
        $eng = array_values(array_unique(array_filter(array_map(function ($p) { return SO_ENGINES[$p['engine'] ?? ''] ?? ($p['engine'] ?? ''); }, $pg))));
        return ['ocr_info' => ['pages' => count($pg), 'engines' => $eng, 'files' => (bool)array_filter($pg, function ($p) { return !empty($p['files']); })]];
    };
    $out(['ok' => true, 'items' => array_map(function ($r) use ($isAdmin, $ocrInfo) { return ($r['kind'] === 'sanhab' ? sh_out($r, false) : (in_array($r['kind'], ['pbody', 'pdamage'], true) ? array_diff_key(pb_out($r), ['pbody' => 1, 'pdamage' => 1]) : inq_out($r, false))) + $ocrInfo($r) + ($isAdmin ? ['has_detail' => true] : []); }, $st->fetchAll()),
          'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per)), 'all' => $all]);
}
if ($action === 'history_export' || $action === 'batch_export') {
    $need('export');
    require_once __DIR__ . '/_xlsx_writer.php';
    $all = $isAdmin || $can('history');
    if ($action === 'batch_export') { $in['batch_id'] = intval($in['id'] ?? 0); $all = true; }
    [$w, $a] = inq_hist_where($pdo, $in, $all, $uid);
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE $w ORDER BY " . ($action === 'batch_export' ? 'row_no, id' : 'id DESC') . " LIMIT 20000"); $st->execute($a);
    $all = $st->fetchAll();
    // گروهِ بدنه / خسارتِ پاسارگاد
    if ($action === 'batch_export' && $all && count(array_unique(array_column($all, 'kind'))) === 1 && in_array($all[0]['kind'], ['pbody', 'pdamage'], true)) {
        $b = inq_batch_info($pdo, $in['batch_id']);
        xlsx_send(pb_batch_export($pdo, $in['batch_id'], $all, $all[0]['kind']), 'نتیجه‌ی ' . ($b['title'] ?? 'استعلامِ گروهی') . ' ' . jdate_short(date('Y-m-d')) . '.xlsx');
        exit;
    }
    // فقط سنهاب: ستون‌های سوابقِ بیمه‌نامه
    if ($all && !array_filter($all, function ($r) { return $r['kind'] !== 'sanhab'; })) {
        $rows = [];
        foreach ($all as $r) {
            $o = sh_out($r, false); $b = $o['sanhab'] ?: [];
            $rows[] = [$r['row_no'] ?: $o['id'], $o['created_j'], $o['sh_type_fa'], $o['query'], $o['status_fa'], $b['insurer'] ?? '', $b['policy_type'] ?? '', $b['policy_no'] ?? '', $b['start_date'] ?? '', $b['end_date'] ?? '',
                       $b['insured'] ?? '', $b['vehicle'] ?? '', $b['plate'] ?? '', $b['loss_status'] ?? '', isset($b['tp_disc']) ? $b['tp_disc'] . '٪' : '', isset($b['dr_disc']) ? $b['dr_disc'] . '٪' : '',
                       $b['older_losses'] ?? '', $r['notice'], $r['user_name']];
        }
        $path = xlsx_build(['ردیف', 'زمان', 'جستجو با', 'مقدار', 'وضعیت', 'بیمه‌گر', 'نوعِ بیمه‌نامه', 'شماره بیمه‌نامه', 'شروع', 'انقضا', 'بیمه‌گذار', 'خودرو', 'پلاک',
                            'خسارت', 'تخفیف ثالث', 'تخفیف حوادث راننده', 'خسارتِ سال‌های قبل', 'نتیجه', 'کاربر'], $rows, 'سنهاب', [0]);
        xlsx_send($path, ($action === 'batch_export' ? 'نتیجه‌ی استعلامِ گروهیِ سنهاب' : 'تاریخچه‌ی استعلامِ سنهاب') . ' ' . jdate_short(date('Y-m-d')) . '.xlsx');
        exit;
    }
    $rows = [];
    foreach ($all as $r) {
        $o = inq_out($r, false);
        $rows[] = [$r['row_no'] ?: $o['id'], $o['created_j'], $o['kind_fa'], $r['national_id'] ?: ($r['query_text'] ?? ''), $r['birth'], $r['postal'], $o['status_fa'], $r['person_name'], $r['father_name'],
                   $o['person']['gender'] ?? '', $r['postal_valid'] === null ? '' : ((int)$r['postal_valid'] ? 'تأیید' : 'عدمِ تأیید'), $r['postal_text'],
                   $o['company'] ? trim(($o['company']['company_type'] ?? '') . ' · ' . ($o['company']['register_number'] ?? '') . ' · ' . ($o['company']['city'] ?? '')) : '', $r['notice'], $r['user_name']];
    }
    $path = xlsx_build(['ردیف', 'زمان', 'نوع', 'کد/شناسه ملی', 'تاریخ تولد', 'کد پستی', 'وضعیت', 'نام', 'نام پدر', 'جنسیت', 'کد پستی', 'پیامِ کد پستی', 'شخص حقوقی', 'توضیح', 'کاربر'], $rows, 'استعلامات', [0]);
    xlsx_send($path, ($action === 'batch_export' ? 'نتیجه‌ی استعلامِ گروهی' : 'تاریخچه‌ی استعلامات') . ' ' . jdate_short(date('Y-m-d')) . '.xlsx');
    exit;
}
if ($action === 'delete') {
    $need('delete');
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [$in['id'] ?? 0]))));
    if (!$ids) $out(['ok' => false, 'error' => 'موردی انتخاب نشده.']);
    $pdo->prepare("DELETE FROM inq_requests WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND status NOT IN ('RUNNING')")->execute($ids);
    foreach ($ids as $id) { $d = __DIR__ . '/data/inq/jobs/' . $id; if (is_dir($d)) { foreach (glob($d . '/*') ?: [] as $f) @unlink($f); @rmdir($d); } }
    $out(['ok' => true]);
}

// ---- گروهی ----
if ($action === 'batch_sample') {
    if (!$can('group_view') && !$can('legal_group_view')) $out(['ok' => false, 'error' => 'دسترسی ندارید.']);
    require_once __DIR__ . '/_xlsx_writer.php';
    $kind = isset(INQ_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'person_postal';
    if ($kind === 'legal') $path = xlsx_build(['نام شرکت', 'شناسه ملی', 'کد اقتصادی'], [['شرکت نمونه', '10101234567', ''], ['', '14001234567', ''], ['بازرگانی نمونه', '', '']], 'شخص حقوقی');
    else $path = xlsx_build(['کد ملی', 'تاریخ تولد', 'کد پستی', 'نام (اختیاری)'], [['0012345678', '1370/05/12', '1234567890', 'علی رضایی'], ['0098765432', '1365/11/03', '9876543210', '']], 'اشخاص');
    xlsx_send($path, 'نمونه‌ی استعلامِ گروهی - ' . INQ_KINDS[$kind] . '.xlsx');
    exit;
}
if ($action === 'batch_upload') {
    $kind = (string)($in['kind'] ?? '');
    if (!isset(INQ_KINDS[$kind]) || in_array($kind, ['sanhab', 'pbody', 'pdamage'], true)) $out(['ok' => false, 'error' => 'نوعِ استعلام را انتخاب کنید.']);
    $need($kind === 'legal' ? 'legal_group' : 'group');
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $out(['ok' => false, 'error' => 'فایلِ اکسل دریافت نشد.']);
    $ext = upload_ext($f['name']);
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) $out(['ok' => false, 'error' => 'فقط فایلِ اکسل (xlsx) یا CSV.']);
    $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.' . $ext;
    move_uploaded_file($f['tmp_name'], $tmp);
    $r = inq_excel_rows($tmp, $kind);
    @unlink($tmp);
    if (!$r['ok']) $out($r);
    if (!empty($in['preview'])) $out(['ok' => true, 'rows' => array_slice($r['rows'], 0, 2000), 'count' => count($r['rows'])]);
    $b = inq_batch_create($pdo, $kind, trim((string)($in['title'] ?? '')) ?: ('استعلامِ گروهی ' . $f['name']), $r['rows'], $f['name']);
    $out($b + ['batch' => inq_batch_info($pdo, $b['batch_id'])]);
}
// ---- گروهیِ بدنه و خسارتِ پاسارگاد (api/_pbody.php): اکسل ← صف ← همان نشستِ ورود (از پنل یا cron در پس‌زمینه) ----
if ($action === 'pbg_sample') {
    $need('pbody_group_view');
    $kind = ($in['kind'] ?? '') === 'pdamage' ? 'pdamage' : 'pbody';
    $t = isset(PB_TYPES[(string)($in['type'] ?? '')]) ? (string)$in['type'] : 'chassis';
    xlsx_send(pb_batch_sample($kind, $t), 'نمونه‌ی استعلامِ گروهیِ ' . ($kind === 'pdamage' ? 'خسارت' : 'بدنه - ' . PB_TYPES[$t]) . '.xlsx');
    exit;
}
if ($action === 'pbg_upload') {
    $need('pbody_group');
    if (!pb_enabled($pdo)) $out(['ok' => false, 'error' => 'استعلامِ بدنه/خسارتِ پاسارگاد در تنظیمات خاموش است.']);
    $kind = ($in['kind'] ?? '') === 'pdamage' ? 'pdamage' : 'pbody';
    $opt = ['type' => isset(PB_TYPES[(string)($in['type'] ?? '')]) ? (string)$in['type'] : 'chassis', 'ins' => isset(PB_INS[(string)($in['ins'] ?? '')]) ? (string)$in['ins'] : 'both',
            'out' => ($in['out'] ?? '') === 'shot' ? 'shot' : 'pdf', 'want_print' => !empty($in['want_print'])];
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $out(['ok' => false, 'error' => 'فایلِ اکسل دریافت نشد.']);
    $ext = upload_ext($f['name']);
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) $out(['ok' => false, 'error' => 'فقط فایلِ اکسل (xlsx) یا CSV.']);
    $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.' . $ext;
    move_uploaded_file($f['tmp_name'], $tmp);
    $r = pb_excel_rows($tmp, $kind, $opt['type']);
    @unlink($tmp);
    if (!$r['ok']) $out($r);
    $labels = array_map(function ($x) use ($kind, $opt) { $v = pb_batch_validate($kind, $opt, $x); return $v['ok'] ? $v['label'] : ('⚠️ ' . $v['error']); }, $r['rows']);
    if (!empty($in['preview'])) $out(['ok' => true, 'rows' => array_slice($labels, 0, 1000), 'count' => count($labels)]);
    $b = pb_batch_create($pdo, $kind, $opt, trim((string)($in['title'] ?? '')) ?: (($kind === 'pdamage' ? 'خسارت ' : 'بدنه ') . $f['name']), $r['rows'], $f['name']);
    $out($b + ['batch' => inq_batch_info($pdo, $b['batch_id'])]);
}
if ($action === 'pbg_zip') {
    $need('pbody_group_view');
    $bid = intval($in['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE batch_id = ? AND kind IN ('pbody', 'pdamage') AND status IN ('OK', 'WARN') ORDER BY row_no, id");
    $st->execute([$bid]);
    $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.zip';
    $stamp = !empty($in['stamp']);
    if (!pb_batch_zip($st->fetchAll(), $tmp, $stamp)) $out(['ok' => false, 'error' => 'هنوز فایلی برای این گروه ساخته نشده.']);
    $b = inq_batch_info($pdo, $bid);
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"pbody.zip\"; filename*=UTF-8''" . rawurlencode(preg_replace('/[\\\\\/:*?"<>|]+/u', '-', (string)($b['title'] ?? 'استعلام')) . ' - فایل‌ها' . ($stamp ? ' (با مهر)' : '') . '.zip'));
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp);
    exit;
}
$anyGroup = function () use ($can, $out) { if (!$can('group') && !$can('legal_group') && !$can('sanhab_group') && !$can('pbody_group')) $out(['ok' => false, 'error' => 'به استعلامِ گروهی دسترسی ندارید.']); };
if ($action === 'batch_step') {
    $anyGroup();
    $bid = intval($in['id'] ?? 0);
    $r = inq_batch_tick($pdo, $bid);
    if (!empty($r['need_captcha'])) { $c = inq_captcha_get($pdo); $out(['ok' => true, 'need_captcha' => true, 'captcha' => $c['image'] ?? null, 'batch' => inq_batch_info($pdo, $bid)]); }
    $last = $r['ran'] ? inq_out(inq_get($pdo, $r['ran']), false) : null;
    $out(['ok' => true, 'batch' => inq_batch_info($pdo, $bid), 'last' => $last]);
}
if ($action === 'batches') {
    if (!$can('group_view') && !$can('legal_group_view') && !$can('sanhab_group_view') && !$can('pbody_group_view')) $out(['ok' => false, 'error' => 'به استعلامِ گروهی دسترسی ندارید.']);
    $rows = [];
    foreach ($pdo->query("SELECT id FROM inq_batches ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN) as $id) $rows[] = inq_batch_info($pdo, $id);
    $out(['ok' => true, 'batches' => $rows]);
}
if ($action === 'batch_cancel' || $action === 'batch_resume') {
    $anyGroup();
    $bid = intval($in['id'] ?? 0);
    if ($action === 'batch_cancel') {
        $pdo->prepare("UPDATE inq_requests SET status = 'FAIL', notice = 'لغو شد', finished_at = NOW() WHERE batch_id = ? AND status = 'QUEUED'")->execute([$bid]);
        $pdo->prepare("UPDATE inq_batches SET status = 'CANCELED', finished_at = NOW() WHERE id = ?")->execute([$bid]);
    } else {
        // ردیف‌های ناموفق دوباره در صف
        $pdo->prepare("UPDATE inq_requests SET status = 'QUEUED', notice = NULL, timeline_json = NULL, result_json = NULL, finished_at = NULL WHERE batch_id = ? AND status = 'FAIL'")->execute([$bid]);
        $pdo->prepare("UPDATE inq_batches SET status = 'RUNNING', finished_at = NULL WHERE id = ?")->execute([$bid]);
    }
    $out(['ok' => true, 'batch' => inq_batch_info($pdo, $bid)]);
}

// ---- پردازش فایل هوشمند: یک صفحه (عکسِ صفحه از مرورگر)، ذخیره در تاریخچه، خروجی ----
if (strpos($action, 'ocr_') === 0) {
    $need('ocr');
    require_once __DIR__ . '/_smart_ocr.php';
    // سقفِ روزانه‌ی «پردازش فایل»ِ استعلامات (صفحه در روز؛ از «کاربران ← دسترسی‌ها»)
    if ($action === 'ocr_quota') {
        require_once __DIR__ . '/_ocr_limit.php';
        $lim = $isAdmin ? 0 : ocrl_limit($pdo, $uid); $used = ocrl_used($pdo, $uid);
        $out(['ok' => true, 'limit' => $lim, 'used' => $used, 'left' => $lim ? max(0, $lim - $used) : null]);
    }
    if ($action === 'ocr_page') {
        @set_time_limit(300);
        $inqSrc = ($in['src'] ?? '') === 'inq';
        if ($inqSrc && !$isAdmin) {
            require_once __DIR__ . '/_ocr_limit.php';
            $lim = ocrl_limit($pdo, $uid);
            if ($lim && ocrl_used($pdo, $uid) >= $lim) $out(['ok' => false, 'browser' => false, 'error' => 'سقفِ روزانه‌ی پردازشِ فایلِ شما (' . $lim . ' صفحه) پر شده است؛ فردا دوباره امکان‌پذیر است یا از مدیر کل بخواهید سقف را بیشتر کند.']);
            ocrl_add($pdo, $uid, 1);
        }
        session_write_close();   // صفحه‌های هم‌زمانِ همین کاربر پشتِ قفلِ نشست نمانند
        $lang = in_array($in['lang'] ?? '', ['fas', 'eng', 'auto'], true) ? $in['lang'] : 'fas';
        $out(so_page($pdo, (string)($in['image'] ?? ''), $lang, in_array($in['engine'] ?? '', ['i2pdf', 'ai', 'ocrspace'], true) ? $in['engine'] : 'auto',
                     ['format' => (string)($in['format'] ?? ''), 'ptoken' => (string)($in['ptoken'] ?? ''), 'pdf' => so_pdf_param($in['pdf'] ?? '')]));
    }
    // پردازش: متنِ OCR ← پرامپتِ نوعِ انتخابی (یا تشخیصِ خودکار) ← فیلدها
    if ($action === 'ocr_extract') {
        @set_time_limit(200); session_write_close();
        require_once __DIR__ . '/_doc_smart.php';
        $type = (string)($in['type'] ?? 'auto');
        $x = ds_extract($pdo, isset(ds_types()[$type]) ? $type : 'auto', (string)($in['text'] ?? ''));
        if (!$x['ok']) $out(['ok' => false, 'error' => $x['error'] ?? 'استخراج نشد.']);
        $rows = $x['type'] === 'letter' ? ($x['data']['rows'] ?? []) : [];
        $out(['ok' => true, 'type' => $x['type'], 'type_fa' => $x['type_fa'], 'fields_verbatim' => array_map(null, array_keys($x['fields']), array_values($x['fields'])), 'rows_verbatim' => $rows]);
    }
    if ($action === 'ocr_progress') { session_write_close(); $out(['ok' => true] + (so_progress_read((string)($in['token'] ?? '')) ?: ['steps' => []])); }
    if ($action === 'ocr_i2_test') {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
        @set_time_limit(300); session_write_close();
        require_once __DIR__ . '/_i2pdf.php';
        $out(i2_test($pdo));
    }
    // پرامپت‌های یک شرکت: base = پرامپتِ کلی (اگر اختصاصی ندارد همین اعمال می‌شود)، custom = اختصاصیِ همین شرکت
    if ($action === 'ocr_prompts_company') {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
        require_once __DIR__ . '/_doc_smart.php';
        $cid = intval($in['company_id'] ?? 0);
        $cfgAll = api_cfg($pdo)['smart_ocr'] ?? [];
        $list = [];
        foreach (ds_types() as $t => $x) $list[] = ['type' => $t, 'label' => $x[0], 'base_verbatim' => trim((string)($cfgAll['prompts'][$t] ?? '')) ?: $x[1], 'custom_verbatim' => (string)($cfgAll['company_prompts'][(string)$cid][$t] ?? '')];
        $out(['ok' => true, 'company_id' => $cid, 'prompts' => $list]);
    }
    // ---- سیستمِ داخلی (Ollama، روی کامپیوترِ دفتر): تنظیمات، وضعیتِ برنامه‌ی ویندوزی، آزمایش و دانلودِ برنامه ----
    if (in_array($action, ['ocr_ollama_get', 'ocr_ollama_save', 'ocr_ollama_test', 'ocr_ollama_zip'], true)) {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
        require_once __DIR__ . '/_ollama.php';
        if ($action === 'ocr_ollama_save') ol_save_cfg($pdo, (array)($in['cfg'] ?? []));
        $c = ol_cfg($pdo);
        // بسته‌ی برنامه‌ی ویندوز برای یک سیستم (با آدرسِ سایت و کلیدِ همان سیستم)
        if ($action === 'ocr_ollama_zip') {
            $nid = (string)($in['node'] ?? '');
            if (!$c['nodes']) { $c = ol_save_cfg($pdo, ['add_node' => 'کامپیوترِ دفتر']); $nid = ''; }
            $node = $c['nodes'][$nid] ?? reset($c['nodes']);
            if (!class_exists('ZipArchive')) $out(['ok' => false, 'error' => 'ZipArchive روی هاست نیست.']);
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname(dirname((string)$_SERVER['SCRIPT_NAME']))), '/');
            $tmp = tempnam(sys_get_temp_dir(), 'olw') . '.zip';
            $z = new ZipArchive();
            $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $dir = dirname(__DIR__) . '/tools/ollama-worker';
            foreach (['worker.ps1', 'install.ps1', 'uninstall.ps1', 'check.ps1', 'install.bat', 'uninstall.bat', 'check.bat', 'README-fa.txt'] as $f) if (is_file("$dir/$f")) $z->addFile("$dir/$f", "ollama-worker/$f");
            $z->addFromString('ollama-worker/worker.config.json', json_encode(['site' => $base, 'token' => $node['token'], 'ollama' => 'http://127.0.0.1:11434', 'name' => $node['name']],
                                                                                 JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $z->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="ollama-worker-' . $node['id'] . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp); @unlink($tmp);
            exit;
        }
        // آزمایش: یک پیامِ کوتاه با یک سیستم و مدلِ مشخص (یا اولین اولویتِ «استخراج»)
        if ($action === 'ocr_ollama_test') {
            @set_time_limit(150);
            $nid = (string)($in['node'] ?? '');
            $model = trim((string)($in['model'] ?? ''));
            if ($model === '') { $r0 = ($c['routes']['extract'] ?? [])[0] ?? (($c['routes']['ocr'] ?? [])[0] ?? null); if ($r0) { $model = $r0['model']; if ($nid === '') $nid = $r0['node']; } }
            if ($model === '') $out(['ok' => false, 'error' => 'اول نامِ مدل را بنویسید (یا در «اولویت‌ها» یک مدل بگذارید).']);
            $t0 = microtime(true);
            $r = ol_call($pdo, 'chat', $model, [['role' => 'user', 'content' => 'فقط همین جمله را بنویس: «سیستمِ داخلی آماده است.» (' . date('H:i:s') . ')']], ['num_predict' => 60, 'node' => $nid], 120);
            $by = !empty($r['node']) && isset($c['nodes'][$r['node']]) ? $c['nodes'][$r['node']]['name'] : '';
            $out(['ok' => true, 'result' => ['ok' => !empty($r['ok']), 'pending' => !empty($r['pending']), 'text' => $r['text'] ?? '', 'error' => $r['error'] ?? '',
                                              'ms' => intval((microtime(true) - $t0) * 1000), 'model' => $model, 'node' => $by]]);
        }
        $nodes = array_values(ol_nodes($pdo, $c));
        unset($c['nodes']);
        $rj = json_decode((string)sms_get($pdo, 'ollama_reject', ''), true);
        if (is_array($rj)) { $rj['ago'] = time() - intval($rj['at'] ?? 0); $rj['ago_fa'] = ol_ago_fa($rj['ago']); if ($rj['ago'] > 2 * 86400) $rj = null; }
        $out(['ok' => true, 'cfg' => $c, 'nodes' => $nodes, 'tasks' => OL_TASKS, 'stats' => ol_stats($pdo), 'why' => ol_why($pdo, 'extract'), 'reject' => is_array($rj) ? $rj : null]);
    }
    if ($action === 'ocr_cfg_get' || $action === 'ocr_cfg_save') {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
        require_once __DIR__ . '/_i2pdf.php';
        if ($action === 'ocr_cfg_save') so_save_cfg($pdo, (array)($in['cfg'] ?? []));
        require_once __DIR__ . '/_doc_smart.php';
        $c = so_cfg($pdo); $custom = (api_cfg($pdo)['smart_ocr'] ?? [])['prompts'] ?? [];
        $prompts = [];
        foreach (ds_types() as $t => $x) $prompts[] = ['type' => $t, 'label' => $x[0], 'default_verbatim' => $x[1], 'custom_verbatim' => (string)($custom[$t] ?? '')];
        $cmap = ds_company_prompt_map($pdo); $companies = [];
        try { foreach ($pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll() as $co) $companies[] = ['id' => intval($co['id']), 'name' => $co['name'], 'custom' => count($cmap[intval($co['id'])] ?? [])]; } catch (Throwable $e) {}
        $out(['ok' => true, 'cfg' => $c, 'engines' => SO_ENGINES, 'ready' => so_engines($pdo), 'formats' => I2_FORMATS, 'i2pdf' => i2_conf($pdo), 'prompts' => $prompts, 'ai_ready' => vdoc_ai_ready($pdo), 'companies' => $companies]);
    }
    if ($action === 'ocr_save') {
        $id = intval($in['id'] ?? 0);
        if ($id) { $r = inq_get($pdo, $id); if (!$r || $r['kind'] !== 'ocr' || !$mine($r)) $out(['ok' => false, 'error' => 'پیدا نشد.']); }
        $out(['ok' => true, 'id' => so_save($pdo, $id, (string)($in['name'] ?? ''), (string)($in['lang'] ?? 'fas'), (array)($in['pages'] ?? []))]);
    }
    if ($action === 'ocr_export') {
        $r = inq_get($pdo, $in['id'] ?? 0);
        if (!$r || $r['kind'] !== 'ocr' || !$mine($r)) $out(['ok' => false, 'error' => 'پیدا نشد.']);
        [$pages] = so_pages_of($r);
        $only = intval($in['page'] ?? 0);
        if ($only) $pages = array_values(array_filter($pages, function ($p) use ($only) { return intval($p['n']) === $only; }));
        $name = preg_replace('/\.[a-z0-9]{2,5}$/i', '', (string)$r['query_text']) . ($only ? ' - صفحه ' . $only : '') . ' (متن)';
        // فایلِ اصلیِ i2pdf (Word / Simple PDF) همان‌طور که سایت داده
        // فایلِ اصلیِ i2pdf (Word یا PDF) همان‌طور که سایت داده: fmt=orig&kind=docx|pdf
        if (($in['fmt'] ?? '') === 'orig' && count($pages) === 1) {
            $kind = ($in['kind'] ?? '') === 'pdf' ? 'pdf' : 'docx';
            $fid = $pages[0]['files'][$kind] ?? (($pages[0]['file'] ?? '') !== '' && pathinfo($pages[0]['file'], PATHINFO_EXTENSION) === $kind ? $pages[0]['file'] : '');
            $f = $fid !== '' ? inq_dir('ocr') . '/' . basename($fid) : '';
            if ($f === '' || !is_file($f)) $out(['ok' => false, 'error' => 'فایلِ i2pdf برای این صفحه نیست.']);
            header_remove('Content-Type');
            header('Content-Type: ' . ($kind === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'));
            header('Content-Disposition: ' . ($kind === 'pdf' ? 'inline' : 'attachment') . "; filename=\"i2pdf.$kind\"; filename*=UTF-8''" . rawurlencode($name . ' (i2pdf).' . $kind));
            readfile($f); exit;
        }
        $fmt = in_array($in['fmt'] ?? '', ['txt', 'docx', 'pdf'], true) ? $in['fmt'] : 'txt';
        $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.' . $fmt;
        if ($fmt === 'txt') file_put_contents($tmp, "\xEF\xBB\xBF" . implode("\r\n\r\n", array_map(function ($p) use ($pages) { return (count($pages) > 1 ? '--- صفحه ' . $p['n'] . " ---\r\n" : '') . str_replace("\n", "\r\n", (string)$p['text']); }, $pages)));
        elseif ($fmt === 'docx') so_docx($pages, $name, $tmp);
        else so_pdf($pages, $name, $tmp);
        if (!is_file($tmp)) $out(['ok' => false, 'error' => 'ساختِ فایل ممکن نشد.']);
        header_remove('Content-Type');
        header('Content-Type: ' . ['txt' => 'text/plain; charset=utf-8', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'pdf' => 'application/pdf'][$fmt]);
        header('Content-Disposition: ' . ($fmt === 'pdf' ? 'inline' : 'attachment') . "; filename=\"ocr.$fmt\"; filename*=UTF-8''" . rawurlencode($name . '.' . $fmt));
        readfile($tmp); @unlink($tmp);
        exit;
    }
    $out(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
}

// ---- پاسارگاد: «استعلام بدنه بیمه مرکزی» و «استعلام خسارت» (api/_pbody.php) — با همان نشستِ ورودِ استعلاماتِ پاسارگاد ----
if (strpos($action, 'pb_') === 0) {
    if (!$can('pbody') && !$can('pbody_form')) $out(['ok' => false, 'error' => 'به استعلامِ بدنه/خسارتِ پاسارگاد دسترسی ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']);
    $actor = inq_actor_from_session();
    $src = ($in['source'] ?? '') === 'form' ? 'form' : 'panel';
    $needRun = function () use ($src, $need) { $need($src === 'form' ? 'pbody_form' : 'pbody'); };
    // ذخیره‌ی مدرک روی ردیف/پرونده: همان قاعده‌ی سنهاب
    $canSave = function ($rtype) use ($pdo) {
        $realRole = perm_real_role();
        return $realRole === 'ADMIN' || perm_user_custom($pdo, $_SESSION['user_id'] ?? 0) !== null || ($rtype === 'C' && $realRole === 'COMPANY_LIAISON');
    };
    $getPb = function ($kinds) use ($pdo, $in, $mine, $out) {
        $row = inq_get($pdo, $in['id'] ?? 0);
        if (!$row || !in_array($row['kind'], (array)$kinds, true) || !$mine($row)) $out(['ok' => false, 'error' => 'استعلام پیدا نشد.']);
        return $row;
    };
    if ($action === 'pb_boot') $out(['ok' => true, 'ready' => pb_enabled($pdo) && inq_creds($pdo)['user'] !== '', 'status' => inq_captcha_pending() ? 'logged_out' : inq_px_status($pdo),
                                     'types' => PB_TYPES, 'ins' => PB_INS, 'is_admin' => $isAdmin, 'stamp' => (function () { require_once __DIR__ . '/_damage_inq.php'; return dmg_has_stamp(); })()]);
    if ($action === 'pb_search') {
        $needRun();
        $out(pb_search($pdo, $in, ['source' => $src, 'ref_type' => $in['ref_type'] ?? null, 'ref_id' => $in['ref_id'] ?? null, 'actor' => $actor]));
    }
    if ($action === 'pb_damage') {
        $needRun();
        $save = null;
        if (!empty($in['save_type']) && intval($in['save_id'] ?? 0)) {
            $rt = $in['save_type'] === 'P' ? 'P' : 'C';
            if (!$canSave($rt)) $out(['ok' => false, 'error' => 'به ذخیره‌ی مدرک روی این درخواست دسترسی ندارید.']);
            $save = ['type' => $rt, 'id' => intval($in['save_id'])];
        }
        $out(pb_damage($pdo, $in, ['source' => $src, 'ref_type' => $in['ref_type'] ?? null, 'ref_id' => $in['ref_id'] ?? null, 'actor' => $actor, 'save' => $save]));
    }
    if ($action === 'pb_last') $out(['ok' => true, 'item' => pb_last($pdo, $in['ref_type'] ?? null, intval($in['ref_id'] ?? 0), array_map('strval', (array)($in['labels'] ?? [])))]);
    if ($action === 'pb_get') { $row = $getPb(['pbody', 'pdamage']); $out(['ok' => true, 'item' => pb_out($row)]); }
    // فایل‌های یک استعلام: چاپِ سامانه، PDFِ خسارت، تصویرِ صفحه
    if ($action === 'pb_file') {
        $row = $getPb(['pbody', 'pdamage']);
        $dir = pb_row_dir($row);
        $res = json_decode((string)$row['result_json'], true) ?: [];
        $f = (string)($in['f'] ?? '');
        $name = $f === 'print' ? ($res['pbody']['print'] ?? '') : ($f === 'damage' ? ($res['pdamage']['pdf'] ?? '') : ($f === 'shot' ? ($res['pdamage']['shot'] ?? '') : ''));
        if ($dir === '' || $name === '' || !preg_match('/^[a-z]+\.(pdf|png)$/', $name) || !is_file($dir . '/' . $name)) $out(['ok' => false, 'error' => 'فایل پیدا نشد.']);
        $isPng = substr($name, -4) === '.png';
        $dl = ($f === 'shot' ? 'تصویر استعلام خسارت' : ($f === 'damage' ? 'استعلام خسارت پاسارگاد' : 'چاپ استعلام بدنه')) . ' - ' . preg_replace('/[\\\/:*?"<>|]+/u', '-', (string)$row['query_text']);
        require_once __DIR__ . '/_damage_inq.php';
        inq_send_file($dir . '/' . $name, $isPng ? 'image/png' : 'application/pdf', $dl . ($isPng ? '.png' : '.pdf'), !empty($in['stamp']));
    }
    if ($action === 'pb_export') {
        $row = $getPb(['pbody']);
        $sel = array_values(array_filter(array_map('intval', array_filter(explode(',', (string)($in['rows'] ?? '')), 'strlen')), function ($x) { return $x >= 0; }));
        $base = 'بدنه بیمه مرکزی ' . preg_replace('/[\\\/:*?"<>|]+/u', '-', (string)$row['query_text']) . ' ' . jdate_short(date('Y-m-d'));
        if (($in['fmt'] ?? '') === 'pdf') {
            $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.pdf';
            if (!pb_export_pdf($row, $sel, $tmp)) $out(['ok' => false, 'error' => 'ساختِ PDF ممکن نشد.']);
            require_once __DIR__ . '/_damage_inq.php';
            register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
            inq_send_file($tmp, 'application/pdf', 'خروجی سایت - ' . $base . '.pdf', !empty($in['stamp']));
        }
        $path = pb_export_xlsx($row, $sel);
        if (!$path) $out(['ok' => false, 'error' => 'ساختِ اکسل ممکن نشد.']);
        xlsx_send($path, $base . '.xlsx');
        exit;
    }
    // خروجیِ استعلامِ خسارت (PDF یا تصویر) => «استعلام خسارت»ِ ردیف/پرونده، با مهر
    if ($action === 'pb_save_doc') {
        $row = $getPb(['pdamage']);
        $rt = ($in['ref_type'] ?? '') === 'P' ? 'P' : 'C'; $rid = intval($in['ref_id'] ?? 0);
        if (!$rid || !$canSave($rt)) $out(['ok' => false, 'error' => 'به ذخیره‌ی مدرک روی این درخواست دسترسی ندارید.']);
        $out(pb_save_doc($pdo, $row, $rt, $rid, ($in['which'] ?? '') === 'pdf' ? 'pdf' : 'shot', $actor[1] ?: null));
    }
    // مدیر کل: صفحه‌های ذخیره‌شده‌ی هر مرحله (برای وقتی که سامانه عوض شده و خانه‌ای پیدا نمی‌شود)
    if ($action === 'pb_steps' || $action === 'pb_step') {
        if (!$isAdmin) $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
        $row = $getPb(['pbody', 'pdamage']);
        $dir = pb_row_dir($row);
        $files = $dir !== '' ? array_values(array_map('basename', glob($dir . '/*.html') ?: [])) : [];
        natsort($files); $files = array_values($files);
        if ($action === 'pb_steps') $out(['ok' => true, 'files' => $files]);
        $f = basename((string)($in['f'] ?? ''));
        if (!in_array($f, $files, true)) { http_response_code(404); exit; }
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        readfile($dir . '/' . $f);
        exit;
    }
    $out(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
}

// ---- سنهاب (سوابقِ بیمه‌نامه‌ها): کپچای ورود (مشترک) و کپچای هر استعلام (نوبتِ همین پنجره) ----
if (strpos($action, 'sh_') === 0) {
    $shAny = $can('sanhab') || $can('sanhab_form') || $can('sanhab_group') || $can('premium');
    if (!$shAny) $out(['ok' => false, 'error' => 'به استعلامِ سنهاب دسترسی ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']);
    $actor = inq_actor_from_session();
    $tok = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['tok'] ?? ''));
    $hkey = $actor[0] . ':' . $actor[1] . ':' . substr($tok, 0, 24);
    // کاربرانِ غیرِ مدیر کل: پیام‌ها بدونِ «ورود/نشست» و نامِ سامانه (sh_user_safe)
    if (!$isAdmin) { $out0 = $out; $out = function ($a) use ($out0) { $out0(sh_user_safe($a)); }; }
    if ($action === 'sh_boot') $out(['ok' => true, 'status' => sh_status($pdo), 'ready' => sh_enabled($pdo) && sh_creds($pdo)['user'] !== '', 'types' => SH_TYPES, 'plcy' => SH_PLCY, 'is_admin' => $isAdmin]);
    if ($action === 'sh_login_captcha') $out(sh_login_captcha($pdo, !empty($in['refresh'])));
    if ($action === 'sh_login') $out(sh_login($pdo, $in['captcha'] ?? ''));
    if ($action === 'sh_captcha') $out(sh_inq_captcha($pdo, $in['type'] ?? '4', $in['plcy'] ?? '0', $hkey, $actor[2], !empty($in['reselect'])));
    if ($action === 'sh_release') { sh_hold_release($hkey); $out(['ok' => true]); }
    if ($action === 'sh_search') {
        $src = in_array($in['source'] ?? '', ['form', 'premium'], true) ? $in['source'] : 'panel';
        if (!empty($in['row_id'])) $need('sanhab_group');
        elseif ($src === 'form') $need('sanhab_form');
        elseif ($src === 'premium') $need('premium');
        else $need('sanhab');
        $out(sh_search($pdo, $in, $hkey, ['source' => $src, 'ref_type' => $in['ref_type'] ?? null, 'ref_id' => $in['ref_id'] ?? null, 'row_id' => intval($in['row_id'] ?? 0), 'actor' => $actor]));
    }
    if ($action === 'sh_last') $out(['ok' => true, 'item' => sh_last($pdo, $in['ref_type'] ?? null, intval($in['ref_id'] ?? 0), (array)($in['cands'] ?? []))]);
    if ($action === 'sh_pdf') {
        $res = sh_pdf($pdo, (string)($in['typ'] ?? '1'), (string)($in['pu'] ?? ''));
        if (!$res) $out(['ok' => false, 'error' => 'سامانه‌ی سنهاب مشغول است؛ چند ثانیه‌ی دیگر دوباره بزنید.']);
        if (stripos((string)$res['contentType'], 'pdf') !== false) {
            require_once __DIR__ . '/_damage_inq.php';
            $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.pdf';
            file_put_contents($tmp, $res['body']);
            register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
            inq_send_file($tmp, 'application/pdf', 'سنهاب ' . preg_replace('/[^0-9,]/', '', (string)($in['pu'] ?? '')) . '.pdf', !empty($in['stamp']));
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<p style="font-family:tahoma;direction:rtl;padding:20px">فایلِ PDF از سنهاب دریافت نشد (شاید نشست بسته شده باشد؛ یک استعلامِ تازه بزنید و دوباره امتحان کنید).</p>';
        exit;
    }
    // «ذخیره‌ی خروجیِ سنهاب» از پنلِ صدور: PDFِ سنهاب (اگر نیامد، PDFِ خروجیِ سایت) با مهرِ استعلامات، به نامِ «پلاک (استعلام خسارت ثالث)»
    // در پوشه‌ی همان درخواست و به‌عنوانِ مدرکِ «استعلام خسارت»ِ همان ردیف/پرونده
    if ($action === 'sh_save_doc') {
        require_once __DIR__ . '/_damage_inq.php';
        $row = inq_get($pdo, $in['id'] ?? 0);
        if (!$row || $row['kind'] !== 'sanhab') $out(['ok' => false, 'error' => 'استعلام پیدا نشد.']);
        $rtype = ($in['ref_type'] ?? '') === 'P' ? 'P' : 'C'; $rid = intval($in['ref_id'] ?? 0);
        $realRole = perm_real_role();
        if (!$rid || ($realRole !== 'ADMIN' && perm_user_custom($pdo, $_SESSION['user_id'] ?? 0) === null && !($rtype === 'C' && $realRole === 'COMPANY_LIAISON')))
            $out(['ok' => false, 'error' => 'به ذخیره‌ی مدرک روی این درخواست دسترسی ندارید.']);
        @set_time_limit(180);
        $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.pdf';
        $src = '';
        // نوعِ خروجی: sanhab (پیش‌فرض: PDFِ خودِ سنهاب؛ اگر نیامد خروجیِ سایت) | site (PDFِ خروجیِ سایت) | shot (اسکرینِ صفحه‌ی سنهاب)
        $want = in_array($in['src'] ?? '', ['site', 'shot'], true) ? $in['src'] : 'sanhab';
        $sel = array_values(array_filter(array_map('intval', array_filter(explode(',', (string)($in['rows'] ?? '')), 'strlen')), function ($x) { return $x >= 0; }));
        if ($want === 'shot') {
            require_once __DIR__ . '/_sanhab_shot.php';
            $tmp = substr($tmp, 0, -4) . '.png';
            if (!sh_shot_png($row, $sel, $tmp)) $out(['ok' => false, 'error' => 'ساختِ اسکرینِ سنهاب ممکن نشد.']);
            $src = 'shot';
        }
        $pu = $want === 'sanhab' ? preg_replace('/[^0-9A-Za-z,\-]/', '', (string)($in['pu'] ?? '')) : '';
        if ($pu !== '') {
            $res = sh_pdf($pdo, (string)($in['typ'] ?? '1'), $pu);
            if ($res && stripos((string)$res['contentType'], 'pdf') !== false && strlen((string)$res['body']) > 500 && @file_put_contents($tmp, $res['body'])) $src = 'sanhab';
        }
        if ($src === '' && sh_export_pdf($row, $sel, $tmp)) $src = 'site';
        if ($src === '') $out(['ok' => false, 'error' => 'PDFِ سنهاب دریافت نشد (شاید نشست بسته شده؛ یک استعلامِ تازه بزنید و دوباره امتحان کنید).']);
        $r = dmg_save($pdo, $rtype, $rid, $tmp, $actor[1] ?: null);
        if (is_file($tmp)) @unlink($tmp);
        $out($r + ['src' => $src, 'want' => $want]);
    }
    // خروجیِ سوابقِ یک استعلام: اکسل یا PDFِ «خروجیِ سایت» (همه یا ردیف‌های انتخاب‌شده)
    if ($action === 'sh_export') {
        $row = inq_get($pdo, $in['id'] ?? 0);
        if (!$row || $row['kind'] !== 'sanhab' || !$mine($row)) $out(['ok' => false, 'error' => 'استعلام پیدا نشد.']);
        $sel = array_values(array_filter(array_map('intval', array_filter(explode(',', (string)($in['rows'] ?? '')), 'strlen')), function ($x) { return $x >= 0; }));
        $base = 'سنهاب ' . preg_replace('/[\\\/:*?"<>|]+/u', '-', (string)$row['query_text']) . ' ' . jdate_short(date('Y-m-d'));
        if (($in['fmt'] ?? '') === 'pdf') {
            $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.pdf';
            if (!sh_export_pdf($row, $sel, $tmp)) $out(['ok' => false, 'error' => 'ساختِ PDF ممکن نشد.']);
            require_once __DIR__ . '/_damage_inq.php';
            register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
            inq_send_file($tmp, 'application/pdf', 'خروجی سایت - ' . $base . '.pdf', !empty($in['stamp']));
        }
        // «اسکرینِ سایتِ سنهاب» (api/_sanhab_shot.php)
        if (($in['fmt'] ?? '') === 'shot') {
            require_once __DIR__ . '/_sanhab_shot.php';
            $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.png';
            if (!sh_shot_png($row, $sel, $tmp)) $out(['ok' => false, 'error' => 'ساختِ اسکرین ممکن نشد.']);
            require_once __DIR__ . '/_damage_inq.php';
            register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
            inq_send_file($tmp, 'image/png', 'اسکرین سنهاب - ' . $base . '.png', !empty($in['stamp']));
        }
        require_once __DIR__ . '/_xlsx_writer.php';
        $path = sh_export_xlsx($row, $sel);
        if (!$path) $out(['ok' => false, 'error' => 'ساختِ اکسل ممکن نشد.']);
        xlsx_send($path, $base . '.xlsx');
        exit;
    }
    if ($action === 'sh_page') {
        if (!$isAdmin) { http_response_code(403); exit; }
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src https: data:; style-src 'unsafe-inline' https:; font-src https: data:");
        header('X-Frame-Options: SAMEORIGIN');
        echo sh_live_html();
        exit;
    }
    // ---- گروهی: اکسل ← صف ← هر ردیف با کپچای خودش ----
    if ($action === 'sh_batch_sample') {
        require_once __DIR__ . '/_xlsx_writer.php';
        $t = (string)($in['type'] ?? '4');
        if ($t === '4') $path = xlsx_build(['ایران (۲ رقم)', 'سه‌رقمی', 'حرف', 'دورقمیِ چپ'], [['21', '123', 'ب', '11'], ['44', '587', 'ع', '62']], 'پلاک');
        elseif ($t === '6') $path = xlsx_build(['شماره موتور', 'شماره شاسی'], [['124K0123456', 'NAAP41FE5PJ123456']], 'موتور و شاسی');
        else $path = xlsx_build([SH_TYPES[$t] ?? 'مقدار'], [[$t === '3' ? '0012345678' : ($t === '7' ? '1404/1234/56/789' : 'NAAP41FE5PJ123456')]], 'سنهاب');
        xlsx_send($path, 'نمونه‌ی استعلامِ گروهیِ سنهاب - ' . (SH_TYPES[$t] ?? '') . '.xlsx');
        exit;
    }
    if ($action === 'sh_batch_upload') {
        $need('sanhab_group');
        $t = isset(SH_TYPES[(string)($in['type'] ?? '')]) ? (string)$in['type'] : '4';
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $out(['ok' => false, 'error' => 'فایلِ اکسل دریافت نشد.']);
        $ext = upload_ext($f['name']);
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) $out(['ok' => false, 'error' => 'فقط فایلِ اکسل (xlsx) یا CSV.']);
        $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6)) . '.' . $ext;
        move_uploaded_file($f['tmp_name'], $tmp);
        $r = sh_excel_rows($tmp, $t);
        @unlink($tmp);
        if (!$r['ok']) $out($r);
        $labels = array_map(function ($x) use ($t) { $v = sh_validate(['type' => $t] + $x); return $v['ok'] ? $v['label'] : ('⚠️ ' . $v['error']); }, $r['rows']);
        if (!empty($in['preview'])) $out(['ok' => true, 'rows' => array_slice($labels, 0, 1000), 'count' => count($labels)]);
        $b = sh_batch_create($pdo, $t, (string)($in['plcy'] ?? '0'), trim((string)($in['title'] ?? '')) ?: ('سنهاب ' . $f['name']), $r['rows'], $f['name']);
        $out($b + ['batch' => inq_batch_info($pdo, $b['batch_id'])]);
    }
    if ($action === 'sh_skip_row') {
        $need('sanhab_group');
        $pdo->prepare("UPDATE inq_requests SET status = 'FAIL', notice = 'ردیف رد شد (استعلام نشد)', finished_at = NOW() WHERE id = ? AND kind = 'sanhab' AND status = 'QUEUED'")->execute([intval($in['id'] ?? 0)]);
        $pdo->exec("UPDATE inq_batches b SET status = 'DONE', finished_at = NOW() WHERE status = 'RUNNING' AND kind = 'sanhab' AND NOT EXISTS (SELECT 1 FROM inq_requests r WHERE r.batch_id = b.id AND r.status IN ('QUEUED', 'RUNNING'))");
        $out(['ok' => true]);
    }
    if ($action === 'sh_batch_next') {
        $need('sanhab_group');
        $bid = intval($in['id'] ?? 0);
        $out(['ok' => true, 'next' => sh_batch_next($pdo, $bid), 'batch' => inq_batch_info($pdo, $bid)]);
    }
    $out(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
}

// ---- تنظیمات و ورود به سامانه (فقط مدیر کل) ----
// کد پستیِ تأییدشده‌ی قبلیِ همین کد ملی (پر کردنِ خودکارِ فرم‌ها)
if ($action === 'known_postal') $out(['ok' => true, 'known' => inq_known_postal($pdo, $in['national_id'] ?? '')]);
// مهرِ استعلامات (PNGِ بی‌زمینه): پایینِ سمتِ چپِ هر فایلِ استعلامِ خسارت
if (in_array($action, ['dmg_stamp_get', 'dmg_stamp_upload', 'dmg_stamp_remove', 'dmg_stamp_sample'], true)) {
    if (!$isAdmin) $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
    require_once __DIR__ . '/_damage_inq.php';
    if ($action === 'dmg_stamp_upload') { $r = dmg_stamp_save($_FILES['file'] ?? []); if (!$r['ok']) $out($r); }
    if ($action === 'dmg_stamp_remove') dmg_stamp_remove();
    if ($action === 'dmg_stamp_sample') {
        // PDFِ نمونه (دو صفحه) با مهر، برای دیدنِ جای واقعیِ مهر روی PDF
        if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
        require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
        $p = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false); $p->setPrintHeader(false); $p->setPrintFooter(false);
        $p->AddFont('vazir', '', 'vazir.php'); $p->setRTL(true); $p->SetFont('vazir', '', 14);
        foreach ([1, 2] as $n) { $p->AddPage(); $p->Cell(0, 12, 'نمونه‌ی استعلامِ خسارت — صفحه‌ی ' . $n, 0, 1); $p->SetFillColor(238, 242, 255); $p->Rect(15, 35, 180, 40, 'F'); }
        $in1 = inq_dir('tmp') . '/' . bin2hex(random_bytes(5)) . '.pdf'; $out1 = $in1 . '.s.pdf';
        $p->Output($in1, 'F');
        dmg_stamp_file($in1, $out1);
        header_remove('Content-Type'); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="stamp-sample.pdf"');
        readfile(is_file($out1) ? $out1 : $in1); @unlink($in1); @unlink($out1);
        exit;
    }
    $out(['ok' => true, 'has' => dmg_has_stamp(), 'preview_b64' => base64_encode(dmg_stamp_preview_png())]);
}
if (in_array($action, ['settings_get', 'settings_save', 'status', 'limit_peek', 'limit_reset'], true) && !$isAdmin) $out(['ok' => false, 'error' => 'تنظیماتِ استعلامات فقط کارِ مدیر کل است.']);
if ($action === 'settings_get') {
    $s = inq_settings($pdo);
    if (inq_get_setting($pdo, 'inq_cron_key') === '') inq_set_setting($pdo, 'inq_cron_key', bin2hex(random_bytes(16)));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'), '/');
    $cr = inq_creds($pdo);
    $sc = sh_creds($pdo);
    $s['inq_pb_body_url'] = inq_get_setting($pdo, 'inq_pb_body_url'); $s['inq_pb_damage_url'] = inq_get_setting($pdo, 'inq_pb_damage_url');
    $s['inq_pb_enabled'] = pb_enabled($pdo) ? '1' : '0'; $s['inq_pb_daily_limit'] = inq_get_setting($pdo, 'inq_pb_daily_limit') ?: '0';
    $out(['ok' => true, 'settings' => $s, 'username' => $cr['user'], 'has_password' => $cr['pass'] !== '', 'sh_username' => $sc['user'], 'sh_has_password' => $sc['pass'] !== '', 'sh_status' => sh_status($pdo, true),
          'cron_url' => $base . '/inquiry_actions.php?action=keepalive&key=' . inq_get_setting($pdo, 'inq_cron_key'), 'status' => inq_px_status($pdo, true)]);
}
if ($action === 'settings_save') {
    foreach (INQ_DEFAULTS as $k => $def) if (array_key_exists($k, $in)) {
        $lims = ['inq_wait_sec' => [0, 9], 'inq_cache_days' => [0, 365], 'inq_daily_limit' => [0, 365], 'inq_keep_minutes' => [5, 600], 'inq_nid_daily_limit' => [0, 1000], 'inq_user_daily_limit' => [0, 10000], 'inq_postal_daily_limit' => [0, 1000], 'inq_sh_daily_limit' => [0, 10000]];
        $v = isset($lims[$k]) ? (string)max($lims[$k][0], min($lims[$k][1], intval($in[$k]))) : (empty($in[$k]) ? '0' : '1');
        inq_set_setting($pdo, $k, $v);
    }
    if (array_key_exists('username', $in)) inq_set_setting($pdo, 'inq_px_user', trim((string)$in['username']));
    if (($in['password'] ?? '') !== '') inq_set_setting($pdo, 'inq_px_pass', inq_enc((string)$in['password']));
    if (array_key_exists('sh_username', $in)) inq_set_setting($pdo, 'inq_sh_user', trim((string)$in['sh_username']));
    if (($in['sh_password'] ?? '') !== '') { inq_set_setting($pdo, 'inq_sh_pass', inq_enc((string)$in['sh_password'])); sh_mark('logged_out'); }
    if (!empty($in['new_cron_key'])) inq_set_setting($pdo, 'inq_cron_key', bin2hex(random_bytes(16)));
    // پاسارگاد (بدنه/خسارت): آدرسِ صفحه‌ها (خالی = از منوی سامانه پیدا شود)، روشن/خاموش، سقفِ روزانه‌ی هر کاربر
    foreach (['inq_pb_body_url', 'inq_pb_damage_url'] as $k) if (array_key_exists($k, $in)) {
        $u = trim((string)$in[$k]);
        if ($u !== '' && !preg_match('#^https://[a-z0-9.-]*pasargadinsurance\.ir/#i', $u)) $out(['ok' => false, 'error' => 'آدرسِ صفحه باید از سامانه‌ی پاسارگاد (https://…pasargadinsurance.ir/…) باشد.']);
        inq_set_setting($pdo, $k, $u);
    }
    if (array_key_exists('inq_pb_enabled', $in)) inq_set_setting($pdo, 'inq_pb_enabled', empty($in['inq_pb_enabled']) ? '0' : '1');
    if (array_key_exists('inq_pb_daily_limit', $in)) inq_set_setting($pdo, 'inq_pb_daily_limit', (string)max(0, min(10000, intval($in['inq_pb_daily_limit']))));
    $out(['ok' => true]);
}
// سقفِ امروزِ یک کد ملی / کد پستی: دیدن و صفر کردن
if ($action === 'limit_peek' || $action === 'limit_reset') {
    $nid = inq_norm_nid($in['national_id'] ?? ''); $postal = inq_digits($in['postal'] ?? '');
    if ($nid === '' && $postal === '') $out(['ok' => false, 'error' => 'کد ملی یا کد پستی را وارد کنید.']);
    if ($nid !== '' && !inq_nid_ok($nid)) $out(['ok' => false, 'error' => 'کد ملی نامعتبر است.']);
    if ($postal !== '' && strlen($postal) !== 10) $out(['ok' => false, 'error' => 'کد پستی باید ۱۰ رقم باشد.']);
    if ($action === 'limit_reset') {
        $by = (string)($_SESSION['full_name'] ?? '');
        if ($nid !== '') inq_limit_reset($pdo, 'n:' . $nid, $by);
        if ($postal !== '') inq_limit_reset($pdo, 'p:' . $postal, $by);
    }
    $o = ['ok' => true, 'reset' => $action === 'limit_reset'];
    if ($nid !== '') $o['nid'] = ['value' => $nid, 'used' => inq_nid_used_today($pdo, $nid), 'limit' => (int)inq_limit_value(inq_get_setting($pdo, 'inq_nid_daily_limit'))];
    if ($postal !== '') $o['postal'] = ['value' => $postal, 'used' => inq_postal_used_today($pdo, $postal), 'limit' => (int)inq_limit_value(inq_get_setting($pdo, 'inq_postal_daily_limit'))];
    $out($o);
}
if ($action === 'status') $out(['ok' => true, 'status' => inq_px_status($pdo, true)]);
$out(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
