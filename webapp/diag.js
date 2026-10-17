/* فایل: webapp/diag.js
 * گزارشِ خطای مینی‌اپ روی خودِ گوشی - عمداً با جاوااسکریپتِ قدیمی (ES5) نوشته شده تا در قدیمی‌ترین
 * WebViewِ بله هم اجرا شود. باید قبل از همه‌ی اسکریپت‌های دیگرِ صفحه بارگذاری شود.
 *  - خطاهای اجرا، اسکریپت‌هایی که بارگذاری نشدند و Promiseهای ردشده را جمع می‌کند
 *  - اگر خطایی بود یا صفحه بعد از چند ثانیه آماده نشد، کادرِ «گزارشِ خطا» را نشان می‌دهد و
 *    همان گزارش را (یک بار) برای سرور می‌فرستد تا در پنل دیده شود
 *  - با ?debug=1 در آدرس، کادرِ اطلاعاتِ دستگاه (اندازه‌ی صفحه، نسخه‌ی مرورگر، …) همیشه نشان داده می‌شود
 */
(function () {
    if (window.__md) return;
    var md = window.__md = { errs: [], sent: false, shown: false, t0: Date.now() };
    var q = location.search || '';
    var DEBUG = /[?&]debug=1/.test(q);

    function short(s, n) { s = String(s == null ? '' : s); return s.length > n ? s.slice(0, n) + '…' : s; }
    function file(u) { u = String(u || ''); var i = u.lastIndexOf('/'); return i >= 0 ? u.slice(i + 1) : u; }
    function add(kind, msg, where) {
        if (md.errs.length >= 15) return;
        md.errs.push({ kind: kind, msg: short(msg, 400), where: short(where, 160), at: Math.round((Date.now() - md.t0) / 100) / 10 });
        if (md.shown) render();
        if (md.loaded) schedule(2000);
    }

    window.addEventListener('error', function (e) {
        var t = e && e.target;
        if (t && t !== window && (t.tagName === 'SCRIPT' || t.tagName === 'LINK')) {
            var src = t.src || t.href || '';
            if (/tapi\.bale\.ai|font-awesome|fonts\.googleapis/.test(src)) return;   // کتابخانه‌های بیرونیِ اختیاری
            add('load', 'فایل بارگذاری نشد', file(src));
            return;
        }
        if (!e || (!e.message && !e.error)) return;
        add('js', e.message || String(e.error), file(e.filename) + ':' + (e.lineno || 0) + ':' + (e.colno || 0));
    }, true);
    window.addEventListener('unhandledrejection', function (e) {
        var r = e && e.reason;
        add('promise', (r && (r.message || r.toString())) || 'Promise rejected', r && r.stack ? short(r.stack.split('\n')[1] || '', 160) : '');
    });

    // صفحه آماده نشد؟ (MiniKit نیامده یا هنوز «در حال آماده‌سازی» است)
    function stuck() {
        if (!window.MiniKit) return 'MiniKit بارگذاری نشد';
        var l = document.getElementById('screen-loading');
        if (l && /\bactive\b/.test(l.className)) return 'صفحه روی «در حال آماده‌سازی» ماند';
        var k = /premium/.test(location.pathname) && document.getElementById('kind-grid');   // فقط صفحه‌ی استعلام (در ثبتِ درخواست بعداً پر می‌شود)
        if (k && !k.children.length) return 'فهرستِ نوعِ وسیله خالی ماند';
        return '';
    }

    function info() {
        var W = null; try { W = window.Bale && window.Bale.WebApp; } catch (e) {}
        var vv = window.visualViewport;
        var d = document.documentElement, b = document.body;
        return {
            ua: navigator.userAgent,
            page: location.pathname,
            bale: W ? ((W.version || '?') + ' / ' + (W.platform || '?')) : 'ندارد',
            win: window.innerWidth + '×' + window.innerHeight,
            bale_vh: W ? (W.viewportHeight + ' / ' + W.viewportStableHeight + (W.isExpanded ? ' (باز)' : '')) : '-',
            vv: vv ? Math.round(vv.width) + '×' + Math.round(vv.height) : '-',
            screen: screen.width + '×' + screen.height + ' @' + (window.devicePixelRatio || 1),
            doc: (d ? d.clientHeight : 0) + ' / body ' + (b ? b.scrollHeight : 0),
            app_h: d && d.style ? d.style.getPropertyValue('--app-h') : '',
            minikit: !!window.MiniKit,
            ver: (document.querySelector('script[src*="mini-kit.js"]') || {}).src ? file(document.querySelector('script[src*="mini-kit.js"]').src) : '-'
        };
    }

    function report() {
        var s = stuck(); var o = info();
        var lines = [];
        for (var i = 0; i < md.errs.length; i++) { var x = md.errs[i]; lines.push('[' + x.kind + ' ' + x.at + 's] ' + x.msg + (x.where ? ' @ ' + x.where : '')); }
        if (s) lines.push('[stuck] ' + s);
        return { errors: lines, info: o };
    }

    function send() {
        if (md.sent) return; md.sent = true;
        try {
            var r = report();
            var m = /[?&](?:t|token)=([^&]+)/.exec(q);
            var body = JSON.stringify({ token: m ? decodeURIComponent(m[1]) : '', page: location.pathname + (DEBUG ? ' (debug)' : ''), errors: r.errors, info: r.info });
            var x = new XMLHttpRequest();
            x.open('POST', '/api/webapp_client_log.php', true);
            x.setRequestHeader('Content-Type', 'application/json');
            x.send(body);
        } catch (e) {}
    }

    // راهِ جایگزین وقتی WebViewِ بله صفحه را اجرا نمی‌کند: همان صفحه در مرورگرِ داخلیِ بله (مثلِ بارگذاریِ مدارک)
    function canOpenLink() { try { return !!(window.Bale && window.Bale.WebApp && typeof window.Bale.WebApp.openLink === 'function' && window.Bale.WebApp.initData); } catch (e) { return false; } }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function render() {
        md.shown = true;
        var box = document.getElementById('md-box');
        if (!box) {
            if (!document.body) return;
            box = document.createElement('div'); box.id = 'md-box';
            box.setAttribute('style', 'position:fixed;left:8px;right:8px;bottom:8px;z-index:2147483646;max-height:60%;overflow:auto;-webkit-overflow-scrolling:touch;' +
                'background:#fff;color:#111;border:2px solid #dc2626;border-radius:14px;padding:10px 12px;font:12px/1.7 Tahoma,sans-serif;direction:rtl;text-align:right;box-shadow:0 10px 30px rgba(0,0,0,.45)');
            document.body.appendChild(box);
        }
        var r = report(), o = r.info, h = '';
        var bad = r.errors.length > 0;
        h += '<div style="font-weight:bold;font-size:13px;color:' + (bad ? '#dc2626' : '#2563eb') + ';margin-bottom:4px">' + (bad ? '⚠️ خطا در اجرای مینی‌اپ' : 'ℹ️ اطلاعاتِ دستگاه') + '</div>';
        if (bad) {
            h += '<div style="color:#444;margin-bottom:6px">از همین کادر عکس بگیرید و برای پشتیبانی بفرستید (گزارش خودکار هم ارسال شد).</div>';
            h += '<div style="direction:ltr;text-align:left;background:#fef2f2;border-radius:8px;padding:6px 8px;font:11px/1.6 monospace;word-break:break-all">' + esc(r.errors.join('\n')).replace(/\n/g, '<br>') + '</div>';
        }
        h += '<div style="direction:ltr;text-align:left;margin-top:6px;font:10.5px/1.6 monospace;color:#333;word-break:break-all">';
        for (var k in o) if (o.hasOwnProperty(k)) h += '<b>' + k + '</b>: ' + esc(o[k]) + '<br>';
        h += '</div><div style="margin-top:8px;display:flex">' +
            '<button id="md-reload" style="flex:1;margin-left:6px;padding:9px;border:0;border-radius:10px;background:#2563eb;color:#fff;font:bold 12px Tahoma">🔄 بارگذاریِ دوباره</button>' +
            '<button id="md-close" style="flex:1;padding:9px;border:0;border-radius:10px;background:#e5e7eb;color:#111;font:bold 12px Tahoma">بستن</button></div>' +
            (canOpenLink() ? '<button id="md-browser" style="display:block;width:100%;margin-top:6px;padding:10px;border:0;border-radius:10px;background:#059669;color:#fff;font:bold 12px Tahoma">🌐 باز کردن در مرورگرِ بله</button>' : '');
        box.innerHTML = h;
        document.getElementById('md-close').onclick = function () { box.parentNode.removeChild(box); md.shown = false; };
        var ob = document.getElementById('md-browser');
        if (ob) ob.onclick = function () { try { window.Bale.WebApp.openLink(location.href.replace(/([?&])debug=1&?/, '$1').replace(/[?&]$/, '')); } catch (e) {} };
        document.getElementById('md-reload').onclick = function () {
            var u = location.href.replace(/([?&])_r=\d+&?/, '$1').replace(/[?&]$/, '');
            location.replace(u + (u.indexOf('?') >= 0 ? '&' : '?') + '_r=' + Date.now());
        };
    }

    var timer = null;
    function schedule(ms) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(check, ms);
    }
    function check() {
        timer = null;
        // کادر فقط وقتی صفحه واقعاً از کار افتاده (یا debug)؛ خطاهای دیگر فقط بی‌صدا گزارش می‌شوند
        var s = !!stuck();
        if (s || DEBUG) render();
        if (s || DEBUG || md.errs.length) send();
    }
    // اولین بررسی بعد از بارگذاری؛ خطاهای اجرا هم خودشان بررسی را جلو می‌اندازند
    window.addEventListener('load', function () { md.loaded = true; schedule(6000); });
    window.mdShow = function () { render(); };
})();
