<?php
// فایل: api/import_letter.php
ob_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';

// تعریف مسیر روت بایگانی دقیقا در Public_html یا روت اصلی
$archiveRootDir = dirname(__DIR__) . '/بایگانی';
$docsRootDir = $archiveRootDir . '/مدارک و صدور';
$financeRootDir = $archiveRootDir . '/مالی';

if (!file_exists($docsRootDir)) mkdir($docsRootDir, 0777, true);
if (!file_exists($financeRootDir)) mkdir($financeRootDir, 0777, true);

function clean_path_string($str) {
    return trim(preg_replace('/[\\\\\\/*?:"<>|\r\n\t]/', '', (string)$str));
}

$national_code = trim($_POST['national_code'] ?? '');
$personnel_code = trim($_POST['personnel_code'] ?? '-');
$full_name = trim($_POST['full_name'] ?? 'نامشخص');
$company_name = trim($_POST['company_name'] ?? 'دنیای ماموت');

if (empty($national_code) && empty($_POST)) {
    $rawInput = file_get_contents('php://input');
    $json = json_decode($rawInput, true);
    if ($json) {
        $national_code = trim($json['national_code'] ?? '');
        $personnel_code = trim($json['personnel_code'] ?? '-');
        $full_name = trim($json['full_name'] ?? 'نامشخص');
    }
}

if (empty($national_code)) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => 'کد ملی ارسال نشده است.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
    $stmt->execute([$company_name]);
    $company_id = $stmt->fetchColumn();
    if (!$company_id) {
        $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
        $stmt->execute([$company_name]);
        $company_id = $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT id FROM persons WHERE national_code = ?");
    $stmt->execute([$national_code]);
    $person_id = $stmt->fetchColumn();
    if (!$person_id) {
        $stmt = $pdo->prepare("INSERT INTO persons (national_code, personnel_code, full_name, company_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$national_code, $personnel_code, $full_name, $company_id]);
        $person_id = $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("UPDATE persons SET personnel_code = ?, full_name = ?, company_id = ? WHERE id = ?");
        $stmt->execute([$personnel_code, $full_name, $company_id, $person_id]);
    }

    $cName = clean_path_string($full_name);
    $cPersonnel = clean_path_string($personnel_code);
    $cNational = clean_path_string($national_code);

    $personFolder = $docsRootDir . "/{$cPersonnel} - {$cNational} - {$cName}";
    $introFolder = $personFolder . '/01 - نامه کسر از حقوق';

    if (!file_exists($introFolder)) mkdir($introFolder, 0777, true);

    $existingFiles = glob($introFolder . '/*.pdf');
    $letterCount = count($existingFiles) + 1;
    $suffix = ($letterCount > 1) ? " {$letterCount}" : "";
    
    $finalFileName = "{$cPersonnel} - {$cNational} - {$cName} - نامه{$suffix}.pdf";
    $finalDiskPath = $introFolder . '/' . $finalFileName;
    $relativeDbPath = "بایگانی/مدارک و صدور/{$cPersonnel} - {$cNational} - {$cName}/01 - نامه کسر از حقوق/{$finalFileName}";

    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        move_uploaded_file($_FILES['pdf_file']['tmp_name'], $finalDiskPath);
    }

    $stmt = $pdo->prepare("INSERT INTO introductions (person_id, file_path) VALUES (?, ?)");
    $stmt->execute([$person_id, $relativeDbPath]);
    $intro_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO insurance_requests (person_id, introduction_id, insurance_type, status) VALUES (?, ?, 'در انتظار انتخاب', 'NEW')");
    $stmt->execute([$person_id, $intro_id]);
    $request_id = $pdo->lastInsertId();

    $pdo->commit();
    ob_end_clean();
    echo json_encode(['ok' => true, 'message' => 'معرفی‌نامه ثبت و آرشیو شد.', 'request_id' => $request_id]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => 'خطای دیتابیس: ' . $e->getMessage()]);
}
?>