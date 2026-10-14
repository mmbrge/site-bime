// فایل: company-portal/zerokm.js
// خودروهای صفر کیلومتر با «فاکتور فروش» در فرمِ ثبتِ درخواست (پنل شرکت‌ها)
//  ۱) اکسلِ خودروها (هر چیدمانی: شاسی، موتور، اتاق، مشتری، نوع محصول) + PDFِ فاکتورها (چند فاکتورِ پشتِ سرِ هم)
//  ۲) PDF صفحه‌به‌صفحه جدا و هر صفحه جدا خوانده می‌شود (شاسی و موتور از بارکد/متن)
//  ۳) تطبیقِ هر خودروی اکسل با فاکتورش (شاسی ← موتور ← تقریبی)؛ شماره‌ها بدونِ . * - و فاصله
//  ۴) تنظیمات: نوعِ بیمه، تعهدِ مالیِ ثالث (کلی + هر خودرو؛ پیش‌فرض از آخرین درخواست)، ارزش و پوشش‌های بدنه (کلی + هر خودرو)
//  ۵) «افزودن به درخواست» => با ثبتِ درخواست برای هر خودرو یک ردیف ساخته و فاکتورش روی همان ردیف بایگانی می‌شود
(function () {
    const API = '../api/company_portal_actions.php';
    const fa = v => String(v ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const en = v => String(v ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const clean = v => en(v).toUpperCase().replace(/[^A-Z0-9]/g, '');
    const money = v => { const n = Number(en(v).replace(/\D/g, '')); return n ? n.toLocaleString('fa-IR') : ''; };
    const post = (body, isForm) => fetch(API, isForm ? {method: 'POST', body} : {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}).then(r => r.json());
    const ocrKey = v => clean(v).replace(/[OQD]/g, '0').replace(/[IL]/g, '1').replace(/B/g, '8').replace(/S/g, '5').replace(/Z/g, '2').replace(/G/g, '6');
    const lev = (a, b) => { if (Math.abs(a.length - b.length) > 3) return 9; const d = Array.from({length: b.length + 1}, (_, i) => i);
        for (let i = 1; i <= a.length; i++) { let p = d[0]; d[0] = i; for (let j = 1; j <= b.length; j++) { const t = d[j]; d[j] = Math.min(d[j] + 1, d[j - 1] + 1, p + (a[i - 1] === b[j - 1] ? 0 : 1)); p = t; } } return d[b.length]; };

    const S = {excel: [], pages: [], token: '', rows: [], opts: null, def: null, type: 'THIRDPARTY', liab: '', cov: {}, covBase: false, added: null};

    function css() {
        if (document.getElementById('zk-css')) return;
        const st = document.createElement('style'); st.id = 'zk-css';
        st.textContent = `.zk-tbl{width:100%;border-collapse:separate;border-spacing:0 4px;font-size:11px}.zk-tbl th{font-size:10px;color:#64748b;font-weight:800;text-align:right;padding:2px 6px;white-space:nowrap}
            .zk-tbl td{background:#fff;padding:6px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;vertical-align:middle}.zk-tbl td:first-child{border-right:1px solid #e2e8f0;border-radius:0 10px 10px 0}
            .zk-tbl td:last-child{border-left:1px solid #e2e8f0;border-radius:10px 0 0 10px}.zk-tbl input,.zk-tbl select{border:1px solid #e2e8f0;border-radius:8px;padding:3px 6px;font-size:11px;background:#f8fafc}
            .zk-ok{background:#ecfdf5;color:#047857}.zk-no{background:#fff1f2;color:#be123c}.zk-pill{display:inline-block;border-radius:999px;padding:1px 8px;font-weight:800;font-size:10px}
            .zk-cov label{display:flex;align-items:center;gap:5px;font-size:11px;background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:4px 8px}`;
        document.head.appendChild(st);
    }
    function modal() {
        let m = document.getElementById('zk-modal');
        if (m) return m;
        m = document.createElement('div'); m.id = 'zk-modal'; m.className = 'modal-overlay';
        m.innerHTML = `<div class="modal-content" style="max-width:min(1100px,96vw);width:96vw">
            <div class="flex items-center justify-between mb-3"><h3 class="font-black text-sm text-indigo-800">🚚 خودروهای صفر کیلومتر با فاکتور فروش</h3>
                <button type="button" class="text-slate-400 text-xl" data-x>&times;</button></div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-3">
                <label class="rounded-xl border-2 border-dashed border-emerald-300 bg-emerald-50/50 p-3 text-center cursor-pointer text-[11.5px] font-bold text-emerald-800">📊 اکسلِ خودروها (شاسی، موتور، مشتری، نوع محصول)
                    <input type="file" id="zk-excel" accept=".xlsx,.xls,.csv" class="hidden"><span id="zk-excel-st" class="block text-[10.5px] font-normal text-slate-500 mt-1">اختیاری؛ بدونِ اکسل از روی خودِ فاکتورها ردیف ساخته می‌شود</span></label>
                <label class="rounded-xl border-2 border-dashed border-indigo-300 bg-indigo-50/50 p-3 text-center cursor-pointer text-[11.5px] font-bold text-indigo-800">🧾 PDFِ فاکتورهای فروش (چند فاکتور پشتِ سرِ هم)
                    <input type="file" id="zk-pdf" accept=".pdf,application/pdf" class="hidden"><span id="zk-pdf-st" class="block text-[10.5px] font-normal text-slate-500 mt-1">هر صفحه جدا خوانده می‌شود</span></label>
            </div>
            <div id="zk-prog" class="hidden rounded-xl bg-slate-50 border p-2 text-[11px] mb-3"></div>
            <div id="zk-settings" class="hidden rounded-xl border border-slate-200 p-3 mb-3"></div>
            <div id="zk-table" class="overflow-x-auto"></div>
            <div class="flex flex-wrap gap-2 justify-end mt-3">
                <button type="button" class="px-4 py-2 rounded-xl text-xs font-bold bg-slate-100" data-x>انصراف</button>
                <button type="button" id="zk-add" class="px-4 py-2 rounded-xl text-xs font-bold bg-indigo-600 text-white disabled:opacity-40" disabled>افزودن به درخواست</button>
            </div></div>`;
        document.body.appendChild(m);
        m.querySelectorAll('[data-x]').forEach(b => b.onclick = () => m.classList.remove('active'));
        m.querySelector('#zk-excel').onchange = e => loadExcel(e.target.files[0]);
        m.querySelector('#zk-pdf').onchange = e => loadPdf(e.target.files[0]);
        m.querySelector('#zk-add').onclick = addToRequest;
        return m;
    }

    async function open() {
        css();
        const m = modal();
        m.classList.add('active');
        if (!S.opts) {
            const cid = (document.getElementById('nr-company') || {}).value;
            const r = await post({action: 'zk_defaults', company_id: cid});
            if (r.ok) {
                S.opts = r.coverage_options || {}; S.liabOpts = r.liability_options || []; S.def = r.defaults || {};
                if (S.def.liability_limit) S.liab = String(S.def.liability_limit);
                if (S.def.coverages && typeof S.def.coverages === 'object') { S.cov = Object.assign({}, S.def.coverages); S.covBase = !Object.keys(S.cov).length; }
            }
        }
        renderSettings(); renderTable();
    }

    async function loadExcel(f) {
        if (!f) return;
        const st = document.getElementById('zk-excel-st'); st.textContent = 'در حالِ خواندن…';
        const fd = new FormData(); fd.append('action', 'zk_parse_excel'); fd.append('file', f);
        const r = await post(fd, true).catch(() => ({ok: false, error: 'خطا در ارتباط'}));
        if (!r.ok) { st.innerHTML = `<span class="text-rose-600">${esc(r.error)}</span>`; return; }
        S.excel = r.rows; st.innerHTML = `<span class="text-emerald-700 font-bold">${fa(r.rows.length)} خودرو خوانده شد</span>`;
        match();
    }

    async function loadPdf(f) {
        if (!f) return;
        const st = document.getElementById('zk-pdf-st'), pr = document.getElementById('zk-prog');
        st.textContent = 'در حالِ بارگذاری و جدا کردنِ صفحه‌ها…';
        const fd = new FormData(); fd.append('action', 'zk_upload_pdf'); fd.append('file', f);
        const r = await post(fd, true).catch(() => ({ok: false, error: 'خطا در ارتباط'}));
        if (!r.ok) { st.innerHTML = `<span class="text-rose-600">${esc(r.error)}</span>`; return; }
        S.token = r.token; S.pages = Array.from({length: r.pages}, (_, i) => ({n: i + 1, state: 'wait'}));
        st.innerHTML = `<span class="font-bold text-indigo-700">${fa(r.pages)} صفحه</span>`;
        pr.classList.remove('hidden');
        for (const p of S.pages) {
            p.state = 'read';
            pr.innerHTML = `⏳ در حالِ خواندنِ فاکتورِ ${fa(p.n)} از ${fa(S.pages.length)}… <span class="text-slate-400">(هر صفحه چند ثانیه)</span>`;
            const x = await post({action: 'zk_read_page', token: S.token, n: p.n}).catch(() => ({ok: false}));
            Object.assign(p, x, {state: x.ok ? 'done' : 'fail'});
            match();
        }
        const ok = S.pages.filter(p => p.data && (p.data.vin || p.data.engine_no)).length;
        pr.innerHTML = `✅ ${fa(S.pages.length)} صفحه خوانده شد؛ ${fa(ok)} فاکتور شناسایی شد${S.pages.length - ok ? ` · ${fa(S.pages.length - ok)} صفحه شناسایی نشد (بعد از ثبت برای کارشناس می‌رود)` : ''}.`;
    }

    // تطبیقِ اکسل با فاکتورها
    function match() {
        const pages = S.pages.filter(p => p.data && (p.data.vin || p.data.engine_no));
        const used = new Set();
        const find = e => {
            const ch = e.chassis, eg = e.engine;
            let p = pages.find(p => !used.has(p.n) && ch && clean(p.data.vin) === ch);
            if (!p) p = pages.find(p => !used.has(p.n) && eg && clean(p.data.engine_no) === eg);
            // تقریبی فقط برای خطای OCR (O/0، I/1، B/8، S/5…)؛ شاسی‌های یک سری فقط در چند رقمِ آخر فرق دارند، پس فاصله‌ی ویرایشی ملاک نیست
            if (!p) p = pages.find(p => !used.has(p.n) && ch && p.data.vin && ocrKey(p.data.vin) === ocrKey(ch));
            if (!p) p = pages.find(p => !used.has(p.n) && eg && p.data.engine_no && (ocrKey(p.data.engine_no) === ocrKey(eg)
                || (eg.length >= 12 && clean(p.data.engine_no).length >= 12 && clean(p.data.engine_no).slice(-12) === eg.slice(-12))));
            return p;
        };
        const keep = {}; S.rows.forEach(r => { keep[r.key] = r; });
        const rows = [];
        S.excel.forEach((e, i) => {
            const p = find(e); if (p) used.add(p.n);
            const key = 'x' + i, old = keep[key] || {};
            rows.push(Object.assign({type: '', liab: '', value: e.value || '', covMode: 'def', cov: null, on: true}, old, {key, src: 'excel', e, page: p ? p.n : null, inv: p ? p.data : null}));
        });
        // فاکتورهای بی‌ردیف: بدونِ اکسل => خودشان ردیف‌اند؛ با اکسل => جدا نشان داده می‌شوند تا اضافه شوند
        pages.filter(p => !used.has(p.n)).forEach(p => {
            const key = 'p' + p.n, old = keep[key];
            if (!S.excel.length || old) rows.push(Object.assign({type: '', liab: '', value: '', covMode: 'def', cov: null, on: true}, old || {}, {key, src: 'invoice', e: null, page: p.n, inv: p.data}));
        });
        S.rows = rows;
        S.extraPages = S.excel.length ? pages.filter(p => !used.has(p.n) && !keep['p' + p.n]) : [];
        renderSettings(); renderTable();
    }

    function covHtml(cov, base, scope) {
        const o = S.opts || {};
        return `<div class="zk-cov flex flex-wrap gap-1.5" data-scope="${scope}">
            <label><input type="checkbox" data-base ${base ? 'checked' : ''}> فقط پوششِ پایه</label>
            ${Object.entries(o).map(([k, v]) => `<label title="${esc(v.desc || '')}"><input type="checkbox" data-cov="${k}" ${cov && cov[k] !== undefined ? 'checked' : ''} ${base ? 'disabled' : ''}> ${esc(v.label)}
                ${v.tiers ? `<select data-tier="${k}" ${base ? 'disabled' : ''}>${Object.entries(v.tiers).map(([tk, tl]) => `<option value="${tk}" ${cov && String(cov[k]) === tk ? 'selected' : ''}>${esc(tl)}</option>`).join('')}</select>` : ''}</label>`).join('')}</div>`;
    }
    function readCov(box) {
        const base = box.querySelector('[data-base]').checked, cov = {};
        if (!base) box.querySelectorAll('[data-cov]').forEach(c => { if (c.checked) { const t = box.querySelector(`[data-tier="${c.dataset.cov}"]`); cov[c.dataset.cov] = t ? t.value : true; } });
        return {base, cov};
    }

    function renderSettings() {
        const box = document.getElementById('zk-settings'); if (!box) return;
        if (!S.rows.length) { box.classList.add('hidden'); return; }
        box.classList.remove('hidden');
        const showBody = S.type !== 'THIRDPARTY' || S.rows.some(r => r.type === 'BODY' || r.type === 'BOTH');
        box.innerHTML = `<p class="text-[12px] font-black text-slate-700 mb-2">⚙️ تنظیماتِ کلی (برای همه‌ی خودروها؛ در جدول برای هر خودرو جدا هم عوض می‌شود)</p>
            <div class="flex flex-wrap gap-3 items-end text-[11px]">
                <label><span class="block text-slate-500 mb-0.5">بیمه‌ی درخواستی</span><select id="zk-type" class="border rounded-lg px-2 py-1.5">
                    <option value="THIRDPARTY" ${S.type === 'THIRDPARTY' ? 'selected' : ''}>ثالث</option><option value="BODY" ${S.type === 'BODY' ? 'selected' : ''}>بدنه</option><option value="BOTH" ${S.type === 'BOTH' ? 'selected' : ''}>ثالث و بدنه</option></select></label>
                <label><span class="block text-slate-500 mb-0.5">سقفِ تعهدِ مالیِ ثالث (ریال)</span><select id="zk-liab" class="border rounded-lg px-2 py-1.5"><option value="">انتخاب کنید</option>
                    ${(S.liabOpts || []).map(v => `<option value="${v}" ${String(v) === String(S.liab) ? 'selected' : ''}>${money(v)}</option>`).join('')}</select>
                    ${S.def && S.def.liability_from ? `<span class="block text-[10px] text-emerald-600 mt-0.5">پیش‌فرض از آخرین درخواست (${fa(S.def.liability_from.date)})</span>` : ''}</label>
            </div>
            ${showBody ? `<div class="mt-2"><span class="block text-[11px] text-slate-500 mb-1">پوشش‌های بدنه (کلی)${S.def && S.def.coverages_from ? ` <span class="text-emerald-600">— از آخرین درخواستِ بدنه (${fa(S.def.coverages_from.date)})</span>` : ''}</span>${covHtml(S.cov, S.covBase, 'all')}</div>` : ''}`;
        box.querySelector('#zk-type').onchange = e => { S.type = e.target.value; renderSettings(); renderTable(); };
        box.querySelector('#zk-liab').onchange = e => { S.liab = e.target.value; renderTable(); };
        const cb = box.querySelector('.zk-cov[data-scope="all"]');
        if (cb) cb.addEventListener('change', () => { const r = readCov(cb); S.cov = r.cov; S.covBase = r.base; renderSettings(); });
    }

    function renderTable() {
        const box = document.getElementById('zk-table'); if (!box) return;
        const add = document.getElementById('zk-add');
        if (!S.rows.length) { box.innerHTML = '<p class="text-center text-[11px] text-slate-400 py-6">اکسل و/یا PDFِ فاکتورها را انتخاب کنید.</p>'; if (add) add.disabled = true; return; }
        const withInv = S.rows.filter(r => r.page).length;
        box.innerHTML = `<p class="text-[11px] mb-1"><b>${fa(S.rows.length)}</b> خودرو · <span class="zk-pill zk-ok">${fa(withInv)} با فاکتور</span> ${S.rows.length - withInv ? `<span class="zk-pill zk-no">${fa(S.rows.length - withInv)} بدونِ فاکتور</span>` : ''}</p>
            <table class="zk-tbl"><thead><tr><th></th><th>شماره شاسی</th><th>شماره موتور</th><th>خودرو</th><th>مشتری / خریدار</th><th>فاکتور</th><th>بیمه</th><th>تعهد ثالث (ریال)</th><th>ارزش بدنه (ریال)</th><th>پوشش بدنه</th></tr></thead><tbody>
            ${S.rows.map((r, i) => { const e = r.e || {}, v = r.inv || {}, t = r.type || S.type;
                const ch = e.chassis || clean(v.vin), eg = e.engine || clean(v.engine_no), car = [v.car_system, v.car_tip].filter(Boolean).join(' ') || e.product || v.usage || '';
                const mis = v.vin && e.chassis && clean(v.vin) !== e.chassis;
                return `<tr data-i="${i}">
                <td><input type="checkbox" data-k="on" ${r.on ? 'checked' : ''}></td>
                <td dir="ltr" class="font-bold ${mis ? 'text-amber-700' : ''}" title="${mis ? 'فاکتور: ' + esc(v.vin) : ''}">${esc(ch)}</td>
                <td dir="ltr">${esc(eg)}${e.engine_raw && e.engine_raw !== eg ? `<div class="text-[9.5px] text-slate-400">${esc(e.engine_raw)}</div>` : ''}</td>
                <td>${esc(car)}</td>
                <td>${esc(e.customer || '')}${v.owner_name ? `<div class="text-[10px] text-indigo-700">فاکتور: ${esc(v.owner_name)}${v.national_id ? ' · ' + fa(v.national_id) : ''}</div>` : ''}</td>
                <td>${r.page ? `<a class="zk-pill zk-ok" target="_blank" href="${API}?action=zk_page&token=${S.token}&n=${r.page}">صفحه ${fa(r.page)} ✓</a>` : '<span class="zk-pill zk-no">ندارد</span>'}</td>
                <td><select data-k="type"><option value="">(کلی)</option><option value="THIRDPARTY" ${r.type === 'THIRDPARTY' ? 'selected' : ''}>ثالث</option><option value="BODY" ${r.type === 'BODY' ? 'selected' : ''}>بدنه</option><option value="BOTH" ${r.type === 'BOTH' ? 'selected' : ''}>هردو</option></select></td>
                <td>${t !== 'BODY' ? `<input data-k="liab" class="w-32" inputmode="numeric" dir="ltr" placeholder="${S.liab ? money(S.liab) : 'کلی'}" value="${r.liab ? money(r.liab) : ''}">` : '—'}</td>
                <td>${t !== 'THIRDPARTY' ? `<input data-k="value" class="w-32" inputmode="numeric" dir="ltr" placeholder="ارزش" value="${r.value ? money(r.value) : ''}">` : '—'}</td>
                <td>${t !== 'THIRDPARTY' ? `<select data-k="covMode"><option value="def" ${r.covMode === 'def' ? 'selected' : ''}>همان کلی</option><option value="base" ${r.covMode === 'base' ? 'selected' : ''}>فقط پایه</option><option value="custom" ${r.covMode === 'custom' ? 'selected' : ''}>سفارشی…</option></select>` : '—'}</td></tr>
                ${r.covMode === 'custom' && t !== 'THIRDPARTY' ? `<tr data-ci="${i}"><td colspan="10">${covHtml(r.cov || {}, false, 'r' + i)}</td></tr>` : ''}`; }).join('')}
            </tbody></table>
            ${(S.extraPages || []).length ? `<div class="mt-2 rounded-xl bg-amber-50 border border-amber-200 p-2 text-[11px]">🧾 ${fa(S.extraPages.length)} فاکتور با هیچ ردیفی از اکسل نخواند:
                ${S.extraPages.map(p => `<button type="button" data-addp="${p.n}" class="zk-pill bg-white border border-amber-300 mx-0.5">صفحه ${fa(p.n)}: ${esc(p.data.vin || p.data.engine_no)} ＋</button>`).join('')}
                <span class="text-slate-500">(اگر اضافه نکنید، بعد از ثبت برای بررسیِ کارشناس می‌رود)</span></div>` : ''}`;
        box.querySelectorAll('tr[data-i]').forEach(tr => {
            const r = S.rows[Number(tr.dataset.i)];
            tr.querySelectorAll('[data-k]').forEach(el => el.onchange = () => {
                const k = el.dataset.k;
                r[k] = el.type === 'checkbox' ? el.checked : (k === 'liab' || k === 'value' ? en(el.value).replace(/\D/g, '') : el.value);
                if (k === 'type' || k === 'covMode') { renderSettings(); renderTable(); }
            });
        });
        box.querySelectorAll('tr[data-ci]').forEach(tr => {
            const r = S.rows[Number(tr.dataset.ci)], cb = tr.querySelector('.zk-cov');
            cb.addEventListener('change', () => { const x = readCov(cb); r.cov = x.base ? {} : x.cov; if (x.base) { r.covMode = 'base'; renderTable(); } });
        });
        box.querySelectorAll('[data-addp]').forEach(b => b.onclick = () => { const p = S.pages.find(x => x.n === Number(b.dataset.addp));
            S.rows.push({key: 'p' + p.n, src: 'invoice', e: null, page: p.n, inv: p.data, type: '', liab: '', value: '', covMode: 'def', cov: null, on: true}); S.extraPages = S.extraPages.filter(x => x.n !== p.n); renderTable(); });
        if (add) add.disabled = !S.rows.some(r => r.on);
    }

    // ردیف‌های آماده برای ثبت (هر «هردو» دو ردیف: ثالث + بدنه)
    function plates() {
        const out = [];
        S.rows.filter(r => r.on).forEach(r => {
            const e = r.e || {}, v = r.inv || {}, t = r.type || S.type;
            const base = {zk: 1, is_new_vehicle: 1, p1: '', p2: '', letter: '', p4: '', chassis_no: e.chassis || clean(v.vin), engine_no: e.engine || clean(v.engine_no),
                car_name: [v.car_system, v.car_tip].filter(Boolean).join(' ') || e.product || v.usage || '', car_type: v.usage || e.product || '',
                note: e.customer ? 'مشتری: ' + e.customer : '', owner_name: v.owner_name || '', owner_national_id: v.national_id || e.national_id || '', zk_page: r.page || null};
            const cov = r.covMode === 'base' ? 'none' : (r.covMode === 'custom' ? (r.cov && Object.keys(r.cov).length ? r.cov : 'none') : (S.covBase ? 'none' : (Object.keys(S.cov || {}).length ? S.cov : null)));
            if (t === 'THIRDPARTY' || t === 'BOTH') out.push(Object.assign({}, base, {insurance_type: 'THIRDPARTY', liability_limit: r.liab || S.liab || '', car_value: ''}));
            // «هردو»: فاکتور روی هر دو ردیف (ثالث و بدنه) بایگانی می‌شود
            if (t === 'BODY' || t === 'BOTH') out.push(Object.assign({}, base, {insurance_type: 'BODY', car_value: r.value || '', liability_limit: '', coverages: cov}));
        });
        return out;
    }

    function addToRequest() {
        const ps = plates();
        if (!ps.length) return;
        const third = ps.filter(p => p.insurance_type === 'THIRDPARTY' && !p.liability_limit);
        if (third.length) { (window.showToast || alert)('سقفِ تعهدِ مالیِ ثالث را (کلی یا برای هر خودرو) انتخاب کنید.'); return; }
        S.added = ps;
        const sum = document.getElementById('zk-summary');
        const n = S.rows.filter(r => r.on).length, w = S.rows.filter(r => r.on && r.page).length;
        if (sum) sum.innerHTML = `<div class="mt-2 text-[11px] font-bold text-indigo-800 bg-white border border-indigo-200 rounded-lg px-3 py-2 flex items-center justify-between gap-2">
            <span>✅ ${fa(n)} خودروی صفر کیلومتر (${fa(w)} با فاکتور) آماده‌ی ثبت با همین درخواست</span><button type="button" class="text-rose-500" onclick="ZeroKm.clear()">حذف</button></div>`;
        document.getElementById('zk-modal').classList.remove('active');
    }

    window.ZeroKm = {
        open,
        // برای submitNewRequest: ردیف‌ها و توکنِ فایلِ فاکتورها
        payload: () => S.added ? {plates: S.added, token: S.token} : null,
        clear: () => { Object.assign(S, {excel: [], pages: [], token: '', rows: [], added: null, extraPages: []}); const s = document.getElementById('zk-summary'); if (s) s.innerHTML = '';
            const m = document.getElementById('zk-modal'); if (m) { m.remove(); } },
    };
})();
