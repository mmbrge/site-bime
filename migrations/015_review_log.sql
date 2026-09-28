-- فایل: migrations/015_review_log.sql
-- تاریخچه‌ی بررسیِ مدارک و بازدیدها: هر تایید، رد، ارسالِ دوباره و تاییدِ نهایی یک ردیف.
-- صفحه‌ی «بازدیدهای تاییدشده» از این جدول نشان می‌دهد چه چیزی کی و توسط چه کسی تایید/رد شد
-- و اگر یک‌بار رد و بعد تایید شده، علتِ ردِ قبلی چه بوده. (اگر اجرا نشود، سیستم کار می‌کند
-- ولی تاریخچه‌ی ردهای قبلی ثبت نمی‌شود.)
CREATE TABLE IF NOT EXISTS `review_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity` VARCHAR(20) NOT NULL,            -- CASE_DOC | HEALTH_PHOTO | HEALTH | HEALTH_REPORT
  `case_id` INT NULL,
  `inspection_id` INT NULL,
  `item_key` VARCHAR(80) NULL,              -- کلیدِ مدرک یا مرحله‌ی عکس
  `item_label` VARCHAR(255) NULL,
  `decision` VARCHAR(20) NOT NULL,          -- APPROVED | REJECTED | RESUBMITTED | UPLOADED | FINAL_APPROVED
  `note` TEXT NULL,
  `user_id` INT NULL,                       -- کارشناس (برای ارسالِ دوباره‌ی کاربر خالی است)
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_case` (`case_id`),
  KEY `idx_review_insp` (`inspection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- وضعیتِ تازه‌ی بازدید سلامت: PHOTOS_APPROVED = همه‌ی عکس‌ها تایید شده و منتظرِ فایلِ گزارشِ کارشناس
-- (بعد از بارگذاریِ گزارش، تاییدِ نهایی = APPROVED). اگر ستون ENUM باشد به VARCHAR تبدیل می‌شود.
ALTER TABLE `health_inspections`
  MODIFY COLUMN `status` VARCHAR(30) NULL DEFAULT 'PENDING';
