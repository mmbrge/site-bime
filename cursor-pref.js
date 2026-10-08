// فایل: cursor-pref.js
// انتخابِ «نشانگرِ موس» برای همین دستگاه (در <head> و پیش از بقیه بارگذاری می‌شود تا صفحه یک لحظه هم با نشانگرِ اشتباه دیده نشود):
//   fancy  = نشانگرِ اختصاصی با انیمیشن و جلوه‌ها (پیش‌فرض)
//   simple = نشانگرِ اختصاصیِ ساده: فقط نقطه، بدونِ حلقه‌ی دنباله‌رو و جلوه‌ی کلیک/درخشش
//   native = نشانگرِ معمولیِ سیستم (برای سیستم‌هایی که با نشانگرِ اختصاصی کند می‌شوند)
// اگر کاربر چیزی انتخاب نکرده: روی دستگاهِ ضعیف یا با «کاهشِ حرکت» در سیستم‌عامل، خودکار native؛ و اگر در چند ثانیه‌ی
// اولِ حرکتِ موس صفحه کُند بود (فریم‌های کُند)، خودکار به simple می‌رود و یک بار خبر می‌دهد.
(function () {
    'use strict';
    if (window.CursorPref) return;
    var KEY = 'bm_cursor', root = document.documentElement;
    function read() { try { return localStorage.getItem(KEY) || ''; } catch (e) { return ''; } }
    function write(v) { try { v ? localStorage.setItem(KEY, v) : localStorage.removeItem(KEY); } catch (e) {} }
    function weakDevice() {
        try {
            if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return true;
            if (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2) return true;
            if (navigator.deviceMemory && navigator.deviceMemory <= 2) return true;
        } catch (e) {}
        return false;
    }
    function effective() { var v = read(); return v === 'fancy' || v === 'simple' || v === 'native' ? v : (weakDevice() ? 'native' : 'fancy'); }
    function apply(m) {
        root.classList.remove('cur-fancy', 'cur-simple', 'cur-native');
        root.classList.add('cur-' + m);
    }
    // قواعدِ مشترک (صفحه‌ها خودشان «* {cursor:none}» را فقط برای html:not(.cur-native) می‌گذارند)
    var css = 'html.cur-native .cursor-dot,html.cur-native .cursor-outline,html.cur-simple .cursor-outline,html.cur-native .cfx-ripple,html.cur-native .cfx-spark,html.cur-simple .cfx-ripple,html.cur-simple .cfx-spark{display:none!important}'
        + 'html.cur-simple .cursor-dot{transition:none!important;animation:none!important;box-shadow:none!important}';
    var st = document.createElement('style'); st.id = 'cursor-pref-css'; st.textContent = css;
    (document.head || root).appendChild(st);
    apply(effective());

    // پایشِ کُندی: فقط وقتی کاربر خودش چیزی انتخاب نکرده و حالت fancy است
    function watchSlow() {
        if (read() || effective() !== 'fancy' || !window.requestAnimationFrame) return;
        var last = 0, slow = 0, total = 0, active = false, until = 0, done = false;
        function tick(t) {
            if (done) return;
            if (last) { var d = t - last; total++; if (d > 45) slow++; }
            last = t;
            if (total >= 90) {
                done = true;
                if (slow / total > 0.35) {
                    write('simple'); apply('simple');
                    setTimeout(function () {
                        if (typeof window.showToast === 'function') window.showToast('نشانگرِ موس روی این سیستم کُند بود؛ به حالتِ ساده رفت (جعبه‌ابزارِ من ← ظاهر).', 'info');
                    }, 300);
                }
                return;
            }
            if (performance.now() < until) requestAnimationFrame(tick); else { active = false; last = 0; }
        }
        window.addEventListener('mousemove', function () {
            if (done) return;
            until = performance.now() + 400;
            if (!active) { active = true; last = 0; requestAnimationFrame(tick); }
        }, {passive: true});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watchSlow); else watchSlow();

    window.CursorPref = {
        get: effective,
        chosen: read,
        // m: fancy | simple | native | '' (خودکار)
        set: function (m) { write(m); apply(effective()); },
        LABELS: {fancy: 'اختصاصی با انیمیشن', simple: 'اختصاصیِ ساده (بدونِ انیمیشن)', native: 'نشانگرِ معمولیِ سیستم'}
    };
})();
