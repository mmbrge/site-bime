// فایل: chat-access.js
// «گفتگوها ← دسترسیِ گفتگو» (فقط مدیر کل): هر همکار با کدام همکاران و کدام شرکت‌ها گفتگو کند.
// همکاران: با همه / فقط افرادِ انتخابی / همه به‌جز انتخابی‌ها - گفتگو فقط وقتی باز است که قانونِ هر دو طرف اجازه بدهد.
// شرکت‌ها: طبقِ نقش / همه / هیچ / فقط شرکت‌های انتخابی. مدیر کل همیشه با همه گفتگو می‌کند. (api/chat_access_actions.php)
(function () {
    'use strict';
    const API = 'api/chat_access_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const norm = s => String(s || '').replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, ' ').toLowerCase();
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : alert(m));
    async function api(action, body = {}) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور'}; }
    }
    const S = {d: null, sel: 0, draft: null, q: '', pq: '', cq: '', dirty: false};
    const CSS = `
    .ca-hero{border-radius:24px;padding:20px 22px;color:#fff;position:relative;overflow:hidden;background:radial-gradient(circle at 10% 120%,rgba(52,211,153,.45),transparent 50%),linear-gradient(125deg,#064e3b,#0f766e 55%,#0e7490)}
    .ca-hero::after{content:'';position:absolute;width:260px;height:260px;border-radius:50%;left:-70px;top:-140px;background:radial-gradient(circle,rgba(255,255,255,.22),transparent 70%);pointer-events:none}
    .ca-stat{border-radius:16px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);padding:8px 14px;text-align:center;min-width:92px}
    .ca-stat b{display:block;font-size:20px;font-weight:900}.ca-stat small{font-size:10.5px;opacity:.85;font-weight:700}
    .ca-grid{display:grid;grid-template-columns:330px 1fr;gap:14px;align-items:start}
    @media (max-width:900px){.ca-grid{grid-template-columns:1fr}}
    .ca-list{max-height:640px;overflow-y:auto}
    .ca-u{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:14px;cursor:pointer;border:1px solid transparent;transition:.15s}
    .ca-u:hover{background:#f8fafc}.ca-u.on{background:#ecfdf5;border-color:#6ee7b7}
    .ca-av{width:36px;height:36px;border-radius:12px;flex:none;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:13px;color:#fff;background:linear-gradient(135deg,#10b981,#0e7490)}
    .ca-av.ad{background:linear-gradient(135deg,#f59e0b,#d97706)}
    .ca-chip{display:inline-flex;align-items:center;gap:4px;font-size:9.5px;font-weight:900;border-radius:999px;padding:2px 8px;background:#f1f5f9;color:#475569;white-space:nowrap}
    .ca-chip.g{background:#dcfce7;color:#15803d}.ca-chip.r{background:#ffe4e6;color:#be123c}.ca-chip.b{background:#e0f2fe;color:#0369a1}.ca-chip.a{background:#fef3c7;color:#b45309}
    .ca-seg{display:flex;flex-wrap:wrap;gap:6px}
    .ca-seg button{flex:1 1 150px;text-align:right;border:1.5px solid #e2e8f0;border-radius:14px;padding:9px 12px;background:#fff;transition:.15s}
    .ca-seg button b{display:block;font-size:12px;font-weight:900;color:#0f172a}.ca-seg button small{font-size:10px;color:#64748b;font-weight:700}
    .ca-seg button.on{border-color:#10b981;background:#ecfdf5;box-shadow:0 0 0 3px rgba(16,185,129,.12)}
    .ca-pick{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px;max-height:300px;overflow-y:auto;padding:2px}
    .ca-pick label{display:flex;align-items:center;gap:8px;border:1px solid #e2e8f0;border-radius:12px;padding:7px 10px;font-size:11.5px;font-weight:800;color:#334155;background:#fff;cursor:pointer}
    .ca-pick label.on{border-color:#34d399;background:#f0fdf4}.ca-pick label.lock{opacity:.6;cursor:not-allowed;background:#fffbeb;border-color:#fde68a}
    .ca-pick input{width:16px;height:16px;accent-color:#059669}
    .ca-res{border-radius:16px;padding:12px 14px;background:linear-gradient(135deg,#f0fdfa,#f8fafc);border:1px dashed #99f6e4}
    `;
    function css() { if (!document.getElementById('ca-css')) { const s = document.createElement('style'); s.id = 'ca-css'; s.textContent = CSS; document.head.appendChild(s); } }

    const allows = (rule, id) => rule.mode === 'ONLY' ? rule.ids.includes(id) : rule.mode === 'EXCEPT' ? !rule.ids.includes(id) : true;
    const user = id => S.d.users.find(u => u.id === id);
    // گفتگوی دو نفر باز است؟ (draft برای کاربرِ در حالِ ویرایش)
    function pairOk(a, b) {
        if (a === b) return false;
        const ua = user(a), ub = user(b);
        if (!ua || !ub) return true;
        if (ua.role === 'ADMIN' || ub.role === 'ADMIN') return true;
        const ra = a === S.sel && S.draft ? S.draft.staff : ua.staff, rb = b === S.sel && S.draft ? S.draft.staff : ub.staff;
        return allows(ra, b) && allows(rb, a);
    }
    function staffSummary(u) {
        if (u.role === 'ADMIN') return '<span class="ca-chip a"><i class="fas fa-crown"></i>همیشه با همه</span>';
        const r = u.staff;
        const blocked = S.d.users.filter(x => x.id !== u.id && !pairOk(u.id, x.id)).length;
        const rule = r.mode === 'ONLY' ? `<span class="ca-chip b">فقط ${fa(r.ids.length)} نفر</span>` : r.mode === 'EXCEPT' ? `<span class="ca-chip r">به‌جز ${fa(r.ids.length)} نفر</span>` : '<span class="ca-chip g">با همه</span>';
        return rule + (blocked ? ` <span class="ca-chip r" title="همکارانی که گفتگو با آن‌ها بسته است (قانونِ خودش یا طرفِ مقابل)"><i class="fas fa-lock"></i>${fa(blocked)}</span>` : '');
    }
    function coSummary(c) {
        return c.mode === 'ALL' ? '<span class="ca-chip g"><i class="fas fa-building"></i>همه‌ی شرکت‌ها</span>' : c.mode === 'NONE' ? '<span class="ca-chip"><i class="fas fa-building"></i>بدونِ شرکت</span>'
             : c.mode === 'LIST' ? `<span class="ca-chip b"><i class="fas fa-building"></i>${fa(c.ids.length)} شرکت</span>` : '<span class="ca-chip"><i class="fas fa-building"></i>طبقِ نقش</span>';
    }
    const initials = n => { const p = String(n || '؟').trim().split(/\s+/); return esc((p[0] || '؟')[0] + (p[1] ? p[1][0] : '')); };

    async function render(root) {
        if (!root) return;
        css();
        S.root = root;
        root.innerHTML = '<p class="text-center text-xs text-slate-400 py-10"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ بارگذاری…</p>';
        const d = await api('get');
        if (!d.ok) { root.innerHTML = `<p class="text-center text-sm text-rose-600 py-10">${esc(d.error || 'خطا')}</p>`; return; }
        S.d = d;
        if (!S.sel || !user(S.sel)) { const first = d.users.find(u => u.role !== 'ADMIN'); S.sel = first ? first.id : 0; }
        S.draft = null; S.dirty = false;
        draw();
    }
    function draw() {
        const d = S.d, staff = d.users.filter(u => u.role !== 'ADMIN');
        const limited = staff.filter(u => u.staff.mode !== 'ALL').length;
        let closed = 0;
        for (let i = 0; i < d.users.length; i++) for (let j = i + 1; j < d.users.length; j++) if (!pairOk(d.users[i].id, d.users[j].id)) closed++;
        S.root.innerHTML = `<div class="space-y-4">
            <div class="ca-hero"><div class="relative z-[1] flex flex-wrap items-center gap-4">
              <span class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center text-xl"><i class="fas fa-user-lock"></i></span>
              <div class="flex-1 min-w-[220px]"><h2 class="text-lg font-black">دسترسیِ گفتگو</h2>
                <p class="text-[11.5px] opacity-90 leading-6">تعیین کنید هر همکار با چه کسانی گفتگو کند. گفتگوی دو نفر فقط وقتی باز است که قانونِ هر دو اجازه بدهد؛ مدیر کل همیشه با همه گفتگو می‌کند.</p></div>
              <div class="flex gap-2">${[['همکاران', staff.length], ['محدودشده', limited], ['گفتگوی بسته', closed]].map(([a, b]) => `<div class="ca-stat"><b>${fa(b)}</b><small>${a}</small></div>`).join('')}</div>
            </div></div>
            <div class="ca-grid">
              <div class="card p-3">
                <div class="relative mb-2"><i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                  <input type="search" class="w-full border border-slate-200 rounded-xl pr-8 pl-3 py-2 text-xs" placeholder="جستجوی همکار…" value="${esc(S.q)}" data-q></div>
                <div class="ca-list space-y-1" data-list>${listHtml()}</div>
              </div>
              <div class="card p-4" data-ed>${editorHtml()}</div>
            </div>
            <div class="card p-4" data-roles>${rolesHtml()}</div>
          </div>`;
        bind();
    }
    function listHtml() {
        const q = norm(S.q);
        return S.d.users.filter(u => !q || norm(u.name + ' ' + u.username + ' ' + u.role_fa).includes(q)).map(u => `
            <div class="ca-u ${u.id === S.sel ? 'on' : ''}" data-u="${u.id}">
              <span class="ca-av ${u.role === 'ADMIN' ? 'ad' : ''}">${initials(u.name)}</span>
              <div class="flex-1 min-w-0"><p class="text-[12.5px] font-black text-slate-800 truncate">${esc(u.name)}</p>
                <p class="text-[10px] text-slate-400 font-bold mb-0.5">${esc(u.role_fa)}</p>
                <div class="flex flex-wrap gap-1">${staffSummary(u)} ${u.role === 'ADMIN' ? '' : coSummary(u.companies)}</div></div>
            </div>`).join('') || '<p class="text-xs text-slate-400 text-center py-6">کسی پیدا نشد.</p>';
    }
    function editorHtml() {
        const u = user(S.sel);
        if (!u) return '<p class="text-xs text-slate-400 text-center py-10">یک همکار را از فهرست انتخاب کنید.</p>';
        if (u.role === 'ADMIN') return `<div class="text-center py-10"><span class="ca-av ad mx-auto" style="width:56px;height:56px;font-size:18px">${initials(u.name)}</span>
            <p class="font-black text-slate-800 mt-3">${esc(u.name)}</p><p class="text-xs text-slate-500 mt-1">مدیر کل همیشه با همه‌ی همکاران و شرکت‌ها گفتگو می‌کند.</p></div>`;
        if (!S.draft) S.draft = {staff: {mode: u.staff.mode, ids: u.staff.ids.slice()}, companies: {mode: u.companies.mode, ids: u.companies.ids.slice()}};
        const D = S.draft, others = S.d.users.filter(x => x.id !== u.id), pq = norm(S.pq), cq = norm(S.cq);
        const okList = others.filter(x => pairOk(u.id, x.id)), noList = others.filter(x => !pairOk(u.id, x.id));
        const why = x => !allows(D.staff, x.id) ? 'طبقِ قانونِ خودش' : 'طبقِ قانونِ ' + x.name;
        const pickStaff = D.staff.mode !== 'ALL';
        return `<div class="flex items-center gap-3 mb-4"><span class="ca-av" style="width:46px;height:46px;font-size:15px">${initials(u.name)}</span>
              <div class="flex-1 min-w-0"><p class="font-black text-slate-800">${esc(u.name)}</p><p class="text-[11px] text-slate-500">${esc(u.role_fa)} · <span dir="ltr">${esc(u.username || '')}</span></p></div>
              ${S.dirty ? '<span class="ca-chip a">تغییرِ ذخیره‌نشده</span>' : ''}</div>
            <p class="text-[11px] font-black text-slate-500 mb-2"><i class="fas fa-user-group text-emerald-500 ml-1"></i>گفتگو با همکاران</p>
            <div class="ca-seg mb-3" data-smode>${[['ALL', 'با همه', 'هر همکاری (اگر طرفِ مقابل هم اجازه بدهد)'], ['ONLY', 'فقط افرادِ انتخابی', 'فقط با کسانی که تیک می‌زنید'], ['EXCEPT', 'همه به‌جز انتخابی‌ها', 'کسانی که تیک می‌زنید بسته می‌شوند']]
                .map(([k, t, s]) => `<button type="button" data-m="${k}" class="${D.staff.mode === k ? 'on' : ''}"><b>${t}</b><small>${s}</small></button>`).join('')}</div>
            ${pickStaff ? `<div class="flex flex-wrap items-center gap-2 mb-2"><input type="search" class="flex-1 min-w-[160px] border border-slate-200 rounded-xl px-3 py-1.5 text-xs" placeholder="جستجو…" value="${esc(S.pq)}" data-pq>
                <button type="button" class="text-[11px] font-black text-emerald-700 bg-emerald-50 rounded-lg px-3 py-1.5" data-sall="1">تیکِ همه</button>
                <button type="button" class="text-[11px] font-black text-slate-600 bg-slate-100 rounded-lg px-3 py-1.5" data-sall="0">هیچ</button>
                <span class="text-[11px] font-black text-slate-500">${fa(D.staff.ids.length)} نفر تیک خورده</span></div>
              <div class="ca-pick mb-3" data-spick>${others.filter(x => !pq || norm(x.name + ' ' + x.role_fa).includes(pq)).map(x => x.role === 'ADMIN'
                    ? `<label class="lock" title="مدیر کل همیشه در دسترس است"><input type="checkbox" checked disabled>${esc(x.name)} <small class="text-[9.5px] text-amber-600 mr-auto">مدیر کل</small></label>`
                    : `<label class="${D.staff.ids.includes(x.id) ? 'on' : ''}"><input type="checkbox" value="${x.id}" ${D.staff.ids.includes(x.id) ? 'checked' : ''}>${esc(x.name)} <small class="text-[9.5px] text-slate-400 mr-auto">${esc(x.role_fa)}</small></label>`).join('')}</div>` : ''}
            <div class="ca-res mb-5"><p class="text-[11.5px] font-black text-teal-800 mb-1.5"><i class="fas fa-eye ml-1"></i>نتیجه: با ${fa(okList.length)} نفر گفتگو دارد${noList.length ? ' و با ' + fa(noList.length) + ' نفر ندارد' : ''}</p>
              ${noList.length ? `<div class="flex flex-wrap gap-1">${noList.map(x => `<span class="ca-chip r" title="${esc(why(x))}"><i class="fas fa-lock"></i>${esc(x.name)}</span>`).join('')}</div>` : '<p class="text-[11px] text-slate-500">گفتگو با همه‌ی همکاران باز است.</p>'}</div>
            <p class="text-[11px] font-black text-slate-500 mb-2"><i class="fas fa-building text-sky-500 ml-1"></i>گفتگو با شرکت‌ها</p>
            <div class="ca-seg mb-3" data-cmode>${[['ROLE', 'طبقِ نقش', S.d.company_roles.includes(u.role) ? 'نقشش با همه‌ی شرکت‌ها گفتگو می‌کند' : 'نقشش با شرکت‌ها گفتگو نمی‌کند'], ['ALL', 'همه‌ی شرکت‌ها', ''], ['NONE', 'هیچ شرکتی', ''], ['LIST', 'فقط شرکت‌های انتخابی', '']]
                .map(([k, t, s]) => `<button type="button" data-m="${k}" class="${D.companies.mode === k ? 'on' : ''}"><b>${t}</b>${s ? `<small>${s}</small>` : ''}</button>`).join('')}</div>
            ${D.companies.mode === 'LIST' ? `<input type="search" class="w-full border border-slate-200 rounded-xl px-3 py-1.5 text-xs mb-2" placeholder="جستجوی شرکت…" value="${esc(S.cq)}" data-cq>
              <div class="ca-pick mb-3" data-cpick>${S.d.companies.filter(c => !cq || norm(c.name).includes(cq)).map(c => `<label class="${D.companies.ids.includes(c.id) ? 'on' : ''}"><input type="checkbox" value="${c.id}" ${D.companies.ids.includes(c.id) ? 'checked' : ''}>${esc(c.name)}</label>`).join('') || '<p class="text-xs text-slate-400">شرکتی نیست.</p>'}</div>` : ''}
            <div class="flex gap-2 mt-4">
              <button type="button" class="text-white text-xs font-black px-6 py-2.5 rounded-xl" style="background:linear-gradient(120deg,#059669,#0e7490)" data-save><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی دسترسیِ ${esc(u.name)}</button>
              ${S.dirty ? '<button type="button" class="text-xs font-black px-4 py-2.5 rounded-xl bg-slate-100 text-slate-600" data-undo>برگرداندن</button>' : ''}
            </div>`;
    }
    function rolesHtml() {
        const d = S.d;
        return `<div class="flex flex-wrap items-center gap-2"><p class="text-[12px] font-black text-slate-700 ml-2"><i class="fas fa-id-badge text-sky-500 ml-1"></i>نقش‌هایی که «طبقِ نقش» با همه‌ی شرکت‌ها گفتگو می‌کنند:</p>
            ${Object.entries(d.company_role_options).map(([k, t]) => `<label class="flex items-center gap-1.5 text-xs font-bold bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5"><input type="checkbox" class="accent-emerald-600" value="${k}" ${d.company_roles.includes(k) ? 'checked' : ''}>${esc(t)}</label>`).join('')}
            <button type="button" class="mr-auto text-[11px] font-black text-white bg-sky-600 hover:bg-sky-700 rounded-lg px-4 py-2" data-saveroles>ذخیره‌ی نقش‌ها</button></div>`;
    }
    function redraw(part) {
        if (part === 'ed' || !part) { S.root.querySelector('[data-ed]').innerHTML = editorHtml(); bindEditor(); }
        if (part === 'list' || !part) { S.root.querySelector('[data-list]').innerHTML = listHtml(); bindList(); }
    }
    function bindList() {
        S.root.querySelectorAll('[data-u]').forEach(el => el.onclick = async () => {
            const id = +el.dataset.u;
            if (id === S.sel) return;
            if (S.dirty && window.uiConfirm && !(await uiConfirm('تغییرِ ذخیره‌نشده', 'تغییراتِ این همکار ذخیره نشده؛ بدونِ ذخیره بروید؟', {ok: 'بله، برو'}))) return;
            S.sel = id; S.draft = null; S.dirty = false; S.pq = ''; S.cq = '';
            redraw();
        });
    }
    function bindEditor() {
        const ed = S.root.querySelector('[data-ed]');
        const D = S.draft;
        const touch = () => { S.dirty = true; redraw(); };
        ed.querySelectorAll('[data-smode] [data-m]').forEach(b => b.onclick = () => { if (D.staff.mode !== b.dataset.m) { D.staff.mode = b.dataset.m; touch(); } });
        ed.querySelectorAll('[data-cmode] [data-m]').forEach(b => b.onclick = () => { if (D.companies.mode !== b.dataset.m) { D.companies.mode = b.dataset.m; touch(); } });
        ed.querySelectorAll('[data-spick] input:not([disabled])').forEach(i => i.onchange = () => {
            const id = +i.value; D.staff.ids = i.checked ? [...new Set([...D.staff.ids, id])] : D.staff.ids.filter(x => x !== id); touch();
        });
        ed.querySelectorAll('[data-cpick] input').forEach(i => i.onchange = () => {
            const id = +i.value; D.companies.ids = i.checked ? [...new Set([...D.companies.ids, id])] : D.companies.ids.filter(x => x !== id); S.dirty = true;
            i.closest('label').classList.toggle('on', i.checked);
        });
        ed.querySelectorAll('[data-sall]').forEach(b => b.onclick = () => {
            const pq = norm(S.pq);
            const vis = S.d.users.filter(x => x.id !== S.sel && x.role !== 'ADMIN' && (!pq || norm(x.name + ' ' + x.role_fa).includes(pq))).map(x => x.id);
            D.staff.ids = b.dataset.sall === '1' ? [...new Set([...D.staff.ids, ...vis])] : D.staff.ids.filter(x => !vis.includes(x));
            touch();
        });
        const pq = ed.querySelector('[data-pq]');
        if (pq) pq.oninput = () => { S.pq = pq.value; const pos = pq.selectionStart; redraw('ed'); const n = S.root.querySelector('[data-pq]'); n.focus(); n.setSelectionRange(pos, pos); };
        const cq = ed.querySelector('[data-cq]');
        if (cq) cq.oninput = () => { S.cq = cq.value; const pos = cq.selectionStart; redraw('ed'); const n = S.root.querySelector('[data-cq]'); n.focus(); n.setSelectionRange(pos, pos); };
        const undo = ed.querySelector('[data-undo]');
        if (undo) undo.onclick = () => { S.draft = null; S.dirty = false; redraw(); };
        const sv = ed.querySelector('[data-save]');
        if (sv) sv.onclick = async () => {
            if (D.staff.mode === 'ONLY' && !D.staff.ids.length && window.uiConfirm
                && !(await uiConfirm('هیچ همکاری انتخاب نشده', '«فقط افرادِ انتخابی» بدونِ هیچ تیکی یعنی این همکار فقط با مدیر کل گفتگو می‌کند. ادامه می‌دهید؟', {ok: 'بله'}))) return;
            const r = await api('save', {user_id: S.sel, staff: D.staff, companies: D.companies});
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            const u = user(S.sel);
            u.staff = {mode: D.staff.mode === 'EXCEPT' && !D.staff.ids.length ? 'ALL' : D.staff.mode, ids: D.staff.ids.slice()};
            u.companies = {mode: D.companies.mode, ids: D.companies.ids.slice()};
            S.draft = null; S.dirty = false;
            toast('دسترسیِ گفتگوی ' + u.name + ' ذخیره شد.', 'success');
            draw();
        };
    }
    function bind() {
        const q = S.root.querySelector('[data-q]');
        q.oninput = () => { S.q = q.value; S.root.querySelector('[data-list]').innerHTML = listHtml(); bindList(); };
        bindList();
        bindEditor();
        S.root.querySelector('[data-saveroles]').onclick = async () => {
            const roles = [...S.root.querySelectorAll('[data-roles] input:checked')].map(i => i.value);
            const r = await api('save_company_roles', {roles});
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            S.d.company_roles = ['ADMIN', ...roles];
            toast('نقش‌های گفتگو با شرکت‌ها ذخیره شد.', 'success');
            redraw('ed'); redraw('list');
        };
    }
    window.ChatAccess = {render};
})();
