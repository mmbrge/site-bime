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
    const PAYEE = () => ({US: H.boot.settings.us_label || 'بیمه با ما', INSURER: H.boot.settings.insurer_label || 'پاسارگاد'});
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
    .hl-badge{background:#f43f5e;color:#fff;border-radius:99px;font-size:10px;padding:1px 7px;margin-right:2px}
    .hl-blk{background:#fff;border:1px solid #e2ece8;border-radius:20px;overflow:hidden;box-shadow:0 10px 30px -26px rgba(15,23,42,.35)}
    .hl-blk-h{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:13px 16px;background:linear-gradient(120deg,#f0fdf4,#ecfeff);border-bottom:1px solid #e2ece8}
    .hl-blk-h b{font-size:14px}.hl-blk-b{padding:12px 14px}.hl-mini{display:flex;flex-wrap:wrap;gap:6px}
    .hl-lt td{font-size:11.5px;padding:7px 7px}.hl-lt th{font-size:10px;padding:8px 7px}.hl-lt td .sub{display:block;font-size:10px;color:#64748b;margin-top:2px;white-space:nowrap}
    .hl-seg{display:inline-flex;background:#f1f5f9;border-radius:12px;padding:3px;gap:3px}.hl-seg button{border:0;background:transparent;border-radius:9px;padding:6px 11px;font-family:inherit;font-weight:800;font-size:11.5px;cursor:pointer;color:#475569}.hl-seg button.on{background:#fff;color:#047857;box-shadow:0 2px 6px -3px rgba(0,0,0,.3)}
    .hl-rgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}
    .hl-rc{background:#fff;border:1px solid #e2ece8;border-radius:18px;overflow:hidden;cursor:pointer;transition:transform .12s,box-shadow .12s;display:flex;flex-direction:column}
    .hl-rc:hover{transform:translateY(-2px);box-shadow:0 14px 30px -22px rgba(4,120,87,.6)}.hl-rc .im{height:170px;background:#f1f5f9 center/cover no-repeat;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:34px}
    .hl-rc .tx{padding:9px 11px;font-size:11.5px;display:flex;flex-direction:column;gap:3px}
    .hl-rv{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:14px}.hl-rv .pv{background:#0f172a;border-radius:16px;min-height:420px;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}
    .hl-rv .pv img{max-width:100%;max-height:70vh;transition:transform .15s;cursor:zoom-in}.hl-rv .pv img.z{transform:scale(1.9);cursor:zoom-out}.hl-rv .pv iframe{width:100%;height:70vh;border:0;background:#fff}
    .hl-fname{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:8px 10px;font-size:11px;color:#334155;word-break:break-all}
    @media (max-width:767px){.hl-rv{grid-template-columns:minmax(0,1fr)}.hl-rv .pv{min-height:260px}}
    @media (max-width:767px){.hl-g2,.hl-g3,.hl-g4{grid-template-columns:minmax(0,1fr)}.hl-tblwrap{display:none}.hl-cards{display:flex}.hl-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
        .hl-hub{border-radius:20px}.hl-nav{width:100%;overflow:auto;flex-wrap:nowrap}.hl-nav button{flex-shrink:0}.hl-f>label{max-width:none;flex:1 1 45%}
        .hl-ov{padding:0;align-items:flex-end}.hl-md{border-radius:22px 22px 0 0;max-height:94vh;overflow:auto}.hl-irow{grid-template-columns:28px 1fr 1fr;}.hl-irow .w2{grid-column:span 2}}`;
    const css = () => { if (!document.getElementById('hl-css')) { const s = document.createElement('style'); s.id = 'hl-css'; s.textContent = STYLE; document.head.appendChild(s); } };

    const TABS = [['hl-dash', 'fa-chart-pie', 'داشبورد'], ['hl-ledger', 'fa-table-list', 'دفتر ماهانه'], ['hl-receipts', 'fa-inbox', 'رسیدهای دریافتی'], ['hl-contracts', 'fa-file-contract', 'قراردادها'],
        ['hl-members', 'fa-users', 'بیمه‌شدگان'], ['hl-pay', 'fa-hand-holding-dollar', 'دریافت‌ها'], ['hl-settle', 'fa-building-columns', 'واریز به پاسارگاد'], ['hl-settings', 'fa-sliders', 'تنظیمات']];
    const shell = (tab, body) => `<div class="hl space-y-4 no-count" data-perm-skip><div class="hl-hub"><div class="t"><span class="ic"><i class="fas fa-stethoscope"></i></span><div><b>مالیِ درمان تکمیلی</b><small>قراردادهای شرکت‌ها، اقساط، وصول و تسویه با ${esc(H.boot.settings.insurer_label || 'پاسارگاد')}</small></div></div>
        <div class="hl-nav">${TABS.filter(t => can(t[0])).map(t => `<button class="${t[0] === tab ? 'on' : ''}" onclick="switchTab('${t[0]}')"><i class="fas ${t[1]}"></i>${t[2]}${t[0] === 'hl-receipts' && H.boot.inbox_new ? `<span class="hl-badge">${fa(H.boot.inbox_new)}</span>` : ''}</button>`).join('')}</div></div>${body}</div>`;
    async function boot(force) { if (!H.boot || force) H.boot = await api('bootstrap'); return H.boot; }
    async function open(tab) {
        css();
        const root = document.getElementById(tab + '-root');
        if (!root) return;
        try { await boot(); } catch (e) { root.innerHTML = `<div class="hl-card hl-empty"><i class="fas fa-lock"></i>${esc(e.message)}</div>`; return; }
        if (!can(tab)) { root.innerHTML = shell(tab, '<div class="hl-card hl-empty"><i class="fas fa-lock"></i>به این بخش دسترسی ندارید.</div>'); return; }
        ({'hl-dash': Dash, 'hl-ledger': Ledger, 'hl-receipts': Inbox, 'hl-members': Members, 'hl-contracts': Con, 'hl-pay': Pay, 'hl-settle': Settle, 'hl-settings': Sett})[tab].render(root);
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
    const conLabel = c => esc(c.company_name) + (c.line && c.line !== 'درمان' ? ' - ' + esc(c.line) : '') + (c.plan ? ' - ' + esc(c.plan) : '') + (c.policy_no ? ' - ' + fa(c.policy_no) : '') + (c.title && !c.plan ? ' (' + esc(c.title) + ')' : '');
    const conOpts = (sel, all) => (all ? `<option value="">${all}</option>` : '') + H.boot.contracts.map(c => `<option value="${c.id}" ${+sel === c.id ? 'selected' : ''}>${conLabel(c)}${c.status !== 'ACTIVE' ? ' - ' + esc(H.boot.statuses[c.status]) : ''}</option>`).join('');
    const methodOpts = sel => Object.entries(H.boot.settings.methods).map(([k, v]) => `<option value="${k}" ${sel === k ? 'selected' : ''}>${esc(v)}</option>`).join('');
    const payeeTag = p => `<span class="hl-tag ${p === 'INSURER' ? 'c' : 'b'}">${esc(PAYEE()[p])}</span>`;
    const docOpts = sel => (H.boot.settings.doc_types || []).map(d => `<option ${sel === d ? 'selected' : ''}>${esc(d)}</option>`).join('');
    const lineOpts = sel => (H.boot.settings.lines || ['درمان']).map(d => `<option ${sel === d ? 'selected' : ''}>${esc(d)}</option>`).join('');
    const conShort = c => esc(c.company_name) + ' · ' + esc(c.line || 'درمان') + (c.plan ? ' · ' + esc(c.plan) : '') + (c.policy_no ? ' · ' + fa(c.policy_no) : '');

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
                ${k.commission ? kp('', 'fa-percent', 'کارمزدِ برآوردی', short(k.commission)) : ''}
                ${H.boot.inbox_new && can('hl-receipts') ? `<div class="hl-kpi amb" style="cursor:pointer" onclick="switchTab('hl-receipts')"><div class="k"><i class="fas fa-inbox"></i>رسیدهای در انتظارِ ثبت</div><div class="v">${fa(H.boot.inbox_new)}</div><div class="s">از گروه‌های ربات - برای ثبت بزنید</div></div>` : ''}</div>
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
                    <label><span>نوعِ بیمه</span><select class="hl-sel" data-k="line"><option value="">همه</option>${lineOpts(f.line)}</select></label>
                    ${can('hl-contracts', 'create') ? '<button class="hl-btn p" data-new><i class="fas fa-plus"></i>قراردادِ جدید</button><button class="hl-btn o" data-bdx><i class="fas fa-file-import text-emerald-600"></i>از بردروی بیمه‌گر</button>' : ''}${can('hl-contracts', 'export') ? '<button class="hl-btn o" data-xls><i class="fas fa-file-excel text-emerald-600"></i>اکسلِ قراردادها</button><button class="hl-btn o" data-xls2><i class="fas fa-file-excel text-emerald-600"></i>اکسلِ همه‌ی اقساط</button>' : ''}
                </div></div><div class="hl-card" data-list><div class="hl-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            let tm;
            root.querySelectorAll('[data-k]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => { clearTimeout(tm); tm = setTimeout(() => { f[el.dataset.k] = el.value; this.load(root); }, 300); }));
            const n = root.querySelector('[data-new]'); if (n) n.onclick = () => this.edit(null, () => this.load(root));
            const bx = root.querySelector('[data-bdx]'); if (bx) bx.onclick = () => this.bordereau(() => this.load(root));
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
                    <div class="text-[11px] text-slate-500">${esc(c.line || 'درمان')}${c.plan ? ' · ' + esc(c.plan) : ''} · ${c.policy_no ? 'بیمه‌نامه ' + fa(c.policy_no) + ' · ' : ''}${esc(c.insurer)}${c.unit_premium ? ' · سرانه ' + short(c.unit_premium) : ''}</div></div>
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
                <div class="hl-kpis mb-3" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">${[['جمعِ اقساط', short(s.total)], ['پرداخت‌شده', short(s.paid) + ' (' + fa(s.pct) + '٪)'], ['مانده', short(s.remaining)], ['معوق', short(s.overdue)], ['وصول به ' + P.US, short(s.paid_us)], ['وصول به ' + P.INSURER, short(s.paid_insurer)]]
                    .map(([a, b], i) => `<div class="hl-kpi ${i === 3 && s.overdue ? 'red' : ''}"><div class="k">${esc(a)}</div><div class="v" style="font-size:16px">${b}</div></div>`).join('')}</div>
                <div class="text-[11.5px] text-slate-600 mb-3 leading-6">${esc(c.line || 'درمان')}${c.plan ? ' · ' + esc(c.plan) : ''} · ${esc(c.insurer)} · ${c.start_j ? fa(c.start_j) + ' تا ' + fa(c.end_j || '…') : ''} · ${fa(c.insured_count)} نفر · حق‌بیمه ${money(c.premium)} ریال${c.unit_premium ? ' · سرانه‌ی ماهانه ' + money(c.unit_premium) : ''}${c.commission_pct ? ' · کارمزد ' + fa(c.commission_pct) + '٪' : ''}${c.issue_j ? ' · صدور ' + fa(c.issue_j) : ''}${c.prev_policy_no ? ' · بیمه‌نامه‌ی قبلی ' + fa(c.prev_policy_no) : ''}${c.contact_name ? ' · رابط: ' + esc(c.contact_name) + ' ' + fa(c.contact_mobile || '') : ''}
                    · بیمه‌شدگان: ${fa(d.statement.members_active)} فعال از ${fa(d.statement.members)}</div>
                ${Con.statementHtml(d)}
                ${c.coverage ? `<details class="hl-card mb-3" style="box-shadow:none"><summary class="font-black text-[13px] cursor-pointer"><i class="fas fa-shield-heart text-emerald-600"></i> تعهدات، سقف‌ها و فرانشیز</summary><div class="hl-mini mt-2">${c.coverage.split(/\s*,\s*(?=گروه)/).filter(Boolean).map(x => `<span class="hl-tag s" style="white-space:normal">${esc(x.replace(/^گروه و تعهد\s*/, ''))}</span>`).join('')}</div></details>` : ''}
                <div class="hl-card mb-3" style="box-shadow:none"><h3><i class="fas fa-list-ol text-emerald-600"></i>${c.billing === 'MONTHLY' ? 'صورتحساب‌های ماهانه' : 'اقساط'}</h3><div class="hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>${c.billing === 'MONTHLY' ? 'ماه / نامه' : '#'}</th><th>سررسید</th><th>مبلغ</th><th>پرداخت‌شده</th><th>مانده</th><th>پرداخت به</th><th>وضعیت</th><th>توضیح</th></tr></thead><tbody>
                    ${d.installments.map(i => `<tr><td>${i.period_fa ? `<b>${esc(i.period_fa)}</b>${i.letter_no ? `<span class="block text-[10px] text-slate-500">نامه ${fa(i.letter_no)}${i.headcount ? ' · ' + fa(i.headcount) + ' نفر' : ''}</span>` : ''}` : fa(i.seq)}</td><td>${fa(i.due_j)}</td><td class="m">${money(i.amount)}</td><td class="m text-emerald-700">${money(i.paid)}</td><td class="m ${i.remaining ? 'text-rose-700' : ''}">${money(i.remaining)}</td><td>${payeeTag(i.payee)}</td>
                        <td><span class="hl-tag ${ST_C[i.status]}">${ST_FA[i.status]}${i.days_late ? ' - ' + fa(i.days_late) + ' روز' : ''}</span></td><td class="text-[11px]">${esc(i.note || '')}</td></tr>`).join('') || '<tr><td colspan="8" class="hl-empty">قسطی تعریف نشده.</td></tr>'}</tbody></table></div></div>
                <div class="hl-card" style="box-shadow:none"><h3><i class="fas fa-hand-holding-dollar text-emerald-600"></i>دریافت‌ها</h3>${Pay.tableHtml(d.payments, false)}</div>`,
                `${can('hl-contracts', 'export') ? '<button class="hl-btn o" data-xi><i class="fas fa-file-excel text-emerald-600"></i>اکسلِ اقساط</button>' : ''}${can('hl-contracts', 'delete') ? '<button class="hl-btn r" data-del><i class="fas fa-trash"></i>حذفِ قرارداد</button>' : ''}${can('hl-contracts', 'edit') ? '<button class="hl-btn s" data-ed><i class="fas fa-pen"></i>ویرایش و اقساط</button>' : ''}${can('hl-pay', 'create') ? '<button class="hl-btn p" data-pay><i class="fas fa-plus"></i>ثبتِ دریافت</button>' : ''}`, {wide: true});
            const reload = () => { m.close(); if (onChange) onChange(); this.view(id, onChange); };
            Pay.bindTable(m, reload);
            const ae = m.querySelector('[data-addend]'); if (ae) ae.onclick = () => this.endEdit(c, null, reload);
            m.querySelectorAll('[data-ee]').forEach(b => b.onclick = () => this.endEdit(c, d.endorsements.find(x => x.id === +b.dataset.ee), reload));
            m.querySelectorAll('[data-ed-del]').forEach(b => b.onclick = async () => { if (!(await confirmUi('حذفِ الحاقیه', 'این الحاقیه حذف شود؟', {danger: true}))) return; try { await api('end_delete', {id: b.dataset.edDel}); reload(); } catch (e) { toast(e.message, 'error'); } });
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
        bordereau(onDone) {
            const m = modal('<i class="fas fa-file-import text-emerald-600"></i>ساخت/تکمیلِ قرارداد از بردروی بیمه‌نامه', `<p class="text-[12px] text-slate-600 leading-7 mb-2">اکسلِ «بردروی بیمه‌نامه» را که از سامانه‌ی ${esc(H.boot.settings.insurer_label)} می‌گیرید بدهید؛ شماره‌ی بیمه‌نامه، تاریخ‌ها، حق‌بیمه، تعدادِ اعلامی و هر «طرح» با حق‌بیمه‌ی سرانه و تعهداتش خوانده می‌شود. برای هر طرح یک قرارداد ساخته یا قراردادِ موجود تکمیل می‌شود.</p>
                <input type="file" class="hl-in" data-file accept=".xlsx,.csv"><div data-prev class="mt-3"></div>`, '<button class="hl-btn p" data-go disabled><i class="fas fa-check"></i>ثبت</button>', {wide: true});
            let tok = null;
            m.querySelector('[data-file]').onchange = async ev => {
                const f = ev.target.files[0]; if (!f) return;
                const fd = new FormData(); fd.append('file', f);
                const box = m.querySelector('[data-prev]'); box.innerHTML = '<div class="hl-empty"><i class="fas fa-spinner fa-spin"></i></div>';
                try {
                    const r = await api('bordereau_preview', {}, fd); tok = r.token;
                    box.innerHTML = `<div class="hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>بیمه‌نامه</th><th>بیمه‌گذار</th><th>نوع</th><th>دوره</th><th>حق‌بیمه</th><th>اعلامی</th><th>طرح‌ها (سرانه)</th></tr></thead><tbody>${r.items.map(i => `<tr><td dir="ltr">${fa(i.policy_no)}</td><td>${esc(i.company)}</td><td>${esc(i.line)}</td><td>${fa(i.start_j || '')} تا ${fa(i.end_j || '')}</td><td class="m">${money(i.premium)}</td><td>${fa(i.insured_count)}</td>
                        <td>${i.plans.map(p => `<span class="hl-tag ${p.match ? 'g' : 'b'}">${esc(p.plan || 'بدون طرح')} · ${money(p.unit)} · ${p.match ? 'تکمیلِ موجود' : 'جدید'}</span>`).join(' ')}</td></tr>`).join('')}</tbody></table></div>`;
                    m.querySelector('[data-go]').disabled = false;
                } catch (e) { box.innerHTML = `<div class="hl-empty">${esc(e.message)}</div>`; }
            };
            m.querySelector('[data-go]').onclick = async ev => { busy(ev.currentTarget, true);
                try { const r = await api('bordereau_commit', {token: tok}); m.close(); toast(`${fa(r.result.new)} قراردادِ جدید و ${fa(r.result.upd)} به‌روزرسانی.`, 'success'); await boot(true); if (onDone) onDone(); } catch (e) { toast(e.message, 'error'); busy(ev.currentTarget, false); } };
        },
        statementHtml(d) {
            const t = d.statement, P = PAYEE(), ed = can('hl-contracts', 'edit');
            const k = (lab, v, cls = '') => `<div class="hl-kpi ${cls}"><div class="k">${lab}</div><div class="v" style="font-size:15px">${short(v)}</div><div class="s">${money(v)}</div></div>`;
            return `<div class="hl-card mb-3" style="box-shadow:none"><h3><i class="fas fa-file-invoice text-emerald-600"></i>صورت‌حسابِ بیمه‌نامه <small>${t.plans > 1 ? 'همه‌ی ' + fa(t.plans) + ' طرحِ همین شماره' : 'مثلِ صورت‌حسابِ ' + esc(P.INSURER)}</small></h3>
                <div class="hl-kpis" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">${k('حق‌بیمه‌ی صادره', t.premium)}${k('الحاقیه‌های اضافی', t.add)}${k('الحاقیه‌های برگشتی', t.refund)}${k('حق‌بیمه‌ی نهایی', t.final, 'grn')}
                    ${k('صورتحساب‌شده به شرکت', t.billed)}${k('وصول از شرکت', t.received)}${k('پرداخت‌شده به ' + esc(P.INSURER), t.paid_insurer)}${k('مانده‌ی بدهی به ' + esc(P.INSURER), t.insurer_balance, t.insurer_balance > 0 ? 'amb' : 'grn')}</div>
                <div class="flex items-center justify-between mt-3 mb-2"><b class="text-[12.5px]">الحاقیه‌ها</b>${ed ? '<button class="hl-btn s sm" data-addend><i class="fas fa-plus"></i>الحاقیه</button>' : ''}</div>
                ${d.endorsements.length ? `<div class="hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>نوع</th><th>شماره</th><th>تاریخ صدور</th><th>تاریخ اثر</th><th>مبلغ</th><th>تغییرِ نفرات</th><th>توضیح</th><th></th></tr></thead><tbody>
                    ${d.endorsements.map(e => `<tr><td><span class="hl-tag ${e.kind === 'ADD' ? 'b' : e.kind === 'REFUND' ? 'r' : 's'}">${esc(e.kind_fa)}</span></td><td>${fa(e.end_no || '—')}</td><td>${fa(e.issue_j || '')}</td><td>${fa(e.effect_j || '')}</td>
                        <td class="m ${e.amount < 0 ? 'text-rose-700' : ''}" dir="ltr">${money(e.amount)}</td><td>${e.headcount_delta !== null ? fa(e.headcount_delta) : ''}</td><td class="text-[11px]">${esc(e.note || '')}</td>
                        <td class="whitespace-nowrap">${e.has_file ? `<a class="hl-btn s sm" target="_blank" href="${url('end_file', {id: e.id})}"><i class="fas fa-paperclip"></i></a>` : ''}${ed ? `<button class="hl-btn s sm" data-ee="${e.id}"><i class="fas fa-pen"></i></button><button class="hl-btn r sm" data-ed-del="${e.id}"><i class="fas fa-trash"></i></button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
                    : '<div class="text-[11.5px] text-slate-500">الحاقیه‌ای ثبت نشده. الحاقیه‌های اضافی/برگشتیِ صورت‌حسابِ بیمه‌گر را این‌جا وارد کنید تا حق‌بیمه‌ی نهایی و بدهی درست حساب شود.</div>'}</div>`;
        },
        endEdit(c, e, onDone) {
            e = e || {kind: 'ADD'};
            const m = modal('<i class="fas fa-file-circle-plus text-emerald-600"></i>' + (e.id ? 'ویرایشِ الحاقیه' : 'الحاقیه‌ی جدید') + ' - ' + esc(c.company_name), `<div class="hl-grid hl-g2">
                <label><span class="hl-lbl">نوع</span><select class="hl-sel" data-f="kind">${Object.entries(H.boot.end_kinds).map(([a, b]) => `<option value="${a}" ${e.kind === a ? 'selected' : ''}>${b}</option>`).join('')}</select></label>
                <label><span class="hl-lbl">شماره‌ی الحاقیه</span><input class="hl-in" data-f="end_no" value="${esc(fa(e.end_no || ''))}"></label>
                <label><span class="hl-lbl">تاریخِ صدور</span><input class="hl-in" data-f="issue_j" data-jdate dir="ltr" value="${esc(fa(e.issue_j || H.boot.today_j))}"></label>
                <label><span class="hl-lbl">تاریخِ اثر</span><input class="hl-in" data-f="effect_j" data-jdate dir="ltr" value="${esc(fa(e.effect_j || ''))}"></label>
                <label><span class="hl-lbl">مبلغ (ریال)</span><input class="hl-in money-input" data-f="amount" value="${e.amount ? money(Math.abs(e.amount)) : ''}"></label>
                <label><span class="hl-lbl">تغییرِ تعدادِ بیمه‌شدگان (+ / −)</span><input class="hl-in" data-f="headcount_delta" dir="ltr" value="${e.headcount_delta !== null && e.headcount_delta !== undefined ? fa(e.headcount_delta) : ''}"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">توضیح</span><input class="hl-in" data-f="note" value="${esc(e.note || '')}"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">فایلِ الحاقیه (اختیاری)</span><input type="file" class="hl-in" data-file accept=".pdf,.jpg,.jpeg,.png,.webp"></label></div>
                <p class="text-[11px] text-slate-500 mt-2">اضافی به حق‌بیمه اضافه و برگشتی کم می‌شود؛ الحاقیه‌ی اصلاحی معمولاً مبلغ ندارد.</p>`, '<button class="hl-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>');
            m.querySelector('[data-save]').onclick = async ev => {
                const v = k => m.querySelector(`[data-f=${k}]`).value, fd = new FormData();
                [['id', e.id || ''], ['contract_id', c.id], ['kind', v('kind')], ['end_no', en(v('end_no'))], ['issue_j', en(v('issue_j'))], ['effect_j', en(v('effect_j'))], ['amount', num(v('amount'))], ['amount_signed', 0], ['headcount_delta', en(v('headcount_delta')).replace(/[^\d-]/g, '')], ['note', v('note')]].forEach(([a, b]) => fd.append(a, b));
                const f = m.querySelector('[data-file]').files[0]; if (f) fd.append('file', f);
                busy(ev.currentTarget, true);
                try { await api('end_save', {}, fd); m.close(); toast('الحاقیه ذخیره شد.', 'success'); if (onDone) onDone(); } catch (er) { toast(er.message, 'error'); busy(ev.currentTarget, false); }
            };
        },
        async edit(id, onDone) {
            const B = H.boot, S = B.settings;
            let c = {company_name: '', insurer: S.default_insurer, status: 'ACTIVE', commission_pct: S.default_commission, insured_count: 0, premium: 0, line: (S.lines || ['درمان'])[0], billing: 'MONTHLY'}, inst = [];
            if (id) { try { const d = await api('contract_get', {id}); c = d.contract; inst = d.installments.filter(i => i.kind !== 'BILL'); } catch (e) { toast(e.message, 'error'); return; } }
            const fld = (k, l, v, x = '') => `<label><span class="hl-lbl">${l}</span><input class="hl-in" data-f="${k}" value="${esc(v ?? '')}" ${x}></label>`;
            const m = modal(`<i class="fas fa-file-contract text-emerald-600"></i>${id ? 'ویرایشِ قرارداد' : 'قراردادِ جدیدِ درمان تکمیلی'}`, `
                <div class="hl-grid hl-g3">
                    <label><span class="hl-lbl">شرکت (بیمه‌گذار)</span><input class="hl-in" list="hl-co" data-f="company_name" value="${esc(c.company_name)}" placeholder="نامِ شرکت"><datalist id="hl-co">${B.companies.map(x => `<option value="${esc(x.name)}">`).join('')}</datalist></label>
                    ${fld('policy_no', 'شماره‌ی بیمه‌نامه', fa(c.policy_no || ''), 'dir="ltr"')}${fld('title', 'عنوان (اختیاری)', c.title, 'placeholder="مثلاً درمانِ کارکنان ۱۴۰۵"')}
                    <label><span class="hl-lbl">بیمه‌گر</span><input class="hl-in" list="hl-ins" data-f="insurer" value="${esc(c.insurer)}"><datalist id="hl-ins">${(S.insurers || []).map(x => `<option value="${esc(x)}">`).join('')}</datalist></label>
                    ${fld('start_j', 'شروع', fa(c.start_j || ''), 'data-jdate dir="ltr"')}${fld('end_j', 'پایان', fa(c.end_j || ''), 'data-jdate dir="ltr"')}
                    ${fld('insured_count', 'تعدادِ بیمه‌شده', fa(c.insured_count || ''))}<label><span class="hl-lbl">حق‌بیمه‌ی کل (ریال)</span><input class="hl-in money-input" data-f="premium" value="${c.premium ? money(c.premium) : ''}"></label>
                    ${fld('commission_pct', 'کارمزد٪ (برآوردی)', fa(c.commission_pct || ''))}
                    <label><span class="hl-lbl">نوعِ بیمه</span><select class="hl-sel" data-f="line">${lineOpts(c.line)}</select></label>
                    ${fld('plan', 'زیرمجموعه / طرح (اختیاری)', c.plan, 'placeholder="مثلاً طرح 1"')}${fld('issue_j', 'تاریخِ صدور', fa(c.issue_j || ''), 'data-jdate dir="ltr"')}
                    <label><span class="hl-lbl">حق‌بیمه‌ی ماهانه‌ی سرانه (ریال)</span><input class="hl-in money-input" data-f="unit_premium" value="${c.unit_premium ? money(c.unit_premium) : ''}"></label>
                    ${fld('prev_policy_no', 'شماره‌ی بیمه‌نامه‌ی قبلی', fa(c.prev_policy_no || ''), 'dir="ltr"')}${fld('holder_code', 'کدِ بیمه‌گذار نزدِ بیمه‌گر', fa(c.holder_code || ''), 'dir="ltr"')}
                    <div style="grid-column:1/-1"><span class="hl-lbl">شیوه‌ی پرداخت</span><div class="hl-tg" data-billing><button data-v="MONTHLY">صورتحسابِ ماهانه (تعدادِ پرسنل × سرانه)</button><button data-v="INSTALL">اقساطِ ثابت</button></div></div>
                    ${fld('contact_name', 'رابطِ شرکت', c.contact_name)}${fld('contact_mobile', 'موبایلِ رابط', fa(c.contact_mobile || ''), 'dir="ltr"')}
                    <label><span class="hl-lbl">وضعیت</span><select class="hl-sel" data-f="status">${Object.entries(B.statuses).map(([a, b]) => `<option value="${a}" ${c.status === a ? 'selected' : ''}>${b}</option>`).join('')}</select></label></div>
                <label class="block mt-2"><span class="hl-lbl">توضیح</span><textarea class="hl-ta" rows="2" data-f="note">${esc(c.note || '')}</textarea></label>
                <div class="hl-row mt-3" data-monthly-hint style="display:none"><b class="text-emerald-800"><i class="fas fa-table-list"></i> صورتحساب‌های ماهانه</b><span class="text-[11.5px] text-slate-600 leading-6">صورتحسابِ هر ماه (شماره و تاریخِ نامه، تعدادِ پرسنل) از «دفتر ماهانه» ثبت می‌شود؛ سامانه تعدادِ پرسنل را از بیمه‌شدگانِ فعال یا ماهِ قبل پیشنهاد می‌دهد.</span></div>
                <div data-instwrap><div class="hl-row mt-3"><b class="text-emerald-800"><i class="fas fa-wand-magic-sparkles"></i> ساختِ خودکارِ اقساط</b><div class="hl-grid hl-g4">
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
                <div data-rows class="flex flex-col gap-2"></div><button class="hl-btn s sm mt-2" data-add><i class="fas fa-plus"></i>افزودنِ قسط</button></div>`,
                '<button class="hl-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>', {wide: true});
            const rowsBox = m.querySelector('[data-rows]');
            let billing = c.billing || 'INSTALL';
            const setBilling = v => { billing = v; m.querySelectorAll('[data-billing] button').forEach(b => b.classList.toggle('on', b.dataset.v === v)); m.querySelector('[data-instwrap]').style.display = v === 'INSTALL' ? '' : 'none'; m.querySelector('[data-monthly-hint]').style.display = v === 'MONTHLY' ? '' : 'none'; };
            m.querySelectorAll('[data-billing] button').forEach(b => b.onclick = () => setBilling(b.dataset.v));
            setBilling(billing);
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
                    premium: num(v('premium')), commission_pct: en(v('commission_pct')), contact_name: v('contact_name'), contact_mobile: en(v('contact_mobile')), status: v('status'), note: v('note'),
                    line: v('line'), plan: v('plan'), issue_j: en(v('issue_j')), unit_premium: num(v('unit_premium')), prev_policy_no: en(v('prev_policy_no')), holder_code: en(v('holder_code')), billing};
                if (billing === 'INSTALL') body.installments = read();
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
                    <label><span class="hl-lbl">اسنادِ واریزی</span><select class="hl-sel" data-f="doc_type"><option value="">—</option>${docOpts('')}</select></label>
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
                const bi = o.bill_id ? open.findIndex(i => i.id === +o.bill_id) : -1;
                const order = bi >= 0 ? [...open.slice(bi), ...open.slice(0, bi)] : [...open].sort((a, b) => (a.payee === payee ? 0 : 1) - (b.payee === payee ? 0 : 1) || String(a.due_g).localeCompare(String(b.due_g)));
                const auto = {}; order.forEach(i => { const a = Math.min(left, i.remaining); if (a > 0) { auto[i.id] = a; left -= a; } });
                box.innerHTML = `<table class="hl-tbl no-count"><thead><tr><th>قسط / ماه</th><th>سررسید</th><th>مانده</th><th>پرداخت به</th><th>${manual ? 'مبلغِ تخصیص' : 'تخصیصِ خودکار'}</th></tr></thead><tbody>${open.map(i => `<tr style="cursor:default"><td>${i.period_fa ? esc(i.period_fa) : fa(i.seq)}</td><td>${fa(i.due_j)} ${i.status === 'OVERDUE' ? '<span class="hl-tag r">معوق</span>' : ''}</td><td class="m">${money(i.remaining)}</td><td>${payeeTag(i.payee)}</td>
                    <td>${manual ? `<input class="hl-in money-input" data-al="${i.id}" value="${auto[i.id] ? money(auto[i.id]) : ''}" style="max-width:150px">` : `<b class="text-emerald-700">${auto[i.id] ? money(auto[i.id]) : '—'}</b>`}</td></tr>`).join('')}</tbody></table>${left > 0 ? `<div class="mt-1 text-amber-700 font-bold">اضافه بر اقساط: ${money(left)} ریال (بستانکار)</div>` : ''}`;
                if (manual && window.MoneyInput) MoneyInput.apply(box);
            };
            const loadInst = async () => {
                const cid = m.querySelector('[data-f=contract_id]').value;
                inst = [];
                if (cid) try { const d = await api('contract_get', {id: cid}); inst = d.installments; const bb = o.bill_id && inst.find(i => i.id === +o.bill_id); if (bb && !amountEl.value && bb.remaining) amountEl.value = money(bb.remaining); if (!o.payee) { const nx = inst.find(i => i.remaining > 0); if (nx) payee = nx.payee; } if (!amountEl.value) { const nx = inst.find(i => i.remaining > 0); if (nx) amountEl.value = money(nx.remaining); } } catch (e) {}
                setPayee(payee);
            };
            m.querySelector('[data-f=contract_id]').onchange = () => { o.payee = null; o.bill_id = null; loadInst(); };
            amountEl.addEventListener('input', drawAlloc);
            m.querySelector('[data-manual]').onchange = drawAlloc;
            loadInst();
            m.querySelector('[data-save]').onclick = async e => {
                const v = k => m.querySelector(`[data-f=${k}]`).value;
                if (!v('contract_id')) { toast('قرارداد را انتخاب کنید.', 'error'); return; }
                const fd = new FormData();
                [['contract_id', v('contract_id')], ['pay_j', en(v('pay_j'))], ['amount', num(v('amount'))], ['payee', payee], ['method', v('method')], ['doc_type', v('doc_type')], ['ref_no', en(v('ref_no'))], ['bank', v('bank')], ['cheque_no', en(v('cheque_no'))], ['cheque_due_j', en(v('cheque_due_j'))], ['note', v('note')], ['bill_id', o.bill_id || '']].forEach(([a, b]) => fd.append(a, b));
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
            box.innerHTML = r.rows.length ? `<div class="hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>تاریخِ واریز</th><th>${esc(H.boot.settings.handover_label || 'تحویل')}</th><th>مبلغ</th><th>روش</th><th>پیگیری</th><th>قرارداد / ماه</th><th>توضیح</th><th>ثبت‌کننده</th><th></th></tr></thead><tbody>
                ${r.rows.map(s => `<tr class="${s.status !== 'OK' ? 'void' : ''}"><td>${fa(s.settle_j)}</td><td>${fa(s.handover_j || '—')}</td><td class="m">${money(s.amount)}</td><td>${esc(s.method_fa)}</td><td>${fa(s.ref_no || '—')}</td><td>${s.company_name ? esc(s.company_name) + (s.plan ? ' · ' + esc(s.plan) : '') + (s.bill ? `<span class="block text-[10.5px] text-slate-500">${esc(s.bill)}</span>` : '') : '<span class="text-slate-400">کلی</span>'}</td><td class="text-[11px]">${esc(s.note || '')}</td>
                    <td class="text-[11px]">${esc(s.by || '')}<div class="text-slate-400">${fa(s.created_at_j)}</div></td><td class="whitespace-nowrap">${s.has_file ? `<a class="hl-btn s sm" href="${url('settle_file', {id: s.id})}" target="_blank"><i class="fas fa-paperclip"></i></a>` : ''}${s.status === 'OK' && can('hl-settle', 'delete') ? `<button class="hl-btn r sm" data-v="${s.id}"><i class="fas fa-ban"></i></button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
                : '<div class="hl-empty"><i class="fas fa-building-columns"></i>پرداختی ثبت نشده.</div>';
            box.querySelectorAll('[data-v]').forEach(b => b.onclick = async () => { if (!(await confirmUi('ابطال', 'این پرداخت باطل شود؟', {danger: true}))) return; try { await api('settle_void', {id: b.dataset.v}); this.render(root); } catch (e) { toast(e.message, 'error'); } });
        },
        add(onDone, pre = {}) {
            const B = H.boot, P = PAYEE();
            const amt = pre.amount || (this.owed > 0 ? this.owed : 0);
            const m = modal(`<i class="fas fa-building-columns text-emerald-600"></i>ثبتِ واریز به ${esc(P.INSURER)}${pre.label ? ' - بابتِ ' + esc(pre.label) : ''}`, `<div class="hl-grid hl-g2">
                <label><span class="hl-lbl">${esc(B.settings.handover_label || 'تاریخ تحویل')}</span><input class="hl-in" data-f="handover_j" data-jdate dir="ltr" value="${fa(B.today_j)}"></label>
                <label><span class="hl-lbl">تاریخِ واریز به ${esc(P.INSURER)}</span><input class="hl-in" data-f="settle_j" data-jdate dir="ltr" value="${fa(B.today_j)}"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">مبلغ (ریال)</span><input class="hl-in money-input" data-f="amount" value="${amt ? money(amt) : ''}"></label>
                <label><span class="hl-lbl">روش</span><select class="hl-sel" data-f="method">${methodOpts('transfer')}</select></label>
                <label><span class="hl-lbl">شماره‌ی پیگیری</span><input class="hl-in" data-f="ref_no" dir="ltr"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">بابتِ قرارداد (اختیاری)</span><select class="hl-sel" data-f="contract_id">${conOpts(pre.contract_id || '', 'کلی / چند قرارداد')}</select></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">توضیح</span><input class="hl-in" data-f="note"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">رسید (اختیاری)</span><input type="file" class="hl-in" data-file accept=".pdf,.jpg,.jpeg,.png,.webp"></label></div>`, '<button class="hl-btn p" data-save><i class="fas fa-floppy-disk"></i>ثبت</button>');
            m.querySelector('[data-save]').onclick = async e => {
                const v = k => m.querySelector(`[data-f=${k}]`).value;
                const fd = new FormData();
                [['settle_j', en(v('settle_j'))], ['handover_j', en(v('handover_j'))], ['amount', num(v('amount'))], ['method', v('method')], ['ref_no', en(v('ref_no'))], ['contract_id', v('contract_id')], ['installment_id', +v('contract_id') === +pre.contract_id ? (pre.installment_id || '') : ''], ['note', v('note')]].forEach(([a, b]) => fd.append(a, b));
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
            let s, groups = [];
            try { s = (await api('settings_get')).settings; } catch (e) { root.innerHTML = shell('hl-settings', `<div class="hl-card hl-empty">${esc(e.message)}</div>`); return; }
            try { groups = (await api('bot_groups')).groups; } catch (e) {}
            const ed = can('hl-settings', 'edit');
            const inp = (k, l, v, x = '') => `<label><span class="hl-lbl">${l}</span><input class="hl-in" data-s="${k}" value="${esc(v)}" ${x}></label>`;
            root.innerHTML = shell('hl-settings', `<div class="hl-card"><h3><i class="fas fa-sliders text-emerald-600"></i>عمومی</h3><div class="hl-grid hl-g3">
                    ${inp('insurer_label', 'نامِ کوتاهِ بیمه‌گر (در برچسب‌ها)', s.insurer_label)}${inp('us_label', 'نامِ ما در «محلِ واریز»', s.us_label)}${inp('default_insurer', 'بیمه‌گرِ پیش‌فرضِ قرارداد', s.default_insurer)}
                    <label><span class="hl-lbl">پرداختِ پیش‌فرض به</span><select class="hl-sel" data-s="default_payee"><option value="US" ${s.default_payee === 'US' ? 'selected' : ''}>${esc(s.us_label)}</option><option value="INSURER" ${s.default_payee === 'INSURER' ? 'selected' : ''}>بیمه‌گر</option></select></label>
                    ${inp('default_commission', 'کارمزدِ پیش‌فرض٪', fa(s.default_commission))}${inp('due_soon_days', '«سررسیدِ نزدیک» تا چند روز', fa(s.due_soon_days))}
                    ${inp('bill_due_days', 'مهلتِ پرداختِ صورتحساب بعد از نامه (روز)', fa(s.bill_due_days))}${inp('remit_due_days', 'مهلتِ واریزِ وجه به بیمه‌گر (روز)', fa(s.remit_due_days))}${inp('round_tolerance', 'اختلافِ گردکردنِ قابلِ قبول (ریال)', fa(s.round_tolerance))}
                    ${inp('handover_label', 'عنوانِ ستونِ «تاریخ تحویل»', s.handover_label)}${inp('insurers', 'بیمه‌گرها (با ، جدا کنید)', (s.insurers || []).join('، '))}${inp('lines', 'نوع‌های بیمه (با ، جدا کنید)', (s.lines || []).join('، '))}
                    <label style="grid-column:1/-1"><span class="hl-lbl">«اسنادِ واریزی» (با ، جدا کنید)</span><input class="hl-in" data-s="doc_types" value="${esc((s.doc_types || []).join('، '))}"></label></div></div>
                <div class="hl-card"><h3><i class="fas fa-money-check-dollar text-emerald-600"></i>روش‌های پرداخت</h3><div class="flex flex-col gap-2" data-meth>${Object.entries(s.methods).map(([k, v]) => `<div class="flex gap-2" data-m><input class="hl-in" dir="ltr" style="max-width:140px" data-mk value="${esc(k)}" ${k === 'cheque' ? 'readonly' : ''}><input class="hl-in" data-mv value="${esc(v)}">${k !== 'cheque' && ed ? '<button class="hl-btn r sm" data-rm><i class="fas fa-xmark"></i></button>' : ''}</div>`).join('')}</div>
                    ${ed ? '<button class="hl-btn s sm mt-2" data-addm><i class="fas fa-plus"></i>روشِ جدید</button>' : ''}<small class="block text-slate-500 mt-1">کد (لاتین) ثابت می‌ماند؛ «چک» برای ثبتِ شماره و سررسیدِ چک لازم است.</small></div>
                <div class="hl-card"><h3><i class="fas fa-robot text-emerald-600"></i>گروه‌های رسیدِ پرداختِ درمان <small>ربات «بیمه با ما» (ربات شرکت‌ها)</small></h3>
                    <div class="hl-row text-[11.5px] leading-7 text-slate-600 mb-3"><b class="text-slate-800">راه‌اندازی:</b>
                        ۱) رباتِ شرکت‌ها را به گروهی که شرکت‌ها رسیدِ پرداختِ درمان را در آن می‌فرستند اضافه کنید و <b>ادمین</b> کنید (تا عکس‌ها را ببیند).
                        ۲) در همان گروه بنویسید <code dir="ltr">/id</code> تا ربات شناسه‌ی گروه را بفرستد (یا از فهرستِ زیر انتخاب کنید).
                        ۳) شناسه را این‌جا اضافه و ذخیره کنید. از این به بعد هر عکس/PDF آن گروه با نامِ تاریخ و ساعتِ دریافت به «رسیدهای دریافتی» می‌آید.</div>
                    <div class="flex flex-col gap-2" data-grps>${(s.receipt_groups || []).map(g => `<div class="flex gap-2" data-g><input class="hl-in" dir="ltr" style="max-width:200px" data-gid value="${esc(g.chat_id)}" placeholder="شناسه‌ی گروه"><input class="hl-in" data-gt value="${esc(g.title || '')}" placeholder="نام (اختیاری)">${ed ? '<button class="hl-btn r sm" data-rm><i class="fas fa-xmark"></i></button>' : ''}</div>`).join('')}</div>
                    ${ed ? `<div class="flex gap-2 flex-wrap mt-2"><button class="hl-btn s sm" data-addg><i class="fas fa-plus"></i>گروهِ جدید</button>${groups.length ? `<select class="hl-sel" style="max-width:320px" data-known><option value="">انتخاب از گروه‌هایی که ربات در آن‌هاست…</option>${groups.map(g => `<option value="${esc(g.chat_id)}" data-t="${esc(g.title || '')}">${esc(g.title || g.chat_id)} - آخرین پیام ${fa(g.seen_j)}</option>`).join('')}</select>` : '<span class="text-[11px] text-slate-500 self-center">هنوز ربات در گروهی پیامی ندیده است.</span>'}</div>` : ''}
                    <div class="flex gap-4 flex-wrap mt-3 text-[12px] font-bold"><label class="flex items-center gap-2"><input type="checkbox" data-s="receipt_ack" ${s.receipt_ack ? 'checked' : ''}>ربات در گروه «رسید دریافت شد» بنویسد</label>
                        <label class="flex items-center gap-2"><input type="checkbox" data-s="receipt_notify" ${s.receipt_notify ? 'checked' : ''}>اعلانِ پنل برای کاربرانِ «رسیدهای دریافتی»</label></div></div>
                <div class="hl-card text-[11.5px] leading-7 text-slate-600"><b class="text-slate-800"><i class="fas fa-shield-halved text-emerald-600"></i> دسترسی‌ها</b><br>از «کاربران پنل ← دسترسی‌ها»، گروهِ «درمان تکمیلی»: داشبورد، دفترِ ماهانه، رسیدهای دریافتی، بیمه‌شدگان، قراردادها، دریافت‌ها، واریز به ${esc(s.insurer_label)} و تنظیمات - هر کدام با مشاهده/ثبت/ویرایش/حذف/خروجیِ جدا.</div>
                ${ed ? '<div><button class="hl-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره‌ی تنظیمات</button></div>' : ''}`);
            if (!ed) { root.querySelectorAll('input,select').forEach(x => x.disabled = true); return; }
            const bind = () => root.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => b.closest('[data-m],[data-g]').remove());
            bind();
            const gRow = (id, t) => `<div class="flex gap-2" data-g><input class="hl-in" dir="ltr" style="max-width:200px" data-gid value="${esc(id)}" placeholder="شناسه‌ی گروه"><input class="hl-in" data-gt value="${esc(t)}" placeholder="نام (اختیاری)"><button class="hl-btn r sm" data-rm><i class="fas fa-xmark"></i></button></div>`;
            root.querySelector('[data-addm]').onclick = () => { root.querySelector('[data-meth]').insertAdjacentHTML('beforeend', '<div class="flex gap-2" data-m><input class="hl-in" dir="ltr" style="max-width:140px" data-mk placeholder="code"><input class="hl-in" data-mv placeholder="نام"><button class="hl-btn r sm" data-rm><i class="fas fa-xmark"></i></button></div>'); bind(); };
            root.querySelector('[data-addg]').onclick = () => { root.querySelector('[data-grps]').insertAdjacentHTML('beforeend', gRow('', '')); bind(); };
            const kn = root.querySelector('[data-known]'); if (kn) kn.onchange = () => { if (!kn.value) return; if (![...root.querySelectorAll('[data-gid]')].some(x => x.value === kn.value)) { root.querySelector('[data-grps]').insertAdjacentHTML('beforeend', gRow(kn.value, kn.selectedOptions[0].dataset.t)); bind(); } kn.value = ''; };
            root.querySelector('[data-save]').onclick = async () => {
                const v = k => root.querySelector(`[data-s=${k}]`).value, list = k => v(k).split(/[،,\n]/).map(x => x.trim()).filter(Boolean);
                const methods = {}; root.querySelectorAll('[data-m]').forEach(r => { const k = r.querySelector('[data-mk]').value.trim(), val = r.querySelector('[data-mv]').value.trim(); if (k && val) methods[k] = val; });
                const receipt_groups = [...root.querySelectorAll('[data-g]')].map(r => ({chat_id: en(r.querySelector('[data-gid]').value.trim()), title: r.querySelector('[data-gt]').value.trim()})).filter(g => g.chat_id);
                try { await api('settings_save', {settings: {insurer_label: v('insurer_label'), us_label: v('us_label'), default_insurer: v('default_insurer'), default_payee: v('default_payee'), default_commission: en(v('default_commission')), due_soon_days: en(v('due_soon_days')),
                    bill_due_days: en(v('bill_due_days')), remit_due_days: en(v('remit_due_days')), round_tolerance: en(v('round_tolerance')).replace(/[^\d]/g, ''), handover_label: v('handover_label'), insurers: list('insurers'), lines: list('lines'), doc_types: list('doc_types'), methods, receipt_groups,
                    receipt_ack: root.querySelector('[data-s=receipt_ack]').checked ? 1 : 0, receipt_notify: root.querySelector('[data-s=receipt_notify]').checked ? 1 : 0}}); toast('ذخیره شد.', 'success'); await boot(true); this.render(root); }
                catch (e) { toast(e.message, 'error'); }
            };
        },
    };

    // =================================================================
    //  دفترِ ماهانه‌ی صورتحساب‌ها
    // =================================================================
    const LST = {PAID: ['g', 'وصول شد'], PARTIAL: ['a', 'نیمه‌وصول'], OVERDUE: ['r', 'معوق'], DUE: ['s', 'در انتظار']};
    const RST = {DONE: ['g', 'واریز شد'], PARTIAL: ['a', 'بخشی واریز شد'], PENDING: ['r', 'واریز نشده'], DIRECT: ['c', 'مستقیم به بیمه‌گر'], NA: ['s', '—']};
    const periodOpts = (sel, back = 14, fwd = 3) => { const [y, m] = H.boot.today_j.split('/').map(Number); const out = []; for (let k = -back; k <= fwd; k++) { let mm = m + k, yy = y; while (mm < 1) { mm += 12; yy--; } while (mm > 12) { mm -= 12; yy++; } const v = `${yy}/${String(mm).padStart(2, '0')}`; out.push(`<option value="${v}" ${sel === v ? 'selected' : ''}>${MONTHS[mm - 1]} ${fa(yy)}</option>`); } return out.reverse().join(''); };
    const Ledger = {
        f: {line_group: '', company: '', contract_id: '', only: '', status: 'ACTIVE'},
        async render(root) {
            const f = this.f, B = H.boot, companies = [...new Set(B.contracts.map(c => c.company_name))];
            root.innerHTML = shell('hl-ledger', `<div class="hl-card"><div class="hl-f">
                    <div class="hl-seg" data-lg>${[['', 'همه'], ['health', 'درمان'], ['other', 'عمر و حادثه']].map(([a, b]) => `<button data-v="${a}" class="${f.line_group === a ? 'on' : ''}">${b}</button>`).join('')}</div>
                    <label style="max-width:240px"><span>شرکت</span><select class="hl-sel" data-k="company"><option value="">همه‌ی شرکت‌ها</option>${companies.map(c => `<option ${f.company === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></label>
                    <label><span>نمایش</span><select class="hl-sel" data-k="only"><option value="">همه‌ی ماه‌ها</option><option value="open" ${f.only === 'open' ? 'selected' : ''}>کارهای باز</option><option value="overdue" ${f.only === 'overdue' ? 'selected' : ''}>فقط معوق</option><option value="unremitted" ${f.only === 'unremitted' ? 'selected' : ''}>واریزنشده به ${esc(PAYEE().INSURER)}</option></select></label>
                    <label><span>وضعیتِ قرارداد</span><select class="hl-sel" data-k="status"><option value="">همه</option>${Object.entries(B.statuses).map(([a, b]) => `<option value="${a}" ${f.status === a ? 'selected' : ''}>${b}</option>`).join('')}</select></label>
                    <div class="flex gap-2 flex-wrap" style="margin-right:auto">
                        ${can('hl-ledger', 'create') ? '<button class="hl-btn p" data-gen><i class="fas fa-bolt"></i>صورتحساب‌های یک ماه</button><button class="hl-btn o" data-imp><i class="fas fa-file-import text-emerald-600"></i>ورود از اکسلِ دفتر</button>' : ''}
                        ${can('hl-ledger', 'export') ? '<button class="hl-btn o" data-xls><i class="fas fa-file-excel text-emerald-600"></i>اکسلِ دفتر</button>' : ''}
                        <button class="hl-btn s" data-sample title="فایلِ نمونه برای ورود"><i class="fas fa-download"></i>نمونه</button></div></div></div>
                <div data-body><div class="hl-card hl-empty"><i class="fas fa-spinner fa-spin"></i>در حال بارگذاری…</div></div>`);
            root.querySelectorAll('[data-lg] button').forEach(b => b.onclick = () => { f.line_group = b.dataset.v; this.render(root); });
            root.querySelectorAll('[data-k]').forEach(el => el.onchange = () => { f[el.dataset.k] = el.value; this.load(root); });
            const g = root.querySelector('[data-gen]'); if (g) g.onclick = () => this.generate(() => this.load(root));
            const im = root.querySelector('[data-imp]'); if (im) im.onclick = () => this.importer(() => this.load(root));
            const x = root.querySelector('[data-xls]'); if (x) x.onclick = () => download(url('ledger_export', f));
            root.querySelector('[data-sample]').onclick = () => download(url('sample', {kind: 'ledger'}));
            this.load(root);
        },
        async load(root) {
            const body = root.querySelector('[data-body]');
            let d;
            try { d = await api('ledger', this.f); } catch (e) { body.innerHTML = `<div class="hl-card hl-empty">${esc(e.message)}</div>`; return; }
            const T = d.totals, P = PAYEE();
            if (!d.contracts.length) { body.innerHTML = `<div class="hl-card hl-empty"><i class="fas fa-table-list"></i>${this.f.only ? 'موردی با این فیلتر نیست.' : 'هنوز صورتحسابی ثبت نشده. از «ورود از اکسلِ دفتر» دفترِ فعلی را وارد کنید یا برای قراردادها صورتحسابِ ماه بسازید.'}</div>`; return; }
            const kp = (cls, ic, lab, v, sub) => `<div class="hl-kpi ${cls}"><div class="k"><i class="fas ${ic}"></i>${lab}</div><div class="v">${short(v)}</div><div class="s">${sub || money(v) + ' ریال'}</div></div>`;
            body.innerHTML = `<div class="hl-kpis">${kp('', 'fa-file-invoice', 'جمعِ صورتحساب‌ها', T.billed, fa(T.bills) + ' صورتحساب · ' + fa(T.headcount) + ' نفرِ آخرین ماه')}${kp('grn', 'fa-circle-check', 'وصول‌شده', T.received)}
                ${kp('red', 'fa-hourglass-half', 'مانده‌ی وصول', T.remaining, 'معوق: ' + short(T.overdue))}${kp('', 'fa-building', 'وصول به ' + esc(P.US), T.received_us, 'مستقیم به ' + esc(P.INSURER) + ': ' + short(T.received_ins))}
                ${kp('grn', 'fa-building-columns', 'واریزشده به ' + esc(P.INSURER), T.remitted)}${kp(T.to_remit ? 'amb' : 'grn', 'fa-arrow-right-arrow-left', 'مانده‌ی واریز به ' + esc(P.INSURER), T.to_remit, T.remit_late ? 'دیرکرد: ' + short(T.remit_late) : 'دیرکردی نیست')}</div>
                <div class="flex flex-col gap-4 mt-4">${d.contracts.map(b => this.block(b)).join('')}</div>`;
            this.bind(body, d, root);
        },
        block(b) {
            const c = b.contract, s = b.sum, P = PAYEE(), ce = can('hl-ledger', 'create'), ee = can('hl-ledger', 'edit'), pe = can('hl-pay', 'create'), se = can('hl-settle', 'create');
            const recCell = r => r.receipts.length ? r.receipts.map(x => `<div class="mb-1"><b>${money(x.amount)}</b>${x.amount !== x.payment_total ? `<span class="text-[10px] text-slate-500"> از ${short(x.payment_total)}</span>` : ''}
                <span class="sub">${fa(x.pay_j)} · <span class="hl-tag ${x.payee === 'INSURER' ? 'c' : 'b'}" style="font-size:9.5px;padding:0 5px">${x.payee === 'INSURER' ? 'مستقیم به ' + esc(P.INSURER) : esc(P.US)}</span> · ${esc(x.doc_type || x.method_fa)}${x.cheque_no ? ' ' + fa(x.cheque_no) : ''}${x.has_file ? ` <a href="${url('pay_file', {id: x.payment_id})}" target="_blank" title="رسید"><i class="fas fa-paperclip"></i></a>` : ''}</span></div>`).join('') : '<span class="text-slate-400">—</span>';
            const remCell = r => r.remits.length ? r.remits.map(x => `<div class="mb-1"><b>${money(x.amount)}</b><span class="sub">${x.handover_j ? 'تحویل ' + fa(x.handover_j) + ' · ' : ''}واریز ${fa(x.settle_j)}${x.has_file ? ` <a href="${url('settle_file', {id: x.id})}" target="_blank"><i class="fas fa-paperclip"></i></a>` : ''}</span></div>`).join('') : '';
            const rows = b.rows.map(r => { const st = LST[r.status] || LST.DUE, rs = RST[r.remit_status] || RST.NA;
                return `<tr><td><b>${esc(r.period_fa || ('قسط ' + fa(r.seq)))}</b><span class="sub">سررسید ${fa(r.due_j)}</span></td><td>${r.letter_no ? fa(r.letter_no) : '<span class="text-amber-600">بدونِ نامه</span>'}<span class="sub">${fa(r.letter_j || '')}</span></td>
                    <td>${r.headcount ? fa(r.headcount) : '—'}<span class="sub">${r.unit_premium ? '× ' + short(r.unit_premium) : ''}</span></td><td class="m">${money(r.amount)}</td><td>${recCell(r)}</td>
                    <td class="m ${r.remaining ? 'text-rose-700' : 'text-slate-400'}">${money(r.remaining)}<span class="sub"><span class="hl-tag ${st[0]}">${st[1]}${r.days_late ? ' ' + fa(r.days_late) + ' روز' : ''}</span></span></td>
                    <td>${remCell(r)}<span class="hl-tag ${rs[0]}">${rs[1]}</span>${r.to_remit ? `<span class="sub ${r.remit_late ? 'text-rose-600 font-bold' : ''}">مانده ${money(r.to_remit)}</span>` : ''}</td>
                    <td class="whitespace-nowrap">${ee ? `<button class="hl-btn s sm" data-be="${r.id}" title="ویرایشِ صورتحساب"><i class="fas fa-pen"></i></button>` : ''}${pe && r.remaining ? `<button class="hl-btn s sm" data-bp="${r.id}" title="ثبتِ وصول"><i class="fas fa-hand-holding-dollar"></i></button>` : ''}${se && r.to_remit ? `<button class="hl-btn s sm" data-bs="${r.id}" title="واریز به ${esc(P.INSURER)}"><i class="fas fa-building-columns"></i></button>` : ''}${can('hl-ledger', 'delete') && !r.received ? `<button class="hl-btn r sm" data-bd="${r.id}" title="حذف"><i class="fas fa-trash"></i></button>` : ''}</td></tr>`; }).join('');
            const cards = b.rows.map(r => { const st = LST[r.status] || LST.DUE, rs = RST[r.remit_status] || RST.NA;
                return `<div class="hl-pc"><div class="flex justify-between items-center"><b>${esc(r.period_fa || ('قسط ' + fa(r.seq)))}</b><span class="hl-tag ${st[0]}">${st[1]}</span></div>
                    <div class="text-[11px] text-slate-600 mt-1">نامه ${fa(r.letter_no || '—')} ${r.letter_j ? '· ' + fa(r.letter_j) : ''} · ${fa(r.headcount || 0)} نفر</div>
                    <div class="flex justify-between mt-2 text-[12px]"><span>صورتحساب <b>${money(r.amount)}</b></span><span>مانده <b class="${r.remaining ? 'text-rose-700' : ''}">${money(r.remaining)}</b></span></div>
                    <div class="text-[11px] mt-1">${recCell(r)}</div><div class="mt-1"><span class="hl-tag ${rs[0]}">${rs[1]}</span>${r.to_remit ? ` <span class="text-[11px]">مانده‌ی واریز ${money(r.to_remit)}</span>` : ''}</div>
                    <div class="flex gap-1 mt-2">${ee ? `<button class="hl-btn s sm" data-be="${r.id}"><i class="fas fa-pen"></i>ویرایش</button>` : ''}${pe && r.remaining ? `<button class="hl-btn s sm" data-bp="${r.id}"><i class="fas fa-plus"></i>وصول</button>` : ''}${se && r.to_remit ? `<button class="hl-btn s sm" data-bs="${r.id}"><i class="fas fa-building-columns"></i>واریز</button>` : ''}</div></div>`; }).join('');
            return `<div class="hl-blk" data-cid="${c.id}"><div class="hl-blk-h"><div class="min-w-0"><b>${esc(c.company_name)}</b> <span class="hl-tag ${c.line === 'درمان' ? 'g' : 'b'}">${esc(c.line)}</span>${c.plan ? ` <span class="hl-tag s">${esc(c.plan)}</span>` : ''}${c.status !== 'ACTIVE' ? ` <span class="hl-tag s">${esc(H.boot.statuses[c.status])}</span>` : ''}
                    <div class="text-[11px] text-slate-500 mt-1" dir="rtl">بیمه‌نامه <span dir="ltr">${fa(c.policy_no || '—')}</span>${c.start_j ? ' · شروع ' + fa(c.start_j) : ''}${c.unit_premium ? ' · سرانه‌ی ماهانه ' + money(c.unit_premium) : ''}</div></div>
                    <div class="hl-mini"><span class="hl-tag b">صورتحساب ${short(s.billed)}</span><span class="hl-tag g">وصول ${short(s.received)}</span>${s.remaining ? `<span class="hl-tag r">مانده ${short(s.remaining)}</span>` : ''}${s.to_remit ? `<span class="hl-tag a">واریزنشده ${short(s.to_remit)}</span>` : ''}${s.unallocated > 0 ? `<span class="hl-tag c" title="مبلغِ اضافه بر صورتحساب‌ها">بستانکار ${money(s.unallocated)}</span>` : ''}
                    ${ce ? `<button class="hl-btn p sm" data-nb="${c.id}"><i class="fas fa-plus"></i>صورتحسابِ ماه</button>` : ''}<button class="hl-btn o sm" data-cv="${c.id}"><i class="fas fa-file-contract"></i>قرارداد</button></div></div>
                <div class="hl-blk-b">${b.rows.length ? `<div class="hl-tblwrap hl-scroll" style="max-height:none"><table class="hl-tbl hl-lt no-count"><thead><tr><th>ماه</th><th>نامه</th><th>پرسنل</th><th>مبلغِ صورتحساب</th><th>وصول (مبلغ، تاریخ، محل، سند)</th><th>مانده</th><th>واریز به ${esc(P.INSURER)}</th><th></th></tr></thead><tbody>${rows}</tbody>
                    <tfoot><tr><td colspan="3">جمع</td><td class="m">${money(s.billed)}</td><td class="m">${money(s.received)}</td><td class="m">${money(s.remaining)}</td><td class="m">${money(s.remitted)}</td><td></td></tr></tfoot></table></div><div class="hl-cards">${cards}</div>`
                    : '<div class="hl-empty">صورتحسابی ندارد.</div>'}</div></div>`;
        },
        bind(body, d, root) {
            const reload = () => this.load(root);
            const findRow = id => { for (const b of d.contracts) { const r = b.rows.find(x => x.id === id); if (r) return [b.contract, r]; } return [null, null]; };
            body.querySelectorAll('[data-nb]').forEach(b => b.onclick = () => this.bill(d.contracts.find(x => x.contract.id === +b.dataset.nb).contract, null, reload));
            body.querySelectorAll('[data-cv]').forEach(b => b.onclick = () => Con.view(+b.dataset.cv, reload));
            body.querySelectorAll('[data-be]').forEach(b => b.onclick = () => { const [c, r] = findRow(+b.dataset.be); this.bill(c, r, reload); });
            body.querySelectorAll('[data-bp]').forEach(b => b.onclick = () => { const [c, r] = findRow(+b.dataset.bp); Pay.add({contract_id: c.id, bill_id: r.id, amount: r.remaining, payee: r.payee}, reload); });
            body.querySelectorAll('[data-bs]').forEach(b => b.onclick = () => { const [c, r] = findRow(+b.dataset.bs); Settle.add(reload, {contract_id: c.id, installment_id: r.id, amount: r.to_remit, label: r.period_fa}); });
            body.querySelectorAll('[data-bd]').forEach(b => b.onclick = async () => { if (!(await confirmUi('حذفِ صورتحساب', 'این صورتحساب حذف شود؟', {danger: true}))) return; try { await api('bill_delete', {id: +b.dataset.bd}); toast('حذف شد.', 'success'); reload(); } catch (e) { toast(e.message, 'error'); } });
        },
        async bill(c, r, onDone) {
            const isNew = !r;
            let sg = null;
            if (isNew) { try { sg = (await api('bill_suggest', {contract_id: c.id})).suggest; } catch (e) { toast(e.message, 'error'); return; } }
            r = r || {period_j: sg.period_j, headcount: sg.headcount, unit_premium: sg.unit_premium, amount: sg.amount, payee: H.boot.settings.default_payee};
            const srcFa = {members: 'از بیمه‌شدگانِ فعال', last: 'از ماهِ قبل', contract: 'از تعدادِ قرارداد'};
            const m = modal(`<i class="fas fa-file-invoice text-emerald-600"></i>${isNew ? 'صورتحسابِ ماهانه' : 'ویرایشِ صورتحساب'} - ${conShort(c)}`, `<div class="hl-grid hl-g2">
                <label><span class="hl-lbl">ماهِ صورتحساب</span><select class="hl-sel" data-f="period_j">${periodOpts(r.period_j, 18, 4)}</select></label>
                <label><span class="hl-lbl">پرداخت به</span><select class="hl-sel" data-f="payee"><option value="US" ${r.payee !== 'INSURER' ? 'selected' : ''}>${esc(PAYEE().US)}</option><option value="INSURER" ${r.payee === 'INSURER' ? 'selected' : ''}>مستقیم به ${esc(PAYEE().INSURER)}</option></select></label>
                <label><span class="hl-lbl">شماره‌ی نامه‌ی صورتحساب</span><input class="hl-in" data-f="letter_no" value="${esc(fa(r.letter_no || ''))}"></label>
                <label><span class="hl-lbl">تاریخِ نامه</span><input class="hl-in" data-f="letter_j" data-jdate dir="ltr" value="${esc(fa(r.letter_j || ''))}"></label>
                <label><span class="hl-lbl">تعدادِ پرسنل ${sg ? `<small class="text-emerald-700">(${srcFa[sg.headcount_src] || ''})</small>` : ''}</span><input class="hl-in" data-f="headcount" value="${r.headcount ? fa(r.headcount) : ''}"></label>
                <label><span class="hl-lbl">حق‌بیمه‌ی ماهانه‌ی سرانه (ریال)</span><input class="hl-in money-input" data-f="unit_premium" value="${r.unit_premium ? money(r.unit_premium) : ''}"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">مبلغِ صورتحساب (ریال) <small class="text-slate-500">خودکار = پرسنل × سرانه؛ قابلِ تغییر</small></span><input class="hl-in money-input" data-f="amount" value="${r.amount ? money(r.amount) : ''}"></label>
                <label style="grid-column:1/-1"><span class="hl-lbl">توضیح</span><input class="hl-in" data-f="note" value="${esc(r.note || '')}"></label></div>
                <p class="text-[11px] text-slate-500 mt-2 leading-6">سررسیدِ پرداخت = تاریخِ نامه + ${fa(H.boot.settings.bill_due_days)} روز (در تنظیمات). نامه را بعداً هم می‌توانید اضافه کنید.</p>`, '<button class="hl-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>');
            const g = k => m.querySelector(`[data-f=${k}]`);
            let touched = !isNew;
            g('amount').addEventListener('input', () => { touched = true; });
            const calc = () => { if (touched && !isNew) return; const v = (+en(g('headcount').value) || 0) * num(g('unit_premium').value); if (v) g('amount').value = money(v); };
            g('headcount').addEventListener('input', () => { touched = false; calc(); }); g('unit_premium').addEventListener('input', () => { touched = false; calc(); });
            if (isNew) g('period_j').onchange = async () => { try { const s2 = (await api('bill_suggest', {contract_id: c.id, period_j: g('period_j').value})).suggest; g('headcount').value = fa(s2.headcount || ''); calc(); } catch (e) {} };
            m.querySelector('[data-save]').onclick = async ev => {
                busy(ev.currentTarget, true);
                try { await api('bill_save', {id: r.id || 0, contract_id: c.id, period_j: g('period_j').value, payee: g('payee').value, letter_no: en(g('letter_no').value), letter_j: en(g('letter_j').value), headcount: en(g('headcount').value), unit_premium: num(g('unit_premium').value), amount: num(g('amount').value), note: g('note').value});
                    m.close(); toast('صورتحساب ذخیره شد.', 'success'); if (onDone) onDone(); }
                catch (e) { toast(e.message, 'error'); busy(ev.currentTarget, false); }
            };
        },
        generate(onDone) {
            const nx = (() => { const [y, mm] = H.boot.today_j.split('/').map(Number); return `${y}/${String(mm).padStart(2, '0')}`; })();
            const mon = H.boot.contracts.filter(c => c.status === 'ACTIVE' && c.billing === 'MONTHLY');
            const m = modal('<i class="fas fa-bolt text-amber-500"></i>صدورِ صورتحساب‌های یک ماه', `<label class="block"><span class="hl-lbl">ماه</span><select class="hl-sel" data-p>${periodOpts(nx, 6, 3)}</select></label>
                <div class="hl-row mt-3"><b class="text-[12px]">قراردادهای ماهانه‌ی فعال (${fa(mon.length)})</b><div class="flex flex-col gap-1 max-h-60 overflow-auto">${mon.map(c => `<label class="flex items-center gap-2 text-[12px]"><input type="checkbox" checked data-c="${c.id}">${conShort(c)}</label>`).join('') || '<span class="text-slate-500">قراردادِ ماهانه‌ای نیست.</span>'}</div></div>
                <p class="text-[11px] text-slate-500 mt-2 leading-6">برای هر قرارداد: تعدادِ پرسنل از بیمه‌شدگانِ فعال در اولِ آن ماه (یا ماهِ قبل) × حق‌بیمه‌ی سرانه. ماهی که صورتحساب دارد رد می‌شود. شماره و تاریخِ نامه را بعداً در دفتر وارد کنید.</p>`, '<button class="hl-btn p" data-go><i class="fas fa-bolt"></i>صدور</button>');
            m.querySelector('[data-go]').onclick = async ev => {
                busy(ev.currentTarget, true);
                try { const r = await api('bill_generate', {period_j: m.querySelector('[data-p]').value, contract_ids: [...m.querySelectorAll('[data-c]:checked')].map(x => +x.dataset.c)});
                    m.close(); toast(`${fa(r.made)} صورتحساب ساخته شد.` + (r.skipped.length ? ` ${fa(r.skipped.length)} مورد رد شد: ${r.skipped.slice(0, 4).join('، ')}` : ''), r.made ? 'success' : 'info'); if (onDone) onDone(); }
                catch (e) { toast(e.message, 'error'); busy(ev.currentTarget, false); }
            };
        },
        importer(onDone) {
            const m = modal('<i class="fas fa-file-import text-emerald-600"></i>ورود از اکسلِ دفترِ ماهانه', `<p class="text-[12px] text-slate-600 leading-7">همان اکسلی که تا امروز دستی پر می‌کردید (شیت‌های درمان و عمر/حادثه، هر بیمه‌نامه یک بلوک، هر ماه یک ردیف). قرارداد با شماره‌ی بیمه‌نامه و سرانه/طرح پیدا یا ساخته می‌شود؛
                صورتحساب‌ها، وصول‌ها (با محلِ واریز و سند و شماره‌ی چک) و واریزهای به ${esc(PAYEE().INSURER)} ثبت می‌شوند. ردیف‌هایی که قبلاً وارد شده‌اند تکرار نمی‌شوند.</p>
                <div class="flex gap-2 items-center mt-2"><input type="file" class="hl-in" data-file accept=".xlsx,.csv"><button class="hl-btn s sm" data-sm><i class="fas fa-download"></i>نمونه</button></div><div data-prev class="mt-3"></div>`, '<button class="hl-btn p" data-go disabled><i class="fas fa-check"></i>ثبتِ موارد انتخاب‌شده</button>', {wide: true});
            m.querySelector('[data-sm]').onclick = () => download(url('sample', {kind: 'ledger'}));
            let tok = null;
            m.querySelector('[data-file]').onchange = async ev => {
                const f = ev.target.files[0]; if (!f) return;
                const fd = new FormData(); fd.append('file', f);
                const box = m.querySelector('[data-prev]'); box.innerHTML = '<div class="hl-empty"><i class="fas fa-spinner fa-spin"></i>در حال خواندن…</div>';
                try {
                    const r = await api('ledger_import_preview', {}, fd); tok = r.token;
                    box.innerHTML = `<div class="hl-scroll"><table class="hl-tbl no-count"><thead><tr><th><input type="checkbox" checked data-all></th><th>شیت</th><th>بیمه‌گذار</th><th>نوع / طرح</th><th>بیمه‌نامه</th><th>سرانه</th><th>ماه‌ها</th><th>صورتحسابِ تازه</th><th>وصول</th><th>واریز</th><th>قرارداد</th></tr></thead><tbody>
                        ${r.blocks.map(b => `<tr><td><input type="checkbox" checked data-pk="${b.i}"></td><td class="text-[11px]">${esc(b.sheet)}</td><td><b>${esc(b.company)}</b></td><td>${esc(b.line)}${b.plan ? ' · ' + esc(b.plan) : ''}</td><td dir="ltr" class="text-[11px]">${fa(b.policy_no)}</td><td class="m">${money(b.unit)}</td>
                            <td class="text-[11px]">${esc(b.first)} تا ${esc(b.last)}</td><td>${fa(b.new_bills)}${b.existing_bills ? ` <span class="text-slate-400">(+${fa(b.existing_bills)} موجود)</span>` : ''}</td><td>${fa(b.payments)}</td><td>${fa(b.remits)}</td>
                            <td>${b.match ? `<span class="hl-tag g">موجود</span>` : '<span class="hl-tag b">جدید</span>'}</td></tr>`).join('')}</tbody></table></div>
                        ${r.warnings.length ? `<details class="mt-2"><summary class="text-amber-700 font-bold text-[12px] cursor-pointer">${fa(r.warnings.length)} هشدار</summary><ul class="text-[11px] text-slate-600 leading-6 list-disc pr-5">${r.warnings.map(w => `<li>${esc(w)}</li>`).join('')}</ul></details>` : ''}`;
                    box.querySelector('[data-all]').onchange = e2 => box.querySelectorAll('[data-pk]').forEach(x => x.checked = e2.target.checked);
                    m.querySelector('[data-go]').disabled = false;
                } catch (e) { box.innerHTML = `<div class="hl-empty">${esc(e.message)}</div>`; }
            };
            m.querySelector('[data-go]').onclick = async ev => {
                const pick = {}; m.querySelectorAll('[data-pk]').forEach(x => pick[x.dataset.pk] = x.checked ? 1 : 0);
                busy(ev.currentTarget, true);
                try { const r = (await api('ledger_import_commit', {token: tok, pick})).result; m.close();
                    toast(`ثبت شد: ${fa(r.contracts_new)} قراردادِ جدید، ${fa(r.bills)} صورتحساب، ${fa(r.payments)} وصول، ${fa(r.remits)} واریز${r.skipped ? ' - ' + fa(r.skipped) + ' ردیفِ تکراری رد شد' : ''}.`, 'success'); await boot(true); if (onDone) onDone(); }
                catch (e) { toast(e.message, 'error'); busy(ev.currentTarget, false); }
            };
        },
    };


    // =================================================================
    //  رسیدهای دریافتی (عکس‌های گروه‌های ربات / بارگذاریِ دستی)
    // =================================================================
    const Inbox = {
        f: {status: 'NEW', q: ''},
        async render(root) {
            const f = this.f;
            root.innerHTML = shell('hl-receipts', `<div class="hl-card"><div class="hl-f">
                    <div class="hl-seg" data-st>${[['NEW', 'در انتظارِ ثبت'], ['DONE', 'ثبت‌شده'], ['REJECT', 'ردشده'], ['ALL', 'همه']].map(([a, b]) => `<button data-v="${a}" class="${f.status === a ? 'on' : ''}">${b}<span data-n="${a}"></span></button>`).join('')}</div>
                    <label style="max-width:none;flex:2 1 200px"><span>جستجو (گروه، فرستنده، توضیح، شرکت)</span><input class="hl-in" data-k="q" value="${esc(f.q)}"></label>
                    ${can('hl-receipts', 'edit') ? '<label class="hl-btn o" style="cursor:pointer"><i class="fas fa-upload text-emerald-600"></i>بارگذاریِ دستیِ رسید<input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" data-up hidden></label>' : ''}</div>
                    <p class="text-[11.5px] text-slate-500 mt-2 leading-6"><i class="fas fa-robot text-emerald-600"></i> هر عکس یا PDF که در گروه‌های تعیین‌شده (تنظیمات ← گروه‌های رسید) فرستاده شود، با نامِ «رسید + تاریخ و ساعتِ دریافت» در پوشه‌ی موقت می‌آید. روی هر رسید بزنید، اطلاعاتِ پرداخت را بنویسید؛ با نامِ درست به پوشه‌ی همان شرکت و بیمه‌نامه منتقل و در دفترِ همان ماه ثبت می‌شود.</p></div>
                <div data-list><div class="hl-card hl-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            root.querySelectorAll('[data-st] button').forEach(b => b.onclick = () => { f.status = b.dataset.v; this.render(root); });
            let tm; const q = root.querySelector('[data-k=q]'); q.oninput = () => { clearTimeout(tm); tm = setTimeout(() => { f.q = q.value; this.load(root); }, 300); };
            const up = root.querySelector('[data-up]'); if (up) up.onchange = async () => { if (!up.files.length) return; const fd = new FormData(); [...up.files].forEach(x => fd.append('files[]', x));
                try { const r = await api('inbox_upload', {}, fd); toast(fa(r.count) + ' رسید اضافه شد.', 'success'); f.status = 'NEW'; this.render(root); } catch (e) { toast(e.message, 'error'); } };
            this.load(root);
        },
        async load(root) {
            const box = root.querySelector('[data-list]');
            let r;
            try { r = await api('inbox_list', this.f); } catch (e) { box.innerHTML = `<div class="hl-card hl-empty">${esc(e.message)}</div>`; return; }
            Object.entries({NEW: r.counts.NEW || 0, DONE: r.counts.DONE || 0, REJECT: r.counts.REJECT || 0}).forEach(([k, n]) => { const el = root.querySelector(`[data-n=${k}]`); if (el) el.innerHTML = n ? ` (${fa(n)})` : ''; });
            H.boot.inbox_new = r.counts.NEW || 0;
            this.rows = r.rows;
            if (!r.rows.length) { box.innerHTML = `<div class="hl-card hl-empty"><i class="fas fa-inbox"></i>${this.f.status === 'NEW' ? 'رسیدِ ثبت‌نشده‌ای نیست.' : 'موردی نیست.'}</div>`; return; }
            box.innerHTML = `<div class="hl-card"><div class="hl-rgrid">${r.rows.map((x, i) => `<div class="hl-rc" data-i="${i}"><div class="im" ${x.is_pdf ? '' : `style="background-image:url('${url('inbox_file', {id: x.id})}')"`}>${x.is_pdf ? '<i class="fas fa-file-pdf text-rose-500"></i>' : ''}</div>
                <div class="tx"><b class="truncate">${esc(x.chat_title || 'بارگذاریِ دستی')}</b><span class="text-slate-500">${fa(x.received_at_j)}${x.sender ? ' · ' + esc(x.sender) : ''}</span>
                ${x.status === 'DONE' ? `<span class="hl-tag g">${esc(x.company_name || '')}${x.bill ? ' · ' + esc(x.bill) : ''} · ${money(x.pay_amount)}</span>` : x.status === 'REJECT' ? `<span class="hl-tag r">رد شد${x.note ? ': ' + esc(x.note) : ''}</span>` : '<span class="hl-tag a">در انتظارِ ثبت</span>'}
                ${x.caption ? `<span class="text-[10.5px] text-slate-600 truncate">${esc(x.caption)}</span>` : ''}</div></div>`).join('')}</div></div>`;
            box.querySelectorAll('[data-i]').forEach(c => c.onclick = () => this.review(+c.dataset.i, root));
        },
        async review(i, root) {
            const x = this.rows[i]; if (!x) return;
            const B = H.boot, P = PAYEE(), done = x.status !== 'NEW';
            const m = modal(`<i class="fas fa-receipt text-emerald-600"></i>رسیدِ ${fa(i + 1)} از ${fa(this.rows.length)} - ${esc(x.chat_title || 'بارگذاریِ دستی')}`, `<div class="hl-rv">
                <div class="pv">${x.is_pdf ? `<iframe src="${url('inbox_file', {id: x.id})}"></iframe>` : `<img src="${url('inbox_file', {id: x.id})}" alt="">`}</div>
                <div><div class="text-[11.5px] text-slate-600 leading-6 mb-2">دریافت: <b>${fa(x.received_at_j)}</b>${x.sender ? ' · فرستنده: ' + esc(x.sender) : ''}<br><span class="hl-fname">${esc(x.file_name)}</span>${x.caption ? `<div class="mt-1">توضیحِ فرستنده: <b>${esc(x.caption)}</b></div>` : ''}</div>
                ${done ? `<div class="hl-row">${x.status === 'DONE' ? `ثبت‌شده برای <b>${esc(x.company_name)}</b> ${x.bill ? '· ' + esc(x.bill) : ''} · ${money(x.pay_amount)} ریال · ${fa(x.pay_j || '')}<div class="text-[11px] text-slate-500">${esc(x.by || '')} - ${fa(x.handled_at_j)}</div>` : `رد شده${x.note ? ': ' + esc(x.note) : ''}`}</div>` : `
                <div class="hl-grid hl-g2">
                    <label style="grid-column:1/-1"><span class="hl-lbl">شرکت و بیمه‌نامه</span><select class="hl-sel" data-f="contract_id">${conOpts(this.lastC || '', '— انتخاب کنید —')}</select></label>
                    <div style="grid-column:1/-1"><div class="hl-tg" data-mode><button data-v="new" class="on">ثبتِ دریافتِ جدید</button><button data-v="attach">پیوست به دریافتِ ثبت‌شده</button></div></div>
                    <label style="grid-column:1/-1;display:none" data-att><span class="hl-lbl">دریافتِ ثبت‌شده (مثلاً از اکسلِ دفتر)</span><select class="hl-sel" data-f="payment_id"><option value="">— اول قرارداد را انتخاب کنید —</option></select></label>
                    <label style="grid-column:1/-1" data-nw><span class="hl-lbl">بابتِ صورتحسابِ ماهِ</span><select class="hl-sel" data-f="bill_id"><option value="">— اول قرارداد را انتخاب کنید —</option></select></label>
                    <label data-nw><span class="hl-lbl">مبلغ (ریال)</span><input class="hl-in money-input" data-f="amount"></label>
                    <label data-nw><span class="hl-lbl">تاریخِ پرداخت</span><input class="hl-in" data-f="pay_j" data-jdate dir="ltr" value="${fa(B.today_j)}"></label>
                    <div style="grid-column:1/-1" data-nw><span class="hl-lbl">پرداخت به</span><div class="hl-tg" data-payee><button data-v="US" class="on">${esc(P.US)}</button><button data-v="INSURER">مستقیم به ${esc(P.INSURER)}</button></div></div>
                    <label data-nw><span class="hl-lbl">روش</span><select class="hl-sel" data-f="method">${methodOpts('transfer')}</select></label>
                    <label data-nw><span class="hl-lbl">اسنادِ واریزی</span><select class="hl-sel" data-f="doc_type"><option value="">—</option>${docOpts('فیش واریزی')}</select></label>
                    <label data-nw><span class="hl-lbl">شماره‌ی پیگیری / فیش</span><input class="hl-in" data-f="ref_no" dir="ltr"></label><label data-nw><span class="hl-lbl">بانک</span><input class="hl-in" data-f="bank"></label>
                    <label data-chq data-nw style="display:none"><span class="hl-lbl">شماره‌ی چک</span><input class="hl-in" data-f="cheque_no" dir="ltr"></label><label data-chq data-nw style="display:none"><span class="hl-lbl">سررسیدِ چک</span><input class="hl-in" data-f="cheque_due_j" data-jdate dir="ltr"></label>
                    <label style="grid-column:1/-1" data-nw><span class="hl-lbl">توضیح</span><input class="hl-in" data-f="note"></label></div>
                <div class="mt-2 text-[11px] text-slate-600">نامِ فایل بعد از ثبت:</div><div class="hl-fname" data-fn>—</div>`}</div></div>`,
                `<button class="hl-btn s" data-prev ${i === 0 ? 'disabled' : ''}><i class="fas fa-arrow-right"></i>قبلی</button><button class="hl-btn s" data-next ${i >= this.rows.length - 1 ? 'disabled' : ''}>بعدی<i class="fas fa-arrow-left"></i></button>
                 ${!done && can('hl-receipts', 'delete') ? '<button class="hl-btn r" data-rej><i class="fas fa-ban"></i>رد / تکراری</button>' : ''}${x.status === 'REJECT' && can('hl-receipts', 'edit') ? '<button class="hl-btn a" data-res><i class="fas fa-rotate-left"></i>برگرداندن</button>' : ''}
                 ${!done && can('hl-receipts', 'edit') ? '<button class="hl-btn p" data-save><i class="fas fa-check"></i>ثبت و انتقال به پوشه</button>' : ''}`, {wide: true});
            const go = j => { m.close(); document.removeEventListener('keydown', key); this.review(j, root); };
            const key = e => { if (!document.body.contains(m)) { document.removeEventListener('keydown', key); return; } if (e.target.closest && e.target.closest('input,select,textarea')) return; if (e.key === 'ArrowLeft' && i < this.rows.length - 1) go(i + 1); if (e.key === 'ArrowRight' && i > 0) go(i - 1); };
            document.addEventListener('keydown', key);
            m.querySelector('[data-prev]').onclick = () => go(i - 1); m.querySelector('[data-next]').onclick = () => go(i + 1);
            const img = m.querySelector('.pv img'); if (img) img.onclick = () => img.classList.toggle('z');
            const res = m.querySelector('[data-res]'); if (res) res.onclick = async () => { try { await api('inbox_restore', {id: x.id}); m.close(); this.load(root); } catch (e) { toast(e.message, 'error'); } };
            if (done) return;
            const g = k => m.querySelector(`[data-f=${k}]`);
            let payee = 'US', bills = [], pays = [], mode = 'new';
            const setMode = v => { mode = v; m.querySelectorAll('[data-mode] button').forEach(b => b.classList.toggle('on', b.dataset.v === v)); m.querySelectorAll('[data-nw]').forEach(z => z.style.display = v === 'new' ? (z.hasAttribute('data-chq') && g('method').value !== 'cheque' ? 'none' : '') : 'none'); m.querySelector('[data-att]').style.display = v === 'attach' ? '' : 'none'; fname(); };
            m.querySelectorAll('[data-mode] button').forEach(b => b.onclick = () => setMode(b.dataset.v));
            const setPayee = v => { payee = v; m.querySelectorAll('[data-payee] button').forEach(b => b.classList.toggle('on', b.dataset.v === v)); fname(); };
            m.querySelectorAll('[data-payee] button').forEach(b => b.onclick = () => setPayee(b.dataset.v));
            g('method').onchange = () => { m.querySelectorAll('[data-chq]').forEach(z => z.style.display = g('method').value === 'cheque' ? '' : 'none'); if (g('method').value === 'cheque') g('doc_type').value = 'چک'; };
            const fname = () => { const c = H.boot.contracts.find(z => z.id === +g('contract_id').value), b = bills.find(z => z.id === +g('bill_id').value);
                if (mode === 'attach') { const py = pays.find(z => z.id === +g('payment_id').value); m.querySelector('[data-fn]').textContent = c && py ? `درمان تکمیلی / ${c.company_name} / … / رسیدهای دریافتی / رسید ${c.company_name} - پرداخت ${py.pay_j.replace(/\//g, '-')} - ${money(py.amount)} ریال` : '—'; return; }
                m.querySelector('[data-fn]').textContent = c ? `درمان تکمیلی / ${c.company_name} / ${c.line || 'درمان'} ${c.policy_no || ''}${c.plan ? ' - ' + c.plan : ''} / رسیدهای دریافتی / رسید ${c.company_name}${b && b.period_fa ? ' - ' + b.period_fa : ''} - پرداخت ${en(g('pay_j').value).replace(/\//g, '-')} - ${money(num(g('amount').value))} ریال` : '—'; };
            const loadBills = async () => {
                const cid = g('contract_id').value, sel = g('bill_id');
                bills = []; sel.innerHTML = '<option value="">بدونِ ماهِ مشخص (قدیمی‌ترین ماهِ باز)</option>';
                if (!cid) { fname(); return; }
                this.lastC = cid;
                try { const d = await api('contract_get', {id: cid}); bills = d.installments; pays = d.payments.filter(z => z.status === 'OK');
                    g('payment_id').innerHTML = '<option value="">— انتخاب کنید —</option>' + pays.map(z => `<option value="${z.id}">${fa(z.pay_j)} - ${money(z.amount)} ریال - ${esc(z.doc_type || z.method_fa)}${z.cheque_no ? ' ' + fa(z.cheque_no) : ''}${z.has_file ? ' (رسید دارد)' : ' (بدونِ رسید)'}</option>`).join('');
                    const open = bills.filter(b => b.remaining > 0);
                    sel.innerHTML += bills.map(b => `<option value="${b.id}" ${b.remaining <= 0 ? 'disabled' : ''}>${esc(b.period_fa || 'قسط ' + fa(b.seq))} - ${b.remaining > 0 ? 'مانده ' + money(b.remaining) : 'وصول شده'}${b.letter_no ? ' - نامه ' + fa(b.letter_no) : ''}</option>`).join('');
                    if (open[0]) { sel.value = open[0].id; if (!g('amount').value) g('amount').value = money(open[0].remaining); setPayee(open[0].payee); }
                } catch (e) {}
                fname();
            };
            g('contract_id').onchange = loadBills;
            g('payment_id').onchange = fname;
            g('bill_id').onchange = () => { const b = bills.find(z => z.id === +g('bill_id').value); if (b) { g('amount').value = money(b.remaining); setPayee(b.payee); } fname(); };
            ['amount', 'pay_j'].forEach(k => { g(k).addEventListener('input', fname); g(k).addEventListener('change', fname); });
            if (window.MoneyInput) MoneyInput.apply(m);
            if (g('contract_id').value) loadBills();
            const rej = m.querySelector('[data-rej]'); if (rej) rej.onclick = async () => {
                const mm = modal('<i class="fas fa-ban text-rose-600"></i>ردِ رسید', '<label><span class="hl-lbl">دلیل</span><input class="hl-in" data-r placeholder="مثلاً تکراری، ناخوانا، مربوط به بیمه‌ی دیگر"></label>', '<button class="hl-btn r" data-ok>رد</button>');
                mm.querySelector('[data-ok]').onclick = async () => { try { await api('inbox_reject', {id: x.id, note: mm.querySelector('[data-r]').value}); mm.close(); m.close(); toast('رد شد.', 'success'); await this.load(root); if (this.rows[i]) this.review(Math.min(i, this.rows.length - 1), root); } catch (e) { toast(e.message, 'error'); } };
            };
            m.querySelector('[data-save]').onclick = async ev => {
                if (!g('contract_id').value) { toast('شرکت و بیمه‌نامه را انتخاب کنید.', 'error'); return; }
                if (mode === 'attach' && !g('payment_id').value) { toast('دریافتِ ثبت‌شده را انتخاب کنید.', 'error'); return; }
                busy(ev.currentTarget, true);
                try {
                    const r = mode === 'attach' ? await api('inbox_attach', {id: x.id, payment_id: g('payment_id').value, note: g('note').value}) : await api('inbox_assign', {id: x.id, contract_id: g('contract_id').value, bill_id: g('bill_id').value, amount: num(g('amount').value), pay_j: en(g('pay_j').value), payee, method: g('method').value, doc_type: g('doc_type').value,
                        ref_no: en(g('ref_no').value), bank: g('bank').value, cheque_no: en(g('cheque_no').value), cheque_due_j: en(g('cheque_due_j').value), note: g('note').value});
                    toast((mode === 'attach' ? 'به دریافتِ ثبت‌شده پیوست و به پوشه‌ی قرارداد منتقل شد.' : 'ثبت و به پوشه‌ی قرارداد منتقل شد.') + (r.unallocated > 0 ? ' (' + money(r.unallocated) + ' ریال بستانکار)' : ''), 'success');
                    m.close(); document.removeEventListener('keydown', key);
                    await this.load(root);
                    if (this.f.status === 'NEW' && this.rows.length) this.review(Math.min(i, this.rows.length - 1), root);
                } catch (e) { toast(e.message, 'error'); busy(ev.currentTarget, false); }
            };
        },
    };

    // =================================================================
    //  بیمه‌شدگان
    // =================================================================
    const Members = {
        f: {contract_id: '', state: 'active', q: ''},
        async render(root) {
            const f = this.f;
            root.innerHTML = shell('hl-members', `<div class="hl-card"><div class="hl-f">
                    <label style="max-width:340px"><span>قرارداد</span><select class="hl-sel" data-k="contract_id">${conOpts(f.contract_id, 'همه‌ی قراردادها')}</select></label>
                    <label><span>وضعیت</span><select class="hl-sel" data-k="state"><option value="active" ${f.state === 'active' ? 'selected' : ''}>فعال</option><option value="ended" ${f.state === 'ended' ? 'selected' : ''}>پایان‌یافته</option><option value="" ${!f.state ? 'selected' : ''}>همه</option></select></label>
                    <label style="max-width:none;flex:2 1 200px"><span>جستجو (نام، کد ملی، تلفن، بیمه‌شده‌ی اصلی)</span><input class="hl-in" data-k="q" value="${esc(f.q)}"></label>
                    ${can('hl-members', 'create') ? '<button class="hl-btn p" data-imp><i class="fas fa-file-import"></i>ورود از اکسلِ بیمه‌گر</button><button class="hl-btn o" data-add><i class="fas fa-user-plus text-emerald-600"></i>افزودن</button>' : ''}
                    ${can('hl-members', 'export') ? '<button class="hl-btn o" data-xls><i class="fas fa-file-excel text-emerald-600"></i>اکسل</button>' : ''}<button class="hl-btn s" data-sm><i class="fas fa-download"></i>نمونه</button></div></div>
                <div class="hl-card" data-list><div class="hl-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            let tm;
            root.querySelectorAll('[data-k]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => { clearTimeout(tm); tm = setTimeout(() => { f[el.dataset.k] = el.value; this.load(root); }, 300); }));
            const im = root.querySelector('[data-imp]'); if (im) im.onclick = () => this.importer(() => this.load(root));
            const ad = root.querySelector('[data-add]'); if (ad) ad.onclick = () => this.edit(null, () => this.load(root));
            const x = root.querySelector('[data-xls]'); if (x) x.onclick = () => download(url('members_export', f));
            root.querySelector('[data-sm]').onclick = () => download(url('sample', {kind: 'members'}));
            this.load(root);
        },
        async load(root) {
            const box = root.querySelector('[data-list]');
            let r;
            try { r = await api('members_list', this.f); } catch (e) { box.innerHTML = `<div class="hl-empty">${esc(e.message)}</div>`; return; }
            this.rows = r.rows;
            if (!r.rows.length) { box.innerHTML = '<div class="hl-empty"><i class="fas fa-users"></i>بیمه‌شده‌ای نیست. «لیستِ بیمه‌شدگان» را از سامانه‌ی بیمه‌گر بگیرید و با «ورود از اکسل» وارد کنید.</div>'; return; }
            const ed = can('hl-members', 'edit'), dl = can('hl-members', 'delete');
            const mains = r.rows.filter(m => m.relation === 'اصلی' || m.nid === m.main_nid).length;
            box.innerHTML = `<h3><i class="fas fa-users text-emerald-600"></i>بیمه‌شدگان <small>${fa(r.total)} نفر · ${fa(mains)} اصلی · ${fa(r.total - mains)} تبعی</small></h3>
                <div class="hl-tblwrap hl-scroll"><table class="hl-tbl no-count"><thead><tr><th>نام</th><th>کد ملی</th><th>نسبت</th><th>بیمه‌شده‌ی اصلی</th><th>قرارداد / طرح</th><th>پوشش</th><th>وضعیت</th><th>ماهانه</th><th></th></tr></thead><tbody>
                ${r.rows.map((m, i) => `<tr><td><b>${esc((m.first_name || '') + ' ' + (m.last_name || ''))}</b>${m.mobile ? `<span class="block text-[10.5px] text-slate-500" dir="ltr">${fa(m.mobile)}</span>` : ''}</td><td dir="ltr">${fa(m.nid || '')}</td><td>${esc(m.relation || '')}</td><td class="text-[11.5px]">${esc(m.main_name || '')}</td>
                    <td class="text-[11.5px]">${esc(m.company_name)}${m.plan ? ' · ' + esc(m.plan) : ''}</td><td class="text-[11px]">${fa(m.start_j || '')} تا ${fa(m.end_j || '')}</td><td><span class="hl-tag ${m.active ? 'g' : 's'}">${m.active ? 'فعال' : 'خارج‌شده'}</span>${m.status && m.status !== 'فعال' ? ` <span class="hl-tag a">${esc(m.status)}</span>` : ''}</td>
                    <td class="m">${m.monthly ? money(m.monthly) : ''}</td><td class="whitespace-nowrap">${ed ? `<button class="hl-btn s sm" data-e="${i}"><i class="fas fa-pen"></i></button>` : ''}${dl ? `<button class="hl-btn r sm" data-d="${m.id}"><i class="fas fa-trash"></i></button>` : ''}</td></tr>`).join('')}</tbody></table></div>
                <div class="hl-cards">${r.rows.map((m, i) => `<div class="hl-pc"><div class="flex justify-between"><b>${esc((m.first_name || '') + ' ' + (m.last_name || ''))}</b><span class="hl-tag ${m.active ? 'g' : 's'}">${m.active ? 'فعال' : 'خارج‌شده'}</span></div><div class="text-[11px] text-slate-600 mt-1">${fa(m.nid || '')} · ${esc(m.relation || '')} · ${esc(m.company_name)}</div><div class="text-[11px] text-slate-500">${fa(m.start_j || '')} تا ${fa(m.end_j || '')}</div>${ed ? `<button class="hl-btn s sm mt-2" data-e="${i}"><i class="fas fa-pen"></i>ویرایش</button>` : ''}</div>`).join('')}</div>`;
            box.querySelectorAll('[data-e]').forEach(b => b.onclick = () => this.edit(this.rows[+b.dataset.e], () => this.load(root)));
            box.querySelectorAll('[data-d]').forEach(b => b.onclick = async () => { if (!(await confirmUi('حذف', 'این بیمه‌شده حذف شود؟', {danger: true}))) return; try { await api('member_delete', {id: b.dataset.d}); this.load(root); } catch (e) { toast(e.message, 'error'); } });
        },
        edit(m, onDone) {
            m = m || {contract_id: +this.f.contract_id || '', relation: 'اصلی'};
            const fl = (k, l, x = '') => `<label><span class="hl-lbl">${l}</span><input class="hl-in" data-f="${k}" value="${esc(fa(m[k] || ''))}" ${x}></label>`;
            const md = modal('<i class="fas fa-user text-emerald-600"></i>' + (m.id ? 'ویرایشِ بیمه‌شده' : 'بیمه‌شده‌ی جدید'), `<div class="hl-grid hl-g3">
                <label style="grid-column:1/-1"><span class="hl-lbl">قرارداد</span><select class="hl-sel" data-f="contract_id">${conOpts(m.contract_id, '— انتخاب کنید —')}</select></label>
                ${fl('first_name', 'نام')}${fl('last_name', 'نام خانوادگی')}${fl('nid', 'کد ملی', 'dir="ltr"')}${fl('birth_j', 'تاریخ تولد', 'data-jdate dir="ltr"')}${fl('father', 'نام پدر')}${fl('mobile', 'تلفن', 'dir="ltr"')}
                ${fl('relation', 'نسبت (اصلی/همسر/فرزند…)')}${fl('main_name', 'بیمه‌شده‌ی اصلی')}${fl('main_nid', 'کد ملیِ اصلی', 'dir="ltr"')}${fl('start_j', 'شروعِ پوشش', 'data-jdate dir="ltr"')}${fl('end_j', 'پایانِ پوشش', 'data-jdate dir="ltr"')}${fl('status', 'وضعیت')}
                ${fl('plan', 'طرح / گروه')}<label><span class="hl-lbl">حق‌بیمه‌ی ماهانه</span><input class="hl-in money-input" data-f="monthly" value="${m.monthly ? money(m.monthly) : ''}"></label>${fl('sheba', 'شبا', 'dir="ltr"')}</div>`, '<button class="hl-btn p" data-s><i class="fas fa-floppy-disk"></i>ذخیره</button>');
            md.querySelector('[data-s]').onclick = async () => {
                const b = {id: m.id || 0}; md.querySelectorAll('[data-f]').forEach(x => b[x.dataset.f] = x.classList.contains('money-input') ? num(x.value) : (x.dir === 'ltr' ? en(x.value) : x.value));
                try { await api('member_save', b); md.close(); toast('ذخیره شد.', 'success'); if (onDone) onDone(); } catch (e) { toast(e.message, 'error'); }
            };
        },
        importer(onDone) {
            const m = modal('<i class="fas fa-file-import text-emerald-600"></i>ورودِ لیستِ بیمه‌شدگان', `<p class="text-[12px] text-slate-600 leading-7">همان اکسلِ «لیست بیمه‌شدگان» سامانه‌ی ${esc(H.boot.settings.insurer_label)} (کد ملی، نسبت، تاریخ شروع/پایانِ پوشش، کد گروه بیمه‌شدگان…). هر نفر بر اساسِ «کد گروه» به طرحِ هم‌نامِ همان بیمه‌نامه می‌رود؛ افرادِ موجود به‌روز می‌شوند.
                تعدادِ «پرسنلِ» صورتحسابِ هر ماه از همین فهرست پیشنهاد می‌شود.</p>
                <input type="file" class="hl-in mt-2" data-file accept=".xlsx,.csv"><div data-prev class="mt-3"></div>`, '<button class="hl-btn p" data-go disabled><i class="fas fa-check"></i>ثبت</button>', {wide: true});
            let tok = null;
            m.querySelector('[data-file]').onchange = async ev => {
                const f = ev.target.files[0]; if (!f) return;
                const fd = new FormData(); fd.append('file', f); fd.append('contract_id', this.f.contract_id || '');
                const box = m.querySelector('[data-prev]'); box.innerHTML = '<div class="hl-empty"><i class="fas fa-spinner fa-spin"></i></div>';
                try {
                    const r = await api('members_import_preview', {}, fd); tok = r.token;
                    box.innerHTML = `<div class="hl-row"><b>${fa(r.count)} نفر در فایل (${fa(r.active)} با پوششِ فعال)</b>${r.policies.length ? `<span class="text-[11.5px]">شماره‌ی قرارداد در فایل: <span dir="ltr">${r.policies.map(fa).join('، ')}</span></span>` : ''}
                        <label><span class="hl-lbl">به قراردادِ</span><select class="hl-sel" data-c>${conOpts(r.contract_id || '', '— انتخاب کنید —')}</select></label></div>
                        <div class="hl-scroll mt-2"><table class="hl-tbl no-count"><thead><tr><th>نام</th><th>کد ملی</th><th>نسبت</th><th>گروه</th><th>شروع</th><th>پایان</th></tr></thead><tbody>${r.sample.map(x => `<tr><td>${esc(x.name)}</td><td dir="ltr">${fa(x.nid)}</td><td>${esc(x.relation)}</td><td>${esc(x.plan)}</td><td>${fa(x.start_j)}</td><td>${fa(x.end_j)}</td></tr>`).join('')}</tbody></table></div>`;
                    m.querySelector('[data-go]').disabled = false;
                } catch (e) { box.innerHTML = `<div class="hl-empty">${esc(e.message)}</div>`; }
            };
            m.querySelector('[data-go]').onclick = async ev => {
                const cid = m.querySelector('[data-c]').value; if (!cid) { toast('قرارداد را انتخاب کنید.', 'error'); return; }
                busy(ev.currentTarget, true);
                try { const r = (await api('members_import_commit', {token: tok, contract_id: cid})).result; m.close();
                    toast(`${fa(r.new)} نفرِ جدید، ${fa(r.upd)} به‌روزرسانی.` + (r.missing.length ? ` ${fa(r.missing.length)} نفرِ قبلی در این فایل نبودند.` : ''), 'success'); this.f.contract_id = cid; if (onDone) onDone(); }
                catch (e) { toast(e.message, 'error'); busy(ev.currentTarget, false); }
            };
        },
    };

    window.Health = {open, newPayment: o => boot().then(() => Pay.add(o || {}, () => {}))};
})();
