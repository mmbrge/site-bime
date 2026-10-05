// فایل: renewals.js
// صفحه‌ی «اعلام تمدید»: همه‌ی بیمه‌نامه‌های صادرشده (شرکتی، بایگانی وارداتی، کارکنان) ردیف‌به‌ردیف با همه‌ی اطلاعات؛
// جستجو در همه‌ی ستون‌ها، مرتب‌سازی با کلیک روی سرستون، فیلتر (منبع، شرکت، بیمه‌گذار، قرارداد، نوع، تاریخ صدور، انقضا، وضعیتِ تمدید)،
// انتخابِ ستون‌ها، اعلامِ تمدید با ربات بله (به تفکیکِ شرکت/شخص) یا ثبتِ پیگیریِ دستی، و خروجیِ اکسل به همان ترتیب.
(function () {
    'use strict';
    const API = 'api/renewal_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v || '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => { const n = Number(v) || 0; return n ? fa(n.toLocaleString('en-US')) : ''; };
    const norm = s => en(s).toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[‌\s\-_/.()]+/g, '');
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const fpath = p => '/' + String(p || '').split('/').map(encodeURIComponent).join('/');
    const jnum = j => { const m = en(j).match(/(1[34]\d{2})\D+(\d{1,2})\D+(\d{1,2})/); return m ? (+m[1]) * 10000 + (+m[2]) * 100 + (+m[3]) : 0; };
    const LSK = 'rn:cols';
    const R = {root: null, rows: [], view: [], sel: new Set(), sort: {k: 'days_left', d: 1}, f: {quick: 'all'}, tpl: '', channels: {}, cols: null};

    // ستون‌ها: [کلید، عنوان، پیش‌فرض نمایش، تابعِ نمایش]
    const COLS = [
        ['source_fa', 'منبع', 1, r => `<span class="rn-src rn-src-${r.src}">${esc(r.source_fa)}</span>`],
        ['insured', 'بیمه‌گذار', 1, r => `<b>${esc(r.insured)}</b>${r.holder && r.holder !== r.insured ? `<small class="block text-slate-400">${esc(r.holder)}</small>` : ''}`],
        ['company', 'شرکت / کارفرما', 1, r => esc(r.company)],
        ['contract', 'قرارداد', 1, r => esc(r.contract)],
        ['plate', 'پلاک', 1, r => r.plate ? (typeof formatPlateHtml === 'function' ? `<span class="plate-display">${formatPlateHtml(r.plate)}</span>` : esc(fa(r.plate))) : '<span class="text-slate-300">—</span>'],
        ['chassis', 'شماره شاسی', 1, r => `<span dir="ltr" class="text-[10.5px]">${esc(r.chassis || '')}</span>`],
        ['type_fa', 'نوع', 1, r => `<span class="rn-t rn-t-${r.type}">${esc(r.type_fa)}</span>`],
        ['car', 'خودرو', 1, r => esc(r.car)],
        ['policy', 'شماره بیمه‌نامه', 1, r => `<span dir="ltr" class="text-[10.5px] font-bold">${esc(fa(r.policy || ''))}</span>`],
        ['issue_date', 'تاریخ صدور', 1, r => fa(r.issue_date)],
        ['end_date', 'انقضا', 1, r => `${fa(r.end_date)}${r.end_est ? '<small class="block text-[9px] text-slate-400">تخمینی (یک سال)</small>' : ''}`],
        ['days_left', 'روزِ مانده', 1, r => r.days_left === null ? '' : `<span class="rn-d ${r.days_left < 0 ? 'x' : r.days_left <= 15 ? 'h' : r.days_left <= 45 ? 'm' : ''}">${r.days_left < 0 ? fa(-r.days_left) + ' روز گذشته' : fa(r.days_left)}</span>`],
        ['premium', 'حق بیمه', 1, r => money(r.premium)],
        ['state_fa', 'وضعیتِ تمدید', 1, r => `<span class="rn-s rn-s-${r.state}">${esc(r.state_fa)}</span>${r.notice ? `<small class="block text-[9.5px] text-slate-400">${fa(r.notice.at)} · ${esc(r.notice.channel_fa)}${r.notice_count > 1 ? ' · ' + fa(r.notice_count) + ' بار' : ''}</small>` : ''}${r.renewed_by ? `<small class="block text-[9.5px] text-emerald-600" dir="ltr">${esc(fa(r.renewed_by.policy || ''))}</small>` : ''}`],
        ['national_id', 'کد / شناسه ملی', 0, r => `<span dir="ltr">${esc(fa(r.national_id || ''))}</span>`],
        ['phone', 'تلفن', 0, r => `<span dir="ltr">${esc(fa(r.phone || ''))}</span>`],
        ['engine', 'شماره موتور', 0, r => `<span dir="ltr">${esc(r.engine || '')}</span>`],
        ['model_year', 'مدل', 0, r => fa(r.model_year || '')],
        ['color', 'رنگ', 0, r => esc(r.color || '')],
        ['usage', 'کاربری', 0, r => esc(r.usage || '')],
        ['start_date', 'شروع', 0, r => fa(r.start_date)],
        ['car_value', 'ارزش خودرو', 0, r => money(r.car_value)],
        ['liability', 'تعهد مالی', 0, r => money(r.liability)],
        ['insurer', 'بیمه‌گر', 0, r => esc(r.insurer)],
        ['central_no', 'کد یکتا', 0, r => `<span dir="ltr">${esc(fa(r.central_no || ''))}</span>`],
        ['prev_insurer', 'بیمه‌گرِ قبلی', 0, r => esc(r.prev_insurer || '')],
        ['prev_policy', 'بیمه‌نامه‌ی قبلی', 0, r => `<span dir="ltr">${esc(fa(r.prev_policy || ''))}</span>`],
        ['address', 'نشانی', 0, r => `<span class="block max-w-[260px] truncate" title="${esc(r.address || '')}">${esc(r.address || '')}</span>`],
        ['owner_name', 'مالک', 0, r => esc(r.owner_name || '')],
    ];
    const QUICK = [['soon', 'تا ۶۰ روزِ آینده'], ['15', 'تا ۱۵ روز'], ['30', 'تا ۳۰ روز'], ['expired', 'منقضی و تمدیدنشده'], ['all', 'همه']];

    const CSS = `
    .rn-hero{border-radius:26px;padding:20px 22px;color:#fff;background:linear-gradient(120deg,#b45309,#db2777 55%,#7c3aed);box-shadow:0 20px 40px -26px #db2777;position:relative;overflow:hidden}
    .rn-hero::after{content:'';pointer-events:none;position:absolute;width:240px;height:240px;border-radius:50%;left:-60px;top:-120px;background:rgba(255,255,255,.12)}
    .rn-hero h2{font-size:19px;font-weight:900}.rn-hero p{font-size:11.5px;opacity:.88;margin-top:4px;line-height:1.9;max-width:780px}
    .rn-btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:14px;padding:8px 13px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit}
    .rn-btn:disabled{opacity:.45;cursor:not-allowed} .rn-btn.w{background:#fff;color:#be185d} .rn-btn.g{background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3)}
    .rn-btn.p{background:linear-gradient(120deg,#db2777,#7c3aed);color:#fff} .rn-btn.s{background:#f1f5f9;color:#334155} .rn-btn.e{background:#059669;color:#fff}
    .rn-chips{display:flex;flex-wrap:wrap;gap:6px}
    .rn-chip{border:1px solid #e2e8f0;background:#fff;border-radius:999px;padding:6px 12px;font-size:11.5px;font-weight:800;cursor:pointer;color:#334155;font-family:inherit}
    .rn-chip.on{background:#0f172a;color:#fff;border-color:#0f172a} .rn-chip b{font-weight:900;margin-right:4px;opacity:.8}
    .rn-f{display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:10px}
    .rn-f input,.rn-f select{border:1px solid #e2e8f0;border-radius:12px;padding:7px 10px;font-size:11.5px;font-family:inherit;background:#fff}
    .rn-tbl{width:100%;font-size:11.5px;border-collapse:separate;border-spacing:0}
    .rn-tbl th{background:#fdf2f8;color:#9d174d;font-weight:900;font-size:10.5px;padding:9px 8px;text-align:right;position:sticky;top:0;z-index:2;white-space:nowrap;cursor:pointer;user-select:none}
    .rn-tbl th .ar{opacity:.35;margin-right:3px} .rn-tbl th.on .ar{opacity:1}
    .rn-tbl td{padding:7px 8px;border-top:1px solid #f1f5f9;vertical-align:middle;white-space:nowrap}
    .rn-tbl tr:hover td{background:#fffafc} .rn-tbl tr.sel td{background:#fdf2f8}
    .rn-src{font-size:10px;font-weight:900;border-radius:999px;padding:2px 8px} .rn-src-C{background:#eef2ff;color:#4338ca} .rn-src-I{background:#ccfbf1;color:#0f766e} .rn-src-P{background:#e0f2fe;color:#0369a1}
    .rn-t{font-size:10px;font-weight:900;border-radius:8px;padding:2px 7px} .rn-t-BODY{background:#ecfeff;color:#0e7490} .rn-t-THIRDPARTY{background:#f5f3ff;color:#6d28d9}
    .rn-d{font-weight:900;color:#334155} .rn-d.m{color:#d97706} .rn-d.h{color:#e11d48} .rn-d.x{color:#9f1239;background:#fff1f2;border-radius:8px;padding:1px 6px}
    .rn-s{font-size:10px;font-weight:900;border-radius:999px;padding:2px 8px} .rn-s-none{background:#f1f5f9;color:#64748b} .rn-s-notified{background:#fef3c7;color:#92400e}
    .rn-s-renewed{background:#d1fae5;color:#065f46} .rn-s-closed{background:#ffe4e6;color:#9f1239}
    .rn-bulk{position:sticky;bottom:10px;z-index:5;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;background:#0f172a;color:#fff;border-radius:18px;padding:10px 14px;box-shadow:0 18px 30px -18px #0f172a}
    .rn-ov{position:fixed;inset:0;z-index:9000;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:28px 12px;overflow:auto;direction:rtl}
    .rn-pn{background:#fff;border-radius:26px;width:100%;max-width:980px;box-shadow:0 30px 60px -25px rgba(0,0,0,.5)}
    .rn-ph{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #f1f5f9} .rn-ph h3{font-size:15px;font-weight:900}
    .rn-x{margin-right:auto;width:34px;height:34px;border-radius:12px;background:#f1f5f9;border:0;cursor:pointer}
    .rn-pb{padding:16px 20px} .rn-g{border:1px solid #f1f5f9;border-radius:18px;padding:12px;margin-bottom:10px}
    .rn-g textarea,.rn-pb textarea{width:100%;border:1px solid #e2e8f0;border-radius:12px;padding:8px 10px;font-size:12px;font-family:inherit;line-height:1.9;min-height:120px}
    .rn-cols{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px}
    .rn-cols label{display:flex;gap:6px;align-items:center;font-size:12px;font-weight:700;cursor:pointer}
    `;
    function css() { if (document.getElementById('rn-css')) return; const s = document.createElement('style'); s.id = 'rn-css'; s.textContent = CSS; document.head.appendChild(s); }
    async function api(action, body) {
        try {
            const r = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body || {}))});
            const t = await r.text();
            try { return JSON.parse(t); } catch (e) { return {ok: false, error: r.ok ? 'پاسخِ نامعتبر از سرور.' : `خطای سرور (${r.status}).`}; }
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }
    function cols() {
        if (!R.cols) { let c = null; try { c = JSON.parse(localStorage.getItem(LSK) || 'null'); } catch (e) {} R.cols = Array.isArray(c) ? c : COLS.filter(x => x[2]).map(x => x[0]); }
        return COLS.filter(x => R.cols.includes(x[0]));
    }

    function shell() {
        R.root.innerHTML = `
        <div class="rn-hero"><h2><i class="fas fa-bell ml-2"></i>اعلام تمدید</h2>
            <p>همه‌ی بیمه‌نامه‌های صادرشده (شرکتی، بایگانی وارداتی و کارکنان) با تاریخِ انقضا. ردیف‌ها را انتخاب کنید و «اعلام تمدید» بزنید: برای هر شرکت یا شخص یک پیام ساخته می‌شود و با ربات بله فرستاده می‌شود، یا تماس/پیامکِ خودتان را به‌عنوانِ پیگیری ثبت کنید. اگر بیمه‌نامه‌ی تازه‌ای برای همان خودرو صادر شود، ردیف خودکار «تمدید شد» می‌شود.</p>
            <div class="flex flex-wrap gap-2 mt-3 relative z-10">
                <button class="rn-btn w" data-a="export"><i class="fas fa-file-excel"></i>اکسلِ همین فهرست</button>
                <button class="rn-btn g" data-a="cols"><i class="fas fa-table-columns"></i>ستون‌ها</button>
                <button class="rn-btn g" data-a="reload"><i class="fas fa-rotate"></i></button>
            </div></div>
        <div class="rn-chips mt-3" data-quick></div>
        <div class="rn-f mt-3">
            <input type="search" data-f="q" placeholder="جستجو در همه‌چیز: بیمه‌گذار، پلاک، شاسی، شماره بیمه‌نامه، تلفن…" style="flex:1;min-width:220px">
            <select data-f="src"><option value="">همه‌ی منابع</option><option value="C">شرکتی</option><option value="I">بایگانی وارداتی</option><option value="P">کارکنان</option></select>
            <select data-f="type"><option value="">بدنه و ثالث</option><option value="BODY">بدنه</option><option value="THIRDPARTY">ثالث</option></select>
            <select data-f="company"><option value="">همه‌ی شرکت‌ها / کارفرماها</option></select>
            <select data-f="contract"><option value="">همه‌ی قراردادها</option></select>
            <select data-f="state"><option value="">همه‌ی وضعیت‌ها</option><option value="none">اعلام نشده</option><option value="notified">اعلام شده</option><option value="renewed">تمدید شد</option><option value="closed">تمدید نمی‌کند / جای دیگر</option></select>
            <input data-f="ifrom" placeholder="صدور از ۱۴۰۵/۰۱/۰۱" dir="ltr" style="width:120px"><input data-f="ito" placeholder="صدور تا" dir="ltr" style="width:100px">
            <input data-f="efrom" placeholder="انقضا از" dir="ltr" style="width:100px"><input data-f="eto" placeholder="انقضا تا" dir="ltr" style="width:100px">
            <button class="rn-btn s" data-a="clear"><i class="fas fa-eraser"></i>پاک‌کردن</button>
        </div>
        <div class="text-[11px] text-slate-500 mt-2 px-1" data-sum></div>
        <div class="card overflow-hidden mt-2"><div class="overflow-auto max-h-[64vh]" data-tw></div></div>
        <div data-bulk></div>`;
        const q = s => R.root.querySelector(s);
        q('[data-a="export"]').onclick = exportXlsx;
        q('[data-a="cols"]').onclick = pickCols;
        q('[data-a="reload"]').onclick = load;
        q('[data-a="clear"]').onclick = () => { R.f = {quick: 'all'}; sync(); apply(); };
        let t = null;
        R.root.querySelectorAll('[data-f]').forEach(el => el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => {
            R.f[el.dataset.f] = el.value; clearTimeout(t); t = setTimeout(apply, el.tagName === 'INPUT' ? 250 : 0);
        }));
    }
    function sync() { R.root.querySelectorAll('[data-f]').forEach(el => { el.value = R.f[el.dataset.f] || ''; }); }

    async function load() {
        const tw = R.root.querySelector('[data-tw]');
        tw.innerHTML = '<p class="p-10 text-center text-slate-400 text-xs"><i class="fas fa-spinner fa-spin ml-1"></i>در حال بارگذاری…</p>';
        const d = await api('list');
        if (!d.ok) { tw.innerHTML = `<p class="p-10 text-center text-rose-600 text-xs">${esc(d.error || 'خطا')}</p>`; return; }
        R.rows = d.rows; R.tpl = d.template; R.channels = d.channels;
        R.rows.forEach(r => { r._s = norm(Object.values(r).filter(v => v && typeof v !== 'object').join(' ') + ' ' + (r.notice ? r.notice.note || '' : '')); r._i = jnum(r.issue_date); r._e = jnum(r.end_date); });
        const opts = (k, first) => { const el = R.root.querySelector(`[data-f="${k}"]`); const vals = [...new Set(R.rows.map(r => r[k]).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fa'));
            el.innerHTML = `<option value="">${first}</option>` + vals.map(v => `<option>${esc(v)}</option>`).join(''); el.value = R.f[k] || ''; };
        opts('company', 'همه‌ی شرکت‌ها / کارفرماها'); opts('contract', 'همه‌ی قراردادها');
        const keys = new Set(R.rows.map(r => r.key)); [...R.sel].forEach(k => { if (!keys.has(k)) R.sel.delete(k); });
        apply();
    }

    function match(r, quick) {
        const f = R.f;
        if (f.src && r.src !== f.src) return false;
        if (f.type && r.type !== f.type) return false;
        if (f.company && r.company !== f.company) return false;
        if (f.contract && r.contract !== f.contract) return false;
        if (f.state && r.state !== f.state) return false;
        if (f.ifrom && jnum(f.ifrom) && r._i < jnum(f.ifrom)) return false;
        if (f.ito && jnum(f.ito) && r._i > jnum(f.ito)) return false;
        if (f.efrom && jnum(f.efrom) && r._e < jnum(f.efrom)) return false;
        if (f.eto && jnum(f.eto) && r._e > jnum(f.eto)) return false;
        if (f.q) { const words = en(f.q).trim().split(/\s+/).map(norm).filter(Boolean); if (!r._s.includes(norm(f.q)) && !words.every(w => r._s.includes(w))) return false; }
        const dl = r.days_left;
        switch (quick) {
            case 'soon': return dl !== null && dl >= -30 && dl <= 60 && r.state !== 'renewed';
            case '15': return dl !== null && dl >= 0 && dl <= 15 && r.state !== 'renewed';
            case '30': return dl !== null && dl >= 0 && dl <= 30 && r.state !== 'renewed';
            case 'expired': return dl !== null && dl < 0 && r.state !== 'renewed' && r.state !== 'closed';
            default: return true;
        }
    }
    function apply() {
        const qb = R.root.querySelector('[data-quick]');
        qb.innerHTML = QUICK.map(([k, l]) => `<button class="rn-chip ${R.f.quick === k ? 'on' : ''}" data-q="${k}">${l}<b>${fa(R.rows.filter(r => match(r, k)).length)}</b></button>`).join('');
        qb.querySelectorAll('[data-q]').forEach(b => b.onclick = () => { R.f.quick = b.dataset.q; apply(); });
        const {k, d} = R.sort;
        const val = r => k === 'issue_date' ? r._i : k === 'end_date' ? r._e : r[k];
        R.view = R.rows.filter(r => match(r, R.f.quick || 'all')).sort((a, b) => {
            let x = val(a), y = val(b);
            if (x === null || x === undefined || x === '') return 1; if (y === null || y === undefined || y === '') return -1;
            return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'fa')) * d;
        });
        render();
    }

    function render() {
        const C = cols(), tw = R.root.querySelector('[data-tw]');
        const prem = R.view.reduce((a, r) => a + (Number(r.premium) || 0), 0);
        R.root.querySelector('[data-sum]').innerHTML = `${fa(R.view.length)} بیمه‌نامه از ${fa(R.rows.length)} · جمعِ حق بیمه ${money(prem) || '۰'} ریال${R.sel.size ? ` · <b class="text-pink-700">${fa(R.sel.size)} انتخاب‌شده</b>` : ''}`;
        if (!R.view.length) { tw.innerHTML = '<div class="p-12 text-center text-slate-400 text-sm"><i class="fas fa-calendar-check text-3xl block mb-2 text-slate-200"></i>بیمه‌نامه‌ای با این فیلترها نیست.</div>'; bulk(); return; }
        const allSel = R.view.every(r => R.sel.has(r.key));
        tw.innerHTML = `<table class="rn-tbl no-count"><thead><tr><th style="width:30px;cursor:default"><input type="checkbox" data-all ${allSel ? 'checked' : ''}></th>
            ${C.map(c => `<th data-sort="${c[0]}" class="${R.sort.k === c[0] ? 'on' : ''}">${c[1]}<span class="ar">${R.sort.k === c[0] ? (R.sort.d > 0 ? '▲' : '▼') : '⇅'}</span></th>`).join('')}<th style="cursor:default"></th></tr></thead>
            <tbody>${R.view.map(r => `<tr class="${R.sel.has(r.key) ? 'sel' : ''}" data-k="${r.key}"><td><input type="checkbox" data-sel="${r.key}" ${R.sel.has(r.key) ? 'checked' : ''}></td>
                ${C.map(c => `<td>${c[3](r)}</td>`).join('')}
                <td><button class="rn-btn s" style="padding:4px 8px;font-size:10.5px" data-h="${r.key}" title="تاریخچه‌ی پیگیری"><i class="fas fa-clock-rotate-left"></i></button>
                ${r.file ? `<a class="rn-btn s" style="padding:4px 8px;font-size:10.5px" href="${fpath(r.file)}" target="_blank" title="فایلِ بیمه‌نامه"><i class="fas fa-file-pdf"></i></a>` : ''}</td></tr>`).join('')}</tbody></table>`;
        tw.querySelector('[data-all]').onchange = e => { R.view.forEach(r => e.target.checked ? R.sel.add(r.key) : R.sel.delete(r.key)); render(); };
        tw.querySelectorAll('[data-sel]').forEach(c => c.onchange = () => { c.checked ? R.sel.add(c.dataset.sel) : R.sel.delete(c.dataset.sel); c.closest('tr').classList.toggle('sel', c.checked); bulk(); });
        tw.querySelectorAll('[data-sort]').forEach(th => th.onclick = () => { const k = th.dataset.sort; R.sort = {k, d: R.sort.k === k ? -R.sort.d : 1}; apply(); });
        tw.querySelectorAll('[data-h]').forEach(b => b.onclick = () => history(b.dataset.h));
        bulk();
    }
    function bulk() {
        const box = R.root.querySelector('[data-bulk]');
        if (!R.sel.size) { box.innerHTML = ''; return; }
        box.innerHTML = `<div class="rn-bulk mt-3"><span class="text-xs font-black"><i class="fas fa-check-double ml-1 text-pink-300"></i>${fa(R.sel.size)} بیمه‌نامه انتخاب شد</span>
            <span class="flex flex-wrap gap-2"><button class="rn-btn p" data-b="notify"><i class="fas fa-paper-plane"></i>اعلام تمدید</button>
            <button class="rn-btn g" style="background:rgba(255,255,255,.12);color:#fff" data-b="mark"><i class="fas fa-clipboard-check"></i>ثبتِ پیگیری</button>
            <button class="rn-btn g" style="background:rgba(255,255,255,.12);color:#fff" data-b="xls"><i class="fas fa-file-excel"></i>اکسلِ انتخاب‌شده‌ها</button>
            <button class="rn-btn g" style="background:rgba(255,255,255,.12);color:#fff" data-b="clr">لغوِ انتخاب</button></span></div>`;
        box.querySelector('[data-b="notify"]').onclick = notify;
        box.querySelector('[data-b="mark"]').onclick = () => mark([...R.sel]);
        box.querySelector('[data-b="xls"]').onclick = () => exportXlsx(R.view.filter(r => R.sel.has(r.key)).map(r => r.key));
        box.querySelector('[data-b="clr"]').onclick = () => { R.sel.clear(); render(); };
    }

    function overlay(title, html) {
        const ov = document.createElement('div'); ov.className = 'rn-ov';
        ov.innerHTML = `<div class="rn-pn"><div class="rn-ph"><h3>${title}</h3><button class="rn-x" data-x>✕</button></div><div class="rn-pb">${html}</div></div>`;
        document.body.appendChild(ov);
        const close = () => { ov.remove(); document.removeEventListener('keydown', key); };
        const key = e => { if (e.key === 'Escape' && !document.querySelector('.modal-overlay.active')) close(); };
        document.addEventListener('keydown', key);
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        ov.querySelector('[data-x]').onclick = close;
        return {ov, close, q: s => ov.querySelector(s)};
    }

    async function notify() {
        const keys = R.view.filter(r => R.sel.has(r.key)).map(r => r.key);
        const m = overlay('<i class="fas fa-paper-plane text-pink-600 ml-1"></i>اعلام تمدید', `
            <label class="text-[11px] font-black text-slate-600">متنِ پیام <small class="font-bold text-slate-400">— {نام} نامِ شرکت/شخص، {لیست} بیمه‌نامه‌ها با پلاک و انقضا، {تعداد}</small></label>
            <textarea data-tpl>${esc(R.tpl)}</textarea>
            <div class="flex flex-wrap gap-2 mt-2"><button class="rn-btn s" data-pv><i class="fas fa-eye"></i>ساختِ پیام‌ها</button>
                <label class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-600"><input type="checkbox" data-save> این متن پیش‌فرض بماند</label></div>
            <div data-groups class="mt-3"></div>`);
        const build = async () => {
            const box = m.q('[data-groups]');
            box.innerHTML = '<p class="text-center text-xs text-slate-400 py-4"><i class="fas fa-spinner fa-spin ml-1"></i>…</p>';
            const d = await api('preview', {keys, template: m.q('[data-tpl]').value});
            if (!d.ok) { box.innerHTML = `<p class="text-rose-600 text-xs">${esc(d.error)}</p>`; return; }
            const bots = d.groups.filter(g => g.bot);
            box.innerHTML = `<p class="text-[11.5px] text-slate-600 mb-2">${fa(d.groups.length)} گیرنده · <b class="text-emerald-700">${fa(bots.length)} با ربات</b> · ${fa(d.groups.length - bots.length)} بدونِ ربات (متن را کپی کنید و با تماس/پیامک اطلاع دهید، بعد «ثبت» بزنید)</p>`
                + d.groups.map(g => `<div class="rn-g" data-g="${g.gid}"><div class="flex flex-wrap items-center gap-2 mb-2">
                    <b class="text-sm">${esc(g.name)}</b><span class="rn-src rn-src-${g.kind}">${{C: 'شرکت', I: 'وارداتی', P: 'کارکنان'}[g.kind]}</span><span class="text-[10.5px] text-slate-500">${fa(g.count)} بیمه‌نامه</span>
                    <span class="text-[10.5px] font-bold ${g.bot ? 'text-emerald-700' : 'text-amber-700'}"><i class="fas ${g.bot ? 'fa-robot' : 'fa-phone'} ml-1"></i>${esc(g.bot_fa)}${g.phone ? ' · <span dir="ltr">' + esc(fa(g.phone)) + '</span>' : ''}</span>
                    <label class="mr-auto inline-flex items-center gap-1 text-[11px] font-bold"><input type="checkbox" data-pick ${g.bot ? 'checked' : ''} ${g.bot ? '' : 'disabled'}>ارسال با ربات</label></div>
                    <textarea data-msg>${esc(g.message)}</textarea>
                    <div class="flex flex-wrap gap-2 mt-2"><button class="rn-btn s" data-copy><i class="fas fa-copy"></i>کپیِ متن</button>
                    ${g.bot ? '' : `<select data-ch class="border rounded-xl px-2 text-xs">${Object.entries(R.channels).filter(([k]) => !['bot', 'declined', 'renewed_out'].includes(k)).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select><button class="rn-btn e" data-mk><i class="fas fa-check"></i>ثبت: اطلاع داده شد</button>`}</div></div>`).join('')
                + (bots.length ? `<button class="rn-btn p mt-2" data-send><i class="fas fa-paper-plane"></i>ارسال با ربات برای انتخاب‌شده‌ها</button>` : '');
            box.querySelectorAll('[data-g]').forEach(el => {
                const g = d.groups.find(x => x.gid === el.dataset.g);
                el.querySelector('[data-copy]').onclick = () => { const t = el.querySelector('[data-msg]').value; (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => toast('کپی شد.', 'success')).catch(() => { el.querySelector('[data-msg]').select(); document.execCommand('copy'); toast('کپی شد.', 'success'); }); };
                const mk = el.querySelector('[data-mk]');
                if (mk) mk.onclick = async () => {
                    const r = await api('mark', {keys: g.keys, channel: el.querySelector('[data-ch]').value, note: 'اعلام تمدید', message: el.querySelector('[data-msg]').value});
                    if (!r.ok) return toast(r.error, 'error');
                    mk.disabled = true; mk.innerHTML = '<i class="fas fa-check"></i>ثبت شد'; R.dirty = true;
                };
            });
            const sb = box.querySelector('[data-send]');
            if (sb) sb.onclick = async () => {
                const gids = [], messages = {};
                box.querySelectorAll('[data-g]').forEach(el => { const c = el.querySelector('[data-pick]'); if (c && c.checked) { gids.push(el.dataset.g); messages[el.dataset.g] = el.querySelector('[data-msg]').value; } });
                if (!gids.length) return toast('گیرنده‌ای انتخاب نشده.', 'error');
                sb.disabled = true; sb.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ ارسال…';
                const r = await api('send', {keys, gids, messages, template: m.q('[data-tpl]').value, save_template: m.q('[data-save]').checked ? 1 : 0});
                if (!r.ok) { sb.disabled = false; return toast(r.error, 'error'); }
                const fail = (r.failed || []).filter(f => gids.includes(f.gid));
                toast(`${fa(r.sent)} پیام فرستاده شد${fail.length ? ` · ${fa(fail.length)} ناموفق` : ''}.`, fail.length ? 'error' : 'success');
                sb.innerHTML = '<i class="fas fa-check"></i>فرستاده شد'; R.dirty = true;
                if (m.q('[data-save]').checked) R.tpl = m.q('[data-tpl]').value;
            };
        };
        m.q('[data-pv]').onclick = build;
        const oldClose = m.close;
        m.ov.querySelector('[data-x]').onclick = () => { oldClose(); if (R.dirty) { R.dirty = false; load(); } };
        build();
    }

    function mark(keys) {
        const m = overlay('<i class="fas fa-clipboard-check text-pink-600 ml-1"></i>ثبتِ پیگیری', `
            <p class="text-xs text-slate-500 mb-2">${fa(keys.length)} بیمه‌نامه</p>
            <div class="flex flex-wrap gap-2 mb-2">${Object.entries(R.channels).filter(([k]) => k !== 'bot').map(([k, v], i) => `<label class="rn-chip"><input type="radio" name="rnch" value="${k}" ${i === 0 ? 'checked' : ''} class="ml-1">${v}</label>`).join('')}</div>
            <textarea data-note placeholder="توضیح (مثلاً: با آقای ... تماس گرفته شد، هفته‌ی بعد خبر می‌دهد)" style="min-height:70px"></textarea>
            <button class="rn-btn p mt-2" data-ok><i class="fas fa-check"></i>ثبت</button>`);
        m.q('[data-ok]').onclick = async () => {
            const r = await api('mark', {keys, channel: m.ov.querySelector('input[name="rnch"]:checked').value, note: m.q('[data-note]').value});
            if (!r.ok) return toast(r.error, 'error');
            toast('پیگیری ثبت شد.', 'success'); m.close(); load();
        };
    }

    async function history(key) {
        const r = R.rows.find(x => x.key === key);
        const d = await api('history', {key});
        if (!d.ok) return toast(d.error, 'error');
        const m = overlay(`<i class="fas fa-clock-rotate-left text-pink-600 ml-1"></i>پیگیری‌ها · ${esc(r ? r.insured : '')}`, `
            ${r ? `<p class="text-xs text-slate-500 mb-3">${esc(r.type_fa)} · ${esc(fa(r.plate || r.chassis || ''))} · بیمه‌نامه <span dir="ltr">${esc(fa(r.policy || ''))}</span> · انقضا ${fa(r.end_date)}</p>` : ''}
            ${d.rows.length ? d.rows.map(n => `<div class="rn-g"><div class="flex flex-wrap gap-2 items-center text-xs"><b>${esc(n.channel_fa)}</b>${n.ok == 1 ? '' : '<span class="text-rose-600 font-bold">ناموفق</span>'}
                <span class="text-slate-400">${fa(n.at)}${n.by_name ? ' · ' + esc(n.by_name) : ''}</span></div>${n.note ? `<p class="text-xs mt-1">${esc(n.note)}</p>` : ''}${n.message ? `<pre class="text-[11px] text-slate-500 whitespace-pre-wrap mt-1" style="font-family:inherit">${esc(n.message)}</pre>` : ''}</div>`).join('')
                : '<p class="text-center text-xs text-slate-400 py-6">هنوز پیگیری‌ای ثبت نشده.</p>'}
            <button class="rn-btn p mt-2" data-new><i class="fas fa-plus"></i>ثبتِ پیگیریِ تازه</button>`);
        m.q('[data-new]').onclick = () => { m.close(); mark([key]); };
    }

    function pickCols() {
        const m = overlay('<i class="fas fa-table-columns text-pink-600 ml-1"></i>ستون‌های جدول', `<div class="rn-cols">${COLS.map(c => `<label><input type="checkbox" value="${c[0]}" ${R.cols.includes(c[0]) ? 'checked' : ''}>${c[1]}</label>`).join('')}</div>
            <div class="flex gap-2 mt-3"><button class="rn-btn p" data-ok>اعمال</button><button class="rn-btn s" data-all>همه</button><button class="rn-btn s" data-def>پیش‌فرض</button></div>`);
        const set = list => { R.cols = list; try { localStorage.setItem(LSK, JSON.stringify(list)); } catch (e) {} m.close(); render(); };
        m.q('[data-ok]').onclick = () => set([...m.ov.querySelectorAll('.rn-cols input:checked')].map(i => i.value));
        m.q('[data-all]').onclick = () => set(COLS.map(c => c[0]));
        m.q('[data-def]').onclick = () => set(COLS.filter(c => c[2]).map(c => c[0]));
    }

    function exportXlsx(keys) {
        const k = Array.isArray(keys) ? keys : R.view.map(r => r.key);
        if (!k.length) return toast('ردیفی برای خروجی نیست.', 'error');
        const f = document.createElement('form');
        f.method = 'POST'; f.action = API; f.target = '_blank';
        f.innerHTML = `<input type="hidden" name="action" value="export"><input type="hidden" name="keys">`;
        f.querySelector('[name="keys"]').value = JSON.stringify(k);
        document.body.appendChild(f); f.submit(); f.remove();
    }

    async function init(root) {
        if (!root) return;
        css();
        if (R.root !== root || !root.querySelector('.rn-hero')) { R.root = root; shell(); sync(); }
        cols();
        await load();
    }
    window.Renewals = {init, reload: load};
})();
