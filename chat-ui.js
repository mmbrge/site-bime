// فایل: chat-ui.js
// پیام‌رسانِ یکپارچه‌ی «بیمه با ما» - یک کامپوننت برای همه‌جا (پنلِ داخلی، وب‌اپِ کارکنان، پنلِ شرکت‌ها)
//   ChatUI.mount(element, {
//     mode: 'full' | 'single',          // full: فهرستِ گفتگوها + گفتگو (پنل) · single: فقط یک گفتگو (وب‌اپ/شرکت)
//     theme: 'light' | 'dark',
//     call(action, data) => Promise<json>,         // اکشن‌های JSON (chat_threads, chat_open, chat_poll, ...)
//     upload(action, FormData) => Promise<json>,   // ارسالِ فایل (chat_send با فایل)
//     key: 'P:12',                       // در حالتِ single (سرور خودش هم تعیین می‌کند)
//     openKey: 'S:5',                    // در حالتِ full: گفتگویی که از اول باز شود
//     onUnread(n)                        // تعدادِ خوانده‌نشده‌ها (برای نشانِ منو)
//   })
// امکانات: فایل/عکس/صوت، پاسخ، ویرایش، حذف، موضوعِ پیام (درخواستِ مربوط)، فیلتر بر اساسِ موضوع،
// منوی کلیک‌راست (و لمسِ طولانی در موبایل)، خوانده‌شدن، «در حال نوشتن»، ایموجی، کشیدن و رها کردن و چسباندنِ عکس.
(function () {
    'use strict';
    const fa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // نتیجه‌ی جستجو: متن با بخشِ پیداشده‌ی زردرنگ
    const highlight = (s, q) => { const t = esc(s), k = esc(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); return t.replace(new RegExp(k, 'gi'), x => `<mark class="cx-mark">${x}</mark>`).replace(/\n/g, '<br>'); };
    const linkify = s => esc(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');
    const EMOJI = '😀 😁 😂 🙂 😊 😍 😎 🤔 😅 😢 😡 👍 👎 👏 🙏 🤝 💪 🎉 ❤️ 💯 ✅ ❌ ⚠️ ❓ ⏳ 📎 📄 📷 🚗 🚙 🛻 🚚 🔧 📞 💬 📌 ⭐ 🔥 👌 😉'.split(' ');
    const TYPE_META = { PERSON: ['کارکنان', 'fa-user', '#0ea5e9'], COMPANY: ['شرکت', 'fa-building', '#8b5cf6'], STAFF: ['همکار', 'fa-user-tie', '#10b981'] };
    const REF_ICON = { CASE: 'fa-file-shield', COMPANY_REQUEST: 'fa-building-circle-check', VISIT_REPORT: 'fa-file-circle-check' };
    const todayJ = (() => {
        try { return en(new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())).replace(/[^\d/]/g, ''); }
        catch (e) { return ''; }
    })();
    const yestJ = (() => {
        try { return en(new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(Date.now() - 864e5))).replace(/[^\d/]/g, ''); }
        catch (e) { return ''; }
    })();
    const dayLabel = d => d === todayJ ? 'امروز' : d === yestJ ? 'دیروز' : fa(d);
    const initials = s => { const w = String(s || '؟').trim().split(/\s+/); return (w[0] || '؟').charAt(0) + (w[1] ? w[1].charAt(0) : ''); };
    const hue = s => { let h = 0; for (const c of String(s || '')) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
    const pad2 = n => String(n).padStart(2, '0');
    // تاریخِ شمسیِ یک Date به شکلِ 1405/07/08 (ارقامِ لاتین، برای مقایسه)
    const jdate = d => { try { return en(new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(d)).replace(/[^\d/]/g, ''); } catch (e) { return ''; } };
    // تاریخی که کاربر تایپ کرده (۱۴۰۵/۷/۱ یا 1405-07-01) => 1405/07/01
    const normJ = s => { const p = en(s).trim().split(/[\/\-.\s]+/).filter(Boolean); if (p.length !== 3 || p[0].length !== 4) return ''; return p[0] + '/' + pad2(p[1]) + '/' + pad2(p[2]); };

    // ---- عکس‌های آماده‌ی پروفایل (preset:1 تا preset:16) ----
    const PRESETS = [['🦁', '#fbbf24', '#ea580c'], ['🐼', '#94a3b8', '#1e293b'], ['🦊', '#fdba74', '#c2410c'], ['🐯', '#fde047', '#f97316'],
        ['🐨', '#cbd5e1', '#475569'], ['🦉', '#c4b5fd', '#6d28d9'], ['🐬', '#7dd3fc', '#1d4ed8'], ['🌸', '#fbcfe8', '#db2777'],
        ['🌿', '#86efac', '#15803d'], ['🚀', '#a5b4fc', '#4338ca'], ['⭐', '#fef08a', '#f59e0b'], ['🎯', '#fda4af', '#be123c'],
        ['🚗', '#67e8f9', '#0e7490'], ['🛡️', '#6ee7b7', '#047857'], ['👨‍💼', '#93c5fd', '#2563eb'], ['👩‍💼', '#f9a8d4', '#a21caf']];
    const avatarUrl = av => (av && !/^preset:/.test(av)) ? '/' + String(av).replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/') : null;
    // عکسِ پروفایل: عکسِ آماده، عکسِ بارگذاری‌شده، یا حروفِ اولِ نام. o: {cls, type, online, size}
    function avatarHtml(av, name, o) {
        injectCss(); o = o || {};
        const m = /^preset:(\d+)$/.exec(av || ''), pr = m && PRESETS[Number(m[1]) - 1], url = avatarUrl(av);
        const bg = pr ? `linear-gradient(135deg,${pr[1]},${pr[2]})` : `linear-gradient(135deg,hsl(${hue(name)},70%,55%),hsl(${(hue(name) + 40) % 360},70%,45%))`;
        const inner = url ? `<img src="${esc(url)}" alt="" loading="lazy">` : pr ? `<em>${pr[0]}</em>` : esc(initials(name));
        const tm = o.type && TYPE_META[o.type];
        const sz = o.size ? `width:${o.size}px;height:${o.size}px;font-size:${Math.round(o.size * .36)}px;` : '';
        return `<span class="cx-av ${o.cls || ''}" style="${sz}background:${bg}">${inner}${tm ? `<small style="color:${tm[2]}"><i class="fas ${tm[1]}"></i></small>` : ''}${o.online ? '<b class="cx-on" title="آنلاین"></b>' : ''}</span>`;
    }
    // «آنلاین» / «آخرین بازدید ...» از {online, ago}؛ at = لحظه‌ی دریافت از سرور (تا زمان هم جلو برود)
    function seenLabel(p, at, group) {
        if (!p) return '';
        if (p.online) return group ? 'کارشناسان آنلاین هستند' : 'آنلاین';
        const pre = group ? 'آخرین حضورِ کارشناس: ' : 'آخرین بازدید: ';
        if (p.ago === null || p.ago === undefined) return group ? '' : 'هنوز وارد نشده';
        const ago = p.ago + Math.max(0, Math.round((Date.now() - (at || Date.now())) / 1000));
        if (ago < 60) return pre + 'لحظاتی پیش';
        if (ago < 3600) return pre + fa(Math.floor(ago / 60)) + ' دقیقه پیش';
        const d = new Date(Date.now() - ago * 1000), hm = fa(pad2(d.getHours()) + ':' + pad2(d.getMinutes())), jd = jdate(d);
        if (jd === jdate(new Date())) return pre + 'امروز ساعت ' + hm;
        if (jd === jdate(new Date(Date.now() - 864e5))) return pre + 'دیروز ساعت ' + hm;
        if (ago < 7 * 86400) return pre + fa(Math.floor(ago / 86400)) + ' روز پیش';
        return pre + fa(jd) + ' ساعت ' + hm;
    }

    // ---- پنجره‌های تایید / ورودیِ متن (به‌جای confirm و prompt مرورگر) ----
    // dialog({title, message, ok, cancel, danger, icon, input: {value, placeholder}, theme}) => Promise<true|false|متن|null>
    function dialog(o) {
        injectCss();
        return new Promise(resolve => {
            const ov = document.createElement('div');
            ov.className = 'cx-dlg-ov' + (o.theme === 'dark' ? ' dark' : '');
            ov.innerHTML = `<div class="cx-dlg"><div class="cx-dlg-ic ${o.danger ? 'danger' : ''}"><i class="fas ${o.icon || (o.danger ? 'fa-trash' : o.input ? 'fa-pen' : 'fa-circle-question')}"></i></div>
                <b class="cx-dlg-t"></b><p class="cx-dlg-m"></p>${o.input ? '<input class="cx-dlg-in">' : ''}
                <div class="cx-dlg-b"><button class="cx-dlg-ok ${o.danger ? 'danger' : ''}"></button><button class="cx-dlg-no"></button></div></div>`;
            ov.querySelector('.cx-dlg-t').textContent = o.title || '';
            const msg = ov.querySelector('.cx-dlg-m'); msg.textContent = o.message || ''; if (!o.message) msg.remove();
            ov.querySelector('.cx-dlg-ok').textContent = o.ok || (o.danger ? 'بله، حذف شود' : 'تایید');
            ov.querySelector('.cx-dlg-no').textContent = o.cancel || 'انصراف';
            const inp = ov.querySelector('.cx-dlg-in');
            if (inp) { inp.value = (o.input.value || ''); inp.placeholder = o.input.placeholder || ''; }
            const done = v => { ov.classList.remove('on'); document.removeEventListener('keydown', key, true); setTimeout(() => ov.remove(), 220); resolve(v); };
            const ok = () => done(inp ? inp.value : true), no = () => done(inp ? null : false);
            const key = e => { if (e.key === 'Escape') { e.preventDefault(); no(); } if (e.key === 'Enter' && (inp || document.activeElement === ov.querySelector('.cx-dlg-ok'))) { e.preventDefault(); ok(); } };
            ov.querySelector('.cx-dlg-ok').onclick = ok; ov.querySelector('.cx-dlg-no').onclick = no;
            ov.onclick = e => { if (e.target === ov) no(); };
            document.addEventListener('keydown', key, true);
            document.body.appendChild(ov);
            requestAnimationFrame(() => ov.classList.add('on'));
            setTimeout(() => (inp || ov.querySelector('.cx-dlg-ok')).focus(), 60);
        });
    }
    function miniToast(msg, type) {
        injectCss();
        const t = document.createElement('div');
        t.className = 'cx-toast ' + (type || ''); t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.classList.add('out'), 2300); setTimeout(() => t.remove(), 2700);
    }

    // ---- انتخابِ عکسِ پروفایل: عکس‌های آماده، گالری/سیستم، یا بدونِ عکس ----
    // pickAvatar({current, name, theme}) => Promise<{avatar:'preset:N'|''} | {file: Blob} | null>
    function pickAvatar(o) {
        injectCss();
        return new Promise(resolve => {
            let sel = o.current || '', blob = null, blobUrl = null;
            const ov = document.createElement('div');
            ov.className = 'cx-dlg-ov' + (o.theme === 'dark' ? ' dark' : '');
            ov.innerHTML = `<div class="cx-dlg cx-pick"><b class="cx-dlg-t">عکسِ پروفایل</b>
                <div class="cx-pick-pv"></div>
                <div class="cx-pick-g">${PRESETS.map((p, i) => `<button type="button" data-p="preset:${i + 1}" style="background:linear-gradient(135deg,${p[1]},${p[2]})">${p[0]}</button>`).join('')}</div>
                <div class="cx-pick-a"><label class="cx-pick-up"><i class="fas fa-image"></i> انتخاب از گالری / سیستم<input type="file" accept="image/*" hidden></label>
                    <button type="button" class="cx-pick-none"><i class="fas fa-font"></i> بدونِ عکس</button></div>
                <div class="cx-dlg-b"><button class="cx-dlg-ok">ذخیره</button><button class="cx-dlg-no">انصراف</button></div></div>`;
            const pv = ov.querySelector('.cx-pick-pv');
            const paint = () => {
                pv.innerHTML = blobUrl ? `<span class="cx-av" style="width:96px;height:96px"><img src="${blobUrl}" alt=""></span>` : avatarHtml(sel, o.name || '', { size: 96 });
                ov.querySelectorAll('[data-p]').forEach(b => b.classList.toggle('on', !blob && b.dataset.p === sel));
            };
            ov.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { sel = b.dataset.p; blob = null; blobUrl = null; paint(); });
            ov.querySelector('.cx-pick-none').onclick = () => { sel = ''; blob = null; blobUrl = null; paint(); };
            ov.querySelector('input[type=file]').onchange = async e => {
                const f = e.target.files[0]; if (!f) return;
                try { blob = await squareImage(f, 360); blobUrl = URL.createObjectURL(blob); paint(); }
                catch (err) { miniToast('این فایل عکس نیست یا باز نشد.', 'error'); }
            };
            const done = v => { ov.classList.remove('on'); setTimeout(() => ov.remove(), 220); resolve(v); };
            ov.querySelector('.cx-dlg-ok').onclick = () => done(blob ? { file: blob } : { avatar: sel });
            ov.querySelector('.cx-dlg-no').onclick = () => done(null);
            ov.onclick = e => { if (e.target === ov) done(null); };
            document.body.appendChild(ov); paint();
            requestAnimationFrame(() => ov.classList.add('on'));
        });
    }
    // برشِ مربعیِ وسطِ عکس و کوچک کردن (حجمِ کم برای بارگذاری)
    function squareImage(file, size) {
        return new Promise((res, rej) => {
            const img = new Image(), url = URL.createObjectURL(file);
            img.onload = () => {
                const s = Math.min(img.naturalWidth, img.naturalHeight), c = document.createElement('canvas');
                c.width = c.height = size;
                c.getContext('2d').drawImage(img, (img.naturalWidth - s) / 2, (img.naturalHeight - s) / 2, s, s, 0, 0, size, size);
                URL.revokeObjectURL(url);
                c.toBlob(b => b ? res(b) : rej(new Error('blob')), 'image/jpeg', .88);
            };
            img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('img')); };
            img.src = url;
        });
    }

    // ---- «زنده‌ام»: هر چند ثانیه حضور را به سرور می‌گوید و با بستنِ صفحه «آفلاین» می‌شود ----
    // heartbeat({ping: () => Promise<json>, offline: () => void, every: 10000, onUnread(n)})
    function heartbeat(o) {
        let busy = false;
        const tick = async () => {
            if (busy) return; busy = true;
            try { const d = await o.ping(); if (d && d.ok && o.onUnread && d.unread !== undefined) o.onUnread(d.unread); } catch (e) {}
            busy = false;
        };
        tick();
        const t = setInterval(tick, o.every || 10000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
        window.addEventListener('pagehide', () => { try { o.offline && o.offline(); } catch (e) {} });
        return { stop() { clearInterval(t); }, now: tick };
    }

    // ------------------------------------------------------------------ استایل
    function injectCss() {
        if (document.getElementById('cx-css')) return;
        const st = document.createElement('style');
        st.id = 'cx-css';
        st.textContent = `
.cx{--bg:#f1f5f9;--panel:#fff;--line:#e2e8f0;--text:#0f172a;--muted:#64748b;--soft:#f8fafc;--me1:#4f46e5;--me2:#7c3aed;--them:#fff;--themtext:#0f172a;--hover:#eef2ff;--acc:#4f46e5;--shadow:0 20px 45px -20px rgba(15,23,42,.35);
  direction:rtl;display:flex;height:100%;min-height:0;background:var(--bg);color:var(--text);font-family:inherit;border-radius:1.4rem;overflow:hidden;position:relative;font-size:13px}
.cx.cx-dark{--bg:#0b1120;--panel:#111a2e;--line:#1e293b;--text:#e2e8f0;--muted:#94a3b8;--soft:#0f172a;--them:#1e293b;--themtext:#e2e8f0;--hover:#1e293b;--acc:#818cf8;--shadow:0 20px 45px -20px rgba(0,0,0,.7)}
.cx *{box-sizing:border-box}
.cx button{font-family:inherit;cursor:pointer}
.cx-side{width:330px;flex-shrink:0;background:var(--panel);border-left:1px solid var(--line);display:flex;flex-direction:column;min-height:0}
.cx-side-h{padding:14px 14px 8px;display:flex;align-items:center;gap:8px}
.cx-side-h h3{margin:0;font-size:16px;font-weight:900;flex:1;display:flex;align-items:center;gap:8px}
.cx-ib{width:36px;height:36px;border-radius:12px;border:0;background:var(--soft);color:var(--muted);display:inline-flex;align-items:center;justify-content:center;transition:.2s}
.cx-ib:hover{background:var(--hover);color:var(--acc);transform:translateY(-1px)}
.cx-ib.cx-prim{background:linear-gradient(135deg,var(--me1),var(--me2));color:#fff;box-shadow:0 8px 18px -8px var(--me1)}
.cx-search{margin:0 14px 8px;position:relative}
.cx-search input{width:100%;border:1px solid var(--line);background:var(--soft);color:var(--text);border-radius:12px;padding:9px 34px 9px 10px;font-size:12px;outline:none;font-family:inherit;transition:.2s}
.cx-search input:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.cx-search i{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:12px}
.cx-chips{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 10px}
.cx-chip{border:1px solid var(--line);background:var(--panel);color:var(--muted);border-radius:999px;padding:5px 11px;font-size:11px;font-weight:700;white-space:nowrap;transition:.2s}
.cx-chip.on{background:linear-gradient(135deg,var(--me1),var(--me2));color:#fff;border-color:transparent;box-shadow:0 6px 14px -8px var(--me1)}
.cx-threads{flex:1;overflow-y:auto;padding:4px 8px 10px}
.cx-th{display:flex;gap:10px;align-items:center;padding:10px;border-radius:14px;cursor:pointer;transition:background .2s,transform .2s;position:relative;animation:cxFade .35s both}
.cx-th:hover{background:var(--hover)}
.cx-th.on{background:linear-gradient(135deg,rgba(79,70,229,.12),rgba(124,58,237,.12))}
.cx-th.on::before{content:'';position:absolute;right:0;top:12px;bottom:12px;width:3px;border-radius:3px;background:linear-gradient(var(--me1),var(--me2))}
.cx-av{width:44px;height:44px;border-radius:15px;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:15px;position:relative}
.cx-av small{position:absolute;bottom:-3px;left:-3px;width:19px;height:19px;border-radius:7px;background:var(--panel);display:flex;align-items:center;justify-content:center;font-size:9px;box-shadow:0 2px 6px rgba(0,0,0,.15)}
.cx-th-b{flex:1;min-width:0}
.cx-th-t{display:flex;justify-content:space-between;gap:6px;align-items:center}
.cx-th-t b{font-size:13px;font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-th-t span{font-size:10px;color:var(--muted);white-space:nowrap}
.cx-th-l{display:flex;justify-content:space-between;gap:6px;align-items:center;margin-top:3px}
.cx-th-l p{margin:0;font-size:11.5px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-badge{min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:linear-gradient(135deg,#f43f5e,#e11d48);color:#fff;font-size:10.5px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;animation:cxPop .4s both}
.cx-main{flex:1;min-width:0;display:flex;flex-direction:column;position:relative;background:var(--bg)}
.cx-main::before{content:'';position:absolute;inset:0;opacity:.5;pointer-events:none;background-image:radial-gradient(circle at 20% 20%,rgba(99,102,241,.08),transparent 40%),radial-gradient(circle at 80% 70%,rgba(168,85,247,.08),transparent 40%)}
.cx-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:var(--muted);text-align:center;padding:20px;position:relative}
.cx-empty .cx-big{width:90px;height:90px;border-radius:30px;display:flex;align-items:center;justify-content:center;font-size:38px;color:#fff;background:linear-gradient(135deg,var(--me1),var(--me2));box-shadow:var(--shadow);animation:cxFloat 3.5s ease-in-out infinite}
.cx-head{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--line);position:relative;z-index:2}
.cx-head .cx-av{width:40px;height:40px;border-radius:13px;font-size:14px}
.cx-head-b{flex:1;min-width:0}
.cx-head-b b{display:block;font-size:14px;font-weight:900;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-head-b span{display:block;font-size:10.5px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-head-b .cx-typing{color:var(--acc);font-weight:700}
.cx-chan{font-size:10px;color:var(--muted);background:var(--soft);border-bottom:1px solid var(--line);padding:5px 14px;display:flex;align-items:center;gap:6px;position:relative;z-index:1}
.cx-filterbar{display:flex;align-items:center;gap:8px;padding:6px 14px;background:rgba(99,102,241,.1);color:var(--acc);font-size:11px;font-weight:700;position:relative;z-index:1;animation:cxSlide .3s both}
.cx-filterbar button{margin-right:auto;border:0;background:transparent;color:var(--acc);font-weight:900}
.cx-body{flex:1;overflow-y:auto;padding:14px 16px 8px;position:relative;scroll-behavior:smooth}
.cx-day{text-align:center;margin:12px 0 8px;position:sticky;top:0;z-index:1}
.cx-day span{display:inline-block;background:var(--panel);color:var(--muted);font-size:10.5px;font-weight:800;padding:4px 12px;border-radius:999px;box-shadow:0 2px 10px rgba(15,23,42,.08)}
.cx-row{display:flex;margin:2px 0;position:relative}
.cx-row.me{justify-content:flex-start}
.cx-row.them{justify-content:flex-end}
.cx-row.gap{margin-top:10px}
.cx-msg{max-width:min(74%,560px);padding:8px 12px 6px;border-radius:18px;position:relative;line-height:1.85;word-wrap:break-word;overflow-wrap:anywhere;box-shadow:0 3px 12px -6px rgba(15,23,42,.25);transition:transform .15s,box-shadow .2s}
.cx-row.me .cx-msg{background:linear-gradient(135deg,var(--me1),var(--me2));color:#fff;border-bottom-right-radius:6px}
.cx-row.them .cx-msg{background:var(--them);color:var(--themtext);border-bottom-left-radius:6px}
.cx-msg:hover{box-shadow:0 8px 22px -10px rgba(15,23,42,.35)}
.cx-msg.cx-in{animation:cxIn .38s cubic-bezier(.2,.9,.3,1.25) both}
.cx-msg.cx-flash{animation:cxFlash 1.2s both}
.cx-msg.cx-del{background:transparent!important;color:var(--muted)!important;border:1px dashed var(--line);box-shadow:none;font-style:italic;font-size:11.5px}
.cx-msg a{color:inherit;text-decoration:underline}
.cx-sender{font-size:10.5px;font-weight:900;margin-bottom:1px;opacity:.85}
.cx-row.them .cx-sender{color:hsl(var(--h),65%,45%)}
.cx-dark .cx-row.them .cx-sender{color:hsl(var(--h),70%,70%)}
.cx-meta{display:flex;align-items:center;gap:5px;justify-content:flex-end;font-size:9.5px;opacity:.75;margin-top:1px;direction:ltr}
.cx-meta i{font-size:10px}
.cx-meta .cx-seen{color:#a5f3fc}
.cx-row.them .cx-meta .cx-seen{color:var(--acc)}
.cx-reply{display:block;border-right:3px solid rgba(255,255,255,.7);background:rgba(255,255,255,.15);padding:4px 8px;border-radius:8px;margin:2px 0 5px;font-size:11px;cursor:pointer}
.cx-row.them .cx-reply{border-right-color:var(--acc);background:rgba(99,102,241,.08)}
.cx-reply b{display:block;font-size:10.5px}
.cx-reply span{display:block;opacity:.85;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px}
.cx-ref{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:800;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.2);margin-bottom:4px;cursor:pointer;max-width:100%}
.cx-row.them .cx-ref{background:rgba(99,102,241,.1);color:var(--acc)}
.cx-ref span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-img{display:block;max-width:260px;max-height:260px;min-width:90px;min-height:90px;background:rgba(127,127,127,.12);border-radius:12px;margin:3px 0;cursor:zoom-in;object-fit:cover;transition:transform .2s}
.cx-img:hover{transform:scale(1.02)}
.cx-file{display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:12px;background:rgba(255,255,255,.15);margin:3px 0;text-decoration:none!important;color:inherit}
.cx-row.them .cx-file{background:var(--soft)}
.cx-file i{font-size:22px}
.cx-file b{display:block;font-size:11.5px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-file small{font-size:9.5px;opacity:.8}
.cx-audio{display:block;max-width:260px;margin:4px 0}
.cx-dots{position:absolute;top:4px;left:4px;width:24px;height:24px;border-radius:8px;border:0;background:rgba(0,0,0,.12);color:inherit;opacity:0;transition:.2s;font-size:11px}
.cx-row.them .cx-dots{left:auto;right:4px;background:var(--soft)}
.cx-msg:hover .cx-dots{opacity:1}
.cx-fab{position:absolute;left:18px;bottom:96px;width:42px;height:42px;border-radius:14px;border:0;background:var(--panel);color:var(--acc);box-shadow:var(--shadow);display:none;align-items:center;justify-content:center;z-index:3;animation:cxPop .3s both}
.cx-fab.show{display:flex}
.cx-fab .cx-badge{position:absolute;top:-8px;right:-8px}
.cx-typing-bubble{display:none;align-items:center;gap:4px;padding:10px 14px;border-radius:18px;background:var(--them);width:fit-content;margin:6px 0 0 auto;box-shadow:0 3px 12px -6px rgba(15,23,42,.25)}
.cx-typing-bubble.show{display:flex;animation:cxIn .3s both}
.cx-typing-bubble i{width:7px;height:7px;border-radius:50%;background:var(--muted);animation:cxDot 1.2s infinite}
.cx-typing-bubble i:nth-child(2){animation-delay:.15s}.cx-typing-bubble i:nth-child(3){animation-delay:.3s}
.cx-compose{background:var(--panel);border-top:1px solid var(--line);padding:8px 12px 10px;position:relative;z-index:2}
.cx-bar{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:12px;background:var(--soft);margin-bottom:6px;font-size:11px;animation:cxSlide .25s both;border-right:3px solid var(--acc)}
.cx-bar div{flex:1;min-width:0}
.cx-bar b{display:block;color:var(--acc);font-size:10.5px}
.cx-bar span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)}
.cx-bar button{border:0;background:transparent;color:var(--muted);font-size:14px}
.cx-topic{display:inline-flex;align-items:center;gap:6px;border:1px dashed var(--line);background:transparent;color:var(--muted);border-radius:999px;padding:3px 10px;font-size:10.5px;font-weight:800;margin-bottom:6px;transition:.2s;max-width:100%}
.cx-topic.set{border-style:solid;border-color:var(--acc);color:var(--acc);background:rgba(99,102,241,.08)}
.cx-topic span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-in-row{display:flex;align-items:flex-end;gap:6px}
.cx-in-row textarea{flex:1;resize:none;border:1px solid var(--line);background:var(--soft);color:var(--text);border-radius:16px;padding:10px 12px;font-family:inherit;font-size:13px;line-height:1.7;max-height:140px;min-height:44px;outline:none;transition:.2s}
.cx-in-row textarea:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(99,102,241,.15);background:var(--panel)}
.cx-send{width:46px;height:46px;border-radius:16px;border:0;background:linear-gradient(135deg,var(--me1),var(--me2));color:#fff;font-size:16px;box-shadow:0 10px 20px -10px var(--me1);transition:transform .15s}
.cx-send:hover{transform:scale(1.06) rotate(-8deg)}
.cx-send:active{transform:scale(.92)}
.cx-send:disabled{opacity:.5}
.cx-att{display:flex;align-items:center;gap:8px;background:var(--soft);border-radius:12px;padding:6px 10px;margin-bottom:6px;font-size:11px;animation:cxSlide .25s both}
.cx-att img{width:38px;height:38px;border-radius:8px;object-fit:cover}
.cx-att div{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cx-att button{border:0;background:transparent;color:#ef4444}
.cx-emoji{position:absolute;bottom:64px;right:12px;background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:8px;display:grid;grid-template-columns:repeat(8,1fr);gap:2px;z-index:20;animation:cxPop .2s both}
.cx-emoji button{border:0;background:transparent;font-size:20px;width:34px;height:34px;border-radius:9px}
.cx-emoji button:hover{background:var(--hover);transform:scale(1.15)}
.cx-refs{position:absolute;top:0;bottom:0;left:0;width:min(330px,92%);background:var(--panel);border-right:1px solid var(--line);box-shadow:var(--shadow);z-index:15;display:flex;flex-direction:column;transform:translateX(-105%);transition:transform .35s cubic-bezier(.2,.9,.3,1)}
.cx-refs.open{transform:none}
.cx-refs-h{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid var(--line)}
.cx-refs-h b{flex:1;font-size:13px}
.cx-refs-l{flex:1;overflow-y:auto;padding:8px}
.cx-ref-i{border:1px solid var(--line);border-radius:14px;padding:9px 10px;margin-bottom:6px;transition:.2s;animation:cxFade .3s both}
.cx-ref-i:hover{border-color:var(--acc);background:var(--hover)}
.cx-ref-i.on{border-color:var(--acc);background:rgba(99,102,241,.1)}
.cx-ref-i b{display:flex;align-items:center;gap:6px;font-size:12px}
.cx-ref-i small{display:block;color:var(--muted);font-size:10.5px;margin-top:2px}
.cx-ref-i .cx-ref-a{display:flex;gap:6px;margin-top:7px}
.cx-ref-i .cx-ref-a button{flex:1;border:1px solid var(--line);background:var(--panel);color:var(--text);border-radius:9px;font-size:10.5px;font-weight:800;padding:5px}
.cx-ref-i .cx-ref-a button:hover{border-color:var(--acc);color:var(--acc)}
.cx-ref-i .cx-cnt{margin-right:auto;font-size:10px;color:var(--muted);font-weight:700}
.cx-menu{position:fixed;z-index:2147483600;min-width:210px;background:rgba(255,255,255,.96);backdrop-filter:blur(12px);border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 25px 60px -15px rgba(15,23,42,.45);padding:6px;direction:rtl;font-size:12.5px;animation:cxMenu .18s cubic-bezier(.2,.9,.3,1.2) both;color:#0f172a;font-family:inherit}
.cx-menu.dark{background:rgba(17,26,46,.97);border-color:#1e293b;color:#e2e8f0}
.cx-menu button{display:flex;align-items:center;gap:10px;width:100%;border:0;background:transparent;color:inherit;padding:8px 10px;border-radius:10px;text-align:right;font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
.cx-menu button:hover{background:rgba(99,102,241,.12);color:#4f46e5}
.cx-menu.dark button:hover{color:#a5b4fc}
.cx-menu button i{width:18px;text-align:center;opacity:.8}
.cx-menu button.danger{color:#e11d48}
.cx-menu button.danger:hover{background:rgba(225,29,72,.1);color:#e11d48}
.cx-menu hr{border:0;border-top:1px solid rgba(148,163,184,.25);margin:4px 6px}
.cx-menu .cx-mh{font-size:10.5px;font-weight:900;color:#94a3b8;padding:6px 10px 2px}
.cx-menu .cx-react{display:flex;justify-content:space-between;padding:2px 4px 6px}
.cx-menu .cx-react button{width:34px;justify-content:center;padding:6px;font-size:18px}
.cx-lb{position:fixed;inset:0;z-index:2147483601;background:rgba(2,6,23,.9);display:flex;align-items:center;justify-content:center;animation:cxFade .2s both;cursor:zoom-out}
.cx-lb img{max-width:92vw;max-height:88vh;border-radius:14px;box-shadow:0 30px 80px rgba(0,0,0,.6);animation:cxPop .3s both}
.cx-modal{position:absolute;inset:0;z-index:30;background:rgba(15,23,42,.45);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:40px 14px;animation:cxFade .2s both}
.cx-modal>div{width:min(460px,100%);background:var(--panel);border-radius:20px;box-shadow:var(--shadow);overflow:hidden;animation:cxPop .3s both;display:flex;flex-direction:column;max-height:100%}
.cx-modal .cx-mhd{display:flex;align-items:center;gap:8px;padding:14px;border-bottom:1px solid var(--line)}
.cx-modal .cx-mhd b{flex:1;font-size:14px}
.cx-modal .cx-mbd{padding:10px;overflow-y:auto}
.cx-drop{position:absolute;inset:8px;z-index:25;border:3px dashed var(--acc);border-radius:20px;background:rgba(99,102,241,.1);display:none;align-items:center;justify-content:center;color:var(--acc);font-weight:900;font-size:15px;pointer-events:none}
.cx-drop.show{display:flex;animation:cxFade .2s both}
.cx-skel{height:52px;border-radius:14px;margin:6px;background:linear-gradient(90deg,var(--soft),var(--hover),var(--soft));background-size:200% 100%;animation:cxShim 1.2s infinite}
.cx-back{display:none}
.cx-av img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block}
.cx-av em{font-style:normal;font-size:1.35em;line-height:1;filter:drop-shadow(0 2px 3px rgba(0,0,0,.18))}
.cx-av .cx-on{position:absolute;bottom:-2px;right:-2px;width:13px;height:13px;border-radius:50%;background:#22c55e;border:2.5px solid var(--panel,#fff);animation:cxPulse 2.2s infinite}
.cx-pr{font-weight:800}
.cx-pr.on{color:#16a34a}
.cx-dark .cx-pr.on{color:#4ade80}
.cx-row.them{align-items:flex-end;gap:6px}
.cx-mav{width:30px;flex-shrink:0;align-self:flex-end}
.cx-mav .cx-av{width:30px;height:30px;border-radius:10px;font-size:11px;animation:cxPop .3s both}
.cx-head-tools{display:flex;align-items:center;gap:6px}
.cx-fq{width:0;opacity:0;border:1px solid var(--line);background:var(--soft);color:var(--text);border-radius:12px;padding:0;height:36px;font-family:inherit;font-size:12px;outline:none;transition:width .35s cubic-bezier(.2,.9,.3,1),opacity .25s,padding .35s}
.cx-head.cx-finding .cx-fq{width:min(230px,38vw);opacity:1;padding:0 12px}
.cx-fq:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.cx-head.cx-finding .cx-find{background:linear-gradient(135deg,var(--me1),var(--me2));color:#fff}
.cx-fdates{display:grid;grid-template-rows:0fr;transition:grid-template-rows .35s cubic-bezier(.2,.9,.3,1);background:var(--panel);border-bottom:1px solid transparent;position:relative;z-index:1}
.cx-fdates>div{overflow:hidden;display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:0 14px;font-size:11px;color:var(--muted)}
.cx-fdates.open{grid-template-rows:1fr;border-bottom-color:var(--line)}
.cx-fdates.open>div{padding:8px 14px}
.cx-fdates input{width:104px;border:1px solid var(--line);background:var(--soft);color:var(--text);border-radius:10px;padding:5px 8px;font-family:inherit;font-size:11.5px;text-align:center;outline:none;direction:ltr}
.cx-fdates input:focus{border-color:var(--acc)}
.cx-fdates .cx-chip{padding:4px 9px;font-size:10.5px}
.cx-fdates .cx-fn{margin-right:auto;font-weight:900;color:var(--acc)}
mark.cx-mark{background:#fde047;color:#0f172a;border-radius:4px;padding:0 2px}
.cx-dlg-ov{position:fixed;inset:0;z-index:2147483602;background:rgba(15,23,42,.5);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:18px;opacity:0;transition:opacity .2s;direction:rtl;font-family:inherit}
.cx-dlg-ov.on{opacity:1}
.cx-dlg{width:min(380px,100%);background:#fff;color:#0f172a;border-radius:24px;padding:22px 20px 16px;text-align:center;box-shadow:0 30px 70px -20px rgba(15,23,42,.55);transform:scale(.88) translateY(14px);transition:transform .28s cubic-bezier(.2,.9,.3,1.3)}
.cx-dlg-ov.on .cx-dlg{transform:none}
.cx-dlg-ov.dark .cx-dlg{background:linear-gradient(160deg,#1e1b4b,#0f172a);color:#e2e8f0;border:1px solid rgba(255,255,255,.12)}
.cx-dlg-ic{width:56px;height:56px;border-radius:18px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 12px 24px -12px #6366f1;animation:cxPop .4s both}
.cx-dlg-ic.danger{background:linear-gradient(135deg,#f43f5e,#e11d48);box-shadow:0 12px 24px -12px #e11d48}
.cx-dlg-t{display:block;font-size:15px;font-weight:900;margin-bottom:6px}
.cx-dlg-m{font-size:12.5px;line-height:1.9;margin:0 0 14px;opacity:.75;white-space:pre-line}
.cx-dlg-in{width:100%;border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px;font-family:inherit;font-size:13px;margin-bottom:14px;outline:none;background:#f8fafc;color:inherit}
.cx-dlg-ov.dark .cx-dlg-in{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.14)}
.cx-dlg-b{display:flex;gap:8px}
.cx-dlg-b button{flex:1;border:0;border-radius:14px;padding:11px 8px;font-family:inherit;font-size:13px;font-weight:800;cursor:pointer;transition:transform .15s}
.cx-dlg-b button:active{transform:scale(.96)}
.cx-dlg-ok{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;box-shadow:0 10px 20px -12px #6366f1}
.cx-dlg-ok.danger{background:linear-gradient(135deg,#f43f5e,#e11d48);box-shadow:0 10px 20px -12px #e11d48}
.cx-dlg-no{background:#f1f5f9;color:#475569}
.cx-dlg-ov.dark .cx-dlg-no{background:rgba(255,255,255,.1);color:#e2e8f0}
.cx-pick{width:min(420px,100%)}
.cx-pick-pv{display:flex;justify-content:center;margin:6px 0 14px}
.cx-pick-pv .cx-av{border-radius:30px;box-shadow:0 16px 30px -14px rgba(15,23,42,.6);animation:cxPop .35s both}
.cx-pick-g{display:grid;grid-template-columns:repeat(8,1fr);gap:7px;margin-bottom:12px}
.cx-pick-g button{aspect-ratio:1;border:0;border-radius:13px;font-size:19px;cursor:pointer;transition:transform .15s,box-shadow .2s;box-shadow:inset 0 0 0 0 transparent}
.cx-pick-g button:hover{transform:scale(1.1)}
.cx-pick-g button.on{box-shadow:0 0 0 3px #fff,0 0 0 5px #6366f1;transform:scale(1.08)}
.cx-pick-a{display:flex;gap:8px;margin-bottom:14px}
.cx-pick-a>*{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;border:1.5px dashed #c7d2fe;border-radius:14px;padding:9px 6px;font-size:11.5px;font-weight:800;color:#4f46e5;background:rgba(99,102,241,.06);cursor:pointer;font-family:inherit}
.cx-dlg-ov.dark .cx-pick-a>*{color:#a5b4fc;border-color:rgba(165,180,252,.35)}
.cx-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:2147483603;background:#0f172a;color:#fff;padding:10px 18px;border-radius:14px;font-size:12.5px;font-weight:700;box-shadow:0 14px 30px -12px rgba(0,0,0,.5);animation:cxSlide .25s both;direction:rtl;font-family:inherit;transition:opacity .3s}
.cx-toast.error{background:#e11d48}.cx-toast.success{background:#059669}.cx-toast.out{opacity:0}
@keyframes cxPulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.55)}70%{box-shadow:0 0 0 7px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
@keyframes cxIn{from{opacity:0;transform:translateY(12px) scale(.94)}to{opacity:1;transform:none}}
@keyframes cxFade{from{opacity:0}to{opacity:1}}
@keyframes cxPop{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:none}}
@keyframes cxMenu{from{opacity:0;transform:scale(.9) translateY(-6px)}to{opacity:1;transform:none}}
@keyframes cxSlide{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes cxDot{0%,60%,100%{transform:translateY(0);opacity:.5}30%{transform:translateY(-5px);opacity:1}}
@keyframes cxFloat{0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-10px) rotate(3deg)}}
@keyframes cxFlash{0%,100%{box-shadow:0 3px 12px -6px rgba(15,23,42,.25)}30%{box-shadow:0 0 0 4px rgba(250,204,21,.8)}}
@keyframes cxShim{from{background-position:200% 0}to{background-position:-200% 0}}
@media (max-width:820px){
  .cx-side{width:100%;border-left:0}
  .cx.cx-full.cx-conv-open .cx-side{display:none}
  .cx.cx-full:not(.cx-conv-open) .cx-main{display:none}
  .cx-back{display:inline-flex}
  .cx-msg{max-width:86%}
  .cx-head.cx-finding .cx-head-b{display:none}
  .cx-head.cx-finding .cx-fq{width:100%;flex:1}
  .cx-head.cx-finding .cx-head-tools{flex:1}
  .cx-pick-g{grid-template-columns:repeat(4,1fr)}
}`;
        document.head.appendChild(st);
    }

    // ------------------------------------------------------------------ کامپوننت
    class Chat {
        constructor(el, o) {
            injectCss();
            this.el = el; this.o = Object.assign({ mode: 'full', theme: 'light', pollMs: 3500, listMs: 9000 }, o || {});
            this.key = this.o.mode === 'single' ? (this.o.key || 'SELF') : null;
            this.threads = []; this.filter = 'all'; this.q = '';
            this.msgs = []; this.sig = ''; this.refs = []; this.head = null;
            this.topic = null; this.topicFilter = null; this.replyTo = null; this.editing = null; this.file = null;
            this.seen = new Set(); this.newBelow = 0; this.caps = {};
            this.render();
            if (this.o.mode === 'full') { this.loadThreads(); this.listTimer = setInterval(() => !document.hidden && this.el.offsetParent && this.loadThreads(true), this.o.listMs); if (this.o.openKey) this.open(this.o.openKey); }
            else this.open(this.key);
            // فقط وقتی گفتگو روی صفحه دیده می‌شود (تب/صفحه‌ی پنهان = offsetParent تهی)
            this.pollTimer = setInterval(() => { if (this.key && !document.hidden && this.el.offsetParent) this.poll(); }, this.o.pollMs);
            document.addEventListener('click', this._docClick = e => { if (!e.target.closest('.cx-emoji,.cx-emo-btn')) this.$('.cx-emoji') && this.$('.cx-emoji').remove(); });
        }
        destroy() { clearInterval(this.pollTimer); clearInterval(this.listTimer); document.removeEventListener('click', this._docClick); this.el.innerHTML = ''; }
        $(s) { return this.el.querySelector(s); }
        toast(m, t) { if (window.showToast) showToast(m, t || 'info'); else if (this.o.toast) this.o.toast(m); else miniToast(m, t); }
        async call(action, data) {
            try { return await this.o.call(action, Object.assign({ key: this.key }, data || {})); }
            catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
        }

        render() {
            const full = this.o.mode === 'full';
            this.el.innerHTML = `<div class="cx ${this.o.theme === 'dark' ? 'cx-dark' : ''} ${full ? 'cx-full' : 'cx-single'}">
                ${full ? `<aside class="cx-side">
                    <div class="cx-side-h"><h3><i class="fas fa-comments" style="color:var(--acc)"></i> گفتگوها</h3>
                        <button class="cx-ib cx-prim cx-new" title="گفتگوی تازه"><i class="fas fa-pen-to-square"></i></button></div>
                    <label class="cx-search"><i class="fas fa-magnifying-glass"></i><input class="cx-q" placeholder="جستجوی نام، کد ملی یا متن پیام..."></label>
                    <div class="cx-chips"></div>
                    <div class="cx-threads"><div class="cx-skel"></div><div class="cx-skel"></div><div class="cx-skel"></div></div>
                </aside>` : ''}
                <section class="cx-main"><div class="cx-empty"><div class="cx-big"><i class="fas fa-comments"></i></div>
                    <b style="font-size:15px;color:var(--text)">یک گفتگو را انتخاب کنید</b><span>یا با «گفتگوی تازه» با هر کسی شروع کنید.</span></div></section>
            </div>`;
            this.root = this.$('.cx');
            if (full) {
                this.$('.cx-new').onclick = () => this.newChat();
                let t; this.$('.cx-q').oninput = e => { clearTimeout(t); t = setTimeout(() => { this.q = e.target.value.trim(); this.loadThreads(); }, 300); };
            }
        }

        // ---------------- فهرستِ گفتگوها
        async loadThreads(silent) {
            const d = await this.call('chat_threads', { q: this.q });
            if (!d.ok) { if (!silent) this.$('.cx-threads').innerHTML = `<p style="padding:20px;text-align:center;color:var(--muted)">${esc(d.error)}</p>`; return; }
            this.threads = d.threads; this.caps = d.caps || {};
            this.renderChips(); this.renderThreads();
            const n = this.threads.reduce((a, t) => a + (t.unread || 0), 0);
            if (this.o.onUnread) this.o.onUnread(n);
        }
        renderChips() {
            const cnt = t => this.threads.filter(x => t === 'unread' ? x.unread : x.type === t).length;
            const chips = [['all', 'همه', this.threads.length], ['unread', 'نخوانده', cnt('unread')]];
            if (this.caps.person) chips.push(['PERSON', 'کارکنان', cnt('PERSON')]);
            if (this.caps.company) chips.push(['COMPANY', 'شرکت‌ها', cnt('COMPANY')]);
            chips.push(['STAFF', 'همکاران', cnt('STAFF')]);
            const box = this.$('.cx-chips');
            box.innerHTML = chips.map(([k, l, n]) => `<button class="cx-chip ${this.filter === k ? 'on' : ''}" data-f="${k}">${l}${n ? ' · ' + fa(n) : ''}</button>`).join('');
            box.querySelectorAll('[data-f]').forEach(b => b.onclick = () => { this.filter = b.dataset.f; this.renderChips(); this.renderThreads(); });
        }
        avatar(title, type, av, presence) {
            return avatarHtml(av, title, { type: type || 'STAFF', online: presence && presence.online });
        }
        renderThreads() {
            const list = this.threads.filter(t => this.filter === 'all' || (this.filter === 'unread' ? t.unread : t.type === this.filter));
            const box = this.$('.cx-threads');
            box.innerHTML = list.length ? list.map((t, i) => `<div class="cx-th ${t.key === this.key ? 'on' : ''}" data-k="${t.key}" style="animation-delay:${Math.min(i, 12) * 25}ms">
                ${this.avatar(t.title, t.type, t.avatar, t.presence)}
                <div class="cx-th-b"><div class="cx-th-t"><b>${esc(t.title)}</b><span>${t.last_date === todayJ ? fa(t.last_time) : dayLabel(t.last_date)}</span></div>
                <div class="cx-th-l"><p>${t.last_mine ? '<i class="fas fa-reply" style="font-size:9px;opacity:.6"></i> ' : ''}${esc(t.last)}</p>${t.unread ? `<span class="cx-badge">${fa(t.unread)}</span>` : ''}</div></div></div>`).join('')
                : `<div style="padding:30px 10px;text-align:center;color:var(--muted)"><i class="fas fa-inbox" style="font-size:28px;opacity:.4"></i><p>گفتگویی نیست.</p></div>`;
            box.querySelectorAll('[data-k]').forEach(el => el.onclick = () => this.open(el.dataset.k));
        }

        async newChat() {
            const m = document.createElement('div');
            m.className = 'cx-modal';
            m.innerHTML = `<div><div class="cx-mhd"><b><i class="fas fa-user-plus" style="color:var(--acc)"></i> گفتگوی تازه</b><button class="cx-ib cx-x"><i class="fas fa-xmark"></i></button></div>
                <div style="padding:10px 10px 0"><label class="cx-search" style="margin:0"><i class="fas fa-magnifying-glass"></i><input class="cx-cq" placeholder="نام، کد ملی، شماره یا نام شرکت..."></label></div>
                <div class="cx-mbd"><p style="text-align:center;color:var(--muted);padding:14px">برای شروع جستجو کنید.</p></div></div>`;
            this.root.appendChild(m);
            const close = () => m.remove();
            m.querySelector('.cx-x').onclick = close;
            m.onclick = e => { if (e.target === m) close(); };
            const inp = m.querySelector('.cx-cq');
            const run = async () => {
                const d = await this.call('chat_contacts', { q: inp.value.trim() });
                const bd = m.querySelector('.cx-mbd');
                if (!d.ok) { bd.innerHTML = `<p style="color:#e11d48;text-align:center">${esc(d.error)}</p>`; return; }
                bd.innerHTML = d.contacts.length ? d.contacts.map(c => `<div class="cx-th" data-k="${c.key}">${this.avatar(c.title, c.type, c.avatar, c.presence)}<div class="cx-th-b"><div class="cx-th-t"><b>${esc(c.title)}</b><span>${(TYPE_META[c.type] || [])[0] || ''}</span></div><div class="cx-th-l"><p>${c.presence && c.presence.online ? '<span class="cx-pr on">آنلاین</span> · ' : ''}${esc(fa(c.sub || ''))}</p></div></div></div>`).join('')
                    : '<p style="text-align:center;color:var(--muted);padding:14px">کسی پیدا نشد.</p>';
                bd.querySelectorAll('[data-k]').forEach(el => el.onclick = () => { close(); this.open(el.dataset.k); });
            };
            let t; inp.oninput = () => { clearTimeout(t); t = setTimeout(run, 250); };
            run(); setTimeout(() => inp.focus(), 50);
        }

        // ---------------- یک گفتگو
        async open(key) {
            this.key = key; this.msgs = []; this.sig = ''; this.seen = new Set(); this.topic = null; this.topicFilter = null; this.replyTo = null; this.editing = null; this.file = null; this.newBelow = 0;
            this.search = null; this.presence = null;
            this.root.classList.add('cx-conv-open');
            if (this.o.mode === 'full') this.renderThreads();
            const main = this.$('.cx-main');
            main.innerHTML = `<div class="cx-head"><button class="cx-ib cx-back" title="بازگشت"><i class="fas fa-arrow-right"></i></button><span class="cx-skel" style="width:40px;height:40px;margin:0"></span><div class="cx-head-b"><b>...</b></div></div><div class="cx-body"><div class="cx-skel" style="width:60%"></div><div class="cx-skel" style="width:45%;margin-right:auto"></div><div class="cx-skel" style="width:55%"></div></div>`;
            const b = main.querySelector('.cx-back'); if (b) b.onclick = () => this.back();
            const d = await this.call('chat_open', { key });
            if (this.key !== key) return;
            if (!d.ok) { main.innerHTML = `<div class="cx-empty"><i class="fas fa-triangle-exclamation" style="font-size:30px;color:#f59e0b"></i><b>${esc(d.error)}</b></div>`; return; }
            this.head = d.head; this.refs = d.refs || [];
            this.presence = d.head.presence || null; this.presenceAt = Date.now();
            this.renderConv();
            this.setMessages(d.messages, true);
            this.setTyping(d.typing);
            if (this.o.mode === 'full') { const t = this.threads.find(x => x.key === key); if (t && t.unread) { t.unread = 0; this.renderThreads(); this.renderChips(); if (this.o.onUnread) this.o.onUnread(this.threads.reduce((a, x) => a + (x.unread || 0), 0)); } }
        }
        back() { this.key = null; this.root.classList.remove('cx-conv-open'); this.renderThreads(); }

        renderConv() {
            const h = this.head, full = this.o.mode === 'full';
            const main = this.$('.cx-main');
            main.innerHTML = `
                <div class="cx-head">${full ? '<button class="cx-ib cx-back" title="بازگشت"><i class="fas fa-arrow-right"></i></button>' : ''}
                    ${this.avatar(h.title, h.type, h.avatar, this.presence)}
                    <div class="cx-head-b"><b>${esc(h.title)}</b><span class="cx-hsub"></span></div>
                    <div class="cx-head-tools"><input class="cx-fq" placeholder="جستجو در پیام‌ها...">
                    <button class="cx-ib cx-find" title="جستجو در گفتگو (متن و تاریخ)"><i class="fas fa-magnifying-glass"></i></button>
                    ${this.refs.length ? `<button class="cx-ib cx-refs-btn" title="درخواست‌ها"><i class="fas fa-folder-tree"></i></button>` : ''}</div></div>
                <div class="cx-fdates"><div><i class="fas fa-calendar-days"></i> از <input class="cx-f-from" placeholder="۱۴۰۵/۰۷/۰۱" inputmode="numeric"> تا <input class="cx-f-to" placeholder="۱۴۰۵/۰۷/۳۰" inputmode="numeric">
                    <button class="cx-chip" data-r="0">امروز</button><button class="cx-chip" data-r="7">۷ روزِ اخیر</button><button class="cx-chip" data-r="30">۳۰ روزِ اخیر</button><button class="cx-chip" data-r="">همه</button>
                    <span class="cx-fn"></span></div></div>
                ${h.channel ? `<div class="cx-chan"><i class="fas fa-circle-info"></i> ${esc(h.channel)}</div>` : ''}
                <div class="cx-filterslot"></div>
                <div class="cx-body"></div>
                <button class="cx-fab" title="پیام‌های تازه"><i class="fas fa-chevron-down"></i></button>
                <div class="cx-compose">
                    <div class="cx-barslot"></div>
                    ${this.refs.length ? `<button class="cx-topic"><i class="fas fa-tag"></i><span></span><i class="fas fa-chevron-down" style="font-size:9px"></i></button>` : ''}
                    <div class="cx-in-row">
                        <button class="cx-ib cx-emo-btn" title="ایموجی"><i class="far fa-face-smile"></i></button>
                        <label class="cx-ib" title="پیوستِ فایل" style="cursor:pointer"><i class="fas fa-paperclip"></i><input type="file" class="cx-file-in" hidden></label>
                        <textarea rows="1" class="cx-ta" placeholder="${window.innerWidth < 640 ? 'پیام بنویسید...' : 'پیام بنویسید... (Enter ارسال، Shift+Enter خطِ تازه)'}"></textarea>
                        <button class="cx-send" title="ارسال"><i class="fas fa-paper-plane"></i></button></div></div>
                <div class="cx-drop"><i class="fas fa-cloud-arrow-up" style="margin-left:8px"></i> فایل را اینجا رها کنید</div>
                ${this.refs.length ? this.refsDrawerHtml() : ''}`;
            const back = main.querySelector('.cx-back'); if (back) back.onclick = () => this.back();
            const body = this.$('.cx-body');
            body.onscroll = () => { this.stick = this.nearBottom(); if (this.stick) { this.newBelow = 0; this.fab(); } else this.fab(true); };
            this.$('.cx-fab').onclick = () => { this.scrollBottom(true); };
            const ta = this.$('.cx-ta');
            ta.oninput = () => { ta.style.height = 'auto'; ta.style.height = Math.min(140, ta.scrollHeight) + 'px'; this.typingPing(); };
            ta.onkeydown = e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); this.send(); } if (e.key === 'Escape') { this.replyTo = null; this.editing = null; this.renderBar(); } };
            ta.onpaste = e => { const f = [...(e.clipboardData || {}).files || []][0]; if (f) { e.preventDefault(); this.attach(f); } };
            this.$('.cx-send').onclick = () => this.send();
            this.$('.cx-file-in').onchange = e => { if (e.target.files[0]) this.attach(e.target.files[0]); e.target.value = ''; };
            this.$('.cx-emo-btn').onclick = e => { e.stopPropagation(); this.emoji(); };
            this.bindFind();
            const tb = this.$('.cx-topic'); if (tb) tb.onclick = () => this.openRefs('pick');
            const rb = this.$('.cx-refs-btn'); if (rb) rb.onclick = () => this.openRefs();
            // کشیدن و رها کردنِ فایل
            let dc = 0; const drop = this.$('.cx-drop');
            main.ondragenter = e => { if ([...(e.dataTransfer || {}).types || []].includes('Files')) { dc++; drop.classList.add('show'); } };
            main.ondragleave = () => { if (--dc <= 0) { dc = 0; drop.classList.remove('show'); } };
            main.ondragover = e => e.preventDefault();
            main.ondrop = e => { e.preventDefault(); dc = 0; drop.classList.remove('show'); const f = (e.dataTransfer.files || [])[0]; if (f) this.attach(f); };
            this.renderTopic(); this.renderBar(); this.renderFilter(); this.renderSub();
            if (this.refs.length) this.bindRefs();
            setTimeout(() => ta.focus(), 60);
        }

        // ---------------- پیام‌ها
        setMessages(list, first) {
            const sig = JSON.stringify(list.map(m => [m.id, m.text, m.edited, m.deleted, m.read, m.ref && m.ref.id, m.file && m.file.url]));
            if (sig === this.sig) return;
            const wasBottom = first || this.nearBottom();
            const fresh = list.filter(m => !this.seen.has(m.id));
            this.sig = sig; this.msgs = list;
            this.renderMessages(first);
            list.forEach(m => this.seen.add(m.id));
            if (wasBottom) this.scrollBottom(!first);
            else { this.newBelow += fresh.filter(m => !m.mine).length; this.fab(true); }
            if (this.refs.length) this.renderRefsList();
        }
        renderMessages(first) {
            const body = this.$('.cx-body');
            if (!body) return;
            let list = this.topicFilter ? this.msgs.filter(m => m.ref && m.ref.type === this.topicFilter.type && m.ref.id === this.topicFilter.id) : this.msgs;
            const sr = this.searchActive() ? this.search : null;
            if (sr) {
                const q = sr.q.toLowerCase();
                list = list.filter(m => !m.deleted && (!q || String(m.text || '').toLowerCase().includes(q) || (m.file && String(m.file.name).toLowerCase().includes(q)) || String(m.sender || '').toLowerCase().includes(q))
                    && (!sr.from || m.date >= sr.from) && (!sr.to || m.date <= sr.to));
                const n = this.$('.cx-fn'); if (n) n.textContent = fa(list.length) + ' پیام';
            }
            if (!list.length) {
                body.innerHTML = `<div class="cx-empty" style="height:100%"><div class="cx-big" style="width:70px;height:70px;font-size:28px"><i class="fas ${sr ? 'fa-magnifying-glass' : 'fa-hand-wave'}"></i></div>
                    <b style="color:var(--text)">${sr ? 'پیامی با این جستجو پیدا نشد' : this.topicFilter ? 'پیامی با این موضوع نیست' : 'هنوز پیامی رد و بدل نشده'}</b><span>${sr || this.topicFilter ? '' : 'اولین پیام را بفرستید 👋'}</span></div><div class="cx-typing-bubble"><i></i><i></i><i></i></div>`;
                return;
            }
            // کدام پیام‌ها پشتِ هم از یک نفرند (فقط آخرینِ هر دسته عکسِ فرستنده را نشان می‌دهد)
            const grp = list.map((m, i) => { const p = list[i - 1]; return !!(p && p.date === m.date && p.mine === m.mine && p.sender === m.sender && (m.ts - p.ts) < 300); });
            let html = '', lastDay = '';
            list.forEach((m, i) => {
                if (m.date !== lastDay) { html += `<div class="cx-day"><span>${dayLabel(m.date)}</span></div>`; lastDay = m.date; }
                html += this.msgHtml(m, grp[i], !first && !this.seen.has(m.id), !grp[i + 1]);
            });
            html += '<div class="cx-typing-bubble"><i></i><i></i><i></i></div>';
            body.innerHTML = html;
            body.querySelectorAll('.cx-msg').forEach(el => {
                const m = this.msgs.find(x => x.id === Number(el.dataset.id));
                el.oncontextmenu = e => { e.preventDefault(); this.menu(e.clientX, e.clientY, m); };
                let lp; el.ontouchstart = e => { lp = setTimeout(() => { const t = e.touches[0]; this.menu(t.clientX, t.clientY, m); }, 480); };
                el.ontouchend = el.ontouchmove = () => clearTimeout(lp);
                const dots = el.querySelector('.cx-dots'); if (dots) dots.onclick = e => { e.stopPropagation(); const r = dots.getBoundingClientRect(); this.menu(r.left, r.bottom, m); };
                const rp = el.querySelector('.cx-reply'); if (rp) rp.onclick = () => this.jump(Number(rp.dataset.to));
                const rf = el.querySelector('.cx-ref'); if (rf) rf.onclick = () => this.setFilter(m.ref);
                el.querySelectorAll('.cx-img').forEach(img => {
                    img.onclick = () => this.lightbox(img.src);
                    // عکس بعد از چیدمان بار می‌شود و ارتفاع را زیاد می‌کند؛ اگر پایینِ گفتگو بودیم همان‌جا بمانیم
                    if (!img.complete) img.onload = () => { if (this.stick !== false) this.scrollBottom(); };
                });
                el.ondblclick = () => { if (!m.deleted) this.reply(m); };
            });
            this.setTyping(this._typing);
        }
        msgHtml(m, grouped, isNew, lastOfGroup) {
            const side = m.mine ? 'me' : 'them';
            // عکسِ فرستنده کنارِ آخرین پیامِ هر دسته (فقط پیام‌های طرفِ مقابل)
            const mav = m.mine ? '' : `<span class="cx-mav">${lastOfGroup ? avatarHtml(m.avatar, m.sender, {}) : ''}</span>`;
            if (m.deleted) return `<div class="cx-row ${side} ${grouped ? '' : 'gap'}"><div class="cx-msg cx-del ${isNew ? 'cx-in' : ''}" data-id="${m.id}"><i class="fas fa-ban"></i> این پیام حذف شد · ${fa(m.time)}</div>${mav}</div>`;
            const f = m.file;
            const file = !f ? '' : f.image ? `<img class="cx-img" src="${esc(f.url)}" alt="">`
                : f.audio ? `<audio class="cx-audio" controls preload="none" src="${esc(f.url)}"></audio>`
                : `<a class="cx-file" href="${esc(f.url)}" target="_blank" download><i class="fas ${/pdf/.test(f.ext) ? 'fa-file-pdf' : /docx?/.test(f.ext) ? 'fa-file-word' : /xlsx?/.test(f.ext) ? 'fa-file-excel' : /zip|rar/.test(f.ext) ? 'fa-file-zipper' : 'fa-file'}"></i><span><b>${esc(f.name)}</b><small>${esc(f.ext.toUpperCase())} · دانلود</small></span></a>`;
            const showSender = !m.mine || this.o.mode === 'full' && this.head && this.head.type !== 'STAFF';
            return `<div class="cx-row ${side} ${grouped ? '' : 'gap'}"><div class="cx-msg ${isNew ? 'cx-in' : ''}" data-id="${m.id}" style="--h:${hue(m.sender)}">
                <button class="cx-dots" title="گزینه‌ها"><i class="fas fa-ellipsis-vertical"></i></button>
                ${!grouped && showSender ? `<div class="cx-sender">${esc(m.mine ? (this.o.mode === 'full' ? m.sender : 'شما') : m.sender)}</div>` : ''}
                ${m.ref ? `<span class="cx-ref" title="نمایشِ همه‌ی پیام‌های این موضوع"><i class="fas ${REF_ICON[m.ref.type] || 'fa-tag'}"></i><span>${esc(fa(m.ref.label))}</span></span>` : ''}
                ${m.reply ? `<span class="cx-reply" data-to="${m.reply.id}"><b>${esc(m.reply.sender)}</b><span>${esc(m.reply.text)}</span></span>` : ''}
                ${file}${m.text ? `<div>${this.searchActive() && this.search.q ? highlight(m.text, this.search.q) : linkify(m.text)}</div>` : ''}
                <div class="cx-meta">${m.mine ? `<i class="fas ${m.read ? 'fa-check-double cx-seen' : 'fa-check'}" title="${m.read ? 'دیده شد' : 'ارسال شد'}"></i>` : ''}<span>${fa(m.time)}</span>${m.edited ? '<span>ویرایش‌شده</span>' : ''}${this.searchActive() ? `<span>${fa(m.date)}</span>` : ''}</div>
            </div>${mav}</div>`;
        }
        nearBottom() { const b = this.$('.cx-body'); return !b || b.scrollHeight - b.scrollTop - b.clientHeight < 120; }
        scrollBottom(smooth) { const b = this.$('.cx-body'); if (!b) return; if (!smooth) { b.style.scrollBehavior = 'auto'; b.scrollTop = b.scrollHeight; b.style.scrollBehavior = ''; } else b.scrollTop = b.scrollHeight; this.stick = true; this.newBelow = 0; this.fab(); }
        fab(show) {
            const f = this.$('.cx-fab'); if (!f) return;
            f.classList.toggle('show', !!show && !this.nearBottom());
            f.innerHTML = `<i class="fas fa-chevron-down"></i>${this.newBelow ? `<span class="cx-badge">${fa(this.newBelow)}</span>` : ''}`;
        }
        jump(id) {
            if (this.topicFilter || this.searchActive()) { this.topicFilter = null; this.closeFind(true); this.renderFilter(); this.renderMessages(); }
            const el = this.$(`.cx-msg[data-id="${id}"]`);
            if (!el) return this.toast('پیامِ اصلی پیدا نشد.', 'warning');
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.remove('cx-flash'); void el.offsetWidth; el.classList.add('cx-flash');
        }
        setTyping(on) {
            this._typing = on;
            const t = this.$('.cx-typing-bubble'); if (t) t.classList.toggle('show', !!on);
            this.renderSub();
            if (on && this.nearBottom()) this.scrollBottom(true);
        }
        async poll() {
            if (this._polling) return;
            this._polling = true;
            const key = this.key;
            const d = await this.call('chat_poll', { key });
            this._polling = false;
            if (!d.ok || key !== this.key) return;
            if (d.presence !== undefined) { this.presence = d.presence; this.presenceAt = Date.now(); this.renderPresenceDot(); }
            this.setMessages(d.messages); this.setTyping(d.typing);
        }
        // زیرِ نامِ طرفِ مقابل: «در حال نوشتن...» یا «آنلاین / آخرین بازدید» و مشخصات
        renderSub() {
            const s = this.$('.cx-hsub'); if (!s || !this.head) return;
            if (this._typing) { s.innerHTML = '<span class="cx-typing">در حال نوشتن...</span>'; return; }
            const group = this.head.type !== 'STAFF' && this.o.mode === 'single';
            const lbl = seenLabel(this.presence, this.presenceAt, group);
            const sub = lbl && group ? '' : fa(this.head.sub || '');
            s.innerHTML = (lbl ? `<span class="cx-pr ${this.presence && this.presence.online ? 'on' : ''}">${esc(lbl)}</span>` : '') + (lbl && sub ? ' · ' : '') + esc(sub);
        }
        renderPresenceDot() {
            const av = this.$('.cx-head .cx-av'); if (!av) return;
            const on = !!(this.presence && this.presence.online), dot = av.querySelector('.cx-on');
            if (on && !dot) av.insertAdjacentHTML('beforeend', '<b class="cx-on" title="آنلاین"></b>');
            if (!on && dot) dot.remove();
            this.renderSub();
        }
        typingPing() { const now = Date.now(); if (now - (this._tp || 0) < 2500) return; this._tp = now; this.call('chat_typing', {}); }

        // ---------------- نوشتن و ارسال
        attach(f) {
            if (f.size > 20 * 1048576) return this.toast('حجم فایل بیشتر از ۲۰ مگابایت است.', 'error');
            this.file = f; this.renderBar();
        }
        renderBar() {
            const slot = this.$('.cx-barslot'); if (!slot) return;
            let h = '';
            if (this.editing) h += `<div class="cx-bar"><i class="fas fa-pen" style="color:var(--acc)"></i><div><b>ویرایشِ پیام</b><span>${esc(this.editing.text || '')}</span></div><button data-x="edit"><i class="fas fa-xmark"></i></button></div>`;
            else if (this.replyTo) h += `<div class="cx-bar"><i class="fas fa-reply" style="color:var(--acc)"></i><div><b>پاسخ به ${esc(this.replyTo.mine ? 'خودتان' : this.replyTo.sender)}</b><span>${esc(this.replyTo.text || (this.replyTo.file ? '📎 ' + this.replyTo.file.name : ''))}</span></div><button data-x="reply"><i class="fas fa-xmark"></i></button></div>`;
            if (this.file) {
                const img = /^image\//.test(this.file.type);
                if (img && !this._fileUrl) this._fileUrl = URL.createObjectURL(this.file);
                h += `<div class="cx-att">${img ? `<img src="${this._fileUrl}">` : '<i class="fas fa-file" style="font-size:22px;color:var(--acc)"></i>'}<div>${esc(this.file.name)} · ${fa((this.file.size / 1048576).toFixed(1))} مگابایت</div><button data-x="file"><i class="fas fa-trash"></i></button></div>`;
            }
            slot.innerHTML = h;
            slot.querySelectorAll('[data-x]').forEach(b => b.onclick = () => {
                const x = b.dataset.x;
                if (x === 'edit') { this.editing = null; this.$('.cx-ta').value = ''; }
                if (x === 'reply') this.replyTo = null;
                if (x === 'file') { this.file = null; if (this._fileUrl) URL.revokeObjectURL(this._fileUrl); this._fileUrl = null; }
                this.renderBar();
            });
        }
        renderTopic() {
            const b = this.$('.cx-topic'); if (!b) return;
            b.classList.toggle('set', !!this.topic);
            b.querySelector('span').textContent = this.topic ? 'درباره‌ی: ' + fa(this.topic.label) : 'موضوع: گفتگوی آزاد (برای انتخابِ درخواست بزنید)';
        }
        async send() {
            const ta = this.$('.cx-ta'), btn = this.$('.cx-send');
            const text = ta.value.trim();
            if (this.editing) {
                if (!text) return;
                btn.disabled = true;
                const d = await this.call('chat_edit', { id: this.editing.id, text });
                btn.disabled = false;
                if (!d.ok) return this.toast(d.error, 'error');
                this.editing = null; ta.value = ''; ta.style.height = ''; this.renderBar(); this.poll();
                return;
            }
            if (!text && !this.file) return;
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
            const ref = this.topic ? { type: this.topic.type, id: this.topic.id } : null;
            let d;
            if (this.file) {
                const fd = new FormData();
                fd.append('action', 'chat_send'); fd.append('key', this.key || ''); fd.append('text', text);
                if (this.replyTo) fd.append('reply_to', this.replyTo.id);
                if (ref) fd.append('ref', JSON.stringify(ref));
                fd.append('file', this.file, this.file.name);
                try { d = await this.o.upload('chat_send', fd); } catch (e) { d = { ok: false, error: 'خطا در ارسالِ فایل.' }; }
            } else d = await this.call('chat_send', { text, reply_to: this.replyTo ? this.replyTo.id : null, ref });
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            if (!d.ok) return this.toast(d.error || 'ارسال نشد.', 'error');
            ta.value = ''; ta.style.height = ''; this.replyTo = null; this.file = null; if (this._fileUrl) URL.revokeObjectURL(this._fileUrl); this._fileUrl = null;
            this.renderBar(); ta.focus();
            await this.poll(); this.scrollBottom(true);
            if (this.o.mode === 'full') this.loadThreads(true);
        }
        reply(m) { this.editing = null; this.replyTo = m; this.renderBar(); this.$('.cx-ta').focus(); }
        edit(m) { this.replyTo = null; this.editing = m; const ta = this.$('.cx-ta'); ta.value = m.text || ''; ta.dispatchEvent(new Event('input')); this.renderBar(); ta.focus(); }
        async del(m) {
            const ok = await dialog({ title: 'حذفِ پیام', message: 'این پیام برای هر دو طرف «این پیام حذف شد» نمایش داده می‌شود.', danger: true, theme: this.o.theme });
            if (!ok) return;
            const d = await this.call('chat_delete', { id: m.id });
            if (!d.ok) return this.toast(d.error, 'error');
            this.poll();
        }
        emoji() {
            const ex = this.$('.cx-emoji'); if (ex) { ex.remove(); return; }
            const p = document.createElement('div');
            p.className = 'cx-emoji';
            p.innerHTML = EMOJI.map(e => `<button>${e}</button>`).join('');
            this.$('.cx-compose').appendChild(p);
            p.querySelectorAll('button').forEach(b => b.onclick = e => {
                e.stopPropagation();
                const ta = this.$('.cx-ta'), s = ta.selectionStart ?? ta.value.length;
                ta.value = ta.value.slice(0, s) + b.textContent + ta.value.slice(ta.selectionEnd ?? s);
                ta.focus(); ta.selectionStart = ta.selectionEnd = s + b.textContent.length;
            });
        }
        // ---------------- جستجو در گفتگو: متن + بازه‌ی تاریخ (کنارِ دکمه‌ی جستجو باز می‌شود)
        searchActive() { return !!(this.search && (this.search.q || this.search.from || this.search.to)); }
        bindFind() {
            const head = this.$('.cx-head'), q = this.$('.cx-fq'), from = this.$('.cx-f-from'), to = this.$('.cx-f-to'), dates = this.$('.cx-fdates');
            const apply = () => {
                this.search = { q: q.value.trim(), from: normJ(from.value), to: normJ(to.value) };
                const n = this.$('.cx-fn'); if (n && !this.searchActive()) n.textContent = '';
                this.renderMessages(); this.scrollBottom();
            };
            let t; const later = () => { clearTimeout(t); t = setTimeout(apply, 220); };
            q.oninput = later; from.oninput = later; to.oninput = later;
            q.onkeydown = e => { if (e.key === 'Escape') this.closeFind(); };
            this.$('.cx-find').onclick = () => {
                if (head.classList.contains('cx-finding')) { if (q.value || from.value || to.value) { q.focus(); return; } this.closeFind(); return; }
                head.classList.add('cx-finding'); dates.classList.add('open'); setTimeout(() => q.focus(), 120);
            };
            dates.querySelectorAll('[data-r]').forEach(b => b.onclick = () => {
                const r = b.dataset.r;
                from.value = r === '' ? '' : fa(jdate(new Date(Date.now() - Math.max(0, Number(r) - 1) * 864e5)));
                to.value = r === '' ? '' : fa(jdate(new Date()));
                dates.querySelectorAll('[data-r]').forEach(x => x.classList.toggle('on', x === b && r !== ''));
                apply();
            });
        }
        closeFind(silent) {
            const head = this.$('.cx-head'); if (!head) return;
            head.classList.remove('cx-finding'); this.$('.cx-fdates').classList.remove('open');
            ['.cx-fq', '.cx-f-from', '.cx-f-to'].forEach(s => { const e = this.$(s); if (e) e.value = ''; });
            this.$('.cx-fdates').querySelectorAll('[data-r]').forEach(x => x.classList.remove('on'));
            const n = this.$('.cx-fn'); if (n) n.textContent = '';
            const was = this.searchActive(); this.search = null;
            if (was && !silent) { this.renderMessages(); this.scrollBottom(); }
        }
        lightbox(src) {
            const lb = document.createElement('div');
            lb.className = 'cx-lb'; lb.innerHTML = `<img src="${esc(src)}">`;
            lb.onclick = () => lb.remove();
            document.body.appendChild(lb);
        }

        // ---------------- منوی کلیک‌راست
        menu(x, y, m) {
            document.querySelectorAll('.cx-menu').forEach(e => e.remove());
            const staffThread = this.o.mode === 'full' && this.head && this.head.type !== 'STAFF';
            const canRef = this.refs.length && !m.deleted && (staffThread || m.can_modify);
            const items = [];
            if (!m.deleted) items.push(['fa-reply', 'پاسخ', () => this.reply(m)]);
            if (m.text) items.push(['fa-copy', 'کپیِ متن', () => { navigator.clipboard && navigator.clipboard.writeText(m.text); this.toast('کپی شد.', 'success'); }]);
            if (m.file) items.push(['fa-download', 'دانلودِ فایل', () => { const a = document.createElement('a'); a.href = m.file.url; a.download = m.file.name; a.target = '_blank'; document.body.appendChild(a); a.click(); a.remove(); }]);
            if (m.reply) items.push(['fa-arrow-turn-up', 'رفتن به پیامِ اصلی', () => this.jump(m.reply.id)]);
            if (this.searchActive() || this.topicFilter) items.push(['fa-location-crosshairs', 'نمایشِ این پیام در گفتگو', () => this.jump(m.id)]);
            if (canRef) items.push(['fa-tag', m.ref ? 'تغییرِ موضوعِ پیام' : 'تعیینِ موضوعِ پیام (درخواست)', () => this.openRefs('message', m)]);
            if (m.ref) items.push(['fa-filter', 'همه‌ی پیام‌های همین موضوع', () => this.setFilter(m.ref)]);
            if (m.can_modify) {
                items.push(null);
                if (m.text !== null) items.push(['fa-pen', 'ویرایش', () => this.edit(m)]);
                items.push(['fa-trash', 'حذف', () => this.del(m), 'danger']);
            }
            if (!items.length) return;
            const el = document.createElement('div');
            el.className = 'cx-menu' + (this.o.theme === 'dark' ? ' dark' : '');
            el.innerHTML = `<div class="cx-mh">${esc(m.mine ? 'پیامِ شما' : m.sender)} · ${fa(m.time)}</div>` + items.map((it, i) => it ? `<button data-i="${i}" class="${it[3] || ''}"><i class="fas ${it[0]}"></i>${it[1]}</button>` : '<hr>').join('');
            document.body.appendChild(el);
            // offsetWidth/Height (نه getBoundingClientRect) چون انیمیشنِ بازشدن اندازه را کوچک نشان می‌دهد
            const w = el.offsetWidth, h = el.offsetHeight;
            el.style.left = Math.max(8, Math.min(x - w, window.innerWidth - w - 8)) + 'px';
            el.style.top = Math.max(8, Math.min(y, window.innerHeight - h - 8)) + 'px';
            el.querySelectorAll('[data-i]').forEach(b => b.onclick = () => { el.remove(); items[Number(b.dataset.i)][2](); });
            const close = e => { if (!el.contains(e.target)) { el.remove(); document.removeEventListener('mousedown', close, true); document.removeEventListener('scroll', close, true); } };
            setTimeout(() => { document.addEventListener('mousedown', close, true); document.addEventListener('scroll', close, true); }, 0);
            document.addEventListener('keydown', function k(e) { if (e.key === 'Escape') { el.remove(); document.removeEventListener('keydown', k); } });
        }

        // ---------------- درخواست‌ها (موضوعِ پیام)
        refsDrawerHtml() {
            return `<div class="cx-refs"><div class="cx-refs-h"><i class="fas fa-folder-tree" style="color:var(--acc)"></i><b class="cx-refs-title">درخواست‌ها</b><button class="cx-ib cx-refs-x"><i class="fas fa-xmark"></i></button></div>
                <div style="padding:8px 10px 0;font-size:10.5px;color:var(--muted)" class="cx-refs-hint"></div><div class="cx-refs-l"></div></div>`;
        }
        bindRefs() { this.$('.cx-refs-x').onclick = () => this.$('.cx-refs').classList.remove('open'); }
        openRefs(mode, msg) {
            this.refsMode = mode || 'browse'; this.refsMsg = msg || null;
            const dr = this.$('.cx-refs'); if (!dr) return;
            this.$('.cx-refs-title').textContent = this.refsMode === 'message' ? 'موضوعِ این پیام' : this.refsMode === 'pick' ? 'پیامِ بعدی درباره‌ی...' : 'درخواست‌های ' + (this.head.type === 'STAFF' ? 'گزارش‌های این همکار' : this.head.title);
            this.$('.cx-refs-hint').textContent = this.refsMode === 'browse' ? 'روی هر درخواست: «صحبت درباره‌ی این» یا «پیام‌های این درخواست».' : 'یک درخواست را انتخاب کنید یا «گفتگوی آزاد».';
            this.renderRefsList();
            dr.classList.add('open');
        }
        renderRefsList() {
            const box = this.$('.cx-refs-l'); if (!box) return;
            const count = r => this.msgs.filter(m => m.ref && m.ref.type === r.type && m.ref.id === r.id && !m.deleted).length;
            const cur = this.refsMode === 'message' ? (this.refsMsg && this.refsMsg.ref) : this.topic;
            const isCur = r => cur && cur.type === r.type && cur.id === r.id;
            const free = `<div class="cx-ref-i ${!cur ? 'on' : ''}" data-free="1"><b><i class="fas fa-comments" style="color:var(--acc)"></i> گفتگوی آزاد<span class="cx-cnt">${fa(this.msgs.filter(m => !m.ref && !m.deleted).length)} پیام</span></b><small>درباره‌ی درخواستِ خاصی نیست</small>
                ${this.refsMode === 'browse' ? '<div class="cx-ref-a"><button data-act="use">صحبتِ آزاد</button></div>' : ''}</div>`;
            box.innerHTML = free + this.refs.map((r, i) => `<div class="cx-ref-i ${isCur(r) ? 'on' : ''}" data-i="${i}" style="animation-delay:${Math.min(i, 10) * 30}ms">
                <b><i class="fas ${REF_ICON[r.type] || 'fa-tag'}" style="color:var(--acc)"></i><span>${esc(fa(r.label))}</span><span class="cx-cnt">${fa(count(r))} پیام</span></b><small>${esc(fa(r.sub || ''))}</small>
                ${this.refsMode === 'browse' ? '<div class="cx-ref-a"><button data-act="use">صحبت درباره‌ی این</button><button data-act="filter">پیام‌های این درخواست</button></div>' : ''}</div>`).join('');
            box.querySelectorAll('.cx-ref-i').forEach(el => {
                const r = el.dataset.free ? null : this.refs[Number(el.dataset.i)];
                const pick = async () => {
                    if (this.refsMode === 'message') {
                        const d = await this.call('chat_set_ref', { id: this.refsMsg.id, ref: r ? { type: r.type, id: r.id } : null });
                        if (!d.ok) return this.toast(d.error, 'error');
                        this.$('.cx-refs').classList.remove('open'); this.toast('موضوعِ پیام ثبت شد.', 'success'); this.poll();
                        return;
                    }
                    this.topic = r; this.renderTopic(); this.$('.cx-refs').classList.remove('open'); this.$('.cx-ta').focus();
                };
                if (this.refsMode === 'browse') {
                    el.querySelectorAll('[data-act]').forEach(b => b.onclick = e => { e.stopPropagation(); if (b.dataset.act === 'use') pick(); else { this.setFilter(r); this.$('.cx-refs').classList.remove('open'); } });
                } else el.onclick = pick;
            });
        }
        setFilter(ref) {
            this.topicFilter = ref ? { type: ref.type, id: ref.id, label: ref.label } : null;
            this.renderFilter(); this.renderMessages(); this.scrollBottom();
        }
        renderFilter() {
            const slot = this.$('.cx-filterslot'); if (!slot) return;
            slot.innerHTML = this.topicFilter ? `<div class="cx-filterbar"><i class="fas fa-filter"></i> فقط پیام‌های «${esc(fa(this.topicFilter.label))}»<button>نمایشِ همه ✕</button></div>` : '';
            const b = slot.querySelector('button'); if (b) b.onclick = () => this.setFilter(null);
        }
    }

    window.ChatUI = { mount: (el, o) => new Chat(el, o), fa, esc, avatarHtml, avatarUrl, seenLabel, pickAvatar, dialog, heartbeat, toast: miniToast, PRESETS };
})();
