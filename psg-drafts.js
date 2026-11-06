// فایل: psg-drafts.js
// «سایت بیمه‌گر / پاسارگاد»: پیش‌نویس‌ها (ثالث / بدنه) و صادره‌ها (ثالث / بدنه) — فهرستِ کامل از دیتابیسِ خودمان و «بروزرسانی از سامانه»
// (سریع: از صفحه‌ی ۱ تا اولین صفحه‌ی بی‌تغییر؛ کامل: تا آخرین صفحه) که در پس‌زمینه صفحه‌به‌صفحه می‌خواند.
// هر ردیف: چاپ‌ها (پیش‌نویس، فرمِ پیشنهاد، فیشِ نقدی، قبضِ رسید، بیمه‌نامه)، باز کردنِ مشاهده/اصلاح/مالی/بارگذاری/استعلامِ مالی در «سایتِ بیمه‌گر»،
// و برای صادره‌ی وصل به ردیفِ سایت: «تأییدِ نهایی» (فایلِ بیمه‌نامه خودکار از سامانه ← فرمِ ثبتِ صدور). API: api/psg_drafts_actions.php
(function () {
    'use strict';
    if (window.PsgDrafts) return;
    const API = 'api/psg_drafts_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = n => n == null ? '—' : fa(Number(n).toLocaleString('en-US'));
    const norm = s => String(s || '').replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[۰-۹]/g, d => FA.indexOf(d)).toLowerCase();
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function post(b) { try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(b)})).json(); } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; } }
    const KINDS = {third: 'ثالث', body: 'بدنه', tiss: 'صادره‌ی ثالث', biss: 'صادره‌ی بدنه'};
    const isIss = k => k === 'tiss' || k === 'biss';
    const isBody = k => k === 'body' || k === 'biss';
    const S = {root: null, kind: 'third', rows: [], last: null, q: '', state: '', site: '', from: '1403/01/01', sync: null, busy: false, stop: false, stale: (function () { try { return localStorage.getItem('psgd_hide_deleted') !== '1'; } catch (e) { return true; } })(), auto: null, admin: false, timer: null, lastLoad: 0, page: 0};
    const PER = 200;
    const PRINTS = {draft: 'چاپِ پیش‌نویس', proposal: 'چاپِ فرمِ پیشنهاد', cash: 'چاپِ فیشِ نقدی', receipt: 'چاپِ قبضِ رسید', policy: 'چاپِ بیمه‌نامه'};
    const OPENS = {view: 'مشاهده‌ی جزئیات', edit: 'اصلاحِ پیش‌نویس', finance: 'تعیینِ وضعیتِ مالی', upload: 'بارگذاریِ فایل', fin_inq: 'استعلامِ مالی'};
    let chan = null; try { chan = new BroadcastChannel('psgx'); chan.onmessage = e => { const m = e.data || {}; if (m.from === 'bar' && /^fin_/.test(m.type || '') && S.root && S.root.isConnected) load(true); }; } catch (e) {}

    function css() {
        if (document.getElementById('psgd-css')) return;
        const s = document.createElement('style'); s.id = 'psgd-css';
        s.textContent = `.psgd-hero{border-radius:22px;padding:18px 20px;color:#fff;background:linear-gradient(120deg,#0c4a6e,#0369a1 55%,#4f46e5);position:relative;overflow:hidden}
        .psgd-hero.iss{background:linear-gradient(120deg,#064e3b,#0f766e 55%,#0369a1)}
        .psgd-hero:after{content:'';position:absolute;inset:auto -60px -80px auto;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%)}
        .psgd-crumb{font-size:11px;opacity:.8}.psgd-tab{padding:7px 14px;border-radius:12px;font-size:12px;font-weight:800;color:#fff;background:transparent;white-space:nowrap}
        .psgd-tab.on{background:#fff;color:#075985}.psgd-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:12px}
        .psgd-in{border:1px solid #e2e8f0;border-radius:12px;padding:7px 10px;font-size:12px}.psgd-btn{border-radius:12px;padding:7px 12px;font-size:12px;font-weight:800;display:inline-flex;gap:6px;align-items:center}
        .psgd-tbl{width:100%;font-size:11.5px;border-collapse:separate;border-spacing:0}.psgd-tbl th{position:sticky;top:0;background:#f8fafc;color:#475569;font-weight:800;text-align:right;padding:8px;border-bottom:1px solid #e2e8f0;white-space:nowrap;z-index:1}
        .psgd-tbl td{padding:7px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top}.psgd-tbl tr:hover td{background:#f8fafc}.psgd-sub{color:#94a3b8;font-size:10px}
        .psgd-gone td{opacity:.5;filter:grayscale(.6)}.psgd-gone td:nth-child(2){opacity:1;filter:none}.psgd-gone b.font-mono{text-decoration:line-through;text-decoration-color:#f43f5e}
        .psgd-ctr{display:inline-flex;align-items:center;gap:4px;margin-top:4px;max-width:190px;font-size:10px;font-weight:700;color:#3730a3;background:#eef2ff;border:1px solid #e0e7ff;border-radius:8px;padding:1px 7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.psgd-ctr small{color:#818cf8;font-size:9px}
        .psgd-gone-tag{display:inline-flex;align-items:center;gap:4px;margin-top:4px;padding:1px 8px;border-radius:999px;font-size:10px;font-weight:800;background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
        .psgd-pill{display:inline-block;border-radius:999px;padding:1px 8px;font-size:10px;font-weight:800}.psgd-bar{height:6px;border-radius:9px;background:#e0f2fe;overflow:hidden}.psgd-bar>i{display:block;height:100%;background:#0284c7;transition:width .3s}
        .psgd-act{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:9px;font-size:12px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0}.psgd-act:hover{background:#e0f2fe;color:#075985;border-color:#bae6fd}
        .psgd-menu{position:fixed;z-index:9999;min-width:220px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 24px 60px -18px rgba(15,23,42,.45);padding:6px;font-size:12px}
        .psgd-menu h6{font-size:10.5px;color:#94a3b8;font-weight:800;padding:6px 8px 2px}.psgd-menu button{display:flex;width:100%;gap:8px;align-items:center;padding:7px 9px;border-radius:10px;text-align:right}.psgd-menu button:hover{background:#f1f5f9}
        .psgd-menu button i{width:16px;text-align:center;color:#0369a1}.psgd-ok{background:linear-gradient(135deg,#0d9488,#059669);color:#fff}
        .psgd-stat{border-radius:14px;padding:8px 12px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18)}.psgd-stat b{display:block;font-size:16px}.psgd-stat span{font-size:10.5px;opacity:.85}`;
        document.head.appendChild(s);
    }
    async function init(root, opt) {
        css(); S.root = root || S.root;
        const wk = window.__psgdKind; window.__psgdKind = null;
        if (wk === 'drafts' && isIss(S.kind)) { S.kind = 'third'; S.state = ''; S.site = ''; S.page = 0; }
        else if (wk && KINDS[wk] && wk !== S.kind) { S.kind = wk; S.state = ''; S.site = ''; S.page = 0; }
        if (opt && opt.kind && KINDS[opt.kind]) S.kind = opt.kind;
        if (!S.root) return;
        S.root.innerHTML = '<div class="psgd-card text-center text-slate-400 text-xs p-8"><i class="fas fa-spinner fa-spin"></i></div>';
        await load();
    }
    async function load(quiet) {
        const r = await post({action: 'list', kind: S.kind, stale: S.stale ? 1 : 0});
        if (!r.ok) { if (!quiet) S.root.innerHTML = `<div class="psgd-card text-rose-600 text-sm">${esc(r.error)}</div>`; return; }
        S.rows = r.rows || []; S.last = r.last_sync; S.auto = r.auto || null; S.admin = !!r.is_admin; S.lastLoad = Date.now();
        if (S.admin && S.auto && S.auto.from && !S._fromTouched) S.from = S.auto.from;
        if (quiet && S.root.contains(document.activeElement) && document.activeElement.matches('input,select')) renderSoft(); else render();
        if (r.running && !S.busy && !(S.auto && S.auto.on)) { S.sync = {id: r.running}; loop(); }
        ensureTimer();
    }
    function ensureTimer() {
        if (S.timer) return;
        S.timer = setInterval(async () => {
            if (!S.root || !S.root.isConnected || S.root.closest('.hidden')) { clearInterval(S.timer); S.timer = null; return; }
            if (document.hidden || S.busy || !S.auto || !S.auto.on) return;
            const r = await post({action: 'auto_tick'});
            if (r && r.ok) { const was = S.auto && S.auto.state; S.auto = Object.assign({}, S.auto, r); if (r.ran && Date.now() - S.lastLoad > 8000) load(true); else if (was !== r.state) renderSoft(); }
        }, Math.max(2, (S.auto && S.auto.gap) || 3) * 1000);
    }
    const AUTO_FA = {run: ['در حالِ بروزرسانیِ خودکار', '#16a34a'], pause: ['بروز است؛ دورِ بعد', '#0284c7'], login: ['نیازمندِ ورود به سامانه', '#d97706'], hours: ['خاموش (بیرون از ساعتِ کاریِ پنل)', '#64748b'], off: ['خاموش', '#64748b']};
    function autoHtml() {
        const a = S.auto; if (!a) return '';
        const [t, c] = AUTO_FA[a.state] || ['—', '#64748b'];
        const extra = a.state === 'pause' && a.next_in ? ` (${fa(a.next_in)} ثانیه‌ی دیگر)` : a.state === 'run' ? ` · ${KINDS[a.kind] || ''} · هر ${fa(a.gap)} ثانیه یک صفحه` : '';
        return `<span data-auto class="psgd-pill" style="background:${c}1a;color:${c};font-size:11px;padding:3px 10px"><i class="fas ${a.state === 'run' ? 'fa-rotate fa-spin' : 'fa-clock'} ml-1"></i>بروزرسانیِ خودکار: ${t}${extra}${a.msg ? ' — ' + esc(a.msg) : ''}</span>`;
    }
    function renderSoft() { const el = S.root && S.root.querySelector('[data-auto]'); if (el) el.outerHTML = autoHtml(); }
    function stateCls(st) {
        if (/پیش\s*نویس/.test(st)) return 'background:#e0f2fe;color:#0369a1';
        if (/موقت/.test(st)) return 'background:#fef3c7;color:#92400e';
        if (/صادر|تایید|تأیید/.test(st)) return 'background:#dcfce7;color:#15803d';
        return 'background:#f1f5f9;color:#475569';
    }
    function view() {
        const q = norm(S.q);
        return S.rows.filter(r => (!S.state || r.state === S.state) && (!S.site || (S.site === 'linked' ? r.site : S.site === 'ready' ? r.site && !r.site.issued : !r.site))
            && (!q || norm([r.no, r.insured, r.car, r.plate, r.engine, r.chassis, r.user, r.unit, r.f2, r.f3, r.site ? '#' + r.site.request : ''].join(' ')).indexOf(q) >= 0));
    }
    function lastTxt() {
        const last = S.last;
        if (!last) return 'هنوز از سامانه خوانده نشده؛ «بروزرسانی از سامانه» را بزنید.';
        return `آخرین بروزرسانی${last.mode === 'inc' ? ' (سریع)' : ' (کامل)'}: ${fa(String(last.at || '').slice(0, 16))} · از ${fa(last.from)} · ${fa(last.pages)}${last.total_pages && last.total_pages !== last.pages ? ' از ' + fa(last.total_pages) : ''} صفحه، ${fa(last.rows)} ردیف${last.status === 'DONE' ? '' : ' · ' + ({RUNNING: 'در حالِ انجام', LOGIN: 'نیازمندِ ورود', FAIL: 'ناموفق', STOPPED: 'متوقف'}[last.status] || last.status)}${last.message ? ' — ' + esc(last.message) : ''}`;
    }
    function render() {
        const L = view(), body = isBody(S.kind), iss = isIss(S.kind);
        const states = [...new Set(S.rows.map(r => r.state).filter(Boolean))];
        const pages = Math.max(1, Math.ceil(L.length / PER)); if (S.page >= pages) S.page = pages - 1;
        const P = L.slice(S.page * PER, S.page * PER + PER);
        const linked = S.rows.filter(r => r.site).length, ready = S.rows.filter(r => r.site && !r.site.issued).length;
        S.root.innerHTML = `
        <div class="psgd-hero ${iss ? 'iss' : ''} mb-3">
            <p class="psgd-crumb"><i class="fas fa-globe ml-1"></i>سایت بیمه‌گر <i class="fas fa-angle-left mx-1"></i> پاسارگاد <i class="fas fa-angle-left mx-1"></i> ${iss ? 'صادره‌ها' : 'پیش‌نویس‌ها'}</p>
            <div class="flex flex-wrap items-center justify-between gap-3 mt-1 relative z-[1]">
                <h2 class="text-xl font-black"><i class="fas ${iss ? 'fa-file-circle-check' : 'fa-file-pen'} ml-1"></i>${iss ? 'بیمه‌نامه‌های صادره‌ی پاسارگاد' : 'پیش‌نویس‌های پاسارگاد'}</h2>
                <div class="flex flex-wrap rounded-xl p-1 gap-1" style="background:rgba(255,255,255,.15)">${Object.entries(KINDS).map(([k, t]) => `<button class="psgd-tab ${S.kind === k ? 'on' : ''}" data-k="${k}">${t}</button>`).join('')}</div>
            </div>
            <div class="flex flex-wrap gap-2 mt-3 relative z-[1]">
                <div class="psgd-stat"><b>${fa(S.rows.length)}</b><span>${iss ? 'بیمه‌نامه' : 'پیش‌نویس'} در فهرست</span></div>
                <div class="psgd-stat"><b>${fa(linked)}</b><span>وصل به ردیفِ سایت</span></div>
                ${iss ? `<div class="psgd-stat" style="background:rgba(16,185,129,.25)"><b>${fa(ready)}</b><span>آماده‌ی تأییدِ نهایی</span></div>` : ''}
                <div class="psgd-stat"><b>${money(L.reduce((a, r) => a + (r.premium || 0), 0))}</b><span>جمعِ حق بیمه‌ی فیلترشده (ریال)</span></div>
            </div>
            <p class="text-[11px] opacity-85 mt-2 relative z-[1]">${lastTxt()}</p>
        </div>
        <div class="psgd-card mb-3">
            <div class="flex flex-wrap items-center gap-2 mb-2">${autoHtml()}</div>
            ${S.admin && S.auto ? `<div class="flex flex-wrap items-center gap-2 mb-2 p-2 rounded-xl" style="background:#f8fafc;border:1px dashed #cbd5e1">
                <span class="text-[11px] font-black text-slate-600"><i class="fas fa-user-shield ml-1 text-indigo-500"></i>فقط مدیر کل:</span>
                <label class="text-[11px] text-slate-600 inline-flex items-center gap-1 cursor-pointer"><input type="checkbox" data-a-on ${S.auto.on ? 'checked' : ''}>بروزرسانیِ خودکار (پیش‌نویس‌ها و صادره‌ها)</label>
                <label class="text-[11px] text-slate-600">تاریخِ پیش‌فرضِ جستجو <input class="psgd-in" dir="ltr" style="width:105px" data-a-from data-jdate value="${esc(fa(S.auto.from || S.from))}"></label>
                <label class="text-[11px] text-slate-600">هر <input class="psgd-in" dir="ltr" type="number" min="2" max="30" style="width:56px" data-a-gap value="${S.auto.gap}"> ثانیه یک صفحه</label>
                <label class="text-[11px] text-slate-600">مکث بینِ دورها <input class="psgd-in" dir="ltr" type="number" min="10" max="3600" style="width:70px" data-a-pause value="${S.auto.pause}"> ثانیه</label>
                <button class="psgd-btn bg-indigo-600 text-white" data-a-save><i class="fas fa-save"></i>ذخیره</button>
                <span class="text-[10px] text-slate-400">دورهای خودکار «سریع» است و هر ۶ ساعت یک بار «کامل»؛ فقط در ساعتِ کاریِ پنل و وقتی نشستِ سامانه باز است.</span></div>` : ''}
            <div class="mb-2 flex flex-wrap gap-2">
                ${window.PsgIssueUI && !iss ? '<button class="psgd-btn text-white" style="background:#0f766e" data-mk><i class="fas fa-file-signature"></i>ایجادِ پیش‌نویس (کپی از نمونه / الگو / پیش‌فرض)</button><button class="psgd-btn text-white" style="background:#7c3aed" data-prem><i class="fas fa-calculator"></i>استعلامِ حق بیمه‌ی بدنه (با چاپ)</button>' : ''}
                <button class="psgd-btn bg-emerald-50 text-emerald-700 border border-emerald-200" data-x="kind"><i class="fas fa-file-excel"></i>اکسلِ همین زبانه</button>
                <button class="psgd-btn bg-emerald-50 text-emerald-700 border border-emerald-200" data-x="${iss ? 'issued' : 'drafts'}"><i class="fas fa-file-excel"></i>اکسلِ همه‌ی ${iss ? 'صادره‌ها' : 'پیش‌نویس‌ها'} (ثالث + بدنه)</button>
                <a class="psgd-btn bg-sky-50 text-sky-700 border border-sky-200" href="#" data-site><i class="fas fa-globe"></i>سایتِ بیمه‌گر</a>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                ${S.admin ? `<label class="text-[11px] font-bold text-slate-600">تاریخ صدور از</label>
                <input class="psgd-in" dir="ltr" style="width:110px" data-from data-jdate value="${esc(fa(S.from))}">` : ''}
                <button class="psgd-btn text-white" style="background:#0284c7" data-sync="inc" ${S.busy ? 'disabled' : ''} title="از صفحه‌ی ۱ می‌خواند و تا رسیدن به صفحه‌ای که همه‌اش تکراری و بی‌تغییر است جلو می‌رود"><i class="fas ${S.busy ? 'fa-spinner fa-spin' : 'fa-rotate'}"></i>${S.busy ? 'در حالِ خواندن از سامانه…' : 'بروزرسانی از سامانه'}</button>
                ${S.busy ? '<button class="psgd-btn bg-slate-100 text-slate-600" data-stop><i class="fas fa-stop"></i>توقف</button>' : '<button class="psgd-btn bg-slate-100 text-slate-700" data-sync="full" title="همه‌ی صفحه‌ها تا آخر (کندتر)؛ آن‌هایی که دیگر در سامانه نیستند «قدیمی» می‌شوند"><i class="fas fa-layer-group"></i>بروزرسانیِ کامل</button>'}
                <span class="flex-1"></span>
                <input class="psgd-in" style="min-width:220px" data-q placeholder="جستجو: شماره، بیمه‌گذار، پلاک، شاسی، کاربر، #درخواست…" value="${esc(S.q)}">
                <select class="psgd-in" data-st><option value="">همه‌ی مرحله‌ها</option>${states.map(s => `<option ${s === S.state ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select>
                <select class="psgd-in" data-sitef><option value="">همه</option><option value="linked" ${S.site === 'linked' ? 'selected' : ''}>وصل به ردیفِ سایت</option>${iss ? `<option value="ready" ${S.site === 'ready' ? 'selected' : ''}>آماده‌ی تأییدِ نهایی</option>` : ''}<option value="none" ${S.site === 'none' ? 'selected' : ''}>بدونِ ردیفِ سایت</option></select>
                <label class="text-[11px] text-slate-500 inline-flex items-center gap-1 cursor-pointer" title="ردیف‌هایی که قبلاً در سامانه بودند و در آخرین بروزرسانیِ کامل دیگر نبودند (کم‌رنگ با برچسبِ «حذف شده»)"><input type="checkbox" data-stale ${S.stale ? '' : 'checked'}>حذف‌شده‌ها نشان داده نشوند</label>
            </div>
            ${S.busy && S.sync ? `<div class="mt-2"><div class="psgd-bar"><i style="width:${S.sync.total_pages ? Math.min(99, Math.round(100 * (S.sync.pages || 0) / S.sync.total_pages)) : Math.min(98, 8 + (S.sync.pages || 0) * 3)}%"></i></div><p class="text-[10.5px] text-sky-700 mt-1">${S.sync.mode === 'full' ? 'کامل' : 'سریع'} · ${fa(S.sync.pages || 0)}${S.sync.total_pages ? ' از ' + fa(S.sync.total_pages) : ''} صفحه، ${fa(S.sync.rows || 0)} ردیف خوانده شد (${fa(S.sync.new || 0)} تازه)… می‌توانید در همین حال کار کنید؛ تا وقتی پنل باز است ادامه دارد.</p></div>` : ''}
        </div>
        <div class="psgd-card">
            <div class="flex flex-wrap items-center gap-2 mb-2"><p class="text-[11px] font-bold text-slate-500">${fa(L.length)} ردیف${pages > 1 ? ` · صفحه‌ی ${fa(S.page + 1)} از ${fa(pages)}` : ''}</p><span class="flex-1"></span>
                ${pages > 1 ? `<button class="psgd-btn bg-slate-100 text-slate-600" data-pg="-1" ${S.page ? '' : 'disabled'}><i class="fas fa-angle-right"></i>قبلی</button><button class="psgd-btn bg-slate-100 text-slate-600" data-pg="1" ${S.page < pages - 1 ? '' : 'disabled'}>بعدی<i class="fas fa-angle-left"></i></button>` : ''}</div>
            <div style="max-height:70vh;overflow:auto"><table class="psgd-tbl"><thead><tr>
                <th>شماره / بیمه‌گذار</th><th>مرحله</th><th>صدور / شروع / انقضا</th><th>خودرو / پلاک</th><th>موتور / شاسی</th>
                <th>${body ? 'ارزش / موافقت‌نامه / نماینده' : 'تعهد مالی / جانی / حوادث'}</th><th>حق بیمه / خالص</th><th>واحد / ناظر / کاربر</th><th>کارها</th></tr></thead><tbody>
                ${P.map(r => `<tr class="${r.stale ? 'psgd-gone' : ''}">
                    <td><b dir="ltr" class="font-mono">${esc(fa(r.no))}</b><div>${esc(r.insured)}</div>${r.site ? `<span class="psgd-pill" style="background:${r.site.issued ? '#dcfce7;color:#166534' : '#ccfbf1;color:#0f766e'};font-size:10px;padding:1px 7px" title="ردیفِ درخواستِ سایت با همین خودرو">ردیفِ سایت · درخواست #${fa(r.site.request)}${iss ? (r.site.issued ? ' · ثبتِ صدور شده' : ' · آماده‌ی تأیید') : ''}</span>` : ''}</td>
                    <td><span class="psgd-pill" style="${stateCls(r.state)}">${esc(r.state || '—')}</span>${r.contract && !r.stale ? `<div class="psgd-ctr" title="مالیِ این پیش‌نویس طبقِ این قرارداد (${r.contract.src === 'row' ? 'قراردادِ شرکتِ ردیفِ سایت' : r.contract.src === 'agreement' ? 'طبقِ موافقت‌نامه‌ی پیش‌نویس' : 'قراردادِ پیش‌فرضِ تنظیماتِ مالی'})"><i class="fas fa-file-contract"></i>${esc(fa(r.contract.name))}${r.contract.src === 'default' ? ' <small>(پیش‌فرض)</small>' : ''}</div>` : ''}${r.stale ? (r.gone === 'issued' ? '<div><span class="psgd-gone-tag" style="background:#dcfce7;color:#166534;border-color:#bbf7d0" title="دیگر در فهرستِ پیش‌نویس‌ها نیست؛ همین خودرو در صادره‌ها هست"><i class="fas fa-circle-check"></i>صادر شد</span></div>' : '<div><span class="psgd-gone-tag" title="قبلاً در سامانه بود و در آخرین بروزرسانیِ کامل دیگر نبود"><i class="fas fa-trash-can"></i>حذف شده</span></div>') : ''}</td>
                    <td>${fa(r.created)}<div class="psgd-sub">${fa(r.start)} تا ${fa(r.end)}</div></td>
                    <td>${esc(fa(r.car))}<div class="psgd-sub">${esc(fa(r.plate))}${r.usage ? ' · ' + esc(r.usage) : ''}</div></td>
                    <td dir="ltr" style="text-align:right" class="font-mono text-[10.5px]">${esc(r.engine)}<div class="psgd-sub">${esc(r.chassis)}</div></td>
                    <td class="text-[10.5px]">${esc(fa(r.f1))}<div class="psgd-sub">${esc(fa(r.f2))}</div><div class="psgd-sub">${esc(fa(r.f3))}</div></td>
                    <td>${money(r.premium)}<div class="psgd-sub">${money(r.net)}</div></td>
                    <td class="text-[10.5px]">${esc(r.unit)}<div class="psgd-sub">${esc(r.supervisor)}</div><div class="psgd-sub">${esc(r.user)}</div></td>
                    <td class="whitespace-nowrap">${r.stale ? '' : `<div class="flex gap-1 items-center">
                        <button class="psgd-act" title="${iss ? 'چاپِ بیمه‌نامه' : 'چاپِ پیش‌نویس'}" data-pr="${iss ? 'policy' : 'draft'}" data-no="${esc(r.no)}"><i class="fas fa-print"></i></button>
                        ${iss ? '' : `<button class="psgd-act" title="مالی (تعیینِ وضعیتِ مالی) در سایتِ بیمه‌گر${r.contract ? ' — طبقِ قراردادِ ' + esc(fa(r.contract.name)) : ''}" data-op="finance" data-no="${esc(r.no)}"><i class="fas fa-sack-dollar"></i></button>`}
                        <button class="psgd-act" title="همه‌ی کارها" data-more="${esc(r.no)}"><i class="fas fa-ellipsis"></i></button>
                        ${iss && r.site && !r.site.issued ? `<button class="psgd-btn psgd-ok" style="padding:4px 9px;font-size:11px" data-final="${esc(r.no)}" title="فایلِ بیمه‌نامه از سامانه ← فرمِ ثبتِ صدورِ ردیف؛ فقط ببینید و تأیید کنید"><i class="fas fa-stamp"></i>تأییدِ نهایی</button>` : ''}</div>`}</td></tr>`).join('') || '<tr><td colspan="9" class="text-center text-slate-400 py-10">ردیفی نیست.</td></tr>'}
            </tbody></table></div>
            <p class="text-[10.5px] text-slate-400 mt-2"><i class="fas fa-circle-info ml-1"></i>چاپ‌ها PDFِ خودِ سامانه‌اند. «مشاهده / اصلاح / مالی / بارگذاری» صفحه‌ی همان ردیف را در «سایتِ بیمه‌گر» (با نوارِ ابزار) باز می‌کند تا همان‌جا ادامه دهید.</p>
        </div>`;
        bind();
    }
    function bind() {
        const R = S.root;
        R.querySelectorAll('[data-k]').forEach(b => b.onclick = () => { if (S.busy) return toast('اول بروزرسانیِ در حالِ انجام تمام شود.', 'warning'); if (b.dataset.k !== S.kind) { S.kind = b.dataset.k; S.state = ''; S.site = ''; S.page = 0; init(); } });
        const qi = R.querySelector('[data-q]'); let tm; qi.oninput = () => { clearTimeout(tm); tm = setTimeout(() => { S.q = qi.value; S.page = 0; const pos = qi.selectionStart; render(); const n = S.root.querySelector('[data-q]'); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} }, 250); };
        R.querySelector('[data-st]').onchange = e => { S.state = e.target.value; S.page = 0; render(); };
        R.querySelector('[data-sitef]').onchange = e => { S.site = e.target.value; S.page = 0; render(); };
        R.querySelector('[data-stale]').onchange = e => { S.stale = !e.target.checked; try { localStorage.setItem('psgd_hide_deleted', S.stale ? '0' : '1'); } catch (x) {} S.page = 0; load(); };
        R.querySelectorAll('[data-sync]').forEach(b => b.onclick = () => start(b.dataset.sync));
        R.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { S.page += +b.dataset.pg; render(); R.querySelector('.psgd-tbl').closest('div').scrollTop = 0; });
        R.querySelectorAll('[data-x]').forEach(b => b.onclick = () => { const g = b.dataset.x; window.open(API + '?' + new URLSearchParams({action: 'export', kind: S.kind, group: g === 'kind' ? '' : g, stale: S.stale ? 1 : 0}), '_blank'); });
        const sl = R.querySelector('[data-site]'); if (sl) sl.onclick = e => { e.preventDefault(); if (window.switchTab && document.getElementById('tab-psg-site')) switchTab('psg-site'); else window.open('psgx/index.php/~s/cins/', '_blank'); };
        const pmb = R.querySelector('[data-prem]'); if (pmb) pmb.onclick = () => PsgIssueUI.premium({});
        const mkb = R.querySelector('[data-mk]'); if (mkb) mkb.onclick = () => PsgIssueUI.manual({onDone: () => load()});
        const st = R.querySelector('[data-stop]'); if (st) st.onclick = () => { S.stop = true; };
        const fi = R.querySelector('[data-from]'); if (fi) fi.addEventListener('change', () => { S._fromTouched = true; });
        R.querySelectorAll('[data-pr]').forEach(b => b.onclick = () => print(S.kind, b.dataset.no, b.dataset.pr, b));
        R.querySelectorAll('[data-op]').forEach(b => b.onclick = () => openIn(S.kind, b.dataset.no, b.dataset.op, b));
        R.querySelectorAll('[data-more]').forEach(b => b.onclick = e => menu(e.currentTarget, b.dataset.more));
        R.querySelectorAll('[data-final]').forEach(b => b.onclick = () => { const r = S.rows.find(x => x.no === b.dataset.final); if (r && r.site) finalApprove({plate: r.site.plate, request: r.site.request, no: r.no, kind: S.kind}); });
        const as = R.querySelector('[data-a-save]');
        if (as) as.onclick = async () => {
            const from = String(R.querySelector('[data-a-from]').value || '').replace(/[۰-۹]/g, d => FA.indexOf(d)).trim();
            const r = await post({action: 'settings_save', auto: R.querySelector('[data-a-on]').checked ? 1 : 0, from, gap: +R.querySelector('[data-a-gap]').value || 3, pause: +R.querySelector('[data-a-pause]').value || 60});
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            toast('تنظیماتِ بروزرسانیِ خودکار ذخیره شد.', 'success');
            S._fromTouched = false; if (S.timer) { clearInterval(S.timer); S.timer = null; } load();
        };
    }
    // ---- کارهای هر ردیف ----
    function menu(btn, no) {
        document.querySelectorAll('.psgd-menu').forEach(m => m.remove());
        const iss = isIss(S.kind), k = S.kind;
        const m = document.createElement('div'); m.className = 'psgd-menu';
        const pr = iss ? ['policy'] : ['draft', 'proposal', 'cash', 'receipt'];
        const op = iss ? ['view', 'fin_inq'] : ['view', 'edit', 'finance', 'upload', 'fin_inq'];
        const ic = {draft: 'fa-file-lines', proposal: 'fa-file-signature', cash: 'fa-money-bill-wave', receipt: 'fa-receipt', policy: 'fa-file-shield', view: 'fa-eye', edit: 'fa-pen-to-square', finance: 'fa-sack-dollar', upload: 'fa-cloud-arrow-up', fin_inq: 'fa-magnifying-glass-dollar'};
        m.innerHTML = `<h6>چاپ و دانلود (PDFِ سامانه)</h6>${pr.map(w => `<button data-w="${w}"><i class="fas ${ic[w]}"></i>${PRINTS[w]}</button>`).join('')}
            <h6>در سایتِ بیمه‌گر</h6>${op.map(w => `<button data-o="${w}"><i class="fas ${ic[w]}"></i>${OPENS[w]}</button>`).join('')}`;
        document.body.appendChild(m);
        const rc = btn.getBoundingClientRect();
        m.style.top = Math.min(window.innerHeight - m.offsetHeight - 8, rc.bottom + 4) + 'px'; m.style.left = Math.max(8, rc.left - m.offsetWidth + rc.width) + 'px';
        m.querySelectorAll('[data-w]').forEach(b => b.onclick = () => { m.remove(); print(k, no, b.dataset.w); });
        m.querySelectorAll('[data-o]').forEach(b => b.onclick = () => { m.remove(); openIn(k, no, b.dataset.o); });
        setTimeout(() => document.addEventListener('click', function f(e) { if (!m.contains(e.target)) { m.remove(); document.removeEventListener('click', f); } }), 0);
    }
    function busyBtn(b, on) { if (!b) return; if (on) { b._h = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; } else { b.disabled = false; if (b._h) b.innerHTML = b._h; } }
    function needLogin(r, retry) { if (r && r.need_login && window.Inquiry && Inquiry.login) { Inquiry.login(retry || (() => {})); return true; } return false; }
    async function printTok(kind, no, what) {
        const r = await post({action: 'print', kind, no, what});
        if (!r.ok) { if (!needLogin(r)) toast(r.error || 'چاپ نشد.', 'error'); return null; }
        return r;
    }
    const pdfUrl = (r, dl) => API + '?' + new URLSearchParams({action: 'pdf', t: r.token, name: r.name, dl: dl ? 1 : 0});
    async function print(kind, no, what, btn) {
        const w = window.open('', '_blank');   // پیش از await تا جلوی پنجره‌ی تازه گرفته نشود
        if (w) w.document.write('<meta charset="utf-8"><body style="font-family:tahoma;direction:rtl;padding:40px;color:#334155">در حالِ گرفتنِ «' + (PRINTS[what] || 'چاپ') + '» از سامانه‌ی پاسارگاد…</body>');
        busyBtn(btn, true); toast('در حالِ گرفتنِ ' + (PRINTS[what] || 'چاپ') + ' از سامانه…', 'info');
        const r = await printTok(kind, no, what);
        busyBtn(btn, false);
        if (!r) { if (w) w.close(); return; }
        if (w) w.location.href = pdfUrl(r); else window.open(pdfUrl(r), '_blank');
    }
    async function openIn(kind, no, act, btn) {
        const w = window.open('', '_blank');
        if (w) w.document.write('<meta charset="utf-8"><body style="font-family:tahoma;direction:rtl;padding:40px;color:#334155">در حالِ باز کردنِ «' + (OPENS[act] || '') + '» در سایتِ بیمه‌گر…</body>');
        busyBtn(btn, true);
        const r = await post({action: 'proxy_open', kind, no, act});
        busyBtn(btn, false);
        if (!r.ok) { if (w) w.close(); if (!needLogin(r)) toast(r.error || 'باز نشد.', 'error'); return; }
        if (w) w.location.href = r.url; else window.open(r.url, '_blank');
    }
    // ---- تأییدِ نهایی: بیمه‌نامه‌ی صادره‌ی پاسارگاد ← فرمِ «ثبتِ صدور»ِ همان ردیف (فایل خودکار؛ خواندن و تطبیق مثلِ همیشه؛ تأیید با کاربر) ----
    async function finalApprove(o) {
        if (typeof openMarkIssued !== 'function') return toast('فرمِ ثبتِ صدور در این صفحه نیست.', 'error');
        toast('در حالِ گرفتنِ فایلِ بیمه‌نامه‌ی ' + fa(o.no) + ' از سامانه‌ی پاسارگاد…', 'info');
        const r = await printTok(o.kind || 'tiss', o.no, 'policy');
        if (!r) return;
        let file;
        try { const b = await (await fetch(pdfUrl(r, 1), {credentials: 'same-origin'})).blob(); if (!/pdf/.test(b.type)) throw 0; file = new File([b], 'bimehnameh-' + String(o.no).replace(/\//g, '-') + '.pdf', {type: 'application/pdf'}); }
        catch (e) { return toast('فایلِ بیمه‌نامه دانلود نشد.', 'error'); }
        try { if (typeof openCompanyRequestDetail === 'function' && o.request) await openCompanyRequestDetail(o.request); } catch (e) {}
        openMarkIssued(o.plate);
        const inp = document.getElementById('cis-file');
        if (!inp) return;
        try { const dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; } catch (e) { return toast('مرورگر اجازه‌ی گذاشتنِ خودکارِ فایل را نداد؛ فایل را دستی انتخاب کنید.', 'warning'); }
        const nm = document.getElementById('cis-file-name'); if (nm) nm.textContent = file.name + ' (از سامانه‌ی پاسارگاد)';
        if (typeof validateCompanyPolicy === 'function') validateCompanyPolicy();
        toast('بیمه‌نامه از سامانه آمد و در حالِ خواندن است؛ اطلاعات را ببینید و «ثبت صدور» را بزنید.', 'success');
    }
    // ---- بروزرسانی ----
    async function start(mode) {
        mode = mode === 'full' ? 'full' : 'inc';
        const fi = S.root.querySelector('[data-from]');
        const from = fi ? String(fi.value || '').replace(/[۰-۹]/g, d => FA.indexOf(d)).trim() : '';
        if (fi && !/^1[34]\d{2}\/\d{1,2}\/\d{1,2}$/.test(from)) return toast('تاریخ را مثلِ ۱۴۰۳/۰۱/۰۱ بنویسید.', 'warning');
        if (from) S.from = from;
        S.busy = true; S.stop = false; S.sync = {pages: 0, rows: 0, new: 0, mode}; render();
        const r = await post({action: 'sync_start', kind: S.kind, from: from || undefined, mode});
        if (!r.ok) {
            S.busy = false; render();
            if (needLogin(r, () => start(mode))) return;
            return toast(r.error || 'خطا', 'error');
        }
        S.sync = r.sync;
        if (r.done) return finish();
        loop();
    }
    async function loop() {
        S.busy = true; render();
        while (S.sync && S.sync.id) {
            if (S.stop) { await post({action: 'sync_stop', id: S.sync.id}); toast('متوقف شد.', 'info'); break; }
            const r = await post({action: 'sync_step', id: S.sync.id});
            if (!r.ok) {
                if (r.need_login && window.Inquiry && Inquiry.login) { S.busy = false; render(); toast('نشستِ سامانه بسته شد؛ وارد شوید و دوباره «بروزرسانی» بزنید.', 'warning'); Inquiry.login(() => start()); return; }
                toast(r.error || 'خطا', 'error'); break;
            }
            S.sync = r.sync || S.sync;
            render();
            if (r.done) break;
            if (r.busy) await new Promise(res => setTimeout(res, 4000));
        }
        finish();
    }
    function finish() {
        const s = S.sync; S.busy = false;
        if (s && s.status === 'DONE') toast(`${fa(s.rows)} ردیف از ${fa(s.pages)} صفحه خوانده شد (${fa(s.new)} تازه)${s.message ? ' — ' + s.message : ''}.`, s.message ? 'warning' : 'success');
        else if (s && s.status === 'FAIL') toast(s.message || 'بروزرسانی ناموفق بود.', 'error');
        S.sync = null; load();
    }
    window.PsgDrafts = {init, finalApprove, print, openIn};
})();
