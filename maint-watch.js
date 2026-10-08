// فایل: maint-watch.js
// «حالتِ به‌روزرسانی» سمتِ مرورگر (پنل، پورتالِ شرکت‌ها، وب‌اپ):
//  - هر پاسخی که سرور با X-Maintenance (۵۰۳) بدهد => رفتن به صفحه‌ی «در حالِ به‌روزرسانی» (و برگشتِ خودکار بعد از پایان)
//  - هر دقیقه وضعیت پرسیده می‌شود: اگر بستنِ سایت زمان‌بندی شده، نوارِ شمارشِ معکوس تا کاربر کارش را ذخیره کند
//  - برای مدیر کل: نوارِ «فقط شما سایت را می‌بینید»
(function () {
    'use strict';
    if (window.MaintWatch) return;
    var base = (window.MAINT_BASE || '/').replace(/\/?$/, '/');
    var STATUS = base + 'api/maint_status.php', PAGE = base + 'errors/maintenance.php';
    var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
    var gone = false;
    function goPage() {
        if (gone) return; gone = true;
        location.href = PAGE + '?back=' + encodeURIComponent(location.pathname + location.search + location.hash);
    }
    // همه‌ی درخواست‌های fetch: پاسخِ «در حالِ به‌روزرسانی» یعنی صفحه عوض شود
    if (window.fetch) {
        var orig = window.fetch;
        window.fetch = function () {
            return orig.apply(this, arguments).then(function (r) {
                // قبل از رفتن، یک بار وضعیت پرسیده می‌شود (پاسخِ ۵۰۳ِ یک API برای مدیر کل نباید او را بیرون بیندازد)
                try { if (r && r.status === 503 && r.headers.get('X-Maintenance') === '1') check(); } catch (e) {}
                return r;
            });
        };
    }
    var bar = null, tick = null, left = 0;
    function css() {
        if (document.getElementById('mw-css')) return;
        var s = document.createElement('style'); s.id = 'mw-css';
        s.textContent = '.mw-bar{position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:2147483600;display:flex;align-items:center;gap:10px;max-width:calc(100% - 24px);'
            + 'padding:9px 16px;border-radius:16px;font:700 12.5px Vazir,Tahoma,sans-serif;direction:rtl;color:#fff;box-shadow:0 18px 40px -16px rgba(15,23,42,.6);animation:mwIn .4s cubic-bezier(.2,.9,.3,1)}'
            + '.mw-bar.warn{background:linear-gradient(120deg,#b45309,#dc2626)}.mw-bar.admin{background:linear-gradient(120deg,#4338ca,#0e7490)}'
            + '.mw-bar b{font-size:15px;font-variant-numeric:tabular-nums;background:rgba(255,255,255,.18);border-radius:10px;padding:2px 10px}'
            + '.mw-bar a,.mw-bar button{color:#fff;background:rgba(255,255,255,.18);border:0;border-radius:10px;padding:4px 10px;font:inherit;cursor:pointer;text-decoration:none}'
            + '.mw-dot{width:9px;height:9px;border-radius:50%;background:#fde68a;box-shadow:0 0 0 0 rgba(253,230,138,.8);animation:mwPing 1.4s infinite;flex-shrink:0}'
            + '@keyframes mwIn{from{transform:translate(-50%,-20px);opacity:0}}@keyframes mwPing{70%{box-shadow:0 0 0 9px rgba(253,230,138,0)}100%{box-shadow:0 0 0 0 rgba(253,230,138,0)}}';
        document.head.appendChild(s);
    }
    function show(kind, html) {
        css();
        if (!bar) { bar = document.createElement('div'); document.body.appendChild(bar); }
        bar.className = 'mw-bar ' + kind;
        bar.innerHTML = html;
        var x = bar.querySelector('[data-x]'); if (x) x.onclick = function () { hide(); hidden = true; };
    }
    var hidden = false;
    function hide() { if (bar) { bar.remove(); bar = null; } clearInterval(tick); tick = null; }
    function mmss(s) { s = Math.max(0, Math.round(s)); return fa(Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2)); }
    function apply(d) {
        if (!d || !d.ok) return;
        if (d.blocked) return goPage();
        if (d.admin && d.on) { if (!hidden) show('admin', '<span class="mw-dot"></span><span>حالتِ به‌روزرسانی روشن است؛ فقط مدیر کل سایت را می‌بیند.</span><button data-x>باشه</button>'); return; }
        if (d.scheduled && d.seconds_left > 0) {
            left = d.seconds_left;
            var draw = function () {
                if (left <= 0) { hide(); check(); return; }
                show('warn', '<span class="mw-dot"></span><span>' + (d.admin ? 'سایت برای بقیه بسته می‌شود تا' : 'سایت برای به‌روزرسانی بسته می‌شود تا') + '</span><b>' + mmss(left) + '</b><span>' + (d.admin ? '' : 'کارتان را ذخیره کنید.') + '</span>');
            };
            draw();
            clearInterval(tick); tick = setInterval(function () { left--; draw(); }, 1000);
            return;
        }
        hide();
    }
    function check() {
        try {
            fetch(STATUS, {cache: 'no-store', credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(apply).catch(function () {});
        } catch (e) {}
    }
    function start() { check(); setInterval(function () { if (!document.hidden) check(); }, 60000); document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); }); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.MaintWatch = {check: check};
})();
