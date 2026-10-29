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
    const isPT = v => window.isPlateText ? window.isPlateText(v) : /ایران/.test(String(v || ''));   // پلاکِ خودرو یا موتورسیکلت
    const plate = p => p && isPT(p) && typeof formatPlateHtml === 'function' ? `<span class="plate-display">${formatPlateHtml(p)}</span>` : esc(fa(p || ''));
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
    .en-x{flex-shrink:0;background:rgba(255,255,255,.18);border:0;color:#fff;width:34px;height:34px;border-radius:12px;cursor:pointer;font-size:16px}
    .en-bd{padding:16px 20px;display:flex;flex-direction:column;gap:14px}
    .en-sec{border:1px solid #e2e8f0;border-radius:18px;padding:12px 14px}.en-sec h4{font-size:12px;font-weight:900;color:#334155;margin-bottom:8px}
    .en-drop{display:block;border:2px dashed #94a3b8;border-radius:18px;padding:18px;text-align:center;cursor:pointer;color:#475569;font-size:12px;background:#f8fafc}
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
        Object.assign(E, {opts: opts || {}, token: null, data: {}, matches: [], policy: null, statement: [], warnings: [], sel: null, previewSeq: 0,
                          finalToken: null, finalDiffs: [], finalWarn: [], finalErr: null});
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
                <label class="en-drop" id="en-drop"><input type="file" data-uk-skip accept="application/pdf,.pdf" class="hidden" id="en-file">
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
            <div class="en-sec" id="en-fin"></div>
            <div class="en-sec"><h4>۵) بیمه‌نامه‌ی نهایی (بعد از الحاقیه) — اختیاری</h4>
                <label class="en-drop" id="en-fdrop"><input type="file" data-uk-skip accept="application/pdf,.pdf" class="hidden" id="en-ffile">${finalDropHtml()}</label>
                <div id="en-fres" class="mt-2">${finalResHtml()}</div>
                <p class="text-[10.5px] text-slate-400 mt-1">اطلاعاتِ این فایل دوباره خوانده و با اطلاعاتِ قبلیِ بیمه‌نامه مقایسه می‌شود؛ هر چه عوض شده (پلاک، تعهد، تاریخ پایان، ...) در شرحِ الحاقیه نوشته می‌شود. بعداً هم از فهرستِ الحاقیه‌ها می‌شود داد.</p></div>`;
        const drop = bd.querySelector('#en-drop'), fi = bd.querySelector('#en-file');
        fi.onchange = () => fi.files[0] && readFile(fi.files[0]);
        drop.ondragover = e => { e.preventDefault(); drop.classList.add('on'); };
        drop.ondragleave = () => drop.classList.remove('on');
        drop.ondrop = e => { e.preventDefault(); drop.classList.remove('on'); const f = e.dataTransfer.files[0]; if (f) readFile(f); };
        const fdrop = bd.querySelector('#en-fdrop'), ffi = bd.querySelector('#en-ffile');
        ffi.onchange = () => ffi.files[0] && readFinal(ffi.files[0]);
        fdrop.ondragover = e => { e.preventDefault(); fdrop.classList.add('on'); };
        fdrop.ondragleave = () => fdrop.classList.remove('on');
        fdrop.ondrop = e => { e.preventDefault(); fdrop.classList.remove('on'); const f = e.dataTransfer.files[0]; if (f) readFinal(f); };
        bd.querySelector('#en-qb').onclick = () => searchPolicy(bd.querySelector('#en-q').value);
        bd.querySelector('#en-q').onkeydown = e => { if (e.key === 'Enter') searchPolicy(e.target.value); };
        bd.querySelectorAll('#en-pols button').forEach(b => b.onclick = () => pickPolicy(b.dataset.k));
        bd.querySelector('#en-kind').onchange = () => { renderFin(); };
        ['en-gross', 'en-eff', 'en-issue'].forEach(id => bd.querySelector('#' + id).addEventListener('input', schedulePreview));
        renderFin();
    }

    function finalDropHtml() {
        return E.finalToken ? `<i class="fas fa-circle-check text-emerald-600 ml-1"></i>بیمه‌نامه‌ی نهایی دریافت شد${E.finalErr ? ` — <span class="text-amber-700">${esc(E.finalErr)}</span>` : ''} · برای عوض کردن کلیک کنید`
            : '<i class="fas fa-file-shield ml-1"></i>فایلِ PDFِ بیمه‌نامه‌ی نهایی (بعد از الحاقیه) را بکشید یا کلیک کنید';
    }
    function finalResHtml() {
        if (!E.finalToken) return '';
        const ds = E.finalDiffs || [];
        return (E.finalWarn || []).map(w => `<div class="en-warn mb-1"><i class="fas fa-triangle-exclamation ml-1"></i>${esc(w)}</div>`).join('')
            + (ds.length ? `<div class="rounded-xl bg-indigo-50/60 border border-indigo-100 p-2.5 text-[11.5px]"><b class="text-indigo-700"><i class="fas fa-code-compare ml-1"></i>تغییرها نسبت به اطلاعاتِ قبلی (${fa(ds.length)}):</b>
                <ul class="mt-1 pr-4" style="list-style:disc">${ds.map(d => `<li>${esc(fa(d.text))}</li>`).join('')}</ul></div>`
                : (E.finalErr ? '' : '<p class="text-[11px] text-emerald-700"><i class="fas fa-check ml-1"></i>تغییری نسبت به اطلاعاتِ قبلیِ بیمه‌نامه دیده نشد.</p>'));
    }
    async function readFinal(f) {
        if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') { toast('فایلِ بیمه‌نامه باید PDF باشد.', 'warning'); return; }
        const drop = document.getElementById('en-fdrop');
        if (drop) drop.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ خواندنِ بیمه‌نامه‌ی نهایی...';
        const [src, ref] = (E.sel || 'X:0').split(':');
        const fd = new FormData();
        fd.append('action', 'final_read'); fd.append('file', f); fd.append('source', src); fd.append('ref_id', ref || 0);
        fd.append('policy_number', en((document.getElementById('en-q') || {}).value || '').trim() || (E.policy || {}).policy_number || '');
        const r = await post(fd, true);
        if (!r.ok) { toast(r.error || 'خطا', 'error'); E.finalToken = null; }
        else { E.finalToken = r.token; E.finalDiffs = r.diffs || []; E.finalWarn = r.warnings || []; E.finalErr = r.read_ok ? null : (r.read_error || 'خوانده نشد؛ فقط فایل ذخیره می‌شود.'); }
        const d2 = document.getElementById('en-fdrop'); if (d2) d2.innerHTML = '<input type="file" data-uk-skip accept="application/pdf,.pdf" class="hidden" id="en-ffile">' + finalDropHtml();
        const fi2 = document.getElementById('en-ffile'); if (fi2) fi2.onchange = () => fi2.files[0] && readFinal(fi2.files[0]);
        const box = document.getElementById('en-fres'); if (box) box.innerHTML = finalResHtml();
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
        const r = await post(Object.assign({action: 'create', token: E.token || '', final_token: E.finalToken || '', request_plate_id: E.opts.requestPlateId || 0}, p));
        if (!r.ok) { toast(r.error || 'خطا', 'error'); b.disabled = false; b.innerHTML = '<i class="fas fa-check"></i>ثبتِ الحاقیه'; return; }
        close();
        toast(`الحاقیه ثبت شد${r.final_premium !== null && r.final_premium !== undefined ? ' · حق بیمه‌ی نهایی ' + money(r.final_premium) + ' ریال' : ''}${(r.plan || {}).credit ? ' · بستانکار ' + money(r.plan.credit) : ''}${r.final ? (r.final.ok ? ' · بیمه‌نامه‌ی نهایی ذخیره شد (' + fa((r.final.diffs || []).length) + ' تغییر)' : ' · بیمه‌نامه‌ی نهایی: ' + (r.final.error || 'خطا')) : ''}.`, r.final && !r.final.ok ? 'warning' : 'success');
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
                <div class="flex gap-2 flex-wrap">${isAdmin && canWrite ? '<button class="en-btn" style="background:#fff;color:#0f766e" id="en-new"><i class="fas fa-plus"></i>ثبتِ الحاقیه</button><button class="en-btn" style="background:#fff;color:#4338ca" id="en-grp"><i class="fas fa-layer-group"></i>صدورِ گروهیِ الحاقیه</button><button class="en-btn" style="background:rgba(255,255,255,.18);color:#fff" id="en-fgrp"><i class="fas fa-file-shield"></i>بیمه‌نامه‌های نهایی (گروهی)</button>' : ''}
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
            const gb = root.querySelector('#en-grp'); if (gb) gb.onclick = () => openGroup({onDone: load});
            const fb = root.querySelector('#en-fgrp'); if (fb) fb.onclick = () => openFinalGroup({onDone: load});
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
                <td class="p-2"><div class="flex gap-1">${filesHtml(x, isAdmin)}
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
        body.querySelectorAll('[data-del]').forEach(b => b.onclick = () => del(P.rows.find(r => r.id === Number(b.dataset.del)) || Number(b.dataset.del)));
        bindFinalUpload(body, load);
        body.querySelectorAll('[data-paid]').forEach(c => c.onchange = async () => {
            const r2 = await post({action: 'credit_paid', id: Number(c.dataset.paid), paid: c.checked ? 1 : 0});
            toast(r2.ok ? (c.checked ? 'استرداد پرداخت‌شده علامت خورد.' : 'برداشته شد.') : (r2.error || 'خطا'), r2.ok ? 'success' : 'error');
            if (r2.ok) load();
        });
    }
    function detail(x) {
        const pl = x.plan || {};
        return `<div class="grid md:grid-cols-2 gap-3 text-[11.5px]">
            <div><p style="white-space:pre-line"><b>شرح:</b> ${esc(fa(x.description || '—'))}</p>${x.new_end_date ? `<p><b>تاریخِ پایانِ جدید:</b> ${fa(x.new_end_date)}</p>` : ''}
                <p class="mt-1">${x.file_path ? `<a href="${fpath(x.file_path)}" target="_blank" class="text-blue-600 font-bold"><i class="fas fa-file-pdf ml-1"></i>فایلِ الحاقیه</a>` : '<span class="text-slate-400">فایلِ الحاقیه ندارد</span>'}
                 · ${x.final_file_path ? `<a href="${fpath(x.final_file_path)}" target="_blank" class="text-emerald-700 font-bold"><i class="fas fa-file-shield ml-1"></i>بیمه‌نامه‌ی نهایی</a>` : '<span class="text-slate-400">بیمه‌نامه‌ی نهایی هنوز داده نشده</span>'}</p>
                <p><b>مبلغ:</b> ${money(x.gross)}${x.net ? ' · خالص ' + money(x.net) : ''}${x.tax ? ' · مالیات ' + money(x.tax) : ''}</p>
                <p class="text-slate-400">ثبت: ${esc(x.creator || '')} · ${x.origin === 'REQUEST' ? 'از درخواستِ شرکت' : x.origin === 'LEGACY' ? 'بایگانیِ قبلی' : 'دستی'}</p></div>
            <div>${(pl.changes || []).length ? `<table class="en-tb"><tr><th>قسط</th><th>سررسید</th><th>قبل</th><th>بعد</th></tr>${pl.changes.map(c => `<tr><td>${fa(c.n)}</td><td>${fa(c.due)}</td><td>${money(c.before)}</td><td>${c.deleted ? 'حذف' : money(c.after)}</td></tr>`).join('')}</table>` : ''}
                ${(pl.new || []).length ? `<table class="en-tb mt-1"><tr><th>قسطِ تازه</th><th>سررسید</th><th>مبلغ</th></tr>${pl.new.map((n, i) => `<tr><td>${fa(i + 1)}</td><td>${fa(n.due)}</td><td>${money(n.amount)}</td></tr>`).join('')}</table>` : ''}
                ${!(pl.changes || []).length && !(pl.new || []).length ? '<p class="text-slate-400">اقساط تغییری نکرد.</p>' : ''}</div></div>`;
    }
    // حذفِ تکیِ الحاقیه با انتخابِ اثرها: برگرداندنِ اثرِ مالی، حذفِ فایلِ الحاقیه، حذفِ بیمه‌نامه‌ی نهایی
    function del(x, after) {
        if (typeof x !== 'object') x = (P.rows || []).find(r => r.id === Number(x)) || { id: Number(x) };
        const hasFin = x.finance_mode && x.finance_mode !== 'NONE';
        const box = document.createElement('div');
        box.className = 'modal-overlay active'; box.style.zIndex = '1000003';
        const ck = (k, label, on, dis, hint) => `<label class="flex items-start gap-2 p-2 rounded-xl border ${dis ? 'opacity-50' : 'hover:bg-slate-50 cursor-pointer'}"><input type="checkbox" data-o="${k}" ${on ? 'checked' : ''} ${dis ? 'disabled' : ''} class="mt-1">
            <span><b class="text-[12px]">${label}</b>${hint ? `<small class="block text-[10.5px] text-slate-500">${hint}</small>` : ''}</span></label>`;
        box.innerHTML = `<div class="modal-content w-full max-w-md p-5 relative">
            <button type="button" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl" data-x><i class="fas fa-times"></i></button>
            <h3 class="font-black text-base text-slate-800 mb-1"><i class="fas fa-trash text-rose-500 ml-1"></i>حذفِ الحاقیه</h3>
            <p class="text-[11px] text-slate-500 mb-3">${esc(x.kind_fa || '')} ${x.endorse_no ? 'شماره‌ی ' + fa(x.endorse_no) : ''} ${x.policy_number ? '· بیمه‌نامه‌ی <span dir="ltr">' + esc(fa(x.policy_number)) + '</span>' : ''}</p>
            <div class="space-y-2">
              ${ck('revert_finance', 'برگرداندنِ اثرِ مالی', hasFin, !hasFin, hasFin ? 'اقساطی که این الحاقیه ساخته/تغییر داده به حالتِ قبل برمی‌گردد (' + esc(x.finance_fa || '') + ')' : 'این الحاقیه اثرِ مالی نداشته است.')}
              ${ck('delete_file', 'حذفِ فایلِ الحاقیه', !!x.file_path, !x.file_path, x.file_path ? 'از پوشه‌ی بیمه‌نامه و همه‌ی کپی‌هایش در بایگانی' : 'فایلی ندارد.')}
              ${ck('delete_final', 'حذفِ بیمه‌نامه‌ی نهایی (بعد از الحاقیه)', !!x.final_file_path, !x.final_file_path, x.final_file_path ? 'با شرایط/صفحه‌های جداشده‌اش' : 'بیمه‌نامه‌ی نهایی ثبت نشده.')}
            </div>
            <p class="text-[10.5px] text-slate-400 mt-2">گزینه‌ای که تیکش برداشته شود همان‌طور می‌ماند؛ خودِ ردیفِ الحاقیه در هر حال حذف می‌شود.</p>
            <button type="button" data-ok class="w-full mt-3 bg-rose-600 hover:bg-rose-700 text-white text-sm font-black py-2.5 rounded-xl"><i class="fas fa-trash ml-1"></i>حذفِ الحاقیه</button></div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.querySelector('[data-x]').onclick = close;
        box.addEventListener('click', e => { if (e.target === box) close(); });
        box.querySelector('[data-ok]').onclick = async () => {
            const o = k => { const c = box.querySelector(`[data-o="${k}"]`); return c && !c.disabled && c.checked ? 1 : 0; };
            const b = box.querySelector('[data-ok]'); b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ حذف...';
            const r = await post({ action: 'delete', id: x.id, revert_finance: o('revert_finance'), delete_file: o('delete_file'), delete_final: o('delete_final') });
            if (!r.ok) { toast(r.error || 'خطا', 'error'); b.disabled = false; b.innerHTML = '<i class="fas fa-trash ml-1"></i>حذفِ الحاقیه'; return; }
            close();
            toast('الحاقیه حذف شد' + (r.finance_reverted && hasFin ? ' و اثرِ مالی‌اش برگشت' : '') + '.', 'success');
            if (typeof after === 'function') after(); else load();
        };
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
                    <td><div class="flex gap-1">${filesHtml(x, typeof IS_ADMIN === 'undefined' || IS_ADMIN)}${typeof IS_ADMIN === 'undefined' || IS_ADMIN ? `<button type="button" data-edel="${x.id}" class="px-2 py-1 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100" title="حذفِ الحاقیه (با انتخابِ اثرها)"><i class="fas fa-trash"></i></button>` : ''}</div></td></tr>
                    ${x.description ? `<tr><td colspan="7" class="text-[10.5px] text-slate-500" style="white-space:pre-line">${esc(fa(x.description))}</td></tr>` : ''}`).join('')}</table>`
                : '<span class="text-slate-400">الحاقیه‌ای ثبت نشده.</span>';
            bindFinalUpload(el, () => { delete el.dataset.loaded; mountLists(el.parentElement || document); });
            el.querySelectorAll('[data-edel]').forEach(b => b.onclick = ev => { ev.stopPropagation(); del(rows.find(r => r.id === Number(b.dataset.edel)), () => mountLists(el.parentElement || document)); });
        }
    }

    // دکمه‌های فایل: الحاقیه، بیمه‌نامه‌ی نهایی، بارگذاریِ بیمه‌نامه‌ی نهایی
    function filesHtml(x, isAdmin) {
        return (x.file_path ? `<a href="${fpath(x.file_path)}" target="_blank" class="px-2 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-[10.5px] font-bold whitespace-nowrap" title="دانلودِ فایلِ الحاقیه" onclick="event.stopPropagation()"><i class="fas fa-file-pdf text-rose-500 ml-1"></i>الحاقیه</a>` : '')
            + (x.final_file_path ? `<a href="${fpath(x.final_file_path)}" target="_blank" class="px-2 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-[10.5px] font-bold whitespace-nowrap" title="دانلودِ بیمه‌نامه‌ی نهایی (بعد از الحاقیه)" onclick="event.stopPropagation()"><i class="fas fa-file-shield ml-1"></i>نهایی</a>` : '')
            + (isAdmin ? `<label class="px-2 py-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-[10.5px] font-bold whitespace-nowrap cursor-pointer" title="${x.final_file_path ? 'عوض کردنِ' : 'بارگذاریِ'} بیمه‌نامه‌ی نهایی (بعد از الحاقیه)" onclick="event.stopPropagation()"><i class="fas fa-upload ml-1"></i>${x.final_file_path ? 'تعویضِ نهایی' : 'بیمه‌نامه‌ی نهایی'}<input type="file" data-uk-skip accept="application/pdf,.pdf" class="hidden" data-final="${x.id}"></label>` : '');
    }
    function bindFinalUpload(root, after) {
        root.querySelectorAll('input[data-final]').forEach(inp => inp.onchange = async () => {
            const f = inp.files[0]; if (!f) return;
            const send = async force => {
                const fd = new FormData();
                fd.append('action', 'final_attach'); fd.append('id', inp.dataset.final); fd.append('file', f); if (force) fd.append('force', '1');
                toast('در حالِ خواندن و مقایسه‌ی بیمه‌نامه‌ی نهایی...', 'info');
                const r = await post(fd, true);
                if (!r.ok && r.mismatch && !force) {
                    const go = () => send(true);
                    if (typeof showConfirm === 'function') showConfirm('شماره‌ی بیمه‌نامه یکی نیست', r.error + ' باز هم ذخیره شود؟', go); else if (confirm(r.error + ' ذخیره شود؟')) go();
                    return;
                }
                if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                toast(`بیمه‌نامه‌ی نهایی ذخیره شد${(r.diffs || []).length ? ' · ' + fa(r.diffs.length) + ' تغییر در شرح نوشته شد' : ' · تغییری دیده نشد'}${r.read ? '' : ' (فایل خوانده نشد؛ فقط ذخیره شد)'}.`, 'success');
                if (after) after();
            };
            send(false);
        });
    }

    // ------------------------------------------------------------------
    //  صدورِ گروهیِ الحاقیه: اکسلِ بیمه‌گر + فایلِ PDFِ گروهی
    // ------------------------------------------------------------------
    const G = {};
    const STATE = {ok: ['آماده', 'bg-emerald-50 text-emerald-700 border-emerald-200'], nomatch: ['بیمه‌نامه در سایت نیست', 'bg-amber-50 text-amber-700 border-amber-200'],
                   dup: ['قبلاً ثبت شده', 'bg-slate-100 text-slate-500 border-slate-200'], incomplete: ['ناقص', 'bg-rose-50 text-rose-700 border-rose-200']};
    function shell(id, title, sub, foot) {
        css();
        const ov = document.createElement('div');
        ov.className = 'en-ov'; ov.id = id;
        ov.innerHTML = `<div class="en-box" style="max-width:1200px"><div class="en-hd"><div><b>${title}</b><small>${sub}</small></div><button class="en-x" data-x>✕</button></div>
            <div class="en-bd" data-bd></div><div class="en-ft" data-ft>${foot || ''}</div></div>`;
        document.body.appendChild(ov);
        ov.querySelector('[data-x]').onclick = () => ov.remove();
        return ov;
    }
    function dropBox(id, accept, text) {
        return `<label class="en-drop" data-drop="${id}"><input type="file" data-uk-skip accept="${accept}" class="hidden" data-in="${id}"><span data-lbl="${id}">${text}</span></label>`;
    }
    function bindDrop(ov, id, onFile) {
        const d = ov.querySelector(`[data-drop="${id}"]`), i = ov.querySelector(`[data-in="${id}"]`);
        const set = f => { onFile(f); ov.querySelector(`[data-lbl="${id}"]`).innerHTML = `<i class="fas fa-circle-check text-emerald-600 ml-1"></i>${esc(f.name)} · برای عوض کردن کلیک کنید`; };
        i.onchange = () => i.files[0] && set(i.files[0]);
        d.ondragover = e => { e.preventDefault(); d.classList.add('on'); };
        d.ondragleave = () => d.classList.remove('on');
        d.ondrop = e => { e.preventDefault(); d.classList.remove('on'); if (e.dataTransfer.files[0]) set(e.dataTransfer.files[0]); };
    }
    function openGroup(opts) {
        Object.keys(G).forEach(k => delete G[k]);
        Object.assign(G, {opts: opts || {}, excel: null, pdf: null, token: null, items: [], rows: {}});
        const ov = shell('en-gov', '<i class="fas fa-layer-group ml-1"></i>صدورِ گروهیِ الحاقیه',
            'اکسلِ الحاقیه‌های بیمه‌گر و/یا فایلِ PDFِ گروهی را بدهید؛ هر الحاقیه جدا، خوانده و به بیمه‌نامه‌ی خودش وصل می‌شود. قبل از ثبت همه را می‌بینید و اثرِ مالی را (کلی یا برای هر ردیف) تعیین می‌کنید.',
            '<button class="en-btn s" data-x2>بستن</button><button class="en-btn p" data-go disabled><i class="fas fa-magnifying-glass"></i>بررسیِ فایل‌ها</button>');
        G.ov = ov;
        ov.querySelector('[data-x2]').onclick = () => ov.remove();
        ov.querySelector('[data-bd]').innerHTML = `<div class="grid md:grid-cols-2 gap-3">
            <div class="en-sec"><h4>اکسلِ الحاقیه‌ها (خروجیِ سایتِ بیمه‌گر)</h4>${dropBox('x', '.xlsx,.csv', '<i class="fas fa-file-excel text-emerald-600 ml-1"></i>فایلِ اکسل را بکشید یا کلیک کنید')}
                <p class="text-[10.5px] text-slate-400 mt-1">شماره‌ی بیمه‌نامه، شماره و نوعِ الحاقیه، مبلغ، تاریخ‌ها و شرح از ستون‌هایش خوانده می‌شود.</p></div>
            <div class="en-sec"><h4>فایلِ PDFِ الحاقیه‌های گروهی</h4>${dropBox('p', 'application/pdf,.pdf', '<i class="fas fa-file-pdf text-rose-500 ml-1"></i>فایلِ PDF را بکشید یا کلیک کنید')}
                <p class="text-[10.5px] text-slate-400 mt-1">هر الحاقیه (با اعلامیه و فیش‌هایش) جدا و کنارِ بیمه‌نامه‌ی خودش ذخیره می‌شود؛ «شرح تغییرات» در شرحِ الحاقیه می‌آید.</p></div></div>
            <p class="text-[11px] text-slate-500">یکی از دو فایل هم کافی است؛ با هر دو، مبلغ‌ها از اکسل و فایل و شرحِ تغییرات از PDF گرفته و با هم مقایسه می‌شود.</p>`;
        const upd = () => { ov.querySelector('[data-go]').disabled = !(G.excel || G.pdf); };
        bindDrop(ov, 'x', f => { G.excel = f; upd(); });
        bindDrop(ov, 'p', f => { G.pdf = f; upd(); });
        ov.querySelector('[data-go]').onclick = analyzeGroup;
    }
    async function analyzeGroup() {
        const ov = G.ov, b = ov.querySelector('[data-go]');
        b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ خواندن (ممکن است کمی طول بکشد)...';
        const fd = new FormData();
        fd.append('action', 'bundle_analyze');
        if (G.excel) fd.append('excel', G.excel);
        if (G.pdf) fd.append('pdf', G.pdf);
        const r = await post(fd, true);
        if (!r.ok) { toast(r.error || 'خطا', 'error'); b.disabled = false; b.innerHTML = '<i class="fas fa-magnifying-glass"></i>بررسیِ فایل‌ها'; return; }
        G.token = r.token; G.items = r.items || [];
        G.items.forEach(it => { G.rows[it.i] = {include: it.state === 'ok' || it.state === 'nomatch', sel: it.sel, mode: 'DEFAULT'}; });
        G.modeA = lsGet('A') || 'SPREAD'; G.modeR = lsGet('R') || 'LAST';
        renderGroup(r);
    }
    function modeSelect(kind, val, attrs) {
        const ms = modesOf(kind);
        if (!ms.length) return '<span class="text-[10.5px] text-slate-400">بدونِ اثرِ مالی</span>';
        return `<select class="en-in" style="padding:4px 6px;font-size:11px" ${attrs}><option value="DEFAULT" ${val === 'DEFAULT' ? 'selected' : ''}>طبقِ تنظیمِ کلی</option>${ms.map(([k, l]) => `<option value="${k}" ${val === k ? 'selected' : ''}>${l}</option>`).join('')}</select>`;
    }
    function rowMode(it) {
        const r = G.rows[it.i];
        if (it.kind === 'CORRECTIVE' || (r.sel || '').startsWith('X')) return 'NONE';
        if (r.mode && r.mode !== 'DEFAULT') return r.mode;
        return it.kind === 'ADDITIONAL' ? G.modeA : G.modeR;
    }
    function renderGroup(r) {
        const ov = G.ov, c = (r && r.counts) || {};
        const sum = G.items.reduce((a, it) => a + (G.rows[it.i].include ? (it.kind === 'ADDITIONAL' ? 1 : (it.kind === 'CORRECTIVE' ? 0 : -1)) * (Number(it.gross) || 0) : 0), 0);
        const nSel = G.items.filter(it => G.rows[it.i].include).length;
        ov.querySelector('[data-bd]').innerHTML = `
            <div class="en-cards"><div><small>الحاقیه‌های پیدا شده</small><b>${fa(G.items.length)}</b></div><div><small>آماده (بیمه‌نامه در سایت)</small><b class="text-emerald-700">${fa(c.ok || 0)}</b></div>
                <div><small>بیمه‌نامه در سایت نیست</small><b class="text-amber-700">${fa(c.nomatch || 0)}</b></div><div><small>قبلاً ثبت شده / ناقص</small><b class="text-slate-500">${fa((c.dup || 0) + (c.incomplete || 0))}</b></div></div>
            ${(r && r.warnings || []).map(w => `<div class="en-warn"><i class="fas fa-triangle-exclamation ml-1"></i>${esc(w)}</div>`).join('')}
            <div class="en-sec"><h4>اثرِ مالی (کلی) — برای هر ردیف هم جدا قابلِ تغییر است</h4>
                <div class="grid md:grid-cols-3 gap-2 text-[11.5px]">
                    <label class="flex flex-col gap-1">الحاقیه‌های اضافی<select class="en-in" data-ga>${MODES.ADDITIONAL.map(([k, l]) => `<option value="${k}" ${G.modeA === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                    <label class="flex flex-col gap-1">برگشتی و فسخ<select class="en-in" data-gr>${MODES.REFUND.map(([k, l]) => `<option value="${k}" ${G.modeR === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                    <label class="flex items-center gap-2 mt-4"><input type="checkbox" data-gnone ${G.modeA === 'NONE' && G.modeR === 'NONE' ? 'checked' : ''}><b>هیچ‌کدام اثرِ مالی نداشته باشد</b></label>
                </div>
                <p class="text-[10.5px] text-slate-400 mt-1">«اقساطِ جدا» برای الحاقیه‌هایی که اعلامیه دارند طبقِ اقساطِ اعلامیه‌ی بیمه‌گر ساخته می‌شود. الحاقیه‌ی اصلاحی و بیمه‌نامه‌ای که در سایت نیست اثرِ مالی ندارند.</p></div>
            <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:14px"><table class="en-tb" style="min-width:1050px"><tr>
                <th><input type="checkbox" data-all ${nSel === G.items.length ? 'checked' : ''}></th><th>وضعیت</th><th>بیمه‌نامه · الحاقیه</th><th>نوع</th><th>بیمه‌نامه در سایت</th><th>مبلغ</th><th>صدور · اثر</th><th>شرح</th><th>فایل</th><th>اثرِ مالی</th></tr>
                ${G.items.map(it => { const rw = G.rows[it.i], st = STATE[it.state] || ['', '']; return `<tr data-row="${it.i}" style="${rw.include ? '' : 'opacity:.55'}">
                    <td><input type="checkbox" data-inc="${it.i}" ${rw.include ? 'checked' : ''} ${it.state === 'dup' || it.state === 'incomplete' ? 'disabled' : ''}></td>
                    <td><span class="en-badge ${st[1]}">${st[0]}</span>${(it.warnings || []).length ? `<i class="fas fa-triangle-exclamation text-amber-500 mr-1" title="${esc(it.warnings.join(' | '))}"></i>` : ''}${it.read_error ? `<i class="fas fa-circle-exclamation text-rose-500 mr-1" title="${esc(it.read_error)}"></i>` : ''}</td>
                    <td><span dir="ltr" class="font-bold">${esc(fa(it.policy_number))}</span><small class="block text-slate-400">الحاقیه‌ی ${fa(it.endorse_no || '—')}${it.excel_row ? ' · ردیفِ ' + fa(it.excel_row) + ' اکسل' : ''}</small></td>
                    <td><span class="en-badge ${KIND_CLS[it.kind] || ''}">${esc(KIND_FA[it.kind] || '؟')}</span><small class="block text-slate-400">${esc(it.insurance_type || '')}</small></td>
                    <td><select class="en-in" style="padding:4px 6px;font-size:11px;max-width:230px" data-sel="${it.i}">${(it.matches || []).map(m => `<option value="${m.source}:${m.ref_id}" ${rw.sel === m.source + ':' + m.ref_id ? 'selected' : ''}>${esc(m.source_fa)} · ${esc(fa(m.label || ''))} · ${esc(m.insured || '')}</option>`).join('')}<option value="X:0" ${rw.sel === 'X:0' ? 'selected' : ''}>خارج از سایت (فقط سابقه)</option></select></td>
                    <td class="font-bold whitespace-nowrap ${it.kind === 'ADDITIONAL' ? 'text-emerald-700' : 'text-rose-700'}">${it.kind === 'CORRECTIVE' ? '—' : smoney((it.kind === 'ADDITIONAL' ? 1 : -1) * it.gross)}${it.tax ? `<small class="block text-slate-400 font-normal">مالیات ${money(it.tax)}</small>` : ''}</td>
                    <td class="whitespace-nowrap">${fa(it.issue_date || '')}<small class="block text-slate-400">اثر ${fa(it.effect_date || '')}</small></td>
                    <td style="max-width:280px;white-space:normal"><small style="white-space:pre-line">${esc(fa(it.full_description || '').slice(0, 260))}${(it.full_description || '').length > 260 ? '…' : ''}</small></td>
                    <td class="whitespace-nowrap">${it.file ? `<i class="fas fa-file-pdf text-rose-500"></i> ${it.pages ? 'ص ' + fa(it.pages[0]) + '–' + fa(it.pages[1]) : ''}` : '<span class="text-slate-400">بدونِ فایل</span>'}${(it.statement || []).length ? `<small class="block text-slate-400">${fa(it.statement.length)} قسط در اعلامیه</small>` : ''}</td>
                    <td>${it.kind === 'CORRECTIVE' || rw.sel === 'X:0' ? '<span class="text-[10.5px] text-slate-400">بدونِ اثرِ مالی</span>' : modeSelect(it.kind, rw.mode, `data-mode="${it.i}"`) + `<small class="block text-[10px] text-indigo-600 mt-0.5">${esc((endoModes[rowMode(it)] || ''))}</small>`}</td></tr>`; }).join('')}</table></div>
            <p class="text-[11.5px] text-slate-600">انتخاب‌شده: <b>${fa(nSel)}</b> الحاقیه · اثرِ جمع بر حق بیمه: <b class="${sum >= 0 ? 'text-emerald-700' : 'text-rose-700'}">${smoney(sum)}</b> ریال</p>`;
        const ft = ov.querySelector('[data-ft]');
        ft.innerHTML = `<button class="en-btn s" data-x2>بستن</button><button class="en-btn p" data-commit ${nSel ? '' : 'disabled'}><i class="fas fa-check"></i>ثبتِ ${fa(nSel)} الحاقیه</button>`;
        ft.querySelector('[data-x2]').onclick = () => ov.remove();
        ft.querySelector('[data-commit]').onclick = commitGroup;
        const bd = ov.querySelector('[data-bd]');
        bd.querySelector('[data-ga]').onchange = e => { G.modeA = e.target.value; lsSet('A', G.modeA); renderGroup(r); };
        bd.querySelector('[data-gr]').onchange = e => { G.modeR = e.target.value; lsSet('R', G.modeR); renderGroup(r); };
        bd.querySelector('[data-gnone]').onchange = e => { if (e.target.checked) { G.modeA = 'NONE'; G.modeR = 'NONE'; } else { G.modeA = lsGet('A') && lsGet('A') !== 'NONE' ? lsGet('A') : 'SPREAD'; G.modeR = lsGet('R') && lsGet('R') !== 'NONE' ? lsGet('R') : 'LAST'; } renderGroup(r); };
        bd.querySelector('[data-all]').onchange = e => { G.items.forEach(it => { if (it.state !== 'dup' && it.state !== 'incomplete') G.rows[it.i].include = e.target.checked; }); renderGroup(r); };
        bd.querySelectorAll('[data-inc]').forEach(x => x.onchange = () => { G.rows[x.dataset.inc].include = x.checked; renderGroup(r); });
        bd.querySelectorAll('[data-sel]').forEach(x => x.onchange = () => { G.rows[x.dataset.sel].sel = x.value; renderGroup(r); });
        bd.querySelectorAll('[data-mode]').forEach(x => x.onchange = () => { G.rows[x.dataset.mode].mode = x.value; renderGroup(r); });
    }
    const endoModes = {SPREAD: 'سرشکن روی اقساطِ باقی‌مانده', SEPARATE: 'اقساطِ جدا', CASH: 'نقد (یک‌جا)', LAST: 'کسر از آخرین اقساط', EVEN: 'کسرِ یکسان از اقساطِ باز',
                       REFUND_CASH: 'استردادِ نقدی (بستانکار)', NONE: 'بدونِ اثرِ مالی'};
    async function commitGroup() {
        const ov = G.ov, b = ov.querySelector('[data-commit]');
        b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ ثبت...';
        const rows = G.items.map(it => ({i: it.i, include: G.rows[it.i].include ? 1 : 0, sel: G.rows[it.i].sel, finance_mode: G.rows[it.i].mode || 'DEFAULT'}));
        const r = await post({action: 'bundle_commit', token: G.token, mode_add: G.modeA, mode_ref: G.modeR, rows});
        if (!r.ok) { toast(r.error || 'خطا', 'error'); b.disabled = false; b.innerHTML = '<i class="fas fa-check"></i>ثبت'; return; }
        const res = r.results || [];
        ov.querySelector('[data-bd]').innerHTML = `
            <div class="en-cards"><div><small>ثبت شد</small><b class="text-emerald-700">${fa(r.done)}</b></div><div><small>ثبت نشد</small><b class="text-rose-700">${fa(res.length - r.done)}</b></div>
                <div><small>با اثرِ مالی</small><b>${fa(res.filter(x => x.ok && x.finance_mode !== 'NONE').length)}</b></div><div><small>بدونِ اثرِ مالی</small><b>${fa(res.filter(x => x.ok && x.finance_mode === 'NONE').length)}</b></div></div>
            <table class="en-tb"><tr><th>نتیجه</th><th>بیمه‌نامه · الحاقیه</th><th>نوع</th><th>اثرِ مالی</th><th>حق بیمه‌ی نهایی</th><th>فایل</th></tr>
            ${res.map(x => `<tr><td>${x.ok ? '<span class="en-badge bg-emerald-50 text-emerald-700 border-emerald-200"><i class="fas fa-check"></i>ثبت شد</span>' : `<span class="en-badge bg-rose-50 text-rose-700 border-rose-200">ثبت نشد</span><small class="block text-rose-600">${esc(x.error || '')}</small>`}</td>
                <td><span dir="ltr" class="font-bold">${esc(fa(x.policy_number))}</span><small class="block text-slate-400">الحاقیه‌ی ${fa(x.endorse_no || '—')}</small></td><td>${esc(KIND_FA[x.kind] || '')}</td>
                <td>${x.ok ? esc(x.finance_fa || '') : '—'}${x.ok && x.source === 'X' ? '<small class="block text-amber-700">بیمه‌نامه در سایت نیست؛ فقط سابقه</small>' : ''}</td>
                <td>${x.final_premium !== null && x.final_premium !== undefined ? money(x.final_premium) : '—'}</td>
                <td>${x.file_path ? `<a href="${fpath(x.file_path)}" target="_blank" class="text-blue-600"><i class="fas fa-file-pdf"></i> الحاقیه</a>` : '—'}</td></tr>`).join('')}</table>
            <div class="en-sec"><h4>مرحله‌ی بعد: بیمه‌نامه‌های نهایی</h4><p class="text-[11.5px] text-slate-600">فایلِ PDFِ بیمه‌نامه‌های نهاییِ (بعد از الحاقیه) همین بیمه‌نامه‌ها را بدهید تا هر کدام به الحاقیه‌اش وصل و تغییرهایش در شرح نوشته شود.</p>
                <button class="en-btn p mt-2" data-fin><i class="fas fa-file-shield"></i>بارگذاریِ بیمه‌نامه‌های نهایی</button></div>`;
        ov.querySelector('[data-ft]').innerHTML = '<button class="en-btn s" data-x2>بستن</button>';
        ov.querySelector('[data-x2]').onclick = () => ov.remove();
        ov.querySelector('[data-fin]').onclick = () => { ov.remove(); openFinalGroup(G.opts); };
        toast(`${fa(r.done)} الحاقیه ثبت شد.`, r.done ? 'success' : 'warning');
        if (typeof G.opts.onDone === 'function') G.opts.onDone(r);
    }

    // ------------------------------------------------------------------
    //  بیمه‌نامه‌های نهایی (گروهی)
    // ------------------------------------------------------------------
    const F = {};
    function openFinalGroup(opts) {
        Object.keys(F).forEach(k => delete F[k]);
        Object.assign(F, {opts: opts || {}, pdf: null, token: null, items: [], rows: {}});
        const ov = shell('en-fov', '<i class="fas fa-file-shield ml-1"></i>بیمه‌نامه‌های نهایی (بعد از الحاقیه) — گروهی',
            'یک PDF با چند بیمه‌نامه‌ی نهایی بدهید؛ هر بیمه‌نامه جدا، خوانده و به الحاقیه‌ی همان بیمه‌نامه وصل می‌شود و تغییرهایش نسبت به قبل در شرحِ الحاقیه نوشته می‌شود.',
            '<button class="en-btn s" data-x2>بستن</button><button class="en-btn p" data-go disabled><i class="fas fa-magnifying-glass"></i>بررسی</button>');
        F.ov = ov;
        ov.querySelector('[data-x2]').onclick = () => ov.remove();
        ov.querySelector('[data-bd]').innerHTML = `<div class="en-sec"><h4>فایلِ PDFِ بیمه‌نامه‌های نهایی</h4>${dropBox('f', 'application/pdf,.pdf', '<i class="fas fa-file-pdf text-rose-500 ml-1"></i>فایل را بکشید یا کلیک کنید')}</div>`;
        bindDrop(ov, 'f', f => { F.pdf = f; ov.querySelector('[data-go]').disabled = false; });
        ov.querySelector('[data-go]').onclick = async () => {
            const b = ov.querySelector('[data-go]');
            b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ خواندن...';
            const fd = new FormData(); fd.append('action', 'final_bundle_analyze'); fd.append('pdf', F.pdf);
            const r = await post(fd, true);
            if (!r.ok) { toast(r.error || 'خطا', 'error'); b.disabled = false; b.innerHTML = '<i class="fas fa-magnifying-glass"></i>بررسی'; return; }
            F.token = r.token; F.items = r.items || [];
            F.items.forEach(it => { F.rows[it.i] = {include: it.state === 'ok', endo_id: it.endo ? it.endo.id : 0}; });
            renderFinal();
        };
    }
    function renderFinal() {
        const ov = F.ov;
        const st = {ok: ['آماده', 'bg-emerald-50 text-emerald-700 border-emerald-200'], has_final: ['قبلاً بیمه‌نامه‌ی نهایی دارد', 'bg-amber-50 text-amber-700 border-amber-200'],
                    noendo: ['الحاقیه‌ای برای این بیمه‌نامه ثبت نشده', 'bg-rose-50 text-rose-700 border-rose-200']};
        const n = F.items.filter(it => F.rows[it.i].include && F.rows[it.i].endo_id).length;
        ov.querySelector('[data-bd]').innerHTML = `<p class="text-[11.5px] text-slate-600">${fa(F.items.length)} بیمه‌نامه در فایل پیدا شد. ردیف‌هایی که «قبلاً بیمه‌نامه‌ی نهایی دارد» با تیک زدن جایگزین می‌شوند.</p>
            <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:14px"><table class="en-tb" style="min-width:900px"><tr><th></th><th>وضعیت</th><th>بیمه‌نامه</th><th>پلاک · بیمه‌گذار</th><th>الحاقیه</th><th>تغییرها نسبت به قبل</th></tr>
            ${F.items.map(it => { const rw = F.rows[it.i], s2 = st[it.state] || ['', '']; return `<tr style="${rw.include ? '' : 'opacity:.6'}">
                <td><input type="checkbox" data-finc="${it.i}" ${rw.include ? 'checked' : ''} ${it.endo ? '' : 'disabled'}></td>
                <td><span class="en-badge ${s2[1]}">${s2[0]}</span><small class="block text-slate-400">ص ${fa(it.page)}${it.end !== it.page ? '–' + fa(it.end) : ''}</small></td>
                <td dir="ltr" class="font-bold" style="text-align:right">${esc(fa(it.policy_number || '؟'))}</td>
                <td>${it.plate ? plate(it.plate) : '—'}<small class="block text-slate-500">${esc(it.insured || '')}</small></td>
                <td>${(it.candidates || []).length ? `<select class="en-in" style="padding:4px 6px;font-size:11px" data-fendo="${it.i}">${it.candidates.map(c => `<option value="${c.id}" ${rw.endo_id === c.id ? 'selected' : ''}>الحاقیه‌ی ${fa(c.endorse_no || '—')} · ${esc(c.kind_fa)}${c.has_final ? ' (نهایی دارد)' : ''}</option>`).join('')}</select>` : '—'}</td>
                <td style="white-space:normal">${(it.diffs || []).length ? `<ul class="pr-4" style="list-style:disc">${it.diffs.map(d => `<li class="text-[11px]">${esc(fa(d.text))}</li>`).join('')}</ul>` : '<span class="text-[11px] text-slate-400">تغییری دیده نشد</span>'}</td></tr>`; }).join('')}</table></div>`;
        const ft = ov.querySelector('[data-ft]');
        ft.innerHTML = `<button class="en-btn s" data-x2>بستن</button><button class="en-btn p" data-commit ${n ? '' : 'disabled'}><i class="fas fa-check"></i>ذخیره‌ی ${fa(n)} بیمه‌نامه‌ی نهایی</button>`;
        ft.querySelector('[data-x2]').onclick = () => ov.remove();
        ov.querySelectorAll('[data-finc]').forEach(x => x.onchange = () => { F.rows[x.dataset.finc].include = x.checked; renderFinal(); });
        ov.querySelectorAll('[data-fendo]').forEach(x => x.onchange = () => { F.rows[x.dataset.fendo].endo_id = Number(x.value); });
        ft.querySelector('[data-commit]').onclick = async () => {
            const b = ft.querySelector('[data-commit]');
            b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ ذخیره...';
            const r = await post({action: 'final_bundle_commit', token: F.token, rows: F.items.map(it => ({i: it.i, include: F.rows[it.i].include ? 1 : 0, endo_id: F.rows[it.i].endo_id || 0}))});
            if (!r.ok) { toast(r.error || 'خطا', 'error'); b.disabled = false; return; }
            const res = r.results || [];
            ov.querySelector('[data-bd]').innerHTML = `<table class="en-tb"><tr><th>نتیجه</th><th>بیمه‌نامه</th><th>تغییرهای ثبت‌شده در شرح</th><th>فایل</th></tr>
                ${res.map(x => `<tr><td>${x.ok ? '<span class="en-badge bg-emerald-50 text-emerald-700 border-emerald-200"><i class="fas fa-check"></i>ذخیره شد</span>' : `<span class="en-badge bg-rose-50 text-rose-700 border-rose-200">نشد</span><small class="block text-rose-600">${esc(x.error || '')}</small>`}</td>
                    <td dir="ltr" style="text-align:right">${esc(fa(x.policy_number))}</td><td style="white-space:normal">${(x.diffs || []).map(d => esc(fa(d.text))).join('<br>') || '<span class="text-slate-400">تغییری نبود</span>'}</td>
                    <td>${x.final_file_path ? `<a href="${fpath(x.final_file_path)}" target="_blank" class="text-emerald-700"><i class="fas fa-file-shield"></i> نهایی</a>` : '—'}</td></tr>`).join('')}</table>`;
            ft.innerHTML = '<button class="en-btn s" data-x2>بستن</button>';
            ft.querySelector('[data-x2]').onclick = () => ov.remove();
            toast(`${fa(r.done)} بیمه‌نامه‌ی نهایی ذخیره شد.`, r.done ? 'success' : 'warning');
            if (typeof F.opts.onDone === 'function') F.opts.onDone(r);
        };
    }

    window.Endorse = {open, init, badge, mountLists, openGroup, openFinalGroup, kindFa: k => KIND_FA[k] || k};
})();
