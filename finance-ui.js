// فایل: finance-ui.js
// داشبورد تحلیلی مالی (پرسنلی + شرکتی) با نمودارهای SVG و فیلترهای کامل، به‌علاوه‌ی ابزارهای کمکی
// صفحه‌ی صورتحساب (انتخاب و ترتیب ستون‌ها). بدون کتابخانه‌ی بیرونی تا روی هاست و شبکه‌ی داخلی هم کار کند.
(function () {
    const API = 'api/finance_actions.php';
    const fa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // مبالغ در دیتابیس ریال است؛ در داشبورد به «تومان» نمایش داده می‌شود (ریال ÷ ۱۰)
    const toToman = v => Math.round((+v || 0) / 10);
    const num = v => fa(toToman(v).toLocaleString('en-US'));
    // مبلغ کوتاه به تومان: ۳۳۲٬۰۰۰٬۰۰۰ ریال ← «۳۳.۲۰۰ میلیون»
    const short = v => {
        v = toToman(v); const a = Math.abs(v);
        if (a >= 1e9) return fa((v / 1e9).toFixed(3)) + ' میلیارد';
        if (a >= 1e6) return fa((v / 1e6).toFixed(3)) + ' میلیون';
        if (a >= 1e3) return fa(Math.round(v / 1e3)) + ' هزار';
        return fa(v);
    };
    // محورِ نمودار: کوتاه‌تر
    const axis = v => {
        v = toToman(v); const a = Math.abs(v);
        if (a >= 1e9) return fa(+(v / 1e9).toFixed(2)) + ' میلیارد';
        if (a >= 1e6) return fa(+(v / 1e6).toFixed(1)) + ' م';
        if (a >= 1e3) return fa(Math.round(v / 1e3)) + ' هزار';
        return fa(v);
    };
    const UNIT = 'تومان';
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const perLabel = k => { const [y, m] = String(k).split(/[-\/]/); return (MONTHS[(+m) - 1] || m) + ' ' + fa(String(y).slice(-2)); };
    const COLORS = { p: '#6366f1', c: '#06b6d4', paid: '#10b981', due: '#94a3b8', rem: '#f59e0b', over: '#ef4444', settled: '#3b82f6', partial: '#f59e0b', open: '#cbd5e1' };

    // ---------------- راهنمای شناور (tooltip) ----------------
    let tip;
    function showTip(e, html) {
        if (!tip) { tip = document.createElement('div'); tip.className = 'fixed z-[9999] pointer-events-none bg-slate-900/95 text-white text-[11px] rounded-xl px-3 py-2 shadow-2xl leading-6'; document.body.appendChild(tip); }
        tip.innerHTML = html; tip.style.display = 'block';
        const x = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 8);
        tip.style.left = x + 'px'; tip.style.top = (e.clientY + 14) + 'px';
    }
    function hideTip() { if (tip) tip.style.display = 'none'; }
    function bindTips(root) {
        root.querySelectorAll('[data-tip]').forEach(el => {
            el.addEventListener('mousemove', e => showTip(e, el.getAttribute('data-tip')));
            el.addEventListener('mouseleave', hideTip);
        });
    }

    // ---------------- نمودار ستونی (گروهی یا انباشته) ----------------
    // labels: [..]، series: [{name, color, data:[..]}]
    function barChart(labels, series, opts = {}) {
        const W = 760, H = opts.h || 260, L = 64, R = 10, T = 16, B = 44;
        const stacked = !!opts.stacked;
        const n = labels.length || 1;
        let max = 0;
        labels.forEach((_, i) => {
            const vals = series.map(s => +s.data[i] || 0);
            max = Math.max(max, stacked ? vals.reduce((a, b) => a + b, 0) : Math.max(...vals, 0));
        });
        if (!max) return empty();
        const ticks = 4; const step = niceStep(max / ticks); max = step * ticks;
        const cw = (W - L - R) / n; const bw = stacked ? Math.min(46, cw * 0.6) : Math.min(22, (cw * 0.75) / series.length);
        const y = v => T + (H - T - B) * (1 - v / max);
        let g = '';
        for (let t = 0; t <= ticks; t++) {
            const v = step * t, yy = y(v);
            g += `<line x1="${L}" x2="${W - R}" y1="${yy}" y2="${yy}" stroke="#e2e8f0" stroke-dasharray="${t ? '3 3' : ''}"/>
                  <text x="${L - 6}" y="${yy + 4}" font-size="10" text-anchor="end" fill="#94a3b8">${axis(v)}</text>`;
        }
        labels.forEach((lab, i) => {
            const cx = L + cw * i + cw / 2;
            let acc = 0;
            const tipHtml = `<b>${esc(lab)}</b><br>` + series.map(s => `<span style="color:${s.color}">●</span> ${esc(s.name)}: ${num(s.data[i])} ${UNIT}`).join('<br>');
            series.forEach((s, si) => {
                const v = +s.data[i] || 0; if (!v && stacked) return;
                let x, yTop, h;
                if (stacked) { x = cx - bw / 2; yTop = y(acc + v); h = y(acc) - yTop; acc += v; }
                else { x = cx - (bw * series.length) / 2 + si * bw; yTop = y(v); h = y(0) - yTop; }
                g += `<rect x="${x + 1}" y="${yTop}" width="${bw - 2}" height="${Math.max(0, h)}" rx="${stacked ? 2 : 4}" fill="${s.color}" class="fin-bar" style="animation-delay:${i * 25}ms"/>`;
            });
            g += `<rect x="${L + cw * i}" y="${T}" width="${cw}" height="${H - T - B}" fill="transparent" data-tip="${esc(tipHtml)}"/>`;
            const every = Math.ceil(n / 14);
            if (i % every === 0) g += `<text x="${cx}" y="${H - B + 16}" font-size="10" text-anchor="middle" fill="#64748b">${esc(lab)}</text>`;
        });
        return wrap(W, H, g, series);
    }

    // ---------------- نمودار خطی/ناحیه‌ای ----------------
    function lineChart(labels, series, opts = {}) {
        const W = 760, H = opts.h || 240, L = 64, R = 14, T = 16, B = 40;
        const n = labels.length;
        let max = 0; series.forEach(s => s.data.forEach(v => { max = Math.max(max, +v || 0); }));
        if (!max || !n) return empty();
        const ticks = 4; const step = niceStep(max / ticks); max = step * ticks;
        const x = i => L + (n === 1 ? (W - L - R) / 2 : (W - L - R) * i / (n - 1));
        const y = v => T + (H - T - B) * (1 - v / max);
        let g = '';
        for (let t = 0; t <= ticks; t++) {
            const v = step * t, yy = y(v);
            g += `<line x1="${L}" x2="${W - R}" y1="${yy}" y2="${yy}" stroke="#e2e8f0" stroke-dasharray="${t ? '3 3' : ''}"/>
                  <text x="${L - 6}" y="${yy + 4}" font-size="10" text-anchor="end" fill="#94a3b8">${axis(v)}</text>`;
        }
        series.forEach((s, si) => {
            const pts = s.data.map((v, i) => `${x(i)},${y(+v || 0)}`);
            const gid = 'lg' + Math.random().toString(36).slice(2);
            g += `<defs><linearGradient id="${gid}" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${s.color}" stop-opacity=".28"/><stop offset="1" stop-color="${s.color}" stop-opacity="0"/></linearGradient></defs>`;
            if (s.area !== false) g += `<polygon points="${x(0)},${y(0)} ${pts.join(' ')} ${x(n - 1)},${y(0)}" fill="url(#${gid})"/>`;
            g += `<polyline points="${pts.join(' ')}" fill="none" stroke="${s.color}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" ${s.dash ? 'stroke-dasharray="6 4"' : ''} class="fin-line"/>`;
            s.data.forEach((v, i) => { g += `<circle cx="${x(i)}" cy="${y(+v || 0)}" r="3.5" fill="#fff" stroke="${s.color}" stroke-width="2"/>`; });
        });
        labels.forEach((lab, i) => {
            const tipHtml = `<b>${esc(lab)}</b><br>` + series.map(s => `<span style="color:${s.color}">●</span> ${esc(s.name)}: ${num(s.data[i])} ${UNIT}`).join('<br>');
            const w = (W - L - R) / Math.max(1, n - 1);
            g += `<rect x="${x(i) - w / 2}" y="${T}" width="${w}" height="${H - T - B}" fill="transparent" data-tip="${esc(tipHtml)}"/>`;
            const every = Math.ceil(n / 12);
            if (i % every === 0) g += `<text x="${x(i)}" y="${H - B + 16}" font-size="10" text-anchor="middle" fill="#64748b">${esc(lab)}</text>`;
        });
        return wrap(W, H, g, series);
    }

    // ---------------- نمودار دونات ----------------
    function donut(items, centerLabel) {
        const total = items.reduce((a, b) => a + (+b.value || 0), 0);
        if (!total) return empty();
        const R = 80, r = 52, C = 100;
        let a0 = -Math.PI / 2, g = '';
        items.forEach(it => {
            const v = +it.value || 0; if (!v) return;
            const a1 = a0 + (v / total) * Math.PI * 2 - (items.filter(x => +x.value).length > 1 ? 0.012 : 0);
            const large = a1 - a0 > Math.PI ? 1 : 0;
            const p = (ang, rad) => `${C + rad * Math.cos(ang)},${C + rad * Math.sin(ang)}`;
            const d = (a1 - a0 >= Math.PI * 2 - 0.02)
                ? `M ${C} ${C - R} A ${R} ${R} 0 1 1 ${C - 0.01} ${C - R} L ${C - 0.01} ${C - r} A ${r} ${r} 0 1 0 ${C} ${C - r} Z`
                : `M ${p(a0, R)} A ${R} ${R} 0 ${large} 1 ${p(a1, R)} L ${p(a1, r)} A ${r} ${r} 0 ${large} 0 ${p(a0, r)} Z`;
            g += `<path d="${d}" fill="${it.color}" class="fin-slice" data-tip="${esc(`<b>${esc(it.label)}</b><br>${num(v)} ${UNIT}<br>${fa(((v / total) * 100).toFixed(1))}٪`)}"/>`;
            a0 += (v / total) * Math.PI * 2;
        });
        g += `<text x="${C}" y="${C - 4}" text-anchor="middle" font-size="13" font-weight="900" fill="#1e293b">${short(total)}</text>
              <text x="${C}" y="${C + 14}" text-anchor="middle" font-size="10" fill="#94a3b8">${esc((centerLabel ? centerLabel + ' · ' : '') + UNIT)}</text>`;
        const legend = items.map(it => `<div class="flex items-center justify-between gap-3 text-[11px] py-1">
            <span class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full" style="background:${it.color}"></span>${esc(it.label)}</span>
            <b class="text-slate-700">${fa(total ? ((+it.value / total) * 100).toFixed(0) : 0)}٪</b></div>`).join('');
        return `<div class="flex items-center gap-4 flex-wrap justify-center"><svg viewBox="0 0 200 200" class="w-44 h-44 shrink-0">${g}</svg><div class="min-w-[150px] flex-1">${legend}</div></div>`;
    }

    // ---------------- ستونی افقی (رتبه‌بندی شرکت‌ها) ----------------
    function hbars(rows) {
        if (!rows.length) return empty();
        const max = Math.max(...rows.map(r => r.amount), 1);
        return `<div class="space-y-2.5">` + rows.map(r => {
            const pPaid = (r.paid / max) * 100, pRem = (r.remaining / max) * 100, pOver = (r.overdue / max) * 100;
            return `<div data-tip="${esc(`<b>${esc(r.name)}</b><br>جمع اقساط: ${num(r.amount)} ${UNIT}<br>دریافت‌شده: ${num(r.paid)}<br>مانده: ${num(r.remaining)}<br>معوق: ${num(r.overdue)}<br>بیمه‌نامه: ${fa(r.policies)}`)}">
                <div class="flex justify-between text-[11px] mb-1"><span class="font-bold text-slate-700 truncate">${esc(r.name)}</span><span class="text-slate-500">${short(r.amount)} ${UNIT}</span></div>
                <div class="h-3 bg-slate-100 rounded-full overflow-hidden flex">
                    <div class="fin-grow h-full bg-emerald-500" style="width:${pPaid}%"></div>
                    <div class="fin-grow h-full bg-amber-400" style="width:${Math.max(0, pRem - pOver)}%"></div>
                    <div class="fin-grow h-full bg-rose-500" style="width:${pOver}%"></div>
                </div></div>`;
        }).join('') + `</div>
        <div class="flex gap-4 text-[10px] text-slate-500 mt-3 justify-center"><span><span class="inline-block w-2 h-2 rounded-full bg-emerald-500 ml-1"></span>دریافت‌شده</span><span><span class="inline-block w-2 h-2 rounded-full bg-amber-400 ml-1"></span>مانده</span><span><span class="inline-block w-2 h-2 rounded-full bg-rose-500 ml-1"></span>معوق</span></div>`;
    }

    function niceStep(v) { const p = Math.pow(10, Math.floor(Math.log10(v || 1))); const m = v / p; return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * p; }
    function empty() { return '<div class="h-40 flex flex-col items-center justify-center text-slate-300 text-xs"><i class="fas fa-chart-simple text-3xl mb-2"></i>داده‌ای برای این فیلترها نیست</div>'; }
    function wrap(W, H, g, series) {
        const legend = series.length > 1 ? `<div class="flex flex-wrap gap-4 justify-center text-[11px] text-slate-600 mt-1">${series.map(s => `<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded" style="background:${s.color}"></span>${esc(s.name)}</span>`).join('')}</div>` : '';
        return `<svg viewBox="0 0 ${W} ${H}" class="w-full h-auto" direction="ltr" style="font-family:inherit">${g}</svg>${legend}`;
    }

    // ---------------- داشبورد ----------------
    const state = { alertTab: 'overdue', data: null };
    // کلاس‌ها کامل نوشته شده‌اند (نه ساخته‌شده از رشته) تا Tailwind همه را بشناسد
    const TAB_ON = { overdue: 'bg-rose-500', upcoming: 'bg-sky-500', unpaid: 'bg-amber-500' };
    const BADGE = { overdue: 'bg-rose-100 text-rose-700', upcoming: 'bg-sky-100 text-sky-700', unpaid: 'bg-amber-100 text-amber-700' };
    const tabCls = (k, on) => 'px-4 py-2 rounded-xl text-xs font-bold transition ' + (on ? TAB_ON[k] + ' text-white shadow' : 'text-slate-600 hover:bg-white');
    const badgeCls = (k, on) => 'mr-1 px-1.5 rounded-full ' + (on ? 'bg-white/25' : BADGE[k]);

    function filterBar() {
        const b = window.finBoot || (typeof finBoot !== 'undefined' ? finBoot : null) || { periods: [], companies: [] };
        const years = [...new Set((b.periods || []).map(p => String(p.title || '').replace(/[^\d۰-۹]/g, '')).filter(Boolean))];
        const y = window.IrTime ? IrTime.parts().jy : new Date().getFullYear() - 621;
        const yearOpts = [...new Set([...years.map(v => v.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))), String(y), String(y - 1)])].sort().reverse();
        const sel = (id, opts, label) => `<div><label class="text-[10px] font-bold text-slate-500 block mb-1">${label}</label><select id="${id}" class="fd-f border-2 border-slate-100 focus:border-indigo-400 rounded-xl px-3 py-2 text-xs font-bold bg-white min-w-[110px]">${opts}</select></div>`;
        return `<div class="card p-4 bg-white/80 backdrop-blur">
            <div class="fd-bar flex flex-wrap gap-3 items-end">
                ${sel('fd-source', '<option value="">پرسنلی + شرکتی</option><option value="P">فقط پرسنلی (کسر از حقوق)</option><option value="C">فقط شرکتی</option>', 'منبع')}
                ${sel('fd-year', '<option value="">همه سال‌ها</option>' + yearOpts.map(v => `<option value="${v}">${fa(v)}</option>`).join(''), 'سال')}
                ${sel('fd-period', '<option value="">همه دوره‌ها</option>' + (b.periods || []).map(p => `<option value="${p.id}">${fa(p.title)}</option>`).join(''), 'دوره مالی')}
                ${sel('fd-company', '<option value="">همه شرکت‌ها</option>' + (b.companies || []).map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join(''), 'شرکت')}
                ${sel('fd-type', '<option value="">همه</option><option value="THIRD_PARTY">ثالث</option><option value="BODY">بدنه</option>', 'نوع بیمه')}
                ${sel('fd-status', '<option value="">همه اقساط</option><option value="OPEN">باز (مانده‌دار)</option><option value="OVERDUE">معوق</option><option value="UPCOMING">سررسید ۳۰ روز آینده</option><option value="UNPAID">پرداخت‌نشده</option><option value="PARTIAL">ناقص</option><option value="PAID">تسویه‌شده</option>', 'وضعیت قسط')}
                ${sel('fd-pasargad', '<option value="">همه</option><option value="open">تسویه‌نشده با پاسارگاد</option><option value="settled">تسویه‌شده با پاسارگاد</option>', 'پاسارگاد')}
                <div><label class="text-[10px] font-bold text-slate-500 block mb-1">بازه‌ی تاریخ</label>
                    <div class="flex gap-1 items-center">
                        <select id="fd-datefield" class="fd-f border-2 border-slate-100 rounded-xl px-2 py-2 text-xs font-bold bg-white"><option value="due">سررسید</option><option value="issue">صدور</option></select>
                        <input id="fd-from" class="fd-f border-2 border-slate-100 rounded-xl px-2 py-2 text-xs w-24 text-center" placeholder="از ۱۴۰۵/۰۱/۰۱" dir="ltr">
                        <input id="fd-to" class="fd-f border-2 border-slate-100 rounded-xl px-2 py-2 text-xs w-24 text-center" placeholder="تا" dir="ltr">
                    </div></div>
                <div class="flex gap-2 mr-auto">
                    <button id="fd-reset" class="px-3 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200"><i class="fas fa-eraser ml-1"></i>پاک‌کردن</button>
                    <button id="fd-master" class="px-3 py-2 rounded-xl text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700 shadow-lg shadow-emerald-500/20"><i class="fas fa-file-excel ml-1"></i>اکسل جامع</button>
                </div>
            </div></div>`;
    }

    function params() {
        const v = id => (document.getElementById(id) || {}).value || '';
        const p2e = s => String(s).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
        return new URLSearchParams({ source: v('fd-source'), year: v('fd-year'), period_id: v('fd-period'), company_id: v('fd-company'),
            insurance_type: v('fd-type'), status: v('fd-status'), pasargad: v('fd-pasargad'), date_field: v('fd-datefield'),
            from: p2e(v('fd-from')), to: p2e(v('fd-to')) });
    }

    function kpiCard(title, value, sub, grad, icon, extra = '', isCount = false) {
        return `<div class="relative overflow-hidden rounded-2xl p-4 text-white shadow-lg bg-gradient-to-br ${grad} fin-pop">
            <div class="absolute -left-6 -bottom-6 w-24 h-24 rounded-full bg-white/10"></div>
            <div class="absolute left-3 top-3 text-white/25 text-3xl"><i class="fas ${icon}"></i></div>
            <p class="text-[11px] text-white/80 font-bold relative">${title}</p>
            <p class="text-xl md:text-2xl font-black mt-1 relative" title="${num(value)}${isCount ? '' : ' ' + UNIT}">${isCount ? fa(value) : short(value) + ' <span class="text-xs font-bold opacity-80">' + UNIT + '</span>'}</p>
            <p class="text-[10px] text-white/75 mt-1 relative">${sub}</p>${extra}</div>`;
    }

    async function dashboard() {
        const root = document.getElementById('fin-dash-root');
        if (!root) return;
        if (!root.dataset.ready) {
            root.dataset.ready = '1';
            root.innerHTML = filterBar() + `<div id="fd-body" class="space-y-6"></div>`;
            root.querySelectorAll('.fd-f').forEach(el => el.addEventListener('change', dashboard));
            root.querySelector('#fd-reset').onclick = () => { root.querySelectorAll('.fd-f').forEach(el => { el.value = el.id === 'fd-datefield' ? 'due' : ''; }); dashboard(); };
            root.querySelector('#fd-master').onclick = () => window.open(`${API}?action=export_master&${params()}`, '_blank');
        }
        const body = document.getElementById('fd-body');
        body.innerHTML = `<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">${'<div class="h-28 rounded-2xl bg-slate-100 animate-pulse"></div>'.repeat(8)}</div>`;
        let d;
        try { d = await (await fetch(`${API}?action=analytics&${params()}`)).json(); } catch (e) { d = { ok: false, error: 'خطا در اتصال' }; }
        if (!d.ok) { body.innerHTML = `<div class="card p-6 text-center text-rose-500 text-sm">${esc(d.error || 'خطا')}</div>`; return; }
        state.data = d;
        const k = d.kpi;
        const pct = k.inst_amount ? Math.round((k.collected / k.inst_amount) * 100) : 0;
        const progress = `<div class="mt-2 h-1.5 bg-white/25 rounded-full overflow-hidden relative"><div class="h-full bg-white rounded-full fin-grow" style="width:${pct}%"></div></div>`;

        const periods = Object.keys(d.by_period);
        const dues = Object.keys(d.by_due).filter(Boolean);
        body.innerHTML = `
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            ${kpiCard('حق بیمه‌ی کل', k.premium, `${fa(k.policies)} بیمه‌نامه · ${fa(k.companies)} شرکت`, 'from-indigo-500 to-violet-600', 'fa-file-shield')}
            ${kpiCard('دریافت‌شده از شرکت‌ها / پرسنل', k.collected, `${fa(pct)}٪ از ${short(k.inst_amount)} ${UNIT} اقساط`, 'from-emerald-500 to-teal-600', 'fa-hand-holding-dollar', progress)}
            ${kpiCard('مانده‌ی وصول‌نشده', k.remaining, `${fa(k.inst_count)} قسط در این فیلتر`, 'from-amber-500 to-orange-600', 'fa-hourglass-half')}
            ${kpiCard('معوق (سررسید گذشته)', k.overdue, `${fa(k.overdue_count)} قسط معوق`, 'from-rose-500 to-red-600', 'fa-triangle-exclamation')}
            ${kpiCard('سررسید ۳۰ روز آینده', k.upcoming, `${fa(k.upcoming_count)} قسط`, 'from-sky-500 to-blue-600', 'fa-calendar-day')}
            ${kpiCard('تسویه‌شده با پاسارگاد', k.settled, 'اقساطی که به پاسارگاد پرداخت شده', 'from-blue-600 to-indigo-700', 'fa-building-columns')}
            ${kpiCard('آماده‌ی پرداخت به پاسارگاد', k.pasargad_due, 'دریافت‌شده ولی هنوز تسویه‌نشده', 'from-fuchsia-500 to-pink-600', 'fa-arrow-right-arrow-left')}
            ${kpiCard('صورتحساب‌های فعال', k.invoices, 'تعداد صورتحساب صادرشده (غیر از غیرفعال‌ها)', 'from-slate-600 to-slate-800', 'fa-file-invoice', '', true)}
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div class="card p-5 xl:col-span-2">
                <div class="flex justify-between items-center mb-3"><h3 class="font-black text-sm text-slate-700"><i class="fas fa-chart-column text-indigo-500 ml-1"></i>حق بیمه‌ی صادره در هر دوره</h3><span class="text-[10px] text-slate-400">پرسنلی و شرکتی جدا</span></div>
                <div id="fd-ch-period"></div>
            </div>
            <div class="card p-5">
                <h3 class="font-black text-sm text-slate-700 mb-3"><i class="fas fa-circle-half-stroke text-emerald-500 ml-1"></i>وضعیت اقساط</h3>
                <div id="fd-ch-status"></div>
            </div>
        </div>

        <div class="card p-5">
            <div class="flex justify-between items-center mb-3 flex-wrap gap-2"><h3 class="font-black text-sm text-slate-700"><i class="fas fa-chart-line text-sky-500 ml-1"></i>جریان نقدی اقساط بر اساس ماهِ سررسید</h3>
            <span class="text-[10px] text-slate-400">جمع اقساطِ سررسید هر ماه، دریافت‌شده و تسویه با پاسارگاد (پیش‌بینی ماه‌های آینده)</span></div>
            <div id="fd-ch-due"></div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div class="card p-5 xl:col-span-2">
                <h3 class="font-black text-sm text-slate-700 mb-4"><i class="fas fa-ranking-star text-amber-500 ml-1"></i>شرکت‌ها: اقساط، دریافتی و معوق</h3>
                <div id="fd-ch-companies" class="max-h-[420px] overflow-y-auto pl-1"></div>
            </div>
            <div class="card p-5 space-y-6">
                <div><h3 class="font-black text-sm text-slate-700 mb-3"><i class="fas fa-people-group text-violet-500 ml-1"></i>پرسنلی / شرکتی</h3><div id="fd-ch-source"></div></div>
                <div><h3 class="font-black text-sm text-slate-700 mb-3"><i class="fas fa-car-burst text-cyan-500 ml-1"></i>ثالث / بدنه</h3><div id="fd-ch-type"></div></div>
            </div>
        </div>

        <div class="card p-0 overflow-hidden">
            <div class="flex flex-wrap gap-1 p-2 bg-slate-50 border-b" id="fd-alert-tabs">
                ${[['overdue', 'معوق', d.alerts.overdue.length], ['upcoming', 'سررسید ۳۰ روز آینده', d.alerts.upcoming.length], ['unpaid', 'بدون هیچ پرداخت', d.alerts.unpaid.length]]
                    .map(([key, t, n]) => `<button data-at="${key}" class="${tabCls(key, state.alertTab === key)}"><i class="fas fa-bell ml-1"></i>${t} <span class="${badgeCls(key, state.alertTab === key)}">${fa(n)}</span></button>`).join('')}
            </div>
            <div id="fd-alerts" class="overflow-x-auto max-h-[420px] overflow-y-auto"></div>
        </div>`;

        const P = periods.map(perLabel);
        document.getElementById('fd-ch-period').innerHTML = barChart(P, [
            { name: 'پرسنلی', color: COLORS.p, data: periods.map(p => d.by_period[p].premium_p) },
            { name: 'شرکتی', color: COLORS.c, data: periods.map(p => d.by_period[p].premium_c) }], { stacked: true });
        document.getElementById('fd-ch-status').innerHTML = donut([
            { label: 'تسویه‌شده', value: d.status.PAID, color: COLORS.paid },
            { label: 'باز (سررسیدنشده)', value: d.status.OPEN, color: COLORS.open },
            { label: 'ناقص', value: d.status.PARTIAL, color: COLORS.partial },
            { label: 'معوق', value: d.status.OVERDUE, color: COLORS.over }], 'جمع اقساط');
        document.getElementById('fd-ch-due').innerHTML = lineChart(dues.map(perLabel), [
            { name: 'جمع اقساط سررسید', color: COLORS.due, data: dues.map(m => d.by_due[m].due), area: false, dash: true },
            { name: 'دریافت‌شده', color: COLORS.paid, data: dues.map(m => d.by_due[m].paid) },
            { name: 'مانده', color: COLORS.rem, data: dues.map(m => d.by_due[m].remaining) },
            { name: 'تسویه با پاسارگاد', color: COLORS.settled, data: dues.map(m => d.by_due[m].settled), area: false }]);
        document.getElementById('fd-ch-companies').innerHTML = hbars(d.companies);
        document.getElementById('fd-ch-source').innerHTML = donut([
            { label: `پرسنلی (${fa(d.by_source.P.count)})`, value: d.by_source.P.premium, color: COLORS.p },
            { label: `شرکتی (${fa(d.by_source.C.count)})`, value: d.by_source.C.premium, color: COLORS.c }], 'حق بیمه');
        const tcol = ['#0ea5e9', '#f97316', '#a855f7', '#64748b'];
        document.getElementById('fd-ch-type').innerHTML = donut(Object.keys(d.by_type).map((t, i) => ({ label: `${t} (${fa(d.by_type[t].count)})`, value: d.by_type[t].premium, color: tcol[i % tcol.length] })), 'حق بیمه');
        renderAlerts();
        body.querySelectorAll('[data-at]').forEach(b => b.onclick = () => { state.alertTab = b.dataset.at; dashboard.redrawTabs(); });
        bindTips(body);
    }
    dashboard.redrawTabs = function () {
        const d = state.data; if (!d) return;
        const tabs = document.getElementById('fd-alert-tabs');
        tabs.querySelectorAll('[data-at]').forEach(b => {
            const on = b.dataset.at === state.alertTab;
            b.className = tabCls(b.dataset.at, on);
            b.querySelector('span').className = badgeCls(b.dataset.at, on);
        });
        renderAlerts();
    };

    function renderAlerts() {
        const d = state.data; const rows = d.alerts[state.alertTab] || [];
        const box = document.getElementById('fd-alerts');
        if (!rows.length) { box.innerHTML = '<div class="p-8 text-center text-slate-400 text-xs"><i class="fas fa-circle-check text-3xl text-emerald-300 mb-2 block"></i>موردی نیست.</div>'; return; }
        const clr = state.alertTab === 'overdue' ? 'text-rose-600' : state.alertTab === 'upcoming' ? 'text-sky-600' : 'text-amber-600';
        box.innerHTML = `<table class="w-full text-[11px]"><thead class="bg-white sticky top-0 shadow-sm text-slate-500"><tr>
            <th class="p-2.5 text-right">منبع</th><th class="p-2.5 text-right">شرکت</th><th class="p-2.5 text-right">بیمه‌گذار</th><th class="p-2.5">پلاک</th>
            <th class="p-2.5">قسط</th><th class="p-2.5">سررسید</th><th class="p-2.5">مبلغ (تومان)</th><th class="p-2.5">مانده (تومان)</th><th class="p-2.5">کد رهگیری</th></tr></thead><tbody>` +
            rows.map(r => `<tr class="border-t hover:bg-slate-50">
                <td class="p-2.5"><span class="px-2 py-0.5 rounded-lg text-[10px] font-bold ${r.source === 'C' ? 'bg-cyan-100 text-cyan-700' : 'bg-indigo-100 text-indigo-700'}">${r.source === 'C' ? 'شرکتی' : 'پرسنلی'}</span></td>
                <td class="p-2.5 font-bold text-slate-700">${esc(r.company_name)}</td><td class="p-2.5">${esc(r.insured || '-')}</td>
                <td class="p-2.5 text-center whitespace-nowrap">${r.plate && window.formatPlateHtml ? formatPlateHtml(r.plate) : `<span class="inline-block border border-slate-300 rounded-md px-2 py-0.5 bg-white font-bold text-slate-700">${esc(fa(r.plate || '-'))}</span>`}</td>
                <td class="p-2.5 text-center font-bold">${fa(r.inst_number)}</td>
                <td class="p-2.5 text-center font-bold ${clr}" dir="ltr">${fa(r.due_jalali)}</td>
                <td class="p-2.5 text-center">${num(r.amount)}</td><td class="p-2.5 text-center font-black ${clr}">${num(r.remaining)}</td>
                <td class="p-2.5 text-center font-mono text-slate-400" dir="ltr">${fa(r.tracking_code || '-')}</td></tr>`).join('') + '</tbody></table>';
    }

    // ---------------- انتخاب و ترتیب ستون‌های صورتحساب ----------------
    // pool: {key:[title,weight,kinds]}؛ selected: [keys] => کلیدهای انتخاب‌شده به ترتیب
    function columnPicker(container, kind, pool, selected, onChange) {
        const keys = Object.keys(pool).filter(k => pool[k][2].includes(kind));
        let order = [...selected.filter(k => keys.includes(k)), ...keys.filter(k => !selected.includes(k))];
        let chosen = new Set(selected.filter(k => keys.includes(k)));
        const draw = () => {
            container.innerHTML = `<div class="flex flex-wrap gap-1.5">` + order.map((k, i) => `
                <div class="flex items-center gap-1 border rounded-xl pl-1 pr-2 py-1 text-[11px] ${chosen.has(k) ? 'bg-blue-50 border-blue-300 text-blue-800' : 'bg-white text-slate-400'}">
                    <label class="flex items-center gap-1 cursor-pointer font-bold"><input type="checkbox" data-k="${k}" ${chosen.has(k) ? 'checked' : ''}>${esc(pool[k][0])}</label>
                    <button data-mv="${i}" data-d="-1" class="px-1.5 text-base leading-none text-slate-400 hover:text-blue-600" title="انتقال به قبل">›</button>
                    <button data-mv="${i}" data-d="1" class="px-1.5 text-base leading-none text-slate-400 hover:text-blue-600" title="انتقال به بعد">‹</button>
                </div>`).join('') + `</div>`;
            container.querySelectorAll('[data-k]').forEach(c => c.onchange = () => { c.checked ? chosen.add(c.dataset.k) : chosen.delete(c.dataset.k); draw(); emit(); });
            container.querySelectorAll('[data-mv]').forEach(b => b.onclick = e => {
                e.preventDefault(); const i = +b.dataset.mv, j = i + (+b.dataset.d);
                if (j < 0 || j >= order.length) return; [order[i], order[j]] = [order[j], order[i]]; draw(); emit();
            });
        };
        const emit = () => onChange && onChange(order.filter(k => chosen.has(k)));
        draw();
        return { get: () => order.filter(k => chosen.has(k)) };
    }

    // کادر رمز عبور (ورودی از نوع password تا رمز دیده نشود)
    function askPassword(title, message) {
        return new Promise(resolve => {
            const ov = document.createElement('div');
            ov.className = 'fixed inset-0 z-[9998] bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4';
            ov.innerHTML = `<div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm p-6 text-center">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-rose-500 to-red-600 text-white flex items-center justify-center text-2xl mx-auto mb-3"><i class="fas fa-lock"></i></div>
                <h3 class="font-black text-slate-800 mb-2">${esc(title)}</h3><p class="text-xs text-slate-500 leading-6 mb-4">${esc(message || '')}</p>
                <input type="password" class="fp-pass w-full border-2 border-slate-200 focus:border-rose-400 outline-none rounded-xl px-4 py-3 text-sm text-center" placeholder="رمز عبور" dir="ltr" autocomplete="current-password">
                <div class="flex gap-2 mt-4"><button class="fp-ok flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-3 rounded-xl text-xs">تایید</button>
                <button class="fp-no flex-1 bg-slate-100 text-slate-600 font-bold py-3 rounded-xl text-xs">انصراف</button></div></div>`;
            document.body.appendChild(ov);
            const inp = ov.querySelector('.fp-pass');
            const done = v => { ov.remove(); resolve(v); };
            ov.querySelector('.fp-no').onclick = () => done(null);
            ov.querySelector('.fp-ok').onclick = () => inp.value.trim() ? done(inp.value.trim()) : inp.focus();
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') ov.querySelector('.fp-ok').click(); if (e.key === 'Escape') done(null); });
            setTimeout(() => inp.focus(), 30);
        });
    }

    window.FinUI = { dashboard, columnPicker, barChart, lineChart, donut, askPassword, num, short, fa, esc };
})();
