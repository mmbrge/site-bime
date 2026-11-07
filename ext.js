// فایل: ext.js
// «افزونه‌ها»: دانلودِ دستیارِ بیمه باما (اقساطِ پاسارگاد + درگاهِ آتی) و برای مدیر: نام‌کاربریِ هر کاربر در هر بخش،
// دستگاه‌ها، فعالیت‌ها (چه کسی، کی، چه کرد)، اعلان‌ها و تنظیمات (VPN، مسیرها، سامانه‌ها، قراردادها و حساب‌ها).
// همچنین بخشِ «افزونه‌ها» در پنجره‌ی «دسترسی‌ها»ی هر کاربر (window.ExtUI.permSection / permSave). API: api/ext_actions.php
(function () {
    'use strict';
    if (window.ExtUI) return;
    const API = 'api/ext_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => FA.indexOf(d));
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function post(b) { try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(b)})).json(); } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; } }
    const MOD = {inst: ['اقساطِ پاسارگاد', '#0284c7', 'fa-money-check-dollar'], ati: ['درگاهِ آتیِ بیمه ایران', '#059669', 'fa-shield-halved']};
    const S = {root: null, tab: 'dl', info: null, data: {}, logF: {page: 0}};

    function css() {
        if (document.getElementById('ext-css')) return;
        const s = document.createElement('style'); s.id = 'ext-css';
        s.textContent = `.ext-hero{border-radius:24px;padding:20px 22px;color:#fff;background:radial-gradient(circle at 85% 10%,rgba(250,204,21,.25),transparent 40%),linear-gradient(125deg,#0f172a,#1e3a8a 55%,#0369a1)}
        .ext-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px}.ext-tab{padding:7px 14px;border-radius:12px;font-size:12px;font-weight:800;color:#dbeafe;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15)}.ext-tab.on{background:#fff;color:#1e3a8a}
        .ext-card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:16px}.ext-in{border:1px solid #e2e8f0;border-radius:11px;padding:6px 9px;font-size:12px;background:#fff}
        .ext-btn{border-radius:12px;padding:8px 14px;font-size:12px;font-weight:800;display:inline-flex;gap:6px;align-items:center}
        .ext-tbl{width:100%;font-size:11.5px;border-collapse:collapse}.ext-tbl th{background:#f1f5f9;padding:7px;text-align:right;color:#475569;white-space:nowrap;position:sticky;top:0}.ext-tbl td{padding:7px;border-bottom:1px solid #f1f5f9;vertical-align:top}
        .ext-chip{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:10.5px;font-weight:800}
        .ext-mod{border-radius:18px;padding:14px;border:1px solid #e2e8f0;background:linear-gradient(180deg,#fff,#f8fafc)}
        .ext-step{display:flex;gap:10px;align-items:flex-start;font-size:12px;line-height:1.8}.ext-step b.n{flex:none;width:24px;height:24px;border-radius:8px;display:grid;place-items:center;color:#fff;background:#1e3a8a;font-size:12px}`;
        document.head.appendChild(s);
    }
    async function init(root, tab) {
        css(); S.root = root || S.root; if (!S.root) return;
        if (tab) S.tab = tab;
        S.root.innerHTML = '<div class="ext-card text-center text-slate-400 p-8"><i class="fas fa-spinner fa-spin"></i></div>';
        const r = await post({action: 'info'});
        if (!r.ok) { S.root.innerHTML = `<div class="ext-card text-rose-600">${esc(r.error)}</div>`; return; }
        S.info = r; render();
    }
    function tabs() {
        const c = S.info.can, T = [['dl', 'fa-download', 'دانلود و نصب']];
        if (c.admin) T.push(['users', 'fa-users-gear', 'کاربران و نام‌کاربری‌ها'], ['devices', 'fa-laptop', 'دستگاه‌ها'], ['logs', 'fa-clock-rotate-left', 'فعالیت‌ها'], ['notifs', 'fa-bell', 'اعلان‌ها'], ['settings', 'fa-sliders', 'تنظیمات']);
        return T;
    }
    async function render() {
        const T = tabs(); if (!T.some(t => t[0] === S.tab)) S.tab = 'dl';
        const head = `<div class="ext-hero mb-3"><p class="text-[11px] opacity-80"><i class="fas fa-puzzle-piece ml-1"></i>افزونه‌ها</p>
            <div class="flex flex-wrap items-center justify-between gap-2 mt-1"><h2 class="text-xl font-black"><i class="fas fa-wand-magic-sparkles ml-1 text-amber-300"></i>دستیارِ بیمه باما <span class="text-[12px] font-bold opacity-80">نسخه‌ی ${fa(S.info.version)}</span></h2></div>
            <p class="text-[11.5px] opacity-85 mt-1">یک افزونه‌ی کروم برای دو کار: ثبتِ خودکارِ نقدی و اقساط در سامانه‌ی پاسارگاد، و درگاهِ هوشمندِ سامانه‌های بیمه ایران (آتی) بدونِ قطعیِ اینترنت.</p>
            <div class="ext-tabs">${T.map(([k, i, t]) => `<button class="ext-tab ${S.tab === k ? 'on' : ''}" data-t="${k}"><i class="fas ${i} ml-1"></i>${t}</button>`).join('')}</div></div>`;
        S.root.innerHTML = head + '<div data-body><div class="ext-card text-center text-slate-400 p-8"><i class="fas fa-spinner fa-spin"></i></div></div>';
        S.root.querySelectorAll('[data-t]').forEach(b => b.onclick = () => { S.tab = b.dataset.t; render(); });
        const body = S.root.querySelector('[data-body]');
        await ({dl: viewDl, users: viewUsers, devices: viewDevices, logs: viewLogs, notifs: viewNotifs, settings: viewSettings}[S.tab])(body);
    }

    // ---------- دانلود ----------
    async function viewDl(b) {
        const i = S.info, mine = i.mine;
        b.innerHTML = `<div class="grid md:grid-cols-2 gap-3 mb-3">${Object.entries(MOD).map(([k, [t, c, ic]]) => `<div class="ext-mod">
                <div class="flex items-center gap-2"><span class="w-9 h-9 rounded-xl grid place-items-center text-white" style="background:${c}"><i class="fas ${ic}"></i></span><b class="text-[13px]">${t}</b>
                ${i.can[k] || i.can.admin ? '<span class="ext-chip" style="background:#dcfce7;color:#166534">دسترسیِ دانلود</span>' : '<span class="ext-chip" style="background:#f1f5f9;color:#64748b">بدونِ دسترسی</span>'}</div>
                <p class="text-[11.5px] text-slate-600 mt-2">نام‌کاربریِ شما: ${mine[k] ? `<b dir="ltr">${esc(mine[k])}</b>` : '<span class="text-amber-600">تعریف نشده (این بخش در افزونه برایتان بسته است)</span>'}</p></div>`).join('')}</div>
            <div class="ext-card mb-3"><div class="flex flex-wrap items-center gap-3"><div class="flex-1 min-w-[220px]"><b class="text-[14px] text-slate-800">بسته‌ی نصب (افزونه + نصابِ ویندوز)</b>
                <p class="text-[11.5px] text-slate-500 mt-1">نشانیِ همین پنل داخلِ افزونه نوشته می‌شود؛ ورود با نام‌کاربری‌ای است که مدیر در «دسترسی‌ها»ی هر نفر تعریف می‌کند.</p></div>
                ${i.can.download ? `<a class="ext-btn text-white" style="background:linear-gradient(135deg,#1e3a8a,#0369a1)" href="${API}?action=download"><i class="fas fa-file-zipper"></i>دانلودِ نسخه‌ی ${fa(i.version)}</a>` : '<span class="text-rose-600 text-[12px]">برای دانلود دسترسی ندارید.</span>'}</div>
                ${i.changelog ? `<div class="mt-3 text-[11.5px] rounded-xl p-2 bg-sky-50 text-sky-800"><b>تغییراتِ این نسخه:</b> ${esc(i.changelog)}</div>` : ''}</div>
            <div class="ext-card"><b class="text-[13px] text-slate-800">نصب (یک بار)</b><div class="mt-3 space-y-2">
                ${[['ZIP را باز کنید (راست‌کلیک ← Extract All) و پوشه را جایی ثابت نگه دارید. فایلِ <b dir="ltr">manifest.json</b> باید مستقیم داخلِ همان پوشه باشد.', ''],
                   ['اگر از درگاهِ آتی استفاده می‌کنید: داخلِ پوشه روی <b>Setup-BimeBama.bat</b> دو بار بزنید (اجازه‌ی مدیرِ ویندوز). نصاب پوشه را به <span dir="ltr">C:\\bimebama\\extension</span> می‌برد و سرویسِ زمینه را با ویندوز اجرا می‌کند.', ''],
                   ['در کروم <span dir="ltr">chrome://extensions</span> ← «Developer mode» ← «Load unpacked» ← پوشه‌ای که <b dir="ltr">manifest.json</b> داخلش است (پیشنهادی: <span dir="ltr">C:\\bimebama\\extension</span> که نصاب ساخته). نسخه‌های قدیمیِ «اقساط» و «آتی» را حذف کنید. بروزرسانی‌های بعدی روی همان پوشه می‌نشیند.', ''],
                   ['روی آیکونِ افزونه بزنید و با نام‌کاربریِ خودتان وارد شوید. بروزرسانی‌های بعدی خودکار است.', '']].map(([t], n) => `<div class="ext-step"><b class="n">${fa(n + 1)}</b><span>${t}</span></div>`).join('')}</div>
                <p class="text-[11px] text-slate-400 mt-3"><i class="fas fa-circle-info ml-1"></i>درگاهِ آتی فقط مسیرِ سامانه‌های بیمه ایران را از تونل می‌برد؛ بقیه‌ی سایت‌ها با اینترنتِ عادی باز می‌شوند (اشکالِ «قطع شدنِ کلِ اینترنت» برطرف شده؛ نصاب را یک بار دوباره اجرا کنید).</p></div>`;
    }

    // «host=ip1,ip2 ; host2=…» => برچسب‌های خوانا
    const routesHtml = rt => !rt ? '' : `<div class="text-[10px] text-slate-500 mt-1" dir="ltr" style="text-align:right">${String(rt).split(' ; ').map(x => { const [h, ips] = x.split('='); return `<span class="ext-chip" style="background:${ips ? '#ecfdf5;color:#065f46' : '#fef2f2;color:#991b1b'};margin:1px">${esc(h)} ← ${esc(ips || 'پیدا نشد')}</span>`; }).join('')}</div>`;
    // ---------- کاربران ----------
    const ROLE = {ADMIN: 'مدیر کل', CEO: 'مدیر عامل', OPERATOR: 'کارشناس صدور', FINANCE: 'کارشناس مالی', COMPANY_LIAISON: 'کارمند بیمه با ما', REQUEST_UNIT: 'واحد درخواست', PARSIAN: 'پارسیان', LIFE: 'بیمه عمر'};
    const UF = {q: '', role: '', st: ''};
    async function viewUsers(b) {
        const r = await post({action: 'users'}); if (!r.ok) { b.innerHTML = `<div class="ext-card text-rose-600">${esc(r.error)}</div>`; return; }
        S.data.users = r;
        const ed = S.info.can.edit, U = r.users;
        const roles = [...new Set(U.map(u => u.role))];
        const has = u => Object.keys(MOD).some(k => u.acc[k] && u.acc[k].username);
        const ST = {'': 'همه', any: 'نام‌کاربری دارد', none: 'بدونِ نام‌کاربری', inst: 'اقساط دارد', ati: 'درگاهِ آتی دارد', blocked: 'مسدود', online: 'آنلاین', dev: 'افزونه نصب کرده'};
        const norm = t => en(String(t || '')).toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/g, '');
        b.innerHTML = `<div class="ext-card"><div class="flex flex-wrap items-center gap-2 mb-2"><b class="text-[13px] text-slate-800">نام‌کاربریِ افزونه برای هر نفر</b>
                <span class="ext-chip" style="background:#eef2ff;color:#3730a3" data-cnt></span><span class="flex-1"></span>
                <input class="ext-in" data-q placeholder="جستجو: نام، نام‌کاربریِ پنل یا افزونه، نقش…" style="min-width:240px" value="${esc(UF.q)}">
                <select class="ext-in" data-role><option value="">همه‌ی نقش‌ها</option>${roles.map(x => `<option value="${esc(x)}" ${UF.role === x ? 'selected' : ''}>${esc(ROLE[x] || x)}</option>`).join('')}</select>
                <select class="ext-in" data-st>${Object.entries(ST).map(([k, t]) => `<option value="${k}" ${UF.st === k ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
            ${(() => { const sh = U.filter(u => u.online && u.tunnel && !(u.acc.ati && u.acc.ati.vpn_user)); return sh.length >= 2 ? `<div class="mb-2 rounded-xl p-2 text-[11.5px]" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e"><i class="fas fa-triangle-exclamation ml-1"></i>${fa(sh.length)} نفر (${esc(sh.map(u => u.name).join('، '))}) هم‌زمان با حسابِ VPNِ مشترکِ تنظیمات وصل‌اند. اگر سرورِ VPN برای هر حساب فقط یک اتصال بدهد هم‌دیگر را قطع می‌کنند؛ برای هر نفر «VPNِ اختصاصی» تعریف کنید.</div>` : ''; })()}
            <p class="text-[11px] text-slate-500 mb-2">همه‌ی کاربرانِ پنل (همه‌ی نقش‌ها). بدونِ نام‌کاربری، آن بخش در افزونه برای آن نفر بسته است. «مسدود» یعنی نام‌کاربری می‌ماند ولی کار نمی‌کند. همین را در «دسترسی‌ها»ی هر کاربر هم می‌توانید تعیین کنید.</p>
            <div style="max-height:65vh;overflow:auto"><table class="ext-tbl no-count no-page"><thead><tr><th>کاربر</th>${Object.entries(MOD).map(([k, [t]]) => `<th>${t}</th>`).join('')}<th>دستگاه / آخرین حضور</th>${ed ? '<th></th>' : ''}</tr></thead><tbody>
            ${U.map(u => `<tr data-u="${u.id}"><td><b>${esc(u.name)}</b><div class="text-[10px] text-slate-400">${esc(ROLE[u.role] || u.role)}${u.login ? ` · <span dir="ltr">${esc(u.login)}</span>` : ''}</div></td>
                ${Object.keys(MOD).map(k => { const a = u.acc[k] || {}; return `<td><input class="ext-in" dir="ltr" style="width:130px" data-m="${k}" value="${esc(a.username || '')}" ${ed ? '' : 'disabled'} placeholder="—" autocomplete="off">
                    <label class="text-[10.5px] inline-flex items-center gap-1 mr-1"><input type="checkbox" data-b="${k}" ${a.blocked ? 'checked' : ''} ${ed ? '' : 'disabled'}>مسدود</label>
                    ${k === 'ati' ? `<details class="mt-1" ${a.vpn_user ? 'open' : ''}><summary class="text-[10.5px] text-emerald-700 cursor-pointer">VPNِ اختصاصی ${a.vpn_user ? '<span class="ext-chip" style="background:#ecfdf5;color:#065f46">دارد</span>' : '<span class="text-slate-400">(خالی = حسابِ مشترکِ تنظیمات)</span>'}</summary>
                        <div class="flex gap-1 mt-1"><input class="ext-in" dir="ltr" style="width:110px" data-vu value="${esc(a.vpn_user || '')}" placeholder="نام‌کاربریِ VPN" autocomplete="off" name="bb-vu-${u.id}" ${ed ? '' : 'disabled'}>
                        <input class="ext-in" dir="ltr" type="password" style="width:100px" data-vp value="${esc(a.vpn_pass || '')}" placeholder="رمز" autocomplete="new-password" name="bb-vp-${u.id}" ${ed ? '' : 'disabled'}></div></details>` : ''}</td>`; }).join('')}
                <td class="text-[11px]">${u.devices ? `${u.online ? '<span class="ext-chip" style="background:#dcfce7;color:#166534">● آنلاین</span>' : ''} ${fa(u.devices)} دستگاه · ${esc(fa(u.seen))}${u.version ? ' · v' + esc(fa(u.version)) : ''}${u.tunnel ? ' · <span class="text-emerald-600">درگاه وصل</span>' : ''}${u.bridge_ver ? ' · برنامه‌ی زمینه v' + esc(fa(u.bridge_ver)) : ''}${u.bridge_admin === false ? ' <span class="ext-chip" style="background:#fef2f2;color:#991b1b" title="مسیرها و DNS تنظیم نمی‌شوند؛ کاربرِ ویندوز Administrator نیست">بدونِ دسترسیِ مدیرِ ویندوز</span>' : ''}${routesHtml(u.routes)}` : '<span class="text-slate-300">—</span>'}</td>
                ${ed ? '<td><button class="ext-btn bg-emerald-600 text-white" style="padding:5px 10px" data-sv><i class="fas fa-save"></i>ذخیره</button></td>' : ''}</tr>`).join('')}
            <tr data-empty style="display:none"><td colspan="5" class="text-center text-slate-400 py-6">کاربری با این جستجو/فیلتر نیست.</td></tr></tbody></table></div></div>`;
        const byId = {}; U.forEach(u => { byId[u.id] = u; });
        const apply = () => {
            const q = norm(UF.q); let n = 0;
            b.querySelectorAll('tr[data-u]').forEach(tr => {
                const u = byId[tr.dataset.u], A = Object.values(u.acc || {});
                const hay = norm([u.name, u.login, ROLE[u.role], u.role, ...A.map(a => a.username)].join(' '));
                let ok = !q || hay.includes(q);
                if (ok && UF.role) ok = u.role === UF.role;
                if (ok && UF.st) ok = {any: has(u), none: !has(u), inst: !!(u.acc.inst && u.acc.inst.username), ati: !!(u.acc.ati && u.acc.ati.username), blocked: A.some(a => a.blocked), online: !!u.online, dev: u.devices > 0}[UF.st];
                tr.style.display = ok ? '' : 'none'; if (ok) n++;
            });
            b.querySelector('[data-cnt]').textContent = fa(n) + ' از ' + fa(U.length) + ' کاربر';
            b.querySelector('[data-empty]').style.display = n ? 'none' : '';
        };
        b.querySelector('[data-q]').oninput = e => { UF.q = e.target.value.trim(); apply(); };
        b.querySelector('[data-role]').onchange = e => { UF.role = e.target.value; apply(); };
        b.querySelector('[data-st]').onchange = e => { UF.st = e.target.value; apply(); };
        apply();
        b.querySelectorAll('[data-sv]').forEach(btn => btn.onclick = async () => {
            const tr = btn.closest('tr'), body = {action: 'account_save', user_id: +tr.dataset.u};
            Object.keys(MOD).forEach(k => { body[k] = {username: en(tr.querySelector(`[data-m="${k}"]`).value.trim()), blocked: tr.querySelector(`[data-b="${k}"]`).checked ? 1 : 0}; });
            const vu = tr.querySelector('[data-vu]'), vp = tr.querySelector('[data-vp]');
            if (vu && body.ati) { body.ati.vpn_user = vu.value.trim(); body.ati.vpn_pass = vp.value; }
            const r2 = await post(body); toast(r2.ok ? 'ذخیره شد.' : r2.error, r2.ok ? 'success' : 'error');
            if (r2.ok) { const u = byId[tr.dataset.u]; Object.keys(MOD).forEach(k => { u.acc[k] = Object.assign({}, u.acc[k] || {}, {username: body[k].username, blocked: !!body[k].blocked}, k === 'ati' && vu ? {vpn_user: body.ati.vpn_user} : {}); }); apply(); }
        });
    }
    // ---------- دستگاه‌ها ----------
    async function viewDevices(b) {
        const r = await post({action: 'devices'}); if (!r.ok) { b.innerHTML = `<div class="ext-card text-rose-600">${esc(r.error)}</div>`; return; }
        const ed = S.info.can.edit;
        b.innerHTML = `<div class="ext-card"><b class="text-[13px] text-slate-800">دستگاه‌ها (هر نصب/ورودِ افزونه)</b><div class="mt-2" style="max-height:65vh;overflow:auto"><table class="ext-tbl"><thead><tr><th>کاربر</th><th>نام‌کاربری</th><th>نسخه</th><th>IP</th><th>درگاهِ آتی</th><th>آخرین حضور</th><th>ورود</th><th></th></tr></thead><tbody>
            ${r.devices.map(d => `<tr style="${d.revoked ? 'opacity:.45' : ''}"><td>${esc(d.user || '—')} ${d.online ? '<span class="ext-chip" style="background:#dcfce7;color:#166534">● آنلاین</span>' : ''}</td><td dir="ltr">${esc(d.login || '')}</td><td>${esc(fa(d.version || ''))}</td><td dir="ltr">${esc(d.ip || '')}</td>
                <td>${d.bridge ? (d.tunnel ? '<span class="text-emerald-600 font-bold">وصل</span>' + (d.vpn_ip ? ' <span dir="ltr" class="text-slate-400">' + esc(d.vpn_ip) + '</span>' : '') : 'سرویس آماده') : '<span class="text-slate-300">—</span>'}${d.bridge_ver ? `<div class="text-[10px] text-slate-400">برنامه‌ی زمینه v${esc(fa(d.bridge_ver))}${d.bridge_admin === false ? ' · <span class="text-rose-600">بدونِ دسترسیِ مدیرِ ویندوز</span>' : ''}</div>` : ''}${routesHtml(d.routes)}</td>
                <td>${esc(fa(d.seen))}</td><td>${esc(fa(d.at))}</td><td>${d.revoked ? 'بیرون شده' : ed ? `<button class="text-rose-600 text-[11px]" data-rv="${d.id}">بیرون کردن</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="8" class="text-center text-slate-400 py-6">هنوز دستگاهی وارد نشده.</td></tr>'}</tbody></table></div></div>`;
        b.querySelectorAll('[data-rv]').forEach(x => x.onclick = async () => { if (!confirm('این دستگاه از افزونه بیرون شود؟ (باید دوباره وارد شود)')) return; const r2 = await post({action: 'device_revoke', id: +x.dataset.rv}); if (r2.ok) viewDevices(b); });
    }
    // ---------- فعالیت‌ها ----------
    async function viewLogs(b) {
        const users = S.data.users || await post({action: 'users'}); S.data.users = users;
        const f = S.logF;
        b.innerHTML = `<div class="ext-card"><div class="flex flex-wrap items-center gap-2 mb-3">
                <select class="ext-in" data-f="user_id"><option value="">همه‌ی کاربران</option>${(users.users || []).filter(u => u.devices || Object.keys(u.acc || {}).length).map(u => `<option value="${u.id}" ${+f.user_id === u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
                <select class="ext-in" data-f="module"><option value="">همه‌ی بخش‌ها</option>${Object.entries(MOD).map(([k, [t]]) => `<option value="${k}" ${f.module === k ? 'selected' : ''}>${t}</option>`).join('')}</select>
                <input class="ext-in" data-f="from" data-jdate placeholder="از تاریخ" style="width:105px" value="${esc(f.from || '')}"><input class="ext-in" data-f="to" data-jdate placeholder="تا تاریخ" style="width:105px" value="${esc(f.to || '')}">
                <input class="ext-in" data-f="q" placeholder="جستجو: پیش‌نویس، قرارداد، سامانه…" style="min-width:200px" value="${esc(f.q || '')}">
                <button class="ext-btn bg-sky-600 text-white" data-go><i class="fas fa-filter"></i>نمایش</button>
                <button class="ext-btn bg-emerald-50 text-emerald-700 border border-emerald-200" data-x><i class="fas fa-file-excel"></i>اکسل</button></div><div data-list class="text-center text-slate-400 py-6"><i class="fas fa-spinner fa-spin"></i></div></div>`;
        const read = () => { b.querySelectorAll('[data-f]').forEach(i => { f[i.dataset.f] = en(i.value.trim()); }); };
        b.querySelector('[data-go]').onclick = () => { read(); f.page = 0; load(); };
        b.querySelector('[data-x]').onclick = () => { read(); window.open(API + '?' + new URLSearchParams(Object.assign({action: 'logs_export'}, f)), '_blank'); };
        async function load() {
            const r = await post(Object.assign({action: 'logs'}, f));
            const L = b.querySelector('[data-list]');
            if (!r.ok) { L.innerHTML = `<p class="text-rose-600">${esc(r.error)}</p>`; return; }
            L.className = '';
            L.innerHTML = `<div style="max-height:62vh;overflow:auto"><table class="ext-tbl"><thead><tr><th>زمان</th><th>کاربر</th><th>بخش</th><th>کار</th><th>جزئیات</th><th>IP / نسخه</th></tr></thead><tbody>
                ${r.logs.map(l => `<tr><td class="whitespace-nowrap">${esc(fa(l.at))}</td><td>${esc(l.user)}${l.login ? `<div class="text-[10px] text-slate-400" dir="ltr">${esc(l.login)}</div>` : ''}</td>
                    <td>${l.module ? `<span class="ext-chip" style="background:${MOD[l.module][1]}1a;color:${MOD[l.module][1]}">${MOD[l.module][0]}</span>` : '<span class="text-slate-300">—</span>'}</td>
                    <td class="font-bold">${esc(l.event)}</td><td class="text-[11px] text-slate-600">${esc(fa(l.detail))}</td><td class="text-[10.5px] text-slate-400" dir="ltr">${esc(l.ip || '')}${l.version ? ' · v' + esc(l.version) : ''}</td></tr>`).join('') || '<tr><td colspan="6" class="text-center text-slate-400 py-6">فعالیتی نیست.</td></tr>'}</tbody></table></div>
                <div class="flex gap-2 mt-2">${f.page ? '<button class="ext-btn bg-slate-100" data-pg="-1">قبلی</button>' : ''}${r.more ? '<button class="ext-btn bg-slate-100" data-pg="1">بعدی</button>' : ''}</div>`;
            L.querySelectorAll('[data-pg]').forEach(x => x.onclick = () => { f.page += +x.dataset.pg; load(); });
        }
        load();
    }
    // ---------- اعلان‌ها ----------
    async function viewNotifs(b) {
        const r = await post({action: 'notifs'}); const users = S.data.users || await post({action: 'users'}); S.data.users = users;
        const ed = S.info.can.edit;
        b.innerHTML = `${ed ? `<div class="ext-card mb-3"><b class="text-[13px] text-slate-800">اعلانِ تازه (داخلِ افزونه نشان داده می‌شود)</b><div class="grid md:grid-cols-3 gap-2 mt-2">
                <input class="ext-in" data-n="title" placeholder="عنوان"><select class="ext-in" data-n="module"><option value="all">همه‌ی بخش‌ها</option>${Object.entries(MOD).map(([k, [t]]) => `<option value="${k}">${t}</option>`).join('')}</select>
                <select class="ext-in" data-n="user_id"><option value="">همه‌ی کاربران</option>${(users.users || []).filter(u => Object.keys(u.acc || {}).length).map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select></div>
                <textarea class="ext-in w-full mt-2" rows="3" data-n="message" placeholder="متنِ اعلان"></textarea><button class="ext-btn bg-sky-600 text-white mt-2" data-send><i class="fas fa-paper-plane"></i>فرستادن</button></div>` : ''}
            <div class="ext-card"><table class="ext-tbl"><thead><tr><th>عنوان</th><th>متن</th><th>بخش</th><th>برای</th><th>زمان</th><th></th></tr></thead><tbody>
            ${(r.notifs || []).map(n => `<tr><td class="font-bold">${esc(n.title)}</td><td class="text-[11px]">${esc(n.message || '')}</td><td>${n.module === 'all' ? 'همه' : MOD[n.module] ? MOD[n.module][0] : ''}</td><td>${esc(n.full_name || 'همه')}</td><td dir="ltr" class="text-[10.5px]">${esc(n.created_at)}</td><td>${ed ? `<button class="text-rose-600 text-[11px]" data-del="${n.id}">حذف</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="6" class="text-center text-slate-400 py-6">اعلانی نیست.</td></tr>'}</tbody></table></div>`;
        const s = b.querySelector('[data-send]');
        if (s) s.onclick = async () => { const o = {action: 'notif_save'}; b.querySelectorAll('[data-n]').forEach(i => { o[i.dataset.n] = i.value; }); const r2 = await post(o); toast(r2.ok ? 'فرستاده شد.' : r2.error, r2.ok ? 'success' : 'error'); if (r2.ok) viewNotifs(b); };
        b.querySelectorAll('[data-del]').forEach(x => x.onclick = async () => { await post({action: 'notif_delete', id: +x.dataset.del}); viewNotifs(b); });
    }
    // ---------- تنظیمات ----------
    async function viewSettings(b) {
        const r = await post({action: 'settings_get'}); if (!r.ok) { b.innerHTML = `<div class="ext-card text-rose-600">${esc(r.error)}</div>`; return; }
        const s = r.settings, dis = r.can_edit ? '' : 'disabled';
        const siteRow = x => `<div class="flex flex-wrap gap-2 items-center mb-2" data-site><input class="ext-in" data-k="title" placeholder="عنوان" value="${esc(x.title || '')}" style="min-width:180px" ${dis}><input class="ext-in" dir="ltr" data-k="url" placeholder="http://sales.iic.local/..." value="${esc(x.url || '')}" style="min-width:260px;flex:1" ${dis}>
            <input type="color" data-k="color" value="${esc(x.color || '#0284c7')}" ${dis}><input type="hidden" data-k="id" value="${esc(x.id || '')}">${r.can_edit ? '<button class="text-rose-600 text-[11px]" data-rm>حذف</button>' : ''}</div>`;
        b.innerHTML = `<div class="grid lg:grid-cols-2 gap-3">
            <div class="ext-card"><b class="text-[13px]" style="color:#059669"><i class="fas fa-shield-halved ml-1"></i>درگاهِ آتیِ بیمه ایران</b>
                <div class="grid grid-cols-2 gap-2 mt-3 text-[11.5px]">
                    <label>نامِ اتصالِ ویندوز (VPN)<input class="ext-in w-full" dir="ltr" data-a="conn" value="${esc(s.ati.conn)}" ${dis}></label>
                    <label>نامِ کاربریِ اتصال<input class="ext-in w-full" dir="ltr" data-a="vpn_user" value="${esc(s.ati.vpn_user)}" ${dis} autocomplete="off" name="bb-vpn-user"></label>
                    <label>رمزِ اتصال<input class="ext-in w-full" dir="ltr" type="password" data-a="vpn_pass" value="${esc(s.ati.vpn_pass)}" ${dis} autocomplete="new-password" name="bb-vpn-pass"></label>
                    <label>مسیرهایی که از تونل می‌روند<textarea class="ext-in w-full" dir="ltr" rows="3" data-a="routes" ${dis}>${esc((s.ati.routes || []).join('\n'))}</textarea></label></div>
                <p class="text-[10.5px] text-slate-400 mt-1">فقط همین مسیرها (و شبکه‌ی سامانه‌های پایین، و DNSِ ‎.iic.local) از تونل می‌روند؛ بقیه‌ی اینترنت عادی می‌ماند. هر خط یک مسیر، مثلِ <span dir="ltr">10.0.0.0/8</span>.</p>
                ${r.can_edit ? `<div class="mt-2 rounded-xl border border-emerald-100 bg-emerald-50/40 p-2">
                    <label class="block text-[11.5px] font-bold text-slate-700">افزودنِ مسیر از روی نشانیِ سامانه</label>
                    <div class="flex flex-wrap gap-2 mt-1"><input class="ext-in" dir="ltr" style="flex:1;min-width:200px" data-rh placeholder="مثلاً sales.iic.local یا http://hullissu.iic.local/…">
                        <button class="ext-btn text-white" style="background:#059669" data-rhb><i class="fas fa-route"></i>بررسی و افزودن</button></div>
                    <div class="text-[11px] mt-1" data-rhm></div>
                    <p class="text-[10px] text-slate-400 mt-1">نشانیِ داخلیِ بیمه ایران فقط از راهِ VPN پیدا می‌شود: همین صفحه را روی سیستمی باز کنید که افزونه نصب و درگاهِ آتی روشن است. شبکه‌ی هر نشانی (/24) اگر در فهرست نباشد اضافه می‌شود؛ بعد «ذخیره» را بزنید.</p></div>` : ''}
                <b class="block text-[12px] mt-3 mb-2">سامانه‌ها (دکمه‌های داخلِ افزونه)</b><div data-sites>${(s.ati.sites || []).map(siteRow).join('')}</div>
                ${r.can_edit ? '<button class="ext-btn bg-slate-100 text-slate-700" data-addsite><i class="fas fa-plus"></i>سامانه‌ی تازه</button>' : ''}</div>
            <div class="ext-card"><b class="text-[13px]" style="color:#0284c7"><i class="fas fa-money-check-dollar ml-1"></i>اقساطِ پاسارگاد</b>
                <p class="text-[11px] text-slate-500 mt-2">قراردادها همان «مالی ← قراردادها»ی سایت است. اگر پیش‌نویس به ردیفِ درخواستی وصل باشد، قراردادِ همان شرکت خودکار انتخاب می‌شود؛ وگرنه قراردادِ پیش‌فرضِ ثالث/بدنه («سایتِ بیمه‌گر ← مالی و قراردادها»).</p>
                <label class="block text-[11.5px] mt-2">حسابِ بانکیِ پیش‌فرض (شماره‌ی حسابِ بیمه در سامانه)<input class="ext-in w-full" dir="ltr" data-i="default_account" value="${esc(fa(s.inst.default_account))}" ${dis}></label>
                <div style="max-height:44vh;overflow:auto" class="mt-2"><table class="ext-tbl"><thead><tr><th>در افزونه</th><th>قرارداد</th><th>حسابِ بانکی</th><th>مبنای تاریخ</th></tr></thead><tbody>
                ${(r.fin_contracts || []).map(c => `<tr style="${+c.is_active ? '' : 'opacity:.45'}"><td><input type="checkbox" data-c="${c.id}" ${!s.inst.contracts.length || s.inst.contracts.includes(+c.id) ? 'checked' : ''} ${dis}></td>
                    <td>${esc(c.name)}${c.contract_no ? ' <span class="text-slate-400">(' + esc(fa(c.contract_no)) + ')</span>' : ''}<div class="text-[10px] text-slate-400">${c.pay_type === 'CASH' ? 'نقد' : fa(c.installment_count) + ' قسط'}</div></td>
                    <td><input class="ext-in" dir="ltr" style="width:120px" data-acc="${c.id}" value="${esc(fa(s.inst.accounts[c.id] || ''))}" placeholder="پیش‌فرض" ${dis}></td>
                    <td><select class="ext-in" data-db="${c.id}" ${dis}>${[['issue', 'تاریخِ صدور'], ['today', 'تاریخِ امروز'], ['both', 'انتخاب با کاربر']].map(([v, t]) => `<option value="${v}" ${(s.inst.date_basis[c.id] || 'issue') === v ? 'selected' : ''}>${t}</option>`).join('')}</select></td></tr>`).join('')}</tbody></table></div></div>
            <div class="ext-card lg:col-span-2"><label class="block text-[12px] font-bold">توضیحِ تغییراتِ نسخه (در افزونه و صفحه‌ی دانلود نشان داده می‌شود)<textarea class="ext-in w-full mt-1" rows="2" data-cl ${dis}>${esc(s.changelog)}</textarea></label>
                ${r.can_edit ? '<button class="ext-btn bg-emerald-600 text-white mt-2" data-save><i class="fas fa-save"></i>ذخیره‌ی تنظیمات</button>' : '<p class="text-[11px] text-slate-400">تغییر فقط با دسترسیِ «ویرایش».</p>'}</div></div>`;
        const ad = b.querySelector('[data-addsite]'); if (ad) ad.onclick = () => { b.querySelector('[data-sites]').insertAdjacentHTML('beforeend', siteRow({})); bindRm(); };
        const rhb = b.querySelector('[data-rhb]'); if (rhb) rhb.onclick = () => addRoutesFromHost(b);
        const bindRm = () => b.querySelectorAll('[data-rm]').forEach(x => x.onclick = () => x.closest('[data-site]').remove());
        bindRm();
        const sv = b.querySelector('[data-save]');
        if (sv) sv.onclick = async () => {
            const ati = {}; b.querySelectorAll('[data-a]').forEach(i => { ati[i.dataset.a] = i.value; });
            ati.routes = ati.routes.split(/\s+/).filter(Boolean);
            ati.sites = [...b.querySelectorAll('[data-site]')].map(row => { const o = {}; row.querySelectorAll('[data-k]').forEach(i => { o[i.dataset.k] = i.value.trim(); }); return o; }).filter(o => o.url);
            const all = [...b.querySelectorAll('[data-c]')];
            const inst = {default_account: en(b.querySelector('[data-i="default_account"]').value), accounts: {}, date_basis: {},
                          contracts: all.every(c => c.checked) ? [] : all.filter(c => c.checked).map(c => +c.dataset.c)};
            b.querySelectorAll('[data-acc]').forEach(i => { inst.accounts[i.dataset.acc] = en(i.value); });
            b.querySelectorAll('[data-db]').forEach(i => { inst.date_basis[i.dataset.db] = i.value; });
            const r2 = await post({action: 'settings_save', ati, inst, changelog: b.querySelector('[data-cl]').value});
            toast(r2.ok ? 'تنظیماتِ افزونه ذخیره شد؛ افزونه‌ها در همگام‌سازیِ بعدی (حداکثر یک دقیقه) می‌گیرند.' : r2.error, r2.ok ? 'success' : 'error');
        };
    }

    // ---------- مسیر از روی نشانی ----------
    const ip2n = ip => ip.split('.').reduce((a, x) => a * 256 + (+x), 0);
    function cidrHas(cidr, ip) { const m = /^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/.exec(cidr.trim()); if (!m) return false; const bits = +m[2], size = Math.pow(2, 32 - bits); const base = Math.floor(ip2n(m[1]) / size) * size; const v = ip2n(ip); return v >= base && v < base + size; }
    function hostOf(v) { v = String(v || '').trim().toLowerCase(); try { if (/^[a-z]+:\/\//.test(v)) v = new URL(v).hostname; } catch (e) {} return v.replace(/[\/:].*$/, ''); }
    // IPها: اول از راهِ افزونه/برنامه‌ی زمینه‌ی همین سیستم (DNSِ VPN)، وگرنه از سرور (فقط نشانیِ عمومی)
    function extResolve(host) {
        return new Promise(res => {
            const id = S.info && S.info.ext_id;
            if (!id || !window.chrome || !chrome.runtime || !chrome.runtime.sendMessage) return res(null);
            const t = setTimeout(() => res(null), 35000);
            try { chrome.runtime.sendMessage(id, {action: 'resolve', hosts: [host]}, r => { clearTimeout(t); if (chrome.runtime.lastError) return res(null); res(r || null); }); } catch (e) { clearTimeout(t); res(null); }
        });
    }
    async function addRoutesFromHost(b) {
        const inp = b.querySelector('[data-rh]'), msg = b.querySelector('[data-rhm]'), ta = b.querySelector('[data-a="routes"]');
        const host = hostOf(inp.value);
        if (!/^[a-z0-9.-]{3,120}$/.test(host)) { msg.innerHTML = '<span class="text-rose-600">نشانی را درست وارد کنید.</span>'; return; }
        msg.innerHTML = '<span class="text-slate-500"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ پیدا کردنِ IPهای ' + esc(host) + '…</span>';
        let ips = [], via = '', note = '';
        const e = await extResolve(host);
        if (e && e.ok) { const row = (e.resolved || []).find(x => x.split('=')[0] === host); ips = row ? row.split('=')[1].split(',').filter(Boolean) : []; via = e.viaVpn ? 'از DNSِ VPN (افزونه‌ی همین سیستم)' : 'از DNSِ همین سیستم (درگاه خاموش است)'; }
        else if (e && e.error === 'bridge_old') note = 'برنامه‌ی زمینه‌ی این سیستم قدیمی است (از افزونه بروزرسانی‌اش کنید). ';
        else if (e && e.error === 'bridge_offline') note = 'برنامه‌ی زمینه‌ی این سیستم اجرا نیست. ';
        if (!ips.length) { const r = await post({action: 'resolve_host', host}); if (r.ok && r.ips && r.ips.length) { ips = r.ips; via = 'از سرورِ پنل (نشانیِ عمومی)'; } }
        if (!ips.length) { msg.innerHTML = `<span class="text-rose-600">${esc(note)}IPی برای ${esc(host)} پیدا نشد. ${e ? 'درگاهِ آتی را روشن کنید و دوباره بزنید.' : 'این صفحه را روی سیستمی باز کنید که افزونه نصب و درگاهِ آتی روشن است.'}</span>`; return; }
        const cur = ta.value.split(/\n+/).map(x => x.trim()).filter(Boolean);
        const added = [], had = [];
        ips.forEach(ip => {
            if (cur.concat(added).some(c => cidrHas(c, ip))) { had.push(ip); return; }
            added.push(ip.replace(/\.\d+$/, '.0') + '/24');
        });
        if (added.length) ta.value = cur.concat(added).join('\n');
        msg.innerHTML = `<span class="text-slate-600">${esc(host)} ← <b dir="ltr">${esc(ips.join('، '))}</b> <span class="text-slate-400">(${esc(via)})</span></span><br>` +
            (added.length ? `<span class="text-emerald-700">اضافه شد: <b dir="ltr">${esc(added.join('، '))}</b> — «ذخیره‌ی تنظیمات» را بزنید.</span>` : '<span class="text-emerald-700">همه‌ی این IPها از قبل در فهرستِ مسیرها هستند.</span>') +
            (had.length && added.length ? `<span class="text-slate-400"> (از قبل بود: ${esc(had.join('، '))})</span>` : '');
    }

    // ---------- بخشِ «افزونه‌ها» در پنجره‌ی دسترسی‌های هر کاربر ----------
    let PE = null;
    async function permSection(box, userId) {
        PE = null; if (!box) return;
        const r = await post({action: 'account_get', user_id: userId});
        if (!r.ok) { box.innerHTML = ''; return; }
        PE = {userId, box, orig: {}, ro: !r.can_edit};
        Object.keys(MOD).forEach(k => { const a = (r.acc || {})[k] || {}; PE.orig[k] = (a.username || '') + '|' + (a.blocked ? 1 : 0); });
        box.innerHTML = `<div class="pm-grp"><h4><span class="pm-ic" style="--a:#1e3a8a;--b:#0369a1"><i class="fas fa-puzzle-piece"></i></span>افزونه‌ی دستیارِ بیمه باما <small class="text-[10px] text-slate-400 font-bold mr-1">بدونِ نام‌کاربری، آن بخش برای این کاربر بسته است</small></h4>
            <div class="grid md:grid-cols-2 gap-2 p-2">${Object.entries(MOD).map(([k, [t, c]]) => { const a = (r.acc || {})[k] || {}; return `<div class="rounded-xl border border-slate-200 p-2"><b class="text-[12px]" style="color:${c}">${t}</b>
                <div class="flex items-center gap-2 mt-1"><input class="ext-in" dir="ltr" style="flex:1" data-pe="${k}" value="${esc(a.username || '')}" placeholder="نام‌کاربری (خالی = بدونِ دسترسی)" ${PE.ro ? 'disabled' : ''}>
                <label class="text-[11px] inline-flex items-center gap-1"><input type="checkbox" data-peb="${k}" ${a.blocked ? 'checked' : ''} ${PE.ro ? 'disabled' : ''}>مسدود</label></div></div>`; }).join('')}</div></div>`;
    }
    async function permSave() {
        if (!PE || PE.ro || !PE.box.isConnected) return true;
        const body = {action: 'account_save', user_id: PE.userId}; let n = 0;
        Object.keys(MOD).forEach(k => {
            const i = PE.box.querySelector(`[data-pe="${k}"]`); if (!i) return;
            const v = {username: en(i.value.trim()), blocked: PE.box.querySelector(`[data-peb="${k}"]`).checked ? 1 : 0};
            if (v.username + '|' + v.blocked !== PE.orig[k]) { body[k] = v; n++; }   // فقط بخش‌هایی که عوض شده‌اند
        });
        if (!n) return true;
        const r = await post(body);
        if (!r.ok) { toast('افزونه: ' + (r.error || 'ذخیره نشد'), 'error'); return false; }
        return true;
    }
    window.ExtUI = {init, permSection, permSave};
})();
