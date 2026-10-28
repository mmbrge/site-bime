// فایل: hooshix-admin.js
// تنظیماتِ هوشیکس X ai برای مدیر کل (از دکمه‌ی «تنظیمات» در hooshix.js): زبانه‌ها —
//   عمومی (سرویس‌دهنده، مدل‌ها، سیستمِ داخلی، صدا) · سهمیه و فایل · پلن‌های اشتراک · تخفیف‌ها (بازه، هر سال، تولد) ·
//   درگاه‌های پرداخت · تاریخچه‌ی گفتگوها (همه‌ی کاربران، با ریزِ هر پیام) · پرداخت‌ها
// API: api/hooshix_actions.php (adm_*)؛ درگاه‌ها: api/_pay.php
(function () {
    if (window.HooshixAdmin) return;
    let X = null;   // {post, esc, fa, num, toast, md, logo, reinit}
    const TABS = [['gen', 'fa-sliders', 'عمومی'], ['quota', 'fa-gauge', 'سهمیه و فایل'], ['plans', 'fa-crown', 'پلن‌ها'], ['offers', 'fa-gift', 'تخفیف‌ها'],
                  ['gw', 'fa-credit-card', 'درگاه‌های پرداخت'], ['hist', 'fa-clock-rotate-left', 'تاریخچه‌ی گفتگوها'], ['pays', 'fa-receipt', 'پرداخت‌ها']];
    const en = s => String(s == null ? '' : s).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const jd = el => { if (window.JDatePick) el.querySelectorAll('[data-jdate]').forEach(i => { try { JDatePick.attach(i); } catch (e) {} }); };

    async function open(ctx) {
        X = ctx;
        const { post, esc, toast } = X;
        const [g, sh] = await Promise.all([post({ action: 'adm_get' }), post({ action: 'adm_shop_get' })]);
        if (!g.ok) return toast(g.error, 'error');
        if (!sh.ok) return toast(sh.error, 'error');
        const m = document.createElement('div'); m.className = 'hx-modal';
        m.innerHTML = `<div style="width:min(1180px,100%)"><div class="flex items-center justify-between p-4 border-b gap-2"><b class="text-sm flex items-center gap-2">${X.logo(26)}تنظیماتِ هوشیکس X ai ${g.ready ? '<span class="text-emerald-600 text-[11px]">● آماده</span>' : '<span class="text-rose-500 text-[11px]">● تنظیم نشده</span>'}</b><button class="hx-btn bg-slate-100" data-x>بستن</button></div>
            <div class="flex flex-wrap gap-1 px-4 pt-3" data-tabs>${TABS.map(([k, ic, l], i) => `<button class="hx-btn ${i ? 'bg-slate-50 text-slate-600' : 'text-white'}" data-tab="${k}" style="${i ? '' : 'background:linear-gradient(120deg,#7c3aed,#06b6d4)'}"><i class="fas ${ic} ml-1"></i>${l}</button>`).join('')}</div>
            <div class="p-4 overflow-y-auto" data-body></div></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) { m.remove(); X.reinit(); } };
        document.body.appendChild(m);
        const body = m.querySelector('[data-body]');
        const panes = {};
        TABS.forEach(([k]) => { const d = document.createElement('div'); d.dataset.pane = k; d.style.display = 'none'; body.appendChild(d); panes[k] = d; });
        const S = { g, sh };
        renderGen(panes.gen, S); renderQuota(panes.quota, S);
        const loaded = {};
        const show = k => {
            m.querySelectorAll('[data-tab]').forEach(b => { const on = b.dataset.tab === k; b.className = 'hx-btn ' + (on ? 'text-white' : 'bg-slate-50 text-slate-600'); b.style.background = on ? 'linear-gradient(120deg,#7c3aed,#06b6d4)' : ''; });
            Object.entries(panes).forEach(([pk, p]) => p.style.display = pk === k ? '' : 'none');
            if (!loaded[k]) { loaded[k] = 1; ({ plans: renderPlans, offers: renderOffers, gw: renderGw, hist: renderHist, pays: renderPays })[k]?.(panes[k], S); }
        };
        m.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => show(b.dataset.tab));
        show('gen');
        // ذخیره‌ی «عمومی» و «سهمیه و فایل» با هم
        const collect = () => { const o = {}; m.querySelectorAll('[data-pane="gen"] [data-k], [data-pane="quota"] [data-k]').forEach(x => { o[x.dataset.k] = x.type === 'checkbox' ? (x.checked ? '1' : '0') : x.value; });
            o.model_names = {}; m.querySelectorAll('[data-mn]').forEach(x => { if (x.value.trim()) o.model_names[x.dataset.mn] = x.value.trim(); }); return o; };
        m.querySelectorAll('[data-gsave]').forEach(b => b.onclick = async () => { const r = await post({ action: 'adm_save', cfg: collect() }); if (!r.ok) return toast(r.error, 'error'); toast('ذخیره شد.', 'success'); });
        const tb = m.querySelector('[data-test]');
        if (tb) tb.onclick = async () => {
            const tr = m.querySelector('[data-tr]'); tr.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            await post({ action: 'adm_save', cfg: collect() });
            const r = await post({ action: 'adm_test' });
            tr.innerHTML = !r.ok ? `<span class="text-rose-600">${esc(r.error)}</span>` : `<span class="${r.result.ok ? 'text-emerald-600' : 'text-rose-600'}">${r.result.ok ? '✅' : '❌'} ${esc(r.result.text)} <small class="text-slate-400">(${X.fa(r.result.ms)} ms)</small></span>`;
        };
    }
    const f = (c, k, l, extra, type) => `<label class="hx-f">${l}<input class="hx-in" data-k="${k}" type="${type || 'text'}" value="${X.esc(c[k] == null ? '' : c[k])}" ${extra || ''}></label>`;
    const yn = (c, k, l) => `<label class="hx-f flex items-center gap-2 mt-5"><input type="checkbox" data-k="${k}" ${c[k] === '1' ? 'checked' : ''} style="accent-color:#7c3aed"> ${l}</label>`;
    const saveBtn = (test) => `<div class="flex flex-wrap gap-2 mt-4"><button class="hx-btn text-white" style="background:linear-gradient(120deg,#7c3aed,#06b6d4)" data-gsave><i class="fas fa-floppy-disk ml-1"></i>ذخیره</button>${test ? '<button class="hx-btn bg-slate-100" data-test><i class="fas fa-plug ml-1"></i>آزمایشِ اتصال</button><span data-tr class="text-[11px] self-center"></span>' : ''}</div>`;

    // ---------------- عمومی ----------------
    function renderGen(el, S) {
        const g = S.g, c = g.cfg, esc = X.esc;
        el.innerHTML = `<div class="space-y-4"><div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <label class="hx-f">سرویس‌دهنده<select class="hx-in" data-k="provider">${Object.entries(g.providers).map(([k, l]) => `<option value="${k}" ${c.provider === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
            <div data-custom class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">${f(c, 'base_url', 'آدرسِ API (مثلاً https://api.openai.com/v1)', 'dir="ltr"')}${f(c, 'token', 'کلیدِ API' + (c.has_token ? ' (ذخیره شده؛ خالی = همان)' : ''), 'dir="ltr" autocomplete="off"', 'password')}</div>
            <label class="hx-f md:col-span-3">مدل‌ها (با کاما؛ اولی پیش‌فرض است و کاربر از بینِ همین‌ها انتخاب می‌کند)<input class="hx-in" data-k="models" dir="ltr" value="${esc(c.models)}" placeholder="gpt-4o-mini, gpt-4o"></label>
            <div class="md:col-span-3 rounded-2xl border border-emerald-200 bg-emerald-50/50 p-3 space-y-2">
                <b class="text-[12px] text-emerald-900"><i class="fas fa-server ml-1"></i>سیستمِ داخلی (Ollama روی کامپیوترهای دفتر) ${!g.ollama.enabled ? '<span class="text-rose-500 text-[11px] font-bold">● ' + esc(g.ollama.why || 'روشن نشده') + '</span>' : (g.ollama.online ? '<span class="text-emerald-600 text-[11px]">● وصل</span>' : '<span class="text-amber-600 text-[11px]">● هیچ سیستمی وصل نیست (' + esc(g.ollama.ago) + ')</span>')}</b>
                ${yn(c, 'ollama_on', 'گفتگو با مدل‌های سیستمِ داخلی هم ممکن باشد (پیام‌ها از سایت بیرون نمی‌روند؛ هر گفتگو روی همان سیستمی می‌ماند که شروعش کرد)')}
                <label class="hx-f">مدل‌های سیستمِ داخلی برای هوشیکس (با کاما؛ خالی = همه‌ی مدل‌های نصب‌شده روی همه‌ی سیستم‌ها)<input class="hx-in" data-k="ollama_models" dir="ltr" value="${esc(c.ollama_models)}" placeholder="${esc((g.ollama.models || []).join(', ') || 'qwen3:4b')}"></label></div>
            <div class="md:col-span-3 rounded-2xl border border-violet-100 bg-violet-50/40 p-3">
                <b class="text-[12px] text-violet-900"><i class="fas fa-tag ml-1"></i>نامِ نمایشیِ مدل‌ها برای کاربران</b>
                <p class="text-[10.5px] text-slate-500 mt-1 mb-2">هر نامی بنویسید کاربر همان را در انتخابِ مدل می‌بیند (مثلاً «هوشیکس سریع» یا «هوشیکس تصویر»)؛ خالی = خودِ نامِ مدل.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">${Object.keys(c.all_models || {}).map(id => `<label class="flex items-center gap-2 text-[11px]"><code dir="ltr" class="bg-white border rounded-lg px-2 py-1 min-w-0 max-w-[45%] truncate" title="${esc(id)}">${esc(c.all_models[id])}</code>
                    <input class="hx-in !mt-0 flex-1" data-mn="${esc(id)}" value="${esc((c.model_names || {})[id] || '')}" placeholder="${esc(c.all_models[id])}"></label>`).join('') || '<p class="text-[11px] text-slate-400">هنوز مدلی نیست (سرویس‌دهنده یا سیستمِ داخلی را تنظیم کنید).</p>'}</div></div>
            ${f(c, 'temperature', 'خلاقیت (۰ تا ۱.۵)', 'dir="ltr"')}${f(c, 'max_reply', 'سقفِ طولِ هر پاسخ (توکن)', 'dir="ltr"')}${f(c, 'history', 'تعدادِ پیام‌های قبلی که همراه فرستاده می‌شود', 'dir="ltr"')}
            <label class="hx-f md:col-span-3">دستورِ اختصاصی برای سبکِ پاسخ‌ها (اختیاری)<textarea class="hx-in" data-k="system" rows="3" placeholder="مثلاً: همیشه کوتاه و با لحنِ صمیمی جواب بده.">${esc(c.system)}</textarea></label></div>
            <div class="rounded-2xl border border-cyan-100 bg-cyan-50/40 p-3 grid grid-cols-1 md:grid-cols-4 gap-3">
                <div>${yn(c, 'voice', 'میکروفون (حرف بزنید، متن می‌شود)')}${yn(c, 'voice_reply', 'خواندنِ پاسخ با صدا و «گفتگوی صوتی»')}</div>${f(c, 'stt_model', 'مدلِ گفتار به متن', 'dir="ltr"')}${f(c, 'tts_model', 'مدلِ متن به گفتار', 'dir="ltr"')}
                <label class="hx-f">صدای گوینده<select class="hx-in" data-k="tts_voice">${g.voices.map(v => `<option ${c.tts_voice === v ? 'selected' : ''}>${v}</option>`).join('')}</select></label></div>
            <p class="text-[10.5px] text-slate-500 leading-6"><i class="fas fa-shield-halved text-emerald-600 ml-1"></i>هوشیکس هیچ ابزار یا دسترسی‌ای به دیتابیس و فایل‌های سایت ندارد؛ فقط پیام‌های همان گفتگو (و فایل‌هایی که خودِ کاربر می‌فرستد) فرستاده می‌شود. نوشتنِ کدِ مخرب، بدافزار، فیشینگ و نفوذ در دستورِ ثابتِ هوشیکس ممنوع است.</p>
            ${saveBtn(true)}</div>`;
        const cust = () => { el.querySelector('[data-custom]').style.display = el.querySelector('[data-k="provider"]').value === 'custom' ? '' : 'none'; };
        el.querySelector('[data-k="provider"]').onchange = cust; cust();
    }
    // ---------------- سهمیه و فایل ----------------
    function renderQuota(el, S) {
        const c = S.g.cfg;
        el.innerHTML = `<div class="space-y-4">
            <div class="rounded-2xl border border-violet-100 bg-violet-50/50 p-3 grid grid-cols-1 md:grid-cols-4 gap-3">
                <label class="hx-f">چه کسانی دسترسی دارند؟<select class="hx-in" data-k="access"><option value="list" ${c.access === 'list' ? 'selected' : ''}>فقط کاربرانی که روشن کنم</option><option value="all" ${c.access === 'all' ? 'selected' : ''}>همه‌ی کاربرانِ پنل (با سهمیه‌ی پیش‌فرض)</option></select></label>
                <div>${yn(c, 'enabled', 'هوشیکس روشن است')}${yn(c, 'mask', 'پوشاندنِ کد ملی/موبایل/کارت پیش از ارسال')}</div>
                <div>${yn(c, 'shop', 'خریدِ اشتراک برای کاربران باز باشد')}</div></div>
            <div class="rounded-2xl border border-slate-200 p-3 grid grid-cols-2 md:grid-cols-5 gap-3">
                <p class="col-span-2 md:col-span-5 text-[11.5px] font-black text-slate-700"><i class="fas fa-gauge ml-1 text-violet-500"></i>سهمیه‌ی پیش‌فرضِ هر نفر (۰ = نامحدود؛ برای هر کاربر در «کاربران ← دسترسی‌ها» جدا هم می‌شود)</p>
                ${f(c, 'def_daily', 'پیام در روز', 'dir="ltr"')}${f(c, 'def_day_tokens', 'توکن در روز (هر روز صفر)', 'dir="ltr"')}${f(c, 'def_week_tokens', 'توکن در هفته (هر شنبه صفر)', 'dir="ltr"')}${f(c, 'def_month_tokens', 'توکن در ماه', 'dir="ltr"')}
                ${f(c, 'ollama_rate', 'ضریبِ توکنِ سیستمِ داخلی (۰.۵ = نصف)', 'dir="ltr"')}</div>
            <div class="rounded-2xl border border-emerald-100 bg-emerald-50/40 p-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                <p class="md:col-span-3 text-[11.5px] font-black text-slate-700"><i class="fas fa-paperclip ml-1 text-emerald-600"></i>فرستادنِ فایل به هوشیکس</p>
                <div>${yn(c, 'files_default', 'برای همه مجاز باشد (وگرنه فقط کاربرانی که در دسترسی‌ها روشن کنید یا اشتراکِ «با فایل» دارند)')}</div>
                ${f(c, 'file_max_mb', 'حداکثر حجمِ هر فایل (مگابایت)', 'dir="ltr"')}${f(c, 'file_max_count', 'حداکثر تعداد فایل در هر پیام', 'dir="ltr"')}
                <p class="md:col-span-3 text-[10.5px] text-slate-500 leading-6">مجاز: عکس (JPG، PNG، WebP، GIF)، PDF، Word (docx)، Excel (xlsx) و متن. نوعِ هر فایل از بایت‌های خودش بررسی می‌شود؛ فایلِ اجرایی، اسکریپت، دوپسوندی، Word/Excelِ ماکرودار، PDFِ اسکریپت‌دار یا با فایلِ پیوست رد می‌شود. فایلِ خام نگه داشته نمی‌شود: عکس از نو ساخته می‌شود و از بقیه فقط متن.</p></div>
            ${saveBtn(false)}</div>`;
    }

    // ---------------- پلن‌ها ----------------
    function renderPlans(el, S) {
        const { esc, post, toast, num } = X;
        let plans = (S.sh.plans || []).map(p => Object.assign({}, p));
        const draw = () => {
            el.innerHTML = `<p class="text-[11px] text-slate-500 leading-6 mb-2">قیمت به تومان. سقف‌ها در زمانِ اشتراک جایگزینِ سقفِ کاربر می‌شوند (هر کدام بیشتر بود؛ ۰ = نامحدود). خریدِ دوباره‌ی همان پلن از پایانِ آن تمدید می‌شود. پلنی که خریده شده با حذف فقط غیرفعال می‌شود.</p>
                <div class="overflow-x-auto"><table class="hx-tbl"><thead><tr><th>نام</th><th>قیمت (تومان)</th><th>روز</th><th>توکنِ روزانه</th><th>توکنِ هفتگی</th><th>توکنِ ماهانه</th><th>پیام/روز</th><th>فایل</th><th>برچسب</th><th>رنگ</th><th>توضیح</th><th>ترتیب</th><th>فعال</th><th></th></tr></thead>
                <tbody>${plans.map((p, i) => `<tr data-i="${i}"><td><input type="text" data-p="name" value="${esc(p.name)}" style="width:130px"></td><td><input type="text" data-p="price" value="${esc(p.price)}" dir="ltr"></td>
                    <td><input type="text" data-p="days" value="${esc(p.days)}" dir="ltr" style="width:50px"></td><td><input type="text" data-p="day_tokens" value="${esc(p.day_tokens)}" dir="ltr"></td><td><input type="text" data-p="week_tokens" value="${esc(p.week_tokens)}" dir="ltr"></td>
                    <td><input type="text" data-p="month_tokens" value="${esc(p.month_tokens)}" dir="ltr"></td><td><input type="text" data-p="daily_msgs" value="${esc(p.daily_msgs)}" dir="ltr" style="width:50px"></td>
                    <td><input type="checkbox" data-p="files" ${+p.files ? 'checked' : ''}></td><td><input type="text" data-p="badge" value="${esc(p.badge || '')}" style="width:70px"></td>
                    <td><input type="color" data-p="color" value="${esc(p.color || '#7c3aed')}" style="width:34px;height:26px"></td><td><input type="text" data-p="descr" value="${esc(p.descr || '')}" style="width:170px"></td>
                    <td><input type="text" data-p="sort" value="${esc(p.sort)}" dir="ltr" style="width:40px"></td><td><input type="checkbox" data-p="active" ${+p.active ? 'checked' : ''}></td>
                    <td><button class="text-rose-600" data-del="${i}" title="حذف"><i class="fas fa-trash"></i></button></td></tr>`).join('')}</tbody></table></div>
                <div class="flex flex-wrap gap-2 mt-3"><button class="hx-btn bg-slate-100" data-add><i class="fas fa-plus ml-1"></i>پلنِ تازه</button>
                    <button class="hx-btn text-white" style="background:linear-gradient(120deg,#7c3aed,#06b6d4)" data-save><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی پلن‌ها</button>
                    <span class="text-[11px] text-slate-400 self-center">فروشِ کل تا امروز: ${num(S.sh.stats.n)} پرداخت · ${num(Math.round(S.sh.stats.s / 10))} تومان</span></div>`;
            el.querySelectorAll('tr[data-i]').forEach(tr => tr.querySelectorAll('[data-p]').forEach(x => x.oninput = x.onchange = () => {
                const p = plans[+tr.dataset.i]; p[x.dataset.p] = x.type === 'checkbox' ? (x.checked ? 1 : 0) : (x.dir === 'ltr' ? en(x.value) : x.value);
            }));
            el.querySelectorAll('[data-del]').forEach(b => b.onclick = () => { plans.splice(+b.dataset.del, 1); draw(); });
            el.querySelector('[data-add]').onclick = () => { plans.push({ name: '', price: 0, days: 30, day_tokens: 100000, week_tokens: 500000, month_tokens: 0, daily_msgs: 0, files: 0, badge: '', color: '#7c3aed', descr: '', sort: plans.length + 1, active: 1 }); draw(); };
            el.querySelector('[data-save]').onclick = async () => { const r = await post({ action: 'adm_plans_save', plans }); if (!r.ok) return toast(r.error, 'error'); plans = r.plans; S.sh.plans = r.plans; toast('پلن‌ها ذخیره شد.', 'success'); draw(); };
        };
        draw();
    }

    // ---------------- تخفیف‌ها ----------------
    function renderOffers(el, S) {
        const { esc, post, toast, fa } = X;
        let offers = (S.sh.offers || []).map(o => ({ id: o.id, name: o.name, kind: o.kind, percent: o.percent, plan_ids: o.plan_ids || [], start: o.start_j ? fa(o.start_j) : '', end: o.end_j ? fa(o.end_j) : '',
                                                    md_from: o.md_from || '', md_to: o.md_to || '', days_before: o.days_before, days_after: o.days_after, gift_plan_id: o.gift_plan_id || '', active: +o.active, note: o.note || '' }));
        const KF = { range: 'بازه‌ی تاریخ (یک بار)', yearly: 'هر سال (مثلاً روزِ بیمه)', birthday: 'تولدِ هر شخص' };
        const draw = () => {
            const plans = S.sh.plans || [];
            el.innerHTML = `<p class="text-[11px] text-slate-500 leading-6 mb-2">در هر خرید بهترین تخفیفِ فعال حساب می‌شود. «تولد» از تاریخِ تولدی است که هر همکار در «تنظیماتِ من» ثبت کرده؛ هر تولد یک بار. با «هدیه» روزِ تولد آن پلن خودکار و رایگان فعال می‌شود. تخفیفِ ۱۰۰٪ = بدونِ درگاه.</p>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">${offers.map((o, i) => `<div class="rounded-2xl border ${o.active ? 'border-pink-200 bg-pink-50/40' : 'border-slate-200 bg-slate-50'} p-3 space-y-2" data-i="${i}">
                    <div class="flex flex-wrap items-center gap-2"><input class="hx-in !mt-0 !w-44" data-o="name" value="${esc(o.name)}" placeholder="نامِ تخفیف">
                        <select class="hx-in !mt-0 !w-auto" data-o="kind">${Object.entries(KF).map(([k, l]) => `<option value="${k}" ${o.kind === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
                        <label class="hx-f flex items-center gap-1">٪<input class="hx-in !mt-0 !w-16" dir="ltr" data-o="percent" value="${esc(o.percent)}"></label>
                        <label class="hx-f flex items-center gap-1"><input type="checkbox" data-o="active" ${o.active ? 'checked' : ''} style="accent-color:#db2777">فعال</label>
                        <button class="mr-auto text-rose-600" data-del="${i}"><i class="fas fa-trash"></i></button></div>
                    ${o.kind === 'range' ? `<div class="grid grid-cols-2 gap-2"><label class="hx-f">از تاریخ<input class="hx-in" dir="ltr" data-jdate data-o="start" value="${esc(o.start)}" placeholder="1405/08/01"></label><label class="hx-f">تا تاریخ<input class="hx-in" dir="ltr" data-jdate data-o="end" value="${esc(o.end)}" placeholder="1405/08/07"></label></div>` : ''}
                    ${o.kind === 'yearly' ? `<div class="grid grid-cols-2 gap-2"><label class="hx-f">هر سال از (ماه/روز)<input class="hx-in" dir="ltr" data-o="md_from" value="${esc(fa(o.md_from))}" placeholder="08/01"></label><label class="hx-f">تا (ماه/روز)<input class="hx-in" dir="ltr" data-o="md_to" value="${esc(fa(o.md_to))}" placeholder="08/03"></label></div>` : ''}
                    ${o.kind === 'birthday' ? `<div class="grid grid-cols-3 gap-2"><label class="hx-f">روز قبل از تولد<input class="hx-in" dir="ltr" data-o="days_before" value="${esc(o.days_before)}"></label><label class="hx-f">روز بعد از تولد<input class="hx-in" dir="ltr" data-o="days_after" value="${esc(o.days_after)}"></label>
                        <label class="hx-f">هدیه‌ی رایگان (اختیاری)<select class="hx-in" data-o="gift_plan_id"><option value="">— بدونِ هدیه —</option>${plans.map(p => `<option value="${p.id}" ${+o.gift_plan_id === +p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label></div>` : ''}
                    <div class="text-[11px]"><b class="text-slate-600">برای پلن‌ها:</b> ${plans.map(p => `<label class="inline-flex items-center gap-1 ml-2"><input type="checkbox" data-pl="${p.id}" ${!o.plan_ids.length || o.plan_ids.includes(+p.id) ? 'checked' : ''}>${esc(p.name)}</label>`).join('')}</div>
                    <input class="hx-in" data-o="note" value="${esc(o.note)}" placeholder="یادداشت (اختیاری)"></div>`).join('') || '<p class="text-xs text-slate-400">تخفیفی نیست.</p>'}</div>
                <div class="flex flex-wrap gap-2 mt-3"><button class="hx-btn bg-slate-100" data-add="range"><i class="fas fa-plus ml-1"></i>تخفیفِ بازه‌ی تاریخ</button><button class="hx-btn bg-slate-100" data-add="yearly"><i class="fas fa-plus ml-1"></i>مناسبتِ هر سال</button>
                    <button class="hx-btn bg-slate-100" data-add="birthday"><i class="fas fa-cake-candles ml-1"></i>تخفیف/هدیه‌ی تولد</button>
                    <button class="hx-btn text-white" style="background:linear-gradient(120deg,#db2777,#7c3aed)" data-save><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تخفیف‌ها</button></div>`;
            jd(el);
            el.querySelectorAll('[data-i]').forEach(card => {
                const o = offers[+card.dataset.i];
                card.querySelectorAll('[data-o]').forEach(x => x.oninput = x.onchange = () => { o[x.dataset.o] = x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; if (x.dataset.o === 'kind') draw(); });
                card.querySelectorAll('[data-pl]').forEach(x => x.onchange = () => { const all = [...card.querySelectorAll('[data-pl]')]; const on = all.filter(y => y.checked).map(y => +y.dataset.pl); o.plan_ids = on.length === all.length ? [] : on; });
            });
            el.querySelectorAll('[data-del]').forEach(b => b.onclick = () => { offers.splice(+b.dataset.del, 1); draw(); });
            el.querySelectorAll('[data-add]').forEach(b => b.onclick = () => { offers.unshift({ name: b.dataset.add === 'birthday' ? 'تولدت مبارک' : (b.dataset.add === 'yearly' ? 'روزِ بیمه' : ''), kind: b.dataset.add, percent: 20, plan_ids: [], start: '', end: '',
                                                                                     md_from: b.dataset.add === 'yearly' ? '08/01' : '', md_to: b.dataset.add === 'yearly' ? '08/03' : '', days_before: 0, days_after: 7, gift_plan_id: '', active: 1, note: '' }); draw(); });
            el.querySelector('[data-save]').onclick = async () => {
                const r = await post({ action: 'adm_offers_save', offers: offers.map(o => Object.assign({}, o, { start: en(o.start), end: en(o.end), md_from: en(o.md_from), md_to: en(o.md_to) })) });
                if (!r.ok) return toast(r.error, 'error');
                S.sh.offers = r.offers; offers = r.offers.map(o => ({ id: o.id, name: o.name, kind: o.kind, percent: o.percent, plan_ids: o.plan_ids || [], start: o.start_j ? fa(o.start_j) : '', end: o.end_j ? fa(o.end_j) : '', md_from: o.md_from || '', md_to: o.md_to || '',
                                                                      days_before: o.days_before, days_after: o.days_after, gift_plan_id: o.gift_plan_id || '', active: +o.active, note: o.note || '' }));
                toast('تخفیف‌ها ذخیره شد.', 'success'); draw();
            };
        };
        draw();
    }

    // ---------------- درگاه‌ها ----------------
    function renderGw(el, S) {
        const { esc, post, toast } = X, T = S.sh.pay_types;
        let gws = (S.sh.gateways || []).map(g => JSON.parse(JSON.stringify(g)));
        const draw = () => {
            el.innerHTML = `<p class="text-[11px] text-slate-500 leading-6 mb-2">چند درگاه را می‌توانید هم‌زمان روشن کنید؛ کاربر هنگامِ خرید انتخاب می‌کند (ترتیبِ همین فهرست). مبلغ همیشه از سرورِ سایت خوانده و بعد از بازگشت با APIِ خودِ درگاه تأیید می‌شود.
                آدرسِ بازگشت (اگر درگاه خواست ثبتش کنید): <code dir="ltr" class="bg-slate-100 rounded px-1">${esc(S.sh.callback)}</code></p>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">${gws.map((g, i) => `<div class="rounded-2xl border ${g.enabled ? 'border-violet-200 bg-white' : 'border-slate-200 bg-slate-50'} p-3 space-y-2" data-i="${i}">
                    <div class="flex flex-wrap items-center gap-2"><i class="fas ${esc((T[g.type] || {}).icon || 'fa-credit-card')} text-violet-500"></i>
                        <select class="hx-in !mt-0 !w-auto" data-g="type">${Object.entries(T).map(([k, t]) => `<option value="${k}" ${g.type === k ? 'selected' : ''}>${esc(t.fa)}</option>`).join('')}</select>
                        <input class="hx-in !mt-0 !w-40" data-g="title" value="${esc(g.title)}" placeholder="نامِ نمایشی">
                        <label class="hx-f flex items-center gap-1"><input type="checkbox" data-g="enabled" ${g.enabled ? 'checked' : ''} style="accent-color:#7c3aed">روشن</label>
                        ${(T[g.type] || {}).sandbox ? `<label class="hx-f flex items-center gap-1"><input type="checkbox" data-g="sandbox" ${g.sandbox ? 'checked' : ''}>حالتِ آزمایشیِ درگاه (sandbox)</label>` : ''}
                        <span class="mr-auto flex gap-1"><button class="hx-btn !px-2 !py-1 bg-slate-100" data-up="${i}" ${i ? '' : 'disabled'}><i class="fas fa-arrow-up"></i></button><button class="hx-btn !px-2 !py-1 bg-slate-100 text-rose-600" data-del="${i}"><i class="fas fa-trash"></i></button></span></div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">${((T[g.type] || {}).fields || []).map(([k, l, kind]) => `<label class="hx-f">${esc(l)}<input class="hx-in" dir="ltr" data-c="${k}" type="${kind === 'secret' ? 'password' : 'text'}" autocomplete="off" value="${esc((g.cfg || {})[k] || '')}"></label>`).join('')
                        || '<p class="text-[11px] text-slate-500">برای آزمایشِ روندِ خرید بدونِ پول؛ فقط مدیر کل می‌بیند.</p>'}</div></div>`).join('') || '<p class="text-xs text-slate-400">هنوز درگاهی اضافه نشده.</p>'}</div>
                <div class="flex flex-wrap gap-2 mt-3"><button class="hx-btn bg-slate-100" data-add><i class="fas fa-plus ml-1"></i>افزودنِ درگاه</button>
                    <button class="hx-btn text-white" style="background:linear-gradient(120deg,#7c3aed,#06b6d4)" data-save><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی درگاه‌ها</button></div>`;
            el.querySelectorAll('[data-i]').forEach(card => {
                const g = gws[+card.dataset.i];
                card.querySelectorAll('[data-g]').forEach(x => x.oninput = x.onchange = () => { g[x.dataset.g] = x.type === 'checkbox' ? x.checked : x.value; if (x.dataset.g === 'type') { g.cfg = {}; g.title = ''; draw(); } });
                card.querySelectorAll('[data-c]').forEach(x => x.oninput = () => { g.cfg = g.cfg || {}; g.cfg[x.dataset.c] = x.value; });
            });
            el.querySelectorAll('[data-del]').forEach(b => b.onclick = () => { gws.splice(+b.dataset.del, 1); draw(); });
            el.querySelectorAll('[data-up]').forEach(b => b.onclick = () => { const i = +b.dataset.up; [gws[i - 1], gws[i]] = [gws[i], gws[i - 1]]; draw(); });
            el.querySelector('[data-add]').onclick = () => { gws.push({ id: '', type: 'zarinpal', title: '', enabled: true, sandbox: false, cfg: {} }); draw(); };
            el.querySelector('[data-save]').onclick = async () => { const r = await post({ action: 'adm_gw_save', gateways: gws }); if (!r.ok) return toast(r.error, 'error'); gws = r.gateways; S.sh.gateways = r.gateways; toast('درگاه‌ها ذخیره شد.', 'success'); draw(); };
        };
        draw();
    }

    // ---------------- تاریخچه‌ی گفتگوها ----------------
    function renderHist(el) {
        const { esc, post, toast, fa, num } = X;
        const F = { q: '', user_id: '', from: '', to: '', page: 0 };
        el.innerHTML = `<div class="flex flex-wrap items-end gap-2 mb-3"><label class="hx-f">جستجو (کد، عنوان، نام)<input class="hx-in" data-f="q" placeholder="HX-000123"></label>
            <label class="hx-f">کاربر<select class="hx-in" data-f="user_id"><option value="">همه</option></select></label>
            <label class="hx-f">از تاریخ<input class="hx-in" dir="ltr" data-jdate data-f="from" placeholder="1405/08/01"></label><label class="hx-f">تا تاریخ<input class="hx-in" dir="ltr" data-jdate data-f="to" placeholder="1405/08/30"></label>
            <button class="hx-btn text-white" style="background:linear-gradient(120deg,#7c3aed,#06b6d4)" data-go><i class="fas fa-magnifying-glass ml-1"></i>نمایش</button></div><div data-list></div>`;
        jd(el);
        const load = async () => {
            el.querySelectorAll('[data-f]').forEach(x => F[x.dataset.f] = en(x.value));
            const box = el.querySelector('[data-list]'); box.innerHTML = '<p class="text-xs text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i></p>';
            const r = await post(Object.assign({ action: 'adm_threads' }, F)); if (!r.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(r.error)}</p>`; return; }
            const us = el.querySelector('[data-f="user_id"]');
            if (us.options.length <= 1) us.innerHTML = '<option value="">همه</option>' + r.users.map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('');
            us.value = F.user_id;
            box.innerHTML = `<div class="overflow-x-auto"><table class="hx-tbl"><thead><tr><th>کد</th><th>کاربر</th><th>عنوان</th><th>مدل</th><th>شروع</th><th>آخرین پیام</th><th>پیام</th><th>توکن (حساب‌شده)</th><th>میانگینِ پاسخ</th><th></th></tr></thead><tbody>
                ${r.rows.map(t => `<tr><td><code class="text-violet-700 font-black" dir="ltr">${esc(t.code)}</code></td><td>${esc(t.user)}${t.live ? ' <span class="text-emerald-600 text-[10px] font-black">● در حالِ گفتگو</span>' : ''}</td>
                    <td>${esc(t.title)}${t.deleted ? ' <span class="text-[10px] text-rose-500">(کاربر حذفش کرده)</span>' : ''}</td><td dir="ltr" class="text-[10.5px]">${esc(t.model)}</td><td>${esc(fa(t.started))}</td><td>${esc(fa(t.last))}</td>
                    <td>${fa(t.msgs)}</td><td>${num(t.tokens)}${t.billed !== t.tokens ? ` <span class="text-slate-400">(${num(t.billed)})</span>` : ''}</td><td>${t.avg_ms ? fa((t.avg_ms / 1000).toFixed(1)) + ' ث' : '—'}</td>
                    <td><button class="hx-btn !py-1 bg-violet-50 text-violet-700" data-open="${t.id}">ریزِ گفتگو</button></td></tr>`).join('') || '<tr><td colspan="10" class="text-center text-slate-400 py-6">گفتگویی نیست.</td></tr>'}</tbody></table></div>
                <div class="flex gap-2 mt-2">${F.page ? '<button class="hx-btn bg-slate-100" data-prev>قبلی</button>' : ''}${r.more ? '<button class="hx-btn bg-slate-100" data-next>بعدی</button>' : ''}</div>`;
            box.querySelectorAll('[data-open]').forEach(b => b.onclick = () => transcript(+b.dataset.open));
            const pv = box.querySelector('[data-prev]'), nx = box.querySelector('[data-next]');
            if (pv) pv.onclick = () => { F.page--; load(); }; if (nx) nx.onclick = () => { F.page++; load(); };
        };
        el.querySelector('[data-go]').onclick = () => { F.page = 0; load(); };
        el.querySelector('[data-f="q"]').onkeydown = e => { if (e.key === 'Enter') { F.page = 0; load(); } };
        load();
    }
    async function transcript(id) {
        const { esc, post, toast, fa, num, md } = X;
        const r = await post({ action: 'adm_thread', id }); if (!r.ok) return toast(r.error, 'error');
        const t = r.thread, ms = r.messages;
        const tok = ms.reduce((a, m) => a + m.tokens, 0), bil = ms.reduce((a, m) => a + m.billed, 0);
        const m = document.createElement('div'); m.className = 'hx-modal';
        m.innerHTML = `<div style="width:min(900px,100%)"><div class="flex items-center justify-between p-4 border-b gap-2"><b class="text-sm"><code class="text-violet-700" dir="ltr">${esc(t.code)}</code> — ${esc(t.title)}</b><button class="hx-btn bg-slate-100" data-x>بستن</button></div>
            <div class="px-4 pt-3 text-[11.5px] text-slate-600 flex flex-wrap gap-x-4 gap-y-1"><span><i class="fas fa-user ml-1"></i>${esc(t.user)}</span><span>شروع ${esc(fa(t.started))}</span><span>آخرین پیام ${esc(fa(t.last))}</span>
                <span>${fa(ms.length)} پیام</span><span>${num(tok)} توکن${bil !== tok ? ' (حساب‌شده ' + num(bil) + ')' : ''}</span>${t.deleted ? '<span class="text-rose-500">کاربر این گفتگو را حذف کرده</span>' : ''}</div>
            <div class="p-4 overflow-y-auto space-y-2">${ms.map(x => `<div class="rounded-2xl border ${x.role === 'user' ? 'border-slate-200 bg-white' : 'border-violet-100 bg-violet-50/40'} p-3">
                <div class="flex flex-wrap gap-x-3 text-[10.5px] text-slate-500 mb-1"><b class="${x.role === 'user' ? 'text-slate-700' : 'text-violet-700'}">${x.role === 'user' ? esc(t.user) : 'هوشیکس'}</b><span>${esc(fa(x.at))}</span>
                    ${x.role === 'assistant' ? `<span>⏱ ${fa((x.ms / 1000).toFixed(1))} ث</span><span>${num(x.tokens)} توکن${x.billed !== x.tokens ? ' (حساب‌شده ' + num(x.billed) + ')' : ''}</span><span dir="ltr">${esc(x.model)}</span>${x.node ? `<span>🖥 ${esc(x.node)}</span>` : ''}` : ''}</div>
                ${(x.files || []).length ? `<div class="hx-mf">${x.files.map(f => f.kind === 'image' ? `<a href="api/hooshix_actions.php?action=file&id=${f.id}" target="_blank" rel="noopener"><img src="api/hooshix_actions.php?action=file&id=${f.id}" alt=""></a>` : `<span><i class="fas fa-file ml-1"></i>${esc(f.name)}</span>`).join('')}</div>` : ''}
                <div class="hx-b !p-0" dir="auto" style="${x.role === 'user' ? 'white-space:pre-wrap' : ''}">${x.role === 'user' ? esc(x.text) : md(x.text)}</div></div>`).join('')}</div></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
        document.body.appendChild(m);
    }

    // ---------------- پرداخت‌ها ----------------
    function renderPays(el) {
        const { esc, post, toast, fa, num } = X;
        el.innerHTML = `<div class="flex flex-wrap items-end gap-2 mb-3"><label class="hx-f">وضعیت<select class="hx-in" data-st><option value="">همه</option><option value="PAID">پرداخت‌شده</option><option value="REDIRECT">در درگاه</option><option value="FAILED">ناموفق</option><option value="CANCELLED">لغو شده</option></select></label>
            <button class="hx-btn bg-slate-100" data-go><i class="fas fa-rotate ml-1"></i>تازه‌سازی</button></div><div data-list></div>`;
        const load = async () => {
            const box = el.querySelector('[data-list]');
            const r = await post({ action: 'adm_payments', status: el.querySelector('[data-st]').value }); if (!r.ok) { box.innerHTML = `<p class="text-xs text-rose-600">${esc(r.error)}</p>`; return; }
            box.innerHTML = `<div class="overflow-x-auto"><table class="hx-tbl"><thead><tr><th>شماره</th><th>کاربر</th><th>بابت</th><th>مبلغ (تومان)</th><th>درگاه</th><th>وضعیت</th><th>کدِ پیگیری</th><th>تاریخ</th><th></th></tr></thead><tbody>
                ${r.rows.map(p => `<tr><td>${fa('HX' + p.id)}</td><td>${esc(p.user)}</td><td>${esc(p.title)}</td><td>${num(p.toman)}</td><td>${esc(p.gw)}</td>
                    <td class="${p.status === 'PAID' ? 'text-emerald-600' : (p.status === 'FAILED' ? 'text-rose-600' : 'text-slate-500')} font-bold" title="${esc(p.error)}">${esc(p.status_fa)}${p.status === 'PAID' && !p.fulfilled ? ' ⚠️' : ''}</td>
                    <td dir="ltr">${esc(fa(p.ref))}</td><td>${esc(fa(p.at))}</td><td>${p.status === 'PAID' && !p.fulfilled ? `<button class="hx-btn !py-1 bg-amber-50 text-amber-700" data-ful="${p.id}">فعال‌کردنِ اشتراک</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="9" class="text-center text-slate-400 py-6">پرداختی نیست.</td></tr>'}</tbody></table></div>`;
            box.querySelectorAll('[data-ful]').forEach(b => b.onclick = async () => { const x = await post({ action: 'adm_pay_fulfil', id: +b.dataset.ful }); toast(x.ok ? x.msg : x.error, x.ok ? 'success' : 'error'); load(); });
        };
        el.querySelector('[data-go]').onclick = load; el.querySelector('[data-st]').onchange = load;
        load();
    }

    window.HooshixAdmin = { open };
})();
