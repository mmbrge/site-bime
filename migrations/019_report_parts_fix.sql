-- =====================================================================
-- 019: اصلاحِ فهرستِ قطعاتِ گزارش‌های بازدید، هماهنگ با ردیف‌های قالب‌ها
--
--  پارسیان (گزارش بازدید اولیه بدنه خودرو):
--   - شناسه‌های p17..p33 با ردیف‌های قالب جابه‌جا بودند (مثلاً تیکِ «کیت پنچرگیری» زیرِ
--     «گلگیر عقب راست» ثبت می‌شد). حالا هر شناسه همان ردیفی است که در قالب تیک می‌خورد.
--   - قطعه‌های موتورسیکلت (p34..p37) که در قالب جایی نداشتند حذف شدند.
--   - «باتری الکتریکی» (p17، فقط خودروهای هیبرید) و «تجهیزات غیرفابریک» (p38..p59) دو کارتِ جدا
--     شدند که پیش‌فرضشان «هیچ‌کدام» است (تیکی نمی‌خورد مگر علامت بزنید). برای رادیو، دوربین،
--     سنسور و ... گزینه‌ها «دارد / ندارد» است.
--   - فیلدِ «ظرفیت» (zarfiat) اضافه شد (در قالب به‌جای عددِ ثابتِ ۵ نشست).
--  پاسارگاد سنگین: t12 «وضعیت لاستیک» در قالب تیک ندارد و از فهرست برداشته شد.
--
--  پس از اجرای این فایل، قالبِ جدیدِ پارسیان (report_assets/templates/template_parsian.docx) را جایگزین
--  کنید یا از «تنظیمات گزارش» دوباره بارگذاری کنید؛ چیدمان خودکار دوباره خوانده می‌شود و تنظیم‌های
--  ویرایشگرِ قالب حفظ می‌شود.
--  اجرای دوباره‌ی این فایل بی‌خطر است.
-- =====================================================================

SET NAMES utf8mb4;

SET @pc := COALESCE((SELECT id FROM report_categories WHERE id = 3 AND insurer = 'PARSIAN'),
                    (SELECT MIN(id) FROM report_categories WHERE insurer = 'PARSIAN'));

UPDATE report_fields SET label = 'وضعیت قطعات فابریک', options =
 'p01|قالپاق‌ها;p02|گلگیر جلو راست;p03|گلگیر جلو چپ;p04|جلو پنجره;p05|آرم جلو;p06|درب‌های سمت راست;p07|رکاب راست;p08|درب موتور;p09|چراغ‌های جلو;p10|آنتن;p11|داشبورد و کنسول;p12|سقف;p13|راهنمای چراغ‌های جلو;p14|شیشه جلو;p15|سپر جلو;p16|رینگ‌ها، لاستیک‌ها و زاپاس;p18|کیت پنچرگیری;p19|گلگیر عقب راست;p20|گلگیر عقب چپ;p21|درب صندوق عقب;p22|آرم عقب;p23|درب‌های سمت چپ;p24|رکاب چپ;p25|یخچال‌ها;p26|چراغ‌های عقب;p27|زه‌های روی بدنه;p28|کیسه هوا (ایربگ);p29|برف پاک‌کن‌ها;p30|شیشه درب‌ها و سایر شیشه‌ها;p31|آینه‌های بغل;p32|شیشه عقب;p33|سپر عقب'
 WHERE category_id = @pc AND field_key = 'parts_status';

INSERT INTO report_fields (category_id, field_key, label, field_type, options, default_value, help, section, sort_order)
SELECT @pc, 'hybrid_parts', 'خودروهای هیبرید', 'PartsStatus', 'p17|باتری الکتریکی', 'none',
       'فقط برای خودروهای هیبرید علامت بزنید؛ در غیر این صورت خالی می‌ماند.', NULL, 152
FROM DUAL WHERE @pc IS NOT NULL AND NOT EXISTS (SELECT 1 FROM report_fields WHERE category_id = @pc AND field_key = 'hybrid_parts');

INSERT INTO report_fields (category_id, field_key, label, field_type, options, default_value, help, section, sort_order)
SELECT @pc, 'extra_parts', 'تجهیزات غیرفابریک (اضافی)', 'PartsStatus',
 'p38|رینگ و لاستیک پهن;p39|گارد جلو;p40|رکاب‌های بغل;p41|گلگیرهای اسپورت;p42|زاپاس‌بند;p43|مه‌شکن (پروژکتور);p44|باله روی درب صندوق;p45|فلاپ بغل;p46|سپرهای اسپورت;p47|باربند و ملزومات;p48|رادیو پخش اصلی|دارد|ندارد;p49|بلندگو/باند|دارد|ندارد;p50|ساب|دارد|ندارد;p51|آمپلی‌فایر|دارد|ندارد;p52|نمایشگر|دارد|ندارد;p53|دوربین‌های جلو|دارد|ندارد;p54|دوربین‌های عقب|دارد|ندارد;p55|سنسورهای جلو|دارد|ندارد;p56|سنسورهای عقب|دارد|ندارد;p57|صندلی برقی|دارد|ندارد;p58|روکش کربن|دارد|ندارد;p59|بخاری درجا|دارد|ندارد',
 'none', 'فقط مواردی که خودرو دارد را علامت بزنید؛ بقیه در گزارش خالی می‌ماند.', NULL, 155
FROM DUAL WHERE @pc IS NOT NULL AND NOT EXISTS (SELECT 1 FROM report_fields WHERE category_id = @pc AND field_key = 'extra_parts');

INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, default_value, section, sort_order)
SELECT @pc, 'zarfiat', 'ظرفیت (نفر / تن)', 'Entry', NULL, 4, NULL, 'capacity,zarfiat', '5', 'مشخصات خودرو', 85
FROM DUAL WHERE @pc IS NOT NULL AND NOT EXISTS (SELECT 1 FROM report_fields WHERE category_id = @pc AND field_key = 'zarfiat');

-- پاسارگاد سنگین: ردیفِ «وضعیت لاستیک‌ها» در قالب تیک ندارد (وضعیتِ لاستیک در فیلدِ «وضعیت لاستیک» نوشته می‌شود)
UPDATE report_fields SET options = REPLACE(options, 't12|وضعیت لاستیک;', '')
 WHERE category_id = 2 AND field_type = 'PartsStatus' AND options LIKE '%t12|وضعیت لاستیک;%';
