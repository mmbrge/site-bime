// فایل: smart-ocr.js
// «پردازش فایل هوشمند»ِ مشترک: PDF/عکس در خودِ مرورگر صفحه‌به‌صفحه به عکس تبدیل می‌شود (pdf.js از vendor-js)
// و صفحه‌ها هم‌زمان (نه پشتِ سرِ هم) برای سرور فرستاده می‌شوند (api/inquiry_actions.php?action=ocr_page:
// اول هوشِ مصنوعیِ تصویری، بعد OCR.space). اگر هیچ موتورِ سروری جواب نداد، همان صفحه با یک Tesseractِ مشترک
// در مرورگر خوانده می‌شود (فقط همان موقع بارگذاری می‌شود).
// استفاده: استعلامات ← پردازش فایل هوشمند، و نامه‌ها ← «خواندنِ هوشمندِ مدارک».
(function () {
    if (window.SmartOCR) return;
    const API = 'api/inquiry_actions.php';
    const BASE = (function () {
        try { return new URL(document.currentScript.src, location.href).href.replace(/[^\/]*$/, '') + 'vendor-js/'; } catch (e) { return 'vendor-js/'; }
    })();
    let pdfjsP = null, tessP = null;
    function pdfjs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (pdfjsP) return pdfjsP;
        pdfjsP = new Promise((res, rej) => {
            const s = document.createElement('script'); s.src = BASE + 'pdf.min.js?v=1';
            s.onload = () => { if (!window.pdfjsLib) { pdfjsP = null; return rej(new Error('pdf.js')); } window.pdfjsLib.GlobalWorkerOptions.workerSrc = BASE + 'pdf.worker.min.js?v=1'; res(window.pdfjsLib); };
            s.onerror = () => { pdfjsP = null; rej(new Error('بارگذاریِ pdf.js ممکن نشد.')); };
            document.head.appendChild(s);
        });
        return pdfjsP;
    }
    // یک worker برای همه‌ی صفحه‌ها (ساختنِ worker برای هر صفحه یکی از دلیل‌های کندیِ نسخه‌ی قبلی بود)
    function tess(lang) {
        if (tessP) return tessP;
        tessP = new Promise((res, rej) => {
            const go = () => window.Tesseract.createWorker(lang === 'eng' ? 'eng' : 'fas+eng').then(res, rej);
            if (window.Tesseract) return go();
            const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
            s.onload = go; s.onerror = () => { tessP = null; rej(new Error('بارگذاریِ Tesseract ممکن نشد.')); };
            document.head.appendChild(s);
        });
        return tessP;
    }
    const isPdf = f => /\.pdf$/i.test(f.name || '') || f.type === 'application/pdf';
    const isImg = f => /^image\//.test(f.type || '') || /\.(jpe?g|png|webp|bmp|gif)$/i.test(f.name || '');
    const MAXSIDE = 2400;
    function canvasOut(cv) {
        const t = document.createElement('canvas'), k = 220 / Math.max(cv.width, cv.height);
        t.width = Math.round(cv.width * k); t.height = Math.round(cv.height * k);
        t.getContext('2d').drawImage(cv, 0, 0, t.width, t.height);
        return { dataUrl: cv.toDataURL('image/jpeg', 0.9), thumb: t.toDataURL('image/jpeg', 0.7) };
    }
    function fitCanvas(w, h) {
        const k = Math.min(1, MAXSIDE / Math.max(w, h)), cv = document.createElement('canvas');
        cv.width = Math.round(w * k); cv.height = Math.round(h * k);
        const g = cv.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, cv.width, cv.height);
        return [cv, g, k];
    }
    function loadImg(file) {
        return new Promise((res, rej) => { const u = URL.createObjectURL(file), im = new Image(); im.onload = () => { res(im); setTimeout(() => URL.revokeObjectURL(u), 1000); }; im.onerror = () => rej(new Error('عکس خوانده نشد: ' + file.name)); im.src = u; });
    }
    // فایل‌ها ← فهرستِ صفحه‌ها [{n, src, dataUrl, thumb}]
    async function pagesFromFiles(files, onProgress) {
        const out = [];
        for (const f of Array.from(files || [])) {
            if (isPdf(f)) {
                const lib = await pdfjs();
                const doc = await lib.getDocument({ data: await f.arrayBuffer() }).promise;
                for (let i = 1; i <= doc.numPages; i++) {
                    const pg = await doc.getPage(i), v1 = pg.getViewport({ scale: 1 });
                    const sc = Math.min(2.2, MAXSIDE / Math.max(v1.width, v1.height));
                    const vp = pg.getViewport({ scale: sc }), [cv, g] = fitCanvas(vp.width, vp.height);
                    await pg.render({ canvasContext: g, viewport: vp }).promise;
                    out.push(Object.assign({ n: out.length + 1, src: f.name + (doc.numPages > 1 ? ' — ص ' + i : '') }, canvasOut(cv)));
                    if (onProgress) onProgress(out.length);
                }
            } else if (isImg(f)) {
                const im = await loadImg(f), [cv, g] = fitCanvas(im.naturalWidth, im.naturalHeight);
                g.drawImage(im, 0, 0, cv.width, cv.height);
                out.push(Object.assign({ n: out.length + 1, src: f.name }, canvasOut(cv)));
                if (onProgress) onProgress(out.length);
            }
        }
        return out;
    }
    async function post(body, api) {
        try { return await (await fetch(api || API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, browser: true, error: 'خطا در ارتباط با سرور.' }; }
    }
    async function browserPage(p, lang) {
        const t0 = Date.now(), w = await tess(lang);
        const r = await w.recognize(p.dataUrl);
        return { ok: true, text: String((r.data || {}).text || '').trim(), engine: 'browser', engine_fa: 'مرورگر (Tesseract)', ms: Date.now() - t0 };
    }
    // فایلِ روی سایت (نامه/مدرکِ بارگذاری‌شده) ← صفحه‌ها
    async function pagesFromUrl(url, name) {
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('فایل باز نشد (HTTP ' + res.status + ').');
        const b = await res.blob();
        let f; try { f = new File([b], name || url.split('/').pop().split('?')[0], { type: b.type }); } catch (e) { f = b; f.name = name || 'file'; }
        if (!isPdf(f) && !isImg(f)) {
            if (/pdf/.test(b.type)) f = new File([b], 'file.pdf', { type: 'application/pdf' });
            else if (/^image\//.test(b.type)) f = new File([b], 'file.jpg', { type: b.type });
            else throw new Error('این نوع فایل خوانده نمی‌شود (فقط PDF و عکس).');
        }
        return pagesFromFiles([f]);
    }
    // همه‌ی کار: فایل/آدرس ← متنِ همه‌ی صفحه‌ها (برای «خواندنِ هوشمند»ِ نامه و مدارک)
    async function textOf(src, opt) {
        opt = opt || {};
        const pages = typeof src === 'string' ? await pagesFromUrl(src, opt.name) : await pagesFromFiles(src);
        if (!pages.length) throw new Error('صفحه‌ای پیدا نشد.');
        if (opt.maxPages && pages.length > opt.maxPages) pages.length = opt.maxPages;
        if (opt.onPages) opt.onPages(pages.length);
        const res = await run(pages, opt);
        const text = res.map(r => (res.length > 1 ? '--- صفحه ' + r.n + ' ---\n' : '') + (r.text || '')).join('\n\n').trim();
        return { text, pages: res };
    }
    // صفحه‌ها را هم‌زمان (حداکثر conc تا) می‌خواند؛ onPage(p, res) برای هر صفحه
    async function run(pages, opt) {
        opt = opt || {};
        const lang = opt.lang || 'fas', engine = opt.engine || 'auto', conc = opt.conc || 3, res = new Array(pages.length);
        let next = 0;
        async function one(i) {
            const p = pages[i]; let r;
            if (opt.onStart) opt.onStart(p);
            if (engine !== 'browser') r = await post({ action: opt.action || 'ocr_page', image: p.dataUrl, lang, engine }, opt.api);
            if (!r || !r.ok) {
                const why = r ? r.error : '';
                try { r = await browserPage(p, lang); if (why) r.note = why; }
                catch (e) { r = { ok: false, text: '', error: (why ? why + ' / ' : '') + e.message }; }
            }
            res[i] = Object.assign({ n: p.n }, r);
            if (opt.onPage) opt.onPage(p, res[i]);
        }
        async function lane() { while (next < pages.length) { const i = next++; await one(i); } }
        await Promise.all(Array.from({ length: Math.min(conc, pages.length) }, lane));
        return res;
    }
    window.SmartOCR = { pagesFromFiles, pagesFromUrl, textOf, run, isPdf, isImg };
})();
