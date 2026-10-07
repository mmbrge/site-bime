// فایل: letters.js
// «اتوماسیونِ نامه‌ها» در پنل: کارتابل و دفترِ نامه‌ها (صادره/وارده/داخلی، پیش‌نویس، ارجاع، منتظرِ پاسخ)، نوشتنِ نامه در ویرایشگرِ A4
// با سربرگ و شماره/تاریخ/پیوست (مثلِ Word)، صدور با شماره‌ی خودکار، ارسال به کارتابلِ شرکت، PDF و Word (و برگرداندنِ نسخه‌ی Word)،
// ارجاع، پیوست، رشته‌ی پاسخ‌ها، سابقه، و تنظیمات (الگوی شماره، سربرگ و صفحه، دسته‌ها، قالب‌ها، امضاها).
(function () {
    'use strict';
    const API = 'api/lt_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const isPhone = () => window.matchMedia && matchMedia('(max-width: 767px)').matches;
    const confirmUi = async (t, m, o) => (window.uiConfirm ? uiConfirm(t, m, o || {}) : confirm(m));
    const kb = n => n > 1048576 ? fa((n / 1048576).toFixed(1)) + ' مگابایت' : fa(Math.max(1, Math.round(n / 1024))) + ' کیلوبایت';
    const T = {boot: null, tab: null, box: {folder: 'all', page: 1}, set: {sub: 'number'}};
    const can = (p, op = 'view') => !!(T.boot && (T.boot.perms[p] || []).includes(op));
    async function api(action, body = {}, form = null) {
        let res;
        try {
            if (form) { form.append('action', action); res = await fetch(API, {method: 'POST', body: form, credentials: 'same-origin'}); }
            else res = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(Object.assign({action}, body))});
        } catch (e) { throw new Error('اتصال به سرور برقرار نشد.'); }
        const j = await res.json().catch(() => ({ok: false, error: 'پاسخِ نامعتبر از سرور'}));
        if (!j.ok) throw new Error(j.error || 'خطا');
        return j;
    }
    const url = (action, o = {}) => API + '?' + Object.entries(Object.assign({action}, o)).filter(([, v]) => v !== '' && v !== null && v !== undefined).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&');
    const download = h => { const a = document.createElement('a'); a.href = h; document.body.appendChild(a); a.click(); setTimeout(() => a.remove(), 300); };
    const busy = (b, on) => { if (!b) return; b.disabled = on; if (on) { b.dataset.h = b.innerHTML; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> صبر کنید…'; } else if (b.dataset.h) b.innerHTML = b.dataset.h; };
    const ST_COLOR = {DRAFT: 's', ISSUED: 'b', SENT: 'v', SEEN: 'c', REPLIED: 'g', NEW: 'r', REVIEWED: 'g', ARCHIVED: 's'};
    const DIR_ICON = {OUT: 'fa-paper-plane', IN: 'fa-inbox', INT: 'fa-building'};
    const catOf = id => (T.boot.categories || []).find(c => c.id === id);

    const STYLE = `
    .lt{direction:rtl;font-size:12.5px;color:#0f172a}.lt *{box-sizing:border-box}
    .lt-hub{display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between;background:linear-gradient(120deg,#1e1b4b,#3730a3 45%,#0e7490);border-radius:24px;padding:16px 18px;color:#fff;box-shadow:0 22px 40px -28px #3730a3;position:relative;overflow:hidden}
    .lt-hub::after{content:'';position:absolute;width:280px;height:280px;border-radius:50%;left:-80px;top:-160px;background:rgba(255,255,255,.09);pointer-events:none}
    .lt-hub .t{display:flex;align-items:center;gap:12px;z-index:1}.lt-hub .ic{width:44px;height:44px;border-radius:15px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:19px}
    .lt-hub b{font-size:17px;font-weight:900;display:block}.lt-hub small{opacity:.85;font-size:11px}
    .lt-nav{display:flex;gap:6px;flex-wrap:wrap;z-index:1}.lt-nav button{border:0;font-family:inherit;font-weight:800;font-size:12px;border-radius:14px;padding:8px 13px;background:rgba(255,255,255,.14);color:#fff;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
    .lt-nav button.on{background:#fff;color:#3730a3}.lt-nav button.new{background:#22d3ee;color:#083344}
    .lt-card{background:#fff;border:1px solid #e6e8f2;border-radius:20px;padding:16px;box-shadow:0 10px 30px -26px rgba(15,23,42,.35)}
    .lt-card h3{font-size:13.5px;font-weight:900;display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}.lt-card h3 small{font-weight:600;color:#64748b;font-size:11px}
    .lt-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:13px;padding:8px 13px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;white-space:nowrap;text-decoration:none}
    .lt-btn:disabled{opacity:.45;cursor:not-allowed}.lt-btn.p{background:linear-gradient(120deg,#4338ca,#0e7490);color:#fff}.lt-btn.s{background:#f1f5f9;color:#334155}.lt-btn.o{background:#fff;color:#334155;border:1px solid #e2e8f0}
    .lt-btn.g{background:#059669;color:#fff}.lt-btn.r{background:#fff1f2;color:#be123c}.lt-btn.a{background:#fffbeb;color:#b45309}.lt-btn.v{background:#ede9fe;color:#5b21b6}.lt-btn.sm{padding:5px 9px;font-size:11px;border-radius:10px}
    .lt-in,.lt-sel,.lt-ta{border:1px solid #dfe3ee;border-radius:12px;padding:8px 10px;font-size:12.5px;font-family:inherit;background:#fff;width:100%;min-width:0;color:#0f172a}
    .lt-in:focus,.lt-sel:focus,.lt-ta:focus{outline:2px solid #c7d2fe;border-color:#818cf8}
    .lt-lbl{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px}
    .lt-grid{display:grid;gap:10px}.lt-g2{grid-template-columns:repeat(2,minmax(0,1fr))}.lt-g3{grid-template-columns:repeat(3,minmax(0,1fr))}.lt-g4{grid-template-columns:repeat(4,minmax(0,1fr))}
    .lt-f{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}.lt-f>label{display:flex;flex-direction:column;gap:3px;flex:1 1 130px;max-width:200px}.lt-f>label span{font-size:10.5px;font-weight:800;color:#64748b}
    .lt-folders{display:flex;gap:8px;overflow:auto;padding-bottom:2px}
    .lt-fd{flex-shrink:0;display:flex;align-items:center;gap:9px;border:1px solid #e6e8f2;background:#fff;border-radius:16px;padding:9px 12px;cursor:pointer;font-family:inherit;text-align:right;min-width:128px}
    .lt-fd .i{width:32px;height:32px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:13px}
    .lt-fd b{display:block;font-size:16px;font-weight:900;line-height:1.2}.lt-fd small{font-size:10.5px;font-weight:800;color:#64748b}
    .lt-fd.on{border-color:#4338ca;box-shadow:0 0 0 3px #e0e7ff;background:#f8f9ff}
    .lt-tag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;border-radius:9px;padding:2px 8px;background:#f1f5f9;color:#475569;white-space:nowrap}
    .lt-tag.g{background:#dcfce7;color:#166534}.lt-tag.r{background:#ffe4e6;color:#9f1239}.lt-tag.a{background:#fef3c7;color:#92400e}.lt-tag.b{background:#dbeafe;color:#1e40af}.lt-tag.v{background:#ede9fe;color:#5b21b6}.lt-tag.c{background:#cffafe;color:#155e75}.lt-tag.s{background:#f1f5f9;color:#475569}
    .lt-num{font-family:inherit;font-weight:900;direction:ltr;unicode-bidi:isolate;color:#312e81;white-space:nowrap}
    .lt-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}.lt-tbl th{background:#eef2ff;color:#3730a3;font-weight:900;font-size:10.5px;padding:9px 8px;text-align:right;white-space:nowrap;position:sticky;top:0;z-index:1}
    .lt-tbl td{padding:9px 8px;border-top:1px solid #eef0f6;vertical-align:middle}.lt-tbl tbody tr{cursor:pointer}.lt-tbl tbody tr:hover td{background:#f8f9ff}.lt-tbl tr.unread td{font-weight:800}
    .lt-scroll{overflow:auto;border-radius:16px;border:1px solid #eef0f6;max-height:64vh}
    .lt-cards{display:none;flex-direction:column;gap:10px}.lt-pc{background:#fff;border:1px solid #e6e8f2;border-radius:18px;padding:12px 13px;cursor:pointer}
    .lt-empty{text-align:center;color:#94a3b8;padding:34px 10px;font-weight:700}.lt-empty i{font-size:28px;display:block;margin-bottom:8px;color:#cbd5e1}
    .lt-ov{position:fixed;inset:0;z-index:9500;background:rgba(15,23,42,.5);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:24px 12px;overflow:auto;direction:rtl}
    .lt-md{background:#fff;border-radius:24px;width:100%;max-width:640px;box-shadow:0 30px 60px -25px rgba(0,0,0,.5);animation:ltPop .18s ease-out}.lt-md.w{max-width:1180px}.lt-md.m{max-width:860px}
    @keyframes ltPop{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}
    .lt-mh{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid #eef0f6}.lt-mh h3{font-size:14.5px;font-weight:900;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .lt-mb{padding:16px 18px}.lt-mf{display:flex;gap:8px;justify-content:flex-end;padding:12px 18px;border-top:1px solid #eef0f6;flex-wrap:wrap}
    .lt-x{margin-right:auto;width:36px;height:36px;border-radius:12px;background:#f1f5f9;border:0;cursor:pointer;flex-shrink:0}
    .lt-sub{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.lt-sub button{border:1px solid #c7d2fe;background:#fff;color:#3730a3;font-family:inherit;font-weight:800;font-size:12px;border-radius:12px;padding:7px 12px;cursor:pointer}
    .lt-sub button.on{background:#4338ca;color:#fff;border-color:#4338ca}
    .lt-row{background:#fafbff;border:1px solid #e6e8f6;border-radius:16px;padding:12px;display:flex;flex-direction:column;gap:8px}
    .lt-chip{display:inline-flex;align-items:center;gap:4px;border:1px dashed #a5b4fc;background:#eef2ff;color:#3730a3;border-radius:10px;padding:4px 8px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit}
    .lt-kv{display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:12px}.lt-kv span{color:#64748b;font-weight:700;white-space:nowrap}.lt-kv b{font-weight:800;min-width:0;overflow-wrap:anywhere}
    .lt-side{display:flex;flex-direction:column;gap:12px}
    .lt-file{display:flex;align-items:center;gap:8px;border:1px solid #eef0f6;border-radius:12px;padding:7px 9px;font-size:11.5px}
    .lt-file .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:800}
    .lt-ext{font-size:9.5px;font-weight:900;border-radius:6px;padding:2px 5px;background:#e0e7ff;color:#3730a3;direction:ltr}
    .lt-tl{display:flex;flex-direction:column;gap:0;position:relative}.lt-tl .e{position:relative;padding:0 18px 12px 0;font-size:11.5px}.lt-tl .e::before{content:'';position:absolute;right:4px;top:6px;width:9px;height:9px;border-radius:50%;background:#818cf8}
    .lt-tl .e::after{content:'';position:absolute;right:8px;top:16px;bottom:0;width:1px;background:#e0e7ff}.lt-tl .e:last-child::after{display:none}.lt-tl small{color:#64748b;display:block}
    .lt-thread{display:flex;flex-direction:column;gap:8px}.lt-th{border:1px solid #e6e8f2;border-radius:14px;padding:9px 11px;cursor:pointer;font-size:11.5px}.lt-th.cur{border-color:#4338ca;background:#f8f9ff}
    .lt-th.in{border-right:4px solid #0891b2}.lt-th.out{border-right:4px solid #4338ca}
    /* صفحه‌ی A4 */
    .lt-pagewrap{position:relative;overflow:hidden;margin:0 auto}
    .lt-page{position:absolute;top:0;right:0;width:210mm;min-height:297mm;background:#fff;box-shadow:0 12px 40px -18px rgba(15,23,42,.45);transform-origin:top right;color:#0f172a;font-family:Vazir,Tahoma,sans-serif}
    .lt-page .pb{position:absolute;left:0;right:0;border-top:1px dashed #f43f5e;pointer-events:none;z-index:4}.lt-page .pb span{position:absolute;left:6px;top:-9px;font-size:9px;background:#fff1f2;color:#be123c;border-radius:6px;padding:0 5px;font-weight:800}
    .lt-page .fld{position:absolute;z-index:3;font-size:10pt;line-height:1.2;white-space:nowrap}
    .lt-page .fld div{display:flex;gap:4px}
    .lt-page .stamps{position:absolute;z-index:3;display:flex;gap:6px}.lt-page .stamps span{border:2px solid #dc2626;color:#dc2626;border-radius:6px;padding:1px 10px;font-weight:900;font-size:10pt;background:rgba(255,255,255,.85)}
    .lt-page .stamps span.c{border-color:#7c3aed;color:#7c3aed}
    .lt-page .content{position:relative;z-index:2}
    .lt-page .head{cursor:pointer;border-radius:6px}.lt-page .head:hover{outline:1px dashed #a5b4fc}
    .lt-page .head p,.lt-page .body p,.lt-page .tail p{margin:0 0 .45em}
    .lt-page .body{outline:none;min-height:40mm;text-align:justify}.lt-page .body:empty::before{content:attr(data-ph);color:#94a3b8}
    .lt-page .body table,.lt-page .tail table{border-collapse:collapse;width:100%}.lt-page .body td,.lt-page .body th{border:1px solid #94a3b8;padding:3px 6px;min-width:30px}
    .lt-page .body ul,.lt-page .body ol{padding-right:22px;margin:0 0 .45em}.lt-page .body ul{list-style:disc}.lt-page .body ol{list-style:decimal}
    .lt-page .body blockquote{margin:0 24px .45em 0}
    .lt-page .tail td{border:0}
    .lt-tb{display:flex;flex-wrap:wrap;gap:3px;align-items:center;background:#fff;border:1px solid #e6e8f2;border-radius:16px;padding:6px;position:sticky;top:0;z-index:20;box-shadow:0 8px 20px -18px rgba(15,23,42,.5)}
    .lt-tb button,.lt-tb select,.lt-tb label.c{height:32px;min-width:32px;border:0;background:transparent;border-radius:9px;cursor:pointer;color:#334155;font-family:inherit;font-size:12px;display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:0 7px;position:relative}
    .lt-tb button:hover,.lt-tb label.c:hover{background:#eef2ff;color:#3730a3}.lt-tb .sep{width:1px;height:22px;background:#e2e8f0;margin:0 3px}
    .lt-tb select{border:1px solid #e2e8f0;background:#fff;padding:0 6px}.lt-tb input[type=color]{position:absolute;opacity:0;inset:0;cursor:pointer}
    .lt-tb .clr{width:14px;height:4px;border-radius:2px;position:absolute;bottom:5px;left:50%;transform:translateX(-50%)}
    .lt-comp{position:fixed;inset:0;z-index:9400;background:#eef1f8;display:flex;flex-direction:column;direction:rtl}
    .lt-comp .top{display:flex;align-items:center;gap:10px;padding:10px 14px;background:linear-gradient(120deg,#1e1b4b,#3730a3);color:#fff;flex-wrap:wrap}
    .lt-comp .top b{font-size:14px;font-weight:900}.lt-comp .top .sp{flex:1}
    .lt-comp .main{flex:1;display:flex;min-height:0}
    .lt-comp .form{width:350px;flex-shrink:0;overflow:auto;padding:14px;background:#fff;border-left:1px solid #e6e8f2;display:flex;flex-direction:column;gap:10px}
    .lt-comp .stage{flex:1;overflow:auto;padding:14px 18px 60px;min-width:0}
    .lt-sec{border:1px solid #eef0f6;border-radius:14px;padding:10px;display:flex;flex-direction:column;gap:8px}.lt-sec>b{font-size:11.5px;color:#3730a3;font-weight:900;display:flex;gap:6px;align-items:center}
    .lt-tg{display:flex;gap:4px;background:#f1f5f9;border-radius:12px;padding:3px}.lt-tg button{flex:1;border:0;background:transparent;border-radius:9px;padding:6px 4px;font-family:inherit;font-weight:800;font-size:11.5px;cursor:pointer;color:#475569}
    .lt-tg button.on{background:#fff;color:#3730a3;box-shadow:0 2px 6px -3px rgba(0,0,0,.3)}.lt-tg button.on.u{color:#dc2626}.lt-tg button.on.c{color:#7c3aed}
    .lt-chk{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;cursor:pointer}
    .lt-mini{position:relative;width:273px;height:386px;border:1px solid #e2e8f0;border-radius:6px;background:#fff center/100% 100% no-repeat;overflow:hidden;flex-shrink:0}
    .lt-mini .m{position:absolute;border:1px dashed #6366f1;background:rgba(99,102,241,.06)}.lt-mini .fb{position:absolute;background:#0e7490;color:#fff;font-size:7px;line-height:8px;padding:0 3px;border-radius:3px;white-space:nowrap}
    .lt-tok{display:flex;flex-wrap:wrap;gap:5px}
    .lt-big{font-size:26px;font-weight:900;color:#312e81;direction:ltr;letter-spacing:1px}
    @media (max-width:1023px){.lt-comp .main{flex-direction:column;overflow:auto}.lt-comp .form{width:auto;border-left:0;border-bottom:1px solid #e6e8f2;overflow:visible}.lt-comp .stage{overflow:visible;padding:10px 8px 80px}}
    @media (max-width:767px){.lt-g2,.lt-g3,.lt-g4{grid-template-columns:minmax(0,1fr)}.lt-tblwrap{display:none}.lt-cards{display:flex}
        .lt-hub{border-radius:20px}.lt-nav{width:100%;overflow:auto;flex-wrap:nowrap}.lt-nav button{flex-shrink:0}.lt-f>label{max-width:none;flex:1 1 45%}
        .lt-ov{padding:0;align-items:flex-end}.lt-md{border-radius:22px 22px 0 0;max-height:94vh;overflow:auto}.lt-vgrid{grid-template-columns:minmax(0,1fr)!important}}`;
    const css = () => { if (!document.getElementById('lt-css')) { const s = document.createElement('style'); s.id = 'lt-css'; s.textContent = STYLE; document.head.appendChild(s); } };

    const TABS = [['lt-box', 'fa-inbox', 'کارتابل نامه‌ها'], ['lt-settings', 'fa-sliders', 'تنظیمات نامه‌ها']];
    const shell = (tab, body) => `<div class="lt space-y-4 no-count" data-perm-skip><div class="lt-hub"><div class="t"><span class="ic"><i class="fas fa-envelope-open-text"></i></span><div><b>اتوماسیون نامه‌ها</b><small>صادره، وارده، ارجاع، کارتابلِ شرکت‌ها، Word و PDF</small></div></div>
        <div class="lt-nav">${can('lt-box', 'create') ? '<button class="new" data-new><i class="fas fa-pen-nib"></i>نامه‌ی جدید</button><button data-newin><i class="fas fa-inbox"></i>ثبتِ نامه‌ی وارده</button>' : ''}
        ${TABS.filter(t => can(t[0])).map(t => `<button class="${t[0] === tab ? 'on' : ''}" onclick="switchTab('${t[0]}')"><i class="fas ${t[1]}"></i>${t[2]}</button>`).join('')}</div></div>${body}</div>`;
    const bindShell = root => {
        const n = root.querySelector('[data-new]'); if (n) n.onclick = () => Compose.open({});
        const i = root.querySelector('[data-newin]'); if (i) i.onclick = () => Compose.open({direction: 'IN'});
    };
    async function boot(force) { if (!T.boot || force) T.boot = await api('bootstrap'); return T.boot; }
    async function open(tab) {
        css();
        const root = document.getElementById(tab + '-root');
        if (!root) return;
        T.tab = tab;
        try { await boot(); } catch (e) { root.innerHTML = `<div class="lt-card lt-empty"><i class="fas fa-lock"></i>${esc(e.message)}</div>`; return; }
        if (!can(tab)) { root.innerHTML = shell(tab, '<div class="lt-card lt-empty"><i class="fas fa-lock"></i>به این بخش دسترسی ندارید.</div>'); return; }
        if (tab === 'lt-box') Box.render(root);
        else if (tab === 'lt-settings') Sett.render(root);
    }
    function modal(title, body, foot, o = {}) {
        const ov = document.createElement('div');
        ov.className = 'lt lt-ov no-count'; ov.setAttribute('data-perm-skip', '');
        ov.innerHTML = `<div class="lt-md ${o.wide ? 'w' : (o.mid ? 'm' : '')}"><div class="lt-mh"><h3>${title}</h3><button class="lt-x" data-x><i class="fas fa-xmark"></i></button></div><div class="lt-mb">${body}</div>${foot ? `<div class="lt-mf">${foot}</div>` : ''}</div>`;
        const close = () => { ov.remove(); if (o.onClose) o.onClose(); };
        ov.addEventListener('mousedown', e => { ov._d = e.target === ov; });
        ov.addEventListener('click', e => { if (e.target === ov && ov._d) close(); });
        ov.querySelector('[data-x]').onclick = close;
        document.body.appendChild(ov);
        ov.close = close;
        return ov;
    }
    const fname = n => { const m = String(n || '').match(/^(.*?)(\.[a-z0-9]{2,5})?$/i); return `<span class="n" title="${esc(n)}">${esc(m[1])}</span>${m[2] ? `<span class="lt-ext">${esc(m[2].slice(1).toUpperCase())}</span>` : ''}`; };
    const prioTag = r => r.priority !== 'normal' ? `<span class="lt-tag r"><i class="fas fa-bolt"></i>${esc(T.boot.priorities[r.priority])}</span>` : '';
    const confTag = r => r.conf !== 'normal' ? `<span class="lt-tag v"><i class="fas fa-lock"></i>${esc(T.boot.confs[r.conf])}</span>` : '';
    const catTag = id => { const c = catOf(id); return c ? `<span class="lt-tag" style="background:${c.color}1a;color:${c.color}"><i class="fas ${esc(c.icon || 'fa-tag')}"></i>${esc(c.name)}</span>` : ''; };
    const stTag = r => `<span class="lt-tag ${ST_COLOR[r.status] || 's'}">${esc(r.status_fa)}</span>${r.overdue ? '<span class="lt-tag r"><i class="fas fa-clock"></i>مهلتِ پاسخ گذشته</span>' : ''}`;
    const numHtml = r => r.number ? `<span class="lt-num">${fa(r.number)}</span>` : '<span class="lt-tag s">بدونِ شماره</span>';

    // =================================================================
    //  کارتابل
    // =================================================================
    const FOLDERS = [['all', 'fa-layer-group', 'همه', '#4338ca', null], ['refs', 'fa-share', 'ارجاع به من', '#db2777', 'refs'], ['new_in', 'fa-inbox', 'وارده‌ی جدید', '#0891b2', 'new_in'],
        ['reply', 'fa-reply', 'منتظرِ پاسخ', '#d97706', 'reply'], ['urgent', 'fa-bolt', 'فوری', '#dc2626', 'urgent'], ['draft', 'fa-file-pen', 'پیش‌نویس‌ها', '#64748b', 'draft'],
        ['out', 'fa-paper-plane', 'صادره', '#4338ca', 'out_month'], ['in', 'fa-envelope', 'وارده', '#0e7490', 'in_month'], ['int', 'fa-building', 'داخلی', '#7c3aed', null],
        ['mine', 'fa-user-pen', 'نامه‌های من', '#0f766e', null], ['archived', 'fa-box-archive', 'بایگانی', '#475569', 'archived']];
    const Box = {
        async render(root) {
            const B = T.boot, f = T.box;
            root.innerHTML = shell('lt-box', `<div class="lt-folders" data-folders></div>
                <div class="lt-card"><div class="lt-f">
                    <label style="max-width:none;flex:2 1 220px"><span>جستجو (شماره، موضوع، شرکت، متن)</span><input class="lt-in" data-k="q" value="${esc(f.q || '')}" placeholder="مثلاً ۱۱۰/۰۵ یا نامِ شرکت"></label>
                    <label><span>دسته</span><select class="lt-sel" data-k="category_id"><option value="">همه</option>${B.categories.map(c => `<option value="${c.id}" ${+f.category_id === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
                    <label><span>شرکت</span><select class="lt-sel" data-k="company_id"><option value="">همه</option>${B.companies.map(c => `<option value="${c.id}" ${+f.company_id === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
                    <label><span>وضعیت</span><select class="lt-sel" data-k="status"><option value="">همه</option>${Object.entries(B.statuses).map(([k, v]) => `<option value="${k}" ${f.status === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
                    <label><span>فوریت</span><select class="lt-sel" data-k="priority"><option value="">همه</option>${Object.entries(B.priorities).map(([k, v]) => `<option value="${k}" ${f.priority === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
                    <label><span>از تاریخ</span><input class="lt-in" data-k="from_j" data-jdate dir="ltr" value="${esc(fa(f.from_j || ''))}" placeholder="۱۴۰۵/۰۱/۰۱"></label>
                    <label><span>تا تاریخ</span><input class="lt-in" data-k="to_j" data-jdate dir="ltr" value="${esc(fa(f.to_j || ''))}" placeholder="۱۴۰۵/۱۲/۲۹"></label>
                    <button class="lt-btn s" data-clear><i class="fas fa-eraser"></i>پاک کردن</button>${can('lt-box', 'export') ? '<button class="lt-btn o" data-xls><i class="fas fa-file-excel text-emerald-600"></i>دفترِ اندیکاتور (اکسل)</button>' : ''}
                </div></div>
                <div class="lt-card" data-list><div class="lt-empty"><i class="fas fa-spinner fa-spin"></i>در حال بارگذاری…</div></div>`);
            bindShell(root);
            let tm;
            root.querySelectorAll('[data-k]').forEach(el => el.addEventListener(el.tagName === 'SELECT' || el.dataset.jdate !== undefined ? 'change' : 'input', () => {
                clearTimeout(tm); tm = setTimeout(() => { f[el.dataset.k] = el.dataset.jdate !== undefined ? en(el.value) : el.value; f.page = 1; this.load(root); }, el.tagName === 'INPUT' ? 350 : 0);
            }));
            root.querySelector('[data-clear]').onclick = () => { T.box = {folder: f.folder, page: 1}; this.render(root); };
            const x = root.querySelector('[data-xls]'); if (x) x.onclick = () => download(url('export', Object.assign({}, T.box, {page: ''})));
            this.load(root);
        },
        async load(root) {
            const box = root.querySelector('[data-list]');
            let r;
            try { r = await api('list', T.box); } catch (e) { box.innerHTML = `<div class="lt-empty"><i class="fas fa-triangle-exclamation"></i>${esc(e.message)}</div>`; return; }
            const C = r.counts || {};
            root.querySelector('[data-folders]').innerHTML = FOLDERS.map(([k, ic, lb, col, ck]) => `<button class="lt-fd ${T.box.folder === k ? 'on' : ''}" data-fd="${k}"><span class="i" style="background:${col}1a;color:${col}"><i class="fas ${ic}"></i></span><span><b>${ck ? fa(C[ck] || 0) : (k === T.box.folder ? fa(r.total) : '•')}</b><small>${lb}${k === 'out' || k === 'in' ? ' (این ماه)' : ''}</small></span></button>`).join('');
            root.querySelectorAll('[data-fd]').forEach(b => b.onclick = () => { T.box.folder = b.dataset.fd; T.box.page = 1; this.load(root); });
            if (C.overdue) root.querySelector('[data-fd="reply"] small').innerHTML += ` <span class="lt-tag r">${fa(C.overdue)} دیرکرد</span>`;
            if (!r.rows.length) { box.innerHTML = '<div class="lt-empty"><i class="fas fa-envelope-open"></i>نامه‌ای در این پوشه نیست.</div>'; return; }
            const party = x => x.party ? esc(x.party) + (x.party_person ? `<small class="text-slate-500"> - ${esc(x.party_person)}</small>` : '') : '<span class="text-slate-400">—</span>';
            const pages = Math.ceil(r.total / r.per);
            box.innerHTML = `<h3><i class="fas fa-list text-indigo-600"></i>${esc(FOLDERS.find(x => x[0] === T.box.folder)[2])}<small>${fa(r.total)} نامه</small></h3>
                <div class="lt-tblwrap lt-scroll"><table class="lt-tbl no-count"><thead><tr><th>شماره</th><th>تاریخ</th><th>نوع</th><th>موضوع</th><th>شرکت / طرف</th><th>دسته</th><th>وضعیت</th><th>ثبت‌کننده</th><th></th></tr></thead><tbody>
                ${r.rows.map(x => `<tr data-id="${x.id}" class="${x.direction === 'IN' && x.status === 'NEW' ? 'unread' : ''}"><td>${numHtml(x)}</td><td>${fa(x.letter_j)}</td>
                    <td><span class="lt-tag ${x.direction === 'IN' ? 'c' : (x.direction === 'INT' ? 'v' : 'b')}"><i class="fas ${DIR_ICON[x.direction]}"></i>${esc(x.direction_fa)}</span></td>
                    <td style="max-width:300px"><div class="font-bold truncate">${esc(x.subject)}</div><div class="flex gap-1 mt-1 flex-wrap">${prioTag(x)}${confTag(x)}${x.needs_reply ? `<span class="lt-tag a"><i class="fas fa-reply"></i>پاسخ${x.reply_due_j ? ' تا ' + fa(x.reply_due_j) : ''}</span>` : ''}${x.to_portal ? '<span class="lt-tag c"><i class="fas fa-building-circle-check"></i>کارتابلِ شرکت</span>' : ''}</div></td>
                    <td>${party(x)}</td><td>${catTag(x.category_id)}</td><td><div class="flex flex-wrap gap-1">${stTag(x)}</div></td>
                    <td class="text-[11px]">${esc(x.portal_user ? x.portal_user + ' (شرکت)' : x.created_by_name)}<div class="text-slate-400">${fa(x.created_at_j)}</div></td>
                    <td class="whitespace-nowrap text-slate-500 text-[11px]">${x.files_n ? `<i class="fas fa-paperclip"></i> ${fa(x.files_n)} ` : ''}${x.replies_n ? `<i class="fas fa-reply-all mr-1"></i> ${fa(x.replies_n)}` : ''}</td></tr>`).join('')}</tbody></table></div>
                <div class="lt-cards">${r.rows.map(x => `<div class="lt-pc" data-id="${x.id}"><div class="flex justify-between gap-2 items-center"><span>${numHtml(x)}</span><span class="text-[11px] text-slate-500">${fa(x.letter_j)}</span></div>
                    <div class="font-black mt-1">${esc(x.subject)}</div><div class="text-[11.5px] text-slate-600 mt-1"><i class="fas ${DIR_ICON[x.direction]} ml-1"></i>${party(x)}</div>
                    <div class="flex flex-wrap gap-1 mt-2">${stTag(x)}${catTag(x.category_id)}${prioTag(x)}${confTag(x)}</div></div>`).join('')}</div>
                ${pages > 1 ? `<div class="flex gap-2 justify-center mt-3 items-center"><button class="lt-btn s sm" data-pg="-1" ${r.page <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button><span class="text-[12px] font-bold">صفحه‌ی ${fa(r.page)} از ${fa(pages)}</span><button class="lt-btn s sm" data-pg="1" ${r.page >= pages ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button></div>` : ''}`;
            box.querySelectorAll('[data-id]').forEach(el => el.onclick = () => View.open(+el.dataset.id, () => this.load(root)));
            box.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { T.box.page += +b.dataset.pg; this.load(root); });
        },
        refresh() { const root = document.getElementById('lt-box-root'); if (root && root.querySelector('[data-list]')) this.load(root); },
    };

    // =================================================================
    //  صفحه‌ی A4 (پیش‌نمایش و ویرایش) - همان اندازه‌ها و جای شماره/تاریخ که در PDF و Word می‌آید
    // =================================================================
    const Page = {
        // r: {number, letter_j, attach_note, priority, conf, letterhead}
        html(r, inner) {
            const S = T.boot.settings;
            const bg = r.letterhead && S.has_letterhead ? `background:#fff url('${url('letterhead_img', {v: S.letterhead.length})}') top right/210mm 297mm ${S.letterhead_pages === 'first' ? 'no-repeat' : 'repeat-y'};` : '';
            const num = r.number ? fa(r.number) : 'پیش‌نویس';
            const lab = l => S.field_labels ? `<span>${l}:</span>` : '';
            const fld = S.show_fields ? `<div class="fld" data-fld style="left:${S.f_x}mm;top:${S.f_y}mm;font-size:${S.f_size}pt">
                ${[['شماره', `<b class="lt-num" style="color:#0f172a">${esc(num)}</b>`], ['تاریخ', `<b>${esc(fa(r.letter_j || T.boot.today_j))}</b>`], ['پیوست', `<b>${esc(r.attach_note || 'ندارد')}</b>`]].map(([l, v]) => `<div style="height:${S.f_gap}mm">${lab(l)}${v}</div>`).join('')}</div>` : '';
            const st = S.stamp_priority ? `<div class="stamps" style="right:${S.m_right}mm;top:${Math.max(8, S.m_top - 14)}mm">${r.priority && r.priority !== 'normal' ? `<span>${esc(T.boot.priorities[r.priority])}</span>` : ''}${r.conf && r.conf !== 'normal' ? `<span class="c">${esc(T.boot.confs[r.conf])}</span>` : ''}</div>` : '';
            return `<div class="lt-page" style="${bg}padding:${S.m_top}mm ${S.m_right}mm ${S.m_bottom}mm ${S.m_left}mm;font-size:${S.font_size}pt;line-height:${S.line_height}">${fld}${st}<div class="content">${inner}</div><div data-pbs></div></div>`;
        },
        // مقیاس به اندازه‌ی جا + خطِ پایانِ هر صفحه
        fit(wrap) {
            const pg = wrap.querySelector('.lt-page');
            if (!pg) return;
            const w = wrap.parentElement.clientWidth || 800;
            const pw = pg.offsetWidth;
            const s = Math.min(1, (w - 4) / pw);
            pg.style.transform = `scale(${s})`;
            const mmPx = pw / 210;
            const h = pg.offsetHeight;
            const pages = Math.max(1, Math.ceil((h - 2) / (297 * mmPx)));
            pg.style.minHeight = (pages * 297) + 'mm';
            const pbs = pg.querySelector('[data-pbs]');
            if (pbs) pbs.innerHTML = Array.from({length: pages - 1}, (_, i) => `<div class="pb" style="top:${(i + 1) * 297}mm"><span>پایانِ صفحه‌ی ${fa(i + 1)}</span></div>`).join('');
            wrap.style.width = (pw * s) + 'px';
            wrap.style.height = (pg.offsetHeight * s) + 'px';
        },
    };
    // سرِ نامه، امضا و رونوشت (همان چیزی که سرور می‌سازد)
    const headHtml = r => {
        if (r.direction === 'IN' || !T.boot.settings.auto_head) return '';
        const party = r.party || '', t = (r.party_title || '').trim(), p = (r.party_person || '').trim();
        const lines = [];
        if (p) { lines.push(`<b>${esc(p)}</b>`); if (t || party) lines.push(esc(((t ? t + ' محترم ' : '') + party).trim())); }
        else if (party) lines.push(`<b>${esc((t ? t + ' محترم ' : '') + party)}</b>`);
        return (lines.length ? `<p>${lines.join('<br>')}</p>` : '<p class="text-slate-400">گیرنده را از فرم انتخاب کنید</p>') + (r.subject ? `<p><b>موضوع: ${esc(r.subject)}</b></p>` : '<p class="text-slate-400"><b>موضوع: …</b></p>');
    };
    const tailHtml = r => {
        const sg = (T.boot.signers || []).find(s => s.id === +r.signer_id);
        const sign = sg ? `<table><tr><td style="width:55%"></td><td style="text-align:center"><b>${esc(sg.name)}</b>${sg.title ? '<br>' + esc(sg.title) : ''}${sg.has_image ? `<br><img src="${url('signer_img', {id: sg.id})}" style="height:16mm;opacity:${r.number ? 1 : .35}">` : ''}</td></tr></table>` : '';
        const cc = String(r.cc || '').split('\n').map(s => s.trim()).filter(Boolean);
        return sign + (cc.length ? `<p style="font-size:9.5pt"><b>رونوشت:</b><br>${cc.map(c => '- ' + esc(c)).join('<br>')}</p>` : '');
    };

    // =================================================================
    //  ویرایشگر (نوارِ ابزار مثلِ Word)
    // =================================================================
    const VARS = [['{شرکت}', 'نامِ شرکت/گیرنده'], ['{گیرنده}', 'نامِ شخص'], ['{سمت}', 'سمت'], ['{موضوع}', 'موضوع'], ['{شماره}', 'شماره‌ی نامه'], ['{تاریخ}', 'تاریخِ نامه'], ['{پیوست}', 'پیوست'], ['{امروز}', 'تاریخِ امروز']];
    const PHRASES = ['با سلام و احترام،', 'احتراماً، به استحضار می‌رساند ', 'خواهشمند است دستور فرمایید ', 'پیشاپیش از همکاری و مساعدتِ جنابعالی سپاسگزاریم.', 'با تشکر و تجدیدِ احترام'];
    function cleanPaste(html) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const ok = ['P', 'DIV', 'BR', 'B', 'STRONG', 'I', 'EM', 'U', 'S', 'SPAN', 'UL', 'OL', 'LI', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TD', 'TH', 'H2', 'H3', 'H4', 'HR', 'SUB', 'SUP', 'BLOCKQUOTE'];
        const keepCss = ['text-align', 'color', 'font-weight', 'font-style', 'text-decoration'];
        const walk = n => [...n.childNodes].forEach(c => {
            if (c.nodeType === 8) { c.remove(); return; }
            if (c.nodeType !== 1) return;
            if (['SCRIPT', 'STYLE', 'META', 'LINK', 'IMG', 'SVG', 'IFRAME', 'OBJECT', 'TITLE', 'XML'].includes(c.tagName) || /^O:|^W:|^V:/.test(c.tagName)) { c.remove(); return; }
            walk(c);
            if (!ok.includes(c.tagName)) { c.replaceWith(...c.childNodes); return; }
            const st = c.style; const keep = keepCss.map(k => st.getPropertyValue(k) ? `${k}:${st.getPropertyValue(k)}` : '').filter(Boolean).join(';');
            [...c.attributes].forEach(a => { if (!['colspan', 'rowspan'].includes(a.name)) c.removeAttribute(a.name); });
            if (keep) c.setAttribute('style', keep);
        });
        walk(doc.body);
        return doc.body.innerHTML;
    }
    function toolbar(area, extra = '') {
        const sizes = [9, 10, 11, 12, 13, 14, 16, 18, 20, 24];
        const html = `<div class="lt-tb" data-perm-skip>
            <button data-c="undo" title="برگرداندن (Ctrl+Z)"><i class="fas fa-rotate-right"></i></button><button data-c="redo" title="انجامِ دوباره"><i class="fas fa-rotate-left"></i></button><span class="sep"></span>
            <button data-c="bold" title="پررنگ (Ctrl+B)"><i class="fas fa-bold"></i></button><button data-c="italic" title="مورب"><i class="fas fa-italic"></i></button><button data-c="underline" title="زیرخط"><i class="fas fa-underline"></i></button><button data-c="strikeThrough" title="خط‌خورده"><i class="fas fa-strikethrough"></i></button>
            <select data-size title="اندازه‌ی قلم"><option value="">اندازه</option>${sizes.map(s => `<option value="${s}">${fa(s)}</option>`).join('')}</select>
            <label class="c" title="رنگِ متن"><i class="fas fa-font"></i><span class="clr" style="background:#dc2626" data-clr1></span><input type="color" value="#dc2626" data-fore></label>
            <label class="c" title="رنگِ زمینه (هایلایت)"><i class="fas fa-highlighter"></i><span class="clr" style="background:#fde047" data-clr2></span><input type="color" value="#fde047" data-back></label><span class="sep"></span>
            <button data-c="justifyRight" title="راست‌چین"><i class="fas fa-align-right"></i></button><button data-c="justifyCenter" title="وسط‌چین"><i class="fas fa-align-center"></i></button><button data-c="justifyLeft" title="چپ‌چین"><i class="fas fa-align-left"></i></button><button data-c="justifyFull" title="تراز از دو طرف"><i class="fas fa-align-justify"></i></button><span class="sep"></span>
            <button data-c="insertUnorderedList" title="فهرستِ نقطه‌ای"><i class="fas fa-list-ul"></i></button><button data-c="insertOrderedList" title="فهرستِ شماره‌دار"><i class="fas fa-list-ol"></i></button><button data-c="indent" title="تورفتگی"><i class="fas fa-indent fa-flip-horizontal"></i></button><button data-c="outdent" title="کاهشِ تورفتگی"><i class="fas fa-outdent fa-flip-horizontal"></i></button>
            <button data-table title="جدول"><i class="fas fa-table"></i></button><button data-c="insertHorizontalRule" title="خطِ جداکننده"><i class="fas fa-minus"></i></button><button data-c="removeFormat" title="پاک کردنِ قالب‌بندی"><i class="fas fa-text-slash"></i></button><span class="sep"></span>
            <select data-var title="درجِ متغیر"><option value="">متغیرها</option>${VARS.map(([v, l]) => `<option value="${v}">${l} ${v}</option>`).join('')}</select>
            <select data-phrase title="عبارت‌های آماده"><option value="">عبارتِ آماده</option>${PHRASES.map(p => `<option>${esc(p)}</option>`).join('')}</select>${extra}</div>`;
        const bind = tb => {
            const keep = () => { const sel = window.getSelection(); if (sel.rangeCount && area.contains(sel.anchorNode)) tb._r = sel.getRangeAt(0).cloneRange(); };
            area.addEventListener('keyup', keep); area.addEventListener('mouseup', keep); area.addEventListener('input', keep);
            const restore = () => { area.focus(); if (tb._r) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(tb._r); } };
            const ex = (c, v = null, css = true) => { restore(); try { document.execCommand('styleWithCSS', false, css); } catch (e) {} document.execCommand(c, false, v); keep(); area.dispatchEvent(new Event('input')); };
            tb.querySelectorAll('[data-c]').forEach(b => { b.onmousedown = e => e.preventDefault(); b.onclick = () => ex(b.dataset.c); });
            tb.querySelector('[data-size]').onchange = e => {
                const pt = e.target.value; e.target.value = ''; if (!pt) return;
                ex('fontSize', '7', false);
                area.querySelectorAll('font[size="7"]').forEach(f => { const s = document.createElement('span'); s.style.fontSize = pt + 'pt'; s.append(...f.childNodes); f.replaceWith(s); });
                area.querySelectorAll('span[style*="xxx-large"]').forEach(s => { s.style.fontSize = pt + 'pt'; });
                area.dispatchEvent(new Event('input'));
            };
            tb.querySelector('[data-fore]').oninput = e => { tb.querySelector('[data-clr1]').style.background = e.target.value; ex('foreColor', e.target.value); };
            tb.querySelector('[data-back]').oninput = e => { tb.querySelector('[data-clr2]').style.background = e.target.value; ex('hiliteColor', e.target.value); };
            tb.querySelector('[data-var]').onchange = e => { const v = e.target.value; e.target.value = ''; if (v) ex('insertText', v); };
            tb.querySelector('[data-phrase]').onchange = e => { const v = e.target.value; e.target.value = ''; if (v) ex('insertText', v); };
            tb.querySelector('[data-table]').onclick = async () => {
                const m = modal('<i class="fas fa-table text-indigo-600"></i>درجِ جدول', `<div class="lt-grid lt-g2"><label><span class="lt-lbl">تعدادِ سطر</span><input class="lt-in" data-r value="۳"></label><label><span class="lt-lbl">تعدادِ ستون</span><input class="lt-in" data-cc value="۳"></label></div>
                    <label class="lt-chk mt-3"><input type="checkbox" data-h checked>سطرِ اول عنوان باشد</label>`, '<button class="lt-btn p" data-ok>درج</button>');
                m.querySelector('[data-ok]').onclick = () => {
                    const r = Math.min(30, Math.max(1, +en(m.querySelector('[data-r]').value) || 3)), c = Math.min(10, Math.max(1, +en(m.querySelector('[data-cc]').value) || 3)), h = m.querySelector('[data-h]').checked;
                    let t = '<table><tbody>';
                    for (let i = 0; i < r; i++) t += '<tr>' + Array.from({length: c}, () => i === 0 && h ? '<th>&nbsp;</th>' : '<td>&nbsp;</td>').join('') + '</tr>';
                    m.close(); ex('insertHTML', t + '</tbody></table><p><br></p>');
                };
            };
        };
        return {html, bind};
    }
    function setupArea(area) {
        try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
        area.addEventListener('paste', e => {
            const d = e.clipboardData; if (!d) return;
            const h = d.getData('text/html'), t = d.getData('text/plain');
            e.preventDefault();
            if (h) document.execCommand('insertHTML', false, cleanPaste(h));
            else document.execCommand('insertHTML', false, t.split(/\r?\n/).map(l => `<p>${esc(l) || '<br>'}</p>`).join(''));
        });
    }

    // =================================================================
    //  نوشتن / ویرایشِ نامه
    // =================================================================
    const Compose = {
        async open(o) {
            css();
            await boot();
            const B = T.boot, S = B.settings;
            let L = {direction: o.direction || 'OUT', letterhead: S.default_letterhead ? 1 : 0, priority: 'normal', conf: 'normal', letter_j: B.today_j, signer_id: S.default_signer || '', category_id: '', body_html: '', cc: '', needs_reply: 0, files: []};
            if (o.id) {
                try { L = (await api('detail', {id: o.id})).letter; } catch (e) { toast(e.message, 'error'); return; }
            } else {
                if (o.parent) {   // پاسخ یا نامه‌ی پیرو
                    const P = o.parent;
                    Object.assign(L, {parent_id: P.id, company_id: P.company_id, party_name: P.company_id ? '' : P.party_name, party: P.party, party_person: o.direction === 'IN' ? '' : (P.direction === 'IN' ? '' : P.party_person),
                        party_title: P.direction === 'IN' ? '' : P.party_title, category_id: P.category_id, subject: (o.direction === 'IN' ? 'پاسخِ ' : (P.direction === 'IN' ? 'پاسخ به نامه‌ی ' : 'پیرو نامه‌ی ')) + fa(P.number || '') + ' - ' + P.subject});
                }
                if (L.direction === 'OUT' && !o.parent) {
                    const t = B.templates[0];
                    if (t) try { const x = (await api('tpl_get', {id: t.id})).template; L.body_html = x.body_html; } catch (e) {}
                }
            }
            const isIn = L.direction === 'IN';
            const issued = !!L.number;
            const ov = document.createElement('div');
            ov.className = 'lt lt-comp no-count'; ov.setAttribute('data-perm-skip', '');
            const opt = (arr, v) => arr.map(([a, b]) => `<option value="${a}" ${String(v) === String(a) ? 'selected' : ''}>${esc(b)}</option>`).join('');
            const companyName = L.company_id ? (B.companies.find(c => c.id === L.company_id) || {}).name || L.company_name || '' : (L.party_name || '');
            const tb = toolbar(null);
            ov.innerHTML = `<div class="top"><i class="fas ${isIn ? 'fa-inbox' : 'fa-pen-nib'}"></i><b>${o.id ? (issued ? 'ویرایشِ نامه‌ی ' + fa(L.number) : 'ویرایشِ پیش‌نویس') : (isIn ? 'ثبتِ نامه‌ی وارده' : 'نامه‌ی جدید')}</b>
                    <span class="lt-tag c" data-numprev>${issued ? 'شماره: ' + fa(L.number) : ''}</span><span class="sp"></span>
                    ${!isIn ? `<button class="lt-btn s sm" data-pdf><i class="fas fa-file-pdf text-rose-600"></i>پیش‌نمایشِ PDF</button>${o.id ? '<button class="lt-btn s sm" data-word><i class="fas fa-file-word text-blue-600"></i>باز کردن در Word</button>' : ''}` : ''}
                    <button class="lt-btn o sm" data-save><i class="fas fa-floppy-disk"></i>${isIn ? 'ثبت و شماره‌ی وارده' : (issued ? 'ذخیره‌ی تغییرات' : 'ذخیره‌ی پیش‌نویس')}</button>
                    ${!isIn && !issued && can('lt-box', 'create') ? '<button class="lt-btn sm" style="background:#22d3ee;color:#083344" data-issue><i class="fas fa-stamp"></i>صدور و شماره</button><button class="lt-btn g sm" data-send><i class="fas fa-paper-plane"></i>صدور و ارسال به شرکت</button>' : ''}
                    <button class="lt-x" data-close style="margin:0;background:rgba(255,255,255,.15);color:#fff"><i class="fas fa-xmark"></i></button></div>
                <div class="main"><div class="form">
                    ${!isIn ? `<div class="lt-sec"><b><i class="fas fa-file-lines"></i>نوع و قالب</b><div class="lt-tg" data-tg="direction">${[['OUT', 'صادره'], ['INT', 'داخلی']].map(([a, b]) => `<button data-v="${a}" class="${L.direction === a ? 'on' : ''}" ${issued ? 'disabled' : ''}>${b}</button>`).join('')}</div>
                        ${!o.id ? `<select class="lt-sel" data-tpl><option value="">— انتخابِ قالب —</option>${B.templates.map(t => `<option value="${t.id}">${esc(t.title)}</option>`).join('')}</select>` : ''}
                        <label class="lt-chk"><input type="checkbox" data-f="letterhead" ${+L.letterhead ? 'checked' : ''}>با سربرگ ${S.has_letterhead ? '' : '<small class="text-amber-600">(تصویرِ سربرگ هنوز در تنظیمات بارگذاری نشده)</small>'}</label></div>` : ''}
                    <div class="lt-sec"><b><i class="fas fa-building"></i>${isIn ? 'فرستنده' : 'گیرنده'}</b>
                        <label><span class="lt-lbl">شرکت / سازمان</span><input class="lt-in" list="lt-companies" data-f="party" value="${esc(companyName)}" placeholder="نامِ شرکت را بنویسید یا انتخاب کنید"></label>
                        <datalist id="lt-companies">${B.companies.map(c => `<option value="${esc(c.name)}">`).join('')}</datalist><div data-plink class="text-[11px]"></div>
                        ${!isIn ? `<div class="lt-grid lt-g2"><label><span class="lt-lbl">شخص (اختیاری)</span><input class="lt-in" data-f="party_person" value="${esc(L.party_person || '')}" placeholder="جناب آقای …"></label>
                        <label><span class="lt-lbl">سمت (اختیاری)</span><input class="lt-in" data-f="party_title" value="${esc(L.party_title || '')}" placeholder="مدیرعامل"></label></div>` : `<div class="lt-grid lt-g2"><label><span class="lt-lbl">شماره‌ی نامه‌ی فرستنده</span><input class="lt-in" data-f="ext_number" dir="ltr" value="${esc(fa(L.ext_number || ''))}"></label>
                        <label><span class="lt-lbl">تاریخِ نامه‌ی فرستنده</span><input class="lt-in" data-f="ext_j" data-jdate dir="ltr" value="${esc(fa(L.ext_j || ''))}"></label></div>`}</div>
                    <div class="lt-sec"><b><i class="fas fa-heading"></i>مشخصات</b>
                        <label><span class="lt-lbl">موضوع</span><input class="lt-in" data-f="subject" value="${esc(L.subject || '')}" placeholder="موضوعِ نامه"></label>
                        <div class="lt-grid lt-g2"><label><span class="lt-lbl">دسته (کدِ نوع در شماره)</span><select class="lt-sel" data-f="category_id"><option value="">بدونِ دسته</option>${opt(B.categories.filter(c => c.active || c.id === L.category_id).map(c => [c.id, c.name + ' (' + fa(c.code) + ')']), L.category_id || '')}</select></label>
                        <label><span class="lt-lbl">${isIn ? 'تاریخِ دریافت' : 'تاریخِ نامه'}</span><input class="lt-in" data-f="letter_j" data-jdate dir="ltr" value="${esc(fa(L.letter_j || B.today_j))}"></label></div>
                        ${issued && can('lt-box', 'edit') ? `<label><span class="lt-lbl">شماره‌ی نامه (تغییرِ دستی)</span><input class="lt-in" data-f="number" dir="ltr" value="${esc(fa(L.number))}"></label>` : ''}
                        <div><span class="lt-lbl">فوریت</span><div class="lt-tg" data-tg="priority">${Object.entries(B.priorities).map(([a, b]) => `<button data-v="${a}" class="${L.priority === a ? 'on' : ''} ${a !== 'normal' ? 'u' : ''}">${b}</button>`).join('')}</div></div>
                        <div><span class="lt-lbl">محرمانگی</span><div class="lt-tg" data-tg="conf">${Object.entries(B.confs).map(([a, b]) => `<button data-v="${a}" class="${L.conf === a ? 'on' : ''} ${a !== 'normal' ? 'c' : ''}">${b}</button>`).join('')}</div></div>
                        <label><span class="lt-lbl">پیوست (متنِ کنارِ شماره و تاریخ)</span><input class="lt-in" data-f="attach_note" value="${esc(L.attach_note || '')}" placeholder="ندارد / دارد / ۲ برگ"></label>
                        <label><span class="lt-lbl">برچسب‌ها (برای جستجو)</span><input class="lt-in" data-f="tags" value="${esc(L.tags || '')}" placeholder="مثلاً: تمدید، ۱۴۰۵"></label></div>
                    ${!isIn ? `<div class="lt-sec"><b><i class="fas fa-signature"></i>امضا و رونوشت</b>
                        <select class="lt-sel" data-f="signer_id"><option value="">بدونِ امضا</option>${opt(B.signers.filter(s => s.active || s.id === L.signer_id).map(s => [s.id, s.name + (s.title ? ' - ' + s.title : '')]), L.signer_id || '')}</select>
                        <label><span class="lt-lbl">رونوشت (هر سطر یک گیرنده)</span><textarea class="lt-ta" rows="2" data-f="cc" placeholder="واحدِ مالی&#10;بایگانی">${esc(L.cc || '')}</textarea></label></div>` : ''}
                    <div class="lt-sec"><b><i class="fas fa-reply"></i>پیگیری</b>
                        <label class="lt-chk"><input type="checkbox" data-f="needs_reply" ${+L.needs_reply ? 'checked' : ''}>${isIn ? 'نیاز به پاسخِ ما دارد' : 'منتظرِ پاسخ از گیرنده'}</label>
                        <label data-due style="${+L.needs_reply ? '' : 'display:none'}"><span class="lt-lbl">مهلتِ پاسخ (خالی = ${fa(S.reply_days)} روز)</span><input class="lt-in" data-f="reply_due_j" data-jdate dir="ltr" value="${esc(fa(L.reply_due_j || ''))}"></label></div>
                    <div class="lt-sec"><b><i class="fas fa-paperclip"></i>${isIn ? 'اسکنِ نامه و پیوست‌ها' : 'پیوست‌ها'}</b>
                        <div data-files class="flex flex-col gap-1">${(L.files || []).filter(f => f.kind === 'ATTACH' || f.kind === 'REPLY').map(f => `<div class="lt-file"><i class="fas fa-paperclip text-slate-400"></i>${fname(f.name)}<span class="text-[10px] text-slate-400">${kb(f.size)}</span></div>`).join('')}</div>
                        <label class="lt-btn s sm" style="cursor:pointer"><i class="fas fa-plus"></i>افزودنِ فایل<input type="file" multiple hidden data-pick></label><div data-pending class="flex flex-col gap-1"></div></div>
                </div>
                <div class="stage">${isIn ? '' : `<div data-tbhost></div><div style="height:10px"></div>`}<div class="lt-pagewrap" data-wrap></div>
                    ${isIn ? '' : `<p class="text-[11px] text-slate-500 text-center mt-3"><i class="fas fa-circle-info"></i> همین صفحه عیناً در PDF و Word می‌آید. سربرگ، شماره، تاریخ و پیوست خودکار جای‌گذاری می‌شوند؛ متغیرهایی مثلِ {شرکت} هنگامِ چاپ پر می‌شوند.</p>`}</div></div>`;
            document.body.appendChild(ov);
            document.documentElement.style.overflow = 'hidden';
            const close = () => { ov.remove(); document.documentElement.style.overflow = ''; window.removeEventListener('resize', fit); };
            const wrap = ov.querySelector('[data-wrap]');
            const state = Object.assign({}, L);
            const pending = [];
            const resolveParty = () => {
                const v = ov.querySelector('[data-f="party"]').value.trim();
                const c = B.companies.find(x => x.name.trim() === v);
                state.company_id = c ? c.id : null; state.party_name = c ? '' : v; state.party = v;
                const pl = ov.querySelector('[data-plink]');
                pl.innerHTML = !v ? '' : c ? '<span class="lt-tag g"><i class="fas fa-link"></i>شرکتِ ثبت‌شده - امکانِ ارسال به کارتابلِ پنلِ شرکت</span>' : '<span class="lt-tag s"><i class="fas fa-pen"></i>گیرنده‌ی آزاد (خارج از فهرستِ شرکت‌ها)</span>';
            };
            resolveParty();
            const body = () => ov.querySelector('[data-body]');
            const draw = keepBody => {
                const cur = keepBody && body() ? body().innerHTML : state.body_html;
                if (isIn) {
                    wrap.innerHTML = `<div class="lt-card" style="width:100%"><h3><i class="fas fa-align-right text-cyan-600"></i>خلاصه یا متنِ نامه‌ی وارده <small>(اختیاری؛ اسکنِ نامه را در «پیوست‌ها» بگذارید)</small></h3>
                        <div class="lt-page" style="position:static;width:100%;min-height:0;box-shadow:none;padding:12px;border:1px solid #e2e8f0;border-radius:14px;font-size:12pt;line-height:1.9"><div class="body" contenteditable="true" data-body data-ph="متن یا خلاصه‌ی نامه…" style="min-height:160px">${cur || ''}</div></div></div>`;
                    wrap.style.width = '100%'; wrap.style.height = 'auto'; wrap.style.overflow = 'visible';
                    setupArea(body());
                    return;
                }
                wrap.innerHTML = Page.html(state, `<div class="head" data-head title="برای تغییر، فرمِ کناری را ویرایش کنید">${headHtml(state)}</div><div class="body" contenteditable="true" data-body data-ph="متنِ نامه را این‌جا بنویسید…">${cur || ''}</div><div class="tail" data-tail>${tailHtml(state)}</div>`);
                setupArea(body());
                body().addEventListener('input', () => { fit(); dirty = true; });
                ov.querySelector('[data-head]').onclick = () => ov.querySelector('[data-f="party"]').focus();
                if (!tbBound) { ov.querySelector('[data-tbhost]').innerHTML = tb.html; }
                const tbe = ov.querySelector('.lt-tb');
                const tbx = toolbar(body()); tbx.bind(tbe);
                tbBound = true;
                requestAnimationFrame(fit);
                ov.querySelectorAll('.lt-page img').forEach(i => i.onload = fit);
            };
            let tbBound = false, dirty = false;
            const fit = () => { if (!isIn) Page.fit(wrap); };
            window.addEventListener('resize', fit);
            draw(false);
            // به‌روزرسانیِ سرِ نامه/امضا/شماره هنگامِ تغییرِ فرم
            const refreshParts = () => {
                if (isIn) return;
                const h = ov.querySelector('[data-head]'); if (h) h.innerHTML = headHtml(state);
                const t = ov.querySelector('[data-tail]'); if (t) t.innerHTML = tailHtml(state);
                const pg = wrap.querySelector('.lt-page');
                const tmp = document.createElement('div'); tmp.innerHTML = Page.html(state, '');
                const np = tmp.firstElementChild;
                pg.setAttribute('style', np.getAttribute('style'));
                ['.fld', '.stamps'].forEach(s => { const a = pg.querySelector(s), b = np.querySelector(s); if (a && b) a.replaceWith(b); else if (a) a.remove(); else if (b) pg.insertBefore(b, pg.firstChild); });
                fit();
            };
            ov.querySelectorAll('[data-f]').forEach(el => el.addEventListener(el.type === 'checkbox' || el.tagName === 'SELECT' || el.dataset.jdate !== undefined ? 'change' : 'input', () => {
                const k = el.dataset.f;
                dirty = true;
                if (k === 'party') resolveParty();
                else state[k] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : (el.dataset.jdate !== undefined || k === 'number' || k === 'ext_number' ? en(el.value) : el.value);
                if (k === 'needs_reply') ov.querySelector('[data-due]').style.display = el.checked ? '' : 'none';
                if (k === 'category_id' || k === 'letter_j') numPreview();
                refreshParts();
            }));
            ov.querySelectorAll('[data-tg]').forEach(g => g.querySelectorAll('button').forEach(b => b.onclick = () => {
                if (b.disabled) return;
                g.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
                state[g.dataset.tg] = b.dataset.v; dirty = true;
                if (g.dataset.tg === 'direction') numPreview();
                refreshParts();
            }));
            const tplSel = ov.querySelector('[data-tpl]');
            if (tplSel) tplSel.onchange = async () => {
                if (!tplSel.value) return;
                try {
                    const x = (await api('tpl_get', {id: tplSel.value})).template;
                    if (body() && body().textContent.trim() && !(await confirmUi('جایگزینیِ متن', 'متنِ فعلی با متنِ قالب جایگزین شود؟'))) { tplSel.value = ''; return; }
                    state.body_html = x.body_html || ''; body().innerHTML = state.body_html;
                    if (x.subject && !ov.querySelector('[data-f="subject"]').value) { ov.querySelector('[data-f="subject"]').value = x.subject; state.subject = x.subject; }
                    if (x.category_id && !state.category_id) { ov.querySelector('[data-f="category_id"]').value = x.category_id; state.category_id = x.category_id; }
                    refreshParts(); numPreview();
                } catch (e) { toast(e.message, 'error'); }
            };
            const numPreview = async () => {
                if (issued) return;
                const el = ov.querySelector('[data-numprev]');
                try { const r = await api('preview_number', {direction: state.direction, category_id: state.category_id || 0}); el.textContent = (isIn ? 'شماره‌ی وارده: ' : 'شماره‌ی بعدی: ') + fa(r.number); } catch (e) { el.textContent = ''; }
            };
            numPreview();
            // پیوست‌های در انتظار
            const drawPending = () => { ov.querySelector('[data-pending]').innerHTML = pending.map((f, i) => `<div class="lt-file"><i class="fas fa-file-arrow-up text-indigo-500"></i>${fname(f.name)}<span class="text-[10px] text-slate-400">${kb(f.size)}</span><button class="lt-btn r sm" data-rmp="${i}"><i class="fas fa-xmark"></i></button></div>`).join('');
                ov.querySelectorAll('[data-rmp]').forEach(b => b.onclick = () => { pending.splice(+b.dataset.rmp, 1); drawPending(); }); };
            ov.querySelector('[data-pick]').onchange = e => { [...e.target.files].forEach(f => pending.push(f)); e.target.value = ''; drawPending(); };
            const collect = () => {
                const fd = new FormData();
                const d = Object.assign({}, state, {body_html: body() ? body().innerHTML : state.body_html});
                ['direction', 'category_id', 'subject', 'company_id', 'party_name', 'party_person', 'party_title', 'cc', 'letter_j', 'ext_number', 'ext_j', 'priority', 'conf', 'letterhead', 'body_html', 'attach_note', 'signer_id', 'needs_reply', 'reply_due_j', 'tags', 'parent_id', 'number']
                    .forEach(k => { if (d[k] !== undefined && d[k] !== null) fd.append(k, d[k]); });
                if (o.id) fd.append('id', o.id);
                pending.forEach(f => fd.append('files[]', f));
                return fd;
            };
            const save = async (then, btn) => {
                const fd = collect();
                if (then) fd.append('then', then);
                busy(btn, true);
                try {
                    const r = await api('save', {}, fd);
                    dirty = false;
                    close();
                    Box.refresh();
                    if (then || isIn) Issued.show(r.letter);
                    else { toast('پیش‌نویس ذخیره شد.', 'success'); View.open(r.id); }
                } catch (e) { toast(e.message, 'error'); busy(btn, false); }
            };
            ov.querySelector('[data-save]').onclick = e => save('', e.currentTarget);
            const bi = ov.querySelector('[data-issue]'); if (bi) bi.onclick = async e => { if (await confirmUi('صدورِ نامه', 'نامه صادر شود و شماره بگیرد؟ بعد از صدور، شماره ثابت می‌ماند.')) save('issue', e.currentTarget); };
            const bs = ov.querySelector('[data-send]'); if (bs) bs.onclick = async e => {
                if (!state.company_id) { toast('برای ارسال به کارتابل، گیرنده باید یکی از شرکت‌های ثبت‌شده باشد.', 'error'); return; }
                if (await confirmUi('صدور و ارسال', 'نامه صادر شود و در کارتابلِ پنلِ شرکت قرار بگیرد؟ (اعلان در ربات بله هم فرستاده می‌شود)')) save('send', e.currentTarget);
            };
            const bp = ov.querySelector('[data-pdf]'); if (bp) bp.onclick = () => {
                // پیش‌نمایشِ PDF از روی همین فرم (بدونِ ذخیره)
                const fd = collect();
                const f = document.createElement('form'); f.method = 'POST'; f.action = API; f.target = '_blank'; f.style.display = 'none';
                const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
                add('action', 'draft_preview'); add('format', 'pdf');
                for (const [k, v] of fd.entries()) if (typeof v === 'string') add(k, v);
                document.body.appendChild(f); f.submit(); setTimeout(() => f.remove(), 1000);
            };
            const bw = ov.querySelector('[data-word]'); if (bw) bw.onclick = async () => { if (dirty) { toast('اول تغییرات را ذخیره کنید، بعد Word را باز کنید.', 'error'); return; } close(); Word.open(L); };
            ov.querySelector('[data-close]').onclick = async () => { if (dirty && !(await confirmUi('بستن', 'تغییرات ذخیره نشده‌اند. بسته شود؟'))) return; close(); };
            ov.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); ov.querySelector('[data-save]').click(); } });
            if (window.MoneyInput) MoneyInput.apply(ov);
            setTimeout(() => { const s = ov.querySelector('[data-f="party"]'); if (s && !s.value) s.focus(); }, 100);
        },
    };

    // پنجره‌ی موفقیتِ صدور
    const Issued = {
        show(L) {
            const m = modal(`<i class="fas fa-circle-check text-emerald-600"></i>${L.direction === 'IN' ? 'نامه‌ی وارده ثبت شد' : 'نامه صادر شد'}`, `<div class="text-center py-3">
                <div class="text-[12px] text-slate-500 font-bold mb-1">${L.direction === 'IN' ? 'شماره‌ی وارده' : 'شماره‌ی نامه'}</div><div class="lt-big">${fa(L.number || '—')}</div>
                <div class="text-[12px] text-slate-600 mt-2">${esc(L.subject)}${L.party ? ' - ' + esc(L.party) : ''}</div>
                <div class="flex flex-wrap justify-center gap-1 mt-2">${stTag(L)}${L.to_portal ? '<span class="lt-tag c"><i class="fas fa-building-circle-check"></i>در کارتابلِ شرکت قرار گرفت</span>' : ''}</div></div>`,
                `<button class="lt-btn s" data-copy><i class="fas fa-copy"></i>کپیِ شماره</button>${L.direction !== 'IN' ? `<button class="lt-btn o" data-pdf><i class="fas fa-file-pdf text-rose-600"></i>PDF</button><button class="lt-btn o" data-word><i class="fas fa-file-word text-blue-600"></i>Word</button>` : ''}<button class="lt-btn p" data-view><i class="fas fa-eye"></i>مشاهده‌ی نامه</button>`);
            m.querySelector('[data-copy]').onclick = () => { try { navigator.clipboard.writeText(fa(L.number)); toast('کپی شد.', 'success'); } catch (e) {} };
            const p = m.querySelector('[data-pdf]'); if (p) p.onclick = () => window.open(url('pdf', {id: L.id, inline: 1}), '_blank');
            const w = m.querySelector('[data-word]'); if (w) w.onclick = () => { m.close(); Word.open(L); };
            m.querySelector('[data-view]').onclick = () => { m.close(); View.open(L.id); };
        },
    };

    // رفت‌وبرگشت با Word
    const Word = {
        open(L) {
            const m = modal('<i class="fas fa-file-word text-blue-600"></i>ویرایش در Microsoft Word', `<div class="flex flex-col gap-3 text-[12.5px] leading-7">
                <div class="lt-row"><b>۱. دریافتِ فایلِ Word</b><span class="text-slate-600">فایل با همان سربرگ، شماره، تاریخ، پیوست، امضا و قلمِ «${esc(T.boot.settings.word_font)}» ساخته می‌شود. بازش کنید و هر تغییری خواستید بدهید.</span>
                    <div class="flex gap-2 flex-wrap"><button class="lt-btn p" data-dl><i class="fas fa-download"></i>دریافتِ فایلِ Word</button>${L.has_word ? '<button class="lt-btn o" data-dlw><i class="fas fa-clock-rotate-left"></i>آخرین نسخه‌ی بارگذاری‌شده</button>' : ''}</div></div>
                <div class="lt-row"><b>۲. ذخیره در Word و برگرداندن به سامانه</b><span class="text-slate-600">بعد از ذخیره (با پسوندِ docx)، فایل را این‌جا بکشید یا انتخاب کنید. متنِ نامه در سامانه هم از روی همین فایل به‌روز می‌شود تا PDF و کارتابلِ شرکت همان را نشان دهند.</span>
                    <label class="lt-chk"><input type="checkbox" data-sync checked>متنِ نامه از روی فایلِ Word به‌روز شود</label>
                    <label class="lt-row" style="border-style:dashed;align-items:center;cursor:pointer;background:#fff" data-drop><i class="fas fa-cloud-arrow-up text-2xl text-indigo-500"></i><span class="font-bold">فایلِ docx را این‌جا رها کنید یا کلیک کنید</span><input type="file" accept=".docx" hidden data-file></label></div>
                <p class="text-[11px] text-slate-500"><i class="fas fa-lightbulb text-amber-500"></i> پیشنهاد: برای بیشترِ نامه‌ها همان ویرایشگرِ داخلِ سامانه سریع‌تر است (همه‌چیز همان‌جا ذخیره و شماره‌گذاری می‌شود)؛ Word برای وقتی است که قالب‌بندیِ خاصی لازم دارید.</p></div>`);
            m.querySelector('[data-dl]').onclick = () => download(url('docx', {id: L.id, fresh: 1}));
            const dw = m.querySelector('[data-dlw]'); if (dw) dw.onclick = () => download(url('docx', {id: L.id}));
            const up = async file => {
                if (!file) return;
                if (!/\.docx$/i.test(file.name)) { toast('فایل باید docx باشد.', 'error'); return; }
                const fd = new FormData(); fd.append('id', L.id); fd.append('file', file); if (m.querySelector('[data-sync]').checked) fd.append('sync', 1);
                try { const r = await api('word_upload', {}, fd); toast(r.synced ? 'نسخه‌ی Word ذخیره شد و متنِ نامه به‌روز شد.' : 'نسخه‌ی Word ذخیره شد.', 'success'); m.close(); View.open(L.id); }
                catch (e) { toast(e.message, 'error'); }
            };
            m.querySelector('[data-file]').onchange = e => up(e.target.files[0]);
            const dz = m.querySelector('[data-drop]');
            dz.addEventListener('dragover', e => { e.preventDefault(); dz.style.background = '#eef2ff'; });
            dz.addEventListener('dragleave', () => { dz.style.background = '#fff'; });
            dz.addEventListener('drop', e => { e.preventDefault(); dz.style.background = '#fff'; up(e.dataTransfer.files[0]); });
        },
    };

    // =================================================================
    //  مشاهده‌ی نامه
    // =================================================================
    const View = {
        async open(id, onChange) {
            css();
            await boot();
            let L;
            try { L = (await api('detail', {id})).letter; } catch (e) { toast(e.message, 'error'); return; }
            const B = T.boot;
            const isIn = L.direction === 'IN';
            const ed = can('lt-box', 'edit'), cr = can('lt-box', 'create'), del = can('lt-box', 'delete');
            const draft = L.status === 'DRAFT';
            const btn = (k, ic, t, cls = 'o') => `<button class="lt-btn ${cls} sm" data-a="${k}"><i class="fas ${ic}"></i>${t}</button>`;
            let acts = '';
            if (draft) acts += (cr || ed ? btn('edit', 'fa-pen', 'ویرایش', 'p') : '') + (cr ? btn('issue', 'fa-stamp', 'صدور و شماره', 'g') : '') + (cr && L.company_id ? btn('issue_send', 'fa-paper-plane', 'صدور و ارسال به شرکت', 'g') : '');
            else {
                if (!isIn) acts += btn('pdf', 'fa-file-pdf', 'PDF / چاپ') + btn('word', 'fa-file-word', 'Word');
                if (!isIn && cr && L.company_id && !L.to_portal) acts += btn('send', 'fa-paper-plane', 'ارسال به کارتابلِ شرکت', 'g');
                if (!isIn && ed && L.to_portal) acts += btn('recall', 'fa-rotate-left', 'برداشتن از کارتابلِ شرکت', 'a');
                if (cr) acts += isIn ? btn('reply_out', 'fa-reply', 'پاسخ به این نامه', 'p') : btn('reg_in', 'fa-inbox', 'ثبتِ پاسخِ دریافتی') + btn('follow', 'fa-share-from-square', 'نامه‌ی پیرو');
                if (isIn && L.status === 'NEW' && (ed || cr)) acts += btn('reviewed', 'fa-check-double', 'بررسی شد', 'g');
                if (ed || cr) acts += L.status === 'ARCHIVED' ? btn('unarchive', 'fa-box-open', 'خروج از بایگانی') : btn('archive', 'fa-box-archive', 'بایگانی');
                if (ed) acts += btn('edit', 'fa-pen', 'ویرایش');
                if (!isIn && (cr || ed)) acts += btn('signed', 'fa-file-signature', 'بارگذاریِ نسخه‌ی امضاشده');
            }
            if (del) acts += btn('delete', 'fa-trash', 'حذف', 'r');
            const files = L.files || [];
            const fileRow = f => `<div class="lt-file"><i class="fas ${f.kind === 'WORD' ? 'fa-file-word text-blue-600' : f.kind === 'SIGNED' ? 'fa-file-signature text-emerald-600' : f.kind === 'REPLY' ? 'fa-reply text-cyan-600' : 'fa-paperclip text-slate-400'}"></i>${fname(f.name)}
                <span class="text-[10px] text-slate-400" title="${esc(f.by)} - ${esc(fa(f.at))}">${kb(f.size)}</span><a class="lt-btn s sm" href="${url('file', {id: f.id, inline: 1})}" target="_blank"><i class="fas fa-eye"></i></a><a class="lt-btn s sm" href="${url('file', {id: f.id})}"><i class="fas fa-download"></i></a>
                ${(ed || del) && f.kind !== 'REPLY' ? `<button class="lt-btn r sm" data-fdel="${f.id}"><i class="fas fa-trash"></i></button>` : ''}</div>`;
            const kind = {ATTACH: 'پیوست', WORD: 'نسخه‌ی Word', SIGNED: 'نسخه‌ی امضاشده', REPLY: 'فایلِ شرکت'};
            const ACT = {create: 'ایجاد', edit: 'ویرایش', issue: 'صدور / شماره', send: 'ارسال به شرکت', seen: 'دیده شد', reply: 'پاسخ', ref: 'ارجاع', ref_done: 'انجامِ ارجاع', pdf: 'PDF', docx: 'Word', word: 'نسخه‌ی Word', signed: 'نسخه‌ی امضاشده', attach: 'پیوست', status: 'وضعیت', recall: 'برداشتن از کارتابل', renumber: 'تغییرِ شماره', file_delete: 'حذفِ فایل', delete: 'حذف'};
            const myRef = (L.refs || []).find(r => r.mine && !r.done);
            const m = modal(`<i class="fas ${DIR_ICON[L.direction]} text-indigo-600"></i>${numHtml(L)} <span>${esc(L.subject)}</span>`, `
                ${myRef ? `<div class="lt-row mb-3" style="background:#fdf2f8;border-color:#fbcfe8"><b class="text-pink-700"><i class="fas fa-share"></i> این نامه از طرفِ ${esc(myRef.from)} به شما ارجاع شده${myRef.due_j ? ' - مهلت ' + fa(myRef.due_j) : ''}</b>${myRef.note ? `<div>${esc(myRef.note)}</div>` : ''}
                    <div class="flex gap-2"><input class="lt-in" data-refnote placeholder="شرحِ اقدام (اختیاری)"><button class="lt-btn g sm" data-refdone="${myRef.id}"><i class="fas fa-check"></i>اقدام شد</button></div></div>` : ''}
                <div class="flex flex-wrap gap-2 mb-3">${acts}</div>
                <div class="lt-grid lt-vgrid" style="grid-template-columns:minmax(0,1fr) 340px;align-items:start">
                    <div><div class="lt-pagewrap" data-wrap style="background:#eef1f8;border-radius:14px"></div></div>
                    <div class="lt-side">
                        <div class="lt-card"><h3><i class="fas fa-circle-info text-indigo-600"></i>مشخصات</h3><div class="lt-kv">
                            <span>نوع</span><b>${esc(L.direction_fa)}</b><span>وضعیت</span><b><span class="flex flex-wrap gap-1">${stTag(L)}</span></b>
                            <span>${isIn ? 'فرستنده' : 'گیرنده'}</span><b>${esc(L.party || '—')}${L.party_person ? '<br><small>' + esc(L.party_person) + (L.party_title ? ' - ' + esc(L.party_title) : '') + '</small>' : ''}</b>
                            <span>دسته</span><b>${catTag(L.category_id) || '—'}</b><span>تاریخ</span><b>${fa(L.letter_j)}</b>
                            ${L.ext_number || L.ext_j ? `<span>نامه‌ی فرستنده</span><b>${fa(L.ext_number || '')} ${L.ext_j ? '- ' + fa(L.ext_j) : ''}</b>` : ''}
                            <span>فوریت/محرمانگی</span><b>${prioTag(L) || 'عادی'} ${confTag(L)}</b>
                            ${L.needs_reply ? `<span>پاسخ</span><b>${L.reply_due_j ? 'تا ' + fa(L.reply_due_j) : 'لازم است'}</b>` : ''}
                            <span>ثبت‌کننده</span><b>${esc(L.portal_user ? L.portal_user + ' (کاربرِ شرکت)' : L.created_by_name)}<br><small>${fa(L.created_at_j)}</small></b>
                            ${L.issued_at_j ? `<span>صدور</span><b>${esc(L.issued_by_name || '')}<br><small>${fa(L.issued_at_j)}</small></b>` : ''}
                            ${L.sent_at_j ? `<span>کارتابلِ شرکت</span><b>${fa(L.sent_at_j)}${L.seen_at_j ? `<br><small class="text-emerald-700"><i class="fas fa-eye"></i> دیده شد ${fa(L.seen_at_j)}</small>` : '<br><small class="text-amber-600">هنوز دیده نشده</small>'}</b>` : ''}
                            ${L.tags ? `<span>برچسب</span><b>${esc(L.tags)}</b>` : ''}</div></div>
                        <div class="lt-card"><h3><i class="fas fa-paperclip text-indigo-600"></i>فایل‌ها <small>${fa(files.length)}</small></h3><div class="flex flex-col gap-1">${files.length ? Object.keys(kind).map(k => files.filter(f => f.kind === k).length ? `<div class="text-[10.5px] font-black text-slate-500 mt-1">${kind[k]}</div>` + files.filter(f => f.kind === k).map(fileRow).join('') : '').join('') : '<div class="text-[11.5px] text-slate-400">فایلی نیست.</div>'}</div>
                            ${cr || ed ? '<label class="lt-btn s sm mt-2" style="cursor:pointer"><i class="fas fa-plus"></i>افزودنِ پیوست<input type="file" multiple hidden data-addf></label>' : ''}</div>
                        <div class="lt-card"><h3><i class="fas fa-share text-pink-600"></i>ارجاع‌ها</h3><div class="lt-tl">${(L.refs || []).map(r => `<div class="e"><b>${esc(r.from)} ← ${esc(r.to)}</b>${r.note ? `<div>${esc(r.note)}</div>` : ''}<small>${fa(r.at)}${r.due_j ? ' - مهلت ' + fa(r.due_j) : ''} ${r.done ? `<span class="lt-tag g">انجام شد ${fa(r.done)}</span>${r.done_note ? ' ' + esc(r.done_note) : ''}` : (r.seen ? '<span class="lt-tag b">دیده شد</span>' : '<span class="lt-tag a">دیده نشده</span>')}</small></div>`).join('') || '<div class="text-[11.5px] text-slate-400">ارجاعی ثبت نشده.</div>'}</div>
                            <button class="lt-btn v sm mt-2" data-ref><i class="fas fa-share"></i>ارجاع به همکار</button></div>
                        ${(L.thread || []).length > 1 ? `<div class="lt-card"><h3><i class="fas fa-comments text-cyan-600"></i>رشته‌ی نامه‌ها</h3><div class="lt-thread">${L.thread.map(t => `<div class="lt-th ${t.direction === 'IN' ? 'in' : 'out'} ${t.id === L.id ? 'cur' : ''}" data-th="${t.id}"><div class="flex justify-between gap-2"><b>${t.direction === 'IN' ? '<i class="fas fa-inbox text-cyan-600"></i> وارده' : '<i class="fas fa-paper-plane text-indigo-600"></i> صادره'} ${numHtml(t)}</b><small>${fa(t.letter_j)}</small></div><div class="truncate mt-1">${esc(t.subject)}</div>${t.portal_user ? `<small class="text-slate-500">${esc(t.portal_user)} (شرکت)</small>` : ''}</div>`).join('')}</div></div>` : ''}
                        <div class="lt-card"><h3 style="cursor:pointer" data-logt><i class="fas fa-clock-rotate-left text-slate-500"></i>سابقه <small>${fa((L.log || []).length)} رویداد - نمایش</small></h3><div class="lt-tl" data-log style="display:none">${(L.log || []).map(g => `<div class="e"><b>${esc(ACT[g.action] || g.action)}</b> ${esc(g.detail || '')}<small>${esc(g.by)} - ${fa(g.at)}${g.ip ? ' - IP ' + esc(g.ip) : ''}</small></div>`).join('')}</div></div>
                    </div></div>`, '', {wide: true, onClose: onChange});
            // پیش‌نمایش
            const wrap = m.querySelector('[data-wrap]');
            try {
                const pv = await api('preview_html', {id});
                if (isIn) {
                    wrap.style.background = 'none';
                    wrap.innerHTML = `<div class="lt-card"><h3><i class="fas fa-inbox text-cyan-600"></i>متنِ نامه‌ی وارده</h3><div class="leading-8 text-[13px]">${pv.html || '<span class="text-slate-400">متنی ثبت نشده؛ فایل‌ها را ببینید.</span>'}</div></div>`;
                    wrap.style.height = 'auto'; wrap.style.width = '100%';
                } else {
                    wrap.innerHTML = Page.html(L, `<div class="tail">${pv.html}</div>`);
                    const fitv = () => Page.fit(wrap);
                    requestAnimationFrame(fitv); setTimeout(fitv, 300);
                    wrap.querySelectorAll('img').forEach(i => i.onload = fitv);
                    window.addEventListener('resize', fitv);
                }
            } catch (e) { wrap.innerHTML = `<div class="lt-empty">${esc(e.message)}</div>`; }
            const reload = () => { m.close(); View.open(id, onChange); };
            const act = async (k, b) => {
                try {
                    if (k === 'edit') { m.close(); Compose.open({id}); return; }
                    if (k === 'pdf') { window.open(url('pdf', {id, inline: 1}), '_blank'); return; }
                    if (k === 'word') { Word.open(L); return; }
                    if (k === 'issue' || k === 'issue_send') { if (!(await confirmUi('صدور', 'نامه صادر شود و شماره بگیرد؟'))) return; busy(b, true); const r = await api('issue', {id, send: k === 'issue_send' ? 1 : 0}); m.close(); Box.refresh(); Issued.show(r.letter); return; }
                    if (k === 'send') { if (!(await confirmUi('ارسال', 'نامه در کارتابلِ پنلِ شرکت قرار بگیرد؟'))) return; busy(b, true); await api('send', {id}); toast('در کارتابلِ شرکت قرار گرفت.', 'success'); reload(); return; }
                    if (k === 'recall') { if (!(await confirmUi('برداشتن', 'نامه از کارتابلِ شرکت برداشته شود؟'))) return; await api('recall', {id}); reload(); return; }
                    if (k === 'archive' || k === 'unarchive' || k === 'reviewed') { await api('set_status', {id, status: k === 'archive' ? 'ARCHIVED' : (k === 'reviewed' ? 'REVIEWED' : (isIn ? 'REVIEWED' : 'ISSUED'))}); reload(); return; }
                    if (k === 'delete') { if (!(await confirmUi('حذفِ نامه', 'این نامه حذف شود؟', {danger: true}))) return; await api('delete', {id}); m.close(); toast('حذف شد.', 'success'); Box.refresh(); return; }
                    if (k === 'reply_out') { m.close(); Compose.open({parent: L, direction: 'OUT'}); return; }
                    if (k === 'follow') { m.close(); Compose.open({parent: L, direction: 'OUT'}); return; }
                    if (k === 'reg_in') { m.close(); Compose.open({parent: L, direction: 'IN'}); return; }
                    if (k === 'signed') { const i = document.createElement('input'); i.type = 'file'; i.accept = '.pdf,.jpg,.jpeg,.png'; i.onchange = async () => { const fd = new FormData(); fd.append('id', id); fd.append('kind', 'SIGNED'); fd.append('file', i.files[0]); try { await api('file_upload', {}, fd); toast('نسخه‌ی امضاشده ذخیره شد؛ شرکت هم همین را می‌بیند.', 'success'); reload(); } catch (e) { toast(e.message, 'error'); } }; i.click(); return; }
                } catch (e) { toast(e.message, 'error'); busy(b, false); }
            };
            m.querySelectorAll('[data-a]').forEach(b => b.onclick = () => act(b.dataset.a, b));
            const af = m.querySelector('[data-addf]'); if (af) af.onchange = async () => { const fd = new FormData(); fd.append('id', id); [...af.files].forEach(f => fd.append('files[]', f)); try { await api('file_upload', {}, fd); toast('پیوست اضافه شد.', 'success'); reload(); } catch (e) { toast(e.message, 'error'); } };
            m.querySelectorAll('[data-fdel]').forEach(b => b.onclick = async () => { if (!(await confirmUi('حذفِ فایل', 'این فایل حذف شود؟', {danger: true}))) return; try { await api('file_delete', {id: b.dataset.fdel}); reload(); } catch (e) { toast(e.message, 'error'); } });
            m.querySelector('[data-logt]').onclick = () => { const l = m.querySelector('[data-log]'); l.style.display = l.style.display === 'none' ? '' : 'none'; };
            m.querySelectorAll('[data-th]').forEach(t => t.onclick = () => { if (+t.dataset.th !== id) { m.close(); View.open(+t.dataset.th, onChange); } });
            const rd = m.querySelector('[data-refdone]'); if (rd) rd.onclick = async () => { try { await api('ref_done', {ref_id: rd.dataset.refdone, note: m.querySelector('[data-refnote]').value}); toast('ثبت شد.', 'success'); reload(); } catch (e) { toast(e.message, 'error'); } };
            m.querySelector('[data-ref]').onclick = () => {
                const r = modal('<i class="fas fa-share text-pink-600"></i>ارجاعِ نامه', `<div class="flex flex-col gap-3"><div><span class="lt-lbl">به چه کسانی؟</span><input class="lt-in mb-2" data-fs placeholder="جستجوی نام…"><div class="flex flex-col gap-1" style="max-height:220px;overflow:auto" data-people>${B.staff.filter(s => s.id !== B.uid).map(s => `<label class="lt-chk" data-nm="${esc(s.name)}"><input type="checkbox" value="${s.id}">${esc(s.name)}</label>`).join('')}</div></div>
                    <label><span class="lt-lbl">دستور / توضیح</span><textarea class="lt-ta" rows="3" data-note placeholder="مثلاً: لطفاً بررسی و اقدام شود"></textarea></label><label><span class="lt-lbl">مهلت (اختیاری)</span><input class="lt-in" data-due data-jdate dir="ltr"></label></div>`,
                    '<button class="lt-btn p" data-ok><i class="fas fa-share"></i>ارجاع</button>');
                r.querySelector('[data-fs]').oninput = e => r.querySelectorAll('[data-nm]').forEach(l => { l.style.display = l.dataset.nm.includes(e.target.value.trim()) ? '' : 'none'; });
                r.querySelector('[data-ok]').onclick = async e => {
                    const to = [...r.querySelectorAll('[data-people] input:checked')].map(i => +i.value);
                    busy(e.currentTarget, true);
                    try { await api('ref_add', {id, to, note: r.querySelector('[data-note]').value, due_j: en(r.querySelector('[data-due]').value)}); r.close(); toast('ارجاع شد.', 'success'); reload(); }
                    catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
                };
            };
        },
    };

    // =================================================================
    //  تنظیمات
    // =================================================================
    const SUBS = [['number', 'fa-hashtag', 'شماره‌گذاری'], ['page', 'fa-file-image', 'سربرگ و صفحه'], ['cats', 'fa-tags', 'دسته‌ها'], ['tpls', 'fa-file-lines', 'قالب‌ها'], ['signers', 'fa-signature', 'امضاها'], ['general', 'fa-gear', 'کارتابلِ شرکت و اعلان']];
    const Sett = {
        d: null,
        async render(root) {
            root.innerHTML = shell('lt-settings', `<div class="lt-card"><div class="lt-sub">${SUBS.map(s => `<button data-s="${s[0]}" class="${T.set.sub === s[0] ? 'on' : ''}"><i class="fas ${s[1]}"></i> ${s[2]}</button>`).join('')}</div><div data-body><div class="lt-empty"><i class="fas fa-spinner fa-spin"></i></div></div></div>`);
            bindShell(root);
            root.querySelectorAll('[data-s]').forEach(b => b.onclick = () => { T.set.sub = b.dataset.s; this.render(root); });
            try { this.d = await api('settings_get'); } catch (e) { root.querySelector('[data-body]').innerHTML = `<div class="lt-empty">${esc(e.message)}</div>`; return; }
            const ed = can('lt-settings', 'edit');
            const body = root.querySelector('[data-body]');
            this[T.set.sub](body, ed, root);
            if (!ed) body.querySelectorAll('input,select,textarea,button:not([data-s])').forEach(x => { if (!x.closest('.lt-sub')) x.disabled = true; });
        },
        async saveSettings(patch, root, msg) {
            try { await api('settings_save', {settings: patch}); toast(msg || 'ذخیره شد.', 'success'); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); }
        },
        number(body, ed, root) {
            const s = this.d.settings;
            const pat = (k, l) => `<label><span class="lt-lbl">${l}</span><div class="flex gap-2"><input class="lt-in" dir="ltr" data-p="${k}" value="${esc(s[k])}" style="font-weight:800;letter-spacing:.5px"><span class="lt-tag b" data-pv="${k}" style="min-width:150px;justify-content:center;font-size:12px"></span></div></label>`;
            body.innerHTML = `<p class="text-[12px] text-slate-600 leading-7 mb-3">شماره‌ی هر نامه هنگامِ «صدور» از روی الگو ساخته می‌شود. روی هر برچسب بزنید تا در الگوی انتخاب‌شده درج شود؛ بقیه‌ی نویسه‌ها (مثلِ / یا -) همان‌طور می‌مانند. {SEQ} شماره‌ی ترتیبی است و الزامی است.</p>
                <div class="lt-tok mb-3">${Object.entries(T.boot.tokens).map(([k, v]) => `<button class="lt-chip" data-tok="${k}" title="${esc(v)}"><b dir="ltr">${k}</b> ${esc(v)}</button>`).join('')}</div>
                <div class="lt-grid lt-g3">${pat('pattern_out', 'الگوی نامه‌ی صادره')}${pat('pattern_in', 'الگوی نامه‌ی وارده')}${pat('pattern_int', 'الگوی نامه‌ی داخلی')}</div>
                <div class="lt-grid lt-g4 mt-3"><label><span class="lt-lbl">عددِ ثابت {FIX}</span><input class="lt-in" dir="ltr" data-p="fix" value="${esc(fa(s.fix))}"></label>
                    <label><span class="lt-lbl">تعدادِ رقمِ شماره‌ی ترتیبی</span><input class="lt-in" data-p="seq_digits" value="${fa(s.seq_digits)}"></label>
                    <label><span class="lt-lbl">شروعِ شماره‌ی ترتیبی از</span><input class="lt-in" data-p="seq_start" value="${fa(s.seq_start)}"></label>
                    <label><span class="lt-lbl">شماره‌ی ترتیبی از اول شروع شود</span><select class="lt-sel" data-p="seq_scope">${[['year', 'هر سالِ شمسی'], ['year_type', 'هر سال و هر نوعِ نامه جدا'], ['month', 'هر ماه'], ['never', 'هیچ‌وقت (پیوسته)']].map(([a, b]) => `<option value="${a}" ${s.seq_scope === a ? 'selected' : ''}>${b}</option>`).join('')}</select></label></div>
                <label class="lt-chk mt-3"><input type="checkbox" data-p="fa_digits" ${+s.fa_digits ? 'checked' : ''}>شماره در نامه با رقم‌های فارسی چاپ شود</label>
                <div class="flex gap-2 mt-3"><select class="lt-sel" data-pvcat style="max-width:260px"><option value="0">پیش‌نمایش با دسته…</option>${this.d.categories.map(c => `<option value="${c.id}">${esc(c.name)} (${fa(c.code)})</option>`).join('')}</select>${ed ? '<button class="lt-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>' : ''}</div>
                <div class="lt-card mt-4" style="box-shadow:none"><h3><i class="fas fa-gauge text-indigo-600"></i>شمارنده‌ها <small>آخرین شماره‌ی مصرف‌شده‌ی هر دوره؛ با تغییرش، نامه‌ی بعدی از عددِ بعدی شماره می‌گیرد</small></h3>
                    ${this.d.counters.length ? `<div class="lt-scroll"><table class="lt-tbl no-count"><thead><tr><th>شمارنده</th><th>آخرین شماره</th><th></th></tr></thead><tbody>${this.d.counters.map(c => `<tr style="cursor:default"><td dir="ltr" class="text-right font-bold">${esc(c.k.replace('OUT', 'صادره').replace('INT', 'داخلی').replace('IN', 'وارده'))}</td><td><input class="lt-in" style="max-width:120px" data-cv="${esc(c.k)}" value="${fa(c.v)}"></td><td>${ed ? `<button class="lt-btn s sm" data-cs="${esc(c.k)}">ثبت</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<div class="text-[12px] text-slate-400">هنوز نامه‌ای صادر نشده.</div>'}</div>`;
            let last = body.querySelector('[data-p="pattern_out"]');
            body.querySelectorAll('[data-p^="pattern"]').forEach(i => i.addEventListener('focus', () => { last = i; }));
            body.querySelectorAll('[data-tok]').forEach(b => b.onclick = () => { const i = last, p = i.selectionStart ?? i.value.length; i.value = i.value.slice(0, p) + b.dataset.tok + i.value.slice(i.selectionEnd ?? p); i.focus(); i.selectionStart = i.selectionEnd = p + b.dataset.tok.length; pv(); });
            const val = k => { const x = body.querySelector(`[data-p="${k}"]`); return x.type === 'checkbox' ? (x.checked ? 1 : 0) : en(x.value); };
            let tm;
            const pv = () => { clearTimeout(tm); tm = setTimeout(async () => {
                for (const [k, dir] of [['pattern_out', 'OUT'], ['pattern_in', 'IN'], ['pattern_int', 'INT']]) {
                    const el = body.querySelector(`[data-pv="${k}"]`);
                    try { const r = await api('preview_number', {direction: dir, category_id: body.querySelector('[data-pvcat]').value, ['pattern_' + dir.toLowerCase()]: val(k), fix: val('fix'), seq_digits: val('seq_digits'), seq_start: val('seq_start'), seq_scope: val('seq_scope')});
                        el.textContent = (+val('fa_digits') ? fa(r.number) : r.number); el.className = 'lt-tag ' + (val(k).includes('{SEQ}') ? 'b' : 'r'); if (!val(k).includes('{SEQ}')) el.textContent = '{SEQ} لازم است'; }
                    catch (e) { el.textContent = '—'; }
                }
            }, 250); };
            body.querySelectorAll('[data-p],[data-pvcat]').forEach(i => i.addEventListener(i.tagName === 'SELECT' || i.type === 'checkbox' ? 'change' : 'input', pv));
            pv();
            const sv = body.querySelector('[data-save]'); if (sv) sv.onclick = () => this.saveSettings(Object.fromEntries(['pattern_out', 'pattern_in', 'pattern_int', 'fix', 'seq_digits', 'seq_start', 'seq_scope', 'fa_digits'].map(k => [k, val(k)])), root, 'الگوی شماره ذخیره شد.');
            body.querySelectorAll('[data-cs]').forEach(b => b.onclick = async () => { if (!(await confirmUi('تغییرِ شمارنده', 'شمارنده تغییر کند؟ ممکن است شماره‌ی تکراری ساخته شود (سامانه شماره‌ی تکراری را رد می‌کند و بعدی را می‌گیرد).'))) return;
                try { await api('counter_set', {k: b.dataset.cs, v: en(body.querySelector(`[data-cv="${CSS.escape(b.dataset.cs)}"]`).value)}); toast('ثبت شد.', 'success'); } catch (e) { toast(e.message, 'error'); } });
        },
        page(body, ed, root) {
            const s = this.d.settings;
            const n = (k, l, step = 1) => `<label><span class="lt-lbl">${l}</span><input class="lt-in" data-p="${k}" value="${fa(s[k])}" inputmode="decimal" data-step="${step}"></label>`;
            body.innerHTML = `<div class="flex flex-wrap gap-5 items-start"><div class="flex flex-col items-center gap-2"><div class="lt-mini" data-mini></div><small class="text-slate-500">پیش‌نمایشِ جای متن و شماره/تاریخ</small></div>
                <div style="flex:1;min-width:280px" class="flex flex-col gap-3">
                    <div class="lt-row"><b><i class="fas fa-image text-indigo-600"></i> تصویرِ سربرگ (A4، PNG یا JPG)</b><span class="text-[11.5px] text-slate-600">یک تصویرِ کاملِ صفحه‌ی A4 (مثلاً ۲۴۸۰×۳۵۰۸ پیکسل) از سربرگِ شرکت. پشتِ متن در PDF و Word قرار می‌گیرد. اگر روی کاغذِ سربرگ‌دار چاپ می‌کنید، نامه را «بدونِ سربرگ» بزنید تا فقط شماره/تاریخ در همان جا چاپ شود.</span>
                        <div class="flex gap-2 flex-wrap">${ed ? `<label class="lt-btn p sm" style="cursor:pointer"><i class="fas fa-upload"></i>${s.has_letterhead ? 'تعویضِ سربرگ' : 'بارگذاریِ سربرگ'}<input type="file" accept="image/png,image/jpeg" hidden data-lh></label>${s.has_letterhead ? '<button class="lt-btn r sm" data-lhrm><i class="fas fa-trash"></i>حذف</button>' : ''}` : ''}
                        <select class="lt-sel" data-p="letterhead_pages" style="max-width:220px"><option value="all" ${s.letterhead_pages === 'all' ? 'selected' : ''}>سربرگ در همه‌ی صفحه‌ها</option><option value="first" ${s.letterhead_pages === 'first' ? 'selected' : ''}>فقط صفحه‌ی اول</option></select>
                        <label class="lt-chk"><input type="checkbox" data-p="default_letterhead" ${+s.default_letterhead ? 'checked' : ''}>نامه‌ی جدید به‌طورِ پیش‌فرض با سربرگ</label></div></div>
                    <div class="lt-row"><b>حاشیه‌ی متن (میلی‌متر)</b><div class="lt-grid lt-g4">${n('m_top', 'بالا')}${n('m_bottom', 'پایین')}${n('m_right', 'راست')}${n('m_left', 'چپ')}</div></div>
                    <div class="lt-row"><b>شماره / تاریخ / پیوست</b><div class="flex gap-3 flex-wrap"><label class="lt-chk"><input type="checkbox" data-p="show_fields" ${+s.show_fields ? 'checked' : ''}>چاپ شود</label><label class="lt-chk"><input type="checkbox" data-p="field_labels" ${+s.field_labels ? 'checked' : ''}>با عنوان («شماره:»؛ اگر روی سربرگ چاپ شده، خاموش کنید)</label></div>
                        <div class="lt-grid lt-g4">${n('f_x', 'فاصله از لبه‌ی چپ')}${n('f_y', 'فاصله از بالا')}${n('f_gap', 'فاصله‌ی سطرها')}${n('f_size', 'اندازه‌ی قلم (pt)')}</div></div>
                    <div class="lt-row"><b>متن</b><div class="lt-grid lt-g3">${n('font_size', 'اندازه‌ی قلمِ متن (pt)')}${n('line_height', 'فاصله‌ی سطرها (ضریب)')}<label><span class="lt-lbl">قلمِ فایلِ Word</span><input class="lt-in" data-p="word_font" value="${esc(s.word_font)}" placeholder="B Nazanin"></label></div>
                        <label><span class="lt-lbl">متنِ پانویسِ صفحه (اختیاری)</span><input class="lt-in" data-p="footer_text" value="${esc(s.footer_text)}" placeholder="نشانی و تلفن"></label>
                        <div class="flex gap-3 flex-wrap"><label class="lt-chk"><input type="checkbox" data-p="page_numbers" ${+s.page_numbers ? 'checked' : ''}>شماره‌ی صفحه</label><label class="lt-chk"><input type="checkbox" data-p="auto_head" ${+s.auto_head ? 'checked' : ''}>سرِ نامه (گیرنده و موضوع) خودکار</label><label class="lt-chk"><input type="checkbox" data-p="stamp_priority" ${+s.stamp_priority ? 'checked' : ''}>مهرِ «فوری/محرمانه» روی نامه</label></div></div>
                    ${ed ? '<div><button class="lt-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button></div>' : ''}</div></div>`;
            const val = k => { const x = body.querySelector(`[data-p="${k}"]`); return x.type === 'checkbox' ? (x.checked ? 1 : 0) : (x.dataset.step ? +en(x.value) || 0 : x.value); };
            const mini = () => {
                const mm = 1.3; // ۱٫۳ پیکسل = ۱ میلی‌متر
                const box = body.querySelector('[data-mini]');
                box.style.backgroundImage = s.has_letterhead ? `url('${url('letterhead_img', {v: Date.now()})}')` : 'none';
                box.innerHTML = `<div class="m" style="top:${val('m_top') * mm}px;bottom:${val('m_bottom') * mm}px;right:${val('m_right') * mm}px;left:${val('m_left') * mm}px"></div>`
                    + (val('show_fields') ? ['شماره', 'تاریخ', 'پیوست'].map((t, i) => `<div class="fb" style="left:${val('f_x') * mm}px;top:${(val('f_y') + i * val('f_gap')) * mm}px">${t}</div>`).join('') : '');
            };
            mini();
            body.querySelectorAll('[data-p]').forEach(i => i.addEventListener(i.type === 'checkbox' || i.tagName === 'SELECT' ? 'change' : 'input', mini));
            const sv = body.querySelector('[data-save]'); if (sv) sv.onclick = () => this.saveSettings(Object.fromEntries([...body.querySelectorAll('[data-p]')].map(i => [i.dataset.p, val(i.dataset.p)])), root, 'تنظیماتِ صفحه ذخیره شد.');
            const lh = body.querySelector('[data-lh]'); if (lh) lh.onchange = async () => { const fd = new FormData(); fd.append('file', lh.files[0]); try { const r = await api('letterhead_upload', {}, fd); if (Math.abs(r.ratio - 1.414) > .08) toast('تصویر ذخیره شد، ولی نسبتش A4 نیست و کشیده دیده می‌شود.', 'error'); else toast('سربرگ ذخیره شد.', 'success'); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); } };
            const lr = body.querySelector('[data-lhrm]'); if (lr) lr.onclick = async () => { if (!(await confirmUi('حذفِ سربرگ', 'تصویرِ سربرگ حذف شود؟', {danger: true}))) return; try { await api('letterhead_upload', {remove: 1}); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); } };
        },
        cats(body, ed, root) {
            const icons = ['fa-envelope', 'fa-car', 'fa-heart-pulse', 'fa-stethoscope', 'fa-coins', 'fa-id-card', 'fa-fire', 'fa-ship', 'fa-plane', 'fa-scale-balanced', 'fa-building', 'fa-file-contract', 'fa-users', 'fa-gavel'];
            const row = c => `<div class="lt-row" data-cat="${c.id || ''}"><div class="flex flex-wrap gap-2 items-end">
                <label style="flex:2 1 180px"><span class="lt-lbl">نام</span><input class="lt-in" data-k="name" value="${esc(c.name || '')}"></label>
                <label style="width:110px"><span class="lt-lbl">کد در شماره</span><input class="lt-in" dir="ltr" data-k="code" value="${esc(fa(c.code ?? ''))}"></label>
                <label style="width:80px"><span class="lt-lbl">رنگ</span><input type="color" class="lt-in" style="padding:2px;height:37px" data-k="color" value="${esc(c.color || '#475569')}"></label>
                <label style="width:150px"><span class="lt-lbl">نماد</span><select class="lt-sel" data-k="icon">${icons.map(i => `<option value="${i}" ${c.icon === i ? 'selected' : ''}>${i.replace('fa-', '')}</option>`).join('')}</select></label>
                <label class="lt-chk pb-2"><input type="checkbox" data-k="active" ${c.active === 0 ? '' : 'checked'}>فعال</label><label class="lt-chk pb-2"><input type="checkbox" data-k="portal" ${c.portal === 0 ? '' : 'checked'}>در پاسخِ شرکت‌ها قابلِ انتخاب</label>
                ${ed ? '<button class="lt-btn r sm mb-1" data-rm><i class="fas fa-trash"></i></button>' : ''}</div></div>`;
            body.innerHTML = `<p class="text-[12px] text-slate-600 leading-7 mb-3">«کد» همان رقمی است که با {TYPE} در شماره‌ی نامه می‌نشیند (مثلاً ثالث و بدنه = ۲). دسته‌ای که نامه دارد پاک نمی‌شود، فقط غیرفعال می‌شود.</p>
                <div class="flex flex-col gap-2" data-list>${this.d.categories.map(row).join('')}</div>${ed ? '<div class="flex gap-2 mt-3"><button class="lt-btn s" data-add><i class="fas fa-plus"></i>دسته‌ی جدید</button><button class="lt-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button></div>' : ''}`;
            if (!ed) return;
            const bind = () => body.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => b.closest('[data-cat]').remove());
            bind();
            body.querySelector('[data-add]').onclick = () => { body.querySelector('[data-list]').insertAdjacentHTML('beforeend', row({active: 1, portal: 1})); bind(); };
            body.querySelector('[data-save]').onclick = async () => {
                const categories = [...body.querySelectorAll('[data-cat]')].map(c => { const v = k => { const x = c.querySelector(`[data-k=${k}]`); return x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; }; return {id: c.dataset.cat || 0, name: v('name'), code: en(v('code')), color: v('color'), icon: v('icon'), active: v('active'), portal: v('portal')}; });
                try { await api('categories_save', {categories}); toast('دسته‌ها ذخیره شد.', 'success'); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); }
            };
        },
        tpls(body, ed, root) {
            body.innerHTML = `<p class="text-[12px] text-slate-600 leading-7 mb-3">قالب‌ها متنِ آماده‌ی نامه‌اند. در متن می‌توانید متغیرهایی مثلِ {شرکت}، {گیرنده}، {شماره} و {تاریخ} بگذارید که هنگامِ چاپ پر می‌شوند.</p>
                <div class="flex flex-col gap-2">${this.d.templates.map(t => `<div class="lt-row" style="flex-direction:row;align-items:center"><i class="fas fa-file-lines text-indigo-500"></i><div style="flex:1;min-width:0"><b>${esc(t.title)}</b><div class="text-[11px] text-slate-500 truncate">${esc(t.subject || '')}</div></div>${catTag(+t.category_id || null)}
                    ${ed ? `<button class="lt-btn s sm" data-te="${t.id}"><i class="fas fa-pen"></i>ویرایش</button><button class="lt-btn r sm" data-td="${t.id}"><i class="fas fa-trash"></i></button>` : ''}</div>`).join('') || '<div class="lt-empty">قالبی نیست.</div>'}</div>
                ${ed ? '<button class="lt-btn p mt-3" data-tn><i class="fas fa-plus"></i>قالبِ جدید</button>' : ''}`;
            const editT = async id => {
                let t = {title: '', subject: '', category_id: '', body_html: '<p>با سلام و احترام،</p><p></p>'};
                if (id) try { t = (await api('tpl_get', {id})).template; } catch (e) { toast(e.message, 'error'); return; }
                const tb = toolbar(null);
                const m = modal('<i class="fas fa-file-lines text-indigo-600"></i>' + (id ? 'ویرایشِ قالب' : 'قالبِ جدید'), `<div class="lt-grid lt-g3 mb-3"><label><span class="lt-lbl">نامِ قالب</span><input class="lt-in" data-k="title" value="${esc(t.title)}"></label>
                    <label><span class="lt-lbl">موضوعِ پیش‌فرض</span><input class="lt-in" data-k="subject" value="${esc(t.subject || '')}"></label><label><span class="lt-lbl">دسته</span><select class="lt-sel" data-k="category_id"><option value="">—</option>${this.d.categories.map(c => `<option value="${c.id}" ${+t.category_id === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label></div>
                    ${tb.html}<div class="lt-page" style="position:static;width:100%;min-height:260px;box-shadow:none;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin-top:8px;font-size:12pt;line-height:1.9"><div class="body" contenteditable="true" data-body>${t.body_html || ''}</div></div>`,
                    '<button class="lt-btn p" data-ok><i class="fas fa-floppy-disk"></i>ذخیره</button>', {mid: true});
                const area = m.querySelector('[data-body]');
                setupArea(area); toolbar(area).bind(m.querySelector('.lt-tb'));
                m.querySelector('[data-ok]').onclick = async () => {
                    try { await api('tpl_save', {id: id || 0, title: m.querySelector('[data-k=title]').value, subject: m.querySelector('[data-k=subject]').value, category_id: m.querySelector('[data-k=category_id]').value, body_html: area.innerHTML}); m.close(); toast('قالب ذخیره شد.', 'success'); await boot(true); this.render(root); }
                    catch (e) { toast(e.message, 'error'); }
                };
            };
            body.querySelectorAll('[data-te]').forEach(b => b.onclick = () => editT(+b.dataset.te));
            const tn = body.querySelector('[data-tn]'); if (tn) tn.onclick = () => editT(0);
            body.querySelectorAll('[data-td]').forEach(b => b.onclick = async () => { if (!(await confirmUi('حذفِ قالب', 'این قالب حذف شود؟', {danger: true}))) return; try { await api('tpl_delete', {id: b.dataset.td}); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); } });
        },
        signers(body, ed, root) {
            const row = g => `<div class="lt-row" data-sg="${g.id || ''}"><div class="flex flex-wrap gap-3 items-end">
                <div style="width:120px;height:56px;border:1px dashed #cbd5e1;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#fff">${g.has_image ? `<img src="${url('signer_img', {id: g.id, v: Date.now()})}" style="max-height:52px;max-width:116px">` : '<small class="text-slate-400">بدونِ تصویر</small>'}</div>
                <label style="flex:1 1 160px"><span class="lt-lbl">نام</span><input class="lt-in" data-k="name" value="${esc(g.name || '')}"></label>
                <label style="flex:1 1 160px"><span class="lt-lbl">سمت</span><input class="lt-in" data-k="title" value="${esc(g.title || '')}"></label>
                <label style="flex:1 1 160px"><span class="lt-lbl">کاربرِ پنل (نامه‌های امضایش را می‌بیند)</span><select class="lt-sel" data-k="user_id"><option value="">—</option>${this.d.staff.map(u => `<option value="${u.id}" ${g.user_id === u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
                <label class="lt-chk pb-2"><input type="checkbox" data-k="active" ${g.active === 0 ? '' : 'checked'}>فعال</label>
                ${ed && g.id ? `<label class="lt-btn s sm mb-1" style="cursor:pointer"><i class="fas fa-upload"></i>تصویرِ امضا/مهر<input type="file" accept="image/png,image/jpeg" hidden data-img></label>${g.has_image ? '<button class="lt-btn r sm mb-1" data-noimg><i class="fas fa-image"></i>حذفِ تصویر</button>' : ''}` : ''}
                ${ed ? '<button class="lt-btn r sm mb-1" data-rm><i class="fas fa-trash"></i></button>' : ''}</div></div>`;
            body.innerHTML = `<p class="text-[12px] text-slate-600 leading-7 mb-3">امضاکننده‌ها در پایینِ نامه با نام و سمت می‌آیند؛ تصویرِ امضا/مهر (PNG با زمینه‌ی شفاف بهتر است) فقط بعد از صدورِ نامه چاپ می‌شود.</p>
                <div class="flex flex-col gap-2" data-list>${this.d.signers.map(row).join('')}</div>${ed ? '<div class="flex gap-2 mt-3"><button class="lt-btn s" data-add><i class="fas fa-plus"></i>امضاکننده‌ی جدید</button><button class="lt-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button></div>' : ''}`;
            if (!ed) return;
            const bind = () => body.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => b.closest('[data-sg]').remove());
            bind();
            body.querySelector('[data-add]').onclick = () => { body.querySelector('[data-list]').insertAdjacentHTML('beforeend', row({active: 1})); bind(); };
            body.querySelector('[data-save]').onclick = async () => {
                const signers = [...body.querySelectorAll('[data-sg]')].map(c => { const v = k => { const x = c.querySelector(`[data-k=${k}]`); return x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; }; return {id: c.dataset.sg || 0, name: v('name'), title: v('title'), user_id: v('user_id'), active: v('active')}; });
                try { await api('signers_save', {signers}); toast('امضاها ذخیره شد.', 'success'); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); }
            };
            body.querySelectorAll('[data-img]').forEach(i => i.onchange = async () => { const fd = new FormData(); fd.append('id', i.closest('[data-sg]').dataset.sg); fd.append('file', i.files[0]); try { await api('signer_image', {}, fd); toast('تصویر ذخیره شد.', 'success'); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); } });
            body.querySelectorAll('[data-noimg]').forEach(b => b.onclick = async () => { try { await api('signer_image', {id: b.closest('[data-sg]').dataset.sg, remove: 1}); await boot(true); this.render(root); } catch (e) { toast(e.message, 'error'); } });
        },
        general(body, ed, root) {
            const s = this.d.settings;
            body.innerHTML = `<div class="flex flex-col gap-3" style="max-width:640px">
                <label class="lt-chk"><input type="checkbox" data-p="portal_enabled" ${+s.portal_enabled ? 'checked' : ''}>بخشِ «اتوماسیون نامه‌ها» در پنلِ شرکت‌ها فعال باشد (دیدنِ نامه‌ها و پاسخ)</label>
                <label class="lt-chk"><input type="checkbox" data-p="notify_bot" ${+s.notify_bot ? 'checked' : ''}>با ارسالِ نامه به کارتابلِ شرکت، در ربات بله به کاربرانِ همان شرکت اعلان برود</label>
                <div class="lt-grid lt-g2"><label><span class="lt-lbl">مهلتِ پیش‌فرضِ پاسخ (روز)</span><input class="lt-in" data-p="reply_days" value="${fa(s.reply_days)}"></label>
                <label><span class="lt-lbl">امضاکننده‌ی پیش‌فرضِ نامه‌ی جدید</span><select class="lt-sel" data-p="default_signer"><option value="0">—</option>${this.d.signers.filter(g => g.active).map(g => `<option value="${g.id}" ${+s.default_signer === g.id ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select></label></div>
                <div class="lt-row text-[11.5px] leading-7 text-slate-600"><b class="text-slate-800"><i class="fas fa-shield-halved text-indigo-600"></i> دسترسی‌ها</b>از «کاربران پنل ← دسترسی‌ها» برای هر نفر تعیین کنید: «کارتابل و دفترِ نامه‌ها» (دیدن، نوشتن/صدور، ویرایش، حذف، اکسل)، «دیدنِ نامه‌های محرمانه و سرّی» و «تنظیماتِ نامه‌ها». هر کس نامه‌ی محرمانه‌ای را بنویسد، امضا کند یا به او ارجاع شود، آن نامه را می‌بیند.</div>
                ${ed ? '<div><button class="lt-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button></div>' : ''}</div>`;
            const val = k => { const x = body.querySelector(`[data-p="${k}"]`); return x.type === 'checkbox' ? (x.checked ? 1 : 0) : en(x.value); };
            const sv = body.querySelector('[data-save]'); if (sv) sv.onclick = () => this.saveSettings(Object.fromEntries(['portal_enabled', 'notify_bot', 'reply_days', 'default_signer'].map(k => [k, val(k)])), root);
        },
    };

    window.Letters = {open, compose: o => Compose.open(o || {}), view: id => View.open(id)};
})();
