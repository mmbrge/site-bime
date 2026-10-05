// فایل: comfort.js
// «امکاناتِ رفاهی»: پخش‌کننده‌ی موسیقیِ شناور (پایین سمت چپ)، جعبه‌ابزارِ من (کارهای امروز، تمرکز و سلامت، وضعیت و «مزاحم نشوید»،
// متن‌های آماده، ابزارهای سریع، ظاهر)، جستجوی سراسری (Ctrl+K) و میانبرها، پیامِ صبح‌بخیر و تولدِ همکاران.
// هر امکان فقط وقتی دیده می‌شود که برای کاربر روشن باشد (api/comfort_actions.php?action=boot).
// پنلِ شرکت‌ها: window.CF_CONFIG = {api: '../api/comfort_actions.php?portal=1', portal: true}
(function () {
    'use strict';
    if (window.CF) return;
    const CFG = window.CF_CONFIG || {};
    const API = CFG.api || 'api/comfort_actions.php';
    const url = q => API + (API.includes('?') ? '&' : '?') + q;
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = n => fa(Math.round(Number(n) || 0).toLocaleString('en-US'));
    const now = () => (window.IrTime ? IrTime.now() : Date.now());
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const DOW = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    const LS = {get(k, d) { try { const v = localStorage.getItem('cf:' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
                set(k, v) { try { localStorage.setItem('cf:' + k, JSON.stringify(v)); } catch (e) {} }};
    function toast(m, t) {
        if (window.showToast) return showToast(m, t || 'info');
        const d = document.createElement('div');
        d.className = 'cf-mini-toast ' + (t || '');
        d.textContent = m;
        document.body.appendChild(d);
        setTimeout(() => d.remove(), 3500);
    }
    async function api(action, body = {}) {
        try {
            const r = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))});
            return await r.json();
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور'}; }
    }
    // ---------------- تقویمِ شمسی ----------------
    function g2j(gy, gm, gd) {
        const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        const gy2 = gm > 2 ? gy + 1 : gy;
        let days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
        let jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
        jy += 4 * Math.floor(days / 1461); days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        const jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
        const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
        return [jy, jm, jd];
    }
    function j2g(jy, jm, jd) {
        jy += 1595;
        let days = -355668 + 365 * jy + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
        let gy = 400 * Math.floor(days / 146097); days %= 146097;
        if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
        gy += 4 * Math.floor(days / 1461); days %= 1461;
        if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        let gd = days + 1;
        const sal = [0, 31, (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        let gm = 0;
        for (gm = 1; gm <= 12 && gd > sal[gm]; gm++) gd -= sal[gm];
        return [gy, gm, gd];
    }
    const irDate = (ms) => { const d = new Date((ms ?? now()) + 12600000); return [d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate(), d.getUTCHours(), d.getUTCMinutes(), d.getUTCDay()]; };
    const todayJ = () => { const [y, m, d] = irDate(); return g2j(y, m, d); };
    const jstr = (y, m, d) => `${y}/${String(m).padStart(2, '0')}/${String(d).padStart(2, '0')}`;
    const parseJ = s => { const m = en(s || '').match(/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/); return m ? [+m[1], +m[2], +m[3]] : null; };
    const mmss = s => { s = Math.max(0, Math.round(s || 0)); return fa(Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0')); };
    // عدد به حروف (برای ابزارِ ریال/تومان)
    function words(n) {
        n = Math.floor(Math.abs(Number(n) || 0));
        if (!n) return 'صفر';
        const ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'], teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        const tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'], hund = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
        const scale = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];
        const three = x => { const p = []; if (x >= 100) { p.push(hund[Math.floor(x / 100)]); x %= 100; } if (x >= 20) { p.push(tens[Math.floor(x / 10)]); x %= 10; if (x) p.push(ones[x]); } else if (x >= 10) p.push(teens[x - 10]); else if (x) p.push(ones[x]); return p.join(' و '); };
        const parts = []; let i = 0;
        while (n > 0 && i < scale.length) { const g = n % 1000; if (g) parts.unshift(three(g) + (scale[i] ? ' ' + scale[i] : '')); n = Math.floor(n / 1000); i++; }
        return parts.join(' و ');
    }

    // ================= سبک =================
    const CSS = `
    .cf-root{position:fixed;left:14px;bottom:14px;z-index:9400;direction:rtl;font-family:inherit}
    .cf-dock{display:flex;flex-direction:column-reverse;gap:10px;align-items:center}
    .cf-disc{position:relative;width:54px;height:54px;border-radius:50%;border:0;padding:0;cursor:pointer;background:transparent;filter:drop-shadow(0 10px 18px rgba(15,23,42,.35));transition:transform .3s cubic-bezier(.2,.9,.3,1.2),opacity .25s}
    /* پخش‌کننده‌ی کوچک: وقتی آهنگی پخش شده و پنل بسته است، دیسک کوچک می‌شود و این نوار جایش باز می‌شود */
    .cf-root.cf-has-mini .cf-disc{transform:scale(.2) rotate(-90deg);opacity:0;pointer-events:none}
    .cf-mini{position:absolute;left:0;bottom:0;height:44px;width:min(244px,calc(100vw - 28px));display:flex;align-items:center;gap:2px;padding:0 5px 0 4px;border-radius:22px;
        background:linear-gradient(120deg,#1e1b4b 0%,#0f172a 58%,#0c2a43 100%);color:#e2e8f0;border:1px solid rgba(165,180,252,.22);
        box-shadow:0 14px 30px -12px rgba(30,27,75,.75),0 0 0 4px rgba(99,102,241,.06);overflow:hidden;isolation:isolate;
        transform-origin:left center;opacity:0;transform:scale(.25);pointer-events:none;transition:transform .38s cubic-bezier(.2,.9,.3,1.12),opacity .25s,box-shadow .4s}
    .cf-root.cf-has-mini .cf-mini{opacity:1;transform:none;pointer-events:auto}
    .cf-mini::before{content:'';position:absolute;inset:0;z-index:-1;background:linear-gradient(100deg,transparent 30%,rgba(129,140,248,.2) 50%,transparent 70%);transform:translateX(-120%);opacity:0}
    .cf-mini.on::before{opacity:1;animation:cfSheen 3.6s ease-in-out infinite}
    .cf-mini.on{box-shadow:0 14px 30px -12px rgba(30,27,75,.75),0 0 18px -4px rgba(99,102,241,.55)}
    @keyframes cfSheen{0%{transform:translateX(120%)}60%,100%{transform:translateX(-120%)}}
    .cf-mini .cf-mc{flex:none;position:relative;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center}
    .cf-mini .cf-mc svg{position:absolute;inset:0;transform:rotate(-90deg)}
    .cf-mini .cf-mc svg circle{fill:none;stroke-width:2.5}
    .cf-mini .cf-mc .cf-mring{stroke:url(#cfmg);stroke-linecap:round;stroke-dasharray:0 101;transition:stroke-dasharray .3s linear}
    .cf-mini .cf-disk{width:28px;height:28px;border-radius:50%;position:relative;box-shadow:inset 0 0 0 1px rgba(255,255,255,.15)}
    .cf-mini .cf-disk::before{content:'';position:absolute;inset:0;border-radius:50%;background:repeating-radial-gradient(circle,rgba(0,0,0,.18) 0 1px,transparent 1px 3px),radial-gradient(circle at 30% 25%,rgba(255,255,255,.45),transparent 50%)}
    .cf-mini .cf-disk::after{content:'';position:absolute;inset:38%;border-radius:50%;background:#0f172a;box-shadow:0 0 0 2px rgba(255,255,255,.35)}
    .cf-mini.on .cf-disk{animation:cfSpin 3.2s linear infinite}
    .cf-mini .cf-mt{flex:1;min-width:0;cursor:pointer;padding:0 5px;overflow:hidden}
    .cf-mini .cf-mt b{display:block;font-size:11px;font-weight:900;white-space:nowrap;overflow:hidden;line-height:1.5}
    .cf-mini .cf-mt b span{display:inline-block}
    .cf-mini .cf-mt b.mq span{animation:cfMq var(--mqd,8s) ease-in-out infinite}
    @keyframes cfMq{0%,18%{transform:translateX(0)}82%,100%{transform:translateX(var(--mq,0))}}
    .cf-mini .cf-mt small{display:flex;align-items:center;gap:4px;font-size:9.5px;opacity:.6;white-space:nowrap;overflow:hidden;line-height:1.4}
    .cf-mini .cf-meq{display:none;align-items:flex-end;gap:1.5px;height:8px}
    .cf-mini .cf-meq i{width:2px;border-radius:1px;background:#a5b4fc;height:3px;animation:cfMeq .9s ease-in-out infinite}
    @keyframes cfMeq{0%,100%{height:2px}50%{height:8px}}
    .cf-mini .cf-meq i:nth-child(2){animation-delay:.2s}.cf-mini .cf-meq i:nth-child(3){animation-delay:.4s}
    .cf-mini.on .cf-meq{display:inline-flex}
    .cf-mini button{flex:none;width:24px;height:24px;border-radius:50%;border:0;background:transparent;color:#cbd5e1;cursor:pointer;font-size:10px;transition:background .15s,color .15s,transform .15s;display:flex;align-items:center;justify-content:center}
    .cf-mini button:hover{background:rgba(255,255,255,.1);color:#fff}
    .cf-mini button:active{transform:scale(.88)}
    .cf-mini button.pp{width:30px;height:30px;color:#fff;font-size:11px;background:var(--cf-acc2,linear-gradient(135deg,#6366f1,#06b6d4));box-shadow:0 4px 12px -4px rgba(99,102,241,.9)}
    .cf-mini button.pp:hover{transform:scale(1.07)}
    .cf-mini button.op{width:20px;height:20px;font-size:8.5px;opacity:.55;margin-right:1px}
    .cf-mini button.op:hover{opacity:1}
    .cf-disc:hover{transform:scale(1.06)}
    .cf-vinyl{position:absolute;inset:4px;border-radius:50%;background:repeating-radial-gradient(circle at 50% 50%,#111827 0 2px,#1f2937 2px 3px);box-shadow:inset 0 0 0 2px rgba(255,255,255,.06)}
    .cf-vinyl::before{content:'';position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 30deg,transparent 0 40deg,rgba(255,255,255,.18) 50deg,transparent 70deg 220deg,rgba(255,255,255,.1) 235deg,transparent 250deg)}
    .cf-vinyl i{position:absolute;inset:32%;border-radius:50%;background:var(--cf-acc2,linear-gradient(135deg,#6366f1,#06b6d4));box-shadow:0 0 0 2px #0b1220}
    .cf-vinyl i::after{content:'';position:absolute;inset:40%;border-radius:50%;background:#0b1220}
    .cf-disc.playing .cf-vinyl{animation:cfSpin 3.2s linear infinite}
    @keyframes cfSpin{to{transform:rotate(360deg)}}
    .cf-disc svg{position:absolute;inset:0;transform:rotate(-90deg)}
    .cf-disc svg circle{fill:none;stroke-width:3}
    .cf-disc .cf-note{position:absolute;right:-2px;top:-2px;width:18px;height:18px;border-radius:50%;background:#fff;color:#4f46e5;font-size:10px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.25)}
    .cf-disc.playing .cf-note{animation:cfBob 1.2s ease-in-out infinite}
    @keyframes cfBob{50%{transform:translateY(-3px)}}
    .cf-hubbtn{position:relative;width:44px;height:44px;border-radius:15px;border:1px solid rgba(255,255,255,.25);cursor:pointer;color:#fff;font-size:16px;
        background:linear-gradient(135deg,#4f46e5,#0891b2);box-shadow:0 10px 20px -8px rgba(79,70,229,.7);transition:transform .2s}
    .cf-hubbtn:hover{transform:translateY(-2px)}
    .cf-hubbtn .cf-badge{position:absolute;top:-5px;left:-5px;min-width:18px;height:18px;border-radius:9px;background:#f43f5e;color:#fff;font-size:10px;font-weight:900;display:none;align-items:center;justify-content:center;padding:0 4px}
    .cf-hubbtn .cf-pomo{position:absolute;bottom:-8px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;font-size:9.5px;font-weight:900;border-radius:8px;padding:1px 5px;white-space:nowrap;display:none}
    .cf-hubbtn .cf-sdot{position:absolute;bottom:-3px;right:-3px;font-size:12px;line-height:1}
    .cf-panel{position:absolute;left:68px;bottom:0;width:min(350px,calc(100vw - 96px));max-height:min(78vh,640px);display:flex;flex-direction:column;border-radius:24px;overflow:hidden;
        background:rgba(15,23,42,.94);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);color:#e2e8f0;border:1px solid rgba(255,255,255,.08);
        box-shadow:0 30px 60px -20px rgba(2,6,23,.65);transform-origin:bottom left;animation:cfIn .22s cubic-bezier(.2,.9,.3,1.2)}
    .cf-panel.light{background:rgba(255,255,255,.97);color:#0f172a;border-color:#e2e8f0}
    .cf-root.cf-has-mini .cf-panel{bottom:56px}
    @keyframes cfIn{from{opacity:0;transform:scale(.92) translateY(10px)}to{opacity:1;transform:none}}
    .cf-panel.cf-out{animation:cfOut .2s cubic-bezier(.4,0,1,1) forwards;pointer-events:none}
    @keyframes cfOut{from{opacity:1;transform:none}to{opacity:0;transform:scale(.9) translateY(14px)}}
    .cf-hidden{display:none!important}
    .cf-ph{display:flex;align-items:center;gap:6px;padding:12px 14px 6px}
    .cf-ph b{font-size:12.5px;font-weight:900}
    .cf-x{margin-right:auto;width:28px;height:28px;border-radius:10px;border:0;background:rgba(255,255,255,.08);color:inherit;cursor:pointer;transition:background .15s,color .15s,transform .15s}
    .cf-x:hover{background:#f43f5e;color:#fff;transform:rotate(90deg)}
    .cf-panel.light .cf-x{background:#f1f5f9}
    .cf-seg{display:inline-flex;gap:2px;background:rgba(255,255,255,.07);border-radius:11px;padding:3px}
    .cf-panel.light .cf-seg{background:#f1f5f9}
    .cf-seg button{border:0;background:transparent;color:inherit;opacity:.7;font-size:10.5px;font-weight:800;padding:4px 9px;border-radius:8px;cursor:pointer;font-family:inherit}
    .cf-seg button.on{background:rgba(255,255,255,.16);opacity:1}
    .cf-panel.light .cf-seg button.on{background:#fff;color:#4338ca;box-shadow:0 2px 6px -2px rgba(15,23,42,.3)}
    .cf-panel>*{flex-shrink:0}
    .cf-panel>.cf-lib,.cf-panel>.cf-body{flex-shrink:1;min-height:0}
    .cf-chips{display:flex;gap:5px;overflow-x:auto;padding:4px 14px 8px;scrollbar-width:none}
    .cf-chips::-webkit-scrollbar{display:none}
    .cf-chip{flex:none;border:1px solid rgba(255,255,255,.14);background:transparent;color:inherit;font-size:10.5px;font-weight:800;padding:4px 10px;border-radius:999px;cursor:pointer;font-family:inherit;opacity:.8}
    .cf-chip.on{background:var(--cf-acc,#6366f1);border-color:transparent;opacity:1;color:#fff}
    .cf-now{display:flex;gap:12px;align-items:center;padding:6px 14px}
    .cf-cover{flex:none;width:62px;height:62px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;position:relative;overflow:hidden;
        background:linear-gradient(135deg,#6366f1,#06b6d4)}
    .cf-cover::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 30% 20%,rgba(255,255,255,.35),transparent 55%)}
    .cf-eq{display:flex;gap:2px;align-items:flex-end;height:14px;margin-top:4px}
    .cf-eq span{width:3px;background:var(--cf-acc,#818cf8);border-radius:2px;height:3px}
    .cf-playing .cf-eq span{animation:cfEq .9s ease-in-out infinite}
    .cf-eq span:nth-child(2){animation-delay:.2s!important}.cf-eq span:nth-child(3){animation-delay:.4s!important}.cf-eq span:nth-child(4){animation-delay:.1s!important}
    @keyframes cfEq{0%,100%{height:3px}50%{height:14px}}
    .cf-ttl{font-size:13.5px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cf-sub{font-size:11px;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cf-seek{padding:2px 14px}
    .cf-range{-webkit-appearance:none;appearance:none;width:100%;height:5px;border-radius:5px;background:rgba(255,255,255,.15);outline:none;cursor:pointer;direction:ltr}
    .cf-panel.light .cf-range{background:#e2e8f0}
    .cf-range::-webkit-slider-thumb{-webkit-appearance:none;width:13px;height:13px;border-radius:50%;background:#fff;box-shadow:0 0 0 3px var(--cf-acc,#6366f1)}
    .cf-range::-moz-range-thumb{width:13px;height:13px;border-radius:50%;background:#fff;border:0;box-shadow:0 0 0 3px var(--cf-acc,#6366f1)}
    .cf-times{display:flex;justify-content:space-between;font-size:10px;opacity:.6;margin-top:3px;direction:ltr}
    .cf-ctrl{display:flex;align-items:center;justify-content:center;gap:10px;padding:4px 14px 8px;direction:ltr}
    .cf-ib{width:34px;height:34px;border-radius:12px;border:0;background:transparent;color:inherit;cursor:pointer;font-size:14px;opacity:.85;transition:.15s}
    .cf-ib:hover{background:rgba(255,255,255,.1);opacity:1}
    .cf-panel.light .cf-ib:hover{background:#f1f5f9}
    .cf-ib.on{color:var(--cf-acc,#818cf8);opacity:1}
    .cf-play{width:50px;height:50px;border-radius:50%;border:0;cursor:pointer;color:#fff;font-size:18px;background:var(--cf-acc2,linear-gradient(135deg,#6366f1,#06b6d4));box-shadow:0 10px 22px -8px rgba(99,102,241,.8)}
    .cf-row{display:flex;align-items:center;gap:8px;padding:0 14px 10px}
    /* ---- پخش‌کننده: نورِ رنگیِ زنده (رنگِ آهنگ)، صفحه‌ی گرامافون، طیفِ صدا ---- */
    .cf-player{--h1:245;--h2:190;background:radial-gradient(120% 80% at 100% 0%,hsl(var(--h1) 60% 22% / .9),transparent 60%),rgba(10,14,30,.96)}
    .cf-player>*:not(.cf-aura){position:relative;z-index:1}
    .cf-aura{position:absolute;inset:0;z-index:0;overflow:hidden;pointer-events:none;border-radius:inherit}
    .cf-aura i{position:absolute;width:230px;height:230px;border-radius:50%;filter:blur(55px);opacity:.38;transition:opacity 1s,background 1.2s;animation:cfAura 16s ease-in-out infinite alternate}
    .cf-aura i:nth-child(1){background:hsl(var(--h1) 85% 55%);top:-90px;right:-70px}
    .cf-aura i:nth-child(2){background:hsl(var(--h2) 85% 50%);top:90px;left:-110px;animation-duration:20s;animation-direction:alternate-reverse}
    .cf-aura i:nth-child(3){background:hsl(calc(var(--h1) + 150) 70% 45%);bottom:-130px;right:10px;animation-duration:24s;opacity:.2}
    .cf-player.cf-playing .cf-aura i{opacity:.58}.cf-player.cf-playing .cf-aura i:nth-child(3){opacity:.3}
    @keyframes cfAura{0%{transform:translate(0,0) scale(1)}50%{transform:translate(34px,24px) scale(1.18)}100%{transform:translate(-24px,36px) scale(.92)}}
    .cf-player .cf-ph b{background:linear-gradient(90deg,#fff,hsl(var(--h1) 90% 80%));-webkit-background-clip:text;background-clip:text;color:transparent}
    .cf-player .cf-ph b i{color:hsl(var(--h1) 90% 75%)}
    .cf-player .cf-chip.on{background:linear-gradient(135deg,hsl(var(--h1) 80% 58%),hsl(var(--h2) 80% 50%));box-shadow:0 4px 12px -4px hsl(var(--h1) 80% 55% / .8)}
    .cf-player .cf-now{padding:8px 14px 2px;gap:12px}
    .cf-art{position:relative;flex:none;width:104px;height:76px}
    .cf-art .cf-cover{position:absolute;right:0;top:0;width:76px;height:76px;border-radius:18px;z-index:2;font-size:26px;box-shadow:0 14px 26px -10px rgba(0,0,0,.75),inset 0 0 0 1px rgba(255,255,255,.12)}
    .cf-rec{position:absolute;right:6px;top:5px;width:66px;height:66px;z-index:1;transition:transform .7s cubic-bezier(.2,.9,.3,1.1)}
    .cf-rec i{position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 20deg,rgba(255,255,255,0) 0 40deg,rgba(255,255,255,.14) 55deg,rgba(255,255,255,0) 75deg 220deg,rgba(255,255,255,.08) 235deg,rgba(255,255,255,0) 255deg),repeating-radial-gradient(circle,#090c16 0 1.4px,#1b2133 1.4px 2.8px);
        box-shadow:0 8px 18px -8px rgba(0,0,0,.9)}
    .cf-rec i::before{content:'';position:absolute;inset:31%;border-radius:50%;background:var(--lbl,#6366f1)}
    .cf-rec i::after{content:'';position:absolute;inset:46%;border-radius:50%;background:#090c16}
    .cf-playing .cf-rec{transform:translateX(-30px)}
    .cf-playing .cf-rec i{animation:cfSpin 2.6s linear infinite}
    .cf-player .cf-ttl{font-size:15px;letter-spacing:-.2px}
    .cf-player .cf-sub{font-size:11px}
    .cf-viz{display:block;width:calc(100% - 28px);height:34px;margin:4px 14px 0}
    .cf-player .cf-range{background:linear-gradient(90deg,hsl(var(--h2) 90% 60%),hsl(var(--h1) 90% 68%)) left/var(--p,0%) 100% no-repeat,rgba(255,255,255,.13)}
    .cf-player .cf-range::-webkit-slider-thumb{box-shadow:0 0 0 3px hsl(var(--h1) 90% 66% / .55),0 0 14px hsl(var(--h1) 90% 66%)}
    .cf-player .cf-range::-moz-range-thumb{box-shadow:0 0 0 3px hsl(var(--h1) 90% 66% / .55),0 0 14px hsl(var(--h1) 90% 66%)}
    .cf-player .cf-play{position:relative;width:56px;height:56px;font-size:19px;background:linear-gradient(135deg,hsl(var(--h1) 85% 60%),hsl(var(--h2) 85% 48%));box-shadow:0 12px 26px -8px hsl(var(--h1) 85% 55% / .85);transition:transform .15s}
    .cf-player .cf-play:hover{transform:scale(1.06)}.cf-player .cf-play:active{transform:scale(.94)}
    .cf-player.cf-playing .cf-play::before,.cf-player.cf-playing .cf-play::after{content:'';position:absolute;inset:-5px;border-radius:50%;border:2px solid hsl(var(--h1) 90% 70% / .55);animation:cfPulse 2s ease-out infinite;pointer-events:none}
    .cf-player.cf-playing .cf-play::after{animation-delay:1s}
    @keyframes cfPulse{from{transform:scale(.92);opacity:1}to{transform:scale(1.45);opacity:0}}
    .cf-player .cf-ib.on{color:hsl(var(--h1) 90% 75%)}
    .cf-player .cf-tr.cur{background:linear-gradient(90deg,hsl(var(--h2) 70% 45% / .18),hsl(var(--h1) 70% 55% / .32))}
    .cf-lib{border-top:1px solid rgba(255,255,255,.08);display:flex;flex-direction:column;min-height:0;flex:1}
    .cf-panel.light .cf-lib{border-color:#eef2f7}
    .cf-in{width:100%;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:inherit;border-radius:12px;padding:7px 10px;font-size:12px;font-family:inherit;outline:none}
    .cf-panel.light .cf-in{border-color:#e2e8f0;background:#fff}
    .cf-in:focus{border-color:var(--cf-acc,#818cf8)}
    .cf-in option{color:#0f172a}
    .cf-list{overflow-y:auto;padding:4px 8px 8px;flex:none;height:auto;max-height:102px;min-height:56px}
    .cf-tr{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:12px;cursor:pointer;font-size:12px}
    .cf-tr:hover{background:rgba(255,255,255,.06)}
    .cf-panel.light .cf-tr:hover{background:#f8fafc}
    .cf-tr.cur{background:rgba(99,102,241,.22)}
    .cf-tr .n{width:22px;text-align:center;opacity:.5;font-size:10.5px;flex:none}
    .cf-tr .t{flex:1;min-width:0}
    .cf-tr .t b{display:block;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cf-tr .t small{display:block;font-size:10px;opacity:.6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cf-tag{font-size:9.5px;font-weight:900;border-radius:999px;padding:1px 7px;background:rgba(255,255,255,.1);flex:none}
    .cf-empty{text-align:center;font-size:11.5px;opacity:.6;padding:18px 10px;line-height:1.9}
    .cf-btn{border:0;border-radius:12px;padding:7px 12px;font-size:11.5px;font-weight:900;cursor:pointer;font-family:inherit;background:var(--cf-acc,#6366f1);color:#fff}
    .cf-btn.s{background:rgba(255,255,255,.1);color:inherit}
    .cf-panel.light .cf-btn.s{background:#f1f5f9;color:#334155}
    .cf-btn.d{background:rgba(244,63,94,.15);color:#fb7185}
    .cf-quota{height:6px;border-radius:6px;background:rgba(255,255,255,.12);overflow:hidden}
    .cf-quota i{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#eab308,#ef4444);background-size:350px 100%}
    .cf-tabs{display:flex;gap:2px;overflow-x:auto;padding:4px 10px 8px;scrollbar-width:none;border-bottom:1px solid #eef2f7}
    .cf-tabs::-webkit-scrollbar{display:none}
    .cf-tabs button{flex:none;border:0;background:transparent;font-family:inherit;font-size:11px;font-weight:800;color:#64748b;padding:7px 9px;border-radius:11px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;min-width:54px}
    .cf-tabs button i{font-size:15px}
    .cf-tabs button.on{background:#eef2ff;color:var(--cf-acc,#4338ca)}
    .cf-body{overflow-y:auto;padding:12px 14px 16px;flex:1;font-size:12px}
    .cf-lbl{display:block;font-size:10.5px;font-weight:800;color:#64748b;margin:8px 0 4px}
    .cf-card{border:1px solid #eef2f7;border-radius:16px;padding:10px 12px;margin-bottom:10px;background:#fff}
    .cf-card h4{font-size:12px;font-weight:900;margin:0 0 6px;display:flex;align-items:center;gap:6px}
    .cf-todo{display:flex;align-items:flex-start;gap:8px;padding:7px 4px;border-bottom:1px dashed #eef2f7}
    .cf-todo:last-child{border-bottom:0}
    .cf-todo input[type=checkbox]{margin-top:3px;accent-color:var(--cf-acc,#6366f1);width:16px;height:16px;flex:none}
    .cf-todo .tx{flex:1;min-width:0;font-size:12px;line-height:1.7}
    .cf-todo.done .tx{text-decoration:line-through;opacity:.5}
    .cf-todo small{display:block;font-size:10px;color:#94a3b8}
    .cf-todo small.late{color:#e11d48;font-weight:800}
    .cf-sw{position:relative;width:36px;height:20px;flex:none;cursor:pointer}
    .cf-sw input{opacity:0;width:0;height:0}
    .cf-sw span{position:absolute;inset:0;border-radius:20px;background:#cbd5e1;transition:.2s}
    .cf-sw span::after{content:'';position:absolute;top:2px;right:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.3)}
    .cf-sw input:checked+span{background:var(--cf-acc,#6366f1)}
    .cf-sw input:checked+span::after{right:18px}
    .cf-line{display:flex;align-items:center;gap:8px;padding:6px 0}
    .cf-line .grow{flex:1}
    .cf-stat{display:grid;grid-template-columns:repeat(2,1fr);gap:6px}
    .cf-stat button{border:1px solid #e2e8f0;background:#fff;border-radius:12px;padding:8px 6px;font-size:11.5px;font-weight:800;cursor:pointer;font-family:inherit;color:#334155;text-align:right}
    .cf-stat button.on{border-color:var(--cf-acc,#6366f1);background:#eef2ff;color:var(--cf-acc,#4338ca)}
    .cf-pomo-big{font-size:38px;font-weight:900;text-align:center;letter-spacing:1px;margin:4px 0;font-variant-numeric:tabular-nums}
    .cf-pops{position:fixed;left:14px;bottom:84px;z-index:9450;display:flex;flex-direction:column;gap:8px;width:min(330px,calc(100vw - 28px))}
    .cf-pop{background:#fff;color:#0f172a;border-radius:18px;padding:12px 14px;box-shadow:0 20px 40px -15px rgba(15,23,42,.45);border:1px solid #e2e8f0;animation:cfIn .25s ease;direction:rtl}
    .cf-pop b{font-size:13px}
    .cf-pop p{font-size:11.5px;color:#475569;margin:4px 0 8px;line-height:1.8}
    .cf-pal{position:fixed;inset:0;z-index:99990;background:rgba(15,23,42,.45);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:9vh 14px 14px;direction:rtl}
    .cf-pal-box{width:min(640px,100%);background:#fff;border-radius:22px;box-shadow:0 30px 70px -20px rgba(2,6,23,.6);overflow:hidden;animation:cfIn .18s ease}
    .cf-pal-in{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #eef2f7}
    .cf-pal-in input{flex:1;border:0;outline:none;font-size:15px;font-family:inherit;background:transparent}
    .cf-pal-res{max-height:60vh;overflow-y:auto;padding:6px}
    .cf-pal-g{font-size:10.5px;font-weight:900;color:#94a3b8;padding:8px 10px 4px}
    .cf-pal-it{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:12px;cursor:pointer}
    .cf-pal-it i.ic{width:30px;height:30px;border-radius:10px;background:#eef2ff;color:var(--cf-acc,#4f46e5);display:flex;align-items:center;justify-content:center;flex:none;font-size:13px}
    .cf-pal-it.sel,.cf-pal-it:hover{background:#f1f5ff}
    .cf-pal-it b{display:block;font-size:12.5px;color:#0f172a}
    .cf-pal-it small{display:block;font-size:10.5px;color:#64748b}
    .cf-kbd{display:inline-block;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:6px;padding:0 5px;font-size:10px;font-family:monospace;color:#475569;background:#f8fafc;direction:ltr}
    .cf-modal{position:fixed;inset:0;z-index:99980;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;padding:16px;direction:rtl}
    .cf-morning{width:min(520px,100%);border-radius:28px;overflow:hidden;background:#fff;box-shadow:0 40px 80px -25px rgba(2,6,23,.6);animation:cfIn .3s ease}
    .cf-morning .hd{padding:22px 24px 18px;color:#fff;position:relative;overflow:hidden}
    .cf-morning .hd::after{content:'';position:absolute;width:240px;height:240px;border-radius:50%;left:-60px;top:-120px;background:radial-gradient(circle,rgba(255,255,255,.35),transparent 70%)}
    .cf-morning .bd{padding:16px 22px 20px;max-height:56vh;overflow-y:auto}
    .cf-mi{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:12.5px}
    .cf-mi:last-child{border-bottom:0}
    .cf-mi i.ic{width:34px;height:34px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex:none}
    .cf-bday{position:fixed;top:78px;left:50%;transform:translateX(-50%);z-index:9300;background:linear-gradient(120deg,#fdf2f8,#eef2ff);border:1px solid #fbcfe8;border-radius:999px;padding:7px 10px 7px 16px;
        box-shadow:0 12px 30px -12px rgba(219,39,119,.45);display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;color:#831843;direction:rtl;max-width:calc(100vw - 24px)}
    .cf-mini-toast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:999999;background:#0f172a;color:#fff;padding:9px 16px;border-radius:12px;font-size:12px;direction:rtl}
    .cf-cpick{position:fixed;z-index:999990;width:300px;max-height:320px;display:flex;flex-direction:column;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 20px 40px -15px rgba(15,23,42,.4);direction:rtl;overflow:hidden}
    .cf-cpick .it{padding:8px 12px;cursor:pointer;border-bottom:1px solid #f8fafc}
    .cf-cpick .it:hover{background:#f1f5ff}
    .cf-cpick .it b{display:block;font-size:12px;color:#0f172a}
    .cf-cpick .it small{display:block;font-size:10.5px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    html.cf-dark{filter:invert(.9) hue-rotate(180deg);background:#fff}
    html.cf-dark img,html.cf-dark video,html.cf-dark canvas,html.cf-dark iframe,html.cf-dark .plate,html.cf-dark .ir-plate,html.cf-dark #cf-root,html.cf-dark .cf-pops,html.cf-dark .cf-bday,html.cf-dark [data-cf-noinv]{filter:invert(1) hue-rotate(180deg)}
    @media (max-width:640px){.cf-root{left:10px;bottom:10px}.cf-disc{width:46px;height:46px}.cf-hubbtn{width:40px;height:40px;border-radius:13px}.cf-panel,.cf-root.cf-has-mini .cf-panel{left:0;bottom:106px;width:calc(100vw - 20px);max-height:68vh}.cf-pops{left:10px;bottom:72px}}
    `;
    function injectCss() { if (document.getElementById('cf-css')) return; const st = document.createElement('style'); st.id = 'cf-css'; st.textContent = CSS; document.head.appendChild(st); }

    // ================= وضعیتِ کلی =================
    const S = {boot: null, F: {}, prefs: {}, tracks: [], likes: new Set(), playlists: [], genres: [], quota: null, canUpload: false,
               todos: [], canned: [], status: null, open: null, hubTab: null, libTab: 'queue', libQ: ''};
    const has = k => !!S.F[k];
    const savePrefs = (part) => { Object.assign(S.prefs, part); return api('prefs_save', {prefs: part}); };
    const dnd = () => has('dnd') && (S.prefs.dnd_until || 0) * 1000 > Date.now();
    Object.defineProperty(window, 'CF_DND', {get: () => dnd(), configurable: true});
    let root, dock, disc, hubBtn, pops, mini;

    // ================= ظاهر =================
    function applyTheme() {
        const t = (has('theme') && S.prefs.theme) || {};
        const dark = t.mode === 'dark' || (t.mode === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('cf-dark', !!dark);
        // بزرگ/کوچک کردنِ متن فقط روی محتوا (سربرگ، صفحه و پنجره‌ها)؛ نه روی کلِ body، چون نشانگرِ موس و
        // منوهایی که با مختصاتِ موس جا می‌گیرند (روی body) جابه‌جا می‌شدند
        document.body.style.zoom = '';
        let zs = document.getElementById('cf-zoom');
        if (t.font && t.font !== 100) {
            if (!zs) { zs = document.createElement('style'); zs.id = 'cf-zoom'; document.head.appendChild(zs); }
            zs.textContent = `${CFG.zoomTargets || 'body > header, body > main, body > .modal-overlay .modal-content'}{zoom:${t.font / 100}}`;
        } else if (zs) zs.remove();
        let st = document.getElementById('cf-accent');
        if (t.accent) {
            if (!st) { st = document.createElement('style'); st.id = 'cf-accent'; document.head.appendChild(st); }
            const a = t.accent;
            st.textContent = `:root{--cf-acc:${a};--cf-acc2:linear-gradient(135deg,${a},${a}cc)}
                .bg-indigo-600,.bg-indigo-500,.bg-blue-600,.bg-blue-500,.hover\\:bg-indigo-700:hover,.hover\\:bg-blue-700:hover{background-color:${a}!important}
                .text-indigo-600,.text-indigo-700,.text-blue-600,.text-blue-700{color:${a}!important}
                .border-indigo-500,.border-indigo-600,.border-blue-500{border-color:${a}!important}
                .accent-indigo-600{accent-color:${a}!important}`;
        } else if (st) st.remove();
    }

    // ================= پخش‌کننده‌ی موسیقی =================
    const P = {audio: null, cur: null, order: [], playing: false, errors: 0, playedSent: false, panel: null, restoring: false};
    const mp = () => Object.assign({source: 'both', genres: [], focus: false, shuffle: false, repeat: 'all', vol: 0.7, playlist: 0, liked: false}, S.prefs.music || {});
    function pool() {
        const m = mp(), focus = (S.boot && S.boot.focus_genres) || [];
        let list = S.tracks.filter(t => m.source === 'both' || (m.source === 'mine' ? t.owner === 'user' : t.owner === 'panel'));
        if (m.focus) list = list.filter(t => focus.includes(t.genre));
        else if (m.genres.length) list = list.filter(t => m.genres.includes(t.genre));
        if (m.liked) list = list.filter(t => S.likes.has(t.id));
        if (m.playlist) {
            const pl = S.playlists.find(p => p.id === m.playlist);
            if (pl) { const map = Object.fromEntries(S.tracks.map(t => [t.id, t])); list = pl.tracks.map(id => map[id]).filter(Boolean); }
        }
        return list;
    }
    function rebuildOrder(keepCur) {
        const ids = pool().map(t => t.id);
        if (mp().shuffle) { for (let i = ids.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [ids[i], ids[j]] = [ids[j], ids[i]]; } }
        if (keepCur && P.cur && ids.includes(P.cur.id)) { ids.splice(ids.indexOf(P.cur.id), 1); ids.unshift(P.cur.id); }
        P.order = ids;
    }
    const trackById = id => S.tracks.find(t => t.id === id);
    const coverOf = t => {
        const g = (t && t.genre) || '';
        const ic = /بی‌کلام|کلاسیک/.test(g) ? 'fa-feather' : /طبیعت|آرامش/.test(g) ? 'fa-leaf' : /شاد/.test(g) ? 'fa-face-laugh-beam' : /ملایم/.test(g) ? 'fa-moon' : /سنتی/.test(g) ? 'fa-guitar' : 'fa-music';
        let h = 0; for (const c of (t ? t.title + t.artist : 'x')) h = (h * 31 + c.charCodeAt(0)) % 360;
        return {ic, h, h2: (h + 60) % 360, bg: `linear-gradient(135deg,hsl(${h} 75% 55%),hsl(${(h + 60) % 360} 80% 45%))`};
    };
    // ---- طیفِ صدا (Web Audio). گرافِ صوتی فقط در پاسخ به کلیکِ کاربر ساخته می‌شود تا مرورگر صدا را قطع نکند؛
    //      اگر پشتیبانی نشد، نوارها با حرکتِ نرمِ ساختگی می‌رقصند ----
    const VZ = {ctx: null, an: null, data: null, raf: 0, fail: false, lv: []};
    function vzEnsure() {
        if (VZ.an || VZ.fail || !P.audio || !navigator.userActivation || !navigator.userActivation.hasBeenActive) return;
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) { VZ.fail = true; return; }
            const ctx = new AC();
            if (ctx.state !== 'running') { ctx.resume().catch(() => {}); }
            const src = ctx.createMediaElementSource(P.audio), an = ctx.createAnalyser();
            an.fftSize = 128; an.smoothingTimeConstant = .78;
            src.connect(an); an.connect(ctx.destination);
            VZ.ctx = ctx; VZ.an = an; VZ.data = new Uint8Array(an.frequencyBinCount);
            // اگر بافتِ صوتی راه نیفتاد، با اولین کلیک دوباره امتحان می‌شود
            document.addEventListener('pointerdown', () => { if (ctx.state !== 'running') ctx.resume().catch(() => {}); }, true);
        } catch (e) { VZ.fail = true; }
    }
    function vzStart() {
        cancelAnimationFrame(VZ.raf);
        const cv = P.panel && P.panel.querySelector('[data-viz]');
        if (!cv) return;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        const W = cv.clientWidth || 300, H = cv.clientHeight || 34;
        cv.width = W * dpr; cv.height = H * dpr;
        const g = cv.getContext('2d'); g.scale(dpr, dpr);
        const N = 36, bw = W / N;
        const draw = t => {
            if (!P.panel || !cv.isConnected) return;
            const h1 = +getComputedStyle(P.panel).getPropertyValue('--h1') || 245, h2 = +getComputedStyle(P.panel).getPropertyValue('--h2') || 190;
            let live = false;
            if (VZ.an && P.playing) { VZ.an.getByteFrequencyData(VZ.data); live = VZ.data.some(v => v > 0); }
            g.clearRect(0, 0, W, H);
            const grad = g.createLinearGradient(0, 0, W, 0);
            grad.addColorStop(0, `hsl(${h2} 90% 62%)`); grad.addColorStop(.5, `hsl(${h1} 90% 70%)`); grad.addColorStop(1, `hsl(${h2} 90% 62%)`);
            g.fillStyle = grad;
            for (let i = 0; i < N; i++) {
                const k = Math.abs(i - (N - 1) / 2) / ((N - 1) / 2);          // متقارن از وسط
                let v;
                if (live) v = VZ.data[Math.min(VZ.data.length - 1, Math.floor(k * 34) + 1)] / 255;
                else if (P.playing) v = (.35 + .3 * Math.sin(t / 260 + i * .7) * Math.sin(t / 410 + i * .23)) * (1 - k * .55);
                else v = .05;
                const cur = VZ.lv[i] || 0, nv = cur + (v - cur) * (v > cur ? .55 : .18);
                VZ.lv[i] = nv;
                const bh = Math.max(2, nv * (H - 2));
                const x = i * bw + bw * .2, w = bw * .6, y = (H - bh) / 2;
                g.globalAlpha = .45 + nv * .55;
                g.beginPath(); if (g.roundRect) g.roundRect(x, y, w, bh, w / 2); else g.rect(x, y, w, bh); g.fill();
            }
            g.globalAlpha = 1;
            VZ.raf = requestAnimationFrame(draw);
        };
        VZ.raf = requestAnimationFrame(draw);
    }
    function ensureAudio() {
        if (P.audio) return P.audio;
        const a = P.audio = new Audio();
        a.preload = 'metadata';
        a.volume = mp().vol;
        a.addEventListener('play', () => { P.playing = true; P.started = true; if (VZ.ctx && VZ.ctx.state !== 'running') VZ.ctx.resume().catch(() => {}); renderDisc(); renderNow(); mediaSession(); });
        a.addEventListener('pause', () => { P.playing = false; renderDisc(); renderNow(); saveResume(); });
        a.addEventListener('timeupdate', () => {
            renderSeek();
            if (!P.playedSent && a.currentTime > 30 && P.cur) { P.playedSent = true; api('played', {id: P.cur.id, duration: Math.round(a.duration || 0)}); }
            if (Math.floor(a.currentTime) % 5 === 0) saveResume();
        });
        a.addEventListener('loadedmetadata', () => { if (P.restoring && P.restoreAt) { try { a.currentTime = P.restoreAt; } catch (e) {} P.restoring = false; } renderSeek(); });
        a.addEventListener('ended', () => { if (mp().repeat === 'one') { a.currentTime = 0; a.play(); } else next(true); });
        a.addEventListener('error', () => {
            if (!P.cur) return;
            P.errors++;
            toast('«' + P.cur.title + '» پخش نشد' + (P.errors < Math.min(5, P.order.length) ? '؛ آهنگِ بعدی…' : '.'), 'warning');
            if (P.errors < Math.min(5, P.order.length)) setTimeout(() => next(true), 800);
        });
        // وقتی صدای دیگری (مثلِ پیامِ صوتیِ گفتگو) پخش شود، موسیقی مکث می‌کند
        document.addEventListener('play', e => { if (e.target !== a && /^(AUDIO|VIDEO)$/.test(e.target.tagName) && !a.paused) { a.pause(); toast('موسیقی مکث شد.', 'info'); } }, true);
        return a;
    }
    function load(t, autoplay, at) {
        if (!t) return;
        const a = ensureAudio();
        P.cur = t; P.playedSent = false;
        a.src = url('action=stream&id=' + t.id);
        if (at) { P.restoring = true; P.restoreAt = at; }
        if (autoplay) a.play().then(() => { P.errors = 0; }).catch(() => {});
        renderNow(); renderList(); renderMini(); saveResume();
    }
    function playPause() {
        if (!has('music')) return;
        const a = ensureAudio();
        vzEnsure();
        if (!P.cur) { rebuildOrder(); const t = trackById(P.order[0]); if (!t) { openPlayer(); toast('آهنگی برای پخش نیست؛ ژانر یا منبع را عوض کنید.', 'info'); return; } load(t, true); return; }
        if (a.paused) a.play().catch(() => {}); else a.pause();
    }
    function next(auto) {
        if (!P.order.length || (P.cur && !P.order.includes(P.cur.id))) rebuildOrder(true);
        if (!P.order.length) return;
        let i = P.cur ? P.order.indexOf(P.cur.id) : -1;
        i++;
        if (i >= P.order.length) { if (auto && mp().repeat === 'off') { P.audio.pause(); return; } if (mp().shuffle) rebuildOrder(); i = 0; }
        load(trackById(P.order[i]), true);
    }
    function prev() {
        const a = ensureAudio();
        if (a.currentTime > 4) { a.currentTime = 0; return; }
        if (!P.order.length) rebuildOrder(true);
        let i = P.cur ? P.order.indexOf(P.cur.id) : 0;
        i = i <= 0 ? P.order.length - 1 : i - 1;
        load(trackById(P.order[i]), true);
    }
    function saveResume() { if (P.cur && P.audio) LS.set('player:' + S.boot.kind, {id: P.cur.id, t: Math.floor(P.audio.currentTime || 0)}); }
    function mediaSession() {
        if (!('mediaSession' in navigator) || !P.cur) return;
        try {
            navigator.mediaSession.metadata = new MediaMetadata({title: P.cur.title, artist: P.cur.artist || '', album: P.cur.genre || ''});
            navigator.mediaSession.setActionHandler('play', () => P.audio.play());
            navigator.mediaSession.setActionHandler('pause', () => P.audio.pause());
            navigator.mediaSession.setActionHandler('nexttrack', () => next());
            navigator.mediaSession.setActionHandler('previoustrack', () => prev());
        } catch (e) {}
    }
    function renderDisc() {
        renderMini();
        if (!disc) return;
        disc.classList.toggle('playing', P.playing);
        disc.title = P.cur ? (P.playing ? 'در حالِ پخش: ' : 'مکث: ') + P.cur.title + ' (Alt+M)' : 'موسیقی (Alt+M)';
        if (P.panel) P.panel.classList.toggle('cf-playing', P.playing);
    }
    function renderSeek() {
        const a = P.audio;
        if (!a || !P.panel) { ring(); return; }
        const r = P.panel.querySelector('[data-seek]');
        if (r && !r._drag) r.value = a.duration ? (a.currentTime / a.duration * 1000) : 0;
        if (r) r.style.setProperty('--p', (r.value / 10) + '%');
        const tm = P.panel.querySelector('[data-times]');
        if (tm) tm.innerHTML = `<span>${mmss(a.currentTime)}</span><span>${a.duration && isFinite(a.duration) ? mmss(a.duration) : '--:--'}</span>`;
        ring();
    }
    function ring() {
        const c = disc && disc.querySelector('.cf-ring');
        if (!c) return;
        const a = P.audio, p = a && a.duration ? a.currentTime / a.duration : 0;
        c.style.strokeDasharray = `${(p * 157).toFixed(1)} 157`;
        const mr = mini && mini.querySelector('.cf-mring');
        if (mr) mr.style.strokeDasharray = `${(p * 100.5).toFixed(1)} 101`;
    }
    function buildMini() {
        mini = document.createElement('div');
        mini.className = 'cf-mini';
        // صفحه‌ی گردانِ کوچک با حلقه‌ی پیشرفت، نامِ آهنگ (اگر بلند بود آرام می‌لغزد) و کنترل‌ها
        mini.innerHTML = `<div class="cf-mc" data-m="open" title="بازکردنِ پخش‌کننده"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="16" stroke="rgba(255,255,255,.1)"></circle>
                <circle class="cf-mring" cx="18" cy="18" r="16"></circle><defs><linearGradient id="cfmg"><stop offset="0" stop-color="#a5b4fc"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs></svg><span class="cf-disk"></span></div>
            <div class="cf-mt" data-m="open"><b><span></span></b><small><span class="cf-meq"><i></i><i></i><i></i></span><span data-sub></span></small></div>
            <button type="button" data-m="prev" title="قبلی"><i class="fas fa-backward-step"></i></button>
            <button type="button" class="pp" data-m="play" title="پخش / مکث"></button>
            <button type="button" data-m="next" title="بعدی"><i class="fas fa-forward-step"></i></button>
            <button type="button" class="op" data-m="open" title="بازکردنِ پخش‌کننده"><i class="fas fa-chevron-up"></i></button>`;
        mini.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', e => {
            e.stopPropagation();
            const m = b.dataset.m;
            // مکث از خودِ نوار: نوار می‌ماند تا بشود دوباره پخش کرد
            if (m === 'play') P.holdMini = P.playing;
            if (m === 'open') openPlayer(); else if (m === 'play') playPause(); else if (m === 'next') next(); else prev();
        }));
        root.appendChild(mini);
    }
    function renderMini() {
        if (!mini) return;
        // فقط وقتی آهنگ پخش می‌شود (یا از خودِ نوار مکث شده)؛ اگر در پنل خاموش شد، بعد از بستنِ پنل همان دیسک برمی‌گردد
        if (P.playing) P.holdMini = false;
        const show = !!(P.cur && P.started && S.open !== 'player' && (P.playing || P.holdMini));
        root.classList.toggle('cf-has-mini', show);
        if (!P.cur) return;
        const t = P.cur, cv = coverOf(t);
        mini.classList.toggle('on', P.playing);
        mini.querySelector('.cf-disk').style.background = cv.bg;
        const tb = mini.querySelector('.cf-mt b'), ts = tb.firstElementChild;
        if (ts.textContent !== t.title) {
            ts.textContent = t.title; tb.classList.remove('mq');
            // نامِ بلند: آرام جابه‌جا می‌شود تا کامل خوانده شود
            requestAnimationFrame(() => { const over = ts.scrollWidth - tb.clientWidth; if (over > 4) { tb.style.setProperty('--mq', over + 'px'); tb.style.setProperty('--mqd', Math.max(6, over / 12) + 's'); tb.classList.add('mq'); } });
        }
        mini.querySelector('[data-sub]').textContent = t.artist || t.genre || '';
        mini.querySelector('[data-m="play"]').innerHTML = `<i class="fas ${P.playing ? 'fa-pause' : 'fa-play'}" style="${P.playing ? '' : 'margin-left:1px'}"></i>`;
        ring();
    }
    function renderNow() {
        if (!P.panel) return;
        const t = P.cur, cv = coverOf(t);
        const box = P.panel.querySelector('[data-now]');
        P.panel.style.setProperty('--h1', cv.h); P.panel.style.setProperty('--h2', cv.h2);
        box.innerHTML = `<div class="cf-art"><span class="cf-rec" style="--lbl:${cv.bg}"><i></i></span><div class="cf-cover" style="background:${cv.bg}"><i class="fas ${cv.ic}"></i></div></div>
            <div style="min-width:0;flex:1"><div class="cf-ttl">${t ? esc(t.title) : 'آهنگی انتخاب نشده'}</div>
            <div class="cf-sub">${t ? esc([t.artist, t.genre].filter(Boolean).join(' · ')) + (t.owner === 'user' ? ' · آهنگِ خودم' : '') : 'یک ژانر انتخاب کنید و پخش را بزنید'}</div>
            <div class="cf-eq"><span></span><span></span><span></span><span></span></div></div>`;
        const pb = P.panel.querySelector('[data-a="play"]');
        pb.innerHTML = `<i class="fas ${P.playing ? 'fa-pause' : 'fa-play'}" style="${P.playing ? '' : 'margin-left:3px'}"></i>`;
        const lk = P.panel.querySelector('[data-a="like"]');
        lk.classList.toggle('on', !!(t && S.likes.has(t.id)));
        lk.innerHTML = `<i class="${t && S.likes.has(t.id) ? 'fas' : 'far'} fa-heart"></i>`;
    }
    function openPlayer() {
        if (S.open === 'player') return closePanels();
        closePanels(true);
        S.open = 'player';
        P.holdMini = false;
        renderMini();
        const m = mp();
        const el = P.panel = document.createElement('div');
        el.className = 'cf-panel cf-player' + (P.playing ? ' cf-playing' : '');
        el.innerHTML = `<div class="cf-aura"><i></i><i></i><i></i></div>
            <div class="cf-ph"><b><i class="fas fa-compact-disc ml-1"></i>موسیقیِ من</b>
                <div class="cf-seg" data-src>${[['both', 'همه'], ['panel', 'پنل'], ['mine', 'خودم']].map(([k, t]) => `<button data-v="${k}" class="${m.source === k ? 'on' : ''}">${t}</button>`).join('')}</div>
                <button class="cf-ib ${m.focus ? 'on' : ''}" data-a="focus" title="حالتِ تمرکز: فقط بی‌کلام و آرام"><i class="fas fa-headphones"></i></button>
                <button class="cf-x" data-a="close" title="بستن (Esc)"><i class="fas fa-xmark"></i></button></div>
            <div class="cf-chips" data-genres></div>
            <div class="cf-now" data-now></div>
            <canvas class="cf-viz" data-viz></canvas>
            <div class="cf-seek"><input type="range" class="cf-range" min="0" max="1000" value="0" data-seek><div class="cf-times" data-times></div></div>
            <div class="cf-ctrl">
                <button class="cf-ib ${m.shuffle ? 'on' : ''}" data-a="shuffle" title="پخشِ تصادفی"><i class="fas fa-shuffle"></i></button>
                <button class="cf-ib" data-a="prev" title="قبلی"><i class="fas fa-backward-step"></i></button>
                <button class="cf-play" data-a="play"></button>
                <button class="cf-ib" data-a="next" title="بعدی (Alt+N)"><i class="fas fa-forward-step"></i></button>
                <button class="cf-ib ${m.repeat !== 'off' ? 'on' : ''}" data-a="repeat" title="تکرار"><i class="fas ${m.repeat === 'one' ? 'fa-repeat' : 'fa-arrows-rotate'}"></i>${m.repeat === 'one' ? '<small style="font-size:8px">۱</small>' : ''}</button>
            </div>
            <div class="cf-row"><button class="cf-ib" data-a="like" title="علاقه‌مندی"></button>
                <i class="fas fa-volume-low" style="opacity:.6;font-size:12px"></i><input type="range" class="cf-range" min="0" max="100" value="${Math.round(m.vol * 100)}" data-vol style="flex:1">
                <button class="cf-ib" data-a="lib" title="فهرست و آهنگ‌های من"><i class="fas fa-list-ul"></i></button></div>
            <div class="cf-lib ${LS.get('libOpen', false) ? '' : 'cf-hidden'}" data-lib></div>`;
        root.appendChild(el);
        renderGenres(); renderNow(); renderSeek(); renderLib();
        vzEnsure(); vzStart();
        const on = (a, fn) => el.querySelectorAll(`[data-a="${a}"]`).forEach(b => b.addEventListener('click', fn));
        on('close', closePanels);
        on('play', playPause); on('next', () => next()); on('prev', prev);
        on('shuffle', e => { const v = !mp().shuffle; savePrefs({music: Object.assign(mp(), {shuffle: v})}); e.currentTarget.classList.toggle('on', v); rebuildOrder(true); });
        on('repeat', e => { const seq = {all: 'one', one: 'off', off: 'all'}; const v = seq[mp().repeat] || 'all'; savePrefs({music: Object.assign(mp(), {repeat: v})}); const b = e.currentTarget;
            b.classList.toggle('on', v !== 'off'); b.innerHTML = `<i class="fas ${v === 'one' ? 'fa-repeat' : 'fa-arrows-rotate'}"></i>${v === 'one' ? '<small style="font-size:8px">۱</small>' : ''}`; toast(v === 'one' ? 'تکرارِ همین آهنگ' : v === 'all' ? 'تکرارِ فهرست' : 'بدونِ تکرار', 'info'); });
        on('like', () => toggleLike(P.cur && P.cur.id));
        on('focus', e => { const v = !mp().focus; savePrefs({music: Object.assign(mp(), {focus: v})}); e.currentTarget.classList.toggle('on', v); renderGenres(); rebuildOrder(true); renderLib();
            toast(v ? 'حالتِ تمرکز: فقط آهنگ‌های بی‌کلام و آرام.' : 'حالتِ تمرکز خاموش شد.', 'info'); });
        on('lib', () => { const l = el.querySelector('[data-lib]'); l.classList.toggle('cf-hidden'); LS.set('libOpen', !l.classList.contains('cf-hidden')); });
        el.querySelectorAll('[data-src] button').forEach(b => b.addEventListener('click', () => {
            el.querySelectorAll('[data-src] button').forEach(x => x.classList.toggle('on', x === b));
            savePrefs({music: Object.assign(mp(), {source: b.dataset.v, playlist: 0, liked: false})}); rebuildOrder(true); renderLib();
        }));
        const seek = el.querySelector('[data-seek]');
        seek.addEventListener('input', () => { seek._drag = true; });
        seek.addEventListener('change', () => { seek._drag = false; const a = ensureAudio(); if (a.duration) a.currentTime = seek.value / 1000 * a.duration; });
        const vol = el.querySelector('[data-vol]');
        const volFill = () => vol.style.setProperty('--p', vol.value + '%');
        volFill();
        vol.addEventListener('input', () => { ensureAudio().volume = vol.value / 100; volFill(); });
        vol.addEventListener('change', () => savePrefs({music: Object.assign(mp(), {vol: vol.value / 100})}));
    }
    function renderGenres() {
        const box = P.panel && P.panel.querySelector('[data-genres]');
        if (!box) return;
        const m = mp();
        if (m.focus) { box.innerHTML = `<span class="cf-chip on"><i class="fas fa-headphones ml-1"></i>تمرکز: ${esc(((S.boot && S.boot.focus_genres) || []).join('، '))}</span>`; return; }
        const counts = {};
        S.tracks.forEach(t => { counts[t.genre] = (counts[t.genre] || 0) + 1; });
        const gl = S.genres.filter(g => counts[g]).concat(Object.keys(counts).filter(g => g && !S.genres.includes(g)));
        box.innerHTML = `<button class="cf-chip ${!m.genres.length ? 'on' : ''}" data-g="">همه‌ی ژانرها</button>` + gl.map(g => `<button class="cf-chip ${m.genres.includes(g) ? 'on' : ''}" data-g="${esc(g)}">${esc(g)} <small style="opacity:.6">${fa(counts[g] || 0)}</small></button>`).join('');
        box.querySelectorAll('[data-g]').forEach(b => b.addEventListener('click', () => {
            let g = mp().genres.slice();
            if (!b.dataset.g) g = []; else if (g.includes(b.dataset.g)) g = g.filter(x => x !== b.dataset.g); else g.push(b.dataset.g);
            savePrefs({music: Object.assign(mp(), {genres: g, playlist: 0, liked: false})}); renderGenres(); rebuildOrder(true); renderLib();
        }));
    }
    function toggleLike(id) {
        if (!id) return;
        const on = !S.likes.has(id);
        if (on) S.likes.add(id); else S.likes.delete(id);
        api('like', {id, on: on ? 1 : 0});
        renderNow(); renderList();
    }
    function renderLib() {
        const box = P.panel && P.panel.querySelector('[data-lib]');
        if (!box) return;
        const tabs = [['queue', 'فهرستِ پخش'], ['liked', 'علاقه‌مندی‌ها'], ['pl', 'پلی‌لیست‌ها']];
        if (S.canUpload) tabs.push(['mine', 'آهنگ‌های من']);
        box.innerHTML = `<div style="padding:8px 12px 4px;display:flex;gap:6px;align-items:center"><div class="cf-seg" data-lt>${tabs.map(([k, t]) => `<button data-v="${k}" class="${S.libTab === k ? 'on' : ''}">${t}</button>`).join('')}</div></div>
            <div style="padding:2px 12px 4px"><input class="cf-in" data-q placeholder="جستجوی نام یا خواننده…" value="${esc(S.libQ)}"></div>
            <div data-extra></div><div class="cf-list" data-list></div>`;
        box.querySelectorAll('[data-lt] button').forEach(b => b.addEventListener('click', () => { S.libTab = b.dataset.v; renderLib(); }));
        box.querySelector('[data-q]').addEventListener('input', e => { S.libQ = e.target.value.trim(); renderList(); });
        renderList();
    }
    function renderList() {
        const box = P.panel && P.panel.querySelector('[data-list]');
        if (!box) return;
        const extra = P.panel.querySelector('[data-extra]');
        extra.innerHTML = '';
        const m = mp(), q = S.libQ;
        let list;
        if (S.libTab === 'queue') {
            if (!P.order.length || (P.cur && !P.order.includes(P.cur.id))) rebuildOrder(true);
            const map = Object.fromEntries(S.tracks.map(t => [t.id, t]));
            list = P.order.map(id => map[id]).filter(Boolean);
            if (m.playlist || m.liked) extra.innerHTML = `<div style="padding:0 12px 4px;font-size:10.5px;opacity:.75">در حالِ پخش از: <b>${m.liked ? 'علاقه‌مندی‌ها' : esc((S.playlists.find(p => p.id === m.playlist) || {}).name || '')}</b>
                <button class="cf-btn s" style="padding:2px 8px;margin-right:6px" data-a="clearmode">برگشت به همه</button></div>`;
        } else if (S.libTab === 'liked') list = S.tracks.filter(t => S.likes.has(t.id));
        else if (S.libTab === 'mine') list = S.tracks.filter(t => t.owner === 'user');
        else list = null;
        if (S.libTab === 'pl') { renderPlaylists(box, extra); return; }
        if (S.libTab === 'mine') renderUpload(extra);
        if (S.libTab === 'liked' && list.length) extra.insertAdjacentHTML('beforeend', `<div style="padding:0 12px 4px"><button class="cf-btn s" data-a="playliked"><i class="fas fa-play ml-1"></i>پخشِ علاقه‌مندی‌ها</button></div>`);
        const ex = (a, fn) => { const b = extra.querySelector(`[data-a="${a}"]`); if (b) b.onclick = fn; };
        ex('clearmode', () => { savePrefs({music: Object.assign(mp(), {playlist: 0, liked: false})}); rebuildOrder(true); renderList(); });
        ex('playliked', () => { savePrefs({music: Object.assign(mp(), {liked: true, playlist: 0})}); rebuildOrder(); S.libTab = 'queue'; renderLib(); load(trackById(P.order[0]), true); });
        if (q) list = list.filter(t => (t.title + ' ' + t.artist + ' ' + t.genre).includes(q));
        box.innerHTML = list.length ? list.map((t, i) => `<div class="cf-tr ${P.cur && P.cur.id === t.id ? 'cur' : ''}" data-id="${t.id}">
                <span class="n">${P.cur && P.cur.id === t.id && P.playing ? '<i class="fas fa-volume-high" style="color:var(--cf-acc,#818cf8)"></i>' : fa(i + 1)}</span>
                <span class="t"><b>${esc(t.title)}</b><small>${esc([t.artist, t.genre].filter(Boolean).join(' · '))}</small></span>
                ${t.owner === 'user' ? '<span class="cf-tag">خودم</span>' : ''}
                <button class="cf-ib" style="width:26px;height:26px;font-size:12px" data-like="${t.id}"><i class="${S.likes.has(t.id) ? 'fas' : 'far'} fa-heart" style="${S.likes.has(t.id) ? 'color:#f43f5e' : ''}"></i></button>
                <button class="cf-ib" style="width:26px;height:26px;font-size:12px" data-more="${t.id}"><i class="fas fa-ellipsis-vertical"></i></button></div>`).join('')
            : `<div class="cf-empty">${S.libTab === 'mine' ? 'هنوز آهنگی آپلود نکرده‌اید.' : S.libTab === 'liked' ? 'روی ♥ کنارِ آهنگ‌ها بزنید تا اینجا جمع شوند.' : S.tracks.length ? 'با این ژانر/منبع آهنگی نیست.' : 'کتابخانه هنوز خالی است.' + (S.canUpload ? '<br>از «آهنگ‌های من» آهنگِ خودتان را اضافه کنید.' : '')}</div>`;
        box.querySelectorAll('.cf-tr').forEach(r => r.addEventListener('click', e => {
            if (e.target.closest('[data-like],[data-more]')) return;
            const t = trackById(+r.dataset.id);
            if (S.libTab !== 'queue') { if (!pool().some(x => x.id === t.id)) savePrefs({music: Object.assign(mp(), {genres: [], playlist: 0, liked: false, focus: false, source: 'both'})}); rebuildOrder(true); }
            load(t, true);
        }));
        box.querySelectorAll('[data-like]').forEach(b => b.addEventListener('click', () => toggleLike(+b.dataset.like)));
        box.querySelectorAll('[data-more]').forEach(b => b.addEventListener('click', e => trackMenu(+b.dataset.more, e.currentTarget)));
    }
    function trackMenu(id, anchor) {
        const t = trackById(id);
        document.querySelectorAll('.cf-cpick').forEach(x => x.remove());
        const m = document.createElement('div');
        m.className = 'cf-cpick';
        m.style.width = '220px';
        const items = [['plnew', 'fa-plus', 'افزودن به پلی‌لیستِ تازه']].concat(S.playlists.map(p => ['pl:' + p.id, 'fa-list', 'افزودن به «' + p.name + '»']));
        if (t.owner === 'user') items.push(['edit', 'fa-pen', 'ویرایشِ نام و ژانر'], ['del', 'fa-trash', 'حذفِ آهنگ']);
        m.innerHTML = items.map(([k, ic, tx]) => `<div class="it" data-k="${k}"><b><i class="fas ${ic} ml-1" style="color:#6366f1"></i>${esc(tx)}</b></div>`).join('');
        document.body.appendChild(m);
        const r = anchor.getBoundingClientRect();
        m.style.left = Math.max(8, r.left - 10) + 'px';
        m.style.top = Math.max(8, Math.min(window.innerHeight - m.offsetHeight - 8, r.top - m.offsetHeight / 2)) + 'px';
        const off = e => { if (!m.contains(e.target)) { m.remove(); document.removeEventListener('mousedown', off, true); } };
        setTimeout(() => document.addEventListener('mousedown', off, true), 0);
        m.querySelectorAll('[data-k]').forEach(it => it.addEventListener('click', async () => {
            m.remove();
            const k = it.dataset.k;
            if (k === 'plnew') {
                const name = await ask('پلی‌لیستِ تازه', 'نامِ پلی‌لیست:', '');
                if (!name) return;
                const r2 = await api('pl_save', {name, tracks: [id]});
                if (r2.ok) { S.playlists.push({id: r2.id, name, tracks: [id]}); toast('پلی‌لیست ساخته شد.', 'success'); }
            } else if (k.startsWith('pl:')) {
                const pl = S.playlists.find(p => p.id === +k.slice(3));
                if (!pl.tracks.includes(id)) pl.tracks.push(id);
                const r2 = await api('pl_save', {id: pl.id, name: pl.name, tracks: pl.tracks});
                toast(r2.ok ? 'به «' + pl.name + '» اضافه شد.' : r2.error, r2.ok ? 'success' : 'error');
            } else if (k === 'edit') editTrack(t);
            else if (k === 'del') {
                if (!(await confirmBox('حذفِ آهنگ', '«' + t.title + '» حذف شود؟'))) return;
                const r2 = await api('my_track_delete', {id});
                if (!r2.ok) return toast(r2.error, 'error');
                S.tracks = S.tracks.filter(x => x.id !== id); S.quota = r2.quota || S.quota;
                if (P.cur && P.cur.id === id) { P.audio.pause(); P.cur = null; renderNow(); }
                rebuildOrder(true); renderList(); toast('حذف شد.', 'success');
            }
        }));
    }
    function editTrack(t) {
        const box = modalBox('ویرایشِ آهنگ', `<label class="cf-lbl">خواننده</label><input class="cf-in" data-f="artist" value="${esc(t.artist)}">
            <label class="cf-lbl">نامِ آهنگ</label><input class="cf-in" data-f="title" value="${esc(t.title)}">
            <label class="cf-lbl">ژانر</label><select class="cf-in" data-f="genre">${S.genres.map(g => `<option ${g === t.genre ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select>`, async b => {
            const v = f => b.querySelector(`[data-f="${f}"]`).value.trim();
            const r = await api('my_track_update', {id: t.id, title: v('title'), artist: v('artist'), genre: v('genre')});
            if (!r.ok) { toast(r.error, 'error'); return false; }
            Object.assign(t, {title: v('title') || 'بی‌نام', artist: v('artist'), genre: v('genre')});
            renderList(); renderNow(); renderGenres();
        });
        return box;
    }
    function renderPlaylists(box, extra) {
        extra.innerHTML = `<div style="padding:0 12px 4px"><button class="cf-btn s" data-a="plnew"><i class="fas fa-plus ml-1"></i>پلی‌لیستِ تازه از فهرستِ فعلی</button></div>`;
        extra.querySelector('[data-a="plnew"]').onclick = async () => {
            const name = await ask('پلی‌لیستِ تازه', 'نامِ پلی‌لیست (آهنگ‌های فهرستِ فعلی داخلش می‌روند):', '');
            if (!name) return;
            const ids = pool().map(t => t.id);
            const r = await api('pl_save', {name, tracks: ids});
            if (!r.ok) return toast(r.error, 'error');
            S.playlists.push({id: r.id, name, tracks: ids}); renderList();
        };
        box.innerHTML = S.playlists.length ? S.playlists.map(p => `<div class="cf-tr" data-pl="${p.id}"><span class="n"><i class="fas fa-list-ul"></i></span>
                <span class="t"><b>${esc(p.name)}</b><small>${fa(p.tracks.length)} آهنگ</small></span>
                <button class="cf-ib" style="width:26px;height:26px;font-size:12px" data-pdel="${p.id}" title="حذفِ پلی‌لیست"><i class="fas fa-trash"></i></button></div>`).join('')
            : '<div class="cf-empty">پلی‌لیستی ندارید؛ از منوی ⋮ کنارِ هر آهنگ هم می‌شود اضافه کرد.</div>';
        box.querySelectorAll('[data-pl]').forEach(r => r.addEventListener('click', e => {
            if (e.target.closest('[data-pdel]')) return;
            savePrefs({music: Object.assign(mp(), {playlist: +r.dataset.pl, liked: false})}); rebuildOrder(); S.libTab = 'queue'; renderLib();
            const t = trackById(P.order[0]); if (t) load(t, true); else toast('این پلی‌لیست خالی است.', 'info');
        }));
        box.querySelectorAll('[data-pdel]').forEach(b => b.addEventListener('click', async () => {
            if (!(await confirmBox('حذفِ پلی‌لیست', 'این پلی‌لیست حذف شود؟ (آهنگ‌ها حذف نمی‌شوند)'))) return;
            await api('pl_delete', {id: +b.dataset.pdel}); S.playlists = S.playlists.filter(p => p.id !== +b.dataset.pdel);
            if (mp().playlist === +b.dataset.pdel) savePrefs({music: Object.assign(mp(), {playlist: 0})});
            renderList();
        }));
    }
    function renderUpload(extra) {
        const q = S.quota || {used: 0, limit: 1};
        const pct = Math.min(100, q.used / q.limit * 100);
        extra.innerHTML = `<div style="padding:0 12px 6px">
            <div style="display:flex;justify-content:space-between;font-size:10px;opacity:.75;margin-bottom:3px"><span>فضای آپلود</span><span>${fa((q.used / 1048576).toFixed(1))} از ${fa(Math.round(q.limit / 1048576))} مگابایت</span></div>
            <div class="cf-quota"><i style="width:${pct}%"></i></div>
            <div style="display:flex;gap:6px;margin-top:8px"><select class="cf-in" data-ug style="flex:1">${S.genres.map(g => `<option>${esc(g)}</option>`).join('')}</select>
                <label class="cf-btn" style="cursor:pointer;white-space:nowrap"><i class="fas fa-upload ml-1"></i>آپلود<input type="file" multiple accept="audio/*,.mp3,.m4a,.ogg,.wav,.flac,.aac,.opus" hidden data-uf></label></div>
            <div data-up style="font-size:10.5px;margin-top:6px"></div></div>`;
        // پیشرفت از مدیرِ آپلود (با بستن/باز کردنِ پخش‌کننده یا رفتن به صفحه‌ی دیگرِ پنل ادامه دارد)
        upMount(extra.querySelector('[data-up]'), 'mine', true);
        extra.querySelector('[data-uf]').addEventListener('change', async e => {
            const files = [...e.target.files]; e.target.value = '';
            await upAdd(files, 'mine', extra.querySelector('[data-ug]').value);
            if (S.open === 'player') { renderGenres(); renderList(); }
        });
    }
    // ================= مدیرِ آپلودِ آهنگ =================
    // مستقل از صفحه: با رفتن به تبِ دیگرِ پنل آپلود ادامه دارد و با برگشتن پیشرفتش دیده می‌شود. آپلودِ تکه‌تکه (۱٫۵ مگابایتی) با offset؛
    // اگر صفحه بسته/تازه شد، سرور تکه‌های رسیده را نگه می‌دارد: زیپِ کامل‌رسیده خودکار باز می‌شود و فایلِ نیمه‌کاره با انتخابِ دوباره‌ی
    // همان فایل از همان‌جا ادامه پیدا می‌کند. زیپ قدم‌به‌قدم باز می‌شود (zip_step) تا شمارشِ آهنگ‌ها هم دیده شود.
    const UP = {items: [], subs: new Set(), running: false, res: {added: [], skipped: [], errors: [], quota: null}, last: null, resumed: false, t: 0};
    const CH = 1536 * 1024;
    const mbs = b => fa((b / 1048576).toFixed(b < 10485760 ? 1 : 0));
    function upNotify(force) {
        const n = Date.now();
        if (!force && n - UP.t < 250) { if (!UP.tm) UP.tm = setTimeout(() => { UP.tm = null; upNotify(true); }, 260); return; }
        UP.t = n;
        UP.subs.forEach(fn => { try { fn(); } catch (e) {} });
        upPill();
    }
    function upPersist() {
        LS.set('up', UP.items.filter(i => i.status === 'up' || i.status === 'wait' || i.status === 'paused').map(i => ({uid: i.uid, name: i.name, size: i.size, lm: i.lm})));
    }
    const upActive = () => UP.items.some(i => ['wait', 'up', 'zip', 'zipwait'].includes(i.status));
    // files: آرایه‌ی File؛ target: panel | mine
    function upAdd(files, target, genre) {
        const ids = [];
        // دسته‌ی تازه بعد از تمام شدنِ قبلی: فهرست و نتیجه از نو (نیمه‌کاره‌ها می‌مانند)
        if (!upActive()) { UP.items = UP.items.filter(i => i.status === 'paused' || i.status === 'zipwait'); UP.res = {added: [], skipped: [], errors: [], quota: null}; }
        files.forEach(f => {
            const lm = String(f.lastModified || '');
            // همان فایلِ نیمه‌کاره (نام + حجم + تاریخ) => ادامه از جای قبلی
            const old = UP.items.find(i => i.status === 'paused' && i.name === f.name && i.size === f.size && (!i.lm || !lm || i.lm === lm));
            if (old) { Object.assign(old, {file: f, status: 'wait', msg: '', target, genre: old.genre || genre}); ids.push(old.uid); return; }
            const it = {uid: Math.random().toString(36).slice(2, 12) + Date.now().toString(36), file: f, name: f.name, size: f.size, lm, have: 0, target, genre,
                        status: 'wait', msg: '', zip: /\.zip$/i.test(f.name), zipPos: 0, zipTotal: 0, added: 0};
            UP.items.push(it); ids.push(it.uid);
        });
        upPersist(); upNotify(true);
        const p = new Promise(resolve => { UP.waiters = (UP.waiters || []).concat([{ids, resolve}]); });
        upRun();
        return p;
    }
    async function upPost(fd) {
        for (let k = 0; k < 6; k++) {
            try {
                const r = await fetch(url('_=' + Date.now()), {method: 'POST', body: fd});
                if (r.status >= 500 || r.status === 0) throw new Error('http ' + r.status);
                return await r.json();
            } catch (e) { await new Promise(r => setTimeout(r, Math.min(16000, 1000 * 2 ** k))); }
        }
        return {ok: false, error: 'ارتباط با سرور قطع شد.'};
    }
    function upTake(d) {
        UP.res.added.push(...(d.added || [])); UP.res.skipped.push(...(d.skipped || [])); UP.res.errors.push(...(d.errors || []));
        if (d.quota) { UP.res.quota = d.quota; S.quota = d.quota; }
        if ((d.added || []).length) {
            try { S.tracks = S.tracks.concat(d.added); if (S.boot) { rebuildOrder(true); if (S.open === 'player') { renderGenres(); renderList(); } } } catch (e) {}
        }
    }
    async function upZip(it) {
        it.status = 'zip'; upNotify(true);
        let fails = 0;
        while (it.status === 'zip') {
            const d = await api('zip_step', {upload_id: it.uid});
            if (!d.ok) {
                if (d.gone || ++fails > 4) { it.status = d.gone && it.zipTotal && it.zipPos >= it.zipTotal ? 'done' : 'err'; it.msg = d.error || ''; if (it.status === 'err') UP.res.errors.push(`«${it.name}»: ${d.error}`); break; }
                await new Promise(r => setTimeout(r, 2000)); continue;
            }
            fails = 0;
            it.zipPos = d.zip_pos; it.zipTotal = d.zip_total; it.added += (d.added || []).length;
            upTake(d);
            if (d.done) { it.status = 'done'; api('up_ack', {upload_id: it.uid}); break; }
            upNotify();
        }
        upNotify(true);
    }
    async function upOne(it) {
        it.status = 'up'; it.t0 = Date.now(); it.h0 = it.have; upNotify(true);
        const total = Math.max(1, Math.ceil(it.size / CH));
        let i = Math.floor(it.have / CH), resync = 0;
        if (it.have && it.have % CH) i = 0;
        while (i < total) {
            if (it.status !== 'up') return;   // لغو
            const fd = new FormData();
            fd.append('action', 'up_chunk'); fd.append('upload_id', it.uid); fd.append('index', i); fd.append('total', total); fd.append('offset', i * CH);
            fd.append('name', it.name); fd.append('size', it.size); fd.append('lm', it.lm); fd.append('target', it.target); fd.append('genre', it.genre || '');
            fd.append('chunk', it.file.slice(i * CH, (i + 1) * CH), 'blob');
            const d = await upPost(fd);
            if (it.status !== 'up') return;
            if (!d.ok && d.resync && resync++ < 4) { i = d.have % CH === 0 ? d.have / CH : 0; it.have = i * CH; continue; }
            if (!d.ok) { it.status = 'err'; it.msg = d.error || 'خطا'; UP.res.errors.push(`«${it.name}»: ${it.msg}`); upNotify(true); return; }
            resync = 0; i++;
            it.have = Math.min(it.size, i * CH); upNotify();
            if (d.zip) { it.zipTotal = d.zip_total || 0; await upZip(it); return; }
            if (d.done) { upTake(d); api('up_ack', {upload_id: it.uid}); it.added = (d.added || []).length; if ((d.errors || []).length && !it.added) { it.status = 'err'; it.msg = d.errors[0]; } else if ((d.skipped || []).length && !it.added) { it.status = 'skip'; it.msg = d.skipped[0]; } else it.status = 'done'; }
        }
        upNotify(true);
    }
    async function upRun() {
        if (UP.running) return;
        UP.running = true;
        let it;
        while ((it = UP.items.find(x => x.status === 'wait' || (x.status === 'zipwait')))) {
            if (it.status === 'zipwait') await upZip(it); else await upOne(it);
            upPersist();
        }
        UP.running = false;
        UP.last = Date.now();
        upPersist(); upNotify(true);
        (UP.waiters || []).forEach(w => {
            const mine = UP.items.filter(x => w.ids.includes(x.uid));
            if (mine.every(x => !['wait', 'up', 'zip', 'zipwait'].includes(x.status))) w.resolve(UP.res);
        });
        UP.waiters = (UP.waiters || []).filter(w => UP.items.filter(x => w.ids.includes(x.uid)).some(x => ['wait', 'up', 'zip', 'zipwait'].includes(x.status)));
        if (UP.res.added.length) toast(`${fa(UP.res.added.length)} آهنگ اضافه شد.`, 'success');
        try { window.dispatchEvent(new CustomEvent('cf-upload-done', {detail: UP.res})); } catch (e) {}
    }
    async function upCancel(uid) {
        const it = UP.items.find(i => i.uid === uid);
        if (it) { it.status = 'cancel'; it.msg = 'لغو شد'; }
        await api('up_cancel', {upload_id: uid});
        upPersist(); upNotify(true);
    }
    // بعد از برگشت به صفحه: کارهای نیمه‌کاره‌ی سرور (زیپِ در حالِ باز شدن ادامه می‌یابد؛ فایلِ نیمه‌کاره منتظرِ انتخابِ دوباره می‌ماند)
    async function upResume() {
        if (UP.resumed) return; UP.resumed = true;
        const d = await api('up_jobs');
        if (!d.ok) return;
        const local = LS.get('up', []);
        (d.jobs || []).forEach(j => {
            if (UP.items.some(i => i.uid === j.uid)) return;
            if (j.phase === 'done') {   // در غیابِ کاربر تمام شد (مثلاً صفحه وسطِ باز شدنِ زیپ تازه شد)
                UP.items.push({uid: j.uid, file: null, name: j.name, size: 0, have: 0, target: j.target || 'panel', zip: /\.zip$/i.test(j.name), added: j.added, status: 'done',
                               msg: 'وقتی این صفحه باز نبود تمام شد'});
                UP.res.away = (UP.res.away || 0) + j.added; UP.res.awaySkip = (UP.res.awaySkip || 0) + j.skipped;
                api('up_ack', {upload_id: j.uid});
                return;
            }
            UP.items.push({uid: j.uid, file: null, name: j.name, size: j.size, lm: j.lm || (local.find(l => l.uid === j.uid) || {}).lm || '', have: j.have, target: j.target, genre: j.genre,
                           zip: /\.zip$/i.test(j.name), zipPos: j.zip_pos, zipTotal: j.zip_total, added: 0,
                           status: j.phase === 'zip' ? 'zipwait' : 'paused', msg: j.phase === 'zip' ? '' : 'برای ادامه همین فایل را دوباره انتخاب کنید'});
        });
        upPersist(); upNotify(true);
        if (UP.items.some(i => i.status === 'zipwait')) upRun();
    }
    function upStats(target) {
        const its = UP.items.filter(i => !target || i.target === target);
        const tot = its.filter(i => i.status !== 'cancel').reduce((a, i) => a + i.size, 0) || 1;
        const sent = its.filter(i => i.status !== 'cancel').reduce((a, i) => a + (['done', 'zip', 'zipwait', 'skip'].includes(i.status) ? i.size : i.have), 0);
        const cur = its.find(i => i.status === 'up');
        let speed = 0, eta = 0;
        if (cur && Date.now() - cur.t0 > 1500) { speed = (cur.have - cur.h0) / ((Date.now() - cur.t0) / 1000); const left = tot - sent; eta = speed > 0 ? left / speed : 0; }
        return {its, tot, sent, pct: Math.min(100, sent / tot * 100), cur, speed, eta, zip: its.find(i => i.status === 'zip' || i.status === 'zipwait'), active: its.some(i => ['wait', 'up', 'zip', 'zipwait'].includes(i.status))};
    }
    const etaTxt = s => s < 60 ? `${fa(Math.max(1, Math.round(s)))} ثانیه` : s < 3600 ? `${fa(Math.round(s / 60))} دقیقه` : `${fa((s / 3600).toFixed(1))} ساعت`;
    const ST = {wait: ['در صف', '#94a3b8'], up: ['در حالِ آپلود', '#6366f1'], zip: ['در حالِ باز کردنِ زیپ', '#d97706'], zipwait: ['منتظرِ باز شدن', '#d97706'], done: ['تمام شد', '#059669'],
                skip: ['رد شد', '#64748b'], err: ['خطا', '#e11d48'], paused: ['نیمه‌کاره', '#d97706'], cancel: ['لغو شد', '#94a3b8']};
    function upHtml(target, dark) {
        const s = upStats(target);
        if (!s.its.length) return '';
        const c = dark ? {bg: 'rgba(255,255,255,.08)', tr: 'rgba(255,255,255,.12)', mut: 'rgba(255,255,255,.6)', tx: '#fff', bd: 'rgba(255,255,255,.12)'} : {bg: '#fff', tr: '#ede9fe', mut: '#64748b', tx: '#334155', bd: '#e9d5ff'};
        const bar = (p, col) => `<div style="height:8px;border-radius:99px;background:${c.tr};overflow:hidden"><div style="height:100%;width:${p.toFixed(1)}%;border-radius:99px;background:${col};transition:width .3s"></div></div>`;
        const zp = s.zip && s.zip.zipTotal ? s.zip.zipPos / s.zip.zipTotal * 100 : 0;
        let head;
        if (s.active) {
            head = s.zip ? `<div style="display:flex;justify-content:space-between;font-size:11px;font-weight:800;color:${c.tx};margin-bottom:5px"><span><i class="fas fa-file-zipper" style="color:#d97706;margin-left:4px"></i>باز کردنِ «${esc(s.zip.name)}»</span><span>${fa(s.zip.zipPos)} از ${fa(s.zip.zipTotal)} آهنگ · ${fa(Math.round(zp))}٪</span></div>${bar(zp, 'linear-gradient(90deg,#f59e0b,#f97316)')}`
                : `<div style="display:flex;justify-content:space-between;font-size:11px;font-weight:800;color:${c.tx};margin-bottom:5px"><span><i class="fas fa-cloud-arrow-up" style="color:#7c3aed;margin-left:4px"></i>${s.cur ? `«${esc(s.cur.name)}»` : 'آماده‌سازی…'}</span><span>${fa(Math.floor(s.pct))}٪</span></div>${bar(s.pct, 'linear-gradient(90deg,#8b5cf6,#6366f1)')}
                   <div style="display:flex;justify-content:space-between;font-size:10px;color:${c.mut};margin-top:4px"><span>${mbs(s.sent)} از ${mbs(s.tot)} مگابایت${s.speed ? ` · ${mbs(s.speed)} مگ/ثانیه` : ''}</span><span>${s.eta ? `حدود ${etaTxt(s.eta)} مانده` : ''}</span></div>`;
        } else {
            const r = UP.res;
            const nAdd = r.added.length + (r.away || 0), nSkip = r.skipped.length + (r.awaySkip || 0);
            head = `<div style="font-size:11px;font-weight:800;color:${c.tx}"><i class="fas fa-circle-check" style="color:#059669;margin-left:4px"></i>آپلود تمام شد: ${fa(nAdd)} آهنگ اضافه شد${nSkip ? ` · ${fa(nSkip)} مورد رد شد (تکراری/نامربوط)` : ''}</div>`
                 + (r.errors.length ? `<div style="font-size:10.5px;color:#e11d48;margin-top:4px">${r.errors.slice(0, 4).map(esc).join('<br>')}${r.errors.length > 4 ? '<br>…' : ''}</div>` : '');
            if (s.its.some(i => i.status === 'paused')) head = `<div style="font-size:11px;font-weight:800;color:#b45309"><i class="fas fa-circle-pause" style="margin-left:4px"></i>آپلودِ نیمه‌کاره: همان فایل را دوباره انتخاب کنید تا از همان‌جا ادامه پیدا کند.</div>`;
        }
        const rows = s.its.slice(-12).map(i => {
            const [lab, col] = ST[i.status] || ['', '#64748b'];
            const p = i.status === 'zip' || i.status === 'zipwait' ? (i.zipTotal ? i.zipPos / i.zipTotal * 100 : 0) : (['done', 'skip'].includes(i.status) ? 100 : i.have / (i.size || 1) * 100);
            return `<div style="display:flex;align-items:center;gap:8px;font-size:10.5px;padding:5px 0;border-top:1px solid ${c.bd}">
                <i class="fas ${i.zip ? 'fa-file-zipper' : 'fa-music'}" style="color:${col};width:14px;text-align:center"></i>
                <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:${c.tx}" dir="auto" title="${esc(i.msg || i.name)}">${esc(i.name)}${i.msg ? ` <small style="color:${c.mut}">— ${esc(i.msg)}</small>` : ''}</span>
                <span style="color:${c.mut};white-space:nowrap">${i.size ? mbs(i.size) + ' مگ' : ''}</span>
                <span style="width:70px">${bar(p, col)}</span>
                <b style="color:${col};white-space:nowrap;min-width:84px;text-align:left">${lab}${i.status === 'up' ? ' ' + fa(Math.floor(p)) + '٪' : ''}${i.status === 'done' && i.zip ? ` (${fa(i.added)})` : ''}</b>
                ${['wait', 'up', 'paused', 'zipwait'].includes(i.status) ? `<button type="button" data-upcancel="${i.uid}" title="لغو" style="color:#e11d48;background:none;border:0;cursor:pointer;padding:0 2px">✕</button>` : ''}</div>`;
        }).join('');
        return `<div style="background:${c.bg};border:1px solid ${c.bd};border-radius:14px;padding:10px 12px;margin-top:8px">${head}<div style="margin-top:8px">${rows}</div></div>`;
    }
    // el را با وضعیتِ آپلود به‌روز نگه می‌دارد (تا وقتی در صفحه است)؛ برگشت به صفحه = نمایشِ همان پیشرفت
    function upMount(el, target, dark) {
        const fn = () => {
            if (!el.isConnected) { UP.subs.delete(fn); return; }
            el.innerHTML = upHtml(target, dark);
            el.querySelectorAll('[data-upcancel]').forEach(b => b.onclick = () => upCancel(b.dataset.upcancel));
        };
        UP.subs.add(fn); fn();
        upResume();
    }
    // نشانگرِ کوچکِ شناور: هر جای پنل پیشرفتِ آپلود دیده می‌شود
    function upPill() {
        let pill = document.getElementById('cf-up-pill');
        const s = upStats('');
        const recent = UP.last && Date.now() - UP.last < 8000;
        if (!s.active && !recent) { if (pill) pill.remove(); return; }
        if (!pill) {
            pill = document.createElement('button');
            pill.id = 'cf-up-pill'; pill.type = 'button';
            pill.style.cssText = 'position:fixed;left:14px;bottom:' + ((CFG.bottom || 14) + 124) + 'px;z-index:9990;display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:0;cursor:pointer;'
                + 'background:linear-gradient(135deg,#4c1d95,#4338ca);color:#fff;font:800 11px inherit;box-shadow:0 10px 30px -8px rgba(76,29,149,.6);direction:rtl';
            pill.onclick = () => {
                const panel = UP.items.some(i => i.target === 'panel');
                if (panel && typeof window.switchTab === 'function' && document.getElementById('cf-lib-root')) {
                    window.switchTab('settings');
                    setTimeout(() => { const r = document.getElementById('cf-lib-root'); if (r) r.scrollIntoView({behavior: 'smooth', block: 'start'}); }, 300);
                } else if (S.boot) openPlayer();
            };
            document.body.appendChild(pill);
        }
        if (!s.active) { pill.innerHTML = `<i class="fas fa-circle-check"></i>آپلود تمام شد · ${fa(UP.res.added.length)} آهنگ`; setTimeout(upPill, 8200); return; }
        const p = s.zip ? (s.zip.zipTotal ? s.zip.zipPos / s.zip.zipTotal * 100 : 0) : s.pct;
        pill.innerHTML = `<i class="fas ${s.zip ? 'fa-file-zipper' : 'fa-cloud-arrow-up'}"></i>${s.zip ? 'باز کردنِ زیپ' : 'آپلودِ آهنگ'} ${fa(Math.floor(p))}٪
            <span style="width:60px;height:5px;border-radius:9px;background:rgba(255,255,255,.25);overflow:hidden;display:inline-block"><i style="display:block;height:100%;width:${p.toFixed(1)}%;background:#22d3ee"></i></span>`;
    }
    window.addEventListener('beforeunload', e => {
        if (UP.items.some(i => i.status === 'up' || i.status === 'wait')) { e.preventDefault(); e.returnValue = 'آپلودِ آهنگ هنوز تمام نشده.'; return e.returnValue; }
    });
    // سازگاری با فراخوانی‌های قبلی
    async function uploadFiles(files, target, genre, onProg) {
        if (onProg) onProg('در حالِ آپلود…');
        return upAdd(files, target, genre);
    }
    const uploadSummary = r => `<span style="color:#22c55e">${fa(r.added.length)} آهنگ اضافه شد.</span>` + (r.skipped.length ? ` <span style="opacity:.75">${fa(r.skipped.length)} مورد رد شد (تکراری/نامربوط).</span>` : '')
        + (r.errors.length ? `<div style="color:#fb7185;margin-top:3px">${r.errors.slice(0, 4).map(esc).join('<br>')}${r.errors.length > 4 ? '<br>…' : ''}</div>` : '');
    async function loadMusic() {
        const r = await api('music_list');
        if (!r.ok) return;
        S.tracks = r.tracks; S.likes = new Set(r.likes); S.playlists = r.playlists; S.genres = r.genres; S.quota = r.quota; S.canUpload = r.can_upload;
        rebuildOrder();
        const sv = LS.get('player:' + S.boot.kind, null);
        if (sv && trackById(sv.id)) load(trackById(sv.id), false, sv.t);
    }

    // ================= جعبه‌ابزارِ من =================
    const HUB_TABS = [['todo', 'fa-list-check', 'کارها'], ['health', 'fa-heart-pulse', 'تمرکز'], ['status', 'fa-circle-dot', 'وضعیت'], ['canned', 'fa-bolt', 'متن‌ها'],
                      ['tools', 'fa-calculator', 'ابزار'], ['theme', 'fa-palette', 'ظاهر'], ['keys', 'fa-keyboard', 'میانبر']];
    const hubTabs = () => HUB_TABS.filter(([k]) => k === 'status' ? (has('status') || has('dnd')) : k === 'keys' ? (has('search') || has('music')) : k === 'theme' ? (has('theme') || has('birthdays') || has('todo') || has('health')) : k === 'tools' ? (has('tools') && Object.keys(S.F).some(x => x.indexOf('tool_') === 0 && S.F[x])) : has(k));
    function openHub(tab) {
        if (S.open === 'hub' && (!tab || tab === S.hubTab)) return closePanels();
        closePanels(true);
        const tabs = hubTabs();
        if (!tabs.length) return;
        S.open = 'hub';
        S.hubTab = tab && tabs.some(t => t[0] === tab) ? tab : (S.hubTab && tabs.some(t => t[0] === S.hubTab) ? S.hubTab : tabs[0][0]);
        const el = S.hubEl = document.createElement('div');
        el.className = 'cf-panel light';
        el.innerHTML = `<div class="cf-ph"><b><i class="fas fa-wand-magic-sparkles ml-1" style="color:var(--cf-acc,#6366f1)"></i>جعبه‌ابزارِ من</b>
            ${window.Announce ? '<button class="cf-btn s" style="margin-right:auto;background:#f1f5f9;color:#475569;padding:5px 10px" data-a="ann" title="اعلان‌های اخیر"><i class="fas fa-bullhorn ml-1"></i>اعلان‌ها</button>' : ''}
            <button class="cf-x" data-a="close" title="بستن (Esc)" ${window.Announce ? 'style="margin-right:4px"' : ''}><i class="fas fa-xmark"></i></button></div>
            <div class="cf-tabs">${tabs.map(([k, ic, t]) => `<button data-t="${k}" class="${S.hubTab === k ? 'on' : ''}"><i class="fas ${ic}"></i>${t}</button>`).join('')}</div>
            <div class="cf-body" data-body></div>`;
        root.appendChild(el);
        el.querySelector('[data-a="close"]').onclick = closePanels;
        const an = el.querySelector('[data-a="ann"]'); if (an) an.onclick = () => { closePanels(); window.Announce.history(); };
        el.querySelectorAll('[data-t]').forEach(b => b.addEventListener('click', () => { S.hubTab = b.dataset.t; el.querySelectorAll('[data-t]').forEach(x => x.classList.toggle('on', x === b)); renderHub(); }));
        renderHub();
    }
    function renderHub() {
        const b = S.hubEl && S.hubEl.querySelector('[data-body]');
        if (!b) return;
        b.style.padding = '';
        ({todo: hubTodo, health: hubHealth, status: hubStatus, canned: hubCanned, tools: hubTools, theme: hubTheme, keys: hubKeys})[S.hubTab](b);
    }
    // بستن با انیمیشن (instant: وقتی پنلِ دیگری جایش باز می‌شود)
    function closePanels(instant) {
        [P.panel, S.hubEl].forEach(el => {
            if (!el) return;
            if (instant === true) { el.remove(); return; }
            el.classList.add('cf-out');
            setTimeout(() => el.remove(), 210);
        });
        P.panel = null; S.hubEl = null;
        S.open = null;
        renderMini();
    }
    // ---- کارها ----
    function hubTodo(b) {
        const tj = jstr(...todayJ());
        const pend = S.todos.filter(t => !t.done), done = S.todos.filter(t => t.done);
        const item = t => {
            const late = !t.done && t.due && t.due < tj;
            return `<div class="cf-todo ${t.done ? 'done' : ''}"><input type="checkbox" data-done="${t.id}" ${t.done ? 'checked' : ''}>
                <div class="tx">${esc(t.text)}${t.due || t.remind_j ? `<small class="${late ? 'late' : ''}">${[t.due ? (t.due === tj ? 'امروز' : fa(t.due)) + (late ? ' (گذشته)' : '') : '', t.remind_j ? '<i class="far fa-bell"></i> ' + fa(t.remind_j.slice(11)) : ''].filter(Boolean).join(' · ')}</small>` : ''}</div>
                <button class="cf-ib" style="width:26px;height:26px;font-size:11px;color:#94a3b8" data-edit="${t.id}"><i class="fas fa-pen"></i></button>
                <button class="cf-ib" style="width:26px;height:26px;font-size:11px;color:#f43f5e" data-del="${t.id}"><i class="fas fa-xmark"></i></button></div>`;
        };
        b.innerHTML = `<div class="cf-card" style="background:linear-gradient(120deg,#eef2ff,#fff)">
                <input class="cf-in" data-f="text" placeholder="کارِ تازه… (Enter)" style="background:#fff">
                <div style="display:flex;gap:6px;margin-top:6px"><input class="cf-in" data-f="due" placeholder="تاریخ (اختیاری) ${fa(tj)}" dir="ltr" style="background:#fff">
                <input class="cf-in" data-f="time" type="time" title="ساعتِ یادآور (اختیاری)" style="background:#fff;width:110px"><button class="cf-btn" data-a="add">افزودن</button></div>
                ${S.boot.kind === 'S' ? '<p style="font-size:10px;color:#64748b;margin-top:6px"><i class="fas fa-circle-info ml-1"></i>کارِ تیک‌خورده خودکار به «شرحِ کارهای امروز» در کارکرد من اضافه می‌شود.</p>' : ''}</div>
            <div class="cf-card"><h4><i class="fas fa-list-check" style="color:#6366f1"></i>در انتظار (${fa(pend.length)})</h4>${pend.length ? pend.map(item).join('') : '<div class="cf-empty">کارِ ماندهای ندارید 🎉</div>'}</div>
            ${done.length ? `<div class="cf-card"><h4><i class="fas fa-check-double" style="color:#10b981"></i>انجام‌شده‌های اخیر</h4>${done.map(item).join('')}</div>` : ''}`;
        const g = f => b.querySelector(`[data-f="${f}"]`);
        const add = async () => {
            const text = g('text').value.trim();
            if (!text) return g('text').focus();
            const r = await api('todo_save', {id: b._edit || 0, text, due: en(g('due').value.trim()), remind_time: g('time').value});
            if (!r.ok) return toast(r.error, 'error');
            S.todos = r.todos; b._edit = 0; renderHub(); updateBadge();
            if (g('time').value) askNotify();
        };
        b.querySelector('[data-a="add"]').onclick = add;
        g('text').addEventListener('keydown', e => { if (e.key === 'Enter') add(); });
        b.querySelectorAll('[data-done]').forEach(c => c.addEventListener('change', async () => {
            const r = await api('todo_done', {id: +c.dataset.done, done: c.checked ? 1 : 0});
            if (r.ok) { S.todos = r.todos; if (r.logged) toast('به شرحِ کارهای امروز اضافه شد.', 'success'); renderHub(); updateBadge(); }
        }));
        b.querySelectorAll('[data-del]').forEach(x => x.addEventListener('click', async () => { const r = await api('todo_delete', {id: +x.dataset.del}); if (r.ok) { S.todos = r.todos; renderHub(); updateBadge(); } }));
        b.querySelectorAll('[data-edit]').forEach(x => x.addEventListener('click', () => {
            const t = S.todos.find(y => y.id === +x.dataset.edit);
            g('text').value = t.text; g('due').value = fa(t.due); g('time').value = t.remind_j ? t.remind_j.slice(11) : '';
            b._edit = t.id; b.querySelector('[data-a="add"]').textContent = 'ذخیره'; g('text').focus();
        }));
    }
    // ---- تمرکز و سلامت ----
    const HP = () => Object.assign({water: {on: false, min: 60}, stretch: {on: false, min: 50}, eyes: {on: false, min: 20}, work_hours: true, sound: true}, S.prefs.health || {});
    const PM = () => Object.assign({work: 25, brk: 5, long: 15}, S.prefs.pomo || {});
    const HEALTH = {water: ['💧', 'آب بنوشید', 'یک لیوان آب بنوشید؛ بدنِ خوب‌آب‌رسانی‌شده بهتر تمرکز می‌کند.'], stretch: ['🧘', 'کمی کشش', 'چند دقیقه بلند شوید، قدم بزنید و گردن و شانه‌ها را کش بدهید.'],
                    eyes: ['👀', 'استراحتِ چشم', 'قانونِ ۲۰-۲۰-۲۰: ۲۰ ثانیه به جایی حدودِ ۶ متر دورتر نگاه کنید و چند بار پلک بزنید.']};
    function hubHealth(b) {
        if (!has('health')) { b.innerHTML = '<div class="cf-empty">این بخش برای شما فعال نیست.</div>'; return; }
        const h = HP(), pm = PM(), st = LS.get('pomo', null);
        const left = st ? (st.paused ? st.left : Math.max(0, Math.round((st.end - Date.now()) / 1000))) : pm.work * 60;
        b.innerHTML = `<div class="cf-card" style="background:linear-gradient(135deg,${st && st.phase !== 'work' ? '#ecfdf5,#fff' : '#eef2ff,#fff'})">
                <h4><i class="fas fa-stopwatch" style="color:#6366f1"></i>تایمرِ تمرکز (پومودورو) <span style="margin-right:auto;font-size:10.5px;color:#64748b">${st ? (st.phase === 'work' ? 'وقتِ کار' : 'وقتِ استراحت') : 'آماده'} · دورِ ${fa((st && st.count) || 0)}</span></h4>
                <div class="cf-pomo-big" data-pomo>${mmss(left)}</div>
                <div style="display:flex;gap:6px;justify-content:center">${!st || st.paused ? `<button class="cf-btn" data-a="pstart"><i class="fas fa-play ml-1"></i>${st ? 'ادامه' : 'شروع'}</button>` : '<button class="cf-btn s" data-a="ppause"><i class="fas fa-pause ml-1"></i>مکث</button>'}
                    ${st ? '<button class="cf-btn s" data-a="pskip"><i class="fas fa-forward ml-1"></i>مرحله‌ی بعد</button><button class="cf-btn d" data-a="preset">توقف</button>' : ''}</div>
                <div style="display:flex;gap:6px;margin-top:8px;font-size:10.5px;align-items:center;justify-content:center;color:#64748b">کار <input class="cf-in" data-p="work" value="${fa(pm.work)}" style="width:48px;padding:3px 6px;text-align:center"> دقیقه ·
                    استراحت <input class="cf-in" data-p="brk" value="${fa(pm.brk)}" style="width:48px;padding:3px 6px;text-align:center"> دقیقه</div>
                ${has('music') ? '<label class="cf-line" style="justify-content:center;font-size:10.5px;color:#64748b"><input type="checkbox" data-p="music" ' + (LS.get('pomoMusic', false) ? 'checked' : '') + '> هنگامِ کار موسیقیِ «تمرکز» پخش شود</label>' : ''}</div>
            <div class="cf-card"><h4><i class="fas fa-heart-pulse" style="color:#f43f5e"></i>یادآورهای سلامت <span style="margin-right:auto;font-size:10px;color:#94a3b8">آرام و بی‌مزاحمت</span></h4>
                ${Object.entries(HEALTH).map(([k, v]) => `<div class="cf-line"><span style="font-size:18px">${v[0]}</span><span class="grow"><b>${v[1]}</b><small style="display:block;color:#94a3b8;font-size:10px">هر
                    <input class="cf-in" data-hm="${k}" value="${fa(h[k].min)}" style="width:44px;padding:2px 5px;text-align:center;display:inline-block"> دقیقه</small></span>
                    <label class="cf-sw"><input type="checkbox" data-h="${k}" ${h[k].on ? 'checked' : ''}><span></span></label></div>`).join('')}
                <div class="cf-line"><span class="grow" style="font-size:11px">فقط در ساعتِ کاری</span><label class="cf-sw"><input type="checkbox" data-h2="work_hours" ${h.work_hours ? 'checked' : ''}><span></span></label></div>
                <div class="cf-line"><span class="grow" style="font-size:11px">صدای ملایم برای یادآورها</span><label class="cf-sw"><input type="checkbox" data-h2="sound" ${h.sound ? 'checked' : ''}><span></span></label></div></div>`;
        const save = () => {
            const n = {}; Object.keys(HEALTH).forEach(k => { n[k] = {on: b.querySelector(`[data-h="${k}"]`).checked, min: +en(b.querySelector(`[data-hm="${k}"]`).value) || h[k].min}; });
            n.work_hours = b.querySelector('[data-h2="work_hours"]').checked; n.sound = b.querySelector('[data-h2="sound"]').checked;
            Object.keys(HEALTH).forEach(k => { if (n[k].on && !h[k].on) LS.set('h:' + k, Date.now()); });
            savePrefs({health: n});
        };
        b.querySelectorAll('[data-h],[data-h2],[data-hm]').forEach(x => x.addEventListener('change', save));
        const savePm = () => savePrefs({pomo: {work: +en(b.querySelector('[data-p="work"]').value) || 25, brk: +en(b.querySelector('[data-p="brk"]').value) || 5, long: pm.long}});
        b.querySelectorAll('[data-p="work"],[data-p="brk"]').forEach(x => x.addEventListener('change', savePm));
        const pmus = b.querySelector('[data-p="music"]'); if (pmus) pmus.onchange = () => LS.set('pomoMusic', pmus.checked);
        const on = (a, fn) => { const x = b.querySelector(`[data-a="${a}"]`); if (x) x.onclick = () => { fn(); renderHub(); }; };
        on('pstart', pomoStart); on('ppause', pomoPause); on('pskip', () => pomoNext(true)); on('preset', () => { LS.set('pomo', null); pomoBadge(); });
    }
    function pomoStart() {
        let st = LS.get('pomo', null);
        if (st && st.paused) { st.end = Date.now() + st.left * 1000; st.paused = false; }
        else st = {phase: 'work', end: Date.now() + PM().work * 60000, count: (st && st.count) || 0, paused: false};
        LS.set('pomo', st);
        if (st.phase === 'work' && LS.get('pomoMusic', false) && has('music') && (!P.audio || P.audio.paused)) { savePrefs({music: Object.assign(mp(), {focus: true})}); rebuildOrder(); playPause(); }
        pomoBadge();
    }
    function pomoPause() { const st = LS.get('pomo', null); if (!st) return; st.left = Math.max(0, Math.round((st.end - Date.now()) / 1000)); st.paused = true; LS.set('pomo', st); pomoBadge(); }
    function pomoNext(manual) {
        const st = LS.get('pomo', null) || {phase: 'brk', count: 0};
        const work = st.phase !== 'work';
        const n = {phase: work ? 'work' : 'brk', count: st.count + (work ? 0 : 1), paused: true, left: (work ? PM().work : PM().brk) * 60};
        LS.set('pomo', n);
        if (!manual) {
            chime();
            popCard(work ? '⏱️' : '☕', work ? 'استراحت تمام شد' : 'وقتِ استراحت!', work ? 'آماده‌ی یک دورِ تمرکزِ تازه هستید؟' : `${fa(PM().brk)} دقیقه از صفحه فاصله بگیرید، آب بنوشید و کمی راه بروید.`,
                [[work ? 'شروعِ دورِ بعد' : 'شروعِ استراحت', () => { pomoStart(); if (S.hubTab === 'health') renderHub(); }], ['بعداً', () => {}]], 'pomo');
            notifyBrowser(work ? 'استراحت تمام شد' : 'وقتِ استراحت!', work ? 'دورِ تازه‌ی تمرکز' : 'چند دقیقه استراحت کنید');
        }
        pomoBadge();
    }
    function pomoBadge() {
        const st = LS.get('pomo', null), el = hubBtn && hubBtn.querySelector('.cf-pomo');
        let left = 0;
        if (st) left = st.paused ? st.left : Math.max(0, Math.round((st.end - Date.now()) / 1000));
        if (el) { el.style.display = st && !st.paused ? 'block' : 'none'; el.textContent = (st && st.phase !== 'work' ? '☕ ' : '') + mmss(left); }
        const big = S.hubEl && S.hubEl.querySelector('[data-pomo]');
        if (big) big.textContent = mmss(st ? left : PM().work * 60);
        if (st && !st.paused && left <= 0) pomoNext(false);
    }
    function inWorkHours() {
        const wh = S.boot.work_hours;
        if (!wh) return true;
        const [, , , hh, mi, wd] = irDate();
        const off = String(wh.offdays || '').split(',').filter(Boolean).map(Number);
        if (off.includes(wd)) return false;
        const cur = hh * 60 + mi, [h1, m1] = (wh.start || '08:00').split(':').map(Number), [h2, m2] = (wh.end || '16:00').split(':').map(Number);
        return cur >= h1 * 60 + m1 && cur <= h2 * 60 + m2;
    }
    function healthTick() {
        if (!has('health') || dnd()) return;
        const h = HP();
        if (h.work_hours && !inWorkHours()) return;
        Object.keys(HEALTH).forEach(k => {
            if (!h[k].on) return;
            const last = LS.get('h:' + k, 0);
            if (!last) { LS.set('h:' + k, Date.now()); return; }
            if (Date.now() - last < h[k].min * 60000 || document.querySelector(`.cf-pop[data-k="h:${k}"]`)) return;
            const v = HEALTH[k];
            if (h.sound) chime(0.04);
            popCard(v[0], v[1], v[2], [['انجام شد ✓', () => LS.set('h:' + k, Date.now())], ['۱۰ دقیقه‌ی دیگر', () => LS.set('h:' + k, Date.now() - (h[k].min - 10) * 60000)]], 'h:' + k);
            notifyBrowser(v[1], v[2]);
            LS.set('h:' + k, Date.now() + 365 * 864e5);   // تا وقتی کارت باز است دوباره نیاید (با دکمه‌ها درست می‌شود)
        });
    }
    // ---- وضعیت و «مزاحم نشوید» ----
    function hubStatus(b) {
        const cur = S.status, sts = S.boot.statuses || {}, du = S.prefs.dnd_until || 0;
        const left = du * 1000 > Date.now() ? Math.round((du * 1000 - Date.now()) / 60000) : 0;
        b.innerHTML = `${has('dnd') ? `<div class="cf-card" style="background:${left ? 'linear-gradient(120deg,#fef2f2,#fff)' : '#fff'}"><h4><i class="fas fa-bell-slash" style="color:#e11d48"></i>مزاحم نشوید
                ${left ? `<span style="margin-right:auto;color:#e11d48;font-size:10.5px">فعال · ${fa(left >= 60 ? Math.floor(left / 60) + ' ساعت و ' + (left % 60) : left)} دقیقه مانده</span>` : ''}</h4>
                <p style="font-size:10.5px;color:#64748b;margin-bottom:6px">یادآورها، تایمر و اعلان‌های مرورگر بی‌صدا می‌شوند${has('status') ? ' و همکاران وضعیتِ «مزاحم نشوید» را کنارِ نامتان می‌بینند' : ''}.</p>
                <div style="display:flex;gap:5px;flex-wrap:wrap">${[[30, '۳۰ دقیقه'], [60, '۱ ساعت'], [120, '۲ ساعت'], ['eod', 'تا آخرِ روز']].map(([v, t]) => `<button class="cf-btn s" data-dnd="${v}">${t}</button>`).join('')}
                ${left ? '<button class="cf-btn d" data-dnd="0">خاموش</button>' : ''}</div></div>` : ''}
            ${has('status') ? `<div class="cf-card"><h4><i class="fas fa-circle-dot" style="color:#10b981"></i>وضعیتِ من <span style="margin-right:auto;font-size:10px;color:#94a3b8">در گفتگو و فهرستِ کاربران دیده می‌شود</span></h4>
                <div class="cf-stat">${Object.entries(sts).filter(([k]) => k !== 'custom').map(([k, s]) => `<button data-st="${k}" class="${(cur ? cur.code : 'ready') === k ? 'on' : ''}">${s.icon} ${esc(s.label)}</button>`).join('')}</div>
                <label class="cf-lbl">متنِ دلخواه (اختیاری)</label><div style="display:flex;gap:6px"><input class="cf-in" data-f="txt" placeholder="مثلاً «تا ساعت ۱۴ در بیمه‌ی مرکزی»" value="${esc(cur && cur.code === 'custom' ? cur.text : '')}">
                <button class="cf-btn" data-a="custom">ثبت</button></div>
                <label class="cf-lbl">تا کی؟</label><select class="cf-in" data-f="until"><option value="0">تا وقتی خودم عوض کنم</option><option value="30">۳۰ دقیقه</option><option value="60">۱ ساعت</option><option value="120">۲ ساعت</option><option value="eod">تا آخرِ امروز</option></select>
                ${cur && cur.until ? `<p style="font-size:10px;color:#64748b;margin-top:6px">تا ساعتِ ${fa(new Date(cur.until * 1000 + 12600000).toISOString().slice(11, 16))}</p>` : ''}</div>` : ''}`;
        const untilOf = v => v === 'eod' ? Math.floor(new Date(new Date().setHours(23, 59, 0, 0)).getTime() / 1000) : (+v ? Math.floor(Date.now() / 1000) + v * 60 : 0);
        b.querySelectorAll('[data-dnd]').forEach(x => x.addEventListener('click', async () => {
            const v = x.dataset.dnd, u = v === '0' ? 0 : untilOf(v === 'eod' ? 'eod' : +v);
            await savePrefs({dnd_until: u});
            if (has('status')) { const r = await api('status_set', u ? {code: 'dnd', until: u} : {code: S.status && S.status.code === 'dnd' ? '' : (S.status ? S.status.code : ''), text: S.status ? S.status.text : '', until: S.status ? S.status.until : 0}); if (r.ok) S.status = r.status; }
            toast(u ? 'حالتِ «مزاحم نشوید» روشن شد.' : 'حالتِ «مزاحم نشوید» خاموش شد.', 'success');
            renderHub(); statusDot();
        }));
        const setSt = async (code, text) => {
            const r = await api('status_set', {code, text, until: untilOf(b.querySelector('[data-f="until"]').value)});
            if (!r.ok) return toast(r.error, 'error');
            S.status = r.status; toast('وضعیت ثبت شد.', 'success'); renderHub(); statusDot();
        };
        b.querySelectorAll('[data-st]').forEach(x => x.addEventListener('click', () => setSt(x.dataset.st, '')));
        const cb = b.querySelector('[data-a="custom"]'); if (cb) cb.onclick = () => { const t = b.querySelector('[data-f="txt"]').value.trim(); if (t) setSt('custom', t); };
    }
    function statusDot() {
        const d = hubBtn && hubBtn.querySelector('.cf-sdot');
        if (d) d.textContent = dnd() ? '🔕' : (S.status ? S.status.icon : '');
    }
    // ---- متن‌های آماده ----
    function hubCanned(b) {
        b.innerHTML = `<div class="cf-card" style="background:linear-gradient(120deg,#fffbeb,#fff)"><h4><i class="fas fa-bolt" style="color:#f59e0b"></i>متنِ آماده‌ی تازه</h4>
                <input class="cf-in" data-f="title" placeholder="عنوان (مثلاً «مدارک ناقص»)" style="background:#fff">
                <textarea class="cf-in" data-f="body" rows="3" placeholder="متنِ پیام…" style="margin-top:6px;background:#fff;resize:vertical"></textarea>
                <div style="display:flex;gap:6px;margin-top:6px;align-items:center">${S.boot.is_admin ? '<label style="font-size:10.5px;display:flex;align-items:center;gap:4px"><input type="checkbox" data-f="global"> برای همه‌ی همکاران</label>' : ''}
                <button class="cf-btn" data-a="save" style="margin-right:auto">ذخیره</button></div>
                <p style="font-size:10px;color:#64748b;margin-top:6px"><i class="fas fa-circle-info ml-1"></i>در گفتگو دکمه‌ی <i class="fas fa-bolt"></i> کنارِ ایموجی را بزنید تا متن در جای پیام بنشیند.</p></div>
            <div class="cf-card">${S.canned.length ? S.canned.map(c => `<div class="cf-todo"><div class="tx"><b style="font-size:12px">${esc(c.title)}</b> ${c.global ? '<span class="cf-tag" style="background:#eef2ff;color:#4338ca">همگانی</span>' : ''}
                    <small style="white-space:pre-wrap;color:#64748b;font-size:10.5px">${esc(c.body)}</small></div>
                    <button class="cf-ib" style="width:26px;height:26px;font-size:11px;color:#64748b" data-copy="${c.id}" title="کپی"><i class="far fa-copy"></i></button>
                    ${c.mine || (c.global && S.boot.is_admin) ? `<button class="cf-ib" style="width:26px;height:26px;font-size:11px;color:#94a3b8" data-edit="${c.id}"><i class="fas fa-pen"></i></button>
                    <button class="cf-ib" style="width:26px;height:26px;font-size:11px;color:#f43f5e" data-del="${c.id}"><i class="fas fa-xmark"></i></button>` : ''}</div>`).join('') : '<div class="cf-empty">هنوز متنی ندارید.</div>'}</div>`;
        const g = f => b.querySelector(`[data-f="${f}"]`);
        b.querySelector('[data-a="save"]').onclick = async () => {
            const r = await api('canned_save', {id: b._edit || 0, title: g('title').value, body: g('body').value, global: g('global') && g('global').checked ? 1 : 0});
            if (!r.ok) return toast(r.error, 'error');
            S.canned = r.canned; b._edit = 0; renderHub(); toast('ذخیره شد.', 'success');
        };
        b.querySelectorAll('[data-copy]').forEach(x => x.addEventListener('click', () => { const c = S.canned.find(y => y.id === +x.dataset.copy); navigator.clipboard && navigator.clipboard.writeText(c.body); toast('کپی شد.', 'success'); }));
        b.querySelectorAll('[data-edit]').forEach(x => x.addEventListener('click', () => { const c = S.canned.find(y => y.id === +x.dataset.edit); g('title').value = c.title; g('body').value = c.body; if (g('global')) g('global').checked = c.global; b._edit = c.id; g('title').focus(); }));
        b.querySelectorAll('[data-del]').forEach(x => x.addEventListener('click', async () => { const r = await api('canned_delete', {id: +x.dataset.del}); if (r.ok) { S.canned = r.canned; renderHub(); } }));
    }
    // انتخابگرِ متنِ آماده برای گفتگو (chat-ui.js)
    function cannedPick(anchor, onPick) {
        document.querySelectorAll('.cf-cpick').forEach(x => x.remove());
        const m = document.createElement('div');
        m.className = 'cf-cpick';
        const draw = q => {
            const list = S.canned.filter(c => !q || (c.title + c.body).includes(q));
            m.querySelector('[data-l]').innerHTML = list.length ? list.map(c => `<div class="it" data-id="${c.id}"><b>${esc(c.title)}</b><small>${esc(c.body)}</small></div>`).join('')
                : `<div class="cf-empty" style="opacity:1;color:#64748b">${S.canned.length ? 'پیدا نشد.' : 'هنوز متنِ آماده‌ای ندارید.'}</div>`;
            m.querySelectorAll('[data-id]').forEach(it => it.addEventListener('click', () => { onPick(S.canned.find(c => c.id === +it.dataset.id).body); m.remove(); }));
        };
        m.innerHTML = `<div style="padding:8px"><input class="cf-in" style="border-color:#e2e8f0" placeholder="جستجوی متنِ آماده…"></div><div data-l style="overflow-y:auto;flex:1"></div>
            <div style="padding:6px 10px;border-top:1px solid #f1f5f9;text-align:left"><button class="cf-btn s" style="background:#f1f5f9;color:#334155;padding:4px 10px" data-m>مدیریتِ متن‌ها</button></div>`;
        document.body.appendChild(m);
        draw('');
        const r = anchor.getBoundingClientRect();
        m.style.top = Math.max(8, r.top - m.offsetHeight - 8) + 'px';
        m.style.left = Math.max(8, Math.min(window.innerWidth - 308, r.left - 140)) + 'px';
        const inp = m.querySelector('input'); inp.focus(); inp.oninput = () => draw(inp.value.trim());
        m.querySelector('[data-m]').onclick = () => { m.remove(); openHub('canned'); };
        const off = e => { if (!m.contains(e.target) && e.target !== anchor) { m.remove(); document.removeEventListener('mousedown', off, true); } };
        setTimeout(() => document.addEventListener('mousedown', off, true), 0);
    }
    // ---- ابزارهای سریع ----
    function hubTools(b) {
        // همان ابزارهای صفحه‌ی «ابزارها» (tools.js)، فشرده برای جعبه‌ابزار
        if (window.Tools) {
            b.style.padding = '10px 10px 14px';
            Tools.mount(b, {compact: true});
            return;
        }
        const tj = todayJ();
        b.innerHTML = `<div class="cf-card"><h4><i class="fas fa-coins" style="color:#f59e0b"></i>ریال ⇄ تومان</h4>
                <div class="cf-seg" data-unit style="background:#f1f5f9"><button data-v="r" class="on">ریال</button><button data-v="t">تومان</button></div>
                <input class="cf-in" data-f="amt" inputmode="numeric" placeholder="مبلغ…" style="margin-top:6px"><div data-o="amt" style="font-size:11.5px;margin-top:6px;line-height:1.9"></div></div>
            <div class="cf-card"><h4><i class="fas fa-calendar-days" style="color:#6366f1"></i>تبدیلِ تاریخ</h4>
                <div style="display:flex;gap:6px"><input class="cf-in" data-f="jd" placeholder="شمسی ${fa(jstr(...tj))}" dir="ltr"><input class="cf-in" data-f="gd" placeholder="میلادی 2026-10-05" dir="ltr"></div>
                <div data-o="date" style="font-size:11.5px;margin-top:6px;line-height:1.9"></div></div>
            <div class="cf-card"><h4><i class="fas fa-table-list" style="color:#10b981"></i>اقساطِ سریع</h4>
                <div style="display:flex;gap:6px"><input class="cf-in" data-f="prem" inputmode="numeric" placeholder="حقِ بیمه (ریال)"><input class="cf-in" data-f="cnt" inputmode="numeric" placeholder="تعداد" style="width:70px" value="۹"></div>
                <div style="display:flex;gap:6px;margin-top:6px"><input class="cf-in" data-f="d0" placeholder="تاریخِ صدور ${fa(jstr(...tj))}" dir="ltr"><input class="cf-in" data-f="off" inputmode="numeric" placeholder="سررسیدِ اول (ماه بعد)" value="۱"></div>
                <div data-o="inst" style="font-size:11px;margin-top:6px;max-height:200px;overflow:auto"></div>
                <p style="font-size:10px;color:#94a3b8;margin-top:4px">مثلِ مموت: هر قسط رُند به هزار ریال، باقی‌مانده روی قسطِ اول.</p></div>`;
        let unit = 'r';
        const g = f => b.querySelector(`[data-f="${f}"]`), o = k => b.querySelector(`[data-o="${k}"]`);
        const num = v => +en(v).replace(/[^\d]/g, '') || 0;
        const amt = () => {
            const n = num(g('amt').value);
            g('amt').value = n ? money(n) : '';
            if (!n) { o('amt').innerHTML = ''; return; }
            const r = unit === 'r' ? n : n * 10, t = unit === 'r' ? Math.floor(n / 10) : n;
            o('amt').innerHTML = `<b>${money(r)}</b> ریال = <b>${money(t)}</b> تومان<br><span style="color:#64748b">${esc(words(t))} تومان</span>`;
        };
        b.querySelectorAll('[data-unit] button').forEach(x => x.addEventListener('click', () => { unit = x.dataset.v; b.querySelectorAll('[data-unit] button').forEach(y => y.classList.toggle('on', y === x)); amt(); }));
        g('amt').addEventListener('input', amt);
        const dateOut = (gy, gm, gd) => {
            const dt = new Date(Date.UTC(gy, gm - 1, gd)), [jy, jm, jd] = g2j(gy, gm, gd);
            const [ty, tm, td] = irDate(); const diff = Math.round((dt - Date.UTC(ty, tm - 1, td)) / 864e5);
            o('date').innerHTML = `<b>${DOW[dt.getUTCDay()]} ${fa(jd)} ${MONTHS[jm - 1]} ${fa(jy)}</b> = <b dir="ltr">${gy}-${String(gm).padStart(2, '0')}-${String(gd).padStart(2, '0')}</b><br><span style="color:#64748b">${diff === 0 ? 'امروز' : fa(Math.abs(diff)) + ' روز ' + (diff > 0 ? 'دیگر' : 'پیش')}</span>`;
        };
        g('jd').addEventListener('input', () => { const p = parseJ(g('jd').value); if (p) dateOut(...j2g(...p)); });
        g('gd').addEventListener('input', () => { const m = en(g('gd').value).match(/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/); if (m) dateOut(+m[1], +m[2], +m[3]); });
        const inst = () => {
            const total = num(g('prem').value), cnt = Math.max(1, Math.min(60, num(g('cnt').value) || 1)), off = num(g('off').value);
            if (g('prem').value) g('prem').value = total ? money(total) : '';
            if (!total) { o('inst').innerHTML = ''; return; }
            const per = Math.floor(total / cnt / 1000) * 1000, first = total - per * (cnt - 1);
            let [y, m, d] = parseJ(g('d0').value) || todayJ();
            const rows = [];
            for (let i = 0; i < cnt; i++) {
                let mm = m + off + i, yy = y; while (mm > 12) { mm -= 12; yy++; }
                const ml = mm <= 6 ? 31 : mm <= 11 ? 30 : 29;
                rows.push(`<tr><td style="padding:3px 4px">${fa(i + 1)}</td><td style="padding:3px 4px" dir="ltr">${fa(jstr(yy, mm, Math.min(d, ml)))}</td><td style="padding:3px 4px;font-weight:800">${money(i === 0 ? first : per)}</td></tr>`);
            }
            o('inst').innerHTML = `<table style="width:100%;border-collapse:collapse">${rows.join('')}</table>`;
        };
        ['prem', 'cnt', 'd0', 'off'].forEach(f => g(f).addEventListener('input', inst));
    }
    // ---- ظاهر و تنظیماتِ من ----
    function hubTheme(b) {
        const t = Object.assign({mode: 'light', font: 100, accent: ''}, S.prefs.theme || {});
        const bd = S.prefs.birth || {};
        const colors = ['', '#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#e11d48', '#8b5cf6', '#0f766e', '#334155'];
        b.innerHTML = `${has('theme') ? `<div class="cf-card"><h4><i class="fas fa-circle-half-stroke" style="color:#6366f1"></i>حالتِ نمایش</h4>
                <div class="cf-seg" data-mode style="background:#f1f5f9">${[['light', 'روشن ☀️'], ['dark', 'تاریک 🌙'], ['auto', 'خودکار']].map(([k, x]) => `<button data-v="${k}" class="${t.mode === k ? 'on' : ''}">${x}</button>`).join('')}</div>
                <label class="cf-lbl">اندازه‌ی متن <b data-fv>${fa(t.font)}٪</b></label><input type="range" class="cf-range" style="background:#e2e8f0" min="85" max="130" step="5" value="${t.font}" data-font>
                <label class="cf-lbl">رنگِ اصلی</label><div style="display:flex;gap:6px;flex-wrap:wrap">${colors.map(c => `<button data-c="${c}" title="${c ? '' : 'پیش‌فرض'}" style="width:28px;height:28px;border-radius:10px;cursor:pointer;border:2px solid ${t.accent === c ? '#0f172a' : 'transparent'};background:${c || 'linear-gradient(135deg,#6366f1,#06b6d4)'}"></button>`).join('')}</div></div>` : ''}
            ${S.boot.kind === 'S' && has('birthdays') ? `<div class="cf-card"><h4><i class="fas fa-cake-candles" style="color:#db2777"></i>تولدِ من</h4>
                <div style="display:flex;gap:6px;align-items:center"><input class="cf-in" data-f="bmd" placeholder="ماه/روز، مثل ۰۷/۱۵" dir="ltr" value="${esc(fa(bd.md || ''))}" style="width:140px">
                <label style="font-size:10.5px;display:flex;align-items:center;gap:4px"><input type="checkbox" data-f="bshow" ${bd.show ? 'checked' : ''}> همکاران روزِ تولدم را ببینند</label>
                <button class="cf-btn" data-a="bsave" style="margin-right:auto">ثبت</button></div></div>` : ''}
            <div class="cf-card"><h4><i class="fas fa-bell" style="color:#0ea5e9"></i>اعلانِ مرورگر</h4>
                <div class="cf-line"><span class="grow" style="font-size:11px">یادآورها وقتی پنل در پس‌زمینه است هم اعلان بدهند</span><label class="cf-sw"><input type="checkbox" data-f="notif" ${S.prefs.notify && S.prefs.notify.browser ? 'checked' : ''}><span></span></label></div></div>`;
        const saveT = part => { const n = Object.assign(t, part); savePrefs({theme: n}); applyTheme(); };
        b.querySelectorAll('[data-mode] button').forEach(x => x.addEventListener('click', () => { b.querySelectorAll('[data-mode] button').forEach(y => y.classList.toggle('on', y === x)); saveT({mode: x.dataset.v}); }));
        const fr = b.querySelector('[data-font]');
        if (fr) { fr.addEventListener('input', () => { b.querySelector('[data-fv]').textContent = fa(fr.value) + '٪'; }); fr.addEventListener('change', () => saveT({font: +fr.value})); }
        b.querySelectorAll('[data-c]').forEach(x => x.addEventListener('click', () => { saveT({accent: x.dataset.c}); renderHub(); }));
        const bs = b.querySelector('[data-a="bsave"]');
        if (bs) bs.onclick = async () => { const md = en(b.querySelector('[data-f="bmd"]').value.trim()); await savePrefs({birth: md ? {md, show: b.querySelector('[data-f="bshow"]').checked} : null}); toast('ثبت شد.', 'success'); };
        b.querySelector('[data-f="notif"]').addEventListener('change', async e => {
            if (e.target.checked && 'Notification' in window && Notification.permission !== 'granted') { const p = await Notification.requestPermission(); if (p !== 'granted') { e.target.checked = false; toast('مرورگر اجازه‌ی اعلان نداد.', 'warning'); return; } }
            savePrefs({notify: {browser: e.target.checked}});
        });
    }
    function hubKeys(b) {
        const k = (keys, t) => `<div class="cf-line"><span class="grow">${t}</span>${keys.map(x => `<span class="cf-kbd">${x}</span>`).join(' + ')}</div>`;
        b.innerHTML = `<div class="cf-card"><h4><i class="fas fa-keyboard" style="color:#6366f1"></i>میانبرهای صفحه‌کلید</h4>
            ${has('search') ? k(['Ctrl', 'K'], 'جستجوی سراسری و پرش به صفحه‌ها') : ''}
            ${k(['Ctrl', '/'], 'همین راهنما')}
            ${has('music') ? k(['Alt', 'M'], 'پخش / مکثِ موسیقی') + k(['Alt', 'N'], 'آهنگِ بعدی') : ''}
            ${has('todo') ? k(['Alt', 'T'], 'کارهای امروز') : ''}
            ${has('dnd') ? k(['Alt', 'D'], 'مزاحم نشوید (۱ ساعت / خاموش)') : ''}
            ${has('health') ? k(['Alt', 'P'], 'شروع / مکثِ تایمرِ تمرکز') : ''}
            ${k(['Esc'], 'بستنِ پنجره‌ها')}
            ${has('search') ? '<p style="font-size:10.5px;color:#64748b;margin-top:6px">در جستجو با «+» شروع کنید تا مستقیم «کارِ امروز» اضافه شود؛ مثل <b>+ تماس با آقای رضایی</b>.</p>' : ''}</div>`;
    }

    // ================= کارت‌ها و اعلان‌ها =================
    function popCard(icon, title, text, buttons, key) {
        if (key && pops.querySelector(`[data-k="${key}"]`)) return;
        const c = document.createElement('div');
        c.className = 'cf-pop';
        if (key) c.dataset.k = key;
        c.innerHTML = `<div style="display:flex;gap:10px;align-items:flex-start"><span style="font-size:24px;line-height:1">${icon}</span><div style="flex:1;min-width:0"><b>${esc(title)}</b><p>${esc(text)}</p>
            <div style="display:flex;gap:6px;flex-wrap:wrap">${(buttons || [['باشه', () => {}]]).map(([t], i) => `<button class="cf-btn ${i ? 's' : ''}" style="${i ? 'background:#f1f5f9;color:#334155' : ''}" data-i="${i}">${esc(t)}</button>`).join('')}</div></div>
            <button class="cf-x" style="background:#f1f5f9;margin:0" data-x><i class="fas fa-xmark"></i></button></div>`;
        pops.appendChild(c);
        const close = () => c.remove();
        c.querySelector('[data-x]').onclick = () => { if (buttons && buttons[buttons.length - 1]) buttons[buttons.length - 1][1](); close(); };
        c.querySelectorAll('[data-i]').forEach(x => x.addEventListener('click', () => { buttons[+x.dataset.i][1](); close(); }));
    }
    function notifyBrowser(title, body) {
        if (dnd() || !(S.prefs.notify && S.prefs.notify.browser) || !('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) return;
        try { new Notification(title, {body, icon: (document.querySelector('link[rel="icon"]') || {}).href || undefined, silent: true}); } catch (e) {}
    }
    function askNotify() {
        if (!('Notification' in window) || Notification.permission !== 'default' || LS.get('askedNotif', false)) return;
        LS.set('askedNotif', true);
        popCard('🔔', 'اعلانِ مرورگر', 'می‌خواهید یادآورها وقتی پنل در پس‌زمینه است هم اعلان بدهند؟', [['بله', async () => { if ((await Notification.requestPermission()) === 'granted') savePrefs({notify: {browser: true}}); }], ['نه', () => {}]]);
    }
    let actx = null;
    function chime(vol = 0.06) {
        if (dnd()) return;
        try {
            actx = actx || new (window.AudioContext || window.webkitAudioContext)();
            [660, 880].forEach((f, i) => {
                const o = actx.createOscillator(), g = actx.createGain(), t0 = actx.currentTime + i * 0.18;
                o.frequency.value = f; o.type = 'sine'; g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(vol, t0 + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.5);
                o.connect(g); g.connect(actx.destination); o.start(t0); o.stop(t0 + 0.55);
            });
        } catch (e) {}
    }
    function todoTick() {
        if (!has('todo') || dnd()) return;
        const t = Date.now() / 1000;
        S.todos.filter(x => !x.done && x.remind_at && !x.reminded && x.remind_at <= t).forEach(x => {
            x.reminded = true;
            api('todo_reminded', {id: x.id});
            if (HP().sound) chime();
            popCard('⏰', 'یادآور', x.text, [['انجام شد ✓', async () => { const r = await api('todo_done', {id: x.id, done: 1}); if (r.ok) { S.todos = r.todos; updateBadge(); if (S.hubTab === 'todo') renderHub(); } }], ['باشه', () => {}]], 'todo:' + x.id);
            notifyBrowser('یادآور', x.text);
        });
    }
    function updateBadge() {
        const el = hubBtn && hubBtn.querySelector('.cf-badge');
        if (!el) return;
        const tj = jstr(...todayJ());
        const n = S.todos.filter(t => !t.done && (!t.due || t.due <= tj)).length;
        el.style.display = n && has('todo') ? 'flex' : 'none';
        el.textContent = fa(n);
    }

    // ================= دیالوگ‌های کوچک =================
    function modalBox(title, body, onOk, okText) {
        const m = document.createElement('div');
        m.className = 'cf-modal';
        m.innerHTML = `<div class="cf-panel light" style="position:relative;bottom:auto;left:auto;width:min(380px,100%);padding:16px"><b style="font-size:13px">${title}</b><div style="margin-top:6px">${body}</div>
            <div style="display:flex;gap:6px;margin-top:12px"><button class="cf-btn" data-ok>${okText || 'ذخیره'}</button><button class="cf-btn s" data-x>انصراف</button></div></div>`;
        document.body.appendChild(m);
        m.querySelector('[data-x]').onclick = () => m.remove();
        m.addEventListener('mousedown', e => { if (e.target === m) m.remove(); });
        m.querySelector('[data-ok]').onclick = async () => { if (await onOk(m) !== false) m.remove(); };
        const f = m.querySelector('input,textarea'); if (f) f.focus();
        return m;
    }
    function ask(title, label, val) {
        if (window.uiPrompt) return uiPrompt(title, label, val || '');
        return new Promise(res => { modalBox(title, `<label class="cf-lbl">${esc(label)}</label><input class="cf-in" data-v value="${esc(val || '')}">`, m => { res(m.querySelector('[data-v]').value.trim() || null); }); });
    }
    function confirmBox(title, text) {
        if (window.uiConfirm) return uiConfirm(title, text);
        return Promise.resolve(window.confirm(text));
    }

    // ================= جستجوی سراسری =================
    function navItems() {
        const out = [], seen = new Set();
        document.querySelectorAll('[onclick*="switchTab("]').forEach(a => {
            const m = (a.getAttribute('onclick') || '').match(/switchTab\('([^']+)'\)/);
            const t = (a.childNodes.length ? [...a.childNodes].filter(n => n.nodeType === 3 || (n.tagName === 'SPAN')).map(n => n.textContent).join(' ') : a.textContent).replace(/\s+/g, ' ').trim() || a.textContent.trim();
            if (!m || !t || seen.has(m[1]) || a.closest('.cf-pal')) return;
            seen.add(m[1]);
            out.push({tab: m[1], title: t.slice(0, 60)});
        });
        return out;
    }
    function openPalette() {
        if (!has('search')) return;
        if (document.querySelector('.cf-pal')) return;
        closePanels();
        const nav = navItems();
        const ov = document.createElement('div');
        ov.className = 'cf-pal';
        ov.innerHTML = `<div class="cf-pal-box"><div class="cf-pal-in"><i class="fas fa-magnifying-glass" style="color:#94a3b8"></i><input placeholder="پلاک، کد ملی، شماره‌ی بیمه‌نامه، نام، شماره‌ی درخواست… یا نامِ صفحه"><span class="cf-kbd">Esc</span></div>
            <div class="cf-pal-res"></div></div>`;
        document.body.appendChild(ov);
        const inp = ov.querySelector('input'), res = ov.querySelector('.cf-pal-res');
        let items = [], sel = 0, timer = null, seq = 0;
        const close = () => { ov.remove(); document.removeEventListener('keydown', key, true); };
        const draw = () => {
            let g = '';
            res.innerHTML = items.length ? items.map((it, i) => { const head = it.group !== g ? `<div class="cf-pal-g">${esc(it.group)}</div>` : ''; g = it.group;
                return head + `<div class="cf-pal-it ${i === sel ? 'sel' : ''}" data-i="${i}"><i class="fas ${it.icon} ic"></i><div style="min-width:0;flex:1"><b>${esc(it.title)}</b>${it.sub ? `<small>${esc(it.sub)}</small>` : ''}</div></div>`; }).join('')
                : `<div class="cf-empty" style="color:#64748b;opacity:1">${inp.value.trim().length < 2 ? 'دست‌کم دو حرف بنویسید. <span class="cf-kbd">↑</span> <span class="cf-kbd">↓</span> برای انتخاب و <span class="cf-kbd">Enter</span> برای باز کردن.' : 'چیزی پیدا نشد.'}</div>`;
            res.querySelectorAll('[data-i]').forEach(x => { x.onclick = () => go(items[+x.dataset.i]); x.onmousemove = () => { if (sel !== +x.dataset.i) { sel = +x.dataset.i; res.querySelectorAll('.cf-pal-it').forEach(y => y.classList.toggle('sel', y === x)); } }; });
        };
        const go = it => {
            if (!it) return;
            close();
            if (it.todo) { api('todo_save', {text: it.todo}).then(r => { if (r.ok) { S.todos = r.todos; updateBadge(); toast('به کارهای امروز اضافه شد.', 'success'); } }); return; }
            const call = o => {
                if (!o) return;
                const fn = o.fn.split('.').reduce((a, k) => a && a[k], window);
                if (typeof fn === 'function') fn(...(o.args || []));
                if (o.then) setTimeout(() => call(o.then), 450);
            };
            call(it.open);
        };
        const run = async () => {
            const q = inp.value.trim(), my = ++seq;
            const local = [];
            if (q.startsWith('+') && q.length > 2 && has('todo')) local.push({group: 'افزودن', icon: 'fa-plus', title: 'افزودن به کارهای امروز: ' + q.slice(1).trim(), todo: q.slice(1).trim()});
            const lq = q.replace(/^\+/, '').trim();
            if (lq) nav.filter(n => n.title.includes(lq)).slice(0, 6).forEach(n => local.push({group: 'پرش به صفحه', icon: 'fa-arrow-up-right-from-square', title: n.title, open: {fn: 'switchTab', args: [n.tab]}}));
            items = local; sel = 0; draw();
            if (q.length < 2 || q.startsWith('+')) return;
            const r = await api('search', {q});
            if (my !== seq || !r.ok) return;
            items = local.concat(r.results || []); draw();
        };
        inp.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 220); });
        const key = e => {
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(items.length - 1, sel + 1); draw(); const s = res.querySelector('.sel'); if (s) s.scrollIntoView({block: 'nearest'}); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); draw(); const s = res.querySelector('.sel'); if (s) s.scrollIntoView({block: 'nearest'}); }
            else if (e.key === 'Enter') { e.preventDefault(); go(items[sel]); }
        };
        document.addEventListener('keydown', key, true);
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        draw(); inp.focus();
    }
    function addHeaderSearch() {
        const mount = document.getElementById('notif-bell-mount');
        if (!mount || !has('search') || document.getElementById('cf-hdr-search')) return;
        const b = document.createElement('button');
        b.id = 'cf-hdr-search';
        b.type = 'button';
        b.title = 'جستجوی سراسری (Ctrl+K)';
        b.className = 'hover-target';
        b.style.cssText = 'width:38px;height:38px;border-radius:12px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;margin-left:6px;display:inline-flex;align-items:center;justify-content:center';
        b.innerHTML = '<i class="fas fa-magnifying-glass"></i>';
        b.onclick = openPalette;
        mount.parentNode.insertBefore(b, mount);
    }

    // ================= میانبرها =================
    function onKey(e) {
        const typing = e.target && (e.target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName));
        const k = (e.key || '').toLowerCase();
        if ((e.ctrlKey || e.metaKey) && (k === 'k' || e.code === 'KeyK') && has('search')) { e.preventDefault(); openPalette(); return; }
        if ((e.ctrlKey || e.metaKey) && (k === '/' || e.code === 'Slash')) { e.preventDefault(); openHub('keys'); return; }
        if (e.key === 'Escape' && S.open) { closePanels(); return; }
        if (!e.altKey || typing && !e.altKey) return;
        const c = e.code;
        if (c === 'KeyM' && has('music')) { e.preventDefault(); playPause(); }
        else if (c === 'KeyN' && has('music')) { e.preventDefault(); next(); }
        else if (c === 'KeyT' && has('todo')) { e.preventDefault(); openHub('todo'); setTimeout(() => { const i = S.hubEl && S.hubEl.querySelector('[data-f="text"]'); if (i) i.focus(); }, 50); }
        else if (c === 'KeyD' && has('dnd')) {
            e.preventDefault();
            const u = dnd() ? 0 : Math.floor(Date.now() / 1000) + 3600;
            savePrefs({dnd_until: u}).then(() => { if (has('status')) api('status_set', u ? {code: 'dnd', until: u} : {code: ''}).then(r => { if (r.ok) S.status = r.status; statusDot(); }); statusDot(); });
            toast(u ? '«مزاحم نشوید» برای ۱ ساعت روشن شد.' : '«مزاحم نشوید» خاموش شد.', 'info');
        } else if (c === 'KeyP' && has('health')) { e.preventDefault(); const st = LS.get('pomo', null); if (st && !st.paused) pomoPause(); else pomoStart(); toast(st && !st.paused ? 'تایمر مکث شد.' : 'تایمرِ تمرکز شروع شد.', 'info'); if (S.hubTab === 'health') renderHub(); }
    }

    // ================= صبح‌بخیر و تولد =================
    async function morning(force) {
        if (!has('morning')) return;
        const today = S.boot.today;
        if (!force && (S.prefs.morning_seen === today || LS.get('morning', '') === today)) return;
        const [, , , hh] = irDate();
        if (!force && (hh < 5 || hh >= 15)) return;   // فقط تا ساعتِ ۱۵
        const d = await api('morning');
        if (!d.ok) return;
        LS.set('morning', today);
        savePrefs({morning_seen: today});
        const greet = hh < 11 ? 'صبح بخیر' : hh < 15 ? 'ظهر بخیر' : 'عصر بخیر';
        const [jy, jm, jd] = todayJ();
        const [gy, gm, gd] = j2g(jy, jm, jd);
        const unread = +en((document.getElementById('chat-nav-badge') || {}).textContent || '0') || 0;
        const rows = [];
        if (d.occasion) rows.push(['fa-star', '#f59e0b', `مناسبتِ امروز: <b>${esc(d.occasion)}</b>`]);
        (d.birthdays || []).forEach(b => rows.push(['fa-cake-candles', '#db2777', b.in_days === 0 ? `امروز تولدِ <b>${esc(b.name)}</b> است 🎉` : `<b>${esc(b.name)}</b> · ${fa(b.in_days)} روزِ دیگر تولد است`]));
        if (d.todos && d.todos.length) rows.push(['fa-list-check', '#6366f1', `<b>${fa(d.todos.length)}</b> کارِ امروز / مانده: ${d.todos.slice(0, 3).map(t => esc(t.text)).join('، ')}${d.todos.length > 3 ? '…' : ''}`]);
        if (d.installments && (d.installments.today || d.installments.overdue)) rows.push(['fa-sack-dollar', '#10b981', `اقساط: <b>${fa(d.installments.today)}</b> سررسیدِ امروز${d.installments.today_amount ? ' (' + money(d.installments.today_amount) + ' ریال)' : ''} · <b>${fa(d.installments.overdue)}</b> گذشته و پرداخت‌نشده`]);
        if (unread) rows.push(['fa-comments', '#0ea5e9', `<b>${fa(unread)}</b> پیامِ خوانده‌نشده در گفتگوها`]);
        if (d.leave) rows.push(['fa-umbrella-beach', '#0d9488', `مانده‌ی مرخصی: <b>${esc(d.leave)}</b>`]);
        const m = document.createElement('div');
        m.className = 'cf-modal';
        m.innerHTML = `<div class="cf-morning"><div class="hd" style="background:${hh < 11 ? 'linear-gradient(120deg,#f59e0b,#ec4899 60%,#6366f1)' : 'linear-gradient(120deg,#0ea5e9,#6366f1)'}">
                <p style="font-size:12px;opacity:.9;font-weight:800">${DOW[new Date(Date.UTC(gy, gm - 1, gd)).getUTCDay()]} ${fa(jd)} ${MONTHS[jm - 1]} ${fa(jy)}</p>
                <h2 style="font-size:24px;font-weight:900;margin-top:4px">${greet} ${esc((d.name || '').split(' ')[0])} ${hh < 11 ? '☀️' : '🌤️'}</h2>
                <p style="font-size:12px;opacity:.9;margin-top:4px">یک روزِ خوب و پرانرژی برایتان آرزو می‌کنیم.</p></div>
            <div class="bd">${rows.length ? rows.map(([ic, c, t]) => `<div class="cf-mi"><i class="fas ${ic} ic" style="background:${c}1a;color:${c}"></i><div>${t}</div></div>`).join('') : '<div class="cf-empty" style="opacity:1;color:#64748b">امروز چیزِ فوری‌ای ندارید؛ روزِ آرامی باشد 🌿</div>'}
                <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
                    <button class="cf-btn" data-a="go" style="padding:10px 18px">شروعِ کار 🚀</button>
                    ${has('music') && S.tracks.length ? '<button class="cf-btn s" style="background:#f1f5f9;color:#334155" data-a="music"><i class="fas fa-music ml-1"></i>یک آهنگِ آرام</button>' : ''}
                    ${has('todo') ? '<button class="cf-btn s" style="background:#f1f5f9;color:#334155" data-a="todo"><i class="fas fa-list-check ml-1"></i>کارهای امروز</button>' : ''}</div></div></div>`;
        document.body.appendChild(m);
        const close = () => m.remove();
        m.addEventListener('mousedown', e => { if (e.target === m) close(); });
        m.querySelector('[data-a="go"]').onclick = close;
        const mu = m.querySelector('[data-a="music"]'); if (mu) mu.onclick = () => { close(); savePrefs({music: Object.assign(mp(), {focus: true})}); rebuildOrder(); P.cur = null; playPause(); };
        const td = m.querySelector('[data-a="todo"]'); if (td) td.onclick = () => { close(); openHub('todo'); };
    }
    function birthdayBanner() {
        if (!has('birthdays') || !S.boot.birthdays) return;
        const today = S.boot.birthdays.filter(b => b.in_days === 0);
        if (!today.length || LS.get('bday', '') === S.boot.today) return;
        const me = S.boot.birthdays.find(b => b.in_days === 0 && b.name === S.boot.name);
        const el = document.createElement('div');
        el.className = 'cf-bday';
        const others = today.filter(b => b !== me);
        el.innerHTML = `<span style="font-size:18px">🎂</span><span>${me ? 'تولدتان مبارک! 🎉 سالی پر از شادی و موفقیت.' : 'امروز تولدِ ' + others.map(b => '<b>' + esc(b.name) + '</b>').join('، ') + ' است'}</span>
            ${!me && others.length === 1 && window.openStaffChatWith ? '<button class="cf-btn" style="padding:4px 10px;background:#db2777" data-a="msg">تبریک بگویید</button>' : ''}
            <button class="cf-x" style="background:transparent;margin:0;color:#9d174d" data-x><i class="fas fa-xmark"></i></button>`;
        document.body.appendChild(el);
        el.querySelector('[data-x]').onclick = () => { LS.set('bday', S.boot.today); el.remove(); };
        const mb = el.querySelector('[data-a="msg"]'); if (mb) mb.onclick = () => { LS.set('bday', S.boot.today); el.remove(); openStaffChatWith(others[0].user_id, others[0].name); };
    }

    // ================= شروع =================
    function buildDock() {
        root = document.createElement('div');
        root.id = 'cf-root';
        root.className = 'cf-root';
        if (CFG.bottom) root.style.bottom = CFG.bottom + 'px';
        root.innerHTML = '<div class="cf-dock"></div>';
        document.body.appendChild(root);
        dock = root.querySelector('.cf-dock');
        pops = document.createElement('div');
        pops.className = 'cf-pops';
        if (CFG.bottom) pops.style.bottom = (CFG.bottom + 70) + 'px';
        document.body.appendChild(pops);
        if (has('music')) {
            disc = document.createElement('button');
            disc.className = 'cf-disc';
            disc.type = 'button';
            disc.innerHTML = `<span class="cf-vinyl"><i></i></span><svg viewBox="0 0 54 54"><circle cx="27" cy="27" r="25" stroke="rgba(255,255,255,.15)"></circle><circle class="cf-ring" cx="27" cy="27" r="25" stroke="url(#cfg)" stroke-linecap="round" stroke-dasharray="0 157"></circle>
                <defs><linearGradient id="cfg"><stop offset="0" stop-color="#818cf8"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs></svg><span class="cf-note"><i class="fas fa-music"></i></span>`;
            disc.onclick = openPlayer;
            disc.oncontextmenu = e => { e.preventDefault(); playPause(); };
            dock.appendChild(disc);
            buildMini();
            renderDisc();
        }
        if (hubTabs().length) {
            hubBtn = document.createElement('button');
            hubBtn.className = 'cf-hubbtn';
            hubBtn.type = 'button';
            hubBtn.title = 'جعبه‌ابزارِ من: کارها، تمرکز، وضعیت، متن‌های آماده، ابزارها و ظاهر';
            hubBtn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i><b class="cf-badge"></b><small class="cf-pomo"></small><span class="cf-sdot"></span>';
            hubBtn.onclick = () => openHub();
            dock.appendChild(hubBtn);
        }
        document.addEventListener('mousedown', e => {
            // کلیکِ بیرونِ پنل (پخش‌کننده یا جعبه‌ابزار) می‌بنددش؛ دکمه‌های گوشه (root) خودشان باز/بسته می‌کنند
            if (!S.open || root.contains(e.target) || e.target.closest('.cf-cpick,.cf-modal,.cf-pal,.cf-pops,#cf-up-pill')) return;
            closePanels();
        });
    }
    async function boot() {
        const d = await api('boot');
        if (!d.ok) return;
        S.boot = d; S.F = d.features || {}; S.prefs = d.prefs || {}; S.genres = d.genres || []; S.todos = d.todos || []; S.canned = d.canned || []; S.status = d.status || null;
        if (!Object.values(S.F).some(Boolean)) return;
        injectCss();
        applyTheme();
        buildDock();
        addHeaderSearch();
        updateBadge(); statusDot(); pomoBadge();
        document.addEventListener('keydown', onKey);
        if (has('canned')) window.CFCanned = {pick: cannedPick, list: () => S.canned};
        if (has('music')) loadMusic();
        setInterval(() => { pomoBadge(); }, 1000);
        setInterval(() => { todoTick(); healthTick(); statusDot(); }, 20000);
        setTimeout(() => { todoTick(); healthTick(); }, 4000);
        setTimeout(() => { birthdayBanner(); morning(false); }, 1500);
        window.addEventListener('beforeunload', saveResume);
    }
    window.CF = {boot, openPlayer, openHub, openPalette, playPause, next, uploadFiles, uploadSummary, upAdd, upMount, upActive, has, morning: () => morning(true), state: S, api};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
