// فایل: idle-guard.js
// خروجِ خودکار بعد از عدمِ فعالیت (مدت در «تنظیمات ← سپر امنیتی»، پیش‌فرض ۳ ساعت).
//  - فقط کارِ واقعیِ کاربر (کلیک، تایپ، لمس، اسکرول) فعالیت حساب می‌شود؛ نظرسنجی‌های پس‌زمینه‌ی صفحه نه.
//  - هر چند دقیقه یک بار (نه با هر حرکت) به سرور خبر می‌دهد؛ چند تبِ باز با هم هماهنگ‌اند (معیار، سرور است).
//  - ۵ دقیقه قبل از خروج هشدار می‌دهد با دکمه‌ی «ادامه می‌دهم».
// پیکربندی: window.IDLE_GUARD = {alive: 'api/alive.php', login: 'index.php?idle=1'}
(function () {
    const CFG = Object.assign({alive: 'api/alive.php', login: 'index.php?idle=1'}, window.IDLE_GUARD || {});
    const PING_EVERY = 120 * 1000, WARN_BEFORE = 5 * 60 * 1000;
    let deadline = Date.now() + 3 * 3600 * 1000, idleMs = 3 * 3600 * 1000;
    let lastInteract = 0, lastPing = 0, pending = false, leaving = false, box = null;
    const origFetch = window.fetch.bind(window);
    const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);

    // forced: مدیر نشست را بسته (پیامِ صفحه‌ی ورود فرق می‌کند)
    function leave(forced) {
        if (leaving) return;
        leaving = true;
        location.href = forced ? CFG.login.replace('idle=1', 'forced=1') : CFG.login;
    }
    function apply(r) {
        if (!r) return;
        if (r.session_expired) return leave(r.forced);
        if (r.ok) { idleMs = r.idle_seconds * 1000; deadline = Date.now() + r.remaining * 1000; tick(); }
    }
    // active=true یعنی «کاربر الان کار کرد»؛ false فقط زمانِ باقی‌مانده را می‌پرسد (برای هماهنگی با تب‌های دیگر)
    function sync(active) {
        if (pending || leaving) return Promise.resolve();
        pending = true;
        if (active) lastPing = Date.now();
        return origFetch(CFG.alive, {credentials: 'same-origin', cache: 'no-store', headers: active ? {'X-User-Activity': '1'} : {}})
            .then(r => r.json().catch(() => null).then(j => (r.status === 401 ? {session_expired: true, forced: !!(j && j.forced) || r.headers.get('X-Session-Expired') === 'forced'} : j)))
            .then(apply).catch(() => {}).finally(() => { pending = false; });
    }
    function interact() {
        lastInteract = Date.now();
        if (box || Date.now() - lastPing > PING_EVERY) sync(true);
    }
    ['mousedown', 'keydown', 'touchstart', 'wheel', 'input', 'change'].forEach(e => document.addEventListener(e, interact, {capture: true, passive: true}));
    window.addEventListener('scroll', interact, {capture: true, passive: true});
    document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(false); });

    function showWarn(left) {
        if (!document.body) return;
        if (!box) {
            box = document.createElement('div');
            box.setAttribute('dir', 'rtl');
            box.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:2147483000;background:#0f172a;color:#f8fafc;border:1px solid #f59e0b;'
                + 'border-radius:16px;padding:14px 18px;box-shadow:0 20px 50px rgba(0,0,0,.35);display:flex;align-items:center;gap:14px;font-family:inherit;max-width:calc(100vw - 32px);font-size:13px';
            box.innerHTML = '<span style="font-size:26px">⏳</span><div><b style="display:block;color:#fbbf24">به‌خاطرِ عدمِ فعالیت به‌زودی از پنل خارج می‌شوید</b>'
                + '<span data-ig-left style="color:#cbd5e1"></span></div><button type="button" style="background:#f59e0b;color:#0f172a;border:0;border-radius:10px;padding:8px 14px;font-weight:900;cursor:pointer;white-space:nowrap;font-family:inherit">ادامه می‌دهم</button>';
            box.querySelector('button').onclick = () => sync(true);
            document.body.appendChild(box);
        }
        const s = Math.max(0, Math.ceil(left / 1000));
        box.querySelector('[data-ig-left]').textContent = 'زمانِ باقی‌مانده: ' + fa(Math.floor(s / 60)) + ':' + fa(String(s % 60).padStart(2, '0'));
    }
    function hideWarn() { if (box) { box.remove(); box = null; } }
    let checking = false;
    function tick() {
        const left = deadline - Date.now();
        if (left <= 0) {
            // شاید در تبِ دیگری کار کرده باشد: قبل از خروج از سرور می‌پرسیم
            if (!checking) { checking = true; sync(false).finally(() => { checking = false; if (deadline - Date.now() <= 0) leave(); }); }
            return;
        }
        if (left <= WARN_BEFORE) showWarn(left); else hideWarn();
    }
    setInterval(tick, 1000);
    setInterval(() => sync(false), 5 * 60 * 1000);

    // هر درخواستی که سرور با «نشست تمام شد» جواب بدهد → صفحه‌ی ورود. درخواستی که بلافاصله بعد از کارِ کاربر
    // (مثلاً کلیکِ «ذخیره») فرستاده شود، خودش فعالیت حساب می‌شود.
    window.fetch = function (input, init) {
        try {
            const url = new URL(typeof input === 'string' ? input : (input && input.url) || '', location.href);
            if (url.origin === location.origin && Date.now() - lastInteract < 2500) {
                init = Object.assign({}, init || {});
                const h = new Headers(init.headers || (typeof input !== 'string' && input.headers) || {});
                h.set('X-User-Activity', '1');
                init.headers = h;
                lastPing = Date.now();
            }
        } catch (e) {}
        return origFetch(input, init).then(res => {
            const ex = res.headers.get('X-Session-Expired');
            if (ex) leave(ex === 'forced');
            return res;
        });
    };
    sync(false);
})();
