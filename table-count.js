// فایل: table-count.js
// زیرِ هر جدول تعدادِ ردیف‌ها را می‌نویسد و با هر تغییر (بارگذاری، جستجو، فیلتر) خودکار به‌روز می‌کند.
// ردیف‌های «در حال بارگذاری» / «موردی یافت نشد» شمرده نمی‌شوند. برای مستثنا کردنِ یک جدول: class="no-count"
// صفحه‌بندی: هر جدولی که بیش از «تعداد در صفحه» ردیف دارد صفحه‌بندی می‌شود (پیش‌فرض ۲۰؛ انتخابِ کاربر تا خروج از پنل می‌ماند
// و با ورودِ دوباره به ۲۰ برمی‌گردد). ردیف‌ها فقط پنهان می‌شوند (data-pg-hide)، از DOM حذف نمی‌شوند؛ جستجو/فیلتر/اکسل
// همه‌ی ردیف‌ها را می‌بینند. برای مستثنا کردن: class="no-page" (جدول‌های فرم که در هر ردیف ورودی دارند خودکار مستثنا هستند).
(function () {
    'use strict';
    const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const css = document.createElement('style');
    css.textContent = `.tbl-count{display:flex;justify-content:flex-start;align-items:center;gap:6px;padding:8px 16px;font-size:11px;font-weight:800;color:#64748b;
        background:linear-gradient(90deg,#f8fafc,#fcfdff);border-top:1px solid #eef2f7;margin:0}
        .tbl-count.inside{position:sticky;left:0;right:0;bottom:0;z-index:2}
        .tbl-count b{color:#334155;font-size:12px}
        .tbl-count .tc-dot{width:6px;height:6px;border-radius:50%;background:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
        tr[data-pg-hide]{display:none!important}
        .tbl-count .tp{display:flex;align-items:center;gap:4px;margin-right:auto;flex-wrap:wrap}
        .tbl-count .tp button{min-width:26px;height:26px;padding:0 6px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-weight:800;font-size:11px;cursor:pointer;font-family:inherit}
        .tbl-count .tp button:hover:not(:disabled){border-color:#a5b4fc;color:#4338ca;background:#eef2ff}
        .tbl-count .tp button.on{background:#4f46e5;border-color:#4f46e5;color:#fff}
        .tbl-count .tp button:disabled{opacity:.4;cursor:default}
        .tbl-count .tp .tp-gap{color:#94a3b8;padding:0 2px}
        .tbl-count .tp select{height:26px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:11px;font-weight:800;color:#475569;padding:0 6px;font-family:inherit;margin-right:6px}`;
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
    // جعبه‌ی اسکرولِ خودِ جدول (کلاسی یا style)؛ نه اسکرولِ کلِ صفحه (main / تب) که شمارنده را بیرون از تب، تهِ صفحه می‌برد
    function scrollerOf(t) {
        for (let e = t.parentElement; e && e !== document.body; e = e.parentElement) {
            if (e.tagName === 'MAIN' || e.classList.contains('tab-content')) return null;
            const cs = getComputedStyle(e);
            if (/auto|scroll/.test(cs.overflowX + ' ' + cs.overflowY)) return e;
        }
        return null;
    }
    function place(t) {
        // جایِ شمارنده یک بار حساب می‌شود (getComputedStyle در هر به‌روزرسانی برای جدول‌های بزرگ کند بود)
        if (t._tc && t._tc.isConnected && t._tcOk) return t._tc;
        const scroller = scrollerOf(t);
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
        t._tcOk = true;
        return box;
    }
    const boxes = new Set();
    // جدول‌های کوچکِ داخلِ جزئیاتِ یک بیمه‌نامه (الحاقیه‌ها، اقساط) شمارنده نمی‌خواهند
    const skip = t => t.classList.contains('no-count') || !!t.closest('.no-count,[data-endolist],[data-finplan],.en-tb,.fc-tbl,.hx-b,[data-msgs]') || t.classList.contains('en-tb') || t.classList.contains('fc-tbl');
    // شمارنده‌ی جدول‌هایی که دوباره ساخته شده‌اند (فیلتر/جستجو) حذف می‌شود تا شمارنده‌ی قبلی تکرار نشود
    function sweep() {
        boxes.forEach(b => { if (!b._t || !b._t.isConnected || skip(b._t)) { b.remove(); boxes.delete(b); } });
        document.querySelectorAll('.tbl-count').forEach(b => { if (!boxes.has(b)) b.remove(); });
    }
    // ---------------- صفحه‌بندی ----------------
    const SIZES = [10, 20, 50, 100, 0];   // ۰ = همه
    const SID = String(window.TP_SID || '');
    function pageSize() {
        try { const v = JSON.parse(localStorage.getItem('tp:size') || 'null'); if (v && v.sid === SID && SIZES.includes(v.n)) return v.n; } catch (e) {}
        return 20;
    }
    function setPageSize(n) { try { localStorage.setItem('tp:size', JSON.stringify({sid: SID, n})); } catch (e) {} }
    // جدولِ فرم (ورودیِ متنی/عددی در بیشترِ ردیف‌ها) صفحه‌بندی نمی‌شود تا ردیفی از چشم نیفتد
    const isFormTable = rows => {
        let n = 0; const k = Math.min(rows.length, 12);
        for (let i = 0; i < k; i++) if (rows[i].querySelector('input:not([type=checkbox]):not([type=radio]):not([type=hidden]),textarea,select')) n++;
        return n > k / 2;
    };
    function pagerHtml(page, pages, size, total) {
        const btn = (p, label, dis, on) => `<button type="button" data-pg="${p}" ${dis ? 'disabled' : ''} class="${on ? 'on' : ''}">${label}</button>`;
        let h = '';
        if (pages > 1) {
            h += btn(page - 1, '<i class="fas fa-chevron-right"></i>', page <= 1);
            const set = new Set([1, pages, page - 1, page, page + 1].filter(p => p >= 1 && p <= pages));
            let last = 0;
            [...set].sort((a, b) => a - b).forEach(p => { if (p - last > 1) h += '<span class="tp-gap">…</span>'; h += btn(p, fa(p), false, p === page); last = p; });
            h += btn(page + 1, '<i class="fas fa-chevron-left"></i>', page >= pages);
        }
        h += `<select data-pgsize title="تعداد ردیف در هر صفحه">${SIZES.map(n => `<option value="${n}" ${n === size ? 'selected' : ''}>${n ? fa(n) + ' در صفحه' : 'همه'}</option>`).join('')}</select>`;
        return `<span class="tp">${h}</span>`;
    }
    function paginate(t, rows) {
        const size = pageSize();
        const allRows = [...t.tBodies[0].rows];
        const usable = !t.classList.contains('no-page') && !t.closest('.no-page') && !isFormTable(rows);
        if (!usable || !size || rows.length <= Math.min(...SIZES.filter(Boolean))) {
            allRows.forEach(tr => tr.hasAttribute('data-pg-hide') && tr.removeAttribute('data-pg-hide'));
            return {pages: 1, page: 1, size, show: usable && rows.length > 10};
        }
        const pages = Math.max(1, Math.ceil(rows.length / size));
        // تازه‌سازیِ خودکار (همان تعداد ردیف) صفحه را عوض نمی‌کند؛ فیلتر/جستجو که تعداد را عوض کند به صفحه‌ی اول برمی‌گردد
        if (t._pgN !== rows.length) { t._pgN = rows.length; t._pg = 1; }
        let page = Math.min(Math.max(1, t._pg || 1), pages);
        t._pg = page;
        const from = (page - 1) * size, to = from + size;
        const keep = new Set(rows.slice(from, to)), inRows = new Set(rows);   // Set: با هزاران ردیف هم سریع (قبلاً includes => n²)
        allRows.forEach(tr => {
            const hide = inRows.has(tr) && !keep.has(tr);
            if (hide !== tr.hasAttribute('data-pg-hide')) hide ? tr.setAttribute('data-pg-hide', '') : tr.removeAttribute('data-pg-hide');
        });
        return {pages, page, size, show: true};
    }
    function update() {
        sweep();
        document.querySelectorAll('table').forEach(t => {
            if (skip(t)) return;
            const tb = t.tBodies[0]; if (!tb) return;
            // جدولِ زبانه‌های پنهان حساب نمی‌شود؛ با باز شدنِ زبانه (تغییرِ class) دوباره به‌روز می‌شود
            if (!t.getClientRects().length) return;
            const box = place(t);
            const rows = [...tb.rows].filter(tr => !isPlaceholder(tr) && tr.style.display !== 'none' && !tr.classList.contains('hidden'));
            const loading = !rows.length && isLoading(tb);
            const pg = loading ? null : paginate(t, rows);
            let html = loading ? '' : `<span class="tc-dot"></span>تعداد ردیف: <b>${fa(rows.length)}</b>`;
            if (pg && pg.pages > 1) { const a = (pg.page - 1) * pg.size + 1, b = Math.min(rows.length, pg.page * pg.size); html += `<span style="color:#94a3b8">(ردیفِ ${fa(a)} تا ${fa(b)})</span>`; }
            if (pg && pg.show) html += pagerHtml(pg.page, pg.pages, pg.size, rows.length);
            if (box._h !== html) { box.innerHTML = html; box._h = html; box.style.display = loading ? 'none' : ''; }
        });
    }
    document.addEventListener('click', e => {
        const b = e.target.closest('.tbl-count [data-pg]'); if (!b || b.disabled) return;
        const box = b.closest('.tbl-count'), t = box && box._t; if (!t) return;
        t._pg = +b.dataset.pg; box._h = null; update();
        // بالای جدول دیده شود
        const r = t.getBoundingClientRect(); if (r.top < 0 || r.top > innerHeight) t.scrollIntoView({block: 'start', behavior: 'smooth'});
    });
    document.addEventListener('change', e => {
        const s = e.target.closest('.tbl-count [data-pgsize]'); if (!s) return;
        setPageSize(+s.value);
        document.querySelectorAll('table').forEach(t => { t._pg = 1; });
        boxes.forEach(b => { b._h = null; }); update();
    });
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
