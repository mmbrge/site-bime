-- =====================================================================
-- 022: عکس پروفایل و «آخرین بازدید / آنلاین» برای همه‌ی کاربران
--   users (کاربرانِ پنل)، persons (کارکنانِ وب‌اپ)، company_portal_users (کاربرانِ شرکت‌ها)
--     avatar          : «preset:N» (یکی از عکس‌های آماده) یا مسیرِ عکسِ بارگذاری‌شده در uploads/avatars/
--     last_seen_at    : آخرین لحظه‌ای که کاربر در سایت بوده (هر چند ثانیه به‌روز می‌شود)
--     last_offline_at : لحظه‌ی بستنِ صفحه / خروج
--   کلِ فایل را یک‌جا اجرا کنید؛ اجرای دوباره‌اش بی‌خطر است.
-- =====================================================================
SET NAMES utf8mb4;

-- users
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar') = 0, 'ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_seen_at') = 0, 'ALTER TABLE `users` ADD COLUMN `last_seen_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_offline_at') = 0, 'ALTER TABLE `users` ADD COLUMN `last_offline_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- persons
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'avatar') = 0, 'ALTER TABLE `persons` ADD COLUMN `avatar` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'last_seen_at') = 0, 'ALTER TABLE `persons` ADD COLUMN `last_seen_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'persons' AND COLUMN_NAME = 'last_offline_at') = 0, 'ALTER TABLE `persons` ADD COLUMN `last_offline_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- company_portal_users
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_portal_users' AND COLUMN_NAME = 'avatar') = 0, 'ALTER TABLE `company_portal_users` ADD COLUMN `avatar` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_portal_users' AND COLUMN_NAME = 'last_seen_at') = 0, 'ALTER TABLE `company_portal_users` ADD COLUMN `last_seen_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_portal_users' AND COLUMN_NAME = 'last_offline_at') = 0, 'ALTER TABLE `company_portal_users` ADD COLUMN `last_offline_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
