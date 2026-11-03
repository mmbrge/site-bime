// فایل: parties.js
// «پرونده‌ی اشخاص و شرکت‌ها»: فهرستِ هر شخصِ حقیقی و حقوقی که جایی در سایت ثبت شده (بیمه‌گذار، مالک، ذینفع، استعلام، شرکت‌ها، پرسنل)
// با آخرین اطلاعاتش؛ پرونده‌ی هر کدام: مشخصات (قابلِ ویرایش)، بیمه‌نامه‌ها (بیمه‌گذار/مالک)، درخواست‌های شرکت، استعلام‌ها،
// و پیوند به «پرونده‌ی پرسنل». API: api/party_actions.php
(function () {
    'use strict';
    if (window.Parties) return;
    const API = 'api/party_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const SRC = {registry: 'دفتر', issue: 'صدور', issue_info: 'اطلاعاتِ صدور', company: 'تعریفِ شرکت', personnel: 'پرسنل', case: 'درخواستِ کارکنان', inquiry: 'استعلام', party: 'مالک/بیمه‌گذار', manual: 'ویرایشِ دستی'};
    async function post(body) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(body)})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }
    function css() {
        if (document.getElementById('pty-css')) return;
        const s = document.createElement('style'); s.id = 'pty-css';
        s.textContent = `
        .pty-hero{border-radius:24px;padding:18px 20px;color:#fff;background:linear-gradient(120deg,#134e4a,#0f766e 45%,#4338ca);box-shadow:0 20px 40px -28px #0f766e;margin-bottom:14px}
        .pty-hero h2{font-size:18px;font-weight:900}.pty-hero p{font-size:11.5px;opacity:.85;margin-top:4px}
        .pty-tabs{display:flex;gap:6px;flex-wrap:wrap}.pty-tab{border-radius:12px;padding:7px 14px;font-size:12px;font-weight:800;background:#f1f5f9;color:#475569}.pty-tab.on{background:#0f766e;color:#fff}
        .pty-tab b{font-size:10.5px;margin-right:4px;opacity:.8}
        .pty-row{cursor:pointer}.pty-row:hover{background:#f8fafc}
        .pty-ov{position:fixed;inset:0;z-index:1000005;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:14px}
        .pty-box{width:min(1100px,100%);max-height:94vh;overflow:auto;background:#f8fafc;border-radius:22px;box-shadow:0 40px 80px -30px rgba(0,0,0,.5)}
        .pty-bh{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:14px 18px;color:#fff;background:linear-gradient(120deg,#134e4a,#0f766e);border-radius:22px 22px 0 0}
        .pty-sec{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:12px;margin-bottom:10px}
        .pty-sec h4{font-size:12.5px;font-weight:900;color:#334155;margin-bottom:8px}
        .pty-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px}
        .pty-grid label{display:block;font-size:10.5px;font-weight:800;color:#64748b}
        .pty-grid input,.pty-grid textarea{width:100%;margin-top:3px;border:1px solid #e2e8f0;border-radius:10px;padding:6px 9px;font-size:12.5px;background:#fff}
        .pty-chip{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:10px;font-weight:800;background:#f1f5f9;color:#475569}
        .pty-tb{width:100%;font-size:11.5px}.pty-tb th{font-size:10.5px;color:#64748b;text-align:right;padding:6px;background:#f8fafc}.pty-tb td{padding:6px;border-top:1px solid #f1f5f9}
        `;
        document.head.appendChild(s);
    }
    const S = {root: null, kind: '', q: '', rows: [], counts: {}};
    function init(root) {
        css();
        if (!root) return;
        S.root = root;
        if (!root.dataset.ready) {
            root.dataset.ready = '1';
            root.innerHTML = `<div class="pty-hero"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2><i class="fas fa-address-book ml-2"></i>پرونده‌ی اشخاص و شرکت‌ها</h2>
                <p>هر کد ملی / شناسه‌ی ملی که جایی ثبت شده، با آخرین اطلاعاتش. در فرمِ صدور (تکی و گروهی) نام، تلفن، کد پستی و نشانیِ بیمه‌گذار و مالک از همین‌جا می‌آید؛ از بیمه‌نامه فقط کد/شناسه‌ی ملی خوانده می‌شود.</p></div>
<div class="flex flex-wrap gap-2"><button class="px-3 py-2 rounded-xl bg-white/15 font-black text-xs" id="pty-open-nid"><i class="fas fa-magnifying-glass ml-1"></i>باز کردنِ پرونده با کد/شناسه‌ی ملی</button>
                <button class="px-3 py-2 rounded-xl bg-white/15 font-black text-xs" onclick="Parties.pcImport()" title="مشتریانِ شخصی (اشخاص و شرکت‌های بی‌قرارداد) که بعداً در «ثبت درخواست» انتخاب می‌شوند"><i class="fas fa-file-excel ml-1"></i>ورودِ مشتریانِ شخصی از اکسل</button>
                <a class="px-3 py-2 rounded-xl bg-white/10 font-bold text-xs" href="api/party_actions.php?action=pc_sample"><i class="fas fa-download ml-1"></i>نمونه‌ی اکسل</a></div></div></div>
                <div class="card p-3 mb-3 flex flex-wrap items-center gap-2">
                    <div class="pty-tabs" id="pty-tabs"></div>
                    <input type="search" id="pty-q" placeholder="جستجو: نام، کد/شناسه‌ی ملی، تلفن، کد اقتصادی…" class="border rounded-xl px-3 py-2 text-xs flex-1 min-w-[220px]">
                </div>
                <div class="card p-0 overflow-x-auto"><table class="w-full text-xs pty-tb"><thead><tr><th>نام</th><th>کد / شناسه‌ی ملی</th><th>نوع</th><th>تلفن</th><th>نشانی</th><th>آخرین منبع</th><th>آخرین تغییر</th></tr></thead>
                <tbody id="pty-body"><tr><td colspan="7" class="p-6 text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i></td></tr></tbody></table></div>`;
            let t = 0;
            root.querySelector('#pty-q').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); load(); }, 300); });
            root.querySelector('#pty-open-nid').onclick = async () => {
                const v = window.uiPrompt ? await uiPrompt('پرونده‌ی شخص / شرکت', 'کد ملیِ ۱۰ رقمی یا شناسه‌ی ملیِ ۱۱ رقمی:', '') : prompt('کد/شناسه‌ی ملی:');
                if (v) open(v);
            };
        }
        load();
    }
    function tabs() {
        const c = S.counts || {};
        const t = [['', 'همه', (c.PERSON || 0) + (c.COMPANY || 0)], ['PERSON', 'اشخاصِ حقیقی', c.PERSON || 0], ['COMPANY', 'شرکت‌ها (حقوقی)', c.COMPANY || 0]];
        const h = S.root.querySelector('#pty-tabs');
        h.innerHTML = t.map(([k, l, n]) => `<button class="pty-tab ${S.kind === k ? 'on' : ''}" data-k="${k}"><i class="fas ${k === 'COMPANY' ? 'fa-building' : k === 'PERSON' ? 'fa-user' : 'fa-users'} ml-1"></i>${l}<b>${fa(n)}</b></button>`).join('');
        h.querySelectorAll('[data-k]').forEach(b => b.onclick = () => { S.kind = b.dataset.k; load(); });
    }
    async function load() {
        const body = S.root.querySelector('#pty-body');
        const r = await post({action: 'list', kind: S.kind, q: S.q});
        if (!r.ok) { body.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-rose-500">${esc(r.error || 'خطا')}</td></tr>`; return; }
        S.rows = r.rows || []; S.counts = r.counts || {};
        tabs();
        body.innerHTML = S.rows.length ? S.rows.map(x => `<tr class="pty-row" data-nid="${esc(x.nid)}">
            <td><b>${esc(x.name || '—')}</b></td><td dir="ltr" class="text-right">${fa(x.nid)}</td>
            <td><span class="pty-chip" style="${x.kind === 'COMPANY' ? 'background:#eef2ff;color:#4338ca' : ''}">${x.kind === 'COMPANY' ? 'حقوقی' : 'حقیقی'}</span></td>
            <td dir="ltr" class="text-right">${fa(x.phone || '')}</td><td class="max-w-[280px] truncate" title="${esc(x.address || '')}">${esc(fa(x.address || ''))}</td>
            <td><span class="pty-chip">${esc(SRC[x.source] || x.source || '')}</span></td><td>${fa(x.updated_at || '')}</td></tr>`).join('')
            : '<tr><td colspan="7" class="p-8 text-center text-slate-400">موردی پیدا نشد.</td></tr>';
        body.querySelectorAll('[data-nid]').forEach(tr => tr.onclick = () => open(tr.dataset.nid));
    }
    // پرونده‌ی یک شخص/شرکت
    async function open(nid) {
        css();
        const r = await post({action: 'detail', nid});
        if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
        const p = r.party || {}, isCo = p.kind === 'COMPANY';
        const ov = document.createElement('div'); ov.className = 'pty-ov';
        const canEdit = typeof permCan !== 'function' || permCan('parties', 'edit');
        const f = (k, l, ltr, wide) => `<label style="${wide ? 'grid-column:1/-1' : ''}">${l}${wide ? `<textarea data-k="${k}" rows="2" ${canEdit ? '' : 'readonly'}>${esc(p[k] || '')}</textarea>` : `<input data-k="${k}" value="${esc(p[k] || '')}" ${ltr ? 'dir="ltr"' : ''} ${canEdit ? '' : 'readonly'}>`}</label>`;
        const pol = r.policies || [];
        ov.innerHTML = `<div class="pty-box" dir="rtl"><div class="pty-bh"><div><b class="text-[15px]"><i class="fas ${isCo ? 'fa-building' : 'fa-user'} ml-1"></i>${esc(p.name || 'بدونِ نام')}</b>
                <div class="text-[11px] opacity-85 mt-0.5">${isCo ? 'شناسه‌ی ملی' : 'کد ملی'}: <span dir="ltr">${fa(p.nid)}</span>${p.source_fa ? ' · آخرین اطلاعات از: ' + esc(p.source_fa) : ''}${p.updated_at ? ' · ' + fa(p.updated_at) : ''}</div></div>
                <div class="flex gap-2">${p.person_id && window.Personnel && Personnel.open ? '<button class="px-3 py-1.5 rounded-xl bg-white/15 text-xs font-black" data-per><i class="fas fa-id-badge ml-1"></i>پرونده‌ی پرسنل</button>' : ''}
                <button class="px-3 py-1.5 rounded-xl bg-white/15 text-xs font-black" data-x><i class="fas fa-times ml-1"></i>بستن</button></div></div>
            <div class="p-4">
                <div class="pty-sec"><h4><i class="fas fa-id-card text-teal-600 ml-1"></i>مشخصات <span class="text-[10px] font-bold text-slate-400">(آخرین اطلاعات؛ هر جای سایت که این ${isCo ? 'شرکت' : 'شخص'} انتخاب شود همین می‌نشیند)</span></h4>
                    <div class="pty-grid">${f('name', isCo ? 'نامِ شرکت' : 'نام و نام خانوادگی')}${isCo ? f('economic_code', 'کد اقتصادی', 1) + f('register_no', 'شماره ثبت', 1) : f('father', 'نام پدر') + f('birth', 'تاریخ تولد', 1)}
                        ${f('phone', 'تلفن', 1)}${f('mobile', 'موبایل', 1)}${f('postal_code', 'کد پستی', 1)}${f('address', 'نشانی', 0, 1)}${f('note', 'یادداشت', 0, 1)}</div>
                    ${canEdit ? '<div class="mt-2 text-left"><button class="px-4 py-2 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-xs font-black" data-save><i class="fas fa-save ml-1"></i>ذخیره</button></div>' : ''}</div>
                <div class="pty-sec"><h4><i class="fas fa-file-shield text-indigo-600 ml-1"></i>بیمه‌نامه‌ها و ردیف‌ها (بیمه‌گذار یا مالک) <span class="pty-chip">${fa(pol.length)}</span></h4>
                    ${pol.length ? `<div class="overflow-x-auto"><table class="pty-tb"><tr><th>نقش</th><th>نوع</th><th>پلاک</th><th>خودرو</th><th>شرکت / درخواست‌دهنده</th><th>شماره بیمه‌نامه</th><th>تاریخ صدور</th><th>وضعیت</th><th></th></tr>
                        ${pol.map(x => `<tr><td>${esc((x.roles || []).join('، '))}</td><td>${esc(x.type)}</td><td>${esc(fa(x.plate || ''))}</td><td>${esc(x.car || '')}</td><td>${esc(x.company || '')}</td>
                            <td dir="ltr" class="text-right">${esc(fa(x.policy || '—'))}</td><td>${fa(x.issue_date || '')}</td><td><span class="pty-chip" style="${x.status === 'ISSUED' ? 'background:#ecfdf5;color:#047857' : ''}">${esc(x.status_fa || x.status)}</span></td>
                            <td>${window.IssuePanel ? `<button class="text-indigo-600 font-bold" data-ip="${x.src}:${x.id}">پنل</button>` : ''}${x.src === 'C' && window.openCompanyRequestDetail ? ` <button class="text-teal-700 font-bold" data-req="${x.request_id}">درخواست</button>` : ''}</td></tr>`).join('')}</table></div>`
                        : '<p class="text-[11px] text-slate-400">هنوز بیمه‌نامه یا ردیفی با این کد/شناسه‌ی ملی ثبت نشده.</p>'}</div>
                ${(r.requests || []).length ? `<div class="pty-sec"><h4><i class="fas fa-folder-open text-amber-600 ml-1"></i>درخواست‌های این شرکت <span class="pty-chip">${fa(r.requests.length)}</span></h4><div class="overflow-x-auto"><table class="pty-tb"><tr><th>شماره</th><th>نوع</th><th>تاریخ</th><th>ردیف‌ها</th><th>صادرشده</th><th></th></tr>
                    ${r.requests.map(x => `<tr><td>#${fa(x.ref || x.id)}</td><td>${esc(x.kind)}</td><td>${fa(x.date)}</td><td>${fa(x.rows)}</td><td>${fa(x.issued)}</td><td>${window.openCompanyRequestDetail ? `<button class="text-teal-700 font-bold" data-req="${x.id}">باز کردن</button>` : ''}</td></tr>`).join('')}</table></div></div>` : ''}
                ${(r.inquiries || []).length ? `<div class="pty-sec"><h4><i class="fas fa-magnifying-glass text-slate-500 ml-1"></i>استعلام‌ها</h4>${r.inquiries.map(x => `<p class="text-[11px] py-0.5"><span class="pty-chip">${fa(x.date)}</span> ${esc(x.notice || x.status)}</p>`).join('')}</div>` : ''}
            </div></div>`;
        document.body.appendChild(ov);
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = e => { if (e.key === 'Escape') close(); };
        document.addEventListener('keydown', onKey);
        ov.querySelector('[data-x]').onclick = close;
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        const pb = ov.querySelector('[data-per]'); if (pb) pb.onclick = () => { close(); Personnel.open(p.person_id); };
        ov.querySelectorAll('[data-ip]').forEach(b => b.onclick = () => { const [t, id] = b.dataset.ip.split(':'); IssuePanel.open({type: t, id: Number(id)}); });
        ov.querySelectorAll('[data-req]').forEach(b => b.onclick = () => { close(); if (window.switchTab) switchTab('companies-requests'); openCompanyRequestDetail(Number(b.dataset.req)); });
        const sb = ov.querySelector('[data-save]');
        if (sb) sb.onclick = async () => {
            const info = {}; ov.querySelectorAll('[data-k]').forEach(i => { info[i.dataset.k] = i.value.trim(); });
            sb.disabled = true;
            const s = await post({action: 'save', nid: p.nid, info});
            sb.disabled = false;
            toast(s.ok ? 'اطلاعات ذخیره شد؛ از این به بعد همه‌جا همین می‌نشیند.' : (s.error || 'خطا'), s.ok ? 'success' : 'error');
            if (s.ok && S.root && document.body.contains(S.root)) load();
        };
    }
    // ورودِ مشتریانِ شخصی از اکسل (ستون‌ها: نوع، نام، کد/شناسه‌ی ملی، موبایل، تلفن، نشانی، کد پستی، توضیح — مثلِ فایلِ نمونه)
    function pcImport(done) {
        const inp = document.createElement('input'); inp.type = 'file'; inp.accept = '.xlsx,.xls,.csv';
        inp.onchange = async () => {
            if (!inp.files[0]) return;
            const fd = new FormData(); fd.append('action', 'pc_import'); fd.append('file', inp.files[0]);
            if (window.showToast) showToast('در حالِ خواندنِ اکسل…', 'info');
            let r; try { r = await (await fetch('api/party_actions.php', {method: 'POST', body: fd, credentials: 'same-origin'})).json(); } catch (e) { r = {ok: false, error: 'خطا در ارتباط.'}; }
            if (!r.ok) return window.showToast ? showToast(r.error || 'خطا', 'error') : alert(r.error);
            const msg = `${r.made} مشتریِ تازه و ${r.updated} به‌روزرسانی` + ((r.errors || []).length ? '\n' + r.errors.join('\n') : '');
            if (window.showToast) showToast(msg.replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]), (r.errors || []).length ? 'warning' : 'success');
            if (typeof done === 'function') done(r);
            if (S.root && document.body.contains(S.root)) try { load(); } catch (e) {}
        };
        inp.click();
    }
    window.Parties = {init, open, pcImport};
})();
