-- =====================================================================
-- 021: ارتقای گفتگوها (پیام‌رسانِ یکپارچه)
--   هر سه نوع گفتگو (کارکنان/کاربرانِ ربات، شرکت‌ها، همکارانِ داخلی) همان جدول‌های قبلی را نگه می‌دارند
--   و این امکانات به هرکدام اضافه می‌شود:
--     پاسخ به یک پیام (reply_to_id)، ویرایش (edited_at)، حذف (deleted_at)،
--     موضوعِ پیام (درخواستِ مربوط: ref_type / ref_id / ref_label) و نامِ اصلیِ فایل (file_name).
--   کلِ فایل را یک‌جا اجرا کنید؛ اجرای دوباره‌اش بی‌خطر است.
-- =====================================================================
SET NAMES utf8mb4;


-- ticket_messages
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'reply_to_id') = 0, 'ALTER TABLE `ticket_messages` ADD COLUMN `reply_to_id` BIGINT NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'edited_at') = 0, 'ALTER TABLE `ticket_messages` ADD COLUMN `edited_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'deleted_at') = 0, 'ALTER TABLE `ticket_messages` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'ref_type') = 0, 'ALTER TABLE `ticket_messages` ADD COLUMN `ref_type` VARCHAR(20) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'ref_id') = 0, 'ALTER TABLE `ticket_messages` ADD COLUMN `ref_id` BIGINT NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'ref_label') = 0, 'ALTER TABLE `ticket_messages` ADD COLUMN `ref_label` VARCHAR(190) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'file_name') = 0, 'ALTER TABLE `ticket_messages` ADD COLUMN `file_name` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- company_chat_messages
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_chat_messages' AND COLUMN_NAME = 'reply_to_id') = 0, 'ALTER TABLE `company_chat_messages` ADD COLUMN `reply_to_id` BIGINT NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_chat_messages' AND COLUMN_NAME = 'edited_at') = 0, 'ALTER TABLE `company_chat_messages` ADD COLUMN `edited_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_chat_messages' AND COLUMN_NAME = 'deleted_at') = 0, 'ALTER TABLE `company_chat_messages` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_chat_messages' AND COLUMN_NAME = 'ref_type') = 0, 'ALTER TABLE `company_chat_messages` ADD COLUMN `ref_type` VARCHAR(20) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_chat_messages' AND COLUMN_NAME = 'ref_id') = 0, 'ALTER TABLE `company_chat_messages` ADD COLUMN `ref_id` BIGINT NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_chat_messages' AND COLUMN_NAME = 'ref_label') = 0, 'ALTER TABLE `company_chat_messages` ADD COLUMN `ref_label` VARCHAR(190) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_chat_messages' AND COLUMN_NAME = 'file_name') = 0, 'ALTER TABLE `company_chat_messages` ADD COLUMN `file_name` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- staff_chat_messages
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_chat_messages' AND COLUMN_NAME = 'reply_to_id') = 0, 'ALTER TABLE `staff_chat_messages` ADD COLUMN `reply_to_id` BIGINT NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_chat_messages' AND COLUMN_NAME = 'edited_at') = 0, 'ALTER TABLE `staff_chat_messages` ADD COLUMN `edited_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_chat_messages' AND COLUMN_NAME = 'deleted_at') = 0, 'ALTER TABLE `staff_chat_messages` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_chat_messages' AND COLUMN_NAME = 'ref_type') = 0, 'ALTER TABLE `staff_chat_messages` ADD COLUMN `ref_type` VARCHAR(20) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_chat_messages' AND COLUMN_NAME = 'ref_id') = 0, 'ALTER TABLE `staff_chat_messages` ADD COLUMN `ref_id` BIGINT NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_chat_messages' AND COLUMN_NAME = 'ref_label') = 0, 'ALTER TABLE `staff_chat_messages` ADD COLUMN `ref_label` VARCHAR(190) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_chat_messages' AND COLUMN_NAME = 'file_name') = 0, 'ALTER TABLE `staff_chat_messages` ADD COLUMN `file_name` VARCHAR(255) NULL DEFAULT NULL', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
