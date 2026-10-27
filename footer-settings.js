// فایل: footer-settings.js
// «تنظیمات ← فوتر (توسعه‌دهنده و نسخه)»: متن، رنگ و نمایش/پنهانِ «طراحی و توسعه» و «حقوق و نسخه» برای پنل و صفحه‌ی ورود
// (api/footer_actions.php)؛ پیش‌نمایش همان لحظه، و بعد از ذخیره فوترِ همین صفحه هم عوض می‌شود.
(function () {
    'use strict';
    const API = 'api/footer_actions.php';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => window.showToast ? showToast(m, t || 'info') : alert(m);
    let S = null, tab = 'panel';
    const TEXTS = [['credit_label', 'برچسب (مثلاً «طراحی و توسعه سیستم :»)'], ['credit_name', 'نامِ توسعه‌دهنده'], ['credit_sub', 'متنِ کوچکِ کنارِ نام'], ['credit_link', 'لینکِ نام (اختیاری، https://...)'],
                   ['copy_text', 'متنِ حقوق (کپی‌رایت)'], ['version_text', 'متنِ نسخه (خالی = نسخه‌ی جاریِ سایت)']];
    const COLORS = [['label_color', 'رنگِ برچسب'], ['name_color', 'رنگِ نام'], ['sub_color', 'رنگِ متنِ کوچک'], ['copy_color', 'رنگِ حقوق و نسخه'], ['box_bg', 'زمینه‌ی کادر'], ['box_border', 'حاشیه‌ی کادر']];
    const SHOWS = [['show_credit', 'نمایشِ «طراحی و توسعه»'], ['show_copy', 'نمایشِ متنِ حقوق'], ['show_version', 'نمایشِ نسخه']];

    function preview(f, version) {
        const st = o => { const p = Object.entries(o).filter(([, v]) => v).map(([k, v]) => k + ':' + v); return p.length ? ` style="${p.join(';')}"` : ''; };
        let h = '';
        if (f.show_credit && (f.credit_label || f.credit_name || f.credit_sub))
            h += `<div class="footer-box"${st({background: f.box_bg, 'border-color': f.box_border})}>${f.credit_label ? `<span${st({color: f.label_color})}>${esc(f.credit_label)}</span>` : ''}${f.credit_name ? `<span class="font-black mr-1"${st({color: f.name_color || '#2563eb'})}>${esc(f.credit_name)}</span>` : ''}${f.credit_sub ? `<span class="text-xs ml-1" style="font-family:Arial,sans-serif;color:${f.sub_color || '#94a3b8'}">${esc(f.credit_sub)}</span>` : ''}</div>`;
        const ver = f.show_version ? (f.version_text || version) : '';
        if ((f.show_copy && f.copy_text) || ver) h += `<p class="text-[10px]"${st({color: f.copy_color || '#64748b'})}>${f.show_copy ? esc(f.copy_text) : ''}${ver ? ` <span dir="ltr" style="font-family:Arial,sans-serif">- ${esc(ver)}</span>` : ''}</p>`;
        return h || '<p class="text-[11px] text-slate-400">فوتر پنهان است (چیزی نمایش داده نمی‌شود).</p>';
    }

    function draw(root) {
        const f = S[tab], v = window.SITE_VERSION || '';
        root.innerHTML = `<div class="flex gap-2 mb-3">${[['panel', 'پنل (داخلِ سایت)', 'fa-table-columns'], ['login', 'صفحه‌ی ورود', 'fa-right-to-bracket']].map(([k, t, ic]) =>
                `<button type="button" data-ft="${k}" class="px-3 py-1.5 rounded-xl text-xs font-black border ${tab === k ? 'bg-violet-600 text-white border-violet-600' : 'bg-white text-slate-600 border-slate-200'}"><i class="fas ${ic} ml-1"></i>${t}</button>`).join('')}</div>
            <div class="grid md:grid-cols-2 gap-4">
              <div class="space-y-3">
                <div class="flex flex-wrap gap-3">${SHOWS.map(([k, l]) => `<label class="flex items-center gap-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-xl px-3 py-2 cursor-pointer"><input type="checkbox" data-fk="${k}" class="accent-violet-600" ${f[k] ? 'checked' : ''}>${l}</label>`).join('')}</div>
                ${TEXTS.map(([k, l]) => `<label class="block text-[10.5px] font-bold text-slate-500">${l}<input data-fk="${k}" value="${esc(f[k])}" ${k === 'credit_link' ? 'dir="ltr"' : ''} class="w-full border border-slate-200 rounded-xl px-3 py-2 text-xs mt-1 bg-white"></label>`).join('')}
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">${COLORS.map(([k, l]) => `<label class="text-[10.5px] font-bold text-slate-500 bg-white border border-slate-200 rounded-xl p-2 flex flex-col gap-1">${l}
                    <span class="flex items-center gap-1"><input type="color" data-fc="${k}" value="${/^#[0-9a-f]{6}$/i.test(f[k]) ? f[k] : '#ffffff'}" class="w-8 h-7 rounded cursor-pointer border-0 p-0">
                    <input data-fk="${k}" value="${esc(f[k])}" dir="ltr" placeholder="پیش‌فرض" class="flex-1 min-w-0 border border-slate-200 rounded-lg px-1.5 py-1 text-[10.5px]"></span></label>`).join('')}</div>
                <p class="text-[10px] text-slate-400 leading-5">رنگِ خالی یعنی رنگِ پیش‌فرضِ همان صفحه. برای پنهان کردنِ کلِ فوتر، هر سه گزینه‌ی نمایش را بردارید.</p>
              </div>
              <div><p class="text-[11px] font-bold text-slate-500 mb-2"><i class="fas fa-eye ml-1"></i>پیش‌نمایش</p>
                <div data-pv class="footer-credit rounded-2xl p-4 ${tab === 'login' ? 'bg-slate-900' : 'bg-slate-50'}" style="margin:0">${preview(f, v)}</div>
                <div class="flex flex-wrap gap-2 mt-4">
                  <button type="button" data-a="save" class="bg-violet-600 hover:bg-violet-700 text-white text-xs font-black px-5 py-2 rounded-xl"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی هر دو</button>
                  <button type="button" data-a="reset" class="bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black px-4 py-2 rounded-xl"><i class="fas fa-rotate-left ml-1"></i>پیش‌فرضِ همین بخش</button>
                </div></div>
            </div>`;
        const upd = () => { root.querySelector('[data-pv]').innerHTML = preview(S[tab], v); };
        root.querySelectorAll('[data-ft]').forEach(b => b.onclick = () => { tab = b.dataset.ft; draw(root); });
        root.querySelectorAll('[data-fk]').forEach(el => el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', () => {
            S[tab][el.dataset.fk] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
            const c = root.querySelector(`[data-fc="${el.dataset.fk}"]`); if (c && /^#[0-9a-f]{6}$/i.test(el.value)) c.value = el.value;
            upd();
        }));
        root.querySelectorAll('[data-fc]').forEach(el => el.addEventListener('input', () => { S[tab][el.dataset.fc] = el.value; const t = root.querySelector(`[data-fk="${el.dataset.fc}"]`); if (t) t.value = el.value; upd(); }));
        root.querySelector('[data-a="reset"]').onclick = () => { S[tab] = Object.assign({}, S.defaults[tab]); draw(root); };
        root.querySelector('[data-a="save"]').onclick = async () => {
            let d;
            try { d = await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'save', panel: S.panel, login: S.login})})).json(); } catch (e) { d = {ok: false}; }
            if (!d.ok) { toast(d.error || 'ذخیره نشد.', 'error'); return; }
            S.panel = d.panel; S.login = d.login;
            const live = document.getElementById('site-footer');
            if (live) { live.innerHTML = preview(S.panel, v); live.style.display = ''; }
            toast('فوتر ذخیره شد.', 'success');
        };
    }

    async function render(root) {
        if (!root) return;
        let d;
        try { d = await (await fetch(API + '?action=get')).json(); } catch (e) { d = {ok: false}; }
        if (!d.ok) { root.innerHTML = `<p class="text-xs text-rose-600">${esc(d.error || 'خطا در خواندنِ تنظیمات')}</p>`; return; }
        S = {panel: d.panel, login: d.login, defaults: d.defaults};
        draw(root);
    }
    window.FooterSettings = {render};
})();
