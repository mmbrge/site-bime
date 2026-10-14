// فایل: home.js
// «میز کار من»: داشبوردِ شخصیِ هر کاربرِ پنل (همه‌ی نقش‌ها به‌جز کاربرانِ شرکت)
//  - خوش‌آمد + وضعیتِ امروز، کارت‌های ماهِ جاری (کارها، ساعتِ حضور، روزهای حضور، روزهای پیاپی، مانده‌ی مرخصی)
//  - نمودارِ فعالیتِ ۳۰ روزِ اخیر (کارها یا ساعتِ حضور) با راهنمای روی هر ستون
//  - کارهای منتظر (فقط بخش‌های مجاز)، دکمه‌های سریع بر اساسِ دسترسی، پرتکرارترین و آخرین کارها
// داده: api/home_actions.php?action=summary
(function () {
    const fa = v => String(v ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = v => String(v ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
    const hm = m => { m = Math.max(0, Math.round(m || 0)); const h = Math.floor(m / 60), r = m % 60; return h ? fa(h) + ' ساعت' + (r ? ' و ' + fa(r) + ' دقیقه' : '') : fa(r) + ' دقیقه'; };
    const hShort = m => fa((Math.round((m || 0) / 6) / 10).toString());
    // مجاز: دسترسیِ نقش/سفارشیِ کاربر (از سرور) + اجازه‌ی صفحه در مرورگر + وجودِ زبانه
    const can = t => (t === 'tools' || !state.data || !Array.isArray(state.data.allowed) || state.data.allowed.includes(t))
        && (typeof window.permCan !== 'function' || window.permCan(t)) && document.getElementById('tab-' + t);
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    // دکمه‌های سریع: [زبانه، آیکن، عنوان، توضیح، رنگ، (اختیاری) کارِ اضافه بعد از باز شدن]
    const ACTIONS = [
        ['records', 'fa-folder-open', 'پرونده‌ها و معرفی‌نامه‌ها', 'جستجو، ثبت و پیگیری', '#2563eb'],
        ['health', 'fa-car-burst', 'بازدید سلامت و مدارک', 'بررسی و تأیید', '#e11d48'],
        ['issue-queue', 'fa-list-check', 'در حالِ صدور', 'ثبتِ صدور و بیمه‌نامه', '#059669'],
        ['issued-list', 'fa-file-circle-check', 'صادره‌ها', 'جستجو، جزئیات، خروجی', '#0d9488'],
        ['companies-requests', 'fa-building', 'درخواست‌های شرکتی', 'ردیف‌ها و مدارک', '#4f46e5'],
        ['companies-inbox', 'fa-inbox', 'صندوقِ مدارکِ شرکت‌ها', 'تخصیصِ مدارک', '#7c3aed'],
        ['issue-group', 'fa-layer-group', 'صدورِ گروهی', 'فایلِ PDFِ بیمه‌گر', '#0891b2'],
        ['endorsements', 'fa-file-pen', 'الحاقیه‌ها', 'اضافی، برگشتی، فسخ', '#9333ea'],
        ['renewals', 'fa-bell', 'اعلامِ تمدید', 'شرکت‌ها و اشخاص', '#d97706'],
        ['fin-dashboard', 'fa-chart-pie', 'داشبوردِ مالی', 'وصول و معوقات', '#16a34a'],
        ['fin-installments', 'fa-calendar-check', 'اقساط', 'سررسید و فیش', '#15803d'],
        ['fin-payments', 'fa-money-bill-transfer', 'دریافت و پرداخت', 'اسنادِ مالی', '#65a30d'],
        ['life-dash', 'fa-heart-pulse', 'بیمه عمر', 'وصول، معوق، همکاران', '#db2777'],
        ['life-policies', 'fa-file-medical', 'بیمه‌نامه‌ها و اقساطِ عمر', 'پرداخت، تماس، رسید', '#be185d'],
        ['vr-build', 'fa-file-circle-plus', 'ساختِ گزارشِ بازدید', 'فرم و عکس‌ها', '#6d28d9'],
        ['vr-list', 'fa-folder-tree', 'گزارش‌های بازدید', 'صادرشده‌ها', '#5b21b6'],
        ['lt-box', 'fa-envelope-open-text', 'کارتابلِ نامه‌ها', 'نوشتن و ارجاع', '#0369a1'],
        ['mk-sales', 'fa-receipt', 'فروشِ بازاریابان', 'ثبت و محاسبه', '#ea580c'],
        ['hl-dash', 'fa-kit-medical', 'درمانِ تکمیلی', 'قراردادها و دریافت‌ها', '#0f766e'],
        ['filemanager', 'fa-box-archive', 'بایگانیِ فایل‌ها', 'پوشه‌ها و دانلود', '#475569'],
        ['tickets', 'fa-comments', 'گفتگوها', 'پیام با همکاران و مشتریان', '#2563eb'],
        ['my-work', 'fa-user-clock', 'کارکردِ من', 'ورود و خروج، مرخصی', '#0284c7'],
        ['staff-work', 'fa-users-gear', 'کارکردِ پرسنل', 'حضور و مرخصیِ همکاران', '#0e7490'],
        ['dashboard', 'fa-gauge-high', 'داشبوردِ سامانه', 'آمارِ کلِ صدور و فروش', '#1e293b'],
        ['tools', 'fa-toolbox', 'ابزارها', 'ماشین‌حساب، یادداشت، موسیقی', '#64748b'],
    ];
    // نامِ صفحه در کارکرد (work_activity.page) → زبانه، برای چیدنِ دکمه‌ها به ترتیبِ استفاده‌ی خودِ کاربر
    const PAGE_TAB = {cases: 'issue-queue', 'issue-queue': 'issue-queue', 'companies-requests': 'companies-requests', 'companies-inbox': 'companies-inbox'};

    let state = {data: null, metric: 'acts', root: null};

    function css() {
        if (document.getElementById('home-css')) return;
        const st = document.createElement('style');
        st.id = 'home-css';
        st.textContent = `
        .hm{--hm-s1:#2a78d6;--hm-s1h:#1d5fb0;--hm-ink:#0f172a;--hm-ink2:#475569;--hm-mute:#94a3b8;--hm-line:#e2e8f0;--hm-card:#fff}
        .hm-hero{position:relative;overflow:hidden;border-radius:24px;padding:22px 22px 18px;color:#fff;background:linear-gradient(125deg,#1e3a8a 0%,#4338ca 45%,#0f766e 100%);box-shadow:0 18px 40px -22px rgba(30,58,138,.7)}
        .hm-hero:before{content:'';position:absolute;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%);top:-110px;left:-60px}
        .hm-hero:after{content:'';position:absolute;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(45,212,191,.35),transparent 70%);bottom:-120px;left:30%}
        .hm-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:5px 11px;font-size:11px;font-weight:800;backdrop-filter:blur(4px)}
        .hm-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
        .hm-kpi{background:var(--hm-card);border:1px solid var(--hm-line);border-radius:18px;padding:14px;display:flex;gap:12px;align-items:center;transition:transform .15s,box-shadow .15s}
        .hm-kpi:hover{transform:translateY(-2px);box-shadow:0 10px 24px -16px rgba(15,23,42,.35)}
        .hm-kpi .ic{width:42px;height:42px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;flex-shrink:0}
        .hm-kpi b{display:block;font-size:20px;font-weight:900;color:var(--hm-ink);line-height:1.2}
        .hm-kpi span{font-size:10.5px;font-weight:800;color:var(--hm-mute)}
        .hm-kpi small{display:block;font-size:10px;color:var(--hm-ink2);font-weight:700}
        .hm-card{background:var(--hm-card);border:1px solid var(--hm-line);border-radius:20px;padding:16px}
        .hm-card h3{font-size:13px;font-weight:900;color:var(--hm-ink);display:flex;align-items:center;gap:7px}
        .hm-seg{display:inline-flex;background:#f1f5f9;border-radius:12px;padding:3px;gap:2px}
        .hm-seg button{font-size:11px;font-weight:800;color:#64748b;padding:5px 12px;border-radius:9px}
        .hm-seg button.on{background:#fff;color:#1e3a8a;box-shadow:0 1px 4px rgba(15,23,42,.12)}
        .hm-chart{position:relative;height:230px;margin-top:10px}
        .hm-chart svg{display:block;width:100%;height:100%;overflow:visible;direction:ltr}
        .hm-chart .bar{fill:var(--hm-s1);transition:fill .12s}
        .hm-chart .hit:hover+.bar,.hm-chart .bar.hov{fill:var(--hm-s1h)}
        .hm-chart .grid{stroke:#eef2f7;stroke-width:1}
        .hm-chart .axis{fill:var(--hm-mute);font-size:10px;font-weight:700}
        .hm-chart .off{fill:#f8fafc}
        .hm-tip{position:absolute;pointer-events:none;background:#0f172a;color:#fff;font-size:11px;font-weight:700;border-radius:10px;padding:6px 10px;white-space:nowrap;transform:translate(50%,-100%);margin-top:-8px;opacity:0;transition:opacity .1s;z-index:5}
        .hm-tip.on{opacity:1}
        .hm-pend{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:14px;border:1px solid var(--hm-line);background:#fff;cursor:pointer;transition:background .12s;width:100%;text-align:right}
        .hm-pend:hover{background:#f8fafc}
        .hm-pend .ic{width:34px;height:34px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .hm-pend .n{margin-right:auto;font-size:15px;font-weight:900;min-width:32px;text-align:center;border-radius:10px;padding:2px 8px}
        .hm-acts{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}
        .hm-act{position:relative;display:flex;gap:10px;align-items:center;padding:12px;border-radius:16px;border:1px solid var(--hm-line);background:#fff;text-align:right;transition:transform .15s,box-shadow .15s,border-color .15s;overflow:hidden}
        .hm-act:hover{transform:translateY(-2px);box-shadow:0 12px 26px -18px rgba(15,23,42,.45);border-color:transparent}
        .hm-act .ic{width:40px;height:40px;border-radius:13px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0}
        .hm-act b{display:block;font-size:12px;font-weight:900;color:var(--hm-ink)}
        .hm-act span{font-size:10px;font-weight:700;color:var(--hm-mute)}
        .hm-act .fav{position:absolute;top:7px;left:8px;font-size:9px;color:#f59e0b}
        .hm-top{display:flex;align-items:center;gap:8px;font-size:11px;margin-bottom:8px}
        .hm-top .lb{flex:0 0 46%;color:var(--hm-ink2);font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .hm-top .tr{flex:1;height:10px;background:#f1f5f9;border-radius:6px;overflow:hidden}
        .hm-top .tr i{display:block;height:100%;background:var(--hm-s1);border-radius:6px}
        .hm-top .v{width:34px;text-align:left;font-weight:900;color:var(--hm-ink)}
        .hm-rec{display:flex;gap:10px;padding:7px 0;border-bottom:1px dashed #eef2f7;font-size:11px}
        .hm-rec:last-child{border-bottom:0}
        .hm-rec .dot{width:9px;height:9px;border-radius:50%;background:var(--hm-s1);margin-top:5px;flex-shrink:0;box-shadow:0 0 0 3px #dbeafe}
        .hm-skel{background:linear-gradient(90deg,#f1f5f9,#e2e8f0,#f1f5f9);background-size:200% 100%;animation:hmsk 1.2s infinite;border-radius:16px}
        @keyframes hmsk{to{background-position:-200% 0}}
        @media (max-width:640px){.hm-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.hm-kpi{padding:10px;gap:8px;flex-direction:column;align-items:flex-start}
            .hm-kpi .ic{width:32px;height:32px;border-radius:11px;font-size:13px}.hm-kpi b{font-size:17px}.hm-hero{padding:18px 16px 14px}.hm-hero h1{font-size:20px}
            .hm-acts{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.hm-act{flex-direction:column;align-items:flex-start;gap:6px;padding:10px}.hm-chart{height:200px}}
        `;
        document.head.appendChild(st);
    }

    function greet(h) { return h < 5 ? 'شب بخیر' : h < 12 ? 'صبح بخیر' : h < 17 ? 'روز بخیر' : h < 20 ? 'عصر بخیر' : 'شب بخیر'; }
    function jlong(j) { const m = String(j || '').match(/(\d{4})\D(\d{1,2})\D(\d{1,2})/); return m ? fa(+m[3]) + ' ' + MONTHS[+m[2] - 1] + ' ' + fa(m[1]) : fa(j); }

    function kpi(icon, grad, label, value, sub) {
        return `<div class="hm-kpi"><div class="ic" style="background:${grad}"><i class="fas ${icon}"></i></div><div class="min-w-0"><span>${label}</span><b>${value}</b>${sub ? `<small>${sub}</small>` : ''}</div></div>`;
    }

    function render() {
        const d = state.data, root = state.root;
        const t = d.today, mo = d.month;
        const tones = {amber: ['#fef3c7', '#b45309'], rose: ['#ffe4e6', '#be123c'], violet: ['#ede9fe', '#6d28d9'], emerald: ['#d1fae5', '#047857'], indigo: ['#e0e7ff', '#4338ca'],
            pink: ['#fce7f3', '#be185d'], sky: ['#e0f2fe', '#0369a1'], slate: ['#f1f5f9', '#334155'], blue: ['#dbeafe', '#1d4ed8']};
        const pend = (d.pending || []).filter(p => can(p.tab));
        // دکمه‌ها: مجازها، با بخش‌هایی که کاربر بیشتر در آن‌ها کار کرده در ابتدا
        const used = {}; (d.pages || []).forEach((p, i) => { const tb = PAGE_TAB[p.page] || p.page; if (used[tb] === undefined) used[tb] = i; });
        const acts = ACTIONS.filter(a => can(a[0]) && a[0] !== 'home')
            .map((a, i) => ({a, i, u: used[a[0]] !== undefined ? used[a[0]] : 999}))
            .sort((x, y) => x.u - y.u || x.i - y.i).map(x => Object.assign(x.a.slice(), {fav: x.u < 4}));
        const maxTop = Math.max(1, ...(d.top || []).map(x => x.n));
        root.innerHTML = `<div class="hm space-y-4">
            <div class="hm-hero">
                <div class="relative z-10 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-[12px] font-bold opacity-80">${greet(t.hour)}، ${jlong(t.j)}</p>
                        <h1 class="text-2xl font-black mt-1">${esc(d.me.name || 'همکار')} <span class="text-lg">👋</span></h1>
                        ${d.me.title ? `<p class="text-[11px] font-bold opacity-75 mt-0.5">${esc(d.me.title)}</p>` : ''}
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="hm-chip"><i class="fas fa-right-to-bracket"></i>${t.in ? 'ورودِ امروز ' + fa(t.in) : 'امروز هنوز ورودی ثبت نشده'}</span>
                        <span class="hm-chip"><i class="fas fa-stopwatch"></i>حضورِ امروز ${hm(t.worked)}</span>
                        <span class="hm-chip"><i class="fas fa-bolt"></i>${fa(t.acts)} کارِ امروز</span>
                    </div>
                </div>
            </div>
            <div class="hm-kpis">
                ${kpi('fa-list-check', 'linear-gradient(135deg,#3b82f6,#1d4ed8)', 'کارهای ' + esc(fa(mo.title)), fa(mo.acts), 'کارهای ثبت‌شده در پنل')}
                ${kpi('fa-clock', 'linear-gradient(135deg,#14b8a6,#0f766e)', 'ساعتِ حضورِ این ماه', hShort(mo.worked), 'ساعت')}
                ${kpi('fa-calendar-day', 'linear-gradient(135deg,#8b5cf6,#6d28d9)', 'روزهای حضور', fa(mo.present) + ' <small class="inline text-[11px]">از ' + fa(mo.work_days) + '</small>', 'روزِ کاریِ این ماه تا امروز')}
                ${kpi('fa-fire', 'linear-gradient(135deg,#f59e0b,#ea580c)', 'روزهای پیاپیِ فعال', fa(d.streak), d.streak >= 5 ? 'عالی، ادامه بده!' : 'روزهای تعطیل حساب نمی‌شوند')}
                ${kpi('fa-umbrella-beach', 'linear-gradient(135deg,#ec4899,#be185d)', 'مانده‌ی مرخصی', esc(d.leave.balance_fa || '-'), '<a href="#" onclick="switchTab(\'my-work\');return false" class="text-blue-600">کارکرد من</a>')}
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="hm-card lg:col-span-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3><i class="fas fa-chart-column text-blue-600"></i><span id="hm-ch-title"></span></h3>
                        <div class="hm-seg" role="tablist"><button data-m="acts">کارها</button><button data-m="worked">ساعتِ حضور</button></div>
                    </div>
                    <p id="hm-ch-sub" class="text-[10.5px] text-slate-400 font-bold mt-1"></p>
                    <div class="hm-chart" id="hm-chart"><div class="hm-tip" id="hm-tip"></div></div>
                </div>
                <div class="hm-card">
                    <h3><i class="fas fa-hourglass-half text-amber-500"></i>کارهای منتظرِ شما</h3>
                    <div class="space-y-2 mt-3">${pend.length ? pend.map(p => { const c = tones[p.tone] || tones.slate; return `<button class="hm-pend" onclick="switchTab('${p.tab}')">
                        <span class="ic" style="background:${c[0]};color:${c[1]}"><i class="fas ${p.icon}"></i></span><span class="text-[11.5px] font-bold text-slate-700">${esc(p.label)}</span>
                        <span class="n" style="background:${c[0]};color:${c[1]}">${fa(p.n)}</span></button>`; }).join('')
                        : `<div class="text-center py-8 text-slate-400"><i class="fas fa-mug-hot text-3xl mb-2 text-emerald-400"></i><p class="text-[12px] font-bold">کارِ منتظری ندارید</p></div>`}</div>
                </div>
            </div>
            <div class="hm-card">
                <h3><i class="fas fa-bolt text-indigo-600"></i>کارهای سریع</h3><p class="text-[10px] font-bold text-slate-400 mt-0.5">بر اساسِ دسترسیِ شما؛ ⭐ بخش‌هایی که بیشتر با آن‌ها کار می‌کنید</p>
                <div class="hm-acts mt-3">${acts.map(a => `<button class="hm-act" onclick="switchTab('${a[0]}')">${a.fav ? '<i class="fas fa-star fav"></i>' : ''}
                    <span class="ic" style="background:linear-gradient(135deg,${a[4]},${a[4]}cc)"><i class="fas ${a[1]}"></i></span><span class="min-w-0"><b>${a[2]}</b><span>${a[3]}</span></span></button>`).join('')}</div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="hm-card">
                    <h3><i class="fas fa-ranking-star text-blue-600"></i>پرتکرارترین کارهای ۳۰ روزِ اخیر</h3>
                    <div class="mt-3">${(d.top || []).length ? d.top.map(x => `<div class="hm-top" title="${esc(x.label)}"><span class="lb">${esc(x.label)}</span><span class="tr"><i style="width:${Math.max(4, x.n / maxTop * 100)}%"></i></span><span class="v">${fa(x.n)}</span></div>`).join('')
                        : '<p class="text-[11px] text-slate-400 text-center py-6">هنوز کاری در این بازه ثبت نشده است.</p>'}</div>
                </div>
                <div class="hm-card">
                    <h3><i class="fas fa-clock-rotate-left text-teal-600"></i>آخرین کارهای شما</h3>
                    <div class="mt-2">${(d.recent || []).length ? d.recent.map(r => `<div class="hm-rec"><span class="dot"></span><div class="min-w-0 flex-1"><p class="font-bold text-slate-700 truncate">${esc(r.label)}${r.qty > 1 ? ` <span class="text-blue-600">×${fa(r.qty)}</span>` : ''}</p>
                        ${r.ref ? `<p class="text-[10px] text-slate-400 truncate">${esc(fa(r.ref))}</p>` : ''}</div><span class="text-[10px] font-bold text-slate-400 whitespace-nowrap">${r.today ? 'امروز' : fa(r.date)} · ${fa(r.time)}</span></div>`).join('')
                        : '<p class="text-[11px] text-slate-400 text-center py-6">هنوز کاری ثبت نشده است.</p>'}</div>
                </div>
            </div>
        </div>`;
        root.querySelectorAll('.hm-seg button').forEach(b => b.onclick = () => { state.metric = b.dataset.m; chart(); });
        chart();
    }

    // نمودارِ ستونی: زمان از راست به چپ (قدیمی‌ترین راست، امروز چپ)؛ یک محور، یک سری
    function chart() {
        const box = document.getElementById('hm-chart'); if (!box) return;
        const tip = document.getElementById('hm-tip');
        const m = state.metric, s = state.data.series || [];
        state.root.querySelectorAll('.hm-seg button').forEach(b => b.classList.toggle('on', b.dataset.m === m));
        const val = r => m === 'acts' ? r.acts : r.worked / 60;
        const vals = s.map(val), total = vals.reduce((a, b) => a + b, 0);
        const activeDays = s.filter(r => val(r) > 0).length;
        document.getElementById('hm-ch-title').textContent = m === 'acts' ? 'کارهای ثبت‌شده در ۳۰ روزِ اخیر' : 'ساعتِ حضور در ۳۰ روزِ اخیر';
        document.getElementById('hm-ch-sub').textContent = m === 'acts'
            ? `جمع ${fa(total)} کار در ${fa(activeDays)} روز${activeDays ? ' · میانگینِ روزهای فعال ' + fa(Math.round(total / activeDays)) : ''}`
            : `جمع ${hm(total * 60)} · میانگینِ روزهای حضور ${activeDays ? hm(total * 60 / activeDays) : '-'}`;
        const W = Math.max(280, box.clientWidth), H = box.clientHeight || 230, padT = 14, padB = 26, padL = 34, padR = 4;
        const max = Math.max(m === 'acts' ? 4 : 2, ...vals);
        const step = niceStep(max / 4), top = Math.ceil(max / step) * step;
        const n = s.length, iw = W - padL - padR, bw = iw / n, barW = Math.max(3, Math.min(22, bw * 0.62));
        const y = v => padT + (H - padT - padB) * (1 - v / top);
        // اندیسِ ۰ (قدیمی‌ترین) سمتِ راست
        const xc = i => padL + iw - (i + 0.5) * bw;
        let g = '';
        s.forEach((r, i) => { if (r.off) g += `<rect class="off" x="${xc(i) - bw / 2}" y="${padT}" width="${bw}" height="${H - padT - padB}"/>`; });
        for (let v = 0; v <= top + 1e-9; v += step) {
            g += `<line class="grid" x1="${padL}" x2="${W - padR}" y1="${y(v)}" y2="${y(v)}"/><text class="axis" x="${padL - 6}" y="${y(v) + 3}" text-anchor="end">${fa(m === 'acts' ? v : Math.round(v * 10) / 10)}</text>`;
        }
        const avg = activeDays ? total / activeDays : 0;
        s.forEach((r, i) => {
            const v = vals[i], x = xc(i), h = Math.max(0, H - padB - y(v));
            if (v > 0) {
                const rr = Math.min(4, barW / 2, h);
                g += `<path class="bar" data-i="${i}" d="M${x - barW / 2},${H - padB} V${H - padB - h + rr} Q${x - barW / 2},${H - padB - h} ${x - barW / 2 + rr},${H - padB - h} H${x + barW / 2 - rr} Q${x + barW / 2},${H - padB - h} ${x + barW / 2},${H - padB - h + rr} V${H - padB} Z"/>`;
            }
            if (i === n - 1) g += `<circle cx="${x}" cy="${H - padB + 5}" r="2.5" fill="#1e3a8a"/>`;
            if ((n - 1 - i) % 5 === 0) { const j = String(r.j).split('/'); g += `<text class="axis" x="${x}" y="${H - 6}" text-anchor="middle">${i === n - 1 ? 'امروز' : fa(+j[2]) + ' ' + MONTHS[+j[1] - 1].slice(0, 3)}</text>`; }
            g += `<rect class="hit" data-i="${i}" x="${x - bw / 2}" y="${padT}" width="${bw}" height="${H - padT - padB + 4}" fill="transparent"/>`;
        });
        if (avg > 0) g += `<line x1="${padL}" x2="${W - padR}" y1="${y(avg)}" y2="${y(avg)}" stroke="#64748b" stroke-width="1" stroke-dasharray="4 4"/><text class="axis" x="${W - padR}" y="${y(avg) - 4}" text-anchor="end">میانگین</text>`;
        box.querySelector('svg') && box.querySelector('svg').remove();
        box.insertAdjacentHTML('afterbegin', `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(document.getElementById('hm-ch-title').textContent)}">${g}</svg>`);
        const svg = box.querySelector('svg');
        svg.querySelectorAll('.hit').forEach(h => {
            const i = +h.dataset.i, r = s[i];
            const on = () => {
                svg.querySelectorAll('.bar.hov').forEach(b => b.classList.remove('hov'));
                const b = svg.querySelector(`.bar[data-i="${i}"]`); if (b) b.classList.add('hov');
                tip.innerHTML = `<div class="opacity-70 text-[10px]">${jlong(r.j)}${r.off ? ' · تعطیل' : ''}</div>${m === 'acts' ? fa(r.acts) + ' کار' : (r.worked ? hm(r.worked) : 'بدونِ حضور')}${r.in && m !== 'acts' ? ' · ورود ' + fa(r.in) : ''}`;
                tip.style.right = (W - xc(i)) + 'px'; tip.style.top = Math.min(y(vals[i]), H - padB - 10) + 'px';
                tip.classList.add('on');
            };
            h.addEventListener('mouseenter', on); h.addEventListener('touchstart', on, {passive: true});
            h.addEventListener('mouseleave', () => { tip.classList.remove('on'); svg.querySelectorAll('.bar.hov').forEach(b => b.classList.remove('hov')); });
        });
    }
    function niceStep(v) { if (v <= 0) return 1; const p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; }

    async function load(root) {
        css();
        state.root = root;
        if (!state.data) root.innerHTML = `<div class="space-y-4"><div class="hm-skel" style="height:120px"></div><div class="grid grid-cols-2 md:grid-cols-5 gap-3">${'<div class="hm-skel" style="height:74px"></div>'.repeat(5)}</div><div class="hm-skel" style="height:280px"></div></div>`;
        try {
            const r = await (await fetch('api/home_actions.php?action=summary', {cache: 'no-store'})).json();
            if (!r.ok) { root.innerHTML = `<p class="text-center text-rose-500 text-sm py-10">${esc(r.error || 'خطا در دریافتِ اطلاعات.')}</p>`; return; }
            state.data = r;
            render();
        } catch (e) { if (!state.data) root.innerHTML = '<p class="text-center text-rose-500 text-sm py-10">خطا در اتصال به سرور.</p>'; }
    }
    let rt;
    window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => { if (state.data && document.getElementById('hm-chart') && document.getElementById('hm-chart').offsetParent) chart(); }, 150); });
    window.Home = {load};
})();
