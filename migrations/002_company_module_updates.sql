-- =====================================================================
-- تکمیل ماژول «شرکت‌ها»: پروفایل کامل شرکت، کاربر چندشرکتی، تگ‌گذاری
-- در سطح پلاک، وضعیت پوشه‌بندی، اقساط شرکتی، و گروه‌های بله.
-- فقط جدول/ستون اضافه می‌کند - هیچ چیز موجودی تغییر/حذف نمی‌شود.
-- پیش‌نیاز: migrations/001_company_module.sql باید قبلاً اجرا شده باشد.
-- اجرا: از طریق phpMyAdmin روی besiteir_dbm (قبلش از دیتابیس بک‌آپ بگیرید).
-- =====================================================================

-- پروفایل کامل‌تر شرکت + تنظیمات قسط‌بندی (parent_id از قبل روی companies
-- هست و همان برای «شرکت مادر/زیرمجموعه» استفاده می‌شود - چیز جدیدی لازم نبود)
ALTER TABLE `companies`
  ADD COLUMN `economic_code` VARCHAR(30) DEFAULT NULL AFTER `payment_terms`,
  ADD COLUMN `address` TEXT DEFAULT NULL AFTER `economic_code`,
  ADD COLUMN `phone` VARCHAR(30) DEFAULT NULL AFTER `address`,
  ADD COLUMN `bale_group_chat_id` BIGINT DEFAULT NULL AFTER `phone`,
  ADD COLUMN `installment_count` INT DEFAULT NULL AFTER `bale_group_chat_id`,
  ADD COLUMN `first_due_offset_months` INT NOT NULL DEFAULT 0 AFTER `installment_count`,
  ADD COLUMN `first_due_offset_days` INT NOT NULL DEFAULT 0 AFTER `first_due_offset_months`;

-- گروه‌های بله‌ای که ربات دستیار عضوشان است (برای انتخاب «گروه مرتبط با این شرکت»
-- در فرم شرکت). هر بار ربات پیامی از یک گروه دریافت کند، این جدول به‌روزرسانی
-- می‌شود (نگاه کنید به تغییر api/bale_webhook.php) - نیازی به کار دستی نیست،
-- فقط باید ربات یک‌بار در آن گروه پیامی ببیند تا در این فهرست ظاهر شود.
CREATE TABLE `bot_known_groups` (
  `chat_id` BIGINT NOT NULL,
  `title` VARCHAR(150) DEFAULT NULL,
  `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- یک کاربر ثبت‌کننده می‌تواند به چند شرکت همزمان دسترسی داشته باشد (اگر فقط
-- یکی داشت، در پنلش قفل روی همان می‌ماند). company_portal_users.company_id
-- به‌عنوان «شرکت پیش‌فرض/اول» دست‌نخورده می‌ماند، دسترسی واقعی از همین جدول
-- خوانده می‌شود.
CREATE TABLE `company_portal_user_companies` (
  `portal_user_id` BIGINT NOT NULL,
  `company_id` INT NOT NULL,
  PRIMARY KEY (`portal_user_id`, `company_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `cpuc_user_fk` FOREIGN KEY (`portal_user_id`) REFERENCES `company_portal_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cpuc_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- وضعیت پوشه‌بندی (آیا پس از صدور به بایگانی صادره‌ی مشترک هم منتقل/کپی شده)
-- + امکان استثنا کردن «بازدید سلامت» برای پلاک‌هایی که طبق روال شرکت نیاز ندارند
ALTER TABLE `company_request_plates`
  ADD COLUMN `folder_status` ENUM('PENDING','TRANSFERRED','FAILED') NOT NULL DEFAULT 'PENDING' AFTER `status`,
  ADD COLUMN `skip_health_inspection` TINYINT(1) NOT NULL DEFAULT 0 AFTER `folder_status`;

-- مدارک می‌توانند به یک ردیف/پلاک مشخص هم وصل شوند (نه فقط کل درخواست) -
-- چه توسط خودِ ثبت‌کننده‌ی شرکت (به‌عنوان پیشنهاد، همچنان status=UNASSIGNED
-- تا ما تایید کنیم) و چه توسط ادمین/همکار در زمان تگ‌گذاری
ALTER TABLE `company_documents`
  ADD COLUMN `plate_id` BIGINT DEFAULT NULL AFTER `request_id`,
  ADD CONSTRAINT `company_documents_plate_fk` FOREIGN KEY (`plate_id`) REFERENCES `company_request_plates` (`id`) ON DELETE SET NULL;

-- اقساط بیمه‌نامه‌های شرکتی، هم‌شکل با policy_installments پرسنلی ولی کاملاً
-- مستقل (چون به company_request_plates وصل است نه policy_cases) - عمداً هنوز
-- به موتور تخصیص پرداخت (payments/payment_allocations) وصل نشده؛ آن اتصال را
-- بعد از تایید این فاز به‌صورت جدا انجام می‌دهیم تا به موتور مالی زنده آسیبی نرسد.
CREATE TABLE `company_installments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `plate_id` BIGINT NOT NULL,
  `inst_number` TINYINT NOT NULL,
  `amount` BIGINT NOT NULL,
  `due_jalali` VARCHAR(12) NOT NULL,
  `due_date` DATE NOT NULL,
  `settled_to_pasargad` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_plate_inst` (`plate_id`, `inst_number`),
  CONSTRAINT `company_installments_ibfk_1` FOREIGN KEY (`plate_id`) REFERENCES `company_request_plates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
