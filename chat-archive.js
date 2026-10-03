// فایل: chat-archive.js
// «بایگانیِ همه‌ی گفتگوها» برای مدیر کل: همه‌ی گفتگوهای کارکنان، شرکت‌ها و همکاران (حتی دونفره‌ی دو همکارِ دیگر)،
// با پیام‌های حذف‌شده (و نامِ حذف‌کننده)، پنهان‌شده‌ها و پاک‌کردن‌های تاریخچه؛ به‌علاوه‌ی تنظیمِ نقش‌هایی که با شرکت‌ها گفتگو می‌کنند.
(function () {
    const API = 'api/chat_actions.php';
    const fa = v => String(v ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const A = {key: null, threads: []};
    async function api(body) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }
    const TYPE = {PERSON: ['کارکنان', 'bg-sky-100 text-sky-700'], COMPANY: ['شرکت', 'bg-indigo-100 text-indigo-700'], STAFF: ['همکاران', 'bg-emerald-100 text-emerald-700']};

    async function init() {
        const root = document.getElementById('chat-archive-root');
        if (!root) return;
        if (!root.dataset.built) {
            root.dataset.built = '1';
            root.innerHTML = `
            <style>.ca-grid{display:grid;gap:12px;min-height:560px}@media(min-width:1024px){.ca-grid{grid-template-columns:360px 1fr}}</style>
            <div class="ca-grid">
                <div class="card p-3 flex flex-col gap-2 overflow-hidden">
                    <div class="grid grid-cols-2 gap-2">
                        <select id="ca-type" class="border rounded-lg px-2 py-2 text-xs font-bold"><option value="">همه‌ی گفتگوها</option><option value="P">کارکنان</option><option value="C">شرکت‌ها</option><option value="S">همکاران با هم</option></select>
                        <label class="flex items-center gap-1.5 text-[11px] font-bold text-rose-600 border rounded-lg px-2"><input type="checkbox" id="ca-del">فقط دارای حذف‌شده</label>
                    </div>
                    <input type="search" id="ca-q" placeholder="جستجو: نام شخص، شرکت یا همکار..." class="border rounded-lg px-3 py-2 text-xs">
                    <div class="grid grid-cols-2 gap-2"><input id="ca-from" placeholder="از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-2 py-1.5 text-xs"><input id="ca-to" placeholder="تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-2 py-1.5 text-xs"></div>
                    <div id="ca-list" class="flex-1 overflow-y-auto space-y-1.5 pl-1" style="max-height:62vh"></div>
                    <details class="border-2 border-indigo-100 rounded-xl p-2 bg-indigo-50/40">
                        <summary class="text-[11px] font-black text-indigo-700 cursor-pointer"><i class="fas fa-user-shield ml-1"></i>چه نقش‌هایی با شرکت‌ها گفتگو کنند؟</summary>
                        <div id="ca-roles" class="mt-2 space-y-1 text-[11px]"></div>
                        <p class="text-[10px] text-slate-500 mt-1">مدیر کل همیشه دسترسی دارد. برای هر نفر هم می‌توانید در «کاربران ← ویرایش» شرکت‌های مشخص (یا همه/هیچ) تعیین کنید.</p>
                        <button type="button" id="ca-roles-save" class="mt-2 w-full text-[11px] font-black text-white rounded-lg py-1.5" style="background:#4f46e5">ذخیره</button>
                    </details>
                </div>
                <div class="card overflow-hidden flex flex-col">
                    <div id="ca-head" class="px-4 py-3 text-white" style="background:linear-gradient(120deg,#334155,#475569)"><b>یک گفتگو را انتخاب کنید</b><p class="text-[11px] opacity-80">همه‌ی پیام‌ها، حتی حذف‌شده‌ها، فقط برای مدیر کل.</p></div>
                    <div id="ca-msgs" class="flex-1 overflow-y-auto p-4 space-y-2 bg-slate-50" style="max-height:70vh"></div>
                </div>
            </div>`;
            let t = null;
            const reload = () => { clearTimeout(t); t = setTimeout(loadThreads, 300); };
            ['ca-q', 'ca-from', 'ca-to'].forEach(id => document.getElementById(id).addEventListener('input', reload));
            ['ca-type', 'ca-del'].forEach(id => document.getElementById(id).addEventListener('change', loadThreads));
            document.getElementById('ca-roles-save').onclick = saveRoles;
            loadRoles();
        }
        loadThreads();
    }
    async function loadRoles() {
        const d = await api({action: 'archive_settings'});
        if (!d.ok) return;
        document.getElementById('ca-roles').innerHTML = Object.entries(d.role_options).map(([k, v]) =>
            `<label class="flex items-center gap-2 bg-white border rounded-lg px-2 py-1.5"><input type="checkbox" class="ca-role" value="${k}" ${d.roles.includes(k) ? 'checked' : ''}>${esc(v)}</label>`).join('');
    }
    async function saveRoles() {
        const roles = [...document.querySelectorAll('.ca-role:checked')].map(c => c.value);
        const d = await api({action: 'archive_save_roles', roles});
        if (window.showToast) showToast(d.ok ? 'ذخیره شد.' : (d.error || 'خطا'), d.ok ? 'success' : 'error');
    }
    async function loadThreads() {
        const box = document.getElementById('ca-list');
        box.innerHTML = '<p class="text-center text-xs text-slate-400 py-6">در حال بارگذاری...</p>';
        const v = id => document.getElementById(id).value.trim();
        const d = await api({action: 'archive_threads', type: v('ca-type'), q: v('ca-q'), from: v('ca-from'), to: v('ca-to'), deleted_only: document.getElementById('ca-del').checked ? 1 : ''});
        if (!d.ok) { box.innerHTML = `<p class="text-center text-xs text-red-500 py-6">${esc(d.error || 'خطا')}</p>`; return; }
        A.threads = d.threads;
        box.innerHTML = d.threads.length ? d.threads.map(t => {
            const ty = TYPE[t.type] || ['', ''];
            return `<button type="button" class="ca-th w-full text-right border-2 ${A.key === t.key ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200 bg-white hover:border-slate-300'} rounded-xl px-3 py-2" data-k="${esc(t.key)}">
                <div class="flex items-center gap-2"><span class="text-[9.5px] font-bold px-1.5 py-0.5 rounded-full ${ty[1]}">${ty[0]}</span><b class="text-[12px] text-slate-800 truncate flex-1">${esc(t.title)}</b><span class="text-[10px] text-slate-400">${fa(t.last_date)}</span></div>
                ${t.sub ? `<p class="text-[10px] text-slate-500 truncate mt-0.5">${esc(t.sub)}</p>` : ''}
                <p class="text-[10px] text-slate-500 mt-0.5">${fa(t.total)} پیام${t.deleted ? ` | <span class="text-rose-600 font-bold">${fa(t.deleted)} حذف‌شده</span>` : ''}</p></button>`;
        }).join('') : '<p class="text-center text-xs text-slate-400 py-6">گفتگویی پیدا نشد.</p>';
        box.querySelectorAll('.ca-th').forEach(b => b.onclick = () => openThread(b.dataset.k));
    }
    async function openThread(key) {
        A.key = key;
        document.querySelectorAll('.ca-th').forEach(b => { const on = b.dataset.k === key; b.classList.toggle('border-indigo-400', on); b.classList.toggle('bg-indigo-50', on); });
        const t = A.threads.find(x => x.key === key) || {};
        document.getElementById('ca-head').innerHTML = `<b>${esc(t.title || key)}</b><p class="text-[11px] opacity-80">${esc((TYPE[t.type] || [''])[0])}${t.sub ? ' | ' + esc(t.sub) : ''} | ${fa(t.total || 0)} پیام${t.deleted ? ' | ' + fa(t.deleted) + ' حذف‌شده' : ''}</p>`;
        const box = document.getElementById('ca-msgs');
        box.innerHTML = '<p class="text-center text-xs text-slate-400 py-6">در حال بارگذاری...</p>';
        const d = await api({action: 'archive_messages', key});
        if (!d.ok) { box.innerHTML = `<p class="text-center text-xs text-red-500 py-6">${esc(d.error || 'خطا')}</p>`; return; }
        let lastDate = '';
        box.innerHTML = (d.state ? `<p class="text-center text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-xl py-1.5">گفتگو ${d.state.closed_mode === 'BOTH' ? 'دوطرفه' : 'یک‌طرفه'} قطع شده — توسطِ ${esc(d.state.closed_by || '')}</p>` : '') +
            (d.messages.length ? d.messages.map(m => {
                const sep = m.date !== lastDate ? `<p class="text-center"><span class="text-[10px] font-bold text-slate-500 bg-white border rounded-full px-3 py-0.5">${fa(m.date)}</span></p>` : '';
                lastDate = m.date;
                const file = m.file ? (m.file.image ? `<a href="${m.file.url}" target="_blank"><img src="${m.file.url}" class="max-h-40 rounded-lg mt-1 border"></a>` : `<a href="${m.file.url}" target="_blank" class="block text-[11px] text-blue-600 mt-1"><i class="fas fa-paperclip ml-1"></i>${esc(m.file.name)}</a>`) : '';
                return sep + `<div class="flex ${m.ours ? 'justify-start' : 'justify-end'}">
                    <div class="max-w-[78%] rounded-2xl px-3 py-2 border-2 ${m.deleted ? 'border-rose-300 bg-rose-50' : (m.ours ? 'border-indigo-100 bg-white' : 'border-emerald-100 bg-emerald-50/60')}">
                        <p class="text-[10.5px] font-black ${m.ours ? 'text-indigo-700' : 'text-emerald-700'}">${esc(m.sender)}</p>
                        ${m.ref ? `<p class="text-[10px] text-slate-500">موضوع: ${esc(m.ref.label || '')}</p>` : ''}
                        ${m.text ? `<p class="text-[12.5px] text-slate-800 whitespace-pre-wrap break-words ${m.deleted ? 'line-through decoration-rose-400' : ''}">${esc(m.text)}</p>` : ''}
                        ${file}
                        <div class="flex flex-wrap items-center gap-1 mt-1 text-[9.5px] text-slate-400">
                            <span>${fa(m.time)}</span>${m.edited ? '<span>| ویرایش‌شده</span>' : ''}
                            ${m.deleted ? `<span class="font-bold text-rose-700 bg-rose-100 rounded-full px-2 py-0.5"><i class="fas fa-trash-can ml-1"></i>حذف‌شده توسطِ ${esc(m.deleted_by || 'نامشخص')}${m.deleted_date ? ' | ' + fa(m.deleted_date) : ''}</span>` : ''}
                            ${(m.hidden_for || []).map(h => `<span class="font-bold text-amber-700 bg-amber-100 rounded-full px-2 py-0.5"><i class="fas fa-eye-slash ml-1"></i>پنهان برای ${esc(h)}</span>`).join('')}
                        </div>
                    </div></div>`;
            }).join('') : '<p class="text-center text-xs text-slate-400 py-6">پیامی نیست.</p>');
        box.scrollTop = box.scrollHeight;
    }
    window.ChatArchive = {init};
})();
