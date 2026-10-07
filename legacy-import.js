// فایل: legacy-import.js
// «ورودِ بایگانی‌های قبلی» (فقط مدیر کل):
//   ۱) اکسل‌های صدورِ پاسارگاد (بدنه/ثالث) + اکسلِ الحاقیه‌ها => پیش‌نمایش (شرکتی/کارکنان، نگاشتِ شرکت‌ها، الحاقیه‌ها) => ثبت
//      شرکتی: هر «شرکت + تاریخِ صدور» یک درخواست مثلِ بایگانیِ خودِ سایت؛ کارکنان: شخص + معرفی‌نامه + سهمیه + پرونده‌ی صادرشده
//   ۲) پوشه‌های فایل (موقت/ورود بایگانی): اسکنِ تکه‌تکه => گروه‌ها => «ادغامِ همه‌ی موارد کامل» یا تکی/دستی
//   ۳) دسته‌های ثبت‌شده با امکانِ برگرداندنِ کامل
// منطق: api/_legacy.php و api/_legacy_files.php  (api/legacy_actions.php)
(function () {
    'use strict';
    const API = 'api/legacy_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => fa((Number(v) || 0).toLocaleString('en-US'));
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const ask = async m => (window.uiConfirm ? uiConfirm('ورودِ بایگانی', m, {}) : confirm(m));
    const plate = p => p && /ایران/.test(p) && typeof formatPlateHtml === 'function' ? `<span class="plate-display">${formatPlateHtml(p)}</span>` : esc(fa(p || ''));
    const size = b => { b = Number(b) || 0; return b > 1048576 ? fa((b / 1048576).toFixed(1)) + ' مگ' : fa(Math.max(1, Math.round(b / 1024))) + ' کیلو'; };
    const post = async (body, isForm) => {
        try {
            const r = await fetch(API, isForm ? {method: 'POST', body} : {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
            const t = await r.text();
            try { return JSON.parse(t); } catch (e) { return {ok: false, error: 'پاسخِ نامعتبر از سرور' + (r.status === 413 ? ' (حجمِ فایل از سقفِ سرور بیشتر است)' : '') + '.'}; }
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    };

    const CSS = `
    .lg-hero{border-radius:26px;padding:20px 22px;color:#fff;background:linear-gradient(120deg,#334155,#0f766e 55%,#4338ca);box-shadow:0 20px 40px -26px #0f766e}
    .lg-hero h2{font-size:19px;font-weight:900}.lg-hero p{font-size:11.5px;opacity:.9;margin-top:4px;line-height:1.9;max-width:900px}
    .lg-steps{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.lg-steps button{border:0;border-radius:999px;padding:7px 14px;font-size:12px;font-weight:800;cursor:pointer;background:rgba(255,255,255,.16);color:#fff;font-family:inherit}
    .lg-steps button.on{background:#fff;color:#0f766e}
    .lg-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:16px;display:flex;flex-direction:column;gap:12px}
    .lg-card h3{font-size:14px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px}.lg-card h3 small{font-size:11px;color:#64748b;font-weight:700}
    .lg-drop{border:2px dashed #94a3b8;border-radius:18px;padding:22px;text-align:center;cursor:pointer;color:#475569;font-size:12px;background:#f8fafc;line-height:2}
    .lg-drop:hover,.lg-drop.on{border-color:#0f766e;background:#f0fdfa}
    .lg-btn{border:0;border-radius:14px;padding:9px 16px;font-weight:800;font-size:12.5px;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px}
    .lg-btn.p{background:linear-gradient(120deg,#0f766e,#4338ca);color:#fff}.lg-btn.s{background:#e2e8f0;color:#334155}.lg-btn.d{background:#fee2e2;color:#b91c1c}.lg-btn.g{background:#059669;color:#fff}
    .lg-btn.sm{padding:5px 10px;font-size:11px;border-radius:10px}.lg-btn:disabled{opacity:.5;cursor:not-allowed}
    .lg-kpi{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}@media(max-width:820px){.lg-kpi{grid-template-columns:repeat(2,minmax(0,1fr))}}
    .lg-kpi div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:8px;text-align:center}.lg-kpi small{display:block;font-size:10.5px;color:#64748b}.lg-kpi b{font-size:15px;color:#0f172a}
    .lg-opts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}@media(max-width:820px){.lg-opts{grid-template-columns:1fr}}
    .lg-opt{border:1px solid #e2e8f0;border-radius:16px;padding:10px 12px;font-size:12px}.lg-opt b{display:block;font-size:12px;margin-bottom:6px;color:#334155}
    .lg-opt label{display:flex;gap:6px;align-items:flex-start;margin:4px 0;cursor:pointer;line-height:1.8}.lg-opt small{color:#64748b;font-size:10.5px}
    .lg-in{border:1px solid #cbd5e1;border-radius:11px;padding:7px 9px;font-size:12px;font-family:inherit;width:100%;background:#fff}
    .lg-tb{width:100%;font-size:11.5px;border-collapse:collapse;white-space:nowrap}.lg-tb th{background:#f1f5f9;color:#475569;font-weight:800;padding:6px;text-align:right;position:sticky;top:0;z-index:1}
    .lg-tb td{border-top:1px solid #f1f5f9;padding:6px;vertical-align:middle}.lg-tb tr.skip td{opacity:.45}.lg-tb tr.dup td{background:#fffbeb}
    .lg-scroll{max-height:560px;overflow:auto;border:1px solid #e2e8f0;border-radius:14px}
    .lg-pill{display:inline-flex;align-items:center;gap:3px;border-radius:999px;padding:1px 8px;font-size:10px;font-weight:800;border:1px solid}
    .lg-pill.c{background:#eef2ff;color:#4338ca;border-color:#c7d2fe}.lg-pill.p{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.lg-pill.w{background:#fffbeb;color:#92400e;border-color:#fde68a}
    .lg-pill.r{background:#fff1f2;color:#be123c;border-color:#fecdd3}.lg-pill.n{background:#f8fafc;color:#475569;border-color:#e2e8f0}
    .lg-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:8px 10px;font-size:11.5px;line-height:1.9}
    .lg-info{background:#f0f9ff;border:1px solid #bae6fd;color:#075985;border-radius:12px;padding:8px 10px;font-size:11.5px;line-height:1.9}
    .lg-bar{height:10px;background:#e2e8f0;border-radius:999px;overflow:hidden}.lg-bar i{display:block;height:100%;background:linear-gradient(90deg,#0f766e,#4338ca);transition:width .3s}
    .lg-grp{border:1px solid #e2e8f0;border-radius:16px;padding:10px 12px;font-size:11.5px}.lg-grp.ok{border-color:#a7f3d0;background:#f0fdf4}
    .lg-grp-h{display:flex;flex-wrap:wrap;align-items:center;gap:8px;justify-content:space-between}.lg-grp ul{margin:6px 0 0;padding:0 16px;color:#475569;font-size:11px;line-height:1.9}
    .lg-filters{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.lg-filters button{border:1px solid #e2e8f0;background:#fff;border-radius:999px;padding:4px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit}
    .lg-filters button.on{background:#0f766e;color:#fff;border-color:#0f766e}
    .lg-res{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:16px;padding:12px;font-size:12px;line-height:2}`;
    function css() { if (!document.getElementById('lg-css')) { const s = document.createElement('style'); s.id = 'lg-css'; s.textContent = CSS; document.head.appendChild(s); } }

    const S = {root: null, step: 'excel', pv: null, rowCh: {}, filter: 'all', q: '', scan: null, res: null, manual: {}, sel: {}};

    function init(root) {
        css();
        if (!root) return;
        S.root = root;
        if (!root.dataset.ready) {
            root.dataset.ready = '1';
            root.innerHTML = `
            <div class="lg-hero"><h2><i class="fas fa-boxes-packing ml-2"></i>ورودِ بایگانی‌های قبلی</h2>
                <p>بیمه‌نامه‌هایی که قبل از سایت صادر شده‌اند با همان نظمِ بایگانیِ سایت وارد می‌شوند: شرکتی‌ها هر «شرکت + تاریخِ صدور» یک نامه، کارکنان با شخص، معرفی‌نامه و سهمیه؛ تاریخ‌ها همه از تاریخِ صدورِ بیمه‌نامه. الحاقیه‌ها روی بیمه‌نامه می‌نشینند و حق بیمه‌ی نهایی حساب می‌شود. هر ورود یک «دسته» است و کاملاً برگشت‌پذیر.</p>
                <div class="lg-steps"><button data-st="excel" class="on"><i class="fas fa-file-excel ml-1"></i>۱. اکسل‌ها</button><button data-st="files"><i class="fas fa-folder-tree ml-1"></i>۲. پوشه‌ها و فایل‌ها</button><button data-st="batches"><i class="fas fa-clock-rotate-left ml-1"></i>۳. دسته‌های ثبت‌شده</button></div></div>
            <div id="lg-excel"></div><div id="lg-files" class="hidden"></div><div id="lg-batches" class="hidden"></div>`;
            root.querySelectorAll('.lg-steps button').forEach(b => b.onclick = () => go(b.dataset.st));
            renderExcelStart();
        }
        if (S.step === 'batches') loadBatches();
    }
    function go(st) {
        S.step = st;
        S.root.querySelectorAll('.lg-steps button').forEach(b => b.classList.toggle('on', b.dataset.st === st));
        ['excel', 'files', 'batches'].forEach(k => S.root.querySelector('#lg-' + k).classList.toggle('hidden', k !== st));
        if (st === 'files' && !S.root.querySelector('#lg-files').dataset.ready) renderFilesStart();
        if (st === 'batches') loadBatches();
    }

    // ------------------------------------------------------------------
    //  ۱) اکسل‌ها
    // ------------------------------------------------------------------
    function renderExcelStart() {
        const box = S.root.querySelector('#lg-excel');
        box.innerHTML = `<div class="lg-card"><h3><i class="fas fa-file-excel text-emerald-600"></i>اکسل‌های صدور و الحاقیه <small>یک یا چند فایل؛ نوعِ هر فایل خودکار تشخیص داده می‌شود</small></h3>
            <div class="lg-drop" id="lg-drop"><i class="fas fa-cloud-arrow-up text-2xl text-teal-600"></i><br><b>اکسل‌ها را اینجا بکشید یا کلیک کنید</b><br>
                گزارشِ صدورِ بدنه، گزارشِ صدورِ ثالث (همان خروجیِ پاسارگاد) و گزارشِ الحاقیه‌ها — همه با هم<input type="file" id="lg-file" accept=".xlsx,.xls,.csv" multiple class="hidden"></div>
            <div class="lg-info"><i class="fas fa-circle-info ml-1"></i>قبل از ثبت هیچ چیزی در سایت عوض نمی‌شود. بیمه‌نامه‌هایی که قبلاً در سایت (یا بایگانیِ وارداتی) هستند خودکار کنار گذاشته می‌شوند. بیمه‌گذارِ «حقیقی» که کد ملیِ پرداخت‌کننده دارد «کارکنان» و بقیه «شرکتی» پیشنهاد می‌شوند؛ هر ردیف را می‌توانید عوض کنید.</div>
            </div><div id="lg-pv" class="space-y-4 mt-4"></div>`;
        const drop = box.querySelector('#lg-drop'), inp = box.querySelector('#lg-file');
        drop.onclick = () => inp.click();
        drop.ondragover = e => { e.preventDefault(); drop.classList.add('on'); };
        drop.ondragleave = () => drop.classList.remove('on');
        drop.ondrop = e => { e.preventDefault(); drop.classList.remove('on'); upload(e.dataTransfer.files); };
        inp.onchange = () => upload(inp.files);
    }
    async function upload(files) {
        if (!files || !files.length) return;
        const fd = new FormData();
        fd.append('action', 'preview');
        [...files].forEach(f => fd.append('files[]', f));
        const pv = S.root.querySelector('#lg-pv');
        pv.innerHTML = '<div class="lg-card text-center text-slate-500 text-sm"><i class="fas fa-spinner fa-spin text-2xl text-teal-600"></i><br>در حالِ خواندنِ اکسل‌ها و مقایسه با سایت...</div>';
        const r = await post(fd, true);
        if (!r.ok) { pv.innerHTML = `<div class="lg-warn">${esc(r.error || 'خطا')}</div>`; return; }
        S.pv = r; S.rowCh = {}; S.filter = 'all'; S.q = '';
        renderPreview();
    }
    function companyOptions(sel, withNone) {
        const list = S.pv.company_list || [];
        return `<option value="">${'خودکار (پیشنهادی یا ساختِ شرکتِ تازه)'}</option><option value="new"${sel === 'new' ? ' selected' : ''}>+ ساختِ شرکتِ تازه با همین نام</option>`
            + (withNone ? `<option value="none"${sel === 'none' ? ' selected' : ''}>بدونِ طرفِ قرارداد</option>` : '')
            + list.map(c => `<option value="${c.id}"${String(sel) === String(c.id) ? ' selected' : ''}>${esc(c.name)}</option>`).join('');
    }
    function rowTarget(r) { return (S.rowCh[r.i] && S.rowCh[r.i].target) || r.target; }
    function rowSkip(r) { return !!(S.rowCh[r.i] && S.rowCh[r.i].skip); }
    function renderPreview() {
        const r = S.pv, s = r.summary || {};
        const pv = S.root.querySelector('#lg-pv');
        const files = (r.files || []).map(f => `<span class="lg-pill ${f.kind === 'endorse' ? 'w' : 'c'}"><i class="fas fa-file-excel"></i>${esc(f.name)} · ${esc(f.kind_fa || '')} · ${fa(f.rows)} ردیف</span>`).join(' ');
        const errs = (r.errors || []).map(e => `<div class="lg-warn">${esc(e)}</div>`).join('');
        const orph = (r.orphans || []);
        const orphSite = orph.filter(o => o.site).length;
        pv.innerHTML = `
        <div class="lg-card"><h3><i class="fas fa-magnifying-glass-chart text-indigo-600"></i>پیش‌نمایش</h3><div class="flex flex-wrap gap-2">${files}</div>${errs}
            <div class="lg-kpi"><div><small>بیمه‌نامه</small><b>${fa(s.policies || 0)}</b></div><div><small>تازه</small><b class="text-emerald-700">${fa(s.new || 0)}</b></div>
                <div><small>قبلاً در سایت</small><b class="text-amber-700">${fa(s.exists || 0)}</b></div><div><small>شرکتی / کارکنان</small><b>${fa(s.company || 0)} / ${fa(s.personnel || 0)}</b></div>
                <div><small>الحاقیه</small><b>${fa(s.endorsements || 0)}</b></div><div><small>الحاقیه‌ی بیمه‌نامه‌های بیرون از این اکسل</small><b>${fa(orph.length)}</b></div></div></div>
        <div class="lg-card"><h3><i class="fas fa-sliders text-teal-600"></i>تنظیماتِ ثبت</h3>
            <div class="lg-opts">
                <div class="lg-opt"><b>مالی</b>
                    <label><input type="radio" name="lg-fin" value="PREMIUM" checked><span>ب) فقط حق بیمه (پیش‌فرض)<br><small>قسط ساخته نمی‌شود؛ حق بیمه و حق بیمه‌ی نهایی در صادره‌ها و گزارش‌ها هست.</small></span></label>
                    <label><input type="radio" name="lg-fin" value="INSTALLMENTS"><span>الف) ساختِ اقساط طبقِ قراردادِ هر شرکت<br><small>برای بیمه‌نامه‌هایی که هنوز اقساطِ باز دارند.</small></span></label></div>
                <div class="lg-opt"><b>سهمیه‌ی معرفی‌نامه‌ی کارکنان</b>
                    <input type="number" min="1" id="lg-quota" class="lg-in" value="${fa(r.default_quota || 4).replace(/[۰-۹]/g, d => FA.indexOf(d))}">
                    <small class="block mt-2">برای هر نفر یک معرفی‌نامه با تاریخِ اولین صدور ساخته می‌شود؛ سهمیه = بیشترِ این عدد و تعدادِ بیمه‌نامه‌هایش، و همه‌شان «مصرف‌شده» حساب می‌شوند.</small></div>
                <div class="lg-opt"><b>نامِ این دسته</b><input id="lg-title" class="lg-in" placeholder="مثلاً: بایگانیِ ۱۴۰۴ — ثالث و بدنه">
                    <small class="block mt-2">در «دسته‌های ثبت‌شده» با این نام دیده می‌شود و از همان‌جا قابلِ برگرداندن است.</small></div>
            </div></div>
        ${(r.companies || []).length ? `<div class="lg-card"><h3><i class="fas fa-building text-indigo-600"></i>شرکت‌های بیمه‌گذار <small>ردیف‌های شرکتی؛ هر شرکت + تاریخِ صدور = یک نامه</small></h3>
            <div class="lg-scroll" style="max-height:340px"><table class="lg-tb"><thead><tr><th>نام در اکسل</th><th>شناسه</th><th>تعداد</th><th>شرکتِ سایت</th></tr></thead><tbody>
            ${r.companies.map(c => `<tr><td class="font-bold">${esc(c.name)}</td><td>${esc(fa(c.nid || '—'))}</td><td>${fa(c.count)}</td><td style="min-width:260px"><select class="lg-in" data-comp="${esc(c.key)}">${companyOptions(c.suggest ? String(c.suggest) : '')}</select>${c.suggest ? '<small class="text-emerald-700">پیدا شد</small>' : '<small class="text-slate-500">شرکتِ تازه ساخته می‌شود</small>'}</td></tr>`).join('')}
            </tbody></table></div></div>` : ''}
        ${(r.employers || []).length ? `<div class="lg-card"><h3><i class="fas fa-user-tie text-emerald-600"></i>طرفِ قراردادِ کارکنان <small>شرکتی که شخص در آن کار می‌کند (پوشه‌ی کسر از حقوق)</small></h3>
            <div class="lg-scroll" style="max-height:300px"><table class="lg-tb"><thead><tr><th>طرفِ قرارداد در اکسل</th><th>تعداد</th><th>شرکتِ سایت</th></tr></thead><tbody>
            ${r.employers.map(c => `<tr><td class="font-bold">${esc(c.name)}</td><td>${fa(c.count)}</td><td style="min-width:260px"><select class="lg-in" data-emp="${esc(c.key)}">${companyOptions(c.suggest ? String(c.suggest) : '', true)}</select></td></tr>`).join('')}
            </tbody></table></div></div>` : ''}
        <div class="lg-card"><h3><i class="fas fa-table-list text-slate-600"></i>ردیف‌ها</h3>
            <div class="lg-filters" id="lg-flt">
                ${[['all', 'همه'], ['C', 'شرکتی'], ['P', 'کارکنان'], ['dup', 'قبلاً در سایت'], ['endo', 'الحاقیه‌دار'], ['bad', 'ایراددار'], ['skip', 'کنارگذاشته']].map(([k, l]) => `<button data-f="${k}" class="${S.filter === k ? 'on' : ''}">${l}</button>`).join('')}
                <input id="lg-q" class="lg-in" style="max-width:260px" placeholder="جستجو: بیمه‌نامه، نام، پلاک، شاسی، کد ملی" value="${esc(S.q)}">
                <button data-bulk="C" class="mr-auto">همه‌ی نمایش‌داده‌ها ← شرکتی</button><button data-bulk="P">← کارکنان</button>
            </div>
            <div class="lg-scroll"><table class="lg-tb"><thead><tr><th></th><th>ردیف</th><th>ثبت به‌عنوانِ</th><th>نوع</th><th>بیمه‌نامه</th><th>صدور</th><th>بیمه‌گذار</th><th>دارنده (کارکنان)</th><th>طرفِ قرارداد</th><th>پلاک / شاسی</th><th>حق بیمه</th><th>نهایی</th><th>وضعیت</th></tr></thead><tbody id="lg-rows"></tbody></table></div>
            <div class="text-[11px] text-slate-500" id="lg-rcount"></div></div>
        ${orph.length ? `<div class="lg-card"><h3><i class="fas fa-file-pen text-amber-600"></i>الحاقیه‌های بیمه‌نامه‌هایی که در این اکسل‌ها نیستند <small>${fa(orphSite)} مورد روی بیمه‌نامه‌ی موجود در سایت می‌نشیند؛ بقیه «خارج از سایت» ثبت می‌شوند تا بعداً با ورودِ بیمه‌نامه‌شان وصل شوند</small></h3>
            <div class="lg-scroll" style="max-height:320px"><table class="lg-tb"><thead><tr><th>بیمه‌نامه</th><th>الحاقیه</th><th>نوع</th><th>صدور</th><th>مبلغ</th><th>در سایت</th></tr></thead><tbody>
            ${orph.map(o => `<tr><td>${esc(fa(o.policy))}</td><td>${fa(o.endorse_no)}</td><td>${esc(o.kind_raw || o.kind)}</td><td>${esc(fa(o.issue_date))}</td><td>${money(o.gross)}</td><td>${o.site ? `<span class="lg-pill p">${esc(o.site.label || 'پیدا شد')}</span>` : '<span class="lg-pill n">خارج از سایت</span>'}</td></tr>`).join('')}
            </tbody></table></div></div>` : ''}
        <div class="lg-card" style="position:sticky;bottom:8px;z-index:5;box-shadow:0 10px 30px -12px rgba(15,23,42,.35)"><div class="flex flex-wrap items-center justify-between gap-2">
            <div class="text-[12px] text-slate-600" id="lg-sum"></div>
            <div class="flex gap-2"><button class="lg-btn s" id="lg-cancel"><i class="fas fa-xmark"></i>انصراف</button><button class="lg-btn p" id="lg-commit"><i class="fas fa-check-double"></i>ثبتِ همه در سایت</button></div></div></div>`;
        pv.querySelectorAll('#lg-flt [data-f]').forEach(b => b.onclick = () => { S.filter = b.dataset.f; pv.querySelectorAll('#lg-flt [data-f]').forEach(x => x.classList.toggle('on', x === b)); renderRows(); });
        let t = null;
        pv.querySelector('#lg-q').oninput = e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); renderRows(); }, 250); };
        pv.querySelectorAll('#lg-flt [data-bulk]').forEach(b => b.onclick = () => {
            visibleRows().forEach(x => { if (!x.dup && x.ok) S.rowCh[x.i] = Object.assign({}, S.rowCh[x.i], {target: b.dataset.bulk}); });
            renderRows();
        });
        pv.querySelector('#lg-cancel').onclick = () => { S.pv = null; pv.innerHTML = ''; };
        pv.querySelector('#lg-commit').onclick = commit;
        renderRows();
    }
    function visibleRows() {
        const q = S.q.replace(/[۰-۹]/g, d => FA.indexOf(d)).toLowerCase();
        return (S.pv.rows || []).filter(r => {
            const tg = rowTarget(r), sk = rowSkip(r);
            if (S.filter === 'C' && (tg !== 'C' || r.dup || sk)) return false;
            if (S.filter === 'P' && (tg !== 'P' || r.dup || sk)) return false;
            if (S.filter === 'dup' && !r.dup) return false;
            if (S.filter === 'endo' && !r.endo_count) return false;
            if (S.filter === 'bad' && r.ok && !(r.warnings || []).length) return false;
            if (S.filter === 'skip' && !sk) return false;
            if (q) {
                const hay = [r.policy, r.insured, r.holder, r.holder_nid, r.plate_text, r.vin, r.contract].join(' ').toLowerCase();
                if (hay.indexOf(q) < 0) return false;
            }
            return true;
        });
    }
    function renderRows() {
        const pv = S.root.querySelector('#lg-pv');
        const rows = visibleRows();
        const shown = rows.slice(0, 400);
        pv.querySelector('#lg-rows').innerHTML = shown.map(r => {
            const tg = rowTarget(r), sk = rowSkip(r);
            const st = r.dup ? `<span class="lg-pill w">${esc(r.dup_fa || 'تکراری')}</span>` : !r.ok ? `<span class="lg-pill r" title="${esc((r.errors || []).join('، '))}">${esc((r.errors || [])[0] || 'ایراد')}</span>`
                : (r.warnings || []).length ? `<span class="lg-pill w" title="${esc(r.warnings.join('، '))}">${esc(r.warnings[0])}</span>` : '<span class="lg-pill p">آماده</span>';
            const canP = String(r.holder_nid || '').length === 10;
            const sel = r.dup || !r.ok ? '—' : `<select class="lg-in" style="width:auto;padding:4px 6px" data-tg="${r.i}"><option value="C"${tg === 'C' ? ' selected' : ''}>شرکتی</option><option value="P"${tg === 'P' ? ' selected' : ''}${canP ? '' : ' disabled'}>کارکنان${canP ? '' : ' (کد ملی ندارد)'}</option></select>`;
            const endo = r.endo_count ? `<span class="lg-pill ${r.cancelled ? 'r' : 'w'}">${r.cancelled ? 'فسخ' : fa(r.endo_count) + ' الحاقیه'}</span>` : '';
            return `<tr class="${sk ? 'skip' : ''} ${r.dup ? 'dup' : ''}">
                <td>${r.dup || !r.ok ? '' : `<input type="checkbox" data-sk="${r.i}" ${sk ? '' : 'checked'} title="ثبت شود">`}</td><td>${fa(r.line)}</td><td>${sel}</td>
                <td>${esc(r.type_fa || '')}${r.zero_km ? ' <span class="lg-pill n">صفر</span>' : ''}</td><td>${esc(fa(r.policy))}</td><td>${esc(fa(r.issue))}</td>
                <td>${esc(r.insured)}<div class="text-[10px] text-slate-400">${esc(r.insured_kind || '')}${r.insured_id ? ' · ' + esc(fa(r.insured_id)) : ''}</div></td>
                <td>${tg === 'P' ? esc(r.holder) + `<div class="text-[10px] text-slate-400">${esc(fa(r.holder_nid))} · ${esc(r.relation || '')}</div>` : '<span class="text-slate-300">—</span>'}</td>
                <td>${esc(r.contract || '')}</td><td>${r.plate_text ? plate(r.plate_text) : ''}<div class="text-[10px] text-slate-400 font-mono">${esc(r.vin || '')}</div></td>
                <td>${money(r.premium)}</td><td>${r.endo_count ? `<b>${money(r.final_premium)}</b> ${endo}` : '<span class="text-slate-300">—</span>'}</td><td>${st}</td></tr>`;
        }).join('') || '<tr><td colspan="13" class="text-center text-slate-400 p-4">ردیفی نیست</td></tr>';
        pv.querySelector('#lg-rcount').textContent = fa(rows.length) + ' ردیف' + (rows.length > shown.length ? ' (' + fa(shown.length) + ' تای اول نمایش داده شده؛ با جستجو/فیلتر محدود کنید)' : '');
        pv.querySelectorAll('[data-tg]').forEach(s => s.onchange = () => { S.rowCh[s.dataset.tg] = Object.assign({}, S.rowCh[s.dataset.tg], {target: s.value}); renderRows(); });
        pv.querySelectorAll('[data-sk]').forEach(c => c.onchange = () => { S.rowCh[c.dataset.sk] = Object.assign({}, S.rowCh[c.dataset.sk], {skip: !c.checked}); renderRows(); });
        summary();
    }
    function summary() {
        let c = 0, p = 0;
        (S.pv.rows || []).forEach(r => { if (r.dup || !r.ok || rowSkip(r)) return; rowTarget(r) === 'P' ? p++ : c++; });
        const el = S.root.querySelector('#lg-sum');
        if (el) el.innerHTML = `ثبت می‌شود: <b>${fa(c)}</b> شرکتی · <b>${fa(p)}</b> کارکنان · <b>${fa((S.pv.endorsements || []).length)}</b> الحاقیه`;
    }
    async function commit() {
        const pv = S.root.querySelector('#lg-pv');
        const companies = {}, employers = {};
        pv.querySelectorAll('[data-comp]').forEach(s => companies[s.dataset.comp] = s.value);
        pv.querySelectorAll('[data-emp]').forEach(s => employers[s.dataset.emp] = s.value);
        const fin = (pv.querySelector('input[name=lg-fin]:checked') || {}).value || 'PREMIUM';
        const quota = parseInt(String(pv.querySelector('#lg-quota').value).replace(/[۰-۹]/g, d => FA.indexOf(d)), 10) || 0;
        if (!await ask('همه‌ی ردیف‌های انتخاب‌شده به‌عنوانِ «صادرشده» در سایت ثبت شوند؟ (از «دسته‌های ثبت‌شده» قابلِ برگرداندن است)')) return;
        const btn = pv.querySelector('#lg-commit');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ ثبت (ساختِ پوشه‌ها، معرفی‌نامه‌ها، الحاقیه‌ها)...';
        const r = await post({action: 'commit', token: S.pv.token, choice: {rows: S.rowCh, companies, employers, finance: fin, quota, title: pv.querySelector('#lg-title').value.trim()}});
        if (!r.ok) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-double"></i>ثبتِ همه در سایت'; toast(r.error || 'خطا', 'error'); return; }
        S.pv = null;
        pv.innerHTML = `<div class="lg-res"><b class="text-emerald-800"><i class="fas fa-circle-check ml-1"></i>دسته‌ی ${fa(r.batch_id)} ثبت شد.</b><br>
            شرکتی: ${fa(r.company)} ردیف در ${fa(r.requests)} نامه${r.companies ? ' · ' + fa(r.companies) + ' شرکتِ تازه' : ''}<br>
            کارکنان: ${fa(r.personnel)} بیمه‌نامه · ${fa(r.persons)} شخصِ تازه · ${fa(r.intros)} معرفی‌نامه<br>
            الحاقیه: ${fa(r.endorsements)} ثبت${r.endo_skipped ? ' · ' + fa(r.endo_skipped) + ' تکراری/ردشده' : ''}
            ${(r.errors || []).length ? `<div class="lg-warn mt-2">${r.errors.map(esc).join('<br>')}</div>` : ''}
            <div class="mt-2 flex gap-2 flex-wrap"><button class="lg-btn p sm" id="lg-to-files"><i class="fas fa-folder-tree"></i>رفتن به پوشه‌ها و ادغامِ فایل‌ها</button><button class="lg-btn s sm" id="lg-to-b">دسته‌ها</button></div></div>`;
        pv.querySelector('#lg-to-files').onclick = () => go('files');
        pv.querySelector('#lg-to-b').onclick = () => go('batches');
        toast('ورودِ بایگانی ثبت شد.', 'success');
    }

    // ------------------------------------------------------------------
    //  ۲) پوشه‌ها و فایل‌ها
    // ------------------------------------------------------------------
    async function renderFilesStart() {
        const box = S.root.querySelector('#lg-files');
        box.dataset.ready = '1';
        box.innerHTML = `<div class="lg-card"><h3><i class="fas fa-folder-tree text-teal-600"></i>پوشه‌های بایگانیِ قبلی</h3>
            <div class="lg-info" id="lg-stinfo">...</div>
            <div class="flex flex-wrap gap-2 items-center">
                <select id="lg-sub" class="lg-in" style="max-width:320px"><option value="">همه‌ی پوشه‌ها</option></select>
                <button class="lg-btn p" id="lg-scan"><i class="fas fa-magnifying-glass"></i>اسکن</button>
                <button class="lg-btn s" id="lg-refresh"><i class="fas fa-rotate"></i>تازه‌سازی</button>
                <label class="lg-btn s" style="cursor:pointer"><i class="fas fa-file-zipper"></i>آپلودِ ZIP<input type="file" id="lg-zip" accept=".zip" class="hidden"></label>
            </div>
            <div id="lg-prog" class="hidden"><div class="lg-bar"><i style="width:0"></i></div><div class="text-[11px] text-slate-500 mt-1" id="lg-prog-t"></div></div>
            </div><div id="lg-scanres" class="space-y-3 mt-4"></div>`;
        box.querySelector('#lg-refresh').onclick = staging;
        box.querySelector('#lg-scan').onclick = scan;
        box.querySelector('#lg-zip').onchange = async e => {
            const f = e.target.files[0]; if (!f) return;
            const fd = new FormData(); fd.append('action', 'upload_zip'); fd.append('file', f);
            toast('در حالِ آپلود و باز کردنِ ZIP...');
            const r = await post(fd, true);
            e.target.value = '';
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            toast(fa(r.files) + ' فایل در «' + r.folder + '» گذاشته شد.', 'success');
            staging();
        };
        staging();
    }
    async function staging() {
        const box = S.root.querySelector('#lg-files');
        const r = await post({action: 'staging'});
        if (!r.ok) { box.querySelector('#lg-stinfo').innerHTML = esc(r.error || 'خطا'); return; }
        box.querySelector('#lg-stinfo').innerHTML = `<i class="fas fa-circle-info ml-1"></i>پوشه‌ها را (هر قدر حجیم) با File Manager هاست یا FTP در این مسیر بگذارید: <b dir="rtl">${esc(r.root)}</b> — مرتب و نامرتب فرقی ندارد. برای حجمِ کم‌تر می‌توانید ZIP هم آپلود کنید.<br>
            اسکن از نامِ پوشه/فایل (شماره بیمه‌نامه، شاسی، پلاک) و اگر لازم شد از متنِ PDF، صاحبِ هر فایل را پیدا می‌کند. پوشه‌ای که نامش شماره‌ی بیمه‌نامه دارد (مثلِ بایگانیِ خودِ سایت) «کامل» است و با یک دکمه ادغام می‌شود.
            <div class="mt-1">${(r.items || []).length ? r.items.map(i => `<span class="lg-pill n ml-1"><i class="fas ${i.is_dir ? 'fa-folder' : 'fa-file'}"></i>${esc(i.name)}</span>`).join('') : '<b>پوشه خالی است.</b>'}</div>`;
        const sub = box.querySelector('#lg-sub');
        sub.innerHTML = '<option value="">همه‌ی پوشه‌ها</option>' + (r.items || []).filter(i => i.is_dir).map(i => `<option value="${esc(i.name)}">${esc(i.name)}</option>`).join('');
    }
    async function scan() {
        const box = S.root.querySelector('#lg-files');
        const btn = box.querySelector('#lg-scan');
        const prog = box.querySelector('#lg-prog'), bar = prog.querySelector('i'), pt = box.querySelector('#lg-prog-t');
        btn.disabled = true; prog.classList.remove('hidden'); bar.style.width = '0'; pt.textContent = 'فهرست کردنِ فایل‌ها...';
        let r = await post({action: 'scan', sub: box.querySelector('#lg-sub').value});
        if (!r.ok) { btn.disabled = false; prog.classList.add('hidden'); return toast(r.error || 'خطا', 'error'); }
        S.scan = r.scan; S.manual = {}; S.sel = {};
        const total = r.total;
        let off = 0;
        while (off < total) {
            pt.textContent = `اسکنِ ${fa(Math.min(off + 150, total))} از ${fa(total)} فایل...`;
            r = await post({action: 'scan', scan: S.scan, offset: off, limit: 150});
            if (!r.ok) { toast(r.error || 'خطا در اسکن', 'error'); break; }
            off = r.done;
            bar.style.width = Math.round(100 * off / Math.max(1, total)) + '%';
            if (r.finished) break;
        }
        pt.textContent = `اسکن تمام شد: ${fa(total)} فایل.`;
        btn.disabled = false;
        loadResult();
    }
    async function loadResult() {
        const out = S.root.querySelector('#lg-scanres');
        const r = await post({action: 'scan_result', scan: S.scan});
        if (!r.ok) { out.innerHTML = `<div class="lg-warn">${esc(r.error || 'خطا')}</div>`; return; }
        S.res = r;
        const groups = r.groups || [];
        const comp = groups.filter(g => g.complete), other = groups.filter(g => !g.complete);
        const grp = g => `<div class="lg-grp ${g.complete ? 'ok' : ''}"><div class="lg-grp-h">
                <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" data-g="${esc(g.key)}" ${S.sel[g.key] ? 'checked' : ''}>
                <b>${esc(fa(g.label))}</b> <span class="text-slate-500">${esc(fa(g.policy || ''))}</span></label>
                <span class="flex gap-1 items-center"><span class="lg-pill ${g.complete ? 'p' : 'n'}">${g.complete ? 'کامل' : 'از ' + esc(g.by_fa)}</span><span class="lg-pill n">${fa(g.files.length)} فایل · ${size(g.size)}</span>
                <button class="lg-btn g sm" data-mg="${esc(g.key)}"><i class="fas fa-code-merge"></i>ادغام</button></span></div>
                <div class="text-[10.5px] text-slate-400 mt-1" dir="rtl"><i class="fas fa-folder ml-1"></i>${esc(g.folder)}</div>
                <ul>${g.files.slice(0, 12).map(f => `<li>${esc(f.rel.split('/').pop())} <span class="lg-pill n">${esc(f.type_fa)}</span></li>`).join('')}${g.files.length > 12 ? `<li>و ${fa(g.files.length - 12)} فایلِ دیگر</li>` : ''}</ul></div>`;
        const typeOpts = sel => ['policy', 'endorsement', 'car_card_front', 'car_card_back', 'ownership_doc', 'sales_invoice', 'prev_body_policy', 'prev_third_policy', 'health_report', 'health_inspection', 'receipt', 'other']
            .map(t => `<option value="${t}"${sel === t ? ' selected' : ''}>${({policy: 'بیمه‌نامه', endorsement: 'الحاقیه', car_card_front: 'کارت ماشین رو', car_card_back: 'کارت ماشین پشت', ownership_doc: 'سند', sales_invoice: 'فاکتور فروش', prev_body_policy: 'بیمه بدنه قبل', prev_third_policy: 'بیمه ثالث قبل', health_report: 'گزارش بازدید', health_inspection: 'عکس بازدید', receipt: 'فیش/اعلامیه اقساط', other: 'سایر مدارک'})[t]}</option>`).join('');
        const um = r.unmatched || [];
        out.innerHTML = `
            <div class="lg-card"><div class="lg-kpi"><div><small>فایل‌ها</small><b>${fa(r.total)}</b></div><div><small>ادغام‌شده</small><b class="text-emerald-700">${fa(r.merged)}</b></div>
                <div><small>گروهِ کامل</small><b class="text-emerald-700">${fa(comp.length)}</b></div><div><small>گروهِ دیگر</small><b>${fa(other.length)}</b></div>
                <div><small>فایلِ بی‌صاحب</small><b class="text-amber-700">${fa(r.unmatched_count)}</b></div><div><small>اسکن‌شده</small><b>${fa(r.done)}</b></div></div>
                <div class="flex flex-wrap gap-2"><button class="lg-btn g" id="lg-all" ${comp.length ? '' : 'disabled'}><i class="fas fa-wand-magic-sparkles"></i>ادغامِ همه‌ی موارد کامل (${fa(comp.length)})</button>
                <button class="lg-btn p" id="lg-selm"><i class="fas fa-code-merge"></i>ادغامِ انتخاب‌شده‌ها</button></div>
                <div class="lg-info"><i class="fas fa-circle-info ml-1"></i>ادغام فایل‌ها را <b>کپی</b> می‌کند (اصلشان در پوشه‌ی ورود می‌ماند) به پوشه‌ی همان بیمه‌نامه در بایگانیِ شرکتی/کسر از حقوق و «بایگانی صادره»، با نام‌گذاریِ سایت؛ مدارک در چک‌لیستِ ردیف ثبت می‌شوند. ادغامِ دوباره فایلِ تکراری نمی‌سازد.</div></div>
            ${comp.length ? `<div class="lg-card"><h3><i class="fas fa-circle-check text-emerald-600"></i>کامل <small>پوشه‌ی هر بیمه‌نامه با شماره‌اش در نام — مثلِ بایگانیِ سایت</small></h3>${comp.map(grp).join('')}</div>` : ''}
            ${other.length ? `<div class="lg-card"><h3><i class="fas fa-link text-indigo-600"></i>پیداشده با شاسی/پلاک/متن <small>قبل از ادغام نگاهی بیندازید</small></h3>${other.map(grp).join('')}</div>` : ''}
            ${um.length ? `<div class="lg-card"><h3><i class="fas fa-circle-question text-amber-600"></i>فایل‌های بی‌صاحب <small>بیمه‌نامه را جستجو کنید و نوع را تعیین کنید؛ بعد «ادغامِ دستی»</small></h3>
                <div class="lg-scroll" style="max-height:420px"><table class="lg-tb"><thead><tr><th>فایل</th><th>نوع</th><th>بیمه‌نامه</th></tr></thead><tbody>
                ${um.slice(0, 500).map(f => `<tr><td dir="rtl" style="white-space:normal;max-width:420px">${esc(f.rel)} <span class="text-[10px] text-slate-400">${size(f.size)}</span></td>
                    <td><select class="lg-in" style="width:auto" data-mt="${f.i}">${typeOpts(f.type)}</select></td>
                    <td style="min-width:280px"><input class="lg-in" data-mq="${f.i}" placeholder="شماره بیمه‌نامه، پلاک یا نام..." value="${esc((S.manual[f.i] || {}).label || '')}"><div data-ml="${f.i}"></div></td></tr>`).join('')}
                </tbody></table></div>
                ${um.length > 500 ? `<div class="text-[11px] text-slate-500">${fa(um.length - 500)} فایلِ دیگر هم هست؛ بعد از ادغامِ این‌ها نمایش داده می‌شوند.</div>` : ''}
                <div><button class="lg-btn p" id="lg-man"><i class="fas fa-hand-pointer"></i>ادغامِ دستی</button></div></div>` : ''}`;
        out.querySelectorAll('[data-g]').forEach(c => c.onchange = () => { S.sel[c.dataset.g] = c.checked; });
        out.querySelectorAll('[data-mg]').forEach(b => b.onclick = () => merge({groups: [b.dataset.mg]}));
        const all = out.querySelector('#lg-all'); if (all) all.onclick = async () => { if (await ask('همه‌ی ' + fa(comp.length) + ' گروهِ کامل ادغام شوند؟')) merge({all_complete: 1}); };
        out.querySelector('#lg-selm').onclick = () => { const g = Object.keys(S.sel).filter(k => S.sel[k]); if (!g.length) return toast('گروهی انتخاب نشده.', 'error'); merge({groups: g}); };
        out.querySelectorAll('[data-mt]').forEach(s => s.onchange = () => { S.manual[s.dataset.mt] = Object.assign({}, S.manual[s.dataset.mt], {type: s.value}); });
        out.querySelectorAll('[data-mq]').forEach(inp => {
            let t = null;
            inp.oninput = () => {
                clearTimeout(t);
                const i = inp.dataset.mq, list = out.querySelector(`[data-ml="${i}"]`);
                if (S.manual[i]) delete S.manual[i].target;
                t = setTimeout(async () => {
                    const q = inp.value.trim(); if (q.length < 3) { list.innerHTML = ''; return; }
                    const r2 = await post({action: 'find_policy', q});
                    list.innerHTML = (r2.matches || []).slice(0, 8).map(m => `<button type="button" class="lg-pill c" style="margin:3px 0 0 3px;cursor:pointer" data-pick="${esc(m.target)}" data-lbl="${esc(m.label)}">${esc(fa(m.label))} · ${esc(fa(m.policy || ''))}</button>`).join('') || '<small class="text-slate-400">پیدا نشد</small>';
                    list.querySelectorAll('[data-pick]').forEach(b => b.onclick = () => {
                        const sel = out.querySelector(`[data-mt="${i}"]`);
                        S.manual[i] = {target: b.dataset.pick, type: sel ? sel.value : 'other', label: b.dataset.lbl};
                        inp.value = b.dataset.lbl; list.innerHTML = '<small class="text-emerald-700"><i class="fas fa-check ml-1"></i>انتخاب شد</small>';
                    });
                }, 300);
            };
        });
        const man = out.querySelector('#lg-man');
        if (man) man.onclick = () => {
            const m = {};
            Object.keys(S.manual).forEach(i => { if (S.manual[i].target) m[i] = {target: S.manual[i].target, type: S.manual[i].type || 'other'}; });
            if (!Object.keys(m).length) return toast('برای هیچ فایلی بیمه‌نامه انتخاب نشده.', 'error');
            merge({manual: m});
        };
    }
    async function merge(opts) {
        toast('در حالِ ادغام...');
        const r = await post(Object.assign({action: 'merge', scan: S.scan}, opts));
        if (!r.ok) return toast(r.error || 'خطا', 'error');
        toast(`${fa(r.files)} فایل به ${fa(r.targets)} بیمه‌نامه اضافه شد${r.skipped ? ' · ' + fa(r.skipped) + ' تکراری' : ''}${r.failed ? ' · ' + fa(r.failed) + ' ناموفق' : ''}.`, r.failed ? 'error' : 'success');
        S.sel = {}; S.manual = {};
        loadResult();
    }

    // ------------------------------------------------------------------
    //  ۳) دسته‌ها
    // ------------------------------------------------------------------
    async function loadBatches() {
        const box = S.root.querySelector('#lg-batches');
        box.innerHTML = '<div class="lg-card text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i></div>';
        const r = await post({action: 'batches'});
        if (!r.ok) { box.innerHTML = `<div class="lg-warn">${esc(r.error || 'خطا')}</div>`; return; }
        const b = r.batches || [];
        box.innerHTML = `<div class="lg-card"><h3><i class="fas fa-clock-rotate-left text-slate-600"></i>دسته‌های ثبت‌شده <small>برگرداندن همه‌ی ردیف‌ها، پرونده‌ها، معرفی‌نامه‌ها، اشخاص و شرکت‌های تازه، الحاقیه‌ها و پوشه‌های ساخته‌شده‌ی همان دسته را پاک می‌کند</small></h3>
            <div class="overflow-x-auto"><table class="lg-tb"><thead><tr><th>#</th><th>نام</th><th>تاریخ</th><th>ثبت‌کننده</th><th>فایل‌ها</th><th>شرکتی</th><th>کارکنان</th><th>الحاقیه</th><th>مالی</th><th>وضعیت</th><th></th></tr></thead><tbody>
            ${b.map(x => `<tr class="${x.rolled_back ? 'skip' : ''}"><td>${fa(x.id)}</td><td class="font-bold">${esc(x.title)}</td><td>${esc(fa(x.created_jalali))}</td><td>${esc(x.creator || '')}</td>
                <td style="white-space:normal;max-width:260px;font-size:10.5px">${(x.files || []).map(esc).join('، ')}</td><td>${fa(x.n_company)}</td><td>${fa(x.n_personnel)}</td><td>${fa(x.n_endo)}</td>
                <td>${(x.options || {}).finance === 'INSTALLMENTS' ? 'اقساط' : 'فقط حق بیمه'}</td><td>${x.rolled_back ? '<span class="lg-pill r">برگردانده شد</span>' : '<span class="lg-pill p">فعال</span>'}</td>
                <td>${x.rolled_back ? '' : `<button class="lg-btn d sm" data-rb="${x.id}"><i class="fas fa-rotate-left"></i>برگرداندن</button>`}</td></tr>`).join('') || '<tr><td colspan="11" class="text-center text-slate-400 p-4">هنوز دسته‌ای ثبت نشده</td></tr>'}
            </tbody></table></div></div>`;
        box.querySelectorAll('[data-rb]').forEach(btn => btn.onclick = async () => {
            if (!await ask('دسته‌ی ' + fa(btn.dataset.rb) + ' کاملاً برگردانده شود؟ همه‌ی ردیف‌ها، پرونده‌ها، معرفی‌نامه‌ها، الحاقیه‌ها و پوشه‌هایش پاک می‌شوند.')) return;
            btn.disabled = true;
            const x = await post({action: 'rollback', batch_id: Number(btn.dataset.rb)});
            if (!x.ok) { btn.disabled = false; return toast(x.error || 'خطا', 'error'); }
            toast(`برگردانده شد: ${fa(x.company)} شرکتی، ${fa(x.personnel)} کارکنان، ${fa(x.endorsements)} الحاقیه.`, 'success');
            loadBatches();
        });
    }

    window.LegacyImport = {init};
})();
