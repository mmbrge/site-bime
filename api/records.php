<?php
// فایل: api/records.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT 
                ir.id, 
                ir.introduction_id,
                p.id AS person_id,
                p.full_name, 
                p.national_code, 
                p.personnel_code, 
                c.name AS company_name,
                ir.created_at,
                i.max_quota,
                i.used_quota
            FROM insurance_requests ir
            JOIN persons p ON ir.person_id = p.id
            LEFT JOIN companies c ON p.company_id = c.id
            LEFT JOIN introductions i ON ir.introduction_id = i.id
            ORDER BY ir.created_at DESC
        ");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($records as &$row) {
            $row['issued_summary'] = get_issued_counts_label($pdo, $row['introduction_id']);
            $rel = get_issued_counts_by_relationship($pdo, $row['introduction_id']);
            $row['relationship_summary'] = "خودش: {$rel['self']} فقره / بستگان: {$rel['relatives']} فقره";
            $row['quota_summary'] = ($row['used_quota'] ?? 0) . ' / ' . ($row['max_quota'] ?? 4);
            // به‌جای یک «وضعیت» واحد و گمراه‌کننده برای کل پرونده، فقط تعداد بیمه‌نامه‌های ثبت‌شده را می‌دهیم؛
            // وضعیت واقعی مال هر بیمه‌نامه به‌طور جداگانه است و در پاپ‌آپ جزئیات نمایش داده می‌شود
            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM policy_cases WHERE introduction_id = ?");
            $stmt2->execute([$row['introduction_id']]);
            $row['total_cases'] = intval($stmt2->fetchColumn());
        }
        
        echo json_encode(['ok' => true, 'data' => $records]);
    } catch (PDOException $e) {
        error_log('[records] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'خطا در دیتابیس.']);
    }
    exit;
}
?>