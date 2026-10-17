// فایل: sms-otp.js
// پنل → تنظیمات «پیامک و ورود با کدِ تأیید»: پنل‌های پیامک (فعال + پشتیبان)، متنِ پیامک/الگو، ارسالِ آزمایشی، ورود با OTP در ربات
// و جدولِ لاگِ کدهای ارسالی (همین جدول در «بانک مخاطبین» برای هر شخص هم استفاده می‌شود)
(function () {
    const API = 'api/sms_admin.php';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function post(body) {
        try { return await (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
    }
    const copy = t => { try { navigator.clipboard.writeText(t); toast('کپی شد.', 'success'); } catch (e) { prompt('کپی کنید:', t); } };

    const ST = { data: null, tab: '' };

    async function settingsCard(el) {
        if (!el) return;
        ST.el = el;
        el.innerHTML = '<p class="text-xs text-slate-400">در حال بارگذاری…</p>';
        const d = await post({ action: 'get' });
        if (!d.ok) { el.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
        ST.data = d;
        ST.tab = ST.tab || d.settings.provider || d.providers[0].key;
        render();
    }

    function provOptions(sel, withNone) {
        return (withNone ? `<option value="">${withNone}</option>` : '') + ST.data.providers.map(p => `<option value="${p.key}" ${sel === p.key ? 'selected' : ''}>${esc(p.fa)}</option>`).join('');
    }

    function fieldHtml(pv, f, c) {
        const [key, label, type, hint] = f;
        const v = c[key] == null ? '' : c[key];
        const base = `data-pv="${pv}" data-k="${key}" class="w-full border rounded-xl px-3 py-2 text-xs mt-1"`;
        let input;
        if (type.indexOf('select:') === 0) input = `<select ${base}>${type.slice(7).split(',').map(o => `<option value="${o}" ${v === o ? 'selected' : ''}>${o.replace('_', ' ')}</option>`).join('')}</select>`;
        else if (type === 'textarea') input = `<textarea rows="2" dir="ltr" ${base}>${esc(v)}</textarea>`;
        else input = `<input dir="ltr" ${type === 'secret' ? 'autocomplete="off"' : ''} value="${esc(v)}" ${base}>`;
        return `<label class="block"><span class="text-[11px] font-bold text-slate-600">${esc(label)}</span>${input}${hint ? `<small class="block text-[10.5px] text-slate-400 mt-0.5">${esc(hint)}</small>` : ''}</label>`;
    }

    function render() {
        const { settings: s, providers } = ST.data, el = ST.el;
        const p = providers.find(x => x.key === ST.tab) || providers[0];
        const c = s.cfg[p.key] || {};
        const mode = c.mode === 'text' ? 'text' : 'pattern';
        el.innerHTML = `
          ${ST.data.curl ? '' : '<p class="bg-rose-50 text-rose-700 text-xs font-bold rounded-xl p-3 mb-3">⚠️ افزونه‌ی curl روی سرور فعال نیست؛ تا فعال نشود پیامکی ارسال نمی‌شود.</p>'}
          <div class="grid md:grid-cols-2 gap-4">
            <div class="bg-violet-50 border border-violet-100 rounded-2xl p-4">
              <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" data-g="otp_bot" ${s.otp_bot ? 'checked' : ''} class="w-5 h-5 mt-0.5 accent-violet-600">
                <span><b class="text-sm text-slate-700">ورود با کدِ تأیید (OTP) در ربات برای کارکنان</b>
                <small class="block text-[11px] text-slate-500 leading-5 mt-1">بعد از فرستادنِ کد ملی: اگر برای این کد ملی در «بانک مخاطبین» شماره باشد، کد به همان شماره پیامک می‌شود؛ اگر نباشد، شماره پرسیده می‌شود (تایپ یا دکمه‌ی اشتراک‌گذاری) و کد به آن شماره می‌رود. با وارد کردنِ کد، شخص وارد می‌شود. اگر ارسالِ پیامک ناموفق باشد، ورود مثلِ قبل با اشتراکِ شماره انجام می‌شود.</small></span>
              </label>
              <div class="grid grid-cols-2 gap-2 mt-3 text-[11px]">
                <label class="font-bold text-slate-500">اعتبارِ کد (دقیقه)<input data-g="otp_ttl" type="number" min="1" max="15" value="${s.otp_ttl}" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs" dir="ltr"></label>
                <label class="font-bold text-slate-500">تعدادِ رقمِ کد<input data-g="otp_len" type="number" min="4" max="6" value="${s.otp_len}" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs" dir="ltr"></label>
                <label class="font-bold text-slate-500">حداکثر تلاشِ اشتباه<input data-g="otp_attempts" type="number" min="2" max="10" value="${s.otp_attempts}" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs" dir="ltr"></label>
                <label class="font-bold text-slate-500">فاصله‌ی ارسالِ دوباره (ثانیه)<input data-g="otp_resend" type="number" min="30" max="600" value="${s.otp_resend}" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs" dir="ltr"></label>
              </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
              <label class="block text-[11px] font-bold text-slate-600">پنلِ پیامکِ فعال
                <select data-g="provider" class="w-full border rounded-xl px-3 py-2 text-xs mt-1 font-bold">${provOptions(s.provider, '— انتخاب نشده —')}</select></label>
              <label class="block text-[11px] font-bold text-slate-600 mt-3">پنلِ پشتیبان (اگر پنلِ فعال خطا داد)
                <select data-g="fallback" class="w-full border rounded-xl px-3 py-2 text-xs mt-1">${provOptions(s.fallback, '— ندارد —')}</select></label>
              <p class="text-[10.5px] text-slate-400 mt-2 leading-5">هر چند پنل که بخواهید را پایین تنظیم کنید؛ ارسال با «پنلِ فعال» است و اگر ناموفق بود با «پشتیبان».</p>
            </div>
          </div>

          <div class="flex flex-wrap gap-1.5 mt-5 mb-3">${providers.map(x => {
              const on = x.key === ST.tab, act = x.key === s.provider, fb = x.key === s.fallback;
              return `<button data-tab="${x.key}" class="px-3 py-1.5 rounded-xl text-[11px] font-bold border ${on ? 'bg-violet-600 text-white border-violet-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'}">${esc(x.fa)}${act ? ' ✅' : fb ? ' 🛟' : ''}</button>`; }).join('')}</div>

          <div class="border border-slate-200 rounded-2xl p-4 bg-white">
            <div class="flex flex-wrap items-center gap-3 mb-3">
              <b class="text-sm text-slate-700">${esc(p.fa)}</b>${p.site ? `<span class="text-[11px] text-slate-400" dir="ltr">${esc(p.site)}</span>` : ''}
              ${p.key !== 'custom' ? `<span class="mr-auto flex gap-1 text-[11px] font-bold">
                <label class="flex items-center gap-1 px-2 py-1 rounded-lg border cursor-pointer ${mode === 'pattern' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'border-slate-200 text-slate-500'}"><input type="radio" name="sms-mode" value="pattern" data-mode ${mode === 'pattern' ? 'checked' : ''}>ارسالِ الگو (پیشنهادی)</label>
                <label class="flex items-center gap-1 px-2 py-1 rounded-lg border cursor-pointer ${mode === 'text' ? 'bg-amber-50 border-amber-200 text-amber-700' : 'border-slate-200 text-slate-500'}"><input type="radio" name="sms-mode" value="text" data-mode ${mode === 'text' ? 'checked' : ''}>متنِ ساده</label></span>` : ''}
            </div>
            <div class="grid md:grid-cols-2 gap-3">${p.fields.map(f => fieldHtml(p.key, f, c)).join('')}</div>
            ${p.key !== 'custom' ? `<div class="mt-4 bg-slate-50 border border-slate-200 rounded-xl p-3">
              <div class="flex items-center gap-2"><b class="text-[11.5px] text-slate-700">متنِ الگو برای ثبت در پنلِ ${esc(p.fa)}</b>
                <button data-copy-pattern class="mr-auto text-[11px] font-bold bg-white border px-2 py-1 rounded-lg hover:bg-slate-100"><i class="fas fa-copy ml-1"></i>کپی</button></div>
              <pre class="text-xs text-slate-700 whitespace-pre-wrap mt-2 font-[inherit] bg-white rounded-lg p-2 border">${esc(p.pattern_text)}</pre>
              <p class="text-[10.5px] text-slate-500 mt-1 leading-5">${esc(p.pattern_note)} بعد از تأییدِ الگو در پنل، نام/شناسه‌ی آن را در خانه‌ی بالا بنویسید. ارسالِ الگو سریع‌تر است، روی خطِ خدماتی می‌رود و به «لغو۱۱» نیاز ندارد.</p>
            </div>` : ''}
            <div class="mt-4 flex flex-wrap items-end gap-2">
              <label class="text-[11px] font-bold text-slate-600">ارسالِ آزمایشی به<input data-test-phone dir="ltr" placeholder="09…" class="block border rounded-xl px-3 py-2 text-xs mt-1 w-44"></label>
              <button data-test class="bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-xl text-xs font-bold"><i class="fas fa-paper-plane ml-1"></i>ارسالِ آزمایشی با این پنل</button>
              <small class="text-[10.5px] text-slate-400">اول «ذخیره» را بزنید؛ آزمایش با تنظیماتِ ذخیره‌شده انجام می‌شود.</small>
            </div>
            <div data-test-out class="mt-3"></div>
          </div>

          <div class="mt-4">
            <div class="flex items-center gap-2"><b class="text-[11.5px] text-slate-700">متنِ پیامکِ کدِ تأیید (حالتِ «متنِ ساده» و وب‌سرویسِ دلخواه)</b>
              <button data-reset-text class="mr-auto text-[11px] font-bold text-violet-600 hover:underline">متنِ پیش‌فرض</button>
              <button data-copy-text class="text-[11px] font-bold bg-white border px-2 py-1 rounded-lg hover:bg-slate-100"><i class="fas fa-copy ml-1"></i>کپی</button></div>
            <textarea data-g="otp_text" rows="4" class="w-full border rounded-xl px-3 py-2 text-xs mt-1 leading-6">${esc(s.otp_text)}</textarea>
            <p class="text-[10.5px] text-slate-400 leading-5">جاینگهدارها: <b dir="ltr">{name}</b> نامِ شخص، <b dir="ltr">{code}</b> کد (الزامی)، <b dir="ltr">{ttl}</b> دقیقه‌ی اعتبار. در حالتِ «ارسالِ الگو» متنِ بالا استفاده نمی‌شود و متنِ الگوی ثبت‌شده در پنل فرستاده می‌شود.</p>
          </div>
          <button data-save class="w-full mt-4 bg-violet-600 hover:bg-violet-700 text-white font-bold py-3 rounded-xl text-xs"><i class="fas fa-save ml-1"></i>ذخیره‌ی تنظیماتِ پیامک و کدِ تأیید</button>

          <div class="mt-6 border-t border-slate-100 pt-4">
            <div class="flex flex-wrap items-center gap-2 mb-2"><b class="text-sm text-slate-700"><i class="fas fa-list-check text-violet-500 ml-1"></i>کدهای ارسالی</b>
              <select data-log-status class="border rounded-lg px-2 py-1 text-[11px] mr-auto"><option value="">همه</option><option value="VERIFIED">تأیید شده</option><option value="SENT">منتظرِ کد</option><option value="FAILED">ارسالِ ناموفق</option><option value="LOCKED">قفل</option><option value="EXPIRED">منقضی</option></select>
              <input data-log-q placeholder="شماره، کد ملی یا نام…" class="border rounded-lg px-2 py-1 text-[11px] w-44">
              <button data-log-re class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-2.5 py-1 rounded-lg text-[11px] font-bold"><i class="fas fa-sync-alt"></i></button></div>
            <div data-logs></div>
          </div>`;

        const collect = () => {
            const g = k => el.querySelector(`[data-g="${k}"]`);
            const cfg = JSON.parse(JSON.stringify(ST.data.settings.cfg || {}));
            cfg[p.key] = cfg[p.key] || {};
            el.querySelectorAll('[data-pv]').forEach(i => { cfg[i.dataset.pv][i.dataset.k] = i.value; });
            const m = el.querySelector('[data-mode]:checked'); if (m) cfg[p.key].mode = m.value;
            ST.data.settings = Object.assign(ST.data.settings, { cfg, provider: g('provider').value, fallback: g('fallback').value, otp_text: g('otp_text').value, otp_bot: g('otp_bot').checked,
                otp_ttl: +g('otp_ttl').value, otp_len: +g('otp_len').value, otp_attempts: +g('otp_attempts').value, otp_resend: +g('otp_resend').value });
            return ST.data.settings;
        };
        el.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => { collect(); ST.tab = b.dataset.tab; render(); });
        el.querySelectorAll('[data-mode]').forEach(r => r.onchange = () => { collect(); render(); });
        el.querySelector('[data-g="provider"]').onchange = () => { collect(); render(); };
        el.querySelector('[data-g="fallback"]').onchange = () => { collect(); render(); };
        const cp = el.querySelector('[data-copy-pattern]'); if (cp) cp.onclick = () => copy(p.pattern_text);
        el.querySelector('[data-copy-text]').onclick = () => copy(el.querySelector('[data-g="otp_text"]').value);
        el.querySelector('[data-reset-text]').onclick = () => { el.querySelector('[data-g="otp_text"]').value = ST.data.default_otp_text; };
        el.querySelector('[data-save]').onclick = async () => {
            const s2 = collect();
            const r = await post(Object.assign({ action: 'save' }, s2));
            if (!r.ok) { toast(r.error || 'خطا', 'error'); return; }
            ST.data.settings = r.settings; render(); toast('تنظیماتِ پیامک ذخیره شد.', 'success');
        };
        el.querySelector('[data-test]').onclick = async () => {
            const box = el.querySelector('[data-test-out]');
            box.innerHTML = '<p class="text-xs text-slate-400">در حال ارسال…</p>';
            const r = await post({ action: 'test', provider: p.key, phone: el.querySelector('[data-test-phone]').value });
            if (!r.ok) { box.innerHTML = `<p class="text-xs text-rose-600 font-bold">${esc(r.error)}</p>`; return; }
            const x = r.result;
            box.innerHTML = `<div class="rounded-xl p-3 text-xs ${x.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}">
                <b>${x.ok ? '✅ ارسال شد' : '❌ ارسال نشد'}</b> · ${esc(x.mode === 'pattern' ? 'الگو' : 'متنِ ساده')} · کدِ آزمایشی: <b dir="ltr">${esc(x.code)}</b> · ${fa(x.ms)} میلی‌ثانیه
                <div class="mt-1">${esc(x.status || '')}${x.ref ? ` · شناسه‌ی پیام: <span dir="ltr">${esc(x.ref)}</span>` : ''}</div>
                ${x.raw ? `<pre class="mt-2 bg-white/70 rounded-lg p-2 whitespace-pre-wrap break-all text-[10.5px] text-slate-600" dir="ltr">${esc(x.raw)}</pre>` : ''}</div>`;
        };
        const lr = () => logsTable(el.querySelector('[data-logs]'), { status: el.querySelector('[data-log-status]').value, q: el.querySelector('[data-log-q]').value });
        el.querySelector('[data-log-status]').onchange = lr;
        el.querySelector('[data-log-re]').onclick = lr;
        let t = null; el.querySelector('[data-log-q]').oninput = () => { clearTimeout(t); t = setTimeout(lr, 400); };
        lr();
    }

    // جدولِ لاگِ کدها. rows داده شود = همان نمایش داده می‌شود (بانک مخاطبین)؛ وگرنه از سرور گرفته می‌شود
    const ST_CLS = { VERIFIED: 'bg-emerald-100 text-emerald-700', SENT: 'bg-sky-100 text-sky-700', FAILED: 'bg-rose-100 text-rose-700', LOCKED: 'bg-rose-100 text-rose-700',
                     EXPIRED: 'bg-slate-100 text-slate-500', REPLACED: 'bg-slate-100 text-slate-500', CANCELED: 'bg-slate-100 text-slate-500' };
    const SRC_FA = { DB: 'از بانک', TYPED: 'تایپِ کاربر', CONTACT: 'اشتراکِ شماره' };
    const mmss = s => fa(Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'));
    function logsHtml(rows, showPerson) {
        if (!rows.length) return '<p class="text-xs text-slate-400 text-center py-4">کدی ارسال نشده است.</p>';
        return `<div class="overflow-x-auto"><table class="w-full text-[11px]"><thead class="bg-slate-50 text-slate-500"><tr>
            <th class="p-2 text-right">زمانِ ارسال</th>${showPerson ? '<th class="p-2 text-right">شخص</th>' : ''}<th class="p-2 text-right">شماره</th><th class="p-2 text-right">پیامک</th>
            <th class="p-2 text-right">وضعیت</th><th class="p-2 text-center">تلاشِ اشتباه</th><th class="p-2 text-center">اعتبارِ باقی‌مانده</th><th class="p-2 text-right">تأیید</th></tr></thead>
            <tbody>${rows.map(r => `<tr class="border-t border-slate-100 align-top">
              <td class="p-2 whitespace-nowrap">${fa(r.created_j || r.created_at)}</td>
              ${showPerson ? `<td class="p-2">${esc(r.full_name || '—')}<small class="block text-slate-400">${fa(r.national_code || '')}</small></td>` : ''}
              <td class="p-2 whitespace-nowrap" dir="ltr">${fa(r.phone_masked)}<small class="block text-slate-400 text-right" dir="rtl">${esc(SRC_FA[r.phone_source] || '')}</small></td>
              <td class="p-2"><span class="font-bold ${+r.send_ok ? 'text-emerald-600' : 'text-rose-600'}">${+r.send_ok ? '✅ ارسال شد' : '❌ ناموفق'}</span>
                <small class="block text-slate-400">${esc(r.provider_fa)}${r.send_ms ? ' · ' + fa(r.send_ms) + 'ms' : ''}</small>
                ${r.send_status ? `<small class="block text-slate-500 max-w-[220px] break-words">${esc(r.send_status)}</small>` : ''}</td>
              <td class="p-2"><span class="px-2 py-0.5 rounded-full font-bold ${ST_CLS[r.status] || ''}">${esc(r.status_fa)}</span></td>
              <td class="p-2 text-center">${fa(r.attempts)} از ${fa(r.max_attempts)}</td>
              <td class="p-2 text-center" ${r.left_sec > 0 ? `data-left="${r.left_sec}"` : ''}>${r.left_sec > 0 ? mmss(r.left_sec) : '—'}</td>
              <td class="p-2 whitespace-nowrap">${r.verified_j ? fa(r.verified_j) : (r.last_try_j ? '<small class="text-slate-400">آخرین تلاش: ' + fa(r.last_try_j) + '</small>' : '—')}</td></tr>`).join('')}</tbody></table></div>`;
    }
    // شمارشِ معکوسِ «اعتبارِ باقی‌مانده»
    function tick(root) {
        clearInterval(root._otpTick);
        root._otpTick = setInterval(() => {
            if (!document.body.contains(root)) { clearInterval(root._otpTick); return; }
            root.querySelectorAll('[data-left]').forEach(td => { const v = Math.max(0, +td.dataset.left - 1); td.dataset.left = v; td.textContent = v > 0 ? mmss(v) : 'منقضی شد'; });
        }, 1000);
    }
    async function logsTable(root, filter, rows) {
        if (!root) return;
        if (!rows) {
            root.innerHTML = '<p class="text-xs text-slate-400">در حال بارگذاری…</p>';
            const d = await post(Object.assign({ action: 'logs' }, filter || {}));
            if (!d.ok) { root.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error)}</p>`; return; }
            const s = d.stats || {};
            root.innerHTML = `<p class="text-[11px] text-slate-500 mb-2">۲۴ ساعتِ اخیر: ${fa(s.n || 0)} کد · ${fa(s.sent || 0)} ارسالِ موفق · ${fa(s.failed || 0)} ناموفق · ${fa(s.verified || 0)} تأیید شده</p>` + logsHtml(d.rows, true);
        } else root.innerHTML = logsHtml(rows, false);
        tick(root);
    }

    window.SmsOtp = { settingsCard, logsTable, logsHtml };
})();
