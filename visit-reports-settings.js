// فایل: visit-reports-settings.js
// «تنظیمات گزارش» (فقط مدیر کل): هر چیزی که در برنامه‌ی ویندوزی در fields_config.xlsx و پوشه‌ی Config بود:
// انواع گزارش و دسترسی‌ها، قالب Word (آپلود، بررسیِ متغیرها، پیش‌نمایش، جابه‌جاییِ کادرها)، الگوریتمِ
// استخراج (.py) و آزمایشش، فیلدها، بازدیدکننده‌ها، بیمه‌گذاران، قلم‌ها، تنظیماتِ عمومی، تاریخچه و راهنما.
(function () {
    'use strict';
    const VR = window.VR;
    const { api, apiForm, modal, fa, en, esc, API } = VR;
    const toast = (m, t = 'info') => (typeof window.showToast === 'function' ? window.showToast(m, t) : console.log(m));
    const confirmBox = (t, m, o) => (window.uiConfirm ? uiConfirm(t, m, o) : Promise.resolve(confirm(m)));
    const S = { boot: null, tab: 'types', catId: null, catTab: 'general' };

    const TABS = [['types', 'انواع گزارش', 'fa-shapes'], ['visitors', 'بازدیدکننده‌ها', 'fa-user-check'], ['insureds', 'بیمه‌گذاران', 'fa-user-tie'],
                  ['fonts', 'قلم‌ها', 'fa-font'], ['general', 'عمومی', 'fa-sliders'], ['logs', 'تاریخچه', 'fa-clock-rotate-left'], ['guide', 'راهنما', 'fa-book-open']];

    async function loadBoot() {
        const d = await api('set_bootstrap');
        if (!d.ok) { toast(d.error, 'error'); return null; }
        S.boot = d;
        if (!S.catId && d.categories.length) S.catId = d.categories[0].id;
        return d;
    }

    function root() { return document.getElementById('vr-settings-root'); }
    function render() {
        const r = root();
        r.innerHTML = `<div class="space-y-4">
            <div class="vr-card p-2 flex flex-wrap gap-1 vr-fade-up">${TABS.map(([k, l, i]) => `<button type="button" data-t="${k}" class="px-3.5 py-2 rounded-xl text-xs font-black transition-all ${S.tab === k ? 'bg-gradient-to-l from-indigo-600 to-violet-600 text-white shadow-md' : 'text-slate-500 hover:bg-slate-100'}"><i class="fas ${i} ml-1"></i>${l}</button>`).join('')}</div>
            <div id="vrs-body"></div></div>`;
        r.querySelectorAll('[data-t]').forEach(b => b.onclick = () => { S.tab = b.dataset.t; render(); });
        const body = r.querySelector('#vrs-body');
        ({ types: renderTypes, visitors: renderVisitors, insureds: renderInsureds, fonts: renderFonts, general: renderGeneral, logs: renderLogs, guide: renderGuide })[S.tab](body);
    }

    const catsCheck = (name, selected = []) => `<div class="flex flex-wrap gap-2">${S.boot.categories.map(c => `<label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-600 bg-slate-50 rounded-lg px-2 py-1 cursor-pointer">
        <input type="checkbox" class="accent-indigo-600" name="${name}" value="${c.id}" ${selected.includes(c.id) ? 'checked' : ''}> ${esc(c.name)}</label>`).join('')}</div>`;
    const checkedVals = (box, name) => [...box.querySelectorAll(`input[name="${name}"]:checked`)].map(i => Number(i.value) || i.value);

    // ------------------------------------------------------------------
    //  انواع گزارش
    // ------------------------------------------------------------------
    function renderTypes(body) {
        const cats = S.boot.categories;
        const cat = cats.find(c => c.id === S.catId);
        body.innerHTML = `<div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            <div class="vr-card p-3 space-y-1.5 h-fit vr-fade-up">
                ${cats.map(c => `<button type="button" data-c="${c.id}" class="w-full text-right p-3 rounded-xl transition-all ${c.id === S.catId ? 'bg-indigo-50 ring-2 ring-indigo-200' : 'hover:bg-slate-50'}">
                    <p class="text-xs font-black ${c.is_active ? 'text-slate-700' : 'text-slate-400 line-through'}">${esc(c.name)}</p>
                    <p class="text-[10px] font-bold text-slate-400 mt-0.5">${esc(S.boot.insurers[c.insurer] || c.insurer)} · ${fa(c.fields.length)} فیلد · ${fa(c.reports)} گزارش
                    ${!c.template.exists ? ' · <span class="text-red-500">بدونِ قالب</span>' : c.template.unfilled.length ? ' · <span class="text-amber-500">متغیرِ بی‌فیلد</span>' : ''}</p></button>`).join('')}
                <button type="button" class="vr-btn vr-btn-p w-full vrs-new-cat"><i class="fas fa-plus"></i> نوعِ گزارشِ جدید</button>
            </div>
            <div class="lg:col-span-3 space-y-4" id="vrs-cat"></div></div>`;
        body.querySelectorAll('[data-c]').forEach(b => b.onclick = () => { S.catId = Number(b.dataset.c); renderTypes(body); });
        body.querySelector('.vrs-new-cat').onclick = () => { S.catId = 0; renderTypes(body); };
        const box = body.querySelector('#vrs-cat');
        if (S.catId === 0) { box.innerHTML = generalCatHtml(null); bindGeneral(box, null, body); return; }
        if (!cat) { box.innerHTML = '<div class="vr-card p-8 text-center text-slate-400 font-bold text-sm">یک نوع گزارش انتخاب کنید یا بسازید.</div>'; return; }
        const sub = [['general', 'مشخصات و دسترسی', 'fa-id-card'], ['template', 'قالب Word', 'fa-file-word'], ['fields', 'فیلدها', 'fa-table-list'], ['parser', 'استخراج از PDF', 'fa-wand-magic-sparkles']];
        box.innerHTML = `<div class="vr-card p-2 flex flex-wrap gap-1">${sub.map(([k, l, i]) => `<button type="button" data-s="${k}" class="px-3 py-1.5 rounded-lg text-[11px] font-black ${S.catTab === k ? 'bg-slate-800 text-white' : 'text-slate-500 hover:bg-slate-100'}"><i class="fas ${i} ml-1"></i>${l}</button>`).join('')}</div><div id="vrs-sub"></div>`;
        box.querySelectorAll('[data-s]').forEach(b => b.onclick = () => { S.catTab = b.dataset.s; renderTypes(body); });
        const sb = box.querySelector('#vrs-sub');
        if (S.catTab === 'general') { sb.innerHTML = generalCatHtml(cat); bindGeneral(sb, cat, body); }
        if (S.catTab === 'template') renderTemplate(sb, cat, body);
        if (S.catTab === 'fields') renderFields(sb, cat, body);
        if (S.catTab === 'parser') renderParser(sb, cat, body);
    }

    function generalCatHtml(c) {
        const o = (c && c.options) || {};
        const acc = (c && c.access) || { roles: ['ADMIN'], users: [] };
        const roles = S.boot.roles;
        return `<div class="vr-card p-5 vr-fade-up space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="md:col-span-2"><label class="vr-lbl">نامِ نوع گزارش</label><input class="vr-in" id="vrs-name" value="${esc(c ? c.name : '')}" placeholder="مثلاً: پارسیان - بازدید بدنه موتورسیکلت"></div>
                <div><label class="vr-lbl">بیمه‌گر (پوشه‌ی بایگانی)</label><select class="vr-in" id="vrs-insurer">${Object.entries(S.boot.insurers).map(([k, v]) => `<option value="${k}" ${c && c.insurer === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
                <div class="md:col-span-3"><label class="vr-lbl">توضیح (زیرِ نامِ نوع در صفحه‌ی ساخت)</label><input class="vr-in" id="vrs-desc" value="${esc(o.description || '')}"></div>
                <div><label class="vr-lbl">برچسبِ «سالم» / پسوندِ متغیر</label><div class="flex gap-2"><input class="vr-in" id="vrs-okl" value="${esc(c ? c.ok_label : 'سالم')}"><input class="vr-in w-20 text-center" dir="ltr" id="vrs-oks" value="${esc(c ? c.ok_suffix : 's')}"></div></div>
                <div><label class="vr-lbl">برچسبِ «خسارتی» / پسوندِ متغیر</label><div class="flex gap-2"><input class="vr-in" id="vrs-badl" value="${esc(c ? c.bad_label : 'خسارتی')}"><input class="vr-in w-20 text-center" dir="ltr" id="vrs-bads" value="${esc(c ? c.bad_suffix : 'k')}"></div></div>
                <div><label class="vr-lbl">علامتِ تیک</label><input class="vr-in text-center" id="vrs-tick" value="${esc(c ? c.tick_mark : '✔')}"></div>
                <div><label class="vr-lbl">ضریبِ اندازه‌ی قلم در PDF</label><input class="vr-in text-center" dir="ltr" id="vrs-fs" value="${esc(o.font_scale || 1)}"></div>
                <div><label class="vr-lbl">فاصله‌ی خطوط</label><input class="vr-in text-center" dir="ltr" id="vrs-lh" value="${esc(o.line_height || 1.1)}"></div>
                <div><label class="vr-lbl">ترتیب نمایش</label><input class="vr-in text-center" dir="ltr" id="vrs-sort" value="${esc(c ? c.sort_order : 0)}"></div>
            </div>
            <label class="flex items-center gap-3 cursor-pointer w-fit"><input type="checkbox" id="vrs-active" class="hidden" ${!c || c.is_active ? 'checked' : ''}><span class="vr-switch"></span><span class="text-xs font-bold text-slate-600">فعال (در صفحه‌ی ساخت نشان داده شود)</span></label>
            <div class="border-t border-slate-100 pt-4 space-y-2"><p class="vr-sec-title !text-xs"><i class="fas fa-images text-cyan-500"></i> عکس‌های بازدید</p>
                <label class="flex items-center gap-3 cursor-pointer w-fit"><input type="checkbox" id="vrs-ph-req" class="hidden" ${o.photos_required === undefined || Number(o.photos_required) ? 'checked' : ''}><span class="vr-switch"></span><span class="text-xs font-bold text-slate-600">بارگذاریِ عکس بازدید برای صدورِ این نوع گزارش <b>اجباری</b> است</span></label>
                <label class="flex items-center gap-3 cursor-pointer w-fit mr-8" id="vrs-ph-nowrap"><input type="checkbox" id="vrs-ph-none" class="hidden" ${Number(o.allow_no_photos) ? 'checked' : ''}><span class="vr-switch"></span><span class="text-xs font-bold text-slate-600">کاربر دکمه‌ی <b>«عکس بازدید ندارم»</b> را داشته باشد (با زدنش بدونِ عکس صادر می‌شود)</span></label>
                <p class="text-[10px] text-slate-400 font-bold">اگر اجباری نباشد، گزارش بدونِ عکس هم صادر می‌شود و آن دکمه لازم نیست. «ساخت تستی گزارش» همیشه بدونِ عکس کار می‌کند.</p></div>
            <div class="border-t border-slate-100 pt-4"><p class="vr-sec-title !text-xs mb-2"><i class="fas fa-key text-amber-500"></i> چه کسانی می‌توانند این نوع گزارش را صادر کنند؟</p>
                <p class="text-[10px] text-slate-400 font-bold mb-2">مدیر کل همیشه دسترسی دارد. کاربرانِ دیگر فقط صدور/دیدن/ویرایشِ گزارش‌های خودشان را دارند؛ تنظیمات فقط برای مدیر کل است.</p>
                <div class="flex flex-wrap gap-2 mb-3">${Object.entries(roles).map(([k, v]) => `<label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-600 bg-slate-50 rounded-lg px-2.5 py-1.5 cursor-pointer"><input type="checkbox" class="accent-indigo-600" name="vrs-role" value="${k}" ${acc.roles.includes(k) || k === 'ADMIN' ? 'checked' : ''} ${k === 'ADMIN' ? 'disabled' : ''}> همه‌ی «${esc(v)}»ها</label>`).join('')}</div>
                <p class="text-[11px] font-bold text-slate-500 mb-1">یا کاربرانِ مشخص:</p>
                <div class="flex flex-wrap gap-2 max-h-40 overflow-y-auto">${S.boot.users.filter(u => u.role !== 'ADMIN').map(u => `<label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-600 bg-slate-50 rounded-lg px-2 py-1 cursor-pointer"><input type="checkbox" class="accent-indigo-600" name="vrs-user" value="${u.id}" ${acc.users.includes(Number(u.id)) ? 'checked' : ''}> ${esc(u.full_name)} <span class="text-slate-400">(${esc(roles[u.role] || u.role)})</span></label>`).join('') || '<span class="text-[11px] text-slate-400">کاربرِ دیگری نیست.</span>'}</div>
            </div>
            <div class="flex flex-wrap gap-2 pt-2">
                <button type="button" class="vr-btn vr-btn-p vrs-save"><i class="fas fa-floppy-disk"></i> ذخیره</button>
                ${c ? `<button type="button" class="vr-btn vr-btn-s vrs-dup"><i class="fas fa-copy"></i> کپی از این نوع (برای ساختِ نوعِ مشابه)</button>
                <button type="button" class="vr-btn vr-btn-r vrs-del mr-auto"><i class="fas fa-trash"></i> حذف</button>` : ''}
            </div></div>`;
    }
    function bindGeneral(box, c, body) {
        const g = id => box.querySelector('#' + id);
        box.querySelector('.vrs-save').onclick = async () => {
            const d = await api('set_cat_save', {
                id: c ? c.id : 0, name: g('vrs-name').value, insurer: g('vrs-insurer').value, ok_label: g('vrs-okl').value, ok_suffix: g('vrs-oks').value,
                bad_label: g('vrs-badl').value, bad_suffix: g('vrs-bads').value, tick_mark: g('vrs-tick').value, is_active: g('vrs-active').checked ? 1 : 0,
                sort_order: Number(en(g('vrs-sort').value)) || 0, options: { description: g('vrs-desc').value, font_scale: en(g('vrs-fs').value), line_height: en(g('vrs-lh').value),
                                                                        photos_required: g('vrs-ph-req').checked ? 1 : 0, allow_no_photos: g('vrs-ph-none').checked ? 1 : 0 },
                access: { roles: checkedVals(box, 'vrs-role'), users: checkedVals(box, 'vrs-user') }
            });
            if (!d.ok) return toast(d.error, 'error');
            toast('ذخیره شد.', 'info');
            S.catId = d.category.id;
            if (!c) S.catTab = 'template';
            await loadBoot(); renderTypes(body);
        };
        const dup = box.querySelector('.vrs-dup');
        if (dup) dup.onclick = async () => { const d = await api('set_cat_duplicate', { id: c.id }); if (!d.ok) return toast(d.error, 'error'); toast('کپی ساخته شد (غیرفعال)؛ نام و قالبش را عوض کنید.', 'info'); S.catId = d.id; S.catTab = 'general'; await loadBoot(); renderTypes(body); };
        const del = box.querySelector('.vrs-del');
        if (del) del.onclick = async () => {
            if (!await confirmBox('حذفِ نوع گزارش', `«${c.name}» و همه‌ی فیلدهایش حذف شود؟ (اگر گزارشی با این نوع صادر شده باشد، فقط می‌شود غیرفعالش کرد.)`, { danger: true, ok: 'حذف' })) return;
            const d = await api('set_cat_delete', { id: c.id }); if (!d.ok) return toast(d.error, 'error');
            S.catId = null; await loadBoot(); renderTypes(body);
        };
    }

    // ---- قالب Word ----
    function renderTemplate(sb, cat, body) {
        const t = cat.template;
        const chips = (arr, cls) => arr.length ? arr.map(v => `<span class="vr-chip ${cls}" dir="ltr">${esc(v)}</span>`).join(' ') : '<span class="text-[11px] text-slate-400 font-bold">—</span>';
        sb.innerHTML = `<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="vr-card p-5 vr-fade-up space-y-3">
                <p class="vr-sec-title !text-xs"><i class="fas fa-file-word text-blue-600"></i> قالبِ فعلی</p>
                ${t.exists ? `<p class="text-xs font-bold text-slate-600">${esc(t.orig_name || '')} · ${fa(t.vars.length)} متغیر</p>` : '<p class="text-xs font-bold text-red-500">هنوز قالبی بارگذاری نشده است.</p>'}
                <label class="vr-drop p-5 flex flex-col items-center gap-2 cursor-pointer text-center" data-drop="docx"><i class="fas fa-cloud-arrow-up text-2xl text-indigo-400"></i>
                    <p class="text-xs font-black text-slate-600">بارگذاریِ قالبِ Word (docx)</p><p class="text-[10px] text-slate-400 font-bold">قالبِ قبلی با «(old)» کنار گذاشته می‌شود</p><input type="file" accept=".docx" class="hidden vrs-tpl-in"></label>
                <p class="vrs-tpl-st text-[11px] font-bold"></p>
                ${t.exists ? `<div class="flex flex-wrap gap-2">
                    <a class="vr-btn vr-btn-p" target="_blank" href="${API}?action=set_template_preview&id=${cat.id}"><i class="fas fa-eye"></i> پیش‌نمایش با دادهٔ نمونه</a>
                    <a class="vr-btn vr-btn-s" target="_blank" href="${API}?action=set_template_preview&id=${cat.id}&names=1&debug=1"><i class="fas fa-vector-square"></i> نامِ متغیرها + کادرها</a>
                    <a class="vr-btn vr-btn-s" href="${API}?action=set_template_download&id=${cat.id}"><i class="fas fa-download"></i> دانلودِ قالب</a>
                    <button type="button" class="vr-btn vr-btn-s vrs-reanalyze"><i class="fas fa-rotate"></i> خواندنِ دوباره</button></div>` : ''}
            </div>
            <div class="vr-card p-5 vr-fade-up space-y-3">
                <p class="vr-sec-title !text-xs"><i class="fas fa-stethoscope text-emerald-500"></i> بررسیِ هماهنگیِ قالب و فیلدها</p>
                <div><p class="text-[11px] font-black text-red-500 mb-1">در قالب هست ولی هیچ فیلدی پُرش نمی‌کند (در PDF خالی می‌ماند):</p>${chips(t.unfilled, 'bg-red-50 text-red-600')}</div>
                <div><p class="text-[11px] font-black text-amber-600 mb-1">فیلد هست ولی جایی در قالب ندارد (در PDF چاپ نمی‌شود):</p>${chips(t.unused, 'bg-amber-50 text-amber-700')}</div>
                <details><summary class="text-[11px] font-black text-slate-500 cursor-pointer">همه‌ی متغیرهای قالب (${fa(t.vars.length)})</summary><div class="mt-2 flex flex-wrap gap-1">${chips(t.vars, 'bg-slate-100 text-slate-600')}</div></details>
            </div></div>
            ${t.exists ? `<div class="vr-card p-5 mt-4 vr-fade-up flex flex-wrap items-center gap-4">
                <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-600 text-white flex items-center justify-center text-xl shadow-lg"><i class="fas fa-object-group"></i></span>
                <div class="flex-1 min-w-[14rem]"><p class="font-black text-slate-700 text-sm">ویرایشگرِ گرافیکیِ کادرها</p>
                <p class="text-[11px] text-slate-500 font-bold leading-6">صفحه‌ی فرم را ببینید و کادرها را با موس (تکی یا چندتایی) جابه‌جا کنید؛ عددِ افقی/عمودی خودکار نوشته می‌شود. قلم، اندازه و متن/متغیرِ هر کادر، و یک قلم برای همه‌ی کادرها هم همین‌جاست.</p></div>
                <button type="button" class="vr-btn vr-btn-p vrs-editor"><i class="fas fa-pen-ruler"></i> بازکردنِ ویرایشگر</button></div>` : ''}`;
        const inp = sb.querySelector('.vrs-tpl-in');
        const upload = async file => {
            const stEl = sb.querySelector('.vrs-tpl-st');
            stEl.className = 'vrs-tpl-st text-[11px] font-bold text-indigo-500'; stEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال بارگذاری و خواندنِ قالب...';
            const fd = new FormData(); fd.append('action', 'set_template_upload'); fd.append('id', cat.id); fd.append('file', file);
            const d = await apiForm(fd);
            if (!d.ok) { stEl.className = 'vrs-tpl-st text-[11px] font-bold text-red-500'; stEl.textContent = d.error; return; }
            toast(`قالب خوانده شد: ${fa(d.pages)} صفحه${d.warnings.length ? ' · ' + d.warnings.join('، ') : ''}`, 'info');
            await loadBoot(); renderTypes(body);
        };
        inp.onchange = () => { if (inp.files[0]) upload(inp.files[0]); };
        const z = sb.querySelector('[data-drop="docx"]');
        ['dragenter', 'dragover'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.add('over'); }));
        ['dragleave', 'drop'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.remove('over'); }));
        z.addEventListener('drop', e => { if (e.dataTransfer.files[0]) upload(e.dataTransfer.files[0]); });
        const re = sb.querySelector('.vrs-reanalyze');
        if (re) re.onclick = async () => { const d = await api('set_template_reanalyze', { id: cat.id }); if (!d.ok) return toast(d.error, 'error'); toast('قالب دوباره خوانده شد.', 'info'); await loadBoot(); renderTypes(body); };
        const ed = sb.querySelector('.vrs-editor');
        if (ed) ed.onclick = () => { if (VR.openLayoutEditor) VR.openLayoutEditor(cat, async () => { await loadBoot(); }); else toast('فایلِ visit-reports-editor.js بارگذاری نشده است.', 'error'); };
    }

    // ---- فیلدها ----
    function renderFields(sb, cat, body) {
        const types = S.boot.field_types, cols = S.boot.excel_columns;
        let rows = cat.fields.map(f => ({ ...f }));
        const draw = () => {
            sb.innerHTML = `<div class="vr-card p-5 vr-fade-up">
                <div class="flex items-center justify-between gap-2 flex-wrap mb-2"><p class="vr-sec-title !text-xs"><i class="fas fa-table-list text-indigo-500"></i> فیلدهای «${esc(cat.name)}» (${fa(rows.length)})</p>
                <div class="flex gap-2"><button type="button" class="vr-btn vr-btn-s !py-1.5 !text-[11px] vrs-f-add"><i class="fas fa-plus"></i> فیلدِ جدید</button>
                <button type="button" class="vr-btn vr-btn-p !py-1.5 !text-[11px] vrs-f-save"><i class="fas fa-floppy-disk"></i> ذخیره‌ی همه</button></div></div>
                <p class="text-[10px] text-slate-400 font-bold mb-4 leading-5">«نامِ متغیر» همان چیزی است که در قالبِ Word داخلِ {{ }} نوشته شده. پلاک، بیمه‌گذار و تاریخ خودکار پُر می‌شوند و فیلد لازم ندارند (راهنما را ببینید).</p>
                <div class="space-y-2 vrs-flist">${rows.map((f, i) => fieldRowHtml(f, i, types, cols, rows.length)).join('')}</div></div>`;
            sb.querySelectorAll('[data-fi]').forEach(card => {
                const i = Number(card.dataset.fi);
                card.querySelectorAll('[data-p]').forEach(inp => {
                    const ev = inp.type === 'checkbox' || inp.tagName === 'SELECT' ? 'change' : 'input';
                    inp.addEventListener(ev, () => { rows[i][inp.dataset.p] = inp.type === 'checkbox' ? (inp.checked ? 1 : 0) : inp.value; if (inp.dataset.p === 'field_type') draw(); });
                });
                const act = (sel, fn) => { const b = card.querySelector(sel); if (b) b.onclick = fn; };
                act('.vrs-up', () => { if (i > 0) { [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]]; draw(); } });
                act('.vrs-down', () => { if (i < rows.length - 1) { [rows[i + 1], rows[i]] = [rows[i], rows[i + 1]]; draw(); } });
                act('.vrs-del', () => { rows.splice(i, 1); draw(); });
                act('.vrs-more', () => card.querySelector('.vrs-adv').classList.toggle('hidden'));
            });
            sb.querySelector('.vrs-f-add').onclick = () => { rows.push({ field_key: '', label: '', field_type: 'Entry', options: '', section: rows.length ? rows[rows.length - 1].section : '', _new: 1 }); draw(); const l = sb.querySelectorAll('[data-fi]'); l[l.length - 1].scrollIntoView({ behavior: 'smooth', block: 'center' }); };
            sb.querySelector('.vrs-f-save').onclick = async () => {
                const d = await api('set_fields_save', { id: cat.id, fields: rows });
                if (!d.ok) return toast(d.error, 'error');
                toast('فیلدها ذخیره شد.', 'info');
                await loadBoot(); renderTypes(body);
            };
        };
        draw();
    }
    function fieldRowHtml(f, i, types, cols, n) {
        const t = f.field_type;
        const optHint = { Combobox: 'گزینه‌ها با ویرگول: شخصی,عمومی,دولتی', PartsStatus: 'قطعات: c1|شیشه جلو; c2|سپر جلو (شناسه|نام) - برچسبِ دلخواه: p48|رادیو|دارد|ندارد - «مقدار پیش‌فرض» = none یعنی هیچ‌کدام تیک نخورد', DamageList: 'حداکثر تعدادِ ردیف (مثلاً ۵)' }[t];
        const inp = (p, ph, cls = '', dir = '') => `<input class="vr-in !py-1.5 !text-xs ${cls}" ${dir ? `dir="${dir}"` : ''} data-p="${p}" value="${esc(f[p] ?? '')}" placeholder="${ph}">`;
        const hasAdv = f.excel_column || f.parser_keys || f.show_if || f.tick_map || f.words_var || f.default_value || f.help || f.width || f.max_chars;
        return `<div class="rounded-2xl border ${f._new ? 'border-indigo-300 bg-indigo-50/40' : 'border-slate-100 bg-slate-50/50'} p-3" data-fi="${i}">
            <div class="grid grid-cols-12 gap-2 items-center">
                <div class="col-span-12 md:col-span-1 flex md:flex-col gap-1 items-center"><span class="text-[10px] font-black text-slate-300">${fa(i + 1)}</span>
                    <button type="button" class="vrs-up text-slate-300 hover:text-indigo-500 ${i === 0 ? 'invisible' : ''}"><i class="fas fa-chevron-up text-[10px]"></i></button><button type="button" class="vrs-down text-slate-300 hover:text-indigo-500 ${i === n - 1 ? 'invisible' : ''}"><i class="fas fa-chevron-down text-[10px]"></i></button></div>
                <div class="col-span-6 md:col-span-2"><label class="vr-lbl !text-[10px]">نامِ متغیر</label>${inp('field_key', 'noecar', 'font-mono', 'ltr')}</div>
                <div class="col-span-6 md:col-span-3"><label class="vr-lbl !text-[10px]">برچسب</label>${inp('label', 'نوع وسیله نقلیه')}</div>
                <div class="col-span-6 md:col-span-2"><label class="vr-lbl !text-[10px]">نوع</label><select class="vr-in !py-1.5 !text-xs" data-p="field_type">${Object.entries(types).map(([k, v]) => `<option value="${k}" ${k === t ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
                <div class="col-span-6 md:col-span-2"><label class="vr-lbl !text-[10px]">بخش (گروه در فرم)</label>${inp('section', 'مشخصات خودرو')}</div>
                <div class="col-span-12 md:col-span-2 flex items-end gap-1.5 justify-end">
                    <label class="flex items-center gap-1 text-[10px] font-bold text-slate-500 ml-1"><input type="checkbox" class="accent-red-500" data-p="required" ${Number(f.required) ? 'checked' : ''}> اجباری</label>
                    <button type="button" class="vrs-more w-8 h-8 rounded-lg ${hasAdv ? 'bg-violet-100 text-violet-600' : 'bg-white text-slate-400'} border border-slate-200" title="تنظیماتِ بیشتر"><i class="fas fa-sliders text-xs"></i></button>
                    <button type="button" class="vrs-del w-8 h-8 rounded-lg bg-white border border-slate-200 text-slate-400 hover:text-red-500"><i class="fas fa-trash text-xs"></i></button></div>
                ${optHint || t === 'Combobox' ? `<div class="col-span-12"><label class="vr-lbl !text-[10px]">${optHint}</label><textarea class="vr-in !py-1.5 !text-xs" rows="${t === 'PartsStatus' ? 3 : 1}" data-p="options">${esc(f.options || '')}</textarea></div>` : ''}
            </div>
            <div class="vrs-adv hidden grid grid-cols-12 gap-2 mt-2 pt-2 border-t border-slate-100">
                <div class="col-span-6 md:col-span-3"><label class="vr-lbl !text-[10px]">ستونِ دفترِ اکسل / جستجو</label><select class="vr-in !py-1.5 !text-xs" data-p="excel_column"><option value="">—</option>${cols.map(c => `<option ${c === f.excel_column ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></div>
                <div class="col-span-6 md:col-span-3"><label class="vr-lbl !text-[10px]">کلیدهای پارسر (با ویرگول)</label>${inp('parser_keys', 'vehicle_type,noecar', 'font-mono', 'ltr')}</div>
                <div class="col-span-6 md:col-span-3"><label class="vr-lbl !text-[10px]">شرطِ نمایش</label>${inp('show_if', 'has_yadak=1', 'font-mono', 'ltr')}</div>
                <div class="col-span-6 md:col-span-3"><label class="vr-lbl !text-[10px]">حداکثر کاراکتر</label>${inp('max_chars', '30', 'text-center', 'ltr')}</div>
                <div class="col-span-12 md:col-span-6"><label class="vr-lbl !text-[10px]">تیک برای هر گزینه (گزینه=متغیر; ...)</label>${inp('tick_map', 'شخصی=plate_personal; عمومی=plate_public')}</div>
                <div class="col-span-6 md:col-span-2"><label class="vr-lbl !text-[10px]">متغیرِ «به حروف»</label>${inp('words_var', 'arzesh_words', 'font-mono', 'ltr')}</div>
                <div class="col-span-6 md:col-span-2"><label class="vr-lbl !text-[10px]">مقدارِ پیش‌فرض</label>${inp('default_value', '')}</div>
                <div class="col-span-6 md:col-span-2"><label class="vr-lbl !text-[10px]">عرض در فرم</label><select class="vr-in !py-1.5 !text-xs" data-p="width"><option value="">خودکار</option><option value="third" ${f.width === 'third' ? 'selected' : ''}>یک‌سوم</option><option value="half" ${f.width === 'half' ? 'selected' : ''}>نصف</option><option value="full" ${f.width === 'full' ? 'selected' : ''}>کامل</option></select></div>
                <div class="col-span-12"><label class="vr-lbl !text-[10px]">راهنمای زیرِ فیلد</label>${inp('help', '')}</div>
            </div></div>`;
    }

    // ---- الگوریتمِ استخراج ----
    async function renderParser(sb, cat, body) {
        const p = cat.parser;
        const files = await api('set_parser_files');
        sb.innerHTML = `<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="vr-card p-5 vr-fade-up space-y-3">
                <p class="vr-sec-title !text-xs"><i class="fab fa-python text-sky-600"></i> الگوریتمِ استخراجِ این نوع</p>
                <p class="text-xs font-bold ${p.exists ? 'text-slate-600' : 'text-slate-400'}">${p.exists ? esc(p.orig_name) : 'فقط الگوریتمِ عمومی (بدونِ فایلِ اختصاصی)'}</p>
                <div class="flex gap-2"><select class="vr-in !py-2 vrs-pfile"><option value="">فقط الگوریتمِ عمومی</option>${(files.files || []).map(f => `<option ${p.orig_name === f ? 'selected' : ''}>${esc(f)}</option>`).join('')}</select>
                <button type="button" class="vr-btn vr-btn-s shrink-0 vrs-psel">اعمال</button></div>
                <label class="vr-drop p-4 flex flex-col items-center gap-1.5 cursor-pointer text-center"><i class="fas fa-file-code text-2xl text-sky-500"></i>
                    <p class="text-xs font-black text-slate-600">بارگذاریِ فایلِ .py</p><p class="text-[10px] text-slate-400 font-bold">با همان نامِ اصلی ذخیره می‌شود (پارسرها با نام همدیگر را صدا می‌زنند)؛ نسخه‌ی قبلی (old) می‌شود</p>
                    <input type="file" accept=".py" class="hidden vrs-py-in"></label>
                <p class="vrs-py-st text-[11px] font-bold"></p>
                ${p.exists ? `<a class="vr-btn vr-btn-s" href="${API}?action=set_parser_download&id=${cat.id}"><i class="fas fa-download"></i> دانلودِ فایلِ فعلی</a>` : ''}
                <p class="text-[10px] text-slate-400 font-bold leading-5">فایل باید تابعِ <code dir="ltr">parse_pdf_fields(raw_text)</code> داشته باشد و یک dict برگرداند. کلیدهایی که پارسرِ اختصاصی پیدا نکند از الگوریتمِ عمومی پُر می‌شود.</p>
            </div>
            <div class="vr-card p-5 vr-fade-up space-y-3">
                <p class="vr-sec-title !text-xs"><i class="fas fa-flask text-violet-500"></i> آزمایش با یک PDF</p>
                <label class="vr-drop p-4 flex flex-col items-center gap-1.5 cursor-pointer text-center"><i class="fas fa-file-pdf text-2xl text-rose-500"></i><p class="text-xs font-black text-slate-600">انتخابِ PDF برای آزمایش</p><input type="file" accept=".pdf" class="hidden vrs-pt-in"></label>
                <div class="vrs-pt-out text-[11px]"></div>
            </div></div>`;
        sb.querySelector('.vrs-psel').onclick = async () => { const d = await api('set_parser_select', { id: cat.id, name: sb.querySelector('.vrs-pfile').value }); if (!d.ok) return toast(d.error, 'error'); toast('اعمال شد.', 'info'); await loadBoot(); renderTypes(body); };
        const pyIn = sb.querySelector('.vrs-py-in');
        pyIn.onchange = async () => {
            const f = pyIn.files[0]; if (!f) return;
            const st = sb.querySelector('.vrs-py-st'); st.className = 'vrs-py-st text-[11px] font-bold text-indigo-500'; st.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال بررسی...';
            const fd = new FormData(); fd.append('action', 'set_parser_upload'); fd.append('id', cat.id); fd.append('file', f);
            const d = await apiForm(fd);
            if (!d.ok) { st.className = 'vrs-py-st text-[11px] font-bold text-red-500 whitespace-pre-wrap'; st.textContent = d.error; return; }
            toast('الگوریتم ذخیره شد' + (d.shared_with.length ? ` (برای «${d.shared_with.join('، ')}» هم استفاده می‌شود)` : ''), 'info');
            await loadBoot(); renderTypes(body);
        };
        const ptIn = sb.querySelector('.vrs-pt-in');
        ptIn.onchange = async () => {
            const f = ptIn.files[0]; if (!f) return;
            const out = sb.querySelector('.vrs-pt-out'); out.innerHTML = '<p class="text-indigo-500 font-bold"><i class="fas fa-spinner fa-spin"></i> در حال اجرا...</p>';
            const fd = new FormData(); fd.append('action', 'set_parser_test'); fd.append('id', cat.id); fd.append('file', f);
            const d = await apiForm(fd);
            ptIn.value = '';
            if (!d.ok) { out.innerHTML = `<p class="text-red-500 font-bold">${esc(d.error)}</p>${d.debug ? `<pre class="bg-slate-900 text-slate-100 rounded-xl p-3 mt-2 overflow-x-auto text-[10px]" dir="ltr">${esc(d.debug)}</pre>` : ''}`; return; }
            const data = d.data || {};
            out.innerHTML = `<p class="font-bold text-emerald-600 mb-2"><i class="fas fa-circle-check"></i> ${fa(Object.keys(data).length)} کلید · ${esc(d.parser_used)} · ${esc(d.method)} · ${fa(d.seconds)} ثانیه</p>
                ${d.parser_error ? `<p class="text-amber-600 font-bold mb-2">خطای پارسرِ اختصاصی: ${esc(d.parser_error)}</p>` : ''}
                <table class="w-full"><tbody>${Object.entries(data).map(([k, v]) => `<tr class="border-t border-slate-50"><td class="p-1 font-mono text-slate-400" dir="ltr">${esc(k)}</td><td class="p-1 font-bold text-slate-700">${esc(v)}</td></tr>`).join('')}</tbody></table>
                ${(d.checked || []).length ? `<details class="mt-2"><summary class="font-black text-slate-500 cursor-pointer">چک‌باکس‌های علامت‌خورده‌ی PDF (${fa(d.checked.length)})</summary><ul class="list-disc pr-5 mt-1 text-slate-600">${d.checked.map(c => `<li>${esc(c)}</li>`).join('')}</ul></details>` : ''}
                <details class="mt-2"><summary class="font-black text-slate-500 cursor-pointer">متنِ خام (${fa(d.raw_len)} کاراکتر)</summary><pre class="bg-slate-50 rounded-xl p-2 mt-1 max-h-60 overflow-auto whitespace-pre-wrap text-[10px]">${esc(d.raw_preview || '')}</pre></details>`;
        };
    }

    // ------------------------------------------------------------------
    //  بازدیدکننده‌ها
    // ------------------------------------------------------------------
    function renderVisitors(body) {
        const vs = S.boot.visitors;
        body.innerHTML = `<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="vr-card p-5 vr-fade-up space-y-3 h-fit" id="vrs-vform"></div>
            <div class="lg:col-span-2 vr-card p-5 vr-fade-up"><p class="vr-sec-title !text-xs mb-3">بازدیدکننده‌ها (${fa(vs.length)})</p>
                <div class="space-y-2 vr-stagger">${vs.map(v => `<div class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 ${v.is_active ? '' : 'opacity-50'}">
                    <span class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-400 to-emerald-500 text-white flex items-center justify-center"><i class="fas fa-user-check text-xs"></i></span>
                    <div class="flex-1 min-w-0"><p class="text-xs font-black text-slate-700">${esc(v.name)}</p><p class="text-[10px] text-slate-400 font-bold truncate">${v.categories.length ? v.categories.map(id => esc((S.boot.categories.find(c => c.id === id) || {}).name || '#' + id)).join('، ') : 'همه‌ی انواع گزارش'}</p></div>
                    <button type="button" class="vr-btn vr-btn-s !py-1 !text-[11px]" data-ve="${v.id}">ویرایش</button><button type="button" class="vr-btn vr-btn-r !py-1 !text-[11px]" data-vd="${v.id}"><i class="fas fa-trash"></i></button></div>`).join('') || '<p class="text-xs text-slate-400 font-bold">هنوز بازدیدکننده‌ای تعریف نشده.</p>'}</div></div></div>`;
        const form = v => {
            const f = body.querySelector('#vrs-vform');
            f.innerHTML = `<p class="vr-sec-title !text-xs">${v ? 'ویرایشِ بازدیدکننده' : 'بازدیدکننده‌ی جدید'}</p>
                <div><label class="vr-lbl">نام</label><input class="vr-in vrs-vn" value="${esc(v ? v.name : '')}"></div>
                <div><label class="vr-lbl">برای کدام انواع گزارش؟ (هیچ‌کدام = همه)</label>${catsCheck('vrs-vc', v ? v.categories : [])}</div>
                <label class="flex items-center gap-3 cursor-pointer w-fit"><input type="checkbox" class="hidden vrs-va" ${!v || v.is_active ? 'checked' : ''}><span class="vr-switch"></span><span class="text-xs font-bold text-slate-600">فعال</span></label>
                <div class="flex gap-2"><button type="button" class="vr-btn vr-btn-p flex-1 vrs-vsave"><i class="fas fa-floppy-disk"></i> ذخیره</button>${v ? '<button type="button" class="vr-btn vr-btn-s vrs-vcancel">انصراف</button>' : ''}</div>`;
            f.querySelector('.vrs-vsave').onclick = async () => {
                const d = await api('set_visitor_save', { id: v ? v.id : 0, name: f.querySelector('.vrs-vn').value, is_active: f.querySelector('.vrs-va').checked ? 1 : 0, categories: checkedVals(f, 'vrs-vc') });
                if (!d.ok) return toast(d.error, 'error'); toast('ذخیره شد.', 'info'); await loadBoot(); renderVisitors(body);
            };
            const c = f.querySelector('.vrs-vcancel'); if (c) c.onclick = () => form(null);
        };
        form(null);
        body.querySelectorAll('[data-ve]').forEach(b => b.onclick = () => form(vs.find(v => v.id === Number(b.dataset.ve))));
        body.querySelectorAll('[data-vd]').forEach(b => b.onclick = async () => {
            if (!await confirmBox('حذفِ بازدیدکننده', 'از فهرست حذف شود؟ (نامش روی گزارش‌های قبلی می‌ماند)', { danger: true, ok: 'حذف' })) return;
            const d = await api('set_visitor_delete', { id: Number(b.dataset.vd) }); if (!d.ok) return toast(d.error, 'error'); await loadBoot(); renderVisitors(body);
        });
    }

    // ------------------------------------------------------------------
    //  بیمه‌گذاران
    // ------------------------------------------------------------------
    function renderInsureds(body) {
        const list = S.boot.insureds;
        body.innerHTML = `<div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
            <div class="lg:col-span-2 vr-card p-5 vr-fade-up space-y-3 h-fit" id="vrs-iform"></div>
            <div class="lg:col-span-3 vr-card p-5 vr-fade-up"><div class="flex items-center justify-between gap-2 mb-3"><p class="vr-sec-title !text-xs">بیمه‌گذارانِ آماده (${fa(list.length)})</p><input class="vr-in !py-1.5 !text-xs w-48 vrs-iq" placeholder="🔍 جستجو"></div>
                <div class="space-y-2 vrs-ilist"></div></div></div>`;
        const drawList = () => {
            const q = body.querySelector('.vrs-iq').value.trim();
            body.querySelector('.vrs-ilist').innerHTML = list.filter(i => !q || i.name.includes(q) || String(i.national_id || '').includes(en(q))).map(i => `<div class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 ${i.is_active ? '' : 'opacity-50'}">
                <span class="w-9 h-9 rounded-xl ${i.source === 'COMPANY' ? 'bg-violet-500' : i.source === 'PERSON' ? 'bg-sky-500' : 'bg-slate-400'} text-white flex items-center justify-center"><i class="fas ${i.source === 'COMPANY' ? 'fa-building' : 'fa-user'} text-xs"></i></span>
                <div class="flex-1 min-w-0"><p class="text-xs font-black text-slate-700">${esc(i.name)} <span class="vr-chip bg-white text-slate-400">${i.source === 'COMPANY' ? 'از شرکت‌ها' : i.source === 'PERSON' ? 'از اشخاص' : 'دستی'}</span></p>
                <p class="text-[10px] text-slate-400 font-bold truncate" dir="ltr" style="text-align:right">${fa(i.national_id || '')} ${i.phone ? '· ' + fa(i.phone) : ''}</p>
                <p class="text-[10px] text-slate-400 font-bold truncate">${i.categories.length ? i.categories.map(id => esc((S.boot.categories.find(c => c.id === id) || {}).name || '')).join('، ') : 'همه‌ی انواع گزارش'}</p></div>
                <button type="button" class="vr-btn vr-btn-s !py-1 !text-[11px]" data-ie="${i.id}">ویرایش</button><button type="button" class="vr-btn vr-btn-r !py-1 !text-[11px]" data-id="${i.id}"><i class="fas fa-trash"></i></button></div>`).join('') || '<p class="text-xs text-slate-400 font-bold">موردی نیست.</p>';
            body.querySelectorAll('[data-ie]').forEach(b => b.onclick = () => form(list.find(x => x.id === Number(b.dataset.ie))));
            body.querySelectorAll('[data-id]').forEach(b => b.onclick = async () => {
                if (!await confirmBox('حذفِ بیمه‌گذار', 'از فهرستِ آماده حذف شود؟ (گزارش‌های قبلی دست نمی‌خورند)', { danger: true, ok: 'حذف' })) return;
                const d = await api('set_insured_delete', { id: Number(b.dataset.id) }); if (!d.ok) return toast(d.error, 'error'); await loadBoot(); renderInsureds(body);
            });
        };
        body.querySelector('.vrs-iq').oninput = drawList;
        const form = v => {
            const f = body.querySelector('#vrs-iform');
            let src = v ? v.source : 'MANUAL', srcId = v ? v.source_id : null;
            f.innerHTML = `<p class="vr-sec-title !text-xs">${v ? 'ویرایشِ بیمه‌گذار' : 'بیمه‌گذارِ جدید'}</p>
                <div class="relative"><label class="vr-lbl">انتخاب از اشخاص و شرکت‌های موجود (اختیاری)</label><input class="vr-in vrs-is" placeholder="🔍 نام، کد ملی یا شناسه‌ی شرکت...">
                    <div class="vrs-idd absolute inset-x-0 mt-1 max-h-64 overflow-y-auto bg-white rounded-xl shadow-2xl border border-slate-100 z-30 hidden"></div></div>
                <p class="vrs-isrc text-[10px] font-bold text-emerald-600 ${src !== 'MANUAL' ? '' : 'hidden'}"><i class="fas fa-link"></i> <span>${src === 'COMPANY' ? 'از شرکت‌ها' : src === 'PERSON' ? 'از اشخاص' : ''}</span> · <button type="button" class="underline vrs-iunlink">جدا کردن (دستی)</button></p>
                <div><label class="vr-lbl">نام بیمه‌گذار</label><input class="vr-in vrs-in" value="${esc(v ? v.name : '')}"></div>
                <div class="grid grid-cols-2 gap-2"><div><label class="vr-lbl">کد ملی / شناسه</label><input class="vr-in vrs-inid" dir="ltr" value="${fa(v ? v.national_id || '' : '')}"></div>
                <div><label class="vr-lbl">تلفن</label><input class="vr-in vrs-iph" dir="ltr" value="${fa(v ? v.phone || '' : '')}"></div></div>
                <div><label class="vr-lbl">آدرس</label><input class="vr-in vrs-iad" value="${esc(v ? v.address || '' : '')}"></div>
                <div><label class="vr-lbl">برای کدام انواع گزارش؟ (هیچ‌کدام = همه)</label>${catsCheck('vrs-ic', v ? v.categories : [])}</div>
                <label class="flex items-center gap-3 cursor-pointer w-fit"><input type="checkbox" class="hidden vrs-ia" ${!v || v.is_active ? 'checked' : ''}><span class="vr-switch"></span><span class="text-xs font-bold text-slate-600">فعال</span></label>
                <div class="flex gap-2"><button type="button" class="vr-btn vr-btn-p flex-1 vrs-isave"><i class="fas fa-floppy-disk"></i> ذخیره</button>${v ? '<button type="button" class="vr-btn vr-btn-s vrs-icancel">انصراف</button>' : ''}</div>`;
            const s = f.querySelector('.vrs-is'), dd = f.querySelector('.vrs-idd');
            let t = null;
            s.oninput = () => { clearTimeout(t); t = setTimeout(async () => {
                if (s.value.trim().length < 2) { dd.classList.add('hidden'); return; }
                const d = await api('set_insured_search', { q: s.value.trim() });
                dd.innerHTML = (d.results || []).map((r, k) => `<button type="button" class="w-full text-right px-3 py-2 hover:bg-indigo-50 border-b border-slate-50" data-k="${k}"><p class="text-xs font-black text-slate-700">${esc(r.name)} <span class="vr-chip bg-slate-100 text-slate-500">${esc(r.kind)}</span></p><p class="text-[10px] text-slate-400 font-bold" dir="ltr">${fa(r.national_id || '')} ${r.phone ? '· ' + fa(r.phone) : ''}</p></button>`).join('') || '<p class="text-center text-[11px] text-slate-400 p-3 font-bold">پیدا نشد</p>';
                dd.classList.remove('hidden');
                dd.querySelectorAll('[data-k]').forEach(b => b.onclick = () => {
                    const r = d.results[Number(b.dataset.k)];
                    f.querySelector('.vrs-in').value = r.name || ''; f.querySelector('.vrs-inid').value = fa(r.national_id || ''); f.querySelector('.vrs-iph').value = fa(r.phone || ''); f.querySelector('.vrs-iad').value = r.address || '';
                    src = r.source; srcId = r.source_id;
                    const ps = f.querySelector('.vrs-isrc'); ps.classList.toggle('hidden', src === 'MANUAL'); ps.querySelector('span').textContent = src === 'COMPANY' ? 'از شرکت‌ها' : 'از اشخاص';
                    dd.classList.add('hidden'); s.value = '';
                });
            }, 300); };
            f.querySelector('.vrs-iunlink').onclick = () => { src = 'MANUAL'; srcId = null; f.querySelector('.vrs-isrc').classList.add('hidden'); };
            f.querySelector('.vrs-isave').onclick = async () => {
                const d = await api('set_insured_save', { id: v ? v.id : 0, name: f.querySelector('.vrs-in').value, national_id: en(f.querySelector('.vrs-inid').value), phone: en(f.querySelector('.vrs-iph').value),
                    address: f.querySelector('.vrs-iad').value, source: src, source_id: srcId, is_active: f.querySelector('.vrs-ia').checked ? 1 : 0, categories: checkedVals(f, 'vrs-ic') });
                if (!d.ok) return toast(d.error, 'error'); toast('ذخیره شد.', 'info'); await loadBoot(); renderInsureds(body);
            };
            const c = f.querySelector('.vrs-icancel'); if (c) c.onclick = () => form(null);
        };
        form(null); drawList();
    }

    // ------------------------------------------------------------------
    //  قلم‌ها
    // ------------------------------------------------------------------
    function renderFonts(body) {
        const b = S.boot;
        const mapped = b.fonts.map(f => f.word_name);
        body.innerHTML = `<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="vr-card p-5 vr-fade-up space-y-3">
                <p class="vr-sec-title !text-xs"><i class="fas fa-font text-indigo-500"></i> بارگذاریِ قلم</p>
                <p class="text-[10px] text-slate-400 font-bold leading-5">به‌طور پیش‌فرض همه‌ی متن‌های فارسی با «وزیر» و متن‌های لاتین با Helvetica ساخته می‌شوند. اگر می‌خواهید PDF دقیقاً با قلمِ قالب (مثلاً B Nazanin) ساخته شود، فایل TTFِ همان قلم را با همان نامی که در Word انتخاب شده آپلود کنید.</p>
                <div><label class="vr-lbl">نامِ قلم در Word</label><input class="vr-in vrs-fw" list="vrs-wf" placeholder="B Nazanin"><datalist id="vrs-wf">${b.word_fonts.map(w => `<option value="${esc(w)}">`).join('')}</datalist></div>
                <div class="grid grid-cols-2 gap-2"><div><label class="vr-lbl">فایلِ معمولی (TTF)</label><input type="file" accept=".ttf,.otf" class="vr-in !p-1.5 vrs-fr"></div><div><label class="vr-lbl">فایلِ Bold (اختیاری)</label><input type="file" accept=".ttf,.otf" class="vr-in !p-1.5 vrs-fb"></div></div>
                <button type="button" class="vr-btn vr-btn-p vrs-fup"><i class="fas fa-upload"></i> بارگذاری و تبدیل</button>
            </div>
            <div class="vr-card p-5 vr-fade-up space-y-3">
                <p class="vr-sec-title !text-xs">قلم‌هایی که قالب‌ها استفاده کرده‌اند</p>
                <p class="text-[10px] text-slate-400 font-bold leading-5">برای اینکه PDF دقیقاً مثلِ Word شود، قلم‌های نارنجی را (فایلِ TTFِ همان قلم از ویندوز، پوشه‌ی C:\\Windows\\Fonts) با همان نام آپلود کنید؛ روی هرکدام بزنید تا نامش در فرم نوشته شود. مثلاً تیکِ «✔» در Word با قلمِ «Segoe UI Symbol» (فایل seguisym.ttf) کشیده می‌شود.</p>
                <div class="flex flex-wrap gap-1.5">${b.word_fonts.map(w => {
                    const builtin = /^(vazir|vazirmatn)$/i.test(w) ? 'وزیر (همراهِ سایت)' : /^arial( narrow)?$/i.test(w) ? 'جایگزین: Helvetica' : '';
                    const st = mapped.includes(w) ? ['bg-emerald-50 text-emerald-600', '<i class="fas fa-check"></i>', 'آپلود شده'] : builtin ? ['bg-sky-50 text-sky-600', '<i class="fas fa-circle-info"></i>', builtin]
                        : ['bg-amber-50 text-amber-700 cursor-pointer hover:bg-amber-100', '<i class="fas fa-triangle-exclamation"></i>', 'آپلود نشده؛ در PDF با قلمِ جایگزین ساخته می‌شود'];
                    return `<span class="vr-chip ${st[0]}" title="${esc(st[2])}" ${!mapped.includes(w) && !builtin ? `data-wf="${esc(w)}"` : ''}>${st[1]} ${esc(w)}</span>`;
                }).join('') || '—'}</div>
                <p class="vr-sec-title !text-xs pt-3">قلم‌های بارگذاری‌شده</p>
                ${b.fonts.map(f => `<div class="flex items-center gap-2 p-2 rounded-xl bg-slate-50 text-xs font-bold"><i class="fas fa-font text-indigo-400"></i><span class="flex-1">${esc(f.word_name)}${f.bold_file ? ' <span class="vr-chip bg-white text-slate-500">+Bold</span>' : ''}</span><button type="button" class="vr-btn vr-btn-r !py-1 !text-[10px]" data-fd="${f.id}"><i class="fas fa-trash"></i></button></div>`).join('') || '<p class="text-[11px] text-slate-400 font-bold">هنوز قلمی بارگذاری نشده.</p>'}
            </div></div>`;
        body.querySelector('.vrs-fup').onclick = async () => {
            const fr = body.querySelector('.vrs-fr').files[0], fb = body.querySelector('.vrs-fb').files[0];
            if (!fr) return toast('فایلِ معمولیِ قلم را انتخاب کنید.', 'error');
            const fd = new FormData(); fd.append('action', 'set_font_upload'); fd.append('word_name', body.querySelector('.vrs-fw').value); fd.append('regular', fr); if (fb) fd.append('bold', fb);
            const d = await apiForm(fd); if (!d.ok) return toast(d.error, 'error');
            toast('قلم اضافه شد.', 'info'); await loadBoot(); renderFonts(body);
        };
        body.querySelectorAll('[data-wf]').forEach(x => x.onclick = () => { const i = body.querySelector('.vrs-fw'); i.value = x.dataset.wf; i.focus(); i.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
        body.querySelectorAll('[data-fd]').forEach(x => x.onclick = async () => { const d = await api('set_font_delete', { id: Number(x.dataset.fd) }); if (!d.ok) return toast(d.error, 'error'); await loadBoot(); renderFonts(body); });
    }

    // ------------------------------------------------------------------
    //  عمومی
    // ------------------------------------------------------------------
    function renderGeneral(body) {
        const s = S.boot.settings;
        body.innerHTML = `<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="vr-card p-5 vr-fade-up space-y-3">
                <p class="vr-sec-title !text-xs"><i class="fab fa-python text-sky-600"></i> پایتون (فقط برای استخراج از PDF)</p>
                <p class="text-[10px] text-slate-400 font-bold leading-5">ساختِ PDF و Word هیچ نیازی به پایتون، ویندوز یا Word ندارد و کاملاً روی همین هاست انجام می‌شود. پایتون فقط برای «استخراجِ خودکار از PDF» استفاده می‌شود و باید PyMuPDF (fitz) داشته باشد.</p>
                <div><label class="vr-lbl">مسیرِ پایتون روی هاست</label><input class="vr-in vrs-py" dir="ltr" value="${esc(s.python_path)}"></div>
                <div class="flex gap-2"><button type="button" class="vr-btn vr-btn-s vrs-pycheck"><i class="fas fa-stethoscope"></i> بررسی</button></div>
                <pre class="vrs-pyout bg-slate-900 text-emerald-300 rounded-xl p-3 text-[10px] hidden" dir="ltr"></pre>
            </div>
            <div class="vr-card p-5 vr-fade-up space-y-3">
                <p class="vr-sec-title !text-xs"><i class="fas fa-file-excel text-emerald-600"></i> دفترِ اکسلِ گزارشات</p>
                <p class="text-[10px] text-slate-400 font-bold leading-5">فایلِ «گزارشات_صادره.xlsx» در ریشه‌ی «بایگانی گزارشات بازدید» بعد از هر صدور/ویرایش/حذف خودکار به‌روز می‌شود و صفحه‌اش با این رمز قفل است.</p>
                <div><label class="vr-lbl">رمزِ قفلِ اکسل</label><input class="vr-in vrs-xp" dir="ltr" value="${esc(s.excel_password)}"></div>
                <div class="flex gap-2 flex-wrap"><button type="button" class="vr-btn vr-btn-s vrs-reg"><i class="fas fa-rotate"></i> ساختِ دوباره‌ی دفتر</button>
                <button type="button" class="vr-btn vr-btn-s vrs-arch"><i class="fas fa-folder-open text-amber-500"></i> رفتن به بایگانیِ گزارشات</button></div>
            </div>
            <div class="lg:col-span-2"><button type="button" class="vr-btn vr-btn-p vrs-gsave"><i class="fas fa-floppy-disk"></i> ذخیره‌ی تنظیمات</button></div></div>`;
        body.querySelector('.vrs-pycheck').onclick = async () => {
            await api('set_options_save', { python_path: body.querySelector('.vrs-py').value });
            const d = await api('set_python_check');
            const o = body.querySelector('.vrs-pyout'); o.classList.remove('hidden'); o.textContent = d.output || 'خروجی‌ای نیامد (مسیر درست نیست یا اجرای برنامه روی هاست بسته است).';
            o.className = `vrs-pyout ${d.ok ? 'bg-slate-900 text-emerald-300' : 'bg-red-950 text-red-200'} rounded-xl p-3 text-[10px]`;
        };
        body.querySelector('.vrs-reg').onclick = async () => { const d = await api('rebuild_register'); toast(d.ok ? 'دفترِ اکسل دوباره ساخته شد.' : 'ساخت ممکن نشد.', d.ok ? 'info' : 'error'); };
        body.querySelector('.vrs-arch').onclick = () => { if (typeof window.switchTab === 'function') { switchTab('filemanager'); if (typeof window.fmOpen === 'function') fmOpen(S.boot.archive_path); } };
        body.querySelector('.vrs-gsave').onclick = async () => {
            const d = await api('set_options_save', { python_path: body.querySelector('.vrs-py').value, excel_password: body.querySelector('.vrs-xp').value });
            if (!d.ok) return toast(d.error, 'error'); toast('ذخیره شد.', 'info'); await loadBoot();
        };
    }

    // ------------------------------------------------------------------
    //  تاریخچه
    // ------------------------------------------------------------------
    async function renderLogs(body) {
        body.innerHTML = `<div class="vr-card p-5 vr-fade-up"><div class="flex flex-wrap gap-2 mb-4"><input class="vr-in !py-2 flex-1 min-w-[12rem] vrs-lq" placeholder="🔍 جستجو در شرح یا نامِ کاربر">
            <select class="vr-in !py-2 w-48 vrs-lt"><option value="">همه‌ی کارها</option></select></div><div class="vrs-logs"><div class="vr-skel h-40"></div></div></div>`;
        let types = null, t = null;
        const load = async () => {
            const d = await api('set_logs', { q: body.querySelector('.vrs-lq').value, type: body.querySelector('.vrs-lt').value });
            if (!d.ok) return;
            if (!types) { types = d.types; body.querySelector('.vrs-lt').insertAdjacentHTML('beforeend', Object.entries(types).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')); }
            body.querySelector('.vrs-logs').innerHTML = d.logs.length ? `<div class="overflow-x-auto"><table class="w-full text-[11px]"><thead><tr class="text-slate-400 font-bold text-right"><th class="p-2">زمان</th><th class="p-2">کار</th><th class="p-2">کاربر</th><th class="p-2">شرح</th></tr></thead>
                <tbody>${d.logs.map(l => `<tr class="border-t border-slate-50 hover:bg-slate-50 ${l.report_id ? 'cursor-pointer' : ''}" ${l.report_id ? `data-r="${l.report_id}"` : ''}><td class="p-2 font-bold text-slate-400 whitespace-nowrap">${fa(l.at)}</td>
                <td class="p-2"><span class="vr-chip bg-indigo-50 text-indigo-600">${esc(l.action)}</span></td><td class="p-2 font-bold text-slate-600 whitespace-nowrap">${esc(l.by || '')}</td><td class="p-2 text-slate-500 break-all">${esc(l.note || '')}</td></tr>`).join('')}</tbody></table></div>`
                : '<p class="text-center text-xs text-slate-400 font-bold p-6">موردی نیست.</p>';
            body.querySelectorAll('[data-r]').forEach(tr => tr.onclick = () => VR.openDetail(Number(tr.dataset.r)));
        };
        body.querySelector('.vrs-lq').oninput = () => { clearTimeout(t); t = setTimeout(load, 300); };
        body.querySelector('.vrs-lt').onchange = load;
        load();
    }

    // ------------------------------------------------------------------
    //  راهنما
    // ------------------------------------------------------------------
    function renderGuide(body) {
        const step = (n, title, html) => `<div class="flex gap-4"><span class="w-9 h-9 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white font-black flex items-center justify-center shrink-0 shadow-lg shadow-indigo-500/30">${fa(n)}</span>
            <div class="min-w-0"><p class="font-black text-slate-700 text-sm mb-1">${title}</p><div class="text-xs text-slate-600 leading-7 font-medium">${html}</div></div></div>`;
        const code = s => `<code dir="ltr" class="bg-slate-100 text-indigo-700 rounded px-1.5 py-0.5 text-[11px]">${s}</code>`;
        body.innerHTML = `<div class="vr-card p-6 space-y-6 vr-fade-up max-w-4xl">
            <div><p class="font-black text-slate-800 text-base">راهنمای افزودن و تغییرِ انواع گزارش</p>
            <p class="text-xs text-slate-500 font-bold mt-1 leading-6">همه‌ی کارها بدونِ نیاز به ویندوز، Word یا LibreOffice روی همین هاست انجام می‌شود: قالبِ Word خوانده می‌شود، جای هر {{ متغیر }} دقیقاً روی همان کادر می‌نشیند و PDF و Word ساخته می‌شود.</p></div>
            ${step(1, 'ساختِ نوعِ گزارش', `در «انواع گزارش» دکمه‌ی «نوعِ گزارشِ جدید» را بزنید (یا از یک نوعِ مشابه «کپی» بگیرید تا فیلدها و بازدیدکننده‌ها هم بیایند). نام، بیمه‌گر (پوشه‌ی بایگانی)، برچسب‌های سالم/خسارتی و دسترسی‌ها را تنظیم کنید.`)}
            ${step(2, 'آماده‌کردنِ قالبِ Word', `در Word، فرمِ اسکن‌شده را به‌صورت عکس پشتِ متن بگذارید و هر جا باید مقداری چاپ شود یک <b>Text Box</b> بکشید و داخلش متغیر را بنویسید؛ مثل ${code('{{ noecar }}')}. اندازه و قلم و چینشِ متنِ داخلِ کادر همان است که در PDF می‌آید. متغیرهایی که خودکار پر می‌شوند:
                <div class="grid sm:grid-cols-2 gap-x-6 mt-2">${[['name', 'نام بیمه‌گذار'], ['national_id', 'کد ملی'], ['phone_bimeg', 'تلفن'], ['addres_bimeg', 'آدرس'], ['plate_part1', 'دو رقمِ اولِ پلاک'], ['plate_letter', 'حرفِ پلاک'], ['plate_part2', 'سه رقمِ پلاک'], ['plate_part3', 'کدِ ایران'],
                ['date_shamsi', 'تاریخ (۱۴۰۵.۰۷.۰۶)'], ['date_shamsi_slash', 'تاریخ (۱۴۰۵/۰۷/۰۶)'], ['report_no', 'شماره گزارش'], ['issuer_name', 'صادرکننده'], ['arzesh_words', 'ارزش به حروف']].map(([k, v]) => `<p>${code(k)} ${v}</p>`).join('')}</div>`)}
            ${step(3, 'بارگذاری و بررسیِ قالب', `در زبانه‌ی «قالب Word» فایل را آپلود کنید. بخشِ «بررسیِ هماهنگی» نشان می‌دهد کدام متغیرِ قالب فیلدی ندارد (خالی چاپ می‌شود) و کدام فیلد جایی در قالب ندارد. «پیش‌نمایش با دادهٔ نمونه» و «نامِ متغیرها + کادرها» را ببینید؛ در «ویرایشگرِ گرافیکیِ کادرها» می‌توانید کادرها را با موس (تکی یا چندتایی با Ctrl/Shift یا کشیدنِ کادرِ انتخاب) یا کلیدهای جهت جابه‌جا کنید، عددِ افقی/عمودی را دستی بنویسید، برای هر کادر قلم و اندازه و متن/متغیر را عوض کنید یا یک قلم برای همه‌ی کادرها بگذارید.`)}
            ${step(4, 'تعریفِ فیلدها', `هر فیلد یک «نامِ متغیر» دارد که باید با نامِ داخلِ {{ }} قالب یکی باشد. انواع:
                <ul class="list-disc pr-5 mt-1"><li><b>متن / عدد / مبلغ / تاریخ / ساعت / متن چندخطی</b>: مقدار همان‌طور چاپ می‌شود (مبلغ با جداکننده؛ با «متغیرِ به حروف» عددش به حروف هم چاپ می‌شود).</li>
                <li><b>انتخاب از لیست</b>: گزینه‌ها با ویرگول. با «تیک برای هر گزینه» (مثلاً ${code('شخصی=plate_personal; عمومی=plate_public')}) کنارِ گزینه‌ی انتخاب‌شده تیک می‌خورد.</li>
                <li><b>تیک (بله/خیر)</b>: اگر روشن باشد علامتِ تیک چاپ می‌شود؛ با «شرطِ نمایش» فیلدهای دیگر را نشان/پنهان می‌کند (مثلاً ${code('has_yadak=1')}).</li>
                <li><b>چک‌لیستِ قطعات</b>: فهرست به شکلِ ${code('c1|شیشه جلو; c2|سپر جلو')}. برای هر قطعه دو متغیر ساخته می‌شود: ${code('c1_s')} (سالم) و ${code('c1_k')} (خسارتی) - پسوندها در «مشخصات» قابلِ تغییرند. اگر قطعه خسارتی زده شود می‌شود توضیح هم نوشت؛ توضیح در ${code('c1_s_t')}، ${code('c1_k_t')} و ${code('c1_t')} (هر کدام که در قالب گذاشته شود) چاپ می‌شود.</li>
                <li><b>مواضعِ آسیب‌دیده</b>: «گزینه» تعدادِ ردیف است؛ متغیرها ${code('damage_location_1')} و ${code('damage_description_1')} تا آخر.</li>
                <li><b>بازدیدکننده</b>: از فهرستِ «بازدیدکننده‌ها» انتخاب می‌شود.</li></ul>
                «بخش» فیلدها را در فرم گروه می‌کند؛ «ستونِ دفترِ اکسل» مقدار را در دفترِ گزارشات و جستجو می‌آورد؛ «شرطِ نمایش» یعنی فیلد فقط وقتی دیده و چاپ شود که شرط برقرار باشد (مثل ${code('plate_type=سایر')}). برای افزودنِ «یک بخشِ اضافه» کافی است فیلدهایش را با بخشِ تازه اضافه کنید و Text Boxهایش را در قالب بگذارید.`)}
            ${step(5, 'استخراجِ خودکار از PDF (اختیاری)', `بدونِ هیچ فایلی، الگوریتمِ عمومی پلاک، شاسی، موتور، ارزش، نام، کد ملی، تلفن، رنگ و... را پیدا می‌کند. برای دقتِ بیشتر یک فایلِ پایتون با تابعِ ${code('parse_pdf_fields(raw_text)')} بنویسید که dictی با کلیدهای هم‌نامِ فیلدها (یا «کلیدهای پارسر»ِ هر فیلد) برگرداند. فایل‌ها با همان نامِ اصلی کنارِ هم ذخیره می‌شوند، پس پارسری که پارسرِ دیگری را صدا می‌زند (مثل template_truck که template_car را می‌خواند) بدونِ تغییر کار می‌کند. با «آزمایش با یک PDF» نتیجه را ببینید.`)}
            ${step(6, 'بازدیدکننده‌ها و بیمه‌گذاران', `برای هر نوع گزارش بازدیدکننده‌ها را مشخص کنید. بیمه‌گذارانِ پرتکرار را از صفر یا از «اشخاص و شرکت‌های» موجود تعریف کنید تا صادرکننده با یک کلیک انتخاب کند (یا دستی بنویسد).`)}
            ${step(7, 'دسترسی و فعال‌سازی', `نوع را «فعال» کنید و مشخص کنید کدام نقش‌ها/کاربران می‌توانند صادر کنند. «کاربر پارسیان» فقط انواعی را می‌بیند که به او داده شده و فقط گزارش‌های خودش را؛ ویرایش با رمزِ پنلِ خودش انجام می‌شود و فایل‌های قبلی با «(old)» نگه داشته می‌شوند.`)}
            <div class="rounded-2xl bg-indigo-50 p-4 text-xs text-indigo-800 font-bold leading-7"><i class="fas fa-folder-tree ml-1"></i> بایگانی: ${code('بایگانی گزارشات بازدید/سال/ماه/بیمه‌گر/(تاریخ) پلاک - بیمه‌گذار/')} شاملِ «(تاریخ)(گزارش بازدید خودرو) پلاک.pdf/.docx» و پوشه و ZIPِ «(تاریخ)(عکس‌های بازدید خودرو) پلاک». گزارشِ متصل به بازدید سلامت یا درخواست، با همین نام‌گذاری در پوشه‌ی همان درخواست هم قرار می‌گیرد.</div>
        </div>`;
    }

    VR.initSettingsTab = async function () {
        const r = root();
        if (!r) return;
        VR.injectCss();
        if (!S.boot) { r.innerHTML = '<div class="vr-skel h-40"></div>'; if (!await loadBoot()) return; }
        render();
    };
})();
