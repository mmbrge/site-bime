<?php
// فایل: api/_damage_inq.php
// «استعلامِ خسارت» برای هر بیمه‌نامه (شرکتی و کارکنان): خروجیِ سنهاب (ثالث) یا فایل/عکسی که دستی گرفته شده (ثالث و بدنه)،
// با «مهرِ استعلامات» (PNGِ بی‌زمینه، تنظیماتِ استعلامات ← سنهاب) پایینِ سمتِ چپ، در پوشه‌ی همان درخواست با نامِ
// «پلاک (استعلام خسارت ثالث)» یا «پلاک (استعلام خسارت بدنه)». خودروی صفر کیلومتر و بیمه‌نامه‌ی بارِ اول استعلامِ خسارت ندارند.

const DMG_DOC = 'damage_inquiry';

function dmg_stamp_path() {
    if (!function_exists('inq_dir')) require_once __DIR__ . '/_inquiry.php';
    return inq_dir('stamp') . '/stamp.png';
}
function dmg_has_stamp() { return is_file(dmg_stamp_path()); }

// ذخیره‌ی مهر: فقط PNG (شفافیت حفظ می‌شود)؛ ضلعِ بزرگ حداکثر ۱۲۰۰ پیکسل
function dmg_stamp_save(array $f) {
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) return ['ok' => false, 'error' => 'فایل دریافت نشد.'];
    if (($f['size'] ?? 0) > 5 * 1048576) return ['ok' => false, 'error' => 'حجمِ مهر حداکثر ۵ مگابایت.'];
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[2] !== IMAGETYPE_PNG) return ['ok' => false, 'error' => 'فقط فایلِ PNG (بدونِ زمینه) قابلِ قبول است.'];
    $im = @imagecreatefrompng($f['tmp_name']);
    if (!$im) return ['ok' => false, 'error' => 'فایلِ PNG خوانده نشد.'];
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    $w = imagesx($im); $h = imagesy($im);
    if (max($w, $h) > 1200) {
        $r = 1200 / max($w, $h); $nw = (int)round($w * $r); $nh = (int)round($h * $r);
        $im2 = imagecreatetruecolor($nw, $nh); imagealphablending($im2, false); imagesavealpha($im2, true);
        imagecopyresampled($im2, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($im); $im = $im2;
    }
    imagealphablending($im, false); imagesavealpha($im, true);
    $ok = imagepng($im, dmg_stamp_path());
    imagedestroy($im);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'ذخیره‌ی مهر ممکن نشد.'];
}
function dmg_stamp_remove() { $p = dmg_stamp_path(); if (is_file($p)) @unlink($p); return ['ok' => true]; }

// مهر روی یک عکس (GD): پایینِ سمتِ چپ، عرضِ مهر ۲۴٪ِ عرضِ عکس
function dmg_stamp_image($src, $dest, $ratio = 0.24) {
    $stamp = @imagecreatefrompng(dmg_stamp_path());
    $info = @getimagesize($src);
    if (!$stamp || !$info) return false;
    $im = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($src) : ($info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : @imagecreatefromjpeg($src));
    if (!$im) return false;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    $W = imagesx($im); $H = imagesy($im);
    $sw = max(40, (int)round($W * $ratio)); $sh = (int)round($sw * imagesy($stamp) / max(1, imagesx($stamp)));
    $m = (int)round($W * 0.035);
    imagealphablending($im, true);
    imagecopyresampled($im, $stamp, $m, $H - $sh - $m, 0, 0, $sw, $sh, imagesx($stamp), imagesy($stamp));
    $ok = $info[2] === IMAGETYPE_PNG ? imagepng($im, $dest) : imagejpeg($im, $dest, 90);
    imagedestroy($im); imagedestroy($stamp);
    return $ok;
}
// مهر روی فایل (PDF همه‌ی صفحه‌ها، عکس)؛ اگر مهر تنظیم نشده یا نشد، فایل بی‌تغییر کپی می‌شود. خروجی: true اگر مهر خورد
function dmg_stamp_file($src, $dest) {
    if (!dmg_has_stamp()) { if ($src !== $dest) @copy($src, $dest); return false; }
    $ext = upload_ext($src);
    $tmp = $dest . '.stamp_' . bin2hex(random_bytes(3));
    $ok = false;
    if ($ext === 'pdf') { require_once __DIR__ . '/_pdf.php'; $ok = pdfx_stamp($src, dmg_stamp_path(), $tmp, 'all'); }
    elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ok = dmg_stamp_image($src, $tmp);
    if ($ok && is_file($tmp) && filesize($tmp) > 0) { @rename($tmp, $dest); return true; }
    @unlink($tmp);
    if ($src !== $dest) @copy($src, $dest);
    return false;
}

// پیش‌نمایش: یک صفحه‌ی نمونه (A4) با مهر در جای واقعی => PNG
function dmg_stamp_preview_png() {
    $W = 595; $H = 842;
    $im = imagecreatetruecolor($W, $H);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $line = imagecolorallocate($im, 226, 232, 240); $head = imagecolorallocate($im, 30, 58, 138); $soft = imagecolorallocate($im, 241, 245, 249);
    imagefilledrectangle($im, 0, 0, $W, 70, $head);
    imagefilledrectangle($im, 40, 100, $W - 40, 160, $soft);
    for ($y = 190; $y < 640; $y += 26) imagefilledrectangle($im, 40 + (($y / 26) % 3) * 30, $y, $W - 40, $y + 8, $line);
    if (dmg_has_stamp()) {
        $tmp = tempnam(sys_get_temp_dir(), 'dmgp') . '.png';
        imagepng($im, $tmp); imagedestroy($im);
        dmg_stamp_image($tmp, $tmp);
        $data = (string)@file_get_contents($tmp); @unlink($tmp);
        return $data;
    }
    ob_start(); imagepng($im); imagedestroy($im); return ob_get_clean();
}

// ردیف/پرونده => اطلاعاتِ لازم: پلاک، نوعِ بیمه، پوشه، و این‌که استعلامِ خسارت لازم است یا نه
function dmg_target($pdo, $type, $id) {
    $siteRoot = dirname(__DIR__);
    if ($type === 'C') {
        if (!function_exists('ensure_plate_folder')) require_once __DIR__ . '/_company_helpers.php';
        $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.request_kind FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
        $st->execute([intval($id)]);
        $r = $st->fetch();
        if (!$r) return null;
        $body = $r['insurance_type'] === 'BODY';
        $why = !empty($r['is_new_vehicle']) ? 'خودروی صفر کیلومتر است' : ($body && ($r['has_prev_body'] ?? '') === 'NO' ? 'بیمه‌ی بدنه‌ی قبل ندارد (بارِ اول)' : '');
        if (($r['request_kind'] ?? 'NEW_POLICY') !== 'NEW_POLICY') $why = 'الحاقیه / فسخ';
        return ['type' => 'C', 'id' => intval($id), 'row' => $r, 'plate' => company_row_label($r) ?: ($r['chassis_no'] ?: ('ردیف ' . $id)), 'body' => $body,
                'applicable' => $why === '', 'why' => $why];
    }
    $st = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $st->execute([intval($id)]);
    $c = $st->fetch();
    if (!$c) return null;
    $body = ($c['insurance_type'] ?? '') === 'BODY';
    $prev = (string)($c['prev_body_insurance'] ?? '');
    $why = $body && in_array($prev, ['خیر', 'NO', 'no', '0'], true) ? 'بیمه‌ی بدنه‌ی قبل ندارد (بارِ اول)' : (!empty($c['is_new_vehicle']) ? 'خودروی صفر کیلومتر است' : '');
    return ['type' => 'P', 'id' => intval($id), 'row' => $c, 'plate' => trim((string)($c['plate'] ?? '')) ?: ($c['chassis_num'] ?: ('پرونده ' . $id)), 'body' => $body,
            'applicable' => $why === '', 'why' => $why];
}
function dmg_label($body) { return $body ? 'استعلام خسارت بدنه' : 'استعلام خسارت ثالث'; }
function dmg_file_name($t, $ext) {
    if (!function_exists('plate_for_filename')) require_once __DIR__ . '/_case_helpers.php';
    $plate = function_exists('plate_for_filename') ? plate_for_filename($t['plate']) : $t['plate'];
    return sanitize_folder_name($plate . ' (' . dmg_label($t['body']) . ')') . '.' . strtolower($ext ?: 'pdf');
}

// فایلِ استعلامِ خسارت => مهر + پوشه‌ی درخواست + ثبت به‌عنوانِ مدرکِ همان ردیف/پرونده
//   $src: فایلِ موقت (جابه‌جا/کپی می‌شود)؛ خروجی: ['ok', 'path', 'name', 'stamped']
function dmg_save($pdo, $type, $id, $src, $userId = null) {
    $t = dmg_target($pdo, $type, $id);
    if (!$t) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    if (!is_file($src)) return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    $ext = upload_ext($src);
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) return ['ok' => false, 'error' => 'فقط PDF یا عکس (jpg/png/webp).'];
    $siteRoot = dirname(__DIR__);
    if ($type === 'C') {
        $dir = ensure_plate_folder($pdo, $siteRoot, $t['id']);
        // ردیفِ صادرشده: پوشه‌ی نهایی
        if (!empty($t['row']['folder_path']) && is_dir($t['row']['folder_path'])) $dir = $t['row']['folder_path'];
    } else {
        if (!function_exists('resolve_case_folder')) require_once __DIR__ . '/_case_helpers.php';
        $dir = resolve_case_folder($pdo, $siteRoot, $t['row']);
    }
    // فقط داخلِ خودِ سایت (پرونده‌ای که پوشه/معرفی‌نامه‌اش پیدا نشد مسیرِ درستی نمی‌دهد)
    if (!$dir || strpos($dir, $siteRoot . '/') !== 0) return ['ok' => false, 'error' => 'پوشه‌ی این درخواست پیدا نشد.'];
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $dest = unique_dest_path($dir . '/' . dmg_file_name($t, $ext === 'jpeg' ? 'jpg' : $ext));
    $stamped = dmg_stamp_file($src, $dest);
    if (!is_file($dest)) return ['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.'];
    @unlink($src);
    $rel = ltrim(str_replace($siteRoot, '', $dest), '/');
    $label = dmg_label($t['body']);
    if ($type === 'C') {
        company_doc_label_ensure($pdo);
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, doc_label, status, assigned_by, assigned_at)
                       VALUES (?, ?, ?, NULL, ?, ?, 'SUPPORTING_DOC', ?, ?, 'ASSIGNED', ?, NOW())")
            ->execute([$t['row']['company_id'], $t['row']['request_id'], $t['id'], $rel, basename($dest), DMG_DOC, $label, $userId]);
        try { company_sync_doc_to_sadere($pdo, $siteRoot, $t['row'], $rel); } catch (Throwable $e) { error_log('[dmg sadere] ' . $e->getMessage()); }
        try { company_sync_plate_status($pdo, $t['id']); } catch (Throwable $e) {}
    } else {
        $pdo->prepare("INSERT INTO case_documents (case_id, doc_key, doc_label, file_path, status) VALUES (?, ?, ?, ?, 'APPROVED')")
            ->execute([$t['id'], DMG_DOC, $label, $rel]);
    }
    return ['ok' => true, 'path' => $rel, 'name' => basename($dest), 'stamped' => $stamped];
}

// فایل‌های استعلامِ خسارتِ ثبت‌شده‌ی یک ردیف/پرونده
function dmg_files($pdo, $type, $id) {
    try {
        if ($type === 'C') {
            $st = $pdo->prepare("SELECT id, file_path, doc_label FROM company_documents WHERE plate_id = ? AND doc_type = ? AND status IN ('ASSIGNED', 'PENDING') ORDER BY id DESC");
        } else {
            $st = $pdo->prepare("SELECT id, file_path, doc_label FROM case_documents WHERE case_id = ? AND doc_key = ? AND COALESCE(status, '') <> 'REJECTED' ORDER BY id DESC");
        }
        $st->execute([intval($id), DMG_DOC]);
        return array_map(function ($r) { return ['id' => intval($r['id']), 'path' => $r['file_path'], 'label' => $r['doc_label'] ?: 'استعلام خسارت', 'name' => basename((string)$r['file_path'])]; }, $st->fetchAll());
    } catch (Throwable $e) { return []; }
}
