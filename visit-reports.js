// فایل: visit-reports.js
// ماژولِ «گزارش بازدید خودرو» در پنل: هسته (API، پنجره‌ها، استایل) + فرمِ «ساخت گزارش بازدید».
// همین فرم هم در صفحه‌ی اختصاصی باز می‌شود، هم به‌صورت پاپ‌آپ از «بازدید سلامت» (با همان فیلدها،
// مواضع آسیب‌دیده و قطعات)، هم برای ویرایشِ یک گزارشِ صادرشده.
// وابستگی به پنل: showToast، plateSplitHtml/parseStoredPlate (ورودیِ یکپارچه‌ی پلاک)، MoneyInput
(function () {
    'use strict';
    const API = 'api/visit_reports.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t = 'info') => (typeof window.showToast === 'function' ? window.showToast(m, t) : console.log(m));
    const money = v => { const d = en(v).replace(/\D/g, ''); return d ? fa(d.replace(/\B(?=(\d{3})+(?!\d))/g, ',')) : ''; };
    let uidSeq = 0;

    // پاسخِ غیرِ JSON (خطای PHP/سرور): متنِ خودِ خطا نشان داده می‌شود تا علتش معلوم باشد
    function badResponse(status, txt) {
        const plain = String(txt || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
        return `پاسخِ نامعتبر از سرور${status && status !== 200 ? ' (کد ' + status + ')' : ''}${plain ? ': ' + plain : ' (پاسخ خالی بود)'}`;
    }
    async function api(action, body = {}) {
        const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }) });
        const txt = await res.text();
        try { return JSON.parse(txt); } catch (e) { return { ok: false, error: badResponse(res.status, txt) }; }
    }
    function apiForm(fd, onProgress) {
        return new Promise((resolve) => {
            const x = new XMLHttpRequest();
            x.open('POST', API);
            if (onProgress) x.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
            x.onload = () => { try { resolve(JSON.parse(x.responseText)); } catch (e) { resolve({ ok: false, error: x.status === 413 ? 'حجمِ فایل‌ها بیش از حدِ مجازِ سرور است.' : badResponse(x.status, x.responseText) }); } };
            x.onerror = () => resolve({ ok: false, error: 'خطای شبکه؛ اتصال را بررسی کنید.' });
            x.send(fd);
        });
    }
    const fileUrl = (id, kind, extra = '') => `${API}?action=file&id=${id}&kind=${kind}${extra}`;

    // عکس‌های حجیمِ گوشی قبل از ارسال کوچک می‌شوند (حداکثر ۲۴۰۰ پیکسل، JPEG)
    async function shrinkImage(file) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1.4 * 1024 * 1024 || !window.createImageBitmap) return file;
        try {
            const bmp = await createImageBitmap(file);
            const r = Math.min(1, 2400 / Math.max(bmp.width, bmp.height));
            const c = document.createElement('canvas');
            c.width = Math.round(bmp.width * r); c.height = Math.round(bmp.height * r);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            const blob = await new Promise(res => c.toBlob(res, 'image/jpeg', 0.86));
            return blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
        } catch (e) { return file; }
    }

    // ------------------------------------------------------------------
    //  استایل
    // ------------------------------------------------------------------
    const CSS = `
    .vr-fade-up{animation:vrFadeUp .45s cubic-bezier(.2,.8,.2,1) both}
    .vr-pop{animation:vrPop .35s cubic-bezier(.2,.9,.3,1.3) both}
    @keyframes vrFadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
    @keyframes vrPop{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:none}}
    .vr-stagger>*{animation:vrFadeUp .45s cubic-bezier(.2,.8,.2,1) both}
    .vr-stagger>*:nth-child(2){animation-delay:.04s}.vr-stagger>*:nth-child(3){animation-delay:.08s}.vr-stagger>*:nth-child(4){animation-delay:.12s}
    .vr-stagger>*:nth-child(5){animation-delay:.16s}.vr-stagger>*:nth-child(6){animation-delay:.2s}.vr-stagger>*:nth-child(n+7){animation-delay:.24s}
    .vr-card{background:rgba(255,255,255,.96);border-radius:1.25rem;box-shadow:0 4px 24px rgba(15,23,42,.05);border:1px solid rgba(226,232,240,.8)}
    .vr-sec-title{display:flex;align-items:center;gap:.6rem;font-weight:900;color:#1e293b;font-size:.9rem}
    .vr-sec-title .vr-ic{width:2rem;height:2rem;border-radius:.75rem;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem;box-shadow:0 6px 14px -6px currentColor}
    .vr-in{width:100%;border:1px solid #cbd5e1;border-radius:.75rem;padding:.6rem .75rem;font-size:.82rem;background:#fff;transition:border-color .15s,box-shadow .15s}
    .vr-in:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
    .vr-lbl{display:block;font-size:.72rem;font-weight:700;color:#475569;margin-bottom:.3rem}
    .vr-lbl .req{color:#ef4444;margin-right:2px}
    .vr-flash{animation:vrFlash 1.6s ease both}
    @keyframes vrFlash{0%{background:#fef9c3;border-color:#facc15}100%{background:#fff}}
    .vr-cat{cursor:pointer;transition:transform .2s,box-shadow .2s,border-color .2s;border:2px solid transparent}
    .vr-cat:hover{transform:translateY(-3px);box-shadow:0 12px 30px -12px rgba(79,70,229,.35)}
    .vr-cat.sel{border-color:#6366f1;box-shadow:0 12px 30px -12px rgba(79,70,229,.5)}
    .vr-drop{border:2px dashed #c7d2fe;border-radius:1rem;transition:all .2s;background:linear-gradient(135deg,#eef2ff 0%,#f8fafc 100%)}
    .vr-drop.over{border-color:#6366f1;background:#e0e7ff;transform:scale(1.01)}
    .vr-seg{display:inline-flex;background:#f1f5f9;border-radius:.7rem;padding:3px;gap:2px}
    .vr-seg button{font-size:.7rem;font-weight:800;padding:.3rem .65rem;border-radius:.55rem;color:#64748b;transition:all .18s}
    .vr-seg button.on-ok{background:#10b981;color:#fff;box-shadow:0 4px 10px -4px #10b981}
    .vr-seg button.on-bad{background:#ef4444;color:#fff;box-shadow:0 4px 10px -4px #ef4444}
    .vr-part{transition:background .2s}
    .vr-part.bad{background:#fef2f2}
    .vr-switch{position:relative;width:2.6rem;height:1.45rem;border-radius:9999px;background:#cbd5e1;transition:background .2s;flex-shrink:0}
    .vr-switch::after{content:"";position:absolute;top:3px;right:3px;width:1.05rem;height:1.05rem;border-radius:9999px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
    input:checked+.vr-switch{background:#6366f1}
    input:checked+.vr-switch::after{transform:translateX(-1.15rem)}
    .vr-thumb{position:relative;border-radius:.9rem;overflow:hidden;aspect-ratio:4/3;background:#f1f5f9;box-shadow:0 2px 8px rgba(0,0,0,.06)}
    .vr-thumb img{width:100%;height:100%;object-fit:cover;transition:transform .3s}
    .vr-thumb:hover img{transform:scale(1.06)}
    .vr-thumb .vr-x{position:absolute;top:.35rem;left:.35rem;width:1.6rem;height:1.6rem;border-radius:9999px;background:rgba(15,23,42,.65);color:#fff;font-size:.7rem;display:flex;align-items:center;justify-content:center}
    .vr-thumb.off img{opacity:.3;filter:grayscale(1)}
    .vr-thumb .vr-cap{position:absolute;bottom:0;inset-inline:0;background:linear-gradient(transparent,rgba(15,23,42,.75));color:#fff;font-size:.62rem;padding:.8rem .5rem .35rem;font-weight:700}
    .vr-thumb .vr-chk{position:absolute;top:.4rem;right:.4rem;width:1.5rem;height:1.5rem;border-radius:.5rem;background:#fff;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.7rem;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3)}
    .vr-thumb.sel .vr-chk{background:#6366f1}
    .vr-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;font-weight:800;font-size:.78rem;border-radius:.8rem;padding:.6rem 1rem;transition:all .18s}
    .vr-btn:active{transform:scale(.97)}
    .vr-btn-p{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;box-shadow:0 10px 24px -10px #6d28d9}
    .vr-btn-p:hover{box-shadow:0 14px 28px -10px #6d28d9;transform:translateY(-1px)}
    .vr-btn-s{background:#fff;color:#334155;border:1px solid #e2e8f0}
    .vr-btn-s:hover{border-color:#a5b4fc;color:#4338ca}
    .vr-btn-g{background:#ecfdf5;color:#047857}.vr-btn-g:hover{background:#d1fae5}
    .vr-btn-r{background:#fef2f2;color:#b91c1c}.vr-btn-r:hover{background:#fee2e2}
    .vr-btn[disabled]{opacity:.55;pointer-events:none}
    .vr-plate{display:inline-flex;align-items:stretch;direction:ltr;border:2px solid #1e293b;border-radius:.45rem;overflow:hidden;font-weight:900;font-size:.8rem;background:#fff;line-height:1}
    .vr-plate .flag{background:#1d4ed8;width:.55rem}
    .vr-plate span{padding:.28rem .35rem}
    .vr-plate .ir{border-left:2px solid #1e293b;display:flex;flex-direction:column;align-items:center;font-size:.72rem;padding:.12rem .35rem}
    .vr-plate .ir small{font-size:.45rem;font-weight:700}
    .vr-overlay{position:fixed;inset:0;z-index:9000;background:rgba(15,23,42,.45);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:2.5vh 1rem;overflow-y:auto;animation:vrFade .2s both}
    @keyframes vrFade{from{opacity:0}to{opacity:1}}
    .vr-modal{width:100%;background:#f8fafc;border-radius:1.4rem;box-shadow:0 30px 80px -20px rgba(15,23,42,.5);animation:vrPop .3s cubic-bezier(.2,.9,.3,1.2) both;overflow:hidden}
    .vr-progress{height:.4rem;border-radius:9999px;background:#e0e7ff;overflow:hidden}
    .vr-progress>div{height:100%;background:linear-gradient(90deg,#6366f1,#a855f7,#6366f1);background-size:200% 100%;animation:vrShim 1.2s linear infinite;transition:width .25s}
    @keyframes vrShim{from{background-position:200% 0}to{background-position:0 0}}
    .vr-check svg{width:5rem;height:5rem}
    .vr-check circle{stroke:#10b981;stroke-width:3;fill:none;stroke-dasharray:160;stroke-dashoffset:160;animation:vrDraw .6s ease forwards}
    .vr-check path{stroke:#10b981;stroke-width:4;fill:none;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:50;stroke-dashoffset:50;animation:vrDraw .4s .5s ease forwards}
    @keyframes vrDraw{to{stroke-dashoffset:0}}
    .vr-chip{display:inline-flex;align-items:center;gap:.3rem;font-size:.66rem;font-weight:800;padding:.18rem .55rem;border-radius:9999px}
    .vr-sticky{position:sticky;bottom:0;z-index:20}
    .vr-skel{background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 37%,#f1f5f9 63%);background-size:400% 100%;animation:vrSk 1.4s ease infinite;border-radius:.75rem}
    @keyframes vrSk{0%{background-position:100% 50%}100%{background-position:0 50%}}
    .vr-hide{display:none!important}
    `;
    function injectCss() {
        if (document.getElementById('vr-css')) return;
        const s = document.createElement('style');
        s.id = 'vr-css'; s.textContent = CSS;
        document.head.appendChild(s);
    }

    // ------------------------------------------------------------------
    //  پنجره‌ها
    // ------------------------------------------------------------------
    function modal({ title, icon = 'fa-file-circle-check', width = '64rem', html = '', onClose } = {}) {
        injectCss();
        const ov = document.createElement('div');
        ov.className = 'vr-overlay';
        ov.innerHTML = `<div class="vr-modal" style="max-width:${width}">
            <div class="flex items-center justify-between gap-3 px-5 py-4 bg-white border-b border-slate-100">
                <div class="flex items-center gap-3 min-w-0"><span class="w-10 h-10 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white flex items-center justify-center shadow-lg shadow-indigo-500/30"><i class="fas ${icon}"></i></span>
                <div class="min-w-0"><h3 class="font-black text-slate-800 text-sm truncate vr-m-title">${title || ''}</h3><p class="text-[11px] text-slate-400 font-bold truncate vr-m-sub"></p></div></div>
                <button type="button" class="vr-m-close w-9 h-9 rounded-xl bg-slate-100 hover:bg-red-50 hover:text-red-500 text-slate-500 transition-colors"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="vr-m-body p-4 sm:p-6">${html}</div></div>`;
        document.body.appendChild(ov);
        document.body.style.overflow = 'hidden';
        const close = () => { ov.remove(); if (!document.querySelector('.vr-overlay')) document.body.style.overflow = ''; if (onClose) onClose(); };
        ov.querySelector('.vr-m-close').onclick = close;
        ov.addEventListener('mousedown', e => { if (e.target === ov) ov.dataset.down = '1'; });
        ov.addEventListener('mouseup', e => { if (e.target === ov && ov.dataset.down) close(); ov.dataset.down = ''; });
        return { el: ov, body: ov.querySelector('.vr-m-body'), close, setTitle: (t, sub) => { ov.querySelector('.vr-m-title').innerHTML = t; ov.querySelector('.vr-m-sub').innerHTML = sub || ''; } };
    }

    // رمزِ پنل (برای ویرایش و حذفِ همیشگی)
    function askPassword(title, hint) {
        return new Promise(resolve => {
            const m = modal({ title, icon: 'fa-lock', width: '26rem', onClose: () => resolve(null), html: `
                <p class="text-xs text-slate-500 font-bold leading-6 mb-3">${hint || ''}</p>
                <input type="password" class="vr-in vr-pw" autocomplete="current-password" placeholder="رمز عبورِ پنلِ خودتان">
                <p class="vr-pw-err text-[11px] text-red-500 font-bold mt-2 hidden">رمز را وارد کنید.</p>
                <div class="flex gap-2 mt-4"><button class="vr-btn vr-btn-p flex-1 vr-pw-ok"><i class="fas fa-check"></i> تایید</button><button class="vr-btn vr-btn-s vr-pw-cancel">انصراف</button></div>` });
            const inp = m.body.querySelector('.vr-pw');
            let done = false;
            const ok = () => { if (!inp.value) { m.body.querySelector('.vr-pw-err').classList.remove('hidden'); inp.focus(); return; } done = true; const v = inp.value; m.el.remove(); document.body.style.overflow = document.querySelector('.vr-overlay') ? 'hidden' : ''; resolve(v); };
            m.body.querySelector('.vr-pw-ok').onclick = ok;
            m.body.querySelector('.vr-pw-cancel').onclick = () => m.close();
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') ok(); });
            setTimeout(() => inp.focus(), 60);
        });
    }

    function lightbox(urls, idx = 0, captions = []) {
        injectCss();
        const ov = document.createElement('div');
        ov.className = 'vr-overlay';
        ov.style.cssText = 'align-items:center;background:rgba(2,6,23,.9);z-index:9500';
        const draw = () => {
            ov.innerHTML = `<button class="absolute top-4 left-4 w-10 h-10 rounded-full bg-white/10 text-white hover:bg-white/20 vr-lb-x"><i class="fas fa-xmark"></i></button>
                ${urls.length > 1 ? `<button class="absolute right-4 top-1/2 w-11 h-11 rounded-full bg-white/10 text-white hover:bg-white/20 vr-lb-n"><i class="fas fa-chevron-right"></i></button>
                <button class="absolute left-4 top-1/2 w-11 h-11 rounded-full bg-white/10 text-white hover:bg-white/20 vr-lb-p"><i class="fas fa-chevron-left"></i></button>` : ''}
                <div class="text-center vr-pop"><img src="${urls[idx]}" class="max-h-[82vh] max-w-[92vw] rounded-2xl shadow-2xl mx-auto">
                <p class="text-white/80 text-xs font-bold mt-3">${esc(captions[idx] || '')} <span class="text-white/40">(${fa(idx + 1)} از ${fa(urls.length)})</span></p></div>`;
            ov.querySelector('.vr-lb-x').onclick = () => ov.remove();
            const n = ov.querySelector('.vr-lb-n'), p = ov.querySelector('.vr-lb-p');
            if (n) n.onclick = e => { e.stopPropagation(); idx = (idx + 1) % urls.length; draw(); };
            if (p) p.onclick = e => { e.stopPropagation(); idx = (idx - 1 + urls.length) % urls.length; draw(); };
        };
        draw();
        ov.addEventListener('click', e => { if (e.target === ov) ov.remove(); });
        document.body.appendChild(ov);
    }

    function plateHtml(p) {
        const m = String(p || '').match(/^(\d{2})ایران\s*-\s*(\d{3})\s*(\S+)\s*(\d{2})$/);
        if (!m) return p ? `<span class="font-bold text-slate-600">${esc(p)}</span>` : '<span class="text-slate-400 text-xs">بدون پلاک</span>';
        return `<span class="vr-plate"><span class="flag"></span><span>${fa(m[4])}</span><span>${esc(m[3])}</span><span>${fa(m[2])}</span><span class="ir"><small>ایران</small>${fa(m[1])}</span></span>`;
    }

    // ------------------------------------------------------------------
    //  فرمِ ساختِ گزارش
    // ------------------------------------------------------------------
    let bootCache = null;
    async function boot(force) {
        if (bootCache && !force) return bootCache;
        const d = await api('bootstrap');
        if (d.ok) bootCache = d;
        return d;
    }

    const SECTION_STYLE = [
        ['from-indigo-500 to-violet-500', 'fa-clipboard-list'], ['from-sky-500 to-cyan-500', 'fa-car-side'], ['from-amber-500 to-orange-500', 'fa-gauge-high'],
        ['from-emerald-500 to-teal-500', 'fa-toolbox'], ['from-rose-500 to-pink-500', 'fa-car-burst'], ['from-fuchsia-500 to-purple-500', 'fa-layer-group'],
    ];

    class Builder {
        // opts: { mode: 'new'|'edit'|'health', reportId, healthId, onDone(report), inModal }
        constructor(root, opts = {}) {
            injectCss();
            this.root = root; this.opts = opts; this.mode = opts.mode || 'new';
            this.u = 'vr' + (++uidSeq);
            this.cat = null; this.parts = {}; this.damages = []; this.uploads = []; this.removePhotos = new Set();
            this.healthSel = new Set(); this.insuredId = null; this.existing = null; this.health = null; this.busy = false;
            this.init();
        }
        id(k) { return `${this.u}-${k}`; }
        $(sel) { return this.root.querySelector(sel); }

        async init() {
            this.root.innerHTML = `<div class="space-y-4">${[1, 2, 3].map(() => '<div class="vr-skel h-28"></div>').join('')}</div>`;
            const b = await boot(true);
            if (!b.ok) { this.root.innerHTML = `<div class="vr-card p-8 text-center text-red-500 font-bold text-sm">${esc(b.error || 'خطا در بارگذاری')}</div>`; return; }
            this.boot = b;
            if (this.mode === 'edit') {
                const d = await api('detail', { id: this.opts.reportId });
                if (!d.ok) { this.root.innerHTML = `<div class="vr-card p-8 text-center text-red-500 font-bold text-sm">${esc(d.error)}</div>`; return; }
                this.existing = d.report;
                this.cat = b.categories.find(c => c.id === d.report.category_id);
                if (!this.cat) { this.root.innerHTML = `<div class="vr-card p-8 text-center text-red-500 font-bold text-sm">اجازه‌ی ویرایشِ این نوع گزارش را ندارید.</div>`; return; }
            }
            if (this.mode === 'health') {
                const h = await api('health_prefill', { id: this.opts.healthId });
                if (!h.ok) { this.root.innerHTML = `<div class="vr-card p-8 text-center text-red-500 font-bold text-sm">${esc(h.error)}</div>`; return; }
                this.health = h;
                h.photos.forEach(p => { if (p.status !== 'REJECTED') this.healthSel.add(p.key); });
            }
            if (!b.categories.length) {
                this.root.innerHTML = `<div class="vr-card p-10 text-center vr-fade-up"><div class="w-16 h-16 rounded-2xl bg-amber-100 text-amber-500 flex items-center justify-center mx-auto text-2xl mb-3"><i class="fas fa-lock"></i></div>
                    <p class="font-black text-slate-700">هیچ نوع گزارشی برای شما فعال نیست.</p><p class="text-xs text-slate-400 mt-2 font-bold">مدیر کل باید از «تنظیمات گزارش» به شما دسترسی بدهد.</p></div>`;
                return;
            }
            this.renderShell();
            if (this.cat) this.selectCat(this.cat.id, true);
            else if (b.categories.length === 1) this.selectCat(b.categories[0].id);
        }

        renderShell() {
            const b = this.boot;
            const head = this.mode === 'edit'
                ? `<div class="vr-card p-4 flex flex-wrap items-center gap-3 vr-fade-up"><span class="vr-chip bg-amber-100 text-amber-700"><i class="fas fa-pen"></i> ویرایشِ گزارش</span>
                    <span class="font-black text-slate-700 text-sm" dir="ltr">${esc(this.existing.report_no)}</span><span class="text-xs text-slate-400 font-bold">${esc(this.existing.category_name)}</span>
                    <span class="text-[11px] text-slate-400 font-bold mr-auto">بعد از ذخیره، فایل‌های قبلی با «(old)» کنارِ فایل‌های تازه نگه داشته می‌شوند.</span></div>`
                : `<div class="vr-card p-4 sm:p-5 vr-fade-up">
                    <div class="flex items-center justify-between gap-3 mb-3 flex-wrap"><div class="vr-sec-title"><span class="vr-ic bg-gradient-to-br from-indigo-500 to-violet-600"><i class="fas fa-shapes"></i></span> نوع گزارش</div>
                    ${this.health ? `<span class="vr-chip bg-rose-50 text-rose-600"><i class="fas fa-heart-pulse"></i> از بازدید سلامت شماره ${fa(this.health.inspection.number)} · ${esc(this.health.inspection.holder || '')}</span>` : ''}</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 vr-stagger">${b.categories.map(c => `
                        <div class="vr-cat vr-card p-4 flex items-start gap-3" data-cat="${c.id}">
                            <span class="w-11 h-11 rounded-2xl flex items-center justify-center text-white shrink-0 bg-gradient-to-br ${c.insurer === 'PARSIAN' ? 'from-rose-500 to-red-600' : 'from-sky-500 to-indigo-600'}"><i class="fas ${/سنگین|کامیون/.test(c.name) ? 'fa-truck' : 'fa-car'}"></i></span>
                            <div class="min-w-0"><p class="font-black text-slate-700 text-[13px] leading-6">${esc(c.name)}</p>
                            <p class="text-[10px] text-slate-400 font-bold mt-0.5">${esc(c.insurer_fa)} · ${fa(c.fields.length)} فیلد${c.has_parser ? ' · <i class="fas fa-wand-magic-sparkles text-violet-400"></i> استخراج از PDF' : ''}</p>
                            ${c.description ? `<p class="text-[10px] text-slate-500 mt-1">${esc(c.description)}</p>` : ''}</div>
                        </div>`).join('')}</div></div>`;
            this.root.innerHTML = `<div class="space-y-4">${head}<div class="vr-form-wrap"></div></div>`;
            this.root.querySelectorAll('[data-cat]').forEach(el => el.onclick = () => this.selectCat(Number(el.dataset.cat)));
        }

        async selectCat(catId, initial) {
            const c = this.boot.categories.find(x => x.id === catId);
            if (!c) return;
            if (this.cat && this.cat.id !== catId && !initial && this.dirty) {
                const ok = await (window.uiConfirm ? uiConfirm('تغییر نوع گزارش', 'اطلاعاتی که وارد کرده‌اید برای نوعِ تازه دوباره چیده می‌شود؛ مقدارهای مشترک حفظ می‌شوند. ادامه می‌دهید؟') : Promise.resolve(true));
                if (!ok) return;
            }
            const keep = this.cat ? this.collect() : null;
            this.cat = c;
            this.root.querySelectorAll('[data-cat]').forEach(el => el.classList.toggle('sel', Number(el.dataset.cat) === catId));
            this.parts = {}; this.damages = [];
            this.renderForm();
            if (this.mode === 'edit') { this.applyForm(this.existing.form || {}); this.$(`#${this.id('date')}`).value = fa(this.existing.report_date.replace(/\./g, '/')); }
            else if (this.mode === 'health') {
                this.applyForm(this.health.forms[catId] || {});
                if (this.health.damages.length && !this.damages.length) { this.damages = this.health.damages.slice(0, this.maxDamages()); this.renderDamages(); }
            }
            if (keep) this.applyForm(keep, true);
            if (this.mode === 'new') this.offerDraft();
            this.updateVisibility();
            this.dirty = false;
        }

        maxDamages() { const f = this.cat.fields.find(x => x.field_type === 'DamageList'); return f ? f.max_rows : 0; }

        // ---------------- ساختِ فرم ----------------
        renderForm() {
            const c = this.cat, u = this.u;
            const secs = [];
            const bySec = {};
            c.fields.forEach(f => {
                const s = f.field_type === 'PartsStatus' ? `__parts_${f.id}` : f.field_type === 'DamageList' ? `__dmg_${f.id}` : (f.section || 'مشخصات');
                if (!bySec[s]) { bySec[s] = []; secs.push(s); }
                bySec[s].push(f);
            });
            let si = 0;
            const secHtml = secs.map(s => {
                const st = SECTION_STYLE[si++ % SECTION_STYLE.length];
                const fs = bySec[s];
                if (s.startsWith('__parts_')) return this.partsCardHtml(fs[0]);
                if (s.startsWith('__dmg_')) return this.damageCardHtml(fs[0]);
                return `<div class="vr-card p-4 sm:p-5 vr-fade-up"><div class="vr-sec-title mb-4"><span class="vr-ic bg-gradient-to-br ${st[0]}"><i class="fas ${st[1]}"></i></span>${esc(s)}</div>
                    <div class="grid grid-cols-1 sm:grid-cols-6 gap-3">${fs.map(f => this.fieldHtml(f)).join('')}</div></div>`;
            }).join('');
            const canParse = this.mode === 'new' && c.has_parser !== undefined;
            const wrap = this.$('.vr-form-wrap');
            wrap.innerHTML = `
                <div class="space-y-4">
                <div class="vr-draft-slot"></div>
                <div class="grid grid-cols-1 ${canParse ? 'lg:grid-cols-3' : ''} gap-4">
                    ${canParse ? `<label class="vr-drop lg:col-span-2 p-5 flex items-center gap-4 cursor-pointer vr-fade-up" data-drop="pdf">
                        <span class="w-14 h-14 rounded-2xl bg-white shadow-md text-indigo-500 flex items-center justify-center text-2xl shrink-0"><i class="fas fa-file-pdf"></i></span>
                        <div class="min-w-0"><p class="font-black text-slate-700 text-sm">استخراجِ خودکار از PDF</p>
                        <p class="text-[11px] text-slate-500 font-bold mt-1 leading-5">فایلِ PDFِ درخواست/مشخصاتِ خودرو را اینجا رها کنید یا کلیک کنید؛ پلاک، بیمه‌گذار و مشخصاتِ خودرو پُر می‌شود${c.has_parser ? ' (با الگوریتمِ اختصاصیِ همین نوع)' : ''}.</p>
                        <p class="vr-parse-st text-[11px] font-bold mt-1.5"></p></div>
                        <input type="file" accept="application/pdf,.pdf" class="hidden vr-pdf-in"></label>` : ''}
                    <div class="vr-card p-4 sm:p-5 vr-fade-up ${canParse ? '' : 'max-w-md'}">
                        <label class="vr-lbl" for="${u}-date"><i class="fas fa-calendar-day text-indigo-400 ml-1"></i> تاریخ گزارش</label>
                        <div class="flex gap-2"><input id="${u}-date" class="vr-in text-center font-bold" dir="ltr" value="${fa(this.boot.today)}" placeholder="۱۴۰۵/۰۱/۰۱">
                        <button type="button" class="vr-btn vr-btn-s shrink-0 vr-today">امروز</button></div>
                        <p class="text-[10px] text-slate-400 font-bold mt-2">پیش‌فرض امروز است؛ در صورتِ نیاز تغییر دهید.</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="vr-card p-4 sm:p-5 vr-fade-up"><div class="vr-sec-title mb-4"><span class="vr-ic bg-gradient-to-br from-slate-600 to-slate-800"><i class="fas fa-rectangle-list"></i></span>پلاک خودرو</div>
                        ${typeof window.plateSplitHtml === 'function' ? window.plateSplitHtml(`${u}-plate`, '') : `<input id="${u}-plate" class="vr-in" placeholder="۱۲ب۳۴۵ ایران ۶۷">`}
                        <p class="text-[10px] text-slate-400 font-bold mt-2">اگر خودرو پلاک ندارد خالی بگذارید و «شماره شاسی» را وارد کنید.</p></div>
                    <div class="vr-card p-4 sm:p-5 vr-fade-up">
                        <div class="flex items-center justify-between gap-2 mb-4"><div class="vr-sec-title"><span class="vr-ic bg-gradient-to-br from-emerald-500 to-green-600"><i class="fas fa-user-tie"></i></span>بیمه‌گذار</div>
                        ${c.insureds.length ? `<div class="relative"><input class="vr-in !py-1.5 !text-xs w-48 vr-ins-q" placeholder="🔍 انتخاب از بیمه‌گذارانِ ثبت‌شده"><div class="vr-ins-dd absolute left-0 mt-1 w-72 max-h-64 overflow-y-auto bg-white rounded-xl shadow-2xl border border-slate-100 z-30 hidden"></div></div>` : ''}</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="sm:col-span-2"><label class="vr-lbl"><span class="req">*</span>نام بیمه‌گذار</label><input id="${u}-ins-name" class="vr-in"></div>
                            <div><label class="vr-lbl">کد ملی / شناسه ملی</label><input id="${u}-ins-nid" class="vr-in" dir="ltr" inputmode="numeric"></div>
                            <div><label class="vr-lbl">تلفن</label><input id="${u}-ins-phone" class="vr-in" dir="ltr" inputmode="tel"></div>
                            <div class="sm:col-span-2"><label class="vr-lbl">آدرس</label><input id="${u}-ins-addr" class="vr-in"></div>
                        </div>
                        <p class="vr-ins-badge text-[10px] font-bold text-emerald-600 mt-2 hidden"><i class="fas fa-link"></i> از بیمه‌گذارانِ ثبت‌شده انتخاب شد (قابلِ ویرایشِ دستی)</p>
                    </div>
                </div>
                ${secHtml}
                ${this.photosCardHtml()}
                <div class="vr-sticky"><div class="vr-card p-3 sm:p-4 flex flex-wrap items-center gap-3 shadow-xl">
                    <div class="flex-1 min-w-[12rem]"><div class="vr-progress hidden"><div style="width:0%"></div></div><p class="vr-status text-[11px] font-bold text-slate-400">${this.mode === 'edit' ? 'برای ذخیره‌ی تغییرات، رمزِ پنلتان پرسیده می‌شود.' : 'بعد از صدور، PDF و Word و ZIPِ عکس‌ها در بایگانی ساخته می‌شود.'}</p></div>
                    <button type="button" class="vr-btn vr-btn-s vr-preview"><i class="fas fa-eye"></i> پیش‌نمایش PDF</button>
                    <button type="button" class="vr-btn vr-btn-p vr-submit px-6"><i class="fas ${this.mode === 'edit' ? 'fa-floppy-disk' : 'fa-file-circle-check'}"></i> ${this.mode === 'edit' ? 'ذخیره‌ی ویرایش' : 'صدور گزارش'}</button>
                </div></div>
                </div>`;
            this.bindForm();
            this.renderParts(); this.renderDamages(); this.renderPhotos();
            if (window.MoneyInput && MoneyInput.apply) MoneyInput.apply(wrap);
        }

        fieldHtml(f) {
            const u = this.u, idv = `${u}-f-${f.field_key}`;
            const span = f.width === 'full' || f.field_type === 'Textarea' ? 'sm:col-span-6' : f.width === 'half' ? 'sm:col-span-3' : 'sm:col-span-2';
            const lbl = `<label class="vr-lbl" for="${idv}">${f.required ? '<span class="req">*</span>' : ''}${esc(f.label)}</label>`;
            const help = f.help ? `<p class="text-[10px] text-slate-400 font-bold mt-1">${esc(f.help)}</p>` : '';
            const ml = f.max_chars && ['Entry', 'Number'].includes(f.field_type) ? ` maxlength="${Math.max(f.max_chars, 1) * (f.field_type === 'Entry' ? 3 : 1)}"` : '';
            const dv = esc(f.default_value || '');
            let input;
            switch (f.field_type) {
                case 'Textarea': input = `<textarea id="${idv}" rows="3" class="vr-in" data-key="${f.field_key}">${dv}</textarea>`; break;
                case 'Number': input = `<input id="${idv}" class="vr-in" dir="ltr" inputmode="numeric" data-key="${f.field_key}" value="${fa(dv)}"${ml}>`; break;
                case 'Money': input = `<div class="relative"><input id="${idv}" class="vr-in money-input !pl-12" dir="ltr" inputmode="numeric" data-key="${f.field_key}" value="${money(dv)}"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400">ریال</span></div><p class="vr-words text-[10px] text-indigo-500 font-bold mt-1 min-h-[1rem]" data-for="${f.field_key}"></p>`; break;
                case 'Combobox': input = `<select id="${idv}" class="vr-in" data-key="${f.field_key}"><option value="">— انتخاب —</option>${(f.choices || []).map(o => `<option ${o === f.default_value ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`; break;
                case 'Checkbox': return `<div class="${span} flex items-center" data-wrap="${f.field_key}"${f.show_if ? ` data-showif="${esc(f.show_if)}"` : ''}><label class="flex items-center gap-3 cursor-pointer select-none mt-4">
                        <input type="checkbox" id="${idv}" class="hidden" data-key="${f.field_key}" ${['1', 'بله', 'true'].includes(f.default_value) ? 'checked' : ''}><span class="vr-switch"></span><span class="text-xs font-bold text-slate-600">${esc(f.label)}</span></label>${help}</div>`;
                case 'Date': input = `<input id="${idv}" class="vr-in text-center" dir="ltr" placeholder="۱۴۰۵/۰۱/۰۱" data-key="${f.field_key}" value="${fa(dv)}">`; break;
                case 'Time': input = `<input id="${idv}" class="vr-in text-center vr-time" dir="ltr" inputmode="numeric" maxlength="5" placeholder="۱۰:۳۰" data-key="${f.field_key}" value="${fa(dv || this.boot.time)}">`; break;
                case 'Visitor': input = `<select id="${idv}" class="vr-in" data-key="${f.field_key}"><option value="">— بازدیدکننده —</option>${this.cat.visitors.map(v => `<option ${v.name === f.default_value ? 'selected' : ''}>${esc(v.name)}</option>`).join('')}</select>`; break;
                default: input = `<input id="${idv}" class="vr-in" data-key="${f.field_key}" value="${dv}"${ml}>`;
            }
            return `<div class="${span}" data-wrap="${f.field_key}"${f.show_if ? ` data-showif="${esc(f.show_if)}"` : ''}>${lbl}${input}${help}</div>`;
        }

        partsCardHtml(f) {
            return `<div class="vr-card p-4 sm:p-5 vr-fade-up" data-wrap="${f.field_key}"${f.show_if ? ` data-showif="${esc(f.show_if)}"` : ''}>
                <div class="flex items-center justify-between gap-2 mb-4 flex-wrap"><div class="vr-sec-title"><span class="vr-ic bg-gradient-to-br from-emerald-500 to-teal-600"><i class="fas fa-list-check"></i></span>${esc(f.label)}
                    <span class="vr-parts-count vr-chip bg-slate-100 text-slate-500"></span></div>
                    <div class="flex gap-2"><button type="button" class="vr-btn vr-btn-g !py-1.5 !text-[11px] whitespace-nowrap vr-all-ok"><i class="fas fa-check-double"></i> همه ${esc(this.cat.ok_label)}</button>
                    <input class="vr-in !py-1.5 !text-xs w-40 vr-parts-q" placeholder="🔍 جستجوی قطعه"></div></div>
                <div class="vr-parts grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-1" data-field="${f.id}"></div></div>`;
        }
        renderParts() {
            this.cat.fields.filter(f => f.field_type === 'PartsStatus').forEach(f => {
                const box = this.root.querySelector(`.vr-parts[data-field="${f.id}"]`);
                if (!box) return;
                const q = (this.root.querySelector('.vr-parts-q') || {}).value || '';
                box.innerHTML = f.parts.filter(p => !q || p.label.includes(q)).map(p => {
                    const bad = this.parts[p.id] === 'k';
                    return `<div class="vr-part ${bad ? 'bad' : ''} flex items-center justify-between gap-2 rounded-xl px-2.5 py-1.5" data-part="${p.id}">
                        <span class="text-xs font-bold ${bad ? 'text-red-600' : 'text-slate-600'}">${esc(p.label)}</span>
                        <span class="vr-seg"><button type="button" data-v="s" class="${bad ? '' : 'on-ok'}">${esc(this.cat.ok_label)}</button><button type="button" data-v="k" class="${bad ? 'on-bad' : ''}">${esc(this.cat.bad_label)}</button></span></div>`;
                }).join('');
                box.querySelectorAll('.vr-part button').forEach(btn => btn.onclick = () => {
                    this.parts[btn.closest('.vr-part').dataset.part] = btn.dataset.v; this.dirty = true; this.renderParts(); this.saveDraft();
                });
                const bad = f.parts.filter(p => this.parts[p.id] === 'k').length;
                const cnt = this.root.querySelector('.vr-parts-count');
                if (cnt) { cnt.textContent = bad ? `${fa(bad)} ${this.cat.bad_label}` : `همه ${this.cat.ok_label}`; cnt.className = `vr-parts-count vr-chip ${bad ? 'bg-red-100 text-red-600' : 'bg-emerald-100 text-emerald-600'}`; }
            });
        }

        damageCardHtml(f) {
            return `<div class="vr-card p-4 sm:p-5 vr-fade-up" data-wrap="${f.field_key}"${f.show_if ? ` data-showif="${esc(f.show_if)}"` : ''}>
                <div class="flex items-center justify-between gap-2 mb-4 flex-wrap"><div class="vr-sec-title"><span class="vr-ic bg-gradient-to-br from-rose-500 to-red-600"><i class="fas fa-car-burst"></i></span>${esc(f.label)}
                    <span class="vr-chip bg-slate-100 text-slate-500">حداکثر ${fa(f.max_rows)} ردیف</span></div>
                    <div class="flex gap-2 flex-wrap"><button type="button" class="vr-btn vr-btn-s !py-1.5 !text-[11px] vr-dmg-from-parts"><i class="fas fa-wand-magic-sparkles"></i> از قطعاتِ ${esc(this.cat.bad_label)}</button>
                    <button type="button" class="vr-btn vr-btn-r !py-1.5 !text-[11px] vr-dmg-add"><i class="fas fa-plus"></i> افزودن موضع</button></div></div>
                <div class="vr-dmg space-y-2"></div></div>`;
        }
        renderDamages() {
            const box = this.root.querySelector('.vr-dmg');
            if (!box) return;
            const max = this.maxDamages();
            if (!this.damages.length) box.innerHTML = `<p class="text-center text-xs text-slate-400 font-bold py-4">موضعِ آسیب‌دیده‌ای ثبت نشده است.</p>`;
            else box.innerHTML = this.damages.map((d, i) => `<div class="grid grid-cols-12 gap-2 items-center vr-fade-up" data-i="${i}">
                <span class="col-span-1 w-7 h-7 rounded-full bg-rose-100 text-rose-600 text-[11px] font-black flex items-center justify-center">${fa(i + 1)}</span>
                <input class="vr-in col-span-11 sm:col-span-4" data-d="location" placeholder="موضع (مثلاً گلگیر جلو راست)" value="${esc(d.location)}">
                <input class="vr-in col-span-10 sm:col-span-6" data-d="description" placeholder="شرح خسارت" value="${esc(d.description)}">
                <button type="button" class="col-span-2 sm:col-span-1 w-8 h-8 rounded-lg bg-slate-100 hover:bg-red-100 hover:text-red-600 text-slate-400 vr-dmg-del"><i class="fas fa-trash text-xs"></i></button></div>`).join('');
            box.querySelectorAll('input[data-d]').forEach(inp => inp.oninput = () => { this.damages[Number(inp.closest('[data-i]').dataset.i)][inp.dataset.d] = inp.value; this.dirty = true; this.saveDraft(); });
            box.querySelectorAll('.vr-dmg-del').forEach(b => b.onclick = () => { this.damages.splice(Number(b.closest('[data-i]').dataset.i), 1); this.renderDamages(); this.saveDraft(); });
            const add = this.root.querySelector('.vr-dmg-add');
            if (add) add.disabled = this.damages.length >= max;
        }

        photosCardHtml() {
            const hp = this.health ? this.health.photos : [];
            return `<div class="vr-card p-4 sm:p-5 vr-fade-up">
                <div class="flex items-center justify-between gap-2 mb-4 flex-wrap"><div class="vr-sec-title"><span class="vr-ic bg-gradient-to-br from-cyan-500 to-blue-600"><i class="fas fa-images"></i></span>عکس‌های بازدید <span class="vr-ph-count vr-chip bg-slate-100 text-slate-500"></span></div>
                <div class="flex gap-2"><label class="vr-btn vr-btn-s !py-1.5 !text-[11px] cursor-pointer"><i class="fas fa-camera"></i> دوربین<input type="file" accept="image/*" capture="environment" class="hidden vr-cam-in"></label></div></div>
                ${hp.length ? `<p class="text-[11px] font-bold text-slate-500 mb-2"><i class="fas fa-heart-pulse text-rose-400"></i> عکس‌های بازدید سلامت (تیک‌خورده‌ها کنارِ گزارش ذخیره می‌شوند)</p>
                    <div class="flex gap-2 mb-2"><button type="button" class="vr-btn vr-btn-s !py-1 !text-[10px] vr-hp-all">انتخابِ همه</button><button type="button" class="vr-btn vr-btn-s !py-1 !text-[10px] vr-hp-none">هیچ‌کدام</button></div>
                    <div class="vr-hp grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-7 gap-2 mb-4"></div>` : ''}
                <div class="vr-ex grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-7 gap-2 mb-3"></div>
                <label class="vr-drop p-5 flex flex-col items-center justify-center gap-2 cursor-pointer text-center" data-drop="img">
                    <i class="fas fa-cloud-arrow-up text-3xl text-indigo-400"></i><p class="font-black text-slate-600 text-xs">عکس‌ها را اینجا رها کنید یا کلیک کنید</p>
                    <p class="text-[10px] text-slate-400 font-bold">چند عکس با هم؛ عکس‌های حجیم خودکار کوچک می‌شوند</p>
                    <input type="file" accept="image/*" multiple class="hidden vr-img-in"></label>
                <div class="vr-up grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-7 gap-2 mt-3"></div></div>`;
        }
        renderPhotos() {
            const hp = this.root.querySelector('.vr-hp');
            if (hp && this.health) {
                hp.innerHTML = this.health.photos.map((p, i) => `<div class="vr-thumb cursor-pointer ${this.healthSel.has(p.key) ? 'sel' : 'off'}" data-k="${esc(p.key)}" data-i="${i}">
                    <img src="${p.url}" loading="lazy"><span class="vr-chk"><i class="fas fa-check"></i></span>
                    <span class="vr-cap">${esc(p.label)}${p.note ? ' · ' + esc(p.note) : ''}</span></div>`).join('');
                hp.querySelectorAll('.vr-thumb').forEach(t => {
                    t.onclick = e => { if (e.detail === 2) return; const k = t.dataset.k; this.healthSel.has(k) ? this.healthSel.delete(k) : this.healthSel.add(k); this.renderPhotos(); };
                    t.ondblclick = () => lightbox(this.health.photos.map(p => p.url), Number(t.dataset.i), this.health.photos.map(p => p.label));
                });
            }
            const ex = this.root.querySelector('.vr-ex');
            if (ex) {
                const photos = this.existing ? this.existing.photos : [];
                ex.innerHTML = photos.map((p, i) => `<div class="vr-thumb ${this.removePhotos.has(p.id) ? 'off' : ''}" data-id="${p.id}" data-i="${i}">
                    <img src="${fileUrl(this.existing.id, 'photo', '&pid=' + p.id)}" loading="lazy" class="cursor-zoom-in">
                    <button type="button" class="vr-x" title="${this.removePhotos.has(p.id) ? 'برگرداندن' : 'حذف'}"><i class="fas ${this.removePhotos.has(p.id) ? 'fa-rotate-left' : 'fa-xmark'}"></i></button>
                    <span class="vr-cap">${this.removePhotos.has(p.id) ? 'حذف می‌شود (با old نگه داشته می‌شود)' : 'عکسِ ذخیره‌شده'}</span></div>`).join('');
                ex.querySelectorAll('.vr-thumb').forEach(t => {
                    const id = Number(t.dataset.id);
                    t.querySelector('.vr-x').onclick = () => { this.removePhotos.has(id) ? this.removePhotos.delete(id) : this.removePhotos.add(id); this.renderPhotos(); };
                    t.querySelector('img').onclick = () => lightbox(photos.map(p => fileUrl(this.existing.id, 'photo', '&pid=' + p.id)), Number(t.dataset.i));
                });
            }
            const up = this.root.querySelector('.vr-up');
            if (up) {
                (this._urls || []).forEach(x => URL.revokeObjectURL(x));
                this._urls = this.uploads.map(f => URL.createObjectURL(f));
                up.innerHTML = this.uploads.map((f, i) => `<div class="vr-thumb vr-pop" data-i="${i}"><img src="${this._urls[i]}" class="cursor-zoom-in"><button type="button" class="vr-x"><i class="fas fa-xmark"></i></button>
                    <span class="vr-cap">${fa((f.size / 1024 / 1024).toFixed(1))} مگابایت</span></div>`).join('');
                up.querySelectorAll('.vr-thumb').forEach(t => {
                    t.querySelector('.vr-x').onclick = () => { this.uploads.splice(Number(t.dataset.i), 1); this.renderPhotos(); };
                    t.querySelector('img').onclick = () => lightbox(this._urls, Number(t.dataset.i));
                });
            }
            const total = this.uploads.length + (this.existing ? this.existing.photos.filter(p => !this.removePhotos.has(p.id)).length : 0) + (this.health ? this.healthSel.size : 0);
            const cnt = this.root.querySelector('.vr-ph-count');
            if (cnt) cnt.textContent = total ? `${fa(total)} عکس` : 'بدون عکس';
        }
        async addImages(files) {
            const list = Array.from(files || []).filter(f => /^image\//.test(f.type));
            if (!list.length) return;
            const st = this.root.querySelector('.vr-status');
            if (st) st.textContent = 'در حال آماده‌سازیِ عکس‌ها...';
            for (const f of list) this.uploads.push(await shrinkImage(f));
            if (this.uploads.length > 40) { this.uploads = this.uploads.slice(0, 40); toast('حداکثر ۴۰ عکس در هر بار ذخیره.', 'warning'); }
            if (st) st.textContent = `${fa(this.uploads.length)} عکسِ تازه آماده‌ی ارسال است.`;
            this.renderPhotos();
        }

        bindForm() {
            const wrap = this.$('.vr-form-wrap');
            wrap.addEventListener('input', e => { this.dirty = true; if (e.target.dataset.key) this.updateVisibility(); this.updateWords(); this.saveDraft(); if (e.target.id === `${this.u}-ins-name` && this.insuredId) { this.insuredId = null; wrap.querySelector('.vr-ins-badge').classList.add('hidden'); } });
            wrap.addEventListener('change', e => { if (e.target.dataset.key) this.updateVisibility(); this.saveDraft(); });
            wrap.querySelectorAll('.vr-time').forEach(t => t.addEventListener('input', () => {
                let d = en(t.value).replace(/\D/g, '').slice(0, 4);
                if (d.length > 2) d = d.slice(0, 2) + ':' + d.slice(2);
                t.value = fa(d);
            }));
            wrap.querySelector('.vr-today').onclick = () => { this.$(`#${this.id('date')}`).value = fa(this.boot.today); };
            // PDF
            const pdfIn = wrap.querySelector('.vr-pdf-in');
            if (pdfIn) pdfIn.onchange = () => { if (pdfIn.files[0]) this.parsePdf(pdfIn.files[0]); pdfIn.value = ''; };
            // کشیدن و رها کردن
            wrap.querySelectorAll('[data-drop]').forEach(z => {
                ['dragenter', 'dragover'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.add('over'); }));
                ['dragleave', 'drop'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.remove('over'); }));
                z.addEventListener('drop', e => {
                    const fs = e.dataTransfer.files;
                    if (z.dataset.drop === 'pdf') { if (fs[0]) this.parsePdf(fs[0]); } else this.addImages(fs);
                });
            });
            const imgIn = wrap.querySelector('.vr-img-in'), camIn = wrap.querySelector('.vr-cam-in');
            imgIn.onchange = () => { this.addImages(imgIn.files); imgIn.value = ''; };
            camIn.onchange = () => { this.addImages(camIn.files); camIn.value = ''; };
            const hpAll = wrap.querySelector('.vr-hp-all'), hpNone = wrap.querySelector('.vr-hp-none');
            if (hpAll) hpAll.onclick = () => { this.health.photos.forEach(p => this.healthSel.add(p.key)); this.renderPhotos(); };
            if (hpNone) hpNone.onclick = () => { this.healthSel.clear(); this.renderPhotos(); };
            // قطعات
            const allOk = wrap.querySelector('.vr-all-ok');
            if (allOk) allOk.onclick = () => { this.parts = {}; this.renderParts(); this.saveDraft(); };
            const pq = wrap.querySelector('.vr-parts-q');
            if (pq) pq.oninput = () => this.renderParts();
            // مواضع آسیب
            const dAdd = wrap.querySelector('.vr-dmg-add');
            if (dAdd) dAdd.onclick = () => { if (this.damages.length < this.maxDamages()) { this.damages.push({ location: '', description: '' }); this.renderDamages(); const ins = this.root.querySelectorAll('.vr-dmg [data-d="location"]'); if (ins.length) ins[ins.length - 1].focus(); } };
            const dParts = wrap.querySelector('.vr-dmg-from-parts');
            if (dParts) dParts.onclick = () => {
                const labels = [];
                this.cat.fields.filter(f => f.field_type === 'PartsStatus').forEach(f => f.parts.forEach(p => { if (this.parts[p.id] === 'k') labels.push(p.label); }));
                if (!labels.length) { toast(`هیچ قطعه‌ای «${this.cat.bad_label}» علامت نخورده است.`, 'warning'); return; }
                let added = 0;
                labels.forEach(l => { if (this.damages.length < this.maxDamages() && !this.damages.some(d => d.location === l)) { this.damages.push({ location: l, description: '' }); added++; } });
                this.renderDamages();
                toast(added ? `${fa(added)} موضع اضافه شد؛ شرحِ خسارت را بنویسید.` : 'همه‌ی قطعاتِ خسارتی از قبل در فهرست هستند.', added ? 'info' : 'warning');
            };
            // بیمه‌گذارانِ ثبت‌شده
            const q = wrap.querySelector('.vr-ins-q'), dd = wrap.querySelector('.vr-ins-dd');
            if (q) {
                const show = () => {
                    const s = en(q.value.trim());
                    const list = this.cat.insureds.filter(i => !s || i.name.includes(q.value.trim()) || String(i.national_id || '').includes(s) || String(i.phone || '').includes(s)).slice(0, 30);
                    dd.innerHTML = list.length ? list.map(i => `<button type="button" class="w-full text-right px-3 py-2 hover:bg-indigo-50 border-b border-slate-50" data-ins="${i.id}">
                        <p class="text-xs font-black text-slate-700">${esc(i.name)} <span class="vr-chip bg-slate-100 text-slate-500 mr-1">${i.source === 'PERSON' ? 'شخص' : i.source === 'COMPANY' ? 'شرکت' : 'دستی'}</span></p>
                        <p class="text-[10px] text-slate-400 font-bold" dir="ltr">${fa(i.national_id || '')} ${i.phone ? '· ' + fa(i.phone) : ''}</p></button>`).join('')
                        : '<p class="text-center text-[11px] text-slate-400 font-bold p-3">موردی نیست</p>';
                    dd.classList.remove('hidden');
                    dd.querySelectorAll('[data-ins]').forEach(b => b.onmousedown = e => { e.preventDefault(); this.pickInsured(this.cat.insureds.find(x => x.id === Number(b.dataset.ins))); q.value = ''; dd.classList.add('hidden'); });
                };
                q.onfocus = show; q.oninput = show; q.onblur = () => setTimeout(() => dd.classList.add('hidden'), 150);
            }
            wrap.querySelector('.vr-preview').onclick = () => this.preview();
            wrap.querySelector('.vr-submit').onclick = () => this.submit();
        }

        pickInsured(i) {
            if (!i) return;
            const set = (k, v) => { const el = this.$(`#${this.id(k)}`); el.value = v || ''; el.classList.remove('vr-flash'); void el.offsetWidth; el.classList.add('vr-flash'); };
            set('ins-name', i.name); set('ins-nid', fa(i.national_id)); set('ins-phone', fa(i.phone)); set('ins-addr', i.address);
            this.insuredId = i.id;
            this.$('.vr-ins-badge').classList.remove('hidden');
            this.saveDraft();
        }

        // ---------------- مقدارها ----------------
        fieldEl(key) { return this.$(`#${this.u}-f-${key}`); }
        collect() {
            const plateEl = this.$(`#${this.id('plate')}`);
            let plate = { p1: '', letter: '', p2: '', iran: '' };
            if (plateEl) {
                if (typeof window.parseStoredPlate === 'function') { const v = window.parseStoredPlate(plateEl.value); plate = { p1: v.p4, letter: v.letter, p2: v.p2, iran: v.p1 }; }
            }
            const g = k => { const el = this.$(`#${this.id(k)}`); return el ? el.value.trim() : ''; };
            const form = { plate, insured: { name: g('ins-name'), national_id: en(g('ins-nid')), phone: en(g('ins-phone')), address: g('ins-addr'), insured_id: this.insuredId },
                           fields: {}, parts: { ...this.parts }, damages: this.damages.filter(d => (d.location || '').trim() || (d.description || '').trim()) };
            (this.cat ? this.cat.fields : []).forEach(f => {
                const el = this.fieldEl(f.field_key);
                if (!el) return;
                if (f.field_type === 'Checkbox') form.fields[f.field_key] = el.checked ? '1' : '';
                else if (f.field_type === 'Money') form.fields[f.field_key] = en(el.value).replace(/\D/g, '');
                else if (['Number', 'Date', 'Time'].includes(f.field_type)) form.fields[f.field_key] = en(el.value.trim());
                else form.fields[f.field_key] = el.value.trim();
            });
            return form;
        }
        // flash: فقط خانه‌هایی که مقدار دارند پُر شوند و با رنگ نشان داده شوند (نتیجه‌ی استخراج)
        applyForm(form, onlyFilled = false, flash = false) {
            if (!form) return;
            const mark = el => { if (flash) { el.classList.remove('vr-flash'); void el.offsetWidth; el.classList.add('vr-flash'); } };
            const pl = form.plate || {};
            if ((pl.p1 || pl.p2 || pl.iran) && (!onlyFilled || pl.p1)) {
                const v = `${pl.iran || ''}ایران - ${pl.p2 || ''} ${pl.letter || ''} ${pl.p1 || ''}`;
                if (typeof window.setPlateSplit === 'function') window.setPlateSplit(this.id('plate'), v); else this.$(`#${this.id('plate')}`).value = v;
                if (flash) this.root.querySelectorAll(`.psplit[data-target="${this.id('plate')}"] input`).forEach(mark);
            }
            const ins = form.insured || {};
            [['ins-name', ins.name], ['ins-nid', fa(ins.national_id)], ['ins-phone', fa(ins.phone)], ['ins-addr', ins.address]].forEach(([k, v]) => {
                if (onlyFilled && !v) return;
                const el = this.$(`#${this.id(k)}`); if (el) { el.value = String(v || '').replace(/\s*\n\s*/g, ' '); if (v) mark(el); }
            });
            if (ins.insured_id) { this.insuredId = ins.insured_id; this.$('.vr-ins-badge').classList.remove('hidden'); }
            const fv = form.fields || {};
            this.cat.fields.forEach(f => {
                if (!(f.field_key in fv)) return;
                let v = fv[f.field_key];
                if (onlyFilled && (v === '' || v === null || v === undefined)) return;
                const el = this.fieldEl(f.field_key);
                if (!el) return;
                // مقدارِ چندخطیِ پارسر در خانه‌ی یک‌خطی با فاصله جدا شود (نه چسبیده)
                if (el.tagName === 'INPUT' && typeof v === 'string') v = v.replace(/\s*\n\s*/g, ' ');
                if (f.field_type === 'Checkbox') el.checked = ['1', 'true', 'on', 'بله'].includes(String(v));
                else if (f.field_type === 'Money') el.value = money(v);
                else if (['Number', 'Date', 'Time'].includes(f.field_type)) el.value = fa(v);
                else if (el.tagName === 'SELECT' && v && ![...el.options].some(o => o.value === v)) { el.insertAdjacentHTML('beforeend', `<option>${esc(v)}</option>`); el.value = v; }
                else el.value = v;
                if (v) mark(el);
            });
            if (form.parts && Object.keys(form.parts).length) { this.parts = { ...form.parts }; this.renderParts(); }
            if (Array.isArray(form.damages) && form.damages.length) { this.damages = form.damages.map(d => ({ location: d.location || '', description: d.description || '' })).slice(0, this.maxDamages()); this.renderDamages(); }
            this.updateVisibility(); this.updateWords();
        }
        updateVisibility() {
            if (!this.cat) return;
            const fv = this.collect().fields;
            this.root.querySelectorAll('[data-showif]').forEach(w => {
                const m = w.dataset.showif.match(/^\s*([A-Za-z0-9_]+)\s*(!?=)\s*(.*)$/);
                if (!m) return;
                const v = String(fv[m[1]] ?? '').trim(), want = m[3].trim();
                const eq = want === '1' ? ['1', 'true', 'on', 'بله'].includes(v) : v === want;
                const show = m[2] === '=' ? eq : !eq;
                if (show && w.classList.contains('vr-hide')) { w.classList.remove('vr-hide'); w.classList.add('vr-fade-up'); }
                else if (!show) w.classList.add('vr-hide');
            });
        }
        updateWords() {
            this.root.querySelectorAll('.vr-words').forEach(p => {
                const el = this.fieldEl(p.dataset.for);
                const n = el ? en(el.value).replace(/\D/g, '') : '';
                p.textContent = n ? numWords(n) + ' ریال' : '';
            });
        }

        // ---------------- پیش‌نویس ----------------
        draftKey() { return `vr_draft_v1_${this.cat ? this.cat.id : 0}`; }
        saveDraft() {
            if (this.mode !== 'new' || !this.cat) return;
            clearTimeout(this._dt);
            this._dt = setTimeout(() => { try { localStorage.setItem(this.draftKey(), JSON.stringify({ form: this.collect(), date: this.$(`#${this.id('date')}`).value, at: Date.now() })); } catch (e) {} }, 700);
        }
        clearDraft() { try { localStorage.removeItem(this.draftKey()); } catch (e) {} }
        offerDraft() {
            let d = null;
            try { d = JSON.parse(localStorage.getItem(this.draftKey()) || 'null'); } catch (e) {}
            const slot = this.$('.vr-draft-slot');
            if (!d || !slot || Date.now() - d.at > 7 * 86400000) return;
            const t = new Date(d.at);
            slot.innerHTML = `<div class="vr-card p-3 flex flex-wrap items-center gap-3 border-amber-200 bg-amber-50/80 vr-pop"><i class="fas fa-clock-rotate-left text-amber-500"></i>
                <p class="text-xs font-bold text-amber-800 flex-1">پیش‌نویسِ ذخیره‌نشده‌ای از ${fa(t.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }))} برای این نوع گزارش دارید${d.form.insured && d.form.insured.name ? ' (' + esc(d.form.insured.name) + ')' : ''}.</p>
                <button type="button" class="vr-btn vr-btn-p !py-1.5 !text-[11px] vr-d-yes">بازیابی</button><button type="button" class="vr-btn vr-btn-s !py-1.5 !text-[11px] vr-d-no">حذف</button></div>`;
            slot.querySelector('.vr-d-yes').onclick = () => { this.applyForm(d.form, false, true); if (d.date) this.$(`#${this.id('date')}`).value = d.date; slot.innerHTML = ''; };
            slot.querySelector('.vr-d-no').onclick = () => { this.clearDraft(); slot.innerHTML = ''; };
        }

        // ---------------- استخراج از PDF ----------------
        async parsePdf(file) {
            if (!/\.pdf$/i.test(file.name)) { toast('فقط فایل PDF.', 'error'); return; }
            const st = this.$('.vr-parse-st');
            st.className = 'vr-parse-st text-[11px] font-bold mt-1.5 text-indigo-500';
            st.innerHTML = `<i class="fas fa-spinner fa-spin"></i> در حال خواندنِ «${esc(file.name)}»...`;
            const fd = new FormData();
            fd.append('action', 'parse_pdf'); fd.append('category_id', this.cat.id); fd.append('file', file);
            const d = await apiForm(fd);
            if (!d.ok) { st.className = 'vr-parse-st text-[11px] font-bold mt-1.5 text-red-500'; st.innerHTML = `<i class="fas fa-triangle-exclamation"></i> ${esc(d.error)}`; return; }
            this.applyForm(d.form, true, true);
            this.dirty = true; this.saveDraft();
            const used = { custom: 'الگوریتمِ اختصاصی', generic: 'الگوریتمِ عمومی', 'custom+generic': 'الگوریتمِ اختصاصی + عمومی' }[d.parser_used] || '';
            st.className = 'vr-parse-st text-[11px] font-bold mt-1.5 text-emerald-600';
            st.innerHTML = `<i class="fas fa-circle-check"></i> ${fa(d.found)} مورد پیدا شد (${used}). خانه‌های زرد را بررسی کنید.${d.parser_error ? ` <span class="text-amber-600">· خطای پارسر: ${esc(d.parser_error)}</span>` : ''}`;
        }

        // ---------------- اعتبارسنجی و ارسال ----------------
        validate(form) {
            const pl = form.plate;
            const filled = [pl.p1, pl.letter, pl.p2, pl.iran].filter(Boolean).length;
            if (filled && filled < 4) return 'پلاک را کامل وارد کنید (هر چهار بخش).';
            const chassisF = this.cat.fields.find(f => f.excel_column === 'شماره شاسی');
            if (!filled && !(chassisF && form.fields[chassisF.field_key])) return 'پلاک یا شماره شاسی را وارد کنید.';
            if (!form.insured.name) return 'نام بیمه‌گذار را وارد کنید.';
            for (const f of this.cat.fields) {
                if (!f.required || ['PartsStatus', 'Checkbox'].includes(f.field_type)) continue;
                const w = this.root.querySelector(`[data-wrap="${f.field_key}"]`);
                if (w && w.classList.contains('vr-hide')) continue;
                if (f.field_type === 'DamageList') { if (!form.damages.length) return `حداقل یک «${f.label}» وارد کنید.`; continue; }
                if (!form.fields[f.field_key]) { const el = this.fieldEl(f.field_key); if (el) { el.focus(); el.classList.add('vr-flash'); } return `فیلدِ «${f.label}» را پر کنید.`; }
            }
            return null;
        }
        formData(action) {
            const form = this.collect();
            const fd = new FormData();
            fd.append('action', action);
            if (this.mode === 'edit') fd.append('id', this.existing.id); else fd.append('category_id', this.cat.id);
            fd.append('report_date', en(this.$(`#${this.id('date')}`).value));
            fd.append('form', JSON.stringify(form));
            return [fd, form];
        }
        async preview() {
            if (!this.cat) return;
            const btn = this.$('.vr-preview');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ساخت...';
            const [fd] = this.formData('preview');
            try {
                const res = await fetch(API, { method: 'POST', body: fd });
                const type = res.headers.get('Content-Type') || '';
                if (type.includes('pdf')) {
                    const url = URL.createObjectURL(await res.blob());
                    const m = modal({ title: 'پیش‌نمایشِ گزارش', icon: 'fa-eye', width: '60rem', html: `<iframe src="${url}" class="w-full rounded-xl border border-slate-200 bg-white" style="height:78vh"></iframe>
                        <p class="text-[11px] text-slate-400 font-bold mt-2">این فقط پیش‌نمایش است و ذخیره نشده است.</p>`, onClose: () => URL.revokeObjectURL(url) });
                    m.setTitle('پیش‌نمایشِ گزارش', esc(this.cat.name));
                } else { const d = await res.json(); toast(d.error || 'ساخت پیش‌نمایش ممکن نشد.', 'error'); }
            } catch (e) { toast('خطا در ساختِ پیش‌نمایش.', 'error'); }
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-eye"></i> پیش‌نمایش PDF';
        }
        async submit() {
            if (this.busy || !this.cat) return;
            const [fd, form] = this.formData(this.mode === 'edit' ? 'edit' : 'issue');
            const err = this.validate(form);
            if (err) { toast(err, 'error'); return; }
            if (this.mode === 'edit') {
                const pw = await askPassword('تاییدِ ویرایش', 'برای ذخیره‌ی ویرایش، رمزِ پنلِ کاربریِ خودتان را وارد کنید. فایل‌های قبلی با «(old)» در همان پوشه نگه داشته می‌شوند.');
                if (pw === null) return;
                fd.append('password', pw);
                fd.append('remove_photos', JSON.stringify([...this.removePhotos]));
            }
            if (this.mode === 'health') { fd.append('health_inspection_id', this.health.inspection.id); fd.append('health_photos', JSON.stringify([...this.healthSel])); }
            this.uploads.forEach(f => fd.append('photos[]', f, f.name));
            this.busy = true;
            const btn = this.$('.vr-submit'), bar = this.$('.vr-progress'), st = this.$('.vr-status');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ساخت...';
            bar.classList.remove('hidden');
            const setP = (p, t) => { bar.firstElementChild.style.width = Math.round(p * 100) + '%'; st.textContent = t; };
            setP(0.05, 'در حال ارسال...');
            const d = await apiForm(fd, p => setP(0.05 + p * 0.6, `ارسالِ اطلاعات و عکس‌ها... ${fa(Math.round(p * 100))}٪`));
            setP(1, d.ok ? 'انجام شد' : '');
            this.busy = false;
            if (!d.ok) {
                btn.disabled = false; btn.innerHTML = `<i class="fas ${this.mode === 'edit' ? 'fa-floppy-disk' : 'fa-file-circle-check'}"></i> ${this.mode === 'edit' ? 'ذخیره‌ی ویرایش' : 'صدور گزارش'}`;
                bar.classList.add('hidden'); st.textContent = '';
                if (window.showAlert) showAlert('ذخیره نشد', d.error || 'خطا', 'error'); else toast(d.error, 'error');
                return;
            }
            this.clearDraft();
            this.dirty = false;
            this.success(d);
        }
        success(d) {
            const r = d.report;
            const link = d.link;
            this.root.innerHTML = `<div class="vr-card p-6 sm:p-10 text-center max-w-2xl mx-auto vr-pop">
                <div class="vr-check flex justify-center mb-3"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-15"/></svg></div>
                <h3 class="font-black text-slate-800 text-lg">${this.mode === 'edit' ? 'ویرایش ذخیره شد' : 'گزارش صادر شد'}</h3>
                <p class="text-xs text-slate-400 font-bold mt-1">شماره گزارش <span dir="ltr" class="text-indigo-600 font-black">${esc(r.report_no)}</span>${r.version > 1 ? ` · نسخه ${fa(r.version)}` : ''}</p>
                <div class="flex justify-center my-4">${plateHtml(r.plate_display)}</div>
                <p class="text-sm font-bold text-slate-600">${esc(r.insured_name)} · ${esc(r.category_name)}</p>
                ${link ? (link.ok ? `<p class="vr-chip bg-emerald-50 text-emerald-600 mt-3"><i class="fas fa-link"></i> فایلِ گزارش در ${link.target === 'health' ? 'بازدید سلامت' : link.target === 'company' ? 'ردیفِ شرکتی' : 'پرونده‌ی درخواست'} هم قرار گرفت${link.final ? ' و بازدید تاییدِ نهایی شد' : ''}.</p>`
                    : `<p class="vr-chip bg-amber-50 text-amber-700 mt-3"><i class="fas fa-triangle-exclamation"></i> ${esc(link.error || 'اتصال انجام نشد')}</p>`) : ''}
                <div class="flex flex-wrap justify-center gap-2 mt-6">
                    <a class="vr-btn vr-btn-p" target="_blank" href="${fileUrl(r.id, 'pdf', '&inline=1')}"><i class="fas fa-file-pdf"></i> مشاهده PDF</a>
                    ${r.has_docx ? `<a class="vr-btn vr-btn-s" href="${fileUrl(r.id, 'docx')}"><i class="fas fa-file-word text-blue-600"></i> Word</a>` : ''}
                    ${r.has_zip ? `<a class="vr-btn vr-btn-s" href="${fileUrl(r.id, 'zip')}"><i class="fas fa-file-zipper text-amber-500"></i> ZIP عکس‌ها</a>` : ''}
                    <button type="button" class="vr-btn vr-btn-s vr-s-detail"><i class="fas fa-circle-info"></i> جزئیات</button>
                    ${this.opts.inModal ? '<button type="button" class="vr-btn vr-btn-s vr-s-close"><i class="fas fa-xmark"></i> بستن</button>' : '<button type="button" class="vr-btn vr-btn-g vr-s-new"><i class="fas fa-plus"></i> گزارشِ جدید</button>'}
                </div></div>`;
            const det = this.root.querySelector('.vr-s-detail');
            if (det) det.onclick = () => VR.openDetail(r.id);
            const nw = this.root.querySelector('.vr-s-new');
            if (nw) nw.onclick = () => { this.mode = 'new'; this.cat = null; this.parts = {}; this.damages = []; this.uploads = []; this.insuredId = null; this.init(); };
            const cl = this.root.querySelector('.vr-s-close');
            if (cl && this.opts.close) cl.onclick = () => this.opts.close();
            if (this.opts.onDone) this.opts.onDone(r, d);
            window.dispatchEvent(new CustomEvent('vr:changed', { detail: r }));
        }
    }

    // عدد به حروف (مثلِ سرور)
    function numWords(n) {
        n = String(n).replace(/^0+/, '');
        if (!n) return 'صفر';
        const ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
        const teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        const tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
        const hunds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
        const scales = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوادریلیون'];
        const three = x => { const p = []; if (x >= 100) { p.push(hunds[Math.floor(x / 100)]); x %= 100; } if (x >= 10 && x <= 19) { p.push(teens[x - 10]); } else { if (x >= 20) { p.push(tens[Math.floor(x / 10)]); x %= 10; } if (x) p.push(ones[x]); } return p.join(' و '); };
        const groups = [];
        for (let i = 0; n.length; i++) {
            const chunk = Number(n.slice(-3)); n = n.slice(0, -3);
            if (chunk) groups.unshift(three(chunk) + (scales[i] ? ' ' + scales[i] : ''));
        }
        return groups.join(' و ');
    }

    // ------------------------------------------------------------------
    //  API عمومیِ ماژول برای پنل
    // ------------------------------------------------------------------
    const VR = window.VR = window.VR || {};
    Object.assign(VR, { api, apiForm, modal, askPassword, lightbox, plateHtml, fa, en, esc, money, fileUrl, injectCss, boot, numWords, Builder, API });

    VR.initBuildTab = function () {
        const root = document.getElementById('vr-build-root');
        if (!root) return;
        if (root._builder && root._builder.dirty) return;   // کارِ نیمه‌تمام پاک نشود
        root._builder = new Builder(root, { mode: 'new' });
    };
    // پاپ‌آپ: ساختِ گزارش از روی یک بازدید سلامت
    VR.openHealthBuilder = function (healthId, onDone) {
        const m = modal({ title: 'ساخت گزارش بازدید', icon: 'fa-file-circle-plus', width: '76rem' });
        m.setTitle('ساخت گزارش بازدید', 'اطلاعات و عکس‌های همین بازدید سلامت پیش‌پر شده است؛ بعد از صدور، PDF مستقیم در همین بازدید قرار می‌گیرد.');
        new Builder(m.body, { mode: 'health', healthId, inModal: true, close: m.close, onDone });
        return m;
    };
    VR.openEditor = function (reportId, onDone) {
        const m = modal({ title: 'ویرایش گزارش', icon: 'fa-pen-to-square', width: '76rem' });
        new Builder(m.body, { mode: 'edit', reportId, inModal: true, close: m.close, onDone });
        return m;
    };
})();
