// فایل: import-archive.js
// صفحه‌ی «بایگانی وارداتی» (مرکز صدور): ورودِ اکسلِ خروجیِ بیمه‌گر (بدنه و ثالث)، فهرستِ ردیف‌ها با وضعیت و کمبودها،
// پنلِ هر ردیف (چک‌لیستِ مدارک، بارگذاریِ چندفایلی با پیش‌نمایش و تعیینِ نوعِ هر فایل، ویرایشِ اطلاعات، بازدید لازم/نالازم،
// ساختِ گزارش بازدید، ثبتِ صدور با همان روالِ صدور)، بارگذاریِ گروهیِ مدارک با تشخیصِ ردیف از نامِ فایل، و صدورِ گروهی.
// API: api/import_actions.php (فهرست/ورود/ویرایش) + api/company_actions.php (مدرک، صدور، صدورِ گروهی)
(function () {
    'use strict';
    const API = 'api/import_actions.php', CAPI = 'api/company_actions.php';
    const S = {root: null, meta: null, rows: [], counts: {}, f: {status: 'OPEN'}, sel: new Set(), busy: false, open: null};
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v || '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => { const n = Number(en(v).replace(/[^\d]/g, '')) || 0; return n ? fa(n.toLocaleString('en-US')) : ''; };
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const fpath = p => '/' + String(p || '').split('/').map(encodeURIComponent).join('/');
    const plateHtml = r => (r.plate_p2 && typeof fmtPlateHtml === 'function') ? `<span class="plate-display">${fmtPlateHtml(r)}</span>`
        : (r.chassis_no ? `<b dir="ltr" class="text-[11px]">${esc(r.chassis_no)}</b><small class="block text-[9px] text-slate-400">شماره شاسی</small>` : '<span class="text-slate-300">—</span>');
    const confirmBox = async (t, m, o) => (window.uiConfirm ? uiConfirm(t, m, o || {}) : confirm(m));
    async function api(action, body, url) {
        try {
            const r = await fetch(url || API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body || {}))});
            const t = await r.text();
            try { return JSON.parse(t); } catch (e) { return {ok: false, error: r.ok ? 'پاسخِ نامعتبر از سرور.' : `خطای سرور (${r.status}).`}; }
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    }

    const CSS = `
    .ia-hero{position:relative;overflow:hidden;border-radius:26px;padding:20px 22px;color:#fff;background:linear-gradient(120deg,#0f766e,#0e7490 55%,#4338ca);box-shadow:0 20px 40px -26px #0f766e}
    .ia-hero::after{content:'';pointer-events:none;position:absolute;width:260px;height:260px;border-radius:50%;left:-70px;top:-130px;background:rgba(255,255,255,.1)}
    .ia-hero h2{font-size:19px;font-weight:900}.ia-hero p{font-size:11.5px;opacity:.85;margin-top:4px;line-height:1.9;max-width:760px}
    .ia-hero .ia-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;position:relative;z-index:1}
    .ia-btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:14px;padding:9px 14px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;transition:filter .15s,transform .15s}
    .ia-btn:hover{filter:brightness(1.06)} .ia-btn:active{transform:scale(.97)} .ia-btn:disabled{opacity:.45;cursor:not-allowed}
    .ia-btn.w{background:#fff;color:#0f766e;box-shadow:0 8px 18px -12px rgba(0,0,0,.5)} .ia-btn.g{background:rgba(255,255,255,.16);color:#fff;border:1px solid rgba(255,255,255,.25)}
    .ia-btn.p{background:linear-gradient(120deg,#059669,#0d9488);color:#fff} .ia-btn.s{background:#f1f5f9;color:#334155} .ia-btn.d{background:#fff1f2;color:#be123c} .ia-btn.i{background:#eef2ff;color:#4338ca}
    .ia-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
    .ia-stat{border-radius:18px;padding:10px 12px;border:1px solid #eef2f7;background:#fff;cursor:pointer;transition:transform .15s,box-shadow .15s}
    .ia-stat:hover{transform:translateY(-1px);box-shadow:0 10px 22px -18px rgba(15,23,42,.5)} .ia-stat.on{outline:2px solid var(--c)}
    .ia-stat p{font-size:10.5px;font-weight:800;color:#64748b} .ia-stat b{font-size:20px;font-weight:900;color:var(--c)}
    .ia-filters{display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:10px}
    .ia-filters input,.ia-filters select{border:1px solid #e2e8f0;border-radius:12px;padding:7px 10px;font-size:11.5px;font-family:inherit;background:#fff}
    .ia-tbl{width:100%;font-size:11.5px;border-collapse:separate;border-spacing:0}
    .ia-tbl th{background:#f0fdfa;color:#115e59;font-weight:900;font-size:10.5px;padding:9px 8px;text-align:right;position:sticky;top:0;z-index:2;white-space:nowrap}
    .ia-tbl td{padding:8px;border-top:1px solid #f1f5f9;vertical-align:middle}
    .ia-tbl tr.ia-g td{background:linear-gradient(90deg,#f8fafc,#fff);font-weight:900;color:#0f172a;font-size:11.5px;border-top:2px solid #e2e8f0}
    .ia-tbl tr.ia-r:hover td{background:#f8fffd} .ia-tbl tr.ia-r.sel td{background:#ecfeff}
    .ia-chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:800;border-radius:999px;padding:2px 8px;white-space:nowrap}
    .ia-st-PENDING{background:#fef3c7;color:#92400e}.ia-st-READY{background:#d1fae5;color:#065f46}.ia-st-ISSUED{background:#e0f2fe;color:#075985}
    .ia-docs{display:flex;flex-wrap:wrap;gap:3px;max-width:330px}
    .ia-d{font-size:9.5px;font-weight:800;border-radius:8px;padding:2px 6px} .ia-d.ok{background:#ecfdf5;color:#047857} .ia-d.no{background:#fff1f2;color:#be123c} .ia-d.op{background:#f1f5f9;color:#64748b}
    .ia-bulk{position:sticky;bottom:10px;z-index:5;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;background:#0f172a;color:#fff;border-radius:18px;padding:10px 14px;box-shadow:0 18px 30px -18px #0f172a}
    .ia-ov{position:fixed;inset:0;z-index:9000;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:28px 12px;overflow:auto;direction:rtl}
    .ia-pn{background:#fff;border-radius:26px;width:100%;max-width:1060px;box-shadow:0 30px 60px -25px rgba(0,0,0,.5);animation:iaIn .22s ease}
    @keyframes iaIn{from{transform:translateY(12px);opacity:0}to{transform:none;opacity:1}}
    .ia-ph{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid #f1f5f9;position:sticky;top:0;background:#fff;border-radius:26px 26px 0 0;z-index:3}
    .ia-ph h3{font-size:15px;font-weight:900;color:#0f172a} .ia-x{margin-right:auto;width:34px;height:34px;border-radius:12px;background:#f1f5f9;border:0;cursor:pointer;font-size:15px}
    .ia-pb{padding:16px 20px}
    .ia-sec{border:1px solid #eef2f7;border-radius:20px;padding:14px;margin-bottom:12px}
    .ia-sec h4{font-size:12.5px;font-weight:900;color:#0f172a;margin-bottom:10px;display:flex;align-items:center;gap:6px}
    .ia-ck{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px}
    .ia-ci{border-radius:16px;padding:10px;border:1px solid #e2e8f0;background:#fafafa} .ia-ci.ok{border-color:#a7f3d0;background:#f0fdf4} .ia-ci.miss{border-color:#fecdd3;background:#fff5f6}
    .ia-ci b{font-size:11.5px;font-weight:900} .ia-ci small{display:block;font-size:10px;color:#64748b;margin-top:2px}
    .ia-ci .ia-dl{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
    .ia-ci .ia-dl a,.ia-ci .ia-dl span{font-size:10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:2px 6px;color:#1d4ed8;text-decoration:none}
    .ia-drop{border:2px dashed #99f6e4;background:#f0fdfa;border-radius:18px;padding:18px;text-align:center;cursor:pointer;color:#0f766e;font-weight:800;font-size:12px;transition:background .15s}
    .ia-drop.drag{background:#ccfbf1}
    .ia-files{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px;margin-top:10px}
    .ia-fc{border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;background:#fff;position:relative}
    .ia-fc .th{height:120px;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:zoom-in}
    .ia-fc .th img{width:100%;height:100%;object-fit:cover} .ia-fc .th i{font-size:34px;color:#e11d48}
    .ia-fc .bd{padding:8px;display:grid;gap:5px}
    .ia-fc select{width:100%;border:1px solid #e2e8f0;border-radius:10px;padding:5px 6px;font-size:11px;font-family:inherit}
    .ia-fc .nm{font-size:9.5px;color:#64748b;word-break:break-all;line-height:1.6}
    .ia-fc .rm{position:absolute;top:6px;left:6px;width:24px;height:24px;border-radius:8px;border:0;background:rgba(15,23,42,.65);color:#fff;cursor:pointer}
    .ia-fc .pg{height:4px;background:#e2e8f0} .ia-fc .pg i{display:block;height:100%;width:0;background:#10b981;transition:width .2s}
    .ia-fc.done{border-color:#6ee7b7} .ia-fc.err{border-color:#fda4af}
    .ia-form{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px}
    .ia-form label{display:block;font-size:10px;font-weight:800;color:#64748b;margin-bottom:3px}
    .ia-form input,.ia-form select,.ia-form textarea{width:100%;border:1px solid #e2e8f0;border-radius:12px;padding:7px 9px;font-size:12px;font-family:inherit;background:#fff}
    .ia-form .w{grid-column:1/-1}
    .ia-kv{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 12px;font-size:11px}
    .ia-kv div{border-bottom:1px dashed #f1f5f9;padding:3px 0} .ia-kv span{color:#94a3b8;font-size:10px;display:block}
    .ia-tog{display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;font-weight:800;color:#0f172a}
    .ia-tog input{width:18px;height:18px;accent-color:#0d9488}
    .ia-pf{position:sticky;bottom:0;background:#fff;border-top:1px solid #f1f5f9;padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px;border-radius:0 0 26px 26px}
    .ia-pv td,.ia-pv th{padding:6px 8px;font-size:11px;border-top:1px solid #f1f5f9;text-align:right;white-space:nowrap} .ia-pv th{background:#f8fafc;font-weight:900;color:#475569;position:sticky;top:0}
    .ia-pv tr.bad td{background:#fff5f5;color:#9f1239}
    .ia-tree{font-size:11px;line-height:2;background:#0f172a;color:#cbd5e1;border-radius:16px;padding:10px 14px;direction:rtl;max-height:200px;overflow:auto}
    .ia-tree b{color:#5eead4}
    `;

    function ensureCss() { if (document.getElementById('ia-css')) return; const s = document.createElement('style'); s.id = 'ia-css'; s.textContent = CSS; document.head.appendChild(s); }

    // ------------------------------------------------------------------ صفحه
    function shell() {
        S.root.innerHTML = `
        <div class="ia-hero">
            <h2><i class="fas fa-box-archive ml-2"></i>بایگانی وارداتی</h2>
            <p>خروجیِ اکسلِ بیمه‌گر (بدنه و ثالث) را وارد کنید تا برای هر بیمه‌نامه ردیف و پوشه ساخته شود: «بایگانی وارداتی / سال / ماهِ صدور / بیمه‌گذار / درخواست». هر چه کم است (مدرک یا اطلاعات) همین‌جا تکمیل می‌شود، بعد بیمه‌نامه را می‌گذارید و با همان روالِ صدور، بایگانی و به «بایگانی صادره» (ماهِ صدورِ بیمه‌نامه) کپی می‌شود. این ردیف‌ها قسط و ردیفِ مالی ندارند و در «صادره‌ها» با برچسبِ «بایگانی وارداتی» قابلِ فیلترند.</p>
            <div class="ia-acts">
                <button class="ia-btn w" data-a="import"><i class="fas fa-file-excel"></i>ورودِ اکسلِ بیمه‌گر</button>
                <button class="ia-btn g" data-a="bundle"><i class="fas fa-layer-group"></i>صدورِ گروهی با فایلِ بیمه‌نامه‌ها</button>
                <button class="ia-btn g" data-a="bulkdocs"><i class="fas fa-images"></i>بارگذاریِ گروهیِ مدارک</button>
                <button class="ia-btn g" data-a="export"><i class="fas fa-file-arrow-down"></i>اکسلِ وضعیت و کمبودها</button>
                <button class="ia-btn g" data-a="reload" title="بروزرسانی"><i class="fas fa-rotate"></i></button>
            </div>
        </div>
        <div class="ia-stats mt-3" data-stats></div>
        <div class="ia-filters mt-3">
            <input type="search" data-f="q" placeholder="جستجو: پلاک، شاسی، شماره بیمه‌نامه، بیمه‌گذار…" style="flex:1;min-width:200px">
            <select data-f="type"><option value="">بدنه و ثالث</option><option value="BODY">بدنه</option><option value="THIRDPARTY">ثالث</option></select>
            <select data-f="company_id"><option value="">همه‌ی بیمه‌گذارها</option></select>
            <select data-f="month"><option value="">همه‌ی ماه‌ها (صدور)</option></select>
            <select data-f="batch_id"><option value="">همه‌ی ورودها</option></select>
            <select data-f="missing"><option value="">هر کمبودی</option><option value="info">اطلاعاتِ ناقص</option><option value="car_card_or_title">کارت ماشین یا سند</option>
                <option value="prev_body_policy">بیمه بدنه قبل</option><option value="health_inspection">بازدید سلامت</option><option value="health_report">گزارش بازدید</option></select>
            <select data-f="inspection"><option value="">بازدید: همه</option><option value="need">بدنه‌ی نیازمندِ بازدید</option><option value="skip">بدنه‌ی بدونِ بازدید</option></select>
        </div>
        <div class="card overflow-hidden mt-3"><div class="overflow-x-auto max-h-[64vh]" data-tw></div></div>
        <div data-bulk></div>`;
        S.root.querySelector('[data-a="import"]').onclick = openImport;
        S.root.querySelector('[data-a="bundle"]').onclick = () => { if (typeof openBundleModal === 'function') openBundleModal(0, 'import'); else toast('صدورِ گروهی در دسترس نیست.', 'error'); };
        S.root.querySelector('[data-a="bulkdocs"]').onclick = () => openUploader(null);
        S.root.querySelector('[data-a="export"]').onclick = () => { const q = new URLSearchParams(Object.assign({action: 'export'}, S.f)); window.open(API + '?' + q.toString(), '_blank'); };
        S.root.querySelector('[data-a="reload"]').onclick = () => reload();
        let t = null;
        S.root.querySelectorAll('[data-f]').forEach(el => {
            const ev = el.tagName === 'INPUT' ? 'input' : 'change';
            el.addEventListener(ev, () => { S.f[el.dataset.f] = el.value; clearTimeout(t); t = setTimeout(load, el.tagName === 'INPUT' ? 350 : 0); });
        });
    }

    async function loadMeta() {
        const d = await api('meta');
        if (!d.ok) return;
        S.meta = d;
        const fill = (k, opts, first) => { const el = S.root.querySelector(`[data-f="${k}"]`); if (!el) return; el.innerHTML = `<option value="">${first}</option>` + opts; el.value = S.f[k] || ''; };
        fill('company_id', d.insured.map(c => `<option value="${c.id}">${esc(c.name)} (${fa(c.n)})</option>`).join(''), 'همه‌ی بیمه‌گذارها');
        fill('month', d.months.map(m => `<option value="${m.month}">${fa(m.label)}</option>`).join(''), 'همه‌ی ماه‌ها (صدور)');
        fill('batch_id', d.batches.map(b => `<option value="${b.id}">ورودِ ${fa(b.created_jalali)} · ${esc(b.file_name || '')} (${fa(b.n)})</option>`).join(''), 'همه‌ی ورودها');
    }

    async function load() {
        const tw = S.root.querySelector('[data-tw]');
        if (!S.rows.length) tw.innerHTML = '<p class="p-10 text-center text-slate-400 text-xs"><i class="fas fa-spinner fa-spin ml-1"></i>در حال بارگذاری…</p>';
        const d = await api('list', S.f);
        if (!d.ok) { tw.innerHTML = `<p class="p-10 text-center text-rose-600 text-xs">${esc(d.error || 'خطا')}</p>`; return; }
        S.rows = d.rows; S.counts = d.counts;
        const ids = new Set(S.rows.map(r => r.id));
        [...S.sel].forEach(id => { if (!ids.has(id)) S.sel.delete(id); });
        render();
        if (S.open) { const r = S.rows.find(x => x.id === S.open); if (r) renderRow(r); }
    }
    async function reload() { await loadMeta(); await load(); }

    function render() {
        const c = S.counts || {};
        const stat = (k, label, n, col) => `<div class="ia-stat ${S.f.status === k ? 'on' : ''}" style="--c:${col}" data-st="${k}"><p>${label}</p><b>${fa(n || 0)}</b></div>`;
        S.root.querySelector('[data-stats]').innerHTML =
            stat('OPEN', 'صادرنشده (همه)', (c.pending || 0) + (c.ready || 0), '#0f766e') + stat('PENDING', 'منتظرِ مدارک / اطلاعات', c.pending, '#d97706')
            + stat('READY', 'آماده‌ی صدور', c.ready, '#059669') + stat('ISSUED', 'صادر و بایگانی‌شده', c.issued, '#0284c7')
            + `<div class="ia-stat" style="--c:#475569;cursor:default"><p>بدنه / ثالث</p><b>${fa(c.body || 0)} / ${fa(c.third || 0)}</b></div>`
            + `<div class="ia-stat" style="--c:#7c3aed;cursor:default"><p>بدنه‌ی بدونِ بازدید</p><b>${fa(c.no_inspection || 0)}</b></div>`
            + stat('', 'همه‌ی ردیف‌ها', c.total, '#334155');
        S.root.querySelectorAll('[data-st]').forEach(el => el.onclick = () => { S.f.status = el.dataset.st; load(); });
        const tw = S.root.querySelector('[data-tw]');
        if (!S.rows.length) {
            tw.innerHTML = `<div class="p-12 text-center"><i class="fas fa-box-open text-4xl text-slate-200"></i><p class="text-sm font-bold text-slate-500 mt-3">ردیفی نیست.</p>
                <p class="text-xs text-slate-400 mt-1">از «ورودِ اکسلِ بیمه‌گر» شروع کنید${S.f.status ? ' یا فیلترها را عوض کنید' : ''}.</p></div>`;
            renderBulk(); return;
        }
        let h = `<table class="ia-tbl no-count"><thead><tr><th style="width:30px"><input type="checkbox" data-all></th><th>پلاک / شاسی</th><th>نوع</th><th>خودرو</th><th>شماره بیمه‌نامه</th>
                 <th>تاریخ صدور</th><th>حق بیمه</th><th>مدارک</th><th>وضعیت</th><th></th></tr></thead><tbody>`;
        let lastG = null;
        const gc = {};
        S.rows.forEach(r => { const g = r.company_id + '|' + r.month; gc[g] = (gc[g] || 0) + 1; });
        S.rows.forEach(r => {
            const g = r.company_id + '|' + r.month;
            if (g !== lastG) {
                lastG = g;
                const cnt = gc[g];
                h += `<tr class="ia-g"><td colspan="10"><i class="fas fa-folder-open text-teal-600 ml-1"></i>${esc(r.insured_name)}
                    <span class="text-slate-400 font-bold text-[10.5px] mr-2">${fa(r.month_fa)} · ${fa(cnt)} ردیف${r.insured_id ? ' · شناسه ' + fa(r.insured_id) : ''}</span></td></tr>`;
            }
            const docs = r.checklist.map(it => `<span class="ia-d ${it.satisfied ? 'ok' : (it.required ? 'no' : 'op')}" title="${esc(it.hint || '')}">${it.satisfied ? '✓' : (it.required ? '✗' : '○')} ${esc(it.label)}</span>`).join('')
                + Object.values(r.missing_info || {}).map(l => `<span class="ia-d no">✗ ${esc(l)}</span>`).join('')
                + (r.insurance_type === 'BODY' && r.skip_health_inspection ? '<span class="ia-d op">بدونِ بازدید</span>' : '');
            const issued = r.state === 'ISSUED';
            h += `<tr class="ia-r ${S.sel.has(r.id) ? 'sel' : ''}" data-id="${r.id}">
                <td>${issued ? '' : `<input type="checkbox" data-sel="${r.id}" ${S.sel.has(r.id) ? 'checked' : ''}>`}</td>
                <td>${plateHtml(r)}</td>
                <td><span class="ia-chip ${r.insurance_type === 'BODY' ? 'bg-cyan-50 text-cyan-700' : 'bg-violet-50 text-violet-700'}">${esc(r.insurance_type_fa)}</span></td>
                <td class="text-[11px] max-w-[160px] truncate" title="${esc(r.car_name || '')}">${esc(r.car_name || '—')}</td>
                <td dir="ltr" class="text-[10.5px] font-bold">${fa(r.import_policy_number || '—')}</td>
                <td>${fa(r.import_issue_date || '—')}</td>
                <td class="text-[11px]">${money(r.pf.premium || '') || '—'}</td>
                <td><div class="ia-docs">${docs}</div></td>
                <td><span class="ia-chip ia-st-${r.state}">${esc(r.status_fa)}</span></td>
                <td class="whitespace-nowrap">
                    <button class="ia-btn i" style="padding:6px 10px;font-size:11px" data-open="${r.id}"><i class="fas fa-folder-open"></i>${issued ? 'مشاهده' : 'مدارک و اطلاعات'}</button>
                    ${issued ? (r.issued_file_path ? `<a class="ia-btn s" style="padding:6px 10px;font-size:11px" href="${fpath(r.issued_file_path)}" target="_blank"><i class="fas fa-file-pdf"></i>بیمه‌نامه</a>` : '')
                        : `<button class="ia-btn p" style="padding:6px 10px;font-size:11px" data-issue="${r.id}" ${r.state === 'READY' ? '' : 'title="مدارک یا اطلاعات کامل نیست"'}><i class="fas fa-stamp"></i>صدور</button>`}
                </td></tr>`;
        });
        tw.innerHTML = h + '</tbody></table>';
        tw.querySelector('[data-all]').onchange = e => { S.rows.filter(r => r.state !== 'ISSUED').forEach(r => e.target.checked ? S.sel.add(r.id) : S.sel.delete(r.id)); render(); };
        tw.querySelectorAll('[data-sel]').forEach(el => el.onchange = () => { const id = +el.dataset.sel; el.checked ? S.sel.add(id) : S.sel.delete(id); el.closest('tr').classList.toggle('sel', el.checked); renderBulk(); });
        tw.querySelectorAll('[data-open]').forEach(el => el.onclick = () => openRow(+el.dataset.open));
        tw.querySelectorAll('[data-issue]').forEach(el => el.onclick = () => issueRow(+el.dataset.issue));
        renderBulk();
    }

    function renderBulk() {
        const box = S.root.querySelector('[data-bulk]');
        if (!S.sel.size) { box.innerHTML = ''; return; }
        const rows = S.rows.filter(r => S.sel.has(r.id));
        const body = rows.filter(r => r.insurance_type === 'BODY').length;
        box.innerHTML = `<div class="ia-bulk mt-3"><span class="text-xs font-black"><i class="fas fa-check-double ml-1 text-teal-300"></i>${fa(S.sel.size)} ردیف انتخاب شد${body ? ` (${fa(body)} بدنه)` : ''}</span>
            <span class="flex flex-wrap gap-2">
                ${body ? `<button class="ia-btn w" data-b="skip1"><i class="fas fa-eye-slash"></i>بدنه‌ها: بدونِ نیاز به بازدید</button><button class="ia-btn g" data-b="skip0"><i class="fas fa-eye"></i>بدنه‌ها: نیاز به بازدید</button>` : ''}
                <button class="ia-btn g" data-b="clear">لغوِ انتخاب</button>
                <button class="ia-btn d" data-b="del"><i class="fas fa-trash"></i>حذفِ ردیف‌ها</button></span></div>`;
        const ids = [...S.sel];
        box.querySelector('[data-b="clear"]').onclick = () => { S.sel.clear(); render(); };
        box.querySelectorAll('[data-b^="skip"]').forEach(b => b.onclick = async () => {
            const d = await api('bulk_skip', {ids, on: b.dataset.b === 'skip1' ? 1 : 0});
            if (!d.ok) return toast(d.error || 'خطا', 'error');
            toast(b.dataset.b === 'skip1' ? 'بدنه‌های انتخاب‌شده بدونِ بازدید صادر می‌شوند.' : 'بدنه‌های انتخاب‌شده بازدید لازم دارند.', 'success'); load();
        });
        box.querySelector('[data-b="del"]').onclick = async () => {
            if (!(await confirmBox('حذفِ ردیف‌ها', `${fa(ids.length)} ردیفِ صادرنشده و مدارکِ بارگذاری‌شده‌شان حذف شوند؟ (ردیفِ صادرشده حذف نمی‌شود)`, {ok: 'حذف', danger: true}))) return;
            const d = await api('delete_rows', {ids});
            if (!d.ok) return toast(d.error || 'خطا', 'error');
            S.sel.clear(); toast(`${fa(d.deleted)} ردیف حذف شد${d.skipped ? ` (${fa(d.skipped)} ردیفِ صادرشده ماند)` : ''}.`, 'success'); reload();
        };
    }

    // ------------------------------------------------------------------ ورودِ اکسل
    function overlay(html, cls, onClose) {
        const ov = document.createElement('div'); ov.className = 'ia-ov';
        ov.innerHTML = `<div class="ia-pn ${cls || ''}">${html}</div>`;
        document.body.appendChild(ov);
        const close = () => { if (!ov.isConnected) return; ov.remove(); document.removeEventListener('keydown', onKey); if (onClose) onClose(); };
        const onKey = e => { if (e.key === 'Escape' && !document.querySelector('.modal-overlay.active,.vr-overlay')) close(); };
        document.addEventListener('keydown', onKey);
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        ov.querySelectorAll('[data-x]').forEach(b => b.onclick = close);
        return {ov, close, q: s => ov.querySelector(s)};
    }

    function openImport() {
        const m = overlay(`<div class="ia-ph"><h3><i class="fas fa-file-excel text-emerald-600 ml-1"></i>ورودِ اکسلِ خروجیِ بیمه‌گر</h3><button class="ia-x" data-x>✕</button></div>
            <div class="ia-pb" data-body>
                <label class="ia-drop block" data-drop><input type="file" accept=".xlsx,.xls,.csv" hidden data-file>
                    <i class="fas fa-cloud-arrow-up text-3xl block mb-2"></i>فایلِ اکسلِ بیمه‌نامه‌های صادره (بدنه یا ثالث) را اینجا رها کنید یا بزنید
                    <small class="block text-[10.5px] font-bold text-teal-700/70 mt-1">همان خروجیِ سایتِ پاسارگاد؛ چند فایل را می‌شود یکی‌یکی وارد کرد. چیزی تا «ثبت» ذخیره نمی‌شود.</small></label>
                <div data-res class="mt-3"></div>
            </div>`);
        const inp = m.q('[data-file]'), drop = m.q('[data-drop]');
        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
        drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) go(e.dataTransfer.files[0]); });
        inp.onchange = () => inp.files[0] && go(inp.files[0]);
        let P = null;
        async function go(file) {
            const res = m.q('[data-res]');
            res.innerHTML = '<p class="text-center text-xs text-slate-500 py-6"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ خواندنِ فایل…</p>';
            const fd = new FormData(); fd.append('action', 'preview'); fd.append('file', file);
            let d;
            try { d = await (await fetch(API, {method: 'POST', body: fd})).json(); } catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
            if (!d.ok) { res.innerHTML = `<p class="text-xs text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-3"><i class="fas fa-circle-exclamation ml-1"></i>${esc(d.error || 'خطا')}</p>`; return; }
            P = d; P.groupBy = 'insured'; P.skip = {}; P.pick = new Set(d.rows.filter(r => r.ok).map(r => r.line));
            drawPreview();
        }
        function drawPreview() {
            const res = m.q('[data-res]'), rows = P.rows;
            const ok = rows.filter(r => r.ok), bad = rows.filter(r => !r.ok);
            const owner = r => P.groupBy === 'contract' && r.contract_party ? r.contract_party : r.insured;
            const groups = {};
            rows.filter(r => r.ok && P.pick.has(r.line)).forEach(r => { const k = owner(r) + '|' + r.month_fa; (groups[k] = groups[k] || {owner: owner(r), month: r.month_fa, body: 0, third: 0}); r.type === 'BODY' ? groups[k].body++ : groups[k].third++; });
            const tree = Object.values(groups).map(g => `<div>بایگانی وارداتی / ${fa(g.month.split(' ')[1] || '')} / ${esc(g.month.split(' ')[0] || '')} / <b>${esc(g.owner)}</b> / درخواست (امروز) / ${g.body ? `بدنه (${fa(g.body)})` : ''}${g.body && g.third ? ' و ' : ''}${g.third ? `ثالث (${fa(g.third)})` : ''}</div>`).join('');
            const bodyOk = ok.filter(r => r.type === 'BODY');
            res.innerHTML = `
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-3">
                    <div class="ia-stat" style="--c:#334155;cursor:default"><p>ردیف‌های فایل</p><b>${fa(rows.length)}</b></div>
                    <div class="ia-stat" style="--c:#059669;cursor:default"><p>قابلِ ثبت</p><b>${fa(ok.length)}</b></div>
                    <div class="ia-stat" style="--c:#be123c;cursor:default"><p>تکراری / ناقص</p><b>${fa(bad.length)}</b></div>
                    <div class="ia-stat" style="--c:#0e7490;cursor:default"><p>انتخاب‌شده برای ثبت</p><b data-pc>${fa(P.pick.size)}</b></div>
                </div>
                <div class="flex flex-wrap items-center gap-3 mb-2 text-xs font-bold">
                    <span class="text-slate-500">پوشه‌ی هر گروه بر اساسِ:</span>
                    <label class="ia-tog"><input type="radio" name="iagb" value="insured" ${P.groupBy === 'insured' ? 'checked' : ''}>بیمه‌گذار</label>
                    <label class="ia-tog"><input type="radio" name="iagb" value="contract" ${P.groupBy === 'contract' ? 'checked' : ''}>طرفِ قرارداد</label>
                    ${bodyOk.length ? `<span class="mr-auto flex gap-2"><button class="ia-btn s" style="padding:5px 10px;font-size:11px" data-sk="1">همه‌ی بدنه‌ها بدونِ بازدید</button><button class="ia-btn s" style="padding:5px 10px;font-size:11px" data-sk="0">همه‌ی بدنه‌ها با بازدید</button></span>` : ''}
                </div>
                <div class="ia-tree mb-3">${tree || '<span class="text-slate-500">ردیفی انتخاب نشده</span>'}</div>
                <div class="overflow-auto max-h-[46vh] border border-slate-100 rounded-2xl"><table class="ia-pv w-full no-count"><thead><tr>
                    <th><input type="checkbox" data-pall ${P.pick.size === ok.length && ok.length ? 'checked' : ''}></th><th>ردیف</th><th>نوع</th><th>پلاک / شاسی</th><th>بیمه‌گذار</th><th>شماره بیمه‌نامه</th><th>صدور</th><th>حق بیمه</th><th>بیمه‌ی قبلی</th><th>بازدید لازم نیست</th><th>توضیح</th></tr></thead><tbody>
                    ${rows.map(r => `<tr class="${r.ok ? '' : 'bad'}">
                        <td>${r.ok ? `<input type="checkbox" data-pk="${r.line}" ${P.pick.has(r.line) ? 'checked' : ''}>` : '<i class="fas fa-ban"></i>'}</td>
                        <td>${fa(r.line)}</td><td>${esc(r.type_fa || '—')}</td>
                        <td>${r.plate ? `<span class="plate-display">${typeof fmtPlateHtml === 'function' ? fmtPlateHtml({plate_p1: r.plate.p1, plate_p2: r.plate.p2, plate_letter: r.plate.letter, plate_p4: r.plate.p4}) : esc(r.pf.plate)}</span>` : `<b dir="ltr">${esc(r.vin)}</b>`}</td>
                        <td class="max-w-[200px] truncate" title="${esc(r.insured)}">${esc(owner(r))}</td>
                        <td dir="ltr">${fa(r.policy)}</td><td>${fa(r.issue)}</td><td>${money(r.premium)}</td>
                        <td class="text-[10px]">${r.type === 'BODY' ? (r.has_prev_body === 'YES' ? '<span class="text-amber-700 font-bold">دارد (مدرکش لازم است)</span>' : 'ندارد') : (r.pf.prev_insurer ? esc(r.pf.prev_insurer) : '—')}</td>
                        <td>${r.ok && r.type === 'BODY' ? `<input type="checkbox" data-skp="${r.line}" ${P.skip[r.line] ? 'checked' : ''}>` : ''}</td>
                        <td class="text-[10px]">${r.errors.map(esc).join('<br>')}${r.warnings.length ? `<span class="text-amber-700">${r.warnings.map(esc).join('<br>')}</span>` : ''}</td></tr>`).join('')}
                </tbody></table></div>
                <div class="flex flex-wrap gap-2 mt-3">
                    <button class="ia-btn p" data-commit ${P.pick.size ? '' : 'disabled'}><i class="fas fa-check"></i>ثبتِ ${fa(P.pick.size)} ردیف و ساختِ پوشه‌ها</button>
                    <button class="ia-btn s" data-x2>انصراف</button></div>`;
            res.querySelectorAll('input[name="iagb"]').forEach(x => x.onchange = () => { P.groupBy = x.value; drawPreview(); });
            res.querySelectorAll('[data-sk]').forEach(b => b.onclick = () => { bodyOk.forEach(r => { if (b.dataset.sk === '1') P.skip[r.line] = 1; else delete P.skip[r.line]; }); drawPreview(); });
            res.querySelectorAll('[data-skp]').forEach(x => x.onchange = () => { if (x.checked) P.skip[x.dataset.skp] = 1; else delete P.skip[x.dataset.skp]; });
            res.querySelectorAll('[data-pk]').forEach(x => x.onchange = () => { x.checked ? P.pick.add(+x.dataset.pk) : P.pick.delete(+x.dataset.pk); drawPreview(); });
            const pall = res.querySelector('[data-pall]'); if (pall) pall.onchange = () => { ok.forEach(r => pall.checked ? P.pick.add(r.line) : P.pick.delete(r.line)); drawPreview(); };
            res.querySelector('[data-x2]').onclick = m.close;
            res.querySelector('[data-commit]').onclick = async e => {
                e.target.disabled = true; e.target.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ ثبت و ساختِ پوشه‌ها…';
                const d = await api('commit', {token: P.token, group_by: P.groupBy, skip: P.skip, lines: [...P.pick]});
                if (!d.ok) { toast(d.error || 'خطا', 'error'); drawPreview(); return; }
                toast(`${fa(d.created)} ردیف در ${fa(d.requests)} پوشه‌ی درخواست ثبت شد.`, 'success');
                m.close(); S.f = {status: 'OPEN', batch_id: String(d.batch_id)}; syncFilters(); reload();
            };
        }
    }
    function syncFilters() { S.root.querySelectorAll('[data-f]').forEach(el => { el.value = S.f[el.dataset.f] || ''; }); }

    // ------------------------------------------------------------------ پنلِ ردیف
    function openRow(id) {
        const r = S.rows.find(x => x.id === id);
        if (!r) { S.f = {status: '', q: ''}; syncFilters(); load().then(() => { const x = S.rows.find(y => y.id === id); if (x) openRow(id); }); return; }
        if (S.modal) S.modal.close();
        S.open = id;
        const m = overlay('<div data-rowbox></div>', '', () => { if (S.modal === m) { S.open = null; S.modal = null; } });
        S.modal = m;
        renderRow(r);
    }

    const DOC_OPTS = ['car_card_front', 'car_card_back', 'ownership_doc', 'sales_invoice', 'prev_body_policy', 'prev_third_policy', 'health_inspection', 'health_report', 'other'];
    const docLabel = k => (S.meta && S.meta.doc_types && S.meta.doc_types[k]) || k;

    function renderRow(r) {
        if (!S.modal) return;
        const box = S.modal.ov.querySelector('[data-rowbox]');
        const issued = r.state === 'ISSUED', body = r.insurance_type === 'BODY', pf = r.pf || {};
        const miss = Object.values(r.missing_docs || {}).concat(Object.values(r.missing_info || {}));
        const ck = r.checklist.map(it => `<div class="ia-ci ${it.satisfied ? 'ok' : (it.required ? 'miss' : '')}">
                <b>${it.satisfied ? '<i class="fas fa-circle-check text-emerald-600"></i>' : (it.required ? '<i class="fas fa-circle-xmark text-rose-500"></i>' : '<i class="far fa-circle text-slate-400"></i>')} ${esc(it.label)}</b>
                <small>${esc(it.hint || '')}${it.required ? '' : ' (اختیاری)'}</small>
                <div class="ia-dl">${(it.docs || []).map(d => (d.is_dir ? `<span title="${esc(d.file_path)}"><i class="fas fa-folder ml-1"></i>${esc(d.label)}</span>`
                    : `<a href="${fpath(d.file_path)}" target="_blank"><i class="fas fa-file ml-1"></i>${esc(d.label)}</a>`) + (issued ? '' : ` <button data-deldoc="${d.id}" class="text-rose-500 text-[10px]" title="حذف"><i class="fas fa-xmark"></i></button>`)).join('')}</div>
            </div>`).join('');
        const kv = [['insured_name', 'بیمه‌گذار'], ['national_id', 'کد / شناسه ملی'], ['phone', 'تلفن'], ['contract_party', 'طرفِ قرارداد'], ['start_date', 'شروع'], ['end_date', 'پایان'],
            ['central_no', 'کد یکتای بیمه مرکزی'], ['car_kind', 'نوع وسیله'], ['car_system', 'سیستم'], ['car_tip', 'تیپ'], ['model_year', 'سال ساخت'], ['color', 'رنگ'], ['usage', 'کاربری'],
            ['capacity', 'ظرفیت'], ['cylinders', 'سیلندر'], ['cargo', 'اتاق بار'], ['car_value', 'ارزش خودرو'], ['total_value', 'سرمایه‌ی کل'], ['trailer_value', 'ارزش یدک'],
            ['liability', 'تعهد مالی'], ['diya', 'تعهد جانی'], ['driver_cover', 'تعهد سرنشین'], ['prev_insurer', 'بیمه‌گرِ قبلی'], ['prev_policy', 'بیمه‌نامه‌ی قبلی'],
            ['prev_expiry', 'انقضای قبلی'], ['prev_claims', 'خسارتِ قبلی'], ['no_claim_years', 'سال‌های عدمِ خسارت'], ['covers', 'پوشش‌های اضافی'], ['address', 'نشانی']]
            .filter(([k]) => pf[k]).map(([k, l]) => `<div ${k === 'covers' || k === 'address' ? 'style="grid-column:1/-1"' : ''}><span>${l}</span>${/value|liability|diya|driver_cover/.test(k) ? money(pf[k]) + ' ریال' : esc(fa(pf[k]))}</div>`).join('');
        const v = k => esc(en(r[k] ?? ''));
        box.innerHTML = `
            <div class="ia-ph"><div class="flex flex-wrap items-center gap-2">${plateHtml(r)}
                <span class="ia-chip ${body ? 'bg-cyan-50 text-cyan-700' : 'bg-violet-50 text-violet-700'}">${esc(r.insurance_type_fa)}</span>
                <span class="ia-chip ia-st-${r.state}">${esc(r.status_fa)}</span>
                <span class="text-[11px] text-slate-500 font-bold">${esc(r.insured_name)} · ${fa(r.month_fa)}</span></div>
                <button class="ia-x" data-x>✕</button></div>
            <div class="ia-pb">
                ${miss.length && !issued ? `<div class="text-[11.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-2 mb-3"><i class="fas fa-list-check ml-1"></i>برای صدور کم است: <b>${miss.map(esc).join('، ')}</b></div>` : ''}
                ${issued ? `<div class="text-[11.5px] text-sky-900 bg-sky-50 border border-sky-200 rounded-2xl px-4 py-3 mb-3"><i class="fas fa-circle-check ml-1"></i>صادر و بایگانی شد · شماره <b dir="ltr">${fa(r.policy_number || '')}</b>
                    ${r.issued_file_path ? ` · <a class="underline font-bold" href="${fpath(r.issued_file_path)}" target="_blank">فایلِ بیمه‌نامه</a>` : ''}${r.folder_status === 'TRANSFERRED' ? ' · کپی در «بایگانی صادره» انجام شد' : ''}</div>` : ''}
                <div class="ia-sec"><h4><i class="fas fa-list-check text-teal-600"></i>چک‌لیستِ مدارک</h4><div class="ia-ck">${ck}</div>
                    ${body && !issued ? `<label class="ia-tog mt-3"><input type="checkbox" data-skip ${r.skip_health_inspection ? 'checked' : ''}>این بدنه نیاز به بازدید ندارد (بدونِ بازدید و گزارش صادر شود)</label>` : ''}</div>
                ${issued ? '' : `<div class="ia-sec"><h4><i class="fas fa-cloud-arrow-up text-teal-600"></i>بارگذاریِ مدارک (چند فایل با هم)</h4><div data-up></div></div>`}
                <div class="ia-sec"><h4><i class="fas fa-pen-to-square text-teal-600"></i>اطلاعاتِ ردیف ${issued ? '' : '<small class="text-[10px] text-slate-400 font-bold">(از اکسل آمده؛ هر چه کم یا اشتباه است اصلاح کنید)</small>'}</h4>
                    <div class="ia-form" data-form>
                        <div><label>نوع بیمه</label><select data-k="insurance_type"><option value="BODY" ${body ? 'selected' : ''}>بدنه</option><option value="THIRDPARTY" ${!body ? 'selected' : ''}>ثالث</option></select></div>
                        <div><label>پلاک: سه رقم</label><input data-k="plate_p2" dir="ltr" maxlength="3" value="${v('plate_p2')}"></div>
                        <div><label>پلاک: حرف</label><input data-k="plate_letter" maxlength="4" value="${esc(r.plate_letter || '')}"></div>
                        <div><label>پلاک: دو رقم</label><input data-k="plate_p4" dir="ltr" maxlength="2" value="${v('plate_p4')}"></div>
                        <div><label>پلاک: کد ایران</label><input data-k="plate_p1" dir="ltr" maxlength="2" value="${v('plate_p1')}"></div>
                        <div><label>شماره شاسی (VIN)</label><input data-k="chassis_no" dir="ltr" value="${esc(r.chassis_no || '')}"></div>
                        <div><label>شماره موتور</label><input data-k="engine_no" dir="ltr" value="${esc(r.engine_no || '')}"></div>
                        <div><label>خودرو</label><input data-k="car_name" value="${esc(r.car_name || '')}"></div>
                        <div><label>شماره بیمه‌نامه *</label><input data-k="import_policy_number" dir="ltr" value="${esc(r.import_policy_number || '')}"></div>
                        <div><label>تاریخ صدور *</label><input data-k="import_issue_date" dir="ltr" placeholder="۱۴۰۵/۰۷/۱۳" value="${esc(r.import_issue_date || '')}"></div>
                        <div><label>حق بیمه (ریال)</label><input data-k="premium" dir="ltr" class="money-input" value="${esc(pf.premium || '')}"></div>
                        <div><label>${body ? 'ارزش خودرو (ریال)' : 'تعهد مالی (ریال)'}</label><input data-k="${body ? 'car_value' : 'liability_limit'}" dir="ltr" class="money-input" value="${esc((body ? r.car_value : r.liability_limit) || '')}"></div>
                        <div><label>انقضای بیمه‌نامه‌ی قبلی</label><input data-k="expiry" dir="ltr" placeholder="۱۴۰۵/۰۷/۱۵" value="${esc(pf.prev_expiry || '')}"></div>
                        ${body ? `<div><label>بیمه بدنه‌ی قبلی</label><select data-k="has_prev_body"><option value="YES" ${r.has_prev_body === 'YES' ? 'selected' : ''}>دارد (مدرکش لازم است)</option><option value="NO" ${r.has_prev_body !== 'YES' ? 'selected' : ''}>ندارد</option></select></div>` : ''}
                        <div><label>بیمه‌گذار</label><input data-k="insured_name" value="${esc(pf.insured_name || '')}"></div>
                        <div><label>کد / شناسه ملی</label><input data-k="national_id" dir="ltr" value="${esc(pf.national_id || '')}"></div>
                        <div><label>تلفن</label><input data-k="phone" dir="ltr" value="${esc(pf.phone || '')}"></div>
                        <div><label>نام مالک</label><input data-k="owner_name" value="${esc(pf.owner_name || '')}"></div>
                        <div><label>بیمه‌گرِ قبلی</label><input data-k="prev_insurer" value="${esc(pf.prev_insurer || '')}"></div>
                        <div><label>شماره بیمه‌نامه‌ی قبلی</label><input data-k="prev_policy" dir="ltr" value="${esc(pf.prev_policy || '')}"></div>
                        <div><label>سال ساخت</label><input data-k="model_year" dir="ltr" value="${esc(pf.model_year || '')}"></div>
                        <div><label>رنگ</label><input data-k="color" value="${esc(pf.color || '')}"></div>
                        <div><label>کاربری</label><input data-k="usage" value="${esc(pf.usage || '')}"></div>
                        <div class="w"><label>نشانی</label><input data-k="address" value="${esc(pf.address || '')}"></div>
                    </div>
                    ${kv ? `<details class="mt-3"><summary class="cursor-pointer text-[11px] font-black text-teal-700">همه‌ی اطلاعاتِ اکسلِ بیمه‌گر برای این بیمه‌نامه</summary><div class="ia-kv mt-2">${kv}</div></details>` : ''}
                </div>
            </div>
            ${issued ? '' : `<div class="ia-pf">
                <button class="ia-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره‌ی اطلاعات</button>
                ${body && !r.skip_health_inspection && window.VR && VR.openTargetBuilder ? '<button class="ia-btn i" data-vr><i class="fas fa-clipboard-check"></i>ساختِ گزارش بازدید</button>' : ''}
                <button class="ia-btn" style="background:linear-gradient(120deg,#0284c7,#4f46e5);color:#fff" data-iss><i class="fas fa-stamp"></i>گذاشتنِ بیمه‌نامه و صدور</button>
                <button class="ia-btn s" data-x style="margin-right:auto">بستن</button></div>`}`;
        box.querySelectorAll('[data-x]').forEach(b => b.onclick = () => S.modal && S.modal.close());
        if (window.MoneyInput && MoneyInput.apply) try { MoneyInput.apply(box); } catch (e) {}
        box.querySelectorAll('[data-deldoc]').forEach(b => b.onclick = async () => {
            if (!(await confirmBox('حذفِ مدرک', 'این فایل از پوشه‌ی ردیف حذف شود؟', {ok: 'حذف', danger: true}))) return;
            const d = await api('delete_row_doc', {doc_id: +b.dataset.deldoc}, CAPI);
            if (!d.ok) return toast(d.error || 'خطا', 'error');
            toast('مدرک حذف شد.', 'success'); load();
        });
        const sk = box.querySelector('[data-skip]');
        if (sk) sk.onchange = async () => { const d = await api('bulk_skip', {ids: [r.id], on: sk.checked ? 1 : 0}); if (!d.ok) toast(d.error || 'خطا', 'error'); load(); };
        const up = box.querySelector('[data-up]');
        if (up) mountUploader(up, r);
        const save = box.querySelector('[data-save]');
        if (save) save.onclick = () => saveRow(r, box);
        const vr = box.querySelector('[data-vr]');
        if (vr) vr.onclick = () => VR.openTargetBuilder({type: 'company', id: r.id}, () => load());
        const iss = box.querySelector('[data-iss]');
        if (iss) iss.onclick = () => issueRow(r.id);
    }

    async function saveRow(r, box) {
        const body = {id: r.id};
        box.querySelectorAll('[data-form] [data-k]').forEach(el => { body[el.dataset.k] = en(el.value).trim(); });
        if (r.insurance_type === 'BODY') body.skip_health_inspection = r.skip_health_inspection ? 1 : 0;
        const d = await api('update_row', body);
        if (!d.ok) return toast(d.error || 'خطا', 'error');
        toast('اطلاعات ذخیره شد' + (d.status === 'READY_FOR_ISSUE' ? ' · ردیف آماده‌ی صدور است.' : '.'), 'success');
        load();
    }

    async function issueRow(id) {
        const r = S.rows.find(x => x.id === id);
        if (!r) return;
        if (r.state !== 'READY') {
            const miss = Object.values(r.missing_docs || {}).concat(Object.values(r.missing_info || {}));
            if (!(await confirmBox('مدارک کامل نیست', `این ردیف هنوز کم دارد: ${miss.join('، ')}. باز هم صادر و بایگانی شود؟`, {ok: 'بله، صادر شود'}))) return;
        }
        if (typeof openMarkIssued !== 'function') return toast('پنجره‌ی صدور در دسترس نیست.', 'error');
        const label = `ردیفِ وارداتی: ${plateHtml(r)} | ${esc(r.insurance_type_fa)} | ${esc(r.insured_name)}`;
        const pf = Object.assign({}, r.pf, {policy_num: r.import_policy_number || r.pf.policy_num || '', issue_date: r.import_issue_date || r.pf.issue_date || '',
            vin: r.chassis_no || r.pf.vin || '', engine_no: r.engine_no || r.pf.engine_no || '', plate: r.pf.plate || ''});
        openMarkIssued(id, {pf, label, onDone: data => {
            toast(`صادر و بایگانی شد${data.folder_status === 'TRANSFERRED' ? ' · کپی در «بایگانی صادره» (ماهِ صدور) انجام شد' : ''}.`, 'success');
            load();
        }});
    }

    // ------------------------------------------------------------------ بارگذاریِ چندفایلی
    // row: ردیفِ مشخص (پنلِ ردیف) یا null (بارگذاریِ گروهی: ردیفِ هر فایل از نامش حدس زده می‌شود)
    function guessType(name, row, taken) {
        const n = en(name).toLowerCase();
        const ext = n.split('.').pop();
        if (/فاکتور|invoice|factor/.test(n)) return 'sales_invoice';
        if (/پشت|back|posht/.test(n)) return 'car_card_back';
        if (/سند|ownership|sanad/.test(n)) return 'ownership_doc';
        if (/(بدنه|body).*(قبل|prev|old)|(قبل|prev|old).*(بدنه|body)/.test(n)) return 'prev_body_policy';
        if (/(ثالث|third).*(قبل|prev|old)|(قبل|prev|old).*(ثالث|third)/.test(n)) return 'prev_third_policy';
        if (/گزارش|report|gozaresh/.test(n)) return 'health_report';
        if (/بازدید|inspect|visit|bazdid/.test(n) || ext === 'zip') return 'health_inspection';
        if (/(^|[^a-z])(رو|front|roo)([^a-z]|$)|کارت|card/.test(n)) return taken.has('car_card_front') ? 'car_card_back' : 'car_card_front';
        // بی‌نام: اولین کمبود
        const missing = row ? row.checklist.filter(it => it.required && !it.satisfied).flatMap(it => it.upload_types) : [];
        const isImg = /^(jpe?g|png|webp|heic|bmp|gif)$/.test(ext);
        for (const t of (isImg ? ['car_card_front', 'car_card_back', 'ownership_doc', 'health_inspection'] : ['prev_body_policy', 'ownership_doc', 'health_report', 'prev_third_policy']))
            if (missing.includes(t) && !taken.has(t)) return t;
        return isImg ? (taken.has('car_card_front') ? (taken.has('car_card_back') ? 'other' : 'car_card_back') : 'car_card_front') : 'other';
    }
    function guessRow(name) {
        const n = en(name).toUpperCase().replace(/[\s\-_.()]/g, '');
        let best = null;
        for (const r of S.rows) {
            if (r.state === 'ISSUED') continue;
            const vin = String(r.chassis_no || '').toUpperCase();
            if (vin.length >= 6 && n.includes(vin.slice(-6))) return r;
            if (r.plate_p2 && r.plate_p4 && n.includes(String(r.plate_p2)) && n.includes(String(r.plate_p4))) {
                if (n.includes(r.plate_p2 + (r.plate_letter || '') + r.plate_p4) || (r.plate_p1 && n.includes(String(r.plate_p1)))) return r;
                best = best || r;
            }
        }
        return best;
    }
    function rowName(r) { return r ? (r.plate_p2 ? `${r.plate_p1}ایران - ${r.plate_p2} ${r.plate_letter} ${r.plate_p4}` : (r.chassis_no || 'بدون‌پلاک')) : ''; }

    function openUploader() {
        const m = overlay(`<div class="ia-ph"><h3><i class="fas fa-images text-teal-600 ml-1"></i>بارگذاریِ گروهیِ مدارک</h3><button class="ia-x" data-x>✕</button></div>
            <div class="ia-pb"><p class="text-[11.5px] text-slate-500 leading-7 mb-2">فایل‌های چند ردیف را با هم بیندازید. ردیفِ هر فایل از روی <b>پلاک یا شماره شاسیِ</b> داخلِ نامش و نوعِ مدرک از کلمه‌هایی مثلِ «رو، پشت، سند، بدنه قبل، بازدید، گزارش» حدس زده می‌شود؛ هر کدام را می‌توانید عوض کنید. نام‌گذاریِ نهایی خودکار است.</p><div data-up></div></div>`);
        mountUploader(m.q('[data-up]'), null, () => { m.close(); });
    }

    function mountUploader(el, row, onAllDone) {
        const files = [];
        const open = S.rows.filter(r => r.state !== 'ISSUED');
        el.innerHTML = `<label class="ia-drop block"><input type="file" multiple hidden accept="image/*,.pdf,.zip,.heic">
            <i class="fas fa-file-circle-plus text-2xl block mb-1"></i>فایل‌ها را اینجا رها کنید یا بزنید (عکس، PDF، زیپِ بازدید)</label>
            <div class="ia-files"></div><div class="flex flex-wrap gap-2 mt-3 hidden" data-acts>
            <button class="ia-btn p" data-go><i class="fas fa-upload"></i>بارگذاریِ همه</button><button class="ia-btn s" data-clr>پاک کردنِ فهرست</button></div>`;
        const drop = el.querySelector('.ia-drop'), inp = drop.querySelector('input'), grid = el.querySelector('.ia-files'), acts = el.querySelector('[data-acts]');
        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
        drop.addEventListener('drop', e => add([...e.dataTransfer.files]));
        inp.onchange = () => { add([...inp.files]); inp.value = ''; };
        function add(list) {
            list.forEach(f => {
                const r = row || guessRow(f.name);
                const taken = new Set(files.filter(x => (x.row && r && x.row.id === r.id)).map(x => x.type));
                files.push({f, row: r, type: guessType(f.name, r, taken), url: /^image\//.test(f.type) ? URL.createObjectURL(f) : null, state: '', pct: 0});
            });
            draw();
        }
        const typeOpts = sel => DOC_OPTS.map(k => `<option value="${k}" ${k === sel ? 'selected' : ''}>${esc(docLabel(k))}</option>`).join('');
        const rowOpts = sel => `<option value="">— ردیف را انتخاب کنید —</option>` + open.map(r => `<option value="${r.id}" ${sel && sel.id === r.id ? 'selected' : ''}>${esc(fa(rowName(r)))} · ${esc(r.insurance_type_fa)} · ${esc(r.insured_name).slice(0, 30)}</option>`).join('');
        function draw() {
            acts.classList.toggle('hidden', !files.length);
            grid.innerHTML = files.map((x, i) => {
                const ext = (x.f.name.split('.').pop() || '').toLowerCase();
                const nm = x.row ? `(${docLabel(x.type)}) ${rowName(x.row)}.${ext}` : '';
                return `<div class="ia-fc ${x.state}" data-i="${i}">
                    <div class="th" data-pv>${x.url ? `<img src="${x.url}" alt="">` : `<i class="fas ${ext === 'pdf' ? 'fa-file-pdf' : (ext === 'zip' ? 'fa-file-zipper text-amber-500' : 'fa-file')}"></i>`}</div>
                    ${x.state === 'done' ? '' : `<button class="rm" data-rm title="حذف از فهرست">✕</button>`}
                    <div class="pg"><i style="width:${x.pct}%"></i></div>
                    <div class="bd">
                        <div class="nm" title="${esc(x.f.name)}">${esc(x.f.name)} · ${fa((x.f.size / 1048576).toFixed(1))} مگ</div>
                        ${row ? '' : `<select data-row>${rowOpts(x.row)}</select>`}
                        <select data-type>${typeOpts(x.type)}</select>
                        <div class="nm" style="color:#0f766e">${nm ? '<i class="fas fa-tag ml-1"></i>' + esc(fa(nm)) : '<span class="text-rose-600">ردیف مشخص نیست</span>'}</div>
                        ${x.state === 'err' ? `<div class="nm text-rose-600">${esc(x.err || 'خطا')}</div>` : (x.state === 'done' ? '<div class="nm text-emerald-700 font-bold"><i class="fas fa-check ml-1"></i>بارگذاری و نام‌گذاری شد</div>' : '')}
                    </div></div>`;
            }).join('');
            grid.querySelectorAll('.ia-fc').forEach(card => {
                const x = files[+card.dataset.i];
                const rm = card.querySelector('[data-rm]'); if (rm) rm.onclick = () => { files.splice(+card.dataset.i, 1); draw(); };
                card.querySelector('[data-type]').onchange = e => { x.type = e.target.value; draw(); };
                const rs = card.querySelector('[data-row]'); if (rs) rs.onchange = e => { x.row = open.find(r => r.id === +e.target.value) || null; draw(); };
                card.querySelector('[data-pv]').onclick = () => {
                    if (x.url && typeof openImageZoom === 'function') return openImageZoom(x.url);
                    const u = x.url || URL.createObjectURL(x.f); window.open(u, '_blank');
                };
            });
        }
        el.querySelector('[data-clr]').onclick = () => { files.splice(0, files.length); draw(); };
        el.querySelector('[data-go]').onclick = async e => {
            const todo = files.filter(x => x.state !== 'done');
            if (todo.some(x => !x.row)) return toast('ردیفِ بعضی فایل‌ها مشخص نیست.', 'error');
            e.target.disabled = true;
            let ok = 0;
            for (const x of todo) {
                x.state = ''; x.pct = 3; draw();
                const res = await uploadOne(x, p => { x.pct = p; const bar = grid.querySelector(`[data-i="${files.indexOf(x)}"] .pg i`); if (bar) bar.style.width = p + '%'; });
                if (res.ok) { x.state = 'done'; x.pct = 100; ok++; } else { x.state = 'err'; x.err = res.error; }
                draw();
            }
            e.target.disabled = false;
            toast(`${fa(ok)} از ${fa(todo.length)} فایل بارگذاری شد.`, ok === todo.length ? 'success' : 'error');
            await load();
            if (ok === todo.length && onAllDone) onAllDone();
        };
    }
    function uploadOne(x, onPct) {
        return new Promise(resolve => {
            const fd = new FormData();
            fd.append('action', 'upload_row_doc'); fd.append('plate_id', x.row.id); fd.append('doc_type', x.type); fd.append('doc_file', x.f, x.f.name);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', CAPI);
            xhr.upload.onprogress = e => { if (e.lengthComputable) onPct(Math.max(3, Math.round(e.loaded / e.total * 95))); };
            xhr.onload = () => { let d; try { d = JSON.parse(xhr.responseText); } catch (e) { d = {ok: false, error: `خطای سرور (${xhr.status})`}; } resolve(d); };
            xhr.onerror = () => resolve({ok: false, error: 'خطا در ارتباط با سرور'});
            xhr.send(fd);
        });
    }

    // ------------------------------------------------------------------ API عمومی
    async function init(root) {
        if (!root) return;
        ensureCss();
        if (S.root !== root || !root.querySelector('.ia-hero')) { S.root = root; shell(); syncFilters(); }
        await loadMeta();
        await load();
    }
    window.ImportArchive = {init, reload, openRow: id => { if (!S.root) { if (typeof switchTab === 'function') switchTab('import-archive'); setTimeout(() => openRow(id), 600); return; } openRow(id); }};
})();
