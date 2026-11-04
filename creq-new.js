// فایل: creq-new.js
// «ثبت درخواست» — یک دکمه به‌جای «گروهی / تکی / خالی». همه‌ی اطلاعاتِ کلیِ درخواست در یک فرمِ ساده:
//   مشتری: شرکتِ طرفِ قرارداد یا مشتریِ شخصی (شخصِ حقیقی / شرکتِ بی‌قرارداد؛ با کد/شناسه‌ی ملی از «اشخاص و شرکت‌ها» پر می‌شود)
//   تکی (یک خودرو) یا گروهی (نامه و چند خودرو) · نوع: جدید، تمدیدی، الحاقیه، فسخ · بیمه‌گر · توضیح · فایلِ نامه
//   تعداد لازم نیست (از ردیف‌ها). الحاقیه/فسخِ تکی: شماره‌ی بیمه‌نامه‌ی قبل — اگر در سایت صادر شده به همان وصل می‌شود؛
//   وگرنه فایلِ بیمه‌نامه‌ی فعلی گرفته و روی ردیف (با خواندنِ اطلاعاتش) گذاشته می‌شود.
// بعد از ثبت: جزئیاتِ درخواست باز می‌شود (تکیِ جدید: پنجره‌ی افزودنِ خودرو هم).  API: api/company_actions.php (creq_create)
(function () {
    'use strict';
    if (window.CreqNew) return;
    const API = 'api/company_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const norm = s => en(String(s || '')).replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\s]+/g, ' ').trim().toLowerCase();
    // فهرستِ قابلِ جستجو (نام یا کد/شناسه‌ی ملی) به‌جای کشوی بلند
    function pickHtml(key, label, placeholder) {
        return `<div class="crn-pick" data-pick="${key}"><input class="crn-in" data-pickq autocomplete="off" placeholder="${esc(placeholder)}" value="${esc(label)}"><div class="crn-pl" hidden></div></div>`;
    }
    function bindPick(root, key, items, onPick, extra) {
        const box = root.querySelector(`[data-pick="${key}"]`); if (!box) return;
        const q = box.querySelector('[data-pickq]'), pl = box.querySelector('.crn-pl');
        const draw = () => {
            const t = norm(q.value), words = t.split(' ').filter(Boolean);
            const list = items.filter(it => { const h = norm(it.name + ' ' + (it.national_id || '') + ' ' + (it.mobile || '')); return words.every(w => h.includes(w)); }).slice(0, 60);
            pl.innerHTML = (extra ? `<button type="button" data-v=""><span><i class="fas fa-plus ml-1 text-blue-600"></i>${esc(extra)}</span></button>` : '')
                + (list.map(it => `<button type="button" data-v="${it.id}"><span>${esc(it.name)}</span><small>${esc(fa(it.national_id || it.mobile || ''))}</small></button>`).join('') || '<p class="p-3 text-[11px] text-slate-400">چیزی پیدا نشد.</p>');
            pl.hidden = false;
            pl.querySelectorAll('[data-v]').forEach(b => b.onmousedown = e => { e.preventDefault(); pl.hidden = true; onPick(b.dataset.v); });
        };
        q.addEventListener('focus', () => { q.select(); draw(); });
        q.addEventListener('input', draw);
        q.addEventListener('blur', () => setTimeout(() => { pl.hidden = true; }, 150));
    }
    async function post(body) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(body)})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }
    function css() {
        if (document.getElementById('crn-css')) return;
        const s = document.createElement('style'); s.id = 'crn-css';
        s.textContent = `
        .crn-ov{position:fixed;inset:0;z-index:1000004;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:14px}
        .crn-box{width:min(760px,100%);max-height:94vh;overflow:auto;background:#f8fafc;border-radius:24px;box-shadow:0 40px 80px -30px rgba(0,0,0,.5)}
        .crn-h{position:sticky;top:0;z-index:2;padding:16px 20px;color:#fff;background:linear-gradient(120deg,#1e3a8a,#2563eb 55%,#0891b2);border-radius:24px 24px 0 0;display:flex;justify-content:space-between;gap:8px;align-items:center}
        .crn-sec{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:14px;margin-bottom:10px}
        .crn-sec h4{font-size:12.5px;font-weight:900;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;gap:6px}
        .crn-sec h4 .n{width:22px;height:22px;border-radius:8px;background:#eff6ff;color:#1d4ed8;display:inline-flex;align-items:center;justify-content:center;font-size:11px}
        .crn-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
        .crn-card{border:2px solid #e2e8f0;border-radius:16px;padding:10px 12px;text-align:right;background:#fff;transition:.15s;cursor:pointer}
        .crn-card b{display:block;font-size:12.5px;font-weight:900;color:#1e293b}.crn-card small{display:block;font-size:10.5px;color:#64748b;margin-top:2px}
        .crn-card i{font-size:16px;color:#2563eb;margin-bottom:4px}
        .crn-card.on{border-color:#2563eb;background:linear-gradient(180deg,#eff6ff,#fff);box-shadow:0 10px 24px -18px #1d4ed8}
        .crn-f{display:block;font-size:11px;font-weight:800;color:#475569}
        .crn-in{width:100%;margin-top:4px;border:1.5px solid #e2e8f0;border-radius:12px;padding:8px 10px;font-size:13px;background:#fff}
        .crn-in:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px #bfdbfe66}
        .crn-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
        .crn-note{font-size:11px;border-radius:12px;padding:8px 10px;margin-top:8px}
        .crn-go{background:linear-gradient(120deg,#1d4ed8,#0891b2);color:#fff;font-weight:900;border-radius:14px;padding:11px 20px;font-size:13px}
        .crn-go:disabled{opacity:.6}
        .crn-pick{position:relative}.crn-pl{position:absolute;inset-inline:0;top:100%;margin-top:4px;z-index:5;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 20px 40px -20px rgba(15,23,42,.35);max-height:260px;overflow:auto}
        .crn-pl button{display:flex;justify-content:space-between;gap:8px;width:100%;text-align:right;padding:8px 12px;font-size:12px;font-weight:700;color:#1e293b;border-bottom:1px solid #f1f5f9}
        .crn-pl button:hover,.crn-pl button.on{background:#eff6ff}.crn-pl small{color:#94a3b8;font-weight:600;direction:ltr}
        `;
        document.head.appendChild(s);
    }
    const KINDS = [['NEW_POLICY', 'fa-file-circle-plus', 'بیمه‌نامه‌ی جدید', 'صدورِ بیمه‌نامه'], ['RENEWAL', 'fa-rotate', 'تمدیدی', 'تمدیدِ بیمه‌نامه‌ی قبلی'],
                   ['ENDORSEMENT', 'fa-file-pen', 'الحاقیه', 'تغییرِ اطلاعاتِ بیمه‌نامه'], ['CANCELLATION', 'fa-file-circle-xmark', 'فسخ', 'فسخِ بیمه‌نامه']];
    let F = null;   // داده‌ی فرم (شرکت‌ها، مشتریانِ شخصی)
    async function open(pre) {
        css();
        pre = pre || {};
        if (!F) {
            const r = await post({action: 'admin_manual_form'});
            if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
            F = r;
        }
        const S = {cust: pre.personal ? 'PERSONAL' : 'CONTRACT', companyId: pre.company_id || '', mode: pre.single ? 'SINGLE' : 'GROUP', kind: 'NEW_POLICY', insurer: 'PASARGAD',
                   pc: {id: '', kind: 'PERSON', name: '', national_id: '', mobile: '', address: ''}, policy: null};
        const ov = document.createElement('div'); ov.className = 'crn-ov';
        ov.innerHTML = `<div class="crn-box" dir="rtl"><div class="crn-h"><div><b class="text-[16px]"><i class="fas fa-file-circle-plus ml-1"></i>ثبت درخواست</b>
            <p class="text-[11px] opacity-85 mt-0.5">اطلاعاتِ کلیِ درخواست؛ خودروها و مدارک را بعد از ثبت اضافه می‌کنید (تعداد خودکار از ردیف‌ها).</p></div>
            <button class="px-3 py-1.5 rounded-xl bg-white/15 text-xs font-black" data-x><i class="fas fa-times ml-1"></i>بستن</button></div><div class="p-4" data-b></div></div>`;
        document.body.appendChild(ov);
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = e => { if (e.key === 'Escape') close(); };
        document.addEventListener('keydown', onKey);
        ov.querySelector('[data-x]').onclick = close;
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        const B = ov.querySelector('[data-b]');
        const card = (grp, v, ic, t, sub, on) => `<button type="button" class="crn-card ${on ? 'on' : ''}" data-${grp}="${v}"><i class="fas ${ic}"></i><b>${t}</b><small>${sub}</small></button>`;
        const company = () => (F.companies || []).find(c => String(c.id) === String(S.companyId));
        const paint = () => {
            const co = company();
            const insOpts = S.cust === 'CONTRACT' && co && co.allowed_insurers && co.allowed_insurers !== 'BOTH' ? [co.allowed_insurers] : ['PASARGAD', 'IRAN'];
            if (!insOpts.includes(S.insurer)) S.insurer = insOpts[0];
            const isEnd = S.kind === 'ENDORSEMENT' || S.kind === 'CANCELLATION';
            B.innerHTML = `
            <div class="crn-sec"><h4><span class="n">۱</span>مشتری</h4>
                <div class="crn-cards">${card('cust', 'CONTRACT', 'fa-building', 'شرکتِ طرفِ قرارداد', 'از شرکت‌های تعریف‌شده', S.cust === 'CONTRACT')}${card('cust', 'PERSONAL', 'fa-user-plus', 'مشتریِ شخصی', 'شخصِ حقیقی یا شرکتِ بی‌قرارداد', S.cust === 'PERSONAL')}</div>
                ${S.cust === 'CONTRACT' ? `<label class="crn-f mt-3">شرکت${pickHtml('company', co ? co.name : '', 'جستجوی شرکت (نام یا شناسه‌ی ملی)…')}</label>`
                : `<div class="crn-grid mt-3">
                    <label class="crn-f">مشتریِ قبلی${pickHtml('pc', S.pc.id ? ((F.personal_customers || []).find(c => String(c.id) === String(S.pc.id)) || {}).name || '' : '', 'جستجو: نام، کد/شناسه‌ی ملی یا موبایل — خالی = مشتریِ تازه')}
                        <span class="flex gap-2 mt-1 text-[10px]"><button type="button" class="text-blue-600 font-bold" data-pcimp><i class="fas fa-file-excel ml-0.5"></i>ورود از اکسل</button><a class="text-slate-500" href="api/party_actions.php?action=pc_sample">نمونه‌ی اکسل</a></span></label>
                    ${S.pc.id ? '' : `<label class="crn-f">نوع<select class="crn-in" data-k="pc_kind"><option value="PERSON" ${S.pc.kind !== 'LEGAL' ? 'selected' : ''}>شخصِ حقیقی</option><option value="LEGAL" ${S.pc.kind === 'LEGAL' ? 'selected' : ''}>شرکت (حقوقی)</option></select></label>
                    <label class="crn-f">${S.pc.kind === 'LEGAL' ? 'شناسه‌ی ملی (۱۱ رقم)' : 'کد ملی'}<input class="crn-in" dir="ltr" data-k="pc_nid" value="${esc(S.pc.national_id)}" inputmode="numeric"></label>
                    <label class="crn-f">${S.pc.kind === 'LEGAL' ? 'نامِ شرکت' : 'نام و نام خانوادگی'}<input class="crn-in" data-k="pc_name" value="${esc(S.pc.name)}"></label>
                    <label class="crn-f">موبایل<input class="crn-in" dir="ltr" data-k="pc_mobile" value="${esc(S.pc.mobile)}" inputmode="numeric"></label>
                    <label class="crn-f" style="grid-column:1/-1">نشانی<input class="crn-in" data-k="pc_address" value="${esc(S.pc.address)}"></label>`}</div><p class="text-[10.5px] text-slate-400 mt-1" data-pcnote></p>`}
            </div>
            <div class="crn-sec"><h4><span class="n">۲</span>نوعِ درخواست</h4>
                <div class="crn-cards">${card('mode', 'SINGLE', 'fa-car', 'تکی', 'یک خودرو؛ نامه اختیاری (اسکرین‌شاتِ چت هم می‌شود)', S.mode === 'SINGLE')}${card('mode', 'GROUP', 'fa-layer-group', 'گروهی', 'نامه و چند خودرو', S.mode === 'GROUP')}</div>
                <div class="crn-cards mt-2">${KINDS.map(([k, ic, t, sub]) => card('kind', k, ic, t, sub, S.kind === k)).join('')}</div>
                ${isEnd && S.mode === 'SINGLE' ? `<div class="crn-grid mt-3">
                    <label class="crn-f">شماره‌ی بیمه‌نامه‌ی قبل <span class="text-rose-500">*</span><input class="crn-in" dir="ltr" data-k="ref" value="${esc(S.ref || '')}" placeholder="مثلاً 2/49357/5650-3/1405/1033"></label>
                    ${S.kind === 'ENDORSEMENT' ? `<label class="crn-f">خواسته‌ی الحاقیه<input class="crn-in" data-k="endo" value="${esc(S.endo || '')}" placeholder="مثلاً تغییرِ پلاک / افزایشِ تعهد"></label>`
                        : `<label class="crn-f">دلیلِ فسخ<select class="crn-in" data-k="cancel"><option value="">—</option>${(F.cancellation_reasons || []).map(c => `<option ${S.cancel === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></label>`}
                    </div><div data-polnote></div>` : ''}
                ${isEnd && S.mode === 'GROUP' ? '<p class="crn-note bg-violet-50 text-violet-800">برای هر ردیف، شماره‌ی بیمه‌نامه‌ی قبل لازم است؛ اگر در سایت صادر شده باشد ردیف به همان وصل می‌شود و پنلِ صدورش از اطلاعاتِ آن می‌آید، وگرنه فایلِ بیمه‌نامه‌ی فعلی را روی همان ردیف بارگذاری کنید.</p>' : ''}
            </div>
            <div class="crn-sec"><h4><span class="n">۳</span>بیمه‌گر، توضیح و نامه</h4>
                <div class="crn-cards">${insOpts.map(i => card('ins', i, 'fa-shield-halved', i === 'IRAN' ? 'بیمه ایران' : 'بیمه پاسارگاد', '', S.insurer === i)).join('')}</div>
                ${S.mode === 'GROUP' && (S.kind === 'NEW_POLICY' || S.kind === 'RENEWAL') ? `<p class="text-[11px] font-black text-slate-600 mt-3 mb-1">ردیف‌های این نامه چه بیمه‌ای می‌خواهند؟ <span class="font-normal text-slate-400">(پیش‌فرضِ ورودِ ردیف‌ها؛ بعداً در صفحه‌ی درخواست هم عوض می‌شود)</span></p>
                <div class="crn-cards">${[['THIRDPARTY', 'fa-car-burst', 'ثالث'], ['BODY', 'fa-car-side', 'بدنه'], ['BOTH', 'fa-clone', 'هر دو'], ['', 'fa-circle-question', 'مشخص نیست']].map(([k, ic, t]) => card('it', k || 'NONE', ic, t, k === 'BOTH' ? 'برای هر خودرو ثالث و بدنه' : '', (S.insTypes || 'NONE') === (k || 'NONE'))).join('')}</div>` : ''}
                <label class="crn-f mt-3">توضیحِ درخواست<textarea class="crn-in" rows="3" data-k="text" placeholder="مثلاً: تمدیدِ ثالثِ ۱۲ دستگاه وانت…">${esc(S.text || '')}</textarea></label>
                <label class="crn-f mt-2">${S.mode === 'GROUP' ? 'فایلِ نامه' : 'فایلِ نامه یا اسکرین‌شاتِ چت (اختیاری)'}<input type="file" class="crn-in" data-k="letter" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif"></label>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <button type="button" class="text-[11px] font-bold text-slate-500 hover:text-blue-700" data-full><i class="fas fa-table-list ml-1"></i>ثبتِ کامل با جدولِ خودروها (یک‌جا)</button>
                <button type="button" class="crn-go" data-go><i class="fas fa-check ml-1"></i>ثبت درخواست</button></div>`;
            const val = k => { const e = B.querySelector(`[data-k="${k}"]`); return e ? e.value : ''; };
            B.querySelectorAll('[data-cust]').forEach(b => b.onclick = () => { S.cust = b.dataset.cust; paint(); });
            B.querySelectorAll('[data-mode]').forEach(b => b.onclick = () => { S.mode = b.dataset.mode; paint(); });
            B.querySelectorAll('[data-kind]').forEach(b => b.onclick = () => { S.kind = b.dataset.kind; paint(); });
            B.querySelectorAll('[data-ins]').forEach(b => b.onclick = () => { S.insurer = b.dataset.ins; paint(); });
            B.querySelectorAll('[data-it]').forEach(b => b.onclick = () => { S.insTypes = b.dataset.it === 'NONE' ? '' : b.dataset.it; paint(); });
            const keep = () => { S.text = val('text'); S.ref = val('ref'); S.endo = val('endo'); S.cancel = val('cancel');
                ['name', 'mobile', 'address'].forEach(k => { const e = B.querySelector(`[data-k="pc_${k}"]`); if (e) S.pc[k] = e.value; }); const n = B.querySelector('[data-k="pc_nid"]'); if (n) S.pc.national_id = n.value; };
            B.querySelectorAll('textarea,input:not([type=file])').forEach(i => i.addEventListener('input', keep));
            bindPick(B, 'company', F.companies || [], v => { keep(); S.companyId = v; paint(); });
            bindPick(B, 'pc', F.personal_customers || [], v => { keep(); S.pc.id = v; paint(); }, 'مشتریِ تازه');
            const pim = B.querySelector('[data-pcimp]'); if (pim && window.Parties && Parties.pcImport) pim.onclick = () => Parties.pcImport(async () => { const r = await post({action: 'admin_manual_form'}); if (r.ok) { F = r; keep(); paint(); } }); else if (pim) pim.remove();
            const pk = B.querySelector('[data-k="pc_kind"]'); if (pk) pk.onchange = () => { keep(); S.pc.kind = pk.value; paint(); };
            // کد/شناسه‌ی ملیِ مشتریِ تازه: اگر قبلاً جایی ثبت شده، نام و موبایل و نشانی از «اشخاص و شرکت‌ها»
            const pn = B.querySelector('[data-k="pc_nid"]');
            if (pn) { let t = 0; pn.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => {
                const d = en(pn.value).replace(/\D/g, ''); if (d.length !== 10 && d.length !== 11) return;
                const r = await post({action: 'party_lookup', nid: d}); const p = r.ok ? (r.parties || {})[d.length === 9 ? d.padStart(10, '0') : d] : null;
                const note = B.querySelector('[data-pcnote]');
                if (!p) { if (note) note.textContent = ''; return; }
                [['pc_name', p.name], ['pc_mobile', p.mobile || p.phone], ['pc_address', p.address]].forEach(([k, v]) => { const e = B.querySelector(`[data-k="${k}"]`); if (e && v && !e.value.trim()) e.value = fa(v).replace(/[۰-۹]/g, x => k === 'pc_name' || k === 'pc_address' ? x : FA.indexOf(x)); });
                if (d.length === 11 && S.pc.kind !== 'LEGAL') { S.pc.kind = 'LEGAL'; keep(); paint(); return; }
                keep();
                if (note) note.innerHTML = `<i class="fas fa-user-check text-emerald-600 ml-1"></i>از «${esc(p.source_fa || 'آخرین اطلاعات')}» پر شد.`;
            }, 450); }); }
            // شماره‌ی بیمه‌نامه‌ی قبل: در سایت صادر شده؟
            const ri = B.querySelector('[data-k="ref"]');
            if (ri) { let t = 0; const chk = async () => {
                const box = B.querySelector('[data-polnote]'); const v = en(ri.value).trim(); if (!box) return;
                if (v.replace(/\W/g, '').length < 6) { box.innerHTML = ''; S.policy = null; return; }
                const r = await post({action: 'creq_policy_check', policy: v});
                S.policy = r.ok ? r.match : null;
                box.innerHTML = S.policy ? `<p class="crn-note bg-emerald-50 text-emerald-800"><i class="fas fa-link ml-1"></i>این بیمه‌نامه در سایت صادر شده (${esc(S.policy.type_fa || '')} · ${esc(fa(S.policy.label || ''))} · ${esc(S.policy.insured || '')})؛ ردیف به همان وصل می‌شود و پنلِ صدور از اطلاعاتِ آن پر می‌شود. موقعِ صدور فایلِ الحاقیه و بیمه‌نامه‌ی نهایی گرفته می‌شود.</p>`
                    : `<div class="crn-note bg-amber-50 text-amber-800"><i class="fas fa-triangle-exclamation ml-1"></i>این بیمه‌نامه در سایت پیدا نشد؛ فایلِ بیمه‌نامه‌ی فعلی را بدهید تا روی ردیف بنشیند و اطلاعاتش خوانده شود.
                        <div class="crn-grid mt-2"><label class="crn-f">فایلِ بیمه‌نامه‌ی فعلی <span class="text-rose-500">*</span><input type="file" class="crn-in" data-k="curpol" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
                        <label class="crn-f">نوعِ بیمه<select class="crn-in" data-k="instype"><option value="">—</option><option value="THIRDPARTY">ثالث</option><option value="BODY">بدنه</option></select></label></div></div>`;
            }; ri.addEventListener('input', () => { clearTimeout(t); t = setTimeout(chk, 500); }); if (ri.value) chk(); }
            B.querySelector('[data-full]').onclick = () => { close(); if (window.CompanyManualRequest) CompanyManualRequest.open(S.cust === 'PERSONAL' ? {personal: true} : (S.mode === 'SINGLE' ? {single: true} : {})); };
            B.querySelector('[data-go]').onclick = () => submit(B, S, val, close);
        };
        paint();
    }
    async function submit(B, S, val, close) {
        const isEnd = S.kind === 'ENDORSEMENT' || S.kind === 'CANCELLATION';
        if (S.cust === 'CONTRACT' && !S.companyId) return toast('شرکت را انتخاب کنید.', 'warning');
        if (S.cust === 'PERSONAL' && !S.pc.id && String(S.pc.name || '').trim().length < 2) return toast('نامِ مشتری را بنویسید یا مشتریِ قبلی را انتخاب کنید.', 'warning');
        const ref = en(val('ref')).trim();
        if (isEnd && S.mode === 'SINGLE' && !ref) return toast('شماره‌ی بیمه‌نامه‌ی قبل را بنویسید.', 'warning');
        const curpol = (B.querySelector('[data-k="curpol"]') || {}).files;
        if (isEnd && S.mode === 'SINGLE' && !S.policy && !(curpol && curpol[0])) return toast('این بیمه‌نامه در سایت نیست؛ فایلِ بیمه‌نامه‌ی فعلی را انتخاب کنید.', 'warning');
        const go = B.querySelector('[data-go]'); go.disabled = true; go.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ ثبت…';
        const r = await post({action: 'creq_create', customer_type: S.cust, company_id: S.companyId, insurer: S.insurer, request_kind: S.kind, req_mode: S.mode, request_text: val('text').trim(),
            customer: S.cust === 'PERSONAL' ? {id: S.pc.id, kind: S.pc.kind, name: S.pc.name, national_id: en(S.pc.national_id), mobile: en(S.pc.mobile), address: S.pc.address} : null,
            ref_policy_number: isEnd && S.mode === 'SINGLE' ? ref : '', endorsement_request: val('endo'), cancellation_reason: val('cancel'), insurance_type: val('instype'),
            ins_types: S.mode === 'GROUP' && !isEnd ? (S.insTypes || '') : ''});
        if (!r.ok) { go.disabled = false; go.innerHTML = '<i class="fas fa-check ml-1"></i>ثبت درخواست'; return toast(r.error || 'خطا', 'error'); }
        // نامه و (الحاقیه/فسخِ بیرون از سایت) فایلِ بیمه‌نامه‌ی فعلی
        const letter = (B.querySelector('[data-k="letter"]') || {}).files;
        const up = async fd => { try { return await (await fetch(API, {method: 'POST', body: fd, credentials: 'same-origin'})).json(); } catch (e) { return {ok: false}; } };
        if (letter && letter[0]) { const fd = new FormData(); fd.append('action', 'admin_upload_plate_doc'); fd.append('request_id', r.request_id); fd.append('doc_type', 'letter'); fd.append('file', letter[0]); await up(fd); }
        if (r.plate_id && curpol && curpol[0] && !r.linked) {
            const fd = new FormData(); fd.append('action', 'upload_row_doc'); fd.append('plate_id', r.plate_id); fd.append('doc_type', 'policy_doc'); fd.append('doc_file', curpol[0], curpol[0].name);
            const u = await up(fd);
            // اطلاعاتِ همان بیمه‌نامه روی ردیف (در پس‌زمینه)
            if (u.ok && u.doc_id) post({action: 'row_doc_process', doc_id: u.doc_id, method: 'smart'}).then(x => { if (x && x.ok && typeof currentRequestId !== 'undefined' && String(currentRequestId) === String(r.request_id) && window.openCompanyRequestDetail) openCompanyRequestDetail(r.request_id); });
        }
        close();
        toast(`درخواست ${r.ref_code ? '#' + fa(r.ref_code) + ' ' : ''}ثبت شد${r.linked ? ' و به بیمه‌نامه‌ی صادرشده‌ی سایت وصل شد' : ''}.`, 'success');
        try { if (S.cust === 'PERSONAL' && window.loadPersonalRequests) loadPersonalRequests(); else if (window.loadCompanyRequests) loadCompanyRequests(); } catch (e) {}
        if (window.openCompanyRequestDetail) {
            await openCompanyRequestDetail(r.request_id);
            if (S.mode === 'SINGLE' && !r.plate_id && window.openAdminAddPlateModal) setTimeout(() => openAdminAddPlateModal(r.request_id), 300);
        }
    }
    window.CreqNew = {open};
})();
