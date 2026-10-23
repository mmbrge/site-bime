// فایل: company-manual-request.js
// «ثبت کامل درخواست دستی شرکت» - برای شرکت‌هایی که درخواست را در پنل خودشان ثبت نمی‌کنند
// (یا فقط مدارک یک خودرو را می‌فرستند و می‌گویند صادر کنید). همه‌چیز در یک فرم:
//   شرکت، بیمه‌گر، نوع درخواست، ثالث/بدنه/هر دو، نامه (اختیاری)،
//   ثالث: تعهد مالی برای همه یا تک‌تک پلاک‌ها؛ بدنه: ارزش خودرو + پوشش‌های درخواستی
//   (پیش‌فرض از آخرین درخواست همان شرکت، قابل تغییر، و پوشش متفاوت برای هر پلاک)،
//   و مدارکِ لازمِ هر خودرو طبق نوعش. پوشه‌ی روز داخل پوشه‌ی شرکت همان لحظه ساخته می‌شود.
(function () {
    const API = 'api/company_actions.php';
    const fa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = v => { const d = en(v).replace(/\D/g, ''); return d ? fa(d.replace(/\B(?=(\d{3})+(?!\d))/g, ',')) : ''; };
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const LETTERS = ['الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'د', 'ز', 'س', 'ش', 'ص', 'ط', 'ع', 'ف', 'ق', 'ک', 'گ', 'ل', 'م', 'ن', 'و', 'ه', 'ی', 'D', 'S', 'معلولین', 'تشریفات'];

    let form = null;          // اطلاعات ثابت فرم (شرکت‌ها، پوشش‌ها، مدارک)
    let st = null;            // وضعیتِ فرمِ باز
    let root = null;
    let seq = 0;

    const newRow = type => ({ id: ++seq, type, noPlate: false, moto: false, mcity: '', mnum: '', p1: '', letter: 'الف', p2: '', p4: '', chassis: '', engine: '', isNew: false, carType: '',
        carName: '', expiry: '', liability: '', carValue: '', prevBody: '', skipHealth: !!(st && st.noVisitAll), customCov: false, cov: null,
        refPolicy: '', endorse: '', cancelReason: '', note: '', files: {}, vclass: '', report: null, manualVisit: false, docPages: [], docData: null,
        partyOpen: false, partyMode: '', party: null, reg: null, regSel: new Set(), regApplied: false, regDismiss: '' });
    // سواری/وانت یا سنگین: انتخابِ کاربر، وگرنه حدس از نام خودرو (مثل سرور)
    const HEAVY_RE = /کامیون|کشنده|تریلی|تریلر|اتوبوس|مینی\s*بوس|میدل\s*باس|بوس|سنگین|کمپرسی|تانکر|جرثقیل|لودر|بیل\s*مکانیکی|گریدر|بولدوزر|تراکتور|ماشین\s*آلات|یدک\s*کش/;
    const vclassOf = r => r.vclass || (HEAVY_RE.test(String(r.carName || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک')) ? 'HEAVY' : 'LIGHT');
    // این ردیف بازدید لازم دارد؟ (بدنه، خودروی کارکرده، و «نیاز به بازدید ندارد» تیک نخورده)
    const needsVisit = r => isNew() && r.type === 'BODY' && !r.skipHealth && !r.isNew;
    const VISIT_TYPES = ['health_inspection', 'health_report'];

    function blankState() {
        return { companyId: '', insurer: 'PASARGAD', kind: 'NEW_POLICY', mode: 'THIRDPARTY', text: '', letter: null, noVisitAll: false,
                 liabilityAll: '', liabilityPerRow: false, cov: null, covBaseOnly: false, defaults: null, rows: [newRow('THIRDPARTY')],
                 partyMode: 'COMPANY', party: null,
                 detect: { busy: false, done: 0, total: 0, pages: [] } };
    }

    async function open() {
        if (!form) {
            const d = await post({ action: 'admin_manual_form' });
            if (!d.ok) { toast(d.error || 'خطا در بارگذاری فرم', 'error'); return; }
            form = d;
        }
        st = blankState();
        if (!root) {
            root = document.createElement('div');
            root.className = 'fixed inset-0 z-[1200] bg-slate-900/60 backdrop-blur-sm overflow-y-auto p-3 md:p-6';
            document.body.appendChild(root);
        }
        root.style.display = 'block';
        render();
    }
    function close() { if (root) root.style.display = 'none'; }

    async function post(body) {
        try {
            const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            return await r.json();
        } catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور' }; }
    }

    const types = () => new Set(st.rows.map(r => r.type));
    const isNew = () => st.kind === 'NEW_POLICY';

    // ---------------- رندر ----------------
    function render() {
        const company = (form.companies || []).find(c => String(c.id) === String(st.companyId));
        const allowed = company ? company.allowed_insurers : 'BOTH';
        const insurers = allowed === 'BOTH' ? ['PASARGAD', 'IRAN'] : [allowed];
        if (!insurers.includes(st.insurer)) st.insurer = insurers[0];
        const hasThird = isNew() && types().has('THIRDPARTY');
        const hasBody = isNew() && types().has('BODY');

        root.innerHTML = `<div class="bg-slate-50 rounded-3xl shadow-2xl max-w-6xl mx-auto overflow-hidden">
          <div class="bg-gradient-to-l from-blue-700 via-indigo-700 to-violet-700 text-white p-5 flex justify-between items-start gap-3">
            <div><h2 class="text-lg md:text-xl font-black"><i class="fas fa-file-circle-plus ml-2"></i>ثبت کامل درخواست دستی شرکت</h2>
              <p class="text-[11px] text-white/80 mt-1 leading-6">برای وقتی که شرکت درخواست را در پنل ثبت نمی‌کند یا فقط مدارک خودرو را می‌فرستد. نامه اختیاری است؛ پوشه‌ی امروز داخل پوشه‌ی همان شرکت ساخته می‌شود و بعد از صدور، بایگانی صادره مثل بقیه ساخته می‌شود.</p></div>
            <button data-act="close" class="text-white/80 hover:text-white text-2xl leading-none px-2">×</button>
          </div>
          <div class="p-4 md:p-6 space-y-5">
            <div class="bg-white rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
              <div class="md:col-span-2"><label class="lbl">شرکت *</label>
                <select data-f="companyId" class="inp">${'<option value="">-- انتخاب شرکت --</option>' + (form.companies || []).map(c => `<option value="${c.id}" ${String(c.id) === String(st.companyId) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
              <div><label class="lbl">بیمه‌گر</label><select data-f="insurer" class="inp">${insurers.map(i => `<option value="${i}" ${st.insurer === i ? 'selected' : ''}>${i === 'IRAN' ? 'ایران' : 'پاسارگاد'}</option>`).join('')}</select></div>
              <div><label class="lbl">نوع درخواست</label><select data-f="kind" class="inp">${Object.entries(form.kinds || {}).map(([k, v]) => `<option value="${k}" ${st.kind === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
              ${isNew() ? `<div class="md:col-span-4"><label class="lbl">بیمه‌ی درخواستی</label>
                <div class="grid grid-cols-3 gap-2 max-w-lg">${[['THIRDPARTY', 'ثالث', 'fa-car-side'], ['BODY', 'بدنه', 'fa-car-burst'], ['MIXED', 'هر دو', 'fa-layer-group']].map(([k, t, ic]) =>
                    `<button data-mode="${k}" class="rounded-xl border-2 py-2.5 text-xs font-black ${st.mode === k ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:border-indigo-200'}"><i class="fas ${ic} ml-1"></i>${t}</button>`).join('')}</div></div>` : ''}
              <div class="md:col-span-2"><label class="lbl">نامه‌ی درخواست (اختیاری)</label>
                <label class="flex items-center gap-2 border-2 border-dashed rounded-xl px-3 py-2.5 cursor-pointer hover:bg-slate-50 text-xs ${st.letter ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-500'}">
                  <i class="fas fa-envelope-open-text"></i><span class="truncate">${st.letter ? esc(st.letter.name) : 'انتخاب فایل نامه (PDF یا عکس) - اگر نامه ندارد خالی بماند'}</span>
                  <input type="file" data-uk-skip data-letter class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif"></label>
                ${st.letter ? '<button data-act="rmletter" class="text-[10px] text-rose-500 underline mt-1">حذف نامه</button>' : ''}</div>
              <div class="md:col-span-2"><label class="lbl">توضیح درخواست (اختیاری)</label><textarea data-f="text" rows="2" class="inp" placeholder="مثلاً: مدارک در بله فرستاده شد، بدون نامه">${esc(st.text)}</textarea></div>
            </div>

            ${isNew() ? detectBox() : ''}
            ${partyBox()}
            ${hasThird ? thirdBox() : ''}
            ${hasBody ? bodyBox() : ''}

            <div class="space-y-3">
              <div class="flex justify-between items-center"><h3 class="font-black text-sm text-slate-700"><i class="fas fa-car ml-1 text-indigo-500"></i>خودروها (${fa(st.rows.length)})</h3>
                <div class="flex gap-2">
                  ${st.mode === 'MIXED' || !isNew() ? `<button data-add="THIRDPARTY" class="btn-s bg-sky-50 text-sky-700">+ ثالث</button><button data-add="BODY" class="btn-s bg-violet-50 text-violet-700">+ بدنه</button>`
                    : `<button data-add="${st.mode}" class="btn-s bg-indigo-600 text-white">+ افزودن خودرو</button>`}
                </div></div>
              ${st.rows.map((r, i) => rowCard(r, i)).join('')}
            </div>
          </div>
          <div class="sticky bottom-0 bg-white/95 backdrop-blur border-t p-4 flex flex-wrap gap-3 items-center justify-between">
            <div class="text-[11px] text-slate-500" id="cmr-summary">${summary()}</div>
            <div class="flex gap-2"><button data-act="close" class="btn-s bg-slate-100 text-slate-600">انصراف</button>
              <button data-act="submit" class="btn-s bg-emerald-600 text-white px-6 shadow-lg shadow-emerald-600/20"><i class="fas fa-check ml-1"></i>ثبت درخواست و بارگذاری مدارک</button></div>
          </div></div>
          <style>.lbl{display:block;font-size:10px;font-weight:700;color:#64748b;margin-bottom:4px}.inp{width:100%;border:2px solid #e2e8f0;border-radius:12px;padding:8px 10px;font-size:12px;background:#fff;outline:none}.inp:focus{border-color:#818cf8}.btn-s{border-radius:12px;padding:8px 14px;font-size:12px;font-weight:800}</style>`;
        bind();
    }

    // ---------------- شناساییِ خودکار از فاکتور فروش / سند / کارت ماشین ----------------
    // هر فایل (PDFِ چندصفحه‌ای یا عکس) صفحه‌به‌صفحه خوانده می‌شود؛ صفحه‌های یک خودرو (همان شاسی/موتور) کنارِ هم می‌آیند
    // و برای هر خودرو یک ردیف (در «هر دو»: ثالث + بدنه) با اطلاعاتش ساخته می‌شود. بعد از ثبت، هر صفحه روی ردیفِ خودش بایگانی می‌شود.
    const KIND_FA = { sales_invoice: 'فاکتور فروش', ownership_doc: 'سند', car_card: 'کارت ماشین', policy: 'بیمه‌نامه', unknown: 'نامعلوم' };
    const vkey = d => en(d.chassis_no || d.vin || '').toUpperCase().replace(/[^A-Z0-9]/g, '') || en(d.engine_no || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    const ckey = v => en(v || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    function detectBox() {
        const D = st.detect, pages = D.pages;
        const veh = new Set(pages.filter(p => p.row).map(p => p.vehicle)).size, unk = pages.filter(p => !p.row).length;
        return `<div class="bg-white rounded-2xl border-2 border-dashed border-emerald-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div><h3 class="font-black text-sm text-emerald-800"><i class="fas fa-wand-magic-sparkles ml-1"></i>شناساییِ خودکار از فاکتور فروش / سند / کارت ماشین</h3>
                <p class="text-[10.5px] text-slate-500 mt-1 leading-5">یک یا چند فایل بدهید (PDFِ چندصفحه‌ای که چند فاکتور یا سند پشتِ سرِ هم دارد، یا عکس). سایت هر صفحه را می‌خواند، نوعش را تشخیص می‌دهد و برای هر خودرو یک ردیف با شاسی، موتور، نام خودرو و مشخصاتِ مالک می‌سازد. بعد از ثبت، هر صفحه روی ردیفِ خودش بایگانی می‌شود و جزئیاتِ کامل در «اطلاعات صدور»ِ همان ردیف (صفحه‌ی صدور و صادره) می‌نشیند.</p></div>
              <label class="btn-s bg-emerald-600 text-white cursor-pointer whitespace-nowrap ${D.busy ? 'opacity-60 pointer-events-none' : ''}"><i class="fas fa-file-arrow-up ml-1"></i>انتخابِ فایل‌ها
                <input type="file" data-uk-skip multiple data-detect class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif"></label>
            </div>
            ${D.busy ? `<div class="mt-3 text-[11px] text-emerald-700 font-bold"><i class="fas fa-circle-notch fa-spin ml-1"></i>در حال خواندن… ${fa(D.done)} از ${fa(D.total)} صفحه</div>
                <div class="h-1.5 rounded-full bg-emerald-50 overflow-hidden mt-1"><div class="h-full bg-emerald-500" style="width:${D.total ? Math.round(D.done * 100 / D.total) : 0}%"></div></div>` : ''}
            ${pages.length ? `<div class="mt-3 text-[11px] font-bold text-slate-600">${fa(pages.length)} صفحه · ${fa(veh)} خودرو${unk ? ` · <span class="text-amber-700">${fa(unk)} صفحه شناخته نشد</span>` : ''}</div>
              <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-1.5">${pages.map(p => {
                const d = p.data || {};
                return `<div class="flex items-center gap-2 rounded-lg border ${p.row ? 'border-emerald-100 bg-emerald-50/50' : 'border-amber-200 bg-amber-50'} p-1.5 text-[10.5px]">
                  <a href="${API}?action=mreq_doc_page&token=${p.token}&n=${p.n}&kind=pdf" target="_blank" title="دیدنِ صفحه"><img src="${API}?action=mreq_doc_page&token=${p.token}&n=${p.n}&kind=png" class="w-9 h-12 object-cover rounded border bg-white" loading="lazy"></a>
                  <div class="min-w-0 flex-1"><b>${esc(KIND_FA[p.kind] || p.kind_fa || 'نامعلوم')}</b>${p.status === 'partial' ? ' <span class="text-amber-600">(ناقص)</span>' : ''}
                    <div class="truncate text-slate-500" dir="ltr">${esc([d.chassis_no || d.vin, d.engine_no].filter(Boolean).join(' · ')) || '—'}</div>
                    ${d.owner_name ? `<div class="truncate text-slate-500">مالک: ${esc(d.owner_name)}</div>` : ''}</div>
                  ${p.row ? `<span class="text-emerald-700 font-black whitespace-nowrap">خودروی ${fa(p.vehicle)}</span>` : '<span class="text-amber-700 whitespace-nowrap">بدونِ ردیف</span>'}
                </div>`; }).join('')}</div>` : ''}
          </div>`;
    }
    async function detectFiles(files) {
        const D = st.detect;
        if (D.busy || !files.length) return;
        D.busy = true; render();
        for (const f of files) {
            const fd = new FormData(); fd.append('action', 'mreq_doc_upload'); fd.append('file', f);
            let j;
            try { j = await (await fetch(API, { method: 'POST', body: fd })).json(); } catch (e) { j = { ok: false, error: 'خطا در ارتباط با سرور' }; }
            if (!j.ok) { toast(`${f.name}: ${j.error || 'خطا'}`, 'error'); continue; }
            D.total += j.pages; render();
            // چند صفحه با هم (یک اجرای پایتون: مدلِ OCR یک بار بارگذاری می‌شود؛ خیلی سریع‌تر از صفحه‌به‌صفحه)
            for (let n0 = 1; n0 <= j.pages; n0 += 3) {
                const ns = []; for (let n = n0; n < n0 + 3 && n <= j.pages; n++) ns.push(n);
                let res = await post({ action: 'mreq_doc_read', token: j.token, ns });
                const list = res && Array.isArray(res.pages) ? res.pages : null;
                const items = list || [];
                if (!list) for (const n of ns) { const r = await post({ action: 'mreq_doc_read', token: j.token, n }); items.push(Object.assign({ n }, r)); }   // سرورِ قدیمی
                for (const r of items) {
                    D.done++;
                    if (r.warn && !D.warned) { D.warned = true; toast(r.warn, 'warning'); }
                    const pg = { token: j.token, n: r.n, kind: r.kind || 'unknown', kind_fa: r.kind_fa || '', status: r.status || 'none', data: r.data || {}, row: false, vehicle: 0 };
                    D.pages.push(pg);
                    detectPlace(pg);
                }
                if (root && root.style.display !== 'none') render();
            }
        }
        D.busy = false;
        const veh = new Set(D.pages.filter(p => p.row).map(p => p.vehicle)).size;
        toast(`${fa(D.pages.length)} صفحه خوانده شد؛ ${fa(veh)} خودرو در ردیف‌ها نشست.`, 'success');
        render();
    }
    // صفحه => ردیفِ خودروی خودش (همان شاسی/موتور) یا ردیفِ تازه
    function detectPlace(pg) {
        const d = pg.data || {}, key = vkey(d);
        if (!key || pg.kind === 'policy') return;
        let rows = st.rows.filter(r => r.docKey === key || (ckey(r.chassis) && ckey(r.chassis) === key) || (ckey(r.engine) && ckey(r.engine) === ckey(d.engine_no)));
        if (!rows.length) {
            // ردیفِ خالیِ اولِ فرم (هنوز چیزی در آن ننوشته‌اند) به‌جای ساختنِ ردیفِ تازه پر می‌شود
            const blank = st.rows.filter(r => !r.docKey && !r.chassis && !r.p2 && !r.carName && !Object.keys(r.files).length);
            const types = st.mode === 'MIXED' ? ['THIRDPARTY', 'BODY'] : [st.mode];
            rows = types.map(t => { const b = blank.find(x => x.type === t && !x._taken); if (b) { b._taken = true; return b; } const nr = newRow(t); st.rows.push(nr); return nr; });
            st.rows.forEach(r => delete r._taken);
            const vno = Math.max(0, ...st.rows.map(r => r.vehicleNo || 0)) + 1;
            rows.forEach(r => { r.vehicleNo = vno; });
        }
        rows.forEach(r => {
            r.docKey = key;
            const chassis = d.chassis_no || d.vin || '';
            if (!r.chassis && chassis) r.chassis = chassis;
            if (!r.engine && d.engine_no) r.engine = d.engine_no;
            if (!r.carName && (d.car_system || d.car_name)) r.carName = d.car_system || d.car_name;
            if (!r.carType && d.car_tip) r.carType = d.car_tip;
            // پلاکِ خوانده‌شده از بارکدِ سند/کارت (حرفِ ناشناخته خالی می‌ماند تا انتخاب شود)
            const pp = d.plate_parts;
            if (pp && pp.three && !r.p2 && !r.p4 && !r.moto) {
                r.p1 = pp.iran || ''; r.p2 = pp.three; r.p4 = pp.two || '';
                r.letter = pp.letter && LETTERS.includes(pp.letter) ? pp.letter : '';
                r.noPlate = false;
            }
            // فاکتورِ فروش = صفرکیلومتر: بیمه‌نامه‌ی قبلی و بازدید ندارد و تا پلاک نگرفته «بدون پلاک» (با شماره شاسی)
            if (pg.kind === 'sales_invoice') { r.isNew = true; if (r.type === 'BODY') r.skipHealth = true; if (!r.p2) r.noPlate = true; }
            else if (!r.p2) r.noPlate = true;   // سند/کارت پلاکی نداد: تا پلاک وارد نشده، با شاسی ثبت می‌شود
            r.docData = Object.assign({}, d, r.docData || {});
            ownerParty(r);
            if (!r.docPages.some(x => x.token === pg.token && x.n === pg.n)) r.docPages.push({ token: pg.token, n: pg.n, kind: pg.kind });
        });
        pg.row = true; pg.vehicle = rows[0].vehicleNo || 0;
    }

    // مالکِ مدرک (سند/فاکتور/کارت) با شرکتِ درخواست‌دهنده فرق دارد؟ => «مالک/بیمه‌گذارِ این خودرو فرق دارد» روشن می‌شود:
    // طبقِ تنظیمِ همان شرکت «ذینفع» (بیمه‌گذار = شرکت؛ پیش‌فرض) یا «مالک = بیمه‌گذار». اگر کاربر خودش دست زده باشد، دست نمی‌خورد.
    function ownerDiffers(d, company) {
        const name = String(d.owner_name || '').trim(), nid = en(d.national_id || '').replace(/\D/g, '');
        if (!company || (!name && !nid)) return null;
        const cn = pnorm(company.name), on = pnorm(name), eco = en(company.economic_code || '').replace(/\D/g, '');
        if (nid && eco && nid === eco) return false;
        if (on && cn && (on.includes(cn) || cn.includes(on))) return false;
        return true;
    }
    function ownerParty(r) {
        if (r.partyUser || !r.docData) return;
        const company = (form.companies || []).find(c => String(c.id) === String(st.companyId));
        const diff = ownerDiffers(r.docData, company);
        if (diff === null) return;
        if (!diff) { if (r.partyAuto) { r.partyOpen = false; r.partyMode = ''; r.party = null; r.partyAuto = false; } return; }
        const d = r.docData, nid = en(d.national_id || '').replace(/\D/g, '');
        r.partyOpen = true; r.partyAuto = true;
        r.partyMode = company.other_owner_mode === 'OWNER' ? 'OWNER' : 'BENEFICIARY';
        r.party = { kind: nid.length === 11 ? 'COMPANY' : 'PERSON', name: String(d.owner_name || '').trim(), national_id: nid,
                    phone: en(d.mobile || d.phone || '').replace(/[^\d+]/g, ''), address: d.address || '', postal_code: en(d.postal_code || '').replace(/\D/g, '') };
    }

    // ---------------- مالک / بیمه‌گذار / ذینفع (party-ui.js): کلی برای همه‌ی خودروها + جدا برای هر خودرو ----------------
    function partyBox() {
        if (!window.PartyUI) return '';
        return `<div class="bg-white rounded-2xl border-2 border-amber-100 p-4">
            <h3 class="font-black text-sm text-amber-800 mb-1"><i class="fas fa-user-shield ml-1"></i>مالک / بیمه‌گذار / ذینفع (برای همه‌ی خودروها)</h3>
            <p class="text-[10.5px] text-slate-500 mb-2 leading-6">خودرو به نامِ خودِ شرکت است، یا به نامِ شخص/شرکتِ دیگری که «ذینفع» می‌شود (بیمه‌گذار: شرکت)، یا به نامِ دیگری که خودش بیمه‌گذار می‌شود. برای خودروی متفاوت، در کارتِ همان خودرو «مالک/بیمه‌گذارِ این خودرو فرق دارد» را بزنید.</p>
            <div data-pty-scope="all">${PartyUI.editor({ mode: st.partyMode, party: st.party, insured: st.insured })}</div></div>`;
    }
    const pnorm = v => String(v || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/شرکت|سهامی|خاص|عام|[\s\u200c()«»\-ـ.]/g, '');
    function rowPartyHtml(r) {
        if (!window.PartyUI) return '';
        const company = (form.companies || []).find(c => String(c.id) === String(st.companyId));
        const eff = r.partyOpen && r.partyMode ? r.partyMode : st.partyMode;
        const own = r.docData && r.docData.owner_name;
        const warn = own && eff === 'COMPANY' && company && pnorm(company.name) && !pnorm(own).includes(pnorm(company.name)) && !pnorm(company.name).includes(pnorm(own))
            ? `<p class="text-[10.5px] text-amber-700 font-bold mt-1">⚠ مالکِ مدرکِ این خودرو «${esc(own)}» است، نه شرکت؛ اگر لازم است «ذینفع» یا «مالک = بیمه‌گذار» را انتخاب کنید.</p>` : '';
        return `<div class="mt-3 border-t pt-3">
            <label class="flex items-center gap-2 text-[11px] font-bold ${r.partyOpen ? 'text-amber-800' : 'text-slate-500'}"><input type="checkbox" data-r="partyOpen" ${r.partyOpen ? 'checked' : ''}> مالک/بیمه‌گذارِ این خودرو فرق دارد
              ${r.partyOpen ? '' : `<span class="font-normal text-slate-400">(طبقِ انتخابِ کلی: ${esc(PartyUI.SHORT[st.partyMode] || '')})</span>`}</label>
            ${r.partyAuto ? `<p class="text-[10.5px] text-amber-700 mt-1">مالکِ مدرک «${esc(r.party && r.party.name || '')}» با شرکت فرق دارد؛ طبقِ تنظیمِ شرکت «${esc(PartyUI.SHORT[r.partyMode] || '')}» انتخاب شد (قابلِ تغییر).</p>` : ''}
            ${r.partyOpen ? `<div class="mt-2" data-pty-scope="row">${PartyUI.editor({ mode: r.partyMode, party: r.party, insured: r.insured }, { allowInherit: true })}</div>` : ''}${warn}</div>`;
    }

    function liabilityOptions(val, withEmpty) {
        return (withEmpty ? `<option value="">${esc(withEmpty)}</option>` : '') + (form.liability_options || []).map(v => `<option value="${v}" ${String(v) === String(val) ? 'selected' : ''}>${money(v)} ریال</option>`).join('');
    }

    function thirdBox() {
        const d = st.defaults;
        const hint = d && d.liability_limit ? `<span class="text-emerald-600">${d.liability_from && d.liability_from.company ? 'پیش‌فرضِ تعریف‌شده برای این شرکت' : `پیش‌فرض از آخرین درخواست این شرکت (${fa(d.liability_from.date)})`}: ${money(d.liability_limit)} ریال</span>` : (st.companyId ? '<span class="text-amber-600">این شرکت درخواست ثالث قبلی ندارد؛ تعهد را انتخاب کنید.</span>' : '');
        return `<div class="bg-white rounded-2xl border-2 border-sky-100 p-4">
            <h3 class="font-black text-sm text-sky-700 mb-3"><i class="fas fa-shield-halved ml-1"></i>تعهد مالی ثالث</h3>
            <div class="flex flex-wrap gap-4 items-end">
              <div class="min-w-[220px]"><label class="lbl">تعهد مالی برای همه‌ی پلاک‌های ثالث</label>
                <select data-f="liabilityAll" class="inp" ${st.liabilityPerRow ? 'disabled' : ''}>${liabilityOptions(st.liabilityAll, '-- انتخاب تعهد --')}</select></div>
              <label class="flex items-center gap-2 text-xs font-bold text-slate-600 pb-2"><input type="checkbox" data-f="liabilityPerRow" ${st.liabilityPerRow ? 'checked' : ''}> برای هر پلاک جداگانه مشخص می‌کنم</label>
            </div><p class="text-[10px] mt-2">${hint}</p></div>`;
    }

    function covEditor(cov, scope) {
        const opts = form.coverage_options || {};
        return `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-1.5">` + Object.entries(opts).map(([k, o]) => {
            const on = cov && Object.prototype.hasOwnProperty.call(cov, k);
            const tiers = o.tiers ? `<select data-tier="${k}" data-scope="${scope}" class="mt-1 border rounded-lg p-1 text-[11px] bg-white w-full" ${on ? '' : 'disabled'}>
                ${Object.entries(o.tiers).map(([tk, tl]) => `<option value="${tk}" ${on && String(cov[k]) === tk ? 'selected' : ''}>${esc(tl)}</option>`).join('')}</select>` : '';
            return `<label class="block rounded-xl border ${on ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-white'} p-2 text-[11px] cursor-pointer" title="${esc(o.desc)}">
                <span class="flex items-center gap-1.5 font-bold text-slate-700"><input type="checkbox" data-cov="${k}" data-scope="${scope}" ${on ? 'checked' : ''}> ${esc(o.label)}</span>${tiers}</label>`;
        }).join('') + `</div>`;
    }

    function bodyBox() {
        const d = st.defaults;
        let hint = '';
        if (d && d.coverages_from) hint = d.coverages_from.company ? '<span class="text-emerald-600"><i class="fas fa-building ml-1"></i>پوشش‌ها از پیش‌فرضِ تعریف‌شده برای این شرکت انتخاب شده‌اند؛ می‌توانید تغییر دهید.</span>'
            : `<span class="text-emerald-600"><i class="fas fa-clock-rotate-left ml-1"></i>پوشش‌ها از آخرین درخواست بدنه‌ی این شرکت (${fa(d.coverages_from.date)}) انتخاب شده‌اند؛ می‌توانید تغییر دهید.</span>`;
        else if (st.companyId) hint = '<span class="text-amber-600"><i class="fas fa-circle-exclamation ml-1"></i>اولین درخواست بدنه‌ی این شرکت است؛ پوشش‌های درخواستی را انتخاب کنید.</span>';
        return `<div class="bg-white rounded-2xl border-2 border-violet-100 p-4">
            <div class="flex flex-wrap justify-between items-center gap-2 mb-2">
              <h3 class="font-black text-sm text-violet-700"><i class="fas fa-list-check ml-1"></i>پوشش‌های درخواستی بدنه (برای همه‌ی خودروهای بدنه)</h3>
              <label class="flex items-center gap-2 text-xs font-bold text-slate-600"><input type="checkbox" data-f="covBaseOnly" ${st.covBaseOnly ? 'checked' : ''}> فقط پوشش پایه (بدون پوشش تکمیلی)</label>
            </div>
            <p class="text-[10px] mb-3">${hint}</p>
            <label class="flex items-center gap-2 text-xs font-black mb-3 rounded-xl border-2 px-3 py-2 w-fit cursor-pointer ${st.noVisitAll ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-slate-200 text-slate-600'}">
              <input type="checkbox" data-f="noVisitAll" ${st.noVisitAll ? 'checked' : ''}> این درخواست نیاز به بازدید ندارد <span class="font-bold text-[10px] text-slate-400">(گزارش و عکس‌های بازدید برای هیچ خودرویی خواسته نمی‌شود)</span></label>
            ${st.covBaseOnly ? '' : covEditor(st.cov || {}, 'all')}
            <p class="text-[10px] text-slate-400 mt-2">اگر یک پلاک پوشش‌های دیگری می‌خواهد، در کارتِ همان خودرو «پوشش متفاوت» را بزنید.</p></div>`;
    }

    function checklistFor(r) {
        const key = `${st.kind}|${r.type}|${r.skipHealth || r.isNew ? 1 : 0}|${r.prevBody === 'YES' ? 'YES' : 'NO'}`;
        return (form.checklists || {})[key] || [];
    }

    // ---------------- پرونده‌ی خودرو (reg-link.js): همین خودرو قبلاً در سامانه بوده؟ ----------------
    const regHtml = r => (window.RegLink && r.reg && r.regDismiss !== r.reg.key) ? RegLink.vehicleCard(r.reg, r.regSel, { applied: r.regApplied }) : '';
    const regTimers = {};
    function scheduleReg(r) {
        if (!window.RegLink) return;
        clearTimeout(regTimers[r.id]);
        regTimers[r.id] = setTimeout(async () => {
            const q = r.moto ? { plate: '' , vin: r.chassis, engine: r.engine } : { p1: r.p1, p2: r.p2, letter: r.letter, p4: r.p4, vin: r.chassis, engine: r.engine };
            const v = await RegLink.vehicle(q);
            if ((v && v.key) !== (r.reg && r.reg.key)) {
                r.reg = v; r.regApplied = false;
                // پیش‌فرض: مدارکِ خودِ خودرو و بیمه‌نامه‌ی قبلیِ همان نوع تیک می‌خورند
                r.regSel = new Set((v ? v.docs : []).map((d, i) => ((d.type === 'prev_body_policy' && r.type !== 'BODY') || (d.type === 'prev_third_policy' && r.type !== 'THIRDPARTY')) ? -1 : i).filter(i => i >= 0));
            }
            const box = root && root.querySelector(`[data-row="${r.id}"] [data-reg-box]`);
            if (box) { box.innerHTML = regHtml(r); bindReg(box, r); }
        }, 650);
    }
    function regFill(r) {
        const v = r.reg, f = (v && v.fill) || {}, sm = (v && v.summary) || {};
        if (!r.moto && !r.noPlate && !(en(r.p1) + en(r.p2) + en(r.p4)).trim() && f.plate_p2) { r.p1 = f.plate_p1 || ''; r.p2 = f.plate_p2 || ''; r.letter = f.plate_letter || r.letter; r.p4 = f.plate_p4 || ''; }
        if (!r.chassis && f.chassis_no) r.chassis = f.chassis_no;
        if (!r.engine && f.engine_no) r.engine = f.engine_no;
        if (!r.carName && f.car_name) r.carName = f.car_name;
        if (!r.carType && f.car_type) r.carType = f.car_type;
        const end = r.type === 'BODY' ? sm.body_end : sm.third_end;
        if (!r.expiry && !r.isNew && end) r.expiry = end;
        if (!r.docData && (f.owner_name || f.model_year || f.color)) r.docData = { owner_name: f.owner_name || '', national_id: f.owner_national_id || '', model_year: f.model_year || '', color: f.color || '' };
        r.regApplied = true;
    }
    function bindReg(box, r) {
        const fb = box.querySelector('[data-reg-fill]'); if (fb) fb.onclick = () => { regFill(r); render(); toast('اطلاعاتِ خودرو از پرونده نشست؛ بررسی کنید.', 'success'); };
        const db = box.querySelector('[data-reg-dismiss]'); if (db) db.onclick = () => { r.regDismiss = r.reg ? r.reg.key : ''; r.regSel = new Set(); box.innerHTML = ''; };
        const ob = box.querySelector('[data-reg-open]'); if (ob) ob.onclick = () => window.Vehicles && Vehicles.open(ob.dataset.regOpen);
        box.querySelectorAll('[data-reg-doc]').forEach(cb => cb.onchange = () => { const i = Number(cb.dataset.regDoc); cb.checked ? r.regSel.add(i) : r.regSel.delete(i); cb.closest('label').className = cb.closest('label').className.replace(/border-\S+ text-\S+/, cb.checked ? 'border-emerald-300 text-emerald-800' : 'border-slate-200 text-slate-600'); });
    }

    function rowCard(r, i) {
        const body = r.type === 'BODY';
        const tBadge = body ? '<span class="px-2 py-0.5 rounded-lg bg-violet-100 text-violet-700 text-[10px] font-black">بدنه</span>' : '<span class="px-2 py-0.5 rounded-lg bg-sky-100 text-sky-700 text-[10px] font-black">ثالث</span>';
        // صفرکیلومتر: شماره شاسی لازم است (پلاک اگر گرفته باشد اختیاری)
        const vinBox = `<div class="grid grid-cols-2 gap-2"><div><label class="lbl">شماره شاسی (VIN) *</label><input data-r="chassis" class="inp" dir="ltr" value="${esc(r.chassis)}"></div>
               <div><label class="lbl">شماره موتور</label><input data-r="engine" class="inp" dir="ltr" value="${esc(r.engine)}"></div></div>`;
        // موتورسیکلت: پلاکِ دوخطی (کد شهر ۳ رقم / شماره‌ی ۵ رقم)
        const motoPin = `<div><label class="lbl">پلاکِ موتور *</label><div class="mt-pin" dir="ltr"><span class="ip-flag"></span><span class="mt-rows">
                <input data-r="mcity" maxlength="3" inputmode="numeric" placeholder="کد شهر ۵۸۳" value="${esc(fa(r.mcity))}">
                <input data-r="mnum" maxlength="5" inputmode="numeric" placeholder="۷۶۲۲۴" value="${esc(fa(r.mnum))}"></span></div>${r.isNew ? '<p class="text-[9.5px] text-slate-400 mt-1">صفرکیلومتر: اگر هنوز پلاک نگرفته، خالی بگذارید.</p>' + vinBox : ''}</div>`;
        const plate = r.noPlate
            ? vinBox
            : r.moto ? motoPin
            // مثلِ بقیه‌ی فرم‌های پنل: چپ‌به‌راست [۲ رقم = p4] [حرف] [۳ رقم] [ایران + کد شهر = p1]
            : `<div><label class="lbl">پلاک *</label><div class="ir-pin" dir="ltr"><span class="ip-flag"></span>
                <input data-r="p4" class="ip-cell" maxlength="2" inputmode="numeric" placeholder="۱۲" value="${esc(fa(r.p4))}">
                <select data-r="letter" class="ip-cell ip-letter${r.letter ? '' : ' !bg-amber-100'}" dir="rtl" title="${r.letter ? '' : 'حرفِ پلاک از مدرک خوانده نشد؛ انتخاب کنید'}">${r.letter ? '' : '<option value="" selected>؟</option>'}${LETTERS.map(l => `<option ${r.letter === l ? 'selected' : ''}>${l}</option>`).join('')}</select>
                <input data-r="p2" class="ip-cell ip-wide" maxlength="3" inputmode="numeric" placeholder="۳۴۵" value="${esc(fa(r.p2))}">
                <span class="ip-ir"><small>ایران</small><input data-r="p1" maxlength="2" inputmode="numeric" placeholder="۶۷" value="${esc(fa(r.p1))}"></span></div>${r.isNew ? '<p class="text-[9.5px] text-slate-400 mt-1">صفرکیلومتر: اگر هنوز پلاک نگرفته، خالی بگذارید.</p>' + vinBox : ''}</div>`;
        const kindFields = isNew() ? (body ? `
                <div><label class="lbl">ارزش خودرو (ریال) *</label><input data-r="carValue" class="inp money-input" value="${esc(money(r.carValue))}" placeholder="۵,۰۰۰,۰۰۰,۰۰۰"></div>
                <div><label class="lbl">بیمه بدنه قبل</label><select data-r="prevBody" class="inp"><option value="">نامشخص</option><option value="YES" ${r.prevBody === 'YES' ? 'selected' : ''}>دارد</option><option value="NO" ${r.prevBody === 'NO' ? 'selected' : ''}>ندارد</option></select></div>
                <div><label class="lbl">نوع خودرو (برای قالبِ گزارش بازدید)</label><select data-r="vclass" class="inp">
                  <option value="" ${!r.vclass ? 'selected' : ''}>خودکار از نام خودرو (${vclassOf({ carName: r.carName }) === 'HEAVY' ? 'سنگین' : 'سواری / وانت'})</option>
                  <option value="LIGHT" ${r.vclass === 'LIGHT' ? 'selected' : ''}>سواری / وانت / استیشن</option>
                  <option value="HEAVY" ${r.vclass === 'HEAVY' ? 'selected' : ''}>سنگین (کامیون، اتوبوس، مینی‌بوس...)</option></select></div>
                <label class="flex items-center gap-2 text-[11px] font-bold mt-5 ${r.skipHealth ? 'text-amber-700' : 'text-slate-600'}"><input type="checkbox" data-r="skipHealth" ${r.skipHealth ? 'checked' : ''}> این خودرو نیاز به بازدید ندارد</label>`
              : (st.liabilityPerRow ? `<div><label class="lbl">تعهد مالی این پلاک *</label><select data-r="liability" class="inp">${liabilityOptions(r.liability, '-- انتخاب --')}</select></div>`
                                    : `<div class="text-[11px] text-slate-500 mt-5">تعهد: <b>${st.liabilityAll ? money(st.liabilityAll) + ' ریال' : '—'}</b> (برای همه)</div>`))
            : `<div><label class="lbl">شماره بیمه‌نامه‌ی فعلی *</label><input data-r="refPolicy" class="inp" dir="ltr" value="${esc(r.refPolicy)}"></div>
               ${st.kind === 'ENDORSEMENT' ? `<div class="md:col-span-2"><label class="lbl">چه تغییری می‌خواهند</label><input data-r="endorse" class="inp" value="${esc(r.endorse)}"></div>`
                 : `<div class="md:col-span-2"><label class="lbl">دلیل فسخ</label><select data-r="cancelReason" class="inp"><option value="">--</option>${Object.entries(form.cancellation_reasons || {}).map(([k, v]) => `<option value="${esc(k)}" ${r.cancelReason === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>`}`;

        const covRow = isNew() && body ? `<div class="mt-3 border-t pt-3">
            <label class="flex items-center gap-2 text-[11px] font-bold ${r.customCov ? 'text-violet-700' : 'text-slate-500'}"><input type="checkbox" data-r="customCov" ${r.customCov ? 'checked' : ''}> پوشش متفاوت برای این پلاک</label>
            ${r.customCov ? `<div class="mt-2"><label class="flex items-center gap-2 text-[11px] mb-2"><input type="checkbox" data-rcovbase ${r.cov && r.cov.__none ? 'checked' : ''}> فقط پوشش پایه</label>${r.cov && r.cov.__none ? '' : covEditor(r.cov || {}, 'row:' + r.id)}</div>`
                          : `<p class="text-[10px] text-slate-400 mt-1">${st.covBaseOnly ? 'فقط پوشش پایه' : (Object.keys(st.cov || {}).length ? 'همان پوشش‌های پیش‌فرض بالا' : 'پوشش پیش‌فرض هنوز انتخاب نشده')}</p>`}</div>` : '';

        const items = checklistFor(r);
        const visitItems = items.filter(it => VISIT_TYPES.includes(it.key));
        const docs = visitCard(r, visitItems) + items.filter(it => !VISIT_TYPES.includes(it.key) || (r.manualVisit && needsVisit(r))).map(item => `<div class="rounded-xl border ${item.required ? 'border-slate-200' : 'border-dashed border-slate-200'} p-2 bg-white">
            <p class="text-[11px] font-bold text-slate-700">${esc(item.label)} ${item.required ? '<span class="text-rose-500">*</span>' : '<span class="text-slate-400 font-normal">(اختیاری)</span>'}</p>
            ${item.hint ? `<p class="text-[9.5px] text-slate-400 mb-1">${esc(item.hint)}</p>` : ''}
            <div class="flex flex-wrap gap-1">${item.upload_types.map(t => {
                const fs = r.files[t] || [];
                return `<label class="text-[10px] font-bold px-2 py-1 rounded-lg cursor-pointer ${fs.length ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-50 text-blue-700 hover:bg-blue-100'}">
                    ${fs.length ? '✓ ' : '+ '}${esc((form.doc_types || {})[t] || t)}${fs.length ? ` (${fa(fs.length)})` : ''}
                    <input type="file" data-uk-skip class="hidden" multiple data-file="${t}" accept=".pdf,.jpg,.jpeg,.png,.webp,.zip,.heic,.heif"></label>`;
            }).join('')}</div>
            ${item.upload_types.some(t => (r.files[t] || []).length) ? `<div class="text-[9.5px] text-slate-500 mt-1 truncate" dir="ltr">${item.upload_types.flatMap(t => (r.files[t] || []).map(f => esc(f.name))).join(' · ')}</div>
              <button data-clearfiles="${item.upload_types.join(',')}" class="text-[9.5px] text-rose-500 underline">حذف فایل‌ها</button>` : ''}</div>`).join('');

        return `<div class="bg-white rounded-2xl border p-4 shadow-sm" data-row="${r.id}">
            <div class="flex justify-between items-center mb-3 gap-2 flex-wrap">
              <div class="flex items-center gap-2"><span class="w-7 h-7 rounded-full bg-slate-800 text-white text-xs font-black flex items-center justify-center">${fa(i + 1)}</span>
                ${st.mode === 'MIXED' && isNew() ? `<select data-r="type" class="inp !w-28 !py-1"><option value="THIRDPARTY" ${!body ? 'selected' : ''}>ثالث</option><option value="BODY" ${body ? 'selected' : ''}>بدنه</option></select>` : tBadge}</div>
              <div class="flex gap-3 items-center text-[11px]">
                <label class="flex items-center gap-1 font-bold ${r.moto ? 'text-blue-700' : 'text-slate-500'}"><input type="checkbox" data-r="moto" ${r.moto ? 'checked' : ''}> 🏍 موتورسیکلت</label>
                <label class="flex items-center gap-1 font-bold text-slate-500"><input type="checkbox" data-r="noPlate" ${r.noPlate ? 'checked' : ''}> بدون پلاک (شماره شاسی)</label>
                <label class="flex items-center gap-1 font-bold text-slate-500"><input type="checkbox" data-r="isNew" ${r.isNew ? 'checked' : ''}> صفرکیلومتر</label>
                ${st.rows.length > 1 ? '<button data-act="delrow" class="text-rose-500 hover:text-rose-700 font-bold">حذف</button>' : ''}</div></div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
              <div class="md:col-span-2">${plate}</div>
              <div><label class="lbl">نام خودرو</label><input data-r="carName" class="inp" value="${esc(r.carName)}" placeholder="مثلاً پژو ۲۰۶"></div>
              <div><label class="lbl">تیپ خودرو</label><input data-r="carType" class="inp" value="${esc(r.carType || '')}" placeholder="مثلاً تیپ ۵"></div>
              ${r.isNew ? '<div class="md:col-span-4 text-[10.5px] text-emerald-700 font-bold bg-emerald-50 rounded-lg px-2 py-1.5">صفر کیلومتر: بیمه‌نامه‌ی قبلی و تاریخِ انقضا ندارد و بازدیدِ سلامت لازم نیست.</div>'
                : `<div><label class="lbl">تاریخ انقضای بیمه‌نامه‌ی قبلی</label><input data-r="expiry" class="inp text-center" dir="ltr" value="${esc(fa(r.expiry))}" placeholder="۱۴۰۵/۰۸/۱۵"></div>`}
              ${!body && !r.isNew && window.SanhabUI ? '<div class="md:col-span-4 rounded-xl bg-cyan-50/70 border border-cyan-100 px-2 py-1.5" data-sh-row></div>' : ''}
              ${kindFields}
              <div class="md:col-span-4"><label class="lbl">توضیح این ردیف</label><input data-r="note" class="inp" value="${esc(r.note)}"></div>
            </div>
            <div data-reg-box>${regHtml(r)}</div>
            ${covRow}
            ${rowPartyHtml(r)}
            ${r.docPages.length ? `<div class="mt-3 rounded-xl bg-emerald-50 border border-emerald-100 p-2 text-[10.5px] text-emerald-800">
              <b><i class="fas fa-wand-magic-sparkles ml-1"></i>${r.vehicleNo ? 'خودروی ' + fa(r.vehicleNo) + ' — ' : ''}از مدارکِ شناسایی‌شده:</b> ${r.docPages.map(p => esc(KIND_FA[p.kind] || 'مدرک') + ' (ص ' + fa(p.n) + ')').join('، ')}
              ${r.docData && r.docData.owner_name ? ` · مالک: <b>${esc(r.docData.owner_name)}</b>` : ''}${r.docData && r.docData.model_year ? ` · مدل ${esc(fa(r.docData.model_year))}` : ''}${r.docData && r.docData.color ? ` · ${esc(r.docData.color)}` : ''}
              <div class="text-emerald-600 mt-0.5">بعد از ثبت، این صفحه‌ها روی همین ردیف بایگانی و جزئیاتشان در «اطلاعات صدور» نشانده می‌شود.</div></div>` : ''}
            <div class="mt-3 border-t pt-3"><p class="text-[11px] font-black text-slate-600 mb-2"><i class="fas fa-paperclip ml-1"></i>مدارک این خودرو (می‌توانید الان بارگذاری کنید یا بعداً از جزئیات درخواست)</p>
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">${docs}</div></div>
          </div>`;
    }

    // «ساخت گزارش بازدید»: گزارش و عکس‌های بازدید یک‌جا، از همان فرمِ صدورِ گزارش (پاپ‌آپ) با اطلاعاتِ همین خودرو
    function visitCard(r, visitItems) {
        if (!needsVisit(r)) return '';
        const rep = r.report;
        const canBuild = !!(window.VR && VR.openTargetBuilder);
        return `<div class="rounded-xl border-2 ${rep ? 'border-emerald-300 bg-emerald-50' : 'border-indigo-200 bg-indigo-50/60'} p-2 md:col-span-2 lg:col-span-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div><p class="text-[11px] font-black ${rep ? 'text-emerald-700' : 'text-indigo-700'}"><i class="fas fa-file-circle-check ml-1"></i>گزارش بازدید <span class="text-rose-500">*</span></p>
                <p class="text-[9.5px] text-slate-500">گزارش و عکس‌های بازدید با هم؛ اطلاعاتِ همین خودرو خودکار در فرم می‌نشیند و قالبِ ${vclassOf(r) === 'HEAVY' ? 'سنگین' : 'سواری / وانت'} باز می‌شود.</p></div>
              <div class="flex flex-wrap gap-1.5 items-center">
                ${rep ? `<span class="text-[10.5px] font-black text-emerald-700">✓ گزارش <span dir="ltr">${esc(rep.no)}</span> · ${fa(rep.photos || 0)} عکس</span>
                    <a href="${VR.fileUrl(rep.id, 'pdf', '&inline=1')}" target="_blank" class="btn-s !py-1 !px-2 !text-[10px] bg-white text-slate-600 border">مشاهده</a>
                    <button data-visit-edit class="btn-s !py-1 !px-2 !text-[10px] bg-white text-indigo-600 border">ویرایش</button>
                    <button data-visit-drop class="btn-s !py-1 !px-2 !text-[10px] bg-white text-rose-500 border" title="گزارش در بایگانی می‌ماند؛ فقط به این ردیف وصل نمی‌شود">جدا کردن</button>`
                    : (canBuild ? `<button data-visit class="btn-s bg-gradient-to-l from-indigo-600 to-violet-600 text-white shadow"><i class="fas fa-file-circle-plus ml-1"></i>ساخت گزارش بازدید</button>` : '<span class="text-[10px] text-amber-600">به بخشِ گزارش بازدید دسترسی ندارید</span>')}
              </div></div>
            ${!rep && visitItems.length ? `<label class="flex items-center gap-1.5 text-[9.5px] text-slate-500 mt-1.5 cursor-pointer"><input type="checkbox" data-r="manualVisit" ${r.manualVisit ? 'checked' : ''}> گزارش/عکسِ آماده دارم (بارگذاریِ فایل به‌جای ساخت)</label>` : ''}
          </div>`;
    }

    async function buildVisit(r) {
        // r.p1 کدِ «ایران» است و r.p4 دو رقمِ سمتِ چپِ پلاک (مثل ذخیره در plate_p1/plate_p4)
        const raw = { plate_part1: r.noPlate ? '' : en(r.p4), plate_letter: r.noPlate || !(r.p1 || r.p2) ? '' : r.letter, plate_part2: r.noPlate ? '' : en(r.p2), plate_part3: r.noPlate ? '' : en(r.p1),
                      chassis_no: r.chassis, engine_no: r.engine, vehicle_type: r.carName, insured_value: en(r.carValue).replace(/\D/g, '') };
        const company = (form.companies || []).find(c => String(c.id) === String(st.companyId));
        await VR.openTargetBuilder({ type: 'raw', raw, insurer: st.insurer, class: vclassOf(r), company_id: st.companyId || '',
                                     title: [company && company.name, r.carName].filter(Boolean).join(' · ') }, rep => {
            r.report = { id: rep.id, no: rep.report_no, photos: rep.photos_count };
            r.manualVisit = false;
            VISIT_TYPES.forEach(t => delete r.files[t]);
            if (root && root.style.display !== 'none') render();
        });
    }

    function summary() {
        const t = st.rows.filter(r => r.type === 'THIRDPARTY').length, b = st.rows.length - t;
        const files = st.rows.reduce((a, r) => a + Object.values(r.files).reduce((x, f) => x + f.length, 0) + r.docPages.length, 0) + (st.letter ? 1 : 0);
        return isNew() ? `${fa(t)} ثالث · ${fa(b)} بدنه · ${fa(files)} فایل برای بارگذاری` : `${fa(st.rows.length)} ردیف · ${fa(files)} فایل`;
    }

    // ---------------- رویدادها ----------------
    const rowOf = el => st.rows.find(r => String(r.id) === el.closest('[data-row]').dataset.row);

    function bind() {
        root.querySelectorAll('[data-act="close"]').forEach(b => b.onclick = close);
        root.querySelector('[data-act="submit"]').onclick = submit;
        const rm = root.querySelector('[data-act="rmletter"]'); if (rm) rm.onclick = () => { st.letter = null; render(); };
        root.querySelector('[data-letter]').onchange = e => { st.letter = e.target.files[0] || null; render(); };
        const det = root.querySelector('[data-detect]'); if (det) det.onchange = e => detectFiles([...e.target.files]);

        root.querySelectorAll('[data-f]').forEach(el => {
            const ev = el.tagName === 'TEXTAREA' ? 'input' : 'change';
            el.addEventListener(ev, async () => {
                const f = el.dataset.f;
                st[f] = el.type === 'checkbox' ? el.checked : el.value;
                if (f === 'companyId') { await loadDefaults(); st.rows.forEach(ownerParty); }
                if (f === 'noVisitAll') st.rows.forEach(r => { if (r.type === 'BODY') r.skipHealth = st.noVisitAll; });
                if (f === 'kind' && st.kind !== 'NEW_POLICY') st.rows.forEach(r => { r.customCov = false; });
                if (f !== 'text') render();
            });
        });
        root.querySelectorAll('[data-mode]').forEach(b => b.onclick = () => {
            st.mode = b.dataset.mode;
            if (st.mode !== 'MIXED') st.rows.forEach(r => { r.type = st.mode; });
            render();
        });
        root.querySelectorAll('[data-add]').forEach(b => b.onclick = () => { st.rows.push(newRow(b.dataset.add)); render(); });
        root.querySelectorAll('[data-act="delrow"]').forEach(b => b.onclick = () => { const r = rowOf(b); st.rows = st.rows.filter(x => x !== r); render(); });

        root.querySelectorAll('[data-r]').forEach(el => {
            const isText = el.tagName === 'INPUT' && el.type !== 'checkbox';
            el.addEventListener(isText ? 'input' : 'change', () => {
                const r = rowOf(el), f = el.dataset.r;
                let v = el.type === 'checkbox' ? el.checked : el.value;
                if (['p1', 'p2', 'p4', 'expiry', 'mcity', 'mnum'].includes(f)) { v = en(v).replace(f === 'expiry' ? /[^\d/]/g : /\D/g, ''); el.value = fa(v); }
                if (f === 'mcity' && v.length >= 3) { const nx = el.closest('.mt-pin').querySelector('[data-r="mnum"]'); if (nx) nx.focus(); }
                r[f] = v;
                if (['p1', 'p2', 'p4', 'letter', 'chassis', 'engine', 'mcity', 'mnum'].includes(f)) scheduleReg(r);
                if (f === 'partyOpen') { r.partyUser = true; r.partyAuto = false; }
                // فیلدهایی که ظاهر کارت را عوض می‌کنند، کارت را دوباره می‌سازند
                if (['type', 'noPlate', 'isNew', 'skipHealth', 'prevBody', 'customCov', 'liability', 'vclass', 'manualVisit', 'partyOpen', 'moto'].includes(f)) {
                    if (f === 'customCov' && v && !r.cov) r.cov = JSON.parse(JSON.stringify(st.covBaseOnly ? { __none: true } : (st.cov || {})));
                    render();
                } else { const s = root.querySelector('#cmr-summary'); if (s) s.innerHTML = summary(); }
            });
        });
        // استعلامِ سنهاب برای ردیف‌های ثالث: عدمِ خسارت / خسارت، تخفیف‌ها، بیمه‌گر و انقضا؛ «نشاندن در همین ردیف» تاریخِ انقضا و شماره‌ی بیمه‌نامه‌ی قبلی را پر می‌کند
        root.querySelectorAll('[data-sh-row]').forEach(h => {
            const r = rowOf(h);
            SanhabUI.button(h, {
                getPrefill: () => ({plate: r.moto || r.noPlate ? null : {p1: en(r.p1), p2: en(r.p2), letter: r.letter, p4: en(r.p4)}, moto: r.moto ? {city: en(r.mcity), num: en(r.mnum)} : null, chassis: r.chassis, engine: r.engine}),
                lastItem: r.shItem || null, lookup: !r.shLooked, source: 'form', title: (r.carName || 'ردیفِ ثالث') + (r.p2 ? ' · ' + fa(r.p4 + ' ' + r.letter + ' ' + r.p2 + ' ایران ' + r.p1) : ''),
                label: 'استعلامِ سنهاب', applyLabel: 'نشاندن در همین ردیف',
                onResult: it => { r.shItem = it; },
                onApply: b => { if (b.end_date) r.expiry = b.end_date; if (b.policy_no && st.kind === 'NEW_POLICY') r.refPolicy = b.policy_no; if (!r.carName && b.vehicle) r.carName = b.vehicle; render(); },
            });
            r.shLooked = true;
        });
        // مالک / بیمه‌گذار: مقدارِ ویرایشگر همان لحظه در وضعیتِ فرم نگه داشته می‌شود
        root.querySelectorAll('[data-pty-scope]').forEach(box => {
            const h = () => {
                const v = PartyUI.collect(box);
                if (box.dataset.ptyScope === 'all') { st.partyMode = v.party_mode || 'COMPANY'; st.party = v.party; st.insured = v.insured; }
                else { const r = rowOf(box); r.partyMode = v.party_mode; r.party = v.party ? Object.assign({}, r.party || {}, v.party) : null; r.insured = v.insured; r.partyUser = true; r.partyAuto = false; }
            };
            box.addEventListener('input', h); box.addEventListener('change', h);
        });
        root.querySelectorAll('[data-reg-box]').forEach(box => { const r = rowOf(box); if (r) bindReg(box, r); });
        root.querySelectorAll('[data-visit]').forEach(b => b.onclick = () => buildVisit(rowOf(b)));
        root.querySelectorAll('[data-visit-edit]').forEach(b => b.onclick = () => {
            const r = rowOf(b);
            VR.openEditor(r.report.id, rep => { r.report = { id: rep.id, no: rep.report_no, photos: rep.photos_count }; render(); });
        });
        root.querySelectorAll('[data-visit-drop]').forEach(b => b.onclick = () => { rowOf(b).report = null; render(); });
        root.querySelectorAll('[data-rcovbase]').forEach(el => el.onchange = () => { const r = rowOf(el); r.cov = el.checked ? { __none: true } : {}; render(); });
        root.querySelectorAll('[data-cov]').forEach(el => el.onchange = () => {
            const cov = covTarget(el.dataset.scope), k = el.dataset.cov, o = form.coverage_options[k];
            if (el.checked) cov[k] = o.tiers ? Object.keys(o.tiers)[0] : true; else delete cov[k];
            render();
        });
        root.querySelectorAll('[data-tier]').forEach(el => el.onchange = () => { covTarget(el.dataset.scope)[el.dataset.tier] = el.value; });
        root.querySelectorAll('[data-file]').forEach(el => el.onchange = () => {
            const r = rowOf(el); r.files[el.dataset.file] = [...(r.files[el.dataset.file] || []), ...el.files]; render();
        });
        root.querySelectorAll('[data-clearfiles]').forEach(b => b.onclick = () => { const r = rowOf(b); b.dataset.clearfiles.split(',').forEach(t => delete r.files[t]); render(); });
    }

    function covTarget(scope) {
        if (scope === 'all') { if (!st.cov) st.cov = {}; return st.cov; }
        const r = st.rows.find(x => 'row:' + x.id === scope);
        if (!r.cov || r.cov.__none) r.cov = {};
        return r.cov;
    }

    async function loadDefaults() {
        st.defaults = null;
        if (!st.companyId) return;
        const d = await post({ action: 'admin_manual_form', company_id: st.companyId });
        if (!d.ok) return;
        st.defaults = d.defaults;
        if (d.defaults.liability_limit && !st.liabilityAll) st.liabilityAll = String(d.defaults.liability_limit);
        if (d.defaults.coverages_from) {
            const c = d.defaults.coverages;
            if (Array.isArray(c) || !c || !Object.keys(c).length) { st.covBaseOnly = true; st.cov = {}; }
            else { st.covBaseOnly = false; st.cov = JSON.parse(JSON.stringify(c)); }
        } else { st.cov = {}; st.covBaseOnly = false; }
    }

    // ---------------- ثبت ----------------
    async function submit() {
        if (!st.companyId) { toast('شرکت را انتخاب کنید.', 'error'); return; }
        if (st.detect && st.detect.busy) { toast('صبر کنید خواندنِ مدارک تمام شود.', 'warning'); return; }
        const noLetter = st.rows.findIndex(r => !r.noPlate && !r.moto && !r.letter && (en(r.p1) + en(r.p2) + en(r.p4)).trim());
        if (noLetter >= 0) { toast(`حرفِ پلاکِ خودروی ردیفِ ${fa(noLetter + 1)} از مدرک خوانده نشد؛ انتخابش کنید.`, 'error'); return; }
        const covOut = c => (!c ? null : (c.__none ? 'none' : c));
        const payload = {
            action: 'admin_create_full_request', company_id: st.companyId, insurer: st.insurer, request_kind: st.kind,
            request_text: st.text,
            default_liability: st.liabilityPerRow ? '' : st.liabilityAll,
            default_coverages: st.covBaseOnly ? 'none' : (st.cov && Object.keys(st.cov).length ? st.cov : null),
            party_mode: st.partyMode || 'COMPANY', party: st.party, insured: st.partyMode === 'SPLIT' ? st.insured : null,
            rows: st.rows.map(r => ({
                insurance_type: r.type,
                // صفرکیلومترِ بی‌پلاک: خانه‌های عددیِ پلاک خالی‌اند، پس حرفِ پیش‌فرضِ «الف» هم فرستاده نمی‌شود (وگرنه «پلاک ناقص» می‌شد)
                ...(r.moto && !r.noPlate
                    ? { plate_p1: en(r.mcity), plate_p2: en(r.mnum), plate_letter: (en(r.mcity) + en(r.mnum)).trim() ? 'موتور' : '', plate_p4: '' }
                    : { plate_p1: r.noPlate ? '' : r.p1, plate_letter: r.noPlate || !(en(r.p1) + en(r.p2) + en(r.p4)).trim() ? '' : r.letter, plate_p2: r.noPlate ? '' : r.p2, plate_p4: r.noPlate ? '' : r.p4 }),
                vehicle_kind: r.moto ? 'MOTOR' : '',
                chassis_no: r.noPlate || r.isNew ? en(r.chassis) : '', engine_no: r.noPlate || r.isNew ? en(r.engine) : '', is_new_vehicle: r.isNew ? 1 : 0,
                car_name: r.carName, car_type: r.carType || '', expiry_date: r.isNew ? '' : en(r.expiry), liability_limit: st.liabilityPerRow ? r.liability : '',
                car_value: en(r.carValue), has_prev_body: r.prevBody, skip_health_inspection: r.skipHealth ? 1 : 0,
                vehicle_class: r.type === 'BODY' ? vclassOf(r) : '',
                coverages: r.type === 'BODY' && r.customCov ? covOut(r.cov || {}) : null,
                ref_policy_number: r.refPolicy, endorsement_request: r.endorse, cancellation_reason: r.cancelReason, row_note: r.note,
                party_mode: r.partyOpen ? r.partyMode : '', party: r.partyOpen ? r.party : null, insured: r.partyOpen && r.partyMode === 'SPLIT' ? r.insured : null,
            })),
        };
        const btn = root.querySelector('[data-act="submit"]');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin ml-1"></i> در حال ثبت...';
        const d = await post(payload);
        if (!d.ok) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check ml-1"></i>ثبت درخواست و بارگذاری مدارک';
            if (window.showAlert) showAlert('ثبت انجام نشد', d.error || 'خطا', 'error'); else toast(d.error, 'error');
            return;
        }
        // بارگذاری نامه و مدارک، یکی‌یکی، با همان مسیر و نام‌گذاریِ استاندارد
        const jobs = [];
        if (st.letter) jobs.push({ plate: null, type: 'letter', file: st.letter });
        st.rows.forEach((r, i) => Object.entries(r.files).forEach(([t, fs]) => fs.forEach(f => jobs.push({ plate: d.plate_ids[i], type: t, file: f }))));
        let failed = 0;
        const mkForm = j => { const fd = new FormData(); fd.append('action', 'admin_upload_plate_doc'); fd.append('request_id', d.request_id);
            if (j.plate) fd.append('plate_id', j.plate); fd.append('doc_type', j.type); fd.append('file', j.file); return fd; };
        // مدارک در پس‌زمینه با نوارِ پیشرفت (upload-kit.js)؛ پنجره بسته می‌شود و کار ادامه دارد
        const bg = jobs.length && window.UploadKit && UploadKit.enqueue;
        if (bg) {
            UploadKit.enqueue({ title: 'مدارکِ درخواست #' + fa(d.request_id), url: API,
                items: jobs.map(j => ({ name: ((form.doc_types || {})[j.type] || (j.type === 'letter' ? 'نامه' : j.type)) + ' — ' + j.file.name, size: j.file.size, form: mkForm(j) })),
                onDone: sum => { toast(`مدارکِ درخواست #${fa(d.request_id)}: ${fa(sum.ok)} از ${fa(sum.total)} بارگذاری شد` + (sum.fail ? ` (${fa(sum.fail)} ناموفق؛ از جزئیاتِ درخواست دوباره بفرستید)` : '.'), sum.fail ? 'warning' : 'success');
                    if (typeof currentRequestId !== 'undefined' && String(currentRequestId) === String(d.request_id) && window.openCompanyRequestDetail) openCompanyRequestDetail(d.request_id); } });
        } else for (let j = 0; j < jobs.length; j++) {
            btn.innerHTML = `<i class="fas fa-circle-notch fa-spin ml-1"></i> بارگذاری مدارک ${fa(j + 1)} از ${fa(jobs.length)}...`;
            try { const x = await (await fetch(API, { method: 'POST', body: mkForm(jobs[j]) })).json(); if (!x.ok) failed++; } catch (e) { failed++; }
        }
        // صفحه‌های شناسایی‌شده (فاکتور/سند/کارت) روی ردیفِ خودشان بایگانی و اطلاعاتشان روی ردیف نشانده می‌شود
        const docItems = [];
        st.rows.forEach((r, i) => (r.docPages || []).forEach(p => { if (d.plate_ids[i]) docItems.push({ token: p.token, n: p.n, plate_id: d.plate_ids[i] }); }));
        for (let k = 0; k < docItems.length; k += 10) {
            btn.innerHTML = `<i class="fas fa-circle-notch fa-spin ml-1"></i> بایگانیِ مدارکِ شناسایی‌شده ${fa(Math.min(k + 10, docItems.length))} از ${fa(docItems.length)}...`;
            const x = await post({ action: 'mreq_doc_attach', items: docItems.slice(k, k + 10) });
            failed += x.ok ? (x.results || []).filter(y => !y.ok).length : Math.min(10, docItems.length - k);
        }
        // مدارکِ انتخاب‌شده از پرونده‌ی خودرو (درخواست‌ها/بیمه‌نامه‌های قبلیِ همین خودرو) روی ردیفِ تازه
        let regDone = 0;
        for (let i = 0; i < st.rows.length; i++) {
            const r = st.rows[i];
            if (!window.RegLink || !r.reg || !r.regSel.size || r.regDismiss === r.reg.key || !d.plate_ids[i]) continue;
            btn.innerHTML = `<i class="fas fa-circle-notch fa-spin ml-1"></i> انتقالِ مدارکِ پرونده‌ی خودروی ${fa(i + 1)}...`;
            const x = await RegLink.attach({ plate_id: d.plate_ids[i] }, [...r.regSel].map(k => r.reg.docs[k]).filter(Boolean));
            if (x && x.ok) { regDone += x.attached || 0; failed += (x.errors || []).length; } else failed++;
        }
        // گزارش‌های بازدیدی که در همین فرم ساخته شده‌اند به ردیفِ خودشان وصل می‌شوند (PDF و عکس‌ها در پوشه‌ی همان ردیف)
        let linked = 0;
        for (let i = 0; i < st.rows.length; i++) {
            const r = st.rows[i];
            if (!r.report || !needsVisit(r) || !d.plate_ids[i]) continue;
            btn.innerHTML = `<i class="fas fa-circle-notch fa-spin ml-1"></i> اتصالِ گزارش بازدید ${fa(i + 1)}...`;
            try { const x = await VR.api('link', { id: r.report.id, type: 'company', target_id: d.plate_ids[i] }); if (x.ok) linked++; else failed++; } catch (e) { failed++; }
        }
        close();
        if (window.showAlert) showAlert('درخواست ثبت شد', `درخواست #${fa(d.request_id)} با ${fa(d.plate_ids.length)} خودرو ثبت شد` + (jobs.length ? (bg ? ` و ${fa(jobs.length)} فایل در پس‌زمینه بارگذاری می‌شود (نوارِ پیشرفت پایینِ صفحه)` : ` و ${fa(Math.max(0, jobs.length - failed))} فایل بارگذاری شد`) : '') + (linked ? ` و ${fa(linked)} گزارش بازدید به ردیف‌ها وصل شد` : '') + (regDone ? ` و ${fa(regDone)} مدرک از پرونده‌ی خودروها روی ردیف‌ها نشست` : '') + (failed ? ` (${fa(failed)} فایل بارگذاری نشد؛ از جزئیات درخواست دوباره بفرستید)` : '') + '.', failed ? 'warning' : 'success');
        if (window.loadCompanyRequests) loadCompanyRequests();
        if (window.openCompanyRequestDetail) openCompanyRequestDetail(d.request_id);
    }

    // ---------------- ویرایش پوشش‌های یک ردیفِ ثبت‌شده (از جزئیات درخواست) ----------------
    async function editCoverages(plateId, currentJson) {
        if (!form) {
            const d = await post({ action: 'admin_manual_form' });
            if (!d.ok) { toast(d.error || 'خطا', 'error'); return; }
            form = d;
        }
        let cur = null; try { cur = currentJson ? JSON.parse(currentJson) : null; } catch (e) {}
        let baseOnly = !!cur && (Array.isArray(cur) ? !cur.length : !Object.keys(cur).length);
        let cov = cur && !Array.isArray(cur) ? { ...cur } : {};
        const ov = document.createElement('div');
        ov.className = 'fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4';
        ov.style.zIndex = '1000001';   // روی مودالِ جزئیات درخواست (z-index: 999999) باز می‌شود
        document.body.appendChild(ov);
        const draw = () => {
            ov.innerHTML = `<div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl p-5 max-h-[90vh] overflow-y-auto">
                <h3 class="font-black text-violet-700 mb-3"><i class="fas fa-list-check ml-1"></i>پوشش‌های درخواستی این خودرو</h3>
                <label class="flex items-center gap-2 text-xs font-bold text-slate-600 mb-3"><input type="checkbox" data-base ${baseOnly ? 'checked' : ''}> فقط پوشش پایه (بدون پوشش تکمیلی)</label>
                ${baseOnly ? '' : covEditor(cov, 'edit')}
                <div class="flex gap-2 mt-4"><button data-ok class="flex-1 bg-violet-600 hover:bg-violet-700 text-white font-bold py-2.5 rounded-xl text-xs">ذخیره</button>
                <button data-no class="flex-1 bg-slate-100 text-slate-600 font-bold py-2.5 rounded-xl text-xs">انصراف</button></div></div>`;
            ov.querySelector('[data-base]').onchange = e => { baseOnly = e.target.checked; draw(); };
            ov.querySelectorAll('[data-cov]').forEach(el => el.onchange = () => {
                const k = el.dataset.cov, o = form.coverage_options[k];
                if (el.checked) cov[k] = o.tiers ? Object.keys(o.tiers)[0] : true; else delete cov[k];
                draw();
            });
            ov.querySelectorAll('[data-tier]').forEach(el => el.onchange = () => { cov[el.dataset.tier] = el.value; });
            ov.querySelector('[data-no]').onclick = () => ov.remove();
            ov.querySelector('[data-ok]').onclick = async () => {
                if (!baseOnly && !Object.keys(cov).length) { toast('حداقل یک پوشش انتخاب کنید یا «فقط پوشش پایه» را بزنید.', 'error'); return; }
                const d = await post({ action: 'set_row_coverages', plate_id: plateId, coverages: baseOnly ? 'none' : cov });
                if (!d.ok) { toast(d.error || 'خطا', 'error'); return; }
                ov.remove(); toast('پوشش‌ها ذخیره شد.', 'success');
                if (typeof currentRequestId !== 'undefined' && currentRequestId && window.openCompanyRequestDetail) openCompanyRequestDetail(currentRequestId);
            };
        };
        draw();
    }

    // ---------------- پیش‌فرضِ پوشش‌های بدنه و تعهدِ ثالث (تعریفِ شرکت، هر درخواست، ورود از نامه) ----------------
    async function ensureForm() {
        if (form) return true;
        const d = await post({ action: 'admin_manual_form' });
        if (!d.ok) { toast(d.error || 'خطا', 'error'); return false; }
        form = d; return true;
    }
    // cov: شیء پوشش‌ها | 'none' (فقط پایه) | null (تعیین نشده)؛ برچسبِ کوتاه برای نمایش
    function defaultsText(cov, liab) {
        const opts = (form && form.coverage_options) || {};
        if (typeof cov === 'string' && cov !== 'none') { try { cov = JSON.parse(cov); } catch (e) { cov = null; } }
        const isBase = cov === 'none' || (cov && typeof cov === 'object' && !Object.keys(cov).length);
        const covTxt = cov === null || cov === undefined ? 'تعیین نشده' : isBase ? 'فقط پوشش پایه'
            : Object.keys(cov).map(k => opts[k] ? opts[k].label.replace(/\s*\(.*?\)\s*/g, '') + (opts[k].tiers && opts[k].tiers[String(cov[k])] ? ' (' + opts[k].tiers[String(cov[k])] + ')' : '') : '').filter(Boolean).join('، ') || 'تعیین نشده';
        return { cov: covTxt, liab: Number(en(liab || 0)) ? money(liab) + ' ریال' : 'تعیین نشده' };
    }
    // پنجره‌ی انتخاب؛ نتیجه: { coverages: شیء|'none'|null, liability: '…'|'' , apply } یا null (انصراف)
    async function pickDefaults(o = {}) {
        if (!(await ensureForm())) return null;
        let cur = o.coverages; if (typeof cur === 'string' && cur !== 'none') { try { cur = JSON.parse(cur); } catch (e) { cur = null; } }
        let mode = cur === null || cur === undefined ? 'unset' : (cur === 'none' || (typeof cur === 'object' && !Object.keys(cur).length) ? 'base' : 'pick');
        let cov = mode === 'pick' ? { ...cur } : {};
        let liab = o.liability ? String(o.liability) : '';
        let apply = o.apply ? 'empty' : 'none';
        return new Promise(resolve => {
            const ov = document.createElement('div');
            ov.className = 'fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4';
            ov.style.zIndex = '1000002';
            document.body.appendChild(ov);
            const done = v => { ov.remove(); resolve(v); };
            const radio = (v, t) => `<label class="flex items-center gap-1.5 cursor-pointer"><input type="radio" name="cmr-cm" value="${v}" ${mode === v ? 'checked' : ''}> ${t}</label>`;
            const draw = () => {
                ov.innerHTML = `<div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl p-5 max-h-[90vh] overflow-y-auto">
                    <h3 class="font-black text-indigo-700"><i class="fas fa-sliders ml-1"></i>${esc(o.title || 'پیش‌فرضِ پوشش‌ها و تعهد')}</h3>
                    ${o.sub ? `<p class="text-[11px] text-slate-500 mt-1 leading-6">${esc(o.sub)}</p>` : ''}
                    ${o.showLiab !== false ? `<div class="mt-4"><label class="block text-[11px] font-black text-sky-700 mb-1"><i class="fas fa-shield-halved ml-1"></i>تعهدِ مالیِ ثالث</label>
                        <select data-liab class="w-full sm:w-72 border-2 border-slate-200 rounded-xl px-3 py-2 text-xs bg-white">${liabilityOptions(liab, '— تعیین نشده —')}</select></div>` : ''}
                    ${o.showCov !== false ? `<div class="mt-4"><label class="block text-[11px] font-black text-violet-700 mb-1"><i class="fas fa-list-check ml-1"></i>پوشش‌های بدنه</label>
                        <div class="flex flex-wrap gap-4 text-xs font-bold text-slate-600 mb-2">${radio('unset', 'تعیین نشده')}${radio('base', 'فقط پوشش پایه')}${radio('pick', 'پوشش‌های تکمیلی')}</div>
                        ${mode === 'pick' ? covEditor(cov, 'def') : ''}</div>` : ''}
                    ${o.apply ? `<div class="mt-4 rounded-xl bg-slate-50 border p-3 text-[11px] font-bold text-slate-600 space-y-1.5">
                        <p class="text-slate-500">روی ردیف‌های همین درخواست:</p>
                        <label class="flex items-center gap-1.5 cursor-pointer"><input type="radio" name="cmr-ap" value="none" ${apply === 'none' ? 'checked' : ''}> فقط برای ردیف‌هایی که از این به بعد اضافه می‌شوند</label>
                        <label class="flex items-center gap-1.5 cursor-pointer"><input type="radio" name="cmr-ap" value="empty" ${apply === 'empty' ? 'checked' : ''}> ردیف‌هایی که تعهد/پوشش ندارند هم پر شوند</label>
                        <label class="flex items-center gap-1.5 cursor-pointer"><input type="radio" name="cmr-ap" value="all" ${apply === 'all' ? 'checked' : ''}> همه‌ی ردیف‌های صادرنشده با همین عوض شوند</label></div>` : ''}
                    <div class="flex gap-2 mt-5"><button data-ok class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded-xl text-xs">تأیید</button>
                    <button data-no class="flex-1 bg-slate-100 text-slate-600 font-bold py-2.5 rounded-xl text-xs">انصراف</button></div></div>`;
                const l = ov.querySelector('[data-liab]'); if (l) l.onchange = () => { liab = l.value; };
                ov.querySelectorAll('[name="cmr-cm"]').forEach(el => el.onchange = () => { mode = el.value; draw(); });
                ov.querySelectorAll('[name="cmr-ap"]').forEach(el => el.onchange = () => { apply = el.value; });
                ov.querySelectorAll('[data-cov]').forEach(el => el.onchange = () => {
                    const k = el.dataset.cov, op = form.coverage_options[k];
                    if (el.checked) cov[k] = op.tiers ? Object.keys(op.tiers)[0] : true; else delete cov[k];
                    draw();
                });
                ov.querySelectorAll('[data-tier]').forEach(el => el.onchange = () => { cov[el.dataset.tier] = el.value; });
                ov.querySelector('[data-no]').onclick = () => done(null);
                ov.querySelector('[data-ok]').onclick = () => {
                    if (mode === 'pick' && !Object.keys(cov).length) { toast('حداقل یک پوشش انتخاب کنید یا «فقط پوشش پایه» را بزنید.', 'error'); return; }
                    done({ coverages: mode === 'unset' ? null : mode === 'base' ? 'none' : { ...cov }, liability: liab, apply });
                };
            };
            draw();
        });
    }

    window.CompanyManualRequest = { open, close, editCoverages, pickDefaults, defaultsText, ensureForm };
})();
