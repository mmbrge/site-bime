// فایل: table-count.js
// زیرِ هر جدول تعدادِ ردیف‌ها را می‌نویسد و با هر تغییر (بارگذاری، جستجو، فیلتر) خودکار به‌روز می‌کند.
// ردیف‌های «در حال بارگذاری» / «موردی یافت نشد» شمرده نمی‌شوند. برای مستثنا کردنِ یک جدول: class="no-count"
(function () {
    'use strict';
    const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const css = document.createElement('style');
    css.textContent = `.tbl-count{display:flex;justify-content:flex-start;align-items:center;gap:6px;padding:8px 16px;font-size:11px;font-weight:800;color:#64748b;
        background:linear-gradient(90deg,#f8fafc,#fcfdff);border-top:1px solid #eef2f7;margin:0}
        .tbl-count.inside{position:sticky;left:0;right:0;bottom:0;z-index:2}
        .tbl-count b{color:#334155;font-size:12px}
        .tbl-count .tc-dot{width:6px;height:6px;border-radius:50%;background:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}`;
    document.head.appendChild(css);

    const isPlaceholder = tr => {
        const cells = tr.cells;
        if (!cells || !cells.length) return true;
        if (cells.length === 1 && cells[0].colSpan > 1) return true;          // «در حال بارگذاری» / «موردی یافت نشد»
        return false;
    };
    const isLoading = tb => !!tb.querySelector('.fa-spin') || /در حال (بارگذاری|خواندن)/.test(tb.textContent.slice(0, 200));

    // «کارت»: جعبه‌ی گردِ سفید/سایه‌دار که جدول داخلش است
    const cardLike = el => {
        if (!el || el === document.body) return false;
        if (el.classList.contains('card')) return true;
        const cs = getComputedStyle(el);
        return parseFloat(cs.borderTopLeftRadius) > 4 && (cs.boxShadow !== 'none' || !/rgba\(0, 0, 0, 0\)|transparent/.test(cs.backgroundColor));
    };
    // شمارنده همیشه چسبیده به جدول: اگر خودِ جعبه‌ی اسکرول «کارت» است داخلش (پایین و ثابت)، وگرنه درست زیرِ جدول داخلِ همان کارت
    function place(t) {
        const scroller = t.closest('.overflow-x-auto, .overflow-auto, .overflow-y-auto');
        const anchor = scroller || t;
        let box = t._tc;
        if (!box) { box = document.createElement('div'); box.className = 'tbl-count'; box._t = t; t._tc = box; boxes.add(box); }
        if (cardLike(anchor) && anchor !== t) {
            box.classList.add('inside');
            if (box.parentElement !== anchor || anchor.lastElementChild !== box) anchor.appendChild(box);
        } else {
            box.classList.remove('inside');
            if (anchor.nextElementSibling !== box) anchor.after(box);
        }
        return box;
    }
    const boxes = new Set();
    // شمارنده‌ی جدول‌هایی که دوباره ساخته شده‌اند (فیلتر/جستجو) حذف می‌شود تا شمارنده‌ی قبلی تکرار نشود
    function sweep() {
        boxes.forEach(b => { if (!b._t || !b._t.isConnected || b._t.classList.contains('no-count')) { b.remove(); boxes.delete(b); } });
        document.querySelectorAll('.tbl-count').forEach(b => { if (!boxes.has(b)) b.remove(); });
    }
    function update() {
        sweep();
        document.querySelectorAll('table').forEach(t => {
            if (t.classList.contains('no-count') || t.closest('.no-count')) return;
            const tb = t.tBodies[0]; if (!tb) return;
            const box = place(t);
            const rows = [...tb.rows].filter(tr => !isPlaceholder(tr) && tr.style.display !== 'none' && !tr.classList.contains('hidden'));
            const loading = !rows.length && isLoading(tb);
            const html = loading ? '' : `<span class="tc-dot"></span>تعداد ردیف: <b>${fa(rows.length)}</b>`;
            if (box._h !== html) { box.innerHTML = html; box._h = html; box.style.display = loading ? 'none' : ''; }
        });
    }
    let t = null;
    const schedule = () => { if (t) return; t = setTimeout(() => { t = null; update(); }, 180); };
    new MutationObserver(muts => {
        // فقط تغییراتی که به جدول‌ها مربوط است (پیام‌رسان و بقیه‌ی صفحه نادیده)
        for (const m of muts) {
            const n = m.target;
            if (m.type === 'attributes' && n.classList && (n.classList.contains('cursor-dot') || n.classList.contains('cursor-outline'))) continue; // موسِ اختصاصی
            if (n.nodeType === 1 && (n.closest('table') || n.querySelector && n.tagName !== 'BODY' && n.querySelector('table'))) { schedule(); return; }
        }
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
    document.addEventListener('DOMContentLoaded', update);
    update();
})();
