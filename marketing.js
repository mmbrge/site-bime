// فایل: marketing.js
// بخشِ «بازاریابی و فروش»: داشبوردِ فروشِ کلی و هر نفر، فروش‌ها (ثبت/ویرایش/ابطال)، محاسبه‌گرِ حق بازاریابی و پاداشِ مشتری،
// بازاریابان و کارشناسانِ تماس (قوانینِ اختصاصی)، تنظیمات (حقوق و هدف، انواعِ بیمه‌نامه و پله‌ها، سطح‌ها، پاداش‌ها)، اکسل و PDF.
(function () {
    'use strict';
    const API = 'api/mk_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => fa((Number(v) || 0).toLocaleString('en-US'));
    const short = v => { const n = Number(v) || 0, a = Math.abs(n); return a >= 1e9 ? fa((n / 1e9).toFixed(a >= 1e10 ? 0 : 1)) + ' میلیارد' : a >= 1e6 ? fa((n / 1e6).toFixed(a >= 1e8 ? 0 : 1)) + ' میلیون' : a >= 1e3 ? fa(Math.round(n / 1e3)) + ' هزار' : fa(n); };
    const num = v => Number(String(en(v)).replace(/[^\d.]/g, '')) || 0;
    const mv = v => (v === null || v === undefined || v === '') ? '' : money(v);
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const C1 = '#2a78d6', C2 = '#eb6834';
    const isPhone = () => window.matchMedia && matchMedia('(max-width: 767px)').matches;
    const K = {boot: null, tab: null, f: {}, sales: {f: {status: 'OK'}}, set: {sub: 'salary'}};
    const can = (p, op = 'view') => !!(K.boot && (K.boot.perms[p] || []).includes(op));
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
    const confirmUi = async (t, m, o) => (window.uiConfirm ? uiConfirm(t, m, o || {}) : confirm(m));
    const mi = (name, v, ph = '') => `<input class="mk-in money-input" data-f="${name}" inputmode="numeric" placeholder="${esc(ph)}" value="${esc(mv(v))}">`;

    const STYLE = `
    .mk{direction:rtl;font-size:12.5px;color:#0f172a}.mk *{box-sizing:border-box}
    .mk-hub{display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between;background:linear-gradient(120deg,#7c2d12,#c2410c 45%,#be185d);border-radius:24px;padding:16px 18px;color:#fff;box-shadow:0 22px 40px -28px #be185d;position:relative;overflow:hidden}
    .mk-hub::after{content:'';position:absolute;width:260px;height:260px;border-radius:50%;left:-70px;top:-150px;background:rgba(255,255,255,.1);pointer-events:none}
    .mk-hub .t{display:flex;align-items:center;gap:12px;z-index:1}.mk-hub .ic{width:44px;height:44px;border-radius:15px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:19px}
    .mk-hub b{font-size:17px;font-weight:900;display:block}.mk-hub small{opacity:.85;font-size:11px}
    .mk-nav{display:flex;gap:6px;flex-wrap:wrap;z-index:1}.mk-nav button{border:0;font-family:inherit;font-weight:800;font-size:12px;border-radius:14px;padding:8px 13px;background:rgba(255,255,255,.14);color:#fff;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
    .mk-nav button.on{background:#fff;color:#9a3412}
    .mk-card{background:#fff;border:1px solid #eee6e2;border-radius:20px;padding:16px;box-shadow:0 10px 30px -26px rgba(15,23,42,.35)}
    .mk-card h3{font-size:13.5px;font-weight:900;display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}.mk-card h3 small{font-weight:600;color:#64748b;font-size:11px}
    .mk-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:13px;padding:8px 13px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;white-space:nowrap;text-decoration:none}
    .mk-btn:disabled{opacity:.45;cursor:not-allowed}.mk-btn.p{background:linear-gradient(120deg,#c2410c,#be185d);color:#fff}.mk-btn.s{background:#f1f5f9;color:#334155}.mk-btn.o{background:#fff;color:#334155;border:1px solid #e2e8f0}
    .mk-btn.g{background:#059669;color:#fff}.mk-btn.r{background:#fff1f2;color:#be123c}.mk-btn.a{background:#fffbeb;color:#b45309}.mk-btn.sm{padding:5px 9px;font-size:11px;border-radius:10px}
    .mk-in,.mk-sel,.mk-ta{border:1px solid #e2dcd8;border-radius:12px;padding:8px 10px;font-size:12.5px;font-family:inherit;background:#fff;width:100%;min-width:0}
    .mk-in:focus,.mk-sel:focus,.mk-ta:focus{outline:2px solid #fed7aa;border-color:#fb923c}
    .mk-lbl{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px}
    .mk-grid{display:grid;gap:12px}.mk-g2{grid-template-columns:repeat(2,minmax(0,1fr))}.mk-g3{grid-template-columns:repeat(3,minmax(0,1fr))}.mk-g4{grid-template-columns:repeat(4,minmax(0,1fr))}
    .mk-f{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}.mk-f>label{display:flex;flex-direction:column;gap:3px;flex:1 1 130px;max-width:220px}.mk-f>label span{font-size:10.5px;font-weight:800;color:#64748b}
    .mk-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}
    .mk-kpi{background:#fff;border:1px solid #eee6e2;border-radius:18px;padding:13px 14px}.mk-kpi .k{font-size:11px;font-weight:800;color:#64748b}.mk-kpi .v{font-size:19px;font-weight:900;margin-top:5px}.mk-kpi .s{font-size:10.5px;color:#64748b;margin-top:2px}
    .mk-bar{height:7px;border-radius:99px;background:#f1f5f9;overflow:hidden;margin-top:7px}.mk-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#16a34a,#22c55e)}
    .mk-tag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;border-radius:9px;padding:2px 8px;background:#f1f5f9;color:#475569;white-space:nowrap}
    .mk-tag.g{background:#dcfce7;color:#166534}.mk-tag.r{background:#ffe4e6;color:#9f1239}.mk-tag.a{background:#fef3c7;color:#92400e}.mk-tag.b{background:#e0f2fe;color:#075985}.mk-tag.v{background:#ede9fe;color:#5b21b6}
    .mk-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}.mk-tbl th{background:#fff7ed;color:#9a3412;font-weight:900;font-size:10.5px;padding:9px 8px;text-align:right;white-space:nowrap;position:sticky;top:0;z-index:1}
    .mk-tbl td{padding:9px 8px;border-top:1px solid #f5efec;vertical-align:middle}.mk-tbl tbody tr:hover td{background:#fffaf5}.mk-tbl tr.void td{opacity:.5;text-decoration:line-through}
    .mk-scroll{overflow:auto;border-radius:16px;border:1px solid #f5efec;max-height:66vh}
    .mk-cards{display:none;flex-direction:column;gap:10px}.mk-pc{background:#fff;border:1px solid #eee6e2;border-radius:18px;padding:12px 13px}
    .mk-empty{text-align:center;color:#94a3b8;padding:34px 10px;font-weight:700}.mk-empty i{font-size:28px;display:block;margin-bottom:8px;color:#cbd5e1}
    .mk-ov{position:fixed;inset:0;z-index:9500;background:rgba(15,23,42,.5);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:24px 12px;overflow:auto;direction:rtl}
    .mk-md{background:#fff;border-radius:24px;width:100%;max-width:680px;box-shadow:0 30px 60px -25px rgba(0,0,0,.5);animation:mkPop .18s ease-out}.mk-md.w{max-width:980px}
    @keyframes mkPop{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}
    .mk-mh{display:flex;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #f5efec}.mk-mh h3{font-size:14.5px;font-weight:900}
    .mk-mb{padding:16px 18px}.mk-mf{display:flex;gap:8px;justify-content:flex-end;padding:12px 18px;border-top:1px solid #f5efec;flex-wrap:wrap}
    .mk-x{margin-right:auto;width:36px;height:36px;border-radius:12px;background:#f1f5f9;border:0;cursor:pointer}
    .mk-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:50%;color:#fff;font-weight:900;flex-shrink:0;box-shadow:inset 0 -3px 0 rgba(0,0,0,.18),0 4px 10px -4px rgba(0,0,0,.4);position:relative;overflow:hidden}
    .mk-badge img{width:100%;height:100%;object-fit:cover}
    .mk-badge::after{content:'';position:absolute;inset:0;background:linear-gradient(140deg,rgba(255,255,255,.45),transparent 45%)}
    .mk-person{display:flex;align-items:center;gap:10px}
    .mk-rank{width:26px;height:26px;border-radius:9px;background:#fff7ed;color:#c2410c;font-weight:900;display:inline-flex;align-items:center;justify-content:center;font-size:12px}
    .mk-chart{position:relative}.mk-chart svg{display:block;width:100%;height:auto;overflow:visible}
    .mk-tip{position:absolute;pointer-events:none;background:#0f172a;color:#fff;border-radius:12px;padding:8px 10px;font-size:11px;line-height:1.8;white-space:nowrap;z-index:5;opacity:0;transition:opacity .12s}
    .mk-tip .sw{display:inline-block;width:9px;height:9px;border-radius:3px;margin-left:5px}
    .mk-leg{display:flex;gap:14px;font-size:11px;font-weight:800;color:#475569;margin-bottom:6px}.mk-leg i{display:inline-block;width:11px;height:11px;border-radius:4px;margin-left:5px;vertical-align:-1px}
    .mk-hb{display:flex;flex-direction:column;gap:8px}.mk-hb .r{display:grid;grid-template-columns:minmax(90px,170px) 1fr auto;gap:8px;align-items:center;font-size:11.5px}
    .mk-hb .t{height:12px;border-radius:6px;background:#f1f5f9;overflow:hidden}.mk-hb .t i{display:block;height:100%;border-radius:0 6px 6px 0;background:${C1}}
    .mk-hb .r span:first-child{font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .mk-sub{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.mk-sub button{border:1px solid #fed7aa;background:#fff;color:#9a3412;font-family:inherit;font-weight:800;font-size:12px;border-radius:12px;padding:7px 12px;cursor:pointer}
    .mk-sub button.on{background:#c2410c;color:#fff;border-color:#c2410c}
    .mk-row{background:#fffaf7;border:1px solid #f5e6dc;border-radius:16px;padding:12px;display:flex;flex-direction:column;gap:8px}
    .mk-tier{display:grid;grid-template-columns:1fr 1fr 90px 1fr 34px;gap:6px;align-items:center}
    .mk-res{white-space:pre-wrap;line-height:2;background:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:12px 14px;font-size:12.5px}
    @media (max-width:767px){.mk-g2,.mk-g3,.mk-g4{grid-template-columns:minmax(0,1fr)}.mk-tblwrap{display:none}.mk-cards{display:flex}.mk-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
        .mk-hub{border-radius:20px}.mk-nav{width:100%;overflow:auto;flex-wrap:nowrap}.mk-nav button{flex-shrink:0}.mk-f>label{max-width:none;flex:1 1 45%}
        .mk-ov{padding:0;align-items:flex-end}.mk-md{border-radius:22px 22px 0 0;max-height:94vh;overflow:auto}.mk-tier{grid-template-columns:1fr 1fr;}.mk-tier .x{grid-column:1/-1}}`;
    const css = () => { if (!document.getElementById('mk-css')) { const s = document.createElement('style'); s.id = 'mk-css'; s.textContent = STYLE; document.head.appendChild(s); } };

    const TABS = [['mk-dash', 'fa-chart-line', 'داشبورد فروش'], ['mk-sales', 'fa-receipt', 'فروش‌ها'], ['mk-people', 'fa-user-tie', 'بازاریابان'], ['mk-settings', 'fa-sliders', 'تنظیمات']];
    const shell = (tab, body) => `<div class="mk space-y-4" data-perm-skip><div class="mk-hub"><div class="t"><span class="ic"><i class="fas fa-bullhorn"></i></span><div><b>بازاریابی و فروش</b><small>عملکردِ بازاریابان، حق بازاریابی، پاداش و حقوق</small></div></div>
        <div class="mk-nav">${TABS.filter(t => can(t[0])).map(t => `<button class="${t[0] === tab ? 'on' : ''}" onclick="switchTab('${t[0]}')"><i class="fas ${t[1]}"></i>${t[2]}</button>`).join('')}</div></div>${body}</div>`;
    async function boot(force) { if (!K.boot || force) K.boot = await api('bootstrap'); return K.boot; }
    async function open(tab) {
        css();
        const root = document.getElementById(tab + '-root');
        if (!root) return;
        K.tab = tab;
        try { await boot(); } catch (e) { root.innerHTML = `<div class="mk-card mk-empty"><i class="fas fa-lock"></i>${esc(e.message)}</div>`; return; }
        if (!can(tab)) { root.innerHTML = shell(tab, '<div class="mk-card mk-empty"><i class="fas fa-lock"></i>به این بخش دسترسی ندارید.</div>'); return; }
        if (tab === 'mk-dash') Dash.render(root);
        else if (tab === 'mk-sales') Sales.render(root);
        else if (tab === 'mk-people') People.render(root);
        else if (tab === 'mk-settings') Sett.render(root);
    }
    function modal(title, body, foot, o = {}) {
        const ov = document.createElement('div');
        ov.className = 'mk mk-ov'; ov.setAttribute('data-perm-skip', '');
        ov.innerHTML = `<div class="mk-md ${o.wide ? 'w' : ''}"><div class="mk-mh"><h3>${title}</h3><button class="mk-x" data-x><i class="fas fa-xmark"></i></button></div><div class="mk-mb">${body}</div>${foot ? `<div class="mk-mf">${foot}</div>` : ''}</div>`;
        const close = () => ov.remove();
        ov.addEventListener('mousedown', e => { ov._d = e.target === ov; });
        ov.addEventListener('click', e => { if (e.target === ov && ov._d) close(); });
        ov.querySelector('[data-x]').onclick = close;
        document.body.appendChild(ov);
        ov.close = close;
        if (window.MoneyInput) MoneyInput.apply(ov);
        return ov;
    }
    const yearOpts = sel => (K.boot.years || [K.boot.jy]).map(y => `<option value="${y}" ${+sel === +y ? 'selected' : ''}>${fa(y)}</option>`).join('');
    const monthOpts = (sel, all = 'کلِ سال') => `<option value="">${all}</option>` + MONTHS.map((m, i) => `<option value="${i + 1}" ${+sel === i + 1 ? 'selected' : ''}>${m}</option>`).join('');
    const personOpts = (sel, all = 'همه') => `<option value="">${all}</option>` + K.boot.people.map(p => `<option value="${p.id}" ${+sel === p.id ? 'selected' : ''}>${esc(p.name)}${p.active ? '' : ' (غیرفعال)'}</option>`).join('');
    const typeOpts = (sel, all = 'همه‌ی انواع') => (all ? `<option value="">${all}</option>` : '') + K.boot.types.map(t => `<option value="${t.id}" ${+sel === t.id ? 'selected' : ''}>${esc((t.icon ? t.icon + ' ' : '') + t.name)}${t.active ? '' : ' (غیرفعال)'}</option>`).join('');
    // نشانِ سطح: تصویرِ بارگذاری‌شده یا دایره‌ی رنگی با حرفِ اول
    const badge = (lv, size = 30) => !lv ? '' : `<span class="mk-badge" title="سطحِ ${esc(lv.name)}" style="width:${size}px;height:${size}px;background:${esc(lv.color || '#64748b')};font-size:${Math.round(size * .42)}px">${lv.image || lv.image_path ? `<img src="${esc(lv.image || lv.image_path)}" alt="">` : esc((lv.name || '?').slice(0, 1))}</span>`;

    function barChart(box, cats, series, fmt, tip) {
        const W = Math.max(300, Math.round(box.clientWidth || 600)), H = 240, Lm = 58, R = 8, T = 12, B = 30, pw = W - Lm - R, ph = H - T - B, n = cats.length, slot = pw / n;
        const xAt = i => W - R - slot * (i + .5);
        const mx0 = Math.max(0, ...series.flatMap(s => s.values));
        const p = Math.pow(10, Math.floor(Math.log10(mx0 || 1))); const max = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].map(m => m * p).find(v => v >= mx0) || 1;
        let g = '';
        for (let i = 0; i <= 4; i++) { const y = T + ph - ph * i / 4; g += `<line x1="${Lm}" x2="${W - R}" y1="${y}" y2="${y}" stroke="${i ? '#f1ece9' : '#d6cfcb'}" ${i ? 'stroke-dasharray="3 4"' : ''}/><text x="${Lm - 6}" y="${y + 3}" text-anchor="end" font-size="9.5" fill="#94a3b8">${fmt(max * i / 4)}</text>`; }
        cats.forEach((c, i) => g += `<text x="${xAt(i)}" y="${H - B + 15}" text-anchor="middle" font-size="9.5" font-weight="700" fill="#64748b">${esc(c)}</text>`);
        const k = series.length, bw = Math.max(3, Math.min(22, (slot * .7 - 2 * (k - 1)) / k));
        let bars = '', hits = '';
        cats.forEach((c, i) => {
            const tot = bw * k + 2 * (k - 1), x0 = xAt(i) + tot / 2;
            series.forEach((s, si) => { const v = s.values[i] || 0, h = ph * Math.min(1, v / max); if (h < .5) return; const x = x0 - (si + 1) * bw - si * 2, y = T + ph - h, r = Math.min(4, bw / 2, h);
                bars += `<path d="M${x},${T + ph}V${y + r}Q${x},${y} ${x + r},${y}H${x + bw - r}Q${x + bw},${y} ${x + bw},${y + r}V${T + ph}Z" fill="${s.color}"/>`; });
            hits += `<rect x="${xAt(i) - slot / 2}" y="${T}" width="${slot}" height="${ph}" fill="transparent" data-i="${i}"/>`;
        });
        box.innerHTML = `${k > 1 ? `<div class="mk-leg">${series.map(s => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('')}</div>` : ''}<div class="mk-chart"><svg viewBox="0 0 ${W} ${H}">${g}${bars}${hits}</svg><div class="mk-tip"></div></div>`;
        const ch = box.querySelector('.mk-chart'), tp = ch.querySelector('.mk-tip');
        ch.addEventListener('mousemove', e => {
            const r = e.target.closest && e.target.closest('rect[data-i]');
            ch.querySelectorAll('rect[data-i]').forEach(x => x.setAttribute('fill', x === r ? 'rgba(194,65,12,.06)' : 'transparent'));
            if (!r) { tp.style.opacity = 0; return; }
            tp.innerHTML = `<b>${esc(cats[+r.dataset.i])}</b><br>${tip(+r.dataset.i)}`;
            const bb = ch.getBoundingClientRect(); let x = e.clientX - bb.left + 14;
            tp.style.opacity = 1; if (x + tp.offsetWidth > bb.width) x = e.clientX - bb.left - tp.offsetWidth - 14;
            tp.style.left = Math.max(0, x) + 'px'; tp.style.top = Math.max(0, e.clientY - bb.top - 10) + 'px';
        });
        ch.addEventListener('mouseleave', () => { tp.style.opacity = 0; });
    }

    // ------------------------------------------------------------------
    //  داشبورد
    // ------------------------------------------------------------------
    const Dash = {
        render(root) {
            const f = K.f; if (!f.jy) { f.jy = K.boot.jy; f.jm = K.boot.jm; }
            const ex = can('mk-sales', 'export') || can('mk-dash', 'export');
            root.innerHTML = shell('mk-dash', `<div class="mk-card"><div class="mk-f">
                <label><span>سال</span><select class="mk-sel" data-k="jy">${yearOpts(f.jy)}</select></label>
                <label><span>ماه</span><select class="mk-sel" data-k="jm">${monthOpts(f.jm)}</select></label>
                <label><span>بازاریاب</span><select class="mk-sel" data-k="person_id">${personOpts(f.person_id, 'همه‌ی افراد')}</select></label>
                ${ex ? `<button class="mk-btn o" data-a="xls"><i class="fas fa-file-excel text-green-600"></i>اکسلِ درآمد</button><button class="mk-btn o" data-a="xls2"><i class="fas fa-file-excel text-green-600"></i>اکسلِ فروش‌ها</button><button class="mk-btn p" data-a="pdf"><i class="fas fa-file-pdf"></i>گزارش PDF</button>` : ''}
                <button class="mk-btn s" data-a="calc"><i class="fas fa-calculator"></i>محاسبه‌گر</button>
            </div></div><div data-body><div class="mk-card mk-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            root.querySelectorAll('[data-k]').forEach(s => s.onchange = () => { f[s.dataset.k] = s.value; this.load(root); });
            const q = a => root.querySelector(`[data-a=${a}]`);
            if (q('xls')) q('xls').onclick = () => download(url('export', {kind: 'summary', jy: f.jy, jm: f.jm, person_id: f.person_id}));
            if (q('xls2')) q('xls2').onclick = () => download(url('export', {kind: 'sales', jy: f.jy, jm: f.jm, person_id: f.person_id}));
            if (q('pdf')) q('pdf').onclick = () => window.open(url('pdf', {jy: f.jy, jm: f.jm, person_id: f.person_id, inline: 1}), '_blank');
            q('calc').onclick = () => Calc.open();
            this.load(root);
        },
        async load(root) {
            const f = K.f, body = root.querySelector('[data-body]');
            let d;
            try { d = await api('dash', {jy: f.jy, jm: f.jm, person_id: f.person_id}); } catch (e) { body.innerHTML = `<div class="mk-card mk-empty">${esc(e.message)}</div>`; return; }
            const T = d.total, per = f.jm ? MONTHS[f.jm - 1] + ' ' + fa(f.jy) : 'سالِ ' + fa(f.jy);
            const tgPct = d.target ? Math.round(T.premium * 100 / d.target) : 0;
            body.innerHTML = `
            <div class="mk-kpis">
                <div class="mk-kpi"><div class="k"><i class="fas fa-receipt ml-1 text-orange-600"></i>تعدادِ فروش · ${per}</div><div class="v">${fa(T.n)}</div><div class="s">${fa(d.active_sellers)} از ${fa(d.people_n)} نفر فروش داشته‌اند</div></div>
                <div class="mk-kpi"><div class="k"><i class="fas fa-sack-dollar ml-1 text-blue-600"></i>جمعِ حق‌بیمه</div><div class="v">${short(T.premium)}</div><div class="s">${money(T.premium)} ریال</div></div>
                <div class="mk-kpi"><div class="k"><i class="fas fa-percent ml-1 text-violet-600"></i>جمعِ کارمزدِ نمایندگی</div><div class="v">${short(T.comm)}</div><div class="s">${T.premium ? fa((T.comm * 100 / T.premium).toFixed(1)) + '٪ از حق‌بیمه' : ''}</div></div>
                <div class="mk-kpi"><div class="k"><i class="fas fa-hand-holding-dollar ml-1 text-green-600"></i>حق بازاریابی</div><div class="v">${short(T.fee)}</div><div class="s">${T.comm ? fa((T.fee * 100 / T.comm).toFixed(1)) + '٪ از کارمزد' : ''}</div></div>
                <div class="mk-kpi"><div class="k"><i class="fas fa-bullseye ml-1 text-rose-600"></i>هدفِ ${f.jm ? 'ماه' : 'سال'}</div><div class="v">${fa(tgPct)}٪</div><div class="s">${short(T.premium)} از ${short(d.target)}</div><div class="mk-bar"><i style="width:${Math.min(100, tgPct)}%"></i></div></div>
            </div>
            <div class="mk-grid mk-g2">
                <div class="mk-card"><h3><i class="fas fa-chart-column text-blue-600"></i>فروشِ ماه به ماهِ ${fa(f.jy)} <small>(ریال)</small></h3><div data-c1></div></div>
                <div class="mk-card"><h3><i class="fas fa-layer-group text-orange-600"></i>فروش بر اساسِ نوعِ بیمه‌نامه <small>${per}</small></h3><div data-types></div></div>
            </div>
            <div class="mk-card"><h3><i class="fas fa-ranking-star text-amber-500"></i>عملکردِ بازاریابان <small>${per} · روی هر نفر بزنید</small></h3><div data-board></div></div>
            <div class="mk-card"><h3><i class="fas fa-clock-rotate-left text-slate-500"></i>آخرین فروش‌ها</h3><div data-recent></div></div>`;
            const draw = () => { const c = body.querySelector('[data-c1]'); if (c) barChart(c, d.months.map(m => m.label), [{name: 'حق‌بیمه', color: C1, values: d.months.map(m => m.premium)}, {name: 'حق بازاریابی', color: C2, values: d.months.map(m => m.fee)}], short,
                i => `<span class="sw" style="background:${C1}"></span>حق‌بیمه: ${money(d.months[i].premium)}<br><span class="sw" style="background:${C2}"></span>حق بازاریابی: ${money(d.months[i].fee)}<br>${fa(d.months[i].n)} فروش`); };
            draw();
            window.removeEventListener('resize', this._rz || (() => {})); this._rz = () => { clearTimeout(this._t); this._t = setTimeout(() => { if (K.tab === 'mk-dash' && body.querySelector('[data-c1]')) draw(); }, 200); }; window.addEventListener('resize', this._rz);
            const max = Math.max(1, ...T.rows.map(r => r.premium));
            body.querySelector('[data-types]').innerHTML = T.rows.length ? `<div class="mk-hb">${T.rows.map(r => `<div class="r" title="${esc(r.type_name)}"><span>${esc(r.type_name)}</span><div class="t"><i style="width:${Math.max(2, r.premium / max * 100)}%"></i></div><b>${fa(r.n)} · ${short(r.premium)}</b></div>`).join('')}</div>` : '<div class="mk-empty">فروشی نیست</div>';
            const b = d.board;
            body.querySelector('[data-board]').innerHTML = b.length ? `<div class="mk-tblwrap mk-scroll"><table class="mk-tbl"><thead><tr><th>#</th><th>نام</th><th>سطح</th><th>تعداد</th><th>حق‌بیمه</th><th>حق بازاریابی</th>${f.jm ? '<th>کلِ درآمد</th><th>حقوقِ ثابت</th>' : ''}<th>هدف</th><th>ربات</th></tr></thead><tbody>
                ${b.map((p, i) => `<tr data-pid="${p.id}" style="cursor:pointer"><td><span class="mk-rank">${fa(i + 1)}</span></td><td><b>${esc(p.full_name)}</b><small class="block text-slate-500">${esc(p.kind_fa)}${p.active ? '' : ' · غیرفعال'}</small></td>
                    <td>${p.month.level ? `<span class="mk-person">${badge(p.month.level, 26)}<span>${esc(p.month.level.name)}</span></span>` : '—'}</td><td>${fa(p.month.n)}</td><td>${money(p.month.premium)}</td><td class="text-green-700 font-bold">${money(p.month.fee)}</td>
                    ${f.jm ? `<td class="font-black">${money(p.month.total)}</td><td>${p.month.base_ok ? '<span class="mk-tag g">رسید</span>' : '<span class="mk-tag a">نرسید</span>'}</td>` : ''}
                    <td style="min-width:120px">${fa(p.month.target_pct)}٪<div class="mk-bar"><i style="width:${Math.min(100, p.month.target_pct)}%"></i></div></td><td>${p.linked ? '<span class="mk-tag g">وصل</span>' : '<span class="mk-tag">—</span>'}</td></tr>`).join('')}</tbody></table></div>
                <div class="mk-cards">${b.map((p, i) => `<div class="mk-pc" data-pid="${p.id}"><div class="mk-person"><span class="mk-rank">${fa(i + 1)}</span>${badge(p.month.level, 34)}<div class="flex-1 min-w-0"><b class="block truncate">${esc(p.full_name)}</b><small class="text-slate-500">${esc(p.kind_fa)}${p.month.level ? ' · ' + esc(p.month.level.name) : ''}</small></div><b class="text-green-700">${short(p.month.fee)}</b></div>
                    <div class="flex flex-wrap gap-1 mt-2"><span class="mk-tag">${fa(p.month.n)} فروش</span><span class="mk-tag b">${short(p.month.premium)}</span>${f.jm ? `<span class="mk-tag v">درآمد ${short(p.month.total)}</span>` : ''}<span class="mk-tag">${fa(p.month.target_pct)}٪ هدف</span></div><div class="mk-bar"><i style="width:${Math.min(100, p.month.target_pct)}%"></i></div></div>`).join('')}</div>`
                : `<div class="mk-empty"><i class="fas fa-user-plus"></i>هنوز بازاریابی تعریف نشده است.${can('mk-people', 'create') ? '<div class="mt-2"><button class="mk-btn p" onclick="switchTab(\'mk-people\')">تعریفِ بازاریاب</button></div>' : ''}</div>`;
            body.querySelectorAll('[data-pid]').forEach(x => x.onclick = () => Income.open(+x.dataset.pid, f.jy, f.jm));
            body.querySelector('[data-recent]').innerHTML = d.recent.length ? `<div class="flex flex-col gap-1">${d.recent.map(r => `<div class="flex flex-wrap items-center gap-2 py-2 border-t border-slate-100 first:border-0 text-[12px]"><span class="mk-tag">${fa(r.sale_j)}</span><b>${esc(r.full_name)}</b><span>${esc(r.type_name)}</span><span class="mk-tag b">${money(r.premium)}</span><span class="mk-tag g">حق بازاریابی ${money(r.fee_amount)}</span>${r.customer_name ? `<span class="text-slate-500">${esc(r.customer_name)}</span>` : ''}<span class="mk-tag ${r.source === 'BOT' ? 'v' : ''} mr-auto">${r.source === 'BOT' ? 'ربات' : 'پنل'}</span></div>`).join('')}</div>` : '<div class="mk-empty">فروشی نیست</div>';
        },
    };

    // درآمدِ یک نفر (ماه به ماه، با جزئیات)
    const Income = {
        async open(pid, jy, jm) {
            const m = modal('درآمد و عملکرد', '<div class="mk-empty"><i class="fas fa-spinner fa-spin"></i></div>', '', {wide: true});
            const draw = async (y, mm) => {
                let d;
                try { d = await api('income', {person_id: pid, jy: y, jm: mm || ''}); } catch (e) { m.querySelector('.mk-mb').innerHTML = `<div class="mk-empty">${esc(e.message)}</div>`; return; }
                const p = d.person, ex = can('mk-sales', 'export') || can('mk-dash', 'export');
                m.querySelector('.mk-mh h3').innerHTML = `<span class="mk-person">${badge(p.month.level, 34)}<span>${esc(p.full_name)} <small class="text-slate-500 font-bold">${esc(p.kind_fa)} · ${p.linked ? 'به ربات وصل است' : 'به ربات وصل نیست'}</small></span></span>`;
                const card = inc => `<div class="mk-row"><div class="flex flex-wrap items-center gap-2"><b class="text-[13px]">${esc(inc.label)}</b>${inc.rules.level ? `<span class="mk-person">${badge({name: inc.rules.level.name, color: inc.rules.level.color, image_path: inc.rules.level.image_path}, 22)}<span class="mk-tag">${esc(inc.rules.level.name)}</span></span>` : ''}
                        <span class="mk-tag ${inc.base_ok ? 'g' : 'a'}">${inc.base_ok ? 'به حقوقِ ثابت رسید' : 'به کف نرسید'}</span><span class="mk-tag">${fa(inc.target_pct)}٪ هدف</span><b class="mr-auto text-[14px]">${money(inc.total)} <small class="text-slate-500">ریال</small></b></div>
                    <div class="mk-scroll" style="max-height:none"><table class="mk-tbl no-count"><thead><tr><th>نوع</th><th>تعداد</th><th>حق‌بیمه</th><th>کارمزد</th><th>حق بازاریابی</th></tr></thead><tbody>${inc.data.rows.map(r => `<tr><td>${esc(r.type_name)}</td><td>${fa(r.n)}</td><td>${money(r.premium)}</td><td>${money(r.comm)}</td><td>${money(r.fee)}</td></tr>`).join('') || '<tr><td colspan="5" class="text-center text-slate-400">فروشی نیست</td></tr>'}
                        <tr style="font-weight:900;background:#fff7ed"><td>جمع</td><td>${fa(inc.data.n)}</td><td>${money(inc.data.premium)}</td><td>${money(inc.data.comm)}</td><td>${money(inc.data.fee)}</td></tr></tbody></table></div>
                    <div class="flex flex-wrap gap-1 text-[11px]"><span class="mk-tag">حقوقِ ثابت ${money(inc.base)}</span><span class="mk-tag g">حق بازاریابی ${money(inc.data.fee)}</span><span class="mk-tag b">بالای کف ${money(inc.over)}</span><span class="mk-tag v">حجمِ فروش ${money(inc.volume)}</span><span class="mk-tag a">پاداشِ سطح ${money(inc.level_bonus)}</span></div>
                    <div class="text-[11.5px] text-slate-600 leading-7">${inc.messages.map(esc).join('<br>')}</div></div>`;
                m.querySelector('.mk-mb').innerHTML = `<div class="mk-f mb-3"><label><span>سال (سال‌های قبل = بایگانی)</span><select class="mk-sel" data-y>${yearOpts(y)}</select></label><label><span>ماه</span><select class="mk-sel" data-m>${monthOpts(mm, 'همه‌ی ماه‌های دارای فروش')}</select></label>
                    ${ex ? `<button class="mk-btn p" data-pdf><i class="fas fa-file-pdf"></i>PDF</button><button class="mk-btn o" data-x1><i class="fas fa-file-excel text-green-600"></i>اکسلِ درآمد</button><button class="mk-btn o" data-x2><i class="fas fa-file-excel text-green-600"></i>اکسلِ فروش‌ها</button>` : ''}</div>
                    <div class="flex flex-col gap-3">${d.months.length ? d.months.map(card).join('') : '<div class="mk-empty">در این سال فروشی نیست.</div>'}</div>`;
                m.querySelector('[data-y]').onchange = e => draw(+e.target.value, m.querySelector('[data-m]').value);
                m.querySelector('[data-m]').onchange = e => draw(+m.querySelector('[data-y]').value, e.target.value);
                const o = {person_id: pid, jy: y, jm: mm || ''};
                if (m.querySelector('[data-pdf]')) { m.querySelector('[data-pdf]').onclick = () => window.open(url('pdf', Object.assign({inline: 1}, o)), '_blank'); m.querySelector('[data-x1]').onclick = () => download(url('export', Object.assign({kind: 'summary'}, o))); m.querySelector('[data-x2]').onclick = () => download(url('export', Object.assign({kind: 'sales'}, o))); }
            };
            draw(jy || K.boot.jy, jm || '');
        },
    };

    // محاسبه‌گر: حق بازاریابی + پاداش/تخفیفِ مشتری
    const Calc = {
        open(typeId, premium) {
            const m = modal('محاسبه‌گرِ حق بازاریابی و پاداشِ مشتری', `<div class="mk-grid mk-g2">
                <label><span class="mk-lbl">نوعِ بیمه‌نامه</span><select class="mk-sel" data-f="type_id">${typeOpts(typeId, '')}</select></label>
                <label><span class="mk-lbl">حق‌بیمه (ریال)</span>${mi('premium', premium, 'مثلاً ۱۲,۵۰۰,۰۰۰')}</label>
                <label><span class="mk-lbl">پرداخت</span><select class="mk-sel" data-f="pay">${Object.entries(K.boot.pay).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
                <label><span class="mk-lbl">مشتری</span><select class="mk-sel" data-f="customer">${Object.entries(K.boot.cust).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
                <label><span class="mk-lbl">تعدادِ بیمه‌نامه / نفر</span><input class="mk-in" data-f="count" inputmode="numeric" value="۱"></label></div>
                <div data-res class="mt-3"></div>`, '<button class="mk-btn s" data-close>بستن</button><button class="mk-btn p" data-go><i class="fas fa-calculator"></i>محاسبه</button>');
            m.querySelector('[data-close]').onclick = () => m.close();
            const go = async () => {
                const v = k => m.querySelector(`[data-f=${k}]`).value;
                if (!num(v('premium'))) return;
                try {
                    const r = await api('reward_calc', {type_id: v('type_id'), premium: num(v('premium')), pay: v('pay'), customer: v('customer'), count: num(v('count')) || 1});
                    m.querySelector('[data-res]').innerHTML = `<div class="mk-res">${esc(r.text)}</div><div class="flex flex-wrap gap-2 mt-2"><span class="mk-tag v">کارمزد: ${money(r.fee.commission)}</span><span class="mk-tag g">حق بازاریاب: ${money(r.fee.fee)} (${fa(r.fee.share)}٪ کارمزد)</span>${r.fee.warn ? `<span class="mk-tag r">${esc(r.fee.warn)}</span>` : ''}</div>`;
                } catch (e) { toast(e.message, 'error'); }
            };
            m.querySelector('[data-go]').onclick = go;
            m.querySelectorAll('[data-f]').forEach(x => x.addEventListener('change', go));
        },
    };

    // ------------------------------------------------------------------
    //  فروش‌ها
    // ------------------------------------------------------------------
    const Sales = {
        render(root) {
            const f = K.sales.f; if (!f.jy) { f.jy = K.boot.jy; f.jm = K.boot.jm; }
            root.innerHTML = shell('mk-sales', `<div class="mk-card space-y-3"><div class="mk-f">
                <label><span>سال</span><select class="mk-sel" data-k="jy">${yearOpts(f.jy)}</select></label><label><span>ماه</span><select class="mk-sel" data-k="jm">${monthOpts(f.jm)}</select></label>
                <label><span>بازاریاب</span><select class="mk-sel" data-k="person_id">${personOpts(f.person_id)}</select></label><label><span>نوع</span><select class="mk-sel" data-k="type_id">${typeOpts(f.type_id)}</select></label>
                <label><span>وضعیت</span><select class="mk-sel" data-k="status"><option value="OK" ${f.status === 'OK' ? 'selected' : ''}>معتبر</option><option value="VOID" ${f.status === 'VOID' ? 'selected' : ''}>باطل‌شده</option><option value="" ${!f.status ? 'selected' : ''}>همه</option></select></label>
                <label style="max-width:none;flex:2 1 200px"><span>جستجو</span><input class="mk-in" data-k="q" placeholder="مشتری، موبایل، شماره بیمه‌نامه، بازاریاب" value="${esc(f.q || '')}"></label></div>
                <div class="flex flex-wrap gap-2">${can('mk-sales', 'create') ? '<button class="mk-btn p" data-a="add"><i class="fas fa-plus"></i>ثبتِ فروش</button>' : ''}<button class="mk-btn s" data-a="calc"><i class="fas fa-calculator"></i>محاسبه‌گر</button>
                    ${can('mk-sales', 'export') ? '<button class="mk-btn o" data-a="xls"><i class="fas fa-file-excel text-green-600"></i>اکسل</button>' : ''}<span class="mr-auto flex flex-wrap gap-1" data-sum></span></div></div>
                <div class="mk-card" style="padding:10px" data-list><div class="mk-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            let t;
            root.querySelectorAll('[data-k]').forEach(el => el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => { f[el.dataset.k] = el.value; clearTimeout(t); t = setTimeout(() => this.load(root), el.tagName === 'INPUT' ? 300 : 0); }));
            const q = a => root.querySelector(`[data-a=${a}]`);
            if (q('add')) q('add').onclick = () => this.form(null, () => this.load(root));
            q('calc').onclick = () => Calc.open();
            if (q('xls')) q('xls').onclick = () => download(url('export', {kind: 'sales', jy: f.jy, jm: f.jm, person_id: f.person_id, type_id: f.type_id}));
            this.load(root);
        },
        async load(root) {
            const f = K.sales.f, box = root.querySelector('[data-list]');
            let d;
            try { d = await api('sales_list', f); } catch (e) { box.innerHTML = `<div class="mk-empty">${esc(e.message)}</div>`; return; }
            root.querySelector('[data-sum]').innerHTML = `<span class="mk-tag">${fa(d.sum.n)} فروش</span><span class="mk-tag b">حق‌بیمه ${short(d.sum.premium)}</span><span class="mk-tag v">کارمزد ${short(d.sum.comm)}</span><span class="mk-tag g">حق بازاریابی ${short(d.sum.fee)}</span>`;
            if (!d.rows.length) { box.innerHTML = '<div class="mk-empty"><i class="fas fa-receipt"></i>فروشی با این فیلترها نیست.</div>'; return; }
            const del = r => can('mk-sales', 'delete') ? `<button class="mk-btn r sm" data-del="${r.id}" title="حذفِ کامل"><i class="fas fa-trash"></i></button>` : '';
            const act = r => `<button class="mk-btn s sm" data-dt="${r.id}" title="جزئیات"><i class="fas fa-circle-info"></i></button>` + (r.status !== 'OK' ? `<span class="mk-tag r" title="${esc(r.void_reason || '')}">باطل${r.void_by_name ? ' · ' + esc(r.void_by_name) : ''}</span>${del(r)}` : `${can('mk-sales', 'edit') ? `<button class="mk-btn s sm" data-ed="${r.id}"><i class="fas fa-pen"></i></button>` : ''}${can('mk-sales', 'delete') ? `<button class="mk-btn a sm" data-vd="${r.id}" title="ابطال"><i class="fas fa-ban"></i></button>` : ''}${del(r)}`);
            box.innerHTML = `<div class="mk-tblwrap mk-scroll"><table class="mk-tbl"><thead><tr><th>تاریخ</th><th>بازاریاب</th><th>نوع</th><th>حق‌بیمه</th><th>کارمزد</th><th>سهم</th><th>حق بازاریابی</th><th>مشتری</th><th>ثبت</th><th></th></tr></thead><tbody>
                ${d.rows.map(r => `<tr class="${r.status !== 'OK' ? 'void' : ''}"><td>${fa(r.sale_j)}</td><td><b>${esc(r.full_name)}</b></td><td>${esc(r.type_name)}</td><td>${money(r.premium)}</td><td>${money(r.commission_amount)}<small class="block text-slate-400">${fa(+r.commission_pct)}٪</small></td>
                    <td>${fa(+r.share_pct)}٪</td><td class="text-green-700 font-bold">${money(r.fee_amount)}</td><td>${esc(r.customer_name || '—')}${r.policy_no ? `<small class="block text-slate-400" dir="ltr">${esc(fa(r.policy_no))}</small>` : ''}</td>
                    <td><span class="mk-tag ${r.source === 'BOT' ? 'v' : ''}">${r.source === 'BOT' ? 'ربات' : 'پنل'}</span>${r.by_name ? `<small class="block text-slate-400">${esc(r.by_name)}</small>` : ''}</td><td style="white-space:nowrap">${act(r)}</td></tr>`).join('')}</tbody></table></div>
                <div class="mk-cards">${d.rows.map(r => `<div class="mk-pc" style="${r.status !== 'OK' ? 'opacity:.55' : ''}"><div class="flex items-center gap-2"><b class="flex-1">${esc(r.full_name)}</b><span class="mk-tag">${fa(r.sale_j)}</span></div>
                    <div class="text-[12px] mt-1">${esc(r.type_name)}${r.customer_name ? ' · ' + esc(r.customer_name) : ''}</div><div class="flex flex-wrap gap-1 mt-2 items-center"><span class="mk-tag b">${money(r.premium)}</span><span class="mk-tag g">${money(r.fee_amount)}</span><span class="mk-tag ${r.source === 'BOT' ? 'v' : ''}">${r.source === 'BOT' ? 'ربات' : 'پنل'}</span><span class="mr-auto flex gap-1">${act(r)}</span></div></div>`).join('')}</div>`;
            box.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => this.form(d.rows.find(r => +r.id === +b.dataset.ed), () => this.load(root)));
            box.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
                const r = d.rows.find(x => +x.id === +b.dataset.del);
                if (!(await confirmUi('حذفِ کاملِ فروش', `فروشِ ${r.full_name} (${r.type_name} · ${money(r.premium)} ریال · ${fa(r.sale_j)}) کاملاً حذف شود؟ از گزارش‌ها و درآمدِ او هم حذف می‌شود و برگشت ندارد.`, {danger: true, ok: 'حذف'}))) return;
                try { await api('sale_delete', {id: r.id}); toast('فروش حذف شد.', 'success'); this.load(root); } catch (e) { toast(e.message, 'error'); }
            });
            box.querySelectorAll('[data-dt]').forEach(b => b.onclick = () => this.detail(d.rows.find(x => +x.id === +b.dataset.dt)));
            box.querySelectorAll('[data-vd]').forEach(b => b.onclick = async () => {
                const reason = window.uiPrompt ? await uiPrompt('ابطالِ فروش', '', {hint: 'دلیلِ ابطال:', ok: 'ابطال', danger: true}) : '';
                if (reason === null) return;
                try { await api('sale_void', {id: +b.dataset.vd, reason}); toast('فروش باطل شد.', 'success'); this.load(root); } catch (e) { toast(e.message, 'error'); }
            });
        },
        detail(r) {
            const t = (k, v) => `<div class="mk-row" style="padding:8px 10px"><small class="text-slate-500 font-bold">${k}</small><b>${v || '—'}</b></div>`;
            modal('جزئیاتِ فروش #' + fa(r.id), `<div class="mk-grid mk-g3">${t('بازاریاب', esc(r.full_name))}${t('نوعِ بیمه‌نامه', esc(r.type_name))}${t('تاریخِ فروش', fa(r.sale_j))}
                ${t('حق‌بیمه', money(r.premium) + ' ریال')}${t('کارمزدِ نمایندگی', money(r.commission_amount) + ' (' + fa(+r.commission_pct) + '٪)')}${t('سهمِ بازاریاب از کارمزد', fa(+r.share_pct) + '٪')}
                ${t('حق بازاریابی', money(r.fee_amount) + ' ریال')}${t('مشتری', esc(r.customer_name))}${t('موبایلِ مشتری', `<span dir="ltr">${esc(fa(r.customer_mobile || ''))}</span>`)}
                ${t('شماره‌ی بیمه‌نامه', `<span dir="ltr">${esc(fa(r.policy_no || ''))}</span>`)}${t('ثبت از', r.source === 'BOT' ? 'ربات بله (خودِ بازاریاب)' : 'پنل')}${t('ثبت‌کننده‌ی پنل', esc(r.by_name))}
                ${t('زمانِ ثبت', fa(r.created_at_j || ''))}${t('وضعیت', r.status === 'OK' ? 'معتبر' : 'باطل' + (r.void_reason ? ': ' + esc(r.void_reason) : '') + (r.void_by_name ? ' · ' + esc(r.void_by_name) : ''))}${t('توضیح', esc(r.note))}</div>`, '');
        },
        form(r, done) {
            const m = modal(r ? 'ویرایشِ فروش' : 'ثبتِ فروش', `<div class="mk-grid mk-g2">
                <label><span class="mk-lbl">بازاریاب</span><select class="mk-sel" data-f="person_id">${personOpts(r ? r.person_id : K.sales.f.person_id, '— انتخاب —')}</select></label>
                <label><span class="mk-lbl">نوعِ بیمه‌نامه</span><select class="mk-sel" data-f="type_id">${typeOpts(r ? r.type_id : '', '— انتخاب —')}</select></label>
                <label><span class="mk-lbl">حق‌بیمه (ریال)</span>${mi('premium', r ? r.premium : '')}</label>
                <label><span class="mk-lbl">تاریخِ فروش</span><input class="mk-in" data-f="sale_j" data-jdate dir="ltr" value="${fa(r ? r.sale_j : K.boot.today_j)}"></label>
                <label><span class="mk-lbl">نامِ مشتری</span><input class="mk-in" data-f="customer_name" value="${esc(r ? r.customer_name || '' : '')}"></label>
                <label><span class="mk-lbl">موبایلِ مشتری</span><input class="mk-in" data-f="customer_mobile" inputmode="tel" value="${esc(fa(r ? r.customer_mobile || '' : ''))}"></label>
                <label><span class="mk-lbl">شماره‌ی بیمه‌نامه</span><input class="mk-in" data-f="policy_no" dir="ltr" value="${esc(r ? r.policy_no || '' : '')}"></label>
                <label><span class="mk-lbl">توضیح</span><input class="mk-in" data-f="note" value="${esc(r ? r.note || '' : '')}"></label></div>
                <div data-fee class="mt-3"></div>${r ? '' : '<label class="flex items-center gap-2 mt-3 text-[12px] font-bold"><input type="checkbox" data-f="notify" checked>به بازاریاب در ربات بله اطلاع داده شود</label>'}`,
                '<button class="mk-btn s" data-close>انصراف</button><button class="mk-btn g" data-ok><i class="fas fa-check"></i>ذخیره</button>');
            const v = k => { const e = m.querySelector(`[data-f=${k}]`); return e ? (e.type === 'checkbox' ? (e.checked ? 1 : 0) : e.value) : ''; };
            const prev = async () => {
                if (!v('type_id') || !num(v('premium'))) { m.querySelector('[data-fee]').innerHTML = ''; return; }
                try { const x = await api('calc', {type_id: v('type_id'), premium: num(v('premium'))}); m.querySelector('[data-fee]').innerHTML = `<div class="flex flex-wrap gap-1"><span class="mk-tag v">کارمزد ${money(x.fee.commission)}</span><span class="mk-tag g">حق بازاریابی ${money(x.fee.fee)} (${fa(x.fee.share)}٪ از کارمزد)</span>${x.fee.warn ? `<span class="mk-tag r">${esc(x.fee.warn)}</span>` : ''}</div>`; } catch (e) {}
            };
            m.querySelectorAll('[data-f=type_id],[data-f=premium]').forEach(x => x.addEventListener('input', prev));
            m.querySelector('[data-f=type_id]').addEventListener('change', prev);
            prev();
            m.querySelector('[data-close]').onclick = () => m.close();
            m.querySelector('[data-ok]').onclick = async e => {
                busy(e.currentTarget, true);
                try {
                    await api('sale_save', {id: r ? r.id : 0, person_id: v('person_id'), type_id: v('type_id'), premium: num(v('premium')), sale_j: en(v('sale_j')), customer_name: v('customer_name'),
                        customer_mobile: en(v('customer_mobile')), policy_no: en(v('policy_no')), note: v('note'), notify: v('notify')});
                    m.close(); toast('ذخیره شد.', 'success'); done && done();
                } catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
    };

    // ------------------------------------------------------------------
    //  بازاریابان
    // ------------------------------------------------------------------
    const People = {
        async render(root) {
            root.innerHTML = shell('mk-people', `<div class="mk-card"><div class="flex flex-wrap gap-2 items-center">${can('mk-people', 'create') ? '<button class="mk-btn p" data-add><i class="fas fa-user-plus"></i>بازاریاب / کارشناسِ تماسِ جدید</button>' : ''}
                <span class="text-[11.5px] text-slate-500">ورود به ربات بله‌ی شرکت‌ها با همین موبایل؛ منوی «ثبت فروش، محاسبه نرخ پاداش، درآمد این ماه، گزارشات» برایشان باز می‌شود.</span></div></div>
                <div data-list class="mk-grid" style="grid-template-columns:repeat(auto-fill,minmax(290px,1fr))"><div class="mk-card mk-empty"><i class="fas fa-spinner fa-spin"></i></div></div>`);
            const a = root.querySelector('[data-add]'); if (a) a.onclick = () => this.form(null, root);
            this.load(root);
        },
        async load(root) {
            const box = root.querySelector('[data-list]');
            let d;
            try { d = await api('people_list', {jy: K.boot.jy, jm: K.boot.jm}); } catch (e) { box.innerHTML = `<div class="mk-card mk-empty">${esc(e.message)}</div>`; return; }
            this.rows = d.people;
            box.innerHTML = d.people.length ? d.people.map(p => `<div class="mk-card" style="padding:14px;${p.active ? '' : 'opacity:.6'}"><div class="mk-person">${badge(p.month.level, 42) || '<span class="mk-badge" style="width:42px;height:42px;background:#cbd5e1">?</span>'}
                <div class="flex-1 min-w-0"><b class="block truncate text-[14px]">${esc(p.full_name)}</b><small class="text-slate-500"><span dir="ltr">${fa(p.mobile)}</span> · ${esc(p.kind_fa)}</small></div>${p.linked ? '<span class="mk-tag g" title="وصل به ربات"><i class="fas fa-robot"></i></span>' : '<span class="mk-tag" title="هنوز وارد ربات نشده"><i class="fas fa-robot"></i></span>'}</div>
                <div class="flex flex-wrap gap-1 mt-3"><span class="mk-tag">${fa(p.month.n)} فروشِ این ماه</span><span class="mk-tag b">${short(p.month.premium)}</span><span class="mk-tag g">درآمد ${short(p.month.total)}</span>${p.month.level ? `<span class="mk-tag">${esc(p.month.level.name)}</span>` : ''}
                    ${p.level_id ? '<span class="mk-tag v">سطحِ ثابت</span>' : ''}${(p.base_salary !== null || p.base_floor !== null || p.target !== null || p.over_pct !== null || p.volume.length) ? '<span class="mk-tag a">قوانینِ اختصاصی</span>' : ''}${p.active ? '' : '<span class="mk-tag r">غیرفعال</span>'}</div>
                <div class="mk-bar"><i style="width:${Math.min(100, p.month.target_pct)}%"></i></div><small class="text-slate-500">${fa(p.month.target_pct)}٪ از هدفِ ${short(p.month.target)}</small>
                <div class="flex flex-wrap gap-1 mt-3"><button class="mk-btn s sm" data-inc="${p.id}"><i class="fas fa-chart-line"></i>درآمد و گزارش</button>${can('mk-people', 'edit') ? `<button class="mk-btn s sm" data-ed="${p.id}"><i class="fas fa-pen"></i>ویرایش</button>` : ''}
                    ${can('mk-people', 'edit') && p.linked ? `<button class="mk-btn s sm" data-ul="${p.id}"><i class="fas fa-link-slash"></i>قطعِ ربات</button>` : ''}${can('mk-people', 'delete') ? `<button class="mk-btn r sm" data-del="${p.id}"><i class="fas fa-trash"></i></button>` : ''}</div></div>`).join('')
                : '<div class="mk-card mk-empty"><i class="fas fa-user-tie"></i>هنوز کسی تعریف نشده است.</div>';
            box.querySelectorAll('[data-inc]').forEach(b => b.onclick = () => Income.open(+b.dataset.inc));
            box.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => this.form(this.rows.find(p => p.id === +b.dataset.ed), root));
            box.querySelectorAll('[data-ul]').forEach(b => b.onclick = async () => { try { await api('person_unlink', {id: +b.dataset.ul}); this.load(root); } catch (e) { toast(e.message, 'error'); } });
            box.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (!(await confirmUi('حذف', 'این شخص حذف شود؟ فروش‌های ثبت‌شده‌اش می‌مانند.', {danger: true, ok: 'حذف'}))) return; try { await api('person_delete', {id: +b.dataset.del}); await boot(true); this.load(root); } catch (e) { toast(e.message, 'error'); } });
        },
        form(p, root) {
            p = p || {kind: 'MARKETER', active: 1, volume: []};
            const m = modal(p.id ? 'ویرایشِ ' + esc(p.full_name) : 'بازاریاب / کارشناسِ تماسِ جدید', `<div class="mk-grid mk-g2">
                <label><span class="mk-lbl">نام و نام خانوادگی</span><input class="mk-in" data-f="full_name" value="${esc(p.full_name || '')}"></label>
                <label><span class="mk-lbl">موبایل (ورود به ربات)</span><input class="mk-in" data-f="mobile" inputmode="tel" dir="ltr" value="${esc(fa(p.mobile || ''))}"></label>
                <label><span class="mk-lbl">نقش</span><select class="mk-sel" data-f="kind">${Object.entries(K.boot.kinds).map(([k, v]) => `<option value="${k}" ${p.kind === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
                <label><span class="mk-lbl">کد ملی</span><input class="mk-in" data-f="national_id" inputmode="numeric" value="${esc(fa(p.national_id || ''))}"></label></div>
                <div class="mk-row mt-3"><b class="text-[12.5px]"><i class="fas fa-user-gear ml-1"></i>قوانینِ اختصاصی <small class="text-slate-500 font-normal">(خالی = طبقِ سطح و تنظیماتِ کلی)</small></b>
                <div class="mk-grid mk-g2"><label><span class="mk-lbl">سطحِ ثابت</span><select class="mk-sel" data-f="level_id"><option value="">خودکار (بر اساسِ فروشِ ماه)</option>${K.boot.levels.map(l => `<option value="${l.id}" ${+p.level_id === l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select></label>
                    <label><span class="mk-lbl">حقوقِ ثابت (ریال)</span>${mi('base_salary', p.base_salary)}</label><label><span class="mk-lbl">کفِ فروش برای حقوقِ ثابت</span>${mi('base_floor', p.base_floor)}</label>
                    <label><span class="mk-lbl">هدفِ ماهانه</span>${mi('target', p.target)}</label><label><span class="mk-lbl">٪ِ فروشِ بالای کف به حقوق</span><input class="mk-in" data-f="over_pct" inputmode="decimal" value="${p.over_pct === null || p.over_pct === undefined ? '' : fa(p.over_pct)}"></label></div>
                <span class="mk-lbl">پاداشِ حجمِ فروشِ اختصاصی (روی حق بازاریابیِ ماه)</span><div data-vol></div><button class="mk-btn s sm" data-addvol style="align-self:flex-start"><i class="fas fa-plus"></i>پله</button></div>
                <label class="block mt-3"><span class="mk-lbl">یادداشت</span><textarea class="mk-ta" rows="2" data-f="note">${esc(p.note || '')}</textarea></label>
                <label class="flex items-center gap-2 mt-2 text-[12px] font-bold"><input type="checkbox" data-f="active" ${p.active ? 'checked' : ''}>فعال</label>`,
                '<button class="mk-btn s" data-close>انصراف</button><button class="mk-btn p" data-ok><i class="fas fa-floppy-disk"></i>ذخیره</button>', {wide: true});
            const vol = m.querySelector('[data-vol]');
            Vol.mount(vol, p.volume || []);
            m.querySelector('[data-addvol]').onclick = () => Vol.add(vol);
            m.querySelector('[data-close]').onclick = () => m.close();
            m.querySelector('[data-ok]').onclick = async e => {
                const v = k => { const x = m.querySelector(`[data-f=${k}]`); return x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; };
                busy(e.currentTarget, true);
                try {
                    await api('person_save', {id: p.id || 0, full_name: v('full_name'), mobile: en(v('mobile')), kind: v('kind'), national_id: en(v('national_id')), level_id: v('level_id'),
                        base_salary: en(v('base_salary')), base_floor: en(v('base_floor')), target: en(v('target')), over_pct: en(v('over_pct')), volume: Vol.read(vol), note: v('note'), active: v('active')});
                    m.close(); toast('ذخیره شد.', 'success'); await boot(true); this.load(root);
                } catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
    };
    // ویرایشگرِ پله‌های پاداشِ حجم (از، تا، درصد، مبلغ)
    const Vol = {
        row: t => `<div class="mk-tier" data-vr><input class="mk-in money-input" data-v="from" placeholder="از فروشِ" value="${mv(t.from)}"><input class="mk-in money-input" data-v="to" placeholder="تا (خالی = بی‌سقف)" value="${mv(t.to)}">
            <input class="mk-in" data-v="pct" placeholder="٪" value="${t.pct ? fa(t.pct) : ''}"><input class="mk-in money-input" data-v="amount" placeholder="+ مبلغ ثابت" value="${mv(t.amount)}"><button class="mk-btn r sm x" data-rm><i class="fas fa-xmark"></i></button></div>`,
        mount(box, rows) { box.innerHTML = `<div class="flex flex-col gap-2">${rows.map(this.row).join('')}</div>`; this.bind(box); },
        add(box) { box.firstElementChild.insertAdjacentHTML('beforeend', this.row({})); this.bind(box); },
        bind(box) { box.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => b.closest('[data-vr]').remove()); if (window.MoneyInput) MoneyInput.apply(box); },
        read(box) { return [...box.querySelectorAll('[data-vr]')].map(r => ({from: num(r.querySelector('[data-v=from]').value), to: r.querySelector('[data-v=to]').value.trim() === '' ? '' : num(r.querySelector('[data-v=to]').value), pct: en(r.querySelector('[data-v=pct]').value), amount: num(r.querySelector('[data-v=amount]').value)})); },
    };

    // ------------------------------------------------------------------
    //  تنظیمات
    // ------------------------------------------------------------------
    const Sett = {
        async render(root) {
            const ed = can('mk-settings', 'edit');
            let d;
            try { d = await api('settings_get'); } catch (e) { root.innerHTML = shell('mk-settings', `<div class="mk-card mk-empty">${esc(e.message)}</div>`); return; }
            this.d = d;
            const subs = [['salary', 'حقوق، کف و هدف'], ['types', 'انواعِ بیمه‌نامه و کارمزد'], ['levels', 'سطح‌بندی'], ['rewards', 'پاداش و تخفیفِ مشتری']];
            root.innerHTML = shell('mk-settings', `<div class="mk-card"><div class="mk-sub">${subs.map(([k, t]) => `<button data-sub="${k}" class="${K.set.sub === k ? 'on' : ''}">${t}</button>`).join('')}</div><div data-body></div>
                ${ed ? '' : '<p class="text-[11px] text-amber-700 mt-2">فقط مشاهده؛ اجازه‌ی ویرایشِ تنظیمات ندارید.</p>'}</div>`);
            root.querySelectorAll('[data-sub]').forEach(b => b.onclick = () => { K.set.sub = b.dataset.sub; this.render(root); });
            const body = root.querySelector('[data-body]');
            this[K.set.sub](body, ed, root);
            if (window.MoneyInput) MoneyInput.apply(body);
        },
        salary(body, ed, root) {
            const s = this.d.settings;
            body.innerHTML = `<div class="mk-grid mk-g3">
                <label><span class="mk-lbl">حقوقِ ثابتِ ماهانه (ریال)</span>${mi('base_salary', s.base_salary)}</label>
                <label><span class="mk-lbl">کفِ فروشِ ماه برای حقوقِ ثابت</span>${mi('base_floor', s.base_floor)}</label>
                <label><span class="mk-lbl">هدفِ ماهانه</span>${mi('monthly_target', s.monthly_target)}</label>
                <label><span class="mk-lbl">درصدِ فروشِ بالای کف که به حقوق اضافه می‌شود</span><input class="mk-in" data-f="over_pct" value="${fa(s.over_pct)}"></label>
                <label><span class="mk-lbl">تعیینِ سطح</span><select class="mk-sel" data-f="level_mode"><option value="auto" ${s.level_mode === 'auto' ? 'selected' : ''}>خودکار بر اساسِ فروشِ همان ماه</option><option value="manual" ${s.level_mode === 'manual' ? 'selected' : ''}>فقط سطحِ تعیین‌شده برای هر نفر</option></select></label>
                <label><span class="mk-lbl">عنوانِ گزارش‌های PDF</span><input class="mk-in" data-f="report_title" value="${esc(s.report_title)}"></label></div>
                <label class="flex items-center gap-2 mt-3 text-[12px] font-bold"><input type="checkbox" data-f="sale_needs_customer" ${+s.sale_needs_customer ? 'checked' : ''}>نامِ مشتری در ثبتِ فروشِ ربات اجباری باشد</label>
                <label class="flex items-center gap-2 mt-2 text-[12px] font-bold"><input type="checkbox" data-f="cap_to_commission" ${+s.cap_to_commission ? 'checked' : ''}>جمعِ تخفیفِ مشتری از کارمزدِ نمایندگیِ همان فروش بیشتر نشود</label>
                <div class="mk-row mt-3"><b class="text-[12.5px]">پاداشِ حجمِ فروش <small class="text-slate-500 font-normal">(اگر فروشِ ماه بینِ «از» و «تا» بود: درصدی روی کلِ حق بازاریابیِ ماه + مبلغِ ثابت)</small></b><div data-vol></div>${ed ? '<button class="mk-btn s sm" data-addvol style="align-self:flex-start"><i class="fas fa-plus"></i>پله</button>' : ''}</div>
                <p class="text-[11.5px] text-slate-600 leading-7 mt-3"><i class="fas fa-circle-info ml-1"></i>درآمدِ ماه = حقوقِ ثابت (اگر فروش به کف رسید) + حق بازاریابی + ٪ِ فروشِ بالای کف + پاداشِ حجم + پاداشِ سطح. هر سطح و هر نفر می‌تواند این عددها را جداگانه داشته باشد (خالی = همین تنظیماتِ کلی).</p>
                ${ed ? '<button class="mk-btn p mt-2" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>' : ''}`;
            const vol = body.querySelector('[data-vol]');
            Vol.mount(vol, s.volume_tiers || []);
            if (!ed) return;
            body.querySelector('[data-addvol]').onclick = () => Vol.add(vol);
            body.querySelector('[data-save]').onclick = async () => {
                const v = k => { const x = body.querySelector(`[data-f=${k}]`); return x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; };
                try { await api('settings_save', {settings: {base_salary: en(v('base_salary')), base_floor: en(v('base_floor')), monthly_target: en(v('monthly_target')), over_pct: en(v('over_pct')), level_mode: v('level_mode'),
                    report_title: v('report_title'), sale_needs_customer: v('sale_needs_customer'), cap_to_commission: v('cap_to_commission'), volume_tiers: Vol.read(vol)}}); toast('ذخیره شد.', 'success'); await boot(true); }
                catch (e) { toast(e.message, 'error'); }
            };
        },
        types(body, ed, root) {
            const tier = t => `<div class="mk-tier" data-tr><input class="mk-in money-input" data-t="from" placeholder="از حق‌بیمه" value="${mv(t.from)}"><input class="mk-in money-input" data-t="to" placeholder="تا (خالی = بی‌سقف)" value="${mv(t.to)}">
                <input class="mk-in" data-t="share" placeholder="سهم ٪" value="${t.share !== undefined ? fa(t.share) : ''}"><input class="mk-in money-input" data-t="fixed" placeholder="+ مبلغ ثابت" value="${mv(t.fixed)}"><button class="mk-btn r sm x" data-rmt><i class="fas fa-xmark"></i></button></div>`;
            const card = t => `<div class="mk-row" data-type="${t.id || ''}"><div class="flex flex-wrap gap-2 items-end"><label style="width:64px"><span class="mk-lbl">آیکن</span><input class="mk-in" data-k="icon" value="${esc(t.icon || '')}"></label>
                <label class="flex-1" style="min-width:180px"><span class="mk-lbl">نوعِ بیمه‌نامه</span><input class="mk-in" data-k="name" value="${esc(t.name || '')}"></label>
                <label style="width:110px"><span class="mk-lbl">کارمزدِ نمایندگی ٪</span><input class="mk-in" data-k="commission_pct" value="${fa(t.commission_pct ?? '')}"></label>
                <label style="width:150px"><span class="mk-lbl">حداقلِ حق‌بیمه</span><input class="mk-in money-input" data-k="min_premium" value="${mv(t.min_premium)}"></label>
                <label style="width:150px"><span class="mk-lbl">حداکثر (خالی = بی‌سقف)</span><input class="mk-in money-input" data-k="max_premium" value="${mv(t.max_premium)}"></label>
                <label class="flex items-center gap-1 text-[12px] font-bold pb-2"><input type="checkbox" data-k="active" ${t.active === 0 ? '' : 'checked'}>فعال</label>
                ${ed ? '<button class="mk-btn r sm" data-rmtype style="margin-bottom:6px"><i class="fas fa-trash"></i></button>' : ''}</div>
                <span class="mk-lbl">پله‌های سهمِ بازاریاب از کارمزد <small class="text-slate-400 font-normal">(حق‌بیمه از … تا … ← چند ٪ِ کارمزد + مبلغِ ثابت)</small></span>
                <div class="flex flex-col gap-2" data-tiers>${(t.tiers || [{from: 0, to: null, share: 30}]).map(tier).join('')}</div>${ed ? '<button class="mk-btn s sm" data-addt style="align-self:flex-start"><i class="fas fa-plus"></i>پله</button>' : ''}
                <input class="mk-in" data-k="note" placeholder="توضیح (اختیاری)" value="${esc(t.note || '')}"></div>`;
            body.innerHTML = `<p class="text-[11.5px] text-slate-600 mb-3 leading-7">حق بازاریابی = حق‌بیمه × کارمزدِ نمایندگی × سهمِ بازاریاب (طبقِ پله‌ی حق‌بیمه) + مبلغِ ثابتِ پله. ترتیبِ این‌جا همان ترتیبِ دکمه‌ها در ربات است.</p>
                <div class="flex flex-col gap-3" data-list>${this.d.types.map(card).join('')}</div>${ed ? '<div class="flex gap-2 mt-3"><button class="mk-btn s" data-addtype><i class="fas fa-plus"></i>نوعِ جدید</button><button class="mk-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره‌ی همه</button></div>' : ''}`;
            const bind = () => {
                body.querySelectorAll('[data-rmt]').forEach(b => b.onclick = () => b.closest('[data-tr]').remove());
                body.querySelectorAll('[data-addt]').forEach(b => b.onclick = () => { b.previousElementSibling.insertAdjacentHTML('beforeend', tier({})); bind(); });
                body.querySelectorAll('[data-rmtype]').forEach(b => b.onclick = () => b.closest('[data-type]').remove());
                if (window.MoneyInput) MoneyInput.apply(body);
            };
            bind();
            if (!ed) return;
            body.querySelector('[data-addtype]').onclick = () => { body.querySelector('[data-list]').insertAdjacentHTML('beforeend', card({active: 1})); bind(); };
            body.querySelector('[data-save]').onclick = async () => {
                const types = [...body.querySelectorAll('[data-type]')].map(c => {
                    const v = k => c.querySelector(`[data-k=${k}]`);
                    return {id: c.dataset.type || 0, icon: v('icon').value, name: v('name').value, commission_pct: en(v('commission_pct').value), min_premium: num(v('min_premium').value),
                        max_premium: v('max_premium').value.trim() === '' ? '' : num(v('max_premium').value), active: v('active').checked ? 1 : 0, note: v('note').value,
                        tiers: [...c.querySelectorAll('[data-tr]')].map(r => ({from: num(r.querySelector('[data-t=from]').value), to: r.querySelector('[data-t=to]').value.trim() === '' ? '' : num(r.querySelector('[data-t=to]').value), share: en(r.querySelector('[data-t=share]').value), fixed: num(r.querySelector('[data-t=fixed]').value)}))};
                });
                try { await api('types_save', {types}); toast('انواعِ بیمه‌نامه ذخیره شد.', 'success'); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); }
            };
        },
        levels(body, ed, root) {
            const card = l => `<div class="mk-row" data-lv="${l.id || ''}"><div class="flex flex-wrap gap-2 items-end">${badge(l.id ? l : {name: l.name || '؟', color: l.color || '#64748b'}, 44)}
                <label style="width:150px"><span class="mk-lbl">نامِ سطح</span><input class="mk-in" data-k="name" value="${esc(l.name || '')}"></label>
                <label style="width:80px"><span class="mk-lbl">رنگ</span><input class="mk-in" type="color" data-k="color" value="${esc(l.color || '#64748b')}" style="padding:2px;height:37px"></label>
                <label style="width:150px"><span class="mk-lbl">از فروشِ ماه</span><input class="mk-in money-input" data-k="min_sales" value="${mv(l.min_sales)}"></label>
                <label style="width:150px"><span class="mk-lbl">تا (خالی = بی‌سقف)</span><input class="mk-in money-input" data-k="max_sales" value="${mv(l.max_sales)}"></label>
                ${ed && l.id ? `<label class="mk-btn s sm" style="cursor:pointer;margin-bottom:6px"><i class="fas fa-image"></i>تصویر<input type="file" accept="image/*" class="hidden" data-img></label>${l.image_path ? '<button class="mk-btn s sm" data-noimg style="margin-bottom:6px">حذفِ تصویر</button>' : ''}` : ''}
                ${ed ? '<button class="mk-btn r sm" data-rm style="margin-bottom:6px"><i class="fas fa-trash"></i></button>' : ''}</div>
                <div class="mk-grid mk-g3"><label><span class="mk-lbl">حقوقِ ثابت (خالی = کلی)</span><input class="mk-in money-input" data-k="base_salary" value="${mv(l.base_salary)}"></label>
                    <label><span class="mk-lbl">کفِ فروش برای حقوقِ ثابت</span><input class="mk-in money-input" data-k="base_floor" value="${mv(l.base_floor)}"></label>
                    <label><span class="mk-lbl">هدفِ ماهانه</span><input class="mk-in money-input" data-k="target" value="${mv(l.target)}"></label>
                    <label><span class="mk-lbl">٪ِ فروشِ بالای کف</span><input class="mk-in" data-k="over_pct" value="${l.over_pct === null || l.over_pct === undefined ? '' : fa(l.over_pct)}"></label>
                    <label><span class="mk-lbl">پاداشِ سطح (٪ روی حق بازاریابی)</span><input class="mk-in" data-k="fee_bonus_pct" value="${l.fee_bonus_pct === null || l.fee_bonus_pct === undefined ? '' : fa(l.fee_bonus_pct)}"></label>
                    <label><span class="mk-lbl">مزایا / توضیح</span><input class="mk-in" data-k="perks" value="${esc(l.perks || '')}"></label></div></div>`;
            body.innerHTML = `<p class="text-[11.5px] text-slate-600 mb-3 leading-7">سطحِ هر نفر با فروشِ همان ماه (یا سطحِ ثابتی که برایش تعیین کرده‌اید) مشخص می‌شود؛ هر سطح می‌تواند حقوقِ ثابت، کف، هدف و پاداشِ خودش را داشته باشد. تصویرِ سطح در ربات و گزارش‌ها نشان داده می‌شود.</p>
                <div class="flex flex-col gap-3" data-list>${this.d.levels.map(card).join('')}</div>${ed ? '<div class="flex gap-2 mt-3"><button class="mk-btn s" data-add><i class="fas fa-plus"></i>سطحِ جدید</button><button class="mk-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره‌ی همه</button></div>' : ''}`;
            if (!ed) return;
            const bind = () => { body.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => b.closest('[data-lv]').remove()); if (window.MoneyInput) MoneyInput.apply(body); };
            bind();
            body.querySelector('[data-add]').onclick = () => { body.querySelector('[data-list]').insertAdjacentHTML('beforeend', card({})); bind(); };
            body.querySelectorAll('[data-img]').forEach(inp => inp.onchange = async () => {
                if (!inp.files[0]) return;
                const fd = new FormData(); fd.append('id', inp.closest('[data-lv]').dataset.lv); fd.append('file', inp.files[0]);
                try { await api('level_image', {}, fd); toast('تصویر ذخیره شد.', 'success'); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); }
            });
            body.querySelectorAll('[data-noimg]').forEach(b => b.onclick = async () => { try { await api('level_image', {id: b.closest('[data-lv]').dataset.lv, remove: 1}); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); } });
            body.querySelector('[data-save]').onclick = async () => {
                const levels = [...body.querySelectorAll('[data-lv]')].map(c => { const v = k => c.querySelector(`[data-k=${k}]`).value; const mo = k => v(k).trim() === '' ? '' : num(v(k));
                    return {id: c.dataset.lv || 0, name: v('name'), color: v('color'), min_sales: num(v('min_sales')), max_sales: mo('max_sales'), base_salary: mo('base_salary'), base_floor: mo('base_floor'),
                            target: mo('target'), over_pct: en(v('over_pct')), fee_bonus_pct: en(v('fee_bonus_pct')), perks: v('perks')}; });
                try { await api('levels_save', {levels}); toast('سطح‌ها ذخیره شد.', 'success'); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); }
            };
        },
        rewards(body, ed, root) {
            const sel = (k, opts, v) => `<select class="mk-sel" data-k="${k}">${Object.entries(opts).map(([a, b]) => `<option value="${a}" ${String(v) === String(a) ? 'selected' : ''}>${b}</option>`).join('')}</select>`;
            const tOpts = Object.assign({'': 'همه‌ی انواع'}, Object.fromEntries(this.d.types.map(t => [t.id, t.name])));
            const card = r => `<div class="mk-row" data-rw="${r.id || ''}"><div class="flex flex-wrap gap-2 items-end">
                <label class="flex-1" style="min-width:200px"><span class="mk-lbl">عنوان</span><input class="mk-in" data-k="title" value="${esc(r.title || '')}"></label>
                <label style="width:230px"><span class="mk-lbl">نوعِ بیمه‌نامه</span>${sel('type_id', tOpts, r.type_id || '')}</label>
                <label class="flex items-center gap-1 text-[12px] font-bold pb-2"><input type="checkbox" data-k="active" ${r.active === 0 ? '' : 'checked'}>فعال</label>${ed ? '<button class="mk-btn r sm" data-rm style="margin-bottom:6px"><i class="fas fa-trash"></i></button>' : ''}</div>
                <div class="mk-grid mk-g4"><label><span class="mk-lbl">حق‌بیمه از</span><input class="mk-in money-input" data-k="min_premium" value="${mv(r.min_premium)}"></label><label><span class="mk-lbl">تا (خالی = بی‌سقف)</span><input class="mk-in money-input" data-k="max_premium" value="${mv(r.max_premium)}"></label>
                    <label><span class="mk-lbl">پرداخت</span>${sel('pay_mode', K.boot.pay, r.pay_mode || 'any')}</label><label><span class="mk-lbl">مشتری</span>${sel('customer', K.boot.cust, r.customer || 'any')}</label>
                    <label><span class="mk-lbl">حداقلِ تعداد</span><input class="mk-in" data-k="min_count" value="${fa(r.min_count || 1)}"></label><label><span class="mk-lbl">نوعِ پاداش</span>${sel('reward_kind', K.boot.reward_kinds, r.reward_kind || 'percent')}</label>
                    <label><span class="mk-lbl">مقدار (٪ یا ریال)</span><input class="mk-in" data-k="value" value="${r.value ? (r.reward_kind === 'amount' ? money(r.value) : fa(r.value)) : ''}"></label><label><span class="mk-lbl">سقفِ تخفیف (ریال)</span><input class="mk-in money-input" data-k="max_amount" value="${mv(r.max_amount)}"></label>
                    <label style="grid-column:span 2"><span class="mk-lbl">هدیه (متن)</span><input class="mk-in" data-k="gift_text" value="${esc(r.gift_text || '')}"></label><label><span class="mk-lbl">اولویت</span><input class="mk-in" data-k="priority" value="${fa(r.priority || 0)}"></label>
                    <label class="flex items-center gap-1 text-[12px] font-bold"><input type="checkbox" data-k="stackable" ${+r.stackable ? 'checked' : ''}>با بقیه جمع می‌شود</label></div>
                <input class="mk-in" data-k="note" placeholder="توضیح" value="${esc(r.note || '')}"></div>`;
            body.innerHTML = `<p class="text-[11.5px] text-slate-600 mb-3 leading-7">در «محاسبه نرخ پاداش» (ربات و پنل) همه‌ی قانون‌های «قابلِ جمع» به‌اضافه‌ی بهترین قانونِ غیرقابلِ جمع (بیشترین تخفیف) اعمال می‌شود. «تعداد» یعنی تعدادِ بیمه‌نامه یا نفراتِ همان خرید.</p>
                <div class="flex flex-col gap-3" data-list>${this.d.rewards.map(card).join('')}</div>${ed ? '<div class="flex gap-2 mt-3 flex-wrap"><button class="mk-btn s" data-add><i class="fas fa-plus"></i>قانونِ جدید</button><button class="mk-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره‌ی همه</button><button class="mk-btn o" data-calc><i class="fas fa-calculator"></i>امتحان در محاسبه‌گر</button></div>' : ''}`;
            if (!ed) return;
            const bind = () => { body.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => b.closest('[data-rw]').remove()); if (window.MoneyInput) MoneyInput.apply(body); };
            bind();
            body.querySelector('[data-calc]').onclick = () => Calc.open();
            body.querySelector('[data-add]').onclick = () => { body.querySelector('[data-list]').insertAdjacentHTML('afterbegin', card({active: 1, min_count: 1})); bind(); };
            body.querySelector('[data-save]').onclick = async () => {
                const rewards = [...body.querySelectorAll('[data-rw]')].map(c => { const v = k => { const x = c.querySelector(`[data-k=${k}]`); return x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; };
                    return {id: c.dataset.rw || 0, title: v('title'), type_id: v('type_id'), min_premium: num(v('min_premium')), max_premium: v('max_premium').trim() === '' ? '' : num(v('max_premium')), pay_mode: v('pay_mode'), customer: v('customer'),
                        min_count: en(v('min_count')), reward_kind: v('reward_kind'), value: v('reward_kind') === 'amount' ? num(v('value')) : en(v('value')), max_amount: v('max_amount').trim() === '' ? '' : num(v('max_amount')),
                        gift_text: v('gift_text'), priority: en(v('priority')), stackable: v('stackable'), active: v('active'), note: v('note')}; });
                try { await api('rewards_save', {rewards}); toast('قوانینِ پاداش ذخیره شد.', 'success'); this.render(root); } catch (e) { toast(e.message, 'error'); }
            };
        },
    };

    window.Marketing = {open};
})();
