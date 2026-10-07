// فایل: settings-nav.js
// صفحه‌ی «تنظیمات» با پنلِ راست (مثلِ پنلِ کاربری): هر کارتِ تنظیمات یک دسته دارد (data-scat) و فقط کارت‌های دسته‌ی
// انتخاب‌شده دیده می‌شوند. تنظیماتِ صفحه‌های دیگر (مالی، گزارش بازدید، اعلان‌ها، امنیت، کاربران) هم میان‌بُر دارند.
(function () {
    'use strict';
    const CATS = [
        ['general', 'عمومی و ظاهر', 'fa-sliders', 'لوگو، فاوآیکن، دامنه و اطلاعاتِ سیستم', '#6366f1', '#8b5cf6'],
        ['issue', 'صدور و استعلام', 'fa-file-shield', 'سقفِ بیمه‌نامه و استعلامِ حق بیمه', '#0ea5e9', '#14b8a6'],
        ['bots', 'ربات‌ها', 'fa-robot', 'ربات بله و ربات درخواست‌های شرکتی', '#10b981', '#0d9488'],
        ['work', 'پرسنل و کارکرد', 'fa-calendar-check', 'مرخصی، ساعتِ کار و تعطیلات', '#4f46e5', '#0ea5e9'],
        ['tools', 'ابزارها', 'fa-toolbox', 'تاریخِ قسط، عکس، اقساط و PDF', '#db2777', '#7c3aed'],
        ['comfort', 'امکاناتِ رفاهی', 'fa-mug-hot', 'کتابخانه‌ی موسیقی و سقفِ حجم', '#d946ef', '#ec4899'],
        ['data', 'پشتیبان و داده‌ها', 'fa-database', 'پشتیبان‌گیری، خروجی/ورود و بازنشانی', '#475569', '#0f172a'],
    ];
    // تنظیماتِ هر بخش (همان صفحه‌ای که در منوی خودِ آن بخش هم هست) داخلِ همین صفحه باز می‌شود
    const MODS = [
        ['life-settings', 'بیمه عمر', 'fa-heart-pulse', 'ستون‌های اکسل، قالبِ رسید، ربات', '#db2777', '#f97316'],
        ['hl-settings', 'درمان تکمیلی', 'fa-stethoscope', 'گروه‌های رسیدِ ربات، روش‌ها، مهلت‌ها', '#059669', '#0e7490'],
        ['mk-settings', 'بازاریابی و فروش', 'fa-bullhorn', 'کارمزد، پاداش، سطح، حقوق', '#c2410c', '#be185d'],
        ['lt-settings', 'اتوماسیونِ نامه‌ها', 'fa-envelope-open-text', 'الگوی شماره، سربرگ، قالب، امضا', '#4338ca', '#0891b2'],
        ['fin-settings', 'مالیِ شرکت‌ها (ثالث و بدنه)', 'fa-coins', 'اقساط، صورتحساب، کارمزد', '#16a34a', '#0d9488'],
        ['vr-settings', 'گزارشِ بازدید', 'fa-clipboard-check', 'قالب‌ها، فیلدها، بازدیدکنندگان', '#ea580c', '#f59e0b'],
    ];
    const LINKS = [
        ['announcements', 'اعلان‌های پاپ‌آپ', 'fa-bullhorn'], ['security', 'سپرِ امنیتی', 'fa-shield-halved'], ['staff-users', 'کاربران و دسترسی‌ها', 'fa-user-shield'],
    ];
    let borrowed = null, host = null, curKey = null;
    // محتوای امانتیِ یک بخش به زبانه‌ی خودش برمی‌گردد
    function release() {
        if (!borrowed) return;
        const src = document.getElementById('tab-' + borrowed.tab);
        if (src) borrowed.nodes.forEach(n => src.appendChild(n));
        borrowed = null;
        if (host) { host.innerHTML = ''; host.classList.add('stn-off'); }
    }
    const CSS = `
    .stn-wrap{display:flex;gap:20px;align-items:flex-start}
    .stn-side{width:262px;flex:none;position:sticky;top:12px;background:#fff;border:1px solid #eef2f7;border-radius:26px;padding:12px;box-shadow:0 14px 34px -26px rgba(15,23,42,.45)}
    .stn-side h4{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:900;color:#0f172a;padding:6px 8px 10px;border-bottom:1px solid #f1f5f9;margin-bottom:8px}
    .stn-side h4 i{color:#8b5cf6}
    .stn-it{width:100%;display:flex;align-items:center;gap:10px;border:0;background:transparent;border-radius:16px;padding:8px;cursor:pointer;text-align:right;font-family:inherit;position:relative;transition:background .15s}
    .stn-it:hover{background:#f8fafc}
    .stn-it .ic{width:36px;height:36px;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex:none;background:linear-gradient(135deg,var(--a),var(--b));box-shadow:0 8px 16px -10px var(--a)}
    .stn-it b{display:block;font-size:12px;font-weight:900;color:#1e293b}
    .stn-it small{display:block;font-size:10px;color:#94a3b8;line-height:1.6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:170px}
    .stn-it .n{margin-right:auto;font-size:10px;font-weight:900;color:#94a3b8;background:#f1f5f9;border-radius:999px;padding:1px 7px}
    .stn-it.on{background:linear-gradient(90deg,color-mix(in srgb,var(--a) 12%,#fff),#fff)}
    .stn-it.on::before{content:'';pointer-events:none;position:absolute;right:-12px;top:10px;bottom:10px;width:4px;border-radius:4px 0 0 4px;background:linear-gradient(var(--a),var(--b))}
    .stn-it.on b{color:var(--a)}
    .stn-sep{font-size:10px;font-weight:900;color:#94a3b8;padding:12px 10px 4px}
    .stn-lk{width:100%;display:flex;align-items:center;gap:8px;border:0;background:transparent;border-radius:12px;padding:7px 10px;cursor:pointer;font-weight:700;font-size:11.5px;font-family:inherit;color:#475569;text-align:right}
    .stn-lk:hover{background:#f8fafc;color:#4f46e5}
    .stn-lk i:first-child{width:18px;text-align:center;color:#94a3b8}
    .stn-lk .fa-arrow-up-left-from-circle{margin-right:auto;font-size:9px;opacity:.5}
    .stn-main{flex:1;min-width:0}
    .stn-head{display:flex;align-items:center;gap:14px;border-radius:24px;padding:18px 20px;color:#fff;background:linear-gradient(120deg,var(--a),var(--b));box-shadow:0 18px 36px -24px var(--a);position:relative;overflow:hidden}
    .stn-head::after{content:'';pointer-events:none;position:absolute;width:220px;height:220px;border-radius:50%;left:-60px;top:-110px;background:rgba(255,255,255,.12)}
    .stn-head .ic{width:50px;height:50px;border-radius:16px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:21px;flex:none}
    .stn-head h2{font-size:18px;font-weight:900}.stn-head p{font-size:11.5px;opacity:.85;margin-top:2px}
    .stn-off{display:none!important}
    @media (max-width:1023px){
      .stn-wrap{flex-direction:column;gap:12px}
      .stn-side{width:100%;position:static;display:flex;gap:6px;overflow-x:auto;padding:8px;border-radius:20px}
      .stn-side h4,.stn-side small,.stn-it .n,.stn-sep,.stn-lk{display:none}
      .stn-it{width:auto;flex:none;padding:6px 10px 6px 6px}.stn-it.on::before{display:none}
      .stn-it .ic{width:30px;height:30px;font-size:12px}
    }`;
    function init() {
        const tab = document.getElementById('tab-settings');
        if (!tab || tab.dataset.stn) return;
        tab.dataset.stn = '1';
        const cards = [...tab.querySelectorAll('[data-scat]')];
        const cats = CATS.filter(c => cards.some(x => x.dataset.scat === c[0]));
        if (!cats.length) return;
        const st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);
        const h1 = tab.querySelector(':scope > h1'); if (h1) h1.remove();
        const main = document.createElement('div'); main.className = 'stn-main space-y-6';
        while (tab.firstChild) main.appendChild(tab.firstChild);
        tab.classList.remove('space-y-6');
        const links = LINKS.filter(l => document.getElementById('tab-' + l[0]) && document.getElementById('nav-' + l[0]));
        const can = t => !window.permCan || window.permCan(t);
        const mods = MODS.filter(m => document.getElementById('tab-' + m[0]) && can(m[0]));
        const side = document.createElement('aside'); side.className = 'stn-side';
        side.innerHTML = `<h4><i class="fas fa-gear"></i>تنظیمات</h4>` + cats.map(c => `<button type="button" class="stn-it" data-c="${c[0]}" style="--a:${c[4]};--b:${c[5]}"><span class="ic"><i class="fas ${c[2]}"></i></span>
            <span style="min-width:0"><b>${c[1]}</b><small>${c[3]}</small></span><span class="n">${cards.filter(x => x.dataset.scat === c[0]).length}</span></button>`).join('')
            + (mods.length ? `<div class="stn-sep">تنظیماتِ بخش‌ها</div>` + mods.map(m => `<button type="button" class="stn-it" data-m="${m[0]}" style="--a:${m[4]};--b:${m[5]}"><span class="ic"><i class="fas ${m[2]}"></i></span>
                <span style="min-width:0"><b>${m[1]}</b><small>${m[3]}</small></span></button>`).join('') : '')
            + (links.length ? `<div class="stn-sep">مدیریت</div>` + links.map(l => `<button type="button" class="stn-lk" data-l="${l[0]}"><i class="fas ${l[2]}"></i>${l[1]}<i class="fas fa-arrow-up-left-from-circle"></i></button>`).join('') : '');
        const head = document.createElement('div'); head.className = 'stn-head';
        main.insertBefore(head, main.firstChild);
        const wrap = document.createElement('div'); wrap.className = 'stn-wrap';
        wrap.appendChild(side); wrap.appendChild(main); tab.appendChild(wrap);
        host = document.createElement('div'); host.className = 'stn-mod stn-off'; main.appendChild(host);
        const setHead = c => { head.style.cssText = `--a:${c[4]};--b:${c[5]}`; head.innerHTML = `<span class="ic"><i class="fas ${c[2]}"></i></span><div><h2>${c[1]}</h2><p>${c[3]}</p></div>`; };
        const openMod = t => {
            const m = mods.find(x => x[0] === t); if (!m) return;
            release();
            const src = document.getElementById('tab-' + t); if (!src) return;
            borrowed = {tab: t, nodes: [...src.childNodes]};
            borrowed.nodes.forEach(n => host.appendChild(n));
            host.classList.remove('stn-off');
            cards.forEach(x => x.classList.add('stn-off'));
            main.querySelectorAll('.grid').forEach(g => { if (g.querySelector('[data-scat]')) g.classList.add('stn-off'); });
            side.querySelectorAll('[data-c],[data-m]').forEach(b => b.classList.toggle('on', b.dataset.m === t));
            setHead([t, 'تنظیماتِ ' + m[1], m[2], m[3] + ' - همان صفحه‌ای که در منوی خودِ این بخش هم هست', m[4], m[5]]);
            curKey = 'm:' + t;
            try { localStorage.setItem('stn:cat', curKey); } catch (e) {}
            if (typeof window.tabLoad === 'function') window.tabLoad(t);
        };
        const show = k => {
            if (String(k).indexOf('m:') === 0 && mods.some(m => 'm:' + m[0] === k)) { openMod(k.slice(2)); return; }
            release();
            curKey = k;
            const c = cats.find(x => x[0] === k) || cats[0];
            cards.forEach(x => x.classList.toggle('stn-off', x.dataset.scat !== c[0]));
            // ردیف‌های شبکه‌ای که هیچ کارتِ دیدنی ندارند پنهان شوند
            main.querySelectorAll('.grid').forEach(g => { if (g.querySelector('[data-scat]')) g.classList.toggle('stn-off', ![...g.children].some(ch => ch.dataset && ch.dataset.scat && !ch.classList.contains('stn-off'))); });
            side.querySelectorAll('[data-c],[data-m]').forEach(b => b.classList.toggle('on', b.dataset.c === c[0]));
            setHead(c);
            try { localStorage.setItem('stn:cat', c[0]); } catch (e) {}
        };
        side.querySelectorAll('[data-c]').forEach(b => b.onclick = () => { show(b.dataset.c); if (window.innerWidth >= 1024) tab.scrollIntoView({block: 'start', behavior: 'smooth'}); });
        side.querySelectorAll('[data-m]').forEach(b => b.onclick = () => { openMod(b.dataset.m); if (window.innerWidth >= 1024) tab.scrollIntoView({block: 'start', behavior: 'smooth'}); });
        side.querySelectorAll('[data-l]').forEach(b => b.onclick = () => { if (typeof window.switchTab === 'function') window.switchTab(b.dataset.l); });
        restoreFn = () => { if (curKey && curKey.indexOf('m:') === 0 && !borrowed) openMod(curKey.slice(2)); };
        let last = null; try { last = localStorage.getItem('stn:cat'); } catch (e) {}
        show(last || cats[0][0]);
    }
    let restoreFn = () => {};
    window.SettingsNav = {init, release, restore: () => restoreFn(), show: k => { const b = document.querySelector(`.stn-it[data-c="${k}"],.stn-it[data-m="${k}"]`); if (b) b.click(); }};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
