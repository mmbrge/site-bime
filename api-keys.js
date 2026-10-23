// فایل: api-keys.js
// پنل → تنظیمات «کلیدهای API»: کلیدِ دسترسی و تنظیماتِ هر وب‌سرویسِ بیرونی (s.api.ir: کلیدِ مشترک، پیامکی OTP، استعلامِ شخصِ حقوقی؛
// هوش مصنوعی: خواندنِ اسکن، GapGPT با آزمایشِ systemone) + آزمایشِ هر کدام
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
        if (svc === 'gapgpt') return `<button data-test="gapgpt" class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-xl text-[11px] font-bold"><i class="fas fa-plug-circle-check ml-1"></i>آزمایشِ اتصال (chat)</button>
            <button data-sys1 class="bg-violet-600 hover:bg-violet-700 text-white px-3 py-2 rounded-xl text-[11px] font-bold"><i class="fas fa-list-check ml-1"></i>آزمایشِ systemone (نمونه‌ی ورودی/خروجی)</button>`;
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
            if (svc === 'gapgpt' && b.dataset.mode === 'systemone') { body.mode = 'systemone'; body.payload = el.querySelector('[data-sys1-in]').value; }
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

    // نمونه‌ی ورودیِ systemone (همان مثالِ مستندِ GapGPT + یک نمونه‌ی فارسیِ بیمه‌ای)؛ کاربر می‌تواند ویرایش کند
    const SYS1_SAMPLE = {
        model: 'typesafe/jev-1.13',
        state: { text: 'شرکتِ نمونه درخواستِ تمدیدِ بیمه‌نامه‌ی ثالثِ ۳ دستگاه خودرو را دارد و یکی از خودروها پارسال خسارتِ مالی داشته است.' },
        questions: {
            renewal: { type: 'noul', instructions: { question: 'آیا درخواستِ تمدید است؟' } },
            kind: { type: 'choice', instructions: ['نوعِ بیمه‌نامه چیست؟'], criteria: { thirdparty: 'بیمه‌ی ثالث', body: 'بیمه‌ی بدنه' } },
            claim: { type: 'score', instructions: 'شدتِ سابقه‌ی خسارت چقدر است؟', criteria: ['بدونِ خسارت', 'خسارتِ مالی', 'خسارتِ جانی'] },
        },
    };
    document.addEventListener('click', e => {
        const b = e.target.closest && e.target.closest('[data-sys1]');
        if (!b || !AK.el) return;
        const box = AK.el.querySelector('[data-tout="gapgpt"]');
        box.innerHTML = `<div class="rounded-xl border border-violet-200 bg-violet-50/50 p-3">
            <p class="text-[11px] font-bold text-violet-800 mb-1">ورودیِ systemone (JSON: model اختیاری، state و questions) — نوع‌ها: <code>noul</code> بله/خیر، <code>choice</code> انتخاب از criteria، <code>score</code> امتیاز روی فهرستِ criteria</p>
            <textarea data-sys1-in dir="ltr" rows="14" class="w-full border rounded-xl p-2 text-[11px] font-mono">${esc(JSON.stringify(SYS1_SAMPLE, null, 2))}</textarea>
            <div class="flex gap-2 mt-2"><button data-test="gapgpt" data-mode="systemone" class="bg-violet-600 hover:bg-violet-700 text-white px-3 py-2 rounded-xl text-[11px] font-bold"><i class="fas fa-play ml-1"></i>اجرا و نمایشِ خروجی</button>
                <button data-sys1-en class="bg-white border px-3 py-2 rounded-xl text-[11px] font-bold">نمونه‌ی انگلیسیِ مستندِ GapGPT</button></div>
            <div data-sys1-out class="mt-2"></div></div>`;
        box.querySelector('[data-sys1-en]').onclick = () => {
            box.querySelector('[data-sys1-in]').value = JSON.stringify({ model: 'typesafe/jev-1.13', state: { text: 'The customer requests a refund for a duplicate payment.' }, questions: {
                refund: { type: 'noul', instructions: { question: 'Is a refund requested?' } },
                team: { type: 'choice', instructions: ['Which team should handle this?'], criteria: { billing: 'Payments and refunds', technical: 'Software errors' } },
                urgency: { type: 'score', instructions: 'How urgent is it?', criteria: ['Routine', 'Urgent', 'Emergency'] } } }, null, 2);
        };
        const run = box.querySelector('[data-mode="systemone"]');
        run.onclick = async () => {
            const out = box.querySelector('[data-sys1-out]');
            run.disabled = true; out.innerHTML = '<p class="text-[11px] text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ ارتباط با GapGPT…</p>';
            const r = await post({ action: 'test', service: 'gapgpt', mode: 'systemone', payload: box.querySelector('[data-sys1-in]').value });
            run.disabled = false;
            if (!r.ok) { out.innerHTML = `<p class="text-[11px] text-rose-600 font-bold">${esc(r.error)}</p>`; return; }
            const x = r.result || {};
            let pretty = x.raw || '';
            try { pretty = JSON.stringify(JSON.parse(x.raw), null, 2); } catch (er) {}
            out.innerHTML = `<div class="rounded-xl p-3 text-[11px] ${x.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}"><b>${x.ok ? '✅' : '❌'} ${esc(x.status || '')}</b>
                ${x.http ? `<span class="text-slate-400 mr-2">HTTP ${fa(x.http)}${x.ms ? ' · ' + fa(x.ms) + ' ms' : ''}</span>` : ''}
                <p class="font-bold mt-2 mb-1">خروجی:</p><pre dir="ltr" class="whitespace-pre-wrap text-[10.5px] bg-white rounded-lg p-2 text-slate-700">${esc(pretty || '—')}</pre></div>`;
        };
    });

    window.ApiKeys = { card };
})();
