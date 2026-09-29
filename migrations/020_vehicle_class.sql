-- =====================================================================
-- 020: نوعِ خودرو (سواری/وانت یا سنگین) روی ردیف‌های درخواستِ شرکتی
--   برای اینکه «ساخت گزارش بازدید» خودکار قالبِ درست را باز کند
--   (سواری و وانت و استیشن => گزارشِ سواری؛ کامیون، اتوبوس، مینی‌بوس و ... => گزارشِ سنگین).
--   ردیف‌های قبلی خالی می‌مانند و نوعشان از «نام خودرو» حدس زده می‌شود.
-- =====================================================================
SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_request_plates' AND COLUMN_NAME = 'vehicle_class');
SET @q := IF(@c = 0, "ALTER TABLE company_request_plates ADD COLUMN vehicle_class ENUM('LIGHT','HEAVY') NULL DEFAULT NULL AFTER car_name", 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
