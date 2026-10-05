// فایل: announce.js
// نمایشِ «اعلان‌های پاپ‌آپ» (api/announce_actions.php): هر دقیقه اعلان‌های تازه را می‌گیرد و با طرحِ همان اعلان
// (اطلاع‌رسانی، هشدار، اخطار، قوانین، تبریک، خبر، به‌روزرسانیِ سامانه، تعطیلی، تولد) نشان می‌دهد.
// پنلِ شرکت‌ها: window.ANN_CONFIG = {api: '../api/announce_actions.php?portal=1'}
(function () {
    'use strict';
    if (window.Announce) return;
    const API = (window.ANN_CONFIG && ANN_CONFIG.api) || 'api/announce_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s ?? '').replace(/\d/g, d => FA[d]);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    async function api(action, body = {}) {
        try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action}, body))})).json(); }
        catch (e) { return {ok: false}; }
    }

    // طرح‌های آماده: نام، نماد، رنگ‌ها، متنِ پیش‌فرض
    const T = {
        info: {name: 'اطلاع‌رسانی', icon: 'ℹ️', bg: 'linear-gradient(135deg,#2563eb,#06b6d4)', acc: '#2563eb', soft: '#eff6ff', btn: 'متوجه شدم',
               title: 'اطلاعیه', body: 'همکارانِ گرامی،\nبه اطلاع می‌رساند …'},
        warning: {name: 'هشدار', icon: '⚠️', bg: 'repeating-linear-gradient(135deg,#f59e0b 0 22px,#fbbf24 22px 44px)', acc: '#d97706', soft: '#fffbeb', btn: 'متوجه شدم',
                  title: 'هشدار', body: 'لطفاً توجه کنید:\n- …'},
        danger: {name: 'اخطار', icon: '🚨', bg: 'linear-gradient(135deg,#dc2626,#9f1239)', acc: '#dc2626', soft: '#fef2f2', btn: 'خواندم و رعایت می‌کنم', ack: true,
                 title: 'اخطار', body: 'این یک اخطارِ رسمی است.\n…'},
        rules: {name: 'قوانین و مقررات', icon: '📜', bg: 'linear-gradient(135deg,#4f46e5,#7c3aed)', acc: '#4f46e5', soft: '#eef2ff', btn: 'خواندم و می‌پذیرم', ack: true,
                title: 'قوانین و مقرراتِ کار', body: 'ساعتِ کاری از ۸ تا ۱۶ است.\nپاسخ به پیامِ مشتریان حداکثر تا ۲ ساعت.\nاطلاعاتِ مشتریان محرمانه است.'},
        celebrate: {name: 'تبریک و جشن', icon: '🎉', bg: 'linear-gradient(135deg,#ec4899,#f97316 55%,#facc15)', acc: '#db2777', soft: '#fdf2f8', btn: 'ممنون 🙌', confetti: true,
                    title: 'تبریک!', body: 'به همه‌ی همکاران تبریک می‌گوییم …'},
        news: {name: 'خبر و اطلاعیه‌ی جدید', icon: '📢', bg: 'linear-gradient(135deg,#0d9488,#10b981)', acc: '#0d9488', soft: '#f0fdfa', btn: 'عالی!',
               title: 'خبرِ تازه', body: 'امکانِ تازه‌ای به پنل اضافه شد:\n- …'},
        maintenance: {name: 'به‌روزرسانی / قطعیِ سامانه', icon: '🛠️', bg: 'linear-gradient(135deg,#0f172a,#334155)', acc: '#334155', soft: '#f1f5f9', btn: 'باشه',
                      title: 'به‌روزرسانیِ سامانه', body: 'سامانه امشب از ساعتِ ۲۲ تا ۲۳ برای به‌روزرسانی در دسترس نیست.\nلطفاً کارهای باز را قبل از آن ذخیره کنید.'},
        holiday: {name: 'تعطیلی', icon: '🌴', bg: 'linear-gradient(135deg,#16a34a,#84cc16)', acc: '#16a34a', soft: '#f0fdf4', btn: 'خوش بگذرد!',
                  title: 'اطلاعیه‌ی تعطیلی', body: 'دفتر روزِ … تعطیل است.\nتعطیلاتِ خوبی داشته باشید. 🌿'},
        birthday: {name: 'تولد', icon: '🎂', bg: 'linear-gradient(135deg,#a855f7,#ec4899)', acc: '#c026d3', soft: '#fdf4ff', btn: 'ممنونم 🥳', confetti: true, balloons: true,
                   title: 'تولدت مبارک! 🎂', body: 'سالی پر از شادی و موفقیت برایت آرزو می‌کنیم.'},
    };

    const CSS = `
    .an-ov{position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);direction:rtl;animation:anFade .25s ease}
    @keyframes anFade{from{opacity:0}to{opacity:1}}
    .an-card{position:relative;width:min(470px,100%);max-height:92vh;display:flex;flex-direction:column;background:#fff;border-radius:30px;overflow:hidden;box-shadow:0 40px 90px -25px rgba(2,6,23,.7);animation:anPop .45s cubic-bezier(.2,1.2,.3,1)}
    @keyframes anPop{from{opacity:0;transform:translateY(30px) scale(.9)}to{opacity:1;transform:none}}
    .an-hd{position:relative;padding:30px 24px 22px;color:#fff;text-align:center;overflow:hidden}
    .an-hd::before{content:'';position:absolute;width:260px;height:260px;border-radius:50%;left:-80px;top:-140px;background:radial-gradient(circle,rgba(255,255,255,.35),transparent 70%)}
    .an-hd::after{content:'';position:absolute;width:200px;height:200px;border-radius:50%;right:-70px;bottom:-120px;background:radial-gradient(circle,rgba(255,255,255,.25),transparent 70%)}
    .an-ic{position:relative;z-index:1;width:84px;height:84px;margin:0 auto 10px;border-radius:28px;display:flex;align-items:center;justify-content:center;font-size:44px;background:rgba(255,255,255,.22);box-shadow:inset 0 0 0 1px rgba(255,255,255,.35),0 12px 26px -10px rgba(0,0,0,.35)}
    .an-hd h2{position:relative;z-index:1;font-size:20px;font-weight:900;line-height:1.6;text-shadow:0 2px 10px rgba(0,0,0,.15)}
    .an-tag{position:relative;z-index:1;display:inline-block;font-size:10.5px;font-weight:900;padding:3px 12px;border-radius:999px;background:rgba(255,255,255,.22);margin-bottom:8px}
    .an-bd{padding:20px 24px 6px;overflow-y:auto;font-size:13.5px;line-height:2.05;color:#334155}
    .an-bd p{margin:0 0 6px}
    .an-bd ul{margin:4px 0 8px;padding-right:20px;list-style:disc}
    .an-bd ol{margin:4px 0 8px;padding:0;list-style:none;counter-reset:an}
    .an-bd ol li{position:relative;padding:8px 44px 8px 10px;margin-bottom:6px;border-radius:14px;background:var(--an-soft);counter-increment:an}
    .an-bd ol li::before{content:counter(an,persian);position:absolute;right:10px;top:8px;width:24px;height:24px;border-radius:9px;background:var(--an-acc);color:#fff;font-size:12px;font-weight:900;display:flex;align-items:center;justify-content:center}
    .an-ft{padding:12px 24px 20px;display:flex;flex-direction:column;gap:10px}
    .an-ackl{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;color:#334155;background:var(--an-soft);border-radius:14px;padding:10px 12px;cursor:pointer}
    .an-ackl input{width:18px;height:18px;accent-color:var(--an-acc)}
    .an-btn{border:0;border-radius:16px;padding:13px 18px;font-size:14px;font-weight:900;color:#fff;cursor:pointer;font-family:inherit;background:var(--an-bg);box-shadow:0 12px 24px -12px var(--an-acc);transition:.2s}
    .an-btn:disabled{opacity:.45;cursor:not-allowed;filter:grayscale(.4)}
    .an-btn:not(:disabled):hover{transform:translateY(-1px)}
    .an-meta{display:flex;justify-content:space-between;font-size:10.5px;color:#94a3b8}
    .an-later{border:0;background:transparent;color:#94a3b8;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit}
    .an-x{position:absolute;top:14px;left:14px;z-index:3;width:32px;height:32px;border-radius:12px;border:0;background:rgba(255,255,255,.25);color:#fff;cursor:pointer;font-size:15px}
    .an-count{position:absolute;top:16px;right:16px;z-index:3;font-size:10.5px;font-weight:900;color:#fff;background:rgba(0,0,0,.2);padding:3px 10px;border-radius:999px}
    .an-t-warning .an-hd{text-shadow:0 1px 0 rgba(0,0,0,.2)}
    .an-t-warning .an-ic{animation:anShake 1.4s ease-in-out infinite}
    @keyframes anShake{0%,60%,100%{transform:rotate(0)}10%,30%,50%{transform:rotate(-8deg)}20%,40%{transform:rotate(8deg)}}
    .an-t-danger .an-card,.an-card.an-t-danger{box-shadow:0 0 0 4px rgba(220,38,38,.35),0 40px 90px -25px rgba(127,29,29,.8);animation:anPop .45s cubic-bezier(.2,1.2,.3,1),anGlow 1.6s ease-in-out .5s infinite}
    @keyframes anGlow{50%{box-shadow:0 0 0 10px rgba(220,38,38,.12),0 40px 90px -25px rgba(127,29,29,.8)}}
    .an-t-danger .an-ic{animation:anPulse 1.2s ease-in-out infinite}
    @keyframes anPulse{50%{transform:scale(1.1)}}
    .an-t-maintenance .an-ic{animation:anSpin 6s linear infinite}
    @keyframes anSpin{to{transform:rotate(360deg)}}
    .an-t-rules .an-bd{background:repeating-linear-gradient(transparent 0 31px,#f1f5f9 31px 32px)}
    .an-t-news .an-ic,.an-t-holiday .an-ic,.an-t-info .an-ic{animation:anBob 2.4s ease-in-out infinite}
    @keyframes anBob{50%{transform:translateY(-5px)}}
    .an-conf{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:2}
    .an-conf i{position:absolute;top:-12px;width:8px;height:14px;border-radius:2px;opacity:.9;animation:anFall linear forwards}
    @keyframes anFall{to{transform:translateY(760px) rotate(720deg);opacity:.2}}
    .an-bal{position:absolute;bottom:-80px;z-index:2;font-size:34px;animation:anRise linear forwards;pointer-events:none}
    @keyframes anRise{to{transform:translateY(-760px) rotate(-8deg)}}
    .an-hist .it{display:flex;gap:10px;padding:10px 12px;border-radius:14px;cursor:pointer;align-items:center}
    .an-hist .it:hover{background:#f8fafc}
    @media (max-width:480px){.an-card{border-radius:24px}.an-hd{padding:24px 18px 18px}.an-bd,.an-ft{padding-left:18px;padding-right:18px}}
    `;
    function css() { if (!document.getElementById('an-css')) { const s = document.createElement('style'); s.id = 'an-css'; s.textContent = CSS; document.head.appendChild(s); } }

    // متن: **پررنگ**، خطِ «- » فهرست، و در «قوانین» هر خط یک بندِ شماره‌دار
    function bodyHtml(text, tpl) {
        const lines = String(text || '').split(/\r?\n/);
        const bold = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
        if (tpl === 'rules') {
            const items = lines.map(l => l.replace(/^\s*(?:[-•*]|\d+[.)-]|[۰-۹]+[.)-])\s*/, '').trim()).filter(Boolean);
            return `<ol>${items.map(l => `<li>${bold(l)}</li>`).join('')}</ol>`;
        }
        let h = '', ul = false;
        lines.forEach(l => {
            const m = l.match(/^\s*[-•*]\s+(.*)$/);
            if (m) { if (!ul) { h += '<ul>'; ul = true; } h += `<li>${bold(m[1])}</li>`; return; }
            if (ul) { h += '</ul>'; ul = false; }
            h += l.trim() ? `<p>${bold(l)}</p>` : '';
        });
        return h + (ul ? '</ul>' : '');
    }
    function confetti(card, balloons) {
        const box = document.createElement('div');
        box.className = 'an-conf';
        const colors = ['#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#ec4899', '#facc15'];
        for (let i = 0; i < 70; i++) {
            const p = document.createElement('i');
            p.style.left = Math.random() * 100 + '%';
            p.style.background = colors[i % colors.length];
            p.style.animationDuration = (2.2 + Math.random() * 2.4) + 's';
            p.style.animationDelay = (Math.random() * 1.2) + 's';
            p.style.transform = `rotate(${Math.random() * 360}deg)`;
            box.appendChild(p);
        }
        if (balloons) ['🎈', '🎈', '🎁', '🎈', '🥳'].forEach((b, i) => { const x = document.createElement('span'); x.className = 'an-bal'; x.textContent = b; x.style.left = (8 + i * 19) + '%'; x.style.animationDuration = (4 + i * 0.5) + 's'; x.style.animationDelay = (i * 0.3) + 's'; box.appendChild(x); });
        card.appendChild(box);
        setTimeout(() => box.remove(), 7500);
    }
    // نمایشِ یک اعلان. opts: {preview, index, total, onDone(acked)}
    function show(item, opts = {}) {
        css();
        const t = T[item.template] || T.info;
        const ov = document.createElement('div');
        ov.className = 'an-ov';
        const ack = !!item.require_ack;
        ov.innerHTML = `<div class="an-card an-t-${esc(item.template)}" style="--an-bg:${t.bg};--an-acc:${t.acc};--an-soft:${t.soft}">
            ${opts.total > 1 ? `<span class="an-count">${fa(opts.index + 1)} از ${fa(opts.total)}</span>` : ''}
            ${!ack || opts.preview ? '<button class="an-x" data-x title="بستن">✕</button>' : ''}
            <div class="an-hd" style="background:${t.bg}"><span class="an-tag">${esc(t.name)}${opts.preview ? ' · پیش‌نمایش' : ''}</span><div class="an-ic">${esc(item.icon || t.icon)}</div><h2>${esc(item.title)}</h2></div>
            <div class="an-bd">${bodyHtml(item.body, item.template)}</div>
            <div class="an-ft">
                ${ack ? `<label class="an-ackl"><input type="checkbox" data-ack> ${item.template === 'rules' ? 'قوانین را خواندم و رعایت می‌کنم' : 'این اعلان را خواندم و متوجه شدم'}</label>` : ''}
                <button class="an-btn" data-ok ${ack ? 'disabled' : ''}>${esc(item.button || t.btn)}</button>
                <div class="an-meta"><span>${item.from ? 'از طرفِ ' + esc(item.from) : ''}</span>${!ack && !opts.preview ? '<button class="an-later" data-later>بعداً یادم بنداز</button>' : ''}<span>${fa(item.at || '')}</span></div>
            </div></div>`;
        document.body.appendChild(ov);
        const card = ov.querySelector('.an-card');
        if (t.confetti) setTimeout(() => confetti(card, t.balloons), 250);
        const done = (how) => { ov.style.transition = 'opacity .2s'; ov.style.opacity = '0'; setTimeout(() => ov.remove(), 200); if (opts.onDone) opts.onDone(how); };
        const cb = ov.querySelector('[data-ack]');
        if (cb) cb.onchange = () => { ov.querySelector('[data-ok]').disabled = !cb.checked; };
        ov.querySelector('[data-ok]').onclick = () => done(ack ? 'ack' : 'seen');
        const x = ov.querySelector('[data-x]'); if (x) x.onclick = () => done(opts.preview ? 'close' : 'seen');
        const l = ov.querySelector('[data-later]'); if (l) l.onclick = () => done('later');
        return ov;
    }

    // ---------------- صفِ اعلان‌ها ----------------
    const Q = {busy: false, timer: null};
    const later = () => { try { return JSON.parse(localStorage.getItem('an:later') || '{}'); } catch (e) { return {}; } };
    function setLater(id) { try { const l = later(); l[id] = Date.now() + 3600e3; localStorage.setItem('an:later', JSON.stringify(l)); } catch (e) {} }
    async function poll() {
        if (Q.busy || document.hidden) return;
        const r = await api('pending');
        if (!r.ok || !r.items || !r.items.length) return;
        const lt = later();
        const items = r.items.filter(i => !(lt[i.id] && lt[i.id] > Date.now()));
        if (!items.length) return;
        Q.busy = true;
        let i = 0;
        const next = () => {
            if (i >= items.length) { Q.busy = false; return; }
            const it = items[i];
            show(it, {index: i, total: items.length, onDone: how => {
                if (how === 'later') setLater(it.id); else api(how === 'ack' ? 'ack' : 'seen', {id: it.id});
                i++; setTimeout(next, 350);
            }});
        };
        next();
    }
    // اعلان‌های ۶۰ روزِ اخیر (برای دیدنِ دوباره)
    async function history() {
        const r = await api('mine');
        css();
        const ov = document.createElement('div');
        ov.className = 'an-ov';
        ov.innerHTML = `<div class="an-card" style="--an-bg:${T.info.bg};--an-acc:#4f46e5;--an-soft:#eef2ff"><div class="an-hd" style="background:linear-gradient(135deg,#4f46e5,#06b6d4);padding:20px"><h2>اعلان‌های اخیر</h2></div>
            <div class="an-bd an-hist">${r.ok && r.items.length ? r.items.map((it, k) => { const t = T[it.template] || T.info; return `<div class="it" data-k="${k}"><span style="font-size:24px">${esc(it.icon || t.icon)}</span><div style="flex:1;min-width:0"><b style="display:block;font-size:12.5px;color:#0f172a">${esc(it.title)}</b><small style="color:#94a3b8;font-size:10.5px">${esc(t.name)} · ${fa(it.at)}</small></div></div>`; }).join('') : '<p style="text-align:center;color:#94a3b8">اعلانی نیست.</p>'}</div>
            <div class="an-ft"><button class="an-btn" data-x>بستن</button></div></div>`;
        document.body.appendChild(ov);
        ov.querySelector('[data-x]').onclick = () => ov.remove();
        ov.addEventListener('mousedown', e => { if (e.target === ov) ov.remove(); });
        ov.querySelectorAll('[data-k]').forEach(x => x.onclick = () => show(r.items[+x.dataset.k], {preview: true}));
    }
    function start() {
        setTimeout(poll, 3500);
        Q.timer = setInterval(poll, 60000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) setTimeout(poll, 800); });
    }
    window.Announce = {TEMPLATES: T, show, preview: item => show(item, {preview: true}), poll, history, bodyHtml};
    if (!window.ANN_CONFIG || ANN_CONFIG.auto !== false) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start(); }
})();
