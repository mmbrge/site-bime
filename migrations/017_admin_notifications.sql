-- =====================================================================
-- اعلان‌های مدیر کل در ربات بله‌ی شرکت‌ها
--   قبلاً فعالیت‌های پرسنل (درخواست جدید، بازدید سلامت، تیکت و پیام پشتیبانی، ...) با ربات
--   پرسنل به یک «آیدی عددی» در تنظیمات فرستاده می‌شد. حالا برای هر کاربرِ «مدیر کل» در این
--   جدول ذخیره می‌شود و مدیر در ربات شرکت‌ها با دکمه‌ی «🔔 اعلان‌ها» همه را یک‌جا می‌بیند؛
--   بعد از دیدن پاک می‌شوند.
-- تنظیمِ قدیمیِ admin_bale_chat_id هم پاک می‌شود (دیگر استفاده‌ای ندارد).
-- اجرا: phpMyAdmin روی besiteir_dbm (قبلش از دیتابیس بک‌آپ بگیرید). بعد از ۰۱۶ اجرا شود.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `admin_notifications` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `text` VARCHAR(600) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_an_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM `system_settings` WHERE `setting_key` = 'admin_bale_chat_id';
