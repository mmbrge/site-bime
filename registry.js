// فایل: registry.js
// «پرونده‌ها»: سه صفحه با یک ظاهر
//   Vehicles.init(root, {portal, api})   پرونده‌ی خودروها: فهرستِ قابلِ جستجو/فیلتر + پرونده‌ی هر پلاک (مشخصات، مالک، بیمه‌نامه‌ها، مدارک، اقساط، PDF)
//   Personnel.init(root)                  پرونده‌ی پرسنل: شرکتِ فعلی، نقل‌وانتقال، عکس، خانواده، بیمه‌ها، اقساطِ معوق، ورودِ لیستِ پرسنلی
//   Bordereau.init(root)                  بردروی پاسارگاد: ورودِ اکسل، فهرستِ کلی و شرکت‌به‌شرکت، تمدید، الحاقیه، خروجیِ اکسل/PDF
// API: api/vehicle_actions.php، api/personnel_actions.php، api/bordereau_actions.php
(function () {
    'use strict';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v == null ? '' : v).replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = v => { const n = Number(v) || 0; return n ? fa(Math.abs(n).toLocaleString('en-US')) : ''; };
    const norm = s => en(s).toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[‌\s\-_/.()]+/g, '');
    const toast = (m, t) => (typeof showToast === 'function' ? showToast(m, t || 'info') : alert(m));
    const fpath = (p, base) => (base || '/') + String(p || '').split('/').map(encodeURIComponent).join('/');
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

    async function call(url, body, isForm) {
        try {
            const r = await fetch(url, isForm ? { method: 'POST', body, credentials: 'same-origin' }
                : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' });
            return await r.json();
        } catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور' }; }
    }
    // دانلودِ فایل (اکسل/PDF) با POST
    async function download(url, body, fallbackName) {
        const fd = new FormData();
        Object.keys(body).forEach(k => fd.append(k, typeof body[k] === 'object' ? JSON.stringify(body[k]) : body[k]));
        try {
            const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
            const ct = r.headers.get('Content-Type') || '';
            if (ct.indexOf('json') >= 0) { const j = await r.json(); toast(j.error || 'خطا', 'error'); return; }
            const blob = await r.blob();
            const cd = r.headers.get('Content-Disposition') || '';
            const m = cd.match(/filename\*=UTF-8''([^;]+)/);
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob); a.download = m ? decodeURIComponent(m[1]) : fallbackName;
            document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
        } catch (e) { toast('دانلود ممکن نشد.', 'error'); }
    }

    function css() {
        if (document.getElementById('rg-css')) return;
        const st = document.createElement('style');
        st.id = 'rg-css';
        st.textContent = `
        .rg-hero{position:relative;overflow:hidden;border-radius:24px;padding:20px 22px;color:#fff;background:linear-gradient(125deg,var(--a,#0f172a),var(--b,#1e3a8a) 55%,var(--c,#0e7490));box-shadow:0 18px 44px -24px rgba(30,58,138,.55)}
        .rg-hero::after{content:'';position:absolute;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%);left:-60px;top:-120px}
        .rg-hero>*{position:relative;z-index:1}
        .rg-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-top:14px}
        .rg-stat{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:16px;padding:10px 12px;cursor:pointer;transition:.15s}
        .rg-stat:hover,.rg-stat.on{background:rgba(255,255,255,.24)}
        .rg-stat b{display:block;font-size:20px;font-weight:900}.rg-stat small{font-size:10.5px;opacity:.85;font-weight:700}
        .rg-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:12px;box-shadow:0 4px 14px -8px rgba(15,23,42,.08)}
        .rg-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .rg-in{border:1.5px solid #e2e8f0;border-radius:12px;padding:8px 11px;font-size:12.5px;background:#fff;min-width:0}
        .rg-in:focus{outline:none;border-color:#818cf8;box-shadow:0 0 0 3px rgba(129,140,248,.18)}
        .rg-btn{border:0;border-radius:12px;padding:8px 13px;font-weight:900;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;transition:.15s}
        .rg-btn.p{background:#4f46e5;color:#fff}.rg-btn.p:hover{background:#4338ca}.rg-btn.s{background:#f1f5f9;color:#334155}.rg-btn.s:hover{background:#e2e8f0}
        .rg-btn.g{background:#059669;color:#fff}.rg-btn.r{background:#fff1f2;color:#e11d48}.rg-btn.a{background:#f59e0b;color:#fff}.rg-btn:disabled{opacity:.5;cursor:default}
        .rg-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
        .rg-tbl th{position:sticky;top:0;background:#f8fafc;color:#475569;font-weight:800;text-align:right;padding:9px 8px;border-bottom:1px solid #e2e8f0;white-space:nowrap;cursor:pointer;z-index:1}
        .rg-tbl td{padding:8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
        .rg-tbl tbody tr{cursor:pointer;transition:background .12s}.rg-tbl tbody tr:hover{background:#f8fafc}
        .rg-wrap{max-height:68vh;overflow:auto;border:1px solid #f1f5f9;border-radius:14px}
        .rg-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 9px;font-size:10.5px;font-weight:900;white-space:nowrap}
        .rg-left{display:inline-flex;align-items:center;justify-content:center;min-width:46px;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:900}
        .rg-plate{display:inline-flex;align-items:stretch;border:2px solid #0f172a;border-radius:7px;overflow:hidden;font-weight:900;font-size:13px;background:#fff;color:#0f172a;direction:ltr;line-height:1.5}
        .rg-plate .fl{width:10px;background:linear-gradient(#16a34a 33%,#fff 33% 66%,#dc2626 66%)}
        .rg-plate span{padding:1px 6px}.rg-plate .ir{border-left:2px solid #0f172a;display:flex;flex-direction:column;align-items:center;font-size:11px;line-height:1.1;padding:1px 5px}
        .rg-plate .ir small{font-size:7.5px;font-weight:700}
        .rg-thumb{width:64px;height:40px;border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden}
        .rg-thumb svg,.rg-thumb img{width:100%;height:100%;object-fit:contain}
        .rg-ov{position:fixed;inset:0;z-index:9400;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:14px}
        .rg-modal{width:min(1150px,100%);max-height:94vh;overflow:auto;background:#f8fafc;border-radius:24px;box-shadow:0 40px 80px -30px rgba(0,0,0,.5)}
        .rg-mh{position:sticky;top:0;z-index:3;padding:16px 18px;color:#fff;background:linear-gradient(125deg,var(--a,#0f172a),var(--b,#1e3a8a) 55%,var(--c,#0e7490));border-radius:24px 24px 0 0}
        .rg-tabs{display:flex;gap:6px;flex-wrap:wrap;padding:10px 14px;background:#fff;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:2}
        .rg-tab{border:0;background:#f1f5f9;color:#475569;border-radius:11px;padding:7px 12px;font-size:12px;font-weight:800;cursor:pointer}
        .rg-tab.on{background:#4f46e5;color:#fff}
        .rg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px}
        .rg-f{background:#fff;border:1px solid #e2e8f0;border-radius:13px;padding:8px 10px;position:relative}
        .rg-f small{display:block;font-size:10.5px;color:#64748b;font-weight:700}.rg-f b{font-size:12.5px;color:#0f172a;word-break:break-word}
        .rg-f .cp{position:absolute;left:6px;top:6px;border:0;background:transparent;color:#94a3b8;cursor:pointer;font-size:11px}.rg-f .cp:hover{color:#4f46e5}
        .rg-img{width:260px;max-width:100%;height:160px;border-radius:18px;background:rgba(255,255,255,.95);display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none}
        .rg-img svg,.rg-img img{width:100%;height:100%;object-fit:contain}
        .rg-car .vw{transform-box:fill-box;transform-origin:center}
        .rg-anim .vw,.rg-anim-h:hover .vw,tr:hover .rg-anim-h .vw,.rg-f:hover .rg-anim-h .vw{animation:rgSpin .8s linear infinite}
        .rg-anim .vb,.rg-anim-h:hover .vb,tr:hover .rg-anim-h .vb,.rg-f:hover .rg-anim-h .vb{animation:rgBob .42s ease-in-out infinite alternate}
        .rg-anim .vr,.rg-anim-h:hover .vr,tr:hover .rg-anim-h .vr,.rg-f:hover .rg-anim-h .vr{animation:rgRoad .5s linear infinite}
        .rg-anim .vl{animation:rgLamp 1.8s ease-in-out infinite}
        .rg-img .rg-anim{animation:rgIn 1s cubic-bezier(.2,.85,.25,1) both}
        @keyframes rgSpin{to{transform:rotate(360deg)}}
        @keyframes rgBob{from{transform:translateY(0)}to{transform:translateY(-2px)}}
        @keyframes rgRoad{to{stroke-dashoffset:30}}
        @keyframes rgLamp{0%,100%{fill:#fde68a}50%{fill:#fffbeb}}
        @keyframes rgIn{from{transform:translateX(-70%);opacity:0}to{transform:none;opacity:1}}
        @media (prefers-reduced-motion:reduce){.rg-car *,.rg-car{animation:none!important}}
        .rg-av{width:42px;height:42px;border-radius:14px;object-fit:cover;background:linear-gradient(135deg,#a78bfa,#ec4899);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:900;font-size:15px;flex:none}
        .rg-av.big{width:110px;height:120px;border-radius:20px;font-size:38px;border:3px solid rgba(255,255,255,.7)}
        .rg-chk{width:16px;height:16px}
        .rg-tl{border-right:3px solid #c7d2fe;padding-right:14px;margin-right:6px}
        .rg-tl>div{position:relative;margin-bottom:12px}.rg-tl>div::before{content:'';position:absolute;right:-21px;top:4px;width:11px;height:11px;border-radius:50%;background:#6366f1;border:2px solid #fff}
        .rg-acc{border:1px solid #e2e8f0;border-radius:16px;background:#fff;margin-bottom:10px;overflow:hidden}
        .rg-acc>summary{list-style:none;cursor:pointer;padding:11px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-weight:900;font-size:13px}
        .rg-acc>summary::-webkit-details-marker{display:none}
        .rg-acc[open]>summary{background:#eef2ff}
        `;
        document.head.appendChild(st);
    }
    const leftCls = d => d === null || d === undefined ? 'background:#f1f5f9;color:#64748b' : d < 0 ? 'background:#ffe4e6;color:#be123c' : d <= 30 ? 'background:#ffedd5;color:#c2410c' : d <= 60 ? 'background:#fef9c3;color:#a16207' : 'background:#dcfce7;color:#15803d';
    const leftTxt = d => d === null || d === undefined ? '—' : d < 0 ? fa(-d) + ' روز گذشته' : d === 0 ? 'امروز' : fa(d) + ' روز';
    function plateHtml(p) {
        p = String(p || '');
        let m = p.match(/^(\d{2})ایران\s*-\s*(\d{3})\s+(\S+)\s+(\d{2})$/);
        if (m) return `<span class="rg-plate"><span class="fl"></span><span>${fa(m[4])}</span><span>${esc(m[3])}</span><span>${fa(m[2])}</span><span class="ir"><small>ایران</small>${fa(m[1])}</span></span>`;
        m = p.match(/^(\d{3})-(\d{5})$/);
        if (m) return `<span class="rg-plate"><span class="fl"></span><span>${fa(m[2])}</span><span class="ir"><small>موتور</small>${fa(m[1])}</span></span>`;
        return p ? `<b>${esc(fa(p))}</b>` : '<span class="text-slate-400">بدونِ پلاک</span>';
    }
    const copyBtn = v => v ? `<button type="button" class="cp" data-copy="${esc(v)}" title="کپی"><i class="far fa-copy"></i></button>` : '';
    const field = (label, v, cp = true) => `<div class="rg-f"><small>${label}</small><b>${v === '' || v === null || v === undefined ? '—' : esc(fa(v))}</b>${cp ? copyBtn(v) : ''}</div>`;
    function bindCopy(el) {
        el.addEventListener('click', e => {
            const b = e.target.closest('[data-copy]'); if (!b) return;
            e.stopPropagation();
            try { navigator.clipboard.writeText(b.dataset.copy); toast('کپی شد.', 'success'); } catch (x) {}
        });
    }
    function modal(html, opts = {}) {
        const ov = document.createElement('div');
        ov.className = 'rg-ov';
        ov.innerHTML = `<div class="rg-modal" style="${opts.width ? 'width:min(' + opts.width + 'px,100%)' : ''}" dir="rtl">${html}</div>`;
        document.body.appendChild(ov);
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); if (opts.onClose) opts.onClose(); };
        const onKey = e => { if (e.key === 'Escape') close(); };
        document.addEventListener('keydown', onKey);
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        ov.querySelectorAll('[data-x]').forEach(b => b.onclick = close);
        bindCopy(ov);
        return { el: ov.firstElementChild, close };
    }
    const spinner = '<div class="p-10 text-center text-slate-400"><i class="fas fa-spinner fa-spin text-2xl"></i></div>';
    const empty = t => `<p class="text-center text-xs text-slate-400 py-6">${t}</p>`;
    const srcPill = s => ({ C: '<span class="rg-pill" style="background:#e0e7ff;color:#4338ca">شرکتی</span>', I: '<span class="rg-pill" style="background:#fef3c7;color:#92400e">وارداتی</span>',
        P: '<span class="rg-pill" style="background:#fce7f3;color:#be185d">کارکنان</span>', B: '<span class="rg-pill" style="background:#cffafe;color:#0e7490">بردرو</span>' })[s] || '';
    const instState = { PAID: ['پرداخت‌شده', '#dcfce7', '#15803d'], PARTIAL: ['ناقص', '#fef3c7', '#92400e'], OVERDUE: ['معوق', '#ffe4e6', '#be123c'], UNPAID: ['در انتظار', '#f1f5f9', '#475569'] };

    // =================================================================
    //  پرونده‌ی خودروها
    // =================================================================
    const VH = { root: null, api: 'api/vehicle_actions.php', portal: false, rows: [], view: [], f: { q: '', company: '', shape: '', src: '', exp: '' }, sort: { k: 'next_left', d: 1 }, meta: {} };
    const vUrl = (q) => VH.api + (VH.api.indexOf('?') >= 0 ? '&' : '?') + q;
    async function vInit(root, opts = {}) {
        css();
        VH.root = root; VH.portal = !!opts.portal; if (opts.api) VH.api = opts.api;
        root.innerHTML = spinner;
        const d = await call(VH.api, { action: 'list' });
        if (!d.ok) { root.innerHTML = `<div class="rg-card text-rose-600 text-sm">${esc(d.error)}</div>`; return; }
        VH.rows = d.rows; VH.meta = d; setTpl(d.tpl_svg);
        vRender();
    }
    function vFilter() {
        const q = norm(VH.f.q);
        const f = VH.f;
        VH.view = VH.rows.filter(r => {
            if (f.company && String(r.company_id) !== f.company) return false;
            if (f.shape && r.shape !== f.shape) return false;
            if (f.src && r.sources.indexOf(f.src) < 0) return false;
            const d = r.next_left;
            if (f.exp === '30' && !(d !== null && d >= 0 && d <= 30)) return false;
            if (f.exp === '60' && !(d !== null && d >= 0 && d <= 60)) return false;
            if (f.exp === 'expired' && !((r.body_left !== null && r.body_left < 0) || (r.third_left !== null && r.third_left < 0)) ) return false;
            if (f.exp === 'none' && r.policies) return false;
            if (f.exp === 'open' && !r.open_requests) return false;
            if (q && norm([r.plate, r.vin, r.engine, r.car, r.model_year, r.color, r.usage, r.owner, r.insured, r.company, r.person, r.last_policy, r.shape_fa].join(' ')).indexOf(q) < 0) return false;
            return true;
        });
        const k = VH.sort.k, dir = VH.sort.d;
        VH.view.sort((a, b) => {
            let x = a[k], y = b[k];
            if (x === null || x === undefined || x === '') x = typeof y === 'number' ? 99999 : 'ي';
            if (y === null || y === undefined || y === '') y = typeof x === 'number' ? 99999 : 'ي';
            return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'fa')) * dir;
        });
    }
    function vRender() {
        const R = VH.rows, f = VH.f;
        const cnt = { all: R.length, 30: R.filter(r => r.next_left !== null && r.next_left >= 0 && r.next_left <= 30).length,
            expired: R.filter(r => (r.body_left !== null && r.body_left < 0) || (r.third_left !== null && r.third_left < 0)).length, open: R.filter(r => r.open_requests).length, none: R.filter(r => !r.policies).length };
        VH.root.innerHTML = `
        <div class="rg-hero mb-4" style="--a:#0f172a;--b:#1e3a8a;--c:#0e7490">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-[11px] opacity-75">هر پلاک یک پرونده: مشخصات، مالک، بیمه‌نامه‌ها، مدارک، اقساط و گزارشِ PDF</p><h2 class="text-xl font-black mt-0.5"><i class="fas fa-car-side ml-1"></i>پرونده‌ی خودروها</h2></div>
                <div class="flex gap-2 flex-wrap">${!VH.portal ? '<button class="rg-btn s" data-models><i class="fas fa-images"></i> عکسِ مدل‌ها</button>' : ''}<button class="rg-btn s" data-xls><i class="fas fa-file-excel text-emerald-600"></i> اکسلِ فهرست</button></div>
            </div>
            <div class="rg-stats">${[['', 'all', 'همه‌ی خودروها'], ['30', '30', 'انقضا تا ۳۰ روز'], ['expired', 'expired', 'بیمه‌ی منقضی'], ['open', 'open', 'درخواستِ باز'], ['none', 'none', 'بدونِ بیمه‌نامه']]
                .map(([v, k, t]) => `<div class="rg-stat ${f.exp === v ? 'on' : ''}" data-exp="${v}"><b>${fa(cnt[k])}</b><small>${t}</small></div>`).join('')}</div>
        </div>
        <div class="rg-card mb-3"><div class="rg-bar">
            <input class="rg-in flex-1" style="min-width:220px" data-q placeholder="جستجو: پلاک، شاسی، موتور، خودرو، مالک، شرکت، بیمه‌نامه…" value="${esc(f.q)}">
            ${VH.meta.companies && VH.meta.companies.length > 1 ? `<select class="rg-in" data-f="company"><option value="">همه‌ی شرکت‌ها</option>${VH.meta.companies.map(c => `<option value="${c.id}" ${String(c.id) === f.company ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>` : ''}
            <select class="rg-in" data-f="shape"><option value="">همه‌ی نوع‌ها</option>${Object.entries(VH.meta.shapes || {}).map(([k, t]) => `<option value="${k}" ${k === f.shape ? 'selected' : ''}>${t}</option>`).join('')}</select>
            ${!VH.portal ? `<select class="rg-in" data-f="src"><option value="">همه‌ی منابع</option>${[['C', 'شرکتی'], ['I', 'وارداتی'], ['P', 'کارکنان'], ['B', 'بردرو']].map(([k, t]) => `<option value="${k}" ${k === f.src ? 'selected' : ''}>${t}</option>`).join('')}</select>` : ''}
            <span class="text-[11px] font-bold text-slate-500" data-n></span>
        </div></div>
        <div class="rg-card"><div class="rg-wrap"><table class="rg-tbl"><thead><tr>
            <th></th><th data-s="plate">پلاک</th><th data-s="car">خودرو</th><th data-s="owner">مالک / بیمه‌گذار</th><th data-s="company">شرکت</th><th>منبع</th>
            <th data-s="policies">بیمه‌نامه</th><th data-s="body_left">بدنه تا</th><th data-s="third_left">ثالث تا</th><th data-s="next_left">نزدیک‌ترین انقضا</th>
        </tr></thead><tbody data-body></tbody></table></div></div>`;
        const R2 = VH.root;
        R2.querySelector('[data-q]').addEventListener('input', debounce(e => { VH.f.q = e.target.value; vTable(); }, 200));
        R2.querySelectorAll('[data-f]').forEach(s => s.onchange = () => { VH.f[s.dataset.f] = s.value; vTable(); });
        R2.querySelectorAll('[data-exp]').forEach(s => s.onclick = () => { VH.f.exp = s.dataset.exp; vRender(); });
        R2.querySelectorAll('[data-s]').forEach(th => th.onclick = () => { const k = th.dataset.s; VH.sort = { k, d: VH.sort.k === k ? -VH.sort.d : 1 }; vTable(); });
        R2.querySelector('[data-xls]').onclick = () => download(VH.api, { action: 'export_xlsx', keys: VH.view.map(r => r.key) }, 'vehicles.xlsx');
        const mb = R2.querySelector('[data-models]'); if (mb) mb.onclick = vModels;
        vTable();
    }
    function vTable() {
        vFilter();
        const tb = VH.root.querySelector('[data-body]');
        VH.root.querySelector('[data-n]').textContent = fa(VH.view.length) + ' خودرو';
        const lim = VH.view.slice(0, 600);
        tb.innerHTML = lim.map(r => `<tr data-k="${esc(r.key)}">
            <td><div class="rg-thumb" data-shape="${r.shape}" data-color="${esc(r.color)}"></div></td>
            <td>${plateHtml(r.plate)}${r.vin ? `<div class="text-[10px] text-slate-400 font-mono mt-1" dir="ltr" style="text-align:right">${esc(r.vin)}</div>` : ''}</td>
            <td><b>${esc(fa(r.car || '—'))}</b><div class="text-[10.5px] text-slate-500">${esc(fa([r.model_year, r.color, r.shape_fa].filter(Boolean).join(' · ')))}</div></td>
            <td>${esc(r.owner || '—')}${r.person ? `<div class="text-[10.5px] text-pink-600">پرسنل: ${esc(r.person)}</div>` : ''}</td>
            <td class="text-[11.5px]">${esc(r.company || '—')}</td>
            <td>${r.sources.map(srcPill).join(' ')}</td>
            <td class="text-center font-black">${fa(r.policies)}${r.open_requests ? `<div class="text-[10px] text-amber-600">${fa(r.open_requests)} باز</div>` : ''}</td>
            <td>${r.body_end ? `<span class="rg-left" style="${leftCls(r.body_left)}">${fa(r.body_end)}</span>` : '<span class="text-slate-300">—</span>'}</td>
            <td>${r.third_end ? `<span class="rg-left" style="${leftCls(r.third_left)}">${fa(r.third_end)}</span>` : '<span class="text-slate-300">—</span>'}</td>
            <td><span class="rg-left" style="${leftCls(r.next_left)}">${leftTxt(r.next_left)}</span>${r.next_type ? `<div class="text-[10px] text-slate-400">${esc(r.next_type)}</div>` : ''}</td>
        </tr>`).join('') || `<tr><td colspan="10">${empty('خودرویی با این جستجو/فیلتر نیست.')}</td></tr>`;
        if (VH.view.length > lim.length) tb.insertAdjacentHTML('beforeend', `<tr><td colspan="10" class="text-center text-[11px] text-slate-400">${fa(VH.view.length - lim.length)} خودروی دیگر؛ جستجو را دقیق‌تر کنید.</td></tr>`);
        tb.querySelectorAll('.rg-thumb').forEach(t => { t.innerHTML = carSvg(t.dataset.shape, t.dataset.color); });
        tb.querySelectorAll('tr[data-k]').forEach(tr => tr.onclick = () => vOpen(tr.dataset.k));
    }
    // تصویرِ کوچکِ فهرست (همان طرحِ سمتِ سرور، ساده‌تر؛ فقط رنگ و نوع)
    const CAR_COLORS = [['سفید', '#f1f5f9'], ['مشکی', '#1f2937'], ['نقره', '#cbd5e1'], ['نوک', '#4b5563'], ['خاکستری', '#6b7280'], ['طوسی', '#9ca3af'], ['سرمه', '#1e3a8a'], ['آبی', '#2563eb'], ['قرمز', '#dc2626'],
        ['زرشکی', '#7f1d1d'], ['آلبالویی', '#9f1239'], ['سبز', '#15803d'], ['زرد', '#facc15'], ['نارنجی', '#f97316'], ['قهوه', '#78350f'], ['بژ', '#d6c7a1'], ['کرم', '#eadfc4'], ['طلایی', '#ca8a04'], ['یشمی', '#0f766e'], ['بنفش', '#6d28d9']];
    // الگوهای SVG از سرور می‌آیند (vh_svg_templates) تا طرحِ صفحه و PDF یکی باشد
    let TPL = {};
    // (در کلیدِ «…_svg» و به صورتِ متنِ JSON، تا فیلترِ XSSِ خروجیِ API نویسه‌های < > را عوض نکند)
    const setTpl = (t) => { try { const o = typeof t === 'string' && t ? JSON.parse(t) : t; if (o && typeof o === 'object' && Object.keys(o).length) TPL = o; } catch (e) {} };
    function carSvg(shape, color, anim) {
        const c = (CAR_COLORS.find(x => String(color || '').replace(/ي/g, 'ی').indexOf(x[0]) >= 0) || [0, '#94a3b8'])[1];
        const st = ['#f1f5f9', '#eadfc4', '#d6c7a1', '#cbd5e1', '#facc15'].indexOf(c) >= 0 ? '#64748b' : '#334155';
        const t = TPL[shape] || TPL.sedan;
        if (!t) return '<i class="fas fa-car text-slate-300 text-2xl"></i>';
        return `<svg class="rg-car ${anim ? 'rg-anim' : 'rg-anim-h'}" viewBox="0 0 320 200">${t.replace(/\{C\}/g, c).replace(/\{S\}/g, st)}</svg>`;
    }
    async function vOpen(key) {
        css();
        const m = modal(spinner);
        const d = await call(VH.api, { action: 'detail', key });
        if (!d.ok) { m.el.innerHTML = `<div class="p-6 text-rose-600 text-sm">${esc(d.error)}</div><div class="p-4"><button class="rg-btn s" data-x2>بستن</button></div>`; m.el.querySelector('[data-x2]').onclick = m.close; return; }
        const s = d.summary;
        setTpl(d.tpl_svg);
        const img = d.model_image ? `<img src="${esc(vUrl('action=model_img&id=' + d.model_image.id))}" alt="">` : carSvg(s.shape, s.color, true);
        const pend = d.records.filter(r => !r.issued && r.src !== 'B');
        const iss = d.records.filter(r => r.issued);
        const tabs = [['pol', 'بیمه‌نامه‌ها', iss.length], ['req', 'درخواست‌های باز', pend.length], ['docs', 'مدارک', d.docs.length], ['inst', 'اقساط', d.installments.length], ['own', 'مالک‌ها', d.owners.length]];
        if (d.endorsements.length) tabs.push(['endo', 'الحاقیه‌ها', d.endorsements.length]);
        if (d.editable) tabs.push(['note', 'یادداشت', d.note && d.note.note ? 1 : 0]);
        let tab = 'pol';
        m.el.innerHTML = `
        <div class="rg-mh" style="--a:#0f172a;--b:#1e3a8a;--c:#0e7490">
            <div class="flex flex-wrap gap-4 items-center">
                <div class="rg-img">${img}</div>
                <div class="flex-1 min-w-[220px]">
                    <div class="flex items-center gap-2 flex-wrap mb-1">${plateHtml(s.plate)}<span class="rg-pill" style="background:rgba(255,255,255,.18);color:#fff">${esc(s.shape_fa)}</span>${s.sources.map(srcPill).join(' ')}</div>
                    <h3 class="text-xl font-black">${esc(fa(s.car || 'خودرو'))}${s.model_year ? ' <small class="text-sm opacity-80">مدل ' + fa(s.model_year) + '</small>' : ''}</h3>
                    <p class="text-[12px] opacity-85 mt-1">${esc(s.owner || '')}${s.company ? ' · ' + esc(s.company) : ''}</p>
                    <div class="flex flex-wrap gap-2 mt-2">
                        ${s.body_end ? `<span class="rg-left" style="${leftCls(s.body_left)}">بدنه تا ${fa(s.body_end)}</span>` : ''}${s.third_end ? `<span class="rg-left" style="${leftCls(s.third_left)}">ثالث تا ${fa(s.third_end)}</span>` : ''}
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <button class="rg-btn s" data-pdf><i class="fas fa-file-pdf text-rose-600"></i> گزارشِ PDF</button>
                    ${s.person_id && window.Personnel && !VH.portal && window.permCan && permCan('personnel') ? `<button class="rg-btn s" data-person="${s.person_id}"><i class="fas fa-id-badge text-pink-600"></i> پرونده‌ی پرسنل</button>` : ''}
                    <button class="rg-btn s" data-x>بستن ✕</button>
                </div>
            </div>
        </div>
        <div class="p-4"><div class="rg-grid">
            ${field('پلاک', s.plate)}${field('شماره شاسی (VIN)', s.vin)}${field('شماره موتور', s.engine)}${field('نامِ خودرو', s.car)}${field('مدل', s.model_year)}${field('رنگ', s.color)}
            ${field('کاربری', s.usage)}${field('مالک / بیمه‌گذار', s.owner)}${field('شرکت', s.company)}${s.person ? field('پرسنل', s.person) : ''}${field('جمعِ حق بیمه‌ها (ریال)', s.premium_total ? money(s.premium_total) : '')}
        </div></div>
        <div class="rg-tabs">${tabs.map(([k, t, n]) => `<button class="rg-tab ${k === tab ? 'on' : ''}" data-t="${k}">${t}${n ? ' (' + fa(n) + ')' : ''}</button>`).join('')}</div>
        <div class="p-4" data-pane></div>`;
        const pane = m.el.querySelector('[data-pane]');
        const draw = () => {
            if (tab === 'pol' || tab === 'req') {
                const list = tab === 'pol' ? iss : pend;
                pane.innerHTML = list.length ? `<div class="rg-wrap" style="max-height:none"><table class="rg-tbl"><thead><tr><th>منبع</th><th>نوع</th><th>${tab === 'pol' ? 'شماره بیمه‌نامه' : 'وضعیت'}</th><th>بیمه‌گذار</th><th>صدور</th><th>شروع</th><th>انقضا</th><th>حق بیمه</th><th>ارزش / تعهد</th><th></th></tr></thead><tbody>
                    ${list.map(r => `<tr style="cursor:default"><td>${srcPill(r.src)}</td><td>${esc(r.type_fa)}</td><td class="font-mono text-[11px]" dir="ltr" style="text-align:right">${esc(fa(tab === 'pol' ? r.policy : r.status_fa))}${r.endorsements && r.endorsements.length ? ' <span class="rg-pill" style="background:#fef3c7;color:#92400e">' + fa(r.endorsements.length) + ' الحاقیه</span>' : ''}</td>
                        <td>${esc(r.insured || '')}${r.company && r.company !== r.insured ? '<div class="text-[10px] text-slate-400">' + esc(r.company) + '</div>' : ''}</td><td>${fa(r.issue_date)}</td><td>${fa(r.start_date)}</td>
                        <td>${r.end_date ? `<span class="rg-left" style="${leftCls(r.days_left)}">${fa(r.end_date)}${r.end_est ? '*' : ''}</span>` : '—'}</td><td>${money(r.premium) || '—'}</td>
                        <td class="text-[10.5px]">${r.car_value ? 'ارزش ' + money(r.car_value) : ''}${r.liability ? '<br>تعهد ' + money(r.liability) : ''}</td>
                        <td>${r.file ? `<a class="rg-btn s" style="padding:4px 8px" target="_blank" href="${fpath(r.file, VH.portal ? '../' : '/')}"><i class="fas fa-file-pdf text-rose-500"></i></a>` : ''}${r.folder ? ` <a class="rg-btn s" style="padding:4px 8px" title="پوشه" target="_blank" href="${VH.portal ? '#' : 'dashboard.php#fm=' + encodeURIComponent(r.folder)}" ${VH.portal ? 'hidden' : ''}><i class="fas fa-folder-open text-amber-500"></i></a>` : ''}</td></tr>`).join('')}
                    </tbody></table></div><p class="text-[10.5px] text-slate-400 mt-2">* انقضای تخمینی (یک سال بعد از شروع/صدور)</p>` : empty(tab === 'pol' ? 'بیمه‌نامه‌ای ثبت نشده.' : 'درخواستِ بازی نیست.');
            } else if (tab === 'docs') {
                pane.innerHTML = d.docs.length ? `<div class="rg-grid">${d.docs.map(x => `<a class="rg-f hover:border-indigo-300" target="_blank" href="${fpath(x.file, VH.portal ? '../' : '/')}"><small>${esc(fa(x.at || ''))} ${srcPill(x.src)}</small><b><i class="fas ${x.policy ? 'fa-file-shield text-indigo-500' : /\.pdf$/i.test(x.file) ? 'fa-file-pdf text-rose-500' : 'fa-image text-emerald-500'} ml-1"></i>${esc(fa(x.label))}</b></a>`).join('')}</div>` : empty('مدرکی بارگذاری نشده.');
            } else if (tab === 'inst') {
                const rem = d.installments.reduce((a, i) => a + Math.max(0, i.amount - i.paid), 0), over = d.installments.filter(i => i.state === 'OVERDUE');
                pane.innerHTML = d.installments.length ? `<div class="flex gap-2 flex-wrap mb-2"><span class="rg-pill" style="background:#f1f5f9;color:#334155">${fa(d.installments.length)} قسط</span>${over.length ? `<span class="rg-pill" style="background:#ffe4e6;color:#be123c">${fa(over.length)} معوق</span>` : ''}<span class="rg-pill" style="background:#eef2ff;color:#4338ca">مانده ${money(rem) || '۰'} ریال</span></div>
                    <div class="rg-wrap" style="max-height:none"><table class="rg-tbl"><thead><tr><th>منبع</th><th>قسط</th><th>سررسید</th><th>مبلغ</th><th>پرداخت‌شده</th><th>وضعیت</th></tr></thead><tbody>
                    ${d.installments.map(i => { const s2 = instState[i.state]; return `<tr style="cursor:default"><td>${srcPill(i.src)}</td><td>${fa(i.n)}</td><td>${fa(i.due)}</td><td>${money(i.amount)}</td><td>${money(i.paid) || '—'}</td><td><span class="rg-pill" style="background:${s2[1]};color:${s2[2]}">${s2[0]}</span>${i.delay ? ' <small class="text-rose-600">' + fa(i.delay) + ' روز</small>' : ''}</td></tr>`; }).join('')}</tbody></table></div>` : empty('قسطی ثبت نشده.');
            } else if (tab === 'own') {
                pane.innerHTML = d.owners.length ? `<div class="rg-tl">${d.owners.map(o => `<div><b class="text-sm">${esc(o.name)}</b> ${o.insured_id ? '<span class="text-[11px] text-slate-500 font-mono">' + fa(o.insured_id) + '</span>' : ''}<div class="text-[11px] text-slate-500">${esc(o.company || '')} · ${fa(o.from)}${o.to && o.to !== o.from ? ' تا ' + fa(o.to) : ''} · ${fa(o.n)} ردیف</div></div>`).join('')}</div>` : empty('مالکی ثبت نشده.');
            } else if (tab === 'endo') {
                pane.innerHTML = `<div class="rg-wrap" style="max-height:none"><table class="rg-tbl"><thead><tr><th>بیمه‌نامه</th><th>شماره</th><th>نوع</th><th>تاریخ</th><th>انقضای تازه</th><th>مبلغ</th></tr></thead><tbody>${d.endorsements.map(e => `<tr style="cursor:default"><td class="font-mono text-[11px]">${esc(fa(e.policy))}</td><td>${fa(e.no)}</td><td>${esc(e.kind)} ${esc(e.reason || '')}</td><td>${fa(e.date)}</td><td>${fa(e.new_end)}</td><td>${money(e.amount)}</td></tr>`).join('')}</tbody></table></div>`;
            } else if (tab === 'note') {
                pane.innerHTML = `<textarea class="rg-in w-full" rows="6" data-note placeholder="یادداشتِ داخلی درباره‌ی این خودرو">${esc(d.note ? d.note.note : '')}</textarea>
                    <div class="flex items-center gap-2 mt-2"><button class="rg-btn p" data-save-note><i class="fas fa-floppy-disk"></i> ذخیره</button>${d.note && d.note.updated_by ? `<small class="text-slate-400">آخرین تغییر: ${esc(d.note.updated_by)}</small>` : ''}</div>`;
                pane.querySelector('[data-save-note]').onclick = async () => { const r = await call(VH.api, { action: 'note', key: s.key, note: pane.querySelector('[data-note]').value }); toast(r.ok ? 'ذخیره شد.' : r.error, r.ok ? 'success' : 'error'); if (r.ok) d.note = { note: pane.querySelector('[data-note]').value }; };
            }
        };
        m.el.querySelectorAll('[data-t]').forEach(b => b.onclick = () => { tab = b.dataset.t; m.el.querySelectorAll('[data-t]').forEach(x => x.classList.toggle('on', x === b)); draw(); });
        m.el.querySelector('[data-pdf]').onclick = () => download(VH.api, { action: 'report_pdf', key: s.key }, 'vehicle.pdf');
        const pb = m.el.querySelector('[data-person]'); if (pb) pb.onclick = () => { m.close(); window.Personnel.open(Number(pb.dataset.person)); };
        draw();
    }
    // عکسِ مدل‌ها: یک بار برای هر مدل (مثلاً «پژو 405») => همه‌ی خودروهای همان مدل
    async function vModels() {
        const m = modal(`<div class="rg-mh"><b class="text-lg"><i class="fas fa-images ml-1"></i>عکسِ رخِ خودرو برای هر مدل</b><p class="text-[11px] opacity-80 mt-1">اگر نامِ خودرو این نام را داشته باشد، به‌جای طرحِ پیش‌فرض همین عکس نشان داده می‌شود (هم در پرونده و هم در PDF).</p></div>
            <div class="p-4 space-y-3"><div class="rg-card"><div class="rg-bar"><input class="rg-in flex-1" data-label placeholder="نامِ مدل (مثلاً پژو 405، پراید، سمند)"><input type="file" accept="image/*" data-file class="text-xs"><button class="rg-btn p" data-up><i class="fas fa-upload"></i> بارگذاری</button></div></div>
            <div data-list>${spinner}</div><div class="text-left"><button class="rg-btn s" data-x>بستن</button></div></div>`, { width: 720 });
        const load = async () => {
            const r = await call(VH.api, { action: 'models' });
            m.el.querySelector('[data-list]').innerHTML = r.ok && r.models.length ? `<div class="rg-grid">${r.models.map(x => `<div class="rg-f"><img src="${esc(vUrl('action=model_img&id=' + x.id))}" style="width:100%;height:110px;object-fit:contain"><b>${esc(fa(x.label))}</b><button class="rg-btn r mt-1" style="padding:4px 8px" data-del="${x.id}"><i class="fas fa-trash"></i></button></div>`).join('')}</div>` : empty('هنوز عکسی بارگذاری نشده.');
            m.el.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { await call(VH.api, { action: 'model_image_delete', id: Number(b.dataset.del) }); load(); });
        };
        m.el.querySelector('[data-up]').onclick = async () => {
            const f = m.el.querySelector('[data-file]').files[0];
            if (!f) return toast('عکس را انتخاب کنید.', 'warning');
            const fd = new FormData(); fd.append('action', 'model_image'); fd.append('label', m.el.querySelector('[data-label]').value); fd.append('file', f);
            const r = await call(VH.api, fd, true);
            toast(r.ok ? 'عکس ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
            if (r.ok) { m.el.querySelector('[data-label]').value = ''; m.el.querySelector('[data-file]').value = ''; load(); }
        };
        load();
    }
    window.Vehicles = { init: vInit, open: (key, opts) => { if (opts && opts.api) VH.api = opts.api; if (opts) VH.portal = !!opts.portal; return vOpen(key); }, plateHtml };

    // =================================================================
    //  پرونده‌ی پرسنل
    // =================================================================
    const PER = { root: null, api: 'api/personnel_actions.php', rows: [], view: [], companies: [], f: { q: '', company: '', flag: '' }, sort: { k: 'name', d: 1 } };
    const avatar = (r, big) => r.photo ? `<img class="rg-av ${big ? 'big' : ''}" src="${PER.api}?action=photo_get&id=${r.id}&t=${Date.now() % 100000}" alt="">` : `<span class="rg-av ${big ? 'big' : ''}">${esc(String(r.name || '؟').trim().charAt(0))}</span>`;
    async function pInit(root) {
        css();
        PER.root = root;
        root.innerHTML = spinner;
        const d = await call(PER.api, { action: 'list' });
        if (!d.ok) { root.innerHTML = `<div class="rg-card text-rose-600 text-sm">${esc(d.error)}</div>`; return; }
        PER.rows = d.rows; PER.companies = d.companies; PER.batches = d.batches;
        pRender();
    }
    function pFilter() {
        const q = norm(PER.f.q), f = PER.f;
        PER.view = PER.rows.filter(r => {
            if (f.company === '0' && r.company_id) return false;
            if (f.company && f.company !== '0' && String(r.company_id) !== f.company) return false;
            if (f.flag === 'overdue' && !r.overdue) return false;
            if (f.flag === 'cars' && !r.cars) return false;
            if (f.flag === 'life' && !r.life) return false;
            if (f.flag === 'health' && !r.health) return false;
            if (f.flag === 'moved' && !r.last_transfer) return false;
            if (f.flag === 'bot' && !r.on_bot) return false;
            if (q && norm([r.name, r.nid, r.pcode, r.mobile, r.company].join(' ')).indexOf(q) < 0) return false;
            return true;
        });
        const k = PER.sort.k, dir = PER.sort.d;
        PER.view.sort((a, b) => { const x = a[k], y = b[k]; return (typeof x === 'number' ? x - y : String(x || '').localeCompare(String(y || ''), 'fa')) * dir; });
    }
    function pRender() {
        const R = PER.rows, f = PER.f;
        const cnt = { all: R.length, overdue: R.filter(r => r.overdue).length, moved: R.filter(r => r.last_transfer).length, cars: R.filter(r => r.cars).length, life: R.filter(r => r.life).length };
        PER.root.innerHTML = `
        <div class="rg-hero mb-4" style="--a:#2e1065;--b:#6d28d9;--c:#be185d">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-[11px] opacity-75">شرکتِ فعلی و سابقه‌ی نقل‌وانتقال، عکس، خانواده، خودروها، بیمه‌های اتومبیل، عمر و درمان، اقساطِ معوق</p><h2 class="text-xl font-black mt-0.5"><i class="fas fa-id-badge ml-1"></i>پرونده‌ی پرسنل</h2></div>
                <div class="flex gap-2 flex-wrap"><button class="rg-btn a" data-roster><i class="fas fa-file-import"></i> ورودِ لیستِ پرسنلی</button><button class="rg-btn s" data-xls><i class="fas fa-file-excel text-emerald-600"></i> اکسل</button></div>
            </div>
            <div class="rg-stats">${[['', 'all', 'همه‌ی پرسنل'], ['overdue', 'overdue', 'قسطِ معوق دارند'], ['moved', 'moved', 'انتقال داشته‌اند'], ['cars', 'cars', 'خودرو دارند'], ['life', 'life', 'بیمه‌ی عمر دارند']]
                .map(([v, k, t]) => `<div class="rg-stat ${f.flag === v ? 'on' : ''}" data-flag="${v}"><b>${fa(cnt[k])}</b><small>${t}</small></div>`).join('')}</div>
        </div>
        <div class="rg-card mb-3"><div class="rg-bar">
            <input class="rg-in flex-1" style="min-width:220px" data-q placeholder="جستجو: نام، کد ملی، کد پرسنلی، موبایل، شرکت…" value="${esc(f.q)}">
            <select class="rg-in" data-f="company"><option value="">همه‌ی شرکت‌ها</option><option value="0" ${f.company === '0' ? 'selected' : ''}>بدونِ شرکت</option>${PER.companies.map(c => `<option value="${c.id}" ${String(c.id) === f.company ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>
            <select class="rg-in" data-f="flag"><option value="">همه</option>${[['overdue', 'قسطِ معوق'], ['cars', 'خودرو دارد'], ['life', 'بیمه‌ی عمر'], ['health', 'درمانِ تکمیلی'], ['moved', 'انتقال داشته'], ['bot', 'در ربات']].map(([k, t]) => `<option value="${k}" ${k === f.flag ? 'selected' : ''}>${t}</option>`).join('')}</select>
            <span class="text-[11px] font-bold text-slate-500" data-n></span>
        </div></div>
        <div class="rg-card"><div class="rg-wrap"><table class="rg-tbl"><thead><tr><th></th><th data-s="name">نام</th><th data-s="nid">کد ملی</th><th data-s="pcode">کد پرسنلی</th><th data-s="company">شرکتِ فعلی</th><th>آخرین انتقال</th>
            <th data-s="cars">خودرو</th><th data-s="auto">اتومبیل</th><th data-s="life">عمر</th><th data-s="health">درمان</th><th data-s="overdue">معوق</th></tr></thead><tbody data-body></tbody></table></div></div>`;
        const R2 = PER.root;
        R2.querySelector('[data-q]').addEventListener('input', debounce(e => { PER.f.q = e.target.value; pTable(); }, 200));
        R2.querySelectorAll('[data-f]').forEach(s => s.onchange = () => { PER.f[s.dataset.f] = s.value; pTable(); });
        R2.querySelectorAll('[data-flag]').forEach(s => s.onclick = () => { PER.f.flag = s.dataset.flag; pRender(); });
        R2.querySelectorAll('[data-s]').forEach(th => th.onclick = () => { const k = th.dataset.s; PER.sort = { k, d: PER.sort.k === k ? -PER.sort.d : 1 }; pTable(); });
        R2.querySelector('[data-xls]').onclick = () => download(PER.api, { action: 'export_xlsx', ids: PER.view.map(r => r.id) }, 'personnel.xlsx');
        R2.querySelector('[data-roster]').onclick = pRoster;
        pTable();
    }
    function pTable() {
        pFilter();
        const tb = PER.root.querySelector('[data-body]');
        PER.root.querySelector('[data-n]').textContent = fa(PER.view.length) + ' نفر';
        const lim = PER.view.slice(0, 600);
        const n = v => v ? `<b>${fa(v)}</b>` : '<span class="text-slate-300">۰</span>';
        tb.innerHTML = lim.map(r => `<tr data-id="${r.id}"><td>${avatar(r)}</td><td><b>${esc(r.name || '—')}</b>${r.mobile ? `<div class="text-[10.5px] text-slate-400" dir="ltr" style="text-align:right">${fa(r.mobile)}</div>` : ''}</td>
            <td class="font-mono">${fa(r.nid)}</td><td>${fa(r.pcode || '')}</td><td>${esc(r.company || '—')}</td>
            <td class="text-[11px]">${r.last_transfer ? fa(r.last_transfer.date) + ' ← ' + esc(r.last_transfer.to || '') : '<span class="text-slate-300">—</span>'}</td>
            <td class="text-center">${n(r.cars)}</td><td class="text-center">${n(r.auto)}</td><td class="text-center">${n(r.life)}</td><td class="text-center">${n(r.health)}</td>
            <td class="text-center">${r.overdue ? `<span class="rg-pill" style="background:#ffe4e6;color:#be123c">${fa(r.overdue)}</span>` : '<span class="text-slate-300">۰</span>'}</td></tr>`).join('') || `<tr><td colspan="11">${empty('کسی با این جستجو/فیلتر نیست.')}</td></tr>`;
        if (PER.view.length > lim.length) tb.insertAdjacentHTML('beforeend', `<tr><td colspan="11" class="text-center text-[11px] text-slate-400">${fa(PER.view.length - lim.length)} نفرِ دیگر؛ جستجو را دقیق‌تر کنید.</td></tr>`);
        tb.querySelectorAll('tr[data-id]').forEach(tr => tr.onclick = () => pOpen(Number(tr.dataset.id)));
    }
    async function pOpen(id) {
        css();
        const m = modal(spinner);
        const load = async () => {
            const d = await call(PER.api, { action: 'detail', id });
            if (!d.ok) { m.el.innerHTML = `<div class="p-6 text-rose-600 text-sm">${esc(d.error)}</div>`; return null; }
            return d;
        };
        let d = await load(); if (!d) return;
        setTpl(d.tpl_svg);
        if (!PER.companies.length) { const l = await call(PER.api, { action: 'list' }); if (l.ok) PER.companies = l.companies; }
        let tab = 'info';
        const render = () => {
            const p = d.person;
            const over = d.installments.filter(i => i.state === 'OVERDUE');
            const tabs = [['info', 'مشخصات'], ['tr', 'نقل‌وانتقال', d.transfers.length], ['fam', 'خانواده', d.family.length], ['car', 'خودروها', d.vehicles.length], ['auto', 'بیمه‌ی اتومبیل', d.auto.length],
                ['life', 'بیمه‌ی عمر', d.life.length], ['health', 'درمانِ تکمیلی', d.health.length], ['inst', 'اقساط', d.installments.length], ['docs', 'مدارک', d.docs.length]];
            m.el.innerHTML = `
            <div class="rg-mh" style="--a:#2e1065;--b:#6d28d9;--c:#be185d"><div class="flex flex-wrap gap-4 items-center">
                <div class="relative">${avatar(p, true)}<label class="absolute -bottom-2 -left-2 rg-btn s" style="padding:5px 8px;cursor:pointer" title="عکس"><i class="fas fa-camera"></i><input type="file" accept="image/*" hidden data-photo></label></div>
                <div class="flex-1 min-w-[220px]"><h3 class="text-xl font-black">${esc(p.name)}</h3>
                    <p class="text-[12px] opacity-90 mt-1">کد ملی ${fa(p.nid)}${p.pcode ? ' · کد پرسنلی ' + fa(p.pcode) : ''}${p.mobile ? ' · ' + fa(p.mobile) : ''}</p>
                    <div class="flex flex-wrap gap-2 mt-2"><span class="rg-pill" style="background:rgba(255,255,255,.2);color:#fff"><i class="fas fa-building"></i>${esc(p.company || 'بدونِ شرکت')}</span>
                        ${over.length ? `<span class="rg-pill" style="background:#ffe4e6;color:#be123c">${fa(over.length)} قسطِ معوق</span>` : ''}${p.on_bot ? '<span class="rg-pill" style="background:#dcfce7;color:#15803d">در ربات</span>' : ''}</div></div>
                <div class="flex flex-col gap-2"><button class="rg-btn s" data-move><i class="fas fa-right-left text-indigo-600"></i> انتقال به شرکتِ دیگر</button><button class="rg-btn s" data-pdf><i class="fas fa-file-pdf text-rose-600"></i> گزارشِ PDF</button><button class="rg-btn s" data-x>بستن ✕</button></div>
            </div></div>
            <div class="rg-tabs">${tabs.map(([k, t, n]) => `<button class="rg-tab ${k === tab ? 'on' : ''}" data-t="${k}">${t}${n ? ' (' + fa(n) + ')' : ''}</button>`).join('')}</div>
            <div class="p-4" data-pane></div>`;
            bindPane();
            m.el.querySelectorAll('[data-t]').forEach(b => b.onclick = () => { tab = b.dataset.t; m.el.querySelectorAll('[data-t]').forEach(x => x.classList.toggle('on', x === b)); bindPane(); });
            m.el.querySelector('[data-x]').onclick = m.close;
            m.el.querySelector('[data-pdf]').onclick = () => download(PER.api, { action: 'report_pdf', id }, 'person.pdf');
            m.el.querySelector('[data-move]').onclick = () => { tab = 'tr'; render(); setTimeout(() => { const s = m.el.querySelector('[data-tc]'); if (s) s.focus(); }, 50); };
            m.el.querySelector('[data-photo]').onchange = async e => {
                const f = e.target.files[0]; if (!f) return;
                const fd = new FormData(); fd.append('action', 'photo'); fd.append('id', id); fd.append('file', f);
                const r = await call(PER.api, fd, true);
                toast(r.ok ? 'عکس ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
                if (r.ok) { d = await load(); render(); const row = PER.rows.find(x => x.id === id); if (row) { row.photo = true; if (PER.root) pTable(); } }
            };
        };
        const reload = async () => { d = await load(); if (d) render(); };
        const bindPane = () => {
            const pane = m.el.querySelector('[data-pane]'), p = d.person;
            if (tab === 'info') {
                const inp = (k, t, v, attrs = '') => `<label class="text-[11px] font-bold text-slate-500">${t}<input class="rg-in w-full mt-1" data-k="${k}" value="${esc(fa(v || ''))}" ${attrs}></label>`;
                pane.innerHTML = `<div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    ${inp('name', 'نام و نام خانوادگی', p.name)}${inp('pcode', 'کد پرسنلی', p.pcode, 'dir="ltr"')}${inp('mobile', 'موبایل', p.mobile, 'dir="ltr"')}
                    ${inp('father', 'نامِ پدر', p.father)}${inp('birth', 'تاریخ تولد', p.birth, 'dir="ltr" placeholder="۱۳۷۰/۰۱/۰۱"')}${inp('gender', 'جنسیت', p.gender)}
                    ${inp('job', 'سمت / شغل', p.job)}${inp('hire', 'تاریخ استخدام', p.hire, 'dir="ltr" placeholder="۱۴۰۰/۰۱/۰۱"')}${inp('phone2', 'تلفنِ دیگر', p.phone2, 'dir="ltr"')}
                    ${inp('postal', 'کد پستی', p.postal, 'dir="ltr"')}<label class="text-[11px] font-bold text-slate-500 md:col-span-2">نشانی<input class="rg-in w-full mt-1" data-k="address" value="${esc(p.address || '')}"></label>
                    <label class="text-[11px] font-bold text-slate-500 md:col-span-3">یادداشت<textarea class="rg-in w-full mt-1" rows="3" data-k="note">${esc(p.note || '')}</textarea></label></div>
                    <div class="flex gap-2 mt-3"><button class="rg-btn p" data-save><i class="fas fa-floppy-disk"></i> ذخیره</button><span class="text-[11px] text-slate-400 self-center">کد ملی: ${fa(p.nid)} (ثابت)</span></div>`;
                pane.querySelector('[data-save]').onclick = async () => {
                    const person = {}; pane.querySelectorAll('[data-k]').forEach(i => person[i.dataset.k] = en(i.value));
                    const r = await call(PER.api, { action: 'save', id, person });
                    toast(r.ok ? 'ذخیره شد.' : r.error, r.ok ? 'success' : 'error');
                    if (r.ok) { reload(); const row = PER.rows.find(x => x.id === id); if (row) { row.name = person.name; row.pcode = person.pcode; row.mobile = person.mobile; if (PER.root) pTable(); } }
                };
            } else if (tab === 'tr') {
                pane.innerHTML = `<div class="rg-card mb-3"><p class="text-xs font-black text-slate-700 mb-2"><i class="fas fa-right-left ml-1 text-indigo-500"></i>انتقال به شرکتِ دیگر</p><div class="rg-bar">
                    <select class="rg-in" data-tc><option value="">— بدونِ شرکت —</option>${PER.companies.map(c => `<option value="${c.id}" ${c.id === p.company_id ? 'disabled' : ''}>${esc(c.name)}${c.id === p.company_id ? ' (فعلی)' : ''}</option>`).join('')}</select>
                    <input class="rg-in" style="width:130px" data-td dir="ltr" placeholder="۱۴۰۵/۰۷/۰۱"><input class="rg-in flex-1" data-tn placeholder="توضیح (اختیاری)"><button class="rg-btn p" data-tgo><i class="fas fa-check"></i> ثبتِ انتقال</button></div></div>
                    ${d.transfers.length ? `<div class="rg-tl">${d.transfers.slice().reverse().map(t => `<div><b class="text-sm">${fa(t.date || '')}</b> <span class="text-sm">${esc(t.from || 'بدونِ شرکت')} <i class="fas fa-arrow-left text-indigo-500 mx-1"></i> ${esc(t.to || 'بدونِ شرکت')}</span>
                        <div class="text-[11px] text-slate-500">${esc(t.source_fa)}${t.note ? ' · ' + esc(t.note) : ''}${t.by ? ' · ' + esc(t.by) : ''} <button class="text-rose-500 mr-2" data-tdel="${t.id}" title="حذفِ این سابقه"><i class="fas fa-trash"></i></button></div></div>`).join('')}</div>` : empty('هنوز انتقالی ثبت نشده.')}`;
                pane.querySelector('[data-tgo]').onclick = async () => {
                    const r = await call(PER.api, { action: 'transfer', id, company_id: Number(pane.querySelector('[data-tc]').value) || 0, date: en(pane.querySelector('[data-td]').value), note: pane.querySelector('[data-tn]').value });
                    toast(r.ok ? 'انتقال ثبت شد.' : r.error, r.ok ? 'success' : 'error');
                    if (r.ok) { reload(); if (PER.root) pInit(PER.root); }
                };
                pane.querySelectorAll('[data-tdel]').forEach(b => b.onclick = async () => { if (!confirm('این سابقه حذف شود؟ (شرکتِ فعلی عوض نمی‌شود)')) return; await call(PER.api, { action: 'transfer_delete', id, transfer_id: Number(b.dataset.tdel) }); reload(); });
            } else if (tab === 'fam') {
                pane.innerHTML = `<div class="rg-card mb-3"><p class="text-xs font-black text-slate-700 mb-2"><i class="fas fa-user-plus ml-1 text-pink-500"></i>افزودنِ عضوِ خانواده</p><div class="rg-bar">
                    <input class="rg-in" data-fn placeholder="نام و نام خانوادگی"><input class="rg-in" style="width:110px" data-fr placeholder="نسبت (همسر، فرزند…)"><input class="rg-in" style="width:120px" data-fi dir="ltr" placeholder="کد ملی">
                    <input class="rg-in" style="width:115px" data-fb dir="ltr" placeholder="۱۳۸۰/۰۱/۰۱"><input class="rg-in" style="width:120px" data-fm dir="ltr" placeholder="موبایل"><button class="rg-btn p" data-fgo><i class="fas fa-plus"></i> افزودن</button></div></div>
                    ${d.family.length ? `<div class="rg-wrap" style="max-height:none"><table class="rg-tbl"><thead><tr><th>نام</th><th>نسبت</th><th>کد ملی</th><th>تولد</th><th>موبایل</th><th>منبع</th><th></th></tr></thead><tbody>
                    ${d.family.map(f => `<tr style="cursor:default"><td><b>${esc(f.name)}</b></td><td>${esc(f.relation || '')}</td><td class="font-mono">${fa(f.nid || '')}</td><td>${fa(f.birth || '')}</td><td>${fa(f.mobile || '')}</td><td><span class="rg-pill" style="background:#f1f5f9;color:#475569">${esc(f.src)}</span></td>
                        <td>${f.id ? `<button class="text-rose-500" data-fdel="${f.id}"><i class="fas fa-trash"></i></button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : empty('عضوی ثبت نشده.')}`;
                pane.querySelector('[data-fgo]').onclick = async () => {
                    const g = k => en(pane.querySelector('[data-' + k + ']').value);
                    const r = await call(PER.api, { action: 'family_save', id, member: { name: pane.querySelector('[data-fn]').value, relation: pane.querySelector('[data-fr]').value, nid: g('fi'), birth: g('fb'), mobile: g('fm') } });
                    toast(r.ok ? 'افزوده شد.' : r.error, r.ok ? 'success' : 'error'); if (r.ok) reload();
                };
                pane.querySelectorAll('[data-fdel]').forEach(b => b.onclick = async () => { if (!confirm('حذف شود؟')) return; await call(PER.api, { action: 'family_delete', id, member_id: Number(b.dataset.fdel) }); reload(); });
            } else if (tab === 'car') {
                pane.innerHTML = d.vehicles.length ? `<div class="rg-grid" style="grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">${d.vehicles.map(v => `<div class="rg-f" style="cursor:pointer" data-vk="${esc(v.key)}"><div class="flex gap-3 items-center"><div class="rg-thumb" style="width:90px;height:56px">${carSvg(v.shape, v.color)}</div>
                    <div><div>${plateHtml(v.plate)}</div><b class="text-[12px]">${esc(fa(v.car || ''))} ${v.model_year ? fa(v.model_year) : ''}</b><div class="text-[10.5px] text-slate-500">${v.body_end ? 'بدنه تا ' + fa(v.body_end) : ''} ${v.third_end ? 'ثالث تا ' + fa(v.third_end) : ''}</div></div></div></div>`).join('')}</div>` : empty('خودرویی ثبت نشده.');
                pane.querySelectorAll('[data-vk]').forEach(c => c.onclick = () => { if (window.permCan && !permCan('vehicles')) return toast('به پرونده‌ی خودروها دسترسی ندارید.', 'warning'); vOpen(c.dataset.vk); });
            } else if (tab === 'auto') {
                pane.innerHTML = d.auto.length ? `<div class="rg-wrap" style="max-height:none"><table class="rg-tbl"><thead><tr><th>منبع</th><th>نوع</th><th>شماره</th><th>پلاک</th><th>خودرو</th><th>صدور</th><th>انقضا</th><th>حق بیمه</th><th></th></tr></thead><tbody>
                    ${d.auto.map(a => `<tr style="cursor:default"><td>${srcPill(a.src)}</td><td>${esc(a.type_fa)}</td><td class="font-mono text-[11px]">${esc(fa(a.policy))}</td><td>${plateHtml(a.plate)}</td><td>${esc(fa(a.car || ''))}</td><td>${fa(a.issue)}</td>
                        <td><span class="rg-left" style="${leftCls(a.days_left)}">${fa(a.end)}${a.end_est ? '*' : ''}</span></td><td>${money(a.premium)}</td><td>${a.file ? `<a class="rg-btn s" style="padding:4px 8px" target="_blank" href="${fpath(a.file)}"><i class="fas fa-file-pdf text-rose-500"></i></a>` : ''}</td></tr>`).join('')}</tbody></table></div>` : empty('بیمه‌ی اتومبیلی ثبت نشده.');
            } else if (tab === 'life') {
                pane.innerHTML = d.life.length ? `<div class="rg-wrap" style="max-height:none"><table class="rg-tbl"><thead><tr><th>شماره</th><th>نقش</th><th>بیمه‌گذار / بیمه‌شده</th><th>طرح</th><th>شروع</th><th>پایان</th><th>وضعیت</th><th>معوق</th></tr></thead><tbody>
                    ${d.life.map(l => `<tr style="cursor:default"><td class="font-mono">${esc(fa(l.policy))}</td><td>${esc(l.role)}</td><td>${esc(l.holder || '')}${l.insured && l.insured !== l.holder ? ' / ' + esc(l.insured) : ''}</td><td>${esc(l.plan || '')}</td><td>${fa(l.start || '')}</td><td>${fa(l.end || '')}</td><td>${esc(l.status || '')}</td>
                        <td>${l.overdue ? `<span class="rg-pill" style="background:#ffe4e6;color:#be123c">${fa(l.overdue)} قسط · ${money(l.overdue_amount)}</span>` : '<span class="text-slate-300">—</span>'}</td></tr>`).join('')}</tbody></table></div>` : empty('بیمه‌ی عمری ثبت نشده.');
            } else if (tab === 'health') {
                pane.innerHTML = d.health.length ? `<div class="rg-wrap" style="max-height:none"><table class="rg-tbl"><thead><tr><th>قرارداد</th><th>بیمه‌نامه</th><th>بیمه‌شده</th><th>نسبت</th><th>طرح</th><th>از</th><th>تا</th><th>حق بیمه</th></tr></thead><tbody>
                    ${d.health.map(h => `<tr style="cursor:default"><td>${esc(h.contract || '')}</td><td class="font-mono">${esc(fa(h.policy || ''))}</td><td>${esc(h.name)}${h.self ? ' <span class="rg-pill" style="background:#ede9fe;color:#6d28d9">خودش</span>' : ''}</td><td>${esc(h.relation || '')}</td><td>${esc(h.plan || '')}</td><td>${fa(h.start || '')}</td><td>${fa(h.end || '')}</td><td>${money(h.premium)}</td></tr>`).join('')}</tbody></table></div>` : empty('درمانِ تکمیلی ثبت نشده.');
            } else if (tab === 'inst') {
                pane.innerHTML = d.installments.length ? `<div class="rg-wrap" style="max-height:none"><table class="rg-tbl"><thead><tr><th>بیمه</th><th>بیمه‌نامه</th><th>قسط</th><th>سررسید</th><th>مبلغ</th><th>پرداخت‌شده</th><th>وضعیت</th></tr></thead><tbody>
                    ${d.installments.map(i => { const s2 = instState[i.state]; return `<tr style="cursor:default"><td>${esc(i.line)}</td><td class="font-mono text-[11px]">${esc(fa(i.policy || ''))} ${i.ref ? '<small class="text-slate-400">' + esc(fa(i.ref)) + '</small>' : ''}</td><td>${fa(i.n)}</td><td>${fa(i.due)}</td><td>${money(i.amount)}</td><td>${money(i.paid) || '—'}</td>
                        <td><span class="rg-pill" style="background:${s2[1]};color:${s2[2]}">${s2[0]}</span>${i.delay ? ' <small class="text-rose-600">' + fa(i.delay) + ' روز</small>' : ''}</td></tr>`; }).join('')}</tbody></table></div>` : empty('قسطی ثبت نشده.');
            } else if (tab === 'docs') {
                pane.innerHTML = d.docs.length ? `<div class="rg-grid">${d.docs.map(x => `<a class="rg-f hover:border-indigo-300" target="_blank" href="${fpath(x.file)}"><b><i class="fas ${/\.pdf$/i.test(x.file) ? 'fa-file-pdf text-rose-500' : 'fa-image text-emerald-500'} ml-1"></i>${esc(fa(x.label))}</b><small>${esc(x.name)}</small></a>`).join('')}</div>` : empty('مدرکی ثبت نشده.');
            }
        };
        render();
    }
    // ورودِ لیستِ پرسنلی: هر کس در کدام شرکت است؛ انتقال‌ها با تاریخ ثبت می‌شوند
    function pRoster() {
        const m = modal(`<div class="rg-mh" style="--a:#78350f;--b:#b45309;--c:#be185d"><b class="text-lg"><i class="fas fa-file-import ml-1"></i>ورودِ لیستِ پرسنلی</b>
            <p class="text-[11px] opacity-85 mt-1">ستون‌ها: کد ملی (لازم)، نام و نام خانوادگی، کد پرسنلی، شرکت، موبایل. اگر ستونِ «شرکت» ندارد، شرکتِ پیش‌فرض را انتخاب کنید. کسی که شرکتش فرق کند «انتقال» با همین تاریخ ثبت می‌شود و نفرِ تازه ساخته می‌شود.</p></div>
            <div class="p-4 space-y-3"><div class="rg-card"><div class="rg-bar">
                <input type="file" accept=".xlsx,.xls,.csv" data-file class="text-xs">
                <select class="rg-in" data-co><option value="">شرکتِ پیش‌فرض (اگر ستونِ شرکت ندارد)</option>${PER.companies.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
                <label class="text-[11px] font-bold text-slate-500">تاریخِ لیست / انتقال <input class="rg-in" style="width:120px" data-date dir="ltr" placeholder="۱۴۰۵/۰۷/۰۱"></label>
                <button class="rg-btn p" data-prev><i class="fas fa-eye"></i> پیش‌نمایش</button></div></div>
                <div data-res></div><div class="text-left"><button class="rg-btn s" data-x>بستن</button></div></div>`, { width: 1000 });
        let token = null;
        m.el.querySelector('[data-prev]').onclick = async () => {
            const f = m.el.querySelector('[data-file]').files[0];
            if (!f) return toast('فایلِ اکسل را انتخاب کنید.', 'warning');
            const fd = new FormData(); fd.append('action', 'roster_preview'); fd.append('file', f); fd.append('company_id', m.el.querySelector('[data-co]').value);
            const box = m.el.querySelector('[data-res]'); box.innerHTML = spinner;
            const r = await call(PER.api, fd, true);
            if (!r.ok) { box.innerHTML = `<div class="rg-card text-rose-600 text-sm">${esc(r.error)}</div>`; return; }
            token = r.token;
            const A = { NEW: ['نفرِ تازه', '#dbeafe', '#1d4ed8'], MOVE: ['انتقال', '#fef3c7', '#92400e'], SAME: ['بدونِ تغییر', '#f1f5f9', '#475569'], ERR: ['خطا', '#ffe4e6', '#be123c'] };
            box.innerHTML = `<div class="flex gap-2 flex-wrap mb-2">${Object.keys(A).map(k => `<span class="rg-pill" style="background:${A[k][1]};color:${A[k][2]}">${A[k][0]}: ${fa(r.counts[k])}</span>`).join('')}</div>
                <div class="rg-wrap" style="max-height:48vh"><table class="rg-tbl"><thead><tr><th>ردیف</th><th>کد ملی</th><th>نام</th><th>از شرکت</th><th>به شرکت</th><th>نتیجه</th></tr></thead><tbody>
                ${r.rows.map(x => `<tr style="cursor:default"><td>${fa(x.line)}</td><td class="font-mono">${fa(x.nid)}</td><td>${esc(x.name)}</td><td>${esc(x.from || '—')}</td><td>${esc(x.to || '—')}</td><td><span class="rg-pill" style="background:${A[x.action][1]};color:${A[x.action][2]}">${A[x.action][0]}</span> <small class="text-rose-600">${esc(x.errors.join(' '))}</small></td></tr>`).join('')}</tbody></table></div>
                <div class="mt-3"><button class="rg-btn g" data-commit ${r.counts.NEW + r.counts.MOVE ? '' : 'disabled'}><i class="fas fa-check"></i> ثبت (${fa(r.counts.NEW)} تازه، ${fa(r.counts.MOVE)} انتقال)</button></div>`;
            box.querySelector('[data-commit]').onclick = async () => {
                const date = en(m.el.querySelector('[data-date]').value);
                if (!date) return toast('تاریخِ لیست / انتقال را وارد کنید.', 'warning');
                const c = await call(PER.api, { action: 'roster_commit', token, date });
                toast(c.ok ? `ثبت شد: ${fa(c.new)} نفرِ تازه، ${fa(c.move)} انتقال.` : c.error, c.ok ? 'success' : 'error');
                if (c.ok) { m.close(); if (PER.root) pInit(PER.root); }
            };
        };
    }
    window.Personnel = { init: pInit, open: id => pOpen(id) };

    // =================================================================
    //  بردروی پاسارگاد
    // =================================================================
    const BR = { root: null, api: 'api/bordereau_actions.php', rows: [], view: [], meta: {}, mode: 'all', sel: new Set(), f: { q: '', group: '', type: '', state: '', exp: '' }, sort: { k: 'days_left', d: 1 } };
    async function bInit(root) {
        css();
        BR.root = root;
        root.innerHTML = spinner;
        const [m, l] = await Promise.all([call(BR.api, { action: 'boot' }), call(BR.api, { action: 'list' })]);
        if (!m.ok || !l.ok) { root.innerHTML = `<div class="rg-card text-rose-600 text-sm">${esc((m.ok ? l : m).error)}</div>`; return; }
        BR.meta = m; BR.rows = l.rows; BR.sel.clear();
        bRender();
    }
    function bFilter() {
        const q = norm(BR.f.q), f = BR.f;
        BR.view = BR.rows.filter(r => {
            if (f.group && r.group !== f.group) return false;
            if (f.type && r.type !== f.type) return false;
            if (f.state === 'active' && r.state === 'cancelled') return false;
            if (f.state && f.state !== 'active' && r.state !== f.state) return false;
            const d = r.days_left;
            if (f.exp === '30' && !(d !== null && d >= 0 && d <= 30 && r.state !== 'cancelled' && r.state !== 'renewed')) return false;
            if (f.exp === '60' && !(d !== null && d >= 0 && d <= 60 && r.state !== 'cancelled' && r.state !== 'renewed')) return false;
            if (f.exp === 'expired' && !(d !== null && d < 0 && r.state !== 'cancelled' && r.state !== 'renewed')) return false;
            if (q && norm([r.policy, r.insured, r.insured_id, r.group, r.contract_party, r.plate, r.vin, r.engine, r.car, r.model_year, r.color, r.usage, r.type_fa, r.issue_date, r.end_date,
                r.phone, r.owner_name, r.state_fa, r.premium, r.central_no, r.prev_policy].join(' ')).indexOf(q) < 0) return false;
            return true;
        });
        const k = BR.sort.k, dir = BR.sort.d;
        BR.view.sort((a, b) => {
            let x = a[k], y = b[k];
            if (x === null || x === undefined || x === '') x = 99999; if (y === null || y === undefined || y === '') y = 99999;
            return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'fa')) * dir;
        });
    }
    const bStatePill = r => { const c = { renewed: ['#dcfce7', '#15803d'], cancelled: ['#e2e8f0', '#475569'], closed: ['#fce7f3', '#be185d'], notified: ['#e0e7ff', '#4338ca'], none: ['#fff7ed', '#c2410c'] }[r.state];
        return `<span class="rg-pill" style="background:${c[0]};color:${c[1]}">${esc(r.state_fa)}</span>`; };
    function bRender() {
        const R = BR.rows, f = BR.f, act = R.filter(r => r.state !== 'cancelled' && r.state !== 'renewed');
        const cnt = { all: R.length, 30: act.filter(r => r.days_left !== null && r.days_left >= 0 && r.days_left <= 30).length, expired: act.filter(r => r.days_left !== null && r.days_left < 0).length,
            renewed: R.filter(r => r.state === 'renewed').length, groups: new Set(R.map(r => r.group)).size };
        const groups = [...new Set(R.map(r => r.group))].sort((a, b) => String(a).localeCompare(String(b), 'fa'));
        BR.root.innerHTML = `
        <div class="rg-hero mb-4" style="--a:#042f2e;--b:#0f766e;--c:#1d4ed8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-[11px] opacity-75">فهرستِ بیمه‌نامه‌های بردروی بیمه‌گر برای اعلامِ تمدید (جدا از مالی و صادره‌ها)؛ به پرونده‌ی خودرو و اشخاص وصل است</p><h2 class="text-xl font-black mt-0.5"><i class="fas fa-table-list ml-1"></i>بردروی پاسارگاد</h2></div>
                <div class="flex gap-2 flex-wrap"><button class="rg-btn a" data-up><i class="fas fa-file-arrow-up"></i> ورودِ اکسلِ بردرو</button></div>
            </div>
            <div class="rg-stats">${[['', 'all', 'همه‌ی ردیف‌ها'], ['30', '30', 'تمدید تا ۳۰ روز'], ['expired', 'expired', 'منقضی (تمدید نشده)'], ['renewed', 'renewed', 'تمدید شده'], ['', 'groups', 'شرکت / گروه']]
                .map(([v, k, t]) => `<div class="rg-stat ${k !== 'all' && k !== 'groups' && (f.exp === v || f.state === v) && v ? 'on' : ''}" data-st="${k}"><b>${fa(cnt[k])}</b><small>${t}</small></div>`).join('')}</div>
        </div>
        <div class="rg-card mb-3"><div class="rg-bar">
            <div class="flex bg-slate-100 rounded-xl p-1 gap-1">${[['all', 'فهرستِ کلی'], ['group', 'بر اساسِ شرکت']].map(([k, t]) => `<button class="rg-tab ${BR.mode === k ? 'on' : ''}" data-mode="${k}">${t}</button>`).join('')}</div>
            <input class="rg-in flex-1" style="min-width:220px" data-q placeholder="جستجو در همه‌ی ستون‌ها…" value="${esc(f.q)}">
            <select class="rg-in" data-f="group"><option value="">همه‌ی شرکت‌ها / گروه‌ها</option>${groups.map(g => `<option ${g === f.group ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select>
            <select class="rg-in" data-f="type"><option value="">بدنه و ثالث</option><option value="BODY" ${f.type === 'BODY' ? 'selected' : ''}>بدنه</option><option value="THIRDPARTY" ${f.type === 'THIRDPARTY' ? 'selected' : ''}>ثالث</option></select>
            <select class="rg-in" data-f="state"><option value="">همه‌ی وضعیت‌ها</option>${[['active', 'غیرِ فسخ‌شده'], ['none', 'اعلام نشده'], ['notified', 'اعلام شد'], ['renewed', 'تمدید شد'], ['closed', 'بسته (تمدید نمی‌کند)'], ['cancelled', 'فسخ‌شده']].map(([k, t]) => `<option value="${k}" ${k === f.state ? 'selected' : ''}>${t}</option>`).join('')}</select>
            <select class="rg-in" data-f="exp"><option value="">همه‌ی تاریخ‌ها</option>${[['30', 'تمدید تا ۳۰ روز'], ['60', 'تمدید تا ۶۰ روز'], ['expired', 'منقضی (تمدید نشده)']].map(([k, t]) => `<option value="${k}" ${k === f.exp ? 'selected' : ''}>${t}</option>`).join('')}</select>
        </div>
        <div class="rg-bar mt-2"><span class="text-[11px] font-bold text-slate-500" data-n></span>
            <button class="rg-btn s" data-exx><i class="fas fa-file-excel text-emerald-600"></i> اکسلِ همین فهرست</button><button class="rg-btn s" data-exp2><i class="fas fa-file-pdf text-rose-600"></i> PDFِ همین فهرست</button>
            <span class="flex-1"></span><span class="text-[11px] text-slate-500" data-seln></span>
            <button class="rg-btn s" data-bnote disabled><i class="fas fa-phone"></i> ثبتِ پیگیری</button><button class="rg-btn r" data-bdel disabled><i class="fas fa-trash"></i> حذف</button></div></div>
        <div data-body></div>`;
        const R2 = BR.root;
        R2.querySelector('[data-q]').addEventListener('input', debounce(e => { BR.f.q = e.target.value; bTable(); }, 220));
        R2.querySelectorAll('[data-f]').forEach(s => s.onchange = () => { BR.f[s.dataset.f] = s.value; bTable(); });
        R2.querySelectorAll('[data-mode]').forEach(b => b.onclick = () => { BR.mode = b.dataset.mode; bRender(); });
        R2.querySelectorAll('[data-st]').forEach(s => s.onclick = () => {
            const k = s.dataset.st;
            if (k === 'groups') { BR.mode = 'group'; } else if (k === 'renewed') { BR.f.state = BR.f.state === 'renewed' ? '' : 'renewed'; BR.f.exp = ''; } else if (k === 'all') { BR.f.exp = ''; BR.f.state = ''; } else { BR.f.exp = BR.f.exp === k ? '' : k; BR.f.state = ''; }
            bRender();
        });
        R2.querySelector('[data-up]').onclick = bUpload;
        R2.querySelector('[data-exx]').onclick = () => download(BR.api, { action: 'export_xlsx', ids: BR.view.map(r => r.id), title: BR.f.group || 'فهرستِ کلی' }, 'bordereau.xlsx');
        R2.querySelector('[data-exp2]').onclick = () => download(BR.api, { action: 'export_pdf', ids: BR.view.map(r => r.id), title: BR.f.group || 'فهرستِ کلی' }, 'bordereau.pdf');
        R2.querySelector('[data-bnote]').onclick = () => bNote([...BR.sel]);
        R2.querySelector('[data-bdel]').onclick = async () => {
            if (!confirm(fa(BR.sel.size) + ' ردیف از بردرو حذف شود؟ (پوشه‌های بایگانی می‌مانند)')) return;
            const r = await call(BR.api, { action: 'delete', ids: [...BR.sel] });
            toast(r.ok ? 'حذف شد.' : r.error, r.ok ? 'success' : 'error'); if (r.ok) bInit(BR.root);
        };
        bTable();
    }
    function bRowsHtml(list, withGroup) {
        return `<div class="rg-wrap"><table class="rg-tbl"><thead><tr><th><input type="checkbox" class="rg-chk" data-all></th>${withGroup ? '<th data-s="group">شرکت / گروه</th>' : ''}<th data-s="insured">بیمه‌گذار</th><th data-s="plate">پلاک</th><th data-s="car">خودرو</th>
            <th data-s="type">نوع</th><th data-s="policy">بیمه‌نامه</th><th data-s="issue_date">صدور</th><th data-s="end_date">انقضا</th><th data-s="days_left">مانده</th><th data-s="premium">حق بیمه</th><th data-s="state">وضعیت</th></tr></thead><tbody>
            ${list.map(r => `<tr data-id="${r.id}" style="${r.state === 'cancelled' ? 'opacity:.55' : ''}"><td><input type="checkbox" class="rg-chk" data-sel="${r.id}" ${BR.sel.has(r.id) ? 'checked' : ''}></td>
                ${withGroup ? `<td class="text-[11.5px]">${esc(r.group)}${r.company_id ? '' : ' <i class="fas fa-link-slash text-slate-300" title="به شرکتی در سامانه وصل نیست"></i>'}</td>` : ''}
                <td>${esc(r.insured)}${r.person_id ? ' <i class="fas fa-id-badge text-pink-500" title="پرسنل"></i>' : ''}</td><td>${plateHtml(r.plate)}${!r.plate && r.vin ? `<div class="text-[10px] font-mono text-slate-400">${esc(r.vin)}</div>` : ''}</td>
                <td class="text-[11.5px]">${esc(fa(r.car || ''))} ${r.model_year ? '<small class="text-slate-400">' + fa(r.model_year) + '</small>' : ''}</td><td>${esc(r.type_fa)}</td>
                <td class="font-mono text-[11px]" dir="ltr" style="text-align:right">${esc(fa(r.policy))}${r.endorsements.length ? ` <span class="rg-pill" style="background:#fef3c7;color:#92400e">${fa(r.endorsements.length)} الحاقیه</span>` : ''}</td>
                <td>${fa(r.issue_date)}</td><td><b>${fa(r.end_date)}</b></td><td><span class="rg-left" style="${leftCls(r.state === 'renewed' || r.state === 'cancelled' ? null : r.days_left)}">${r.state === 'renewed' ? 'تمدید شد' : leftTxt(r.days_left)}</span></td>
                <td>${money(r.premium)}</td><td>${bStatePill(r)}${r.notes.length ? `<div class="text-[10px] text-slate-400">${esc(r.notes[r.notes.length - 1].at)}</div>` : ''}</td></tr>`).join('') || `<tr><td colspan="12">${empty('ردیفی نیست.')}</td></tr>`}
            </tbody></table></div>`;
    }
    function bTable() {
        bFilter();
        const body = BR.root.querySelector('[data-body]');
        BR.root.querySelector('[data-n]').textContent = fa(BR.view.length) + ' ردیف · جمعِ حق بیمه ' + money(BR.view.reduce((a, r) => a + (r.state === 'cancelled' ? 0 : r.premium), 0)) + ' ریال';
        if (BR.mode === 'all') body.innerHTML = `<div class="rg-card">${bRowsHtml(BR.view.slice(0, 1500), true)}</div>`;
        else {
            const by = {};
            BR.view.forEach(r => { (by[r.group] = by[r.group] || []).push(r); });
            body.innerHTML = Object.keys(by).sort((a, b) => a.localeCompare(b, 'fa')).map(g => {
                const L = by[g], soon = L.filter(r => r.days_left !== null && r.days_left >= 0 && r.days_left <= 30 && r.state !== 'renewed' && r.state !== 'cancelled').length;
                const cid = (L.find(r => r.company_id) || {}).company_id || '';
                return `<details class="rg-acc" ${Object.keys(by).length === 1 ? 'open' : ''}><summary><i class="fas fa-building text-teal-600"></i>${esc(g)} <span class="rg-pill" style="background:#f1f5f9;color:#334155">${fa(L.length)} بیمه‌نامه</span>
                    ${soon ? `<span class="rg-pill" style="background:#ffedd5;color:#c2410c">${fa(soon)} تمدید تا ۳۰ روز</span>` : ''}<span class="flex-1"></span>
                    <button class="rg-btn s" style="padding:5px 9px" data-gx="${esc(g)}" data-cid="${cid}"><i class="fas fa-file-excel text-emerald-600"></i> اکسل</button><button class="rg-btn s" style="padding:5px 9px" data-gp="${esc(g)}"><i class="fas fa-file-pdf text-rose-600"></i> PDF</button></summary>
                    <div class="p-2">${bRowsHtml(L, false)}</div></details>`;
            }).join('') || `<div class="rg-card">${empty('ردیفی نیست.')}</div>`;
            body.querySelectorAll('[data-gx]').forEach(b => b.onclick = e => { e.preventDefault(); const g = b.dataset.gx; download(BR.api, { action: 'export_xlsx', ids: by[g].map(r => r.id), title: g }, 'bordereau.xlsx'); });
            body.querySelectorAll('[data-gp]').forEach(b => b.onclick = e => { e.preventDefault(); const g = b.dataset.gp; download(BR.api, { action: 'export_pdf', ids: by[g].map(r => r.id), title: g }, 'bordereau.pdf'); });
        }
        body.querySelectorAll('[data-s]').forEach(th => th.onclick = () => { const k = th.dataset.s; BR.sort = { k, d: BR.sort.k === k ? -BR.sort.d : 1 }; bTable(); });
        const selN = () => { BR.root.querySelector('[data-seln]').textContent = BR.sel.size ? fa(BR.sel.size) + ' انتخاب' : ''; BR.root.querySelector('[data-bnote]').disabled = BR.root.querySelector('[data-bdel]').disabled = !BR.sel.size; };
        body.querySelectorAll('[data-sel]').forEach(c => { c.onclick = e => e.stopPropagation(); c.onchange = () => { const id = Number(c.dataset.sel); c.checked ? BR.sel.add(id) : BR.sel.delete(id); selN(); }; });
        body.querySelectorAll('[data-all]').forEach(c => { c.onclick = e => e.stopPropagation(); c.onchange = () => { c.closest('table').querySelectorAll('[data-sel]').forEach(x => { x.checked = c.checked; const id = Number(x.dataset.sel); c.checked ? BR.sel.add(id) : BR.sel.delete(id); }); selN(); }; });
        body.querySelectorAll('tr[data-id]').forEach(tr => tr.onclick = () => bOpen(Number(tr.dataset.id)));
        selN();
    }
    function bOpen(id) {
        const r = BR.rows.find(x => x.id === id); if (!r) return;
        const kinds = BR.meta.endorse_kinds || {};
        const m = modal(`
            <div class="rg-mh" style="--a:#042f2e;--b:#0f766e;--c:#1d4ed8"><div class="flex flex-wrap gap-3 items-center justify-between">
                <div><div class="flex gap-2 items-center flex-wrap">${plateHtml(r.plate)}<span class="rg-pill" style="background:rgba(255,255,255,.2);color:#fff">${esc(r.type_fa)}</span>${bStatePill(r)}</div>
                    <h3 class="text-lg font-black mt-1">${esc(fa(r.car || 'خودرو'))} · بیمه‌نامه ${esc(fa(r.policy))}</h3><p class="text-[12px] opacity-85">${esc(r.insured)} · ${esc(r.group)}</p></div>
                <div class="flex gap-2 flex-wrap">${window.permCan && permCan('vehicles') ? '<button class="rg-btn s" data-veh><i class="fas fa-car-side"></i> پرونده‌ی خودرو</button>' : ''}${r.person_id && window.permCan && permCan('personnel') ? '<button class="rg-btn s" data-per><i class="fas fa-id-badge"></i> پرونده‌ی شخص</button>' : ''}<button class="rg-btn s" data-x>بستن ✕</button></div>
            </div></div>
            <div class="p-4 space-y-4">
                <div class="rg-grid">${field('بیمه‌گذار', r.insured)}${field('کد / شناسه ملی', r.insured_id)}${field('نوع بیمه‌گذار', r.insured_kind)}${field('شماره بیمه‌نامه', r.policy)}${field('کد یکتا', r.central_no)}
                    ${field('پلاک', r.plate)}${field('شماره شاسی', r.vin)}${field('شماره موتور', r.engine)}${field('خودرو', r.car)}${field('سیستم / تیپ', [r.car_system, r.car_tip].filter(Boolean).join(' '))}${field('مدل', r.model_year)}${field('رنگ', r.color)}${field('کاربری', r.usage)}
                    ${field('صدور', r.issue_date)}${field('شروع', r.start_date)}${field('انقضا', r.end_date + (r.end_orig && r.end_orig !== r.end_date ? ' (اصلی: ' + r.end_orig + ')' : ''))}${field('روزِ مانده', r.days_left === null ? '' : String(r.days_left))}
                    ${field('حق بیمه (ریال)', money(r.premium))}${field('ارزش خودرو (ریال)', money(r.car_value))}${field('تعهد مالی (ریال)', money(r.liability))}${field('تلفن', r.phone)}${field('کد پستی', r.postal_code)}${field('نشانی', r.address)}
                    ${field('مالک', r.owner_name)}${field('طرف قرارداد', r.contract_party)}${field('بیمه‌نامه‌ی قبلی', [r.prev_insurer, r.prev_policy].filter(Boolean).join(' '))}${r.renewed_by ? field('تمدید با', r.renewed_by.policy + ' (' + r.renewed_by.src + ' ' + r.renewed_by.issue + ')') : ''}</div>
                <div class="rg-card"><p class="text-xs font-black text-slate-700 mb-2"><i class="fas fa-building ml-1 text-teal-600"></i>شرکت در سامانه</p><div class="rg-bar">
                    <select class="rg-in" data-co><option value="">— وصل نیست (گروه: ${esc(r.group)}) —</option>${(BR.meta.companies || []).map(c => `<option value="${c.id}" ${c.id === r.company_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>
                    <button class="rg-btn s" data-co-save><i class="fas fa-floppy-disk"></i> ذخیره</button>${r.folder ? `<span class="text-[10.5px] text-slate-400 mr-2"><i class="fas fa-folder text-amber-500"></i> ${esc(r.folder.split('/').slice(-2).join(' / '))}</span>` : ''}</div></div>
                <div class="rg-card"><p class="text-xs font-black text-slate-700 mb-2"><i class="fas fa-file-pen ml-1 text-amber-600"></i>الحاقیه‌ها (فقط روی همین فهرست؛ به مالی نمی‌رود)</p>
                    ${r.endorsements.length ? `<div class="rg-wrap mb-2" style="max-height:none"><table class="rg-tbl"><thead><tr><th>نوع</th><th>شماره</th><th>تاریخ</th><th>مبلغ</th><th>انقضای تازه</th><th>توضیح</th><th></th></tr></thead><tbody>${r.endorsements.map(e => `<tr style="cursor:default"><td>${esc(e.kind_fa)}</td><td>${fa(e.no || '')}</td><td>${fa(e.date || '')}</td><td>${money(e.amount)}</td><td>${fa(e.new_end || '')}</td><td>${esc(e.note || '')}</td>
                        <td>${e.file ? `<a class="rg-btn s" style="padding:3px 7px" target="_blank" href="${fpath(e.file)}"><i class="fas fa-paperclip"></i></a>` : ''} <button class="text-rose-500" data-edel="${e.id}"><i class="fas fa-trash"></i></button></td></tr>`).join('')}</tbody></table></div>` : ''}
                    <div class="rg-bar"><select class="rg-in" data-ek>${Object.entries(kinds).map(([k, t]) => `<option value="${k}">${t}</option>`).join('')}</select><input class="rg-in" style="width:90px" data-eno placeholder="شماره"><input class="rg-in" style="width:115px" data-edt dir="ltr" placeholder="۱۴۰۵/۰۷/۰۱">
                        <input class="rg-in" style="width:140px" data-eam placeholder="مبلغ (ریال)" inputmode="numeric"><input class="rg-in" style="width:125px" data-ene dir="ltr" placeholder="انقضای تازه ۱۴۰۶/۰۱/۰۱"><input class="rg-in flex-1" data-ent placeholder="توضیح">
                        <input type="file" accept=".pdf,image/*" data-ef class="text-xs"><button class="rg-btn p" data-ego><i class="fas fa-plus"></i> ثبتِ الحاقیه</button></div></div>
                <div class="rg-card"><p class="text-xs font-black text-slate-700 mb-2"><i class="fas fa-bell ml-1 text-indigo-500"></i>پیگیریِ تمدید</p>
                    ${r.notes.length ? `<div class="rg-tl mb-2">${r.notes.slice().reverse().map(n => `<div><b class="text-[12px]">${esc(n.channel_fa)}</b> <small class="text-slate-500">${esc(n.at)}${n.by ? ' · ' + esc(n.by) : ''}</small>${n.note ? `<div class="text-[11.5px] text-slate-600">${esc(n.note)}</div>` : ''}</div>`).join('')}</div>` : ''}
                    <div class="rg-bar"><select class="rg-in" data-nc>${Object.entries(BR.meta.channels || {}).map(([k, t]) => `<option value="${k}">${t}</option>`).join('')}</select><input class="rg-in flex-1" data-nt placeholder="توضیح (مثلاً: تماس گرفته شد، هفته‌ی بعد تمدید می‌کند)"><button class="rg-btn p" data-ngo><i class="fas fa-check"></i> ثبت</button></div></div>
            </div>`);
        const refresh = row => { if (!row) return; const i = BR.rows.findIndex(x => x.id === row.id); if (i >= 0) BR.rows[i] = row; m.close(); bTable(); bOpen(row.id); };
        m.el.querySelector('[data-co-save]').onclick = async () => { const x = await call(BR.api, { action: 'set_company', id, company_id: Number(m.el.querySelector('[data-co]').value) || 0 }); toast(x.ok ? 'ذخیره شد.' : x.error, x.ok ? 'success' : 'error'); if (x.ok) refresh(x.row); };
        m.el.querySelector('[data-ego]').onclick = async () => {
            const fd = new FormData(); fd.append('action', 'endorse'); fd.append('id', id);
            [['kind', 'ek'], ['no', 'eno'], ['date', 'edt'], ['amount', 'eam'], ['new_end', 'ene'], ['note', 'ent']].forEach(([k, s]) => fd.append(k, en(m.el.querySelector('[data-' + s + ']').value)));
            const f = m.el.querySelector('[data-ef]').files[0]; if (f) fd.append('file', f);
            const x = await call(BR.api, fd, true); toast(x.ok ? 'الحاقیه ثبت شد.' : x.error, x.ok ? 'success' : 'error'); if (x.ok) refresh(x.row);
        };
        m.el.querySelectorAll('[data-edel]').forEach(b => b.onclick = async () => { if (!confirm('این الحاقیه حذف شود؟')) return; const x = await call(BR.api, { action: 'endorse_delete', endorse_id: Number(b.dataset.edel) }); if (x.ok) refresh(x.row); else toast(x.error, 'error'); });
        m.el.querySelector('[data-ngo]').onclick = async () => { const x = await call(BR.api, { action: 'note', ids: [id], channel: m.el.querySelector('[data-nc]').value, note: m.el.querySelector('[data-nt]').value }); toast(x.ok ? 'ثبت شد.' : x.error, x.ok ? 'success' : 'error'); if (x.ok) refresh(x.rows[0]); };
        const vb = m.el.querySelector('[data-veh]'); if (vb) vb.onclick = () => { m.close(); vOpen(r.vin ? 'v:' + String(r.vin).toUpperCase().replace(/[^A-Z0-9]/g, '') : 'p:' + r.plate_core); };
        const pb = m.el.querySelector('[data-per]'); if (pb) pb.onclick = () => { m.close(); pOpen(r.person_id); };
    }
    function bNote(ids) {
        const m = modal(`<div class="rg-mh" style="--a:#042f2e;--b:#0f766e;--c:#1d4ed8"><b class="text-lg"><i class="fas fa-bell ml-1"></i>ثبتِ پیگیری برای ${fa(ids.length)} ردیف</b></div>
            <div class="p-4 space-y-3"><div class="rg-bar"><select class="rg-in" data-nc>${Object.entries(BR.meta.channels || {}).map(([k, t]) => `<option value="${k}">${t}</option>`).join('')}</select><input class="rg-in flex-1" data-nt placeholder="توضیح"></div>
            <div class="flex gap-2"><button class="rg-btn p" data-go><i class="fas fa-check"></i> ثبت</button><button class="rg-btn s" data-x>انصراف</button></div></div>`, { width: 620 });
        m.el.querySelector('[data-go]').onclick = async () => {
            const x = await call(BR.api, { action: 'note', ids, channel: m.el.querySelector('[data-nc]').value, note: m.el.querySelector('[data-nt]').value });
            toast(x.ok ? 'ثبت شد.' : x.error, x.ok ? 'success' : 'error');
            if (x.ok) { x.rows.forEach(row => { const i = BR.rows.findIndex(y => y.id === row.id); if (i >= 0) BR.rows[i] = row; }); BR.sel.clear(); m.close(); bTable(); }
        };
    }
    function bUpload() {
        const m = modal(`<div class="rg-mh" style="--a:#78350f;--b:#b45309;--c:#0f766e"><b class="text-lg"><i class="fas fa-file-arrow-up ml-1"></i>ورودِ اکسلِ بردروی پاسارگاد</b>
            <p class="text-[11px] opacity-85 mt-1">همان خروجیِ اکسلِ بیمه‌گر (بدنه و ثالث). بیمه‌نامه‌هایی که قبلاً آمده‌اند به‌روز می‌شوند؛ الحاقیه‌ها و پیگیری‌ها می‌مانند. برای هر ردیف پوشه‌ای در «بایگانی بردرو» ساخته می‌شود. به مالی و صادره‌ها اضافه نمی‌شود.</p></div>
            <div class="p-4 space-y-3"><div class="rg-card"><div class="rg-bar"><input type="file" accept=".xlsx,.xls,.csv" data-file class="text-xs flex-1"><button class="rg-btn p" data-prev><i class="fas fa-eye"></i> پیش‌نمایش</button></div></div>
            <div data-res></div><div class="text-left"><button class="rg-btn s" data-x>بستن</button></div></div>`, { width: 1100 });
        m.el.querySelector('[data-prev]').onclick = async () => {
            const f = m.el.querySelector('[data-file]').files[0];
            if (!f) return toast('فایلِ اکسل را انتخاب کنید.', 'warning');
            const fd = new FormData(); fd.append('action', 'preview'); fd.append('file', f);
            const box = m.el.querySelector('[data-res]'); box.innerHTML = spinner;
            const r = await call(BR.api, fd, true);
            if (!r.ok) { box.innerHTML = `<div class="rg-card text-rose-600 text-sm">${esc(r.error)}</div>`; return; }
            box.innerHTML = `<div class="flex gap-2 flex-wrap mb-2"><span class="rg-pill" style="background:#dbeafe;color:#1d4ed8">تازه: ${fa(r.counts.new)}</span><span class="rg-pill" style="background:#fef3c7;color:#92400e">به‌روزرسانی: ${fa(r.counts.update)}</span><span class="rg-pill" style="background:#ffe4e6;color:#be123c">ناقص: ${fa(r.counts.bad)}</span></div>
                <div class="rg-wrap" style="max-height:48vh"><table class="rg-tbl"><thead><tr><th>ردیف</th><th>نوع</th><th>بیمه‌نامه</th><th>بیمه‌گذار</th><th>گروه</th><th>پلاک / شاسی</th><th>خودرو</th><th>صدور</th><th>انقضا</th><th>حق بیمه</th><th>نتیجه</th></tr></thead><tbody>
                ${r.rows.map(x => `<tr style="cursor:default;${x.ok ? '' : 'background:#fff1f2'}"><td>${fa(x.line)}</td><td>${esc(x.type_fa)}</td><td class="font-mono text-[11px]">${esc(fa(x.policy))}</td><td>${esc(x.insured)}</td><td class="text-[11px]">${esc(x.group)}</td><td>${x.plate ? plateHtml(x.plate) : esc(x.vin)}</td><td class="text-[11px]">${esc(fa(x.car))}</td><td>${fa(x.issue)}</td><td>${fa(x.end)}</td><td>${money(x.premium)}</td>
                    <td>${x.ok ? (x.exists ? '<span class="rg-pill" style="background:#fef3c7;color:#92400e">به‌روزرسانی</span>' : '<span class="rg-pill" style="background:#dbeafe;color:#1d4ed8">تازه</span>') : `<small class="text-rose-600">${esc(x.errors.join(' '))}</small>`}${x.warnings.length ? `<div class="text-[10px] text-amber-600">${esc(x.warnings.join(' '))}</div>` : ''}</td></tr>`).join('')}</tbody></table></div>
                <div class="mt-3"><button class="rg-btn g" data-commit ${r.counts.ok ? '' : 'disabled'}><i class="fas fa-check"></i> ثبت (${fa(r.counts.ok)} ردیف)</button></div>`;
            box.querySelector('[data-commit]').onclick = async e => {
                e.target.disabled = true; e.target.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حالِ ثبت و ساختِ پوشه‌ها…';
                const c = await call(BR.api, { action: 'commit', token: r.token });
                toast(c.ok ? `ثبت شد: ${fa(c.new)} تازه، ${fa(c.updated)} به‌روزرسانی.` : c.error, c.ok ? 'success' : 'error');
                if (c.ok) { m.close(); bInit(BR.root); } else e.target.disabled = false;
            };
        };
    }
    window.Bordereau = { init: bInit };
})();
