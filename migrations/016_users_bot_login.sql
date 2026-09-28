-- =====================================================================
-- کاربران پنل، ورود با کد از طریق ربات بله‌ی شرکت‌ها، لاگ ورود و بازیابی رمز
--   users                : کد پرسنلی، اتصال به ربات (chat_id + زمان اتصال)، حذفِ نرم
--                          (کاربر حذف‌شده دیگر وارد نمی‌شود ولی نامش روی همه‌ی کارهایش می‌ماند)
--   company_portal_users : زمان اتصال به ربات + حذفِ نرم
--   login_logs           : هر ورود (موفق یا ناموفق) با تاریخ، ساعت، مرورگر و دستگاه
--   phone_otps           : کدهای ۵ رقمیِ ورود که با ربات فرستاده می‌شوند (هش‌شده)
--   password_reset_requests : درخواست‌های بازیابی رمز که از ربات می‌آید
--   company_bot_state    : وضعیتِ گفتگوی هر کاربر با ربات شرکت‌ها
-- فقط ستون/جدول اضافه می‌کند - به داده‌های موجود دست نمی‌زند.
-- اجرا: phpMyAdmin روی besiteir_dbm (قبلش از دیتابیس بک‌آپ بگیرید).
-- =====================================================================

ALTER TABLE `users`
  ADD COLUMN `personnel_code` VARCHAR(30) DEFAULT NULL,
  ADD COLUMN `bale_chat_id` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN `bot_linked_at` DATETIME DEFAULT NULL,
  ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `deleted_at` DATETIME DEFAULT NULL;

ALTER TABLE `company_portal_users`
  ADD COLUMN `bot_linked_at` DATETIME DEFAULT NULL,
  ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `deleted_at` DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_type` VARCHAR(10) NOT NULL,          -- STAFF | COMPANY
  `user_id` BIGINT DEFAULT NULL,
  `user_name` VARCHAR(100) DEFAULT NULL,
  `role` VARCHAR(30) DEFAULT NULL,
  `method` VARCHAR(10) NOT NULL,             -- PASSWORD | OTP
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `note` VARCHAR(200) DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `browser` VARCHAR(60) DEFAULT NULL,
  `os` VARCHAR(60) DEFAULT NULL,
  `device` VARCHAR(60) DEFAULT NULL,
  `user_agent` VARCHAR(400) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ll_user` (`user_type`, `user_id`),
  KEY `idx_ll_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `phone_otps` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_type` VARCHAR(10) NOT NULL,
  `user_id` BIGINT NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_po_user` (`user_type`, `user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_requests` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_type` VARCHAR(10) NOT NULL,
  `user_id` BIGINT NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'PENDING', -- PENDING | APPROVED | REJECTED
  `note` VARCHAR(255) DEFAULT NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `handled_by` INT DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_bot_state` (
  `chat_id` VARCHAR(50) NOT NULL,
  `state` VARCHAR(40) DEFAULT NULL,
  `temp` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
