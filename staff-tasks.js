// فایل: staff-tasks.js
// «کارهای من» (هر کاربر) و «کارهای پرسنل» (مدیر کل): کارهایی که مدیر برای کارمندان تعریف می‌کند — مهلت، یادآوری، اولویت،
// وضعیت (در انتظار / در حالِ انجام / انجام شد)، یادداشت و تاریخچه‌ی هر کار؛ نشانِ تعداد کنارِ منو و یادآوریِ سرِ وقت.
(function () {
    'use strict';
    if (window.StaffTasks) return;
    const API = 'api/staff_tasks_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : alert(m));
    // پنجره‌های خودِ سایت (نه پنجره‌ی پیش‌فرضِ مرورگر)
    const ask = (title, text, o) => window.uiConfirm ? uiConfirm(title, text, o || {}) : Promise.resolve(window.confirm(text));
    const askText = (title, value, o) => window.uiPrompt ? uiPrompt(title, value || '', o || {}) : Promise.resolve(window.prompt(title, value || ''));
    async function post(b) { try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(b)})).json(); } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; } }
    const ST = {OPEN: ['در انتظارِ انجام', '#f1f5f9', '#475569', 'fa-hourglass-half'], DOING: ['در حالِ انجام', '#dbeafe', '#1d4ed8', 'fa-person-running'],
        DONE: ['انجام شد', '#dcfce7', '#15803d', 'fa-circle-check'], CANCELLED: ['لغو شد', '#f1f5f9', '#94a3b8', 'fa-ban']};
    const PR = {1: ['کم', '#94a3b8'], 2: ['معمولی', '#0ea5e9'], 3: ['مهم', '#f59e0b'], 4: ['فوری', '#e11d48']};
    let css = false;
    function injectCss() {
        if (css) return; css = true;
        const s = document.createElement('style');
        s.textContent = `.stk-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:12px 14px;transition:.15s}.stk-card:hover{box-shadow:0 10px 30px -18px rgba(15,23,42,.35)}
        .stk-card.over{border-color:#fecdd3;background:linear-gradient(180deg,#fff1f2,#fff)}.stk-card.done{opacity:.7}
        .stk-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:10.5px;font-weight:800}
        .stk-btn{border-radius:10px;padding:5px 10px;font-size:11px;font-weight:800;display:inline-flex;align-items:center;gap:5px;cursor:pointer}
        .stk-in{width:100%;border:1px solid #e2e8f0;border-radius:10px;padding:7px 10px;font-size:12px}.stk-lbl{display:block;font-size:10.5px;font-weight:800;color:#64748b;margin-bottom:3px}
        .stk-hero{border-radius:22px;padding:18px 20px;color:#fff;background:linear-gradient(120deg,#0f172a,#3730a3 55%,#0e7490)}
        .stk-u{border:1px solid #e2e8f0;border-radius:14px;padding:8px 10px;cursor:pointer;background:#fff}.stk-u.on{border-color:#6366f1;box-shadow:0 0 0 3px #e0e7ff}`;
        document.head.appendChild(s);
    }
    const pill = (bg, c, ic, t) => `<span class="stk-pill" style="background:${bg};color:${c}"><i class="fas ${ic}"></i>${esc(t)}</span>`;
    function cardHtml(t, mode) {
        const st = ST[t.status] || ST.OPEN, pr = PR[t.priority] || PR[2];
        return `<div class="stk-card ${t.overdue ? 'over' : ''} ${t.status === 'DONE' || t.status === 'CANCELLED' ? 'done' : ''}" data-t="${t.id}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0"><b class="text-[13px] text-slate-800">${!t.seen && mode === 'my' ? '<span class="stk-pill" style="background:#fef3c7;color:#92400e">تازه</span> ' : ''}${esc(t.title)}</b>
                    <p class="text-[10.5px] text-slate-500 mt-0.5">${mode === 'admin' ? `<i class="fas fa-user ml-1"></i>${esc(t.assignee)} · ` : (t.creator ? `از طرفِ ${esc(t.creator)} · ` : '')}ثبت ${fa(t.created_j)}</p></div>
                <span class="flex flex-wrap gap-1">${pill(st[1], st[2], st[3], st[0])}${pill('#fff', pr[1], 'fa-flag', pr[0])}</span></div>
            ${t.detail ? `<p class="text-[11.5px] text-slate-600 leading-6 mt-2 whitespace-pre-wrap">${esc(t.detail)}</p>` : ''}
            <div class="flex flex-wrap items-center gap-2 mt-2 text-[10.5px] font-bold">
                ${t.start_j ? `<span class="text-slate-500"><i class="far fa-calendar ml-1"></i>شروع ${fa(t.start_j)}</span>` : ''}
                ${t.due_j ? `<span class="${t.overdue ? 'text-rose-600' : 'text-slate-600'}"><i class="fas fa-clock ml-1"></i>مهلت ${fa(t.due_j)}${t.overdue ? ' (گذشته)' : ''}</span>` : ''}
                ${t.remind_j ? `<span class="text-indigo-500"><i class="fas fa-bell ml-1"></i>یادآوری ${fa(t.remind_j)}</span>` : ''}
                ${t.done_j ? `<span class="text-emerald-600"><i class="fas fa-check ml-1"></i>انجام ${fa(t.done_j)}</span>` : ''}</div>
            ${t.done_note ? `<p class="text-[11px] text-emerald-800 bg-emerald-50 rounded-lg px-2 py-1 mt-2">${esc(t.done_note)}</p>` : ''}
            <div class="flex flex-wrap gap-1.5 mt-2">
                ${mode === 'my' && t.status === 'OPEN' ? `<button class="stk-btn" style="background:#dbeafe;color:#1d4ed8" data-s="DOING"><i class="fas fa-play"></i>شروع کردم</button>` : ''}
                ${mode === 'my' && (t.status === 'OPEN' || t.status === 'DOING') ? `<button class="stk-btn" style="background:#10b981;color:#fff" data-s="DONE"><i class="fas fa-check"></i>انجام شد</button>` : ''}
                ${mode === 'my' && t.status === 'DONE' ? `<button class="stk-btn" style="background:#f1f5f9;color:#475569" data-s="OPEN"><i class="fas fa-rotate-left"></i>برگرداندن</button>` : ''}
                <button class="stk-btn" style="background:#f1f5f9;color:#475569" data-note><i class="far fa-comment"></i>یادداشت</button>
                <button class="stk-btn" style="background:#f1f5f9;color:#475569" data-log><i class="fas fa-clock-rotate-left"></i>تاریخچه</button>
                ${mode === 'admin' && t.status !== 'CANCELLED' ? `<button class="stk-btn" style="background:#fef3c7;color:#92400e" data-edit><i class="fas fa-pen"></i>ویرایش</button><button class="stk-btn" style="background:#fff1f2;color:#be123c" data-cancel><i class="fas fa-ban"></i>لغو</button>` : ''}
                ${mode === 'admin' ? `<button class="stk-btn" style="background:#fff1f2;color:#be123c" data-del title="حذفِ کامل"><i class="fas fa-trash"></i></button>` : ''}</div>
            <div data-logbox class="mt-2"></div></div>`;
    }
    function bindCards(root, items, mode, reload) {
        root.querySelectorAll('[data-t]').forEach(el => {
            const t = items.find(x => x.id === +el.dataset.t); if (!t) return;
            el.querySelectorAll('[data-s]').forEach(b => b.onclick = async () => {
                let note = '';
                if (b.dataset.s === 'DONE') { note = await askText('انجام شد', '', { hint: 'توضیح (اختیاری): چه کردید؟', ok: 'ثبت' }); if (note === null) return; }
                const r = await post({action: 'my_status', id: t.id, status: b.dataset.s, note});
                if (!r.ok) return toast(r.error, 'error');
                toast(b.dataset.s === 'DONE' ? 'ثبت شد: انجام شد ✅' : 'وضعیت ثبت شد.', 'success'); reload(); ping();
            });
            el.querySelector('[data-note]').onclick = async () => { const n = await askText('یادداشت روی این کار', '', { ok: 'ثبت', required: true }); if (!n) return; const r = await post({action: 'note', id: t.id, note: n}); toast(r.ok ? 'یادداشت ثبت شد.' : r.error, r.ok ? 'success' : 'error'); if (r.ok) el.querySelector('[data-log]').click(); };
            el.querySelector('[data-log]').onclick = async () => {
                const box = el.querySelector('[data-logbox]');
                if (box.innerHTML) { box.innerHTML = ''; return; }
                const r = await post({action: 'log', id: t.id});
                box.innerHTML = r.ok ? `<ul class="text-[11px] space-y-1 border-r-2 border-indigo-100 pr-2">${r.log.map(l => `<li><b class="text-slate-700">${esc(l.act)}</b> · ${esc(l.by)} · <span class="text-slate-400">${fa(l.at)}</span>${l.note ? `<p class="text-slate-600">${esc(l.note)}</p>` : ''}</li>`).join('')}</ul>` : `<p class="text-rose-600 text-[11px]">${esc(r.error)}</p>`;
            };
            const ed = el.querySelector('[data-edit]'); if (ed) ed.onclick = () => editor(t, reload);
            const cn = el.querySelector('[data-cancel]'); if (cn) cn.onclick = async () => { if (!(await ask('لغوِ کار', 'این کار لغو شود؟', { danger: true, ok: 'لغو شود' }))) return; const r = await post({action: 'admin_cancel', id: t.id}); if (r.ok) reload(); else toast(r.error, 'error'); };
            const dl = el.querySelector('[data-del]'); if (dl) dl.onclick = async () => { if (!(await ask('حذفِ کار', 'این کار و تاریخچه‌اش کامل حذف شود؟', { danger: true, ok: 'حذف' }))) return; const r = await post({action: 'admin_delete', id: t.id}); if (r.ok) reload(); else toast(r.error, 'error'); };
        });
    }

    // ---------------- کارهای من ----------------
    async function initMy(el) {
        injectCss();
        el.innerHTML = '<p class="text-xs text-slate-400 p-6 text-center"><i class="fas fa-spinner fa-spin"></i></p>';
        const r = await post({action: 'my_list'});
        if (!r.ok) { el.innerHTML = `<p class="text-rose-600 text-xs p-4">${esc(r.error)}</p>`; return; }
        const open = r.items.filter(t => t.status === 'OPEN' || t.status === 'DOING'), rest = r.items.filter(t => !(t.status === 'OPEN' || t.status === 'DOING'));
        el.innerHTML = `<div class="stk-hero mb-4"><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-[11px] opacity-80"><i class="fas fa-list-check ml-1"></i>اطلاعات پرسنلی</p><h2 class="text-xl font-black">کارهای من</h2>
                <p class="text-[11.5px] opacity-85 mt-1">کارهایی که مدیر برای شما تعریف کرده؛ وضعیت و توضیح را همین‌جا ثبت کنید.</p></div>
                <div class="flex gap-2 text-center">${[['باز', open.length], ['دیرشده', open.filter(t => t.overdue).length], ['انجام‌شده (۳۰ روز)', rest.filter(t => t.status === 'DONE').length]].map(([l, n]) => `<div class="bg-white/10 rounded-2xl px-4 py-2"><b class="text-xl">${fa(n)}</b><p class="text-[10.5px] opacity-80">${l}</p></div>`).join('')}</div></div>
                ${r.is_manager ? '<button class="stk-btn mt-3" style="background:rgba(255,255,255,.15);color:#fff" onclick="switchTab(\'staff-tasks\')"><i class="fas fa-users-gear"></i>مدیریتِ کارهای پرسنل</button>' : ''}</div>
            <div class="grid gap-3 md:grid-cols-2" data-open>${open.map(t => cardHtml(t, 'my')).join('') || '<p class="text-xs text-slate-400 md:col-span-2 text-center py-8"><i class="fas fa-mug-hot ml-1"></i>کارِ بازی ندارید.</p>'}</div>
            ${rest.length ? `<details class="mt-5"><summary class="text-xs font-black text-slate-500 cursor-pointer">انجام‌شده و لغوشده (${fa(rest.length)})</summary><div class="grid gap-3 md:grid-cols-2 mt-3">${rest.map(t => cardHtml(t, 'my')).join('')}</div></details>` : ''}`;
        bindCards(el, r.items, 'my', () => initMy(el));
    }

    // ---------------- کارهای پرسنل (مدیر) ----------------
    const A = {user: 0, filter: 'open', q: ''};
    async function initAdmin(el) {
        injectCss();
        A.el = el;
        el.innerHTML = '<p class="text-xs text-slate-400 p-6 text-center"><i class="fas fa-spinner fa-spin"></i></p>';
        const r = await post({action: 'admin_list', user_id: A.user, filter: A.filter, q: A.q});
        if (!r.ok) { el.innerHTML = `<p class="text-rose-600 text-xs p-4">${esc(r.error)}</p>`; return; }
        A.users = r.users;
        el.innerHTML = `<div class="stk-hero mb-4"><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-[11px] opacity-80"><i class="fas fa-users-gear ml-1"></i>مدیریتِ پرسنل</p><h2 class="text-xl font-black">کارهای پرسنل</h2>
                <p class="text-[11.5px] opacity-85 mt-1">برای هر کارمند (یا چند نفر با هم) کار تعریف کنید؛ مهلت، یادآوری و اولویت بگذارید و پیشرفت را ببینید.</p></div>
                ${r.can_edit ? '<button class="stk-btn" style="background:#fff;color:#3730a3" data-new><i class="fas fa-plus"></i>کارِ تازه</button>' : ''}</div></div>
            <div class="grid gap-4 lg:grid-cols-[280px,1fr]">
                <div class="space-y-2"><div class="stk-u ${!A.user ? 'on' : ''}" data-u="0"><b class="text-[12px]">همه‌ی پرسنل</b></div>
                    ${r.users.map(u => `<div class="stk-u ${A.user === u.id ? 'on' : ''}" data-u="${u.id}"><div class="flex items-center justify-between"><b class="text-[12px] text-slate-800">${esc(u.name)}</b>
                        <span class="flex gap-1">${u.open ? `<span class="stk-pill" style="background:#dbeafe;color:#1d4ed8">${fa(u.open)} باز</span>` : ''}${u.overdue ? `<span class="stk-pill" style="background:#ffe4e6;color:#be123c">${fa(u.overdue)} دیر</span>` : ''}</span></div>
                        <p class="text-[10px] text-slate-400 mt-0.5">${fa(u.done30)} انجام‌شده در ۳۰ روز${u.last_act ? ' · آخرین فعالیت ' + fa(u.last_act) : ''}</p></div>`).join('')}
                    <button class="stk-btn w-full justify-center" style="background:#eef2ff;color:#3730a3" onclick="switchTab('staff-work')"><i class="fas fa-users-viewfinder"></i>جزئیاتِ فعالیت و کارکردِ هر کاربر</button></div>
                <div><div class="flex flex-wrap items-center gap-2 mb-3">${[['open', 'باز'], ['overdue', 'دیرشده'], ['done', 'انجام‌شده'], ['all', 'همه']].map(([k, l]) => `<button class="stk-btn" data-f="${k}" style="${A.filter === k ? 'background:#4f46e5;color:#fff' : 'background:#f1f5f9;color:#475569'}">${l}</button>`).join('')}
                    <input class="stk-in" style="max-width:240px" data-q placeholder="جستجو در عنوان و شرح…" value="${esc(A.q)}"></div>
                    <div class="grid gap-3 md:grid-cols-2">${r.items.map(t => cardHtml(t, 'admin')).join('') || '<p class="text-xs text-slate-400 md:col-span-2 text-center py-8">کاری نیست.</p>'}</div></div></div>`;
        el.querySelectorAll('[data-u]').forEach(x => x.onclick = () => { A.user = +x.dataset.u; initAdmin(el); });
        el.querySelectorAll('[data-f]').forEach(x => x.onclick = () => { A.filter = x.dataset.f; initAdmin(el); });
        const q = el.querySelector('[data-q]'); let tm; q.oninput = () => { clearTimeout(tm); tm = setTimeout(() => { A.q = q.value.trim(); initAdmin(el).then(() => { const n = el.querySelector('[data-q]'); if (n) { n.focus(); n.setSelectionRange(n.value.length, n.value.length); } }); }, 400); };
        const nb = el.querySelector('[data-new]'); if (nb) nb.onclick = () => editor(null, () => initAdmin(el));
        bindCards(el, r.items, 'admin', () => initAdmin(el));
    }
    function editor(t, done) {
        injectCss();
        const users = A.users || [];
        const [dd, dt] = t && t.due_j ? t.due_j.split(' ') : ['', ''];
        const [sd, stt] = t && t.start_j ? t.start_j.split(' ') : ['', ''];
        const [rd, rt] = t && t.remind_j ? t.remind_j.split(' ') : ['', ''];
        const ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;z-index:1000010;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:14px';
        ov.innerHTML = `<div class="bg-white rounded-3xl p-5 w-[min(620px,100%)] max-h-[92vh] overflow-y-auto" dir="rtl">
            <div class="flex items-center justify-between mb-3"><b class="text-base text-slate-800"><i class="fas fa-list-check text-indigo-600 ml-1"></i>${t ? 'ویرایشِ کار' : 'کارِ تازه برای پرسنل'}</b><button data-x class="text-slate-400 hover:text-rose-500"><i class="fas fa-times"></i></button></div>
            <label class="stk-lbl">عنوانِ کار *</label><input class="stk-in" data-k="title" value="${esc(t ? t.title : '')}" placeholder="مثلاً پیگیریِ مدارکِ درخواستِ ۳۲۵۱۵">
            <label class="stk-lbl mt-2">شرح (اختیاری)</label><textarea class="stk-in" rows="3" data-k="detail">${esc(t ? t.detail || '' : '')}</textarea>
            <label class="stk-lbl mt-2">${t ? 'مسئول' : 'برای چه کسی؟ (می‌شود چند نفر را انتخاب کرد)'}</label>
            ${t ? `<select class="stk-in" data-k="assignee_id">${users.map(u => `<option value="${u.id}" ${u.id === t.assignee_id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>`
                : `<div class="flex flex-wrap gap-1.5 max-h-40 overflow-y-auto border border-slate-100 rounded-xl p-2">${users.map(u => `<label class="stk-pill cursor-pointer" style="background:#f8fafc;color:#334155;border:1px solid #e2e8f0"><input type="checkbox" data-as="${u.id}" ${A.user === u.id ? 'checked' : ''} class="accent-indigo-600"> ${esc(u.name)}</label>`).join('')}</div>`}
            <div class="grid grid-cols-2 gap-3 mt-2">
                <div><label class="stk-lbl">شروع (تاریخ)</label><input class="stk-in" dir="ltr" data-k="start_date" data-jdate value="${esc(sd)}" placeholder="1405/08/12"></div>
                <div><label class="stk-lbl">ساعتِ شروع</label><input class="stk-in" dir="ltr" data-k="start_time" value="${esc(stt || '')}" placeholder="09:00"></div>
                <div><label class="stk-lbl">مهلت (تاریخ)</label><input class="stk-in" dir="ltr" data-k="due_date" data-jdate value="${esc(dd)}" placeholder="1405/08/14"></div>
                <div><label class="stk-lbl">ساعتِ مهلت</label><input class="stk-in" dir="ltr" data-k="due_time" value="${esc(dt || '')}" placeholder="16:00"></div>
                <div><label class="stk-lbl">یادآوری (تاریخ)</label><input class="stk-in" dir="ltr" data-k="remind_date" data-jdate value="${esc(rd)}" placeholder="خالی = از مهلت"></div>
                <div><label class="stk-lbl">ساعتِ یادآوری</label><input class="stk-in" dir="ltr" data-k="remind_time" value="${esc(rt || '')}" placeholder="10:00"></div>
                <div><label class="stk-lbl">یا یادآوری پیش از مهلت</label><select class="stk-in" data-k="remind_before"><option value="">—</option><option value="30">۳۰ دقیقه قبل</option><option value="60">۱ ساعت قبل</option><option value="180">۳ ساعت قبل</option><option value="1440">۱ روز قبل</option></select></div>
                <div><label class="stk-lbl">اولویت</label><select class="stk-in" data-k="priority">${Object.entries(PR).map(([k, v]) => `<option value="${k}" ${(t ? t.priority : 2) === +k ? 'selected' : ''}>${v[0]}</option>`).join('')}</select></div></div>
            <button data-ok class="w-full mt-4 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-sm py-2.5 rounded-xl"><i class="fas fa-check ml-1"></i>${t ? 'ذخیره' : 'ثبت و ارسال برای پرسنل'}</button></div>`;
        document.body.appendChild(ov);
        if (window.JDatePick) ov.querySelectorAll('[data-jdate]').forEach(i => { try { JDatePick.attach(i); } catch (e) {} });
        ov.querySelector('[data-x]').onclick = () => ov.remove();
        ov.addEventListener('click', e => { if (e.target === ov) ov.remove(); });
        ov.querySelector('[data-ok]').onclick = async () => {
            const b = {action: 'admin_save', id: t ? t.id : 0};
            ov.querySelectorAll('[data-k]').forEach(x => b[x.dataset.k] = en(x.value.trim()));
            if (!t) b.assignees = [...ov.querySelectorAll('[data-as]:checked')].map(x => +x.dataset.as);
            const r = await post(b);
            if (!r.ok) return toast(r.error, 'error');
            toast(t ? 'ذخیره شد.' : `کار برای ${fa(r.ids.length)} نفر ثبت و اعلام شد.`, 'success');
            ov.remove(); done && done();
        };
    }

    // ---------------- نشانِ منو و یادآوری ----------------
    async function ping() {
        const r = await post({action: 'my_ping'});
        if (!r.ok) return;
        const nav = document.getElementById('nav-my-tasks');
        if (nav) {
            let b = nav.querySelector('.stk-badge');
            if (!b) { b = document.createElement('span'); b.className = 'stk-badge'; b.style.cssText = 'margin-right:6px;border-radius:999px;padding:0 7px;font-size:10px;font-weight:900;color:#fff'; nav.appendChild(b); }
            b.style.display = r.open ? '' : 'none';
            b.style.background = r.overdue ? '#e11d48' : '#4f46e5';
            b.textContent = fa(r.open);
        }
        (r.remind || []).forEach(t => {
            toast(`⏰ یادآوریِ کار: ${t.title}${t.due_j ? ' — مهلت ' + fa(t.due_j) : ''}`, 'warning');
            try { if (window.Notification && Notification.permission === 'granted') new Notification('یادآوریِ کار', {body: t.title}); } catch (e) {}
        });
    }
    function start() { setTimeout(ping, 2500); setInterval(ping, 60000); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

    window.StaffTasks = {initMy: () => { const el = document.getElementById('stk-my-root'); if (el) initMy(el); }, initAdmin: () => { const el = document.getElementById('stk-admin-root'); if (el) initAdmin(el); }, ping};
})();
