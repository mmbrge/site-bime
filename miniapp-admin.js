// فایل: miniapp-admin.js
// پنل → تنظیمات «مینی‌اپ کاربران» (ساعتِ کاریِ ثبتِ درخواست، انواعِ وسیله‌ی نقلیه) و مرکز صدور → «استعلام‌ها»
(function () {
    const API = 'api/miniapp_admin.php', VC = 'api/vehicle_catalog.php';
    const fa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = n => n == null || n === '' ? '—' : fa(Number(n).toLocaleString('en-US'));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function post(url, body) {
        try { return await (await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
    }

    // =====================================================================
    //  ساعتِ کاریِ ثبتِ درخواستِ آنلاین
    // =====================================================================
    const DAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    async function hoursCard(el) {
        if (!el) return;
        el.innerHTML = '<p class="text-xs text-slate-400">در حال بارگذاری…</p>';
        const d = await post(API, { action: 'hours_get' });
        if (!d.ok) { el.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        const c = d.cfg;
        el.innerHTML = `
          <label class="flex items-center gap-3 bg-violet-50 border border-violet-100 rounded-xl p-3 mb-3 cursor-pointer">
            <input type="checkbox" data-en ${c.enabled ? 'checked' : ''} class="w-5 h-5 accent-violet-600">
            <span class="text-xs font-bold text-slate-700">محدودیتِ ساعتِ کاری فعال است<small class="block font-normal text-slate-500 mt-0.5">خاموش = ثبتِ درخواست همیشه باز است. روشن = بیرون از ساعت‌های زیر، ثبتِ درخواست و بارگذاریِ مدارک بسته می‌شود و صفحه‌ی متحرکِ «پایانِ ساعتِ کاری» با برنامه‌ی هفته و زمانِ بعدی نشان داده می‌شود.</small></span>
          </label>
          <div class="overflow-x-auto"><table class="w-full text-xs"><thead><tr class="text-slate-500"><th class="p-2 text-right">روز</th><th class="p-2">فعال</th><th class="p-2">از ساعت</th><th class="p-2">تا ساعت</th></tr></thead>
          <tbody>${DAYS.map((n, i) => `<tr class="border-t border-slate-100"><td class="p-2 font-bold text-slate-700">${n}</td>
            <td class="p-2 text-center"><input type="checkbox" data-on="${i}" ${c.days[i].on ? 'checked' : ''} class="w-4 h-4 accent-violet-600"></td>
            <td class="p-2"><input type="time" data-from="${i}" value="${c.days[i].from}" class="border rounded-lg px-2 py-1.5 w-full text-center" dir="ltr"></td>
            <td class="p-2"><input type="time" data-to="${i}" value="${c.days[i].to}" class="border rounded-lg px-2 py-1.5 w-full text-center" dir="ltr"></td></tr>`).join('')}</tbody></table></div>
          <label class="block mt-3"><span class="text-[11px] font-bold text-slate-500">متنِ صفحه‌ی بسته (اختیاری)</span>
            <textarea data-msg rows="2" class="w-full border rounded-xl px-3 py-2 text-xs mt-1" placeholder="خالی = متنِ پیش‌فرض">${esc(c.message || '')}</textarea></label>
          <p data-st class="text-[11px] mt-2 ${d.status.open ? 'text-emerald-700' : 'text-rose-600'} font-bold"></p>
          <button data-save class="w-full mt-3 bg-violet-600 hover:bg-violet-700 text-white font-bold py-3 rounded-xl text-xs"><i class="fas fa-save ml-1"></i>ذخیره‌ی ساعتِ کاری</button>`;
        const paint = st => { const p = el.querySelector('[data-st]'); p.className = `text-[11px] mt-2 font-bold ${st.open ? 'text-emerald-700' : 'text-rose-600'}`;
            p.textContent = !st.enabled ? 'وضعیتِ فعلی: محدودیت خاموش است (ثبتِ درخواست همیشه باز).' : st.open ? 'وضعیتِ فعلی: الان باز است ✅' : `وضعیتِ فعلی: الان بسته است — زمانِ بعدی: ${st.next || '—'}`; };
        paint(d.status);
        el.querySelector('[data-save]').onclick = async () => {
            const cfg = { enabled: el.querySelector('[data-en]').checked ? 1 : 0, message: el.querySelector('[data-msg]').value, days: DAYS.map((n, i) => ({
                on: el.querySelector(`[data-on="${i}"]`).checked ? 1 : 0, from: el.querySelector(`[data-from="${i}"]`).value, to: el.querySelector(`[data-to="${i}"]`).value })) };
            const r = await post(API, { action: 'hours_save', cfg });
            if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
            paint(r.status); toast('ساعتِ کاری ذخیره شد.', 'success');
        };
    }

    // =====================================================================
    //  انواعِ وسیله‌ی نقلیه (نوع ← گروه ← مدل) + رده‌ی استعلام
    // =====================================================================
    const VS = { kind: 'CAR', grp: '', q: '', rate: '', page: 1, data: null };
    async function vehicleCard(el) {
        if (!el) return;
        VS.el = el;
        el.innerHTML = `<div class="flex flex-wrap gap-2 mb-3" data-kinds></div>
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <div class="lg:col-span-1"><div class="text-[11px] font-bold text-slate-500 mb-1">گروه‌ها <small class="font-normal">(رده‌ی استعلامِ ثالث برای کلِ گروه)</small></div><div data-groups class="space-y-1.5 max-h-[520px] overflow-y-auto"></div></div>
            <div class="lg:col-span-2">
              <div class="flex flex-wrap gap-2 mb-2"><input data-q class="border rounded-xl px-3 py-2 text-xs flex-1 min-w-[160px]" placeholder="جستجوی مدل یا کدِ رایانه…">
                <select data-rate class="border rounded-xl px-2 py-2 text-xs"><option value="">همه</option><option value="has">دارای استعلامِ آنلاین</option><option value="none">بدونِ استعلامِ آنلاین</option></select>
                <button data-add class="bg-indigo-600 text-white rounded-xl px-3 py-2 text-xs font-bold"><i class="fas fa-plus ml-1"></i>مدلِ تازه</button>
                <label class="bg-emerald-600 text-white rounded-xl px-3 py-2 text-xs font-bold cursor-pointer"><i class="fas fa-file-excel ml-1"></i>ورود از اکسل<input type="file" data-imp accept=".xlsx" class="hidden"></label></div>
              <div data-rows class="text-xs"></div>
            </div></div>`;
        el.querySelector('[data-q]').oninput = e => { clearTimeout(VS.t); VS.t = setTimeout(() => { VS.q = e.target.value.trim(); VS.page = 1; vLoad(); }, 300); };
        el.querySelector('[data-rate]').onchange = e => { VS.rate = e.target.value; VS.page = 1; vLoad(); };
        el.querySelector('[data-add]').onclick = () => vEdit({ kind: VS.kind, grp: VS.grp, active: 1 });
        el.querySelector('[data-imp]').onchange = async e => {
            const f = e.target.files[0]; e.target.value = ''; if (!f) return;
            const fd = new FormData(); fd.append('action', 'admin_import'); fd.append('kind', VS.kind); fd.append('file', f);
            let r; try { r = await (await fetch(VC + '?action=admin_import', { method: 'POST', body: fd })).json(); } catch (x) { r = { ok: false, error: 'خطا در ارسال.' }; }
            toast(r.ok ? `${fa(r.added)} مدلِ تازه اضافه شد (${fa(r.skipped)} تکراری).` : (r.error || 'خطا'), r.ok ? 'success' : 'error');
            if (r.ok) vLoad();
        };
        vLoad();
    }
    async function vLoad() {
        const el = VS.el;
        const d = await post(VC, { action: 'admin_list', kind: VS.kind, grp: VS.grp, q: VS.q, rate: VS.rate, page: VS.page });
        if (!d.ok) { el.querySelector('[data-rows]').innerHTML = `<p class="text-rose-600">${esc(d.error)}</p>`; return; }
        VS.data = d;
        const catName = c => (d.premium_cats.find(x => x.code == c) || {}).name || '';
        el.querySelector('[data-kinds]').innerHTML = d.kinds.map(k => `<button data-k="${k.key}" class="px-3 py-2 rounded-xl text-xs font-bold border ${k.key === VS.kind ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600'}">${k.icon} ${k.fa}
            <span class="opacity-70">(${fa(d.groups.filter(g => g.kind === k.key).reduce((a, g) => a + Number(g.n), 0))})</span></button>`).join('');
        el.querySelectorAll('[data-k]').forEach(b => b.onclick = () => { VS.kind = b.dataset.k; VS.grp = ''; VS.page = 1; vLoad(); });
        const groups = d.groups.filter(g => g.kind === VS.kind);
        const catOpts = sel => `<option value="">بدونِ استعلام (ارجاع به کارشناس)</option>` + d.premium_cats.map(c => `<option value="${c.code}" ${String(sel) === String(c.code) ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
        el.querySelector('[data-groups]').innerHTML = `<button data-g="" class="w-full text-right px-3 py-2 rounded-xl border text-xs font-bold ${!VS.grp ? 'bg-indigo-50 border-indigo-300 text-indigo-700' : 'bg-white'}">همه‌ی گروه‌ها</button>` +
            groups.map(g => {
                const mixed = g.mn !== g.mx || (Number(g.nonull) > 0 && Number(g.nonull) < Number(g.n));
                return `<div class="rounded-xl border ${VS.grp === g.grp ? 'bg-indigo-50 border-indigo-300' : 'bg-white'} p-2">
                  <button data-g="${esc(g.grp)}" class="w-full text-right text-xs font-bold text-slate-700">${esc(g.grp)} <span class="text-slate-400 font-normal">(${fa(g.n)}${Number(g.act) < Number(g.n) ? ' · ' + fa(Number(g.n) - Number(g.act)) + ' غیرفعال' : ''})</span></button>
                  <select data-gcat="${esc(g.grp)}" class="mt-1 w-full border rounded-lg px-2 py-1 text-[11px]">${mixed ? '<option value="__">— مختلط (هر مدل جدا) —</option>' : ''}${catOpts(mixed ? '__' : (g.mn ?? ''))}</select></div>`; }).join('');
        el.querySelectorAll('[data-g]').forEach(b => b.onclick = () => { VS.grp = b.dataset.g; VS.page = 1; vLoad(); });
        el.querySelectorAll('[data-gcat]').forEach(s => s.onchange = async () => {
            if (s.value === '__') return;
            const r = await post(VC, { action: 'admin_group', kind: VS.kind, grp: s.dataset.gcat, premium_cat: s.value });
            toast(r.ok ? 'رده‌ی استعلامِ گروه ذخیره شد.' : (r.error || 'خطا'), r.ok ? 'success' : 'error'); vLoad();
        });
        const pages = Math.max(1, Math.ceil(d.total / d.per));
        el.querySelector('[data-rows]').innerHTML = `<div class="overflow-x-auto border rounded-xl"><table class="w-full"><thead class="bg-slate-50 text-slate-500"><tr>
              <th class="p-2 text-right">مدل</th><th class="p-2 text-right">گروه</th><th class="p-2">مشخصات</th><th class="p-2 text-right">رده‌ی استعلام</th><th class="p-2"></th></tr></thead>
            <tbody>${d.rows.map(r => `<tr class="border-t ${Number(r.active) ? '' : 'opacity-50'}"><td class="p-2 font-bold">${esc(r.title)}${r.code ? `<small class="block text-slate-400 font-normal">کد ${fa(r.code)}</small>` : ''}</td>
              <td class="p-2 text-slate-500">${esc(r.grp)}</td><td class="p-2 text-center text-slate-500">${[r.cylinders ? fa(r.cylinders) + ' سیلندر' : '', Number(r.tonnage) ? fa(Number(r.tonnage)) + ' تن' : '', r.seats ? fa(r.seats) + ' نفر' : ''].filter(Boolean).join(' · ')}</td>
              <td class="p-2">${r.premium_cat ? `<span class="bg-teal-50 text-teal-700 rounded-lg px-2 py-0.5">${esc(catName(r.premium_cat))}</span>` : '<span class="text-amber-600">ارجاع به کارشناس</span>'}</td>
              <td class="p-2 whitespace-nowrap"><button data-e="${r.id}" class="text-indigo-600 font-bold">ویرایش</button> · <button data-d="${r.id}" class="text-rose-600 font-bold">حذف</button></td></tr>`).join('') || '<tr><td colspan="5" class="p-6 text-center text-slate-400">موردی نیست.</td></tr>'}</tbody></table></div>
            <div class="flex items-center justify-between mt-2 text-slate-500"><span>${fa(d.total)} مدل</span><span class="flex gap-1">
              <button data-p="-1" class="px-3 py-1 border rounded-lg" ${VS.page <= 1 ? 'disabled' : ''}>قبلی</button><span class="px-2 py-1">${fa(VS.page)} / ${fa(pages)}</span><button data-p="1" class="px-3 py-1 border rounded-lg" ${VS.page >= pages ? 'disabled' : ''}>بعدی</button></span></div>`;
        el.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { VS.page += Number(b.dataset.p); vLoad(); });
        el.querySelectorAll('[data-e]').forEach(b => b.onclick = () => vEdit(d.rows.find(r => String(r.id) === b.dataset.e)));
        el.querySelectorAll('[data-d]').forEach(b => b.onclick = async () => {
            const go = async () => { const r = await post(VC, { action: 'admin_delete', id: b.dataset.d }); toast(r.ok ? 'حذف شد.' : (r.error || 'خطا'), r.ok ? 'success' : 'error'); vLoad(); };
            if (window.showConfirm) showConfirm('حذفِ مدل', 'این مدل از فهرست حذف شود؟', go); else if (confirm('حذف شود؟')) go();
        });
    }
    function vEdit(r) {
        const d = VS.data;
        const box = document.createElement('div');
        box.className = 'modal-overlay active'; box.style.zIndex = '1000002';
        const inp = (k, l, v, x = '') => `<label class="block"><span class="text-[11px] font-bold text-slate-500">${l}</span><input data-f="${k}" value="${esc(v ?? '')}" class="w-full border rounded-xl px-3 py-2 text-sm mt-1" ${x}></label>`;
        box.innerHTML = `<div class="modal-content w-full max-w-lg p-6 relative"><button class="absolute top-4 left-4 text-slate-400 text-xl" data-x>✕</button>
          <h3 class="font-black mb-4">${r.id ? 'ویرایشِ مدل' : 'مدلِ تازه'}</h3><div class="grid grid-cols-2 gap-3">
          <label class="block"><span class="text-[11px] font-bold text-slate-500">نوع</span><select data-f="kind" class="w-full border rounded-xl px-3 py-2 text-sm mt-1">${d.kinds.map(k => `<option value="${k.key}" ${k.key === r.kind ? 'selected' : ''}>${k.fa}</option>`).join('')}</select></label>
          ${inp('grp', 'گروه', r.grp, 'list="vc-grp-dl"')}<datalist id="vc-grp-dl">${[...new Set(d.groups.map(g => g.grp))].map(g => `<option value="${esc(g)}">`).join('')}</datalist>
          <div class="col-span-2">${inp('title', 'نامِ مدل', r.title)}</div>
          ${inp('cylinders', 'سیلندر', r.cylinders, 'dir="ltr"')}${inp('seats', 'سرنشین', r.seats, 'dir="ltr"')}${inp('tonnage', 'تناژ', r.tonnage, 'dir="ltr"')}${inp('code', 'کدِ رایانه', r.code, 'dir="ltr"')}
          <label class="block col-span-2"><span class="text-[11px] font-bold text-slate-500">رده‌ی استعلامِ ثالث</span><select data-f="premium_cat" class="w-full border rounded-xl px-3 py-2 text-sm mt-1"><option value="">بدونِ استعلامِ آنلاین (ارجاع به کارشناس)</option>${d.premium_cats.map(c => `<option value="${c.code}" ${String(r.premium_cat) === String(c.code) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
          <label class="flex items-center gap-2 col-span-2 text-xs font-bold"><input type="checkbox" data-f="active" ${Number(r.active ?? 1) ? 'checked' : ''}> در مینی‌اپ نشان داده شود</label></div>
          <button data-ok class="w-full mt-4 bg-indigo-600 text-white font-bold py-3 rounded-xl text-sm">ذخیره</button></div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.querySelector('[data-x]').onclick = close;
        box.addEventListener('click', e => { if (e.target === box) close(); });
        box.querySelector('[data-ok]').onclick = async () => {
            const g = k => box.querySelector(`[data-f="${k}"]`);
            const body = { action: 'admin_save', id: r.id || 0, kind: g('kind').value, grp: g('grp').value, title: g('title').value, cylinders: en(g('cylinders').value), seats: en(g('seats').value),
                           tonnage: en(g('tonnage').value), code: en(g('code').value), premium_cat: g('premium_cat').value, active: g('active').checked ? 1 : 0 };
            const x = await post(VC, body);
            if (!x.ok) { toast(x.error || 'خطا', 'error'); return; }
            close(); toast('ذخیره شد.', 'success'); vLoad();
        };
    }

    // =====================================================================
    //  استعلام‌های حق بیمه (لاگ + پیام به کاربر)
    // =====================================================================
    const IS = { filter: '', q: '', page: 1 };
    const ST = { DONE: ['استعلام شد', 'bg-slate-100 text-slate-600'], ACCURATE: ['درخواستِ استعلامِ دقیق', 'bg-amber-100 text-amber-800'], ANSWERED: ['پاسخ داده شد', 'bg-emerald-100 text-emerald-700'], NO_RATE: ['بدونِ استعلامِ آنلاین', 'bg-rose-100 text-rose-700'] };
    async function inquiries(el) {
        if (!el) return;
        IS.el = el;
        if (!el.dataset.ready) {
            el.dataset.ready = 1;
            el.innerHTML = `<div class="card p-5"><div class="flex flex-wrap items-center gap-3 mb-3"><h3 class="font-black text-slate-800"><i class="fas fa-calculator text-teal-500 ml-1"></i>استعلام‌های حق بیمه از مینی‌اپ</h3>
              <div data-kpi class="flex gap-2 mr-auto text-[11px]"></div></div>
              <div class="flex flex-wrap gap-2 mb-3"><select data-f class="border rounded-xl px-3 py-2 text-xs"><option value="">همه</option><option value="ACCURATE">درخواستِ استعلامِ دقیق</option><option value="NO_RATE">بدونِ استعلامِ آنلاین</option><option value="ANSWERED">پاسخ داده شده</option><option value="GUEST">کاربرانِ مهمان (وارد نشده)</option></select>
              <input data-q class="border rounded-xl px-3 py-2 text-xs flex-1 min-w-[180px]" placeholder="جستجو: نام، کد ملی، موبایل، مدلِ خودرو یا شماره‌ی استعلام"></div><div data-list></div></div>`;
            el.querySelector('[data-f]').onchange = e => { IS.filter = e.target.value; IS.page = 1; iLoad(); };
            el.querySelector('[data-q]').oninput = e => { clearTimeout(IS.t); IS.t = setTimeout(() => { IS.q = e.target.value.trim(); IS.page = 1; iLoad(); }, 300); };
        }
        iLoad();
    }
    async function iLoad() {
        const el = IS.el;
        const d = await post(API, { action: 'inq_list', filter: IS.filter, q: IS.q, page: IS.page });
        if (!d.ok) { el.querySelector('[data-list]').innerHTML = `<p class="text-rose-600 text-xs">${esc(d.error)}</p>`; return; }
        const c = d.counts || {};
        el.querySelector('[data-kpi]').innerHTML = `<span class="bg-slate-100 rounded-lg px-2 py-1">امروز: <b>${fa(d.today)}</b></span><span class="bg-amber-100 text-amber-800 rounded-lg px-2 py-1">منتظرِ پاسخ: <b>${fa(c.ACCURATE || 0)}</b></span><span class="bg-emerald-100 text-emerald-700 rounded-lg px-2 py-1">پاسخ‌داده: <b>${fa(c.ANSWERED || 0)}</b></span>`;
        const pages = Math.max(1, Math.ceil(d.total / d.per));
        el.querySelector('[data-list]').innerHTML = `<div class="overflow-x-auto border rounded-xl"><table class="w-full text-xs"><thead class="bg-slate-50 text-slate-500"><tr>
            <th class="p-2">#</th><th class="p-2 text-right">زمان</th><th class="p-2 text-right">کاربر</th><th class="p-2 text-right">وسیله</th><th class="p-2">تعهد</th><th class="p-2">مبلغِ آنلاین</th><th class="p-2">وضعیت</th><th class="p-2"></th></tr></thead>
            <tbody>${d.rows.map(r => `<tr class="border-t hover:bg-slate-50 cursor-pointer" data-id="${r.id}"><td class="p-2 text-center text-slate-400">${fa(r.id)}</td><td class="p-2 whitespace-nowrap">${fa(r.created_j)}</td>
              <td class="p-2"><b>${esc(r.person_name || r.user_name || 'مهمان')}</b><small class="block text-slate-400">${r.person_id ? (fa(r.national_code || '') + ' · ' + fa(r.mobile_number || '')) : (r.bale_user_id ? 'مهمان · دارای شناسه‌ی بله' : 'مهمان · بدونِ شناسه')}</small></td>
              <td class="p-2">${esc(r.inputs.vehicle_kind_fa || '')} ${r.vehicle_title ? '· ' + esc(r.vehicle_title) : ''}<small class="block text-slate-400">${esc(r.vehicle_group || '')}</small></td>
              <td class="p-2 text-center">${r.inputs.liability_tier ? fa(r.inputs.liability_tier) + ' م' : '—'}</td><td class="p-2 text-center font-bold">${r.grand_total ? money(r.grand_total) : '—'}</td>
              <td class="p-2 text-center"><span class="rounded-lg px-2 py-0.5 ${(ST[r.status] || ST.DONE)[1]}">${(ST[r.status] || ST.DONE)[0]}</span></td>
              <td class="p-2 text-indigo-600 font-bold whitespace-nowrap">جزئیات ›</td></tr>`).join('') || '<tr><td colspan="8" class="p-8 text-center text-slate-400">استعلامی ثبت نشده.</td></tr>'}</tbody></table></div>
            <div class="flex items-center justify-between mt-2 text-xs text-slate-500"><span>${fa(d.total)} استعلام</span><span class="flex gap-1">
              <button data-p="-1" class="px-3 py-1 border rounded-lg" ${IS.page <= 1 ? 'disabled' : ''}>قبلی</button><span class="px-2 py-1">${fa(IS.page)} / ${fa(pages)}</span><button data-p="1" class="px-3 py-1 border rounded-lg" ${IS.page >= pages ? 'disabled' : ''}>بعدی</button></span></div>`;
        el.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { IS.page += Number(b.dataset.p); iLoad(); });
        el.querySelectorAll('tr[data-id]').forEach(tr => tr.onclick = () => iDetail(d.rows.find(r => String(r.id) === tr.dataset.id)));
    }
    function iDetail(r) {
        const i = r.inputs || {}, res = r.result;
        const row = (k, v) => v === '' || v == null ? '' : `<div class="flex justify-between gap-3 border-b border-slate-100 py-1.5"><span class="text-slate-500">${k}</span><b class="text-left">${v}</b></div>`;
        const box = document.createElement('div');
        box.className = 'modal-overlay active'; box.style.zIndex = '1000002';
        box.innerHTML = `<div class="modal-content w-full max-w-2xl p-6 relative"><button class="absolute top-4 left-4 text-slate-400 text-xl" data-x>✕</button>
          <h3 class="font-black mb-1">استعلامِ شماره‌ی ${fa(r.id)}</h3><p class="text-[11px] text-slate-500 mb-3">${fa(r.created_j)} · <span class="rounded px-2 ${(ST[r.status] || ST.DONE)[1]}">${(ST[r.status] || ST.DONE)[0]}</span></p>
          <div class="grid md:grid-cols-2 gap-4 text-xs">
            <div><div class="font-bold text-indigo-700 mb-1">👤 کاربر</div>${row('نام', esc(r.person_name || r.user_name || 'مهمان'))}${row('کد ملی', fa(r.national_code || ''))}${row('موبایل', fa(r.mobile_number || ''))}${row('وضعیت', r.person_id ? 'واردشده در ربات' : (r.bale_user_id ? 'مهمان (شناسه‌ی بله دارد)' : 'مهمان (بدونِ شناسه)'))}
              <div class="font-bold text-indigo-700 mt-3 mb-1">🚗 وسیله و بیمه‌نامه‌ی قبلی</div>${row('نوع', esc(i.vehicle_kind_fa || ''))}${row('گروه', esc(i.vehicle_group || ''))}${row('مدل', esc(i.vehicle_title || ''))}${row('سال ساخت', fa(i.model_year || ''))}
              ${row('سقف تعهد', i.liability_tier ? fa(i.liability_tier) + ' میلیون تومان' : '')}${row('شروعِ بیمه‌نامه‌ی قبلی', fa(i.prev_start || ''))}${row('انقضای بیمه‌نامه‌ی قبلی', fa(i.prev_expiry || ''))}
              ${row('تخفیفِ عدمِ خسارتِ ثالث', i.tp_prev_percent != null ? fa(i.tp_prev_percent) + '٪' : '')}${row('تخفیفِ عدمِ خسارتِ حوادث راننده', i.dr_prev_percent != null ? fa(i.dr_prev_percent) + '٪' : '')}
              ${row('خسارتِ ثالثِ سالِ قبل', esc(i.tp_claims || ''))}${row('خسارتِ حوادث راننده‌ی سالِ قبل', esc(i.dr_claims || ''))}</div>
            <div><div class="font-bold text-teal-700 mb-1">💰 نتیجه‌ی محاسبه</div>${res ? row('رده', esc(res.category_name || '')) + row('درصدِ ثالث / راننده', `${fa(res.thirdparty_percent)}٪ / ${fa(res.driver_percent)}٪`) + row('روزِ تأخیر', fa(res.days_late || 0))
                + (res.breakdown ? row('پایه / مازاد / راننده', `${money(res.breakdown.base_final)} / ${money(res.breakdown.excess_final)} / ${money(res.breakdown.driver_final)}`) + row('سهم صندوق', money(res.breakdown.fund_contribution)) : '')
                + row('مالیات', money(res.tax)) + row('مبلغِ نهایی', `<span class="text-emerald-700">${money(res.grand_total)} ریال</span>`) : '<p class="text-amber-700">استعلامِ آنلاین برای این وسیله نیست.</p>'}
              ${r.user_note ? `<div class="mt-3 bg-amber-50 border border-amber-200 rounded-xl p-2"><b>📝 توضیحِ کاربر:</b> ${esc(r.user_note)}</div>` : ''}
              ${r.reply_text ? `<div class="mt-3 bg-emerald-50 border border-emerald-200 rounded-xl p-2 whitespace-pre-line"><b>پاسخِ فرستاده‌شده (${fa(r.replied_j)}):</b>\n${esc(r.reply_text)}</div>` : ''}
              <div class="mt-3 border-t pt-3"><div class="font-bold mb-1">✉️ پیام به کاربر ${r.person_id ? '(در پنلِ کاربری و ربات)' : '(مستقیم در ربات بله)'}</div>
                ${r.can_message ? `<input data-amt class="w-full border rounded-xl px-3 py-2 mb-2" placeholder="مبلغِ استعلامِ دقیق (ریال، اختیاری)" inputmode="numeric" dir="ltr">
                <textarea data-txt rows="4" class="w-full border rounded-xl px-3 py-2" placeholder="متنِ پیام…"></textarea>
                <button data-send class="w-full mt-2 bg-teal-600 text-white font-bold py-2.5 rounded-xl">ارسالِ پیام</button>` : '<p class="text-slate-400">این کاربر از بیرونِ ربات استعلام گرفته و شناسه‌ای برای پیام ندارد.</p>'}
                ${r.status === 'ACCURATE' ? '<button data-done class="w-full mt-2 border border-slate-200 font-bold py-2 rounded-xl">علامت‌گذاری به‌عنوانِ رسیدگی‌شده</button>' : ''}</div></div></div></div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.querySelector('[data-x]').onclick = close;
        box.addEventListener('click', e => { if (e.target === box) close(); });
        const amt = box.querySelector('[data-amt]');
        if (amt) amt.oninput = () => { const v = en(amt.value).replace(/\D/g, ''); amt.value = v ? fa(Number(v).toLocaleString('en-US')) : ''; };
        const send = box.querySelector('[data-send]');
        if (send) send.onclick = async () => {
            send.disabled = true;
            const x = await post(API, { action: 'inq_reply', id: r.id, text: box.querySelector('[data-txt]').value, amount: amt ? amt.value : '' });
            send.disabled = false;
            if (!x.ok) { toast(x.error || 'خطا', 'error'); return; }
            toast('پیام فرستاده شد (' + x.via + ').', 'success'); close(); iLoad();
        };
        const done = box.querySelector('[data-done]');
        if (done) done.onclick = async () => { await post(API, { action: 'inq_status', id: r.id, status: 'ANSWERED' }); close(); iLoad(); };
    }

    window.MiniAppAdmin = { hoursCard, vehicleCard, inquiries };
})();
