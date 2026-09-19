-- =====================================================================
-- مالی شرکتی: همان منطق دو-مرحله‌ای مالی پرسنلی (اول دریافت از شرکت،
-- بعد پرداخت به پاسارگاد) ولی روی company_installments، و بایگانی فیش‌ها
-- داخل پوشه‌ی خودِ همان درخواست (نه یک آرشیو مالی سراسری جدا).
-- فقط جدول/ستون اضافه می‌کند.
-- =====================================================================

-- توجه: ستون settled_to_pasargad از قبل در migrations/002_company_module_updates.sql
-- به company_installments اضافه شده؛ اینجا دوباره اضافه نمی‌شود (باعث خطای
-- «Duplicate column name» و توقف این فایل در phpMyAdmin پیش از ساخته‌شدنِ دو
-- جدول زیر می‌شد - برای رفعش روی دیتابیس‌هایی که این را قبلاً اجرا کرده‌اند
-- به migrations/006_fix_missing_company_finance_tables.sql نگاه کنید).

CREATE TABLE `company_payments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT NOT NULL,
  `target` ENUM('US','PASARGAD') NOT NULL,
  `amount` BIGINT NOT NULL,
  `method` ENUM('TRANSFER','CHEQUE','CASH','PAYROLL') NOT NULL DEFAULT 'TRANSFER',
  `paid_jalali` VARCHAR(12) DEFAULT NULL,
  `paid_at` DATE DEFAULT NULL,
  `reference_no` VARCHAR(80) DEFAULT NULL,
  `receipts` TEXT DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `cp_request_fk` FOREIGN KEY (`request_id`) REFERENCES `company_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE `company_payment_allocations` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT NOT NULL,
  `installment_id` BIGINT NOT NULL,
  `amount` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_id` (`payment_id`),
  KEY `installment_id` (`installment_id`),
  CONSTRAINT `cpa_payment_fk` FOREIGN KEY (`payment_id`) REFERENCES `company_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cpa_inst_fk` FOREIGN KEY (`installment_id`) REFERENCES `company_installments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
