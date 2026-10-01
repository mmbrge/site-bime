-- =====================================================================
-- 024: رمزِ جداگانه برای ویرایشِ گزارش بازدید (برای هر کاربرِ پنل)
--   users.report_edit_password_hash : اگر مدیر کل در «کاربران» برای کسی رمزِ ویرایشِ گزارش گذاشته باشد،
--   برای ویرایشِ گزارش همین رمز پرسیده می‌شود؛ اگر خالی باشد، همان رمزِ ورودِ پنلِ خودِ کاربر.
--   کلِ فایل را یک‌جا اجرا کنید؛ اجرای دوباره‌اش بی‌خطر است.
-- =====================================================================
SET NAMES utf8mb4;

SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'report_edit_password_hash') = 0, 'ALTER TABLE `users` ADD COLUMN `report_edit_password_hash` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
