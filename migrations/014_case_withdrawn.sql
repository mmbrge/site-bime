-- فایل: migrations/014_case_withdrawn.sql
-- وضعیتِ تازه‌ی «خارج از فاز عملیاتی» (WITHDRAWN) برای درخواست‌های کارکنان.
-- درخواستی که حتی یک مدرکِ تاییدشده دارد دیگر حذف نمی‌شود، فقط به این وضعیت می‌رود.
--
-- اگر ستونِ status از قبل VARCHAR باشد این دستور چیزی را عوض نمی‌کند؛ اگر ENUM باشد
-- آن را به VARCHAR تبدیل می‌کند تا مقدارِ WITHDRAWN هم ذخیره شود (مقدارهای فعلی دست نمی‌خورند).
ALTER TABLE `policy_cases`
  MODIFY COLUMN `status` VARCHAR(40) NULL DEFAULT 'REGISTERED';
