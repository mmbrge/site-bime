-- =====================================================================
-- خودروهای بدونِ پلاک (لیفتراک و خودروی صفرکیلومتر) + ارزش خودرو و تعهد مالی
-- فقط ستون اضافه می‌کند - هیچ داده‌ای حذف یا بازنویسی نمی‌شود.
-- پیش‌نیاز: migrations/008_company_rows_checklist.sql
-- اجرا: phpMyAdmin روی besiteir_dbm (قبلش از دیتابیس بک‌آپ بگیرید).
-- =====================================================================

-- بعضی درخواست‌ها اصلاً پلاک ندارند: لیفتراک‌ها فقط شماره شاسی دارند، و خودروهای
-- صفرکیلومتر هم هنوز پلاک نگرفته‌اند (شماره شاسی و موتور دارند و تاریخ انقضا هم
-- ندارند). این ردیف‌ها با شماره شاسی شناسایی می‌شوند و همه‌جا - جدول‌ها، نام
-- پوشه و نام فایل - شماره شاسی به‌جای پلاک نوشته می‌شود.
-- توجه: ستون `vin` از قبل هست و مخصوصِ VINِ بیمه‌نامه‌ی صادرشده است؛ این دو ستون
-- شناسه‌ی خودِ درخواست‌اند (پیش از صدور) و عمداً با آن قاطی نشده‌اند.
ALTER TABLE `company_request_plates`
  ADD COLUMN `chassis_no` VARCHAR(60) DEFAULT NULL AFTER `plate_p4`,
  ADD COLUMN `engine_no` VARCHAR(60) DEFAULT NULL AFTER `chassis_no`,
  ADD COLUMN `is_new_vehicle` TINYINT(1) NOT NULL DEFAULT 0 AFTER `engine_no`;

-- ارزش خودرو برای بیمه بدنه، و سقف تعهد مالی برای بیمه ثالث (هر دو به ریال).
-- خودِ ثبت‌کننده‌ی شرکت هم موقع ثبت درخواست می‌تواند این‌ها را مشخص کند.
ALTER TABLE `company_request_plates`
  ADD COLUMN `car_value` BIGINT DEFAULT NULL AFTER `is_new_vehicle`,
  ADD COLUMN `liability_limit` BIGINT DEFAULT NULL AFTER `car_value`;

-- جستجوی سریع‌تر روی شماره شاسی
ALTER TABLE `company_request_plates`
  ADD INDEX `idx_chassis` (`chassis_no`);
