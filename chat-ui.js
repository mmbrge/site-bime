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
    const REACTS = ['❤️', '👍', '😂', '😮', '😢', '🙏', '🔥', '👏'];
    const LS = { get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} } };

    // ---- صدای ارسال و دریافت (ساخته‌شده با Web Audio؛ شبیهِ «ووش» و «دینگ»ِ پیام‌های آیفون) ----
    const SND = {
        ctx: null,
        ac() {
            if (!this.ctx) { const C = window.AudioContext || window.webkitAudioContext; if (!C) return null; this.ctx = new C(); }
            if (this.ctx.state === 'suspended') this.ctx.resume().catch(() => {});
            return this.ctx;
        },
        // ووشِ ارسال: نویزِ فیلترشده که از زیر به بالا جارو می‌شود + یک «تیک»ِ ملایم
        send() {
            const c = this.ac(); if (!c) return;
            const t = c.currentTime, len = 0.32, n = Math.floor(c.sampleRate * len), buf = c.createBuffer(1, n, c.sampleRate), d = buf.getChannelData(0);
            for (let i = 0; i < n; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / n);
            const src = c.createBufferSource(); src.buffer = buf;
            const bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.Q.value = 1.4;
            bp.frequency.setValueAtTime(500, t); bp.frequency.exponentialRampToValueAtTime(3800, t + 0.2);
            const g = c.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.22, t + 0.05); g.gain.exponentialRampToValueAtTime(0.0001, t + len);
            src.connect(bp).connect(g).connect(c.destination); src.start(t); src.stop(t + len);
            const o = c.createOscillator(), og = c.createGain(); o.type = 'sine';
            o.frequency.setValueAtTime(1400, t + 0.16); o.frequency.exponentialRampToValueAtTime(2100, t + 0.24);
            og.gain.setValueAtTime(0.0001, t + 0.16); og.gain.exponentialRampToValueAtTime(0.07, t + 0.18); og.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
            o.connect(og).connect(c.destination); o.start(t + 0.16); o.stop(t + 0.32);
        },
        // دینگِ دریافت: دو نتِ زنگ‌مانند پشتِ سرِ هم
        recv() {
            const c = this.ac(); if (!c) return;
            const t = c.currentTime;
            [[1318.5, 0], [1760, 0.11]].forEach(([f, dt]) => {
                [1, 2.01].forEach((h, k) => {
                    const o = c.createOscillator(), g = c.createGain(); o.type = k ? 'sine' : 'triangle'; o.frequency.value = f * h;
                    const v = k ? 0.035 : 0.13;
                    g.gain.setValueAtTime(0.0001, t + dt); g.gain.exponentialRampToValueAtTime(v, t + dt + 0.012); g.gain.exponentialRampToValueAtTime(0.0001, t + dt + 0.55);
                    o.connect(g).connect(c.destination); o.start(t + dt); o.stop(t + dt + 0.6);
                });
            });
        },
    };
    // مرورگر بدونِ تعاملِ کاربر صدا پخش نمی‌کند؛ با اولین لمس/کلیک آماده می‌شود
    ['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, () => { try { SND.ac(); } catch (e) {} }, { once: true, capture: true }));
    // بی‌صدا: کلِ گفتگوها، یا هر گفتگو جدا
    const MUTE = {
        k: 'cx:mute',
        get() { return LS.get(this.k, { all: false, keys: {} }) || { all: false, keys: {} }; },
        all() { return !!this.get().all; },
        is(key) { const m = this.get(); return !!m.all || !!(key && m.keys && m.keys[key]); },
        own(key) { const m = this.get(); return !!(m.keys && m.keys[key]); },
        toggle(key) { const m = this.get(); m.keys = m.keys || {}; if (key) { if (m.keys[key]) delete m.keys[key]; else m.keys[key] = 1; } else m.all = !m.all; LS.set(this.k, m); },
    };
    // ایموجیِ تکی: بزرگ و با انیمیشنِ مخصوصِ خودش
    const JUMBO_RE = /^(?:\p{Regional_Indicator}{2}|(?:\p{Extended_Pictographic}|[\u2600-\u27BF])(?:\uFE0F|\p{Emoji_Modifier})?(?:\u200D(?:\p{Extended_Pictographic}|[\u2600-\u27BF])(?:\uFE0F|\p{Emoji_Modifier})?)*)$/u;
    const isJumbo = t => { t = String(t || '').trim(); return !!t && t.length <= 16 && JUMBO_RE.test(t); };
    const JUMBO_ANIM = e => {
        const M = [['❤️💖💗💓💕♥️😍🥰😘', 'beat'], ['😂🤣😆😁😀😃😄', 'laugh'], ['👍👌✅💯🤝', 'thumb'], ['🔥', 'flame'], ['🎉🥳🎊✨⭐🌟', 'party'],
                   ['😢😭😞😔🥺', 'cry'], ['😡🤬😠👿', 'angry'], ['😮😯😲🤯😱', 'wow'], ['🙏', 'pray'], ['👏💪', 'clap'], ['😎🤩', 'cool'], ['🤔🧐', 'think'], ['👋', 'wave'], ['🚗🚙🛻🚚🚀', 'drive']];
        const f = M.find(([set]) => [...set].some(c => e.indexOf(c) === 0 || e === c)); return f ? f[1] : 'bounce';
    };
    const JUMBO_SEEN = { k: 'cx:jumbo', has(id) { return (LS.get(this.k, []) || []).includes(id); }, add(id) { const a = LS.get(this.k, []) || []; if (!a.includes(id)) { a.push(id); LS.set(this.k, a.slice(-400)); } } };
    // گفتگوهایی که همین الان روی صفحه دیده می‌شوند (برای «خوانده شد»ِ اعلان‌ها)
    const LIVE = new Set();
    // همه‌ی «امروز/دیروز/ساعت»ها به وقتِ ایران و بر اساسِ ساعتِ سرور (iran-time.js)، نه ساعتِ سیستمِ کاربر
    const nowMs = () => window.IrTime ? IrTime.now() : Date.now();
    const dayLabel = d => d === jdate(nowMs()) ? 'امروز' : d === jdate(nowMs() - 864e5) ? 'دیروز' : fa(d);
    const initials = s => { const w = String(s || '؟').trim().split(/\s+/); return (w[0] || '؟').charAt(0) + (w[1] ? w[1].charAt(0) : ''); };
    const hue = s => { let h = 0; for (const c of String(s || '')) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
    const pad2 = n => String(n).padStart(2, '0');
    // تاریخِ شمسیِ یک لحظه (Date یا میلی‌ثانیه) به وقتِ ایران به شکلِ 1405/07/08 (ارقامِ لاتین، برای مقایسه)
    const jdate = d => {
        const ms = typeof d === 'number' ? d : +d;
        if (window.IrTime) return IrTime.jdate(ms);
        try { return en(new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Tehran' }).format(new Date(ms))).replace(/[^\d/]/g, ''); } catch (e) { return ''; }
    };
    const hmIr = ms => window.IrTime ? IrTime.hm(ms) : (d => pad2(d.getHours()) + ':' + pad2(d.getMinutes()))(new Date(ms));
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
        // «وضعیتِ من» (امکاناتِ رفاهی): مثلِ «🗓️ در جلسه» کنارِ آنلاین/آخرین بازدید
        const st = !group && p.status && p.status.text ? ' · ' + (p.status.icon || '') + ' ' + p.status.text : '';
        return seenBase(p, at, group) + st;
    }
    function seenBase(p, at, group) {
        if (p.online) return group ? 'کارشناسان آنلاین هستند' : 'آنلاین';
        const pre = group ? 'آخرین حضورِ کارشناس: ' : 'آخرین بازدید: ';
        if (p.ago === null || p.ago === undefined) return group ? '' : 'هنوز وارد نشده';
        const ago = p.ago + Math.max(0, Math.round((Date.now() - (at || Date.now())) / 1000));
        if (ago < 60) return pre + 'لحظاتی پیش';
        if (ago < 3600) return pre + fa(Math.floor(ago / 60)) + ' دقیقه پیش';
        const t = nowMs() - ago * 1000, hm = fa(hmIr(t)), jd = jdate(t);
        if (jd === jdate(nowMs())) return pre + 'امروز ساعت ' + hm;
        if (jd === jdate(nowMs() - 864e5)) return pre + 'دیروز ساعت ' + hm;
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
                ${o.choices ? `<div class="cx-dlg-ch">${o.choices.map((c, i) => `<button data-c="${i}" class="${c.danger ? 'danger' : ''}"><b>${esc(c.label)}</b>${c.note ? `<small>${esc(c.note)}</small>` : ''}</button>`).join('')}</div>` : ''}
                <div class="cx-dlg-b">${o.choices ? '' : '<button class="cx-dlg-ok ' + (o.danger ? 'danger' : '') + '"></button>'}<button class="cx-dlg-no"></button></div></div>`;
            ov.querySelector('.cx-dlg-t').textContent = o.title || '';
            const msg = ov.querySelector('.cx-dlg-m'); msg.textContent = o.message || ''; if (!o.message) msg.remove();
            const okb = ov.querySelector('.cx-dlg-ok'); if (okb) okb.textContent = o.ok || (o.danger ? 'بله، حذف شود' : 'تایید');
            ov.querySelector('.cx-dlg-no').textContent = o.cancel || 'انصراف';
            const inp = ov.querySelector('.cx-dlg-in');
            if (inp) { inp.value = (o.input.value || ''); inp.placeholder = o.input.placeholder || ''; }
            const done = v => { ov.classList.remove('on'); document.removeEventListener('keydown', key, true); setTimeout(() => ov.remove(), 220); resolve(v); };
            const ok = () => done(inp ? inp.value : true), no = () => done(o.choices || inp ? null : false);
            const key = e => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); no(); } if (e.key === 'Enter' && okb && (inp || document.activeElement === okb)) { e.preventDefault(); ok(); } };
            if (okb) okb.onclick = ok; ov.querySelector('.cx-dlg-no').onclick = no;
            ov.querySelectorAll('[data-c]').forEach(b => b.onclick = () => done(o.choices[Number(b.dataset.c)].value));
            ov.onclick = e => { if (e.target === ov) no(); };
            document.addEventListener('keydown', key, true);
            document.body.appendChild(ov);
            requestAnimationFrame(() => ov.classList.add('on'));
            setTimeout(() => { const f = inp || okb || ov.querySelector('.cx-dlg-no'); if (f) f.focus(); }, 60);
        });
    }
    function miniToast(msg, type) {
        injectCss();
        const t = document.createElement('div');
        t.className = 'cx-toast ' + (type || ''); t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.classList.add('out'), 2300); setTimeout(() => t.remove(), 2700);
    }

    // به‌روزرسانیِ بی‌صدای یک فهرست: blocks = [[کلید، html، تازه؟], ...]؛ فقط گره‌هایی که html‌شان عوض شده
    // دوباره ساخته می‌شوند و ترتیب درست می‌شود (بدونِ خالی/پر شدنِ کلِ فهرست که چشمک می‌زند)
    function patchList(box, blocks, onNew) {
        const old = new Map();
        [...box.children].forEach(c => { if (c.dataset && c.dataset.pk) old.set(c.dataset.pk, c); });
        const keep = new Set(); let prev = null;
        for (const [k, html, isNew] of blocks) {
            let node = old.get(k);
            if (!node || node._h !== html) {
                const t = document.createElement('template'); t.innerHTML = html.trim();
                const n = t.content.firstElementChild; n.dataset.pk = k; n._h = html;
                if (node) node.replaceWith(n);
                node = n; if (onNew) onNew(n, k, isNew);
            }
            const want = prev ? prev.nextSibling : box.firstChild;
            if (want !== node) box.insertBefore(node, want);
            keep.add(node); prev = node;
        }
        [...box.children].forEach(c => { if (!keep.has(c) && !(c.classList && c.classList.contains('cx-typing-bubble'))) c.remove(); });
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
.cx-settled .cx-th{animation:none}
.cx-th.on{background:linear-gradient(135deg,rgba(79,70,229,.12),rgba(124,58,237,.12))}
.cx-th.on::before{content:'';pointer-events:none;position:absolute;right:0;top:12px;bottom:12px;width:3px;border-radius:3px;background:linear-gradient(var(--me1),var(--me2))}
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
.cx-menu button:disabled,.cx-menu button.disabled{opacity:.38;cursor:not-allowed;background:transparent!important;color:inherit!important}
.cx-selslot:empty{display:none}
.cx-selbar{display:flex;align-items:center;gap:6px;padding:7px 12px;background:linear-gradient(135deg,rgba(79,70,229,.12),rgba(124,58,237,.12));border-bottom:1px solid var(--line);position:relative;z-index:2;animation:cxSlide .25s both;flex-wrap:wrap}
.cx-selbar b{flex:1;font-size:12px;color:var(--acc);min-width:120px}
.cx-selb{border:1px solid var(--line);background:var(--panel);color:var(--text);border-radius:10px;padding:5px 10px;font-size:11px;font-weight:800;transition:.15s}
.cx-selb:hover:not(:disabled){border-color:var(--acc);color:var(--acc)}
.cx-selb.danger{color:#e11d48}
.cx-selb:disabled{opacity:.4;cursor:not-allowed}
.cx-selmode .cx-row{padding-right:34px;cursor:pointer;border-radius:14px;transition:background .15s}
.cx-selmode .cx-row::before{content:'';pointer-events:none;position:absolute;right:6px;top:50%;width:18px;height:18px;margin-top:-9px;border-radius:50%;border:2px solid var(--muted);background:var(--panel);transition:.15s}
.cx-selmode .cx-row.cx-sel{background:rgba(99,102,241,.1)}
.cx-selmode .cx-row.cx-sel::before{border-color:var(--acc);background:var(--acc);box-shadow:inset 0 0 0 3px var(--panel)}
.cx-selmode .cx-dots{display:none}
.cx-closedbar{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:14px;background:rgba(225,29,72,.08);color:#be123c;font-size:12px;font-weight:700;animation:cxSlide .25s both;margin-bottom:6px}
.cx-dark .cx-closedbar{color:#fda4af}
.cx-closedbar.slim{background:rgba(245,158,11,.1);color:#b45309;padding:6px 10px;font-size:11px}
.cx-closedbar span{flex:1;line-height:1.8}
.cx-closedbar button{border:0;background:var(--panel);color:var(--acc);border-radius:10px;padding:5px 10px;font-size:11px;font-weight:900;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.cx-compose.cx-blocked .cx-in-row,.cx-compose.cx-blocked .cx-topic,.cx-compose.cx-blocked .cx-barslot{display:none}
.cx-compose.cx-blocked .cx-closedbar{margin-bottom:0}
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
.cx-dlg-ch{display:flex;flex-direction:column;gap:8px;margin-bottom:10px}
.cx-dlg-ch button{border:1.5px solid #e0e7ff;background:#f8faff;border-radius:16px;padding:10px 14px;text-align:right;font-family:inherit;cursor:pointer;transition:.18s;color:inherit}
.cx-dlg-ch button:hover{border-color:#6366f1;background:#eef2ff;transform:translateY(-1px)}
.cx-dlg-ch button b{display:block;font-size:13px;color:#4338ca}
.cx-dlg-ch button small{display:block;font-size:11px;opacity:.7;margin-top:2px;line-height:1.7}
.cx-dlg-ch button.danger{border-color:#fecdd3;background:#fff5f6}
.cx-dlg-ch button.danger b{color:#e11d48}
.cx-dlg-ch button.danger:hover{border-color:#e11d48;background:#ffe4e6}
.cx-dlg-ov.dark .cx-dlg-ch button{background:rgba(255,255,255,.05);border-color:rgba(165,180,252,.25)}
.cx-dlg-ov.dark .cx-dlg-ch button b{color:#a5b4fc}
.cx-dlg-ov.dark .cx-dlg-ch button.danger b{color:#fb7185}
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
.cx-head-b .cx-typing{display:inline-flex;align-items:center;gap:3px}
.cx-typing i{display:inline-block;width:4px;height:4px;border-radius:50%;background:currentColor;animation:cxDot 1.1s infinite}
.cx-typing i:nth-of-type(2){animation-delay:.15s}.cx-typing i:nth-of-type(3){animation-delay:.3s}
.cx-th-l p.cx-tp{color:var(--acc);font-weight:800;display:inline-flex;align-items:center;gap:3px}
.cx-mute{border:0;background:transparent;color:var(--muted);font-size:11px;width:22px;height:22px;border-radius:7px;opacity:0;transition:.2s;flex-shrink:0}
.cx-th:hover .cx-mute,.cx-mute.on{opacity:1}.cx-mute.on{color:#e11d48}.cx-mute:hover{background:var(--soft)}
.cx-ib.cx-muted{color:#e11d48}
.cx-reacts{display:flex;flex-wrap:wrap;gap:4px;margin-top:3px}
.cx-row.me .cx-reacts{justify-content:flex-start}.cx-row.them .cx-reacts{justify-content:flex-end}
.cx-rc{border:1px solid var(--line);background:var(--panel);color:var(--text);border-radius:999px;padding:1px 8px 1px 7px;font-size:13px;line-height:1.6;display:inline-flex;align-items:center;gap:3px;box-shadow:0 2px 8px -4px rgba(15,23,42,.3);transition:transform .15s}
.cx-rc b{font-size:10px;font-weight:900;color:var(--muted)}
.cx-rc.mine{border-color:var(--acc);background:rgba(99,102,241,.12)}
.cx-rc:hover{transform:scale(1.08)}
.cx-rc.pop{animation:cxRPop .7s cubic-bezier(.2,1.6,.4,1) both}
.cx-msgwrap{display:flex;flex-direction:column;max-width:min(74%,560px)}
.cx-row.me .cx-msgwrap{align-items:flex-start}.cx-row.them .cx-msgwrap{align-items:flex-end}
.cx-msgwrap .cx-msg{max-width:100%}
.cx-msg.cx-sent{animation:cxSend .5s cubic-bezier(.2,.9,.25,1.2) both}
.cx-msg.cx-recv{animation:cxRecv .55s cubic-bezier(.2,.9,.3,1.3) both}
.cx-swipe-ic{position:absolute;top:50%;width:30px;height:30px;margin-top:-15px;border-radius:50%;background:var(--panel);color:var(--acc);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px -4px rgba(15,23,42,.35);opacity:0;transform:scale(.4);transition:opacity .15s,transform .15s;pointer-events:none;font-size:12px}
.cx-msg.cx-jmb{background:transparent!important;box-shadow:none!important;padding:0 4px;color:var(--text)!important}
.cx-jumbo{font-size:58px;line-height:1.15;display:inline-block;cursor:pointer;user-select:none;filter:drop-shadow(0 6px 10px rgba(15,23,42,.18));transform-origin:50% 80%}
.cx-jumbo.a-beat{animation:jBeat 1.3s ease-in-out}.cx-jumbo.a-laugh{animation:jLaugh 1.2s ease-in-out}.cx-jumbo.a-thumb{animation:jThumb 1s cubic-bezier(.2,1.6,.4,1)}
.cx-jumbo.a-flame{animation:jFlame 1.4s ease-in-out}.cx-jumbo.a-party{animation:jParty 1.2s cubic-bezier(.2,1.5,.4,1)}.cx-jumbo.a-cry{animation:jCry 1.6s ease-in-out}
.cx-jumbo.a-angry{animation:jAngry .9s linear}.cx-jumbo.a-wow{animation:jWow 1s cubic-bezier(.2,1.6,.4,1)}.cx-jumbo.a-pray{animation:jPray 1.3s ease-in-out}
.cx-jumbo.a-clap{animation:jClap 1s ease-in-out}.cx-jumbo.a-cool{animation:jCool 1.2s ease-in-out}.cx-jumbo.a-think{animation:jThink 1.5s ease-in-out}
.cx-jumbo.a-wave{animation:jWave 1.3s ease-in-out}.cx-jumbo.a-drive{animation:jDrive 1.3s ease-in-out}.cx-jumbo.a-bounce{animation:jBounce 1s cubic-bezier(.2,1.6,.4,1)}
.cx-rec{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,rgba(225,29,72,.1),rgba(124,58,237,.1));border-radius:16px;padding:8px 12px;margin-bottom:6px;animation:cxSlide .25s both}
.cx-rec .dot{width:10px;height:10px;border-radius:50%;background:#e11d48;animation:cxPulseR 1s infinite}
.cx-rec b{font-variant-numeric:tabular-nums;font-size:13px;min-width:44px}
.cx-rec .wv{flex:1;display:flex;align-items:center;gap:2px;height:26px;overflow:hidden}
.cx-rec .wv i{width:3px;border-radius:3px;background:var(--acc);opacity:.75}
.cx-rec button{border:0;border-radius:12px;width:38px;height:38px}
.cx-rec .x{background:var(--soft);color:#e11d48}.cx-rec .ok{background:linear-gradient(135deg,var(--me1),var(--me2));color:#fff}
.cx-mic.on{background:#e11d48;color:#fff;animation:cxPulseR 1.2s infinite}
.cx-voice{display:flex;align-items:center;gap:8px;min-width:200px;padding:3px 0}
.cx-voice .pl{width:36px;height:36px;border-radius:50%;border:0;flex-shrink:0;background:rgba(255,255,255,.25);color:inherit;font-size:13px}
.cx-row.them .cx-voice .pl{background:linear-gradient(135deg,var(--me1),var(--me2));color:#fff}
.cx-voice .bars{flex:1;display:flex;align-items:center;gap:2px;height:28px;cursor:pointer}
.cx-voice .bars i{flex:1;min-width:2px;border-radius:2px;background:currentColor;opacity:.35;transition:opacity .2s}
.cx-voice .bars i.on{opacity:1}
.cx-voice small{font-size:10px;opacity:.8;min-width:34px;text-align:left;font-variant-numeric:tabular-nums}
.cx-send.cx-fly i{animation:cxFly .5s ease-in both}
@keyframes cxRPop{0%{transform:scale(0) rotate(-20deg);opacity:0}55%{transform:scale(1.45) rotate(8deg);opacity:1}100%{transform:none}}
@keyframes cxSend{0%{opacity:0;transform:translate(-30px,40px) scale(.6)}70%{opacity:1;transform:translate(2px,-3px) scale(1.03)}100%{transform:none}}
@keyframes cxRecv{0%{opacity:0;transform:translateX(30px) scale(.7)}60%{opacity:1;transform:translateX(-4px) scale(1.04)}100%{transform:none}}
@keyframes cxFly{0%{transform:none}45%{transform:translate(-14px,-14px) rotate(-20deg);opacity:0}46%{transform:translate(14px,14px);opacity:0}100%{transform:none;opacity:1}}
@keyframes cxPulseR{0%{box-shadow:0 0 0 0 rgba(225,29,72,.5)}70%{box-shadow:0 0 0 9px rgba(225,29,72,0)}100%{box-shadow:0 0 0 0 rgba(225,29,72,0)}}
@keyframes jBeat{0%,100%{transform:scale(1)}15%{transform:scale(1.35)}30%{transform:scale(1)}45%{transform:scale(1.3)}60%{transform:scale(1)}}
@keyframes jLaugh{0%,100%{transform:none}10%,30%,50%,70%{transform:rotate(-12deg) translateY(-4px)}20%,40%,60%,80%{transform:rotate(12deg) translateY(2px)}}
@keyframes jThumb{0%{transform:scale(.2) rotate(-60deg)}60%{transform:scale(1.3) rotate(12deg)}80%{transform:scale(.95) rotate(-4deg)}100%{transform:none}}
@keyframes jFlame{0%,100%{transform:scale(1);filter:drop-shadow(0 0 0 rgba(249,115,22,0))}25%{transform:scale(1.15,1.25) skewX(4deg);filter:drop-shadow(0 -6px 14px rgba(249,115,22,.8))}50%{transform:scale(.95,1.1) skewX(-5deg)}75%{transform:scale(1.12,1.2) skewX(3deg);filter:drop-shadow(0 -6px 16px rgba(239,68,68,.7))}}
@keyframes jParty{0%{transform:scale(.3) rotate(-40deg)}50%{transform:scale(1.4) rotate(15deg)}70%{transform:scale(.9) rotate(-8deg)}85%{transform:scale(1.08) rotate(4deg)}100%{transform:none}}
@keyframes jCry{0%,100%{transform:none}20%{transform:translateY(4px) rotate(-6deg)}40%{transform:translateY(0) rotate(6deg)}60%{transform:translateY(4px) rotate(-5deg)}80%{transform:translateY(1px) rotate(3deg)}}
@keyframes jAngry{0%,100%{transform:none;filter:none}10%,30%,50%,70%,90%{transform:translateX(-6px) scale(1.1);filter:drop-shadow(0 0 10px rgba(225,29,72,.8))}20%,40%,60%,80%{transform:translateX(6px) scale(1.1)}}
@keyframes jWow{0%{transform:scale(.4)}40%{transform:scale(1.5)}60%{transform:scale(.9)}80%{transform:scale(1.1)}100%{transform:none}}
@keyframes jPray{0%,100%{transform:none}30%{transform:translateY(-6px) scale(1.12)}50%{transform:translateY(0) scale(1)}70%{transform:translateY(-4px) scale(1.08)}}
@keyframes jClap{0%,100%{transform:none}15%,45%,75%{transform:scale(1.25) rotate(-8deg)}30%,60%,90%{transform:scale(.9) rotate(6deg)}}
@keyframes jCool{0%{transform:translateY(-30px) rotate(-20deg);opacity:0}50%{transform:translateY(4px) rotate(8deg);opacity:1}75%{transform:translateY(-3px) rotate(-3deg)}100%{transform:none}}
@keyframes jThink{0%,100%{transform:none}25%{transform:rotate(-14deg) translateX(-4px)}50%{transform:rotate(10deg)}75%{transform:rotate(-8deg) translateX(3px)}}
@keyframes jWave{0%,100%{transform:rotate(0)}15%,45%,75%{transform:rotate(22deg)}30%,60%,90%{transform:rotate(-12deg)}}
@keyframes jDrive{0%{transform:translateX(60px);opacity:0}50%{transform:translateX(-8px);opacity:1}70%{transform:translateX(4px)}100%{transform:none}}
@keyframes jBounce{0%{transform:scale(.3)}40%{transform:scale(1.3) translateY(-10px)}65%{transform:scale(.92)}85%{transform:scale(1.05)}100%{transform:none}}
/* موبایل: گفتگوی باز تمام‌صفحه با سرتیتر و نوارِ نوشتنِ جمع‌وجور؛ فاصله‌ی امن برای ناچ و نوارِ پایینِ گوشی */
.cx-mobfull{position:fixed!important;inset:0!important;width:100%!important;height:100vh!important;height:100dvh!important;min-height:0!important;z-index:9500;margin:0!important;animation:cxMobIn .28s cubic-bezier(.2,.9,.3,1)}
@keyframes cxMobIn{from{transform:translateX(-24px);opacity:.4}to{transform:none;opacity:1}}
.cx-mobfull .cx{border-radius:0;height:100%}
html.cx-mob-lock,html.cx-mob-lock body{overflow:hidden!important;overscroll-behavior:none}
html.cx-mob-lock .cf-root,html.cx-mob-lock .cf-pops{display:none!important}
@media (max-width:640px){
  .cx{font-size:13.5px}
  .cx-side-h{padding:12px 12px 6px}
  .cx-search{margin:0 12px 8px}
  .cx-chips{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;padding:0 12px 10px;-webkit-overflow-scrolling:touch}
  .cx-chips::-webkit-scrollbar{display:none}
  .cx-th{padding:10px 8px;border-radius:16px}
  .cx-mobfull .cx-head{padding-top:max(10px,env(safe-area-inset-top,0px));box-shadow:0 6px 18px -14px rgba(15,23,42,.45);position:relative;z-index:2}
  .cx-mobfull .cx-compose{padding:6px 8px max(8px,env(safe-area-inset-bottom,0px))}
  .cx-head .cx-ib{width:34px;height:34px;border-radius:11px}
  .cx-in-row{gap:5px!important}
  .cx-in-row .cx-ib{width:36px;height:36px;flex-shrink:0}
  .cx-in-row textarea{min-height:40px;font-size:14px;padding:8px 11px;border-radius:20px}
  .cx-send{width:42px;height:42px;border-radius:50%}
  .cx-body{padding-left:10px!important;padding-right:10px!important}
}
@media (max-width:820px){
  .cx-side{width:100%;border-left:0}
  .cx.cx-full.cx-conv-open .cx-side{display:none}
  .cx.cx-full:not(.cx-conv-open) .cx-main{display:none}
  .cx-back{display:inline-flex}
  .cx-msg{max-width:86%}
  .cx-msgwrap{max-width:86%}
  .cx-jumbo{font-size:50px}
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
            this.seen = new Set(); this.newBelow = 0; this.caps = {}; this.rseen = new Set(); this.unreadMap = null;
            LIVE.add(this);
            this.render();
            if (this.o.mode === 'full') { this.loadThreads(); this.listTimer = setInterval(() => !document.hidden && this.el.offsetParent && this.loadThreads(true), this.o.listMs); if (this.o.openKey) this.open(this.o.openKey); }
            else this.open(this.key);
            // فقط وقتی گفتگو روی صفحه دیده می‌شود (تب/صفحه‌ی پنهان = offsetParent تهی)
            this.pollTimer = setInterval(() => { if (this.key && !document.hidden && this.el.offsetParent) this.poll(); }, this.o.pollMs);
            document.addEventListener('click', this._docClick = e => { if (!e.target.closest('.cx-emoji,.cx-emo-btn')) this.$('.cx-emoji') && this.$('.cx-emoji').remove(); });
        }
        destroy() { clearInterval(this.pollTimer); clearInterval(this.listTimer); document.removeEventListener('click', this._docClick); this.stopRec(true); LIVE.delete(this); this.el.innerHTML = ''; }
        // همین گفتگو همین الان روی صفحه دیده می‌شود؟
        viewing(key) { return !!this.key && (!key || this.key === key) && !document.hidden && !!this.el.offsetParent; }
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
                        <button class="cx-ib cx-mute-all ${MUTE.all() ? 'cx-muted' : ''}" title="${MUTE.all() ? 'وصلِ صدای همه‌ی گفتگوها' : 'قطعِ صدای همه‌ی گفتگوها'}"><i class="fas ${MUTE.all() ? 'fa-volume-xmark' : 'fa-volume-high'}"></i></button>
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
                this.$('.cx-mute-all').onclick = () => { MUTE.toggle(null); this.paintMute(); this.toast(MUTE.all() ? 'صدای همه‌ی گفتگوها قطع شد.' : 'صدای گفتگوها وصل شد.', 'info'); };
                let t; this.$('.cx-q').oninput = e => { clearTimeout(t); t = setTimeout(() => { this.q = e.target.value.trim(); this.loadThreads(); }, 300); };
            }
        }

        // ---------------- فهرستِ گفتگوها
        async loadThreads(silent) {
            const d = await this.call('chat_threads', { q: this.q });
            if (!d.ok) { if (!silent) this.$('.cx-threads').innerHTML = `<p style="padding:20px;text-align:center;color:var(--muted)">${esc(d.error)}</p>`; return; }
            // پیامِ تازه در گفتگوهای دیگر => صدای دریافت (اگر آن گفتگو یا همه بی‌صدا نباشد)
            const prev = this.unreadMap, map = {};
            d.threads.forEach(t => { map[t.key] = t.unread || 0; });
            if (prev && d.threads.some(t => t.key !== this.key && (t.unread || 0) > (prev[t.key] || 0) && !MUTE.is(t.key))) SND.recv();
            this.unreadMap = map;
            this.threads = d.threads; this.caps = d.caps || {};
            this.renderChips(); this.renderThreads();
            const n = this.threads.reduce((a, t) => a + (t.unread || 0), 0);
            if (this.o.onUnread) this.o.onUnread(n);
        }
        renderChips() {
            const prevHtml = this._chipsSig;
            const cnt = t => this.threads.filter(x => t === 'unread' ? x.unread : x.type === t).length;
            const chips = [['all', 'همه', this.threads.length], ['unread', 'نخوانده', cnt('unread')]];
            if (this.caps.person) chips.push(['PERSON', 'کارکنان', cnt('PERSON')]);
            if (this.caps.company) chips.push(['COMPANY', 'شرکت‌ها', cnt('COMPANY')]);
            chips.push(['STAFF', 'همکاران', cnt('STAFF')]);
            const box = this.$('.cx-chips');
            const sig = JSON.stringify([chips, this.filter]); if (sig === prevHtml) return; this._chipsSig = sig;
            box.innerHTML = chips.map(([k, l, n]) => `<button class="cx-chip ${this.filter === k ? 'on' : ''}" data-f="${k}">${l}${n ? ' · ' + fa(n) : ''}</button>`).join('');
            box.querySelectorAll('[data-f]').forEach(b => b.onclick = () => { this.filter = b.dataset.f; this.renderChips(); this.renderThreads(); });
        }
        avatar(title, type, av, presence) {
            return avatarHtml(av, title, { type: type || 'STAFF', online: presence && presence.online });
        }
        renderThreads() {
            const list = this.threads.filter(t => this.filter === 'all' || (this.filter === 'unread' ? t.unread : t.type === this.filter));
            const box = this.$('.cx-threads');
            if (!list.length) { box.innerHTML = `<div style="padding:30px 10px;text-align:center;color:var(--muted)"><i class="fas fa-inbox" style="font-size:28px;opacity:.4"></i><p>گفتگویی نیست.</p></div>`; return; }
            // انیمیشنِ ورود فقط بارِ اول؛ به‌روزرسانی‌های پس‌زمینه فقط ردیف‌های عوض‌شده را بی‌صدا عوض می‌کنند
            if (!box.querySelector('[data-pk]')) { box.classList.remove('cx-settled'); setTimeout(() => box.classList.add('cx-settled'), 700); }
            patchList(box, list.map((t, i) => ['t:' + t.key, `<div class="cx-th ${t.key === this.key ? 'on' : ''}" data-k="${t.key}" style="animation-delay:${Math.min(i, 12) * 25}ms">
                ${this.avatar(t.title, t.type, t.avatar, t.presence)}
                <div class="cx-th-b"><div class="cx-th-t"><b>${t.closed ? '<i class="fas fa-lock" style="font-size:10px;color:#e11d48" title="گفتگو قطع شده"></i> ' : ''}${esc(t.title)}</b><span>${t.last_date === jdate(nowMs()) ? fa(t.last_time) : dayLabel(t.last_date)}</span></div>
                <div class="cx-th-l">${t.typing ? '<p class="cx-tp cx-typing">در حال نوشتن<i></i><i></i><i></i></p>' : `<p>${t.last_mine ? '<i class="fas fa-reply" style="font-size:9px;opacity:.6"></i> ' : ''}${esc(t.last)}</p>`}
                    <span style="display:flex;align-items:center;gap:4px"><button class="cx-mute ${MUTE.own(t.key) ? 'on' : ''}" data-mute="${t.key}" title="${MUTE.own(t.key) ? 'وصلِ صدای این گفتگو' : 'قطعِ صدای این گفتگو'}"><i class="fas ${MUTE.own(t.key) ? 'fa-bell-slash' : 'fa-bell'}"></i></button>${t.unread ? `<span class="cx-badge">${fa(t.unread)}</span>` : ''}</span></div></div></div>`]),
                n => {
                    n.onclick = e => { if (e.target.closest('[data-mute]')) return; this.open(n.dataset.k); };
                    const mb = n.querySelector('[data-mute]'); if (mb) mb.onclick = e => { e.stopPropagation(); MUTE.toggle(mb.dataset.mute); this.paintMute(); };
                    n.oncontextmenu = e => { e.preventDefault(); e.stopPropagation(); this.threadMenu(e.clientX, e.clientY, n.dataset.k); };
                });
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
            this.stopRec(true);
            this.key = key; this.msgs = []; this.sig = ''; this.seen = new Set(); this.topic = null; this.topicFilter = null; this.replyTo = null; this.editing = null; this.file = null; this.newBelow = 0;
            this.search = null; this.presence = null; this.state = null; this.selecting = false; this.selected = new Set();
            if (this.root) this.root.classList.remove('cx-selmode');
            this.root.classList.add('cx-conv-open');
            this.mobFull(true);
            if (this.o.mode === 'full') this.renderThreads();
            const main = this.$('.cx-main');
            main.innerHTML = `<div class="cx-head"><button class="cx-ib cx-back" title="بازگشت"><i class="fas fa-arrow-right"></i></button><span class="cx-skel" style="width:40px;height:40px;margin:0"></span><div class="cx-head-b"><b>...</b></div></div><div class="cx-body"><div class="cx-skel" style="width:60%"></div><div class="cx-skel" style="width:45%;margin-right:auto"></div><div class="cx-skel" style="width:55%"></div></div>`;
            const b = main.querySelector('.cx-back'); if (b) b.onclick = () => this.back();
            const d = await this.call('chat_open', { key });
            if (this.key !== key) return;
            if (!d.ok) { main.innerHTML = `<div class="cx-empty"><i class="fas fa-triangle-exclamation" style="font-size:30px;color:#f59e0b"></i><b>${esc(d.error)}</b></div>`; return; }
            this.head = d.head; this.refs = d.refs || [];
            this.presence = d.head.presence || null; this.presenceAt = Date.now(); this.state = d.state || null;
            this.renderConv();
            this.setMessages(d.messages, true);
            this.setTyping(d.typing);
            if (this.o.mode === 'full') { const t = this.threads.find(x => x.key === key); if (t && t.unread) { t.unread = 0; this.renderThreads(); this.renderChips(); if (this.o.onUnread) this.o.onUnread(this.threads.reduce((a, x) => a + (x.unread || 0), 0)); } }
        }
        back(fromPop) {
            // دکمه‌ی «بازگشت» گوشی و دکمه‌ی بازگشتِ گفتگو یکی‌اند
            if (!fromPop && this._hist) { this._hist = false; try { history.back(); } catch (e) {} }
            this.key = null; this.root.classList.remove('cx-conv-open'); this.mobFull(false); this.renderThreads();
        }
        // موبایل: گفتگوی باز تمام‌صفحه می‌شود (مثلِ پیام‌رسان‌ها) و با «بازگشت» به فهرست برمی‌گردد
        mobFull(on) {
            if (this.o.mode !== 'full' || !this.el || !this.el.parentNode) return;
            const phone = window.matchMedia && matchMedia('(max-width:640px)').matches;
            if (on && phone && !this._ph) {
                this._ph = document.createComment('cx-place');
                this.el.parentNode.insertBefore(this._ph, this.el);
                document.body.appendChild(this.el);
                this.el.classList.add('cx-mobfull');
                document.documentElement.classList.add('cx-mob-lock');
                try { history.pushState({cxConv: 1}, ''); this._hist = true; } catch (e) {}
                if (!this._popB) {
                    this._popB = () => { if (this._hist) { this._hist = false; if (this._ph) this.back(true); } };
                    window.addEventListener('popstate', this._popB);
                    // اگر صفحه‌ی گفتگوها پنهان شد (رفتن به بخشِ دیگر) یا صفحه پهن شد، تمام‌صفحه بسته می‌شود
                    window.addEventListener('resize', () => { if (this._ph && !matchMedia('(max-width:640px)').matches) this.mobFull(false); });
                }
                clearInterval(this._phT);
                this._phT = setInterval(() => {
                    if (!this._ph) return clearInterval(this._phT);
                    const host = this._ph.parentNode;
                    if (!host || !document.contains(host) || host.closest('.hidden') || !host.getClientRects().length) this.back();
                }, 800);
            } else if (!on && this._ph) {
                clearInterval(this._phT);
                if (this._ph.parentNode) this._ph.parentNode.insertBefore(this.el, this._ph);
                this._ph.remove(); this._ph = null;
                this.el.classList.remove('cx-mobfull');
                document.documentElement.classList.remove('cx-mob-lock');
            }
        }

        renderConv() {
            const h = this.head, full = this.o.mode === 'full';
            const main = this.$('.cx-main');
            main.innerHTML = `
                <div class="cx-head">${full ? '<button class="cx-ib cx-back" title="بازگشت"><i class="fas fa-arrow-right"></i></button>' : ''}
                    ${this.avatar(h.title, h.type, h.avatar, this.presence)}
                    <div class="cx-head-b"><b>${esc(h.title)}</b><span class="cx-hsub"></span></div>
                    <div class="cx-head-tools"><input class="cx-fq" placeholder="جستجو در پیام‌ها...">
                    <button class="cx-ib cx-find" title="جستجو در گفتگو (متن و تاریخ)"><i class="fas fa-magnifying-glass"></i></button>
                    <button class="cx-ib cx-hmute" title="قطع/وصلِ صدای این گفتگو"><i class="fas fa-bell"></i></button>
                    ${this.refs.length ? `<button class="cx-ib cx-refs-btn" title="درخواست‌ها"><i class="fas fa-folder-tree"></i></button>` : ''}
                    <button class="cx-ib cx-more" title="گزینه‌های گفتگو"><i class="fas fa-ellipsis-vertical"></i></button></div></div>
                <div class="cx-selslot"></div>
                <div class="cx-fdates"><div><i class="fas fa-calendar-days"></i> از <input class="cx-f-from" placeholder="۱۴۰۵/۰۷/۰۱" inputmode="numeric"> تا <input class="cx-f-to" placeholder="۱۴۰۵/۰۷/۳۰" inputmode="numeric">
                    <button class="cx-chip" data-r="0">امروز</button><button class="cx-chip" data-r="7">۷ روزِ اخیر</button><button class="cx-chip" data-r="30">۳۰ روزِ اخیر</button><button class="cx-chip" data-r="">همه</button>
                    <span class="cx-fn"></span></div></div>
                ${h.channel ? `<div class="cx-chan"><i class="fas fa-circle-info"></i> ${esc(h.channel)}</div>` : ''}
                <div class="cx-filterslot"></div>
                <div class="cx-body"></div>
                <button class="cx-fab" title="پیام‌های تازه"><i class="fas fa-chevron-down"></i></button>
                <div class="cx-compose">
                    <div class="cx-stateslot"></div>
                    <div class="cx-barslot"></div>
                    ${this.refs.length ? `<button class="cx-topic"><i class="fas fa-tag"></i><span></span><i class="fas fa-chevron-down" style="font-size:9px"></i></button>` : ''}
                    <div class="cx-in-row">
                        <button class="cx-ib cx-emo-btn" title="ایموجی"><i class="far fa-face-smile"></i></button>
                        ${window.CFCanned ? '<button class="cx-ib cx-canned-btn" title="متن‌های آماده"><i class="fas fa-bolt"></i></button>' : ''}
                        <label class="cx-ib" title="پیوستِ فایل" style="cursor:pointer"><i class="fas fa-paperclip"></i><input type="file" class="cx-file-in" hidden></label>
                        <textarea rows="1" class="cx-ta" placeholder="${window.innerWidth < 640 ? 'پیام بنویسید...' : 'پیام بنویسید... (Enter ارسال، Shift+Enter خطِ تازه)'}"></textarea>
                        ${navigator.mediaDevices && window.MediaRecorder ? '<button class="cx-ib cx-mic" title="پیامِ صوتی (برای شروع بزنید)"><i class="fas fa-microphone"></i></button>' : ''}
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
            const mic = this.$('.cx-mic'); if (mic) mic.onclick = () => (this.rec ? this.stopRec(false) : this.startRec());
            this.$('.cx-hmute').onclick = () => { MUTE.toggle(this.key); this.paintMute(); this.toast(MUTE.own(this.key) ? 'صدای این گفتگو قطع شد.' : 'صدای این گفتگو وصل شد.', 'info'); };
            this.paintMute();
            this.$('.cx-file-in').onchange = e => { if (e.target.files[0]) this.attach(e.target.files[0]); e.target.value = ''; };
            this.$('.cx-emo-btn').onclick = e => { e.stopPropagation(); this.emoji(); };
            const cn = this.$('.cx-canned-btn');
            if (cn) cn.onclick = e => { e.stopPropagation(); window.CFCanned.pick(cn, txt => { const t = this.$('.cx-ta'); const pos = t.selectionStart ?? t.value.length; t.value = t.value.slice(0, pos) + txt + t.value.slice(t.selectionEnd ?? pos); t.focus(); t.dispatchEvent(new Event('input')); }); };
            this.bindFind();
            // منوی خودِ گفتگو: ⋮، کلیک‌راست روی سرِ گفتگو یا جای خالیِ گفتگو
            const more = this.$('.cx-more'); more.onclick = e => { e.stopPropagation(); const r = more.getBoundingClientRect(); this.threadMenu(r.left + r.width, r.bottom + 4, this.key); };
            this.$('.cx-head').oncontextmenu = e => { if (e.target.closest('input')) return; e.preventDefault(); e.stopPropagation(); this.threadMenu(e.clientX, e.clientY, this.key); };
            body.oncontextmenu = e => { if (e.target.closest('.cx-msg')) return; e.preventDefault(); e.stopPropagation(); this.threadMenu(e.clientX, e.clientY, this.key); };
            main.onkeydown = e => { if (e.key === 'Escape' && this.selecting) this.stopSelect(); };
            const tb = this.$('.cx-topic'); if (tb) tb.onclick = () => this.openRefs('pick');
            const rb = this.$('.cx-refs-btn'); if (rb) rb.onclick = () => this.openRefs();
            // کشیدن و رها کردنِ فایل
            let dc = 0; const drop = this.$('.cx-drop');
            main.ondragenter = e => { if ([...(e.dataTransfer || {}).types || []].includes('Files')) { dc++; drop.classList.add('show'); } };
            main.ondragleave = () => { if (--dc <= 0) { dc = 0; drop.classList.remove('show'); } };
            main.ondragover = e => e.preventDefault();
            main.ondrop = e => { e.preventDefault(); dc = 0; drop.classList.remove('show'); const f = (e.dataTransfer.files || [])[0]; if (f) this.attach(f); };
            this.renderTopic(); this.renderBar(); this.renderFilter(); this.renderSub(); this.renderState();
            if (this.refs.length) this.bindRefs();
            setTimeout(() => ta.focus(), 60);
        }

        // ---------------- پیام‌ها
        setMessages(list, first) {
            const sig = JSON.stringify(list.map(m => [m.id, m.text, m.edited, m.deleted, m.read, m.ref && m.ref.id, m.file && m.file.url, m.reactions]));
            if (sig === this.sig) return;
            const wasBottom = first || this.nearBottom();
            const fresh = list.filter(m => !this.seen.has(m.id));
            if (first) list.forEach(m => (m.reactions || []).forEach(r => this.rseen.add(m.id + ':' + r.e)));
            if (!first && fresh.some(m => !m.mine) && !MUTE.is(this.key)) SND.recv();
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
            const blocks = []; let lastDay = '';
            list.forEach((m, i) => {
                if (m.date !== lastDay) { blocks.push(['d:' + m.date, `<div class="cx-day"><span>${dayLabel(m.date)}</span></div>`]); lastDay = m.date; }
                blocks.push(['m:' + m.id, this.msgHtml(m, grp[i], false, !grp[i + 1]), !first && !this.seen.has(m.id)]);
            });
            // فقط پیام‌هایی که واقعاً عوض شده‌اند دوباره ساخته می‌شوند؛ بقیه دست نمی‌خورند (بدونِ چشمک زدن)
            patchList(body, blocks, (node, k, isNew) => {
                if (k[0] !== 'm') return;
                if (isNew) { const mm = node.querySelector('.cx-msg'); if (mm) mm.classList.add(node.querySelector('.cx-row.me') || node.classList.contains('me') ? 'cx-sent' : 'cx-recv'); }
                this.bindRow(node);
            });
            if (!body.querySelector(':scope > .cx-typing-bubble')) body.insertAdjacentHTML('beforeend', '<div class="cx-typing-bubble"><i></i><i></i><i></i></div>');
            else body.appendChild(body.querySelector(':scope > .cx-typing-bubble'));
            this.paintSelection();
            this.setTyping(this._typing);
        }
        // رویدادهای یک پیام (کلیک‌راست، لمسِ طولانی، انتخاب، پاسخ، عکس)
        bindRow(row) {
            const el = row.querySelector('.cx-msg'); if (!el) return;
            const id = Number(el.dataset.id), m = () => this.msgs.find(x => x.id === id);
            el.oncontextmenu = e => { e.preventDefault(); e.stopPropagation(); if (m()) this.menu(e.clientX, e.clientY, m()); };
            // موبایل: یک لمس => منوی پیام؛ کشیدن به چپ یا راست => پاسخ
            let sx = 0, sy = 0, st = 0, dx = 0, swiping = false, ic = null;
            el.ontouchstart = e => { const t = e.touches[0]; sx = t.clientX; sy = t.clientY; st = Date.now(); dx = 0; swiping = false; };
            el.ontouchmove = e => {
                const t = e.touches[0], mx = t.clientX - sx, my = t.clientY - sy;
                if (!swiping && Math.abs(mx) > 12 && Math.abs(mx) > Math.abs(my) * 1.4 && !this.selecting && m() && !m().deleted && this.canSend()) {
                    swiping = true; ic = document.createElement('span'); ic.className = 'cx-swipe-ic'; ic.innerHTML = '<i class="fas fa-reply"></i>';
                    ic.style[mx > 0 ? 'left' : 'right'] = '-38px'; el.style.position = 'relative'; el.appendChild(ic);
                }
                if (!swiping) return;
                e.preventDefault();
                dx = Math.max(-90, Math.min(90, mx));
                el.style.transition = 'none'; el.style.transform = `translateX(${dx}px)`;
                const k = Math.min(1, Math.abs(dx) / 60); ic.style.opacity = k; ic.style.transform = `scale(${0.4 + k * 0.6})`;
            };
            el.ontouchend = e => {
                if (swiping) {
                    el.style.transition = 'transform .25s cubic-bezier(.2,.9,.3,1.3)'; el.style.transform = '';
                    if (ic) { const x = ic; setTimeout(() => x.remove(), 250); ic = null; }
                    if (Math.abs(dx) >= 55 && m()) { if (navigator.vibrate) navigator.vibrate(12); this.reply(m()); }
                    swiping = false; return;
                }
                const tt = e.changedTouches[0];
                if (Date.now() - st < 450 && Math.abs(tt.clientX - sx) < 10 && Math.abs(tt.clientY - sy) < 10 && !this.selecting
                    && !e.target.closest('a,img,audio,button,.cx-reply,.cx-ref,.cx-jumbo,.cx-voice')) { e.preventDefault(); if (m()) this.menu(tt.clientX, tt.clientY, m()); }
            };
            const wrap = row.querySelector('.cx-msgwrap');
            if (wrap) wrap.querySelectorAll('[data-re]').forEach(b => b.onclick = e => { e.stopPropagation(); const r = (m().reactions || []).find(x => x.e === b.dataset.re); this.react(m(), r && r.mine ? '' : b.dataset.re); });
            const jb = el.querySelector('.cx-jumbo');
            if (jb) {
                const play = () => { jb.classList.remove('a-' + jb.dataset.anim); void jb.offsetWidth; jb.classList.add('a-' + jb.dataset.anim); };
                jb.onclick = e => { e.stopPropagation(); play(); };
                const jid = (this.key || '') + ':' + id;
                if (!JUMBO_SEEN.has(jid)) { JUMBO_SEEN.add(jid); setTimeout(play, 60); }
            }
            const vc = el.querySelector('.cx-voice'); if (vc) this.bindVoice(vc);
            row.onclick = e => { if (this.selecting) { e.preventDefault(); e.stopPropagation(); this.toggleSel(id); } };
            const dots = el.querySelector('.cx-dots'); if (dots) dots.onclick = e => { e.stopPropagation(); const r = dots.getBoundingClientRect(); if (m()) this.menu(r.left, r.bottom, m()); };
            const rp = el.querySelector('.cx-reply'); if (rp) rp.onclick = e => { if (this.selecting) return; this.jump(Number(rp.dataset.to)); };
            const rf = el.querySelector('.cx-ref'); if (rf) rf.onclick = e => { if (this.selecting) return; this.setFilter(m().ref); };
            el.querySelectorAll('.cx-img').forEach(img => {
                img.onclick = e => { if (this.selecting) return; this.lightbox(img.src); };
                // عکس بعد از چیدمان بار می‌شود و ارتفاع را زیاد می‌کند؛ اگر پایینِ گفتگو بودیم همان‌جا بمانیم
                if (!img.complete) img.onload = () => { if (this.stick !== false) this.scrollBottom(); };
            });
            el.ondblclick = () => { const x = m(); if (x && !x.deleted && !this.selecting) this.reply(x); };
        }
        msgHtml(m, grouped, isNew, lastOfGroup) {
            const side = m.mine ? 'me' : 'them';
            // عکسِ فرستنده کنارِ آخرین پیامِ هر دسته (فقط پیام‌های طرفِ مقابل)
            const mav = m.mine ? '' : `<span class="cx-mav">${lastOfGroup ? avatarHtml(m.avatar, m.sender, {}) : ''}</span>`;
            if (m.deleted) return `<div class="cx-row ${side} ${grouped ? '' : 'gap'}"><div class="cx-msg cx-del ${isNew ? 'cx-in' : ''}" data-id="${m.id}"><i class="fas fa-ban"></i> این پیام حذف شد · ${fa(m.time)}</div>${mav}</div>`;
            const f = m.file;
            const voice = f && f.audio && /^voice-/.test(f.name || '');
            const file = !f ? '' : f.image ? `<img class="cx-img" src="${esc(f.url)}" alt="">`
                : voice ? `<div class="cx-voice" data-src="${esc(f.url)}"><button class="pl" title="پخش"><i class="fas fa-play"></i></button><span class="bars">${this.bars(m.id)}</span><small>🎤</small></div>`
                : f.audio ? `<audio class="cx-audio" controls preload="none" src="${esc(f.url)}"></audio>`
                : `<a class="cx-file" href="${esc(f.url)}" target="_blank" download><i class="fas ${/pdf/.test(f.ext) ? 'fa-file-pdf' : /docx?/.test(f.ext) ? 'fa-file-word' : /xlsx?/.test(f.ext) ? 'fa-file-excel' : /zip|rar/.test(f.ext) ? 'fa-file-zipper' : 'fa-file'}"></i><span><b>${esc(f.name)}</b><small>${esc(f.ext.toUpperCase())} · دانلود</small></span></a>`;
            const showSender = !m.mine || this.o.mode === 'full' && this.head && this.head.type !== 'STAFF';
            const jumbo = !f && !m.reply && isJumbo(m.text);
            const reacts = (m.reactions || []).length ? `<div class="cx-reacts">${m.reactions.map(r => `<button class="cx-rc ${r.mine ? 'mine' : ''} ${this.rseen.has(m.id + ':' + r.e) ? '' : 'pop'}" data-re="${esc(r.e)}" title="${r.mine ? 'برداشتنِ واکنشِ من' : 'همین واکنش'}">${r.e}${r.n > 1 ? `<b>${fa(r.n)}</b>` : ''}</button>`).join('')}</div>` : '';
            (m.reactions || []).forEach(r => this.rseen.add(m.id + ':' + r.e));
            return `<div class="cx-row ${side} ${grouped ? '' : 'gap'}"><div class="cx-msgwrap"><div class="cx-msg ${jumbo ? 'cx-jmb' : ''} ${isNew ? 'cx-in' : ''}" data-id="${m.id}" style="--h:${hue(m.sender)}">
                <button class="cx-dots" title="گزینه‌ها"><i class="fas fa-ellipsis-vertical"></i></button>
                ${!grouped && showSender ? `<div class="cx-sender">${esc(m.mine ? (this.o.mode === 'full' ? m.sender : 'شما') : m.sender)}</div>` : ''}
                ${m.ref ? `<span class="cx-ref" title="نمایشِ همه‌ی پیام‌های این موضوع"><i class="fas ${REF_ICON[m.ref.type] || 'fa-tag'}"></i><span>${esc(fa(m.ref.label))}</span></span>` : ''}
                ${m.reply ? `<span class="cx-reply" data-to="${m.reply.id}"><b>${esc(m.reply.sender)}</b><span>${esc(m.reply.text)}</span></span>` : ''}
                ${file}${jumbo ? `<div><span class="cx-jumbo" data-anim="${JUMBO_ANIM(m.text.trim())}">${esc(m.text.trim())}</span></div>` : m.text ? `<div>${this.searchActive() && this.search.q ? highlight(m.text, this.search.q) : linkify(m.text)}</div>` : ''}
                <div class="cx-meta">${m.mine ? `<i class="fas ${m.read ? 'fa-check-double cx-seen' : 'fa-check'}" title="${m.read ? 'دیده شد' : 'ارسال شد'}"></i>` : ''}<span>${fa(m.time)}</span>${m.edited ? '<span>ویرایش‌شده</span>' : ''}${this.searchActive() ? `<span>${fa(m.date)}</span>` : ''}</div>
            </div>${reacts}</div>${mav}</div>`;
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
            if (d.state !== undefined) { this.state = d.state; this.renderState(); }
            this.setMessages(d.messages); this.setTyping(d.typing);
        }
        // زیرِ نامِ طرفِ مقابل: «در حال نوشتن...» یا «آنلاین / آخرین بازدید» و مشخصات
        renderSub() {
            const s = this.$('.cx-hsub'); if (!s || !this.head) return;
            if (this._typing) { if (!s.querySelector('.cx-typing')) s.innerHTML = '<span class="cx-typing"><i class="fas fa-pen" style="font-size:9px;width:auto;height:auto;background:none;animation:jThink 1.2s infinite"></i> در حال نوشتن<i></i><i></i><i></i></span>'; return; }
            const group = this.head.type !== 'STAFF' && this.o.mode === 'single';
            const lbl = seenLabel(this.presence, this.presenceAt, group);
            const sub = lbl && group ? '' : fa(this.head.sub || '');
            const h = (lbl ? `<span class="cx-pr ${this.presence && this.presence.online ? 'on' : ''}">${esc(lbl)}</span>` : '') + (lbl && sub ? ' · ' : '') + esc(sub);
            if (s.innerHTML !== h) s.innerHTML = h;
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
            if (!d.ok) { if (d.closed) this.poll(); return this.toast(d.error || 'ارسال نشد.', 'error'); }
            ta.value = ''; ta.style.height = ''; this.replyTo = null; this.file = null; if (this._fileUrl) URL.revokeObjectURL(this._fileUrl); this._fileUrl = null;
            if (!MUTE.is(this.key)) SND.send();
            btn.classList.remove('cx-fly'); void btn.offsetWidth; btn.classList.add('cx-fly');
            this.renderBar(); ta.focus();
            await this.poll(); this.scrollBottom(true);
            if (this.o.mode === 'full') this.loadThreads(true);
        }
        reply(m) { this.editing = null; this.replyTo = m; this.renderBar(); this.$('.cx-ta').focus(); }
        paintMute() {
            const all = MUTE.all();
            const ma = this.$('.cx-mute-all');
            if (ma) { ma.classList.toggle('cx-muted', all); ma.innerHTML = `<i class="fas ${all ? 'fa-volume-xmark' : 'fa-volume-high'}"></i>`; ma.title = all ? 'وصلِ صدای همه‌ی گفتگوها' : 'قطعِ صدای همه‌ی گفتگوها'; }
            const hm = this.$('.cx-hmute');
            if (hm) { const on = MUTE.own(this.key); hm.classList.toggle('cx-muted', on || all); hm.innerHTML = `<i class="fas ${on || all ? 'fa-bell-slash' : 'fa-bell'}"></i>`; hm.title = on ? 'وصلِ صدای این گفتگو' : (all ? 'صدای همه‌ی گفتگوها قطع است' : 'قطعِ صدای این گفتگو'); }
            this.el.querySelectorAll('[data-mute]').forEach(b => { const on = MUTE.own(b.dataset.mute); b.classList.toggle('on', on); b.innerHTML = `<i class="fas ${on ? 'fa-bell-slash' : 'fa-bell'}"></i>`; });
        }
        // نوارهای موجِ پیامِ صوتی (ثابت برای هر پیام)
        bars(id) { let x = Number(id) * 9301 + 49297, h = ''; for (let i = 0; i < 28; i++) { x = (x * 9301 + 49297) % 233280; h += `<i style="height:${30 + Math.round(x / 233280 * 70)}%"></i>`; } return h; }
        bindVoice(box) {
            const btn = box.querySelector('.pl'), bars = [...box.querySelectorAll('.bars i')], lbl = box.querySelector('small');
            let a = null;
            const fmt = s => fa(Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0'));
            const paint = () => { const k = a && a.duration ? a.currentTime / a.duration : 0; bars.forEach((b, i) => b.classList.toggle('on', i / bars.length < k)); if (a && isFinite(a.duration)) lbl.textContent = fmt(a.paused && !a.currentTime ? a.duration : a.currentTime); };
            const ensure = () => {
                if (a) return a;
                a = new Audio(box.dataset.src); a.preload = 'metadata';
                a.ontimeupdate = paint; a.onloadedmetadata = paint;
                a.onended = () => { btn.innerHTML = '<i class="fas fa-play"></i>'; a.currentTime = 0; paint(); };
                return a;
            };
            btn.onclick = e => {
                e.stopPropagation(); ensure();
                document.querySelectorAll('.cx-voice').forEach(v => { if (v !== box && v._a && !v._a.paused) { v._a.pause(); v.querySelector('.pl').innerHTML = '<i class="fas fa-play"></i>'; } });
                box._a = a;
                if (a.paused) { a.play().catch(() => this.toast('پخش نشد.', 'error')); btn.innerHTML = '<i class="fas fa-pause"></i>'; } else { a.pause(); btn.innerHTML = '<i class="fas fa-play"></i>'; }
            };
            box.querySelector('.bars').onclick = e => { e.stopPropagation(); ensure(); const r = e.currentTarget.getBoundingClientRect(); const k = (r.right - e.clientX) / r.width; if (isFinite(a.duration)) a.currentTime = a.duration * Math.max(0, Math.min(1, k)); else a.addEventListener('loadedmetadata', () => { a.currentTime = a.duration * k; }, { once: true }); };
        }
        // ---------------- پیامِ صوتی: ضبط با MediaRecorder و ارسال مثلِ فایل
        async startRec() {
            if (!this.canSend()) return;
            let stream;
            try { stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }); }
            catch (e) { return this.toast('دسترسی به میکروفون داده نشد (از تنظیماتِ مرورگر اجازه دهید).', 'error'); }
            const types = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm'];
            const mime = types.find(t => MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) || '';
            const mr = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
            const R = this.rec = { mr, stream, chunks: [], t0: Date.now(), mime: mr.mimeType || mime || 'audio/webm' };
            mr.ondataavailable = e => { if (e.data && e.data.size) R.chunks.push(e.data); };
            mr.start(250);
            // نمایشِ زنده‌ی صدا
            try {
                const c = SND.ac(); const an = c.createAnalyser(); an.fftSize = 64; c.createMediaStreamSource(stream).connect(an); R.an = an;
            } catch (e) {}
            const slot = this.$('.cx-barslot');
            const box = document.createElement('div'); box.className = 'cx-rec';
            box.innerHTML = `<button class="x" title="لغو"><i class="fas fa-trash"></i></button><span class="dot"></span><b>۰:۰۰</b><span class="wv"></span><button class="ok" title="ارسال"><i class="fas fa-paper-plane"></i></button>`;
            slot.prepend(box); R.box = box;
            box.querySelector('.x').onclick = () => this.stopRec(true);
            box.querySelector('.ok').onclick = () => this.stopRec(false);
            const mic = this.$('.cx-mic'); if (mic) { mic.classList.add('on'); mic.innerHTML = '<i class="fas fa-stop"></i>'; mic.title = 'پایان و ارسال'; }
            const wv = box.querySelector('.wv'), lbl = box.querySelector('b'), buf = new Uint8Array(32);
            R.tick = setInterval(() => {
                const s = (Date.now() - R.t0) / 1000;
                lbl.textContent = fa(Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0'));
                let lv = 0.15; if (R.an) { R.an.getByteFrequencyData(buf); lv = Math.min(1, buf.reduce((a, b) => a + b, 0) / buf.length / 110); }
                const i = document.createElement('i'); i.style.height = Math.max(12, lv * 100) + '%'; wv.appendChild(i);
                while (wv.children.length > 60) wv.firstChild.remove();
                if (s > 300) this.stopRec(false);   // حداکثر ۵ دقیقه
            }, 120);
        }
        stopRec(cancel) {
            const R = this.rec; if (!R) return;
            this.rec = null;
            clearInterval(R.tick);
            if (R.box) R.box.remove();
            const mic = this.$('.cx-mic'); if (mic) { mic.classList.remove('on'); mic.innerHTML = '<i class="fas fa-microphone"></i>'; mic.title = 'پیامِ صوتی (برای شروع بزنید)'; }
            const finish = () => {
                R.stream.getTracks().forEach(t => t.stop());
                if (cancel) return;
                const secs = (Date.now() - R.t0) / 1000;
                if (secs < 0.8 || !R.chunks.length) return this.toast('پیامِ صوتی خیلی کوتاه بود.', 'warning');
                const ext = /mp4/.test(R.mime) ? 'm4a' : /ogg/.test(R.mime) ? 'ogg' : 'webm';
                const blob = new Blob(R.chunks, { type: R.mime.split(';')[0] });
                this.file = new File([blob], `voice-${Date.now()}.${ext}`, { type: blob.type });
                this.send();
            };
            if (R.mr.state !== 'inactive') { R.mr.onstop = finish; try { R.mr.stop(); } catch (e) { finish(); } } else finish();
        }
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
                from.value = r === '' ? '' : fa(jdate(nowMs() - Math.max(0, Number(r) - 1) * 864e5));
                to.value = r === '' ? '' : fa(jdate(nowMs()));
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
        // منوی شناور (کلیک‌راست / لمسِ طولانی / ⋮). items: [آیکن، عنوان، کار، 'danger'|'disabled'] یا null (خطِ جداکننده)
        popMenu(x, y, title, items, reactMsg) {
            document.querySelectorAll('.cx-menu').forEach(e => e.remove());
            if (!items.length) return;
            const el = document.createElement('div');
            el.className = 'cx-menu' + (this.o.theme === 'dark' ? ' dark' : '');
            const myR = reactMsg ? ((reactMsg.reactions || []).find(r => r.mine) || {}).e : null;
            el.innerHTML = (reactMsg ? `<div class="cx-react">${REACTS.map(e => `<button data-r="${e}" style="${e === myR ? 'background:rgba(99,102,241,.18);border-radius:10px' : ''}" title="${e === myR ? 'برداشتنِ واکنش' : 'واکنش'}">${e}</button>`).join('')}</div>` : '')
                + (title ? `<div class="cx-mh">${esc(title)}</div>` : '') + items.map((it, i) => it
                ? `<button data-i="${i}" class="${it[3] || ''}" ${it[3] === 'disabled' ? 'disabled title="' + esc(it[4] || '') + '"' : ''}><i class="fas ${it[0]}"></i>${it[1]}</button>` : '<hr>').join('');
            document.body.appendChild(el);
            // offsetWidth/Height (نه getBoundingClientRect) چون انیمیشنِ بازشدن اندازه را کوچک نشان می‌دهد
            const w = el.offsetWidth, h = el.offsetHeight;
            el.style.left = Math.max(8, Math.min(x - w, window.innerWidth - w - 8)) + 'px';
            el.style.top = Math.max(8, Math.min(y, window.innerHeight - h - 8)) + 'px';
            const close = e => { if (!e || !el.contains(e.target)) { el.remove(); document.removeEventListener('mousedown', close, true); document.removeEventListener('scroll', close, true); document.removeEventListener('keydown', key, true); } };
            const key = e => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
            el.querySelectorAll('[data-i]').forEach(b => b.onclick = () => { const it = items[Number(b.dataset.i)]; if (it[3] === 'disabled') return; close(); it[2](); });
            el.querySelectorAll('[data-r]').forEach(b => { b.style.transition = 'transform .15s'; b.onmouseenter = () => { b.style.transform = 'scale(1.35) translateY(-3px)'; }; b.onmouseleave = () => { b.style.transform = ''; };
                b.onclick = () => { close(); this.react(reactMsg, b.dataset.r === myR ? '' : b.dataset.r); }; });
            setTimeout(() => { document.addEventListener('mousedown', close, true); document.addEventListener('scroll', close, true); document.addEventListener('keydown', key, true); }, 0);
        }
        menu(x, y, m) {
            if (this.selecting) return this.selMenu(x, y, m);
            const staffThread = this.o.mode === 'full' && this.head && this.head.type !== 'STAFF';
            const canRef = this.refs.length && !m.deleted && (staffThread || m.can_modify);
            const items = [];
            if (!m.deleted && this.canSend()) items.push(['fa-reply', 'پاسخ', () => this.reply(m)]);
            if (m.text) items.push(['fa-copy', 'کپیِ متن', () => this.copy(m.text)]);
            if (m.file) items.push(['fa-download', 'دانلودِ فایل', () => { const a = document.createElement('a'); a.href = m.file.url; a.download = m.file.name; a.target = '_blank'; document.body.appendChild(a); a.click(); a.remove(); }]);
            if (m.reply) items.push(['fa-arrow-turn-up', 'رفتن به پیامِ اصلی', () => this.jump(m.reply.id)]);
            if (this.searchActive() || this.topicFilter) items.push(['fa-location-crosshairs', 'نمایشِ این پیام در گفتگو', () => this.jump(m.id)]);
            if (canRef) items.push(['fa-tag', m.ref ? 'تغییرِ موضوعِ پیام' : 'تعیینِ موضوعِ پیام (درخواست)', () => this.openRefs('message', m)]);
            if (m.ref) items.push(['fa-filter', 'همه‌ی پیام‌های همین موضوع', () => this.setFilter(m.ref)]);
            items.push(['fa-square-check', 'انتخاب (چند پیام)', () => this.startSelect(m.id)]);
            items.push(null);
            if (m.can_modify && m.text !== null) items.push(['fa-pen', 'ویرایش', () => this.edit(m)]);
            items.push(['fa-trash', 'حذف', () => this.del([m]), 'danger']);
            this.popMenu(x, y, (m.mine ? 'پیامِ شما' : m.sender) + ' · ' + fa(m.time), items, m.deleted ? null : m);
        }
        // واکنش (ایموجی) روی پیام: انتخاب، عوض کردن یا برداشتن ('' = برداشتن)
        async react(m, emoji) {
            if (!m) return;
            const d = await this.call('chat_react', { id: m.id, emoji });
            if (!d.ok) return this.toast(d.error || 'ثبت نشد.', 'error');
            if (emoji) this.rseen.delete(m.id + ':' + emoji);
            this.sig = ''; this.poll();
        }
        copy(text) {
            try { navigator.clipboard.writeText(text); this.toast('کپی شد.', 'success'); }
            catch (e) { this.toast('کپی ممکن نشد.', 'error'); }
        }

        // ---------------- حذف: برای هر دو طرف یا فقط برای من (یک یا چند پیام)
        async del(list) {
            list = list.filter(Boolean);
            if (!list.length) return;
            const canBoth = list.filter(m => m.can_modify && !m.deleted);
            const n = list.length, many = n > 1;
            const choices = [];
            if (canBoth.length) choices.push({ value: 'both', danger: true, label: 'حذف برای هر دو طرف',
                note: canBoth.length < n ? `فقط ${fa(canBoth.length)} پیامِ خودتان برای طرفِ مقابل هم حذف می‌شود؛ بقیه فقط برای شما.` : 'طرفِ مقابل به‌جایش «این پیام حذف شد» می‌بیند.' });
            choices.push({ value: 'me', label: 'فقط برای من', note: 'فقط از صفحه‌ی شما پاک می‌شود؛ طرفِ مقابل همچنان می‌بیند.' });
            const v = await dialog({ title: many ? `حذفِ ${fa(n)} پیام` : 'حذفِ پیام', message: many ? 'پیام‌های انتخاب‌شده چطور حذف شوند؟' : 'این پیام چطور حذف شود؟', choices, theme: this.o.theme, danger: true });
            if (!v) return;
            let d;
            if (v === 'both') {
                d = await this.call('chat_delete', { ids: canBoth.map(m => m.id) });
                const rest = list.filter(m => !canBoth.includes(m));
                if (d.ok && rest.length) d = await this.call('chat_hide', { ids: rest.map(m => m.id) });
            } else d = await this.call('chat_hide', { ids: list.map(m => m.id) });
            if (!d.ok) return this.toast(d.error || 'حذف نشد.', 'error');
            this.stopSelect(); this.sig = ''; await this.poll();
            if (this.o.mode === 'full') this.loadThreads(true);
        }

        // ---------------- انتخابِ چند پیام
        startSelect(id) {
            this.selecting = true; this.selected = new Set(id ? [id] : []);
            this.root.classList.add('cx-selmode'); this.renderSelBar(); this.paintSelection();
        }
        stopSelect() {
            if (!this.selecting) return;
            this.selecting = false; this.selected = new Set();
            this.root.classList.remove('cx-selmode'); this.renderSelBar(); this.paintSelection();
        }
        toggleSel(id) {
            if (this.selected.has(id)) this.selected.delete(id); else this.selected.add(id);
            this.renderSelBar(); this.paintSelection();
        }
        paintSelection() {
            const body = this.$('.cx-body'); if (!body) return;
            body.querySelectorAll('[data-pk^="m:"]').forEach(r => r.classList.toggle('cx-sel', !!this.selecting && this.selected.has(Number(r.dataset.pk.slice(2)))));
        }
        selList() { return this.msgs.filter(m => this.selected.has(m.id)); }
        renderSelBar() {
            const slot = this.$('.cx-selslot'); if (!slot) return;
            if (!this.selecting) { slot.innerHTML = ''; return; }
            const n = this.selected.size;
            slot.innerHTML = `<div class="cx-selbar"><button class="cx-ib" data-a="x" title="لغوِ انتخاب"><i class="fas fa-xmark"></i></button>
                <b>${n ? fa(n) + ' پیام انتخاب شد' : 'روی پیام‌ها بزنید تا انتخاب شوند'}</b>
                <button class="cx-selb" data-a="all"><i class="fas fa-check-double"></i> همه</button>
                <button class="cx-selb" data-a="copy" ${n ? '' : 'disabled'}><i class="fas fa-copy"></i> کپی</button>
                <button class="cx-selb" disabled title="در انتخابِ چندتایی ویرایش غیرفعال است"><i class="fas fa-pen"></i> ویرایش</button>
                <button class="cx-selb danger" data-a="del" ${n ? '' : 'disabled'}><i class="fas fa-trash"></i> حذف</button></div>`;
            slot.querySelectorAll('[data-a]').forEach(b => b.onclick = () => {
                const a = b.dataset.a;
                if (a === 'x') this.stopSelect();
                if (a === 'all') { this.msgs.forEach(m => this.selected.add(m.id)); this.renderSelBar(); this.paintSelection(); }
                if (a === 'copy') this.copySelected();
                if (a === 'del') this.del(this.selList());
            });
        }
        copySelected() {
            const t = this.selList().filter(m => m.text).map(m => `${m.mine ? 'شما' : m.sender} (${fa(m.date)} ${fa(m.time)}):\n${m.text}`).join('\n\n');
            if (t) this.copy(t);
        }
        selMenu(x, y, m) {
            const on = this.selected.has(m.id), n = this.selected.size;
            this.popMenu(x, y, n ? fa(n) + ' پیام انتخاب شده' : 'انتخابِ چندتایی', [
                ['fa-square-check', on ? 'برداشتنِ انتخاب' : 'انتخابِ این پیام', () => this.toggleSel(m.id)],
                ['fa-copy', 'کپیِ پیام‌های انتخاب‌شده', () => this.copySelected(), n ? '' : 'disabled'],
                ['fa-pen', 'ویرایش', null, 'disabled', 'در انتخابِ چندتایی ویرایش غیرفعال است'],
                null,
                ['fa-trash', `حذفِ ${n ? fa(n) + ' ' : ''}پیام`, () => this.del(this.selList()), n ? 'danger' : 'disabled'],
                ['fa-xmark', 'لغوِ انتخاب', () => this.stopSelect()],
            ]);
        }

        // ---------------- منوی خودِ گفتگو (کلیک‌راست روی فهرست، سرِ گفتگو یا زمینه‌ی گفتگو)
        convInfo(key) {
            const t = this.threads.find(x => x.key === key) || {};
            const type = String(key || '').charAt(0);
            const st = key === this.key && this.state ? this.state : null;
            return { key, title: t.title || (this.head && key === this.key ? this.head.title : ''), closed: st ? st.mode : (t.closed || null),
                     canClose: st ? st.can_close : this.o.mode === 'full', canReopen: st ? !!st.can_reopen : this.o.mode === 'full',
                     canBoth: st ? st.can_clear_both : (this.o.mode === 'full' && (type === 'S' || !!this.caps.admin)) };
        }
        threadMenu(x, y, key) {
            const c = this.convInfo(key), open = key === this.key, items = [];
            if (!open) items.push(['fa-comments', 'باز کردنِ گفتگو', () => this.open(key)]);
            if (open) {
                items.push(['fa-magnifying-glass', 'جستجو در گفتگو', () => this.$('.cx-find') && this.$('.cx-find').click()]);
                items.push(['fa-square-check', 'انتخابِ پیام‌ها', () => this.startSelect()]);
            }
            items.push([MUTE.own(key) ? 'fa-bell' : 'fa-bell-slash', MUTE.own(key) ? 'وصلِ صدای این گفتگو' : 'قطعِ صدای این گفتگو', () => { MUTE.toggle(key); this.paintMute(); }]);
            items.push(null);
            items.push(['fa-broom', 'پاک کردنِ تاریخچه‌ی گفتگو', () => this.clearHistory(key), 'danger']);
            if (c.canClose) {
                if (c.closed) items.push(['fa-link', 'وصل کردنِ دوباره‌ی گفتگو', () => this.reopen(key), c.canReopen ? '' : 'disabled', 'فقط کسی که قطع کرده (یا مدیر کل)']);
                else items.push(['fa-ban', 'قطعِ گفتگو', () => this.closeConv(key), 'danger']);
            }
            this.popMenu(x, y, c.title || 'گفتگو', items);
        }
        async clearHistory(key) {
            const c = this.convInfo(key);
            const choices = [{ value: 'me', label: 'فقط برای من', note: 'پیام‌ها فقط از صفحه‌ی شما پاک می‌شوند.' }];
            if (c.canBoth) choices.unshift({ value: 'both', danger: true, label: 'برای هر دو طرف', note: 'همه‌ی پیام‌های تا این لحظه برای هر دو طرف پاک می‌شوند.' });
            const v = await dialog({ title: 'پاک کردنِ تاریخچه', message: `تاریخچه‌ی گفتگو${c.title ? ' با «' + c.title + '»' : ''} پاک شود؟`, choices, danger: true, icon: 'fa-broom', theme: this.o.theme });
            if (!v) return;
            const d = await this.call('chat_clear', { key, scope: v });
            if (!d.ok) return this.toast(d.error || 'انجام نشد.', 'error');
            this.toast('تاریخچه پاک شد.', 'success');
            if (key === this.key) { this.sig = ''; this.stopSelect(); await this.poll(); }
            if (this.o.mode === 'full') this.loadThreads(true);
        }
        async closeConv(key) {
            const c = this.convInfo(key);
            const v = await dialog({ title: 'قطعِ گفتگو', message: `گفتگو${c.title ? ' با «' + c.title + '»' : ''} چطور قطع شود؟ هر وقت خواستید می‌توانید دوباره وصلش کنید.`, icon: 'fa-ban', danger: true, theme: this.o.theme,
                choices: [{ value: 'ONE', label: 'یک‌طرفه', note: 'طرفِ مقابل دیگر نمی‌تواند پیام بفرستد؛ شما می‌توانید.' },
                          { value: 'BOTH', danger: true, label: 'دوطرفه', note: 'هیچ‌کدام از دو طرف نمی‌توانند پیام بفرستند.' }] });
            if (!v) return;
            const d = await this.call('chat_close', { key, mode: v });
            if (!d.ok) return this.toast(d.error || 'انجام نشد.', 'error');
            this.toast('گفتگو قطع شد.', 'success');
            if (key === this.key) await this.poll();
            if (this.o.mode === 'full') this.loadThreads(true);
        }
        async reopen(key) {
            const d = await this.call('chat_close', { key, mode: 'OPEN' });
            if (!d.ok) return this.toast(d.error || 'انجام نشد.', 'error');
            this.toast('گفتگو دوباره وصل شد.', 'success');
            if (key === this.key) await this.poll();
            if (this.o.mode === 'full') this.loadThreads(true);
        }
        // وضعیتِ قطع: نوارِ جای کادرِ نوشتن (یا نوارِ باریک اگر خودم یک‌طرفه قطع کرده‌ام)
        canSend() { return !this.state || this.state.can_send !== false; }
        renderState() {
            const slot = this.$('.cx-stateslot'), st = this.state; if (!slot) return;
            const sig = JSON.stringify(st || null); if (slot._sig === sig) return; slot._sig = sig;
            const blocked = st && !st.can_send;
            this.$('.cx-compose').classList.toggle('cx-blocked', !!blocked);
            if (!st || !st.mode) { slot.innerHTML = ''; return; }
            slot.innerHTML = `<div class="cx-closedbar ${blocked ? '' : 'slim'}"><i class="fas ${blocked ? 'fa-lock' : 'fa-ban'}"></i><span>${esc(fa(st.text))}</span>${st.can_reopen ? '<button><i class="fas fa-link"></i> وصلِ دوباره</button>' : ''}</div>`;
            const b = slot.querySelector('button'); if (b) b.onclick = () => this.reopen(this.key);
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

    window.ChatUI = { mount: (el, o) => new Chat(el, o), fa, esc, avatarHtml, avatarUrl, seenLabel, pickAvatar, dialog, heartbeat, toast: miniToast, PRESETS,
        // گفتگویی با این کلید (یا هر گفتگویی، بدونِ کلید) همین الان روی صفحه باز و دیده می‌شود؟
        isViewing: key => [...LIVE].some(c => c.viewing(key)), sound: SND, mute: MUTE };
})();
