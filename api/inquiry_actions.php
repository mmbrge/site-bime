<?php
// فایل: api/inquiry_actions.php
// پنل ← «استعلامات»: استعلامِ تکی (شخص حقیقی، شخص + کد پستی، کد پستی، شخص حقوقی)، استعلامِ گروهی با اکسل،
// تاریخچه و خروجی، و تنظیمات (فقط مدیر کل: ورود به سامانه‌ی پاسارگاد با کپچا، نگه‌داشتنِ نشست، صف و کش).
// استعلام از داخلِ فرم‌ها (ثبتِ درخواست، کد پستی) هم از همین‌جا می‌آید (source = form).
// منطق: api/_inquiry.php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_inquiry.php';
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
    $pending = intval($pdo->query("SELECT COUNT(*) FROM inq_requests r JOIN inq_batches b ON b.id = r.batch_id WHERE b.status = 'RUNNING' AND r.status = 'QUEUED'")->fetchColumn());
    if ($idle > $keep * 60 && !$pending) $out(['ok' => true, 'state' => 'idle', 'idle_min' => intdiv($idle, 60)]);
    $s = inq_px_status($pdo, true);
    $ran = 0; $t0 = time();
    if ($s === 'logged_in') while (time() - $t0 < 200) { $r = inq_batch_tick($pdo); if (!$r['ran']) break; $ran++; }
    $out(['ok' => true, 'state' => $s, 'ran' => $ran]);
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

$isAdmin = perm_real_role() === 'ADMIN';
$custom = $isAdmin ? null : perm_user_custom($pdo, $uid);
// دسترسیِ هر بخش: مدیر کل همه؛ کاربرِ سفارشی طبقِ «دسترسی‌ها»؛ بقیه فقط استعلامِ داخلِ فرم‌ها
$can = function ($what) use ($isAdmin, $custom) {
    if ($isAdmin) return true;
    $spec = ['single' => 'inq-single:create', 'single_view' => 'inq-single:view|inq-single:create', 'group' => 'inq-group:create', 'group_view' => 'inq-group:view',
             'export' => 'inq-history:export|inq-group:export', 'history' => 'inq-history:view', 'delete' => 'inq-history:delete',
             'form' => 'inq-single:create|records:create|records:edit|cases:edit|companies-requests:create|companies-requests:edit|issue-queue:edit'][$what] ?? '';
    if ($custom === null) return $what === 'form';
    return perm_allows($custom, $spec);
};
$need = function ($what) use ($can, $out) { if (!$can($what)) $out(['ok' => false, 'error' => 'به این بخشِ استعلامات دسترسی ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']); };
$mine = function ($row) use ($isAdmin, $uid, $can) { return $isAdmin || $can('history') || (($row['user_type'] ?? '') === 'STAFF' && intval($row['user_id']) === $uid); };

if ($action === 'boot') {
    $st = $isAdmin ? inq_px_status($pdo) : null;
    $o = ['ok' => true, 'is_admin' => $isAdmin, 'kinds' => INQ_KINDS, 'status' => $st,
          'can' => ['single' => $can('single'), 'group' => $can('group'), 'group_view' => $can('group_view') || $can('group'), 'export' => $can('export'), 'history' => $can('history'), 'delete' => $can('delete'), 'form' => $can('form')],
          'sapi_ready' => (function () use ($pdo) { require_once __DIR__ . '/_apis.php'; return sapi_ready($pdo, 'sapi_company'); })()];
    $q = $pdo->query("SELECT COUNT(*) n, SUM(status = 'OK') ok, SUM(status = 'FAIL') fail, SUM(status IN ('QUEUED', 'RUNNING')) q FROM inq_requests WHERE created_at >= CURDATE()")->fetch();
    $o['today'] = ['total' => intval($q['n']), 'ok' => intval($q['ok']), 'fail' => intval($q['fail']), 'queue' => intval($q['q'])];
    $out($o);
}

// ---- استعلامِ تکی / داخلِ فرم ----
if ($action === 'create') {
    $kind = (string)($in['kind'] ?? '');
    $src = ($in['source'] ?? '') === 'form' ? 'form' : 'panel';
    $need($src === 'form' ? 'form' : 'single');
    if (!$isAdmin && $kind === 'legal' && !$can('single')) $out(['ok' => false, 'error' => 'به استعلامِ شخصِ حقوقی دسترسی ندارید.']);
    $r = inq_create($pdo, $kind, $in, $src, null, null, null, empty($in['fresh']) || !$isAdmin);
    if (!$r['ok']) $out($r);
    $row = inq_get($pdo, $r['id']);
    $out(['ok' => true, 'id' => $r['id'], 'cached' => $r['cached'], 'item' => inq_out($row, $isAdmin)]);
}
if ($action === 'exec') {
    $row = inq_get($pdo, $in['id'] ?? 0);
    if (!$row || !$mine($row)) $out(['ok' => false, 'error' => 'استعلام پیدا نشد.']);
    $r = $row['status'] === 'QUEUED' ? inq_execute($pdo, intval($row['id'])) : [];
    $o = ['ok' => true, 'item' => inq_out(inq_get($pdo, $row['id']), $isAdmin)];
    // نشستِ سامانه بسته بود: تصویرِ امنیتی برای همین کاربر (زیرِ فیلدها) و بعد از واردکردنش همین استعلام دوباره اجرا می‌شود
    if (!empty($r['need_captcha'])) { $c = inq_captcha_get($pdo); $o['need_captcha'] = true; $o['captcha'] = $c['image'] ?? null; if (!$c['ok']) $o['captcha_error'] = $c['error'] ?? ''; }
    $out($o);
}
// ---- کپچا (هر کاربری که استعلام می‌زند) ----
if ($action === 'captcha' || $action === 'captcha_login') {
    if (!$can('form') && !$can('single') && !$can('group')) $out(['ok' => false, 'error' => 'دسترسی ندارید.']);
    if ($action === 'captcha') $out(inq_captcha_get($pdo, !empty($in['refresh'])));
    $out(inq_captcha_login($pdo, $in['captcha'] ?? ''));
}
if ($action === 'get') {
    $row = inq_get($pdo, $in['id'] ?? 0);
    if (!$row || !$mine($row)) $out(['ok' => false, 'error' => 'استعلام پیدا نشد.']);
    $out(['ok' => true, 'item' => inq_out($row, $isAdmin)]);
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
        $w[] = '(person_name LIKE ? OR father_name LIKE ? OR user_name LIKE ?' . ($qd !== '' ? ' OR national_id LIKE ? OR postal LIKE ?' : '') . ')';
        array_push($a, "%$q%", "%$q%", "%$q%"); if ($qd !== '') array_push($a, "%$qd%", "%$qd%");
    }
    return [implode(' AND ', $w), $a];
}
if ($action === 'history') {
    $all = $isAdmin || $can('history');
    [$w, $a] = inq_hist_where($pdo, $in, $all, $uid);
    $page = max(1, intval($in['page'] ?? 1)); $per = 50;
    $st = $pdo->prepare("SELECT COUNT(*) FROM inq_requests WHERE $w"); $st->execute($a); $total = intval($st->fetchColumn());
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($a);
    $out(['ok' => true, 'items' => array_map(function ($r) use ($isAdmin) { return inq_out($r, false) + ($isAdmin ? ['has_detail' => true] : []); }, $st->fetchAll()),
          'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per)), 'all' => $all]);
}
if ($action === 'history_export' || $action === 'batch_export') {
    $need('export');
    require_once __DIR__ . '/_xlsx_writer.php';
    $all = $isAdmin || $can('history');
    if ($action === 'batch_export') { $in['batch_id'] = intval($in['id'] ?? 0); $all = true; }
    [$w, $a] = inq_hist_where($pdo, $in, $all, $uid);
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE $w ORDER BY " . ($action === 'batch_export' ? 'row_no, id' : 'id DESC') . " LIMIT 20000"); $st->execute($a);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $o = inq_out($r, false);
        $rows[] = [$r['row_no'] ?: $o['id'], $o['created_j'], $o['kind_fa'], $r['national_id'], $r['birth'], $r['postal'], $o['status_fa'], $r['person_name'], $r['father_name'],
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
    $need('group_view');
    require_once __DIR__ . '/_xlsx_writer.php';
    $kind = isset(INQ_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'person_postal';
    if ($kind === 'legal') $path = xlsx_build(['شناسه ملی', 'نام (اختیاری)'], [['10101234567', 'شرکت نمونه'], ['14001234567', '']], 'شخص حقوقی');
    else $path = xlsx_build(['کد ملی', 'تاریخ تولد', 'کد پستی', 'نام (اختیاری)'], [['0012345678', '1370/05/12', '1234567890', 'علی رضایی'], ['0098765432', '1365/11/03', '9876543210', '']], 'اشخاص');
    xlsx_send($path, 'نمونه‌ی استعلامِ گروهی - ' . INQ_KINDS[$kind] . '.xlsx');
    exit;
}
if ($action === 'batch_upload') {
    $need('group');
    $kind = (string)($in['kind'] ?? '');
    if (!isset(INQ_KINDS[$kind])) $out(['ok' => false, 'error' => 'نوعِ استعلام را انتخاب کنید.']);
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
if ($action === 'batch_step') {
    $need('group');
    $bid = intval($in['id'] ?? 0);
    $r = inq_batch_tick($pdo, $bid);
    if (!empty($r['need_captcha'])) { $c = inq_captcha_get($pdo); $out(['ok' => true, 'need_captcha' => true, 'captcha' => $c['image'] ?? null, 'batch' => inq_batch_info($pdo, $bid)]); }
    $last = $r['ran'] ? inq_out(inq_get($pdo, $r['ran']), false) : null;
    $out(['ok' => true, 'batch' => inq_batch_info($pdo, $bid), 'last' => $last]);
}
if ($action === 'batches') {
    $need('group_view');
    $rows = [];
    foreach ($pdo->query("SELECT id FROM inq_batches ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN) as $id) $rows[] = inq_batch_info($pdo, $id);
    $out(['ok' => true, 'batches' => $rows]);
}
if ($action === 'batch_cancel' || $action === 'batch_resume') {
    $need('group');
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

// ---- تنظیمات و ورود به سامانه (فقط مدیر کل) ----
if (in_array($action, ['settings_get', 'settings_save', 'login', 'status'], true) && !$isAdmin) $out(['ok' => false, 'error' => 'تنظیماتِ استعلامات فقط کارِ مدیر کل است.']);
if ($action === 'settings_get') {
    $s = inq_settings($pdo);
    if (inq_get_setting($pdo, 'inq_cron_key') === '') inq_set_setting($pdo, 'inq_cron_key', bin2hex(random_bytes(16)));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'), '/');
    $cr = inq_creds($pdo);
    $out(['ok' => true, 'settings' => $s, 'username' => $cr['user'], 'has_password' => $cr['pass'] !== '',
          'cron_url' => $base . '/inquiry_actions.php?action=keepalive&key=' . inq_get_setting($pdo, 'inq_cron_key'), 'status' => inq_px_status($pdo, true)]);
}
if ($action === 'settings_save') {
    foreach (INQ_DEFAULTS as $k => $def) if (array_key_exists($k, $in)) {
        $v = in_array($k, ['inq_wait_sec', 'inq_cache_days', 'inq_daily_limit', 'inq_keep_minutes'], true) ? (string)max($k === 'inq_keep_minutes' ? 5 : 0, min($k === 'inq_wait_sec' ? 9 : ($k === 'inq_keep_minutes' ? 600 : 365), intval($in[$k]))) : (empty($in[$k]) ? '0' : '1');
        inq_set_setting($pdo, $k, $v);
    }
    if (array_key_exists('username', $in)) inq_set_setting($pdo, 'inq_px_user', trim((string)$in['username']));
    if (($in['password'] ?? '') !== '') inq_set_setting($pdo, 'inq_px_pass', inq_enc((string)$in['password']));
    if (!empty($in['new_cron_key'])) inq_set_setting($pdo, 'inq_cron_key', bin2hex(random_bytes(16)));
    $out(['ok' => true]);
}
if ($action === 'status') $out(['ok' => true, 'status' => inq_px_status($pdo, true)]);
if ($action === 'login') {
    $cr = inq_creds($pdo);
    $u = trim((string)($in['username'] ?? '')) ?: $cr['user'];
    $p = (string)($in['password'] ?? '') ?: $cr['pass'];
    if ($u === '' || $p === '') $out(['ok' => false, 'error' => 'نام کاربری و رمزِ سامانه را وارد کنید.']);
    $lk = inq_lock(20);
    if (!$lk) $out(['ok' => false, 'error' => 'سامانه مشغولِ استعلام است؛ چند ثانیه‌ی دیگر دوباره بزنید.']);
    try { $r = (new InqPasargad())->login($u, $p, trim((string)($in['captcha'] ?? ''))); } finally { inq_unlock($lk); }
    if ($r['ok']) {
        inq_px_mark('logged_in'); inq_touch_activity(); @unlink(inq_dir() . '/captcha.json');
        if (!empty($in['remember'])) { inq_set_setting($pdo, 'inq_px_user', $u); inq_set_setting($pdo, 'inq_px_pass', inq_enc($p)); }
    }
    $out($r);
}

$out(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
