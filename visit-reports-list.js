// فایل: visit-reports-list.js
// «گزارش‌های صادرشده»: آمار، جستجو و فیلتر، فهرست، و پنجره‌ی جزئیات (عکس‌ها، فایل‌ها، نسخه‌ها،
// تاریخچه، اتصال به بازدید سلامت/درخواست کارکنان/ردیف شرکتی، حذف و بازگردانی).
// مدیر کل همه‌ی گزارش‌ها را می‌بیند؛ بقیه فقط گزارش‌هایی که خودشان صادر کرده‌اند.
(function () {
    'use strict';
    const VR = window.VR;
    const { api, modal, askPassword, lightbox, plateHtml, fa, en, esc, money, fileUrl, API } = VR;
    const toast = (m, t = 'info') => (typeof window.showToast === 'function' ? window.showToast(m, t) : console.log(m));
    const confirmBox = (t, m, o) => (window.uiConfirm ? uiConfirm(t, m, o) : Promise.resolve(confirm(m)));
    const LINK_FA = { health: ['بازدید سلامت', 'fa-heart-pulse', 'bg-rose-50 text-rose-600'], case: ['درخواست کارکنان', 'fa-user-tie', 'bg-sky-50 text-sky-600'], company: ['ردیف شرکتی', 'fa-building', 'bg-violet-50 text-violet-600'] };

    const st = { page: 1, filters: {}, data: null, timer: null };

    function countUp(el, to) {
        const t0 = performance.now(), dur = 700, from = 0;
        const step = now => { const p = Math.min(1, (now - t0) / dur); el.textContent = fa(Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3)))); if (p < 1) requestAnimationFrame(step); };
        requestAnimationFrame(step);
    }

    function shell(root) {
        VR.injectCss();
        root.innerHTML = `
        <div class="space-y-4">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 vr-stagger" id="vrl-stats">
                ${[['total', 'کلِ گزارش‌ها', 'fa-file-lines', 'from-indigo-500 to-violet-600'], ['today', 'امروز', 'fa-sun', 'from-amber-400 to-orange-500'],
                   ['month', 'این ماه', 'fa-calendar', 'from-sky-500 to-cyan-500'], ['linked', 'متصل به درخواست', 'fa-link', 'from-emerald-500 to-teal-500']].map(([k, l, i, g]) => `
                <div class="vr-card p-4 flex items-center gap-3 overflow-hidden relative"><span class="w-12 h-12 rounded-2xl bg-gradient-to-br ${g} text-white flex items-center justify-center text-lg shadow-lg"><i class="fas ${i}"></i></span>
                <div><p class="text-[11px] font-bold text-slate-400">${l}</p><p class="text-2xl font-black text-slate-800" data-stat="${k}">۰</p></div>
                <i class="fas ${i} absolute -left-3 -bottom-4 text-7xl text-slate-100 -z-0 pointer-events-none"></i></div>`).join('')}
            </div>
            <div class="vr-card p-4 vr-fade-up">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative flex-1 min-w-[14rem]"><i class="fas fa-magnifying-glass absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-sm"></i>
                        <input id="vrl-q" class="vr-in pr-9" placeholder="جستجو: شماره گزارش، پلاک، بیمه‌گذار، کد ملی، شاسی، موتور، بازدیدکننده..."></div>
                    <button type="button" class="vr-btn vr-btn-s" id="vrl-toggle"><i class="fas fa-sliders"></i> فیلترها <span id="vrl-fcount" class="vr-chip bg-indigo-100 text-indigo-600 hidden"></span></button>
                    <button type="button" class="vr-btn vr-btn-g" id="vrl-excel"><i class="fas fa-file-excel"></i> خروجی اکسل</button>
                    <button type="button" class="vr-btn vr-btn-p" id="vrl-new"><i class="fas fa-plus"></i> گزارشِ جدید</button>
                </div>
                <div id="vrl-filters" class="hidden grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mt-4 pt-4 border-t border-slate-100"></div>
            </div>
            <div id="vrl-list" class="space-y-2"></div>
            <div id="vrl-pager" class="flex items-center justify-center gap-2 py-2"></div>
        </div>`;
        root.querySelector('#vrl-q').addEventListener('input', () => { clearTimeout(st.timer); st.timer = setTimeout(() => { st.page = 1; load(); }, 350); });
        root.querySelector('#vrl-toggle').onclick = () => root.querySelector('#vrl-filters').classList.toggle('hidden');
        root.querySelector('#vrl-excel').onclick = () => { window.location = `${API}?action=export_excel&${new URLSearchParams(params()).toString()}`; };
        root.querySelector('#vrl-new').onclick = () => { if (typeof window.switchTab === 'function') switchTab('vr-build'); };
    }

    function filtersHtml(f, isAdmin) {
        const sel = (id, label, opts) => `<div><label class="vr-lbl">${label}</label><select id="${id}" class="vr-in !py-2">${opts}</select></div>`;
        return sel('vrl-cat', 'نوع گزارش', `<option value="">همه</option>${f.categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}`)
            + sel('vrl-ins', 'بیمه‌گر', `<option value="">همه</option>${Object.entries(f.insurers).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}`)
            + sel('vrl-vis', 'بازدیدکننده', `<option value="">همه</option>${f.visitors.map(v => `<option>${esc(v)}</option>`).join('')}`)
            + (isAdmin ? sel('vrl-iss', 'صادرکننده', `<option value="">همه</option>${(f.issuers || []).map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}`) : '')
            + `<div><label class="vr-lbl">از تاریخ</label><input id="vrl-from" class="vr-in !py-2 text-center" dir="ltr" placeholder="۱۴۰۵/۰۱/۰۱"></div>
               <div><label class="vr-lbl">تا تاریخ</label><input id="vrl-to" class="vr-in !py-2 text-center" dir="ltr" placeholder="۱۴۰۵/۱۲/۲۹"></div>`
            + sel('vrl-link', 'اتصال', `<option value="">همه</option><option value="yes">متصل</option><option value="no">بدونِ اتصال</option><option value="health">بازدید سلامت</option><option value="case">درخواست کارکنان</option><option value="company">ردیف شرکتی</option>`)
            + sel('vrl-sort', 'مرتب‌سازی', `<option value="new">جدیدترین</option><option value="old">قدیمی‌ترین</option><option value="date">تاریخ گزارش</option><option value="value">ارزش خودرو</option>`)
            + `<div class="flex items-end gap-3"><label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer"><input type="checkbox" id="vrl-edited" class="accent-indigo-600"> ویرایش‌شده</label>
               <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer"><input type="checkbox" id="vrl-photos" class="accent-indigo-600"> با عکس</label></div>`
            + (isAdmin ? sel('vrl-status', 'وضعیت', `<option value="">فعال</option><option value="DELETED">حذف‌شده‌ها</option>`) : '')
            + `<div class="flex items-end"><button type="button" class="vr-btn vr-btn-s w-full" id="vrl-reset"><i class="fas fa-rotate-left"></i> پاک کردنِ فیلترها</button></div>`;
    }

    function params() {
        const v = id => { const el = document.getElementById(id); return el ? (el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value.trim()) : ''; };
        const p = { q: en(v('vrl-q')), category_id: v('vrl-cat'), insurer: v('vrl-ins'), visitor: v('vrl-vis'), issuer_id: v('vrl-iss'), date_from: en(v('vrl-from')),
                    date_to: en(v('vrl-to')), linked: v('vrl-link'), sort: v('vrl-sort') || 'new', edited: v('vrl-edited'), has_photos: v('vrl-photos'), status: v('vrl-status') };
        Object.keys(p).forEach(k => { if (!p[k]) delete p[k]; });
        return p;
    }

    async function load() {
        const list = document.getElementById('vrl-list');
        if (!list) return;
        if (!st.data) list.innerHTML = [1, 2, 3, 4].map(() => '<div class="vr-skel h-20"></div>').join('');
        const p = params();
        const d = await api('list', { ...p, page: st.page, per_page: 25 });
        if (!d.ok) { list.innerHTML = `<div class="vr-card p-8 text-center text-red-500 font-bold text-sm">${esc(d.error)}</div>`; return; }
        const first = !st.data;
        st.data = d;
        const fBox = document.getElementById('vrl-filters');
        if (first) {
            fBox.innerHTML = filtersHtml(d.filters, d.is_admin);
            fBox.querySelectorAll('select,input').forEach(el => el.addEventListener(el.tagName === 'INPUT' && el.type !== 'checkbox' ? 'input' : 'change', () => { clearTimeout(st.timer); st.timer = setTimeout(() => { st.page = 1; load(); }, 350); }));
            document.getElementById('vrl-reset').onclick = () => { fBox.querySelectorAll('select').forEach(s => s.selectedIndex = 0); fBox.querySelectorAll('input').forEach(i => i.type === 'checkbox' ? (i.checked = false) : (i.value = '')); st.page = 1; load(); };
            Object.entries(d.stats).forEach(([k, v]) => { const el = document.querySelector(`[data-stat="${k}"]`); if (el) countUp(el, v || 0); });
        } else Object.entries(d.stats).forEach(([k, v]) => { const el = document.querySelector(`[data-stat="${k}"]`); if (el) el.textContent = fa(v || 0); });
        const fc = Object.keys(p).filter(k => !['q', 'sort'].includes(k)).length;
        const fcEl = document.getElementById('vrl-fcount');
        fcEl.textContent = fa(fc); fcEl.classList.toggle('hidden', !fc);
        if (!d.rows.length) {
            list.innerHTML = `<div class="vr-card p-12 text-center vr-fade-up"><div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-300 flex items-center justify-center mx-auto text-2xl mb-3"><i class="fas fa-folder-open"></i></div>
                <p class="font-black text-slate-500 text-sm">${Object.keys(p).length > 1 ? 'گزارشی با این جستجو/فیلتر پیدا نشد.' : 'هنوز گزارشی صادر نشده است.'}</p></div>`;
        } else list.innerHTML = `<div class="space-y-2 vr-stagger">${d.rows.map(rowHtml).join('')}</div>`;
        list.querySelectorAll('[data-open]').forEach(el => el.onclick = () => VR.openDetail(Number(el.dataset.open)));
        const pages = Math.ceil(d.total / d.per_page);
        const pg = document.getElementById('vrl-pager');
        pg.innerHTML = pages > 1 ? `<button class="vr-btn vr-btn-s !py-1.5" ${st.page <= 1 ? 'disabled' : ''} data-pg="${st.page - 1}"><i class="fas fa-chevron-right"></i></button>
            <span class="text-xs font-bold text-slate-500">صفحه ${fa(st.page)} از ${fa(pages)} · ${fa(d.total)} گزارش</span>
            <button class="vr-btn vr-btn-s !py-1.5" ${st.page >= pages ? 'disabled' : ''} data-pg="${st.page + 1}"><i class="fas fa-chevron-left"></i></button>` : (d.total ? `<span class="text-xs font-bold text-slate-400">${fa(d.total)} گزارش</span>` : '');
        pg.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { st.page = Number(b.dataset.pg); load(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    }

    function linksChips(r) {
        const out = [];
        if (r.health_inspection_id) out.push(['health']); else if (r.case_id) out.push(['case']);
        if (r.company_plate_id) out.push(['company']);
        return out.map(([k]) => `<span class="vr-chip ${LINK_FA[k][2]}"><i class="fas ${LINK_FA[k][1]}"></i> ${LINK_FA[k][0]}</span>`).join('');
    }
    function rowHtml(r) {
        return `<div class="vr-card p-3 sm:p-4 grid grid-cols-12 gap-3 items-center cursor-pointer hover:shadow-lg hover:-translate-y-0.5 transition-all ${r.status === 'DELETED' ? 'opacity-60' : ''}" data-open="${r.id}">
            <div class="col-span-12 sm:col-span-3 flex items-center gap-3 min-w-0">
                <span class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 bg-gradient-to-br ${r.insurer === 'PARSIAN' ? 'from-rose-500 to-red-600' : 'from-sky-500 to-indigo-600'}"><i class="fas ${/سنگین|کامیون/.test(r.category_name) ? 'fa-truck' : 'fa-car'}"></i></span>
                <div class="min-w-0"><p class="font-black text-slate-700 text-xs" dir="ltr" style="text-align:right">${esc(r.report_no)}</p>
                <p class="text-[10px] text-slate-400 font-bold truncate">${esc(r.category_name)}</p></div></div>
            <div class="col-span-6 sm:col-span-2">${plateHtml(r.plate_display)}${!r.plate_display && r.chassis_no ? `<p class="text-[10px] font-bold text-slate-500 mt-1" dir="ltr">${esc(r.chassis_no)}</p>` : ''}</div>
            <div class="col-span-6 sm:col-span-3 min-w-0"><p class="text-xs font-black text-slate-700 truncate">${esc(r.insured_name)}</p>
                <p class="text-[10px] text-slate-400 font-bold truncate">${esc(r.vehicle_type || '')}${r.model_year ? ' · ' + fa(r.model_year) : ''}${r.car_value ? ' · ' + money(r.car_value) + ' ریال' : ''}</p></div>
            <div class="col-span-7 sm:col-span-2 text-[10px] font-bold text-slate-500 leading-5"><p><i class="fas fa-calendar-day text-slate-300 ml-1"></i>${fa(r.report_date.replace(/\./g, '/'))}</p>
                <p class="truncate"><i class="fas fa-user-check text-slate-300 ml-1"></i>${esc(r.visitor_name || '—')}</p><p class="truncate"><i class="fas fa-pen-nib text-slate-300 ml-1"></i>${esc(r.issuer_name || '')}</p></div>
            <div class="col-span-5 sm:col-span-2 flex flex-wrap gap-1 justify-end">
                ${r.status === 'DELETED' ? '<span class="vr-chip bg-red-100 text-red-600"><i class="fas fa-trash"></i> حذف‌شده</span>' : ''}
                ${linksChips(r)}
                ${r.version > 1 ? `<span class="vr-chip bg-amber-50 text-amber-600"><i class="fas fa-code-branch"></i> نسخه ${fa(r.version)}</span>` : ''}
                ${r.photos_count ? `<span class="vr-chip bg-slate-100 text-slate-500"><i class="fas fa-image"></i> ${fa(r.photos_count)}</span>` : ''}
            </div></div>`;
    }

    // ------------------------------------------------------------------
    //  جزئیات
    // ------------------------------------------------------------------
    VR.openDetail = async function (id) {
        const m = modal({ title: 'جزئیات گزارش', icon: 'fa-file-lines', width: '72rem', html: `<div class="space-y-3">${[1, 2, 3].map(() => '<div class="vr-skel h-24"></div>').join('')}</div>` });
        const reload = async () => {
            const d = await api('detail', { id });
            if (!d.ok) { m.body.innerHTML = `<div class="vr-card p-8 text-center text-red-500 font-bold text-sm">${esc(d.error)}</div>`; return; }
            renderDetail(m, d.report, reload);
        };
        reload();
        return m;
    };

    function renderDetail(m, r, reload) {
        m.setTitle(`گزارش <span dir="ltr">${esc(r.report_no)}</span>`, `${esc(r.category_name)} · صادرکننده: ${esc(r.issuer_name || '')} · ${fa(r.created_jalali)}`);
        const photoUrls = r.photos.map(p => fileUrl(r.id, 'photo', '&pid=' + p.id));
        const bad = r.parts.filter(p => p.status === 'k');
        const dmg = (r.form && r.form.damages) || [];
        const bySec = {};
        r.display.forEach(x => { (bySec[x.section || 'مشخصات'] = bySec[x.section || 'مشخصات'] || []).push(x); });
        const active = r.status === 'ACTIVE';
        m.body.innerHTML = `<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 space-y-4">
                <div class="vr-card p-5 vr-fade-up">
                    <div class="flex flex-wrap items-center gap-3 mb-4">${plateHtml(r.plate_display)}
                        ${r.status === 'DELETED' ? '<span class="vr-chip bg-red-100 text-red-600"><i class="fas fa-trash"></i> حذف‌شده</span>' : ''}
                        ${r.version > 1 ? `<span class="vr-chip bg-amber-50 text-amber-600"><i class="fas fa-code-branch"></i> نسخه ${fa(r.version)}</span>` : ''}
                        <span class="vr-chip bg-indigo-50 text-indigo-600 mr-auto"><i class="fas fa-calendar-day"></i> ${fa(r.report_date.replace(/\./g, '/'))}</span></div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-xs">
                        ${[['بیمه‌گذار', r.insured_name], ['کد ملی', fa(r.national_id || '')], ['تلفن', fa(r.phone || '')], ['آدرس', r.address], ['بازدیدکننده', r.visitor_name], ['بیمه‌گر', r.insurer_fa]]
                            .filter(x => x[1]).map(([k, v]) => `<div class="flex gap-2 border-b border-dashed border-slate-100 pb-1.5"><span class="text-slate-400 font-bold w-24 shrink-0">${k}</span><span class="font-black text-slate-700">${esc(v)}</span></div>`).join('')}
                    </div></div>
                ${Object.entries(bySec).map(([s, rows]) => `<div class="vr-card p-5 vr-fade-up"><p class="vr-sec-title mb-3 !text-xs">${esc(s)}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-xs">${rows.map(x => `<div class="flex gap-2 border-b border-dashed border-slate-100 pb-1.5"><span class="text-slate-400 font-bold w-28 shrink-0">${esc(x.label)}</span><span class="font-black text-slate-700 break-all">${esc(x.value ? (/^[\d\s,.:\/]+( ریال)?$/.test(x.value) ? fa(x.value) : x.value) : '—')}</span></div>`).join('')}</div></div>`).join('')}
                ${r.parts.length ? `<div class="vr-card p-5 vr-fade-up"><p class="vr-sec-title mb-3 !text-xs">وضعیت قطعات <span class="vr-chip ${bad.length ? 'bg-red-100 text-red-600' : 'bg-emerald-100 text-emerald-600'}">${bad.length ? fa(bad.length) + ' ' + esc(r.bad_label) : 'همه ' + esc(r.ok_label)}</span></p>
                    <div class="flex flex-wrap gap-1.5">${r.parts.map(p => `<span class="vr-chip ${p.status === 'k' ? 'bg-red-100 text-red-600' : 'bg-slate-50 text-slate-400'}"><i class="fas ${p.status === 'k' ? 'fa-circle-exclamation' : 'fa-check'}"></i> ${esc(p.label)}</span>`).join('')}</div></div>` : ''}
                ${dmg.length ? `<div class="vr-card p-5 vr-fade-up"><p class="vr-sec-title mb-3 !text-xs">مواضع آسیب‌دیده</p>
                    <div class="space-y-1.5">${dmg.map((x, i) => `<div class="flex gap-2 text-xs"><span class="w-6 h-6 rounded-full bg-rose-100 text-rose-600 font-black flex items-center justify-center text-[10px] shrink-0">${fa(i + 1)}</span><span class="font-black text-slate-700">${esc(x.location)}</span><span class="text-slate-500">${esc(x.description)}</span></div>`).join('')}</div></div>` : ''}
                <div class="vr-card p-5 vr-fade-up"><p class="vr-sec-title mb-3 !text-xs">عکس‌ها (${fa(r.photos.length)})</p>
                    ${r.photos.length ? `<div class="grid grid-cols-3 sm:grid-cols-5 gap-2">${r.photos.map((p, i) => `<div class="vr-thumb cursor-zoom-in" data-ph="${i}"><img src="${photoUrls[i]}" loading="lazy"><span class="vr-cap">${esc(p.name.replace(/\.\w+$/, ''))}</span></div>`).join('')}</div>`
                        : '<p class="text-xs text-slate-400 font-bold">عکسی ذخیره نشده است.</p>'}</div>
            </div>
            <div class="space-y-4">
                <div class="vr-card p-5 vr-fade-up space-y-2">
                    <p class="vr-sec-title mb-2 !text-xs">فایل‌ها</p>
                    <a class="vr-btn vr-btn-p w-full" target="_blank" href="${fileUrl(r.id, 'pdf', '&inline=1')}"><i class="fas fa-file-pdf"></i> مشاهده PDF</a>
                    <div class="grid grid-cols-2 gap-2">
                        <a class="vr-btn vr-btn-s" href="${fileUrl(r.id, 'pdf')}"><i class="fas fa-download"></i> دانلود PDF</a>
                        ${r.has_docx ? `<a class="vr-btn vr-btn-s" href="${fileUrl(r.id, 'docx')}"><i class="fas fa-file-word text-blue-600"></i> Word</a>` : '<span></span>'}
                        ${r.has_zip ? `<a class="vr-btn vr-btn-s" href="${fileUrl(r.id, 'zip')}"><i class="fas fa-file-zipper text-amber-500"></i> ZIP عکس‌ها</a>` : '<span></span>'}
                        <a class="vr-btn vr-btn-s" href="${API}?action=folder_zip&id=${r.id}"><i class="fas fa-box-archive text-violet-500"></i> کلِ پوشه</a>
                    </div>
                    ${r.can_admin && r.archive_path ? `<button type="button" class="vr-btn vr-btn-s w-full vr-d-folder"><i class="fas fa-folder-open text-amber-500"></i> نمایشِ پوشه در بایگانی</button>` : ''}
                    ${active && r.can_edit ? `<button type="button" class="vr-btn vr-btn-s w-full vr-d-edit !text-amber-700 !border-amber-200"><i class="fas fa-pen-to-square"></i> ویرایش گزارش</button>` : ''}
                    ${r.can_admin ? (active ? `<button type="button" class="vr-btn vr-btn-r w-full vr-d-del"><i class="fas fa-trash"></i> حذف (انتقال به «حذف شده»)</button>`
                        : `<div class="grid grid-cols-2 gap-2"><button type="button" class="vr-btn vr-btn-g vr-d-restore"><i class="fas fa-rotate-left"></i> بازگردانی</button><button type="button" class="vr-btn vr-btn-r vr-d-purge"><i class="fas fa-fire"></i> حذف همیشگی</button></div>`) : ''}
                </div>
                <div class="vr-card p-5 vr-fade-up"><p class="vr-sec-title mb-3 !text-xs">اتصال‌ها</p>
                    ${r.links.length ? r.links.map(l => `<div class="flex items-center gap-2 text-xs font-bold mb-2"><span class="vr-chip ${LINK_FA[l.type][2]}"><i class="fas ${LINK_FA[l.type][1]}"></i></span><span class="text-slate-600">${esc(l.title)}</span></div>`).join('')
                        : '<p class="text-xs text-slate-400 font-bold">به هیچ درخواستی وصل نیست.</p>'}
                    ${r.can_admin && active ? `<div class="mt-3 pt-3 border-t border-slate-100"><p class="text-[11px] font-black text-slate-500 mb-2"><i class="fas fa-wand-magic-sparkles text-violet-400"></i> پیشنهادِ اتصال (هم‌پلاک / هم‌شاسی)</p>
                        ${r.candidates.length ? r.candidates.map(c => `<div class="flex items-center gap-2 p-2 rounded-xl hover:bg-slate-50 mb-1">
                            <span class="vr-chip ${LINK_FA[c.type][2]}"><i class="fas ${LINK_FA[c.type][1]}"></i></span>
                            <div class="min-w-0 flex-1"><p class="text-[11px] font-black text-slate-700 truncate">${esc(c.title)}</p><p class="text-[10px] text-slate-400 font-bold truncate">${esc(c.subtitle || '')}</p></div>
                            <button type="button" class="vr-btn vr-btn-s !py-1 !px-2 !text-[10px] vr-d-link" data-type="${c.type}" data-tid="${c.id}" ${c.ready ? '' : 'disabled title="عکس‌های این بازدید هنوز تایید نشده"'}>اتصال</button></div>`).join('')
                            : '<p class="text-[11px] text-slate-400 font-bold">درخواستِ هم‌پلاک یا هم‌شاسی پیدا نشد.</p>'}
                        ${r.links.length ? '<button type="button" class="vr-btn vr-btn-s w-full mt-2 !text-[11px] vr-d-unlink"><i class="fas fa-link-slash"></i> قطعِ اتصال</button>' : ''}</div>` : ''}
                </div>
                ${r.versions.length ? `<div class="vr-card p-5 vr-fade-up"><p class="vr-sec-title mb-3 !text-xs">نسخه‌های قبلی</p>
                    ${r.versions.map(v => `<div class="flex items-center gap-2 text-[11px] font-bold mb-2"><span class="vr-chip bg-amber-50 text-amber-600">نسخه ${fa(v.version)}</span>
                        <span class="text-slate-400 flex-1 truncate">${esc(v.edited_by || '')} · ${fa(v.edited_jalali)}</span>
                        ${v.has_pdf ? `<a class="text-rose-500 hover:text-rose-700" target="_blank" href="${fileUrl(r.id, 'pdf', '&vid=' + v.id + '&inline=1')}" title="PDF"><i class="fas fa-file-pdf"></i></a>` : ''}
                        ${v.has_docx ? `<a class="text-blue-500 hover:text-blue-700" href="${fileUrl(r.id, 'docx', '&vid=' + v.id)}" title="Word"><i class="fas fa-file-word"></i></a>` : ''}</div>`).join('')}</div>` : ''}
                <div class="vr-card p-5 vr-fade-up"><p class="vr-sec-title mb-3 !text-xs">تاریخچه</p>
                    <div class="relative pr-4 border-r-2 border-indigo-100 space-y-3">${r.history.map(h => `<div class="relative"><span class="absolute -right-[1.4rem] top-1 w-2.5 h-2.5 rounded-full bg-indigo-400 ring-4 ring-indigo-50"></span>
                        <p class="text-[11px] font-black text-slate-700">${esc(h.action)} <span class="text-slate-400 font-bold">· ${esc(h.by || '')}</span></p>
                        <p class="text-[10px] text-slate-400 font-bold">${fa(h.at)}</p>${h.note ? `<p class="text-[10px] text-slate-500 break-all">${esc(h.note)}</p>` : ''}</div>`).join('') || '<p class="text-[11px] text-slate-400">—</p>'}</div></div>
            </div></div>`;
        m.body.querySelectorAll('[data-ph]').forEach(el => el.onclick = () => lightbox(photoUrls, Number(el.dataset.ph), r.photos.map(p => p.name)));
        const on = (sel, fn) => { const el = m.body.querySelector(sel); if (el) el.onclick = fn; };
        const changed = () => { window.dispatchEvent(new CustomEvent('vr:changed', { detail: r })); };
        on('.vr-d-edit', () => { m.close(); VR.openEditor(r.id, () => changed()); });
        on('.vr-d-folder', () => { m.close(); if (typeof window.switchTab === 'function') { switchTab('filemanager'); if (typeof window.fmOpen === 'function') fmOpen(r.archive_path); } });
        on('.vr-d-del', async () => {
            if (!await confirmBox('حذف گزارش', `گزارش ${r.report_no} به پوشه‌ی «حذف شده» منتقل می‌شود و از دفترِ اکسل حذف می‌شود؛ بعداً قابلِ بازگردانی است.`, { danger: true, ok: 'حذف' })) return;
            const d = await api('delete', { id: r.id }); if (!d.ok) return toast(d.error, 'error');
            toast('گزارش حذف شد.', 'info'); m.close(); changed();
        });
        on('.vr-d-restore', async () => { const d = await api('restore', { id: r.id }); if (!d.ok) return toast(d.error, 'error'); toast('گزارش بازگردانده شد.', 'info'); reload(); changed(); });
        on('.vr-d-purge', async () => {
            const pw = await askPassword('حذفِ همیشگی', `پوشه و همه‌ی فایل‌های گزارش ${esc(r.report_no)} برای همیشه پاک می‌شود و برگشت‌پذیر نیست. رمزِ خودتان را وارد کنید.`);
            if (pw === null) return;
            const d = await api('purge', { id: r.id, password: pw }); if (!d.ok) return toast(d.error, 'error');
            toast('برای همیشه پاک شد.', 'info'); m.close(); changed();
        });
        m.body.querySelectorAll('.vr-d-link').forEach(b => b.onclick = async () => {
            const label = LINK_FA[b.dataset.type][0];
            if (!await confirmBox('اتصال گزارش', `فایلِ PDFِ گزارش با نام‌گذاریِ استاندارد در پوشه‌ی این ${label} قرار می‌گیرد${b.dataset.type === 'health' ? ' و اگر بازدید منتظرِ گزارش است، تاییدِ نهایی می‌شود' : ''}. ادامه می‌دهید؟`)) return;
            b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            const d = await api('link', { id: r.id, type: b.dataset.type, target_id: Number(b.dataset.tid) });
            if (!d.ok) { toast(d.error, 'error'); b.disabled = false; b.textContent = 'اتصال'; return; }
            toast(`به ${label} وصل شد.`, 'info'); reload(); changed();
        });
        on('.vr-d-unlink', async () => {
            if (!await confirmBox('قطعِ اتصال', 'اتصالِ این گزارش برداشته می‌شود (فایل‌هایی که قبلاً در پوشه‌ی مقصد کپی شده‌اند سرِ جایشان می‌مانند).')) return;
            const d = await api('unlink', { id: r.id }); if (!d.ok) return toast(d.error, 'error'); reload(); changed();
        });
    }

    VR.initListTab = function () {
        const root = document.getElementById('vr-list-root');
        if (!root) return;
        if (!root.dataset.ready) { shell(root); root.dataset.ready = '1'; st.data = null; }
        load();
    };
    window.addEventListener('vr:changed', () => { if (document.getElementById('vrl-list') && st.data) load(); });
})();
