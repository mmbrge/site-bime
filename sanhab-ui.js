// فایل: sanhab-ui.js
// «استعلامِ سنهاب» (سوابقِ بیمه‌نامه‌ها، الحاقیه‌ها و خسارت‌ها از بیمه مرکزی) — یک ابزارِ مشترک برای همه‌ی بخش‌ها:
//   SanhabUI.form(host, opts)    فرمِ جستجو + کپچا + نتیجه داخلِ یک بخش (استعلامات ← سنهاب)
//   SanhabUI.open(opts)          همان فرم در پنجره (از ثبتِ درخواست، پنلِ صدور، استعلامِ حق بیمه)
//   SanhabUI.button(host, opts)  دکمه‌ی «استعلامِ سنهاب» + آخرین نتیجه‌ی همین ردیف/پلاک کنارش
//   SanhabUI.batch(host, id, o)  اجرای استعلامِ گروهی: هر ردیف با کپچای خودش، پشتِ سرِ هم
//   SanhabUI.details(item)       جدولِ کاملِ سوابق (بیمه‌نامه‌ها + الحاقیه‌ها + خسارت‌ها)
// opts: {prefill: {type, plcy, plate|{p1,p2,letter,p4}, moto:{city,num}, vin, chassis, engine, nid, policy_no}, source: 'panel'|'form'|'premium',
//        ref: {type, id}, title, onResult(item), onApply(brief, item), applyLabel}
// کپچای ورود (وقتی نشستِ سنهاب بسته است) و کپچای هر استعلام را خودِ کاربر می‌زند؛ نام کاربری و رمز فقط در تنظیمات (مدیر کل).
// منطق: api/inquiry_actions.php (sh_*) و api/_sanhab.php
(function () {
    'use strict';
    if (window.SanhabUI) return;
    const API = 'api/inquiry_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    // مقدارِ جستجو: پلاکِ فارسی راست‌به‌چپ، شاسی/شماره چپ‌به‌راست
    const qv = q => `<span ${/[\u0600-\u06FF]/.test(String(q || '')) ? '' : 'dir="ltr"'}>${esc(fa(q || ''))}</span>`;
    const TYPES = {'4': 'پلاک (خودرو)', '8': 'پلاک (موتورسیکلت)', '45': 'پلاک غیرملی', '7': 'شماره بیمه‌نامه', '3': 'کد ملی بیمه‌گذار', '5': 'شماره VIN', '1': 'شماره موتور', '2': 'شماره شاسی', '6': 'موتور و شاسی'};
    const TYPE_ORDER = ['4', '8', '45', '7', '3', '5', '2', '1', '6'];   // کلیدهای عددی در شیء مرتب می‌شوند؛ ترتیبِ نمایش جدا
    const LETTERS = ['الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'د', 'ز', 'س', 'ش', 'ص', 'ط', 'ع', 'ف', 'ق', 'ک', 'گ', 'ل', 'م', 'ن', 'و', 'ه', 'ی', 'ژ', 'D', 'S'];
    async function post(body) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(body)})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }
    const newTok = () => Math.random().toString(36).slice(2) + Date.now().toString(36);
    const TOKS = new Set();
    window.addEventListener('pagehide', () => { TOKS.forEach(t => { try { navigator.sendBeacon(API, new Blob([JSON.stringify({action: 'sh_release', tok: t})], {type: 'application/json'})); } catch (e) {} }); });

    function css() {
        if (document.getElementById('shu-css')) return;
        const s = document.createElement('style'); s.id = 'shu-css';
        s.textContent = `
        .shu{font-size:12px}
        .shu-card{background:#fff;border:1.5px solid #e9edf5;border-radius:20px;padding:16px}
        .shu-f{display:block;font-size:11px;font-weight:800;color:#475569}
        .shu-in{margin-top:5px;width:100%;border:1.5px solid #dbe3ee;border-radius:12px;padding:9px 11px;font-size:13.5px;font-weight:800;background:#f8fafc;outline:none}
        .shu-in:focus{border-color:#0ea5e9;background:#fff;box-shadow:0 0 0 4px #e0f2fe}.shu-in.err{border-color:#f43f5e;background:#fff1f2}
        .shu-plate{display:flex;direction:ltr;align-items:stretch;border:2.5px solid #0f172a;border-radius:10px;overflow:hidden;background:#fff;max-width:330px;height:48px}
        .shu-plate .fl{width:22px;background:linear-gradient(#16a34a 33%,#fff 33% 66%,#dc2626 66%)}
        .shu-plate input,.shu-plate select{border:0;outline:none;text-align:center;font-size:19px;font-weight:900;background:transparent;min-width:0}
        .shu-plate .ir{border-left:2px solid #0f172a;display:flex;flex-direction:column;align-items:center;justify-content:center;width:56px}
        .shu-plate .ir small{font-size:9px;font-weight:900}.shu-plate .ir input{width:44px;font-size:16px}
        .shu-go{border-radius:13px;padding:10px 18px;font-weight:900;color:#fff;background:linear-gradient(120deg,#0369a1,#0891b2 60%,#0d9488);box-shadow:0 12px 26px -14px #0369a1;white-space:nowrap}
        .shu-go:disabled{opacity:.55}
        .shu-b{font-size:10.5px;font-weight:800;border-radius:9px;padding:5px 10px;background:#e0f2fe;color:#075985;white-space:nowrap;display:inline-flex;align-items:center;gap:5px}
        .shu-b.g{background:#f1f5f9;color:#475569}.shu-b.ok{background:#d1fae5;color:#065f46}.shu-b:disabled{opacity:.5}
        .shu-cap{border:1.5px dashed #7dd3fc;background:#f0f9ff;border-radius:16px;padding:10px 12px}
        .shu-capimg{display:inline-flex;align-items:center;justify-content:center;min-width:130px;min-height:46px;background:#fff;border-radius:10px;padding:3px;border:1px solid #e0f2fe}.shu-capimg img{max-height:52px;display:block}
        .shu-res{border-radius:18px;border:1.5px solid;padding:14px;margin-top:12px}
        .shu-res.ok{background:#ecfdf5;border-color:#a7f3d0}.shu-res.bad{background:#fff1f2;border-color:#fecdd3}.shu-res.warn{background:#fffbeb;border-color:#fde68a}
        .shu-big{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
        .shu-st{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 14px;font-size:13px;font-weight:900;color:#fff}
        .shu-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:10px}@media(max-width:760px){.shu-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .shu-kpi{background:rgba(255,255,255,.75);border-radius:12px;padding:8px 10px}.shu-kpi span{display:block;font-size:10px;color:#64748b;font-weight:700}.shu-kpi b{font-size:13px;color:#0f172a;word-break:break-word}
        .shu-kpi.hl b{font-size:18px;color:#0369a1}
        .shu-tbl{width:100%;font-size:11px;border-collapse:collapse}.shu-tbl th{background:#f8fafc;color:#64748b;font-weight:800;padding:7px;text-align:right;white-space:nowrap;position:sticky;top:0}
        .shu-tbl td{padding:7px;border-top:1px solid #f1f5f9;vertical-align:top;white-space:nowrap}
        .shu-sub{width:100%;font-size:10.5px;border-collapse:collapse;margin:4px 0}.shu-sub th{padding:5px;font-weight:800;text-align:right}.shu-sub td{padding:5px;border-top:1px solid rgba(0,0,0,.05)}
        .shu-modal{position:fixed;inset:0;z-index:1000005;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;padding:12px}
        .shu-modal>div{background:#f8fafc;border-radius:22px;width:min(980px,100%);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(0,0,0,.5)}
        .shu-mh{background:linear-gradient(120deg,#0c4a6e,#0369a1 55%,#0d9488);color:#fff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px}
        .shu-chip{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:3px 10px;font-size:10.5px;font-weight:900;white-space:nowrap}`;
        document.head.appendChild(s);
    }
    const pct = v => v == null ? '—' : fa(v) + '٪';
    // وضعیتِ نتیجه => [برچسب، رنگ، آیکن، کلاس]
    function tone(it) {
        const b = it.sanhab || {};
        if (it.status === 'FAIL') return ['ناموفق', '#e11d48', 'fa-circle-xmark', 'bad'];
        if (!b.found) return ['بیمه‌نامه‌ای پیدا نشد', '#d97706', 'fa-circle-question', 'warn'];
        return b.has_loss ? ['خسارت دارد', '#e11d48', 'fa-car-burst', 'bad'] : ['عدم خسارت', '#059669', 'fa-shield-heart', 'ok'];
    }
    // متنِ کوتاه برای کنارِ دکمه‌ها
    function shortText(it) {
        const b = it.sanhab || {};
        if (!b.found) return it.notice || 'پیدا نشد';
        return `${b.has_loss ? 'خسارت دارد' + (b.loss_types && b.loss_types !== 'ندارد' ? ' (' + b.loss_types + ')' : '') : 'عدم خسارت'} · تخفیف ثالث ${pct(b.tp_disc)} · حوادث راننده ${pct(b.dr_disc)}${b.end_date ? ' · انقضا ' + fa(b.end_date) : ''}`;
    }
    function resultHtml(it, o) {
        const b = it.sanhab || {}, t = tone(it);
        o = o || {};
        const k = (l, v, hl) => `<div class="shu-kpi ${hl ? 'hl' : ''}"><span>${l}</span><b ${/^[\d\/\-A-Z ]+$/.test(String(v || '')) ? 'dir="ltr"' : ''}>${esc(fa(v || '—'))}</b></div>`;
        return `<div class="shu-res ${t[3]}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="shu-big"><span class="shu-st" style="background:${t[1]}"><i class="fas ${t[2]}"></i>${t[0]}</span>
                    ${b.found && b.has_loss && b.loss_types && b.loss_types !== 'ندارد' ? `<span class="text-[11.5px] font-black text-rose-700">${esc(b.loss_types)}</span>` : ''}</div>
                <span class="text-[10.5px] text-slate-500 font-bold">${esc(it.sh_type_fa || '')} ${it.query ? '· ' + qv(it.query) : ''} · ${esc(fa(it.created_j || ''))}${o.cachedNote ? ' · <b class="text-sky-700">' + esc(o.cachedNote) + '</b>' : ''}</span></div>
            ${b.found ? `<div class="shu-kpis">
                ${k('تخفیفِ ثالث (مالی/جانی)', b.tp_disc == null ? '—' : b.tp_disc + '٪', true)}${k('تخفیفِ حوادثِ راننده', b.dr_disc == null ? '—' : b.dr_disc + '٪', true)}
                ${k('بیمه‌گر (آخرین بیمه‌نامه)', b.insurer)}${k('شماره بیمه‌نامه', b.policy_no)}
                ${k('شروع', b.start_date)}${k('انقضا', b.end_date)}${k('بیمه‌گذار', b.insured)}${k('خودرو', b.vehicle)}
                ${k('نوعِ بیمه‌نامه', b.policy_type)}${k('سال‌های عدمِ خسارتِ مالی / جانی', [b.no_loss_fin, b.no_loss_life].map(x => x == null ? '—' : x).join(' / '))}${k('پلاک', b.plate)}${k('تعدادِ سوابق', b.count)}</div>
                ${b.older_losses && b.older_losses !== 'ندارد' ? `<p class="mt-2 text-[11px] font-bold text-amber-800"><i class="fas fa-clock-rotate-left ml-1"></i>خسارت در بیمه‌نامه‌های قبلی: ${esc(fa(b.older_losses))}</p>` : ''}`
              : `<p class="mt-2 text-xs font-bold text-slate-700">${esc(it.notice || '')}</p>`}
            <div class="flex flex-wrap gap-1.5 mt-3">
                ${b.found ? '<button type="button" class="shu-b" data-shd><i class="fas fa-table-list"></i>جزئیاتِ کامل (بیمه‌نامه‌ها، الحاقیه‌ها، خسارت‌ها)</button>' : ''}
                ${b.found && b.summary_text ? '<button type="button" class="shu-b g" data-shc><i class="far fa-copy"></i>کپیِ نتیجه</button>' : ''}
                ${b.found && b.unique_code ? `<a class="shu-b g" target="_blank" href="${API}?action=sh_pdf&typ=${it.sh_plcy === '1' ? '2' : '1'}&pu=${encodeURIComponent(b.unique_code)}"><i class="fas fa-file-pdf"></i>PDFِ بیمه‌نامه</a>` : ''}
                ${o.onApply && b.found ? `<button type="button" class="shu-b ok" data-sha><i class="fas fa-check-double"></i>${esc(o.applyLabel || 'نشاندن در فرم')}</button>` : ''}
                ${o.again ? '<button type="button" class="shu-b g" data-shr><i class="fas fa-rotate"></i>استعلامِ دوباره</button>' : ''}</div></div>`;
    }
    function bindResult(el, it, o) {
        o = o || {};
        const q = s => el.querySelector(s);
        if (q('[data-shd]')) q('[data-shd]').onclick = () => details(it);
        if (q('[data-shc]')) q('[data-shc]').onclick = async () => { try { await navigator.clipboard.writeText((it.sanhab || {}).summary_text || ''); toast('کپی شد.', 'success'); } catch (e) {} };
        if (q('[data-sha]')) q('[data-sha]').onclick = () => { o.onApply(it.sanhab, it); toast('اطلاعاتِ سنهاب در فرم نشست.', 'success'); };
        if (q('[data-shr]') && o.again) q('[data-shr]').onclick = o.again;
    }
    async function details(it) {
        css();
        if (!it.sanhab_rows) { const d = await post({action: 'get', id: it.id}); if (d.ok) it = d.item; }
        const rows = it.sanhab_rows || [];
        const m = document.createElement('div'); m.className = 'shu-modal';
        const sub = (cls, head, cols, list, map) => `<tr><td colspan="16" style="background:${cls};padding:6px 12px"><table class="shu-sub"><thead><tr>${head.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${list.map(x => `<tr>${map(x).map(v => `<td>${esc(fa(v || '—'))}</td>`).join('')}</tr>`).join('')}</tbody></table></td></tr>`;
        m.innerHTML = `<div style="width:min(1250px,100%)"><div class="shu-mh"><b class="text-sm"><i class="fas fa-table-list ml-1"></i>سوابقِ کاملِ سنهاب · ${qv(it.query)} (${fa(rows.length)} بیمه‌نامه)</b><button class="shu-b g" data-x>بستن</button></div>
            <div class="p-3 overflow-auto"><div class="bg-white rounded-2xl border overflow-auto"><table class="shu-tbl"><thead><tr><th>#</th><th>وضعیتِ خسارت</th><th>نوع</th><th>بیمه‌گر</th><th>بیمه‌گذار</th><th>شماره بیمه‌نامه</th><th>بیمه‌نامه‌ی قبل</th><th>خودرو</th><th>پلاک</th><th>شاسی / VIN</th><th>موتور</th><th>صدور</th><th>شروع</th><th>انقضا</th><th>تخفیف ثالث / جانی / راننده</th><th>عدمِ خسارت (مالی/جانی/سرنشین)</th></tr></thead><tbody>
            ${rows.map((r, i) => {
                const rs = r.rowSummary || {};
                let h = `<tr><td>${fa(r.radif || i + 1)}</td><td><span class="shu-chip" style="background:${rs.hasLoss ? '#fee2e2' : '#d1fae5'};color:${rs.hasLoss ? '#be123c' : '#065f46'}">${esc(rs.lossStatus || '')}</span></td>
                    <td>${esc(r.policyType)}</td><td><b>${esc(r.company)}</b></td><td>${esc(r.insuredName)}</td><td dir="ltr">${esc(fa(r.policyNo))}</td><td dir="ltr">${esc(fa(r.prevPolicyNo))}</td><td>${esc(r.vehicleName)}</td><td>${esc(fa(r.plate))}</td>
                    <td dir="ltr">${esc(r.chassisNo || r.vin)}</td><td dir="ltr">${esc(r.motorNo)}</td><td dir="ltr">${esc(fa(r.issueDate))}</td><td dir="ltr"><b>${esc(fa(r.startDate))}</b></td><td dir="ltr"><b>${esc(fa(r.endDate))}</b></td>
                    <td><b>${pct(r.discFinPct || 0)} / ${pct(r.discLifePct || 0)} / ${pct(r.discPassPct || 0)}</b></td><td>${esc(fa([r.noLossFinYr, r.noLossLifeYr, r.noLossPassYr].join(' / ')))}</td></tr>`;
                if ((r.endorsements || []).length) h += sub('#f0fdf4', ['ردیف', 'نوعِ الحاقیه', 'تاریخ', 'بیمه‌گذار', 'پلاک', 'شاسی', 'تخفیف مالی', 'تخفیف جانی', 'تخفیف سرنشین'], 0, r.endorsements,
                    e => [e.radif, e.endorsementType, e.issueDate, e.insuredName, e.plate, e.chassisNo || e.vin, (e.discFinPct || 0) + '٪', (e.discLifePct || 0) + '٪', (e.discPassPct || 0) + '٪']);
                if ((r.losses || []).length) h += sub('#fef2f2', ['ردیف', 'نوعِ خسارت', 'مبلغِ پرداختی (ریال)', 'تاریخِ اعلام', 'تاریخِ پرداخت'], 0, r.losses, l => [l.radif, l.lossType, l.paidAmount, l.declareDate, l.paidDate]);
                return h;
            }).join('') || '<tr><td colspan="16" class="text-center text-slate-400 py-8">سابقه‌ای نیست.</td></tr>'}</tbody></table></div>
            ${(it.sanhab || {}).inquirer ? `<p class="text-[10.5px] text-slate-400 mt-2">${esc(it.sanhab.inquirer)} · ${esc(fa(it.sanhab.inquiry_date || ''))}</p>` : ''}</div></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
        document.body.appendChild(m);
    }

    // ---------------- فرمِ جستجو ----------------
    function pf(p) {
        p = Object.assign({}, p || {});
        if (typeof p.plate === 'string' && p.plate) {
            const s = en(p.plate);
            const mm = s.match(/^\s*(\d{3})\s*-\s*(\d{5})\s*$/);
            if (mm) p.moto = {city: mm[1], num: mm[2]};
            else { const m = s.match(/(\d{2})\s*ایران\s*-?\s*(\d{3})\s*(\S+?)\s*(\d{2})/); if (m) p.plate = {p1: m[1], p2: m[2], letter: m[3], p4: m[4]}; else p.plate = null; }
        }
        if (p.plate && p.plate.letter === 'موتور') { p.moto = {city: p.plate.p1, num: p.plate.p2}; p.plate = null; }
        if (!p.type) p.type = p.plate && p.plate.p2 ? '4' : p.moto && p.moto.num ? '8' : p.vin || p.chassis ? (p.chassis && !p.vin ? '2' : '5') : p.policy_no ? '7' : p.nid ? '3' : p.engine ? '1' : '4';
        return p;
    }
    function fieldsHtml(type, p) {
        if (type === '4') {
            const pl = p.plate || {};
            return `<label class="shu-f">پلاکِ خودرو</label><div class="shu-plate mt-1"><span class="fl"></span>
                <input data-k="plk4" maxlength="2" inputmode="numeric" style="width:52px" placeholder="۱۱" value="${esc(fa(pl.p4 || ''))}">
                <select data-k="plk3" style="width:62px">${LETTERS.map(l => `<option ${l === (pl.letter === 'آ' ? 'الف' : pl.letter) ? 'selected' : ''}>${l}</option>`).join('')}</select>
                <input data-k="plk2" maxlength="3" inputmode="numeric" style="width:70px" placeholder="۱۲۳" value="${esc(fa(pl.p2 || ''))}">
                <span class="ir"><small>ایران</small><input data-k="plk1" maxlength="2" inputmode="numeric" placeholder="۲۱" value="${esc(fa(pl.p1 || ''))}"></span></div>`;
        }
        if (type === '8' || type === '45') {
            const mo = p.moto || {};
            return `<div class="grid grid-cols-2 gap-2" dir="ltr"><label class="shu-f" dir="rtl">بخشِ ۳ رقمیِ بالا<input class="shu-in" data-k="plk2m" maxlength="3" inputmode="numeric" value="${esc(fa(mo.city || ''))}"></label>
                <label class="shu-f" dir="rtl">بخشِ ۵ رقمیِ پایین<input class="shu-in" data-k="plk1m" maxlength="5" inputmode="numeric" value="${esc(fa(mo.num || ''))}"></label></div>`;
        }
        if (type === '6') return `<div class="grid grid-cols-1 md:grid-cols-2 gap-2"><label class="shu-f">شماره موتور<input class="shu-in" data-k="txtInput" dir="ltr" value="${esc(p.engine || '')}"></label>
            <label class="shu-f">شماره شاسی<input class="shu-in" data-k="txtInput2" dir="ltr" value="${esc(p.chassis || p.vin || '')}"></label></div>`;
        const v = {'7': p.policy_no, '3': p.nid, '5': p.vin || p.chassis, '1': p.engine, '2': p.chassis || p.vin}[type] || '';
        return `<label class="shu-f">${esc(TYPES[type])}<input class="shu-in" data-k="txtInput" dir="ltr" value="${esc(fa(v))}"></label>`;
    }
    function form(host, opts) {
        css();
        opts = opts || {};
        const p = pf(opts.prefill);
        const S = {type: p.type, plcy: p.plcy || '0', tok: newTok(), hasCap: false, capType: '', busy: false};
        TOKS.add(S.tok);
        host.innerHTML = `<div class="shu space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                <label class="shu-f">جستجو با<select class="shu-in" data-type style="font-size:12.5px">${TYPE_ORDER.map(k => `<option value="${k}" ${k === S.type ? 'selected' : ''}>${TYPES[k]}</option>`).join('')}</select></label>
                <label class="shu-f">نوعِ استعلام<select class="shu-in" data-plcy style="font-size:12.5px"><option value="0" ${S.plcy === '0' ? 'selected' : ''}>بیمه‌نامه (ثالث / بدنه / حوادث)</option><option value="1" ${S.plcy === '1' ? 'selected' : ''}>سوابقِ خسارت</option></select></label>
            </div>
            <div data-fields>${fieldsHtml(S.type, p)}</div>
            <div class="shu-cap" data-capbox>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="shu-capimg" data-capimg><button type="button" class="shu-b" data-getcap><i class="fas fa-shield-halved"></i>دریافتِ کدِ امنیتی</button></span>
                    <button type="button" class="shu-b g" data-recap title="تصویرِ دیگر"><i class="fas fa-rotate"></i></button>
                    <input class="shu-in !mt-0" data-cap style="width:150px;text-align:center;font-size:15px" dir="ltr" autocomplete="off" spellcheck="false" placeholder="کدِ تصویر">
                    <button type="button" class="shu-go" data-go><i class="fas fa-magnifying-glass ml-1"></i>استعلام از سنهاب</button></div>
                <p class="text-[10.5px] text-slate-500 mt-1.5" data-hint>کدِ تصویرِ امنیتیِ سنهاب را بزنید؛ هر استعلام کدِ خودش را دارد (بعد از هر استعلام، کدِ بعدی خودکار می‌آید).</p>
                <p class="text-[11px] font-bold text-rose-600 mt-1" data-err></p></div>
            <div data-login></div>
            <div data-out></div></div>`;
        const q = s => host.querySelector(s);
        const err = t => { q('[data-err]').textContent = t || ''; };
        const setImg = src => { q('[data-capimg]').innerHTML = src ? `<img src="${src}" alt="کد امنیتی">` : '<i class="fas fa-spinner fa-spin text-slate-400"></i>'; S.hasCap = !!src; };
        const params = () => { const o = {type: S.type, plcy: S.plcy}; host.querySelectorAll('[data-fields] [data-k]').forEach(i => { o[i.dataset.k] = i.tagName === 'SELECT' ? i.value : en(i.value).trim(); }); return o; };
        // ورود به سنهاب (کپچای ورود؛ نام کاربری و رمز از تنظیمات)
        const loginBox = (img, msg) => new Promise(res => {
            const L = q('[data-login]');
            L.innerHTML = `<div class="shu-cap" style="border-color:#fbbf24;background:#fffbeb"><p class="text-[11.5px] font-black text-amber-800 mb-2"><i class="fas fa-key ml-1"></i>نشستِ سامانه‌ی سنهاب بسته است؛ کدِ تصویرِ «ورود» را بزنید</p>
                <div class="flex flex-wrap items-center gap-2"><span class="shu-capimg" data-limg>${img ? `<img src="${img}">` : '<i class="fas fa-spinner fa-spin text-slate-400"></i>'}</span>
                <button type="button" class="shu-b g" data-lre><i class="fas fa-rotate"></i></button><input class="shu-in !mt-0" data-lin style="width:150px;text-align:center" dir="ltr" autocomplete="off" placeholder="کدِ تصویر">
                <button type="button" class="shu-go" data-lok>ورود به سنهاب</button><button type="button" class="shu-b g" data-lno>انصراف</button></div><p class="text-[11px] font-bold text-rose-600 mt-1" data-lerr>${esc(msg || '')}</p></div>`;
            const li = L.querySelector('[data-lin]'), le = L.querySelector('[data-lerr]');
            const sImg = s => { L.querySelector('[data-limg]').innerHTML = s ? `<img src="${s}">` : '<i class="fas fa-spinner fa-spin text-slate-400"></i>'; };
            const re = async force => { sImg(null); const r = await post({action: 'sh_login_captcha', refresh: force ? 1 : 0}); if (r.logged_in) { L.innerHTML = ''; res(true); return; } if (!r.ok) le.textContent = r.error || ''; sImg(r.image); };
            if (!img) re(false); else setTimeout(() => li.focus(), 50);
            const go = async () => {
                const t = en(li.value).trim(); if (!t) { li.focus(); return; }
                const b = L.querySelector('[data-lok]'); b.disabled = true;
                const r = await post({action: 'sh_login', captcha: t});
                b.disabled = false;
                if (r.ok) { L.innerHTML = ''; res(true); return; }
                le.textContent = r.error || 'کد اشتباه بود.'; li.value = ''; if (r.image) sImg(r.image); else re(true);
            };
            L.querySelector('[data-lok]').onclick = go;
            L.querySelector('[data-lre]').onclick = () => re(true);
            L.querySelector('[data-lno]').onclick = () => { L.innerHTML = ''; res(false); };
            li.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
        });
        const getCap = async reselect => {
            err(''); setImg(null);
            const r = await post({action: 'sh_captcha', type: S.type, plcy: S.plcy, tok: S.tok, reselect: reselect ? 1 : 0});
            if (r.need_login) { setImg(''); q('[data-capimg]').innerHTML = '<i class="fas fa-lock text-amber-500"></i>'; if (await loginBox(r.login_image, '')) return getCap(true); return false; }
            if (!r.ok) {
                q('[data-capimg]').innerHTML = `<button type="button" class="shu-b" data-getcap><i class="fas fa-shield-halved"></i>دریافتِ کدِ امنیتی</button>`;
                q('[data-getcap]').onclick = () => getCap(false); err(r.error || 'کدِ امنیتی نیامد.'); S.hasCap = false; return false;
            }
            setImg(r.image); S.capType = S.type + ':' + S.plcy; q('[data-cap]').value = ''; q('[data-cap]').focus();
            return true;
        };
        const out = q('[data-out]');
        const showItem = (it, note) => {
            out.innerHTML = resultHtml(it, {onApply: opts.onApply, applyLabel: opts.applyLabel, cachedNote: note});
            bindResult(out, it, {onApply: opts.onApply});
        };
        const search = async () => {
            if (S.busy) return;
            const cap = en(q('[data-cap]').value).trim();
            if (!S.hasCap || S.capType !== S.type + ':' + S.plcy) { await getCap(S.capType !== '' && S.capType !== S.type + ':' + S.plcy); return; }
            if (!cap) { q('[data-cap]').focus(); err('کدِ تصویر را بنویسید.'); return; }
            const b = q('[data-go]'); S.busy = true; b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ استعلام…'; err('');
            const r = await post(Object.assign({action: 'sh_search', tok: S.tok, captcha: cap, source: opts.source || 'panel', ref_type: opts.ref ? opts.ref.type : null, ref_id: opts.ref ? opts.ref.id : null}, params()));
            S.busy = false; b.disabled = false; b.innerHTML = '<i class="fas fa-magnifying-glass ml-1"></i>استعلام از سنهاب';
            if (r.need_login) { if (await loginBox(r.login_image, '')) getCap(true); return; }
            if (r.image) { setImg(r.image); q('[data-cap]').value = ''; } else if (!r.ok) S.hasCap = false;
            if (r.need_captcha) { S.hasCap = false; await getCap(true); return; }
            if (!r.ok) {
                err(r.error || 'خطا');
                if (r.field && r.field !== 'captcha') { const i = host.querySelector('[data-fields] input'); if (i) { i.classList.add('err'); i.focus(); } } else q('[data-cap]').focus();
                return;
            }
            showItem(r.item);
            if (opts.onResult) opts.onResult(r.item);
        };
        q('[data-getcap]').onclick = () => getCap(false);
        q('[data-recap]').onclick = () => getCap(false);
        q('[data-go]').onclick = search;
        q('[data-cap]').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
        q('[data-type]').onchange = e => { S.type = e.target.value; q('[data-fields]').innerHTML = fieldsHtml(S.type, p); bindFields(); if (S.hasCap) getCap(true); };
        q('[data-plcy]').onchange = e => { S.plcy = e.target.value; if (S.hasCap) getCap(true); };
        const bindFields = () => host.querySelectorAll('[data-fields] input').forEach(i => i.addEventListener('input', () => {
            i.classList.remove('err');
            if (['plk1', 'plk2', 'plk4', 'plk1m', 'plk2m'].includes(i.dataset.k)) i.value = fa(en(i.value).replace(/\D/g, ''));
            if (i.maxLength > 0 && en(i.value).length >= i.maxLength && i.dataset.k !== 'plk1') { const all = [...host.querySelectorAll('[data-fields] input')]; const n = all[all.indexOf(i) + 1]; if (n) n.focus(); }
        }));
        bindFields();
        if (opts.lastItem) showItem(opts.lastItem, 'نتیجه‌ی قبلی');
        if (opts.autoCaptcha) getCap(false);
        // آزاد کردنِ نوبت وقتی این فرم بسته شد
        const watch = setInterval(() => { if (!host.isConnected) { clearInterval(watch); TOKS.delete(S.tok); post({action: 'sh_release', tok: S.tok}); } }, 2000);
        return {release: () => { clearInterval(watch); TOKS.delete(S.tok); post({action: 'sh_release', tok: S.tok}); }};
    }
    function open(opts) {
        css();
        opts = opts || {};
        const m = document.createElement('div'); m.className = 'shu-modal';
        m.innerHTML = `<div><div class="shu-mh"><div><p class="text-[10.5px] opacity-80"><i class="fas fa-shield-halved ml-1"></i>استعلامِ سنهاب (بیمه مرکزی)</p><b class="text-sm">${esc(fa(opts.title || 'سوابقِ بیمه‌نامه، خسارت و تخفیف‌ها'))}</b></div><button class="shu-b g" data-x>بستن</button></div><div class="p-4 overflow-y-auto" data-h></div></div>`;
        document.body.appendChild(m);
        let f = null;
        const close = () => { if (f) f.release(); m.remove(); };
        m.addEventListener('click', e => { if (e.target === m || e.target.closest('[data-x]')) close(); });
        f = form(m.querySelector('[data-h]'), Object.assign({autoCaptcha: !opts.lastItem}, opts, {
            onResult: it => { if (opts.onResult) opts.onResult(it); },
            onApply: opts.onApply ? (b, it) => { opts.onApply(b, it); close(); } : null,
        }));
        return {close};
    }
    // دکمه + آخرین نتیجه (برای ردیف‌ها). getPrefill: () => prefill
    function button(host, opts) {
        css();
        opts = opts || {};
        host.innerHTML = `<div class="flex flex-wrap items-center gap-1.5"><button type="button" class="shu-b" data-shb><i class="fas fa-shield-halved"></i>${esc(opts.label || 'استعلامِ سنهاب')}</button><span data-shs class="text-[10.5px] font-bold"></span></div>`;
        const st = host.querySelector('[data-shs]');
        let last = null;
        const show = it => {
            last = it;
            if (!it) { st.textContent = ''; return; }
            const t = tone(it);
            st.innerHTML = `<span class="shu-chip" style="background:${t[1]}1a;color:${t[1]}"><i class="fas ${t[2]}"></i>${esc(fa(shortText(it)))}</span> <button type="button" class="shu-b g" style="padding:2px 8px" data-shdd>جزئیات</button>`;
            st.querySelector('[data-shdd]').onclick = () => details(it);
            if (opts.onResult) opts.onResult(it);
        };
        const pre = () => pf(typeof opts.getPrefill === 'function' ? opts.getPrefill() : opts.prefill);
        const cands = p => {
            const c = [];
            if (p.plate && p.plate.p2) c.push({type: '4', plk1: p.plate.p1, plk2: p.plate.p2, plk3: p.plate.letter, plk4: p.plate.p4});
            if (p.moto && p.moto.num) c.push({type: '8', plk2m: p.moto.city, plk1m: p.moto.num});
            if (p.vin) c.push({type: '5', txtInput: p.vin}, {type: '2', txtInput: p.vin});
            if (p.chassis) c.push({type: '2', txtInput: p.chassis}, {type: '5', txtInput: p.chassis});
            if (p.policy_no) c.push({type: '7', txtInput: p.policy_no});
            return c;
        };
        const refresh = async () => {
            const p = pre();
            const d = await post({action: 'sh_last', ref_type: opts.ref ? opts.ref.type : null, ref_id: opts.ref ? opts.ref.id : null, cands: cands(p)});
            if (d.ok) show(d.item);
        };
        host.querySelector('[data-shb]').onclick = () => open({prefill: pre(), source: opts.source || 'form', ref: opts.ref, title: opts.title, lastItem: last, applyLabel: opts.applyLabel,
                                                              onApply: opts.onApply, onResult: it => show(it)});
        if (opts.lastItem) show(opts.lastItem);
        else if (opts.lookup !== false) refresh();
        return {refresh, get last() { return last; }};
    }

    // ---------------- اجرای استعلامِ گروهی (هر ردیف با کپچای خودش) ----------------
    async function batch(host, id, o) {
        css();
        o = o || {};
        const tok = newTok(); TOKS.add(tok);
        let stop = false, img = null, done = 0;
        host.innerHTML = `<div class="shu-cap"><div class="flex flex-wrap items-center justify-between gap-2"><b class="text-[12px] text-sky-900"><i class="fas fa-layer-group ml-1"></i>استعلامِ گروهیِ سنهاب — ردیف به ردیف</b><button type="button" class="shu-b g" data-stop>توقف</button></div>
            <p class="text-[11.5px] font-black text-slate-700 mt-2" data-row></p>
            <div class="flex flex-wrap items-center gap-2 mt-2"><span class="shu-capimg" data-ci><i class="fas fa-spinner fa-spin text-slate-400"></i></span><button type="button" class="shu-b g" data-re><i class="fas fa-rotate"></i></button>
                <input class="shu-in !mt-0" data-in style="width:150px;text-align:center;font-size:15px" dir="ltr" autocomplete="off" placeholder="کدِ تصویر"><button type="button" class="shu-go" data-ok>استعلام و ردیفِ بعد</button><button type="button" class="shu-b g" data-skip>ردکردنِ این ردیف</button></div>
            <p class="text-[11px] font-bold text-rose-600 mt-1" data-e></p><div data-log class="mt-2 space-y-1 max-h-40 overflow-auto"></div><div data-login></div></div>`;
        const q = s => host.querySelector(s);
        const fin = msg => { stop = true; TOKS.delete(tok); post({action: 'sh_release', tok}); host.innerHTML = `<p class="text-xs font-bold text-emerald-700 p-2"><i class="fas fa-circle-check ml-1"></i>${esc(msg)}</p>`; if (o.onDone) o.onDone(); };
        q('[data-stop]').onclick = () => fin('استعلامِ گروهی متوقف شد؛ هر وقت خواستید «ادامه» را بزنید.');
        const setImg = s => { img = s; q('[data-ci]').innerHTML = s ? `<img src="${s}">` : '<i class="fas fa-spinner fa-spin text-slate-400"></i>'; };
        const login = async (limg) => {
            const L = q('[data-login]');
            const ok = await new Promise(res => {
                L.innerHTML = `<div class="mt-2 p-2 rounded-xl bg-amber-50"><p class="text-[11px] font-black text-amber-800">نشستِ سنهاب بسته است؛ کدِ ورود:</p><div class="flex flex-wrap items-center gap-2 mt-1"><span class="shu-capimg" data-li>${limg ? `<img src="${limg}">` : ''}</span>
                    <input class="shu-in !mt-0" data-lin style="width:140px;text-align:center" dir="ltr"><button type="button" class="shu-go" data-lok>ورود</button></div><p class="text-[11px] text-rose-600 font-bold" data-le></p></div>`;
                const go = async () => { const r = await post({action: 'sh_login', captcha: en(L.querySelector('[data-lin]').value).trim()}); if (r.ok) { L.innerHTML = ''; res(true); } else { L.querySelector('[data-le]').textContent = r.error || ''; if (r.image) L.querySelector('[data-li]').innerHTML = `<img src="${r.image}">`; } };
                L.querySelector('[data-lok]').onclick = go;
                if (!limg) post({action: 'sh_login_captcha'}).then(r => { if (r.logged_in) { L.innerHTML = ''; res(true); } else if (r.image) L.querySelector('[data-li]').innerHTML = `<img src="${r.image}">`; });
            });
            return ok;
        };
        let row = null;
        const next = async () => {
            if (stop || !host.isConnected) return;
            const d = await post({action: 'sh_batch_next', id});
            if (o.onUpdate && d.batch) o.onUpdate(d.batch);
            row = d.ok ? d.next : null;
            if (!row) { fin(`استعلامِ گروهی تمام شد (${fa(done)} ردیف در این نوبت).`); return; }
            q('[data-row]').innerHTML = `ردیفِ ${fa(row.row_no)} از ${fa(d.batch ? d.batch.total : '')}: ${qv(row.query)} <small class="text-slate-400">(${esc(row.sh_type_fa)})</small>`;
            if (!img) {
                setImg(null);
                const c = await post({action: 'sh_captcha', type: row.sh_type, plcy: row.sh_plcy, tok});
                if (c.need_login) { if (await login(c.login_image)) return next(); return; }
                if (!c.ok) { q('[data-e]').textContent = c.error || ''; q('[data-ci]').innerHTML = '<button type="button" class="shu-b" data-retry>دوباره</button>'; q('[data-retry]').onclick = () => next(); return; }
                setImg(c.image);
            }
            q('[data-in]').value = ''; q('[data-in]').focus();
        };
        const go = async () => {
            if (!row) return;
            const cap = en(q('[data-in]').value).trim(); if (!cap) { q('[data-in]').focus(); return; }
            const b = q('[data-ok]'); b.disabled = true; q('[data-e]').textContent = '';
            const r = await post({action: 'sh_search', row_id: row.id, captcha: cap, tok});
            b.disabled = false;
            if (r.need_login) { img = null; if (await login(r.login_image)) next(); return; }
            img = r.image || null; if (img) setImg(img);
            if (!r.ok) { q('[data-e]').textContent = r.error || ''; if (!img) next(); else q('[data-in]').value = ''; return; }
            done++;
            const it = r.item, t = tone(it);
            q('[data-log]').insertAdjacentHTML('afterbegin', `<p class="text-[11px]"><b>${qv(it.query)}</b> — <span style="color:${t[1]}" class="font-black">${esc(fa(shortText(it)))}</span></p>`);
            next();
        };
        q('[data-ok]').onclick = go;
        q('[data-re]').onclick = async () => { img = null; setImg(null); const c = await post({action: 'sh_captcha', type: row ? row.sh_type : '4', plcy: row ? row.sh_plcy : '0', tok}); if (c.ok) setImg(c.image); else q('[data-e]').textContent = c.error || ''; };
        q('[data-skip]').onclick = async () => { if (!row) return; await post({action: 'sh_skip_row', id: row.id}); next(); };
        q('[data-in]').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
        next();
    }

    window.SanhabUI = {form, open, button, batch, details, resultHtml, shortText, tone};
})();
