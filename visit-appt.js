// فایل: visit-appt.js
// پنل ← «بازدید سلامت و مدارک» ← نوبت‌های بازدیدِ خودرو (بدنه): تقویمِ کاری (هفته به هفته)، فهرستِ نوبت‌ها، نوبتِ دستی،
// صدورِ گزارشِ بازدید از روی نوبت (بعد از صدور، بازدید تایید و نوبت «انجام شد» می‌شود) + کادرِ نوبت در صفحه‌ی هر درخواست.
// منطق: api/_health_admin.php  (api/visit_appt_actions.php)
(function () {
    const API = 'api/visit_appt_actions.php';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const can = op => typeof permCan !== 'function' || permCan('health', op) || permCan('cases', op);
    const isPT = v => window.isPlateText ? window.isPlateText(v) : /ایران/.test(String(v || ''));   // پلاکِ خودرو یا موتورسیکلت
    const plate = p => p && isPT(p) && typeof formatPlateHtml === 'function' ? `<span class="plate-display">${formatPlateHtml(p)}</span>` : esc(fa(p || '—'));
    async function post(body) {
        try { return await (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
    }
    const ST_FA = { BOOKED: ['نوبت دارد', 'bg-sky-50 text-sky-700'], DONE: ['بازدید انجام شد', 'bg-emerald-50 text-emerald-700'], CANCELED: ['لغو شد', 'bg-slate-100 text-slate-500'] };
    const SRC_FA = { USER: 'انتخابِ خودِ کاربر', STAFF: 'نوبتِ دستی', AUTO: 'نوبتِ خودکار' };
    const V = { el: null, from: 0, tab: 'cal', scope: 'upcoming', q: '' };

    function css() {
        if (document.getElementById('va-css')) return;
        const s = document.createElement('style'); s.id = 'va-css';
        s.textContent = `
        .va-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}@media(max-width:900px){.va-week{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .va-day{border:1px solid #e2e8f0;border-radius:14px;background:#fff;display:flex;flex-direction:column;min-height:120px}
        .va-day.closed{background:#f8fafc}.va-day.today{border-color:#0ea5e9;box-shadow:0 0 0 2px #bae6fd}
        .va-dh{padding:6px 8px;border-bottom:1px solid #f1f5f9;font-size:11px;font-weight:900;color:#334155;display:flex;justify-content:space-between;align-items:center}
        .va-dh small{font-weight:700;color:#94a3b8}
        .va-slots{padding:5px;display:flex;flex-direction:column;gap:4px;max-height:360px;overflow-y:auto}
        .va-slot{display:flex;gap:4px;align-items:stretch;font-size:10.5px}
        .va-slot .t{width:38px;flex:0 0 38px;color:#64748b;font-weight:800;padding-top:3px}
        .va-slot .b{flex:1;display:flex;flex-direction:column;gap:3px}
        .va-free{border:1px dashed #cbd5e1;border-radius:9px;padding:3px 6px;color:#94a3b8;cursor:pointer;text-align:center}.va-free:hover{border-color:#0ea5e9;color:#0284c7;background:#f0f9ff}
        .va-ap{border-radius:9px;padding:4px 6px;cursor:pointer;line-height:1.5;border:1px solid}
        .va-ap.BOOKED{background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a}.va-ap.DONE{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}.va-ap.past{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
        .va-ap b{display:block;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .va-chip{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 10px;font-size:11px;font-weight:800;border:1px solid #e2e8f0;background:#fff;cursor:pointer}
        .va-chip.on{background:#0f172a;color:#fff;border-color:#0f172a}`;
        document.head.appendChild(s);
    }

    // ---------------- کارتِ اصلی (تبِ بازدید سلامت) ----------------
    async function card(el) {
        if (!el) return;
        css(); V.el = el;
        el.innerHTML = `<div class="flex flex-wrap items-center gap-2 mb-3">
            <h2 class="font-bold text-sky-700 text-sm"><i class="fas fa-calendar-days ml-1"></i>نوبت‌های بازدیدِ خودرو (بدنه)</h2>
            <span id="va-stats" class="flex flex-wrap gap-1.5"></span>
            <span class="mr-auto flex gap-1.5">
              <button data-tab="cal" class="va-chip"><i class="fas fa-calendar-week"></i>تقویمِ کاری</button>
              <button data-tab="list" class="va-chip"><i class="fas fa-list"></i>فهرستِ نوبت‌ها</button>
              ${can('edit') ? '<button data-new class="va-chip" style="background:#0284c7;color:#fff;border-color:#0284c7"><i class="fas fa-plus"></i>نوبتِ دستی</button>' : ''}</span></div>
          <div id="va-meta" class="text-[11px] text-slate-500 mb-2"></div>
          <div id="va-body"></div>`;
        el.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => { V.tab = b.dataset.tab; render(); });
        const nb = el.querySelector('[data-new]'); if (nb) nb.onclick = () => bookDialog(null, null);
        render();
    }
    function render() {
        V.el.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === V.tab));
        if (V.tab === 'cal') renderCal(); else renderList();
    }
    function metaLine(m) {
        if (!m) return '';
        const days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
        return `<i class="fas fa-circle-info ml-1"></i>تقویمِ کاری: ${m.days.map(d => days[d]).join('، ') || '—'} · ساعت ${fa(m.start)} تا ${fa(m.end)} · هر نوبت ${fa(m.slot)} دقیقه · ${fa(m.cap)} خودرو در هر نوبت`
            + (m.self_enabled ? ' · <b class="text-amber-700">«بازدید توسط کاربر» روشن است؛ نوبت‌دهی فقط وقتی خاموش باشد در مینی‌اپ پیشنهاد می‌شود</b>' : (m.pick ? ` · کاربر تا ${fa(m.window)} روزِ آینده خودش نوبت انتخاب می‌کند` : ' · نوبتِ خودکار'))
            + ' <span class="text-slate-400">(تغییر از «تنظیمات ← بازدید سلامت»)</span>';
    }

    async function renderCal() {
        const body = V.el.querySelector('#va-body');
        body.innerHTML = '<p class="text-xs text-slate-400 text-center py-6"><i class="fas fa-spinner fa-spin"></i></p>';
        const d = await post({ action: 'calendar', from: V.from, days: 7 });
        if (!d.ok) { body.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        V.el.querySelector('#va-meta').innerHTML = metaLine(d.meta);
        loadStats();
        const today = new Date(); const td = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        body.innerHTML = `<div class="flex items-center justify-between mb-2">
              <button data-nav="-7" class="va-chip"><i class="fas fa-chevron-right"></i>هفته‌ی قبل</button>
              <span class="text-xs font-black text-slate-600">${esc(fa(d.days[0].jdate))} تا ${esc(fa(d.days[d.days.length - 1].jdate))}${V.from ? ` <button data-nav="0" class="va-chip mr-1">امروز</button>` : ''}</span>
              <button data-nav="7" class="va-chip">هفته‌ی بعد<i class="fas fa-chevron-left"></i></button></div>
            <div class="va-week">${d.days.map(day => `<div class="va-day ${day.closed ? 'closed' : ''} ${day.date === td ? 'today' : ''}">
                <div class="va-dh"><span>${esc(day.weekday)} ${fa(day.day)} ${esc(day.month_fa)}</span><small>${day.closed ? esc(day.closed) : fa(day.appts.length) + ' نوبت'}</small></div>
                <div class="va-slots">${day.slots.map(sl => {
                    const aps = day.appts.filter(a => a.time === sl.time);
                    const freeN = Math.max(0, (sl.cap || 0) - aps.filter(a => a.status === 'BOOKED').length);
                    if (!aps.length && (day.closed || !sl.free)) return '';
                    return `<div class="va-slot"><span class="t">${fa(sl.time)}</span><span class="b">${aps.map(a => `<span class="va-ap ${a.status} ${a.status === 'BOOKED' && a.past ? 'past' : ''}" data-case="${a.case_id}" title="${esc(a.insured)} · ${esc(a.code)}">
                        <b>${esc(a.insured || '—')}</b>${esc(fa(a.plate || a.code))}${a.status === 'DONE' ? ' ✓' : ''}</span>`).join('')}
                        ${!day.closed && sl.free && freeN > 0 && can('edit') ? `<span class="va-free" data-at="${esc(sl.at)}">+ آزاد${sl.cap > 1 ? ' (' + fa(freeN) + ')' : ''}</span>` : ''}</span></div>`;
                }).join('') || '<p class="text-[10.5px] text-slate-300 text-center py-3">—</p>'}</div></div>`).join('')}</div>`;
        body.querySelectorAll('[data-nav]').forEach(b => b.onclick = () => { V.from = b.dataset.nav === '0' ? 0 : V.from + (+b.dataset.nav); renderCal(); });
        body.querySelectorAll('[data-case]').forEach(b => b.onclick = () => detail(+b.dataset.case));
        body.querySelectorAll('[data-at]').forEach(b => b.onclick = () => bookDialog(null, b.dataset.at));
    }

    async function loadStats() {
        const d = await post({ action: 'list', scope: 'today' });
        if (!d.ok || !V.el) return;
        const c = d.counts || {};
        V.el.querySelector('#va-stats').innerHTML = [['امروز', c.td, 'bg-sky-50 text-sky-700'], ['پیشِ رو', c.up, 'bg-indigo-50 text-indigo-700'], ['گذشته، بی‌گزارش', c.pa, 'bg-orange-50 text-orange-700'], ['انجام‌شده', c.dn, 'bg-emerald-50 text-emerald-700']]
            .map(([l, n, cl]) => `<span class="text-[10.5px] font-bold px-2 py-0.5 rounded-full ${cl}">${l}: ${fa(n || 0)}</span>`).join('');
    }

    async function renderList() {
        const body = V.el.querySelector('#va-body');
        body.innerHTML = `<div class="flex flex-wrap items-center gap-1.5 mb-2">${[['upcoming', 'پیشِ رو'], ['today', 'امروز'], ['past', 'گذشته (بدونِ گزارش)'], ['done', 'انجام‌شده'], ['canceled', 'لغوشده'], ['all', 'همه']]
                .map(([k, l]) => `<button data-sc="${k}" class="va-chip ${V.scope === k ? 'on' : ''}">${l}</button>`).join('')}
              <input data-q value="${esc(V.q)}" placeholder="پلاک، نام، کدِ درخواست…" class="border rounded-lg px-2 py-1 text-[11px] mr-auto w-52"></div>
            <div data-rows><p class="text-xs text-slate-400 text-center py-6"><i class="fas fa-spinner fa-spin"></i></p></div>`;
        body.querySelectorAll('[data-sc]').forEach(b => b.onclick = () => { V.scope = b.dataset.sc; renderList(); });
        let t = null;
        body.querySelector('[data-q]').oninput = e => { clearTimeout(t); t = setTimeout(() => { V.q = e.target.value.trim(); loadRows(); }, 300); };
        loadRows();
    }
    async function loadRows() {
        const box = V.el.querySelector('[data-rows]');
        const d = await post({ action: 'list', scope: V.scope, q: V.q });
        if (!d.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        V.el.querySelector('#va-meta').innerHTML = metaLine(d.meta);
        loadStats();
        if (!d.rows.length) { box.innerHTML = '<p class="text-xs text-slate-400 text-center py-6">نوبتی نیست.</p>'; return; }
        box.innerHTML = `<div class="overflow-x-auto"><table class="w-full text-[11px]"><thead class="bg-slate-50 text-slate-500"><tr>
            <th class="p-2 text-right">زمان</th><th class="p-2 text-right">پلاک</th><th class="p-2 text-right">بیمه‌گذار / شرکت</th><th class="p-2 text-right">درخواست</th><th class="p-2 text-right">نوبت</th><th class="p-2"></th></tr></thead>
            <tbody>${d.rows.map(a => `<tr class="border-t border-slate-100 ${a.status === 'BOOKED' && a.past ? 'bg-orange-50/50' : ''}">
              <td class="p-2 whitespace-nowrap font-bold">${esc(a.weekday)} ${fa(a.jdate)}<br><span class="text-sky-700">${fa(a.time)}</span></td>
              <td class="p-2">${plate(a.plate)}</td>
              <td class="p-2"><b>${esc(a.insured || '—')}</b><br><span class="text-slate-400">${esc(a.company || '')}${a.mobile ? ' · ' + esc(fa(a.mobile)) : ''}</span></td>
              <td class="p-2">${esc(fa(a.code))}<br><span class="text-slate-400">${esc(a.case_status_fa || '')}</span></td>
              <td class="p-2"><span class="px-2 py-0.5 rounded-full font-bold ${(ST_FA[a.status] || ST_FA.BOOKED)[1]}">${(ST_FA[a.status] || ST_FA.BOOKED)[0]}</span><br><span class="text-slate-400">${SRC_FA[a.source] || ''}${a.staff ? ' · ' + esc(a.staff) : ''}</span></td>
              <td class="p-2 whitespace-nowrap"><button data-case="${a.case_id}" class="text-[11px] font-bold text-sky-700 hover:underline">جزئیات</button></td></tr>`).join('')}</tbody></table></div>`;
        box.querySelectorAll('[data-case]').forEach(b => b.onclick = () => detail(+b.dataset.case));
    }

    // ---------------- پنجره‌ها ----------------
    function modal(inner, w) {
        const box = document.createElement('div');
        box.className = 'modal-overlay active'; box.style.zIndex = '1000003';
        box.innerHTML = `<div class="modal-content w-full ${w || 'max-w-lg'} p-6 relative max-h-[90vh] overflow-y-auto"><button type="button" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl" data-x><i class="fas fa-times"></i></button>${inner}</div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.querySelector('[data-x]').onclick = close;
        box.addEventListener('click', e => { if (e.target === box) close(); });
        return { box, close };
    }
    function refreshAll() { if (V.el && document.body.contains(V.el)) render(); document.querySelectorAll('[data-va-case]').forEach(el => caseBox(el, +el.dataset.vaCase)); }

    // جزئیاتِ نوبتِ یک درخواست: بازکردنِ درخواست، صدورِ گزارشِ بازدید، تغییر زمان، لغو
    async function detail(caseId) {
        const d = await post({ action: 'case', case_id: caseId });
        if (!d.ok) return toast(d.error || 'خطا', 'error');
        const a = d.appointment;
        const m = modal(`<h3 class="font-black text-base mb-1"><i class="fas fa-calendar-check text-sky-600 ml-1"></i>نوبتِ بازدیدِ خودرو</h3>
            ${a ? `<div class="bg-slate-50 rounded-xl p-3 text-xs leading-7 mt-2">
              <div class="flex flex-wrap items-center gap-2">${plate(a.plate)} <b>${esc(a.insured || '')}</b> <span class="text-slate-400">${esc(fa(a.code))}</span></div>
              <div><span class="text-slate-400">زمان:</span> <b>${esc(a.weekday)} ${fa(a.jdate)} ساعت ${fa(a.time)}</b>${a.status === 'BOOKED' && a.past ? ' <b class="text-orange-600">(گذشته؛ گزارش صادر نشده)</b>' : ''}</div>
              <div><span class="text-slate-400">وضعیت:</span> <b>${(ST_FA[a.status] || ST_FA.BOOKED)[0]}</b> · ${SRC_FA[a.source] || ''}${a.staff ? ' · ' + esc(a.staff) : ''}</div>
              ${a.company ? `<div><span class="text-slate-400">شرکت:</span> ${esc(a.company)}</div>` : ''}${a.mobile ? `<div><span class="text-slate-400">موبایل:</span> <span dir="ltr">${esc(fa(a.mobile))}</span></div>` : ''}
              ${a.note ? `<div><span class="text-slate-400">توضیح:</span> ${esc(a.note)}</div>` : ''}</div>` : '<p class="text-xs text-slate-500 mt-2">این درخواست نوبتِ بازدید ندارد.</p>'}
            <div class="grid grid-cols-2 gap-2 mt-4">
              <button data-open class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl"><i class="fas fa-folder-open ml-1"></i>صفحه‌ی درخواست</button>
              ${window.VR && VR.openTargetBuilder && (!a || a.status !== 'DONE') ? `<button data-vr class="bg-gradient-to-l from-indigo-600 to-violet-600 text-white text-xs font-bold py-2.5 rounded-xl"><i class="fas fa-file-circle-plus ml-1"></i>صدورِ گزارشِ بازدید</button>` : '<span></span>'}
              ${can('edit') && (!a || a.status !== 'DONE') ? `<button data-move class="bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold py-2.5 rounded-xl"><i class="fas fa-clock-rotate-left ml-1"></i>${a && a.status === 'BOOKED' ? 'تغییرِ زمان' : 'ثبتِ نوبت'}</button>` : ''}
              ${can('edit') && a && a.status === 'BOOKED' ? `<button data-cancel class="bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold py-2.5 rounded-xl"><i class="fas fa-ban ml-1"></i>لغوِ نوبت</button>` : ''}</div>
            <p class="text-[10.5px] text-slate-400 mt-3 leading-5">با «صدورِ گزارشِ بازدید» فرمِ گزارش با اطلاعاتِ همین درخواست باز می‌شود؛ عکس‌هایی که آن‌جا بارگذاری می‌کنید عکس‌های بازدیدِ این خودرو هم می‌شوند، و بعد از صدور بازدید تایید، گزارش و عکس‌ها در پوشه‌ی بایگانیِ درخواست ثبت و نوبت «انجام شد» می‌شود.</p>`);
        const q = s => m.box.querySelector(s);
        q('[data-open]').onclick = () => { m.close(); if (typeof openCase === 'function') openCase(caseId); };
        if (q('[data-vr]')) q('[data-vr]').onclick = () => { m.close(); VR.openTargetBuilder({ type: 'case', id: caseId }, refreshAll); };
        if (q('[data-move]')) q('[data-move]').onclick = () => { m.close(); bookDialog(caseId, null, d); };
        if (q('[data-cancel]')) q('[data-cancel]').onclick = async () => {
            if (!(window.uiConfirm ? await uiConfirm('لغوِ نوبت', 'نوبتِ این درخواست لغو شود؟', {}) : confirm('لغو شود؟'))) return;
            const r = await post({ action: 'cancel', case_id: caseId });
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            m.close(); toast('نوبت لغو شد.', 'success'); refreshAll();
        };
    }

    // نوبتِ دستی: انتخابِ درخواست (اگر داده نشده)، سپس یکی از نوبت‌های ۱۴ روزِ آینده یا تاریخ/ساعتِ دلخواه
    async function bookDialog(caseId, at, pre) {
        let cid = caseId, info = pre || null;
        const m = modal(`<h3 class="font-black text-base mb-3"><i class="fas fa-calendar-plus text-sky-600 ml-1"></i>نوبتِ دستیِ بازدید</h3><div data-step></div>`, 'max-w-2xl');
        const step = m.box.querySelector('[data-step]');
        const pickCase = () => {
            step.innerHTML = `<label class="text-[11px] font-bold text-slate-500">درخواستِ بدنه (پلاک، نام، کد ملی یا کدِ درخواست)</label>
                <input data-sq class="w-full border rounded-xl px-3 py-2 text-sm mt-1" placeholder="مثلاً ۱۲ب۳۴۵ یا نامِ بیمه‌گذار">
                <div data-sr class="mt-2 space-y-1.5"></div>`;
            const inp = step.querySelector('[data-sq]'); setTimeout(() => inp.focus(), 50);
            let t = null;
            inp.oninput = () => { clearTimeout(t); t = setTimeout(async () => {
                const r = await post({ action: 'search', q: inp.value });
                const box = step.querySelector('[data-sr]');
                box.innerHTML = (r.rows || []).map(x => `<button data-c="${x.case_id}" class="w-full text-right border rounded-xl px-3 py-2 hover:bg-sky-50 text-xs flex flex-wrap items-center gap-2">
                    ${plate(x.plate)} <b>${esc(x.insured || '')}</b> <span class="text-slate-400">${esc(fa(x.code))}${x.company ? ' · ' + esc(x.company) : ''}</span>${x.has_appt ? '<span class="mr-auto text-[10px] text-sky-700 font-bold">نوبت دارد</span>' : ''}</button>`).join('')
                    || (inp.value.trim().length > 1 ? '<p class="text-[11px] text-slate-400">درخواستِ بدنه‌ی بازی پیدا نشد.</p>' : '');
                box.querySelectorAll('[data-c]').forEach(b => b.onclick = async () => { cid = +b.dataset.c; info = await post({ action: 'case', case_id: cid }); pickTime(); });
            }, 300); };
        };
        const pickTime = () => {
            const days = (info && info.slots) || [];
            let sel = at || '';
            const draw = () => {
                step.innerHTML = `${info && info.appointment && info.appointment.status === 'BOOKED' ? `<p class="text-[11px] bg-amber-50 text-amber-800 rounded-xl p-2 mb-2">نوبتِ فعلی: ${esc(info.slot_fa)} — با ثبتِ زمانِ تازه جایگزین می‌شود.</p>` : ''}
                    <p class="text-[11px] font-bold text-slate-500 mb-1">یکی از نوبت‌های ۱۴ روزِ آینده (نوبتِ پُر هم برای کارشناس قابلِ انتخاب است):</p>
                    <div class="max-h-[300px] overflow-y-auto space-y-2 border rounded-xl p-2">${days.map(d => `<div><p class="text-[11px] font-black ${d.closed ? 'text-slate-400' : 'text-slate-700'}">${esc(d.weekday)} ${fa(d.jdate)}${d.closed ? ' (' + esc(d.closed) + ')' : ''}</p>
                        <div class="flex flex-wrap gap-1 mt-1">${d.slots.map(x => `<button data-at="${esc(x.at)}" class="px-2 py-1 rounded-lg text-[11px] font-bold border ${x.at === sel ? 'bg-sky-600 text-white border-sky-600' : x.used >= x.cap ? 'bg-slate-50 text-slate-400 border-slate-200' : 'bg-white text-slate-700 border-slate-200 hover:border-sky-400'}">${fa(x.time)}${x.used ? ' · ' + fa(x.used) : ''}</button>`).join('') || '<span class="text-[10.5px] text-slate-300">—</span>'}</div></div>`).join('')}</div>
                    <div class="grid grid-cols-3 gap-2 mt-3 text-[11px]">
                      <label class="font-bold text-slate-500">یا تاریخِ دلخواه<input data-d dir="ltr" placeholder="1405/07/30" class="w-full border rounded-lg px-2 py-1.5 mt-1"></label>
                      <label class="font-bold text-slate-500">ساعت<input data-t dir="ltr" placeholder="10:30" class="w-full border rounded-lg px-2 py-1.5 mt-1"></label>
                      <label class="font-bold text-slate-500">توضیح (اختیاری)<input data-n class="w-full border rounded-lg px-2 py-1.5 mt-1"></label></div>
                    <button data-save class="w-full mt-3 bg-sky-600 hover:bg-sky-700 text-white text-sm font-black py-2.5 rounded-xl"><i class="fas fa-check ml-1"></i>ثبتِ نوبت و خبر به کاربر در ربات</button>`;
                step.querySelectorAll('[data-at]').forEach(b => b.onclick = () => { sel = b.dataset.at; draw(); });
                step.querySelector('[data-save]').onclick = async e => {
                    const dd = step.querySelector('[data-d]').value.trim(), tt = step.querySelector('[data-t]').value.trim();
                    const body = { action: 'book', case_id: cid, note: step.querySelector('[data-n]').value };
                    if (dd || tt) { body.date = dd; body.time = tt; } else if (sel) body.at = sel; else return toast('یک نوبت یا تاریخ و ساعت انتخاب کنید.', 'error');
                    e.currentTarget.disabled = true;
                    const r = await post(body);
                    if (!r.ok) { e.currentTarget.disabled = false; return toast(r.error || 'خطا', 'error'); }
                    m.close(); toast('نوبت ثبت شد: ' + r.slot_fa, 'success'); refreshAll();
                };
            };
            draw();
        };
        if (cid) { if (!info) info = await post({ action: 'case', case_id: cid }); pickTime(); } else pickCase();
    }

    // ---------------- کادرِ نوبت در صفحه‌ی درخواست ----------------
    async function caseBox(el, caseId) {
        if (!el) return;
        css(); el.dataset.vaCase = caseId;
        const d = await post({ action: 'case', case_id: caseId });
        if (!d.ok) { el.innerHTML = ''; return; }
        const a = d.appointment;
        el.innerHTML = `<div class="bg-sky-50 border border-sky-100 rounded-xl p-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs font-bold text-sky-800"><i class="fas fa-calendar-check ml-1"></i>نوبتِ بازدیدِ خودرو
              <span class="block font-normal text-[11px] text-slate-600 mt-0.5">${a ? `${esc(a.weekday)} ${fa(a.jdate)} ساعت ${fa(a.time)} · <b>${(ST_FA[a.status] || ST_FA.BOOKED)[0]}</b> · ${SRC_FA[a.source] || ''}${a.status === 'BOOKED' && a.past ? ' · <b class="text-orange-600">گذشته؛ گزارش صادر نشده</b>' : ''}` : 'نوبتی ثبت نشده است. صدورِ بیمه‌نامه منوط به انجامِ بازدیدِ خودرو است.'}</span></p>
            <span class="flex gap-1.5">${can('edit') && (!a || a.status !== 'DONE') ? `<button data-b class="text-[11px] font-bold text-white bg-sky-600 hover:bg-sky-700 px-3 py-1.5 rounded-lg">${a && a.status === 'BOOKED' ? 'تغییرِ زمان' : 'ثبتِ نوبتِ دستی'}</button>` : ''}
              ${a ? `<button data-d class="text-[11px] font-bold text-sky-700 bg-white border border-sky-200 px-3 py-1.5 rounded-lg">جزئیات</button>` : ''}</span></div>`;
        const b = el.querySelector('[data-b]'); if (b) b.onclick = () => bookDialog(caseId, null, d);
        const dd = el.querySelector('[data-d]'); if (dd) dd.onclick = () => detail(caseId);
    }

    window.VisitAppt = { card, caseBox, detail, bookDialog };
})();
