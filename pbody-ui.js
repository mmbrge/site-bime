// فایل: pbody-ui.js
// «استعلام بدنه بیمه مرکزی» و «استعلام خسارت» — فقط سامانه‌ی پاسارگاد (همان نشستِ ورودِ استعلاماتِ پاسارگاد)
//   PBodyUI.form(host, opts)   فرم + نتیجه داخلِ یک بخش (استعلامات ← بدنه بیمه مرکزی)
//   PBodyUI.open(opts)         همان فرم در پنجره (پنلِ صدورِ ردیف‌های بدنه)
//   PBodyUI.details(item)      جزئیاتِ یک استعلامِ ثبت‌شده (تاریخچه)
// opts: {prefill: {type, unique, print, chassis, engine, vin, plate: {p1, p2, letter, p4}, policy_no}, source: 'panel'|'form', ref: {type, id},
//        save: {type: 'C'|'P', id, onSaved}  (ذخیره‌ی استعلامِ خسارت روی همان ردیف، با مهر), onApply(row, item), applyLabel, tab: 'body'|'damage'}
// ترتیبِ نتیجه: آخرین بیمه‌نامه (نزدیک‌ترین پایان به امروز) اول؛ همه‌ی ردیف‌ها نشان داده می‌شوند.
// منطق: api/inquiry_actions.php (pb_*) و api/_pbody.php
(function () {
    'use strict';
    if (window.PBodyUI) return;
    const API = 'api/inquiry_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const TYPES = [['plate', 'پلاک ملی'], ['chassis', 'شماره شاسی'], ['vin', 'vin'], ['engine', 'شماره موتور'], ['chassis_engine', 'شماره شاسی و موتور'], ['print', 'شماره چاپی'], ['unique', 'کد یکتا']];
    const LETTERS = ['الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'د', 'ز', 'س', 'ش', 'ص', 'ط', 'ع', 'ف', 'ق', 'ک', 'گ', 'ل', 'م', 'ن', 'و', 'ه', 'ی', 'ژ', 'D', 'S'];
    async function post(body) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(body)})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }
    const fileUrl = (it, f) => `${API}?action=pb_file&id=${it.id}&f=${f}`;
    // «خروجی با مهر»: پیوندهای PDF/عکسِ همان ردیفِ دکمه‌ها با stamp=1
    document.addEventListener('change', e => {
        const cb = e.target.closest && e.target.closest('[data-pstamp]'); if (!cb) return;
        cb.closest('div').querySelectorAll('a[data-out]').forEach(a => { a.href = a.href.replace(/&stamp=1$/, '') + (cb.checked ? '&stamp=1' : ''); });
    });
    const exportUrl = (it, fmt, sel) => `${API}?action=pb_export&fmt=${fmt}&id=${it.id}${sel && sel.length ? '&rows=' + sel.join(',') : ''}`;
    const dkey = d => { const m = /(1[34]\d\d)\D(\d{1,2})\D(\d{1,2})/.exec(en(d || '')); return m ? m[1] + m[2].padStart(2, '0') + m[3].padStart(2, '0') : ''; };
    const todayKey = () => { try { const p = new Intl.DateTimeFormat('en-US-u-ca-persian', {year: 'numeric', month: '2-digit', day: '2-digit'}).formatToParts(new Date()); const g = t => (p.find(x => x.type === t) || {}).value || ''; return en(g('year')).replace(/\D/g, '') + g('month') + g('day'); } catch (e) { return ''; } };

    function css() {
        if (document.getElementById('pbu-css')) return;
        const s = document.createElement('style'); s.id = 'pbu-css';
        s.textContent = `
        .pbu{font-size:12px}
        .pbu-card{background:#fff;border:1.5px solid #e9edf5;border-radius:20px;padding:16px}
        .pbu-plate{display:flex;direction:ltr;align-items:stretch;border:2.5px solid #0f172a;border-radius:10px;overflow:hidden;background:#fff;max-width:330px;height:48px;margin-top:4px}
        .pbu-plate .fl{width:22px;background:linear-gradient(#16a34a 33%,#fff 33% 66%,#dc2626 66%)}
        .pbu-plate input,.pbu-plate select{border:0;outline:none;text-align:center;font-size:19px;font-weight:900;background:transparent;min-width:0}
        .pbu-plate .ir{border-left:2px solid #0f172a;display:flex;flex-direction:column;align-items:center;justify-content:center;width:56px}
        .pbu-plate .ir small{font-size:9px;font-weight:900}.pbu-plate .ir input{width:44px;font-size:16px}
        .pbu-tabs{display:flex;gap:6px;background:#f1f5f9;border-radius:14px;padding:4px;margin:10px 0}
        .pbu-tab{flex:1;border-radius:11px;padding:8px;font-size:12px;font-weight:900;color:#475569}.pbu-tab.on{background:linear-gradient(120deg,#0f766e,#0d9488);color:#fff;box-shadow:0 8px 18px -12px #0f766e}
        .pbu-f{display:block;font-size:11px;font-weight:800;color:#475569}
        .pbu-in{width:100%;margin-top:4px;border:1.5px solid #e2e8f0;border-radius:12px;padding:8px 10px;font-size:13px;background:#fff}
        .pbu-in:focus{outline:none;border-color:#0d9488;box-shadow:0 0 0 3px #99f6e455}
        .pbu-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
        .pbu-go{background:linear-gradient(120deg,#0f766e,#0d9488);color:#fff;font-weight:900;border-radius:14px;padding:10px 18px;font-size:12.5px;display:inline-flex;align-items:center;gap:6px}
        .pbu-go:disabled{opacity:.6}
        .pbu-btn{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:800;border-radius:10px;padding:6px 10px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0}
        .pbu-btn:hover{background:#e2e8f0}.pbu-btn.g{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.pbu-btn.r{background:#fff1f2;color:#be123c;border-color:#fecdd3}.pbu-btn.b{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
        .pbu-sum{border-radius:18px;padding:14px;color:#fff;background:linear-gradient(120deg,#134e4a,#0f766e 55%,#0e7490);display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
        .pbu-sum b{font-size:15px;font-weight:900}.pbu-sum small{display:block;font-size:11px;opacity:.85}
        .pbu-pill{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:900;border-radius:999px;padding:3px 10px}
        .pbu-row{border:1.5px solid #e2e8f0;border-radius:16px;margin-top:10px;overflow:hidden;background:#fff}
        .pbu-row.first{border-color:#5eead4;box-shadow:0 10px 24px -18px #0f766e}
        .pbu-rh{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:11.5px;font-weight:900;color:#0f172a}
        .pbu-row.first .pbu-rh{background:#f0fdfa}
        .pbu-kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:0}
        .pbu-kv div{padding:6px 12px;border-bottom:1px dashed #eef2f7;font-size:11.5px}.pbu-kv small{display:block;font-size:9.5px;color:#94a3b8;font-weight:700}
        .pbu-kv b{font-weight:800;color:#1e293b;word-break:break-all}
        .pbu-dmg{margin:8px 12px 10px;border:1px solid #fecdd3;background:#fff1f2;border-radius:12px;padding:6px 10px;font-size:11px;color:#9f1239}
        .pbu-res{margin-top:12px}
        .pbu-ok{border:1.5px solid #a7f3d0;background:#ecfdf5;color:#065f46;border-radius:16px;padding:12px}
        .pbu-bad{border:1.5px solid #fecdd3;background:#fff1f2;color:#9f1239;border-radius:16px;padding:12px}
        .pbu-shot{display:block;max-width:100%;border:1px solid #e2e8f0;border-radius:12px;margin-top:8px}
        .pbu-ov{position:fixed;inset:0;z-index:1000010;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;padding:14px}
        .pbu-box{width:min(1050px,100%);max-height:94vh;overflow:auto;background:#f8fafc;border-radius:24px;box-shadow:0 40px 80px -30px rgba(0,0,0,.5)}
        .pbu-bh{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px 16px;color:#fff;background:linear-gradient(120deg,#134e4a,#0d9488);border-radius:24px 24px 0 0}
        .pbu-mp{display:inline-flex;direction:ltr;align-items:stretch;border:1.8px solid #0f172a;border-radius:6px;overflow:hidden;background:#fff;height:26px;font-weight:900;font-size:13px;color:#0f172a;vertical-align:middle}
        .pbu-mp i{width:9px;background:linear-gradient(#16a34a 33%,#fff 33% 66%,#dc2626 66%)}.pbu-mp b{display:flex;align-items:center;padding:0 5px}
        .pbu-mp span{display:flex;flex-direction:column;align-items:center;justify-content:center;border-left:1.8px solid #0f172a;padding:0 5px;line-height:1;font-size:12px}.pbu-mp small{font-size:7px}
        .pbu-end{margin:6px 12px 10px;border:1px solid #c7d2fe;background:#eef2ff;border-radius:12px;padding:6px 10px;font-size:11px;color:#3730a3}
        .pbu-end table{width:100%;border-collapse:collapse;margin-top:4px}.pbu-end th{text-align:right;font-weight:800;color:#6366f1;padding:3px 6px;font-size:10px}.pbu-end td{padding:4px 6px;border-top:1px solid #e0e7ff;color:#1e293b}
        .pbu-chk{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:800;color:#334155;cursor:pointer}
        `;
        document.head.appendChild(s);
    }
    // پلاک به شکلِ خودِ پلاک (چپ‌به‌راست: دو رقم، حرف، سه رقم | ایران و کدِ شهر)
    // نوارِ پیمایشِ افقی بالای جدول هم (همگام با نوارِ پایین)؛ فقط وقتی جدول از قاب پهن‌تر است دیده می‌شود
    window.DualScroll = window.DualScroll || function (el) {
        if (!el || el.dataset.dual) return;
        el.dataset.dual = '1';
        const top = document.createElement('div');
        top.style.cssText = 'overflow-x:auto;overflow-y:hidden;height:14px;margin-bottom:2px;direction:' + getComputedStyle(el).direction;
        const inner = document.createElement('div'); inner.style.height = '1px'; top.appendChild(inner);
        el.parentNode.insertBefore(top, el);
        const size = () => { inner.style.width = el.scrollWidth + 'px'; top.style.display = el.scrollWidth > el.clientWidth + 2 ? '' : 'none'; };
        top.addEventListener('scroll', () => { if (el.scrollLeft !== top.scrollLeft) el.scrollLeft = top.scrollLeft; });
        el.addEventListener('scroll', () => { if (top.scrollLeft !== el.scrollLeft) top.scrollLeft = el.scrollLeft; });
        size();
        if (window.ResizeObserver) { const ro = new ResizeObserver(size); ro.observe(el); if (el.firstElementChild) ro.observe(el.firstElementChild); }
        else window.addEventListener('resize', size);
    };
    const dual = h => { if (h) h.querySelectorAll('.pbu-end .overflow-x-auto').forEach(x => window.DualScroll(x)); };
    const plateFa = r => { const p = r.plate_parts; return p ? `<span class="pbu-mp" title="${esc(fa(r.plate_fa || ''))}"><i></i><b>${fa(p.p4)}</b><b>${esc(p.letter)}</b><b>${fa(p.p2)}</b><span><small>ایران</small>${fa(p.p1)}</span></span>` : esc(fa(r.plate_fa || '')); };
    const endsHtml = r => (r.endorsements || []).length ? `<div class="pbu-end"><b><i class="fas fa-list-ol ml-1"></i>الحاقیه‌ها (${fa(r.endorsements.length)})</b><div class="overflow-x-auto"><table><tr><th>#</th><th>نوع الحاقیه</th><th>تاریخ صدور</th><th>تاریخ اثر</th><th>کد یکتا</th><th>شماره چاپی</th><th>پلاک</th></tr>
        ${r.endorsements.map((e, j) => `<tr><td>${fa(e.n || j + 1)}</td><td><b>${esc(e.endorse_type || '—')}</b></td><td>${esc(fa(e.issue_date || '—'))}</td><td>${esc(fa(e.effect_date || '—'))}</td><td>${esc(fa(e.unique_code || '—'))}</td><td dir="ltr">${esc(fa(e.print_no || '—'))}</td><td>${esc(fa(e.plate_text || '—'))}</td></tr>`).join('')}</table></div></div>` : '';
    const kv = (l, v, ltr) => v ? `<div><small>${l}</small><b ${ltr ? 'dir="ltr"' : ''}>${esc(fa(v))}</b></div>` : '';

    // ---- نتیجه‌ی استعلامِ بدنه ----
    function bodyResultHtml(it, o) {
        const d = it.pbody || {}, rows = d.rows || [];
        if (!rows.length) return `<div class="pbu-bad"><b><i class="fas fa-circle-info ml-1"></i>بیمه‌نامه‌ای پیدا نشد</b><p class="text-[11px] mt-1">${esc(fa(d.message || it.notice || ''))}</p></div>`;
        const f = rows[0], tk = todayKey(), active = tk && dkey(f.end_date) >= tk;
        const nd = rows.reduce((a, r) => a + (r.damages || []).length, 0);
        return `<div class="pbu-sum"><div><small>آخرین بیمه‌نامه (نزدیک‌ترین پایان به امروز) · ${fa(rows.length)} بیمه‌نامه</small>
                <b>${esc(f.company || '—')} · تا ${esc(fa(f.end_date || '—'))}</b><small>${esc(fa(f.insured || ''))}${f.print_no ? ' · <span dir="ltr">' + esc(fa(f.print_no)) + '</span>' : ''}</small></div>
                <div class="flex flex-wrap gap-2"><span class="pbu-pill" style="background:${active ? '#ccfbf1' : '#e2e8f0'};color:${active ? '#0f766e' : '#475569'}">${active ? 'فعال' : 'منقضی'}</span>
                <span class="pbu-pill" style="background:${nd ? '#ffe4e6' : '#dcfce7'};color:${nd ? '#be123c' : '#15803d'}">${nd ? fa(nd) + ' پرونده‌ی خسارت' : 'بدونِ خسارت'}</span>
                ${f.no_loss_years !== undefined && f.no_loss_years !== '' ? `<span class="pbu-pill" style="background:#e0e7ff;color:#3730a3">${fa(f.no_loss_years)} سال تخفیف</span>` : ''}</div></div>
            <div class="flex flex-wrap gap-2 mt-2">
                ${o && o.onApply ? `<button class="pbu-btn g" data-pa><i class="fas fa-file-import"></i> ${esc(o.applyLabel || 'نشاندنِ اطلاعاتِ آخرین بیمه‌نامه روی ردیف')}</button>` : ''}
                <button class="pbu-btn r" data-pdmg><i class="fas fa-file-shield"></i> استعلامِ خسارت با آخرین بیمه‌نامه</button>
                <a class="pbu-btn" href="${exportUrl(it, 'xlsx')}" data-sel-x><i class="fas fa-file-excel text-emerald-600"></i> اکسل</a>
                <a class="pbu-btn" href="${exportUrl(it, 'pdf')}" target="_blank" data-sel-p data-out><i class="fas fa-file-pdf text-rose-600"></i> PDFِ سایت</a>
                ${d.print ? `<a class="pbu-btn b" href="${fileUrl(it, 'print')}" target="_blank" data-out><i class="fas fa-print"></i> چاپِ سامانه (PDF)</a>` : ''}
                <label class="pbu-btn" style="cursor:pointer" title="PDF و عکس با مهرِ استعلامات باز/دانلود می‌شود"><input type="checkbox" data-pstamp style="accent-color:#0f766e"> خروجی با مهر</label>
            </div>
            ${rows.map((r, i) => `<div class="pbu-row ${i === 0 ? 'first' : ''}"><div class="pbu-rh"><input type="checkbox" class="accent-teal-600" data-rsel="${i}">
                    <span>ردیف ${fa(i + 1)}</span><span class="text-teal-700">${esc(r.company || '')}</span><span class="text-slate-500 font-bold">${esc(fa(r.start_date || ''))} تا <b class="text-slate-800">${esc(fa(r.end_date || ''))}</b></span>
                    ${i === 0 ? '<span class="pbu-pill" style="background:#ccfbf1;color:#0f766e">آخرین</span>' : ''}${(r.endorsements || []).length ? `<span class="pbu-pill" style="background:#e0e7ff;color:#3730a3">${fa(r.endorsements.length)} الحاقیه</span>` : ''}${(r.damages || []).length ? `<span class="pbu-pill" style="background:#ffe4e6;color:#be123c">${fa(r.damages.length)} خسارت</span>` : ''}</div>
                <div class="pbu-kv">${kv('کد یکتا', r.unique_code)}${kv('شماره چاپی', r.print_no, true)}${kv('بیمه‌گذار', r.insured)}${kv('تاریخ صدور', r.issue_date)}
                    ${r.plate_fa || r.plate_parts ? `<div><small>پلاک</small><b>${plateFa(r)}</b></div>` : ''}${kv('نوع پلاک', r.plate_kind)}${kv('شماره شاسی', r.chassis, true)}${kv('شماره موتور', r.engine, true)}${kv('VIN', r.vin, true)}
                    ${kv('گروه خودرو', r.car_group)}${kv('سیستم خودرو', r.car_system)}${kv('سال تخفیف عدم خسارت', r.no_loss_years)}${kv('کد یکتا بیمه‌نامه‌ی قبلی', r.prev_unique_code)}</div>
                ${endsHtml(r)}
                ${(r.damages || []).map(dm => `<div class="pbu-dmg"><b>خسارت · پرونده ${esc(fa(dm.case_code || ''))}</b> — وقوع ${esc(fa(dm.accident_date || '—'))} · اعلام ${esc(fa(dm.declare_date || '—'))} · تشکیل ${esc(fa(dm.file_date || '—'))}${dm.complete_date ? ' · تکمیل ' + esc(fa(dm.complete_date)) : ''}</div>`).join('')}
            </div>`).join('')}`;
    }
    // ---- نتیجه‌ی استعلامِ خسارت ----
    function damageResultHtml(it, o) {
        const d = it.pdamage || {};
        const u = d.used || it.q || {};
        const head = d.found
            ? `<div class="pbu-bad"><b><i class="fas fa-triangle-exclamation ml-1"></i>خسارت ثبت شده است</b><p class="text-[11px] mt-1">${esc(fa(d.message || ''))}</p></div>`
            : `<div class="pbu-ok"><b><i class="fas fa-circle-check ml-1"></i>${esc(d.message || 'اطلاعات خسارتی با مشخصات درخواستی ثبت نشده است')}</b>
                <p class="text-[11px] mt-1">بیمه‌نامه <span dir="ltr">${esc(fa(u.policy_no || '—'))}</span> · شاسی <span dir="ltr">${esc(u.chassis || '—')}</span> · موتور <span dir="ltr">${esc(u.engine || '—')}</span></p></div>`;
        return `${head}<div class="flex flex-wrap gap-2 mt-2">
                ${d.pdf ? `<a class="pbu-btn b" href="${fileUrl(it, 'damage')}" target="_blank" data-out><i class="fas fa-file-pdf"></i> PDFِ استعلامِ خسارت (سامانه)</a>` : ''}
                ${d.shot ? `<a class="pbu-btn" href="${fileUrl(it, 'shot')}" target="_blank" data-out><i class="fas fa-image"></i> تصویرِ صفحه</a>` : ''}
                ${d.pdf || d.shot ? '<label class="pbu-btn" style="cursor:pointer" title="PDF و عکس با مهرِ استعلامات باز/دانلود می‌شود"><input type="checkbox" data-pstamp style="accent-color:#0f766e"> خروجی با مهر</label>' : ''}
                ${o && o.save && (d.pdf || d.shot) ? `<button class="pbu-btn g" data-psave="pdf"><i class="fas fa-floppy-disk"></i> ذخیره روی ردیف (PDF، با مهر)</button>${d.shot && !d.pdf ? '<button class="pbu-btn g" data-psave="shot"><i class="fas fa-floppy-disk"></i> ذخیره روی ردیف (تصویر، با مهر)</button>' : ''}` : ''}
            </div>${d.shot ? `<img class="pbu-shot" src="${fileUrl(it, 'shot')}" alt="تصویرِ صفحه‌ی استعلامِ خسارت">` : ''}`;
    }

    function form(host, opts) {
        css();
        opts = opts || {};
        const pf = opts.prefill || {};
        const S = {tab: opts.tab || 'body', type: pf.type || (pf.plate && pf.plate.p2 ? 'plate' : (pf.chassis || pf.vin ? 'chassis' : (pf.print ? 'print' : 'plate'))), busy: false, last: null, dmg: null};
        const pl = pf.plate || {};
        host.innerHTML = `<div class="pbu"><div class="pbu-card">
            <div class="flex flex-wrap items-center justify-between gap-2"><b class="text-[14px] font-black text-slate-800"><i class="fas fa-car-burst text-teal-600 ml-1"></i>استعلامِ بیمه‌ی بدنه</b></div>
            <div class="pbu-tabs"><button class="pbu-tab" data-t="body"><i class="fas fa-magnifying-glass ml-1"></i>استعلام بدنه بیمه مرکزی</button><button class="pbu-tab" data-t="damage"><i class="fas fa-file-shield ml-1"></i>استعلام خسارت</button></div>
            <div data-pane="body">
                <div class="pbu-grid"><label class="pbu-f">انواع استعلام<select class="pbu-in" data-k="type">${TYPES.map(([k, t]) => `<option value="${k}">${t}</option>`).join('')}</select></label>
                    <label class="pbu-f" data-for="unique">کد یکتا<input class="pbu-in" dir="ltr" data-k="unique" value="${esc(pf.unique || '')}"></label>
                    <label class="pbu-f" data-for="print">شماره چاپی<input class="pbu-in" dir="ltr" data-k="print" value="${esc(pf.print || pf.policy_no || '')}" placeholder="2/49357/5650-3/1405/1033"></label>
                    <label class="pbu-f" data-for="chassis vin chassis_engine">شماره شاسی / VIN<input class="pbu-in" dir="ltr" data-k="chassis" value="${esc(pf.chassis || pf.vin || '')}"></label>
                    <label class="pbu-f" data-for="engine chassis_engine">شماره موتور<input class="pbu-in" dir="ltr" data-k="engine" value="${esc(pf.engine || '')}"></label>
                    <div class="pbu-f" data-for="plate" style="grid-column:1/-1">پلاکِ خودرو <input data-pq class="pbu-in" style="display:inline-block;width:auto;min-width:220px;font-size:12px;margin-right:6px;padding:4px 8px" placeholder="یا یک‌جا بنویسید/بچسبانید: ۹۹۴ط۵۹ ۷۸">
                        <div class="pbu-plate"><span class="fl"></span>
                        <input data-k="p4" maxlength="2" inputmode="numeric" style="width:52px" placeholder="۱۱" value="${esc(fa(pl.p4 || ''))}">
                        <select data-k="letter" style="width:62px">${LETTERS.map(l => `<option ${l === (pl.letter === 'آ' ? 'الف' : pl.letter) ? 'selected' : ''}>${l}</option>`).join('')}</select>
                        <input data-k="p2" maxlength="3" inputmode="numeric" style="width:70px" placeholder="۱۲۳" value="${esc(fa(pl.p2 || ''))}">
                        <span class="ir"><small>ایران</small><input data-k="p1" maxlength="2" inputmode="numeric" placeholder="۲۱" value="${esc(fa(pl.p1 || ''))}"></span></div></div></div>
                <div class="flex flex-wrap items-center gap-3 mt-3"><button class="pbu-go" data-go="body"><i class="fas fa-magnifying-glass"></i> استعلام</button>
                    <label class="pbu-chk"><input type="checkbox" data-k="print_too" checked> چاپِ سامانه (PDF) هم گرفته شود</label><span class="text-[11px] text-slate-500" data-st="body"></span></div>
                <div class="pbu-res" data-res="body"></div>
            </div>
            <div data-pane="damage" hidden>
                <div class="pbu-grid"><label class="pbu-f">شماره بیمه‌نامه (قبلی)<input class="pbu-in" dir="ltr" data-k="d_pol" value="${esc(pf.policy_no || pf.print || '')}"></label>
                    <label class="pbu-f">شماره شاسی<input class="pbu-in" dir="ltr" data-k="d_ch" value="${esc(pf.chassis || pf.vin || '')}"></label>
                    <label class="pbu-f">شماره موتور<input class="pbu-in" dir="ltr" data-k="d_en" value="${esc(pf.engine || '')}"></label>
                    <label class="pbu-f">نوع بیمه<select class="pbu-in" data-k="d_ins"><option value="both">هردو</option><option value="body">بدنه</option><option value="third">ثالث</option></select></label></div>
                <div class="flex flex-wrap items-center gap-4 mt-3">
                    <span class="text-[11px] font-black text-slate-600">خروجی:</span>
                    <label class="pbu-chk"><input type="radio" name="pbo-${Date.now()}" data-k="d_out" value="pdf"> PDF</label>
                    <label class="pbu-chk"><input type="radio" name="pbo-${Date.now()}" data-k="d_out" value="shot" checked> تصویرِ صفحه (اگر خسارتی ثبت نشده)</label>
                    ${opts.save ? '<label class="pbu-chk"><input type="checkbox" data-k="d_save" checked> ذخیره به‌عنوانِ «استعلام خسارت»ِ همین ردیف (با مهر)</label>' : ''}</div>
                <p class="text-[10.5px] text-slate-500 mt-1">اگر خسارت ثبت شده باشد، PDFِ خودِ سامانه ذخیره می‌شود؛ اگر نه، تصویرِ همان صفحه با شماره بیمه‌نامه، شاسی و موتورِ جستجو (یا همان تصویر داخلِ PDF).</p>
                <div class="flex flex-wrap items-center gap-3 mt-3"><button class="pbu-go" data-go="damage" style="background:linear-gradient(120deg,#9f1239,#e11d48)"><i class="fas fa-file-shield"></i> استعلامِ خسارت</button><span class="text-[11px] text-slate-500" data-st="damage"></span></div>
                <div class="pbu-res" data-res="damage"></div>
            </div></div></div>`;
        const $ = s => host.querySelector(s);
        const val = k => { const e = host.querySelector(`[data-k="${k}"]`); return e ? en(e.value).trim() : ''; };
        $('[data-k="type"]').value = S.type;
        const paint = () => {
            host.querySelectorAll('[data-t]').forEach(b => b.classList.toggle('on', b.dataset.t === S.tab));
            host.querySelectorAll('[data-pane]').forEach(p => p.hidden = p.dataset.pane !== S.tab);
            host.querySelectorAll('[data-for]').forEach(l => l.style.display = l.dataset.for.split(' ').includes(S.type) ? '' : 'none');
        };
        host.querySelectorAll('[data-t]').forEach(b => b.onclick = () => { S.tab = b.dataset.t; paint(); });
        $('[data-k="type"]').onchange = e => { S.type = e.target.value; paint(); };
        // پلاک: همان رفتارِ کادرِ پلاکِ سایت — یک‌جا چسباندن («۹۹۴ط۵۹ ۷۸»، «۲۱ایران - ۱۲۳ ب ۱۱»)، فقط رقم، رفتن به خانه‌ی بعد
        const plateBox = host.querySelector('.pbu-plate');
        const pq = host.querySelector('[data-pq]');
        if (pq) pq.addEventListener('input', () => {
            const pp = window.SanhabUI && SanhabUI.parsePlate ? SanhabUI.parsePlate(pq.value) : null; if (!pp) return;
            const set = (k, v) => { const x = host.querySelector(`[data-k="${k}"]`); if (x) x.value = k === 'letter' ? v : fa(v); };
            set('p4', pp.p4); set('p2', pp.p2); set('p1', pp.p1);
            const sl = host.querySelector('[data-k="letter"]'); if (sl && ![...sl.options].some(o => o.value === pp.letter)) sl.insertAdjacentHTML('beforeend', `<option>${esc(pp.letter)}</option>`); if (sl) sl.value = pp.letter;
            pq.style.borderColor = '#10b981';
        });
        if (plateBox) plateBox.querySelectorAll('input').forEach(i => i.addEventListener('input', () => {
            i.value = fa(en(i.value).replace(/\D/g, ''));
            if (i.maxLength > 0 && en(i.value).length >= i.maxLength && i.dataset.k !== 'p1') { const all = [...plateBox.querySelectorAll('input,select')]; const n = all[all.indexOf(i) + 1]; if (n) n.focus(); }
        }));
        paint();
        const st = (w, h) => { const e = $(`[data-st="${w}"]`); if (e) e.innerHTML = h || ''; };
        // نشستِ بسته: کپچای ورودِ مشترکِ پاسارگاد (inquiry.js)، بعد همان استعلام دوباره
        const withLogin = (r, again) => {
            if (r && r.need_login && window.Inquiry && Inquiry.login) { Inquiry.login(again); return true; }
            return false;
        };
        const bindBody = it => {
            const box = $('[data-res="body"]');
            box.innerHTML = bodyResultHtml(it, opts);
            dual(box);
            const rows = (it.pbody || {}).rows || [];
            const selOf = () => [...box.querySelectorAll('[data-rsel]:checked')].map(c => Number(c.dataset.rsel));
            const ex = box.querySelector('[data-sel-x]'), ep = box.querySelector('[data-sel-p]');
            box.querySelectorAll('[data-rsel]').forEach(c => c.onchange = () => { const s = selOf(), st = box.querySelector('[data-pstamp]:checked') ? '&stamp=1' : ''; if (ex) ex.href = exportUrl(it, 'xlsx', s); if (ep) ep.href = exportUrl(it, 'pdf', s) + st; });
            const ap = box.querySelector('[data-pa]'); if (ap) ap.onclick = () => { try { opts.onApply(rows[0], it); } catch (e) {} };
            const dm = box.querySelector('[data-pdmg]'); if (dm) dm.onclick = () => {
                const f = rows[0] || {};
                $('[data-k="d_pol"]').value = f.print_no || $('[data-k="d_pol"]').value;
                $('[data-k="d_ch"]').value = f.chassis || f.vin || $('[data-k="d_ch"]').value;
                $('[data-k="d_en"]').value = f.engine || $('[data-k="d_en"]').value;
                S.tab = 'damage'; paint();
            };
        };
        const bindDamage = (it, saved) => {
            const box = $('[data-res="damage"]');
            box.innerHTML = damageResultHtml(it, opts) + (saved ? (saved.ok ? `<p class="text-[11px] font-bold text-emerald-700 mt-2"><i class="fas fa-check ml-1"></i>روی ردیف ذخیره شد: ${esc(fa(saved.name || ''))}${saved.stamped ? ' (با مهر)' : ''}</p>` : `<p class="text-[11px] font-bold text-rose-600 mt-2">${esc(saved.error || 'ذخیره نشد')}</p>`) : '');
            box.querySelectorAll('[data-psave]').forEach(b => b.onclick = async () => {
                b.disabled = true;
                const r = await post({action: 'pb_save_doc', id: it.id, which: b.dataset.psave, ref_type: opts.save.type, ref_id: opts.save.id});
                b.disabled = false;
                if (!r.ok) return toast(r.error || 'خطا', 'error');
                toast(`ذخیره شد: ${r.name}${r.stamped ? ' (با مهر)' : ''}`, 'success');
                if (opts.save.onSaved) try { opts.save.onSaved(r); } catch (e) {}
            });
        };
        const runBody = async () => {
            if (S.busy) return;
            const body = {action: 'pb_search', type: S.type, source: opts.source || 'panel', want_print: $('[data-k="print_too"]').checked ? 1 : 0,
                          unique: val('unique'), print: val('print'), chassis: val('chassis'), engine: val('engine'), p1: val('p1'), p2: val('p2'), p4: val('p4'), letter: val('letter')};
            if (opts.ref) { body.ref_type = opts.ref.type; body.ref_id = opts.ref.id; }
            S.busy = true; const b = $('[data-go="body"]'); b.disabled = true; const t0 = Date.now();
            const tick = setInterval(() => st('body', `<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ استعلام از پاسارگاد… ${fa(Math.round((Date.now() - t0) / 1000))} ثانیه`), 500);
            const r = await post(body);
            clearInterval(tick); S.busy = false; b.disabled = false; st('body', '');
            if (withLogin(r, runBody)) return;
            if (!r.ok) { $('[data-res="body"]').innerHTML = `<div class="pbu-bad"><b>${esc(r.error || 'خطا')}</b>${r.debug ? '<p class="text-[10.5px] mt-1">صفحه‌های سامانه برای بررسی ذخیره شد (مدیر کل: تاریخچه ← جزئیات).</p>' : ''}</div>`; return; }
            S.last = r.item; bindBody(r.item);
            if (opts.onResult) try { opts.onResult(r.item); } catch (e) {}
        };
        const runDamage = async () => {
            if (S.busy) return;
            const out = (host.querySelector('[data-k="d_out"]:checked') || {}).value || 'shot';
            const body = {action: 'pb_damage', source: opts.source || 'panel', policy_no: val('d_pol'), chassis: val('d_ch'), engine: val('d_en'), ins: val('d_ins') || 'both', out};
            if (opts.ref) { body.ref_type = opts.ref.type; body.ref_id = opts.ref.id; }
            const sv = $('[data-k="d_save"]');
            if (opts.save && sv && sv.checked) { body.save_type = opts.save.type; body.save_id = opts.save.id; }
            S.busy = true; const b = $('[data-go="damage"]'); b.disabled = true; const t0 = Date.now();
            const tick = setInterval(() => st('damage', `<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ استعلامِ خسارت… ${fa(Math.round((Date.now() - t0) / 1000))} ثانیه`), 500);
            const r = await post(body);
            clearInterval(tick); S.busy = false; b.disabled = false; st('damage', '');
            if (withLogin(r, runDamage)) return;
            if (!r.ok) { $('[data-res="damage"]').innerHTML = `<div class="pbu-bad"><b>${esc(r.error || 'خطا')}</b></div>`; return; }
            S.dmg = r.item; bindDamage(r.item, r.saved);
            if (r.saved && r.saved.ok && opts.save && opts.save.onSaved) try { opts.save.onSaved(r.saved); } catch (e) {}
        };
        $('[data-go="body"]').onclick = runBody;
        $('[data-go="damage"]').onclick = runDamage;
        // آخرین استعلامِ همین ردیف (بدونِ استعلامِ دوباره)
        if (opts.ref) post({action: 'pb_last', ref_type: opts.ref.type, ref_id: opts.ref.id}).then(r => { if (r.ok && r.item && !S.last && host.isConnected) { S.last = r.item; bindBody(r.item); st('body', `آخرین استعلامِ همین ردیف: ${esc(fa(r.item.created_at_fa || r.item.created_at || ''))}`); } });
        return {run: runBody};
    }

    function open(opts) {
        css();
        const ov = document.createElement('div');
        ov.className = 'pbu-ov';
        ov.innerHTML = `<div class="pbu-box" dir="rtl"><div class="pbu-bh"><b class="text-sm"><i class="fas fa-car-burst ml-1"></i>${esc((opts && opts.title) || 'استعلامِ بدنه و خسارت — پاسارگاد')}</b>
            <button class="pbu-btn" data-x><i class="fas fa-times"></i> بستن</button></div><div class="p-3" data-h></div></div>`;
        document.body.appendChild(ov);
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = e => { if (e.key === 'Escape') close(); };
        document.addEventListener('keydown', onKey);
        ov.querySelector('[data-x]').onclick = close;
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        const api = form(ov.querySelector('[data-h]'), opts);
        return {close, run: api.run};
    }

    // جزئیاتِ یک استعلامِ ثبت‌شده (تاریخچه)
    function details(item) {
        css();
        const h = document.createElement('div');
        h.className = 'pbu';
        h.innerHTML = item.kind === 'pdamage' ? damageResultHtml(item, null) : bodyResultHtml(item, null);
        requestAnimationFrame(() => dual(h));
        h.querySelectorAll('[data-pdmg]').forEach(b => b.remove());
        return h;
    }
    window.PBodyUI = {form, open, details};
})();
