// فهرستِ بازشوی زیبا برای همه‌ی <select>های پنل (به‌جای فهرستِ سفید و ساده‌ی مرورگر)
// خودِ <select> سرِ جایش می‌ماند (مقدار، رویدادِ change و کدهای قبلی دست نمی‌خورند)؛ فقط به‌جای پنجره‌ی مرورگر این فهرست باز می‌شود:
//   جستجو (وقتی گزینه‌ها زیاد است)، گروه‌ها (optgroup)، گزینه‌ی غیرفعال، تیکِ گزینه‌ی انتخاب‌شده، کیبورد (↑ ↓ Enter Esc).
// استثنا: select چندتایی/با size، غیرفعال، data-native، و گوشی‌ها (انتخابگرِ خودِ گوشی بهتر است).
(function () {
    'use strict';
    if (window.UISelect) return;
    const fine = () => !window.matchMedia || matchMedia('(pointer:fine)').matches;
    const skip = s => !s || s.multiple || s.size > 1 || s.disabled || s.hasAttribute('data-native') || s.closest('[data-native-select]');
    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const norm = t => String(t || '').toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[ً-ٟ‌]/g, '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim();

    function css() {
        if (document.getElementById('uis-css')) return;
        const s = document.createElement('style'); s.id = 'uis-css';
        s.textContent = `
        .uis{position:fixed;z-index:2147483000;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 24px 60px -18px rgba(15,23,42,.35),0 4px 14px -6px rgba(15,23,42,.12);
             display:flex;flex-direction:column;overflow:hidden;font-family:inherit;direction:rtl;animation:uis-in .14s ease-out;min-width:170px}
        .uis.up{animation-name:uis-up}
        @keyframes uis-in{from{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}
        @keyframes uis-up{from{opacity:0;transform:translateY(6px) scale(.98)}to{opacity:1;transform:none}}
        .uis-s{margin:8px 8px 4px;border:1.5px solid #e2e8f0;border-radius:11px;padding:7px 32px 7px 10px;font-size:12.5px;font-weight:700;outline:none;background:#f8fafc
             url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%2394a3b8' stroke-width='2.4' stroke-linecap='round'%3E%3Ccircle cx='6' cy='6' r='4.5'/%3E%3Cpath d='M9.5 9.5L13 13'/%3E%3C/svg%3E") no-repeat right 10px center;font-family:inherit;color:#0f172a}
        .uis-s:focus{border-color:#8b5cf6;background-color:#fff;box-shadow:0 0 0 3px #ede9fe}
        .uis-l{overflow-y:auto;padding:5px;overscroll-behavior:contain;scrollbar-width:thin}
        .uis-o{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;font-size:12.5px;font-weight:700;color:#334155;cursor:pointer;line-height:1.6;user-select:none}
        .uis-o:before{content:'';width:16px;height:16px;flex:none;border-radius:6px}
        .uis-o.h{background:#f1f5f9;color:#0f172a}
        .uis-o.on{background:linear-gradient(120deg,#f5f3ff,#ecfeff);color:#5b21b6}
        .uis-o.on:before{background:linear-gradient(135deg,#7c3aed,#06b6d4) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' stroke='%23fff' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 8.5l2.6 2.6L12 5.5'/%3E%3C/svg%3E") center/16px no-repeat;box-shadow:0 4px 10px -4px #7c3aed}
        .uis-o.dis{opacity:.4;cursor:default}
        .uis-o.ph{color:#94a3b8}
        .uis-g{font-size:10.5px;font-weight:900;color:#7c3aed;padding:9px 10px 4px;letter-spacing:.2px}
        .uis-g:not(:first-child){border-top:1px dashed #e2e8f0;margin-top:4px}
        .uis-n{padding:14px;text-align:center;font-size:12px;color:#94a3b8;font-weight:700}
        select.uis-open{box-shadow:0 0 0 3px #ede9fe !important;border-color:#a78bfa !important}`;
        document.head.appendChild(s);
    }

    let cur = null;   // {sel, el, list, items, hi, onScroll}
    function close(refocus) {
        if (!cur) return;
        const c = cur; cur = null;
        c.el.remove(); c.sel.classList.remove('uis-open');
        window.removeEventListener('scroll', c.onScroll, true);
        window.removeEventListener('resize', c.onResize);
        if (refocus) try { c.sel.focus({ preventScroll: true }); } catch (e) {}
    }
    function choose(i) {
        if (!cur) return;
        const it = cur.items[i]; if (!it || it.dis) return;
        const sel = cur.sel;
        close(true);
        if (sel.selectedIndex !== it.idx) {
            sel.selectedIndex = it.idx;
            sel.dispatchEvent(new Event('input', { bubbles: true }));
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    function highlight(i, scroll) {
        if (!cur) return;
        const vis = cur.items.filter(x => !x.hide && !x.dis);
        if (!vis.length) return;
        cur.items.forEach(x => x.node && x.node.classList.remove('h'));
        const it = cur.items[i]; if (!it || it.hide) return;
        cur.hi = i; it.node.classList.add('h');
        if (scroll) it.node.scrollIntoView({ block: 'nearest' });
    }
    function move(d) {
        if (!cur) return;
        const order = cur.items.map((x, i) => i).filter(i => !cur.items[i].hide && !cur.items[i].dis);
        if (!order.length) return;
        let p = order.indexOf(cur.hi);
        p = p < 0 ? (d > 0 ? 0 : order.length - 1) : Math.max(0, Math.min(order.length - 1, p + d));
        highlight(order[p], true);
    }
    function place() {
        if (!cur) return;
        const r = cur.sel.getBoundingClientRect(), el = cur.el;
        if (!r.width && !r.height) { close(); return; }
        const vw = document.documentElement.clientWidth, vh = window.innerHeight;
        const w = Math.min(Math.max(r.width, 190), vw - 16, 420);
        const below = vh - r.bottom - 10, above = r.top - 10;
        const up = below < 220 && above > below;
        const maxH = Math.max(140, Math.min(340, up ? above : below));
        el.style.width = w + 'px';
        el.style.maxHeight = maxH + 'px';
        let left = r.right - w;                       // راست‌چین با خودِ خانه
        left = Math.max(8, Math.min(left, vw - w - 8));
        el.style.left = left + 'px';
        el.classList.toggle('up', up);
        if (up) { el.style.top = ''; el.style.bottom = (vh - r.top + 6) + 'px'; } else { el.style.bottom = ''; el.style.top = (r.bottom + 6) + 'px'; }
    }
    function open(sel) {
        close();
        css();
        const el = document.createElement('div'); el.className = 'uis'; el.setAttribute('role', 'listbox');
        const opts = Array.from(sel.options);
        const many = opts.length > 8;
        el.innerHTML = (many ? '<input class="uis-s" type="text" autocomplete="off" spellcheck="false" placeholder="جستجو…">' : '') + '<div class="uis-l"></div>';
        const list = el.querySelector('.uis-l');
        const items = [];
        let lastGroup = null, html = '';
        opts.forEach((o, idx) => {
            const g = o.parentElement && o.parentElement.tagName === 'OPTGROUP' ? o.parentElement : null;
            if (g !== lastGroup) { lastGroup = g; if (g) html += `<div class="uis-g" data-g="${items.length}">${esc(g.label)}</div>`; }
            if (o.hidden) return;
            const dis = o.disabled || (g && g.disabled);
            const ph = o.value === '' && idx === 0;
            html += `<div class="uis-o${o.selected ? ' on' : ''}${dis ? ' dis' : ''}${ph ? ' ph' : ''}" role="option" data-i="${items.length}"${o.dir ? ` dir="${esc(o.dir)}"` : ''}>${esc(o.label || o.text || ' ')}</div>`;
            items.push({ idx, dis, text: norm((o.label || o.text) + ' ' + o.value), group: g });
        });
        list.innerHTML = html;
        items.forEach((it, i) => { it.node = list.querySelector(`[data-i="${i}"]`); });
        document.body.appendChild(el);
        cur = { sel, el, list, items, hi: -1 };
        cur.onScroll = e => { if (cur && !el.contains(e.target)) close(); };
        cur.onResize = () => close();
        window.addEventListener('scroll', cur.onScroll, true);
        window.addEventListener('resize', cur.onResize);
        sel.classList.add('uis-open');
        place();
        const onIdx = items.findIndex(x => x.idx === sel.selectedIndex);
        if (onIdx >= 0) highlight(onIdx, true);
        list.addEventListener('mousemove', e => { const n = e.target.closest('.uis-o'); if (n && +n.dataset.i !== cur.hi && !n.classList.contains('dis')) highlight(+n.dataset.i, false); });
        const s = el.querySelector('.uis-s');
        if (s) {
            s.addEventListener('input', () => {
                const q = norm(s.value);
                items.forEach(it => { it.hide = q !== '' && it.text.indexOf(q) < 0; it.node.style.display = it.hide ? 'none' : ''; });
                list.querySelectorAll('.uis-g').forEach(gn => {
                    let n = gn.nextElementSibling, any = false;
                    while (n && !n.classList.contains('uis-g')) { if (n.style.display !== 'none') any = true; n = n.nextElementSibling; }
                    gn.style.display = any ? '' : 'none';
                });
                let none = list.querySelector('.uis-n');
                if (items.every(it => it.hide)) { if (!none) list.insertAdjacentHTML('beforeend', '<div class="uis-n">موردی پیدا نشد</div>'); }
                else if (none) none.remove();
                cur.hi = -1; move(1);
            });
            setTimeout(() => s.focus({ preventScroll: true }), 0);
        }
    }
    function key(e) {
        if (!cur) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Home' && e.target.tagName !== 'INPUT') { e.preventDefault(); cur.hi = -1; move(1); }
        else if (e.key === 'Enter') { e.preventDefault(); if (cur.hi >= 0) choose(cur.hi); }
        else if (e.key === 'Escape' || e.key === 'Tab') { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); } close(e.key === 'Escape'); }
    }

    // کلیک داخلِ فهرست همین‌جا (روی window و پیش از بقیه) مصرف می‌شود: پنجره‌هایی که با «کلیکِ بیرون» بسته می‌شوند
    // (جعبه‌ابزار، پاپ‌آپ‌ها، منوها) فهرست را بیرونِ خودشان حساب نکنند و بسته نشوند
    ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click', 'touchstart'].forEach(t => window.addEventListener(t, e => {
        if (!cur || !cur.el.contains(e.target)) return;
        e.stopPropagation();
        const onSearch = e.target.classList && e.target.classList.contains('uis-s');
        if ((t === 'mousedown' || t === 'pointerdown') && !onSearch) e.preventDefault();   // فوکوس از select/جستجو نرود
        if (t === 'click') { const n = e.target.closest('.uis-o'); if (n) choose(+n.dataset.i); }
    }, true));

    // باز کردن با کلیک (جلوی فهرستِ خودِ مرورگر گرفته می‌شود) و با کیبورد (Enter / Space / Alt+↓)
    window.addEventListener('mousedown', e => {
        if (cur && !cur.el.contains(e.target) && e.target !== cur.sel) close();
        const sel = e.target.closest && e.target.closest('select');
        if (!sel || e.button !== 0 || skip(sel) || !fine()) return;
        e.preventDefault();
        if (cur && cur.sel === sel) { close(true); return; }
        try { sel.focus({ preventScroll: true }); } catch (x) {}
        open(sel);
    }, true);
    window.addEventListener('keydown', e => {
        if (cur && (e.target === cur.sel || cur.el.contains(e.target))) { key(e); return; }
        const sel = e.target;
        if (!sel || sel.tagName !== 'SELECT' || skip(sel) || !fine()) return;
        if (e.key === 'Enter' || e.key === ' ' || (e.altKey && e.key === 'ArrowDown') || e.key === 'F4') { e.preventDefault(); open(sel); }
    }, true);
    // اگر خانه از صفحه برداشته شد یا پنجره‌ی دیگری باز شد
    document.addEventListener('focusin', e => { if (cur && e.target !== cur.sel && !cur.el.contains(e.target)) close(); });
    window.addEventListener('blur', () => close());

    window.UISelect = { open, close };
})();
