// فایل: fin-contracts.js
// ۱) «مالی ← قراردادها»: تعریفِ قراردادهای پرداخت (شماره، نام، نقد/اقساط، تعداد قسط، سررسیدِ اول) + قراردادِ کارکنان
// ۲) بخشِ «مالی و روشِ پرداخت» داخلِ پاپ‌آپِ صدورِ هر بیمه‌نامه (شرکتی / کارکنان): روشِ پیش‌فرض، تغییرِ روش،
//    قراردادِ خوانده‌شده از بیمه‌نامه، مغایرت‌گیری با فیش‌ها و اقساطِ ساخته‌شده با جزئیاتِ هر فیش.
//    هر عنصرِ دارای data-finplan="C:12" یا "P:34" خودکار به این بخش تبدیل می‌شود.
(function () {
    'use strict';
    const API = 'api/fin_plan_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = n => fa(Number(n || 0).toLocaleString('en-US'));
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : alert(m));
    const fileHref = p => p ? '../' + String(p).split('/').map(encodeURIComponent).join('/') : '';
    async function api(action, body = {}) {
        try {
            const r = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))});
            return await r.json();
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور'}; }
    }

    function css() {
        if (document.getElementById('fc-css')) return;
        const st = document.createElement('style');
        st.id = 'fc-css';
        st.textContent = `
        .fc-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:14px;box-shadow:0 4px 14px -8px rgba(15,23,42,.08)}
        .fc-in{border:1.5px solid #e2e8f0;border-radius:11px;padding:7px 10px;font-size:12px;background:#fff;width:100%}
        .fc-in:focus{outline:none;border-color:#818cf8;box-shadow:0 0 0 3px rgba(129,140,248,.18)}
        .fc-lbl{display:block;font-size:10.5px;font-weight:800;color:#64748b;margin-bottom:3px}
        .fc-btn{border:0;border-radius:11px;padding:7px 12px;font-weight:900;font-size:11.5px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
        .fc-btn.p{background:#4f46e5;color:#fff}.fc-btn.s{background:#f1f5f9;color:#334155}.fc-btn.r{background:#fff1f2;color:#e11d48}.fc-btn.g{background:#059669;color:#fff}.fc-btn.a{background:#f59e0b;color:#fff}
        .fc-seg{display:inline-flex;background:#f1f5f9;border-radius:12px;padding:3px;gap:3px;flex-wrap:wrap}
        .fc-seg button{border:0;background:transparent;border-radius:9px;padding:6px 10px;font-size:11px;font-weight:800;color:#64748b;cursor:pointer}
        .fc-seg button.on{background:#fff;color:#4338ca;box-shadow:0 2px 6px -2px rgba(15,23,42,.2)}
        .fc-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:10px;font-weight:900;white-space:nowrap}
        .fc-tbl{width:100%;font-size:11px;border-collapse:separate;border-spacing:0}
        .fc-tbl th{background:#f8fafc;color:#64748b;font-weight:800;text-align:right;padding:7px;border-bottom:1px solid #e2e8f0;white-space:nowrap}
        .fc-tbl td{padding:7px;border-bottom:1px solid #f1f5f9;vertical-align:top}
        .fc-alert{border-radius:14px;padding:10px 12px;font-size:11.5px;line-height:1.9}
        .fc-alert.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.fc-alert.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
        .fc-alert.bad{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}.fc-alert.info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
        .fc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        @media (min-width: 900px){.fc-grid.c4{grid-template-columns:repeat(4,minmax(0,1fr))}}
        .fc-ccard{border:1px solid #e2e8f0;border-radius:16px;padding:12px;background:linear-gradient(180deg,#fff,#f8fafc)}
        .fc-ccard.off{opacity:.55}
        `;
        document.head.appendChild(st);
    }
    const typeFa = t => t === 'CASH' ? 'نقد' : 'اقساط';

    // =================================================================
    //  صفحه‌ی قراردادها
    // =================================================================
    let page = null;
    async function renderPage(root) {
        css();
        page = root;
        root.innerHTML = '<div class="p-10 text-center text-slate-400"><i class="fas fa-spinner fa-spin text-2xl"></i></div>';
        const d = await api('contracts');
        if (!d.ok) { root.innerHTML = `<div class="fc-card text-rose-600">${esc(d.error)}</div>`; return; }
        const pc = d.contracts.find(c => c.id === d.personnel_contract_id), cdc = d.contracts.find(c => c.id === d.company_default_contract_id);
        const opt = (aud, sel) => d.contracts.filter(c => c.is_active && c.audience !== aud).map(c => `<option value="${c.id}" ${c.id === sel ? 'selected' : ''}>${esc(c.name)}${c.contract_key ? ' (' + esc(c.contract_key) + ')' : ''} — ${c.pay_type === 'CASH' ? 'نقد' : fa(c.installment_count) + ' قسط'}</option>`).join('');
        root.innerHTML = `
        <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
            <div class="flex items-center gap-3"><span class="w-11 h-11 rounded-2xl text-white flex items-center justify-center shadow-lg" style="background:linear-gradient(135deg,#4338ca,#7c3aed)"><i class="fas fa-file-contract"></i></span>
            <div><h1 class="text-lg font-black text-slate-800">قراردادهای پرداخت</h1><p class="text-[11px] font-bold text-slate-400">هر شرکت طبقِ یکی از این قراردادها پرداخت می‌کند؛ اقساطِ هر بیمه‌نامه از روی قراردادش ساخته و با فیش‌های بیمه‌نامه مغایرت‌گیری می‌شود.</p></div></div>
            <div class="flex gap-2"><button class="fc-btn s" data-slipset><i class="fas fa-receipt"></i> فیش‌های بیمه‌نامه</button>
            ${d.can_edit ? '<button class="fc-btn p" data-new><i class="fas fa-plus"></i> قراردادِ جدید</button>' : ''}</div>
        </div>
        <div class="fc-card mb-4" style="background:linear-gradient(135deg,#eef2ff,#fff)">
            <div class="flex items-center gap-2 flex-wrap mb-2"><i class="fas fa-users text-indigo-500"></i><b class="text-sm text-slate-800">کارکنان (کسر از حقوق)</b>
                <span class="fc-pill" style="background:${d.personnel_use_contract ? '#e0e7ff;color:#4338ca' : '#f1f5f9;color:#475569'}">${d.personnel_use_contract && pc ? 'طبقِ قرارداد ' + esc(pc.contract_key || pc.name) : 'طبقِ تنظیماتِ مالیِ کارکنان (' + fa(d.personnel_settings.count) + ' قسط)'}</span></div>
            <div class="flex flex-wrap gap-2 items-end">
                <label class="flex items-center gap-2 text-xs font-bold text-slate-600"><input type="checkbox" data-puse ${d.personnel_use_contract ? 'checked' : ''} ${d.can_edit ? '' : 'disabled'}> اقساطِ کارکنان طبقِ قرارداد حساب شود</label>
                <select class="fc-in" style="max-width:340px" data-pcid ${d.can_edit ? '' : 'disabled'}><option value="0">— انتخابِ قرارداد —</option>${opt('COMPANY', d.personnel_contract_id)}</select>
                ${d.can_edit ? '<button class="fc-btn s" data-psave><i class="fas fa-floppy-disk"></i> ذخیره</button>' : ''}
            </div>
            <p class="text-[10.5px] text-slate-500 mt-2">اگر تیکِ «طبقِ قرارداد» را بردارید، اقساطِ کارکنان مثلِ قبل طبقِ «تنظیمات مالی» (تعداد قسط و روشِ رُند کردن) ساخته می‌شود. برای هر بیمه‌نامه هم در پاپ‌آپِ صدور می‌شود روشِ دیگری انتخاب کرد.</p>
        </div>
        <div class="fc-card mb-4" style="background:linear-gradient(135deg,#ecfeff,#fff)">
            <div class="flex items-center gap-2 flex-wrap mb-2"><i class="fas fa-building text-cyan-600"></i><b class="text-sm text-slate-800">شرکت‌ها (پیش‌فرض)</b>
                <span class="fc-pill" style="background:${cdc ? '#cffafe;color:#0e7490' : '#f1f5f9;color:#475569'}">${cdc ? 'طبقِ قرارداد ' + esc(cdc.contract_key || cdc.name) + ' (' + (cdc.pay_type === 'CASH' ? 'نقد' : fa(cdc.installment_count) + ' قسط') + ')' : 'هر شرکت طبقِ تنظیماتِ خودش'}</span>
                <span class="text-[10.5px] text-slate-500">${fa(d.companies_without)} شرکت قراردادِ اختصاصی ندارند</span></div>
            <div class="flex flex-wrap gap-2 items-end">
                <select class="fc-in" style="max-width:340px" data-cdid ${d.can_edit ? '' : 'disabled'}><option value="0">— بدونِ پیش‌فرض (مثلِ قبل) —</option>${opt('PERSONNEL', d.company_default_contract_id)}</select>
                ${d.can_edit ? '<button class="fc-btn s" data-cdsave><i class="fas fa-floppy-disk"></i> ذخیره</button>' : ''}
            </div>
            <p class="text-[10.5px] text-slate-500 mt-2">برای شرکت‌هایی که در فرمِ شرکت قراردادِ جداگانه ندارند. شرکتی که قراردادِ خودش را دارد، طبقِ همان حساب می‌شود.
                <br><b>مثال:</b> دو قرارداد با شماره‌ی ۵۰۵۱ بسازید: «۵۰۵۱ کارکنان» با ۱۲ قسط و «برای چه کسانی = کارکنان»، و «۵۰۵۱ شرکتی» با ۹ قسط و «برای چه کسانی = شرکت‌ها». اولی را بالا برای کارکنان و دومی را این‌جا پیش‌فرضِ شرکت‌ها کنید.</p>
        </div>
        <div class="grid gap-3" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))">
            ${d.contracts.map(c => `<div class="fc-ccard ${c.is_active ? '' : 'off'}">
                <div class="flex items-start gap-2"><span class="fc-pill" style="background:${c.pay_type === 'CASH' ? '#dcfce7;color:#15803d' : '#e0e7ff;color:#4338ca'}">${typeFa(c.pay_type)}</span>
                    <div class="flex-1 min-w-0"><b class="text-sm text-slate-800 block truncate">${esc(c.name)}</b><span class="text-[11px] text-slate-500 font-mono" dir="ltr">${esc(c.contract_no || 'بدونِ شماره')}</span></div>
                    <span class="fc-pill" style="background:${c.audience === 'PERSONNEL' ? '#e0e7ff;color:#4338ca' : c.audience === 'COMPANY' ? '#cffafe;color:#0e7490' : '#f1f5f9;color:#475569'}" title="برای چه کسانی">${c.audience === 'ALL' ? 'همه' : esc(c.audience_fa)}</span>
                    ${c.contract_key ? `<span class="fc-pill" style="background:#0f172a;color:#fff" title="کلیدِ قرارداد (در شماره‌ی بیمه‌نامه هم می‌آید)">${fa(c.contract_key)}</span>` : '<span class="fc-pill" style="background:#fef3c7;color:#92400e">بدونِ شماره</span>'}</div>
                <div class="text-[11px] text-slate-600 mt-2 leading-6">${c.pay_type === 'CASH' ? 'یک‌جا' : fa(c.installment_count) + ' قسط، هر ' + fa(c.interval_months) + ' ماه'} · سررسیدِ اول ${c.first_due_months || c.first_due_days ? fa(c.first_due_months) + ' ماه' + (c.first_due_days ? ' و ' + fa(c.first_due_days) + ' روز' : '') + ' بعد از صدور' : 'همان روزِ صدور'}
                    <br>${c.id === d.personnel_contract_id ? '<span class="text-indigo-600 font-bold">قراردادِ کارکنان</span> · ' : ''}${c.id === d.company_default_contract_id ? '<span class="text-cyan-700 font-bold">پیش‌فرضِ شرکت‌ها</span> · ' : ''}${fa(c.companies)} شرکت${c.note ? '<br><span class="text-slate-400">' + esc(c.note) + '</span>' : ''}</div>
                ${d.can_edit ? `<div class="flex gap-2 mt-2"><button class="fc-btn s" data-edit="${c.id}"><i class="fas fa-pen"></i> ویرایش</button><button class="fc-btn r" data-del="${c.id}"><i class="fas fa-trash"></i></button></div>` : ''}
            </div>`).join('') || '<p class="text-sm text-slate-400">قراردادی تعریف نشده است.</p>'}
        </div>`;
        const nb = root.querySelector('[data-new]');
        if (nb) nb.onclick = () => contractForm(null);
        root.querySelector('[data-slipset]').onclick = () => slipSettings();
        root.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => contractForm(d.contracts.find(c => c.id === Number(b.dataset.edit))));
        root.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
            if (window.uiConfirm && !(await uiConfirm('حذفِ قرارداد', 'این قرارداد حذف شود؟', {danger: true}))) return;
            const r = await api('contract_delete', {id: Number(b.dataset.del)});
            toast(r.ok ? 'قرارداد حذف شد.' : r.error, r.ok ? 'success' : 'error');
            if (r.ok) renderPage(root);
        });
        const cds = root.querySelector('[data-cdsave]');
        if (cds) cds.onclick = async () => {
            const r = await api('company_default_save', {contract_id: Number(root.querySelector('[data-cdid]').value)});
            toast(r.ok ? 'قراردادِ پیش‌فرضِ شرکت‌ها ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
            if (r.ok) { renderPage(root); window.dispatchEvent(new CustomEvent('fin:contracts-changed')); }
        };
        const ps = root.querySelector('[data-psave]');
        if (ps) ps.onclick = async () => {
            const r = await api('personnel_contract_save', {contract_id: Number(root.querySelector('[data-pcid]').value), use_contract: root.querySelector('[data-puse]').checked});
            toast(r.ok ? 'تنظیمِ کارکنان ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
            if (r.ok) renderPage(root);
        };
    }
    function contractForm(c) {
        c = c || {pay_type: 'INSTALLMENT', installment_count: 9, first_due_months: 1, first_due_days: 0, interval_months: 1, split_method: 'mamut', is_active: 1, audience: 'ALL'};
        const ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;z-index:9500;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px';
        ov.innerHTML = `<div class="fc-card" style="width:min(560px,100%);max-height:92vh;overflow:auto" dir="rtl">
            <div class="flex items-center justify-between mb-3"><b class="text-base text-slate-800"><i class="fas fa-file-contract text-indigo-500 ml-1"></i>${c.id ? 'ویرایشِ قرارداد' : 'قراردادِ جدید'}</b><button class="fc-btn s" data-x>✕</button></div>
            <div class="fc-grid">
                <div><label class="fc-lbl">شماره قرارداد</label><input class="fc-in" data-f="contract_no" dir="ltr" placeholder="5051/30000/1404" value="${esc(c.contract_no || '')}"></div>
                <div><label class="fc-lbl">نام قرارداد *</label><input class="fc-in" data-f="name" value="${esc(c.name || '')}"></div>
                <div style="grid-column:1/-1"><label class="fc-lbl">برای چه کسانی</label><select class="fc-in" data-f="audience">${[['ALL', 'همه (کارکنان و شرکت‌ها)'], ['PERSONNEL', 'فقط کارکنان'], ['COMPANY', 'فقط شرکت‌ها']].map(([k, t]) => `<option value="${k}" ${(c.audience || 'ALL') === k ? 'selected' : ''}>${t}</option>`).join('')}</select>
                    <span class="text-[10px] text-slate-400">با جدا کردنِ گروه، یک شماره‌ی قرارداد (مثلاً ۵۰۵۱) می‌تواند برای کارکنان و شرکت‌ها دو تعداد قسطِ متفاوت داشته باشد.</span></div>
                <div><label class="fc-lbl">نوعِ پرداخت</label><select class="fc-in" data-f="pay_type"><option value="INSTALLMENT" ${c.pay_type !== 'CASH' ? 'selected' : ''}>اقساط</option><option value="CASH" ${c.pay_type === 'CASH' ? 'selected' : ''}>نقد (یک‌جا)</option></select></div>
                <div data-inst><label class="fc-lbl">تعداد قسط</label><input class="fc-in" data-f="installment_count" type="number" min="1" max="60" value="${c.installment_count}"></div>
                <div><label class="fc-lbl">سررسیدِ اول: چند ماه بعد از صدور</label><input class="fc-in" data-f="first_due_months" type="number" min="0" value="${c.first_due_months}"></div>
                <div><label class="fc-lbl">+ چند روز</label><input class="fc-in" data-f="first_due_days" type="number" min="0" value="${c.first_due_days}"></div>
                <div data-inst><label class="fc-lbl">فاصله‌ی اقساط (ماه)</label><input class="fc-in" data-f="interval_months" type="number" min="1" max="12" value="${c.interval_months}"></div>
                <div data-inst><label class="fc-lbl">روشِ تقسیمِ مبلغ</label><select class="fc-in" data-f="split_method"><option value="mamut" ${c.split_method !== 'even' ? 'selected' : ''}>فرمولِ بیمه‌گر (رُند به هزار، اختلاف در قسطِ اول)</option><option value="even" ${c.split_method === 'even' ? 'selected' : ''}>مساوی</option></select></div>
                <div style="grid-column:1/-1"><label class="fc-lbl">توضیح</label><input class="fc-in" data-f="note" value="${esc(c.note || '')}"></div>
                <label class="flex items-center gap-2 text-xs font-bold text-slate-600"><input type="checkbox" data-f="is_active" ${c.is_active ? 'checked' : ''}> فعال</label>
            </div>
            <p class="fc-alert info mt-3 text-[11px]" data-ex></p>
            <div class="flex gap-2 mt-3"><button class="fc-btn p" data-save><i class="fas fa-check"></i> ذخیره</button><button class="fc-btn s" data-x>انصراف</button></div></div>`;
        document.body.appendChild(ov);
        const g = k => ov.querySelector(`[data-f="${k}"]`);
        const upd = () => {
            const cash = g('pay_type').value === 'CASH';
            ov.querySelectorAll('[data-inst]').forEach(x => x.style.display = cash ? 'none' : '');
            const m = Number(en(g('first_due_months').value)) || 0, dd = Number(en(g('first_due_days').value)) || 0, n = Number(en(g('installment_count').value)) || 1;
            ov.querySelector('[data-ex]').innerHTML = `<i class="fas fa-circle-info ml-1"></i>مثال: بیمه‌نامه‌ای که ۱۴۰۵/۰۷/۰۲ صادر شود ${cash ? 'یک‌جا' : fa(n) + ' قسط دارد و'} سررسیدِ ${cash ? 'آن' : 'قسطِ اولش'} ${m || dd ? fa(m) + ' ماه' + (dd ? ' و ' + fa(dd) + ' روز' : '') + ' بعد' : 'همان روز'} است. کلیدِ قرارداد (بخشِ اولِ شماره) با شماره‌ی قراردادِ داخلِ بیمه‌نامه مقایسه می‌شود.`;
        };
        ov.querySelectorAll('input,select').forEach(x => x.addEventListener('input', upd));
        upd();
        ov.querySelectorAll('[data-x]').forEach(b => b.onclick = () => ov.remove());
        ov.querySelector('[data-save]').onclick = async () => {
            const o = {id: c.id || 0};
            ov.querySelectorAll('[data-f]').forEach(x => { o[x.dataset.f] = x.type === 'checkbox' ? (x.checked ? 1 : 0) : en(x.value); });
            const r = await api('contract_save', {contract: o});
            if (!r.ok) return toast(r.error, 'error');
            toast('قرارداد ذخیره شد.', 'success');
            ov.remove();
            if (page) renderPage(page);
            window.dispatchEvent(new CustomEvent('fin:contracts-changed'));
        };
    }

    // =================================================================
    //  بخشِ «مالی و روشِ پرداخت» در پاپ‌آپِ صدور
    // =================================================================
    const STATUS = {
        ok: ['ok', 'fa-circle-check'], no_slips: ['info', 'fa-circle-info'], contract_mismatch: ['bad', 'fa-triangle-exclamation'],
        diff_slips_applied: ['warn', 'fa-scale-unbalanced'], diff_formula_applied: ['warn', 'fa-scale-unbalanced'],
        diff_site_applied: ['info', 'fa-wand-magic-sparkles'], no_slips_site: ['info', 'fa-wand-magic-sparkles'],
    };
    async function mountPlan(el) {
        css();
        const [kind, id] = (el.dataset.finplan || '').split(':');
        if (!id || !Number(id)) { el.innerHTML = ''; return; }
        el.innerHTML = '<p class="text-[11px] text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ خواندنِ روشِ پرداخت...</p>';
        const d = await api('plan_get', {kind, id: Number(id)});
        if (!d.ok) { el.innerHTML = `<p class="text-[11px] text-rose-600">${esc(d.error)}</p>`; return; }
        renderPlan(el, d);
    }
    // وضعیتِ هر قسطِ صادره: پرداخت‌شده / ناقص / سررسیدگذشته / در انتظار + نوع و تاریخِ هر دریافت و پرداخت به بیمه‌گر
    const PAY_ST = {PAID: ['پرداخت‌شده', '#dcfce7', '#15803d', 'fa-circle-check'], PARTIAL: ['پرداختِ ناقص', '#fef3c7', '#92400e', 'fa-circle-half-stroke'],
        OVERDUE: ['سررسید گذشته', '#ffe4e6', '#be123c', 'fa-clock'], UNPAID: ['در انتظارِ پرداخت', '#f1f5f9', '#475569', 'fa-hourglass-half']};
    function payCells(r) {
        const st = PAY_ST[r.pay_status] || PAY_ST.UNPAID;
        const pays = r.payments || [];
        return `<td class="whitespace-nowrap"><span class="fc-pill" style="background:${st[1]};color:${st[2]}"><i class="fas ${st[3]}"></i>${st[0]}</span>
                ${r.pay_status === 'PARTIAL' ? `<div class="text-[10px] text-slate-500 mt-1">مانده ${money(r.remaining)}</div>` : ''}
                ${r.delay_days ? `<div class="text-[10px] text-rose-600 mt-1">${fa(r.delay_days)} روز تأخیر</div>` : ''}</td>
            <td class="text-[10.5px] leading-5">${pays.length ? pays.map(p => `<div class="whitespace-nowrap"><b>${esc(p.method_fa || '—')}</b> · <span>${fa(p.date || '—')}</span>${pays.length > 1 || p.amount !== r.amount ? ' · ' + money(p.amount) : ''}${p.cheque_no ? ` · چک <span dir="ltr">${fa(p.cheque_no)}</span>` : ''}${p.ref ? ` · <span class="text-slate-400" dir="ltr">${fa(p.ref)}</span>` : ''}</div>`).join('') : '<span class="text-slate-400">—</span>'}
                ${r.insurer_paid ? `<div class="text-[10px] text-indigo-600 mt-0.5"><i class="fas fa-building-columns"></i> به بیمه‌گر${r.insurer_paid < r.amount ? ' ' + money(r.insurer_paid) : ''}${r.insurer_date ? ' · ' + fa(r.insurer_date) : ''}</div>` : ''}</td>`;
    }
    function payFoot(rows) {
        const paid = rows.filter(r => r.pay_status === 'PAID').length, over = rows.filter(r => r.pay_status === 'OVERDUE').length;
        const rem = rows.reduce((a, r) => a + Number(r.remaining || 0), 0);
        return `${fa(paid)} قسط پرداخت‌شده${over ? ' · <span class="text-rose-600 font-bold">' + fa(over) + ' سررسید گذشته</span>' : ''}${rem ? ' · مانده ' + money(rem) + ' ریال' : ''}`;
    }
    function renderPlan(el, d) {
        const cu = d.custom || {};
        const mode = cu.mode || 'default';
        const prefer = cu.prefer || 'auto';
        const sd = d.slip_default || {};
        const ck = d.check;
        const st = ck ? (STATUS[ck.status] || ['info', 'fa-circle-info']) : null;
        const diffs = ck && ck.diffs ? ck.diffs : [];
        const diffTxt = x => x.type === 'count' ? `تعداد اقساط: محاسبه ${fa(x.computed)} · فیش ${fa(x.slip)}` : x.type === 'total' ? `جمع: حق بیمه ${money(x.computed)} · فیش‌ها ${money(x.slip)}`
            : x.type === 'amount' ? `قسط ${fa(x.n)}: مبلغِ محاسبه ${money(x.computed)} · فیش ${money(x.slip)}` : `قسط ${fa(x.n)}: سررسیدِ محاسبه ${fa(x.computed)} · فیش ${fa(x.slip)}`;
        const rows = d.issued && d.installments.length ? d.installments : d.preview;
        const dc = d.detected_contract;
        const showPay = !!(d.issued && d.installments.length && rows === d.installments);
        el.innerHTML = `
        <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="text-[11px] font-bold text-slate-500">روشِ فعلی:</span><b class="text-[12px] text-slate-800">${esc(d.plan.label)}</b>
            ${d.plan.custom ? '<span class="fc-pill" style="background:#fef3c7;color:#92400e">تغییرِ دستی برای همین بیمه‌نامه</span>' : '<span class="fc-pill" style="background:#e0e7ff;color:#4338ca">پیش‌فرض ' + (d.kind === 'C' ? 'شرکت' : 'کارکنان') + '</span>'}
            ${d.detected_key ? `<span class="fc-pill" style="background:${d.detected_key === (d.plan.contract_key || d.detected_key) ? '#dcfce7;color:#15803d' : '#ffe4e6;color:#be123c'}" title="از شماره‌ی قرارداد / شماره‌ی بیمه‌نامه"><i class="fas fa-file-signature"></i> قراردادِ بیمه‌نامه: ${fa(d.detected_no || d.detected_key)}</span>` : ''}
        </div>
        ${ck ? `<div class="fc-alert ${st[0]} mb-2"><i class="fas ${st[1]} ml-1"></i>${esc(((ck.messages || [])[0] || '').replace(d.stale || !d.issued ? /(ساخته|ثبت) شد/g : /$^/, '$1 می‌شود'))}
            ${diffs.length ? `<ul class="mt-1 pr-4" style="list-style:disc">${diffs.slice(0, 6).map(x => `<li>${diffTxt(x)}</li>`).join('')}${diffs.length > 6 ? `<li>و ${fa(diffs.length - 6)} مورد دیگر…</li>` : ''}</ul>` : ''}
            ${ck.status === 'contract_mismatch' ? `<div class="flex flex-wrap gap-2 mt-2">
                <button class="fc-btn s" data-q="formula"><i class="fas fa-calculator"></i> بله، با فرمولِ قراردادِ ${d.kind === 'C' ? 'شرکت' : 'کارکنان'} حساب شود</button>
                ${(ck.slips || []).length ? '<button class="fc-btn a" data-q="slips"><i class="fas fa-receipt"></i> طبقِ فیش‌های بیمه‌نامه</button>' : ''}
                ${dc ? `<button class="fc-btn p" data-q="contract" data-cid="${dc.id}"><i class="fas fa-file-contract"></i> طبقِ قراردادِ «${esc(dc.name)}»</button>` : ''}</div>` : ''}</div>` : ''}
        ${d.issued && d.installments.length ? `<div class="fc-alert ${d.applied === 'slips' ? 'warn' : 'ok'} mb-2" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
            <span><i class="fas ${d.applied === 'slips' ? 'fa-receipt' : 'fa-file-contract'} ml-1"></i>${d.applied === 'slips' ? 'اقساطِ ثبت‌شده طبقِ فیش‌های داخلِ فایلِ بیمه‌نامه است.' : 'اقساطِ ثبت‌شده طبقِ قراردادِ پرداخت (' + esc(d.plan.label) + ') است.'}
                ${d.slips_pending ? `<br><span class="text-[10.5px]"><i class="fas fa-spinner fa-spin ml-1"></i>فیشِ سامانه‌ی ${fa(d.slips_pending)} قسط در حالِ ساخت است…</span>` : ''}</span>
            ${d.any_touched ? '<span class="text-[10.5px] text-slate-500"><i class="fas fa-lock ml-1"></i>روی اقساط دریافت/تسویه ثبت شده؛ قابلِ تغییر نیست.</span>'
                : `<button class="fc-btn p" data-tocontract title="اقساط طبقِ قراردادِ پرداختِ همین بیمه‌نامه دوباره ساخته می‌شود؛ فیش‌های قبلی به پوشه‌ی «فیش‌های قدیمی» در همان بایگانی می‌روند و فیشِ هر قسط را سامانه می‌سازد"><i class="fas fa-file-contract"></i> ${d.applied === 'slips' ? 'تبدیل به اقساطِ قرارداد' : 'ساختِ دوباره طبقِ قرارداد'}</button>`}</div>` : ''}
        ${d.issued && d.stale ? `<div class="fc-alert warn mb-2"><i class="fas fa-rotate ml-1"></i>اقساطِ ثبت‌شده با روشِ فعلی (${esc(d.plan.label)}) یکی نیست؛ احتمالاً قراردادِ ${d.kind === 'C' ? 'شرکت' : 'کارکنان'} بعد از صدور عوض شده.
            ${d.any_touched ? '<br><span class="text-[10.5px]">روی بعضی اقساط دریافت/تسویه ثبت شده؛ آن‌ها دست نمی‌خورند.</span>' : ''}
            <div class="mt-2"><button class="fc-btn p" data-regen><i class="fas fa-rotate"></i> ساختِ دوباره‌ی اقساط طبقِ روشِ فعلی</button></div></div>` : ''}
        <details class="mb-2" ${mode !== 'default' || prefer !== 'auto' ? 'open' : ''}><summary class="text-[11px] font-black text-indigo-600 cursor-pointer select-none"><i class="fas fa-sliders ml-1"></i>تغییرِ روشِ پرداختِ همین بیمه‌نامه</summary>
            <div class="mt-2 space-y-2">
                <div class="fc-seg" data-mode>${[['default', 'پیش‌فرض (' + (d.kind === 'C' ? 'قراردادِ شرکت' : 'کارکنان') + ')'], ['contract', 'قراردادِ دیگر'], ['custom', 'روشِ اختصاصی']].map(([k, t]) => `<button type="button" data-m="${k}" class="${mode === k ? 'on' : ''}">${t}</button>`).join('')}</div>
                <div data-box="contract" class="${mode === 'contract' ? '' : 'hidden'}"><select class="fc-in" data-cid>${d.contracts.map(c => `<option value="${c.id}" ${Number(cu.contract_id) === c.id ? 'selected' : ''}>${esc(c.name)}${c.contract_key ? ' (' + esc(c.contract_key) + ')' : ''} — ${esc(c.label.split(' · ').slice(1).join(' · '))}</option>`).join('')}</select></div>
                <div data-box="custom" class="${mode === 'custom' ? '' : 'hidden'} fc-grid c4">
                    <div><label class="fc-lbl">نوع</label><select class="fc-in" data-c="type"><option value="INSTALLMENT" ${cu.type !== 'CASH' ? 'selected' : ''}>اقساط</option><option value="CASH" ${cu.type === 'CASH' ? 'selected' : ''}>نقد</option></select></div>
                    <div><label class="fc-lbl">تعداد قسط</label><input class="fc-in" type="number" min="1" data-c="count" value="${cu.count || d.default.count}"></div>
                    <div><label class="fc-lbl">سررسیدِ اول (ماه بعد)</label><input class="fc-in" type="number" min="0" data-c="first_m" value="${cu.first_m ?? d.default.first_m}"></div>
                    <div><label class="fc-lbl">+ روز</label><input class="fc-in" type="number" min="0" data-c="first_d" value="${cu.first_d ?? d.default.first_d}"></div>
                </div>
                <div class="flex items-center gap-2 flex-wrap"><span class="text-[10.5px] font-bold text-slate-500">وقتی فیش‌ها با محاسبه فرق دارند:</span>
                    <div class="fc-seg" data-prefer>${[['auto', 'پیش‌فرض: ' + (sd.fa || 'طبقِ فیش‌های بیمه‌نامه') + (sd.src === 'company' ? ' (تنظیمِ شرکت)' : ' (تنظیمِ کلیِ صدور)')], ['slips', 'همیشه طبقِ فیش'], ['formula', 'همیشه طبقِ فرمول'],
                        ['site', 'فرق داشت: فیش با سامانه'], ['site_all', 'فرق داشت یا فیش نبود: فیش با سامانه']].map(([k, t]) => `<button type="button" data-p="${k}" class="${prefer === k ? 'on' : ''}">${t}</button>`).join('')}</div></div>
                <div class="flex gap-2"><button class="fc-btn p" data-save><i class="fas fa-rotate"></i> ${d.issued ? 'ذخیره و ساختِ دوباره‌ی اقساط' : 'ذخیره (هنگامِ صدور اعمال می‌شود)'}</button>
                    ${d.custom ? '<button class="fc-btn s" data-reset><i class="fas fa-rotate-left"></i> برگشت به پیش‌فرض</button>' : ''}</div>
            </div></details>
        ${rows && rows.length ? `<div style="overflow:auto;border:1px solid #f1f5f9;border-radius:12px"><table class="fc-tbl"><thead><tr><th>قسط</th><th>سررسید</th><th>مبلغ (ریال)</th>${showPay ? '<th>وضعیت</th><th>نوع و تاریخِ پرداخت</th>' : ''}<th>نوع</th><th>شناسه پرداخت</th><th>حساب / بانک</th><th>فیش</th></tr></thead><tbody>
            ${rows.map(r => { const s = r.slip || {}; return `<tr><td>${fa(r.n)}${r.touched ? ' <i class="fas fa-lock text-slate-300" title="دریافت/تسویه ثبت شده"></i>' : ''}</td><td class="whitespace-nowrap">${fa(r.due)}</td><td class="whitespace-nowrap font-bold">${money(r.amount)}</td>${showPay ? payCells(r) : ''}
                <td>${esc(s.kind || '—')}</td><td class="font-mono" dir="ltr" style="text-align:right">${fa(s.shenase || s.fish || '—')}</td>
                <td class="text-[10.5px]">${s.account ? '<span dir="ltr">' + fa(s.account) + '</span><br>' : ''}<span class="text-slate-500">${esc(s.bank || '')}</span></td>
                <td>${r.slip_file ? `<a class="fc-btn s" style="padding:3px 8px" target="_blank" href="${fileHref(r.slip_file)}" title="${s.site ? 'ساخته‌ی سامانه طبقِ اقساطِ قرارداد' : 'از فایلِ بیمه‌نامه'}"><i class="fas ${s.site ? 'fa-wand-magic-sparkles text-indigo-500' : 'fa-file-invoice text-rose-500'}"></i> ${s.site ? 'فیشِ سامانه' : 'فیش'}</a>` : (s.site ? `<span class="text-[10px] text-indigo-500" title="${d.issued ? 'در حالِ ساخت' : 'بعد از صدور ساخته می‌شود'}">${d.issued ? '<i class="fas fa-spinner fa-spin"></i> ' : ''}فیشِ سامانه</span>` : '—')}</td></tr>`; }).join('')}
            </tbody><tfoot><tr><td colspan="2" class="font-black">جمع (${fa(rows.length)} قسط)</td><td class="font-black whitespace-nowrap">${money(rows.reduce((a, r) => a + Number(r.amount || 0), 0))}</td><td colspan="${showPay ? 6 : 4}" class="text-[10.5px] text-slate-400">${d.issued ? (showPay ? payFoot(rows) : '') : 'پیش‌نمایش؛ اقساطِ واقعی بعد از صدور ساخته می‌شود.'}</td></tr></tfoot></table></div>`
            : `<p class="text-[11px] text-slate-400">${d.premium ? '' : 'حق بیمه هنوز معلوم نیست؛ اقساط بعد از صدور ساخته می‌شود.'}</p>`}`;
        let selMode = mode, selPrefer = prefer;
        el.querySelectorAll('[data-m]').forEach(b => b.onclick = () => { selMode = b.dataset.m; el.querySelectorAll('[data-m]').forEach(x => x.classList.toggle('on', x === b)); el.querySelectorAll('[data-box]').forEach(x => x.classList.toggle('hidden', x.dataset.box !== selMode)); });
        el.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { selPrefer = b.dataset.p; el.querySelectorAll('[data-p]').forEach(x => x.classList.toggle('on', x === b)); });
        const save = async plan => {
            const r = await api('plan_save', {kind: d.kind, id: d.id, plan});
            if (!r.ok) return toast(r.error, 'error');
            toast(r.warning ? r.warning : (d.issued ? 'روشِ پرداخت ذخیره و اقساط دوباره ساخته شد.' : 'روشِ پرداخت ذخیره شد.'), r.warning ? 'warning' : 'success');
            renderPlan(el, r);
        };
        el.querySelector('[data-save]').onclick = () => {
            const plan = {mode: selMode, prefer: selPrefer};
            if (selMode === 'contract') plan.contract_id = Number(el.querySelector('select[data-cid]').value);
            if (selMode === 'custom') el.querySelectorAll('[data-c]').forEach(x => { plan[x.dataset.c] = en(x.value); });
            save(plan);
        };
        const tc = el.querySelector('[data-tocontract]');
        if (tc) tc.onclick = async () => {
            if (!confirm('اقساطِ این بیمه‌نامه طبقِ قراردادِ پرداختش (' + d.plan.label + ') دوباره ساخته شود؟\nفیش‌های قبلی به پوشه‌ی «فیش‌های قدیمی» در همان بایگانی می‌روند و فیشِ هر قسط را سامانه می‌سازد.')) return;
            tc.disabled = true; tc.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حالِ ساخت…';
            const r = await api('plan_to_contract', {kind: d.kind, id: d.id});
            if (!r.ok) { tc.disabled = false; tc.innerHTML = '<i class="fas fa-file-contract"></i> تبدیل به اقساطِ قرارداد'; return toast(r.error, 'error'); }
            toast(r.warning || 'اقساط طبقِ قرارداد ساخته شد؛ فیش‌ها در حالِ ساخت‌اند.', r.warning ? 'warning' : 'success');
            renderPlan(el, r);
        };
        // فیش‌های سامانه بعد از پاسخ ساخته می‌شوند: تا آماده شوند هر چند ثانیه دوباره خوانده می‌شود
        clearTimeout(el._fpPoll);
        if (d.slips_pending && (el._fpTries = (el._fpTries || 0) + 1) <= 40) el._fpPoll = setTimeout(async () => { if (!document.body.contains(el)) return; const x = await api('plan_get', {kind: d.kind, id: d.id}); if (x.ok) renderPlan(el, x); }, 4000);
        else if (!d.slips_pending) el._fpTries = 0;
        const rg = el.querySelector('[data-regen]');
        if (rg) rg.onclick = async () => { const r = await api('plan_regen', {kind: d.kind, id: d.id}); if (!r.ok) return toast(r.error, 'error'); toast(r.warning || 'اقساط دوباره ساخته شد.', r.warning ? 'warning' : 'success'); renderPlan(el, r); };
        const rs = el.querySelector('[data-reset]');
        if (rs) rs.onclick = async () => { const r = await api('plan_reset', {kind: d.kind, id: d.id}); if (r.ok) { toast('به روشِ پیش‌فرض برگشت.', 'success'); renderPlan(el, r); } else toast(r.error, 'error'); };
        el.querySelectorAll('[data-q]').forEach(b => b.onclick = () => {
            const q = b.dataset.q;
            if (q === 'contract') save({mode: 'contract', contract_id: Number(b.dataset.cid), prefer: 'auto'});
            else save({mode: 'default', prefer: q});
        });
    }
    // =================================================================
    //  تنظیمِ کلیِ صدور: «وقتی فیش‌های بیمه‌نامه با اقساطِ قرارداد فرق داشت» — کارکنان و شرکتی جدا
    //  (هر شرکت در فرمِ شرکت می‌تواند تنظیمِ خودش را داشته باشد)
    // =================================================================
    async function slipSettings() {
        css();
        const d = await api('slip_modes');
        if (!d.ok) return toast(d.error, 'error');
        const HINT = {auto: 'اگر شماره‌ی قراردادِ بیمه‌نامه با قرارداد یکی بود، مبلغ و سررسیدِ اقساط طبقِ فیش‌های داخلِ فایلِ بیمه‌نامه ثبت می‌شود (روشِ فعلی).',
            site: 'اگر فیش‌های بیمه‌نامه (تعداد، مبلغ، سررسید یا شماره‌ی قرارداد) با اقساطِ قرارداد فرق داشت، اقساط طبقِ قرارداد ثبت می‌شود و سامانه برای هر قسط فیشِ PDF می‌سازد (حساب، بانک و پرداخت‌کننده از فیش‌های بیمه‌نامه، اگر بود). اگر یکی بود، همان فیش‌های بیمه‌نامه.',
            site_all: 'مثلِ گزینه‌ی قبل؛ و اگر در فایلِ بیمه‌نامه هیچ فیشی نبود هم سامانه طبقِ قرارداد برای هر قسط فیش می‌سازد.'};
        const ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;z-index:9500;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px';
        const sel = {P: d.P, C: d.C, liability_max: Object.assign({P: 10000000000, C: 14000000000}, d.liability_max || {})};
        const ovr = d.company_overrides || {};
        const ovN = Object.values(ovr).reduce((a, b) => a + Number(b || 0), 0);
        const draw = () => {
            ov.innerHTML = `<div class="fc-card" style="width:min(640px,100%);max-height:92vh;overflow:auto" dir="rtl">
            <div class="flex items-center justify-between mb-2"><b class="text-base text-slate-800"><i class="fas fa-sliders text-indigo-500 ml-1"></i>تنظیماتِ صدور: فیش‌ها و سقفِ تعهد</b><button class="fc-btn s" data-x>✕</button></div>
            <p class="text-[11px] text-slate-500 mb-3 leading-6">وقتی فیش‌های داخلِ فایلِ بیمه‌نامه با اقساطی که از قرارداد حساب می‌شود فرق داشت، اقساط و فیش‌ها چطور ثبت شود؟</p>
            ${[['P', 'fa-users', 'کارکنان'], ['C', 'fa-building', 'شرکتی']].map(([k, ic, t]) => `<div class="fc-card mb-3" style="background:linear-gradient(135deg,${k === 'P' ? '#eef2ff' : '#ecfeff'},#fff)">
                <b class="text-sm text-slate-800 block mb-2"><i class="fas ${ic} ml-1 ${k === 'P' ? 'text-indigo-500' : 'text-cyan-600'}"></i>صدورِ ${t}</b>
                <div class="fc-seg" data-k="${k}">${d.modes.map(m => `<button type="button" data-m="${m.k}" class="${sel[k] === m.k ? 'on' : ''}" ${d.can_edit ? '' : 'disabled'}>${esc(m.fa)}</button>`).join('')}</div>
                <p class="text-[10.5px] text-slate-500 mt-2 leading-6">${esc(HINT[sel[k]] || '')}</p>
                ${k === 'C' && ovN ? `<p class="text-[10.5px] text-amber-700 mt-1"><i class="fas fa-circle-info ml-1"></i>${fa(ovN)} شرکت در فرمِ شرکت تنظیمِ اختصاصی دارند و طبقِ همان عمل می‌شود.</p>` : ''}
                <label class="flex items-center gap-2 flex-wrap mt-3 pt-2" style="border-top:1px dashed #e2e8f0"><span class="text-[11px] font-bold text-slate-600"><i class="fas fa-shield-halved ml-1 text-slate-400"></i>بیشترین تعهدِ مالیِ ثالث که ${k === 'P' ? 'کارکنان (ربات، مینی‌اپ و ثبتِ دستی)' : 'شرکت‌ها (پنلِ ما و پنلِ شرکت‌ها)'} می‌بینند و ثبت می‌کنند:</span>
                    <select class="fc-in" style="width:auto" data-lmax="${k}" ${d.can_edit ? '' : 'disabled'}>${(d.liability_all || []).map(v => `<option value="${v}" ${Number(sel.liability_max[k]) === Number(v) ? 'selected' : ''}>${money(v)} ریال</option>`).join('')}</select></label>
            </div>`).join('')}
            <p class="fc-alert info text-[10.5px]"><i class="fas fa-shield-halved ml-1"></i>روی بیمه‌نامه‌هایی که از این به بعد صادر می‌شوند (یا اقساطشان در پاپ‌آپِ صدور دوباره ساخته شود) اعمال می‌شود؛ اقساط و دریافتی‌های فعلی دست نمی‌خورند و فیش‌های اصلیِ بیمه‌نامه هم حذف نمی‌شوند. برای یک بیمه‌نامه هم در «مالی و روشِ پرداخت» می‌شود روشِ دیگری گذاشت.</p>
            <div class="flex gap-2 mt-3">${d.can_edit ? '<button class="fc-btn p" data-save><i class="fas fa-check"></i> ذخیره</button>' : ''}<button class="fc-btn s" data-x>بستن</button></div></div>`;
            ov.querySelectorAll('[data-x]').forEach(b => b.onclick = () => ov.remove());
            ov.querySelectorAll('[data-k] [data-m]').forEach(b => b.onclick = () => { sel[b.closest('[data-k]').dataset.k] = b.dataset.m; draw(); });
            ov.querySelectorAll('[data-lmax]').forEach(x => x.onchange = () => { sel.liability_max[x.dataset.lmax] = Number(x.value); });
            const sv = ov.querySelector('[data-save]');
            if (sv) sv.onclick = async () => {
                const r = await api('slip_modes_save', sel);
                if (!r.ok) return toast(r.error, 'error');
                toast('تنظیماتِ صدور ذخیره شد.', 'success');
                ov.remove();
            };
        };
        draw();
        document.body.appendChild(ov);
    }

    // هر عنصرِ data-finplan که به صفحه اضافه شود خودکار پر می‌شود
    function scan(root) {
        (root.querySelectorAll ? root : document).querySelectorAll('[data-finplan]:not([data-fp-on])').forEach(el => { el.dataset.fpOn = '1'; mountPlan(el); });
    }
    const mo = new MutationObserver(ms => { for (const m of ms) for (const n of m.addedNodes) if (n.nodeType === 1) { if (n.matches && n.matches('[data-finplan]:not([data-fp-on])')) { n.dataset.fpOn = '1'; mountPlan(n); } else if (n.querySelector) scan(n); } });
    const start = () => { mo.observe(document.body, {childList: true, subtree: true}); scan(document); };
    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);

    window.FinContracts = {render: renderPage, api, slipSettings};
    window.FinPlan = {mount: mountPlan};
})();
