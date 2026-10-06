// فایل: life.js
// بخشِ «بیمه عمر»: داشبورد (وصول، معوق، فروش، نمودارها)، بیمه‌نامه‌ها و اقساط (فیلتر، کارت/جدول، جزئیات با پرداخت،
// رسید، تماس و یادداشت، فایل‌ها)، ورودِ اکسلِ بیمه‌گر با پیش‌نمایش و حلِ مغایرت، بایگانیِ عمر و تنظیمات (ستون‌ها و قالبِ رسید).
// دسترسی‌ها را خودِ این فایل اعمال می‌کند (data-perm-skip) چون عملیاتِ هر صفحه به چند دسترسیِ جدا بسته است.
(function () {
    'use strict';
    const API = 'api/life_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => fa((Number(v) || 0).toLocaleString('en-US'));
    const short = v => { const n = Number(v) || 0, a = Math.abs(n); return a >= 1e9 ? fa((n / 1e9).toFixed(a >= 1e10 ? 0 : 1)) + ' میلیارد' : a >= 1e6 ? fa((n / 1e6).toFixed(a >= 1e8 ? 0 : 1)) + ' میلیون' : a >= 1e3 ? fa(Math.round(n / 1e3)) + ' هزار' : fa(n); };
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const mLabel = m => { const p = String(m || '').split('/'); return p.length > 1 ? MONTHS[(+p[1] || 1) - 1] + ' ' + fa(p[0].slice(2)) : fa(m); };
    const todayJ = () => window.IrTime ? IrTime.jdate(IrTime.now()) : (L.boot ? L.boot.today_j : '');
    const nowHM = () => { if (window.IrTime) return IrTime.hm(IrTime.now()); const d = new Date(); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
    const isPhone = () => window.matchMedia && matchMedia('(max-width: 767px)').matches;
    const ST = {PAID: ['پرداخت‌شده', 'ok', 'fa-circle-check'], PARTIAL: ['بخشی پرداخت‌شده', 'part', 'fa-circle-half-stroke'], OVERDUE: ['معوق', 'bad', 'fa-triangle-exclamation'], DUE: ['سررسید نشده', 'due', 'fa-clock']};
    const C1 = '#2a78d6', C2 = '#eb6834';   // سری‌های نمودار: سررسید (آبی)، وصول (نارنجی)

    const L = {boot: null, booting: null, tab: null, list: {f: {sort: 'overdue'}, page: 1, sel: new Set(), data: null}, dash: {f: {}}, imp: null, arch: {path: '', q: ''}, det: null};
    const can = (page, op = 'view') => !!(L.boot && (L.boot.perms[page] || []).includes(op));

    async function api(action, body = {}, form = null) {
        let res;
        try {
            if (form) { form.append('action', action); res = await fetch(API, {method: 'POST', body: form, credentials: 'same-origin'}); }
            else res = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(Object.assign({action}, body))});
        } catch (e) { throw new Error('اتصال به سرور برقرار نشد.'); }
        const j = await res.json().catch(() => ({ok: false, error: 'پاسخِ نامعتبر از سرور (' + res.status + ')'}));
        if (!j.ok) throw new Error(j.error || 'خطا');
        return j;
    }
    const qs = o => Object.entries(o).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false).map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(Array.isArray(v) ? v.join(',') : v)).join('&');
    const url = (action, o = {}) => API + '?' + qs(Object.assign({action}, o));
    function download(href) { const a = document.createElement('a'); a.href = href; a.rel = 'noopener'; document.body.appendChild(a); a.click(); setTimeout(() => a.remove(), 500); }
    async function copy(t) {
        t = en(t);
        try { await navigator.clipboard.writeText(t); }
        catch (e) { const x = document.createElement('textarea'); x.value = t; document.body.appendChild(x); x.select(); try { document.execCommand('copy'); } catch (e2) {} x.remove(); }
        toast('کپی شد: ' + fa(t), 'success');
    }
    const confirmUi = async (t, m, o) => (window.uiConfirm ? uiConfirm(t, m, o || {}) : confirm(m));
    const busy = (btn, on) => { if (!btn) return; btn.disabled = on; if (on) { btn.dataset.h = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> لطفاً صبر کنید…'; } else if (btn.dataset.h) btn.innerHTML = btn.dataset.h; };
    const jd = j => fa(j || '') || '—';
    // نامِ فایل در متنِ راست‌به‌چپ: پسوندِ لاتین جدا (وگرنه «.jpg» به ابتدای نام می‌پرد)
    const fname = n => { const m = String(n || '').match(/^(.*?)(\.[A-Za-z0-9]{1,5})?$/); return `<bdi>${esc(fa(m[1]))}</bdi>${m[2] ? `<span class="lf-ext">${esc(m[2].slice(1).toUpperCase())}</span>` : ''}`; };
    const stChip = s => `<span class="lf-st ${ST[s][1]}"><i class="fas ${ST[s][2]}"></i>${ST[s][0]}</span>`;

    // ------------------------------------------------------------------
    //  CSS
    // ------------------------------------------------------------------
    const STYLE = `
    .lf{direction:rtl;--lf1:#0e7490;--lf2:#2563eb;font-size:12.5px;color:#0f172a}
    .lf *{box-sizing:border-box}
    .lf-hub{display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between;background:linear-gradient(120deg,#0e7490,#2563eb 60%,#4f46e5);border-radius:24px;padding:16px 18px;color:#fff;box-shadow:0 22px 40px -28px #2563eb;position:relative;overflow:hidden}
    .lf-hub::after{content:'';position:absolute;width:260px;height:260px;border-radius:50%;left:-70px;top:-150px;background:rgba(255,255,255,.1);pointer-events:none}
    .lf-hub .t{display:flex;align-items:center;gap:12px;z-index:1}.lf-hub .ic{width:44px;height:44px;border-radius:15px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:19px}
    .lf-hub b{font-size:17px;font-weight:900;display:block}.lf-hub small{opacity:.85;font-size:11px}
    .lf-nav{display:flex;gap:6px;flex-wrap:wrap;z-index:1}.lf-nav button{border:0;font-family:inherit;font-weight:800;font-size:12px;border-radius:14px;padding:8px 13px;background:rgba(255,255,255,.14);color:#fff;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
    .lf-nav button.on{background:#fff;color:#1e40af;box-shadow:0 8px 18px -10px rgba(0,0,0,.4)}
    .lf-card{background:#fff;border:1px solid #e9eef5;border-radius:20px;padding:16px;box-shadow:0 10px 30px -26px rgba(15,23,42,.35)}
    .lf-card h3{font-size:13.5px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px;margin-bottom:10px}.lf-card h3 small{font-weight:600;color:#64748b;font-size:11px}
    .lf-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:13px;padding:8px 13px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;white-space:nowrap;transition:transform .12s,box-shadow .12s;text-decoration:none}
    .lf-btn:active{transform:scale(.97)}.lf-btn:disabled{opacity:.45;cursor:not-allowed}
    .lf-btn.p{background:linear-gradient(120deg,#0e7490,#2563eb);color:#fff;box-shadow:0 10px 20px -14px #2563eb}.lf-btn.s{background:#f1f5f9;color:#334155}.lf-btn.o{background:#fff;color:#334155;border:1px solid #e2e8f0}
    .lf-btn.g{background:#059669;color:#fff}.lf-btn.r{background:#fff1f2;color:#be123c}.lf-btn.a{background:#fffbeb;color:#b45309}.lf-btn.sm{padding:5px 9px;font-size:11px;border-radius:10px}
    .lf-in,.lf-sel,.lf-ta{border:1px solid #dbe3ee;border-radius:12px;padding:8px 10px;font-size:12.5px;font-family:inherit;background:#fff;color:#0f172a;width:100%;min-width:0}
    .lf-in:focus,.lf-sel:focus,.lf-ta:focus{outline:2px solid #bfdbfe;border-color:#60a5fa}
    .lf-lbl{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px}
    .lf-grid{display:grid;gap:12px}.lf-g2{grid-template-columns:repeat(2,minmax(0,1fr))}.lf-g3{grid-template-columns:repeat(3,minmax(0,1fr))}.lf-g4{grid-template-columns:repeat(4,minmax(0,1fr))}
    .lf-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(178px,1fr));gap:10px}
    .lf-kpi{background:#fff;border:1px solid #e9eef5;border-radius:18px;padding:13px 14px;position:relative;overflow:hidden;cursor:default}
    .lf-kpi[data-go]{cursor:pointer}.lf-kpi[data-go]:hover{border-color:#93c5fd;box-shadow:0 10px 24px -18px #2563eb}
    .lf-kpi .k{font-size:11px;font-weight:800;color:#64748b;display:flex;align-items:center;gap:6px}.lf-kpi .v{font-size:19px;font-weight:900;margin-top:6px;color:#0f172a}
    .lf-kpi .s{font-size:10.5px;color:#64748b;margin-top:2px}.lf-kpi .ic{width:28px;height:28px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:12px}
    .lf-kpi .bar{height:6px;border-radius:99px;background:#eef2f7;margin-top:8px;overflow:hidden}.lf-kpi .bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#16a34a,#22c55e)}
    .lf-f{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}.lf-f>label{display:flex;flex-direction:column;gap:3px;min-width:120px;flex:1 1 130px;max-width:220px}
    .lf-f>label span{font-size:10.5px;font-weight:800;color:#64748b}
    .lf-chk{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#334155;cursor:pointer;user-select:none}
    .lf-chk input{width:16px;height:16px;accent-color:#2563eb}
    .lf-st{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:900;border-radius:999px;padding:2px 9px;white-space:nowrap}
    .lf-st.ok{background:#dcfce7;color:#166534}.lf-st.part{background:#fef3c7;color:#92400e}.lf-st.bad{background:#fee2e2;color:#991b1b}.lf-st.due{background:#eef2ff;color:#3730a3}
    .lf-tag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;border-radius:9px;padding:2px 8px;background:#f1f5f9;color:#475569;white-space:nowrap}
    .lf-tag.b{background:#e0f2fe;color:#075985}.lf-tag.r{background:#ffe4e6;color:#9f1239}.lf-tag.a{background:#fef3c7;color:#92400e}.lf-tag.g{background:#dcfce7;color:#166534}.lf-tag.v{background:#ede9fe;color:#5b21b6}
    .lf-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
    .lf-tbl th{background:#f1f7fd;color:#1e3a8a;font-weight:900;font-size:10.5px;padding:9px 8px;text-align:right;white-space:nowrap;position:sticky;top:0;z-index:1}
    .lf-tbl td{padding:9px 8px;border-top:1px solid #f1f5f9;vertical-align:middle}
    .lf-tbl tbody tr{cursor:pointer}.lf-tbl tbody tr:hover td{background:#f8fbff}.lf-tbl tr.sel td{background:#eff6ff}
    .lf-scroll{overflow:auto;border-radius:16px;border:1px solid #eef2f7;max-height:68vh}
    .lf-cards{display:none;flex-direction:column;gap:10px}
    .lf-pc{background:#fff;border:1px solid #e6edf5;border-radius:18px;padding:12px 13px;box-shadow:0 8px 20px -20px rgba(15,23,42,.5)}
    .lf-pc .h{display:flex;align-items:flex-start;gap:8px;justify-content:space-between}.lf-pc .n{font-weight:900;font-size:13.5px}.lf-pc .m{font-size:11px;color:#64748b;margin-top:2px}
    .lf-pc .row{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}.lf-pc .amt{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:10px}
    .lf-pc .amt div{background:#f8fafc;border-radius:12px;padding:6px 8px}.lf-pc .amt small{display:block;font-size:10px;color:#64748b;font-weight:700}.lf-pc .amt b{font-size:12px}
    .lf-mono{direction:ltr;unicode-bidi:isolate;font-variant-numeric:tabular-nums}
    .lf-ph{display:inline-flex;align-items:center;gap:4px;background:#f0f9ff;color:#075985;border-radius:10px;padding:3px 8px;font-weight:800;font-size:11.5px;border:0;cursor:pointer;font-family:inherit}
    .lf-ph:hover{background:#e0f2fe}.lf-ph a{color:inherit}
    .lf-pager{display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:wrap;margin-top:12px}
    .lf-empty{text-align:center;color:#94a3b8;padding:38px 10px;font-weight:700}.lf-empty i{font-size:30px;display:block;margin-bottom:8px;color:#cbd5e1}
    .lf-sum{display:flex;flex-wrap:wrap;gap:6px}
    .lf-ov{position:fixed;inset:0;z-index:9500;background:rgba(15,23,42,.5);backdrop-filter:blur(3px);display:flex;justify-content:flex-start;direction:rtl;animation:lfFade .18s}
    .lf-ov.c{align-items:flex-start;justify-content:center;padding:26px 12px;overflow:auto}
    .lf-dr{background:#f8fafc;width:min(980px,100%);height:100%;overflow:auto;box-shadow:0 0 60px -10px rgba(0,0,0,.45);animation:lfIn .22s ease-out;display:flex;flex-direction:column}
    .lf-md{background:#fff;border-radius:24px;width:100%;max-width:640px;box-shadow:0 30px 60px -25px rgba(0,0,0,.5);animation:lfPop .18s ease-out}
    .lf-md.w{max-width:900px}
    .lf-mh{display:flex;align-items:center;gap:10px;padding:15px 18px;border-bottom:1px solid #f1f5f9}.lf-mh h3{font-size:14.5px;font-weight:900}
    .lf-mb{padding:16px 18px}.lf-mf{display:flex;gap:8px;justify-content:flex-end;padding:12px 18px;border-top:1px solid #f1f5f9;flex-wrap:wrap}
    .lf-x{margin-right:auto;width:36px;height:36px;border-radius:12px;background:#f1f5f9;border:0;cursor:pointer;flex-shrink:0;font-size:14px;color:#334155}
    @keyframes lfFade{from{opacity:0}to{opacity:1}}@keyframes lfIn{from{transform:translateX(40px);opacity:.4}to{transform:none;opacity:1}}@keyframes lfPop{from{transform:translateY(10px) scale(.98);opacity:0}to{transform:none;opacity:1}}
    .lf-dh{background:linear-gradient(120deg,#0e7490,#2563eb);color:#fff;padding:16px 18px 12px;position:sticky;top:0;z-index:3}
    .lf-dh .x{background:rgba(255,255,255,.18);color:#fff}
    .lf-dh h2{font-size:17px;font-weight:900}.lf-dh .sub{font-size:11.5px;opacity:.9;margin-top:3px;display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center}
    .lf-dh .cp{background:rgba(255,255,255,.16);border:0;color:#fff;border-radius:9px;padding:2px 8px;font-family:inherit;font-weight:800;cursor:pointer;font-size:11px}
    .lf-dt{display:flex;gap:4px;margin-top:12px;overflow:auto}.lf-dt button{border:0;background:rgba(255,255,255,.14);color:#fff;font-family:inherit;font-weight:800;font-size:12px;border-radius:12px 12px 0 0;padding:8px 14px;cursor:pointer;white-space:nowrap}
    .lf-dt button.on{background:#f8fafc;color:#1e3a8a}
    .lf-db{padding:14px 16px 90px;display:flex;flex-direction:column;gap:12px}
    .lf-ms{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.lf-ms div{background:#fff;border:1px solid #e9eef5;border-radius:14px;padding:9px 10px}
    .lf-ms small{display:block;font-size:10.5px;color:#64748b;font-weight:800}.lf-ms b{font-size:14px}
    .lf-inst{background:#fff;border:1px solid #e6edf5;border-radius:18px;overflow:hidden}
    .lf-inst.bad{border-color:#fecaca}.lf-inst.ok{border-color:#bbf7d0}
    .lf-ih{display:flex;align-items:center;gap:10px;padding:11px 13px;flex-wrap:wrap}
    .lf-in-no{width:40px;height:40px;border-radius:14px;background:#eff6ff;color:#1d4ed8;font-weight:900;display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1;flex-shrink:0}
    .lf-in-no small{font-size:8.5px;font-weight:800;opacity:.7;margin-top:2px}
    .lf-inst.bad .lf-in-no{background:#fef2f2;color:#b91c1c}.lf-inst.ok .lf-in-no{background:#f0fdf4;color:#15803d}
    .lf-ia{display:flex;gap:6px;flex-wrap:wrap;margin-right:auto}
    .lf-iv{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;flex:1 1 380px}.lf-iv div small{display:block;font-size:10px;color:#64748b;font-weight:700}.lf-iv div b{font-size:12.5px}
    .lf-ib{border-top:1px dashed #e2e8f0;padding:10px 13px;background:#fbfdff;display:flex;flex-direction:column;gap:8px}
    .lf-pay{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:7px 10px;font-size:11.5px}
    .lf-pay.v{opacity:.55;text-decoration:line-through}
    .lf-file{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:4px 8px;font-size:11px;font-weight:700;color:#334155;max-width:100%}
    .lf-ext{display:inline-block;margin-right:5px;font-size:9px;font-weight:900;letter-spacing:.3px;background:#e2e8f0;color:#475569;border-radius:5px;padding:0 4px;vertical-align:1px;direction:ltr}
    .lf-file a{color:#1d4ed8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;display:inline-block}
    .lf-tl{display:flex;flex-direction:column;gap:8px}
    .lf-note{background:#fff;border:1px solid #e9eef5;border-radius:16px;padding:10px 12px;position:relative}
    .lf-note.sys{background:#f8fafc;border-style:dashed;color:#64748b;font-size:11.5px;padding:7px 12px}
    .lf-note .h{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:11px;color:#64748b;font-weight:700}
    .lf-note .tx{margin-top:5px;white-space:pre-wrap;line-height:1.9;font-size:12.5px;color:#1e293b}
    .lf-kv{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px}
    .lf-kv div{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:8px 10px}.lf-kv small{display:block;font-size:10.5px;color:#64748b;font-weight:800;margin-bottom:2px}.lf-kv b{font-size:12.5px;word-break:break-word}
    .lf-chart{position:relative;width:100%}.lf-chart svg{display:block;width:100%;height:auto;overflow:visible}
    .lf-tip{position:absolute;pointer-events:none;background:#0f172a;color:#fff;border-radius:12px;padding:8px 10px;font-size:11px;line-height:1.8;white-space:nowrap;z-index:5;opacity:0;transition:opacity .12s;box-shadow:0 12px 24px -12px rgba(0,0,0,.6)}
    .lf-tip .sw{display:inline-block;width:9px;height:9px;border-radius:3px;margin-left:5px;vertical-align:-1px}
    .lf-leg{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;font-weight:800;color:#475569;margin-bottom:6px}.lf-leg i{display:inline-block;width:11px;height:11px;border-radius:4px;margin-left:5px;vertical-align:-1px}
    .lf-hb{display:flex;flex-direction:column;gap:8px}.lf-hb .r{display:grid;grid-template-columns:minmax(80px,130px) 1fr auto;gap:8px;align-items:center;font-size:11.5px}
    .lf-hb .t{height:12px;border-radius:6px;background:#f1f5f9;overflow:hidden}.lf-hb .t i{display:block;height:100%;border-radius:0 6px 6px 0}
    .lf-hb .r span:first-child{font-weight:800;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.lf-hb .r b{font-size:11px;color:#334155;white-space:nowrap}
    .lf-stack{display:flex;height:16px;border-radius:9px;overflow:hidden;gap:2px;background:#fff}.lf-stack i{display:block;height:100%}
    .lf-li{display:flex;align-items:center;gap:10px;padding:9px 6px;border-top:1px solid #f1f5f9;cursor:pointer;border-radius:10px}.lf-li:hover{background:#f8fbff}.lf-li:first-child{border-top:0}
    .lf-li .av{width:34px;height:34px;border-radius:12px;background:#eff6ff;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-weight:900;flex-shrink:0}
    .lf-drop{border:2px dashed #93c5fd;background:#f8fbff;border-radius:20px;padding:24px;text-align:center;cursor:pointer;transition:background .15s}
    .lf-drop:hover,.lf-drop.on{background:#eff6ff}.lf-drop i{font-size:30px;color:#2563eb}
    .lf-ipol{background:#fff;border:1px solid #e6edf5;border-radius:18px;overflow:hidden}
    .lf-ipol.off{opacity:.6}.lf-ipol.cf{border-color:#fdba74;box-shadow:0 0 0 3px #fff7ed}
    .lf-ipol .h{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;background:#fbfdff;border-bottom:1px solid #f1f5f9}
    .lf-irow{display:grid;grid-template-columns:56px 96px 1fr 1fr 120px minmax(160px,1.3fr);gap:8px;align-items:center;padding:8px 12px;border-top:1px solid #f6f8fb;font-size:11.5px}
    .lf-irow.hd{background:#f8fafc;font-weight:900;color:#475569;font-size:10.5px;border-top:0}
    .lf-irow.cf{background:#fff7ed}
    .lf-diff{font-size:10.5px;color:#9a3412;margin-top:3px;line-height:1.8}.lf-diff s{color:#94a3b8}
    .lf-bulk{position:sticky;bottom:10px;z-index:6;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;background:#0f172a;color:#fff;border-radius:18px;padding:10px 14px;box-shadow:0 18px 30px -18px #0f172a}
    .lf-crumb{display:flex;flex-wrap:wrap;gap:4px;align-items:center;font-size:12px;font-weight:800}.lf-crumb button{border:0;background:#f1f5f9;border-radius:10px;padding:5px 10px;font-family:inherit;font-weight:800;cursor:pointer;color:#334155}
    .lf-fi{display:flex;align-items:center;gap:10px;padding:10px;border-radius:14px;border:1px solid #eef2f7;background:#fff;cursor:pointer}.lf-fi:hover{border-color:#bfdbfe;background:#f8fbff}
    .lf-fi .ic{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
    .lf-chip-in{display:flex;flex-wrap:wrap;gap:5px;align-items:center}
    .lf-code{font-family:inherit;direction:rtl;background:#f1f5f9;border-radius:8px;padding:2px 7px;font-size:11px;font-weight:800;cursor:pointer;border:0;color:#1e293b}
    .lf-seg{display:inline-flex;background:#f1f5f9;border-radius:12px;padding:3px;gap:2px}.lf-seg button{border:0;background:transparent;font-family:inherit;font-weight:800;font-size:11.5px;padding:6px 11px;border-radius:9px;cursor:pointer;color:#475569}
    .lf-seg button.on{background:#fff;color:#1d4ed8;box-shadow:0 2px 6px -2px rgba(15,23,42,.25)}
    @media (max-width:1023px){.lf-g4{grid-template-columns:repeat(2,minmax(0,1fr))}.lf-irow{grid-template-columns:44px 84px 1fr 1fr;}.lf-irow .c5,.lf-irow .c6{grid-column:span 2}}
    @media (max-width:767px){
        .lf-hub{border-radius:20px;padding:13px 14px}.lf-hub b{font-size:15px}.lf-nav{width:100%;overflow:auto;flex-wrap:nowrap;padding-bottom:2px}.lf-nav button{flex-shrink:0}
        .lf-g2,.lf-g3,.lf-g4{grid-template-columns:minmax(0,1fr)}.lf-tblwrap{display:none}.lf-cards{display:flex}
        .lf-f>label{max-width:none;flex:1 1 45%}.lf-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.lf-kpi .v{font-size:16px}
        .lf-dr{width:100%}.lf-ms{grid-template-columns:repeat(2,1fr)}.lf-iv{grid-template-columns:repeat(2,minmax(0,1fr));flex-basis:100%}
        .lf-ia{margin-right:0;width:100%}.lf-ia .lf-btn{flex:1 1 auto}
        .lf-ov.c{padding:0;align-items:flex-end}.lf-md{border-radius:22px 22px 0 0;max-height:92vh;overflow:auto}
        .lf-irow{grid-template-columns:40px 1fr 1fr}.lf-irow.hd{display:none}.lf-irow .c3,.lf-irow .c4{grid-column:span 1}.lf-irow .c5,.lf-irow .c6{grid-column:1/-1}
        .lf-card{padding:13px;border-radius:18px}.lf-bulk{border-radius:16px}
    }`;
    function css() { if (!document.getElementById('lf-css')) { const s = document.createElement('style'); s.id = 'lf-css'; s.textContent = STYLE; document.head.appendChild(s); } }

    // ------------------------------------------------------------------
    //  ورود و پوسته
    // ------------------------------------------------------------------
    const TABS = [['life-dash', 'fa-chart-pie', 'داشبورد'], ['life-policies', 'fa-file-medical', 'بیمه‌نامه‌ها و اقساط'], ['life-import', 'fa-file-import', 'ورود از اکسل'],
                  ['life-archive', 'fa-box-archive', 'بایگانی'], ['life-settings', 'fa-sliders', 'تنظیمات']];
    async function boot() {
        if (L.boot) return L.boot;
        if (!L.booting) L.booting = api('bootstrap').then(b => (L.boot = b)).finally(() => { L.booting = null; });
        return L.booting;
    }
    function shell(tab, body) {
        const nav = TABS.filter(t => can(t[0])).map(t => `<button type="button" class="${t[0] === tab ? 'on' : ''}" onclick="switchTab('${t[0]}')"><i class="fas ${t[1]}"></i>${t[2]}</button>`).join('');
        return `<div class="lf space-y-4" data-perm-skip><div class="lf-hub"><div class="t"><span class="ic"><i class="fas fa-heart-pulse"></i></span><div><b>بیمه عمر</b><small>اقساط، وصول، پیگیریِ تماس و رسید</small></div></div><div class="lf-nav">${nav}</div></div>${body}</div>`;
    }
    async function open(tab) {
        css();
        const root = document.getElementById(tab + '-root');
        if (!root) return;
        L.tab = tab;
        if (!L.boot) root.innerHTML = shell(tab, '<div class="lf-card lf-empty"><i class="fas fa-spinner fa-spin"></i>در حال بارگذاری…</div>');
        try { await boot(); }
        catch (e) { root.innerHTML = shell(tab, `<div class="lf-card lf-empty"><i class="fas fa-lock"></i>${esc(e.message)}</div>`); return; }
        if (!can(tab)) { root.innerHTML = shell(tab, '<div class="lf-card lf-empty"><i class="fas fa-lock"></i>به این بخش دسترسی ندارید.</div>'); return; }
        if (tab === 'life-dash') return Dash.render(root);
        if (tab === 'life-policies') return Pol.render(root);
        if (tab === 'life-import') return Imp.render(root);
        if (tab === 'life-archive') return Arch.render(root);
        if (tab === 'life-settings') return Sett.render(root);
    }
    const monthOpts = (sel, label) => {
        // ماه‌های ۱۴۰۰ تا سالِ بعد (از تازه به قدیم)
        const y = +(todayJ().slice(0, 4)) || 1405;
        let o = `<option value="">${label}</option>`;
        for (let yy = y + 1; yy >= 1398; yy--) for (let m = 12; m >= 1; m--) { const v = yy + '/' + String(m).padStart(2, '0'); o += `<option value="${v}" ${v === sel ? 'selected' : ''}>${MONTHS[m - 1]} ${fa(yy)}</option>`; }
        return o;
    };

    // ------------------------------------------------------------------
    //  نمودارها (SVG دست‌ساز؛ دسته‌ها از راست به چپ، یک محورِ عمودی، راهنما و راهنمای شناور)
    // ------------------------------------------------------------------
    function niceMax(v) { if (v <= 0) return 1; const p = Math.pow(10, Math.floor(Math.log10(v))); for (const m of [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]) if (m * p >= v) return m * p; return 10 * p; }
    function barChart(box, cats, series, fmt, tipRows) {
        const W = Math.max(300, Math.round(box.clientWidth || 600)), H = 250, Lm = 56, R = 8, T = 14, B = 34;
        const pw = W - Lm - R, ph = H - T - B, n = Math.max(1, cats.length), slot = pw / n;
        const xAt = i => W - R - slot * (i + .5);
        const max = niceMax(Math.max(0, ...series.flatMap(s => s.values)));
        let g = '';
        for (let i = 0; i <= 4; i++) {
            const y = T + ph - ph * i / 4;
            g += `<line x1="${Lm}" x2="${W - R}" y1="${y}" y2="${y}" stroke="${i ? '#eef2f7' : '#cbd5e1'}" ${i ? 'stroke-dasharray="3 4"' : ''}/><text x="${Lm - 6}" y="${y + 3}" text-anchor="end" font-size="9.5" fill="#94a3b8">${fmt(max * i / 4)}</text>`;
        }
        const every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(pw / 56))));
        cats.forEach((c, i) => { if (i % every === 0 || i === n - 1) g += `<text x="${xAt(i)}" y="${H - B + 15}" text-anchor="middle" font-size="9.5" font-weight="700" fill="#64748b">${esc(c)}</text>`; });
        const k = series.length, gap = 2, bw = Math.max(3, Math.min(26, (slot * .72 - gap * (k - 1)) / k));
        let bars = '', hits = '';
        cats.forEach((c, i) => {
            const total = bw * k + gap * (k - 1), x0 = xAt(i) + total / 2;
            series.forEach((s, si) => {
                const v = s.values[i] || 0, h = ph * Math.min(1, v / max);
                if (h < .5) return;
                const x = x0 - (si + 1) * bw - si * gap, y = T + ph - h, r = Math.min(4, bw / 2, h);
                bars += `<path d="M${x},${T + ph}V${y + r}Q${x},${y} ${x + r},${y}H${x + bw - r}Q${x + bw},${y} ${x + bw},${y + r}V${T + ph}Z" fill="${s.color}"/>`;
            });
            hits += `<rect x="${xAt(i) - slot / 2}" y="${T}" width="${slot}" height="${ph}" fill="transparent" data-i="${i}"/>`;
        });
        const leg = series.length > 1 ? `<div class="lf-leg">${series.map(s => `<span><i style="background:${s.color}"></i>${esc(s.name)}</span>`).join('')}</div>` : '';
        box.innerHTML = `${leg}<div class="lf-chart"><svg viewBox="0 0 ${W} ${H}" role="img">${g}${bars}<g class="hz">${hits}</g></svg><div class="lf-tip"></div></div>`;
        const ch = box.querySelector('.lf-chart'), tip = ch.querySelector('.lf-tip'), svg = ch.querySelector('svg');
        let hl = null;
        ch.addEventListener('mousemove', e => {
            const r = e.target.closest && e.target.closest('rect[data-i]');
            if (!r) { tip.style.opacity = 0; if (hl) hl.setAttribute('fill', 'transparent'); return; }
            const i = +r.dataset.i;
            if (hl && hl !== r) hl.setAttribute('fill', 'transparent');
            hl = r; r.setAttribute('fill', 'rgba(37,99,235,.06)');
            tip.innerHTML = `<b>${esc(cats[i])}</b><br>` + tipRows(i);
            const bb = ch.getBoundingClientRect();
            let x = e.clientX - bb.left + 14, y = e.clientY - bb.top - 10;
            tip.style.opacity = 1;
            if (x + tip.offsetWidth > bb.width) x = e.clientX - bb.left - tip.offsetWidth - 14;
            tip.style.left = Math.max(0, x) + 'px'; tip.style.top = Math.max(0, y) + 'px';
        });
        ch.addEventListener('mouseleave', () => { tip.style.opacity = 0; if (hl) hl.setAttribute('fill', 'transparent'); });
        // لمس در موبایل
        svg.addEventListener('touchstart', e => { const t = e.touches[0]; ch.dispatchEvent(new MouseEvent('mousemove', {clientX: t.clientX, clientY: t.clientY, bubbles: true})); const el = document.elementFromPoint(t.clientX, t.clientY); if (el && el.matches('rect[data-i]')) el.dispatchEvent(new MouseEvent('mousemove', {clientX: t.clientX, clientY: t.clientY, bubbles: true})); }, {passive: true});
    }
    function hbars(rows, color, fmtV) {
        const max = Math.max(1, ...rows.map(r => r.v));
        return `<div class="lf-hb">${rows.map(r => `<div class="r" title="${esc(r.tip || '')}"><span>${esc(r.label)}</span><div class="t"><i style="width:${Math.max(2, r.v / max * 100)}%;background:${r.color || color}"></i></div><b>${fmtV(r)}</b></div>`).join('')}</div>`;
    }

    // ------------------------------------------------------------------
    //  داشبورد
    // ------------------------------------------------------------------
    const Dash = {
        async render(root) {
            const f = L.dash.f;
            root.innerHTML = shell('life-dash', `
                <button class="lf-btn s w-full md:hidden" data-a="dfl"><i class="fas fa-filter"></i>فیلترها${Object.values(f).filter(Boolean).length ? ' (' + fa(Object.values(f).filter(Boolean).length) + ')' : ''}</button>
                <div class="lf-card" data-box="dfl"><div class="lf-f">
                    <label><span>صدور از</span><select class="lf-sel" data-k="issue_from">${monthOpts(f.issue_from, 'همه')}</select></label>
                    <label><span>صدور تا</span><select class="lf-sel" data-k="issue_to">${monthOpts(f.issue_to, 'همه')}</select></label>
                    <label><span>سررسید از</span><select class="lf-sel" data-k="due_from">${monthOpts(f.due_from, 'همه')}</select></label>
                    <label><span>سررسید تا</span><select class="lf-sel" data-k="due_to">${monthOpts(f.due_to, 'همه')}</select></label>
                    <label><span>روش پرداخت</span><select class="lf-sel" data-k="pay_method"><option value="">همه</option>${(L.boot.lists.pay_methods || []).map(m => `<option ${m === f.pay_method ? 'selected' : ''}>${esc(m)}</option>`).join('')}</select></label>
                    <label><span>وضعیت بیمه‌نامه</span><select class="lf-sel" data-k="policy_status"><option value="">همه</option>${(L.boot.lists.policy_status || []).map(m => `<option ${m === f.policy_status ? 'selected' : ''}>${esc(m)}</option>`).join('')}</select></label>
                    <button class="lf-btn s" data-a="reset"><i class="fas fa-rotate-left"></i>همه</button>
                </div></div>
                <div id="lfd-body"><div class="lf-card lf-empty"><i class="fas fa-spinner fa-spin"></i>در حال محاسبه…</div></div>`);
            root.querySelectorAll('[data-k]').forEach(s => s.onchange = () => { f[s.dataset.k] = s.value; this.load(root); });
            root.querySelector('[data-a=reset]').onclick = () => { L.dash.f = {}; this.render(root); };
            const fb = root.querySelector('[data-box=dfl]');
            if (isPhone() && !Object.values(f).some(Boolean)) fb.style.display = 'none';
            root.querySelector('[data-a=dfl]').onclick = () => { fb.style.display = fb.style.display === 'none' ? '' : 'none'; };
            this.load(root);
        },
        async load(root) {
            const body = root.querySelector('#lfd-body');
            let d;
            try { d = await api('stats', L.dash.f); } catch (e) { body.innerHTML = `<div class="lf-card lf-empty"><i class="fas fa-triangle-exclamation"></i>${esc(e.message)}</div>`; return; }
            L.dash.d = d;
            const badge = document.getElementById('life-nav-badge');
            if (badge) { badge.textContent = fa(d.followups_total); badge.classList.toggle('hidden', !d.followups_total); }
            const I = d.insts, P = d.policies;
            const kpi = (ic, bg, k, v, s, go, extra = '') => `<div class="lf-kpi" ${go ? `data-go='${esc(JSON.stringify(go))}'` : ''}><div class="k"><span class="ic" style="background:${bg[0]};color:${bg[1]}"><i class="fas ${ic}"></i></span>${k}</div><div class="v">${v}</div><div class="s">${s}</div>${extra}</div>`;
            if (!P.n) {
                body.innerHTML = `<div class="lf-card lf-empty"><i class="fas fa-heart-pulse"></i>هنوز بیمه‌نامه‌ی عمری ثبت نشده است.${can('life-import', 'create') ? '<div class="mt-3"><button class="lf-btn p" onclick="switchTab(\'life-import\')"><i class="fas fa-file-import"></i>ورودِ اکسلِ اقساط</button></div>' : ''}</div>`;
                return;
            }
            body.innerHTML = `
            <div class="lf-kpis">
                ${kpi('fa-file-medical', ['#e0f2fe', '#0369a1'], 'بیمه‌نامه‌ها', fa(P.n), `${fa(P.clean)} بدونِ بدهی`, {})}
                ${kpi('fa-sack-dollar', ['#ede9fe', '#6d28d9'], 'فروش (برآوردِ حق‌بیمه‌ی سالانه)', short(d.sales_total), 'از روی مبلغ و روشِ پرداختِ اقساط')}
                ${kpi('fa-coins', ['#eff6ff', '#1d4ed8'], 'جمعِ اقساطِ ثبت‌شده', short(I.amount), `${fa(I.n)} قسط · ریال`)}
                ${kpi('fa-circle-check', ['#dcfce7', '#15803d'], 'وصول‌شده', short(I.paid), `${fa(String(I.rate))}٪ از کل · ${fa(I.n_paid)} قسطِ کامل`, {status: 'paid'}, `<div class="bar"><i style="width:${Math.min(100, I.rate)}%"></i></div>`)}
                ${kpi('fa-hourglass-half', ['#f1f5f9', '#334155'], 'مانده‌ی کل', short(I.rem), `${fa(I.n_partial)} قسطِ بخشی‌پرداخت`, {status: 'unpaid'})}
                ${kpi('fa-triangle-exclamation', ['#fee2e2', '#b91c1c'], 'معوق (وصول‌نشده)', short(I.over_amount), `${fa(I.n_over)} قسط در ${fa(P.overdue)} بیمه‌نامه`, {status: 'overdue'})}
                ${kpi('fa-calendar-day', ['#fef3c7', '#b45309'], 'سررسیدِ ۳۰ روزِ آینده', short(I.soon_amount), `${fa(I.n_soon)} قسط`, {status: 'unpaid', sort: 'next_due'})}
                ${kpi('fa-phone-volume', ['#ecfeff', '#0e7490'], 'پیگیری‌های امروز', fa(d.followups_total), `${fa(d.calls_month)} تماس در این ماه`, {followup: 1, sort: 'next_due'})}
                ${kpi('fa-user-xmark', ['#fff1f2', '#be123c'], 'قسطِ اولِ پرداخت‌نشده', fa(P.first_unpaid), 'بدونِ هیچ پرداختی', {status: 'first_unpaid'})}
                ${kpi('fa-phone-slash', ['#f8fafc', '#475569'], 'بدونِ شماره‌ی تماس', fa(P.no_phone), 'با اولین تماس ثبت کنید', {no_phone: 1})}
            </div>
            <div class="lf-grid lf-g2">
                <div class="lf-card"><h3><i class="fas fa-chart-column text-blue-600"></i>سررسید و وصول، ماه به ماه <small>(ریال، بر اساسِ ماهِ سررسید)</small></h3><div id="lfd-c1"></div></div>
                <div class="lf-card"><h3><i class="fas fa-chart-simple text-violet-600"></i>صدورِ بیمه‌نامه، ماه به ماه <small>(تعداد)</small></h3><div id="lfd-c2"></div></div>
            </div>
            <div class="lf-grid lf-g3">
                <div class="lf-card"><h3><i class="fas fa-layer-group text-slate-600"></i>وضعیتِ اقساط</h3><div id="lfd-st"></div></div>
                <div class="lf-card"><h3><i class="fas fa-clock-rotate-left text-red-600"></i>سنِ معوقات <small>(روز از سررسید)</small></h3><div id="lfd-ag"></div></div>
                <div class="lf-card"><h3><i class="fas fa-money-bill-transfer text-cyan-700"></i>روشِ پرداختِ حق‌بیمه</h3><div id="lfd-pm"></div></div>
            </div>
            <div class="lf-grid lf-g2">
                <div class="lf-card"><h3><i class="fas fa-ranking-star text-red-600"></i>بیشترین معوقات</h3><div id="lfd-top"></div></div>
                <div class="lf-card"><h3><i class="fas fa-phone text-cyan-700"></i>پیگیری‌های سررسیده <small>(تاریخِ پیگیریِ بعدیِ آخرین تماس رسیده)</small></h3><div id="lfd-fu"></div></div>
            </div>
            ${d.last_import ? `<div class="text-[11px] text-slate-500 font-bold px-2"><i class="fas fa-file-excel text-green-600 ml-1"></i>آخرین ورودِ اکسل: ${esc(d.last_import.file_name)} · ${fa(d.last_import.at_j)}${d.last_import.range_from ? ` · بازه‌ی سررسید ${fa(d.last_import.range_from)} تا ${fa(d.last_import.range_to)}` : ''}</div>` : ''}`;
            body.querySelectorAll('[data-go]').forEach(k => k.onclick = () => { L.list.f = Object.assign({sort: 'overdue'}, JSON.parse(k.dataset.go || '{}')); L.list.page = 1; switchTab('life-policies'); });
            const drawCharts = () => {
                const bd = d.by_due;
                const c1 = body.querySelector('#lfd-c1');
                if (c1) bd.length ? barChart(c1, bd.map(x => mLabel(x.m)), [{name: 'مبلغِ سررسید', color: C1, values: bd.map(x => x.amount)}, {name: 'وصول‌شده', color: C2, values: bd.map(x => x.paid)}], short,
                    i => `<span class="sw" style="background:${C1}"></span>سررسید: ${money(bd[i].amount)}<br><span class="sw" style="background:${C2}"></span>وصول: ${money(bd[i].paid)}<br>معوق: ${money(bd[i].over)} · ${fa(bd[i].n)} قسط`) : (c1.innerHTML = '<div class="lf-empty">داده‌ای نیست</div>');
                const sl = d.sales, c2 = body.querySelector('#lfd-c2');
                if (c2) sl.length ? barChart(c2, sl.map(x => mLabel(x.m)), [{name: 'تعداد', color: C1, values: sl.map(x => x.n)}], v => fa(Math.round(v)),
                    i => `${fa(sl[i].n)} بیمه‌نامه<br>برآوردِ حق‌بیمه‌ی سالانه: ${money(sl[i].premium)}`) : (c2.innerHTML = '<div class="lf-empty">تاریخِ صدور ثبت نشده</div>');
            };
            drawCharts();
            clearTimeout(this._rz); window.removeEventListener('resize', this._onrz || (() => {}));
            this._onrz = () => { clearTimeout(this._rz); this._rz = setTimeout(() => { if (L.tab === 'life-dash' && document.getElementById('lfd-c1')) drawCharts(); }, 200); };
            window.addEventListener('resize', this._onrz);
            // وضعیتِ اقساط: نوارِ ۱۰۰٪ با برچسب
            const sts = [['PAID', I.n_paid, '#16a34a'], ['PARTIAL', I.n_partial, '#d97706'], ['OVERDUE', I.n_over - 0, '#dc2626'], ['DUE', Math.max(0, I.n - I.n_paid - I.n_partial - I.n_over), '#64748b']];
            const tot = Math.max(1, I.n);
            body.querySelector('#lfd-st').innerHTML = `<div class="lf-stack">${sts.filter(s => s[1] > 0).map(s => `<i style="width:${s[1] / tot * 100}%;background:${s[2]}" title="${ST[s[0]][0]}: ${fa(s[1])}"></i>`).join('')}</div>
                <div class="lf-hb mt-3">${sts.map(s => `<div class="r"><span><i class="fas ${ST[s[0]][2]} ml-1" style="color:${s[2]}"></i>${ST[s[0]][0]}</span><div class="t"><i style="width:${s[1] / tot * 100}%;background:${s[2]}"></i></div><b>${fa(s[1])} · ${fa(Math.round(s[1] / tot * 100))}٪</b></div>`).join('')}</div>
                <p class="text-[10.5px] text-slate-500 mt-2">«بخشی پرداخت‌شده»ها اگر سررسیدشان گذشته باشد در «معوق» هم شمرده می‌شوند.</p>`;
            const agC = ['#fdc9b0', '#f39a6f', '#e0612f', '#a3401a'];
            body.querySelector('#lfd-ag').innerHTML = d.aging.some(a => a.n) ? hbars(d.aging.map((a, i) => ({label: a.label, v: a.amount, color: agC[i], n: a.n})), C2, r => `${short(r.v)} · ${fa(r.n)} قسط`) : '<div class="lf-empty"><i class="fas fa-face-smile"></i>معوقی نیست</div>';
            body.querySelector('#lfd-pm').innerHTML = d.methods.length ? hbars(d.methods.map(m => ({label: m.label, v: m.n, p: m.premium})), C1, r => `${fa(r.v)} · ${short(r.p)}`) : '<div class="lf-empty">—</div>';
            const li = r => `<div class="lf-li" data-id="${r.id}"><span class="av">${esc((r.holder_name || '?').trim().slice(0, 1))}</span><div class="flex-1 min-w-0"><b class="block truncate">${esc(r.holder_name || '—')}</b><small class="text-slate-500"><span class="lf-mono">${fa(r.policy_no)}</span> · ${esc(r.pay_method || '')}</small></div>
                <div class="text-left"><b class="text-red-700 block">${money(r.overdue_amount)}</b><small class="text-slate-500">${fa(r.overdue_count)} قسط · ${fa(r.overdue_days)} روز</small></div></div>`;
            body.querySelector('#lfd-top').innerHTML = d.top.length ? d.top.map(li).join('') : '<div class="lf-empty"><i class="fas fa-face-smile"></i>بدهیِ معوقی نیست</div>';
            body.querySelector('#lfd-fu').innerHTML = d.followups.length ? d.followups.map(r => `<div class="lf-li" data-id="${r.id}"><span class="av" style="background:#ecfeff;color:#0e7490"><i class="fas fa-phone"></i></span><div class="flex-1 min-w-0"><b class="block truncate">${esc(r.holder_name)}</b><small class="text-slate-500">آخرین تماس: ${fa(r.last_call || '')} · ${esc(r.last_result || '')}</small></div><span class="lf-tag a">${fa(r.next_follow)}</span></div>`).join('') : '<div class="lf-empty"><i class="fas fa-mug-hot"></i>پیگیریِ سررسیده‌ای نیست</div>';
            body.querySelectorAll('.lf-li[data-id]').forEach(x => x.onclick = () => Det.open(+x.dataset.id));
        },
    };

    // ------------------------------------------------------------------
    //  فهرستِ بیمه‌نامه‌ها
    // ------------------------------------------------------------------
    const Pol = {
        render(root) {
            const f = L.list.f;
            const pm = (L.boot.lists.pay_methods || []).map(m => `<option ${m === f.pay_method ? 'selected' : ''}>${esc(m)}</option>`).join('');
            const ps = (L.boot.lists.policy_status || []).map(m => `<option ${m === f.policy_status ? 'selected' : ''}>${esc(m)}</option>`).join('');
            root.innerHTML = shell('life-policies', `
            <div class="lf-card space-y-3">
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="relative flex-1 min-w-[220px]"><i class="fas fa-magnifying-glass absolute top-1/2 -translate-y-1/2 right-3 text-slate-400"></i><input class="lf-in" style="padding-right:34px" data-k="q" placeholder="جستجو: شماره بیمه‌نامه، نام، کد ملی، موبایل، شناسه واریز…" value="${esc(f.q || '')}"></div>
                    ${can('life-policies', 'export') ? '<button class="lf-btn o" data-a="export"><i class="fas fa-file-excel text-green-600"></i>خروجی اکسل</button>' : ''}
                    ${can('life-policies', 'delete') ? '<button class="lf-btn r" data-a="purge"><i class="fas fa-broom"></i>اعتبارسنجی و پاک‌سازی</button>' : ''}
                    <button class="lf-btn s" data-a="more"><i class="fas fa-filter"></i>فیلترها</button>
                </div>
                <div class="lf-f" data-box="more">
                    <label><span>وضعیت</span><select class="lf-sel" data-k="status"><option value="">همه</option>${[['overdue', 'دارای قسطِ معوق'], ['unpaid', 'دارای مانده (پرداخت‌نشده)'], ['paid', 'بدونِ بدهی'], ['first_unpaid', 'قسطِ اولِ پرداخت‌نشده']].map(([v, t]) => `<option value="${v}" ${f.status === v ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
                    <label><span>تعدادِ اقساطِ معوق</span><select class="lf-sel" data-k="overdue_count"><option value="">مهم نیست</option>${[['0', 'بدونِ معوق'], ['1', 'یک قسط'], ['2', 'دو قسط'], ['2+', 'دو قسط و بیشتر'], ['3+', 'سه قسط و بیشتر']].map(([v, t]) => `<option value="${v}" ${f.overdue_count === v ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
                    <label><span>ماهِ سررسید از</span><select class="lf-sel" data-k="due_from">${monthOpts(f.due_from, 'همه')}</select></label>
                    <label><span>ماهِ سررسید تا</span><select class="lf-sel" data-k="due_to">${monthOpts(f.due_to, 'همه')}</select></label>
                    <label><span>ماهِ صدور از</span><select class="lf-sel" data-k="issue_from">${monthOpts(f.issue_from, 'همه')}</select></label>
                    <label><span>ماهِ صدور تا</span><select class="lf-sel" data-k="issue_to">${monthOpts(f.issue_to, 'همه')}</select></label>
                    <label><span>روش پرداخت</span><select class="lf-sel" data-k="pay_method"><option value="">همه</option>${pm}</select></label>
                    <label><span>وضعیت بیمه‌نامه</span><select class="lf-sel" data-k="policy_status"><option value="">همه</option>${ps}</select></label>
                    <label><span>مرتب‌سازی</span><select class="lf-sel" data-k="sort">${[['overdue', 'بیشترین معوق'], ['rem', 'بیشترین مانده'], ['next_due', 'نزدیک‌ترین سررسید'], ['issue', 'جدیدترین صدور'], ['issue_asc', 'قدیمی‌ترین صدور'], ['name', 'نام'], ['recent', 'آخرین تغییر']].map(([v, t]) => `<option value="${v}" ${f.sort === v ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
                    <label class="lf-chk" style="flex:0 0 auto;max-width:none;flex-direction:row"><input type="checkbox" data-k="followup" ${f.followup ? 'checked' : ''}>پیگیریِ سررسیده</label>
                    <label class="lf-chk" style="flex:0 0 auto;max-width:none;flex-direction:row"><input type="checkbox" data-k="no_phone" ${f.no_phone ? 'checked' : ''}>بدونِ شماره</label>
                    <button class="lf-btn s" data-a="reset"><i class="fas fa-rotate-left"></i>حذفِ فیلترها</button>
                </div>
                <div class="lf-sum" data-box="sum"></div>
            </div>
            <div class="lf-card" style="padding:10px"><div data-box="list"><div class="lf-empty"><i class="fas fa-spinner fa-spin"></i></div></div></div>
            <div data-box="bulk"></div>`);
            const more = root.querySelector('[data-box=more]');
            const nf = Object.keys(f).filter(k => !['q', 'sort'].includes(k) && f[k]).length;
            if (isPhone() && !nf) more.style.display = 'none';
            root.querySelector('[data-a=more]').onclick = () => { more.style.display = more.style.display === 'none' ? '' : 'none'; };
            let t;
            root.querySelectorAll('[data-k]').forEach(el => {
                const ev = el.tagName === 'INPUT' && el.type !== 'checkbox' ? 'input' : 'change';
                el.addEventListener(ev, () => {
                    f[el.dataset.k] = el.type === 'checkbox' ? (el.checked ? 1 : '') : el.value;
                    clearTimeout(t); t = setTimeout(() => { L.list.page = 1; this.load(root); }, ev === 'input' ? 320 : 0);
                });
            });
            root.querySelector('[data-a=reset]').onclick = () => { L.list.f = {sort: 'overdue'}; L.list.page = 1; this.render(root); };
            const ex = root.querySelector('[data-a=export]'); if (ex) ex.onclick = () => Exp.open();
            const pu = root.querySelector('[data-a=purge]'); if (pu) pu.onclick = () => Purge.open(() => this.load(root));
            this.load(root);
        },
        async load(root) {
            root = root || document.getElementById('life-policies-root');
            if (!root) return;
            const box = root.querySelector('[data-box=list]');
            let d;
            try { d = await api('list', Object.assign({}, L.list.f, {page: L.list.page, per: isPhone() ? 20 : 40})); }
            catch (e) { box.innerHTML = `<div class="lf-empty"><i class="fas fa-triangle-exclamation"></i>${esc(e.message)}</div>`; return; }
            L.list.data = d;
            const s = d.sum;
            root.querySelector('[data-box=sum]').innerHTML = `<span class="lf-tag b"><i class="fas fa-file-medical"></i>${fa(d.total)} بیمه‌نامه</span><span class="lf-tag">جمع اقساط ${short(s.amount)}</span>
                <span class="lf-tag g">پرداختی ${short(s.paid)}</span><span class="lf-tag a">مانده ${short(s.rem)}</span><span class="lf-tag r">معوق ${short(s.overdue)} · ${fa(s.overdue_count)} قسط</span>`;
            if (!d.rows.length) { box.innerHTML = '<div class="lf-empty"><i class="fas fa-folder-open"></i>بیمه‌نامه‌ای با این فیلترها پیدا نشد.</div>'; this.bulk(root); return; }
            const sel = L.list.sel;
            const phones = r => r.holder_mobile ? `<button class="lf-ph" data-copy="${esc(r.holder_mobile)}" title="کپی"><i class="fas fa-copy text-[10px]"></i><span class="lf-mono">${fa(r.holder_mobile)}</span></button>` : '<span class="lf-tag r"><i class="fas fa-phone-slash"></i>ندارد</span>';
            const od = r => r.overdue_count ? `<b class="text-red-700">${money(r.overdue_amount)}</b><small class="block text-red-500">${fa(r.overdue_count)} قسط · ${fa(r.overdue_days)} روز</small>` : '<span class="lf-tag g"><i class="fas fa-check"></i>ندارد</span>';
            box.innerHTML = `<div class="lf-tblwrap lf-scroll"><table class="lf-tbl"><thead><tr><th style="width:34px"><input type="checkbox" data-all class="lf-cb"></th><th>بیمه‌گذار</th><th>شماره بیمه‌نامه</th><th>موبایل</th><th>صدور</th><th>روش پرداخت</th><th>اقساط</th><th>پرداختی / مانده</th><th>معوق</th><th>آخرین تماس</th><th>پیگیری</th></tr></thead><tbody>
                ${d.rows.map(r => `<tr data-id="${r.id}" class="${sel.has(r.id) ? 'sel' : ''}"><td data-stop><input type="checkbox" data-sel="${r.id}" ${sel.has(r.id) ? 'checked' : ''}></td>
                    <td><b>${esc(r.holder_name || '—')}</b><small class="block text-slate-500 lf-mono">${fa(r.holder_nid || '')}</small></td>
                    <td><span class="lf-mono font-bold">${fa(r.policy_no)}</span>${r.first_unpaid ? '<small class="block"><span class="lf-tag r">قسطِ اول پرداخت نشده</span></small>' : ''}</td>
                    <td data-stop>${phones(r)}</td><td>${jd(r.issue_j)}</td><td>${esc(r.pay_method || '')}</td>
                    <td><span class="lf-tag">${fa(r.inst_count)} قسط</span>${r.next_due ? `<small class="block text-slate-500 mt-1">بعدی: ${fa(r.next_due)}</small>` : ''}</td>
                    <td><span class="text-green-700 font-bold">${money(r.total_paid)}</span><small class="block text-slate-500">مانده ${money(r.total_rem)}</small></td>
                    <td>${od(r)}</td><td>${r.last_call ? `${fa(r.last_call)}<small class="block text-slate-500">${esc(r.last_result || '')}</small>` : '<span class="text-slate-300">—</span>'}</td>
                    <td>${r.next_follow ? `<span class="lf-tag a">${fa(r.next_follow)}</span>` : ''}</td></tr>`).join('')}
            </tbody></table></div>
            <div class="lf-cards">${d.rows.map(r => `<div class="lf-pc" data-id="${r.id}"><div class="h"><div class="min-w-0"><div class="n truncate">${esc(r.holder_name || '—')}</div><div class="m"><span class="lf-mono">${fa(r.policy_no)}</span> · ${jd(r.issue_j)}</div></div>
                    <label data-stop class="lf-chk"><input type="checkbox" data-sel="${r.id}" ${sel.has(r.id) ? 'checked' : ''}></label></div>
                <div class="row" data-stop>${phones(r)}<span class="lf-tag">${esc(r.pay_method || '')}</span><span class="lf-tag">${fa(r.inst_count)} قسط</span>${r.first_unpaid ? '<span class="lf-tag r">قسطِ اول پرداخت نشده</span>' : ''}${r.next_follow ? `<span class="lf-tag a"><i class="fas fa-phone"></i>${fa(r.next_follow)}</span>` : ''}</div>
                <div class="amt"><div><small>پرداختی</small><b class="text-green-700">${short(r.total_paid)}</b></div><div><small>مانده</small><b>${short(r.total_rem)}</b></div><div><small>معوق</small><b class="${r.overdue_count ? 'text-red-700' : 'text-green-700'}">${r.overdue_count ? short(r.overdue_amount) + ' · ' + fa(r.overdue_count) + ' قسط' : 'ندارد'}</b></div></div></div>`).join('')}</div>
            ${this.pager(d)}`;
            box.querySelectorAll('[data-copy]').forEach(b => b.onclick = e => { e.stopPropagation(); copy(b.dataset.copy); });
            box.querySelectorAll('[data-stop]').forEach(x => x.addEventListener('click', e => e.stopPropagation()));
            box.querySelectorAll('tr[data-id], .lf-pc[data-id]').forEach(x => x.onclick = () => Det.open(+x.dataset.id));
            box.querySelectorAll('[data-sel]').forEach(c => c.onchange = () => { const id = +c.dataset.sel; c.checked ? sel.add(id) : sel.delete(id); box.querySelectorAll(`[data-sel="${id}"]`).forEach(o => { o.checked = c.checked; const tr = o.closest('tr'); if (tr) tr.classList.toggle('sel', c.checked); }); this.bulk(root); });
            const all = box.querySelector('[data-all]');
            if (all) all.onchange = () => { d.rows.forEach(r => all.checked ? sel.add(r.id) : sel.delete(r.id)); this.load(root); };
            box.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { L.list.page = +b.dataset.pg; this.load(root); root.scrollIntoView({behavior: 'smooth', block: 'start'}); });
            this.bulk(root);
        },
        pager(d) {
            const pages = Math.ceil(d.total / d.per);
            if (pages <= 1) return `<div class="text-center text-[11px] text-slate-500 mt-2">${fa(d.total)} ردیف</div>`;
            const p = d.page, b = [];
            for (let i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - p) <= 2) b.push(i); else if (b[b.length - 1] !== '…') b.push('…');
            return `<div class="lf-pager">${p > 1 ? `<button class="lf-btn s sm" data-pg="${p - 1}"><i class="fas fa-chevron-right"></i></button>` : ''}${b.map(i => i === '…' ? '<span class="text-slate-400">…</span>' : `<button class="lf-btn sm ${i === p ? 'p' : 's'}" data-pg="${i}">${fa(i)}</button>`).join('')}${p < pages ? `<button class="lf-btn s sm" data-pg="${p + 1}"><i class="fas fa-chevron-left"></i></button>` : ''}<span class="text-[11px] text-slate-500 mr-2">${fa(d.total)} ردیف</span></div>`;
        },
        bulk(root) {
            const n = L.list.sel.size, box = root.querySelector('[data-box=bulk]');
            if (!box) return;
            if (!n) { box.innerHTML = ''; return; }
            box.innerHTML = `<div class="lf-bulk"><span class="font-black"><i class="fas fa-check-double ml-1"></i>${fa(n)} بیمه‌نامه انتخاب شده</span><div class="flex gap-2 flex-wrap">
                ${can('life-policies', 'export') ? '<button class="lf-btn p sm" data-a="bx"><i class="fas fa-file-excel"></i>خروجیِ انتخاب‌شده‌ها</button>' : ''}
                <button class="lf-btn s sm" data-a="bc"><i class="fas fa-xmark"></i>لغوِ انتخاب</button></div></div>`;
            const bx = box.querySelector('[data-a=bx]'); if (bx) bx.onclick = () => Exp.open([...L.list.sel]);
            box.querySelector('[data-a=bc]').onclick = () => { L.list.sel.clear(); this.load(root); };
        },
    };

    // ------------------------------------------------------------------
    //  پنجره‌ها
    // ------------------------------------------------------------------
    function modal(title, body, foot, opts = {}) {
        const ov = document.createElement('div');
        ov.className = 'lf lf-ov c'; ov.setAttribute('data-perm-skip', ''); ov.setAttribute('data-cf-noinv', '');
        ov.innerHTML = `<div class="lf-md ${opts.wide ? 'w' : ''}"><div class="lf-mh">${opts.icon ? `<span class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:#eff6ff;color:#1d4ed8"><i class="fas ${opts.icon}"></i></span>` : ''}<h3>${title}</h3><button class="lf-x" data-x><i class="fas fa-xmark"></i></button></div><div class="lf-mb">${body}</div>${foot ? `<div class="lf-mf">${foot}</div>` : ''}</div>`;
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); if (opts.onClose) opts.onClose(); };
        const onKey = e => { if (e.key === 'Escape' && document.body.lastElementChild === ov) close(); };
        ov.addEventListener('mousedown', e => { if (e.target === ov) ov._down = true; });
        ov.addEventListener('click', e => { if (e.target === ov && ov._down) close(); ov._down = false; });
        ov.querySelector('[data-x]').onclick = close;
        document.addEventListener('keydown', onKey);
        document.body.appendChild(ov);
        ov.close = close;
        if (window.MoneyInput) MoneyInput.apply(ov);
        return ov;
    }

    // ------------------------------------------------------------------
    //  جزئیاتِ بیمه‌نامه
    // ------------------------------------------------------------------
    const Det = {
        async open(id, tab) {
            css();
            await boot();
            let ov = document.getElementById('lf-det');
            if (!ov) {
                ov = document.createElement('div');
                ov.id = 'lf-det'; ov.className = 'lf lf-ov'; ov.setAttribute('data-perm-skip', '');
                ov.innerHTML = '<div class="lf-dr"><div class="lf-empty" style="margin:auto"><i class="fas fa-spinner fa-spin"></i>در حال بارگذاری…</div></div>';
                ov.addEventListener('click', e => { if (e.target === ov) this.close(); });
                document.body.appendChild(ov);
                this._key = e => { if (e.key === 'Escape' && document.body.lastElementChild === ov) this.close(); };
                document.addEventListener('keydown', this._key);
                if (isPhone()) document.documentElement.style.overflow = 'hidden';
            }
            L.det = {id, tab: tab || (L.det && L.det.id === id ? L.det.tab : 'inst'), open: new Set(L.det && L.det.id === id ? L.det.open : [])};
            await this.load();
        },
        close() {
            const ov = document.getElementById('lf-det');
            if (ov) ov.remove();
            document.removeEventListener('keydown', this._key);
            document.documentElement.style.overflow = '';
            if (this.dirty) { this.dirty = false; const r = document.getElementById('life-policies-root'); if (r && L.tab === 'life-policies') Pol.load(r); const dr = document.getElementById('life-dash-root'); if (dr && L.tab === 'life-dash') Dash.load(dr); }
        },
        async load() {
            const ov = document.getElementById('lf-det');
            if (!ov) return;
            try { L.det.d = await api('detail', {id: L.det.id}); }
            catch (e) { ov.querySelector('.lf-dr').innerHTML = `<div class="lf-empty" style="margin:auto"><i class="fas fa-triangle-exclamation"></i>${esc(e.message)}<div class="mt-3"><button class="lf-btn s" onclick="Life.closeDetail()">بستن</button></div></div>`; return; }
            this.draw();
        },
        draw() {
            const ov = document.getElementById('lf-det'), d = L.det.d, p = d.policy, s = d.sum;
            const sc = ov.querySelector('.lf-db') ? ov.querySelector('.lf-dr').scrollTop : 0;
            const tabs = [['inst', 'fa-list-ol', `اقساط (${fa(d.insts.length)})`]];
            if (can('life-calls')) tabs.push(['notes', 'fa-phone', `تماس و یادداشت (${fa(d.notes.filter(n => n.kind !== 'sys').length)})`]);
            tabs.push(['info', 'fa-id-card', 'اطلاعات کامل']);
            if (!tabs.some(t => t[0] === L.det.tab)) L.det.tab = 'inst';
            const phoneBtns = p.phones_list.map(x => `<span class="inline-flex gap-1"><button class="cp" data-copy="${esc(x.phone)}" title="کپی ${esc(x.label || '')}"><i class="fas fa-copy ml-1"></i><span class="lf-mono">${fa(x.phone)}</span>${x.label ? ` <small>(${esc(x.label)})</small>` : ''}</button><a class="cp" href="tel:${esc(x.phone)}" title="تماس"><i class="fas fa-phone"></i></a></span>`).join('');
            const canPh = can('life-calls', 'create') || can('life-policies', 'edit');
            ov.querySelector('.lf-dr').innerHTML = `
            <div class="lf-dh"><div class="flex items-start gap-3"><div class="min-w-0 flex-1">
                <h2 class="truncate">${esc(p.holder_name || '—')}</h2>
                <div class="sub"><button class="cp" data-copy="${esc(p.policy_no)}"><i class="fas fa-copy ml-1"></i>بیمه‌نامه <span class="lf-mono">${fa(p.policy_no)}</span></button>
                    ${p.holder_nid ? `<button class="cp" data-copy="${esc(p.holder_nid)}"><i class="fas fa-copy ml-1"></i>کد ملی <span class="lf-mono">${fa(p.holder_nid)}</span></button>` : ''}
                    ${p.pay_id ? `<button class="cp" data-copy="${esc(p.pay_id)}"><i class="fas fa-copy ml-1"></i>شناسه واریز <span class="lf-mono">${fa(p.pay_id)}</span></button>` : ''}
                    <span>${esc(p.pay_method || '')} · صدور ${jd(p.issue_j)}</span></div>
                <div class="sub mt-2">${phoneBtns || '<span class="cp" style="background:rgba(244,63,94,.35)"><i class="fas fa-phone-slash ml-1"></i>شماره‌ی تماس ثبت نشده</span>'}${canPh ? '<button class="cp" data-a="phones"><i class="fas fa-pen ml-1"></i>شماره‌ها</button>' : ''}</div>
            </div><button class="lf-x x" data-a="close"><i class="fas fa-xmark"></i></button></div>
            <div class="lf-dt">${tabs.map(t => `<button data-tab="${t[0]}" class="${t[0] === L.det.tab ? 'on' : ''}"><i class="fas ${t[1]} ml-1"></i>${t[2]}</button>`).join('')}</div></div>
            <div class="lf-db">
                <div class="lf-ms"><div><small>جمعِ اقساط</small><b>${money(s.amount)}</b></div><div><small>پرداختی</small><b class="text-green-700">${money(s.paid)}</b></div><div><small>مانده</small><b>${money(s.rem)}</b></div><div><small>معوق</small><b class="${s.overdue ? 'text-red-700' : 'text-green-700'}">${s.overdue ? money(s.overdue) + ' · ' + fa(s.overdue_count) + ' قسط' : 'ندارد'}</b></div></div>
                ${p.first_unpaid ? '<div class="lf-card" style="background:#fff1f2;border-color:#fecdd3;padding:10px 12px;color:#9f1239;font-weight:800;font-size:12px"><i class="fas fa-circle-exclamation ml-1"></i>قسطِ اول پرداخت نشده و هیچ پرداختی ثبت نشده است (نامزدِ «اعتبارسنجی و پاک‌سازی»).</div>' : ''}
                <div data-box="tab"></div>
            </div>`;
            ov.querySelectorAll('[data-copy]').forEach(b => b.onclick = () => copy(b.dataset.copy));
            ov.querySelector('[data-a=close]').onclick = () => this.close();
            const ph = ov.querySelector('[data-a=phones]'); if (ph) ph.onclick = () => this.phones();
            ov.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => { L.det.tab = b.dataset.tab; this.draw(); });
            const box = ov.querySelector('[data-box=tab]');
            if (L.det.tab === 'inst') this.drawInsts(box);
            else if (L.det.tab === 'notes') this.drawNotes(box);
            else this.drawInfo(box);
            ov.querySelector('.lf-dr').scrollTop = sc;
        },
        drawInsts(box) {
            const d = L.det.d;
            const add = can('life-pay', 'create') ? `<button class="lf-btn o sm" data-a="iadd"><i class="fas fa-plus"></i>افزودنِ قسط</button>` : '';
            box.innerHTML = `<div class="flex items-center justify-between gap-2 mb-2"><b class="text-[13px]">اقساط</b><div class="flex gap-2">${add}</div></div>
            <div class="flex flex-col gap-2">${d.insts.map(i => {
                const cls = i.status === 'PAID' ? 'ok' : (i.status === 'OVERDUE' || (i.status === 'PARTIAL' && i.days > 0)) ? 'bad' : '';
                const openB = L.det.open.has(i.id);
                const pays = i.payments.length, files = i.files.filter(f => f.name !== 'اطلاعات قسط.txt').length;
                return `<div class="lf-inst ${cls}" data-iid="${i.id}"><div class="lf-ih">
                    <span class="lf-in-no">${fa(i.inst_no)}<small>قسط</small></span>
                    <div class="lf-iv"><div><small>سررسید</small><b>${jd(i.due_j)}</b>${i.days > 0 && i.rem > 0 ? `<small class="text-red-600">${fa(i.days)} روز گذشته</small>` : ''}</div><div><small>مبلغ</small><b>${money(i.amount)}</b></div>
                        <div><small>پرداختی</small><b class="text-green-700">${money(i.eff_paid)}</b>${i.cleared ? '<small class="text-green-700">تسویه نزدِ بیمه‌گر</small>' : i.excel_collected && i.excel_collected >= i.paid_amount ? '<small class="text-slate-500">طبقِ اکسل</small>' : ''}</div><div><small>مانده</small><b>${money(i.rem)}</b></div></div>
                    <div class="lf-ia">${stChip(i.status)}
                        ${can('life-pay', 'create') && i.rem > 0 ? `<button class="lf-btn g sm" data-a="pay"><i class="fas fa-money-bill-wave"></i>ثبتِ پرداخت</button>` : ''}
                        ${can('life-pay') ? `<button class="lf-btn o sm" data-a="rcpt"><i class="fas fa-receipt text-blue-600"></i>رسید</button>` : ''}
                        <button class="lf-btn s sm" data-a="tog"><i class="fas fa-chevron-${openB ? 'up' : 'down'}"></i>${fa(pays)} پرداخت · ${fa(files)} فایل</button></div></div>
                    ${openB ? this.instBody(i) : ''}</div>`;
            }).join('') || '<div class="lf-empty">قسطی ثبت نشده است.</div>'}</div>`;
            const ia = box.querySelector('[data-a=iadd]'); if (ia) ia.onclick = () => this.instForm(null);
            box.querySelectorAll('.lf-inst').forEach(el => {
                const i = d.insts.find(x => x.id === +el.dataset.iid);
                el.querySelectorAll('[data-a]').forEach(b => b.onclick = e => {
                    e.stopPropagation();
                    const a = b.dataset.a;
                    if (a === 'tog') { L.det.open.has(i.id) ? L.det.open.delete(i.id) : L.det.open.add(i.id); this.drawInsts(box); }
                    else if (a === 'pay') Pay.open(i);
                    else if (a === 'rcpt') Rcpt.open(i);
                    else if (a === 'iedit') this.instForm(i);
                    else if (a === 'clear') this.clearToggle(i);
                    else if (a === 'idel') this.instDelete(i);
                    else if (a === 'void') this.voidPay(+b.dataset.pid);
                    else if (a === 'prcpt') Rcpt.open(i, +b.dataset.pid);
                    else if (a === 'fup') this.fileUpload(i);
                    else if (a === 'fdel') this.fileDelete(i, b.dataset.name);
                    else if (a === 'inote') { L.det.tab = 'notes'; L.det.noteInst = i.id; this.draw(); }
                });
            });
        },
        instBody(i) {
            const furl = (name, inline) => url('inst_file', {id: i.id, name, inline: inline ? 1 : ''});
            const pays = i.payments.map(p => `<div class="lf-pay ${p.voided ? 'v' : ''}"><i class="fas fa-money-bill-wave text-green-600"></i><b>${money(p.amount)}</b><span>${fa(p.paid_j)}</span><span class="lf-tag">${esc(p.method || '')}</span>
                ${p.ref_no ? `<span class="lf-tag b">پیگیری <span class="lf-mono">${fa(p.ref_no)}</span></span>` : ''}${p.note ? `<span class="text-slate-500">${esc(p.note)}</span>` : ''}<small class="text-slate-400">ثبت: ${esc(p.by)} · ${fa(p.at)}</small>
                ${p.voided ? '<span class="lf-tag r">باطل‌شده</span>' : `<span class="mr-auto flex gap-1">${can('life-pay') ? `<button class="lf-btn o sm" data-a="prcpt" data-pid="${p.id}"><i class="fas fa-receipt"></i></button>` : ''}${can('life-pay', 'delete') ? `<button class="lf-btn r sm" data-a="void" data-pid="${p.id}" title="ابطال"><i class="fas fa-ban"></i></button>` : ''}</span>`}</div>`).join('');
            const files = i.files.map(f => `<span class="lf-file"><i class="fas ${/\.(jpe?g|png|webp|gif|heic)$/i.test(f.name) ? 'fa-image text-violet-500' : /\.pdf$/i.test(f.name) ? 'fa-file-pdf text-red-500' : /\.docx?$/i.test(f.name) ? 'fa-file-word text-blue-600' : 'fa-file-lines text-slate-500'}"></i>
                <a href="${furl(f.name, 1)}" target="_blank" rel="noopener" title="${esc(f.name)}">${fname(f.name)}</a><a href="${furl(f.name)}" title="دانلود" style="max-width:none"><i class="fas fa-download"></i></a>${can('life-pay', 'delete') && f.name !== 'اطلاعات قسط.txt' ? `<button class="text-red-500" data-a="fdel" data-name="${esc(f.name)}" title="حذف" style="border:0;background:none;cursor:pointer"><i class="fas fa-trash"></i></button>` : ''}</span>`).join('');
            return `<div class="lf-ib">
                <div><b class="text-[11.5px]">پرداخت‌ها</b><div class="flex flex-col gap-1 mt-1">${pays || '<span class="text-[11.5px] text-slate-400">پرداختی ثبت نشده' + (i.excel_collected ? ' (وصول طبقِ اکسلِ بیمه‌گر: ' + money(i.excel_collected) + ')' : '') + '</span>'}</div></div>
                <div><b class="text-[11.5px]">فایل‌های قسط</b> <small class="text-slate-400">(فیش‌ها، رسیدها و هر مدرکِ دیگر در بایگانیِ همین قسط)</small><div class="flex flex-wrap gap-1 mt-1">${files || '<span class="text-[11.5px] text-slate-400">فایلی نیست</span>'}</div></div>
                ${i.cleared_note ? `<div class="text-[11px] text-green-700"><i class="fas fa-circle-check ml-1"></i>${esc(fa(i.cleared_note))}</div>` : ''}${i.note ? `<div class="text-[11.5px] text-slate-600"><i class="fas fa-note-sticky ml-1"></i>${esc(i.note)}</div>` : ''}
                <div class="flex flex-wrap gap-1">${can('life-pay', 'create') ? '<button class="lf-btn o sm" data-a="fup"><i class="fas fa-paperclip"></i>افزودنِ فایل</button>' : ''}
                    ${can('life-calls', 'create') ? '<button class="lf-btn o sm" data-a="inote"><i class="fas fa-phone"></i>تماس / یادداشتِ این قسط</button>' : ''}
                    ${can('life-pay', 'edit') ? `<button class="lf-btn o sm" data-a="iedit"><i class="fas fa-pen"></i>ویرایش</button><button class="lf-btn ${i.cleared ? 'a' : 's'} sm" data-a="clear"><i class="fas fa-flag-checkered"></i>${i.cleared ? 'لغوِ تسویه' : 'تسویه‌شده نزدِ بیمه‌گر'}</button>` : ''}
                    ${can('life-policies', 'delete') ? '<button class="lf-btn r sm" data-a="idel"><i class="fas fa-trash"></i>حذفِ قسط</button>' : ''}
                    ${i.internal_no ? `<span class="lf-tag">شماره داخلی <span class="lf-mono">${fa(i.internal_no)}</span></span>` : ''}</div></div>`;
        },
        async refresh() { this.dirty = true; await this.load(); },
        instForm(i) {
            const m = modal(i ? `ویرایشِ قسطِ ${fa(i.inst_no)}` : 'افزودنِ قسط', `<div class="lf-grid lf-g2">
                ${i ? '' : `<label><span class="lf-lbl">شماره قسط</span><input class="lf-in" data-f="inst_no" inputmode="numeric" value="${fa((Math.max(0, ...L.det.d.insts.map(x => x.inst_no)) + 1))}"></label>`}
                <label><span class="lf-lbl">مبلغ قسط (ریال)</span><input class="lf-in money-input" data-f="amount" inputmode="numeric" value="${i ? money(i.amount) : ''}"></label>
                <label><span class="lf-lbl">سررسید</span><input class="lf-in" data-f="due_j" data-jdate dir="ltr" placeholder="۱۴۰۵/۰۷/۱۴" value="${i ? fa(i.due_j) : ''}"></label>
                <label class="col-span-full" style="grid-column:1/-1"><span class="lf-lbl">یادداشت</span><textarea class="lf-ta" rows="2" data-f="note">${esc(i ? i.note || '' : '')}</textarea></label></div>`,
                '<button class="lf-btn s" data-x2>انصراف</button><button class="lf-btn p" data-ok><i class="fas fa-floppy-disk"></i>ذخیره</button>', {icon: 'fa-list-ol'});
            m.querySelector('[data-x2]').onclick = () => m.close();
            m.querySelector('[data-ok]').onclick = async e => {
                const v = k => (m.querySelector(`[data-f=${k}]`) || {}).value || '';
                busy(e.currentTarget, true);
                try { await api(i ? 'inst_save' : 'inst_add', i ? {id: i.id, amount: en(v('amount')), due_j: en(v('due_j')), note: v('note')} : {policy_id: L.det.id, inst_no: en(v('inst_no')), amount: en(v('amount')), due_j: en(v('due_j')), note: v('note')}); m.close(); toast('ذخیره شد.', 'success'); this.refresh(); }
                catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
        async clearToggle(i) {
            let note = '';
            if (!i.cleared) { note = window.uiPrompt ? await uiPrompt('تسویه نزدِ بیمه‌گر', 'تسویه‌ی دستی', {hint: 'این قسط پرداخت‌شده حساب می‌شود (مثلاً مستقیم به بیمه‌گر پرداخت شده). توضیح:'}) : 'تسویه‌ی دستی'; if (note === null) return; }
            try { await api('inst_save', {id: i.id, cleared: i.cleared ? 0 : 1, cleared_note: note}); this.refresh(); } catch (e) { toast(e.message, 'error'); }
        },
        async instDelete(i) {
            if (!(await confirmUi('حذفِ قسط', `قسطِ ${fa(i.inst_no)} و پوشه‌ی بایگانی‌اش حذف شود؟`, {danger: true, ok: 'حذف'}))) return;
            try { await api('inst_delete', {id: i.id}); this.refresh(); } catch (e) { toast(e.message, 'error'); }
        },
        async voidPay(pid) {
            const r = window.uiPrompt ? await uiPrompt('ابطالِ پرداخت', '', {hint: 'دلیلِ ابطال (اختیاری):', ok: 'ابطال', danger: true}) : '';
            if (r === null) return;
            try { await api('pay_void', {id: pid, reason: r}); toast('پرداخت باطل شد.', 'success'); this.refresh(); } catch (e) { toast(e.message, 'error'); }
        },
        fileUpload(i) {
            const m = modal(`افزودنِ فایل به قسطِ ${fa(i.inst_no)}`, `<label class="block mb-3"><span class="lf-lbl">عنوان (اختیاری)</span><input class="lf-in" data-f="title" placeholder="مثلاً: فیش واریز، نامه، تصویرِ چک"></label>
                <label class="lf-drop block"><i class="fas fa-cloud-arrow-up"></i><div class="font-black mt-2">انتخابِ فایل یا عکس</div><small class="text-slate-500">عکس، PDF، Word، Excel یا زیپ تا ۲۰ مگابایت</small><input type="file" data-f="file" class="hidden" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt"><div data-fn class="mt-2 text-[12px] font-bold text-blue-700"></div></label>`,
                '<button class="lf-btn s" data-x2>انصراف</button><button class="lf-btn p" data-ok><i class="fas fa-upload"></i>بارگذاری</button>', {icon: 'fa-paperclip'});
            const inp = m.querySelector('[data-f=file]');
            inp.onchange = () => { m.querySelector('[data-fn]').textContent = inp.files[0] ? inp.files[0].name : ''; };
            m.querySelector('[data-x2]').onclick = () => m.close();
            m.querySelector('[data-ok]').onclick = async e => {
                if (!inp.files[0]) { toast('فایلی انتخاب نشده است.', 'warning'); return; }
                const fd = new FormData(); fd.append('id', i.id); fd.append('title', m.querySelector('[data-f=title]').value); fd.append('file', inp.files[0]);
                busy(e.currentTarget, true);
                try { await api('inst_file_upload', {}, fd); m.close(); L.det.open.add(i.id); toast('فایل در بایگانیِ قسط ذخیره شد.', 'success'); this.refresh(); }
                catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
        async fileDelete(i, name) {
            if (!(await confirmUi('حذفِ فایل', `«${name}» از بایگانیِ قسط حذف شود؟`, {danger: true, ok: 'حذف'}))) return;
            try { await api('inst_file_delete', {id: i.id, name}); this.refresh(); } catch (e) { toast(e.message, 'error'); }
        },
        phones() {
            const p = L.det.d.policy;
            const others = p.phones_list.filter(x => x.phone !== p.holder_mobile);
            const row = (x = {phone: '', label: ''}) => `<div class="flex gap-2 mb-2" data-row><input class="lf-in lf-mono" style="flex:1.3" data-p placeholder="۰۹۱۲۱۲۳۴۵۶۷" inputmode="tel" value="${esc(fa(x.phone))}"><input class="lf-in" style="flex:1" data-l placeholder="برچسب (همسر، محلِ کار…)" value="${esc(x.label)}"><button class="lf-btn r sm" data-rm><i class="fas fa-xmark"></i></button></div>`;
            const m = modal('شماره‌های تماس', `<label class="block mb-3"><span class="lf-lbl">موبایلِ بیمه‌گذار</span><input class="lf-in lf-mono" data-main inputmode="tel" placeholder="۰۹۱۲۱۲۳۴۵۶۷" value="${esc(fa(p.holder_mobile || ''))}"></label>
                <span class="lf-lbl">شماره‌های دیگر</span><div data-list>${others.map(row).join('')}</div><button class="lf-btn o sm" data-add><i class="fas fa-plus"></i>شماره‌ی دیگر</button>`,
                '<button class="lf-btn s" data-x2>انصراف</button><button class="lf-btn p" data-ok><i class="fas fa-floppy-disk"></i>ذخیره</button>', {icon: 'fa-address-book'});
            const bind = () => m.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => b.closest('[data-row]').remove());
            bind();
            m.querySelector('[data-add]').onclick = () => { m.querySelector('[data-list]').insertAdjacentHTML('beforeend', row()); bind(); };
            m.querySelector('[data-x2]').onclick = () => m.close();
            m.querySelector('[data-ok]').onclick = async e => {
                const phones = [...m.querySelectorAll('[data-row]')].map(r => ({phone: en(r.querySelector('[data-p]').value), label: r.querySelector('[data-l]').value})).filter(x => x.phone.trim());
                busy(e.currentTarget, true);
                try { await api('phones_save', {id: p.id, holder_mobile: en(m.querySelector('[data-main]').value), phones}); m.close(); toast('شماره‌ها ذخیره شد.', 'success'); this.refresh(); }
                catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
        drawNotes(box) {
            const d = L.det.d, p = d.policy;
            const instSel = L.det.noteInst || '';
            const phOpts = p.phones_list.map(x => `<option value="${esc(x.phone)}">${fa(x.phone)}${x.label ? ' — ' + esc(x.label) : ''}</option>`).join('');
            const form = can('life-calls', 'create') ? `<div class="lf-card"><div class="flex items-center gap-2 mb-3"><div class="lf-seg" data-kind><button class="on" data-v="call"><i class="fas fa-phone ml-1"></i>تماس</button><button data-v="note"><i class="fas fa-note-sticky ml-1"></i>یادداشت</button></div></div>
                <div class="lf-grid lf-g4">
                    <label><span class="lf-lbl">تاریخ</span><input class="lf-in" data-f="date_j" data-jdate dir="ltr" value="${fa(todayJ())}"></label>
                    <label><span class="lf-lbl">ساعت</span><input class="lf-in lf-mono" data-f="time" value="${fa(nowHM())}" placeholder="۱۴:۳۰"></label>
                    <label data-call><span class="lf-lbl">شماره</span>${phOpts ? `<select class="lf-sel" data-f="phone_sel">${phOpts}<option value="__new">شماره‌ی تازه…</option></select>` : ''}<input class="lf-in lf-mono mt-1 ${phOpts ? 'hidden' : ''}" data-f="phone" inputmode="tel" placeholder="۰۹۱۲۱۲۳۴۵۶۷"></label>
                    <label data-call><span class="lf-lbl">نتیجه</span><select class="lf-sel" data-f="result"><option value="">—</option>${L.boot.call_results.map(r => `<option>${esc(r)}</option>`).join('')}</select></label>
                    <label style="grid-column:1/-1"><span class="lf-lbl">شرح</span><textarea class="lf-ta" rows="3" data-f="text" placeholder="مثلاً: تماس گرفتم، گفت تا آخرِ هفته واریز می‌کند…"></textarea></label>
                    <label><span class="lf-lbl">مربوط به قسطِ</span><select class="lf-sel" data-f="installment_id"><option value="">کلِ بیمه‌نامه</option>${d.insts.map(i => `<option value="${i.id}" ${+instSel === i.id ? 'selected' : ''}>قسط ${fa(i.inst_no)} · ${fa(i.due_j)}</option>`).join('')}</select></label>
                    <label><span class="lf-lbl">پیگیریِ بعدی</span><input class="lf-in" data-f="next_j" data-jdate dir="ltr" placeholder="۱۴۰۵/۰۷/۲۰"></label>
                    <label class="lf-chk" data-call style="align-self:end;padding-bottom:9px"><input type="checkbox" data-f="save_phone" checked>این شماره در شماره‌های بیمه‌نامه ذخیره شود</label>
                    <div style="align-self:end"><button class="lf-btn p w-full" data-a="save"><i class="fas fa-floppy-disk"></i>ثبت</button></div>
                </div></div>` : '';
            const instNo = id => { const i = d.insts.find(x => x.id === id); return i ? 'قسط ' + fa(i.inst_no) : ''; };
            const notes = d.notes.map(n => n.kind === 'sys'
                ? `<div class="lf-note sys"><i class="fas fa-gear ml-1"></i>${fa(n.at_j)} ${fa(n.at_t)} · ${esc(fa(n.text))}${n.by ? ' · ' + esc(n.by) : ''}</div>`
                : `<div class="lf-note"><div class="h">${n.kind === 'call' ? '<span class="lf-tag b"><i class="fas fa-phone"></i>تماس</span>' : '<span class="lf-tag v"><i class="fas fa-note-sticky"></i>یادداشت</span>'}<span>${fa(n.at_j)} · ${fa(n.at_t)}</span>
                    ${n.phone ? `<button class="lf-ph" data-copy="${esc(n.phone)}"><span class="lf-mono">${fa(n.phone)}</span></button>` : ''}${n.result ? `<span class="lf-tag ${/پرداخت|قول/.test(n.result) ? 'g' : /نداد|خاموش|اشتباه|انصراف/.test(n.result) ? 'r' : ''}">${esc(n.result)}</span>` : ''}
                    ${n.installment_id ? `<span class="lf-tag">${instNo(n.installment_id)}</span>` : ''}${n.next_j ? `<span class="lf-tag ${n.next_due ? 'r' : 'a'}"><i class="fas fa-bell"></i>پیگیری ${fa(n.next_j)}</span>` : ''}
                    <span class="mr-auto">${esc(n.by)}</span>${can('life-calls', 'delete') ? `<button class="text-red-500" data-del="${n.id}" style="border:0;background:none;cursor:pointer" title="حذف"><i class="fas fa-trash"></i></button>` : ''}</div>${n.text ? `<div class="tx">${esc(n.text)}</div>` : ''}</div>`).join('');
            box.innerHTML = `${form}<div class="lf-tl mt-3">${notes || '<div class="lf-empty"><i class="fas fa-comments"></i>هنوز تماسی ثبت نشده است.</div>'}</div>`;
            L.det.noteInst = null;
            box.querySelectorAll('[data-copy]').forEach(b => b.onclick = () => copy(b.dataset.copy));
            box.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (!(await confirmUi('حذفِ یادداشت', 'این مورد حذف شود؟', {danger: true, ok: 'حذف'}))) return; try { await api('note_delete', {id: +b.dataset.del}); this.refresh(); } catch (e) { toast(e.message, 'error'); } });
            if (!form) return;
            let kind = 'call';
            box.querySelectorAll('[data-kind] button').forEach(b => b.onclick = () => { kind = b.dataset.v; box.querySelectorAll('[data-kind] button').forEach(x => x.classList.toggle('on', x === b)); box.querySelectorAll('[data-call]').forEach(x => x.style.display = kind === 'call' ? '' : 'none'); });
            const ps = box.querySelector('[data-f=phone_sel]'), pi = box.querySelector('[data-f=phone]');
            if (ps) ps.onchange = () => { pi.classList.toggle('hidden', ps.value !== '__new'); if (ps.value === '__new') pi.focus(); };
            box.querySelector('[data-a=save]').onclick = async e => {
                const v = k => { const el = box.querySelector(`[data-f=${k}]`); return el ? (el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value) : ''; };
                const phone = ps && ps.value !== '__new' ? ps.value : en(pi.value);
                busy(e.currentTarget, true);
                try {
                    await api('note_add', {policy_id: L.det.id, kind, date_j: en(v('date_j')), time: en(v('time')), phone: kind === 'call' ? phone : '', result: kind === 'call' ? v('result') : '',
                        text: v('text'), installment_id: v('installment_id'), next_j: en(v('next_j')), save_phone: kind === 'call' && v('save_phone') ? 1 : 0});
                    toast('ثبت شد.', 'success'); this.refresh();
                } catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
        drawInfo(box) {
            const p = L.det.d.policy, ed = can('life-policies', 'edit');
            const F = [['holder_name', 'بیمه‌گذار', 1], ['holder_nid', 'کد ملی بیمه‌گذار', 1], ['insured_name', 'بیمه‌شده‌ی اصلی', 1], ['insured_nid', 'کد ملی بیمه‌شده', 1], ['issue_j', 'تاریخ صدور', 1],
                       ['start_j', 'تاریخ شروع'], ['end_j', 'تاریخ پایان'], ['duration', 'مدت (سال)'], ['policy_year', 'سال بیمه‌ای'], ['pay_method', 'روش پرداخت', 1], ['policy_status', 'وضعیت بیمه‌نامه', 1],
                       ['field_name', 'رشته', 1], ['variant', 'طرح / واریانت', 1], ['contract_name', 'قرارداد'], ['agent', 'نماینده'], ['branch', 'شعبه'], ['channel', 'کانال فروش'], ['pay_id', 'شناسه واریز']];
            const extra = Object.entries(p.extra || {}).filter(([k]) => !F.some(f => f[1] === k));
            box.innerHTML = `<div class="lf-card"><h3><i class="fas fa-id-card text-blue-600"></i>مشخصاتِ بیمه‌نامه ${ed ? '<button class="lf-btn o sm mr-auto" data-a="edit"><i class="fas fa-pen"></i>ویرایش</button>' : ''}</h3>
                <div class="lf-kv">${F.map(([k, l]) => `<div><small>${l}</small><b class="${/nid|pay_id|_j$/.test(k) ? 'lf-mono' : ''}">${esc(fa(p[k] ?? '')) || '—'}</b></div>`).join('')}
                    <div><small>برآوردِ حق‌بیمه‌ی سالانه</small><b>${money(p.annual_est)}</b></div></div>
                ${p.note ? `<div class="mt-3 text-[12px] bg-amber-50 border border-amber-100 rounded-xl p-3 whitespace-pre-wrap"><b class="block text-amber-800 mb-1">یادداشتِ بیمه‌نامه</b>${esc(p.note)}</div>` : ''}</div>
            ${extra.length ? `<div class="lf-card"><h3><i class="fas fa-table-list text-slate-500"></i>همه‌ی ستون‌های اکسل <small>(آخرین ورود)</small></h3><div class="lf-kv">${extra.map(([k, v]) => `<div><small>${esc(k)}</small><b>${esc(fa(v))}</b></div>`).join('')}</div></div>` : ''}
            <div class="lf-card"><h3><i class="fas fa-folder-tree text-amber-600"></i>بایگانی</h3><div class="text-[12px] text-slate-600 lf-mono" style="direction:rtl">${esc(p.archive || 'هنوز ساخته نشده')}</div>
                ${p.archive && can('life-archive') ? '<button class="lf-btn o sm mt-2" data-a="arch"><i class="fas fa-folder-open"></i>بازکردنِ پوشه در بایگانی</button>' : ''}</div>
            ${can('life-policies', 'delete') ? '<div class="text-left"><button class="lf-btn r" data-a="del"><i class="fas fa-trash"></i>حذفِ کاملِ بیمه‌نامه و بایگانی‌اش</button></div>' : ''}`;
            const eb = box.querySelector('[data-a=edit]');
            if (eb) eb.onclick = () => {
                const m = modal('ویرایشِ بیمه‌نامه', `<div class="lf-grid lf-g2">${F.filter(f => f[2]).map(([k, l]) => `<label><span class="lf-lbl">${l}</span><input class="lf-in" data-f="${k}" ${k === 'issue_j' ? 'data-jdate dir="ltr"' : ''} value="${esc(fa(p[k] ?? ''))}"></label>`).join('')}
                    <label style="grid-column:1/-1"><span class="lf-lbl">یادداشتِ بیمه‌نامه</span><textarea class="lf-ta" rows="3" data-f="note">${esc(p.note || '')}</textarea></label></div>`,
                    '<button class="lf-btn s" data-x2>انصراف</button><button class="lf-btn p" data-ok><i class="fas fa-floppy-disk"></i>ذخیره</button>', {icon: 'fa-pen', wide: true});
                m.querySelector('[data-x2]').onclick = () => m.close();
                m.querySelector('[data-ok]').onclick = async e => {
                    const o = {id: p.id};
                    m.querySelectorAll('[data-f]').forEach(el => { o[el.dataset.f] = /nid|_j$/.test(el.dataset.f) ? en(el.value) : el.value; });
                    busy(e.currentTarget, true);
                    try { await api('policy_save', o); m.close(); toast('ذخیره شد.', 'success'); this.refresh(); } catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
                };
            };
            const ab = box.querySelector('[data-a=arch]');
            if (ab) ab.onclick = () => { L.arch.path = String(p.archive).replace(/^Archive\/بایگانی\/بایگانی بیمه عمر\/?/, ''); L.arch.q = ''; this.close(); switchTab('life-archive'); };
            const db = box.querySelector('[data-a=del]');
            if (db) db.onclick = async () => {
                if (!(await confirmUi('حذفِ بیمه‌نامه', `بیمه‌نامه‌ی ${fa(p.policy_no)} (${p.holder_name}) با همه‌ی اقساط، پرداخت‌ها، یادداشت‌ها و پوشه‌ی بایگانی‌اش حذف شود؟ این کار برگشت ندارد.`, {danger: true, ok: 'حذفِ کامل'}))) return;
                try { await api('policy_delete', {id: p.id}); toast('حذف شد.', 'success'); this.dirty = true; this.close(); } catch (e) { toast(e.message, 'error'); }
            };
        },
    };

    // ثبتِ پرداخت
    const Pay = {
        open(i) {
            const m = modal(`ثبتِ پرداختِ قسطِ ${fa(i.inst_no)}`, `
                <div class="lf-ms mb-3" style="grid-template-columns:repeat(3,1fr)"><div><small>مبلغ قسط</small><b>${money(i.amount)}</b></div><div><small>پرداختی</small><b class="text-green-700">${money(i.eff_paid)}</b></div><div><small>مانده</small><b>${money(i.rem)}</b></div></div>
                <div class="lf-grid lf-g2">
                    <label><span class="lf-lbl">مبلغِ پرداخت (ریال)</span><input class="lf-in money-input" data-f="amount" inputmode="numeric" value="${money(i.rem)}"></label>
                    <label><span class="lf-lbl">تاریخِ پرداخت</span><input class="lf-in" data-f="paid_j" data-jdate dir="ltr" value="${fa(todayJ())}"></label>
                    <label><span class="lf-lbl">روشِ پرداخت</span><select class="lf-sel" data-f="method">${L.boot.pay_methods.map(x => `<option>${esc(x)}</option>`).join('')}</select></label>
                    <label><span class="lf-lbl">شماره‌ی پیگیری / مرجع</span><input class="lf-in lf-mono" data-f="ref_no" inputmode="numeric"></label>
                    <label style="grid-column:1/-1"><span class="lf-lbl">توضیح</span><input class="lf-in" data-f="note"></label>
                    <label class="lf-drop block" style="grid-column:1/-1;padding:16px"><i class="fas fa-receipt" style="font-size:22px"></i><div class="font-black mt-1">فیشِ پرداخت (اختیاری)</div><small class="text-slate-500">عکس یا PDF؛ در پوشه‌ی همین قسط بایگانی می‌شود</small><input type="file" class="hidden" data-f="file" accept="image/*,.pdf"><div data-fn class="mt-1 text-[12px] font-bold text-blue-700"></div></label>
                    <label class="lf-chk" style="grid-column:1/-1"><input type="checkbox" data-f="rcpt" ${can('life-pay') ? 'checked' : ''}>بعد از ثبت، رسید را آماده کن</label>
                </div>`, '<button class="lf-btn s" data-x2>انصراف</button><button class="lf-btn g" data-ok><i class="fas fa-check"></i>ثبتِ پرداخت</button>', {icon: 'fa-money-bill-wave'});
            const inp = m.querySelector('[data-f=file]');
            inp.onchange = () => { m.querySelector('[data-fn]').textContent = inp.files[0] ? inp.files[0].name : ''; };
            m.querySelector('[data-x2]').onclick = () => m.close();
            m.querySelector('[data-ok]').onclick = async e => {
                const fd = new FormData();
                fd.append('installment_id', i.id);
                ['amount', 'paid_j', 'ref_no'].forEach(k => fd.append(k, en(m.querySelector(`[data-f=${k}]`).value)));
                ['method', 'note'].forEach(k => fd.append(k, m.querySelector(`[data-f=${k}]`).value));
                if (inp.files[0]) fd.append('file', inp.files[0]);
                busy(e.currentTarget, true);
                try {
                    const r = await api('pay_add', {}, fd);
                    const want = m.querySelector('[data-f=rcpt]').checked;
                    m.close(); toast('پرداخت ثبت شد.', 'success');
                    L.det.open.add(i.id);
                    await Det.refresh();
                    if (want) { const ni = L.det.d.insts.find(x => x.id === i.id) || i; Rcpt.open(ni, r.payment_id); }
                } catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
    };

    // رسید
    const Rcpt = {
        open(i, pid) {
            const tpls = L.boot.templates || [];
            const def = tpls.find(t => t.is_default) || tpls[0];
            if (!def) { toast('قالبی تعریف نشده است.', 'error'); return; }
            const pays = i.payments.filter(p => !p.voided);
            const m = modal(`رسیدِ قسطِ ${fa(i.inst_no)}`, `<div class="lf-grid lf-g2">
                <label><span class="lf-lbl">قالب</span><select class="lf-sel" data-f="template_id">${tpls.map(t => `<option value="${t.id}" ${t === def ? 'selected' : ''}>${esc(t.name)}${t.builtin ? ' (داخلی)' : ' (Word)'}</option>`).join('')}</select></label>
                <label><span class="lf-lbl">پرداخت</span><select class="lf-sel" data-f="payment_id"><option value="">همه‌ی پرداخت‌های این قسط</option>${pays.map(p => `<option value="${p.id}" ${p.id === pid ? 'selected' : ''}>${fa(p.paid_j)} · ${money(p.amount)} · ${esc(p.method)}</option>`).join('')}</select></label>
                <label style="grid-column:1/-1"><span class="lf-lbl">متنِ زیرِ جدول <small class="text-slate-400">(از تنظیماتِ قالب؛ برای همین رسید می‌توانید عوضش کنید)</small></span><textarea class="lf-ta" rows="4" data-f="footer">${esc(def.footer_text || '')}</textarea></label></div>
                <p class="text-[11px] text-slate-500 mt-2"><i class="fas fa-circle-info ml-1"></i>نسخه‌ی Word${L.boot.pdf_ok ? '/PDF' : ''} در پوشه‌ی همین قسط در بایگانی هم ذخیره می‌شود. برای PDF از «نمایش و چاپ» ← «ذخیره‌ی PDF» هم می‌توانید استفاده کنید.</p>`,
                `<button class="lf-btn s" data-x2>بستن</button><button class="lf-btn o" data-k="receipt_docx"><i class="fas fa-file-word text-blue-600"></i>Word</button>${L.boot.pdf_ok ? '<button class="lf-btn o" data-k="receipt_pdf"><i class="fas fa-file-pdf text-red-500"></i>PDF</button>' : ''}<button class="lf-btn p" data-k="receipt_html"><i class="fas fa-print"></i>نمایش و چاپ</button>`, {icon: 'fa-receipt'});
            const ts = m.querySelector('[data-f=template_id]'), ft = m.querySelector('[data-f=footer]');
            ts.onchange = () => { const t = tpls.find(x => x.id === +ts.value); if (t) ft.value = t.footer_text || ''; };
            m.querySelector('[data-x2]').onclick = () => m.close();
            m.querySelectorAll('[data-k]').forEach(b => b.onclick = () => {
                const t = tpls.find(x => x.id === +ts.value);
                const o = {installment_id: i.id, payment_id: m.querySelector('[data-f=payment_id]').value, template_id: ts.value, footer: ft.value !== (t ? t.footer_text : '') ? ft.value : ''};
                const href = url(b.dataset.k, o);
                if (b.dataset.k === 'receipt_html') window.open(href, '_blank', 'noopener'); else { download(href); setTimeout(() => Det.refresh(), 1500); }
            });
        },
    };

    // خروجیِ اکسل
    const Exp = {
        open(ids) {
            const cols = L.boot.export_columns;
            let saved = {};
            try { saved = JSON.parse(localStorage.getItem('lf:exp') || '{}'); } catch (e) {}
            const mode0 = saved.mode || 'policy';
            const defP = ['policy_no', 'holder_name', 'holder_nid', 'holder_mobile', 'phones', 'issue_j', 'pay_method', 'total_amount', 'total_paid', 'total_rem', 'overdue_count', 'overdue_amount', 'next_due', 'last_call', 'last_result'];
            const defI = ['policy_no', 'holder_name', 'holder_nid', 'holder_mobile', 'pay_method', 'inst_no', 'due_j', 'amount', 'eff_paid', 'rem', 'status_fa', 'days', 'pay_dates', 'pay_refs'];
            const chosen = new Set(saved['c_' + mode0] || (mode0 === 'inst' ? defI : defP));
            const m = modal('خروجیِ اکسل', `
                <div class="flex flex-wrap gap-3 items-center mb-3"><div class="lf-seg" data-mode><button data-v="policy" class="${mode0 === 'policy' ? 'on' : ''}">هر بیمه‌نامه یک ردیف</button><button data-v="inst" class="${mode0 === 'inst' ? 'on' : ''}">هر قسط یک ردیف</button></div>
                    <span class="lf-tag b">${ids ? fa(ids.length) + ' بیمه‌نامه‌ی انتخاب‌شده' : 'همه‌ی ردیف‌های فیلترشده (' + fa(L.list.data ? L.list.data.total : 0) + ')'}</span></div>
                <div data-inst class="lf-f mb-3 ${mode0 === 'inst' ? '' : 'hidden'}"><label><span>اقساط</span><select class="lf-sel" data-f="inst_status"><option value="">همه‌ی اقساط</option><option value="unpaid">فقط پرداخت‌نشده / مانده‌دار</option><option value="overdue">فقط معوق</option><option value="paid">فقط پرداخت‌شده</option></select></label>
                    <small class="text-slate-500 self-center">فیلترِ ماهِ سررسیدِ صفحه هم روی اقساط اعمال می‌شود.</small></div>
                <div class="flex gap-2 mb-2"><button class="lf-btn s sm" data-a="all">همه</button><button class="lf-btn s sm" data-a="none">هیچ</button><button class="lf-btn s sm" data-a="def">پیش‌فرض</button></div>
                <div data-cols class="grid gap-1" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))"></div>`,
                '<button class="lf-btn s" data-x2>انصراف</button><button class="lf-btn g" data-ok><i class="fas fa-file-excel"></i>دریافتِ اکسل</button>', {icon: 'fa-file-excel', wide: true});
            let mode = mode0;
            const draw = () => {
                m.querySelector('[data-cols]').innerHTML = Object.entries(cols).filter(([, c]) => mode === 'inst' || c.level === 'p').map(([k, c]) =>
                    `<label class="lf-chk" style="background:${c.level === 'i' ? '#f5f3ff' : '#f8fafc'};border-radius:10px;padding:6px 8px"><input type="checkbox" value="${k}" ${chosen.has(k) ? 'checked' : ''}>${esc(c.label)}</label>`).join('');
                m.querySelectorAll('[data-cols] input').forEach(c => c.onchange = () => c.checked ? chosen.add(c.value) : chosen.delete(c.value));
            };
            draw();
            m.querySelectorAll('[data-mode] button').forEach(b => b.onclick = () => {
                mode = b.dataset.v; m.querySelectorAll('[data-mode] button').forEach(x => x.classList.toggle('on', x === b));
                m.querySelector('[data-inst]').classList.toggle('hidden', mode !== 'inst');
                chosen.clear(); (saved['c_' + mode] || (mode === 'inst' ? defI : defP)).forEach(c => chosen.add(c)); draw();
            });
            m.querySelector('[data-a=all]').onclick = () => { Object.entries(cols).forEach(([k, c]) => { if (mode === 'inst' || c.level === 'p') chosen.add(k); }); draw(); };
            m.querySelector('[data-a=none]').onclick = () => { chosen.clear(); draw(); };
            m.querySelector('[data-a=def]').onclick = () => { chosen.clear(); (mode === 'inst' ? defI : defP).forEach(c => chosen.add(c)); draw(); };
            m.querySelector('[data-x2]').onclick = () => m.close();
            m.querySelector('[data-ok]').onclick = () => {
                const order = Object.keys(cols).filter(k => chosen.has(k));
                if (!order.length) { toast('حداقل یک ستون انتخاب کنید.', 'warning'); return; }
                saved.mode = mode; saved['c_' + mode] = order;
                try { localStorage.setItem('lf:exp', JSON.stringify(saved)); } catch (e) {}
                const f = Object.assign({}, L.list.f, {mode, cols: order, inst_status: mode === 'inst' ? m.querySelector('[data-f=inst_status]').value : ''});
                if (ids) { Object.keys(f).forEach(k => { if (!['mode', 'cols', 'inst_status', 'sort', 'due_from', 'due_to'].includes(k)) delete f[k]; }); f.ids = ids; }
                download(url('export', f));
                m.close();
            };
        },
    };

    // اعتبارسنجی و پاک‌سازی: بیمه‌نامه‌هایی که قسطِ ۱ دارند و هیچ پرداختی ندارند
    const Purge = {
        async open(after) {
            const m = modal('اعتبارسنجی: قسطِ اولِ پرداخت‌نشده', '<div class="lf-empty"><i class="fas fa-spinner fa-spin"></i>در حال بررسی…</div>', '', {icon: 'fa-broom', wide: true});
            let rows;
            try { rows = (await api('purge_candidates')).rows; } catch (e) { m.querySelector('.lf-mb').innerHTML = `<div class="lf-empty">${esc(e.message)}</div>`; return; }
            const sel = new Set(rows.map(r => r.id));
            const body = m.querySelector('.lf-mb');
            const draw = () => {
                body.innerHTML = rows.length ? `<p class="text-[12px] text-slate-600 mb-3 leading-7">این بیمه‌نامه‌ها <b>قسطِ اول</b> دارند ولی <b>هیچ</b> پرداختی (نه در اکسلِ بیمه‌گر و نه ثبتِ ما) ندارند؛ یعنی بیمه‌نامه صادر شده ولی مشتری هیچ قسطی نداده است. موارد انتخاب‌شده با همه‌ی اقساط، یادداشت‌ها و <b>پوشه‌ی بایگانی</b> حذف می‌شوند.</p>
                    <div class="flex gap-2 mb-2"><button class="lf-btn s sm" data-a="all">انتخابِ همه</button><button class="lf-btn s sm" data-a="none">هیچ</button><span class="lf-tag r mr-auto">${fa(sel.size)} از ${fa(rows.length)}</span></div>
                    <div class="lf-scroll" style="max-height:55vh"><table class="lf-tbl"><thead><tr><th></th><th>بیمه‌گذار</th><th>کد ملی</th><th>شماره بیمه‌نامه</th><th>صدور</th><th>اقساط</th><th>مبلغ</th></tr></thead><tbody>
                    ${rows.map(r => `<tr data-r="${r.id}" class="${sel.has(r.id) ? 'sel' : ''}"><td><input type="checkbox" ${sel.has(r.id) ? 'checked' : ''}></td><td><b>${esc(r.holder_name)}</b></td><td class="lf-mono">${fa(r.holder_nid || '')}</td><td class="lf-mono">${fa(r.policy_no)}</td><td>${jd(r.issue_j)}</td><td>${fa(r.inst_count)}</td><td>${money(r.total_amount)}</td></tr>`).join('')}</tbody></table></div>`
                    : '<div class="lf-empty"><i class="fas fa-circle-check text-green-500"></i>بیمه‌نامه‌ای با قسطِ اولِ پرداخت‌نشده پیدا نشد.</div>';
                body.querySelectorAll('tr[data-r]').forEach(tr => tr.onclick = () => { const id = +tr.dataset.r; sel.has(id) ? sel.delete(id) : sel.add(id); draw(); });
                const a = body.querySelector('[data-a=all]'); if (a) a.onclick = () => { rows.forEach(r => sel.add(r.id)); draw(); };
                const n = body.querySelector('[data-a=none]'); if (n) n.onclick = () => { sel.clear(); draw(); };
            };
            draw();
            if (!rows.length) return;
            m.querySelector('.lf-md').insertAdjacentHTML('beforeend', '<div class="lf-mf"><button class="lf-btn s" data-x2>انصراف</button><button class="lf-btn r" data-ok><i class="fas fa-trash"></i>حذفِ انتخاب‌شده‌ها</button></div>');
            m.querySelector('[data-x2]').onclick = () => m.close();
            m.querySelector('[data-ok]').onclick = async e => {
                if (!sel.size) { toast('موردی انتخاب نشده است.', 'warning'); return; }
                if (!(await confirmUi('حذفِ قطعی', `${fa(sel.size)} بیمه‌نامه با همه‌ی اقساط و پوشه‌ی بایگانی‌شان حذف شوند؟ این کار برگشت ندارد.`, {danger: true, ok: 'حذف'}))) return;
                busy(e.currentTarget, true);
                try { const r = await api('purge', {ids: [...sel]}); toast(`${fa(r.deleted)} بیمه‌نامه حذف شد.`, 'success'); m.close(); L.list.sel.clear(); if (after) after(); }
                catch (er) { toast(er.message, 'error'); busy(e.currentTarget, false); }
            };
        },
    };

    // ------------------------------------------------------------------
    //  ورود از اکسل
    // ------------------------------------------------------------------
    const Imp = {
        render(root) {
            if (L.imp && L.imp.state) return this.preview(root);
            root.innerHTML = shell('life-import', `
            <div class="lf-grid lf-g2" style="align-items:start">
                <div class="lf-card space-y-3"><h3><i class="fas fa-file-excel text-green-600"></i>گزارشِ اقساطِ بیمه‌گر <small>(xlsx یا csv)</small></h3>
                    <label class="lf-drop block" data-drop><i class="fas fa-cloud-arrow-up"></i><div class="font-black mt-2">فایل را این‌جا بکشید یا انتخاب کنید</div><small class="text-slate-500">مثلاً «گزارشِ سررسیدهای وصول‌نشده» (ReportDelayedInstallments)</small><input type="file" class="hidden" accept=".xlsx,.csv"><div data-fn class="mt-2 text-[12px] font-bold text-blue-700"></div></label>
                    <label class="lf-chk" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:10px 12px;align-items:flex-start"><input type="checkbox" data-drop1 style="margin-top:3px"><span><b>حذفِ قسط‌اولی‌ها</b><small class="block text-slate-600 font-normal leading-6 mt-1">بیمه‌نامه‌هایی که قسطِ اولشان در فایل هست (یعنی پرداخت نشده) و هیچ‌کدام از اقساطشان وصول نشده — با همه‌ی اقساطِ بعدی‌شان — از ورود کنار گذاشته می‌شوند. (با کد ملی هم بررسی می‌شود؛ در پیش‌نمایش فهرستشان را می‌بینید و می‌توانید برگردانید.)</small></span></label>
                    <button class="lf-btn p w-full" data-go disabled><i class="fas fa-magnifying-glass-chart"></i>بررسیِ فایل و پیش‌نمایش</button>
                    <p class="text-[11px] text-slate-500 leading-6"><i class="fas fa-circle-info ml-1"></i>تا «ثبتِ نهایی» نزنید هیچ چیزی ذخیره نمی‌شود. اگر ستون‌های فایلِ بیمه‌گر عوض شد، از <a href="#" onclick="switchTab('life-settings');return false" class="text-blue-600 font-bold">تنظیمات ← ستون‌های اکسل</a> نامِ سرستون‌ها را تعریف کنید.</p>
                </div>
                <div class="lf-card"><h3><i class="fas fa-clock-rotate-left text-slate-500"></i>ورودهای قبلی</h3><div data-hist><div class="lf-empty"><i class="fas fa-spinner fa-spin"></i></div></div></div>
            </div>`);
            const drop = root.querySelector('[data-drop]'), inp = drop.querySelector('input'), go = root.querySelector('[data-go]');
            const setF = f => { L.imp = {file: f}; drop.querySelector('[data-fn]').textContent = f ? f.name : ''; go.disabled = !f || !can('life-import', 'create'); };
            inp.onchange = () => setF(inp.files[0]);
            ['dragover', 'dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('on'); }));
            ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('on'); }));
            drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) setF(e.dataTransfer.files[0]); });
            if (!can('life-import', 'create')) go.title = 'دسترسیِ ثبت ندارید';
            go.onclick = async () => {
                const fd = new FormData(); fd.append('file', L.imp.file); fd.append('drop_first', root.querySelector('[data-drop1]').checked ? 1 : 0);
                busy(go, true);
                try { const st = await api('import_preview', {}, fd); L.imp = {state: st, dec: {}, filter: st.summary.inst_conflict || st.summary.policy_conflicts ? 'cf' : 'all', page: 1, clear: new Set(st.missing.map(x => x.id))}; this.preview(root); }
                catch (e) { toast(e.message, 'error'); busy(go, false); }
            };
            api('imports_list').then(r => {
                root.querySelector('[data-hist]').innerHTML = r.rows.length ? r.rows.map(x => `<div class="lf-li" style="cursor:default"><span class="av" style="background:#dcfce7;color:#15803d"><i class="fas fa-file-excel"></i></span><div class="flex-1 min-w-0"><b class="block truncate text-[12px]">${esc(x.file_name)}</b>
                    <small class="text-slate-500">${fa(x.at_j)} · ${esc(x.by)}${x.range_from ? ` · سررسید ${fa(x.range_from)} تا ${fa(x.range_to)}` : ''}</small><div class="flex flex-wrap gap-1 mt-1">${x.stats.policies_new ? `<span class="lf-tag b">${fa(x.stats.policies_new)} بیمه‌نامه‌ی تازه</span>` : ''}${x.stats.inst_new ? `<span class="lf-tag g">${fa(x.stats.inst_new)} قسطِ تازه</span>` : ''}${x.stats.inst_updated ? `<span class="lf-tag a">${fa(x.stats.inst_updated)} به‌روزرسانی</span>` : ''}${x.stats.cleared ? `<span class="lf-tag v">${fa(x.stats.cleared)} تسویه</span>` : ''}${x.stats.policies_skipped ? `<span class="lf-tag">${fa(x.stats.policies_skipped)} کنارگذاشته</span>` : ''}</div></div></div>`).join('') : '<div class="lf-empty">هنوز ورودی انجام نشده است.</div>';
            }).catch(() => {});
        },
        // تصمیمِ فعلیِ یک قسط (پیش‌فرض یا انتخابِ کاربر)
        act(pol, r) {
            const d = ((L.imp.dec[pol.key] || {}).insts || {})[r.inst_no];
            if (d && d.act) return d.act;
            if (r.status === 'new') return 'add';
            if (r.status === 'update') return 'file';
            return r.status === 'conflict' ? null : 'keep';
        },
        include(pol) { const d = L.imp.dec[pol.key]; return d && 'include' in d ? d.include : !pol.dropped; },
        pdec(pol) { const d = L.imp.dec[pol.key]; return d && d.policy ? d.policy : (pol.nid_conflict ? null : 'file'); },
        unresolved() {
            let n = 0;
            for (const p of L.imp.state.policies) {
                if (!this.include(p)) continue;
                if (p.nid_conflict && !this.pdec(p)) n++;
                for (const r of p.insts) if (r.status === 'conflict' && !this.act(p, r)) n++;
            }
            return n;
        },
        setDec(pol, patch, n) {
            const d = L.imp.dec[pol.key] = L.imp.dec[pol.key] || {insts: {}};
            if (n === undefined) Object.assign(d, patch); else d.insts[n] = Object.assign(d.insts[n] || {}, patch);
        },
        preview(root) {
            const st = L.imp.state, S = st.summary;
            const f = L.imp.filter;
            const isCf = p => p.nid_conflict || p.insts.some(r => r.status === 'conflict');
            const flt = {all: () => true, cf: isCf, new: p => !p.existing_id && !p.dropped, ex: p => p.existing_id && !p.dropped, drop: p => p.dropped || p.first_unpaid, same: p => !isCf(p) && p.insts.every(r => r.status === 'same')};
            const list = st.policies.filter(flt[f] || flt.all);
            const per = 30, pages = Math.max(1, Math.ceil(list.length / per));
            L.imp.page = Math.min(L.imp.page, pages);
            const chip = (k, t, n, cls = '') => `<button class="lf-btn sm ${f === k ? 'p' : 's'} ${cls}" data-flt="${k}">${t} <b class="mr-1">${fa(n)}</b></button>`;
            const nCf = st.policies.filter(isCf).length;
            root.innerHTML = shell('life-import', `
            <div class="lf-card space-y-3">
                <div class="flex flex-wrap items-center gap-2"><h3 style="margin:0"><i class="fas fa-file-excel text-green-600"></i>${esc(st.file_name)}</h3>
                    ${st.range && st.range[0] ? `<span class="lf-tag b">بازه‌ی سررسیدِ گزارش: ${fa(st.range[0])} تا ${fa(st.range[1])}</span>` : ''}<span class="lf-tag">${fa(st.total_rows)} ردیف</span>${st.drop_first ? '<span class="lf-tag r">حذفِ قسط‌اولی‌ها: روشن</span>' : ''}
                    <button class="lf-btn s sm mr-auto" data-a="cancel"><i class="fas fa-xmark"></i>انصراف و فایلِ دیگر</button></div>
                <div class="lf-kpis" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr))">
                    <div class="lf-kpi"><div class="k">بیمه‌نامه‌ها</div><div class="v">${fa(S.policies)}</div><div class="s">${fa(S.new_policies)} تازه · ${fa(S.existing)} موجود</div></div>
                    <div class="lf-kpi"><div class="k" style="color:#15803d">قسطِ تازه</div><div class="v">${fa(S.inst_new)}</div><div class="s">اضافه می‌شود</div></div>
                    <div class="lf-kpi"><div class="k" style="color:#b45309">به‌روزرسانی</div><div class="v">${fa(S.inst_update)}</div><div class="s">وصول/بدهی عوض شده</div></div>
                    <div class="lf-kpi"><div class="k">بدونِ تغییر</div><div class="v">${fa(S.inst_same)}</div><div class="s">شناسه‌ها مطابق</div></div>
                    <div class="lf-kpi" style="${S.inst_conflict + S.policy_conflicts ? 'border-color:#fdba74;background:#fff7ed' : ''}"><div class="k" style="color:#c2410c">مغایرت</div><div class="v">${fa(S.inst_conflict + S.policy_conflicts)}</div><div class="s">باید تصمیم بگیرید</div></div>
                    <div class="lf-kpi"><div class="k" style="color:#be123c">کنارگذاشته (قسطِ اول)</div><div class="v">${fa(S.dropped)}</div><div class="s">${fa(S.dropped_insts)} قسط</div></div>
                    ${st.missing.length ? `<div class="lf-kpi"><div class="k" style="color:#6d28d9">دیگر در گزارش نیست</div><div class="v">${fa(st.missing.length)}</div><div class="s">احتمالاً پرداخت شده</div></div>` : ''}
                </div>
                <div class="flex flex-wrap gap-2">${chip('all', 'همه', st.policies.length)}${chip('cf', 'مغایرت‌دار', nCf)}${chip('new', 'بیمه‌نامه‌ی تازه', S.new_policies)}${chip('ex', 'موجود', S.existing)}${chip('drop', 'قسطِ اولِ پرداخت‌نشده', st.policies.filter(p => p.dropped || p.first_unpaid).length)}${chip('same', 'بدونِ تغییر', st.policies.filter(flt.same).length)}</div>
                ${nCf ? '<div class="flex flex-wrap gap-2 items-center text-[11.5px]"><b class="text-orange-700"><i class="fas fa-wand-magic-sparkles ml-1"></i>همه‌ی مغایرت‌ها:</b><button class="lf-btn a sm" data-bulk="file">طبقِ فایل</button><button class="lf-btn s sm" data-bulk="keep">نگه‌داشتنِ اطلاعاتِ فعلی</button><button class="lf-btn r sm" data-bulk="skip">رد کردن</button></div>' : ''}
            </div>
            <div class="flex flex-col gap-3" data-list>${list.slice((L.imp.page - 1) * per, L.imp.page * per).map(p => this.polCard(p)).join('') || '<div class="lf-card lf-empty">موردی نیست.</div>'}</div>
            ${pages > 1 ? `<div class="lf-pager">${Array.from({length: pages}, (_, i) => `<button class="lf-btn sm ${i + 1 === L.imp.page ? 'p' : 's'}" data-pg="${i + 1}">${fa(i + 1)}</button>`).join('')}</div>` : ''}
            ${st.missing.length ? `<div class="lf-card"><h3><i class="fas fa-flag-checkered text-violet-600"></i>اقساطی که در بازه‌ی گزارش بودند ولی دیگر در فهرستِ وصول‌نشده‌ها نیستند <small>(تیک‌خورده‌ها «تسویه نزدِ بیمه‌گر» می‌شوند)</small></h3>
                <div class="flex gap-2 mb-2"><button class="lf-btn s sm" data-mall>همه</button><button class="lf-btn s sm" data-mnone>هیچ</button></div>
                <div class="lf-scroll" style="max-height:40vh"><table class="lf-tbl"><thead><tr><th></th><th>بیمه‌گذار</th><th>بیمه‌نامه</th><th>قسط</th><th>سررسید</th><th>مانده</th></tr></thead><tbody>
                ${st.missing.map(x => `<tr><td><input type="checkbox" data-miss="${x.id}" ${L.imp.clear.has(x.id) ? 'checked' : ''}></td><td>${esc(x.holder_name)}<small class="block text-slate-500 lf-mono">${fa(x.holder_nid || '')}</small></td><td class="lf-mono">${fa(x.policy_no)}</td><td>${fa(x.inst_no)}</td><td>${fa(x.due_j)}</td><td>${money(x.rem)}</td></tr>`).join('')}</tbody></table></div></div>` : ''}
            <div class="lf-bulk" data-bar></div>`);
            this.bar(root);
            root.querySelector('[data-a=cancel]').onclick = () => { L.imp = null; this.render(root); };
            root.querySelectorAll('[data-flt]').forEach(b => b.onclick = () => { L.imp.filter = b.dataset.flt; L.imp.page = 1; this.preview(root); });
            root.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { L.imp.page = +b.dataset.pg; this.preview(root); root.scrollIntoView({block: 'start'}); });
            root.querySelectorAll('[data-bulk]').forEach(b => b.onclick = () => {
                const v = b.dataset.bulk;
                st.policies.forEach(p => {
                    if (!this.include(p)) return;
                    if (p.nid_conflict) this.setDec(p, {policy: v === 'skip' ? 'keep' : v});
                    p.insts.forEach(r => { if (r.status === 'conflict') this.setDec(p, {act: v === 'file' ? (r.db ? 'file' : 'add') : v === 'keep' ? (r.db ? 'keep' : 'skip') : 'skip'}, r.inst_no); });
                });
                this.preview(root);
            });
            const ms = root.querySelectorAll('[data-miss]');
            ms.forEach(c => c.onchange = () => { c.checked ? L.imp.clear.add(+c.dataset.miss) : L.imp.clear.delete(+c.dataset.miss); this.bar(root); });
            const ma = root.querySelector('[data-mall]'); if (ma) ma.onclick = () => { st.missing.forEach(x => L.imp.clear.add(x.id)); this.preview(root); };
            const mn = root.querySelector('[data-mnone]'); if (mn) mn.onclick = () => { L.imp.clear.clear(); this.preview(root); };
            this.bind(root);
        },
        polCard(p) {
            const inc = this.include(p), pd = this.pdec(p);
            const cf = p.nid_conflict || p.insts.some(r => r.status === 'conflict');
            const badge = p.dropped ? '<span class="lf-tag r"><i class="fas fa-user-xmark"></i>کنارگذاشته: قسطِ اول</span>' : p.existing_id ? '<span class="lf-tag b">موجود</span>' : '<span class="lf-tag g"><i class="fas fa-plus"></i>بیمه‌نامه‌ی تازه</span>';
            const ST2 = {new: ['تازه', 'g'], same: ['بدونِ تغییر', ''], update: ['به‌روزرسانی', 'a'], conflict: ['مغایرت', 'r']};
            const actOpts = r => {
                const o = r.db ? [['file', 'طبقِ فایل'], ['keep', 'نگه‌داشتنِ فعلی'], ['edit', 'ویرایش…'], ['skip', 'رد']] : [['add', 'افزودن'], ['edit', 'ویرایش و افزودن…'], ['skip', 'رد']];
                const a = this.act(p, r);
                return `<select class="lf-sel" data-act="${r.inst_no}" style="padding:5px 8px;font-size:11.5px;${r.status === 'conflict' && !a ? 'border-color:#f97316;background:#fff7ed' : ''}">${!a ? '<option value="">— تصمیم بگیرید —</option>' : ''}${o.map(([v, t]) => `<option value="${v}" ${a === v ? 'selected' : ''}>${t}</option>`).join('')}</select>`;
            };
            const rows = p.insts.map(r => {
                const a = this.act(p, r), ed = ((L.imp.dec[p.key] || {}).insts || {})[r.inst_no] || {};
                const diffs = r.diffs.map(x => `${esc(x.label)}: <s>${esc(fa(x.field === 'amount' ? money(x.db) : x.db))}</s> ← <b>${esc(fa(x.field === 'amount' ? money(x.file) : x.file))}</b>`).join(' · ');
                return `<div class="lf-irow ${r.status === 'conflict' ? 'cf' : ''}"><div class="c1"><b>${fa(r.inst_no)}</b></div><div class="c2">${jd(r.due_j)}</div><div class="c3">${money(r.amount)}<small class="block text-slate-500">وصول ${money(r.collected)}</small></div>
                    <div class="c4"><span class="lf-tag ${ST2[r.status][1]}">${ST2[r.status][0]}</span>${r.internal_no ? `<small class="block text-slate-400 lf-mono">${fa(r.internal_no)}</small>` : ''}</div>
                    <div class="c5">${diffs || r.note ? `<div class="lf-diff">${diffs}${r.note ? (diffs ? '<br>' : '') + esc(r.note) : ''}</div>` : ''}</div>
                    <div class="c6">${actOpts(r)}${a === 'edit' ? `<div class="grid grid-cols-3 gap-1 mt-1"><input class="lf-in money-input" style="padding:5px" data-ed="amount" data-n="${r.inst_no}" placeholder="مبلغ" value="${money(ed.amount ?? r.amount)}"><input class="lf-in" style="padding:5px" data-ed="due_j" data-n="${r.inst_no}" data-jdate dir="ltr" value="${fa(ed.due_j ?? r.due_j)}"><input class="lf-in money-input" style="padding:5px" data-ed="collected" data-n="${r.inst_no}" placeholder="وصول" value="${money(ed.collected ?? r.collected)}"></div>` : ''}</div></div>`;
            }).join('');
            const issues = p.issues.length ? `<div class="lf-diff" style="padding:6px 12px">${p.issues.map(x => `${esc(x.label)}: <s>${esc(fa(x.db || '—'))}</s> ← <b>${esc(fa(x.file))}</b>${x.note ? ' (' + esc(x.note) + ')' : ''}`).join(' · ')}
                ${p.existing_id ? `<div class="mt-1 flex gap-2 items-center"><span class="font-bold">اطلاعاتِ بیمه‌نامه:</span><div class="lf-seg"><button data-pol="file" class="${pd === 'file' ? 'on' : ''}">طبقِ فایل</button><button data-pol="keep" class="${pd === 'keep' ? 'on' : ''}">نگه‌داشتنِ فعلی</button></div>${p.nid_conflict && !pd ? '<span class="lf-tag r">تصمیم بگیرید</span>' : ''}</div>` : ''}</div>` : '';
            return `<div class="lf-ipol ${inc ? '' : 'off'} ${cf && inc ? 'cf' : ''}" data-key="${esc(p.key)}">
                <div class="h"><label class="lf-chk"><input type="checkbox" data-inc ${inc ? 'checked' : ''}></label>
                    <div class="min-w-0"><b>${esc(p.holder_name || '—')}</b> <small class="text-slate-500 lf-mono">${fa(p.holder_nid || '')}</small><div class="text-[11px] text-slate-500"><span class="lf-mono">${fa(p.policy_no)}</span> · ${esc(p.pay_method || '')} · صدور ${jd(p.issue_j)}</div></div>
                    ${badge}${p.drop_reason ? `<small class="text-rose-700">${esc(p.drop_reason)}</small>` : ''}
                    <label class="mr-auto flex items-center gap-1 text-[11px] font-bold text-slate-600"><i class="fas fa-phone"></i><input class="lf-in lf-mono" style="width:150px;padding:5px 8px" data-phone inputmode="tel" placeholder="شماره‌ی تماس" value="${esc(fa(((L.imp.dec[p.key] || {}).phone) ?? p.phone ?? ''))}"></label></div>
                ${issues}
                ${inc ? `<div class="lf-irow hd"><div>قسط</div><div>سررسید</div><div>مبلغ / وصول</div><div>وضعیت</div><div>مغایرت</div><div>تصمیم</div></div>${rows}` : `<div class="text-[11.5px] text-slate-500 px-3 py-2">${fa(p.insts.length)} قسط · ${p.dropped ? 'برای برگرداندن تیک را بزنید.' : 'وارد نمی‌شود.'}</div>`}</div>`;
        },
        bind(root) {
            const st = L.imp.state;
            root.querySelectorAll('.lf-ipol').forEach(card => {
                const p = st.policies.find(x => x.key === card.dataset.key);
                const redraw = () => { card.outerHTML = this.polCard(p); const nc = root.querySelector(`.lf-ipol[data-key="${CSS.escape(p.key)}"]`); this.bindOne(root, nc, p); this.bar(root); };
                this.bindOne(root, card, p, redraw);
            });
            if (window.MoneyInput) MoneyInput.apply(root);
        },
        bindOne(root, card, p, redraw) {
            redraw = redraw || (() => { card.outerHTML = this.polCard(p); const nc = root.querySelector(`.lf-ipol[data-key="${CSS.escape(p.key)}"]`); this.bindOne(root, nc, p); this.bar(root); });
            card.querySelector('[data-inc]').onchange = e => { this.setDec(p, {include: e.target.checked}); redraw(); };
            card.querySelector('[data-phone]').oninput = e => this.setDec(p, {phone: en(e.target.value)});
            card.querySelectorAll('[data-pol]').forEach(b => b.onclick = () => { this.setDec(p, {policy: b.dataset.pol}); redraw(); });
            card.querySelectorAll('[data-act]').forEach(s => s.onchange = () => { this.setDec(p, {act: s.value || null}, +s.dataset.act); redraw(); });
            card.querySelectorAll('[data-ed]').forEach(i => i.oninput = () => this.setDec(p, {[i.dataset.ed]: en(i.value).replace(/,/g, '')}, +i.dataset.n));
            if (window.MoneyInput) MoneyInput.apply(card);
        },
        bar(root) {
            const bar = root.querySelector('[data-bar]');
            if (!bar) return;
            const st = L.imp.state, un = this.unresolved();
            let nPol = 0, nInst = 0;
            st.policies.forEach(p => { if (!this.include(p)) return; nPol++; p.insts.forEach(r => { const a = this.act(p, r); if (a && a !== 'skip' && a !== 'keep') nInst++; }); });
            bar.innerHTML = `<div class="text-[12px]"><b>${fa(nPol)}</b> بیمه‌نامه · <b>${fa(nInst)}</b> قسط ثبت/به‌روز می‌شود${st.missing.length ? ` · <b>${fa(L.imp.clear.size)}</b> تسویه` : ''}${un ? ` · <span class="text-orange-300 font-black"><i class="fas fa-triangle-exclamation"></i> ${fa(un)} مغایرتِ بی‌تصمیم</span>` : ''}</div>
                <button class="lf-btn g" data-commit ${un || !can('life-import', 'create') ? 'disabled' : ''}><i class="fas fa-check-double"></i>ثبتِ نهایی</button>`;
            bar.querySelector('[data-commit]').onclick = e => this.commit(root, e.currentTarget);
        },
        async commit(root, btn) {
            const st = L.imp.state, dec = {policies: {}, clear_missing: [...L.imp.clear]};
            st.policies.forEach(p => {
                const d = L.imp.dec[p.key] || {}, o = {include: this.include(p), policy: this.pdec(p) || 'keep', insts: {}};
                if ('phone' in d) o.phone = d.phone;
                p.insts.forEach(r => { const a = this.act(p, r); o.insts[r.inst_no] = Object.assign({}, (d.insts || {})[r.inst_no] || {}, {act: a || 'skip'}); });
                dec.policies[p.key] = o;
            });
            if (!(await confirmUi('ثبتِ نهایی', 'اطلاعات طبقِ پیش‌نمایش ثبت شود؟', {ok: 'ثبت'}))) return;
            busy(btn, true);
            try {
                const r = await api('import_commit', {token: st.token, decisions: dec});
                const s = r.stats;
                L.imp = null; L.boot = null; await boot();
                root.innerHTML = shell('life-import', `<div class="lf-card text-center" style="padding:34px 16px"><div class="w-16 h-16 rounded-full bg-green-100 text-green-600 flex items-center justify-center mx-auto text-2xl mb-3"><i class="fas fa-check"></i></div>
                    <h3 style="justify-content:center;font-size:16px">ورود انجام شد</h3>
                    <div class="flex flex-wrap gap-2 justify-center mt-2"><span class="lf-tag b">${fa(s.policies_new)} بیمه‌نامه‌ی تازه</span><span class="lf-tag">${fa(s.policies_updated)} بیمه‌نامه‌ی به‌روزشده</span><span class="lf-tag g">${fa(s.inst_new)} قسطِ تازه</span><span class="lf-tag a">${fa(s.inst_updated)} قسطِ به‌روزشده</span><span class="lf-tag v">${fa(s.cleared)} تسویه</span><span class="lf-tag r">${fa(s.policies_skipped)} بیمه‌نامه‌ی کنارگذاشته</span></div>
                    <div class="flex gap-2 justify-center mt-5 flex-wrap"><button class="lf-btn p" onclick="switchTab('life-policies')"><i class="fas fa-file-medical"></i>بیمه‌نامه‌ها</button><button class="lf-btn s" onclick="switchTab('life-dash')"><i class="fas fa-chart-pie"></i>داشبورد</button><button class="lf-btn o" data-again><i class="fas fa-file-import"></i>فایلِ دیگر</button></div></div>`);
                root.querySelector('[data-again]').onclick = () => this.render(root);
            } catch (e) { toast(e.message, 'error'); busy(btn, false); }
        },
    };

    // ------------------------------------------------------------------
    //  بایگانی
    // ------------------------------------------------------------------
    const Arch = {
        async render(root) {
            root.innerHTML = shell('life-archive', `<div class="lf-card space-y-3"><div class="flex flex-wrap gap-2 items-center"><div class="lf-crumb flex-1" data-crumb></div>
                <div class="relative min-w-[220px]"><i class="fas fa-magnifying-glass absolute top-1/2 -translate-y-1/2 right-3 text-slate-400"></i><input class="lf-in" style="padding-right:34px" data-q placeholder="جستجو: شماره بیمه‌نامه، نام، کد ملی" value="${esc(L.arch.q)}"></div>
                ${can('life-archive', 'export') ? '<button class="lf-btn o" data-zip><i class="fas fa-file-zipper text-amber-600"></i>زیپِ همین پوشه</button>' : ''}</div>
                <p class="text-[11px] text-slate-500"><i class="fas fa-circle-info ml-1"></i>ساختار: سالِ صدور ← ماهِ صدور ← «شماره بیمه‌نامه - نام - کد ملی» ← «قسط N - سررسید» (اطلاعاتِ قسط، فیش‌ها و رسیدها)</p>
                <div data-items><div class="lf-empty"><i class="fas fa-spinner fa-spin"></i></div></div></div>`);
            let t;
            root.querySelector('[data-q]').oninput = e => { clearTimeout(t); t = setTimeout(() => { L.arch.q = e.target.value.trim(); this.load(root); }, 300); };
            const z = root.querySelector('[data-zip]'); if (z) z.onclick = () => download(url('archive_zip', {path: L.arch.path}));
            this.load(root);
        },
        async load(root) {
            const box = root.querySelector('[data-items]');
            let r;
            try { r = await api('archive_list', {path: L.arch.path, q: L.arch.q}); }
            catch (e) { if (L.arch.path) { L.arch.path = ''; return this.load(root); } box.innerHTML = `<div class="lf-empty">${esc(e.message)}</div>`; return; }
            const parts = r.path ? r.path.split('/') : [];
            root.querySelector('[data-crumb]').innerHTML = `<button data-p=""><i class="fas fa-house ml-1"></i>بایگانی بیمه عمر</button>` + parts.map((x, i) => `<i class="fas fa-chevron-left text-[9px] text-slate-400"></i><button data-p="${esc(parts.slice(0, i + 1).join('/'))}">${esc(fa(x))}</button>`).join('');
            root.querySelectorAll('[data-crumb] [data-p]').forEach(b => b.onclick = () => { L.arch.path = b.dataset.p; L.arch.q = ''; root.querySelector('[data-q]').value = ''; this.load(root); });
            const ic = it => it.dir ? ['fa-folder', '#fef3c7', '#d97706'] : /\.(jpe?g|png|webp|gif|heic)$/i.test(it.name) ? ['fa-image', '#f5f3ff', '#7c3aed'] : /\.pdf$/i.test(it.name) ? ['fa-file-pdf', '#fef2f2', '#dc2626'] : /\.docx?$/i.test(it.name) ? ['fa-file-word', '#eff6ff', '#2563eb'] : /\.txt$/i.test(it.name) ? ['fa-file-lines', '#f1f5f9', '#475569'] : ['fa-file', '#f1f5f9', '#475569'];
            const size = b => b == null ? '' : b > 1048576 ? fa((b / 1048576).toFixed(1)) + ' مگابایت' : fa(Math.max(1, Math.round(b / 1024))) + ' کیلوبایت';
            box.innerHTML = r.items.length ? `<div class="grid gap-2" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">${r.items.map(it => { const c = ic(it); return `<div class="lf-fi" data-path="${esc(it.path)}" data-dir="${it.dir ? 1 : ''}"><span class="ic" style="background:${c[1]};color:${c[2]}"><i class="fas ${c[0]}"></i></span>
                <div class="min-w-0 flex-1"><b class="block truncate text-[12.5px]" title="${esc(it.name)}">${it.dir ? esc(fa(it.name)) : fname(it.name)}</b><small class="text-slate-500">${it.where ? esc(fa(it.where)) + ' · ' : ''}${it.dir ? (it.count != null ? fa(it.count) + ' مورد' : 'پوشه') : size(it.size)} · ${fa(it.mtime_j)}</small></div>
                ${!it.dir ? `<a class="lf-btn s sm" href="${url('archive_file', {path: it.path})}" data-stop title="دانلود"><i class="fas fa-download"></i></a>` : ''}</div>`; }).join('')}</div>`
                : `<div class="lf-empty"><i class="fas fa-folder-open"></i>${L.arch.q ? 'چیزی پیدا نشد.' : 'این پوشه خالی است.'}</div>`;
            box.querySelectorAll('[data-stop]').forEach(a => a.addEventListener('click', e => e.stopPropagation()));
            box.querySelectorAll('.lf-fi').forEach(el => el.onclick = () => {
                if (el.dataset.dir) { L.arch.path = el.dataset.path; L.arch.q = ''; root.querySelector('[data-q]').value = ''; this.load(root); }
                else window.open(url('archive_file', {path: el.dataset.path, inline: 1}), '_blank', 'noopener');
            });
        },
    };

    // ------------------------------------------------------------------
    //  تنظیمات: ستون‌های اکسل و قالب‌های رسید
    // ------------------------------------------------------------------
    const Sett = {
        async render(root) {
            const ed = can('life-settings', 'edit');
            root.innerHTML = shell('life-settings', `<div class="lf-grid lf-g2" style="align-items:start">
                <div class="lf-card"><h3><i class="fas fa-table-columns text-green-600"></i>ستون‌های اکسلِ بیمه‌گر <small>هر کلید با هر کدام از این سرستون‌ها پیدا می‌شود</small></h3>
                    ${ed ? `<label class="lf-btn o sm mb-3" style="cursor:pointer"><i class="fas fa-file-arrow-up"></i>خواندنِ سرستون‌ها از یک فایلِ نمونه<input type="file" class="hidden" accept=".xlsx,.csv" data-hdr></label><div data-hdrs></div>` : ''}
                    <div data-cols><div class="lf-empty"><i class="fas fa-spinner fa-spin"></i></div></div>
                    ${ed ? '<div class="flex gap-2 mt-3"><button class="lf-btn p" data-savecols><i class="fas fa-floppy-disk"></i>ذخیره‌ی ستون‌ها</button><button class="lf-btn s" data-defcols><i class="fas fa-rotate-left"></i>پیش‌فرض</button></div>' : ''}</div>
                <div class="space-y-4"><div class="lf-card"><h3><i class="fas fa-receipt text-blue-600"></i>قالب‌های رسید</h3><div data-tpls><div class="lf-empty"><i class="fas fa-spinner fa-spin"></i></div></div>
                    ${ed ? `<div class="border-t border-slate-100 mt-3 pt-3 space-y-2"><b class="text-[12px]">افزودنِ قالبِ Word</b><div class="lf-grid lf-g2"><input class="lf-in" data-tn placeholder="نامِ قالب"><label class="lf-btn o" style="cursor:pointer"><i class="fas fa-file-word text-blue-600"></i><span data-tfn>انتخابِ فایلِ docx</span><input type="file" class="hidden" accept=".docx" data-tf></label></div>
                        <textarea class="lf-ta" rows="2" data-tt placeholder="متنِ زیرِ جدول برای این قالب"></textarea><div class="flex gap-2 flex-wrap"><button class="lf-btn p" data-tup><i class="fas fa-upload"></i>بارگذاریِ قالب</button><a class="lf-btn s" href="${url('tpl_sample')}"><i class="fas fa-download"></i>دریافتِ قالبِ نمونه (Word)</a></div></div>` : ''}</div>
                    <div class="lf-card"><h3><i class="fas fa-code text-violet-600"></i>کدهای قالب <small>روی هر کد بزنید تا کپی شود</small></h3><div data-codes class="flex flex-col gap-1"></div></div></div></div>`);
            this.cols(root); this.tpls(root);
            const hdr = root.querySelector('[data-hdr]');
            if (hdr) hdr.onchange = async () => {
                if (!hdr.files[0]) return;
                const fd = new FormData(); fd.append('file', hdr.files[0]);
                try {
                    const r = await api('colmap_headers', {}, fd);
                    const keys = this._cols || [];
                    root.querySelector('[data-hdrs]').innerHTML = `<div class="bg-slate-50 border border-slate-100 rounded-xl p-3 mb-3"><b class="text-[12px]">سرستون‌های «${esc(r.sheet || '')}»</b> <small class="text-slate-500">برای هر سرستونِ ناشناخته کلیدش را انتخاب کنید</small>
                        <div class="flex flex-col gap-1 mt-2">${r.headers.map(h => `<div class="flex items-center gap-2 text-[11.5px]"><span class="flex-1 truncate font-bold">${esc(h.header)}</span>${h.key ? `<span class="lf-tag g">${esc((keys.find(k => k.key === h.key) || {}).label || h.key)}</span>` : `<select class="lf-sel" style="width:180px;padding:4px 6px;font-size:11px" data-map="${esc(h.header)}"><option value="">— استفاده نمی‌شود —</option>${keys.map(k => `<option value="${k.key}">${esc(k.label)}</option>`).join('')}</select>`}</div>`).join('')}</div></div>`;
                    root.querySelectorAll('[data-map]').forEach(s => s.onchange = () => {
                        if (!s.value) return;
                        const inp = root.querySelector(`[data-colk="${s.value}"]`);
                        if (inp && !inp.value.split('،').map(x => x.trim()).includes(s.dataset.map)) inp.value = (inp.value ? inp.value + '، ' : '') + s.dataset.map;
                        toast('اضافه شد؛ «ذخیره‌ی ستون‌ها» را بزنید.', 'info');
                    });
                } catch (e) { toast(e.message, 'error'); }
                hdr.value = '';
            };
            const sc = root.querySelector('[data-savecols]');
            if (sc) sc.onclick = async () => {
                const columns = {};
                root.querySelectorAll('[data-colk]').forEach(i => { columns[i.dataset.colk] = i.value.split(/[،,]/).map(x => x.trim()).filter(Boolean); });
                try { await api('colmap_save', {columns}); toast('ستون‌ها ذخیره شد.', 'success'); this.cols(root); } catch (e) { toast(e.message, 'error'); }
            };
            const dc = root.querySelector('[data-defcols]');
            if (dc) dc.onclick = async () => { if (!(await confirmUi('پیش‌فرض', 'نام‌های سرستون به حالتِ پیش‌فرض برگردد؟'))) return; try { await api('colmap_save', {columns: {}}); this.cols(root); } catch (e) { toast(e.message, 'error'); } };
            const tf = root.querySelector('[data-tf]');
            if (tf) tf.onchange = () => { root.querySelector('[data-tfn]').textContent = tf.files[0] ? tf.files[0].name : 'انتخابِ فایلِ docx'; };
            const tu = root.querySelector('[data-tup]');
            if (tu) tu.onclick = async () => {
                if (!tf.files[0]) { toast('فایلِ Word را انتخاب کنید.', 'warning'); return; }
                const fd = new FormData(); fd.append('file', tf.files[0]); fd.append('name', root.querySelector('[data-tn]').value); fd.append('footer_text', root.querySelector('[data-tt]').value);
                busy(tu, true);
                try {
                    const r = await api('tpl_upload', {}, fd);
                    L.boot.templates = r.templates;
                    if (r.unknown.length) showAlert ? showAlert('قالب ذخیره شد', 'این کدها شناخته نشدند و خالی می‌مانند: ' + r.unknown.map(x => '{{' + x + '}}').join('، '), 'warning') : 0;
                    else toast('قالب ذخیره شد.', 'success');
                    this.render(root);
                } catch (e) { toast(e.message, 'error'); busy(tu, false); }
            };
        },
        async cols(root) {
            const box = root.querySelector('[data-cols]'), ed = can('life-settings', 'edit');
            try {
                const r = await api('colmap_get');
                this._cols = r.columns;
                box.innerHTML = `<div class="flex flex-col gap-2">${r.columns.map(c => `<div class="grid gap-2 items-center" style="grid-template-columns:minmax(110px,150px) 1fr"><span class="text-[11.5px] font-black text-slate-700">${esc(c.label)}${c.required ? ' <span class="text-red-500">*</span>' : ''}</span>
                    ${ed ? `<input class="lf-in" style="padding:6px 9px;font-size:11.5px" data-colk="${c.key}" value="${esc(c.names.join('، '))}">` : `<div class="lf-chip-in">${c.names.map(n => `<span class="lf-tag">${esc(n)}</span>`).join('')}</div>`}</div>`).join('')}</div>
                    <p class="text-[10.5px] text-slate-500 mt-2">* ستون‌های اجباری. نام‌ها را با «،» جدا کنید؛ فاصله، نیم‌فاصله و «ی/ي» در مقایسه مهم نیست.</p>`;
            } catch (e) { box.innerHTML = `<div class="lf-empty">${esc(e.message)}</div>`; }
        },
        async tpls(root) {
            const box = root.querySelector('[data-tpls]'), ed = can('life-settings', 'edit');
            let r;
            try { r = await api('templates'); } catch (e) { box.innerHTML = `<div class="lf-empty">${esc(e.message)}</div>`; return; }
            L.boot.templates = r.templates;
            box.innerHTML = `<div class="flex flex-col gap-3">${r.templates.map(t => `<div class="border border-slate-100 rounded-2xl p-3 ${t.is_default ? 'bg-blue-50/40' : ''}" data-t="${t.id}">
                <div class="flex flex-wrap items-center gap-2 mb-2"><i class="fas ${t.builtin ? 'fa-wand-magic-sparkles text-violet-600' : 'fa-file-word text-blue-600'}"></i>${ed ? `<input class="lf-in" style="flex:1;min-width:150px;padding:6px 9px" data-n value="${esc(t.name)}">` : `<b>${esc(t.name)}</b>`}
                    ${t.is_default ? '<span class="lf-tag b">پیش‌فرض</span>' : ed ? '<button class="lf-btn s sm" data-def>پیش‌فرض شود</button>' : ''}${!t.builtin ? `<a class="lf-btn s sm" href="${url('tpl_file', {id: t.id})}"><i class="fas fa-download"></i></a>` : ''}${ed && !t.builtin ? '<button class="lf-btn r sm" data-del><i class="fas fa-trash"></i></button>' : ''}</div>
                <span class="lf-lbl">متنِ زیرِ جدولِ اقساط</span>${ed ? `<textarea class="lf-ta" rows="3" data-ft>${esc(t.footer_text)}</textarea><button class="lf-btn p sm mt-2" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>` : `<div class="text-[12px] whitespace-pre-wrap">${esc(t.footer_text)}</div>`}</div>`).join('')}</div>`;
            box.querySelectorAll('[data-t]').forEach(el => {
                const id = +el.dataset.t;
                const save = async (extra = {}) => { try { const x = await api('tpl_save', Object.assign({id, name: el.querySelector('[data-n]').value, footer_text: el.querySelector('[data-ft]').value}, extra)); L.boot.templates = x.templates; toast('ذخیره شد.', 'success'); this.tpls(root); } catch (e) { toast(e.message, 'error'); } };
                const s = el.querySelector('[data-save]'); if (s) s.onclick = () => save();
                const d = el.querySelector('[data-def]'); if (d) d.onclick = () => save({is_default: 1});
                const x = el.querySelector('[data-del]'); if (x) x.onclick = async () => { if (!(await confirmUi('حذفِ قالب', 'این قالب حذف شود؟', {danger: true, ok: 'حذف'}))) return; try { const y = await api('tpl_delete', {id}); L.boot.templates = y.templates; this.tpls(root); } catch (e) { toast(e.message, 'error'); } };
            });
            root.querySelector('[data-codes]').innerHTML = Object.entries(r.codes).map(([k, v]) => `<div class="flex items-center gap-2 text-[11.5px]"><button class="lf-code" data-code="{{${esc(k)}}}">{{${esc(k)}}}</button><span class="text-slate-500">${esc(v)}</span></div>`).join('');
            root.querySelectorAll('[data-code]').forEach(b => b.onclick = () => copy(b.dataset.code));
        },
    };

    window.Life = {open, openDetail: id => Det.open(id), closeDetail: () => Det.close()};
})();
