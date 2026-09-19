-- =====================================================================
-- ریزِ ردیف‌های درخواست شرکتی: مرحله‌بندیِ هر ردیف، وضعیت «بیمه بدنه قبل»،
-- و چند فیلد توصیفی که از نامه/هوش مصنوعی استخراج می‌شود.
-- فقط ستون اضافه/گسترش می‌دهد - هیچ داده‌ای حذف یا بازنویسی نمی‌شود.
-- اجرا: phpMyAdmin روی besiteir_dbm (قبلش از دیتابیس بک‌آپ بگیرید).
-- =====================================================================

-- مرحله‌ی هر ردیف. دو مرحله‌ی «در انتظار مدارک» (PENDING) و «آماده‌ی صدور»
-- (READY_FOR_ISSUE) خودکار و بر اساس کامل‌بودن چک‌لیستِ مدارک تعیین می‌شوند؛
-- WITH_BOSS (دادن به رئیس) و IN_ISSUANCE (صدورات دارد پروسس می‌کند) دستی‌اند.
ALTER TABLE `company_request_plates`
  MODIFY COLUMN `status` ENUM('PENDING','READY_FOR_ISSUE','WITH_BOSS','IN_ISSUANCE','ISSUED','CANCELLED') NOT NULL DEFAULT 'PENDING';

-- «بیمه بدنه قبل» برای بیمه بدنه *در صورت وجود* اجباری است؛ با این ستون مشخص
-- می‌شود که این خودرو بیمه بدنه قبلی دارد یا نه (NULL = هنوز مشخص نشده).
ALTER TABLE `company_request_plates`
  ADD COLUMN `has_prev_body` ENUM('YES','NO') DEFAULT NULL AFTER `skip_health_inspection`,
  ADD COLUMN `car_name` VARCHAR(100) DEFAULT NULL AFTER `has_prev_body`,
  ADD COLUMN `row_note` VARCHAR(255) DEFAULT NULL AFTER `car_name`;

-- متنِ استخراج‌شده‌ی نامه (خروجی هوش مصنوعی) که ردیف‌ها از آن ساخته شده‌اند،
-- برای اینکه بعداً بشود مرجع را دید و با نامه مقایسه کرد.
ALTER TABLE `company_requests`
  ADD COLUMN `letter_parsed_json` MEDIUMTEXT DEFAULT NULL AFTER `letter_file_path`;

-- اعتبارسنجیِ فایل بیمه‌نامه‌ی صادرشده (همان روال دو‌مرحله‌ای پرسنلی): فایل ابتدا
-- موقت ذخیره و OCR می‌شود، نتیجه‌ی استخراج نگه داشته می‌شود، و فقط بعد از تطبیق
-- پلاک و نوع بیمه، صدور نهایی ثبت می‌گردد.
ALTER TABLE `company_request_plates`
  ADD COLUMN `pending_policy_temp_path` VARCHAR(500) DEFAULT NULL AFTER `issued_file_path`,
  ADD COLUMN `pending_policy_orig_name` VARCHAR(255) DEFAULT NULL AFTER `pending_policy_temp_path`,
  ADD COLUMN `ocr_extracted_data` TEXT DEFAULT NULL AFTER `pending_policy_orig_name`;
