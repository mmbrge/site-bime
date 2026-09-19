-- =====================================================================
-- رفع اشکال migration 004: چون ستون settled_to_pasargad قبلاً در migration 002
-- به company_installments اضافه شده بود، خط اول migration 004 (که دوباره همین
-- ستون را اضافه می‌کرد) با خطای «Duplicate column name 'settled_to_pasargad'»
-- روبرو می‌شد و phpMyAdmin روی همان خط متوقف می‌ماند - یعنی دو جدول زیر
-- (company_payments و company_payment_allocations) هرگز ساخته نشدند و همین
-- باعث «خطای سرور» در بخش مالی شرکت‌ها (لیست اقساط/ثبت پرداختی) می‌شد.
-- این migration را اجرا کنید؛ چه migration 004 را قبلاً (ناقص) اجرا کرده باشید
-- چه اصلاً اجرا نکرده باشید، بی‌خطر است (IF NOT EXISTS).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `company_payments` (
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

CREATE TABLE IF NOT EXISTS `company_payment_allocations` (
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

-- اگر ستون settled_to_pasargad به هر دلیلی (مثلاً چون migration 002 را کامل
-- اجرا نکرده بودید) هنوز روی company_installments نیست، این خط را جداگانه و
-- تنها در صورت نیاز اجرا کنید (اگر ستون از قبل هست، این خط را رد کنید):
-- ALTER TABLE `company_installments` ADD COLUMN `settled_to_pasargad` TINYINT(1) NOT NULL DEFAULT 0 AFTER `due_date`;
