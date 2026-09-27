// فایل: money-input.js
// جداکننده‌ی سه‌رقمی برای همه‌ی فیلدهای مبلغ (ارزش خودرو، تعهد مالی، حق بیمه، مبلغ پرداخت و ...)
// - مشترک بین پنل مدیریت و پنل شرکت‌ها.
//
// هر <input> با کلاس «money-input» هنگامِ تایپ خودبه‌خود سه‌رقم‌سه‌رقم و با رقم فارسی می‌شود
// (۱۲,۵۰۰,۰۰۰). رقم لاتین یا عربی هم تایپ شود قبول است. سرور همه‌ی این شکل‌ها را با
// money_to_int() به عدد تبدیل و بدون جداکننده ذخیره می‌کند.
//
//   MoneyInput.format(12500000)   -> «۱۲,۵۰۰,۰۰۰»  (برای مقداردهیِ برنامه‌ای به فیلد)
//   MoneyInput.digits('۱۲,۵۰۰')   -> «12500»        (عددِ خام، اگر لازم شد)
//   MoneyInput.apply(root)        -> مقدارِ فعلیِ همه‌ی فیلدهای مبلغِ داخلِ root را قالب‌بندی می‌کند
(function () {
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const toEn = s => String(s == null ? '' : s)
        .replace(/[۰-۹]/g, d => String(FA.indexOf(d)))
        .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
    const toFa = s => String(s).replace(/\d/g, d => FA[d]);

    function digits(v) {
        return toEn(v).replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    }
    function format(v) {
        const d = digits(v);
        return d ? toFa(d.replace(/\B(?=(\d{3})+(?!\d))/g, ',')) : '';
    }

    function onInput(e) {
        const el = e.target;
        if (!el.classList || !el.classList.contains('money-input')) return;
        const raw = el.value;
        const caret = el.selectionStart == null ? raw.length : el.selectionStart;
        // چند رقم قبل از مکان‌نما بود؟ بعد از قالب‌بندی مکان‌نما را بعد از همان تعداد رقم می‌گذاریم
        const digitsBefore = digits(raw.slice(0, caret)).length;
        const out = format(raw);
        if (out === raw) return;
        el.value = out;
        let pos = 0, seen = 0;
        while (pos < out.length && seen < digitsBefore) { if (/[۰-۹]/.test(out[pos])) seen++; pos++; }
        try { el.setSelectionRange(pos, pos); } catch (err) { /* بعضی نوع‌های input انتخاب ندارند */ }
    }

    function apply(root) {
        (root || document).querySelectorAll('input.money-input').forEach(el => {
            if (el.value) el.value = format(el.value);
        });
    }

    document.addEventListener('input', onInput);
    window.MoneyInput = { format, digits, apply };
})();
