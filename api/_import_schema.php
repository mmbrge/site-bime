<?php
// فایل: api/_import_schema.php
// ستون‌ها و جدولِ «بایگانی وارداتی» (خودکار؛ نیازی به اجرای SQL نیست). جدا از _company_helpers.php تا
// هر فایلی (حتی بدونِ توابعِ شرکتی) بتواند با require_once فیلترِ is_import را امن به کار ببرد.
if (!function_exists('imp_ensure')) {
    function imp_ensure($pdo) {
        static $done = false;
        if ($done || $pdo->inTransaction()) return;   // ALTER داخلِ تراکنش آن را بی‌صدا commit می‌کند
        $done = true;
        try {
            // آخرین ستون؛ اگر هست، همه ساخته شده‌اند
            if ($pdo->query("SHOW COLUMNS FROM company_request_plates LIKE 'import_json'")->fetch()) return;
            $add = function ($t, $c, $def) use ($pdo) {
                if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            };
            $add('companies', 'is_import', 'TINYINT(1) NOT NULL DEFAULT 0');
            $add('company_requests', 'is_import', 'TINYINT(1) NOT NULL DEFAULT 0');
            $add('company_requests', 'import_batch_id', 'INT NULL');
            $add('company_requests', 'import_month', 'CHAR(7) NULL');
            $add('company_request_plates', 'import_policy_number', 'VARCHAR(60) NULL');
            $add('company_request_plates', 'import_issue_date', 'VARCHAR(10) NULL');
            $pdo->exec("CREATE TABLE IF NOT EXISTS import_batches (
                id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(255) NULL, rows_total INT NOT NULL DEFAULT 0, rows_created INT NOT NULL DEFAULT 0,
                rows_skipped INT NOT NULL DEFAULT 0, group_by VARCHAR(20) NULL, created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try { $pdo->exec("ALTER TABLE company_requests ADD INDEX idx_cr_import (is_import)"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE company_request_plates ADD INDEX idx_crp_imp_pol (import_policy_number)"); } catch (Throwable $e) {}
            $add('company_request_plates', 'import_json', 'MEDIUMTEXT NULL');
        } catch (Throwable $e) { error_log('[imp_ensure] ' . $e->getMessage()); }
    }
}
