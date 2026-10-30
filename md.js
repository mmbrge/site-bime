// فایل: md.js
// «درمان و عمرِ گروهی» (api/md_actions.php): داشبورد، قراردادهای سالانه (طرح‌ها، حق‌بیمه‌ی ماهانه، سهمِ شرکت/شخص، سقفِ پوشش‌ها و ورود از اکسل)،
// تنظیماتِ درمانِ هر شرکت (اصلی / با خانواده، پیروی از شرکتِ مادر یا قانونِ مستقل) و تنظیماتِ بخش. هر صفحه یک راهنمای کوتاه دارد.
(function () {
    'use strict';
    if (window.Treat) return;
    const API = 'api/md_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => (v === null || v === undefined || v === '') ? '' : fa((Number(v) || 0).toLocaleString('en-US'));
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const ask = (t, m, o) => window.uiConfirm ? uiConfirm(t, m, o || {}) : Promise.resolve(window.confirm(m));
    const M = {boot: null, con: {kind: '', status: 'ACTIVE', q: ''}, co: {q: '', f: ''}, contracts: null, companies: null, lib: null};
    const can = (p, op = 'view') => !!(M.boot && (M.boot.perms[p] || []).includes(op));
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
    const busy = (b, on) => { if (!b) return; b.disabled = on; if (on) { b.dataset.h = b.innerHTML; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> صبر کنید…'; } else if (b.dataset.h) b.innerHTML = b.dataset.h; };

    const STYLE = `
    .md{direction:rtl;font-size:12.5px;color:#0f172a}.md *{box-sizing:border-box}
    .md-hub{display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between;background:linear-gradient(120deg,#1e1b4b,#3730a3 45%,#0f766e);border-radius:24px;padding:16px 18px;color:#fff;box-shadow:0 22px 40px -28px #3730a3;position:relative;overflow:hidden}
    .md-hub::after{content:'';position:absolute;width:280px;height:280px;border-radius:50%;left:-80px;top:-160px;background:rgba(255,255,255,.09);pointer-events:none}
    .md-hub .t{display:flex;align-items:center;gap:12px;z-index:1}.md-hub .ic{width:44px;height:44px;border-radius:15px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:19px}
    .md-hub b{font-size:17px;font-weight:900;display:block}.md-hub small{opacity:.85;font-size:11px}
    .md-nav{display:flex;gap:6px;flex-wrap:wrap;z-index:1}.md-nav button{border:0;font-family:inherit;font-weight:800;font-size:12px;border-radius:14px;padding:8px 13px;background:rgba(255,255,255,.14);color:#fff;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
    .md-nav button.on{background:#fff;color:#3730a3}
    .md-card{background:#fff;border:1px solid #e4e7f5;border-radius:20px;padding:16px;box-shadow:0 10px 30px -26px rgba(15,23,42,.35)}
    .md-card h3{font-size:13.5px;font-weight:900;display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}.md-card h3 small{font-weight:600;color:#64748b;font-size:11px}
    .md-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:13px;padding:8px 13px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;white-space:nowrap;text-decoration:none}
    .md-btn:disabled{opacity:.45;cursor:not-allowed}.md-btn.p{background:linear-gradient(120deg,#4338ca,#0f766e);color:#fff}.md-btn.s{background:#f1f5f9;color:#334155}.md-btn.o{background:#fff;color:#334155;border:1px solid #e2e8f0}
    .md-btn.r{background:#fff1f2;color:#be123c}.md-btn.sm{padding:5px 9px;font-size:11px;border-radius:10px}
    .md-in,.md-sel,.md-ta{border:1px solid #dbe0f0;border-radius:12px;padding:8px 10px;font-size:12.5px;font-family:inherit;background:#fff;width:100%;min-width:0}
    .md-in:focus,.md-sel:focus,.md-ta:focus{outline:2px solid #c7d2fe;border-color:#6366f1}
    .md-lbl{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px}.md-hint{font-size:10.5px;color:#64748b;margin-top:3px;line-height:1.7}
    .md-grid{display:grid;gap:10px}.md-g2{grid-template-columns:repeat(2,minmax(0,1fr))}.md-g3{grid-template-columns:repeat(3,minmax(0,1fr))}.md-g4{grid-template-columns:repeat(4,minmax(0,1fr))}
    .md-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
    .md-kpi{background:#fff;border:1px solid #e4e7f5;border-radius:18px;padding:13px 14px}.md-kpi .k{font-size:11px;font-weight:800;color:#64748b;display:flex;gap:6px;align-items:center}.md-kpi .v{font-size:20px;font-weight:900;margin-top:5px;color:#3730a3}.md-kpi .s{font-size:10.5px;color:#64748b;margin-top:2px}
    .md-tag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;border-radius:9px;padding:2px 8px;background:#f1f5f9;color:#475569;white-space:nowrap}
    .md-tag.g{background:#dcfce7;color:#166534}.md-tag.r{background:#ffe4e6;color:#9f1239}.md-tag.a{background:#fef3c7;color:#92400e}.md-tag.b{background:#e0e7ff;color:#3730a3}.md-tag.c{background:#ccfbf1;color:#115e59}
    .md-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}.md-tbl th{background:#eef2ff;color:#3730a3;font-weight:900;font-size:10.5px;padding:9px 8px;text-align:right;white-space:nowrap;position:sticky;top:0;z-index:1}
    .md-tbl td{padding:9px 8px;border-top:1px solid #eef0f8;vertical-align:middle}.md-tbl tbody tr:hover td{background:#f8f9ff}
    .md-scroll{overflow:auto;border-radius:16px;border:1px solid #eef0f8;max-height:64vh}
    .md-empty{text-align:center;color:#94a3b8;padding:30px 10px;font-weight:700}.md-empty i{font-size:26px;display:block;margin-bottom:8px;color:#cbd5e1}
    .md-ov{position:fixed;inset:0;z-index:9500;background:rgba(15,23,42,.5);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:24px 12px;overflow:auto;direction:rtl}
    .md-md{background:#fff;border-radius:24px;width:100%;max-width:700px;box-shadow:0 30px 60px -25px rgba(0,0,0,.5);animation:mdPop .18s ease-out}.md-md.w{max-width:1150px}
    @keyframes mdPop{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}
    .md-mh{display:flex;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #eef0f8}.md-mh h3{font-size:14.5px;font-weight:900}
    .md-mb{padding:16px 18px}.md-mf{display:flex;gap:8px;justify-content:flex-end;padding:12px 18px;border-top:1px solid #eef0f8;flex-wrap:wrap;position:sticky;bottom:0;background:#fff;border-radius:0 0 24px 24px}
    .md-x{margin-right:auto;width:36px;height:36px;border-radius:12px;background:#f1f5f9;border:0;cursor:pointer}
    .md-seg{display:inline-flex;background:#f1f5f9;border-radius:12px;padding:3px;gap:3px;flex-wrap:wrap}.md-seg button{border:0;background:transparent;border-radius:9px;padding:6px 11px;font-family:inherit;font-weight:800;font-size:11.5px;cursor:pointer;color:#475569}
    .md-seg button.on{background:#fff;color:#3730a3;box-shadow:0 2px 6px -3px rgba(0,0,0,.3)}
    .md-help{background:linear-gradient(120deg,#eef2ff,#f0fdfa);border:1px solid #e0e7ff;border-radius:16px;padding:0 14px}.md-help summary{cursor:pointer;font-weight:900;color:#3730a3;padding:10px 0;font-size:12px;list-style:none}
    .md-help summary::-webkit-details-marker{display:none}.md-help ul{margin:0 0 10px;padding-right:18px;line-height:2;color:#334155;font-size:11.5px;list-style:disc}
    .md-plan{border:1px solid #e0e7ff;border-radius:18px;padding:12px;background:#fbfbff;display:flex;flex-direction:column;gap:10px}
    .md-plan-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.md-plan-h b{font-size:13px;color:#3730a3}
    .md-cov td{padding:4px 5px}.md-cov input{border:1px solid #e2e8f0;border-radius:9px;padding:5px 7px;font-size:12px;font-family:inherit;width:100%;background:#fff}
    .md-tree td:first-child{white-space:nowrap}.md-sub td:first-child{padding-right:28px}.md-sub td:first-child::before{content:'└';color:#a5b4fc;margin-left:6px}
    .md-danger{border:1px solid #fecdd3;background:linear-gradient(120deg,#fff,#fff5f6)}
    @media (max-width:767px){.md-g2,.md-g3,.md-g4{grid-template-columns:minmax(0,1fr)}.md-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.md-nav{width:100%;overflow:auto;flex-wrap:nowrap}.md-nav button{flex-shrink:0}
        .md-ov{padding:0;align-items:flex-end}.md-md{border-radius:22px 22px 0 0;max-height:94vh;overflow:auto}}`;
    const css = () => { if (!document.getElementById('md-css')) { const s = document.createElement('style'); s.id = 'md-css'; s.textContent = STYLE; document.head.appendChild(s); } };

    const TABS = [['md-dash', 'fa-chart-pie', 'داشبورد'], ['md-contracts', 'fa-file-contract', 'قراردادها و طرح‌ها'], ['md-companies', 'fa-building', 'شرکت‌ها'], ['md-settings', 'fa-sliders', 'تنظیمات']];
    const shell = (tab, body) => `<div class="md space-y-4 no-count" data-perm-skip><div class="md-hub"><div class="t"><span class="ic"><i class="fas fa-staff-snake"></i></span><div><b>درمان و عمرِ گروهی</b><small>قراردادهای درمانِ تکمیلی و عمر و حوادثِ شرکت‌ها، طرح‌ها، سقفِ پوشش‌ها و قانونِ هر شرکت</small></div></div>
        <div class="md-nav">${TABS.filter(t => can(t[0])).map(t => `<button class="${t[0] === tab ? 'on' : ''}" onclick="switchTab('${t[0]}')"><i class="fas ${t[1]}"></i>${t[2]}</button>`).join('')}</div></div>${body}</div>`;
    const help = (title, items, open) => `<details class="md-help" ${open ? 'open' : ''}><summary><i class="fas fa-circle-question ml-1"></i>${esc(title)}</summary><ul>${items.map(x => `<li>${x}</li>`).join('')}</ul></details>`;
    async function boot(force) { if (!M.boot || force) M.boot = await api('bootstrap'); return M.boot; }
    async function open(tab) {
        css();
        const root = document.getElementById(tab + '-root');
        if (!root) return;
        try { await boot(true); } catch (e) { root.innerHTML = `<div class="md md-card md-empty"><i class="fas fa-lock"></i>${esc(e.message)}</div>`; return; }
        if (!can(tab)) { root.innerHTML = shell(tab, '<div class="md-card md-empty"><i class="fas fa-lock"></i>به این بخش دسترسی ندارید.</div>'); return; }
        ({'md-dash': Dash, 'md-contracts': Con, 'md-companies': Co, 'md-settings': Sett})[tab].render(root);
    }
    function modal(title, body, foot, o = {}) {
        const ov = document.createElement('div');
        ov.className = 'md md-ov no-count'; ov.setAttribute('data-perm-skip', '');
        ov.innerHTML = `<div class="md-md ${o.wide ? 'w' : ''}"><div class="md-mh"><h3>${title}</h3><button class="md-x" data-x title="بستن"><i class="fas fa-xmark"></i></button></div><div class="md-mb">${body}</div>${foot ? `<div class="md-mf">${foot}</div>` : ''}</div>`;
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = e => { if (e.key === 'Escape' && !o.sticky) close(); };
        ov.addEventListener('click', e => { if (e.target.closest('[data-x]') || (e.target === ov && !o.sticky)) close(); });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(ov);
        if (window.MoneyInput && MoneyInput.apply) MoneyInput.apply(ov);
        return {el: ov, close, $: s => ov.querySelector(s), $$: s => [...ov.querySelectorAll(s)]};
    }
    const opts = (obj, sel) => Object.entries(obj).map(([k, v]) => `<option value="${esc(k)}" ${String(k) === String(sel ?? '') ? 'selected' : ''}>${esc(v)}</option>`).join('');
    async function loadContracts(force) { if (!M.contracts || force) M.contracts = (await api('contracts', {})).contracts; return M.contracts; }
    async function loadCompanies(force) { if (!M.companies || force) M.companies = (await api('companies')).companies; return M.companies; }
    async function loadLib() { if (!M.lib) M.lib = (await api('cov_lib')).items; return M.lib; }

    // ============================ داشبورد ============================
    const Dash = {
        async render(root) {
            const s = M.boot.stats;
            const k = (ic, t, v, sub) => `<div class="md-kpi"><div class="k"><i class="fas ${ic}"></i>${t}</div><div class="v">${fa(v)}</div>${sub ? `<div class="s">${sub}</div>` : ''}</div>`;
            const phase = (done, t, d) => `<li class="flex gap-2 items-start"><i class="fas ${done ? 'fa-circle-check text-emerald-600' : 'fa-hourglass-half text-indigo-400'} mt-1"></i><span><b>${t}</b><small class="block text-slate-500">${d}</small></span></li>`;
            root.innerHTML = shell('md-dash', `
                ${help('راهنمای بخشِ درمان و عمرِ گروهی', [
                    '<b>قراردادها و طرح‌ها:</b> هر سال برای هر شرکت (یا شرکتِ مادر و زیرمجموعه‌هایش) یک قراردادِ درمانِ تکمیلی و/یا عمر و حوادث ثبت کنید؛ هر قرارداد چند طرح دارد با حق‌بیمه‌ی ماهانه و سقفِ پوشش‌ها.',
                    '<b>شرکت‌ها:</b> برای هر شرکت مشخص کنید درمان و عمر برای «فقط بیمه‌شده‌ی اصلی» است یا «اصلی و خانواده»، و کدام قرارداد و طرح. زیرمجموعه‌ها خودکار از قانونِ شرکتِ مادر پیروی می‌کنند مگر «قانونِ مستقل» بزنید.',
                    'زیرمجموعه بودن فقط روی محاسبه و اقساطِ درمان اثر دارد؛ بایگانیِ خودرو جداست.',
                ], true)}
                <div class="md-kpis">${k('fa-file-contract', 'قراردادهای فعال', s.contracts, `درمان ${fa(s.health)} · عمر و حوادث ${fa(s.life)}`)}${k('fa-heart-pulse', 'شرکت‌های دارای درمان', s.companies_health, 'با قانونِ مستقل یا پیرو')}
                    ${k('fa-shield-heart', 'شرکت‌های دارای عمر و حوادث', s.companies_life)}${k('fa-sitemap', 'پیرو از شرکتِ مادر', s.inherited, `از ${fa(s.companies)} شرکت`)}</div>
                <div class="md-card"><h3><i class="fas fa-route text-indigo-600"></i>مراحلِ ساختِ بخشِ درمان <small>این بخش مرحله به مرحله کامل می‌شود</small></h3>
                    <ul class="space-y-2">${phase(true, 'قراردادها، طرح‌ها، سقفِ پوشش‌ها و تنظیماتِ درمانِ شرکت‌ها', 'همین حالا قابلِ استفاده است')}
                        ${phase(false, 'لیستِ ابتدای دوره و لیست‌های ماهانه (افزایشی/کاهشی)، نقل‌وانتقال، پرونده‌ی افراد و خروجیِ «ورودی به پاسارگاد»', 'مرحله‌ی بعد')}
                        ${phase(false, 'مغایرت‌گیری با سندِ حقوق، صورت‌حسابِ ماهانه (هر شرکت و تجمیعی)، پرداخت به ما / به بیمه‌گر، فیش‌های گروهِ ربات', '')}
                        ${phase(false, 'خروجیِ بازه‌ی تاریخی با ستون‌های دلخواه، بایگانیِ درمان، نمودارها، شرکتِ ایجاد / حضور در اقساطِ خودرو', '')}</ul></div>`);
        }
    };

    // ============================ قراردادها ============================
    const Con = {
        async render(root) {
            const f = M.con;
            root.innerHTML = shell('md-contracts', `
                ${help('راهنمای قراردادها و طرح‌ها', [
                    'هر قرارداد برای یک سال است: نوع (درمانِ تکمیلی یا عمر و حوادث)، بیمه‌گر، شماره‌ی بیمه‌نامه، شرکتِ بیمه‌گذار و تاریخِ شروع و پایان.',
                    '<b>حق‌بیمه:</b> برای هر طرح، یا یک مبلغِ ماهانه برای همه، یا جدا برای اصلی / همسر / فرزند / والدین (از «نحوه‌ی حق‌بیمه»).',
                    '<b>سهمِ شرکت:</b> درصدی که شرکت برای بیمه‌شده‌ی اصلی و برای خانواده می‌دهد؛ بقیه سهمِ خودِ شخص است (۱۰۰ = همه را شرکت می‌دهد، ۰ = همه را شخص).',
                    '<b>سقفِ پوشش‌ها:</b> برای هر طرح ردیف به ردیف (یا از «پوشش‌های رایج»، «کپی از طرحِ دیگر» یا «ورود از اکسل») با سقف، فرانشیز و توضیح.',
                    'عمر و حوادث: مبلغِ ماهانه‌ی هر نفر و سرمایه در طرح؛ اگر مبلغ برای افراد فرق می‌کند «مبلغ برای هر نفر متغیر است» را بزنید.',
                ])}
                <div class="md-card"><div class="flex flex-wrap gap-2 items-center justify-between mb-3">
                    <div class="flex flex-wrap gap-2 items-center"><div class="md-seg" data-kind>${[['', 'همه'], ...Object.entries(M.boot.kinds)].map(([k, v]) => `<button data-k="${k}" class="${f.kind === k ? 'on' : ''}">${v}</button>`).join('')}</div>
                        <div class="md-seg" data-st>${[['', 'همه'], ...Object.entries(M.boot.status)].map(([k, v]) => `<button data-k="${k}" class="${f.status === k ? 'on' : ''}">${v}</button>`).join('')}</div>
                        <input class="md-in" data-q style="width:220px" placeholder="🔍 عنوان، شماره بیمه‌نامه، بیمه‌گر، شرکت" value="${esc(f.q)}"></div>
                    ${can('md-contracts', 'create') ? '<button class="md-btn p" data-new><i class="fas fa-plus"></i>قراردادِ جدید</button>' : ''}</div>
                    <div data-list><p class="md-empty"><i class="fas fa-spinner fa-spin"></i></p></div></div>`);
            const list = root.querySelector('[data-list]');
            const paint = async () => {
                let items;
                try { items = await loadContracts(true); } catch (e) { list.innerHTML = `<p class="md-empty">${esc(e.message)}</p>`; return; }
                const q = f.q.trim();
                items = items.filter(c => (!f.kind || c.kind === f.kind) && (!f.status || c.status === f.status) && (!q || [c.title, c.policy_no, c.insurer, c.holder_name, ...c.companies].join(' ').includes(q)));
                if (!items.length) { list.innerHTML = `<p class="md-empty"><i class="fas fa-file-circle-plus"></i>قراردادی نیست${can('md-contracts', 'create') ? '؛ «قراردادِ جدید» را بزنید.' : '.'}</p>`; return; }
                list.innerHTML = `<div class="md-scroll"><table class="md-tbl no-page"><thead><tr><th>قرارداد</th><th>نوع</th><th>بیمه‌گر</th><th>بیمه‌گذار</th><th>دوره</th><th>طرح‌ها</th><th>شرکت‌های استفاده‌کننده</th><th>وضعیت</th><th></th></tr></thead><tbody>
                    ${items.map(c => `<tr><td><b>${esc(c.title || '—')}</b><small class="block text-slate-500" dir="ltr">${esc(fa(c.policy_no))}</small></td>
                        <td><span class="md-tag ${c.kind === 'HEALTH' ? 'c' : 'b'}">${esc(c.kind_fa)}</span></td><td>${esc(c.insurer)}</td><td>${esc(c.holder_name || '—')}</td>
                        <td class="whitespace-nowrap">${esc(fa(c.start_j))} تا ${esc(fa(c.end_j))}</td>
                        <td>${c.plans.map(p => `<span class="md-tag">${esc(p.name)}${p.code ? ` <small>(${esc(fa(p.code))})</small>` : ''}</span>`).join(' ')}</td>
                        <td>${c.companies.length ? `<small>${esc(c.companies.slice(0, 4).join('، '))}${c.companies.length > 4 ? ` و ${fa(c.companies.length - 4)} شرکتِ دیگر` : ''}</small>` : '<small class="text-slate-400">—</small>'}</td>
                        <td><span class="md-tag ${c.status === 'ACTIVE' ? 'g' : 's'}">${esc(c.status_fa)}</span></td>
                        <td class="whitespace-nowrap"><button class="md-btn o sm" data-ed="${c.id}"><i class="fas ${can('md-contracts', 'edit') ? 'fa-pen' : 'fa-eye'}"></i>${can('md-contracts', 'edit') ? 'ویرایش' : 'نمایش'}</button>
                            ${can('md-contracts', 'delete') ? `<button class="md-btn r sm" data-del="${c.id}" title="حذف"><i class="fas fa-trash-can"></i></button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
                list.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => Con.edit(+b.dataset.ed, paint));
                list.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
                    const c = items.find(x => x.id === +b.dataset.del);
                    if (!(await ask('حذفِ قرارداد', `قراردادِ «${c.title || c.policy_no || ''}» حذف شود؟${c.companies.length ? `\nاز تنظیماتِ ${fa(c.companies.length)} شرکت هم برداشته می‌شود.` : ''}`, {danger: true, ok: 'حذف'}))) return;
                    try { await api('contract_delete', {id: c.id}); toast('قرارداد حذف شد.', 'success'); M.companies = null; paint(); } catch (e) { toast(e.message, 'error'); }
                });
            };
            root.querySelectorAll('[data-kind] [data-k]').forEach(b => b.onclick = () => { f.kind = b.dataset.k; Con.render(root); });
            root.querySelectorAll('[data-st] [data-k]').forEach(b => b.onclick = () => { f.status = b.dataset.k; Con.render(root); });
            let tm; root.querySelector('[data-q]').oninput = e => { clearTimeout(tm); tm = setTimeout(() => { f.q = e.target.value; paint(); }, 300); };
            const nb = root.querySelector('[data-new]'); if (nb) nb.onclick = () => Con.edit(0, paint);
            paint();
        },
        // ویرایشگرِ قرارداد: مشخصات، نحوه‌ی حق‌بیمه و سهم‌ها، طرح‌ها با سقفِ پوشش‌ها
        async edit(id, onSaved) {
            let c;
            try {
                await loadLib(); await loadCompanies();
                c = id ? (await api('contract_get', {id})).contract : {kind: M.con.kind || 'HEALTH', insurer: 'پاسارگاد', premium_mode: 'FLAT', share_main_pct: 100, share_family_pct: 100, vat_pct: 0, status: 'ACTIVE', plans: [{name: 'طرح ۱', code: '1', coverages: []}]};
            } catch (e) { toast(e.message, 'error'); return; }
            const ro = id ? !can('md-contracts', 'edit') : !can('md-contracts', 'create');
            const S = JSON.parse(JSON.stringify(c));
            const coOpts = `<option value="">— انتخاب —</option>${M.companies.map(x => `<option value="${x.id}" ${x.id === S.holder_company_id ? 'selected' : ''}>${esc(x.parent_name ? x.name + ' ← ' + x.parent_name : x.name)}</option>`).join('')}`;
            const m = modal(id ? 'ویرایشِ قرارداد' : 'قراردادِ جدید', `
                <div class="md-grid md-g4">
                    <label class="col-span-full"><span class="md-lbl">نوعِ قرارداد</span><div class="md-seg" data-kind>${Object.entries(M.boot.kinds).map(([k, v]) => `<button type="button" data-k="${k}" class="${S.kind === k ? 'on' : ''}">${v}</button>`).join('')}</div></label>
                    <label><span class="md-lbl">عنوان</span><input class="md-in" data-f="title" value="${esc(S.title || '')}" placeholder="مثلاً درمانِ گروه سرزمین ۱۴۰۵"></label>
                    <label><span class="md-lbl">بیمه‌گر</span><input class="md-in" data-f="insurer" list="md-ins-list" value="${esc(S.insurer || '')}"><datalist id="md-ins-list">${M.boot.insurers.map(x => `<option value="${esc(x)}">`).join('')}</datalist></label>
                    <label><span class="md-lbl">شماره‌ی بیمه‌نامه / قرارداد</span><input class="md-in" data-f="policy_no" dir="ltr" value="${esc(S.policy_no || '')}"></label>
                    <label><span class="md-lbl">شرکتِ بیمه‌گذار</span><select class="md-sel" data-f="holder_company_id">${coOpts}</select></label>
                    <label><span class="md-lbl">تاریخِ شروع</span><input class="md-in" data-f="start_j" data-jdate value="${esc(fa(S.start_j || ''))}" placeholder="۱۴۰۵/۰۸/۰۱"></label>
                    <label><span class="md-lbl">تاریخِ پایان</span><input class="md-in" data-f="end_j" data-jdate value="${esc(fa(S.end_j || ''))}" placeholder="۱۴۰۶/۰۸/۰۱"></label>
                    <label><span class="md-lbl">مالیات بر ارزش افزوده (٪)</span><input class="md-in" data-f="vat_pct" value="${esc(fa(S.vat_pct || 0))}"><div class="md-hint">در صورت‌حساب جدا نوشته می‌شود (۰ = ندارد).</div></label>
                    <label><span class="md-lbl">وضعیت</span><select class="md-sel" data-f="status">${opts(M.boot.status, S.status)}</select></label>
                </div>
                <div class="md-card mt-3" style="background:#fbfbff"><h3><i class="fas fa-scale-balanced text-indigo-600"></i>حق‌بیمه و سهمِ پرداخت</h3><div class="md-grid md-g3">
                    <label data-hp><span class="md-lbl">نحوه‌ی حق‌بیمه‌ی طرح‌ها</span><div class="md-seg" data-pm>${Object.entries(M.boot.premium_modes).map(([k, v]) => `<button type="button" data-k="${k}" class="${S.premium_mode === k ? 'on' : ''}">${v}</button>`).join('')}</div></label>
                    <label><span class="md-lbl">سهمِ شرکت برای بیمه‌شده‌ی اصلی (٪)</span><input class="md-in" data-f="share_main_pct" value="${esc(fa(S.share_main_pct))}"><div class="md-hint">بقیه سهمِ خودِ شخص است.</div></label>
                    <label><span class="md-lbl">سهمِ شرکت برای خانواده (٪)</span><input class="md-in" data-f="share_family_pct" value="${esc(fa(S.share_family_pct))}"><div class="md-hint">۰ = حق‌بیمه‌ی خانواده را کاملاً خودِ شخص می‌دهد.</div></label>
                </div></div>
                <div class="flex items-center justify-between mt-4 mb-2"><b class="text-[13px]"><i class="fas fa-layer-group ml-1 text-indigo-600"></i>طرح‌ها</b>${ro ? '' : '<button type="button" class="md-btn s sm" data-addplan><i class="fas fa-plus"></i>طرحِ تازه</button>'}</div>
                <div data-plans class="space-y-3"></div>
                <label class="block mt-3"><span class="md-lbl">توضیح</span><textarea class="md-ta" data-f="note" rows="2">${esc(S.note || '')}</textarea></label>`,
                ro ? '<button class="md-btn s" data-x>بستن</button>' : '<button class="md-btn s" data-x>انصراف</button><button class="md-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره‌ی قرارداد</button>', {wide: true, sticky: true});
            const plansBox = m.$('[data-plans]');
            const libList = `<datalist id="md-cov-lib">${M.lib.map(x => `<option value="${esc(x)}">`).join('')}</datalist>`;
            const covRow = (cv, pi, ci) => `<tr data-ci="${ci}"><td style="min-width:240px"><input list="md-cov-lib" data-cv="name" value="${esc(cv.name || '')}" placeholder="نامِ پوشش" ${ro ? 'readonly' : ''}></td>
                <td style="width:170px"><input class="money-input" data-cv="cap" value="${esc(money(cv.cap))}" placeholder="سقف (ریال)" ${ro ? 'readonly' : ''}></td>
                <td style="width:110px"><input data-cv="franchise" value="${esc(cv.franchise || '')}" placeholder="فرانشیز" ${ro ? 'readonly' : ''}></td>
                <td><input data-cv="note" value="${esc(cv.note || '')}" placeholder="توضیح" ${ro ? 'readonly' : ''}></td>
                <td style="width:34px">${ro ? '' : '<button type="button" class="md-btn r sm" data-cvdel title="حذف"><i class="fas fa-xmark"></i></button>'}</td></tr>`;
            const premFields = (p, pi) => {
                const inp = (k, l, h) => `<label><span class="md-lbl">${l}</span><input class="md-in money-input" data-pf="${k}" value="${esc(money(p[k] || 0))}" ${ro ? 'readonly' : ''}>${h ? `<div class="md-hint">${h}</div>` : ''}</label>`;
                if (S.kind === 'LIFE') return inp('p_flat', 'مبلغِ ماهانه‌ی هر نفر (ریال)') + inp('life_capital', 'سرمایه‌ی بیمه (ریال)', 'اختیاری') + `<label class="flex items-center gap-2 mt-5"><input type="checkbox" data-pf="variable_amount" ${p.variable_amount ? 'checked' : ''} ${ro ? 'disabled' : ''}><span class="text-[11.5px] font-bold">مبلغ برای هر نفر متغیر است</span></label>`;
                if (S.premium_mode === 'REL') return inp('p_main', 'اصلی (ماهانه)') + inp('p_spouse', 'همسر (ماهانه)') + inp('p_child', 'هر فرزند (ماهانه)') + inp('p_parent', 'پدر / مادر (ماهانه)');
                return inp('p_flat', 'حق‌بیمه‌ی ماهانه‌ی هر نفر (ریال)');
            };
            const paintPlans = () => {
                plansBox.innerHTML = S.plans.map((p, pi) => `<div class="md-plan" data-pi="${pi}">
                    <div class="md-plan-h"><b><i class="fas fa-layer-group ml-1"></i>طرحِ ${fa(pi + 1)}</b>
                        <input class="md-in" style="max-width:220px" data-pf="name" value="${esc(p.name || '')}" placeholder="نامِ طرح" ${ro ? 'readonly' : ''}>
                        <input class="md-in" style="max-width:140px" data-pf="code" value="${esc(p.code || '')}" placeholder="کدِ گروه" title="کدِ گروه / نوعِ طرح در فایلِ «ورودی به پاسارگاد»" ${ro ? 'readonly' : ''}>
                        <span class="flex-1"></span>${ro || S.plans.length < 2 ? '' : '<button type="button" class="md-btn r sm" data-pdel><i class="fas fa-trash-can"></i>حذفِ طرح</button>'}</div>
                    <div class="md-grid md-g4">${premFields(p, pi)}</div>
                    <div><div class="flex flex-wrap items-center gap-2 mb-1"><b class="text-[12px]">سقفِ پوشش‌ها</b><small class="text-slate-500">${fa(p.coverages.length)} ردیف</small><span class="flex-1"></span>
                        ${ro ? '' : `<button type="button" class="md-btn s sm" data-cvadd><i class="fas fa-plus"></i>ردیف</button><button type="button" class="md-btn s sm" data-cvlib><i class="fas fa-list-check"></i>پوشش‌های رایج</button>
                        ${S.plans.length > 1 ? '<button type="button" class="md-btn s sm" data-cvcopy><i class="fas fa-copy"></i>کپی از طرحِ دیگر</button>' : ''}<button type="button" class="md-btn s sm" data-cvxl><i class="fas fa-file-excel"></i>ورود از اکسل</button>`}</div>
                        <div class="md-scroll" style="max-height:340px"><table class="md-tbl md-cov no-page"><thead><tr><th>پوشش</th><th>سقف (ریال)</th><th>فرانشیز</th><th>توضیح</th><th></th></tr></thead><tbody>${p.coverages.map((cv, ci) => covRow(cv, pi, ci)).join('') || '<tr><td colspan="5" class="text-center text-slate-400 py-3">هنوز پوششی ثبت نشده.</td></tr>'}</tbody></table></div></div></div>`).join('') + libList;
                if (window.MoneyInput && MoneyInput.apply) MoneyInput.apply(plansBox);
                bindPlans();
            };
            // مقدارهای فرم به S (پیش از هر بازسازی)
            const pull = () => {
                m.$$('[data-f]').forEach(i => { S[i.dataset.f] = i.value; });
                m.$$('[data-pi]').forEach(card => {
                    const p = S.plans[+card.dataset.pi]; if (!p) return;
                    card.querySelectorAll('[data-pf]').forEach(i => { p[i.dataset.pf] = i.type === 'checkbox' ? i.checked : i.value; });
                    p.coverages = [...card.querySelectorAll('tr[data-ci]')].map(tr => { const o = {}; tr.querySelectorAll('[data-cv]').forEach(i => o[i.dataset.cv] = i.value); return o; });
                });
            };
            const bindPlans = () => {
                m.$$('[data-pi]').forEach(card => {
                    const pi = +card.dataset.pi, p = S.plans[pi];
                    const on = (sel, fn) => { const b = card.querySelector(sel); if (b) b.onclick = () => { pull(); fn(); paintPlans(); }; };
                    on('[data-pdel]', () => S.plans.splice(pi, 1));
                    on('[data-cvadd]', () => p.coverages.push({name: '', cap: '', franchise: '', note: ''}));
                    on('[data-cvlib]', () => { const have = new Set(p.coverages.map(x => x.name)); M.lib.forEach(n => { if (!have.has(n)) p.coverages.push({name: n, cap: '', franchise: '', note: ''}); }); });
                    const cp = card.querySelector('[data-cvcopy]');
                    if (cp) cp.onclick = async () => {
                        pull();
                        const others = S.plans.map((x, i) => [i, x]).filter(([i]) => i !== pi);
                        const pick = others.length === 1 ? others[0][0] : await new Promise(res => {
                            const mm = modal('کپیِ پوشش‌ها از طرحِ دیگر', `<div class="flex flex-col gap-2">${others.map(([i, x]) => `<button class="md-btn o" data-pick="${i}">${esc(x.name || 'طرحِ ' + fa(i + 1))} <small>(${fa(x.coverages.length)} ردیف)</small></button>`).join('')}</div>`, '<button class="md-btn s" data-x>انصراف</button>');
                            mm.$$('[data-pick]').forEach(b => b.onclick = () => { mm.close(); res(+b.dataset.pick); });
                            mm.el.addEventListener('click', e => { if (e.target.closest('[data-x]')) res(null); });
                        });
                        if (pick === null || pick === undefined) return;
                        if (p.coverages.length && !(await ask('کپیِ پوشش‌ها', `پوشش‌های فعلیِ این طرح (${fa(p.coverages.length)} ردیف) با پوشش‌های «${S.plans[pick].name}» جایگزین شود؟`, {ok: 'جایگزین شود'}))) return;
                        p.coverages = JSON.parse(JSON.stringify(S.plans[pick].coverages)); paintPlans();
                    };
                    const xl = card.querySelector('[data-cvxl]'); if (xl) xl.onclick = () => { pull(); Con.importCov(S, pi, paintPlans); };
                    card.querySelectorAll('[data-cvdel]').forEach(b => b.onclick = () => { pull(); p.coverages.splice(+b.closest('tr').dataset.ci, 1); paintPlans(); });
                });
            };
            m.$$('[data-kind] [data-k]').forEach(b => b.onclick = () => { if (ro) return; pull(); S.kind = b.dataset.k; m.$$('[data-kind] [data-k]').forEach(x => x.classList.toggle('on', x === b)); m.$('[data-hp]').style.display = S.kind === 'LIFE' ? 'none' : ''; paintPlans(); });
            m.$$('[data-pm] [data-k]').forEach(b => b.onclick = () => { if (ro) return; pull(); S.premium_mode = b.dataset.k; m.$$('[data-pm] [data-k]').forEach(x => x.classList.toggle('on', x === b)); paintPlans(); });
            m.$('[data-hp]').style.display = S.kind === 'LIFE' ? 'none' : '';
            const ap = m.$('[data-addplan]'); if (ap) ap.onclick = () => { pull(); S.plans.push({name: 'طرح ' + fa(S.plans.length + 1), code: String(S.plans.length + 1), coverages: []}); paintPlans(); };
            if (ro) m.$$('[data-f]').forEach(i => i.disabled = true);
            paintPlans();
            const sv = m.$('[data-save]');
            if (sv) sv.onclick = async () => {
                pull();
                busy(sv, true);
                try {
                    const r = await api('contract_save', {contract: S});
                    toast('قرارداد ذخیره شد.', 'success'); m.close(); M.companies = null;
                    if (onSaved) onSaved(r.contract);
                } catch (e) { toast(e.message, 'error'); }
                busy(sv, false);
            };
        },
        // ورودِ سقفِ پوشش‌ها از اکسل: بارگذاری → انتخابِ شیت و ستون‌ها → خواندن → افزودن به طرحِ فعلی (یا به طرح‌های همنام)
        importCov(S, pi, done) {
            const m = modal('ورودِ سقفِ پوشش‌ها از اکسل', `
                ${help('راهنما', ['فایلِ اکسلِ بیمه‌گر (xlsx یا xls) را انتخاب کنید؛ ستون‌های «پوشش»، «سقف» و «فرانشیز» خودکار پیدا می‌شوند و اگر درست نبود عوضشان کنید.',
                    'اگر فایل ستونِ «طرح» دارد و آن را انتخاب کنید، هر ردیف به طرحِ همنامش می‌رود (طرحِ تازه هم ساخته می‌شود)؛ وگرنه همه به همین طرح اضافه می‌شوند.'], true)}
                <div class="flex flex-wrap gap-2 items-end mt-3"><label class="flex-1"><span class="md-lbl">فایل</span><input type="file" class="md-in" data-file accept=".xlsx,.xls,.csv"></label></div>
                <div data-map class="mt-3"></div>`, '<button class="md-btn s" data-x>انصراف</button><button class="md-btn p" data-go disabled><i class="fas fa-file-import"></i>افزودن به طرح</button>', {wide: true});
            let P = null;
            const paintMap = () => {
                const d = P.detect, hdr = (P.sample[P.hr] || []);
                const colSel = (k, l, req) => `<label><span class="md-lbl">${l}${req ? ' *' : ''}</span><select class="md-sel" data-mc="${k}"><option value="">${req ? '— انتخاب —' : '— ندارد —'}</option>${hdr.map((h, i) => `<option value="${i}" ${String(P.map[k]) === String(i) ? 'selected' : ''}>${esc(fa(i + 1))}. ${esc(String(h).slice(0, 40))}</option>`).join('')}</select></label>`;
                m.$('[data-map]').innerHTML = `<div class="md-grid md-g4">
                    ${P.sheets.length > 1 ? `<label><span class="md-lbl">شیت</span><select class="md-sel" data-sheet>${P.sheets.map(s => `<option ${s === P.sheet ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select></label>` : ''}
                    <label><span class="md-lbl">ردیفِ عنوان‌ها</span><select class="md-sel" data-hr>${P.sample.map((r, i) => `<option value="${i}" ${i === P.hr ? 'selected' : ''}>${fa(i + 1)}: ${esc(r.filter(Boolean).slice(0, 3).join(' | ').slice(0, 50))}</option>`).join('')}</select></label>
                    ${colSel('name', 'نامِ پوشش', true)}${colSel('cap', 'سقف')}${colSel('franchise', 'فرانشیز')}${colSel('plan', 'طرح')}${colSel('note', 'توضیح')}</div>
                    <div class="md-scroll mt-3" style="max-height:260px"><table class="md-tbl no-page"><tbody>${P.sample.slice(0, 15).map((r, i) => `<tr style="${i === P.hr ? 'background:#eef2ff;font-weight:800' : ''}">${r.map(x => `<td>${esc(fa(String(x).slice(0, 40)))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
                const sh = m.$('[data-sheet]'); if (sh) sh.onchange = () => preview(sh.value);
                m.$('[data-hr]').onchange = e => { P.hr = +e.target.value; paintMap(); };
                m.$$('[data-mc]').forEach(s => s.onchange = () => { P.map[s.dataset.mc] = s.value; m.$('[data-go]').disabled = P.map.name === '' || P.map.name === null || P.map.name === undefined; });
                m.$('[data-go]').disabled = P.map.name === null || P.map.name === undefined || P.map.name === '';
            };
            const preview = async sheet => {
                const fd = new FormData();
                if (P && P.token) fd.append('token', P.token); else fd.append('file', m.$('[data-file]').files[0]);
                if (sheet) fd.append('sheet', sheet);
                try {
                    const r = await api('cov_import_preview', {}, fd);
                    P = {token: r.token, sheets: r.sheets, sheet: r.sheet, sample: r.sample, detect: r.detect, hr: r.detect.header_row,
                         map: {name: r.detect.name, cap: r.detect.cap, franchise: r.detect.franchise, plan: r.detect.plan, note: r.detect.note}};
                    paintMap();
                } catch (e) { toast(e.message, 'error'); }
            };
            m.$('[data-file]').onchange = () => { P = null; if (m.$('[data-file]').files[0]) preview(); };
            m.$('[data-go]').onclick = async () => {
                const b = m.$('[data-go]'); busy(b, true);
                try {
                    const r = await api('cov_import_parse', {token: P.token, sheet: P.sheet, map: Object.assign({header_row: P.hr}, P.map)});
                    let added = 0;
                    r.rows.forEach(x => {
                        let t = S.plans[pi];
                        if (x.plan && P.map.plan !== '' && P.map.plan !== null) {
                            t = S.plans.find(p => p.name.trim() === x.plan.trim() || String(p.code || '') === x.plan.trim());
                            if (!t) { t = {name: x.plan, code: '', coverages: []}; S.plans.push(t); }
                        }
                        t.coverages.push({name: x.name, cap: x.cap, franchise: x.franchise, note: x.note}); added++;
                    });
                    toast(`${fa(added)} ردیفِ پوشش اضافه شد؛ برای ثبت، قرارداد را ذخیره کنید.`, 'success');
                    m.close(); done();
                } catch (e) { toast(e.message, 'error'); }
                busy(b, false);
            };
        }
    };

    // ============================ شرکت‌ها ============================
    const Co = {
        async render(root) {
            const f = M.co;
            root.innerHTML = shell('md-companies', `
                ${help('راهنمای تنظیماتِ درمانِ شرکت‌ها', [
                    'برای هر شرکت مشخص کنید درمانِ تکمیلی و عمر و حوادث «ندارد»، «فقط بیمه‌شده‌ی اصلی» یا «اصلی و خانواده» است و از کدام قرارداد و طرح.',
                    'شرکتِ زیرمجموعه (شرکتِ مادر در «مدیریتِ شرکت‌ها» تعیین می‌شود) خودکار از قانونِ شرکتِ مادر پیروی می‌کند؛ با «قانونِ مستقل» قانونِ جدا می‌گیرد و حساب‌وکتابِ اقساطش جدا می‌شود.',
                    'این تنظیمات فقط برای درمان است؛ روی بایگانیِ خودرو اثری ندارد.',
                ])}
                <div class="md-card"><div class="flex flex-wrap gap-2 items-center mb-3">
                    <input class="md-in" data-q style="width:240px" placeholder="🔍 نامِ شرکت" value="${esc(f.q)}">
                    <div class="md-seg" data-f>${[['', 'همه'], ['health', 'دارای درمان'], ['life', 'دارای عمر و حوادث'], ['own', 'قانونِ مستقل'], ['inh', 'پیرو از شرکتِ مادر'], ['none', 'بدونِ درمان و عمر']].map(([k, v]) => `<button data-k="${k}" class="${f.f === k ? 'on' : ''}">${v}</button>`).join('')}</div></div>
                    <div data-list><p class="md-empty"><i class="fas fa-spinner fa-spin"></i></p></div></div>`);
            const list = root.querySelector('[data-list]');
            const paint = async () => {
                let rows;
                try { rows = await loadCompanies(true); } catch (e) { list.innerHTML = `<p class="md-empty">${esc(e.message)}</p>`; return; }
                const q = f.q.trim();
                const ok = r => (!q || r.name.includes(q) || (r.parent_name || '').includes(q)) && (!f.f || (f.f === 'health' && r.eff.health_mode !== 'none') || (f.f === 'life' && r.eff.life_mode !== 'none')
                    || (f.f === 'own' && !r.eff.inherited) || (f.f === 'inh' && r.eff.inherited) || (f.f === 'none' && r.eff.health_mode === 'none' && r.eff.life_mode === 'none'));
                // درختی: هر شرکتِ مادر و زیرمجموعه‌هایش زیرِ آن
                const byParent = {}; rows.forEach(r => { (byParent[r.parent_id || 0] = byParent[r.parent_id || 0] || []).push(r); });
                const ids = new Set(rows.map(r => r.id)), order = [];
                const walk = (pid, depth) => (byParent[pid] || []).forEach(r => { order.push([r, depth]); walk(r.id, depth + 1); });
                walk(0, 0); rows.filter(r => r.parent_id && !ids.has(r.parent_id)).forEach(r => order.push([r, 0]));
                const shown = order.filter(([r]) => ok(r));
                if (!shown.length) { list.innerHTML = '<p class="md-empty"><i class="fas fa-building"></i>شرکتی پیدا نشد.</p>'; return; }
                const side = (mode, con, plan) => mode === 'none' ? '<span class="md-tag">ندارد</span>' : `<span class="md-tag ${mode === 'family' ? 'g' : 'c'}">${esc(M.boot.modes[mode])}</span>${con ? `<small class="block text-slate-500 mt-1">${esc(con)}${plan ? ' · ' + esc(plan) : ''}</small>` : '<small class="block text-rose-600 mt-1">قرارداد انتخاب نشده</small>'}`;
                list.innerHTML = `<div class="md-scroll"><table class="md-tbl md-tree no-page"><thead><tr><th>شرکت</th><th>قانون</th><th>درمانِ تکمیلی</th><th>عمر و حوادث</th><th></th></tr></thead><tbody>
                    ${shown.map(([r, d]) => `<tr class="${d ? 'md-sub' : ''}" style="${d > 1 ? `--d:${d}` : ''}"><td style="${d > 1 ? `padding-right:${14 + d * 14}px` : ''}"><b>${esc(r.name)}</b>${r.children ? ` <small class="text-slate-400">(${fa(r.children)} زیرمجموعه)</small>` : ''}</td>
                        <td>${r.eff.inherited ? `<span class="md-tag a"><i class="fas fa-arrow-turn-up"></i>پیرو «${esc(r.eff.source_name)}»</span>` : `<span class="md-tag b">${r.parent_id ? 'قانونِ مستقل' : 'قانونِ خودش'}</span>`}</td>
                        <td>${side(r.eff.health_mode, r.eff.health_contract, r.eff.health_plan)}</td><td>${side(r.eff.life_mode, r.eff.life_contract, r.eff.life_plan)}</td>
                        <td>${can('md-companies', 'edit') ? `<button class="md-btn o sm" data-ed="${r.id}"><i class="fas fa-sliders"></i>تنظیم</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
                list.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => Co.edit(rows.find(x => x.id === +b.dataset.ed), paint));
            };
            root.querySelectorAll('[data-f] [data-k]').forEach(b => b.onclick = () => { f.f = b.dataset.k; Co.render(root); });
            let tm; root.querySelector('[data-q]').oninput = e => { clearTimeout(tm); tm = setTimeout(() => { f.q = e.target.value; paint(); }, 250); };
            paint();
        },
        async edit(r, onSaved) {
            let cons;
            try { cons = await loadContracts(true); } catch (e) { toast(e.message, 'error'); return; }
            const C = Object.assign({own_rule: r.own_rule || !r.parent_id}, r.cfg);
            const conOpts = (kind, sel) => `<option value="">— انتخاب —</option>${cons.filter(c => c.kind === kind && (c.status === 'ACTIVE' || c.id === sel)).map(c => `<option value="${c.id}" ${c.id === sel ? 'selected' : ''}>${esc((c.title || c.policy_no || 'قرارداد #' + c.id) + ' · ' + fa(c.start_j) + ' تا ' + fa(c.end_j))}</option>`).join('')}`;
            const planOpts = (cid, sel) => { const c = cons.find(x => x.id === +cid); return `<option value="">— طرحِ پیش‌فرض —</option>${(c ? c.plans : []).map(p => `<option value="${p.id}" ${p.id === sel ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}`; };
            const side = (k, title, ic) => `<div class="md-card" style="background:#fbfbff"><h3><i class="fas ${ic} text-indigo-600"></i>${title}</h3><div class="md-grid md-g3">
                <label><span class="md-lbl">پوشش</span><select class="md-sel" data-c="${k}_mode">${opts(M.boot.modes, C[k + '_mode'])}</select></label>
                <label><span class="md-lbl">قرارداد</span><select class="md-sel" data-c="${k}_contract_id">${conOpts(k === 'health' ? 'HEALTH' : 'LIFE', C[k + '_contract_id'])}</select></label>
                <label><span class="md-lbl">طرحِ پیش‌فرض</span><select class="md-sel" data-c="${k}_plan_id">${planOpts(C[k + '_contract_id'], C[k + '_plan_id'])}</select><div class="md-hint">برای افرادی که طرحشان در لیست مشخص نیست.</div></label></div></div>`;
            const m = modal(`تنظیماتِ درمانِ «${esc(r.name)}»`, `
                ${r.parent_id ? `<div class="md-card mb-3"><h3><i class="fas fa-sitemap text-indigo-600"></i>قانونِ درمان <small>شرکتِ مادر: ${esc(r.parent_name)}</small></h3>
                    <div class="md-seg" data-own><button type="button" data-k="0" class="${!C.own_rule ? 'on' : ''}">پیروی از قانونِ «${esc(r.parent_name)}»</button><button type="button" data-k="1" class="${C.own_rule ? 'on' : ''}">قانونِ مستقل برای همین شرکت</button></div>
                    <div class="md-hint" data-inh></div></div>` : ''}
                <div data-own-box class="space-y-3">${side('health', 'درمانِ تکمیلی', 'fa-heart-pulse')}${side('life', 'عمر و حوادث', 'fa-shield-heart')}
                    <label class="flex items-center gap-2 text-[12px] text-slate-400"><input type="checkbox" disabled ${C.portal_on ? 'checked' : ''}>بارگذاریِ لیست و دیدنِ صورت‌حساب در پرتالِ شرکت (فعلاً غیرفعال)</label></div>
                <label class="block mt-3"><span class="md-lbl">یادداشت</span><textarea class="md-ta" data-c="note" rows="2">${esc(C.note || '')}</textarea></label>`,
                '<button class="md-btn s" data-x>انصراف</button><button class="md-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>');
            const box = m.$('[data-own-box]');
            const paintOwn = () => {
                if (!r.parent_id) return;
                box.style.display = C.own_rule ? '' : 'none';
                const inh = m.$('[data-inh]'), par = (M.companies || []).find(x => x.id === r.parent_id);
                inh.innerHTML = C.own_rule ? 'این شرکت قانون و حساب‌وکتابِ درمانِ جدا دارد.' : (par ? `فعلاً: درمان «${esc(M.boot.modes[par.eff.health_mode])}»${par.eff.health_contract ? ' (' + esc(par.eff.health_contract) + ')' : ''} · عمر و حوادث «${esc(M.boot.modes[par.eff.life_mode])}»${par.eff.life_contract ? ' (' + esc(par.eff.life_contract) + ')' : ''}` : '');
            };
            m.$$('[data-own] [data-k]').forEach(b => b.onclick = () => { C.own_rule = b.dataset.k === '1'; m.$$('[data-own] [data-k]').forEach(x => x.classList.toggle('on', x === b)); paintOwn(); });
            ['health', 'life'].forEach(k => { m.$(`[data-c="${k}_contract_id"]`).onchange = e => { m.$(`[data-c="${k}_plan_id"]`).innerHTML = planOpts(+e.target.value, null); }; });
            paintOwn();
            m.$('[data-save]').onclick = async () => {
                const cfg = {own_rule: C.own_rule ? 1 : 0, portal_on: C.portal_on ? 1 : 0};
                m.$$('[data-c]').forEach(i => cfg[i.dataset.c] = i.value);
                if (cfg.own_rule || !r.parent_id) for (const k of ['health', 'life']) if (cfg[k + '_mode'] !== 'none' && !cfg[k + '_contract_id']) { toast(`برای ${k === 'health' ? 'درمانِ تکمیلی' : 'عمر و حوادث'} قرارداد را انتخاب کنید (یا «ندارد» بزنید).`, 'error'); return; }
                const b = m.$('[data-save]'); busy(b, true);
                try { await api('company_save', {company_id: r.id, cfg}); toast('تنظیماتِ درمانِ شرکت ذخیره شد.', 'success'); m.close(); onSaved && onSaved(); } catch (e) { toast(e.message, 'error'); }
                busy(b, false);
            };
        }
    };

    // ============================ تنظیمات ============================
    const Sett = {
        async render(root) {
            root.innerHTML = shell('md-settings', `
                ${help('راهنمای تنظیمات', ['«پوشش‌های رایج» همان فهرستی است که در ویرایشگرِ طرح با یک دکمه اضافه می‌شود (هر خط یک پوشش).',
                    'پاک کردنِ کلِ داده‌های درمان در «تنظیمات سیستم ← پاکسازی و بازنشانی داده‌ها» بخشِ «درمان و عمرِ گروهی» است (فقط مدیر کل؛ پیش از حذف خودکار بکاپ کامل گرفته می‌شود).'])}
                ${(window.__DB || {}).realAdmin && typeof window.openResetModal === 'function' ? `<div class="md-card" style="border-color:#fecaca;background:#fff7f7"><h3 class="text-rose-600"><i class="fas fa-dumpster-fire"></i>پاک کردنِ کلِ داده‌های درمان</h3>
                    <p class="text-[11.5px] text-slate-600 leading-6">فقط داده‌های همین بخش (قراردادها، طرح‌ها، سقفِ پوشش‌ها، تنظیمِ درمانِ شرکت‌ها، لیست‌ها، صورت‌حساب‌ها، پرداخت‌ها و بایگانیِ درمان) از دیتابیس و بایگانی پاک می‌شود؛ شرکت‌ها، خودرو و بقیه‌ی بخش‌ها دست نمی‌خورند. پیش از حذف بکاپِ کامل ساخته می‌شود.</p>
                    <button class="md-btn r mt-2" data-wipe><i class="fas fa-trash-can"></i>پاک کردنِ داده‌های درمان…</button></div>` : ''}
                <div class="md-grid md-g2"><div class="md-card"><h3><i class="fas fa-list-check text-indigo-600"></i>پوشش‌های رایج</h3>
                    <textarea class="md-ta" data-lib rows="14" ${can('md-settings', 'edit') ? '' : 'readonly'}></textarea>
                    ${can('md-settings', 'edit') ? '<div class="mt-2"><button class="md-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button></div>' : ''}</div>
                <div class="md-card"><h3><i class="fas fa-clock-rotate-left text-indigo-600"></i>آخرین تغییرات</h3><div data-log class="md-scroll" style="max-height:360px"><p class="md-empty"><i class="fas fa-spinner fa-spin"></i></p></div></div></div>`);
            try { M.lib = null; root.querySelector('[data-lib]').value = (await loadLib()).join('\n'); } catch (e) {}
            const wp = root.querySelector('[data-wipe]');
            if (wp) wp.onclick = () => window.openResetModal('treatment');
            const sv = root.querySelector('[data-save]');
            if (sv) sv.onclick = async () => { busy(sv, true); try { await api('cov_lib_save', {items: root.querySelector('[data-lib]').value.split('\n')}); M.lib = null; toast('ذخیره شد.', 'success'); } catch (e) { toast(e.message, 'error'); } busy(sv, false); };
            try {
                const l = await api('log');
                root.querySelector('[data-log]').innerHTML = l.items.length ? `<table class="md-tbl no-page"><tbody>${l.items.map(x => `<tr><td class="whitespace-nowrap text-slate-500">${esc(fa(x.at))}</td><td>${esc(x.user_name || '')}</td><td>${esc(x.note || x.action)}</td></tr>`).join('')}</tbody></table>` : '<p class="md-empty">هنوز تغییری ثبت نشده.</p>';
            } catch (e) { root.querySelector('[data-log]').innerHTML = `<p class="md-empty">${esc(e.message)}</p>`; }
        }
    };

    window.Treat = {open};
})();
