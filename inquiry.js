// فایل: inquiry.js
// پنل ← «استعلامات»: استعلامِ تکی (شخص حقیقی، شخص + کد پستی، کد پستی، شخص حقوقی)، گروهی با اکسل، تاریخچه، تنظیمات (مدیر کل)
// + Inquiry.attachForm: دکمه‌ی «استعلام» کنارِ نام و کد پستی در فرم‌های ثبتِ درخواست.
// مدیر کل جزئیاتِ کامل (تایم‌لاینِ گام‌ها، صفحه‌ی واقعیِ سامانه در هر گام، پیامِ خامِ سامانه) را می‌بیند؛ بقیه فقط نتیجه را.
// منطق: api/inquiry_actions.php و api/_inquiry.php
(function () {
    const API = 'api/inquiry_actions.php';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function post(body) {
        try { return await (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
    }
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const KIND_UI = {
        person: { ic: 'fa-id-card', c: '#2563eb', d: 'نام، نام خانوادگی، نام پدر و جنسیت با کد ملی و تاریخ تولد' },
        person_postal: { ic: 'fa-house-user', c: '#7c3aed', d: 'مشخصاتِ شخص + تأییدِ کد پستی در املاک و اسکان' },
        postal: { ic: 'fa-map-location-dot', c: '#0891b2', d: 'فقط نتیجه‌ی کد پستی (کد ملی و تولدِ یک شخص هم لازم است)' },
        legal: { ic: 'fa-building-circle-check', c: '#059669', d: 'مشخصاتِ ثبتیِ شرکت با شناسه‌ی ملیِ ۱۱ رقمی' },
    };
    const ST_UI = { OK: ['موفق', '#059669', 'fa-circle-check'], WARN: ['با هشدار', '#d97706', 'fa-triangle-exclamation'], FAIL: ['ناموفق', '#e11d48', 'fa-circle-xmark'],
                    QUEUED: ['در صف', '#64748b', 'fa-hourglass-half'], RUNNING: ['در حال اجرا', '#4f46e5', 'fa-spinner fa-spin'] };
    const SRC_FA = { panel: 'پنل', form: 'فرمِ ثبت', group: 'گروهی', miniapp: 'مینی‌اپ' };
    const V = { el: null, boot: null, tab: 'single', kind: 'person_postal', hist: { page: 1, q: '', kind: '', status: '', source: '' }, running: false, batchRun: {} };

    function css() {
        if (document.getElementById('inq-css')) return;
        const s = document.createElement('style'); s.id = 'inq-css';
        s.textContent = `
        .inq-hero{position:relative;overflow:hidden;border-radius:24px;padding:20px 22px;color:#fff;background:linear-gradient(120deg,#0f172a,#312e81 50%,#0e7490);box-shadow:0 24px 50px -30px #312e81}
        .inq-hero::after{content:'';position:absolute;width:280px;height:280px;border-radius:50%;left:-80px;top:-160px;background:rgba(255,255,255,.08)}
        .inq-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 12px;font-size:11px;font-weight:800;background:rgba(255,255,255,.14)}
        .inq-chip i.dot{width:8px;height:8px;border-radius:50%;display:inline-block}
        .inq-tabs{display:flex;gap:6px;flex-wrap:wrap;background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:5px}
        .inq-tab{flex:1;min-width:110px;border-radius:13px;padding:9px 12px;font-size:12px;font-weight:900;color:#475569;display:flex;align-items:center;justify-content:center;gap:7px}
        .inq-tab.on{background:linear-gradient(120deg,#4338ca,#7c3aed);color:#fff;box-shadow:0 10px 24px -14px #4338ca}
        .inq-card{background:#fff;border:1.5px solid #e9edf5;border-radius:20px;padding:18px}
        .inq-kinds{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}@media(max-width:900px){.inq-kinds{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .inq-kind{border:2px solid #e2e8f0;border-radius:16px;padding:12px;text-align:right;transition:.15s;background:#fff}
        .inq-kind:hover{border-color:#c7d2fe}.inq-kind.on{border-color:var(--c);background:color-mix(in srgb,var(--c) 7%,#fff);box-shadow:0 10px 24px -18px var(--c)}
        .inq-kind .ic{width:36px;height:36px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;color:#fff;background:var(--c);margin-bottom:6px}
        .inq-kind b{display:block;font-size:13px;color:#0f172a}.inq-kind small{display:block;font-size:10.5px;color:#64748b;line-height:1.7;margin-top:2px}
        .inq-f{display:block;font-size:11px;font-weight:800;color:#475569}
        .inq-in{margin-top:5px;width:100%;border:1.5px solid #dbe3ee;border-radius:12px;padding:10px 12px;font-size:14px;font-weight:800;letter-spacing:.5px;background:#f8fafc;outline:none}
        .inq-in:focus{border-color:#6366f1;background:#fff;box-shadow:0 0 0 4px #e0e7ff}.inq-in.err{border-color:#f43f5e;background:#fff1f2}
        .inq-go{border-radius:14px;padding:11px 20px;font-weight:900;color:#fff;background:linear-gradient(120deg,#4338ca,#7c3aed);box-shadow:0 12px 26px -14px #4338ca}
        .inq-go:disabled{opacity:.55}
        .inq-steps{display:flex;gap:6px;align-items:center;margin:14px 0 4px}
        .inq-step{flex:1;height:8px;border-radius:9px;background:#e2e8f0;position:relative;overflow:hidden}
        .inq-step.ok{background:#10b981}.inq-step.warn{background:#f59e0b}.inq-step.bad{background:#f43f5e}
        .inq-step.run::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,#818cf8,transparent);animation:inqrun 1.1s linear infinite}
        @keyframes inqrun{from{transform:translateX(100%)}to{transform:translateX(-100%)}}
        .inq-res{border-radius:18px;padding:16px;border:1.5px solid}
        .inq-dl{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}@media(max-width:700px){.inq-dl{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .inq-dl div{background:rgba(255,255,255,.7);border-radius:12px;padding:8px 10px}.inq-dl span{display:block;font-size:10px;color:#64748b;font-weight:700}.inq-dl b{font-size:13px;color:#0f172a}
        .inq-tl li{border-right:3px solid #e2e8f0;padding:6px 12px 8px;margin-right:6px;position:relative}
        .inq-tl li::before{content:'';position:absolute;right:-7px;top:10px;width:11px;height:11px;border-radius:50%;background:var(--c,#94a3b8);box-shadow:0 0 0 3px #fff}
        .inq-tbl{width:100%;font-size:11.5px}.inq-tbl th{background:#f8fafc;color:#64748b;font-weight:800;padding:8px;text-align:right;white-space:nowrap}.inq-tbl td{padding:8px;border-top:1px solid #f1f5f9;vertical-align:top}
        .inq-badge{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:10.5px;font-weight:800;white-space:nowrap}
        .inq-bar{height:9px;border-radius:9px;background:#e0e7ff;overflow:hidden}.inq-bar i{display:block;height:100%;background:linear-gradient(90deg,#6366f1,#22d3ee);transition:width .3s}
        .inq-sw{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1.5px solid #e9edf5;border-radius:14px;cursor:pointer}
        .inq-sw input{margin-top:3px;accent-color:#4f46e5;width:16px;height:16px}
        .inq-modal{position:fixed;inset:0;z-index:1000003;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;padding:14px}
        .inq-modal>div{background:#fff;border-radius:22px;width:min(1100px,100%);max-height:92vh;display:flex;flex-direction:column;overflow:hidden}
        .inq-fb{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:6px}
        .inq-fbtn{font-size:10.5px;font-weight:800;border-radius:9px;padding:4px 10px;background:#eef2ff;color:#4338ca;white-space:nowrap}
        .inq-fbtn:disabled{opacity:.5}.inq-fmsg{font-size:10.5px;font-weight:700}`;
        document.head.appendChild(s);
    }
    const badge = st => { const u = ST_UI[st] || ['', '#64748b', 'fa-circle']; return `<span class="inq-badge" style="background:${u[1]}1a;color:${u[1]}"><i class="fas ${u[2]}"></i>${u[0]}</span>`; };
    const pName = it => it.person && (it.person.name || it.person.family) ? `${it.person.name} ${it.person.family}`.trim() : (it.person_name || '');

    // ---------------- اجرای یک استعلام (ثبت ← اجرا + خواندنِ پیشرفت) ----------------
    async function run(body, onProg) {
        const c = await post(Object.assign({ action: 'create' }, body));
        if (!c.ok) return c;
        if (c.item.done) { if (onProg) onProg(c.item); return { ok: true, item: c.item }; }
        let done = null;
        const ex = post({ action: 'exec', id: c.id }).then(r => { done = r; });
        let last = c.item;
        while (!done) {
            await sleep(1300);
            if (done) break;
            const g = await post({ action: 'get', id: c.id });
            if (g.ok) { last = g.item; if (onProg) onProg(last); if (last.done) break; }
        }
        await ex.catch(() => {});
        const f = done && done.ok ? done.item : (await post({ action: 'get', id: c.id })).item || last;
        if (onProg) onProg(f);
        return { ok: true, item: f };
    }

    // ---------------- نمای اصلی ----------------
    async function init(el) {
        css(); V.el = el;
        el.innerHTML = '<div class="inq-card text-center text-xs text-slate-400 py-10"><i class="fas fa-spinner fa-spin ml-1"></i>در حال بارگذاری…</div>';
        const b = await post({ action: 'boot' });
        if (!b.ok) { el.innerHTML = `<div class="inq-card text-red-600 text-xs">${esc(b.error)}</div>`; return; }
        V.boot = b;
        if (!b.can.single && V.tab === 'single') V.tab = b.can.group_view ? 'group' : 'history';
        render();
    }
    function statusChip(s) {
        const m = { logged_in: ['متصل به سامانه', '#34d399'], logged_out: ['نیاز به ورود (کپچا)', '#fbbf24'], down: ['سامانه در دسترس نیست', '#f87171'], busy: ['مشغولِ استعلام', '#a5b4fc'] }[s];
        return m ? `<span class="inq-chip"><i class="dot" style="background:${m[1]}"></i>${m[0]}</span>` : '';
    }
    function render() {
        const b = V.boot, t = b.today || {};
        const tabs = [['single', 'fa-magnifying-glass', 'استعلامِ تکی', b.can.single], ['group', 'fa-file-excel', 'استعلامِ گروهی', b.can.group_view], ['history', 'fa-clock-rotate-left', 'تاریخچه', true], ['settings', 'fa-sliders', 'تنظیمات', b.is_admin]];
        V.el.innerHTML = `
        <div class="inq-hero mb-4"><div class="relative flex flex-wrap items-center justify-between gap-3" style="z-index:1">
            <div><p class="text-[11px] opacity-75">شخص حقیقی، کد پستی (املاک و اسکان) و شخص حقوقی</p><h2 class="font-black text-xl mt-0.5"><i class="fas fa-user-check ml-1"></i>استعلامات</h2></div>
            <div class="flex flex-wrap items-center gap-2">${b.is_admin ? statusChip(b.status) + (b.status === 'logged_out' ? '<button class="inq-chip" style="background:#fbbf24;color:#422006" data-a="login"><i class="fas fa-key"></i>ورود</button>' : '') : ''}
                <span class="inq-chip">امروز: ${fa(t.total || 0)} استعلام · ${fa(t.ok || 0)} موفق${t.queue ? ' · ' + fa(t.queue) + ' در صف' : ''}</span></div></div></div>
        <div class="inq-tabs mb-4">${tabs.filter(x => x[3]).map(x => `<button class="inq-tab ${V.tab === x[0] ? 'on' : ''}" data-tab="${x[0]}"><i class="fas ${x[1]}"></i>${x[2]}</button>`).join('')}</div>
        <div id="inq-body"></div>`;
        V.el.querySelectorAll('[data-tab]').forEach(x => x.onclick = () => { V.tab = x.dataset.tab; render(); });
        const lb = V.el.querySelector('[data-a="login"]'); if (lb) lb.onclick = () => loginDialog();
        const body = V.el.querySelector('#inq-body');
        ({ single: renderSingle, group: renderGroup, history: renderHistory, settings: renderSettings })[V.tab](body);
    }

    // ---------------- استعلامِ تکی ----------------
    function renderSingle(body) {
        const k = V.kind, kinds = V.boot.kinds;
        body.innerHTML = `<div class="inq-card space-y-4">
            <div class="inq-kinds">${Object.keys(kinds).map(x => `<button type="button" class="inq-kind ${k === x ? 'on' : ''}" data-k="${x}" style="--c:${KIND_UI[x].c}"><span class="ic"><i class="fas ${KIND_UI[x].ic}"></i></span><b>${kinds[x]}</b><small>${KIND_UI[x].d}</small></button>`).join('')}</div>
            ${k === 'legal' && !V.boot.sapi_ready ? '<p class="text-[11px] font-bold text-amber-700 bg-amber-50 rounded-xl p-2.5"><i class="fas fa-circle-info ml-1"></i>کلیدِ s.api.ir هنوز در «تنظیمات ← کلیدهای API» وارد نشده است.</p>' : ''}
            <form id="inq-form" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end" autocomplete="off">
                ${k === 'legal' ? `<label class="inq-f md:col-span-2">شناسه‌ی ملیِ شرکت (۱۱ رقم)<input class="inq-in" name="company_nid" dir="ltr" inputmode="numeric" maxlength="11" placeholder="10101234567"></label>`
                    : `<label class="inq-f">کد ملی<input class="inq-in" name="national_id" dir="ltr" inputmode="numeric" maxlength="10" placeholder="0012345678"></label>
                       <label class="inq-f">تاریخ تولد<input class="inq-in" name="birth" dir="ltr" inputmode="numeric" placeholder="1370/05/12"></label>
                       ${k !== 'person' ? '<label class="inq-f">کد پستی (۱۰ رقم)<input class="inq-in" name="postal" dir="ltr" inputmode="numeric" maxlength="10" placeholder="1234567890"></label>' : ''}`}
                <button class="inq-go ${k === 'person' ? 'md:col-span-2' : ''}" type="submit"><i class="fas fa-magnifying-glass ml-1"></i>استعلام</button>
            </form>
            ${V.boot.is_admin ? '<label class="flex items-center gap-2 text-[11px] font-bold text-slate-500"><input type="checkbox" id="inq-fresh" class="accent-indigo-600"> نتیجه‌ی قبلی (کش) استفاده نشود و دوباره از سامانه پرسیده شود</label>' : ''}
            <div id="inq-out"></div></div>`;
        body.querySelectorAll('[data-k]').forEach(x => x.onclick = () => { V.kind = x.dataset.k; renderSingle(body); });
        const f = body.querySelector('#inq-form');
        f.querySelectorAll('input').forEach(i => i.addEventListener('input', () => { if (i.name !== 'birth') i.value = en(i.value).replace(/\D/g, ''); i.classList.remove('err'); }));
        f.onsubmit = async e => {
            e.preventDefault();
            if (V.running) return;
            const d = Object.fromEntries(new FormData(f).entries());
            Object.keys(d).forEach(x => d[x] = en(d[x]).trim());
            const btn = f.querySelector('button'); btn.disabled = true; V.running = true;
            const outEl = body.querySelector('#inq-out');
            const fresh = body.querySelector('#inq-fresh');
            const r = await run(Object.assign({ kind: k, fresh: fresh && fresh.checked ? 1 : 0 }, d), it => { outEl.innerHTML = resultHtml(it, true); bindResult(outEl, it); });
            btn.disabled = false; V.running = false;
            if (!r.ok) { if (r.field) { const i = f.querySelector(`[name="${r.field === 'national_id' && k === 'legal' ? 'company_nid' : r.field}"]`); if (i) { i.classList.add('err'); i.focus(); } } toast(r.error || 'خطا', 'error'); }
            else if (r.item.need_login && V.boot.is_admin) loginDialog();
        };
    }
    function stepsBar(it) {
        const n = it.steps || 1, tl = it.timeline || [];
        let h = '';
        for (let i = 1; i <= n; i++) {
            const x = tl[i - 1];
            const cls = x ? (x.status === 'ok' ? 'ok' : x.status === 'bad' ? 'bad' : 'warn') : (!it.done && i === tl.length + 1 && it.status === 'RUNNING' ? 'run' : '');
            h += `<div class="inq-step ${cls}" title="${esc(x ? x.title : 'گام ' + fa(i))}"></div>`;
        }
        return `<div class="inq-steps">${h}</div>`;
    }
    function resultHtml(it, big) {
        const ui = ST_UI[it.status] || ST_UI.QUEUED;
        const tone = it.status === 'OK' ? '#ecfdf5;border-color:#a7f3d0' : it.status === 'FAIL' ? '#fff1f2;border-color:#fecdd3' : it.status === 'WARN' ? '#fffbeb;border-color:#fde68a' : '#f8fafc;border-color:#e2e8f0';
        let inner = '';
        if (!it.done) inner = `<p class="text-xs font-bold text-indigo-700"><i class="fas fa-spinner fa-spin ml-1"></i>${esc(it.notice || 'در حال آماده‌سازی…')}</p>`;
        else {
            if (it.company) {
                const c = it.company;
                inner += `<div class="inq-dl">${[['نام', c.name], ['نوع', c.company_type], ['شماره ثبت', c.register_number], ['تاریخ ثبت', c.register_date], ['استان / شهر', [c.province, c.city].filter(Boolean).join(' / ')],
                    ['کد پستی', c.postal_code], ['وضعیت', c.active === false ? 'غیرفعال' : c.active ? 'فعال' : '—'], ['آدرس', c.address]].map(x => `<div><span>${x[0]}</span><b>${esc(fa(x[1] || '—'))}</b></div>`).join('')}</div>`;
            } else if (it.person || it.person_name) {
                const p = it.person || {};
                inner += `<div class="inq-dl">${[['نام', p.name || it.person_name], ['نام خانوادگی', p.family], ['نام پدر', p.father || it.father_name], ['جنسیت', p.gender]].map(x => `<div><span>${x[0]}</span><b>${esc(x[1] || '—')}</b></div>`).join('')}</div>`;
            }
            if (it.kind === 'person_postal' || it.kind === 'postal') {
                const v = it.postal_valid, c = v === true ? '#059669' : v === false ? '#e11d48' : '#d97706';
                inner += `<div class="mt-3 rounded-xl p-3 text-xs font-bold" style="background:${c}14;color:${c}"><i class="fas ${v === true ? 'fa-circle-check' : v === false ? 'fa-circle-xmark' : 'fa-triangle-exclamation'} ml-1"></i>کد پستی ${fa(it.postal || '')}: ${esc(it.postal_text || (v === true ? 'تأیید شد' : v === false ? 'تأیید نشد' : 'نامشخص'))}</div>`;
            }
            if (it.status === 'FAIL' && !(it.kind === 'person_postal' || it.kind === 'postal') || (it.status === 'FAIL' && !it.person && !it.postal_text)) inner += `<p class="mt-2 text-xs font-bold text-rose-700">${esc(it.notice || 'استعلام انجام نشد.')}</p>`;
        }
        return `<div class="inq-res mt-1" style="background:${tone}">
            <div class="flex flex-wrap items-center justify-between gap-2"><b class="text-sm text-slate-800"><i class="fas ${(KIND_UI[it.kind] || {}).ic || 'fa-search'} ml-1"></i>${esc(it.kind_fa)} ${it.national_id ? '· <span dir="ltr">' + fa(it.national_id) + '</span>' : ''}</b>
                <span class="flex items-center gap-2">${it.cached ? '<span class="inq-badge" style="background:#e0e7ff;color:#4338ca"><i class="fas fa-bolt"></i>از نتیجه‌ی قبلی</span>' : ''}${badge(it.status)}${it.ms ? `<small class="text-slate-400">${fa((it.ms / 1000).toFixed(1))} ثانیه</small>` : ''}</span></div>
            ${it.kind !== 'legal' ? stepsBar(it) : ''}${inner}
            ${V.boot && V.boot.is_admin && it.timeline && it.timeline.length ? `<details class="mt-3" ${big && !it.done ? 'open' : ''}><summary class="text-[11px] font-black text-slate-500 cursor-pointer">جزئیاتِ گام‌ها (فقط مدیر کل)</summary>${timelineHtml(it)}</details>` : ''}</div>`;
    }
    function timelineHtml(it) {
        const col = { ok: '#10b981', warn: '#f59e0b', bad: '#f43f5e' };
        return `<ul class="inq-tl mt-2 space-y-1">${(it.timeline || []).map(x => `<li style="--c:${col[x.status] || '#94a3b8'}">
            <div class="flex flex-wrap items-center justify-between gap-2"><b class="text-[11.5px] text-slate-700">${esc(x.title)}</b>
                <span class="flex items-center gap-2"><small class="text-slate-400">${esc(fa(x.time || ''))}</small>${(it.step_pages || []).includes(x.step) ? `<button class="inq-fbtn" data-page="${x.step}"><i class="fas fa-eye ml-1"></i>صفحه‌ی سامانه</button>` : ''}</span></div>
            ${x.detail ? `<p class="text-[11px] text-slate-500 mt-0.5 leading-6">${esc(x.detail)}</p>` : ''}
            ${x.live ? liveHtml(x.live) : ''}</li>`).join('')}</ul>`;
    }
    function liveHtml(l) {
        const cb = l.checkboxes || {}, p = l.person || {}, tf = l.tarafTable || {};
        return `<details class="mt-1"><summary class="text-[10.5px] text-indigo-600 font-bold cursor-pointer">وضعیتِ فرمِ سامانه در این گام</summary>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-1.5 mt-1 text-[10.5px] text-slate-600">
            <div class="bg-slate-50 rounded-lg p-2"><b>نوعِ شخص و تیک‌ها:</b> ${esc(l.noeShakhs)} · بیمه‌گذار ${cb.isBimehgozar ? '✅' : '❌'} اصلی ${cb.isTarafAsli ? '✅' : '❌'} متعهد ${cb.isPardakhtKonandeh ? '✅' : '❌'}</div>
            <div class="bg-slate-50 rounded-lg p-2"><b>کد ملی / ID:</b> ${esc(l.codeMelli)} · ${esc(l.shakhsId)}</div>
            <div class="bg-slate-50 rounded-lg p-2"><b>تاریخ تولد در فرم:</b> ${esc(l.birthYMD)}</div>
            <div class="bg-slate-50 rounded-lg p-2"><b>شخص در فرم:</b> ${esc((p.name || '—') + ' ' + (p.family || ''))} (فرزند ${esc(p.fatherName || '—')})</div>
            <div class="bg-slate-50 rounded-lg p-2 md:col-span-2"><b>جدولِ طرف قرارداد:</b> ${tf.added ? '✅ ' : '⚠️ '}${esc(tf.text || '')}</div>
            <div class="bg-slate-50 rounded-lg p-2 md:col-span-2"><b>پیامِ سامانه:</b> ${esc(l.serverNotice || '')}</div></div></details>`;
    }
    function bindResult(el, it) {
        el.querySelectorAll('[data-page]').forEach(b => b.onclick = () => stepPage(it.id, +b.dataset.page));
    }
    function stepPage(id, n) {
        const url = `${API}?action=step_page&id=${id}&n=${n}`;
        const m = document.createElement('div'); m.className = 'inq-modal';
        m.innerHTML = `<div><div class="flex items-center justify-between p-3 border-b"><b class="text-sm">صفحه‌ی واقعیِ سامانه‌ی پاسارگاد — گام ${fa(n)}</b><span class="flex gap-2"><a href="${url}" target="_blank" class="inq-fbtn">تبِ جدید</a><button class="inq-fbtn" data-x>بستن</button></span></div>
            <iframe src="${url}" sandbox="" style="flex:1;width:100%;min-height:75vh;border:0;background:#fff"></iframe></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
        document.body.appendChild(m);
    }

    // ---------------- گروهی ----------------
    async function renderGroup(body) {
        const can = V.boot.can;
        body.innerHTML = `<div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
            ${can.group ? `<div class="inq-card xl:col-span-2 space-y-3">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-file-arrow-up ml-1 text-indigo-500"></i>استعلامِ گروهیِ تازه</p>
                <label class="inq-f">نوعِ استعلام<select id="inq-gk" class="inq-in" style="font-size:12.5px">${Object.entries(V.boot.kinds).map(([k, l]) => `<option value="${k}" ${k === 'person_postal' ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                <label class="inq-f">عنوان (اختیاری)<input id="inq-gt" class="inq-in" style="font-size:12.5px;letter-spacing:0" placeholder="مثلاً پرسنلِ شرکتِ نمونه - مهر"></label>
                <label class="block border-2 border-dashed border-indigo-200 hover:border-indigo-400 rounded-2xl p-5 text-center cursor-pointer">
                    <i class="fas fa-file-excel text-3xl text-emerald-500"></i><p class="text-xs font-black text-slate-600 mt-2" id="inq-gfn">انتخابِ فایلِ اکسل (xlsx / csv)</p>
                    <p class="text-[10.5px] text-slate-400 mt-1">ستون‌ها با نامِ سرستون شناخته می‌شوند: کد ملی، تاریخ تولد، کد پستی (برای حقوقی: شناسه ملی)</p>
                    <input type="file" id="inq-gf" accept=".xlsx,.xls,.csv" class="hidden"></label>
                <div class="flex flex-wrap gap-2"><a id="inq-gs" class="inq-fbtn" href="#"><i class="fas fa-download ml-1"></i>دانلودِ اکسلِ نمونه</a></div>
                <div id="inq-gp"></div>
                <button id="inq-gstart" class="inq-go w-full hidden"><i class="fas fa-play ml-1"></i>شروعِ استعلامِ گروهی</button>
                <p class="text-[10.5px] text-slate-400 leading-6">ردیف‌ها یکی‌یکی و پشتِ سرِ هم استعلام می‌شوند (نشستِ سامانه یکی است). اگر این صفحه را ببندید، با «ادامه» از همان‌جا جلو می‌رود${V.boot.is_admin ? '؛ اگر cron را در تنظیمات گذاشته باشید، در پس‌زمینه هم ادامه پیدا می‌کند' : ''}.</p>
            </div>` : ''}
            <div class="inq-card ${can.group ? 'xl:col-span-3' : 'xl:col-span-5'}"><div class="flex items-center justify-between mb-3"><p class="font-black text-sm text-slate-800"><i class="fas fa-layer-group ml-1 text-violet-500"></i>استعلام‌های گروهی</p><button class="inq-fbtn" id="inq-gr"><i class="fas fa-rotate"></i></button></div><div id="inq-gl" class="space-y-2"></div></div></div>`;
        if (can.group) {
            const kindSel = body.querySelector('#inq-gk'), fileIn = body.querySelector('#inq-gf'), sample = body.querySelector('#inq-gs');
            const setSample = () => sample.href = `${API}?action=batch_sample&kind=${kindSel.value}`;
            kindSel.onchange = setSample; setSample();
            fileIn.onchange = async () => {
                const f = fileIn.files[0]; if (!f) return;
                body.querySelector('#inq-gfn').textContent = f.name;
                const fd = new FormData(); fd.append('action', 'batch_upload'); fd.append('kind', kindSel.value); fd.append('preview', '1'); fd.append('file', f);
                const p = body.querySelector('#inq-gp'); p.innerHTML = '<p class="text-xs text-indigo-600"><i class="fas fa-spinner fa-spin ml-1"></i>در حال خواندن…</p>';
                let d; try { d = await (await fetch(API, { method: 'POST', body: fd })).json(); } catch (e) { d = { ok: false, error: 'خطا در ارتباط.' }; }
                if (!d.ok) { p.innerHTML = `<p class="text-xs text-rose-600 font-bold">${esc(d.error)}</p>`; return; }
                p.innerHTML = `<p class="text-xs font-bold text-emerald-700 mb-1"><i class="fas fa-circle-check ml-1"></i>${fa(d.count)} ردیف خوانده شد</p>
                    <div class="max-h-48 overflow-auto border rounded-xl"><table class="inq-tbl"><thead><tr><th>#</th><th>کد ملی</th><th>تاریخ تولد</th><th>کد پستی</th></tr></thead><tbody>${d.rows.slice(0, 50).map((r, i) => `<tr><td>${fa(i + 1)}</td><td dir="ltr">${esc(fa(r.national_id))}</td><td dir="ltr">${esc(fa(r.birth || ''))}</td><td dir="ltr">${esc(fa(r.postal || ''))}</td></tr>`).join('')}</tbody></table></div>`;
                const st = body.querySelector('#inq-gstart'); st.classList.remove('hidden');
                st.onclick = async () => {
                    st.disabled = true;
                    const fd2 = new FormData(); fd2.append('action', 'batch_upload'); fd2.append('kind', kindSel.value); fd2.append('title', body.querySelector('#inq-gt').value.trim()); fd2.append('file', f);
                    let r; try { r = await (await fetch(API, { method: 'POST', body: fd2 })).json(); } catch (e) { r = { ok: false, error: 'خطا در ارتباط.' }; }
                    st.disabled = false;
                    if (!r.ok) { toast(r.error, 'error'); return; }
                    toast(`${fa(r.queued)} ردیف در صف` + (r.invalid.length ? ` · ${fa(r.invalid.length)} ردیف نامعتبر کنار گذاشته شد` : ''), r.invalid.length ? 'warning' : 'success');
                    p.innerHTML = r.invalid.length ? `<p class="text-[11px] text-amber-700 font-bold">ردیف‌های نامعتبر: ${r.invalid.slice(0, 20).map(x => fa(x.row) + ' (' + esc(x.error) + ')').join('، ')}</p>` : '';
                    st.classList.add('hidden'); fileIn.value = ''; body.querySelector('#inq-gfn').textContent = 'انتخابِ فایلِ اکسل (xlsx / csv)';
                    await loadBatches(body); runBatch(r.batch_id, body);
                };
            };
        }
        body.querySelector('#inq-gr').onclick = () => loadBatches(body);
        loadBatches(body);
    }
    async function loadBatches(body) {
        const box = body.querySelector('#inq-gl'); if (!box) return;
        const d = await post({ action: 'batches' });
        if (!d.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        if (!d.batches.length) { box.innerHTML = '<p class="text-center text-xs text-slate-400 py-8">هنوز استعلامِ گروهی‌ای ثبت نشده.</p>'; return; }
        box.innerHTML = d.batches.map(batchCard).join('');
        bindBatches(body);
    }
    function batchCard(b) {
        const p = b.total ? b.done / b.total * 100 : 100, c = b.counts, running = V.batchRun[b.id];
        const st = b.status === 'RUNNING' ? (running ? '<span class="inq-badge" style="background:#e0e7ff;color:#4338ca"><i class="fas fa-spinner fa-spin"></i>در حال اجرا</span>' : '<span class="inq-badge" style="background:#fef3c7;color:#92400e">متوقف / منتظرِ ادامه</span>')
            : b.status === 'CANCELED' ? '<span class="inq-badge" style="background:#f1f5f9;color:#64748b">لغو شد</span>' : '<span class="inq-badge" style="background:#d1fae5;color:#065f46"><i class="fas fa-check"></i>تمام شد</span>';
        return `<div class="border border-slate-100 rounded-2xl p-3" data-b="${b.id}">
            <div class="flex flex-wrap items-center justify-between gap-2"><div class="min-w-0"><b class="text-xs text-slate-800">${esc(b.title)}</b><small class="block text-[10.5px] text-slate-400">${esc(b.kind_fa)} · ${esc(fa(b.created_j))} · ${esc(b.user_name || '')}</small></div>${st}</div>
            <div class="inq-bar mt-2"><i style="width:${p.toFixed(1)}%"></i></div>
            <div class="flex flex-wrap items-center justify-between gap-2 mt-2 text-[10.5px] font-bold text-slate-500">
                <span>${fa(b.done)} از ${fa(b.total)} · <span class="text-emerald-600">${fa(c.OK)} موفق</span> · <span class="text-amber-600">${fa(c.WARN)} هشدار</span> · <span class="text-rose-600">${fa(c.FAIL)} ناموفق</span></span>
                <span class="flex flex-wrap gap-1">${V.boot.can.group && b.status === 'RUNNING' && !running ? `<button class="inq-fbtn" data-ba="run"><i class="fas fa-play ml-1"></i>ادامه</button>` : ''}
                ${V.boot.can.group && b.status === 'RUNNING' ? `<button class="inq-fbtn" data-ba="cancel" style="background:#fee2e2;color:#be123c">لغو</button>` : ''}
                ${V.boot.can.group && b.status !== 'RUNNING' && c.FAIL ? `<button class="inq-fbtn" data-ba="retry"><i class="fas fa-rotate-right ml-1"></i>دوباره برای ناموفق‌ها</button>` : ''}
                <button class="inq-fbtn" data-ba="rows"><i class="fas fa-list ml-1"></i>ردیف‌ها</button>
                ${V.boot.can.export ? `<a class="inq-fbtn" href="${API}?action=batch_export&id=${b.id}"><i class="fas fa-file-excel ml-1"></i>خروجیِ اکسل</a>` : ''}</span></div></div>`;
    }
    function bindBatches(body) {
        body.querySelectorAll('[data-b]').forEach(card => {
            const id = +card.dataset.b;
            card.querySelectorAll('[data-ba]').forEach(btn => btn.onclick = async () => {
                const a = btn.dataset.ba;
                if (a === 'run') runBatch(id, body);
                else if (a === 'rows') { V.hist = { page: 1, q: '', kind: '', status: '', source: '', batch_id: id }; V.tab = 'history'; render(); }
                else if (a === 'cancel' || a === 'retry') { delete V.batchRun[id]; const d = await post({ action: a === 'cancel' ? 'batch_cancel' : 'batch_resume', id }); if (!d.ok) toast(d.error, 'error'); await loadBatches(body); if (a === 'retry') runBatch(id, body); }
            });
        });
    }
    async function runBatch(id, body) {
        if (V.batchRun[id]) return;
        V.batchRun[id] = true;
        try {
            for (let i = 0; i < 5000 && V.batchRun[id]; i++) {
                const d = await post({ action: 'batch_step', id });
                if (!d.ok) { toast(d.error, 'error'); if (d.need_login && V.boot.is_admin) loginDialog(); break; }
                const card = body.querySelector(`[data-b="${id}"]`);
                if (card && d.batch) { card.outerHTML = batchCard(d.batch); bindBatches(body); }
                if (!d.batch || d.batch.status !== 'RUNNING') { toast('استعلامِ گروهی تمام شد.', 'success'); break; }
                if (d.last && d.last.need_login) { toast('نشستِ سامانه بسته شد؛ بعد از ورودِ دوباره «ادامه» را بزنید.', 'warning'); if (V.boot.is_admin) loginDialog(); break; }
                if (!d.last) await sleep(2500);
            }
        } finally { delete V.batchRun[id]; const b = body.querySelector('#inq-gl'); if (b && b.isConnected) loadBatches(body); }
    }

    // ---------------- تاریخچه ----------------
    async function renderHistory(body) {
        const h = V.hist;
        body.innerHTML = `<div class="inq-card"><div class="flex flex-wrap gap-2 mb-3 items-center">
            <input id="inq-hq" class="inq-in !mt-0 flex-1 min-w-[12rem]" style="font-size:12px;letter-spacing:0;padding:8px 12px" placeholder="🔍 کد ملی، نام، کد پستی، کاربر" value="${esc(h.q)}">
            <select id="inq-hk" class="inq-in !mt-0 w-auto" style="font-size:12px;padding:8px"><option value="">همه‌ی نوع‌ها</option>${Object.entries(V.boot.kinds).map(([k, l]) => `<option value="${k}" ${h.kind === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
            <select id="inq-hs" class="inq-in !mt-0 w-auto" style="font-size:12px;padding:8px"><option value="">همه‌ی وضعیت‌ها</option>${['OK', 'WARN', 'FAIL', 'QUEUED'].map(s => `<option value="${s}" ${h.status === s ? 'selected' : ''}>${ST_UI[s][0]}</option>`).join('')}</select>
            <select id="inq-hsrc" class="inq-in !mt-0 w-auto" style="font-size:12px;padding:8px"><option value="">همه‌ی منبع‌ها</option>${Object.entries(SRC_FA).map(([k, l]) => `<option value="${k}" ${h.source === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
            ${h.batch_id ? `<span class="inq-badge" style="background:#ede9fe;color:#5b21b6">گروهِ #${fa(h.batch_id)} <button data-clrb>✕</button></span>` : ''}
            ${V.boot.can.export ? `<a id="inq-hx" class="inq-fbtn" href="#"><i class="fas fa-file-excel ml-1"></i>خروجیِ اکسل</a>` : ''}</div>
            <div id="inq-hl" class="overflow-x-auto"><p class="text-center text-xs text-slate-400 py-8"><i class="fas fa-spinner fa-spin"></i></p></div></div>`;
        const q = body.querySelector('#inq-hq'); let tm;
        q.oninput = () => { clearTimeout(tm); tm = setTimeout(() => { h.q = q.value.trim(); h.page = 1; loadHist(body); }, 350); };
        [['#inq-hk', 'kind'], ['#inq-hs', 'status'], ['#inq-hsrc', 'source']].forEach(([s, k]) => body.querySelector(s).onchange = e => { h[k] = e.target.value; h.page = 1; loadHist(body); });
        const cb = body.querySelector('[data-clrb]'); if (cb) cb.onclick = () => { delete h.batch_id; renderHistory(body); };
        loadHist(body);
    }
    async function loadHist(body) {
        const h = V.hist, box = body.querySelector('#inq-hl');
        const x = body.querySelector('#inq-hx');
        if (x) x.href = `${API}?action=history_export&` + new URLSearchParams(Object.fromEntries(Object.entries(h).filter(([k, v]) => v && k !== 'page'))).toString();
        const d = await post(Object.assign({ action: 'history' }, h));
        if (!d.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        if (!d.items.length) { box.innerHTML = '<p class="text-center text-xs text-slate-400 py-10">استعلامی پیدا نشد.</p>'; return; }
        box.innerHTML = `${!d.all ? '<p class="text-[10.5px] text-slate-400 mb-2">فقط استعلام‌های خودتان نشان داده می‌شود.</p>' : ''}<table class="inq-tbl"><thead><tr><th>#</th><th>زمان</th><th>نوع</th><th>کد/شناسه ملی</th><th>تاریخ تولد</th><th>نتیجه‌ی شخص</th><th>کد پستی</th><th>وضعیت</th><th>منبع / کاربر</th><th></th></tr></thead><tbody>
            ${d.items.map(it => `<tr>
                <td class="text-slate-400">${fa(it.batch_id ? it.row_no : it.id)}</td><td class="whitespace-nowrap">${esc(fa(it.created_j))}</td><td>${esc(it.kind_fa)}</td>
                <td dir="ltr" class="font-mono">${esc(fa(it.national_id || ''))}</td><td dir="ltr">${esc(fa(it.birth || ''))}</td>
                <td><b>${esc(it.company ? it.company.name : pName(it) || '—')}</b>${it.father_name ? `<small class="block text-slate-400">فرزند ${esc(it.father_name)}</small>` : ''}</td>
                <td>${it.postal ? `<span dir="ltr">${fa(it.postal)}</span>${it.postal_valid === true ? ' <i class="fas fa-circle-check text-emerald-600"></i>' : it.postal_valid === false ? ' <i class="fas fa-circle-xmark text-rose-600"></i>' : ''}${it.postal_text ? `<small class="block text-slate-400 max-w-[14rem]">${esc(it.postal_text)}</small>` : ''}` : '—'}</td>
                <td>${badge(it.status)}${it.cached ? ' <i class="fas fa-bolt text-indigo-400" title="از نتیجه‌ی قبلی"></i>' : ''}${it.status === 'FAIL' && it.notice ? `<small class="block text-rose-500 max-w-[14rem]">${esc(it.notice)}</small>` : ''}</td>
                <td><small>${esc(SRC_FA[it.source] || it.source)}</small><small class="block text-slate-400">${esc(it.user_name || '')}</small></td>
                <td class="whitespace-nowrap">${V.boot.is_admin ? `<button class="inq-fbtn" data-det="${it.id}">جزئیات</button>` : `<button class="inq-fbtn" data-det="${it.id}">نتیجه</button>`}${V.boot.can.delete ? ` <button class="inq-fbtn" data-del="${it.id}" style="background:#fee2e2;color:#be123c">✕</button>` : ''}</td></tr>`).join('')}</tbody></table>
            ${d.pages > 1 ? `<div class="flex items-center justify-center gap-2 mt-3 text-xs"><button class="inq-fbtn" data-pg="-1" ${d.page <= 1 ? 'disabled' : ''}>قبلی</button><span>${fa(d.page)} از ${fa(d.pages)} (${fa(d.total)})</span><button class="inq-fbtn" data-pg="1" ${d.page >= d.pages ? 'disabled' : ''}>بعدی</button></div>` : ''}`;
        box.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { h.page += +b.dataset.pg; loadHist(body); });
        box.querySelectorAll('[data-det]').forEach(b => b.onclick = () => detail(+b.dataset.det));
        box.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (!confirm('این استعلام از تاریخچه حذف شود؟')) return; const r = await post({ action: 'delete', id: +b.dataset.del }); if (!r.ok) toast(r.error, 'error'); loadHist(body); });
    }
    async function detail(id) {
        const d = await post({ action: 'get', id });
        if (!d.ok) { toast(d.error, 'error'); return; }
        const m = document.createElement('div'); m.className = 'inq-modal';
        m.innerHTML = `<div><div class="flex items-center justify-between p-4 border-b"><b class="text-sm">استعلامِ #${fa(id)} · ${esc(fa(d.item.created_j))}${d.item.user_name ? ' · ' + esc(d.item.user_name) : ''}</b><button class="inq-fbtn" data-x>بستن</button></div><div class="p-4 overflow-y-auto">${resultHtml(d.item, true)}</div></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
        document.body.appendChild(m);
        const det = m.querySelector('details'); if (det) det.open = true;
        bindResult(m, d.item);
    }

    // ---------------- تنظیمات (مدیر کل) ----------------
    async function renderSettings(body) {
        body.innerHTML = '<div class="inq-card text-center text-xs text-slate-400 py-8"><i class="fas fa-spinner fa-spin"></i></div>';
        const d = await post({ action: 'settings_get' });
        if (!d.ok) { body.innerHTML = `<div class="inq-card text-xs text-rose-600">${esc(d.error)}</div>`; return; }
        const s = d.settings;
        const sw = (k, t, h) => `<label class="inq-sw"><input type="checkbox" data-s="${k}" ${s[k] === '1' ? 'checked' : ''}><span><b class="text-xs text-slate-700">${t}</b>${h ? `<small class="block text-[10.5px] text-slate-400 leading-5 mt-0.5">${h}</small>` : ''}</span></label>`;
        const num = (k, t, h) => `<label class="inq-f">${t}<input type="number" min="0" data-s="${k}" class="inq-in" style="font-size:13px" value="${esc(s[k])}">${h ? `<small class="block text-[10px] text-slate-400 font-normal mt-1">${h}</small>` : ''}</label>`;
        body.innerHTML = `<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="inq-card space-y-3">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-plug ml-1 text-indigo-500"></i>اتصال به سامانه‌ی ریتونِ پاسارگاد</p>
                <div class="flex flex-wrap items-center gap-2">${statusChip(d.status).replace('inq-chip', 'inq-chip !bg-slate-800 text-white')}<button class="inq-fbtn" id="inq-ss"><i class="fas fa-rotate ml-1"></i>بررسیِ دوباره</button><button class="inq-go !py-2 !px-4 text-xs" id="inq-sl"><i class="fas fa-key ml-1"></i>ورود با کپچا</button></div>
                <label class="inq-f">نام کاربریِ سامانه<input id="inq-su" class="inq-in" dir="ltr" style="font-size:13px" value="${esc(d.username)}"></label>
                <label class="inq-f">رمزِ سامانه<input id="inq-sp" type="password" class="inq-in" dir="ltr" style="font-size:13px" placeholder="${d.has_password ? '•••••• (ذخیره شده؛ برای تغییر بنویسید)' : ''}"></label>
                <p class="text-[10.5px] text-slate-400 leading-6">رمز رمزنگاری‌شده روی هاست ذخیره می‌شود. نشستِ سامانه بینِ همه‌ی استعلام‌ها مشترک است؛ اگر بسته شود به شما در ربات پیام می‌آید و تا ورودِ دوباره، استعلام‌ها خطای «اطلاعات را دستی وارد کنید» می‌دهند.</p>
                <div class="rounded-2xl bg-slate-50 p-3"><p class="text-[11px] font-black text-slate-600 mb-1"><i class="fas fa-clock ml-1"></i>نگه‌داشتنِ نشست + ادامه‌ی استعلامِ گروهی در پس‌زمینه</p>
                    <p class="text-[10.5px] text-slate-500 leading-6">در cPanel ← Cron Jobs هر ۵ دقیقه این دستور را بگذارید:</p>
                    <code class="block text-[10.5px] bg-white border rounded-lg p-2 mt-1 break-all" dir="ltr" id="inq-cron">wget -q -O /dev/null "${esc(d.cron_url)}"</code>
                    <div class="flex gap-2 mt-2"><button class="inq-fbtn" id="inq-cc"><i class="far fa-copy ml-1"></i>کپی</button><button class="inq-fbtn" id="inq-ck">کلیدِ تازه</button></div></div>
            </div>
            <div class="inq-card space-y-2.5">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-sliders ml-1 text-violet-500"></i>رفتارِ استعلام‌ها</p>
                ${sw('inq_enabled', 'استعلام‌ها فعال باشد')}
                ${sw('inq_miniapp_person', 'استعلامِ هوشمندِ شخص در مینی‌اپ', 'به محضِ واردکردنِ کد ملی و تاریخ تولد، نام و نام خانوادگی خودکار پر می‌شود (اگر نیامد، کاربر دستی می‌نویسد)')}
                ${sw('inq_miniapp_postal', 'دکمه‌ی استعلامِ کد پستی در مینی‌اپ و درخواستِ کاربران')}
                ${sw('inq_postal_required', 'استعلامِ کد پستی برای کاربران اجباری باشد', 'کاربر تا کد پستیِ تأییدشده (املاک و اسکان) نزند، نمی‌تواند درخواست را ادامه دهد')}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-1">
                    ${num('inq_wait_sec', 'مکث بینِ گام‌ها (ثانیه)', '۰ تا ۹؛ پیش‌فرض ۲')}
                    ${num('inq_cache_days', 'اعتبارِ نتیجه‌ی قبلی (روز)', '۰ = همیشه دوباره بپرس')}
                    ${num('inq_daily_limit', 'سقفِ روزانه برای هر کاربرِ مینی‌اپ', '۰ = بدونِ سقف')}
                </div>
                <button class="inq-go w-full mt-2" id="inq-ssave"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تنظیمات</button>
            </div></div>`;
        body.querySelector('#inq-ss').onclick = async () => { const r = await post({ action: 'status' }); V.boot.status = r.status; renderSettings(body); };
        body.querySelector('#inq-sl').onclick = () => loginDialog(() => renderSettings(body));
        body.querySelector('#inq-cc').onclick = () => { try { navigator.clipboard.writeText(body.querySelector('#inq-cron').textContent); toast('کپی شد.', 'info'); } catch (e) {} };
        body.querySelector('#inq-ck').onclick = async () => { if (!confirm('کلیدِ cron عوض شود؟ دستورِ قبلی دیگر کار نمی‌کند.')) return; await post({ action: 'settings_save', new_cron_key: 1 }); renderSettings(body); };
        body.querySelector('#inq-ssave').onclick = async () => {
            const b = { action: 'settings_save', username: body.querySelector('#inq-su').value.trim(), password: body.querySelector('#inq-sp').value };
            body.querySelectorAll('[data-s]').forEach(i => b[i.dataset.s] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : en(i.value));
            const r = await post(b); toast(r.ok ? 'ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
            if (r.ok) renderSettings(body);
        };
    }
    async function loginDialog(after) {
        const m = document.createElement('div'); m.className = 'inq-modal';
        m.innerHTML = `<div style="width:min(420px,100%)"><div class="p-5 space-y-3"><div class="flex items-center justify-between"><b class="text-sm"><i class="fas fa-key ml-1 text-indigo-500"></i>ورود به سامانه‌ی پاسارگاد</b><button class="inq-fbtn" data-x>✕</button></div>
            <div id="inq-cap" class="text-center rounded-xl bg-slate-50 p-3 min-h-[70px]"><i class="fas fa-spinner fa-spin text-slate-400"></i></div>
            <label class="inq-f">متنِ تصویر<input id="inq-lc" class="inq-in" dir="ltr" autocomplete="off"></label>
            <details><summary class="text-[11px] text-slate-500 cursor-pointer">نام کاربری / رمزِ دیگر</summary><input id="inq-lu" class="inq-in" dir="ltr" placeholder="خالی = ذخیره‌شده"><input id="inq-lp" type="password" class="inq-in" dir="ltr" placeholder="خالی = ذخیره‌شده">
                <label class="flex items-center gap-2 text-[11px] mt-2"><input type="checkbox" id="inq-lr" checked> ذخیره شود</label></details>
            <p id="inq-lh" class="text-[11px] font-bold text-rose-600"></p>
            <button class="inq-go w-full" id="inq-lb">ورود</button></div></div>`;
        document.body.appendChild(m);
        const close = () => m.remove();
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) close(); };
        const cap = async () => {
            const box = m.querySelector('#inq-cap'); box.innerHTML = '<i class="fas fa-spinner fa-spin text-slate-400"></i>';
            const r = await post({ action: 'captcha' });
            if (!r.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(r.error)}</p>`; return; }
            if (r.loggedIn) { box.innerHTML = '<p class="text-xs font-bold text-emerald-600">نشست باز است؛ نیازی به ورود نیست.</p>'; return; }
            box.innerHTML = `<img src="${r.image}" class="mx-auto rounded-lg" alt="کپچا"><button class="inq-fbtn mt-2" data-rc>↻ تصویرِ دیگر</button>`;
            box.querySelector('[data-rc]').onclick = cap;
            m.querySelector('#inq-lc').focus();
        };
        cap();
        const go = async () => {
            const btn = m.querySelector('#inq-lb'); btn.disabled = true;
            const r = await post({ action: 'login', captcha: en(m.querySelector('#inq-lc').value.trim()), username: m.querySelector('#inq-lu').value.trim(), password: m.querySelector('#inq-lp').value, remember: m.querySelector('#inq-lr').checked ? 1 : 0 });
            btn.disabled = false;
            if (!r.ok) { m.querySelector('#inq-lh').textContent = r.error || 'ورود ناموفق بود.'; m.querySelector('#inq-lc').value = ''; cap(); return; }
            toast('وارد شدید؛ استعلام‌ها آماده‌اند.', 'success'); close();
            if (V.boot) { V.boot.status = 'logged_in'; if (V.el && V.el.isConnected && !after) render(); }
            if (after) after();
        };
        m.querySelector('#inq-lb').onclick = go;
        m.querySelector('#inq-lc').addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
    }

    // ---------------- استعلام داخلِ فرم‌ها ----------------
    // opts: { nid: () => کد ملی, birth: input, postal: input, name: input (پر می‌شود), father: input (اختیاری), onPerson(p), onPostal(z) }
    // کنارِ «نام» دکمه‌ی «استعلامِ نام» و کنارِ «کد پستی» دکمه‌ی «استعلام» می‌آید؛ دستی نوشتن همیشه آزاد است.
    function attachForm(opts) {
        css();
        const mk = (input, label, kind) => {
            if (!input || input.dataset.inqBound) return null;
            input.dataset.inqBound = '1';
            const wrap = document.createElement('div'); wrap.className = 'inq-fb';
            wrap.innerHTML = `<button type="button" class="inq-fbtn"><i class="fas fa-magnifying-glass ml-1"></i>${label}</button><span class="inq-fmsg text-slate-400"></span>`;
            input.insertAdjacentElement('afterend', wrap);
            const btn = wrap.querySelector('button'), msg = wrap.querySelector('span');
            btn.onclick = async () => {
                const nid = en(typeof opts.nid === 'function' ? opts.nid() : (opts.nid && opts.nid.value) || '').replace(/\D/g, '');
                const birth = en(opts.birth ? opts.birth.value : '').trim();
                const postal = en(opts.postal ? opts.postal.value : '').replace(/\D/g, '');
                if (!nid || !birth) { msg.className = 'inq-fmsg text-amber-600'; msg.textContent = 'اول کد ملی و تاریخ تولد را وارد کنید.'; return; }
                if (kind === 'postal' && postal.length !== 10) { msg.className = 'inq-fmsg text-amber-600'; msg.textContent = 'کد پستیِ ۱۰ رقمی را وارد کنید.'; return; }
                btn.disabled = true; msg.className = 'inq-fmsg text-indigo-600'; msg.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حال استعلام…';
                const r = await run({ kind: kind === 'postal' ? 'postal' : 'person', source: 'form', national_id: nid, birth, postal }, it => { if (!it.done) msg.innerHTML = `<i class="fas fa-spinner fa-spin ml-1"></i>${esc(it.notice || 'در حال استعلام…')}`; });
                btn.disabled = false;
                if (!r.ok) { msg.className = 'inq-fmsg text-rose-600'; msg.textContent = r.error || 'خطا'; return; }
                const it = r.item;
                if (kind === 'postal') {
                    const v = it.postal_valid;
                    msg.className = 'inq-fmsg ' + (v === true ? 'text-emerald-600' : v === false ? 'text-rose-600' : 'text-amber-600');
                    msg.innerHTML = `<i class="fas ${v === true ? 'fa-circle-check' : v === false ? 'fa-circle-xmark' : 'fa-triangle-exclamation'} ml-1"></i>${esc(it.postal_text || it.notice || '')}`;
                    if (opts.onPostal) opts.onPostal(it);
                } else {
                    const p = it.person || {};
                    if (it.status !== 'FAIL' && (p.name || it.person_name)) {
                        const full = `${p.name || ''} ${p.family || ''}`.trim() || it.person_name;
                        if (opts.name) { opts.name.value = full; opts.name.dispatchEvent(new Event('input', { bubbles: true })); }
                        if (opts.father && (p.father || it.father_name)) opts.father.value = p.father || it.father_name;
                        msg.className = 'inq-fmsg text-emerald-600';
                        msg.innerHTML = `<i class="fas fa-circle-check ml-1"></i>${esc(full)}${p.father ? ' · فرزند ' + esc(p.father) : ''}${it.cached ? ' (از استعلامِ قبلی)' : ''}`;
                        if (opts.onPerson) opts.onPerson(it);
                    } else { msg.className = 'inq-fmsg text-rose-600'; msg.textContent = (it.notice || 'مشخصات پیدا نشد') + ' — دستی وارد کنید.'; }
                }
            };
            return wrap;
        };
        mk(opts.name, 'استعلامِ نام', 'person');
        mk(opts.postal, 'استعلامِ کد پستی', 'postal');
    }

    window.Inquiry = { init, attachForm, run, login: loginDialog };
})();
