// فایل: tools.js
// «ابزارها»ی روزمره‌ی کارمند — هم صفحه‌ی «ابزارها» و هم تبِ «ابزار»ِ جعبه‌ابزارِ من (comfort.js) از همین فایل ساخته می‌شوند.
// هر ابزار جدا در «دسترسی‌ها» (امکاناتِ رفاهی) روشن/خاموش می‌شود؛ ابزارِ خاموش در هیچ‌کدام از دو جا دیده نمی‌شود.
// کارهای عکس، تاریخ، اقساط و متن داخلِ مرورگر انجام می‌شود (فایلی به سرور نمی‌رود)؛ فقط PDF روی سرور (api/tools_actions.php).
(function () {
    'use strict';
    if (window.Tools) return;
    const CF = window.CF_CONFIG || {};
    const API = (window.TOOLS_CONFIG && TOOLS_CONFIG.api) || (CF.api ? CF.api.replace('comfort_actions', 'tools_actions') : 'api/tools_actions.php');
    const url = q => API + (API.includes('?') ? '&' : '?') + q;
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = n => fa(Math.round(Number(n) || 0).toLocaleString('en-US'));
    const num = v => +en(v).replace(/[^\d]/g, '') || 0;
    const kb = b => b >= 1048576 ? fa((b / 1048576).toFixed(2)) + ' مگابایت' : fa(Math.max(1, Math.round(b / 1024))) + ' کیلوبایت';
    const toast = (m, t) => window.showToast ? showToast(m, t || 'info') : alert(m);
    async function api(action, body = {}) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))})).json(); }
        catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور'}; }
    }
    function copy(text, msg) {
        const done = () => toast(msg || 'کپی شد.', 'success');
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, () => fallback());
        else fallback();
        function fallback() { const t = document.createElement('textarea'); t.value = text; t.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} t.remove(); }
    }
    async function pasteText() {
        try { if (navigator.clipboard && navigator.clipboard.readText) return await navigator.clipboard.readText(); } catch (e) {}
        toast('مرورگر اجازه‌ی خواندنِ کلیپ‌بورد نداد؛ با Ctrl+V بچسبانید.', 'warning');
        return null;
    }
    function download(blob, name) {
        const a = document.createElement('a');
        // کروم نیم‌فاصله را در نامِ فایل به «_» تبدیل می‌کند؛ فاصله‌ی معمولی جایش
        a.href = URL.createObjectURL(blob); a.download = String(name).replace(/\u200c/g, ' ');
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 30000);
    }
    const baseName = n => String(n || 'file').replace(/\.[^.]+$/, '');

    // ---------------- تقویمِ شمسی (الگوریتمِ دقیقِ jalaali با سال‌های کبیسه‌ی واقعی) ----------------
    const div = (a, b) => ~~(a / b), mod = (a, b) => a - ~~(a / b) * b;
    const BRK = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    function jalCal(jy) {
        let gy = jy + 621, leapJ = -14, jp = BRK[0], jm, jump = 0, n, i;
        for (i = 1; i < BRK.length; i++) { jm = BRK[i]; jump = jm - jp; if (jy < jm) break; leapJ += div(jump, 33) * 8 + div(mod(jump, 33), 4); jp = jm; }
        n = jy - jp;
        leapJ += div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
        if (mod(jump, 33) === 4 && jump - n === 4) leapJ++;
        const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150, march = 20 + leapJ - leapG;
        if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
        let leap = mod(mod(n + 1, 33) - 1, 4); if (leap === -1) leap = 4;
        return {leap, gy, march};
    }
    function g2d(gy, gm, gd) { let d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408; return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752; }
    function d2g(jdn) { let j = 4 * jdn + 139361631; j += div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908; const i = div(mod(j, 1461), 4) * 5 + 308, gd = div(mod(i, 153), 5) + 1, gm = mod(div(i, 153), 12) + 1; return [div(j, 1461) - 100100 + div(8 - gm, 6), gm, gd]; }
    function j2d(jy, jm, jd) { const r = jalCal(jy); return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1; }
    function d2j(jdn) {
        const gy = d2g(jdn)[0]; let jy = gy - 621; const r = jalCal(jy); let k = jdn - g2d(gy, 3, r.march);
        if (k >= 0) { if (k <= 185) return [jy, 1 + div(k, 31), mod(k, 31) + 1]; k -= 186; } else { jy--; k += 179; if (r.leap === 1) k++; }
        return [jy, 7 + div(k, 30), mod(k, 30) + 1];
    }
    const isLeap = jy => jalCal(jy).leap === 0;
    const mLen = (jy, jm) => jm <= 6 ? 31 : jm <= 11 ? 30 : (isLeap(jy) ? 30 : 29);
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const DOW = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    const p2 = n => String(n).padStart(2, '0');
    const jstr = (y, m, d) => `${y}/${p2(m)}/${p2(d)}`;
    const jdow = (y, m, d) => DOW[mod(j2d(y, m, d) + 1, 7)];   // JDN ⇒ روزِ هفته (۰ = یکشنبه)
    function today() {
        const t = window.IrTime ? IrTime.parts() : null;
        if (t && t.jy) return [t.jy, t.jm, t.jd];
        const n = new Date(Date.now() + (3.5 * 3600 + new Date().getTimezoneOffset() * 60) * 1000);
        return d2j(g2d(n.getFullYear(), n.getMonth() + 1, n.getDate()));
    }
    // «۱۴۰۵/۷/۹»، «1405-07-09»، «14050709» => [y, m, d] (یا null)
    function parseJ(s) {
        s = en(s).trim().replace(/[‎‏]/g, '');
        let m = s.match(/^(1[2-5]\d{2})\D+(\d{1,2})\D+(\d{1,2})$/) || s.match(/^(1[2-5]\d{2})(\d{2})(\d{2})$/);
        if (!m) { m = s.match(/^(\d{1,2})\D+(\d{1,2})\D+(1[2-5]\d{2})$/); if (m) m = [m[0], m[3], m[2], m[1]]; }
        if (!m) return null;
        const y = +m[1], mo = +m[2], d = +m[3];
        if (mo < 1 || mo > 12 || d < 1 || d > mLen(y, mo)) return null;
        return [y, mo, d];
    }
    const addDays = (y, m, d, n) => d2j(j2d(y, m, d) + n);
    // ماه به ماه با طولِ واقعیِ هر ماه: ۳۱ شهریور + ۱ ماه = ۳۰ مهر؛ ۳۰ بهمن + ۱ ماه = ۲۹ یا ۳۰ اسفند
    function addMonths(y, m, d, n) {
        let t = (m - 1) + n, yy = y + Math.floor(t / 12); t = mod(mod(t, 12) + 12, 12);
        const mm = t + 1;
        return [yy, mm, Math.min(d, mLen(yy, mm))];
    }

    // ---------------- عدد به حروف ----------------
    function words(n) {
        n = Math.floor(Math.abs(Number(n) || 0));
        if (!n) return 'صفر';
        const yk = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'], dh = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        const ds = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'], sd = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
        const sc = ['', ' هزار', ' میلیون', ' میلیارد', ' هزار میلیارد', ' میلیون میلیارد'];
        const three = x => { const p = []; const h = Math.floor(x / 100), r = x % 100; if (h) p.push(sd[h]); if (r >= 10 && r < 20) p.push(dh[r - 10]); else { if (r >= 20) p.push(ds[Math.floor(r / 10)]); if (r % 10) p.push(yk[r % 10]); } return p.join(' و '); };
        const out = []; let i = 0;
        while (n > 0 && i < sc.length) { const c = n % 1000; if (c) out.unshift(three(c) + sc[i]); n = Math.floor(n / 1000); i++; }
        return out.join(' و ');
    }

    // ---------------- ابزارها ----------------
    const GROUPS = [['own', 'اختصاصی', 'fa-star'], ['file', 'عکس و فایل', 'fa-photo-film'], ['calc', 'محاسبه و تاریخ', 'fa-calculator'], ['text', 'متن و اعتبارسنجی', 'fa-spell-check']];
    const TOOLS = [
        {k: 'pdate', t: 'تاریخِ قسط', d: 'تاریخ + روز/ماهِ هر نوع (هامرز، پایا، …)', ic: 'fa-calendar-plus', c: ['#7c3aed', '#db2777'], g: 'own'},
        {k: 'inst', t: 'محاسبه‌ی اقساط', d: 'مبلغ و سررسیدِ هر قسط با طولِ واقعیِ ماه‌ها', ic: 'fa-table-list', c: ['#059669', '#0d9488'], g: 'calc'},
        {k: 'money', t: 'ریال ⇄ تومان', d: 'تبدیل و نوشتنِ مبلغ به حروف', ic: 'fa-coins', c: ['#f59e0b', '#ea580c'], g: 'calc'},
        {k: 'calc', t: 'ماشین‌حساب', d: 'محاسبه، درصد و ارزش افزوده', ic: 'fa-calculator', c: ['#0ea5e9', '#6366f1'], g: 'calc'},
        {k: 'date', t: 'تاریخ', d: 'شمسی ⇄ میلادی، فاصله و افزودن', ic: 'fa-calendar-days', c: ['#6366f1', '#8b5cf6'], g: 'calc'},
        {k: 'img', t: 'کم‌کردنِ حجمِ عکس', d: 'کیفیت، اندازه یا حجمِ هدف', ic: 'fa-compress', c: ['#ec4899', '#f43f5e'], g: 'file'},
        {k: 'conv', t: 'تبدیلِ فرمتِ عکس', d: 'JPG، PNG و WebP', ic: 'fa-shuffle', c: ['#14b8a6', '#0ea5e9'], g: 'file'},
        {k: 'batch', t: 'حجمِ گروهیِ عکس‌ها', d: 'چند عکس یک‌جا؛ خروجیِ ZIP', ic: 'fa-images', c: ['#8b5cf6', '#ec4899'], g: 'file'},
        {k: 'img2pdf', t: 'عکس به PDF', d: 'چند عکس در یک فایلِ PDF', ic: 'fa-file-image', c: ['#ef4444', '#f97316'], g: 'file'},
        {k: 'pdf', t: 'ابزارهای PDF', d: 'ادغام، جداسازی، چرخش، کم‌حجم، عکس', ic: 'fa-file-pdf', c: ['#dc2626', '#9333ea'], g: 'file'},
        {k: 'check', t: 'بررسیِ کد و حساب', d: 'کد ملی، شناسه ملی، شبا و کارت', ic: 'fa-id-card', c: ['#0891b2', '#10b981'], g: 'text'},
        {k: 'text', t: 'اصلاحِ متن', d: 'ی و کِ عربی، اعداد، فاصله‌ها', ic: 'fa-spell-check', c: ['#475569', '#0f172a'], g: 'text'},
    ];
    const T = {boot: null, booting: null};
    function boot(force) {
        if (T.boot && !force) return Promise.resolve(T.boot);
        if (T.booting && !force) return T.booting;
        T.booting = api('boot').then(d => { if (d.ok) T.boot = d; T.booting = null; return d; });
        return T.booting;
    }
    const available = () => T.boot && T.boot.enabled ? TOOLS.filter(t => T.boot.tools[t.k]) : [];
    const S = () => (T.boot && T.boot.settings) || {};
    const titleOf = t => t.k === 'pdate' ? (S().pdate_title || t.t) : t.t;

    const CSS = `
    .tl{direction:rtl;--c1:#6366f1;--c2:#06b6d4}
    .tl-hero{position:relative;overflow:hidden;border-radius:26px;padding:22px 24px;color:#fff;background:linear-gradient(120deg,#312e81,#4f46e5 45%,#0891b2);box-shadow:0 20px 40px -24px rgba(49,46,129,.8);margin-bottom:16px}
    .tl-hero::before,.tl-hero::after{content:'';position:absolute;border-radius:50%;background:rgba(255,255,255,.09)}
    .tl-hero::before{width:260px;height:260px;left:-60px;top:-120px}.tl-hero::after{width:160px;height:160px;left:160px;bottom:-110px}
    .tl-hero h2{font-size:20px;font-weight:900;position:relative}.tl-hero p{font-size:11.5px;opacity:.85;margin-top:4px;position:relative}
    .tl-gh{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:900;color:#475569;margin:14px 2px 8px}
    .tl-gh i{color:#94a3b8}
    .tl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
    .tl-card{position:relative;overflow:hidden;border-radius:20px;padding:16px;background:#fff;border:1px solid #eef2f7;cursor:pointer;text-align:right;font-family:inherit;
        transition:transform .2s cubic-bezier(.2,.9,.3,1.2),box-shadow .2s,border-color .2s;display:flex;flex-direction:column;gap:10px;color:#0f172a}
    .tl-card::after{content:'';position:absolute;width:120px;height:120px;border-radius:50%;left:-40px;bottom:-60px;background:var(--c1);opacity:.08;transition:opacity .2s,transform .3s}
    .tl-card:hover{transform:translateY(-4px);box-shadow:0 18px 32px -18px rgba(15,23,42,.45);border-color:transparent}
    .tl-card:hover::after{opacity:.16;transform:scale(1.3)}
    .tl-ic{width:48px;height:48px;border-radius:16px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;flex:none;
        background:linear-gradient(135deg,var(--c1),var(--c2));box-shadow:0 10px 20px -10px var(--c1)}
    .tl-card b{font-size:13.5px;font-weight:900}.tl-card small{font-size:10.5px;color:#64748b;line-height:1.7}
    .tl.compact .tl-grid{grid-template-columns:repeat(3,1fr);gap:8px}
    .tl.compact .tl-card{padding:12px 6px 10px;align-items:center;text-align:center;gap:7px;border-radius:18px}
    .tl.compact .tl-card .tl-ic{width:42px;height:42px;border-radius:14px;font-size:17px}
    .tl.compact .tl-card b{font-size:10.5px;line-height:1.5}.tl.compact .tl-card small{display:none}
    .tl.compact .tl-gh{margin:10px 2px 6px;font-size:10.5px}
    .tl-strip{display:flex;gap:6px;overflow-x:auto;padding:2px 2px 10px;scrollbar-width:thin}
    .tl-chip{flex:none;display:flex;align-items:center;gap:7px;border:0;border-radius:14px;padding:5px 11px 5px 6px;background:#f1f5f9;color:#475569;font-weight:800;font-size:11px;font-family:inherit;cursor:pointer;transition:all .2s}
    .tl-chip i{width:24px;height:24px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10.5px;background:linear-gradient(135deg,var(--c1),var(--c2))}
    .tl-chip:hover{background:#e2e8f0}
    .tl-chip.on{background:linear-gradient(135deg,var(--c1),var(--c2));color:#fff;box-shadow:0 8px 16px -8px var(--c1)}
    .tl-chip.on i{background:rgba(255,255,255,.22)}
    .tl-chip.home i{background:#334155}
    .tl.compact .tl-chip{padding:4px;border-radius:12px}.tl.compact .tl-chip span{display:none}
    .tl.compact .tl-chip.on span{display:inline;padding-left:6px}
    .tl-view{background:#fff;border:1px solid #eef2f7;border-radius:24px;padding:18px;box-shadow:0 10px 30px -24px rgba(15,23,42,.5)}
    .tl.page .tl-view{max-width:860px;margin:0 auto}
    .tl.compact .tl-view{padding:12px;border-radius:18px;box-shadow:none}
    .tl-vh{display:flex;align-items:center;gap:12px;margin-bottom:14px}
    .tl-vh .tl-ic{width:44px;height:44px;font-size:18px}
    .tl-vh b{display:block;font-size:15px;font-weight:900;color:#0f172a}.tl-vh small{font-size:10.5px;color:#64748b}
    .tl.compact .tl-vh .tl-ic{width:36px;height:36px;font-size:15px;border-radius:12px}.tl.compact .tl-vh b{font-size:13px}
    .tl-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
    .tl-f{flex:1;min-width:130px}
    .tl-f>label,.tl-lbl{display:block;font-size:10.5px;font-weight:800;color:#64748b;margin-bottom:4px}
    .tl-in{width:100%;border:1.5px solid #e2e8f0;background:#fff;border-radius:13px;padding:9px 12px;font-weight:600;font-size:13px;font-family:inherit;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s}
    .tl-in:focus{border-color:var(--c1);box-shadow:0 0 0 4px color-mix(in srgb,var(--c1) 14%,transparent)}
    .tl-in.big{font-size:20px;font-weight:900;padding:12px 14px;letter-spacing:.5px}
    textarea.tl-in{resize:vertical;min-height:110px;line-height:1.9}
    .tl-btn{border:0;border-radius:13px;padding:9px 16px;font-weight:900;font-size:12px;font-family:inherit;cursor:pointer;color:#fff;background:linear-gradient(135deg,var(--c1),var(--c2));box-shadow:0 8px 16px -10px var(--c1);transition:transform .15s,opacity .15s;white-space:nowrap}
    .tl-btn:hover{transform:translateY(-1px)}.tl-btn:active{transform:scale(.96)}.tl-btn[disabled]{opacity:.5;pointer-events:none}
    .tl-btn.ghost{background:#f1f5f9;color:#334155;box-shadow:none}.tl-btn.ghost:hover{background:#e2e8f0}
    .tl-btn.sm{padding:6px 10px;font-size:11px;border-radius:10px}
    .tl-seg{display:inline-flex;flex-wrap:wrap;gap:4px;background:#f1f5f9;border-radius:14px;padding:4px}
    .tl-seg button{border:0;background:transparent;border-radius:10px;padding:6px 12px;font-weight:800;font-size:11.5px;font-family:inherit;color:#475569;cursor:pointer}
    .tl-seg button.on{background:#fff;color:var(--c1);box-shadow:0 2px 8px -3px rgba(15,23,42,.3)}
    .tl-res{margin-top:12px;border-radius:20px;padding:16px;color:#fff;background:linear-gradient(135deg,var(--c1),var(--c2));position:relative;overflow:hidden;box-shadow:0 14px 28px -16px var(--c1)}
    .tl-res::after{content:'';position:absolute;width:160px;height:160px;border-radius:50%;left:-50px;top:-80px;background:rgba(255,255,255,.12)}
    .tl-res .big{font-size:28px;font-weight:900;letter-spacing:1px;position:relative}
    .tl-res small{display:block;font-size:11px;opacity:.85;position:relative}
    .tl-res .tl-btn{background:rgba(255,255,255,.2);box-shadow:none;position:relative}
    .tl-res.empty{background:#f8fafc;color:#94a3b8;box-shadow:none;border:1.5px dashed #e2e8f0}
    .tl-res.empty::after{display:none}
    .tl-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;margin-top:10px}
    .tl-tbl th{font-size:10.5px;color:#64748b;font-weight:800;text-align:right;padding:7px 8px;background:#f8fafc}
    .tl-tbl th:first-child{border-radius:0 10px 10px 0}.tl-tbl th:last-child{border-radius:10px 0 0 10px}
    .tl-tbl td{padding:7px 8px;border-bottom:1px solid #f1f5f9}
    .tl-drop{border:2px dashed #cbd5e1;border-radius:20px;padding:22px 14px;text-align:center;cursor:pointer;transition:all .2s;background:#f8fafc;color:#64748b;font-size:12px;font-weight:700}
    .tl-drop i{display:block;font-size:26px;margin-bottom:8px;color:var(--c1)}
    .tl-drop:hover,.tl-drop.over{border-color:var(--c1);background:color-mix(in srgb,var(--c1) 6%,#fff)}
    .tl-files{display:flex;flex-direction:column;gap:6px;margin-top:10px}
    .tl-file{display:flex;align-items:center;gap:10px;border:1px solid #eef2f7;border-radius:14px;padding:7px 10px;font-size:11.5px;background:#fff}
    .tl-file img{width:38px;height:38px;border-radius:10px;object-fit:cover;flex:none;background:#f1f5f9}
    .tl-file .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:700}
    .tl-file .sz{color:#64748b;font-size:10.5px;white-space:nowrap}
    .tl-file button{border:0;background:#f1f5f9;border-radius:8px;width:26px;height:26px;cursor:pointer;color:#475569;font-size:11px;flex:none}
    .tl-file button:hover{background:#e2e8f0}
    .tl-bar{height:7px;border-radius:9px;background:#eef2f7;overflow:hidden;margin-top:10px}
    .tl-bar i{display:block;height:100%;width:0;border-radius:9px;background:linear-gradient(90deg,var(--c1),var(--c2));transition:width .25s}
    .tl-range{width:100%;accent-color:var(--c1)}
    .tl-chips{display:flex;flex-wrap:wrap;gap:6px}
    .tl-tag{border:1.5px solid #e2e8f0;border-radius:999px;padding:6px 13px;font-weight:800;font-size:11.5px;font-family:inherit;cursor:pointer;background:#fff;color:#334155;transition:all .15s}
    .tl-tag:hover{border-color:var(--c1)}
    .tl-tag.on{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--c1),var(--c2));box-shadow:0 6px 14px -8px var(--c1)}
    .tl-tag small{opacity:.7;font-weight:700;margin-right:3px}
    .tl-note{font-size:10.5px;color:#64748b;line-height:1.9;margin-top:8px}
    .tl-ok{color:#059669;font-weight:900}.tl-bad{color:#e11d48;font-weight:900}
    .tl-cmp{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
    .tl-cmp>div{border-radius:16px;background:#f8fafc;padding:10px;text-align:center;font-size:11px;color:#64748b}
    .tl-cmp img{max-width:100%;max-height:200px;border-radius:12px;display:block;margin:0 auto 6px}
    .tl-cmp b{display:block;color:#0f172a;font-size:13px}
    .tl-empty{text-align:center;color:#94a3b8;font-size:12px;padding:30px 10px;line-height:2}
    .tl-calc-k{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:10px;direction:ltr}
    .tl-calc-k button{border:0;border-radius:13px;padding:12px 0;font-weight:900;font-size:15px;font-family:inherit;background:#f1f5f9;color:#0f172a;cursor:pointer}
    .tl-calc-k button:hover{background:#e2e8f0}.tl-calc-k button.op{background:color-mix(in srgb,var(--c1) 14%,#fff);color:var(--c1)}
    .tl-calc-k button.eq{background:linear-gradient(135deg,var(--c1),var(--c2));color:#fff}
    @media (max-width:640px){.tl-grid{grid-template-columns:repeat(2,1fr)}.tl-view{padding:12px}}
    `;
    function css() { if (document.getElementById('tl-css')) return; const s = document.createElement('style'); s.id = 'tl-css'; s.textContent = CSS; document.head.appendChild(s); }
    const cvars = t => `--c1:${t.c[0]};--c2:${t.c[1]}`;

    // ---------------- قالبِ اصلی: شبکه‌ی ابزارها یا یک ابزار با نوارِ دکمه‌ها ----------------
    // opts: {compact (جعبه‌ابزار), page (صفحه‌ی ابزارها), open (کلیدِ ابزارِ اولیه)}
    async function mount(el, opts = {}) {
        css();
        el.innerHTML = '<div class="tl-empty"><i class="fas fa-spinner fa-spin"></i></div>';
        const d = await boot();
        if (!d || !d.ok) { el.innerHTML = `<div class="tl-empty">${esc((d && d.error) || 'ابزارها بارگذاری نشد.')}</div>`; return; }
        const st = {tool: opts.open || null};
        const root = document.createElement('div');
        root.className = 'tl ' + (opts.compact ? 'compact' : 'page');
        el.innerHTML = ''; el.appendChild(root);
        const draw = () => {
            const list = available();
            if (!list.length) { root.innerHTML = '<div class="tl-empty"><i class="fas fa-toolbox" style="font-size:26px;display:block;margin-bottom:8px"></i>ابزاری برای شما فعال نیست.<br>از مدیرِ کل بخواهید در «دسترسی‌ها» روشنش کند.</div>'; return; }
            if (st.tool && !list.some(t => t.k === st.tool)) st.tool = null;
            if (!st.tool) {
                root.innerHTML = (opts.page ? `<div class="tl-hero"><h2><i class="fas fa-toolbox ml-2"></i>ابزارها</h2><p>ابزارهای روزمره در یک جا: عکس و PDF، تاریخ و اقساط، مبلغ و متن. پردازشِ عکس‌ها داخلِ همین مرورگر انجام می‌شود.</p></div>` : '')
                    + GROUPS.map(([g, gt, gi]) => { const ts = list.filter(t => t.g === g); return ts.length ? `<div class="tl-gh"><i class="fas ${gi}"></i>${gt}</div><div class="tl-grid">${ts.map(t =>
                        `<button type="button" class="tl-card" style="${cvars(t)}" data-k="${t.k}"><span class="tl-ic"><i class="fas ${t.ic}"></i></span><b>${esc(titleOf(t))}</b><small>${esc(t.d)}</small></button>`).join('')}</div>` : ''; }).join('');
                root.querySelectorAll('[data-k]').forEach(b => b.onclick = () => { st.tool = b.dataset.k; draw(); });
                return;
            }
            const t = list.find(x => x.k === st.tool);
            root.innerHTML = `<div class="tl-strip"><button type="button" class="tl-chip home" data-home title="همه‌ی ابزارها"><i class="fas fa-grip"></i><span>همه</span></button>
                ${list.map(x => `<button type="button" class="tl-chip ${x.k === t.k ? 'on' : ''}" style="${cvars(x)}" data-k="${x.k}" title="${esc(titleOf(x))}"><i class="fas ${x.ic}"></i><span>${esc(titleOf(x))}</span></button>`).join('')}</div>
                <div class="tl-view" style="${cvars(t)}"><div class="tl-vh"><span class="tl-ic"><i class="fas ${t.ic}"></i></span><div><b>${esc(titleOf(t))}</b><small>${esc(t.d)}</small></div></div><div data-body></div></div>`;
            root.querySelector('[data-home]').onclick = () => { st.tool = null; draw(); };
            root.querySelectorAll('.tl-strip [data-k]').forEach(b => b.onclick = () => { st.tool = b.dataset.k; draw(); });
            const on = root.querySelector('.tl-chip.on'); if (on) on.scrollIntoView({block: 'nearest', inline: 'center'});
            try { (IMPL[t.k] || (b => { b.innerHTML = ''; }))(root.querySelector('[data-body]'), opts); }
            catch (e) { root.querySelector('[data-body]').innerHTML = `<div class="tl-empty">خطا: ${esc(e.message)}</div>`; }
        };
        draw();
        return {open: k => { st.tool = k; draw(); }};
    }

    // ---------------- ورودیِ فایل (کشیدن یا انتخاب) ----------------
    function dropzone(box, {multiple, accept, label, onFiles}) {
        box.innerHTML = `<div class="tl-drop" tabindex="0"><i class="fas fa-cloud-arrow-up"></i>${label}<br><small style="font-weight:600;color:#94a3b8">کلیک کنید یا فایل را اینجا بکشید</small>
            <input type="file" hidden ${multiple ? 'multiple' : ''} accept="${accept}"></div>`;
        const dz = box.firstElementChild, inp = dz.querySelector('input');
        dz.onclick = () => inp.click();
        inp.onchange = () => { const f = [...inp.files]; inp.value = ''; if (f.length) onFiles(f); };
        dz.ondragover = e => { e.preventDefault(); dz.classList.add('over'); };
        dz.ondragleave = () => dz.classList.remove('over');
        dz.ondrop = e => { e.preventDefault(); dz.classList.remove('over'); const f = [...e.dataTransfer.files]; if (f.length) onFiles(multiple ? f : [f[0]]); };
    }

    // ---------------- عکس: خواندن و فشرده‌سازی در مرورگر ----------------
    async function loadImage(file) {
        if (window.createImageBitmap) {
            try { return await createImageBitmap(file, {imageOrientation: 'from-image'}); } catch (e) {}
            try { return await createImageBitmap(file); } catch (e) {}
        }
        return await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('این فایل عکسِ قابلِ خواندن نیست (فرمت‌های JPG، PNG، WebP، GIF و BMP).')); i.src = URL.createObjectURL(file); });
    }
    const MIME = {jpeg: 'image/jpeg', webp: 'image/webp', png: 'image/png'};
    const EXT = {jpeg: 'jpg', webp: 'webp', png: 'png'};
    function canvasOf(img, maxSide, fmt) {
        const w0 = img.width, h0 = img.height, k = maxSide && Math.max(w0, h0) > maxSide ? maxSide / Math.max(w0, h0) : 1;
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(w0 * k)); c.height = Math.max(1, Math.round(h0 * k));
        const g = c.getContext('2d');
        if (fmt === 'jpeg') { g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); }   // پس‌زمینه‌ی شفاف در JPG سفید شود
        g.imageSmoothingQuality = 'high';
        g.drawImage(img, 0, 0, c.width, c.height);
        return c;
    }
    const toBlob = (c, fmt, q) => new Promise(res => c.toBlob(b => res(b), MIME[fmt], q));
    // targetKB: حجمِ هدف؛ کیفیت (و در صورتِ نیاز اندازه) کم می‌شود تا به آن برسد
    async function encode(file, {maxSide = 0, quality = .75, fmt = 'jpeg', targetKB = 0}) {
        const img = await loadImage(file);
        let side = maxSide || Math.max(img.width, img.height), c = canvasOf(img, side, fmt);
        let blob = await toBlob(c, fmt, quality);
        if (blob && blob.type !== MIME[fmt]) fmt = blob.type === 'image/png' ? 'png' : 'jpeg';   // مرورگر WebP نمی‌سازد
        if (targetKB && fmt !== 'png') {
            const target = targetKB * 1024;
            for (let round = 0; round < 6 && blob.size > target; round++) {
                let lo = .3, hi = quality, best = null;
                for (let i = 0; i < 7; i++) { const q = (lo + hi) / 2, b = await toBlob(c, fmt, q); if (b.size <= target) { best = b; lo = q; } else hi = q; }
                if (best) { blob = best; break; }
                side = Math.round(Math.max(c.width, c.height) * .82); c = canvasOf(img, side, fmt); blob = await toBlob(c, fmt, .3);
            }
        }
        return {blob, w: c.width, h: c.height, fmt, ow: img.width, oh: img.height};
    }

    // ---------------- ZIP و PDF (بدونِ کتابخانه‌ی بیرونی) ----------------
    const CRC = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
    function crc32(u8) { let c = 0xFFFFFFFF; for (let i = 0; i < u8.length; i++) c = CRC[(c ^ u8[i]) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }
    // files: [{name, data: Uint8Array}] => Blob (بدونِ فشرده‌سازی؛ عکس‌ها خودشان فشرده‌اند)
    function makeZip(files) {
        const enc = new TextEncoder(), parts = [], central = []; let off = 0;
        const used = {};
        files.forEach(f => {
            let name = f.name; while (used[name]) name = name.replace(/(\.[^.]*)?$/, m => ' (' + (++used[f.name]) + ')' + m); used[name] = 1;
            const nb = enc.encode(name), crc = crc32(f.data), sz = f.data.length;
            const h = new DataView(new ArrayBuffer(30));
            h.setUint32(0, 0x04034b50, true); h.setUint16(4, 20, true); h.setUint16(6, 0x0800, true); h.setUint16(8, 0, true);
            h.setUint32(14, crc, true); h.setUint32(18, sz, true); h.setUint32(22, sz, true); h.setUint16(26, nb.length, true);
            parts.push(new Uint8Array(h.buffer), nb, f.data);
            const c = new DataView(new ArrayBuffer(46));
            c.setUint32(0, 0x02014b50, true); c.setUint16(4, 20, true); c.setUint16(6, 20, true); c.setUint16(8, 0x0800, true);
            c.setUint32(16, crc, true); c.setUint32(20, sz, true); c.setUint32(24, sz, true); c.setUint16(28, nb.length, true); c.setUint32(42, off, true);
            central.push(new Uint8Array(c.buffer), nb);
            off += 30 + nb.length + sz;
        });
        const cs = central.reduce((a, x) => a + x.length, 0), e = new DataView(new ArrayBuffer(22));
        e.setUint32(0, 0x06054b50, true); e.setUint16(8, files.length, true); e.setUint16(10, files.length, true); e.setUint32(12, cs, true); e.setUint32(16, off, true);
        return new Blob([...parts, ...central, new Uint8Array(e.buffer)], {type: 'application/zip'});
    }
    // pages: [{jpeg: Uint8Array, w, h}]؛ size: 'a4' (عکس داخلِ A4 با حاشیه) یا 'fit' (اندازه‌ی خودِ عکس)
    function makePdf(pages, size, margin) {
        const enc = new TextEncoder(), chunks = [], offs = []; let len = 0;
        const put = x => { const u = typeof x === 'string' ? enc.encode(x) : x; chunks.push(u); len += u.length; };
        const obj = (n, body) => { offs[n] = len; put(`${n} 0 obj\n`); body(); put('\nendobj\n'); };
        put('%PDF-1.4\n'); put(new Uint8Array([37, 226, 227, 207, 211, 10]));   // سطرِ دوم: بایت‌های دودویی (استانداردِ PDF)
        const n = pages.length, kids = [];
        for (let i = 0; i < n; i++) kids.push(`${3 + i * 3} 0 R`);
        obj(1, () => put('<< /Type /Catalog /Pages 2 0 R >>'));
        obj(2, () => put(`<< /Type /Pages /Count ${n} /Kids [${kids.join(' ')}] >>`));
        pages.forEach((p, i) => {
            const pn = 3 + i * 3, xn = pn + 1, cn = pn + 2;
            let PW, PH, dw, dh, x, y;
            if (size === 'fit') { PW = p.w * .75; PH = p.h * .75; dw = PW; dh = PH; x = 0; y = 0; }
            else {
                const land = p.w > p.h; PW = land ? 842 : 595; PH = land ? 595 : 842;
                const m = margin || 0, k = Math.min((PW - 2 * m) / p.w, (PH - 2 * m) / p.h);
                dw = p.w * k; dh = p.h * k; x = (PW - dw) / 2; y = (PH - dh) / 2;
            }
            const content = `q ${dw.toFixed(2)} 0 0 ${dh.toFixed(2)} ${x.toFixed(2)} ${y.toFixed(2)} cm /Im0 Do Q`;
            obj(pn, () => put(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PW.toFixed(2)} ${PH.toFixed(2)}] /Resources << /XObject << /Im0 ${xn} 0 R >> >> /Contents ${cn} 0 R >>`));
            obj(xn, () => { put(`<< /Type /XObject /Subtype /Image /Width ${p.w} /Height ${p.h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${p.jpeg.length} >>\nstream\n`); put(p.jpeg); put('\nendstream'); });
            obj(cn, () => put(`<< /Length ${content.length} >>\nstream\n${content}\nendstream`));
        });
        const total = 3 + n * 3, xref = len;
        put(`xref\n0 ${total}\n0000000000 65535 f \n`);
        for (let i = 1; i < total; i++) put(String(offs[i]).padStart(10, '0') + ' 00000 n \n');
        put(`trailer\n<< /Size ${total} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`);
        return new Blob(chunks, {type: 'application/pdf'});
    }
    const u8 = async b => new Uint8Array(await b.arrayBuffer());

    // ---------------- پیاده‌سازیِ ابزارها ----------------
    const IMPL = {};

    // ===== تاریخِ قسط (نسخه‌ی وبِ برنامه‌ی «دریافت تاریخ اقساط پارسیان») =====
    IMPL.pdate = b => {
        const types = (S().pdate_types || []).filter(t => t && t.name);
        const LSK = 'tl:pdate';
        let sel = 0; try { sel = Math.min(types.length - 1, Math.max(0, +(localStorage.getItem(LSK) || 0))); } catch (e) {}
        const unitFa = t => t.unit === 'month' ? 'ماه' : 'روز';
        b.innerHTML = `
            <div class="tl-f"><label>تاریخِ شمسی</label>
              <div style="display:flex;gap:6px"><input class="tl-in big" data-f="d" dir="ltr" placeholder="1405/07/13" autocomplete="off" style="text-align:center">
                <button type="button" class="tl-btn ghost" data-a="paste" title="چسباندن از کلیپ‌بورد"><i class="fas fa-paste"></i></button>
                <button type="button" class="tl-btn ghost" data-a="today" title="امروز"><i class="fas fa-calendar-day"></i></button></div></div>
            <div class="tl-lbl" style="margin-top:12px">نوع</div>
            ${types.length ? `<div class="tl-chips" data-types>${types.map((t, i) => `<button type="button" class="tl-tag ${i === sel ? 'on' : ''}" data-i="${i}">${esc(t.name)}<small>+${fa(t.n)} ${unitFa(t)}</small></button>`).join('')}</div>`
                : '<p class="tl-note">هنوز نوعی تعریف نشده؛ مدیرِ کل از «تنظیمات ← ابزارها» اضافه می‌کند.</p>'}
            <div data-o></div>
            <div data-all></div>
            <p class="tl-note"><i class="fas fa-circle-info ml-1"></i>«روز» یعنی همان تعداد روزِ تقویمی؛ «ماه» یعنی ماه به ماه با طولِ واقعی (۶ ماهِ اول ۳۱، بعدی‌ها ۳۰ و اسفند ۲۹ یا ۳۰ روز). خروجی با رقمِ انگلیسی کپی می‌شود.</p>`;
        const inp = b.querySelector('[data-f="d"]'), o = b.querySelector('[data-o]'), all = b.querySelector('[data-all]');
        const calc = (p, t) => t.unit === 'month' ? addMonths(...p, t.n) : addDays(...p, t.n);
        const run = () => {
            const p = parseJ(inp.value);
            inp.style.borderColor = inp.value.trim() && !p ? '#f43f5e' : '';
            if (!p || !types.length) { o.innerHTML = `<div class="tl-res empty"><small>${inp.value.trim() && !p ? 'تاریخ معتبر نیست (مثل 1405/07/13).' : 'تاریخ را وارد کنید یا بچسبانید.'}</small></div>`; all.innerHTML = ''; return; }
            const t = types[sel], r = calc(p, t), s = jstr(...r);
            o.innerHTML = `<div class="tl-res"><small>${esc(t.name)} · ${fa(jdow(...p))} ${fa(p[2])} ${MONTHS[p[1] - 1]} + ${fa(t.n)} ${unitFa(t)}</small>
                <div style="display:flex;align-items:center;gap:10px;margin-top:6px"><div class="big" dir="ltr">${fa(s)}</div>
                <button type="button" class="tl-btn sm" data-copy="${s}" style="margin-right:auto"><i class="fas fa-copy ml-1"></i>کپی</button></div>
                <small style="margin-top:4px">${jdow(...r)} ${fa(r[2])} ${MONTHS[r[1] - 1]} ${fa(r[0])}</small></div>`;
            all.innerHTML = types.length > 1 ? `<table class="tl-tbl no-count"><thead><tr><th>نوع</th><th>افزوده</th><th>تاریخ</th><th></th></tr></thead><tbody>${types.map((x, i) => { const rr = jstr(...calc(p, x));
                return `<tr ${i === sel ? 'style="background:color-mix(in srgb,var(--c1) 7%,#fff)"' : ''}><td style="font-weight:800">${esc(x.name)}</td><td>${fa(x.n)} ${unitFa(x)}</td><td dir="ltr" style="text-align:right;font-weight:900">${fa(rr)}</td>
                <td style="text-align:left"><button type="button" class="tl-btn ghost sm" data-copy="${rr}"><i class="fas fa-copy"></i></button></td></tr>`; }).join('')}</tbody></table>` : '';
            b.querySelectorAll('[data-copy]').forEach(x => x.onclick = () => copy(x.dataset.copy, 'تاریخ کپی شد: ' + fa(x.dataset.copy)));
        };
        inp.addEventListener('input', run);
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { const p = parseJ(inp.value); if (p && types.length) copy(jstr(...calc(p, types[sel])), 'تاریخ کپی شد.'); } });
        b.querySelector('[data-a="paste"]').onclick = async () => { const t = await pasteText(); if (t != null) { inp.value = t.trim(); run(); } else inp.focus(); };
        b.querySelector('[data-a="today"]').onclick = () => { inp.value = jstr(...today()); run(); };
        b.querySelectorAll('[data-types] [data-i]').forEach(x => x.onclick = () => { sel = +x.dataset.i; try { localStorage.setItem(LSK, sel); } catch (e) {} b.querySelectorAll('[data-types] [data-i]').forEach(y => y.classList.toggle('on', y === x)); run(); });
        run(); setTimeout(() => inp.focus(), 50);
    };

    // ===== محاسبه‌ی اقساط =====
    IMPL.inst = b => {
        const D = Object.assign({count: 9, offset: 1, round: 1000}, S().inst || {});
        b.innerHTML = `
            <div class="tl-row"><div class="tl-f"><label>مبلغِ کل (ریال)</label><input class="tl-in" data-f="total" inputmode="numeric" placeholder="مثلاً ۳۳۲٬۳۳۳٬۰۰۰"></div>
              <div class="tl-f"><label>پیش‌پرداخت (ریال)</label><input class="tl-in" data-f="pre" inputmode="numeric" placeholder="اختیاری"></div></div>
            <div class="tl-row" style="margin-top:8px"><div class="tl-f" style="min-width:90px"><label>تعدادِ قسط</label><input class="tl-in" data-f="cnt" inputmode="numeric" value="${fa(D.count)}"></div>
              <div class="tl-f"><label>تاریخِ شروع (صدور)</label><input class="tl-in" data-f="d0" dir="ltr" value="${fa(jstr(...today()))}"></div>
              <div class="tl-f" style="min-width:90px"><label>قسطِ اول چند ماه بعد</label><input class="tl-in" data-f="off" inputmode="numeric" value="${fa(D.offset)}"></div>
              <div class="tl-f" style="min-width:120px"><label>رُند کردن</label><select class="tl-in" data-f="round">${[[1, 'بدون'], [1000, 'هزار ریال'], [10000, 'ده هزار ریال'], [100000, 'صد هزار ریال']].map(([v, t]) => `<option value="${v}" ${+D.round === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div></div>
            <div style="margin-top:8px" class="tl-seg" data-rem><button data-v="first" class="on">باقی‌مانده روی قسطِ اول</button><button data-v="last">روی قسطِ آخر</button></div>
            <div data-o></div>`;
        const g = f => b.querySelector(`[data-f="${f}"]`);
        let rem = 'first';
        const run = () => {
            ['total', 'pre'].forEach(f => { const n = num(g(f).value); if (g(f).value) g(f).value = n ? money(n) : ''; });
            const total = num(g('total').value), pre = Math.min(total, num(g('pre').value)), cnt = Math.max(1, Math.min(60, num(g('cnt').value) || 1)), off = num(g('off').value), rnd = +g('round').value || 1;
            const o = b.querySelector('[data-o]');
            const d0 = parseJ(g('d0').value);
            if (!total || !d0) { o.innerHTML = `<div class="tl-res empty" style="margin-top:12px"><small>${!d0 && g('d0').value.trim() ? 'تاریخِ شروع معتبر نیست.' : 'مبلغ را وارد کنید.'}</small></div>`; return; }
            const rest = total - pre, per = Math.floor(rest / cnt / rnd) * rnd, extra = rest - per * cnt;
            const rows = [];
            for (let i = 0; i < cnt; i++) {
                const dt = addMonths(...d0, off + i), amt = per + (rem === 'first' ? (i === 0 ? extra : 0) : (i === cnt - 1 ? extra : 0));
                rows.push([i + 1, dt, amt]);
            }
            const txt = rows.map(r => `${r[0]}\t${jstr(...r[1])}\t${r[2]}`).join('\n');
            o.innerHTML = `<div class="tl-res"><small>${pre ? `پیش‌پرداخت ${money(pre)} + ` : ''}${fa(cnt)} قسط</small><div class="big">${money(per)} <span style="font-size:13px">ریال</span></div>
                <small>هر قسط (${rem === 'first' ? 'قسطِ اول' : 'قسطِ آخر'}: ${money(per + extra)}) · جمع: ${money(total)} ریال</small></div>
                <table class="tl-tbl no-count"><thead><tr><th>#</th><th>سررسید</th><th>روز</th><th>مبلغ (ریال)</th></tr></thead><tbody>
                ${pre ? `<tr><td>—</td><td dir="ltr" style="text-align:right">${fa(jstr(...d0))}</td><td>${jdow(...d0)}</td><td style="font-weight:900">${money(pre)} <small style="color:#64748b">پیش‌پرداخت</small></td></tr>` : ''}
                ${rows.map(r => `<tr><td>${fa(r[0])}</td><td dir="ltr" style="text-align:right;font-weight:700">${fa(jstr(...r[1]))}</td><td style="color:#64748b">${jdow(...r[1])}</td><td style="font-weight:900">${money(r[2])}</td></tr>`).join('')}</tbody></table>
                <div style="display:flex;gap:6px;margin-top:10px"><button type="button" class="tl-btn ghost sm" data-a="cp"><i class="fas fa-copy ml-1"></i>کپیِ جدول (برای اکسل)</button></div>`;
            o.querySelector('[data-a="cp"]').onclick = () => copy('قسط\tسررسید\tمبلغ\n' + txt, 'جدولِ اقساط کپی شد.');
        };
        b.querySelectorAll('input,select').forEach(x => x.addEventListener('input', run));
        b.querySelectorAll('[data-rem] button').forEach(x => x.onclick = () => { rem = x.dataset.v; b.querySelectorAll('[data-rem] button').forEach(y => y.classList.toggle('on', y === x)); run(); });
        run();
    };

    // ===== ریال ⇄ تومان و به حروف =====
    IMPL.money = b => {
        b.innerHTML = `<div class="tl-seg" data-unit><button data-v="r" class="on">ورودی به ریال</button><button data-v="t">ورودی به تومان</button></div>
            <input class="tl-in big" data-f="a" inputmode="numeric" placeholder="مبلغ…" style="margin-top:10px"><div data-o></div>`;
        let unit = 'r';
        const inp = b.querySelector('[data-f="a"]'), o = b.querySelector('[data-o]');
        const run = () => {
            const n = num(inp.value); inp.value = n ? money(n) : '';
            if (!n) { o.innerHTML = '<div class="tl-res empty"><small>مبلغ را وارد کنید.</small></div>'; return; }
            const r = unit === 'r' ? n : n * 10, t = unit === 'r' ? r / 10 : n;
            const row = (lbl, v, w) => `<div style="position:relative;margin-top:8px"><small>${lbl}</small><div style="display:flex;align-items:center;gap:8px"><b style="font-size:22px;font-weight:900">${money(v)}</b>
                <button type="button" class="tl-btn sm" data-copy="${Math.round(v)}" style="margin-right:auto"><i class="fas fa-copy"></i></button></div>
                <small style="margin-top:2px">${esc(w)}</small><button type="button" class="tl-btn sm" data-copy="${esc(w)}" style="margin-top:4px"><i class="fas fa-copy ml-1"></i>کپیِ به حروف</button></div>`;
            o.innerHTML = `<div class="tl-res">${row('ریال', r, words(r) + ' ریال')}${row('تومان', t, words(t) + ' تومان' + (t % 1 ? ' (و ' + fa(Math.round(t % 1 * 10)) + ' ریال)' : ''))}</div>`;
            o.querySelectorAll('[data-copy]').forEach(x => x.onclick = () => copy(x.dataset.copy));
        };
        inp.addEventListener('input', run);
        b.querySelectorAll('[data-unit] button').forEach(x => x.onclick = () => { unit = x.dataset.v; b.querySelectorAll('[data-unit] button').forEach(y => y.classList.toggle('on', y === x)); run(); });
        run(); setTimeout(() => inp.focus(), 50);
    };

    // ===== ماشین‌حساب =====
    IMPL.calc = b => {
        b.innerHTML = `<input class="tl-in big" data-f="e" dir="ltr" placeholder="مثلاً 2500000*9 + 15%" style="text-align:left">
            <div data-o></div>
            <div class="tl-calc-k">${['7', '8', '9', '/', '4', '5', '6', '*', '1', '2', '3', '-', '0', '000', '.', '+', '(', ')', '%', 'C'].map(k => `<button type="button" class="${'+-*/%()'.includes(k) ? 'op' : ''}" data-k="${k}">${k === '*' ? '×' : k === '/' ? '÷' : k}</button>`).join('')}
              <button type="button" class="eq" data-k="=" style="grid-column:span 4">=</button></div>
            <div class="tl-row" style="margin-top:14px"><div class="tl-f"><label>مبلغ برای ارزش افزوده</label><input class="tl-in" data-f="v" inputmode="numeric" placeholder="ریال"></div>
              <div class="tl-f" style="min-width:80px;max-width:110px"><label>نرخ ٪</label><input class="tl-in" data-f="vr" inputmode="numeric" value="${fa(10)}"></div></div><div data-ov class="tl-note"></div>
            <div class="tl-row" style="margin-top:10px"><div class="tl-f"><label>از مبلغِ قبلی</label><input class="tl-in" data-f="p1" inputmode="numeric"></div><div class="tl-f"><label>به مبلغِ جدید</label><input class="tl-in" data-f="p2" inputmode="numeric"></div></div><div data-op class="tl-note"></div>
            <div data-h class="tl-note"></div>`;
        const e = b.querySelector('[data-f="e"]'), o = b.querySelector('[data-o]'), hist = [];
        const evalx = s => {
            s = en(s).replace(/[×x]/g, '*').replace(/÷/g, '/').replace(/,|٬/g, '').replace(/\s+/g, '');
            if (!s || !/^[\d+\-*/().%]+$/.test(s)) return null;
            // «a + 15%» = a + 15٪ِ a ؛ «200*15%» = ۱۵٪ِ ۲۰۰
            s = s.replace(/([\d.)]+)([+\-])(\d+(?:\.\d+)?)%/g, '($1)*(1$2$3/100)').replace(/(\d+(?:\.\d+)?)%/g, '($1/100)');
            try { const v = Function('"use strict";return (' + s + ')')(); return isFinite(v) ? v : null; } catch (x) { return null; }
        };
        const show = () => { const v = evalx(e.value); o.innerHTML = v == null ? (e.value.trim() ? '<div class="tl-res empty"><small>عبارت کامل نیست.</small></div>' : '') :
            `<div class="tl-res"><small>نتیجه</small><div style="display:flex;align-items:center;gap:8px"><div class="big">${fa((Math.round(v * 1e6) / 1e6).toLocaleString('en-US', {maximumFractionDigits: 6}))}</div><button type="button" class="tl-btn sm" data-copy="${Math.round(v * 1e6) / 1e6}" style="margin-right:auto"><i class="fas fa-copy"></i></button></div>${Number.isInteger(v) && Math.abs(v) < 1e15 ? `<small>${esc(words(v))}</small>` : ''}</div>`;
            const c = o.querySelector('[data-copy]'); if (c) c.onclick = () => copy(c.dataset.copy); };
        const commit = () => { const v = evalx(e.value); if (v == null) return; hist.unshift(`${fa(e.value)} = <b>${fa((Math.round(v * 1e6) / 1e6).toLocaleString('en-US'))}</b>`); hist.splice(6); e.value = String(Math.round(v * 1e6) / 1e6); show(); b.querySelector('[data-h]').innerHTML = hist.join('<br>'); };
        e.addEventListener('input', show);
        e.addEventListener('keydown', x => { if (x.key === 'Enter') commit(); });
        b.querySelectorAll('[data-k]').forEach(x => x.onclick = () => { const k = x.dataset.k; if (k === 'C') e.value = ''; else if (k === '=') return commit(); else e.value += k; show(); e.focus(); });
        const v = b.querySelector('[data-f="v"]'), vr = b.querySelector('[data-f="vr"]');
        const vat = () => { const n = num(v.value), r = +en(vr.value) || 0; v.value = n ? money(n) : ''; b.querySelector('[data-ov]').innerHTML = n ? `با ${fa(r)}٪: <b>${money(n * (1 + r / 100))}</b> (مالیات ${money(n * r / 100)}) · اگر همین مبلغ با مالیات است: بدونِ مالیات <b>${money(n / (1 + r / 100))}</b>، مالیاتش ${money(n - n / (1 + r / 100))}` : ''; };
        [v, vr].forEach(x => x.addEventListener('input', vat));
        const p1 = b.querySelector('[data-f="p1"]'), p2 = b.querySelector('[data-f="p2"]');
        const pct = () => { const a = num(p1.value), c = num(p2.value); p1.value = a ? money(a) : ''; p2.value = c ? money(c) : ''; b.querySelector('[data-op]').innerHTML = a && c ? `تغییر: <b class="${c >= a ? 'tl-ok' : 'tl-bad'}">${c >= a ? '+' : '−'}${fa(Math.abs((c - a) / a * 100).toFixed(2))}٪</b> (${money(Math.abs(c - a))} ریال)` : ''; };
        [p1, p2].forEach(x => x.addEventListener('input', pct));
        setTimeout(() => e.focus(), 50);
    };

    // ===== تاریخ =====
    IMPL.date = b => {
        const t = today();
        b.innerHTML = `<div class="tl-lbl">تبدیلِ تاریخ</div>
            <div class="tl-row"><div class="tl-f"><label>شمسی</label><input class="tl-in" data-f="j" dir="ltr" placeholder="${jstr(...t)}"></div><div class="tl-f"><label>میلادی</label><input class="tl-in" data-f="g" dir="ltr" placeholder="2026-10-05"></div></div>
            <div data-oc></div>
            <div class="tl-lbl" style="margin-top:16px">فاصله‌ی دو تاریخِ شمسی</div>
            <div class="tl-row"><div class="tl-f"><label>از</label><input class="tl-in" data-f="a" dir="ltr" value="${jstr(...t)}"></div><div class="tl-f"><label>تا</label><input class="tl-in" data-f="z" dir="ltr" placeholder="1405/12/29"></div></div>
            <div data-od class="tl-note"></div>
            <div class="tl-lbl" style="margin-top:16px">افزودن / کم‌کردن</div>
            <div class="tl-row"><div class="tl-f"><label>تاریخ</label><input class="tl-in" data-f="s" dir="ltr" value="${jstr(...t)}"></div>
              <div class="tl-f" style="min-width:80px;max-width:110px"><label>مقدار (منفی = قبل)</label><input class="tl-in" data-f="n" dir="ltr" value="30"></div>
              <div class="tl-f" style="min-width:110px;max-width:130px"><label>واحد</label><select class="tl-in" data-f="u"><option value="d">روز</option><option value="m">ماه</option><option value="y">سال</option></select></div></div>
            <div data-oa></div>`;
        const g = f => b.querySelector(`[data-f="${f}"]`);
        const fullJ = p => `${jdow(...p)} ${fa(p[2])} ${MONTHS[p[1] - 1]} ${fa(p[0])}`;
        const conv = (src) => {
            let p = null;
            if (src === 'j') { p = parseJ(g('j').value); if (p) { const gg = d2g(j2d(...p)); g('g').value = `${gg[0]}-${p2(gg[1])}-${p2(gg[2])}`; } }
            else { const m = en(g('g').value).match(/^(\d{4})\D+(\d{1,2})\D+(\d{1,2})$/); if (m && +m[2] <= 12 && +m[3] <= 31) { p = d2j(g2d(+m[1], +m[2], +m[3])); g('j').value = jstr(...p); } }
            const o = b.querySelector('[data-oc]');
            if (!p) { o.innerHTML = ''; return; }
            const diff = j2d(...p) - j2d(...today()), gg = d2g(j2d(...p));
            o.innerHTML = `<div class="tl-res"><div class="big" style="font-size:20px">${fullJ(p)}</div><small dir="ltr" style="text-align:right">${gg[0]}-${p2(gg[1])}-${p2(gg[2])} · ${['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][mod(j2d(...p) + 1, 7)]}</small>
                <small>${diff === 0 ? 'امروز' : fa(Math.abs(diff)) + ' روز ' + (diff > 0 ? 'دیگر' : 'پیش')}${isLeap(p[0]) ? ' · سالِ ' + fa(p[0]) + ' کبیسه است' : ''}</small></div>`;
        };
        g('j').addEventListener('input', () => conv('j')); g('g').addEventListener('input', () => conv('g'));
        const dd = () => {
            const a = parseJ(g('a').value), z = parseJ(g('z').value), o = b.querySelector('[data-od]');
            if (!a || !z) { o.innerHTML = ''; return; }
            let d = j2d(...z) - j2d(...a); const neg = d < 0; if (neg) d = -d;
            let [y1, m1, x1] = neg ? z : a, [y2, m2, x2] = neg ? a : z;
            let yy = y2 - y1, mm = m2 - m1, xx = x2 - x1;
            if (xx < 0) { mm--; const pm = m2 === 1 ? 12 : m2 - 1, py = m2 === 1 ? y2 - 1 : y2; xx += mLen(py, pm); }
            if (mm < 0) { yy--; mm += 12; }
            o.innerHTML = `<span style="font-size:13px"><b>${fa(d)}</b> روز</span> = ${yy ? fa(yy) + ' سال و ' : ''}${mm ? fa(mm) + ' ماه و ' : ''}${fa(xx)} روز · حدودِ ${fa((d / 7).toFixed(1))} هفته`;
        };
        ['a', 'z'].forEach(f => g(f).addEventListener('input', dd));
        const ad = () => {
            const p = parseJ(g('s').value), n = parseInt(en(g('n').value).replace(/[^\d-]/g, ''), 10) || 0, u = g('u').value, o = b.querySelector('[data-oa]');
            if (!p) { o.innerHTML = ''; return; }
            const r = u === 'd' ? addDays(...p, n) : addMonths(...p, u === 'm' ? n : n * 12);
            o.innerHTML = `<div class="tl-res"><div style="display:flex;align-items:center;gap:8px"><div class="big" dir="ltr" style="font-size:22px">${fa(jstr(...r))}</div><button type="button" class="tl-btn sm" data-copy="${jstr(...r)}" style="margin-right:auto"><i class="fas fa-copy"></i></button></div><small>${fullJ(r)}</small></div>`;
            o.querySelector('[data-copy]').onclick = e => copy(e.currentTarget.dataset.copy);
        };
        ['s', 'n', 'u'].forEach(f => g(f).addEventListener('input', ad));
        dd(); ad();
    };

    // ===== کم‌کردنِ حجمِ یک عکس =====
    IMPL.img = b => {
        const D = Object.assign({quality: 75, max_side: 1920, format: 'jpeg'}, S().img || {});
        let file = null, last = null;
        b.innerHTML = `<div data-dz></div>
            <div class="tl-row" style="margin-top:12px"><div class="tl-f"><label>کیفیت: <b data-qv>${fa(D.quality)}</b>٪</label><input type="range" class="tl-range" min="30" max="95" value="${D.quality}" data-f="q"></div>
              <div class="tl-f" style="min-width:110px"><label>بزرگ‌ترین ضلع (پیکسل)</label><select class="tl-in" data-f="m">${[[0, 'بدونِ تغییر'], [3000, '۳۰۰۰'], [2560, '۲۵۶۰'], [1920, '۱۹۲۰'], [1600, '۱۶۰۰'], [1280, '۱۲۸۰'], [1024, '۱۰۲۴'], [800, '۸۰۰']].map(([v, t]) => `<option value="${v}" ${+D.max_side === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
              <div class="tl-f" style="min-width:100px"><label>فرمت</label><select class="tl-in" data-f="fmt">${['jpeg', 'webp', 'png'].map(f => `<option value="${f}" ${D.format === f ? 'selected' : ''}>${f === 'jpeg' ? 'JPG' : f.toUpperCase()}</option>`).join('')}</select></div>
              <div class="tl-f" style="min-width:110px"><label>حجمِ هدف (کیلوبایت)</label><input class="tl-in" data-f="t" inputmode="numeric" placeholder="اختیاری؛ مثل ۳۰۰"></div></div>
            <div data-o></div>`;
        const g = f => b.querySelector(`[data-f="${f}"]`), o = b.querySelector('[data-o]');
        dropzone(b.querySelector('[data-dz]'), {multiple: false, accept: 'image/*', label: 'عکس را انتخاب کنید', onFiles: f => { file = f[0]; run(); }});
        let tm;
        const run = async () => {
            b.querySelector('[data-qv]').textContent = fa(g('q').value);
            if (!file) { o.innerHTML = ''; return; }
            o.innerHTML = '<div class="tl-empty"><i class="fas fa-spinner fa-spin"></i> در حالِ ساخت…</div>';
            try {
                const r = await encode(file, {maxSide: +g('m').value, quality: g('q').value / 100, fmt: g('fmt').value, targetKB: num(g('t').value)});
                last = r;
                const save = Math.round((1 - r.blob.size / file.size) * 100), src = URL.createObjectURL(file), out = URL.createObjectURL(r.blob);
                o.innerHTML = `<div class="tl-cmp"><div><img src="${src}"><b>${kb(file.size)}</b>اصلی · ${fa(r.ow)}×${fa(r.oh)}</div><div><img src="${out}"><b>${kb(r.blob.size)}</b>خروجی · ${fa(r.w)}×${fa(r.h)}</div></div>
                    <div class="tl-res" style="display:flex;align-items:center;gap:10px"><div><small>${save > 0 ? 'کاهشِ حجم' : 'حجم کمتر نشد'}</small><div class="big">${save > 0 ? fa(save) + '٪' : '—'}</div></div>
                    <button type="button" class="tl-btn" data-a="dl" style="margin-right:auto"><i class="fas fa-download ml-1"></i>دانلود</button></div>`;
                o.querySelector('[data-a="dl"]').onclick = () => download(r.blob, `${baseName(file.name)} (کم‌حجم).${EXT[r.fmt]}`);
            } catch (e) { o.innerHTML = `<div class="tl-empty tl-bad">${esc(e.message)}</div>`; }
        };
        b.querySelectorAll('[data-f]').forEach(x => x.addEventListener('input', () => { clearTimeout(tm); tm = setTimeout(run, 300); }));
    };

    // ===== تبدیلِ فرمت و کم‌کردنِ حجمِ گروهی (مشترک) =====
    function multiImage(b, mode) {
        const D = Object.assign({quality: 75, max_side: 1920, format: 'jpeg'}, S().img || {}), max = +(S().batch_max || 60);
        let files = [], outs = [];
        b.innerHTML = `<div data-dz></div>
            <div class="tl-row" style="margin-top:12px">
              <div class="tl-f" style="min-width:100px"><label>${mode === 'conv' ? 'تبدیل به' : 'فرمتِ خروجی'}</label><div class="tl-seg" data-fmt>${['jpeg', 'png', 'webp'].map(f => `<button data-v="${f}" class="${(mode === 'conv' ? 'jpeg' : D.format) === f ? 'on' : ''}">${f === 'jpeg' ? 'JPG' : f.toUpperCase()}</button>`).join('')}</div></div>
              <div class="tl-f"><label>کیفیت: <b data-qv>${fa(mode === 'conv' ? 90 : D.quality)}</b>٪</label><input type="range" class="tl-range" min="30" max="100" value="${mode === 'conv' ? 90 : D.quality}" data-f="q"></div>
              ${mode === 'batch' ? `<div class="tl-f" style="min-width:110px"><label>بزرگ‌ترین ضلع</label><select class="tl-in" data-f="m">${[[0, 'بدونِ تغییر'], [2560, '۲۵۶۰'], [1920, '۱۹۲۰'], [1600, '۱۶۰۰'], [1280, '۱۲۸۰'], [1024, '۱۰۲۴'], [800, '۸۰۰']].map(([v, t]) => `<option value="${v}" ${+D.max_side === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
                <div class="tl-f" style="min-width:110px"><label>حجمِ هدفِ هر عکس (KB)</label><input class="tl-in" data-f="t" inputmode="numeric" placeholder="اختیاری"></div>` : ''}
            </div>
            <div style="display:flex;gap:6px;margin-top:12px;flex-wrap:wrap"><button type="button" class="tl-btn" data-a="go"><i class="fas fa-wand-magic-sparkles ml-1"></i>${mode === 'conv' ? 'تبدیل' : 'کم‌کردنِ حجم'}</button>
              <button type="button" class="tl-btn ghost" data-a="zip" disabled><i class="fas fa-file-zipper ml-1"></i>دانلودِ همه (ZIP)</button>
              <button type="button" class="tl-btn ghost" data-a="clr"><i class="fas fa-trash-can ml-1"></i>پاک کردن</button></div>
            <div class="tl-bar" data-bar style="display:none"><i></i></div><div data-sum class="tl-note"></div><div class="tl-files" data-list></div>`;
        let fmt = mode === 'conv' ? 'jpeg' : D.format;
        const g = f => b.querySelector(`[data-f="${f}"]`), list = b.querySelector('[data-list]');
        const draw = () => {
            list.innerHTML = files.map((f, i) => { const r = outs[i]; return `<div class="tl-file"><img src="${f._u || (f._u = URL.createObjectURL(f))}" alt=""><span class="nm" title="${esc(f.name)}">${esc(f.name)}</span>
                <span class="sz">${kb(f.size)}${r ? (r.err ? ` <span class="tl-bad">${esc(r.err)}</span>` : ` ← <b class="${r.blob.size < f.size ? 'tl-ok' : ''}">${kb(r.blob.size)}</b>`) : ''}</span>
                ${r && !r.err ? `<button type="button" data-dl="${i}" title="دانلود"><i class="fas fa-download"></i></button>` : ''}<button type="button" data-rm="${i}" title="حذف"><i class="fas fa-xmark"></i></button></div>`; }).join('');
            list.querySelectorAll('[data-rm]').forEach(x => x.onclick = () => { files.splice(+x.dataset.rm, 1); outs.splice(+x.dataset.rm, 1); draw(); });
            list.querySelectorAll('[data-dl]').forEach(x => x.onclick = () => { const r = outs[+x.dataset.dl]; download(r.blob, r.name); });
            b.querySelector('[data-a="zip"]').disabled = !outs.some(r => r && !r.err);
        };
        dropzone(b.querySelector('[data-dz]'), {multiple: true, accept: 'image/*', label: `عکس‌ها را انتخاب کنید (تا ${fa(max)} عکس)`, onFiles: f => {
            f = f.filter(x => /^image\//.test(x.type) || /\.(jpe?g|png|webp|gif|bmp|avif)$/i.test(x.name));
            if (files.length + f.length > max) { toast(`حداکثر ${fa(max)} عکس در هر بار.`, 'warning'); f = f.slice(0, Math.max(0, max - files.length)); }
            files = files.concat(f); outs.length = files.length; draw(); }});
        b.querySelectorAll('[data-fmt] button').forEach(x => x.onclick = () => { fmt = x.dataset.v; b.querySelectorAll('[data-fmt] button').forEach(y => y.classList.toggle('on', y === x)); });
        g('q').addEventListener('input', () => { b.querySelector('[data-qv]').textContent = fa(g('q').value); });
        b.querySelector('[data-a="clr"]').onclick = () => { files = []; outs = []; draw(); b.querySelector('[data-sum]').innerHTML = ''; };
        b.querySelector('[data-a="go"]').onclick = async e => {
            if (!files.length) return toast('اول عکس‌ها را انتخاب کنید.', 'warning');
            const btn = e.currentTarget; btn.disabled = true;
            const bar = b.querySelector('[data-bar]'); bar.style.display = ''; outs = [];
            let before = 0, after = 0;
            for (let i = 0; i < files.length; i++) {
                try {
                    const r = await encode(files[i], {maxSide: mode === 'batch' ? +g('m').value : 0, quality: Math.min(1, g('q').value / 100), fmt, targetKB: mode === 'batch' ? num(g('t').value) : 0});
                    outs[i] = {blob: r.blob, name: `${baseName(files[i].name)}.${EXT[r.fmt]}`}; before += files[i].size; after += r.blob.size;
                } catch (x) { outs[i] = {err: 'خوانده نشد'}; }
                bar.firstElementChild.style.width = ((i + 1) / files.length * 100) + '%';
                draw();
                await new Promise(r => setTimeout(r, 0));
            }
            btn.disabled = false;
            b.querySelector('[data-sum]').innerHTML = `${fa(outs.filter(r => r && !r.err).length)} عکس آماده شد · از ${kb(before)} به <b>${kb(after)}</b>${before ? ` (${fa(Math.round((1 - after / before) * 100))}٪ کمتر)` : ''}`;
        };
        b.querySelector('[data-a="zip"]').onclick = async () => {
            const ok = outs.filter(r => r && !r.err);
            const z = makeZip(await Promise.all(ok.map(async r => ({name: r.name, data: await u8(r.blob)}))));
            download(z, mode === 'conv' ? 'عکس‌های تبدیل‌شده.zip' : 'عکس‌های کم‌حجم.zip');
        };
    }
    IMPL.conv = b => multiImage(b, 'conv');
    IMPL.batch = b => multiImage(b, 'batch');

    // ===== عکس‌ها به PDF =====
    IMPL.img2pdf = b => {
        let files = [];
        b.innerHTML = `<div data-dz></div>
            <div class="tl-row" style="margin-top:12px"><div class="tl-f"><label>اندازه‌ی صفحه</label><div class="tl-seg" data-sz><button data-v="a4" class="on">A4 (خودکار عمودی/افقی)</button><button data-v="fit">هم‌اندازه‌ی عکس</button></div></div>
              <div class="tl-f" style="min-width:110px"><label>کیفیت</label><select class="tl-in" data-f="q"><option value="0.92">بالا</option><option value="0.8" selected>متوسط</option><option value="0.65">کم‌حجم</option></select></div>
              <div class="tl-f" style="min-width:110px"><label>نامِ فایل</label><input class="tl-in" data-f="n" value="اسکن"></div></div>
            <div class="tl-files" data-list></div>
            <div style="display:flex;gap:6px;margin-top:12px"><button type="button" class="tl-btn" data-a="go"><i class="fas fa-file-pdf ml-1"></i>ساختِ PDF</button></div>
            <div class="tl-bar" data-bar style="display:none"><i></i></div><p class="tl-note">ترتیبِ صفحه‌ها همان ترتیبِ فهرست است؛ با فلش‌ها جابه‌جا کنید.</p>`;
        let size = 'a4';
        const list = b.querySelector('[data-list]');
        const draw = () => {
            list.innerHTML = files.map((f, i) => `<div class="tl-file"><b style="width:20px;text-align:center;color:#94a3b8">${fa(i + 1)}</b><img src="${f._u || (f._u = URL.createObjectURL(f))}" alt=""><span class="nm">${esc(f.name)}</span><span class="sz">${kb(f.size)}</span>
                <button type="button" data-up="${i}" title="بالا"><i class="fas fa-arrow-up"></i></button><button type="button" data-dn="${i}" title="پایین"><i class="fas fa-arrow-down"></i></button><button type="button" data-rm="${i}" title="حذف"><i class="fas fa-xmark"></i></button></div>`).join('');
            const mv = (i, j) => { if (j < 0 || j >= files.length) return; [files[i], files[j]] = [files[j], files[i]]; draw(); };
            list.querySelectorAll('[data-up]').forEach(x => x.onclick = () => mv(+x.dataset.up, +x.dataset.up - 1));
            list.querySelectorAll('[data-dn]').forEach(x => x.onclick = () => mv(+x.dataset.dn, +x.dataset.dn + 1));
            list.querySelectorAll('[data-rm]').forEach(x => x.onclick = () => { files.splice(+x.dataset.rm, 1); draw(); });
        };
        dropzone(b.querySelector('[data-dz]'), {multiple: true, accept: 'image/*', label: 'عکس‌ها (یا اسکنِ صفحه‌ها) را انتخاب کنید', onFiles: f => { files = files.concat(f.filter(x => /^image\//.test(x.type) || /\.(jpe?g|png|webp|gif|bmp)$/i.test(x.name))); draw(); }});
        b.querySelectorAll('[data-sz] button').forEach(x => x.onclick = () => { size = x.dataset.v; b.querySelectorAll('[data-sz] button').forEach(y => y.classList.toggle('on', y === x)); });
        b.querySelector('[data-a="go"]').onclick = async e => {
            if (!files.length) return toast('اول عکس‌ها را انتخاب کنید.', 'warning');
            const btn = e.currentTarget; btn.disabled = true;
            const bar = b.querySelector('[data-bar]'); bar.style.display = '';
            const pages = [];
            try {
                for (let i = 0; i < files.length; i++) {
                    const r = await encode(files[i], {maxSide: 2480, quality: +b.querySelector('[data-f="q"]').value, fmt: 'jpeg'});
                    pages.push({jpeg: await u8(r.blob), w: r.w, h: r.h});
                    bar.firstElementChild.style.width = ((i + 1) / files.length * 100) + '%';
                }
                const pdf = makePdf(pages, size, size === 'a4' ? 18 : 0);
                download(pdf, (b.querySelector('[data-f="n"]').value.trim() || 'اسکن') + '.pdf');
                toast(`PDF با ${fa(pages.length)} صفحه ساخته شد (${kb(pdf.size)}).`, 'success');
            } catch (x) { toast(x.message || 'ساختِ PDF ممکن نشد.', 'error'); }
            btn.disabled = false;
        };
    };

    // ===== ابزارهای PDF (روی سرور) =====
    IMPL.pdf = b => {
        const OPS = [['merge', 'ادغام', 'fa-object-group', 'چند PDF را به ترتیب پشتِ هم یک فایل کنید.'], ['extract', 'انتخابِ صفحه‌ها', 'fa-scissors', 'فقط صفحه‌های لازم در یک PDFِ تازه.'],
                     ['split', 'صفحه‌به‌صفحه', 'fa-layer-group', 'هر صفحه یک PDFِ جدا (داخلِ ZIP).'], ['compress', 'کم‌کردنِ حجم', 'fa-compress', 'عکس‌های داخلِ PDF کم‌حجم می‌شوند.'],
                     ['toimg', 'PDF به عکس', 'fa-image', 'هر صفحه یک عکس (JPG یا PNG).'], ['rotate', 'چرخش', 'fa-rotate-right', 'چرخاندنِ همه یا بعضی صفحه‌ها.']];
        let op = 'merge', files = [];
        const maxMb = +(S().pdf_max_mb || 60);
        b.innerHTML = `<div class="tl-seg" data-ops>${OPS.map(([k, t, ic]) => `<button data-v="${k}" class="${k === op ? 'on' : ''}"><i class="fas ${ic} ml-1"></i>${t}</button>`).join('')}</div>
            <p class="tl-note" data-desc></p><div data-dz></div><div class="tl-files" data-list></div><div data-opts style="margin-top:10px"></div>
            <div style="display:flex;gap:6px;margin-top:12px"><button type="button" class="tl-btn" data-a="go"><i class="fas fa-gears ml-1"></i>انجام بده</button></div>
            <div class="tl-bar" data-bar style="display:none"><i></i></div><div data-o></div>
            <p class="tl-note"><i class="fas fa-lock ml-1"></i>فایل برای پردازش به سرورِ خودِ سایت می‌رود و بعد از دانلود پاک می‌شود. حداکثر ${fa(maxMb)} مگابایت برای هر فایل.</p>`;
        const list = b.querySelector('[data-list]'), opts = b.querySelector('[data-opts]');
        const multi = () => op === 'merge';
        const drawOpts = () => {
            b.querySelector('[data-desc]').textContent = OPS.find(x => x[0] === op)[3];
            opts.innerHTML = ['extract', 'split', 'toimg', 'rotate'].includes(op) ? `<div class="tl-row"><div class="tl-f"><label>صفحه‌ها ${op === 'extract' ? '' : '(خالی = همه)'}</label><input class="tl-in" data-f="pages" dir="ltr" placeholder="مثلاً 1-3, 5, 8-"></div>
                ${op === 'toimg' ? `<div class="tl-f" style="min-width:100px"><label>وضوح</label><select class="tl-in" data-f="dpi"><option value="100">کم (۱۰۰)</option><option value="150" selected>معمولی (۱۵۰)</option><option value="220">بالا (۲۲۰)</option></select></div>
                  <div class="tl-f" style="min-width:90px"><label>فرمت</label><select class="tl-in" data-f="format"><option value="jpg">JPG</option><option value="png">PNG</option></select></div>` : ''}
                ${op === 'rotate' ? `<div class="tl-f" style="min-width:110px"><label>زاویه</label><select class="tl-in" data-f="angle"><option value="90">۹۰° ساعت‌گرد</option><option value="180">۱۸۰°</option><option value="270">۹۰° پادساعت‌گرد</option></select></div>` : ''}</div>`
                : op === 'compress' ? `<div class="tl-f"><label>میزانِ کاهش</label><div class="tl-seg" data-lvl><button data-v="70,150">کم (کیفیتِ بهتر)</button><button data-v="55,110" class="on">متوسط</button><button data-v="40,80">زیاد (حجمِ کمتر)</button></div></div>` : '';
            opts.querySelectorAll('[data-lvl] button').forEach(x => x.onclick = () => opts.querySelectorAll('[data-lvl] button').forEach(y => y.classList.toggle('on', y === x)));
            dropzone(b.querySelector('[data-dz]'), {multiple: multi(), accept: 'application/pdf,.pdf', label: multi() ? 'فایل‌های PDF را انتخاب کنید (دو یا چند فایل)' : 'فایلِ PDF را انتخاب کنید', onFiles: f => {
                f = f.filter(x => /\.pdf$/i.test(x.name) || x.type === 'application/pdf');
                const big = f.find(x => x.size > maxMb * 1048576); if (big) { toast(`«${big.name}» بزرگ‌تر از ${fa(maxMb)} مگابایت است.`, 'warning'); f = f.filter(x => x !== big); }
                files = multi() ? files.concat(f) : f.slice(0, 1); draw(); }});
        };
        const draw = () => {
            list.innerHTML = files.map((f, i) => `<div class="tl-file"><i class="fas fa-file-pdf" style="color:#dc2626;font-size:20px;width:24px;text-align:center"></i><span class="nm">${esc(f.name)}</span><span class="sz">${kb(f.size)}</span>
                ${multi() ? `<button type="button" data-up="${i}"><i class="fas fa-arrow-up"></i></button><button type="button" data-dn="${i}"><i class="fas fa-arrow-down"></i></button>` : ''}<button type="button" data-rm="${i}"><i class="fas fa-xmark"></i></button></div>`).join('');
            const mv = (i, j) => { if (j < 0 || j >= files.length) return; [files[i], files[j]] = [files[j], files[i]]; draw(); };
            list.querySelectorAll('[data-up]').forEach(x => x.onclick = () => mv(+x.dataset.up, +x.dataset.up - 1));
            list.querySelectorAll('[data-dn]').forEach(x => x.onclick = () => mv(+x.dataset.dn, +x.dataset.dn + 1));
            list.querySelectorAll('[data-rm]').forEach(x => x.onclick = () => { files.splice(+x.dataset.rm, 1); draw(); });
        };
        b.querySelectorAll('[data-ops] button').forEach(x => x.onclick = () => { op = x.dataset.v; b.querySelectorAll('[data-ops] button').forEach(y => y.classList.toggle('on', y === x)); if (!multi()) files = files.slice(0, 1); drawOpts(); draw(); b.querySelector('[data-o]').innerHTML = ''; });
        drawOpts();
        const CH = 1536 * 1024;
        async function upload(f, onProg) {
            const id = Math.random().toString(36).slice(2, 12) + Date.now().toString(36), total = Math.max(1, Math.ceil(f.size / CH));
            for (let i = 0; i < total; i++) {
                const fd = new FormData();
                fd.append('action', 'up'); fd.append('upload_id', id); fd.append('index', i); fd.append('offset', i * CH); fd.append('size', f.size); fd.append('chunk', f.slice(i * CH, (i + 1) * CH), 'blob');
                let d = null;
                for (let k = 0; k < 4 && !d; k++) { try { d = await (await fetch(url('_=' + Date.now()), {method: 'POST', body: fd})).json(); } catch (e) { await new Promise(r => setTimeout(r, 1500 * (k + 1))); } }
                if (!d) throw new Error('ارتباط قطع شد؛ دوباره امتحان کنید.');
                if (!d.ok) throw new Error(d.error || 'آپلود ناموفق بود.');
                onProg((i + 1) / total);
            }
            return id;
        }
        b.querySelector('[data-a="go"]').onclick = async e => {
            if (!files.length) return toast('اول فایل را انتخاب کنید.', 'warning');
            if (op === 'merge' && files.length < 2) return toast('برای ادغام دست‌کم دو فایل لازم است.', 'warning');
            const g = f => opts.querySelector(`[data-f="${f}"]`);
            if (op === 'extract' && !g('pages').value.trim()) return toast('شماره‌ی صفحه‌ها را بنویسید (مثلاً 1-3, 5).', 'warning');
            const btn = e.currentTarget; btn.disabled = true;
            const bar = b.querySelector('[data-bar]'), o = b.querySelector('[data-o]'); bar.style.display = ''; bar.firstElementChild.style.width = '0';
            o.innerHTML = '<p class="tl-note"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ آپلود…</p>';
            try {
                const tot = files.reduce((a, f) => a + f.size, 0); let done = 0; const ids = [];
                for (const f of files) { ids.push(await upload(f, p => { bar.firstElementChild.style.width = ((done + p * f.size) / tot * 92) + '%'; })); done += f.size; }
                o.innerHTML = '<p class="tl-note"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ پردازش روی سرور…</p>';
                const lvl = opts.querySelector('[data-lvl] .on');
                const [q, dpi] = lvl ? lvl.dataset.v.split(',').map(Number) : [60, 0];
                const r = await api('pdf_run', {op, files: ids, base: baseName(files[0].name), pages: g('pages') ? en(g('pages').value) : '', quality: q,
                                                dpi: g('dpi') ? +g('dpi').value : dpi, format: g('format') ? g('format').value : 'jpg', angle: g('angle') ? +g('angle').value : 90});
                bar.firstElementChild.style.width = '100%';
                if (!r.ok) throw new Error(r.error || 'خطا');
                o.innerHTML = `<div class="tl-res" style="display:flex;align-items:center;gap:10px"><div><small>${esc(r.name)}</small><div class="big" style="font-size:20px">${kb(r.size)}</div>
                    <small>${r.pages ? fa(r.pages) + ' صفحه' : ''}${r.before ? ` · از ${kb(r.before)} (${fa(Math.round((1 - r.size / r.before) * 100))}٪ کمتر)` : ''}</small></div>
                    <a class="tl-btn" href="${url('action=dl&token=' + encodeURIComponent(r.token))}" style="margin-right:auto;text-decoration:none"><i class="fas fa-download ml-1"></i>دانلود</a></div>`;
            } catch (x) { o.innerHTML = `<p class="tl-note tl-bad">${esc(x.message)}</p>`; }
            btn.disabled = false;
        };
    };

    // ===== بررسیِ کد ملی، شناسه ملی، شبا و کارت =====
    const BANK_IBAN = {'010': 'بانک مرکزی', '011': 'صنعت و معدن', '012': 'ملت', '013': 'رفاه کارگران', '014': 'مسکن', '015': 'سپه', '016': 'کشاورزی', '017': 'ملی ایران', '018': 'تجارت', '019': 'صادرات ایران',
        '020': 'توسعه صادرات', '021': 'پست بانک', '022': 'توسعه تعاون', '051': 'مؤسسه اعتباری توسعه', '053': 'کارآفرین', '054': 'پارسیان', '055': 'اقتصاد نوین', '056': 'سامان', '057': 'پاسارگاد',
        '058': 'سرمایه', '059': 'سینا', '060': 'قرض‌الحسنه مهر ایران', '061': 'شهر', '062': 'آینده', '063': 'انصار', '064': 'گردشگری', '065': 'حکمت ایرانیان', '066': 'دی', '069': 'ایران زمین',
        '070': 'قرض‌الحسنه رسالت', '073': 'کوثر', '075': 'ملل', '078': 'خاورمیانه', '080': 'نور', '095': 'ایران و ونزوئلا'};
    const BANK_BIN = {'603799': 'ملی ایران', '589210': 'سپه', '627961': 'صنعت و معدن', '603770': 'کشاورزی', '628023': 'مسکن', '627760': 'پست بانک', '502908': 'توسعه تعاون', '627412': 'اقتصاد نوین',
        '622106': 'پارسیان', '639194': 'پارسیان', '627884': 'پارسیان', '502229': 'پاسارگاد', '639347': 'پاسارگاد', '627488': 'کارآفرین', '502910': 'کارآفرین', '621986': 'سامان', '639346': 'سینا',
        '639607': 'سرمایه', '636214': 'آینده', '502806': 'شهر', '504706': 'شهر', '502938': 'دی', '603769': 'صادرات ایران', '610433': 'ملت', '991975': 'ملت', '585983': 'تجارت', '627353': 'تجارت',
        '589463': 'رفاه کارگران', '627381': 'انصار', '505416': 'گردشگری', '636949': 'حکمت ایرانیان', '505785': 'ایران زمین', '504172': 'قرض‌الحسنه رسالت', '606373': 'قرض‌الحسنه مهر ایران',
        '505801': 'کوثر', '606256': 'ملل', '585947': 'خاورمیانه', '507677': 'نور', '627648': 'توسعه صادرات', '207177': 'توسعه صادرات', '639599': 'قوامین', '628157': 'مؤسسه اعتباری توسعه'};
    const checkNid = s => { if (!/^\d{10}$/.test(s) || /^(\d)\1{9}$/.test(s)) return false; let t = 0; for (let i = 0; i < 9; i++) t += +s[i] * (10 - i); const r = t % 11; return r < 2 ? +s[9] === r : +s[9] === 11 - r; };
    const checkLegal = s => { if (!/^\d{11}$/.test(s) || /^(\d)\1{10}$/.test(s)) return false; const c = +s[9] + 2, k = [29, 27, 23, 19, 17]; let t = 0; for (let i = 0; i < 10; i++) t += (+s[i] + c) * k[i % 5]; let r = t % 11; if (r === 10) r = 0; return r === +s[10]; };
    const checkIban = s => { if (!/^IR\d{24}$/.test(s)) return false; const r = (s.slice(4) + '1827' + s.slice(2, 4)); let m = 0; for (const ch of r) m = (m * 10 + +ch) % 97; return m === 1; };
    const checkCard = s => { if (!/^\d{16}$/.test(s)) return false; let t = 0; for (let i = 0; i < 16; i++) { let d = +s[i]; if (i % 2 === 0) { d *= 2; if (d > 9) d -= 9; } t += d; } return t % 10 === 0; };
    IMPL.check = b => {
        b.innerHTML = `<input class="tl-in big" data-f="v" dir="ltr" placeholder="کد ملی، شناسه ملی، شماره شبا یا کارت…" style="text-align:center">
            <div data-o></div><p class="tl-note">نوعِ عدد خودکار تشخیص داده می‌شود: ۱۰ رقم = کد ملی، ۱۱ رقم = شناسه‌ی ملیِ شرکت، IR + ۲۴ رقم = شبا، ۱۶ رقم = کارتِ بانکی.</p>`;
        const inp = b.querySelector('[data-f="v"]'), o = b.querySelector('[data-o]');
        const run = () => {
            let s = en(inp.value).toUpperCase().replace(/[\s\-_.]/g, '');
            if (/^\d{24}$/.test(s)) s = 'IR' + s;
            if (!s) { o.innerHTML = ''; return; }
            let kind = '', ok = false, extra = '';
            if (/^\d{10}$/.test(s)) { kind = 'کد ملی'; ok = checkNid(s); if (ok) extra = 'سه رقمِ اول (کدِ شهر): ' + fa(s.slice(0, 3)); }
            else if (/^\d{11}$/.test(s)) { kind = 'شناسه‌ی ملیِ شخصِ حقوقی'; ok = checkLegal(s); }
            else if (/^IR\d{24}$/.test(s)) { kind = 'شماره شبا'; ok = checkIban(s); const bn = BANK_IBAN[s.slice(4, 7)]; extra = (bn ? 'بانک ' + bn : 'بانک نامشخص') + ' · ' + s.replace(/(.{4})/g, '$1 ').trim(); }
            else if (/^\d{16}$/.test(s)) { kind = 'کارتِ بانکی'; ok = checkCard(s); const bn = BANK_BIN[s.slice(0, 6)]; extra = (bn ? 'بانک ' + bn : 'بانک نامشخص') + ' · ' + s.replace(/(.{4})/g, '$1 ').trim(); }
            else if (/^\d{8,9}$/.test(s)) { kind = 'کد ملی'; ok = false; extra = 'کد ملی ۱۰ رقمی است؛ اگر با صفر شروع می‌شود، صفرهای اول را هم بنویسید: ' + fa(s.padStart(10, '0')); }
            else { o.innerHTML = '<div class="tl-res empty"><small>نوعِ این عدد شناخته نشد.</small></div>'; return; }
            o.innerHTML = `<div class="tl-res" style="${ok ? 'background:linear-gradient(135deg,#059669,#0d9488)' : 'background:linear-gradient(135deg,#e11d48,#f97316)'}"><small>${kind}</small>
                <div class="big"><i class="fas ${ok ? 'fa-circle-check' : 'fa-circle-xmark'} ml-2"></i>${ok ? 'معتبر است' : 'معتبر نیست'}</div>${extra ? `<small dir="auto" style="margin-top:4px">${esc(fa(extra))}</small>` : ''}</div>`;
        };
        inp.addEventListener('input', run); setTimeout(() => inp.focus(), 50);
    };

    // ===== اصلاحِ متن =====
    IMPL.text = b => {
        b.innerHTML = `<textarea class="tl-in" data-f="t" placeholder="متن را اینجا بچسبانید…"></textarea>
            <div class="tl-chips" style="margin-top:10px">
              <button type="button" class="tl-tag" data-a="fix"><i class="fas fa-wand-magic-sparkles ml-1"></i>اصلاحِ کامل</button>
              <button type="button" class="tl-tag" data-a="yk">ي ك عربی ⇐ ی ک</button>
              <button type="button" class="tl-tag" data-a="fa">اعداد ⇐ فارسی</button>
              <button type="button" class="tl-tag" data-a="en">اعداد ⇐ انگلیسی</button>
              <button type="button" class="tl-tag" data-a="sp">فاصله‌های اضافه</button>
              <button type="button" class="tl-tag" data-a="nl">یک‌خط کردن</button></div>
            <div style="display:flex;gap:6px;margin-top:10px;align-items:center"><button type="button" class="tl-btn" data-a="cp"><i class="fas fa-copy ml-1"></i>کپی</button><span class="tl-note" data-st style="margin:0"></span></div>`;
        const t = b.querySelector('[data-f="t"]');
        const st = () => { const v = t.value; b.querySelector('[data-st]').textContent = `${fa(v.length)} نویسه · ${fa((v.trim().match(/\S+/g) || []).length)} کلمه · ${fa(v ? v.split('\n').length : 0)} خط`; };
        const ops = {
            yk: v => v.replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/ى/g, 'ی').replace(/ة/g, 'ه'),
            fa: v => v.replace(/\d/g, d => FA[d]).replace(/[٠-٩]/g, d => FA['٠١٢٣٤٥٦٧٨٩'.indexOf(d)]),
            en: v => en(v),
            sp: v => v.replace(/[ \t ]+/g, ' ').replace(/ ?‌ ?/g, '‌').replace(/ ([،.؛:!؟?)])/g, '$1').replace(/\n{3,}/g, '\n\n').trim(),
            nl: v => v.replace(/\s*\n\s*/g, ' ').trim(),
        };
        ops.fix = v => ops.sp(ops.yk(v)).replace(/(^|\s)(ن?می) (?=\S)/g, '$1$2‌');
        b.querySelectorAll('[data-a]').forEach(x => x.onclick = () => { const a = x.dataset.a; if (a === 'cp') return copy(t.value, 'متن کپی شد.'); t.value = ops[a](t.value); st(); });
        t.addEventListener('input', st); st();
    };

    // ---------------- تنظیماتِ ابزارها (مدیرِ کل؛ تنظیمات ← ابزارها) ----------------
    async function renderSettings(el) {
        css();
        el.innerHTML = '<div class="tl-empty"><i class="fas fa-spinner fa-spin"></i></div>';
        const d = await boot(true);
        if (!d.ok) { el.innerHTML = `<div class="tl-empty">${esc(d.error || 'خطا')}</div>`; return; }
        if (!d.is_admin) { el.innerHTML = '<div class="tl-empty">فقط مدیرِ کل.</div>'; return; }
        const s = JSON.parse(JSON.stringify(d.settings));
        const pt = TOOLS[0];
        el.innerHTML = `<div class="tl" style="${cvars(pt)}">
            <div class="tl-view" style="max-width:none"><div class="tl-vh"><span class="tl-ic"><i class="fas ${pt.ic}"></i></span><div><b>تاریخِ قسط</b><small>نوع‌ها: نام و این‌که تاریخ چند روز یا چند ماه جلو برود</small></div></div>
              <div class="tl-f"><label>عنوانِ ابزار</label><input class="tl-in" data-f="ptitle" value="${esc(s.pdate_title || '')}"></div>
              <table class="tl-tbl no-count"><thead><tr><th>نام</th><th>مقدار</th><th>واحد</th><th>نمونه از امروز</th><th></th></tr></thead><tbody data-types></tbody></table>
              <button type="button" class="tl-btn ghost sm" data-a="add" style="margin-top:8px"><i class="fas fa-plus ml-1"></i>نوعِ جدید</button>
              <p class="tl-note">«ماه» با طولِ واقعیِ ماه‌ها حساب می‌شود (۳۱، ۳۰ و اسفندِ ۲۹/۳۰ روزه؛ مثلاً ۳۱ شهریور + ۱ ماه = ۳۰ مهر). «روز» یعنی همان تعداد روزِ تقویمی.</p></div>
            <div class="tl-view" style="max-width:none;margin-top:12px;${cvars(TOOLS[5])}"><div class="tl-vh"><span class="tl-ic"><i class="fas fa-image"></i></span><div><b>عکس‌ها</b><small>پیش‌فرض‌های «کم‌کردنِ حجم» و «حجمِ گروهی»</small></div></div>
              <div class="tl-row"><div class="tl-f"><label>کیفیتِ پیش‌فرض (٪)</label><input class="tl-in" data-f="iq" inputmode="numeric" value="${fa(s.img.quality)}"></div>
                <div class="tl-f"><label>بزرگ‌ترین ضلع (پیکسل)</label><input class="tl-in" data-f="im" inputmode="numeric" value="${fa(s.img.max_side)}"></div>
                <div class="tl-f"><label>فرمت</label><select class="tl-in" data-f="if">${['jpeg', 'webp', 'png'].map(f => `<option value="${f}" ${s.img.format === f ? 'selected' : ''}>${f === 'jpeg' ? 'JPG' : f.toUpperCase()}</option>`).join('')}</select></div>
                <div class="tl-f"><label>حداکثرِ عکس در هر بار</label><input class="tl-in" data-f="bm" inputmode="numeric" value="${fa(s.batch_max)}"></div></div></div>
            <div class="tl-view" style="max-width:none;margin-top:12px;${cvars(TOOLS[1])}"><div class="tl-vh"><span class="tl-ic"><i class="fas fa-table-list"></i></span><div><b>اقساط و PDF</b><small>پیش‌فرض‌های «محاسبه‌ی اقساط» و سقفِ فایلِ PDF</small></div></div>
              <div class="tl-row"><div class="tl-f"><label>تعدادِ قسط</label><input class="tl-in" data-f="ic" inputmode="numeric" value="${fa(s.inst.count)}"></div>
                <div class="tl-f"><label>قسطِ اول چند ماه بعد</label><input class="tl-in" data-f="io" inputmode="numeric" value="${fa(s.inst.offset)}"></div>
                <div class="tl-f"><label>رُند کردن</label><select class="tl-in" data-f="ir">${[[1, 'بدون'], [1000, 'هزار ریال'], [10000, 'ده هزار ریال'], [100000, 'صد هزار ریال']].map(([v, t]) => `<option value="${v}" ${+s.inst.round === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
                <div class="tl-f"><label>حداکثرِ حجمِ PDF (مگابایت)</label><input class="tl-in" data-f="pm" inputmode="numeric" value="${fa(s.pdf_max_mb)}"></div></div></div>
            <div style="margin-top:12px"><button type="button" class="tl-btn" data-a="save" style="${cvars(TOOLS[0])}"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تنظیماتِ ابزارها</button></div></div>`;
        const tb = el.querySelector('[data-types]');
        const drawTypes = () => {
            const t0 = today();
            tb.innerHTML = s.pdate_types.map((t, i) => { const r = t.n > 0 ? (t.unit === 'month' ? addMonths(...t0, t.n) : addDays(...t0, t.n)) : null;
                return `<tr><td><input class="tl-in" data-tn="${i}" value="${esc(t.name)}" style="padding:6px 10px"></td><td style="width:90px"><input class="tl-in" data-tv="${i}" value="${fa(t.n)}" inputmode="numeric" style="padding:6px 10px"></td>
                <td style="width:100px"><select class="tl-in" data-tu="${i}" style="padding:6px 8px"><option value="day" ${t.unit !== 'month' ? 'selected' : ''}>روز</option><option value="month" ${t.unit === 'month' ? 'selected' : ''}>ماه</option></select></td>
                <td dir="ltr" style="text-align:right;color:#64748b;font-size:11px">${r ? fa(jstr(...r)) : '—'}</td><td style="width:40px"><button type="button" class="tl-btn ghost sm" data-tx="${i}"><i class="fas fa-trash-can"></i></button></td></tr>`; }).join('')
                || '<tr><td colspan="5" class="tl-note" style="text-align:center">نوعی تعریف نشده.</td></tr>';
            tb.querySelectorAll('[data-tn]').forEach(x => x.oninput = () => { s.pdate_types[+x.dataset.tn].name = x.value; });
            tb.querySelectorAll('[data-tv]').forEach(x => x.oninput = () => { s.pdate_types[+x.dataset.tv].n = num(x.value); });
            tb.querySelectorAll('[data-tv]').forEach(x => x.onchange = drawTypes);
            tb.querySelectorAll('[data-tu]').forEach(x => x.onchange = () => { s.pdate_types[+x.dataset.tu].unit = x.value; drawTypes(); });
            tb.querySelectorAll('[data-tx]').forEach(x => x.onclick = () => { s.pdate_types.splice(+x.dataset.tx, 1); drawTypes(); });
        };
        drawTypes();
        el.querySelector('[data-a="add"]').onclick = () => { s.pdate_types.push({name: '', n: 30, unit: 'day'}); drawTypes(); const l = tb.querySelector('tr:last-child [data-tn]'); if (l) l.focus(); };
        el.querySelector('[data-a="save"]').onclick = async () => {
            const g = f => el.querySelector(`[data-f="${f}"]`).value;
            const body = {pdate_title: g('ptitle'), pdate_types: s.pdate_types.filter(t => String(t.name).trim() && t.n > 0),
                          img: {quality: num(g('iq')), max_side: num(g('im')), format: g('if')}, batch_max: num(g('bm')),
                          inst: {count: num(g('ic')), offset: num(g('io')), round: +g('ir')}, pdf_max_mb: num(g('pm'))};
            const r = await api('settings_save', {settings: body});
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            T.boot.settings = r.settings;
            toast('تنظیماتِ ابزارها ذخیره شد.', 'success');
            renderSettings(el);
        };
    }

    window.Tools = {mount, boot, available: () => boot().then(available), renderSettings, list: TOOLS,
                    _test: {parseJ, addDays, addMonths, j2d, d2j, d2g, g2d, mLen, isLeap, words, checkNid, checkLegal, checkIban, checkCard, makeZip, makePdf}};
})();
