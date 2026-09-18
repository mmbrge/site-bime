-- =====================================================================
-- چت داخلی (بین کاربران پنل خودمان) و چت با کاربران شرکت‌های درخواست‌کننده.
-- کاملاً مستقل از تیکت‌های بله‌ای موجود (tickets/ticket_messages) -
-- فقط جدول اضافه می‌کند، چیزی تغییر/حذف نمی‌شود.
-- =====================================================================

-- چت مستقیم (یک‌به‌یک) بین دو کاربر داخلی پنل
CREATE TABLE `staff_chat_messages` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `from_user_id` INT NOT NULL,
  `to_user_id` INT NOT NULL,
  `message` TEXT DEFAULT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversation` (`from_user_id`, `to_user_id`),
  CONSTRAINT `scm_from_fk` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scm_to_fk` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- یک گفتگوی مشترک بین ما و همه‌ی ثبت‌کننده‌های یک شرکت (نه هر کاربر شرکتی جدا)
CREATE TABLE `company_chat_messages` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `company_id` INT NOT NULL,
  `sender_type` ENUM('ADMIN','COMPANY') NOT NULL,
  `sender_user_id` INT DEFAULT NULL,
  `sender_portal_user_id` BIGINT DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `ccm_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
