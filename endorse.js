// فایل: endorse.js
// «الحاقیه‌ها»: پنجره‌ی ثبتِ الحاقیه (فایلِ PDF ← خواندنِ خودکار ← بیمه‌نامه ← اثرِ مالی با پیش‌نمایش) و صفحه‌ی فهرستِ الحاقیه‌ها.
//   Endorse.open({requestPlateId, refPolicy, source, refId, onDone})   پنجره‌ی ثبت
//   Endorse.init(root)                                                  صفحه‌ی «الحاقیه‌ها» (مرکز صدور)
//   Endorse.badge(row)                                                  نشانِ «الحاقیه/فسخ» برای جدول‌های دیگر
// منطق و اثرِ مالی: api/_endorse.php
(function () {
    'use strict';
    const API = 'api/endorse_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v || '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => { const n = Number(v) || 0; return fa(Math.abs(n).toLocaleString('en-US')); };
    const smoney = v => { const n = Number(v) || 0; return n === 0 ? '۰' : (n > 0 ? '+' : '−') + money(n); };
    const digits = v => en(v).replace(/\D/g, '');
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const fpath = p => '/' + String(p || '').split('/').map(encodeURIComponent).join('/');
    const plate = p => p && /ایران/.test(p) && typeof formatPlateHtml === 'function' ? `<span class="plate-display">${formatPlateHtml(p)}</span>` : esc(fa(p || ''));
    const post = async (body, isForm) => {
        try {
            const r = await fetch(API, isForm ? {method: 'POST', body} : {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
            return await r.json();
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    };
    const KIND_FA = {ADDITIONAL: 'اضافی', REFUND: 'برگشتی', CANCELLATION: 'فسخ', CORRECTIVE: 'اصلاحی'};
    const KIND_CLS = {ADDITIONAL: 'bg-emerald-50 text-emerald-700 border-emerald-200', REFUND: 'bg-amber-50 text-amber-700 border-amber-200',
                      CANCELLATION: 'bg-rose-50 text-rose-700 border-rose-200', CORRECTIVE: 'bg-slate-50 text-slate-600 border-slate-200'};
    const MODES = {
        ADDITIONAL: [['SPREAD', 'سرشکن روی اقساطِ باقی‌مانده', 'مبلغ به‌طور مساوی روی اقساطِ بازِ بعد از تاریخِ اثر اضافه می‌شود'],
                     ['SEPARATE', 'اقساطِ جدا', 'چند قسطِ تازه با سررسیدِ دلخواه (یا طبقِ اعلامیه‌ی بیمه‌گر)'],
                     ['CASH', 'نقد (یک‌جا)', 'یک قسطِ تازه به مبلغِ کلِ الحاقیه'], ['NONE', 'بدونِ اثرِ مالی', 'فقط حق بیمه‌ی نهایی عوض می‌شود']],
        REFUND: [['LAST', 'کسر از آخرین اقساط', 'از آخرین قسطِ باز به عقب کم می‌شود (قسطِ صفرشده حذف می‌شود)'],
                 ['EVEN', 'کسرِ یکسان از اقساطِ باز', 'به نسبتِ مانده از همه‌ی اقساطِ باز کم می‌شود'],
                 ['REFUND_CASH', 'استردادِ نقدی (بستانکار)', 'اقساط دست نمی‌خورد؛ مبلغ به‌عنوانِ بستانکار ثبت می‌شود'], ['NONE', 'بدونِ اثرِ مالی', 'فقط حق بیمه‌ی نهایی عوض می‌شود']],
    };
    const modesOf = k => k === 'ADDITIONAL' ? MODES.ADDITIONAL : (k === 'REFUND' || k === 'CANCELLATION') ? MODES.REFUND : [];
    const LS = 'endo:mode:';
    const lsGet = k => { try { return localStorage.getItem(LS + k); } catch (e) { return null; } };
    const lsSet = (k, v) => { try { localStorage.setItem(LS + k, v); } catch (e) {} };

    const CSS = `
    .en-ov{position:fixed;inset:0;z-index:1000002;background:rgba(15,23,42,.6);display:flex;align-items:flex-start;justify-content:center;padding:18px 10px;overflow-y:auto}
    .en-box{background:#fff;border-radius:24px;width:100%;max-width:980px;box-shadow:0 30px 60px -20px rgba(0,0,0,.4);overflow:hidden}
    .en-hd{padding:16px 20px;background:linear-gradient(120deg,#0f766e,#4338ca);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:10px}
    .en-hd b{font-size:15px;font-weight:900}.en-hd small{display:block;font-size:11px;opacity:.85;margin-top:2px}
    .en-x{background:rgba(255,255,255,.18);border:0;color:#fff;width:34px;height:34px;border-radius:12px;cursor:pointer;font-size:16px}
    .en-bd{padding:16px 20px;display:flex;flex-direction:column;gap:14px}
    .en-sec{border:1px solid #e2e8f0;border-radius:18px;padding:12px 14px}.en-sec h4{font-size:12px;font-weight:900;color:#334155;margin-bottom:8px}
    .en-drop{border:2px dashed #94a3b8;border-radius:18px;padding:18px;text-align:center;cursor:pointer;color:#475569;font-size:12px;background:#f8fafc}
    .en-drop:hover,.en-drop.on{border-color:#0f766e;background:#f0fdfa}
    .en-g{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.en-g label{font-size:10.5px;color:#64748b;font-weight:700;display:flex;flex-direction:column;gap:3px}
    .en-g input,.en-g select,.en-g textarea,.en-in{border:1px solid #cbd5e1;border-radius:11px;padding:7px 9px;font-size:12px;font-family:inherit;width:100%;background:#fff}
    .en-g .w2{grid-column:span 2}.en-g .w4{grid-column:span 4}
    @media(max-width:720px){.en-g{grid-template-columns:repeat(2,minmax(0,1fr))}.en-g .w4,.en-g .w2{grid-column:span 2}}
    .en-pol{display:flex;flex-wrap:wrap;gap:6px}.en-pol button{border:1px solid #cbd5e1;border-radius:12px;padding:6px 10px;font-size:11px;background:#fff;cursor:pointer;text-align:right;font-family:inherit}
    .en-pol button.on{border-color:#0f766e;background:#f0fdfa;box-shadow:0 0 0 2px #99f6e4}
    .en-kpi{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px}@media(max-width:720px){.en-kpi{grid-template-columns:repeat(2,minmax(0,1fr))}}
    .en-kpi div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:7px;text-align:center}.en-kpi small{display:block;font-size:10px;color:#64748b}.en-kpi b{font-size:12.5px;color:#0f172a}
    .en-modes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}@media(max-width:720px){.en-modes{grid-template-columns:1fr}}
    .en-modes label{border:1px solid #e2e8f0;border-radius:14px;padding:8px 10px;cursor:pointer;display:flex;gap:8px;align-items:flex-start;font-size:11.5px}
    .en-modes label.on{border-color:#4338ca;background:#eef2ff}.en-modes small{display:block;color:#64748b;font-size:10.5px;margin-top:2px;line-height:1.7}
    .en-tb{width:100%;font-size:11px;border-collapse:collapse}.en-tb th{background:#f1f5f9;color:#475569;font-weight:800;padding:5px;text-align:right}.en-tb td{border-top:1px solid #f1f5f9;padding:5px}
    .en-ft{padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;background:#f8fafc}
    .en-btn{border:0;border-radius:14px;padding:9px 16px;font-weight:800;font-size:12.5px;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px}
    .en-btn.p{background:linear-gradient(120deg,#0f766e,#4338ca);color:#fff}.en-btn.s{background:#e2e8f0;color:#334155}.en-btn:disabled{opacity:.5;cursor:not-allowed}
    .en-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:8px 10px;font-size:11.5px}
    .en-badge{display:inline-flex;align-items:center;gap:3px;border:1px solid;border-radius:999px;padding:1px 7px;font-size:10px;font-weight:800;white-space:nowrap}
    .en-hero{border-radius:26px;padding:20px 22px;color:#fff;background:linear-gradient(120deg,#0f766e,#4338ca 60%,#7c3aed);box-shadow:0 20px 40px -26px #4338ca}
    .en-hero h2{font-size:19px;font-weight:900}.en-hero p{font-size:11.5px;opacity:.88;margin-top:4px;line-height:1.9;max-width:820px}
    .en-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}@media(max-width:720px){.en-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
    .en-cards div{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:10px;text-align:center}.en-cards small{font-size:10.5px;color:#64748b;display:block}.en-cards b{font-size:15px}`;
    function css() { if (!document.getElementById('en-css')) { const s = document.createElement('style'); s.id = 'en-css'; s.textContent = CSS; document.head.appendChild(s); } }

    // ------------------------------------------------------------------
    //  نشان برای جدول‌های دیگر (صادره‌ها و ...)
    // ------------------------------------------------------------------
    function badge(r) {
        if (!r) return '';
        let h = '';
        if (Number(r.is_cancelled)) h += `<span class="en-badge ${KIND_CLS.CANCELLATION}" title="فسخ از ${esc(fa(r.cancelled_on || ''))}"><i class="fas fa-ban"></i>فسخ‌شده</span> `;
        if (Number(r.endo_count)) h += `<span class="en-badge bg-indigo-50 text-indigo-700 border-indigo-200" title="حق بیمه‌ی نهایی با الحاقیه‌ها"><i class="fas fa-file-pen"></i>${fa(r.endo_count)} الحاقیه</span>`;
        return h;
    }

    // ------------------------------------------------------------------
    //  پنجره‌ی ثبتِ الحاقیه
    // ------------------------------------------------------------------
    const E = {};
    function open(opts) {
        css();
        Object.keys(E).forEach(k => delete E[k]);
        Object.assign(E, {opts: opts || {}, token: null, data: {}, matches: [], policy: null, statement: [], warnings: [], sel: null, previewSeq: 0});
        const ov = document.createElement('div');
        ov.className = 'en-ov'; ov.id = 'en-ov';
        ov.innerHTML = `<div class="en-box">
            <div class="en-hd"><div><b><i class="fas fa-file-pen ml-1"></i>${E.opts.requestPlateId ? 'صدورِ الحاقیه / فسخِ درخواست' : 'ثبتِ الحاقیه'}</b>
                <small>فایلِ PDFِ الحاقیه را بگذارید؛ شماره‌ی بیمه‌نامه، نوع، مبلغ و تاریخ‌ها خودکار خوانده می‌شود. اثرش روی حق بیمه و اقساط را خودتان انتخاب می‌کنید.</small></div>
                <button class="en-x" data-x>✕</button></div>
            <div class="en-bd" id="en-bd"></div>
            <div class="en-ft"><button class="en-btn s" data-x>انصراف</button><button class="en-btn p" id="en-save" disabled><i class="fas fa-check"></i>ثبتِ الحاقیه</button></div></div>`;
        document.body.appendChild(ov);
        ov.querySelectorAll('[data-x]').forEach(b => b.onclick = close);
        ov.querySelector('#en-save').onclick = save;
        renderBody(true);
        // اگر بیمه‌نامه از قبل معلوم است (دکمه‌ی روی خودِ بیمه‌نامه)، اطلاعاتش همین حالا
        if (E.opts.source && E.opts.refId) loadPolicy(E.opts.source, E.opts.refId);
        else if (E.opts.refPolicy) searchPolicy(E.opts.refPolicy);
    }
    function close() { const o = document.getElementById('en-ov'); if (o) o.remove(); }

    function renderBody(first) {
        const bd = document.getElementById('en-bd');
        if (!bd) return;
        const d = E.data || {};
        const kind = d.kind || '';
        bd.innerHTML = `
            <div class="en-sec"><h4>۱) فایلِ الحاقیه</h4>
                <label class="en-drop" id="en-drop"><input type="file" accept="application/pdf,.pdf" class="hidden" id="en-file">
                    ${E.token ? `<i class="fas fa-circle-check text-emerald-600 ml-1"></i>فایل دریافت شد${E.readErr ? ` — <span class="text-rose-600">${esc(E.readErr)}</span>` : ' و خوانده شد'} · برای عوض کردن کلیک کنید`
                              : '<i class="fas fa-cloud-arrow-up ml-1"></i>فایلِ PDFِ الحاقیه را بکشید یا کلیک کنید (اختیاری؛ بدونِ فایل هم می‌شود دستی وارد کرد)'}</label>
                ${(E.warnings || []).map(w => `<div class="en-warn mt-2"><i class="fas fa-triangle-exclamation ml-1"></i>${esc(w)}</div>`).join('')}
            </div>
            <div class="en-sec"><h4>۲) بیمه‌نامه</h4>
                <div class="flex gap-2 mb-2"><input class="en-in" id="en-q" placeholder="شماره‌ی بیمه‌نامه (مثلاً ۲/۴۹۳۵۷/۵۸۵۱-۱/۱۴۰۵/۶۲۸)" value="${esc(fa(d.policy_num || E.opts.refPolicy || ''))}" dir="ltr">
                    <button class="en-btn s" id="en-qb"><i class="fas fa-magnifying-glass"></i>جستجو</button></div>
                <div class="en-pol" id="en-pols">${polButtons()}</div>
                <div id="en-polinfo" class="mt-2">${polInfo()}</div>
            </div>
            <div class="en-sec"><h4>۳) مشخصاتِ الحاقیه</h4>
                <div class="en-g">
                    <label>نوعِ الحاقیه<select id="en-kind"><option value="">— انتخاب —</option>${Object.entries(KIND_FA).map(([k, l]) => `<option value="${k}" ${kind === k ? 'selected' : ''}>${l}${k === 'CANCELLATION' ? ' (برگشتی)' : ''}</option>`).join('')}</select></label>
                    <label>شماره‌ی الحاقیه<input id="en-no" value="${esc(fa(d.endorse_no || ''))}"></label>
                    <label>تاریخِ صدور<input id="en-issue" placeholder="۱۴۰۵/۰۶/۲۷" value="${esc(fa(d.issue_date || ''))}"></label>
                    <label>تاریخِ اثر<input id="en-eff" placeholder="۱۴۰۵/۰۶/۲۷" value="${esc(fa(d.effect_date || ''))}"></label>
                    <label>مبلغِ کل (با مالیات، ریال)<input id="en-gross" class="money-input" value="${d.gross ? money(d.gross) : ''}"></label>
                    <label>حق بیمه‌ی خالص<input id="en-net" class="money-input" value="${d.net ? money(d.net) : ''}"></label>
                    <label>مالیات<input id="en-tax" class="money-input" value="${d.tax ? money(d.tax) : ''}"></label>
                    <label>تاریخِ پایانِ جدید<input id="en-end" placeholder="در صورتِ تغییرِ مدت" value="${esc(fa(d.new_end_date || ''))}"></label>
                    <label class="w4">شرح<textarea id="en-desc" rows="2">${esc(d.description || '')}${(d.changes || []).length ? '' : ''}</textarea></label>
                </div>
            </div>
            <div class="en-sec" id="en-fin"></div>`;
        const drop = bd.querySelector('#en-drop'), fi = bd.querySelector('#en-file');
        fi.onchange = () => fi.files[0] && readFile(fi.files[0]);
        drop.ondragover = e => { e.preventDefault(); drop.classList.add('on'); };
        drop.ondragleave = () => drop.classList.remove('on');
        drop.ondrop = e => { e.preventDefault(); drop.classList.remove('on'); const f = e.dataTransfer.files[0]; if (f) readFile(f); };
        bd.querySelector('#en-qb').onclick = () => searchPolicy(bd.querySelector('#en-q').value);
        bd.querySelector('#en-q').onkeydown = e => { if (e.key === 'Enter') searchPolicy(e.target.value); };
        bd.querySelectorAll('#en-pols button').forEach(b => b.onclick = () => pickPolicy(b.dataset.k));
        bd.querySelector('#en-kind').onchange = () => { renderFin(); };
        ['en-gross', 'en-eff', 'en-issue'].forEach(id => bd.querySelector('#' + id).addEventListener('input', schedulePreview));
        renderFin();
    }

    function polButtons() {
        const ms = E.matches || [];
        let h = ms.map(m => `<button data-k="${m.source}:${m.ref_id}" class="${E.sel && E.sel === m.source + ':' + m.ref_id ? 'on' : ''}">
            <b>${esc(m.source_fa)}</b> · ${plate(m.label)} · <span dir="ltr">${esc(fa(m.policy_number))}</span><br><small class="text-slate-500">${esc(m.insured || '')} · ${esc(m.type_fa || '')} · حق بیمه ${money(m.premium)}${m.endo_count ? ' · ' + fa(m.endo_count) + ' الحاقیه' : ''}</small></button>`).join('');
        h += `<button data-k="X:0" class="${E.sel === 'X:0' ? 'on' : ''}"><b>این بیمه‌نامه در سایت نیست</b><br><small class="text-slate-500">فقط سابقه‌ی الحاقیه ثبت می‌شود (بعداً با ورودِ بیمه‌نامه وصل می‌شود)</small></button>`;
        return h;
    }
    function polInfo() {
        const p = E.policy;
        if (!p) return E.sel === 'X:0' ? '<p class="text-[11px] text-amber-700">بدونِ بیمه‌نامه در سایت: اثرِ مالی ثبت نمی‌شود.</p>' : '<p class="text-[11px] text-slate-400">بیمه‌نامه‌ای انتخاب نشده.</p>';
        const insts = p.installments || [];
        return `<div class="en-kpi">
                <div><small>حق بیمه‌ی اصلی</small><b>${money(p.premium)}</b></div><div><small>حق بیمه‌ی فعلی (با الحاقیه‌ها)</small><b>${money(p.final_premium)}</b></div>
                <div><small>الحاقیه‌های قبلی</small><b>${fa(p.endo_count || 0)}</b></div><div><small>اقساطِ باز</small><b>${fa(p.open_count)} · ${money(p.open_remaining)}</b></div>
                <div><small>پرداخت‌شده</small><b>${money(p.paid_total)}</b></div></div>
            ${p.no_installments ? '<p class="text-[11px] text-amber-700 mt-1">این بیمه‌نامه «بدونِ قسط» ثبت شده؛ فقط حق بیمه‌ی نهایی عوض می‌شود.</p>' : ''}
            ${Number(p.is_cancelled) ? '<p class="text-[11px] text-rose-700 mt-1">این بیمه‌نامه قبلاً فسخ شده.</p>' : ''}
            ${insts.length ? `<details class="mt-2"><summary class="text-[11px] font-bold text-slate-600 cursor-pointer">اقساطِ فعلی (${fa(insts.length)})</summary>
                <table class="en-tb mt-1"><tr><th>قسط</th><th>سررسید</th><th>مبلغ</th><th>پرداخت‌شده</th><th>وضعیت</th></tr>
                ${insts.map(i => `<tr><td>${fa(i.n)}${i.endorsement_id ? ' <small class="text-indigo-600">(الحاقیه)</small>' : ''}</td><td>${fa(i.due)}</td><td>${money(i.amount)}</td><td>${money(i.paid)}</td><td>${i.open ? 'باز' : '<span class="text-emerald-600">بسته</span>'}</td></tr>`).join('')}</table></details>` : ''}
            ${(p.endorsements || []).length ? `<p class="text-[11px] text-slate-500 mt-1">الحاقیه‌های قبلی: ${p.endorsements.map(x => `${KIND_FA[x.kind]} ${fa(x.endorse_no || '')} (${smoney(x.signed)})`).join('، ')}</p>` : ''}`;
    }

    function renderFin() {
        const box = document.getElementById('en-fin');
        if (!box) return;
        const kind = (document.getElementById('en-kind') || {}).value || '';
        const modes = modesOf(kind);
        const noFin = !E.policy || E.sel === 'X:0' || Number((E.policy || {}).no_installments);
        if (!kind || kind === 'CORRECTIVE' || !modes.length) {
            box.innerHTML = '<h4>۴) اثرِ مالی</h4><p class="text-[11px] text-slate-500">' + (kind === 'CORRECTIVE' ? 'الحاقیه‌ی اصلاحی مبلغ ندارد و اثرِ مالی ندارد.' : 'اول نوعِ الحاقیه را انتخاب کنید.') + '</p>';
            E.mode = 'NONE'; updateSave(); return;
        }
        const grp = kind === 'ADDITIONAL' ? 'A' : 'R';
        let mode = E.modeFor === grp && E.mode ? E.mode : (lsGet(grp) || modes[0][0]);
        if (noFin) mode = 'NONE';
        E.mode = mode; E.modeFor = grp;
        const st = (E.statement || []).filter(x => x.amount && x.due);
        box.innerHTML = `<h4>۴) اثرِ مالی — ${kind === 'ADDITIONAL' ? 'حق بیمه‌ی اضافه از کجا گرفته شود؟' : 'مبلغِ برگشتی چطور کم شود؟'}</h4>
            <div class="en-modes">${modes.map(([k, l, h]) => `<label class="${mode === k ? 'on' : ''}" ${noFin && k !== 'NONE' ? 'style="opacity:.45;pointer-events:none"' : ''}>
                <input type="radio" name="en-mode" value="${k}" ${mode === k ? 'checked' : ''}><span><b>${l}</b><small>${h}</small></span></label>`).join('')}</div>
            <div id="en-sep" class="${mode === 'SEPARATE' ? '' : 'hidden'} en-g mt-2">
                <label>تعدادِ اقساط<input id="en-cnt" type="number" min="1" max="36" value="${E.cnt || (st.length || 1)}"></label>
                <label>سررسیدِ قسطِ اول<input id="en-first" placeholder="۱۴۰۵/۰۷/۱۰" value="${esc(fa(E.first || ''))}"></label>
                ${st.length ? `<label class="w2" style="flex-direction:row;align-items:center;gap:6px;margin-top:16px"><input type="checkbox" id="en-usest" ${E.useSt !== false ? 'checked' : ''} style="width:auto">طبقِ اعلامیه‌ی بیمه‌گر (${fa(st.length)} قسط)</label>` : ''}
            </div>
            <div id="en-prev" class="mt-2"></div>`;
        box.querySelectorAll('input[name=en-mode]').forEach(r => r.onchange = () => { E.mode = r.value; lsSet(grp, r.value); renderFin(); });
        ['en-cnt', 'en-first'].forEach(id => { const el = box.querySelector('#' + id); if (el) el.oninput = () => { E[id === 'en-cnt' ? 'cnt' : 'first'] = el.value; schedulePreview(); }; });
        const us = box.querySelector('#en-usest'); if (us) us.onchange = () => { E.useSt = us.checked; schedulePreview(); };
        updateSave();
        schedulePreview();
    }

    let pvT = null;
    function schedulePreview() { clearTimeout(pvT); pvT = setTimeout(preview, 350); }
    function payload() {
        const g = id => (document.getElementById(id) || {}).value || '';
        const [src, ref] = (E.sel || 'X:0').split(':');
        const st = (E.statement || []).filter(x => x.amount && x.due);
        return {source: src, ref_id: Number(ref) || 0, policy_number: en(g('en-q')).trim(), kind: g('en-kind'), endorse_no: en(g('en-no')).trim(),
                issue_date: en(g('en-issue')), effect_date: en(g('en-eff')) || en(g('en-issue')), new_end_date: en(g('en-end')),
                gross: digits(g('en-gross')), net: digits(g('en-net')), tax: digits(g('en-tax')), description: g('en-desc'),
                central_no: (E.data || {}).central_no || '', insurance_type: (E.data || {}).insurance_type || '',
                finance_mode: E.mode || 'NONE', finance_opt: {count: Number(en(E.cnt || 1)) || 1, first_due: en(E.first || ''),
                    schedule: (E.mode === 'SEPARATE' && E.useSt !== false && st.length) ? st.map(x => ({amount: x.amount, due: x.due})) : []}};
    }
    async function preview() {
        const box = document.getElementById('en-prev');
        if (!box) return;
        const p = payload();
        if (!p.kind || p.finance_mode === 'NONE' || !Number(p.gross) || p.source === 'X') {
            box.innerHTML = E.policy && Number(p.gross) && p.kind && p.kind !== 'CORRECTIVE'
                ? `<p class="text-[11.5px] text-slate-600">حق بیمه‌ی نهایی: <b>${money(E.policy.final_premium)}</b> ← <b class="text-indigo-700">${money(E.policy.final_premium + (p.kind === 'ADDITIONAL' ? 1 : -1) * Number(p.gross))}</b></p>` : '';
            return;
        }
        const seq = ++E.previewSeq;
        const r = await post(Object.assign({action: 'preview'}, p));
        if (seq !== E.previewSeq || !r.ok) return;
        const pl = r.plan || {};
        const sign = p.kind === 'ADDITIONAL' ? 1 : -1;
        box.innerHTML = `<div class="rounded-xl bg-indigo-50/60 border border-indigo-100 p-2.5">
            <p class="text-[11.5px] text-slate-700 mb-1"><i class="fas fa-eye ml-1 text-indigo-500"></i>پیش‌نمایشِ اثر — حق بیمه‌ی نهایی: <b>${money(E.policy ? E.policy.final_premium : 0)}</b> ← <b class="text-indigo-700">${money((E.policy ? E.policy.final_premium : 0) + sign * Number(p.gross))}</b></p>
            ${(pl.changes || []).length ? `<table class="en-tb"><tr><th>قسط</th><th>سررسید</th><th>مبلغِ فعلی</th><th>مبلغِ جدید</th><th>تغییر</th></tr>
                ${pl.changes.map(c => `<tr><td>${fa(c.n)}</td><td>${fa(c.due)}</td><td>${money(c.before)}</td><td><b>${c.after <= 0 ? '<span class="text-rose-600">حذف</span>' : money(c.after)}</b></td><td>${smoney(c.after - c.before)}</td></tr>`).join('')}</table>` : ''}
            ${(pl.new || []).length ? `<table class="en-tb mt-1"><tr><th>قسطِ تازه</th><th>سررسید</th><th>مبلغ</th></tr>${pl.new.map((x, i) => `<tr><td>${fa(i + 1)}</td><td>${fa(x.due)}</td><td><b>${money(x.amount)}</b></td></tr>`).join('')}</table>` : ''}
            ${pl.credit ? `<p class="text-[11.5px] text-amber-700 mt-1"><i class="fas fa-hand-holding-dollar ml-1"></i>بستانکار (استردادِ نقدی): <b>${money(pl.credit)}</b></p>` : ''}
            ${pl.note ? `<p class="text-[11px] text-slate-500 mt-1">${esc(pl.note)}</p>` : ''}</div>`;
    }

    function updateSave() {
        const b = document.getElementById('en-save');
        if (b) b.disabled = !E.sel || !((document.getElementById('en-kind') || {}).value);
    }

    async function readFile(f) {
        if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') { toast('فایلِ الحاقیه باید PDF باشد.', 'warning'); return; }
        const drop = document.getElementById('en-drop');
        if (drop) drop.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حال خواندنِ الحاقیه...';
        const fd = new FormData();
        fd.append('action', 'read'); fd.append('file', f);
        if (E.opts.refPolicy) fd.append('ref_policy', E.opts.refPolicy);
        const r = await post(fd, true);
        if (!r.ok) { toast(r.error || 'خطا', 'error'); renderBody(); return; }
        E.token = r.token; E.data = r.data || {}; E.statement = r.statement || []; E.warnings = r.warnings || [];
        E.readErr = r.read_ok ? null : (r.read_error || 'خوانده نشد؛ دستی وارد کنید.');
        if (!E.opts.source) {
            E.matches = r.matches || [];
            E.policy = r.policy || null;
            E.sel = E.policy ? E.policy.source + ':' + E.policy.ref_id : (E.matches.length ? null : 'X:0');
        }
        if (E.statement.length) E.cnt = E.statement.length;
        renderBody();
        if (r.read_ok) toast(`الحاقیه خوانده شد: ${KIND_FA[E.data.kind] || ''} · ${money(E.data.gross)} ریال${E.policy ? '' : ' — بیمه‌نامه‌اش در سایت پیدا نشد'}`, E.policy ? 'success' : 'warning');
    }

    async function searchPolicy(q) {
        q = en(q).trim();
        if (!q) return;
        const r = await post({action: 'policy_search', q});
        E.matches = r.matches || [];
        if (E.matches.length === 1) { await loadPolicy(E.matches[0].source, E.matches[0].ref_id); return; }
        if (!E.matches.length) { E.sel = 'X:0'; E.policy = null; toast('بیمه‌نامه‌ای با این شماره در سایت پیدا نشد.', 'warning'); }
        refreshPol();
    }
    async function loadPolicy(src, id) {
        const r = await post({action: 'policy_info', source: src, ref_id: id});
        if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
        E.policy = r.policy; E.sel = src + ':' + id;
        if (!E.matches.some(m => m.source === src && String(m.ref_id) === String(id))) E.matches.unshift(r.policy);
        refreshPol();
    }
    function pickPolicy(k) {
        if (k === 'X:0') { E.sel = 'X:0'; E.policy = null; refreshPol(); return; }
        const [s, id] = k.split(':');
        loadPolicy(s, id);
    }
    function refreshPol() {
        const a = document.getElementById('en-pols'), b = document.getElementById('en-polinfo');
        if (a) { a.innerHTML = polButtons(); a.querySelectorAll('button').forEach(x => x.onclick = () => pickPolicy(x.dataset.k)); }
        if (b) b.innerHTML = polInfo();
        renderFin();
    }

    async function save() {
        const p = payload();
        if (!p.kind) { toast('نوعِ الحاقیه را انتخاب کنید.', 'warning'); return; }
        if (p.kind !== 'CORRECTIVE' && !Number(p.gross)) { toast('مبلغِ الحاقیه را وارد کنید.', 'warning'); return; }
        if (p.source === 'X' && !p.policy_number) { toast('شماره‌ی بیمه‌نامه را بنویسید.', 'warning'); return; }
        const b = document.getElementById('en-save');
        b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حال ثبت...';
        const r = await post(Object.assign({action: 'create', token: E.token || '', request_plate_id: E.opts.requestPlateId || 0}, p));
        if (!r.ok) { toast(r.error || 'خطا', 'error'); b.disabled = false; b.innerHTML = '<i class="fas fa-check"></i>ثبتِ الحاقیه'; return; }
        close();
        toast(`الحاقیه ثبت شد${r.final_premium !== null && r.final_premium !== undefined ? ' · حق بیمه‌ی نهایی ' + money(r.final_premium) + ' ریال' : ''}${(r.plan || {}).credit ? ' · بستانکار ' + money(r.plan.credit) : ''}.`, 'success');
        if (typeof E.opts.onDone === 'function') E.opts.onDone(r);
        if (P.root && document.body.contains(P.root)) load();
    }

    // ------------------------------------------------------------------
    //  صفحه‌ی «الحاقیه‌ها»
    // ------------------------------------------------------------------
    const P = {root: null, f: {}, rows: [], open: null};
    function init(root) {
        css();
        if (!root) return;
        P.root = root;
        if (!root.dataset.ready) {
            root.dataset.ready = '1';
            const canWrite = typeof permCan !== 'function' || permCan('endorsements', 'create') || permCan('issue-queue', 'edit');
            const isAdmin = typeof IS_ADMIN === 'undefined' || IS_ADMIN;
            root.innerHTML = `
            <div class="en-hero"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2><i class="fas fa-file-pen ml-2"></i>الحاقیه‌ها</h2>
                <p>الحاقیه‌های اضافی، برگشتی، فسخ و اصلاحیِ بیمه‌نامه‌های شرکتی و کارکنان. حق بیمه‌ی نهاییِ هر بیمه‌نامه = حق بیمه‌ی اصلی + اضافی‌ها − برگشتی‌ها، و اثرش روی اقساط همان‌طور که موقعِ ثبت انتخاب شد.</p></div>
                <div class="flex gap-2">${isAdmin && canWrite ? '<button class="en-btn" style="background:#fff;color:#0f766e" id="en-new"><i class="fas fa-plus"></i>ثبتِ الحاقیه</button>' : ''}
                <button class="en-btn" style="background:rgba(255,255,255,.18);color:#fff" id="en-xls"><i class="fas fa-file-excel"></i>اکسل</button></div></div></div>
            <div class="en-cards" id="en-cards"></div>
            <div class="card p-3 grid grid-cols-2 md:grid-cols-6 gap-2">
                <input type="search" id="en-fq" placeholder="جستجو: شماره بیمه‌نامه، پلاک، بیمه‌گذار، شاسی..." class="border rounded-lg px-3 py-2 text-xs md:col-span-2">
                <select id="en-fk" class="border rounded-lg px-2 py-2 text-xs"><option value="">همه‌ی نوع‌ها</option>${Object.entries(KIND_FA).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>
                <select id="en-fs" class="border rounded-lg px-2 py-2 text-xs"><option value="">شرکتی و کارکنان</option><option value="C">شرکتی</option><option value="P">کارکنان</option><option value="X">خارج از سایت</option></select>
                <input id="en-ff" placeholder="از تاریخ ۱۴۰۵/۰۱/۰۱" class="border rounded-lg px-2 py-2 text-xs"><input id="en-ft" placeholder="تا تاریخ" class="border rounded-lg px-2 py-2 text-xs">
            </div>
            <div class="card p-0 overflow-x-auto"><table class="w-full text-[11.5px] text-right whitespace-nowrap trk-table"><thead class="bg-slate-50 text-[10.5px] text-slate-500"><tr>
                <th class="p-2">#</th><th class="p-2">نوع</th><th class="p-2">بیمه‌گذار</th><th class="p-2">پلاک / شاسی</th><th class="p-2">بیمه‌نامه · الحاقیه</th><th class="p-2">صدور · اثر</th>
                <th class="p-2">مبلغ</th><th class="p-2">حق بیمه: اصلی ← نهایی</th><th class="p-2">اثرِ مالی</th><th class="p-2">بستانکار</th><th class="p-2"></th></tr></thead>
                <tbody id="en-body"></tbody></table></div>`;
            const nb = root.querySelector('#en-new'); if (nb) nb.onclick = () => open({onDone: load});
            root.querySelector('#en-xls').onclick = () => { const q = new URLSearchParams(Object.assign({action: 'export'}, filters())); location.href = API + '?' + q.toString(); };
            let t = null;
            ['en-fq', 'en-fk', 'en-fs', 'en-ff', 'en-ft'].forEach(id => root.querySelector('#' + id).addEventListener(id === 'en-fq' || id === 'en-ff' || id === 'en-ft' ? 'input' : 'change', () => { clearTimeout(t); t = setTimeout(load, 300); }));
        }
        load();
    }
    function filters() {
        const g = id => (P.root.querySelector('#' + id) || {}).value || '';
        return {q: en(g('en-fq')), kind: g('en-fk'), source: g('en-fs'), from: en(g('en-ff')), to: en(g('en-ft'))};
    }
    async function load() {
        const body = P.root.querySelector('#en-body');
        body.innerHTML = '<tr><td colspan="11" class="p-6 text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i></td></tr>';
        const r = await post(Object.assign({action: 'list'}, filters()));
        if (!r.ok) { body.innerHTML = `<tr><td colspan="11" class="p-6 text-center text-rose-500">${esc(r.error || 'خطا')}</td></tr>`; return; }
        P.rows = r.rows || [];
        const s = r.summary || {};
        P.root.querySelector('#en-cards').innerHTML = `<div><small>تعدادِ الحاقیه‌ها</small><b>${fa(s.count || 0)}</b></div>
            <div><small>جمعِ اضافی (ریال)</small><b class="text-emerald-700">${money(s.add)}</b></div>
            <div><small>جمعِ برگشتی و فسخ (ریال)</small><b class="text-rose-700">${money(s.ref)}</b></div>
            <div><small>بستانکاریِ پرداخت‌نشده</small><b class="text-amber-700">${money(s.credit)}</b></div>`;
        const isAdmin = typeof IS_ADMIN === 'undefined' || IS_ADMIN;
        body.innerHTML = P.rows.length ? P.rows.map((x, i) => `
            <tr class="hover:bg-slate-50 cursor-pointer" data-i="${i}">
                <td class="p-2">${fa(i + 1)}</td>
                <td class="p-2"><span class="en-badge ${KIND_CLS[x.kind] || ''}">${esc(x.kind_fa)}</span><small class="block text-[9.5px] text-slate-400">${esc(x.source_fa)}</small></td>
                <td class="p-2"><b>${esc(x.insured || '—')}</b>${x.company && x.company !== x.insured ? `<small class="block text-slate-400">${esc(x.company)}</small>` : ''}</td>
                <td class="p-2">${x.label ? plate(x.label) : '—'}</td>
                <td class="p-2"><span dir="ltr" class="font-bold">${esc(fa(x.policy_number || ''))}</span><small class="block text-slate-400">الحاقیه‌ی ${fa(x.endorse_no || '—')}</small></td>
                <td class="p-2">${fa(x.issue_date || '')}<small class="block text-slate-400">اثر: ${fa(x.effect_date || '')}</small></td>
                <td class="p-2 font-black ${x.signed >= 0 ? 'text-emerald-700' : 'text-rose-700'}">${smoney(x.signed)}</td>
                <td class="p-2">${x.premium ? money(x.premium) + ' ← <b>' + money(x.final_premium) + '</b>' : '—'}</td>
                <td class="p-2">${esc(x.finance_fa)}</td>
                <td class="p-2">${x.credit ? `<label class="inline-flex items-center gap-1 ${x.credit_paid ? 'text-emerald-600' : 'text-amber-700'}"><input type="checkbox" data-paid="${x.id}" ${x.credit_paid ? 'checked' : ''} ${isAdmin ? '' : 'disabled'}>${money(x.credit)}</label>` : '—'}</td>
                <td class="p-2"><div class="flex gap-1">${x.file_path ? `<a href="${fpath(x.file_path)}" target="_blank" class="px-2 py-1 rounded-lg bg-slate-100 hover:bg-slate-200" title="فایلِ الحاقیه" onclick="event.stopPropagation()"><i class="fas fa-file-pdf text-rose-500"></i></a>` : ''}
                    ${isAdmin ? `<button data-del="${x.id}" class="px-2 py-1 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100" title="حذف و برگرداندنِ اثرِ مالی"><i class="fas fa-trash"></i></button>` : ''}</div></td>
            </tr>
            <tr class="${P.open === x.id ? '' : 'hidden'}" data-d="${x.id}"><td colspan="11" class="p-3 bg-slate-50">${detail(x)}</td></tr>`).join('')
            : '<tr><td colspan="11" class="p-8 text-center text-slate-400">الحاقیه‌ای ثبت نشده.</td></tr>';
        body.querySelectorAll('tr[data-i]').forEach(tr => tr.onclick = e => {
            if (e.target.closest('button,a,input,label')) return;
            const x = P.rows[Number(tr.dataset.i)];
            P.open = P.open === x.id ? null : x.id;
            body.querySelectorAll('tr[data-d]').forEach(d => d.classList.toggle('hidden', Number(d.dataset.d) !== P.open));
        });
        body.querySelectorAll('[data-del]').forEach(b => b.onclick = () => del(Number(b.dataset.del)));
        body.querySelectorAll('[data-paid]').forEach(c => c.onchange = async () => {
            const r2 = await post({action: 'credit_paid', id: Number(c.dataset.paid), paid: c.checked ? 1 : 0});
            toast(r2.ok ? (c.checked ? 'استرداد پرداخت‌شده علامت خورد.' : 'برداشته شد.') : (r2.error || 'خطا'), r2.ok ? 'success' : 'error');
            if (r2.ok) load();
        });
    }
    function detail(x) {
        const pl = x.plan || {};
        return `<div class="grid md:grid-cols-2 gap-3 text-[11.5px]">
            <div><p><b>شرح:</b> ${esc(x.description || '—')}</p>${x.new_end_date ? `<p><b>تاریخِ پایانِ جدید:</b> ${fa(x.new_end_date)}</p>` : ''}
                <p><b>مبلغ:</b> ${money(x.gross)}${x.net ? ' · خالص ' + money(x.net) : ''}${x.tax ? ' · مالیات ' + money(x.tax) : ''}</p>
                <p class="text-slate-400">ثبت: ${esc(x.creator || '')} · ${x.origin === 'REQUEST' ? 'از درخواستِ شرکت' : x.origin === 'LEGACY' ? 'بایگانیِ قبلی' : 'دستی'}</p></div>
            <div>${(pl.changes || []).length ? `<table class="en-tb"><tr><th>قسط</th><th>سررسید</th><th>قبل</th><th>بعد</th></tr>${pl.changes.map(c => `<tr><td>${fa(c.n)}</td><td>${fa(c.due)}</td><td>${money(c.before)}</td><td>${c.deleted ? 'حذف' : money(c.after)}</td></tr>`).join('')}</table>` : ''}
                ${(pl.new || []).length ? `<table class="en-tb mt-1"><tr><th>قسطِ تازه</th><th>سررسید</th><th>مبلغ</th></tr>${pl.new.map((n, i) => `<tr><td>${fa(i + 1)}</td><td>${fa(n.due)}</td><td>${money(n.amount)}</td></tr>`).join('')}</table>` : ''}
                ${!(pl.changes || []).length && !(pl.new || []).length ? '<p class="text-slate-400">اقساط تغییری نکرد.</p>' : ''}</div></div>`;
    }
    function del(id) {
        const go = async () => {
            const r = await post({action: 'delete', id});
            toast(r.ok ? 'الحاقیه حذف و اثرِ مالی‌اش برگردانده شد.' : (r.error || 'خطا'), r.ok ? 'success' : 'error');
            if (r.ok) load();
        };
        if (typeof showConfirm === 'function') showConfirm('حذفِ الحاقیه', 'الحاقیه حذف می‌شود و تغییراتی که روی اقساط داده بود برمی‌گردد. ادامه می‌دهید؟', go);
        else if (confirm('حذف شود؟')) go();
    }

    // فهرستِ الحاقیه‌های یک بیمه‌نامه داخلِ پنجره‌های دیگر: <div data-endolist="C:12">
    async function mountLists(root) {
        css();
        for (const el of (root || document).querySelectorAll('[data-endolist]')) {
            const [src, id] = el.dataset.endolist.split(':');
            if (!Number(id)) continue;
            const r = await post({action: 'list', ref_source: src, ref_id: Number(id)});
            if (!r.ok) { el.innerHTML = `<span class="text-rose-500">${esc(r.error || 'خطا')}</span>`; continue; }
            const rows = r.rows || [];
            el.innerHTML = rows.length ? `<table class="en-tb"><tr><th>نوع</th><th>شماره</th><th>صدور · اثر</th><th>مبلغ</th><th>اثرِ مالی</th><th>حق بیمه‌ی نهایی</th><th></th></tr>
                ${rows.map(x => `<tr><td><span class="en-badge ${KIND_CLS[x.kind] || ''}">${esc(x.kind_fa)}</span></td><td>${fa(x.endorse_no || '—')}</td>
                    <td>${fa(x.issue_date || '')} · ${fa(x.effect_date || '')}</td><td class="font-bold ${x.signed >= 0 ? 'text-emerald-700' : 'text-rose-700'}">${smoney(x.signed)}</td>
                    <td>${esc(x.finance_fa)}${x.credit ? ` · بستانکار ${money(x.credit)}` : ''}</td><td>${money(x.final_premium)}</td>
                    <td>${x.file_path ? `<a href="${fpath(x.file_path)}" target="_blank" class="text-blue-600"><i class="fas fa-file-pdf"></i></a>` : ''}</td></tr>`).join('')}</table>`
                : '<span class="text-slate-400">الحاقیه‌ای ثبت نشده.</span>';
        }
    }

    window.Endorse = {open, init, badge, mountLists, kindFa: k => KIND_FA[k] || k};
})();
