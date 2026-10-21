// فایل: renew-req.js
// «درخواستِ تمدید» از روی بیمه‌نامه‌های قبلی («اعلام تمدید» و «بردروی پاسارگاد»): برای هر ردیف درخواستِ صدورِ تازه با همان مشخصات
// ساخته می‌شود و مدارکِ موجود (کارت/سند/فاکتور و خودِ بیمه‌نامه‌ی قبلی) مستقیم روی درخواست می‌نشیند.
//   بدنه: «بدونِ خسارت» => گزارش عدم خسارت لازم و بازدید نه؛ «خسارت داشته» => بازدیدِ سلامت دوباره
//   RenewReq.open(items, {onDone, onPersonnel})
//     items: [{src: 'C'|'I'|'B'|'P', id, label, type: 'BODY'|'THIRDPARTY', plate, company, renewed}]
//     onPersonnel(item): ردیفِ کارکنان (فرمِ «ثبتِ دستیِ درخواستِ کارکنان» با همان اطلاعات باز می‌شود)
(function () {
    'use strict';
    if (window.RenewReq) return;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));

    function open(items, opts = {}) {
        items = (items || []).filter(Boolean);
        if (!items.length) { toast('ردیفی انتخاب نشده.', 'warning'); return; }
        const co = items.filter(it => it.src !== 'P'), pers = items.filter(it => it.src === 'P');
        const st = co.map(it => ({...it, type: it.type === 'BODY' ? 'BODY' : 'THIRDPARTY', claim: 'NO_CLAIM', on: !it.renewed}));
        // شرکتِ درخواست‌دهنده (پیش‌فرض: شرکتِ خودِ هر بیمه‌نامه)، پوشش/تعهد (قبلی یا پیش‌فرضِ شرکت)، نامه و توضیح
        const G = {company: '', values: 'prev', letter: null, text: '', companies: null};
        fetch('api/renewal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify({action: 'companies'})})
            .then(r => r.json()).then(d => { G.companies = d.ok ? d.companies : []; if (ov.isConnected) draw(); }).catch(() => { G.companies = []; });
        const ov = document.createElement('div');
        ov.className = 'fixed inset-0 flex items-center justify-center p-3';
        ov.style.cssText = 'z-index:1000004;background:rgba(15,23,42,.6);backdrop-filter:blur(3px)';
        document.body.appendChild(ov);
        const close = () => ov.remove();
        ov.addEventListener('click', e => { if (e.target === ov) close(); });
        const draw = () => {
            const nBody = st.filter(x => x.on && x.type === 'BODY').length;
            ov.innerHTML = `<div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl max-h-[92vh] flex flex-col overflow-hidden">
                <div class="p-4 text-white" style="background:linear-gradient(120deg,#047857,#0d9488 55%,#0e7490)">
                    <h3 class="font-black text-base"><i class="fas fa-rotate ml-1"></i>درخواستِ تمدید (صدورِ تازه)</h3>
                    <p class="text-[11px] opacity-90 mt-1 leading-6">برای هر ردیف درخواستِ صدور برای همان شرکت ساخته می‌شود (ردیف‌های یک شرکت در یک درخواست). کارت/سند/فاکتور و خودِ بیمه‌نامه‌ی قبلی اگر در سامانه باشد مستقیم روی درخواست می‌نشیند.
                    بدنه: <b>بدونِ خسارت</b> = «گزارش عدم خسارت» لازم و بازدید نه؛ <b>خسارت داشته</b> = بازدیدِ سلامت دوباره لازم.</p>
                </div>
                <div class="p-4 overflow-y-auto flex-1 text-[12px]">
                    ${st.length ? `<div class="grid gap-2 mb-3" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr))">
                        <label class="text-[11px] font-bold text-slate-600">شرکتِ درخواست‌دهنده
                            <select data-gco class="mt-1 w-full border border-slate-200 rounded-xl px-2 py-1.5 text-[12px] bg-white"><option value="">شرکتِ خودِ هر بیمه‌نامه</option>${(G.companies || []).map(c => `<option value="${c.id}" ${String(c.id) === String(G.company) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
                        <div class="text-[11px] font-bold text-slate-600">پوشش‌ها، ارزش و تعهدِ مالی
                            <label class="flex items-center gap-1.5 mt-1.5 cursor-pointer"><input type="radio" name="rr-val" value="prev" ${G.values === 'prev' ? 'checked' : ''}> همان بیمه‌نامه‌ی قبلی (با الحاقیه‌ها)</label>
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="radio" name="rr-val" value="company" ${G.values === 'company' ? 'checked' : ''}> پیش‌فرضِ تعریف‌شده‌ی شرکت</label></div>
                        <label class="text-[11px] font-bold text-slate-600">نامه‌ی درخواست (اختیاری)
                            <input type="file" data-glet accept=".pdf,.jpg,.jpeg,.png,.webp" class="mt-1 w-full text-[11px]"><span class="text-[10px] text-slate-400 font-normal">${G.letter ? esc(G.letter.name) : 'روی هر درخواستِ ساخته‌شده بایگانی می‌شود'}</span></label>
                        <label class="text-[11px] font-bold text-slate-600">توضیحِ درخواست<input data-gtext class="mt-1 w-full border border-slate-200 rounded-xl px-2 py-1.5 text-[12px]" value="${esc(G.text)}" placeholder="مثلاً: تمدیدِ بیمه‌نامه‌های مهرماه"></label>
                    </div>
                    ${!G.company && st.some(x => x.on && x.no_company) ? '<p class="text-[11px] text-amber-700 font-bold mb-2"><i class="fas fa-triangle-exclamation ml-1"></i>بعضی ردیف‌ها به شرکتی وصل نیستند؛ «شرکتِ درخواست‌دهنده» را انتخاب کنید.</p>' : ''}
                    ${nBody > 1 ? `<div class="flex flex-wrap items-center gap-2 mb-2 text-[11px] font-bold text-slate-600">برای همه‌ی بدنه‌ها:
                        <button type="button" data-allc="NO_CLAIM" class="rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 px-2.5 py-1">بدونِ خسارت</button>
                        <button type="button" data-allc="CLAIM" class="rounded-lg bg-amber-50 text-amber-700 border border-amber-200 px-2.5 py-1">خسارت داشته</button></div>` : ''}
                    <table class="w-full text-right"><thead class="text-[11px] text-slate-500"><tr><th class="p-1.5"></th><th class="p-1.5">بیمه‌نامه / خودرو</th><th class="p-1.5">شرکت</th><th class="p-1.5">نوع</th><th class="p-1.5">خسارت (بدنه)</th></tr></thead>
                    <tbody>${st.map((x, i) => `<tr class="border-t border-slate-100 ${x.on ? '' : 'opacity-50'}">
                        <td class="p-1.5"><input type="checkbox" data-on="${i}" ${x.on ? 'checked' : ''}></td>
                        <td class="p-1.5"><b>${esc(fa(x.label || ''))}</b>${x.plate ? `<div class="text-[10.5px] text-slate-500">${esc(fa(x.plate))}</div>` : ''}${x.renewed ? '<div class="text-[10px] text-emerald-600 font-bold">قبلاً تمدید شده</div>' : ''}</td>
                        <td class="p-1.5">${G.company ? `<span class="text-emerald-700">${esc(((G.companies || []).find(c => String(c.id) === String(G.company)) || {}).name || '')}</span>` : (x.no_company ? '<span class="text-amber-600">وصل نیست</span>' : esc(x.company || '—'))}</td>
                        <td class="p-1.5"><select data-type="${i}" class="border border-slate-200 rounded-lg px-1.5 py-1 text-[11px] bg-white"><option value="THIRDPARTY" ${x.type === 'THIRDPARTY' ? 'selected' : ''}>ثالث</option><option value="BODY" ${x.type === 'BODY' ? 'selected' : ''}>بدنه</option></select></td>
                        <td class="p-1.5">${x.type === 'BODY' ? `<select data-claim="${i}" class="border border-slate-200 rounded-lg px-1.5 py-1 text-[11px] bg-white"><option value="NO_CLAIM" ${x.claim === 'NO_CLAIM' ? 'selected' : ''}>بدونِ خسارت (گزارش عدم خسارت)</option><option value="CLAIM" ${x.claim === 'CLAIM' ? 'selected' : ''}>خسارت داشته (بازدیدِ دوباره)</option></select>` : '<span class="text-slate-400 text-[11px]">همان مدارکِ قبلی</span>'}</td></tr>`).join('')}</tbody></table>` : ''}
                    ${pers.length ? `<div class="mt-3 rounded-xl border border-sky-200 bg-sky-50 p-3 text-[11px] text-slate-700"><b class="text-sky-800"><i class="fas fa-id-card ml-1"></i>بیمه‌نامه‌های کارکنان</b> — از فرمِ «ثبتِ دستیِ درخواستِ کارکنان» با همان اطلاعات (مدارکِ خودرو هم از پرونده پیشنهاد می‌شود):
                        <div class="flex flex-wrap gap-1.5 mt-1.5">${pers.map((p, i) => `<button type="button" data-pers="${i}" class="bg-white border border-sky-200 rounded-lg px-2.5 py-1 hover:bg-sky-100">${esc(fa(p.label || ''))}${p.plate ? ' · ' + esc(fa(p.plate)) : ''}</button>`).join('')}</div></div>` : ''}
                    <div data-res></div>
                </div>
                <div class="p-3 border-t border-slate-100 flex justify-between items-center gap-2">
                    <span class="text-[11px] text-slate-500">${fa(st.filter(x => x.on).length)} ردیف برای ساختِ درخواست</span>
                    <span class="flex gap-2"><button type="button" data-x class="rounded-xl bg-slate-100 text-slate-700 font-bold text-xs px-4 py-2">بستن</button>
                    ${st.length ? '<button type="button" data-go class="rounded-xl text-white font-black text-xs px-4 py-2" style="background:linear-gradient(120deg,#047857,#0d9488)"><i class="fas fa-check ml-1"></i>ساختِ درخواست‌ها</button>' : ''}</span>
                </div></div>`;
            ov.querySelector('[data-x]').onclick = close;
            const gco = ov.querySelector('[data-gco]'); if (gco) gco.onchange = () => { G.company = gco.value; draw(); };
            ov.querySelectorAll('[name="rr-val"]').forEach(x => x.onchange = () => { G.values = x.value; });
            const gl = ov.querySelector('[data-glet]'); if (gl) gl.onchange = () => { G.letter = gl.files[0] || null; };
            const gt = ov.querySelector('[data-gtext]'); if (gt) gt.oninput = () => { G.text = gt.value; };
            ov.querySelectorAll('[data-on]').forEach(c => c.onchange = () => { st[+c.dataset.on].on = c.checked; draw(); });
            ov.querySelectorAll('[data-type]').forEach(c => c.onchange = () => { st[+c.dataset.type].type = c.value; draw(); });
            ov.querySelectorAll('[data-claim]').forEach(c => c.onchange = () => { st[+c.dataset.claim].claim = c.value; });
            ov.querySelectorAll('[data-allc]').forEach(b => b.onclick = () => { st.forEach(x => { if (x.type === 'BODY') x.claim = b.dataset.allc; }); draw(); });
            ov.querySelectorAll('[data-pers]').forEach(b => b.onclick = () => { if (typeof opts.onPersonnel === 'function') { close(); opts.onPersonnel(pers[+b.dataset.pers]); } });
            const go = ov.querySelector('[data-go]');
            if (go) go.onclick = async () => {
                const sel = st.filter(x => x.on);
                if (!sel.length) { toast('ردیفی انتخاب نشده.', 'warning'); return; }
                go.disabled = true; go.innerHTML = '<i class="fas fa-circle-notch fa-spin ml-1"></i> در حالِ ساخت و انتقالِ مدارک…';
                let d;
                try { d = await (await fetch('api/renewal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin',
                    body: JSON.stringify({action: 'create_requests', company_id: G.company || 0, values: G.values, text: G.text, items: sel.map(x => ({src: x.src, id: x.id, type: x.type, claim: x.type === 'BODY' ? x.claim : ''}))})})).json(); }
                catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
                go.disabled = false; go.innerHTML = '<i class="fas fa-check ml-1"></i>ساختِ درخواست‌ها';
                if (!d.ok) { toast(d.error || 'خطا', 'error'); return; }
                const reqs = {};
                (d.created || []).forEach(c => { (reqs[c.request_id] = reqs[c.request_id] || {company: c.company, rows: 0, docs: 0}); reqs[c.request_id].rows++; reqs[c.request_id].docs += c.docs || 0; });
                // نامه روی هر درخواستِ ساخته‌شده (پوشه‌ی نامه‌ی همان درخواست) — در پس‌زمینه اگر ابزارِ بارگذاری هست
                if (G.letter && Object.keys(reqs).length) {
                    const mk = rid => { const fd = new FormData(); fd.append('action', 'admin_upload_plate_doc'); fd.append('request_id', rid); fd.append('doc_type', 'letter'); fd.append('file', G.letter, G.letter.name); return fd; };
                    if (window.UploadKit && UploadKit.enqueue) UploadKit.enqueue({title: 'نامه‌ی درخواست‌های تمدید', url: 'api/company_actions.php', items: Object.keys(reqs).map(rid => ({name: 'نامه — درخواست #' + fa(rid), size: G.letter.size, form: mk(rid)}))});
                    else for (const rid of Object.keys(reqs)) { try { await fetch('api/company_actions.php', {method: 'POST', body: mk(rid), credentials: 'same-origin'}); } catch (e) {} }
                }
                ov.querySelector('[data-res]').innerHTML = `<div class="mt-3 rounded-xl border ${Object.keys(reqs).length ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'} p-3 text-[11.5px] leading-7">
                    ${Object.keys(reqs).length ? `<b class="text-emerald-800"><i class="fas fa-circle-check ml-1"></i>${fa((d.created || []).length)} ردیف در ${fa(Object.keys(reqs).length)} درخواست ساخته شد:</b>
                    ${Object.entries(reqs).map(([rid, r]) => `<div>درخواست <b>#${fa(rid)}</b> · ${esc(r.company)} · ${fa(r.rows)} ردیف · ${fa(r.docs)} مدرک منتقل شد ${window.openCompanyRequestDetail ? `<button type="button" data-open="${rid}" class="text-blue-600 underline font-bold mr-1">باز کردن</button>` : ''}</div>`).join('')}` : ''}
                    ${(d.errors || []).length ? `<div class="text-amber-800 mt-1">${d.errors.map(e => '• ' + esc(fa(e))).join('<br>')}</div>` : ''}</div>`;
                ov.querySelectorAll('[data-open]').forEach(b => b.onclick = () => { close(); openCompanyRequestDetail(+b.dataset.open); });
                st.forEach(x => { if (x.on && (d.created || []).some(c => c.label && x.label && c.label.indexOf(x.label) >= 0)) x.on = false; });
                if (typeof opts.onDone === 'function') opts.onDone(d);
                toast(`${fa((d.created || []).length)} درخواستِ تمدید ساخته شد.`, (d.created || []).length ? 'success' : 'warning');
            };
        };
        draw();
    }
    window.RenewReq = { open };
})();
