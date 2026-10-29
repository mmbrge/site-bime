// فایل: doc-proc-ui.js
// «پردازشِ مدرک» با سه روش (منطق: api/_docproc.php) — مشترک برای مدارکِ ردیف‌های درخواست و پنلِ صدور:
//   DocProc.menu(دکمه, cfg)  منوی کشویی: پردازش هوشمند / پردازش دقیق (کند) / پردازش دقیق (سریع) / نمایشِ نتیجه‌ها
//   DocProc.open(cfg, روش)   پنجره‌ی نتیجه: هر روش یک زبانه (چه کسی، کی، چقدر طول کشید، موتورها)، مقایسه با ردیف،
//                            ویرایشِ دستیِ هر مقدار، «اصلاحِ همه‌ی اختلاف‌ها» یا «اصلاحِ انتخاب‌شده‌ها»، و زبانه‌ی «همه‌ی روش‌ها»
// هر مدرک با هر روش یک بار پردازش می‌شود؛ انتخابِ دوباره‌ی همان روش فقط نتیجه‌اش را نشان می‌دهد.
// cfg: { title, load(): Promise<نتیجه‌ها>, run(method): Promise, apply(method, values): Promise, force?(method), remove?(), extra?: [[برچسب, تابع]], onChange?() }
(function () {
    'use strict';
    if (window.DocProc) return;
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const ask = (t, x, o) => window.uiConfirm ? uiConfirm(t, x, o || {}) : Promise.resolve(window.confirm(x));
    const ltr = v => /^[\x20-\x7e]+$/.test(String(v || '')) ? 'dir="ltr"' : '';
    const ICON = { smart: 'fa-wand-magic-sparkles', slow: 'fa-magnifying-glass-plus', fast: 'fa-bolt' };
    const NAMES = { smart: 'پردازش هوشمند', slow: 'پردازش دقیق (کند)', fast: 'پردازش دقیق (سریع)' };
    const HINT = { smart: 'متنِ فایل ← قاعده ← هوشِ مصنوعی؛ اگر فیلدِ لازمی نماند، با عکس', slow: 'همان به‌اضافه‌ی بررسیِ کامل با خودِ عکس (کندتر، دقیق‌تر)', fast: 'خودِ عکس با هوشِ مصنوعیِ بیرونی و مدلِ سریع؛ فقط فیلدها' };
    const ENG = { rules: 'قاعده', ai: 'هوشِ مصنوعیِ متنی', vision: 'عکس', fast: 'هوشِ مصنوعیِ بیرونی (سریع)', ocr: 'OCR' };

    function css() {
        if (document.getElementById('dpu-css')) return;
        const s = document.createElement('style'); s.id = 'dpu-css';
        s.textContent = `
        .dpu-menu{position:fixed;z-index:2147482000;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 24px 60px -18px rgba(15,23,42,.35);padding:6px;min-width:270px;direction:rtl;font-family:inherit;animation:dpu-in .14s ease-out}
        @keyframes dpu-in{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:none}}
        .dpu-mi{display:flex;align-items:center;gap:10px;width:100%;text-align:right;padding:9px 10px;border-radius:11px;font-size:12px;font-weight:800;color:#334155;background:none;border:0;cursor:pointer;font-family:inherit}
        .dpu-mi:hover:not(:disabled){background:#f5f3ff;color:#5b21b6}.dpu-mi:disabled{opacity:.45;cursor:not-allowed}
        .dpu-mi i.ic{width:30px;height:30px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#7c3aed;flex:none}
        .dpu-mi small{display:block;font-size:10px;font-weight:600;color:#94a3b8;margin-top:1px}
        .dpu-b{margin-right:auto;font-size:9.5px;font-weight:900;border-radius:999px;padding:2px 8px;white-space:nowrap}
        .dpu-sep{height:1px;background:#f1f5f9;margin:4px 6px}
        .dpu-ov{position:fixed;inset:0;z-index:1000020;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:12px}
        .dpu-box{background:#f8fafc;border-radius:22px;width:min(1000px,100%);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 40px 90px -30px rgba(0,0,0,.6);direction:rtl}
        .dpu-hd{background:linear-gradient(120deg,#4c1d95,#6d28d9 55%,#0891b2);color:#fff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px}
        .dpu-tabs{display:flex;gap:6px;flex-wrap:wrap;padding:10px 14px 0}
        .dpu-tab{display:inline-flex;align-items:center;gap:6px;border-radius:12px 12px 0 0;padding:8px 12px;font-size:11.5px;font-weight:900;color:#475569;background:#e2e8f0;border:0;cursor:pointer;font-family:inherit}
        .dpu-tab.on{background:#fff;color:#5b21b6;box-shadow:0 -6px 16px -10px rgba(0,0,0,.25)}
        .dpu-body{background:#fff;margin:0 14px 14px;border-radius:0 14px 14px 14px;padding:14px;overflow:auto;flex:1}
        .dpu-meta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
        .dpu-chip{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:3px 10px;font-size:10.5px;font-weight:800;background:#f1f5f9;color:#475569}
        .dpu-t{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
        .dpu-t th{background:#f8fafc;color:#64748b;font-weight:800;padding:7px 8px;text-align:right;font-size:11px;position:sticky;top:0;z-index:1}
        .dpu-t td{padding:6px 8px;border-top:1px solid #f1f5f9;vertical-align:middle}
        .dpu-t tr.diff td{background:#fff7ed}.dpu-t tr.empty td{background:#f0f9ff}
        .dpu-in{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:5px 8px;font-size:12px;font-weight:800;font-family:inherit;background:#fff;outline:none}
        .dpu-in:focus{border-color:#8b5cf6;box-shadow:0 0 0 3px #ede9fe}.dpu-in.ch{border-color:#f59e0b;background:#fffbeb}
        .dpu-st{font-size:10px;font-weight:900;border-radius:999px;padding:2px 8px;white-space:nowrap}
        .dpu-btn{display:inline-flex;align-items:center;gap:6px;border-radius:12px;padding:8px 14px;font-size:11.5px;font-weight:900;border:0;cursor:pointer;font-family:inherit}
        .dpu-btn:disabled{opacity:.5;cursor:default}
        .dpu-go{color:#fff;background:linear-gradient(120deg,#7c3aed,#0891b2)}.dpu-warn{color:#fff;background:#d97706}.dpu-g{background:#f1f5f9;color:#334155}.dpu-red{background:#e11d48;color:#fff}
        .dpu-kv{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:6px;margin-top:8px}
        .dpu-kv div{background:#f8fafc;border-radius:10px;padding:6px 9px;font-size:11px}.dpu-kv span{display:block;color:#94a3b8;font-size:10px;font-weight:700}.dpu-kv b{word-break:break-word}
        `;
        document.head.appendChild(s);
    }
    const badge = v => {
        if (!v) return ['آماده', '#f1f5f9', '#64748b'];
        return { OK: ['✓ پردازش شده', '#dcfce7', '#15803d'], MISMATCH: ['خودروی دیگر؟', '#ffe4e6', '#be123c'], WAIT: ['در صف', '#ede9fe', '#6d28d9'], FAIL: ['ناموفق', '#fee2e2', '#b91c1c'] }[v.status] || ['—', '#f1f5f9', '#64748b'];
    };
    const done = v => v && (v.status === 'OK' || v.status === 'MISMATCH');

    // ---------------- منوی کشویی ----------------
    let openMenu = null;
    function closeMenu() { if (openMenu) { openMenu.remove(); openMenu = null; } }
    document.addEventListener('mousedown', e => { if (openMenu && !openMenu.contains(e.target) && !e.target.closest('[data-dpu-anchor]')) closeMenu(); }, true);
    async function menu(btn, cfg) {
        css();
        if (openMenu && openMenu._btn === btn) { closeMenu(); return; }
        closeMenu();
        const m = document.createElement('div'); m.className = 'dpu-menu'; m._btn = btn;
        btn.setAttribute('data-dpu-anchor', '1');
        m.innerHTML = '<div class="p-3 text-[11px] text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ خواندنِ وضعیت…</div>';
        document.body.appendChild(m); openMenu = m;
        const place = () => {
            const r = btn.getBoundingClientRect(), w = m.offsetWidth, h = m.offsetHeight;
            let left = Math.max(8, Math.min(r.right - w, document.documentElement.clientWidth - w - 8));
            let top = r.bottom + 6; if (top + h > window.innerHeight - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
            m.style.left = left + 'px'; m.style.top = top + 'px';
        };
        place();
        const d = await cfg.load();
        if (openMenu !== m) return;
        if (!d.ok) { m.innerHTML = `<div class="p-3 text-[11px] text-rose-600">${esc(d.error || 'خطا')}</div>`; return; }
        const av = d.available || {};
        m.innerHTML = Object.keys(NAMES).map(k => {
            const v = d.methods[k], b = badge(v), a = av[k] || {};
            return `<button type="button" class="dpu-mi" data-m="${k}" ${!a.ok && !done(v) ? `disabled title="${esc(a.why || '')}"` : ''}><i class="fas ${ICON[k]} ic"></i><span>${NAMES[k]}<small>${done(v) ? 'نتیجه‌ی قبلی نشان داده می‌شود' : esc(a.ok ? HINT[k] : (a.why || ''))}</small></span><span class="dpu-b" style="background:${b[1]};color:${b[2]}">${b[0]}</span></button>`;
        }).join('') + `<div class="dpu-sep"></div><button type="button" class="dpu-mi" data-m="view"><i class="fas fa-table-columns ic" style="color:#0891b2"></i><span>نمایشِ نتیجه‌ها و مقایسه<small>همه‌ی روش‌ها، ویرایش و نشاندن روی ردیف</small></span></button>`
          + (cfg.extra || []).map((x, i) => `<button type="button" class="dpu-mi" data-x="${i}"><i class="fas ${x[2] || 'fa-file-lines'} ic" style="color:#64748b"></i><span>${esc(x[0])}${x[1] ? `<small>${esc(x[1])}</small>` : ''}</span></button>`).join('');
        place();
        m.onclick = async e => {
            const x = e.target.closest('[data-x]'); if (x) { closeMenu(); cfg.extra[+x.dataset.x][3](); return; }
            const b = e.target.closest('[data-m]'); if (!b || b.disabled) return;
            const k = b.dataset.m; closeMenu();
            if (k === 'view') { open(cfg); return; }
            if (done(d.methods[k])) { open(cfg, k); return; }
            await runAndOpen(cfg, k, btn);
        };
    }
    // منوی ساده با گزینه‌های دلخواه (مثلاً «پردازشِ همه‌ی مدارک»): items = [{icon, color, label, hint, badge: [متن، زمینه، رنگ], disabled, onClick}] یا '-'
    //   items می‌تواند Promise باشد (تا آماده شود «در حالِ خواندن…» دیده می‌شود)
    async function listMenu(btn, items) {
        css();
        if (openMenu && openMenu._btn === btn) { closeMenu(); return; }
        closeMenu();
        const m = document.createElement('div'); m.className = 'dpu-menu'; m._btn = btn;
        btn.setAttribute('data-dpu-anchor', '1');
        m.innerHTML = '<div class="p-3 text-[11px] text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ خواندنِ وضعیت…</div>';
        document.body.appendChild(m); openMenu = m;
        const place = () => {
            const r = btn.getBoundingClientRect(), w = m.offsetWidth, h = m.offsetHeight;
            m.style.left = Math.max(8, Math.min(r.right - w, document.documentElement.clientWidth - w - 8)) + 'px';
            let top = r.bottom + 6; if (top + h > window.innerHeight - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
            m.style.top = top + 'px';
        };
        place();
        const list = await items;
        if (openMenu !== m) return;
        if (!Array.isArray(list)) { m.innerHTML = `<div class="p-3 text-[11px] text-rose-600">${esc((list && list.error) || 'خطا')}</div>`; return; }
        m.innerHTML = list.map((x, i) => x === '-' ? '<div class="dpu-sep"></div>'
            : `<button type="button" class="dpu-mi" data-i="${i}" ${x.disabled ? 'disabled' : ''}><i class="fas ${x.icon || 'fa-circle'} ic" ${x.color ? `style="color:${x.color}"` : ''}></i><span>${esc(x.label)}${x.hint ? `<small>${esc(x.hint)}</small>` : ''}</span>${x.badge ? `<span class="dpu-b" style="background:${x.badge[1]};color:${x.badge[2]}">${esc(x.badge[0])}</span>` : ''}</button>`).join('');
        place();
        m.onclick = e => { const b = e.target.closest('[data-i]'); if (!b || b.disabled) return; closeMenu(); list[+b.dataset.i].onClick(); };
    }
    async function runAndOpen(cfg, k, btn) {
        const lbl = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i>${NAMES[k]}…`; }
        const t0 = Date.now();
        const r = await cfg.run(k);
        if (btn) { btn.disabled = false; btn.innerHTML = lbl; }
        if (!r.ok && r.pending) { toast(r.error || 'در صفِ سیستمِ داخلی است؛ جوابش که برسد نتیجه همین‌جا دیده می‌شود.', 'info'); if (cfg.onChange) cfg.onChange(); return; }
        if (!r.ok) { toast(r.error || 'پردازش نشد.', 'error'); open(cfg, k); return; }
        if (!r.cached) toast(`${NAMES[k]} انجام شد (${fa(((Date.now() - t0) / 1000).toFixed(1))} ثانیه).`, 'success');
        if (cfg.onChange && !r.cached) cfg.onChange();
        open(cfg, k);
    }

    // ---------------- پنجره‌ی نتیجه ----------------
    async function open(cfg, method) {
        css();
        const ov = document.createElement('div'); ov.className = 'dpu-ov';
        ov.innerHTML = `<div class="dpu-box"><div class="dpu-hd"><div class="min-w-0"><p class="text-[10.5px] opacity-80"><i class="fas fa-wand-magic-sparkles ml-1"></i>نتیجه‌ی پردازشِ مدرک</p><b class="text-sm" data-title>${esc(cfg.title || '')}</b></div>
            <button type="button" class="dpu-btn dpu-g" data-x>بستن</button></div><div class="dpu-tabs" data-tabs></div><div class="dpu-body" data-body><p class="text-xs text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ بارگذاری…</p></div></div>`;
        document.body.appendChild(ov);
        let D = null, cur = method || null, changed = false;
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); if (changed && cfg.onChange) cfg.onChange(); };
        const onKey = e => { if (e.key === 'Escape') close(); };
        document.addEventListener('keydown', onKey);
        ov.addEventListener('click', e => { if (e.target === ov || e.target.closest('[data-x]')) close(); });
        const reload = async () => {
            D = await cfg.load();
            if (!D.ok) { ov.querySelector('[data-body]').innerHTML = `<p class="text-xs text-rose-600">${esc(D.error || 'خطا')}</p>`; return; }
            if (D.title_text) ov.querySelector('[data-title]').textContent = D.title_text;
            if (!cur) cur = Object.keys(NAMES).find(k => done(D.methods[k])) || 'all';
            paint();
        };
        const paint = () => {
            const tabs = Object.keys(NAMES).map(k => { const b = badge(D.methods[k]); return `<button type="button" class="dpu-tab ${cur === k ? 'on' : ''}" data-t="${k}"><i class="fas ${ICON[k]}"></i>${NAMES[k]}<span class="dpu-b" style="background:${b[1]};color:${b[2]};margin:0">${b[0]}</span></button>`; }).join('')
                + `<button type="button" class="dpu-tab ${cur === 'all' ? 'on' : ''}" data-t="all"><i class="fas fa-table-columns"></i>همه‌ی روش‌ها</button>`;
            ov.querySelector('[data-tabs]').innerHTML = tabs;
            ov.querySelectorAll('[data-t]').forEach(b => b.onclick = () => { cur = b.dataset.t; paint(); });
            const body = ov.querySelector('[data-body]');
            body.innerHTML = cur === 'all' ? allHtml() : methodHtml(cur);
            bind(body);
        };
        // «خودروی دیگر؟»: شاسی/موتور/پلاکِ مدرک با ردیف نمی‌خواند. در زبانه‌ی هر روش، مقدارِ خوانده‌شده (مثلاً پلاکی که اشتباه خوانده شده)
        // همین‌جا اصلاح می‌شود (روی ردیف نمی‌نشیند)؛ اگر با ردیف یکی شد هشدار برطرف می‌شود
        const mismatchHtml = () => {
            const v = cur && cur !== 'all' ? D.methods[cur] : null, list = v ? (v.mismatch || []) : (D.mismatch || []);
            if (!list.length) return '';
            const btns = `${cfg.remove ? '<button type="button" class="dpu-btn dpu-red" data-rm><i class="fas fa-trash-can"></i>حذفِ این مدرک از این خودرو</button>' : ''}${cfg.force ? '<button type="button" class="dpu-btn dpu-g" data-force>مالِ همین خودرو است؛ خانه‌های خالی پر شود</button>' : ''}`;
            if (!v || !cfg.edit) return `<div class="rounded-xl border border-rose-200 bg-rose-50 p-3 mb-3"><p class="text-[11.5px] font-black text-rose-700 mb-1"><i class="fas fa-triangle-exclamation ml-1"></i>این مدرک به نظر مالِ خودروی دیگری است؛ خودکار چیزی روی ردیف ننشست.</p>
                ${list.map(x => `<p class="text-[11px]">${esc(x.label)}: ردیف <b ${ltr(x.row)}>${esc(fa(x.row))}</b> · مدرک <b ${ltr(x.doc)}>${esc(fa(x.doc))}</b></p>`).join('')}<div class="flex flex-wrap gap-2 mt-2">${btns}</div></div>`;
            return `<div class="rounded-xl border border-rose-200 bg-rose-50 p-3 mb-3"><p class="text-[12px] font-black text-rose-700 mb-1"><i class="fas fa-triangle-exclamation ml-1"></i>خطا: ${esc(list.map(x => x.label).join('، '))}ِ مدرک با این ردیف نمی‌خواند — شاید مدرکِ خودروی دیگری است، یا اشتباه خوانده شده.</p>
                <p class="text-[10.5px] text-rose-900/80 mb-2">اگر اشتباه خوانده شده، مقدارِ درست را همین‌جا بنویسید (یا «همانِ ردیف» را بزنید) و «ذخیره‌ی اصلاحِ مدرک» را بزنید؛ اگر با ردیف یکی شد، هشدار برطرف می‌شود و بعد بقیه‌ی اطلاعات را روی ردیف بنشانید. اگر ردیف اشتباه است، از جدولِ پایین مقدارِ مدرک را روی ردیف بنشانید.</p>
                ${list.map(x => `<div class="flex flex-wrap items-center gap-2 mb-1.5"><b class="text-[11px] text-slate-700" style="min-width:92px">${esc(x.label)}</b><span class="text-[10.5px] text-slate-500">ردیف: <b ${ltr(x.row)}>${esc(fa(x.row))}</b></span>
                    <input class="dpu-in" style="max-width:240px" data-mm="${esc(x.field)}" data-row="${esc(x.row)}" value="${esc(x.doc)}" ${ltr(x.doc)} ${x.field === '__plate' ? 'placeholder="مثلِ ۲۱ایران - ۷۱۱ د ۱۶"' : ''}>
                    <button type="button" class="dpu-btn dpu-g" data-mmrow style="padding:4px 9px">همانِ ردیف</button></div>`).join('')}
                <div class="flex flex-wrap gap-2 mt-2"><button type="button" class="dpu-btn dpu-go" data-fixdoc><i class="fas fa-pen"></i>ذخیره‌ی اصلاحِ مدرک</button>${btns}</div></div>`;
        };
        const methodHtml = k => {
            const v = D.methods[k], a = (D.available || {})[k] || {};
            if (!v) return `<div class="text-center py-8"><i class="fas ${ICON[k]} text-3xl text-violet-300"></i><p class="text-sm font-black text-slate-700 mt-2">${NAMES[k]}</p><p class="text-[11px] text-slate-500 mt-1">${esc(HINT[k])}</p>
                ${a.ok ? `<button type="button" class="dpu-btn dpu-go mt-3" data-run="${k}"><i class="fas fa-play"></i>پردازش با این روش</button>${a.model ? `<p class="text-[10px] text-slate-400 mt-1" dir="ltr">${esc(a.model)}</p>` : ''}` : `<p class="text-[11px] text-amber-700 mt-3">${esc(a.why || '')}</p>`}</div>`;
            const meta = `<div class="dpu-meta"><span class="dpu-chip"><i class="fas fa-file-lines"></i>${esc(v.kind_fa || 'نوع: ؟')}</span>${v.user ? `<span class="dpu-chip"><i class="fas fa-user"></i>${esc(v.user)}</span>` : ''}<span class="dpu-chip"><i class="far fa-clock"></i>${esc(fa(v.at))}</span>
                ${v.ms ? `<span class="dpu-chip"><i class="fas fa-stopwatch"></i>${fa((v.ms / 1000).toFixed(1))} ثانیه</span>` : ''}${(v.engines || []).length ? `<span class="dpu-chip"><i class="fas fa-gears"></i>${esc(v.engines.map(e => ENG[e] || e).join(' ← '))}</span>` : ''}</div>`;
            if (v.status === 'WAIT') return meta + `<p class="text-[12px] text-violet-700 font-bold"><i class="fas fa-hourglass-half ml-1"></i>در صفِ سیستمِ داخلی است؛ جوابش که برسد اینجا دیده می‌شود.</p><button type="button" class="dpu-btn dpu-g mt-2" data-reload><i class="fas fa-rotate"></i>دوباره نگاه کن</button>`;
            if (v.status === 'FAIL') return meta + `<p class="text-[12px] text-rose-700 font-bold"><i class="fas fa-circle-exclamation ml-1"></i>${esc(v.error || 'خوانده نشد.')}</p>${a.ok ? `<button type="button" class="dpu-btn dpu-go mt-2" data-run="${k}"><i class="fas fa-rotate"></i>دوباره امتحان</button>` : ''}`;
            const cmp = v.compare || [];
            const rows = cmp.map((x, i) => {
                const val = x.edited != null ? x.edited : x.doc, unread = x.unread && x.edited == null;
                const st = unread ? ['خوانده نشد', '#fef3c7', '#b45309'] : x.same ? ['یکی', '#dcfce7', '#15803d'] : (x.row === '' ? ['ردیف خالی', '#e0f2fe', '#0369a1'] : ['اختلاف', '#ffedd5', '#c2410c']);
                const ph = x.field === '__plate' ? (unread ? 'خوانده نشد؛ پلاک را بنویسید، مثلِ ۲۱ایران - ۷۱۱ د ۱۶' : 'مثلِ ۲۱ایران - ۷۱۱ د ۱۶') : '';
                return `<tr class="${x.same ? '' : (x.row === '' || unread ? 'empty' : 'diff')}" data-i="${i}"><td style="width:28px"><input type="checkbox" data-ck style="accent-color:#7c3aed"></td><td class="font-bold text-slate-600">${esc(x.label)}</td>
                    <td ${ltr(x.row)}>${x.row ? esc(fa(x.row)) : '<span class="text-slate-300">—</span>'}</td><td style="min-width:220px"><input class="dpu-in ${x.edited != null && x.edited !== x.doc ? 'ch' : ''}" data-v value="${esc(val)}" ${ltr(val)} ${ph ? `placeholder="${esc(ph)}"` : ''}></td>
                    <td><span class="dpu-st" style="background:${st[1]};color:${st[2]}">${st[0]}</span></td></tr>`;
            }).join('');
            const nd = cmp.filter(x => !x.same).length;
            return mismatchHtml() + meta + (cmp.length ? `<div class="overflow-auto rounded-xl border border-slate-100"><table class="dpu-t no-count no-page"><thead><tr><th></th><th>خانه</th><th>الان روی ردیف</th><th>از مدرک (ویرایش‌پذیر)</th><th>وضعیت</th></tr></thead><tbody>${rows}</tbody></table></div>
                <p class="text-[10.5px] text-slate-500 mt-2">هر مقدار را می‌توانید دستی درست کنید (خانه‌ی ویرایش‌شده خودکار انتخاب می‌شود)؛ بعد «اصلاحِ همه‌ی اختلاف‌ها» یا «اصلاحِ انتخاب‌شده‌ها» را بزنید تا روی ردیف بنشیند.</p>
                <div class="flex flex-wrap gap-2 mt-2"><button type="button" class="dpu-btn dpu-warn" data-all ${nd ? '' : 'disabled'}><i class="fas fa-check-double"></i>اصلاحِ همه‌ی اختلاف‌ها (${fa(nd)})</button>
                    <button type="button" class="dpu-btn dpu-go" data-sel><i class="fas fa-check"></i>اصلاحِ انتخاب‌شده‌ها</button>${cfg.edit ? '<button type="button" class="dpu-btn dpu-g" data-saveed title="مقدارهای ویرایش‌شده فقط کنارِ نتیجه‌ی این روش ذخیره می‌شود (مثلاً پلاکی که اشتباه خوانده شده)؛ ردیف عوض نمی‌شود"><i class="fas fa-floppy-disk"></i>فقط ذخیره‌ی ویرایش‌ها (بدونِ نشاندن)</button>' : ''}</div>`
                : '<p class="text-[11px] text-slate-500">خانه‌ای برای مقایسه با ردیف پیدا نشد.</p>')
                + (Object.keys(v.other || {}).length ? `<details class="mt-3"><summary class="text-[11px] font-bold text-slate-500 cursor-pointer">همه‌ی اطلاعاتِ خوانده‌شده با این روش (${fa(Object.keys(v.other).length)})</summary><div class="dpu-kv">${Object.entries(v.other).map(([a, b]) => `<div><span>${esc(a)}</span><b ${ltr(b)}>${esc(fa(b))}</b></div>`).join('')}</div></details>` : '');
        };
        // همه‌ی روش‌ها کنارِ هم: هر خانه، مقدارِ ردیف و جوابِ هر روش
        const allHtml = () => {
            const ks = Object.keys(NAMES).filter(k => done(D.methods[k]));
            if (!ks.length) return '<p class="text-[11px] text-slate-500 py-6 text-center">هنوز با هیچ روشی پردازش نشده؛ از زبانه‌ی هر روش «پردازش با این روش» را بزنید.</p>';
            const fields = {}, order = [];
            ks.forEach(k => (D.methods[k].compare || []).forEach(x => { if (!fields[x.field]) { fields[x.field] = { label: x.label, row: x.row, v: {} }; order.push(x.field); } fields[x.field].v[k] = x.edited != null ? x.edited : x.doc; }));
            return mismatchHtml() + `<div class="overflow-auto rounded-xl border border-slate-100"><table class="dpu-t no-count no-page"><thead><tr><th>خانه</th><th>الان روی ردیف</th>${ks.map(k => `<th><i class="fas ${ICON[k]} ml-1"></i>${NAMES[k]}</th>`).join('')}</tr></thead><tbody>
                ${order.map(f => { const x = fields[f]; const vals = ks.map(k => x.v[k] || ''); const agree = vals.filter(Boolean).every(v => v === vals.filter(Boolean)[0]);
                    return `<tr class="${agree ? '' : 'diff'}"><td class="font-bold text-slate-600">${esc(x.label)}</td><td ${ltr(x.row)}>${x.row ? esc(fa(x.row)) : '<span class="text-slate-300">—</span>'}</td>${vals.map(v => `<td ${ltr(v)}>${v ? esc(fa(v)) : '<span class="text-slate-300">—</span>'}</td>`).join('')}</tr>`; }).join('')}
                </tbody></table></div><p class="text-[10.5px] text-slate-500 mt-2">ردیف‌های نارنجی: روش‌ها با هم فرق دارند. برای نشاندن، زبانه‌ی همان روش را باز کنید.</p>`;
        };
        const bind = body => {
            body.querySelectorAll('[data-run]').forEach(b => b.onclick = async () => {
                b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ پردازش…';
                const t0 = Date.now(), r = await cfg.run(b.dataset.run);
                if (!r.ok && r.pending) toast(r.error || 'در صفِ سیستمِ داخلی است.', 'info');
                else if (!r.ok) toast(r.error || 'پردازش نشد.', 'error');
                else { toast(`${NAMES[b.dataset.run]} انجام شد (${fa(((Date.now() - t0) / 1000).toFixed(1))} ثانیه).`, 'success'); changed = true; }
                await reload();
            });
            const rl = body.querySelector('[data-reload]'); if (rl) rl.onclick = reload;
            body.querySelectorAll('[data-v]').forEach(inp => inp.addEventListener('input', () => { const tr = inp.closest('tr'); tr.querySelector('[data-ck]').checked = true; inp.classList.add('ch'); }));
            const collect = all => {
                const v = D.methods[cur], vals = {};
                body.querySelectorAll('tr[data-i]').forEach(tr => {
                    const x = v.compare[+tr.dataset.i], val = tr.querySelector('[data-v]').value.trim();
                    const edited = val !== (x.edited != null ? x.edited : x.doc);
                    if (all ? (!x.same || edited || tr.querySelector('[data-ck]').checked) : tr.querySelector('[data-ck]').checked) if (val !== '') vals[x.field] = val;
                });
                return vals;
            };
            const doApply = async (all, btn) => {
                const vals = collect(all);
                if (!Object.keys(vals).length) { toast(all ? 'اختلافی نیست.' : 'خانه‌ای انتخاب نشده است.', 'info'); return; }
                const mis = (D.methods[cur].mismatch || []).filter(x => !(x.field in vals));
                const warn = (vals.__plate ? `\nپلاکِ ردیف به «${fa(vals.__plate)}» عوض می‌شود.` : '')
                    + (mis.length ? `\nهشدار: ${mis.map(x => x.label).join('، ')}ِ مدرک با ردیف نمی‌خواند؛ اگر مدرک مالِ خودروی دیگری است اطلاعاتِ اشتباه روی ردیف می‌نشیند.` : '');
                if (!(await ask('نشاندن روی ردیف', `${fa(Object.keys(vals).length)} خانه با مقدارِ این روش (و ویرایش‌های شما) روی ردیف نوشته می‌شود؛ مقدارِ فعلیِ آن خانه‌ها عوض می‌شود.${warn}`, { ok: 'بله، بنشیند', danger: !!mis.length }))) return;
                btn.disabled = true;
                const r = await cfg.apply(cur, vals);
                btn.disabled = false;
                if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                changed = true;
                toast(`${fa(Object.keys(r.applied || {}).length)} خانه روی ردیف نشست.`, 'success');
                await reload();
            };
            // فقط اصلاحِ مقدارهای خوانده‌شده (پلاک/شاسی/...) بدونِ نوشتن روی ردیف
            const saveEdits = async (vals, btn) => {
                if (!Object.keys(vals).length) { toast('مقداری عوض نشده است.', 'info'); return; }
                btn.disabled = true;
                const r = await cfg.edit(cur, vals);
                btn.disabled = false;
                if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                changed = true;
                toast(r.resolved === true ? 'اصلاح ذخیره شد؛ شاسی/موتور/پلاکِ مدرک حالا با ردیف می‌خواند.' : r.resolved === false ? 'اصلاح ذخیره شد، ولی هنوز با ردیف نمی‌خواند.' : 'اصلاح ذخیره شد.', r.resolved === false ? 'info' : 'success');
                await reload();
            };
            body.querySelectorAll('[data-mmrow]').forEach(b => b.onclick = () => { const i = b.parentElement.querySelector('[data-mm]'); i.value = i.dataset.row; });
            const fd = body.querySelector('[data-fixdoc]');
            if (fd) fd.onclick = () => { const vals = {}; body.querySelectorAll('[data-mm]').forEach(i => { if (i.value.trim()) vals[i.dataset.mm] = i.value.trim(); }); saveEdits(vals, fd); };
            const se = body.querySelector('[data-saveed]');
            if (se) se.onclick = () => {
                const v = D.methods[cur], vals = {};
                body.querySelectorAll('tr[data-i]').forEach(tr => { const x = v.compare[+tr.dataset.i], val = tr.querySelector('[data-v]').value.trim(); if (val !== '' && val !== (x.edited != null ? x.edited : x.doc)) vals[x.field] = val; });
                saveEdits(vals, se);
            };
            const ba = body.querySelector('[data-all]'); if (ba) ba.onclick = () => doApply(true, ba);
            const bs = body.querySelector('[data-sel]'); if (bs) bs.onclick = () => doApply(false, bs);
            const rm = body.querySelector('[data-rm]');
            if (rm) rm.onclick = async () => {
                if (!(await ask('حذفِ مدرک از این خودرو', 'این مدرک از این ردیف و از روی سرور حذف می‌شود. مطمئنید؟', { danger: true, ok: 'بله، حذف شود' }))) return;
                const r = await cfg.remove(); if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                changed = true; toast('مدرک از این خودرو حذف شد.', 'success'); close();
            };
            const fc = body.querySelector('[data-force]');
            if (fc) fc.onclick = async () => { const r = await cfg.force(cur === 'all' ? Object.keys(NAMES).find(k => done(D.methods[k])) : cur); if (!r.ok) { toast(r.error || 'خطا', 'error'); return; } changed = true; toast('خانه‌های خالیِ ردیف پر شد.', 'success'); await reload(); };
        };
        await reload();
    }

    window.DocProc = { menu, open, run: runAndOpen, listMenu, NAMES, ICON, HINT };
})();
