// فایل: comfort-admin.js
// بخشِ مدیرِ «امکاناتِ رفاهی»: کتابخانه‌ی موسیقی در «تنظیمات» (آپلودِ تکی و زیپ، آهنگ‌های کاربران با نامِ آپلودکننده،
// ویرایش، انتقال به پنل، حذف و مسدودسازی، سقفِ حجم و ریستِ آن، ژانرها) و روشن/خاموش کردنِ امکانات برای هر کاربر.
(function () {
    'use strict';
    const API = 'api/comfort_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const mb = b => fa((Number(b || 0) / 1048576).toFixed(1));
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : alert(m));
    async function api(action, body = {}) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور'}; }
    }
    const jdate = s => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); if (!m) return ''; try { return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date(+m[1], +m[2] - 1, +m[3], 12)); } catch (e) { return fa(m[0]); } };

    // ================= کتابخانه‌ی موسیقی (تنظیمات) =================
    const L = {tab: 'tracks', owner: 'all', genre: '', q: '', uploader: '', data: null, sel: new Set(), audio: null};
    async function renderLibrary(el) {
        if (!el) return;
        L.el = el;
        el.innerHTML = '<p class="text-xs text-slate-400">در حالِ بارگذاری…</p>';
        const d = await api('lib_list', {owner: L.owner, genre: L.genre, q: L.q, uploader: L.uploader});
        if (!d.ok) { el.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error || 'خطا')}</p>`; return; }
        L.data = d;
        draw();
    }
    function draw() {
        const d = L.data, el = L.el;
        const tabs = [['tracks', 'fa-music', 'آهنگ‌ها'], ['quota', 'fa-hard-drive', 'سقفِ حجمِ کاربران'], ['blocked', 'fa-ban', `مسدودشده‌ها (${fa(d.blocked)})`], ['settings', 'fa-sliders', 'ژانرها و تنظیمات']];
        el.innerHTML = `
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-3">
                ${[['کتابخانه‌ی پنل', fa(d.stat.panel.n) + ' آهنگ', mb(d.stat.panel.s) + ' مگابایت', '#6366f1', 'fa-compact-disc'], ['آهنگ‌های کاربران', fa(d.stat.user.n) + ' آهنگ', mb(d.stat.user.s) + ' مگابایت', '#0ea5e9', 'fa-users'],
                   ['سقفِ هر نفر', fa(d.quota_mb) + ' مگابایت', 'قابلِ تغییر و ریست', '#10b981', 'fa-gauge'], ['مسدودشده', fa(d.blocked) + ' فایل', 'دیگر آپلود نمی‌شوند', '#e11d48', 'fa-ban']]
                    .map(([a, b, c, col, ic]) => `<div class="rounded-2xl border border-slate-100 bg-white p-3 relative"><i class="fas ${ic} absolute left-3 top-3 text-sm" style="color:${col}"></i><p class="text-[10.5px] font-bold text-slate-500">${a}</p><b class="block text-base font-black text-slate-800">${b}</b><small class="text-[10px] text-slate-400">${c}</small></div>`).join('')}
            </div>
            <div class="flex flex-wrap gap-1 mb-3 bg-slate-100 rounded-2xl p-1 w-fit">${tabs.map(([k, ic, t]) => `<button type="button" data-tab="${k}" class="text-[11.5px] font-black px-3 py-1.5 rounded-xl ${L.tab === k ? 'bg-white text-violet-700 shadow' : 'text-slate-500'}"><i class="fas ${ic} ml-1"></i>${t}</button>`).join('')}</div>
            <div data-pane></div>`;
        el.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => { L.tab = b.dataset.tab; draw(); });
        const pane = el.querySelector('[data-pane]');
        ({tracks: paneTracks, quota: paneQuota, blocked: paneBlocked, settings: paneSettings})[L.tab](pane);
    }
    function paneTracks(p) {
        const d = L.data;
        p.innerHTML = `
            <div class="rounded-2xl border-2 border-dashed border-violet-200 bg-violet-50/40 p-4 mb-3" data-drop>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-[220px]"><b class="text-[13px] text-slate-700"><i class="fas fa-cloud-arrow-up text-violet-500 ml-1"></i>آپلود در کتابخانه‌ی پنل</b>
                        <p class="text-[10.5px] text-slate-500 mt-1 leading-6">آهنگ‌ها را تکی (MP3، M4A، OGG، WAV، FLAC…) یا یک‌جا در فایلِ <b>ZIP</b> بکشید یا انتخاب کنید. زیپ خودکار باز می‌شود، هر آهنگ جدا ذخیره و نامش «خواننده - نامِ آهنگ» مرتب می‌شود (از برچسبِ آهنگ یا نامِ فایل). اگر داخلِ زیپ پوشه‌هایی مثلِ «شاد» یا «بی‌کلام» باشد و ژانر «خودکار» باشد، نامِ پوشه ژانر می‌شود.</p></div>
                    <select class="border border-slate-200 rounded-xl px-3 py-2 text-xs bg-white" data-ug><option value="auto">ژانر: خودکار (پوشه / برچسب)</option>${d.genres.map(g => `<option>${esc(g)}</option>`).join('')}</select>
                    <label class="cursor-pointer bg-violet-600 hover:bg-violet-700 text-white text-xs font-black px-4 py-2.5 rounded-xl"><i class="fas fa-upload ml-1"></i>انتخابِ فایل یا زیپ<input type="file" multiple hidden accept="audio/*,.mp3,.m4a,.ogg,.wav,.flac,.aac,.opus,.zip" data-uf></label>
                </div>
                <div data-prog class="text-[11px] text-slate-600"></div>
            </div>
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <select class="border border-slate-200 rounded-xl px-2 py-1.5 text-xs" data-f="owner"><option value="all">همه</option><option value="panel" ${L.owner === 'panel' ? 'selected' : ''}>کتابخانه‌ی پنل</option><option value="user" ${L.owner === 'user' ? 'selected' : ''}>آپلودِ کاربران (منتظرِ انتشار)</option></select>
                <select class="border border-slate-200 rounded-xl px-2 py-1.5 text-xs" data-f="genre"><option value="">همه‌ی ژانرها</option>${d.genres.map(g => `<option ${L.genre === g ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select>
                <input class="border border-slate-200 rounded-xl px-3 py-1.5 text-xs w-52" data-f="q" placeholder="جستجوی نام، خواننده، فایل…" value="${esc(L.q)}">
                <span class="text-[11px] text-slate-500 mr-auto">${fa(d.tracks.length)} آهنگ</span>
                ${[...L.sel].some(id => (d.tracks.find(t => t.id === id) || {}).owner === 'user') ? `<button type="button" class="text-[11px] font-black text-emerald-700 bg-emerald-50 hover:bg-emerald-100 rounded-xl px-3 py-1.5" data-a="bulkpub"><i class="fas fa-bullhorn ml-1"></i>انتشارِ انتخاب‌شده‌ها برای همه</button>` : ''}
                <button type="button" class="text-[11px] font-black text-rose-600 bg-rose-50 hover:bg-rose-100 rounded-xl px-3 py-1.5 ${L.sel.size ? '' : 'opacity-40 pointer-events-none'}" data-a="bulkdel"><i class="fas fa-trash ml-1"></i>حذف و مسدودِ انتخاب‌شده‌ها (${fa(L.sel.size)})</button>
            </div>
            <div class="overflow-x-auto border border-slate-100 rounded-2xl">
            <table class="w-full text-xs min-w-[860px]"><thead class="bg-slate-50 text-slate-500 text-[10.5px]"><tr>
                <th class="p-2 w-8"><input type="checkbox" data-all></th><th class="p-2 text-right">آهنگ</th><th class="p-2 text-right">ژانر</th><th class="p-2 text-right">کجا / آپلودکننده</th><th class="p-2 text-right">حجم</th><th class="p-2 text-right">تاریخ</th><th class="p-2 text-right">پخش</th><th class="p-2"></th></tr></thead>
            <tbody>${d.tracks.length ? d.tracks.map(t => `<tr class="border-t border-slate-100 hover:bg-slate-50/60">
                <td class="p-2 text-center"><input type="checkbox" data-sel="${t.id}" ${L.sel.has(t.id) ? 'checked' : ''}></td>
                <td class="p-2"><div class="flex items-center gap-2"><button type="button" class="w-7 h-7 rounded-full bg-violet-100 text-violet-700 shrink-0" data-play="${t.id}" title="پیش‌شنود"><i class="fas fa-play text-[10px]"></i></button>
                    <div class="min-w-0"><b class="block text-slate-800 truncate max-w-[260px]">${esc(t.title)}</b><small class="text-slate-500">${esc(t.artist || '—')}</small>${t.orig_name ? `<small class="block text-[9.5px] text-slate-300 truncate max-w-[260px]" dir="auto">${esc(t.orig_name)}</small>` : ''}</div></div></td>
                <td class="p-2"><span class="text-[10.5px] font-bold bg-slate-100 rounded-full px-2 py-0.5">${esc(t.genre)}</span></td>
                <td class="p-2">${t.owner === 'panel' ? '<span class="text-[10.5px] font-black text-violet-700"><i class="fas fa-compact-disc ml-1"></i>کتابخانه‌ی پنل</span>' : `<span class="text-[10.5px] font-black text-sky-700"><i class="fas fa-user ml-1"></i>${esc(t.owner_name)}</span>`}
                    <small class="block text-[10px] text-slate-400">آپلود: ${esc(t.uploader || '—')}</small>${t.owner !== 'panel' ? '<small class="block text-[10px] text-amber-600 font-bold"><i class="fas fa-lock ml-1"></i>فقط خودش و مدیر</small>' : ''}</td>
                <td class="p-2 text-slate-500">${mb(t.size)} مگ</td><td class="p-2 text-slate-500">${jdate(t.created_at)}</td><td class="p-2 text-slate-500">${fa(t.plays)}</td>
                <td class="p-2 whitespace-nowrap"><button type="button" class="text-blue-600 text-[11px] font-bold ml-2" data-edit="${t.id}"><i class="fas fa-pen ml-1"></i>ویرایش</button>
                    ${t.owner !== 'panel' ? `<button type="button" class="text-emerald-700 bg-emerald-50 rounded-lg px-2 py-0.5 text-[11px] font-black ml-2" data-topanel="${t.id}" title="الان فقط خودِ ${esc(t.owner_name)} و مدیر می‌شنوند"><i class="fas fa-bullhorn ml-1"></i>انتشار برای همه</button>` : ''}
                    <button type="button" class="text-rose-500 text-[11px] font-bold" data-del="${t.id}"><i class="fas fa-ban ml-1"></i>حذف و مسدود</button></td></tr>`).join('')
                : '<tr><td colspan="8" class="p-8 text-center text-slate-400">آهنگی نیست.</td></tr>'}</tbody></table></div>
            <p class="text-[10.5px] text-slate-500 mt-2"><i class="fas fa-circle-info text-violet-500 ml-1"></i>«حذف و مسدود» آهنگ را از همه جا (کتابخانه‌ی پنل و آهنگ‌های همه‌ی کاربران) پاک می‌کند و همان فایل دیگر قابلِ آپلود نیست؛ از تبِ «مسدودشده‌ها» می‌شود آزادش کرد.</p>`;
        const f = k => p.querySelector(`[data-f="${k}"]`);
        f('owner').onchange = () => { L.owner = f('owner').value; L.sel.clear(); renderLibrary(L.el); };
        f('genre').onchange = () => { L.genre = f('genre').value; L.sel.clear(); renderLibrary(L.el); };
        let tm; f('q').oninput = () => { clearTimeout(tm); tm = setTimeout(() => { L.q = f('q').value.trim(); renderLibrary(L.el); }, 400); };
        // پیشرفت از مدیرِ آپلود (comfort.js): با رفتن به تبِ دیگر آپلود ادامه دارد و با برگشتن همین‌جا دیده می‌شود
        if (window.CF && CF.upMount) CF.upMount(p.querySelector('[data-prog]'), 'panel', false);
        if (!L.upHook) { L.upHook = 1; window.addEventListener('cf-upload-done', () => { if (L.el && L.el.isConnected && L.tab === 'tracks') renderLibrary(L.el); }); }
        const up = async files => {
            if (!files.length || !window.CF || !CF.upAdd) { toast('ابزارِ آپلود آماده نیست؛ صفحه را تازه کنید.', 'error'); return; }
            const res = await CF.upAdd(files, 'panel', p.querySelector('[data-ug]').value);
            if (!res.added.length) toast(res.errors.length ? 'آهنگی اضافه نشد؛ جزئیات زیرِ نوارِ آپلود.' : 'آهنگِ تازه‌ای اضافه نشد (تکراری/نامربوط).', 'warning');
        };
        p.querySelector('[data-uf]').onchange = e => { const fs = [...e.target.files]; e.target.value = ''; up(fs); };
        const dz = p.querySelector('[data-drop]');
        dz.ondragover = e => { e.preventDefault(); dz.classList.add('bg-violet-100'); };
        dz.ondragleave = () => dz.classList.remove('bg-violet-100');
        dz.ondrop = e => { e.preventDefault(); dz.classList.remove('bg-violet-100'); up([...e.dataTransfer.files]); };
        p.querySelector('[data-all]').onchange = e => { d.tracks.forEach(t => e.target.checked ? L.sel.add(t.id) : L.sel.delete(t.id)); draw(); };
        p.querySelectorAll('[data-sel]').forEach(c => c.onchange = () => { c.checked ? L.sel.add(+c.dataset.sel) : L.sel.delete(+c.dataset.sel); draw(); });
        p.querySelectorAll('[data-play]').forEach(b => b.onclick = () => {
            const id = +b.dataset.play;
            if (!L.audio) L.audio = new Audio();
            if (L.playing === id && !L.audio.paused) { L.audio.pause(); b.innerHTML = '<i class="fas fa-play text-[10px]"></i>'; return; }
            p.querySelectorAll('[data-play]').forEach(x => { x.innerHTML = '<i class="fas fa-play text-[10px]"></i>'; });
            L.audio.src = API + '?action=stream&id=' + id; L.audio.play().catch(() => toast('پخش نشد.', 'error')); L.playing = id;
            b.innerHTML = '<i class="fas fa-pause text-[10px]"></i>';
        });
        p.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => editTrack(d.tracks.find(t => t.id === +b.dataset.edit)));
        p.querySelectorAll('[data-topanel]').forEach(b => b.onclick = async () => {
            const t = d.tracks.find(x => x.id === +b.dataset.topanel);
            if (window.uiConfirm && !(await uiConfirm('انتشار برای همه', `«${t.title}» (آپلودِ ${t.owner_name}) به کتابخانه‌ی پنل برود تا برای همه پخش شود؟`))) return;
            const r = await api('lib_update', {id: t.id, title: t.title, artist: t.artist, genre: t.genre, to_panel: 1});
            toast(r.ok ? 'منتشر شد؛ حالا برای همه پخش می‌شود.' : r.error, r.ok ? 'success' : 'error'); renderLibrary(L.el);
        });
        const bp = p.querySelector('[data-a="bulkpub"]');
        if (bp) bp.onclick = async () => {
            const ids = [...L.sel].filter(id => (d.tracks.find(t => t.id === id) || {}).owner === 'user');
            if (window.uiConfirm && !(await uiConfirm('انتشار برای همه', `${fa(ids.length)} آهنگِ کاربران برای همه پخش شوند؟`))) return;
            const r = await api('music_publish', {ids});
            toast(r.ok ? `${fa(r.published)} آهنگ منتشر شد.` : r.error, r.ok ? 'success' : 'error'); L.sel.clear(); renderLibrary(L.el);
        };
        const del = async ids => {
            if (window.uiConfirm && !(await uiConfirm('حذف و مسدودسازی', `${fa(ids.length)} آهنگ از همه جا حذف و مسدود شود؟ دیگر قابلِ آپلود نخواهد بود.`, {ok: 'حذف و مسدود', danger: true}))) return;
            const r = await api('lib_delete', {ids});
            toast(r.ok ? `${fa(r.deleted)} آهنگ حذف و مسدود شد.` : r.error, r.ok ? 'success' : 'error');
            L.sel.clear(); renderLibrary(L.el);
        };
        p.querySelectorAll('[data-del]').forEach(b => b.onclick = () => del([+b.dataset.del]));
        p.querySelector('[data-a="bulkdel"]').onclick = () => del([...L.sel]);
    }
    function editTrack(t) {
        const box = document.createElement('div');
        box.className = 'modal-overlay active';
        box.innerHTML = `<div class="modal-content w-full max-w-md p-6 relative"><h3 class="font-black text-base mb-3"><i class="fas fa-pen text-violet-600 ml-1"></i>ویرایشِ آهنگ</h3>
            <label class="block text-[11px] font-bold text-slate-500 mb-1">خواننده</label><input class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm mb-2" data-f="artist" value="${esc(t.artist)}">
            <label class="block text-[11px] font-bold text-slate-500 mb-1">نامِ آهنگ</label><input class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm mb-2" data-f="title" value="${esc(t.title)}">
            <label class="block text-[11px] font-bold text-slate-500 mb-1">ژانر</label><select class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm" data-f="genre">${L.data.genres.concat(L.data.genres.includes(t.genre) ? [] : [t.genre]).map(g => `<option ${g === t.genre ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select>
            <div class="flex gap-2 mt-4"><button class="bg-violet-600 text-white text-xs font-black px-5 py-2.5 rounded-xl" data-ok>ذخیره</button><button class="bg-slate-100 text-slate-600 text-xs font-black px-5 py-2.5 rounded-xl" data-x>انصراف</button></div></div>`;
        document.body.appendChild(box);
        box.querySelector('[data-x]').onclick = () => box.remove();
        box.querySelector('[data-ok]').onclick = async () => {
            const v = k => box.querySelector(`[data-f="${k}"]`).value.trim();
            const r = await api('lib_update', {id: t.id, title: v('title'), artist: v('artist'), genre: v('genre')});
            if (!r.ok) return toast(r.error, 'error');
            box.remove(); toast('ذخیره شد.', 'success'); renderLibrary(L.el);
        };
    }
    async function paneQuota(p) {
        p.innerHTML = '<p class="text-xs text-slate-400">…</p>';
        const r = await api('quotas');
        if (!r.ok) { p.innerHTML = `<p class="text-xs text-rose-600">${esc(r.error)}</p>`; return; }
        p.innerHTML = `<p class="text-[11px] text-slate-500 mb-2"><i class="fas fa-circle-info text-violet-500 ml-1"></i>هر نفر تا ${fa(L.data.quota_mb)} مگابایت آپلود می‌کند. «ریستِ سقف» مصرف را صفر می‌کند بی‌آنکه آهنگ‌هایش پاک شود (از آن به بعد دوباره ${fa(L.data.quota_mb)} مگابایت جا دارد).</p>
            <div class="overflow-x-auto border border-slate-100 rounded-2xl"><table class="w-full text-xs min-w-[640px]"><thead class="bg-slate-50 text-slate-500 text-[10.5px]"><tr><th class="p-2 text-right">کاربر</th><th class="p-2 text-right">آهنگ‌ها</th><th class="p-2 text-right">کلِ حجم</th><th class="p-2 text-right">مصرفِ سقف</th><th class="p-2 text-right">آخرین ریست</th><th class="p-2"></th></tr></thead><tbody>
            ${r.rows.length ? r.rows.map(x => { const pct = Math.min(100, x.used / x.limit * 100); return `<tr class="border-t border-slate-100"><td class="p-2"><b>${esc(x.name)}</b> <small class="text-slate-400">${esc(x.kind)}</small></td>
                <td class="p-2">${fa(x.count)}</td><td class="p-2">${mb(x.total)} مگ</td>
                <td class="p-2" style="min-width:170px"><div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-full ${pct >= 100 ? 'bg-rose-500' : pct > 75 ? 'bg-amber-500' : 'bg-emerald-500'}" style="width:${pct}%"></div></div><small class="text-[10px] text-slate-500">${mb(x.used)} از ${mb(x.limit)} مگ${pct >= 100 ? ' · <b class="text-rose-600">پر شده</b>' : ''}</small></td>
                <td class="p-2 text-slate-500">${fa(x.reset_at) || '—'}</td>
                <td class="p-2 whitespace-nowrap"><button type="button" class="text-[11px] font-black text-emerald-700 bg-emerald-50 hover:bg-emerald-100 rounded-lg px-3 py-1" data-reset="${x.type}:${x.id}"><i class="fas fa-rotate-left ml-1"></i>ریستِ سقف</button>
                    <button type="button" class="text-[11px] font-bold text-violet-600 mr-1" data-show="${x.type}${x.id}">آهنگ‌ها</button></td></tr>`; }).join('')
                : '<tr><td colspan="6" class="p-6 text-center text-slate-400">هنوز کاربری آهنگ آپلود نکرده.</td></tr>'}</tbody></table></div>`;
        p.querySelectorAll('[data-reset]').forEach(b => b.onclick = async () => {
            const [type, id] = b.dataset.reset.split(':');
            const rr = await api('quota_reset', {type, id: +id});
            toast(rr.ok ? 'سقفِ حجم ریست شد؛ آهنگ‌ها سرِ جایشان هستند.' : rr.error, rr.ok ? 'success' : 'error');
            paneQuota(p);
        });
        p.querySelectorAll('[data-show]').forEach(b => b.onclick = () => { L.tab = 'tracks'; L.owner = 'user'; L.uploader = b.dataset.show; renderLibrary(L.el); L.uploader = ''; });
    }
    async function paneBlocked(p) {
        const r = await api('lib_blocked');
        p.innerHTML = `<div class="border border-slate-100 rounded-2xl overflow-hidden">${r.ok && r.rows.length ? r.rows.map(x => `<div class="flex items-center gap-2 px-3 py-2 border-b border-slate-50 text-xs">
                <i class="fas fa-ban text-rose-400"></i><b class="flex-1">${esc(x.title || 'بی‌نام')}</b><span class="text-slate-400">${fa(x.at)}</span>
                <button type="button" class="text-[11px] font-bold text-emerald-700 bg-emerald-50 rounded-lg px-3 py-1" data-ub="${x.sha1}">آزاد کردن</button></div>`).join('')
            : '<p class="p-6 text-center text-xs text-slate-400">فایلی مسدود نشده.</p>'}</div>`;
        p.querySelectorAll('[data-ub]').forEach(b => b.onclick = async () => { await api('lib_unblock', {sha1: b.dataset.ub}); toast('آزاد شد؛ دوباره قابلِ آپلود است.', 'success'); L.data.blocked--; draw(); });
    }
    function paneSettings(p) {
        p.innerHTML = `<div class="grid md:grid-cols-2 gap-4">
            <div><label class="block text-[11px] font-bold text-slate-500 mb-1">ژانرها (هر خط یکی)</label><textarea class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm" rows="9" data-f="genres">${esc(L.data.genres.join('\n'))}</textarea>
                <p class="text-[10.5px] text-slate-400 mt-1">«حالتِ تمرکز» آهنگ‌های ژانرهای بی‌کلام، کلاسیک، طبیعت و آرامش و ملایم را پخش می‌کند (با هر املایی، مثلاً «بیکلام» یا «آرامش»).</p></div>
            <div><label class="block text-[11px] font-bold text-slate-500 mb-1">سقفِ آپلودِ هر کاربر (مگابایت)</label><input class="w-40 border border-slate-200 rounded-xl px-3 py-2 text-sm" data-f="quota" value="${fa(L.data.quota_mb)}" inputmode="numeric">
                <p class="text-[10.5px] text-slate-400 mt-2 leading-6">آهنگ‌ها روی همین هاست در <span dir="ltr">uploads/music</span> ذخیره می‌شوند و فقط از داخلِ پنل (با بررسیِ دسترسی) پخش می‌شوند؛ لینکِ مستقیم ندارند. آهنگ‌های شخصیِ هر کاربر را فقط خودش (و مدیر) می‌بیند.</p></div></div>
            <div class="flex flex-wrap gap-2 mt-3"><button type="button" class="bg-violet-600 hover:bg-violet-700 text-white text-xs font-black px-5 py-2.5 rounded-xl" data-a="save"><i class="fas fa-floppy-disk ml-1"></i>ذخیره</button>
              <button type="button" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black px-4 py-2.5 rounded-xl" data-a="regenre" title="آهنگ‌های «سایر» از روی برچسبِ فایل، عنوان، خواننده و نامِ فایل دوباره دسته‌بندی می‌شوند"><i class="fas fa-wand-magic-sparkles ml-1"></i>تشخیصِ دوباره‌ی ژانرِ آهنگ‌های «سایر»</button></div>`;
        p.querySelector('[data-a="regenre"]').onclick = async () => {
            const r = await api('lib_regenre');
            toast(r.ok ? (r.count ? fa(r.count) + ' آهنگ از «سایر» به ژانرِ درست رفت.' : 'آهنگِ دیگری برای جابه‌جایی پیدا نشد؛ ژانرِ بقیه را از «ویرایش» تعیین کنید.') : r.error, r.ok ? 'success' : 'error');
            if (r.ok && r.count) renderLibrary(L.el);
        };
        p.querySelector('[data-a="save"]').onclick = async () => {
            const r = await api('lib_settings', {genres: p.querySelector('[data-f="genres"]').value.split('\n').map(s => s.trim()).filter(Boolean), quota_mb: +en(p.querySelector('[data-f="quota"]').value) || 250});
            toast(r.ok ? 'ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
            if (r.ok) renderLibrary(L.el);
        };
    }

    // ================= روشن/خاموش کردنِ امکانات برای هر نفر =================
    const A = {cur: null, items: null};
    // امکانات و ابزارها در دو گروهِ جدا (هر ابزار جدا روشن/خاموش می‌شود)
    const togglesHtml = items => { const tl = items.filter(i => i.key.indexOf('tool_') === 0), ot = items.filter(i => i.key.indexOf('tool_') !== 0);
        return togglesGrid(ot) + (tl.length ? `<div class="text-[11px] font-black text-slate-500 mt-3 mb-1.5"><i class="fas fa-toolbox text-pink-500 ml-1"></i>ابزارها <small class="font-bold text-slate-400">(«ابزارها» در بالا کلیدِ اصلی است؛ خاموش باشد همه خاموش‌اند)</small></div>` + togglesGrid(tl) : ''); };
    const togglesGrid = items => `<div class="grid sm:grid-cols-2 gap-1.5">${items.map(it => `<label class="flex items-center gap-2 rounded-xl border ${it.changed ? 'border-amber-200 bg-amber-50/40' : 'border-slate-100 bg-white'} px-3 py-2 text-[11.5px] font-bold text-slate-700 cursor-pointer">
        <input type="checkbox" class="accent-violet-600 w-4 h-4" data-cf="${it.key}" ${it.on ? 'checked' : ''}><span class="flex-1">${esc(it.title)}</span>${it.changed ? '<small class="text-[9.5px] text-amber-600">تغییر یافته</small>' : ''}</label>`).join('')}</div>`;
    const collect = root => { const o = {}; root.querySelectorAll('[data-cf]').forEach(c => { o[c.dataset.cf] = c.checked ? 1 : 0; }); return o; };
    // داخلِ فرمِ «دسترسی‌ها»ی کاربرِ پنل: همان ردیف‌های جدولِ دسترسی‌ها (امکاناتِ رفاهی و ابزارها، هر کدام با کلید)
    // «ابزارها» یک کلیدِ اصلی دارد که هم صفحه‌ی ابزارها و هم دکمه‌ی جعبه‌ابزار را باز/بسته می‌کند؛ هر ابزار جدا روشن/خاموش می‌شود.
    const pmRows = items => items.map(it => `<tr data-row="${it.key}"><td class="font-bold text-slate-700">${esc(it.title.replace(/^ابزار:\s*/, ''))}${it.changed ? '<span class="pm-chg">تغییر یافته</span>' : ''}</td>
        <td><input type="checkbox" class="pm-sw" data-cf="${it.key}" ${it.on ? 'checked' : ''}></td></tr>`).join('');
    const pmGrp = (icon, a, b, title, sub, body, key) => `<div class="pm-grp" data-grp="${key}"><h4><span class="pm-ic" style="--a:${a};--b:${b}"><i class="fas ${icon}"></i></span>${title}
        <small data-cnt></small><button type="button" class="pm-row-all mr-auto" data-gon>همه</button><button type="button" class="pm-row-all text-rose-500" data-goff>هیچ</button></h4>
        ${sub ? `<p class="text-[10.5px] text-slate-500 px-3 pt-2">${sub}</p>` : ''}
        <table class="pm-tbl"><thead><tr><th>${key === 'tools' ? 'ابزار' : 'امکان'}</th><th style="width:90px">فعال</th></tr></thead><tbody>${body}</tbody></table></div>`;
    async function permSection(userId) {
        const box = document.getElementById('pm-comfort');
        if (!box) return;
        box.innerHTML = '';
        const r = await api('access_get', {type: 'S', id: userId});
        if (!r.ok) return;
        A.perm = {id: userId, initial: JSON.stringify(collectFrom(r.items))};
        const master = r.items.find(i => i.key === 'tools');
        const tools = r.items.filter(i => i.key.indexOf('tool_') === 0), other = r.items.filter(i => i.key.indexOf('tool_') !== 0 && i.key !== 'tools');
        box.innerHTML = pmGrp('fa-mug-hot', '#d946ef', '#8b5cf6', 'امکاناتِ رفاهی', '', pmRows(other), 'cf')
            + (master ? pmGrp('fa-toolbox', '#db2777', '#7c3aed', 'ابزارها', 'کلیدِ اول هم صفحه‌ی «ابزارها» و هم دکمه‌ی جعبه‌ابزار را باز می‌کند؛ ابزارها را تک‌تک روشن یا خاموش کنید.',
                `<tr data-row="tools" style="background:#faf5ff"><td class="font-black text-violet-700"><i class="fas fa-key ml-1"></i>صفحه‌ی ابزارها و دکمه‌ی جعبه‌ابزار<span class="pm-hint">خاموش باشد، هیچ ابزاری دیده نمی‌شود</span></td>
                    <td><input type="checkbox" class="pm-sw" data-cf="tools" ${master.on ? 'checked' : ''}></td></tr>` + pmRows(tools), 'tools') : '');
        const sync = () => {
            const m = box.querySelector('[data-cf="tools"]');
            box.querySelectorAll('[data-grp="tools"] tr[data-row^="tool_"]').forEach(tr => { tr.classList.toggle('pm-off', !!m && !m.checked); tr.querySelector('input').disabled = !!m && !m.checked; });
            box.querySelectorAll('[data-grp]').forEach(g => { const c = [...g.querySelectorAll('[data-cf]')]; g.querySelector('[data-cnt]').textContent = `${fa(c.filter(x => x.checked).length)} از ${fa(c.length)} روشن`; });
        };
        box.querySelectorAll('[data-cf]').forEach(c => c.addEventListener('change', sync));
        box.querySelectorAll('[data-grp]').forEach(g => {
            g.querySelector('[data-gon]').onclick = () => { g.querySelectorAll('[data-cf]').forEach(c => { c.checked = true; }); sync(); };
            g.querySelector('[data-goff]').onclick = () => { g.querySelectorAll('[data-cf]').forEach(c => { c.checked = false; }); sync(); };
        });
        sync();
    }
    const collectFrom = items => Object.fromEntries(items.map(i => [i.key, i.on ? 1 : 0]));
    async function permSave() {
        const box = document.getElementById('pm-comfort');
        if (!box || !A.perm || !box.querySelector('[data-cf]')) return;
        const cur = collect(box);
        if (JSON.stringify(cur) === A.perm.initial) return;
        const r = await api('access_save', {type: 'S', id: A.perm.id, features: cur});
        if (!r.ok) toast(r.error, 'error');
    }
    // پنجره‌ی جدا (کاربرانِ شرکت، یا هر کاربرِ پنل از فهرستِ کاربران)
    async function openAccess(type, id, name) {
        const r = await api('access_get', {type, id});
        if (!r.ok) return toast(r.error, 'error');
        const box = document.createElement('div');
        box.className = 'modal-overlay active';
        box.innerHTML = `<div class="modal-content w-full max-w-xl p-6 relative" style="max-height:92vh;overflow:auto">
            <button type="button" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl" data-x><i class="fas fa-times"></i></button>
            <h3 class="font-black text-base mb-1"><i class="fas fa-mug-hot text-violet-600 ml-1"></i>امکاناتِ رفاهیِ ${esc(name || '')}</h3>
            <p class="text-[11px] text-slate-500 mb-3 leading-6">${type === 'C' ? 'برای کاربرانِ شرکت‌ها پیش‌فرض همه خاموش است؛ هر کدام را می‌خواهید روشن کنید تا در پنلِ شرکت‌ها برایش دیده شود.' : 'برای کاربرانِ پنل پیش‌فرض همه روشن است؛ هر کدام را نمی‌خواهید خاموش کنید.'}</p>
            <div class="flex gap-2 mb-2"><button type="button" class="text-[11px] font-bold text-violet-600 bg-violet-50 rounded-lg px-3 py-1" data-allon>همه روشن</button><button type="button" class="text-[11px] font-bold text-rose-500 bg-rose-50 rounded-lg px-3 py-1" data-alloff>همه خاموش</button></div>
            ${togglesHtml(r.items)}
            <button type="button" class="w-full mt-4 bg-violet-600 hover:bg-violet-700 text-white text-sm font-black py-2.5 rounded-xl" data-ok><i class="fas fa-floppy-disk ml-1"></i>ذخیره</button></div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.querySelector('[data-x]').onclick = close;
        box.addEventListener('click', e => { if (e.target === box) close(); });
        box.querySelector('[data-allon]').onclick = () => box.querySelectorAll('[data-cf]').forEach(c => { c.checked = true; });
        box.querySelector('[data-alloff]').onclick = () => box.querySelectorAll('[data-cf]').forEach(c => { c.checked = false; });
        box.querySelector('[data-ok]').onclick = async () => {
            const rr = await api('access_save', {type, id, features: collect(box)});
            if (!rr.ok) return toast(rr.error, 'error');
            toast('امکاناتِ رفاهی ذخیره شد؛ از بارِ بعدیِ صفحه‌ی کاربر اعمال می‌شود.', 'success');
            close();
        };
    }

    window.CFAdmin = {renderLibrary, permSection, permSave, openAccess};
})();
