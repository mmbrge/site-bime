// فایل: work-log.js
// «اطلاعات پرسنلی»: کارکرد من (هر کاربر برای خودش)، کارکرد پرسنل (مدیر)، مرخصی و تنظیماتِ کارکرد.
// تقویمِ شمسی، ساعتِ ورود/خروجِ خودکار از پنل (قابلِ ویرایش)، حال‌وهوای روز، شرحِ کار، کارهای ثبت‌شده در پنل، گزارش و خروجیِ اکسل.
(function () {
    'use strict';
    const API = 'api/work_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : alert(m));
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WD_HEAD = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];                 // شنبه اول
    const DOW_FA = {6: 'شنبه', 0: 'یکشنبه', 1: 'دوشنبه', 2: 'سه‌شنبه', 3: 'چهارشنبه', 4: 'پنجشنبه', 5: 'جمعه'};
    const MOODS = {1: ['😞', 'روزِ بد'], 2: ['😕', 'نه چندان خوب'], 3: ['😐', 'معمولی'], 4: ['🙂', 'خوب'], 5: ['😄', 'عالی']};
    const TYPES = {WORK: ['حضوری', 'fa-building', '#0284c7'], REMOTE: ['دورکاری', 'fa-house-laptop', '#7c3aed'], MISSION: ['مأموریت', 'fa-plane-departure', '#d97706'],
                   LEAVE: ['مرخصی', 'fa-umbrella-beach', '#059669'], OFF: ['تعطیل', 'fa-mug-hot', '#64748b'], ABSENT: ['غیبت', 'fa-user-xmark', '#e11d48']};
    const LV_ST = {APPROVED: ['تأییدشده', 'bg-emerald-100 text-emerald-700'], PENDING: ['در انتظارِ تأیید', 'bg-amber-100 text-amber-700'], REJECTED: ['ردشده', 'bg-rose-100 text-rose-700']};
    const ACT_IC = {login: ['fa-right-to-bracket', '#0284c7'], logout: ['fa-right-from-bracket', '#64748b'], seen: ['fa-eye', '#94a3b8'], create: ['fa-plus', '#059669'],
                    edit: ['fa-pen', '#7c3aed'], delete: ['fa-trash', '#e11d48'], export: ['fa-file-export', '#d97706']};
    const dur = m => { m = Math.round(Number(m) || 0); const neg = m < 0; m = Math.abs(m); return (neg ? '-' : '') + fa(Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0')); };

    // ---------------- تقویمِ شمسی ----------------
    function g2j(gy, gm, gd) {
        const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        const gy2 = gm > 2 ? gy + 1 : gy;
        let days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
        let jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
        jy += 4 * Math.floor(days / 1461); days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        const jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
        const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
        return [jy, jm, jd];
    }
    function j2g(jy, jm, jd) {
        jy += 1595;
        let days = -355668 + 365 * jy + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
        let gy = 400 * Math.floor(days / 146097); days %= 146097;
        if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
        gy += 4 * Math.floor(days / 1461); days %= 1461;
        if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        let gd = days + 1;
        const sal = [0, 31, (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        let gm = 0;
        for (gm = 1; gm <= 12 && gd > sal[gm]; gm++) gd -= sal[gm];
        return [gy, gm, gd];
    }
    const monthLen = (jy, jm) => jm <= 6 ? 31 : jm <= 11 ? 30 : (g2j(...j2g(jy, 12, 30))[2] === 30 ? 30 : 29);
    const jstr = (y, m, d) => `${y}/${String(m).padStart(2, '0')}/${String(d).padStart(2, '0')}`;
    const parseJ = s => { const m = en(s || '').match(/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/); return m ? [+m[1], +m[2], +m[3]] : null; };
    const dowOf = (y, m, d) => new Date(...(([gy, gm, gd]) => [gy, gm - 1, gd])(j2g(y, m, d))).getDay();
    const todayJ = () => { const n = new Date((window.IrTime ? IrTime.now() : Date.now()) + 12600000); return g2j(n.getUTCFullYear(), n.getUTCMonth() + 1, n.getUTCDate()); };
    const longDate = s => { const p = parseJ(s); return p ? `${DOW_FA[dowOf(...p)]} ${fa(p[2])} ${MONTHS[p[1] - 1]} ${fa(p[0])}` : fa(s); };

    async function api(action, body = {}) {
        try {
            const res = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))});
            return await res.json();
        } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور'}; }
    }

    // ---------------- انتخابگرِ تاریخِ شمسی (برای خانه‌های تاریخ) ----------------
    let jpEl = null;
    function jpClose() { if (jpEl) { jpEl.remove(); jpEl = null; } }
    function jpOpen(input) {
        jpClose();
        const cur = parseJ(input.value) || todayJ();
        let y = cur[0], m = cur[1];
        jpEl = document.createElement('div');
        jpEl.className = 'wk-jp';
        const r = input.getBoundingClientRect();
        jpEl.style.top = (window.scrollY + r.bottom + 4) + 'px';
        jpEl.style.left = Math.max(8, window.scrollX + r.left + r.width - 252) + 'px';
        const draw = () => {
            const first = (dowOf(y, m, 1) + 1) % 7, n = monthLen(y, m), t = todayJ(), sel = parseJ(input.value);
            let h = `<div class="wk-jp-h"><button type="button" data-n="1">‹</button><b>${MONTHS[m - 1]} ${fa(y)}</b><button type="button" data-n="-1">›</button></div><div class="wk-jp-g">`;
            h += WD_HEAD.map(w => `<i>${w}</i>`).join('') + '<span></span>'.repeat(first);
            for (let d = 1; d <= n; d++) {
                const isT = t[0] === y && t[1] === m && t[2] === d, isS = sel && sel[0] === y && sel[1] === m && sel[2] === d;
                h += `<button type="button" data-d="${d}" class="${isT ? 't' : ''} ${isS ? 's' : ''} ${dowOf(y, m, d) === 5 ? 'f' : ''}">${fa(d)}</button>`;
            }
            jpEl.innerHTML = h + '</div>';
        };
        jpEl.addEventListener('mousedown', e => e.preventDefault());
        jpEl.addEventListener('click', e => {
            const b = e.target.closest('button'); if (!b) return;
            if (b.dataset.n) { m -= +b.dataset.n; if (m < 1) { m = 12; y--; } if (m > 12) { m = 1; y++; } draw(); return; }
            input.value = fa(jstr(y, m, +b.dataset.d));
            input.dispatchEvent(new Event('input', {bubbles: true}));
            jpClose();
        });
        draw();
        document.body.appendChild(jpEl);
    }
    document.addEventListener('focusin', e => { if (e.target.matches && e.target.matches('input[data-jdate]')) jpOpen(e.target); });
    document.addEventListener('mousedown', e => { if (jpEl && !jpEl.contains(e.target) && !(e.target.matches && e.target.matches('input[data-jdate]'))) jpClose(); });

    // ---------------- استایل ----------------
    const css = `
    .wk-hero{position:relative;overflow:hidden;border-radius:26px;padding:22px 24px;color:#fff;background:linear-gradient(125deg,#0f172a,#312e81 45%,#0e7490);box-shadow:0 20px 50px -25px rgba(49,46,129,.6)}
    .wk-hero::before{content:'';position:absolute;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,rgba(56,189,248,.35),transparent 70%);left:-80px;top:-140px;pointer-events:none}
    .wk-hero::after{content:'';position:absolute;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(167,139,250,.35),transparent 70%);right:20%;bottom:-170px;pointer-events:none}
    .wk-hero > *{position:relative;z-index:1}
    .wk-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:5px 12px;font-size:11px;font-weight:800}
    .wk-hbtn{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:14px;padding:8px 14px;font-size:12px;font-weight:900;color:#fff;transition:.2s}
    .wk-hbtn:hover{background:rgba(255,255,255,.26)} .wk-hbtn.solid{background:#fff;color:#312e81}
    .wk-kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:10px} @media(min-width:768px){.wk-kpis{grid-template-columns:repeat(3,1fr)}} @media(min-width:1200px){.wk-kpis{grid-template-columns:repeat(6,1fr)}}
    .wk-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:12px 14px;position:relative;overflow:hidden}
    .wk-kpi p{font-size:10.5px;font-weight:800;color:#64748b} .wk-kpi b{display:block;font-size:19px;font-weight:900;color:#0f172a;margin-top:2px} .wk-kpi small{font-size:10px;color:#94a3b8;font-weight:700}
    .wk-kpi i.ic{position:absolute;left:12px;top:12px;width:30px;height:30px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:13px}
    .wk-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:16px;box-shadow:0 10px 30px -22px rgba(15,23,42,.25)}
    .wk-card h3{font-size:13px;font-weight:900;color:#1e293b;display:flex;align-items:center;gap:8px}
    .wk-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
    .wk-cal .wh{font-size:10.5px;font-weight:900;color:#94a3b8;text-align:center;padding:4px 0}
    .wk-day{position:relative;min-height:74px;border-radius:14px;border:1px solid #eef2f7;background:#fbfdff;padding:6px 7px;text-align:right;cursor:pointer;transition:.15s;display:flex;flex-direction:column;justify-content:space-between}
    .wk-day:hover{border-color:#a5b4fc;transform:translateY(-1px);box-shadow:0 8px 18px -12px rgba(79,70,229,.5)}
    .wk-day .n{font-size:13px;font-weight:900;color:#334155} .wk-day .mo{position:absolute;left:6px;top:4px;font-size:17px;line-height:1}
    .wk-day .h{font-size:10.5px;font-weight:900;color:#0f766e} .wk-day .tg{font-size:9px;font-weight:900;border-radius:999px;padding:1px 6px;display:inline-block;margin-top:2px}
    .wk-day.off{background:#f8fafc} .wk-day.off .n{color:#e11d48} .wk-day.today{border:2px solid #6366f1} .wk-day.sel{background:linear-gradient(160deg,#eef2ff,#ecfeff);border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
    .wk-day.future{opacity:.75} .wk-day.blank{visibility:hidden}
    .wk-main{display:grid;gap:16px} @media(min-width:1280px){.wk-main{grid-template-columns:1.45fr 1fr;align-items:start}}
    .wk-bar{height:100%;display:flex;align-items:flex-end;gap:3px} .wk-bar > div{flex:1;border-radius:6px 6px 2px 2px;min-height:2px;position:relative}
    .wk-seg{display:inline-flex;flex-wrap:wrap;gap:4px;background:#f1f5f9;border-radius:12px;padding:3px}
    .wk-seg button{padding:5px 10px;border-radius:9px;font-size:11px;font-weight:800;color:#475569} .wk-seg button.on{background:#fff;color:#4338ca;box-shadow:0 2px 8px -3px rgba(15,23,42,.3)}
    .wk-mood{display:flex;gap:6px} .wk-mood button{flex:1;border:1px solid #e2e8f0;border-radius:14px;padding:6px 2px;font-size:22px;line-height:1.1;background:#fff;transition:.15s;filter:grayscale(.65);opacity:.75}
    .wk-mood button small{display:block;font-size:9px;font-weight:800;color:#64748b;margin-top:2px} .wk-mood button.on{filter:none;opacity:1;border-color:#6366f1;background:#eef2ff;transform:scale(1.04)}
    .wk-in{width:100%;border:1px solid #e2e8f0;border-radius:12px;padding:7px 10px;font-size:12px;background:#fff} .wk-in:focus{outline:none;border-color:#818cf8;box-shadow:0 0 0 3px rgba(129,140,248,.2)}
    .wk-lbl{font-size:10.5px;font-weight:800;color:#64748b;margin-bottom:3px;display:block}
    .wk-tl{position:relative;padding-right:18px} .wk-tl::before{content:'';position:absolute;right:6px;top:4px;bottom:4px;width:2px;background:#e2e8f0;border-radius:2px}
    .wk-tl > div{position:relative;padding:4px 0 6px} .wk-tl > div::before{content:'';position:absolute;right:-16px;top:9px;width:10px;height:10px;border-radius:50%;background:var(--c,#94a3b8);box-shadow:0 0 0 3px #fff}
    .wk-chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:900;border-radius:999px;padding:2px 8px}
    .wk-jp{position:absolute;z-index:99999999;width:252px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 20px 40px -15px rgba(15,23,42,.35);padding:10px;direction:rtl}
    .wk-jp-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px} .wk-jp-h b{font-size:12px} .wk-jp-h button{width:28px;height:28px;border-radius:9px;background:#f1f5f9;font-weight:900}
    .wk-jp-g{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center} .wk-jp-g i{font-style:normal;font-size:10px;color:#94a3b8;font-weight:800}
    .wk-jp-g button{height:30px;border-radius:9px;font-size:12px;font-weight:700} .wk-jp-g button:hover{background:#eef2ff} .wk-jp-g button.t{border:1.5px solid #6366f1} .wk-jp-g button.s{background:#4f46e5;color:#fff} .wk-jp-g button.f{color:#e11d48}
    .wk-tbl{width:100%;font-size:11.5px} .wk-tbl th{background:#f8fafc;color:#64748b;font-weight:800;font-size:10.5px;padding:8px 6px;text-align:right;position:sticky;top:0} .wk-tbl td{padding:7px 6px;border-top:1px solid #f1f5f9;vertical-align:top}
    .wk-tbl tr.off td{background:#fafafa;color:#94a3b8}
    `;
    if (!document.getElementById('wk-css')) { const st = document.createElement('style'); st.id = 'wk-css'; st.textContent = css; document.head.appendChild(st); }

    // =====================================================================
    //  نمای کارکردِ یک نفر (برای «کارکرد من»، و برای مدیر داخلِ «کارکرد پرسنل»)
    // =====================================================================
    function PersonView(root, opts) {
        const S = {root, uid: opts.userId || 0, staff: !!opts.staff, jy: 0, jm: 0, data: null, sel: null, day: null, timeline: [], editTasks: [], draft: null};
        const t0 = todayJ(); S.jy = opts.jy || t0[0]; S.jm = opts.jm || t0[1];

        async function load(keepSel) {
            root.innerHTML = root.innerHTML || '<p class="text-center text-xs text-slate-400 py-10"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ بارگذاری…</p>';
            const d = await api('month', {jy: S.jy, jm: S.jm, user_id: S.uid || undefined});
            if (!d.ok) { root.innerHTML = `<p class="text-center text-sm text-rose-600 py-10">${esc(d.error || 'خطا')}</p>`; return; }
            S.data = d;
            if (!keepSel || !S.sel || !S.sel.startsWith(`${S.jy}/${String(S.jm).padStart(2, '0')}`)) {
                const t = todayJ();
                S.sel = (t[0] === S.jy && t[1] === S.jm) ? jstr(...t) : jstr(S.jy, S.jm, 1);
            }
            render();
            openDay(S.sel);
        }
        const canEdit = () => S.data && (S.data.is_me || S.data.can_manage);

        function render() {
            const d = S.data, sm = d.summary, b = d.balance_now, u = d.user || {};
            const t = todayJ(), todayStr = jstr(...t);
            const today = d.days.find(x => x.jdate === todayStr);
            const leaveDayMin = Math.round((+d.settings.work_leave_day_hours || 8) * 60);
            const leaveFa = m => { m = Math.round(m || 0); const neg = m < 0; m = Math.abs(m); const dd = Math.floor(m / leaveDayMin), r = m % leaveDayMin; return (neg ? 'منفی ' : '') + ([dd ? fa(dd) + ' روز' : '', r ? dur(r) + ' ساعت' : ''].filter(Boolean).join(' و ') || '۰'); };
            const moodAvg = sm.mood_avg ? MOODS[Math.round(sm.mood_avg)][0] : '—';
            const dismissed = (() => { try { return localStorage.getItem('wk-open-dismiss-' + (u.id || '')) === '1'; } catch (e) { return false; } })();
            root.innerHTML = `
            <div class="space-y-4">
              <div class="wk-hero">
                <div class="flex flex-wrap items-start gap-4">
                  <div class="flex-1 min-w-[220px]">
                    <p class="text-[11px] opacity-80 font-bold">${S.staff ? 'کارکردِ همکار' : 'کارکرد من'} · ${esc(longDate(todayStr))}</p>
                    <h2 class="text-xl font-black mt-1">${esc(u.name || '')}</h2>
                    <div class="flex flex-wrap gap-2 mt-3">
                      ${today && today.check_in ? `<span class="wk-pill"><i class="fas fa-right-to-bracket"></i>ورودِ امروز ${fa(today.check_in)}</span>
                        <span class="wk-pill">${today.live ? '<span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>در پنل · ' : '<i class="fas fa-right-from-bracket"></i>خروج ' + fa(today.check_out || '—') + ' · '}${dur(today.worked)} حضور</span>`
                        : '<span class="wk-pill"><i class="fas fa-moon"></i>امروز هنوز حضوری ثبت نشده</span>'}
                      <span class="wk-pill"><i class="fas fa-umbrella-beach"></i>مانده‌ی مرخصی: ${esc(b.balance_fa)}</span>
                      ${b.pending ? `<span class="wk-pill" style="background:rgba(251,191,36,.25)"><i class="fas fa-hourglass-half"></i>${leaveFa(b.pending)} در انتظارِ تأیید</span>` : ''}
                    </div>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    ${canEdit() ? `<button type="button" class="wk-hbtn solid" data-a="leave"><i class="fas fa-umbrella-beach ml-1"></i>ثبتِ مرخصی</button>` : ''}
                    <button type="button" class="wk-hbtn" data-a="report"><i class="fas fa-chart-column ml-1"></i>گزارش و خروجی</button>
                    <button type="button" class="wk-hbtn" data-a="export-month"><i class="fas fa-file-excel ml-1"></i>اکسلِ ${MONTHS[S.jm - 1]}</button>
                  </div>
                </div>
              </div>
              ${d.is_me && !b.opening_set && !dismissed ? `
              <div class="wk-card border-indigo-200" style="background:linear-gradient(120deg,#eef2ff,#f0fdfa)" data-box="opening">
                <div class="flex flex-wrap items-end gap-3">
                  <div class="flex-1 min-w-[220px]"><h3><i class="fas fa-flag-checkered text-indigo-600"></i>شروعِ محاسبه‌ی مرخصی</h3>
                    <p class="text-[11px] text-slate-600 mt-1 leading-6">اگر تا ابتدای <b>${MONTHS[t[1] - 1]} ${fa(t[0])}</b> از مرخصیِ سالانه‌تان مانده‌ای داشته‌اید وارد کنید؛ از این ماه به بعد خودکار حساب می‌شود (هر ماه ${leaveFa(b.accrual)} اضافه و هر مرخصی کم می‌شود؛ هر ${fa(d.settings.work_leave_day_hours)} ساعت مرخصیِ ساعتی = یک روز).</p></div>
                  <label><span class="wk-lbl">روز</span><input class="wk-in w-24" data-f="op-days" inputmode="decimal" placeholder="مثلاً ۵"></label>
                  <label><span class="wk-lbl">ساعت (مثل ۳:۳۰)</span><input class="wk-in w-28" data-f="op-hours" dir="ltr" placeholder="0:00"></label>
                  <button type="button" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black px-4 py-2.5 rounded-xl" data-a="op-save">ثبت</button>
                  <button type="button" class="text-xs font-bold text-slate-500 px-2 py-2.5" data-a="op-skip">فعلاً نه</button>
                </div>
              </div>` : ''}
              <div class="wk-kpis">
                ${kpi('روزهای حضور', fa(sm.present), `از ${fa(sm.work_days)} روزِ کاری`, 'fa-calendar-check', '#0284c7')}
                ${kpi('ساعتِ کارکرد', dur(sm.worked), 'در ' + MONTHS[S.jm - 1], 'fa-clock', '#4f46e5')}
                ${kpi(sm.over >= 0 ? 'اضافه‌کار' : 'کسرِ کار', dur(Math.abs(sm.over)), `نسبت به ${fa(d.settings.work_day_hours)} ساعت در روز`, sm.over >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down', sm.over >= 0 ? '#059669' : '#e11d48')}
                ${kpi('مرخصیِ این ماه', leaveFa(sm.leave_min), sm.leave_days ? fa(sm.leave_days) + ' روزِ کامل' : 'روزانه و ساعتی', 'fa-umbrella-beach', '#0d9488')}
                ${kpi('مانده‌ی مرخصی', esc(b.balance_fa), b.opening_set ? 'از ' + fa(b.start) : 'محاسبه از ' + fa(b.start), 'fa-piggy-bank', '#d97706')}
                ${kpi('حال‌وهوای ماه', `<span style="font-size:24px">${moodAvg}</span>`, sm.mood_n ? `میانگین از ${fa(sm.mood_n)} روز` : 'هنوز امتیازی نداده‌اید', 'fa-face-smile', '#db2777')}
              </div>
              <div class="wk-main">
                <div class="wk-card">
                  <div class="flex flex-wrap items-center gap-2 mb-3">
                    <h3><i class="fas fa-calendar-days text-indigo-600"></i>تقویمِ ${MONTHS[S.jm - 1]} ${fa(S.jy)}</h3>
                    <div class="mr-auto flex items-center gap-1.5">
                      <button type="button" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 font-black" data-a="prev" title="ماهِ قبل">‹</button>
                      <select class="wk-in" style="width:auto;padding-top:6px;padding-bottom:6px" data-f="m">${MONTHS.map((n, i) => `<option value="${i + 1}" ${i + 1 === S.jm ? 'selected' : ''}>${n}</option>`).join('')}</select>
                      <select class="wk-in" style="width:auto;padding-top:6px;padding-bottom:6px" data-f="y">${Array.from({length: 7}, (_, i) => t[0] - 4 + i).map(y => `<option value="${y}" ${y === S.jy ? 'selected' : ''}>${fa(y)}</option>`).join('')}</select>
                      <button type="button" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 font-black" data-a="next" title="ماهِ بعد">›</button>
                      <button type="button" class="text-[11px] font-black text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-xl px-3 py-2" data-a="today">امروز</button>
                    </div>
                  </div>
                  <div class="wk-cal">${WD_HEAD.map(w => `<div class="wh">${w}</div>`).join('')}${calCells()}</div>
                  <div class="flex flex-wrap gap-3 mt-3 text-[10px] font-bold text-slate-500">
                    ${Object.values(MOODS).map(m => `<span>${m[0]} ${m[1]}</span>`).join('')}
                    <span><span class="wk-chip bg-emerald-100 text-emerald-700">مرخصی</span></span><span><span class="wk-chip bg-violet-100 text-violet-700">دورکاری</span></span>
                  </div>
                </div>
                <div class="wk-card" data-box="day"></div>
              </div>
              <div class="grid gap-4 lg:grid-cols-2">
                <div class="wk-card" data-box="leaves">${leavesHtml(leaveFa)}</div>
                <div class="wk-card">
                  <h3><i class="fas fa-chart-simple text-indigo-600"></i>ساعتِ حضورِ روزهای ${MONTHS[S.jm - 1]}</h3>
                  <div class="mt-3 bg-slate-50 rounded-2xl p-2 pt-5 relative" style="height:150px">
                    <div class="border-t border-dashed border-emerald-300" style="position:absolute;left:8px;right:8px;bottom:${8 + 122 * Math.min(1, (+d.settings.work_day_hours || 8) / 12)}px"><span class="text-[9px] font-bold text-emerald-600" style="position:absolute;left:0;top:-15px">${fa(d.settings.work_day_hours)} ساعت</span></div>
                    <div class="wk-bar">${d.days.map(x => `<div title="${esc(fa(x.jdate))}: ${x.worked ? dur(x.worked) : '—'}${x.mood ? ' ' + MOODS[x.mood][0] : ''}" style="height:${Math.min(100, x.worked / 7.2)}%;background:${x.off ? '#cbd5e1' : x.mood && x.mood <= 2 ? '#fb7185' : x.mood >= 4 ? '#34d399' : '#818cf8'}"></div>`).join('')}</div>
                  </div>
                  <div class="grid grid-cols-3 gap-2 mt-3 text-center">
                    <div class="bg-slate-50 rounded-xl p-2"><p class="text-[10px] text-slate-500 font-bold">دورکاری</p><b class="text-sm">${fa(sm.remote)} روز</b></div>
                    <div class="bg-slate-50 rounded-xl p-2"><p class="text-[10px] text-slate-500 font-bold">مأموریت</p><b class="text-sm">${fa(sm.mission)} روز</b></div>
                    <div class="bg-slate-50 rounded-xl p-2"><p class="text-[10px] text-slate-500 font-bold">کارهای ثبت‌شده در پنل</p><b class="text-sm">${fa(sm.acts)}</b></div>
                  </div>
                </div>
              </div>
            </div>`;
            bind();
        }
        function kpi(label, val, sub, ic, color) {
            return `<div class="wk-kpi"><i class="fas ${ic} ic" style="background:${color}1a;color:${color}"></i><p>${label}</p><b>${val}</b><small>${sub}</small></div>`;
        }
        function calCells() {
            const d = S.data, first = (dowOf(S.jy, S.jm, 1) + 1) % 7, t = jstr(...todayJ());
            let h = '<div class="wk-day blank"></div>'.repeat(first);
            d.days.forEach(x => {
                const p = parseJ(x.jdate), ty = TYPES[x.day_type];
                const lv = x.leaves.length ? (x.leaves.some(l => l.status === 'PENDING') ? '<span class="tg bg-amber-100 text-amber-700">مرخصی؟</span>'
                    : `<span class="tg bg-emerald-100 text-emerald-700">${x.leaves.some(l => l.kind === 'DAY') ? 'مرخصی' : 'ساعتی ' + dur(x.leave_min)}</span>`) : '';
                const tyTag = ty && ['REMOTE', 'MISSION', 'ABSENT'].includes(x.day_type) ? `<span class="tg" style="background:${ty[2]}1a;color:${ty[2]}">${ty[0]}</span>` : '';
                h += `<div class="wk-day ${x.off ? 'off' : ''} ${x.jdate === t ? 'today' : ''} ${x.jdate === S.sel ? 'sel' : ''} ${x.jdate > t ? 'future' : ''}" data-day="${x.jdate}">
                    <div><span class="n">${fa(p[2])}</span>${x.mood ? `<span class="mo">${MOODS[x.mood][0]}</span>` : ''}</div>
                    <div>${x.worked ? `<div class="h"><i class="far fa-clock ml-0.5"></i>${dur(x.worked)}${x.live ? ' <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>' : ''}</div>` : (x.off ? '<div class="text-[9px] text-rose-400 font-bold">تعطیل</div>' : '')}${lv}${tyTag}</div>
                </div>`;
            });
            return h;
        }
        function leavesHtml(leaveFa) {
            const d = S.data, b = d.balance_now, ed = canEdit();
            return `
                <div class="flex flex-wrap items-center gap-2"><h3><i class="fas fa-umbrella-beach text-emerald-600"></i>مرخصی</h3>
                  ${ed ? `<button type="button" class="mr-auto text-[11px] font-bold text-indigo-700 hover:underline" data-a="op-edit"><i class="fas fa-sliders ml-1"></i>مانده‌ی اولیه</button>` : ''}</div>
                <div class="grid grid-cols-3 gap-2 mt-3 text-center">
                  <div class="rounded-2xl p-3" style="background:linear-gradient(140deg,#ecfdf5,#d1fae5)"><p class="text-[10px] font-bold text-emerald-700">مانده تا امروز</p><b class="text-sm text-emerald-900">${esc(b.balance_fa)}</b></div>
                  <div class="rounded-2xl p-3 bg-slate-50"><p class="text-[10px] font-bold text-slate-500">سهمِ هر ماه</p><b class="text-sm">${esc(b.accrual_fa)}</b></div>
                  <div class="rounded-2xl p-3 bg-slate-50"><p class="text-[10px] font-bold text-slate-500">استفاده از ${fa(b.start)}</p><b class="text-sm">${esc(b.used_fa)}</b></div>
                </div>
                <p class="text-[10.5px] text-slate-500 mt-2">مانده‌ی آخرِ ${MONTHS[S.jm - 1]} ${fa(S.jy)}: <b>${esc(d.balance.balance_fa)}</b>${b.opening_set ? ` · مانده‌ی اولیه ${leaveFa(b.opening_minutes)} از ${fa(b.start)}` : ''}</p>
                ${ed ? `<div class="mt-3 border border-emerald-100 rounded-2xl p-3 bg-emerald-50/40" data-box="leave-form">
                  <div class="flex flex-wrap items-center gap-2 mb-2"><b class="text-[12px] text-emerald-800">ثبتِ مرخصیِ تازه</b>
                    <div class="wk-seg mr-auto"><button type="button" data-lk="DAY" class="on">روزانه</button><button type="button" data-lk="HOUR">ساعتی</button></div></div>
                  <div class="grid grid-cols-2 gap-2">
                    <label><span class="wk-lbl" data-lbl="from">از تاریخ</span><input class="wk-in" data-f="lv-from" data-jdate dir="ltr" placeholder="۱۴۰۵/۰۷/۱۲" value="${fa(S.sel || '')}"></label>
                    <label data-only="DAY"><span class="wk-lbl">تا تاریخ</span><input class="wk-in" data-f="lv-to" data-jdate dir="ltr" placeholder="همان روز" value="${fa(S.sel || '')}"></label>
                    <label data-only="HOUR" class="hidden"><span class="wk-lbl">از ساعت</span><input type="time" class="wk-in" data-f="lv-tf" value="${d.settings.work_start || '08:00'}"></label>
                    <label data-only="HOUR" class="hidden"><span class="wk-lbl">تا ساعت</span><input type="time" class="wk-in" data-f="lv-tt" value="10:00"></label>
                    <label class="col-span-2"><span class="wk-lbl">علت (اختیاری)</span><input class="wk-in" data-f="lv-reason" placeholder="مثلاً کارِ شخصی، بیماری، …"></label>
                  </div>
                  <div class="flex items-center gap-2 mt-2"><p class="text-[10.5px] text-emerald-800 flex-1" data-f="lv-calc"></p>
                    <button type="button" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black px-4 py-2 rounded-xl" data-a="lv-save">ثبتِ مرخصی</button></div>
                  ${d.settings.work_leave_approval === '1' && d.is_me ? '<p class="text-[10px] text-amber-700 mt-1"><i class="fas fa-circle-info ml-1"></i>مرخصی بعد از تأییدِ مدیر قطعی می‌شود (تا آن موقع از مانده کم شده و «در انتظار» است).</p>' : ''}
                </div>` : ''}
                <div class="mt-3 space-y-1.5 max-h-64 overflow-y-auto">
                  ${d.leaves.length ? d.leaves.map(L => `<div class="flex flex-wrap items-center gap-2 border border-slate-100 rounded-xl px-3 py-2 text-[11px]">
                      <span class="wk-chip ${L.kind === 'HOUR' ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700'}">${L.kind === 'HOUR' ? 'ساعتی' : 'روزانه'}</span>
                      <b>${L.kind === 'HOUR' ? fa(L.from) + ' · ' + fa(L.time_from) + ' تا ' + fa(L.time_to) : fa(L.from) + (L.to !== L.from ? ' تا ' + fa(L.to) : '')}</b>
                      <span class="text-slate-500">${esc(L.minutes_fa)}</span>
                      <span class="wk-chip ${LV_ST[L.status][1]}">${LV_ST[L.status][0]}</span>
                      ${L.reason ? `<span class="text-slate-400 truncate max-w-[160px]" title="${esc(L.reason)}">${esc(L.reason)}</span>` : ''}
                      ${L.decide_note ? `<span class="text-rose-500" title="نظرِ مدیر">${esc(L.decide_note)}</span>` : ''}
                      ${S.data.can_manage && L.status === 'PENDING' ? `<span class="mr-auto flex gap-1"><button type="button" class="text-[10px] font-black bg-emerald-600 text-white rounded-lg px-2 py-1" data-lv-ok="${L.id}">تأیید</button><button type="button" class="text-[10px] font-black bg-rose-50 text-rose-600 rounded-lg px-2 py-1" data-lv-no="${L.id}">رد</button></span>` : ''}
                      ${ed && (L.status !== 'APPROVED' || S.data.can_manage || S.data.settings.work_leave_approval !== '1') ? `<button type="button" class="${S.data.can_manage && L.status === 'PENDING' ? '' : 'mr-auto'} text-rose-400 hover:text-rose-600" title="حذفِ این مرخصی" data-lv-del="${L.id}"><i class="fas fa-trash"></i></button>` : ''}
                    </div>`).join('') : '<p class="text-center text-[11px] text-slate-400 py-4">در این ماه مرخصی ثبت نشده است.</p>'}
                </div>`;
        }

        function bind() {
            root.querySelectorAll('[data-day]').forEach(el => el.addEventListener('click', () => openDay(el.dataset.day)));
            const on = (a, fn) => root.querySelectorAll(`[data-a="${a}"]`).forEach(el => el.addEventListener('click', fn));
            on('prev', () => { S.jm--; if (S.jm < 1) { S.jm = 12; S.jy--; } load(); });
            on('next', () => { S.jm++; if (S.jm > 12) { S.jm = 1; S.jy++; } load(); });
            on('today', () => { const t = todayJ(); S.jy = t[0]; S.jm = t[1]; S.sel = jstr(...t); load(true); });
            root.querySelector('[data-f="m"]').addEventListener('change', e => { S.jm = +e.target.value; load(); });
            root.querySelector('[data-f="y"]').addEventListener('change', e => { S.jy = +e.target.value; load(); });
            on('report', () => openReport({userId: S.uid, name: S.data.user && S.data.user.name}));
            on('export-month', () => { const n = monthLen(S.jy, S.jm); exportUrl({from: jstr(S.jy, S.jm, 1), to: jstr(S.jy, S.jm, n), user_id: S.uid || ''}); });
            on('leave', () => { const f = root.querySelector('[data-box="leave-form"]'); if (f) { f.scrollIntoView({behavior: 'smooth', block: 'center'}); f.classList.add('ring-2', 'ring-emerald-300'); setTimeout(() => f.classList.remove('ring-2', 'ring-emerald-300'), 1600); } });
            on('op-skip', () => { try { localStorage.setItem('wk-open-dismiss-' + (S.data.user || {}).id, '1'); } catch (e) {} root.querySelector('[data-box="opening"]').remove(); });
            on('op-save', () => saveOpening(root.querySelector('[data-f="op-days"]').value, root.querySelector('[data-f="op-hours"]').value));
            on('op-edit', openOpeningDialog);
            // فرمِ مرخصی
            const lf = root.querySelector('[data-box="leave-form"]');
            if (lf) {
                let kind = 'DAY';
                lf.querySelectorAll('[data-lk]').forEach(btn => btn.addEventListener('click', () => {
                    kind = btn.dataset.lk;
                    lf.querySelectorAll('[data-lk]').forEach(b2 => b2.classList.toggle('on', b2 === btn));
                    lf.querySelectorAll('[data-only]').forEach(x => x.classList.toggle('hidden', x.dataset.only !== kind));
                    lf.querySelector('[data-lbl="from"]').textContent = kind === 'DAY' ? 'از تاریخ' : 'تاریخ';
                    calc();
                }));
                const g = f => lf.querySelector(`[data-f="${f}"]`);
                const calc = () => {
                    const s = S.data.settings, dayMin = Math.round((+s.work_leave_day_hours || 8) * 60);
                    let m = 0;
                    if (kind === 'HOUR') { const [a, b] = [g('lv-tf').value, g('lv-tt').value].map(x => x ? x.split(':').map(Number) : null); if (a && b) m = Math.max(0, (b[0] * 60 + b[1]) - (a[0] * 60 + a[1])); }
                    else {
                        const f = parseJ(g('lv-from').value), t = parseJ(g('lv-to').value) || f;
                        if (f && t) {
                            const off = String(s.work_offdays || '').split(',').filter(Boolean).map(Number), hol = String(s.work_holidays || '').split(/\s+/);
                            let [y, mo, dd] = f, guard = 0;
                            while (guard++ < 400) {
                                const js = jstr(y, mo, dd);
                                if (!off.includes(dowOf(y, mo, dd)) && !hol.includes(js)) m += dayMin;
                                if (js >= jstr(...t)) break;
                                dd++; if (dd > monthLen(y, mo)) { dd = 1; mo++; if (mo > 12) { mo = 1; y++; } }
                            }
                        }
                    }
                    const left = S.data.balance_now.balance - m;
                    const lfa = x => { x = Math.round(x); const neg = x < 0; x = Math.abs(x); const a = Math.floor(x / dayMin), r = x % dayMin; return (neg ? 'منفی ' : '') + ([a ? fa(a) + ' روز' : '', r ? dur(r) + ' ساعت' : ''].filter(Boolean).join(' و ') || '۰'); };
                    g('lv-calc').innerHTML = m ? `این مرخصی <b>${lfa(m)}</b> است؛ مانده بعد از آن: <b class="${left < 0 ? 'text-rose-600' : ''}">${lfa(left)}</b>` : '';
                };
                lf.addEventListener('input', calc); calc();
                lf.querySelector('[data-a="lv-save"]').addEventListener('click', async () => {
                    const body = {kind, date_from: en(g('lv-from').value), date_to: en(g('lv-to').value), time_from: g('lv-tf').value, time_to: g('lv-tt').value, reason: g('lv-reason').value, user_id: S.uid || undefined};
                    const r = await api('leave_add', body);
                    if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                    toast(r.status === 'PENDING' ? `مرخصی (${r.minutes_fa}) ثبت شد و در انتظارِ تأییدِ مدیر است.` : `مرخصی (${r.minutes_fa}) ثبت شد.`, 'success');
                    load(true);
                });
            }
            root.querySelectorAll('[data-lv-del]').forEach(b => b.addEventListener('click', async () => {
                if (window.uiConfirm && !(await uiConfirm('حذفِ مرخصی', 'این مرخصی حذف شود؟', {ok: 'حذف', danger: true}))) return;
                const r = await api('leave_delete', {id: +b.dataset.lvDel, user_id: S.uid || undefined});
                if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                toast('مرخصی حذف شد.', 'success'); load(true);
            }));
            root.querySelectorAll('[data-lv-ok],[data-lv-no]').forEach(b => b.addEventListener('click', async () => {
                const ok = !!b.dataset.lvOk;
                let note = '';
                if (!ok && window.uiPrompt) { note = await uiPrompt('ردِ مرخصی', 'علتِ رد (اختیاری):', ''); if (note === null) return; }
                const r = await api('leave_decide', {id: +(b.dataset.lvOk || b.dataset.lvNo), status: ok ? 'APPROVED' : 'REJECTED', note});
                if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                toast(ok ? 'مرخصی تأیید شد.' : 'مرخصی رد شد.', 'success'); load(true);
            }));
        }

        async function saveOpening(days, hours) {
            const r = await api('profile_save', {days: en(days || '0'), hours: en(hours || '0'), user_id: S.uid || undefined});
            if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
            toast('مانده‌ی اولیه ثبت شد؛ از این ماه به بعد خودکار حساب می‌شود.', 'success');
            load(true);
        }
        function openOpeningDialog() {
            const b = S.data.balance_now, t = todayJ();
            const box = document.createElement('div');
            box.className = 'modal-overlay active';
            box.innerHTML = `<div class="modal-content w-full max-w-md p-6 relative">
                <button type="button" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl" data-x><i class="fas fa-times"></i></button>
                <h3 class="font-black text-base mb-1"><i class="fas fa-flag-checkered text-indigo-600 ml-1"></i>مانده‌ی مرخصیِ اولیه</h3>
                <p class="text-[11px] text-slate-500 mb-3 leading-6">مانده‌ی مرخصی تا ابتدای ماهی که انتخاب می‌کنید؛ از همان ماه به بعد سهمِ ماهانه اضافه و مرخصی‌ها کم می‌شوند.${b.opening_set ? ` (فعلاً: ${esc(fa(b.start))})` : ''}</p>
                <div class="grid grid-cols-2 gap-2">
                  <label><span class="wk-lbl">ماه</span><select class="wk-in" data-f="m">${MONTHS.map((n, i) => `<option value="${i + 1}" ${i + 1 === t[1] ? 'selected' : ''}>${n}</option>`).join('')}</select></label>
                  <label><span class="wk-lbl">سال</span><input class="wk-in" data-f="y" dir="ltr" value="${fa(t[0])}"></label>
                  <label><span class="wk-lbl">روز</span><input class="wk-in" data-f="d" inputmode="decimal" placeholder="۰"></label>
                  <label><span class="wk-lbl">ساعت (مثل ۳:۳۰)</span><input class="wk-in" data-f="h" dir="ltr" placeholder="0:00"></label>
                </div>
                <button type="button" class="w-full mt-4 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-black py-2.5 rounded-xl" data-ok>ثبت</button></div>`;
            document.body.appendChild(box);
            const close = () => box.remove();
            box.querySelector('[data-x]').onclick = close;
            box.addEventListener('click', e => { if (e.target === box) close(); });
            box.querySelector('[data-ok]').onclick = async () => {
                const q = f => box.querySelector(`[data-f="${f}"]`).value;
                const r = await api('profile_save', {days: en(q('d') || '0'), hours: en(q('h') || '0'), jy: +en(q('y')), jm: +q('m'), user_id: S.uid || undefined});
                if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                toast('مانده‌ی اولیه ثبت شد.', 'success'); close(); load(true);
            };
        }

        // ---------------- پنلِ یک روز ----------------
        async function openDay(jd) {
            S.sel = jd;
            root.querySelectorAll('.wk-day').forEach(el => el.classList.toggle('sel', el.dataset.day === jd));
            const box = root.querySelector('[data-box="day"]');
            if (!box) return;
            box.innerHTML = '<p class="text-center text-xs text-slate-400 py-10"><i class="fas fa-spinner fa-spin ml-1"></i></p>';
            const r = await api('day', {date: jd, user_id: S.uid || undefined});
            if (!r.ok) { box.innerHTML = `<p class="text-rose-600 text-xs">${esc(r.error || 'خطا')}</p>`; return; }
            S.day = r.day; S.timeline = r.timeline || [];
            S.editTasks = (r.day.tasks || []).map(t => Object.assign({}, t));
            drawDay();
        }
        function drawDay() {
            const box = root.querySelector('[data-box="day"]'), D = S.day, ed = canEdit();
            const ty = D.day_type || (D.off ? 'OFF' : 'WORK');
            const ro = ed ? '' : 'disabled';
            box.innerHTML = `
                <div class="flex flex-wrap items-center gap-2">
                  <h3><i class="fas fa-calendar-day text-indigo-600"></i>${esc(longDate(D.jdate))}</h3>
                  ${D.off ? '<span class="wk-chip bg-rose-50 text-rose-600">تعطیل</span>' : ''}
                  ${D.live ? '<span class="wk-chip bg-emerald-100 text-emerald-700"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>الان در پنل</span>' : ''}
                </div>
                <div class="mt-3 rounded-2xl p-3 text-[11px]" style="background:linear-gradient(120deg,#f8fafc,#eef2ff)">
                  <p class="font-black text-slate-600 mb-1"><i class="fas fa-robot text-indigo-500 ml-1"></i>ثبتِ خودکارِ سیستم</p>
                  <p class="text-slate-600">اولین ورود / فعالیت: <b>${D.auto_in ? fa(D.auto_in) : '—'}</b> · آخرین خروج / فعالیت: <b>${D.auto_out ? fa(D.auto_out) : '—'}</b></p>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-3 items-end">
                  <label><span class="wk-lbl">ساعتِ ورود ${D.edited_in ? '<span class="text-amber-600">(دستی)</span>' : '<span class="text-emerald-600">(خودکار)</span>'}</span><input type="time" class="wk-in" data-f="in" value="${D.check_in || ''}" ${ro}></label>
                  <label><span class="wk-lbl">ساعتِ خروج ${D.edited_out ? '<span class="text-amber-600">(دستی)</span>' : '<span class="text-emerald-600">(خودکار)</span>'}</span><input type="time" class="wk-in" data-f="out" value="${D.live ? '' : (D.check_out || '')}" placeholder="${D.live ? 'هنوز در پنل' : ''}" ${ro}></label>
                  <div class="rounded-xl bg-indigo-50 text-center py-1.5"><p class="text-[10px] font-bold text-indigo-600">مدتِ کار</p><b class="text-base text-indigo-900" data-f="dur">${dur(D.worked)}</b></div>
                </div>
                ${ed && (D.edited_in || D.edited_out) ? '<button type="button" class="text-[10.5px] font-bold text-indigo-600 hover:underline mt-1" data-a="auto"><i class="fas fa-rotate-left ml-1"></i>برگشت به ساعتِ خودکارِ سیستم</button>' : ''}
                <div class="mt-3"><span class="wk-lbl">نوعِ روز</span><div class="wk-seg" data-f="type">${Object.entries(TYPES).map(([k, v]) => `<button type="button" data-v="${k}" class="${k === ty ? 'on' : ''}" ${ro}><i class="fas ${v[1]} ml-1" style="color:${v[2]}"></i>${v[0]}</button>`).join('')}</div></div>
                <div class="mt-3"><span class="wk-lbl">امروز چطور بود؟</span><div class="wk-mood" data-f="mood">${Object.entries(MOODS).map(([k, v]) => `<button type="button" data-v="${k}" class="${+k === D.mood ? 'on' : ''}" ${ro}>${v[0]}<small>${v[1]}</small></button>`).join('')}</div></div>
                <label class="block mt-3"><span class="wk-lbl">توضیحاتِ روز</span><textarea class="wk-in" rows="2" data-f="note" placeholder="هر نکته‌ای درباره‌ی امروز…" ${ro}>${esc(D.note || '')}</textarea></label>
                <div class="mt-3">
                  <div class="flex items-center gap-2"><span class="wk-lbl" style="margin-bottom:0">شرحِ کارهای امروز</span>
                    ${ed ? `<button type="button" class="mr-auto text-[10.5px] font-bold text-indigo-600 hover:underline" data-a="from-tl" title="کارهایی که سیستم ثبت کرده به فهرست اضافه شود"><i class="fas fa-wand-magic-sparkles ml-1"></i>از کارهای پنل</button>
                    <button type="button" class="text-[10.5px] font-bold text-emerald-700 hover:underline" data-a="add-task"><i class="fas fa-plus ml-1"></i>افزودن</button>` : ''}</div>
                  <div class="space-y-1.5 mt-1.5" data-f="tasks"></div>
                </div>
                ${D.leaves.length ? `<div class="mt-3 flex flex-wrap gap-1.5">${D.leaves.map(L => `<span class="wk-chip ${LV_ST[L.status][1]}"><i class="fas fa-umbrella-beach"></i>${L.kind === 'HOUR' ? 'مرخصیِ ساعتی ' + fa(L.from) + ' تا ' + fa(L.to) : 'مرخصیِ روزانه'} · ${LV_ST[L.status][0]}</span>`).join('')}</div>` : ''}
                ${ed ? `<button type="button" class="w-full mt-3 text-white text-sm font-black py-2.5 rounded-xl" style="background:linear-gradient(120deg,#4f46e5,#0ea5e9)" data-a="save-day"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی این روز</button>` : ''}
                <div class="mt-4">
                  <p class="wk-lbl"><i class="fas fa-timeline ml-1"></i>کارهای ثبت‌شده در پنل (${fa(S.timeline.length)})</p>
                  ${S.timeline.length ? `<div class="wk-tl max-h-64 overflow-y-auto pl-1 mt-2">${S.timeline.map(e => { const ic = ACT_IC[e.kind] || ACT_IC.edit; return `<div style="--c:${ic[1]}"><div class="flex items-start gap-2 text-[11px]"><b class="text-slate-500 w-10 shrink-0" dir="ltr">${fa(e.t)}</b><span class="flex-1"><i class="fas ${ic[0]} ml-1" style="color:${ic[1]}"></i>${esc(e.label)}${e.ref ? `<span class="block text-[10px] text-slate-400">${esc(fa(e.ref))}</span>` : ''}</span></div></div>`; }).join('')}</div>`
                    : '<p class="text-[11px] text-slate-400 mt-1">در این روز فعالیتی در پنل ثبت نشده است.</p>'}
                </div>`;
            drawTasks();
            if (!ed) return;
            const g = f => box.querySelector(`[data-f="${f}"]`);
            const upd = () => {
                const a = g('in').value, b = g('out').value || (D.live ? new Date((window.IrTime ? IrTime.now() : Date.now()) + 12600000).toISOString().slice(11, 16) : '');
                if (a && b) { const [h1, m1] = a.split(':').map(Number), [h2, m2] = b.split(':').map(Number); g('dur').textContent = dur(Math.max(0, h2 * 60 + m2 - h1 * 60 - m1)); }
                else g('dur').textContent = dur(0);
            };
            g('in').addEventListener('input', upd); g('out').addEventListener('input', upd);
            g('type').querySelectorAll('button').forEach(b => b.addEventListener('click', () => g('type').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b))));
            g('mood').querySelectorAll('button').forEach(b => b.addEventListener('click', () => g('mood').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b && !x.classList.contains('on')))));
            const on = (a, fn) => { const el = box.querySelector(`[data-a="${a}"]`); if (el) el.addEventListener('click', fn); };
            on('auto', () => { g('in').value = D.auto_in || ''; g('out').value = D.live ? '' : (D.auto_out || ''); D.edited_in = D.edited_out = false; S._auto = true; upd(); });
            on('add-task', () => { collectTasks(); S.editTasks.push({from: '', to: '', title: ''}); drawTasks(); const ins = box.querySelectorAll('[data-t="title"]'); if (ins.length) ins[ins.length - 1].focus(); });
            on('from-tl', () => {
                collectTasks();
                const have = new Set(S.editTasks.map(t => t.title));
                S.timeline.filter(e => !['login', 'logout', 'seen'].includes(e.kind)).forEach(e => {
                    const title = e.label + (e.ref ? ' (' + e.ref + ')' : '');
                    if (!have.has(title)) { S.editTasks.push({from: e.t, to: '', title}); have.add(title); }
                });
                drawTasks();
            });
            on('save-day', async () => {
                collectTasks();
                const sel = (f) => { const b = g(f).querySelector('button.on'); return b ? b.dataset.v : ''; };
                const auto = !!S._auto; S._auto = false;
                const inV = g('in').value, outV = g('out').value;
                const body = {date: D.jdate, user_id: S.uid || undefined, day_type: sel('type') || 'WORK', mood: +sel('mood') || 0, note: g('note').value, tasks: S.editTasks,
                              check_in: (auto && inV === (D.auto_in || '')) || (!D.edited_in && inV === (D.auto_in || '')) ? '' : inV,
                              check_out: (auto && outV === (D.auto_out || '')) || (!D.edited_out && (outV === (D.auto_out || '') || (D.live && !outV))) ? '' : outV};
                const r = await api('save_day', body);
                if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
                toast('روز ذخیره شد.', 'success');
                const keep = S.sel; await load(true); if (keep) openDay(keep);
            });
        }
        function drawTasks() {
            const box = root.querySelector('[data-box="day"] [data-f="tasks"]'), ed = canEdit();
            if (!box) return;
            box.innerHTML = S.editTasks.length ? S.editTasks.map((t, i) => `<div class="flex items-center gap-1.5" data-ti="${i}">
                <input type="time" class="wk-in" style="width:96px;flex:0 0 96px;padding-left:6px;padding-right:6px" data-t="from" value="${esc(t.from || '')}" ${ed ? '' : 'disabled'}>
                <input type="time" class="wk-in" style="width:96px;flex:0 0 96px;padding-left:6px;padding-right:6px" data-t="to" value="${esc(t.to || '')}" ${ed ? '' : 'disabled'}>
                <input class="wk-in flex-1" data-t="title" value="${esc(t.title || '')}" placeholder="چه کاری انجام شد؟" ${ed ? '' : 'disabled'}>
                ${ed ? `<button type="button" class="text-rose-400 hover:text-rose-600 px-1" data-tdel="${i}" title="حذف"><i class="fas fa-xmark"></i></button>` : ''}</div>`).join('')
                : '<p class="text-[11px] text-slate-400">هنوز شرحِ کاری ننوشته‌اید.</p>';
            box.querySelectorAll('[data-tdel]').forEach(b => b.addEventListener('click', () => { collectTasks(); S.editTasks.splice(+b.dataset.tdel, 1); drawTasks(); }));
        }
        function collectTasks() {
            const box = root.querySelector('[data-box="day"] [data-f="tasks"]');
            if (!box) return;
            S.editTasks = [...box.querySelectorAll('[data-ti]')].map(r => ({from: r.querySelector('[data-t="from"]').value, to: r.querySelector('[data-t="to"]').value, title: r.querySelector('[data-t="title"]').value.trim()}));
        }
        load();
        return {reload: () => load(true), setUser: (id) => { S.uid = id; load(); }};
    }

    // ---------------- گزارشِ هر بازه + خروجیِ اکسل ----------------
    function exportUrl(q) {
        const p = new URLSearchParams(Object.assign({action: 'export'}, q));
        window.open(API + '?' + p.toString(), '_blank');
    }
    function openReport(o) {
        const t = todayJ();
        const box = document.createElement('div');
        box.className = 'modal-overlay active';
        box.innerHTML = `<div class="modal-content creq-wide-modal p-0 relative overflow-hidden" style="max-width:1100px;width:calc(100% - 24px);max-height:92vh;display:flex;flex-direction:column">
            <div class="px-6 py-4 text-white" style="background:linear-gradient(120deg,#312e81,#0e7490)">
              <button type="button" class="absolute top-4 left-4 text-white/80 hover:text-white text-xl" data-x><i class="fas fa-times"></i></button>
              <h3 class="font-black"><i class="fas fa-chart-column ml-1"></i>گزارشِ کارکرد${o.all ? 'ِ همه‌ی پرسنل' : (o.name ? ' · ' + esc(o.name) : '')}</h3>
              <p class="text-[11px] opacity-80">هر بازه‌ای که بخواهید: ماهانه، سالانه یا دلخواه؛ با خروجیِ اکسل</p></div>
            <div class="px-6 py-3 border-b border-slate-100 flex flex-wrap items-end gap-2">
              <div class="wk-seg" data-f="pre">
                <button type="button" data-p="m" class="on">این ماه</button><button type="button" data-p="pm">ماهِ قبل</button><button type="button" data-p="q">سه ماهِ اخیر</button><button type="button" data-p="y">امسال</button><button type="button" data-p="py">سالِ قبل</button></div>
              <label><span class="wk-lbl">از</span><input class="wk-in w-32" data-f="from" data-jdate dir="ltr"></label>
              <label><span class="wk-lbl">تا</span><input class="wk-in w-32" data-f="to" data-jdate dir="ltr"></label>
              <button type="button" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black px-4 py-2.5 rounded-xl" data-a="run"><i class="fas fa-magnifying-glass ml-1"></i>نمایش</button>
              <button type="button" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black px-4 py-2.5 rounded-xl" data-a="xls"><i class="fas fa-file-excel ml-1"></i>خروجیِ اکسل</button>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-4 no-count" data-f="out"></div></div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.querySelector('[data-x]').onclick = close;
        box.addEventListener('click', e => { if (e.target === box) close(); });
        const g = f => box.querySelector(`[data-f="${f}"]`);
        const setRange = p => {
            let y = t[0], m = t[1], a, b;
            if (p === 'm') { a = jstr(y, m, 1); b = jstr(y, m, monthLen(y, m)); }
            if (p === 'pm') { m--; if (m < 1) { m = 12; y--; } a = jstr(y, m, 1); b = jstr(y, m, monthLen(y, m)); }
            if (p === 'q') { let m2 = m - 2, y2 = y; if (m2 < 1) { m2 += 12; y2--; } a = jstr(y2, m2, 1); b = jstr(y, m, monthLen(y, m)); }
            if (p === 'y') { a = jstr(y, 1, 1); b = jstr(y, 12, monthLen(y, 12)); }
            if (p === 'py') { a = jstr(y - 1, 1, 1); b = jstr(y - 1, 12, monthLen(y - 1, 12)); }
            g('from').value = fa(a); g('to').value = fa(b);
            g('pre').querySelectorAll('button').forEach(x => x.classList.toggle('on', x.dataset.p === p));
        };
        g('pre').querySelectorAll('button').forEach(b => b.addEventListener('click', () => { setRange(b.dataset.p); run(); }));
        const q = () => ({from: en(g('from').value), to: en(g('to').value), user_id: o.userId || '', all: o.all ? 1 : ''});
        async function run() {
            g('out').innerHTML = '<p class="text-center text-xs text-slate-400 py-10"><i class="fas fa-spinner fa-spin ml-1"></i></p>';
            const r = await api('report', q());
            if (!r.ok) { g('out').innerHTML = `<p class="text-rose-600 text-sm">${esc(r.error || 'خطا')}</p>`; return; }
            if (r.all) {
                g('out').innerHTML = `<table class="wk-tbl"><thead><tr><th>نام</th><th>حضور</th><th>ساعتِ کارکرد</th><th>اضافه/کسر</th><th>مرخصی</th><th>دورکاری</th><th>مأموریت</th><th>حال</th><th>کارها در پنل</th><th>مانده‌ی مرخصی</th></tr></thead><tbody>
                    ${r.rows.map(x => `<tr><td class="font-bold">${esc(x.name)}</td><td>${fa(x.present)} از ${fa(x.work_days)}</td><td>${fa(x.worked_fa)}</td><td class="${x.over < 0 ? 'text-rose-600' : 'text-emerald-700'}">${fa(x.over_fa)}</td><td>${esc(fa(x.leave_fa))}</td><td>${fa(x.remote)}</td><td>${fa(x.mission)}</td><td>${x.mood_avg ? MOODS[Math.round(x.mood_avg)][0] : '—'}</td><td>${fa(x.acts)}</td><td>${esc(fa(x.balance_fa))}</td></tr>`).join('')}</tbody></table>`;
                return;
            }
            const s = r.summary;
            g('out').innerHTML = `<div class="grid grid-cols-2 md:grid-cols-6 gap-2 mb-3 text-center">
                ${[['حضور', fa(s.present) + ' روز'], ['روزهای کاری', fa(s.work_days)], ['کارکرد', fa(s.worked_fa)], ['اضافه/کسر کار', fa(s.over_fa)], ['مرخصی', esc(fa(s.leave_fa))], ['مانده‌ی مرخصی', esc(r.balance.balance_fa)]].map(([a, b]) => `<div class="bg-slate-50 rounded-xl p-2"><p class="text-[10px] text-slate-500 font-bold">${a}</p><b class="text-sm">${b}</b></div>`).join('')}</div>
                <table class="wk-tbl"><thead><tr><th>تاریخ</th><th>نوع</th><th>ورود</th><th>خروج</th><th>کارکرد</th><th>مرخصی</th><th>حال</th><th>کارها</th><th>شرح و توضیحات</th></tr></thead><tbody>
                ${r.days.map(x => `<tr class="${x.off ? 'off' : ''}"><td class="whitespace-nowrap font-bold">${esc(longDate(x.jdate))}</td><td>${TYPES[x.day_type] ? TYPES[x.day_type][0] : (x.off ? 'تعطیل' : '')}</td>
                    <td>${x.check_in ? fa(x.check_in) : ''}</td><td>${x.check_out ? fa(x.check_out) : ''}</td><td>${x.worked ? dur(x.worked) : ''}</td><td>${x.leave_min ? dur(x.leave_min) : ''}</td>
                    <td class="text-base">${x.mood ? MOODS[x.mood][0] : ''}</td><td>${x.acts ? fa(x.acts) : ''}</td>
                    <td class="text-[11px]">${x.tasks.map(t => `<div>• ${t.from ? fa(t.from) + ' ' : ''}${esc(t.title)}</div>`).join('')}${x.note ? `<div class="text-slate-500">${esc(x.note)}</div>` : ''}</td></tr>`).join('')}</tbody></table>`;
        }
        box.querySelector('[data-a="run"]').onclick = run;
        box.querySelector('[data-a="xls"]').onclick = () => exportUrl(q());
        setRange('m'); run();
    }

    // ---------------- تنظیماتِ کارکرد و مرخصی (مدیر) ----------------
    async function renderSettings(el) {
        if (!el) return;
        const r = await api('settings_get');
        if (!r.ok) { el.innerHTML = `<p class="text-rose-600 text-xs">${esc(r.error || 'خطا')}</p>`; return; }
        const s = r.settings, off = String(s.work_offdays || '').split(',').filter(Boolean).map(Number), ro = r.can_manage ? '' : 'disabled';
        el.innerHTML = `
            <div class="grid gap-3 md:grid-cols-3">
              <label><span class="wk-lbl">مرخصیِ ماهانه (روز)</span><input class="wk-in" data-k="work_leave_days_month" value="${fa(s.work_leave_days_month)}" inputmode="decimal" ${ro}></label>
              <label><span class="wk-lbl">هر روزِ مرخصی = چند ساعتِ مرخصیِ ساعتی</span><input class="wk-in" data-k="work_leave_day_hours" value="${fa(s.work_leave_day_hours)}" inputmode="decimal" ${ro}></label>
              <label><span class="wk-lbl">ساعتِ کارِ موظفِ هر روز</span><input class="wk-in" data-k="work_day_hours" value="${fa(s.work_day_hours)}" inputmode="decimal" ${ro}></label>
              <label><span class="wk-lbl">شروعِ ساعتِ کاری</span><input type="time" class="wk-in" data-k="work_start" value="${s.work_start}" ${ro}></label>
              <label><span class="wk-lbl">پایانِ ساعتِ کاری</span><input type="time" class="wk-in" data-k="work_end" value="${s.work_end}" ${ro}></label>
              <label class="flex items-center gap-2 mt-5 text-xs font-bold text-slate-700"><input type="checkbox" class="accent-indigo-600 w-4 h-4" data-k="work_leave_approval" ${s.work_leave_approval === '1' ? 'checked' : ''} ${ro}>مرخصی‌ها نیازِ به تأییدِ مدیر دارند</label>
            </div>
            <div class="mt-3"><span class="wk-lbl">روزهای تعطیلِ هفته</span>
              <div class="flex flex-wrap gap-2">${[6, 0, 1, 2, 3, 4, 5].map(d => `<label class="flex items-center gap-1.5 text-xs font-bold bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5"><input type="checkbox" class="accent-indigo-600" data-off="${d}" ${off.includes(d) ? 'checked' : ''} ${ro}>${DOW_FA[d]}</label>`).join('')}</div></div>
            <label class="block mt-3"><span class="wk-lbl">تعطیلاتِ رسمی (هر تاریخ در یک خط یا با فاصله، مثل ۱۴۰۵/۰۱/۰۱)</span>
              <textarea class="wk-in" rows="3" dir="ltr" data-k="work_holidays" placeholder="1405/01/01  1405/01/02 …" ${ro}>${esc(fa(String(s.work_holidays || '').replace(/\n/g, '  ')))}</textarea></label>
            <p class="text-[10.5px] text-slate-500 mt-2 leading-6"><i class="fas fa-circle-info text-indigo-500 ml-1"></i>هر ماه مقدارِ «مرخصیِ ماهانه» به مانده‌ی هر نفر اضافه می‌شود. مرخصیِ ساعتی بر اساسِ ساعت کم می‌شود و هر ${fa(s.work_leave_day_hours)} ساعت = یک روز. مرخصیِ روزانه فقط روزهای کاری را می‌شمارد (تعطیلاتِ بالا حساب نمی‌شوند).</p>
            ${r.can_manage ? '<button type="button" class="mt-3 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black px-5 py-2.5 rounded-xl" data-a="save"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تنظیماتِ کارکرد و مرخصی</button>' : ''}`;
        const btn = el.querySelector('[data-a="save"]');
        if (btn) btn.onclick = async () => {
            const o = {};
            el.querySelectorAll('[data-k]').forEach(i => { o[i.dataset.k] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : en(i.value); });
            o.work_offdays = [...el.querySelectorAll('[data-off]:checked')].map(i => +i.dataset.off);
            const res = await api('settings_save', {settings: o});
            if (!res.ok) { toast(res.error || 'خطا', 'error'); return; }
            toast('تنظیماتِ کارکرد و مرخصی ذخیره شد.', 'success');
            renderSettings(el);
        };
    }

    // =====================================================================
    //  «کارکرد پرسنل» (مدیر): نمای کلیِ همه + انتخابِ هر نفر + مرخصی‌های در انتظار + تنظیمات
    // =====================================================================
    function StaffPage(root) {
        const t = todayJ();
        const S = {jy: t[0], jm: t[1], person: null, view: null};
        async function load() {
            const r = await api('staff_overview', {jy: S.jy, jm: S.jm});
            if (!r.ok) { root.innerHTML = `<p class="text-center text-sm text-rose-600 py-10">${esc(r.error || 'خطا')}</p>`; return; }
            const pend = await api('pending_leaves');
            root.innerHTML = `
              <div class="space-y-4">
                <div class="wk-hero">
                  <div class="flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-[220px]"><p class="text-[11px] opacity-80 font-bold">اطلاعات پرسنلی</p><h2 class="text-xl font-black mt-1">کارکرد پرسنل · ${MONTHS[S.jm - 1]} ${fa(S.jy)}</h2>
                      <p class="text-[11px] opacity-80 mt-1">ساعتِ ورود و خروج، حضور، مرخصی و حال‌وهوای همه؛ روی هر نفر بزنید تا تقویمِ کاملش را ببینید.</p></div>
                    <div class="flex flex-wrap items-center gap-2">
                      <button type="button" class="wk-hbtn" data-a="prev">‹</button>
                      <select class="wk-in" style="width:auto;padding-top:8px;padding-bottom:8px;color:#0f172a;background:#fff" data-f="m">${MONTHS.map((n, i) => `<option value="${i + 1}" ${i + 1 === S.jm ? 'selected' : ''}>${n}</option>`).join('')}</select>
                      <select class="wk-in" style="width:auto;padding-top:8px;padding-bottom:8px;color:#0f172a;background:#fff" data-f="y">${Array.from({length: 7}, (_, i) => t[0] - 4 + i).map(y => `<option value="${y}" ${y === S.jy ? 'selected' : ''}>${fa(y)}</option>`).join('')}</select>
                      <button type="button" class="wk-hbtn" data-a="next">›</button>
                      <button type="button" class="wk-hbtn solid" data-a="report"><i class="fas fa-chart-column ml-1"></i>گزارش و اکسلِ همه</button>
                      <button type="button" class="wk-hbtn" data-a="settings"><i class="fas fa-sliders ml-1"></i>تنظیماتِ مرخصی</button>
                    </div>
                  </div>
                </div>
                <div class="wk-card hidden" data-box="settings"><h3 class="mb-3"><i class="fas fa-sliders text-indigo-600"></i>تنظیماتِ کارکرد و مرخصی</h3><div data-f="settings"></div></div>
                ${pend.ok && pend.rows.length ? `<div class="wk-card border-amber-200" style="background:linear-gradient(120deg,#fffbeb,#fff)">
                  <h3><i class="fas fa-hourglass-half text-amber-600"></i>مرخصی‌های در انتظارِ تأیید (${fa(pend.rows.length)})</h3>
                  <div class="mt-2 space-y-1.5">${pend.rows.map(L => `<div class="flex flex-wrap items-center gap-2 bg-white border border-amber-100 rounded-xl px-3 py-2 text-[11px]">
                    <b>${esc(L.name)}</b><span class="wk-chip ${L.kind === 'HOUR' ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700'}">${L.kind === 'HOUR' ? 'ساعتی' : 'روزانه'}</span>
                    <span>${L.kind === 'HOUR' ? fa(L.from) + ' · ' + fa(L.time_from) + ' تا ' + fa(L.time_to) : fa(L.from) + (L.to !== L.from ? ' تا ' + fa(L.to) : '')}</span><span class="text-slate-500">${esc(L.minutes_fa)}</span>
                    ${L.reason ? `<span class="text-slate-400">${esc(L.reason)}</span>` : ''}
                    <span class="mr-auto flex gap-1"><button type="button" class="text-[10.5px] font-black bg-emerald-600 text-white rounded-lg px-3 py-1" data-ok="${L.id}">تأیید</button><button type="button" class="text-[10.5px] font-black bg-rose-50 text-rose-600 rounded-lg px-3 py-1" data-no="${L.id}">رد</button></span></div>`).join('')}</div></div>` : ''}
                <div class="wk-card overflow-x-auto">
                  <h3 class="mb-2"><i class="fas fa-users text-indigo-600"></i>همه‌ی پرسنل</h3>
                  <table class="wk-tbl min-w-[900px]"><thead><tr><th>نام</th><th>امروز</th><th>حضور</th><th>ساعتِ کارکرد</th><th>اضافه/کسر</th><th>مرخصیِ ماه</th><th>مانده‌ی مرخصی</th><th>حال</th><th>کارها در پنل</th><th></th></tr></thead><tbody>
                  ${r.rows.map(x => `<tr class="${S.person === x.id ? 'bg-indigo-50' : ''}">
                    <td class="font-bold">${esc(x.name)} <span class="text-[10px] text-slate-400 font-normal">${esc((typeof ROLE_FA !== 'undefined' ? ROLE_FA : {})[x.role] || '')}</span></td>
                    <td>${x.today_in ? `${x.today_live ? '<span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse ml-1"></span>' : ''}${fa(x.today_in)}${x.today_out ? ' تا ' + fa(x.today_out) : ''}` : (x.today_type === 'LEAVE' ? '<span class="wk-chip bg-emerald-100 text-emerald-700">مرخصی</span>' : '<span class="text-slate-300">—</span>')}</td>
                    <td>${fa(x.present)} از ${fa(x.work_days)}</td><td>${fa(x.worked_fa)}</td><td class="${x.over < 0 ? 'text-rose-600' : 'text-emerald-700'}">${fa(x.over_fa)}</td>
                    <td>${esc(fa(x.leave_fa))}</td><td class="${x.balance < 0 ? 'text-rose-600 font-bold' : ''}">${esc(fa(x.balance_fa))}${x.pending ? ' <span class="wk-chip bg-amber-100 text-amber-700">در انتظار</span>' : ''}</td>
                    <td class="text-base">${x.mood_avg ? MOODS[Math.round(x.mood_avg)][0] : '—'}</td><td>${fa(x.acts)}</td>
                    <td><button type="button" class="text-[11px] font-black text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-lg px-3 py-1" data-p="${x.id}">مشاهده</button></td></tr>`).join('')}
                  </tbody></table>
                </div>
                <div data-box="person"></div>
              </div>`;
            const on = (a, fn) => root.querySelectorAll(`[data-a="${a}"]`).forEach(el => el.addEventListener('click', fn));
            on('prev', () => { S.jm--; if (S.jm < 1) { S.jm = 12; S.jy--; } load(); });
            on('next', () => { S.jm++; if (S.jm > 12) { S.jm = 1; S.jy++; } load(); });
            root.querySelector('[data-f="m"]').onchange = e => { S.jm = +e.target.value; load(); };
            root.querySelector('[data-f="y"]').onchange = e => { S.jy = +e.target.value; load(); };
            on('report', () => openReport({all: true}));
            on('settings', () => { const b = root.querySelector('[data-box="settings"]'); b.classList.toggle('hidden'); if (!b.classList.contains('hidden')) renderSettings(b.querySelector('[data-f="settings"]')); });
            root.querySelectorAll('[data-p]').forEach(b => b.addEventListener('click', () => openPerson(+b.dataset.p)));
            root.querySelectorAll('[data-ok],[data-no]').forEach(b => b.addEventListener('click', async () => {
                const ok = !!b.dataset.ok; let note = '';
                if (!ok && window.uiPrompt) { note = await uiPrompt('ردِ مرخصی', 'علتِ رد (اختیاری):', ''); if (note === null) return; }
                const res = await api('leave_decide', {id: +(b.dataset.ok || b.dataset.no), status: ok ? 'APPROVED' : 'REJECTED', note});
                if (!res.ok) { toast(res.error || 'خطا', 'error'); return; }
                toast(ok ? 'تأیید شد.' : 'رد شد.', 'success'); load();
            }));
            if (S.person) openPerson(S.person, true);
        }
        function openPerson(id, silent) {
            S.person = id;
            root.querySelectorAll('[data-p]').forEach(b => b.closest('tr').classList.toggle('bg-indigo-50', +b.dataset.p === id));
            const box = root.querySelector('[data-box="person"]');
            box.innerHTML = '';
            const inner = document.createElement('div');
            box.appendChild(inner);
            S.view = PersonView(inner, {userId: id, staff: true, jy: S.jy, jm: S.jm});
            if (!silent) setTimeout(() => box.scrollIntoView({behavior: 'smooth', block: 'start'}), 250);
        }
        load();
        return {reload: load};
    }

    let myView = null, staffView = null;
    window.WorkLog = {
        initMy() { const el = document.getElementById('wk-my-root'); if (!el) return; if (myView) myView.reload(); else myView = PersonView(el, {}); },
        initStaff() { const el = document.getElementById('wk-staff-root'); if (!el) return; if (staffView) staffView.reload(); else staffView = StaffPage(el); },
        renderSettings,
        _j: {g2j, j2g, monthLen},
    };
})();
