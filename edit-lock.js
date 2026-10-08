// فایل: edit-lock.js
// هشدارِ «همکارِ دیگری همین الان این را باز کرده» (api/edit_lock.php). هر عنصرِ دیده‌شده با data-lock="کلید" (مثلاً plate:12، case:5، life:30)
// حضورِ این کاربر را اعلام می‌کند و اگر کسِ دیگری هم همان را باز کرده باشد، داخلِ همان عنصر یک نوارِ هشدار می‌گیرد.
(function () {
    'use strict';
    if (window.EditLock) return;
    var API = (window.EDIT_LOCK_API || 'api/edit_lock.php');
    var mine = [], busy = false, timer = null;
    function css() {
        if (document.getElementById('el-css')) return;
        var s = document.createElement('style'); s.id = 'el-css';
        s.textContent = '.el-warn{display:flex;align-items:center;gap:8px;margin:0 0 10px;padding:8px 12px;border-radius:14px;background:linear-gradient(120deg,#fff7ed,#fef3c7);border:1px solid #fcd34d;color:#92400e;font-size:12px;font-weight:700;line-height:1.9;animation:elIn .3s ease}'
            + '.el-warn i{width:26px;height:26px;border-radius:9px;background:#f59e0b;color:#fff;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;font-style:normal}'
            + '@keyframes elIn{from{opacity:0;transform:translateY(-4px)}}';
        document.head.appendChild(s);
    }
    function visible(el) { return !!(el.offsetParent || el.getClientRects().length); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
    function beat() {
        if (busy || document.hidden) return;
        var els = Array.prototype.filter.call(document.querySelectorAll('[data-lock]'), visible);
        var keys = [];
        els.forEach(function (e) { var k = e.getAttribute('data-lock'); if (k && keys.indexOf(k) < 0) keys.push(k); });
        var gone = mine.filter(function (k) { return keys.indexOf(k) < 0; });
        mine = keys;
        if (!keys.length && !gone.length) return;
        busy = true;
        fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({keys: keys, release: gone})})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                css();
                els.forEach(function (el) {
                    var o = (d.others || {})[el.getAttribute('data-lock')] || [];
                    var html = o.length ? '<div class="el-warn"><i>!</i><span><b>' + o.map(esc).join('، ') + '</b> همین الان همین مورد را باز کرده' + (o.length > 1 ? 'اند' : '') + '؛ برای اینکه تغییرها روی هم نیفتد، قبل از ذخیره هماهنگ کنید.</span></div>' : '';
                    if (el.getAttribute('data-lock-html') !== html) { el.innerHTML = html; el.setAttribute('data-lock-html', html); }
                });
            })
            .catch(function () {})
            .then(function () { busy = false; });
    }
    function soon() { clearTimeout(timer); timer = setTimeout(beat, 700); }
    document.addEventListener('click', soon, true);
    setInterval(beat, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) beat(); });
    window.addEventListener('pagehide', function () {
        if (!mine.length || !navigator.sendBeacon) return;
        try { navigator.sendBeacon(API, new Blob([JSON.stringify({keys: [], release: mine})], {type: 'application/json'})); } catch (e) {}
    });
    window.EditLock = {beat: beat};
})();
