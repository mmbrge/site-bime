<?php
// فایل: api/_pdf_split.php
// جدا کردنِ PDFهای چندصفحه‌ای به یک PDF برای هر صفحه (با همان PyMuPDF ابزارها: api/py/pdf_tools.py op=pages)
// کاربرد: وقتی شرکت یا خودمان چند مدرک را در یک PDF می‌فرستیم، هر صفحه جدا بررسی و نوع‌گذاری می‌شود.
//   - مدارکِ شرکت‌ها: هر صفحه یک ردیفِ «تخصیص‌نیافته» در company_documents (همان صندوقِ تگ‌گذاری)
//   - مدارکِ کارکنان: صفحه‌ها در یک پوشه‌ی موقت می‌مانند تا در پنجره‌ی «تفکیکِ صفحه‌ها» نوعِ هرکدام انتخاب شود

const PDF_SPLIT_MAX_PAGES = 60;

function pdf_split_python($pdo) {
    $def = '/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3';
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'report_python_path'");
        $st->execute();
        $v = trim((string)$st->fetchColumn());
        if ($v !== '') return $v;
    } catch (Throwable $e) {}
    return is_file($def) ? $def : 'python3';
}

function pdf_split_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach ((array)scandir($dir) as $f) { if ($f === '.' || $f === '..') continue; $p = $dir . '/' . $f; is_dir($p) ? pdf_split_rrmdir($p) : @unlink($p); }
    @rmdir($dir);
}

// اجرای pdf_tools.py؛ خروجی JSONِ آخرین خط یا ['ok'=>false, 'error'=>...]
function pdf_split_run($pdo, $op, $outDir, $params, $files, $timeout = 180) {
    if (!function_exists('proc_open')) return ['ok' => false, 'error' => 'اجرای پایتون روی این سرور مجاز نیست (proc_open غیرفعال است).'];
    if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
    $pj = $outDir . '/.params.json';
    @file_put_contents($pj, json_encode((object)$params, JSON_UNESCAPED_UNICODE));
    $home = getenv('HOME');
    if (!$home && preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m)) $home = $m[1];
    $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $home ?: sys_get_temp_dir(), 'PYTHONWARNINGS' => 'ignore'];
    $proc = @proc_open(array_merge([pdf_split_python($pdo), __DIR__ . '/py/pdf_tools.py', $op, $outDir, $pj], $files), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) { @unlink($pj); return ['ok' => false, 'error' => 'اجرای پایتون ممکن نشد.']; }
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $start = time();
    while (true) {
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        $s = proc_get_status($proc);
        if (!$s['running']) break;
        if (time() - $start > $timeout) { proc_terminate($proc, 9); @unlink($pj); return ['ok' => false, 'error' => 'پردازشِ PDF بیش از حد طول کشید.']; }
        usleep(50000);
    }
    $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    @unlink($pj);
    $j = json_decode(trim((string)substr($out, strrpos("\n" . trim($out), "\n"))), true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'خروجیِ پردازشگرِ PDF خوانده نشد.', 'debug' => mb_substr(trim($err ?: $out), -500)];
    return $j;
}

// شمارِ صفحه‌های یک PDF (null یعنی معلوم نشد)
function pdf_page_count($pdo, $abs) {
    if (!is_file($abs) || strtolower(pathinfo($abs, PATHINFO_EXTENSION)) !== 'pdf') return null;
    $tmp = sys_get_temp_dir() . '/pgc_' . uniqid();
    $r = pdf_split_run($pdo, 'pages', $tmp, ['max' => 100000, 'count_only' => 1], [$abs], 60);
    pdf_split_rrmdir($tmp);
    return !empty($r['ok']) ? intval($r['pages']) : null;
}

// PDFِ $abs را صفحه‌به‌صفحه داخلِ $destDir می‌نویسد.
// خروجی: ['ok'=>true, 'pages'=>n, 'files'=>[مسیرِ مطلقِ صفحه‌ها]] — برای PDFِ تک‌صفحه‌ای files خالی است (نیازی به جدا کردن نیست).
// $baseName: پیشوندِ نامِ فایل‌ها («... - صفحه ۲ از ۵.pdf»)؛ خالی => p001.pdf, p002.pdf, ...
function pdf_split_pages($pdo, $abs, $destDir, $baseName = '', $max = PDF_SPLIT_MAX_PAGES) {
    if (!is_file($abs)) return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    if (strtolower(pathinfo($abs, PATHINFO_EXTENSION)) !== 'pdf') return ['ok' => true, 'pages' => 1, 'files' => []];
    $tmp = rtrim($destDir, '/') . '/.split_' . uniqid();
    $r = pdf_split_run($pdo, 'pages', $tmp, ['max' => $max], [$abs]);
    if (empty($r['ok'])) { pdf_split_rrmdir($tmp); return ['ok' => false, 'error' => $r['error'] ?? 'جدا کردنِ صفحه‌ها ممکن نشد.', 'pages' => $r['pages'] ?? null]; }
    $n = intval($r['pages']);
    $files = [];
    foreach ((array)($r['files'] ?? []) as $i => $f) {
        $src = $tmp . '/' . basename($f);
        if (!is_file($src)) continue;
        $name = $baseName !== '' ? pdf_split_safe_name($baseName) . ' - صفحه ' . ($i + 1) . ' از ' . $n . '.pdf' : sprintf('p%03d.pdf', $i + 1);
        $dest = function_exists('unique_dest_path') ? unique_dest_path(rtrim($destDir, '/') . '/' . $name) : rtrim($destDir, '/') . '/' . $name;
        if (@rename($src, $dest)) $files[] = $dest;
    }
    pdf_split_rrmdir($tmp);
    if ($n > 1 && count($files) !== $n) { foreach ($files as $f) @unlink($f); return ['ok' => false, 'error' => 'همه‌ی صفحه‌ها جدا نشدند.']; }
    return ['ok' => true, 'pages' => $n, 'files' => $files];
}

function pdf_split_safe_name($s) {
    $s = preg_replace('/\.pdf$/i', '', trim((string)$s));
    $s = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#u', ' ', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s !== '' ? $s : 'مدرک', 0, 80);
}

// چند PDF (یا عکس) را یکی می‌کند — برای وقتی چند صفحه یک نوع مدرک‌اند (مثلاً رو و پشتِ کارت)
function pdf_merge_files($pdo, $files, $destPath) {
    $tmp = dirname($destPath) . '/.merge_' . uniqid();
    $r = pdf_split_run($pdo, 'merge', $tmp, [], array_values($files));
    if (empty($r['ok']) || !is_file($tmp . '/out.pdf')) { pdf_split_rrmdir($tmp); return false; }
    $ok = @rename($tmp . '/out.pdf', $destPath);
    pdf_split_rrmdir($tmp);
    return $ok ? $destPath : false;
}

// =====================================================================
//  شرکت‌ها: یک ردیفِ company_documents که PDFِ چندصفحه‌ای است => یک ردیفِ «تخصیص‌نیافته» برای هر صفحه
// =====================================================================
// خروجی: null اگر لازم نبود (عکس/تک‌صفحه/نامه) یا ['ok'=>..., 'pages'=>n, 'ids'=>[...]]
// $force: مدرکِ تخصیص‌یافته (یا نامه) را هم جدا کن (دکمه‌ی «جدا کردنِ صفحه‌ها» در پنل)
function company_split_document($pdo, $siteRoot, $docId, $force = false) {
    $st = $pdo->prepare("SELECT cd.*, c.name AS company_name FROM company_documents cd JOIN companies c ON c.id = cd.company_id WHERE cd.id = ?");
    $st->execute([$docId]);
    $doc = $st->fetch();
    if (!$doc) return ['ok' => false, 'error' => 'مدرک پیدا نشد.'];
    if (!$force && ($doc['status'] !== 'UNASSIGNED' || $doc['file_kind'] === 'LETTER')) return null;
    $abs = $siteRoot . '/' . ltrim($doc['file_path'], '/');
    if (!is_file($abs) || strtolower(pathinfo($abs, PATHINFO_EXTENSION)) !== 'pdf') return $force ? ['ok' => false, 'error' => 'فقط فایلِ PDF صفحه‌به‌صفحه جدا می‌شود.'] : null;

    $destDir = company_unassigned_temp_path($siteRoot, $doc['company_name'] ?: 'نامشخص');
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $base = pdf_split_safe_name($doc['orig_name'] ?: basename($abs));
    $r = pdf_split_pages($pdo, $abs, $destDir, $base);
    if (empty($r['ok'])) return $force ? $r : null;   // آپلود خراب نمی‌شود؛ فقط جدا نمی‌شود
    if ($r['pages'] < 2) return $force ? ['ok' => false, 'error' => 'این فایل فقط یک صفحه دارد.'] : null;

    $ins = $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, plate_p1, plate_p2, plate_letter, plate_p4, status, uploaded_at)
                          VALUES (?, ?, ?, ?, ?, ?, 'SUPPORTING_DOC', ?, ?, ?, ?, ?, 'UNASSIGNED', ?)");
    $ids = [];
    $pdo->beginTransaction();
    try {
        foreach ($r['files'] as $i => $f) {
            $ins->execute([$doc['company_id'], $doc['request_id'], $doc['plate_id'], $doc['uploaded_by'], ltrim(str_replace($siteRoot, '', $f), '/'),
                           $base . ' - صفحه ' . ($i + 1) . ' از ' . $r['pages'] . '.pdf', $doc['doc_type'] === 'letter' ? null : $doc['doc_type'],
                           $doc['plate_p1'], $doc['plate_p2'], $doc['plate_letter'], $doc['plate_p4'], $doc['uploaded_at']]);
            $ids[] = intval($pdo->lastInsertId());
        }
        $pdo->prepare("DELETE FROM company_documents WHERE id = ?")->execute([$docId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        foreach ($r['files'] as $f) @unlink($f);
        return ['ok' => false, 'error' => 'ثبتِ صفحه‌ها ممکن نشد.'];
    }
    @unlink($abs);   // همه‌ی صفحه‌ها جدا ذخیره شدند؛ فایلِ ترکیبی دیگر لازم نیست
    if ($doc['status'] === 'ASSIGNED' && $doc['plate_id'] && function_exists('company_sync_plate_status')) {
        try { company_sync_plate_status($pdo, intval($doc['plate_id'])); } catch (Throwable $e) {}
    }
    if ($doc['file_kind'] === 'LETTER' && $doc['request_id']) {
        $pdo->prepare("UPDATE company_requests SET letter_file_path = NULL WHERE id = ? AND letter_file_path = ?")->execute([$doc['request_id'], $doc['file_path']]);
    }
    return ['ok' => true, 'pages' => $r['pages'], 'ids' => $ids, 'request_id' => $doc['request_id'] ? intval($doc['request_id']) : null];
}

// =====================================================================
//  کارکنان: PDFِ ترکیبی => صفحه‌ها در پوشه‌ی موقت (توکن) تا در پنجره‌ی تفکیک نوعِ هر صفحه انتخاب شود
// =====================================================================
function pdf_split_job_root($siteRoot) {
    $d = $siteRoot . '/tmp_ocr/splits';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    foreach ((array)glob($d . '/s_*', GLOB_ONLYDIR) as $old) if (@filemtime($old) < time() - 86400) pdf_split_rrmdir($old);
    return $d;
}
function pdf_split_job_dir($siteRoot, $token) {
    if (!preg_match('/^[a-f0-9]{24}$/', (string)$token)) return null;
    $d = pdf_split_job_root($siteRoot) . '/s_' . $token;
    return is_dir($d) ? $d : null;
}
// آپلودِ $_FILES => ['ok', 'token', 'pages', 'name']
function pdf_split_job_create($pdo, $siteRoot, $file, $ownerId) {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return ['ok' => false, 'error' => 'فایل دریافت نشد (شاید حجمش زیاد است).'];
    if (strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)) !== 'pdf') return ['ok' => false, 'error' => 'فقط فایلِ PDF.'];
    if (($file['size'] ?? 0) > 41943040) return ['ok' => false, 'error' => 'حجمِ فایل بیشتر از ۴۰ مگابایت است.'];
    $token = bin2hex(random_bytes(12));
    $dir = pdf_split_job_root($siteRoot) . '/s_' . $token;
    @mkdir($dir, 0755, true);
    $src = $dir . '/source.pdf';
    if (!move_uploaded_file($file['tmp_name'], $src)) { pdf_split_rrmdir($dir); return ['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.']; }
    $r = pdf_split_run($pdo, 'pages', $dir, ['max' => PDF_SPLIT_MAX_PAGES, 'force' => 1, 'thumbs' => 1], [$src]);
    if (empty($r['ok'])) { pdf_split_rrmdir($dir); return ['ok' => false, 'error' => $r['error'] ?? 'جدا کردنِ صفحه‌ها ممکن نشد.']; }
    @unlink($src);
    $name = pdf_split_safe_name($file['name']);
    @file_put_contents($dir . '/job.json', json_encode(['owner' => (string)$ownerId, 'pages' => intval($r['pages']), 'name' => $name], JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'token' => $token, 'pages' => intval($r['pages']), 'name' => $name];
}
function pdf_split_job_info($siteRoot, $token, $ownerId) {
    $dir = pdf_split_job_dir($siteRoot, $token);
    if (!$dir) return null;
    $j = json_decode((string)@file_get_contents($dir . '/job.json'), true);
    if (!is_array($j) || (string)($j['owner'] ?? '') !== (string)$ownerId || (string)$ownerId === '') return null;
    $j['dir'] = $dir;
    return $j;
}
// فایلِ یک صفحه (pdf یا png) — برای پیش‌نمایش
function pdf_split_job_page_path($siteRoot, $token, $ownerId, $n, $kind = 'pdf') {
    $j = pdf_split_job_info($siteRoot, $token, $ownerId);
    $n = intval($n);
    if (!$j || $n < 1 || $n > intval($j['pages'])) return null;
    $p = $j['dir'] . sprintf('/p%03d.', $n) . ($kind === 'png' ? 'png' : 'pdf');
    return is_file($p) ? $p : null;
}
