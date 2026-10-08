// فایل: cursor-fx.js
// جلوه‌های نشانگرِ اختصاصیِ موس (روی همان .cursor-dot و .cursor-outline؛ اندازه‌ها عوض نمی‌شوند):
//  - کلیک: موجِ حلقه‌ای + چند جرقه‌ی کوچک در محلِ کلیک، و فشرده‌شدنِ نشانگر هنگامِ نگه‌داشتنِ دکمه
//  - روی جای تایپ (input/textarea/contenteditable): نقطه به خطِ چشمک‌زنِ تایپ تبدیل می‌شود
//  - روی چیزِ قابل‌کلیک: درخششِ نرم و تپنده
// فقط روی دستگاهِ دارای موسِ واقعی؛ رنگ با --cfx (پیش‌فرض آبی) قابلِ تغییر است.
(function () {
    'use strict';
    if (window.CursorFx) return;
    if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    const css = `
    :root{--cfx:${window.CURSOR_FX_COLOR || '#3b82f6'}}
    .cursor-dot{transition:background .2s,width .18s,height .18s,border-radius .18s,box-shadow .25s}
    .cursor-outline{transition:opacity .2s,width .2s,height .2s,border-color .2s}
    .cursor-dot.is-hover{animation:cfxGlow 1.4s ease-in-out infinite}
    @keyframes cfxGlow{0%,100%{box-shadow:0 0 8px color-mix(in srgb,var(--cfx) 55%,transparent)}50%{box-shadow:0 0 0 5px color-mix(in srgb,var(--cfx) 18%,transparent),0 0 16px var(--cfx)}}
    .cursor-dot.is-text.is-text{width:3px;height:20px;border-radius:2px;animation:cfxBlink 1.05s steps(1) infinite;box-shadow:0 0 6px color-mix(in srgb,var(--cfx) 60%,transparent)}
    .cursor-dot.is-text.is-text{transform:translate(var(--cx,-100px),var(--cy,-100px)) translate(-50%,-50%)}
    .cursor-outline.is-text.is-text{opacity:0}
    @keyframes cfxBlink{0%,60%{opacity:1}61%,100%{opacity:.25}}
    .cursor-dot.is-down{transform:translate(var(--cx,-100px),var(--cy,-100px)) translate(-50%,-50%) scale(.6)!important}
    .cursor-outline.is-down{transform:translate(var(--ox,-100px),var(--oy,-100px)) translate(-50%,-50%) scale(.75)!important;border-color:var(--cfx)}
    .cfx-ripple{position:fixed;left:0;top:0;width:10px;height:10px;margin:-5px 0 0 -5px;border-radius:50%;pointer-events:none;z-index:2147483645;
        border:2px solid var(--cfx);animation:cfxRing .55s cubic-bezier(.2,.7,.3,1) forwards}
    @keyframes cfxRing{from{transform:scale(.4);opacity:.9}to{transform:scale(4.2);opacity:0;border-width:1px}}
    .cfx-spark{position:fixed;left:0;top:0;width:4px;height:4px;margin:-2px 0 0 -2px;border-radius:50%;background:var(--cfx);pointer-events:none;z-index:2147483645;
        animation:cfxSpark .5s ease-out forwards}
    @keyframes cfxSpark{from{transform:translate(0,0) scale(1);opacity:1}to{transform:translate(var(--dx),var(--dy)) scale(.2);opacity:0}}
    `;
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
    const TEXT_SEL = 'textarea, [contenteditable=""], [contenteditable="true"], input:not([type]), input[type="text"], input[type="search"], input[type="email"], input[type="tel"], input[type="url"], input[type="password"], input[type="number"]';
    const dots = () => [document.querySelector('.cursor-dot'), document.querySelector('.cursor-outline')].filter(Boolean);
    let isText = false;
    function setText(on) {
        if (on === isText) return;
        isText = on;
        dots().forEach(el => el.classList.toggle('is-text', on));
    }
    document.addEventListener('mouseover', e => {
        const t = e.target instanceof Element ? e.target : null;
        const inp = t && t.closest(TEXT_SEL);
        setText(!!(inp && !inp.disabled && !inp.readOnly));
    }, {passive: true});
    document.addEventListener('mousedown', e => { if (e.button === 0) dots().forEach(el => el.classList.add('is-down')); }, {passive: true});
    const up = () => dots().forEach(el => el.classList.remove('is-down'));
    document.addEventListener('mouseup', up, {passive: true});
    window.addEventListener('blur', up);
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const fancy = () => !document.documentElement.classList.contains('cur-simple') && !document.documentElement.classList.contains('cur-native');
    document.addEventListener('click', e => {
        if (reduce || !fancy() || !e.isTrusted || (e.clientX === 0 && e.clientY === 0)) return;
        const x = e.clientX, y = e.clientY;
        const r = document.createElement('div');
        r.className = 'cfx-ripple';
        r.style.left = x + 'px'; r.style.top = y + 'px';
        document.body.appendChild(r);
        setTimeout(() => r.remove(), 700);
        if (isText) return;   // روی جای تایپ فقط موجِ ساده
        for (let i = 0; i < 6; i++) {
            const a = (Math.PI * 2 * i) / 6 + Math.random() * .5, d = 14 + Math.random() * 10;
            const s = document.createElement('div');
            s.className = 'cfx-spark';
            s.style.left = x + 'px'; s.style.top = y + 'px';
            s.style.setProperty('--dx', (Math.cos(a) * d).toFixed(1) + 'px');
            s.style.setProperty('--dy', (Math.sin(a) * d).toFixed(1) + 'px');
            document.body.appendChild(s);
            setTimeout(() => s.remove(), 650);
        }
    }, {passive: true, capture: true});
    window.CursorFx = {setText};
})();
