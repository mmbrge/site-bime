// فایل: page-guard.js
// محافظِ صفحه (همه‌ی صفحه‌های سایت): بستنِ میان‌برهای ابزارِ توسعه‌دهنده (F12، Ctrl+Shift+I/J/C/K، Ctrl+U، و در مک ⌘⌥I/J/C/U)
// و Ctrl+S (ذخیره‌ی صفحه: مرورگر خروجیِ HTMLِ همان لحظه را با نامِ آدرس، مثلاً dashboard.php، دانلود می‌کند).
// اگر window.PAGE_GUARD_DETECT روشن باشد (تنظیمات ← سپر امنیتی)، وقتی ابزارِ توسعه‌دهنده از منوی مرورگر باز شود محتوای صفحه پوشانده می‌شود.
// کدِ PHP هیچ‌وقت به مرورگر نمی‌رسد؛ این محافظ فقط دیدنِ HTML/JSِ صفحه را سخت می‌کند.
(function () {
    'use strict';
    if (window.__pageGuard) return;
    window.__pageGuard = true;

    function block(e) {
        const k = (e.key || '').toLowerCase(), c = e.keyCode || 0;
        const mod = e.ctrlKey || e.metaKey;
        let bad = false;
        if (k === 'f12' || c === 123) bad = true;
        else if (mod && e.shiftKey && ['i', 'j', 'c', 'k', 'e', 'm'].indexOf(k) >= 0) bad = true;          // Ctrl+Shift+I/J/C/K (و E/M در فایرفاکس)
        else if (e.metaKey && e.altKey && ['i', 'j', 'c', 'u'].indexOf(k) >= 0) bad = true;                  // مک: ⌘⌥I/J/C/U
        else if (mod && !e.shiftKey && !e.altKey && (k === 'u' || k === 's')) bad = true;                     // نمایشِ سورس / ذخیره‌ی صفحه
        else if (mod && e.shiftKey && k === 's') bad = true;
        if (!bad) return;
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        return false;
    }
    window.addEventListener('keydown', block, true);
    document.addEventListener('keydown', block, true);

    // کلیک‌راست: صفحه‌هایی که منوی خودشان را دارند (پنل، ورود) آن را نگه می‌دارند؛ بقیه منوی مرورگر را نشان نمی‌دهند
    // (روی window و در آخرِ مسیر، تا منوی اختصاصیِ صفحه‌ها که روی document ثبت شده‌اند اول اجرا شوند)
    window.addEventListener('contextmenu', function (e) {
        if (e.defaultPrevented) return;
        const t = e.target;
        if (t && t.closest && t.closest('input, textarea, [contenteditable="true"]')) return;
        e.preventDefault();
    });
    // کشیدنِ عکس‌ها و لینک‌ها به بیرون (برای ذخیره)
    document.addEventListener('dragstart', function (e) {
        const t = e.target;
        if (t && (t.tagName === 'IMG' || t.tagName === 'A') && !(t.closest && t.closest('[draggable="true"]'))) e.preventDefault();
    });

    try { console.log('%cتوقف!', 'color:#e11d48;font-size:32px;font-weight:900'); console.log('%cاین بخش فقط برای توسعه‌دهنده است. اگر کسی از شما خواسته چیزی این‌جا بچسبانید، حسابِ شما در خطر است.', 'font-size:14px'); } catch (e) {}

    // تشخیصِ بازبودنِ ابزارِ توسعه‌دهنده: دستورِ debugger فقط وقتی ابزار باز است اجرا را نگه می‌دارد (برای کاربرِ عادی هیچ اثری ندارد)؛
    // اگر نگه داشت، صفحه پوشانده می‌شود. به بزرگ‌نمایی و اندازه‌ی پنجره ربطی ندارد.
    if (!window.PAGE_GUARD_DETECT) return;
    let ov = null, okRuns = 0;
    function cover(on) {
        if (on && !ov) {
            ov = document.createElement('div');
            ov.setAttribute('style', 'position:fixed;inset:0;z-index:2147483647;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;font-family:inherit;direction:rtl;text-align:center;padding:24px');
            ov.innerHTML = '<div style="font-size:44px">🔒</div><b style="font-size:18px">ابزارِ توسعه‌دهنده باز است</b><span style="font-size:13px;color:#94a3b8">برای ادامه‌ی کار با سایت، آن را ببندید.</span>';
            (document.body || document.documentElement).appendChild(ov);
            document.documentElement.style.overflow = 'hidden';
        } else if (!on && ov) {
            ov.remove(); ov = null;
            document.documentElement.style.overflow = '';
        }
    }
    const trap = new Function('debugger');
    function check() {
        const t = performance.now();
        trap();
        if (performance.now() - t > 120) { okRuns = 0; cover(true); }
        else if (++okRuns >= 2) cover(false);
    }
    setInterval(check, 1000);
})();
