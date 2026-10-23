// فایل: jdate-picker.js
// تقویمِ شمسیِ پنل (همان تقویمِ مینی‌اپ، با تمِ روشن) برای همه‌ی خانه‌های تاریخ.
// در پنل هم تایپِ دستی آزاد است و هم انتخاب از تقویم؛ با کلیک/فوکوس روی خانه، تقویم کنارش باز می‌شود
// و با تایپ، روزِ انتخاب‌شده همگام می‌شود. خانه‌ها خودکار شناخته می‌شوند:
//   - data-jdate، یا placeholder/مقداری به شکلِ ۱۴۰۵/۰۷/۳۰، یا برچسبِ «تاریخ/سررسید/انقضا/تولد» با مقدارِ تاریخ
//   - data-nojdate یعنی این خانه تقویم نگیرد.
// اگر مقدار ساعت هم دارد (۱۴۰۵/۰۷/۱۴ ۰۹:۰۰)، فقط بخشِ تاریخ عوض می‌شود.
(function () {
    if (window.JDatePick) return;
    const FA = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const EN = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WD = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    const WD_LONG = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    // ---------------- محاسبه‌ی تقویم (همان مینی‌اپ) ----------------
    function g2j(gy, gm, gd) {
        const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        let jy = gy <= 1600 ? 0 : 979; gy -= gy <= 1600 ? 621 : 1600;
        const gy2 = gm > 2 ? gy + 1 : gy;
        let days = 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
        jy += 33 * Math.floor(days / 12053); days %= 12053;
        jy += 4 * Math.floor(days / 1461); days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        const jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
        const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
        return [jy, jm, jd];
    }
    function j2g(jy, jm, jd) {
        jy += 1595;
        let days = -355668 + 365 * jy + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
        let gy = 400 * Math.floor(days / 146097); days %= 146097;
        if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
        gy += 4 * Math.floor(days / 1461); days %= 1461;
        if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        let gd = days + 1;
        const sal = [0, 31, (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        let gm = 0;
        for (gm = 1; gm <= 12 && gd > sal[gm]; gm++) gd -= sal[gm];
        return [gy, gm, gd];
    }
    // کبیسه با چرخه‌ی ۳۳ساله (۱۳۹۹، ۱۴۰۳، ۱۴۰۸، …)
    const leap = jy => [1, 5, 9, 13, 17, 22, 26, 30].indexOf(jy % 33) >= 0;
    const mdays = (jy, jm) => jm <= 6 ? 31 : jm <= 11 ? 30 : (leap(jy) ? 30 : 29);
    function jToday() {
        if (window.IrTime && IrTime.parts) { const p = IrTime.parts(); if (p && p.jy) return [p.jy, p.jm, p.jd]; }
        const n = new Date(); return g2j(n.getFullYear(), n.getMonth() + 1, n.getDate());
    }
    const jFmt = j => `${j[0]}/${String(j[1]).padStart(2, '0')}/${String(j[2]).padStart(2, '0')}`;
    const DATE_RE = /(1[34]\d{2})[\/.\-](\d{1,2})[\/.\-](\d{1,2})/;
    function jParse(s) {
        const m = EN(s).match(DATE_RE); if (!m) return null;
        const j = [+m[1], +m[2], +m[3]];
        return j[1] >= 1 && j[1] <= 12 && j[2] >= 1 && j[2] <= mdays(j[0], j[1]) ? j : null;
    }
    const jCmp = (a, b) => (a[0] - b[0]) || (a[1] - b[1]) || (a[2] - b[2]);
    function jWeekday(j) { const g = j2g(j[0], j[1], j[2]); return (new Date(Date.UTC(g[0], g[1] - 1, g[2])).getUTCDay() + 1) % 7; }

    // ---------------- استایل (تمِ روشنِ پنل) ----------------
    let cssDone = false;
    function css() {
        if (cssDone) return; cssDone = true;
        const st = document.createElement('style');
        st.textContent = `
.jdp-in{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236366f1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4.5' width='18' height='17' rx='3'/%3E%3Cpath d='M16 2.5v4M8 2.5v4M3 10h18'/%3E%3C/svg%3E")!important;background-repeat:no-repeat!important;background-position:left 8px center!important;background-size:15px 15px!important;padding-left:30px!important}
.jdp{position:fixed;z-index:2147481500;width:292px;background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 24px 60px -18px rgba(15,23,42,.35),0 4px 14px rgba(15,23,42,.08);padding:12px;font-family:'Vazir',Tahoma,sans-serif;direction:rtl;color:#0f172a;opacity:0;transform:translateY(-6px) scale(.98);transition:opacity .16s,transform .16s;user-select:none}
.jdp.on{opacity:1;transform:none}
.jdp.sheet{left:0!important;right:0!important;bottom:0!important;top:auto!important;width:auto;max-width:480px;margin:0 auto;border-radius:24px 24px 0 0;padding:14px 16px calc(16px + env(safe-area-inset-bottom,0px));transform:translateY(30px)}
.jdp.sheet.on{transform:none}
.jdp-ov{position:fixed;inset:0;z-index:2147481400;background:rgba(15,23,42,.45);backdrop-filter:blur(2px)}
.jdp-grab{width:44px;height:5px;border-radius:9px;background:#cbd5e1;margin:0 auto 10px;display:none}
.jdp.sheet .jdp-grab{display:block}
.jdp-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px}
.jdp-nav{width:34px;height:34px;border-radius:12px;border:1px solid #e2e8f0;background:#f8fafc;color:#334155;font-size:17px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-family:inherit}
.jdp-nav:hover{background:#eef2ff;border-color:#c7d2fe;color:#4338ca}
.jdp-chips{display:flex;gap:6px}
.jdp-chip{border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3;border-radius:11px;padding:6px 12px;font-weight:900;font-size:13px;cursor:pointer;font-family:inherit}
.jdp-chip:hover{background:#e0e7ff}
.jdp-wd{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;margin-bottom:3px}
.jdp-wd span{text-align:center;font-size:10.5px;color:#94a3b8;font-weight:800}
.jdp-days{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.jdp-day{aspect-ratio:1/1;border-radius:11px;border:0;background:#f8fafc;color:#1e293b;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .12s,transform .1s;padding:0}
.jdp-day:hover{background:#e0e7ff}
.jdp-day:active{transform:scale(.92)}
.jdp-day.fri{color:#e11d48}
.jdp-day.today{box-shadow:inset 0 0 0 2px #10b981}
.jdp-day.sel{background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;box-shadow:0 6px 14px rgba(99,102,241,.35)}
.jdp-day.empty{visibility:hidden}
.jdp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;overflow-y:auto;max-height:236px;padding:2px}
.jdp-grid.y{grid-template-columns:repeat(4,1fr)}
.jdp-cell{border:1px solid #e2e8f0;background:#f8fafc;color:#1e293b;border-radius:12px;padding:10px 4px;font-weight:800;font-size:12.5px;cursor:pointer;font-family:inherit}
.jdp-cell:hover{background:#eef2ff;border-color:#c7d2fe}
.jdp-cell.sel{background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;border-color:transparent}
.jdp-foot{display:flex;gap:6px;margin-top:10px}
.jdp-btn{flex:1;border:1px solid #e2e8f0;background:#f8fafc;color:#334155;border-radius:12px;padding:8px 6px;font-weight:900;font-size:12px;cursor:pointer;font-family:inherit}
.jdp-btn:hover{background:#f1f5f9}
.jdp-btn.ok{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-color:transparent}
.jdp-prev{text-align:center;font-size:11.5px;color:#4f46e5;font-weight:800;margin-top:8px;min-height:16px}
html.dark .jdp,body.dark .jdp{background:#0f172a;border-color:#334155;color:#e2e8f0}
html.dark .jdp-day,html.dark .jdp-cell,html.dark .jdp-nav,html.dark .jdp-btn{background:#1e293b;color:#e2e8f0;border-color:#334155}
`;
        document.head.appendChild(st);
    }

    // ---------------- تشخیصِ خانه‌ی تاریخ ----------------
    const LABEL_RE = /تاریخ|سررسید|انقضا|تولد/;
    const SHORT_RE = /^\*?\s*(شروع|پایان|صدور|شروعِ?\s*بیمه|پایانِ?\s*بیمه)\s*\*?$/;
    const RANGE_RE = /^(صدور|انقضا|انقضای\s*\S+|شروع|پایان|ثبت|پرداخت|سررسید|دریافت)\s*(از|تا)(\s|$)|^(از|تا)\s*تاریخ/;
    const NOT_DATE_RE = /شماره|کد|روز|مبلغ|ساعت|تعداد|ماه|جستجو|توضیح|مثلاً|نام/;
    function labelText(el) {
        let t = '';
        const lb = el.closest('label'); if (lb) t += ' ' + lb.textContent;
        if (el.id) { try { const f = document.querySelector('label[for="' + el.id + '"]'); if (f) t += ' ' + f.textContent; } catch (e) { /* id نامعتبر */ } }
        const nx = el.nextElementSibling, pv = el.previousElementSibling;
        if (nx && nx.tagName === 'LABEL') t += ' ' + nx.textContent;
        if (pv && pv.tagName === 'LABEL') t += ' ' + pv.textContent;
        return t;
    }
    function isDateInput(el) {
        if (!el || el.tagName !== 'INPUT' || el.hasAttribute('data-nojdate')) return false;
        const ty = (el.getAttribute('type') || 'text').toLowerCase();
        if (ty !== 'text' && ty !== 'tel') return false;
        if (el.hasAttribute('data-jdate')) return true;
        if (el.readOnly || el.disabled || el.classList.contains('mk-date')) return false;
        const ph = el.getAttribute('placeholder') || '';
        if (DATE_RE.test(EN(ph)) && !/شماره/.test(ph)) return true;
        // جفتِ «تا» کنارِ خانه‌ی «از تاریخ» (placeholder بدونِ نمونه، مثلاً «تا تاریخ» یا «صدور تا»)
        const pv = el.previousElementSibling;
        if (/(^|\s)تا(\s|$)/.test(ph) && pv && pv.tagName === 'INPUT' && DATE_RE.test(EN(pv.getAttribute('placeholder') || ''))) return true;
        // بازه‌ها: «صدور از / انقضا تا / شروع از / از تاریخ …» حتی بدونِ نمونه‌ی تاریخ
        if (RANGE_RE.test(ph.trim()) && !NOT_DATE_RE.test(ph)) return true;
        const v = EN(el.value || '').trim();
        const isDateVal = /^1[34]\d{2}[\/.\-]\d{1,2}[\/.\-]\d{1,2}(\s+\d{1,2}:\d{2})?$/.test(v);
        const lt = labelText(el).replace(/\s+/g, ' ').trim();
        if (LABEL_RE.test(lt + ' ' + ph) && isDateVal) return true;
        // خانه‌ی خالی با برچسبِ کوتاهِ تاریخ (مثلاً «تاریخ صدور»، «شروع»، «پایان»، «انقضای بیمه‌نامه‌ی قبلی»)
        const short = lt.length <= 60 ? lt : '';
        return v === '' && !NOT_DATE_RE.test(short + ' ' + ph) && (LABEL_RE.test(short + ' ' + ph) || SHORT_RE.test(short));
    }
    function decorate(el) {
        if (el._jdp !== undefined) return el._jdp;
        el._jdp = isDateInput(el);
        if (el._jdp) { css(); el.classList.add('jdp-in'); el.setAttribute('autocomplete', 'off'); }
        return el._jdp;
    }
    let scanT = 0;
    function scan() {
        scanT = 0;
        const list = document.querySelectorAll('input:not(.jdp-in)');
        for (let i = 0; i < list.length; i++) if (list[i]._jdp === undefined) decorate(list[i]);
    }
    const queueScan = () => { if (!scanT) scanT = setTimeout(scan, 200); };

    // ---------------- پنجره‌ی تقویم ----------------
    let P = null; // {el, box, ov, vy, vm, view, sel}
    const narrow = () => window.innerWidth < 560;
    function close() {
        if (!P) return;
        const p = P; P = null;
        p.box.classList.remove('on'); if (p.ov) p.ov.remove();
        setTimeout(() => p.box.remove(), 170);
    }
    function position() {
        if (!P || P.box.classList.contains('sheet')) return;
        const r = P.el.getBoundingClientRect(), b = P.box, h = b.offsetHeight || 340, w = b.offsetWidth || 292;
        if (r.bottom < 0 || r.top > window.innerHeight) { close(); return; }
        let top = r.bottom + 6;
        if (top + h > window.innerHeight - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
        let left = r.right - w;
        left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
        b.style.top = Math.max(8, top) + 'px'; b.style.left = left + 'px';
    }
    function write(el, j) {
        const old = el.value || '', useFa = /[۰-۹]/.test(old) || (!/\d/.test(old) && !/\d/.test(el.getAttribute('placeholder') || '')) || /[۰-۹]/.test(el.getAttribute('placeholder') || '');
        let s = jFmt(j);
        const tm = EN(old).match(/\s+(\d{1,2}:\d{2})\s*$/);
        if (tm) s += ' ' + tm[1];
        el.value = useFa ? FA(s) : s;
        el.dispatchEvent(new Event('input', {bubbles: true}));
        el.dispatchEvent(new Event('change', {bubbles: true}));
    }
    function render() {
        if (!P) return;
        const today = jToday(), sel = P.sel, b = P.box.querySelector('.jdp-body');
        if (P.view === 'year') {
            let h = '';
            for (let y = today[0] + 15; y >= 1300; y--) h += `<button type="button" class="jdp-cell ${y === P.vy ? 'sel' : ''}" data-y="${y}">${FA(y)}</button>`;
            b.innerHTML = `<div class="jdp-grid y">${h}</div>`;
            const g = b.firstChild, s = b.querySelector('.sel'); if (s) g.scrollTop = s.offsetTop - g.offsetTop - g.clientHeight / 2 + s.offsetHeight / 2;
        } else if (P.view === 'month') {
            b.innerHTML = `<div class="jdp-head"><span></span><div class="jdp-chips"><button type="button" class="jdp-chip" data-v="year">${FA(P.vy)}</button></div><span></span></div>`
                + `<div class="jdp-grid">${MONTHS.map((m, i) => `<button type="button" class="jdp-cell ${i + 1 === P.vm ? 'sel' : ''}" data-m="${i + 1}">${m}</button>`).join('')}</div>`;
        } else {
            const first = jWeekday([P.vy, P.vm, 1]), n = mdays(P.vy, P.vm);
            let cells = '';
            for (let i = 0; i < first; i++) cells += '<button type="button" class="jdp-day empty" tabindex="-1"></button>';
            for (let d = 1; d <= n; d++) {
                const j = [P.vy, P.vm, d], wd = (first + d - 1) % 7;
                cells += `<button type="button" class="jdp-day ${wd === 6 ? 'fri' : ''} ${jCmp(j, today) === 0 ? 'today' : ''} ${sel && jCmp(j, sel) === 0 ? 'sel' : ''}" data-d="${d}">${FA(d)}</button>`;
            }
            b.innerHTML = `<div class="jdp-head"><button type="button" class="jdp-nav" data-n="-1" title="ماهِ قبل">›</button>
                <div class="jdp-chips"><button type="button" class="jdp-chip" data-v="month">${MONTHS[P.vm - 1]}</button><button type="button" class="jdp-chip" data-v="year">${FA(P.vy)}</button></div>
                <button type="button" class="jdp-nav" data-n="1" title="ماهِ بعد">‹</button></div>
                <div class="jdp-wd">${WD.map(w => `<span>${w}</span>`).join('')}</div><div class="jdp-days">${cells}</div>`;
        }
        P.box.querySelector('.jdp-prev').textContent = sel ? `${WD_LONG[jWeekday(sel)]} ${FA(sel[2])} ${MONTHS[sel[1] - 1]} ${FA(sel[0])}` : '';
        position();
    }
    function open(el) {
        if (P && P.el === el) return;
        close();
        css();
        const sel = jParse(el.value), base = sel || jToday();
        const box = document.createElement('div');
        box.className = 'jdp' + (narrow() ? ' sheet' : '');
        box.innerHTML = '<div class="jdp-grab"></div><div class="jdp-body"></div><div class="jdp-prev"></div>'
            + '<div class="jdp-foot"><button type="button" class="jdp-btn" data-today>امروز</button><button type="button" class="jdp-btn" data-clear>پاک کردن</button><button type="button" class="jdp-btn ok" data-close>بستن</button></div>';
        let ov = null;
        if (narrow()) { ov = document.createElement('div'); ov.className = 'jdp-ov'; ov.addEventListener('mousedown', close); document.body.appendChild(ov); }
        document.body.appendChild(box);
        P = {el, box, ov, vy: base[0], vm: base[1], view: 'day', sel};
        // فوکوس روی خانه بماند تا تایپِ دستی هم ممکن باشد
        box.addEventListener('mousedown', e => { if (!narrow()) e.preventDefault(); });
        box.addEventListener('click', e => {
            const t = e.target.closest('button'); if (!t || !P) return;
            e.preventDefault();
            if (t.dataset.n) { P.vm += +t.dataset.n; if (P.vm < 1) { P.vm = 12; P.vy--; } if (P.vm > 12) { P.vm = 1; P.vy++; } render(); return; }
            if (t.dataset.v) { P.view = t.dataset.v; render(); return; }
            if (t.dataset.y) { P.vy = +t.dataset.y; P.view = 'month'; render(); return; }
            if (t.dataset.m) { P.vm = +t.dataset.m; P.view = 'day'; render(); return; }
            if (t.dataset.d) { const j = [P.vy, P.vm, +t.dataset.d]; const f = P.el; write(f, j); close(); return; }
            if (t.hasAttribute('data-today')) { const f = P.el; write(f, jToday()); close(); return; }
            if (t.hasAttribute('data-clear')) { const f = P.el; f.value = ''; f.dispatchEvent(new Event('input', {bubbles: true})); f.dispatchEvent(new Event('change', {bubbles: true})); close(); return; }
            if (t.hasAttribute('data-close')) close();
        });
        render();
        requestAnimationFrame(() => box.classList.add('on'));
    }
    // تایپِ دستی: تقویم با مقدارِ تایپ‌شده همگام می‌شود
    function syncTyped(el) {
        if (!P || P.el !== el) return;
        const j = jParse(el.value);
        P.sel = j;
        if (j) { P.vy = j[0]; P.vm = j[1]; P.view = 'day'; }
        render();
    }

    // دسکتاپ: با فوکوس باز می‌شود. موبایل: با زدنِ آیکونِ تقویم (لبه‌ی چپِ خانه) تا کیبورد برای تایپِ دستی هم بماند
    document.addEventListener('focusin', e => { const el = e.target; if (el && el.tagName === 'INPUT' && decorate(el) && !narrow()) open(el); }, true);
    document.addEventListener('click', e => {
        const el = e.target; if (!el || el.tagName !== 'INPUT' || !decorate(el) || (P && P.el === el)) return;
        if (narrow()) { if (e.clientX - el.getBoundingClientRect().left > 38) return; el.blur(); }
        open(el);
    }, true);
    document.addEventListener('input', e => { if (P && e.target === P.el) syncTyped(e.target); }, true);
    document.addEventListener('keydown', e => {
        if (!P) return;
        if (e.key === 'Escape' || (e.key === 'Tab' && e.target === P.el)) close();
    }, true);
    document.addEventListener('mousedown', e => { if (P && !P.box.contains(e.target) && e.target !== P.el) close(); }, true);
    document.addEventListener('focusout', e => { if (P && e.target === P.el && !narrow()) setTimeout(() => { if (P && document.activeElement !== P.el && !P.box.contains(document.activeElement)) close(); }, 120); }, true);
    window.addEventListener('scroll', () => position(), true);
    window.addEventListener('resize', () => { if (P && narrow() !== P.box.classList.contains('sheet')) close(); else position(); });

    function start() {
        scan();
        try { new MutationObserver(queueScan).observe(document.body, {childList: true, subtree: true}); } catch (e) { /* مرورگرِ قدیمی: با فوکوس تشخیص داده می‌شود */ }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

    window.JDatePick = {open, close, attach: el => { if (el) { el.setAttribute('data-jdate', ''); el._jdp = undefined; decorate(el); } return el; }, g2j, j2g, jParse, jFmt, jToday};
})();
