// فایل: company-pick.js
// پیشنهادِ «شرکتِ موجود» زیرِ خانه‌ی نامِ شرکت در فرم‌های معرفی‌نامه (ثبتِ دستی، بررسیِ صف، ویرایش):
// وقتی نامِ معرفی‌نامه کامل/دقیق نیست (مثلاً «پارس خودرو» به‌جای «شرکت پارس خودرو سهامی خاص»)، شرکت‌های مشابهِ فهرست
// نشان داده می‌شوند و با یک کلیک انتخاب می‌شوند. نامِ تازه هم پیش از ثبت گفته می‌شود که به‌عنوانِ شرکتِ «اطلاعات ناقص» اضافه می‌شود.
(function () {
    let list = null, loading = null;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // مثلِ company_name_key در api/_case_helpers.php
    const key = s => String(s || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/ة/g, 'ه').replace(/[أإآ]/g, 'ا')
        .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
        .replace(/شرکت|سهامی|خاص|عام|مسئولیت|محدود|تعاونی|موسسه|مؤسسه|گروه|صنعتی|و\s*شرکا/g, '')
        .replace(/[\s​-‏‪-‮\-ـ«»"'().،,_/]+/g, '').toLowerCase();
    const bigrams = s => { const o = new Map(); for (let i = 0; i < s.length - 1; i++) { const b = s.substr(i, 2); o.set(b, (o.get(b) || 0) + 1); } return o; };
    const dice = (a, b) => {
        if (a.length < 2 || b.length < 2) return 0;
        const A = bigrams(a), B = bigrams(b); let n = 0;
        A.forEach((v, k) => { n += Math.min(v, B.get(k) || 0); });
        return (2 * n) / (a.length + b.length - 2);
    };
    function load() {
        if (list) return Promise.resolve(list);
        if (!loading) loading = fetch('api/record_actions.php?action=company_names').then(r => r.json())
            .then(d => (list = d.ok ? d.companies || [] : []), () => (list = [])).finally(() => { loading = null; });
        return loading;
    }
    function similar(name) {
        const k = key(name);
        if (k.length < 2) return [];
        return (list || []).map(c => {
            const ck = key(c.name);
            let s = 0;
            if (!ck) s = 0;
            else if (ck === k) s = 100;
            else if (ck.includes(k) || k.includes(ck)) s = 80 - Math.abs(ck.length - k.length);
            else { const d = dice(ck, k); if (d >= 0.6) s = Math.round(d * 70); }
            return { id: c.id, name: c.name, s };
        }).filter(x => x.s > 0).sort((a, b) => b.s - a.s).slice(0, 6);
    }
    function boxOf(input) {
        if (input._cpBox) return input._cpBox;
        const b = document.createElement('div');
        b.className = 'cp-box text-[10.5px] leading-6 mt-1';
        const anchor = input.closest('.float-input') || input;
        anchor.insertAdjacentElement('afterend', b);
        b.addEventListener('mousedown', e => e.preventDefault());   // کلیک روی پیشنهاد فوکوس را از خانه نگیرد
        b.addEventListener('click', e => {
            const t = e.target.closest('[data-cp]'); if (!t) return;
            input.value = t.dataset.cp;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        input._cpBox = b;
        return b;
    }
    async function refresh(input) {
        if (!input) return;
        const b = boxOf(input);
        const v = String(input.value || '').trim();
        if (!v) { b.innerHTML = ''; return; }
        await load();
        if (String(input.value || '').trim() !== v) return;
        if ((list || []).some(c => c.name === v)) { b.innerHTML = '<span class="text-emerald-700 font-bold"><i class="fas fa-circle-check ml-1"></i>شرکتِ موجود در فهرست</span>'; return; }
        const s = similar(v);
        b.innerHTML = s.length
            ? `<span class="text-amber-700 font-bold">شرکتِ مشابهِ موجود (برای انتخاب کلیک کنید):</span> ${s.map(x => `<button type="button" data-cp="${esc(x.name)}" class="inline-block bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold rounded-lg px-2 py-0.5 m-0.5">${esc(x.name)}</button>`).join('')}
               <span class="text-slate-400 block">اگر هیچ‌کدام نیست، با همین نام به‌عنوانِ شرکتِ تازه («اطلاعات ناقص») به فهرستِ شرکت‌ها اضافه می‌شود.</span>`
            : '<span class="text-slate-500"><i class="fas fa-circle-plus ml-1 text-amber-500"></i>شرکتِ تازه: بعد از ثبت با «اطلاعات ناقص» به فهرستِ شرکت‌ها اضافه می‌شود؛ از صفحه‌ی شرکت‌ها تکمیلش کنید.</span>';
    }
    function attach(input) {
        if (!input || input._cpOn) return;
        input._cpOn = true;
        let t = null;
        const go = () => { clearTimeout(t); t = setTimeout(() => refresh(input), 200); };
        input.addEventListener('input', go);
        input.addEventListener('change', go);
        input.addEventListener('focus', go);
        refresh(input);
    }
    window.CompanyPick = { attach, refresh, reload: () => { list = null; return load(); } };
})();
