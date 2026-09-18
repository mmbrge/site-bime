-- =====================================================================
-- ماژول «شرکت‌ها»: درخواست‌های بیمه‌ی شرکتی، پنل ثبت‌کننده، بایگانی جدا
-- این migration فقط جدول/ستون اضافه می‌کند - هیچ داده یا جدول موجودی را
-- تغییر یا حذف نمی‌کند. قبل از اجرا روی سایت لایو، از دیتابیس بک‌آپ بگیرید.
-- اجرا: از طریق phpMyAdmin روی besiteir_dbm.
-- =====================================================================

-- شرکت‌ها می‌توانند هم «کارفرمای پرسنل» (کسر از حقوق) و هم «مشتری درخواست‌کننده‌ی
-- بیمه» باشند؛ به همین دلیل جدول companies موجود گسترش پیدا می‌کند نه اینکه جدول
-- جدا و موازی ساخته شود (چون جداول مالی از قبل بر اساس company_id کار می‌کنند).
ALTER TABLE `companies`
  ADD COLUMN `kind` ENUM('PERSONNEL_EMPLOYER','INSURANCE_CLIENT','BOTH') NOT NULL DEFAULT 'PERSONNEL_EMPLOYER' AFTER `name`,
  ADD COLUMN `payment_terms` ENUM('INSTALLMENT','CASH_NET30','CASH_IMMEDIATE') DEFAULT NULL AFTER `kind`;

-- نقش جدید برای همکاری که مسئول ارتباط با شرکت‌های درخواست‌کننده است
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('ADMIN','OPERATOR','FINANCE','COMPANY_LIAISON') NOT NULL DEFAULT 'OPERATOR';

-- حساب‌های کاربری ثبت‌کننده در شرکت درخواست‌کننده (فاز ۱: ورود با نام‌کاربری/رمز؛
-- ستون bale_chat_id برای فاز ۲ - مینی‌اپ بله - از الان رزرو شده تا نیازی به تغییر
-- ساختار جدول در آینده نباشد)
CREATE TABLE `company_portal_users` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `company_id` INT NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `mobile_number` VARCHAR(20) DEFAULT NULL,
  `bale_chat_id` VARCHAR(50) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `company_portal_users_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- یک درخواست/نامه از یک شرکت (می‌تواند چند پلاک را همزمان پوشش بدهد)
CREATE TABLE `company_requests` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `company_id` INT NOT NULL,
  `submitted_by` BIGINT DEFAULT NULL,
  `request_text` TEXT DEFAULT NULL,
  `letter_file_path` VARCHAR(500) DEFAULT NULL,
  `insurer` ENUM('PASARGAD','IRAN') NOT NULL DEFAULT 'PASARGAD',
  `status` ENUM('NEW','DOCS_PENDING','DOCS_REVIEW','READY_FOR_ISSUE','ISSUED','CANCELLED') NOT NULL DEFAULT 'NEW',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  KEY `submitted_by` (`submitted_by`),
  CONSTRAINT `company_requests_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_requests_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `company_portal_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- هر پلاک/خودرویی که زیر یک درخواست باید بیمه بشود (یک نامه = چند پلاک ممکن است).
-- عمداً هیچ FK ای به policy_cases ندارد تا صدور شرکتی کاملاً مستقل از پایپ‌لاین
-- پرسنلی/بله بماند و ریسکی متوجه آن جدولِ زنده نشود؛ فیلدهای صدور مستقیماً همینجا هستند.
CREATE TABLE `company_request_plates` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT NOT NULL,
  `plate_p1` VARCHAR(2) DEFAULT NULL,
  `plate_p2` VARCHAR(3) DEFAULT NULL,
  `plate_letter` VARCHAR(5) DEFAULT NULL,
  `plate_p4` VARCHAR(2) DEFAULT NULL,
  `insurance_type` ENUM('THIRDPARTY','BODY') DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `status` ENUM('PENDING','READY_FOR_ISSUE','ISSUED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `policy_number` VARCHAR(50) DEFAULT NULL,
  `vin` VARCHAR(50) DEFAULT NULL,
  `total_premium` BIGINT DEFAULT NULL,
  `folder_path` VARCHAR(500) DEFAULT NULL,
  `issued_file_path` VARCHAR(500) DEFAULT NULL,
  `issued_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `company_request_plates_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `company_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- صندوق ورودی مسطح مدارک/نامه‌های شرکتی: هر فایل ابتدا اینجا و بدون تخصیص می‌نشیند،
-- بعد ادمین/همکار به‌صورت دستی پلاک+نوع مدرک+درخواست را مشخص می‌کند (assign_document)
-- و آن‌وقت است که سیستم به‌طور خودکار فایل را به پوشه‌ی نهایی منتقل می‌کند.
CREATE TABLE `company_documents` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `company_id` INT NOT NULL,
  `request_id` BIGINT DEFAULT NULL,
  `uploaded_by` BIGINT DEFAULT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `orig_name` VARCHAR(255) DEFAULT NULL,
  `file_kind` ENUM('LETTER','SUPPORTING_DOC') NOT NULL DEFAULT 'SUPPORTING_DOC',
  `doc_type` VARCHAR(50) DEFAULT NULL,
  `plate_p1` VARCHAR(2) DEFAULT NULL,
  `plate_p2` VARCHAR(3) DEFAULT NULL,
  `plate_letter` VARCHAR(5) DEFAULT NULL,
  `plate_p4` VARCHAR(2) DEFAULT NULL,
  `status` ENUM('UNASSIGNED','ASSIGNED') NOT NULL DEFAULT 'UNASSIGNED',
  `assigned_by` INT DEFAULT NULL,
  `assigned_at` TIMESTAMP NULL DEFAULT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `company_documents_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_documents_ibfk_2` FOREIGN KEY (`request_id`) REFERENCES `company_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
