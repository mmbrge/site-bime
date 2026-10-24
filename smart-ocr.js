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
    // پنجره‌ی انتخابِ صفحه‌ها (پیش از OCR، وقتی فایل چند صفحه دارد): همه یا چندتا ← Promise<صفحه‌های انتخابی | null>
    function pickPages(pages, opt) {
        opt = opt || {};
        if (pages.length <= 1) return Promise.resolve(pages);
        return new Promise(res => {
            const on = pages.map(() => true);
            const m = document.createElement('div');
            m.style.cssText = 'position:fixed;inset:0;z-index:10050;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:14px';
            const paint = () => {
                const k = on.filter(Boolean).length;
                m.innerHTML = `<div dir="rtl" style="background:#fff;border-radius:20px;width:min(860px,100%);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;font-family:inherit">
                    <div style="padding:14px 16px;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                        <b style="font-size:14px;color:#1e1b4b">${opt.title || 'کدام صفحه‌ها پردازش شوند؟'} <span style="color:#7c3aed;font-size:12px">(${k} از ${pages.length} صفحه)</span></b>
                        <span style="display:flex;gap:6px"><button data-a="all" style="border:1px solid #ddd6fe;background:#f5f3ff;color:#6d28d9;border-radius:10px;padding:5px 10px;font-size:11.5px;font-weight:700">همه‌ی صفحه‌ها</button>
                        <button data-a="none" style="border:1px solid #e2e8f0;background:#fff;border-radius:10px;padding:5px 10px;font-size:11.5px;font-weight:700">هیچ‌کدام</button></span></div>
                    <div style="padding:14px;overflow:auto;display:flex;flex-wrap:wrap;gap:10px">${pages.map((p, i) => `<label style="position:relative;cursor:pointer">
                        <img src="${p.thumb}" style="height:150px;border-radius:10px;border:3px solid ${on[i] ? '#7c3aed' : '#e2e8f0'};opacity:${on[i] ? 1 : .4};display:block">
                        <input type="checkbox" data-i="${i}" ${on[i] ? 'checked' : ''} style="position:absolute;top:6px;right:6px;accent-color:#7c3aed;width:16px;height:16px">
                        <small style="position:absolute;bottom:6px;left:6px;background:rgba(255,255,255,.92);border-radius:6px;padding:1px 6px;font-size:10.5px;font-weight:700">صفحه‌ی ${p.n}</small></label>`).join('')}</div>
                    <div style="padding:12px 16px;border-top:1px solid #eef2f7;display:flex;gap:8px;justify-content:flex-end">
                        <button data-a="cancel" style="border:1px solid #e2e8f0;background:#fff;border-radius:12px;padding:8px 16px;font-size:12px;font-weight:700">انصراف</button>
                        <button data-a="ok" ${k ? '' : 'disabled'} style="border:0;background:linear-gradient(120deg,#7c3aed,#06b6d4);color:#fff;border-radius:12px;padding:8px 18px;font-size:12px;font-weight:900;opacity:${k ? 1 : .5}">ادامه و پردازش (${k})</button></div></div>`;
                m.querySelectorAll('[data-i]').forEach(c => c.onchange = () => { on[+c.dataset.i] = c.checked; paint(); });
                m.querySelectorAll('[data-a]').forEach(b => b.onclick = () => {
                    const a = b.dataset.a;
                    if (a === 'all' || a === 'none') { on.fill(a === 'all'); paint(); return; }
                    m.remove(); res(a === 'ok' ? pages.filter((p, i) => on[i]) : null);
                });
            };
            paint(); document.body.appendChild(m);
        });
    }
    // همه‌ی کار: فایل/آدرس ← (انتخابِ صفحه‌ها اگر opt.pick) ← متنِ صفحه‌ها (برای «خواندنِ هوشمند»ِ نامه و مدارک)
    async function textOf(src, opt) {
        opt = opt || {};
        let pages = typeof src === 'string' ? await pagesFromUrl(src, opt.name) : await pagesFromFiles(src);
        if (!pages.length) throw new Error('صفحه‌ای پیدا نشد.');
        if (opt.pick && pages.length > 1) {
            pages = await pickPages(pages, { title: opt.pickTitle });
            if (!pages) { const e = new Error('انتخابِ صفحه لغو شد.'); e.cancel = true; throw e; }
        }
        if (opt.maxPages && pages.length > opt.maxPages) pages.length = opt.maxPages;
        if (opt.onPages) opt.onPages(pages.length);
        const res = await run(pages, opt);
        const text = res.map(r => (res.length > 1 ? '--- صفحه ' + r.n + ' ---\n' : '') + (r.text_verbatim || r.text || '')).join('\n\n').trim();
        return { text, pages: res };
    }
    // صفحه‌ها را هم‌زمان (حداکثر conc تا) می‌خواند؛ onPage(p, res) برای هر صفحه
    // opt: lang، engine (auto = اولویتِ تنظیمات)، order (موتورهای روشن به ترتیب؛ از boot)، format (قالبِ i2pdf)،
    //      conc، api/action، onStart(p)، onPage(p, r)، onProgress(p, steps, elapsed) = تایم‌لاینِ هر صفحه (هر ثانیه)
    async function run(pages, opt) {
        opt = opt || {};
        const lang = opt.lang || 'fas', engine = opt.engine || 'auto', conc = opt.conc || 3, res = new Array(pages.length);
        const order = opt.order || null;
        const browserFirst = engine === 'browser' || (engine === 'auto' && order && order[0] === 'browser');
        const browserOk = engine === 'browser' || !order || order.includes('browser');
        let next = 0;
        async function one(i) {
            const p = pages[i]; let r, timer = null;
            if (opt.onStart) opt.onStart(p);
            if (!browserFirst) {
                const ptoken = opt.onProgress ? (Date.now().toString(36) + Math.random().toString(36).slice(2, 10)).replace(/[^a-z0-9]/g, '') : '';
                if (ptoken) {
                    const poll = async () => {
                        const g = await post({ action: 'ocr_progress', token: ptoken }, opt.api);
                        if (g && g.ok && timer !== null) opt.onProgress(p, g.steps || [], g.elapsed || 0);
                        if (timer !== null) timer = setTimeout(poll, 1000);
                    };
                    timer = setTimeout(poll, 700);
                }
                r = await post({ action: opt.action || 'ocr_page', image: p.dataUrl, lang, engine, format: opt.format || '', ptoken }, opt.api);
                if (timer) clearTimeout(timer); timer = null;
                if (ptoken) { const g = await post({ action: 'ocr_progress', token: ptoken }, opt.api); if (g && g.ok && (g.steps || []).length) opt.onProgress(p, g.steps, g.elapsed || 0); }   // تایم‌لاینِ کامل
            }
            if ((!r || !r.ok) && (browserFirst || (browserOk && (!r || r.browser !== false)))) {
                const why = r ? r.error : '';
                if (opt.onProgress) opt.onProgress(p, [{ step: 'browser', label: 'خواندن در خودِ مرورگر (Tesseract)…', at: 0 }], 0);
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
    window.SmartOCR = { pagesFromFiles, pagesFromUrl, pickPages, textOf, run, isPdf, isImg };
})();
