-- =====================================================================
-- گزارش بازدید خودرو (نسخه‌ی تحت وبِ برنامه‌ی ویندوزی «سیستم صدور گزارش بازدید»)
--   report_categories   : انواع گزارش (قالب Word، الگوریتم استخراج، برچسب‌های سالم/خسارتی، دسترسی)
--   report_fields       : فیلدهای هر نوع گزارش (جایگزینِ fields_config.xlsx)
--   report_visitors     : بازدیدکننده‌ها (+ این‌که برای کدام نوع گزارش)
--   report_insureds     : بیمه‌گذاران آماده (دستی، یا از اشخاص/شرکت‌های موجود) (+ نوع گزارش)
--   report_fonts        : قلم‌هایی که برای قالب‌ها آپلود می‌شود (نامِ قلمِ Word => فایل)
--   visit_reports       : هر گزارشِ صادرشده، با همه‌ی اطلاعات
--   visit_report_photos : عکس‌های هر گزارش
--   visit_report_versions : نسخه‌های قبلیِ هر گزارش (بعد از هر ویرایش)
--   و نقشِ تازه‌ی «کاربر پارسیان» (PARSIAN) در کاربران پنل
-- اجرا: phpMyAdmin روی besiteir_dbm (قبلش از دیتابیس بک‌آپ بگیرید).
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `users` MODIFY `role` ENUM('ADMIN','OPERATOR','FINANCE','COMPANY_LIAISON','PARSIAN') NOT NULL DEFAULT 'OPERATOR';

CREATE TABLE IF NOT EXISTS `report_categories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `insurer` VARCHAR(30) NOT NULL DEFAULT 'PASARGAD',
  `template_path` VARCHAR(300) DEFAULT NULL,
  `template_orig_name` VARCHAR(200) DEFAULT NULL,
  `layout_json` MEDIUMTEXT DEFAULT NULL,
  `layout_updated_at` DATETIME DEFAULT NULL,
  `parser_path` VARCHAR(300) DEFAULT NULL,
  `parser_orig_name` VARCHAR(200) DEFAULT NULL,
  `ok_suffix` VARCHAR(10) NOT NULL DEFAULT 's',
  `bad_suffix` VARCHAR(10) NOT NULL DEFAULT 'k',
  `ok_label` VARCHAR(40) NOT NULL DEFAULT 'سالم',
  `bad_label` VARCHAR(40) NOT NULL DEFAULT 'خسارتی',
  `tick_mark` VARCHAR(10) NOT NULL DEFAULT '✔',
  `access_json` TEXT DEFAULT NULL,
  `options_json` TEXT DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_fields` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT NOT NULL,
  `field_key` VARCHAR(60) NOT NULL,
  `label` VARCHAR(150) NOT NULL,
  `field_type` VARCHAR(20) NOT NULL DEFAULT 'Entry',
  `options` TEXT DEFAULT NULL,
  `max_chars` INT DEFAULT NULL,
  `excel_column` VARCHAR(60) DEFAULT NULL,
  `parser_keys` VARCHAR(255) DEFAULT NULL,
  `section` VARCHAR(100) DEFAULT NULL,
  `show_if` VARCHAR(255) DEFAULT NULL,
  `tick_map` TEXT DEFAULT NULL,
  `words_var` VARCHAR(60) DEFAULT NULL,
  `default_value` VARCHAR(255) DEFAULT NULL,
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `width` VARCHAR(10) DEFAULT NULL,
  `help` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_rf_cat` (`category_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_visitors` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_visitor_categories` (
  `visitor_id` INT NOT NULL,
  `category_id` INT NOT NULL,
  PRIMARY KEY (`visitor_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_insureds` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `national_id` VARCHAR(20) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `source` VARCHAR(10) NOT NULL DEFAULT 'MANUAL',
  `source_id` BIGINT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_insured_categories` (
  `insured_id` INT NOT NULL,
  `category_id` INT NOT NULL,
  PRIMARY KEY (`insured_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_fonts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `word_name` VARCHAR(100) NOT NULL,
  `family` VARCHAR(60) NOT NULL,
  `regular_file` VARCHAR(200) DEFAULT NULL,
  `bold_file` VARCHAR(200) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rfont_word` (`word_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `visit_reports` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `report_no` VARCHAR(30) DEFAULT NULL,
  `category_id` INT NOT NULL,
  `category_name` VARCHAR(150) DEFAULT NULL,
  `insurer` VARCHAR(30) DEFAULT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'ACTIVE',
  `report_date` VARCHAR(12) NOT NULL,
  `report_date_g` DATE DEFAULT NULL,
  `plate_p1` VARCHAR(4) DEFAULT NULL,
  `plate_letter` VARCHAR(8) DEFAULT NULL,
  `plate_p2` VARCHAR(4) DEFAULT NULL,
  `plate_iran` VARCHAR(4) DEFAULT NULL,
  `plate_display` VARCHAR(60) DEFAULT NULL,
  `insured_name` VARCHAR(200) DEFAULT NULL,
  `national_id` VARCHAR(20) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `insured_id` INT DEFAULT NULL,
  `vehicle_type` VARCHAR(150) DEFAULT NULL,
  `model_year` VARCHAR(20) DEFAULT NULL,
  `color` VARCHAR(60) DEFAULT NULL,
  `usage_type` VARCHAR(60) DEFAULT NULL,
  `engine_no` VARCHAR(60) DEFAULT NULL,
  `chassis_no` VARCHAR(60) DEFAULT NULL,
  `car_value` BIGINT DEFAULT NULL,
  `visitor_id` INT DEFAULT NULL,
  `visitor_name` VARCHAR(120) DEFAULT NULL,
  `issuer_user_id` INT DEFAULT NULL,
  `issuer_name` VARCHAR(120) DEFAULT NULL,
  `form_json` MEDIUMTEXT DEFAULT NULL,
  `data_json` MEDIUMTEXT DEFAULT NULL,
  `folder_path` VARCHAR(700) DEFAULT NULL,
  `pdf_path` VARCHAR(700) DEFAULT NULL,
  `docx_path` VARCHAR(700) DEFAULT NULL,
  `zip_path` VARCHAR(700) DEFAULT NULL,
  `photos_count` INT NOT NULL DEFAULT 0,
  `version` INT NOT NULL DEFAULT 1,
  `health_inspection_id` BIGINT DEFAULT NULL,
  `case_id` BIGINT DEFAULT NULL,
  `company_plate_id` BIGINT DEFAULT NULL,
  `linked_at` DATETIME DEFAULT NULL,
  `linked_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  `deleted_by` INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vr_no` (`report_no`),
  KEY `idx_vr_issuer` (`issuer_user_id`, `id`),
  KEY `idx_vr_date` (`report_date_g`),
  KEY `idx_vr_chassis` (`chassis_no`),
  KEY `idx_vr_health` (`health_inspection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `visit_report_photos` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `report_id` BIGINT NOT NULL,
  `file_path` VARCHAR(700) NOT NULL,
  `orig_name` VARCHAR(255) DEFAULT NULL,
  `source` VARCHAR(10) NOT NULL DEFAULT 'UPLOAD',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vrp_report` (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `visit_report_versions` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `report_id` BIGINT NOT NULL,
  `version` INT NOT NULL,
  `pdf_path` VARCHAR(700) DEFAULT NULL,
  `docx_path` VARCHAR(700) DEFAULT NULL,
  `data_json` MEDIUMTEXT DEFAULT NULL,
  `form_json` MEDIUMTEXT DEFAULT NULL,
  `edited_by` INT DEFAULT NULL,
  `edited_by_name` VARCHAR(120) DEFAULT NULL,
  `edited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vrv_report` (`report_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- داده‌ی اولیه: همان سه نوع گزارشِ برنامه‌ی ویندوزی، با همه‌ی فیلدها ----
INSERT INTO report_categories (id, name, insurer, template_path, template_orig_name, parser_path, parser_orig_name, ok_suffix, bad_suffix, ok_label, bad_label, tick_mark, access_json, sort_order) VALUES (1, 'پاسارگاد - سواری و وانت و استیشن', 'PASARGAD', 'report_assets/templates/template_car.docx', 'template_car.docx', 'report_assets/parsers/template_car.py', 'template_car.py', 's', 'k', 'سالم', 'خسارتی', '✔', '{"roles":["ADMIN"],"users":[]}', 1);
INSERT INTO report_categories (id, name, insurer, template_path, template_orig_name, parser_path, parser_orig_name, ok_suffix, bad_suffix, ok_label, bad_label, tick_mark, access_json, sort_order) VALUES (2, 'پاسارگاد - سنگین و کامیون و مینی‌بوس و کامیونت و اتوبوس', 'PASARGAD', 'report_assets/templates/template_truck.docx', 'template_truck.docx', 'report_assets/parsers/template_truck.py', 'template_truck.py', 's', 'k', 'سالم', 'خسارتی', '✔', '{"roles":["ADMIN"],"users":[]}', 2);
INSERT INTO report_categories (id, name, insurer, template_path, template_orig_name, parser_path, parser_orig_name, ok_suffix, bad_suffix, ok_label, bad_label, tick_mark, access_json, sort_order) VALUES (3, 'پارسیان - گزارش بازدید اولیه بدنه خودرو', 'PARSIAN', 'report_assets/templates/template_parsian.docx', 'template_parsian.docx', 'report_assets/parsers/template_parsian.py', 'template_parsian.py', 's', 'k', 'سالم', 'آسیب دیده', '✔', '{"roles":["ADMIN","PARSIAN"],"users":[]}', 3);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'karshenas', 'بازدید کننده', 'Visitor', NULL, 10, 'بازدید کننده', 'inspector,karshenas', 'اطلاعات بازدید', NULL, NULL, NULL, 10);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'time', 'ساعت بازدید', 'Time', NULL, 5, NULL, NULL, 'اطلاعات بازدید', NULL, NULL, NULL, 20);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'noecar', 'نوع وسیله نقلیه', 'Entry', NULL, 30, 'نوع وسیله', 'vehicle_type,noecar', 'مشخصات خودرو', NULL, NULL, NULL, 30);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'sal_sakht', 'سال ساخت', 'Entry', NULL, 4, 'مدل/سال', 'year,manufacture_year,sal_sakht', 'مشخصات خودرو', NULL, NULL, NULL, 40);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'rang', 'رنگ', 'Entry', NULL, 20, 'رنگ', 'color,rang', 'مشخصات خودرو', NULL, NULL, NULL, 50);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'motor', 'شماره موتور', 'Entry', NULL, 30, 'شماره موتور', 'engine_no,motor', 'مشخصات خودرو', NULL, NULL, NULL, 60);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'shasi', 'شماره شاسی', 'Entry', NULL, 30, 'شماره شاسی', 'chassis_no,shasi,vin', 'مشخصات خودرو', NULL, NULL, NULL, 70);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'cylinder_count', 'تعداد سیلندر', 'Entry', NULL, 2, 'سیلندر', 'cylinder_count', 'مشخصات خودرو', NULL, NULL, NULL, 80);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'mordes', 'مورد استفاده', 'Entry', NULL, 30, 'مورد استفاده', 'usage,mordes', 'مشخصات خودرو', NULL, NULL, NULL, 90);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'arzesh', 'ارزش وسیله نقلیه', 'Money', NULL, 24, 'ارزش وسیله', 'insured_value,arzesh', 'مشخصات خودرو', NULL, NULL, 'arzesh_words', 100);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (1, 'parts_status', 'وضعیت قطعات', 'PartsStatus', 'c1|شیشه جلو;c2|برف پاک کن ها;c3|سپر جلو;c4|جلو پنجره و آرم;c5|سینی زیر نمره جلو;c6|درب موتور;c7|چراغ های جلو;c8|راهنماهای جلو;c9|آینه های بغل;c10|آنتن;c11|گلگیر جلو راست;c12|درب سمت راست و رکاب;c13|گلگیر عقب راست;c14|خطر و راهنمای عقب;c15|شیشه عقب;c16|درب صندوق عقب;c17|سینی زیر نمره عقب;c18|سپر عقب;c19|گلگیر عقب چپ;c20|درب سمت چپ و رکاب;c21|گلگیر جلو چپ;c22|سقف;c23|شیشه های درب ها;c24|رینگ ها و لاستیک;c25|قالپاق ها;c26|زه های روی بدنه;c27|داشبورد و کنسول;c28|ایربگ;c29|یخچال;c30|قفل مرکزی', NULL, NULL, NULL, 'وضعیت قطعات', NULL, NULL, NULL, 110);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'karshenassa', 'بازدید کننده', 'Visitor', NULL, 10, 'بازدید کننده', 'inspector,karshenas', 'اطلاعات بازدید', NULL, NULL, NULL, 10);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'timesa', 'ساعت بازدید', 'Time', NULL, 5, NULL, NULL, 'اطلاعات بازدید', NULL, NULL, NULL, 20);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'noecarsa', 'نوع وسیله نقلیه', 'Entry', NULL, 30, 'نوع وسیله', 'vehicle_type,noecar', 'مشخصات خودرو', NULL, NULL, NULL, 30);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'sal_sakhtsa', 'سال ساخت', 'Entry', NULL, 4, 'مدل/سال', 'year,manufacture_year,sal_sakht', 'مشخصات خودرو', NULL, NULL, NULL, 40);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'rangsa', 'رنگ', 'Entry', NULL, 20, 'رنگ', 'color,rang', 'مشخصات خودرو', NULL, NULL, NULL, 50);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'motorsa', 'شماره موتور', 'Entry', NULL, 30, 'شماره موتور', 'engine_no,motor', 'مشخصات خودرو', NULL, NULL, NULL, 60);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'shasisa', 'شماره شاسی', 'Entry', NULL, 30, 'شماره شاسی', 'chassis_no,shasi,vin', 'مشخصات خودرو', NULL, NULL, NULL, 70);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'cylinder_count', 'تعداد سیلندر', 'Entry', NULL, 2, 'سیلندر', 'cylinder_count', 'مشخصات خودرو', NULL, NULL, NULL, 80);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'mordessa', 'مورد استفاده', 'Entry', NULL, 30, 'مورد استفاده', 'usage,mordes', 'مشخصات خودرو', NULL, NULL, NULL, 90);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'arzeshsa', 'ارزش وسیله نقلیه', 'Money', NULL, 24, 'ارزش وسیله', 'insured_value,arzesh', 'مشخصات خودرو', NULL, NULL, 'arzesh_words', 100);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (2, 'parts_status', 'وضعیت قطعات', 'PartsStatus', 't1|شیشه جلو;t2|شیشه عقب;t3|شیشه درب‌ها و سایر شیشه‌ها;t4|آینه‌های بغل;t5|چراغ‌های جلو;t6|راهنمای جلو;t7|سپر جلو;t8|جلو پنجره;t9|برف پاک کن;t10|سینی جلو و آرم جلو;t11|سقف;t12|وضعیت لاستیک;t13|گلگیرهای جلو;t14|درب‌های جلو;t15|درب موتور;t16|بدنه و گلگیر عقب;t17|درب صندوق بار;t18|سپر عقب;t19|راهنمای خطر عقب;t20|سینی عقب;t21|بدنه و گلگیر عقب چپ;t22|آفتاب‌گیر;t23|بادشکن;t24|پروژکتور', NULL, NULL, NULL, 'وضعیت قطعات', NULL, NULL, NULL, 110);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'karshenas', 'بازدید کننده', 'Visitor', NULL, 10, 'بازدید کننده', 'inspector,karshenas', 'اطلاعات بازدید', NULL, NULL, NULL, 10);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'time', 'ساعت بازدید', 'Time', NULL, 5, NULL, NULL, 'اطلاعات بازدید', NULL, NULL, NULL, 20);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'noecar', 'نوع وسیله نقلیه', 'Entry', NULL, 30, 'نوع وسیله', 'vehicle_type,noecar', 'مشخصات خودرو', NULL, NULL, NULL, 30);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'sal_sakht', 'سال ساخت', 'Entry', NULL, 4, 'مدل/سال', 'year,manufacture_year,sal_sakht', 'مشخصات خودرو', NULL, NULL, NULL, 40);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'rang', 'رنگ', 'Entry', NULL, 20, 'رنگ', 'color,rang', 'مشخصات خودرو', NULL, NULL, NULL, 50);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'motor', 'شماره موتور', 'Entry', NULL, 30, 'شماره موتور', 'engine_no,motor', 'مشخصات خودرو', NULL, NULL, NULL, 60);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'shasi', 'شماره شاسی', 'Entry', NULL, 30, 'شماره شاسی', 'chassis_no,shasi,vin', 'مشخصات خودرو', NULL, NULL, NULL, 70);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'cylinder_count', 'تعداد سیلندر', 'Entry', NULL, 2, 'سیلندر', 'cylinder_count', 'مشخصات خودرو', NULL, NULL, NULL, 80);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'mordes', 'مورد استفاده', 'Entry', NULL, 30, 'مورد استفاده', 'usage,mordes', 'مشخصات خودرو', NULL, NULL, NULL, 90);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'arzesh', 'ارزش وسیله نقلیه', 'Money', NULL, 24, 'ارزش وسیله', 'insured_value,arzesh', 'مشخصات خودرو', NULL, NULL, 'arzesh_words', 100);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'plate_type', 'نوع پلاک', 'Combobox', 'شخصی,عمومی,دولتی,نظامی,سایر', 20, NULL, NULL, 'وضعیت کلی', NULL, '{"شخصی": "plate_personal", "عمومی": "plate_public", "دولتی": "plate_government", "نظامی": "plate_police", "سایر": "plate_other"}', NULL, 110);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'plate_other_text', 'عنوان نوع پلاک سایر', 'Entry', NULL, 30, NULL, NULL, 'وضعیت کلی', 'plate_type=سایر', NULL, NULL, 120);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'overall_condition', 'وضعیت کلی خودرو', 'Combobox', 'خوب,متوسط,بد و غیرقابل قبول', 30, NULL, NULL, 'وضعیت کلی', NULL, '{"خوب": "overall_good", "متوسط": "overall_medium", "بد و غیرقابل قبول": "overall_bad"}', NULL, 130);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'engine_status', 'وضعیت موتور', 'Combobox', 'روشن,حرکت می‌کند,حرکت نمی‌کند', 30, NULL, NULL, 'وضعیت کلی', NULL, '{"روشن": "engine_running", "حرکت می‌کند": "engine_moving", "حرکت نمی‌کند": "engine_not_moving"}', NULL, 140);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'parts_status', 'وضعیت قطعات و تجهیزات', 'PartsStatus', 'p01|قالپاق‌ها;p02|گلگیر جلو راست;p03|گلگیر جلو چپ;p04|جلو پنجره;p05|آرم جلو;p06|درب‌های سمت راست;p07|رکاب راست;p08|درب موتور;p09|چراغ‌های جلو;p10|آنتن;p11|داشبورد و کنسول;p12|سقف;p13|راهنمای چراغ‌های جلو;p14|شیشه جلو;p15|سپر جلو;p16|رینگ‌ها و لاستیک‌ها;p17|تیپ پشت‌سری;p18|گلگیر عقب راست;p19|گلگیر عقب چپ;p20|درب صندوق عقب;p21|آرم عقب;p22|درب‌های سمت چپ;p23|رکاب چپ;p24|پنجره‌ها;p25|چراغ‌های عقب;p26|زه‌های روی بدنه;p27|کیسه هوا (ایربگ);p28|درب باک;p29|شیشه درب‌ها و سایر شیشه‌ها;p30|آینه‌های بغل;p31|شیشه عقب;p32|سپر عقب;p33|آرم عقب موتورسیکلت;p34|چراغ‌های عقب موتورسیکلت;p35|راهنمای چراغ‌های جلو و عقب;p36|رینگ‌ها و لاستیک‌ها موتورسیکلت;p37|وضعیت موتور در حالت روشن;p38|رینگ و لاستیک پهن;p39|گارد جلو;p40|رکاب‌های بغل;p41|گلگیرهای اسپرت;p42|زاپاس‌بند;p43|مه‌شکن (پروژکتور);p44|پایه روی درب صندوق;p45|فلاپ بغل;p46|سپرهای اسپرت;p47|باربند و ملزومات;p48|رادیو پخش اصلی;p49|بلندگو/باند;p50|ساب;p51|آمپلی‌فایر;p52|نمایشگر;p53|دوربین‌های جلو;p54|دوربین‌های عقب;p55|سنسورهای جلو;p56|سنسورهای عقب;p57|صندلی برقی;p58|روکش کربن;p59|بخاری درجا', NULL, NULL, NULL, 'وضعیت قطعات', NULL, NULL, NULL, 150);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, sort_order) VALUES (3, 'damage_locations', 'مواضع آسیب‌دیده', 'DamageList', '10', NULL, NULL, NULL, 'مواضع آسیب‌دیده', NULL, NULL, NULL, 160);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'lastikka', 'وضعیت لاستیک', 'Entry', NULL, 'یدک و لاستیک', NULL, 120);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'has_yadak', 'دارای یدک می‌باشد', 'Checkbox', NULL, 'یدک و لاستیک', NULL, 130);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'yadaknk', 'نوع یدک', 'Entry', NULL, 'یدک و لاستیک', 'has_yadak=1', 140);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'pelakk', 'شماره شهربانی یدک', 'Entry', NULL, 'یدک و لاستیک', 'has_yadak=1', 150);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'salsayk', 'سال ساخت یدک', 'Entry', NULL, 'یدک و لاستیک', 'has_yadak=1', 160);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'rangyk', 'رنگ یدک', 'Entry', NULL, 'یدک و لاستیک', 'has_yadak=1', 170);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'termoshk', 'ترموکینگ', 'Entry', NULL, 'یدک و لاستیک', 'has_yadak=1', 180);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'shasiyk', 'شاسی یدک', 'Entry', NULL, 'یدک و لاستیک', 'has_yadak=1', 190);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'arzeshyakchal', 'ارزش یخچال', 'Money', NULL, 'یدک و لاستیک', 'has_yadak=1', 200);
INSERT INTO report_fields (category_id, field_key, label, field_type, options, section, show_if, sort_order) VALUES (2, 'arzeshyadak', 'ارزش یدک', 'Money', NULL, 'یدک و لاستیک', 'has_yadak=1', 210);
INSERT INTO report_visitors (id, name) VALUES (1, 'علی طارمی'), (2, 'مینو آقاجانی');
INSERT INTO report_visitor_categories (visitor_id, category_id) VALUES (1, 1), (1, 2), (1, 3), (2, 3);
