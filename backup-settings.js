// فایل: backup-settings.js
// «تنظیمات سیستم ← پشتیبان‌گیری، خروجی (Export) و ورود اطلاعات (Import)» - فقط مدیر کل
(function () {
    const API = 'api/backup_actions.php';
    const fa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const size = b => {
        if (b == null) return '-';
        const u = ['بایت', 'KB', 'MB', 'GB']; let i = 0; let n = +b;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return fa((i ? n.toFixed(1) : n) + ' ' + u[i]);
    };
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const TAGS = { 'manual': ['دستی', 'bg-blue-100 text-blue-700'], 'before-reset': ['پیش از حذف اطلاعات', 'bg-rose-100 text-rose-700'],
                   'before-restore': ['پیش از بازیابی', 'bg-amber-100 text-amber-700'] };
    let state = { list: [], limit: 0 };

    // کادر رمز عبور (رمز کاربرِ مدیرِ فعلی)
    function askPassword(title, message, extraHtml = '') {
        return new Promise(resolve => {
            const ov = document.createElement('div');
            ov.className = 'fixed inset-0 z-[9998] bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4';
            ov.innerHTML = `<div class="bg-white rounded-3xl shadow-2xl w-full max-w-md p-6">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white flex items-center justify-center text-2xl mx-auto mb-3 shadow-lg shadow-indigo-500/30"><i class="fas fa-shield-halved"></i></div>
                <h3 class="font-black text-slate-800 text-center mb-2">${esc(title)}</h3>
                <p class="text-xs text-slate-500 leading-6 text-center mb-4">${message}</p>
                ${extraHtml}
                <input type="password" class="bk-pass w-full border-2 border-slate-200 focus:border-indigo-500 outline-none rounded-xl px-4 py-3 text-sm font-bold text-center" placeholder="رمز عبور مدیر" dir="ltr" autocomplete="current-password">
                <div class="flex gap-2 mt-4">
                    <button class="bk-ok flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl text-xs">تایید</button>
                    <button class="bk-no flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-3 rounded-xl text-xs">انصراف</button>
                </div></div>`;
            document.body.appendChild(ov);
            const inp = ov.querySelector('.bk-pass');
            const done = v => { ov.remove(); resolve(v); };
            ov.querySelector('.bk-no').onclick = () => done(null);
            ov.querySelector('.bk-ok').onclick = () => {
                if (!inp.value.trim()) { inp.focus(); return; }
                const extra = {};
                ov.querySelectorAll('[data-opt]').forEach(el => { extra[el.dataset.opt] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
                done({ password: inp.value.trim(), ...extra });
            };
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') ov.querySelector('.bk-ok').click(); if (e.key === 'Escape') done(null); });
            setTimeout(() => inp.focus(), 30);
        });
    }

    function busy(on, text) {
        let el = document.getElementById('bk-busy');
        if (!on) { if (el) el.remove(); return; }
        if (!el) {
            el = document.createElement('div'); el.id = 'bk-busy';
            el.className = 'fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4';
            document.body.appendChild(el);
        }
        el.innerHTML = `<div class="bg-white rounded-3xl p-8 text-center shadow-2xl max-w-sm">
            <i class="fas fa-circle-notch fa-spin text-4xl text-indigo-500 mb-4"></i>
            <p class="font-black text-slate-700">${esc(text)}</p>
            <p class="text-xs text-slate-400 mt-2 leading-6">بسته به حجم بایگانی ممکن است چند دقیقه طول بکشد؛ صفحه را نبندید.</p></div>`;
    }

    async function post(body, isForm) {
        const res = await fetch(API, isForm ? { method: 'POST', body } : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const txt = await res.text();
        try { return JSON.parse(txt); } catch (e) { return { ok: false, error: 'پاسخ نامعتبر از سرور (ممکن است زمان اجرای هاست تمام شده باشد).' }; }
    }

    async function load() {
        const box = document.getElementById('bk-list');
        if (!box) return;
        box.innerHTML = '<div class="p-6 text-center text-slate-400 text-xs"><i class="fas fa-circle-notch fa-spin ml-1"></i> در حال بارگذاری...</div>';
        const d = await (await fetch(API + '?action=list')).json().catch(() => ({ ok: false }));
        if (!d.ok) { box.innerHTML = `<div class="p-6 text-center text-rose-500 text-xs">${esc(d.error || 'خطا در دریافت فهرست')}</div>`; return; }
        state.list = d.data; state.limit = d.upload_limit;
        const info = document.getElementById('bk-info');
        if (info) info.innerHTML = `<span><i class="fas fa-hard-drive ml-1"></i>فضای آزاد هاست: <b>${size(d.free_space)}</b></span>
            <span><i class="fas fa-upload ml-1"></i>سقف آپلود: <b>${size(d.upload_limit)}</b></span>
            <span><i class="fas fa-box-archive ml-1"></i>تعداد بکاپ‌ها: <b>${fa(d.data.length)}</b></span>
            ${d.zip ? '' : '<span class="text-rose-600 font-black">افزونه‌ی ZipArchive فعال نیست!</span>'}`;
        if (!d.data.length) { box.innerHTML = '<div class="p-8 text-center text-slate-400 text-xs"><i class="fas fa-box-open text-3xl mb-2 block"></i>هنوز بکاپی ساخته نشده است.</div>'; return; }
        box.innerHTML = `<table class="w-full text-xs"><thead><tr class="bg-slate-50 text-slate-500">
            <th class="p-3 text-right">تاریخ</th><th class="p-3 text-right">نوع</th><th class="p-3 text-right">محتوا</th>
            <th class="p-3 text-right">حجم</th><th class="p-3 text-right">توضیح</th><th class="p-3 text-center">عملیات</th></tr></thead><tbody>
            ${d.data.map(b => {
                const t = TAGS[b.tag] || [b.tag || 'آپلودی', 'bg-slate-100 text-slate-600'];
                return `<tr class="border-t hover:bg-indigo-50/40">
                <td class="p-3 font-bold text-slate-700 whitespace-nowrap">${fa(String(b.created_jalali || b.day).split(' ')[0].replace(/\./g, '/'))}<div class="text-[10px] text-slate-400">ساعت ${fa(String(b.created_jalali || '').split(' ')[1] || '-')} · پوشه‌ی <span dir="ltr">${esc(b.day)}</span></div></td>
                <td class="p-3"><span class="px-2 py-1 rounded-lg font-bold ${t[1]}">${esc(t[0])}</span></td>
                <td class="p-3 text-slate-600">${b.valid ? (b.mode === 'db' ? 'فقط دیتابیس' : 'دیتابیس + فایل‌ها') + `<div class="text-[10px] text-slate-400">${fa(b.rows ?? 0)} ردیف · ${fa(b.files ?? 0)} فایل</div>` : '<span class="text-rose-500">نامعتبر</span>'}</td>
                <td class="p-3 font-bold text-slate-600 whitespace-nowrap">${size(b.size)}</td>
                <td class="p-3 text-slate-500">${esc(b.note)}${b.created_by ? `<div class="text-[10px] text-slate-400">${esc(b.created_by)}</div>` : ''}</td>
                <td class="p-3"><div class="flex gap-1 justify-center">
                    <a href="${API}?action=download&file=${encodeURIComponent(b.rel)}" class="px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 font-bold" title="دانلود (Export)"><i class="fas fa-download ml-1"></i>دانلود</a>
                    ${b.valid ? `<button data-restore="${esc(b.rel)}" class="px-3 py-2 rounded-lg bg-amber-50 text-amber-700 hover:bg-amber-100 font-bold" title="بازیابی (Import)"><i class="fas fa-rotate-left ml-1"></i>بازیابی</button>` : ''}
                    <button data-del="${esc(b.rel)}" class="px-3 py-2 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100 font-bold" title="حذف"><i class="fas fa-trash ml-1"></i>حذف</button>
                </div></td></tr>`; }).join('')}</tbody></table>`;
        box.querySelectorAll('[data-restore]').forEach(b => b.onclick = () => restore(b.dataset.restore));
        box.querySelectorAll('[data-del]').forEach(b => b.onclick = () => del(b.dataset.del));
    }

    async function create(mode) {
        const r = await askPassword(mode === 'db' ? 'بکاپ فقط دیتابیس' : 'بکاپ کامل (دیتابیس + همه‌ی فایل‌ها)',
            'یک فایل ZIP در پوشه‌ی <b>backup/تاریخ امروز</b> ساخته می‌شود که همه‌ی ' + (mode === 'db' ? 'جدول‌های دیتابیس' : 'جدول‌های دیتابیس، بایگانی، بیمه‌نامه‌ها، صورتحساب‌ها، فیش‌ها، اکسل‌ها و گزارش‌ها') + ' را دارد.',
            `<input data-opt="note" class="w-full border rounded-xl px-4 py-2 text-xs mb-3" placeholder="توضیح (اختیاری)">`);
        if (!r) return;
        busy(true, 'در حال ساخت بکاپ...');
        const d = await post({ action: 'create', mode, password: r.password, note: r.note || '' });
        busy(false);
        if (!d.ok) { toast(d.error || 'خطا در ساخت بکاپ', 'error'); return; }
        showAlert('بکاپ ساخته شد', `فایل: backup/${d.file}\nحجم: ${size(d.size)} — ${fa(d.rows)} ردیف دیتابیس و ${fa(d.files)} فایل`, 'success');
        load();
    }

    const restoreOpts = `<div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-3 text-[11px] text-amber-800 leading-6 text-right">
        <label class="flex items-center gap-2 font-bold"><input type="checkbox" data-opt="with_files" checked> فایل‌ها هم برگردند (بایگانی، اکسل‌ها، صورتحساب‌ها، ...)</label>
        <label class="flex items-center gap-2 font-bold"><input type="checkbox" data-opt="safety" checked> پیش از بازیابی از وضعیت فعلی بکاپ گرفته شود (پیشنهادی)</label></div>`;

    async function restore(rel) {
        const ok = await uiConfirm('بازیابی اطلاعات', 'همه‌ی اطلاعات فعلیِ سایت با اطلاعات این بکاپ جایگزین می‌شود (دیتابیس دقیقاً مثل زمانِ بکاپ). ادامه می‌دهید؟', { danger: true });
        if (!ok) return;
        const r = await askPassword('تایید بازیابی', 'برای بازیابی رمز عبور مدیر را وارد کنید.', restoreOpts);
        if (!r) return;
        busy(true, 'در حال بازیابی اطلاعات...');
        const d = await post({ action: 'restore', file: rel, password: r.password, with_files: r.with_files, safety: r.safety });
        busy(false);
        done(d);
    }

    async function uploadRestore(input) {
        const f = input.files[0]; input.value = '';
        if (!f) return;
        if (state.limit && f.size > state.limit) {
            showAlert('حجم فایل زیاد است', `حجم این فایل (${size(f.size)}) از سقف آپلود هاست (${size(state.limit)}) بیشتر است.\nفایل را با File Manager هاست داخل پوشه‌ی backup (در یک زیرپوشه، مثلاً backup/upload) بگذارید؛ در همین فهرست ظاهر می‌شود و از دکمه‌ی بازیابی کنارش استفاده کنید.`, 'warning');
            return;
        }
        const ok = await uiConfirm('ورود اطلاعات از فایل', `فایل «${f.name}» آپلود و همه‌ی اطلاعات سایت با آن جایگزین می‌شود. ادامه می‌دهید؟`, { danger: true });
        if (!ok) return;
        const r = await askPassword('تایید ورود اطلاعات', 'برای بازیابی رمز عبور مدیر را وارد کنید.', restoreOpts);
        if (!r) return;
        const fd = new FormData();
        fd.append('action', 'upload_restore'); fd.append('password', r.password);
        fd.append('with_files', r.with_files); fd.append('safety', r.safety); fd.append('file', f);
        busy(true, 'در حال آپلود و بازیابی...');
        const d = await post(fd, true);
        busy(false);
        done(d);
    }

    function done(d) {
        if (!d.ok) { toast(d.error || 'خطا در بازیابی', 'error'); load(); return; }
        const r = d.result;
        let msg = `بکاپ تاریخ ${fa(r.created_jalali)} بازگردانده شد.\n${fa(r.tables)} جدول، ${fa(r.rows)} ردیف` + (r.mode !== 'db' ? `، ${fa(r.files)} فایل` : '') + '.';
        if (r.mismatch && r.mismatch.length) msg += '\nهشدار - تعداد ردیف این جدول‌ها با بکاپ یکی نیست: ' + r.mismatch.join('، ');
        if (r.files_failed) msg += `\n${fa(r.files_failed)} فایل برگردانده نشد.`;
        if (d.safety) msg += `\nبکاپِ وضعیتِ پیش از بازیابی: backup/${d.safety}`;
        if (d.relogin) msg += '\nحساب شما در این بکاپ نبود؛ دوباره وارد شوید.';
        showAlert('بازیابی انجام شد', msg, r.mismatch && r.mismatch.length ? 'warning' : 'success', () => location.reload());
    }

    async function del(rel) {
        const r = await askPassword('حذف بکاپ', `فایل <span dir="ltr">${esc(rel)}</span> برای همیشه پاک می‌شود.`);
        if (!r) return;
        const d = await post({ action: 'delete', file: rel, password: r.password });
        if (!d.ok) { toast(d.error, 'error'); return; }
        toast('بکاپ حذف شد.', 'success'); load();
    }

    window.BackupUI = { load, create, uploadRestore };
})();
