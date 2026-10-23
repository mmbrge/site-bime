// فایل: api-keys.js
// پنل → تنظیمات «کلیدهای API»: کلیدِ دسترسی و تنظیماتِ هر وب‌سرویسِ بیرونی (s.api.ir: کلیدِ مشترک، پیامکی OTP، استعلامِ شخصِ حقوقی) + آزمایشِ هر کدام
// منطق: api/_apis.php  (api/api_admin.php)
(function () {
    const API = 'api/api_admin.php';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function post(body) {
        try { return await (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
    }
    const AK = { el: null, data: null };
    const CH = { off: 'غیرفعال', bot: 'ربات بله', sms: 'پنلِ پیامک', sapi: 'پیامکی OTP (s.api.ir)' };

    async function card(el) {
        if (!el) return;
        AK.el = el;
        el.innerHTML = '<p class="text-xs text-slate-400">در حال بارگذاری…</p>';
        const d = await post({ action: 'get' });
        if (!d.ok) { el.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        AK.data = d;
        render();
    }

    function field(svc, f, c) {
        const [key, label, type, hint] = f;
        const v = c[key] == null ? '' : String(c[key]);
        const base = `data-svc="${svc}" data-k="${key}" class="w-full border rounded-xl px-3 py-2 text-xs mt-1"`;
        let input;
        if (type.indexOf('select:') === 0) {
            const opts = type.slice(7).split(',').map(o => o.split('='));
            input = `<select ${base}>${opts.map(([val, lab]) => `<option value="${esc(val)}" ${v === val || (!v && opts[0][0] === val) ? 'selected' : ''}>${esc(fa(val))} — ${esc(lab)}</option>`).join('')}</select>`;
        } else input = `<input dir="ltr" ${type === 'secret' ? 'autocomplete="off" spellcheck="false"' : ''} value="${esc(v)}" placeholder="${type === 'secret' ? 'eyJhbGciOi…' : ''}" ${base}>`;
        return `<label class="block"><span class="text-[11px] font-bold text-slate-600">${esc(label)}</span>${input}${hint ? `<small class="block text-[10.5px] text-slate-400 mt-0.5 leading-5">${esc(hint)}</small>` : ''}</label>`;
    }

    function testBox(svc) {
        if (svc === 'sapi') return `<button data-test="sapi" class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-xl text-[11px] font-bold"><i class="fas fa-plug-circle-check ml-1"></i>آزمایشِ کلید (Echo — رایگان)</button>`;
        if (svc === 'sapi_otp') return `<input data-tin="sapi_otp" dir="ltr" placeholder="09…" class="border rounded-xl px-3 py-2 text-xs w-40">
            <button data-test="sapi_otp" class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-xl text-[11px] font-bold"><i class="fas fa-paper-plane ml-1"></i>ارسالِ کدِ آزمایشی</button>`;
        if (svc === 'sapi_company') return `<input data-tin="sapi_company" dir="ltr" placeholder="شناسه‌ی ملیِ ۱۱ رقمی" class="border rounded-xl px-3 py-2 text-xs w-48">
            <button data-test="sapi_company" class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-xl text-[11px] font-bold"><i class="fas fa-magnifying-glass ml-1"></i>استعلامِ آزمایشی</button>`;
        if (svc === 'ai_vision') return `<button data-test="ai_vision" class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-xl text-[11px] font-bold"><i class="fas fa-plug-circle-check ml-1"></i>آزمایشِ اتصال</button>`;
        return '';
    }

    function render() {
        const d = AK.data, el = AK.el;
        const u = d.usage || {};
        el.innerHTML = `
          ${d.curl ? '' : '<p class="bg-rose-50 text-rose-700 text-xs font-bold rounded-xl p-3 mb-3">⚠️ افزونه‌ی curl روی سرور فعال نیست؛ تا فعال نشود هیچ وب‌سرویسی کار نمی‌کند.</p>'}
          <p class="text-[11px] text-slate-500 leading-6 mb-3">کلیدِ هر سرویس را این‌جا وارد کنید. کلیدها روی سرور می‌مانند و در صفحه فقط ۴ نویسه‌ی آخرشان دیده می‌شود. برای «پیامکی OTP»، انتخابِ اینکه کدِ ورود از کدام راه برود در کارتِ «پیامک و ورود با کدِ تأیید» است
            (الان: ورودِ کارکنان در ربات = <b>${esc(CH[u.otp_bot] || '—')}</b>، ورود به پنل با کد = <b>${esc(CH[u.panel_otp] || '—')}</b>).</p>
          <div class="space-y-3">${d.services.map(sv => {
              const c = d.cfg[sv.key] || {};
              return `<div class="border border-slate-200 rounded-2xl p-4 bg-white">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <b class="text-sm text-slate-700"><i class="fas ${esc(sv.icon)} text-sky-600 ml-1"></i>${esc(sv.fa)}</b>
                  ${sv.endpoint ? `<code class="text-[10.5px] text-slate-400" dir="ltr">${esc(sv.endpoint)}</code>` : ''}
                  <span class="mr-auto text-[10.5px] font-bold px-2 py-0.5 rounded-full ${sv.ready ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}">${sv.ready ? (sv.key === 'sapi' || sv.own_key ? '✓ کلید ثبت شده' : '✓ با کلیدِ مشترک') : 'کلید ندارد'}</span></div>
                <p class="text-[11px] text-slate-500 leading-5 mb-3">${esc(sv.desc)}</p>
                <div class="grid md:grid-cols-3 gap-3">${sv.fields.map(f => field(sv.key, f, c)).join('')}</div>
                <div class="mt-3 flex flex-wrap items-center gap-2">${testBox(sv.key)}<small class="text-[10.5px] text-slate-400">${sv.key === 'sapi_otp' ? 'کد با قالبی که الان انتخاب شده فرستاده می‌شود (حتی پیش از ذخیره)؛ کلید از تنظیماتِ ذخیره‌شده.' : 'آزمایش با تنظیماتِ ذخیره‌شده انجام می‌شود.'}</small></div>
                <div data-tout="${sv.key}" class="mt-2"></div></div>`; }).join('')}</div>
          <button data-save class="w-full mt-4 bg-sky-600 hover:bg-sky-700 text-white font-bold py-3 rounded-xl text-xs"><i class="fas fa-save ml-1"></i>ذخیره‌ی کلیدها و تنظیماتِ وب‌سرویس‌ها</button>`;

        el.querySelector('[data-save]').onclick = async e => {
            const cfg = {};
            el.querySelectorAll('[data-svc]').forEach(i => { (cfg[i.dataset.svc] = cfg[i.dataset.svc] || {})[i.dataset.k] = i.value; });
            const b = e.currentTarget; b.disabled = true;
            const r = await post({ action: 'save', cfg });
            b.disabled = false;
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            AK.data = r; render(); toast('کلیدها و تنظیمات ذخیره شد.', 'success');
        };
        el.querySelectorAll('[data-test]').forEach(b => b.onclick = async () => {
            const svc = b.dataset.test, box = el.querySelector(`[data-tout="${svc}"]`);
            const inp = el.querySelector(`[data-tin="${svc}"]`);
            const body = { action: 'test', service: svc };
            if (svc === 'sapi_otp') { body.phone = inp.value; const t = el.querySelector('[data-svc="sapi_otp"][data-k="template"]'); if (t) body.template = t.value; }
            if (svc === 'sapi_company') body.national_id = inp.value;
            b.disabled = true; box.innerHTML = `<p class="text-[11px] text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ ارتباط با ${svc === 'ai_vision' ? 'سرویسِ هوشِ مصنوعی' : 's.api.ir'}…</p>`;
            const r = await post(body);
            b.disabled = false;
            if (!r.ok) { box.innerHTML = `<p class="text-[11px] text-rose-600 font-bold">${esc(r.error)}</p>`; return; }
            const x = r.result || {};
            const co = x.company ? `<div class="grid grid-cols-2 md:grid-cols-4 gap-1.5 mt-2 text-[10.5px]">${[['نام', x.company.name], ['نوع', x.company.company_type], ['شماره‌ی ثبت', x.company.register_number], ['تاریخِ ثبت', x.company.register_date],
                ['کدپستی', x.company.postal_code], ['استان / شهر', [x.company.province, x.company.city].filter(Boolean).join(' / ')], ['وضعیت', x.company.active === false ? 'غیرفعال' : 'فعال'], ['آدرس', x.company.address]]
                .map(([k, v]) => `<div class="bg-slate-50 rounded-lg px-2 py-1"><span class="text-slate-400">${k}:</span> <b>${esc(fa(v || '—'))}</b></div>`).join('')}</div>` : '';
            box.innerHTML = `<div class="rounded-xl p-3 text-[11px] ${x.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}"><b>${x.ok ? '✅' : '❌'} ${esc(fa(x.status || ''))}</b>
                ${x.http ? `<span class="text-slate-400 mr-2">HTTP ${fa(x.http)}${x.ms ? ' · ' + fa(x.ms) + ' ms' : ''}</span>` : ''}${co}
                ${x.sent ? `<details class="mt-1"><summary class="cursor-pointer text-slate-500">درخواستِ فرستاده‌شده</summary><pre dir="ltr" class="whitespace-pre-wrap text-[10px] text-slate-600 mt-1">${esc(x.sent)}</pre></details>` : ''}
                ${x.raw ? `<details class="mt-1"><summary class="cursor-pointer text-slate-500">پاسخِ خامِ سرویس</summary><pre dir="ltr" class="whitespace-pre-wrap text-[10px] text-slate-600 mt-1">${esc(x.raw)}</pre></details>` : ''}</div>`;
        });
    }

    window.ApiKeys = { card };
})();
