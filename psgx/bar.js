// فایل: psgx/bar.js
// نوارِ بالای «سایتِ بیمه‌گر» روی صفحه‌های خودِ سامانه‌ی پاسارگاد (از راهِ پروکسیِ psgx): جای فضا نمی‌گیرد (روی صفحه می‌نشیند و جمع می‌شود)
//   راست: لوگو و نامِ پرتال، نشست · وسط: ابزارهای همین صفحه · چپ: بزرگنمایی، اعلان‌ها، کاربر، ساعت، تاریخ، بازگشت به پنل
//   صفحه‌ی «دریافتِ حق بیمه»: قرارداد ← اقساط (همان موتورِ مالیِ سایت) ← ثبتِ خودکارِ گام‌به‌گام (+ ثبتِ نهایی)
//   فرمِ پیش‌نویس: پیش‌فرض‌ها (اعمال / ساختن از همین صفحه) و «پر کردن از ردیفِ درخواست»
//   پنل (همان مرورگر) با BroadcastChannel('psgx') دستور می‌دهد (مثلاً «این ردیف را بنشان») و از کارها باخبر می‌شود
(function () {
    'use strict';
    if (window.top !== window || window.__psgxBar) return;
    window.__psgxBar = true;
    const C = Object.assign({}, window.__PSGX || {});
    // نشانی‌های کامل روی دامنه‌ی خودمان (شیمِ پروکسی هر نشانیِ «/…» را به پاسارگاد می‌فرستد)
    ['api', 'panel', 'root', 'logo'].forEach(k => { if (typeof C[k] === 'string' && (C[k] === '' || C[k].charAt(0) === '/') && C[k].charAt(1) !== '/') C[k] = location.origin + C[k]; });
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const nrm = s => String(s || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, ' ');
    const money = n => fa(Number(n || 0).toLocaleString('en-US'));
    const LS = {get(k, d) { try { const v = localStorage.getItem('psgx_' + k); return v == null ? d : v; } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('psgx_' + k, v); } catch (e) {} }};
    const SS = {get(k) { try { return JSON.parse(sessionStorage.getItem('psgx_' + k) || 'null'); } catch (e) { return null; } }, set(k, v) { try { if (v == null) sessionStorage.removeItem('psgx_' + k); else sessionStorage.setItem('psgx_' + k, JSON.stringify(v)); } catch (e) {} }};
    async function api(file, body) {
        try { const r = await fetch(C.api + file, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}); return await r.json(); }
        catch (e) { return {ok: false, error: 'ارتباط با پنل برقرار نشد.'}; }
    }
    const bar = (a, b) => api('psgx_bar_actions.php', Object.assign({action: a}, b || {}));
    let chan = null; try { chan = new BroadcastChannel('psgx'); } catch (e) {}
    const tell = m => { try { chan && chan.postMessage(Object.assign({from: 'bar', page: C.page}, m)); } catch (e) {} };

    // ---------- بزرگنمایی (پیش‌فرض ۸۰٪) ----------
    let Z = parseFloat(LS.get('zoom', '0.8')) || 0.8;
    function zoom(z) { Z = Math.min(1.3, Math.max(0.5, Math.round(z * 20) / 20)); LS.set('zoom', String(Z)); document.documentElement.style.zoom = Z; if (host) host.style.zoom = 1 / Z; const zl = $('[data-zl]'); if (zl) zl.textContent = fa(Math.round(Z * 100)) + '٪'; }

    // ---------- خواندنِ صفحه ----------
    const txt = () => nrm(document.body ? document.body.innerText : '');
    const byName = n => document.getElementsByName(n);
    const has = n => byName(n).length > 0;
    function after(label, rx) { const t = txt(); const i = t.indexOf(label); if (i < 0) return ''; const m = t.slice(i + label.length, i + label.length + 120).match(rx); return m ? m[1].trim() : ''; }
    const PAGE = (() => {
        const ent = (byName('entity')[0] || {}).value || '';
        if (has('tedadAghsaat') || /daryaftGharardadBimeh/i.test(ent)) return 'finance';
        if (document.getElementById('editform') && (document.getElementById('editform:save') || has('shomarehShasi') || has('shomarehMotor')) && /Pishnevis|pishnevis/.test(ent + location.pathname)) return 'draft';
        if (document.getElementById('exampleform')) return 'list';
        return 'other';
    })();
    const kindOfPage = () => /Badaneh|badaneh/.test(((byName('entity')[0] || {}).value || '') + location.pathname) || /بدنه/.test(after('نوع بيمه نامه', /\s*([^\n\t]+)/) || after('نوع بیمه نامه', /\s*([^\n\t]+)/)) ? 'body' : 'third';

    // ---------- نوشتنِ خانه‌ها (بی‌صدا؛ رویدادِ change نمی‌زنیم تا صفحه وسطِ کار ارسال نشود) ----------
    function setField(name, val) {
        const els = byName(name);
        if (!els.length) {
            const f = document.getElementById('editform'); if (!f) return false;
            const h = document.createElement('input'); h.type = 'hidden'; h.name = name; h.value = Array.isArray(val) ? val.join(',') : String(val); f.appendChild(h); return true;
        }
        const arr = Array.isArray(val) ? val.map(String) : null;
        let ok = false;
        for (const el of els) {
            if (el.type === 'checkbox') { const on = arr ? arr.includes(el.value) : (val === true || val === 'true' || val === 'on' || String(val) === el.value); if (el.checked !== on) el.checked = on; ok = true; }
            else if (el.type === 'radio') { el.checked = String(val) === el.value; ok = ok || el.checked; }
            else if (el.tagName === 'SELECT') { if ([...el.options].some(o => o.value === String(val))) { el.value = String(val); ok = true; } }
            else { el.value = arr ? arr.join(',') : String(val); ok = true; }
        }
        return ok;
    }
    function setDate(name, ymd) { const [y, m, d] = ymd.split('/'); setField(name, `${y}/${m}/${d};HIJRI`); setField(name + '_y', y); setField(name + '_m', m); setField(name + '_d', d); }
    function applyFields(F) { const miss = []; Object.keys(F || {}).forEach(k => { if (!setField(k, F[k])) miss.push(k); }); return miss; }
    function pageErrors() {
        const out = [];
        try { if (Array.isArray(window.errorList)) window.errorList.forEach(e => out.push(typeof e === 'string' ? e : (e && (e.message || e.msg)) || JSON.stringify(e))); } catch (e) {}
        if (!out.length) { const m = document.documentElement.innerHTML.match(/errorList\.push\(\s*['"]([^'"]{3,300})/g); if (m) m.forEach(x => out.push(x.replace(/^errorList\.push\(\s*['"]/, ''))); }
        return out;
    }
    function submitJsf(id) {
        try { if (window.myfaces && myfaces.oam && myfaces.oam.submitForm) return myfaces.oam.submitForm('editform', id); } catch (e) {}
        const el = document.getElementById(id); if (el) el.click();
    }

    // ---------- پوسته ----------
    let host = null, R = null;
    const $ = q => R ? R.querySelector(q) : null;
    function css() {
        const fs = document.createElement('style');
        fs.textContent = `@font-face{font-family:PsgxV;src:url(${C.root}/Font/Vazir-Regular.woff2) format('woff2');font-weight:400}@font-face{font-family:PsgxV;src:url(${C.root}/Font/Vazir-Bold.woff2) format('woff2');font-weight:700}`;
        document.head.appendChild(fs);
        return `:host{all:initial}*{box-sizing:border-box;font-family:PsgxV,tahoma,sans-serif}
        .wrap{position:fixed;top:0;left:0;right:0;z-index:2147483646;direction:rtl;transition:transform .28s cubic-bezier(.2,.8,.2,1);transform:translateY(0)}
        .wrap.hid{transform:translateY(calc(-100% + 5px))}
        .bar{display:flex;align-items:center;gap:10px;height:46px;padding:0 12px;color:#e2e8f0;background:linear-gradient(100deg,rgba(15,23,42,.94),rgba(12,74,110,.93) 55%,rgba(67,56,202,.92));backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);box-shadow:0 8px 30px -12px rgba(2,6,23,.65);border-bottom:1px solid rgba(255,255,255,.08)}
        .strip{position:absolute;left:0;right:0;bottom:-5px;height:5px;background:linear-gradient(90deg,#f59e0b,#0ea5e9,#6366f1)}
        .tab{position:absolute;left:50%;transform:translateX(-50%);bottom:-22px;height:18px;padding:0 12px;border-radius:0 0 10px 10px;background:#0c4a6e;color:#e0f2fe;font-size:10.5px;display:flex;align-items:center;gap:5px;cursor:pointer;box-shadow:0 4px 10px -4px rgba(0,0,0,.4)}
        .brand{display:flex;align-items:center;gap:8px;min-width:0}.brand img{height:28px;max-width:110px;object-fit:contain;filter:drop-shadow(0 1px 2px rgba(0,0,0,.4));background:#fff;border-radius:8px;padding:2px 5px}
        .logo{width:30px;height:30px;border-radius:10px;display:grid;place-items:center;background:linear-gradient(135deg,#0ea5e9,#6366f1);font-weight:700;color:#fff}
        .t1{font-weight:700;font-size:13px;color:#fff;white-space:nowrap}.t2{font-size:10.5px;color:#93c5fd;white-space:nowrap}
        .chip{font-size:10.5px;padding:2px 9px;border-radius:999px;white-space:nowrap}.chip.s{background:rgba(245,158,11,.18);color:#fcd34d;border:1px solid rgba(245,158,11,.35)}.chip.o{background:rgba(16,185,129,.16);color:#6ee7b7;border:1px solid rgba(16,185,129,.35)}
        .mid{flex:1;display:flex;justify-content:center;gap:6px;min-width:0;overflow:hidden}
        .b{all:unset;cursor:pointer;display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 11px;border-radius:10px;font-size:12px;font-weight:700;color:#e0f2fe;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);white-space:nowrap;transition:background .15s}
        .b:hover{background:rgba(255,255,255,.18)}.b.on{background:#f8fafc;color:#0c4a6e}.b.go{background:linear-gradient(135deg,#10b981,#0d9488);border-color:transparent;color:#fff}.b.warn{background:linear-gradient(135deg,#f59e0b,#ea580c);border-color:transparent;color:#fff}
        .b[disabled]{opacity:.5;pointer-events:none}.ic{font-size:14px;line-height:1}
        .end{display:flex;align-items:center;gap:8px}
        .clock{display:flex;flex-direction:column;align-items:center;line-height:1.15;padding:0 6px}.clock b{font-size:13.5px;color:#fff;letter-spacing:.5px}.clock span{font-size:10px;color:#bae6fd}
        .user{font-size:11.5px;color:#e2e8f0;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .zoom{display:flex;align-items:center;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12)}.zoom button{all:unset;cursor:pointer;width:24px;text-align:center;color:#e0f2fe;font-size:14px}.zoom span{font-size:11px;min-width:36px;text-align:center;color:#fff}
        .bell{position:relative}.bell i{position:absolute;top:-4px;left:-4px;min-width:16px;height:16px;border-radius:9px;background:#ef4444;color:#fff;font-size:9.5px;font-style:normal;display:grid;place-items:center;padding:0 3px}
        .pop{position:fixed;top:54px;z-index:2147483647;width:min(440px,calc(100vw - 20px));max-height:calc(100vh - 80px);overflow:auto;background:#fff;color:#0f172a;border-radius:18px;box-shadow:0 30px 70px -20px rgba(2,6,23,.55);border:1px solid #e2e8f0;direction:rtl;font-size:12.5px;animation:pin .18s ease-out}
        @keyframes pin{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
        .ph{padding:12px 14px;border-radius:18px 18px 0 0;color:#fff;background:linear-gradient(120deg,#0c4a6e,#4338ca);display:flex;align-items:center;gap:8px}.ph b{font-size:13.5px}.ph .x{margin-right:auto;cursor:pointer;opacity:.8}
        .pb{padding:12px 14px;display:flex;flex-direction:column;gap:10px}
        .card{border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px;background:#f8fafc}.card h4{margin:0 0 6px;font-size:12.5px;color:#334155}
        label.l{display:flex;flex-direction:column;gap:4px;font-size:11px;color:#475569}
        .in{all:unset;box-sizing:border-box;border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:6px 9px;font-size:12px;color:#0f172a;width:100%}
        select.in{appearance:auto;-webkit-appearance:auto}
        .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.muted{color:#64748b;font-size:10.8px}
        .pbtn{all:unset;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:7px 12px;border-radius:11px;font-size:12px;font-weight:700;color:#fff;background:#0369a1}
        .pbtn.g{background:linear-gradient(135deg,#10b981,#0d9488)}.pbtn.v{background:#7c3aed}.pbtn.l{background:#e2e8f0;color:#334155}.pbtn.r{background:#e11d48}.pbtn[disabled]{opacity:.5;pointer-events:none}
        table{width:100%;border-collapse:collapse;font-size:11.5px}th{background:#e2e8f0;color:#334155;padding:5px;text-align:right}td{padding:5px;border-bottom:1px solid #e2e8f0}
        .it{padding:8px 10px;border-radius:12px;border:1px solid #e2e8f0;background:#fff;cursor:pointer}.it:hover{border-color:#0ea5e9;background:#f0f9ff}
        .note{padding:8px 10px;border-radius:12px;font-size:11.5px}.note.y{background:#fef3c7;color:#92400e}.note.r{background:#ffe4e6;color:#9f1239}.note.g{background:#dcfce7;color:#166534}.note.b{background:#e0f2fe;color:#075985}
        .toasts{position:fixed;left:14px;bottom:14px;z-index:2147483647;display:flex;flex-direction:column;gap:8px;direction:rtl}
        .toast{min-width:240px;max-width:380px;padding:10px 12px;border-radius:14px;background:#0f172a;color:#e2e8f0;font-size:12px;box-shadow:0 16px 40px -14px rgba(0,0,0,.6);border-right:4px solid #0ea5e9;animation:pin .2s}
        .toast.success{border-color:#10b981}.toast.error{border-color:#ef4444}.toast.warning{border-color:#f59e0b}.toast b{display:block;color:#fff;margin-bottom:2px}
        .chk{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#334155}
        .fl{max-height:260px;overflow:auto;display:flex;flex-direction:column;gap:3px}.fl label{display:flex;gap:6px;align-items:flex-start;font-size:11px;padding:3px 4px;border-radius:7px}.fl label:hover{background:#f1f5f9}.fl code{direction:ltr;color:#64748b;font-size:10px}
        @media (max-width:900px){.t2,.user,.clock span{display:none}.mid .b span{display:none}}`;
    }
    function toast(msg, type, title) {
        let box = $('.toasts'); if (!box) return;
        const t = document.createElement('div'); t.className = 'toast ' + (type || ''); t.innerHTML = (title ? `<b>${esc(title)}</b>` : '') + esc(msg);
        box.appendChild(t); setTimeout(() => { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 450); }, type === 'error' ? 9000 : 5000);
    }
    let popEl = null;
    function pop(title, icon, html, anchorLeft) {
        closePop();
        popEl = document.createElement('div'); popEl.className = 'pop';
        popEl.style[anchorLeft ? 'left' : 'right'] = '10px';
        if (!anchorLeft) { popEl.style.right = '50%'; popEl.style.transform = 'translateX(50%)'; }
        popEl.innerHTML = `<div class="ph"><span class="ic">${icon || ''}</span><b>${esc(title)}</b><span class="x" data-x>✕</span></div><div class="pb">${html}</div>`;
        R.appendChild(popEl);
        popEl.querySelector('[data-x]').onclick = closePop;
        pinned(true);
        return popEl.querySelector('.pb');
    }
    function closePop() { if (popEl) { popEl.remove(); popEl = null; } }

    // جمع شدنِ نوار: بعد از چند ثانیه بی‌کاری (مگر سنجاق باشد یا پنجره‌ای باز باشد)
    let hideT = null, pin = LS.get('pin', '0') === '1', hold = false;
    function pinned(h) { hold = !!h; show(); }
    function show() { const w = $('.wrap'); if (!w) return; w.classList.remove('hid'); clearTimeout(hideT); if (!pin) hideT = setTimeout(() => { if (!hold && !popEl && !w.matches(':hover')) w.classList.add('hid'); }, 3200); }

    function build() {
        host = document.createElement('div'); host.id = 'psgx-host';
        document.documentElement.appendChild(host);
        R = host.attachShadow({mode: 'open'});
        const st = document.createElement('style'); st.textContent = css(); R.appendChild(st);
        const w = document.createElement('div'); w.className = 'wrap';
        const tools = PAGE === 'finance' ? `<button class="b go" data-t="fin"><span class="ic">💳</span><span>اقساط و ثبتِ خودکار</span></button><button class="b" data-t="finset"><span class="ic">⚙️</span><span>قراردادِ پیش‌فرض</span></button>`
            : PAGE === 'draft' ? `<button class="b go" data-t="row"><span class="ic">📋</span><span>پر کردن از ردیفِ درخواست</span></button><button class="b" data-t="preset"><span class="ic">🎛️</span><span>پیش‌فرض‌ها</span></button>`
            : PAGE === 'list' ? `<span class="t2">برای چاپ، مالی و اصلاح می‌توانید از بخشِ «پیش‌نویس‌ها»ی پنل هم استفاده کنید.</span>` : '';
        w.innerHTML = `<div class="bar">
            <div class="brand">${C.logo ? `<img src="${esc(C.logo)}" alt="">` : '<div class="logo">ب</div>'}<div><div class="t1">سایتِ بیمه‌گر · پاسارگاد</div><div class="t2">از راهِ پنلِ بیمه با ما</div></div>
                <span class="chip ${C.mode === 's' ? 's' : 'o'}" title="${C.mode === 's' ? 'همان نشستی که استعلام‌ها و پیش‌نویس‌ها دارند؛ «خروج» نزنید' : 'نشستِ جدای خودتان'}">${C.mode === 's' ? 'نشستِ سرور' : 'نشستِ جدا'}</span></div>
            <div class="mid">${tools}</div>
            <div class="end">
                <div class="zoom" title="بزرگنمایی صفحه"><button data-z="-">−</button><span data-zl></span><button data-z="+">+</button></div>
                <button class="b bell" data-t="notif" title="اعلان‌های پنل"><span class="ic">🔔</span><i data-nc style="display:none">0</i></button>
                <span class="user" title="کاربرِ پنل">👤 ${esc(C.user || '')}</span>
                <div class="clock"><b data-clk>--:--</b><span data-date></span></div>
                <button class="b" data-pin title="سنجاق: نوار همیشه باز بماند">${pin ? '📌' : '📍'}</button>
                <button class="b warn" data-back title="بازگشت به پنل"><span class="ic">↩</span><span>بازگشت به پنل</span></button>
            </div></div><div class="strip"></div><div class="tab" data-tab>▾ نوارِ ابزار</div>`;
        R.appendChild(w);
        const tb = document.createElement('div'); tb.className = 'toasts'; R.appendChild(tb);
        w.addEventListener('mouseenter', show); w.addEventListener('mouseleave', show);
        R.querySelector('[data-tab]').onclick = show;
        // لبه‌ی بالای صفحه: باز شدنِ نوار
        document.addEventListener('mousemove', e => { if (e.clientY * Z < 8) show(); }, {passive: true});
        R.querySelectorAll('[data-z]').forEach(b => b.onclick = () => zoom(Z + (b.dataset.z === '+' ? 0.05 : -0.05)));
        R.querySelector('[data-pin]').onclick = e => { pin = !pin; LS.set('pin', pin ? '1' : '0'); e.currentTarget.textContent = pin ? '📌' : '📍'; show(); };
        R.querySelector('[data-back]').onclick = back;
        R.querySelectorAll('[data-t]').forEach(b => b.onclick = () => ({fin: finUI, finset: finSettingsUI, row: rowUI, preset: presetUI, notif: notifUI})[b.dataset.t]());
        zoom(Z); tick(); setInterval(tick, 1000); show();
    }
    function back() {
        tell({type: 'back'});
        try { if (window.opener && !window.opener.closed && /dashboard\.php/.test(window.opener.location.pathname)) { window.opener.focus(); window.close(); return; } } catch (e) {}
        location.href = C.panel;
    }
    function tick() {
        const d = new Date();
        const c = $('[data-clk]'); if (c) c.textContent = fa(d.toLocaleTimeString('en-GB', {hour12: false}));
        const t = $('[data-date]'); if (t && (!t.textContent || d.getSeconds() === 0)) { try { t.textContent = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'}).format(d); } catch (e) { t.textContent = ''; } }
    }

    // ---------- اعلان‌ها (همان فیدِ پنل) ----------
    let NA = null, NI = [], NN = 0;
    async function notifPoll() {
        try {
            const r = await (await fetch(C.api + 'notifications_feed.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({after_id: NA})})).json();
            if (!r || !r.ok) return;
            if (r.persisted) {
                NI = (r.items || []).slice(0, 30);
                if (NA !== null) (r.new || []).forEach(n => { NN++; toast(n.body || '', 'info', n.title || 'اعلانِ پنل'); });
                NA = r.max_id;
            } else (r.events || []).forEach(n => { NN++; NI.unshift(n); toast(n.body || '', 'info', n.title || 'اعلانِ پنل'); });
            const nc = $('[data-nc]'); if (nc) { nc.style.display = NN ? 'grid' : 'none'; nc.textContent = fa(NN); }
        } catch (e) {}
    }
    function notifUI() {
        NN = 0; const nc = $('[data-nc]'); if (nc) nc.style.display = 'none';
        pop('اعلان‌های پنل', '🔔', NI.length ? NI.map(n => `<div class="it" style="cursor:default"><b style="font-size:12px">${esc(n.title || '')}</b><div class="muted">${esc(n.body || '')}</div><div class="muted" style="font-size:10px">${esc(fa(n.at_jalali || n.at || ''))}</div></div>`).join('') : '<p class="muted">اعلانِ تازه‌ای نیست.</p>', true);
    }

    // ---------- مالی (دریافتِ حق بیمه) ----------
    let CTX = null;
    async function ctx(no, agreement) { if (CTX && CTX._no === no) return CTX; const r = await bar('ctx', Object.assign({no, kind: kindOfPage()}, agreement ? {agreement} : {})); if (r.ok) { CTX = r; CTX._no = no; } return r; }
    const SRC_FA = {row: 'قراردادِ شرکتِ ردیفِ سایت', agreement: 'طبقِ موافقت‌نامه‌ی پیش‌نویس', default: 'قراردادِ پیش‌فرضِ تنظیماتِ مالی'};
    function finRead() {
        const no = en(after('شماره پیش نویس', /\s*([\d\/\-]+)/) || after('شماره پیش‌نویس', /\s*([\d\/\-]+)/));
        const remain = en(after('مبلغ ریالی مانده', /\s*([\d,]+)/)).replace(/,/g, '');
        const issue = en(after('تاریخ صدور', /\s*(1[34]\d\d\/\d\d?\/\d\d?)/));
        const acc = {id: (byName('editform:ghest_tab_sanadtaahodavar:hesabBimehAghsat')[0] || {}).value || '', desc: (byName('editform:ghest_tab_sanadtaahodavar:hesabBimehAghsat.desc')[0] || {}).value || ''};
        const agreement = (after('موافقت نامه', /[\s:：]*([^\n]{2,80})/) || after('موافقت‌نامه', /[\s:：]*([^\n]{2,80})/)).trim();
        return {no, remain: +remain || 0, issue, kind: kindOfPage(), acc, agreement};
    }
    async function finUI() {
        const P = finRead();
        const b = pop('اقساط و ثبتِ خودکار', '💳', '<p class="muted">در حالِ خواندن…</p>');
        const r = await ctx(P.no, P.agreement);
        if (!r.ok) { b.innerHTML = `<div class="note r">${esc(r.error || 'خطا')}</div>`; return; }
        const L = r.link, F = r.fin, K = r.contracts || [], RS = r.resolved;
        const def = (RS && RS.contract_id) || F.default[P.kind] || (K[0] && K[0].id) || 0;
        b.innerHTML = `
            <div class="card"><div class="row"><span>پیش‌نویس <b dir="ltr">${esc(fa(P.no || '—'))}</b></span><span class="muted">${P.kind === 'body' ? 'بدنه' : 'ثالث'} · صدور ${esc(fa(P.issue || '—'))}</span></div>
                <div class="row" style="margin-top:4px"><span>مبلغِ مانده: <b>${money(P.remain)}</b> ریال</span></div>
                ${L ? `<div class="note b" style="margin-top:6px">ردیفِ سایت: درخواست #${fa(L.request)} · ${esc(L.company || '')}</div>` : ''}
                ${RS ? `<div class="note g" style="margin-top:6px">قرارداد: ${esc(SRC_FA[RS.source] || '')}${RS.agreement ? ' («' + esc(fa(RS.agreement)) + '»)' : ''}</div>` : '<div class="note y" style="margin-top:6px">قراردادی برای این پیش‌نویس معلوم نشد؛ خودتان انتخاب کنید یا در «قراردادِ پیش‌فرض» تنظیم کنید.</div>'}</div>
            <label class="l">قرارداد (اقساط از موتورِ مالیِ سایت)<select class="in" data-c>${K.map(c => `<option value="${c.id}" ${+c.id === +def ? 'selected' : ''}>${esc(c.name)}${c.contract_no ? ' (' + esc(fa(c.contract_no)) + ')' : ''} — ${c.pay_type === 'CASH' ? 'نقد' : fa(c.installment_count) + ' قسط'}</option>`).join('')}</select></label>
            <div data-plan class="muted">…</div>
            <div class="card"><h4>حسابِ پاسارگاد برای اقساط</h4><div data-acc class="muted"></div></div>
            <label class="chk"><input type="checkbox" data-final ${F.final_save ? 'checked' : ''}>بعد از ثبتِ اقساط، «ثبتِ نهایی» هم زده شود</label>
            <div class="row"><button class="pbtn g" data-run>▶ ثبتِ خودکارِ اقساط</button><button class="pbtn l" data-fill>فقط پر کردنِ گامِ اول</button>
                <button class="pbtn v" data-save>ثبتِ نهایی</button></div>
            <p class="muted">هر گام یک بار دکمه‌ی «ثبتِ اقساط»ِ خودِ سامانه را می‌زند؛ صفحه دوباره باز می‌شود و نوار خودش گامِ بعد را ادامه می‌دهد. با پیامِ قرمزِ سامانه متوقف می‌شود.</p>`;
        let PLAN = null;
        const sel = b.querySelector('[data-c]');
        async function plan() {
            const cid = +sel.value; const box = b.querySelector('[data-plan]');
            if (!cid) { box.innerHTML = '<div class="note y">هیچ قراردادی در «مالی ← قراردادها» تعریف نشده.</div>'; return; }
            if (!P.remain) { box.innerHTML = '<div class="note g">مبلغِ مانده صفر است؛ اقساط ثبت شده.</div>'; PLAN = null; return; }
            box.textContent = '…';
            const p = await bar('fin_plan', {contract_id: cid, total: P.remain, issue: P.issue});
            if (!p.ok) { box.innerHTML = `<div class="note r">${esc(p.error)}</div>`; PLAN = null; return; }
            PLAN = p;
            box.innerHTML = `<div class="card"><h4>${esc(fa(p.plan))}</h4><table><tr><th>#</th><th>سررسید</th><th>مبلغ</th></tr>${p.schedule.map(s => `<tr><td>${fa(s.n)}</td><td>${fa(s.due)}</td><td>${money(s.amount)}</td></tr>`).join('')}</table>
                <p class="muted" style="margin-top:4px">در سامانه ${fa(p.steps.length)} گام: ${p.steps.map(s => fa(s.count) + ' قسطِ ' + money(s.amount) + ' از ' + fa(s.due)).join(' ، ')}</p></div>`;
            const acc = p.account; const ab = b.querySelector('[data-acc]');
            ab.innerHTML = P.acc.id ? `حسابِ فعلیِ صفحه: <b>${esc(fa(P.acc.desc || P.acc.id))}</b>${acc && acc.id !== P.acc.id ? `<div class="note y" style="margin-top:4px">برای این قرارداد «${esc(fa(acc.desc))}» ذخیره شده (همان به کار می‌رود)</div>` : ''}${r.can_edit ? ' <button class="pbtn l" data-sacc style="padding:3px 8px">ذخیره برای این قرارداد</button>' : ''}`
                : acc ? `از تنظیمات: <b>${esc(fa(acc.desc || acc.id))}</b>` : '<div class="note y">حسابی انتخاب نشده؛ یک بار در فرمِ سامانه «حسابِ بیمه» را انتخاب کنید و «ذخیره برای این قرارداد» را بزنید.</div>';
            const sa = ab.querySelector('[data-sacc]'); if (sa) sa.onclick = async () => { const s = await bar('fin_save', {account: {contract: cid, id: P.acc.id, desc: P.acc.desc}}); toast(s.ok ? 'حساب برای این قرارداد ذخیره شد.' : s.error, s.ok ? 'success' : 'error'); CTX = null; };
        }
        sel.onchange = plan; plan();
        b.querySelector('[data-fill]').onclick = () => { if (!PLAN) return toast('برنامه‌ی اقساط آماده نیست.', 'warning'); finStep(PLAN.steps[0], PLAN.account); toast('گامِ اول در فرم نشست؛ خودتان «ثبتِ اقساط» را بزنید.', 'success'); };
        b.querySelector('[data-save]').onclick = () => { if (confirm('«ثبتِ نهایی»ِ فرمِ دریافتِ حق بیمه زده شود؟')) submitJsf('editform:save'); };
        b.querySelector('[data-run]').onclick = () => {
            if (!PLAN || !PLAN.steps.length) return toast('برنامه‌ی اقساط آماده نیست.', 'warning');
            if (!PLAN.account && !P.acc.id) return toast('حسابِ پاسارگادِ اقساط معلوم نیست.', 'warning');
            if (!confirm(`${fa(PLAN.steps.length)} گام اقساط در سامانه ثبت شود؟`)) return;
            SS.set('fin', {no: P.no, steps: PLAN.steps, acc: PLAN.account, i: 0, final: b.querySelector('[data-final]').checked, at: Date.now()});
            finContinue();
        };
    }
    function finStep(s, acc) {
        setField('editform:tabbedPane_indexSubmit', '3');
        setField('tedadAghsaat', String(s.count)); setField('moddatAghsaat', String(s.interval || 1));
        const [y, m, d] = s.due.split('/'); setDate('editform:ghest_tab_sanadtaahodavar:tarikhSarresidAghsat', `${y}/${('0' + m).slice(-2)}/${('0' + d).slice(-2)}`);
        setField('mablaghAghsat', Number(s.amount).toLocaleString('en-US'));
        if (acc && acc.id) { setField('editform:ghest_tab_sanadtaahodavar:hesabBimehAghsat', acc.id); setField('editform:ghest_tab_sanadtaahodavar:hesabBimehAghsat.desc', acc.desc || ''); }
    }
    // ادامه‌ی ثبتِ خودکار بعد از بارگذاریِ دوباره‌ی صفحه
    function finContinue() {
        const st = SS.get('fin'); if (!st) return;
        const P = finRead();
        if (st.no && P.no && st.no !== P.no || Date.now() - st.at > 15 * 60000) { SS.set('fin', null); return; }
        if (st.i > 0) {
            const er = pageErrors();
            if (er.length) { SS.set('fin', null); toast(er.join(' — '), 'error', 'سامانه گامِ ' + fa(st.i) + ' را نپذیرفت'); return; }
        }
        if (st.i >= st.steps.length || P.remain === 0) {
            SS.set('fin', null);
            tell({type: 'fin_done', no: st.no});
            if (st.final && !st.finalDone) { toast('اقساط ثبت شد؛ «ثبتِ نهایی»…', 'success'); SS.set('fin_final', {no: st.no, at: Date.now()}); setTimeout(() => submitJsf('editform:save'), 600); }
            else toast('همه‌ی اقساط ثبت شد.', 'success', 'ثبتِ خودکار');
            return;
        }
        const s = st.steps[st.i];
        toast(`گامِ ${fa(st.i + 1)} از ${fa(st.steps.length)}: ${fa(s.count)} قسطِ ${money(s.amount)}`, 'info', 'ثبتِ خودکارِ اقساط');
        finStep(s, st.acc);
        st.i++; st.at = Date.now(); SS.set('fin', st);
        setTimeout(() => submitJsf('editform:ghest_tab_sanadtaahodavar:aghsaat_savebutton'), 700);
    }
    // مالیِ خودکار: صفحه‌ی «دریافتِ حق بیمه» باز شد، مانده دارد، قرارداد و حساب معلوم است => بعد از ۶ ثانیه (با دکمه‌ی لغو) ثبتِ اقساط شروع می‌شود
    async function finAutoStart() {
        if (SS.get('fin') || SS.get('fin_final')) return;
        const P = finRead();
        if (!P.no || !P.remain || SS.get('fin_auto_' + P.no)) return;
        const r = await ctx(P.no, P.agreement);
        if (!r.ok || !r.fin || !r.fin.auto_run || !r.resolved || !r.resolved.contract_id) return;
        const p = await bar('fin_plan', {contract_id: r.resolved.contract_id, total: P.remain, issue: P.issue});
        if (!p.ok || !p.steps || !p.steps.length) return;
        if (!p.account && !P.acc.id) { toast('مالیِ خودکار: حسابِ پاسارگادِ این قرارداد ذخیره نشده؛ یک بار از «اقساط و ثبتِ خودکار» حساب را ذخیره کنید.', 'warning'); return; }
        const c = (r.contracts || []).find(x => +x.id === +r.resolved.contract_id);
        SS.set('fin_auto_' + P.no, 1);
        let n = 6, cancel = false;
        const b = pop('مالیِ خودکار', '⚡', `<div class="note g">پیش‌نویس <b dir="ltr">${esc(fa(P.no))}</b> · ${esc(c ? c.name : '')}<br>${esc(SRC_FA[r.resolved.source] || '')} · ${esc(fa(p.plan))}</div>
            <p class="muted">${fa(p.steps.length)} گام: ${p.steps.map(s => fa(s.count) + ' قسطِ ' + money(s.amount)).join(' ، ')}${r.fin.final_save ? ' — بعد «ثبتِ نهایی»' : ''}</p>
            <div class="row"><b data-cd>شروع تا ${fa(n)} ثانیه‌ی دیگر…</b><button class="pbtn l" data-cx>لغو</button></div>`);
        b.querySelector('[data-cx]').onclick = () => { cancel = true; closePop(); toast('مالیِ خودکار برای این پیش‌نویس لغو شد.', 'info'); };
        const t = setInterval(() => {
            if (cancel) return clearInterval(t);
            n--; const cd = b.querySelector('[data-cd]'); if (cd) cd.textContent = `شروع تا ${fa(n)} ثانیه‌ی دیگر…`;
            if (n > 0) return;
            clearInterval(t); closePop();
            SS.set('fin', {no: P.no, steps: p.steps, acc: p.account, i: 0, final: !!r.fin.final_save, at: Date.now()});
            tell({type: 'fin_auto', no: P.no, contract: r.resolved.contract_id});
            finContinue();
        }, 1000);
    }
    function finFinalCheck() {
        const f = SS.get('fin_final'); if (!f) return;
        SS.set('fin_final', null);
        const er = pageErrors();
        if (er.length) toast(er.join(' — '), 'error', 'ثبتِ نهایی انجام نشد'); else { toast('ثبتِ نهایی زده شد.', 'success'); tell({type: 'fin_final', no: f.no}); }
    }
    async function finSettingsUI() {
        const b = pop('قراردادِ پیش‌فرضِ هر حالت', '⚙️', '<p class="muted">…</p>');
        const r = await bar('ctx', {});
        if (!r.ok) { b.innerHTML = `<div class="note r">${esc(r.error)}</div>`; return; }
        const K = r.contracts || [], F = r.fin;
        const opt = v => '<option value="0">— هیچ —</option>' + K.map(c => `<option value="${c.id}" ${+c.id === +v ? 'selected' : ''}>${esc(c.name)}${c.contract_no ? ' (' + esc(fa(c.contract_no)) + ')' : ''}</option>`).join('');
        b.innerHTML = `<p class="muted">ترتیب: قراردادِ شرکتِ ردیفِ سایتِ وصل به پیش‌نویس ← قراردادِ نوشته‌شده روی پیش‌نویس (جدولِ زیر) ← این پیش‌فرض‌ها.</p>
            <label class="l">ثالث<select class="in" data-d="third" ${r.can_edit ? '' : 'disabled'}>${opt(F.default.third)}</select></label>
            <label class="l">بدنه<select class="in" data-d="body" ${r.can_edit ? '' : 'disabled'}>${opt(F.default.body)}</select></label>
            <label class="chk"><input type="checkbox" data-fs ${F.final_save ? 'checked' : ''} ${r.can_edit ? '' : 'disabled'}>بعد از اقساط «ثبتِ نهایی» هم زده شود (پیش‌فرض)</label>
            <label class="chk"><input type="checkbox" data-ar ${F.auto_run ? 'checked' : ''} ${r.can_edit ? '' : 'disabled'}>مالیِ خودکار: با باز شدنِ صفحه‌ی «دریافتِ حق بیمه»، اقساط طبقِ قراردادِ همان پیش‌نویس خودش ثبت شود (۶ ثانیه فرصتِ لغو)</label>
            <div class="card"><h4>قراردادِ نوشته‌شده روی پیش‌نویس (موافقت‌نامه) ← قراردادِ مالی</h4>
                <p class="muted">اگر متنِ «موافقت‌نامه/قرارداد»ِ پیش‌نویس شاملِ این عبارت باشد، همین قرارداد به کار می‌رود (بعد از قراردادِ شرکتِ ردیفِ سایت، قبل از پیش‌فرض).</p>
                <div data-map></div>${r.can_edit ? '<button class="pbtn l" data-addmap style="padding:3px 10px">+ ردیفِ تازه</button>' : ''}</div>
            <div class="card"><h4>حساب‌های ذخیره‌شده</h4>${Object.keys(F.accounts || {}).length ? Object.entries(F.accounts).map(([cid, a]) => { const c = K.find(x => +x.id === +cid); return `<div class="row"><span>${esc(c ? c.name : '#' + cid)}</span><b>${esc(fa(a.desc || a.id))}</b></div>`; }).join('') : '<p class="muted">هنوز حسابی ذخیره نشده.</p>'}</div>
            ${r.can_edit ? '<button class="pbtn g" data-s>ذخیره</button>' : '<p class="muted">فقط کسی که «ویرایش»ِ سایتِ بیمه‌گر را دارد تغییر می‌دهد.</p>'}`;
        const mapBox = b.querySelector('[data-map]');
        const mapRow = m => `<div class="row" data-mr style="margin-bottom:4px"><input class="in" data-mm placeholder="مثلاً: ایران خودرو یا شماره‌ی موافقت‌نامه" value="${esc(m.match || '')}" style="flex:1" ${r.can_edit ? '' : 'disabled'}>
            <select class="in" data-mc style="flex:1" ${r.can_edit ? '' : 'disabled'}>${opt(m.contract || 0)}</select>${r.can_edit ? '<button class="pbtn l" data-mx style="padding:3px 8px">✕</button>' : ''}</div>`;
        const bindMap = () => mapBox.querySelectorAll('[data-mx]').forEach(x => x.onclick = () => x.closest('[data-mr]').remove());
        mapBox.innerHTML = (F.map || []).map(mapRow).join('') || (r.can_edit ? '' : '<p class="muted">ردیفی تعریف نشده.</p>'); bindMap();
        const am = b.querySelector('[data-addmap]'); if (am) am.onclick = () => { mapBox.insertAdjacentHTML('beforeend', mapRow({})); bindMap(); };
        const sv = b.querySelector('[data-s]');
        if (sv) sv.onclick = async () => {
            const map = [...mapBox.querySelectorAll('[data-mr]')].map(x => ({match: x.querySelector('[data-mm]').value.trim(), contract: +x.querySelector('[data-mc]').value})).filter(m => m.match && m.contract);
            const s = await bar('fin_save', {default: {third: +b.querySelector('[data-d="third"]').value, body: +b.querySelector('[data-d="body"]').value}, final_save: b.querySelector('[data-fs]').checked ? 1 : 0, auto_run: b.querySelector('[data-ar]').checked ? 1 : 0, map});
            toast(s.ok ? 'ذخیره شد.' : s.error, s.ok ? 'success' : 'error'); CTX = null; if (s.ok) closePop();
        };
    }

    // ---------- فرمِ پیش‌نویس: پر کردن از ردیف ----------
    async function rowFill(plateId) {
        const r = await bar('row_fill', {plate_id: plateId, html: document.documentElement.outerHTML});
        if (!r.ok) { toast(r.error || 'نشد', 'error'); return null; }
        const miss = applyFields(r.fields);
        tell({type: 'row_filled', plate_id: plateId});
        return Object.assign(r, {missKeys: miss});
    }
    function rowUI() {
        const b = pop('پر کردن از ردیفِ درخواست', '📋', `<label class="l">جستجو: شماره‌ی درخواست، پلاک، شاسی/VIN، موتور، نامِ شرکت یا خودرو<input class="in" data-q placeholder="مثلاً 1520 یا N3PLT9…"></label><div data-res class="fl"></div><div data-out></div>`);
        const q = b.querySelector('[data-q]'); q.focus();
        let tm;
        q.oninput = () => { clearTimeout(tm); tm = setTimeout(async () => {
            const v = q.value.trim(); const box = b.querySelector('[data-res]');
            if (v.length < 2) { box.innerHTML = ''; return; }
            const r = await bar('row_search', {q: v, kind: kindOfPage()});
            box.innerHTML = (r.rows || []).map(x => `<div class="it" data-id="${x.id}"><b>${esc(x.car || '—')}</b> <span class="muted">${esc(fa(x.plate))}</span><div class="muted">درخواست #${fa(x.request)} · ${esc(x.company || '')} · <span dir="ltr">${esc(x.chassis || '')}</span>${x.draft ? ' · پیش‌نویس: ' + esc(fa(x.draft)) : ''}${x.status === 'ISSUED' ? ' · صادر شده' : ''}</div></div>`).join('') || '<p class="muted">ردیفی پیدا نشد.</p>';
            box.querySelectorAll('[data-id]').forEach(el => el.onclick = async () => {
                const o = b.querySelector('[data-out]'); o.innerHTML = '<p class="muted">در حالِ پر کردن…</p>';
                const f = await rowFill(+el.dataset.id); if (!f) { o.innerHTML = ''; return; }
                const ins = f.insured || {};
                o.innerHTML = `<div class="note g">${fa(Object.keys(f.fields || {}).length)} خانه از ردیفِ #${fa(f.row.id)} (${esc(f.row.car || '')}) نشست.</div>
                    ${(f.miss || []).length ? `<div class="note y">پیدا نشد: ${esc(f.miss.join('، '))}</div>` : ''}
                    <div class="card"><h4>بیمه‌گذارِ این ردیف</h4><div>${esc(ins.name || '—')} <b dir="ltr">${esc(fa(ins.nid || ''))}</b> ${ins.legal ? '(حقوقی)' : ''}</div>
                        <p class="muted">بیمه‌گذار در جدولِ «طرف قرارداد» با همین ${ins.legal ? 'شناسه‌ی ملی (فهرستِ اشخاصِ حقوقی)' : 'کد ملی'} انتخاب شود${!ins.legal && has('shakhsHaghighi_codeMelli') ? '؛ کد ملی در خانه‌اش نشست' : ''}.</p></div>
                    ${f.preset ? `<button class="pbtn v" data-ap>اعمالِ پیش‌فرضِ «${esc(f.preset.name)}» (همین شرکت/قرارداد)</button>` : ''}`;
                if (!ins.legal && ins.nid && has('shakhsHaghighi_codeMelli')) setField('shakhsHaghighi_codeMelli', ins.nid);
                const ap = o.querySelector('[data-ap]'); if (ap) ap.onclick = () => applyPreset(f.preset.id);
                toast('اطلاعاتِ ردیف در فرم نشست؛ بررسی و «ثبت» با خودتان.', 'success');
            });
        }, 300); };
    }

    // ---------- پیش‌فرض‌ها ----------
    async function applyPreset(id) {
        const r = await ctx(''); const p = (r.presets || []).find(x => x.id === id);
        if (!p) return toast('پیش‌فرض پیدا نشد.', 'error');
        const miss = applyFields(p.values);
        toast(`پیش‌فرضِ «${p.name}» اعمال شد${miss.length ? ' (' + fa(miss.length) + ' خانه در این صفحه نبود)' : ''}.`, 'success');
    }
    function pageFields() {
        const f = document.getElementById('editform'); if (!f) return [];
        const out = {}, skip = /^(token|entity|autoScroll|filesId|filesHidden|javax\.faces|editform_SUBMIT|editform:_idcl|editform:tabbedPane)|j_id_jsp_/;
        const party = /^(?:editform:)?(?:shakhsHaghighi_|shakhsHoghooghi_|shakhs_|moshakhasatOmumi_|isBimehgozar|isTarafAsli|isPardakhtKonandeh|noeShakhs|noeTarafGharardad|TarafGharardad)|^tarafDovvom/;
        const row = /^(shomarehEntezami\d?|seryalPelak(_inputHidden)?|vin|shomarehShasi|shomarehMotor|salSakht|rang|arzeshVasilehNaghliyeh|shomarehBimehnamehGhabli)$|tarikh(Shoroo|Payan|PishnahadNameh)/;
        for (const el of f.elements) {
            const n = el.name; if (!n || skip.test(n) || party.test(n) || row.test(n) || el.type === 'file' || el.type === 'submit' || el.type === 'button') continue;
            if (el.type === 'checkbox') { if (n === 'tarhListCheckBox') { out[n] = out[n] || []; if (el.checked) out[n].push(el.value); } else out[n] = el.checked ? (el.value || 'true') : ''; continue; }
            if (el.type === 'radio') { if (el.checked) out[n] = el.value; continue; }
            if (el.value === '' || el.value === ';HIJRI') continue;
            out[n] = el.value;
        }
        const lab = n => { const el = byName(n)[0]; if (!el) return n; const td = el.closest('td'); const prev = td && td.previousElementSibling; let t = prev ? prev.innerText : ''; if (!t && el.id) { const l = document.querySelector(`label[for="${CSS.escape(el.id)}"]`); if (l) t = l.innerText; } return nrm(t || '').trim().slice(0, 50) || n; };
        return Object.keys(out).filter(k => !(Array.isArray(out[k]) && !out[k].length)).map(k => ({key: k, value: out[k], label: lab(k.replace(/\.desc$/, ''))}));
    }
    async function presetUI() {
        const b = pop('پیش‌فرض‌های پیش‌نویس', '🎛️', '<p class="muted">…</p>');
        CTX = null; const r = await ctx('');
        if (!r.ok) { b.innerHTML = `<div class="note r">${esc(r.error)}</div>`; return; }
        const kind = kindOfPage(); const L = (r.presets || []).filter(p => p.kind === kind);
        b.innerHTML = `<div class="card"><h4>اعمال روی همین فرم (${kind === 'body' ? 'بدنه' : 'ثالث'})</h4>
                ${L.length ? `<div class="row"><select class="in" data-p style="flex:1">${L.map(p => `<option value="${esc(p.id)}">${esc(p.name)} — ${fa(Object.keys(p.values || {}).length)} خانه</option>`).join('')}</select><button class="pbtn g" data-a>اعمال</button></div>` : '<p class="muted">هنوز پیش‌فرضی برای این رشته نیست.</p>'}
                <p class="muted">نسبت‌دادن به شرکت/قرارداد و حالتِ تکی/گروهی در پنل: «سایتِ بیمه‌گر ← پیش‌فرض‌ها».</p></div>
            ${r.can_edit ? `<div class="card"><h4>ساختنِ پیش‌فرض از همین صفحه</h4><label class="l">نام<input class="in" data-n placeholder="مثلاً بارکش‌های شرکتِ …"></label>
                <p class="muted">خانه‌هایی که در پیش‌فرض بمانند را تیک بزنید (پلاک، شاسی، موتور، تاریخ‌ها و بیمه‌گذار هرگز در پیش‌فرض نمی‌مانند).</p>
                <div class="row"><button class="pbtn l" data-all style="padding:3px 8px">همه</button><button class="pbtn l" data-none style="padding:3px 8px">هیچ</button></div>
                <div class="fl" data-fl>${pageFields().map((f, i) => `<label><input type="checkbox" data-i="${i}"><span>${esc(f.label)}: <b>${esc(fa(Array.isArray(f.value) ? f.value.join('، ') : f.value).slice(0, 60))}</b> <code>${esc(f.key)}</code></span></label>`).join('')}</div>
                <button class="pbtn v" data-sv>ذخیره‌ی پیش‌فرض</button></div>` : ''}`;
        const a = b.querySelector('[data-a]'); if (a) a.onclick = () => applyPreset(b.querySelector('[data-p]').value);
        const PF = pageFields();
        const all = v => b.querySelectorAll('[data-fl] input').forEach(x => { x.checked = v; });
        const ba = b.querySelector('[data-all]'); if (ba) { ba.onclick = () => all(true); b.querySelector('[data-none]').onclick = () => all(false); }
        const sv = b.querySelector('[data-sv]');
        if (sv) sv.onclick = async () => {
            const name = b.querySelector('[data-n]').value.trim(); if (!name) return toast('نامِ پیش‌فرض را بنویسید.', 'warning');
            const values = {}; b.querySelectorAll('[data-fl] input:checked').forEach(x => { const f = PF[+x.dataset.i]; if (f) values[f.key] = f.value; });
            if (!Object.keys(values).length) return toast('هیچ خانه‌ای تیک نخورده.', 'warning');
            const s = await api('psg_issue_actions.php', {action: 'dp_save', name, kind, values, targets: []});
            toast(s.ok ? 'پیش‌فرض ذخیره شد؛ در پنل به شرکت/قرارداد نسبتش دهید.' : (s.error || 'نشد'), s.ok ? 'success' : 'error');
            if (s.ok) { CTX = null; tell({type: 'presets'}); presetUI(); }
        };
    }

    // ---------- دستورهای پنل ----------
    if (chan) chan.onmessage = async e => {
        const m = e.data || {}; if (m.from !== 'panel') return;
        if (m.type === 'fill' && m.plate_id) {
            if (PAGE !== 'draft') return;
            const f = await rowFill(+m.plate_id); if (f) toast(`ردیفِ #${fa(m.plate_id)} از پنل در فرم نشست.`, 'success', 'پنل');
            if (f && m.preset) applyPreset(m.preset);
        }
        if (m.type === 'preset' && m.id && PAGE === 'draft') applyPreset(m.id);
        if (m.type === 'ping') tell({type: 'pong', kind: PAGE});
    };

    function boot() {
        if (!document.body) return setTimeout(boot, 50);
        build();
        notifPoll(); setInterval(() => { if (!document.hidden) notifPoll(); }, 20000);
        if (PAGE === 'finance') { finFinalCheck(); setTimeout(finContinue, 400); setTimeout(finAutoStart, 1500); }
        tell({type: 'open', kind: PAGE});
    }
    zoom(Z);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
