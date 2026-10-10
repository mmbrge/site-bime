<?php
// فایل: api/tools_actions.php
// «ابزارها»ی کارمند (صفحه‌ی ابزارها + تبِ ابزارِ جعبه‌ابزار، tools.js):
//   boot           : ابزارهای روشنِ همین کاربر (امکاناتِ رفاهی، cf_features) + تنظیماتِ ابزارها
//   settings_save  : تنظیماتِ ابزارها (فقط مدیر کل): انواعِ «تاریخِ قسط»، پیش‌فرض‌های عکس، اقساط و سقفِ PDF
//   up / pdf_run / dl : کارهای PDF سمتِ سرور (PyMuPDF): آپلودِ تکه‌تکه، پردازش و دانلودِ یک‌باره‌ی خروجی
// کارهای عکس، تاریخ، اقساط و متن کاملاً داخلِ مرورگر انجام می‌شوند.
if (($_GET['portal'] ?? '') === '1') session_name('bime_company_portal');
session_start();
require_once __DIR__ . '/_dl_name.php';   // نامِ درستِ فایل‌های دانلودی (بدونِ نویسه‌های نامرئی و «(ماه)»)
require '../config/db.php';
require_once __DIR__ . '/_comfort.php';

function tlo($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$A = cf_actor();
if (!$A) { http_response_code(401); tlo(['ok' => false, 'error' => 'ابتدا وارد شوید.', 'session_expired' => true]); }
cf_ensure($pdo);
$raw = json_decode(file_get_contents('php://input'), true) ?: [];
$data = $raw + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
[$AT, $AID] = [$A[0], $A[1]];
$isAdmin = cf_is_admin($A);
// آپلود و پردازش طول می‌کشد؛ قفلِ نشست آزاد می‌شود
if (in_array($action, ['up', 'pdf_run', 'dl'], true) && session_status() === PHP_SESSION_ACTIVE) session_write_close();
$F = cf_features($pdo, $AT, $AID);
$tools = [];
foreach ($F as $k => $v) if (strpos($k, 'tool_') === 0) $tools[substr($k, 5)] = (bool)$v;

const TL_DEFAULTS = [
    // «تاریخِ قسط»: هر نوع = نام + چند روز/ماه جلوتر (ماه‌ها با طولِ واقعیِ ۳۱/۳۰/۲۹/۳۰ روزه)
    'pdate_types' => [['name' => 'هامرز', 'n' => 45, 'unit' => 'day'], ['name' => 'پایا', 'n' => 90, 'unit' => 'day']],
    'pdate_title' => 'تاریخِ قسطِ پارسیان',
    'img' => ['quality' => 75, 'max_side' => 1920, 'format' => 'jpeg'],
    'batch_max' => 60,
    'pdf_max_mb' => 60,
    'inst' => ['count' => 9, 'offset' => 1, 'round' => 1000],
];
function tl_settings($pdo) {
    $s = TL_DEFAULTS;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tools_settings'");
        $st->execute();
        $v = json_decode((string)$st->fetchColumn(), true);
        if (is_array($v)) foreach ($v as $k => $x) if (array_key_exists($k, TL_DEFAULTS)) $s[$k] = is_array(TL_DEFAULTS[$k]) && !isset(TL_DEFAULTS[$k][0]) && is_array($x) ? array_merge(TL_DEFAULTS[$k], $x) : $x;
    } catch (Throwable $e) {}
    return $s;
}
function tl_dir() {
    $d = dirname(__DIR__) . '/tmp_ocr/tools';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    // کارهای رهاشده‌ی قدیمی‌تر از یک روز
    foreach ((array)glob($d . '/*') as $f) if (@filemtime($f) < time() - 86400) { if (is_dir($f)) { array_map('unlink', (array)glob($f . '/*')); @rmdir($f); } else @unlink($f); }
    return $d;
}
function tl_python($pdo) {
    $def = '/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3';
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'report_python_path'");
        $st->execute();
        $v = trim((string)$st->fetchColumn());
        if ($v !== '') return $v;
    } catch (Throwable $e) {}
    return is_file($def) ? $def : 'python3';
}

try {
    if ($action === 'boot') {
        tlo(['ok' => true, 'enabled' => !empty($F['tools']), 'tools' => $tools, 'settings' => tl_settings($pdo), 'is_admin' => $isAdmin, 'kind' => $AT]);
    }

    if ($action === 'settings_save') {
        if (!$isAdmin) tlo(['ok' => false, 'error' => 'تنظیماتِ ابزارها فقط کارِ مدیر کل است.']);
        $in = (array)($data['settings'] ?? []);
        $s = tl_settings($pdo);
        if (isset($in['pdate_types'])) {
            $types = [];
            foreach ((array)$in['pdate_types'] as $t) {
                $name = trim(mb_substr((string)($t['name'] ?? ''), 0, 40));
                $n = intval(p2e_digits((string)($t['n'] ?? 0)));
                if ($name === '' || $n < 1 || $n > 999) continue;
                $types[] = ['name' => $name, 'n' => $n, 'unit' => ($t['unit'] ?? '') === 'month' ? 'month' : 'day'];
                if (count($types) >= 30) break;
            }
            $s['pdate_types'] = $types;
        }
        if (isset($in['pdate_title'])) $s['pdate_title'] = trim(mb_substr((string)$in['pdate_title'], 0, 60)) ?: TL_DEFAULTS['pdate_title'];
        if (isset($in['img']) && is_array($in['img'])) {
            $s['img'] = ['quality' => max(30, min(95, intval($in['img']['quality'] ?? 75))), 'max_side' => max(320, min(8000, intval($in['img']['max_side'] ?? 1920))),
                         'format' => in_array($in['img']['format'] ?? '', ['jpeg', 'webp', 'png'], true) ? $in['img']['format'] : 'jpeg'];
        }
        if (isset($in['batch_max'])) $s['batch_max'] = max(1, min(300, intval($in['batch_max'])));
        if (isset($in['pdf_max_mb'])) $s['pdf_max_mb'] = max(1, min(300, intval($in['pdf_max_mb'])));
        if (isset($in['inst']) && is_array($in['inst'])) {
            $s['inst'] = ['count' => max(1, min(60, intval($in['inst']['count'] ?? 9))), 'offset' => max(0, min(12, intval($in['inst']['offset'] ?? 1))),
                          'round' => in_array(intval($in['inst']['round'] ?? 1000), [1, 10, 100, 1000, 10000, 100000], true) ? intval($in['inst']['round']) : 1000];
        }
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tools_settings', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
        tlo(['ok' => true, 'settings' => tl_settings($pdo)]);
    }

    // ---------------- PDF (سمتِ سرور) ----------------
    if (in_array($action, ['up', 'pdf_run', 'dl'], true) && empty($tools['pdf'])) tlo(['ok' => false, 'error' => 'ابزارِ PDF برای شما فعال نیست.']);
    $set = tl_settings($pdo);
    $maxBytes = intval($set['pdf_max_mb']) * 1024 * 1024;
    $uidOk = function ($u) { return preg_match('/^[a-z0-9]{8,40}$/', (string)$u) ? (string)$u : null; };

    // آپلودِ تکه‌تکه (هر تکه حداکثر ۲ مگابایت) با offset تا محدودیتِ آپلودِ هاست مشکلی نسازد
    if ($action === 'up') {
        $uid = $uidOk($data['upload_id'] ?? '');
        if (!$uid) tlo(['ok' => false, 'error' => 'شناسه‌ی آپلود نامعتبر است.']);
        $f = $_FILES['chunk'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) tlo(['ok' => false, 'error' => 'تکه‌ی فایل نرسید؛ دوباره امتحان کنید.']);
        if ($f['size'] > 3 * 1024 * 1024) tlo(['ok' => false, 'error' => 'تکه‌ی فایل بزرگ است.']);
        if (intval($data['size'] ?? 0) > $maxBytes) tlo(['ok' => false, 'error' => 'حجمِ هر فایل حداکثر ' . intval($set['pdf_max_mb']) . ' مگابایت است.']);
        $part = tl_dir() . '/' . $AT . $AID . '_' . $uid . '.part';
        $idx = intval($data['index'] ?? 0); $off = intval($data['offset'] ?? 0);
        if ($idx === 0) @unlink($part);
        clearstatcache();
        $have = is_file($part) ? filesize($part) : 0;
        if ($have !== $off) tlo(['ok' => false, 'resync' => true, 'have' => $have, 'error' => 'جای ادامه‌ی آپلود عوض شده.']);
        $h = fopen($part, 'ab'); fwrite($h, file_get_contents($f['tmp_name'])); fclose($h);
        clearstatcache();
        if (filesize($part) > $maxBytes) { @unlink($part); tlo(['ok' => false, 'error' => 'حجمِ هر فایل حداکثر ' . intval($set['pdf_max_mb']) . ' مگابایت است.']); }
        if ($idx === 0) {
            $head = (string)@file_get_contents($part, false, null, 0, 5);
            if ($head !== '%PDF-') { @unlink($part); tlo(['ok' => false, 'error' => 'این فایل PDF نیست.']); }
        }
        tlo(['ok' => true, 'have' => filesize($part)]);
    }

    if ($action === 'pdf_run') {
        $op = (string)($data['op'] ?? '');
        if (!in_array($op, ['merge', 'extract', 'split', 'compress', 'toimg', 'rotate'], true)) tlo(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
        $ids = array_values(array_filter(array_map($uidOk, (array)($data['files'] ?? []))));
        if (!$ids) tlo(['ok' => false, 'error' => 'اول فایل را انتخاب کنید.']);
        if ($op === 'merge' && count($ids) < 2) tlo(['ok' => false, 'error' => 'برای ادغام دست‌کم دو فایل لازم است.']);
        if ($op !== 'merge') $ids = [$ids[0]];
        if (count($ids) > 40) tlo(['ok' => false, 'error' => 'حداکثر ۴۰ فایل.']);
        if (!function_exists('proc_open')) tlo(['ok' => false, 'error' => 'اجرای پایتون روی این سرور مجاز نیست (proc_open غیرفعال است).']);
        $dir = tl_dir();
        $job = $dir . '/job_' . $AT . $AID . '_' . bin2hex(random_bytes(6));
        @mkdir($job, 0777, true);
        $files = [];
        foreach ($ids as $i => $u) {
            $part = $dir . '/' . $AT . $AID . '_' . $u . '.part';
            if (!is_file($part)) tlo(['ok' => false, 'error' => 'فایلِ آپلودشده پیدا نشد؛ دوباره انتخاب کنید.']);
            $files[] = $job . '/in_' . sprintf('%02d', $i) . '.pdf';
            @rename($part, end($files));
        }
        $params = ['base' => trim(mb_substr(preg_replace('/\.pdf$/i', '', (string)($data['base'] ?? 'خروجی')), 0, 80)) ?: 'خروجی',
                   'pages' => mb_substr((string)($data['pages'] ?? ''), 0, 200), 'quality' => intval($data['quality'] ?? 60), 'dpi' => intval($data['dpi'] ?? 0),
                   'format' => ($data['format'] ?? '') === 'png' ? 'png' : 'jpg', 'angle' => intval($data['angle'] ?? 90)];
        file_put_contents($job . '/params.json', json_encode($params, JSON_UNESCAPED_UNICODE));
        @set_time_limit(400);
        $home = getenv('HOME');
        if (!$home && preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m)) $home = $m[1];
        $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $home ?: sys_get_temp_dir(), 'PYTHONWARNINGS' => 'ignore'];
        $proc = @proc_open(array_merge([tl_python($pdo), __DIR__ . '/py/pdf_tools.py', $op, $job, $job . '/params.json'], $files), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) tlo(['ok' => false, 'error' => 'اجرای پایتون ممکن نشد.']);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $err = ''; $t0 = time();
        while (true) {
            $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
            $s = proc_get_status($proc);
            if (!$s['running']) break;
            if (time() - $t0 > 360) { proc_terminate($proc, 9); tlo(['ok' => false, 'error' => 'پردازش بیش از حد طول کشید.']); }
            usleep(80000);
        }
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        foreach ($files as $f) @unlink($f);
        $j = json_decode(trim((string)substr($out, strrpos("\n" . trim($out), "\n"))), true);
        if (!is_array($j)) { error_log('[tools pdf] ' . mb_substr($err ?: $out, -600)); tlo(['ok' => false, 'error' => 'خروجیِ پردازشگرِ PDF خوانده نشد.']); }
        if (empty($j['ok'])) { if (!empty($j['debug'])) error_log('[tools pdf] ' . $j['debug']); tlo(['ok' => false, 'error' => $j['error'] ?? 'خطا']); }
        $token = basename($job) . '__' . basename((string)$j['file']);
        file_put_contents($job . '/name.txt', (string)$j['name']);
        tlo(['ok' => true, 'token' => $token, 'name' => $j['name'], 'size' => intval($j['size'] ?? 0), 'pages' => intval($j['pages'] ?? 0), 'before' => intval($j['before'] ?? 0)]);
    }

    if ($action === 'dl') {
        $t = (string)($data['token'] ?? '');
        if (!preg_match('/^(job_' . $AT . $AID . '_[a-f0-9]{12})__(out\.(pdf|zip|jpg|png))$/', $t, $m)) { http_response_code(404); exit('not found'); }
        $job = tl_dir() . '/' . $m[1];
        $path = $job . '/' . $m[2];
        if (!is_file($path)) { http_response_code(404); exit('فایل پیدا نشد (منقضی شده)؛ دوباره بسازید.'); }
        $name = str_replace("\u{200C}", ' ', (string)@file_get_contents($job . '/name.txt') ?: $m[2]);   // نیم‌فاصله در نامِ فایل «_» می‌شود
        $mime = ['pdf' => 'application/pdf', 'zip' => 'application/zip', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$m[3]];
        header('Content-Type: ' . $mime);
        header("Content-Disposition: attachment; filename=\"" . $m[2] . "\"; filename*=UTF-8''" . rawurlencode(dl_name($name)));
        header('Content-Length: ' . filesize($path));
        readfile($path);
        // یک‌بار دانلود: پاک می‌شود
        array_map('unlink', (array)glob($job . '/*')); @rmdir($job);
        exit;
    }

    tlo(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[tools_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    tlo(['ok' => false, 'error' => 'خطای سرور.']);
}
