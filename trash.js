// فایل: trash.js
// پنل → تنظیمات «حذف‌شده‌ها»: هر «حذفِ کامل» (معرفی‌نامه، درخواستِ کارکنان/شرکتی، بیمه‌نامه‌ی صادره) این‌جا می‌ماند تا
// با همه‌ی جزئیات برگردانده یا برای همیشه پاک شود (api/trash_actions.php). هر دو کار با رمزِ مدیر.
(function () {
    const API = 'api/trash_actions.php';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const size = b => b > 1048576 ? fa((b / 1048576).toFixed(1)) + ' مگابایت' : fa(Math.max(1, Math.round(b / 1024))) + ' کیلوبایت';
    const TBL = { policy_cases: 'درخواست', company_request_plates: 'ردیف', company_requests: 'درخواستِ شرکتی', introductions: 'معرفی‌نامه',
                  policy_installments: 'قسط', company_installments: 'قسط', case_documents: 'مدرک', company_documents: 'مدرک', policy_endorsements: 'الحاقیه',
                  payment_allocations: 'تخصیصِ دریافت', company_payment_allocations: 'تخصیصِ دریافت', health_inspections: 'بازدید' };
    async function post(body) {
        try { return await (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
    }

    async function card(el) {
        if (!el) return;
        el.innerHTML = '<p class="text-xs text-slate-400">در حال بارگذاری…</p>';
        const d = await post({ action: 'list' });
        if (!d.ok) { el.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        const items = d.items || [];
        if (!items.length) { el.innerHTML = '<p class="text-xs text-slate-400 text-center py-6">چیزی حذف نشده است.</p>'; return; }
        el.innerHTML = `<div class="space-y-2 max-h-[560px] overflow-y-auto">${items.map((it, i) => {
            const cnt = Object.entries(it.counts || {}).filter(([t]) => TBL[t]).map(([t, n]) => fa(n) + ' ' + TBL[t]).join('، ');
            return `<div class="border ${it.restored_at ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200 bg-white'} rounded-xl p-3 text-xs">
              <div class="flex flex-wrap items-center gap-2">
                <span class="px-2 py-0.5 rounded-full font-bold bg-rose-50 text-rose-700">${esc(it.what)}</span>
                <b class="text-slate-700">${esc(it.summary)}</b>
                <span class="mr-auto text-slate-400">${fa(it.at_j)}${it.by ? ' · ' + esc(it.by) : ''}</span></div>
              <div class="text-[11px] text-slate-500 mt-1">${cnt || '—'} · ${fa(it.files)} فایل/پوشه · ${size(it.size)}</div>
              ${it.restored_at ? `<div class="text-[11px] text-emerald-700 font-bold mt-1">✅ برگردانده شد (${fa(it.restored_j)})${it.restore_note ? ' — ' + esc(it.restore_note) : ''}</div>` : ''}
              <div class="flex gap-2 mt-2">
                ${it.restored_at ? '' : `<button data-act="restore" data-i="${i}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg font-bold"><i class="fas fa-rotate-left ml-1"></i>برگرداندن با همه‌ی جزئیات</button>`}
                <button data-act="purge" data-i="${i}" class="bg-rose-50 hover:bg-rose-100 text-rose-700 px-3 py-1.5 rounded-lg font-bold"><i class="fas fa-trash ml-1"></i>${it.restored_at ? 'پاک کردنِ این سابقه' : 'پاک کردنِ دائمی'}</button>
              </div></div>`; }).join('')}</div>`;
        el.querySelectorAll('[data-act]').forEach(b => b.onclick = () => confirmAct(el, b.dataset.act, items[+b.dataset.i]));
    }

    function confirmAct(el, act, it) {
        const restore = act === 'restore';
        const box = document.createElement('div');
        box.className = 'modal-overlay active'; box.style.zIndex = '1000002';
        box.innerHTML = `<div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl" data-x><i class="fas fa-times"></i></button>
            <h3 class="font-black text-base text-slate-800 mb-1">${restore ? '<i class="fas fa-rotate-left text-emerald-600 ml-1"></i>برگرداندن' : '<i class="fas fa-trash text-rose-600 ml-1"></i>پاک کردنِ دائمی'}</h3>
            <p class="text-xs text-slate-600 mb-3">${esc(it.summary)}</p>
            <p class="text-[11px] leading-6 rounded-xl p-3 mb-3 ${restore ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}">${restore
                ? 'همه‌ی اطلاعات (با همان شناسه‌ها)، اقساط و تخصیصِ دریافت‌ها، الحاقیه‌ها، مدارک و پوشه‌های بایگانی سرِ جای قبلی‌شان برمی‌گردند و سهمیه‌ی معرفی‌نامه هم دوباره حساب می‌شود.'
                : (it.restored_at ? 'فقط سابقه‌ی این مورد از «حذف‌شده‌ها» پاک می‌شود (خودِ اطلاعات قبلاً برگردانده شده است).' : 'این مورد و فایل‌هایش برای همیشه از روی هاست پاک می‌شود و دیگر قابلِ برگشت نیست.')}</p>
            <label class="text-[11px] font-bold text-slate-500 block mb-1">برای تأیید، رمزِ ورودِ خودتان را وارد کنید</label>
            <input type="password" data-pw class="w-full border rounded-xl px-3 py-2.5 text-sm mb-3" autocomplete="current-password">
            <button type="button" data-ok class="w-full ${restore ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'} text-white text-sm font-black py-2.5 rounded-xl">${restore ? 'برگرداندن' : 'پاک کردنِ دائمی'}</button></div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.querySelector('[data-x]').onclick = close;
        box.addEventListener('click', e => { if (e.target === box) close(); });
        const pw = box.querySelector('[data-pw]'); setTimeout(() => pw.focus(), 50);
        const go = async () => {
            if (!pw.value) { toast('رمز را وارد کنید.', 'error'); return; }
            const b = box.querySelector('[data-ok]'); b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>…';
            const r = await post({ action: act, name: it.name, password: pw.value });
            if (!r.ok) { toast(r.error || 'خطا', 'error'); b.disabled = false; b.textContent = restore ? 'برگرداندن' : 'پاک کردنِ دائمی'; return; }
            close(); toast(r.message || 'انجام شد.', 'success'); card(el);
            if (restore && typeof window.tabInvalidate === 'function') { try { tabInvalidate(); } catch (e) {} }
        };
        box.querySelector('[data-ok]').onclick = go;
        pw.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
    }

    window.TrashBin = { card };
})();
