// فایل: health.js
// «مالیِ درمان تکمیلی»: داشبورد (وصول، معوق، سررسیدهای نزدیک، بدهی به پاسارگاد، نمودارِ ماه به ماه)، قراردادها و اقساطِ هر شرکت
// (ساختِ خودکارِ اقساط، پرداخت به ما یا مستقیم به بیمه‌گر)، دریافت‌ها با هر روش و تخصیص به اقساط، پرداخت به پاسارگاد، اکسل و تنظیمات.
(function () {
    'use strict';
    const API = 'api/hl_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => fa((Number(v) || 0).toLocaleString('en-US'));
    const short = v => { const n = Number(v) || 0, a = Math.abs(n); return a >= 1e9 ? fa((n / 1e9).toFixed(a >= 1e10 ? 0 : 1)) + ' میلیارد' : a >= 1e6 ? fa((n / 1e6).toFixed(a >= 1e8 ? 0 : 1)) + ' میلیون' : a >= 1e3 ? fa(Math.round(n / 1e3)) + ' هزار' : fa(n); };
    const num = v => Number(String(en(v)).replace(/[^\d.]/g, '')) || 0;
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const confirmUi = async (t, m, o) => (window.uiConfirm ? uiConfirm(t, m, o || {}) : confirm(m));
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const C1 = '#2a78d6', C2 = '#eb6834', C3 = '#1baf7a';
    const H = {boot: null, dash: {}, pay: {}, con: {q: '', status: ''}};
    const can = (p, op = 'view') => !!(H.boot && (H.boot.perms[p] || []).includes(op));
    async function api(action, body = {}, form = null) {
        let res;
        try {
            if (form) { form.append('action', action); res = await fetch(API, {method: 'POST', body: form, credentials: 'same-origin'}); }
            else res = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(Object.assign({action}, body))});
        } catch (e) { throw new Error('اتصال به سرور برقرار نشد.'); }
        const j = await res.json().catch(() => ({ok: false, error: 'پاسخِ نامعتبر از سرور'}));
        if (!j.ok) throw new Error(j.error || 'خطا');
        return j;
    }
    const url = (action, o = {}) => API + '?' + Object.entries(Object.assign({action}, o)).filter(([, v]) => v !== '' && v !== null && v !== undefined).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&');
    const download = h => { const a = document.createElement('a'); a.href = h; document.body.appendChild(a); a.click(); setTimeout(() => a.remove(), 300); };
    const busy = (b, on) => { if (!b) return; b.disabled = on; if (on) { b.dataset.h = b.innerHTML; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> صبر کنید…'; } else if (b.dataset.h) b.innerHTML = b.dataset.h; };
    const PAYEE = () => ({US: 'بیمه با ما', INSURER: H.boot.settings.insurer_label || 'پاسارگاد'});
    const ST_FA = {PAID: 'پرداخت‌شده', PARTIAL: 'نیمه‌پرداخت', OVERDUE: 'معوق', DUE: 'سررسیدنشده'}, ST_C = {PAID: 'g', PARTIAL: 'a', OVERDUE: 'r', DUE: 's'};

    const STYLE = `
    .hl{direction:rtl;font-size:12.5px;color:#0f172a}.hl *{box-sizing:border-box}
    .hl-hub{display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between;background:linear-gradient(120deg,#064e3b,#047857 45%,#0e7490);border-radius:24px;padding:16px 18px;color:#fff;box-shadow:0 22px 40px -28px #047857;position:relative;overflow:hidden}
    .hl-hub::after{content:'';position:absolute;width:260px;height:260px;border-radius:50%;left:-70px;top:-150px;background:rgba(255,255,255,.1);pointer-events:none}
    .hl-hub .t{display:flex;align-items:center;gap:12px;z-index:1}.hl-hub .ic{width:44px;height:44px;border-radius:15px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:19px}
    .hl-hub b{font-size:17px;font-weight:900;display:block}.hl-hub small{opacity:.85;font-size:11px}
    .hl-nav{display:flex;gap:6px;flex-wrap:wrap;z-index:1}.hl-nav button{border:0;font-family:inherit;font-weight:800;font-size:12px;border-radius:14px;padding:8px 13px;background:rgba(255,255,255,.14);color:#fff;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
    .hl-nav button.on{background:#fff;color:#047857}
    .hl-card{background:#fff;border:1px solid #e2ece8;border-radius:20px;padding:16px;box-shadow:0 10px 30px -26px rgba(15,23,42,.35)}
    .hl-card h3{font-size:13.5px;font-weight:900;display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}.hl-card h3 small{font-weight:600;color:#64748b;font-size:11px}
    .hl-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:13px;padding:8px 13px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;white-space:nowrap;text-decoration:none}
    .hl-btn:disabled{opacity:.45;cursor:not-allowed}.hl-btn.p{background:linear-gradient(120deg,#047857,#0e7490);color:#fff}.hl-btn.s{background:#f1f5f9;color:#334155}.hl-btn.o{background:#fff;color:#334155;border:1px solid #e2e8f0}
    .hl-btn.r{background:#fff1f2;color:#be123c}.hl-btn.a{background:#fffbeb;color:#b45309}.hl-btn.sm{padding:5px 9px;font-size:11px;border-radius:10px}
    .hl-in,.hl-sel,.hl-ta{border:1px solid #d9e4df;border-radius:12px;padding:8px 10px;font-size:12.5px;font-family:inherit;background:#fff;width:100%;min-width:0}
    .hl-in:focus,.hl-sel:focus,.hl-ta:focus{outline:2px solid #a7f3d0;border-color:#10b981}
    .hl-lbl{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px}
    .hl-grid{display:grid;gap:10px}.hl-g2{grid-template-columns:repeat(2,minmax(0,1fr))}.hl-g3{grid-template-columns:repeat(3,minmax(0,1fr))}.hl-g4{grid-template-columns:repeat(4,minmax(0,1fr))}
    .hl-f{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}.hl-f>label{display:flex;flex-direction:column;gap:3px;flex:1 1 130px;max-width:220px}.hl-f>label span{font-size:10.5px;font-weight:800;color:#64748b}
    .hl-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:10px}
    .hl-kpi{background:#fff;border:1px solid #e2ece8;border-radius:18px;padding:13px 14px;position:relative;overflow:hidden}.hl-kpi .k{font-size:11px;font-weight:800;color:#64748b;display:flex;gap:6px;align-items:center}.hl-kpi .v{font-size:18px;font-weight:900;margin-top:5px}.hl-kpi .s{font-size:10.5px;color:#64748b;margin-top:2px}
    .hl-kpi.red{border-color:#fecdd3;background:linear-gradient(135deg,#fff,#fff5f6)}.hl-kpi.red .v{color:#be123c}.hl-kpi.grn .v{color:#047857}.hl-kpi.amb{border-color:#fde68a}.hl-kpi.amb .v{color:#b45309}
    .hl-bar{height:7px;border-radius:99px;background:#f1f5f9;overflow:hidden;margin-top:7px}.hl-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#10b981,#22c55e)}
    .hl-tag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;border-radius:9px;padding:2px 8px;background:#f1f5f9;color:#475569;white-space:nowrap}
    .hl-tag.g{background:#dcfce7;color:#166534}.hl-tag.r{background:#ffe4e6;color:#9f1239}.hl-tag.a{background:#fef3c7;color:#92400e}.hl-tag.b{background:#dbeafe;color:#1e40af}.hl-tag.c{background:#cffafe;color:#155e75}.hl-tag.s{background:#f1f5f9;color:#475569}
    .hl-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}.hl-tbl th{background:#ecfdf5;color:#065f46;font-weight:900;font-size:10.5px;padding:9px 8px;text-align:right;white-space:nowrap;position:sticky;top:0;z-index:1}
    .hl-tbl td{padding:9px 8px;border-top:1px solid #eef4f1;vertical-align:middle}.hl-tbl tbody tr:hover td{background:#f7fdfa}.hl-tbl tr.void td{opacity:.5;text-decoration:line-through}.hl-tbl .m{font-weight:800;white-space:nowrap}
    .hl-tbl tfoot td{background:#f8fafc;font-weight:900;border-top:2px solid #d1fae5}
    .hl-scroll{overflow:auto;border-radius:16px;border:1px solid #eef4f1;max-height:62vh}
    .hl-cards{display:none;flex-direction:column;gap:10px}.hl-pc{background:#fff;border:1px solid #e2ece8;border-radius:18px;padding:12px 13px}
    .hl-empty{text-align:center;color:#94a3b8;padding:30px 10px;font-weight:700}.hl-empty i{font-size:26px;display:block;margin-bottom:8px;color:#cbd5e1}
    .hl-ov{position:fixed;inset:0;z-index:9500;background:rgba(15,23,42,.5);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:24px 12px;overflow:auto;direction:rtl}
    .hl-md{background:#fff;border-radius:24px;width:100%;max-width:680px;box-shadow:0 30px 60px -25px rgba(0,0,0,.5);animation:hlPop .18s ease-out}.hl-md.w{max-width:1080px}
    @keyframes hlPop{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}
    .hl-mh{display:flex;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #eef4f1}.hl-mh h3{font-size:14.5px;font-weight:900}
    .hl-mb{padding:16px 18px}.hl-mf{display:flex;gap:8px;justify-content:flex-end;padding:12px 18px;border-top:1px solid #eef4f1;flex-wrap:wrap}
    .hl-x{margin-right:auto;width:36px;height:36px;border-radius:12px;background:#f1f5f9;border:0;cursor:pointer}
    .hl-chart{position:relative}.hl-chart svg{display:block;width:100%;height:auto;overflow:visible}
    .hl-tip{position:absolute;pointer-events:none;background:#0f172a;color:#fff;border-radius:12px;padding:8px 10px;font-size:11px;line-height:1.8;white-space:nowrap;z-index:5;opacity:0;transition:opacity .12s}
    .hl-tip .sw{display:inline-block;width:9px;height:9px;border-radius:3px;margin-left:5px}
    .hl-leg{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;font-weight:800;color:#475569;margin-bottom:6px}.hl-leg i{display:inline-block;width:11px;height:11px;border-radius:4px;margin-left:5px;vertical-align:-1px}
    .hl-hb{display:flex;flex-direction:column;gap:8px}.hl-hb .r{display:grid;grid-template-columns:minmax(90px,170px) 1fr auto;gap:8px;align-items:center;font-size:11.5px}
    .hl-hb .t{height:12px;border-radius:6px;background:#f1f5f9;overflow:hidden}.hl-hb .t i{display:block;height:100%;border-radius:0 6px 6px 0;background:#e34948}
    .hl-hb .r span:first-child{font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .hl-tg{display:flex;gap:4px;background:#f1f5f9;border-radius:12px;padding:3px}.hl-tg button{flex:1;border:0;background:transparent;border-radius:9px;padding:7px 4px;font-family:inherit;font-weight:800;font-size:11.5px;cursor:pointer;color:#475569}
    .hl-tg button.on{background:#fff;color:#047857;box-shadow:0 2px 6px -3px rgba(0,0,0,.3)}
    .hl-row{background:#f7fdfa;border:1px solid #dcefe6;border-radius:16px;padding:12px;display:flex;flex-direction:column;gap:8px}
    .hl-irow{display:grid;grid-template-columns:34px 1.1fr 1.3fr 1.1fr 1.4fr 34px;gap:6px;align-items:center}
    .hl-sub{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.hl-sub button{border:1px solid #a7f3d0;background:#fff;color:#065f46;font-family:inherit;font-weight:800;font-size:12px;border-radius:12px;padding:7px 12px;cursor:pointer}.hl-sub button.on{background:#047857;color:#fff;border-color:#047857}
    @media (max-width:767px){.hl-g2,.hl-g3,.hl-g4{grid-template-columns:minmax(0,1fr)}.hl-tblwrap{display:none}.hl-cards{display:flex}.hl-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
        .hl-hub{border-radius:20px}.hl-nav{width:100%;overflow:auto;flex-wrap:nowrap}.hl-nav button{flex-shrink:0}.hl-f>label{max-width:none;flex:1 1 45%}
        .hl-ov{padding:0;align-items:flex-end}.hl-md{border-radius:22px 22px 0 0;max-height:94vh;overflow:auto}.hl-irow{grid-template-columns:28px 1fr 1fr;}.hl-irow .w2{grid-column:span 2}}`;
    const css = () => { if (!document.getElementById('hl-css')) { const s = document.createElement('style'); s.id = 'hl-css'; s.textContent = STYLE; document.head.appendChild(s); } };

    const TABS = [['hl-dash', 'fa-chart-pie', 'داشبورد'], ['hl-contracts', 'fa-file-contract', 'قراردادها و اقساط'], ['hl-pay', 'fa-hand-holding-dollar', 'دریافت‌ها'], ['hl-settle', 'fa-building-columns', 'پرداخت به پاسارگاد'], ['hl-settings', 'fa-sliders', 'تنظیمات']];
    const shell = (tab, body) => `<div class="hl space-y-4 no-count" data-perm-skip><div class="hl-hub"><div class="t"><span class="ic"><i class="fas fa-stethoscope"></i></span><div><b>مالیِ درمان تکمیلی</b><small>قراردادهای شرکت‌ها، اقساط، وصول و تسویه با ${esc(H.boot.settings.insurer_label || 'پاسارگاد')}</small></div></div>
        <div class="hl-nav">${TABS.filter(t => can(t[0])).map(t => `<button class="${t[0] === tab ? 'on' : ''}" onclick="switchTab('${t[0]}')"><i class="fas ${t[1]}"></i>${t[2]}</button>`).join('')}</div></div>${body}</div>`;
    async function boot(force) { if (!H.boot || force) H.boot = await api('bootstrap'); return H.boot; }
    async function open(tab) {
        css();
        const root = document.getElementById(tab + '-root');
        if (!root) return;
        try { await boot(); } catch (e) { root.innerHTML = `<div class="hl-card hl-empty"><i class="fas fa-lock"></i>${esc(e.message)}</div>`; return; }
        if (!can(tab)) { root.innerHTML = shell(tab, '<div class="hl-card hl-empty"><i class="fas fa-lock"></i>به این بخش دسترسی ندارید.</div>'); return; }
        ({'hl-dash': Dash, 'hl-contracts': Con, 'hl-pay': Pay, 'hl-settle': Settle, 'hl-settings': Sett})[tab].render(root);
    }
    function modal(title, body, foot, o = {}) {
        const ov = document.createElement('div');
        ov.className = 'hl hl-ov no-count'; ov.setAttribute('data-perm-skip', '');
        ov.innerHTML = `<div class="hl-md ${o.wide ? 'w' : ''}"><div class="hl-mh"><h3>${title}</h3><button class="hl-x" data-x><i class="fas fa-xmark"></i></button></div><div class="hl-mb">${body}</div>${foot ? `<div class="hl-mf">${foot}</div>` : ''}</div>`;
        const close = () => ov.remove();
        ov.addEventListener('mousedown', e => { ov._d = e.target === ov; });
        ov.addEventListener('click', e => { if (e.target === ov && ov._d) close(); });
        ov.querySelector('[data-x]').onclick = close;
        document.body.appendChild(ov);
        ov.close = close;
        if (window.MoneyInput) MoneyInput.apply(ov);
        return ov;
    }
    const conLabel = c => esc(c.company_name) + (c.policy_no ? ' - ' + fa(c.policy_no) : '') + (c.title ? ' (' + esc(c.title) + ')' : '');
    const conOpts = (sel, all) => (all ? `<option value="">${all}</option>` : '') + H.boot.contracts.map(c => `<option value="${c.id}" ${+sel === c.id ? 'selected' : ''}>${conLabel(c)}${c.status !== 'ACTIVE' ? ' - ' + esc(H.boot.statuses[c.status]) : ''}</option>`).join('');
    const methodOpts = sel => Object.entries(H.boot.settings.methods).map(([k, v]) => `<option value="${k}" ${sel === k ? 'selected' : ''}>${esc(v)}</option>`).join('');
    const payeeTag = p => `<span class="hl-tag ${p === 'INSURER' ? 'c' : 'b'}">${esc(PAYEE()[p])}</span>`;

    function barChart(box, cats, series, fmt, tip) {
        const W = Math.max(300, Math.round(box.clientWidth || 600)), Hh = 250, Lm = 62, R = 8, T = 12, B = 30, pw = W - Lm - R, ph = Hh - T - B, n = cats.length, slot = pw / n;
        const xAt = i => W - R - slot * (i + .5);
        const mx0 = Math.max(0, ...series.flatMap(s => s.values));
        const p = Math.pow(10, Math.floor(Math.log10(mx0 || 1))); const max = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].map(m => m * p).find(v => v >= mx0) || 1;
        let g = '';
        for (let i = 0; i <= 4; i++) { const y = T + ph - ph * i / 4; g += `<line x1="${Lm}" x2="${W - R}" y1="${y}" y2="${y}" stroke="${i ? '#eef2f0' : '#cfd8d4'}" ${i ? 'stroke-dasharray="3 4"' : ''}/><text x="${Lm - 6}" y="${y + 3}" text-anchor="end" font-size="9.5" fill="#94a3b8">${fmt(max * i / 4)}</text>`; }
        cats.forEach((c, i) => g += `<text x="${xAt(i)}" y="${Hh - B + 15}" text-anchor="middle" font-size="9.5" font-weight="700" fill="#64748b">${esc(c)}</text>`);
        const k = series.length, bw = Math.max(3, Math.min(18, (slot * .72 - 2 * (k - 1)) / k));
        let bars = '', hits = '';
        cats.forEach((c, i) => {
            const tot = bw * k + 2 * (k - 1), x0 = xAt(i) + tot / 2;
            series.forEach((s, si) => { const v = s.values[i] || 0, h = ph * Math.min(1, v / max); if (h < .5) return; const x = x0 - (si + 1) * bw - si * 2, y = T + ph - h, r = Math.min(4, bw / 2, h);
                bars += `<path d="M${x},${T + ph}V${y + r}Q${x},${y} ${x + r},${y}H${x + bw - r}Q${x + bw},${y} ${x + bw},${y + r}V${T + ph}Z" fill="${s.color}"/>`; });
            hits += `<rect x="${xAt(i) - slot / 2}" y="${T}" width="${slot}" height="${ph}" fill="transparent" data-i="${i}"/>`;
        });
        box.innerHTML = `<div class="hl-leg">${series.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('')}</div><div class="hl-chart"><svg viewBox="0 0 ${W} ${Hh}">${g}${bars}${hits}</svg><div class="hl-tip"></div></div>`;
        const ch = box.querySelector('.hl-chart'), tp = ch.querySelector('.hl-tip');
        ch.addEventListener('mousemove', e => {
            const r = e.target.closest && e.target.closest('rect[data-i]');
            ch.querySelectorAll('rect[data-i]').forEach(x => x.setAttribute('fill', x === r ? 'rgba(4,120,87,.06)' : 'transparent'));
            if (!r) { tp.style.opacity = 0; return; }
            tp.innerHTML = `<b>${esc(cats[+r.dataset.i])}</b><br>${tip(+r.dataset.i)}`;
            const bb = ch.getBoundingClientRect(); let x = e.clientX - bb.left + 14;
            tp.style.opacity = 1; if (x + tp.offsetWidth > bb.width) x = e.clientX - bb.left - tp.offsetWidth - 14;
            tp.style.left = Math.max(0, x) + 'px'; tp.style.top = Math.max(0, e.clientY - bb.top - 10) + 'px';
        });
        ch.addEventListener('mouseleave', () => { tp.style.opacity = 0; });
    }

    // =================================================================
    //  داشبورد
    // =================================================================
    const Dash = {
        async render(root) {
            const B = H.boot, f = H.dash;
            f.jy = f.jy || B.jy;
            const companies = [...new Set(B.contracts.map(c => c.company_name))];
            root.innerHTML = shell('hl-dash', `<div class="hl-card"><div class="hl-f">
                    <label><span>سال</span><select class="hl-sel" data-k="jy">${B.years.map(y => `<option value="${y}" ${+f.jy === y ? 'selected' : ''}>${fa(y)}</option>`).join('')}</select></label>
                    <label style="max-width:260px"><span>شرکت</span><select class="hl-sel" data-k="company"><option value="">همه‌ی شرکت‌ها</option>${companies.map(c => `<option ${f.company === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></label>
                    <label style="max-width:320px"><span>قرارداد</span><select class="hl-sel" data-k="contract_id">${conOpts(f.contract_id, 'همه‌ی قراردادها')}</select></label>
                    ${can('hl-pay', 'create') ? '<button class="hl-btn p" data-newpay><i class="fas fa-plus"></i>ثبتِ دریافت</button>' : ''}${can('hl-dash', 'export') ? '<button class="hl-btn o" data-xls><i class="fas fa-file-excel text-emerald-600"></i>اکسلِ معوقات</button>' : ''}</div></div>
                <div data-body><div class="hl-card hl-empty"><i class="fas fa-spinner fa-spin"></i>در حال بارگذاری…</div></div>`);
            root.querySelectorAll('[data-k]').forEach(el => el.onchange = () => { f[el.dataset.k] = el.value; this.load(root); });
            const np = root.querySelector('[data-newpay]'); if (np) np.onclick = () => Pay.add({contract_id: f.contract_id}, () => this.load(root));
            const x = root.querySelector('[data-xls]'); if (x) x.onclick = () => download(url('export', {kind: 'overdue', contract_id: f.contract_id || ''}));
            this.load(root);
        },
        async load(root) {
            const body = root.querySelector('[data-body]');
            let d;
            try { d = await api('dash', H.dash); } catch (e) { body.innerHTML = `<div class="hl-card hl-empty">${esc(e.message)}</div>`; return; }
            const k = d.kpi, P = PAYEE();
            const kp = (cls, ic, lab, v, s, bar) => `<div class="hl-kpi ${cls}"><div class="k"><i class="fas ${ic}"></i>${lab}</div><div class="v">${v}</div>${s ? `<div class="s">${s}</div>` : ''}${bar !== undefined ? `<div class="hl-bar"><i style="width:${Math.min(100, bar)}%"></i></div>` : ''}</div>`;
            body.innerHTML = `<div class="hl-kpis">
                ${kp('', 'fa-file-contract', 'قراردادهای فعال', fa(k.active) + ' <small class="text-[11px] text-slate-500">از ' + fa(k.contracts) + '</small>', fa(k.insured) + ' نفر بیمه‌شده')}
                ${kp('', 'fa-sack-dollar', 'جمعِ اقساط', short(k.inst_total), 'حق‌بیمه‌ی قراردادها: ' + short(k.premium))}
                ${kp('grn', 'fa-circle-check', 'وصول‌شده', short(k.paid), fa(k.collect_pct) + '٪ از سررسیدشده‌ها (' + short(k.due_to_date) + ')', k.collect_pct)}
                ${kp('red', 'fa-triangle-exclamation', 'معوق', short(k.overdue), fa(k.overdue_n) + ' قسطِ سررسیدگذشته')}
                ${kp('amb', 'fa-calendar-day', 'سررسیدِ ' + fa(H.boot.settings.due_soon_days) + ' روزِ آینده', short(k.soon), fa(k.soon_n) + ' قسط')}
                ${kp('', 'fa-hourglass-half', 'مانده‌ی کلِ اقساط', short(k.remaining))}
                ${kp('', 'fa-building', 'وصول به ' + esc(P.US), short(k.paid_us), 'وصول مستقیم به ' + esc(P.INSURER) + ': ' + short(k.paid_insurer))}
                ${kp(k.owed_insurer > 0 ? 'amb' : 'grn', 'fa-building-columns', 'مانده‌ی بدهیِ ما به ' + esc(P.INSURER), short(k.owed_insurer), 'پرداخت‌شده تا امروز: ' + short(k.settled))}
                ${kp('', 'fa-money-check', 'چک‌های در جریان', short(k.cheques_pending), fa(k.cheques_pending_n) + ' فقره')}
                ${k.commission ? kp('', 'fa-percent', 'کارمزدِ برآوردی', short(k.commission)) : ''}</div>
                <div class="hl-grid" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr)">
                    <div class="hl-card"><h3><i class="fas fa-chart-column text-emerald-600"></i>سررسید و وصولِ ماه به ماهِ ${fa(H.dash.jy)} <small>(ریال)</small></h3><div data-c1></div></div>
                    <div class="hl-card"><h3><i class="fas fa-building text-rose-600"></i>معوق به تفکیکِ شرکت</h3><div data-c2></div></div></div>
                <div class="hl-card"><h3><i class="fas fa-triangle-exclamation text-rose-600"></i>اقساطِ معوق <small>${fa(d.overdue.length)} قسط - بیشترین تأخیر بالا</small></h3>${this.table(d.overdue, true)}</div>
                <div class="hl-card"><h3><i class="fas fa-calendar-day text-amber-600"></i>سررسیدهای ${fa(H.boot.settings.due_soon_days)} روزِ آینده</h3>${this.table(d.soon, false)}</div>`;
            const m = d.months;
            barChart(body.querySelector('[data-c1]'), m.map(x => x.label), [{name: 'سررسیدِ اقساط', color: C1, values: m.map(x => x.due)}, {name: 'وصول به ' + P.US, color: C2, values: m.map(x => x.us)}, {name: 'وصول به ' + P.INSURER, color: C3, values: m.map(x => x.insurer)}],
                short, i => `<span class="sw" style="background:${C1}"></span>سررسید: ${money(m[i].due)}<br><span class="sw" style="background:${C2}"></span>وصول به ${esc(P.US)}: ${money(m[i].us)}<br><span class="sw" style="background:${C3}"></span>وصول به ${esc(P.INSURER)}: ${money(m[i].insurer)}<br>پرداختِ ما به ${esc(P.INSURER)}: ${money(m[i].settled)}`);
            const bc = d.by_company, mx = Math.max(1, ...bc.map(x => x.amount));
            body.querySelector('[data-c2]').innerHTML = bc.length ? `<div class="hl-hb">${bc.map(x => `<div class="r" title="${money(x.amount)} ریال"><span>${esc(x.name)}</span><span class="t"><i style="width:${Math.max(2, x.amount * 100 / mx)}%"></i></span><span class="m text-[11px]">${short(x.amount)}</span></div>`).join('')}</div>` : '<div class="hl-empty"><i class="fas fa-face-smile"></i>معوقی نیست.</div>';
            body.querySelectorAll('[data-pay]').forEach(b => b.onclick = () => Pay.add({contract_id: +b.dataset.pay, amount: +b.dataset.amt, payee: b.dataset.payee}, () => this.load(root)));
            body.querySelectorAll('[data-con]').forEach(b => b.onclick = () => Con.view(+b.dataset.con, () => this.load(root)));
        },
        table(rows, late) {
            if (!rows.length) return '<div class="hl-empty"><i class="fas fa-circle-check"></i>موردی نیست.</div>';
            const tot = rows.reduce((a, r) => a + r.remaining, 0);
            const act = r => can('hl-pay', 'create') ? `<button class="hl-btn s sm" data-pay="${r.contract_id}" data-amt="${r.remaining}" data-payee="${r.payee}"><i class="fas fa-plus"></i>دریافت</button>` : '';
            return `<div class="hl-tblwrap hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>شرکت</th><th>بیمه‌نامه</th><th>قسط</th><th>سررسید</th>${late ? '<th>تأخیر</th>' : ''}<th>مبلغ</th><th>مانده</th><th>پرداخت به</th><th></th></tr></thead><tbody>
                ${rows.map(r => `<tr><td><a href="#" class="font-bold text-emerald-700" data-con="${r.contract_id}" onclick="return false">${esc(r.company_name)}</a></td><td>${fa(r.policy_no || '—')}</td><td>${fa(r.seq)}</td><td>${fa(r.due_j)}</td>
                    ${late ? `<td><span class="hl-tag r">${fa(r.days_late)} روز</span></td>` : ''}<td class="m">${money(r.amount)}</td><td class="m text-rose-700">${money(r.remaining)}</td><td>${payeeTag(r.payee)}</td><td>${act(r)}</td></tr>`).join('')}</tbody>
                <tfoot><tr><td colspan="${late ? 6 : 5}">جمع</td><td class="m">${money(tot)}</td><td colspan="2"></td></tr></tfoot></table></div>
                <div class="hl-cards">${rows.map(r => `<div class="hl-pc"><div class="flex justify-between gap-2"><b data-con="${r.contract_id}">${esc(r.company_name)}</b>${late ? `<span class="hl-tag r">${fa(r.days_late)} روز تأخیر</span>` : `<span class="hl-tag a">${fa(r.due_j)}</span>`}</div>
                    <div class="text-[11.5px] text-slate-600 mt-1">قسطِ ${fa(r.seq)} - سررسید ${fa(r.due_j)} - ${payeeTag(r.payee)}</div><div class="flex justify-between items-center mt-2"><b class="text-rose-700">${money(r.remaining)} ریال</b>${act(r)}</div></div>`).join('')}</div>`;
        },
    };

    // =================================================================
    //  قراردادها و اقساط
    // =================================================================
    const Con = {
        async render(root) {
            const f = H.con;
            root.innerHTML = shell('hl-contracts', `<div class="hl-card"><div class="hl-f">
                    <label style="max-width:none;flex:2 1 220px"><span>جستجو (شرکت، شماره بیمه‌نامه، رابط)</span><input class="hl-in" data-k="q" value="${esc(f.q)}"></label>
                    <label><span>وضعیت</span><select class="hl-sel" data-k="status"><option value="">همه</option>${Object.entries(H.boot.statuses).map(([a, b]) => `<option value="${a}" ${f.status === a ? 'selected' : ''}>${b}</option>`).join('')}</select></label>
                    ${can('hl-contracts', 'create') ? '<button class="hl-btn p" data-new><i class="fas fa-plus"></i>قراردادِ جدید</button>' : ''}${can('hl-contracts', 'export') ? '<button class="hl-btn o" data-xls><i class="fas fa-file-excel text-emerald-600"></i>اکسلِ قراردادها</button><button class="hl-btn o" data-xls2><i class="fas fa-file-excel text-emerald-600"></i>اکسلِ همه‌ی اقساط</button>' : ''}
                </div></div><div class="hl-card" data-list><div class="hl-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            let tm;
            root.querySelectorAll('[data-k]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => { clearTimeout(tm); tm = setTimeout(() => { f[el.dataset.k] = el.value; this.load(root); }, 300); }));
            const n = root.querySelector('[data-new]'); if (n) n.onclick = () => this.edit(null, () => this.load(root));
            const x = root.querySelector('[data-xls]'); if (x) x.onclick = () => download(url('export', {kind: 'contracts'}));
            const x2 = root.querySelector('[data-xls2]'); if (x2) x2.onclick = () => download(url('export', {kind: 'installments'}));
            this.load(root);
        },
        async load(root) {
            const box = root.querySelector('[data-list]');
            let r;
            try { r = await api('contracts_list', H.con); } catch (e) { box.innerHTML = `<div class="hl-empty">${esc(e.message)}</div>`; return; }
            if (!r.rows.length) { box.innerHTML = '<div class="hl-empty"><i class="fas fa-file-contract"></i>قراردادی ثبت نشده. با «قراردادِ جدید» شروع کنید.</div>'; return; }
            box.innerHTML = `<h3><i class="fas fa-file-contract text-emerald-600"></i>قراردادها <small>${fa(r.rows.length)} قرارداد</small></h3><div class="hl-grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
                ${r.rows.map(c => { const s = c.sum; return `<div class="hl-row" style="background:#fff"><div class="flex justify-between gap-2 items-start"><div class="min-w-0"><b class="text-[14px]">${esc(c.company_name)}</b>
                    <div class="text-[11px] text-slate-500">${c.policy_no ? 'بیمه‌نامه ' + fa(c.policy_no) + ' · ' : ''}${esc(c.insurer)}${c.title ? ' · ' + esc(c.title) : ''}</div></div>
                    <span class="hl-tag ${c.status === 'ACTIVE' ? 'g' : 's'}">${esc(H.boot.statuses[c.status])}</span></div>
                    <div class="text-[11px] text-slate-600">${c.start_j ? fa(c.start_j) + ' تا ' + fa(c.end_j || '…') + ' · ' : ''}${fa(c.insured_count)} نفر · حق‌بیمه ${short(c.premium)}</div>
                    <div class="flex justify-between text-[11.5px]"><span>پرداخت‌شده <b>${short(s.paid)}</b> از ${short(s.total)}</span><b>${fa(s.pct)}٪</b></div><div class="hl-bar" style="margin-top:0"><i style="width:${s.pct}%"></i></div>
                    <div class="flex flex-wrap gap-1">${s.overdue ? `<span class="hl-tag r"><i class="fas fa-triangle-exclamation"></i>معوق ${short(s.overdue)} (${fa(s.overdue_n)} قسط)</span>` : '<span class="hl-tag g">بدونِ معوق</span>'}${s.next_due_j ? `<span class="hl-tag a">قسطِ بعد ${fa(s.next_due_j)}: ${short(s.next_amount)}</span>` : ''}
                        <span class="hl-tag b">به ما ${short(s.paid_us)}</span><span class="hl-tag c">به بیمه‌گر ${short(s.paid_insurer)}</span></div>
                    <div class="flex gap-1 flex-wrap"><button class="hl-btn s sm" data-v="${c.id}"><i class="fas fa-eye"></i>اقساط و دریافت‌ها</button>${can('hl-pay', 'create') ? `<button class="hl-btn s sm" data-p="${c.id}"><i class="fas fa-plus"></i>دریافت</button>` : ''}${can('hl-contracts', 'edit') ? `<button class="hl-btn s sm" data-e="${c.id}"><i class="fas fa-pen"></i>ویرایش</button>` : ''}</div></div>`; }).join('')}</div>`;
            box.querySelectorAll('[data-v]').forEach(b => b.onclick = () => this.view(+b.dataset.v, () => this.load(root)));
            box.querySelectorAll('[data-p]').forEach(b => b.onclick = () => Pay.add({contract_id: +b.dataset.p}, () => this.load(root)));
            box.querySelectorAll('[data-e]').forEach(b => b.onclick = () => this.edit(+b.dataset.e, () => this.load(root)));
        },
        async view(id, onChange) {
            let d;
            try { d = await api('contract_get', {id}); } catch (e) { toast(e.message, 'error'); return; }
            const c = d.contract, s = d.sum, P = PAYEE();
            const m = modal(`<i class="fas fa-file-contract text-emerald-600"></i>${esc(c.company_name)}${c.policy_no ? ' - بیمه‌نامه ' + fa(c.policy_no) : ''}`, `
                <div class="hl-kpis mb-3">${[['جمعِ اقساط', short(s.total)], ['پرداخت‌شده', short(s.paid) + ' (' + fa(s.pct) + '٪)'], ['مانده', short(s.remaining)], ['معوق', short(s.overdue)], ['وصول به ' + P.US, short(s.paid_us)], ['وصول به ' + P.INSURER, short(s.paid_insurer)]]
                    .map(([a, b], i) => `<div class="hl-kpi ${i === 3 && s.overdue ? 'red' : ''}"><div class="k">${esc(a)}</div><div class="v" style="font-size:16px">${b}</div></div>`).join('')}</div>
                <div class="text-[11.5px] text-slate-600 mb-3">${esc(c.insurer)} · ${c.start_j ? fa(c.start_j) + ' تا ' + fa(c.end_j || '…') : ''} · ${fa(c.insured_count)} نفر · حق‌بیمه ${money(c.premium)} ریال${c.commission_pct ? ' · کارمزد ' + fa(c.commission_pct) + '٪' : ''}${c.contact_name ? ' · رابط: ' + esc(c.contact_name) + ' ' + fa(c.contact_mobile || '') : ''}</div>
                <div class="hl-card mb-3" style="box-shadow:none"><h3><i class="fas fa-list-ol text-emerald-600"></i>اقساط</h3><div class="hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>#</th><th>سررسید</th><th>مبلغ</th><th>پرداخت‌شده</th><th>مانده</th><th>پرداخت به</th><th>وضعیت</th><th>توضیح</th></tr></thead><tbody>
                    ${d.installments.map(i => `<tr><td>${fa(i.seq)}</td><td>${fa(i.due_j)}</td><td class="m">${money(i.amount)}</td><td class="m text-emerald-700">${money(i.paid)}</td><td class="m ${i.remaining ? 'text-rose-700' : ''}">${money(i.remaining)}</td><td>${payeeTag(i.payee)}</td>
                        <td><span class="hl-tag ${ST_C[i.status]}">${ST_FA[i.status]}${i.days_late ? ' - ' + fa(i.days_late) + ' روز' : ''}</span></td><td class="text-[11px]">${esc(i.note || '')}</td></tr>`).join('') || '<tr><td colspan="8" class="hl-empty">قسطی تعریف نشده.</td></tr>'}</tbody></table></div></div>
                <div class="hl-card" style="box-shadow:none"><h3><i class="fas fa-hand-holding-dollar text-emerald-600"></i>دریافت‌ها</h3>${Pay.tableHtml(d.payments, false)}</div>`,
                `${can('hl-contracts', 'export') ? '<button class="hl-btn o" data-xi><i class="fas fa-file-excel text-emerald-600"></i>اکسلِ اقساط</button>' : ''}${can('hl-contracts', 'delete') ? '<button class="hl-btn r" data-del><i class="fas fa-trash"></i>حذفِ قرارداد</button>' : ''}${can('hl-contracts', 'edit') ? '<button class="hl-btn s" data-ed><i class="fas fa-pen"></i>ویرایش و اقساط</button>' : ''}${can('hl-pay', 'create') ? '<button class="hl-btn p" data-pay><i class="fas fa-plus"></i>ثبتِ دریافت</button>' : ''}`, {wide: true});
            const reload = () => { m.close(); if (onChange) onChange(); this.view(id, onChange); };
            Pay.bindTable(m, reload);
            const xi = m.querySelector('[data-xi]'); if (xi) xi.onclick = () => download(url('export', {kind: 'installments', contract_id: id}));
            const ed = m.querySelector('[data-ed]'); if (ed) ed.onclick = () => { m.close(); this.edit(id, () => { if (onChange) onChange(); this.view(id, onChange); }); };
            const pb = m.querySelector('[data-pay]'); if (pb) pb.onclick = () => Pay.add({contract_id: id}, reload);
            const dl = m.querySelector('[data-del]'); if (dl) dl.onclick = async () => {
                if (!(await confirmUi('حذفِ قرارداد', 'این قرارداد و اقساطش از فهرست حذف شود؟', {danger: true}))) return;
                try { await api('contract_delete', {id}); } catch (e) {
                    if (!(await confirmUi('دریافت دارد', e.message, {danger: true}))) return;
                    try { await api('contract_delete', {id, force: 1}); } catch (e2) { toast(e2.message, 'error'); return; }
                }
                m.close(); toast('قرارداد حذف شد.', 'success'); await boot(true); if (onChange) onChange();
            };
        },
        async edit(id, onDone) {
            const B = H.boot, S = B.settings;
            let c = {company_name: '', insurer: S.default_insurer, status: 'ACTIVE', commission_pct: S.default_commission, insured_count: 0, premium: 0}, inst = [];
            if (id) { try { const d = await api('contract_get', {id}); c = d.contract; inst = d.installments; } catch (e) { toast(e.message, 'error'); return; } }
            const fld = (k, l, v, x = '') => `<label><span class="hl-lbl">${l}</span><input class="hl-in" data-f="${k}" value="${esc(v ?? '')}" ${x}></label>`;
            const m = modal(`<i class="fas fa-file-contract text-emerald-600"></i>${id ? 'ویرایشِ قرارداد' : 'قراردادِ جدیدِ درمان تکمیلی'}`, `
                <div class="hl-grid hl-g3">
                    <label><span class="hl-lbl">شرکت (بیمه‌گذار)</span><input class="hl-in" list="hl-co" data-f="company_name" value="${esc(c.company_name)}" placeholder="نامِ شرکت"><datalist id="hl-co">${B.companies.map(x => `<option value="${esc(x.name)}">`).join('')}</datalist></label>
                    ${fld('policy_no', 'شماره‌ی بیمه‌نامه', fa(c.policy_no || ''), 'dir="ltr"')}${fld('title', 'عنوان (اختیاری)', c.title, 'placeholder="مثلاً درمانِ کارکنان ۱۴۰۵"')}
                    <label><span class="hl-lbl">بیمه‌گر</span><input class="hl-in" list="hl-ins" data-f="insurer" value="${esc(c.insurer)}"><datalist id="hl-ins">${(S.insurers || []).map(x => `<option value="${esc(x)}">`).join('')}</datalist></label>
                    ${fld('start_j', 'شروع', fa(c.start_j || ''), 'data-jdate dir="ltr"')}${fld('end_j', 'پایان', fa(c.end_j || ''), 'data-jdate dir="ltr"')}
                    ${fld('insured_count', 'تعدادِ بیمه‌شده', fa(c.insured_count || ''))}<label><span class="hl-lbl">حق‌بیمه‌ی کل (ریال)</span><input class="hl-in money-input" data-f="premium" value="${c.premium ? money(c.premium) : ''}"></label>
                    ${fld('commission_pct', 'کارمزد٪ (برآوردی)', fa(c.commission_pct || ''))}
                    ${fld('contact_name', 'رابطِ شرکت', c.contact_name)}${fld('contact_mobile', 'موبایلِ رابط', fa(c.contact_mobile || ''), 'dir="ltr"')}
                    <label><span class="hl-lbl">وضعیت</span><select class="hl-sel" data-f="status">${Object.entries(B.statuses).map(([a, b]) => `<option value="${a}" ${c.status === a ? 'selected' : ''}>${b}</option>`).join('')}</select></label></div>
                <label class="block mt-2"><span class="hl-lbl">توضیح</span><textarea class="hl-ta" rows="2" data-f="note">${esc(c.note || '')}</textarea></label>
                <div class="hl-row mt-3"><b class="text-emerald-800"><i class="fas fa-wand-magic-sparkles"></i> ساختِ خودکارِ اقساط</b><div class="hl-grid hl-g4">
                    <label><span class="hl-lbl">تعدادِ قسط</span><input class="hl-in" data-g="n" value="${fa(inst.length || 12)}"></label>
                    <label><span class="hl-lbl">سررسیدِ اولین قسط</span><input class="hl-in" data-g="first" data-jdate dir="ltr" value="${fa(inst[0] ? inst[0].due_j : (c.start_j || B.today_j))}"></label>
                    <label><span class="hl-lbl">فاصله</span><select class="hl-sel" data-g="gap"><option value="1">ماهانه</option><option value="2">دوماهه</option><option value="3">سه‌ماهه</option><option value="6">شش‌ماهه</option></select></label>
                    <label><span class="hl-lbl">جمعِ مبلغ (ریال)</span><input class="hl-in money-input" data-g="total" value="${c.premium ? money(c.premium) : ''}"></label>
                    <label><span class="hl-lbl">پیش‌پرداخت (قسطِ اول، اختیاری)</span><input class="hl-in money-input" data-g="down"></label>
                    <label><span class="hl-lbl">پرداخت به</span><select class="hl-sel" data-g="payee"><option value="US" ${S.default_payee === 'US' ? 'selected' : ''}>${esc(PAYEE().US)}</option><option value="INSURER" ${S.default_payee === 'INSURER' ? 'selected' : ''}>${esc(PAYEE().INSURER)}</option></select></label>
                    <label><span class="hl-lbl">گرد کردن به</span><select class="hl-sel" data-g="round"><option value="1000">هزار ریال</option><option value="10000">ده هزار ریال</option><option value="100000">صد هزار ریال</option><option value="1">بدونِ گرد کردن</option></select></label>
                    <div style="display:flex;align-items:flex-end"><button class="hl-btn a" data-gen style="width:100%"><i class="fas fa-bolt"></i>ساختِ اقساط</button></div></div>
                    <small class="text-slate-500">مانده‌ی گرد کردن به قسطِ آخر اضافه می‌شود. هر ردیف را بعداً می‌توانید جداگانه تغییر دهید.</small></div>
                <div class="flex items-center justify-between mt-3 mb-2"><b class="text-[13px]">اقساط</b><span data-tot class="text-[11.5px] font-bold"></span></div>
                <div data-rows class="flex flex-col gap-2"></div><button class="hl-btn s sm mt-2" data-add><i class="fas fa-plus"></i>افزودنِ قسط</button>`,
                '<button class="hl-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>', {wide: true});
            const rowsBox = m.querySelector('[data-rows]');
            const rowHtml = (r, i) => `<div class="hl-irow" data-r="${r.id || ''}"><b class="text-center text-slate-500">${fa(i + 1)}</b>
                <input class="hl-in" data-c="due_j" data-jdate dir="ltr" value="${esc(fa(r.due_j || ''))}" placeholder="سررسید"><input class="hl-in money-input" data-c="amount" value="${r.amount ? money(r.amount) : ''}" placeholder="مبلغ (ریال)">
                <select class="hl-sel" data-c="payee"><option value="US" ${r.payee !== 'INSURER' ? 'selected' : ''}>${esc(PAYEE().US)}</option><option value="INSURER" ${r.payee === 'INSURER' ? 'selected' : ''}>${esc(PAYEE().INSURER)}</option></select>
                <input class="hl-in w2" data-c="note" value="${esc(r.note || '')}" placeholder="توضیح${r.paid ? ' (پرداخت‌شده: ' + money(r.paid) + ')' : ''}"><button class="hl-btn r sm" data-rm ${r.paid ? 'disabled title="این قسط پرداخت دارد"' : ''}><i class="fas fa-xmark"></i></button></div>`;
            const read = () => [...rowsBox.querySelectorAll('[data-r]')].map(r => ({id: +r.dataset.r || 0, due_j: en(r.querySelector('[data-c=due_j]').value), amount: num(r.querySelector('[data-c=amount]').value), payee: r.querySelector('[data-c=payee]').value, note: r.querySelector('[data-c=note]').value,
                paid: +(r.dataset.paid || 0)}));
            const draw = rows => {
                rowsBox.innerHTML = rows.map(rowHtml).join('');
                rows.forEach((r, i) => { if (r.paid) rowsBox.children[i].dataset.paid = r.paid; });
                rowsBox.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { const all = read(); all.splice([...rowsBox.children].indexOf(b.closest('[data-r]')), 1); draw(all); });
                rowsBox.querySelectorAll('[data-c=amount]').forEach(i => i.addEventListener('input', tot));
                if (window.MoneyInput) MoneyInput.apply(rowsBox);
                tot();
            };
            const tot = () => { const t = read().reduce((a, r) => a + r.amount, 0), p = num(m.querySelector('[data-f=premium]').value);
                m.querySelector('[data-tot]').innerHTML = `جمع: ${money(t)} ریال${p && t !== p ? ` <span class="hl-tag a">با حق‌بیمه ${money(Math.abs(p - t))} ${t > p ? 'بیشتر' : 'کمتر'}</span>` : (p ? ' <span class="hl-tag g">برابر با حق‌بیمه</span>' : '')}`; };
            draw(inst.length ? inst : []);
            m.querySelector('[data-f=premium]').addEventListener('input', () => { m.querySelector('[data-g=total]').value = m.querySelector('[data-f=premium]').value; tot(); });
            m.querySelector('[data-add]').onclick = () => { const all = read(); const last = all[all.length - 1]; all.push({due_j: last && last.due_j ? addMonths(last.due_j, 1) : B.today_j, amount: last ? last.amount : 0, payee: last ? last.payee : S.default_payee}); draw(all); };
            m.querySelector('[data-gen]').onclick = async () => {
                const g = k => m.querySelector(`[data-g=${k}]`).value;
                const n = Math.max(1, Math.min(60, +en(g('n')) || 1)), first = en(g('first')), gap = +g('gap'), total = num(g('total')), down = num(g('down')), round = +g('round'), payee = g('payee');
                if (!/^1[34]\d\d\/\d\d?\/\d\d?$/.test(first)) { toast('سررسیدِ اولین قسط را درست وارد کنید.', 'error'); return; }
                if (!total) { toast('جمعِ مبلغ را وارد کنید.', 'error'); return; }
                if (read().some(r => r.paid)) { toast('بعضی اقساط پرداخت دارند؛ ساختِ دوباره ممکن نیست. ردیف‌ها را دستی ویرایش کنید.', 'error'); return; }
                if (read().length && !(await confirmUi('ساختِ اقساط', 'اقساطِ فعلیِ فرم جایگزین شوند؟'))) return;
                const rows = []; let rest = total, k = 0;
                if (down && n > 1) { rows.push({due_j: first, amount: down, payee}); rest -= down; k = 1; }
                const cnt = n - rows.length, each = Math.floor(rest / cnt / round) * round;
                for (let i = 0; i < cnt; i++) rows.push({due_j: addMonths(first, (k + i) * gap), amount: i === cnt - 1 ? rest - each * (cnt - 1) : each, payee});
                draw(rows);
            };
            m.querySelector('[data-save]').onclick = async e => {
                const v = k => m.querySelector(`[data-f=${k}]`).value;
                const body = {id: id || 0, company_name: v('company_name'), policy_no: en(v('policy_no')), title: v('title'), insurer: v('insurer'), start_j: en(v('start_j')), end_j: en(v('end_j')), insured_count: en(v('insured_count')),
                    premium: num(v('premium')), commission_pct: en(v('commission_pct')), contact_name: v('contact_name'), contact_mobile: en(v('contact_mobile')), status: v('status'), note: v('note'), installments: read()};
                busy(e.currentTarget, true);
                try { await api('contract_save', body); m.close(); toast('قرارداد ذخیره شد.', 'success'); await boot(true); if (onDone) onDone(); }
                catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
    };
    // ماهِ شمسی + n
    function addMonths(j, n) {
        let [y, m, d] = j.split('/').map(Number);
        m += n; while (m > 12) { m -= 12; y++; } while (m < 1) { m += 12; y--; }
        const max = m <= 6 ? 31 : (m <= 11 ? 30 : 29);
        return `${y}/${String(m).padStart(2, '0')}/${String(Math.min(d, max)).padStart(2, '0')}`;
    }

    // =================================================================
    //  دریافت‌ها
    // =================================================================
    const Pay = {
        async render(root) {
            const f = H.pay, B = H.boot;
            root.innerHTML = shell('hl-pay', `<div class="hl-card"><div class="hl-f">
                    <label style="max-width:none;flex:2 1 200px"><span>جستجو (شرکت، پیگیری، چک)</span><input class="hl-in" data-k="q" value="${esc(f.q || '')}"></label>
                    <label style="max-width:300px"><span>قرارداد</span><select class="hl-sel" data-k="contract_id">${conOpts(f.contract_id, 'همه')}</select></label>
                    <label><span>دریافت‌کننده</span><select class="hl-sel" data-k="payee"><option value="">همه</option>${Object.entries(PAYEE()).map(([a, b]) => `<option value="${a}" ${f.payee === a ? 'selected' : ''}>${esc(b)}</option>`).join('')}</select></label>
                    <label><span>روش</span><select class="hl-sel" data-k="method"><option value="">همه</option>${methodOpts(f.method)}</select></label>
                    <label><span>از تاریخ</span><input class="hl-in" data-k="from_j" data-jdate dir="ltr" value="${esc(fa(f.from_j || ''))}"></label><label><span>تا تاریخ</span><input class="hl-in" data-k="to_j" data-jdate dir="ltr" value="${esc(fa(f.to_j || ''))}"></label>
                    <label class="flex-row items-center gap-1" style="flex-direction:row;max-width:none;flex:0 0 auto;padding-bottom:8px"><input type="checkbox" data-k="show_void" ${f.show_void ? 'checked' : ''}><span>باطل‌شده‌ها هم</span></label>
                    ${can('hl-pay', 'create') ? '<button class="hl-btn p" data-new><i class="fas fa-plus"></i>ثبتِ دریافت</button>' : ''}${can('hl-pay', 'export') ? '<button class="hl-btn o" data-xls><i class="fas fa-file-excel text-emerald-600"></i>اکسل</button>' : ''}</div></div>
                <div class="hl-card" data-list><div class="hl-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            let tm;
            root.querySelectorAll('[data-k]').forEach(el => el.addEventListener(el.tagName === 'SELECT' || el.type === 'checkbox' || el.dataset.jdate !== undefined ? 'change' : 'input', () => { clearTimeout(tm); tm = setTimeout(() => { f[el.dataset.k] = el.type === 'checkbox' ? (el.checked ? 1 : '') : (el.dataset.jdate !== undefined ? en(el.value) : el.value); this.load(root); }, 300); }));
            const n = root.querySelector('[data-new]'); if (n) n.onclick = () => this.add({contract_id: f.contract_id}, () => this.load(root));
            const x = root.querySelector('[data-xls]'); if (x) x.onclick = () => download(url('export', {kind: 'payments'}));
            this.load(root);
        },
        async load(root) {
            const box = root.querySelector('[data-list]');
            let r;
            try { r = await api('pay_list', H.pay); } catch (e) { box.innerHTML = `<div class="hl-empty">${esc(e.message)}</div>`; return; }
            const P = PAYEE();
            box.innerHTML = `<h3><i class="fas fa-hand-holding-dollar text-emerald-600"></i>دریافت‌ها <small>${fa(r.rows.length)} مورد - به ${esc(P.US)}: ${money(r.totals.US)} · مستقیم به ${esc(P.INSURER)}: ${money(r.totals.INSURER)} ریال</small></h3>${this.tableHtml(r.rows, true)}`;
            this.bindTable(box, () => this.load(root));
        },
        tableHtml(rows, withCo) {
            if (!rows.length) return '<div class="hl-empty"><i class="fas fa-receipt"></i>دریافتی ثبت نشده.</div>';
            const act = p => `${p.has_file ? `<a class="hl-btn s sm" href="${url('pay_file', {id: p.id})}" target="_blank" title="رسید"><i class="fas fa-paperclip"></i></a>` : ''}${p.status === 'OK' && can('hl-pay', 'edit') ? `<button class="hl-btn s sm" data-pe='${esc(JSON.stringify(p))}' title="ویرایش"><i class="fas fa-pen"></i></button>` : ''}${p.status === 'OK' && can('hl-pay', 'delete') ? `<button class="hl-btn r sm" data-pv="${p.id}" title="ابطال"><i class="fas fa-ban"></i></button>` : ''}`;
            const det = p => [p.ref_no ? 'پیگیری ' + fa(p.ref_no) : '', p.bank ? esc(p.bank) : '', p.cheque_no ? 'چک ' + fa(p.cheque_no) + (p.cheque_due_j ? ' - ' + fa(p.cheque_due_j) : '') : ''].filter(Boolean).join(' · ');
            return `<div class="hl-tblwrap hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>تاریخ</th>${withCo ? '<th>شرکت</th>' : ''}<th>مبلغ</th><th>روش</th><th>دریافت‌کننده</th><th>جزئیات</th><th>وضعیت</th><th>ثبت‌کننده</th><th></th></tr></thead><tbody>
                ${rows.map(p => `<tr class="${p.status !== 'OK' ? 'void' : ''}"><td>${fa(p.pay_j)}</td>${withCo ? `<td><b>${esc(p.company_name)}</b>${p.policy_no ? `<div class="text-[10.5px] text-slate-500">${fa(p.policy_no)}</div>` : ''}</td>` : ''}<td class="m">${money(p.amount)}</td><td>${esc(p.method_fa)}</td><td>${payeeTag(p.payee)}</td>
                    <td class="text-[11px]">${det(p) || '—'}${p.note ? `<div class="text-slate-500">${esc(p.note)}</div>` : ''}</td><td>${p.status === 'OK' ? (p.cheque_status ? `<span class="hl-tag ${p.cheque_status === 'CLEARED' ? 'g' : 'a'}">چک: ${esc(p.cheque_status_fa)}</span>` : '<span class="hl-tag g">معتبر</span>') : `<span class="hl-tag r">باطل${p.void_reason ? ' - ' + esc(p.void_reason) : ''}</span>`}</td>
                    <td class="text-[11px]">${esc(p.by)}<div class="text-slate-400">${fa(p.created_at_j)}</div></td><td class="whitespace-nowrap">${act(p)}</td></tr>`).join('')}</tbody></table></div>
                <div class="hl-cards">${rows.map(p => `<div class="hl-pc ${p.status !== 'OK' ? 'opacity-50' : ''}"><div class="flex justify-between"><b>${money(p.amount)} ریال</b><span class="text-[11px]">${fa(p.pay_j)}</span></div>${withCo ? `<div class="text-[12px] font-bold mt-1">${esc(p.company_name)}</div>` : ''}
                    <div class="flex flex-wrap gap-1 mt-1">${payeeTag(p.payee)}<span class="hl-tag">${esc(p.method_fa)}</span>${p.status !== 'OK' ? '<span class="hl-tag r">باطل</span>' : ''}</div><div class="text-[11px] text-slate-500 mt-1">${det(p)}</div><div class="flex gap-1 mt-2">${act(p)}</div></div>`).join('')}</div>`;
        },
        bindTable(box, reload) {
            box.querySelectorAll('[data-pv]').forEach(b => b.onclick = async () => {
                const m = modal('<i class="fas fa-ban text-rose-600"></i>ابطالِ دریافت', '<label><span class="hl-lbl">دلیل</span><input class="hl-in" data-r placeholder="مثلاً ثبتِ اشتباه"></label><p class="text-[11.5px] text-slate-500 mt-2">با ابطال، تخصیصش از اقساط برداشته می‌شود و اقساط دوباره باز می‌شوند.</p>', '<button class="hl-btn r" data-ok>ابطال</button>');
                m.querySelector('[data-ok]').onclick = async () => { try { await api('pay_void', {id: b.dataset.pv, reason: m.querySelector('[data-r]').value}); m.close(); toast('باطل شد.', 'success'); reload(); } catch (e) { toast(e.message, 'error'); } };
            });
            box.querySelectorAll('[data-pe]').forEach(b => b.onclick = () => {
                const p = JSON.parse(b.dataset.pe);
                const m = modal('<i class="fas fa-pen text-emerald-600"></i>ویرایشِ دریافت', `<div class="hl-grid hl-g2"><label><span class="hl-lbl">شماره‌ی پیگیری</span><input class="hl-in" data-k="ref_no" value="${esc(fa(p.ref_no || ''))}"></label><label><span class="hl-lbl">بانک</span><input class="hl-in" data-k="bank" value="${esc(p.bank || '')}"></label></div>
                    ${p.method === 'cheque' ? `<label class="block mt-2"><span class="hl-lbl">وضعیتِ چک</span><select class="hl-sel" data-k="cheque_status">${Object.entries(H.boot.chq).map(([a, b2]) => `<option value="${a}" ${p.cheque_status === a ? 'selected' : ''}>${b2}</option>`).join('')}</select><small class="text-rose-600">«برگشتی» دریافت را باطل و اقساط را دوباره باز می‌کند.</small></label>` : ''}
                    <label class="block mt-2"><span class="hl-lbl">توضیح</span><input class="hl-in" data-k="note" value="${esc(p.note || '')}"></label><p class="text-[11px] text-slate-500 mt-2">برای تغییرِ مبلغ یا تاریخ، دریافت را باطل و دوباره ثبت کنید.</p>`, '<button class="hl-btn p" data-ok>ذخیره</button>');
                m.querySelector('[data-ok]').onclick = async () => { const v = k => { const x = m.querySelector(`[data-k=${k}]`); return x ? x.value : ''; };
                    try { await api('pay_update', {id: p.id, ref_no: en(v('ref_no')), bank: v('bank'), note: v('note'), cheque_status: v('cheque_status')}); m.close(); toast('ذخیره شد.', 'success'); reload(); } catch (e) { toast(e.message, 'error'); } };
            });
        },
        async add(o, onDone) {
            const B = H.boot;
            if (!B.contracts.length) { toast('اول یک قرارداد ثبت کنید.', 'error'); return; }
            const m = modal('<i class="fas fa-hand-holding-dollar text-emerald-600"></i>ثبتِ دریافتِ درمان تکمیلی', `
                <div class="hl-grid hl-g2"><label style="grid-column:1/-1"><span class="hl-lbl">قرارداد</span><select class="hl-sel" data-f="contract_id">${conOpts(o.contract_id || '', '— انتخاب کنید —')}</select></label>
                    <label><span class="hl-lbl">تاریخِ دریافت</span><input class="hl-in" data-f="pay_j" data-jdate dir="ltr" value="${fa(B.today_j)}"></label>
                    <label><span class="hl-lbl">مبلغ (ریال)</span><input class="hl-in money-input" data-f="amount" value="${o.amount ? money(o.amount) : ''}"></label>
                    <div style="grid-column:1/-1"><span class="hl-lbl">به چه کسی پرداخت شده؟</span><div class="hl-tg" data-payee><button data-v="US">${esc(PAYEE().US)}</button><button data-v="INSURER">مستقیم به ${esc(PAYEE().INSURER)}</button></div></div>
                    <label><span class="hl-lbl">روش</span><select class="hl-sel" data-f="method">${methodOpts('transfer')}</select></label>
                    <label><span class="hl-lbl">شماره‌ی پیگیری / فیش</span><input class="hl-in" data-f="ref_no" dir="ltr"></label>
                    <label><span class="hl-lbl">بانک</span><input class="hl-in" data-f="bank"></label>
                    <label data-chq style="display:none"><span class="hl-lbl">شماره‌ی چک</span><input class="hl-in" data-f="cheque_no" dir="ltr"></label>
                    <label data-chq style="display:none"><span class="hl-lbl">سررسیدِ چک</span><input class="hl-in" data-f="cheque_due_j" data-jdate dir="ltr"></label>
                    <label style="grid-column:1/-1"><span class="hl-lbl">توضیح</span><input class="hl-in" data-f="note"></label>
                    <label style="grid-column:1/-1"><span class="hl-lbl">تصویرِ رسید / فیش (اختیاری)</span><input type="file" class="hl-in" data-file accept=".pdf,.jpg,.jpeg,.png,.webp"></label></div>
                <div class="hl-row mt-3"><div class="flex justify-between items-center"><b class="text-emerald-800"><i class="fas fa-list-check"></i> تخصیص به اقساط</b><label class="text-[11.5px] font-bold flex items-center gap-1"><input type="checkbox" data-manual>تخصیصِ دستی</label></div><div data-alloc class="text-[11.5px]"></div></div>`,
                '<button class="hl-btn p" data-save><i class="fas fa-floppy-disk"></i>ثبت</button>');
            let payee = o.payee || B.settings.default_payee, inst = [];
            const setPayee = v => { payee = v; m.querySelectorAll('[data-payee] button').forEach(b => b.classList.toggle('on', b.dataset.v === v)); drawAlloc(); };
            m.querySelectorAll('[data-payee] button').forEach(b => b.onclick = () => setPayee(b.dataset.v));
            const meth = m.querySelector('[data-f=method]');
            meth.onchange = () => m.querySelectorAll('[data-chq]').forEach(x => x.style.display = meth.value === 'cheque' ? '' : 'none');
            const amountEl = m.querySelector('[data-f=amount]');
            const drawAlloc = () => {
                const box = m.querySelector('[data-alloc]');
                const open = inst.filter(i => i.remaining > 0);
                if (!open.length) { box.innerHTML = '<div class="text-slate-500">قسطِ بازی نیست؛ مبلغ به‌عنوانِ بستانکاری ثبت می‌شود.</div>'; return; }
                const manual = m.querySelector('[data-manual]').checked;
                let left = num(amountEl.value);
                const order = [...open].sort((a, b) => (a.payee === payee ? 0 : 1) - (b.payee === payee ? 0 : 1) || String(a.due_g).localeCompare(String(b.due_g)));
                const auto = {}; order.forEach(i => { const a = Math.min(left, i.remaining); if (a > 0) { auto[i.id] = a; left -= a; } });
                box.innerHTML = `<table class="hl-tbl no-count"><thead><tr><th>قسط</th><th>سررسید</th><th>مانده</th><th>پرداخت به</th><th>${manual ? 'مبلغِ تخصیص' : 'تخصیصِ خودکار'}</th></tr></thead><tbody>${open.map(i => `<tr style="cursor:default"><td>${fa(i.seq)}</td><td>${fa(i.due_j)} ${i.status === 'OVERDUE' ? '<span class="hl-tag r">معوق</span>' : ''}</td><td class="m">${money(i.remaining)}</td><td>${payeeTag(i.payee)}</td>
                    <td>${manual ? `<input class="hl-in money-input" data-al="${i.id}" value="${auto[i.id] ? money(auto[i.id]) : ''}" style="max-width:150px">` : `<b class="text-emerald-700">${auto[i.id] ? money(auto[i.id]) : '—'}</b>`}</td></tr>`).join('')}</tbody></table>${left > 0 ? `<div class="mt-1 text-amber-700 font-bold">اضافه بر اقساط: ${money(left)} ریال (بستانکار)</div>` : ''}`;
                if (manual && window.MoneyInput) MoneyInput.apply(box);
            };
            const loadInst = async () => {
                const cid = m.querySelector('[data-f=contract_id]').value;
                inst = [];
                if (cid) try { const d = await api('contract_get', {id: cid}); inst = d.installments; if (!o.payee) { const nx = inst.find(i => i.remaining > 0); if (nx) payee = nx.payee; } if (!amountEl.value) { const nx = inst.find(i => i.remaining > 0); if (nx) amountEl.value = money(nx.remaining); } } catch (e) {}
                setPayee(payee);
            };
            m.querySelector('[data-f=contract_id]').onchange = () => { o.payee = null; loadInst(); };
            amountEl.addEventListener('input', drawAlloc);
            m.querySelector('[data-manual]').onchange = drawAlloc;
            loadInst();
            m.querySelector('[data-save]').onclick = async e => {
                const v = k => m.querySelector(`[data-f=${k}]`).value;
                if (!v('contract_id')) { toast('قرارداد را انتخاب کنید.', 'error'); return; }
                const fd = new FormData();
                [['contract_id', v('contract_id')], ['pay_j', en(v('pay_j'))], ['amount', num(v('amount'))], ['payee', payee], ['method', v('method')], ['ref_no', en(v('ref_no'))], ['bank', v('bank')], ['cheque_no', en(v('cheque_no'))], ['cheque_due_j', en(v('cheque_due_j'))], ['note', v('note')]].forEach(([a, b]) => fd.append(a, b));
                if (m.querySelector('[data-manual]').checked) fd.append('alloc', JSON.stringify([...m.querySelectorAll('[data-al]')].map(x => ({installment_id: +x.dataset.al, amount: num(x.value)})).filter(x => x.amount > 0)));
                const f = m.querySelector('[data-file]').files[0]; if (f) fd.append('file', f);
                busy(e.currentTarget, true);
                try { const r = await api('pay_save', {}, fd); m.close(); toast('دریافت ثبت شد.' + (r.unallocated > 0 ? ' (' + money(r.unallocated) + ' ریال بستانکار)' : ''), 'success'); if (onDone) onDone(); }
                catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
    };

    // =================================================================
    //  پرداخت به پاسارگاد
    // =================================================================
    const Settle = {
        async render(root) {
            const P = PAYEE();
            root.innerHTML = shell('hl-settle', `<div data-k class="hl-kpis"></div><div class="hl-card"><div class="flex gap-2 flex-wrap">${can('hl-settle', 'create') ? `<button class="hl-btn p" data-new><i class="fas fa-plus"></i>ثبتِ پرداخت به ${esc(P.INSURER)}</button>` : ''}${can('hl-pay', 'export') ? '<button class="hl-btn o" data-xls><i class="fas fa-file-excel text-emerald-600"></i>اکسل</button>' : ''}</div>
                <p class="text-[11.5px] text-slate-600 mt-2 leading-6">این‌جا پول‌هایی را ثبت کنید که «بیمه با ما» از شرکت‌ها گرفته و به ${esc(P.INSURER)} واریز کرده است. «مانده‌ی بدهی» = وصول به ما − پرداخت‌شده به ${esc(P.INSURER)}. دریافت‌هایی که شرکت مستقیم به ${esc(P.INSURER)} داده در این حساب نمی‌آید.</p></div>
                <div class="hl-card" data-list><div class="hl-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            const n = root.querySelector('[data-new]'); if (n) n.onclick = () => this.add(() => this.render(root));
            const x = root.querySelector('[data-xls]'); if (x) x.onclick = () => download(url('export', {kind: 'settlements'}));
            let r;
            try { r = await api('settle_list', {show_void: 1}); } catch (e) { root.querySelector('[data-list]').innerHTML = `<div class="hl-empty">${esc(e.message)}</div>`; return; }
            this.owed = r.owed;
            root.querySelector('[data-k]').innerHTML = `<div class="hl-kpi"><div class="k">وصول به ${esc(P.US)}</div><div class="v">${short(r.paid_us)}</div></div><div class="hl-kpi grn"><div class="k">پرداخت‌شده به ${esc(P.INSURER)}</div><div class="v">${short(r.settled)}</div></div>
                <div class="hl-kpi ${r.owed > 0 ? 'amb' : 'grn'}"><div class="k">مانده‌ی بدهی به ${esc(P.INSURER)}</div><div class="v">${short(r.owed)}</div><div class="s">${money(r.owed)} ریال</div></div>`;
            const box = root.querySelector('[data-list]');
            box.innerHTML = r.rows.length ? `<div class="hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>تاریخ</th><th>مبلغ</th><th>روش</th><th>پیگیری</th><th>قرارداد</th><th>توضیح</th><th>ثبت‌کننده</th><th></th></tr></thead><tbody>
                ${r.rows.map(s => `<tr class="${s.status !== 'OK' ? 'void' : ''}"><td>${fa(s.settle_j)}</td><td class="m">${money(s.amount)}</td><td>${esc(s.method_fa)}</td><td>${fa(s.ref_no || '—')}</td><td>${s.company_name ? esc(s.company_name) : '<span class="text-slate-400">کلی</span>'}</td><td class="text-[11px]">${esc(s.note || '')}</td>
                    <td class="text-[11px]">${esc(s.by || '')}<div class="text-slate-400">${fa(s.created_at_j)}</div></td><td class="whitespace-nowrap">${s.has_file ? `<a class="hl-btn s sm" href="${url('settle_file', {id: s.id})}" target="_blank"><i class="fas fa-paperclip"></i></a>` : ''}${s.status === 'OK' && can('hl-settle', 'delete') ? `<button class="hl-btn r sm" data-v="${s.id}"><i class="fas fa-ban"></i></button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
                : '<div class="hl-empty"><i class="fas fa-building-columns"></i>پرداختی ثبت نشده.</div>';
            box.querySelectorAll('[data-v]').forEach(b => b.onclick = async () => { if (!(await confirmUi('ابطال', 'این پرداخت باطل شود؟', {danger: true}))) return; try { await api('settle_void', {id: b.dataset.v}); this.render(root); } catch (e) { toast(e.message, 'error'); } });
        },
        add(onDone) {
            const B = H.boot, P = PAYEE();
            const m = modal(`<i class="fas fa-building-columns text-emerald-600"></i>ثبتِ پرداخت به ${esc(P.INSURER)}`, `<div class="hl-grid hl-g2">
                <label><span class="hl-lbl">تاریخ</span><input class="hl-in" data-f="settle_j" data-jdate dir="ltr" value="${fa(B.today_j)}"></label>
                <label><span class="hl-lbl">مبلغ (ریال)</span><input class="hl-in money-input" data-f="amount" value="${this.owed > 0 ? money(this.owed) : ''}"></label>
                <label><span class="hl-lbl">روش</span><select class="hl-sel" data-f="method">${methodOpts('transfer')}</select></label>
                <label><span class="hl-lbl">شماره‌ی پیگیری</span><input class="hl-in" data-f="ref_no" dir="ltr"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">بابتِ قرارداد (اختیاری)</span><select class="hl-sel" data-f="contract_id">${conOpts('', 'کلی / چند قرارداد')}</select></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">توضیح</span><input class="hl-in" data-f="note"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">رسید (اختیاری)</span><input type="file" class="hl-in" data-file accept=".pdf,.jpg,.jpeg,.png,.webp"></label></div>`, '<button class="hl-btn p" data-save><i class="fas fa-floppy-disk"></i>ثبت</button>');
            m.querySelector('[data-save]').onclick = async e => {
                const v = k => m.querySelector(`[data-f=${k}]`).value;
                const fd = new FormData();
                [['settle_j', en(v('settle_j'))], ['amount', num(v('amount'))], ['method', v('method')], ['ref_no', en(v('ref_no'))], ['contract_id', v('contract_id')], ['note', v('note')]].forEach(([a, b]) => fd.append(a, b));
                const f = m.querySelector('[data-file]').files[0]; if (f) fd.append('file', f);
                busy(e.currentTarget, true);
                try { await api('settle_save', {}, fd); m.close(); toast('ثبت شد.', 'success'); if (onDone) onDone(); } catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
    };

    // =================================================================
    //  تنظیمات
    // =================================================================
    const Sett = {
        async render(root) {
            let s;
            try { s = (await api('settings_get')).settings; } catch (e) { root.innerHTML = shell('hl-settings', `<div class="hl-card hl-empty">${esc(e.message)}</div>`); return; }
            const ed = can('hl-settings', 'edit');
            root.innerHTML = shell('hl-settings', `<div class="hl-card"><div class="hl-grid hl-g3">
                    <label><span class="hl-lbl">نامِ کوتاهِ بیمه‌گر (در برچسب‌ها)</span><input class="hl-in" data-s="insurer_label" value="${esc(s.insurer_label)}"></label>
                    <label><span class="hl-lbl">بیمه‌گرِ پیش‌فرضِ قرارداد</span><input class="hl-in" data-s="default_insurer" value="${esc(s.default_insurer)}"></label>
                    <label><span class="hl-lbl">پرداختِ پیش‌فرضِ اقساط به</span><select class="hl-sel" data-s="default_payee"><option value="US" ${s.default_payee === 'US' ? 'selected' : ''}>بیمه با ما</option><option value="INSURER" ${s.default_payee === 'INSURER' ? 'selected' : ''}>بیمه‌گر</option></select></label>
                    <label><span class="hl-lbl">کارمزدِ پیش‌فرض٪</span><input class="hl-in" data-s="default_commission" value="${fa(s.default_commission)}"></label>
                    <label><span class="hl-lbl">«سررسیدِ نزدیک» تا چند روز</span><input class="hl-in" data-s="due_soon_days" value="${fa(s.due_soon_days)}"></label>
                    <label><span class="hl-lbl">بیمه‌گرها (با ، جدا کنید)</span><input class="hl-in" data-s="insurers" value="${esc((s.insurers || []).join('، '))}"></label></div>
                <div class="hl-row mt-3"><b class="text-emerald-800">روش‌های پرداخت</b><div class="flex flex-col gap-2" data-meth>${Object.entries(s.methods).map(([k, v]) => `<div class="flex gap-2" data-m><input class="hl-in" dir="ltr" style="max-width:140px" data-mk value="${esc(k)}" ${k === 'cheque' ? 'readonly' : ''}><input class="hl-in" data-mv value="${esc(v)}">${k !== 'cheque' && ed ? '<button class="hl-btn r sm" data-rm><i class="fas fa-xmark"></i></button>' : ''}</div>`).join('')}</div>
                    ${ed ? '<button class="hl-btn s sm" data-addm style="align-self:flex-start"><i class="fas fa-plus"></i>روشِ جدید</button>' : ''}<small class="text-slate-500">کد (لاتین) ثابت می‌ماند؛ «چک» برای ثبتِ شماره و سررسیدِ چک لازم است.</small></div>
                <div class="hl-row mt-3 text-[11.5px] leading-7 text-slate-600"><b class="text-slate-800"><i class="fas fa-shield-halved text-emerald-600"></i> دسترسی‌ها</b>از «کاربران پنل ← دسترسی‌ها»، گروهِ «مالیِ درمان تکمیلی»: داشبورد، قراردادها و اقساط، دریافت‌ها، پرداخت به پاسارگاد و تنظیمات - هرکدام با دیدن/ثبت/ویرایش/حذف/اکسلِ جدا.</div>
                ${ed ? '<button class="hl-btn p mt-3" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>' : ''}</div>`);
            if (!ed) { root.querySelectorAll('input,select').forEach(x => x.disabled = true); return; }
            const bind = () => root.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => b.closest('[data-m]').remove());
            bind();
            root.querySelector('[data-addm]').onclick = () => { root.querySelector('[data-meth]').insertAdjacentHTML('beforeend', '<div class="flex gap-2" data-m><input class="hl-in" dir="ltr" style="max-width:140px" data-mk placeholder="code"><input class="hl-in" data-mv placeholder="نام"><button class="hl-btn r sm" data-rm><i class="fas fa-xmark"></i></button></div>'); bind(); };
            root.querySelector('[data-save]').onclick = async () => {
                const v = k => root.querySelector(`[data-s=${k}]`).value;
                const methods = {}; root.querySelectorAll('[data-m]').forEach(r => { const k = r.querySelector('[data-mk]').value.trim(), val = r.querySelector('[data-mv]').value.trim(); if (k && val) methods[k] = val; });
                try { await api('settings_save', {settings: {insurer_label: v('insurer_label'), default_insurer: v('default_insurer'), default_payee: v('default_payee'), default_commission: en(v('default_commission')), due_soon_days: en(v('due_soon_days')),
                    insurers: v('insurers').split(/[،,\n]/).map(x => x.trim()).filter(Boolean), methods}}); toast('ذخیره شد.', 'success'); await boot(true); this.render(root); }
                catch (e) { toast(e.message, 'error'); }
            };
        },
    };

    window.Health = {open, newPayment: o => boot().then(() => Pay.add(o || {}, () => {}))};
})();
