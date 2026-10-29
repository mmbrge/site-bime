// فایل: inquiry.js
// پنل ← «استعلامات»: استعلامِ تکی (شخص حقیقی، شخص + کد پستی، کد پستی، شخص حقوقی، سنهاب)، گروهی با اکسل، استعلامِ حق بیمه،
// تاریخچه، تنظیمات (مدیر کل: نام کاربری/رمزِ پاسارگاد و سنهاب)
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
        legal: { ic: 'fa-building-circle-check', c: '#059669', d: 'مشخصاتِ ثبتی و تماسِ شرکت با شناسه‌ی ملیِ ۱۱ رقمی (اول پاسارگاد، بعد سرویسِ API)' },
        sanhab: { ic: 'fa-shield-halved', c: '#0e7490', d: 'سوابقِ بیمه‌نامه‌ها از بیمه مرکزی: عدمِ خسارت یا خسارت، تخفیف‌ها، بیمه‌گر و انقضا' },
        pbody: { ic: 'fa-car-burst', c: '#0f766e', d: 'همه‌ی بیمه‌نامه‌های بدنه‌ی خودرو (آخرین بالا)، خسارت‌ها، تخفیف و استعلامِ خسارت با تصویرِ صفحه' },
        pdamage: { ic: 'fa-file-shield', c: '#be123c', d: 'استعلامِ خسارت از سامانه‌ی پاسارگاد' },
        ocr: { ic: 'fa-file-lines', c: '#9333ea', d: 'متنِ PDF و عکس' },
    };
    const ST_UI = { OK: ['موفق', '#059669', 'fa-circle-check'], WARN: ['با هشدار', '#d97706', 'fa-triangle-exclamation'], FAIL: ['ناموفق', '#e11d48', 'fa-circle-xmark'],
                    QUEUED: ['در صف', '#64748b', 'fa-hourglass-half'], RUNNING: ['در حال اجرا', '#4f46e5', 'fa-spinner fa-spin'] };
    const SRC_FA = { panel: 'بخشِ استعلامات', issue: 'پنلِ صدور', form: 'فرمِ ثبتِ درخواست', group: 'گروهی', miniapp: 'مینی‌اپ', premium: 'استعلامِ حق بیمه' };
    const V = { el: null, boot: null, tab: 'single', kind: 'person_postal', hist: { page: 1, q: '', kind: '', status: '', source: '', from: '', to: '', user: '', ref: '' }, running: false, batchRun: {} };

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
        .inq-hbar select.inq-in,.inq-hbar input[data-jdate].inq-in{width:auto;max-width:100%}
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
        .inq-live{position:relative;border-radius:16px;padding:2px;overflow:hidden;isolation:isolate;background:#e0e7ff;transition:background .3s,box-shadow .3s}
        .inq-live::before{content:'';position:absolute;inset:-150%;background:conic-gradient(from 0deg,transparent 0 55%,#6366f1 70%,#22d3ee 80%,transparent 92%);animation:inqspin 1.4s linear infinite;z-index:-1}
        .inq-live>.inq-live-in{position:relative;background:#fff;border-radius:14px;padding:10px 12px}
        .inq-live.ok{background:#10b981;box-shadow:0 0 0 4px rgba(16,185,129,.15)}.inq-live.bad{background:#f43f5e;box-shadow:0 0 0 4px rgba(244,63,94,.15)}
        .inq-live.warn{background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.15)}.inq-live.ok::before,.inq-live.bad::before,.inq-live.warn::before{display:none}
        .inq-live.sm>.inq-live-in{padding:5px 10px;font-size:11px;font-weight:800}
        @keyframes inqspin{to{transform:rotate(360deg)}}
        .inq-qb{position:absolute;left:4px;top:50%;transform:translateY(-50%);width:26px;height:26px;border-radius:9px;background:#eef2ff;color:#4f46e5;display:inline-flex;align-items:center;justify-content:center;font-size:11px;cursor:pointer;z-index:2;border:1px solid #c7d2fe}
        .inq-qb:hover{background:#4f46e5;color:#fff}
        .inq-qpop{position:fixed;z-index:1000020;width:min(360px,calc(100vw - 24px));max-height:calc(100vh - 24px);overflow-y:auto;overscroll-behavior:contain;background:#fff;border-radius:18px;box-shadow:0 24px 60px -18px rgba(15,23,42,.45);border:1px solid #e2e8f0;padding:12px;direction:rtl}
        .inq-qpop .inq-qtab{flex:1;border-radius:10px;padding:6px;font-size:11px;font-weight:800;background:#f1f5f9;color:#475569}.inq-qpop .inq-qtab.on{background:#4f46e5;color:#fff}
        .inq-qpop input{width:100%;border:1px solid #e2e8f0;border-radius:10px;padding:6px 10px;font-size:12px;margin-top:3px}
        .inq-qpop label{display:block;font-size:10.5px;font-weight:800;color:#64748b;margin-top:6px}
        .inq-fbtn{font-size:10.5px;font-weight:800;border-radius:9px;padding:4px 10px;background:#eef2ff;color:#4338ca;white-space:nowrap}
        .inq-fbtn:disabled{opacity:.5}.inq-fmsg{font-size:10.5px;font-weight:700}
        .inq-cap{margin-top:10px;border:1.5px dashed #a5b4fc;background:#eef2ff;border-radius:16px;padding:12px}
        .inq-capimg{display:inline-flex;align-items:center;justify-content:center;min-width:120px;min-height:44px;background:#fff;border-radius:10px;padding:3px}.inq-capimg img{max-height:50px;display:block}`;
        document.head.appendChild(s);
    }
    const badge = st => { const u = ST_UI[st] || ['', '#64748b', 'fa-circle']; return `<span class="inq-badge" style="background:${u[1]}1a;color:${u[1]}"><i class="fas ${u[2]}"></i>${u[0]}</span>`; };
    // جزئیاتِ گام‌ها فقط برای مدیر کل (سرور فقط برای او step_pages را می‌فرستد)
    const isAdm = it => (V.boot ? !!V.boot.is_admin : false) || Array.isArray(it && it.step_pages);
    const pName = it => it.person && (it.person.name || it.person.family) ? `${it.person.name} ${it.person.family}`.trim() : (it.person_name || '');

    const CAPW = new Set();   // کپچاهای باز: با ورودِ موفق از هر جا، همه‌ی استعلام‌های منتظر ادامه پیدا می‌کنند
    // ---------------- کپچا: هر کاربری که به نشستِ بسته‌ی سامانه برسد، کدِ تصویر را زیرِ همان فیلدهایش می‌زند ----------------
    // نام کاربری و رمز را مدیر کل در تنظیمات گذاشته؛ کاربر فقط متنِ تصویر را می‌نویسد. خروجی: true (وارد شد) / false (انصراف)
    // alt: دکمه‌ی جایگزین (مثلاً «بدونِ پاسارگاد، از سرویسِ API») => نتیجه 'alt'
    function captchaBox(host, image, err, alt) {
        css();
        return new Promise(resolve => {
            host.innerHTML = `<div class="inq-cap"><p class="text-[11.5px] font-black text-indigo-800 mb-2"><i class="fas fa-shield-halved ml-1"></i>برای انجامِ استعلام، کدِ تصویرِ امنیتی را وارد کنید</p>
                <div class="flex flex-wrap items-center gap-2"><span class="inq-capimg">${image ? `<img src="${image}" alt="کد امنیتی">` : '<i class="fas fa-spinner fa-spin text-slate-400"></i>'}</span>
                    <button type="button" class="inq-fbtn" data-c="re" title="تصویرِ دیگر"><i class="fas fa-rotate"></i></button>
                    <input class="inq-in !mt-0" style="width:150px;font-size:15px;text-align:center" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="کد تصویر">
                    <button type="button" class="inq-go !py-2 !px-4 text-xs" data-c="ok">ادامه‌ی استعلام</button>
                    ${alt ? `<button type="button" class="inq-fbtn" data-c="alt" style="background:#d1fae5;color:#065f46">${esc(alt)}</button>` : ''}
                    <button type="button" class="inq-fbtn" data-c="no" style="background:#f1f5f9;color:#475569">انصراف</button></div>
                <p class="inq-caperr text-[11px] font-bold text-rose-600 mt-1.5">${esc(err || '')}</p></div>`;
            const inp = host.querySelector('input'), er = host.querySelector('.inq-caperr'), img = host.querySelector('.inq-capimg');
            const setImg = src => { img.innerHTML = src ? `<img src="${src}" alt="کد امنیتی">` : '<i class="fas fa-spinner fa-spin text-slate-400"></i>'; };
            let settled = false;
            const done = v => { if (settled) return; settled = true; CAPW.delete(done); clearInterval(watch); if (host.isConnected) host.innerHTML = ''; resolve(v); };
            CAPW.add(done);
            // اگر این بخش بسته/عوض شد، استعلامِ منتظر رها می‌شود تا دکمه‌ها قفل نمانند
            const watch = setInterval(() => { if (!host.isConnected) done(false); }, 1500);
            const refresh = async force => {
                setImg(null);
                const r = await post({ action: 'captcha', refresh: force ? 1 : 0 });
                if (r.ok && r.logged_in) { done(true); return; }
                if (!r.ok) { er.textContent = r.error || 'تصویر نیامد؛ دوباره بزنید.'; return; }
                setImg(r.image); inp.value = ''; inp.focus();
            };
            if (!image) refresh(false); else setTimeout(() => inp.focus(), 50);
            const go = async () => {
                const t = en(inp.value).trim();
                if (!t) { inp.focus(); return; }
                const b = host.querySelector('[data-c="ok"]'); b.disabled = true; er.textContent = '';
                const r = await post({ action: 'captcha_login', captcha: t });
                b.disabled = false;
                if (r.ok) { CAPW.forEach(f => f(true)); done(true); return; }
                er.textContent = r.error || 'کد اشتباه بود.'; inp.value = '';
                if (r.image) setImg(r.image); else refresh(true);
                inp.focus();
            };
            host.querySelector('[data-c="ok"]').onclick = go;
            host.querySelector('[data-c="re"]').onclick = () => refresh(true);
            host.querySelector('[data-c="no"]').onclick = () => done(false);
            if (alt) host.querySelector('[data-c="alt"]').onclick = () => done('alt');
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
        });
    }
    // جای کپچا وقتی فرمی میزبانش نیست (پنجره‌ی کوچک)
    function captchaModal(image, err, alt) {
        const m = document.createElement('div'); m.className = 'inq-modal';
        m.innerHTML = '<div style="width:min(460px,100%)"><div class="p-4" data-h></div></div>';
        document.body.appendChild(m);
        return captchaBox(m.querySelector('[data-h]'), image, err, alt).then(v => { m.remove(); return v; });
    }

    // ---------------- اجرای یک استعلام (ثبت ← اجرا + خواندنِ پیشرفت؛ اگر نشست بسته بود: کپچا و ادامه) ----------------
    async function run(body, onProg, capHost) {
        const c = await post(Object.assign({ action: 'create' }, body));
        if (!c.ok) return c;
        if (!c.cached && V.boot && V.boot.my_quota) V.boot.my_quota.used++;
        if (c.item.done) { if (onProg) onProg(c.item); return { ok: true, item: c.item }; }
        let last = c.item, viaApi = 0;
        for (let attempt = 0; attempt < 6; attempt++) {
            let done = null;
            const ex = post({ action: 'exec', id: c.id, via_api: viaApi }).then(r => { done = r; });
            const t0 = Date.now();
            while (!done) {
                await sleep(1300);
                if (done) break;
                const g = await post({ action: 'get', id: c.id });
                if (g.ok) { last = g.item; if (onProg) onProg(last); if (last.done) break; }
                if (Date.now() - t0 > 240000) return { ok: false, error: 'پاسخی از سامانه نیامد؛ کمی بعد دوباره امتحان کنید.', item: last };
            }
            await ex.catch(() => {});
            if (done && done.need_captcha) {
                if (onProg) onProg(Object.assign({}, done.item, { notice: 'منتظرِ کدِ تصویرِ امنیتی…' }));
                const alt = done.api_alt ? 'بدونِ پاسارگاد، از سرویسِ API' : '';
                const ok = capHost ? await captchaBox(capHost, done.captcha, done.captcha_error, alt) : await captchaModal(done.captcha, done.captcha_error, alt);
                if (!ok) return { ok: false, error: 'استعلام انجام نشد (کدِ امنیتی وارد نشد).', item: done.item };
                if (ok === 'alt') { viaApi = 1; continue; }
                if (V.boot) V.boot.status = 'logged_in';
                continue;
            }
            const f = done && done.ok ? done.item : (await post({ action: 'get', id: c.id })).item || last;
            if (onProg) onProg(f);
            return { ok: true, item: f };
        }
        return { ok: false, error: 'ورود به سامانه انجام نشد؛ کمی بعد دوباره امتحان کنید.' };
    }

    // ---------------- نمای اصلی ----------------
    async function init(el) {
        css(); V.el = el;
        el.innerHTML = '<div class="inq-card text-center text-xs text-slate-400 py-10"><i class="fas fa-spinner fa-spin ml-1"></i>در حال بارگذاری…</div>';
        const b = await post({ action: 'boot' });
        if (!b.ok) { el.innerHTML = `<div class="inq-card text-red-600 text-xs">${esc(b.error)}</div>`; return; }
        V.boot = b;
        if (!Object.keys(b.kinds || {}).length && V.tab === 'single') V.tab = b.can.group_view ? 'group' : (b.can.premium ? 'premium' : (b.can.ocr && window.SmartOCR ? 'ocr' : 'history'));
        render();
    }
    function statusChip(s) {
        const m = { logged_in: ['متصل به سامانه', '#34d399'], logged_out: ['نیاز به ورود (کپچا)', '#fbbf24'], down: ['سامانه در دسترس نیست', '#f87171'], busy: ['مشغولِ استعلام', '#a5b4fc'] }[s];
        return m ? `<span class="inq-chip"><i class="dot" style="background:${m[1]}"></i>${m[0]}</span>` : '';
    }
    function render() {
        const b = V.boot, t = b.today || {};
        const tabs = [['single', 'fa-magnifying-glass', 'استعلامِ تکی', Object.keys(b.kinds || {}).length > 0], ['group', 'fa-file-excel', 'استعلامِ گروهی', b.can.group_view],
                      ['premium', 'fa-calculator', 'استعلامِ حق بیمه', b.can.premium], ['ocr', 'fa-file-lines', 'پردازش فایل و استخراجِ مدارک', b.can.ocr && !!window.SmartOCR], ['history', 'fa-clock-rotate-left', 'تاریخچه', true], ['settings', 'fa-sliders', 'تنظیمات', b.is_admin]];
        V.el.innerHTML = `
        <div class="inq-hero mb-4"><div class="relative flex flex-wrap items-center justify-between gap-3" style="z-index:1">
            <div><p class="text-[11px] opacity-75">شخص حقیقی، کد پستی (املاک و اسکان)، شخص حقوقی، سنهاب (سوابقِ بیمه‌نامه) و حق بیمه</p><h2 class="font-black text-xl mt-0.5"><i class="fas fa-user-check ml-1"></i>استعلامات</h2></div>
            <div class="flex flex-wrap items-center gap-2">${b.is_admin ? '<span class="inq-chip">پاسارگاد:</span>' + statusChip(b.status) + (b.status === 'logged_out' ? '<button class="inq-chip" style="background:#fbbf24;color:#422006" data-a="login"><i class="fas fa-key"></i>ورود</button>' : '')
                    + '<span class="inq-chip">سنهاب:</span>' + (statusChip(b.sh_status) || '<span class="inq-chip">تنظیم نشده</span>')
                    + (b.sh_status === 'logged_out' ? '<button class="inq-chip" style="background:#fbbf24;color:#422006" data-a="shlogin"><i class="fas fa-key"></i>ورود</button>' : '') : ''}
                <span class="inq-chip">امروز: ${fa(t.total || 0)} استعلام، ${fa(t.ok || 0)} موفق${t.queue ? '، ' + fa(t.queue) + ' در صف' : ''}</span>
                ${!b.is_admin && b.my_quota && b.my_quota.limit ? `<span class="inq-chip" title="سقفِ استعلامِ روزانه‌ی شما"><i class="fas fa-gauge"></i>سهمِ امروزِ شما: ${fa(b.my_quota.used)} از ${fa(b.my_quota.limit)}</span>` : ''}</div></div></div>
        <div class="inq-tabs mb-4">${tabs.filter(x => x[3]).map(x => `<button class="inq-tab ${V.tab === x[0] ? 'on' : ''}" data-tab="${x[0]}"><i class="fas ${x[1]}"></i>${x[2]}</button>`).join('')}</div>
        <div id="inq-body"></div>`;
        V.el.querySelectorAll('[data-tab]').forEach(x => x.onclick = () => { V.tab = x.dataset.tab; render(); });
        const lb = V.el.querySelector('[data-a="login"]'); if (lb) lb.onclick = () => loginDialog();
        const sb = V.el.querySelector('[data-a="shlogin"]'); if (sb) sb.onclick = () => shLoginDialog();
        const body = V.el.querySelector('#inq-body');
        ({ single: renderSingle, group: renderGroup, premium: renderPremium, ocr: renderOcr, history: renderHistory, settings: renderSettings })[V.tab](body);
    }

    // ---------------- استعلامِ تکی ----------------
    function renderSingle(body) {
        if (!V.boot.kinds[V.kind]) V.kind = Object.keys(V.boot.kinds)[0];
        const k = V.kind, kinds = V.boot.kinds;
        body.innerHTML = `<div class="inq-card space-y-4">
            <div class="inq-kinds">${Object.keys(kinds).map(x => `<button type="button" class="inq-kind ${k === x ? 'on' : ''}" data-k="${x}" style="--c:${KIND_UI[x].c}"><span class="ic"><i class="fas ${KIND_UI[x].ic}"></i></span><b>${kinds[x]}</b><small>${KIND_UI[x].d}</small></button>`).join('')}</div>
            ${k === 'legal' && !V.boot.sapi_ready ? '<p class="text-[11px] font-bold text-slate-500 bg-slate-50 rounded-xl p-2.5"><i class="fas fa-circle-info ml-1"></i>استعلام از سامانه‌ی پاسارگاد انجام می‌شود؛ اگر کلیدِ s.api.ir را هم در «تنظیمات ← کلیدهای API» وارد کنید، وقتی پاسارگاد نتیجه نداد از آن استعلام می‌شود.</p>' : ''}
            ${k === 'pbody' ? `${!V.boot.pb_ready ? '<p class="text-[11px] font-bold text-amber-700 bg-amber-50 rounded-xl p-2.5"><i class="fas fa-circle-info ml-1"></i>نام کاربری و رمزِ سامانه‌ی پاسارگاد هنوز در «استعلامات ← تنظیمات» وارد نشده (یا این استعلام خاموش است)؛ به مدیر کل اطلاع دهید.</p>' : ''}<div id="inq-pb"></div>` : ''}
            ${k === 'pbody' ? '' : k === 'sanhab' ? `${!V.boot.sh_ready ? '<p class="text-[11px] font-bold text-amber-700 bg-amber-50 rounded-xl p-2.5"><i class="fas fa-circle-info ml-1"></i>' + (V.boot.is_admin ? 'نام کاربری و رمزِ سنهاب هنوز در «استعلامات ← تنظیمات» وارد نشده.' : 'این استعلام هنوز راه‌اندازی نشده؛ به مدیر کل اطلاع دهید.') + '</p>' : ''}<div id="inq-sh"></div>` : `
            <form id="inq-form" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end" autocomplete="off">
                ${k === 'legal' ? `<label class="inq-f md:col-span-2">نامِ شرکت (اولویتِ جستجو)<input class="inq-in" name="company_name" style="letter-spacing:0" placeholder="مثلاً بازرگانی نمونه"></label>
                       <label class="inq-f">شناسه‌ی ملی (اختیاری)<input class="inq-in" name="company_nid" dir="ltr" inputmode="numeric" maxlength="11" placeholder="10101234567"></label>
                       <label class="inq-f">کد اقتصادی (اختیاری)<input class="inq-in" name="economic_code" dir="ltr" inputmode="numeric" maxlength="14"></label>
                       <label class="inq-f">کد پستی (اختیاری؛ برای تأیید)<input class="inq-in" name="postal" dir="ltr" inputmode="numeric" maxlength="10" placeholder="1234567890"></label>
                       <p class="md:col-span-3 text-[10.5px] text-slate-500 self-center">دست‌کم یکی از نام، شناسه‌ی ملی یا کد اقتصادی؛ اول با همه جستجو می‌شود، بعد با نام به‌تنهایی، بعد شناسه و کد اقتصادی.</p>`
                    : `<label class="inq-f">کد ملی<input class="inq-in" name="national_id" dir="ltr" inputmode="numeric" maxlength="10" placeholder="0012345678"></label>
                       <label class="inq-f">تاریخ تولد<input class="inq-in" name="birth" dir="ltr" inputmode="numeric" placeholder="1370/05/12"></label>
                       ${k !== 'person' ? '<label class="inq-f">کد پستی (۱۰ رقم)<input class="inq-in" name="postal" dir="ltr" inputmode="numeric" maxlength="10" placeholder="1234567890"></label>' : ''}`}
                <button class="inq-go ${k === 'person' ? 'md:col-span-2' : ''}" type="submit"><i class="fas fa-magnifying-glass ml-1"></i>استعلام</button>
            </form>
            ${V.boot.is_admin ? '<label class="flex items-center gap-2 text-[11px] font-bold text-slate-500"><input type="checkbox" id="inq-fresh" class="accent-indigo-600"> نتیجه‌ی قبلی (کش) استفاده نشود و دوباره از سامانه پرسیده شود</label>' : ''}
            <div id="inq-capbox"></div>
            <div id="inq-out"></div>`}</div>`;
        body.querySelectorAll('[data-k]').forEach(x => x.onclick = () => { V.kind = x.dataset.k; renderSingle(body); });
        if (k === 'pbody') { if (window.PBodyUI) PBodyUI.form(body.querySelector('#inq-pb'), { source: 'panel' }); else body.querySelector('#inq-pb').innerHTML = '<p class="text-xs text-rose-600">فایلِ pbody-ui.js بارگذاری نشده.</p>'; return; }
        if (k === 'sanhab') { if (window.SanhabUI) SanhabUI.form(body.querySelector('#inq-sh'), { source: 'panel' }); else body.querySelector('#inq-sh').innerHTML = '<p class="text-xs text-rose-600">فایلِ sanhab-ui.js بارگذاری نشده.</p>'; return; }
        const f = body.querySelector('#inq-form');
        f.querySelectorAll('input').forEach(i => i.addEventListener('input', () => { if (i.name !== 'birth' && i.name !== 'company_name') i.value = en(i.value).replace(/\D/g, ''); i.classList.remove('err'); }));
        f.onsubmit = async e => {
            e.preventDefault();
            const d = Object.fromEntries(new FormData(f).entries());
            Object.keys(d).forEach(x => d[x] = en(d[x]).trim());
            const btn = f.querySelector('button[type="submit"]');
            if (btn.disabled) return;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حال استعلام…';
            const outEl = body.querySelector('#inq-out');
            const fresh = body.querySelector('#inq-fresh');
            const r = await run(Object.assign({ kind: k, fresh: fresh && fresh.checked ? 1 : 0 }, d), it => { outEl.innerHTML = resultHtml(it, true); bindResult(outEl, it); }, body.querySelector('#inq-capbox'));
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-magnifying-glass ml-1"></i>استعلام';
            if (!r.ok) { if (r.field) { const i = f.querySelector(`[name="${r.field === 'national_id' && k === 'legal' ? 'company_nid' : r.field}"]`); if (i) { i.classList.add('err'); i.focus(); } } toast(r.error || 'خطا', 'error'); }
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
    // کادرِ «در حالِ استعلام» برای همه به‌جز مدیر کل: نوری که دورِ کادر می‌چرخد؛ نتیجه آمد = سبز، نیامد = قرمز (بدونِ گام‌ها و جزئیاتِ سامانه)
    const liveState = it => !it.done ? 'run' : (it.status === 'OK' ? 'ok' : it.status === 'FAIL' ? 'bad' : 'warn');
    const liveBox = (state, html, sm) => `<div class="inq-live ${state === 'run' ? '' : state}${sm ? ' sm' : ''}"><div class="inq-live-in">${html}</div></div>`;
    function resultHtml(it, big) {
        if (!isAdm(it)) {
            const st = liveState(it);
            const head = `<div class="flex flex-wrap items-center justify-between gap-2 mb-1"><b class="text-sm text-slate-800"><i class="fas ${(KIND_UI[it.kind] || {}).ic || 'fa-search'} ml-1"></i>${esc(it.kind_fa || 'استعلام')} ${it.query ? '· ' + esc(it.query) : ''} ${it.national_id ? '· <span dir="ltr">' + fa(it.national_id) + '</span>' : ''}</b>
                <span class="text-[11px] font-black ${st === 'ok' ? 'text-emerald-600' : st === 'bad' ? 'text-rose-600' : st === 'warn' ? 'text-amber-600' : 'text-indigo-600'}">${st === 'run' ? '<i class="fas fa-circle-notch fa-spin ml-1"></i>در حالِ استعلام…' : st === 'ok' ? '<i class="fas fa-circle-check ml-1"></i>نتیجه آمد' : st === 'bad' ? '<i class="fas fa-circle-xmark ml-1"></i>نتیجه نداد' : '<i class="fas fa-triangle-exclamation ml-1"></i>نیاز به بررسی'}</span></div>`;
            const body = st === 'run' ? '<p class="text-[11px] text-slate-400">لطفاً چند لحظه صبر کنید…</p>' : resultInner(it);
            return `<div class="mt-1">${liveBox(st, head + body)}</div>`;
        }
        const ui = ST_UI[it.status] || ST_UI.QUEUED;
        const tone = it.status === 'OK' ? '#ecfdf5;border-color:#a7f3d0' : it.status === 'FAIL' ? '#fff1f2;border-color:#fecdd3' : it.status === 'WARN' ? '#fffbeb;border-color:#fde68a' : '#f8fafc;border-color:#e2e8f0';
        let inner = '';
        if (!it.done) inner = `<p class="text-xs font-bold text-indigo-700"><i class="fas fa-spinner fa-spin ml-1"></i>${esc(it.notice || 'در حال آماده‌سازی…')}</p>`;
        else {
            if (it.company) {
                const c = it.company;
                inner += `<div class="inq-dl">${[['نام', c.name], ['نوع', c.company_type], ['شناسه‌ی ملی', c.national_id], ['شماره ثبت', c.register_number], ['تاریخ ثبت / تأسیس', c.register_date], ['کد اقتصادی', c.economic_code],
                    ['استان / شهر', [c.province, c.city].filter(Boolean).join(' / ')], ['کد پستی', c.postal_code], ['تلفن', c.phone], ['موبایل', c.mobile], ['ایمیل', c.email], ['وضعیت', c.active === false ? 'غیرفعال' : c.active ? 'فعال' : '—'],
                    ['آدرس', c.address]].filter(x => x[1] || ['نام', 'شماره ثبت', 'آدرس'].includes(x[0])).map(x => `<div><span>${x[0]}</span><b>${esc(fa(x[1] || '—'))}</b></div>`).join('')}</div>
                    <p class="text-[10.5px] text-slate-500 mt-2"><i class="fas fa-database ml-1"></i>منبع: ${c.source === 'api' || it.via === 'api' ? 'سرویسِ API (s.api.ir)' : 'سامانه‌ی پاسارگاد'}</p>`;
            } else if (it.person || it.person_name) {
                const p = it.person || {};
                inner += `<div class="inq-dl">${[['نام', p.name || it.person_name], ['نام خانوادگی', p.family], ['نام پدر', p.father || it.father_name], ['جنسیت', p.gender]].map(x => `<div><span>${x[0]}</span><b>${esc(x[1] || '—')}</b></div>`).join('')}</div>`;
            }
            if (it.kind === 'person_postal' || it.kind === 'postal' || (it.kind === 'legal' && it.postal)) {
                const v = it.postal_valid, c = v === true ? '#059669' : v === false ? '#e11d48' : '#d97706';
                inner += `<div class="mt-3 rounded-xl p-3 text-xs font-bold" style="background:${c}14;color:${c}"><i class="fas ${v === true ? 'fa-circle-check' : v === false ? 'fa-circle-xmark' : 'fa-triangle-exclamation'} ml-1"></i>کد پستی ${fa(it.postal || '')}: ${esc(it.postal_text || (v === true ? 'تأیید شد' : v === false ? 'تأیید نشد' : 'نامشخص'))}</div>`;
            }
            if (it.status === 'FAIL' && !(it.kind === 'person_postal' || it.kind === 'postal') || (it.status === 'FAIL' && !it.person && !it.postal_text)) inner += `<p class="mt-2 text-xs font-bold text-rose-700">${esc(it.notice || 'استعلام انجام نشد.')}</p>`;
        }
        return `<div class="inq-res mt-1" style="background:${tone}">
            <div class="flex flex-wrap items-center justify-between gap-2"><b class="text-sm text-slate-800"><i class="fas ${(KIND_UI[it.kind] || {}).ic || 'fa-search'} ml-1"></i>${esc(it.kind_fa)} ${it.query ? '· ' + esc(it.query) : ''} ${it.national_id ? '· <span dir="ltr">' + fa(it.national_id) + '</span>' : ''}</b>
                <span class="flex items-center gap-2">${it.cached ? '<span class="inq-badge" style="background:#e0e7ff;color:#4338ca"><i class="fas fa-bolt"></i>از نتیجه‌ی قبلی</span>' : ''}${badge(it.status)}${it.ms ? `<small class="text-slate-400">${fa((it.ms / 1000).toFixed(1))} ثانیه</small>` : ''}</span></div>
            ${it.kind !== 'legal' && isAdm(it) ? stepsBar(it) : ''}${inner}
            ${isAdm(it) && it.timeline && it.timeline.length ? `<details class="mt-3" open><summary class="text-[11px] font-black text-slate-500 cursor-pointer">جزئیاتِ گام‌ها (فقط مدیر کل)</summary>${timelineHtml(it)}</details>` : ''}</div>`;
    }
    function resultInner(it) {
        let inner = '';
        if (it.company) {
            const c = it.company;
            inner += `<div class="inq-dl">${[['نام', c.name], ['نوع', c.company_type], ['شناسه‌ی ملی', c.national_id], ['شماره ثبت', c.register_number], ['تاریخ ثبت / تأسیس', c.register_date], ['کد اقتصادی', c.economic_code],
                ['استان / شهر', [c.province, c.city].filter(Boolean).join(' / ')], ['کد پستی', c.postal_code], ['تلفن', c.phone], ['موبایل', c.mobile], ['وضعیت', c.active === false ? 'غیرفعال' : c.active ? 'فعال' : '—'],
                ['آدرس', c.address]].filter(x => x[1] || ['نام', 'آدرس'].includes(x[0])).map(x => `<div><span>${x[0]}</span><b>${esc(fa(x[1] || '—'))}</b></div>`).join('')}</div>`;
        } else if (it.person || it.person_name) {
            const p = it.person || {};
            inner += `<div class="inq-dl">${[['نام', p.name || it.person_name], ['نام خانوادگی', p.family], ['نام پدر', p.father || it.father_name], ['جنسیت', p.gender]].map(x => `<div><span>${x[0]}</span><b>${esc(x[1] || '—')}</b></div>`).join('')}</div>`;
        }
        if (it.kind === 'person_postal' || it.kind === 'postal' || (it.kind === 'legal' && it.postal)) {
            const v = it.postal_valid, c = v === true ? '#059669' : v === false ? '#e11d48' : '#d97706';
            inner += `<div class="mt-2 rounded-xl p-2.5 text-xs font-bold" style="background:${c}14;color:${c}"><i class="fas ${v === true ? 'fa-circle-check' : v === false ? 'fa-circle-xmark' : 'fa-triangle-exclamation'} ml-1"></i>کد پستی ${fa(it.postal || '')}: ${esc(it.postal_text || (v === true ? 'تأیید شد' : v === false ? 'تأیید نشد' : 'نامشخص'))}</div>`;
        }
        if (it.status === 'FAIL' && !inner) inner = `<p class="text-xs font-bold text-rose-700">${esc(it.notice || 'استعلام انجام نشد.')}</p>`;
        return inner;
    }
    // تنظیمات ← مهرِ استعلامات: پیش‌نمایشِ جای مهر روی یک صفحه‌ی نمونه
    async function stampBox(body) {
        const box = body.querySelector('#inq-stamp'); if (!box) return;
        const paint = d => {
            box.querySelector('[data-stp]').innerHTML = d.ok ? `<div class="text-center"><img src="data:image/png;base64,${d.preview_b64}" style="width:170px;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 6px 18px -10px #0f172a;background:#fff">
                <p class="text-[10px] mt-1 ${d.has ? 'text-emerald-600' : 'text-amber-600'} font-bold">${d.has ? '✓ مهر تنظیم شده (پیش‌نمایشِ جای مهر)' : 'هنوز مهری بارگذاری نشده'}</p></div>` : `<span class="text-rose-600">${esc(d.error || '')}</span>`;
            box.querySelector('[data-str]').style.display = d.has ? '' : 'none';
        };
        paint(await post({ action: 'dmg_stamp_get' }));
        box.querySelector('[data-stu]').onchange = async e => {
            const f = e.target.files[0]; e.target.value = ''; if (!f) return;
            const fd = new FormData(); fd.append('action', 'dmg_stamp_upload'); fd.append('file', f);
            let d; try { d = await (await fetch(API, { method: 'POST', body: fd })).json(); } catch (x) { d = { ok: false, error: 'خطا در ارتباط با سرور.' }; }
            if (!d.ok) return toast(d.error || 'خطا', 'error');
            toast('مهر ذخیره شد.', 'success'); paint(d);
        };
        box.querySelector('[data-str]').onclick = async () => { if (!(await ask('حذفِ مهر', 'مهرِ استعلامات حذف شود؟', { danger: true, ok: 'حذف' }))) return; paint(await post({ action: 'dmg_stamp_remove' })); };
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
    // اجازه‌ی ساختن/ادامه‌ی گروهیِ هر نوع (شخصِ حقیقی، حقوقی، سنهاب جدا)
    const canGroupKind = k => { const c = V.boot.can; return k === 'legal' ? c.legal_group : k === 'sanhab' ? c.sanhab_group : (k === 'pbody' || k === 'pdamage') ? c.pbody_group : c.group; };
    async function renderGroup(body) {
        const can = V.boot.can;
        const gk = Object.entries(V.boot.group_kinds || {}).filter(([k]) => canGroupKind(k));
        const canNew = gk.length > 0;
        const sht = V.boot.sh_types || {};
        body.innerHTML = `<div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
            ${canNew ? `<div class="inq-card xl:col-span-2 space-y-3">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-file-arrow-up ml-1 text-indigo-500"></i>استعلامِ گروهیِ تازه</p>
                <label class="inq-f">نوعِ استعلام<select id="inq-gk" class="inq-in" style="font-size:12.5px">${gk.map(([k, l]) => `<option value="${k}" ${k === 'person_postal' ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                <div id="inq-gsh" class="grid grid-cols-2 gap-2 hidden"><label class="inq-f">جستجو با<select id="inq-gst" class="inq-in" style="font-size:12px">${(Array.isArray(sht) ? sht : Object.entries(sht)).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
                    <label class="inq-f">نوع<select id="inq-gsp" class="inq-in" style="font-size:12px"><option value="0">بیمه‌نامه</option><option value="1">سوابقِ خسارت</option></select></label>
                    <p class="col-span-2 text-[10.5px] text-sky-700 font-bold">سنهاب برای هر ردیف کدِ امنیتیِ خودش را می‌خواهد؛ بعد از شروع، ردیف‌ها یکی‌یکی با کپچا جلو می‌روند (کپچای بعدی خودکار می‌آید).</p></div>
                <div id="inq-gpb" class="grid grid-cols-2 gap-2 hidden">
                    <label class="inq-f" data-pbo="pbody">جستجو با<select id="inq-gpt" class="inq-in" style="font-size:12px">${Object.entries(V.boot.pb_types || {}).map(([k, l]) => `<option value="${k}" ${k === 'chassis' ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
                    <label class="inq-f flex items-center gap-2 mt-5" data-pbo="pbody"><input type="checkbox" id="inq-gpw" style="accent-color:#0f766e">چاپِ سامانه هم گرفته شود (کندتر)</label>
                    <label class="inq-f" data-pbo="pdamage">نوعِ بیمه<select id="inq-gpi" class="inq-in" style="font-size:12px">${Object.entries(V.boot.pb_ins || {}).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select></label>
                    <label class="inq-f" data-pbo="pdamage">خروجیِ هر ردیف<select id="inq-gpo" class="inq-in" style="font-size:12px"><option value="pdf">PDF</option><option value="shot">تصویرِ صفحه</option></select></label>
                    <p class="col-span-2 text-[10.5px] text-teal-700 font-bold">با همان نشستِ پاسارگاد یکی‌یکی انجام می‌شود (کپچا فقط برای ورود)؛ اگر cron در تنظیمات گذاشته شده باشد با بستنِ صفحه هم در پس‌زمینه ادامه پیدا می‌کند.${V.boot.pb_ready ? '' : ' <span class="text-amber-700">نام کاربری/رمزِ پاسارگاد هنوز در تنظیمات وارد نشده.</span>'}</p></div>
                <label class="inq-f">عنوان (اختیاری)<input id="inq-gt" class="inq-in" style="font-size:12.5px;letter-spacing:0" placeholder="مثلاً پرسنلِ شرکتِ نمونه - مهر"></label>
                <label class="block border-2 border-dashed border-indigo-200 hover:border-indigo-400 rounded-2xl p-5 text-center cursor-pointer">
                    <i class="fas fa-file-excel text-3xl text-emerald-500"></i><p class="text-xs font-black text-slate-600 mt-2" id="inq-gfn">انتخابِ فایلِ اکسل (xlsx / csv)</p>
                    <p class="text-[10.5px] text-slate-400 mt-1" id="inq-ghint">ستون‌ها با نامِ سرستون شناخته می‌شوند: کد ملی، تاریخ تولد، کد پستی (برای حقوقی: شناسه ملی)</p>
                    <input type="file" id="inq-gf" accept=".xlsx,.xls,.csv" class="hidden"></label>
                <div class="flex flex-wrap gap-2"><a id="inq-gs" class="inq-fbtn" href="#"><i class="fas fa-download ml-1"></i>دانلودِ اکسلِ نمونه</a></div>
                <div id="inq-gp"></div>
                <button id="inq-gstart" class="inq-go w-full hidden"><i class="fas fa-play ml-1"></i>شروعِ استعلامِ گروهی</button>
                <p class="text-[10.5px] text-slate-400 leading-6">ردیف‌ها یکی‌یکی و پشتِ سرِ هم استعلام می‌شوند (نشستِ سامانه یکی است). اگر این صفحه را ببندید، با «ادامه» از همان‌جا جلو می‌رود${V.boot.is_admin ? '؛ اگر cron را در تنظیمات گذاشته باشید، در پس‌زمینه هم ادامه پیدا می‌کند' : ''}.</p>
            </div>` : ''}
            <div class="inq-card ${canNew ? 'xl:col-span-3' : 'xl:col-span-5'}"><div class="flex items-center justify-between mb-3"><p class="font-black text-sm text-slate-800"><i class="fas fa-layer-group ml-1 text-violet-500"></i>استعلام‌های گروهی</p><button class="inq-fbtn" id="inq-gr"><i class="fas fa-rotate"></i></button></div><div id="inq-gcap"></div><div id="inq-gl" class="space-y-2 mt-2"></div></div></div>`;
        if (canNew) {
            const kindSel = body.querySelector('#inq-gk'), fileIn = body.querySelector('#inq-gf'), sample = body.querySelector('#inq-gs');
            const isSh = () => kindSel.value === 'sanhab', isPb = () => kindSel.value === 'pbody' || kindSel.value === 'pdamage';
            const shT = () => body.querySelector('#inq-gst').value, shP = () => body.querySelector('#inq-gsp').value;
            const pbT = () => body.querySelector('#inq-gpt').value;
            const upAct = () => isSh() ? 'sh_batch_upload' : (isPb() ? 'pbg_upload' : 'batch_upload');
            const fdOf = (f, extra) => { const fd = new FormData(); fd.append('action', upAct()); fd.append('kind', kindSel.value); fd.append('type', isPb() ? pbT() : shT()); fd.append('plcy', shP());
                fd.append('ins', body.querySelector('#inq-gpi').value); fd.append('out', body.querySelector('#inq-gpo').value); fd.append('want_print', body.querySelector('#inq-gpw').checked ? '1' : '');
                Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v)); fd.append('file', f); return fd; };
            const setSample = () => {
                body.querySelector('#inq-gsh').classList.toggle('hidden', !isSh());
                body.querySelector('#inq-gpb').classList.toggle('hidden', !isPb());
                body.querySelectorAll('[data-pbo]').forEach(x => x.style.display = x.dataset.pbo === kindSel.value ? '' : 'none');
                body.querySelector('#inq-ghint').textContent = isSh() ? 'پلاک: چهار ستون (ایران، سه‌رقمی، حرف، دورقمیِ چپ) یا یک ستونِ کامل مثلِ «۲۱ ایران - ۱۲۳ ب ۱۱»؛ بقیه: مقدار در ستونِ اول (موتور و شاسی: دو ستون)'
                    : kindSel.value === 'pdamage' ? 'ستون‌ها: شماره بیمه‌نامه، شماره شاسی، شماره موتور (با سرستون یا به همین ترتیب؛ هر ردیف دست‌کم یکی)'
                    : kindSel.value === 'pbody' ? 'مقدارِ جستجو در ستونِ اول (شاسی و موتور: دو ستون؛ پلاک: چهار ستون «ایران، سه‌رقمی، حرف، دورقمی» یا یک ستونِ کامل)'
                    : 'ستون‌ها با نامِ سرستون شناخته می‌شوند: کد ملی، تاریخ تولد، کد پستی (برای حقوقی: شناسه ملی)';
                sample.href = isSh() ? `${API}?action=sh_batch_sample&type=${shT()}` : (isPb() ? `${API}?action=pbg_sample&kind=${kindSel.value}&type=${pbT()}` : `${API}?action=batch_sample&kind=${kindSel.value}`);
                body.querySelector('#inq-gp').innerHTML = ''; body.querySelector('#inq-gstart').classList.add('hidden'); fileIn.value = ''; body.querySelector('#inq-gfn').textContent = 'انتخابِ فایلِ اکسل (xlsx / csv)';
            };
            kindSel.onchange = setSample; body.querySelector('#inq-gst').onchange = setSample; body.querySelector('#inq-gpt').onchange = setSample; setSample();
            fileIn.onchange = async () => {
                const f = fileIn.files[0]; if (!f) return;
                body.querySelector('#inq-gfn').textContent = f.name;
                const fd = fdOf(f, { preview: '1' });
                const p = body.querySelector('#inq-gp'); p.innerHTML = '<p class="text-xs text-indigo-600"><i class="fas fa-spinner fa-spin ml-1"></i>در حال خواندن…</p>';
                let d; try { d = await (await fetch(API, { method: 'POST', body: fd })).json(); } catch (e) { d = { ok: false, error: 'خطا در ارتباط.' }; }
                if (!d.ok) { p.innerHTML = `<p class="text-xs text-rose-600 font-bold">${esc(d.error)}</p>`; return; }
                p.innerHTML = `<p class="text-xs font-bold text-emerald-700 mb-1"><i class="fas fa-circle-check ml-1"></i>${fa(d.count)} ردیف خوانده شد</p>
                    <div class="max-h-48 overflow-auto border rounded-xl"><table class="inq-tbl">${isSh() || isPb() ? `<thead><tr><th>#</th><th>${esc(isPb() ? (kindSel.value === 'pdamage' ? 'استعلامِ خسارت' : body.querySelector('#inq-gpt').selectedOptions[0].textContent) : body.querySelector('#inq-gst').selectedOptions[0].textContent)}</th></tr></thead><tbody>${d.rows.slice(0, 50).map((r, i) => `<tr><td>${fa(i + 1)}</td><td dir="ltr">${esc(fa(r))}</td></tr>`).join('')}</tbody>`
                        : `<thead><tr><th>#</th><th>کد ملی</th><th>تاریخ تولد</th><th>کد پستی</th></tr></thead><tbody>${d.rows.slice(0, 50).map((r, i) => `<tr><td>${fa(i + 1)}</td><td dir="ltr">${esc(fa(r.national_id))}</td><td dir="ltr">${esc(fa(r.birth || ''))}</td><td dir="ltr">${esc(fa(r.postal || ''))}</td></tr>`).join('')}</tbody>`}</table></div>`;
                const st = body.querySelector('#inq-gstart'); st.classList.remove('hidden');
                st.onclick = async () => {
                    st.disabled = true;
                    const fd2 = fdOf(f, { title: body.querySelector('#inq-gt').value.trim() });
                    let r; try { r = await (await fetch(API, { method: 'POST', body: fd2 })).json(); } catch (e) { r = { ok: false, error: 'خطا در ارتباط.' }; }
                    st.disabled = false;
                    if (!r.ok) { toast(r.error, 'error'); return; }
                    toast(`${fa(r.queued)} ردیف در صف` + (r.invalid.length ? ` · ${fa(r.invalid.length)} ردیف نامعتبر کنار گذاشته شد` : ''), r.invalid.length ? 'warning' : 'success');
                    p.innerHTML = r.invalid.length ? `<p class="text-[11px] text-amber-700 font-bold">ردیف‌های نامعتبر: ${r.invalid.slice(0, 20).map(x => fa(x.row) + ' (' + esc(x.error) + ')').join('، ')}</p>` : '';
                    st.classList.add('hidden'); fileIn.value = ''; body.querySelector('#inq-gfn').textContent = 'انتخابِ فایلِ اکسل (xlsx / csv)';
                    await loadBatches(body); if (isSh()) runShBatch(r.batch_id, body); else runBatch(r.batch_id, body);
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
        const p = b.total ? b.done / b.total * 100 : 100, c = b.counts, running = V.batchRun[b.id], cg = canGroupKind(b.kind);
        const st = b.status === 'RUNNING' ? (running ? '<span class="inq-badge" style="background:#e0e7ff;color:#4338ca"><i class="fas fa-spinner fa-spin"></i>در حال اجرا</span>' : '<span class="inq-badge" style="background:#fef3c7;color:#92400e">متوقف / منتظرِ ادامه</span>')
            : b.status === 'CANCELED' ? '<span class="inq-badge" style="background:#f1f5f9;color:#64748b">لغو شد</span>' : '<span class="inq-badge" style="background:#d1fae5;color:#065f46"><i class="fas fa-check"></i>تمام شد</span>';
        return `<div class="border border-slate-100 rounded-2xl p-3" data-b="${b.id}">
            <div class="flex flex-wrap items-center justify-between gap-2"><div class="min-w-0"><b class="text-xs text-slate-800">${esc(b.title)}</b><small class="block text-[10.5px] text-slate-400">${esc(b.kind_fa)} · ${esc(fa(b.created_j))} · ${esc(b.user_name || '')}</small></div>${st}</div>
            <div class="inq-bar mt-2"><i style="width:${p.toFixed(1)}%"></i></div>
            <div class="flex flex-wrap items-center justify-between gap-2 mt-2 text-[10.5px] font-bold text-slate-500">
                <span>${fa(b.done)} از ${fa(b.total)} · <span class="text-emerald-600">${fa(c.OK)} موفق</span> · <span class="text-amber-600">${fa(c.WARN)} هشدار</span> · <span class="text-rose-600">${fa(c.FAIL)} ناموفق</span></span>
                <span class="flex flex-wrap gap-1">${cg && b.status === 'RUNNING' && !running ? `<button class="inq-fbtn" data-ba="run" data-kind="${esc(b.kind)}"><i class="fas fa-play ml-1"></i>ادامه${b.kind === 'sanhab' ? ' (با کپچا)' : ''}</button>` : ''}
                ${cg && b.status === 'RUNNING' ? `<button class="inq-fbtn" data-ba="cancel" style="background:#fee2e2;color:#be123c">لغو</button>` : ''}
                ${cg && b.status !== 'RUNNING' && c.FAIL ? `<button class="inq-fbtn" data-ba="retry" data-kind="${esc(b.kind)}"><i class="fas fa-rotate-right ml-1"></i>دوباره برای ناموفق‌ها</button>` : ''}
                <button class="inq-fbtn" data-ba="rows"><i class="fas fa-list ml-1"></i>ردیف‌ها</button>
                ${V.boot.can.export ? `<a class="inq-fbtn" href="${API}?action=batch_export&id=${b.id}"><i class="fas fa-file-excel ml-1"></i>خروجیِ اکسل</a>` : ''}
                ${(b.kind === 'pdamage' || b.kind === 'pbody') && (c.OK + c.WARN) ? `<a class="inq-fbtn" href="${API}?action=pbg_zip&id=${b.id}" title="${b.kind === 'pdamage' ? 'PDF یا تصویرِ صفحه‌ی هر ردیف' : 'چاپِ سامانه‌ی هر ردیف (اگر گرفته شده)'}"><i class="fas fa-file-zipper ml-1"></i>همه‌ی فایل‌ها (ZIP)</a><a class="inq-fbtn" href="${API}?action=pbg_zip&id=${b.id}&stamp=1" title="همان فایل‌ها، هر کدام با مهرِ استعلامات"><i class="fas fa-stamp ml-1"></i>ZIP با مهر</a>` : ''}</span></div></div>`;
    }
    function bindBatches(body) {
        body.querySelectorAll('[data-b]').forEach(card => {
            const id = +card.dataset.b;
            card.querySelectorAll('[data-ba]').forEach(btn => btn.onclick = async () => {
                const a = btn.dataset.ba;
                const sh = btn.dataset.kind === 'sanhab';
                if (a === 'run') (sh ? runShBatch : runBatch)(id, body);
                else if (a === 'rows') { V.hist = { page: 1, q: '', kind: '', status: '', source: '', from: '', to: '', user: '', ref: '', batch_id: id }; V.tab = 'history'; render(); }
                else if (a === 'cancel' || a === 'retry') { delete V.batchRun[id]; const d = await post({ action: a === 'cancel' ? 'batch_cancel' : 'batch_resume', id }); if (!d.ok) toast(d.error, 'error'); await loadBatches(body); if (a === 'retry') (sh ? runShBatch : runBatch)(id, body); }
            });
        });
    }
    async function runBatch(id, body) {
        if (V.batchRun[id]) return;
        V.batchRun[id] = true;
        try {
            for (let i = 0; i < 5000 && V.batchRun[id]; i++) {
                const d = await post({ action: 'batch_step', id });
                if (!d.ok) { toast(d.error, 'error'); break; }
                if (d.need_captcha) {
                    const host = body.querySelector('#inq-gcap');
                    const ok = host ? await captchaBox(host, d.captcha) : await captchaModal(d.captcha);
                    if (!ok) { toast('استعلامِ گروهی متوقف شد؛ هر وقت خواستید «ادامه» را بزنید.', 'warning'); break; }
                    continue;
                }
                const card = body.querySelector(`[data-b="${id}"]`);
                if (card && d.batch) { card.outerHTML = batchCard(d.batch); bindBatches(body); }
                if (!d.batch || d.batch.status !== 'RUNNING') { toast('استعلامِ گروهی تمام شد.', 'success'); break; }
                if (!d.last) await sleep(2500);
            }
        } finally { delete V.batchRun[id]; const b = body.querySelector('#inq-gl'); if (b && b.isConnected) loadBatches(body); }
    }

    // ---------------- استعلامِ حق بیمه‌ی ثالث (همان محاسبه‌ی مینی‌اپ) + پر شدنِ خودکارِ سابقه از سنهاب ----------------
    const PQ = { init: null, model: null, kind: '', tier: 0 };
    async function renderPremium(body) {
        const P = 'api/premium_actions.php';
        if (!PQ.init) { try { PQ.init = await (await fetch(P + '?action=init')).json(); } catch (e) { PQ.init = { ok: false }; } }
        const I = PQ.init;
        if (!I || !I.ok) { body.innerHTML = '<div class="inq-card text-xs text-rose-600">اطلاعاتِ استعلامِ حق بیمه نیامد.</div>'; return; }
        const curY = (() => { try { return JDatePick.jToday()[0]; } catch (e) { return 1405; } })();
        const pctOpts = Array.from({ length: 15 }, (x, i) => i * 5).map(p => `<option value="${p}">${fa(p)}٪${p === 0 ? ' (بدون تخفیف)' : ''}</option>`).join('');
        const nOpts = Array.from({ length: 4 }, (x, i) => `<option value="${i}">${i === 3 ? '۳ و بیشتر' : fa(i)}</option>`).join('');
        const tierFa = t => t === 1000 ? 'یک میلیارد تومان' : fa(t) + ' میلیون تومان';
        body.innerHTML = `<div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
            <div class="inq-card xl:col-span-2 space-y-3">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-shield-halved ml-1 text-cyan-600"></i>سابقه از سنهاب (اختیاری)</p>
                <p class="text-[10.5px] text-slate-500 leading-6">با پلاک یا شماره بیمه‌نامه استعلام بزنید و «نشاندن در فرم» را بزنید: تاریخ‌های بیمه‌نامه‌ی قبلی، درصدهای تخفیف و تعدادِ خسارت‌ها خودکار پر می‌شود؛ بیمه‌گر، انقضا و جزئیات هم همان‌جا دیده می‌شود.</p>
                ${V.boot.sh_ready ? '<div id="pq-sh"></div>' : '<p class="text-[11px] font-bold text-amber-700 bg-amber-50 rounded-xl p-2.5">سنهاب هنوز در تنظیمات تنظیم نشده؛ اطلاعات را دستی وارد کنید.</p>'}
            </div>
            <div class="inq-card xl:col-span-3 space-y-3">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-calculator ml-1 text-indigo-500"></i>محاسبه‌ی حق بیمه‌ی ثالث</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="inq-f">نوعِ وسیله<select id="pq-kind" class="inq-in" style="font-size:12.5px"><option value="">انتخاب کنید</option>${(I.kinds || []).map(k => `<option value="${esc(k.key)}">${esc(k.icon || '')} ${esc(k.fa)}</option>`).join('')}</select></label>
                    <label class="inq-f md:col-span-2">نامِ خودرو (جستجو)<input id="pq-q" class="inq-in" style="font-size:12.5px;letter-spacing:0" placeholder="مثلاً پژو ۲۰۶، پراید ۱۳۱، کشنده ..." autocomplete="off"></label></div>
                <div id="pq-models" class="flex flex-wrap gap-1.5"></div>
                <p id="pq-model" class="text-[11.5px] font-bold text-slate-600"></p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="inq-f">سال ساخت<select id="pq-year" class="inq-in" style="font-size:12.5px">${Array.from({ length: curY - 1299 }, (x, i) => curY - i).map(y => `<option value="${y}">${fa(y)}</option>`).join('')}</select></label>
                    <label class="inq-f">شروعِ بیمه‌نامه‌ی قبلی<input id="pq-start" class="inq-in" dir="ltr" data-jdate placeholder="۱۴۰۴/۰۸/۱۵"></label>
                    <label class="inq-f">انقضای بیمه‌نامه‌ی قبلی<input id="pq-exp" class="inq-in" dir="ltr" data-jdate placeholder="۱۴۰۵/۰۸/۱۵"></label></div>
                <div><span class="inq-f mb-1">سقف تعهد مالی</span><div class="flex flex-wrap gap-1.5" id="pq-tiers">${(I.liability_tiers || []).map(t => `<button type="button" class="inq-fbtn" data-tier="${t}">${tierFa(t)}</button>`).join('')}</div></div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <label class="inq-f">تخفیفِ ثالثِ قبلی<select id="pq-tp" class="inq-in" style="font-size:12.5px">${pctOpts}</select></label>
                    <label class="inq-f">تخفیفِ حوادثِ راننده‌ی قبلی<select id="pq-dr" class="inq-in" style="font-size:12.5px">${pctOpts}</select></label>
                    <span></span>
                    <label class="inq-f">خسارتِ مالیِ ثالث (سالِ قبل)<select id="pq-tpm" class="inq-in" style="font-size:12.5px">${nOpts}</select></label>
                    <label class="inq-f">خسارتِ بدنیِ ثالث (سالِ قبل)<select id="pq-tpb" class="inq-in" style="font-size:12.5px">${nOpts}</select></label>
                    <label class="inq-f">خسارتِ حوادثِ راننده (سالِ قبل)<select id="pq-drb" class="inq-in" style="font-size:12.5px">${nOpts}</select></label></div>
                <p id="pq-from" class="text-[11px] font-bold text-cyan-700"></p>
                <button class="inq-go w-full" id="pq-go"><i class="fas fa-calculator ml-1"></i>محاسبه‌ی حق بیمه</button>
                <div id="pq-out"></div>
            </div></div>`;
        const g = id => body.querySelector('#' + id);
        try { body.querySelectorAll('[data-jdate]').forEach(el => window.JDatePick && JDatePick.attach(el)); } catch (e) {}
        // پیش‌فرض مثلِ مینی‌اپ: انقضا = امروز، شروع = یک سال قبل
        try { const t = JDatePick.jToday(); g('pq-exp').value = fa(JDatePick.jFmt(t)); g('pq-start').value = fa(JDatePick.jFmt([t[0] - 1, t[1], t[2]])); } catch (e) {}
        const paintModel = () => { g('pq-model').innerHTML = PQ.model ? `<i class="fas fa-car ml-1 text-indigo-500"></i>${esc(PQ.model.title)}${PQ.model.sub ? ' · ' + esc(PQ.model.sub) : ''}${PQ.model.rate == null ? ' <span class="text-amber-700">(استعلامِ آنلاین ندارد؛ برای کارشناس ثبت می‌شود)</span>' : ''}` : ''; };
        let tm;
        const search = async () => {
            const k = g('pq-kind').value, q = g('pq-q').value.trim();
            if (!k) { g('pq-models').innerHTML = '<span class="text-[11px] text-amber-700">اول نوعِ وسیله را انتخاب کنید.</span>'; return; }
            let d; try { d = await (await fetch('api/vehicle_catalog.php?action=vsearch&kind=' + encodeURIComponent(k) + '&q=' + encodeURIComponent(q))).json(); } catch (e) { d = {}; }
            const list = (d.models || []).slice(0, 40);
            if (!list.length && d.brands) { g('pq-models').innerHTML = d.brands.slice(0, 40).map(b => `<button type="button" class="inq-fbtn" data-brand="${esc(b.brand || b)}">${esc(b.brand || b)}</button>`).join(''); g('pq-models').querySelectorAll('[data-brand]').forEach(x => x.onclick = () => { g('pq-q').value = x.dataset.brand; search(); }); return; }
            g('pq-models').innerHTML = list.map((m, i) => `<button type="button" class="inq-fbtn ${PQ.model && PQ.model.id === m.id ? '!bg-indigo-600 !text-white' : ''}" data-m="${i}">${esc(m.title)}</button>`).join('') || '<span class="text-[11px] text-slate-400">چیزی پیدا نشد.</span>';
            g('pq-models').querySelectorAll('[data-m]').forEach(x => x.onclick = () => { PQ.model = list[+x.dataset.m]; paintModel(); search(); });
        };
        g('pq-kind').onchange = () => { PQ.model = null; paintModel(); search(); };
        g('pq-q').oninput = () => { clearTimeout(tm); tm = setTimeout(search, 300); };
        body.querySelectorAll('[data-tier]').forEach(b => b.onclick = () => { PQ.tier = +b.dataset.tier; body.querySelectorAll('[data-tier]').forEach(x => { x.style.background = +x.dataset.tier === PQ.tier ? '#4338ca' : ''; x.style.color = +x.dataset.tier === PQ.tier ? '#fff' : ''; }); });
        // نتیجه‌ی سنهاب => فرم
        const near5 = v => Math.max(0, Math.min(70, Math.round((+v || 0) / 5) * 5));
        const apply = (b) => {
            if (!b) return;
            if (b.start_date) g('pq-start').value = fa(b.start_date);
            if (b.end_date) g('pq-exp').value = fa(b.end_date);
            if (b.tp_disc != null) g('pq-tp').value = String(near5(b.tp_disc));
            if (b.dr_disc != null) g('pq-dr').value = String(near5(b.dr_disc));
            const lc = b.loss_counts || {};
            g('pq-tpm').value = String(Math.min(3, lc.mali || 0)); g('pq-tpb').value = String(Math.min(3, lc.jani || 0)); g('pq-drb').value = String(Math.min(3, lc.driver || 0));
            g('pq-from').innerHTML = `<i class="fas fa-shield-halved ml-1"></i>از سنهاب: ${esc(b.insurer || '')} · بیمه‌نامه ${esc(fa(b.policy_no || ''))} · ${b.has_loss ? 'خسارت دارد' : 'عدم خسارت'}${b.vehicle ? ' · ' + esc(b.vehicle) : ''}`;
            if (b.vehicle && !g('pq-q').value) g('pq-q').value = b.vehicle;
        };
        if (window.SanhabUI && g('pq-sh')) SanhabUI.form(g('pq-sh'), { source: 'premium', onApply: apply, applyLabel: 'نشاندن در فرمِ حق بیمه' });
        g('pq-go').onclick = async () => {
            if (!PQ.model) { toast('نوع و نامِ خودرو را انتخاب کنید.', 'warning'); g('pq-q').focus(); return; }
            if (!PQ.tier) { toast('سقف تعهد مالی را انتخاب کنید.', 'warning'); return; }
            const b = { action: 'calculate', panel: 1, model_id: PQ.model.id, liability_tier: PQ.tier, model_year: +g('pq-year').value,
                        prev_start_jalali: en(g('pq-start').value).trim(), prev_expiry_jalali: en(g('pq-exp').value).trim(),
                        tp_prev_percent: g('pq-tp').value, dr_prev_percent: g('pq-dr').value, tp_mali: g('pq-tpm').value, tp_badani: g('pq-tpb').value, dr_mali: 0, dr_badani: g('pq-drb').value };
            const btn = g('pq-go'); btn.disabled = true;
            let d; try { d = await (await fetch(P, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(b) })).json(); } catch (e) { d = { ok: false, error: 'خطا در ارتباط.' }; }
            btn.disabled = false;
            const o = g('pq-out');
            if (!d.ok) { o.innerHTML = `<div class="inq-res mt-1" style="background:#fffbeb;border-color:#fde68a"><p class="text-xs font-bold text-amber-800">${esc(d.error || 'خطا')}</p></div>`; return; }
            const k = d.breakdown || {}, m = v => fa(Math.round(+v || 0).toLocaleString('en-US'));
            o.innerHTML = `<div class="inq-res mt-1" style="background:#eef2ff;border-color:#c7d2fe">
                <div class="flex flex-wrap items-center justify-between gap-2"><b class="text-sm text-indigo-900">${esc(d.category_name || '')} · تعهد ${tierFa(d.liability_tier)}</b><span class="text-[10.5px] text-slate-500">استعلامِ شماره‌ی ${fa(d.inquiry_id || '')}</span></div>
                <p class="text-2xl font-black text-indigo-700 mt-2">${m(d.grand_total)} <small class="text-xs">ریال</small></p>
                <div class="inq-dl mt-3">${[['حقِ بیمه‌ی پایه (بعد از تخفیف)', m(k.base_final)], ['مازادِ تعهد', m(k.excess_final)], ['حوادثِ راننده', m(k.driver_final)], ['سهمِ صندوق', m(k.fund_contribution)],
                    ['مالیات', m(d.tax)], ['درصدِ تخفیف/جریمه‌ی ثالث', fa(d.thirdparty_percent) + '٪'], ['درصدِ حوادثِ راننده', fa(d.driver_percent) + '٪'], ['روزهای دیرکرد', fa(d.days_late || 0)]]
                    .map(x => `<div><span>${x[0]}</span><b>${x[1]}</b></div>`).join('')}</div>
                <p class="text-[10.5px] text-slate-500 mt-2">مبلغ تقریبی است و به درستیِ اطلاعاتِ واردشده بستگی دارد.</p></div>`;
        };
    }

    // سنهابِ گروهی: هر ردیف با کپچای خودش (در همین صفحه)
    function runShBatch(id, body) {
        const host = body.querySelector('#inq-gcap');
        if (!host || !window.SanhabUI || V.batchRun[id]) return;
        V.batchRun[id] = true;
        SanhabUI.batch(host, id, {
            onUpdate: b => { const card = body.querySelector(`[data-b="${id}"]`); if (card) { card.outerHTML = batchCard(b); bindBatches(body); } },
            onDone: () => { delete V.batchRun[id]; const b = body.querySelector('#inq-gl'); if (b && b.isConnected) loadBatches(body); },
        });
        host.scrollIntoView({block: 'nearest'});
    }

    // ---------------- تاریخچه ----------------
    async function renderHistory(body) {
        const h = V.hist;
        body.innerHTML = `<div class="inq-card"><div class="inq-hbar flex flex-wrap gap-2 mb-3 items-center">
            <input id="inq-hq" class="inq-in !mt-0 flex-1 min-w-[12rem]" style="font-size:12px;letter-spacing:0;padding:8px 12px" placeholder="🔍 کد ملی، نام، کد پستی، پلاک، کاربر" value="${esc(h.q)}">
            <select id="inq-hk" class="inq-in !mt-0 w-auto" style="font-size:12px;padding:8px"><option value="">همه‌ی نوع‌ها</option>${Object.entries(V.boot.kinds_all || V.boot.kinds).map(([k, l]) => `<option value="${k}" ${h.kind === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
            <select id="inq-hs" class="inq-in !mt-0 w-auto" style="font-size:12px;padding:8px"><option value="">همه‌ی وضعیت‌ها</option>${['OK', 'WARN', 'FAIL', 'QUEUED'].map(s => `<option value="${s}" ${h.status === s ? 'selected' : ''}>${ST_UI[s][0]}</option>`).join('')}</select>
            <select id="inq-hsrc" class="inq-in !mt-0 w-auto" style="font-size:12px;padding:8px"><option value="">همه‌ی منبع‌ها</option>${Object.entries(SRC_FA).map(([k, l]) => `<option value="${k}" ${h.source === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
            <select id="inq-href" class="inq-in !mt-0 w-auto" style="font-size:12px;padding:8px"><option value="">همه (با/بی ردیف)</option><option value="any" ${h.ref === 'any' ? 'selected' : ''}>فقط مربوط به ردیف/پرونده</option><option value="C" ${h.ref === 'C' ? 'selected' : ''}>ردیف‌های شرکتی</option><option value="P" ${h.ref === 'P' ? 'selected' : ''}>پرونده‌های پرسنلی</option></select>
            <select id="inq-hu" class="inq-in !mt-0 w-auto" style="font-size:12px;padding:8px;${V.histUsers ? '' : 'display:none'}"><option value="">همه‌ی کاربرها</option>${(V.histUsers || []).map(u => `<option value="${esc(u)}" ${h.user === u ? 'selected' : ''}>${esc(u)}</option>`).join('')}</select>
            <span class="inline-flex items-center gap-1 text-[11px] text-slate-500">از <input id="inq-hf" data-jdate class="inq-in !mt-0" style="font-size:12px;padding:8px;width:7.5rem;letter-spacing:0" placeholder="۱۴۰۵/۰۸/۰۱" value="${esc(h.from || '')}">
                تا <input id="inq-ht" data-jdate class="inq-in !mt-0" style="font-size:12px;padding:8px;width:7.5rem;letter-spacing:0" placeholder="۱۴۰۵/۰۸/۳۰" value="${esc(h.to || '')}"></span>
            ${h.q || h.kind || h.status || h.source || h.from || h.to || h.user || h.ref ? '<button class="inq-fbtn" data-hclr><i class="fas fa-filter-circle-xmark ml-1"></i>پاک کردنِ فیلترها</button>' : ''}
            ${h.batch_id ? `<span class="inq-badge" style="background:#ede9fe;color:#5b21b6">گروهِ #${fa(h.batch_id)} <button data-clrb>✕</button></span>` : ''}
            ${V.boot.can.export ? `<a id="inq-hx" class="inq-fbtn" href="#"><i class="fas fa-file-excel ml-1"></i>خروجیِ اکسل</a>` : ''}</div>
            <div id="inq-hl" class="overflow-x-auto"><p class="text-center text-xs text-slate-400 py-8"><i class="fas fa-spinner fa-spin"></i></p></div></div>`;
        const q = body.querySelector('#inq-hq'); let tm;
        q.oninput = () => { clearTimeout(tm); tm = setTimeout(() => { h.q = q.value.trim(); h.page = 1; loadHist(body); }, 350); };
        [['#inq-hk', 'kind'], ['#inq-hs', 'status'], ['#inq-hsrc', 'source'], ['#inq-href', 'ref'], ['#inq-hu', 'user']].forEach(([s, k]) => body.querySelector(s).onchange = e => { h[k] = e.target.value; h.page = 1; loadHist(body); });
        // تاریخ: با انتخاب از تقویم (change) یا تایپِ کامل
        [['#inq-hf', 'from'], ['#inq-ht', 'to']].forEach(([s, k]) => { const i = body.querySelector(s); const go = () => { const v = i.value.trim(); if (v === h[k] || (v && !/^[\d۰-۹]{4}[\/.\-][\d۰-۹]{1,2}[\/.\-][\d۰-۹]{1,2}$/.test(v))) return; h[k] = v; h.page = 1; loadHist(body); }; i.addEventListener('change', go); i.addEventListener('input', () => { clearTimeout(tm); tm = setTimeout(go, 600); }); });
        const hc = body.querySelector('[data-hclr]'); if (hc) hc.onclick = () => { Object.assign(h, { q: '', kind: '', status: '', source: '', from: '', to: '', user: '', ref: '', page: 1 }); renderHistory(body); };
        const cb = body.querySelector('[data-clrb]'); if (cb) cb.onclick = () => { delete h.batch_id; renderHistory(body); };
        loadHist(body);
    }
    async function loadHist(body) {
        const h = V.hist, box = body.querySelector('#inq-hl');
        const x = body.querySelector('#inq-hx');
        if (x) x.href = `${API}?action=history_export&` + new URLSearchParams(Object.fromEntries(Object.entries(h).filter(([k, v]) => v && k !== 'page'))).toString();
        const d = await post(Object.assign({ action: 'history' }, h));
        if (!d.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        // فهرستِ کاربرها برای فیلتر (فقط کسی که همه‌ی تاریخچه را می‌بیند)
        if (d.users && !V.histUsers) { V.histUsers = d.users; const us = body.querySelector('#inq-hu'); if (us) { us.innerHTML = '<option value="">همه‌ی کاربرها</option>' + d.users.map(u => `<option value="${esc(u)}" ${h.user === u ? 'selected' : ''}>${esc(u)}</option>`).join(''); us.style.display = ''; } }
        if (!d.items.length) { box.innerHTML = '<p class="text-center text-xs text-slate-400 py-10">استعلامی پیدا نشد.</p>'; return; }
        box.innerHTML = `${!d.all ? '<p class="text-[10.5px] text-slate-400 mb-2">فقط استعلام‌های خودتان نشان داده می‌شود.</p>' : ''}<table class="inq-tbl"><thead><tr><th>#</th><th>زمان</th><th>نوع</th><th>کد/شناسه ملی / پلاک</th><th>تاریخ تولد</th><th>نتیجه‌ی شخص</th><th>کد پستی</th><th>وضعیت</th><th>کجا / کاربر</th><th></th></tr></thead><tbody>
            ${d.items.map(it => `<tr>
                <td class="text-slate-400">${fa(it.batch_id ? it.row_no : it.id)}</td><td class="whitespace-nowrap">${esc(fa(it.created_j))}</td><td>${esc(it.kind_fa)}</td>
                <td ${it.query && it.kind !== 'legal' ? '' : 'dir="ltr"'} class="${it.kind === 'ocr' ? '' : 'font-mono'}">${esc(fa(it.kind === 'sanhab' || it.kind === 'ocr' || it.kind === 'pbody' || it.kind === 'pdamage' ? (it.query || '') : [it.national_id, it.kind === 'legal' ? it.query : ''].filter(Boolean).join(' · ')))}</td><td dir="ltr">${esc(fa(it.birth || ''))}</td>
                <td>${it.kind === 'ocr' && it.ocr_info ? `<b class="text-violet-700">${fa(it.ocr_info.pages)} صفحه${it.ocr_info.engines.length ? ' · ' + esc(it.ocr_info.engines.join('، ')) : ''}${it.ocr_info.files ? ' · <i class="fas fa-file-word" title="فایل‌های اصلیِ i2pdf"></i>' : ''}</b><small class="block text-slate-400 max-w-[16rem] truncate" dir="auto">${esc((it.notice || '').slice(0, 90))}</small>` : `<b>${esc(it.company ? it.company.name : pName(it) || '—')}</b>`}${it.father_name ? `<small class="block text-slate-400">فرزند ${esc(it.father_name)}</small>` : ''}${it.kind === 'sanhab' && it.sanhab && window.SanhabUI ? `<small class="block font-bold" style="color:${SanhabUI.tone(it)[1]}">${esc(fa(SanhabUI.shortText(it)))}</small>` : ''}</td>
                <td>${it.postal ? `<span dir="ltr">${fa(it.postal)}</span>${it.postal_valid === true ? ' <i class="fas fa-circle-check text-emerald-600"></i>' : it.postal_valid === false ? ' <i class="fas fa-circle-xmark text-rose-600"></i>' : ''}${it.postal_text ? `<small class="block text-slate-400 max-w-[14rem]">${esc(it.postal_text)}</small>` : ''}` : '—'}</td>
                <td>${badge(it.status)}${it.cached ? ' <i class="fas fa-bolt text-indigo-400" title="از نتیجه‌ی قبلی"></i>' : ''}${it.status === 'FAIL' && it.notice && it.kind !== 'ocr' ? `<small class="block text-rose-500 max-w-[14rem]">${esc(it.notice)}</small>` : ''}</td>
                <td><small class="font-bold ${it.source === 'issue' ? 'text-indigo-700' : ''}">${esc(SRC_FA[it.source] || it.source)}</small>${it.place ? `<small class="block text-slate-600 max-w-[15rem] truncate" title="${esc(it.place.label)}"><i class="fas ${it.place.type === 'C' ? 'fa-car' : 'fa-user'} ml-1 text-slate-400"></i>${esc(fa(it.place.label))}</small>` : ''}<small class="block text-slate-400"><i class="fas fa-user-pen ml-1"></i>${esc(it.user_name || '')}</small>${(it.saved || []).length ? `<small class="block text-emerald-700" title="${esc(it.saved.map(x => (x.what || '') + ' · ' + (x.user || '')).join('\n'))}"><i class="fas fa-folder-open ml-1"></i>${fa(it.saved.length)} خروجی در پوشه (با مهر)</small>` : ''}</td>
                <td class="whitespace-nowrap">${V.boot.is_admin ? `<button class="inq-fbtn" data-det="${it.id}">جزئیات</button>` : `<button class="inq-fbtn" data-det="${it.id}">نتیجه</button>`}${V.boot.can.delete ? ` <button class="inq-fbtn" data-del="${it.id}" style="background:#fee2e2;color:#be123c">✕</button>` : ''}</td></tr>`).join('')}</tbody></table>
            ${d.pages > 1 ? `<div class="flex items-center justify-center gap-2 mt-3 text-xs"><button class="inq-fbtn" data-pg="-1" ${d.page <= 1 ? 'disabled' : ''}>قبلی</button><span>${fa(d.page)} از ${fa(d.pages)} (${fa(d.total)})</span><button class="inq-fbtn" data-pg="1" ${d.page >= d.pages ? 'disabled' : ''}>بعدی</button></div>` : ''}`;
        box.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { h.page += +b.dataset.pg; loadHist(body); });
        box.querySelectorAll('[data-det]').forEach(b => b.onclick = () => detail(+b.dataset.det));
        box.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (!(await ask('حذفِ استعلام', 'این استعلام از تاریخچه حذف شود؟', { danger: true, ok: 'حذف' }))) return; const r = await post({ action: 'delete', id: +b.dataset.del }); if (!r.ok) toast(r.error, 'error'); loadHist(body); });
    }
    // جا (پنلِ صدور / ردیف / پرونده)، کاربر و خروجی‌هایی که در پوشه ذخیره شد
    const placeHtml = it => {
        const parts = [`<span><i class="fas fa-location-dot ml-1 text-slate-400"></i>${esc(SRC_FA[it.source] || it.source || '')}</span>`];
        if (it.place) parts.push(`<span><i class="fas ${it.place.type === 'C' ? 'fa-car' : 'fa-user'} ml-1 text-slate-400"></i>${esc(fa(it.place.label))}</span>`);
        if (it.user_name) parts.push(`<span><i class="fas fa-user-pen ml-1 text-slate-400"></i>${esc(it.user_name)}</span>`);
        if (it.created_j) parts.push(`<span><i class="far fa-clock ml-1 text-slate-400"></i>${esc(fa(it.created_j))}</span>`);
        const sv = (it.saved || []).map(x => `<div class="text-[11px] text-emerald-800"><i class="fas fa-folder-open ml-1"></i>${esc(x.what || '')} → <span dir="auto">${esc(x.name || '')}</span> <span class="text-slate-400">· ${esc(x.user || '')}</span></div>`).join('');
        return `<div class="rounded-xl border border-slate-100 bg-slate-50 p-2.5 mb-3"><div class="flex flex-wrap gap-x-4 gap-y-1 text-[11.5px] text-slate-700">${parts.join('')}</div>${sv ? `<div class="mt-1.5 pt-1.5 border-t border-slate-200">${sv}</div>` : ''}</div>`;
    };
    async function detail(id) {
        const d = await post({ action: 'get', id });
        if (!d.ok) { toast(d.error, 'error'); return; }
        if (d.item.kind === 'ocr') { ocrDetail(d.item); return; }
        if ((d.item.kind === 'pbody' || d.item.kind === 'pdamage') && window.PBodyUI) {
            const m = document.createElement('div'); m.className = 'inq-modal';
            m.innerHTML = `<div style="width:min(980px,100%)"><div class="flex items-center justify-between p-4 border-b"><b class="text-sm">${esc(d.item.kind_fa)} #${fa(id)} · ${esc(fa(d.item.created_j || ''))}${d.item.user_name ? ' · ' + esc(d.item.user_name) : ''}</b><button class="inq-fbtn" data-x>بستن</button></div>
                <div class="p-4" data-h></div>${V.boot.is_admin ? '<div class="px-4 pb-4"><button class="inq-fbtn" data-steps><i class="fas fa-code ml-1"></i>صفحه‌های سامانه (برای بررسی)</button><div data-sl class="flex flex-wrap gap-1.5 mt-2"></div></div>' : ''}</div>`;
            m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
            document.body.appendChild(m);
            m.querySelector('[data-h]').insertAdjacentHTML('beforeend', placeHtml(d.item));
            m.querySelector('[data-h]').appendChild(PBodyUI.details(d.item));
            const sb = m.querySelector('[data-steps]');
            if (sb) sb.onclick = async () => {
                const r = await post({ action: 'pb_steps', id });
                m.querySelector('[data-sl]').innerHTML = r.ok && r.files.length ? r.files.map(f => `<a class="inq-fbtn" target="_blank" href="${API}?action=pb_step&id=${id}&f=${encodeURIComponent(f)}">${esc(f)}</a>`).join('') : '<small class="text-slate-400">صفحه‌ای ذخیره نشده.</small>';
            };
            return;
        }
        if (d.item.kind === 'sanhab' && window.SanhabUI) {
            const m = document.createElement('div'); m.className = 'inq-modal';
            m.innerHTML = `<div style="width:min(900px,100%)"><div class="flex items-center justify-between p-4 border-b"><b class="text-sm">سنهاب #${fa(id)}${d.item.user_name ? ' · ' + esc(d.item.user_name) : ''}</b><button class="inq-fbtn" data-x>بستن</button></div><div class="p-4 overflow-y-auto" data-h></div></div>`;
            m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
            document.body.appendChild(m);
            const h = m.querySelector('[data-h]');
            h.innerHTML = SanhabUI.resultHtml(d.item, {});
            const db = h.querySelector('[data-shd]'); if (db) db.onclick = () => SanhabUI.details(d.item);
            const stb = h.querySelector('[data-shstamp]');
            if (stb) stb.onchange = () => h.querySelectorAll('a[data-out]').forEach(a => { a.href = a.href.replace(/&stamp=1$/, '') + (stb.checked ? '&stamp=1' : ''); });
            h.insertAdjacentHTML('afterbegin', placeHtml(d.item));
            const cb = h.querySelector('[data-shc]'); if (cb) cb.onclick = async () => { try { await navigator.clipboard.writeText((d.item.sanhab || {}).summary_text || ''); toast('کپی شد.', 'success'); } catch (e) {} };
            return;
        }
        const m = document.createElement('div'); m.className = 'inq-modal';
        m.innerHTML = `<div><div class="flex items-center justify-between p-4 border-b"><b class="text-sm">استعلامِ #${fa(id)} · ${esc(fa(d.item.created_j))}${d.item.user_name ? ' · ' + esc(d.item.user_name) : ''}</b><button class="inq-fbtn" data-x>بستن</button></div><div class="p-4 overflow-y-auto">${resultHtml(d.item, true)}</div></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
        document.body.appendChild(m);
        const det = m.querySelector('details'); if (det) det.open = true;
        bindResult(m, d.item);
    }

    // ---------------- پردازش فایل هوشمند ----------------
    // مثلِ سایتِ i2pdf: فایل (PDF یا عکس) ← انتخابِ صفحه‌ها (همه یا چندتا) ← زبان (فارسی/انگلیسی) و قالبِ خروجی ← «شروع»
    // ← تایم‌لاینِ هر صفحه با زمانِ سپری‌شده ← «Your result is ready.» ← متنِ هر صفحه (کپی/ویرایش) و دانلود با قالب‌های مختلف.
    // موتورها به ترتیبِ اولویتِ تنظیمات (i2pdf، هوشِ مصنوعی، OCR.space، مرورگر) و صفحه‌ها هم‌زمان (smart-ocr.js)
    const OCR = { pages: [], res: [], id: 0, name: '', busy: false, prog: {} };
    const OCR_ENG = { i2pdf: 'i2pdf.com', ai: 'هوشِ مصنوعی', ocrspace: 'OCR.space', browser: 'مرورگر (Tesseract)' };
    const OCR_FMT = { txt: 'Editable text (.txt)', simple_pdf: 'Simple PDF (text only)', docx: 'Word document (.docx)' };
    const mmss = s => fa(String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(Math.floor(s % 60)).padStart(2, '0'));
    const ocrUrl = (id, fmt, n) => `${API}?action=ocr_export&id=${id}&fmt=${fmt}${n ? '&page=' + n : ''}`;
    // دانلودها: TXT/Word/PDFِ ساختِ سایتِ خودمان (از متنِ ویرایش‌شده) + فایل‌های اصلیِ i2pdf (Word و PDF) اگر هست
    const ocrDl = (id, n, files) => ['txt', 'docx', 'pdf'].map(f => `<a class="inq-fbtn" target="_blank" href="${ocrUrl(id, f, n)}"><i class="fas ${{ txt: 'fa-file-lines', docx: 'fa-file-word', pdf: 'fa-file-pdf' }[f]} ml-1"></i>${{ txt: 'TXT', docx: 'Word', pdf: 'PDF' }[f]}</a>`).join(' ')
        + ['docx', 'pdf'].filter(k => files && files[k]).map(k => ` <a class="inq-fbtn" style="background:#ede9fe;color:#6d28d9" target="_blank" href="${ocrUrl(id, 'orig', n)}&kind=${k}" title="همان فایلی که i2pdf داد"><i class="fas fa-file-arrow-down ml-1"></i>i2pdf ${k === 'docx' ? 'Word' : 'PDF'}</a>`).join('');
    function renderOcr(body) {
        const b = V.boot, en = b.ocr_engines || {}, order = b.ocr_order || [];
        const opt = (v, l, ok) => `<option value="${v}" ${ok === false ? 'disabled' : ''}>${l}${ok === false ? ' (خاموش/تنظیم نشده)' : ''}</option>`;
        body.innerHTML = `<div class="inq-card">
            <div class="flex flex-wrap items-end gap-3">
                <label class="inq-f flex-1 min-w-[220px]">۱) فایلِ PDF یا عکس (چند عکس هم می‌شود؛ عکس خودکار PDF می‌شود)<input id="ocr-f" type="file" accept="application/pdf,image/*" multiple class="inq-in" style="font-size:12px"></label>
                <label class="inq-f">۲) زبانِ تشخیص<select id="ocr-l" class="inq-in" style="font-size:12px"><option value="fas">فارسی (Persian)</option><option value="eng">انگلیسی (English)</option></select></label>
                <label class="inq-f">قالبِ خروجی<select id="ocr-fmt" class="inq-in" style="font-size:12px">${Object.entries(OCR_FMT).map(([k, l]) => `<option value="${k}" ${k === (b.ocr_format || 'txt') ? 'selected' : ''}>${l}</option>`).join('')}<option disabled>Searchable PDF (در i2pdf غیرفعال)</option></select></label>
                <label class="inq-f">موتور<select id="ocr-e" class="inq-in" style="font-size:12px">${opt('auto', 'خودکار — اولویتِ تنظیمات: ' + (order.map(e => OCR_ENG[e]).join(' ← ') || '—'))}${['i2pdf', 'ai', 'ocrspace', 'browser'].map(e => opt(e, OCR_ENG[e], !!en[e])).join('')}</select></label>
                <button id="ocr-go" class="inq-go" disabled><i class="fas fa-play ml-1"></i>شروع (Start)</button></div>
            <div id="ocr-pick" class="hidden mt-3 rounded-2xl border border-violet-100 bg-violet-50/50 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2"><b class="text-[12px] text-violet-900"><i class="fas fa-list-check ml-1"></i>کدام صفحه‌ها پردازش شوند؟ <span id="ocr-cnt" class="text-violet-600"></span></b>
                    <span class="flex gap-1.5"><button class="inq-fbtn" data-sel="all">همه‌ی صفحه‌ها</button><button class="inq-fbtn" data-sel="none">هیچ‌کدام</button></span></div>
                <div id="ocr-th" class="flex flex-wrap gap-2"></div></div>
            <p class="text-[10.5px] text-slate-400 mt-2 leading-6">هر صفحه جدا پردازش می‌شود و صفحه‌ها هم‌زمان جلو می‌روند. ${en.i2pdf ? 'موتورِ i2pdf آماده است.' : (b.is_admin ? 'برای موتورِ i2pdf، در «تنظیمات ← پردازش فایل هوشمند» درخواستِ پردازش را بچسبانید.' : '')}</p></div>
            <div id="ocr-out" class="mt-4"></div>`;
        const f = body.querySelector('#ocr-f'), go = body.querySelector('#ocr-go'), th = body.querySelector('#ocr-th'), pick = body.querySelector('#ocr-pick');
        const thumbs = () => {
            pick.classList.toggle('hidden', !OCR.pages.length);
            th.innerHTML = OCR.pages.map((p, i) => `<label class="relative cursor-pointer" title="${esc(p.src)}"><img src="${p.thumb}" style="height:120px;border-radius:10px;border:2.5px solid ${p.on ? '#7c3aed' : '#e2e8f0'};opacity:${p.on ? 1 : .4}">
                <input type="checkbox" data-pi="${i}" ${p.on ? 'checked' : ''} class="absolute top-1 right-1" style="accent-color:#7c3aed"><small class="absolute bottom-1 left-1 bg-white/90 rounded px-1 text-[10px] font-bold">صفحه‌ی ${fa(p.n)}</small></label>`).join('');
            th.querySelectorAll('[data-pi]').forEach(c => c.onchange = () => { OCR.pages[+c.dataset.pi].on = c.checked; thumbs(); });
            const k = OCR.pages.filter(p => p.on).length;
            body.querySelector('#ocr-cnt').textContent = `(${fa(k)} از ${fa(OCR.pages.length)} صفحه)`;
            go.disabled = OCR.busy || !k;
        };
        body.querySelectorAll('[data-sel]').forEach(x => x.onclick = () => { OCR.pages.forEach(p => p.on = x.dataset.sel === 'all'); thumbs(); });
        f.onchange = async () => {
            if (!f.files.length) return;
            OCR.busy = true; go.disabled = true; OCR.res = []; OCR.id = 0; OCR.name = f.files[0].name + (f.files.length > 1 ? ' (+' + (f.files.length - 1) + ')' : '');
            pick.classList.remove('hidden');
            th.innerHTML = '<span class="text-xs text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>جدا کردنِ صفحه‌ها…</span>';
            try { OCR.pages = (await SmartOCR.pagesFromFiles(f.files, n => { th.innerHTML = `<span class="text-xs text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>صفحه‌ی ${fa(n)}…</span>`; })).map(p => Object.assign(p, { on: true })); }
            catch (e) { OCR.pages = []; toast(e.message || 'فایل خوانده نشد.', 'error'); }
            OCR.busy = false;
            if (!OCR.pages.length) th.innerHTML = '<span class="text-xs text-rose-600">صفحه‌ای پیدا نشد (فقط PDF و عکس).</span>'; else thumbs();
            body.querySelector('#ocr-out').innerHTML = '';
        };
        go.onclick = async () => {
            let sel = OCR.pages.filter(p => p.on); if (!sel.length || OCR.busy) return;
            // سقفِ روزانه‌ی پردازشِ فایل (صفحه): پیش از شروع بررسی می‌شود و سرور هم همان را اعمال می‌کند
            const qt = await post({ action: 'ocr_quota' });
            if (qt && qt.ok && qt.limit) {
                if (!qt.left) { toast(`سقفِ روزانه‌ی پردازشِ فایلِ شما (${fa(qt.limit)} صفحه) پر شده است.`, 'error'); return; }
                if (sel.length > qt.left) { toast(`امروز ${fa(qt.left)} صفحه‌ی دیگر می‌توانید پردازش کنید؛ فقط ${fa(qt.left)} صفحه‌ی اول پردازش می‌شود.`, 'warning'); sel = sel.slice(0, qt.left); }
            }
            OCR.busy = true; go.disabled = true; OCR.id = 0; OCR.prog = {};
            const lang = body.querySelector('#ocr-l').value, fmt = body.querySelector('#ocr-fmt').value, eng = body.querySelector('#ocr-e').value;
            OCR.fmt = fmt; OCR.lang = lang;
            const out = body.querySelector('#ocr-out'), t0 = Date.now();
            out.innerHTML = `<div class="inq-card"><div class="flex flex-wrap items-center justify-between gap-2 mb-2"><b class="text-sm"><i class="fas fa-gear fa-spin ml-1 text-violet-600" data-spin></i><span data-h>در حالِ پردازش</span> — <span data-c>۰</span> از ${fa(sel.length)} صفحه</b>
                    <span class="inq-chip !bg-slate-800 text-white" dir="ltr"><span class="opacity-70 ml-1">Elapsed time</span><time data-t>۰۰:۰۰</time></span></div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">${sel.map(p => `<div class="rounded-xl border border-slate-100 bg-slate-50 p-2.5" data-pg="${p.n}">
                    <div class="flex items-center justify-between"><b class="text-[11.5px] text-slate-700">صفحه‌ی ${fa(p.n)}</b><span class="text-[10.5px] font-bold text-slate-400" data-st>در صف</span></div>
                    <ul class="mt-1 space-y-0.5 text-[10.5px] text-slate-500" data-tl></ul></div>`).join('')}</div></div>`;
            const tm = setInterval(() => { const e = out.querySelector('[data-t]'); if (e) e.textContent = mmss((Date.now() - t0) / 1000); }, 500);
            const card = n => out.querySelector(`[data-pg="${n}"]`);
            const paintTl = (p, steps, el) => {
                const c = card(p.n); if (!c) return;
                c.querySelector('[data-tl]').innerHTML = steps.map((s, i) => `<li class="flex justify-between gap-2"><span>${i === steps.length - 1 && !/done|fail/.test(s.step) ? '<i class="fas fa-circle-notch fa-spin ml-1 text-violet-500"></i>' : (s.step === 'fail' ? '<i class="fas fa-xmark ml-1 text-rose-500"></i>' : '<i class="fas fa-check ml-1 text-emerald-500"></i>')}${esc(s.label)}</span><span dir="ltr" class="text-slate-400">${mmss(s.at || 0)}</span></li>`).join('');
                c.querySelector('[data-st]').innerHTML = `<span dir="ltr">${mmss(el)}</span>`;
            };
            let done = 0;
            const res = await SmartOCR.run(sel, { lang, engine: qt && qt.ok && qt.limit ? (eng === 'browser' ? 'auto' : eng) : eng, format: fmt, order: b.ocr_order, conc: b.ocr_conc || 3, extra: { src: 'inq' },
                onStart: p => { const c = card(p.n); if (c) { c.style.borderColor = '#c4b5fd'; c.querySelector('[data-st]').textContent = 'شروع شد'; } },
                onProgress: paintTl,
                onPage: (p, r) => { done++; const c = card(p.n);
                    if (c) { c.style.borderColor = r.ok ? '#6ee7b7' : '#fda4af'; c.style.background = r.ok ? '#ecfdf5' : '#fff1f2';
                        c.querySelector('[data-st]').innerHTML = r.ok ? `<span class="text-emerald-600">Your result is ready.</span>` : '<span class="text-rose-600">ناموفق</span>';
                        if (!r.ok || !c.querySelector('[data-tl]').children.length) c.querySelector('[data-tl]').insertAdjacentHTML('beforeend', `<li class="${r.ok ? 'text-emerald-700' : 'text-rose-600'}">${esc(r.ok ? (r.engine_fa || r.engine || '') + (r.ms ? ' · ' + mmss(r.ms / 1000) : '') : (r.error || 'خوانده نشد.'))}</li>`); }
                    const k = out.querySelector('[data-c]'); if (k) k.textContent = fa(done); } });
            clearInterval(tm);
            OCR.res = res.map(r => ({ n: r.n, text: r.text_verbatim || r.text || '', engine: r.engine || '', engine_fa: r.engine_fa || '', ms: r.ms || 0, files: r.files || {}, format: r.format || '', error: r.ok ? (r.note || '') : (r.error || 'خوانده نشد.') }));
            OCR.ms = Date.now() - t0; OCR.busy = false; go.disabled = false;
            const sp = out.querySelector('[data-spin]'); if (sp) sp.className = 'fas fa-circle-check ml-1 text-emerald-600';
            const hh = out.querySelector('[data-h]'); if (hh) hh.textContent = 'Your result is ready.';
            const box = document.createElement('div'); box.className = 'mt-4'; out.appendChild(box);
            ocrResult(box);
            ocrSave(box, true);
        };
        if (OCR.pages.length) thumbs();
        if (OCR.res.length) { const box = document.createElement('div'); body.querySelector('#ocr-out').appendChild(box); ocrResult(box); }
    }
    function ocrResult(out) {
        OCR.out = out;
        out.innerHTML = `<div class="inq-card">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><b class="text-sm"><i class="fas fa-file-lines text-violet-600 ml-1"></i>Recognized text — ${esc(OCR.name)} · ${fa(OCR.res.length)} صفحه${OCR.ms ? ' در ' + mmss(OCR.ms / 1000) : ''}</b>
                <span class="flex flex-wrap gap-1.5"><button class="inq-fbtn" data-cp="all"><i class="fas fa-copy ml-1"></i>کپیِ همه (Copy)</button><button class="inq-fbtn" data-sv><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی ویرایش</button><span data-dl class="flex flex-wrap gap-1.5"></span></span></div>
            <p class="text-[10.5px] text-slate-400 mb-2">ویرایشِ متن روی دانلودهای TXT، Word و PDF هم اثر دارد (بعد از «ذخیره‌ی ویرایش»)؛ «فایلِ i2pdf» همان خروجیِ اصلیِ سایت است.</p>
            <div class="rounded-2xl border border-fuchsia-200 bg-gradient-to-l from-fuchsia-50 to-violet-50 p-3 mb-3">
                <div class="flex flex-wrap items-end gap-2"><b class="text-[12px] text-fuchsia-900 flex-1 min-w-[180px]"><i class="fas fa-brain ml-1"></i>پردازش با هوشِ مصنوعی — اطلاعاتِ لازم از این متن با پرامپتِ همان نوعِ مدرک</b>
                    <select data-xt class="inq-in !mt-0 w-auto" style="font-size:12px"><option value="auto">تشخیصِ خودکارِ نوع</option>${Object.entries(V.boot.ocr_types || {}).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select>
                    <button class="inq-go !py-2 !px-4 text-xs" data-xgo><i class="fas fa-wand-magic-sparkles ml-1"></i>پردازش</button></div>
                <div data-xout class="mt-2"></div></div>
            ${OCR.res.map((r, i) => `<div class="mb-3 rounded-2xl border border-slate-100 p-3"><div class="flex flex-wrap items-center justify-between gap-2 mb-1"><b class="text-[11.5px] text-slate-600">صفحه‌ی ${fa(r.n)}${r.engine_fa ? ` · <small class="text-slate-400">${esc(r.engine_fa)}${r.ms ? ' · ' + mmss(r.ms / 1000) : ''}</small>` : ''}</b>
                <span class="flex flex-wrap gap-1.5"><button class="inq-fbtn" data-cp="${i}"><i class="fas fa-copy ml-1"></i>Copy</button><span data-dlp="${r.n}" class="flex flex-wrap gap-1.5"></span></span></div>
                ${r.error ? `<p class="text-[10.5px] ${r.text ? 'text-amber-600' : 'text-rose-600'} mb-1">${esc(r.error)}</p>` : ''}
                <textarea class="inq-in" data-tx="${i}" dir="auto" style="min-height:200px;font-size:13px;font-weight:600;line-height:1.9;letter-spacing:0">${esc(r.text)}</textarea></div>`).join('')}</div>`;
        out.querySelectorAll('[data-tx]').forEach(t => t.oninput = () => { OCR.res[+t.dataset.tx].text = t.value; });
        out.querySelectorAll('[data-cp]').forEach(b => b.onclick = async () => {
            const v = b.dataset.cp === 'all' ? OCR.res.map(r => r.text).join('\n\n') : OCR.res[+b.dataset.cp].text;
            try { await navigator.clipboard.writeText(v); toast('کپی شد.', 'success'); } catch (e) {}
        });
        out.querySelector('[data-sv]').onclick = () => ocrSave(out, false);
        out.querySelector('[data-xgo]').onclick = () => ocrExtract(out);
        ocrLinks(out);
    }
    // پردازش (استخراج): متنِ همه‌ی صفحه‌ها ← پرامپتِ نوع ← جدولِ فیلدها (یا ردیف‌های نامه)
    async function ocrExtract(out) {
        const box = out.querySelector('[data-xout]'), b = out.querySelector('[data-xgo]'), t0 = Date.now();
        const text = OCR.res.map(r => r.text).join('\n\n').trim();
        if (!text) { toast('متنی برای پردازش نیست.', 'error'); return; }
        b.disabled = true;
        const tm = setInterval(() => { box.innerHTML = `<p class="text-[11px] text-fuchsia-700"><i class="fas fa-spinner fa-spin ml-1"></i>هوشِ مصنوعی در حالِ استخراج… ${mmss((Date.now() - t0) / 1000)}</p>`; }, 400);
        const r = await post({ action: 'ocr_extract', text, type: out.querySelector('[data-xt]').value });
        clearInterval(tm); b.disabled = false;
        if (!r.ok) { box.innerHTML = `<p class="text-[11px] text-rose-600">${esc(r.error)}</p>`; return; }
        const f = r.fields_verbatim || [], rows = r.rows_verbatim || [];
        const plain = f.map(x => x[0] + ': ' + x[1]).join('\n');
        const cols = rows.length ? Array.from(rows.reduce((a, x) => { Object.keys(x).forEach(k => a.add(k)); return a; }, new Set())) : [];
        box.innerHTML = `<div class="flex flex-wrap items-center justify-between gap-2 mb-1"><b class="text-[11.5px] text-slate-700">نوع: ${esc(r.type_fa)} · ${fa(f.length)} فیلد${rows.length ? ' · ' + fa(rows.length) + ' ردیف' : ''} · ${mmss((Date.now() - t0) / 1000)}</b>
                <button class="inq-fbtn" data-xcp><i class="fas fa-copy ml-1"></i>کپیِ همه</button></div>
            ${f.length ? `<table class="inq-tbl bg-white rounded-xl"><tbody>${f.map((x, i) => `<tr data-cpf="${i}" title="کلیک = کپیِ همین فیلد" style="cursor:pointer"><td class="text-slate-500 whitespace-nowrap" style="width:1%">${esc(x[0])}</td><td><i class="far fa-copy text-slate-300 ml-1"></i><b dir="auto">${esc(/[A-Za-z]/.test(x[1]) ? x[1] : fa(x[1]))}</b></td></tr>`).join('')}</tbody></table>` : ''}
            ${rows.length ? `<div class="overflow-auto mt-2"><table class="inq-tbl bg-white"><thead><tr><th>#</th>${cols.map(c => `<th dir="ltr">${esc(c)}</th>`).join('')}</tr></thead><tbody>${rows.map((x, i) => `<tr><td>${fa(i + 1)}</td>${cols.map(c => `<td dir="auto">${esc(x[c] || '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : ''}
            ${!f.length && !rows.length ? '<p class="text-[11px] text-slate-400">چیزی استخراج نشد.</p>' : ''}`;
        box.querySelector('[data-xcp]').onclick = async () => { try { await navigator.clipboard.writeText(rows.length ? JSON.stringify(rows, null, 2) : plain); toast('کپی شد.', 'success'); } catch (e) {} };
        box.querySelectorAll('[data-cpf]').forEach(tr => tr.onclick = async () => { const x = f[+tr.dataset.cpf]; try { await navigator.clipboard.writeText(String(x[1])); toast(x[0] + ' کپی شد.', 'success'); } catch (e) {} });
    }
    function ocrLinks(out) {
        if (!OCR.id) return;
        const a = out.querySelector('[data-dl]'); if (a) a.innerHTML = ocrDl(OCR.id, 0, OCR.res.length === 1 ? OCR.res[0].files : null);
        out.querySelectorAll('[data-dlp]').forEach(s => { const r = OCR.res.find(x => x.n === +s.dataset.dlp); if (OCR.res.length > 1) s.innerHTML = ocrDl(OCR.id, +s.dataset.dlp, r ? r.files : null); });
    }
    async function ocrSave(out, auto) {
        const r = await post({ action: 'ocr_save', id: OCR.id, name: OCR.name, lang: OCR.lang || 'fas',
                               pages: OCR.res.map(p => ({ n: p.n, text: p.text, engine: p.engine, ms: p.ms, files: p.files })) });
        if (!r.ok) { toast(r.error || 'ذخیره نشد.', 'error'); return; }
        OCR.id = r.id; ocrLinks(out);
        if (!auto) toast('ذخیره شد.', 'success');
    }
    function ocrDetail(it) {
        const pages = it.pages || [];
        const ext = p => (p && p.files) || null;
        const m = document.createElement('div'); m.className = 'inq-modal';
        m.innerHTML = `<div style="width:min(900px,100%)"><div class="flex flex-wrap items-center justify-between gap-2 p-4 border-b"><b class="text-sm"><i class="fas fa-file-lines ml-1 text-violet-600"></i>${esc(it.query || 'پردازش فایل')} · ${fa(pages.length)} صفحه · ${esc(fa(it.created_j || ''))}</b>
            <span class="flex flex-wrap gap-1.5">${ocrDl(it.id, 0, pages.length === 1 ? ext(pages[0]) : null)}<button class="inq-fbtn" data-x>بستن</button></span></div>
            <div class="p-4 overflow-y-auto">${pages.map((p, i) => `<div class="mb-3"><div class="flex flex-wrap items-center justify-between gap-2 mb-1"><b class="text-[11.5px] text-slate-600">صفحه‌ی ${fa(p.n)}</b>
                <span class="flex flex-wrap gap-1.5"><button class="inq-fbtn" data-cp="${i}"><i class="fas fa-copy ml-1"></i>Copy</button>${pages.length > 1 ? ocrDl(it.id, p.n, ext(p)) : ''}</span></div>
                <pre dir="auto" class="bg-slate-50 rounded-xl p-3 text-[12.5px] leading-7 whitespace-pre-wrap" style="font-family:inherit">${esc(p.text_verbatim || p.text || '—')}</pre></div>`).join('') || '<p class="text-xs text-slate-400">متنی ذخیره نشده.</p>'}</div></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
        m.querySelectorAll('[data-cp]').forEach(b => b.onclick = async () => { try { await navigator.clipboard.writeText(pages[+b.dataset.cp].text_verbatim || pages[+b.dataset.cp].text || ''); toast('کپی شد.', 'success'); } catch (e) {} });
        document.body.appendChild(m);
    }
    // تنظیماتِ «پردازش فایل هوشمند» (مدیر کل): روشن/خاموش و اولویتِ موتورها، قالبِ پیش‌فرض، و موتورِ i2pdf
    async function ocrSettings(box) {
        const d = await post({ action: 'ocr_cfg_get' });
        if (!d.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        const c = d.cfg, i2 = d.i2pdf;
        let order = c.order.slice();
        const paint = () => {
            box.querySelector('[data-ord]').innerHTML = order.map((e, i) => `<div class="flex items-center gap-2 rounded-xl border ${c.on[e] ? 'border-violet-200 bg-white' : 'border-slate-200 bg-slate-50 opacity-70'} px-2.5 py-2">
                <b class="w-6 h-6 rounded-lg bg-violet-600 text-white text-[11px] grid place-items-center">${fa(i + 1)}</b>
                <label class="flex items-center gap-1.5 flex-1 text-[12px] font-bold text-slate-700"><input type="checkbox" data-on="${e}" ${c.on[e] ? 'checked' : ''} style="accent-color:#7c3aed">${esc(d.engines[e])}</label>
                <span class="text-[10px] font-bold ${d.ready[e] ? 'text-emerald-600' : 'text-amber-600'}">${d.ready[e] ? '● آماده' : (c.on[e] ? '● تنظیم نشده' : '● خاموش')}</span>
                <button class="inq-fbtn !px-2" data-up="${i}" ${i === 0 ? 'disabled' : ''}><i class="fas fa-arrow-up"></i></button><button class="inq-fbtn !px-2" data-dn="${i}" ${i === order.length - 1 ? 'disabled' : ''}><i class="fas fa-arrow-down"></i></button></div>`).join('');
            box.querySelectorAll('[data-up]').forEach(b => b.onclick = () => { const i = +b.dataset.up; [order[i - 1], order[i]] = [order[i], order[i - 1]]; paint(); });
            box.querySelectorAll('[data-dn]').forEach(b => b.onclick = () => { const i = +b.dataset.dn; [order[i + 1], order[i]] = [order[i], order[i + 1]]; paint(); });
            box.querySelectorAll('[data-on]').forEach(b => b.onchange = () => { c.on[b.dataset.on] = b.checked; paint(); });
        };
        box.innerHTML = `<p class="font-black text-sm text-slate-800"><i class="fas fa-file-lines ml-1 text-violet-600"></i>پردازش فایل هوشمند — موتورها و اولویت</p>
            <p class="text-[10.5px] text-slate-400 leading-6">هر صفحه اول با موتورِ ردیفِ ۱ پردازش می‌شود؛ اگر جواب نداد، با بعدی. موتورِ خاموش استفاده نمی‌شود.</p>
            <div class="space-y-1.5" data-ord></div>
            <div class="grid grid-cols-2 gap-3">
                <label class="inq-f">قالبِ خروجیِ پیش‌فرض<select class="inq-in" style="font-size:12px" data-fmt>${Object.entries(OCR_FMT).map(([k, l]) => `<option value="${k}" ${c.format === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                <label class="inq-f">صفحه‌های هم‌زمان<input type="number" min="1" max="6" class="inq-in" style="font-size:12px" data-conc value="${c.conc}"></label></div>
            <label class="flex items-start gap-2 text-[11.5px] font-bold text-slate-700 cursor-pointer"><input type="checkbox" data-auto ${c.auto ? 'checked' : ''} style="accent-color:#7c3aed" class="mt-0.5">
                <span>بعد از OCR، خودکار با پرامپتِ هر نوع مدرک اطلاعات استخراج و جای‌گذاری شود<small class="block text-[10.5px] text-slate-400 font-normal leading-5">برای مدارکِ اسکن/عکس در ثبتِ دستیِ درخواست و مدارکِ پرسنلی (معرفی‌نامه/بیمه‌نامه)؛ مدارکِ شرکت‌ها طبقِ «خواندنِ خودکار» در تعریفِ هر شرکت. ${d.ai_ready ? '' : '<b class="text-rose-500">سرویسِ هوشِ مصنوعی (کلیدهای API) تنظیم نشده.</b>'}</small></span></label>
            <details class="rounded-2xl border border-fuchsia-100 bg-fuchsia-50/40 p-3"><summary class="text-[12px] font-black text-fuchsia-900 cursor-pointer"><i class="fas fa-message ml-1"></i>پرامپت‌های پردازش (هر نوع مدرک چه اطلاعاتی لازم دارد)</summary>
                <p class="text-[10.5px] text-slate-500 leading-6 mt-2">هر نوع مدرک پرامپتِ خودش را دارد؛ کلیدهای انگلیسیِ داخلِ پرامپت (مثلِ vin، plate_two، owner_name) نامِ فیلدهای سایت‌اند و نباید عوض شوند. خالی یا «پیش‌فرض» = پرامپتِ پیش‌فرض.</p>
                ${(d.prompts || []).map(p => `<div class="mt-2"><div class="flex items-center justify-between"><b class="text-[11.5px] text-slate-700">${esc(p.label)} <code class="text-[10px] text-slate-400">${p.type}</code>${p.custom_verbatim ? ' <span class="text-[10px] text-fuchsia-600">(ویرایش‌شده)</span>' : ''}</b>
                    <button class="inq-fbtn !py-0.5" data-pdef="${p.type}">پیش‌فرض</button></div>
                    <textarea class="inq-in" rows="5" dir="rtl" data-prompt="${p.type}" style="font-size:11px;font-weight:500;letter-spacing:0;line-height:1.9">${esc(p.custom_verbatim || p.default_verbatim)}</textarea></div>`).join('')}</details>
            <details class="rounded-2xl border border-sky-100 bg-sky-50/40 p-3" data-cpbox><summary class="text-[12px] font-black text-sky-900 cursor-pointer"><i class="fas fa-building ml-1"></i>پرامپتِ اختصاصیِ هر شرکت ${(d.companies || []).some(x => x.custom) ? `<span class="text-[10px] text-sky-600">(${fa((d.companies || []).filter(x => x.custom).length)} شرکت تنظیم‌شده)</span>` : ''}</summary>
                <p class="text-[10.5px] text-slate-500 leading-6 mt-2">پرامپت‌های بالا برای همه‌ی شرکت‌ها اعمال می‌شود؛ اگر برای یک شرکت پرامپتِ یک مدرک را اینجا عوض کنید، فقط برای همان شرکت (مدارکِ درخواست‌ها، نامه‌ها و پنلِ صدور) به جای پرامپتِ کلی استفاده می‌شود.</p>
                <select class="inq-in mt-2" style="font-size:12px" data-cpco><option value="">— انتخابِ شرکت —</option>${(d.companies || []).map(x => `<option value="${x.id}">${esc(x.name)}${x.custom ? ` ★ (${fa(x.custom)} پرامپتِ اختصاصی)` : ''}</option>`).join('')}</select>
                <div data-cplist class="mt-2"></div></details>
            <details class="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-3" data-olbox><summary class="text-[12px] font-black text-emerald-900 cursor-pointer"><i class="fas fa-server ml-1"></i>سیستمِ داخلی (Ollama روی کامپیوترِ دفتر) <span data-olsum class="text-[10.5px] font-bold"></span></summary><div data-ol class="mt-2"><p class="text-[11px] text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i></p></div></details>
            <details class="rounded-2xl border border-violet-100 bg-violet-50/40 p-3" open><summary class="text-[12px] font-black text-violet-900 cursor-pointer">موتورِ i2pdf.com ${d.ready.i2pdf ? '<span class="text-emerald-600">● آماده</span>' : '<span class="text-amber-600">● cURL روی هاست نیست</span>'}</summary>
                <p class="text-[10.5px] text-slate-600 leading-6 mt-2">چیزی برای چسباندن لازم نیست: هر صفحه مثلِ خودِ سایت (باز کردنِ صفحه، «I'm not a robot» با حلِ خودکار، Start و دریافتِ txt، Word و PDF با هم) از روی هاست پردازش می‌شود. برای اطمینان «آزمایشِ اتصال» را بزنید؛ اگر خطا داد، گزارشش را بفرستید.</p>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-2">
                    <label class="inq-f">کدِ زبانِ فارسی<input class="inq-in" dir="ltr" style="font-size:11px" data-i2="lang_fa" value="${esc(i2.lang_fa)}"></label>
                    <label class="inq-f">کدِ زبانِ انگلیسی<input class="inq-in" dir="ltr" style="font-size:11px" data-i2="lang_en" value="${esc(i2.lang_en)}"></label>
                    <label class="inq-f">مهلتِ هر صفحه (ثانیه)<input class="inq-in" dir="ltr" style="font-size:11px" data-i2="timeout" value="${esc(i2.timeout)}"></label>
                    <label class="inq-f">موتورِ i2pdf (engine)<input class="inq-in" dir="ltr" style="font-size:11px" data-i2="engine" value="${esc(i2.engine)}"></label>
                    <label class="inq-f">چیدمان (layout)<input class="inq-in" dir="ltr" style="font-size:11px" data-i2="layout" value="${esc(i2.layout)}"></label>
                    <label class="inq-f">آدرسِ صفحه<input class="inq-in" dir="ltr" style="font-size:11px" data-i2="page_url" value="${esc(i2.page_url)}"></label></div>
                <button class="inq-fbtn mt-2" data-i2test><i class="fas fa-plug ml-1"></i>آزمایشِ اتصال به i2pdf</button>
                <div data-i2res class="mt-2"></div></details>
            <button class="inq-go w-full" data-osave><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تنظیماتِ پردازش فایل</button>`;
        paint();
        ollamaSettings(box.querySelector('[data-ol]'), box.querySelector('[data-olsum]'));
        box.querySelectorAll('[data-pdef]').forEach(b => b.onclick = () => { const p = (d.prompts || []).find(x => x.type === b.dataset.pdef); if (p) box.querySelector(`[data-prompt="${p.type}"]`).value = p.default_verbatim; });
        box.querySelector('[data-cpco]').onchange = async e => {
            const cid = +e.target.value, lb = box.querySelector('[data-cplist]');
            if (!cid) { lb.innerHTML = ''; return; }
            lb.innerHTML = '<p class="text-[11px] text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i></p>';
            const r = await post({ action: 'ocr_prompts_company', company_id: cid });
            if (!r.ok) { lb.innerHTML = `<p class="text-[11px] text-rose-600">${esc(r.error)}</p>`; return; }
            lb.innerHTML = r.prompts.map(p => `<div class="mt-2"><div class="flex items-center justify-between"><b class="text-[11.5px] text-slate-700">${esc(p.label)} <code class="text-[10px] text-slate-400">${p.type}</code> <span class="text-[10px] ${p.custom_verbatim ? 'text-sky-600' : 'text-slate-400'}" data-cpst="${p.type}">${p.custom_verbatim ? '(اختصاصیِ این شرکت)' : '(همان پرامپتِ کلی)'}</span></b>
                    <button class="inq-fbtn !py-0.5" data-cpdef="${p.type}">برگرداندن به پرامپتِ کلی</button></div>
                    <textarea class="inq-in" rows="5" dir="rtl" data-cprompt="${p.type}" style="font-size:11px;font-weight:500;letter-spacing:0;line-height:1.9">${esc(p.custom_verbatim || p.base_verbatim)}</textarea></div>`).join('')
                + '<button class="inq-go w-full mt-3" data-cpsave><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی پرامپت‌های این شرکت</button>';
            lb.querySelectorAll('[data-cpdef]').forEach(b => b.onclick = () => { const p = r.prompts.find(x => x.type === b.dataset.cpdef); if (p) lb.querySelector(`[data-cprompt="${p.type}"]`).value = p.base_verbatim; });
            lb.querySelector('[data-cpsave]').onclick = async () => {
                const prompts = {}; lb.querySelectorAll('[data-cprompt]').forEach(x => prompts[x.dataset.cprompt] = x.value);
                const s = await post({ action: 'ocr_cfg_save', cfg: { company_prompts: { id: cid, prompts } } });
                if (!s.ok) return toast(s.error, 'error');
                toast('پرامپت‌های این شرکت ذخیره شد.', 'success');
                const co = (s.companies || []).find(x => x.id === cid), op = e.target.selectedOptions[0];
                if (co && op) op.textContent = co.name + (co.custom ? ` ★ (${fa(co.custom)} پرامپتِ اختصاصی)` : '');
                e.target.onchange({ target: e.target });
            };
        };
        box.querySelector('[data-i2test]').onclick = async () => {
            const rb = box.querySelector('[data-i2res]'), t0 = Date.now();
            const tm = setInterval(() => { rb.innerHTML = `<p class="text-[11px] text-violet-700"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ آزمایش (یک صفحه‌ی نمونه)… ${mmss((Date.now() - t0) / 1000)}</p>`; }, 500);
            const r = await post({ action: 'ocr_i2_test' });
            clearInterval(tm);
            if (!r.ok) { rb.innerHTML = `<p class="text-[11px] text-rose-600">${esc(r.error)}</p>`; return; }
            const x = r.result, rep = JSON.stringify({ result: x, steps: r.steps, debug: r.debug }, null, 2);
            rb.innerHTML = `<div class="rounded-xl border ${x.ok ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'} p-2.5 text-[11px]">
                <b class="${x.ok ? 'text-emerald-700' : 'text-rose-700'}">${x.ok ? '✅ کار می‌کند — ' + mmss((x.ms || 0) / 1000) : '❌ ' + esc(x.error)}</b>
                ${x.text ? `<pre dir="auto" class="mt-1 whitespace-pre-wrap text-slate-700" style="font-family:inherit">${esc(x.text)}</pre>` : ''}
                <ul class="mt-1 text-slate-500">${(r.steps || []).map(s => `<li>• ${esc(s)}</li>`).join('')}</ul>
                <details class="mt-1"><summary class="cursor-pointer text-slate-500">گزارشِ فنی (برای فرستادن)</summary><textarea readonly dir="ltr" class="inq-in" rows="8" style="font-size:10px;font-weight:500">${esc(rep)}</textarea>
                <button class="inq-fbtn mt-1" data-cprep><i class="fas fa-copy ml-1"></i>کپیِ گزارش</button></details></div>`;
            rb.querySelector('[data-cprep]').onclick = async () => { try { await navigator.clipboard.writeText(rep); toast('کپی شد.', 'success'); } catch (e) {} };
        };
        box.querySelector('[data-osave]').onclick = async () => {
            const olb = box.querySelector('[data-ol]');
            if (olb && olb.__olSave) { const ro = await olb.__olSave(); if (!ro.ok) return; }
            const i2v = {}; box.querySelectorAll('[data-i2]').forEach(x => i2v[x.dataset.i2] = x.value);
            const prompts = {}; box.querySelectorAll('[data-prompt]').forEach(x => prompts[x.dataset.prompt] = x.value);
            const r = await post({ action: 'ocr_cfg_save', cfg: { order, on: c.on, format: box.querySelector('[data-fmt]').value, conc: box.querySelector('[data-conc]').value, i2pdf: i2v,
                                                              auto: box.querySelector('[data-auto]').checked ? 1 : 0, prompts } });
            if (!r.ok) return toast(r.error, 'error');
            toast('ذخیره شد.', 'success');
            const bt = await post({ action: 'boot' }); if (bt.ok) V.boot = bt;
            ocrSettings(box);
        };
    }

    // «سیستمِ داخلی (Ollama)»: چند سیستم (کامپیوتر)، هر کدام با کلید و برنامه‌ی ویندوزیِ خودش؛ برای هر کار فهرستِ اولویت‌دارِ [سیستم، مدل] (api/_ollama.php)
    //   تیک‌ها همان لحظه ذخیره می‌شوند؛ دکمه‌ی «ذخیره»ی همین بخش و دکمه‌ی اصلیِ پایینِ صفحه هم همه را ذخیره می‌کنند (el.__olSave)
    async function ollamaSettings(el, sum) {
        const d = await post({ action: 'ocr_ollama_get' });
        if (!d.ok) { el.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        const c = d.cfg, st = d.stats, tasks = d.tasks || {};
        const nodes = (d.nodes || []).map(n => Object.assign({}, n));
        const routes = {}; Object.keys(tasks).forEach(t => routes[t] = ((c.routes || {})[t] || []).map(r => ({ node: r.node || '', model: r.model || '' })));
        const onl = nodes.filter(n => n.enabled && n.online);
        sum.innerHTML = !c.enabled ? '<span class="text-rose-500">● خاموش</span>' : (!nodes.length ? '<span class="text-amber-600">● هیچ سیستمی اضافه نشده</span>'
            : (onl.length ? `<span class="text-emerald-600">● ${fa(onl.length)} از ${fa(nodes.length)} سیستم وصل</span>` : '<span class="text-amber-600">● هیچ سیستمی وصل نیست</span>'));
        const lc = s => String(s || '').trim().toLowerCase();
        const hasM = (n, m) => { m = lc(m); return (n.models || []).some(x => { x = lc(x); return x === m || x === m + ':latest' || x + ':latest' === m; }); };
        const allModels = () => [...new Set(nodes.filter(n => n.enabled).flatMap(n => n.models || []))];
        const chk = (k, l, h) => `<label class="flex items-start gap-2 text-[11.5px] font-bold text-slate-700 cursor-pointer"><input type="checkbox" data-olc="${k}" ${c[k] ? 'checked' : ''} class="mt-0.5" style="accent-color:#059669"><span>${l}${h ? `<small class="block text-[10.5px] text-slate-400 font-normal leading-5">${h}</small>` : ''}</span></label>`;
        const chip = m => `<code class="bg-slate-100 rounded px-1 mx-0.5" dir="ltr">${esc(m)}</code>`;
        const nodeCard = n => `<div class="rounded-xl border ${!n.enabled ? 'border-slate-200 bg-slate-50' : (n.online ? 'border-emerald-200 bg-white' : 'border-amber-200 bg-amber-50')} p-2.5 text-[11px] leading-6" data-node="${n.id}">
                <div class="flex flex-wrap items-center gap-2">
                    <input class="inq-in !w-44" style="font-size:12px" data-nname value="${esc(n.name)}" placeholder="نامِ سیستم">
                    <label class="flex items-center gap-1 font-bold cursor-pointer"><input type="checkbox" data-nen ${n.enabled ? 'checked' : ''} style="accent-color:#059669">روشن</label>
                    <b>${!n.enabled ? '<span class="text-slate-400">⏸ خاموش</span>' : (n.online ? '<span class="text-emerald-700">✅ وصل</span>' : (n.reached && n.blocked ? '<span class="text-rose-600">⛔ برنامه وصل شد ولی ' + (n.blocked === 'disabled' ? 'تیکِ «سیستمِ داخلی روشن باشد» خاموش است' : 'این سیستم خاموش است') + '</span>'
                        : (n.at ? '<span class="text-amber-700">⚠️ وصل نیست — آخرین اتصال ' + esc(fa(n.ago_fa)) + '</span>' : '<span class="text-amber-700">⚠️ برنامه‌ی ویندوزی هنوز وصل نشده</span>')))}</b>
                    ${n.host ? `<span class="text-slate-500">کامپیوتر: <span dir="ltr">${esc(n.host)}</span></span>` : ''}
                    ${n.at ? `<span class="text-slate-500">Ollama: ${n.ollama ? '<span class="text-emerald-700">در حالِ اجرا</span>' : '<span class="text-rose-600">اجرا نیست</span>'}</span>` : ''}
                    ${n.busy ? `<span class="text-indigo-600"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ انجامِ کار</span>` : ''}
                    <span class="text-slate-400">امروز ${fa((st.by_node || {})[n.id] || 0)} کار</span></div>
                ${(n.models || []).length ? `<div>مدل‌های نصب‌شده: ${n.models.map(chip).join('')}</div>` : (n.at ? '<div class="text-rose-600">هیچ مدلی روی این سیستم گزارش نشده (در cmd بزنید: <code dir="ltr">ollama pull qwen2.5vl:7b</code>)</div>' : '')}
                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                    <a class="inq-fbtn" href="${API}?action=ocr_ollama_zip&node=${n.id}"><i class="fas fa-download ml-1"></i>دانلودِ برنامه‌ی ویندوزِ همین سیستم</a>
                    <select class="inq-in !w-auto !py-1" style="font-size:11px" dir="ltr" data-ntm>${(n.models || []).map(m => `<option>${esc(m)}</option>`).join('') || '<option value="">(مدلی گزارش نشده)</option>'}</select>
                    <button class="inq-fbtn" data-ntest><i class="fas fa-plug ml-1"></i>آزمایش</button>
                    <button class="inq-fbtn" data-nkey title="کلیدِ قبلیِ همین سیستم باطل می‌شود"><i class="fas fa-key ml-1"></i>کلیدِ تازه</button>
                    <button class="inq-fbtn" data-ndel style="background:#fee2e2;color:#be123c"><i class="fas fa-trash ml-1"></i>حذف</button>
                    <span class="text-slate-400">کلید: <code dir="ltr">${esc(n.token_mask)}</code></span></div>
                <div data-nres></div></div>`;
        const routeRow = (t, r, i) => {
            const opts = (r.node ? (nodes.find(n => n.id === r.node) || {}).models || [] : allModels());
            const ok = !r.model || nodes.some(n => n.enabled && (!r.node || n.id === r.node) && hasM(n, r.model));
            return `<div class="flex flex-wrap items-center gap-1.5 mt-1" data-rrow="${t}:${i}">
                <span class="w-5 text-center font-black text-emerald-700">${fa(i + 1)}</span>
                <select class="inq-in !w-auto !py-1" style="font-size:11px" data-rnode>
                    <option value="">هر سیستمی که این مدل را دارد</option>${nodes.map(n => `<option value="${n.id}" ${n.id === r.node ? 'selected' : ''}>${esc(n.name)}${n.enabled ? '' : ' (خاموش)'}</option>`).join('')}</select>
                <input class="inq-in !w-48 !py-1" style="font-size:11.5px" dir="ltr" list="ol-dl-${t}-${i}" data-rmodel value="${esc(r.model)}" placeholder="qwen2.5vl:7b">
                <datalist id="ol-dl-${t}-${i}">${opts.map(m => `<option value="${esc(m)}">`).join('')}</datalist>
                <button class="inq-fbtn !py-0.5 !px-2" data-rup ${i ? '' : 'disabled'} title="بالاتر"><i class="fas fa-arrow-up"></i></button>
                <button class="inq-fbtn !py-0.5 !px-2" data-rdown ${i < routes[t].length - 1 ? '' : 'disabled'} title="پایین‌تر"><i class="fas fa-arrow-down"></i></button>
                <button class="inq-fbtn !py-0.5 !px-2" data-rdel title="حذف" style="color:#be123c"><i class="fas fa-xmark"></i></button>
                ${ok ? '' : `<span class="text-[10.5px] text-amber-700">⚠️ این مدل روی ${r.node ? 'این سیستم' : 'هیچ سیستمی'} گزارش نشده (نام باید دقیقاً مثلِ «ollama list» باشد)</span>`}</div>`;
        };
        const routesHtml = () => Object.keys(tasks).map(t => `<div class="rounded-xl border border-slate-200 bg-white p-2.5" data-rtask="${t}">
                <b class="text-[11.5px] text-slate-700">${esc(tasks[t])}</b>
                ${routes[t].length ? routes[t].map((r, i) => routeRow(t, r, i)).join('') : '<p class="text-[10.5px] text-rose-600 mt-1">هنوز مدلی تعیین نشده؛ این کار با سیستمِ داخلی انجام نمی‌شود.</p>'}
                <button class="inq-fbtn mt-1.5 !py-0.5" data-radd="${t}"><i class="fas fa-plus ml-1"></i>افزودنِ اولویت</button></div>`).join('');
        const banner = !c.enabled ? '<div class="rounded-xl border border-rose-200 bg-rose-50 p-2.5 mt-2 text-[11.5px] font-bold text-rose-700">❌ سیستمِ داخلی خاموش است — تیکِ «سیستمِ داخلی روشن باشد» را بزنید (همان لحظه ذخیره می‌شود).</div>'
            : (!nodes.length ? '<div class="rounded-xl border border-amber-200 bg-amber-50 p-2.5 mt-2 text-[11.5px] font-bold text-amber-800">⚠️ هنوز هیچ سیستمی اضافه نشده — «افزودنِ سیستم» را بزنید، برنامه‌ی ویندوزِ آن را دانلود و روی همان کامپیوتر نصب کنید.</div>' : '');
        const rj = d.reject && d.reject.code === 'bad_token' ? `<div class="rounded-xl border border-rose-200 bg-rose-50 p-2.5 mt-2 text-[11.5px] text-rose-700 leading-6"><b>⛔ ${esc(fa(d.reject.ago_fa))} یک برنامه‌ی ویندوزی${d.reject.host ? ' (کامپیوترِ <span dir="ltr">' + esc(d.reject.host) + '</span>)' : ''} با کلیدِ نامعتبر وصل شد و رد شد.</b>
            فایلِ نصبیِ آن کامپیوتر قدیمی است (کلیدِ تازه ساخته شده یا سیستمش حذف شده): از کارتِ همان سیستم «دانلودِ برنامه‌ی ویندوز» را بزنید، پوشه را جایگزین کنید و install.bat را دوباره اجرا کنید.</div>` : '';
        el.innerHTML = `<p class="text-[10.5px] text-slate-600 leading-6">مدل‌ها روی کامپیوترهای خودتان اجرا می‌شوند و مدرک به هیچ سرویسِ بیرونی نمی‌رود. سایت مستقیم به آن کامپیوترها وصل نمی‌شود؛ «برنامه‌ی ویندوزی»ِ هر سیستم هر چند ثانیه از سایت کار می‌گیرد، روی Ollamaِ همان کامپیوتر اجرا می‌کند و جواب را برمی‌گرداند — پورت و IPِ ثابت لازم نیست. هر سیستم کلید و فایلِ نصبِ خودش را دارد.</p>
            ${banner}${rj}
            <div class="mt-2">${chk('enabled', 'سیستمِ داخلی روشن باشد', '')}</div>
            <div class="flex items-center justify-between mt-3"><b class="text-[12px] text-emerald-900"><i class="fas fa-desktop ml-1"></i>سیستم‌ها (کامپیوترها)</b>
                <button class="inq-fbtn" data-nadd><i class="fas fa-plus ml-1"></i>افزودنِ سیستم</button></div>
            <div class="space-y-2 mt-1.5" data-nodes>${nodes.map(nodeCard).join('') || '<p class="text-[11px] text-slate-400">سیستمی نیست.</p>'}</div>
            <div class="text-[10.5px] text-slate-500 mt-1">صف: ${fa(st.queued)} منتظر · ${fa(st.running)} در حالِ اجرا · امروز ${fa(st.done_today)} انجام‌شده${st.fail_today ? ' · ' + fa(st.fail_today) + ' خطا' : ''}</div>
            <b class="block text-[12px] text-emerald-900 mt-3"><i class="fas fa-list-ol ml-1"></i>اولویتِ هر کار: کدام سیستم با کدام مدل</b>
            <p class="text-[10.5px] text-slate-500 leading-5">هر کار اول به ردیفِ ۱ می‌رود؛ اگر آن سیستم وصل نبود (یا با «پخشِ کار» مشغول بود)، ردیفِ بعدی. «هر سیستمی که این مدل را دارد» = به ترتیبِ فهرستِ سیستم‌ها.</p>
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-2 mt-1.5" data-routes>${routesHtml()}</div>
            <div class="space-y-1.5 mt-3">
                ${chk('use_ocr', 'خواندنِ متن (OCR): اول i2OCR، اگر نشد سیستمِ داخلی', 'ترتیبِ موتورها را بالای همین صفحه می‌توانید عوض کنید.')}
                ${chk('use_extract', 'استخراجِ فیلدها با سیستمِ داخلی', 'اول همیشه «قاعده» (لحظه‌ای)؛ فقط فیلدهایی که پیدا نشدند یا معتبر نبودند به این مدل می‌روند.')}
                ${chk('use_vision', 'بررسیِ کامل با عکس با سیستمِ داخلی', 'دکمه‌ی «با عکس» کنارِ هر مدرک و «بررسی مدارک».')}
                ${chk('auto_vision', 'بررسی با عکس خودکار باشد وقتی فیلدِ لازمی پیدا نشد', 'خاموش = فقط با دکمه‌ی دستیِ «با عکس».')}
                ${chk('spread', 'پخشِ کار: اگر سیستمِ اولویتِ بالاتر مشغول بود، سیستمِ بعدی انجام دهد', 'خاموش = کار منتظرِ همان سیستمِ اول می‌ماند (تا وقتی وصل است).')}
                ${chk('keep_loaded', 'مدل‌ها همیشه در حافظه‌ی کامپیوتر بمانند (اولین جواب بدونِ انتظارِ بارگذاری)', 'برنامه‌ی ویندوزی (نسخه‌ی ۱.۲ به بعد) مدل‌های اولویت‌ها و «مدلِ هوشمندِ» هوشیکس را از قبل بارگذاری می‌کند؛ رمِ بیشتری می‌گیرد.')}
                ${chk('fallback', 'اگر هیچ سیستمی وصل نبود، از سرویسِ آنلاین (GapGPT) استفاده شود', 'خاموش (پیشنهادی) = مدارک منتظر می‌مانند تا یکی از کامپیوترها روشن شود؛ هیچ داده‌ای بیرون نمی‌رود.')}</div>
            <div class="grid grid-cols-2 gap-3 mt-2">
                <label class="inq-f">حداکثر انتظار در هر درخواست (ثانیه)<input type="number" min="10" max="240" class="inq-in" style="font-size:12px" data-oln="wait" value="${c.wait}"></label>
                <label class="inq-f">اندازه‌ی حافظه‌ی متن (num_ctx)<input type="number" min="2048" max="32768" step="1024" class="inq-in" style="font-size:12px" data-oln="num_ctx" value="${c.num_ctx}"></label></div>
            <p class="text-[10.5px] text-slate-500 mt-1 leading-5">اگر جواب در این مدت نرسید، کار در صف می‌ماند و وقتی رسید خودکار روی همان مدرک می‌نشیند.</p>
            <button class="inq-go mt-3" data-olsave><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تنظیماتِ سیستمِ داخلی</button>`;
        const vals = extra => {
            const cfg = Object.assign({}, extra || {});
            el.querySelectorAll('[data-olc]').forEach(x => cfg[x.dataset.olc] = x.checked ? 1 : 0);
            el.querySelectorAll('[data-oln]').forEach(x => cfg[x.dataset.oln] = x.value);
            cfg.nodes = nodes.map(n => ({ id: n.id, name: n.name, enabled: n.enabled ? 1 : 0 }));
            cfg.routes = routes;
            return cfg;
        };
        const save = async (extra, msg) => {
            const r = await post({ action: 'ocr_ollama_save', cfg: vals(extra) });
            if (!r.ok) toast(r.error || 'ذخیره نشد.', 'error'); else if (msg) toast(msg, 'success');
            return r;
        };
        el.__olSave = () => save();
        const reload = () => ollamaSettings(el, sum);
        // تیک‌ها همان لحظه ذخیره می‌شوند
        el.querySelectorAll('[data-olc]').forEach(x => x.onchange = async () => { const r = await save(null, 'ذخیره شد.'); if (r.ok && x.dataset.olc === 'enabled') reload(); });
        el.querySelector('[data-olsave]').onclick = async () => { const r = await save(null, 'ذخیره شد.'); if (r.ok) reload(); };
        el.querySelector('[data-nadd]').onclick = async () => {
            const name = window.uiPrompt ? await uiPrompt('نامِ این سیستم', 'سیستمِ ' + (nodes.length + 1), { hint: 'مثلاً «کامپیوترِ اتاقِ صدور»', ok: 'افزودن' }) : prompt('نامِ این سیستم (مثلاً «کامپیوترِ اتاقِ صدور»):', 'سیستمِ ' + (nodes.length + 1));
            if (name === null) return;
            const r = await save({ add_node: name.trim() || ('سیستمِ ' + (nodes.length + 1)) }, 'سیستم اضافه شد؛ برنامه‌ی ویندوزِ همین سیستم را دانلود و روی آن کامپیوتر نصب کنید.');
            if (r.ok) reload();
        };
        el.querySelectorAll('[data-node]').forEach(card => {
            const n = nodes.find(x => x.id === card.dataset.node);
            card.querySelector('[data-nname]').oninput = e => { n.name = e.target.value; };
            card.querySelector('[data-nen]').onchange = async e => { n.enabled = e.target.checked; const r = await save(null, 'ذخیره شد.'); if (r.ok) reload(); };
            card.querySelector('[data-ndel]').onclick = async () => {
                if (!(await ask('حذفِ سیستم', `سیستمِ «${n.name}» حذف شود؟ برنامه‌ی ویندوزیِ آن دیگر کار نمی‌گیرد و از اولویت‌ها هم برداشته می‌شود.`, { danger: true, ok: 'حذف' }))) return;
                nodes.splice(nodes.indexOf(n), 1);
                Object.keys(routes).forEach(t => routes[t] = routes[t].filter(r => r.node !== n.id));
                const r = await save(null, 'حذف شد.'); reload();
            };
            card.querySelector('[data-nkey]').onclick = async () => {
                if (!(await ask('کلیدِ تازه', `کلیدِ تازه برای «${n.name}» ساخته شود؟ برنامه‌ی ویندوزیِ نصب‌شده روی آن تا دانلود و نصبِ دوباره کار نمی‌کند.`, { ok: 'بساز' }))) return;
                const r = await save({ new_token: n.id }, 'کلیدِ تازه ساخته شد؛ برنامه‌ی همین سیستم را دوباره دانلود و نصب کنید.'); if (r.ok) reload();
            };
            card.querySelector('[data-ntest]').onclick = async () => {
                const rb = card.querySelector('[data-nres]'), t0 = Date.now(), model = card.querySelector('[data-ntm]').value;
                if (!model) { rb.innerHTML = '<p class="text-[11px] text-rose-600">این سیستم هنوز مدلی گزارش نکرده (برنامه‌ی ویندوزی وصل نیست یا مدلی نصب نیست).</p>'; return; }
                const tm = setInterval(() => { rb.innerHTML = `<p class="text-[11px] text-emerald-700"><i class="fas fa-spinner fa-spin ml-1"></i>منتظرِ جوابِ «${esc(n.name)}»… ${mmss((Date.now() - t0) / 1000)}</p>`; }, 500);
                await save();
                const r = await post({ action: 'ocr_ollama_test', node: n.id, model });
                clearInterval(tm);
                if (!r.ok) { rb.innerHTML = `<p class="text-[11px] text-rose-600">${esc(r.error)}</p>`; return; }
                const x = r.result;
                rb.innerHTML = `<div class="rounded-xl border ${x.ok ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'} p-2 mt-1 text-[11px]"><b>${x.ok ? '✅ جواب رسید — ' + mmss(x.ms / 1000) + ' (' + esc(x.model) + ')' : (x.pending ? '⏳ ' : '❌ ') + esc(x.error)}</b>${x.text ? `<p class="mt-1" dir="auto">${esc(x.text)}</p>` : ''}</div>`;
            };
        });
        const bindRoutes = () => {
            const box = el.querySelector('[data-routes]');
            box.innerHTML = routesHtml();
            box.querySelectorAll('[data-radd]').forEach(b => b.onclick = () => { routes[b.dataset.radd].push({ node: '', model: '' }); bindRoutes(); });
            box.querySelectorAll('[data-rrow]').forEach(row => {
                const [t, i] = row.dataset.rrow.split(':'), k = +i, r = routes[t][k];
                row.querySelector('[data-rnode]').onchange = e => { r.node = e.target.value; bindRoutes(); };
                row.querySelector('[data-rmodel]').oninput = e => { r.model = e.target.value.trim(); };
                row.querySelector('[data-rmodel]').onchange = () => bindRoutes();
                row.querySelector('[data-rdel]').onclick = () => { routes[t].splice(k, 1); bindRoutes(); };
                row.querySelector('[data-rup]').onclick = () => { if (k) { routes[t].splice(k - 1, 0, routes[t].splice(k, 1)[0]); bindRoutes(); } };
                row.querySelector('[data-rdown]').onclick = () => { if (k < routes[t].length - 1) { routes[t].splice(k + 1, 0, routes[t].splice(k, 1)[0]); bindRoutes(); } };
            });
        };
        bindRoutes();
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
                <p class="text-[10.5px] text-slate-400 leading-6">رمز رمزنگاری‌شده روی هاست ذخیره می‌شود و استعلام‌ها خودکار با همین نام کاربری و رمز وارد می‌شوند. نشستِ سامانه بینِ همه مشترک است؛ اگر بسته باشد، هر کاربری که استعلام بزند (پنل یا مینی‌اپ) زیرِ فیلدهایش کدِ تصویرِ امنیتی را می‌بیند و با زدنش، ورود و استعلام خودکار انجام می‌شود.</p>
                <div class="rounded-2xl bg-slate-50 p-3"><p class="text-[11px] font-black text-slate-600 mb-1"><i class="fas fa-clock ml-1"></i>نگه‌داشتنِ نشست + ادامه‌ی استعلامِ گروهی در پس‌زمینه</p>
                    <p class="text-[10.5px] text-slate-500 leading-6">اختیاری ولی پیشنهادی: بدونِ آن، سامانه‌ی پاسارگاد بعد از مدتی بی‌کاری خودش نشست را می‌بندد و کاربر زودتر کپچا می‌بیند، و استعلامِ گروهی فقط تا وقتی این صفحه باز است جلو می‌رود. با آن، نشست تا «نگه‌داشتنِ نشست» دقیقه بعد از آخرین استعلام باز می‌ماند و بعد رها می‌شود.</p>
                    <p class="text-[10.5px] text-slate-500 leading-6 mt-1">cPanel ← Cron Jobs ← Common Settings: <b>Once Per Five Minutes (*/5 * * * *)</b> و در Command این را بگذارید:</p>
                    <code class="block text-[10.5px] bg-white border rounded-lg p-2 mt-1 break-all" dir="ltr" id="inq-cron">curl -s -o /dev/null "${esc(d.cron_url)}"</code>
                    <div class="flex gap-2 mt-2"><button class="inq-fbtn" id="inq-cc"><i class="far fa-copy ml-1"></i>کپی</button><button class="inq-fbtn" id="inq-ck">کلیدِ تازه</button></div></div>
            </div>
            <div class="inq-card space-y-3">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-shield-halved ml-1 text-cyan-600"></i>اتصال به سامانه‌ی سنهاب (بیمه مرکزی)</p>
                <div class="flex flex-wrap items-center gap-2">${(statusChip(d.sh_status) || '<span class="inq-chip">تنظیم نشده</span>').replace('inq-chip', 'inq-chip !bg-slate-800 text-white')}<button class="inq-fbtn" id="inq-shs"><i class="fas fa-rotate ml-1"></i>بررسیِ دوباره</button><button class="inq-go !py-2 !px-4 text-xs" id="inq-shl"><i class="fas fa-key ml-1"></i>ورود با کپچا</button>
                    <button class="inq-fbtn" id="inq-shp"><i class="fas fa-eye ml-1"></i>صفحه‌ی آخرِ سامانه</button></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="inq-f">نام کاربریِ سنهاب<input id="inq-shu" class="inq-in" dir="ltr" style="font-size:13px" value="${esc(d.sh_username || '')}"></label>
                    <label class="inq-f">رمزِ سنهاب<input id="inq-shpw" type="password" class="inq-in" dir="ltr" style="font-size:13px" placeholder="${d.sh_has_password ? '•••••• (ذخیره شده؛ برای تغییر بنویسید)' : ''}"></label></div>
                ${sw('inq_sh_enabled', 'استعلامِ سنهاب فعال باشد', 'در استعلامات، ثبتِ درخواست (ردیف‌های ثالث)، پنلِ صدور و استعلامِ حق بیمه')}
                ${num('inq_sh_daily_limit', 'سقفِ روزانه‌ی سنهاب برای هر کاربر', '۰ = بدونِ سقف؛ مدیر کل سقف ندارد')}
                <p class="text-[10.5px] text-slate-400 leading-6">رمز رمزنگاری‌شده ذخیره می‌شود و فقط مدیر کل آن را می‌بیند/عوض می‌کند. بقیه‌ی کاربران فقط کدِ تصویر را می‌زنند: اگر نشست بسته باشد کدِ «ورود»، و برای هر استعلام کدِ خودِ آن استعلام. نشستِ سنهاب بینِ همه مشترک است، پس در هر لحظه یک نفر استعلام می‌زند (حداکثر ۹۰ ثانیه نوبت؛ بقیه چند ثانیه صبر می‌کنند). با cronِ بالا، نشستِ سنهاب هم تا «نگه‌داشتنِ نشست» دقیقه بعد از آخرین استعلام باز می‌ماند.</p>
                <div class="rounded-2xl border border-cyan-100 bg-cyan-50/40 p-3 space-y-2" id="inq-stamp">
                    <p class="font-black text-[12.5px] text-slate-800"><i class="fas fa-stamp ml-1 text-cyan-600"></i>واترمارکِ استعلامات (مهرِ ما)</p>
                    <p class="text-[10.5px] text-slate-500 leading-6">یک عکسِ PNG بدونِ زمینه (مهر) بارگذاری کنید؛ روی هر استعلامِ خسارت (خروجیِ سنهاب و فایل/عکسِ دستیِ ثالث و بدنه) پایینِ سمتِ چپ می‌خورد.</p>
                    <div class="flex flex-wrap items-start gap-3"><div data-stp class="text-[11px] text-slate-400"><i class="fas fa-spinner fa-spin"></i></div>
                        <div class="flex flex-col gap-1.5"><label class="inq-fbtn cursor-pointer"><i class="fas fa-upload ml-1"></i>بارگذاریِ مهر (PNG)<input type="file" accept="image/png" class="hidden" data-stu></label>
                            <a class="inq-fbtn" target="_blank" href="${API}?action=dmg_stamp_sample"><i class="fas fa-file-pdf ml-1"></i>PDFِ نمونه با مهر</a>
                            <button type="button" class="inq-fbtn" data-str style="background:#fee2e2;color:#be123c"><i class="fas fa-trash ml-1"></i>حذفِ مهر</button></div></div></div>
                <div class="rounded-2xl border border-teal-100 bg-teal-50/40 p-3 space-y-2">
                    <p class="font-black text-[12.5px] text-slate-800"><i class="fas fa-car-burst ml-1 text-teal-600"></i>پاسارگاد: «استعلام بدنه بیمه مرکزی» و «استعلام خسارت»</p>
                    <p class="text-[10.5px] text-slate-500 leading-6">با همان نام کاربری و نشستِ پاسارگادِ بالا. سامانه تک‌صفحه‌ای است (آدرسِ مرورگر با زدنِ منو عوض نمی‌شود)؛ برای همین سایت خودش از صفحه‌ی اصلی (<span dir="ltr">…/cins/template/fa/index_fa.xhtml</span>) منوی «خودرو ← سناریو خاص ← استعلام» را پیدا می‌کند و صفحه را باز می‌کند و آدرسش را نگه می‌دارد. خانه‌های زیر را خالی بگذارید (یا همان آدرسِ صفحه‌ی اصلی را بگذارید).
                        اگر بعد از استعلام خطای «پیدا نشد» آمد: در کروم صفحه را باز کنید، F12 ← زبانه‌ی Network، روی منوی همان استعلام بزنید و آدرسِ اولین درخواستِ نوعِ Document را کپی کنید (یا روی خودِ صفحه کلیک‌راست ← «Open frame in new tab» و آدرسِ آن زبانه) و اینجا بگذارید.</p>
                    ${sw('inq_pb_enabled', 'استعلامِ بدنه و خسارتِ پاسارگاد فعال باشد', 'در استعلامات و پنلِ صدورِ ردیف‌های بدنه')}
                    ${num('inq_pb_daily_limit', 'سقفِ روزانه‌ی این استعلام برای هر کاربر', '۰ = بدونِ سقف؛ مدیر کل سقف ندارد')}
                    <label class="inq-f">آدرسِ صفحه‌ی «استعلام بدنه بیمه مرکزی» (اختیاری)<input data-s="inq_pb_body_url" class="inq-in" dir="ltr" style="font-size:12px" placeholder="https://core.pasargadinsurance.ir/..." value="${esc(s.inq_pb_body_url || '')}"></label>
                    <label class="inq-f">آدرسِ صفحه‌ی «استعلام خسارت» (اختیاری)<input data-s="inq_pb_damage_url" class="inq-in" dir="ltr" style="font-size:12px" placeholder="https://core.pasargadinsurance.ir/..." value="${esc(s.inq_pb_damage_url || '')}"></label></div>
                <p class="font-black text-sm text-slate-800 pt-2"><i class="fas fa-building-circle-check ml-1 text-emerald-600"></i>شخصِ حقوقی</p>
                ${sw('inq_legal_px', 'اول از سامانه‌ی پاسارگاد استعلام شود', 'از فهرستِ اشخاصِ حقوقیِ پاسارگاد (نام، شماره ثبت، کد اقتصادی، نشانی، تلفن، ...)؛ اگر نتیجه نداد یا خاموش بود، از سرویسِ s.api.ir')}
            </div>
            <div class="inq-card space-y-2.5">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-sliders ml-1 text-violet-500"></i>رفتارِ استعلام‌ها</p>
                ${sw('inq_enabled', 'استعلام‌ها فعال باشد')}
                ${sw('inq_miniapp_person', 'استعلامِ هوشمندِ شخص در مینی‌اپ', 'به محضِ واردکردنِ کد ملی و تاریخ تولد، نام و نام خانوادگی خودکار پر می‌شود (اگر نیامد، کاربر دستی می‌نویسد)')}
                ${sw('inq_miniapp_postal', 'دکمه‌ی استعلامِ کد پستی در مینی‌اپ و درخواستِ کاربران')}
                ${sw('inq_postal_required', 'استعلامِ کد پستی برای کاربران اجباری باشد', 'کاربر تا کد پستیِ تأییدشده (املاک و اسکان) نزند، نمی‌تواند درخواست را ادامه دهد')}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
                    ${num('inq_wait_sec', 'مکث بینِ گام‌ها (ثانیه)', '۰ تا ۹؛ پیش‌فرض ۲')}
                    ${num('inq_cache_days', 'اعتبارِ نتیجه‌ی قبلی (روز)', '۰ = همیشه دوباره بپرس')}
                    ${num('inq_daily_limit', 'سقفِ روزانه برای هر کاربرِ مینی‌اپ', '۰ = بدونِ سقف')}
                    ${num('inq_nid_daily_limit', 'سقفِ روزانه برای هر کد ملی', 'همه‌ی کاربران روی هم؛ پیش‌فرض ۲؛ ۰ = بدونِ سقف')}
                    ${num('inq_postal_daily_limit', 'سقفِ روزانه برای هر کد پستی', 'همه‌ی کاربران روی هم؛ پیش‌فرض ۲؛ ۰ = بدونِ سقف')}
                    ${num('inq_user_daily_limit', 'سقفِ روزانه‌ی پیش‌فرضِ هر کاربرِ پنل', '۰ = بدونِ سقف؛ عددِ هر نفر در «کاربران» قابلِ تغییر است')}
                    ${num('inq_keep_minutes', 'نگه‌داشتنِ نشست (دقیقه)', 'بعد از آخرین استعلام؛ پیش‌فرض ۶۰ (با cron)')}
                </div>
                <button class="inq-go w-full mt-2" id="inq-ssave"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تنظیمات</button>
            </div>
            <div class="inq-card space-y-2.5" id="inq-ocrset"><p class="text-xs text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>پردازش فایل هوشمند…</p></div>
            <div class="inq-card space-y-2.5">
                <p class="font-black text-sm text-slate-800"><i class="fas fa-rotate-left ml-1 text-rose-500"></i>سقفِ امروزِ یک کد ملی یا کد پستی</p>
                <p class="text-[10.5px] text-slate-400">ببینید امروز چند بار استعلام شده و در صورتِ نیاز شمارش را صفر کنید تا دوباره استعلام شود.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="inq-f">کد ملی<input id="inq-ln" class="inq-in" dir="ltr" inputmode="numeric" maxlength="10" style="font-size:13px"></label>
                    <label class="inq-f">کد پستی<input id="inq-lp" class="inq-in" dir="ltr" inputmode="numeric" maxlength="10" style="font-size:13px"></label>
                </div>
                <div class="flex flex-wrap gap-2"><button class="inq-fbtn" id="inq-lpk"><i class="fas fa-eye ml-1"></i>نمایشِ شمارشِ امروز</button><button class="inq-fbtn" id="inq-lrs" style="color:#be123c"><i class="fas fa-rotate-left ml-1"></i>صفر کردن</button></div>
                <p id="inq-lres" class="text-[11.5px] font-bold text-slate-600"></p>
            </div></div>`;
        const limitCall = async action => {
            const r = await post({ action, national_id: en(body.querySelector('#inq-ln').value), postal: en(body.querySelector('#inq-lp').value) });
            const box = body.querySelector('#inq-lres');
            if (!r.ok) { box.className = 'text-[11.5px] font-bold text-rose-600'; box.textContent = r.error; return; }
            const part = (t, x) => x ? `${t} ${fa(x.value)}: امروز ${fa(x.used)} بار${x.limit ? ' از ' + fa(x.limit) : ' (بدونِ سقف)'}` : '';
            box.className = 'text-[11.5px] font-bold ' + (r.reset ? 'text-emerald-600' : 'text-slate-600');
            box.textContent = (r.reset ? 'صفر شد. ' : '') + [part('کد ملی', r.nid), part('کد پستی', r.postal)].filter(Boolean).join(' · ');
        };
        ocrSettings(body.querySelector('#inq-ocrset'));
        stampBox(body);
        body.querySelector('#inq-lpk').onclick = () => limitCall('limit_peek');
        body.querySelector('#inq-lrs').onclick = () => limitCall('limit_reset');
        body.querySelector('#inq-ss').onclick = async () => { const r = await post({ action: 'status' }); V.boot.status = r.status; renderSettings(body); };
        body.querySelector('#inq-sl').onclick = () => loginDialog(() => renderSettings(body));
        body.querySelector('#inq-shs').onclick = async () => { await post({ action: 'sh_boot' }); renderSettings(body); };
        body.querySelector('#inq-shp').onclick = () => {
            const url = `${API}?action=sh_page`;
            const m = document.createElement('div'); m.className = 'inq-modal';
            m.innerHTML = `<div><div class="flex items-center justify-between p-3 border-b"><b class="text-sm">صفحه‌ی آخرِ سامانه‌ی سنهاب</b><button class="inq-fbtn" data-x>بستن</button></div><iframe src="${url}" sandbox="" style="flex:1;width:100%;min-height:75vh;border:0;background:#fff"></iframe></div>`;
            m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
            document.body.appendChild(m);
        };
        body.querySelector('#inq-shl').onclick = () => shLoginDialog(() => renderSettings(body));
        body.querySelector('#inq-cc').onclick = () => { try { navigator.clipboard.writeText(body.querySelector('#inq-cron').textContent); toast('کپی شد.', 'info'); } catch (e) {} };
        body.querySelector('#inq-ck').onclick = async () => { if (!(await ask('کلیدِ cron', 'کلیدِ cron عوض شود؟ دستورِ قبلی دیگر کار نمی‌کند.', { ok: 'عوض شود' }))) return; await post({ action: 'settings_save', new_cron_key: 1 }); renderSettings(body); };
        body.querySelector('#inq-ssave').onclick = async () => {
            const b = { action: 'settings_save', username: body.querySelector('#inq-su').value.trim(), password: body.querySelector('#inq-sp').value,
                        sh_username: body.querySelector('#inq-shu').value.trim(), sh_password: body.querySelector('#inq-shpw').value };
            body.querySelectorAll('[data-s]').forEach(i => b[i.dataset.s] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : en(i.value));
            const r = await post(b); toast(r.ok ? 'ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
            if (r.ok) renderSettings(body);
        };
    }
    // ورودِ دستی به سامانه (فقط کدِ تصویر؛ نام کاربری و رمز فقط از تنظیمات)
    async function loginDialog(after) {
        const ok = await captchaModal(null, '');
        if (!ok) return;
        toast('وارد شدید؛ استعلام‌ها آماده‌اند.', 'success');
        if (V.boot) { V.boot.status = 'logged_in'; if (V.el && V.el.isConnected && !after) render(); }
        if (after) after();
    }

    // ورود به سنهاب (مدیر کل): کپچای ورود در پنجره؛ نام کاربری و رمز از تنظیمات
    async function shLoginDialog(after) {
        const r = await post({ action: 'sh_login_captcha', refresh: 1 });
        const done = () => { toast('به سنهاب وارد شدید؛ استعلام‌ها آماده‌اند.', 'success'); if (V.boot) V.boot.sh_status = 'logged_in'; if (after) after(); else if (V.el && V.el.isConnected) render(); };
        if (r.logged_in) { done(); return; }
        if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
        const m = document.createElement('div'); m.className = 'inq-modal';
        m.innerHTML = `<div style="width:min(460px,100%)"><div class="p-4 space-y-3"><p class="font-black text-sm text-slate-800"><i class="fas fa-key ml-1 text-amber-500"></i>ورود به سامانه‌ی سنهاب</p>
            <p class="text-[11px] text-slate-500">کدِ تصویرِ ورود را بزنید (نام کاربری و رمز از تنظیمات خوانده می‌شود).</p>
            <div class="inq-cap"><div class="flex flex-wrap items-center gap-2"><span class="inq-capimg"><img src="${r.image}" alt=""></span><button type="button" class="inq-fbtn" data-re title="تصویرِ دیگر"><i class="fas fa-rotate"></i></button>
                <input class="inq-in !mt-0" style="width:150px;text-align:center" dir="ltr" autocomplete="off" data-c><button class="inq-go !py-2 !px-4 text-xs" data-ok>ورود</button></div>
                <p class="text-[11px] text-rose-600 font-bold mt-1" data-e></p></div>
            <div class="text-left"><button class="inq-fbtn" data-x>انصراف</button></div></div></div>`;
        document.body.appendChild(m);
        const c = m.querySelector('[data-c]'), e = m.querySelector('[data-e]'), img = m.querySelector('img');
        const go = async () => {
            const t = en(c.value).trim(); if (!t) { c.focus(); return; }
            const b = m.querySelector('[data-ok]'); b.disabled = true;
            const x = await post({ action: 'sh_login', captcha: t });
            b.disabled = false;
            if (x.ok) { m.remove(); done(); return; }
            e.textContent = x.error || 'کد اشتباه بود.'; c.value = ''; if (x.image) img.src = x.image; c.focus();
        };
        m.querySelector('[data-ok]').onclick = go;
        c.addEventListener('keydown', ev => { if (ev.key === 'Enter') go(); });
        m.querySelector('[data-re]').onclick = async () => { const n = await post({ action: 'sh_login_captcha', refresh: 1 }); if (n.logged_in) { m.remove(); done(); } else if (n.image) img.src = n.image; else e.textContent = n.error || ''; };
        m.querySelector('[data-x]').onclick = () => m.remove();
        setTimeout(() => c.focus(), 50);
    }

    // تأیید با پاپ‌آپِ خودِ سایت (uiConfirm)؛ اگر نبود، پنجره‌ی مرورگر
    function ask(title, text, o) { return window.uiConfirm ? uiConfirm(title, text, o || {}) : Promise.resolve(window.confirm(text)); }

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
            const capHost = document.createElement('div'); wrap.insertAdjacentElement('afterend', capHost);
            const btn = wrap.querySelector('button'), msg = wrap.querySelector('span');
            btn.onclick = async () => {
                const nid = en(typeof opts.nid === 'function' ? opts.nid() : (opts.nid && opts.nid.value) || '').replace(/\D/g, '');
                const birth = en(opts.birth ? opts.birth.value : '').trim();
                const postal = en(opts.postal ? opts.postal.value : '').replace(/\D/g, '');
                if (!nid || !birth) { msg.className = 'inq-fmsg text-amber-600'; msg.textContent = !nid && !birth ? 'اول کد ملی و تاریخ تولدِ بیمه‌گذار را وارد کنید.' : (!nid ? 'کد ملیِ بیمه‌گذار پیدا نشد؛ آن را وارد کنید.' : 'اول تاریخ تولدِ بیمه‌گذار را وارد کنید.'); return; }
                if (kind === 'postal' && postal.length !== 10) { msg.className = 'inq-fmsg text-amber-600'; msg.textContent = 'کد پستیِ ۱۰ رقمی را وارد کنید.'; return; }
                btn.disabled = true; msg.className = 'inq-fmsg'; msg.innerHTML = liveBox('run', '<i class="fas fa-circle-notch fa-spin ml-1 text-indigo-600"></i>در حالِ استعلام…', 1);
                const adm = V.boot ? !!V.boot.is_admin : !!window.IS_REAL_ADMIN;
                const r = await run({ kind: kind === 'postal' ? 'postal' : 'person', source: 'form', national_id: nid, birth, postal }, it => { if (!it.done) msg.innerHTML = liveBox('run', `<i class="fas fa-circle-notch fa-spin ml-1 text-indigo-600"></i>${esc(adm && it.notice ? it.notice : 'در حالِ استعلام…')}`, 1); }, capHost);
                btn.disabled = false;
                if (!r.ok) { msg.className = 'inq-fmsg text-rose-600'; msg.innerHTML = liveBox('bad', esc(r.error || 'خطا'), 1); return; }
                const it = r.item;
                if (kind === 'postal') {
                    const v = it.postal_valid;
                    msg.className = 'inq-fmsg ' + (v === true ? 'text-emerald-600' : v === false ? 'text-rose-600' : 'text-amber-600');
                    msg.innerHTML = liveBox(v === true ? 'ok' : v === false ? 'bad' : 'warn', `<i class="fas ${v === true ? 'fa-circle-check' : v === false ? 'fa-circle-xmark' : 'fa-triangle-exclamation'} ml-1"></i>${esc(it.postal_text || it.notice || '')}`, 1);
                    if (isAdm(it)) { msg.insertAdjacentHTML('beforeend', ' <button type="button" class="inq-fbtn" data-det>جزئیات</button>'); msg.querySelector('[data-det]').onclick = () => detail(it.id); }
                    if (opts.onPostal) opts.onPostal(it);
                } else {
                    const p = it.person || {};
                    if (it.status !== 'FAIL' && (p.name || it.person_name)) {
                        const full = `${p.name || ''} ${p.family || ''}`.trim() || it.person_name;
                        if (opts.name) { opts.name.value = full; opts.name.dispatchEvent(new Event('input', { bubbles: true })); }
                        if (opts.father && (p.father || it.father_name)) opts.father.value = p.father || it.father_name;
                        msg.className = 'inq-fmsg text-emerald-600';
                        msg.innerHTML = liveBox('ok', `<i class="fas fa-circle-check ml-1"></i>${esc(full)}${p.father ? ' · فرزند ' + esc(p.father) : ''}${it.cached ? ' (از استعلامِ قبلی)' : ''}`, 1);
                        if (isAdm(it)) { msg.insertAdjacentHTML('beforeend', ' <button type="button" class="inq-fbtn" data-det>جزئیات</button>'); msg.querySelector('[data-det]').onclick = () => detail(it.id); }
                        if (opts.onPerson) opts.onPerson(it);
                    } else { msg.className = 'inq-fmsg text-rose-600'; msg.innerHTML = liveBox('bad', esc((it.notice || 'مشخصات پیدا نشد') + ' — دستی وارد کنید.'), 1); }
                }
            };
            return wrap;
        };
        mk(opts.name, 'استعلامِ نام', 'person');
        const pw = mk(opts.postal, 'استعلامِ کد پستی', 'postal');
        // کد پستیِ تأییدشده‌ی قبلیِ همین کد ملی خودکار نوشته می‌شود (کاربر می‌تواند عوضش کند)
        if (pw && opts.postal) {
            const pin = opts.postal, pmsg = pw.querySelector('span');
            let lastNid = '', timer = 0;
            pin.addEventListener('input', () => { pin.dataset.inqAuto = ''; });
            const check = async () => {
                const nid = en(typeof opts.nid === 'function' ? opts.nid() : (opts.nid && opts.nid.value) || '').replace(/\D/g, '');
                if (nid.length !== 10 || nid === lastNid) return;
                lastNid = nid;
                if (pin.value.trim() && pin.dataset.inqAuto !== '1') return;
                const r = await post({ action: 'known_postal', national_id: nid });
                if (!r.ok || !r.known) { if (pin.dataset.inqAuto === '1') { pin.value = ''; pin.dataset.inqAuto = ''; pmsg.textContent = ''; } return; }
                pin.value = fa(r.known.postal); pin.dataset.inqAuto = '1';
                pin.dispatchEvent(new Event('change', { bubbles: true }));
                pmsg.className = 'inq-fmsg text-emerald-600';
                pmsg.innerHTML = `<i class="fas fa-circle-check ml-1"></i>این کد پستی قبلاً استعلام و تأیید شده (${esc(fa(r.known.date))})؛ اگر عوض شده، کدِ تازه را بنویسید.`;
                if (opts.onPostal) opts.onPostal({ postal_valid: true, postal: r.known.postal, known: true });
            };
            const sched = () => { clearTimeout(timer); timer = setTimeout(check, 450); };
            (opts.watch || []).forEach(el => { if (el) { el.addEventListener('input', sched); el.addEventListener('change', sched); } });
            pin.addEventListener('focus', check);
            sched();
        }
    }

    // =================================================================
    //  استعلام از داخلِ هر فرم: کنارِ هر فیلدِ «کد ملی / شناسه ملی» (بیمه‌گذار، مالک، ذینفع، شرکت) دکمه‌ی ذره‌بین؛
    //  شخص (کد ملی + تاریخ تولد) یا شرکت (نام یا شناسه‌ی ملی) از همان استعلاماتِ سایت؛ نتیجه در فیلدهای همان بخش می‌نشیند
    // =================================================================
    const QB_ATTRS = ['data-pf', 'data-pty-f', 'data-pty-i', 'data-pp-f', 'data-k', 'data-ii', 'name'];
    // فیلدِ کد ملی ← فیلدهای همان بخش (نام، تلفن، کد پستی، نشانی، شناسه)
    const QB_MAP = {
        national_id: { name: ['insured_name', 'name'], phone: ['phone', 'insured_phone'], postal: ['postal_code', 'insured_postal_code'], address: ['address', 'insured_address'] },
        insured_national_id: { name: ['insured_name'], phone: ['insured_phone'], postal: ['insured_postal_code'], address: ['insured_address'] },
        owner_national_id: { name: ['owner_name'], phone: ['owner_phone'], address: ['owner_address'] },
        beneficiary_national_id: { name: ['beneficiary'] },
    };
    function qbCanUse() {
        if (!window.permCan) return true;
        try { return permCan('inq-single') || permCan('inq-legal') || permCan('inquiries'); } catch (e) { return true; }
    }
    // فیلدهای با id (فرمِ ثبتِ دستیِ کارکنان): کد ملی ← نام
    const QB_IDS = { 'mc-owner-nid': 'mc-owner-name' };
    function qbFind(input) {
        if (QB_IDS[input.id]) {
            const nameEl = document.getElementById(QB_IDS[input.id]);
            if (nameEl) return { box: input.closest('form,div') || document.body, nid: input, name: nameEl, isCompany: () => false };
        }
        for (const a of QB_ATTRS) {
            const key = input.getAttribute(a);
            if (!key || !QB_MAP[key]) continue;
            const m = QB_MAP[key];
            // نزدیک‌ترین بخشی که فیلدِ نام هم در آن است
            let box = input.parentElement, nameEl = null;
            for (let i = 0; i < 7 && box && !nameEl; i++, box = box.parentElement) for (const nk of m.name) { nameEl = box.querySelector(`[${a}="${nk}"]`); if (nameEl) break; }
            if (!nameEl) continue;
            box = nameEl.parentElement; while (box && !box.contains(input)) box = box.parentElement;
            const f = keys => { for (const k of keys || []) { const el = box.querySelector(`[${a}="${k}"]`); if (el) return el; } return null; };
            // شخص/شرکت از رادیوی همان بخش (ویرایشگرِ نقش‌ها)
            const radio = box.querySelector('input[type=radio][value=COMPANY]');
            return { box, nid: input, name: nameEl, phone: f(m.phone), postal: f(m.postal), address: f(m.address), isCompany: () => !!(radio && radio.checked) };
        }
        return null;
    }
    function qbSet(el, v) { if (!el || v == null || v === '') return; el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
    function qbBind(input) {
        if (input.dataset.inqBound || input.dataset.inqQb || input.type === 'hidden' || input.readOnly) return;
        const g = qbFind(input);
        if (!g) return;
        input.dataset.inqQb = '1';
        const host = input.parentElement;
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'inq-qb'; b.title = 'استعلام از سامانه (نام و مشخصات در فرم می‌نشیند)';
        b.innerHTML = '<i class="fas fa-magnifying-glass"></i>';
        b.onclick = e => { e.preventDefault(); e.stopPropagation(); qbOpen(b, g); };
        // داخلِ کادرهای فلکس (کنارِ دکمه‌ی کپی) کنارِ فیلد؛ وگرنه داخلِ خودِ فیلد سمتِ چپ
        if (/flex/.test(getComputedStyle(host).display)) { b.style.cssText = 'position:static;transform:none;flex:none;margin-inline:3px'; input.insertAdjacentElement('afterend', b); return; }
        if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
        input.style.paddingLeft = '34px';
        host.appendChild(b);
    }
    function qbOpen(anchor, g) {
        css();
        document.querySelectorAll('.inq-qpop').forEach(x => x.remove());
        const nid = en(g.nid.value).replace(/\D/g, ''), nm = (g.name.value || '').trim();
        let mode = g.isCompany() || nid.length === 11 || (!nid && /شرکت|موسسه|سازمان|بانک|گروه/.test(nm)) ? 'legal' : 'person';
        const pop = document.createElement('div'); pop.className = 'inq-qpop';
        // زیرِ دکمه؛ اگر جا نبود بالای آن، و اگر باز هم نشد هم‌تراز با بالای صفحه (با اسکرولِ داخلی). با بزرگ شدنِ محتوا (کپچا، نتیجه) دوباره جا می‌گیرد
        const place = () => {
            const r = anchor.getBoundingClientRect(), w = pop.offsetWidth || 340, h = pop.offsetHeight;
            let top = r.bottom + 6;
            if (top + h > innerHeight - 12) top = r.top - h - 6 >= 12 ? r.top - h - 6 : Math.max(12, innerHeight - h - 12);
            pop.style.top = top + 'px'; pop.style.left = Math.max(12, Math.min(innerWidth - w - 12, r.left)) + 'px';
        };
        if (window.ResizeObserver) new ResizeObserver(() => { if (pop.isConnected) place(); }).observe(pop);
        const draw = () => {
            pop.innerHTML = `<div class="flex items-center justify-between mb-2"><b class="text-[12.5px] text-slate-800"><i class="fas fa-magnifying-glass text-indigo-600 ml-1"></i>استعلام و پر کردنِ فرم</b><button type="button" data-x class="text-slate-400 hover:text-rose-500"><i class="fas fa-times"></i></button></div>
                <div class="flex gap-1.5"><button type="button" class="inq-qtab ${mode === 'person' ? 'on' : ''}" data-m="person"><i class="fas fa-user ml-1"></i>شخص حقیقی</button><button type="button" class="inq-qtab ${mode === 'legal' ? 'on' : ''}" data-m="legal"><i class="fas fa-building ml-1"></i>شرکت / حقوقی</button></div>
                ${mode === 'person' ? `<label>کد ملی<input data-q="national_id" dir="ltr" inputmode="numeric" maxlength="10" value="${esc(nid.length === 10 ? nid : '')}"></label>
                    <label>تاریخ تولد<input data-q="birth" dir="ltr" placeholder="1370/05/12"></label>`
                  : `<label>نامِ شرکت<input data-q="company_name" value="${esc(nm)}" placeholder="مثلاً بازرگانی نمونه"></label>
                    <label>شناسه‌ی ملی (اختیاری)<input data-q="company_nid" dir="ltr" inputmode="numeric" maxlength="11" value="${esc(nid.length === 11 ? nid : '')}"></label>`}
                <button type="button" data-go class="w-full mt-3 bg-indigo-600 hover:bg-indigo-700 text-white text-[12px] font-black py-2 rounded-xl"><i class="fas fa-magnifying-glass ml-1"></i>استعلام</button>
                <div data-cap class="mt-2"></div><div data-out class="mt-2"></div>`;
            pop.querySelector('[data-x]').onclick = () => pop.remove();
            pop.querySelectorAll('[data-m]').forEach(x => x.onclick = () => { mode = x.dataset.m; draw(); place(); });
            pop.querySelector('[data-go]').onclick = go;
            const bi = pop.querySelector('[data-q="birth"]'); if (bi && window.JDatePick && JDatePick.attach) try { JDatePick.attach(bi); } catch (e) {}
            const first = pop.querySelector(mode === 'person' ? (nid.length === 10 ? '[data-q="birth"]' : '[data-q="national_id"]') : '[data-q="company_name"]'); if (first) setTimeout(() => first.focus(), 30);
        };
        const go = async () => {
            const q = {}; pop.querySelectorAll('[data-q]').forEach(x => q[x.dataset.q] = en(x.value).trim());
            const out = pop.querySelector('[data-out]'), btn = pop.querySelector('[data-go]');
            if (mode === 'person' && (q.national_id.replace(/\D/g, '').length !== 10 || !q.birth)) { out.innerHTML = liveBox('warn', 'کد ملیِ ۱۰ رقمی و تاریخ تولد را وارد کنید.', 1); return; }
            if (mode === 'legal' && !q.company_name && !q.company_nid) { out.innerHTML = liveBox('warn', 'نامِ شرکت یا شناسه‌ی ملی را وارد کنید.', 1); return; }
            btn.disabled = true;
            const adm = V.boot ? !!V.boot.is_admin : !!window.IS_REAL_ADMIN;
            const show = it => { out.innerHTML = liveBox('run', `<i class="fas fa-circle-notch fa-spin ml-1 text-indigo-600"></i>${esc(adm && it && it.notice ? it.notice : 'در حالِ استعلام…')}`, 1); };
            show(null);
            const body = mode === 'person' ? { kind: 'person', source: 'form', national_id: q.national_id.replace(/\D/g, ''), birth: q.birth } : { kind: 'legal', source: 'form', company_name: q.company_name, company_nid: q.company_nid.replace(/\D/g, '') };
            const r = await run(body, it => { if (!it.done) show(it); }, pop.querySelector('[data-cap]'));
            btn.disabled = false;
            if (!r.ok) { out.innerHTML = liveBox('bad', '<i class="fas fa-circle-xmark ml-1"></i>' + esc(r.error || 'خطا')); return; }
            const it = r.item;
            if (mode === 'person') {
                const p = it.person || {}, full = `${p.name || ''} ${p.family || ''}`.trim() || it.person_name || '';
                if (it.status === 'FAIL' || !full) { out.innerHTML = liveBox('bad', '<i class="fas fa-circle-xmark ml-1"></i>' + esc(it.notice || 'مشخصات پیدا نشد.')); return; }
                qbSet(g.name, full); qbSet(g.nid, body.national_id);
                out.innerHTML = liveBox('ok', `<i class="fas fa-circle-check ml-1 text-emerald-600"></i><b>${esc(full)}</b>${p.father || it.father_name ? ' · فرزند ' + esc(p.father || it.father_name) : ''}<p class="text-[10.5px] text-emerald-700 mt-1">در فرم نشست.</p>`);
            } else {
                const c = it.company;
                if (it.status === 'FAIL' || !c) { out.innerHTML = liveBox('bad', '<i class="fas fa-circle-xmark ml-1"></i>' + esc(it.notice || 'شرکت پیدا نشد.')); return; }
                qbSet(g.name, c.name); qbSet(g.nid, c.national_id); qbSet(g.phone, c.phone || c.mobile); qbSet(g.postal, c.postal_code); qbSet(g.address, c.address);
                const kr = g.box.querySelector('input[type=radio][value=COMPANY]'); if (kr && !kr.checked) { kr.checked = true; kr.dispatchEvent(new Event('change', { bubbles: true })); }
                out.innerHTML = liveBox('ok', `<i class="fas fa-circle-check ml-1 text-emerald-600"></i><b>${esc(c.name || '')}</b>${c.national_id ? ' · <span dir="ltr">' + fa(c.national_id) + '</span>' : ''}${c.address ? `<p class="text-[10.5px] text-slate-500 mt-1">${esc(fa(c.address))}</p>` : ''}<p class="text-[10.5px] text-emerald-700 mt-1">در فرم نشست.</p>`);
            }
        };
        draw();
        document.body.appendChild(pop); place();
        const off = e => { if (!pop.contains(e.target) && e.target !== anchor && !anchor.contains(e.target) && !e.target.closest('.jdp,.jdp-ov')) { pop.remove(); document.removeEventListener('mousedown', off, true); } };
        setTimeout(() => document.addEventListener('mousedown', off, true), 50);
    }
    function qbScan(root) {
        if (!qbCanUse()) return;
        const sel = Object.keys(QB_MAP).map(k => QB_ATTRS.map(a => `input[${a}="${k}"]`).join(',')).join(',') + ',' + Object.keys(QB_IDS).map(i => '#' + i).join(',');
        (root.querySelectorAll ? root : document).querySelectorAll(sel).forEach(qbBind);
    }
    function qbStart() {
        if (qbStart.on) return; qbStart.on = true;
        const mo = new MutationObserver(ms => { for (const m of ms) for (const n of m.addedNodes) if (n.nodeType === 1) { if (n.matches && n.matches('input')) qbScan(n.parentElement || n); else qbScan(n); } });
        mo.observe(document.body, { childList: true, subtree: true });
        qbScan(document);
    }
    if (document.body) setTimeout(qbStart, 800); else document.addEventListener('DOMContentLoaded', () => setTimeout(qbStart, 800));

    window.Inquiry = { init, attachForm, run, login: loginDialog, quickScan: qbScan };
})();
