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

// ریشه‌ی بایگانی شرکت‌ها، کاملاً جدا از «بایگانی صادره»ی پرسنلی طبق خواسته‌ی کارفرما
function company_archive_root($siteRoot) {
    return archive_root($siteRoot) . '/شرکت‌ها';
}

// پوشه‌ی موقتِ مدارکِ تخصیص‌نیافته‌ی یک شرکت (پیش از تگ‌گذاری دستی)
function company_unassigned_temp_path($siteRoot, $companyName) {
    return temp_archive_root($siteRoot) . '/شرکت‌ها/' . sanitize_folder_name($companyName) . '/نامرتب';
}

// پوشه‌ی نهایی یک درخواست/پلاک، پیش از صدور: .../شرکت‌ها/{نام شرکت}/{سال}/{ماه}/درخواست {id}/{پلاک}
function build_company_request_folder($siteRoot, $createdTimestamp, $companyName, $requestId, $plateDisplay) {
    [$jy, $jm, ] = jalali_from_gregorian_ts($createdTimestamp);
    $monthName = jalali_month_name($jm);
    $reqFolder = sanitize_folder_name(name_join(['درخواست ' . intval($requestId)]));
    $plateFolder = sanitize_folder_name($plateDisplay ?: 'بدون‌پلاک');
    return company_archive_root($siteRoot) . '/' . sanitize_folder_name($companyName) . '/' . $jy . '/' . $monthName . '/' . $reqFolder . '/' . $plateFolder;
}

// پوشه‌ی نهایی پس از صدور: همان مسیر بالا + نام پوشه با شماره‌ی بیمه‌نامه/VIN،
// دقیقاً هم‌الگو با build_case_folder_name_issued پرسنلی
function build_company_issued_folder_name($plateDisplay, $policyNum, $vin) {
    return sanitize_folder_name(name_join([$plateDisplay ?: 'بدون‌پلاک', policy_number_for_filename($policyNum), $vin]));
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

function require_company_portal_session() {
    company_portal_session_start();
    if (empty($_SESSION['company_user_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز. لطفاً دوباره وارد شوید.']);
        exit;
    }
    return [
        'company_user_id' => $_SESSION['company_user_id'],
        'company_id' => $_SESSION['company_id'],
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

// اعتبارسنجی/ذخیره‌ی فایل آپلودی برای ماژول شرکت‌ها (PDF یا عکس)، با محدودیت حجم و پسوند
function company_store_uploaded_file($fileArr, $destDir, $maxBytes = 15728640) {
    if (empty($fileArr) || !is_uploaded_file($fileArr['tmp_name'] ?? '')) {
        return ['ok' => false, 'error' => 'فایلی دریافت نشد.'];
    }
    if (($fileArr['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'حجم فایل بیشتر از حد مجاز (۱۵ مگابایت) است.'];
    }
    $ext = strtolower(pathinfo($fileArr['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        return ['ok' => false, 'error' => 'فقط فایل PDF یا عکس مجاز است.'];
    }
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return ['ok' => false, 'error' => 'خطا در آماده‌سازی پوشه‌ی ذخیره‌سازی.'];
    }
    $destPath = unique_dest_path($destDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext);
    if (!move_uploaded_file($fileArr['tmp_name'], $destPath)) {
        return ['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل روی سرور.'];
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && function_exists('compress_image_if_needed')) {
        compress_image_if_needed($destPath);
    }
    return ['ok' => true, 'path' => $destPath, 'orig_name' => $fileArr['name']];
}
