// فایل: recon.js
// «مغایرت‌گیری با پاسارگاد» (مرکز صدور): خروجیِ هفتگی/ماهانه‌ی پاسارگاد (اکسلِ صدورِ بدنه/ثالث + اکسلِ الحاقیه‌ها) => مقایسه با سایت
//   منطبق / مغایر (فیلد به فیلد: سایت درست است، پاسارگاد درست است، اصلاحِ دستی) / فقط در پاسارگاد (ورود به سایت) / فقط در سایت (بررسی با توضیح)
// منطق: api/_recon.php  (api/recon_actions.php)
(function () {
    'use strict';
    const API = 'api/recon_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => fa((Number(v) || 0).toLocaleString('en-US'));
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const ask = async m => (window.uiConfirm ? uiConfirm('مغایرت‌گیری', m, {}) : confirm(m));
    const isPT = v => window.isPlateText ? window.isPlateText(v) : /ایران/.test(String(v || ''));   // پلاکِ خودرو یا موتورسیکلت
    const plate = p => p && isPT(p) && typeof formatPlateHtml === 'function' ? `<span class="plate-display">${formatPlateHtml(p)}</span>` : esc(fa(p || ''));
    const can = a => typeof permCan !== 'function' || permCan('pasargad-recon', a);
    const isAdmin = () => typeof IS_ADMIN === 'undefined' || IS_ADMIN;
    const post = async (body, isForm) => {
        try {
            const r = await fetch(API, isForm ? {method: 'POST', body} : {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
            const t = await r.text();
            try { return JSON.parse(t); } catch (e) { return {ok: false, error: 'پاسخِ نامعتبر از سرور.'}; }
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    };
    const CAT = {DIFF: ['مغایر', 'r', 'fa-not-equal'], ONLY_P: ['فقط در پاسارگاد', 'w', 'fa-cloud-arrow-down'], ONLY_S: ['فقط در سایت', 'c', 'fa-globe'], MATCH: ['منطبق', 'p', 'fa-check-double']};
    const FIELD = {type: 'نوع', issue: 'تاریخ صدور', start: 'تاریخ شروع', end: 'تاریخ انقضا', premium: 'حق بیمه', plate: 'پلاک', vin: 'شاسی', insured: 'بیمه‌گذار',
                   endo: 'الحاقیه‌ها', final: 'حق بیمه‌ی نهایی', cancel: 'فسخ'};
    const RES = {SITE: ['سایت درست است', 'c'], PASARGAD: ['پاسارگاد اعمال شد', 'p'], CUSTOM: ['اصلاحِ دستی', 'w']};
    const MONEY_F = {premium: 1, final: 1};

    const CSS = `
    .rc-hero{border-radius:26px;padding:20px 22px;color:#fff;background:linear-gradient(120deg,#9a3412,#be123c 45%,#4338ca);box-shadow:0 20px 40px -26px #be123c}
    .rc-hero h2{font-size:19px;font-weight:900}.rc-hero p{font-size:11.5px;opacity:.9;margin-top:4px;line-height:1.9;max-width:900px}
    .rc-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:14px 16px;display:flex;flex-direction:column;gap:10px}
    .rc-card h3{font-size:14px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.rc-card h3 small{font-size:11px;color:#64748b;font-weight:700}
    .rc-up{display:grid;grid-template-columns:1fr 260px auto;gap:8px;align-items:center}@media(max-width:820px){.rc-up{grid-template-columns:1fr}}
    .rc-drop{border:2px dashed #fda4af;border-radius:16px;padding:14px;text-align:center;cursor:pointer;color:#475569;font-size:12px;background:#fff1f2;line-height:1.9}
    .rc-drop.on{border-color:#be123c;background:#ffe4e6}
    .rc-in{border:1px solid #cbd5e1;border-radius:11px;padding:7px 9px;font-size:12px;font-family:inherit;width:100%;background:#fff}
    .rc-btn{border:0;border-radius:13px;padding:8px 14px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
    .rc-btn.p{background:linear-gradient(120deg,#be123c,#4338ca);color:#fff}.rc-btn.s{background:#e2e8f0;color:#334155}.rc-btn.g{background:#059669;color:#fff}.rc-btn.i{background:#eef2ff;color:#4338ca}
    .rc-btn.d{background:#fee2e2;color:#b91c1c}.rc-btn.sm{padding:4px 9px;font-size:10.5px;border-radius:9px}.rc-btn:disabled{opacity:.45;cursor:not-allowed}
    .rc-rbar{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.rc-rbar .rc-in{width:auto}.rc-rbar select.rc-in{padding:6px 8px}
    .rc-seg{display:inline-flex;background:#f1f5f9;border-radius:12px;padding:3px;gap:2px}.rc-seg button{border:0;background:transparent;border-radius:9px;padding:4px 10px;font-size:11px;font-weight:800;color:#475569;cursor:pointer;font-family:inherit}
    .rc-seg button.on{background:#fff;color:#be123c;box-shadow:0 1px 3px rgba(15,23,42,.12)}
    .rc-mchips{display:flex;gap:5px;overflow-x:auto;padding-bottom:2px}.rc-mchips button{border:1px solid #e2e8f0;background:#fff;border-radius:999px;padding:3px 10px;font-size:10.5px;font-weight:800;color:#475569;cursor:pointer;white-space:nowrap;font-family:inherit}
    .rc-mchips button.on{background:#0f172a;color:#fff;border-color:#0f172a}.rc-mchips small{opacity:.6;margin-right:3px}
    .rc-mhead{font-size:11px;font-weight:900;color:#64748b;margin:8px 2px 2px;display:flex;align-items:center;gap:6px}.rc-mhead:after{content:'';flex:1;height:1px;background:#e2e8f0}
    .rc-runs{display:flex;flex-direction:column;gap:6px}
    .rc-run{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1.2fr) 150px auto;gap:10px;align-items:center;border:1px solid #e2e8f0;border-radius:16px;padding:9px 12px;cursor:pointer;font-size:11px;background:#fff;text-align:right;font-family:inherit;width:100%;transition:border-color .15s,box-shadow .15s}
    .rc-run:hover{border-color:#fda4af}.rc-run.on{border-color:#be123c;box-shadow:0 0 0 2px #fecdd3;background:#fff7f8}
    .rc-run b{font-size:12.5px;color:#0f172a;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rc-run .m{color:#64748b;font-size:10.5px;margin-top:2px}
    .rc-run .pills{display:flex;flex-wrap:wrap;gap:4px}.rc-prog{height:6px;border-radius:99px;background:#f1f5f9;overflow:hidden}.rc-prog i{display:block;height:100%;background:linear-gradient(90deg,#059669,#10b981);border-radius:99px}
    .rc-run .pct{font-size:10px;color:#475569;font-weight:800;margin-top:3px}.rc-run .act{display:flex;gap:4px}
    .rc-ico{border:1px solid #e2e8f0;background:#fff;border-radius:10px;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;color:#475569;cursor:pointer}.rc-ico:hover{background:#f8fafc;color:#be123c}
    @media(max-width:820px){.rc-run{grid-template-columns:1fr auto}.rc-run .pills,.rc-run .pg{grid-column:span 2}}
    .rc-pager{display:flex;align-items:center;justify-content:center;gap:8px;font-size:11px;color:#475569;margin-top:4px}
    .rc-kpi{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}@media(max-width:820px){.rc-kpi{grid-template-columns:repeat(2,minmax(0,1fr))}}
    .rc-kpi button{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:9px;text-align:center;cursor:pointer;font-family:inherit}
    .rc-kpi button.on{border-color:#be123c;background:#fff1f2}.rc-kpi small{display:block;font-size:10.5px;color:#64748b}.rc-kpi b{font-size:17px;color:#0f172a}
    .rc-pill{display:inline-flex;align-items:center;gap:3px;border-radius:999px;padding:1px 8px;font-size:10px;font-weight:800;border:1px solid;white-space:nowrap}
    .rc-pill.c{background:#eef2ff;color:#4338ca;border-color:#c7d2fe}.rc-pill.p{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.rc-pill.w{background:#fffbeb;color:#92400e;border-color:#fde68a}
    .rc-pill.r{background:#fff1f2;color:#be123c;border-color:#fecdd3}.rc-pill.n{background:#f8fafc;color:#475569;border-color:#e2e8f0}
    .rc-flt{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.rc-flt button{border:1px solid #e2e8f0;background:#fff;border-radius:999px;padding:4px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit}
    .rc-flt button.on{background:#be123c;color:#fff;border-color:#be123c}
    .rc-item{border:1px solid #e2e8f0;border-radius:18px;padding:10px 12px;font-size:11.5px;background:#fff}.rc-item.done{background:#f8fafc;opacity:.85}
    .rc-ih{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}
    .rc-dt{width:100%;border-collapse:collapse;margin-top:8px;font-size:11.5px}.rc-dt th{background:#f8fafc;color:#64748b;font-weight:800;padding:5px 6px;text-align:right;font-size:10.5px}
    .rc-dt td{border-top:1px solid #f1f5f9;padding:6px;vertical-align:middle}.rc-dt td.v{font-weight:700}.rc-dt td.v.pas{color:#be123c}
    @media(max-width:720px){.rc-dt thead{display:none}.rc-dt tr{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #f1f5f9;padding:6px 0}.rc-dt td{border:0;padding:3px}.rc-dt td.f{grid-column:span 2;font-weight:900}.rc-dt td.a{grid-column:span 2}}
    .rc-tb{width:100%;font-size:11.5px;border-collapse:collapse;white-space:nowrap}.rc-tb th{background:#f1f5f9;color:#475569;font-weight:800;padding:6px;text-align:right}.rc-tb td{border-top:1px solid #f1f5f9;padding:6px}
    .rc-info{background:#f0f9ff;border:1px solid #bae6fd;color:#075985;border-radius:12px;padding:8px 10px;font-size:11.5px;line-height:1.9}`;
    function css() { if (!document.getElementById('rc-css')) { const s = document.createElement('style'); s.id = 'rc-css'; s.textContent = CSS; document.head.appendChild(s); } }

    const S = {root: null, runs: [], run: null, cur: null, cat: 'DIFF', status: 'OPEN', field: '', q: '', rows: [], sel: {},
               rf: {q: '', year: '', month: '', status: '', creator: '', page: 1}};

    function init(root) {
        css();
        if (!root) return;
        S.root = root;
        if (!root.dataset.ready) {
            root.dataset.ready = '1';
            root.innerHTML = `
            <div class="rc-hero"><h2><i class="fas fa-scale-unbalanced ml-2"></i>مغایرت‌گیری با پاسارگاد</h2>
                <p>هر هفته یا هر ماه خروجیِ صدورِ پاسارگاد (بدنه و ثالث، همان قالبِ همیشگی) و در صورتِ امکان خروجیِ الحاقیه‌ها را بدهید. سایت می‌گوید کدام بیمه‌نامه در هر دو هست و یکی است، کدام فیلدش فرق دارد، کدام فقط در پنلِ پاسارگاد است و کدام فقط در سایت. برای هر مغایرت شما تعیین می‌کنید کدام طرف درست است؛ «پاسارگاد درست است» مقدارِ پاسارگاد را روی سایت می‌نشاند.</p></div>
            ${can('create') ? `<div class="rc-card"><h3><i class="fas fa-file-excel text-emerald-600"></i>مغایرت‌گیریِ تازه</h3>
                <div class="rc-up"><div class="rc-drop" id="rc-drop"><b>اکسل‌های خروجیِ پاسارگاد را اینجا بکشید یا کلیک کنید</b><br>بدنه، ثالث و الحاقیه‌ها — با هم یا جدا<input type="file" data-uk-skip id="rc-file" accept=".xlsx,.xls,.csv" multiple class="hidden"></div>
                <input id="rc-title" class="rc-in" placeholder="عنوان (اختیاری): مثلاً هفته‌ی دوم مهر">
                <button class="rc-btn p" id="rc-go" disabled><i class="fas fa-magnifying-glass-chart"></i>شروع</button></div>
                <div class="text-[11px] text-slate-500" id="rc-fnames"></div><div id="rc-result"></div></div>` : ''}
            <div class="rc-card"><h3><i class="fas fa-clock-rotate-left text-slate-500"></i>مغایرت‌گیری‌های قبلی <small id="rc-rcount"></small></h3>
                <div class="rc-rbar">
                    <input id="rc-rq" class="rc-in" style="flex:1;min-width:180px" placeholder="جستجو: عنوان، نامِ فایل، تاریخ، کاربر">
                    <select id="rc-ry" class="rc-in"><option value="">همه‌ی سال‌ها</option></select>
                    <div class="rc-seg" id="rc-rst"><button data-v="" class="on">همه</button><button data-v="open">باز دارد</button><button data-v="done">بررسی‌شده</button></div>
                    <select id="rc-rc" class="rc-in hidden"><option value="">همه‌ی کاربران</option></select></div>
                <div class="rc-mchips" id="rc-rm"></div>
                <div class="rc-runs" id="rc-runs"></div><div class="rc-pager" id="rc-rpg"></div></div>
            <div id="rc-view" class="space-y-3"></div>`;
            const drop = root.querySelector('#rc-drop');
            if (drop) {
                const inp = root.querySelector('#rc-file'), go = root.querySelector('#rc-go');
                const show = () => { root.querySelector('#rc-fnames').textContent = [...inp.files].map(f => f.name).join('، '); go.disabled = !inp.files.length; };
                drop.onclick = () => inp.click();
                drop.ondragover = e => { e.preventDefault(); drop.classList.add('on'); };
                drop.ondragleave = () => drop.classList.remove('on');
                drop.ondrop = e => { e.preventDefault(); drop.classList.remove('on'); inp.files = e.dataTransfer.files; show(); };
                inp.onchange = show;
                const send = async (mode, runId) => {
                    const fd = new FormData();
                    fd.append('action', 'run'); fd.append('title', root.querySelector('#rc-title').value.trim());
                    if (mode) fd.append('mode', mode);
                    if (runId) fd.append('run_id', runId);
                    [...inp.files].forEach(f => fd.append('files[]', f));
                    go.disabled = true; go.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ مقایسه...';
                    const r = await post(fd, true);
                    go.innerHTML = '<i class="fas fa-magnifying-glass-chart"></i>شروع';
                    if (r.need_choice) {
                        // همین فایل قبلاً مغایرت‌گیری شده و فایلِ اصلی‌اش روی سایت نیست
                        const c = await chooseOld(r.match);
                        if (c === 'attach') return send('attach', r.match.id);
                        if (c === 'new') return send('new');
                        go.disabled = false; return;
                    }
                    if (!r.ok) { go.disabled = false; return toast(r.error || 'خطا', 'error'); }
                    inp.value = ''; show();
                    if (mode === 'attach') {
                        toast(`فایل به مغایرت‌گیریِ «${fa(r.title || '')}» وصل شد؛ تصمیم‌های قبلی سرِ جایش است.`, 'success');
                        S.run = r.run_id; S.status = 'OPEN'; S.field = '';
                    } else {
                        toast(`مقایسه شد: ${fa(r.counts.MATCH)} منطبق، ${fa(r.counts.DIFF)} مغایر، ${fa(r.counts.ONLY_P)} فقط پاسارگاد، ${fa(r.counts.ONLY_S)} فقط سایت.`, 'success');
                        S.run = r.run_id; S.cat = r.counts.DIFF ? 'DIFF' : (r.counts.ONLY_P ? 'ONLY_P' : 'MATCH'); S.status = 'OPEN'; S.field = '';
                    }
                    S.focus = r.run_id;
                    showResult(r.run_id, r.src_files || [], mode === 'attach');
                    const v = S.root.querySelector('#rc-view'); if (v) v.dataset.run = '';
                    await loadRuns(); loadItems();
                };
                go.onclick = () => send('');
            }
        }
        // فیلترهای فهرستِ مغایرت‌گیری‌ها
        let rt = null;
        root.querySelector('#rc-rq').oninput = e => { clearTimeout(rt); rt = setTimeout(() => { S.rf.q = e.target.value.trim(); S.rf.page = 1; loadRuns(); }, 300); };
        root.querySelector('#rc-ry').onchange = e => { S.rf.year = e.target.value; S.rf.month = ''; S.rf.page = 1; loadRuns(); };
        root.querySelector('#rc-rc').onchange = e => { S.rf.creator = e.target.value; S.rf.page = 1; loadRuns(); };
        root.querySelectorAll('#rc-rst button').forEach(b => b.onclick = () => { S.rf.status = b.dataset.v; S.rf.page = 1; root.querySelectorAll('#rc-rst button').forEach(x => x.classList.toggle('on', x === b)); loadRuns(); });
        loadRuns().then(() => { if (S.run) loadItems(); });
    }

    // باز کردنِ پوشه‌ی بایگانیِ مغایرت‌گیری در «بایگانی فایل‌ها»
    function openArchive(rel) {
        if (!rel || typeof switchTab !== 'function') return toast('پوشه‌ی بایگانی هنوز ساخته نشده؛ چند لحظه بعد دوباره امتحان کنید.', 'info');
        switchTab('filemanager');
        setTimeout(() => { if (typeof fmOpen === 'function') fmOpen(rel); }, 80);
    }

    async function loadRuns() {
        const f = S.rf;
        const r = await post({action: 'runs', q: f.q, year: f.year, month: f.month, status: f.status, creator: f.creator, page: f.page, per: 12, focus: S.focus || ''});
        S.focus = null;
        if (!r.ok) return toast(r.error || 'خطا', 'error');
        S.runs = r.runs || [];
        S.rf.page = r.page;
        if (!S.run && S.runs.length) S.run = S.runs[0].id;
        const cur = S.runs.find(x => +x.id === +S.run); if (cur) S.cur = cur;
        const root = S.root;
        root.querySelector('#rc-rcount').textContent = r.total ? `(${fa(r.total)} مورد${r.all ? '' : ' — فقط مغایرت‌گیری‌های شما'})` : '';
        const ys = root.querySelector('#rc-ry');
        ys.innerHTML = '<option value="">همه‌ی سال‌ها</option>' + (r.years || []).map(y => `<option value="${y.y}" ${String(y.y) === String(f.year) ? 'selected' : ''}>${fa(y.y)} (${fa(y.n)})</option>`).join('');
        const cs = root.querySelector('#rc-rc');
        cs.classList.toggle('hidden', !(r.creators || []).length);
        cs.innerHTML = '<option value="">همه‌ی کاربران</option>' + (r.creators || []).map(c => `<option value="${c.id}" ${String(c.id) === String(f.creator) ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
        const mc = root.querySelector('#rc-rm');
        mc.innerHTML = f.year ? `<button data-m="" class="${f.month ? '' : 'on'}">همه‌ی ماه‌های ${fa(f.year)}</button>` + (r.months || []).map(m => `<button data-m="${m.m}" class="${String(m.m) === String(f.month) ? 'on' : ''}">${esc(m.fa)}<small>${fa(m.n)}</small></button>`).join('') : '';
        mc.querySelectorAll('[data-m]').forEach(b => b.onclick = () => { S.rf.month = b.dataset.m; S.rf.page = 1; loadRuns(); });
        const box = root.querySelector('#rc-runs');
        let lastM = '';
        box.innerHTML = S.runs.map(x => {
            const c = x.counts || {};
            const tot = +x.items_total || 0, done = Math.max(0, tot - (+x.open_count || 0)), pct = tot ? Math.round(done * 100 / tot) : 100;
            const head = x.month_fa !== lastM ? `<div class="rc-mhead"><i class="fas fa-calendar"></i>${esc(fa(x.month_fa))}</div>` : '';
            lastM = x.month_fa;
            return head + `<div class="rc-run ${+x.id === +S.run ? 'on' : ''}" data-run="${x.id}" role="button">
                <div><b title="${esc(x.title)}">${esc(fa(x.title))}</b><div class="m">${x.period_from ? esc(fa(x.period_from)) + ' تا ' + esc(fa(x.period_to || '')) + ' · ' : ''}${esc(fa(x.created_jalali))}${x.creator ? ' · ' + esc(x.creator) : ''}</div></div>
                <div class="pills"><span class="rc-pill r">${fa(c.DIFF || 0)} مغایر</span><span class="rc-pill w">${fa(c.ONLY_P || 0)} پاسارگاد</span><span class="rc-pill c">${fa(c.ONLY_S || 0)} سایت</span><span class="rc-pill p">${fa(c.MATCH || 0)} منطبق</span></div>
                <div class="pg"><div class="rc-prog"><i style="width:${pct}%"></i></div><div class="pct">${+x.open_count ? fa(x.open_count) + ' باز · ' + fa(pct) + '٪ بررسی‌شده' : '✓ همه بررسی شد'}</div></div>
                <div class="act">${x.archive ? `<span class="rc-ico" data-arch="${esc(x.archive)}" title="پوشه‌ی بایگانی"><i class="fas fa-folder-open"></i></span>` : ''}</div></div>`;
        }).join('') || `<div class="text-[12px] text-slate-400 text-center py-4">${f.q || f.year || f.status || f.creator ? 'موردی با این فیلترها پیدا نشد.' : 'هنوز مغایرت‌گیری‌ای انجام نشده.'}</div>`;
        box.querySelectorAll('[data-arch]').forEach(b => b.onclick = e => { e.stopPropagation(); openArchive(b.dataset.arch); });
        box.querySelectorAll('[data-run]').forEach(b => b.onclick = () => {
            S.run = +b.dataset.run; S.field = ''; S.cur = S.runs.find(x => +x.id === S.run) || null;
            box.querySelectorAll('.rc-run').forEach(x => x.classList.toggle('on', x === b)); loadItems();
            const v = root.querySelector('#rc-view'); if (v) setTimeout(() => v.scrollIntoView({behavior: 'smooth', block: 'start'}), 60);
        });
        const pages = Math.max(1, Math.ceil((r.total || 0) / (r.per || 12)));
        const pg = root.querySelector('#rc-rpg');
        pg.innerHTML = pages > 1 ? `<button class="rc-btn s sm" data-pg="${r.page - 1}" ${r.page <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-right"></i>قبلی</button>
            <span>صفحه‌ی ${fa(r.page)} از ${fa(pages)}</span><button class="rc-btn s sm" data-pg="${r.page + 1}" ${r.page >= pages ? 'disabled' : ''}>بعدی<i class="fas fa-chevron-left"></i></button>` : '';
        pg.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { S.rf.page = +b.dataset.pg; loadRuns(); });
    }

    async function loadItems() {
        const view = S.root.querySelector('#rc-view');
        if (!S.run) { view.innerHTML = ''; return; }
        const run = S.runs.find(x => +x.id === +S.run) || (S.cur && +S.cur.id === +S.run ? S.cur : {});
        const c = run.counts || {};
        if (!view.dataset.run || +view.dataset.run !== +S.run) {
            view.dataset.run = S.run;
            view.innerHTML = `<div class="rc-card">
                <div class="rc-kpi">${['DIFF', 'ONLY_P', 'ONLY_S', 'MATCH'].map(k => `<button data-cat="${k}"><small><i class="fas ${CAT[k][2]} ml-1"></i>${CAT[k][0]}</small><b>${fa(c[k] || 0)}</b></button>`).join('')}
                    <button data-cat=""><small>همه</small><b>${fa((c.DIFF || 0) + (c.ONLY_P || 0) + (c.ONLY_S || 0) + (c.MATCH || 0))}</b></button></div>
                ${Object.keys(c.fields || {}).length ? `<div class="rc-flt" id="rc-fields"><span class="text-[11px] text-slate-500">مغایرت در:</span>${Object.entries(c.fields).map(([f, n]) => `<button data-fld="${f}">${esc(FIELD[f] || f)} (${fa(n)})</button>`).join('')}</div>` : ''}
                <div class="rc-flt"><button data-st="OPEN">باز</button><button data-st="RESOLVED">بررسی‌شده</button><button data-st="">همه</button>
                    <input id="rc-q" class="rc-in" style="max-width:260px" placeholder="جستجو: شماره بیمه‌نامه، پلاک، بیمه‌گذار">
                    <span class="mr-auto flex gap-2 flex-wrap" id="rc-bulk"></span>
                    <button id="rc-xls" class="rc-btn s sm" style="border-radius:999px" title="فهرستِ مغایرت‌ها و تصمیم‌ها (قالبِ سایت)"><i class="fas fa-file-excel"></i>اکسلِ فهرستِ مغایرت‌ها</button>
                    <button id="rc-arch" class="rc-btn i sm" style="border-radius:999px" title="ورودی‌ها، خروجی‌های بیمه‌گر و اکسلِ سایت در بایگانی"><i class="fas fa-folder-open"></i>پوشه‌ی بایگانی</button>
                    ${isAdmin() ? '<button id="rc-del" class="rc-btn d sm" style="border-radius:999px"><i class="fas fa-trash"></i>حذفِ این مغایرت‌گیری</button>' : ''}</div>
                <div class="rc-flt" style="border-top:1px dashed #e2e8f0;padding-top:8px;margin-top:6px"><span class="text-[11px] text-slate-500"><i class="fas fa-file-circle-check text-emerald-600 ml-1"></i>خروجیِ اکسلِ بیمه‌گرِ جدید (همان اکسلِ ورودی با اطلاعاتِ به‌روزِ سایت):</span>
                    ${(run.src_files || []).length ? run.src_files.map(f => f.can ? `<button class="rc-btn g sm" style="border-radius:999px" data-corr="${f.idx}"><i class="fas fa-file-excel"></i>${esc(fa(f.label))}</button>` : `<span class="text-[11px] text-slate-400">${esc(f.name)} (فقط xlsx)</span>`).join('')
                        : '<span class="text-[11px] text-amber-700">فایلِ اصلیِ این مغایرت‌گیری روی سایت نیست (قبل از این امکان انجام شده). همان اکسلی را که از پاسارگاد گرفته بودید این‌جا بارگذاری کنید؛ تصمیم‌های قبلی سرِ جایش می‌ماند و نیازی به مغایرت‌گیریِ دوباره نیست.</span>'}
                    <button id="rc-attach" class="rc-btn ${(run.src_files || []).length ? 's' : 'p'} sm" style="border-radius:999px"><i class="fas fa-upload"></i>${(run.src_files || []).length ? 'افزودنِ فایلِ اصلیِ دیگر' : 'بارگذاریِ فایلِ اصلیِ بیمه‌گر (xlsx)'}</button>
                    <input type="file" data-uk-skip id="rc-attach-f" accept=".xlsx" multiple class="hidden"></div></div>
                <div id="rc-list" class="space-y-2"></div>`;
            view.querySelectorAll('[data-cat]').forEach(b => b.onclick = () => { S.cat = b.dataset.cat; S.sel = {}; if (S.cat !== 'DIFF') S.field = ''; loadItems(); });
            view.querySelectorAll('[data-fld]').forEach(b => b.onclick = () => { S.field = S.field === b.dataset.fld ? '' : b.dataset.fld; if (S.field) S.cat = 'DIFF'; loadItems(); });
            view.querySelectorAll('[data-st]').forEach(b => b.onclick = () => { S.status = b.dataset.st; loadItems(); });
            let t = null;
            view.querySelector('#rc-q').oninput = e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); loadItems(); }, 300); };
            view.querySelector('#rc-xls').onclick = () => { location.href = API + '?' + new URLSearchParams({action: 'export', run_id: S.run, category: S.cat, status: S.status, field: S.field, q: S.q}).toString(); };
            view.querySelectorAll('[data-corr]').forEach(b => b.onclick = () => corrected(+b.dataset.corr));
            view.querySelector('#rc-arch').onclick = () => openArchive(S.archive || run.archive);
            // وصل کردنِ فایلِ اصلیِ بیمه‌گر به مغایرت‌گیریِ قدیمی (بدونِ اجرای دوباره)
            const att = view.querySelector('#rc-attach'), attF = view.querySelector('#rc-attach-f');
            att.onclick = () => attF.click();
            attF.onchange = async () => {
                if (!attF.files.length) return;
                const fd = new FormData();
                fd.append('action', 'attach_src'); fd.append('run_id', S.run);
                [...attF.files].forEach(f => fd.append('files[]', f));
                att.disabled = true; att.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ بررسیِ فایل...';
                const r = await post(fd, true);
                attF.value = '';
                if (!r.ok) { att.disabled = false; att.innerHTML = '<i class="fas fa-upload"></i>بارگذاریِ فایلِ اصلیِ بیمه‌گر (xlsx)'; return toast(r.error || 'خطا', 'error'); }
                toast(`فایل وصل شد (${fa(r.matched)} بیمه‌نامه از ${fa(r.total)} در این مغایرت‌گیری). حالا روی دکمه‌ی خروجیِ اصلاح‌شده بزنید.`, 'success');
                S.focus = S.run; await loadRuns(); view.dataset.run = ''; loadItems();
            };
            const del = view.querySelector('#rc-del');
            if (del) del.onclick = async () => {
                if (!await ask('این مغایرت‌گیری و تصمیم‌هایش از فهرست حذف شود؟ (تغییراتی که روی سایت اعمال شده سرِ جایش می‌ماند)')) return;
                const r = await post({action: 'delete_run', run_id: S.run});
                if (!r.ok) return toast(r.error || 'خطا', 'error');
                S.run = null; view.dataset.run = ''; view.innerHTML = ''; loadRuns().then(() => { if (S.run) loadItems(); });
            };
        }
        view.querySelectorAll('[data-cat]').forEach(b => b.classList.toggle('on', b.dataset.cat === S.cat));
        view.querySelectorAll('[data-fld]').forEach(b => b.classList.toggle('on', b.dataset.fld === S.field));
        view.querySelectorAll('[data-st]').forEach(b => b.classList.toggle('on', b.dataset.st === S.status));
        const list = view.querySelector('#rc-list');
        list.innerHTML = '<div class="rc-card text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i></div>';
        const r = await post({action: 'items', run_id: S.run, category: S.cat, status: S.status, field: S.field, q: S.q});
        if (!r.ok) { list.innerHTML = `<div class="rc-info">${esc(r.error || 'خطا')}</div>`; return; }
        S.archive = r.archive || '';
        S.rows = r.rows || [];
        renderList();
    }

    // پیش‌نمایشِ تغییراتِ «خروجیِ اصلاح‌شده» و دانلود (نام: «نوع - عنوانِ مغایرت‌گیری»)
    // پرسش: فایل به مغایرت‌گیریِ قبلیِ بی‌فایل وصل شود یا مغایرت‌گیریِ تازه؟  → 'attach' | 'new' | null
    function chooseOld(m) {
        return new Promise(res => {
            const box = document.createElement('div');
            box.className = 'modal-overlay active'; box.style.zIndex = '1000002';
            box.innerHTML = `<div class="modal-content w-full max-w-md p-6 relative">
                <h3 class="font-black text-base mb-2"><i class="fas fa-link text-emerald-600 ml-1"></i>این فایل قبلاً مغایرت‌گیری شده</h3>
                <p class="text-[12px] text-slate-600 leading-7">بیمه‌نامه‌های این فایل همان‌هایی است که در مغایرت‌گیریِ <b>«${esc(fa(m.title))}»</b> (${esc(fa(m.created_jalali))}) بررسی شده‌اند (${fa(m.matched)} از ${fa(m.total)})، ولی فایلِ اصلیِ آن روی سایت نیست.</p>
                <p class="text-[11.5px] text-emerald-800 bg-emerald-50 rounded-xl p-3 mt-2 leading-6">با «وصل به همان» فایل به همان مغایرت‌گیری اضافه می‌شود، تصمیم‌های قبلی سرِ جایش می‌ماند و دکمه‌ی «خروجیِ اکسلِ بیمه‌گرِ جدید» ساخته می‌شود.</p>
                <button data-c="attach" class="w-full mt-4 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-black py-2.5 rounded-xl"><i class="fas fa-link ml-1"></i>وصل به همان مغایرت‌گیری (پیشنهادی)</button>
                <button data-c="new" class="w-full mt-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold py-2.5 rounded-xl">نه، مغایرت‌گیریِ تازه</button>
                <button data-c="" class="w-full mt-2 text-slate-400 hover:text-slate-600 text-xs py-1">انصراف</button></div>`;
            document.body.appendChild(box);
            box.querySelectorAll('[data-c]').forEach(b => b.onclick = () => { box.remove(); res(b.dataset.c || null); });
        });
    }

    // بعد از مغایرت‌گیری (یا وصل کردنِ فایل) زیرِ همان بخشِ ورود: دکمه‌های «خروجیِ اکسلِ بیمه‌گرِ جدید»
    function showResult(runId, files, attached) {
        const box = S.root.querySelector('#rc-result');
        if (!box) return;
        const ok = files.filter(f => f.can);
        box.innerHTML = `<div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3">
            <div class="text-[11.5px] font-bold text-emerald-800 mb-2"><i class="fas fa-circle-check ml-1"></i>${attached ? 'فایل به مغایرت‌گیریِ قبلی وصل شد.' : 'مغایرت‌گیری انجام شد.'}
              ${ok.length ? 'همان اکسلِ ورودی با اطلاعاتِ به‌روزِ سایت (همان ستون‌ها):' : 'خروجیِ اکسلِ بیمه‌گر فقط برای فایلِ xlsx ساخته می‌شود.'}</div>
            <div class="flex flex-wrap gap-2">${ok.map(f => `<button class="rc-btn g" data-rcorr="${f.idx}"><i class="fas fa-file-excel"></i>خروجیِ اکسلِ بیمه‌گرِ جدید — ${esc(fa(f.label))}</button>`).join('')}</div>
            <div class="text-[10.5px] text-slate-500 mt-2">بعد از تصمیم‌گیری روی مغایرت‌ها دوباره همین دکمه را بزنید؛ خروجی هر بار با آخرین اطلاعاتِ سایت ساخته می‌شود.</div></div>`;
        box.querySelectorAll('[data-rcorr]').forEach(b => b.onclick = () => { S.run = runId; corrected(+b.dataset.rcorr); });
    }

    async function corrected(idx) {
        const d = await post({action: 'corrected_preview', run_id: S.run, idx});
        if (!d.ok) return toast(d.error || 'خطا', 'error');
        const st = d.stats || {};
        const box = document.createElement('div');
        box.className = 'modal-overlay active'; box.style.zIndex = '1000002';
        box.innerHTML = `<div class="modal-content w-full max-w-4xl p-6 relative max-h-[90vh] overflow-y-auto">
            <button type="button" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl" data-x><i class="fas fa-times"></i></button>
            <h3 class="font-black text-base mb-1"><i class="fas fa-file-circle-check text-emerald-600 ml-1"></i>${esc(fa(d.name))}</h3>
            <p class="text-[11.5px] text-slate-500 leading-6 mb-3">همان فایلِ بیمه‌گر با همان ستون‌ها و قالب؛ فقط خانه‌های زیر عوض می‌شوند: برای بیمه‌نامه‌های کارکنان «کد پرسنلی»، «کد پرسنلی پرداخت کننده» و نام، نام خانوادگی و کد ملیِ پرداخت‌کننده از دارنده‌ی معرفی‌نامه، و هر فیلدی که در این مغایرت‌گیری تصمیمش گرفته شد با مقدارِ درستِ سایت.</p>
            <div class="flex flex-wrap gap-2 text-[11px] font-bold mb-3">
              <span class="rc-pill c">${fa(st.rows || 0)} ردیف در فایل</span><span class="rc-pill p">${fa(st.matched || 0)} ردیف در سایت پیدا شد</span>
              <span class="rc-pill n">${fa(st.payer || 0)} خانه‌ی پرداخت‌کننده/کد پرسنلی</span><span class="rc-pill r">${fa(st.fixed || 0)} خانه‌ی اصلاح‌شده</span></div>
            ${st.open ? `<p class="text-[11px] text-amber-800 bg-amber-50 rounded-xl p-2.5 mb-3"><i class="fas fa-triangle-exclamation ml-1"></i>${fa(st.open)} مغایرت هنوز تصمیم ندارد؛ آن خانه‌ها همان مقدارِ بیمه‌گر می‌مانند. بعد از تعیینِ «کدام درست است» دوباره خروجی بگیرید.</p>` : ''}
            ${d.changes.length ? `<div class="overflow-x-auto border rounded-xl"><table class="w-full text-[11px]"><thead class="bg-slate-50 text-slate-500"><tr><th class="p-2 text-right">ردیف</th><th class="p-2 text-right">بیمه‌نامه</th><th class="p-2 text-right">ستون</th><th class="p-2 text-right">قبلی</th><th class="p-2 text-right">جدید</th></tr></thead>
              <tbody>${d.changes.map(c => `<tr class="border-t"><td class="p-2">${fa(c.row)}</td><td class="p-2" dir="ltr">${esc(c.policy)}</td><td class="p-2 font-bold ${c.payer ? 'text-indigo-700' : 'text-rose-700'}">${esc(c.col)}</td>
                <td class="p-2 text-slate-400 line-through">${esc(fa(c.old))}</td><td class="p-2 font-bold text-emerald-700">${esc(fa(c.new))}</td></tr>`).join('')}</tbody></table></div>`
              : '<p class="text-xs text-slate-400 text-center py-4">تغییری لازم نیست؛ فایل همان فایلِ بیمه‌گر است.</p>'}
            <a href="${API}?${new URLSearchParams({action: 'corrected', run_id: S.run, idx})}" class="block text-center w-full mt-4 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-black py-2.5 rounded-xl"><i class="fas fa-download ml-1"></i>دانلودِ ${esc(fa(d.name))}</a></div>`;
        document.body.appendChild(box);
        box.querySelector('[data-x]').onclick = () => box.remove();
        box.addEventListener('click', e => { if (e.target === box) box.remove(); });
    }

    function renderBulk() {
        const box = S.root.querySelector('#rc-bulk');
        if (!box) return;
        let h = '';
        const open = S.rows.filter(x => x.status === 'OPEN');
        if (S.cat === 'DIFF' && S.field && open.length && can('edit')) {
            const apply = S.rows.some(x => (x.diffs || []).some(d => d.f === S.field && d.apply));
            h += `<button class="rc-btn i sm" data-bulk="SITE">همه‌ی «${esc(FIELD[S.field])}»ها: سایت درست است</button>`;
            if (apply) h += `<button class="rc-btn g sm" data-bulk="PASARGAD">همه: پاسارگاد درست است</button>`;
        }
        if (S.cat === 'ONLY_P' && open.length && isAdmin() && can('create')) h += `<button class="rc-btn g sm" id="rc-imp"><i class="fas fa-file-import"></i>ورودِ انتخاب‌شده‌ها به سایت</button>`;
        box.innerHTML = h;
        box.querySelectorAll('[data-bulk]').forEach(b => b.onclick = () => bulk(b.dataset.bulk));
        const imp = box.querySelector('#rc-imp');
        if (imp) imp.onclick = importSel;
    }
    async function bulk(choice) {
        const items = S.rows.filter(x => x.status === 'OPEN' && (x.diffs || []).some(d => d.f === S.field && !d.res && (choice === 'SITE' || d.apply)));
        if (!items.length) return toast('موردی نمانده.');
        if (!await ask(`برای ${fa(items.length)} بیمه‌نامه، «${FIELD[S.field]}»: ${choice === 'SITE' ? 'سایت درست است' : 'مقدارِ پاسارگاد روی سایت بنشیند'}؟`)) return;
        let ok = 0, bad = 0;
        for (const it of items) { const r = await post({action: 'resolve', item_id: it.id, field: S.field, choice}); r.ok ? ok++ : bad++; }
        toast(`${fa(ok)} مورد انجام شد${bad ? '، ' + fa(bad) + ' ناموفق' : ''}.`, bad ? 'error' : 'success');
        loadRuns(); loadItems();
    }
    async function importSel() {
        const ids = Object.keys(S.sel).filter(k => S.sel[k]).map(Number);
        if (!ids.length) return toast('ردیفی انتخاب نشده (تیکِ کنارِ هر ردیف).', 'error');
        if (!await ask(`${fa(ids.length)} ردیف با سازوکارِ «ورودِ بایگانی» (شرکتی با نامه، کارکنان با معرفی‌نامه و سهمیه؛ مالی: فقط حق بیمه) به سایت وارد شود؟ از «ورودِ بایگانی قبلی ← دسته‌ها» قابلِ برگرداندن است.`)) return;
        const r = await post({action: 'import', item_ids: ids});
        if (!r.ok) return toast(r.error || 'خطا', 'error');
        toast(`وارد شد (دسته‌ی ${fa(r.batch_id)}): ${fa(r.company)} شرکتی، ${fa(r.personnel)} کارکنان، ${fa(r.endorsements)} الحاقیه.`, 'success');
        S.sel = {}; loadRuns(); loadItems();
    }

    const val = (f, v) => v === '' || v === null || v === undefined ? '<span class="text-slate-300">خالی</span>' : MONEY_F[f] ? money(v) : f === 'plate' && isPT(v) ? plate(v) : esc(fa(v));
    function head(x) {
        const st = x.status === 'OPEN' ? '<span class="rc-pill n">باز</span>' : '<span class="rc-pill p"><i class="fas fa-check"></i>بررسی‌شده</span>';
        return `<div class="rc-ih"><div class="flex flex-wrap gap-2 items-center">
                ${x.category === 'ONLY_P' && x.status === 'OPEN' && isAdmin() ? `<input type="checkbox" data-sel="${x.id}" ${S.sel[x.id] ? 'checked' : ''}>` : ''}
                <span class="rc-pill ${CAT[x.category][1]}">${CAT[x.category][0]}</span><b>${esc(fa(x.policy_number || ''))}</b>
                <span>${x.label && isPT(x.label) ? plate(x.label.split(' · ')[0]) + ' · ' + esc(x.label.split(' · ').slice(1).join(' · ')) : esc(fa(x.label || ''))}</span>
                ${x.type_fa ? `<span class="rc-pill n">${esc(x.type_fa)}</span>` : ''}${x.issue_date ? `<span class="text-slate-500">صدور ${esc(fa(x.issue_date))}</span>` : ''}${x.source ? `<span class="rc-pill n">${x.source === 'C' ? 'شرکتی' : 'کارکنان'}</span>` : ''}</div>
                <div class="flex gap-1 items-center">${st}${x.note ? `<span class="text-[10.5px] text-slate-500">${esc(x.note)}</span>` : ''}</div></div>`;
    }
    function renderList() {
        const list = S.root.querySelector('#rc-list');
        renderBulk();
        if (!S.rows.length) { list.innerHTML = '<div class="rc-card text-center text-slate-400 text-[12px]">موردی نیست.</div>'; return; }
        if (S.cat === 'MATCH') {
            list.innerHTML = `<div class="rc-card"><div class="overflow-x-auto"><table class="rc-tb"><thead><tr><th>بیمه‌نامه</th><th>نوع</th><th>صدور</th><th>پلاک / شاسی</th><th>بیمه‌گذار</th></tr></thead><tbody>
                ${S.rows.map(x => `<tr><td>${esc(fa(x.policy_number))}</td><td>${esc(x.type_fa)}</td><td>${esc(fa(x.issue_date))}</td><td>${plate((x.label || '').split(' · ')[0])}</td><td>${esc(x.insured || '')}</td></tr>`).join('')}</tbody></table></div></div>`;
            return;
        }
        list.innerHTML = S.rows.map(x => {
            let body = '';
            if (x.category === 'DIFF') {
                body = `<table class="rc-dt no-count"><thead><tr><th>فیلد</th><th>سایت</th><th>پاسارگاد</th><th>تصمیم</th></tr></thead><tbody>${(x.diffs || []).map(d => {
                    const done = d.res ? `<span class="rc-pill ${RES[d.res][1]}">${RES[d.res][0]}${d.res_value ? ': ' + esc(fa(d.res_value)) : ''}</span>` : '';
                    const acts = d.res || !can('edit') ? done : `<div class="flex flex-wrap gap-1"><button class="rc-btn i sm" data-res="SITE" data-it="${x.id}" data-f="${d.f}">سایت درست است</button>
                        ${d.apply ? `<button class="rc-btn g sm" data-res="PASARGAD" data-it="${x.id}" data-f="${d.f}">پاسارگاد درست است</button>` : ''}
                        ${d.apply && d.f !== 'endo' ? `<button class="rc-btn s sm" data-edit="${x.id}" data-f="${d.f}"><i class="fas fa-pen"></i>ویرایش</button>` : ''}</div>
                        ${!d.apply ? '<div class="text-[10px] text-slate-400 mt-1">از خودِ بیمه‌نامه/الحاقیه‌ها درست کنید</div>' : ''}<div data-ed="${x.id}-${d.f}"></div>`;
                    return `<tr><td class="f">${esc(d.label)}</td><td class="v">${val(d.f, d.site)}</td><td class="v pas">${val(d.f, d.pas)}</td><td class="a">${acts}</td></tr>`;
                }).join('')}</tbody></table>`;
            } else if (x.category === 'ONLY_P') {
                const p = x.pas;
                body = p ? `<div class="mt-2 text-slate-600 leading-7">${esc(p.type_fa)} · بیمه‌گذار: <b>${esc(p.insured)}</b>${p.target === 'P' ? ` · کارکنان: ${esc(p.holder)}` : ' · شرکتی'}${p.contract ? ' · طرفِ قرارداد: ' + esc(p.contract) : ''}<br>
                    ${p.plate_text ? plate(p.plate_text) : ''} <span class="font-mono text-[10.5px]">${esc(p.vin || '')}</span> · حق بیمه ${money(p.premium)} · ${esc(fa(p.start))} تا ${esc(fa(p.end))}</div>` : '';
                if ((x.pas_endos || []).length) body += `<div class="mt-1 flex flex-wrap gap-1">${x.pas_endos.map(e => `<span class="rc-pill w">الحاقیه ${fa(e.no)} · ${esc(e.kind)} · ${money(e.gross)}</span>`).join('')}</div>`;
            }
            if (x.category !== 'DIFF' && can('edit')) {
                body += `<div class="mt-2 flex flex-wrap gap-2 items-center">${x.status === 'OPEN'
                    ? `<input class="rc-in" style="max-width:320px" data-note="${x.id}" placeholder="${x.category === 'ONLY_S' ? 'توضیح: مثلاً با کدِ نماینده‌ی دیگر / بعد از این تاریخ' : 'توضیح (اختیاری)'}"><button class="rc-btn i sm" data-mark="${x.id}"><i class="fas fa-check"></i>بررسی شد</button>`
                    : `<button class="rc-btn s sm" data-reopen="${x.id}">باز کردنِ دوباره</button>`}</div>`;
            }
            return `<div class="rc-item ${x.status === 'OPEN' ? '' : 'done'}">${head(x)}${body}</div>`;
        }).join('') + (S.rows.length >= 2000 ? '<div class="text-[11px] text-slate-500">۲۰۰۰ ردیفِ اول نمایش داده شد؛ با فیلتر محدود کنید.</div>' : '');
        list.querySelectorAll('[data-sel]').forEach(c => c.onchange = () => { S.sel[c.dataset.sel] = c.checked; });
        list.querySelectorAll('[data-res]').forEach(b => b.onclick = async () => {
            b.disabled = true;
            const r = await post({action: 'resolve', item_id: +b.dataset.it, field: b.dataset.f, choice: b.dataset.res});
            if (!r.ok) { b.disabled = false; return toast(r.error || 'خطا', 'error'); }
            toast(b.dataset.res === 'PASARGAD' ? (r.created !== undefined ? fa(r.created) + ' الحاقیه ثبت شد.' : 'مقدارِ پاسارگاد روی سایت نشست.') : 'ثبت شد.', 'success');
            refreshItem(+b.dataset.it, r.status);
        });
        list.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => {
            const box = list.querySelector(`[data-ed="${b.dataset.edit}-${b.dataset.f}"]`);
            const it = S.rows.find(x => x.id === +b.dataset.edit), d = (it.diffs || []).find(z => z.f === b.dataset.f);
            box.innerHTML = `<div class="flex gap-1 mt-1"><input class="rc-in" value="${esc(fa(d.pas))}" placeholder="${b.dataset.f === 'plate' ? 'مثلاً ۲۱ایران - ۹۸۷ ع ۴۱' : ''}"><button class="rc-btn p sm">ذخیره</button></div>`;
            box.querySelector('button').onclick = async () => {
                const r = await post({action: 'resolve', item_id: +b.dataset.edit, field: b.dataset.f, choice: 'CUSTOM', value: box.querySelector('input').value});
                if (!r.ok) return toast(r.error || 'خطا', 'error');
                toast('روی سایت ذخیره شد.', 'success');
                refreshItem(+b.dataset.edit, r.status);
            };
        });
        list.querySelectorAll('[data-mark]').forEach(b => b.onclick = async () => {
            const note = (list.querySelector(`[data-note="${b.dataset.mark}"]`) || {}).value || '';
            const r = await post({action: 'mark', item_id: +b.dataset.mark, status: 'RESOLVED', note});
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            refreshItem(+b.dataset.mark, 'RESOLVED');
        });
        list.querySelectorAll('[data-reopen]').forEach(b => b.onclick = async () => {
            const r = await post({action: 'mark', item_id: +b.dataset.reopen, status: 'OPEN', note: ''});
            if (r.ok) refreshItem(+b.dataset.reopen, 'OPEN');
        });
    }
    async function refreshItem(id, status) {
        // فقط همان ردیف دوباره خوانده می‌شود تا جای اسکرول عوض نشود
        const r = await post({action: 'items', run_id: S.run, category: S.cat, status: '', field: S.field, q: S.q});
        const fresh = (r.rows || []).find(x => x.id === id);
        const i = S.rows.findIndex(x => x.id === id);
        if (i >= 0) { if (fresh) S.rows[i] = fresh; else S.rows[i].status = status; }
        renderList();
        loadRuns();
    }

    window.Recon = {init};
})();
