// فایل: net-watch.js
// هشدارِ اینترنت: قطع‌شدن (رویدادِ offline یا جواب‌ندادنِ سرور) و ضعیف‌بودنِ اتصال (تأخیرِ زیاد یا شبکه‌ی ۲G)
// با یک نوارِ کوچک بالای صفحه؛ با برگشتنِ اتصال چند ثانیه «اتصال برقرار شد» نشان داده می‌شود.
// پنلِ شرکت‌ها: window.NET_WATCH_PING = '../api/server_time.php'
(function () {
    'use strict';
    if (window.NetWatch) return;
    const PING = window.NET_WATCH_PING || 'api/server_time.php';
    const SLOW_MS = 2500, TIMEOUT_MS = 9000, EVERY_MS = 25000;
    const st = {state: 'ok', slow: 0, fail: 0, el: null, timer: null, busy: false, hideT: null};
    const css = `
    #net-watch{position:fixed;top:12px;left:50%;transform:translate(-50%,-140%);z-index:2147483000;direction:rtl;display:flex;align-items:center;gap:10px;
        padding:9px 16px 9px 12px;border-radius:999px;color:#fff;font-weight:800;font-size:12px;line-height:1.6;font-family:inherit;box-shadow:0 14px 30px -12px rgba(15,23,42,.55);
        transition:transform .35s cubic-bezier(.2,.9,.3,1.2),background .3s;pointer-events:auto;max-width:calc(100vw - 24px)}
    #net-watch.show{transform:translate(-50%,0)}
    #net-watch .nw-ic{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.2);flex:none;position:relative}
    #net-watch.off{background:linear-gradient(135deg,#e11d48,#be123c)}
    #net-watch.weak{background:linear-gradient(135deg,#f59e0b,#ea580c)}
    #net-watch.back{background:linear-gradient(135deg,#10b981,#0d9488)}
    #net-watch.off .nw-ic::after,#net-watch.weak .nw-ic::after{content:'';pointer-events:none;position:absolute;inset:-4px;border-radius:50%;border:2px solid rgba(255,255,255,.55);animation:nwPulse 1.6s ease-out infinite}
    @keyframes nwPulse{from{transform:scale(.85);opacity:1}to{transform:scale(1.5);opacity:0}}
    #net-watch small{display:block;font-weight:600;font-size:10.5px;opacity:.85}
    #net-watch button{border:0;background:rgba(255,255,255,.2);color:#fff;border-radius:999px;padding:4px 10px;font-weight:800;font-size:10.5px;font-family:inherit;cursor:pointer;margin-right:4px}
    `;
    function ensure() {
        if (st.el) return st.el;
        const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
        st.el = document.createElement('div'); st.el.id = 'net-watch'; st.el.setAttribute('role', 'status');
        document.body.appendChild(st.el);
        return st.el;
    }
    const MSG = {
        off: ['fa-wifi', 'اینترنت قطع است', 'تا برگشتنِ اتصال، تغییرات ذخیره نمی‌شوند؛ صفحه را نبندید.'],
        noserver: ['fa-server', 'ارتباط با سرور برقرار نیست', 'اینترنت یا سرور در دسترس نیست؛ دوباره امتحان می‌کنیم…'],
        weak: ['fa-signal', 'اینترنت ضعیف است', 'بارگذاری و ذخیره ممکن است کند شود.'],
        back: ['fa-circle-check', 'اتصال برقرار شد', ''],
    };
    function show(kind) {
        const el = ensure();
        clearTimeout(st.hideT);
        const [ic, t, sub] = MSG[kind];
        el.className = (kind === 'noserver' ? 'off' : kind) + ' show';
        el.innerHTML = `<span class="nw-ic"><i class="fas ${ic}"></i></span><span>${t}${sub ? `<small>${sub}</small>` : ''}</span>${kind !== 'back' ? '<button type="button">بررسیِ دوباره</button>' : ''}`;
        const b = el.querySelector('button'); if (b) b.onclick = () => probe();
        if (kind === 'back') st.hideT = setTimeout(() => el.classList.remove('show'), 3500);
    }
    function set(state) {
        if (state === st.state) return;
        const was = st.state;
        st.state = state;
        if (state === 'ok') { if (was !== 'ok') show('back'); }
        else show(state);
    }
    async function probe() {
        if (st.busy) return;
        if (navigator.onLine === false) { set('off'); return; }
        st.busy = true;
        const ctl = window.AbortController ? new AbortController() : null;
        const to = setTimeout(() => ctl && ctl.abort(), TIMEOUT_MS);
        const t0 = performance.now();
        try {
            const r = await fetch(PING + (PING.includes('?') ? '&' : '?') + 'nw=' + Date.now(), {cache: 'no-store', signal: ctl ? ctl.signal : undefined, credentials: 'same-origin'});
            await r.text();
            const ms = performance.now() - t0;
            st.fail = 0;
            const c = navigator.connection || {};
            const slowNet = /(^|-)2g$/.test(c.effectiveType || '') || (c.downlink && c.downlink < 0.35);
            st.slow = (ms > SLOW_MS || slowNet) ? st.slow + 1 : 0;
            set(st.slow >= 2 ? 'weak' : 'ok');
        } catch (e) {
            st.fail++;
            set(navigator.onLine === false ? 'off' : (st.fail >= 2 ? 'noserver' : (st.state === 'ok' ? 'ok' : st.state)));
            if (st.fail === 1) setTimeout(probe, 3000);   // یک خطا ممکن است گذرا باشد؛ زود دوباره امتحان
        } finally { clearTimeout(to); st.busy = false; }
    }
    // خطای شبکه در درخواست‌های خودِ صفحه => بررسیِ فوری
    if (window.fetch && !window.fetch.__nw) {
        const of = window.fetch;
        const wrapped = function () {
            return of.apply(this, arguments).catch(e => { if (!e || e.name !== 'AbortError') setTimeout(probe, 300); throw e; });
        };
        wrapped.__nw = true;
        window.fetch = wrapped;
    }
    window.addEventListener('offline', () => set('off'));
    window.addEventListener('online', () => { st.fail = 0; probe(); });
    if (navigator.connection && navigator.connection.addEventListener) navigator.connection.addEventListener('change', () => probe());
    document.addEventListener('visibilitychange', () => { if (!document.hidden) probe(); });
    const start = () => { if (navigator.onLine === false) set('off'); st.timer = setInterval(() => { if (!document.hidden || st.state !== 'ok') probe(); }, EVERY_MS); setTimeout(probe, 4000); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.NetWatch = {probe, state: () => st.state};
})();
