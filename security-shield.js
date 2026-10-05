// فایل: security-shield.js
// «تنظیمات ← سپر امنیتی» (فقط مدیر کل): امتیازِ امنیت، بررسی‌ها با راهِ رفع، نمودارِ رخدادها، جدولِ رخدادها با فیلتر و اکسل،
// نشست‌های کاربران با خروجِ اجباری، IPهای مسدود و تنظیماتِ خروجِ خودکار / قفلِ ورود.
(function () {
    'use strict';
    const API = 'api/security_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const dec = s => { try { return decodeURIComponent(s); } catch (e) { return s; } };
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : alert(m));
    const confirmBox = (t, m) => (window.uiConfirm ? uiConfirm(t, m) : Promise.resolve(confirm(t + '\n' + m)));
    const SEV = {info: ['اطلاع', '#64748b', '#f1f5f9'], low: ['کم', '#0284c7', '#e0f2fe'], medium: ['متوسط', '#d97706', '#fef3c7'], high: ['زیاد', '#ea580c', '#ffedd5'], critical: ['بحرانی', '#e11d48', '#ffe4e6']};
    const TYPE_IC = {LOGIN_FAIL: 'fa-key', LOGIN_LOCK: 'fa-lock', LOGIN_BLOCKED: 'fa-ban', NEW_IP: 'fa-location-dot', IDLE_LOGOUT: 'fa-hourglass-end', FORCED_LOGOUT: 'fa-right-from-bracket',
                     CSRF_BLOCK: 'fa-mask', UPLOAD_BLOCK: 'fa-file-circle-xmark', WEBHOOK_FORGED: 'fa-robot', IP_BLOCKED: 'fa-ban', SUSPICIOUS_INPUT: 'fa-bug', PERM_DENIED: 'fa-user-lock',
                     FILE_DENIED: 'fa-folder-closed', ADMIN_ACTION: 'fa-user-gear'};
    let root = null, ov = null, evFilter = {type: '', severity: '', q: '', from: '', to: '', page: 1}, tab = 'overview';

    async function api(action, body = {}) {
        try {
            const res = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))});
            return await res.json();
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور'}; }
    }

    function css() {
        if (document.getElementById('sec-css')) return;
        const st = document.createElement('style');
        st.id = 'sec-css';
        st.textContent = `
        .sec-hero{background:radial-gradient(1200px 300px at 100% 0,#1e3a8a 0,#0f172a 55%);color:#e2e8f0;border-radius:24px;padding:22px;display:flex;gap:22px;align-items:center;flex-wrap:wrap;box-shadow:0 20px 50px -20px rgba(15,23,42,.6);position:relative;overflow:hidden}
        .sec-hero:after{content:"";position:absolute;inset:0;background:repeating-linear-gradient(135deg,rgba(255,255,255,.025) 0 2px,transparent 2px 14px);pointer-events:none}
        .sec-gauge{width:150px;height:150px;flex:none;position:relative}
        .sec-gauge .num{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
        .sec-gauge .num b{font-size:38px;font-weight:900;line-height:1}
        .sec-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;flex:1;min-width:260px;z-index:1}
        .sec-kpi{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:12px}
        .sec-kpi b{display:block;font-size:22px;font-weight:900;color:#fff}
        .sec-kpi span{font-size:11px;color:#94a3b8}
        .sec-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:16px 0}
        .sec-tabs button{border:1px solid #e2e8f0;background:#fff;border-radius:12px;padding:8px 14px;font-weight:800;font-size:12px;color:#475569;cursor:pointer}
        .sec-tabs button.on{background:#0f172a;color:#fff;border-color:#0f172a}
        .sec-card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:16px;box-shadow:0 4px 16px -8px rgba(15,23,42,.08)}
        .sec-card h3{font-weight:900;color:#0f172a;font-size:14px;margin-bottom:10px;display:flex;align-items:center;gap:8px}
        .sec-check{display:flex;gap:12px;padding:12px;border-radius:14px;border:1px solid #f1f5f9;margin-bottom:8px;align-items:flex-start}
        .sec-check .ic{width:34px;height:34px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex:none;font-size:14px}
        .sec-check.ok .ic{background:#dcfce7;color:#16a34a}.sec-check.warn .ic{background:#fef3c7;color:#d97706}.sec-check.bad .ic{background:#ffe4e6;color:#e11d48}
        .sec-check.bad{border-color:#fecdd3;background:#fff7f8}.sec-check.warn{border-color:#fde68a;background:#fffdf5}
        .sec-check .t{font-weight:900;font-size:13px;color:#0f172a}.sec-check .d{font-size:12px;color:#475569;margin-top:2px;line-height:1.8}
        .sec-check .f{font-size:11px;color:#0369a1;background:#f0f9ff;border-radius:10px;padding:6px 10px;margin-top:6px;line-height:1.8}
        .sec-grp{font-size:11px;font-weight:900;color:#94a3b8;margin:12px 4px 6px}
        .sec-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:10px;font-weight:900;white-space:nowrap}
        .sec-tbl{width:100%;font-size:12px;border-collapse:separate;border-spacing:0}
        .sec-tbl th{background:#f8fafc;color:#64748b;font-weight:800;text-align:right;padding:9px;border-bottom:1px solid #e2e8f0;white-space:nowrap;position:sticky;top:0}
        .sec-tbl td{padding:9px;border-bottom:1px solid #f1f5f9;vertical-align:top}
        .sec-tbl tr:hover td{background:#f8fafc}
        .sec-in{border:1px solid #e2e8f0;border-radius:12px;padding:8px 10px;font-size:12px;background:#fff;min-width:0}
        .sec-btn{border:0;border-radius:12px;padding:9px 14px;font-weight:900;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
        .sec-btn.dark{background:#0f172a;color:#fff}.sec-btn.blue{background:#2563eb;color:#fff}.sec-btn.red{background:#e11d48;color:#fff}.sec-btn.light{background:#f1f5f9;color:#334155}
        .sec-btn.green{background:#059669;color:#fff}
        .sec-btn:disabled{opacity:.6;cursor:wait}
        .sec-bars{display:flex;align-items:flex-end;gap:6px;height:130px;padding-top:6px}
        .sec-bars .b{flex:1;display:flex;flex-direction:column-reverse;border-radius:8px 8px 0 0;overflow:hidden;min-height:2px;background:#f1f5f9}
        .sec-bars .lb{font-size:9px;color:#94a3b8;text-align:center;margin-top:4px}
        .sec-dot{width:9px;height:9px;border-radius:50%;display:inline-block}
        .sec-grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        @media (max-width: 900px){.sec-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.sec-grid2{grid-template-columns:minmax(0,1fr)}}
        .sec-sw{position:relative;width:42px;height:24px;flex:none}.sec-sw input{opacity:0;width:0;height:0}
        .sec-sw span{position:absolute;inset:0;background:#cbd5e1;border-radius:99px;transition:.2s;cursor:pointer}
        .sec-sw span:before{content:"";position:absolute;width:18px;height:18px;right:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
        .sec-sw input:checked+span{background:#059669}.sec-sw input:checked+span:before{transform:translateX(-18px)}
        `;
        document.head.appendChild(st);
    }

    function gauge(score) {
        const col = score >= 85 ? '#22c55e' : score >= 60 ? '#f59e0b' : '#f43f5e';
        const r = 62, c = 2 * Math.PI * r, off = c * (1 - score / 100);
        const word = score >= 85 ? 'عالی' : score >= 70 ? 'خوب' : score >= 50 ? 'نیاز به توجه' : 'در خطر';
        return `<div class="sec-gauge"><svg width="150" height="150" viewBox="0 0 150 150"><circle cx="75" cy="75" r="${r}" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="13"/>
            <circle cx="75" cy="75" r="${r}" fill="none" stroke="${col}" stroke-width="13" stroke-linecap="round" stroke-dasharray="${c}" stroke-dashoffset="${off}" transform="rotate(-90 75 75)" style="transition:stroke-dashoffset 1s"/></svg>
            <div class="num"><b style="color:${col}">${fa(score)}</b><span style="font-size:11px;color:#cbd5e1;margin-top:4px">${word}</span></div></div>`;
    }
    const sevPill = s => { const v = SEV[s] || SEV.medium; return `<span class="sec-pill" style="color:${v[1]};background:${v[2]}">${v[0]}</span>`; };

    async function init(el) {
        root = el; css();
        root.innerHTML = '<div class="p-10 text-center text-slate-400"><i class="fas fa-spinner fa-spin text-2xl"></i></div>';
        const d = await api('overview');
        if (!d.ok) { root.innerHTML = `<div class="sec-card text-rose-600 font-bold">${esc(d.error || 'خطا')}</div>`; return; }
        ov = d;
        render();
    }

    function render() {
        const d = ov, h24 = d.stats.h24, d7 = d.stats.d7;
        const sum = o => Object.values(o).reduce((a, b) => a + b, 0);
        const bad = d.checks.filter(c => c.status === 'bad').length, warn = d.checks.filter(c => c.status === 'warn').length;
        root.innerHTML = `
        <div class="sec-hero">
            ${gauge(d.score)}
            <div style="flex:1;min-width:240px;z-index:1">
                <div class="flex items-center gap-2 mb-1"><i class="fas fa-shield-halved text-sky-400 text-xl"></i><h2 class="text-xl font-black text-white">سپر امنیتی</h2></div>
                <p class="text-xs text-slate-300 leading-7">${bad ? `<b class="text-rose-300">${fa(bad)} مشکلِ جدی</b> و ` : ''}${warn ? `<b class="text-amber-300">${fa(warn)} هشدار</b> پیدا شد؛ راهِ رفعِ هرکدام پایینِ همان مورد نوشته شده.` : (bad ? '' : 'همه‌ی بررسی‌ها سالم است. 👌')}
                <br>آخرین بررسی: ${esc(d.now_fa)} · IPِ شما: <span dir="ltr">${esc(d.my_ip)}</span></p>
                <div class="flex flex-wrap gap-2 mt-3">
                    <button class="sec-btn blue" data-act="live"><i class="fas fa-vial"></i> آزمایشِ زنده</button>
                    <button class="sec-btn light" data-act="weak"><i class="fas fa-key"></i> بررسیِ رمزهای ضعیف</button>
                    <button class="sec-btn green" data-act="hooks"><i class="fas fa-link"></i> اتصالِ امنِ وب‌هوکِ ربات‌ها</button>
                    <button class="sec-btn light" data-act="refresh"><i class="fas fa-rotate"></i> به‌روزرسانی</button>
                </div>
            </div>
            <div class="sec-kpis">
                <div class="sec-kpi"><b>${fa(h24.LOGIN_FAIL || 0)}</b><span>ورودِ ناموفق (۲۴ ساعت)</span></div>
                <div class="sec-kpi"><b>${fa((h24.LOGIN_LOCK || 0) + (h24.LOGIN_BLOCKED || 0))}</b><span>قفلِ ورود (۲۴ ساعت)</span></div>
                <div class="sec-kpi"><b>${fa((d7.UPLOAD_BLOCK || 0) + (d7.WEBHOOK_FORGED || 0) + (d7.CSRF_BLOCK || 0))}</b><span>حمله‌ی دفع‌شده (۷ روز)</span></div>
                <div class="sec-kpi"><b>${fa(d.blocked_count)}</b><span>IPِ مسدود</span></div>
            </div>
        </div>
        <div class="sec-tabs">
            ${[['overview', 'fa-gauge-high', 'بررسی‌ها'], ['events', 'fa-list-ul', `رخدادها (${fa(d.total_events)})`], ['sessions', 'fa-users-viewfinder', 'نشست‌ها و کاربران'],
               ['blocked', 'fa-ban', 'IPهای مسدود'], ['settings', 'fa-sliders', 'تنظیمات']].map(([k, ic, t]) => `<button data-tab="${k}" class="${tab === k ? 'on' : ''}"><i class="fas ${ic} ml-1"></i>${t}</button>`).join('')}
        </div>
        <div id="sec-body"></div>`;
        root.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => { tab = b.dataset.tab; root.querySelectorAll('[data-tab]').forEach(x => x.classList.toggle('on', x === b)); body(); });
        root.querySelector('[data-act=refresh]').onclick = () => init(root);
        root.querySelector('[data-act=live]').onclick = e => runBtn(e.currentTarget, 'live_test', r => toast(r.bad ? `آزمایش تمام شد: ${fa(r.bad)} مشکل پیدا شد.` : 'آزمایش تمام شد؛ همه‌چیز بسته و امن بود.', r.bad ? 'error' : 'success'));
        root.querySelector('[data-act=weak]').onclick = e => runBtn(e.currentTarget, 'weak_scan', r => toast(r.count ? `${fa(r.count)} حساب رمزِ ضعیف دارد.` : 'هیچ رمزِ ضعیفی پیدا نشد.', r.count ? 'error' : 'success'));
        root.querySelector('[data-act=hooks]').onclick = async e => {
            const btn = e.currentTarget;   // بعد از await دیگر در دسترس نیست
            if (!await confirmBox('اتصالِ امنِ وب‌هوک', 'آدرسِ وب‌هوکِ هر دو ربات با یک کلیدِ مخفیِ تازه دوباره در بله ثبت می‌شود تا پیامِ جعلی پذیرفته نشود. ادامه می‌دهید؟')) return;
            runBtn(btn, 'fix_webhooks', r => {
                const msg = r.results.map(x => `${x.ok ? '✅' : (x.ok === null ? '➖' : '❌')} ${x.label}: ${x.msg}`).join('\n');
                if (window.showAlert) showAlert('اتصالِ امنِ وب‌هوک', msg, r.results.some(x => x.ok === false) ? 'warning' : 'success'); else toast(msg);
            });
        };
        body();
    }
    async function runBtn(btn, action, done) {
        const html = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> لطفاً صبر کنید...';
        const r = await api(action);
        btn.disabled = false; btn.innerHTML = html;
        if (!r.ok) return toast(r.error || 'خطا', 'error');
        done(r);
        const d = await api('overview');
        if (d.ok) { ov = d; render(); }
    }

    function body() {
        const el = root.querySelector('#sec-body');
        if (tab === 'overview') return bodyOverview(el);
        if (tab === 'events') return bodyEvents(el);
        if (tab === 'sessions') return bodySessions(el);
        if (tab === 'blocked') return bodyBlocked(el);
        if (tab === 'settings') return bodySettings(el);
    }

    function bodyOverview(el) {
        const d = ov, groups = {};
        const order = {bad: 0, warn: 1, ok: 2};
        d.checks.slice().sort((a, b) => order[a.status] - order[b.status]).forEach(c => (groups[c.group] = groups[c.group] || []).push(c));
        const ic = {ok: 'fa-check', warn: 'fa-exclamation', bad: 'fa-xmark'};
        const checks = Object.entries(groups).map(([g, items]) => `<div class="sec-grp">${esc(g)}</div>` + items.map(c => `
            <div class="sec-check ${c.status}"><div class="ic"><i class="fas ${ic[c.status]}"></i></div><div class="min-w-0 flex-1">
                <div class="t">${esc(c.title)}</div><div class="d">${esc(c.detail)}</div>
                ${c.status !== 'ok' && c.fix ? `<div class="f"><i class="fas fa-screwdriver-wrench ml-1"></i>راهِ رفع: ${esc(c.fix)}</div>` : ''}</div></div>`).join('')).join('');
        const days = d.stats.days, max = Math.max(1, ...days.map(x => x.info + x.low + x.medium + x.high + x.critical));
        const bars = days.map(x => {
            const seg = ['critical', 'high', 'medium', 'low', 'info'].map(s => x[s] ? `<div style="height:${x[s] / max * 118}px;background:${SEV[s][1]}" title="${SEV[s][0]}: ${fa(x[s])}"></div>` : '').join('');
            const tot = x.info + x.low + x.medium + x.high + x.critical;
            return `<div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;height:100%"><div class="b" style="flex:none" title="${fa(tot)} رخداد">${seg}</div><div class="lb">${esc(x.label)}</div></div>`;
        }).join('');
        const types = Object.entries(d.stats.d7).sort((a, b) => b[1] - a[1]);
        const ips = d.stats.top_ips;
        el.innerHTML = `
        <div class="sec-grid2">
            <div class="sec-card"><h3><i class="fas fa-list-check text-blue-600"></i> بررسی‌های امنیتی</h3>${checks}</div>
            <div class="space-y-4">
                <div class="sec-card"><h3><i class="fas fa-chart-column text-violet-600"></i> رخدادهای ۱۴ روزِ اخیر</h3>
                    <div class="sec-bars">${bars}</div>
                    <div class="flex flex-wrap gap-3 mt-3 text-[11px] text-slate-500">${Object.entries(SEV).map(([k, v]) => `<span><i class="sec-dot" style="background:${v[1]}"></i> ${v[0]}</span>`).join('')}</div></div>
                <div class="sec-card"><h3><i class="fas fa-layer-group text-amber-600"></i> رخدادها بر اساسِ نوع (۷ روز)</h3>
                    ${types.length ? types.map(([t, n]) => `<button class="w-full flex items-center gap-2 py-2 border-b border-slate-50 text-right hover:bg-slate-50 rounded-lg px-1" data-type="${esc(t)}">
                        <i class="fas ${TYPE_IC[t] || 'fa-circle'} text-slate-400 w-5"></i><span class="flex-1 text-xs font-bold text-slate-700">${esc(d.types[t] || t)}</span>
                        <span class="text-xs text-slate-400">۲۴ساعت: ${fa(d.stats.h24[t] || 0)}</span><b class="text-sm text-slate-800 w-10 text-left">${fa(n)}</b></button>`).join('') : '<p class="text-xs text-slate-400 py-6 text-center">در ۷ روزِ گذشته رخدادی ثبت نشده. 🌿</p>'}</div>
                <div class="sec-card"><h3><i class="fas fa-location-crosshairs text-rose-600"></i> IPهای پرتکرار در رخدادها (۷ روز)</h3>
                    ${ips.length ? `<table class="sec-tbl"><thead><tr><th>IP</th><th>رخداد</th><th>مهم</th><th>آخرین</th><th></th></tr></thead><tbody>${ips.map(r => `<tr>
                        <td dir="ltr" class="font-mono text-left">${esc(r.ip)}</td><td>${fa(r.n)}</td><td>${r.hi ? `<b class="text-rose-600">${fa(r.hi)}</b>` : '—'}</td><td class="text-slate-500">${esc(r.last_fa)}</td>
                        <td class="text-left">${r.blocked ? '<span class="sec-pill" style="background:#ffe4e6;color:#e11d48">مسدود</span>' : (r.ip === ov.my_ip ? '<span class="sec-pill" style="background:#e0f2fe;color:#0284c7">شما</span>' : (ov.server_ips || []).includes(r.ip) ? '<span class="sec-pill" style="background:#ecfdf5;color:#047857">سرورِ سایت</span>' : `<button class="sec-btn red" style="padding:5px 9px" data-block="${esc(r.ip)}"><i class="fas fa-ban"></i> مسدود</button>`)}</td></tr>`).join('')}</tbody></table>` : '<p class="text-xs text-slate-400 py-6 text-center">موردی نیست.</p>'}</div>
            </div>
        </div>`;
        el.querySelectorAll('[data-type]').forEach(b => b.onclick = () => { evFilter = {type: b.dataset.type, severity: '', q: '', from: '', to: '', page: 1}; tab = 'events'; render(); });
        el.querySelectorAll('[data-block]').forEach(b => b.onclick = () => blockIp(b.dataset.block));
    }

    async function bodyEvents(el) {
        const f = evFilter;
        el.innerHTML = `<div class="sec-card">
            <div class="flex flex-wrap gap-2 mb-3 items-end">
                <select class="sec-in" id="sec-f-type"><option value="">همه‌ی رخدادها</option>${Object.entries(ov.types).map(([k, v]) => `<option value="${k}" ${f.type === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>
                <select class="sec-in" id="sec-f-sev"><option value="">همه‌ی اهمیت‌ها</option>${Object.entries(SEV).map(([k, v]) => `<option value="${k}" ${f.severity === k ? 'selected' : ''}>${v[0]}</option>`).join('')}</select>
                <input class="sec-in" id="sec-f-from" placeholder="از تاریخ ۱۴۰۵/۰۱/۰۱" value="${esc(f.from)}" style="width:140px">
                <input class="sec-in" id="sec-f-to" placeholder="تا تاریخ" value="${esc(f.to)}" style="width:120px">
                <input class="sec-in" id="sec-f-q" placeholder="جستجو: کاربر، IP، جزئیات..." value="${esc(f.q)}" style="flex:1;min-width:160px">
                <button class="sec-btn dark" id="sec-f-go"><i class="fas fa-filter"></i> فیلتر</button>
                <button class="sec-btn green" id="sec-f-xls"><i class="fas fa-file-excel"></i> اکسل</button>
            </div>
            <div id="sec-ev-list"><div class="p-8 text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i></div></div></div>`;
        const read = () => { f.type = el.querySelector('#sec-f-type').value; f.severity = el.querySelector('#sec-f-sev').value; f.from = el.querySelector('#sec-f-from').value.trim(); f.to = el.querySelector('#sec-f-to').value.trim(); f.q = el.querySelector('#sec-f-q').value.trim(); };
        el.querySelector('#sec-f-go').onclick = () => { read(); f.page = 1; loadEvents(); };
        el.querySelector('#sec-f-q').onkeydown = e => { if (e.key === 'Enter') { read(); f.page = 1; loadEvents(); } };
        el.querySelector('#sec-f-xls').onclick = () => { read(); location.href = API + '?' + new URLSearchParams({action: 'events_export', type: f.type, severity: f.severity, from: f.from, to: f.to, q: f.q}).toString(); };
        loadEvents();
    }
    async function loadEvents() {
        const box = root.querySelector('#sec-ev-list');
        if (!box) return;
        const r = await api('events', evFilter);
        if (!r.ok) { box.innerHTML = `<p class="text-rose-600 text-sm">${esc(r.error)}</p>`; return; }
        if (!r.rows.length) { box.innerHTML = '<p class="text-center text-slate-400 text-sm py-10">رخدادی با این فیلتر پیدا نشد.</p>'; return; }
        box.innerHTML = `<div style="overflow:auto;max-height:65vh;border:1px solid #f1f5f9;border-radius:14px"><table class="sec-tbl"><thead><tr><th>زمان</th><th>رخداد</th><th>اهمیت</th><th>کاربر</th><th>IP</th><th>دستگاه</th><th>جزئیات</th></tr></thead><tbody>
            ${r.rows.map(x => `<tr><td class="whitespace-nowrap text-slate-500">${esc(x.at_fa)}</td>
                <td class="whitespace-nowrap font-bold text-slate-700"><i class="fas ${TYPE_IC[x.type] || 'fa-circle'} text-slate-400 ml-1"></i>${esc(x.type_fa)}</td>
                <td>${sevPill(x.severity)}</td>
                <td class="whitespace-nowrap">${esc(x.user_name || '—')}${x.user_type ? `<div class="text-[10px] text-slate-400">${x.user_type === 'COMPANY' ? 'کاربرِ شرکت' : 'پنل'}</div>` : ''}</td>
                <td dir="ltr" class="font-mono text-left whitespace-nowrap">${esc(x.ip)}${(ov.server_ips || []).includes(x.ip) ? ' <span class="sec-pill" style="background:#ecfdf5;color:#047857" title="درخواست از خودِ سرورِ سایت (مثلاً آزمایشِ زنده)">سرورِ سایت</span>' : ''}${x.ip && x.ip !== ov.my_ip && !(ov.server_ips || []).includes(x.ip) ? ` <button class="text-rose-500 text-[10px]" title="مسدودکردنِ این IP" data-block="${esc(x.ip)}"><i class="fas fa-ban"></i></button>` : ''}</td>
                <td class="text-slate-500 whitespace-nowrap">${esc(x.device)}</td>
                <td style="min-width:220px"><div class="text-slate-700">${esc(x.detail)}</div>${x.path ? `<div class="text-[10px] text-slate-400 font-mono" dir="ltr" style="word-break:break-all">${esc(dec(x.path))}</div>` : ''}</td></tr>`).join('')}
            </tbody></table></div>
            <div class="flex items-center justify-between mt-3 text-xs text-slate-500"><span>${fa(r.total)} رخداد · صفحه‌ی ${fa(r.page)} از ${fa(r.pages)}</span>
            <span class="flex gap-2"><button class="sec-btn light" ${r.page <= 1 ? 'disabled' : ''} data-pg="${r.page - 1}">قبلی</button><button class="sec-btn light" ${r.page >= r.pages ? 'disabled' : ''} data-pg="${r.page + 1}">بعدی</button></span></div>`;
        box.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { evFilter.page = +b.dataset.pg; loadEvents(); });
        box.querySelectorAll('[data-block]').forEach(b => b.onclick = () => blockIp(b.dataset.block));
    }

    async function bodySessions(el) {
        el.innerHTML = '<div class="sec-card"><div class="p-8 text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i></div></div>';
        const r = await api('sessions');
        if (!r.ok) { el.innerHTML = `<div class="sec-card text-rose-600">${esc(r.error)}</div>`; return; }
        const on = r.rows.filter(x => x.online).length;
        const ago = s => s === null ? 'هرگز' : s < 60 ? 'همین الان' : s < 3600 ? fa(Math.round(s / 60)) + ' دقیقه پیش' : s < 86400 ? fa(Math.round(s / 3600)) + ' ساعت پیش' : fa(Math.round(s / 86400)) + ' روز پیش';
        el.innerHTML = `<div class="sec-card">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h3 style="margin:0"><i class="fas fa-users-viewfinder text-emerald-600"></i> نشست‌ها و کاربران <span class="sec-pill" style="background:#dcfce7;color:#16a34a">${fa(on)} آنلاین</span></h3>
                <button class="sec-btn red" id="sec-kill-all"><i class="fas fa-power-off"></i> خروجِ اجباریِ همه (جز من)</button>
            </div>
            <p class="text-[11px] text-slate-500 mb-3 leading-6">کاربری که «${fa(ov.settings.sec_idle_hours)} ساعت» با پنل کار نکند خودکار خارج می‌شود؛ با «خروجِ اجباری» نشستِ کاربر در کمتر از ۱۰ ثانیه بسته می‌شود (مثلاً اگر گوشی‌اش گم شده یا از شرکت رفته).</p>
            <div style="overflow:auto;max-height:65vh;border:1px solid #f1f5f9;border-radius:14px"><table class="sec-tbl"><thead><tr><th>کاربر</th><th>نقش</th><th>وضعیت</th><th>آخرین ورود</th><th>IP / دستگاه</th><th></th></tr></thead><tbody>
            ${r.rows.map(x => `<tr><td class="whitespace-nowrap"><b>${esc(x.name)}</b>${x.me ? ' <span class="text-[10px] text-blue-500">(شما)</span>' : ''}<div class="text-[10px] text-slate-400 font-mono" dir="ltr" style="text-align:right">${esc(x.username)}</div></td>
                <td class="whitespace-nowrap text-slate-600">${esc(x.role_fa)}</td>
                <td class="whitespace-nowrap">${x.online ? '<span class="sec-pill" style="background:#dcfce7;color:#16a34a"><i class="sec-dot" style="background:#22c55e"></i> آنلاین</span>' : `<span class="text-slate-500"><i class="sec-dot" style="background:#cbd5e1"></i> آفلاین · ${ago(x.ago)}</span>`}</td>
                <td class="whitespace-nowrap text-slate-500">${esc(x.last_login_fa || '—')}${x.method ? `<div class="text-[10px] text-slate-400">${esc(x.method)}</div>` : ''}</td>
                <td><span dir="ltr" class="font-mono">${esc(x.last_ip || '—')}</span><div class="text-[10px] text-slate-400">${esc(x.device)}</div></td>
                <td class="text-left">${x.me ? '' : `<button class="sec-btn light" style="padding:5px 10px" data-kill="${x.type}:${x.id}" data-name="${esc(x.name)}"><i class="fas fa-right-from-bracket"></i> خروجِ اجباری</button>`}</td></tr>`).join('')}
            </tbody></table></div></div>`;
        el.querySelector('#sec-kill-all').onclick = async () => {
            if (!await confirmBox('خروجِ اجباریِ همه', 'همه‌ی کاربرانِ پنل و شرکت‌ها (جز خودتان) از پنل خارج می‌شوند و باید دوباره وارد شوند. مطمئنید؟')) return;
            const r2 = await api('force_logout', {all: 1});
            toast(r2.ok ? r2.msg : r2.error, r2.ok ? 'success' : 'error');
            if (r2.ok) { el.querySelectorAll('[data-kill]').forEach(x => markLeaving(x)); refreshLater(el); }
        };
        el.querySelectorAll('[data-kill]').forEach(b => b.onclick = async () => {
            const [type, id] = b.dataset.kill.split(':');
            if (!await confirmBox('خروجِ اجباری', `«${b.dataset.name}» از پنل خارج شود؟`)) return;
            const r2 = await api('force_logout', {type, id: +id});
            toast(r2.ok ? r2.msg : r2.error, r2.ok ? 'success' : 'error');
            if (r2.ok) { markLeaving(b); refreshLater(el); }
        });
    }
    // تا نشستِ کاربر واقعاً بسته شود (حداکثر ۱۰ ثانیه) «در حالِ خروج» نشان داده می‌شود و بعد فهرست تازه می‌شود
    function markLeaving(btn) {
        const tr = btn.closest('tr');
        const st = tr && tr.children[2];
        if (st) st.innerHTML = '<span class="sec-pill" style="background:#fef3c7;color:#b45309"><i class="fas fa-spinner fa-spin"></i> در حالِ خروج…</span>';
        btn.disabled = true;
    }
    let refreshTimer = null;
    function refreshLater(el) {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(() => { if (tab === 'sessions' && root.contains(el)) bodySessions(el); }, 12000);
    }

    async function blockIp(ip) {
        const reason = window.uiPrompt ? await uiPrompt(`مسدودکردنِ ${ip}`, '', {placeholder: 'دلیل (اختیاری)'}) : prompt('دلیل');
        if (reason === null) return;
        const r = await api('block_ip', {ip, reason, hours: 0});
        toast(r.ok ? 'IP مسدود شد.' : r.error, r.ok ? 'success' : 'error');
        if (r.ok) { const d = await api('overview'); if (d.ok) { ov = d; render(); } }
    }

    async function bodyBlocked(el) {
        el.innerHTML = '<div class="sec-card"><div class="p-8 text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i></div></div>';
        const r = await api('blocked');
        if (!r.ok) { el.innerHTML = `<div class="sec-card text-rose-600">${esc(r.error)}</div>`; return; }
        el.innerHTML = `<div class="sec-card">
            <h3><i class="fas fa-ban text-rose-600"></i> IPهای مسدود</h3>
            <div class="flex flex-wrap gap-2 mb-3 items-center bg-slate-50 rounded-2xl p-3">
                <input class="sec-in" id="sec-b-ip" placeholder="IP مثل 5.6.7.8" dir="ltr" style="width:160px">
                <select class="sec-in" id="sec-b-h"><option value="0">همیشه</option><option value="1">۱ ساعت</option><option value="24">۱ روز</option><option value="168">۱ هفته</option><option value="720">۱ ماه</option></select>
                <input class="sec-in" id="sec-b-r" placeholder="دلیل (اختیاری)" style="flex:1;min-width:160px">
                <button class="sec-btn red" id="sec-b-add"><i class="fas fa-plus"></i> مسدود کن</button>
            </div>
            <p class="text-[11px] text-slate-500 mb-3">IPِ شما: <span dir="ltr" class="font-mono">${esc(r.my_ip)}</span> (خودتان را نمی‌توانید مسدود کنید.)</p>
            ${r.rows.length ? `<div style="overflow:auto;border:1px solid #f1f5f9;border-radius:14px"><table class="sec-tbl"><thead><tr><th>IP</th><th>دلیل</th><th>تا</th><th>ثبت</th><th></th></tr></thead><tbody>
            ${r.rows.map(x => `<tr style="${x.active ? '' : 'opacity:.5'}"><td dir="ltr" class="font-mono text-left">${esc(x.ip)}</td><td>${esc(x.reason || '—')}</td><td class="whitespace-nowrap">${esc(x.until_fa)}${x.active ? '' : ' (تمام شده)'}</td>
                <td class="whitespace-nowrap text-slate-500">${esc(x.created_fa)}<div class="text-[10px]">${esc(x.by_name || '')}</div></td>
                <td class="text-left"><button class="sec-btn light" style="padding:5px 10px" data-unblock="${esc(x.ip)}"><i class="fas fa-unlock"></i> برداشتن</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="text-center text-slate-400 text-sm py-8">هیچ IP‌ای مسدود نیست.</p>'}</div>`;
        el.querySelector('#sec-b-add').onclick = async () => {
            const r2 = await api('block_ip', {ip: el.querySelector('#sec-b-ip').value.trim(), hours: +el.querySelector('#sec-b-h').value, reason: el.querySelector('#sec-b-r').value});
            toast(r2.ok ? 'IP مسدود شد.' : r2.error, r2.ok ? 'success' : 'error');
            if (r2.ok) bodyBlocked(el);
        };
        el.querySelectorAll('[data-unblock]').forEach(b => b.onclick = async () => {
            const r2 = await api('unblock_ip', {ip: b.dataset.unblock});
            toast(r2.ok ? 'مسدودی برداشته شد.' : r2.error, r2.ok ? 'success' : 'error');
            if (r2.ok) bodyBlocked(el);
        });
    }

    function bodySettings(el) {
        const s = ov.settings;
        const sw = (k, t, d) => `<label class="flex items-start gap-3 p-3 rounded-2xl border border-slate-100 cursor-pointer"><span class="sec-sw"><input type="checkbox" data-k="${k}" ${String(s[k]) === '1' ? 'checked' : ''}><span></span></span>
            <span><b class="text-sm text-slate-800">${t}</b><span class="block text-[11px] text-slate-500 mt-1 leading-6">${d}</span></span></label>`;
        const num = (k, t, d, step) => `<label class="block p-3 rounded-2xl border border-slate-100"><b class="text-sm text-slate-800">${t}</b>
            <input class="sec-in w-full mt-2" data-k="${k}" type="number" step="${step}" value="${esc(s[k])}" dir="ltr"><span class="block text-[11px] text-slate-500 mt-1 leading-6">${d}</span></label>`;
        el.innerHTML = `<div class="sec-card">
            <h3><i class="fas fa-sliders text-slate-700"></i> تنظیماتِ سپر امنیتی</h3>
            <div class="sec-grid2">
                ${num('sec_idle_hours', 'خروجِ خودکار بعد از عدمِ فعالیت (ساعت)', 'اگر کاربر این مدت با پنل کار نکند (کلیک، تایپ، اسکرول) خودکار خارج می‌شود؛ تا وقتی کار می‌کند داخل می‌ماند. ۵ دقیقه قبل از خروج هشدار می‌بیند. (۰٫۲۵ تا ۲۴)', '0.25')}
                ${num('sec_max_attempts', 'تعدادِ تلاشِ ناموفق تا قفلِ ورود', 'بعد از این تعداد رمزِ اشتباه (از یک IP یا برای یک نام کاربری) ورود موقتاً قفل می‌شود. (۳ تا ۲۰)', '1')}
                ${num('sec_lock_minutes', 'مدتِ قفلِ ورود (دقیقه)', 'در این مدت حتی با رمزِ درست هم ورود ممکن نیست. (۱ تا ۱۴۴۰)', '1')}
                <div class="space-y-3">
                ${sw('sec_notify', 'اعلانِ رخدادِ مهم در ربات بله', 'رخدادهای «زیاد» و «بحرانی» (مثلِ قفلِ ورود، آپلودِ فایلِ خطرناک، پیامِ جعلی به ربات) برای مدیرانِ کلی که ربات شرکت‌ها را وصل کرده‌اند فرستاده می‌شود.')}
                ${sw('sec_waf', 'پایشِ ورودی‌های مشکوک', 'ورودی‌هایی که شبیهِ حمله‌اند (تزریقِ SQL، اسکریپت، پیمایشِ مسیر) ثبت می‌شوند تا در رخدادها ببینید.')}
                </div>
            </div>
            <div class="flex flex-wrap gap-2 mt-4"><button class="sec-btn dark" id="sec-s-save"><i class="fas fa-floppy-disk"></i> ذخیره</button>
            <button class="sec-btn light" id="sec-s-clean"><i class="fas fa-broom"></i> پاک‌کردنِ رخدادهای قدیمی‌تر از ۶ ماه</button></div>
            <div class="mt-5 p-4 rounded-2xl bg-slate-50 text-[12px] text-slate-600 leading-8">
                <b class="text-slate-800">سپر همیشه این‌ها را انجام می‌دهد:</b><br>
                🔒 کوکیِ نشستِ امن (دور از دسترسِ اسکریپت، ارسال‌نشدن از سایتِ دیگر) · 🧱 ردِ درخواستِ جعلی از سایت‌های دیگر ·
                📎 ردِ آپلودِ فایل‌های اجرایی (php، html، svg، ...) · 🗂 اجرانشدنِ هیچ فایلی در پوشه‌های آپلود و بایگانی ·
                🗄 بسته‌بودنِ فایل‌های بایگانی برای کسی که وارد نشده · 🤖 پذیرفتنِ پیامِ ربات فقط با کلیدِ مخفی ·
                🧼 خنثی‌کردنِ کدِ مخرب در متن‌هایی که مشتری‌ها می‌فرستند · 🙈 پنهان‌بودنِ خطاهای فنی · 📝 ثبتِ همه‌ی رخدادها با زمان، کاربر، IP و دستگاه
            </div></div>`;
        el.querySelector('#sec-s-save').onclick = async () => {
            const o = {};
            el.querySelectorAll('[data-k]').forEach(i => { o[i.dataset.k] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; });
            const r = await api('save_settings', {settings: o});
            toast(r.ok ? 'تنظیماتِ سپر ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
            if (r.ok) { const d = await api('overview'); if (d.ok) { ov = d; render(); } }
        };
        el.querySelector('#sec-s-clean').onclick = async () => {
            if (!await confirmBox('پاک‌کردنِ رخدادهای قدیمی', 'رخدادهای امنیتیِ قدیمی‌تر از ۶ ماه پاک شوند؟')) return;
            const r = await api('clear_old', {days: 180});
            toast(r.ok ? `${fa(r.deleted)} رخداد پاک شد.` : r.error, r.ok ? 'success' : 'error');
        };
    }

    window.SecShield = {init};
})();
