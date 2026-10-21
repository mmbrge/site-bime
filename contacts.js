// فایل: contacts.js
// پنل → «بانک مخاطبین»: کارکنانِ همه‌ی شرکت‌ها با فیلتر (شرکت، موبایل، ربات، معرفی‌نامه)، ویرایش، ورود از اکسل (پیش‌نمایش ← ثبت)،
// خروجیِ اکسل با همان فیلترها، اکسلِ نمونه و لاگِ کدهای تأییدِ (OTP) ارسالی برای هر شخص (api/contacts_actions.php)
(function () {
    // تأیید با پاپ‌آپِ خودِ سایت (uiConfirm)
    const uiAsk = (t) => window.uiConfirm ? uiConfirm('تأیید', t, {}) : Promise.resolve(window.confirm(t));
    const API = 'api/contacts_actions.php';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function post(body) {
        try { return await (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
    }
    const F = { q: '', company: '', phone: '', bot: '', intro: '', dup: '', page: 1, per: 50 };
    let META = null, root = null, qTimer = null;

    async function init(el) {
        root = el;
        if (!root) return;
        if (!root.dataset.built) {
            root.dataset.built = '1';
            root.innerHTML = `
              <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-black text-slate-800"><i class="fas fa-address-book text-indigo-500 ml-2"></i>بانک مخاطبین</h1>
                <div class="mr-auto flex flex-wrap gap-2">
                  <button data-bc class="bg-fuchsia-600 hover:bg-fuchsia-700 text-white px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-bullhorn ml-1"></i>پیامِ همگانی در ربات</button>
                  <button data-bclog class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-list-check ml-1"></i>گزارشِ ارسال‌ها</button>
                  <button data-imp class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-file-import ml-1"></i>ورود از اکسل</button>
                  <a data-exp href="#" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-file-excel ml-1"></i>خروجیِ اکسل (با فیلترها)</a>
                  <a href="${API}?action=sample" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-download ml-1"></i>اکسلِ نمونه</a>
                </div>
              </div>
              <p class="text-xs text-slate-400 mt-1">همه‌ی کارکنانِ شرکت‌ها (از معرفی‌نامه‌ها، ربات و ورودِ اکسل). شماره‌ی موبایلِ اینجا همان شماره‌ای است که در «ورود با کدِ تأیید» برایش پیامک می‌رود.</p>
              <div data-stats class="mt-3"></div>
              <div class="card p-3 mt-3 grid grid-cols-2 md:grid-cols-6 gap-2">
                <div class="relative md:col-span-2"><i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                  <input data-f="q" type="search" placeholder="نام، کد ملی، موبایل، کد پرسنلی…" class="w-full border rounded-xl pr-8 pl-3 py-2 text-xs"></div>
                <select data-f="company" class="border rounded-xl px-3 py-2 text-xs font-bold"><option value="">همه‌ی شرکت‌ها</option></select>
                <select data-f="phone" class="border rounded-xl px-3 py-2 text-xs"><option value="">موبایل: همه</option><option value="has">موبایل دارد</option><option value="none">موبایل ندارد</option></select>
                <select data-f="bot" class="border rounded-xl px-3 py-2 text-xs"><option value="">ربات: همه</option><option value="linked">متصل به ربات</option><option value="unlinked">متصل نیست</option></select>
                <select data-f="intro" class="border rounded-xl px-3 py-2 text-xs"><option value="">معرفی‌نامه: همه</option><option value="has">دارد</option><option value="none">ندارد</option></select>
              </div>
              <div class="card p-0 mt-3 overflow-x-auto"><div data-body><p class="text-xs text-slate-400 p-6 text-center">در حال بارگذاری…</p></div></div>
              <div data-pager class="flex items-center justify-center gap-2 mt-3 text-xs"></div>`;
            root.querySelectorAll('[data-f]').forEach(i => {
                const k = i.dataset.f;
                i.addEventListener(k === 'q' ? 'input' : 'change', () => { F[k] = i.value; F.page = 1; if (k === 'q') { clearTimeout(qTimer); qTimer = setTimeout(load, 350); } else load(); });
            });
            root.querySelector('[data-imp]').onclick = importDialog;
            root.querySelector('[data-bc]').onclick = broadcastDialog;
            root.querySelector('[data-bclog]').onclick = broadcastLog;
            root.querySelector('[data-exp]').onclick = e => { e.preventDefault(); location.href = API + '?action=export&' + new URLSearchParams({ q: F.q, company: F.company, phone: F.phone, bot: F.bot, intro: F.intro, dup: F.dup }); };
        }
        await loadMeta();
        loadStats();
        load();
    }

    // ---------------- آمارِ بالای صفحه + به تفکیکِ شرکت ----------------
    async function loadStats() {
        const box = root.querySelector('[data-stats]');
        const d = await post({ action: 'stats' });
        if (!d.ok) { box.innerHTML = ''; return; }
        const t = d.totals || {}, b = d.broadcasts || {}, o = d.otp || {};
        const tile = (ic, label, n, sub, color, act) => `<div ${act ? `data-st="${act}"` : ''} class="rounded-2xl border border-${color}-100 bg-gradient-to-br from-white to-${color}-50 p-3 ${act ? 'cursor-pointer hover:shadow-md' : ''}">
            <div class="flex items-center gap-2 text-[11px] font-bold text-${color}-700"><i class="fas ${ic}"></i>${label}</div>
            <div class="text-xl font-black text-slate-800 mt-1">${fa(Number(n || 0).toLocaleString('en-US'))}</div>${sub ? `<div class="text-[10.5px] text-slate-500 mt-0.5">${sub}</div>` : ''}</div>`;
        box.innerHTML = `<div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-2">
            ${tile('fa-users', 'مخاطبان', t.persons, fa(t.nids || 0) + ' کد ملیِ یکتا', 'indigo')}
            ${tile('fa-mobile-screen', 'با شماره‌ی پیش‌فرض', t.with_phone, 'بی‌شماره: ' + fa((t.persons || 0) - (t.with_phone || 0)), 'emerald', 'phone')}
            ${tile('fa-phone', 'کلِ شماره‌ها', t.phones, fa(t.multi_phone || 0) + ' نفر چند شماره دارند', 'teal')}
            ${tile('fa-robot', 'متصل به ربات', t.bot, 'از ' + fa(t.persons || 0) + ' نفر', 'sky', 'bot')}
            ${tile('fa-file-signature', 'با معرفی‌نامه', t.with_intro, '', 'violet', 'intro')}
            ${tile('fa-building', 'شرکت‌ها', (d.companies || []).length, 'بدونِ شرکت: ' + fa(t.nocompany || 0), 'amber')}
            ${tile('fa-bullhorn', 'پیام‌های همگانی', b.n, fa(b.sent || 0) + ' ارسال · ' + fa(b.failed || 0) + ' ناموفق', 'fuchsia', 'bclog')}
            ${tile('fa-key', 'کدهای تأیید (پیامک)', o.sent, fa(o.verified || 0) + ' تأیید شده', 'rose')}
          </div>
          ${+t.dup_nids ? `<div data-st="dup" class="mt-2 cursor-pointer text-[11.5px] font-bold rounded-xl px-3 py-2 ${F.dup ? 'bg-rose-600 text-white' : 'bg-rose-50 text-rose-700'}"><i class="fas fa-clone ml-1"></i>${fa(t.dup_nids)} کد ملی بیش از یک ردیف در سایت دارد — ${F.dup ? 'نمایشِ همه' : 'نمایشِ همین‌ها'}</div>` : ''}
          <details class="card mt-2 p-0 overflow-hidden"><summary class="cursor-pointer px-4 py-2.5 text-xs font-bold text-slate-700"><i class="fas fa-building text-amber-500 ml-1"></i>به تفکیکِ شرکت (${fa((d.companies || []).length)} شرکت) — روی هر شرکت بزنید تا فهرست فیلتر شود</summary>
            <div class="overflow-x-auto max-h-[340px]"><table class="w-full text-[11.5px]"><thead class="bg-slate-50 text-slate-500 sticky top-0"><tr><th class="p-2 text-right">شرکت</th><th class="p-2">مخاطب</th><th class="p-2">با شماره</th><th class="p-2">متصل به ربات</th><th class="p-2">با معرفی‌نامه</th></tr></thead>
            <tbody>${(d.companies || []).map(c => `<tr data-co="${c.id}" class="border-t border-slate-100 cursor-pointer hover:bg-indigo-50/50 ${String(F.company) === String(c.id) ? 'bg-indigo-50' : ''}">
              <td class="p-2 font-bold text-slate-700">${esc(c.name)}</td><td class="p-2 text-center">${fa(c.persons)}</td><td class="p-2 text-center">${fa(c.with_phone || 0)}</td>
              <td class="p-2 text-center">${fa(c.bot || 0)}</td><td class="p-2 text-center">${fa(c.with_intro || 0)}</td></tr>`).join('')}</tbody></table></div></details>`;
        const setF = (k, v) => { F[k] = v; F.page = 1; const el = root.querySelector(`[data-f="${k}"]`); if (el) el.value = v; load(); loadStats(); };
        box.querySelectorAll('[data-st]').forEach(x => x.onclick = () => {
            const a = x.dataset.st;
            if (a === 'bclog') return broadcastLog();
            if (a === 'phone') return setF('phone', F.phone === 'has' ? '' : 'has');
            if (a === 'bot') return setF('bot', F.bot === 'linked' ? '' : 'linked');
            if (a === 'intro') return setF('intro', F.intro === 'has' ? '' : 'has');
            if (a === 'dup') return setF('dup', F.dup ? '' : '1');
        });
        box.querySelectorAll('[data-co]').forEach(x => x.onclick = () => setF('company', String(F.company) === x.dataset.co ? '' : x.dataset.co));
    }

    async function loadMeta() {
        const d = await post({ action: 'meta' });
        if (!d.ok) return;
        META = d;
        const sel = root.querySelector('[data-f="company"]');
        sel.innerHTML = '<option value="">همه‌ی شرکت‌ها</option><option value="none">— بدونِ شرکت —</option>' + d.companies.map(c => `<option value="${c.id}">${esc(c.name)} (${fa(c.n)})</option>`).join('');
        sel.value = F.company;
    }

    async function load() {
        const body = root.querySelector('[data-body]');
        const d = await post(Object.assign({ action: 'list' }, F));
        if (!d.ok) { body.innerHTML = `<p class="text-xs text-rose-600 p-6">${esc(d.error)}</p>`; return; }
        if (!d.rows.length) { body.innerHTML = '<p class="text-xs text-slate-400 p-8 text-center">موردی پیدا نشد.</p>'; root.querySelector('[data-pager]').innerHTML = ''; return; }
        body.innerHTML = `<table class="w-full text-xs"><thead class="bg-slate-50 text-slate-500"><tr>
            <th class="p-3 text-right">نام</th><th class="p-3 text-right">کد ملی</th><th class="p-3 text-right">موبایل</th><th class="p-3 text-right">شرکت</th>
            <th class="p-3 text-right">کد پرسنلی</th><th class="p-3 text-right">معرفی‌نامه</th><th class="p-3 text-center">ربات</th><th class="p-3 text-right">آخرین کدِ تأیید</th><th class="p-3"></th></tr></thead>
            <tbody>${d.rows.map(r => `<tr class="border-t border-slate-100 hover:bg-slate-50/60">
              <td class="p-3 font-bold text-slate-700">${esc(r.full_name || '—')}${+r.nid_rows > 1 ? `<small class="block text-[10px] text-rose-600 font-bold">⚠️ ${fa(r.nid_rows)} ردیف با همین کد ملی</small>` : ''}</td>
              <td class="p-3" dir="ltr" style="text-align:right">${fa(r.national_code)}</td>
              <td class="p-3" dir="ltr" style="text-align:right">${r.mobile_number ? fa(r.mobile_number) : '<span class="text-slate-300">—</span>'}${+r.phone_count > 1 ? `<small class="block text-[10px] text-teal-700 font-bold" dir="rtl">+${fa(r.phone_count - 1)} شماره‌ی دیگر</small>` : ''}</td>
              <td class="p-3">${r.company_name ? esc(r.company_name) : '<span class="text-amber-600">بدونِ شرکت</span>'}</td>
              <td class="p-3">${fa(r.personnel_code || '')}</td>
              <td class="p-3">${+r.intro_count ? `${fa(r.intro_count)} مورد<small class="block text-slate-400">آخرین: ${fa(r.last_intro_j)}${r.quota ? ' · سهمیه ' + fa(r.quota) : ''}</small>` : '<span class="text-slate-300">ندارد</span>'}</td>
              <td class="p-3 text-center">${r.bot_linked ? '<span title="متصل" class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500"></span>' : '<span title="متصل نیست" class="inline-block w-2.5 h-2.5 rounded-full bg-slate-300"></span>'}</td>
              <td class="p-3">${r.otp ? `<span class="font-bold ${r.otp.status === 'VERIFIED' ? 'text-emerald-600' : (r.otp.send_ok ? 'text-sky-600' : 'text-rose-600')}">${esc(r.otp.status_fa)}</span><small class="block text-slate-400">${fa(r.otp.at)}</small>` : '<span class="text-slate-300">—</span>'}</td>
              <td class="p-3"><button data-open="${r.id}" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-3 py-1.5 rounded-lg font-bold text-[11px]"><i class="fas fa-id-card ml-1"></i>جزئیات و کدها</button></td></tr>`).join('')}</tbody></table>`;
        body.querySelectorAll('[data-open]').forEach(b => b.onclick = () => detail(+b.dataset.open));
        const pages = Math.max(1, Math.ceil(d.total / d.per));
        const pg = root.querySelector('[data-pager]');
        pg.innerHTML = `<button data-p="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''} class="px-3 py-1.5 rounded-lg bg-white border disabled:opacity-40">قبلی</button>
            <span class="text-slate-500">صفحه‌ی ${fa(d.page)} از ${fa(pages)} · ${fa(d.total)} نفر</span>
            <button data-p="${d.page + 1}" ${d.page >= pages ? 'disabled' : ''} class="px-3 py-1.5 rounded-lg bg-white border disabled:opacity-40">بعدی</button>`;
        pg.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { F.page = +b.dataset.p; load(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    }

    // پنجره‌ی ساده (بستن با × یا Esc یا کلیک بیرون)
    function modal(title, html, wide) {
        const ov = document.createElement('div');
        ov.className = 'fixed inset-0 bg-slate-900/50 flex items-start justify-center p-4 overflow-y-auto';
        ov.style.zIndex = '99990';
        ov.innerHTML = `<div class="bg-white rounded-2xl shadow-2xl w-full ${wide ? 'max-w-5xl' : 'max-w-2xl'} my-6">
            <div class="flex items-center gap-2 px-5 py-4 border-b border-slate-100"><b class="text-sm text-slate-800">${title}</b>
            <button data-x class="mr-auto w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500"><i class="fas fa-times"></i></button></div><div class="p-5" data-mb>${html}</div></div>`;
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = e => { if (e.key === 'Escape') close(); };
        ov.addEventListener('click', e => { if (e.target === ov) close(); });
        ov.querySelector('[data-x]').onclick = close;
        document.addEventListener('keydown', onKey);
        document.body.appendChild(ov);
        return { el: ov.querySelector('[data-mb]'), close };
    }

    async function detail(id) {
        const m = modal('جزئیاتِ مخاطب', '<p class="text-xs text-slate-400">در حال بارگذاری…</p>', true);
        const d = await post({ action: 'get', id });
        if (!d.ok) { m.el.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        const p = d.person, pr = p.profile || {};
        const cos = (META && META.companies) || [];
        m.el.innerHTML = `
          <div class="grid md:grid-cols-2 gap-3 text-xs">
            <label class="font-bold text-slate-600">نام و نام خانوادگی<input data-e="full_name" value="${esc(p.full_name || '')}" class="w-full border rounded-xl px-3 py-2 mt-1"></label>
            <label class="font-bold text-slate-600">کد ملی<input value="${esc(p.national_code)}" disabled dir="ltr" class="w-full border rounded-xl px-3 py-2 mt-1 bg-slate-50 text-slate-500"></label>
            <label class="font-bold text-slate-600">شماره‌ی پیش‌فرض (پیامکِ کدِ تأیید)<input data-e="mobile_number" value="${esc(p.mobile_number || '')}" dir="ltr" placeholder="09…" class="w-full border rounded-xl px-3 py-2 mt-1"><small class="block font-normal text-slate-400 mt-0.5">شماره‌ی تازه پیش‌فرض می‌شود و قبلی در فهرستِ پایین می‌ماند</small></label>
            <label class="font-bold text-slate-600">شرکت<select data-e="company_id" class="w-full border rounded-xl px-3 py-2 mt-1"><option value="">— بدونِ شرکت —</option>
              ${cos.map(c => `<option value="${c.id}" ${+c.id === +p.company_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
            <label class="font-bold text-slate-600">کد پرسنلی<input data-e="personnel_code" value="${esc(p.personnel_code || '')}" dir="ltr" class="w-full border rounded-xl px-3 py-2 mt-1"></label>
            <div class="bg-slate-50 rounded-xl p-3 text-[11px] text-slate-600 leading-6">
              <div>ربات بله: ${p.bot_linked ? `<b class="text-emerald-600">متصل</b> (شماره‌ی ربات: <span dir="ltr">${fa(p.bot_phone || '—')}</span>${p.bot_linked_j ? ' · از ' + fa(p.bot_linked_j) : ''})` : '<b class="text-slate-500">متصل نیست</b>'}</div>
              <div>معرفی‌نامه: ${+p.intro_count ? fa(p.intro_count) + ' مورد · آخرین ' + fa(p.last_intro_j) + (p.quota ? ' · سهمیه ' + fa(p.quota) : '') : 'ندارد'}</div>
              ${pr.birth_date || pr.address ? `<div>تولد: ${fa(pr.birth_date || '—')} · کد پستی: ${fa(pr.postal_code || '—')}</div><div>آدرس: ${esc(pr.address || '—')}</div>` : ''}
              <div>ثبت در سامانه: ${fa(p.created_j)}</div>
            </div>
          </div>
          <button data-save class="mt-3 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-xl text-xs font-bold"><i class="fas fa-save ml-1"></i>ذخیره</button>
          <div class="mt-6 border-t border-slate-100 pt-4">
            <b class="text-sm text-slate-700"><i class="fas fa-phone text-teal-500 ml-1"></i>شماره‌های این شخص</b>
            <p class="text-[11px] text-slate-400 mb-2">همه‌ی شماره‌هایی که برای این کد ملی آمده (اکسل، پنل، ربات). شماره‌ی پیش‌فرض همان شماره‌ای است که کدِ تأیید برایش پیامک می‌شود.</p>
            <div data-phones></div>
            <div class="flex flex-wrap items-center gap-2 mt-2 text-xs">
              <input data-newphone dir="ltr" placeholder="شماره‌ی تازه 09…" class="border rounded-xl px-3 py-2 w-44">
              <label class="flex items-center gap-1 text-slate-600"><input type="checkbox" data-newdef checked>پیش‌فرض شود</label>
              <button data-addphone class="bg-teal-600 hover:bg-teal-700 text-white px-3 py-2 rounded-xl font-bold"><i class="fas fa-plus ml-1"></i>افزودنِ شماره</button>
            </div>
          </div>
          <div class="mt-6 border-t border-slate-100 pt-4">
            <b class="text-sm text-slate-700"><i class="fas fa-key text-violet-500 ml-1"></i>کدهای تأییدِ ارسالی (OTP)</b>
            <p class="text-[11px] text-slate-400 mb-2">زمانِ ارسال، موفق/ناموفق بودنِ پیامک و پاسخِ پنل، تعدادِ کدِ اشتباه، تأیید و اعتبارِ باقی‌مانده.</p>
            <div data-otp></div>
          </div>`;
        if (window.SmsOtp) SmsOtp.logsTable(m.el.querySelector('[data-otp]'), null, p.otp_logs);
        const paintPhones = list => {
            const box = m.el.querySelector('[data-phones]');
            box.innerHTML = list.length ? `<div class="space-y-1.5">${list.map(ph => `<div class="flex flex-wrap items-center gap-2 border rounded-xl px-3 py-2 text-xs ${ph.is_default ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200'}">
                <b dir="ltr" class="text-slate-800">${fa(ph.phone)}</b>${ph.is_default ? '<span class="px-2 py-0.5 rounded-full bg-emerald-600 text-white text-[10px] font-bold">پیش‌فرض</span>' : ''}
                <span class="text-[10.5px] text-slate-400">${esc(ph.source_fa)}${ph.created_j ? ' · ' + fa(ph.created_j) : ''}</span>
                ${ph.is_default ? '' : `<span class="mr-auto flex gap-1"><button data-pdef="${ph.id}" class="bg-white border border-emerald-300 text-emerald-700 hover:bg-emerald-50 px-2 py-1 rounded-lg font-bold text-[11px]">پیش‌فرض کن</button>
                  <button data-pdel="${ph.id}" title="حذفِ این شماره از فهرست" class="bg-white border border-rose-200 text-rose-600 hover:bg-rose-50 px-2 py-1 rounded-lg text-[11px]"><i class="fas fa-trash ml-1"></i>حذف</button></span>`}</div>`).join('')}</div>`
              : '<p class="text-xs text-slate-400">شماره‌ای ثبت نشده.</p>';
            const after = r => { if (!r.ok) return toast(r.error || 'خطا', 'error'); paintPhones(r.phones || []); const def = (r.phones || []).find(x => x.is_default); m.el.querySelector('[data-e="mobile_number"]').value = def ? def.phone : ''; load(); loadStats(); };
            box.querySelectorAll('[data-pdef]').forEach(b => b.onclick = async () => after(await post({ action: 'phone_default', id, phone_id: +b.dataset.pdef })));
            box.querySelectorAll('[data-pdel]').forEach(b => b.onclick = async () => { if (!(await uiAsk('این شماره از فهرست حذف شود؟'))) return; after(await post({ action: 'phone_remove', id, phone_id: +b.dataset.pdel })); });
            m.el.querySelector('[data-addphone]').onclick = async () => {
                const r = await post({ action: 'phone_add', id, phone: m.el.querySelector('[data-newphone]').value, make_default: m.el.querySelector('[data-newdef]').checked });
                if (r.ok) m.el.querySelector('[data-newphone]').value = '';
                after(r);
            };
        };
        paintPhones(p.phones || []);
        m.el.querySelector('[data-save]').onclick = async () => {
            const g = k => m.el.querySelector(`[data-e="${k}"]`).value;
            const r = await post({ action: 'update', id, full_name: g('full_name'), mobile_number: g('mobile_number'), company_id: g('company_id'), personnel_code: g('personnel_code') });
            if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
            toast('ذخیره شد.', 'success'); m.close(); loadMeta(); loadStats(); load();
        };
    }

    // ---------------- ورود از اکسل ----------------
    const ACT = { new: ['تازه', 'bg-emerald-100 text-emerald-700'], update: ['به‌روزرسانی', 'bg-sky-100 text-sky-700'], same: ['تکراری (قبلاً ثبت شده)', 'bg-slate-100 text-slate-500'], error: ['خطا', 'bg-rose-100 text-rose-700'] };
    const FLD = { full_name: 'نام', mobile: 'موبایل', personnel_code: 'کد پرسنلی', company: 'شرکت' };
    function importDialog() {
        const m = modal('ورودِ مخاطبین از اکسل', `
          <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3 text-[11.5px] text-slate-600 leading-6">
            ستون‌ها: <b>کد ملی</b> (الزامی)، نام و نام خانوادگی، موبایل، شرکت، کد پرسنلی — سرستون‌ها در ردیفِ اول.
            <a href="${API}?action=sample" class="font-bold text-indigo-600 hover:underline mr-1"><i class="fas fa-download ml-1"></i>دانلودِ اکسلِ نمونه</a><br>
            هر کد ملی که در سامانه باشد به‌روز می‌شود و بقیه اضافه می‌شوند؛ <b>خانه‌ی خالیِ اکسل هیچ اطلاعاتی را پاک نمی‌کند</b>. شرکتِ ناآشنا خودکار ساخته می‌شود. اول پیش‌نمایش نشان داده می‌شود و تا «ثبت» را نزنید چیزی ذخیره نمی‌شود.
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3">
            <input type="file" data-file accept=".xlsx" class="text-xs">
            <button data-prev class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-xs font-bold"><i class="fas fa-eye ml-1"></i>پیش‌نمایش</button>
          </div>
          <div data-pv class="mt-4"></div>`, true);
        m.el.querySelector('[data-prev]').onclick = async () => {
            const f = m.el.querySelector('[data-file]').files[0];
            if (!f) { toast('فایلِ اکسل را انتخاب کنید.', 'error'); return; }
            const box = m.el.querySelector('[data-pv]');
            box.innerHTML = '<p class="text-xs text-slate-400">در حال خواندن…</p>';
            const fd = new FormData(); fd.append('action', 'import_preview'); fd.append('file', f);
            let d;
            try { d = await (await fetch(API, { method: 'POST', body: fd })).json(); } catch (e) { d = { ok: false, error: 'خطا در ارسالِ فایل.' }; }
            if (!d.ok) { box.innerHTML = `<p class="text-xs text-rose-600 font-bold">${esc(d.error)}</p>`; return; }
            const c = d.counts;
            box.innerHTML = `
              <div class="flex flex-wrap gap-1.5 text-[11px] font-bold">
                <span class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700">تازه: ${fa(c.new)}</span>
                <span class="px-2 py-1 rounded-lg bg-sky-50 text-sky-700">به‌روزرسانی: ${fa(c.update)}</span>
                <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-600">تکراری / بدونِ تغییر: ${fa(c.same)}</span>
                <span class="px-2 py-1 rounded-lg bg-rose-50 text-rose-700">خطا (وارد نمی‌شود): ${fa(c.error)}</span>
                ${c.new_company ? `<span class="px-2 py-1 rounded-lg bg-amber-50 text-amber-700">شرکتِ تازه: ${fa(c.new_company)}</span>` : ''}
              </div>
              <div class="max-h-[380px] overflow-auto mt-3 border rounded-xl"><table class="w-full text-[11px]"><thead class="bg-slate-50 text-slate-500 sticky top-0"><tr>
                <th class="p-2 text-right">ردیف</th><th class="p-2 text-right">وضعیت</th><th class="p-2 text-right">کد ملی</th><th class="p-2 text-right">نام</th><th class="p-2 text-right">موبایل</th>
                <th class="p-2 text-right">شرکت</th><th class="p-2 text-right">کد پرسنلی</th><th class="p-2 text-right">توضیح</th></tr></thead>
                <tbody>${d.items.map(it => {
                    const ch = k => (it.diff || []).indexOf(k) >= 0 ? 'bg-sky-50 font-bold' : '';
                    const cur = k => it.current && (it.diff || []).indexOf(k) >= 0 && it.current[k] ? `<small class="block text-slate-400 line-through">${esc(fa(it.current[k]))}</small>` : '';
                    return `<tr class="border-t border-slate-100 align-top">
                      <td class="p-2">${fa(it.row)}</td><td class="p-2"><span class="px-2 py-0.5 rounded-full font-bold ${ACT[it.action][1]}">${ACT[it.action][0]}</span>${it.dup_phone && it.action === 'update' ? '<small class="block text-slate-400 mt-1 whitespace-nowrap">کد ملی و شماره تکراری</small>' : ''}</td>
                      <td class="p-2" dir="ltr" style="text-align:right">${fa(it.national_code || it.raw_nid || '')}</td>
                      <td class="p-2 ${ch('full_name')}">${esc(it.full_name)}${cur('full_name')}</td>
                      <td class="p-2 ${ch('mobile')}" dir="ltr" style="text-align:right">${fa(it.mobile)}${cur('mobile')}</td>
                      <td class="p-2 ${ch('company')}">${esc(it.company_match || it.company)}${cur('company')}</td>
                      <td class="p-2 ${ch('personnel_code')}">${fa(it.personnel_code)}${cur('personnel_code')}</td>
                      <td class="p-2 text-amber-700">${esc((it.warn || []).join('؛ '))}</td></tr>`; }).join('')}</tbody></table></div>
              ${d.total > d.items.length ? `<p class="text-[11px] text-slate-400 mt-1">${fa(d.items.length)} ردیف از ${fa(d.total)} نشان داده شده؛ همه ثبت می‌شوند.</p>` : ''}
              <p class="mt-3 text-[11px] text-sky-800 bg-sky-50 rounded-xl p-2.5 leading-6"><i class="fas fa-phone ml-1"></i>شماره‌ی تازه برای کد ملیِ موجود در هر دو حالت <b>شماره‌ی پیش‌فرض</b> می‌شود و شماره‌ی قبلی در فهرستِ شماره‌های همان شخص می‌ماند (از پنجره‌ی شخص می‌شود پیش‌فرض را عوض کرد).</p>
              <div class="mt-2 bg-slate-50 rounded-xl p-3 text-xs">
                <label class="flex items-start gap-2 cursor-pointer"><input type="radio" name="ct-mode" value="fill" checked class="mt-0.5"><span><b>فقط خانه‌های خالیِ سایت پر شود</b> (پیشنهادی)<small class="block text-slate-500">اطلاعاتی که الان در سایت هست عوض نمی‌شود؛ فقط جاهای خالی از اکسل پر می‌شود.</small></span></label>
                <label class="flex items-start gap-2 cursor-pointer mt-2"><input type="radio" name="ct-mode" value="overwrite" class="mt-0.5"><span><b>مقدارِ اکسل جایگزین شود</b><small class="block text-slate-500">برای خانه‌هایی که در اکسل پر است، مقدارِ سایت با اکسل عوض می‌شود (خانه‌ی خالیِ اکسل باز هم چیزی را پاک نمی‌کند).</small></span></label>
              </div>
              <button data-commit class="w-full mt-3 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-xs" ${c.new + c.update ? '' : 'disabled'}><i class="fas fa-check ml-1"></i>ثبتِ ${fa(c.new + c.update)} ردیف</button>`;
            const btn = box.querySelector('[data-commit]');
            btn.onclick = async () => {
                btn.disabled = true; btn.textContent = 'در حال ثبت…';
                const r = await post({ action: 'import_commit', token: d.token, mode: box.querySelector('[name="ct-mode"]:checked').value });
                if (!r.ok) { toast(r.error || 'خطا', 'error'); btn.disabled = false; btn.textContent = 'ثبت'; return; }
                const x = r.result;
                box.innerHTML = `<div class="bg-emerald-50 text-emerald-800 rounded-xl p-4 text-sm font-bold">✅ ثبت شد: ${fa(x.created)} نفرِ تازه، ${fa(x.updated)} نفر به‌روز شد${x.skipped ? '، ' + fa(x.skipped) + ' ردیف بدونِ تغییر' : ''}${x.companies ? '، ' + fa(x.companies) + ' شرکتِ تازه' : ''}.</div>`;
                loadMeta(); loadStats(); load();
            };
        };
    }

    // ---------------- پیامِ همگانی در ربات ----------------
    const filterText = () => {
        const p = [];
        if (F.company) p.push(F.company === 'none' ? 'بدونِ شرکت' : 'شرکت: ' + (((META && META.companies) || []).find(c => String(c.id) === String(F.company)) || {}).name);
        if (F.phone) p.push(F.phone === 'has' ? 'موبایل دارد' : 'موبایل ندارد');
        if (F.intro) p.push(F.intro === 'has' ? 'معرفی‌نامه دارد' : 'معرفی‌نامه ندارد');
        if (F.q) p.push('جستجو: ' + F.q);
        if (F.dup) p.push('کد ملیِ تکراری');
        return p.length ? p.join('، ') : 'همه‌ی مخاطبان';
    };
    async function broadcastDialog() {
        const filters = { q: F.q, company: F.company, phone: F.phone, intro: F.intro, dup: F.dup };
        const cnt = await post({ action: 'bc_count', filters });
        const m = modal('📢 پیامِ همگانی در ربات', `
          <div class="bg-fuchsia-50 border border-fuchsia-100 rounded-xl p-3 text-[11.5px] text-slate-600 leading-6">
            گیرنده‌ها: کسانی که در رباتِ بله وارد شده‌اند، با فیلترهای فعلیِ صفحه (<b>${esc(filterText())}</b>). برای تغییرِ گیرنده‌ها، پنجره را ببندید و فیلترِ فهرست را عوض کنید.
            <div class="mt-1 text-sm font-black text-fuchsia-700">${fa(cnt.count || 0)} گیرنده</div></div>
          <label class="block mt-3 text-xs font-bold text-slate-600">متنِ پیام
            <textarea data-bctext rows="6" class="w-full border rounded-xl px-3 py-2 mt-1 text-sm leading-7" placeholder="{name} عزیز، …"></textarea></label>
          <p class="text-[10.5px] text-slate-400">جاینگهدارها: <b dir="ltr">{name}</b> نامِ شخص، <b dir="ltr">{company}</b> نامِ شرکت. ارسال تکه‌تکه انجام می‌شود؛ تا پایان این پنجره را نبندید (اگر بستید، از «گزارشِ ارسال‌ها» ادامه می‌دهد).</p>
          <div data-bcprog class="mt-3"></div>
          <button data-bcgo ${cnt.count ? '' : 'disabled'} class="w-full mt-3 bg-fuchsia-600 hover:bg-fuchsia-700 disabled:opacity-40 text-white font-bold py-3 rounded-xl text-sm"><i class="fas fa-paper-plane ml-1"></i>ارسال به ${fa(cnt.count || 0)} نفر</button>`);
        m.el.querySelector('[data-bcgo]').onclick = async () => {
            const text = m.el.querySelector('[data-bctext]').value.trim();
            if (text.length < 2) return toast('متنِ پیام را بنویسید.', 'error');
            if (!(await uiAsk(`پیام برای ${fa(cnt.count)} نفر در ربات فرستاده شود؟`))) return;
            const btn = m.el.querySelector('[data-bcgo]'); btn.disabled = true;
            const r = await post({ action: 'bc_create', filters, text });
            if (!r.ok) { btn.disabled = false; return toast(r.error || 'خطا', 'error'); }
            runBroadcast(r.id, m.el.querySelector('[data-bcprog]'), () => { btn.innerHTML = '<i class="fas fa-check ml-1"></i>ارسال تمام شد'; loadStats(); });
        };
    }
    // ارسالِ تکه‌تکه با نوارِ پیشرفت
    async function runBroadcast(id, box, onDone) {
        let stop = false;
        for (;;) {
            const r = await post({ action: 'bc_step', id });
            if (!r.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(r.error)}</p>`; return; }
            const b = r.broadcast, done = (+b.sent) + (+b.failed), pct = b.total ? Math.round(done * 100 / b.total) : 100;
            box.innerHTML = `<div class="h-3 rounded-full bg-slate-100 overflow-hidden"><div class="h-full bg-gradient-to-l from-fuchsia-500 to-violet-500" style="width:${pct}%"></div></div>
              <div class="flex flex-wrap items-center gap-2 mt-1 text-[11px] font-bold"><span class="text-slate-600">${fa(done)} از ${fa(b.total)}</span><span class="text-emerald-700">✅ ${fa(b.sent)} ارسال شد</span><span class="text-rose-600">❌ ${fa(b.failed)} ناموفق</span>
              <a href="${API}?action=bc_export&id=${b.id}" class="mr-auto text-indigo-600 hover:underline"><i class="fas fa-file-excel ml-1"></i>گزارشِ ارسال (اکسل)</a>
              ${b.status === 'RUNNING' ? '<button data-bcstop class="text-rose-600 hover:underline">توقف</button>' : ''}</div>`;
            const st = box.querySelector('[data-bcstop]'); if (st) st.onclick = async () => { stop = true; await post({ action: 'bc_cancel', id }); };
            if (b.status !== 'RUNNING' || stop || !document.body.contains(box)) { if (onDone) onDone(b); return; }
        }
    }
    async function broadcastLog() {
        const m = modal('گزارشِ پیام‌های همگانی', '<p class="text-xs text-slate-400">در حال بارگذاری…</p>', true);
        const d = await post({ action: 'bc_list' });
        if (!d.ok) { m.el.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        const ST = { RUNNING: ['در حال ارسال', 'bg-sky-100 text-sky-700'], DONE: ['تمام شد', 'bg-emerald-100 text-emerald-700'], CANCELED: ['متوقف شد', 'bg-slate-100 text-slate-600'] };
        m.el.innerHTML = d.rows.length ? `<div class="space-y-2">${d.rows.map(b => `<div class="border border-slate-200 rounded-xl p-3 text-xs">
            <div class="flex flex-wrap items-center gap-2"><span class="px-2 py-0.5 rounded-full font-bold ${(ST[b.status] || ['', ''])[1]}">${(ST[b.status] || [b.status])[0]}</span>
              <span class="text-slate-500">${fa(b.created_j)}${b.by_name ? ' · ' + esc(b.by_name) : ''}</span>
              <span class="mr-auto font-bold"><span class="text-slate-600">${fa(b.total)} گیرنده</span> · <span class="text-emerald-700">${fa(b.sent)} ارسال شد</span> · <span class="text-rose-600">${fa(b.failed)} ناموفق</span></span></div>
            <p class="mt-2 text-slate-700 whitespace-pre-wrap bg-slate-50 rounded-lg p-2">${esc(b.text)}</p>
            <div class="flex gap-2 mt-2"><a href="${API}?action=bc_export&id=${b.id}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg font-bold"><i class="fas fa-file-excel ml-1"></i>گزارشِ ارسال (اکسل)</a>
              ${b.status === 'RUNNING' ? `<button data-resume="${b.id}" class="bg-fuchsia-600 hover:bg-fuchsia-700 text-white px-3 py-1.5 rounded-lg font-bold"><i class="fas fa-play ml-1"></i>ادامه‌ی ارسال</button>` : ''}</div>
            <div data-prog="${b.id}" class="mt-2"></div></div>`).join('')}</div>` : '<p class="text-xs text-slate-400 text-center py-6">هنوز پیامِ همگانی‌ای فرستاده نشده.</p>';
        m.el.querySelectorAll('[data-resume]').forEach(b => b.onclick = () => { b.disabled = true; runBroadcast(+b.dataset.resume, m.el.querySelector(`[data-prog="${b.dataset.resume}"]`), () => loadStats()); });
    }

    window.Contacts = { init };
})();
