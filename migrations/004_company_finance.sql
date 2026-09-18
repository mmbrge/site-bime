-- =====================================================================
-- مالی شرکتی: همان منطق دو-مرحله‌ای مالی پرسنلی (اول دریافت از شرکت،
-- بعد پرداخت به پاسارگاد) ولی روی company_installments، و بایگانی فیش‌ها
-- داخل پوشه‌ی خودِ همان درخواست (نه یک آرشیو مالی سراسری جدا).
-- فقط جدول/ستون اضافه می‌کند.
-- =====================================================================

ALTER TABLE `company_installments`
  ADD COLUMN `settled_to_pasargad` TINYINT(1) NOT NULL DEFAULT 0 AFTER `due_date`;

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
