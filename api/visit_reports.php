<?php
// فایل: api/visit_reports.php
// ماژولِ «گزارش بازدید خودرو» (نسخه‌ی تحت وبِ برنامه‌ی ویندوزیِ صدور گزارش بازدید)
//  - صدور: فرمِ پویا بر اساسِ فیلدهای هر نوع گزارش، استخراج از PDF، عکس‌ها، ساختِ PDF/Word روی همین هاست
//  - ویرایش (با رمزِ پنلِ خودِ کاربر): فایل‌های قبلی با «(old)» کنارِ فایل‌های تازه نگه داشته می‌شوند
//  - فهرست/جستجو/فیلتر، جزئیات، دانلود، ZIP عکس‌ها، خروجی اکسل، دفترِ اکسلِ قفل‌شده‌ی گزارشات
//  - اتصال به بازدید سلامت / درخواستِ کارکنان / ردیفِ شرکتی (فقط مدیر کل)
//  - تنظیمات (فقط مدیر کل): انواع گزارش، قالب Word، الگوریتمِ استخراج، فیلدها، بازدیدکننده‌ها،
//    بیمه‌گذاران، قلم‌ها، دسترسی‌ها، مسیرِ پایتون، رمزِ اکسل، و تاریخچه‌ی کارها
// دسترسی: مدیر کل همه‌چیز؛ بقیه فقط صدور/دیدن/ویرایشِ گزارش‌های خودشان، آن هم فقط در نوع‌هایی که مجازند.
session_start();
// اگر خطای جدیِ PHP پیش آمد (مثلاً فایلی ناقص آپلود شده یا نسخه‌ی PHP قدیمی است)، به‌جای صفحه‌ی خالی/HTML
// همان پیامِ خطا به‌صورت JSON برگردانده می‌شود تا در پنل دیده شود
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok' => false, 'error' => 'خطای سرور: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')'], JSON_UNESCAPED_UNICODE);
});
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_visit_report_helpers.php';

$siteRoot = dirname(__DIR__);
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
$action = $data['action'] ?? ($_GET['action'] ?? '');

function vr_out($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function vr_fail($msg, $extra = []) { vr_out(['ok' => false, 'error' => $msg] + $extra); }

$user = vr_current_user($pdo);
if (!$user) vr_fail('ابتدا وارد پنل شوید.');
if (!vr_ready($pdo)) vr_fail(VR_SCHEMA_MSG);
$isAdmin = $user['is_admin'];
function vr_need_admin() { global $isAdmin; if (!$isAdmin) vr_fail('این بخش فقط برای مدیر کل است.'); }

function vr_check_password($pdo, $userId, $pwd) {
    $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    return $pwd !== '' && $hash && password_verify((string)$pwd, $hash);
}

function vr_load($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM visit_reports WHERE id = ?");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function vr_load_visible($pdo, $id, $user) {
    $r = vr_load($pdo, $id);
    if (!$r || !vr_report_visible($pdo, $r, $user)) vr_fail('گزارش پیدا نشد یا به آن دسترسی ندارید.');
    return $r;
}

// ---------------------------------------------------------------------
//  فرم: پاک‌سازی و اعتبارسنجی
// ---------------------------------------------------------------------
function vr_input_form($data) {
    $f = $data['form'] ?? [];
    if (is_string($f)) $f = json_decode($f, true) ?: [];
    return is_array($f) ? $f : [];
}
function vr_clean_form(array $form, array $fields) {
    $t = fn($v) => trim(str_replace(["\r\n", "\r"], "\n", (string)$v));
    $pl = $form['plate'] ?? []; $ins = $form['insured'] ?? [];
    $out = [
        'plate' => ['p1' => preg_replace('/\D/', '', p2e_digits($pl['p1'] ?? '')), 'letter' => mb_substr($t($pl['letter'] ?? ''), 0, 3),
                    'p2' => preg_replace('/\D/', '', p2e_digits($pl['p2'] ?? '')), 'iran' => preg_replace('/\D/', '', p2e_digits($pl['iran'] ?? ''))],
        'insured' => ['name' => mb_substr($t($ins['name'] ?? ''), 0, 200), 'national_id' => mb_substr(preg_replace('/\s+/', '', p2e_digits($ins['national_id'] ?? '')), 0, 20),
                      'phone' => mb_substr(preg_replace('/\s+/', '', p2e_digits($ins['phone'] ?? '')), 0, 40), 'address' => mb_substr($t($ins['address'] ?? ''), 0, 1000),
                      'insured_id' => intval($ins['insured_id'] ?? 0) ?: null],
        'fields' => [], 'parts' => [], 'damages' => [],
    ];
    $fv = is_array($form['fields'] ?? null) ? $form['fields'] : [];
    foreach ($fields as $f) {
        $k = $f['field_key'];
        if ($f['field_type'] === 'PartsStatus') {
            foreach (vr_parts_list($f) as $p) $out['parts'][$p['id']] = (($form['parts'][$p['id']] ?? 's') === 'k') ? 'k' : 's';
            continue;
        }
        if ($f['field_type'] === 'DamageList') {
            $n = max(1, intval($f['options'] ?: 5));
            foreach (array_values((array)($form['damages'] ?? [])) as $d) {
                $loc = mb_substr($t($d['location'] ?? ''), 0, 150); $desc = mb_substr($t($d['description'] ?? ''), 0, 300);
                if ($loc === '' && $desc === '') continue;
                $out['damages'][] = ['location' => $loc, 'description' => $desc];
                if (count($out['damages']) >= $n) break;
            }
            continue;
        }
        $v = $t($fv[$k] ?? '');
        if (in_array($f['field_type'], ['Money', 'Number'], true)) $v = preg_replace('/[^\d.]/', '', p2e_digits($v));
        if ($f['field_type'] === 'Time') $v = p2e_digits($v);
        if ($f['field_type'] === 'Checkbox') $v = in_array($v, ['1', 'true', 'on', 'بله'], true) ? '1' : '';
        if ($f['max_chars'] && !in_array($f['field_type'], ['Textarea', 'Money', 'Combobox', 'Visitor'], true)) $v = mb_substr($v, 0, max($f['max_chars'], 1) * 3);
        $out['fields'][$k] = mb_substr($v, 0, 2000);
    }
    return $out;
}
function vr_validate_form(array $form, array $fields) {
    $pl = $form['plate'];
    $filled = array_filter([$pl['p1'], $pl['letter'], $pl['p2'], $pl['iran']], fn($x) => $x !== '');
    if ($filled && count($filled) < 4) return 'پلاک را کامل وارد کنید (هر چهار بخش).';
    if ($filled && (strlen($pl['p1']) !== 2 || strlen($pl['p2']) !== 3 || strlen($pl['iran']) !== 2)) return 'شکلِ پلاک درست نیست (دو رقم، حرف، سه رقم، و دو رقمِ ایران).';
    $chassis = '';
    foreach ($fields as $f) if (($f['excel_column'] ?? '') === 'شماره شاسی') $chassis = $form['fields'][$f['field_key']] ?? '';
    if (!$filled && $chassis === '') return 'پلاک یا شماره شاسی را وارد کنید.';
    if ($form['insured']['name'] === '') return 'نام بیمه‌گذار را وارد کنید.';
    foreach ($fields as $f) {
        if (!$f['required'] || in_array($f['field_type'], ['PartsStatus', 'Checkbox'], true) || !vr_field_visible($f, $form['fields'])) continue;
        if ($f['field_type'] === 'DamageList') { if (!$form['damages']) return 'حداقل یک «' . $f['label'] . '» وارد کنید.'; continue; }
        if (($form['fields'][$f['field_key']] ?? '') === '') return 'فیلدِ «' . $f['label'] . '» را پر کنید.';
    }
    return null;
}

// نامِ بازدیدکننده از فیلدِ Visitor
function vr_visitor_from_form(array $form, array $fields, $pdo, $catId) {
    foreach ($fields as $f) {
        if ($f['field_type'] !== 'Visitor') continue;
        $name = $form['fields'][$f['field_key']] ?? '';
        if ($name === '') return [null, null];
        foreach (vr_visitors($pdo, $catId) as $v) if ($v['name'] === $name) return [$v['id'], $name];
        return [null, $name];
    }
    return [null, null];
}

// ---------------------------------------------------------------------
//  عکس‌ها
// ---------------------------------------------------------------------
function vr_uploaded_images() {
    $out = [];
    if (empty($_FILES['photos'])) return $out;
    $f = $_FILES['photos'];
    $names = (array)$f['name'];
    foreach ($names as $i => $n) {
        $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
        $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
        $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        $info = @getimagesize($tmp);
        if (!$info || !in_array($ext, ['jpg', 'png', 'webp'], true)) {
            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
            if (!$ext) continue;
        }
        $out[] = ['tmp' => $tmp, 'name' => $n, 'ext' => $ext];
    }
    return $out;
}
function vr_add_photo($pdo, $siteRoot, $reportId, $photosDir, $srcAbs, $isUpload, $origName, $ext, $plateDisplay, $label, $source) {
    if (!is_dir($photosDir)) @mkdir($photosDir, 0775, true);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM visit_report_photos WHERE report_id = ?");
    $cnt->execute([$reportId]);
    $idx = intval($cnt->fetchColumn()) + 1;
    $dest = unique_dest_path($photosDir . '/' . vr_photo_name($idx, $plateDisplay, $ext, $label));
    $ok = $isUpload ? move_uploaded_file($srcAbs, $dest) : @copy($srcAbs, $dest);
    if (!$ok) return false;
    compress_image_if_needed($dest, 1572864, 2400);
    $pdo->prepare("INSERT INTO visit_report_photos (report_id, file_path, orig_name, source, sort_order) VALUES (?, ?, ?, ?, ?)")
        ->execute([$reportId, vr_rel($siteRoot, $dest), mb_substr((string)$origName, 0, 250), $source, $idx]);
    return true;
}

// ---------------------------------------------------------------------
//  ساخت/بازسازیِ یک گزارش (مشترکِ صدور و ویرایش)
// ---------------------------------------------------------------------
function vr_produce($pdo, $siteRoot, $user, $cat, array $fields, array $form, $dateDot, $dateTs, $existing, array $opts) {
    $plateDisplay = vr_plate_display($form['plate']['p1'], $form['plate']['letter'], $form['plate']['p2'], $form['plate']['iran']);
    $reportNo = $existing ? $existing['report_no'] : vr_next_report_no($pdo, $dateDot);
    $issuerName = $existing ? $existing['issuer_name'] : $user['name'];
    $values = vr_build_values($cat, $fields, $form, ['date' => $dateDot, 'issuer_name' => $issuerName, 'report_no' => $reportNo]);
    [$visitorId, $visitorName] = vr_visitor_from_form($form, $fields, $pdo, $cat['id']);
    $targetFolder = vr_report_folder($siteRoot, $dateDot, $cat['insurer'], $plateDisplay, $form['insured']['name']);
    $photosName = build_health_photos_folder_name($dateDot, $plateDisplay);

    $version = 1;
    if ($existing) {
        $version = intval($existing['version']) + 1;
        $curFolder = vr_abs($siteRoot, $existing['folder_path']);
        $folder = $curFolder;
        // تاریخ/پلاک/بیمه‌گذار عوض شده: پوشه با همه‌ی محتوایش به نامِ تازه می‌رود
        if ($curFolder !== $targetFolder && is_dir($curFolder) && !file_exists($targetFolder)) {
            @mkdir(dirname($targetFolder), 0775, true);
            if (@rename($curFolder, $targetFolder)) {
                $folder = $targetFolder;
                $oldRel = vr_rel($siteRoot, $curFolder); $newRel = vr_rel($siteRoot, $targetFolder);
                foreach (['pdf_path', 'docx_path', 'zip_path'] as $c) if ($existing[$c]) $existing[$c] = $newRel . substr($existing[$c], strlen($oldRel));
                $pdo->prepare("UPDATE visit_report_photos SET file_path = CONCAT(?, SUBSTRING(file_path, ?)) WHERE report_id = ? AND file_path LIKE ?")
                    ->execute([$newRel, mb_strlen($oldRel) + 1, $existing['id'], $oldRel . '%']);
                $pdo->prepare("UPDATE visit_report_versions SET pdf_path = CONCAT(?, SUBSTRING(pdf_path, ?)), docx_path = IF(docx_path IS NULL, NULL, CONCAT(?, SUBSTRING(docx_path, ?))) WHERE report_id = ? AND pdf_path LIKE ?")
                    ->execute([$newRel, mb_strlen($oldRel) + 1, $newRel, mb_strlen($oldRel) + 1, $existing['id'], $oldRel . '%']);
                // پوشه‌های خالیِ قبلی (ماه/بیمه‌گر) پاک می‌شوند
                $d = dirname($curFolder);
                while ($d !== vr_archive_root($siteRoot) && strlen($d) > strlen(vr_archive_root($siteRoot)) && @rmdir($d)) $d = dirname($d);
            }
        }
        // فایل‌های قبلی با (old) کنار گذاشته می‌شوند
        $oldPdf = vr_abs($siteRoot, $existing['pdf_path']); $oldDocx = vr_abs($siteRoot, $existing['docx_path']);
        $keptPdf = null; $keptDocx = null;
        if ($oldPdf && is_file($oldPdf)) { $keptPdf = vr_old_name($oldPdf); @rename($oldPdf, $keptPdf); }
        if ($oldDocx && is_file($oldDocx)) { $keptDocx = vr_old_name($oldDocx); @rename($oldDocx, $keptDocx); }
        $pdo->prepare("INSERT INTO visit_report_versions (report_id, version, pdf_path, docx_path, data_json, form_json, edited_by, edited_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$existing['id'], intval($existing['version']), vr_rel($siteRoot, $keptPdf), vr_rel($siteRoot, $keptDocx), $existing['data_json'], $existing['form_json'], $user['id'], $user['name']]);
        // پوشه‌ی عکس‌ها هم اگر نامش عوض شده
        $photoRows = $pdo->prepare("SELECT file_path FROM visit_report_photos WHERE report_id = ? LIMIT 1");
        $photoRows->execute([$existing['id']]);
        $anyPhoto = $photoRows->fetchColumn();
        $newPhotosDir = $folder . '/' . $photosName;
        if ($anyPhoto) {
            $curPhotosDir = dirname(vr_abs($siteRoot, $anyPhoto));
            if ($curPhotosDir !== $newPhotosDir && is_dir($curPhotosDir) && !file_exists($newPhotosDir) && @rename($curPhotosDir, $newPhotosDir)) {
                $oRel = vr_rel($siteRoot, $curPhotosDir); $nRel = vr_rel($siteRoot, $newPhotosDir);
                $pdo->prepare("UPDATE visit_report_photos SET file_path = CONCAT(?, SUBSTRING(file_path, ?)) WHERE report_id = ? AND file_path LIKE ?")
                    ->execute([$nRel, mb_strlen($oRel) + 1, $existing['id'], $oRel . '%']);
            }
        }
        // پلاک عوض شده: نامِ عکس‌ها هم با پلاکِ تازه
        if ($existing['plate_display'] && $plateDisplay && $existing['plate_display'] !== $plateDisplay) {
            $oldP = sanitize_folder_name($existing['plate_display']); $newP = sanitize_folder_name($plateDisplay);
            $ph = $pdo->prepare("SELECT id, file_path FROM visit_report_photos WHERE report_id = ?");
            $ph->execute([$existing['id']]);
            foreach ($ph->fetchAll() as $p) {
                $abs = vr_abs($siteRoot, $p['file_path']);
                $nb = str_replace($oldP, $newP, basename($abs));
                if ($nb === basename($abs) || !is_file($abs)) continue;
                $dest = unique_dest_path(dirname($abs) . '/' . $nb);
                if (@rename($abs, $dest)) $pdo->prepare("UPDATE visit_report_photos SET file_path = ? WHERE id = ?")->execute([vr_rel($siteRoot, $dest), $p['id']]);
            }
        }
        if ($existing['zip_path'] && is_file(vr_abs($siteRoot, $existing['zip_path']))) @unlink(vr_abs($siteRoot, $existing['zip_path']));
    } else {
        $folder = vr_unique_dir($targetFolder);
        @mkdir($folder, 0775, true);
    }
    if (!is_dir($folder)) throw new RuntimeException('ساخت پوشه‌ی بایگانیِ گزارش ممکن نشد.');

    // PDF و Word
    [$pdfAbs, $docxAbs] = vr_render_files($pdo, $cat, $values, $folder, $dateDot, $plateDisplay);

    $rec = vr_record_columns($fields, $values) + ['vehicle_type' => null, 'model_year' => null, 'color' => null, 'usage_type' => null,
                                                   'engine_no' => null, 'chassis_no' => null, 'car_value' => null, 'visitor_name' => null];
    if ($visitorName) $rec['visitor_name'] = $visitorName;
    $cols = [
        'category_id' => $cat['id'], 'category_name' => $cat['name'], 'insurer' => $cat['insurer'], 'report_date' => $dateDot, 'report_date_g' => date('Y-m-d', $dateTs),
        'plate_p1' => $form['plate']['p1'] ?: null, 'plate_letter' => $form['plate']['letter'] ?: null, 'plate_p2' => $form['plate']['p2'] ?: null,
        'plate_iran' => $form['plate']['iran'] ?: null, 'plate_display' => $plateDisplay ?: null,
        'insured_name' => $form['insured']['name'], 'national_id' => $form['insured']['national_id'] ?: null, 'phone' => $form['insured']['phone'] ?: null,
        'address' => $form['insured']['address'] ?: null, 'insured_id' => $form['insured']['insured_id'],
        'vehicle_type' => $rec['vehicle_type'], 'model_year' => $rec['model_year'], 'color' => $rec['color'], 'usage_type' => $rec['usage_type'],
        'engine_no' => $rec['engine_no'], 'chassis_no' => $rec['chassis_no'] ? strtoupper($rec['chassis_no']) : null, 'car_value' => $rec['car_value'],
        'visitor_id' => $visitorId, 'visitor_name' => $rec['visitor_name'],
        'form_json' => json_encode($form, JSON_UNESCAPED_UNICODE), 'data_json' => json_encode($values, JSON_UNESCAPED_UNICODE),
        'folder_path' => vr_rel($siteRoot, $folder), 'pdf_path' => vr_rel($siteRoot, $pdfAbs), 'docx_path' => vr_rel($siteRoot, $docxAbs), 'version' => $version,
    ];
    if ($existing) {
        $cols['updated_at'] = date('Y-m-d H:i:s'); $cols['updated_by'] = $user['id'];
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
        $pdo->prepare("UPDATE visit_reports SET $set WHERE id = ?")->execute([...array_values($cols), $existing['id']]);
        $id = intval($existing['id']);
    } else {
        $cols['report_no'] = $reportNo; $cols['issuer_user_id'] = $user['id']; $cols['issuer_name'] = $user['name'];
        $sql = "INSERT INTO visit_reports (`" . implode('`, `', array_keys($cols)) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")";
        for ($try = 0; ; $try++) {
            try { $pdo->prepare($sql)->execute(array_values($cols)); break; }
            catch (PDOException $e) {
                // شماره‌ی تکراری (دو صدورِ هم‌زمان): شماره‌ی بعدی
                if ($try < 5 && strpos($e->getMessage(), 'uq_vr_no') !== false) { $cols['report_no'] = $reportNo = vr_next_report_no($pdo, $dateDot); continue; }
                throw $e;
            }
        }
        $id = intval($pdo->lastInsertId());
    }

    // عکس‌ها: حذف‌شده‌ها با (old) کنار می‌روند؛ تازه‌ها (آپلودی یا از بازدید سلامت) اضافه می‌شوند
    $photosDir = $folder . '/' . $photosName;
    foreach ((array)($opts['remove_photos'] ?? []) as $pid) {
        $st = $pdo->prepare("SELECT * FROM visit_report_photos WHERE id = ? AND report_id = ?");
        $st->execute([intval($pid), $id]);
        if ($ph = $st->fetch()) {
            $abs = vr_abs($siteRoot, $ph['file_path']);
            if ($abs && is_file($abs)) @rename($abs, vr_old_name($abs));
            $pdo->prepare("DELETE FROM visit_report_photos WHERE id = ?")->execute([$ph['id']]);
        }
    }
    foreach ($opts['uploads'] ?? [] as $u) vr_add_photo($pdo, $siteRoot, $id, $photosDir, $u['tmp'], true, $u['name'], $u['ext'], $plateDisplay, null, 'UPLOAD');
    foreach ($opts['health_photos'] ?? [] as $hp) vr_add_photo($pdo, $siteRoot, $id, $photosDir, $hp['abs'], false, basename($hp['abs']), $hp['ext'], $plateDisplay, $hp['label'], 'HEALTH');
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM visit_report_photos WHERE report_id = ?");
    $cnt->execute([$id]);
    $photosCount = intval($cnt->fetchColumn());
    $zipAbs = $photosCount ? vr_rebuild_zip($siteRoot, $photosDir, $folder . '/' . $photosName . '.zip') : null;
    $pdo->prepare("UPDATE visit_reports SET photos_count = ?, zip_path = ? WHERE id = ?")->execute([$photosCount, vr_rel($siteRoot, $zipAbs), $id]);
    return vr_load($pdo, $id);
}

// عکس‌های انتخاب‌شده از یک بازدید سلامت => [['abs', 'ext', 'label'], ...]
function vr_health_photos_pick($pdo, $siteRoot, $inspId, array $keys) {
    if (!$keys) return [];
    $st = $pdo->prepare("SELECT * FROM health_inspections WHERE id = ?");
    $st->execute([$inspId]);
    $insp = $st->fetch();
    if (!$insp) return [];
    $photos = json_decode($insp['photos'] ?: '{}', true) ?: [];
    $steps = health_inspection_steps();
    $out = [];
    foreach ($keys as $k) {
        $k = (string)$k;
        if (!isset($photos[$k])) continue;
        $abs = health_abs_path($siteRoot, $photos[$k]);
        if (!is_file($abs)) continue;
        $parts = explode('-', $k);
        $label = ($steps[$parts[0]]['label'] ?? $k) . (isset($parts[1]) ? ' ' . $parts[1] : '');
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'jpg';
        $out[] = ['abs' => $abs, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext, 'label' => $label];
    }
    return $out;
}

function vr_link_titles($pdo, $r) {
    $out = [];
    if ($r['health_inspection_id']) {
        $st = $pdo->prepare("SELECT hi.inspection_number, hi.status, COALESCE(pc.unique_code, CONCAT('آزاد-', hi.id)) AS code FROM health_inspections hi LEFT JOIN policy_cases pc ON pc.id = hi.case_id WHERE hi.id = ?");
        $st->execute([$r['health_inspection_id']]);
        if ($h = $st->fetch()) $out[] = ['type' => 'health', 'id' => intval($r['health_inspection_id']), 'title' => 'بازدید سلامت شماره ' . $h['inspection_number'] . ' · ' . $h['code']];
    }
    if ($r['case_id']) {
        $st = $pdo->prepare("SELECT unique_code, insured_name FROM policy_cases WHERE id = ?");
        $st->execute([$r['case_id']]);
        if ($c = $st->fetch()) $out[] = ['type' => 'case', 'id' => intval($r['case_id']), 'title' => 'درخواست کارکنان ' . $c['unique_code'] . ($c['insured_name'] ? ' · ' . $c['insured_name'] : '')];
    }
    if ($r['company_plate_id']) {
        $st = $pdo->prepare("SELECT crp.request_id, c.name FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
        $st->execute([$r['company_plate_id']]);
        if ($p = $st->fetch()) $out[] = ['type' => 'company', 'id' => intval($r['company_plate_id']), 'request_id' => intval($p['request_id']), 'title' => 'ردیف شرکتی · ' . $p['name'] . ' · درخواست #' . $p['request_id']];
    }
    return $out;
}

function vr_send_file($abs, $downloadName, $inline = false) {
    if (!$abs || !is_file($abs)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'فایل پیدا نشد.'; exit; }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'zip' => 'application/zip',
              'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
              'py' => 'text/x-python; charset=utf-8'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"report.{$ext}\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=60');
    readfile($abs);
    exit;
}

// فیلترهای فهرست (مشترکِ list و export)
function vr_list_where($pdo, $data, $user) {
    $w = []; $p = [];
    $status = ($user['is_admin'] && ($data['status'] ?? '') === 'DELETED') ? 'DELETED' : 'ACTIVE';
    $w[] = 'status = ?'; $p[] = $status;
    if (!$user['is_admin']) {
        $w[] = 'issuer_user_id = ?'; $p[] = $user['id'];
        $ids = array_map(fn($c) => intval($c['id']), vr_user_categories($pdo, $user, false));
        $w[] = $ids ? 'category_id IN (' . implode(',', $ids) . ')' : '0';
    }
    $q = trim(p2e_digits((string)($data['q'] ?? '')));
    if ($q !== '') {
        $core = plate_core($q);
        $w[] = "(report_no LIKE ? OR insured_name LIKE ? OR national_id LIKE ? OR phone LIKE ? OR chassis_no LIKE ? OR engine_no LIKE ? OR vehicle_type LIKE ?
                 OR visitor_name LIKE ? OR REPLACE(REPLACE(REPLACE(plate_display, ' ', ''), '-', ''), 'ایران', '') LIKE ?)";
        $like = '%' . $q . '%';
        array_push($p, $like, $like, $like, $like, $like, $like, $like, $like, '%' . $core . '%');
    }
    if (!empty($data['category_id'])) { $w[] = 'category_id = ?'; $p[] = intval($data['category_id']); }
    if (!empty($data['insurer'])) { $w[] = 'insurer = ?'; $p[] = (string)$data['insurer']; }
    if (!empty($data['visitor'])) { $w[] = 'visitor_name = ?'; $p[] = (string)$data['visitor']; }
    if ($user['is_admin'] && !empty($data['issuer_id'])) { $w[] = 'issuer_user_id = ?'; $p[] = intval($data['issuer_id']); }
    if (!empty($data['date_from']) && ($d = vr_parse_jalali($data['date_from']))) { $w[] = 'report_date_g >= ?'; $p[] = date('Y-m-d', $d[1]); }
    if (!empty($data['date_to']) && ($d = vr_parse_jalali($data['date_to']))) { $w[] = 'report_date_g <= ?'; $p[] = date('Y-m-d', $d[1]); }
    $linked = (string)($data['linked'] ?? '');
    if ($linked === 'yes') $w[] = '(health_inspection_id IS NOT NULL OR case_id IS NOT NULL OR company_plate_id IS NOT NULL)';
    if ($linked === 'no') $w[] = '(health_inspection_id IS NULL AND case_id IS NULL AND company_plate_id IS NULL)';
    if ($linked === 'health') $w[] = 'health_inspection_id IS NOT NULL';
    if ($linked === 'case') $w[] = 'case_id IS NOT NULL AND health_inspection_id IS NULL';
    if ($linked === 'company') $w[] = 'company_plate_id IS NOT NULL';
    if (!empty($data['has_photos'])) $w[] = 'photos_count > 0';
    if (!empty($data['edited'])) $w[] = 'version > 1';
    return [implode(' AND ', $w), $p];
}

try {
    // =================================================================
    //  صدور
    // =================================================================
    if ($action === 'bootstrap') {
        $cats = [];
        foreach (vr_user_categories($pdo, $user) as $c) {
            $fields = vr_fields($pdo, $c['id']);
            foreach ($fields as &$f) {
                if ($f['field_type'] === 'PartsStatus') $f['parts'] = vr_parts_list($f);
                if ($f['field_type'] === 'Combobox') $f['choices'] = vr_combo_options($f);
                if ($f['field_type'] === 'DamageList') $f['max_rows'] = max(1, intval($f['options'] ?: 5));
                unset($f['tick_map'], $f['parser_keys'], $f['words_var']);
            }
            unset($f);
            $opts = json_decode($c['options_json'] ?? '', true) ?: [];
            $cats[] = ['id' => intval($c['id']), 'name' => $c['name'], 'insurer' => $c['insurer'], 'insurer_fa' => vr_insurer_fa($c['insurer']),
                       'ok_label' => $c['ok_label'], 'bad_label' => $c['bad_label'], 'has_template' => !empty($c['template_path']),
                       'has_parser' => !empty($c['parser_path']), 'description' => $opts['description'] ?? '',
                       'fields' => $fields, 'visitors' => vr_visitors($pdo, $c['id']), 'insureds' => vr_insureds($pdo, $c['id'])];
        }
        [$jy, $jm, $jd] = jalali_from_gregorian_ts(time());
        vr_out(['ok' => true, 'user' => $user, 'is_admin' => $isAdmin, 'categories' => $cats,
                'today' => sprintf('%04d/%02d/%02d', $jy, $jm, $jd), 'time' => vr_rounded_time(),
                'can_link' => $isAdmin, 'can_health' => $user['role'] !== 'PARSIAN']);
    }

    if ($action === 'parse_pdf') {
        $cat = vr_category($pdo, $data['category_id'] ?? 0);
        if (!$cat || !vr_can_issue($cat, $user)) vr_fail('اجازه‌ی صدور این نوع گزارش را ندارید.');
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) vr_fail('فایل PDF دریافت نشد.');
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'pdf') vr_fail('فقط فایل PDF قابل استخراج است.');
        $tmp = sys_get_temp_dir() . '/vr_parse_' . bin2hex(random_bytes(6)) . '.pdf';
        move_uploaded_file($f['tmp_name'], $tmp);
        $parser = $cat['parser_path'] ? $siteRoot . '/' . ltrim($cat['parser_path'], '/') : null;
        $res = vr_run_parser($pdo, $tmp, ($parser && is_file($parser)) ? $parser : null);
        @unlink($tmp);
        if (empty($res['ok'])) vr_fail($res['error'] ?? 'استخراج ناموفق بود.', ['debug' => $res['debug'] ?? null]);
        $fields = vr_fields($pdo, $cat['id']);
        $mapped = vr_map_parsed($res['data'] ?? [], $fields, vr_visitors($pdo, $cat['id']));
        vr_audit($pdo, $user['id'], 'VR_PARSE', 0, $cat['name'] . ' · ' . $f['name'] . ' · ' . ($res['parser_used'] ?? ''));
        vr_out(['ok' => true, 'form' => $mapped, 'parser_used' => $res['parser_used'] ?? null, 'method' => $res['method'] ?? null,
                'parser_error' => $res['parser_error'] ?? null, 'found' => count(array_filter($res['data'] ?? []))]);
    }

    // اطلاعاتِ یک بازدید سلامت برای پُرکردنِ فرمِ صدور (پاپ‌آپِ صفحه‌ی بازدیدها)
    if ($action === 'health_prefill') {
        if ($user['role'] === 'PARSIAN') vr_fail('دسترسی ندارید.');
        $id = intval($data['id'] ?? ($_GET['id'] ?? 0));
        $st = $pdo->prepare("SELECT hi.*, COALESCE(pc.plate, hi.free_plate) AS plate_txt, pc.insured_name, pc.insured_national_id, pc.insured_phone, pc.insured_address,
                                    pc.vin, pc.chassis_num, pc.engine_num, pc.car_system, pc.car_type, pc.car_model_year, pc.car_color, pc.car_usage,
                                    COALESCE(pc.car_value, pc.estimated_car_value) AS car_val, pc.unique_code, pc.insurance_type,
                                    p.full_name, p.national_code, p.mobile_number
                               FROM health_inspections hi LEFT JOIN policy_cases pc ON pc.id = hi.case_id
                               LEFT JOIN persons p ON p.id = COALESCE(pc.person_id, hi.person_id) WHERE hi.id = ?");
        $st->execute([$id]);
        $h = $st->fetch();
        if (!$h) vr_fail('بازدید پیدا نشد.');
        $raw = ['name' => $h['insured_name'] ?: $h['full_name'], 'national_id' => $h['insured_national_id'] ?: $h['national_code'],
                'phone_bimeg' => $h['insured_phone'] ?: $h['mobile_number'], 'addres_bimeg' => $h['insured_address'],
                'chassis_no' => $h['chassis_num'] ?: $h['vin'], 'engine_no' => $h['engine_num'], 'year' => $h['car_model_year'], 'color' => $h['car_color'],
                'usage' => $h['car_usage'], 'vehicle_type' => trim(($h['car_system'] ?? '') . ' ' . ($h['car_type'] ?? '')),
                'insured_value' => $h['car_val'] ? preg_replace('/\D/', '', (string)$h['car_val']) : ''];
        $raw = array_merge($raw, array_combine(['plate_part1', 'plate_letter', 'plate_part2', 'plate_part3'], array_values(vr_plate_split($h['plate_txt']))));
        $forms = [];
        foreach (vr_user_categories($pdo, $user) as $c) $forms[$c['id']] = vr_map_parsed($raw, vr_fields($pdo, $c['id']), vr_visitors($pdo, $c['id']));
        $steps = health_inspection_steps();
        $photos = json_decode($h['photos'] ?: '{}', true) ?: [];
        $list = []; $damages = [];
        foreach (health_photo_reviews($h) as $k => $rv) {
            $parts = explode('-', (string)$k);
            $label = ($steps[$parts[0]]['label'] ?? $k) . (isset($parts[1]) ? ' ' . $parts[1] : '');
            $rel = vr_rel($siteRoot, health_abs_path($siteRoot, $photos[$k]));
            $list[] = ['key' => (string)$k, 'label' => $label, 'url' => '/' . implode('/', array_map('rawurlencode', explode('/', $rel))), 'status' => $rv['status'], 'note' => $rv['note']];
            if ($rv['note'] !== '' && $rv['status'] !== 'REJECTED') $damages[] = ['location' => $label, 'description' => $rv['note']];
        }
        $ex = $pdo->prepare("SELECT id, report_no FROM visit_reports WHERE health_inspection_id = ? AND status = 'ACTIVE' ORDER BY id DESC LIMIT 1");
        $ex->execute([$id]);
        vr_out(['ok' => true, 'inspection' => ['id' => $id, 'number' => intval($h['inspection_number']), 'status' => $h['status'], 'plate' => $h['plate_txt'],
                                                'code' => $h['unique_code'] ?: ('آزاد-' . $id), 'holder' => $h['insured_name'] ?: $h['full_name'],
                                                'insurance_type' => $h['insurance_type'], 'ready' => in_array($h['status'], ['PHOTOS_APPROVED', 'APPROVED'], true)],
                'forms' => $forms, 'photos' => $list, 'damages' => $damages, 'existing' => $ex->fetch() ?: null]);
    }

    // پیش‌نمایشِ PDF از روی فرمِ ذخیره‌نشده (بدونِ ثبت و بدونِ بایگانی)
    if ($action === 'preview') {
        $existing = !empty($data['id']) ? vr_load_visible($pdo, $data['id'], $user) : null;
        $cat = vr_category($pdo, $existing ? $existing['category_id'] : ($data['category_id'] ?? 0));
        if (!$cat || !vr_can_issue($cat, $user)) vr_fail('اجازه‌ی صدور این نوع گزارش را ندارید.');
        $fields = vr_fields($pdo, $cat['id']);
        $form = vr_clean_form(vr_input_form($data), $fields);
        $date = vr_parse_jalali($data['report_date'] ?? '') ?: [jalali_from_gregorian_ts_dotted(time()), time()];
        $values = vr_build_values($cat, $fields, $form, ['date' => $date[0], 'issuer_name' => $existing['issuer_name'] ?? $user['name'],
                                                         'report_no' => $existing['report_no'] ?? 'پیش‌نمایش']);
        [$layout, $assetDir] = vr_layout($pdo, $cat);
        $opts = json_decode($cat['options_json'] ?? '', true) ?: [];
        $tmp = sys_get_temp_dir() . '/vr_prev_' . bin2hex(random_bytes(6)) . '.pdf';
        rpt_render_pdf($layout, $assetDir, $values, $tmp, ['fontMap' => vr_font_map($pdo), 'fontScale' => floatval($opts['font_scale'] ?? 1) ?: 1,
                                                           'lineHeight' => floatval($opts['line_height'] ?? 1.1) ?: 1.1]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="preview.pdf"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    if ($action === 'issue' || $action === 'edit') {
        $existing = null;
        if ($action === 'edit') {
            $existing = vr_load_visible($pdo, $data['id'] ?? 0, $user);
            if ($existing['status'] !== 'ACTIVE') vr_fail('گزارشِ حذف‌شده را نمی‌شود ویرایش کرد.');
            if (!vr_check_password($pdo, $user['id'], (string)($data['password'] ?? ''))) vr_fail('رمز عبورِ پنلِ شما نادرست است.', ['password' => true]);
            $cat = vr_category($pdo, $existing['category_id']);
        } else {
            $cat = vr_category($pdo, $data['category_id'] ?? 0);
        }
        if (!$cat || !vr_can_issue($cat, $user)) vr_fail('اجازه‌ی صدور این نوع گزارش را ندارید.');
        $fields = vr_fields($pdo, $cat['id']);
        $form = vr_clean_form(vr_input_form($data), $fields);
        if ($err = vr_validate_form($form, $fields)) vr_fail($err);
        $date = vr_parse_jalali($data['report_date'] ?? '');
        if (!$date) {
            if (trim((string)($data['report_date'] ?? '')) !== '') vr_fail('تاریخ گزارش درست نیست (مثلاً ۱۴۰۵/۰۷/۰۶).');
            $date = [jalali_from_gregorian_ts_dotted(time()), time()];
        }
        $healthId = intval($data['health_inspection_id'] ?? 0);
        $healthKeys = $data['health_photos'] ?? [];
        if (is_string($healthKeys)) $healthKeys = json_decode($healthKeys, true) ?: [];
        if ($healthId) {
            if ($user['role'] === 'PARSIAN') vr_fail('دسترسی به بازدید سلامت ندارید.');
            $h = $pdo->prepare("SELECT status FROM health_inspections WHERE id = ?");
            $h->execute([$healthId]);
            $hs = $h->fetchColumn();
            if (!$hs) vr_fail('بازدید سلامت پیدا نشد.');
            if (!in_array($hs, ['PHOTOS_APPROVED', 'APPROVED'], true)) vr_fail('اول همه‌ی عکس‌های این بازدید سلامت باید تایید شوند؛ بعد گزارش صادر می‌شود.');
        }
        $removePhotos = $data['remove_photos'] ?? [];
        if (is_string($removePhotos)) $removePhotos = json_decode($removePhotos, true) ?: [];
        $uploads = vr_uploaded_images();
        $report = vr_produce($pdo, $siteRoot, $user, $cat, $fields, $form, $date[0], $date[1], $existing,
                             ['uploads' => $uploads, 'remove_photos' => $existing ? $removePhotos : [],
                              'health_photos' => $healthId ? vr_health_photos_pick($pdo, $siteRoot, $healthId, (array)$healthKeys) : []]);
        $link = null;
        try {
            if ($healthId) $link = vr_link_health($pdo, $siteRoot, $report, $healthId, $user['id']);
            elseif ($existing) $link = vr_relink($pdo, $siteRoot, $report, $user['id']);
        } catch (Throwable $e) { $link = ['ok' => false, 'error' => $e->getMessage()]; }
        if ($link && !empty($link['ok'])) vr_audit($pdo, $user['id'], 'VR_LINK', $report['id'], ($link['target'] ?? '') . ' · ' . ($link['report'] ?? ''));
        vr_write_register($pdo, $siteRoot);
        $note = $report['report_no'] . ' · ' . ($report['plate_display'] ?: $report['chassis_no']) . ' · ' . $report['insured_name'] . ($existing ? ' · نسخه ' . $report['version'] : '');
        vr_audit($pdo, $user['id'], $existing ? 'VR_EDIT' : 'VR_ISSUE', $report['id'], $note);
        if (!$existing && !$isAdmin && function_exists('notify_admin')) {
            try { notify_admin($pdo, "📝 گزارش بازدید {$report['report_no']} صادر شد\n{$cat['name']}\n" . ($report['plate_display'] ?: '') . ' · ' . $report['insured_name'] . "\nصادرکننده: {$user['name']}"); } catch (Throwable $e) {}
        }
        vr_out(['ok' => true, 'report' => vr_row_out(vr_load($pdo, $report['id'])), 'link' => $link]);
    }

    // =================================================================
    //  فهرست، جزئیات، فایل‌ها
    // =================================================================
    if ($action === 'list') {
        [$where, $params] = vr_list_where($pdo, $data + $_GET, $user);
        $page = max(1, intval($data['page'] ?? ($_GET['page'] ?? 1)));
        $per = min(200, max(10, intval($data['per_page'] ?? 30)));
        $st = $pdo->prepare("SELECT COUNT(*) FROM visit_reports WHERE $where");
        $st->execute($params);
        $total = intval($st->fetchColumn());
        $sort = ['new' => 'id DESC', 'old' => 'id ASC', 'date' => 'report_date_g DESC, id DESC', 'value' => 'car_value DESC, id DESC'][$data['sort'] ?? 'new'] ?? 'id DESC';
        $st = $pdo->prepare("SELECT * FROM visit_reports WHERE $where ORDER BY $sort LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($params);
        $rows = array_map('vr_row_out', $st->fetchAll());
        // آمار (در محدوده‌ی دسترسیِ همین کاربر)
        [$w0, $p0] = vr_list_where($pdo, [], $user);
        [$jy, $jm] = jalali_from_gregorian_ts(time());
        $st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(report_date_g = CURDATE()) AS today, SUM(report_date LIKE ?) AS month,
                                    SUM(health_inspection_id IS NOT NULL OR case_id IS NOT NULL OR company_plate_id IS NOT NULL) AS linked FROM visit_reports WHERE $w0");
        $st->execute(array_merge([sprintf('%04d.%02d.%%', $jy, $jm)], $p0));
        $stats = array_map('intval', $st->fetch() ?: []);
        $filters = ['categories' => array_map(fn($c) => ['id' => intval($c['id']), 'name' => $c['name'], 'insurer' => $c['insurer']],
                                              $isAdmin ? $pdo->query("SELECT * FROM report_categories ORDER BY sort_order, id")->fetchAll() : vr_user_categories($pdo, $user, false)),
                    'insurers' => vr_insurers()];
        $st = $pdo->prepare("SELECT DISTINCT visitor_name FROM visit_reports WHERE $w0 AND visitor_name IS NOT NULL ORDER BY visitor_name");
        $st->execute($p0);
        $filters['visitors'] = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($isAdmin) $filters['issuers'] = $pdo->query("SELECT DISTINCT issuer_user_id AS id, issuer_name AS name FROM visit_reports ORDER BY issuer_name")->fetchAll();
        vr_out(['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'stats' => $stats, 'filters' => $filters, 'is_admin' => $isAdmin]);
    }

    if ($action === 'detail') {
        $r = vr_load_visible($pdo, $data['id'] ?? ($_GET['id'] ?? 0), $user);
        $out = vr_row_out($r);
        $out += ['address' => $r['address'], 'engine_no' => $r['engine_no'], 'color' => $r['color'], 'usage_type' => $r['usage_type'],
                 'form' => json_decode($r['form_json'] ?: '{}', true), 'folder' => $r['folder_path'], 'deleted_at' => $r['deleted_at']];
        $cat = vr_category($pdo, $r['category_id']);
        $fields = $cat ? vr_fields($pdo, $cat['id']) : [];
        // جدولِ خوانای مقدارها برای نمایش
        $form = $out['form'] ?: [];
        $display = [];
        foreach ($fields as $f) {
            if (in_array($f['field_type'], ['PartsStatus', 'DamageList'], true) || !vr_field_visible($f, $form['fields'] ?? [])) continue;
            $v = $form['fields'][$f['field_key']] ?? '';
            if ($f['field_type'] === 'Money' && $v !== '') $v = vr_money($v) . ' ریال';
            if ($f['field_type'] === 'Checkbox') $v = $v ? 'بله' : 'خیر';
            $display[] = ['label' => $f['label'], 'value' => $v, 'section' => $f['section']];
        }
        $parts = [];
        foreach ($fields as $f) if ($f['field_type'] === 'PartsStatus') foreach (vr_parts_list($f) as $p)
            $parts[] = ['id' => $p['id'], 'label' => $p['label'], 'status' => ($form['parts'][$p['id']] ?? 's')];
        $out['display'] = $display; $out['parts'] = $parts;
        $out['ok_label'] = $cat['ok_label'] ?? 'سالم'; $out['bad_label'] = $cat['bad_label'] ?? 'خسارتی';
        $st = $pdo->prepare("SELECT id, file_path, orig_name, source FROM visit_report_photos WHERE report_id = ? ORDER BY sort_order, id");
        $st->execute([$r['id']]);
        $out['photos'] = array_map(fn($p) => ['id' => intval($p['id']), 'name' => basename($p['file_path']), 'source' => $p['source']], $st->fetchAll());
        $st = $pdo->prepare("SELECT id, version, pdf_path, docx_path, edited_by_name, edited_at FROM visit_report_versions WHERE report_id = ? ORDER BY version DESC");
        $st->execute([$r['id']]);
        $out['versions'] = array_map(fn($v) => ['id' => intval($v['id']), 'version' => intval($v['version']), 'has_pdf' => (bool)$v['pdf_path'], 'has_docx' => (bool)$v['docx_path'],
                                                 'edited_by' => $v['edited_by_name'], 'edited_jalali' => jalali_from_gregorian_ts_dotted(strtotime($v['edited_at'])) . ' ' . date('H:i', strtotime($v['edited_at']))], $st->fetchAll());
        $out['links'] = vr_link_titles($pdo, $r);
        $st = $pdo->prepare("SELECT a.action_type, a.new_value, a.created_at, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                              WHERE a.target_table = 'visit_reports' AND a.target_id = ? ORDER BY a.id DESC LIMIT 50");
        $st->execute([$r['id']]);
        $acts = vr_audit_actions();
        $out['history'] = array_map(fn($h) => ['action' => $acts[$h['action_type']] ?? $h['action_type'], 'note' => $h['new_value'], 'by' => $h['full_name'],
                                                'at' => jalali_from_gregorian_ts_dotted(strtotime($h['created_at'])) . ' ' . date('H:i', strtotime($h['created_at']))], $st->fetchAll());
        $out['can_edit'] = $r['status'] === 'ACTIVE' && $cat && vr_can_issue($cat, $user);
        $out['can_admin'] = $isAdmin;
        if ($isAdmin) {
            $out['archive_path'] = ltrim(str_replace(archive_root($siteRoot), '', vr_abs($siteRoot, $r['folder_path'])), '/');
            $out['candidates'] = $r['status'] === 'ACTIVE' ? vr_match_candidates($pdo, $r) : [];
        }
        vr_out(['ok' => true, 'report' => $out]);
    }

    if ($action === 'file') {
        $r = vr_load_visible($pdo, $_GET['id'] ?? 0, $user);
        $kind = $_GET['kind'] ?? 'pdf';
        $inline = !empty($_GET['inline']);
        if (!empty($_GET['vid'])) {
            $st = $pdo->prepare("SELECT * FROM visit_report_versions WHERE id = ? AND report_id = ?");
            $st->execute([intval($_GET['vid']), $r['id']]);
            $v = $st->fetch();
            if (!$v) vr_send_file(null, '');
            $abs = vr_abs($siteRoot, $kind === 'docx' ? $v['docx_path'] : $v['pdf_path']);
            vr_send_file($abs, basename((string)$abs), $inline);
        }
        if ($kind === 'photo') {
            $st = $pdo->prepare("SELECT file_path FROM visit_report_photos WHERE id = ? AND report_id = ?");
            $st->execute([intval($_GET['pid'] ?? 0), $r['id']]);
            $abs = vr_abs($siteRoot, $st->fetchColumn() ?: null);
            vr_send_file($abs, basename((string)$abs), true);
        }
        $col = ['pdf' => 'pdf_path', 'docx' => 'docx_path', 'zip' => 'zip_path'][$kind] ?? 'pdf_path';
        $abs = vr_abs($siteRoot, $r[$col]);
        if (!$inline) vr_audit($pdo, $user['id'], 'VR_DOWNLOAD', $r['id'], $kind . ' · ' . $r['report_no']);
        vr_send_file($abs, basename((string)$abs), $inline);
    }

    // ZIP کاملِ پوشه‌ی گزارش (PDF + Word + عکس‌ها)
    if ($action === 'folder_zip') {
        $r = vr_load_visible($pdo, $_GET['id'] ?? 0, $user);
        $dir = vr_abs($siteRoot, $r['folder_path']);
        if (!is_dir($dir) || !class_exists('ZipArchive')) vr_send_file(null, '');
        $tmp = sys_get_temp_dir() . '/vr_zip_' . bin2hex(random_bytes(6)) . '.zip';
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile() || (!$isAdmin && strpos($file->getFilename(), '(old') !== false)) continue;
            if (strtolower($file->getExtension()) === 'zip') continue;
            $z->addFile($file->getPathname(), basename($dir) . '/' . substr($file->getPathname(), strlen($dir) + 1));
        }
        $z->close();
        vr_audit($pdo, $user['id'], 'VR_DOWNLOAD', $r['id'], 'folder · ' . $r['report_no']);
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"report.zip\"; filename*=UTF-8''" . rawurlencode(basename($dir) . '.zip'));
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    if ($action === 'export_excel') {
        if (!function_exists('xlsx_build')) require_once __DIR__ . '/_xlsx_writer.php';
        [$where, $params] = vr_list_where($pdo, $_GET, $user);
        $st = $pdo->prepare("SELECT * FROM visit_reports WHERE $where ORDER BY id");
        $st->execute($params);
        $rows = []; $i = 0;
        foreach ($st->fetchAll() as $r) $rows[] = vr_register_row($r, ++$i, $siteRoot);
        $tmp = xlsx_build(vr_register_columns(), $rows, 'گزارشات بازدید', [0, 20]);
        if (!$tmp) vr_fail('ساخت فایل اکسل ممکن نشد.');
        vr_audit($pdo, $user['id'], 'VR_DOWNLOAD', 0, 'excel · ' . count($rows) . ' ردیف');
        xlsx_send($tmp, 'گزارشات بازدید ' . jalali_from_gregorian_ts_dotted(time()) . '.xlsx');
        exit;
    }

    // =================================================================
    //  کارهای مدیر کل روی گزارش‌ها
    // =================================================================
    if (in_array($action, ['delete', 'restore', 'purge', 'link', 'unlink', 'match_candidates', 'rebuild_register'], true)) vr_need_admin();

    if ($action === 'delete') {
        $r = vr_load($pdo, $data['id'] ?? 0);
        if (!$r || $r['status'] !== 'ACTIVE') vr_fail('گزارش پیدا نشد.');
        $src = vr_abs($siteRoot, $r['folder_path']);
        $dest = vr_unique_dir(vr_trash_root($siteRoot) . '/' . basename($src));
        @mkdir(vr_trash_root($siteRoot), 0775, true);
        $newRel = $r['folder_path'];
        if (is_dir($src) && @rename($src, $dest)) {
            $oldRel = $r['folder_path']; $newRel = vr_rel($siteRoot, $dest);
            $fix = fn($p) => $p ? $newRel . substr($p, strlen($oldRel)) : null;
            $pdo->prepare("UPDATE visit_reports SET pdf_path = ?, docx_path = ?, zip_path = ? WHERE id = ?")->execute([$fix($r['pdf_path']), $fix($r['docx_path']), $fix($r['zip_path']), $r['id']]);
            foreach (['visit_report_photos' => 'file_path', 'visit_report_versions' => 'pdf_path'] as $t => $c)
                $pdo->prepare("UPDATE $t SET $c = CONCAT(?, SUBSTRING($c, ?)) WHERE report_id = ? AND $c LIKE ?")->execute([$newRel, mb_strlen($oldRel) + 1, $r['id'], $oldRel . '%']);
            $pdo->prepare("UPDATE visit_report_versions SET docx_path = CONCAT(?, SUBSTRING(docx_path, ?)) WHERE report_id = ? AND docx_path LIKE ?")->execute([$newRel, mb_strlen($oldRel) + 1, $r['id'], $oldRel . '%']);
            $d = dirname($src);
            while (strlen($d) > strlen(vr_archive_root($siteRoot)) && @rmdir($d)) $d = dirname($d);
        }
        $pdo->prepare("UPDATE visit_reports SET status = 'DELETED', folder_path = ?, deleted_at = NOW(), deleted_by = ? WHERE id = ?")->execute([$newRel, $user['id'], $r['id']]);
        vr_write_register($pdo, $siteRoot);
        vr_audit($pdo, $user['id'], 'VR_DELETE', $r['id'], $r['report_no'] . ' · ' . trim((string)($data['reason'] ?? '')));
        vr_out(['ok' => true]);
    }

    if ($action === 'restore') {
        $r = vr_load($pdo, $data['id'] ?? 0);
        if (!$r || $r['status'] !== 'DELETED') vr_fail('گزارش پیدا نشد.');
        $src = vr_abs($siteRoot, $r['folder_path']);
        $dest = vr_unique_dir(vr_report_folder($siteRoot, $r['report_date'], $r['insurer'], $r['plate_display'], $r['insured_name']));
        @mkdir(dirname($dest), 0775, true);
        $newRel = $r['folder_path'];
        if (is_dir($src) && @rename($src, $dest)) {
            $oldRel = $r['folder_path']; $newRel = vr_rel($siteRoot, $dest);
            $fix = fn($p) => $p ? $newRel . substr($p, strlen($oldRel)) : null;
            $pdo->prepare("UPDATE visit_reports SET pdf_path = ?, docx_path = ?, zip_path = ? WHERE id = ?")->execute([$fix($r['pdf_path']), $fix($r['docx_path']), $fix($r['zip_path']), $r['id']]);
            foreach (['visit_report_photos' => 'file_path', 'visit_report_versions' => 'pdf_path'] as $t => $c)
                $pdo->prepare("UPDATE $t SET $c = CONCAT(?, SUBSTRING($c, ?)) WHERE report_id = ? AND $c LIKE ?")->execute([$newRel, mb_strlen($oldRel) + 1, $r['id'], $oldRel . '%']);
            $pdo->prepare("UPDATE visit_report_versions SET docx_path = CONCAT(?, SUBSTRING(docx_path, ?)) WHERE report_id = ? AND docx_path LIKE ?")->execute([$newRel, mb_strlen($oldRel) + 1, $r['id'], $oldRel . '%']);
        }
        $pdo->prepare("UPDATE visit_reports SET status = 'ACTIVE', folder_path = ?, deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$newRel, $r['id']]);
        vr_write_register($pdo, $siteRoot);
        vr_audit($pdo, $user['id'], 'VR_RESTORE', $r['id'], $r['report_no']);
        vr_out(['ok' => true]);
    }

    // حذفِ همیشگی (فقط از «حذف‌شده‌ها»، با رمزِ مدیر)
    if ($action === 'purge') {
        $r = vr_load($pdo, $data['id'] ?? 0);
        if (!$r || $r['status'] !== 'DELETED') vr_fail('فقط گزارش‌های حذف‌شده را می‌شود برای همیشه پاک کرد.');
        if (!vr_check_password($pdo, $user['id'], (string)($data['password'] ?? ''))) vr_fail('رمز عبورِ مدیر (رمز خودتان) نادرست است.', ['password' => true]);
        $dir = vr_abs($siteRoot, $r['folder_path']);
        if ($dir && is_dir($dir) && strpos($dir, vr_trash_root($siteRoot)) === 0) {
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rii as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            @rmdir($dir);
        }
        $pdo->prepare("DELETE FROM visit_report_photos WHERE report_id = ?")->execute([$r['id']]);
        $pdo->prepare("DELETE FROM visit_report_versions WHERE report_id = ?")->execute([$r['id']]);
        $pdo->prepare("DELETE FROM visit_reports WHERE id = ?")->execute([$r['id']]);
        vr_audit($pdo, $user['id'], 'VR_DELETE', $r['id'], 'حذف همیشگی · ' . $r['report_no']);
        vr_out(['ok' => true]);
    }

    if ($action === 'match_candidates') {
        $r = vr_load($pdo, $data['id'] ?? 0);
        if (!$r) vr_fail('گزارش پیدا نشد.');
        vr_out(['ok' => true, 'candidates' => vr_match_candidates($pdo, $r)]);
    }

    if ($action === 'link') {
        $r = vr_load($pdo, $data['id'] ?? 0);
        if (!$r || $r['status'] !== 'ACTIVE') vr_fail('گزارش پیدا نشد.');
        $tid = intval($data['target_id'] ?? 0);
        $type = (string)($data['type'] ?? '');
        if ($type === 'health') $res = vr_link_health($pdo, $siteRoot, $r, $tid, $user['id']);
        elseif ($type === 'case') $res = vr_link_case($pdo, $siteRoot, $r, $tid, $user['id']);
        elseif ($type === 'company') $res = vr_link_company_plate($pdo, $siteRoot, $r, $tid, $user['id']);
        else vr_fail('نوعِ اتصال نامعتبر است.');
        if (!empty($res['ok'])) { vr_audit($pdo, $user['id'], 'VR_LINK', $r['id'], $type . ' #' . $tid . ' · ' . ($res['report'] ?? '')); vr_write_register($pdo, $siteRoot); }
        vr_out($res);
    }

    if ($action === 'unlink') {
        $r = vr_load($pdo, $data['id'] ?? 0);
        if (!$r) vr_fail('گزارش پیدا نشد.');
        $pdo->prepare("UPDATE visit_reports SET health_inspection_id = NULL, case_id = NULL, company_plate_id = NULL, linked_at = NULL, linked_by = NULL WHERE id = ?")->execute([$r['id']]);
        vr_audit($pdo, $user['id'], 'VR_UNLINK', $r['id'], $r['report_no'] . ' (فایل‌هایی که قبلاً در پوشه‌ی مقصد کپی شده‌اند سرِ جایشان می‌مانند)');
        vr_write_register($pdo, $siteRoot);
        vr_out(['ok' => true]);
    }

    if ($action === 'rebuild_register') {
        vr_out(['ok' => (bool)vr_write_register($pdo, $siteRoot)]);
    }

    // =================================================================
    //  تنظیمات (فقط مدیر کل)
    // =================================================================
    if (strpos($action, 'set_') === 0) {
        vr_need_admin();
        require __DIR__ . '/visit_report_settings.php';
        exit;
    }

    vr_fail('عملیات نامعتبر است.');
} catch (Throwable $e) {
    error_log('[visit_reports] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    vr_fail('خطا: ' . $e->getMessage());
}
