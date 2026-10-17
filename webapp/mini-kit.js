// فایل: webapp/mini-kit.js
// ابزارهای مشترکِ صفحه‌های مینی‌اپ (تمِ تیره):
//   MiniKit.dateField(input, opts)  تقویمِ شمسیِ زیبا؛ خانه‌ی تاریخ فقط از روی تقویم پر می‌شود (تایپِ دستی بسته است)
//   MiniKit.pick(opts)              فهرستِ انتخابیِ قابلِ جستجو و پیمایش (bottom sheet)
//   MiniKit.closedScreen(el, info)  صفحه‌ی متحرکِ «ساعتِ کاریِ ثبتِ درخواست تمام شده»
//   MiniKit.jToday() / jAddDays / jFmt / jParse / g2j / j2g
(function () {
    const FA = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const EN = s => String(s ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WD = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    // ---------------- تقویم ----------------
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
    const leap = jy => { const r = (jy - (jy > 0 ? 474 : 473)) % 2820 + 474; return ((r + 38) * 682) % 2816 < 682; };
    const mdays = (jy, jm) => jm <= 6 ? 31 : jm <= 11 ? 30 : (leap(jy) ? 30 : 29);
    function jToday() {
        // ساعتِ ایران بر اساسِ سرور (iran-time.js) اگر بارگذاری شده باشد
        if (window.IrTime && IrTime.parts) { const p = IrTime.parts(); if (p.jy) return [p.jy, p.jm, p.jd]; }
        const n = new Date(); return g2j(n.getFullYear(), n.getMonth() + 1, n.getDate());
    }
    function jAddDays(j, n) {
        const [gy, gm, gd] = j2g(j[0], j[1], j[2]);
        const d = new Date(Date.UTC(gy, gm - 1, gd) + n * 86400000);
        return g2j(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
    }
    function jAddYears(j, n) { const y = j[0] + n; return [y, j[1], Math.min(j[2], mdays(y, j[1]))]; }
    const jFmt = j => j ? `${j[0]}/${String(j[1]).padStart(2, '0')}/${String(j[2]).padStart(2, '0')}` : '';
    function jParse(s) { const m = EN(s).match(/^(\d{4})\D(\d{1,2})\D(\d{1,2})$/); return m ? [+m[1], +m[2], +m[3]] : null; }
    const jCmp = (a, b) => (a[0] - b[0]) || (a[1] - b[1]) || (a[2] - b[2]);
    // روزِ هفته (۰ = شنبه)
    function jWeekday(j) { const [gy, gm, gd] = j2g(j[0], j[1], j[2]); return (new Date(Date.UTC(gy, gm - 1, gd)).getUTCDay() + 1) % 7; }

    let cssDone = false;
    function css() {
        if (cssDone) return; cssDone = true;
        const st = document.createElement('style');
        st.textContent = `
.mk-ov{position:fixed;inset:0;z-index:2147482000;background:rgba(2,6,23,.66);backdrop-filter:blur(3px);display:flex;align-items:flex-end;justify-content:center;opacity:0;transition:opacity .2s;font-family:'Vazir',Tahoma,sans-serif;direction:rtl}
.mk-ov.on{opacity:1}
.mk-sheet{width:100%;max-width:480px;background:linear-gradient(170deg,#262262,#111633 60%,#0b1024);border:1px solid rgba(255,255,255,.12);border-bottom:0;border-radius:26px 26px 0 0;padding:14px 16px calc(16px + env(safe-area-inset-bottom,0px));color:#f1f5f9;transform:translateY(40px);transition:transform .25s cubic-bezier(.2,.9,.3,1.2);max-height:88vh;display:flex;flex-direction:column;box-shadow:0 -20px 50px rgba(0,0,0,.45)}
.mk-ov.on .mk-sheet{transform:none}
.mk-grab{width:44px;height:5px;border-radius:9px;background:rgba(255,255,255,.22);margin:0 auto 10px}
.mk-title{font-weight:900;font-size:14.5px;text-align:center;margin-bottom:10px}
.mk-cal-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
.mk-nav{width:40px;height:40px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.mk-nav:disabled{opacity:.25}
.mk-chips{display:flex;gap:6px}
.mk-chip{border:1px solid rgba(165,180,252,.35);background:rgba(99,102,241,.16);color:#e0e7ff;border-radius:12px;padding:8px 14px;font-weight:900;font-size:14px;cursor:pointer;font-family:inherit}
.mk-wd{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:4px}
.mk-wd span{text-align:center;font-size:11px;color:#94a3b8;font-weight:800}
.mk-days{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
.mk-day{aspect-ratio:1/1;border-radius:14px;border:0;background:rgba(255,255,255,.04);color:#e2e8f0;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;transition:transform .1s,background .15s}
.mk-day:active{transform:scale(.9)}
.mk-day.fri{color:#fca5a5}
.mk-day.today{box-shadow:inset 0 0 0 2px rgba(52,211,153,.8)}
.mk-day.sel{background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;box-shadow:0 6px 16px rgba(99,102,241,.45)}
.mk-day:disabled{opacity:.18;cursor:default}
.mk-day.empty{visibility:hidden}
.mk-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;overflow-y:auto;max-height:46vh;padding:2px}
.mk-grid.y{grid-template-columns:repeat(4,1fr)}
.mk-cell{border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);color:#e2e8f0;border-radius:14px;padding:12px 4px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit}
.mk-cell.sel{background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;border-color:transparent}
.mk-cell:disabled{opacity:.25}
.mk-foot{display:flex;gap:8px;margin-top:12px}
.mk-btn{flex:1;border:0;border-radius:15px;padding:13px 10px;font-weight:900;font-size:13.5px;cursor:pointer;font-family:inherit}
.mk-ok{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;box-shadow:0 8px 22px rgba(99,102,241,.35)}
.mk-ghost{background:rgba(255,255,255,.08);color:#e2e8f0;border:1px solid rgba(255,255,255,.14)}
.mk-preview{text-align:center;font-size:12.5px;color:#c7d2fe;margin-top:10px;min-height:20px}
.mk-date{cursor:pointer;caret-color:transparent;background-image:linear-gradient(transparent,transparent)!important}
.mk-datebox{position:relative;display:flex;align-items:center}
.mk-datebox .mk-date{padding-left:44px!important;text-align:center}
.mk-datebox .mk-cal-ic{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:18px;pointer-events:none}
/* فهرستِ انتخابی */
.mk-search{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:4px 12px;margin-bottom:8px}
.mk-search input{flex:1;background:transparent!important;border:0!important;color:#fff;font-size:14px;padding:10px 0!important;outline:none;font-family:inherit;width:auto!important}
.mk-list{overflow-y:auto;flex:1;min-height:120px;max-height:58vh;-webkit-overflow-scrolling:touch;padding:2px}
.mk-group{font-size:11px;color:#a5b4fc;font-weight:900;margin:10px 4px 6px}
.mk-item{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:right;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:#f1f5f9;border-radius:14px;padding:12px 14px;margin-bottom:6px;cursor:pointer;font-family:inherit;font-size:13.5px;font-weight:700}
.mk-item small{display:block;color:#94a3b8;font-size:10.5px;font-weight:500;margin-top:2px}
.mk-item:active{background:rgba(99,102,241,.22)}
.mk-item.sel{border-color:#818cf8;background:rgba(99,102,241,.18)}
.mk-empty{text-align:center;color:#64748b;font-size:12.5px;padding:24px 8px}
.mk-count{font-size:10.5px;color:#64748b;text-align:center;margin-top:6px}
/* صفحه‌ی خارج از ساعتِ کاری */
.mk-closed{display:flex;flex-direction:column;align-items:center;text-align:center;gap:12px;padding:10px 4px 20px}
.mk-clock{width:118px;height:118px;border-radius:50%;position:relative;background:radial-gradient(circle at 30% 25%,#4c1d95,#1e1b4b 70%);box-shadow:0 0 0 6px rgba(139,92,246,.18),0 18px 40px rgba(76,29,149,.5);animation:mkFloat 3.4s ease-in-out infinite}
.mk-clock:before{content:'';position:absolute;inset:10px;border-radius:50%;border:2px dashed rgba(196,181,253,.35);animation:mkSpin 18s linear infinite}
.mk-clock i{position:absolute;left:50%;bottom:50%;transform-origin:50% 100%;border-radius:4px;background:#e9d5ff}
.mk-clock i.h{width:5px;height:30px;margin-left:-2.5px;animation:mkSpin 24s linear infinite}
.mk-clock i.m{width:3px;height:42px;margin-left:-1.5px;background:#f0abfc;animation:mkSpin 3s linear infinite}
.mk-clock b{position:absolute;left:50%;top:50%;width:12px;height:12px;margin:-6px 0 0 -6px;border-radius:50%;background:#fff}
.mk-moon{position:absolute;top:-6px;right:-10px;font-size:30px;animation:mkPulse 2.4s ease-in-out infinite}
.mk-closed h2{font-size:17px;font-weight:900;margin:6px 0 0}
.mk-closed p{font-size:12.5px;color:#cbd5e1;line-height:2;margin:0;max-width:340px}
.mk-next{background:linear-gradient(135deg,rgba(16,185,129,.18),rgba(5,150,105,.12));border:1px solid rgba(52,211,153,.4);border-radius:16px;padding:12px 14px;font-size:13px;color:#a7f3d0;font-weight:800;width:100%;max-width:360px}
.mk-week{width:100%;max-width:360px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:6px 12px;text-align:right}
.mk-week div{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:12px}
.mk-week div:last-child{border-bottom:0}
.mk-week div.today{color:#c4b5fd;font-weight:900}
.mk-week span:last-child{direction:rtl;color:#e2e8f0}
.mk-week .off{color:#fca5a5!important}
.mk-stages{display:flex;align-items:flex-start;gap:0;margin:12px 0 4px;direction:rtl}
.mk-st{flex:1;display:flex;flex-direction:column;align-items:center;text-align:center;position:relative;min-width:0}
.mk-st:not(:last-child)::after{content:'';position:absolute;top:17px;left:-50%;width:100%;height:4px;border-radius:4px;background:rgba(255,255,255,.12);z-index:0}
.mk-st.done:not(:last-child)::after{background:var(--mk-c)}
.mk-st i{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-style:normal;font-size:16px;font-weight:900;background:#1c1d4a;border:2px solid rgba(255,255,255,.18);color:#94a3b8;position:relative;z-index:1}
.mk-st.done i{background:var(--mk-c);border-color:var(--mk-c);color:#0b1024}
.mk-st.cur i{border-color:var(--mk-c);color:var(--mk-c);box-shadow:0 0 0 0 var(--mk-c);animation:mkRing 1.6s infinite}
.mk-st span{font-size:10.5px;line-height:1.6;margin-top:6px;color:#94a3b8;font-weight:700;padding:0 2px}
.mk-st.done span,.mk-st.cur span{color:#f1f5f9}
.mk-st.g{--mk-c:#86efac}.mk-st.b{--mk-c:#60a5fa}
.mk-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 12px;font-size:11.5px;font-weight:800;border:1px solid}
.mk-pill.awaiting{background:rgba(245,158,11,.14);border-color:rgba(245,158,11,.5);color:#fcd34d}
.mk-pill.needs_fix{background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.5);color:#fca5a5}
.mk-pill.received{background:rgba(134,239,172,.14);border-color:rgba(134,239,172,.5);color:#bbf7d0}
.mk-pill.approved,.mk-pill.issued{background:rgba(96,165,250,.15);border-color:rgba(96,165,250,.55);color:#bfdbfe}
@keyframes mkRing{0%{box-shadow:0 0 0 0 rgba(255,255,255,.35)}70%{box-shadow:0 0 0 9px rgba(255,255,255,0)}100%{box-shadow:0 0 0 0 rgba(255,255,255,0)}}
@keyframes mkSpin{to{transform:rotate(360deg)}}
@keyframes mkFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes mkPulse{0%,100%{transform:scale(1);opacity:.9}50%{transform:scale(1.15);opacity:1}}
`;
        document.head.appendChild(st);
    }

    function sheet(title, inner) {
        css();
        const ov = document.createElement('div');
        ov.className = 'mk-ov';
        ov.innerHTML = `<div class="mk-sheet"><div class="mk-grab"></div>${title ? `<div class="mk-title">${title}</div>` : ''}${inner}</div>`;
        document.body.appendChild(ov);
        requestAnimationFrame(() => ov.classList.add('on'));
        const close = () => { ov.classList.remove('on'); setTimeout(() => ov.remove(), 220); };
        ov.addEventListener('click', e => { if (e.target === ov) close(); });
        return { ov, box: ov.querySelector('.mk-sheet'), close };
    }

    // تقویم: opts = {title, value:[y,m,d]|null, min:[y,m,d], max:[y,m,d], startView:'day'|'year'} → Promise<[y,m,d]|null>
    function calendar(opts = {}) {
        return new Promise(resolve => {
            const today = jToday();
            let sel = opts.value || null;
            let vy = (sel || opts.focus || today)[0], vm = (sel || opts.focus || today)[1];
            let view = opts.startView || 'day';
            const min = opts.min || null, max = opts.max || null;
            const out = d => (min && jCmp(d, min) < 0) || (max && jCmp(d, max) > 0);
            const S = sheet(opts.title || '📅 انتخاب تاریخ', `<div class="mk-body"></div><div class="mk-preview"></div>
                <div class="mk-foot"><button class="mk-btn mk-ok" data-ok>✅ تایید</button><button class="mk-btn mk-ghost" data-today style="flex:0 0 92px">امروز</button><button class="mk-btn mk-ghost" data-x style="flex:0 0 84px">انصراف</button></div>`);
            const body = S.box.querySelector('.mk-body'), prev = S.box.querySelector('.mk-preview');
            let done = false;
            const finish = v => { if (done) return; done = true; S.close(); resolve(v); };
            S.ov.addEventListener('click', e => { if (e.target === S.ov) finish(null); });
            const showPrev = () => { prev.textContent = sel ? `${FA(sel[2])} ${MONTHS[sel[1] - 1]} ${FA(sel[0])}` : 'روزی را انتخاب کنید'; };
            function render() {
                if (view === 'year') {
                    const lo = min ? min[0] : today[0] - 100, hi = max ? max[0] : today[0] + 2;
                    let h = '';
                    for (let y = hi; y >= lo; y--) h += `<button class="mk-cell ${y === vy ? 'sel' : ''}" data-y="${y}">${FA(y)}</button>`;
                    body.innerHTML = `<div class="mk-title" style="font-size:12px;color:#a5b4fc">سال را انتخاب کنید</div><div class="mk-grid y">${h}</div>`;
                    const s = body.querySelector('.mk-cell.sel'); if (s) s.scrollIntoView({ block: 'center' });
                    body.querySelectorAll('[data-y]').forEach(b => b.onclick = () => { vy = +b.dataset.y; view = 'month'; render(); });
                    return;
                }
                if (view === 'month') {
                    body.innerHTML = `<div class="mk-title" style="font-size:12px;color:#a5b4fc">ماهِ سالِ ${FA(vy)}</div><div class="mk-grid">${MONTHS.map((m, i) => {
                        const dis = (min && (vy < min[0] || (vy === min[0] && i + 1 < min[1]))) || (max && (vy > max[0] || (vy === max[0] && i + 1 > max[1])));
                        return `<button class="mk-cell ${i + 1 === vm ? 'sel' : ''}" data-m="${i + 1}" ${dis ? 'disabled' : ''}>${m}</button>`; }).join('')}</div>`;
                    body.querySelectorAll('[data-m]').forEach(b => b.onclick = () => { vm = +b.dataset.m; view = 'day'; render(); });
                    return;
                }
                const first = jWeekday([vy, vm, 1]), n = mdays(vy, vm);
                let cells = '';
                for (let i = 0; i < first; i++) cells += '<button class="mk-day empty" disabled></button>';
                for (let d = 1; d <= n; d++) {
                    const j = [vy, vm, d], wd = (first + d - 1) % 7;
                    cells += `<button class="mk-day ${wd === 6 ? 'fri' : ''} ${jCmp(j, today) === 0 ? 'today' : ''} ${sel && jCmp(j, sel) === 0 ? 'sel' : ''}" data-d="${d}" ${out(j) ? 'disabled' : ''}>${FA(d)}</button>`;
                }
                const pm = vm === 1 ? [vy - 1, 12] : [vy, vm - 1], nm = vm === 12 ? [vy + 1, 1] : [vy, vm + 1];
                const pDis = min && jCmp([pm[0], pm[1], mdays(pm[0], pm[1])], min) < 0, nDis = max && jCmp([nm[0], nm[1], 1], max) > 0;
                body.innerHTML = `<div class="mk-cal-head"><button class="mk-nav" data-n="1" ${nDis ? 'disabled' : ''}>›</button>
                    <div class="mk-chips"><button class="mk-chip" data-v="month">${MONTHS[vm - 1]}</button><button class="mk-chip" data-v="year">${FA(vy)}</button></div>
                    <button class="mk-nav" data-n="-1" ${pDis ? 'disabled' : ''}>‹</button></div>
                    <div class="mk-wd">${WD.map(w => `<span>${w}</span>`).join('')}</div><div class="mk-days">${cells}</div>`;
                body.querySelectorAll('[data-n]').forEach(b => b.onclick = () => { const t = +b.dataset.n > 0 ? nm : pm; vy = t[0]; vm = t[1]; render(); });
                body.querySelectorAll('[data-v]').forEach(b => b.onclick = () => { view = b.dataset.v; render(); });
                body.querySelectorAll('[data-d]').forEach(b => b.onclick = () => { sel = [vy, vm, +b.dataset.d]; render(); showPrev(); });
            }
            S.box.querySelector('[data-ok]').onclick = () => { if (!sel) { prev.textContent = '⚠️ ابتدا روز را روی تقویم بزنید'; return; } finish(sel); };
            S.box.querySelector('[data-x]').onclick = () => finish(null);
            const tb = S.box.querySelector('[data-today]');
            if (out(today)) tb.style.display = 'none';
            tb.onclick = () => { sel = today; vy = today[0]; vm = today[1]; view = 'day'; render(); showPrev(); };
            render(); showPrev();
        });
    }

    // خانه‌ی تاریخ: فقط با تقویم پر می‌شود. opts: {title, min, max, startView, onChange, defaultValue}
    function dateField(input, opts = {}) {
        if (!input || input._mkDate) return input;
        css();
        input._mkDate = true;
        input.readOnly = true;
        input.setAttribute('inputmode', 'none');
        input.classList.add('mk-date');
        input.placeholder = input.placeholder && /\d|[۰-۹]/.test(input.placeholder) ? 'برای انتخاب بزنید' : (input.placeholder || 'برای انتخاب بزنید');
        if (!input.parentElement.classList.contains('mk-datebox')) {
            const wrap = document.createElement('div'); wrap.className = 'mk-datebox';
            input.parentElement.insertBefore(wrap, input); wrap.appendChild(input);
            const ic = document.createElement('span'); ic.className = 'mk-cal-ic'; ic.textContent = '📅'; wrap.appendChild(ic);
        }
        if (opts.defaultValue && !input.value) input.value = FA(jFmt(opts.defaultValue));
        const open = async e => {
            if (e) { e.preventDefault(); e.stopPropagation(); input.blur(); }
            const o = typeof opts === 'function' ? opts() : opts;
            const v = await calendar({ title: o.title, value: jParse(input.value), min: o.min, max: o.max, startView: jParse(input.value) ? 'day' : (o.startView || 'day'), focus: o.focus });
            if (!v) return;
            input.value = FA(jFmt(v));
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            if (o.onChange) o.onChange(v);
        };
        input.addEventListener('click', open);
        input.addEventListener('focus', open);
        input.addEventListener('keydown', e => { if (e.key !== 'Tab') e.preventDefault(); });
        input.addEventListener('paste', e => e.preventDefault());
        input._mkOpen = open;
        return input;
    }

    // فهرستِ انتخابی: opts = {title, items:[{v, label, sub, group}], value, placeholder, empty, onSearch:async(q)=>items, max}
    function pick(opts = {}) {
        return new Promise(resolve => {
            const S = sheet(opts.title || 'انتخاب کنید', `<div class="mk-search"><span>🔎</span><input type="text" placeholder="${esc(opts.placeholder || 'جستجو...')}" autocomplete="off"></div>
                <div class="mk-list"></div><div class="mk-count"></div><div class="mk-foot"><button class="mk-btn mk-ghost" data-x>بستن</button></div>`);
            const inp = S.box.querySelector('input'), list = S.box.querySelector('.mk-list'), cnt = S.box.querySelector('.mk-count');
            let done = false, items = opts.items || [], timer = null, seq = 0;
            const finish = v => { if (done) return; done = true; S.close(); resolve(v); };
            S.ov.addEventListener('click', e => { if (e.target === S.ov) finish(null); });
            S.box.querySelector('[data-x]').onclick = () => finish(null);
            const norm = s => EN(String(s || '')).replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\s‌\-_.()]/g, '').toLowerCase();
            function draw(arr, q) {
                const max = opts.max || 400;
                const shown = arr.slice(0, max);
                if (!shown.length) { list.innerHTML = `<div class="mk-empty">${esc(opts.empty || 'موردی پیدا نشد.')}</div>`; cnt.textContent = ''; return; }
                let g = null, h = '';
                shown.forEach((it, i) => {
                    if (it.group && it.group !== g) { g = it.group; h += `<div class="mk-group">${esc(it.group)}</div>`; }
                    h += `<button class="mk-item ${opts.value != null && String(opts.value) === String(it.v) ? 'sel' : ''}" data-i="${i}"><span>${esc(it.label)}${it.sub ? `<small>${esc(it.sub)}</small>` : ''}</span><span style="color:#818cf8">${it.icon || '›'}</span></button>`;
                });
                list.innerHTML = h;
                cnt.textContent = arr.length > max ? `${FA(max)} مورد از ${FA(arr.length)} نشان داده شده؛ برای پیدا کردنِ بقیه جستجو کنید` : `${FA(arr.length)} مورد`;
                list.querySelectorAll('[data-i]').forEach(b => b.onclick = () => finish(shown[+b.dataset.i]));
                const s = list.querySelector('.mk-item.sel'); if (s && !q) s.scrollIntoView({ block: 'center' });
            }
            async function run() {
                const q = inp.value.trim();
                if (opts.onSearch) {
                    const my = ++seq;
                    list.innerHTML = '<div class="mk-empty">در حال جستجو...</div>';
                    let arr = [];
                    try { arr = await opts.onSearch(q); } catch (e) {}
                    if (my === seq) draw(arr || [], q);
                    return;
                }
                const nq = norm(q);
                draw(nq ? items.filter(it => norm(it.label + ' ' + (it.sub || '') + ' ' + (it.group || '')).includes(nq)) : items, q);
            }
            inp.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, opts.onSearch ? 250 : 60); });
            run();
            if (opts.autofocus) setTimeout(() => inp.focus(), 250);
        });
    }

    // صفحه‌ی «خارج از ساعتِ کاری»: info = {message, week:[{day, hours, off, today}], next}
    function closedScreen(el, info, extraHtml) {
        css();
        el.innerHTML = `<div class="mk-closed">
            <div class="mk-clock"><span class="mk-moon">🌙</span><i class="h"></i><i class="m"></i><b></b></div>
            <h2>ساعتِ کاریِ ثبتِ درخواستِ آنلاین به پایان رسیده است</h2>
            <p>${esc(info.message || 'ثبتِ درخواستِ بیمه و بارگذاریِ مدارک فقط در ساعاتِ کاری امکان‌پذیر است. بقیه‌ی بخش‌ها (بیمه‌نامه‌های من، پلاک‌های من و ارتباط با کارشناس) فعال هستند.')}</p>
            ${info.next ? `<div class="mk-next">⏰ زمانِ بعدیِ ثبتِ درخواست: ${esc(info.next)}</div>` : ''}
            ${(info.week || []).length ? `<div class="mk-week">${info.week.map(w => `<div class="${w.today ? 'today' : ''}"><span>${esc(w.day)}${w.today ? ' (امروز)' : ''}</span><span class="${w.off ? 'off' : ''}">${esc(w.hours)}</span></div>`).join('')}</div>` : ''}
            ${extraHtml || ''}
        </div>`;
    }

    // مراحلِ پیگیریِ یک درخواست (۴ مرحله؛ دو مرحله‌ی آخر هم‌رنگ): stage = awaiting | needs_fix | received | approved | issued
    const STAGE_STEPS = ['مدارک دریافت شد', 'در انتظار تایید کارشناس', 'تایید شده در انتظار صدور', 'تایید شده، بیمه‌نامه صادر شد'];
    const STAGE_ICONS = ['📥', '🔎', '✅', '📄'];
    function stages(stage) {
        css();
        const at = { awaiting: -1, needs_fix: -1, received: 1, approved: 2, issued: 4 }[stage];
        const cur = at === undefined ? -1 : at;
        return `<div class="mk-stages">${STAGE_STEPS.map((t, i) => {
            const done = i < cur || (stage === 'received' && i === 0), now = i === cur;
            return `<div class="mk-st ${i < 2 ? 'g' : 'b'} ${done ? 'done' : ''} ${now ? 'cur' : ''}"><i>${done ? '✓' : STAGE_ICONS[i]}</i><span>${t}</span></div>`;
        }).join('')}</div>`;
    }
    const STAGE_FA = { awaiting: '🟡 در انتظار بارگزاری مدارک', needs_fix: '🔴 نیاز به اصلاحِ مدارک', received: '🟢 مدارک دریافت شد · در انتظار تایید کارشناس', approved: '🔵 تایید شده · در انتظار صدور', issued: '🔵 تایید شده · بیمه‌نامه صادر شد' };
    function stagePill(stage) { css(); return `<span class="mk-pill ${esc(stage)}">${STAGE_FA[stage] || ''}</span>`; }

    window.MiniKit = { calendar, dateField, pick, closedScreen, stages, stagePill, STAGE_FA, jToday, jAddDays, jAddYears, jFmt, jParse, jCmp, g2j, j2g, fa: FA, en: EN, esc, MONTHS };
})();
