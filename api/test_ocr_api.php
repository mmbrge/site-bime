<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز']);
    exit;
}

// «همکار شرکت‌ها» (COMPANY_LIAISON) فقط به شرکت‌ها، بایگانی و مالی دسترسی دارد؛
// منوی این بخش برایش پنهان است و اینجا هم مستقیم جلویش گرفته می‌شود.
if (($_SESSION['role'] ?? '') === 'COMPANY_LIAISON') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// جلوگیری از قفل شدن سایت
session_write_close();

// بررسی خطاهای آپلود سرور
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $max_upload = ini_get('upload_max_filesize');
    $max_post = ini_get('post_max_size');
    $error_msg = 'فایلی ارسال نشده است.';
    
    if (isset($_FILES['file']['error'])) {
        $err_code = $_FILES['file']['error'];
        if ($err_code == UPLOAD_ERR_INI_SIZE || $err_code == UPLOAD_ERR_FORM_SIZE) {
            $error_msg = "حجم فایل از حد مجاز سرور بیشتر است (محدودیت فعلی: $max_upload).";
        } else {
            $error_msg = "آپلود با خطا مواجه شد (کد خطا: $err_code).";
        }
    } else if (empty($_FILES) && empty($_POST) && isset($_SERVER['REQUEST_METHOD']) && strtolower($_SERVER['REQUEST_METHOD']) == 'post') {
        $error_msg = "حجم کل درخواست از محدودیت POST سرور ($max_post) بیشتر است یا ریدایرکت رخ داده.";
    }
    
    echo json_encode(['ok' => false, 'error' => $error_msg]);
    exit;
}

$uploadDir = dirname(__DIR__) . '/queue/pending/';
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

$ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
$fileName = 'test_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
$targetPath = $uploadDir . $fileName;

if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
    $python_env = "/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3";
    $script_path = "/home/besiteir/ocr-paddle/test_pipeline.py";
    
    $command = "export PYTHONIOENCODING=utf8; " . escapeshellarg($python_env) . " " . escapeshellarg($script_path) . " " . escapeshellarg($targetPath) . " 2>&1";
    
    $output = shell_exec($command);
    @unlink($targetPath); 
    
    if ($output !== null && preg_match('/\{.*\}/s', $output, $matches)) {
        echo $matches[0];
        exit;
    }
    
    echo json_encode(['ok' => false, 'error' => 'خطا در اسکریپت پایتون.', 'raw' => $output]);
} else {
    echo json_encode(['ok' => false, 'error' => 'خطا در ذخیره فایل روی هاست. محدودیت دسترسی (Permission) را بررسی کنید.']);
}
