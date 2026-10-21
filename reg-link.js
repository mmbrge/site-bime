// فایل: reg-link.js
// «پرونده‌ی خودروها و اشخاص» در فرم‌های ثبتِ درخواست: با نوشتنِ پلاک / شاسی / موتور / کد ملی، اگر همین خودرو یا شخص
// قبلاً در سامانه هست، کادری با اطلاعاتش نشان داده می‌شود و با یک دکمه اطلاعات (و مدارکِ انتخاب‌شده) در درخواستِ تازه می‌نشیند.
//   RegLink.vehicle(q, {portal})    => Promise<داده | null>   (q: plate یا p1/p2/letter/p4، vin، engine، exclude_plate_id)
//   RegLink.person(nid, name)        => Promise<{person, suggest}>
//   RegLink.vehicleCard(v, sel)      => HTML کادرِ پیشنهاد (دکمه‌ها: data-reg-fill، چک‌باکسِ مدارک: data-reg-doc="index")
//   RegLink.attach(target, docs, {portal}) => Promise  (target: {plate_id} یا {case_id})
//   RegLink.debounce(fn, ms)
(function () {
    'use strict';
    if (window.RegLink) return;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const base = () => (/\/company-portal\//.test(location.pathname) ? '../' : '');
    const url = p => '/' + String(p || '').split('/').map(encodeURIComponent).join('/');
    const cache = {};
    async function post(portal, body) {
        const api = portal ? base() + 'api/company_portal_actions.php' : 'api/registry_actions.php';
        try { return await (await fetch(api, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(body)})).json(); }
        catch (e) { return {ok: false}; }
    }
    function qKey(q) {
        const c = x => en(x || '').replace(/\s+/g, '').toUpperCase();
        const plateOk = (c(q.p1) + c(q.p2) + c(q.p4)).length >= 7 || c(q.plate).length >= 7;
        const vin = c(q.vin).replace(/[^A-Z0-9]/g, ''), eng = c(q.engine).replace(/[^A-Z0-9]/g, '');
        if (!plateOk && vin.length < 6 && eng.length < 5) return '';
        return [plateOk ? c(q.plate) + '|' + c(q.p1) + c(q.p2) + c(q.letter) + c(q.p4) : '', vin.length >= 6 ? vin : '', eng.length >= 5 ? eng : '', q.exclude_plate_id || ''].join('#');
    }
    async function vehicle(q, o = {}) {
        const k = qKey(q);
        if (!k) return null;
        const ck = (o.portal ? 'P' : 'A') + k;
        if (cache[ck] !== undefined) return cache[ck];
        const body = Object.assign({action: o.portal ? 'registry_lookup' : 'lookup'}, q);
        ['p1', 'p2', 'p4', 'vin', 'engine'].forEach(f => { if (body[f]) body[f] = en(body[f]); });
        const d = await post(o.portal, body);
        return (cache[ck] = d && d.ok ? (d.vehicle || null) : null);
    }
    async function person(nid, name) {
        nid = en(nid || '').replace(/\D/g, '');
        if (nid.length !== 10 && String(name || '').trim().length < 3) return {person: null, suggest: []};
        const ck = 'N' + nid + '|' + (nid.length === 10 ? '' : String(name).trim());
        if (cache[ck] !== undefined) return cache[ck];
        const d = await post(false, {action: 'lookup', nid, name: nid.length === 10 ? '' : name});
        return (cache[ck] = d && d.ok ? {person: d.person || null, suggest: d.suggest || []} : {person: null, suggest: []});
    }
    // sel: Set از اندیسِ مدارکِ تیک‌خورده؛ o.applied: اطلاعات قبلاً نشانده شده
    function vehicleCard(v, sel, o = {}) {
        if (!v) return '';
        const s = v.summary || {}, docs = v.docs || [];
        const bits = [s.car && esc(fa(s.car)), s.model_year && 'مدل ' + fa(s.model_year), s.color && esc(s.color), s.plate && esc(fa(s.plate))].filter(Boolean).join(' · ');
        const who = [s.owner && 'مالک/بیمه‌گذار: ' + esc(s.owner), s.company && 'شرکت: ' + esc(s.company), s.person && s.person !== s.owner && 'پرسنل: ' + esc(s.person)].filter(Boolean).join(' · ');
        const last = v.last ? `آخرین بیمه‌نامه: ${esc(v.last.type)} ${v.last.policy ? '<span dir="ltr">' + esc(fa(v.last.policy)) + '</span>' : ''}${v.last.end_date ? ' تا ' + esc(fa(v.last.end_date)) : ''}` : '';
        const open = (v.open || []).length ? `<p class="text-amber-700 font-bold mt-1"><i class="fas fa-triangle-exclamation ml-1"></i>درخواستِ باز برای همین خودرو هست: ${v.open.map(x => esc(x.type) + (x.company ? ' · ' + esc(x.company) : '') + (x.request_id ? ' (#' + fa(x.request_id) + ')' : '')).join('، ')}</p>` : '';
        return `<div class="reg-card mt-2 rounded-xl border border-sky-200 bg-sky-50 p-2.5 text-[11px] leading-6 text-slate-700">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0"><b class="text-sky-800"><i class="fas fa-magnifying-glass-plus ml-1"></i>این خودرو قبلاً در سامانه هست${v.match ? ' (با ' + esc(v.match) + ')' : ''}</b>
                    <div>${bits}</div>${who ? `<div class="text-slate-500">${who}</div>` : ''}${last ? `<div class="text-slate-500">${last}</div>` : ''}</div>
                <div class="flex gap-1.5 flex-wrap">
                    <button type="button" data-reg-fill class="text-[10.5px] font-bold text-white ${o.applied ? 'bg-emerald-600' : 'bg-sky-600 hover:bg-sky-700'} rounded-lg px-2.5 py-1">${o.applied ? '<i class="fas fa-check ml-1"></i>اطلاعات نشست' : '<i class="fas fa-file-import ml-1"></i>بیار اطلاعات'}</button>
                    ${o.portal || !window.Vehicles ? '' : `<button type="button" data-reg-open="${esc(v.key)}" class="text-[10.5px] font-bold bg-white border border-sky-200 text-sky-700 rounded-lg px-2.5 py-1">پرونده</button>`}
                    <button type="button" data-reg-dismiss class="text-[10.5px] text-slate-400 hover:text-slate-600 px-1" title="نادیده بگیر">✕</button></div>
            </div>${open}
            ${docs.length ? `<div class="mt-1.5 border-t border-sky-100 pt-1.5"><span class="font-bold text-slate-600">مدارکِ موجود (تیک‌خورده‌ها بعد از ثبت روی همین ردیف هم می‌نشینند):</span>
                <div class="flex flex-wrap gap-1.5 mt-1">${docs.map((d, i) => `<label class="inline-flex items-center gap-1 bg-white border ${sel && sel.has(i) ? 'border-emerald-300 text-emerald-800' : 'border-slate-200 text-slate-600'} rounded-lg px-2 py-0.5 cursor-pointer">
                    <input type="checkbox" data-reg-doc="${i}" ${sel && sel.has(i) ? 'checked' : ''}> ${esc(fa(d.label))}${d.at ? ' <span class="text-slate-400">' + esc(fa(d.at)) + '</span>' : ''}
                    <a href="${url(d.file)}" target="_blank" class="text-blue-500 mr-1" title="مشاهده" onclick="event.stopPropagation()"><i class="fas fa-eye"></i></a></label>`).join('')}</div></div>` : ''}
        </div>`;
    }
    // کادرِ شخص (کد ملی): o.applied
    function personCard(p, o = {}) {
        if (!p) return '';
        const bits = [p.full_name && '<b>' + esc(p.full_name) + '</b>', p.personnel_code && 'کد پرسنلی ' + esc(fa(p.personnel_code)), p.company && esc(p.company), p.mobile && '<span dir="ltr">' + esc(fa(p.mobile)) + '</span>',
                      p.postal_code && 'کدپستی ' + esc(fa(p.postal_code)), p.last_plate && 'آخرین پلاک ' + esc(fa(p.last_plate))].filter(Boolean).join(' · ');
        return `<div class="reg-card mt-2 rounded-xl border border-sky-200 bg-sky-50 p-2.5 text-[11px] leading-6 text-slate-700 flex flex-wrap items-center justify-between gap-2">
            <div><b class="text-sky-800"><i class="fas fa-user-check ml-1"></i>این شخص قبلاً در سامانه هست:</b> ${bits}</div>
            <div class="flex gap-1.5"><button type="button" data-reg-pfill class="text-[10.5px] font-bold text-white ${o.applied ? 'bg-emerald-600' : 'bg-sky-600 hover:bg-sky-700'} rounded-lg px-2.5 py-1">${o.applied ? '<i class="fas fa-check ml-1"></i>نشست' : '<i class="fas fa-file-import ml-1"></i>بیار اطلاعات'}</button>
            <button type="button" data-reg-dismiss class="text-[10.5px] text-slate-400 hover:text-slate-600 px-1">✕</button></div></div>`;
    }
    async function attach(target, docs, o = {}) {
        if (!docs || !docs.length) return {ok: true, attached: 0};
        const body = Object.assign({action: o.portal ? 'registry_attach' : 'attach', docs: docs.map(d => ({src: d.src, id: d.id, type: d.type}))}, target);
        return (await post(o.portal, body)) || {ok: false};
    }
    function debounce(fn, ms) { let t = null; return function () { const a = arguments, self = this; clearTimeout(t); t = setTimeout(() => fn.apply(self, a), ms || 600); }; }
    window.RegLink = { vehicle, person, vehicleCard, personCard, attach, debounce };
})();
