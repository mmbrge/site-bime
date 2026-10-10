// فایل: announce-admin.js
// صفحه‌ی «اعلان‌های پاپ‌آپ»: نوشتنِ اعلان با طرح‌های آماده، انتخابِ گیرندگان (همه، کاربرانِ پنل، کاربرانِ شرکت‌ها، نقش‌ها یا افراد)،
// زمان‌بندی، «خواندم/می‌پذیرم»، پیش‌نمایش؛ فهرستِ اعلان‌ها با آمارِ دیده‌شدن؛ و اعلانِ خودکارِ تولد + تاریخِ تولدِ همکاران.
(function () {
    'use strict';
    const API = 'api/announce_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : alert(m));
    async function api(action, body = {}) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور'}; }
    }
    const ICONS = ['ℹ️', '📢', '⚠️', '🚨', '📜', '🎉', '🎂', '🌴', '🛠️', '✅', '⭐', '💡', '📌', '🔔', '🕌', '🏆', '❤️', '☕', '📅', '🚗'];
    const STATE = {live: ['در حالِ نمایش', 'bg-emerald-100 text-emerald-700'], scheduled: ['زمان‌بندی‌شده', 'bg-sky-100 text-sky-700'], ended: ['پایان‌یافته', 'bg-slate-100 text-slate-500'], off: ['غیرفعال', 'bg-rose-100 text-rose-600']};
    const S = {data: null, form: null, touched: false};
    const blank = () => ({id: 0, template: 'info', title: '', body: '', icon: '', button: '', require_ack: false, audience: {type: 'all', roles: [], users: []}, start: '', end: '', release: {version: '', items: [], view: ''}});
    const REL_TYPES = {feature: ['✨', 'امکانِ تازه', 'bg-violet-100 text-violet-700'], improve: ['⚡', 'بهبود', 'bg-sky-100 text-sky-700'], fix: ['🛠️', 'رفعِ اشکال', 'bg-amber-100 text-amber-700']};

    async function render(root) {
        if (!root) return;
        S.root = root;
        const [d, cl] = await Promise.all([api('list'), api('changelog')]);
        if (!d.ok) { root.innerHTML = `<p class="text-center text-sm text-rose-600 py-10">${esc(d.error || 'خطا')}</p>`; return; }
        S.data = d;
        S.cl = cl.ok ? cl : {versions: [], sent: {}, current: ''};
        if (!S.form) { S.form = blank(); applyTpl('info', true); }
        root.innerHTML = `
          <div class="space-y-5">
            <div class="rounded-[26px] p-6 text-white relative overflow-hidden" style="background:linear-gradient(125deg,#1e1b4b,#7c3aed 55%,#db2777)">
              <div style="position:absolute;width:300px;height:300px;border-radius:50%;left:-80px;top:-150px;background:radial-gradient(circle,rgba(255,255,255,.25),transparent 70%)"></div>
              <div class="relative flex flex-wrap items-center gap-4">
                <div class="flex-1 min-w-[240px]"><p class="text-[11px] opacity-80 font-bold">اطلاع‌رسانی به کاربران</p><h2 class="text-2xl font-black mt-1"><i class="fas fa-bullhorn ml-2"></i>اعلان‌های پاپ‌آپ</h2>
                  <p class="text-[11.5px] opacity-85 mt-1 leading-6">با یکی از طرح‌های آماده اعلان بنویسید؛ همان لحظه (یا در زمانی که تعیین می‌کنید) برای کاربرانِ پنل و پنلِ شرکت‌ها به‌صورتِ پاپ‌آپ باز می‌شود.</p></div>
                <div class="flex gap-2">${[['اعلان‌ها', d.items.length], ['در حالِ نمایش', d.items.filter(x => x.state === 'live').length]].map(([a, b]) => `<div class="rounded-2xl bg-white/15 border border-white/20 px-4 py-2 text-center"><b class="block text-xl font-black">${fa(b)}</b><small class="text-[10.5px] opacity-85">${a}</small></div>`).join('')}</div>
              </div>
            </div>
            ${d.can.create || (S.form.id && d.can.edit) ? composer() : ''}
            <div class="card p-5">
              <div class="flex flex-wrap items-center gap-2 mb-3"><h3 class="font-black text-slate-700 text-sm"><i class="fas fa-list text-violet-500 ml-1"></i>اعلان‌های فرستاده‌شده</h3>
                <select class="border border-slate-200 rounded-xl px-2 py-1.5 text-xs mr-auto" data-flt><option value="">همه</option>${Object.entries(STATE).map(([k, v]) => `<option value="${k}">${v[0]}</option>`).join('')}<option value="auto">فقط خودکار (تولد)</option></select></div>
              <div data-list></div>
            </div>
            <div class="card p-5" data-bday><p class="text-xs text-slate-400">…</p></div>
            <div class="card p-5" data-morning><p class="text-xs text-slate-400">…</p></div>
          </div>`;
        bindComposer();
        drawList('');
        root.querySelector('[data-flt]').onchange = e => drawList(e.target.value);
        drawBday();
        drawMorning();
    }
    function applyTpl(k, force) {
        const t = window.Announce.TEMPLATES[k];
        const f = S.form;
        const isDefault = !S.touched || force;
        f.template = k;
        if (isDefault) { f.title = t.title; f.body = t.body; f.button = ''; f.icon = ''; f.require_ack = !!t.ack; S.touched = false; }
        if (k === 'release' && S.cl) {
            f.release = f.release || {version: '', items: [], view: ''};
            if (!f.release.version) f.release.version = S.cl.current || '';
            if (!f.release.view) f.release.view = S.cl.current || '';
        }
    }
    // ---------------- «تغییراتِ نسخه»: انتخابِ امکانات و رفعِ اشکال‌ها با تیک ----------------
    function clIndex() { const m = {}; (S.cl.versions || []).forEach(v => v.items.forEach(i => { m[i.id] = Object.assign({version: v.version}, i); })); return m; }
    function releasePicker() {
        const f = S.form, r = f.release, cl = S.cl, sel = new Set(r.items);
        const vers = (cl.versions || []).filter(v => !r.view || v.version === r.view);
        const sentChip = id => { const x = (cl.sent || {})[id]; return x && x.length ? `<span class="text-[9.5px] font-black rounded-full px-2 py-0.5 bg-emerald-50 text-emerald-700" title="${esc(x.map(y => 'نسخه‌ی ' + y.version + ' · ' + y.at).join('\n'))}">✓ اعلام‌شده${x.length > 1 ? ' ' + fa(x.length) + ' بار' : ''}</span>` : ''; };
        return `<div class="rounded-2xl border border-indigo-100 p-4 mt-4" style="background:linear-gradient(135deg,#eef2ff,#ecfeff)" data-rel>
            <div class="flex flex-wrap items-end gap-2 mb-3">
              <div><p class="text-[11px] font-black text-indigo-700 mb-1"><i class="fas fa-rocket ml-1"></i>امکانات و رفعِ اشکال‌ها</p>
                <p class="text-[10.5px] text-slate-500">هرچه تیک بزنید با طرحِ «تغییراتِ نسخه» برای گیرندگان نشان داده می‌شود.</p></div>
              <label class="mr-auto"><span class="block text-[10px] font-bold text-slate-500 mb-0.5">شماره‌ی نسخه در اعلان</span><input class="w-28 border border-indigo-200 rounded-xl px-2 py-1.5 text-xs text-center font-black" dir="ltr" data-rv value="${esc(r.version)}" placeholder="${esc(cl.current || '1.0.0')}"></label>
              <label><span class="block text-[10px] font-bold text-slate-500 mb-0.5">نمایشِ فهرست</span><select class="border border-indigo-200 rounded-xl px-2 py-1.5 text-xs font-bold" data-rview>
                <option value="">همه‌ی نسخه‌ها</option>${(cl.versions || []).map(v => `<option value="${esc(v.version)}" ${r.view === v.version ? 'selected' : ''}>نسخه‌ی ${esc(v.version)} · ${fa(v.items.length)} مورد</option>`).join('')}</select></label>
            </div>
            <div class="flex flex-wrap gap-1.5 mb-3">
              <button type="button" class="text-[11px] font-black bg-indigo-600 text-white rounded-lg px-3 py-1.5" data-rsel="all"><i class="fas fa-check-double ml-1"></i>همه‌ی موارد فهرست</button>
              <button type="button" class="text-[11px] font-black bg-white text-indigo-700 border border-indigo-200 rounded-lg px-3 py-1.5" data-rsel="new">فقط اعلام‌نشده‌ها</button>
              ${Object.entries(REL_TYPES).map(([k, t]) => `<button type="button" class="text-[11px] font-black bg-white text-slate-600 border border-slate-200 rounded-lg px-3 py-1.5" data-rsel="t:${k}">${t[0]} همه‌ی ${t[1]}‌ها</button>`).join('')}
              <button type="button" class="text-[11px] font-black bg-white text-rose-600 border border-rose-200 rounded-lg px-3 py-1.5" data-rsel="none">هیچ</button>
              <span class="mr-auto text-[11px] font-black text-indigo-700 bg-white rounded-lg px-3 py-1.5" data-rcount>${fa(sel.size)} مورد انتخاب شد</span>
            </div>
            <div class="space-y-3 max-h-[420px] overflow-y-auto pl-1">${vers.map(v => `<div>
                <p class="text-[11px] font-black text-slate-600 mb-1.5">نسخه‌ی <span dir="ltr">${esc(v.version)}</span> <small class="text-slate-400 font-bold">${fa(v.date)} · ${esc(v.title || '')}</small></p>
                <div class="grid md:grid-cols-2 gap-1.5">${v.items.map(i => { const t = REL_TYPES[i.type] || REL_TYPES.feature; return `<label class="flex gap-2 items-start rounded-xl border ${sel.has(i.id) ? 'border-indigo-400 bg-white shadow-sm' : 'border-slate-200 bg-white/70'} px-3 py-2 cursor-pointer" data-ritem>
                    <input type="checkbox" class="accent-indigo-600 w-4 h-4 mt-1" value="${esc(i.id)}" ${sel.has(i.id) ? 'checked' : ''}>
                    <span class="min-w-0"><span class="flex flex-wrap items-center gap-1"><span class="text-[9.5px] font-black rounded-full px-2 py-0.5 ${t[2]}">${t[0]} ${t[1]}</span><b class="text-[12px] text-slate-800">${esc(i.title)}</b>${i.area ? `<span class="text-[9.5px] text-slate-500 bg-slate-100 rounded-full px-2">${esc(i.area)}</span>` : ''}${sentChip(i.id)}</span>
                      <span class="block text-[10.5px] text-slate-500 leading-5 mt-0.5">${esc(i.desc || '')}</span></span></label>`; }).join('')}</div></div>`).join('') || '<p class="text-xs text-slate-400">فهرستی نیست.</p>'}</div>
          </div>`;
    }
    function releaseExtra(f) {
        const m = clIndex(), order = ['feature', 'improve', 'fix'];
        const items = f.release.items.map(id => m[id]).filter(Boolean).sort((a, b) => order.indexOf(a.type) - order.indexOf(b.type));
        return {version: f.release.version || S.cl.current, date: '', items};
    }
    function composer() {
        const f = S.form, T = window.Announce.TEMPLATES, d = S.data;
        const aud = f.audience;
        const roles = Object.entries(d.roles);
        return `<div class="card p-5" data-comp>
            <div class="flex items-center gap-2 mb-3"><h3 class="font-black text-slate-700 text-sm"><i class="fas fa-pen-to-square text-violet-500 ml-1"></i>${f.id ? 'ویرایشِ اعلان' : 'اعلانِ تازه'}</h3>
              ${f.id ? '<button type="button" class="mr-auto text-[11px] font-bold text-slate-500 bg-slate-100 rounded-lg px-3 py-1" data-a="new">اعلانِ تازه</button>' : ''}</div>
            <p class="text-[11px] font-black text-slate-500 mb-2">۱. طرح را انتخاب کنید</p>
            <div class="grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-10 gap-2 mb-4">${Object.entries(T).map(([k, t]) => `<button type="button" data-tpl="${k}" class="rounded-2xl overflow-hidden border-2 ${f.template === k ? 'border-violet-500 shadow-lg' : 'border-transparent'} text-right transition hover:-translate-y-0.5" style="background:#fff">
                <div style="height:46px;background:${t.bg};display:flex;align-items:center;justify-content:center;font-size:24px">${t.icon}</div><div class="px-2 py-1.5 text-[10.5px] font-black text-slate-700 text-center">${t.name}</div></button>`).join('')}</div>
            <div class="grid lg:grid-cols-2 gap-4">
              <div class="space-y-3">
                <p class="text-[11px] font-black text-slate-500">۲. متن</p>
                <div class="flex gap-2"><input class="w-16 border border-slate-200 rounded-xl px-2 py-2 text-xl text-center" data-f="icon" value="${esc(f.icon)}" placeholder="${T[f.template].icon}" title="نماد (اختیاری)">
                  <input class="flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm font-bold" data-f="title" value="${esc(f.title)}" placeholder="${f.template === 'release' ? 'عنوان (خالی = «تازه‌های نسخه‌ی …»)' : 'عنوانِ اعلان'}"></div>
                <div class="flex flex-wrap gap-1">${ICONS.map(i => `<button type="button" class="w-8 h-8 rounded-lg hover:bg-slate-100 text-lg" data-icon="${i}">${i}</button>`).join('')}</div>
                <textarea class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm leading-7" rows="${f.template === 'release' ? 3 : 7}" data-f="body" placeholder="${f.template === 'release' ? 'متنِ کوتاهِ ابتدای اعلان (اختیاری)، مثلاً: همکارانِ عزیز، نسخه‌ی تازه‌ی سامانه آماده است…' : 'متنِ اعلان…'}">${esc(f.body)}</textarea>
                <p class="text-[10.5px] text-slate-400 leading-5">«**متن**» پررنگ می‌شود؛ خطی که با «- » شروع شود فهرست می‌شود${f.template === 'rules' ? '؛ در «قوانین» هر خط یک بندِ شماره‌دار است' : ''}.</p>
                <div class="flex flex-wrap gap-3 items-center"><input class="flex-1 min-w-[160px] border border-slate-200 rounded-xl px-3 py-2 text-xs" data-f="button" value="${esc(f.button)}" placeholder="متنِ دکمه: ${esc(T[f.template].btn)}">
                  <label class="flex items-center gap-2 text-xs font-bold text-slate-700"><input type="checkbox" class="accent-violet-600 w-4 h-4" data-f="require_ack" ${f.require_ack ? 'checked' : ''}>باید تیکِ «خواندم» بزنند</label></div>
              </div>
              <div class="space-y-3">
                <p class="text-[11px] font-black text-slate-500">۳. گیرندگان</p>
                <div class="grid grid-cols-2 gap-1.5">${[['all', 'fa-globe', 'همه (پنل و شرکت‌ها)'], ['staff', 'fa-user-tie', 'همه‌ی کاربرانِ پنل'], ['company', 'fa-building', 'همه‌ی کاربرانِ شرکت‌ها'], ['roles', 'fa-id-badge', 'نقش‌های خاص'], ['users', 'fa-user-check', 'افرادِ خاص']]
                    .map(([k, ic, t]) => `<label class="flex items-center gap-2 rounded-xl border ${aud.type === k ? 'border-violet-400 bg-violet-50' : 'border-slate-200 bg-white'} px-3 py-2 text-xs font-bold text-slate-700 cursor-pointer"><input type="radio" name="an-aud" value="${k}" class="accent-violet-600" ${aud.type === k ? 'checked' : ''}><i class="fas ${ic} text-violet-500"></i>${t}</label>`).join('')}</div>
                <div class="${aud.type === 'roles' ? '' : 'hidden'} flex flex-wrap gap-1.5" data-roles>${roles.map(([k, t]) => `<label class="flex items-center gap-1.5 text-xs font-bold bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5"><input type="checkbox" class="accent-violet-600" value="${k}" ${(aud.roles || []).includes(k) ? 'checked' : ''}>${esc(t)}</label>`).join('')}</div>
                <div class="${aud.type === 'users' ? '' : 'hidden'}" data-users>
                  <input class="w-full border border-slate-200 rounded-xl px-3 py-1.5 text-xs mb-1.5" placeholder="جستجوی نام…" data-usq>
                  <div class="max-h-44 overflow-y-auto grid grid-cols-2 gap-1" data-ulist>${d.people.map(p => `<label class="flex items-center gap-1.5 text-[11.5px] font-bold text-slate-700 bg-slate-50 rounded-lg px-2 py-1.5" data-un="${esc(p.name)}"><input type="checkbox" class="accent-violet-600" value="${p.t}:${p.id}" ${(aud.users || []).includes(p.t + ':' + p.id) ? 'checked' : ''}>${esc(p.name)} <small class="text-[9.5px] text-slate-400">${p.t === 'C' ? 'شرکت' : esc(d.roles[p.role] || '')}</small></label>`).join('')}</div></div>
                <p class="text-[11px] font-black text-slate-500 pt-2">۴. زمان</p>
                <div class="grid grid-cols-2 gap-2">
                  <label><span class="block text-[10.5px] font-bold text-slate-500 mb-1">شروع (خالی = همین حالا)</span><input class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs" dir="ltr" data-f="start" value="${esc(fa(f.start))}" placeholder="۱۴۰۵/۰۷/۱۴ ۰۹:۰۰"></label>
                  <label><span class="block text-[10.5px] font-bold text-slate-500 mb-1">پایان (اختیاری)</span><input class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs" dir="ltr" data-f="end" value="${esc(fa(f.end))}" placeholder="۱۴۰۵/۰۷/۲۰"></label></div>
                <p class="text-[10.5px] text-slate-400">بعد از پایان دیگر به کسی نشان داده نمی‌شود؛ هر نفر اعلان را یک بار می‌بیند (اگر «خواندم» لازم باشد، تا تیک نزند دوباره می‌آید).</p>
              </div>
            </div>
            ${f.template === 'release' ? releasePicker() : ''}
            <div class="flex flex-wrap gap-2 mt-4">
              <button type="button" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black px-5 py-2.5 rounded-xl" data-a="preview"><i class="fas fa-eye ml-1"></i>پیش‌نمایش</button>
              <button type="button" class="text-white text-xs font-black px-6 py-2.5 rounded-xl" style="background:linear-gradient(120deg,#7c3aed,#db2777)" data-a="send"><i class="fas fa-paper-plane ml-1"></i>${f.id ? 'ذخیره‌ی تغییرات' : 'ارسالِ اعلان'}</button>
              ${f.id ? '<label class="flex items-center gap-2 text-xs font-bold text-slate-600 mr-2"><input type="checkbox" class="accent-violet-600" data-f="resend">همه دوباره ببینند</label>' : ''}
            </div></div>`;
    }
    function collect() {
        const c = S.root.querySelector('[data-comp]');
        if (!c) return S.form;
        const f = S.form, g = k => c.querySelector(`[data-f="${k}"]`);
        f.title = g('title').value; f.body = g('body').value; f.icon = g('icon').value.trim(); f.button = g('button').value; f.require_ack = g('require_ack').checked;
        f.start = en(g('start').value.trim()); f.end = en(g('end').value.trim()); f.resend = g('resend') ? g('resend').checked : false;
        f.audience.type = (c.querySelector('input[name="an-aud"]:checked') || {}).value || 'all';
        f.audience.roles = [...c.querySelectorAll('[data-roles] input:checked')].map(i => i.value);
        f.audience.users = [...c.querySelectorAll('[data-ulist] input:checked')].map(i => i.value);
        const rv = c.querySelector('[data-rv]');
        if (rv) f.release.version = en(rv.value.trim());
        return f;
    }
    function bindComposer() {
        const c = S.root.querySelector('[data-comp]');
        if (!c) return;
        c.querySelectorAll('[data-tpl]').forEach(b => b.onclick = async () => {
            collect();
            if (S.touched && window.uiConfirm) {
                const keep = await uiConfirm('عوض کردنِ طرح', 'متنی که نوشته‌اید بماند؟ («نه» یعنی متنِ پیش‌فرضِ این طرح بیاید)', {ok: 'بله، بماند', cancel: 'نه، متنِ طرح'});
                applyTpl(b.dataset.tpl, !keep);
            } else applyTpl(b.dataset.tpl, true);
            redrawComposer();
        });
        ['title', 'body', 'icon', 'button'].forEach(k => c.querySelector(`[data-f="${k}"]`).addEventListener('input', () => { S.touched = true; }));
        c.querySelectorAll('[data-icon]').forEach(b => b.onclick = () => { c.querySelector('[data-f="icon"]').value = b.dataset.icon; S.touched = true; });
        c.querySelectorAll('input[name="an-aud"]').forEach(r => r.onchange = () => { collect(); redrawComposer(); });
        const q = c.querySelector('[data-usq]');
        if (q) q.oninput = () => c.querySelectorAll('[data-un]').forEach(l => l.classList.toggle('hidden', !!q.value.trim() && !l.dataset.un.includes(q.value.trim())));
        // انتخابِ امکانات و رفعِ اشکال‌ها
        const rel = c.querySelector('[data-rel]');
        if (rel) {
            const f = S.form, cnt = () => { rel.querySelector('[data-rcount]').textContent = fa(f.release.items.length) + ' مورد انتخاب شد'; };
            rel.querySelectorAll('[data-ritem] input').forEach(i => i.onchange = () => {
                const set = new Set(f.release.items); i.checked ? set.add(i.value) : set.delete(i.value); f.release.items = [...set];
                const lb = i.closest('[data-ritem]'); lb.classList.toggle('border-indigo-400', i.checked); lb.classList.toggle('shadow-sm', i.checked); lb.classList.toggle('border-slate-200', !i.checked); cnt();
            });
            rel.querySelector('[data-rview]').onchange = e => {
                collect(); f.release.view = e.target.value;
                if (e.target.value) f.release.version = e.target.value;   // نسخه‌ی اعلان همان نسخه‌ی انتخابی
                redrawComposer();
            };
            rel.querySelectorAll('[data-rsel]').forEach(b => b.onclick = () => {
                collect();
                const k = b.dataset.rsel, vis = (S.cl.versions || []).filter(v => !f.release.view || v.version === f.release.view).flatMap(v => v.items);
                const set = new Set(f.release.items);
                if (k === 'none') vis.forEach(i => set.delete(i.id));
                else vis.filter(i => k === 'all' || (k === 'new' && !((S.cl.sent || {})[i.id] || []).length) || k === 't:' + i.type).forEach(i => set.add(i.id));
                f.release.items = [...set];
                redrawComposer();
            });
        }
        const on = (a, fn) => { const b = c.querySelector(`[data-a="${a}"]`); if (b) b.onclick = fn; };
        on('new', () => { S.form = blank(); applyTpl('info', true); redrawComposer(); });
        on('preview', () => {
            const f = collect(), rel = f.template === 'release';
            if (rel && !f.release.items.length) return toast('دست‌کم یک امکان یا رفعِ اشکال را تیک بزنید.', 'error');
            const ex = rel ? releaseExtra(f) : null;
            window.Announce.preview({template: f.template, title: f.title || (rel ? 'تازه‌های نسخه‌ی ' + ex.version : '(بی‌عنوان)'), body: f.body, icon: f.icon, button: f.button, require_ack: f.require_ack, from: 'پیش‌نمایش', at: '', extra: ex});
        });
        on('send', async () => {
            const f = collect();
            const r = await api('save', f);
            if (!r.ok) return toast(r.error, 'error');
            toast(f.id ? 'اعلان ذخیره شد.' : 'اعلان فرستاده شد؛ کاربران تا یک دقیقه‌ی دیگر می‌بینند.', 'success');
            S.form = blank(); applyTpl('info', true);
            render(S.root);
        });
    }
    function redrawComposer() {
        const old = S.root.querySelector('[data-comp]');
        if (!old) return;
        const tmp = document.createElement('div'); tmp.innerHTML = composer();
        old.replaceWith(tmp.firstElementChild);
        bindComposer();
    }
    function audText(a) {
        const d = S.data;
        if (a.type === 'staff') return 'همه‌ی کاربرانِ پنل' + (a.birthday_of ? '' : '');
        if (a.type === 'company') return 'همه‌ی کاربرانِ شرکت‌ها';
        if (a.type === 'roles') return 'نقش‌ها: ' + (a.roles || []).map(r => d.roles[r] || r).join('، ');
        if (a.type === 'users') { const names = (a.users || []).map(k => { const [t, id] = k.split(':'); const p = d.people.find(x => x.t === t && x.id === +id); return p ? p.name : k; }); return names.length > 3 ? names.slice(0, 3).join('، ') + ' و ' + fa(names.length - 3) + ' نفرِ دیگر' : names.join('، '); }
        return 'همه';
    }
    function drawList(flt) {
        const box = S.root.querySelector('[data-list]');
        const d = S.data, T = window.Announce.TEMPLATES;
        const items = d.items.filter(x => !flt || (flt === 'auto' ? x.auto : x.state === flt));
        box.innerHTML = items.length ? `<div class="space-y-2">${items.map(x => { const t = T[x.template] || T.info, st = STATE[x.state], pct = x.targets ? Math.round(x.seen / x.targets * 100) : 0;
            return `<div class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-100 p-3 hover:border-violet-200 bg-white">
                <div style="width:46px;height:46px;border-radius:15px;background:${t.bg};display:flex;align-items:center;justify-content:center;font-size:22px;flex:none">${esc(x.icon || t.icon)}</div>
                <div class="flex-1 min-w-[200px]"><b class="text-[13px] text-slate-800">${esc(x.title)}</b> <span class="text-[10px] font-black rounded-full px-2 py-0.5 ${st[1]}">${st[0]}</span>${x.auto ? ' <span class="text-[10px] font-black rounded-full px-2 py-0.5 bg-fuchsia-100 text-fuchsia-700">خودکار</span>' : ''}${x.require_ack ? ' <span class="text-[10px] font-black rounded-full px-2 py-0.5 bg-indigo-100 text-indigo-700">نیازِ به «خواندم»</span>' : ''}
                  <p class="text-[10.5px] text-slate-500 mt-0.5">${esc(t.name)}${x.extra && x.extra.version ? ' <span dir="ltr">' + esc(x.extra.version) + '</span> · ' + fa((x.extra.items || []).length) + ' مورد' : ''} · ${esc(audText(x.audience))} · ${fa(x.start || x.created)}${x.end ? ' تا ' + fa(x.end) : ''}${x.from ? ' · ' + esc(x.from) : ''}</p></div>
                <div class="w-40"><div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-full bg-gradient-to-l from-violet-500 to-fuchsia-500" style="width:${pct}%"></div></div>
                  <small class="text-[10px] text-slate-500">دیده: ${fa(x.seen)} از ${fa(x.targets)}${x.require_ack ? ' · پذیرفته: ' + fa(x.acked) : ''}</small></div>
                <div class="flex flex-wrap gap-1">
                  <button type="button" class="text-[11px] font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg px-2.5 py-1" data-pv="${x.id}"><i class="fas fa-eye"></i></button>
                  <button type="button" class="text-[11px] font-bold text-violet-700 bg-violet-50 hover:bg-violet-100 rounded-lg px-2.5 py-1" data-rd="${x.id}">چه کسانی دیدند</button>
                  ${d.can.edit ? `<button type="button" class="text-[11px] font-bold text-blue-700 bg-blue-50 rounded-lg px-2.5 py-1" data-ed="${x.id}">ویرایش</button>
                  <button type="button" class="text-[11px] font-bold text-emerald-700 bg-emerald-50 rounded-lg px-2.5 py-1" data-rs="${x.id}" title="همه دوباره ببینند">دوباره</button>
                  <button type="button" class="text-[11px] font-bold ${x.active ? 'text-amber-700 bg-amber-50' : 'text-emerald-700 bg-emerald-50'} rounded-lg px-2.5 py-1" data-tg="${x.id}">${x.active ? 'توقف' : 'فعال'}</button>` : ''}
                  ${d.can.delete ? `<button type="button" class="text-[11px] font-bold text-rose-600 bg-rose-50 rounded-lg px-2.5 py-1" data-dl="${x.id}"><i class="fas fa-trash"></i></button>` : ''}</div></div>`; }).join('')}</div>`
            : '<p class="text-center text-xs text-slate-400 py-8">اعلانی نیست.</p>';
        const find = id => d.items.find(x => x.id === +id);
        box.querySelectorAll('[data-pv]').forEach(b => b.onclick = () => window.Announce.preview(find(b.dataset.pv)));
        box.querySelectorAll('[data-rd]').forEach(b => b.onclick = () => readers(find(b.dataset.rd)));
        box.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => {
            const x = find(b.dataset.ed);
            S.form = {id: x.id, template: x.template, title: x.title, body: x.body, icon: x.icon, button: x.button, require_ack: x.require_ack,
                      audience: Object.assign({roles: [], users: []}, x.audience), start: x.start, end: x.end,
                      release: x.extra ? {version: x.extra.version || '', items: (x.extra.items || []).map(i => i.id), view: ''} : {version: '', items: [], view: ''}};
            S.touched = true; redrawComposer();
            S.root.querySelector('[data-comp]').scrollIntoView({behavior: 'smooth', block: 'start'});
        });
        box.querySelectorAll('[data-rs]').forEach(b => b.onclick = async () => { const r = await api('resend', {id: +b.dataset.rs}); toast(r.ok ? 'دوباره برای همه نمایش داده می‌شود.' : r.error, r.ok ? 'success' : 'error'); render(S.root); });
        box.querySelectorAll('[data-tg]').forEach(b => b.onclick = async () => { const x = find(b.dataset.tg); await api('toggle', {id: x.id, active: x.active ? 0 : 1}); render(S.root); });
        box.querySelectorAll('[data-dl]').forEach(b => b.onclick = async () => {
            if (window.uiConfirm && !(await uiConfirm('حذفِ اعلان', 'این اعلان و آمارِ دیده‌شدنش حذف شود؟', {ok: 'حذف', danger: true}))) return;
            await api('delete', {id: +b.dataset.dl}); render(S.root);
        });
    }
    async function readers(x) {
        const r = await api('reads', {id: x.id});
        if (!r.ok) return toast(r.error, 'error');
        const box = document.createElement('div');
        box.className = 'modal-overlay active';
        const seen = r.rows.filter(y => y.seen).length;
        box.innerHTML = `<div class="modal-content w-full max-w-lg p-6 relative" style="max-height:88vh;overflow:auto">
            <button type="button" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl" data-x><i class="fas fa-times"></i></button>
            <h3 class="font-black text-base mb-1">«${esc(x.title)}»</h3><p class="text-[11px] text-slate-500 mb-3">${fa(seen)} از ${fa(r.rows.length)} نفر دیده‌اند${r.require_ack ? ' · ' + fa(r.rows.filter(y => y.ack).length) + ' نفر پذیرفته‌اند' : ''}.</p>
            <table class="w-full text-xs"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-2 text-right">نام</th><th class="p-2 text-right">دید</th>${r.require_ack ? '<th class="p-2 text-right">پذیرفت</th>' : ''}</tr></thead><tbody>
            ${r.rows.map(y => `<tr class="border-t border-slate-100"><td class="p-2 font-bold">${esc(y.name)} <small class="text-slate-400 font-normal">${esc(y.kind)}</small></td><td class="p-2">${y.seen ? '<span class="text-emerald-600">✓ ' + fa(y.seen) + '</span>' : '<span class="text-slate-300">هنوز ندیده</span>'}</td>${r.require_ack ? `<td class="p-2">${y.ack ? '<span class="text-indigo-600">✓ ' + fa(y.ack) + '</span>' : '—'}</td>` : ''}</tr>`).join('')}</tbody></table></div>`;
        document.body.appendChild(box);
        box.querySelector('[data-x]').onclick = () => box.remove();
        box.addEventListener('click', e => { if (e.target === box) box.remove(); });
    }
    // ---------------- تولدها ----------------
    async function drawBday() {
        const box = S.root.querySelector('[data-bday]');
        const r = await api('bday_get');
        if (!r.ok) { box.innerHTML = ''; return; }
        const s = r.settings, ed = S.data.can.edit;
        const todayList = r.users.filter(u => u.md === r.today_md);
        box.innerHTML = `<div class="flex flex-wrap items-center gap-2 mb-3"><h3 class="font-black text-slate-700 text-sm"><i class="fas fa-cake-candles text-fuchsia-500 ml-1"></i>اعلانِ خودکارِ تولد</h3>
                ${todayList.length ? `<span class="text-[11px] font-black text-fuchsia-700 bg-fuchsia-50 rounded-full px-3 py-1">🎂 امروز: ${todayList.map(u => esc(u.name)).join('، ')}</span>` : ''}
                <button type="button" class="mr-auto text-[11px] font-bold text-fuchsia-700 bg-fuchsia-50 rounded-lg px-3 py-1" data-a="pv">پیش‌نمایشِ طرحِ تولد</button></div>
            <div class="grid lg:grid-cols-2 gap-4">
              <div class="rounded-2xl border border-fuchsia-100 bg-fuchsia-50/30 p-3"><label class="flex items-center gap-2 text-xs font-black text-slate-700 mb-2"><input type="checkbox" class="accent-fuchsia-600 w-4 h-4" data-s="ann_bday_self" ${s.ann_bday_self === '1' ? 'checked' : ''}>برای خودِ شخص، روزِ تولدش</label>
                <input class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs mb-1.5" data-s="ann_bday_self_title" value="${esc(s.ann_bday_self_title)}">
                <textarea class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs leading-6" rows="4" data-s="ann_bday_self_body">${esc(s.ann_bday_self_body)}</textarea></div>
              <div class="rounded-2xl border border-violet-100 bg-violet-50/30 p-3"><label class="flex items-center gap-2 text-xs font-black text-slate-700 mb-2"><input type="checkbox" class="accent-violet-600 w-4 h-4" data-s="ann_bday_all" ${s.ann_bday_all === '1' ? 'checked' : ''}>برای بقیه‌ی همکاران (اگر شخص اجازه داده باشد)</label>
                <input class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs mb-1.5" data-s="ann_bday_all_title" value="${esc(s.ann_bday_all_title)}">
                <textarea class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs leading-6" rows="4" data-s="ann_bday_all_body">${esc(s.ann_bday_all_body)}</textarea></div>
            </div>
            <p class="text-[10.5px] text-slate-400 mt-1">«{name}» با نامِ همکار جایگزین می‌شود. هر کاربر تاریخِ تولدش را در «جعبه‌ابزارِ من ← ظاهر» هم می‌تواند ثبت کند.</p>
            <details class="mt-3"><summary class="cursor-pointer text-xs font-black text-slate-600"><i class="fas fa-users ml-1 text-violet-500"></i>تاریخِ تولدِ همکاران (${fa(r.users.filter(u => u.md).length)} از ${fa(r.users.length)} ثبت شده)</summary>
              <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-1.5 mt-2">${r.users.map(u => `<div class="flex items-center gap-2 rounded-xl border border-slate-100 bg-white px-3 py-1.5" data-u="${u.id}">
                <span class="flex-1 text-[11.5px] font-bold text-slate-700 truncate">${esc(u.name)} <small class="text-[9.5px] text-slate-400">${esc(u.role)}</small></span>
                <input class="w-20 border border-slate-200 rounded-lg px-2 py-1 text-[11px] text-center" dir="ltr" placeholder="ماه/روز" value="${esc(fa(u.md))}" data-md ${ed ? '' : 'disabled'}>
                <label title="همکاران ببینند" class="text-[10px] text-slate-500 flex items-center gap-1"><input type="checkbox" class="accent-violet-600" data-show ${u.show ? 'checked' : ''} ${ed ? '' : 'disabled'}>همه</label></div>`).join('')}</div></details>
            ${ed ? '<button type="button" class="mt-3 bg-fuchsia-600 hover:bg-fuchsia-700 text-white text-xs font-black px-5 py-2.5 rounded-xl" data-a="save"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تنظیماتِ تولد</button>' : ''}`;
        box.querySelector('[data-a="pv"]').onclick = () => window.Announce.preview({template: 'birthday', title: box.querySelector('[data-s="ann_bday_self_title"]').value.replace('{name}', 'علی رضایی'), body: box.querySelector('[data-s="ann_bday_self_body"]').value.replace(/\{name\}/g, 'علی رضایی'), button: 'ممنونم 🥳', from: 'بیمه با ما'});
        const sv = box.querySelector('[data-a="save"]');
        if (sv) sv.onclick = async () => {
            const settings = {};
            box.querySelectorAll('[data-s]').forEach(i => { settings[i.dataset.s] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; });
            const users = [...box.querySelectorAll('[data-u]')].map(row => ({id: +row.dataset.u, md: en(row.querySelector('[data-md]').value.trim()), show: row.querySelector('[data-show]').checked ? 1 : 0}));
            const rr = await api('bday_save', {settings, users});
            toast(rr.ok ? 'تنظیماتِ تولد ذخیره شد.' : rr.error, rr.ok ? 'success' : 'error');
            if (rr.ok) drawBday();
        };
    }
    // ---------------- پیامِ صبح‌بخیر (اولین ورودِ هر روز) ----------------
    const MTHEMES = {auto: ['خودکار (صبح/ظهر)', 'linear-gradient(120deg,#f59e0b,#ec4899 50%,#0ea5e9)'], sunrise: ['طلوع', 'linear-gradient(120deg,#f59e0b,#ec4899 60%,#6366f1)'], ocean: ['اقیانوس', 'linear-gradient(120deg,#0ea5e9,#6366f1)'],
                     violet: ['بنفش', 'linear-gradient(120deg,#7c3aed,#db2777)'], emerald: ['زمردی', 'linear-gradient(120deg,#059669,#0e7490)'], night: ['شب', 'linear-gradient(120deg,#0f172a,#334155 60%,#4338ca)']};
    const MITEMS = [['show_date', 'fa-calendar-day', 'تاریخ و روزِ هفته'], ['occasion', 'fa-star', 'مناسبتِ امروز'], ['birthdays', 'fa-cake-candles', 'تولدِ همکاران (تا ۳ روزِ دیگر)'],
                    ['todos', 'fa-list-check', 'کارهای امروز و مانده'], ['installments', 'fa-sack-dollar', 'اقساطِ سررسید و گذشته (برای کسی که دسترسی دارد)'], ['unread', 'fa-comments', 'پیام‌های خوانده‌نشده'],
                    ['leave', 'fa-umbrella-beach', 'مانده‌ی مرخصی'], ['emoji', 'fa-sun', 'شکلکِ ☀️ کنارِ سلام'], ['btn_music', 'fa-music', 'دکمه‌ی «یک آهنگِ آرام»'], ['btn_todo', 'fa-list', 'دکمه‌ی «کارهای امروز»']];
    async function drawMorning() {
        const box = S.root.querySelector('[data-morning]');
        if (!box) return;
        const r = await api('morning_get');
        if (!r.ok) { box.innerHTML = ''; return; }
        const c = r.cfg, ed = S.data.can.edit, dis = ed ? '' : 'disabled';
        const inp = (k, ph, cls = '') => `<input class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs ${cls}" data-m="${k}" value="${esc(c[k])}" placeholder="${esc(ph || '')}" ${dis}>`;
        const num = (k, max) => `<input class="w-16 border border-slate-200 rounded-xl px-2 py-1.5 text-xs text-center" data-m="${k}" value="${fa(c[k])}" inputmode="numeric" data-max="${max}" ${dis}>`;
        box.innerHTML = `<div class="flex flex-wrap items-center gap-2 mb-3"><h3 class="font-black text-slate-700 text-sm"><i class="fas fa-sun text-amber-500 ml-1"></i>پیامِ صبح‌بخیر <small class="text-slate-400 font-bold">(اولین ورودِ هر روز)</small></h3>
                <label class="flex items-center gap-2 text-xs font-black ${c.enabled ? 'text-emerald-700' : 'text-slate-500'} bg-slate-50 rounded-full px-3 py-1 mr-2"><input type="checkbox" class="accent-emerald-600 w-4 h-4" data-m="enabled" ${c.enabled ? 'checked' : ''} ${dis}>فعال</label>
                <button type="button" class="mr-auto text-[11px] font-bold text-amber-700 bg-amber-50 rounded-lg px-3 py-1" data-a="pv"><i class="fas fa-eye ml-1"></i>پیش‌نمایش</button></div>
            <div class="grid lg:grid-cols-3 gap-4">
              <div class="space-y-2">
                <p class="text-[11px] font-black text-slate-500">چه چیزهایی نوشته شود</p>
                ${MITEMS.map(([k, ic, t]) => `<label class="flex items-center gap-2 rounded-xl border border-slate-100 bg-white px-3 py-1.5 text-xs font-bold text-slate-700"><input type="checkbox" class="accent-amber-500 w-4 h-4" data-m="${k}" ${c[k] ? 'checked' : ''} ${dis}><i class="fas ${ic} text-amber-500 w-4 text-center"></i>${t}</label>`).join('')}
              </div>
              <div class="space-y-2">
                <p class="text-[11px] font-black text-slate-500">متن‌ها</p>
                <div class="grid grid-cols-3 gap-1.5"><label class="text-[10px] font-bold text-slate-500">صبح (تا ۱۱)${inp('g_morning')}</label><label class="text-[10px] font-bold text-slate-500">ظهر (تا ۱۵)${inp('g_noon')}</label><label class="text-[10px] font-bold text-slate-500">بعدازظهر${inp('g_evening')}</label></div>
                <label class="block text-[10px] font-bold text-slate-500">جمله‌ی زیرِ سلام${inp('subtitle', 'خالی = بدونِ جمله')}</label>
                <label class="block text-[10px] font-bold text-slate-500">پیامِ روز (اختیاری؛ برای همه بالای فهرست)<textarea class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs leading-6" rows="3" data-m="note" placeholder="مثلاً: جلسه‌ی ساعت ۱۰ در اتاقِ مدیریت" ${dis}>${esc(c.note)}</textarea></label>
                <label class="block text-[10px] font-bold text-slate-500">وقتی چیزی برای گفتن نیست${inp('empty_text')}</label>
                <label class="block text-[10px] font-bold text-slate-500">متنِ دکمه‌ی اصلی${inp('start_text')}</label>
              </div>
              <div class="space-y-3">
                <p class="text-[11px] font-black text-slate-500">کی و چطور نشان داده شود</p>
                <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">از ساعت ${num('from', 23)} تا ساعت ${num('to', 24)}</div>
                <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">با ${num('delay', 60)} ثانیه تأخیر بعد از ورود</div>
                <select class="w-full border border-slate-200 rounded-xl px-2 py-2 text-xs font-bold" data-m="days" ${dis}><option value="all" ${c.days === 'all' ? 'selected' : ''}>همه‌ی روزها</option><option value="work" ${c.days === 'work' ? 'selected' : ''}>فقط روزهای کاری (نه تعطیلات)</option></select>
                <select class="w-full border border-slate-200 rounded-xl px-2 py-2 text-xs font-bold" data-m="audience" ${dis}><option value="all" ${c.audience === 'all' ? 'selected' : ''}>برای همه (پنل و شرکت‌ها)</option><option value="staff" ${c.audience === 'staff' ? 'selected' : ''}>فقط کاربرانِ پنل</option><option value="company" ${c.audience === 'company' ? 'selected' : ''}>فقط کاربرانِ شرکت‌ها</option></select>
                <div><p class="text-[10.5px] font-bold text-slate-500 mb-1">رنگِ سربرگ</p><div class="grid grid-cols-3 gap-1.5" data-themes>${Object.entries(MTHEMES).map(([k, [t, g]]) => `<button type="button" data-th="${k}" class="rounded-xl overflow-hidden border-2 ${c.theme === k ? 'border-amber-500' : 'border-transparent'}" ${dis}><div style="height:26px;background:${g}"></div><div class="text-[10px] font-black text-slate-600 py-0.5 bg-white">${t}</div></button>`).join('')}</div></div>
                <p class="text-[10.5px] text-slate-400 leading-5">هر کاربر روزی یک بار، در اولین ورود داخلِ این بازه، این پیام را می‌بیند. خاموش/روشن بودنش برای هر نفر هم در «امکاناتِ رفاهی» است.</p>
              </div>
            </div>
            ${ed ? '<button type="button" class="mt-3 bg-amber-500 hover:bg-amber-600 text-white text-xs font-black px-5 py-2.5 rounded-xl" data-a="save"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی پیامِ صبح‌بخیر</button>' : ''}`;
        let theme = c.theme;
        box.querySelectorAll('[data-th]').forEach(b => b.onclick = () => { theme = b.dataset.th; box.querySelectorAll('[data-th]').forEach(x => x.classList.toggle('border-amber-500', x === b)); box.querySelectorAll('[data-th]').forEach(x => x.classList.toggle('border-transparent', x !== b)); });
        const read = () => {
            const o = {theme};
            box.querySelectorAll('[data-m]').forEach(i => { o[i.dataset.m] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : (i.dataset.max ? +en(i.value) || 0 : i.value); });
            return o;
        };
        box.querySelector('[data-a="pv"]').onclick = () => { if (window.CF && CF.morningPreview) CF.morningPreview(read()); else toast('پیش‌نمایش وقتی «امکاناتِ رفاهی» برایتان فعال است کار می‌کند.', 'info'); };
        const sv = box.querySelector('[data-a="save"]');
        if (sv) sv.onclick = async () => {
            const rr = await api('morning_save', {cfg: read()});
            toast(rr.ok ? 'پیامِ صبح‌بخیر ذخیره شد؛ از ورودِ بعدی با همین تنظیم نشان داده می‌شود.' : rr.error, rr.ok ? 'success' : 'error');
            if (rr.ok) drawMorning();
        };
    }
    window.AnnounceAdmin = {render};
})();
