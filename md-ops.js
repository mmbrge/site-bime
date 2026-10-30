// فایل: md-ops.js
// «درمان و عمرِ گروهی» — صفحه‌های عملیاتی (روی ابزارهای md.js): داشبورد با نمودار و هشدار، بیمه‌شدگان و لیست‌ها و «ورودی به پاسارگاد»،
// مغایرت‌گیری با سند حقوق، صورت‌حساب‌ها (هر شرکت و تجمیعی)، دریافت‌ها و صندوقِ رسیدها و واریز به بیمه‌گر، بایگانی و خروجی، و تنظیماتِ تکمیلی.
(function () {
    'use strict';
    if (!window.Treat || !Treat._ || Treat.pages['md-members']) return;
    const {api, modal, esc, fa, en, num, money, toast, ask, busy, can, shell, help, opts, M, loadCompanies, loadContracts, API} = Treat._;
    const X = {cfg: null, period: null};
    const PAS = {NONE: ['r', 'واردِ پاسارگاد نشده'], EXPORTED: ['g', 'ورود شده به پاسارگاد'], DONE: ['c', 'تأییدِ بیمه‌گر']};
    const RECON = {MISSING: ['r', 'در سند حقوق نبود'], OPEN: ['a', 'در حالِ بررسی'], CONFIRMED: ['g', 'تأییدشده'], VOID: ['', 'ابطال‌شده']};
    const tag = (c, t, ic) => `<span class="md-tag ${c}">${ic ? `<i class="fas ${ic}"></i>` : ''}${esc(t)}</span>`;
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const pfa = p => { if (!/^\d{4}\/\d{2}$/.test(p || '')) return p || ''; return MONTHS[+p.slice(5) - 1] + ' ' + fa(p.slice(0, 4)); };
    async function cfg() { if (!X.cfg) X.cfg = await api('settings'); if (!X.period) X.period = X.cfg.period; return X.cfg; }
    // انتخابِ ماه: سال + ماه => YYYY/MM
    const periodSel = (key, val) => {
        const y0 = +(val || X.period || '1405/01').slice(0, 4), cy = +((X.cfg && X.cfg.period) || val || '1405/01').slice(0, 4);
        const ys = []; for (let y = Math.min(y0, cy) - 3; y <= Math.max(y0, cy) + 1; y++) ys.push(y);
        return `<span class="inline-flex gap-1" data-per="${key}"><select class="md-sel" style="width:auto" data-pm>${MONTHS.map((m, i) => `<option value="${String(i + 1).padStart(2, '0')}" ${+String(val).slice(5) === i + 1 ? 'selected' : ''}>${m}</option>`).join('')}</select>
            <select class="md-sel" style="width:auto" data-py>${ys.map(y => `<option value="${y}" ${y === y0 ? 'selected' : ''}>${fa(y)}</option>`).join('')}</select></span>`;
    };
    const perVal = (root, key) => { const w = root.querySelector(`[data-per="${key}"]`); return w ? w.querySelector('[data-py]').value + '/' + w.querySelector('[data-pm]').value : ''; };
    const onPer = (root, key, fn) => { const w = root.querySelector(`[data-per="${key}"]`); if (w) w.querySelectorAll('select').forEach(s => s.onchange = () => fn(perVal(root, key))); };
    const coOpts = (sel, all = 'همه‌ی شرکت‌ها') => `<option value="">${all}</option>${(M.companies || []).map(c => `<option value="${c.id}" ${String(c.id) === String(sel || '') ? 'selected' : ''}>${esc(c.parent_name ? c.name + ' ← ' + c.parent_name : c.name)}</option>`).join('')}`;
    const kindOpts = (sel, all = 'درمان و عمر') => `<option value="">${all}</option><option value="HEALTH" ${sel === 'HEALTH' ? 'selected' : ''}>درمانِ تکمیلی</option><option value="LIFE" ${sel === 'LIFE' ? 'selected' : ''}>عمر و حوادث</option>`;
    const subNav = (items, cur) => `<div class="md-seg mb-3" data-sub>${items.map(([k, t, ic]) => `<button type="button" data-k="${k}" class="${k === cur ? 'on' : ''}"><i class="fas ${ic} ml-1"></i>${t}</button>`).join('')}</div>`;
    const dl = (action, params) => { const q = new URLSearchParams(Object.assign({action}, params)); window.open(`${API}?${q}`, '_blank'); };
    const empty = (t, ic = 'fa-inbox') => `<p class="md-empty"><i class="fas ${ic}"></i>${t}</p>`;
    const spin = () => empty('در حالِ بارگذاری…', 'fa-spinner fa-spin');
    async function upload(action, file, extra = {}) { const fd = new FormData(); fd.append('file', file); Object.entries(extra).forEach(([k, v]) => fd.append(k, v)); return api(action, {}, fd); }
    const askText = (title, label, okText, opt = {}) => new Promise(res => {
        const m = modal(title, `<label class="block"><span class="md-lbl">${label}</span><textarea class="md-ta" data-t rows="3">${esc(opt.value || '')}</textarea></label>`,
            `<button class="md-btn s" data-x>انصراف</button><button class="md-btn ${opt.danger ? 'r' : 'p'}" data-ok>${okText}</button>`);
        m.$('[data-ok]').onclick = () => { const v = m.$('[data-t]').value.trim(); if (opt.required !== false && !v) { toast('این خانه را پر کنید.', 'error'); return; } m.close(); res(v); };
        m.el.addEventListener('click', e => { if (e.target.closest('[data-x]')) res(null); });
    });

    // ---------------- پرونده‌ی شخص ----------------
    async function openPerson(nid) {
        let d; try { d = await api('person_history', {nid}); } catch (e) { toast(e.message, 'error'); return; }
        const cur = d.members.find(m => !m.end_p) || d.members[0] || {};
        const m = modal(`پرونده‌ی درمان و عمر — ${esc(cur.name || nid)}`, `
            <div class="flex flex-wrap gap-2 items-center mb-3"><span class="md-tag b">کدِ ملی ${fa(nid)}</span>${cur.company ? tag('c', 'شرکتِ فعلی: ' + cur.company) : ''}<span class="flex-1"></span>
                <button class="md-btn o sm" data-pf><i class="fas fa-id-card"></i>پرونده‌ی پرسنل (نقل‌وانتقال، خودرو، مدارک)</button></div>
            <div class="md-card"><h3><i class="fas fa-heart-pulse text-indigo-600"></i>پوشش‌های درمان و عمر</h3><div class="md-scroll" style="max-height:260px"><table class="md-tbl no-page"><thead><tr><th>نام</th><th>نسبت</th><th>شرکت</th><th>نوع</th><th>طرح</th><th>از</th><th>تا</th></tr></thead>
                <tbody>${d.members.map(x => `<tr><td>${esc(x.name)}</td><td>${esc(x.rel_fa)}</td><td>${esc(x.company)}</td><td>${x.kind === 'LIFE' ? 'عمر' : 'درمان'}</td><td>${esc(x.plan || '—')}</td><td>${pfa(x.start_p)}</td><td>${x.end_p ? pfa(x.end_p) : tag('g', 'فعال')}</td></tr>`).join('') || '<tr><td colspan="7">' + empty('سابقه‌ای نیست') + '</td></tr>'}</tbody></table></div></div>
            <div class="md-grid md-g2 mt-3"><div class="md-card"><h3><i class="fas fa-people-roof text-indigo-600"></i>خانواده</h3>${d.family.length ? `<table class="md-tbl no-page"><tbody>${d.family.map(f => `<tr><td>${esc(f.name)}</td><td>${esc(f.rel)}</td><td>${fa(f.nid)}</td><td>${f.active ? tag('g', 'فعال') : tag('', 'پایان')}</td></tr>`).join('')}</tbody></table>` : empty('ثبت نشده')}</div>
                <div class="md-card"><h3><i class="fas fa-right-left text-indigo-600"></i>انتقال‌ها</h3>${d.transfers.length ? `<table class="md-tbl no-page"><tbody>${d.transfers.map(t => `<tr><td>${esc(t.period_fa)}</td><td>${esc(t.from)} ← ${esc(t.to)}</td><td>${t.kind === 'LIFE' ? 'عمر' : 'درمان'}</td></tr>`).join('')}</tbody></table>` : empty('انتقالی ثبت نشده')}</div></div>`,
            '<button class="md-btn s" data-x>بستن</button>', {wide: true});
        m.$('[data-pf]').onclick = async () => {
            try { const r = await api('person_open', {nid}); if (window.Personnel && Personnel.open) { if (r.created) toast('برای این شخص پرونده‌ی پرسنل ساخته شد.', 'success'); Personnel.open(r.person_id); } else toast('بخشِ پرونده‌ی پرسنل در دسترس نیست.', 'error'); }
            catch (e) { toast(e.message, 'error'); }
        };
    }
    Treat.openPerson = openPerson;
    const nameLink = (name, nid) => `<a href="#" class="text-indigo-700 font-bold hover:underline" data-person="${esc(nid)}">${esc(name || fa(nid))}</a>`;
    const bindPersons = el => el.querySelectorAll('[data-person]').forEach(a => a.onclick = e => { e.preventDefault(); openPerson(a.dataset.person); });

    // ---------------- نمودار (SVG ساده: ستونِ گروهی و میله‌ی افقی) ----------------
    const C1 = '#4f46e5', C2 = '#0d9488';
    function bars(series, keys, colors, labels, fmt = v => fa(v)) {
        const W = 640, H = 220, pl = 8, pb = 36, pt = 14, n = series.length || 1;
        const max = Math.max(1, ...series.flatMap(s => keys.map(k => s[k] || 0)));
        const gw = (W - pl * 2) / n, bw = Math.max(4, Math.min(22, (gw - 8) / keys.length - 2));
        let g = '';
        series.forEach((s, i) => {
            const x0 = pl + i * gw + (gw - keys.length * (bw + 2)) / 2;
            keys.forEach((k, j) => {
                const v = s[k] || 0, h = (H - pb - pt) * v / max, x = x0 + j * (bw + 2), y = H - pb - h;
                g += `<rect x="${x}" y="${y}" width="${bw}" height="${Math.max(h, v ? 1.5 : 0)}" rx="3" fill="${colors[j]}"><title>${esc(s.label)} — ${esc(labels[j])}: ${fmt(v)}</title></rect>`;
            });
            g += `<text x="${pl + i * gw + gw / 2}" y="${H - pb + 14}" font-size="9.5" fill="#64748b" text-anchor="middle">${esc(String(s.label).split(' ')[0])}</text>`;
        });
        const legend = labels.map((l, j) => `<span class="inline-flex items-center gap-1 ml-3"><i style="display:inline-block;width:10px;height:10px;border-radius:3px;background:${colors[j]}"></i>${esc(l)}</span>`).join('');
        return `<div class="text-[11px] text-slate-600 mb-1">${legend}</div><svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;direction:ltr" role="img"><line x1="${pl}" x2="${W - pl}" y1="${H - pb}" y2="${H - pb}" stroke="#e2e8f0"/>${g}</svg>`;
    }
    function hbars(rows, key, label, color, fmt = v => money(v)) {
        if (!rows.length) return empty('داده‌ای نیست');
        const max = Math.max(1, ...rows.map(r => r[key]));
        return `<div class="space-y-1.5">${rows.map(r => `<div title="${esc(r[label])}: ${fmt(r[key])}"><div class="flex justify-between text-[11px]"><span class="truncate">${esc(r[label])}</span><b>${fmt(r[key])}</b></div>
            <div style="height:8px;background:#f1f5f9;border-radius:6px"><div style="height:8px;width:${Math.max(2, 100 * r[key] / max)}%;background:${color};border-radius:6px"></div></div></div>`).join('')}</div>`;
    }

    // ============================ داشبورد ============================
    Treat.pages['md-dash'] = {
        async render(root) {
            await cfg();
            root.innerHTML = shell('md-dash', spin());
            let d; try { d = await api('dashboard', {period: X.period}); } catch (e) { root.innerHTML = shell('md-dash', `<div class="md-card md-empty">${esc(e.message)}</div>`); return; }
            const k = d.kpi, s = M.boot.stats;
            const kp = (ic, t, v, sub, cls) => `<div class="md-kpi"><div class="k"><i class="fas ${ic}"></i>${t}</div><div class="v" style="${cls || ''}">${v}</div>${sub ? `<div class="s">${sub}</div>` : ''}</div>`;
            const lv = {r: ['#be123c', 'fa-circle-exclamation'], a: ['#b45309', 'fa-triangle-exclamation']};
            root.innerHTML = shell('md-dash', `
                ${help('راهنمای بخشِ درمان و عمرِ گروهی', [
                    '<b>ترتیبِ کار هر ماه:</b> ۱) لیست‌های شرکت‌ها (شروع، ماهانه، افزایشی، کاهشی) را در «بیمه‌شدگان و لیست‌ها» بارگذاری و اعمال کنید و «ورودی به پاسارگاد» بگیرید. ۲) سند حقوق را در «مغایرت با سند حقوق» بارگذاری، مغایرت‌ها را بررسی و تأیید کنید. ۳) در «صورت‌حساب‌ها» صادر کنید. ۴) رسیدها را در «دریافت‌ها و رسیدها» ثبت کنید.',
                    '<b>قراردادها و شرکت‌ها:</b> هر شرکت قرارداد، طرحِ پیش‌فرض و سهمِ شرکت (درصد یا مبلغِ ثابت) دارد؛ زیرمجموعه‌ها از قانونِ شرکتِ مادر پیروی می‌کنند مگر قانونِ مستقل بگیرند.',
                    'ورود و خروج از ابتدای ماه حساب می‌شود؛ کسی که در یک ماه در دو شرکت است فقط در شرکتِ دوم حساب می‌شود.',
                ])}
                <div class="md-card flex flex-wrap items-center gap-2"><b class="text-[12.5px]"><i class="fas fa-calendar ml-1 text-indigo-600"></i>ماه:</b>${periodSel('p', X.period)}<span class="flex-1"></span>
                    ${s.contracts ? '' : tag('a', 'هنوز قراردادی ثبت نشده', 'fa-triangle-exclamation')}</div>
                <div class="md-kpis">${kp('fa-heart-pulse', 'بیمه‌شدگانِ درمان', fa(k.health), d.period_fa)}${kp('fa-shield-heart', 'بیمه‌شدگانِ عمر', fa(k.life))}${kp('fa-building', 'شرکت‌های فعال', fa(k.companies))}
                    ${kp('fa-file-invoice-dollar', 'صورت‌حسابِ این ماه', money(k.billed), 'دریافت‌شده ' + money(k.paid))}${kp('fa-hourglass-half', 'مانده‌ی کلِ وصول‌نشده', money(k.outstanding), k.overdue ? fa(k.overdue) + ' صورت‌حسابِ سررسیدگذشته' : '', k.overdue ? 'color:#be123c' : '')}
                    ${kp('fa-inbox', 'رسیدهای ثبت‌نشده', fa(k.inbox), 'در صندوقِ رسیدها')}</div>
                <div class="md-card"><h3><i class="fas fa-scale-unbalanced text-indigo-600"></i>مغایرت‌گیریِ ${esc(d.period_fa)}</h3><div class="flex flex-wrap gap-2">
                    ${tag('g', 'تأییدشده: ' + fa(d.recon.CONFIRMED))}${tag('a', 'در حالِ بررسی: ' + fa(d.recon.OPEN))}${tag('r', 'در سند حقوق نبود: ' + fa(d.recon.MISSING))}${tag('', 'انجام‌نشده: ' + fa(d.recon.NONE))}</div></div>
                <div class="md-grid md-g2">
                    <div class="md-card"><h3><i class="fas fa-users text-indigo-600"></i>تعدادِ بیمه‌شدگان <small>۱۲ ماهِ اخیر</small></h3>${bars(d.series, ['health', 'life'], [C1, C2], ['درمان', 'عمر'])}</div>
                    <div class="md-card"><h3><i class="fas fa-coins text-indigo-600"></i>صورت‌حساب و وصول <small>ریال</small></h3>${bars(d.series, ['billed', 'paid'], [C1, C2], ['صورت‌حساب', 'دریافتی'], v => money(v))}</div>
                    <div class="md-card"><h3><i class="fas fa-ranking-star text-indigo-600"></i>بیشترین مانده‌ی وصول‌نشده</h3>${hbars(d.outstanding_by_company, 'remain', 'company', '#e11d48')}</div>
                    <div class="md-card"><h3><i class="fas fa-layer-group text-indigo-600"></i>بیمه‌شدگانِ درمان به تفکیکِ طرح</h3>${hbars(d.plans, 'n', 'name', C1, v => fa(v))}</div>
                </div>
                <div class="md-grid md-g2">
                    <div class="md-card"><h3><i class="fas fa-bell text-rose-600"></i>هشدارها <small>${fa(d.warnings.length)} مورد</small></h3><div class="md-scroll" style="max-height:340px">${d.warnings.length ? `<ul class="text-[12px] divide-y">${d.warnings.map(w => `<li class="py-2 flex gap-2"><i class="fas ${lv[w.level][1]} mt-1" style="color:${lv[w.level][0]}"></i><span>${w.nid ? nameLink(w.name, w.nid) : `<b>${esc(w.name || '')}</b>`} ${esc(w.msg)}</span></li>`).join('')}</ul>` : empty('هشداری نیست', 'fa-circle-check')}</div></div>
                    <div class="md-card"><h3><i class="fas fa-calendar-check text-indigo-600"></i>یادآورِ سررسیدها</h3><div class="md-scroll" style="max-height:340px">${d.reminders.length ? `<table class="md-tbl no-page"><tbody>${d.reminders.map(b => `<tr><td>${esc(b.company)}</td><td>${esc(b.kind_fa)} ${esc(b.period_fa)}</td><td>${money(b.remain)}</td><td>${b.when === 'overdue' ? tag('r', 'سررسید ' + fa(b.due_j) + ' گذشته') : tag('a', 'سررسید ' + fa(b.due_j))}</td></tr>`).join('')}</tbody></table>` : empty('سررسیدِ نزدیکی نیست', 'fa-circle-check')}</div></div>
                </div>`);
            onPer(root, 'p', p => { X.period = p; Treat.pages['md-dash'].render(root); });
            bindPersons(root);
        }
    };

    // ============================ بیمه‌شدگان و لیست‌ها ============================
    const MEM = {sub: 'members', f: {company_id: '', kind: '', state: 'active', q: '', rel: ''}, lf: {company_id: '', type: '', status: ''}};
    Treat.pages['md-members'] = {
        async render(root) {
            await cfg(); await loadCompanies();
            root.innerHTML = shell('md-members', `
                ${help('راهنمای بیمه‌شدگان و لیست‌ها', [
                    '<b>بارگذاریِ لیست:</b> شرکت، نوعِ بیمه، نوعِ لیست و ماه را انتخاب کنید. ستون‌ها از روی عنوان خوانده می‌شوند (کد ملی، نام، نسبت، طرح/گروه، تاریخِ شروع/پایان/اثر، شبا، موبایل و…) — هر ستونی که هست استفاده می‌شود.',
                    '<b>لیستِ شروعِ قرارداد / ماهانه:</b> فهرستِ کامل؛ کسانی که نیستند پایان می‌خورند، تازه‌ها اضافه و تغییرِ طرح از همان ماه اعمال می‌شود. <b>افزایشی</b> فقط اضافه و <b>کاهشی</b> فقط خروج (با «تاریخ اثر»).',
                    'کسی که در شرکتِ دیگری فعال است «انتقال» حساب می‌شود: در شرکتِ قبلی تمام و در این شرکت شروع می‌شود و در پرونده‌ی شخص ثبت می‌شود.',
                    'پیش از اعمال، تفاوت‌ها را می‌بینید؛ بعد از اعمال هم «ابطال» همه‌چیز را برمی‌گرداند. «ورودی به پاسارگاد» برای افزایشی و کاهشی جدا (xlsx یا xls) گرفته می‌شود و وضعیتش ثبت می‌شود.',
                ])}
                ${subNav([['members', 'بیمه‌شدگان', 'fa-users'], ['lists', 'لیست‌ها', 'fa-file-excel'], ['pas', 'وضعیتِ ورود به پاسارگاد', 'fa-arrow-right-to-bracket']], MEM.sub)}
                <div data-body></div>`);
            root.querySelectorAll('[data-sub] [data-k]').forEach(b => b.onclick = () => { MEM.sub = b.dataset.k; Treat.pages['md-members'].render(root); });
            const body = root.querySelector('[data-body]');
            if (MEM.sub === 'members') return this.members(body);
            if (MEM.sub === 'lists') return this.lists(body);
            return this.pas(body);
        },
        async members(body) {
            const f = MEM.f;
            body.innerHTML = `<div class="md-card"><div class="flex flex-wrap gap-2 items-end">
                <label><span class="md-lbl">شرکت</span><select class="md-sel" data-f="company_id">${coOpts(f.company_id)}</select></label>
                <label><span class="md-lbl">نوع</span><select class="md-sel" data-f="kind">${kindOpts(f.kind)}</select></label>
                <label><span class="md-lbl">ماه</span>${periodSel('p', X.period)}</label>
                <label><span class="md-lbl">وضعیت</span><select class="md-sel" data-f="state">${opts({active: 'فعال در این ماه', ended: 'پایان‌یافته', future: 'شروع در ماه‌های بعد', all: 'همه'}, f.state)}</select></label>
                <label><span class="md-lbl">نسبت</span><select class="md-sel" data-f="rel">${opts({'': 'همه', 1: 'فقط اصلی', 2: 'فقط خانواده'}, f.rel)}</select></label>
                <label class="flex-1" style="min-width:180px"><span class="md-lbl">جستجو</span><input class="md-in" data-f="q" value="${esc(f.q)}" placeholder="نام، کد ملی، کد پرسنلی"></label>
                ${can('md-members', 'create') ? '<button class="md-btn p" data-add><i class="fas fa-user-plus"></i>افزودنِ نفر</button>' : ''}
                ${can('md-members', 'export') ? '<button class="md-btn o" data-xl><i class="fas fa-file-excel"></i>اکسل</button>' : ''}</div></div>
                <div class="md-card mt-3" data-list>${spin()}</div>`;
            const load = async () => {
                const list = body.querySelector('[data-list]'); list.innerHTML = spin();
                try {
                    const r = await api('members', Object.assign({}, f, {period: X.period}));
                    list.innerHTML = `<div class="flex justify-between mb-2 text-[12px] text-slate-500"><span>${fa(r.total)} نفر ${r.total > r.items.length ? '(نمایشِ ' + fa(r.items.length) + ' ردیفِ اول)' : ''}</span></div>
                        <div class="md-scroll"><table class="md-tbl no-page"><thead><tr><th>نام</th><th>کد ملی</th><th>نسبت</th><th>شرکت</th><th>نوع</th><th>طرح</th><th>شروع</th><th>پایان</th><th>کد پرسنلی</th><th></th></tr></thead><tbody>
                        ${r.items.map(m => `<tr><td>${nameLink(m.name, m.main_nid)}${m.rel > 1 ? '' : ''}</td><td>${fa(m.nid)}</td><td>${esc(m.rel_fa)}</td><td>${esc(m.company)}</td><td>${m.kind === 'LIFE' ? 'عمر' : 'درمان'}</td>
                            <td>${m.plan ? esc(m.plan) : tag('r', 'بدونِ طرح')}</td><td>${pfa(m.start_p)}</td><td>${m.end_p ? pfa(m.end_p) : '—'}</td><td>${fa(m.personnel_no)}</td>
                            <td class="whitespace-nowrap">${can('md-members', 'edit') ? `<button class="md-btn o sm" data-ed="${m.id}"><i class="fas fa-pen"></i></button>${!m.end_p ? `<button class="md-btn r sm" data-end="${m.id}" title="پایانِ پوشش"><i class="fas fa-user-minus"></i></button>` : ''}` : ''}</td></tr>`).join('') || `<tr><td colspan="10">${empty('کسی نیست')}</td></tr>`}</tbody></table></div>`;
                    bindPersons(list);
                    list.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => this.memberEdit(r.items.find(x => x.id === +b.dataset.ed), load));
                    list.querySelectorAll('[data-end]').forEach(b => b.onclick = () => this.memberEnd(r.items.find(x => x.id === +b.dataset.end), load));
                } catch (e) { list.innerHTML = empty(esc(e.message)); }
            };
            body.querySelectorAll('[data-f]').forEach(i => { const ev = i.tagName === 'INPUT' ? 'input' : 'change'; let tm; i.addEventListener(ev, () => { clearTimeout(tm); tm = setTimeout(() => { f[i.dataset.f] = i.value; load(); }, ev === 'input' ? 300 : 0); }); });
            onPer(body, 'p', p => { X.period = p; load(); });
            const a = body.querySelector('[data-add]'); if (a) a.onclick = () => this.memberEdit(null, load);
            const x = body.querySelector('[data-xl]'); if (x) x.onclick = () => dl('members_export', Object.assign({}, f, {period: X.period}));
            load();
        },
        async memberEdit(mem, done, pre = {}) {
            const cons = await loadContracts();
            const plans = cons.flatMap(c => c.plans.map(p => [p.id, (c.kind === 'LIFE' ? 'عمر · ' : 'درمان · ') + (c.title || c.policy_no || '') + ' · ' + p.name, c.kind]));
            const v = mem || Object.assign({kind: MEM.f.kind || 'HEALTH', rel: 1, company_id: MEM.f.company_id || ''}, pre);
            const inp = (k, l, o = {}) => `<label><span class="md-lbl">${l}</span><input class="md-in ${o.money ? 'money-input' : ''}" data-v="${k}" value="${esc(o.money ? money(v[k]) : (v[k] ?? ''))}" ${o.dir ? `dir="${o.dir}"` : ''} ${o.jd ? 'data-jdate' : ''} ${o.ro ? 'readonly' : ''}></label>`;
            const m = modal(mem ? 'ویرایشِ بیمه‌شده' : 'افزودنِ بیمه‌شده', `
                <div class="md-grid md-g3">
                    ${mem ? `<div class="col-span-full md-hint">شرکت: <b>${esc(mem.company)}</b> · ${mem.kind === 'LIFE' ? 'عمر و حوادث' : 'درمانِ تکمیلی'} · از ${pfa(mem.start_p)}</div>` : `<label><span class="md-lbl">شرکت</span><select class="md-sel" data-v="company_id">${coOpts(v.company_id, '— انتخاب —')}</select></label>
                    <label><span class="md-lbl">نوع</span><select class="md-sel" data-v="kind">${kindOpts(v.kind, '— انتخاب —')}</select></label>`}
                    ${inp('nid', 'کد ملی', {dir: 'ltr', ro: !!mem})}${inp('first_name', 'نام')}${inp('last_name', 'نام خانوادگی')}
                    <label><span class="md-lbl">نسبت</span><select class="md-sel" data-v="rel">${opts(X.cfg.rel_codes, v.rel)}</select></label>${inp('main_nid', 'کد ملیِ بیمه‌شده‌ی اصلی (برای خانواده)', {dir: 'ltr'})}
                    <label><span class="md-lbl">طرح</span><select class="md-sel" data-v="plan_id"><option value="">—</option>${plans.map(([id, t]) => `<option value="${id}" ${+v.plan_id === id ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
                    ${inp('father', 'نام پدر')}<label><span class="md-lbl">جنسیت</span><select class="md-sel" data-v="gender">${opts({'': '—', 0: 'مرد', 1: 'زن'}, v.gender ?? '')}</select></label>${inp('birth_j', 'تاریخ تولد', {jd: 1})}
                    ${inp('id_no', 'شماره شناسنامه', {dir: 'ltr'})}${inp('mobile', 'موبایل', {dir: 'ltr'})}${inp('personnel_no', 'کد پرسنلی')}${inp('sheba', 'شبا (بدون IR)', {dir: 'ltr'})}${inp('bank', 'بانک')}${inp('life_amount', 'مبلغ/سرمایه‌ی متغیرِ عمر', {money: 1})}
                    <label><span class="md-lbl">${mem ? 'تغییرِ طرح/نسبت از ماهِ' : 'شروع از ماهِ'}</span>${periodSel('from', X.period)}</label>${mem ? '' : inp('start_j', 'تاریخِ شروع (اختیاری)', {jd: 1})}
                </div><label class="block mt-2"><span class="md-lbl">یادداشت</span><input class="md-in" data-v="note" value="${esc(v.note || '')}"></label>
                <div class="md-hint mt-2">تغییرِ طرح یا نسبت از ماهِ انتخابی به بعد اعمال می‌شود؛ ماه‌های قبل (و صورت‌حساب‌هایشان) دست نمی‌خورند.</div>`,
                '<button class="md-btn s" data-x>انصراف</button><button class="md-btn p" data-save><i class="fas fa-floppy-disk"></i>ذخیره</button>', {wide: true});
            m.$('[data-save]').onclick = async () => {
                const o = mem ? {id: mem.id} : {};
                m.$$('[data-v]').forEach(i => o[i.dataset.v] = i.value);
                o.from_p = perVal(m.el, 'from');
                const b = m.$('[data-save]'); busy(b, true);
                try { const r = await api('member_save', {member: o}); toast(r.transfer ? 'ذخیره شد؛ از شرکتِ قبلی منتقل شد.' : 'ذخیره شد.', 'success'); m.close(); done && done(); } catch (e) { toast(e.message, 'error'); }
                busy(b, false);
            };
        },
        async memberEnd(mem, done) {
            const m = modal('پایانِ پوشش', `<p class="text-[12.5px] mb-2"><b>${esc(mem.name)}</b> (${fa(mem.nid)}) در ${esc(mem.company)}</p>
                <div class="flex flex-wrap gap-3 items-end"><label><span class="md-lbl">از ابتدای ماهِ</span>${periodSel('e', X.period)}</label><label><span class="md-lbl">تاریخِ اثر (اختیاری)</span><input class="md-in" data-jdate data-ej></label></div>
                ${mem.rel === 1 ? '<label class="flex items-center gap-2 mt-3 text-[12px] font-bold"><input type="checkbox" data-fam checked>خانواده‌اش هم پایان بگیرند</label>' : ''}
                <div class="md-hint mt-2">از این ماه به بعد حساب نمی‌شود.</div>`, '<button class="md-btn s" data-x>انصراف</button><button class="md-btn r" data-ok>پایانِ پوشش</button>');
            m.$('[data-ok]').onclick = async () => {
                try { const r = await api('member_end', {id: mem.id, end_p: perVal(m.el, 'e'), end_j: m.$('[data-ej]').value, family: m.$('[data-fam]') && m.$('[data-fam]').checked ? 1 : 0}); toast(fa(r.n) + ' نفر پایان خوردند.', 'success'); m.close(); done(); } catch (e) { toast(e.message, 'error'); }
            };
        },
        async lists(body) {
            const f = MEM.lf;
            body.innerHTML = `<div class="md-card"><div class="flex flex-wrap gap-2 items-end">
                <label><span class="md-lbl">شرکت</span><select class="md-sel" data-f="company_id">${coOpts(f.company_id)}</select></label>
                <label><span class="md-lbl">نوعِ لیست</span><select class="md-sel" data-f="type">${opts(Object.assign({'': 'همه'}, X.cfg.list_types), f.type)}</select></label>
                <label><span class="md-lbl">وضعیت</span><select class="md-sel" data-f="status">${opts({'': 'همه', DRAFT: 'پیش‌نویس (اعمال‌نشده)', APPLIED: 'اعمال‌شده', VOID: 'ابطال‌شده'}, f.status)}</select></label>
                <span class="flex-1"></span>${can('md-members', 'create') ? '<button class="md-btn p" data-up><i class="fas fa-file-arrow-up"></i>بارگذاریِ لیست</button>' : ''}</div></div>
                <div class="md-card mt-3" data-list>${spin()}</div>`;
            const load = async () => {
                const el = body.querySelector('[data-list]'); el.innerHTML = spin();
                try {
                    const r = await api('lists', f);
                    const st = {DRAFT: tag('a', 'پیش‌نویس'), APPLIED: tag('g', 'اعمال‌شده'), VOID: tag('', 'ابطال')};
                    el.innerHTML = `<div class="md-scroll"><table class="md-tbl no-page"><thead><tr><th>#</th><th>نوعِ لیست</th><th>شرکت</th><th>ماه</th><th>ردیف</th><th>تغییرات</th><th>وضعیت</th><th>افزایشی پاسارگاد</th><th>کاهشی پاسارگاد</th><th></th></tr></thead><tbody>
                        ${r.lists.map(L => `<tr><td>${fa(L.id)}</td><td>${esc(L.type_fa)}<small class="block text-slate-400">${esc(L.kind_fa)}</small></td><td>${esc(L.company)}</td><td>${esc(L.period_fa)}</td><td>${fa(L.rows)}</td>
                            <td class="text-[11px]">${L.add ? '+' + fa(L.add) + ' ' : ''}${L.trans ? '↔' + fa(L.trans) + ' ' : ''}${L.rem ? '−' + fa(L.rem) + ' ' : ''}${L.chg ? '✎' + fa(L.chg) + ' ' : ''}${L.warn ? `<span class="text-amber-600">⚠${fa(L.warn)}</span>` : ''}</td>
                            <td>${st[L.status] || ''}</td><td>${L.status === 'APPLIED' && (L.add + L.trans + L.chg) ? tag(...PAS[L.pas_add]) : '—'}</td><td>${L.status === 'APPLIED' && L.rem ? tag(...PAS[L.pas_rem]) : '—'}</td>
                            <td class="whitespace-nowrap"><button class="md-btn o sm" data-open="${L.id}"><i class="fas fa-eye"></i>جزئیات</button></td></tr>`).join('') || `<tr><td colspan="10">${empty('لیستی بارگذاری نشده')}</td></tr>`}</tbody></table></div>`;
                    el.querySelectorAll('[data-open]').forEach(b => b.onclick = () => this.listView(+b.dataset.open, load));
                } catch (e) { el.innerHTML = empty(esc(e.message)); }
            };
            body.querySelectorAll('[data-f]').forEach(i => i.onchange = () => { f[i.dataset.f] = i.value; load(); });
            const up = body.querySelector('[data-up]'); if (up) up.onclick = () => this.listUpload(load);
            load();
        },
        listUpload(done) {
            const m = modal('بارگذاریِ لیست', `
                <div class="md-grid md-g2">
                    <label><span class="md-lbl">شرکت</span><select class="md-sel" data-v="company_id">${coOpts(MEM.lf.company_id, '— انتخاب —')}</select></label>
                    <label><span class="md-lbl">نوعِ بیمه</span><select class="md-sel" data-v="kind">${kindOpts('HEALTH', '— انتخاب —')}</select></label>
                    <label><span class="md-lbl">نوعِ لیست</span><select class="md-sel" data-v="type">${opts(X.cfg.list_types, 'ADD')}</select></label>
                    <label><span class="md-lbl">مربوط به ماهِ</span>${periodSel('p', X.period)}</label>
                    <label class="col-span-full"><span class="md-lbl">فایلِ اکسل (xlsx / xls)</span><input type="file" class="md-in" data-file accept=".xlsx,.xls,.csv"></label>
                </div><div class="md-hint mt-2">لیستِ شروع/ماهانه = فهرستِ کاملِ آن ماه؛ افزایشی = فقط ورودی‌ها؛ کاهشی = کد ملی + «تاریخ اثر».</div>`,
                '<button class="md-btn s" data-x>انصراف</button><button class="md-btn p" data-go><i class="fas fa-magnifying-glass"></i>خواندن و پیش‌نمایش</button>');
            m.$('[data-go]').onclick = async () => {
                const v = {}; m.$$('[data-v]').forEach(i => v[i.dataset.v] = i.value); v.period = perVal(m.el, 'p');
                const file = m.$('[data-file]').files[0];
                if (!v.company_id || !v.kind || !file) { toast('شرکت، نوعِ بیمه و فایل را انتخاب کنید.', 'error'); return; }
                const b = m.$('[data-go]'); busy(b, true);
                try { const r = await upload('list_upload', file, v); m.close(); done(); this.listView(r.id, done); } catch (e) { toast(e.message, 'error'); }
                busy(b, false);
            };
        },
        async listView(id, done) {
            let L; try { L = (await api('list_get', {id})).list; } catch (e) { toast(e.message, 'error'); return; }
            const o = L.ops || {add: [], remove: [], transfer: [], change: [], warn: [], skip: []};
            const tbl = (rows, cols) => rows.length ? `<div class="md-scroll" style="max-height:300px"><table class="md-tbl no-page"><tbody>${rows.map(r => `<tr>${cols.map(c => `<td>${c(r)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : empty('موردی نیست');
            const rel = r => esc(X.cfg.rel_codes[r.rel] || '');
            const tabs = [['add', 'ورود', o.add.length], ['transfer', 'انتقال', o.transfer.length], ['remove', 'خروج', o.remove.length], ['change', 'تغییر', o.change.length], ['warn', 'هشدار', o.warn.length + o.skip.length]];
            const sec = {
                add: tbl(o.add, [r => nameLink(r.name, r.item ? r.item.main_nid : r.nid), r => fa(r.nid), rel, r => 'از ' + pfa(r.start_p), r => r.item && r.item.end_p ? 'تا ' + pfa(r.item.end_p) : '']),
                transfer: tbl(o.transfer, [r => nameLink(r.name, r.nid), r => fa(r.nid), r => esc(r.from) + ' ← این شرکت', r => 'از ' + pfa(r.start_p)]),
                remove: tbl(o.remove, [r => nameLink(r.name, r.nid), r => fa(r.nid), rel, r => 'از ' + pfa(r.end_p)]),
                change: tbl(o.change, [r => nameLink(r.name, r.nid), r => fa(r.nid), r => esc(Object.keys(r.set).map(k => ({plan_id: 'طرح', rel: 'نسبت', main_nid: 'سرپرست', life_amount: 'مبلغ عمر'})[k] || k).join('، ')), r => 'از ' + pfa(r.from_p)]),
                warn: tbl(o.warn.concat(o.skip), [r => fa(r.nid), r => esc(r.name || ''), r => esc(r.msg)]),
            };
            const draft = L.status === 'DRAFT', applied = L.status === 'APPLIED';
            const pasBtns = applied ? `<div class="md-card mt-3" style="background:#f0fdfa"><h3><i class="fas fa-arrow-right-to-bracket text-teal-700"></i>ورودی به پاسارگاد</h3><div class="flex flex-wrap gap-2 items-center">
                ${(L.add + L.trans + L.chg) ? `<span>${L.type === 'START' ? 'شروعِ قرارداد' : 'افزایشی'}: ${tag(...PAS[L.pas_add])}</span>${can('md-members', 'export') ? `<button class="md-btn p sm" data-pas="add|xlsx">xlsx</button><button class="md-btn p sm" data-pas="add|xls">xls</button><button class="md-btn o sm" data-pas="bank|xlsx">اطلاعات بانکی</button>` : ''}${can('md-members', 'edit') ? `<button class="md-btn s sm" data-mark="add|${L.pas_add === 'DONE' ? 'EXPORTED' : 'DONE'}">${L.pas_add === 'DONE' ? 'برداشتنِ تأیید' : 'تأییدِ بیمه‌گر'}</button>` : ''}` : ''}
                ${L.rem ? `<span class="mr-3">کاهشی: ${tag(...PAS[L.pas_rem])}</span>${can('md-members', 'export') ? `<button class="md-btn p sm" data-pas="remove|xlsx">xlsx</button><button class="md-btn p sm" data-pas="remove|xls">xls</button>` : ''}${can('md-members', 'edit') ? `<button class="md-btn s sm" data-mark="remove|${L.pas_rem === 'DONE' ? 'EXPORTED' : 'DONE'}">${L.pas_rem === 'DONE' ? 'برداشتنِ تأیید' : 'تأییدِ بیمه‌گر'}</button>` : ''}` : ''}
                </div><div class="md-hint">با گرفتنِ خروجی، وضعیت «ورود شده به پاسارگاد» می‌شود و نسخه‌ای در بایگانیِ همان شرکت و ماه می‌ماند.</div></div>` : '';
            const m = modal(`${esc(L.type_fa)} — ${esc(L.company)} — ${esc(L.period_fa)}`, `
                <div class="flex flex-wrap gap-2 mb-3">${tag('b', L.kind_fa)}${tag(L.status === 'APPLIED' ? 'g' : L.status === 'VOID' ? '' : 'a', {DRAFT: 'پیش‌نویس — هنوز اعمال نشده', APPLIED: 'اعمال‌شده', VOID: 'ابطال‌شده'}[L.status])}
                    ${tag('', fa(L.rows) + ' ردیف در فایل')}${L.file_name ? `<a href="#" data-file class="md-tag c"><i class="fas fa-download"></i>${esc(L.file_name)}</a>` : ''}${L.void_reason ? tag('r', 'دلیلِ ابطال: ' + L.void_reason) : ''}</div>
                <div class="md-kpis mb-3">${tabs.map(([k, t, n]) => `<div class="md-kpi" style="cursor:pointer" data-t="${k}"><div class="k">${t}</div><div class="v">${fa(n)}</div></div>`).join('')}</div>
                <div data-sec>${sec[(tabs.find(t => t[2] > 0) || tabs[0])[0]]}</div>${pasBtns}
                ${L.headers && L.headers.length ? `<details class="md-help mt-3"><summary>ستون‌های خوانده‌شده</summary><ul>${Object.entries(L.map || {}).map(([k, i]) => `<li>${esc(L.headers[i] || '')} ← ${esc(k)}</li>`).join('')}</ul></details>` : ''}`,
                `<button class="md-btn s" data-x>بستن</button>${(draft || applied) && can('md-members', 'delete') ? `<button class="md-btn r" data-void><i class="fas fa-ban"></i>${draft ? 'حذفِ پیش‌نویس' : 'ابطال و برگرداندنِ تغییرات'}</button>` : ''}${draft && can('md-members', 'edit') ? '<button class="md-btn p" data-apply><i class="fas fa-check"></i>اعمالِ لیست</button>' : ''}`, {wide: true});
            bindPersons(m.el);
            m.$$('[data-t]').forEach(k => k.onclick = () => { m.$('[data-sec]').innerHTML = sec[k.dataset.t]; bindPersons(m.$('[data-sec]')); });
            const fl = m.$('[data-file]'); if (fl) fl.onclick = e => { e.preventDefault(); dl('list_file', {id}); };
            m.$$('[data-pas]').forEach(b => b.onclick = () => { const [part, fmt] = b.dataset.pas.split('|'); dl('pas_export', {id, part, fmt}); setTimeout(() => { m.close(); done && done(); this.listView(id, done); }, 1500); });
            m.$$('[data-mark]').forEach(b => b.onclick = async () => { const [part, status] = b.dataset.mark.split('|'); try { await api('pas_mark', {id, part, status}); m.close(); done && done(); this.listView(id, done); } catch (e) { toast(e.message, 'error'); } });
            const ap = m.$('[data-apply]');
            if (ap) ap.onclick = async () => {
                if (!(await ask('اعمالِ لیست', `${fa(o.add.length)} ورود، ${fa(o.transfer.length)} انتقال، ${fa(o.remove.length)} خروج و ${fa(o.change.length)} تغییر اعمال شود؟`, {ok: 'اعمال شود'}))) return;
                busy(ap, true);
                try { await api('list_apply', {id}); toast('لیست اعمال شد.', 'success'); m.close(); done && done(); this.listView(id, done); } catch (e) { toast(e.message, 'error'); busy(ap, false); }
            };
            const vd = m.$('[data-void]');
            if (vd) vd.onclick = async () => {
                const reason = await askText(draft ? 'حذفِ پیش‌نویس' : 'ابطالِ لیست', draft ? 'توضیح (اختیاری)' : 'دلیلِ ابطال — همه‌ی ورود/خروج/انتقال‌های این لیست برمی‌گردد', draft ? 'حذف' : 'ابطال', {danger: true, required: !draft});
                if (reason === null) return;
                try { await api('list_void', {id, reason}); toast('انجام شد.', 'success'); m.close(); done && done(); } catch (e) { toast(e.message, 'error'); }
            };
        },
        async pas(body) {
            body.innerHTML = `<div class="md-card flex flex-wrap gap-2 items-end"><label><span class="md-lbl">ماه</span>${periodSel('p', X.period)}</label><div class="md-hint">وضعیتِ «ورود به پاسارگاد» هر شرکت، جدا برای لیستِ شروع، افزایشی و کاهشی.</div></div><div class="md-card mt-3" data-list>${spin()}</div>`;
            const load = async () => {
                const el = body.querySelector('[data-list]');
                try {
                    const r = await api('pas_overview', {period: X.period});
                    const c = v => v ? tag(...PAS[v]) : '<span class="text-slate-300">—</span>';
                    el.innerHTML = `<table class="md-tbl no-page"><thead><tr><th>شرکت</th><th>نوع</th><th>شروعِ قرارداد</th><th>افزایشی</th><th>کاهشی</th><th>لیست‌ها</th></tr></thead><tbody>${r.rows.map(x => `<tr><td>${esc(x.company)}</td><td>${esc(x.kind_fa)}</td><td>${c(x.start)}</td><td>${c(x.add)}</td><td>${c(x.remove)}</td><td>${x.lists.map(i => `<a href="#" data-open="${i}" class="md-tag b">#${fa(i)}</a>`).join(' ')}</td></tr>`).join('') || `<tr><td colspan="6">${empty('در این ماه لیستی اعمال نشده')}</td></tr>`}</tbody></table>`;
                    el.querySelectorAll('[data-open]').forEach(a => a.onclick = e => { e.preventDefault(); this.listView(+a.dataset.open, load); });
                } catch (e) { el.innerHTML = empty(esc(e.message)); }
            };
            onPer(body, 'p', p => { X.period = p; load(); });
            load();
        }
    };

    // ============================ مغایرت با سند حقوق ============================
    Treat.pages['md-payroll'] = {
        async render(root) {
            await cfg(); await loadCompanies();
            root.innerHTML = shell('md-payroll', `
                ${help('راهنمای مغایرت‌گیری با سند حقوق', [
                    'پیش از صدورِ صورت‌حسابِ هر ماه، مغایرت‌گیری با سند حقوقِ همان ماه <b>الزامی</b> است.',
                    'سند حقوق یک اکسل است که هر شیتش یک شرکت است؛ شیت‌ها خودکار به شرکت‌ها وصل می‌شوند (اگر اسم فرق داشت یک بار انتخاب کنید، دفعه‌ی بعد یادش می‌ماند).',
                    'ستون‌ها از روی عنوان خوانده می‌شوند: شماره پرسنلی، کد ملی، نام، طرح (flag)، سهمِ شخص (بیمه تکمیلی)، سهمِ کارفرما، جمع (کارگر+کارفرما)، تعداد، بیمه عمر. اگر شیتی بعضی ستون‌ها را ندارد، فقط با ستون‌هایی که دارد بررسی می‌شود.',
                    'شرکتی که در سند نبود «در سند حقوق نبود» می‌خورد تا با سندِ بعدی بررسی شود؛ شرکتی که قبلاً مغایرت‌گیری شده در سندِ بعدی رد می‌شود (برای بررسیِ دوباره اول «ابطال» بزنید).',
                    'مغایرت‌ها را در جا اصلاح کنید (طرح از سند، افزودن به لیست، پایانِ پوشش، ویرایشِ خانواده) یا با توضیح بپذیرید و در آخر «تأیید» بزنید. اکسلِ سندِ هر شرکت با گزارشِ مغایرت در پوشه‌ی همان شرکت و ماه بایگانی می‌شود.',
                ])}
                <div class="md-card flex flex-wrap gap-2 items-end"><label><span class="md-lbl">ماه</span>${periodSel('p', X.period)}</label><span class="flex-1"></span>
                    ${can('md-payroll', 'create') ? '<button class="md-btn p" data-up><i class="fas fa-file-arrow-up"></i>بارگذاریِ سند حقوق</button>' : ''}<button class="md-btn o" data-hist><i class="fas fa-clock-rotate-left"></i>سندهای بارگذاری‌شده</button></div>
                <div class="md-card mt-3" data-list>${spin()}</div>`);
            onPer(root, 'p', p => { X.period = p; this.render(root); });
            const up = root.querySelector('[data-up]'); if (up) up.onclick = () => this.upload(() => this.render(root));
            root.querySelector('[data-hist]').onclick = () => this.history();
            const el = root.querySelector('[data-list]');
            try {
                const [rec, board] = await Promise.all([api('recons', {period: X.period}), api('bill_board', {period: X.period}).catch(() => ({rows: []}))]);
                const byCo = {}; rec.items.forEach(r => byCo[r.company_id] = r);
                const cos = {}; board.rows.forEach(b => { cos[b.company_id] = cos[b.company_id] || {company_id: b.company_id, company: b.company, count: 0}; cos[b.company_id].count += b.count; });
                rec.items.forEach(r => { if (!cos[r.company_id]) cos[r.company_id] = {company_id: r.company_id, company: r.company, count: 0}; });
                const rows = Object.values(cos).sort((a, b) => a.company.localeCompare(b.company, 'fa'));
                el.innerHTML = `<table class="md-tbl no-page"><thead><tr><th>شرکت</th><th>بیمه‌شدگان</th><th>وضعیت</th><th>مغایرت‌ها</th><th>شیت</th><th>آخرین رخداد</th><th></th></tr></thead><tbody>${rows.map(c => {
                    const r = byCo[c.company_id]; const st = r ? RECON[r.status] : ['', 'انجام‌نشده'];
                    const last = r && r.history ? r.history.trim().split('\n').pop() : '';
                    return `<tr><td><b>${esc(c.company)}</b></td><td>${fa(c.count)}</td><td>${tag(st[0], st[1])}</td><td>${r && r.stats && r.stats.items !== undefined ? `${fa(r.stats.items)} <small class="text-slate-500">(${fa(r.stats.open)} باز)</small>` : (r && r.stats && r.stats.nopayroll ? 'بدونِ سند حقوق' : '—')}</td>
                        <td>${esc(r ? r.sheet : '')}</td><td class="text-[11px] text-slate-500" style="max-width:280px">${esc(fa(last))}</td>
                        <td class="whitespace-nowrap">${r && ['OPEN', 'CONFIRMED'].includes(r.status) && r.sheet ? `<button class="md-btn p sm" data-open="${r.id}"><i class="fas fa-list-check"></i>بررسی</button>` : ''}
                        ${r && r.has_file ? `<button class="md-btn o sm" data-file="${r.id}" title="اکسلِ سند و مغایرت‌ها"><i class="fas fa-file-excel"></i></button>` : ''}
                        ${(!r || r.status === 'MISSING') && can('md-payroll', 'edit') ? `<button class="md-btn s sm" data-nop="${c.company_id}" title="این شرکت کسر از حقوق ندارد">بدونِ سند حقوق</button>` : ''}
                        ${r && r.status !== 'MISSING' && can('md-payroll', 'delete') ? `<button class="md-btn r sm" data-void="${r.id}" title="ابطال"><i class="fas fa-ban"></i></button>` : ''}</td></tr>`; }).join('') || `<tr><td colspan="7">${empty('در این ماه بیمه‌شده‌ی فعالی نیست')}</td></tr>`}</tbody></table>`;
                el.querySelectorAll('[data-open]').forEach(b => b.onclick = () => this.detail(+b.dataset.open, () => this.render(root)));
                el.querySelectorAll('[data-file]').forEach(b => b.onclick = () => dl('recon_file', {id: b.dataset.file}));
                el.querySelectorAll('[data-nop]').forEach(b => b.onclick = async () => { const note = await askText('تأیید بدونِ سند حقوق', 'دلیل (مثلاً: این شرکت حق‌بیمه را یک‌جا می‌پردازد و کسر از حقوق ندارد)', 'تأیید'); if (note === null) return; try { await api('recon_nopayroll', {company_id: b.dataset.nop, period: X.period, note}); toast('تأیید شد.', 'success'); this.render(root); } catch (e) { toast(e.message, 'error'); } });
                el.querySelectorAll('[data-void]').forEach(b => b.onclick = async () => { const reason = await askText('ابطالِ مغایرت‌گیری', 'دلیلِ ابطال (بعد از ابطال، با بارگذاریِ دوباره‌ی سند حقوق از نو بررسی می‌شود)', 'ابطال', {danger: true}); if (reason === null) return; try { await api('recon_void', {id: b.dataset.void, reason}); toast('ابطال شد.', 'success'); this.render(root); } catch (e) { toast(e.message, 'error'); } });
            } catch (e) { el.innerHTML = empty(esc(e.message)); }
        },
        upload(done) {
            const m = modal('بارگذاریِ سند حقوق — ' + pfa(X.period), `<label class="block"><span class="md-lbl">فایلِ اکسل (هر شیت یک شرکت)</span><input type="file" class="md-in" data-file accept=".xlsx,.xls"></label><div data-prev class="mt-3"></div>`,
                '<button class="md-btn s" data-x>انصراف</button><button class="md-btn p" data-go disabled><i class="fas fa-check"></i>ثبت و مغایرت‌گیری</button>', {wide: true});
            let P = null;
            m.$('[data-file]').onchange = async () => {
                const f = m.$('[data-file]').files[0]; if (!f) return;
                m.$('[data-prev]').innerHTML = spin();
                try { P = await upload('payroll_preview', f, {period: X.period}); } catch (e) { m.$('[data-prev]').innerHTML = empty(esc(e.message)); return; }
                const st = {}; P.preview.companies.forEach(c => st[c.company_id] = c);
                const used = new Set(P.preview.sheets.map(s => s.company_id).filter(Boolean));
                const missing = P.preview.companies.filter(c => c.has_members && !used.has(c.company_id));
                m.$('[data-prev]').innerHTML = `<table class="md-tbl no-page"><thead><tr><th>شیت</th><th>ردیف</th><th>ستون‌های پیداشده</th><th>شرکت</th><th></th></tr></thead><tbody>${P.preview.sheets.map(s => `<tr><td><b>${esc(s.sheet)}</b></td><td>${fa(s.rows)}</td>
                    <td class="text-[11px]">${s.cols.map(c => ({nid: 'کد ملی', pno: 'پرسنلی', name: 'نام', plan: 'طرح', employee: 'سهم شخص', employer: 'سهم کارفرما', total: 'جمع', count: 'تعداد', life: 'عمر'})[c] || c).join('، ')}</td>
                    <td><select class="md-sel" data-sheet="${esc(s.sheet)}"><option value="0">— رد شود —</option>${(M.companies || []).map(c => `<option value="${c.id}" ${c.id === s.company_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></td>
                    <td data-st="${esc(s.sheet)}"></td></tr>`).join('')}</tbody></table>
                    ${missing.length ? `<div class="md-card mt-3" style="background:#fff7ed;border-color:#fed7aa"><b class="text-[12px] text-amber-800"><i class="fas fa-triangle-exclamation ml-1"></i>این شرکت‌ها بیمه‌شده دارند ولی در این سند نیستند و «در سند حقوق نبود» می‌خورند:</b><div class="text-[12px] mt-1">${missing.map(c => esc(c.company)).join('، ')}</div></div>` : ''}`;
                const paintSt = () => m.$$('[data-sheet]').forEach(s => { const c = st[+s.value]; m.$(`[data-st="${CSS.escape(s.dataset.sheet)}"]`).innerHTML = !+s.value ? '' : (c && c.recon && c.recon !== 'MISSING' ? tag('r', 'قبلاً مغایرت‌گیری شده — رد می‌شود') : c && c.recon === 'MISSING' ? tag('a', 'قبلاً نبود — حالا بررسی می‌شود') : tag('g', 'بررسی می‌شود')); });
                m.$$('[data-sheet]').forEach(s => s.onchange = paintSt); paintSt();
                m.$('[data-go]').disabled = false;
            };
            m.$('[data-go]').onclick = async () => {
                const map = {}; m.$$('[data-sheet]').forEach(s => map[s.dataset.sheet] = +s.value);
                const b = m.$('[data-go]'); busy(b, true);
                try {
                    const r = (await api('payroll_commit', {token: P.token, name: P.name, period: X.period, map})).result;
                    m.close(); done();
                    modal('نتیجه‌ی بارگذاریِ سند حقوق', `<ul class="text-[12.5px] leading-8">
                        ${r.done.map(d => `<li><i class="fas fa-circle-check text-emerald-600 ml-1"></i>${esc(d.company)}: ${d.issues ? fa(d.issues) + ' مغایرت' : 'بدونِ مغایرت'}</li>`).join('')}
                        ${r.duplicate.map(d => `<li><i class="fas fa-ban text-rose-600 ml-1"></i>${esc(d.company)}: ${esc(d.msg)}</li>`).join('')}
                        ${r.missing.map(n => `<li><i class="fas fa-triangle-exclamation text-amber-600 ml-1"></i>${esc(n)}: در این سند نبود؛ مغایرت‌گیری نشد</li>`).join('')}
                        ${r.ignored.map(n => `<li class="text-slate-400"><i class="fas fa-minus ml-1"></i>شیتِ «${esc(n)}» رد شد</li>`).join('')}</ul>`, '<button class="md-btn p" data-x>باشه</button>');
                } catch (e) { toast(e.message, 'error'); busy(b, false); }
            };
        },
        async history() {
            const r = await api('payrolls');
            modal('سندهای حقوقِ بارگذاری‌شده', r.items.length ? `<table class="md-tbl no-page"><thead><tr><th>ماه</th><th>فایل</th><th>نتیجه</th><th>توسط</th><th></th></tr></thead><tbody>${r.items.map(p => `<tr><td>${esc(p.period_fa)}</td><td>${esc(p.file_name)}</td>
                <td class="text-[11px]">${p.result ? `${fa(p.result.done.length)} بررسی · ${fa(p.result.missing.length)} نبود · ${fa(p.result.duplicate.length)} تکراری` : ''}</td><td class="text-[11px]">${esc(p.created_name)}<br>${fa(p.created_at)}</td>
                <td><button class="md-btn o sm" onclick="window.open('${API}?action=payroll_file&id=${p.id}')"><i class="fas fa-download"></i></button></td></tr>`).join('')}</tbody></table>` : empty('هنوز سندی بارگذاری نشده'), '<button class="md-btn s" data-x>بستن</button>', {wide: true});
        },
        async detail(id, done) {
            let R; try { R = (await api('recon_get', {id})).recon; } catch (e) { toast(e.message, 'error'); return; }
            const fmt = v => v === null || v === undefined ? '—' : (typeof v === 'object' ? Object.entries(v).filter(([, x]) => x !== null).map(([k, x]) => `${({plan: 'طرح', count: 'تعداد', total: 'جمع', employee: 'سهم شخص'})[k] || k}: ${money(x)}`).join(' · ') : money(v));
            const canE = can('md-payroll', 'edit') && R.status !== 'VOID';
            const fixBtn = it => {
                if (!canE || it.res) return '';
                if (it.type === 'PLAN') return `<button class="md-btn o sm" data-fix="plan_from_payroll" data-key="${esc(it.key)}">طرح از سند</button>`;
                if (['NOT_IN_PAYROLL', 'LIFE_MISSING'].includes(it.type)) return `<button class="md-btn o sm" data-fix="end_member" data-key="${esc(it.key)}">پایانِ پوشش از این ماه</button>`;
                if (['NOT_IN_LIST', 'LIFE_NOT_IN_LIST'].includes(it.type)) return `<button class="md-btn o sm" data-fix="add_member" data-key="${esc(it.key)}">افزودن به لیست</button>`;
                if (it.type === 'COUNT' && /^\d{10}$/.test(it.nid)) return `<button class="md-btn o sm" data-fam="${esc(it.nid)}" title="خانواده را در پرونده ببینید و عضوِ جاافتاده را اضافه کنید">${+it.pay > +it.exp ? 'افزودنِ عضوِ خانواده' : 'خانواده'}</button>`;
                return '';
            };
            const resTag = it => it.res === 'OK' ? tag('g', 'پذیرفته' + (it.note ? ': ' + it.note : '')) : it.res === 'FIXED' ? tag('c', 'اصلاح شد') : tag('a', 'باز');
            const m = modal(`مغایرت‌گیری — ${esc(R.company)} — ${esc(R.period_fa)}`, `
                <div class="flex flex-wrap gap-2 mb-3">${tag(...RECON[R.status])}${R.stats ? `${tag('', fa(R.stats.payroll_rows) + ' ردیفِ سند')}${tag('', fa(R.stats.families) + ' بیمه‌شده‌ی اصلیِ درمان')}${tag('b', 'حق‌بیمه‌ی درمان ' + money(R.stats.health_total))}${tag('b', 'عمر ' + money(R.stats.life_total))}` : ''}
                    ${tag('', 'ستون‌ها: ' + (R.cols || []).map(c => ({nid: 'کد ملی', pno: 'پرسنلی', name: 'نام', plan: 'طرح', employee: 'سهم شخص', employer: 'سهم کارفرما', total: 'جمع', count: 'تعداد', life: 'عمر'})[c] || c).join('، '))}</div>
                ${R.items.length ? `<div class="flex flex-wrap gap-2 mb-2">${canE ? '<button class="md-btn s sm" data-allok><i class="fas fa-check-double"></i>پذیرفتنِ انتخاب‌شده‌ها با توضیح</button>' : ''}<label class="text-[11.5px] flex items-center gap-1"><input type="checkbox" data-onlyopen checked>فقط موارد باز</label></div>
                <div class="md-scroll" style="max-height:52vh"><table class="md-tbl no-page"><thead><tr><th><input type="checkbox" data-chkall></th><th>نام</th><th>کد ملی / پرسنلی</th><th>مغایرت</th><th>لیستِ درمان</th><th>سند حقوق</th><th>وضعیت</th><th></th></tr></thead><tbody>
                ${R.items.map(it => `<tr data-row="${esc(it.key)}" data-open="${it.res ? 0 : 1}"><td>${it.res ? '' : `<input type="checkbox" data-chk="${esc(it.key)}">`}</td><td>${/^\d{10}$/.test(it.nid) ? nameLink(it.name, it.nid) : esc(it.name)}</td><td>${fa(it.nid)}<small class="block text-slate-400">${fa(it.pno)}</small></td>
                    <td>${esc(it.type_fa)}</td><td>${fmt(it.exp)}</td><td>${fmt(it.pay)}</td><td>${resTag(it)}</td>
                    <td class="whitespace-nowrap">${fixBtn(it)}${canE ? (it.res ? `<button class="md-btn s sm" data-reopen="${esc(it.key)}">باز کن</button>` : `<button class="md-btn s sm" data-ok1="${esc(it.key)}">پذیرفتن</button>`) : ''}</td></tr>`).join('')}</tbody></table></div>` : empty('مغایرتی نیست؛ «تأیید» را بزنید.', 'fa-circle-check')}
                <details class="md-help mt-3"><summary>سابقه</summary><pre class="text-[11px] whitespace-pre-wrap leading-6 pb-2">${esc(fa(R.history || ''))}</pre></details>`,
                `<button class="md-btn s" data-x>بستن</button>${canE ? '<button class="md-btn o" data-re><i class="fas fa-rotate"></i>بررسیِ دوباره</button>' : ''}${canE && R.status !== 'CONFIRMED' ? '<button class="md-btn p" data-conf><i class="fas fa-check"></i>تأییدِ مغایرت‌گیری</button>' : ''}`, {wide: true, sticky: true});
            bindPersons(m.el);
            const reload = () => { m.close(); done && done(); this.detail(id, done); };
            const filt = () => { const only = m.$('[data-onlyopen]') && m.$('[data-onlyopen]').checked; m.$$('tr[data-row]').forEach(tr => tr.style.display = only && tr.dataset.open === '0' ? 'none' : ''); };
            const oo = m.$('[data-onlyopen]'); if (oo) { oo.onchange = filt; filt(); }
            const ca = m.$('[data-chkall]'); if (ca) ca.onchange = () => m.$$('[data-chk]').forEach(c => { if (c.closest('tr').style.display !== 'none') c.checked = ca.checked; });
            const resolve = async (keys, res) => { let note = ''; if (res === 'OK') { note = await askText('پذیرفتنِ مغایرت', 'توضیح (چرا این مغایرت پذیرفته می‌شود)', 'پذیرفتن'); if (note === null) return; } try { await api('recon_resolve', {id, keys, res, note}); reload(); } catch (e) { toast(e.message, 'error'); } };
            m.$$('[data-ok1]').forEach(b => b.onclick = () => resolve([b.dataset.ok1], 'OK'));
            m.$$('[data-reopen]').forEach(b => b.onclick = () => resolve([b.dataset.reopen], ''));
            const ak = m.$('[data-allok]'); if (ak) ak.onclick = () => { const keys = m.$$('[data-chk]:checked').map(c => c.dataset.chk); if (!keys.length) { toast('ردیف‌ها را تیک بزنید.', 'error'); return; } resolve(keys, 'OK'); };
            m.$$('[data-fix]').forEach(b => b.onclick = async () => { const key = b.dataset.key, what = b.dataset.fix; busy(b, true); try { await api('recon_fix', {id, key, what}); toast('اصلاح شد.', 'success'); reload(); } catch (e) { toast(e.message, 'error'); busy(b, false); } });
            m.$$('[data-fam]').forEach(b => b.onclick = () => {
                const it = R.items.find(x => x.nid === b.dataset.fam && x.type === 'COUNT');
                if (+it.pay > +it.exp) Treat.pages['md-members'].memberEdit(null, reload, {company_id: R.company_id, kind: 'HEALTH', rel: 3, main_nid: it.nid});
                else openPerson(it.nid);
            });
            const re = m.$('[data-re]'); if (re) re.onclick = async () => { busy(re, true); try { await api('recon_recompute', {id}); reload(); } catch (e) { toast(e.message, 'error'); busy(re, false); } };
            const cf = m.$('[data-conf]');
            if (cf) cf.onclick = async () => {
                const open = R.items.filter(x => !x.res).length;
                let note = '', accept = 0;
                if (open) { note = await askText('تأیید با مغایرت‌های باز', `${fa(open)} مغایرتِ باز مانده؛ با تأیید، همه با این توضیح پذیرفته می‌شوند:`, 'پذیرفتن و تأیید'); if (note === null) return; accept = 1; }
                try { await api('recon_confirm', {id, accept_rest: accept, note}); toast('مغایرت‌گیری تأیید شد؛ حالا می‌توانید صورت‌حساب صادر کنید.', 'success'); m.close(); done && done(); } catch (e) { toast(e.message, 'error'); }
            };
        }
    };

    // ============================ صورت‌حساب‌ها ============================
    const BL = {sub: 'board', f: {company_id: '', kind: '', state: ''}};
    Treat.pages['md-bills'] = {
        async render(root) {
            await cfg(); await loadCompanies();
            root.innerHTML = shell('md-bills', `
                ${help('راهنمای صورت‌حساب‌ها', [
                    'صورت‌حسابِ هر ماه برای هر شرکت (درمان و عمر جدا) از روی بیمه‌شدگانِ فعالِ همان ماه ساخته می‌شود؛ پیش از آن مغایرت‌گیری با سند حقوقِ همان ماه باید تأیید شده باشد.',
                    'متنِ صورت‌حساب مثلِ نمونه همه‌ی ماه‌های پرداخت‌نشده‌ی شرکت را با مبلغِ پرداختی و مانده نشان می‌دهد. شماره‌گذاری، سربرگ، امضا و متن‌ها در «تنظیمات» قابلِ تغییرند.',
                    '«تجمیعی» برای گروهی از شرکت‌ها (مثلاً شرکتِ مادر و زیرمجموعه‌ها) یک جدول با تعداد و حق‌بیمه‌ی هر شرکت می‌سازد.',
                    'صورت‌حسابِ اشتباه را «ابطال» کنید و دوباره صادر کنید؛ دریافت‌های تخصیص‌یافته آزاد و دوباره تخصیص داده می‌شوند.',
                ])}
                ${subNav([['board', 'صدورِ ماهانه', 'fa-wand-magic-sparkles'], ['list', 'صورت‌حساب‌ها', 'fa-file-invoice-dollar'], ['cons', 'تجمیعی', 'fa-layer-group']], BL.sub)}<div data-body></div>`);
            root.querySelectorAll('[data-sub] [data-k]').forEach(b => b.onclick = () => { BL.sub = b.dataset.k; this.render(root); });
            const body = root.querySelector('[data-body]');
            if (BL.sub === 'board') return this.board(body, root);
            if (BL.sub === 'list') return this.list(body);
            return this.cons(body);
        },
        async board(body, root) {
            body.innerHTML = `<div class="md-card flex flex-wrap gap-2 items-end"><label><span class="md-lbl">ماه</span>${periodSel('p', X.period)}</label>
                <label><span class="md-lbl">تاریخِ صدور</span><input class="md-in" data-jdate data-issue value="${esc(fa(X.cfg.today))}"></label><span class="flex-1"></span>
                ${can('md-bills', 'create') ? '<button class="md-btn p" data-bulk><i class="fas fa-file-circle-plus"></i>صدورِ انتخاب‌شده‌ها</button>' : ''}</div><div class="md-card mt-3" data-list>${spin()}</div>`;
            onPer(body, 'p', p => { X.period = p; this.board(body, root); });
            const el = body.querySelector('[data-list]');
            let rows;
            try { rows = (await api('bill_board', {period: X.period})).rows; } catch (e) { el.innerHTML = empty(esc(e.message)); return; }
            el.innerHTML = `<table class="md-tbl no-page"><thead><tr><th></th><th>شرکت</th><th>نوع</th><th>بیمه‌شدگان</th><th>طرح‌ها</th><th>حق‌بیمه</th><th>مغایرت‌گیری</th><th>صورت‌حساب</th></tr></thead><tbody>${rows.map((r, i) => {
                const ready = r.recon === 'CONFIRMED' && !r.bill && !r.no_plan;
                return `<tr><td>${ready && can('md-bills', 'create') ? `<input type="checkbox" data-i="${i}" checked>` : ''}</td><td><b>${esc(r.company)}</b></td><td>${esc(r.kind_fa)}</td><td>${fa(r.count)}${r.no_plan ? '<br>' + tag('r', fa(r.no_plan) + ' بدونِ طرح') : ''}</td>
                    <td class="text-[11px]">${r.plans.map(p => `${esc(p.name)}: ${fa(p.count)} × ${p.rate !== null ? money(p.rate) : 'متغیر'}`).join('<br>')}</td><td>${money(r.amount)}</td>
                    <td>${r.recon ? tag(...RECON[r.recon]) : tag('', 'انجام‌نشده')}</td>
                    <td>${r.bill ? `<a href="#" data-pdf="${r.bill.id}" class="md-tag g"><i class="fas fa-file-pdf"></i>${fa(r.bill.no)}</a> ${r.bill.remain ? '<small>مانده ' + money(r.bill.remain) + '</small>' : tag('c', 'پرداخت‌شده')}` : (r.recon === 'CONFIRMED' ? '<span class="text-slate-400">آماده‌ی صدور</span>' : '<span class="text-amber-600 text-[11px]">اول مغایرت‌گیری را تأیید کنید</span>')}</td></tr>`; }).join('') || `<tr><td colspan="8">${empty('در این ماه بیمه‌شده‌ی فعالی نیست')}</td></tr>`}</tbody></table>`;
            el.querySelectorAll('[data-pdf]').forEach(a => a.onclick = e => { e.preventDefault(); dl('bill_pdf', {id: a.dataset.pdf}); });
            const bk = body.querySelector('[data-bulk]');
            if (bk) bk.onclick = async () => {
                const items = [...el.querySelectorAll('[data-i]:checked')].map(c => ({company_id: rows[+c.dataset.i].company_id, kind: rows[+c.dataset.i].kind}));
                if (!items.length) { toast('موردی برای صدور انتخاب نشده.', 'error'); return; }
                if (!(await ask('صدورِ صورت‌حساب', `${fa(items.length)} صورت‌حساب برای ${pfa(X.period)} صادر شود؟`, {ok: 'صادر شود'}))) return;
                busy(bk, true);
                try { const r = await api('bill_create', {items, period: X.period, issue_j: en(body.querySelector('[data-issue]').value)}); toast(fa(r.ids.length) + ' صورت‌حساب صادر شد.' + (r.errors.length ? ' خطا: ' + r.errors.join(' | ') : ''), r.errors.length ? 'error' : 'success'); this.board(body, root); }
                catch (e) { toast(e.message, 'error'); busy(bk, false); }
            };
        },
        async list(body) {
            const f = BL.f;
            body.innerHTML = `<div class="md-card"><div class="flex flex-wrap gap-2 items-end">
                <label><span class="md-lbl">شرکت</span><select class="md-sel" data-f="company_id">${coOpts(f.company_id)}</select></label><label><span class="md-lbl">نوع</span><select class="md-sel" data-f="kind">${kindOpts(f.kind)}</select></label>
                <label><span class="md-lbl">وضعیت</span><select class="md-sel" data-f="state">${opts({'': 'همه', unpaid: 'پرداخت‌نشده', overdue: 'سررسید گذشته'}, f.state)}</select></label>
                <label class="flex items-center gap-1 text-[12px]"><input type="checkbox" data-void>ابطال‌شده‌ها هم</label></div></div><div class="md-card mt-3" data-list>${spin()}</div>`;
            const load = async () => {
                const el = body.querySelector('[data-list]'); el.innerHTML = spin();
                try {
                    const r = await api('bills', Object.assign({}, f, {with_void: body.querySelector('[data-void]').checked ? 1 : 0}));
                    let sum = 0, rem = 0; r.items.forEach(b => { if (b.status === 'ISSUED') { sum += b.total; rem += b.remain; } });
                    el.innerHTML = `<div class="text-[12px] text-slate-500 mb-2">${fa(r.items.length)} صورت‌حساب · جمع ${money(sum)} · مانده ${money(rem)}</div><div class="md-scroll"><table class="md-tbl no-page"><thead><tr><th>شماره</th><th>تاریخ</th><th>شرکت</th><th>ماه</th><th>نوع</th><th>تعداد</th><th>جمع</th><th>پرداختی</th><th>مانده</th><th>سررسید</th><th></th></tr></thead><tbody>
                        ${r.items.map(b => `<tr style="${b.status === 'VOID' ? 'opacity:.5;text-decoration:line-through' : ''}"><td><b>${fa(b.no)}</b></td><td>${fa(b.issue_j)}</td><td>${esc(b.company)}</td><td>${esc(b.period_fa)}</td><td>${esc(b.kind_fa)}</td><td>${fa(b.headcount)}</td>
                            <td>${money(b.total)}</td><td>${money(b.paid)}</td><td>${b.remain ? `<b>${money(b.remain)}</b>` : tag('c', 'تسویه')}</td><td>${b.overdue ? tag('r', fa(b.due_j)) : fa(b.due_j)}</td>
                            <td class="whitespace-nowrap"><button class="md-btn o sm" data-pdf="${b.id}" title="مشاهده"><i class="fas fa-file-pdf"></i></button><button class="md-btn o sm" data-dl="${b.id}" title="دانلود"><i class="fas fa-download"></i></button>
                            ${b.status === 'ISSUED' && can('md-bills', 'delete') ? `<button class="md-btn r sm" data-void1="${b.id}" title="ابطال"><i class="fas fa-ban"></i></button>` : ''}</td></tr>`).join('') || `<tr><td colspan="11">${empty('صورت‌حسابی نیست')}</td></tr>`}</tbody></table></div>`;
                    el.querySelectorAll('[data-pdf]').forEach(b => b.onclick = () => dl('bill_pdf', {id: b.dataset.pdf}));
                    el.querySelectorAll('[data-dl]').forEach(b => b.onclick = () => dl('bill_pdf', {id: b.dataset.dl, dl: 1}));
                    el.querySelectorAll('[data-void1]').forEach(b => b.onclick = async () => { const reason = await askText('ابطالِ صورت‌حساب', 'دلیلِ ابطال', 'ابطال', {danger: true}); if (reason === null) return; try { await api('bill_void', {id: b.dataset.void1, reason}); toast('ابطال شد.', 'success'); load(); } catch (e) { toast(e.message, 'error'); } });
                } catch (e) { el.innerHTML = empty(esc(e.message)); }
            };
            body.querySelectorAll('[data-f]').forEach(i => i.onchange = () => { f[i.dataset.f] = i.value; load(); });
            body.querySelector('[data-void]').onchange = load;
            load();
        },
        async cons(body) {
            body.innerHTML = `<div class="md-card"><h3><i class="fas fa-layer-group text-indigo-600"></i>صورت‌حسابِ تجمیعیِ تازه</h3><div class="flex flex-wrap gap-2 items-end">
                <label><span class="md-lbl">ماه</span>${periodSel('p', X.period)}</label><label><span class="md-lbl">نوع</span><select class="md-sel" data-kind>${kindOpts('HEALTH', '—').replace('<option value="">—</option>', '')}</select></label>
                <label><span class="md-lbl">گیرنده (شرکتِ مادر)</span><select class="md-sel" data-to>${coOpts('', '—')}</select></label><label class="flex-1"><span class="md-lbl">عنوان (اختیاری)</span><input class="md-in" data-title placeholder="مثلاً: شرکت‌های گروهِ …"></label></div>
                <div class="mt-2 text-[12px]"><b>شرکت‌ها:</b> <button class="md-btn s sm" data-allc>همه</button> <button class="md-btn s sm" data-kids>گیرنده و زیرمجموعه‌هایش</button></div>
                <div class="grid gap-1 mt-2" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))" data-cos>${(M.companies || []).map(c => `<label class="text-[12px] flex items-center gap-1"><input type="checkbox" value="${c.id}" data-pid="${c.parent_id || ''}">${esc(c.name)}</label>`).join('')}</div>
                ${can('md-bills', 'create') ? '<button class="md-btn p mt-3" data-make><i class="fas fa-file-circle-plus"></i>ساختِ صورت‌حسابِ تجمیعی</button>' : ''}</div>
                <div class="md-card mt-3" data-list>${spin()}</div>`;
            onPer(body, 'p', p => { X.period = p; load(); });
            const boxes = () => [...body.querySelectorAll('[data-cos] input')];
            body.querySelector('[data-allc]').onclick = () => boxes().forEach(b => b.checked = true);
            body.querySelector('[data-kids]').onclick = () => { const to = body.querySelector('[data-to]').value; if (!to) { toast('اول گیرنده را انتخاب کنید.', 'error'); return; } boxes().forEach(b => b.checked = b.value === to || b.dataset.pid === to); };
            const load = async () => {
                const el = body.querySelector('[data-list]');
                try {
                    const r = await api('cbills', {period: X.period});
                    el.innerHTML = `<table class="md-tbl no-page"><thead><tr><th>شماره</th><th>تاریخ</th><th>ماه</th><th>نوع</th><th>عنوان / گیرنده</th><th>صورت‌حساب‌ها</th><th>جمع</th><th></th></tr></thead><tbody>${r.items.map(c => `<tr style="${c.status === 'VOID' ? 'opacity:.5' : ''}"><td>${fa(c.no)}</td><td>${fa(c.issue_j)}</td><td>${esc(c.period_fa)}</td><td>${esc(c.kind_fa)}</td><td>${esc(c.title || c.to)}</td><td>${fa(c.bills)}</td><td>${money(c.total)}</td>
                        <td class="whitespace-nowrap"><button class="md-btn o sm" data-pdf="${c.id}"><i class="fas fa-file-pdf"></i></button>${c.status === 'ISSUED' && can('md-bills', 'delete') ? `<button class="md-btn r sm" data-v="${c.id}"><i class="fas fa-ban"></i></button>` : ''}</td></tr>`).join('') || `<tr><td colspan="8">${empty('در این ماه صورت‌حسابِ تجمیعی نیست')}</td></tr>`}</tbody></table>`;
                    el.querySelectorAll('[data-pdf]').forEach(b => b.onclick = () => dl('cbill_pdf', {id: b.dataset.pdf}));
                    el.querySelectorAll('[data-v]').forEach(b => b.onclick = async () => { const reason = await askText('ابطال', 'دلیل', 'ابطال', {danger: true}); if (reason === null) return; try { await api('cbill_void', {id: b.dataset.v, reason}); load(); } catch (e) { toast(e.message, 'error'); } });
                } catch (e) { el.innerHTML = empty(esc(e.message)); }
            };
            const mk = body.querySelector('[data-make]');
            if (mk) mk.onclick = async () => {
                const ids = boxes().filter(b => b.checked).map(b => +b.value);
                if (!ids.length) { toast('شرکت‌ها را انتخاب کنید.', 'error'); return; }
                busy(mk, true);
                try { const r = await api('cbill_create', {period: X.period, kind: body.querySelector('[data-kind]').value, company_ids: ids, title: body.querySelector('[data-title]').value, to_company_id: body.querySelector('[data-to]').value}); toast('ساخته شد.', 'success'); dl('cbill_pdf', {id: r.id}); load(); }
                catch (e) { toast(e.message, 'error'); }
                busy(mk, false);
            };
            load();
        }
    };

    // ============================ دریافت‌ها و رسیدها ============================
    const PY = {sub: 'pay', f: {company_id: '', payee: ''}, inbox: 'NEW'};
    Treat.pages['md-pay'] = {
        async render(root) {
            await cfg(); await loadCompanies();
            root.innerHTML = shell('md-pay', `
                ${help('راهنمای دریافت‌ها', [
                    'هر دریافت یا «به ما» واریز شده یا شرکت «مستقیم به بیمه‌گر» پرداخته (بیمه‌گر از قراردادِ شرکت پیشنهاد می‌شود). دریافت خودکار به قدیمی‌ترین صورت‌حساب‌های بازِ همان شرکت می‌نشیند، مگر صورت‌حساب‌ها را خودتان انتخاب کنید.',
                    '<b>صندوقِ رسیدها:</b> عکس‌هایی که در گروه‌های تعیین‌شده (تنظیمات ← گروه‌های رسید) برای ربات فرستاده می‌شوند این‌جا می‌آیند؛ «ثبتِ دریافت» بزنید تا با همان رسید ثبت و در پوشه‌ی شرکت بایگانی شود.',
                    '<b>واریز به بیمه‌گر:</b> پولی که ما به بیمه‌گر واریز کرده‌ایم؛ مانده‌ی هر بیمه‌گر = دریافت‌های «به ما» منهای واریزها.',
                ])}
                ${subNav([['pay', 'دریافت‌ها', 'fa-hand-holding-dollar'], ['inbox', 'صندوقِ رسیدها', 'fa-inbox'], ['settle', 'واریز به بیمه‌گر', 'fa-building-columns']], PY.sub)}<div data-body></div>`);
            root.querySelectorAll('[data-sub] [data-k]').forEach(b => b.onclick = () => { PY.sub = b.dataset.k; this.render(root); });
            const body = root.querySelector('[data-body]');
            if (PY.sub === 'pay') return this.pays(body);
            if (PY.sub === 'inbox') return this.inbox(body);
            return this.settle(body);
        },
        async pays(body) {
            const f = PY.f;
            body.innerHTML = `<div class="md-card"><div class="flex flex-wrap gap-2 items-end"><label><span class="md-lbl">شرکت</span><select class="md-sel" data-f="company_id">${coOpts(f.company_id)}</select></label>
                <label><span class="md-lbl">دریافت‌کننده</span><select class="md-sel" data-f="payee">${opts(Object.assign({'': 'همه'}, X.cfg.payees), f.payee)}</select></label><span class="flex-1"></span>
                ${can('md-pay', 'create') ? '<button class="md-btn p" data-add><i class="fas fa-plus"></i>ثبتِ دریافت</button>' : ''}</div></div><div class="md-card mt-3" data-list>${spin()}</div>`;
            const load = async () => {
                const el = body.querySelector('[data-list]');
                try {
                    const r = await api('payments', f);
                    el.innerHTML = `<div class="md-scroll"><table class="md-tbl no-page"><thead><tr><th>تاریخ</th><th>شرکت</th><th>نوع</th><th>مبلغ</th><th>تخصیص</th><th>دریافت‌کننده</th><th>پیگیری</th><th>ثبت</th><th></th></tr></thead><tbody>${r.items.map(p => `<tr><td>${fa(p.pay_j)}</td><td>${esc(p.company)}</td><td>${esc(p.kind_fa)}</td><td><b>${money(p.amount)}</b></td>
                        <td>${p.free ? `${money(p.alloc)} <small class="text-amber-600">(آزاد ${money(p.free)})</small>` : money(p.alloc)}</td><td>${esc(p.payee_fa)}${p.insurer ? `<small class="block text-slate-400">${esc(p.insurer)}</small>` : ''}</td><td>${fa(p.ref_no)}</td><td class="text-[11px]">${esc(p.created_name)}</td>
                        <td class="whitespace-nowrap">${p.has_file ? `<button class="md-btn o sm" onclick="window.open('${API}?action=payment_file&id=${p.id}')"><i class="fas fa-receipt"></i></button>` : ''}${can('md-pay', 'delete') ? `<button class="md-btn r sm" data-v="${p.id}"><i class="fas fa-ban"></i></button>` : ''}</td></tr>`).join('') || `<tr><td colspan="9">${empty('دریافتی ثبت نشده')}</td></tr>`}</tbody></table></div>`;
                    el.querySelectorAll('[data-v]').forEach(b => b.onclick = async () => { const reason = await askText('ابطالِ دریافت', 'دلیل', 'ابطال', {danger: true}); if (reason === null) return; try { await api('payment_void', {id: b.dataset.v, reason}); load(); } catch (e) { toast(e.message, 'error'); } });
                } catch (e) { el.innerHTML = empty(esc(e.message)); }
            };
            body.querySelectorAll('[data-f]').forEach(i => i.onchange = () => { f[i.dataset.f] = i.value; load(); });
            const a = body.querySelector('[data-add]'); if (a) a.onclick = () => this.payForm({}, load);
            load();
        },
        async payForm(pre, done) {
            const m = modal('ثبتِ دریافت', `<div class="md-grid md-g3">
                <label><span class="md-lbl">شرکت</span><select class="md-sel" data-v="company_id">${coOpts(pre.company_id || '', '— انتخاب —')}</select></label>
                <label><span class="md-lbl">نوع</span><select class="md-sel" data-v="kind">${kindOpts('', 'همه (به ترتیبِ قدیمی‌ترین)')}</select></label>
                <label><span class="md-lbl">مبلغ (ریال)</span><input class="md-in money-input" data-v="amount"></label>
                <label><span class="md-lbl">تاریخِ پرداخت</span><input class="md-in" data-jdate data-v="pay_j" value="${esc(fa(X.cfg.today))}"></label>
                <label><span class="md-lbl">دریافت‌کننده</span><select class="md-sel" data-v="payee">${opts(X.cfg.payees, 'US')}</select></label>
                <label><span class="md-lbl">بیمه‌گر (اگر مستقیم)</span><input class="md-in" data-v="insurer" placeholder="خودکار از قرارداد"></label>
                <label><span class="md-lbl">روش</span><input class="md-in" data-v="method" placeholder="کارت به کارت، پایا، چک…"></label>
                <label><span class="md-lbl">شماره‌ی پیگیری</span><input class="md-in" data-v="ref_no" dir="ltr"></label>
                ${pre.inbox_id ? `<div class="md-hint">رسید از صندوق: #${fa(pre.inbox_id)}</div>` : `<label><span class="md-lbl">تصویرِ رسید</span><input type="file" class="md-in" data-file accept=".jpg,.jpeg,.png,.pdf,.webp"></label>`}
                </div><div data-bills class="mt-3"></div><label class="block mt-2"><span class="md-lbl">توضیح</span><input class="md-in" data-v="note" value="${esc(pre.note || '')}"></label>`,
                '<button class="md-btn s" data-x>انصراف</button><button class="md-btn p" data-save><i class="fas fa-floppy-disk"></i>ثبت</button>', {wide: true});
            const loadBills = async () => {
                const cid = m.$('[data-v="company_id"]').value, box = m.$('[data-bills]');
                if (!cid) { box.innerHTML = ''; return; }
                const r = await api('bills', {company_id: cid, state: 'unpaid'});
                box.innerHTML = r.items.length ? `<b class="text-[12px]">صورت‌حساب‌های باز <small class="text-slate-500 font-normal">(تیک نزنید = خودکار به قدیمی‌ترین‌ها)</small></b><div class="grid gap-1 mt-1">${r.items.slice().reverse().map(b => `<label class="text-[12px] flex items-center gap-2"><input type="checkbox" value="${b.id}">${fa(b.no)} · ${esc(b.kind_fa)} ${esc(b.period_fa)} · مانده ${money(b.remain)}</label>`).join('')}</div>` : '<div class="md-hint">صورت‌حسابِ بازی ندارد؛ دریافت آزاد می‌ماند و به صورت‌حسابِ بعدی می‌نشیند.</div>';
            };
            m.$('[data-v="company_id"]').onchange = loadBills; loadBills();
            m.$('[data-save]').onclick = async () => {
                const p = Object.assign({}, pre.inbox_id ? {inbox_id: pre.inbox_id} : {}); m.$$('[data-v]').forEach(i => p[i.dataset.v] = i.value);
                p.bill_ids = m.$$('[data-bills] input:checked').map(c => +c.value);
                const b = m.$('[data-save]'); busy(b, true);
                try {
                    const f = m.$('[data-file]') && m.$('[data-file]').files[0];
                    if (f) p.file_token = (await upload('file_tmp', f)).token;
                    await api('payment_save', {payment: p}); toast('دریافت ثبت شد.', 'success'); m.close(); done && done();
                } catch (e) { toast(e.message, 'error'); }
                busy(b, false);
            };
        },
        async inbox(body) {
            body.innerHTML = `<div class="md-card flex flex-wrap gap-2 items-end"><div class="md-seg" data-st>${[['NEW', 'ثبت‌نشده'], ['USED', 'ثبت‌شده'], ['IGNORED', 'نادیده']].map(([k, t]) => `<button type="button" data-k="${k}" class="${PY.inbox === k ? 'on' : ''}">${t}</button>`).join('')}</div><span class="flex-1"></span>
                ${can('md-pay', 'create') ? '<label class="md-btn o" style="cursor:pointer"><i class="fas fa-upload"></i>بارگذاریِ رسید<input type="file" hidden data-up accept=".jpg,.jpeg,.png,.pdf,.webp" multiple></label>' : ''}</div><div class="mt-3" data-list>${spin()}</div>`;
            body.querySelectorAll('[data-st] [data-k]').forEach(b => b.onclick = () => { PY.inbox = b.dataset.k; this.inbox(body); });
            const up = body.querySelector('[data-up]');
            if (up) up.onchange = async () => { for (const f of up.files) { try { await upload('inbox_upload', f); } catch (e) { toast(e.message, 'error'); } } this.inbox(body); };
            const el = body.querySelector('[data-list]');
            try {
                const r = await api('inbox', {status: PY.inbox});
                el.innerHTML = r.items.length ? `<div class="grid gap-3" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">${r.items.map(x => `<div class="md-card" style="padding:10px">
                    <a href="${API}?action=inbox_file&id=${x.id}" target="_blank">${x.ext === 'pdf' ? '<div class="md-empty" style="padding:30px 0"><i class="fas fa-file-pdf"></i>PDF</div>' : `<img src="${API}?action=inbox_file&id=${x.id}" style="width:100%;height:160px;object-fit:cover;border-radius:12px" loading="lazy" alt="">`}</a>
                    <div class="text-[11.5px] mt-2"><b>${esc(x.chat_title || '')}</b><div class="text-slate-500">${esc(x.sender || '')} · ${fa(x.received_at)}</div>${x.caption ? `<div class="mt-1">${esc(x.caption)}</div>` : ''}</div>
                    <div class="flex gap-1 mt-2">${x.status === 'NEW' && can('md-pay', 'create') ? `<button class="md-btn p sm" data-use="${x.id}"><i class="fas fa-check"></i>ثبتِ دریافت</button>` : ''}${can('md-pay', 'edit') ? (x.status === 'IGNORED' ? `<button class="md-btn s sm" data-res="${x.id}">برگرداندن</button>` : x.status === 'NEW' ? `<button class="md-btn s sm" data-ign="${x.id}">نادیده</button>` : '') : ''}</div></div>`).join('')}</div>` : `<div class="md-card">${empty('رسیدی نیست')}</div>`;
                el.querySelectorAll('[data-use]').forEach(b => b.onclick = () => { const x = r.items.find(i => i.id === +b.dataset.use); this.payForm({inbox_id: x.id, note: x.caption}, () => this.inbox(body)); });
                el.querySelectorAll('[data-ign]').forEach(b => b.onclick = async () => { await api('inbox_ignore', {id: b.dataset.ign}); this.inbox(body); });
                el.querySelectorAll('[data-res]').forEach(b => b.onclick = async () => { await api('inbox_ignore', {id: b.dataset.res, restore: 1}); this.inbox(body); });
            } catch (e) { el.innerHTML = empty(esc(e.message)); }
        },
        async settle(body) {
            body.innerHTML = `<div class="md-card" data-bal>${spin()}</div><div class="md-card mt-3"><div class="flex justify-between mb-2"><h3><i class="fas fa-building-columns text-indigo-600"></i>واریزها به بیمه‌گر</h3>${can('md-pay', 'create') ? '<button class="md-btn p sm" data-add><i class="fas fa-plus"></i>ثبتِ واریز</button>' : ''}</div><div data-list></div></div>`;
            const load = async () => {
                const r = await api('settles');
                body.querySelector('[data-bal]').innerHTML = `<h3><i class="fas fa-scale-balanced text-indigo-600"></i>مانده‌ی هر بیمه‌گر</h3><table class="md-tbl no-page"><thead><tr><th>بیمه‌گر</th><th>دریافت‌های «به ما»</th><th>واریز به بیمه‌گر</th><th>مانده نزدِ ما</th></tr></thead><tbody>${r.balance.map(b => `<tr><td>${esc(b.insurer)}</td><td>${money(b.in)}</td><td>${money(b.out)}</td><td><b>${money(b.balance)}</b></td></tr>`).join('') || `<tr><td colspan="4">${empty('هنوز چیزی نیست')}</td></tr>`}</tbody></table>`;
                body.querySelector('[data-list]').innerHTML = `<table class="md-tbl no-page"><thead><tr><th>تاریخ</th><th>بیمه‌گر</th><th>مبلغ</th><th>دوره</th><th>پیگیری</th><th>توضیح</th><th></th></tr></thead><tbody>${r.items.map(s => `<tr style="${s.status === 'VOID' ? 'opacity:.5' : ''}"><td>${fa(s.pay_j)}</td><td>${esc(s.insurer)}</td><td>${money(s.amount)}</td><td>${s.period_from ? pfa(s.period_from) + (s.period_to && s.period_to !== s.period_from ? ' تا ' + pfa(s.period_to) : '') : ''}</td><td>${fa(s.ref_no)}</td><td>${esc(s.note)}</td>
                    <td class="whitespace-nowrap">${s.has_file ? `<button class="md-btn o sm" onclick="window.open('${API}?action=settle_file&id=${s.id}')"><i class="fas fa-receipt"></i></button>` : ''}${s.status === 'OK' && can('md-pay', 'delete') ? `<button class="md-btn r sm" data-v="${s.id}"><i class="fas fa-ban"></i></button>` : ''}</td></tr>`).join('') || `<tr><td colspan="7">${empty('واریزی ثبت نشده')}</td></tr>`}</tbody></table>`;
                body.querySelectorAll('[data-v]').forEach(b => b.onclick = async () => { if (!(await ask('ابطالِ واریز', 'این واریز ابطال شود؟', {ok: 'ابطال', danger: true}))) return; await api('settle_void', {id: b.dataset.v}); load(); });
            };
            const a = body.querySelector('[data-add]');
            if (a) a.onclick = () => {
                const m = modal('ثبتِ واریز به بیمه‌گر', `<div class="md-grid md-g3"><label><span class="md-lbl">بیمه‌گر</span><input class="md-in" data-v="insurer" value="پاسارگاد"></label><label><span class="md-lbl">نوع</span><select class="md-sel" data-v="kind">${kindOpts('', 'همه')}</select></label>
                    <label><span class="md-lbl">مبلغ</span><input class="md-in money-input" data-v="amount"></label><label><span class="md-lbl">تاریخ</span><input class="md-in" data-jdate data-v="pay_j" value="${esc(fa(X.cfg.today))}"></label>
                    <label><span class="md-lbl">از ماهِ</span>${periodSel('f', X.period)}</label><label><span class="md-lbl">تا ماهِ</span>${periodSel('t', X.period)}</label>
                    <label><span class="md-lbl">شماره‌ی پیگیری</span><input class="md-in" data-v="ref_no" dir="ltr"></label><label><span class="md-lbl">رسید</span><input type="file" class="md-in" data-file accept=".jpg,.jpeg,.png,.pdf"></label></div>
                    <label class="block mt-2"><span class="md-lbl">توضیح</span><input class="md-in" data-v="note"></label>`, '<button class="md-btn s" data-x>انصراف</button><button class="md-btn p" data-save>ثبت</button>', {wide: true});
                m.$('[data-save]').onclick = async () => {
                    const s = {}; m.$$('[data-v]').forEach(i => s[i.dataset.v] = i.value); s.period_from = perVal(m.el, 'f'); s.period_to = perVal(m.el, 't');
                    try { const f = m.$('[data-file]').files[0]; if (f) s.file_token = (await upload('file_tmp', f)).token; await api('settle_save', {settle: s}); toast('ثبت شد.', 'success'); m.close(); load(); } catch (e) { toast(e.message, 'error'); }
                };
            };
            load();
        }
    };

    // ============================ بایگانی و خروجی ============================
    const AR = {sub: 'files', path: '', ex: {type: 'members', cols: null}};
    Treat.pages['md-archive'] = {
        async render(root) {
            await cfg(); await loadCompanies();
            root.innerHTML = shell('md-archive', `
                ${help('راهنمای بایگانی و خروجی', [
                    'بایگانیِ درمان برای هر شرکت و هر ماه یک پوشه دارد: لیست‌ها، ورودی‌های پاسارگاد، سند حقوق و مغایرت‌گیری، صورت‌حساب‌ها و رسیدها. قراردادها، سندهای حقوقِ کامل، صورت‌حساب‌های تجمیعی و رسیدهای ربات پوشه‌های جدا دارند.',
                    '<b>خروجیِ بازه‌ای:</b> نوع (بیمه‌شدگانِ ماه‌به‌ماه، صورت‌حساب‌ها یا دریافت‌ها)، بازه‌ی ماه‌ها، فیلترها و ستون‌ها را انتخاب کنید.',
                    '<b>اکسلِ کلی / شرکت:</b> بیمه‌شدگانِ یک ماه با حق‌بیمه و سهم‌ها (هر شرکت یک شیت)؛ نسخه‌ای هم در بایگانی می‌ماند.',
                ])}
                ${subNav([['files', 'پوشه‌ها', 'fa-folder-tree'], ['export', 'خروجیِ بازه‌ای', 'fa-file-export'], ['count', 'تعدادِ ماهانه', 'fa-table'], ['book', 'اکسلِ کلی / شرکت', 'fa-file-excel']], AR.sub)}<div data-body></div>`);
            root.querySelectorAll('[data-sub] [data-k]').forEach(b => b.onclick = () => { AR.sub = b.dataset.k; this.render(root); });
            const body = root.querySelector('[data-body]');
            return this[AR.sub](body);
        },
        async files(body) {
            body.innerHTML = `<div class="md-card">${spin()}</div>`;
            let r; try { r = await api('archive_ls', {path: AR.path}); } catch (e) { AR.path = ''; body.innerHTML = `<div class="md-card">${empty(esc(e.message))}</div>`; return; }
            const parts = r.path ? r.path.split('/') : [];
            const crumbs = [`<a href="#" data-go="">بایگانیِ درمان و عمر</a>`].concat(parts.map((p, i) => `<a href="#" data-go="${esc(parts.slice(0, i + 1).join('/'))}">${esc(p)}</a>`)).join(' <i class="fas fa-angle-left text-slate-300"></i> ');
            const size = n => n > 1048576 ? fa((n / 1048576).toFixed(1)) + ' MB' : fa(Math.max(1, Math.round(n / 1024))) + ' KB';
            body.innerHTML = `<div class="md-card"><div class="text-[12.5px] font-bold mb-3">${crumbs}</div><div class="md-scroll"><table class="md-tbl no-page"><tbody>${r.items.map(x => `<tr><td>${x.dir ? `<a href="#" data-go="${esc(x.rel)}" class="font-bold"><i class="fas fa-folder text-amber-500 ml-2"></i>${esc(x.name)}</a>` : `<a href="#" data-get="${esc(x.rel)}"><i class="fas ${/\.pdf$/i.test(x.name) ? 'fa-file-pdf text-rose-500' : /\.xlsx?$/i.test(x.name) ? 'fa-file-excel text-emerald-600' : 'fa-file text-slate-400'} ml-2"></i>${esc(x.name)}</a>`}</td><td class="text-slate-500 text-[11px]">${x.dir ? '' : size(x.size)}</td><td class="text-slate-500 text-[11px]">${fa(x.at)}</td></tr>`).join('') || `<tr><td>${empty('خالی')}</td></tr>`}</tbody></table></div></div>`;
            body.querySelectorAll('[data-go]').forEach(a => a.onclick = e => { e.preventDefault(); AR.path = a.dataset.go; this.files(body); });
            body.querySelectorAll('[data-get]').forEach(a => a.onclick = e => { e.preventDefault(); dl('archive_get', {path: a.dataset.get, view: /\.(pdf|jpe?g|png|webp)$/i.test(a.dataset.get) ? 1 : 0}); });
        },
        async export(body) {
            const cat = (await api('export_catalog')).catalog; const cons = await loadContracts();
            const E = AR.ex;
            const paint = () => {
                const c = cat[E.type]; const sel = E.cols || Object.keys(c);
                body.innerHTML = `<div class="md-card"><div class="flex flex-wrap gap-2 items-end">
                    <label><span class="md-lbl">نوعِ خروجی</span><select class="md-sel" data-type>${opts({members: 'بیمه‌شدگان (ماه‌به‌ماه)', bills: 'صورت‌حساب‌ها', payments: 'دریافت‌ها'}, E.type)}</select></label>
                    <label><span class="md-lbl">از ماهِ</span>${periodSel('f', E.from || X.period)}</label><label><span class="md-lbl">تا ماهِ</span>${periodSel('t', E.to || X.period)}</label>
                    <label><span class="md-lbl">شرکت</span><select class="md-sel" data-co>${coOpts(E.company_id)}</select></label>
                    ${E.type !== 'payments' ? `<label><span class="md-lbl">نوعِ بیمه</span><select class="md-sel" data-kind>${kindOpts(E.kind || '')}</select></label>` : ''}
                    ${E.type === 'members' ? `<label><span class="md-lbl">طرح</span><select class="md-sel" data-plan><option value="">همه</option>${cons.flatMap(x => x.plans.map(p => `<option value="${p.id}" ${String(E.plan_id) === String(p.id) ? 'selected' : ''}>${esc((x.title || '') + ' · ' + p.name)}</option>`)).join('')}</select></label>` : ''}</div>
                    <div class="mt-3"><b class="text-[12px]">ستون‌ها</b> <button class="md-btn s sm" data-alls>همه</button> <button class="md-btn s sm" data-none>هیچ</button>
                    <div class="grid gap-1 mt-2" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">${Object.entries(c).map(([k, t]) => `<label class="text-[12px] flex items-center gap-1"><input type="checkbox" data-col="${k}" ${sel.includes(k) ? 'checked' : ''}>${esc(t)}</label>`).join('')}</div></div>
                    ${can('md-archive', 'export') ? '<button class="md-btn p mt-3" data-go><i class="fas fa-file-excel"></i>دانلودِ اکسل</button>' : ''}</div>`;
                body.querySelector('[data-type]').onchange = e => { E.type = e.target.value; E.cols = null; paint(); };
                body.querySelector('[data-alls]').onclick = () => body.querySelectorAll('[data-col]').forEach(x => x.checked = true);
                body.querySelector('[data-none]').onclick = () => body.querySelectorAll('[data-col]').forEach(x => x.checked = false);
                const go = body.querySelector('[data-go]');
                if (go) go.onclick = () => {
                    E.cols = [...body.querySelectorAll('[data-col]:checked')].map(x => x.dataset.col); E.from = perVal(body, 'f'); E.to = perVal(body, 't'); E.company_id = body.querySelector('[data-co]').value;
                    E.kind = body.querySelector('[data-kind]') ? body.querySelector('[data-kind]').value : ''; E.plan_id = body.querySelector('[data-plan]') ? body.querySelector('[data-plan]').value : '';
                    if (!E.cols.length) { toast('دستِ کم یک ستون انتخاب کنید.', 'error'); return; }
                    const q = new URLSearchParams({action: 'export', type: E.type, from: E.from, to: E.to, company_id: E.company_id, kind: E.kind, plan_id: E.plan_id});
                    E.cols.forEach(c2 => q.append('cols[]', c2));
                    window.open(`${API}?${q}`, '_blank');
                };
            };
            paint();
        },
        async count(body) {
            const C = AR.cnt || (AR.cnt = {from: X.period.slice(0, 4) + '/01', to: X.period, kind: 'HEALTH'});
            body.innerHTML = `<div class="md-card flex flex-wrap gap-2 items-end"><label><span class="md-lbl">از ماهِ</span>${periodSel('f', C.from)}</label><label><span class="md-lbl">تا ماهِ</span>${periodSel('t', C.to)}</label>
                <label><span class="md-lbl">نوع</span><select class="md-sel" data-kind>${kindOpts(C.kind, '—').replace('<option value="">—</option>', '')}</select></label><button class="md-btn p" data-go>نمایش</button></div><div class="md-card mt-3" data-list>${spin()}</div>`;
            const load = async () => {
                C.from = perVal(body, 'f'); C.to = perVal(body, 't'); C.kind = body.querySelector('[data-kind]').value;
                const el = body.querySelector('[data-list]');
                try {
                    const r = await api('headcount', C);
                    const tot = r.periods.map((p, i) => r.rows.reduce((s, x) => s + x.months[i], 0));
                    el.innerHTML = `<div class="md-scroll"><table class="md-tbl no-page"><thead><tr><th>شرکت</th>${r.labels.map(l => `<th>${esc(l)}</th>`).join('')}</tr></thead><tbody>${r.rows.map(x => `<tr><td><b>${esc(x.company)}</b></td>${x.months.map(v => `<td>${v ? fa(v) : '<span class="text-slate-300">—</span>'}</td>`).join('')}</tr>`).join('')}
                        <tr style="background:#eef2ff;font-weight:900"><td>جمع</td>${tot.map(v => `<td>${fa(v)}</td>`).join('')}</tr></tbody></table></div>`;
                } catch (e) { el.innerHTML = empty(esc(e.message)); }
            };
            body.querySelector('[data-go]').onclick = load; load();
        },
        async book(body) {
            body.innerHTML = `<div class="md-card"><div class="flex flex-wrap gap-2 items-end"><label><span class="md-lbl">ماه</span>${periodSel('p', X.period)}</label><label><span class="md-lbl">شرکت</span><select class="md-sel" data-co>${coOpts('', 'همه‌ی شرکت‌ها (هر شرکت یک شیت)')}</select></label>
                ${can('md-archive', 'export') ? '<button class="md-btn p" data-go><i class="fas fa-file-excel"></i>دانلود</button>' : ''}</div><div class="md-hint mt-2">بیمه‌شدگانِ آن ماه با طرح، حق‌بیمه، سهمِ شرکت و سهمِ شخص؛ نسخه‌ای هم در بایگانی ذخیره می‌شود.</div></div>`;
            const go = body.querySelector('[data-go]'); if (go) go.onclick = () => dl('company_book', {period: perVal(body, 'p'), company_id: body.querySelector('[data-co]').value});
        }
    };

    // ============================ تنظیماتِ تکمیلی ============================
    Treat.hooks.settings = async el => {
        let c; try { X.cfg = null; c = await cfg(); } catch (e) { el.innerHTML = `<div class="md-card">${empty(esc(e.message))}</div>`; return; }
        const S = c.settings, ro = !can('md-settings', 'edit');
        const t = (k, l, o = {}) => `<label class="${o.full ? 'col-span-full' : ''}"><span class="md-lbl">${l}</span>${o.area ? `<textarea class="md-ta" data-s="${k}" rows="${o.area}" ${ro ? 'readonly' : ''}>${esc(S[k])}</textarea>` : `<input class="md-in" data-s="${k}" value="${esc(S[k])}" ${o.dir ? `dir="${o.dir}"` : ''} ${ro ? 'readonly' : ''}>`}${o.hint ? `<div class="md-hint">${o.hint}</div>` : ''}</label>`;
        const img = (w, l) => `<div class="md-card" style="background:#fbfbff"><b class="text-[12px]">${l}</b><div class="mt-2">${S[w] ? `<img src="${API}?action=settings_image&which=${w}&t=${Date.now()}" style="max-height:120px;max-width:100%;border:1px solid #e2e8f0;border-radius:10px" alt="">` : '<div class="md-hint">تنظیم نشده</div>'}</div>
            ${ro ? '' : `<div class="flex gap-2 mt-2"><label class="md-btn o sm" style="cursor:pointer"><i class="fas fa-upload"></i>انتخابِ تصویر (png/jpg)<input type="file" hidden data-img="${w}" accept=".png,.jpg,.jpeg"></label>${S[w] ? `<button class="md-btn r sm" data-rmimg="${w}">حذف</button>` : ''}</div>`}</div>`;
        el.innerHTML = `
            <div class="md-card"><h3><i class="fas fa-file-invoice text-indigo-600"></i>صورت‌حساب: شماره، سررسید، متن و قالب</h3><div class="md-grid md-g4">
                ${t('no_format', 'الگوی شماره', {dir: 'ltr', hint: '{yyyy} سال، {yy} دو رقمِ سال، {mm} ماه، {n} شماره‌ی ترتیبی (هر سال از ۱)'})}${t('no_pad', 'تعدادِ رقمِ {n}')}${t('due_days', 'سررسید (روز بعد از صدور)')}${t('remind_days', 'یادآوری (روز پیش از سررسید)')}
                ${t('intro_health', 'متنِ آغازِ صورت‌حسابِ درمان', {area: 3, full: 1})}${t('intro_life', 'متنِ آغازِ صورت‌حسابِ عمر', {area: 3, full: 1})}${t('outro', 'متنِ پایانی (درخواستِ پرداخت)', {area: 3, full: 1})}
                ${t('sheba', 'شماره‌ی شبای واریز', {dir: 'ltr'})}${t('account_name', 'به نامِ')}${t('email', 'ایمیلِ ارسالِ فیش', {dir: 'ltr'})}${t('signer', 'امضاکننده / نامِ شرکت')}
                ${t('m_top', 'فاصله از بالا (میلی‌متر، برای سربرگ)')}${t('m_side', 'حاشیه‌ی کناری (میلی‌متر)')}${t('font_size', 'اندازه‌ی قلم')}</div>
                <div class="md-grid md-g2 mt-3">${img('letterhead', 'سربرگ (پشتِ کلِ صفحه‌ی A4)')}${img('signature', 'مهر و امضا')}</div></div>
            <div class="md-card"><h3><i class="fas fa-users-gear text-indigo-600"></i>لیست‌ها و هشدارها</h3><div class="md-grid md-g3">
                <label><span class="md-lbl">ماهِ خروج</span><select class="md-sel" data-s="exit_rounding" ${ro ? 'disabled' : ''}>${opts({same: 'ماهِ تاریخِ خروج حساب نمی‌شود (از ابتدای همان ماه)', next: 'ماهِ تاریخِ خروج هم حساب می‌شود'}, S.exit_rounding)}</select></label>
                ${t('child_max_age', 'سقفِ سنِ فرزند برای هشدار')}<label><span class="md-lbl">پرونده‌ی پرسنل</span><select class="md-sel" data-s="create_persons" ${ro ? 'disabled' : ''}>${opts({1: 'برای بیمه‌شده‌ی اصلیِ تازه پرونده ساخته شود', 0: 'ساخته نشود'}, String(S.create_persons))}</select></label></div></div>
            <div class="md-card"><h3><i class="fas fa-arrow-right-to-bracket text-indigo-600"></i>ثابت‌های فایلِ «ورودی به پاسارگاد»</h3><div class="md-grid md-g4">
                ${t('pas_insurer_first', 'بیمه‌گرِ اول', {dir: 'ltr'})}${t('pas_gold_card', 'کارتِ طلایی دارد؟')}${t('pas_booklet', 'دفترچه‌ی درمان دارد؟')}${t('pas_takafol', 'وضعیتِ تکفل')}${t('pas_status', 'وضعیتِ بیمه‌شده')}${t('pas_warning', 'اعمالِ هشدار')}
                ${t('pas_nationality', 'ملیت', {dir: 'ltr'})}${t('pas_letter_no', 'شماره‌ی نامه‌ی بیمه‌گذار', {dir: 'ltr'})}${t('pas_letter_j', 'تاریخِ نامه‌ی بیمه‌گذار')}${t('pas_cover_code', 'کدِ پوششِ عمر (شیتِ سرمایه)', {dir: 'ltr'})}</div></div>
            <div class="md-card"><h3><i class="fas fa-robot text-indigo-600"></i>گروه‌های رسیدِ ربات</h3><div class="md-hint mb-2">ربات را به گروه اضافه کنید و در گروه «/id» بفرستید تا شناسه را بگوید؛ عکس‌ها/PDFهای آن گروه به «صندوقِ رسیدها» می‌آیند.</div>
                <div data-groups class="space-y-2"></div>${ro ? '' : '<button class="md-btn s sm mt-2" data-addg><i class="fas fa-plus"></i>گروهِ تازه</button>'}
                <label class="flex items-center gap-2 mt-3 text-[12px]"><input type="checkbox" data-s="receipt_ack" ${+S.receipt_ack ? 'checked' : ''} ${ro ? 'disabled' : ''}>پاسخِ «رسید دریافت شد» در گروه</label></div>
            ${ro ? '' : '<div><button class="md-btn p" data-save2><i class="fas fa-floppy-disk"></i>ذخیره‌ی تنظیمات</button></div>'}`;
        const groups = (S.receipt_groups || []).slice();
        const paintG = () => { el.querySelector('[data-groups]').innerHTML = groups.map((g, i) => `<div class="flex gap-2"><input class="md-in" dir="ltr" style="max-width:220px" data-gid="${i}" value="${esc(g.chat_id)}" placeholder="شناسه‌ی گروه" ${ro ? 'readonly' : ''}><input class="md-in" data-gt="${i}" value="${esc(g.title || '')}" placeholder="نام" ${ro ? 'readonly' : ''}>${ro ? '' : `<button class="md-btn r sm" data-gdel="${i}"><i class="fas fa-xmark"></i></button>`}</div>`).join('') || '<div class="md-hint">گروهی تعریف نشده.</div>';
            el.querySelectorAll('[data-gdel]').forEach(b => b.onclick = () => { pullG(); groups.splice(+b.dataset.gdel, 1); paintG(); }); };
        const pullG = () => el.querySelectorAll('[data-gid]').forEach(i => { groups[+i.dataset.gid] = {chat_id: i.value, title: el.querySelector(`[data-gt="${i.dataset.gid}"]`).value}; });
        paintG();
        const ag = el.querySelector('[data-addg]'); if (ag) ag.onclick = () => { pullG(); groups.push({chat_id: '', title: ''}); paintG(); };
        el.querySelectorAll('[data-img]').forEach(i => i.onchange = async () => { try { await upload('settings_upload', i.files[0], {which: i.dataset.img}); toast('ذخیره شد.', 'success'); Treat.hooks.settings(el); } catch (e) { toast(e.message, 'error'); } });
        el.querySelectorAll('[data-rmimg]').forEach(b => b.onclick = async () => { await api('settings_upload', {which: b.dataset.rmimg, remove: 1}); Treat.hooks.settings(el); });
        const sv = el.querySelector('[data-save2]');
        if (sv) sv.onclick = async () => {
            pullG();
            const o = {receipt_groups: groups.filter(g => g.chat_id)};
            const numeric = ['no_pad', 'due_days', 'remind_days', 'child_max_age', 'm_top', 'm_side', 'font_size', 'sheba', 'pas_letter_no', 'pas_letter_j', 'pas_gold_card', 'pas_booklet', 'pas_takafol', 'pas_status', 'pas_warning'];
            el.querySelectorAll('[data-s]').forEach(i => o[i.dataset.s] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : (numeric.includes(i.dataset.s) ? en(i.value) : i.value));
            busy(sv, true);
            try { await api('settings_save', {settings: o}); X.cfg = null; toast('تنظیمات ذخیره شد.', 'success'); } catch (e) { toast(e.message, 'error'); }
            busy(sv, false);
        };
    };
})();
