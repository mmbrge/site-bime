// فایل: finance-hub.js
// «مرکز اقساط و پرداخت» و «دفتر دریافت‌ها و پرداخت‌ها»، هم‌روش با برنامه‌ی مالیِ ویندوزی (ماموت):
//   هر قسط سه مرحله دارد: بدهکار به ما ← (دریافت از بیمه‌گذار) ← بدهکار به بیمه‌گر ← (پرداخت به بیمه‌گر) ← تسویه‌ی نهایی
//   روی هر ردیف یا چند ردیفِ انتخاب‌شده (کارکنان و شرکتی با هم) دریافت یا پرداخت ثبت می‌شود؛ مبلغِ هر ردیف قابلِ ویرایش است.
(function () {
    const API = 'api/finance_actions.php';
    const fa = v => (v === null || v === undefined || v === '') ? '' : String(v).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const num = v => fa(Number(v || 0).toLocaleString('en-US'));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : console.log(m));
    // دیالوگ‌های خودِ سایت (نه پنجره‌ی مرورگر)
    const ask = (title, msg, opts) => (window.uiConfirm ? uiConfirm(title, msg, opts || {}) : Promise.resolve(false));
    const info = (title, msg, type) => (window.showAlert ? showAlert(title, msg, type || 'info') : toast(title + ' — ' + msg, type));
    const plate = p => !p ? '<span class="text-slate-300">—</span>' : (/ایران/.test(p) && window.formatPlateHtml ? formatPlateHtml(p) : `<span dir="ltr" class="font-bold text-[11px]">${esc(p)}</span>`);
    const digits = v => String(v || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[^\d]/g, '');
    const todayJ = () => (window.IrTime && IrTime.jdate) ? IrTime.jdate() : '';
    const filePath = p => '/' + String(p || '').split('/').map(encodeURIComponent).join('/');
    const J_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const STAGE = {
        US:      ['بدهکار به ما', 'bg-rose-100 text-rose-700', 'fa-hand-holding-dollar'],
        INSURER: ['بدهکار به بیمه‌گر', 'bg-amber-100 text-amber-800', 'fa-building-columns'],
        SETTLED: ['تسویه‌ی نهایی', 'bg-emerald-100 text-emerald-700', 'fa-circle-check'],
    };
    const CHQ = {PENDING: ['در انتظار وصول', 'bg-amber-100 text-amber-800'], CLEARED: ['وصول شد', 'bg-emerald-100 text-emerald-700'], BOUNCED: ['برگشتی', 'bg-red-100 text-red-700']};
    let META = null;
    async function api(params, body) {
        try {
            const res = body ? await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})
                             : await fetch(API + '?' + new URLSearchParams(params).toString());
            return await res.json();
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }
    async function meta() {
        if (META) return META;
        const d = await api({action: 'ledger_meta'});
        if (d.ok) META = d;
        return META || {methods: {}, insurers: {}, companies: []};
    }
    function monthOpts() {
        const now = window.IrTime ? IrTime.parts() : {jy: 1405, jm: 1};
        let y = now.jy, m = now.jm, h = '<option value="">همه‌ی دوره‌ها</option>';
        for (let i = 0; i < 24; i++) { h += `<option value="${y}-${String(m).padStart(2, '0')}">دوره‌ی ${J_MONTHS[m - 1]} ${fa(y)}</option>`; if (--m < 1) { m = 12; y--; } }
        return h;
    }
    function modal(id, inner, wide) {
        let el = document.getElementById(id);
        if (!el) { el = document.createElement('div'); el.id = id; el.className = 'modal-overlay'; el.style.zIndex = '1000001'; }
        document.body.appendChild(el);   // آخرین پنجره همیشه رو (مثلاً ثبتِ دریافت روی پنجره‌ی ردیفِ قسط)
        el.innerHTML = `<div class="modal-content ${wide ? 'creq-wide-modal' : 'w-full max-w-3xl'} p-0 relative max-h-[92vh] overflow-y-auto">${inner}</div>`;
        el.classList.add('active');
        el.onclick = e => { if (e.target === el) el.classList.remove('active'); };
        return el;
    }
    const closeModal = id => { const el = document.getElementById(id); if (el) el.classList.remove('active'); };

    // ---------- کپی و پیش‌نمایشِ فیش ----------
    async function copyText(t) {
        t = String(t ?? '');
        try { await navigator.clipboard.writeText(t); }
        catch (e) { const ta = document.createElement('textarea'); ta.value = t; ta.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (x) {} ta.remove(); }
        toast('کپی شد.', 'success');
    }
    function previewFile(path, title) {
        if (!path) return;
        const url = filePath(path);
        const isImg = /\.(jpe?g|png|webp|gif)$/i.test(path);
        modal('fh-preview', `
            <div class="flex flex-wrap items-center gap-2 p-4 border-b border-slate-100">
                <b class="text-sm text-slate-800"><i class="fas fa-receipt ml-1 text-sky-600"></i>${esc(title || 'فیش')}</b>
                <span class="text-[10.5px] text-slate-400 truncate max-w-[50%]" dir="ltr">${esc(String(path).split('/').pop())}</span>
                <span class="mr-auto flex gap-2">
                    <a href="${url}" target="_blank" class="text-[11px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg px-3 py-1.5"><i class="fas fa-up-right-from-square ml-1"></i>برگه‌ی جدید</a>
                    <a href="${url}" download class="text-[11px] font-bold text-white rounded-lg px-3 py-1.5" style="background:#0284c7"><i class="fas fa-download ml-1"></i>دانلود</a>
                    <button type="button" onclick="document.getElementById('fh-preview').classList.remove('active')" class="text-slate-400 hover:text-slate-700 text-lg px-1"><i class="fas fa-times"></i></button>
                </span>
            </div>
            <div class="bg-slate-100 p-2" style="height:72vh">${isImg ? `<img src="${url}" alt="" class="max-w-full max-h-full mx-auto object-contain block">` : `<iframe src="${url}" class="w-full h-full rounded-lg bg-white" title="فیش"></iframe>`}</div>`, true);
    }
    // اطلاعاتِ فیشِ هر قسط (از فایلِ بیمه‌نامه): هر فیلد با دکمه‌ی کپی، «کپیِ همه» و «مشاهده‌ی فیش»
    const SLIP_F = [['fish', 'شماره فیش'], ['shenase', 'شناسه پرداخت'], ['amount', 'مبلغ (ریال)'], ['date', 'تاریخ'], ['account', 'شماره حساب'],
                    ['bank', 'بانک'], ['payer', 'پرداخت‌کننده'], ['payer_nid', 'کد ملیِ پرداخت‌کننده'], ['kind', 'نوع']];
    const slipText = rc => SLIP_F.filter(([k]) => rc[k] !== undefined && rc[k] !== null && rc[k] !== '').map(([k, l]) => `${l}: ${k === 'amount' ? Number(rc[k]).toLocaleString('en-US') : rc[k]}`).join('\n');
    function slipCardHtml(rc, title) {
        if (!rc) return '';
        const f = SLIP_F.filter(([k]) => rc[k] !== undefined && rc[k] !== null && rc[k] !== '');
        return `<div class="rounded-xl border-2 border-sky-200 bg-sky-50/70 p-3 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <b class="text-[12px] text-sky-900"><i class="fas fa-receipt ml-1"></i>${esc(title || 'فیش پرداختیِ این قسط')}</b>
                <span class="mr-auto flex flex-wrap gap-1.5">
                    ${f.length ? `<button type="button" class="text-[10.5px] font-bold bg-white hover:bg-sky-100 text-sky-800 border border-sky-200 rounded-lg px-2 py-1" data-copy="${esc(slipText(rc))}"><i class="fas fa-copy ml-1"></i>کپیِ همه</button>` : ''}
                    ${rc.file ? `<button type="button" class="text-[10.5px] font-bold text-white rounded-lg px-2 py-1" style="background:#0284c7" data-preview="${esc(rc.file)}" data-ptitle="${esc(title || 'فیش پرداختی')}"><i class="fas fa-eye ml-1"></i>مشاهده‌ی فیش</button>` : ''}
                </span>
            </div>
            ${f.length ? `<div class="grid grid-cols-2 md:grid-cols-3 gap-1.5">${f.map(([k, l]) => `<div class="bg-white border border-sky-100 rounded-lg px-2.5 py-1.5 flex items-center gap-2">
                <div class="min-w-0 flex-1"><p class="text-[9.5px] text-slate-400">${l}</p><p class="text-[11.5px] font-bold text-slate-700 truncate" ${/^(fish|shenase|account|payer_nid)$/.test(k) ? 'dir="ltr" style="text-align:right"' : ''}>${k === 'amount' ? num(rc[k]) : esc(fa(rc[k]))}</p></div>
                <button type="button" class="w-6 h-6 rounded-md bg-slate-50 hover:bg-sky-100 text-slate-400 hover:text-sky-700 text-[11px] flex-none" title="کپی" data-copy="${esc(String(rc[k]))}"><i class="fas fa-copy"></i></button></div>`).join('')}</div>`
                : '<p class="text-[11px] text-slate-500">اطلاعاتی از این فیش خوانده نشده؛ فقط خودِ فایل موجود است.</p>'}
        </div>`;
    }
    function bindCopyPreview(root) {
        root.querySelectorAll('[data-copy]').forEach(b => b.onclick = e => { e.preventDefault(); e.stopPropagation(); copyText(b.dataset.copy); });
        root.querySelectorAll('[data-preview]').forEach(b => b.onclick = e => { e.preventDefault(); e.stopPropagation(); previewFile(b.dataset.preview, b.dataset.ptitle); });
    }
    const instText = r => [`بیمه‌گذار: ${r.insured || ''}`, r.holder_name && r.holder_name !== r.insured ? `پرسنل: ${r.holder_name}` : '', r.personnel_code ? `کد پرسنلی: ${r.personnel_code}` : '',
        `شرکت: ${r.company_name || ''}`, `پلاک: ${r.plate || ''}`, `شماره بیمه‌نامه: ${r.policy_number || ''}`, `قسط: ${r.inst_number}`, `سررسید: ${r.due_jalali || ''}`,
        `مبلغ قسط: ${Number(r.amount || 0).toLocaleString('en-US')}`, `دریافت‌شده: ${Number(r.paid || 0).toLocaleString('en-US')}`, `مانده: ${Number(r.remaining || 0).toLocaleString('en-US')}`,
        `پرداخت به بیمه‌گر: ${Number(r.paid_insurer || 0).toLocaleString('en-US')}`, r.tracking_code ? `کد رهگیری: ${r.tracking_code}` : ''].filter(Boolean).join('\n');
    const histText = h => [h.direction === 'IN' ? 'دریافت از بیمه‌گذار' : 'پرداخت به بیمه‌گر', `مبلغ: ${Number(h.amount || 0).toLocaleString('en-US')} ریال`, `تاریخ: ${h.paid_jalali || ''}`,
        `روش: ${h.method_fa || ''}`, h.reference_no ? `شماره پیگیری: ${h.reference_no}` : '', h.cheque_no ? `شماره چک: ${h.cheque_no}` : '',
        h.receipt_id ? `سند: ${h.receipt_id}` : '', `ثبت: ${h.created_by_name || ''}`, h.note ? `توضیح: ${h.note}` : ''].filter(Boolean).join('\n');

    // =================================================================
    //  مرکز اقساط
    // =================================================================
    const H = {stage: 'US', rows: [], sel: new Map(), summary: null, total: 0, view: 'rows', groups: [], gsel: new Set(), stageGroups: null};
    try { if (localStorage.getItem('fh:view') === 'groups') H.view = 'groups'; } catch (e) {}
    const isGroups = () => H.view === 'groups';
    function fhFilters() {
        const v = id => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
        return {
            action: 'ledger_installments', stage: H.stage === 'ALL' ? '' : H.stage, q: v('fh-q'),
            source: v('fh-source'), company_id: v('fh-company'), insurer: v('fh-insurer'), insurance_type: v('fh-type'), status: v('fh-status'),
            insurer_status: v('fh-insst'), invoiced: v('fh-inv'), period: v('fh-period'), date_field: v('fh-datef') || 'due', from: v('fh-from'), to: v('fh-to'), min_delay: v('fh-delay'),
        };
    }
    async function initInstallments() {
        const root = document.getElementById('fh-root');
        if (!root) return;
        const m = await meta();
        if (!root.dataset.built) {
            root.dataset.built = '1';
            root.innerHTML = `
            <div id="fh-kpis" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3"></div>
            <div class="fh-views" id="fh-views">
                <button type="button" data-v="rows"><span class="v-ic"><i class="fas fa-list"></i></span><span><b>ردیف به ردیف</b><small>هر قسطِ هر بیمه‌نامه (کارکنان و شرکتی) جدا</small></span></button>
                <button type="button" data-v="groups"><span class="v-ic"><i class="fas fa-building-user"></i></span><span><b>کارکنان به تفکیکِ شرکت</b><small>هر شرکت در هر دوره‌ی مالی: یک ردیف برای هر قسط (جمعِ ثالث و بدنه‌ها)</small></span></button>
            </div>
            <div class="card p-3 flex flex-wrap items-center gap-2 fh-stages" id="fh-stages"></div>
            <div class="card p-4 grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2">
                <input type="search" id="fh-q" placeholder="جستجو: نام، پلاک، بیمه‌نامه، کد رهگیری، کد ملی/پرسنلی..." class="border rounded-lg px-3 py-2 text-xs col-span-2">
                <select id="fh-source" class="border rounded-lg px-3 py-2 text-xs font-bold"><option value="">کارکنان و شرکتی</option><option value="P">کارکنان (کسر از حقوق)</option><option value="C">شرکتی</option></select>
                <select id="fh-company" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی شرکت‌ها</option>${(m.companies || []).map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
                <select id="fh-insurer" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی بیمه‌گرها</option>${Object.entries(m.insurers || {}).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select>
                <select id="fh-type" class="border rounded-lg px-3 py-2 text-xs"><option value="">بدنه و ثالث</option><option value="BODY">بدنه</option><option value="THIRDPARTY">ثالث</option></select>
                <select id="fh-status" class="border rounded-lg px-3 py-2 text-xs"><option value="">وضعیتِ دریافت: همه</option><option value="UNPAID">دریافت‌نشده</option><option value="PARTIAL">مانده‌دار (ناقص)</option><option value="PAID">کامل دریافت‌شده</option><option value="OVERDUE">معوق</option><option value="UPCOMING">سررسیدِ ۳۰ روزِ آینده</option></select>
                <select id="fh-insst" class="border rounded-lg px-3 py-2 text-xs"><option value="">وضعیتِ بیمه‌گر: همه</option><option value="unpaid">پرداخت‌نشده به بیمه‌گر</option><option value="partial">پرداختِ ناقص به بیمه‌گر</option><option value="paid">کامل پرداخت‌شده به بیمه‌گر</option></select>
                <select id="fh-period" class="border rounded-lg px-3 py-2 text-xs">${monthOpts()}</select>
                <select id="fh-inv" class="border rounded-lg px-3 py-2 text-xs"><option value="">صورتحساب: همه</option><option value="0">بدون صورتحساب</option><option value="1">دارای صورتحساب</option></select>
                <select id="fh-datef" class="border rounded-lg px-3 py-2 text-xs"><option value="due">بازه روی سررسید</option><option value="issue">بازه روی تاریخ صدور</option></select>
                <input type="text" id="fh-from" placeholder="از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="fh-to" placeholder="تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <select id="fh-delay" class="border rounded-lg px-3 py-2 text-xs"><option value="">تأخیر: همه</option><option value="1">دارای تأخیر</option><option value="30">بیش از ۳۰ روز</option><option value="60">بیش از ۶۰ روز</option><option value="90">بیش از ۹۰ روز</option></select>
                <button type="button" id="fh-reset" class="border border-slate-200 rounded-lg px-3 py-2 text-xs font-bold text-slate-500 hover:bg-slate-50"><i class="fas fa-eraser ml-1"></i>پاک‌کردنِ فیلترها</button>
                <button type="button" id="fh-export" class="text-white rounded-lg px-3 py-2 text-xs font-bold" style="background:#059669"><i class="fas fa-file-excel ml-1"></i>خروجی اکسل</button>
            </div>
            <div class="fh-bar card" id="fh-bar">
                <div class="text-[12px] font-bold text-slate-600" id="fh-selinfo">ردیفی انتخاب نشده</div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" id="fh-clear" class="text-[11px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-xl">پاک‌کردنِ انتخاب</button>
                    <button type="button" id="fh-info-sel" class="text-[12px] font-black text-white px-4 py-2 rounded-xl disabled:opacity-40" style="background:linear-gradient(120deg,#4338ca,#6366f1)"><i class="fas fa-circle-info ml-1"></i>اطلاعاتِ اقساطِ انتخاب‌شده</button>
                    <button type="button" id="fh-pay-in" class="text-[12px] font-black text-white px-4 py-2 rounded-xl disabled:opacity-40" style="background:linear-gradient(120deg,#e11d48,#f43f5e)"><i class="fas fa-hand-holding-dollar ml-1"></i>ثبت دریافت از بیمه‌گذار</button>
                    <button type="button" id="fh-pay-out" class="text-[12px] font-black text-white px-4 py-2 rounded-xl disabled:opacity-40" style="background:linear-gradient(120deg,#b45309,#f59e0b)"><i class="fas fa-building-columns ml-1"></i>ثبت پرداخت به بیمه‌گر</button>
                </div>
            </div>
            <div class="card overflow-hidden"><div class="overflow-x-auto max-h-[64vh]" id="fh-table"></div></div>`;
            let t = null;
            const reload = () => { clearTimeout(t); t = setTimeout(loadInstallments, 350); };
            root.querySelectorAll('select').forEach(el => el.addEventListener('change', loadInstallments));
            ['fh-q', 'fh-from', 'fh-to'].forEach(id => document.getElementById(id).addEventListener('input', reload));
            document.getElementById('fh-reset').onclick = () => { root.querySelectorAll('input').forEach(i => { i.value = ''; }); root.querySelectorAll('select').forEach(s => { s.selectedIndex = 0; }); loadInstallments(); };
            root.querySelectorAll('#fh-views button').forEach(b => b.onclick = () => {
                if (H.view === b.dataset.v) return;
                H.view = b.dataset.v;
                try { localStorage.setItem('fh:view', H.view); } catch (e) {}
                H.sel.clear(); H.gsel.clear();
                loadInstallments();
            });
            document.getElementById('fh-export').onclick = () => {
                const p = fhFilters(); p.export = '1';
                if (isGroups()) { p.action = 'ledger_groups'; delete p.source; }
                Object.keys(p).forEach(k => { if (p[k] === '') delete p[k]; });
                window.location.href = API + '?' + new URLSearchParams(p).toString();
            };
            document.getElementById('fh-clear').onclick = () => { H.sel.clear(); H.gsel.clear(); isGroups() ? renderGroupTable() : renderTable(); };
            document.getElementById('fh-info-sel').onclick = () => isGroups() ? openMultiDetail(selMembers()) : openMultiDetail([...H.sel.values()]);
            document.getElementById('fh-pay-in').onclick = () => openPayDialog('IN', isGroups() ? selMembers() : [...H.sel.values()]);
            document.getElementById('fh-pay-out').onclick = () => openPayDialog('OUT', isGroups() ? selMembers() : [...H.sel.values()]);
        }
        loadInstallments();
    }
    async function loadInstallments() {
        const box = document.getElementById('fh-table');
        if (!box) return;
        document.querySelectorAll('#fh-views button').forEach(b => b.classList.toggle('on', b.dataset.v === H.view));
        const srcSel = document.getElementById('fh-source');
        if (srcSel) srcSel.classList.toggle('hidden', isGroups());
        if (isGroups()) return loadGroups();
        box.innerHTML = '<p class="p-10 text-center text-slate-400 text-xs">در حال بارگذاری...</p>';
        const p = fhFilters();
        const d = await api(p);
        if (!d.ok) { box.innerHTML = `<p class="p-10 text-center text-red-500 text-xs">${esc(d.error || 'خطا')}</p>`; return; }
        H.rows = d.rows || []; H.summary = d.summary; H.total = d.total_rows;
        // انتخاب‌هایی که دیگر در فهرست نیستند هم نگه داشته می‌شوند تا با عوضِ فیلتر از دست نروند
        H.rows.forEach(r => { const k = r.source + ':' + r.id; if (H.sel.has(k)) H.sel.set(k, r); });
        renderKpis(); renderStages(); renderTable();
    }
    function renderKpis() {
        const s = H.summary || {};
        const card = (t, v, sub, grad, ic) => `<div class="rounded-2xl p-4 text-white shadow-lg" style="background:${grad}">
            <div class="flex items-center justify-between"><p class="text-[11px] font-bold opacity-90">${t}</p><i class="fas ${ic} opacity-70"></i></div>
            <p class="text-xl font-black mt-1">${v}</p><p class="text-[10px] opacity-80 mt-0.5">${sub}</p></div>`;
        document.getElementById('fh-kpis').innerHTML =
            card('کلِ اقساطِ این فیلتر', num(s.amount), fa(s.count) + ' قسط · ریال', 'linear-gradient(135deg,#334155,#64748b)', 'fa-list-ol') +
            card('دریافت‌شده از بیمه‌گذار', num(s.paid), 'ریال', 'linear-gradient(135deg,#059669,#10b981)', 'fa-arrow-down') +
            card('مانده‌ی دریافت', num(s.remaining), 'ریال', 'linear-gradient(135deg,#e11d48,#fb7185)', 'fa-hourglass-half') +
            card('معوق', num(s.overdue), fa(s.overdue_count) + ' قسطِ سررسید گذشته', 'linear-gradient(135deg,#9f1239,#e11d48)', 'fa-triangle-exclamation') +
            card('پرداخت‌شده به بیمه‌گر', num(s.insurer_paid), 'ریال', 'linear-gradient(135deg,#4338ca,#6366f1)', 'fa-arrow-up') +
            card('قابلِ پرداخت به بیمه‌گر', num(s.insurer_payable), (META && META.rule_collect_before_pay) ? 'طبقِ قانونِ «اول دریافت، بعد پرداخت»' : 'ریال', 'linear-gradient(135deg,#b45309,#f59e0b)', 'fa-building-columns');
    }
    function renderStages() {
        const st = (isGroups() ? H.stageGroups : (H.summary && H.summary.stage)) || {};
        const items = [['US', 'بدهکار به ما (دریافت از بیمه‌گذار)', 'fa-hand-holding-dollar'], ['INSURER', 'بدهکار به بیمه‌گر (پرداخت به بیمه‌گر)', 'fa-building-columns'],
                       ['SETTLED', 'تسویه‌ی نهایی', 'fa-circle-check'], ['ALL', 'همه‌ی اقساط', 'fa-layer-group']];
        document.getElementById('fh-stages').innerHTML = items.map(([k, label, ic]) => {
            const n = k === 'ALL' ? '' : (H.stage === 'ALL' || H.stage === k ? fa(st[k] || 0) : '');
            return `<button type="button" data-st="${k}" class="${H.stage === k ? 'on' : ''}"><i class="fas ${ic} ml-1"></i>${label}${n ? ` <span class="fh-n">${n}</span>` : ''}</button>`;
        }).join('') + (isGroups()
            ? `<span class="mr-auto text-[11px] text-slate-400">${fa(H.total)} ردیفِ قسطِ شرکت${H.total > H.groups.length ? ` (نمایشِ ${fa(H.groups.length)} ردیفِ اول)` : ''}</span>`
            : `<span class="mr-auto text-[11px] text-slate-400">${fa(H.total)} ردیف${H.total > H.rows.length ? ` (نمایشِ ${fa(H.rows.length)} ردیفِ اول؛ با فیلتر محدود کنید)` : ''}</span>`);
        document.querySelectorAll('#fh-stages button[data-st]').forEach(b => b.onclick = () => { H.stage = b.dataset.st; loadInstallments(); });
    }
    function rowKey(r) { return r.source + ':' + r.id; }
    function renderTable() {
        const box = document.getElementById('fh-table');
        if (!H.rows.length) { box.innerHTML = '<div class="p-12 text-center text-slate-400"><i class="fas fa-inbox text-3xl mb-2 block"></i><p class="text-sm">با این فیلترها قسطی پیدا نشد.</p></div>'; updateBar(); return; }
        const allSel = H.rows.every(r => H.sel.has(rowKey(r)));
        box.innerHTML = `<table class="w-full text-right text-[11.5px] whitespace-nowrap fh-table no-count">
            <thead class="sticky top-0 z-10"><tr>
                <th class="p-2.5"><input type="checkbox" id="fh-all" ${allSel ? 'checked' : ''} class="accent-indigo-600 w-4 h-4"></th>
                <th class="p-2.5">کد رهگیری</th><th class="p-2.5">منبع / شرکت</th><th class="p-2.5">بیمه‌گذار</th><th class="p-2.5">پلاک</th>
                <th class="p-2.5">بیمه‌نامه</th><th class="p-2.5">بیمه‌گر</th><th class="p-2.5 text-center">قسط</th><th class="p-2.5">سررسید</th>
                <th class="p-2.5">مبلغ</th><th class="p-2.5">دریافت از بیمه‌گذار</th><th class="p-2.5">پرداخت به بیمه‌گر</th><th class="p-2.5">مرحله</th><th class="p-2.5"></th>
            </tr></thead><tbody>${H.rows.map(r => {
                const k = rowKey(r), sel = H.sel.has(k);
                const st = STAGE[r.stage] || STAGE.US;
                const pctIn = r.amount ? Math.min(100, Math.round(r.paid * 100 / r.amount)) : 0;
                const pctOut = r.amount ? Math.min(100, Math.round(r.paid_insurer * 100 / r.amount)) : 0;
                return `<tr class="border-t border-slate-100 ${sel ? 'bg-indigo-50/70' : 'hover:bg-slate-50'} ${r.overdue ? 'fh-overdue' : ''}" data-k="${k}">
                    <td class="p-2.5"><input type="checkbox" class="fh-chk accent-indigo-600 w-4 h-4" data-k="${k}" ${sel ? 'checked' : ''}></td>
                    <td class="p-2.5 font-mono text-[10.5px] text-slate-500" dir="ltr">${esc(r.tracking_code || '')}</td>
                    <td class="p-2.5"><span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${r.source === 'C' ? 'bg-indigo-100 text-indigo-700' : 'bg-sky-100 text-sky-700'}">${r.source === 'C' ? 'شرکتی' : 'کارکنان'}</span>
                        <p class="text-[10.5px] text-slate-600 mt-0.5 font-bold">${esc(r.company_name)}</p></td>
                    <td class="p-2.5"><p class="font-bold text-slate-800">${esc(r.insured)}</p>${r.source === 'P' ? `<p class="text-[10px] text-slate-400">${esc(r.holder_name || '')}${r.personnel_code ? ' · ' + fa(r.personnel_code) : ''}</p>` : ''}</td>
                    <td class="p-2.5">${plate(r.plate)}</td>
                    <td class="p-2.5"><p dir="ltr" class="font-mono text-[10.5px] text-right">${esc(r.policy_number || '—')}</p><p class="text-[10px] text-slate-400">${r.insurance_type === 'BODY' ? 'بدنه' : 'ثالث'} · صدور ${fa(r.issue_jalali)}</p></td>
                    <td class="p-2.5 text-[11px]">${esc(r.insurer_fa)}</td>
                    <td class="p-2.5 text-center font-black">${fa(r.inst_number)}</td>
                    <td class="p-2.5"><p class="${r.overdue ? 'text-red-600 font-black' : (r.upcoming ? 'text-amber-600 font-bold' : '')}">${fa(r.due_jalali)}</p>${r.delay_days ? `<p class="text-[10px] text-red-500">${fa(r.delay_days)} روز تأخیر</p>` : ''}</td>
                    <td class="p-2.5 font-black">${num(r.amount)}</td>
                    <td class="p-2.5 min-w-[150px]"><div class="flex justify-between text-[10.5px]"><span class="text-emerald-700 font-bold">${num(r.paid)}</span><span class="${r.remaining ? 'text-rose-600 font-bold' : 'text-slate-300'}">${r.remaining ? 'مانده ' + num(r.remaining) : 'کامل'}</span></div>
                        <div class="fh-prog"><span style="width:${pctIn}%;background:#10b981"></span></div></td>
                    <td class="p-2.5 min-w-[150px]"><div class="flex justify-between text-[10.5px]"><span class="text-indigo-700 font-bold">${num(r.paid_insurer)}</span><span class="${r.insurer_remaining ? 'text-amber-700 font-bold' : 'text-slate-300'}">${r.insurer_remaining ? 'مانده ' + num(r.insurer_remaining) : 'کامل'}</span></div>
                        <div class="fh-prog"><span style="width:${pctOut}%;background:#6366f1"></span></div></td>
                    <td class="p-2.5"><span class="text-[10px] font-bold px-2 py-1 rounded-full ${st[1]}"><i class="fas ${st[2]} ml-0.5"></i>${st[0]}</span>${r.is_invoiced ? '<p class="text-[9.5px] text-blue-600 mt-0.5">دارای صورتحساب</p>' : ''}</td>
                    <td class="p-2.5"><div class="flex gap-1">
                        <button class="fh-act w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600" data-a="info" data-k="${k}" title="جزئیات و سابقه"><i class="fas fa-circle-info"></i></button>
                        ${r.receipt && r.receipt.file ? `<button type="button" data-preview="${esc(r.receipt.file)}" data-ptitle="فیشِ قسطِ ${fa(r.inst_number)} — ${esc(r.insured)}" class="h-7 px-2 inline-flex items-center gap-1 rounded-lg bg-sky-50 hover:bg-sky-100 text-sky-700 text-[10.5px] font-bold" title="فیش پرداختیِ این قسط (از فایلِ بیمه‌نامه)"><i class="fas fa-receipt"></i>فیش</button>` : ''}
                        ${r.remaining > 0 ? `<button class="fh-act w-7 h-7 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600" data-a="in" data-k="${k}" title="ثبت دریافت"><i class="fas fa-hand-holding-dollar"></i></button>` : ''}
                        ${r.insurer_payable > 0 ? `<button class="fh-act w-7 h-7 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700" data-a="out" data-k="${k}" title="پرداخت به بیمه‌گر"><i class="fas fa-building-columns"></i></button>` : ''}
                    </div></td>
                </tr>`;
            }).join('')}</tbody></table>`;
        box.querySelectorAll('.fh-chk').forEach(c => c.onchange = () => {
            const r = H.rows.find(x => rowKey(x) === c.dataset.k);
            if (c.checked) H.sel.set(c.dataset.k, r); else H.sel.delete(c.dataset.k);
            c.closest('tr').classList.toggle('bg-indigo-50/70', c.checked);
            updateBar();
        });
        const all = document.getElementById('fh-all');
        if (all) all.onchange = () => { H.rows.forEach(r => { if (all.checked) H.sel.set(rowKey(r), r); else H.sel.delete(rowKey(r)); }); renderTable(); };
        bindCopyPreview(box);
        box.querySelectorAll('.fh-act').forEach(b => b.onclick = e => {
            e.stopPropagation();
            const r = H.rows.find(x => rowKey(x) === b.dataset.k);
            if (b.dataset.a === 'info') openInstDetail(r);
            if (b.dataset.a === 'in') openPayDialog('IN', [r]);
            if (b.dataset.a === 'out') openPayDialog('OUT', [r]);
        });
        updateBar();
    }
    function updateBar() {
        if (isGroups()) return updateGroupBar();
        const sel = [...H.sel.values()];
        const rem = sel.reduce((a, r) => a + (r.remaining || 0), 0), pay = sel.reduce((a, r) => a + (r.insurer_payable || 0), 0);
        document.getElementById('fh-selinfo').innerHTML = sel.length
            ? `<i class="fas fa-check-square text-indigo-600 ml-1"></i><b>${fa(sel.length)}</b> ردیف انتخاب شده · مانده‌ی دریافت: <b class="text-rose-600">${num(rem)}</b> · قابلِ پرداخت به بیمه‌گر: <b class="text-amber-700">${num(pay)}</b> ریال`
            : 'ردیف‌ها را تیک بزنید (یا تیکِ بالای جدول برای همه‌ی ردیف‌های این فیلتر) تا دریافت یا پرداخت را یک‌جا ثبت کنید.';
        document.getElementById('fh-info-sel').disabled = !sel.length;
        document.getElementById('fh-pay-in').disabled = !sel.some(r => r.remaining > 0);
        document.getElementById('fh-pay-out').disabled = !sel.some(r => r.insurer_payable > 0);
    }

    // =================================================================
    //  کارکنان به تفکیکِ شرکت: «ردیفِ قسط» = شرکت + دوره‌ی مالی + شماره‌ی قسط
    // =================================================================
    const selMembers = () => H.groups.filter(g => H.gsel.has(g.key)).flatMap(g => g.members);
    const typeChips = g => `${g.third_count ? `<span class="fh-chip bg-sky-100 text-sky-700">${fa(g.third_count)} ثالث</span>` : ''}${g.body_count ? `<span class="fh-chip bg-violet-100 text-violet-700">${fa(g.body_count)} بدنه</span>` : ''}`;
    const stageChips = sc => [['US', 'bg-rose-100 text-rose-700', 'بدهکار به ما'], ['INSURER', 'bg-amber-100 text-amber-800', 'آماده‌ی پرداخت به بیمه‌گر'], ['SETTLED', 'bg-emerald-100 text-emerald-700', 'تسویه']]
        .filter(([k]) => sc[k]).map(([k, c, l]) => `<span class="fh-chip ${c}">${fa(sc[k])} ${l}</span>`).join(' ');
    async function loadGroups() {
        const box = document.getElementById('fh-table');
        box.innerHTML = '<p class="p-10 text-center text-slate-400 text-xs">در حال بارگذاری...</p>';
        const p = fhFilters(); p.action = 'ledger_groups'; delete p.source;
        const d = await api(p);
        if (!isGroups()) return;
        if (!d.ok) { box.innerHTML = `<p class="p-10 text-center text-red-500 text-xs">${esc(d.error || 'خطا')}</p>`; return; }
        H.groups = d.groups || []; H.summary = d.summary; H.total = d.total_groups; H.stageGroups = d.stage_groups; H.dueCfg = d.due_cfg;
        const keys = new Set(H.groups.map(g => g.key));
        [...H.gsel].forEach(k => { if (!keys.has(k)) H.gsel.delete(k); });
        renderKpis(); renderStages(); renderGroupTable();
    }
    function renderGroupTable() {
        const box = document.getElementById('fh-table');
        if (!H.groups.length) {
            box.innerHTML = '<div class="p-12 text-center text-slate-400"><i class="fas fa-building-user text-3xl mb-2 block"></i><p class="text-sm">با این فیلترها ردیفِ قسطی برای شرکت‌ها پیدا نشد.</p><p class="text-[11px] mt-1">فقط اقساطِ کارکنان (کسر از حقوق) در این نما جمع می‌شوند.</p></div>';
            updateBar(); return;
        }
        const allSel = H.groups.every(g => H.gsel.has(g.key));
        const cfg = H.dueCfg || {day: 15, offset: 1};
        box.innerHTML = `<div class="px-3 py-2 text-[10.5px] text-slate-500 bg-sky-50 border-b border-sky-100"><i class="fas fa-circle-info ml-1 text-sky-600"></i>
                هر ردیف = اقساطِ همه‌ی بیمه‌نامه‌های کارکنانِ یک شرکت که در یک دوره‌ی مالی صادر شده‌اند. سررسیدِ قسطِ ۱: روزِ ${fa(cfg.day)} از ${!cfg.offset ? 'همان ماهِ دوره' : (cfg.offset === 1 ? 'ماهِ بعد از دوره' : fa(cfg.offset) + ' ماه بعد از دوره')} و قسط‌های بعدی هر ماه همان روز (قابلِ تغییر در تنظیماتِ مالی). روی هر ردیف بزنید تا بیمه‌نامه‌ها و اقساطش را ببینید.</div>
            <table class="w-full text-right text-[11.5px] whitespace-nowrap fh-table no-count">
            <thead class="sticky top-0 z-10"><tr>
                <th class="p-2.5"><input type="checkbox" id="fhg-all" ${allSel ? 'checked' : ''} class="accent-indigo-600 w-4 h-4"></th>
                <th class="p-2.5">شرکت</th><th class="p-2.5">دوره‌ی مالی</th><th class="p-2.5 text-center">قسط</th><th class="p-2.5">سررسید</th><th class="p-2.5">بیمه‌نامه‌ها</th>
                <th class="p-2.5">جمعِ قسط</th><th class="p-2.5">دریافت از شرکت</th><th class="p-2.5">پرداخت به بیمه‌گر</th><th class="p-2.5">وضعیت</th><th class="p-2.5"></th>
            </tr></thead><tbody>${H.groups.map(g => {
                const sel = H.gsel.has(g.key);
                const pctIn = g.amount ? Math.min(100, Math.round(g.paid * 100 / g.amount)) : 0;
                const pctOut = g.amount ? Math.min(100, Math.round(g.paid_insurer * 100 / g.amount)) : 0;
                return `<tr class="fh-grp-row border-t border-slate-100 ${sel ? 'bg-indigo-50/70' : 'hover:bg-slate-50'} ${g.overdue ? 'fh-overdue' : ''}" data-k="${esc(g.key)}">
                    <td class="p-2.5"><input type="checkbox" class="fhg-chk accent-indigo-600 w-4 h-4" data-k="${esc(g.key)}" ${sel ? 'checked' : ''}></td>
                    <td class="p-2.5"><p class="font-black text-slate-800">${esc(g.company_name)}</p><span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-sky-100 text-sky-700">کارکنان</span></td>
                    <td class="p-2.5 font-bold text-slate-600">${fa(g.period_title)}</td>
                    <td class="p-2.5 text-center"><span class="inline-flex w-8 h-8 items-center justify-center rounded-xl bg-indigo-600 text-white font-black">${fa(g.inst_number)}</span></td>
                    <td class="p-2.5"><p class="${g.overdue ? 'text-red-600 font-black' : (g.upcoming ? 'text-amber-600 font-bold' : '')}">${fa(g.due_jalali)}</p>${g.delay_days ? `<p class="text-[10px] text-red-500">${fa(g.delay_days)} روز تأخیر</p>` : ''}</td>
                    <td class="p-2.5"><b>${fa(g.count)}</b> بیمه‌نامه <div class="mt-0.5 flex gap-1">${typeChips(g)}</div></td>
                    <td class="p-2.5 font-black">${num(g.amount)}</td>
                    <td class="p-2.5 min-w-[160px]"><div class="flex justify-between text-[10.5px]"><span class="text-emerald-700 font-bold">${num(g.paid)}</span><span class="${g.remaining ? 'text-rose-600 font-bold' : 'text-slate-300'}">${g.remaining ? 'مانده ' + num(g.remaining) : 'کامل'}</span></div>
                        <div class="fh-prog"><span style="width:${pctIn}%;background:#10b981"></span></div></td>
                    <td class="p-2.5 min-w-[160px]"><div class="flex justify-between text-[10.5px]"><span class="text-indigo-700 font-bold">${num(g.paid_insurer)}</span><span class="${g.insurer_remaining ? 'text-amber-700 font-bold' : 'text-slate-300'}">${g.insurer_remaining ? 'مانده ' + num(g.insurer_remaining) : 'کامل'}</span></div>
                        <div class="fh-prog"><span style="width:${pctOut}%;background:#6366f1"></span></div></td>
                    <td class="p-2.5 whitespace-normal min-w-[150px]">${stageChips(g.stage_count)}</td>
                    <td class="p-2.5"><div class="flex gap-1">
                        <button class="fhg-act w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600" data-a="info" data-k="${esc(g.key)}" title="بیمه‌نامه‌ها و اقساط"><i class="fas fa-circle-info"></i></button>
                        ${g.remaining > 0 ? `<button class="fhg-act w-7 h-7 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600" data-a="in" data-k="${esc(g.key)}" title="ثبت دریافت از شرکت"><i class="fas fa-hand-holding-dollar"></i></button>` : ''}
                        ${g.insurer_payable > 0 ? `<button class="fhg-act w-7 h-7 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700" data-a="out" data-k="${esc(g.key)}" title="پرداخت به بیمه‌گر"><i class="fas fa-building-columns"></i></button>` : ''}
                    </div></td></tr>`;
            }).join('')}</tbody></table>`;
        const byKey = k => H.groups.find(g => g.key === k);
        box.querySelectorAll('.fhg-chk').forEach(c => {
            c.onclick = e => e.stopPropagation();
            c.onchange = () => { if (c.checked) H.gsel.add(c.dataset.k); else H.gsel.delete(c.dataset.k); c.closest('tr').classList.toggle('bg-indigo-50/70', c.checked); updateBar(); };
        });
        const all = document.getElementById('fhg-all');
        if (all) all.onchange = () => { H.groups.forEach(g => { if (all.checked) H.gsel.add(g.key); else H.gsel.delete(g.key); }); renderGroupTable(); };
        box.querySelectorAll('.fhg-act').forEach(b => b.onclick = e => {
            e.stopPropagation();
            const g = byKey(b.dataset.k);
            if (b.dataset.a === 'info') openGroupDetail(g);
            if (b.dataset.a === 'in') openPayDialog('IN', g.members);
            if (b.dataset.a === 'out') openPayDialog('OUT', g.members);
        });
        box.querySelectorAll('.fh-grp-row').forEach(tr => tr.onclick = () => openGroupDetail(byKey(tr.dataset.k)));
        updateBar();
    }
    function updateGroupBar() {
        const groups = H.groups.filter(g => H.gsel.has(g.key)), mem = groups.flatMap(g => g.members);
        const rem = mem.reduce((a, r) => a + (r.remaining || 0), 0), pay = mem.reduce((a, r) => a + (r.insurer_payable || 0), 0);
        document.getElementById('fh-selinfo').innerHTML = groups.length
            ? `<i class="fas fa-check-square text-indigo-600 ml-1"></i><b>${fa(groups.length)}</b> ردیفِ قسط (${fa(mem.length)} قسطِ بیمه‌نامه) · مانده‌ی دریافت: <b class="text-rose-600">${num(rem)}</b> · قابلِ پرداخت به بیمه‌گر: <b class="text-amber-700">${num(pay)}</b> ریال`
            : 'ردیف‌های قسطِ شرکت‌ها را تیک بزنید تا دریافت از شرکت یا پرداخت به بیمه‌گر را یک‌جا ثبت کنید؛ یا روی هر ردیف بزنید تا بیمه‌نامه‌هایش را ببینید.';
        document.getElementById('fh-info-sel').disabled = !mem.length;
        document.getElementById('fh-pay-in').disabled = !mem.some(r => r.remaining > 0);
        document.getElementById('fh-pay-out').disabled = !mem.some(r => r.insurer_payable > 0);
    }
    // پنجره‌ی یک ردیفِ قسط: همه‌ی بیمه‌نامه‌هایش (در همه‌ی مرحله‌ها) + اقساطِ کاملِ هر بیمه‌نامه
    async function openGroupDetail(g0) {
        if (!g0) return;
        const el = modal('fh-gdetail', '<p class="p-10 text-center text-slate-400 text-xs">در حال بارگذاری...</p>', true);
        const reload = () => openGroupDetail(g0);
        const d = await api({action: 'ledger_groups', stage: 'ALL', company_id: g0.company_id || '', period: g0.period, limit: 3000});
        const g = (d.groups || []).find(x => x.key === g0.key);
        if (!d.ok || !g) { el.querySelector('.modal-content').innerHTML = `<p class="p-10 text-center text-slate-400 text-xs">${esc(d.error || 'این ردیف دیگر قسطی ندارد.')}</p>`; return; }
        const mem = g.members;
        const pol = await api(null, {action: 'ledger_policy_insts', company_id: g.company_id || '', refs: mem.map(m => m.ref_id)});
        const insts = (pol && pol.policies) || {};
        const pctIn = g.amount ? Math.round(g.paid * 100 / g.amount) : 0;
        el.querySelector('.modal-content').innerHTML = `
            <div class="p-5 text-white rounded-t-[inherit]" style="background:linear-gradient(120deg,#0c4a6e,#4338ca)">
                <button type="button" onclick="document.getElementById('fh-gdetail').classList.remove('active')" class="absolute top-4 left-4 text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
                <p class="text-[11px] opacity-80">کارکنانِ شرکت · دوره‌ی مالیِ ${fa(g.period_title)}</p>
                <h3 class="text-lg font-black">قسطِ ${fa(g.inst_number)} — ${esc(g.company_name)}</h3>
                <div class="flex flex-wrap gap-2 mt-2 text-[11px] font-bold">
                    <span class="bg-white/20 rounded-full px-2.5 py-1"><i class="fas fa-calendar-day ml-1"></i>سررسید ${fa(g.due_jalali)}${g.delay_days ? ` · ${fa(g.delay_days)} روز تأخیر` : ''}</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">${fa(g.count)} بیمه‌نامه${g.third_count ? ` · ${fa(g.third_count)} ثالث` : ''}${g.body_count ? ` · ${fa(g.body_count)} بدنه` : ''}</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">جمعِ قسط: ${num(g.amount)} ریال</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">دریافت‌شده: ${num(g.paid)} (${fa(pctIn)}٪)</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">مانده: ${num(g.remaining)}</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">پرداخت به بیمه‌گر: ${num(g.paid_insurer)}</span>
                </div>
            </div>
            <div class="p-4 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    ${stageChips(g.stage_count)}
                    <div class="mr-auto flex flex-wrap gap-2">
                        ${g.remaining > 0 ? `<button type="button" id="fhg-in" class="text-[12px] font-black text-white px-4 py-2 rounded-xl" style="background:linear-gradient(120deg,#e11d48,#f43f5e)"><i class="fas fa-hand-holding-dollar ml-1"></i>ثبت دریافت از شرکت</button>` : ''}
                        ${g.insurer_payable > 0 ? `<button type="button" id="fhg-out" class="text-[12px] font-black text-white px-4 py-2 rounded-xl" style="background:linear-gradient(120deg,#b45309,#f59e0b)"><i class="fas fa-building-columns ml-1"></i>پرداخت به بیمه‌گر (${num(g.insurer_payable)})</button>` : ''}
                    </div>
                </div>
                <p class="text-[10.5px] text-slate-500"><i class="fas fa-circle-info ml-1"></i>در «ثبت دریافت از شرکت» مبلغِ هر بیمه‌نامه جدا قابلِ تغییر است؛ اگر شرکت کمتر پرداخت کرد، همان بیمه‌نامه با مانده‌اش در «بدهکار به ما» می‌ماند و بقیه در همین ردیف به «بدهکار به بیمه‌گر» می‌روند.</p>
                <div class="space-y-2">${mem.map((m, i) => {
                    const st = STAGE[m.stage] || STAGE.US;
                    const all = insts[m.ref_id] || [];
                    return `<details class="border-2 ${m.stage === 'US' ? 'border-rose-100' : (m.stage === 'INSURER' ? 'border-amber-100' : 'border-emerald-100')} rounded-2xl overflow-hidden">
                        <summary class="cursor-pointer flex flex-wrap items-center gap-2 px-4 py-2.5 bg-slate-50 text-[12px]">
                            <b class="text-slate-400">${fa(i + 1)}</b>
                            <span><b class="text-slate-800">${esc(m.holder_name || m.insured)}</b>${m.personnel_code ? ` <span class="text-[10px] text-slate-400">کد پرسنلی ${fa(m.personnel_code)}</span>` : ''}${m.insured && m.insured !== m.holder_name ? `<span class="text-[10px] text-slate-500"> · بیمه‌گذار: ${esc(m.insured)}</span>` : ''}</span>
                            <span>${plate(m.plate)}</span>
                            <span class="fh-chip ${fin_body(m) ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700'}">${fin_body(m) ? 'بدنه' : 'ثالث'}</span>
                            <span dir="ltr" class="font-mono text-[10.5px] text-slate-500">${esc(m.policy_number || '')}</span>
                            <span class="mr-auto flex flex-wrap items-center gap-2">
                                <span>قسطِ ${fa(m.inst_number)}: <b>${num(m.amount)}</b></span>
                                <span class="text-emerald-700 font-bold">دریافت ${num(m.paid)}</span>
                                ${m.remaining ? `<span class="text-rose-600 font-bold">مانده ${num(m.remaining)}</span>` : ''}
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${st[1]}">${st[0]}</span>
                            </span>
                        </summary>
                        <div class="p-3 space-y-2">
                            <div class="flex flex-wrap gap-2 text-[10.5px] text-slate-500">
                                <span>صدور ${fa(m.issue_jalali)}</span><span>حق بیمه‌ی کل ${num(m.premium)}</span><span>سررسیدِ خودِ این قسط ${fa(m.due_jalali)}</span>
                                <span class="font-mono" dir="ltr">${esc(m.tracking_code || '')}</span>
                                <span class="mr-auto flex gap-1">
                                    <button type="button" class="fhg-m text-[10.5px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg px-2 py-1" data-a="hist" data-i="${i}"><i class="fas fa-clock-rotate-left ml-1"></i>سابقه</button>
                                    ${m.remaining > 0 ? `<button type="button" class="fhg-m text-[10.5px] font-bold bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg px-2 py-1" data-a="in" data-i="${i}">دریافتِ همین</button>` : ''}
                                    ${m.insurer_payable > 0 ? `<button type="button" class="fhg-m text-[10.5px] font-bold bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg px-2 py-1" data-a="out" data-i="${i}">پرداختِ همین به بیمه‌گر</button>` : ''}
                                </span>
                            </div>
                            ${m.receipt ? slipCardHtml(m.receipt, 'فیشِ قسطِ ' + fa(m.inst_number) + ' این بیمه‌نامه') : ''}
                            <p class="text-[11px] font-black text-slate-600"><i class="fas fa-list-ol ml-1"></i>همه‌ی اقساطِ این بیمه‌نامه</p>
                            ${all.length ? `<div class="overflow-x-auto"><table class="w-full text-[11px] text-right whitespace-nowrap no-count"><thead class="bg-slate-50 text-slate-500"><tr>
                                <th class="p-1.5 text-center">قسط</th><th class="p-1.5">سررسید</th><th class="p-1.5">مبلغ</th><th class="p-1.5">دریافت</th><th class="p-1.5">مانده</th><th class="p-1.5">پرداخت به بیمه‌گر</th><th class="p-1.5">مرحله</th></tr></thead><tbody>
                                ${all.map(x => { const s2 = STAGE[x.stage] || STAGE.US; return `<tr class="border-t border-slate-100 ${x.inst_number === m.inst_number ? 'bg-indigo-50/70 font-bold' : ''}">
                                    <td class="p-1.5 text-center">${fa(x.inst_number)}</td><td class="p-1.5 ${x.overdue ? 'text-red-600' : ''}">${fa(x.due_jalali)}</td><td class="p-1.5">${num(x.amount)}</td>
                                    <td class="p-1.5 text-emerald-700">${num(x.paid)}</td><td class="p-1.5 ${x.remaining ? 'text-rose-600' : 'text-slate-300'}">${x.remaining ? num(x.remaining) : 'کامل'}</td>
                                    <td class="p-1.5 text-indigo-700">${num(x.paid_insurer)}</td><td class="p-1.5"><span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${s2[1]}">${s2[0]}</span></td></tr>`; }).join('')}
                            </tbody></table></div>` : '<p class="text-[11px] text-slate-400">اقساطِ دیگری پیدا نشد.</p>'}
                        </div>
                    </details>`;
                }).join('')}</div>
            </div>`;
        const after = () => { reload(); };
        bindCopyPreview(el);
        const bi = document.getElementById('fhg-in'), bo = document.getElementById('fhg-out');
        if (bi) bi.onclick = () => openPayDialog('IN', mem, after);
        if (bo) bo.onclick = () => openPayDialog('OUT', mem, after);
        el.querySelectorAll('.fhg-m').forEach(b => b.onclick = e => {
            e.preventDefault();
            const m = mem[Number(b.dataset.i)];
            if (b.dataset.a === 'hist') openInstDetail(m);
            if (b.dataset.a === 'in') openPayDialog('IN', [m], after);
            if (b.dataset.a === 'out') openPayDialog('OUT', [m], after);
        });
    }
    const fin_body = m => /BODY|بدنه/i.test(String(m.insurance_type || ''));

    // ---------- جزئیات و سابقه‌ی یک قسط (و چند قسطِ انتخاب‌شده) ----------
    function histHtml(hist) {
        const isAdmin = META && META.is_admin;
        if (!hist.length) return '<p class="text-[11px] text-slate-400">هنوز دریافت یا پرداختی برای این قسط ثبت نشده.</p>';
        return `<div class="space-y-1.5">${hist.map(h => `<div class="flex flex-wrap items-center gap-2 border border-slate-200 rounded-xl px-3 py-2 text-[11.5px]">
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${h.direction === 'IN' ? 'bg-emerald-100 text-emerald-700' : 'bg-indigo-100 text-indigo-700'}">${h.direction === 'IN' ? 'دریافت از بیمه‌گذار' : 'پرداخت به بیمه‌گر'}</span>
            <b>${num(h.amount)}</b> ریال <span class="text-slate-300">|</span> ${fa(h.paid_jalali) || '—'} <span class="text-slate-300">|</span> ${esc(h.method_fa)}
            ${h.reference_no ? `<span class="text-slate-500">پیگیری: <span dir="ltr">${esc(h.reference_no)}</span></span>` : ''}
            ${h.cheque_no ? `<span class="text-slate-500">چک ${fa(h.cheque_no)} ${h.cheque_status ? `<span class="text-[10px] px-1.5 rounded ${(CHQ[h.cheque_status] || ['', ''])[1]}">${(CHQ[h.cheque_status] || [''])[0]}</span>` : ''}</span>` : ''}
            ${(h.files || []).map((f, i) => `<button type="button" class="text-[10.5px] font-bold text-sky-700 bg-sky-50 hover:bg-sky-100 rounded-lg px-2 py-1" data-preview="${esc(f)}" data-ptitle="${esc((h.direction === 'IN' ? 'فیشِ دریافت' : 'فیشِ پرداخت به بیمه‌گر') + ' ' + fa(i + 1))}"><i class="fas fa-eye ml-1"></i>فیش ${fa(i + 1)}</button>`).join(' ')}
            <button type="button" class="text-[10.5px] font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg px-2 py-1" data-copy="${esc(histText(h))}" title="کپیِ اطلاعاتِ این ثبت"><i class="fas fa-copy ml-1"></i>کپی</button>
            <span class="text-[10.5px] text-slate-500 bg-slate-100 rounded-full px-2 py-0.5"><i class="fas fa-user-pen ml-1"></i>ثبت: ${esc(h.created_by_name || 'نامشخص')}</span>
            ${isAdmin ? `<button type="button" class="fh-hvoid mr-auto text-[10.5px] font-bold text-red-600 bg-red-50 hover:bg-red-100 rounded-lg px-2 py-1" data-kind="${h.receipt_id ? 'R' : esc(h.legacy_kind || '')}" data-id="${h.receipt_id || h.legacy_id || ''}" data-dir="${h.direction}"><i class="fas fa-ban ml-1"></i>ابطال</button>` : ''}
            ${h.note ? `<span class="text-slate-400 text-[10.5px] w-full">${esc(h.note)}</span>` : ''}
        </div>`).join('')}</div>`;
    }
    function instBodyHtml(r, hist, compact) {
        const row = (l, v) => `<div class="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5"><p class="text-[9.5px] text-slate-400">${l}</p><p class="text-[11.5px] font-bold text-slate-700">${v || '—'}</p></div>`;
        return `
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    ${row('منبع', r.source === 'C' ? 'شرکتی' : 'کارکنان')}${row('پلاک', plate(r.plate))}${row('شماره بیمه‌نامه', `<span dir="ltr">${esc(r.policy_number || '')}</span>`)}${row('بیمه‌گر', esc(r.insurer_fa))}
                    ${row('تاریخ صدور', fa(r.issue_jalali))}${row('سررسید', fa(r.due_jalali) + (r.delay_days ? ` <span class="text-red-500">(${fa(r.delay_days)} روز تأخیر)</span>` : ''))}${row('حق بیمه‌ی کل', num(r.premium))}${row('مبلغ قسط', num(r.amount))}
                </div>
                <div class="flex justify-end -mt-1"><button type="button" class="text-[10.5px] font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg px-2.5 py-1" data-copy="${esc(instText(r))}"><i class="fas fa-copy ml-1"></i>کپیِ اطلاعاتِ این قسط</button></div>
                ${r.receipt ? slipCardHtml(r.receipt) : '<p class="text-[10.5px] text-slate-400"><i class="fas fa-receipt ml-1"></i>فیشی برای این قسط در فایلِ بیمه‌نامه پیدا نشده.</p>'}
                <div class="grid md:grid-cols-2 gap-3">
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-3"><p class="text-[11px] font-black text-emerald-800 mb-1"><i class="fas fa-arrow-down ml-1"></i>دریافت از بیمه‌گذار</p>
                        <p class="text-sm"><b>${num(r.paid)}</b> از ${num(r.amount)} ریال${r.remaining ? ` | <span class="text-rose-600 font-bold">مانده ${num(r.remaining)}</span>` : ' | <span class="text-emerald-700 font-bold">کامل</span>'}</p></div>
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50/60 p-3"><p class="text-[11px] font-black text-indigo-800 mb-1"><i class="fas fa-arrow-up ml-1"></i>پرداخت به بیمه‌گر</p>
                        <p class="text-sm"><b>${num(r.paid_insurer)}</b> از ${num(r.amount)} ریال${r.insurer_remaining ? ` | <span class="text-amber-700 font-bold">مانده ${num(r.insurer_remaining)}</span>` : ' | <span class="text-emerald-700 font-bold">کامل</span>'}</p></div>
                </div>
                <div>
                    <p class="text-xs font-black text-slate-600 mb-2"><i class="fas fa-clock-rotate-left ml-1"></i>سابقه‌ی دریافت و پرداخت</p>
                    ${histHtml(hist)}
                </div>`;
    }
    function bindHistVoid(root, after) {
        bindCopyPreview(root);
        root.querySelectorAll('.fh-hvoid').forEach(b => b.onclick = () => voidDoc(b.dataset.kind, b.dataset.id, b.dataset.dir, after));
    }
    async function voidDoc(kind, id, dir, after) {
        if (!id || !kind) return;
        const ok = await ask('ابطالِ ' + (dir === 'OUT' ? 'پرداخت به بیمه‌گر' : 'دریافت از بیمه‌گذار'),
            'این ' + (dir === 'OUT' ? 'پرداخت' : 'دریافت') + ' باطل شود؟ مبالغش از همه‌ی قسط‌هایش برداشته می‌شود و قسط‌ها به وضعیتِ قبل برمی‌گردند.' +
            (dir === 'IN' ? '\nاگر روی این قسط‌ها پرداخت به بیمه‌گر ثبت شده باشد، اول باید آن پرداخت باطل شود.' : ''), {ok: 'بله، باطل شود', danger: true});
        if (!ok) return;
        const d = await api(null, {action: 'ledger_void', id, kind});
        if (!d.ok) { info('ابطال انجام نشد', d.error || 'خطا', 'warning'); return; }
        toast('سند باطل شد.', 'success');
        if (typeof after === 'function') after();
        if (document.getElementById('fh-root') && !document.getElementById('tab-fin-installments').classList.contains('hidden')) loadInstallments();
        if (document.getElementById('fl-root') && !document.getElementById('tab-fin-payments').classList.contains('hidden')) loadLedger();
    }
    async function openInstDetail(r) {
        const el = modal('fh-detail', '<p class="p-10 text-center text-slate-400 text-xs">در حال بارگذاری...</p>');
        const d = await api({action: 'ledger_history', source: r.source, id: r.id});
        const hist = (d.history || []);
        const st = STAGE[r.stage] || STAGE.US;
        el.querySelector('.modal-content').innerHTML = `
            <div class="p-5 text-white rounded-t-[inherit]" style="background:linear-gradient(120deg,#312e81,#4f46e5)">
                <button type="button" onclick="document.getElementById('fh-detail').classList.remove('active')" class="absolute top-4 left-4 text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
                <p class="text-[11px] opacity-80">قسطِ ${fa(r.inst_number)} | کد رهگیری <span dir="ltr">${esc(r.tracking_code || '')}</span></p>
                <h3 class="text-lg font-black">${esc(r.insured)} <span class="text-[12px] font-bold opacity-80">— ${esc(r.company_name)}</span></h3>
                <span class="inline-block mt-2 text-[10.5px] font-bold px-2 py-1 rounded-full bg-white/20">${st[0]}</span>
            </div>
            <div class="p-5 space-y-4">
                ${instBodyHtml(r, hist)}
                <div class="flex flex-wrap gap-2 pt-1">
                    ${r.remaining > 0 ? `<button type="button" id="fhd-in" class="text-[12px] font-black text-white px-4 py-2 rounded-xl" style="background:linear-gradient(120deg,#e11d48,#f43f5e)"><i class="fas fa-hand-holding-dollar ml-1"></i>ثبت دریافت</button>` : ''}
                    ${r.insurer_payable > 0 ? `<button type="button" id="fhd-out" class="text-[12px] font-black text-white px-4 py-2 rounded-xl" style="background:linear-gradient(120deg,#b45309,#f59e0b)"><i class="fas fa-building-columns ml-1"></i>پرداخت به بیمه‌گر</button>` : ''}
                </div>
            </div>`;
        const bi = document.getElementById('fhd-in'), bo = document.getElementById('fhd-out');
        if (bi) bi.onclick = () => { closeModal('fh-detail'); openPayDialog('IN', [r]); };
        if (bo) bo.onclick = () => { closeModal('fh-detail'); openPayDialog('OUT', [r]); };
        bindHistVoid(el, () => closeModal('fh-detail'));
    }
    // اطلاعاتِ چند قسطِ انتخاب‌شده با هم (هر قسط یک کارتِ تاشونده با همان جزئیاتِ پنجره‌ی تکی)
    async function openMultiDetail(rows) {
        if (!rows.length) return;
        const el = modal('fh-detail', '<p class="p-10 text-center text-slate-400 text-xs">در حال بارگذاری...</p>', true);
        const hists = await Promise.all(rows.slice(0, 80).map(r => api({action: 'ledger_history', source: r.source, id: r.id}).then(d => d.history || [])));
        const sum = k => rows.reduce((a, r) => a + (Number(r[k]) || 0), 0);
        el.querySelector('.modal-content').innerHTML = `
            <div class="p-5 text-white rounded-t-[inherit]" style="background:linear-gradient(120deg,#312e81,#4f46e5)">
                <button type="button" onclick="document.getElementById('fh-detail').classList.remove('active')" class="absolute top-4 left-4 text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
                <h3 class="text-lg font-black"><i class="fas fa-layer-group ml-1"></i>اطلاعاتِ ${fa(rows.length)} قسطِ انتخاب‌شده</h3>
                <div class="flex flex-wrap gap-2 mt-2 text-[11px] font-bold">
                    <span class="bg-white/20 rounded-full px-2.5 py-1">جمعِ اقساط: ${num(sum('amount'))} ریال</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">دریافت‌شده: ${num(sum('paid'))}</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">مانده‌ی دریافت: ${num(sum('remaining'))}</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">پرداخت به بیمه‌گر: ${num(sum('paid_insurer'))}</span>
                    <span class="bg-white/20 rounded-full px-2.5 py-1">مانده‌ی بیمه‌گر: ${num(sum('insurer_remaining'))}</span>
                </div>
            </div>
            <div class="p-4 space-y-2">
                ${rows.slice(0, 80).map((r, i) => {
                    const st = STAGE[r.stage] || STAGE.US;
                    return `<details class="border-2 border-slate-200 rounded-2xl overflow-hidden" ${rows.length <= 3 ? 'open' : ''}>
                        <summary class="cursor-pointer flex flex-wrap items-center gap-2 px-4 py-2.5 bg-slate-50 text-[12px]">
                            <b class="text-slate-400">${fa(i + 1)}</b><b class="text-slate-800">${esc(r.insured)}</b><span class="text-slate-500">${esc(r.company_name)}</span>
                            <span>${plate(r.plate)}</span><span class="text-slate-500">قسط ${fa(r.inst_number)} | سررسید ${fa(r.due_jalali)}</span>
                            <b>${num(r.amount)}</b><span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${st[1]}">${st[0]}</span>
                            <span class="font-mono text-[10px] text-slate-400 mr-auto" dir="ltr">${esc(r.tracking_code || '')}</span>
                        </summary>
                        <div class="p-4 space-y-3">${instBodyHtml(r, hists[i] || [])}</div>
                    </details>`;
                }).join('')}
                ${rows.length > 80 ? `<p class="text-[11px] text-slate-400 text-center">فقط ۸۰ قسطِ اول نمایش داده شد.</p>` : ''}
            </div>`;
        bindHistVoid(el, () => closeModal('fh-detail'));
    }

    // ---------- پنجره‌ی ثبتِ دریافت / پرداخت ----------
    async function openPayDialog(dir, rows, onDone) {
        const m = await meta();
        const list = rows.filter(r => dir === 'IN' ? r.remaining > 0 : r.insurer_payable > 0);
        if (!list.length) return toast(dir === 'IN' ? 'ردیفِ انتخاب‌شده‌ای مانده‌ی دریافت ندارد.' : 'ردیفِ انتخاب‌شده‌ای قابلِ پرداخت به بیمه‌گر نیست.', 'warning');
        const skipped = rows.length - list.length;
        const isIn = dir === 'IN';
        const insurers = [...new Set(list.map(r => r.insurer || 'PASARGAD'))];
        const el = modal('fh-pay', `
            <div class="p-5 text-white" style="background:${isIn ? 'linear-gradient(120deg,#be123c,#f43f5e)' : 'linear-gradient(120deg,#92400e,#f59e0b)'}">
                <button type="button" onclick="document.getElementById('fh-pay').classList.remove('active')" class="absolute top-4 left-4 text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
                <h3 class="text-lg font-black"><i class="fas ${isIn ? 'fa-hand-holding-dollar' : 'fa-building-columns'} ml-1"></i>${isIn ? 'ثبت دریافت از بیمه‌گذار / شرکت' : 'ثبت پرداخت به بیمه‌گر'}</h3>
                <p class="text-[11px] opacity-90">${fa(list.length)} قسط${skipped ? ` · ${fa(skipped)} ردیفِ انتخاب‌شده در این مرحله نبود و کنار گذاشته شد` : ''}. مبلغِ هر ردیف را می‌توانید تغییر دهید (پرداختِ جزئی).</p>
            </div>
            <div class="p-5 space-y-4">
                <div class="overflow-x-auto border border-slate-100 rounded-xl max-h-[38vh]">
                    <table class="w-full text-[11.5px] text-right whitespace-nowrap no-count">
                        <thead class="bg-slate-50 text-slate-500 sticky top-0"><tr><th class="p-2"><input type="checkbox" id="fp-all" checked class="accent-indigo-600"></th><th class="p-2">بیمه‌گذار / شرکت</th><th class="p-2">بیمه‌نامه</th><th class="p-2 text-center">قسط</th><th class="p-2">سررسید</th><th class="p-2">مبلغ قسط</th><th class="p-2">${isIn ? 'مانده‌ی دریافت' : 'قابلِ پرداخت'}</th><th class="p-2">مبلغِ این ثبت (ریال)</th><th class="p-2">بعد از این ثبت</th></tr></thead>
                        <tbody>${list.map((r, i) => {
                            const max = isIn ? r.remaining : r.insurer_payable;
                            return `<tr class="border-t border-slate-100"><td class="p-2"><input type="checkbox" class="fp-chk accent-indigo-600" data-i="${i}" checked></td>
                                <td class="p-2"><b>${esc(r.insured)}</b><p class="text-[10px] text-slate-400">${esc(r.company_name)} · ${r.source === 'C' ? 'شرکتی' : 'کارکنان'}</p></td>
                                <td class="p-2" dir="ltr">${esc(r.policy_number || '')}</td><td class="p-2 text-center font-black">${fa(r.inst_number)}</td><td class="p-2">${fa(r.due_jalali)}</td>
                                <td class="p-2">${num(r.amount)}</td><td class="p-2 font-bold ${isIn ? 'text-rose-600' : 'text-amber-700'}">${num(max)}</td>
                                <td class="p-2"><div class="flex items-center gap-1"><input type="text" inputmode="numeric" dir="ltr" class="fp-amt money-input border rounded-lg px-2 py-1 w-36 text-[12px] font-bold" data-i="${i}" data-max="${max}" value="${num(max)}">
                                    <button type="button" class="fp-q text-[10px] font-bold bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-md px-1.5 py-1" data-i="${i}" data-v="full" title="کلِ مانده">کامل</button>
                                    <button type="button" class="fp-q text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-md px-1.5 py-1" data-i="${i}" data-v="zero" title="${isIn ? 'برای این بیمه‌نامه پرداخت نشده' : 'فعلاً پرداخت نمی‌شود'}">${isIn ? 'نداده' : 'نه'}</button></div></td>
                                <td class="p-2"><span class="fp-after text-[10.5px] font-bold" data-i="${i}"></span></td></tr>`;
                        }).join('')}</tbody>
                    </table>
                </div>
                <div class="flex flex-wrap gap-2 items-center text-[11px]">
                    <button type="button" id="fp-allfull" class="font-bold bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-lg px-3 py-1.5"><i class="fas fa-check-double ml-1"></i>همه کامل</button>
                    <span id="fp-plan" class="text-slate-500"></span>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-slate-900 text-white px-4 py-3"><span class="text-[12px] font-bold">جمعِ این ثبت</span><span class="text-lg font-black" id="fp-total">۰</span></div>
                <div class="grid md:grid-cols-3 gap-3">
                    <div><label class="text-[11px] font-bold text-slate-500 block mb-1">تاریخ ${isIn ? 'دریافت' : 'پرداخت'}</label><input id="fp-date" dir="ltr" class="border rounded-lg px-3 py-2 text-xs w-full" value="${fa(todayJ())}" placeholder="۱۴۰۵/۰۷/۱۱"></div>
                    <div><label class="text-[11px] font-bold text-slate-500 block mb-1">روشِ ${isIn ? 'دریافت' : 'پرداخت'}</label><select id="fp-method" class="border rounded-lg px-3 py-2 text-xs w-full">${Object.entries(m.methods || {}).filter(([k]) => isIn || k !== 'PAYROLL').map(([k, v]) => `<option value="${k}" ${k === (isIn && list.every(r => r.source === 'P') ? 'PAYROLL' : 'TRANSFER') ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
                    <div><label class="text-[11px] font-bold text-slate-500 block mb-1">شماره پیگیری / فیش</label><input id="fp-ref" dir="ltr" class="border rounded-lg px-3 py-2 text-xs w-full"></div>
                    ${!isIn ? `<div><label class="text-[11px] font-bold text-slate-500 block mb-1">بیمه‌گر</label><select id="fp-insurer" class="border rounded-lg px-3 py-2 text-xs w-full">${Object.entries(m.insurers || {}).map(([k, v]) => `<option value="${k}" ${k === insurers[0] ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>${insurers.length > 1 ? '<p class="text-[10px] text-amber-600 mt-1">ردیف‌ها بیمه‌گرِ متفاوت دارند؛ بهتر است جدا ثبت شوند.</p>' : ''}</div>` : ''}
                    <div class="${isIn ? 'md:col-span-1' : ''}"><label class="text-[11px] font-bold text-slate-500 block mb-1">${isIn ? 'پرداخت‌کننده' : 'گیرنده'} (اختیاری)</label><input id="fp-party" class="border rounded-lg px-3 py-2 text-xs w-full" placeholder="${isIn ? 'خودکار: نامِ شرکت' : 'خودکار: نامِ بیمه‌گر'}"></div>
                    <div class="${isIn ? 'md:col-span-2' : ''}"><label class="text-[11px] font-bold text-slate-500 block mb-1">توضیح</label><input id="fp-note" class="border rounded-lg px-3 py-2 text-xs w-full"></div>
                </div>
                <div id="fp-cheque" class="hidden grid md:grid-cols-3 gap-3 rounded-xl border border-amber-200 bg-amber-50/60 p-3">
                    <div><label class="text-[11px] font-bold text-amber-800 block mb-1">شماره چک *</label><input id="fp-chq-no" dir="ltr" class="border rounded-lg px-3 py-2 text-xs w-full"></div>
                    <div><label class="text-[11px] font-bold text-amber-800 block mb-1">بانک</label><input id="fp-chq-bank" class="border rounded-lg px-3 py-2 text-xs w-full"></div>
                    <div><label class="text-[11px] font-bold text-amber-800 block mb-1">تاریخ سررسید چک</label><input id="fp-chq-due" dir="ltr" class="border rounded-lg px-3 py-2 text-xs w-full" placeholder="۱۴۰۵/۰۸/۱۵"></div>
                </div>
                <label class="flex items-center gap-3 border-2 border-dashed border-slate-200 rounded-xl p-3 cursor-pointer hover:bg-slate-50">
                    <i class="fas fa-paperclip text-slate-400 text-lg"></i><span class="text-[12px] font-bold text-slate-600" id="fp-files-l">فیش / تصویرِ چک / رسید (عکس یا PDF، چندتایی)</span>
                    <input type="file" id="fp-files" multiple accept="image/*,.pdf" class="hidden"></label>
                <div id="fp-err" class="hidden text-[11.5px] text-red-700 bg-red-50 border border-red-200 rounded-xl p-3"></div>
                <div class="flex gap-2 justify-end">
                    <button type="button" onclick="document.getElementById('fh-pay').classList.remove('active')" class="text-[12px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-600 px-4 py-2 rounded-xl">انصراف</button>
                    <button type="button" id="fp-save" class="text-[12px] font-black text-white px-5 py-2 rounded-xl" style="background:${isIn ? '#e11d48' : '#d97706'}"><i class="fas fa-check ml-1"></i>ثبتِ نهایی</button>
                </div>
            </div>`, true);
        const total = () => {
            let t = 0;
            let full = 0, part = 0, none = 0;
            el.querySelectorAll('.fp-amt').forEach(inp => {
                const on = el.querySelector(`.fp-chk[data-i="${inp.dataset.i}"]`).checked; inp.disabled = !on;
                const a = on ? Number(digits(inp.value) || 0) : 0, max = Number(inp.dataset.max);
                t += a;
                // وضعیتِ هر ردیف بعد از این ثبت: کامل => مرحله‌ی بعد؛ کمتر => مانده‌دار در همین مرحله می‌ماند
                const after = el.querySelector(`.fp-after[data-i="${inp.dataset.i}"]`);
                if (a > max) { after.innerHTML = '<span class="text-red-600">بیشتر از مانده!</span>'; part++; }
                else if (a >= max) { after.innerHTML = `<span class="text-emerald-700"><i class="fas fa-arrow-left ml-1"></i>${isIn ? 'کامل؛ می‌رود به «بدهکار به بیمه‌گر»' : 'کامل؛ تسویه'}</span>`; full++; }
                else if (a > 0) { after.innerHTML = `<span class="text-amber-700">مانده ${num(max - a)} — در همین مرحله می‌ماند</span>`; part++; }
                else { after.innerHTML = `<span class="text-slate-400">بدونِ ${isIn ? 'دریافت' : 'پرداخت'} — با کلِ مانده می‌ماند</span>`; none++; }
            });
            document.getElementById('fp-total').textContent = num(t) + ' ریال';
            document.getElementById('fp-plan').innerHTML = `<b class="text-emerald-700">${fa(full)}</b> کامل · <b class="text-amber-700">${fa(part)}</b> ناقص (مانده‌دار) · <b class="text-slate-600">${fa(none)}</b> ${isIn ? 'نداده' : 'بدونِ پرداخت'}`;
        };
        el.querySelectorAll('.fp-amt').forEach(inp => inp.addEventListener('input', total));
        el.querySelectorAll('.fp-chk').forEach(c => c.addEventListener('change', total));
        document.getElementById('fp-all').onchange = e => { el.querySelectorAll('.fp-chk').forEach(c => { c.checked = e.target.checked; }); total(); };
        const setAmt = (i, v) => { const inp = el.querySelector(`.fp-amt[data-i="${i}"]`); el.querySelector(`.fp-chk[data-i="${i}"]`).checked = true; inp.value = v === 'full' ? num(inp.dataset.max) : '۰'; };
        el.querySelectorAll('.fp-q').forEach(b => b.onclick = () => { setAmt(b.dataset.i, b.dataset.v); total(); });
        document.getElementById('fp-allfull').onclick = () => { el.querySelectorAll('.fp-amt').forEach(inp => setAmt(inp.dataset.i, 'full')); total(); };
        const mth = document.getElementById('fp-method');
        const chq = () => document.getElementById('fp-cheque').classList.toggle('hidden', mth.value !== 'CHEQUE');
        mth.onchange = chq; chq();
        document.getElementById('fp-files').onchange = e => { const n = e.target.files.length; document.getElementById('fp-files-l').textContent = n ? fa(n) + ' فایل انتخاب شد' : 'فیش / تصویرِ چک / رسید (عکس یا PDF، چندتایی)'; };
        total();
        document.getElementById('fp-save').onclick = async () => {
            const err = document.getElementById('fp-err');
            err.classList.add('hidden');
            const items = [];
            let over = false;
            el.querySelectorAll('.fp-amt').forEach(inp => {
                if (!el.querySelector(`.fp-chk[data-i="${inp.dataset.i}"]`).checked) return;
                const a = Number(digits(inp.value) || 0);
                if (a > Number(inp.dataset.max)) over = true;
                if (a > 0) { const r = list[Number(inp.dataset.i)]; items.push({source: r.source, id: r.id, amount: a}); }
            });
            if (!items.length) { err.textContent = 'مبلغی برای ثبت وارد نشده.'; err.classList.remove('hidden'); return; }
            if (over) { err.textContent = 'مبلغِ بعضی ردیف‌ها از مانده‌شان بیشتر است.'; err.classList.remove('hidden'); return; }
            if (mth.value === 'CHEQUE' && !document.getElementById('fp-chq-no').value.trim()) { err.textContent = 'شماره‌ی چک را وارد کنید.'; err.classList.remove('hidden'); return; }
            const fd = new FormData();
            fd.append('action', 'ledger_register'); fd.append('direction', dir); fd.append('items', JSON.stringify(items));
            fd.append('method', mth.value); fd.append('paid_jalali', document.getElementById('fp-date').value.trim());
            fd.append('reference_no', document.getElementById('fp-ref').value.trim()); fd.append('note', document.getElementById('fp-note').value.trim());
            fd.append('party', document.getElementById('fp-party').value.trim());
            if (!isIn) fd.append('insurer', document.getElementById('fp-insurer').value);
            if (mth.value === 'CHEQUE') { fd.append('cheque_no', document.getElementById('fp-chq-no').value.trim()); fd.append('cheque_bank', document.getElementById('fp-chq-bank').value.trim()); fd.append('cheque_due', document.getElementById('fp-chq-due').value.trim()); }
            [...document.getElementById('fp-files').files].forEach(f => fd.append('files[]', f));
            const btn = document.getElementById('fp-save'); btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حال ثبت...';
            let d;
            try { d = await (await fetch(API, {method: 'POST', body: fd})).json(); } catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check ml-1"></i>ثبتِ نهایی';
            if (!d.ok) {
                err.innerHTML = esc(d.error || 'خطا') + ((d.rejected || []).length ? '<ul class="mt-1 list-disc pr-4">' + d.rejected.map(x => `<li>${esc(x.label)}: ${esc(x.reason)}</li>`).join('') + '</ul>' : '');
                err.classList.remove('hidden'); return;
            }
            closeModal('fh-pay');
            toast(`${fa(d.applied)} قسط ثبت شد · ${num(d.total)} ریال${(d.rejected || []).length ? ` · ${fa(d.rejected.length)} ردیف رد شد` : ''}`, (d.rejected || []).length ? 'warning' : 'success');
            if ((d.rejected || []).length) info('ردیف‌های ردشده', d.rejected.map(x => `• ${x.label}: ${x.reason}`).join('\n'), 'warning');
            H.sel.clear(); H.gsel.clear();
            if (typeof onDone === 'function') onDone(d);
            if (document.getElementById('fh-root') && !document.getElementById('tab-fin-installments').classList.contains('hidden')) loadInstallments();
            if (document.getElementById('fl-root') && !document.getElementById('tab-fin-payments').classList.contains('hidden')) loadLedger();
        };
    }

    // =================================================================
    //  دفتر دریافت‌ها و پرداخت‌ها + چک‌ها
    // =================================================================
    const L = {tab: 'IN', rows: []};
    function flFilters() {
        const v = id => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
        return {action: 'ledger_list', direction: L.tab === 'IN' || L.tab === 'OUT' ? L.tab : '', cheque_only: L.tab === 'CHEQUE' ? '1' : '',
                q: v('fl-q'), company_id: v('fl-company'), method: v('fl-method'), insurer: v('fl-insurer'), from: v('fl-from'), to: v('fl-to'),
                cheque_status: v('fl-chq'), status: v('fl-status') || 'ACTIVE'};
    }
    async function initLedger() {
        const root = document.getElementById('fl-root');
        if (!root) return;
        const m = await meta();
        if (!root.dataset.built) {
            root.dataset.built = '1';
            root.innerHTML = `
            <div id="fl-kpis" class="grid grid-cols-2 xl:grid-cols-4 gap-3"></div>
            <div class="card p-3 flex flex-wrap items-center gap-2 fh-stages" id="fl-tabs"></div>
            <div class="card p-4 grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2">
                <input type="search" id="fl-q" placeholder="جستجو: طرف حساب، پیگیری، شماره چک، مبلغ..." class="border rounded-lg px-3 py-2 text-xs col-span-2">
                <select id="fl-company" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی شرکت‌ها</option>${(m.companies || []).map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
                <select id="fl-method" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی روش‌ها</option>${Object.entries(m.methods || {}).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select>
                <select id="fl-insurer" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی بیمه‌گرها</option>${Object.entries(m.insurers || {}).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select>
                <select id="fl-chq" class="border rounded-lg px-3 py-2 text-xs"><option value="">وضعیتِ چک: همه</option><option value="PENDING">در انتظار وصول</option><option value="CLEARED">وصول شد</option><option value="BOUNCED">برگشتی</option></select>
                <input type="text" id="fl-from" placeholder="از تاریخ ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="fl-to" placeholder="تا تاریخ ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <select id="fl-status" class="border rounded-lg px-3 py-2 text-xs"><option value="ACTIVE">فقط فعال</option><option value="ALL">همراه با باطل‌شده‌ها</option></select>
                <button type="button" id="fl-new-in" class="text-white rounded-lg px-3 py-2 text-xs font-bold" style="background:#e11d48"><i class="fas fa-plus ml-1"></i>دریافتِ جدید</button>
                <button type="button" id="fl-new-out" class="text-white rounded-lg px-3 py-2 text-xs font-bold" style="background:#d97706"><i class="fas fa-plus ml-1"></i>پرداختِ جدید به بیمه‌گر</button>
                <button type="button" id="fl-export" class="text-white rounded-lg px-3 py-2 text-xs font-bold" style="background:#059669"><i class="fas fa-file-excel ml-1"></i>خروجی اکسل</button>
            </div>
            <div class="card overflow-hidden"><div class="overflow-x-auto max-h-[64vh]" id="fl-table"></div></div>`;
            let t = null;
            root.querySelectorAll('select').forEach(el => el.addEventListener('change', loadLedger));
            ['fl-q', 'fl-from', 'fl-to'].forEach(id => document.getElementById(id).addEventListener('input', () => { clearTimeout(t); t = setTimeout(loadLedger, 350); }));
            document.getElementById('fl-export').onclick = () => { const p = flFilters(); p.export = '1'; Object.keys(p).forEach(k => { if (p[k] === '') delete p[k]; }); window.location.href = API + '?' + new URLSearchParams(p).toString(); };
            // دریافت/پرداخت همیشه روی قسط‌ها ثبت می‌شود؛ به مرکز اقساط با همان مرحله می‌رود
            document.getElementById('fl-new-in').onclick = () => { H.stage = 'US'; switchTab('fin-installments'); toast('قسط‌ها را تیک بزنید و «ثبت دریافت از بیمه‌گذار» را بزنید.', 'info'); };
            document.getElementById('fl-new-out').onclick = () => { H.stage = 'INSURER'; switchTab('fin-installments'); toast('قسط‌ها را تیک بزنید و «ثبت پرداخت به بیمه‌گر» را بزنید.', 'info'); };
        }
        loadLedger();
    }
    async function loadLedger() {
        const box = document.getElementById('fl-table');
        if (!box) return;
        box.innerHTML = '<p class="p-10 text-center text-slate-400 text-xs">در حال بارگذاری...</p>';
        const d = await api(flFilters());
        if (!d.ok) { box.innerHTML = `<p class="p-10 text-center text-red-500 text-xs">${esc(d.error || 'خطا')}</p>`; return; }
        L.rows = d.rows || [];
        const s = d.summary || {};
        const card = (t, v, sub, grad, ic) => `<div class="rounded-2xl p-4 text-white shadow-lg" style="background:${grad}"><div class="flex items-center justify-between"><p class="text-[11px] font-bold opacity-90">${t}</p><i class="fas ${ic} opacity-70"></i></div><p class="text-xl font-black mt-1">${v}</p><p class="text-[10px] opacity-80">${sub}</p></div>`;
        document.getElementById('fl-kpis').innerHTML =
            card('دریافت از بیمه‌گذاران', num(s.IN), 'ریال · روی همین فیلتر', 'linear-gradient(135deg,#be123c,#f43f5e)', 'fa-arrow-down') +
            card('پرداخت به بیمه‌گرها', num(s.OUT), 'ریال · روی همین فیلتر', 'linear-gradient(135deg,#92400e,#f59e0b)', 'fa-arrow-up') +
            card('مانده‌ی نزدِ ما', num((s.IN || 0) - (s.OUT || 0)), 'دریافت منهای پرداخت', 'linear-gradient(135deg,#312e81,#6366f1)', 'fa-scale-balanced') +
            card('چک‌های در انتظار وصول', num(s.cheque_pending), 'ریال', 'linear-gradient(135deg,#334155,#64748b)', 'fa-money-check');
        const tabs = [['IN', 'دریافت از بیمه‌گذار', 'fa-hand-holding-dollar'], ['OUT', 'پرداخت به بیمه‌گر', 'fa-building-columns'], ['CHEQUE', 'چک‌ها', 'fa-money-check'], ['ALL', 'همه', 'fa-layer-group']];
        document.getElementById('fl-tabs').innerHTML = tabs.map(([k, l, ic]) => `<button type="button" data-t="${k}" class="${L.tab === k ? 'on' : ''}"><i class="fas ${ic} ml-1"></i>${l}</button>`).join('') + `<span class="mr-auto text-[11px] text-slate-400">${fa(s.count)} ردیف</span>`;
        document.querySelectorAll('#fl-tabs button').forEach(b => b.onclick = () => { L.tab = b.dataset.t; loadLedger(); });
        if (!L.rows.length) { box.innerHTML = '<div class="p-12 text-center text-slate-400"><i class="fas fa-receipt text-3xl mb-2 block"></i><p class="text-sm">ردیفی پیدا نشد.</p></div>'; return; }
        const isAdmin = META && META.is_admin;
        box.innerHTML = `<table class="w-full text-right text-[11.5px] whitespace-nowrap fh-table no-count"><thead class="sticky top-0 z-10"><tr>
            <th class="p-2.5">تاریخ</th><th class="p-2.5">نوع</th><th class="p-2.5">طرف حساب</th><th class="p-2.5">مبلغ (ریال)</th><th class="p-2.5">روش</th><th class="p-2.5">پیگیری</th>
            <th class="p-2.5">چک</th><th class="p-2.5 text-center">قسط</th><th class="p-2.5">فیش</th><th class="p-2.5">ثبت‌کننده</th><th class="p-2.5">توضیح</th><th class="p-2.5"></th></tr></thead><tbody>
            ${L.rows.map(r => {
                const chq = r.cheque_no ? `<div class="text-[10.5px]"><b>${fa(r.cheque_no)}</b> ${esc(r.cheque_bank || '')}${r.cheque_due_jalali ? ` · سررسید ${fa(r.cheque_due_jalali)}` : ''}</div>
                    ${r.status === 'ACTIVE' && (r.is_new || r.direction === 'IN') ? `<select class="fl-chq border rounded px-1 py-0.5 text-[10px] mt-0.5 ${(CHQ[r.cheque_status] || ['', 'bg-slate-50'])[1]}" data-id="${r.id}" data-kind="${r.is_new ? 'R' : 'P'}">${Object.entries(CHQ).map(([k, v]) => `<option value="${k}" ${r.cheque_status === k ? 'selected' : ''}>${v[0]}</option>`).join('')}</select>` : `<span class="text-[10px]">${(CHQ[r.cheque_status] || [''])[0]}</span>`}` : '<span class="text-slate-300">—</span>';
                return `<tr class="border-t border-slate-100 ${r.status === 'VOID' ? 'opacity-50 line-through' : 'hover:bg-slate-50'}">
                    <td class="p-2.5 font-bold">${fa(r.paid_jalali) || '—'}</td>
                    <td class="p-2.5"><span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${r.direction === 'IN' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-800'}">${r.direction === 'IN' ? 'دریافت' : 'پرداخت به بیمه‌گر'}</span>
                        <p class="text-[9.5px] text-slate-400 mt-0.5">${r.sources === 'C' ? 'شرکتی' : (r.sources === 'P' ? 'کارکنان' : 'کارکنان و شرکتی')}${r.is_new ? '' : ' · ثبتِ قبلی'}</p></td>
                    <td class="p-2.5"><b>${esc(r.party || '—')}</b>${r.insurer_fa && r.direction === 'OUT' ? `<p class="text-[10px] text-slate-400">${esc(r.insurer_fa)}</p>` : ''}</td>
                    <td class="p-2.5 font-black ${r.direction === 'IN' ? 'text-rose-700' : 'text-amber-800'}">${num(r.amount)}</td>
                    <td class="p-2.5">${esc(r.method_fa)}</td>
                    <td class="p-2.5" dir="ltr">${esc(r.reference_no || '—')}</td>
                    <td class="p-2.5">${chq}</td>
                    <td class="p-2.5 text-center">${fa(r.items_count)}</td>
                    <td class="p-2.5">${(r.files || []).map((f, i) => `<a href="${filePath(f)}" target="_blank" class="text-blue-600 hover:underline ml-1"><i class="fas fa-paperclip"></i>${fa(i + 1)}</a>`).join('') || '<span class="text-slate-300">—</span>'}</td>
                    <td class="p-2.5 text-[11px]"><b class="text-slate-700">${esc(r.created_by_name || '—')}</b>${r.status === 'VOID' && r.voided_by_name ? `<p class="text-[10px] text-red-500 no-underline">باطل: ${esc(r.voided_by_name)}</p>` : ''}</td>
                    <td class="p-2.5 max-w-[220px] truncate" title="${esc(r.note || '')}">${esc(r.note || '')}</td>
                    <td class="p-2.5"><div class="flex gap-1">${r.is_new ? `<button class="fl-view w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600" data-id="${r.id}" title="اقلامِ این سند"><i class="fas fa-list"></i></button>` : ''}
                        ${isAdmin && r.status === 'ACTIVE' ? `<button class="fl-void w-7 h-7 rounded-lg bg-red-50 hover:bg-red-100 text-red-600" data-id="${r.id}" data-kind="${r.is_new ? 'R' : esc(r.legacy_kind || '')}" data-dir="${r.direction}" title="باطل‌کردن"><i class="fas fa-ban"></i></button>` : ''}</div></td>
                </tr>`;
            }).join('')}</tbody></table>`;
        box.querySelectorAll('.fl-chq').forEach(s => s.onchange = async () => {
            const d = await api(null, {action: 'ledger_cheque_status', id: s.dataset.id, kind: s.dataset.kind, status: s.value});
            toast(d.ok ? 'وضعیتِ چک به‌روز شد.' : (d.error || 'خطا'), d.ok ? 'success' : 'error');
            loadLedger();
        });
        box.querySelectorAll('.fl-void').forEach(b => b.onclick = () => voidDoc(b.dataset.kind, b.dataset.id, b.dataset.dir));
        box.querySelectorAll('.fl-view').forEach(b => b.onclick = () => openReceipt(b.dataset.id));
    }
    async function openReceipt(id) {
        const el = modal('fl-receipt', '<p class="p-10 text-center text-slate-400 text-xs">در حال بارگذاری...</p>');
        const d = await api({action: 'ledger_receipt', id});
        if (!d.ok) { el.querySelector('.modal-content').innerHTML = `<p class="p-10 text-center text-red-500 text-xs">${esc(d.error || 'خطا')}</p>`; return; }
        const r = d.receipt, isIn = r.direction === 'IN';
        el.querySelector('.modal-content').innerHTML = `
            <div class="p-5 text-white" style="background:${isIn ? 'linear-gradient(120deg,#be123c,#f43f5e)' : 'linear-gradient(120deg,#92400e,#f59e0b)'}">
                <button type="button" onclick="document.getElementById('fl-receipt').classList.remove('active')" class="absolute top-4 left-4 text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
                <p class="text-[11px] opacity-85">سندِ شماره‌ی ${fa(r.id)} · ${fa(r.paid_jalali)} · ${esc(r.method_fa)}${r.status === 'VOID' ? ' · باطل‌شده' : ''}</p>
                <h3 class="text-lg font-black">${isIn ? 'دریافت از' : 'پرداخت به'} ${esc(r.party || '')} — ${num(r.amount)} ریال</h3>
            </div>
            <div class="p-5 space-y-3">
                ${r.cheque_no ? `<p class="text-[12px] bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">چک ${fa(r.cheque_no)} ${esc(r.cheque_bank || '')} · سررسید ${fa(r.cheque_due_jalali || '')} · ${(CHQ[r.cheque_status] || [''])[0]}</p>` : ''}
                ${r.reference_no ? `<p class="text-[12px]">شماره پیگیری: <b dir="ltr">${esc(r.reference_no)}</b></p>` : ''}
                <p class="text-[12px] text-slate-600"><i class="fas fa-user-pen ml-1 text-slate-400"></i>ثبت‌کننده: <b>${esc(r.created_by_name || 'نامشخص')}</b>${r.created_at_j ? ` | ${fa(r.created_at_j)}` : ''}${r.status === 'VOID' ? ` | <span class="text-red-600">باطل‌شده توسطِ ${esc(r.voided_by_name || 'نامشخص')}</span>` : ''}</p>
                ${r.note ? `<p class="text-[12px] text-slate-600">${esc(r.note)}</p>` : ''}
                <div class="overflow-x-auto border border-slate-100 rounded-xl"><table class="w-full text-[11.5px] text-right whitespace-nowrap no-count"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-2">بیمه‌گذار</th><th class="p-2">شرکت</th><th class="p-2">پلاک</th><th class="p-2">بیمه‌نامه</th><th class="p-2 text-center">قسط</th><th class="p-2">مبلغ</th></tr></thead>
                    <tbody>${(d.lines || []).map(l => `<tr class="border-t border-slate-100"><td class="p-2 font-bold">${esc(l.insured)}</td><td class="p-2">${esc(l.company_name)}</td><td class="p-2">${plate(l.plate)}</td><td class="p-2" dir="ltr">${esc(l.policy_number)}</td><td class="p-2 text-center">${fa(l.inst_number)}</td><td class="p-2 font-black">${num(l.amount)}</td></tr>`).join('')}</tbody></table></div>
                ${(r.files || []).length ? `<div class="flex flex-wrap gap-2">${r.files.map((f, i) => `<a href="${filePath(f)}" target="_blank" class="text-[11px] font-bold bg-blue-50 text-blue-700 px-3 py-1.5 rounded-lg"><i class="fas fa-paperclip ml-1"></i>فایل ${fa(i + 1)}</a>`).join('')}</div>` : ''}
            </div>`;
    }

    // =================================================================
    //  پیگیری: کدِ رهگیریِ قسط / شناسه‌ی درخواست / شماره‌ی پرونده / شماره‌ی بیمه‌نامه
    // =================================================================
    const REQ_ST = {NEW: 'جدید', DOCS_PENDING: 'در انتظار مدارک', DOCS_REVIEW: 'در حال بررسی', READY_FOR_ISSUE: 'آماده‌ی صدور', ISSUED: 'صادر شده', CANCELLED: 'لغو شده'};
    function initTracking() {
        const root = document.getElementById('trk-root');
        if (!root) return;
        meta();
        if (!root.dataset.built) {
            root.dataset.built = '1';
            root.innerHTML = `
            <div class="card p-4">
                <div class="flex flex-col md:flex-row gap-2">
                    <textarea id="trk-q" rows="2" dir="ltr" placeholder="7041286026   43233   BM-11" class="flex-1 border-2 border-slate-200 focus:border-indigo-400 rounded-xl px-3 py-2 text-sm font-mono"></textarea>
                    <button type="button" id="trk-go" class="text-white rounded-xl px-6 py-2 text-sm font-black" style="background:linear-gradient(120deg,#4338ca,#6366f1)"><i class="fas fa-magnifying-glass ml-1"></i>پیگیری</button>
                </div>
            </div>
            <div id="trk-out" class="space-y-4"></div>`;
            const go = () => runTracking();
            document.getElementById('trk-go').onclick = go;
            document.getElementById('trk-q').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); go(); } });
        }
        setTimeout(() => { const q = document.getElementById('trk-q'); if (q) q.focus(); }, 50);
    }
    function instMiniTable(rows) {
        if (!rows.length) return '<p class="text-[11px] text-slate-400">قسطی ساخته نشده.</p>';
        return `<div class="overflow-x-auto border-2 border-slate-200 rounded-xl"><table class="w-full text-[11.5px] text-right whitespace-nowrap no-count trk-table"><thead><tr>
            <th>کد رهگیری</th><th>پلاک</th><th>بیمه‌نامه</th><th class="text-center">قسط</th><th>سررسید</th><th>مبلغ</th><th>دریافت‌شده</th><th>مانده</th><th>به بیمه‌گر</th><th>مرحله</th><th></th></tr></thead><tbody>
            ${rows.map(r => { const st = STAGE[r.stage] || STAGE.US; return `<tr>
                <td class="font-mono text-[10.5px]" dir="ltr">${esc(r.tracking_code || '')}</td><td>${plate(r.plate)}</td><td dir="ltr" class="font-mono text-[10.5px]">${esc(r.policy_number || '—')}</td>
                <td class="text-center font-black">${fa(r.inst_number)}</td><td class="${r.overdue ? 'text-red-600 font-black' : ''}">${fa(r.due_jalali)}</td><td class="font-bold">${num(r.amount)}</td>
                <td class="text-emerald-700">${num(r.paid)}</td><td class="${r.remaining ? 'text-rose-600 font-bold' : 'text-slate-300'}">${num(r.remaining)}</td><td class="text-indigo-700">${num(r.paid_insurer)}</td>
                <td><span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${st[1]}">${st[0]}</span></td>
                <td><div class="flex gap-1"><button type="button" class="trk-inst w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600" data-k="${r.source}:${r.id}" title="جزئیات و سابقه"><i class="fas fa-circle-info"></i></button>
                    ${r.receipt ? `<a href="${filePath(r.receipt.file)}" target="_blank" class="h-7 px-2 inline-flex items-center gap-1 rounded-lg bg-sky-50 hover:bg-sky-100 text-sky-700 text-[10.5px] font-bold"><i class="fas fa-receipt"></i>فیش</a>` : ''}</div></td></tr>`; }).join('')}
            </tbody></table></div>`;
    }
    function sumChips(s) {
        if (!s || !s.count) return '';
        return `<div class="flex flex-wrap gap-2 text-[11px] font-bold">
            <span class="bg-slate-100 rounded-full px-2.5 py-1">${fa(s.count)} قسط | ${num(s.amount)} ریال</span>
            <span class="bg-emerald-50 text-emerald-700 rounded-full px-2.5 py-1">دریافت‌شده ${num(s.paid)}</span>
            <span class="bg-rose-50 text-rose-700 rounded-full px-2.5 py-1">مانده ${num(s.remaining)}</span>
            <span class="bg-indigo-50 text-indigo-700 rounded-full px-2.5 py-1">پرداخت به بیمه‌گر ${num(s.paid_insurer)}</span></div>`;
    }
    async function runTracking() {
        const out = document.getElementById('trk-out');
        const q = document.getElementById('trk-q').value.trim();
        if (!q) return toast('کدی وارد نشده.', 'warning');
        out.innerHTML = '<p class="p-10 text-center text-slate-400 text-xs">در حال جستجو...</p>';
        const d = await api({action: 'track', q});
        if (!d.ok) { out.innerHTML = `<p class="p-10 text-center text-red-500 text-xs">${esc(d.error || 'خطا')}</p>`; return; }
        const instIndex = {};
        out.innerHTML = d.results.map(res => {
            if (!res.matches.length) return `<div class="card p-4 border-2 border-dashed border-slate-200 text-[12px] text-slate-500"><b class="font-mono" dir="ltr">${esc(res.code)}</b> — چیزی پیدا نشد.</div>`;
            return res.matches.map(m => {
                if (m.type === 'installment') {
                    const r = m.row; instIndex[r.source + ':' + r.id] = r;
                    const st = STAGE[r.stage] || STAGE.US;
                    return `<div class="card overflow-hidden border-2 border-indigo-100">
                        <div class="px-4 py-3 text-white flex flex-wrap items-center gap-2" style="background:linear-gradient(120deg,#312e81,#4f46e5)">
                            <span class="text-[10.5px] bg-white/20 rounded-full px-2 py-0.5">قسط</span><b class="font-mono" dir="ltr">${esc(res.code)}</b>
                            <b>${esc(r.insured)}</b><span class="opacity-80 text-[12px]">${esc(r.company_name)}</span>
                            <span class="mr-auto text-[10.5px] font-bold px-2 py-1 rounded-full bg-white/20">${st[0]}</span></div>
                        <div class="p-4 space-y-3">${instBodyHtml(r, r.history || [])}</div></div>`;
                }
                (m.installments || []).forEach(r => { instIndex[r.source + ':' + r.id] = r; });
                if (m.type === 'company_request') {
                    return `<div class="card overflow-hidden border-2 border-cyan-100">
                        <div class="px-4 py-3 text-white flex flex-wrap items-center gap-2" style="background:linear-gradient(120deg,#155e75,#0891b2)">
                            <span class="text-[10.5px] bg-white/20 rounded-full px-2 py-0.5">درخواست شرکتی</span><b>شناسه‌ی ${fa(m.ref_code || m.id)}</b>
                            <b>${esc(m.company_name)}</b><span class="opacity-85 text-[12px]">${esc(m.request_kind_fa)} | بیمه ${m.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد'} | ثبت ${fa(m.created_jalali)}</span>
                            <span class="mr-auto text-[10.5px] font-bold px-2 py-1 rounded-full bg-white/20">${REQ_ST[m.status] || esc(m.status)}</span></div>
                        <div class="p-4 space-y-3">
                            ${m.requested_counts_fa ? `<p class="text-[12px] text-indigo-700 font-bold">تعدادِ درخواستی: ${esc(m.requested_counts_fa)}</p>` : ''}
                            ${m.request_text ? `<p class="text-[12px] text-slate-600">${esc(m.request_text)}</p>` : ''}
                            <div class="overflow-x-auto border-2 border-slate-200 rounded-xl"><table class="w-full text-[11.5px] text-right whitespace-nowrap no-count trk-table"><thead><tr><th>#</th><th>پلاک / شاسی</th><th>نوع</th><th>خودرو</th><th>وضعیت</th><th>بیمه‌نامه</th><th>حق بیمه</th><th>انقضای قبلی</th></tr></thead><tbody>
                                ${m.rows.map((x, i) => `<tr><td>${fa(i + 1)}</td><td>${x.plate && /ایران/.test(x.plate) ? plate(x.plate) : `<span dir="ltr">${esc(x.plate || x.chassis_no || '—')}</span>`}</td><td>${x.insurance_type === 'BODY' ? 'بدنه' : 'ثالث'}</td><td>${esc(x.car_name || '')}</td>
                                    <td><span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${x.status === 'ISSUED' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}">${esc(x.status_fa)}</span></td>
                                    <td dir="ltr" class="font-mono text-[10.5px]">${esc(x.policy_number || '—')}</td><td>${x.premium ? num(x.premium) : '—'}</td><td>${fa(x.expiry_jalali) || '—'}</td></tr>`).join('') || '<tr><td colspan="8" class="text-center text-slate-400">ردیفی ثبت نشده.</td></tr>'}
                            </tbody></table></div>
                            <p class="text-xs font-black text-slate-600"><i class="fas fa-list-ol ml-1"></i>اقساط</p>${sumChips(m.inst_summary)}${instMiniTable(m.installments || [])}
                            <button type="button" class="trk-open-req text-[11px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-2 rounded-xl" data-id="${m.id}"><i class="fas fa-up-right-from-square ml-1"></i>بازکردنِ درخواست</button>
                        </div></div>`;
                }
                return `<div class="card overflow-hidden border-2 border-sky-100">
                    <div class="px-4 py-3 text-white flex flex-wrap items-center gap-2" style="background:linear-gradient(120deg,#0c4a6e,#0284c7)">
                        <span class="text-[10.5px] bg-white/20 rounded-full px-2 py-0.5">درخواست کارکنان</span><b>شناسه‌ی ${fa(m.ref_code || '')}</b><span class="font-mono text-[11px] opacity-85" dir="ltr">${esc(m.unique_code || '')}</span>
                        <b>${esc(m.insured)}</b><span class="opacity-85 text-[12px]">${esc(m.company_name || '')}${m.personnel_code ? ' | کد پرسنلی ' + fa(m.personnel_code) : ''}</span>
                        <span class="mr-auto text-[10.5px] font-bold px-2 py-1 rounded-full bg-white/20">${esc(m.status_fa)}</span></div>
                    <div class="p-4 space-y-3">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-[11.5px]">
                            <div class="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5"><p class="text-[9.5px] text-slate-400">پلاک</p>${plate(m.plate)}</div>
                            <div class="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5"><p class="text-[9.5px] text-slate-400">نوع</p><b>${m.insurance_type === 'BODY' ? 'بدنه' : 'ثالث'}</b></div>
                            <div class="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5"><p class="text-[9.5px] text-slate-400">بیمه‌نامه</p><b dir="ltr">${esc(m.policy_number || '—')}</b></div>
                            <div class="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5"><p class="text-[9.5px] text-slate-400">حق بیمه</p><b>${m.premium ? num(m.premium) : '—'}</b></div>
                        </div>
                        <p class="text-xs font-black text-slate-600"><i class="fas fa-list-ol ml-1"></i>اقساط</p>${sumChips(m.inst_summary)}${instMiniTable(m.installments || [])}
                    </div></div>`;
            }).join('');
        }).join('');
        out.querySelectorAll('.trk-inst').forEach(b => b.onclick = () => { const r = instIndex[b.dataset.k]; if (r) openInstDetail(r); });
        out.querySelectorAll('.trk-open-req').forEach(b => b.onclick = () => { if (window.openCompanyRequestDetail) openCompanyRequestDetail(Number(b.dataset.id)); });
        bindHistVoid(out, runTracking);
    }

    window.FinHub = {initInstallments, initLedger, initTracking, reloadInstallments: loadInstallments, openPayDialog, openInstDetail, previewFile, copyText, STAGE};
})();
