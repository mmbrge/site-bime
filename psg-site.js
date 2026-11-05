// فایل: psg-site.js
// «سایتِ بیمه‌گر ← پاسارگاد» در پنل: صفحه‌ی «آیا مطمئنید؟» و انتقال به خودِ سامانه (پروکسی با نوارِ ابزار)،
// پیش‌فرض‌های پیش‌نویس (بی‌نهایت) با نسبت به شرکت / قرارداد و حالتِ تکی / گروهی، قراردادِ پیش‌فرضِ مالیِ هر حالت،
// و انتخابِ ردیف‌های درخواست: «بنشان در سایتِ بیمه‌گر» (فرمِ بازِ نوار) یا پیش‌نویسِ تکی / گروهی.
// API: api/psgx_bar_actions.php (ctx، fin_save، row_search) و api/psg_issue_actions.php (dp_*)
(function () {
    'use strict';
    if (window.PsgSite) return;
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => FA.indexOf(d));
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function call(file, b) { try { return await (await fetch('api/' + file, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(b)})).json(); } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; } }
    const bar = (a, b) => call('psgx_bar_actions.php', Object.assign({action: a}, b || {}));
    const iss = (a, b) => call('psg_issue_actions.php', Object.assign({action: a}, b || {}));
    const S = {root: null, tab: 'go', sess: 's', live: null, data: null, rows: [], pick: {}};
    let chan = null;
    try { chan = new BroadcastChannel('psgx'); chan.onmessage = e => { const m = e.data || {}; if (m.from !== 'bar') return; if (m.type === 'open' || m.type === 'pong') { S.live = {page: m.page, kind: m.kind, at: Date.now()}; liveSoft(); } if (m.type === 'presets' && S.tab === 'presets') render(); }; } catch (e) {}
    const send = m => { try { chan && chan.postMessage(Object.assign({from: 'panel'}, m)); return !!chan; } catch (e) { return false; } };

    function css() {
        if (document.getElementById('psgs-css')) return;
        const s = document.createElement('style'); s.id = 'psgs-css';
        s.textContent = `.psgs-hero{border-radius:24px;padding:20px 22px;color:#fff;background:radial-gradient(circle at 15% 20%,rgba(56,189,248,.35),transparent 45%),linear-gradient(125deg,#0f172a,#0c4a6e 50%,#4338ca);position:relative;overflow:hidden}
        .psgs-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px}.psgs-tab{padding:7px 14px;border-radius:12px;font-size:12px;font-weight:800;color:#e0f2fe;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15)}.psgs-tab.on{background:#fff;color:#0c4a6e}
        .psgs-card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:16px}.psgs-in{border:1px solid #e2e8f0;border-radius:12px;padding:7px 10px;font-size:12px;background:#fff}
        .psgs-btn{border-radius:12px;padding:8px 14px;font-size:12px;font-weight:800;display:inline-flex;gap:6px;align-items:center}
        .psgs-go{max-width:720px;margin:0 auto;text-align:center}.psgs-go .ic{width:84px;height:84px;margin:0 auto 12px;border-radius:26px;display:grid;place-items:center;font-size:34px;color:#fff;background:linear-gradient(135deg,#0ea5e9,#6366f1);box-shadow:0 20px 40px -18px rgba(79,70,229,.7)}
        .psgs-opt{display:flex;gap:10px;align-items:flex-start;text-align:right;border:2px solid #e2e8f0;border-radius:16px;padding:12px;cursor:pointer}.psgs-opt.on{border-color:#0ea5e9;background:#f0f9ff}
        .psgs-li{border:1px solid #e2e8f0;border-radius:16px;padding:12px;background:#f8fafc}.psgs-chip{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:10.5px;font-weight:800}
        .psgs-tbl{width:100%;font-size:11.5px;border-collapse:collapse}.psgs-tbl th{background:#f1f5f9;padding:7px;text-align:right;color:#475569}.psgs-tbl td{padding:7px;border-bottom:1px solid #f1f5f9}`;
        document.head.appendChild(s);
    }
    async function init(root) { css(); S.root = root || S.root; if (!S.root) return; send({type: 'ping'}); render(); }
    function shell(inner) {
        const T = [['go', 'fa-right-to-bracket', 'ورود به سایتِ بیمه‌گر'], ['rows', 'fa-list-check', 'ردیف‌ها: بنشان / گروهی'], ['presets', 'fa-sliders', 'پیش‌فرض‌های پیش‌نویس'], ['fin', 'fa-sack-dollar', 'مالی و قراردادها']];
        return `<div class="psgs-hero mb-3">
            <p class="text-[11px] opacity-80"><i class="fas fa-globe ml-1"></i>سایت بیمه‌گر <i class="fas fa-angle-left mx-1"></i> پاسارگاد</p>
            <div class="flex flex-wrap items-center justify-between gap-3 mt-1"><h2 class="text-xl font-black"><i class="fas fa-building-shield ml-1"></i>سایتِ بیمه‌گر — پاسارگاد</h2><span data-live></span></div>
            <p class="text-[11.5px] opacity-85 mt-1">خودِ سامانه‌ی پاسارگاد از راهِ سرورِ پنل، با نوارِ ابزار (پیش‌فرض‌ها، پر کردن از ردیف، اقساطِ خودکار، اعلان‌ها و بازگشت به پنل).</p>
            <div class="psgs-tabs">${T.map(([k, i, t]) => `<button class="psgs-tab ${S.tab === k ? 'on' : ''}" data-t="${k}"><i class="fas ${i} ml-1"></i>${t}</button>`).join('')}
                <button class="psgs-tab" data-goto="psg-drafts"><i class="fas fa-file-pen ml-1"></i>پیش‌نویس‌ها</button><button class="psgs-tab" data-goto="tiss"><i class="fas fa-file-circle-check ml-1"></i>صادره‌ها</button></div></div>${inner}`;
    }
    function liveHtml() { const l = S.live; return l && Date.now() - l.at < 600000 ? `<span class="psgs-chip" style="background:rgba(16,185,129,.2);color:#a7f3d0"><i class="fas fa-circle text-[7px]"></i>سایتِ بیمه‌گر باز است${l.kind === 'draft' ? ' · فرمِ پیش‌نویس' : l.kind === 'finance' ? ' · دریافتِ حق بیمه' : ''}</span>` : ''; }
    function liveSoft() { const el = S.root && S.root.querySelector('[data-live]'); if (el) el.innerHTML = liveHtml(); }
    async function render() {
        const fn = {go: goHtml, rows: rowsHtml, presets: presetsHtml, fin: finHtml}[S.tab] || goHtml;
        S.root.innerHTML = shell('<div class="psgs-card text-center text-slate-400 text-xs p-8"><i class="fas fa-spinner fa-spin"></i></div>');
        const inner = await fn();
        S.root.innerHTML = shell(inner);
        liveSoft();
        S.root.querySelectorAll('[data-t]').forEach(b => b.onclick = () => { S.tab = b.dataset.t; render(); });
        S.root.querySelectorAll('[data-goto]').forEach(b => b.onclick = () => { if (b.dataset.goto === 'tiss') window.__psgdKind = 'tiss'; switchTab('psg-drafts'); });
        ({go: goBind, rows: rowsBind, presets: presetsBind, fin: finBind}[S.tab] || (() => {}))();
    }

    // ---------- ورود (آیا مطمئنید؟) ----------
    async function goHtml() {
        return `<div class="psgs-card"><div class="psgs-go">
            <div class="ic"><i class="fas fa-up-right-from-square"></i></div>
            <h3 class="text-lg font-black text-slate-800">به سایتِ بیمه‌گر (سامانه‌ی پاسارگاد) منتقل شوید؟</h3>
            <p class="text-[12px] text-slate-500 mt-2 leading-6">صفحه‌ی خودِ سامانه در زبانه‌ی تازه باز می‌شود؛ هر کاری آن‌جا بکنید مستقیم در سامانه‌ی پاسارگاد ثبت می‌شود. نوارِ بالای صفحه به این پنل وصل است (اعلان‌ها، پیش‌فرض‌ها، پر کردن از ردیف، اقساطِ خودکار) و با «بازگشت به پنل» برمی‌گردید.</p>
            <div class="grid md:grid-cols-2 gap-3 mt-4">
                <label class="psgs-opt ${S.sess === 's' ? 'on' : ''}" data-sess="s"><input type="radio" name="psgs-s" ${S.sess === 's' ? 'checked' : ''}><span><b class="text-[12.5px] text-slate-800">نشستِ سرور (پیشنهادی)</b><span class="block text-[11px] text-slate-500 mt-1">همان نشستی که استعلام‌ها و پیش‌نویس‌ها دارند؛ بدونِ ورودِ دوباره. «خروج» در سامانه نزنید.</span></span></label>
                <label class="psgs-opt ${S.sess === 'o' ? 'on' : ''}" data-sess="o"><input type="radio" name="psgs-s" ${S.sess === 'o' ? 'checked' : ''}><span><b class="text-[12.5px] text-slate-800">نشستِ جدا</b><span class="block text-[11px] text-slate-500 mt-1">خودتان با نام‌کاربری و کدِ تصویر وارد می‌شوید؛ به نشستِ سرور کاری ندارد.</span></span></label>
            </div>
            <div class="flex flex-wrap justify-center gap-2 mt-5">
                <button class="psgs-btn text-white" style="background:linear-gradient(135deg,#0284c7,#4f46e5)" data-yes><i class="fas fa-check"></i>بله، مطمئنم — منتقل شو</button>
                <button class="psgs-btn bg-slate-100 text-slate-600" data-no><i class="fas fa-xmark"></i>انصراف</button></div>
            <p class="text-[10.5px] text-slate-400 mt-3">بزرگنماییِ پیش‌فرضِ صفحه ۸۰٪ است (از نوار عوض می‌شود). <a class="underline" href="psgx/index.php?__u=%2F~${S.sess}%2Fcins%2F" target="_blank" rel="noopener">اگر باز نشد</a></p>
        </div></div>`;
    }
    function goBind() {
        const R = S.root;
        R.querySelectorAll('[data-sess]').forEach(o => o.onclick = () => { S.sess = o.dataset.sess; render(); });
        R.querySelector('[data-yes]').onclick = () => { window.open('psgx/index.php/~' + S.sess + '/cins/', 'psgx_site'); };
        R.querySelector('[data-no]').onclick = () => { if (window.switchTab) switchTab('psg-drafts'); };
    }

    // ---------- ردیف‌ها ----------
    async function rowsHtml() {
        return `<div class="psgs-card">
            <div class="flex flex-wrap gap-2 items-center mb-3"><input class="psgs-in" style="min-width:300px;flex:1" data-q placeholder="جستجوی ردیف: شماره‌ی درخواست، پلاک، شاسی/VIN، موتور، نامِ شرکت یا خودرو" value="${esc(S.q || '')}">
                <select class="psgs-in" data-k><option value="">ثالث و بدنه</option><option value="third" ${S.k === 'third' ? 'selected' : ''}>ثالث</option><option value="body" ${S.k === 'body' ? 'selected' : ''}>بدنه</option></select>
                <button class="psgs-btn bg-sky-600 text-white" data-s><i class="fas fa-magnifying-glass"></i>جستجو</button></div>
            <div data-res>${rowsTable()}</div>
            <div class="flex flex-wrap gap-2 items-center mt-3"><button class="psgs-btn text-white" style="background:#0f766e" data-grp><i class="fas fa-layer-group"></i>پیش‌نویسِ گروهیِ انتخاب‌شده‌ها (<span data-n>${fa(Object.keys(S.pick).length)}</span>)</button>
                <span class="text-[10.5px] text-slate-400">در گروهی می‌توانید منبع را انتخاب کنید: خودکار (نمونه/الگو + پیش‌فرضِ شرکت)، یک پیش‌فرض، یک نمونه، یا دستی.</span></div>
            <p class="text-[10.5px] text-slate-400 mt-2"><i class="fas fa-circle-info ml-1"></i>«بنشان در سایت»: اطلاعاتِ ردیف در فرمِ پیش‌نویسی که در سایتِ بیمه‌گر باز است می‌نشیند (همان مرورگر؛ نوار باید باز باشد). ثبت با خودتان.</p></div>`;
    }
    function rowsTable() {
        if (!S.rows.length) return '<p class="text-center text-slate-400 text-xs py-6">ردیفی را جستجو کنید.</p>';
        return `<div style="max-height:60vh;overflow:auto"><table class="psgs-tbl"><thead><tr><th></th><th>درخواست</th><th>شرکت</th><th>خودرو / پلاک</th><th>شاسی / موتور</th><th>رشته</th><th>پاسارگاد</th><th></th></tr></thead><tbody>
            ${S.rows.map(r => `<tr><td><input type="checkbox" data-p="${r.id}" ${S.pick[r.id] ? 'checked' : ''} ${r.status === 'ISSUED' ? 'disabled' : ''}></td><td>#${fa(r.request)}</td><td>${esc(r.company || '—')}</td>
                <td>${esc(r.car || '—')}<div class="text-[10px] text-slate-400">${esc(fa(r.plate))}</div></td><td dir="ltr" class="font-mono text-[10.5px]" style="text-align:right">${esc(r.chassis || '')}<div class="text-slate-400">${esc(r.engine || '')}</div></td>
                <td>${r.kind === 'body' ? 'بدنه' : 'ثالث'}</td><td>${r.draft ? `<span class="psgs-chip" style="background:#e0f2fe;color:#075985">${esc(fa(r.draft))}</span>` : '—'}${r.status === 'ISSUED' ? ' <span class="psgs-chip" style="background:#dcfce7;color:#166534">صادر شده</span>' : ''}</td>
                <td class="whitespace-nowrap"><button class="psgs-btn bg-indigo-50 text-indigo-700" style="padding:4px 9px" data-fill="${r.id}"><i class="fas fa-wand-magic-sparkles"></i>بنشان در سایت</button>
                    ${window.PsgIssueUI && r.status !== 'ISSUED' ? `<button class="psgs-btn bg-teal-50 text-teal-700" style="padding:4px 9px" data-one="${r.id}"><i class="fas fa-file-signature"></i>پیش‌نویس</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
    }
    function rowsBind() {
        const R = S.root;
        const go = async () => { S.q = R.querySelector('[data-q]').value.trim(); S.k = R.querySelector('[data-k]').value; if (S.q.length < 2) return toast('دست‌کم دو نویسه بنویسید.', 'warning'); const r = await bar('row_search', {q: S.q, kind: S.k}); if (!r.ok) return toast(r.error || 'خطا', 'error'); S.rows = r.rows || []; R.querySelector('[data-res]').innerHTML = rowsTable(); bindRows(); };
        R.querySelector('[data-s]').onclick = go;
        R.querySelector('[data-q]').onkeydown = e => { if (e.key === 'Enter') go(); };
        R.querySelector('[data-grp]').onclick = () => {
            const ids = Object.keys(S.pick); if (!ids.length) return toast('ردیفی انتخاب نشده.', 'warning');
            if (!window.PsgIssueUI) return toast('پنلِ پیش‌نویسِ پاسارگاد در دسترس نیست.', 'error');
            PsgIssueUI.group(ids.map(id => ({id: +id, title: S.pick[id]})), {onDone: () => {}});
        };
        bindRows();
        function bindRows() {
            R.querySelectorAll('[data-p]').forEach(c => c.onchange = () => { const r = S.rows.find(x => x.id === +c.dataset.p); if (c.checked) S.pick[c.dataset.p] = `#${r.request} · ${r.car || ''} · ${r.plate || ''}`; else delete S.pick[c.dataset.p]; R.querySelector('[data-n]').textContent = fa(Object.keys(S.pick).length); });
            R.querySelectorAll('[data-fill]').forEach(b => b.onclick = () => { if (!send({type: 'fill', plate_id: +b.dataset.fill})) return toast('مرورگر این ارتباط را پشتیبانی نمی‌کند.', 'error'); toast(S.live && S.live.kind === 'draft' ? 'فرستاده شد؛ در زبانه‌ی سایتِ بیمه‌گر ببینید.' : 'فرستاده شد؛ اگر فرمِ پیش‌نویس در سایتِ بیمه‌گر باز نیست، اول بازش کنید.', 'info'); });
            R.querySelectorAll('[data-one]').forEach(b => b.onclick = () => PsgIssueUI.open(+b.dataset.one, {}));
        }
    }

    // ---------- پیش‌فرض‌ها ----------
    async function presetsHtml() {
        const r = await iss('dp_list'); S.data = r;
        if (!r.ok) return `<div class="psgs-card text-rose-600 text-sm">${esc(r.error)}</div>`;
        const P = r.presets || [];
        return `<div class="psgs-card mb-3"><div class="flex flex-wrap items-center gap-2"><b class="text-slate-700 text-[13px]"><i class="fas fa-sliders ml-1 text-violet-600"></i>پیش‌فرض‌های پیش‌نویس (${fa(P.length)})</b><span class="flex-1"></span>
            <button class="psgs-btn bg-violet-600 text-white" data-new><i class="fas fa-plus"></i>ساختن از روی یک پیش‌نویس</button></div>
            <p class="text-[11px] text-slate-500 mt-2 leading-6">هر پیش‌فرض فقط خانه‌هایی را دارد که انتخاب کرده‌اید (پلاک، شاسی، موتور، تاریخ‌ها و بیمه‌گذار همیشه از خودِ ردیف). برای هر شرکت یا قرارداد می‌توانید یک پیش‌فرض برای حالتِ تکی و یکی برای گروهی بگذارید؛ «خودکار» به این ترتیب انتخاب می‌کند: شرکت ← قرارداد ← همه. در نوارِ سایتِ بیمه‌گر هم از همان صفحه پیش‌فرض می‌سازید.</p></div>
            <div data-new-box></div>
            ${P.map(p => presetCard(p)).join('') || '<div class="psgs-card text-center text-slate-400 text-xs">هنوز پیش‌فرضی نیست.</div>'}`;
    }
    function tName(t) {
        const D = S.data || {}; const M = {any: 'هر دو حالت', single: 'تکی', group: 'گروهی'}[t.mode] || '';
        if (t.t === 'company') { const c = (D.companies || []).find(x => +x.id === +t.id); return `شرکت: ${c ? c.name : '#' + t.id} · ${M}`; }
        if (t.t === 'contract') { const c = (D.contracts || []).find(x => +x.id === +t.id); return `قرارداد: ${c ? c.name : '#' + t.id} · ${M}`; }
        return `همه · ${M}`;
    }
    function presetCard(p) {
        const n = Object.keys(p.values || {}).length;
        return `<div class="psgs-li mb-2" data-pid="${esc(p.id)}"><div class="flex flex-wrap items-center gap-2"><b class="text-[13px] text-slate-800">${esc(p.name)}</b>
            <span class="psgs-chip" style="background:${p.kind === 'body' ? '#ede9fe;color:#6d28d9' : '#e0f2fe;color:#075985'}">${p.kind === 'body' ? 'بدنه' : 'ثالث'}</span><span class="psgs-chip" style="background:#f1f5f9;color:#475569">${fa(n)} خانه</span>
            <span class="text-[10.5px] text-slate-400">${esc(p.by || '')} · ${esc(fa(p.at || ''))}</span><span class="flex-1"></span>
            <button class="psgs-btn bg-white border border-slate-200 text-slate-600" style="padding:4px 9px" data-fields><i class="fas fa-list"></i>خانه‌ها</button>
            <button class="psgs-btn bg-white border border-rose-200 text-rose-600" style="padding:4px 9px" data-del><i class="fas fa-trash"></i></button></div>
            <div class="flex flex-wrap gap-1 mt-2">${(p.targets || []).map((t, i) => `<span class="psgs-chip" style="background:#ecfdf5;color:#047857">${esc(tName(t))}<button data-rt="${i}" title="برداشتن">✕</button></span>`).join('') || '<span class="text-[10.5px] text-amber-600">به هیچ شرکت/قراردادی نسبت داده نشده (فقط با انتخابِ دستی به کار می‌رود).</span>'}</div>
            <div class="flex flex-wrap gap-2 items-center mt-2 text-[11px]"><span class="text-slate-500">نسبت دادن به:</span>
                <select class="psgs-in" data-tt><option value="company">شرکت</option><option value="contract">قرارداد</option><option value="all">همه (پیش‌فرضِ کلی)</option></select>
                <select class="psgs-in" data-tid style="max-width:240px">${(S.data.companies || []).map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
                <select class="psgs-in" data-tm><option value="any">هر دو حالت</option><option value="single">فقط تکی</option><option value="group">فقط گروهی</option></select>
                <button class="psgs-btn bg-emerald-600 text-white" style="padding:5px 10px" data-at><i class="fas fa-plus"></i>افزودن</button></div>
            <div data-fl class="hidden mt-2 text-[11px] text-slate-600"></div></div>`;
    }
    function savePreset(p, patch) {
        const o = Object.assign({id: p.id, name: p.name, kind: p.kind, values: p.values, targets: p.targets || []}, patch);
        return iss('dp_save', o).then(r => { if (!r.ok) toast(r.error || 'ذخیره نشد', 'error'); else { toast('ذخیره شد.', 'success'); send({type: 'presets'}); } return r; });
    }
    function presetsBind() {
        const R = S.root, P = (S.data && S.data.presets) || [];
        R.querySelectorAll('[data-pid]').forEach(card => {
            const p = P.find(x => x.id === card.dataset.pid); if (!p) return;
            const tt = card.querySelector('[data-tt]'), tid = card.querySelector('[data-tid]');
            tt.onchange = () => { const L = tt.value === 'company' ? S.data.companies : tt.value === 'contract' ? S.data.contracts : []; tid.innerHTML = (L || []).map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join(''); tid.style.display = tt.value === 'all' ? 'none' : ''; };
            card.querySelector('[data-at]').onclick = async () => { const t = {t: tt.value, id: tt.value === 'all' ? 0 : +tid.value, mode: card.querySelector('[data-tm]').value}; if (t.t !== 'all' && !t.id) return toast('انتخاب کنید.', 'warning'); const r = await savePreset(p, {targets: (p.targets || []).concat([t])}); if (r.ok) render(); };
            card.querySelectorAll('[data-rt]').forEach(b => b.onclick = async () => { const T = (p.targets || []).slice(); T.splice(+b.dataset.rt, 1); const r = await savePreset(p, {targets: T}); if (r.ok) render(); });
            card.querySelector('[data-del]').onclick = async () => { if (!confirm('پیش‌فرضِ «' + p.name + '» پاک شود؟')) return; const r = await iss('dp_delete', {id: p.id}); if (r.ok) { send({type: 'presets'}); render(); } else toast(r.error, 'error'); };
            card.querySelector('[data-fields]').onclick = () => { const f = card.querySelector('[data-fl]'); f.classList.toggle('hidden'); f.innerHTML = Object.entries(p.values || {}).map(([k, v]) => `<div class="flex gap-2 border-b border-slate-100 py-1"><code dir="ltr" class="text-slate-400 text-[10px]">${esc(k)}</code><b>${esc(fa(Array.isArray(v) ? v.join('، ') : v))}</b></div>`).join(''); };
        });
        R.querySelector('[data-new]').onclick = () => {
            const box = R.querySelector('[data-new-box]');
            box.innerHTML = `<div class="psgs-card mb-3"><div class="flex flex-wrap gap-2 items-center"><select class="psgs-in" data-nk><option value="third">ثالث</option><option value="body">بدنه</option></select>
                <input class="psgs-in" dir="ltr" style="width:200px" data-nn placeholder="شماره‌ی پیش‌نویس: 1/49357/1405/523"><button class="psgs-btn bg-sky-600 text-white" data-nr><i class="fas fa-download"></i>خواندنِ خانه‌ها از سامانه</button></div><div data-nf class="mt-2"></div></div>`;
            box.querySelector('[data-nr]').onclick = async () => {
                const kind = box.querySelector('[data-nk]').value, no = en(box.querySelector('[data-nn]').value.trim()); if (!no) return toast('شماره‌ی پیش‌نویس را بنویسید.', 'warning');
                const nf = box.querySelector('[data-nf]'); nf.innerHTML = '<p class="text-slate-400 text-xs"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ باز کردنِ پیش‌نویس در سامانه (فقط خواندن)…</p>';
                const r = await iss('dp_from_draft', {kind, draft_no: no});
                if (!r.ok) { nf.innerHTML = `<p class="text-rose-600 text-xs">${esc(r.error)}</p>`; if (r.need_login && window.Inquiry && Inquiry.login) Inquiry.login(() => {}); return; }
                const F = r.fields || [];
                nf.innerHTML = `<input class="psgs-in mb-2" style="min-width:260px" data-pn placeholder="نامِ پیش‌فرض"><div style="max-height:320px;overflow:auto" class="border border-slate-100 rounded-xl p-2">${F.map((f, i) => `<label class="flex gap-2 items-start text-[11px] py-1 border-b border-slate-50"><input type="checkbox" data-fi="${i}"><span>${esc(f.label)}: <b>${esc(fa(Array.isArray(f.value) ? f.value.join('، ') : f.value).slice(0, 80))}</b> <code dir="ltr" class="text-slate-400 text-[10px]">${esc(f.key)}</code></span></label>`).join('')}</div>
                    <button class="psgs-btn bg-violet-600 text-white mt-2" data-ps><i class="fas fa-save"></i>ذخیره‌ی پیش‌فرض</button>`;
                nf.querySelector('[data-ps]').onclick = async () => {
                    const name = nf.querySelector('[data-pn]').value.trim(); if (!name) return toast('نام را بنویسید.', 'warning');
                    const values = {}; nf.querySelectorAll('[data-fi]:checked').forEach(c => { const f = F[+c.dataset.fi]; values[f.key] = f.value; });
                    const s = await iss('dp_save', {name, kind, values, targets: []}); if (s.ok) { toast('پیش‌فرض ساخته شد؛ حالا به شرکت/قرارداد نسبتش دهید.', 'success'); send({type: 'presets'}); render(); } else toast(s.error, 'error');
                };
            };
        };
    }

    // ---------- مالی ----------
    async function finHtml() {
        const r = await bar('ctx', {}); S.data = r;
        if (!r.ok) return `<div class="psgs-card text-rose-600 text-sm">${esc(r.error)}</div>`;
        const K = r.contracts || [], F = r.fin, dis = r.can_edit ? '' : 'disabled';
        const opt = v => '<option value="0">— هیچ —</option>' + K.map(c => `<option value="${c.id}" ${+c.id === +v ? 'selected' : ''}>${esc(c.name)}${c.contract_no ? ' (' + esc(fa(c.contract_no)) + ')' : ''} — ${c.pay_type === 'CASH' ? 'نقد' : fa(c.installment_count) + ' قسط'}</option>`).join('');
        return `<div class="psgs-card"><b class="text-slate-700 text-[13px]"><i class="fas fa-sack-dollar ml-1 text-emerald-600"></i>اقساطِ خودکار در صفحه‌ی «دریافتِ حق بیمه»</b>
            <p class="text-[11px] text-slate-500 mt-1 leading-6">در سایتِ بیمه‌گر، روی صفحه‌ی مالیِ هر پیش‌نویس دکمه‌ی «اقساط و ثبتِ خودکار» هست: قرارداد ← اقساط با همان موتورِ مالیِ سایت ← ثبتِ گام‌به‌گام. اگر پیش‌نویس به ردیفِ سایت وصل باشد قراردادِ همان شرکت، وگرنه پیش‌فرضِ همان حالت.</p>
            <div class="grid md:grid-cols-2 gap-3 mt-3">
                <label class="text-[11.5px] font-bold text-slate-600">قراردادِ پیش‌فرضِ ثالث<select class="psgs-in w-full mt-1" data-d="third" ${dis}>${opt(F.default.third)}</select></label>
                <label class="text-[11.5px] font-bold text-slate-600">قراردادِ پیش‌فرضِ بدنه<select class="psgs-in w-full mt-1" data-d="body" ${dis}>${opt(F.default.body)}</select></label></div>
            <label class="inline-flex items-center gap-2 text-[11.5px] mt-3"><input type="checkbox" data-fs ${F.final_save ? 'checked' : ''} ${dis}>بعد از ثبتِ اقساط، «ثبتِ نهایی» هم زده شود</label>
            <div class="mt-3"><b class="text-[12px] text-slate-700">حسابِ پاسارگادِ هر قرارداد</b>
                <table class="psgs-tbl mt-1"><thead><tr><th>قرارداد</th><th>حساب</th><th></th></tr></thead><tbody>${Object.entries(F.accounts || {}).map(([cid, a]) => { const c = K.find(x => +x.id === +cid); return `<tr><td>${esc(c ? c.name : '#' + cid)}</td><td>${esc(fa(a.desc || a.id))}</td><td>${r.can_edit ? `<button class="text-rose-600 text-[11px]" data-ra="${cid}">برداشتن</button>` : ''}</td></tr>`; }).join('') || '<tr><td colspan="3" class="text-slate-400 text-center">هنوز حسابی ذخیره نشده؛ در صفحه‌ی مالیِ سایتِ بیمه‌گر، بعد از انتخابِ «حسابِ بیمه»، «ذخیره برای این قرارداد» را بزنید.</td></tr>'}</tbody></table></div>
            ${r.can_edit ? '<button class="psgs-btn bg-emerald-600 text-white mt-3" data-sv><i class="fas fa-save"></i>ذخیره</button>' : '<p class="text-[11px] text-slate-400 mt-3">تغییر فقط با دسترسیِ «ویرایش»ِ سایتِ بیمه‌گر.</p>'}
            <p class="text-[10.5px] text-slate-400 mt-2">قراردادها از «مالی ← قراردادها» می‌آیند (تعدادِ قسط، سررسیدِ اول، فاصله، روشِ تقسیم).</p></div>`;
    }
    function finBind() {
        const R = S.root;
        const sv = R.querySelector('[data-sv]');
        if (sv) sv.onclick = async () => { const r = await bar('fin_save', {default: {third: +R.querySelector('[data-d="third"]').value, body: +R.querySelector('[data-d="body"]').value}, final_save: R.querySelector('[data-fs]').checked ? 1 : 0}); toast(r.ok ? 'ذخیره شد.' : r.error, r.ok ? 'success' : 'error'); };
        R.querySelectorAll('[data-ra]').forEach(b => b.onclick = async () => { const r = await bar('fin_save', {account: {contract: +b.dataset.ra, id: ''}}); if (r.ok) render(); else toast(r.error, 'error'); });
    }
    window.PsgSite = {init, send};
})();
