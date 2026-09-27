-- =====================================================================
-- اعلان‌های ماندگارِ هر کاربر (زنگوله‌ی کنارِ دکمه‌ی خروج)، تا اگر همان کاربر با
-- مرورگر یا دستگاهِ دیگری وارد شد، همان فهرست (با وضعیتِ خوانده/نخوانده) را ببیند.
--   scope = STAFF  -> کاربرانِ پنل مدیریت (users.id)
--   scope = PORTAL -> کاربرانِ پنل شرکت‌ها (company_portal_users.id)
-- notification_cursors آخرین زمانی را نگه می‌دارد که رویدادهای هر کاربر به اعلان تبدیل
-- شده‌اند، تا با چند مرورگرِ باز، هیچ اعلانی دوبار ساخته نشود.
-- فقط جدولِ تازه می‌سازد - به داده‌های موجود دست نمی‌زند.
-- اجرا: phpMyAdmin روی besiteir_dbm (قبلش از دیتابیس بک‌آپ بگیرید).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `scope` ENUM('STAFF','PORTAL') NOT NULL,
  `user_id` BIGINT NOT NULL,
  `type` VARCHAR(40) DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `tab` VARCHAR(60) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_un_user` (`scope`, `user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_cursors` (
  `scope` ENUM('STAFF','PORTAL') NOT NULL,
  `user_id` BIGINT NOT NULL,
  `last_at` DATETIME NOT NULL,
  PRIMARY KEY (`scope`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
