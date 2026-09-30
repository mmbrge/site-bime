-- =====================================================================
-- 023: پیام‌رسان - حذفِ «فقط برای من»، پاک کردنِ تاریخچه و قطعِ گفتگو
--   chat_hidden : پیام‌هایی که برای یک نفر (viewer) یا برای همه ('*') پنهان شده‌اند
--                 msg_id = یک پیام ؛ upto_id = همه‌ی پیام‌ها تا این شناسه (پاک کردنِ تاریخچه)
--   chat_state  : گفتگوی قطع‌شده - ONE (یک‌طرفه: طرفِ مقابل نمی‌تواند بفرستد) یا BOTH (هیچ‌کدام)
--   کلِ فایل را یک‌جا اجرا کنید؛ اجرای دوباره‌اش بی‌خطر است.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_hidden` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `viewer` VARCHAR(40) NOT NULL,
    `thread` VARCHAR(40) NOT NULL,
    `msg_id` BIGINT NULL DEFAULT NULL,
    `upto_id` BIGINT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_thread_viewer` (`thread`, `viewer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_state` (
    `thread` VARCHAR(40) NOT NULL,
    `closed_mode` VARCHAR(8) NULL DEFAULT NULL,
    `closed_side` VARCHAR(40) NULL DEFAULT NULL,
    `closed_by` VARCHAR(150) NULL DEFAULT NULL,
    `closed_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`thread`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
