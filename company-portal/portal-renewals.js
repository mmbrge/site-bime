// فایل: company-portal/portal-renewals.js
// پنلِ شرکت‌ها ← «تمدیدی‌ها» (فقط اگر برای کاربر باز شده): بیمه‌نامه‌های قبلیِ شرکت‌های همین کاربر (صادره‌ها و بردرو)
// با اطلاعاتِ کلی (پلاک/شاسی، شرکت، شروع/انقضا، حق بیمه‌ی قبلی)، جستجو و فیلتر، انتخابِ چند ردیف و «درخواستِ تمدید».
(function () {
    'use strict';
    if (window.PortalRenewals) return;
    const API = '../api/company_portal_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => String(v == null ? '' : v).replace(/\d/g, d => FA[d]);
    const en = v => String(v || '').replace(/[۰-۹]/g, d => FA.indexOf(d));
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => Number(v) ? fa(Number(v).toLocaleString('en-US')) : '—';
    const norm = s => en(s).toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[\s‌\-_/.()]+/g, '');
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t) : alert(m));
    const S = {root: null, rows: [], sel: new Set(), f: {q: '', type: '', company: '', state: 'open', exp: ''}};

    async function post(body) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(body)})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }
    async function open(root) {
        S.root = root;
        root.innerHTML = '<p class="text-center text-slate-400 text-sm py-10"><i class="fas fa-spinner fa-spin ml-1"></i>در حال بارگذاری…</p>';
        const d = await post({action: 'renewals_list'});
        if (!d.ok) { root.innerHTML = `<p class="text-center text-rose-600 text-sm py-10">${esc(d.error || 'خطا')}</p>`; return; }
        S.rows = d.rows || [];
        S.rows.forEach(r => { r._s = norm([r.plate, r.chassis, r.car, r.company, r.type_fa, r.start_date, r.end_date].join(' ')); });
        const keys = new Set(S.rows.map(r => r.key)); [...S.sel].forEach(k => { if (!keys.has(k)) S.sel.delete(k); });
        render();
    }
    function match(r) {
        const f = S.f;
        if (f.state === 'open' && r.state === 'renewed') return false;
        if (f.state && f.state !== 'open' && r.state !== f.state) return false;
        if (f.type && r.type !== f.type) return false;
        if (f.company && String(r.company_id) !== f.company) return false;
        const dl = r.days_left;
        if (f.exp === '30' && !(dl !== null && dl >= 0 && dl <= 30)) return false;
        if (f.exp === '60' && !(dl !== null && dl >= 0 && dl <= 60)) return false;
        if (f.exp === 'expired' && !(dl !== null && dl < 0)) return false;
        if (f.q && !r._s.includes(norm(f.q))) return false;
        return true;
    }
    function render() {
        const list = S.rows.filter(match).sort((a, b) => (a.days_left ?? 99999) - (b.days_left ?? 99999));
        const companies = [...new Map(S.rows.map(r => [String(r.company_id), r.company])).entries()];
        const f = S.f, sel = S.sel;
        const dayPill = r => r.days_left === null ? '' : `<span class="text-[10px] font-bold rounded-full px-2 py-0.5 ${r.days_left < 0 ? 'bg-rose-100 text-rose-700' : r.days_left <= 30 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}">${r.days_left < 0 ? fa(-r.days_left) + ' روز گذشته' : fa(r.days_left) + ' روز مانده'}</span>`;
        S.root.innerHTML = `
        <div class="rounded-3xl p-5 text-white mb-3" style="background:linear-gradient(120deg,#047857,#0d9488 55%,#0e7490)">
            <h2 class="font-black text-lg"><i class="fas fa-rotate ml-1"></i>تمدیدی‌ها</h2>
            <p class="text-[11.5px] opacity-90 mt-1 leading-6">بیمه‌نامه‌های قبلیِ شرکت‌تان. ردیف‌هایی را که می‌خواهید تمدید شوند انتخاب کنید و «درخواستِ تمدید» را بزنید؛ برای هر شرکت یک درخواستِ تمدیدی با همان مشخصات ساخته می‌شود و در «درخواست‌ها» می‌آید.</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center bg-white border border-slate-100 rounded-2xl p-3 mb-2 text-xs">
            <input data-f="q" value="${esc(f.q)}" placeholder="جستجو: پلاک، شاسی، خودرو، شرکت، تاریخ…" class="border rounded-xl px-3 py-2 flex-1 min-w-[200px]">
            ${companies.length > 1 ? `<select data-f="company" class="border rounded-xl px-2 py-2 font-bold"><option value="">همه‌ی شرکت‌ها</option>${companies.map(([id, n]) => `<option value="${esc(id)}" ${f.company === id ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select>` : ''}
            <select data-f="type" class="border rounded-xl px-2 py-2 font-bold"><option value="">بدنه و ثالث</option><option value="BODY" ${f.type === 'BODY' ? 'selected' : ''}>بدنه</option><option value="THIRDPARTY" ${f.type === 'THIRDPARTY' ? 'selected' : ''}>ثالث</option></select>
            <select data-f="exp" class="border rounded-xl px-2 py-2 font-bold"><option value="">همه‌ی تاریخ‌ها</option><option value="30" ${f.exp === '30' ? 'selected' : ''}>انقضا تا ۳۰ روز</option><option value="60" ${f.exp === '60' ? 'selected' : ''}>انقضا تا ۶۰ روز</option><option value="expired" ${f.exp === 'expired' ? 'selected' : ''}>منقضی‌شده</option></select>
            <select data-f="state" class="border rounded-xl px-2 py-2 font-bold"><option value="open" ${f.state === 'open' ? 'selected' : ''}>منتظرِ تمدید</option><option value="requested" ${f.state === 'requested' ? 'selected' : ''}>درخواستِ تمدید ثبت شد</option><option value="renewed" ${f.state === 'renewed' ? 'selected' : ''}>تمدید شده</option><option value="" ${f.state === '' ? 'selected' : ''}>همه</option></select>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2 text-[11.5px]">
            <span class="text-slate-500">${fa(list.length)} بیمه‌نامه${sel.size ? ` · <b class="text-emerald-700">${fa(sel.size)} انتخاب‌شده</b>` : ''}</span>
            <span class="flex gap-2">${sel.size ? '<button data-unsel class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl px-3 py-1.5"><i class="fas fa-xmark ml-1"></i>لغوِ انتخاب</button>' : ''}
                <button data-req ${sel.size ? '' : 'disabled'} class="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 text-white font-black rounded-xl px-4 py-1.5"><i class="fas fa-rotate ml-1"></i>درخواستِ تمدید${sel.size ? ' (' + fa(sel.size) + ')' : ''}</button></span>
        </div>
        <div class="bg-white border border-slate-100 rounded-2xl overflow-auto">
            <table class="w-full text-[11.5px]"><thead class="bg-slate-50 text-slate-500"><tr>
                <th class="p-2 w-8"><input type="checkbox" data-all ${list.length && list.filter(r => r.state !== 'renewed').every(r => sel.has(r.key)) ? 'checked' : ''}></th>
                <th class="p-2 text-right">پلاک / شاسی</th><th class="p-2 text-right">خودرو</th><th class="p-2 text-right">نوع</th><th class="p-2 text-right">شرکت</th>
                <th class="p-2 text-right">شروع</th><th class="p-2 text-right">انقضا</th><th class="p-2 text-right">حق بیمه‌ی قبلی</th><th class="p-2 text-right">وضعیت</th></tr></thead>
            <tbody>${list.map(r => `<tr class="border-t border-slate-100 ${sel.has(r.key) ? 'bg-emerald-50/60' : ''}">
                <td class="p-2 text-center">${r.state === 'renewed' ? '' : `<input type="checkbox" data-sel="${esc(r.key)}" ${sel.has(r.key) ? 'checked' : ''}>`}</td>
                <td class="p-2">${r.plate ? (typeof formatPlateHtml === 'function' ? `<span class="plate-display">${formatPlateHtml(r.plate)}</span>` : esc(fa(r.plate))) : `<span dir="ltr" class="text-[10.5px]">${esc(r.chassis || '—')}</span>`}</td>
                <td class="p-2">${esc(r.car || '—')}</td>
                <td class="p-2"><span class="rounded-lg px-2 py-0.5 text-[10px] font-bold ${r.type === 'BODY' ? 'bg-cyan-50 text-cyan-700' : 'bg-violet-50 text-violet-700'}">${esc(r.type_fa)}</span></td>
                <td class="p-2">${esc(r.company || '')}</td>
                <td class="p-2 whitespace-nowrap">${esc(fa(r.start_date || '—'))}</td>
                <td class="p-2 whitespace-nowrap">${esc(fa(r.end_date || '—'))}${r.end_est ? '<small class="block text-[9px] text-slate-400">تخمینی</small>' : ''} ${dayPill(r)}</td>
                <td class="p-2 whitespace-nowrap">${money(r.premium)}</td>
                <td class="p-2 text-[10.5px]">${r.state === 'renewed' ? '<span class="text-emerald-700 font-bold"><i class="fas fa-circle-check ml-1"></i>تمدید شد</span>'
                    : r.state === 'requested' ? `<span class="text-sky-700 font-bold"><i class="fas fa-hourglass-half ml-1"></i>درخواستِ تمدید ثبت شد${r.renewal && r.renewal.date ? ' (' + esc(fa(r.renewal.date)) + ')' : ''}</span>` : '<span class="text-slate-400">منتظرِ تمدید</span>'}</td></tr>`).join('')
                || '<tr><td colspan="9" class="p-10 text-center text-slate-400">بیمه‌نامه‌ای با این فیلترها نیست.</td></tr>'}</tbody></table></div>`;
        const q = S.root.querySelector('[data-f="q"]');
        let t = null;
        q.oninput = () => { clearTimeout(t); t = setTimeout(() => { S.f.q = q.value; const pos = q.selectionStart; render(); const nq = S.root.querySelector('[data-f="q"]'); nq.focus(); try { nq.setSelectionRange(pos, pos); } catch (e) {} }, 250); };
        S.root.querySelectorAll('select[data-f]').forEach(el => el.onchange = () => { S.f[el.dataset.f] = el.value; render(); });
        S.root.querySelectorAll('[data-sel]').forEach(c => c.onchange = () => { c.checked ? sel.add(c.dataset.sel) : sel.delete(c.dataset.sel); render(); });
        const all = S.root.querySelector('[data-all]');
        if (all) all.onchange = () => { list.filter(r => r.state !== 'renewed').forEach(r => all.checked ? sel.add(r.key) : sel.delete(r.key)); render(); };
        const un = S.root.querySelector('[data-unsel]'); if (un) un.onclick = () => { sel.clear(); render(); };
        S.root.querySelector('[data-req]').onclick = () => request(S.rows.filter(r => sel.has(r.key)));
    }
    function request(rows) {
        if (!rows.length) return;
        const body = rows.filter(r => r.type === 'BODY');
        const ov = document.createElement('div');
        ov.className = 'fixed inset-0 flex items-center justify-center p-3';
        ov.style.cssText = 'z-index:100000;background:rgba(15,23,42,.6)';
        ov.innerHTML = `<div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg p-5 text-sm" dir="rtl">
            <h3 class="font-black text-base mb-1"><i class="fas fa-rotate text-emerald-600 ml-1"></i>درخواستِ تمدید برای ${fa(rows.length)} بیمه‌نامه</h3>
            <p class="text-[11px] text-slate-500 mb-3 leading-6">برای هر شرکت یک درخواستِ «تمدیدی» با همان مشخصاتِ قبلی ساخته می‌شود؛ بعد از ثبت، مدارکِ لازم را در همان درخواست بارگذاری کنید.</p>
            ${body.length ? `<div class="rounded-xl border border-cyan-100 bg-cyan-50/60 p-3 mb-3 text-[11.5px]"><b>بدنه (${fa(body.length)}):</b> در سالِ گذشته خسارت داشته‌اند؟
                <div class="grid gap-1 mt-2">${body.map(r => `<label class="flex items-center justify-between gap-2 bg-white rounded-lg px-2 py-1"><span>${esc(fa(r.plate || r.chassis || ''))} · ${esc(r.car || '')}</span>
                    <select data-claim="${esc(r.key)}" class="border rounded-lg px-1 py-0.5"><option value="NO_CLAIM">بدونِ خسارت (گزارش عدم خسارت)</option><option value="CLAIM">خسارت داشته (بازدید لازم)</option></select></label>`).join('')}</div></div>` : ''}
            <label class="block text-[11px] font-bold text-slate-600 mb-1">توضیح (اختیاری)</label>
            <textarea data-text class="w-full border rounded-xl p-2 text-xs" rows="2" placeholder="مثلاً: لطفاً پوشش‌ها مثلِ سالِ قبل باشد"></textarea>
            <div class="flex justify-end gap-2 mt-3"><button data-x class="bg-slate-100 text-slate-700 font-bold rounded-xl px-4 py-2 text-xs">انصراف</button>
                <button data-go class="bg-emerald-600 hover:bg-emerald-700 text-white font-black rounded-xl px-4 py-2 text-xs"><i class="fas fa-check ml-1"></i>ثبتِ درخواستِ تمدید</button></div></div>`;
        document.body.appendChild(ov);
        ov.querySelector('[data-x]').onclick = () => ov.remove();
        ov.addEventListener('click', e => { if (e.target === ov) ov.remove(); });
        ov.querySelector('[data-go]').onclick = async function () {
            const claims = {}; ov.querySelectorAll('[data-claim]').forEach(s => { claims[s.dataset.claim] = s.value; });
            this.disabled = true; this.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ ثبت…';
            const d = await post({action: 'renewals_request', keys: rows.map(r => r.key), claims, text: ov.querySelector('[data-text]').value.trim()});
            if (!d.ok) { this.disabled = false; this.innerHTML = '<i class="fas fa-check ml-1"></i>ثبتِ درخواستِ تمدید'; toast(d.error || 'خطا', 'error'); return; }
            ov.remove();
            const n = (d.created || []).length, errs = d.errors || [];
            toast(`${fa(n)} ردیف در ${fa(new Set((d.created || []).map(c => c.request_id)).size)} درخواستِ تمدیدی ثبت شد` + (errs.length ? ` · ${fa(errs.length)} مورد ساخته نشد: ${errs.slice(0, 2).join('؛ ')}` : ''), n ? 'success' : 'error');
            S.sel.clear();
            await open(S.root);
            if (n && typeof loadRequests === 'function') loadRequests();
        };
    }
    window.PortalRenewals = {open};
})();
