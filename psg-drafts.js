// فایل: psg-drafts.js
// «سایت بیمه‌گر / پاسارگاد / پیش‌نویس‌ها»: دو زبانه (ثالث / بدنه)، فهرستِ کامل از دیتابیسِ خودمان و «بروزرسانی از سامانه»
// که در پس‌زمینه (تا وقتی صفحه باز است) صفحه‌به‌صفحه می‌خواند. API: api/psg_drafts_actions.php
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
    const S = {root: null, kind: 'third', rows: [], last: null, q: '', state: '', from: '1403/01/01', sync: null, busy: false, stop: false, stale: false};
    try { const f = localStorage.getItem('psgd_from'); if (f) S.from = f; } catch (e) {}

    function css() {
        if (document.getElementById('psgd-css')) return;
        const s = document.createElement('style'); s.id = 'psgd-css';
        s.textContent = `.psgd-hero{border-radius:22px;padding:18px 20px;color:#fff;background:linear-gradient(120deg,#0c4a6e,#0369a1 55%,#4f46e5)}
        .psgd-crumb{font-size:11px;opacity:.8}.psgd-tab{padding:7px 16px;border-radius:12px;font-size:12px;font-weight:800;color:#fff;background:transparent}
        .psgd-tab.on{background:#fff;color:#075985}.psgd-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:12px}
        .psgd-in{border:1px solid #e2e8f0;border-radius:12px;padding:7px 10px;font-size:12px}.psgd-btn{border-radius:12px;padding:7px 12px;font-size:12px;font-weight:800;display:inline-flex;gap:6px;align-items:center}
        .psgd-tbl{width:100%;font-size:11.5px;border-collapse:separate;border-spacing:0}.psgd-tbl th{position:sticky;top:0;background:#f8fafc;color:#475569;font-weight:800;text-align:right;padding:8px;border-bottom:1px solid #e2e8f0;white-space:nowrap}
        .psgd-tbl td{padding:7px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top}.psgd-tbl tr:hover td{background:#f8fafc}.psgd-sub{color:#94a3b8;font-size:10px}
        .psgd-pill{display:inline-block;border-radius:999px;padding:1px 8px;font-size:10px;font-weight:800}.psgd-bar{height:6px;border-radius:9px;background:#e0f2fe;overflow:hidden}.psgd-bar>i{display:block;height:100%;background:#0284c7;transition:width .3s}`;
        document.head.appendChild(s);
    }
    async function init(root) {
        css(); S.root = root || S.root;
        if (!S.root) return;
        S.root.innerHTML = '<div class="psgd-card text-center text-slate-400 text-xs p-8"><i class="fas fa-spinner fa-spin"></i></div>';
        await load();
    }
    async function load() {
        const r = await post({action: 'list', kind: S.kind, stale: S.stale ? 1 : 0});
        if (!r.ok) { S.root.innerHTML = `<div class="psgd-card text-rose-600 text-sm">${esc(r.error)}</div>`; return; }
        S.rows = r.rows || []; S.last = r.last_sync;
        render();
        // بروزرسانیِ نیمه‌کاره (مثلاً صفحه بسته شده بود): ادامه بده
        if (r.running && !S.busy) { S.sync = {id: r.running}; loop(); }
    }
    function stateCls(st) {
        if (/پیش\s*نویس/.test(st)) return 'background:#e0f2fe;color:#0369a1';
        if (/موقت/.test(st)) return 'background:#fef3c7;color:#92400e';
        if (/صادر|تایید|تأیید/.test(st)) return 'background:#dcfce7;color:#15803d';
        return 'background:#f1f5f9;color:#475569';
    }
    function view() {
        const q = norm(S.q);
        return S.rows.filter(r => (!S.state || r.state === S.state) && (!q || norm([r.no, r.insured, r.car, r.plate, r.engine, r.chassis, r.user, r.unit, r.f2, r.f3].join(' ')).indexOf(q) >= 0));
    }
    function render() {
        const L = view(), body = S.kind === 'body';
        const states = [...new Set(S.rows.map(r => r.state).filter(Boolean))];
        const last = S.last;
        const lastTxt = last ? `آخرین بروزرسانی: ${fa(String(last.at || '').slice(0, 16))} · از ${fa(last.from)} · ${fa(last.pages)} صفحه، ${fa(last.rows)} ردیف${last.status === 'DONE' ? '' : ' · ' + ({RUNNING: 'در حالِ انجام', LOGIN: 'نیازمندِ ورود', FAIL: 'ناموفق', STOPPED: 'متوقف'}[last.status] || last.status)}${last.message ? ' — ' + esc(last.message) : ''}` : 'هنوز از سامانه خوانده نشده؛ «بروزرسانی از سامانه» را بزنید.';
        S.root.innerHTML = `
        <div class="psgd-hero mb-3">
            <p class="psgd-crumb"><i class="fas fa-globe ml-1"></i>سایت بیمه‌گر <i class="fas fa-angle-left mx-1"></i> پاسارگاد <i class="fas fa-angle-left mx-1"></i> پیش‌نویس‌ها</p>
            <div class="flex flex-wrap items-center justify-between gap-3 mt-1">
                <h2 class="text-xl font-black"><i class="fas fa-file-pen ml-1"></i>پیش‌نویس‌های پاسارگاد</h2>
                <div class="flex rounded-xl p-1 gap-1" style="background:rgba(255,255,255,.15)">${[['third', 'ثالث'], ['body', 'بدنه']].map(([k, t]) => `<button class="psgd-tab ${S.kind === k ? 'on' : ''}" data-k="${k}">${t}</button>`).join('')}</div>
            </div>
            <p class="text-[11px] opacity-85 mt-2">${lastTxt}</p>
        </div>
        <div class="psgd-card mb-3">
            <div class="flex flex-wrap items-center gap-2">
                <label class="text-[11px] font-bold text-slate-600">تاریخ صدور از</label>
                <input class="psgd-in" dir="ltr" style="width:110px" data-from data-jdate value="${esc(fa(S.from))}">
                <button class="psgd-btn text-white" style="background:#0284c7" data-sync ${S.busy ? 'disabled' : ''}><i class="fas ${S.busy ? 'fa-spinner fa-spin' : 'fa-rotate'}"></i>${S.busy ? 'در حالِ خواندن از سامانه…' : 'بروزرسانی از سامانه'}</button>
                ${S.busy ? '<button class="psgd-btn bg-slate-100 text-slate-600" data-stop><i class="fas fa-stop"></i>توقف</button>' : ''}
                <span class="flex-1"></span>
                <input class="psgd-in" style="min-width:220px" data-q placeholder="جستجو: شماره، بیمه‌گذار، پلاک، شاسی، کاربر…" value="${esc(S.q)}">
                <select class="psgd-in" data-st><option value="">همه‌ی مرحله‌ها</option>${states.map(s => `<option ${s === S.state ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select>
                <label class="text-[11px] text-slate-500 inline-flex items-center gap-1 cursor-pointer" title="پیش‌نویس‌هایی که در آخرین بروزرسانی دیگر در سامانه نبودند (ثبت یا حذف شده‌اند)"><input type="checkbox" data-stale ${S.stale ? 'checked' : ''}>قدیمی‌ها هم</label>
            </div>
            ${S.busy && S.sync ? `<div class="mt-2"><div class="psgd-bar"><i style="width:${Math.min(98, 8 + (S.sync.pages || 0) * 3)}%"></i></div><p class="text-[10.5px] text-sky-700 mt-1">${fa(S.sync.pages || 0)} صفحه، ${fa(S.sync.rows || 0)} ردیف خوانده شد (${fa(S.sync.new || 0)} تازه)… می‌توانید در همین حال کار کنید؛ تا وقتی پنل باز است ادامه دارد.</p></div>` : ''}
        </div>
        <div class="psgd-card">
            <p class="text-[11px] font-bold text-slate-500 mb-2">${fa(L.length)} پیش‌نویس · جمعِ حق بیمه ${money(L.reduce((a, r) => a + (r.premium || 0), 0))} ریال</p>
            <div style="max-height:70vh;overflow:auto"><table class="psgd-tbl"><thead><tr>
                <th>شماره / بیمه‌گذار</th><th>مرحله</th><th>صدور / شروع / انقضا</th><th>خودرو / پلاک</th><th>موتور / شاسی</th>
                <th>${body ? 'ارزش / موافقت‌نامه / نماینده' : 'تعهد مالی / جانی / حوادث'}</th><th>حق بیمه / خالص</th><th>واحد / ناظر / کاربر</th></tr></thead><tbody>
                ${L.slice(0, 3000).map(r => `<tr style="${r.stale ? 'opacity:.5' : ''}">
                    <td><b dir="ltr" class="font-mono">${esc(fa(r.no))}</b><div>${esc(r.insured)}</div></td>
                    <td><span class="psgd-pill" style="${stateCls(r.state)}">${esc(r.state || '—')}</span>${r.stale ? '<div class="psgd-sub">دیگر در سامانه نیست</div>' : ''}</td>
                    <td>${fa(r.created)}<div class="psgd-sub">${fa(r.start)} تا ${fa(r.end)}</div></td>
                    <td>${esc(fa(r.car))}<div class="psgd-sub">${esc(fa(r.plate))}${r.usage ? ' · ' + esc(r.usage) : ''}</div></td>
                    <td dir="ltr" style="text-align:right" class="font-mono text-[10.5px]">${esc(r.engine)}<div class="psgd-sub">${esc(r.chassis)}</div></td>
                    <td class="text-[10.5px]">${esc(fa(r.f1))}<div class="psgd-sub">${esc(fa(r.f2))}</div><div class="psgd-sub">${esc(fa(r.f3))}</div></td>
                    <td>${money(r.premium)}<div class="psgd-sub">${money(r.net)}</div></td>
                    <td class="text-[10.5px]">${esc(r.unit)}<div class="psgd-sub">${esc(r.supervisor)}</div><div class="psgd-sub">${esc(r.user)}</div></td></tr>`).join('') || '<tr><td colspan="8" class="text-center text-slate-400 py-10">پیش‌نویسی نیست.</td></tr>'}
            </tbody></table></div>
            ${L.length > 3000 ? `<p class="text-[10.5px] text-slate-400 mt-2">${fa(3000)} ردیفِ اول نشان داده شد؛ با جستجو محدود کنید.</p>` : ''}
            <p class="text-[10.5px] text-slate-400 mt-2"><i class="fas fa-circle-info ml-1"></i>فقط خواندن؛ عملیات روی پیش‌نویس‌ها (تأیید، مالی، کپی و …) در فازِ بعد اضافه می‌شود.</p>
        </div>`;
        const R = S.root;
        R.querySelectorAll('[data-k]').forEach(b => b.onclick = () => { if (b.dataset.k !== S.kind && !S.busy) { S.kind = b.dataset.k; S.state = ''; init(); } else if (S.busy) toast('اول بروزرسانیِ در حالِ انجام تمام شود.', 'warning'); });
        const qi = R.querySelector('[data-q]'); let tm; qi.oninput = () => { clearTimeout(tm); tm = setTimeout(() => { S.q = qi.value; const pos = qi.selectionStart; render(); const n = S.root.querySelector('[data-q]'); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} }, 250); };
        R.querySelector('[data-st]').onchange = e => { S.state = e.target.value; render(); };
        R.querySelector('[data-stale]').onchange = e => { S.stale = e.target.checked; load(); };
        const sb = R.querySelector('[data-sync]'); if (sb) sb.onclick = start;
        const st = R.querySelector('[data-stop]'); if (st) st.onclick = () => { S.stop = true; };
    }
    async function start() {
        const from = String(S.root.querySelector('[data-from]').value || '').replace(/[۰-۹]/g, d => FA.indexOf(d)).trim();
        if (!/^1[34]\d{2}\/\d{1,2}\/\d{1,2}$/.test(from)) return toast('تاریخ را مثلِ ۱۴۰۳/۰۱/۰۱ بنویسید.', 'warning');
        S.from = from; try { localStorage.setItem('psgd_from', from); } catch (e) {}
        S.busy = true; S.stop = false; S.sync = {pages: 0, rows: 0, new: 0}; render();
        const r = await post({action: 'sync_start', kind: S.kind, from});
        if (!r.ok) {
            S.busy = false; render();
            if (r.need_login && window.Inquiry && Inquiry.login) { Inquiry.login(() => start()); return; }
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
        if (s && s.status === 'DONE') toast(`${fa(s.rows)} پیش‌نویس از ${fa(s.pages)} صفحه خوانده شد (${fa(s.new)} تازه).`, 'success');
        else if (s && s.status === 'FAIL') toast(s.message || 'بروزرسانی ناموفق بود.', 'error');
        S.sync = null; load();
    }
    window.PsgDrafts = {init};
})();
