<?php
// فایل: api/_company_helpers.php
// توابع مربوط به ماژول «شرکت‌ها»: نام‌گذاری/مسیر بایگانی و کنترل دسترسی.
// برای اجزای مشترک (تاریخ جلالی، sanitize_folder_name، name_join و ...)
// به‌جای تکرار، از api/_case_helpers.php استفاده می‌شود (باید قبل از این فایل include شده باشد).

// نام کامل نمایشی پلاک از روی ۴ بخش، هم‌شکل با پلاک‌های پرسنلی («۱۲ایران - ۳۴۵ الف ۶۷»)
function company_plate_display($p1, $p2, $letter, $p4) {
    $p1 = trim((string)$p1); $p2 = trim((string)$p2); $letter = trim((string)$letter); $p4 = trim((string)$p4);
    if ($p1 === '' && $p2 === '' && $letter === '' && $p4 === '') return null;
    return "{$p1}ایران - {$p2} {$letter} {$p4}";
}

// ریشه‌ی بایگانی شرکتی، کاملاً جدا از شخصِ «بایگانی صادره»ی پرسنلی (بعد از صدور
// یک کپی هم به بایگانی صادره‌ی مشترک می‌رود - نگاه کنید به company_copy_to_shared_sadere)
function company_archive_root($siteRoot) {
    return archive_root($siteRoot) . '/بایگانی شرکتی';
}

// پوشه‌ی موقتِ مدارکِ تخصیص‌نیافته‌ی یک شرکت (پیش از تگ‌گذاری دستی)
function company_unassigned_temp_path($siteRoot, $companyName) {
    return temp_archive_root($siteRoot) . '/شرکت‌ها/' . sanitize_folder_name($companyName) . '/نامرتب';
}

// طبق ساختار خواسته‌شده:
// بایگانی شرکتی/{سال ثبت نامه}/{نام شرکت}/{تاریخ ثبت نامه به‌صورت ۱۴۰۵.۰۲.۱۵}/{بدنه|ثالث}/
function company_request_base_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum) {
    [$jy, ] = jalali_from_gregorian_ts($requestCreatedTimestamp);
    $letterDate = jalali_from_gregorian_ts_dotted($requestCreatedTimestamp);
    return company_archive_root($siteRoot) . '/' . $jy . '/' . sanitize_folder_name($companyName) . '/' . $letterDate . '/' . insurance_type_fa($insuranceTypeEnum);
}

// نام پوشه‌ی هر پلاک، پیش از صدور: «{روزِ ماهِ تاریخ انقضا}(بدنه|ثالث) پلاک»
// مثال خواسته‌شده: «۳۰(بدنه) ۲۱ایران - ۵۱۵ ع ۴۴»
function company_plate_folder_name($expiryDate, $insuranceTypeEnum, $plateDisplay) {
    $dayPart = '';
    if ($expiryDate) {
        $ts = is_numeric($expiryDate) ? $expiryDate : strtotime($expiryDate);
        if ($ts) { [, , $jd] = jalali_from_gregorian_ts($ts); $dayPart = intval($jd); }
    }
    $prefix = $dayPart !== '' ? "{$dayPart}(" . insurance_type_fa($insuranceTypeEnum) . ')' : '(' . insurance_type_fa($insuranceTypeEnum) . ')';
    return sanitize_folder_name($prefix . ' ' . ($plateDisplay ?: 'بدون‌پلاک'));
}

// مسیر کامل پوشه‌ی یک پلاک پیش از صدور (بدون نیاز به دانستن insurance_type دوباره،
// چون از خودِ رکورد پلاک گرفته می‌شود - نگاه کنید به فراخوانی‌ها در company_actions.php)
function build_company_plate_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum, $expiryDate, $plateDisplay) {
    return company_request_base_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum)
        . '/' . company_plate_folder_name($expiryDate, $insuranceTypeEnum, $plateDisplay);
}

// پوشه‌ی نهایی پس از صدور: «پلاک - نام شرکت - شماره بیمه‌نامه - VIN»،
// دقیقاً هم‌الگو با build_case_folder_name_issued پرسنلی ولی با نام شرکت به‌جای بیمه‌گذار
function build_company_issued_folder_name($plateDisplay, $companyName, $policyNum, $vin) {
    return sanitize_folder_name(name_join([plate_for_filename($plateDisplay) ?: 'بدون‌پلاک', $companyName, policy_number_for_filename($policyNum), $vin]));
}

// پس از صدور، علاوه بر تغییرِ نامِ پوشه‌ی خودِ بایگانی شرکتی، یک *کپی* (نه انتقال)
// هم داخل بایگانی صادره‌ی مشترک (همان‌جایی که بیمه‌نامه‌های پرسنلی هم هستند) قرار
// می‌گیرد تا یک‌جا هم بشود همه‌ی صادره‌های شرکتی و پرسنلی را با هم دید.
function company_copy_to_shared_sadere($siteRoot, $issuedFolderAbsPath, $issuedTimestamp, $insuranceTypeEnum, $issuedFolderName) {
    $dest = build_sadere_base_path($siteRoot, $issuedTimestamp, $insuranceTypeEnum) . '/' . $issuedFolderName;
    if (is_dir($issuedFolderAbsPath)) {
        copy_dir_recursive($issuedFolderAbsPath, $dest);
        return is_dir($dest);
    }
    return false;
}

// =====================================================================
//  چک‌لیستِ مدارک لازم برای هر پلاک، بر اساس نوع بیمه
// =====================================================================
function company_required_docs($insuranceTypeEnum, $skipHealthInspection = false) {
    $docs = ['car_card_or_title' => 'کارت ماشین (پشت و رو) یا سند مالکیت'];
    if ($insuranceTypeEnum === 'BODY') {
        $docs['prev_body_policy'] = 'بیمه بدنه قبلی (در صورت وجود)';
        if (!$skipHealthInspection) {
            $docs['health_inspection'] = 'گزارش بازدید سلامت خودرو';
        }
    }
    return $docs;
}

// وضعیت کامل‌بودن مدارک یک پلاک: کدام مدارک لازم هنوز تخصیص‌نیافته‌اند
function company_plate_missing_docs($insuranceTypeEnum, $skipHealthInspection, array $assignedDocTypes) {
    $required = company_required_docs($insuranceTypeEnum, $skipHealthInspection);
    $missing = [];
    foreach ($required as $key => $label) {
        // «بیمه بدنه قبلی» اختیاری است؛ فقط بقیه را در فهرست «کم دارد» می‌آوریم
        if ($key === 'prev_body_policy') continue;
        if (!in_array($key, $assignedDocTypes, true)) $missing[$key] = $label;
    }
    return $missing;
}

// =====================================================================
//  کنترل دسترسی
// =====================================================================

// سشن پنل ثبت‌کننده‌ی شرکت (وب) - کاملاً جدا از سشن پنل ادمین (نام کوکی متفاوت)
function company_portal_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('bime_company_portal');
        session_start();
    }
}

// $pdo باید از قبل به دیتابیس وصل باشد؛ فهرست شرکت‌های مجاز از جدول واسط خوانده
// می‌شود (نه از ستون تکی قدیمی) چون یک کاربر می‌تواند به چند شرکت دسترسی داشته باشد.
function require_company_portal_session($pdo) {
    company_portal_session_start();
    if (empty($_SESSION['company_user_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز. لطفاً دوباره وارد شوید.']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT c.id, c.name FROM company_portal_user_companies cpuc
                            JOIN companies c ON c.id = cpuc.company_id
                            WHERE cpuc.portal_user_id = ? ORDER BY c.name");
    $stmt->execute([$_SESSION['company_user_id']]);
    $companies = $stmt->fetchAll();
    return [
        'company_user_id' => $_SESSION['company_user_id'],
        'companies' => $companies,
        'company_ids' => array_column($companies, 'id'),
        'full_name' => $_SESSION['company_user_full_name'] ?? '',
    ];
}

// برای اکشن‌های پنل ادمین که مخصوص ماژول شرکت‌هاست: هم ADMIN و هم COMPANY_LIAISON مجازند
function require_admin_or_liaison() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'COMPANY_LIAISON'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
        exit;
    }
    return ['user_id' => $_SESSION['user_id'], 'role' => $_SESSION['role']];
}

// اکشن‌های حساس (مثل ثبت صدور نهایی) فقط برای ADMIN
function require_admin_only() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'این عملیات فقط برای مدیر کل مجاز است.']);
        exit;
    }
    return ['user_id' => $_SESSION['user_id']];
}

// اعتبارسنجی/ذخیره‌ی فایل آپلودی برای ماژول شرکت‌ها (PDF یا عکس، یا زیپ اگر
// $allowZip=true - برای گزارش‌های بازدید سلامت که معمولاً زیپ چند فایلی هستند؛
// عمداً استخراج نمی‌شود، خودِ زیپ همان‌طور داخل پوشه‌ی بازدید سلامت نگه داشته می‌شود)
// اگر $extractZip=true و فایل زیپ بود، به‌جای ذخیره‌ی خودِ زیپ، محتوایش مستقیم
// داخل $destDir استخراج می‌شود (برای گزارش‌های بازدید سلامت که معمولاً چند فایلی‌اند)
// و path برگشتی خودِ $destDir خواهد بود (is_dir=true)؛ در غیر این صورت مثل قبل یک فایل تکی.
function company_store_uploaded_file($fileArr, $destDir, $maxBytes = 15728640, $allowZip = false, $extractZip = false) {
    if (empty($fileArr) || !is_uploaded_file($fileArr['tmp_name'] ?? '')) {
        return ['ok' => false, 'error' => 'فایلی دریافت نشد.'];
    }
    if (($fileArr['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'حجم فایل بیشتر از حد مجاز (۱۵ مگابایت) است.'];
    }
    $ext = strtolower(pathinfo($fileArr['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if ($allowZip) $allowedExts[] = 'zip';
    if (!in_array($ext, $allowedExts, true)) {
        return ['ok' => false, 'error' => $allowZip ? 'فقط فایل PDF، عکس یا زیپ مجاز است.' : 'فقط فایل PDF یا عکس مجاز است.'];
    }
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return ['ok' => false, 'error' => 'خطا در آماده‌سازی پوشه‌ی ذخیره‌سازی.'];
    }

    if ($extractZip && $ext === 'zip') {
        $tmpZipPath = sys_get_temp_dir() . '/' . uniqid('company_zip_') . '.zip';
        if (!move_uploaded_file($fileArr['tmp_name'], $tmpZipPath)) {
            return ['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل روی سرور.'];
        }
        $extracted = company_extract_zip_to_dir($tmpZipPath, $destDir);
        @unlink($tmpZipPath);
        if ($extracted === false) {
            return ['ok' => false, 'error' => 'فایل زیپ خراب است یا قابل استخراج نیست.'];
        }
        return ['ok' => true, 'path' => $destDir, 'orig_name' => $fileArr['name'], 'is_dir' => true, 'extracted_count' => $extracted];
    }

    $destPath = unique_dest_path($destDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext);
    if (!move_uploaded_file($fileArr['tmp_name'], $destPath)) {
        return ['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل روی سرور.'];
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && function_exists('compress_image_if_needed')) {
        compress_image_if_needed($destPath);
    }
    return ['ok' => true, 'path' => $destPath, 'orig_name' => $fileArr['name'], 'is_dir' => false];
}

// استخراج امنِ یک زیپ داخل یک پوشه‌ی مقصد: هر ورودی که بخواهد از مقصد بیرون بزند
// (مثلاً با «..» یا مسیر مطلق) نادیده گرفته می‌شود تا zip-slip ممکن نباشد.
// خروجی: تعداد فایل‌های استخراج‌شده، یا false در صورت خرابی زیپ.
function company_extract_zip_to_dir($zipPath, $destDir) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return false;
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $destReal = realpath($destDir) ?: $destDir;
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if ($entryName === false) continue;
        // نام‌های حاوی «..» یا مسیر مطلق را رد کن (zip-slip)
        if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || preg_match('/^[a-zA-Z]:/', $entryName)) continue;
        $targetPath = $destReal . '/' . ltrim($entryName, '/');
        if (substr($entryName, -1) === '/') { @mkdir($targetPath, 0755, true); continue; }
        if (!is_dir(dirname($targetPath))) @mkdir(dirname($targetPath), 0755, true);
        $stream = $zip->getStream($entryName);
        if ($stream === false) continue;
        $out = fopen(unique_dest_path($targetPath), 'wb');
        if ($out) { stream_copy_to_stream($stream, $out); fclose($out); $count++; }
        fclose($stream);
    }
    $zip->close();
    return $count;
}

// زیپ‌کردنِ محتوای یک پوشه (بازگشتی) برای دانلود آنی - نتیجه فایل موقتی است که
// پس از ارسال باید حذف شود (مثل build_zip_from_files از _case_helpers.php)
function company_zip_folder($folderPath) {
    if (!is_dir($folderPath)) return null;
    $zipPath = sys_get_temp_dir() . '/' . uniqid('folderzip_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) return null;
    $base = realpath($folderPath);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $localName = ltrim(str_replace($base, '', $file->getPathname()), '/');
        if ($file->isDir()) $zip->addEmptyDir($localName);
        else $zip->addFile($file->getPathname(), $localName);
    }
    $zip->close();
    return $zipPath;
}

// =====================================================================
//  اقساط شرکتی (کاملاً جدا از موتور مالی پرسنلی؛ فقط از fin_split_installments
//  که یک تابع محضِ ریاضی است و به هیچ جدولی وابسته نیست، استفاده مجدد می‌شود)
// =====================================================================
function company_add_months_jalali($jy, $jm, $step) {
    $total = ($jy * 12) + ($jm - 1) + $step;
    return [intdiv($total, 12), ($total % 12) + 1];
}

function company_jalali_month_len($jy, $jm) {
    if ($jm <= 6) return 31;
    if ($jm <= 11) return 30;
    $r = (($jy - ($jy > 0 ? 474 : 473)) % 2820) + 474;
    return ((($r + 38) * 682) % 2816 < 682) ? 30 : 29;
}

// اولین سررسید = تاریخ صدور + first_due_offset_months ماه + first_due_offset_days روز؛
// اقساط بعدی هرکدام دقیقاً یک ماه بعد از قبلی (با تنظیم روز برای ماه‌های کوتاه‌تر).
// نیازمند fin_split_installments از api/finance_core.php (باید include شده باشد).
function company_generate_installments($pdo, $plateId) {
    $stmt = $pdo->prepare("SELECT crp.*, cr.company_id FROM company_request_plates crp
                            JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch();
    if (!$plate || $plate['status'] !== 'ISSUED' || empty($plate['total_premium'])) {
        return ['ok' => false, 'error' => 'پلاک صادر نشده یا حق بیمه ثبت نشده است.'];
    }

    $stmt = $pdo->prepare("SELECT installment_count, first_due_offset_months, first_due_offset_days FROM companies WHERE id = ?");
    $stmt->execute([$plate['company_id']]);
    $comp = $stmt->fetch();
    $count = intval($comp['installment_count'] ?? 0) ?: 1;

    $issuedTs = $plate['issued_at'] ? strtotime($plate['issued_at']) : time();
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($issuedTs);
    [$fy, $fm] = company_add_months_jalali($jy, $jm, intval($comp['first_due_offset_months'] ?? 0));
    $fd = min($jd, company_jalali_month_len($fy, $fm));
    $firstTs = jalali_to_gregorian_ts($fy, $fm, $fd) + (intval($comp['first_due_offset_days'] ?? 0) * 86400);
    [$fy, $fm, $fd] = jalali_from_gregorian_ts($firstTs);

    $parts = fin_split_installments($plate['total_premium'], $count, 'easy');

    $pdo->prepare("DELETE FROM company_installments WHERE plate_id = ?")->execute([$plateId]);
    $ins = $pdo->prepare("INSERT INTO company_installments (plate_id, inst_number, amount, due_jalali, due_date) VALUES (?, ?, ?, ?, ?)");
    $cy = $fy; $cm = $fm;
    foreach ($parts as $i => $amount) {
        if ($i > 0) [$cy, $cm] = company_add_months_jalali($cy, $cm, 1);
        $cd = min($fd, company_jalali_month_len($cy, $cm));
        $dueJ = sprintf('%04d/%02d/%02d', $cy, $cm, $cd);
        $dueG = date('Y-m-d', jalali_to_gregorian_ts($cy, $cm, $cd));
        $ins->execute([$plateId, $i + 1, $amount, $dueJ, $dueG]);
    }
    return ['ok' => true, 'count' => count($parts)];
}
