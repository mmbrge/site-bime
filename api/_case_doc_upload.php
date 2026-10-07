<?php
// فایل: api/_case_doc_upload.php
// ارسالِ مدارکِ پرونده توسطِ خودِ کاربر (مینی‌اپ: webapp_visit.php و webapp_order.php)
//   - عکس (دوربین یا گالری) یا PDF (تک‌صفحه یا چندصفحه) برای هر مدرک
//   - «چند مدرک در یک PDF»: صفحه‌ها جدا و پیش‌نمایش می‌شوند و کاربر نوعِ هر صفحه را انتخاب می‌کند
// (بازدیدِ سلامت از این مسیر نمی‌گذرد؛ آن فقط با دوربین است - webapp_health.php)
require_once __DIR__ . '/_pdf_split.php';

// نوعِ واقعیِ فایل از روی محتوایش (نه نامِ فایل، که مثلاً همیشه doc.jpg فرستاده می‌شد)
function case_doc_sniff_ext($path, $origName = '') {
    $h = (string)@file_get_contents($path, false, null, 0, 16);
    if (strncmp($h, '%PDF', 4) === 0) return 'pdf';
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
    if (strncmp($h, "\x89PNG", 4) === 0) return 'png';
    if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') return 'webp';
    if (substr($h, 4, 4) === 'ftyp' && preg_match('/^(heic|heix|hevc|mif1|msf1)/', substr($h, 8, 4))) return 'heic';
    return null;
}

// اعتبارسنجیِ یک ردیف از $_FILES => ['ok'=>true, 'ext'=>...] یا ['ok'=>false, 'error'=>...]
function case_doc_check_upload($file, $maxBytes = 26214400) {
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        $big = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        return ['ok' => false, 'error' => $big ? 'حجمِ فایل بیشتر از حدِ مجازِ سرور است.' : 'فایلی دریافت نشد. لطفاً دوباره تلاش کنید.'];
    }
    if (($file['size'] ?? 0) > $maxBytes) return ['ok' => false, 'error' => 'حجمِ فایل بیشتر از ' . round($maxBytes / 1048576) . ' مگابایت است.'];
    $ext = case_doc_sniff_ext($file['tmp_name'], $file['name'] ?? '');
    if ($ext === 'heic') return ['ok' => false, 'error' => 'عکسِ HEIC پشتیبانی نمی‌شود؛ با دوربینِ همین صفحه عکس بگیرید یا عکس را JPG کنید.'];
    if (!$ext) return ['ok' => false, 'error' => 'فقط عکس (JPG/PNG/WEBP) یا PDF قابل ارسال است.'];
    return ['ok' => true, 'ext' => $ext];
}

// ذخیره‌ی یک مدرکِ کاربر (در انتظار بررسیِ کارشناس) — همان نام‌گذاریِ «(نام مدرک) کد ملی» در پوشه‌ی موقتِ پلاک
function case_doc_store_user($pdo, $siteRoot, $case, $person, $docKey, $docLabel, $srcPath, $ext, $isUpload) {
    $tmpDir = build_temp_plate_path($siteRoot, time(), $case['insured_national_id'] ?: ($person['national_code'] ?? ''), $case['plate']);
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) return ['ok' => false, 'error' => 'خطا در آماده‌سازیِ پوشه‌ی ذخیره‌سازی.'];
    $ownerNid = $case['insured_national_id'] ?: ($person['national_code'] ?? '');
    $destPath = unique_dest_path($tmpDir . '/' . sanitize_folder_name("({$docLabel}) " . ($ownerNid ?: 'نامشخص')) . '.' . $ext);
    $moved = $isUpload ? move_uploaded_file($srcPath, $destPath) : (@rename($srcPath, $destPath) || (@copy($srcPath, $destPath) && @unlink($srcPath)));
    if (!$moved) return ['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل. لطفاً دوباره تلاش کنید.'];
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) compress_image_if_needed($destPath);
    $relPath = ltrim(str_replace($siteRoot, '', $destPath), '/');
    $caseId = intval($case['id']);
    // ارسالِ دوباره‌ی مدرکی که رد شده بود در تاریخچه‌ی بررسی ثبت می‌شود
    $wasRej = $pdo->prepare("SELECT doc_label FROM case_documents WHERE case_id = ? AND doc_key = ? AND status = 'REJECTED' LIMIT 1");
    $wasRej->execute([$caseId, $docKey]);
    if (($rejLabel = $wasRej->fetchColumn()) !== false) review_log($pdo, 'CASE_DOC', 'RESUBMITTED', ['case_id' => $caseId, 'key' => $docKey, 'label' => $rejLabel, 'note' => 'ارسالِ دوباره توسط کاربر']);
    $pdo->prepare("DELETE FROM case_documents WHERE case_id = ? AND doc_key = ? AND status IN ('PENDING','REJECTED')")->execute([$caseId, $docKey]);
    $pdo->prepare("INSERT INTO case_documents (case_id, doc_key, doc_label, file_path, status) VALUES (?, ?, ?, ?, 'PENDING')")
        ->execute([$caseId, $docKey, $docLabel, $relPath]);
    return ['ok' => true, 'doc_id' => intval($pdo->lastInsertId())];
}

// مدرکی که تایید شده یا در انتظارِ بررسی است، از سمتِ کاربر عوض نمی‌شود
function case_doc_locked($pdo, $caseId, $docKey) {
    $st = $pdo->prepare("SELECT status FROM case_documents WHERE case_id = ? AND doc_key = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$caseId, $docKey]);
    return in_array($st->fetchColumn(), ['APPROVED', 'PENDING'], true);
}

// ---- «چند مدرک در یک PDF» ----
function case_doc_split_owner($person) { return 'P' . intval($person['id']); }

// ۱) آپلودِ PDF => صفحه‌ها در پوشه‌ی موقت + پیش‌نمایش
function case_doc_split_upload($pdo, $siteRoot, $person, $file) {
    $chk = case_doc_check_upload($file, 41943040);
    if (!$chk['ok']) return $chk;
    if ($chk['ext'] !== 'pdf') return ['ok' => false, 'error' => 'برای این گزینه فقط فایلِ PDF.'];
    // pdf_split_job_create نامِ فایل را برای پسوند می‌خواهد
    $file['name'] = preg_match('/\.pdf$/i', $file['name'] ?? '') ? $file['name'] : 'مدارک.pdf';
    return pdf_split_job_create($pdo, $siteRoot, $file, case_doc_split_owner($person));
}

// ۲) پیش‌نمایشِ یک صفحه (خروجیِ مستقیم به مرورگر)
function case_doc_split_serve($siteRoot, $person, $token, $n, $kind) {
    $p = pdf_split_job_page_path($siteRoot, $token, case_doc_split_owner($person), $n, $kind === 'png' ? 'png' : 'pdf');
    if (!$p) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'صفحه پیدا نشد.']); return; }
    header('Content-Type: ' . ($kind === 'png' ? 'image/png' : 'application/pdf'));
    header('Content-Disposition: inline; filename="page-' . intval($n) . '.' . ($kind === 'png' ? 'png' : 'pdf') . '"');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($p));
    readfile($p);
}

// ۳) ثبتِ صفحه‌هایی که نوعشان انتخاب شده (چند صفحه با یک نوع => یک PDF). $required = [key => label]
function case_doc_split_assign($pdo, $siteRoot, $case, $person, $token, $items, $required) {
    $job = pdf_split_job_info($siteRoot, $token, case_doc_split_owner($person));
    if (!$job) return ['ok' => false, 'error' => 'زمانِ این فایل گذشته؛ دوباره ارسال کنید.'];
    $groups = [];
    foreach ((array)$items as $it) {
        $n = intval($it['n'] ?? 0);
        $key = (string)($it['doc_key'] ?? '');
        if ($n < 1 || $n > intval($job['pages']) || !isset($required[$key])) continue;
        $groups[$key][] = $n;
    }
    if (!$groups) return ['ok' => false, 'error' => 'برای هیچ صفحه‌ای نوعِ مدرک انتخاب نشده.'];
    $saved = []; $failed = []; $locked = [];
    foreach ($groups as $key => $pages) {
        if (case_doc_locked($pdo, intval($case['id']), $key)) { $locked[] = $required[$key]; continue; }
        sort($pages);
        $files = array_values(array_filter(array_map(function ($n) use ($job) { return $job['dir'] . sprintf('/p%03d.pdf', $n); }, $pages), 'is_file'));
        $src = $files[0] ?? null;
        if ($src && count($files) > 1) $src = pdf_merge_files($pdo, $files, $job['dir'] . '/m_' . uniqid() . '.pdf');
        if (!$src) { $failed[] = $required[$key]; continue; }
        $r = case_doc_store_user($pdo, $siteRoot, $case, $person, $key, $required[$key], $src, 'pdf', false);
        if (!empty($r['ok'])) $saved[] = ['label' => $required[$key], 'pages' => $pages]; else $failed[] = $required[$key];
    }
    pdf_split_rrmdir($job['dir']);
    $err = $saved ? null : ($locked ? 'این مدارک قبلاً ارسال یا تایید شده‌اند: ' . implode('، ', $locked) : 'ذخیره‌ی صفحه‌ها ممکن نشد.');
    return ['ok' => (bool)$saved, 'saved' => $saved, 'failed' => $failed, 'locked' => $locked, 'error' => $err];
}
