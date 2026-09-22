-- =====================================================================
-- نوعِ درخواست: صدور بیمه‌نامه‌ی جدید، صدور الحاقیه، یا فسخ بیمه‌نامه
-- فقط ستون اضافه می‌کند - هیچ داده‌ای حذف یا بازنویسی نمی‌شود و درخواست‌های
-- موجود همگی «صدور بیمه‌نامه‌ی جدید» (مقدار پیش‌فرض) می‌مانند.
-- پیش‌نیاز: migrations/009_vin_rows_and_values.sql
-- اجرا: phpMyAdmin روی besiteir_dbm (قبلش از دیتابیس بک‌آپ بگیرید).
-- =====================================================================

-- هر نامه/درخواست یک نوع دارد. یک نامه‌ی «الحاقیه» ممکن است چند خودرو را پوشش
-- بدهد، ولی همه‌ی ردیف‌هایش از همان نوع‌اند - برای همین نوع روی خودِ درخواست است.
ALTER TABLE `company_requests`
  ADD COLUMN `request_kind` ENUM('NEW_POLICY','ENDORSEMENT','CANCELLATION') NOT NULL DEFAULT 'NEW_POLICY' AFTER `insurer`;

-- شماره‌ی بیمه‌نامه‌ی مرجع: بیمه‌نامه‌ای که این درخواست به آن اشاره دارد.
-- برای الحاقیه و فسخ عملاً لازم است و برای صدور جدید اختیاری.
-- توجه: با ستون `policy_number` قاطی نمی‌شود؛ آن شماره‌ی چیزی است که *ما* صادر
-- می‌کنیم (بیمه‌نامه یا الحاقیه)، این شماره‌ی چیزی است که از قبل وجود دارد.
ALTER TABLE `company_request_plates`
  ADD COLUMN `ref_policy_number` VARCHAR(60) DEFAULT NULL AFTER `liability_limit`,
  ADD COLUMN `endorsement_request` TEXT DEFAULT NULL AFTER `ref_policy_number`,
  ADD COLUMN `cancellation_reason` VARCHAR(120) DEFAULT NULL AFTER `endorsement_request`;

ALTER TABLE `company_request_plates`
  ADD INDEX `idx_ref_policy` (`ref_policy_number`);
