// فایل: upload-kit.js
// ابزارِ مشترکِ بارگذاری در مرورگر (پنل، پرتالِ شرکت‌ها و مینی‌اپ) — بدونِ هیچ پردازشِ سنگینی روی هاست:
//   - انتخابِ صفحه‌های PDF: هر جا فایلِ PDFِ چندصفحه‌ای انتخاب شود، همه‌ی صفحه‌ها با پیش‌نمایش نشان داده می‌شود
//     و فقط صفحه‌های انتخاب‌شده فرستاده می‌شود (جایگزینِ دکمه‌ی «جداسازی PDF»). با data-uk-skip روی input خاموش می‌شود.
//   - عکسِ HEIC آیفون پیش از ارسال به JPG تبدیل می‌شود.
//   - ارسالِ گروهی: زیپ (تودرتو)، پوشه و چند فایل باز می‌شود، PDFها صفحه‌به‌صفحه جدا و فایل‌ها ۱ تا N نام‌گذاری می‌شوند.
//   - صفِ ارسال در پس‌زمینه با نوارِ پیشرفت (مثلِ آپلودِ آهنگ): با رفتن به بخشِ دیگرِ همین صفحه ادامه دارد.
// کتابخانه‌ها (pdf.js، pdf-lib، JSZip، heic2any) از پوشه‌ی vendor-js و فقط هنگامِ نیاز بارگذاری می‌شوند.
(function () {
    'use strict';
    if (window.UploadKit) return;

    var SCRIPT = document.currentScript;
    var BASE = (function () {
        try { return new URL(SCRIPT.src, location.href).href.replace(/[^\/]*$/, '') + 'vendor-js/'; } catch (e) { return '/vendor-js/'; }
    })();
    var LV = '1';
    var LIBS = {
        pdfjs: {file: 'pdf.min.js', ok: function () { return window.pdfjsLib; },
                init: function () { window.pdfjsLib.GlobalWorkerOptions.workerSrc = BASE + 'pdf.worker.min.js?v=' + LV; }},
        pdflib: {file: 'pdf-lib.min.js', ok: function () { return window.PDFLib; }},
        jszip: {file: 'jszip.min.js', ok: function () { return window.JSZip; }},
        heic: {file: 'heic2any.min.js', ok: function () { return window.heic2any; }}
    };
    var loading = {};
    function ensure(name) {
        var L = LIBS[name];
        if (L.ok()) return Promise.resolve(L.ok());
        if (loading[name]) return loading[name];
        loading[name] = new Promise(function (res, rej) {
            var s = document.createElement('script');
            s.src = BASE + L.file + '?v=' + LV;
            s.onload = function () { if (L.init) L.init(); res(L.ok()); };
            s.onerror = function () { delete loading[name]; rej(new Error('بارگذاریِ ابزارِ ' + name + ' ممکن نشد (اینترنت را بررسی کنید).')); };
            document.head.appendChild(s);
        });
        return loading[name];
    }

    // ---------------- کمکی‌ها
    var faD = function (v) { return String(v == null ? '' : v).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
    function ext(name) { var m = /\.([A-Za-z0-9]{1,10})\s*$/.exec(String(name || '')); return m ? m[1].toLowerCase() : ''; }
    function base(name) { return String(name || '').replace(/^.*[\/\\]/, '').replace(/\.[A-Za-z0-9]{1,10}\s*$/, ''); }
    function isPdf(f) { return f && (ext(f.name) === 'pdf' || f.type === 'application/pdf'); }
    function isZip(f) { return f && (ext(f.name) === 'zip' || /zip/.test(f.type || '')); }
    function isHeic(f) { return f && (/^(heic|heif)$/.test(ext(f.name)) || /image\/hei[cf]/.test(f.type || '')); }
    function isImg(f) { return f && /^(jpe?g|png|webp|gif|bmp)$/.test(ext(f.name)); }
    var MIME = {pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif', bmp: 'image/bmp', zip: 'application/zip'};
    function mkFile(blob, name, type) {
        type = type || MIME[ext(name)] || blob.type || 'application/octet-stream';
        try { return new File([blob], name, {type: type, lastModified: Date.now()}); }
        catch (e) { var b = new Blob([blob], {type: type}); b.name = name; b.lastModified = Date.now(); return b; }
    }
    function readBuf(file) {
        if (file.arrayBuffer) return file.arrayBuffer();
        return new Promise(function (res, rej) { var r = new FileReader(); r.onload = function () { res(r.result); }; r.onerror = rej; r.readAsArrayBuffer(file); });
    }
    // مرتب‌سازیِ طبیعی (۲ پیش از ۱۰)
    var collator = (window.Intl && Intl.Collator) ? new Intl.Collator('fa', {numeric: true, sensitivity: 'base'}) : null;
    function natCmp(a, b) { return collator ? collator.compare(a, b) : (a < b ? -1 : a > b ? 1 : 0); }
    function canvasBlob(c, type, q) { return new Promise(function (res) { if (c.toBlob) c.toBlob(res, type, q); else { var d = atob(c.toDataURL(type, q).split(',')[1]), a = new Uint8Array(d.length); for (var i = 0; i < d.length; i++) a[i] = d.charCodeAt(i); res(new Blob([a], {type: type})); } }); }

    // ---------------- PDF
    function openPdf(buf) {
        return ensure('pdfjs').then(function (lib) {
            return lib.getDocument({data: new Uint8Array(buf.slice(0)), isEvalSupported: false, disableFontFace: false, useSystemFonts: true}).promise;
        });
    }
    function pageCount(file) {
        return readBuf(file).then(openPdf).then(function (doc) { var n = doc.numPages; doc.destroy(); return n; });
    }
    function renderPage(doc, n, maxW, maxH) {
        return doc.getPage(n).then(function (page) {
            var v1 = page.getViewport({scale: 1});
            var scale = Math.min(maxW / v1.width, (maxH || 1e9) / v1.height);
            var vp = page.getViewport({scale: scale});
            var c = document.createElement('canvas');
            c.width = Math.max(1, Math.round(vp.width)); c.height = Math.max(1, Math.round(vp.height));
            var ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
            return page.render({canvasContext: ctx, viewport: vp}).promise.then(function () { page.cleanup(); return {canvas: c, w: v1.width, h: v1.height}; });
        });
    }
    // صفحه‌های idx (از صفر) از یک PDF: اول کپیِ دقیقِ صفحه‌ها با pdf-lib؛ PDFِ رمزدار/خراب => عکسِ صفحه‌ها در یک PDFِ تازه
    function extractPages(buf, idx, pdfDoc) {
        return ensure('pdflib').then(function (P) {
            return P.PDFDocument.load(buf.slice(0), {ignoreEncryption: true, updateMetadata: false}).then(function (src) {
                if (src.isEncrypted) throw new Error('enc');
                return P.PDFDocument.create().then(function (out) {
                    return out.copyPages(src, idx).then(function (pages) { pages.forEach(function (p) { out.addPage(p); }); return out.save(); });
                });
            }).catch(function () { return rasterPages(buf, idx, pdfDoc); });
        });
    }
    function rasterPages(buf, idx, pdfDoc) {
        var P = window.PDFLib;
        return (pdfDoc ? Promise.resolve(pdfDoc) : openPdf(buf)).then(function (doc) {
            return P.PDFDocument.create().then(function (out) {
                var chain = Promise.resolve();
                idx.forEach(function (i) {
                    chain = chain.then(function () { return renderPage(doc, i + 1, 1800, 2400); }).then(function (r) {
                        return canvasBlob(r.canvas, 'image/jpeg', 0.85).then(readBuf).then(function (jb) {
                            return out.embedJpg(jb).then(function (img) { var pg = out.addPage([r.w, r.h]); pg.drawImage(img, {x: 0, y: 0, width: r.w, height: r.h}); });
                        });
                    });
                });
                return chain.then(function () { return out.save(); });
            });
        });
    }

    // ---------------- استایل
    var cssDone = false;
    function css() {
        if (cssDone) return; cssDone = true;
        var s = document.createElement('style');
        s.textContent = [
            '.uk-ov{position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.62);display:flex;align-items:center;justify-content:center;padding:12px;direction:rtl;font-family:inherit}',
            '.uk-box{background:#fff;border-radius:20px;width:min(1100px,100%);max-height:94vh;display:flex;flex-direction:column;box-shadow:0 30px 80px -20px rgba(0,0,0,.5);overflow:hidden}',
            '.uk-hd{padding:14px 18px;border-bottom:1px solid #eef2f7;display:flex;flex-wrap:wrap;gap:8px;align-items:center}',
            '.uk-hd b{font-size:14px;color:#0f172a}.uk-hd small{color:#64748b;font-size:11px;font-weight:700}',
            '.uk-grid{padding:14px;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;background:#f8fafc;flex:1}',
            '.uk-pg{position:relative;background:#fff;border:3px solid #e2e8f0;border-radius:14px;padding:6px;cursor:pointer;transition:.15s;user-select:none}',
            '.uk-pg:hover{border-color:#a5b4fc}.uk-pg.on{border-color:#4f46e5;box-shadow:0 8px 20px -10px rgba(79,70,229,.6)}',
            '.uk-pg .uk-th{height:180px;display:flex;align-items:center;justify-content:center;background:#f1f5f9;border-radius:9px;overflow:hidden}',
            '.uk-pg canvas{max-width:100%;max-height:180px;display:block;box-shadow:0 1px 4px rgba(0,0,0,.15)}',
            '.uk-pg .uk-n{display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:11px;font-weight:800;color:#334155}',
            '.uk-ck{width:22px;height:22px;border-radius:50%;border:2px solid #cbd5e1;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:12px;background:#fff}',
            '.uk-pg.on .uk-ck{background:#4f46e5;border-color:#4f46e5}',
            '.uk-zm{position:absolute;top:10px;left:10px;width:28px;height:28px;border-radius:9px;border:0;background:rgba(15,23,42,.55);color:#fff;cursor:pointer;font-size:12px}',
            '.uk-ft{padding:12px 18px;border-top:1px solid #eef2f7;display:flex;flex-wrap:wrap;gap:8px;align-items:center}',
            '.uk-b{border:0;border-radius:11px;padding:8px 14px;font:800 12px inherit;cursor:pointer;background:#f1f5f9;color:#334155}',
            '.uk-b.p{background:linear-gradient(120deg,#4f46e5,#7c3aed);color:#fff}.uk-b:disabled{opacity:.5;cursor:default}',
            '.uk-big{position:fixed;inset:0;z-index:2147483001;background:rgba(2,6,23,.85);display:flex;align-items:center;justify-content:center;padding:16px;cursor:zoom-out}',
            '.uk-big canvas{max-width:100%;max-height:100%;background:#fff;border-radius:6px}',
            '.uk-busy{display:flex;align-items:center;gap:10px;padding:18px 22px;background:#fff;border-radius:16px;font:800 12px inherit;color:#334155;direction:rtl}',
            '.uk-spin{width:20px;height:20px;border-radius:50%;border:3px solid #c7d2fe;border-top-color:#4f46e5;animation:ukspin .8s linear infinite}',
            '@keyframes ukspin{to{transform:rotate(360deg)}}',
            '#uk-pill{position:fixed;left:14px;bottom:14px;z-index:2147482000;display:flex;align-items:center;gap:8px;padding:9px 15px;border-radius:999px;border:0;cursor:pointer;background:linear-gradient(135deg,#0f766e,#4338ca);color:#fff;font:800 11px inherit;box-shadow:0 10px 30px -8px rgba(15,118,110,.6);direction:rtl}',
            '#uk-pill .uk-pb{width:70px;height:5px;border-radius:9px;background:rgba(255,255,255,.25);overflow:hidden;display:inline-block}#uk-pill .uk-pb i{display:block;height:100%;background:#22d3ee;transition:width .3s}',
            '#uk-panel{position:fixed;left:14px;bottom:62px;z-index:2147482000;width:min(380px,calc(100vw - 28px));max-height:60vh;overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 20px 50px -15px rgba(0,0,0,.35);padding:12px;direction:rtl;font-size:11px}',
            '#uk-panel .uk-row{display:flex;align-items:center;gap:8px;padding:5px 0;border-top:1px solid #f1f5f9}',
            '#uk-panel .uk-bar{height:7px;border-radius:9px;background:#e0e7ff;overflow:hidden}#uk-panel .uk-bar i{display:block;height:100%;border-radius:9px;transition:width .3s}'
        ].join('\n');
        document.head.appendChild(s);
    }
    function busy(text) {
        css();
        var ov = document.createElement('div');
        ov.className = 'uk-ov';
        ov.innerHTML = '<div class="uk-busy"><span class="uk-spin"></span><span class="uk-bt">' + esc(text) + '</span></div>';
        document.body.appendChild(ov);
        return {set: function (t) { var b = ov.querySelector('.uk-bt'); if (b) b.textContent = t; }, close: function () { ov.remove(); }};
    }

    // ---------------- پنجره‌ی انتخابِ صفحه‌ها
    // خروجی: همان فایل (همه‌ی صفحه‌ها)، فایلِ تازه با صفحه‌های انتخاب‌شده، یا null (انصراف)
    function pickPages(file, opts) {
        opts = opts || {};
        var bz = busy('در حالِ خواندنِ صفحه‌های «' + file.name + '»…');
        var buf, doc;
        return readBuf(file).then(function (b) { buf = b; return openPdf(b); }).then(function (d) {
            doc = d; bz.close();
            if (doc.numPages <= 1) { doc.destroy(); return file; }
            return showPicker(file, buf, doc, opts);
        }, function (e) { bz.close(); if (opts.strict) throw e; return file; });   // PDFِ ناخوانا: همان فایل فرستاده می‌شود
    }
    function showPicker(file, buf, doc, opts) {
        css();
        var n = doc.numPages, sel = {};
        for (var i = 1; i <= n; i++) sel[i] = opts.defaultNone ? false : true;
        var ov = document.createElement('div');
        ov.className = 'uk-ov';
        var cards = '';
        for (var k = 1; k <= n; k++) cards += '<div class="uk-pg' + (sel[k] ? ' on' : '') + '" data-n="' + k + '"><button type="button" class="uk-zm" data-zoom="' + k + '" title="بزرگ‌نمایی">⤢</button><div class="uk-th"><span class="uk-spin"></span></div><div class="uk-n"><span>صفحه‌ی ' + faD(k) + '</span><span class="uk-ck">✓</span></div></div>';
        ov.innerHTML = '<div class="uk-box"><div class="uk-hd"><div style="flex:1;min-width:200px"><b>کدام صفحه‌ها فرستاده شود؟</b><br><small dir="auto">' + esc(file.name) + ' · ' + faD(n) + ' صفحه' + (opts.title ? ' · ' + esc(opts.title) : '') + '</small></div>'
            + '<button type="button" class="uk-b" data-a="all">همه</button><button type="button" class="uk-b" data-a="none">هیچ‌کدام</button><button type="button" class="uk-b" data-a="inv">برعکس</button></div>'
            + '<div class="uk-grid">' + cards + '</div>'
            + '<div class="uk-ft"><small style="color:#64748b;font-weight:700;flex:1">روی هر صفحه بزنید تا انتخاب یا حذف شود.</small><button type="button" class="uk-b" data-a="cancel">انصراف</button><button type="button" class="uk-b p" data-a="ok"></button></div></div>';
        document.body.appendChild(ov);
        var okBtn = ov.querySelector('[data-a="ok"]');
        function paint() {
            var c = 0; for (var j = 1; j <= n; j++) if (sel[j]) c++;
            ov.querySelectorAll('.uk-pg').forEach(function (el) { el.classList.toggle('on', !!sel[el.dataset.n]); });
            okBtn.disabled = !c;
            okBtn.textContent = c === n ? 'فرستادنِ همه‌ی ' + faD(n) + ' صفحه' : 'فرستادنِ ' + faD(c) + ' صفحه‌ی انتخاب‌شده';
        }
        paint();
        // پیش‌نمایش‌ها یکی‌یکی و فقط وقتی دیده می‌شوند ساخته می‌شوند (PDFهای صدصفحه‌ای هم سبک می‌مانند)
        var queue = [], running = false, closed = false;
        function pump() {
            if (running || closed) return;
            var el = queue.shift(); if (!el) return;
            running = true;
            renderPage(doc, Number(el.dataset.n), 260, 360).then(function (r) {
                var th = el.querySelector('.uk-th'); th.innerHTML = ''; th.appendChild(r.canvas);
            }).catch(function () { el.querySelector('.uk-th').textContent = '—'; }).then(function () { running = false; pump(); });
        }
        var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (ents) {
            ents.forEach(function (e) { if (e.isIntersecting && !e.target._q) { e.target._q = 1; queue.push(e.target); io.unobserve(e.target); } });
            pump();
        }, {root: ov.querySelector('.uk-grid'), rootMargin: '300px'}) : null;
        ov.querySelectorAll('.uk-pg').forEach(function (el) { if (io) io.observe(el); else { queue.push(el); } });
        if (!io) pump();

        return new Promise(function (resolve) {
            function done(v) { closed = true; if (io) io.disconnect(); ov.remove(); document.removeEventListener('keydown', onKey, true); resolve(v); }
            function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); done(null); } else if (e.key === 'Enter' && !okBtn.disabled) { e.preventDefault(); okBtn.click(); } }
            document.addEventListener('keydown', onKey, true);
            ov.addEventListener('click', function (e) {
                var z = e.target.closest('[data-zoom]');
                if (z) {
                    e.stopPropagation();
                    var big = document.createElement('div'); big.className = 'uk-big'; big.innerHTML = '<span class="uk-spin"></span>';
                    big.onclick = function () { big.remove(); };
                    document.body.appendChild(big);
                    renderPage(doc, Number(z.dataset.zoom), Math.min(1600, window.innerWidth * 1.5), window.innerHeight * 1.5).then(function (r) { big.innerHTML = ''; big.appendChild(r.canvas); });
                    return;
                }
                var pg = e.target.closest('.uk-pg');
                if (pg) { sel[pg.dataset.n] = !sel[pg.dataset.n]; paint(); return; }
                var a = e.target.closest('[data-a]'); if (!a) return;
                var act = a.dataset.a, j;
                if (act === 'all') { for (j = 1; j <= n; j++) sel[j] = true; paint(); }
                else if (act === 'none') { for (j = 1; j <= n; j++) sel[j] = false; paint(); }
                else if (act === 'inv') { for (j = 1; j <= n; j++) sel[j] = !sel[j]; paint(); }
                else if (act === 'cancel') { doc.destroy(); done(null); }
                else if (act === 'ok') {
                    var idx = []; for (j = 1; j <= n; j++) if (sel[j]) idx.push(j - 1);
                    if (idx.length === n) { doc.destroy(); done(file); return; }
                    okBtn.disabled = true; okBtn.textContent = 'در حالِ ساختِ فایل…';
                    extractPages(buf, idx, doc).then(function (bytes) {
                        doc.destroy();
                        var label = idx.length === 1 ? ' (ص ' + (idx[0] + 1) + ')' : ' (' + idx.length + ' صفحه)';
                        done(mkFile(new Blob([bytes], {type: 'application/pdf'}), base(file.name) + label + '.pdf', 'application/pdf'));
                    }).catch(function () { okBtn.disabled = false; paint(); alert('ساختِ فایل با صفحه‌های انتخاب‌شده ممکن نشد.'); });
                }
            });
        });
    }

    // ---------------- HEIC => JPG
    function heicToJpg(f) {
        return ensure('heic').then(function (h) { return h({blob: f, toType: 'image/jpeg', quality: 0.9}); })
            .then(function (out) { return mkFile(Array.isArray(out) ? out[0] : out, base(f.name) + '.jpg', 'image/jpeg'); });
    }

    // ---------------- بازکردنِ گروهی (زیپ/پوشه/چند فایل)
    // opts: splitPdf (پیش‌فرض true)، renumber (پیش‌فرض true)، allow (پسوندهای مجاز)، onStatus(text)
    var DOC_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif', 'bmp'];
    function expand(files, opts) {
        opts = opts || {};
        var allow = opts.allow || DOC_EXT, split = opts.splitPdf !== false, renum = opts.renumber !== false;
        var st = opts.onStatus || function () {};
        var list = Array.prototype.slice.call(files || []).map(function (f) { return {f: f, path: f.webkitRelativePath || f.name}; });
        list.sort(function (a, b) { return natCmp(a.path, b.path); });
        var out = [], skipped = [];
        function one(item, depth) {
            var f = item.f;
            if (isZip(f)) {
                if (depth > 5) { skipped.push(item.path); return Promise.resolve(); }
                st('باز کردنِ «' + f.name + '»…');
                return ensure('jszip').then(function (JSZip) { return JSZip.loadAsync(f); }).then(function (z) {
                    var ents = [];
                    z.forEach(function (p, e) { if (!e.dir && !/(^|\/)(__MACOSX|\.DS_Store|Thumbs\.db|desktop\.ini)/i.test(p) && !/(^|\/)\._/.test(p)) ents.push(e); });
                    ents.sort(function (a, b) { return natCmp(a.name, b.name); });
                    var ch = Promise.resolve();
                    ents.forEach(function (e) {
                        ch = ch.then(function () { return e.async('blob'); }).then(function (bl) {
                            var nm = e.name.replace(/^.*\//, '');
                            return one({f: mkFile(bl, nm), path: item.path + '/' + e.name}, depth + 1);
                        });
                    });
                    return ch;
                }).catch(function () { skipped.push(item.path + ' (زیپِ خراب یا رمزدار)'); });
            }
            var e = ext(f.name);
            if (allow.indexOf(e) < 0 && !(e === '' && /^image\//.test(f.type || ''))) { skipped.push(item.path); return Promise.resolve(); }
            if (isHeic(f)) { st('تبدیلِ عکسِ HEIC…'); return heicToJpg(f).then(function (j) { out.push(j); }, function () { out.push(f); }); }
            if (isPdf(f) && split) {
                st('جدا کردنِ صفحه‌های «' + f.name + '»…');
                var buf;
                return readBuf(f).then(function (b) { buf = b; return openPdf(b); }).then(function (doc) {
                    var n = doc.numPages;
                    if (n <= 1) { doc.destroy(); out.push(f); return; }
                    var ch = Promise.resolve();
                    for (var i = 0; i < n; i++) (function (i) {
                        ch = ch.then(function () { st('جدا کردنِ صفحه‌ی ' + faD(i + 1) + ' از ' + faD(n) + ' («' + f.name + '»)'); return extractPages(buf, [i], doc); })
                               .then(function (bytes) { out.push(mkFile(new Blob([bytes], {type: 'application/pdf'}), base(f.name) + ' - ' + (i + 1) + '.pdf', 'application/pdf')); });
                    })(i);
                    return ch.then(function () { doc.destroy(); });
                }).catch(function () { out.push(f); });
            }
            out.push(f);
            return Promise.resolve();
        }
        var chain = Promise.resolve();
        list.forEach(function (it) { chain = chain.then(function () { return one(it, 0); }); });
        return chain.then(function () {
            if (renum) out = out.map(function (f, i) { return mkFile(f, (i + 1) + '.' + (ext(f.name) || 'pdf')); });
            return {files: out, skipped: skipped};
        });
    }

    // ---------------- صفِ ارسال در پس‌زمینه
    // UploadKit.enqueue({title, url, items: [{name, size, form: FormData}], onItem(res, item), onDone(summary)})
    var Q = {jobs: [], running: false, open: false, last: 0};
    function xhrPost(url, fd, onProg) {
        return new Promise(function (resolve) {
            var x = new XMLHttpRequest();
            x.open('POST', url, true);
            x.withCredentials = true;
            x.setRequestHeader('X-User-Activity', '1');
            x.setRequestHeader('Accept', 'application/json');
            if (x.upload && onProg) x.upload.onprogress = function (e) { if (e.lengthComputable) onProg(e.loaded, e.total); };
            x.onload = function () { var d; try { d = JSON.parse(x.responseText); } catch (e) { d = {ok: false, error: x.status === 413 ? 'حجمِ فایل از سقفِ هاست بیشتر است.' : 'پاسخِ نامعتبر از سرور (' + x.status + ').'}; } resolve(d); };
            x.onerror = function () { resolve({ok: false, error: 'خطا در ارتباط با سرور.'}); };
            x.ontimeout = function () { resolve({ok: false, error: 'زمانِ ارسال تمام شد.'}); };
            x.send(fd);
        });
    }
    function enqueue(job) {
        job.id = Date.now() + '_' + Math.random().toString(36).slice(2, 7);
        job.items = (job.items || []).map(function (it) { return {name: it.name, size: it.size || 0, form: it.form, sent: 0, status: 'wait', msg: '', res: null}; });
        job.okCount = 0; job.failCount = 0;
        Q.jobs.push(job);
        paintQ(); run();
        return job.id;
    }
    function run() {
        if (Q.running) return;
        Q.running = true;
        (function next() {
            var job = null, it = null;
            for (var j = 0; j < Q.jobs.length && !it; j++) for (var k = 0; k < Q.jobs[j].items.length; k++) if (Q.jobs[j].items[k].status === 'wait') { job = Q.jobs[j]; it = Q.jobs[j].items[k]; break; }
            if (!it) { Q.running = false; Q.last = Date.now(); paintQ(); setTimeout(paintQ, 9000); return; }
            it.status = 'up'; it.t0 = Date.now(); paintQ();
            xhrPost(job.url, it.form, function (l, t) { it.sent = l; it.size = it.size || t; paintQ(); }).then(function (d) {
                it.res = d; it.form = null;
                if (d && d.ok) { it.status = 'done'; it.sent = it.size; job.okCount++; } else { it.status = 'err'; it.msg = (d && d.error) || 'خطا'; job.failCount++; }
                try { if (job.onItem) job.onItem(d, it); } catch (e) {}
                if (job.items.every(function (x) { return x.status === 'done' || x.status === 'err' || x.status === 'cancel'; })) {
                    job.finished = true;
                    try { if (job.onDone) job.onDone({ok: job.okCount, fail: job.failCount, total: job.items.length, items: job.items}); } catch (e) {}
                }
                paintQ(); next();
            });
        })();
    }
    function cancelJob(id) {
        Q.jobs.forEach(function (j) { if (j.id === id) j.items.forEach(function (it) { if (it.status === 'wait') { it.status = 'cancel'; it.msg = 'لغو شد'; } }); });
        paintQ();
    }
    function stats() {
        var tot = 0, sent = 0, active = false, cur = null, n = 0, d = 0, err = 0;
        Q.jobs.forEach(function (j) { j.items.forEach(function (it) {
            if (it.status === 'cancel') return;
            n++; tot += it.size || 1; sent += it.status === 'done' || it.status === 'err' ? (it.size || 1) : it.sent;
            if (it.status === 'wait' || it.status === 'up') active = true;
            if (it.status === 'up') cur = it;
            if (it.status === 'done') d++; if (it.status === 'err') err++;
        }); });
        return {tot: tot || 1, sent: sent, pct: Math.min(100, sent / (tot || 1) * 100), active: active, cur: cur, n: n, done: d, err: err};
    }
    var mb = function (b) { return faD((b / 1048576).toFixed(b < 10485760 ? 1 : 0)); };
    function paintQ() {
        css();
        var s = stats();
        var pill = document.getElementById('uk-pill'), panel = document.getElementById('uk-panel');
        var recent = Q.last && Date.now() - Q.last < 8500;
        if (!Q.jobs.length || (!s.active && !recent && !Q.open)) { if (pill) pill.remove(); if (panel) panel.remove(); if (!s.active) Q.jobs = Q.jobs.filter(function (j) { return !j.finished; }); return; }
        if (!pill) {
            pill = document.createElement('button'); pill.id = 'uk-pill'; pill.type = 'button';
            pill.onclick = function () { Q.open = !Q.open; paintQ(); };
            document.body.appendChild(pill);
        }
        pill.innerHTML = s.active
            ? '<span class="uk-spin" style="width:14px;height:14px;border-width:2px;border-color:rgba(255,255,255,.35);border-top-color:#fff"></span>ارسالِ مدارک ' + faD(s.done + s.err) + '/' + faD(s.n) + ' · ' + faD(Math.floor(s.pct)) + '٪<span class="uk-pb"><i style="width:' + s.pct.toFixed(1) + '%"></i></span>'
            : '✓ ارسال تمام شد · ' + faD(s.done) + ' فایل' + (s.err ? ' · ' + faD(s.err) + ' خطا' : '');
        if (!Q.open) { if (panel) panel.remove(); return; }
        if (!panel) { panel = document.createElement('div'); panel.id = 'uk-panel'; document.body.appendChild(panel); }
        var ST = {wait: ['در صف', '#94a3b8'], up: ['در حالِ ارسال', '#4f46e5'], done: ['ارسال شد', '#059669'], err: ['خطا', '#e11d48'], cancel: ['لغو شد', '#94a3b8']};
        var html = '';
        Q.jobs.slice(-4).forEach(function (j) {
            var left = j.items.some(function (it) { return it.status === 'wait'; });
            html += '<div style="display:flex;justify-content:space-between;align-items:center;font-weight:800;color:#0f172a;margin:6px 0 2px"><span>' + esc(j.title || 'ارسالِ فایل‌ها') + '</span>'
                + (left ? '<button type="button" data-ukc="' + j.id + '" style="border:0;background:#fee2e2;color:#be123c;border-radius:8px;padding:2px 8px;font:800 10px inherit;cursor:pointer">لغوِ باقی‌مانده</button>' : '') + '</div>';
            j.items.slice(-40).forEach(function (it) {
                var lab = ST[it.status] || ['', '#64748b'], p = it.status === 'done' ? 100 : (it.size ? it.sent / it.size * 100 : 0);
                html += '<div class="uk-row"><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155" dir="auto" title="' + esc(it.msg || it.name) + '">' + esc(it.name) + (it.msg ? ' <small style="color:#e11d48">— ' + esc(it.msg) + '</small>' : '') + '</span>'
                    + '<span style="color:#94a3b8;white-space:nowrap">' + (it.size ? mb(it.size) + ' مگ' : '') + '</span>'
                    + '<span style="width:60px" class="uk-bar"><i style="width:' + p.toFixed(1) + '%;background:' + lab[1] + '"></i></span>'
                    + '<b style="color:' + lab[1] + ';white-space:nowrap;min-width:70px;text-align:left">' + lab[0] + (it.status === 'up' ? ' ' + faD(Math.floor(p)) + '٪' : '') + '</b></div>';
            });
        });
        panel.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center"><b style="font-size:12px">صفِ ارسال</b><button type="button" data-ukx style="border:0;background:none;cursor:pointer;color:#64748b;font-size:14px">✕</button></div>'
            + '<p style="color:#64748b;margin:4px 0 0">می‌توانید به بخش‌های دیگر بروید؛ ارسال ادامه دارد (فقط این صفحه را نبندید).</p>' + html;
        panel.querySelectorAll('[data-ukc]').forEach(function (b) { b.onclick = function () { cancelJob(b.dataset.ukc); }; });
        var x = panel.querySelector('[data-ukx]'); if (x) x.onclick = function () { Q.open = false; paintQ(); };
    }
    window.addEventListener('beforeunload', function (e) {
        if (stats().active) { e.preventDefault(); e.returnValue = 'ارسالِ مدارک هنوز تمام نشده.'; return e.returnValue; }
    });

    // ---------------- اتصالِ خودکار به همه‌ی inputهای فایل
    // PDFِ چندصفحه‌ای => انتخابِ صفحه‌ها؛ HEIC => JPG. inputهایی که data-uk-skip دارند (یا داخلِ [data-uk-skip]اند) دست نمی‌خورند.
    // inputِ data-uk-bulk: زیپ/پوشه/چند فایل باز و PDFها صفحه‌به‌صفحه جدا و ۱..N نام‌گذاری می‌شوند.
    function setFiles(input, files) {
        try { var dt = new DataTransfer(); files.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; return true; } catch (e) { return false; }
    }
    function fire(input) {
        input._ukPass = true;
        try { input.dispatchEvent(new Event('change', {bubbles: true})); } finally { input._ukPass = false; }
    }
    function skipInput(el) { return !!(el.closest && el.closest('[data-uk-skip]')); }
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el || el.tagName !== 'INPUT' || el.type !== 'file' || el._ukPass || !el.files || !el.files.length || skipInput(el)) return;
        if (typeof DataTransfer === 'undefined') return;
        var files = Array.prototype.slice.call(el.files);
        var bulk = el.hasAttribute('data-uk-bulk');
        var needs = bulk ? files.some(function (f) { return isZip(f) || isPdf(f) || isHeic(f); }) || files.length > 1 || !!el.webkitdirectory
                         : files.some(function (f) { return isPdf(f) || isHeic(f); });
        if (!needs) return;
        e.stopImmediatePropagation();
        var p;
        if (bulk) {
            var bz = busy('آماده‌سازیِ فایل‌ها…');
            p = expand(files, {onStatus: bz.set}).then(function (r) {
                bz.close();
                el._ukSkipped = r.skipped;
                if (r.skipped.length) try { (window.showToast || window.alert)('این موارد پشتیبانی نمی‌شود و کنار گذاشته شد: ' + r.skipped.slice(0, 5).join('، ') + (r.skipped.length > 5 ? ' و …' : '')); } catch (x) {}
                return r.files;
            }, function (err) { bz.close(); try { alert(err.message || err); } catch (x) {} return files; });
        } else {
            p = files.reduce(function (ch, f) {
                return ch.then(function (acc) {
                    if (acc === null) return null;
                    if (isHeic(f)) { var bz2 = busy('تبدیلِ عکسِ آیفون (HEIC) به JPG…'); return heicToJpg(f).then(function (j) { bz2.close(); acc.push(j); return acc; }, function () { bz2.close(); acc.push(f); return acc; }); }
                    if (isPdf(f)) return pickPages(f, {title: el.getAttribute('data-uk-title') || ''}).then(function (r) { if (r === null) return null; acc.push(r); return acc; });
                    acc.push(f); return acc;
                });
            }, Promise.resolve([]));
        }
        p.then(function (out) {
            if (out === null || !out.length) { el.value = ''; return; }   // انصراف
            if (!setFiles(el, out)) { /* مرورگرِ قدیمی: همان فایل‌های اصلی */ }
            fire(el);
        });
    }, true);

    // پیش‌نمایشِ PNGِ صفحه‌ها که دیگر روی هاست ساخته نمی‌شود: همان صفحه‌ی PDF در مرورگر کشیده می‌شود
    document.addEventListener('error', function (e) {
        var img = e.target;
        if (!img || img.tagName !== 'IMG' || img._ukFix || !/[?&]kind=png\b/.test(img.src || '')) return;
        img._ukFix = true;
        var url = img.src.replace(/([?&])kind=png\b/, '$1kind=pdf');
        fetch(url, {credentials: 'same-origin'}).then(function (r) { if (!r.ok) throw 0; return r.arrayBuffer(); }).then(openPdf).then(function (doc) {
            return renderPage(doc, 1, Math.max(120, img.clientWidth * 2 || 300), Math.max(160, img.clientHeight * 2 || 420)).then(function (r) { img.src = r.canvas.toDataURL('image/jpeg', 0.8); doc.destroy(); });
        }).catch(function () {});
    }, true);

    // PDF به عکس (ابزارِ «PDF به عکس»): pages = شماره‌ی صفحه‌ها از ۱ (خالی = همه)؛ خروجی [{name, blob}]
    function pdfToImages(file, opts) {
        opts = opts || {};
        var dpi = opts.dpi || 150, fmt = opts.format === 'png' ? 'png' : 'jpg', onProg = opts.onProgress || function () {};
        return readBuf(file).then(openPdf).then(function (doc) {
            var n = doc.numPages, pages = (opts.pages && opts.pages.length ? opts.pages : null) || Array.from({length: n}, function (_, i) { return i + 1; });
            pages = pages.filter(function (p) { return p >= 1 && p <= n; });
            var out = [], ch = Promise.resolve();
            pages.forEach(function (pn, i) {
                ch = ch.then(function () { return doc.getPage(pn); }).then(function (page) {
                    var vp = page.getViewport({scale: dpi / 72});
                    var c = document.createElement('canvas'); c.width = Math.round(vp.width); c.height = Math.round(vp.height);
                    var ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
                    return page.render({canvasContext: ctx, viewport: vp}).promise.then(function () { page.cleanup(); return canvasBlob(c, fmt === 'png' ? 'image/png' : 'image/jpeg', 0.9); });
                }).then(function (blob) { out.push({name: base(file.name) + ' - ' + pn + '.' + fmt, blob: blob}); onProg((i + 1) / pages.length); });
            });
            return ch.then(function () { doc.destroy(); return {images: out, total: n}; });
        });
    }

    window.UploadKit = {
        pdfToImages: pdfToImages, pageCount: pageCount,
        ensure: ensure, pickPages: pickPages, expand: expand, extractPages: extractPages, heicToJpg: heicToJpg,
        enqueue: enqueue, post: xhrPost, isPdf: isPdf, isZip: isZip, isHeic: isHeic, isImg: isImg, ext: ext, mkFile: mkFile, busy: busy
    };
})();
