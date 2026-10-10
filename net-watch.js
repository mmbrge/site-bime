// فایل: net-watch.js
// هشدارِ اینترنت: قطع‌شدن و ضعیف‌بودنِ اتصال، به‌شکلِ یک کارتِ اعلان در گوشه‌ی پایینِ سمتِ راستِ صفحه
// (کنارِ بقیه‌ی اعلان‌ها). با برگشتنِ اتصال چند ثانیه «اتصال برقرار شد» نشان داده می‌شود.
// همه‌ی حالت‌ها دکمه‌ی ضربدر دارند؛ «اتصال برقرار شد» و «ضعیف» بعد از ۱۰ ثانیه خودشان می‌روند، «قطع» تا وصل‌شدن می‌ماند.
// دقت: آزمون با یک فایلِ ایستا (بدونِ PHP و قفلِ نشست) انجام می‌شود، هر درخواستِ موفقِ خودِ صفحه
// هم «اتصال سالم است» حساب می‌شود، و فقط بعد از چند خطای پشتِ‌سرِ‌هم پیام داده می‌شود.
// پنلِ شرکت‌ها: window.NET_WATCH_PING = '../net-watch.js'
(function () {
    'use strict';
    if (window.NetWatch) return;
    // ریشه‌ی سایت از آدرسِ خودِ همین فایل (پنلِ شرکت‌ها در زیرپوشه است)
    const ROOT = (() => { try { const c = document.currentScript; return c && c.src ? new URL('.', c.src).href : '/'; } catch (e) { return '/'; } })();
    const PING = window.NET_WATCH_PING || 'net-watch.js';
    const OFFLINE_MS = window.NET_WATCH_OFFLINE_MS || 600000;   // بعد از ۱۰ دقیقه قطعیِ پیوسته، صفحه‌ی اختصاصیِ قطعی
    const SLOW_MS = 3500,     // آزمونِ یک فایلِ کوچک بیش از این طول بکشد => کند
          TIMEOUT_MS = 12000,
          EVERY_MS = 30000,
          FAILS_OFF = 3,      // چند خطای پشتِ‌سرِ‌هم تا «قطع»
          SLOWS_WEAK = 3,     // چند آزمونِ کندِ پشتِ‌سرِ‌هم تا «ضعیف»
          RETRY_MS = 2500,
          RESUME_GRACE = 4000;
    const st = {state: 'ok', slow: 0, fail: 0, el: null, timer: null, busy: false, hideT: null, again: false, lastOk: Date.now(), quietUntil: Date.now() + RESUME_GRACE, leaving: false};
    const css = `
    #net-watch{direction:rtl;display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #e2e8f0;border-right:5px solid var(--nw);border-radius:18px;
        padding:14px 16px;box-shadow:0 18px 40px -14px rgba(15,23,42,.35);font-family:inherit;color:#0f172a;pointer-events:auto;order:99;
        opacity:0;transform:translateX(40px) scale(.96);transition:opacity .3s,transform .35s cubic-bezier(.2,.9,.3,1.2);max-height:0;overflow:hidden;margin-top:-10px;padding-block:0}
    #net-watch.show{opacity:1;transform:none;max-height:200px;margin-top:0;padding-block:14px}
    #net-watch.off{--nw:#e11d48}#net-watch.weak{--nw:#f59e0b}#net-watch.back{--nw:#10b981}
    #net-watch .nw-ic{width:40px;height:40px;border-radius:14px;flex:none;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;position:relative;
        background:linear-gradient(135deg,var(--nw),color-mix(in srgb,var(--nw) 65%,#0f172a));box-shadow:0 8px 16px -8px var(--nw)}
    #net-watch.off .nw-ic::after,#net-watch.weak .nw-ic::after{content:'';pointer-events:none;position:absolute;inset:-4px;border-radius:17px;border:2px solid var(--nw);animation:nwPulse 1.6s ease-out infinite}
    @keyframes nwPulse{from{transform:scale(.9);opacity:.9}to{transform:scale(1.35);opacity:0}}
    #net-watch .nw-t{font-weight:900;font-size:13.5px;margin:0 0 2px}
    #net-watch .nw-b{font-size:12.5px;color:#475569;line-height:1.8;margin:0}
    #net-watch button{border:0;background:#f1f5f9;color:#334155;border-radius:10px;padding:6px 10px;font-weight:800;font-size:11px;font-family:inherit;cursor:pointer;flex:none}
    #net-watch button:hover{background:#e2e8f0}
    #net-watch .nw-x{background:transparent;color:#94a3b8;width:26px;height:26px;padding:0;font-size:13px;align-self:flex-start}
    #net-watch .nw-x:hover{background:#f1f5f9;color:#475569}
    #net-watch-box{position:fixed;bottom:20px;right:20px;z-index:2147483000;display:flex;flex-direction:column;gap:10px;width:min(400px,calc(100vw - 32px));pointer-events:none}
    `;
    function ensure() {
        if (st.el) return st.el;
        const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
        // اگر صفحه جعبه‌ی اعلان دارد، کارت همان‌جا (زیرِ اعلان‌ها) می‌نشیند؛ وگرنه جعبه‌ی خودش را می‌سازد
        let box = document.getElementById('notif-stack');
        if (!box) { box = document.createElement('div'); box.id = 'net-watch-box'; document.body.appendChild(box); }
        st.el = document.createElement('div'); st.el.id = 'net-watch'; st.el.setAttribute('role', 'status');
        box.appendChild(st.el);
        return st.el;
    }
    const MSG = {
        off: ['fa-wifi', 'اینترنت قطع است', 'تا برگشتنِ اتصال، تغییرات ذخیره نمی‌شوند؛ صفحه را نبندید.'],
        noserver: ['fa-server', 'ارتباط با سرور برقرار نیست', 'سایت جواب نمی‌دهد؛ چند ثانیه‌ی دیگر دوباره امتحان می‌کنیم…'],
        weak: ['fa-signal', 'اینترنت ضعیف است', 'بارگذاری و ذخیره ممکن است کند شود.'],
        back: ['fa-circle-check', 'اتصال برقرار شد', 'همه‌چیز دوباره عادی است.'],
    };
    function show(kind) {
        const el = ensure();
        clearTimeout(st.hideT);
        const [ic, t, sub] = MSG[kind];
        el.className = (kind === 'noserver' ? 'off' : kind);
        el.innerHTML = `<span class="nw-ic"><i class="fas ${ic}"></i></span><div style="flex:1;min-width:0"><p class="nw-t">${t}</p><p class="nw-b">${sub}</p></div>${kind !== 'back' ? '<button type="button" class="nw-re">بررسیِ دوباره</button>' : ''}<button type="button" class="nw-x" aria-label="بستن" title="بستن"><i class="fas fa-xmark"></i></button>`;
        const b = el.querySelector('.nw-re'); if (b) b.onclick = () => probe(true);
        el.querySelector('.nw-x').onclick = () => { clearTimeout(st.hideT); el.classList.remove('show'); };
        requestAnimationFrame(() => el.classList.add('show'));
        if (kind === 'back' || kind === 'weak') st.hideT = setTimeout(() => el.classList.remove('show'), 10000);
    }
    function set(state) {
        if (state === st.state) return;
        const was = st.state;
        st.state = state;
        const down = s => s === 'off' || s === 'noserver';
        if (down(state) && !down(was)) { st.offSince = Date.now(); scheduleOverlay(); }
        if (!down(state)) { st.offSince = 0; hideOverlay(); }
        if (state === 'ok') { if (down(was)) show('back'); else if (st.el) st.el.classList.remove('show'); }
        else show(state);
    }

    // ---------------- صفحه‌ی اختصاصیِ قطعی (بعد از ۱۰ دقیقه) ----------------
    // متنِ صفحه از قبل (در زمانِ بیکاریِ مرورگر، با اولویتِ پایین) گرفته و در حافظه نگه داشته می‌شود تا وقتی
    // اینترنت قطع است، بدونِ نیاز به شبکه نشان داده شود. صفحه داخلِ یک iframe روی همین صفحه باز می‌شود؛
    // پس کارِ نیمه‌تمام و فرم‌های پرشده سرِ جایشان می‌مانند و با وصل‌شدن، صفحه خودش کنار می‌رود.
    function prefetchOffline() {
        if (st.offHtml || st.offBusy) return;
        st.offBusy = true;
        const opts = {cache: 'force-cache', credentials: 'same-origin'};
        try { opts.priority = 'low'; } catch (e) {}
        origFetch(ROOT + 'errors/offline.html', opts).then(r => r.ok ? r.text() : '').then(t => { if (t && t.indexOf('<html') !== -1) st.offHtml = t; })
            .catch(() => {}).then(() => { st.offBusy = false; });
    }
    function scheduleOverlay() {
        clearTimeout(st.ovT);
        if (!st.offSince) return;
        const left = Math.max(0, (st.ovSnooze || st.offSince) + OFFLINE_MS - Date.now());
        st.ovT = setTimeout(showOverlay, left + 50);
    }
    function showOverlay() {
        if (st.ov || !st.offSince || (st.state !== 'off' && st.state !== 'noserver')) return;
        if (Date.now() - (st.ovSnooze || st.offSince) < OFFLINE_MS) { scheduleOverlay(); return; }
        const inject = `<script>window.NW_OFF_SINCE=${st.offSince};window.NW_KIND=${JSON.stringify(st.state)};window.NW_ROOT=${JSON.stringify(ROOT)};<\/script>`;
        const html = st.offHtml || `<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="margin:0;font-family:Vazir,Tahoma;background:#0b1023;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center">
            <div><div style="font-size:54px">📡</div><h2>اتصال به اینترنت قطع است</h2><p style="color:#94a3b8">به‌محضِ وصل‌شدن، صفحه خودکار ادامه می‌دهد.</p>
            <button onclick="parent.postMessage({nw:'close'},'*')" style="font-family:inherit;border:0;border-radius:12px;padding:10px 18px;background:#6366f1;color:#fff;font-weight:800">ماندن در صفحه</button></div></body></html>`;
        const f = document.createElement('iframe');
        f.id = 'net-watch-offline';
        f.setAttribute('title', 'اتصال قطع است');
        f.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;border:0;z-index:2147483600;opacity:0;transition:opacity .45s;background:#0b1023';
        f.srcdoc = html.replace(/<head>/i, '<head>' + inject);
        document.body.appendChild(f);
        st.ov = f;
        requestAnimationFrame(() => requestAnimationFrame(() => { f.style.opacity = '1'; }));
    }
    function hideOverlay() {
        clearTimeout(st.ovT);
        st.ovSnooze = 0;
        const f = st.ov; if (!f) return;
        st.ov = null;
        f.style.opacity = '0';
        setTimeout(() => f.remove(), 480);
    }
    window.addEventListener('message', e => {
        if (!st.ov || e.source !== st.ov.contentWindow || !e.data) return;
        if (e.data.nw === 'online') { markOk(); hideOverlay(); }
        if (e.data.nw === 'close') {   // «ماندن در صفحه»: اگر قطعی ادامه داشت، ۱۰ دقیقه‌ی دیگر دوباره
            const f = st.ov; st.ov = null; f.style.opacity = '0'; setTimeout(() => f.remove(), 480);
            st.ovSnooze = Date.now(); scheduleOverlay();
        }
    });
    function markOk() { st.lastOk = Date.now(); st.fail = 0; if (st.state === 'off' || st.state === 'noserver') set('ok'); }
    const quiet = () => st.leaving || Date.now() < st.quietUntil;

    async function probe(manual) {
        if (st.busy) { if (manual) st.again = true; return; }   // آزمونِ دیگری در جریان است؛ بعد از آن دوباره
        if (!manual && quiet()) return;
        st.busy = true;
        const ctl = window.AbortController ? new AbortController() : null;
        const to = setTimeout(() => ctl && ctl.abort(), TIMEOUT_MS);
        const t0 = performance.now();
        try {
            // HEAD روی فایلِ ایستا: سبک، بدونِ اجرای PHP و بدونِ گیرکردن پشتِ قفلِ نشستِ درخواست‌های سنگین
            const r = await origFetch(PING + (PING.includes('?') ? '&' : '?') + 'nw=' + Date.now(), {method: 'HEAD', cache: 'no-store', signal: ctl ? ctl.signal : undefined, credentials: 'same-origin'});
            const ms = performance.now() - t0;
            if (!r || (r.status >= 500 && r.status !== 503)) throw new Error('bad');
            markOk();
            const c = navigator.connection || {};
            const slowNet = /^(slow-)?2g$/.test(c.effectiveType || '');
            st.slow = (ms > SLOW_MS || slowNet) ? st.slow + 1 : 0;
            if (st.slow >= SLOWS_WEAK) set('weak');
            else if (st.state === 'weak' && st.slow === 0) set('ok');
        } catch (e) {
            if (st.leaving) return;
            // اگر همین چند لحظه پیش درخواستی از صفحه موفق بوده، این خطا را جدی نگیر
            if (Date.now() - st.lastOk < RETRY_MS * 2) return;
            st.fail++;
            if (st.fail >= FAILS_OFF) set(navigator.onLine === false ? 'off' : 'noserver');
            else setTimeout(() => probe(true), RETRY_MS);   // تا تأیید نشده، پیامی نشان نده
        } finally {
            clearTimeout(to); st.busy = false;
            if (st.again) { st.again = false; setTimeout(() => probe(true), 400); }
        }
    }

    // درخواست‌های خودِ صفحه: موفق => اتصال سالم؛ خطای شبکه => آزمونِ تأییدی (نه پیامِ فوری)
    const origFetch = window.fetch ? window.fetch.bind(window) : null;
    if (origFetch && !window.fetch.__nw) {
        const wrapped = function () {
            return origFetch.apply(null, arguments).then(r => { markOk(); return r; }, e => {
                if (e && e.name !== 'AbortError' && !st.leaving) setTimeout(() => probe(true), 600);
                throw e;
            });
        };
        wrapped.__nw = true;
        window.fetch = wrapped;
    }
    if (window.XMLHttpRequest) {
        const oSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function () {
            this.addEventListener('load', markOk);
            return oSend.apply(this, arguments);
        };
    }
    // رویدادِ offline گاهی لحظه‌ای و اشتباه است؛ با کمی مکث و آزمون تأیید می‌شود
    window.addEventListener('offline', () => setTimeout(() => { if (navigator.onLine === false) { st.fail = FAILS_OFF; set('off'); } }, 2000));
    window.addEventListener('online', () => { st.fail = 0; st.quietUntil = 0; setTimeout(() => probe(true), 800); });
    if (navigator.connection && navigator.connection.addEventListener) navigator.connection.addEventListener('change', () => setTimeout(() => probe(true), 1500));
    // برگشت از پس‌زمینه/خواب: شبکه چند ثانیه طول می‌کشد بیدار شود؛ در این فاصله پیامی نده
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { st.quietUntil = Date.now() + RESUME_GRACE; setTimeout(() => probe(), RESUME_GRACE + 200); } });
    window.addEventListener('pageshow', () => { st.leaving = false; st.quietUntil = Date.now() + RESUME_GRACE; });
    window.addEventListener('pagehide', () => { st.leaving = true; });
    window.addEventListener('beforeunload', () => { st.leaving = true; setTimeout(() => { st.leaving = false; }, 5000); });
    const start = () => {
        st.timer = setInterval(() => {
            if (document.hidden && st.state === 'ok') return;
            if (st.state === 'ok' && Date.now() - st.lastOk < EVERY_MS) return;   // تازه درخواستِ موفقی بوده
            probe();
        }, EVERY_MS / 2);
        setTimeout(() => probe(), RESUME_GRACE + 1000);
        // صفحه‌ی قطعی و Service Worker در زمانِ بیکاری (بعد از بارگذاریِ کاملِ صفحه)؛ سرعتِ سایت را کم نمی‌کند
        const idle = fn => (window.requestIdleCallback ? requestIdleCallback(fn, {timeout: 20000}) : setTimeout(fn, 1500));
        setTimeout(() => idle(() => {
            prefetchOffline();
            // اگر صفحه‌ای در حالِ قطعی باز یا تازه شود، مرورگر به‌جای صفحه‌ی پیش‌فرضش همین صفحه‌ی قطعی را نشان می‌دهد
            try {
                if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1') && !window.NET_WATCH_NO_SW)
                    navigator.serviceWorker.register(ROOT + 'sw.js', {scope: ROOT}).catch(() => {});
            } catch (e) {}
        }), 8000);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.NetWatch = {probe: () => probe(true), state: () => st.state, showOffline: () => { st.offSince = st.offSince || Date.now() - OFFLINE_MS; st.ovSnooze = 0; showOverlay(); }};
})();
