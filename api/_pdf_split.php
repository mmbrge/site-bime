<?php
// فایل: api/_pdf_split.php
// جدا کردنِ PDFهای چندصفحه‌ای به یک PDF برای هر صفحه (موتورِ PHPِ api/_pdf_tools.php op=pages)
// کاربرد: وقتی شرکت یا خودمان چند مدرک را در یک PDF می‌فرستیم، هر صفحه جدا بررسی و نوع‌گذاری می‌شود.
//   - مدارکِ شرکت‌ها: هر صفحه یک ردیفِ «تخصیص‌نیافته» در company_documents (همان صندوقِ تگ‌گذاری)
//   - مدارکِ کارکنان: صفحه‌ها در یک پوشه‌ی موقت می‌مانند تا در پنجره‌ی «تفکیکِ صفحه‌ها» نوعِ هرکدام انتخاب شود

const PDF_SPLIT_MAX_PAGES = 60;

// (قبلاً با پایتون؛ حالا موتورِ PHPِ api/_pdf_tools.php — بدونِ برنامه‌ی بیرونی)
function pdf_split_python($pdo) { return ''; }

function pdf_split_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach ((array)scandir($dir) as $f) { if ($f === '.' || $f === '..') continue; $p = $dir . '/' . $f; is_dir($p) ? pdf_split_rrmdir($p) : @unlink($p); }
    @rmdir($dir);
}

// اجرای یکی از ابزارهای PDF؛ خروجی همان JSONِ قبلی یا ['ok'=>false, 'error'=>...]
function pdf_split_run($pdo, $op, $outDir, $params, $files, $timeout = 180) {
    require_once __DIR__ . '/_pdf_tools.php';
    if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
    return pdftool_run($op, $outDir, (array)$params, array_values($files));
}

// شمارِ صفحه‌های یک PDF (null یعنی معلوم نشد)
function pdf_page_count($pdo, $abs) {
    if (!is_file($abs) || upload_ext($abs) !== 'pdf') return null;
    require_once __DIR__ . '/_pdf.php';
    return pdfx_page_count($abs);
}

// PDFِ $abs را صفحه‌به‌صفحه داخلِ $destDir می‌نویسد.
// خروجی: ['ok'=>true, 'pages'=>n, 'files'=>[مسیرِ مطلقِ صفحه‌ها]] — برای PDFِ تک‌صفحه‌ای files خالی است (نیازی به جدا کردن نیست).
// $baseName: پیشوندِ نامِ فایل‌ها («... - صفحه ۲ از ۵.pdf»)؛ خالی => p001.pdf, p002.pdf, ...
function pdf_split_pages($pdo, $abs, $destDir, $baseName = '', $max = PDF_SPLIT_MAX_PAGES) {
    if (!is_file($abs)) return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    if (upload_ext($abs) !== 'pdf') return ['ok' => true, 'pages' => 1, 'files' => []];
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

// ---------------- «شرایط عمومی و خصوصی»ِ بیمه‌نامه‌ی بدنه ----------------
// فایلی جدا کنارِ خودِ بیمه‌نامه (همان پوشه): «شرایط عمومی و خصوصی ‎- <نامِ فایلِ بیمه‌نامه>.pdf». خودِ بیمه‌نامه دست نمی‌خورد.
function policy_conditions_path($policyAbs) {
    $sep = defined('NAME_SEP') ? NAME_SEP : " \u{200E}- ";
    return dirname($policyAbs) . '/شرایط عمومی و خصوصی' . $sep . pathinfo($policyAbs, PATHINFO_FILENAME) . '.pdf';
}
function policy_conditions_make($pdo, $policyAbs) {
    if (!is_file($policyAbs) || upload_ext($policyAbs) !== 'pdf') return ['ok' => false, 'error' => 'فایلِ PDFِ بیمه‌نامه پیدا نشد.'];
    $dest = policy_conditions_path($policyAbs);
    if (is_file($dest)) return ['ok' => true, 'path' => $dest, 'existed' => true];
    $tmp = dirname($policyAbs) . '/.cond_' . uniqid();
    $r = pdf_split_run($pdo, 'conditions', $tmp, [], [$policyAbs], 120);
    if (empty($r['ok']) || !is_file($tmp . '/out.pdf')) { pdf_split_rrmdir($tmp); return ['ok' => false, 'error' => $r['error'] ?? 'جدا کردنِ صفحه‌های شرایط ممکن نشد.']; }
    $ok = @rename($tmp . '/out.pdf', $dest);
    pdf_split_rrmdir($tmp);
    return $ok ? ['ok' => true, 'path' => $dest, 'pages' => $r['pages'] ?? null, 'from' => $r['from'] ?? null, 'detected' => !empty($r['detected'])] : ['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.'];
}

// ---------------- فایلِ بیمه‌نامه فقط چند صفحه‌ی اول ----------------
// تنظیمات ← «تعداد صفحه‌های فایلِ بیمه‌نامه» (پیش‌فرض ۱). هنگامِ صدور (تکی، گروهی، کارکنان) فایلِ بیمه‌نامه به همین تعداد صفحه
// کوتاه می‌شود و بقیه‌ی صفحه‌ها گم نمی‌شوند: بدنه => «شرایط عمومی و خصوصی»، بقیه => «صفحه‌های تکمیلی» (هر دو کنارِ بیمه‌نامه).
function policy_pages_setting($pdo) {
    try {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'policy_file_pages'")->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') return max(1, min(20, intval($v)));
    } catch (Throwable $e) {}
    return 1;
}
function policy_extras_path($policyAbs) {
    $sep = defined('NAME_SEP') ? NAME_SEP : " \u{200E}- ";
    return dirname($policyAbs) . '/صفحه‌های تکمیلی' . $sep . pathinfo($policyAbs, PATHINFO_FILENAME) . '.pdf';
}
function policy_finalize_file($pdo, $policyAbs, $insuranceType) {
    if (!is_file($policyAbs) || upload_ext($policyAbs) !== 'pdf') return ['ok' => false, 'skipped' => true];
    if (!empty($GLOBALS['POLICY_KEEP_ALL_PAGES'])) return ['ok' => true, 'skipped' => true];   // «صفحه‌های پرداخت هم بیاید» در صدورِ گروهی
    $n = policy_pages_setting($pdo);
    $tmp = dirname($policyAbs) . '/.trim_' . uniqid();
    $r = pdf_split_run($pdo, 'keep_first', $tmp, ['n' => $n], [$policyAbs], 120);
    if (empty($r['ok'])) { pdf_split_rrmdir($tmp); return ['ok' => false, 'error' => $r['error'] ?? 'کوتاه کردنِ فایلِ بیمه‌نامه ممکن نشد.']; }
    if (empty($r['rest']) || !is_file($tmp . '/head.pdf')) { pdf_split_rrmdir($tmp); return ['ok' => true, 'pages' => intval($r['total'] ?? 0), 'trimmed' => false]; }
    $restDest = strtoupper((string)$insuranceType) === 'BODY' ? policy_conditions_path($policyAbs) : policy_extras_path($policyAbs);
    if (!is_file($restDest)) @rename($tmp . '/rest.pdf', $restDest);
    $ok = is_file($restDest) && @rename($tmp . '/head.pdf', $policyAbs);   // اول صفحه‌های بعدی امن ذخیره شوند، بعد بیمه‌نامه کوتاه شود
    pdf_split_rrmdir($tmp);
    return ['ok' => $ok, 'pages' => intval($r['kept'] ?? $n), 'rest' => intval($r['rest'] ?? 0), 'rest_file' => $ok ? $restDest : null, 'trimmed' => $ok];
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
    if (!is_file($abs) || upload_ext($abs) !== 'pdf') return $force ? ['ok' => false, 'error' => 'فقط فایلِ PDF صفحه‌به‌صفحه جدا می‌شود.'] : null;

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
    return pdf_split_job_create_ex($pdo, $siteRoot, $file, $ownerId, false);
}
// $allowImages: عکس (jpg/png/webp) هم پذیرفته و اول به PDFِ یک‌صفحه‌ای تبدیل می‌شود
function pdf_split_job_create_ex($pdo, $siteRoot, $file, $ownerId, $allowImages = false, $meta = []) {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return ['ok' => false, 'error' => 'فایل دریافت نشد (شاید حجمش زیاد است).'];
    $ext = upload_ext($file['name'] ?? '');
    $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
    if ($ext !== 'pdf' && !($allowImages && $isImg)) return ['ok' => false, 'error' => $allowImages ? 'فقط PDF یا عکس.' : 'فقط فایلِ PDF.'];
    if (($file['size'] ?? 0) > 41943040) return ['ok' => false, 'error' => 'حجمِ فایل بیشتر از ۴۰ مگابایت است.'];
    $token = bin2hex(random_bytes(12));
    $dir = pdf_split_job_root($siteRoot) . '/s_' . $token;
    @mkdir($dir, 0755, true);
    $src = $dir . '/source.pdf';
    if ($isImg) {
        $img = $dir . '/img.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $img) || !pdf_merge_files($pdo, [$img], $src)) { pdf_split_rrmdir($dir); return ['ok' => false, 'error' => 'خواندنِ عکس ممکن نشد.']; }
        @unlink($img);
    } elseif (!move_uploaded_file($file['tmp_name'], $src)) { pdf_split_rrmdir($dir); return ['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.']; }
    return pdf_split_job_finish($pdo, $dir, $token, $file['name'], $ownerId, $meta);
}
// همان کار برای فایلی که از قبل روی سرور است (دکمه‌ی «جداسازی» زیرِ هر PDFِ بارگذاری‌شده) — فایلِ اصلی دست نمی‌خورد
// $meta کنارِ job.json نگه داشته می‌شود (مثلاً doc_id تا هنگامِ ثبت معلوم باشد این صفحه‌ها از کدام مدرک‌اند)
function pdf_split_job_from_path($pdo, $siteRoot, $abs, $name, $ownerId, $meta = []) {
    if (!is_file($abs) || upload_ext($abs) !== 'pdf') return ['ok' => false, 'error' => 'فقط فایلِ PDF صفحه‌به‌صفحه جدا می‌شود.'];
    $token = bin2hex(random_bytes(12));
    $dir = pdf_split_job_root($siteRoot) . '/s_' . $token;
    @mkdir($dir, 0755, true);
    if (!@copy($abs, $dir . '/source.pdf')) { pdf_split_rrmdir($dir); return ['ok' => false, 'error' => 'خواندنِ فایل ممکن نشد.']; }
    return pdf_split_job_finish($pdo, $dir, $token, $name, $ownerId, $meta);
}
function pdf_split_job_finish($pdo, $dir, $token, $origName, $ownerId, $meta = []) {
    $src = $dir . '/source.pdf';
    $r = pdf_split_run($pdo, 'pages', $dir, ['max' => PDF_SPLIT_MAX_PAGES, 'force' => 1, 'thumbs' => 1], [$src]);
    if (empty($r['ok'])) { pdf_split_rrmdir($dir); return ['ok' => false, 'error' => $r['error'] ?? 'جدا کردنِ صفحه‌ها ممکن نشد.']; }
    @unlink($src);
    $name = pdf_split_safe_name($origName);
    @file_put_contents($dir . '/job.json', json_encode(array_merge($meta, ['owner' => (string)$ownerId, 'pages' => intval($r['pages']), 'name' => $name]), JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'token' => $token, 'pages' => intval($r['pages']), 'name' => $name];
}
// صفحه‌های انتخاب‌شده‌ی یک کار (به ترتیب) => یک PDF در $destPath (یک صفحه => کپی، چند صفحه => ادغام)
function pdf_split_job_take($pdo, $job, array $pages, $destPath) {
    sort($pages);
    $files = array_values(array_filter(array_map(function ($n) use ($job) { return $job['dir'] . sprintf('/p%03d.pdf', $n); }, $pages), 'is_file'));
    if (!$files) return false;
    if (count($files) === 1) return @copy($files[0], $destPath) ? $destPath : false;
    return pdf_merge_files($pdo, $files, $destPath);
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
