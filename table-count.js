// فایل: table-count.js
// زیرِ هر جدول تعدادِ ردیف‌ها را می‌نویسد و با هر تغییر (بارگذاری، جستجو، فیلتر) خودکار به‌روز می‌کند.
// ردیف‌های «در حال بارگذاری» / «موردی یافت نشد» شمرده نمی‌شوند. برای مستثنا کردنِ یک جدول: class="no-count"
(function () {
    'use strict';
    const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const css = document.createElement('style');
    css.textContent = `.tbl-count{display:flex;justify-content:flex-start;align-items:center;gap:6px;padding:7px 14px;font-size:11px;font-weight:800;color:#64748b;
        background:linear-gradient(90deg,#f8fafc,#fff);border-top:1px solid #eef2f7}
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

    function update() {
        document.querySelectorAll('table').forEach(t => {
            if (t.classList.contains('no-count') || t.closest('.no-count')) return;
            const tb = t.tBodies[0]; if (!tb) return;
            const anchor = t.closest('.overflow-x-auto, .overflow-auto') || t;
            let box = anchor.nextElementSibling;
            if (!box || !box.classList.contains('tbl-count')) {
                box = document.createElement('div');
                box.className = 'tbl-count';
                anchor.after(box);
            }
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
