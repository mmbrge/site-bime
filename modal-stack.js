// فایل: modal-stack.js
// قانونِ کلیِ پاپ‌آپ‌ها: هر پاپ‌آپی که باز می‌شود، روی پاپ‌آپ‌های بازِ قبلی می‌نشیند (نه زیرشان).
// مثلاً پنلِ صدور باز است و از داخلش «ورود به پاسارگاد» یا «استعلام» یا «پرونده‌ی خودرو» باز می‌شود.
// هر بخشِ سایت پاپ‌آپِ خودش را با z-indexِ ثابتی ساخته؛ اینجا وقتی یک لایه‌ی تمام‌صفحه (overlay) نمایان می‌شود،
// اگر پایین‌تر از لایه‌های بازِ دیگر بود، یک پله بالاتر از همه‌شان برده می‌شود. هیچ لایه‌ای پایین آورده نمی‌شود.
(function () {
    'use strict';
    if (window.ModalStack) return;
    // نام‌هایی که لایه‌های پاپ‌آپِ سایت دارند (modal-overlay، ipn-ov، rg-ov، inq-modal، pbu-ov، dupc-ov، …)
    const NAME_RE = /(^|[\s_-])(ov|overlay|modal|backdrop|dlg|dialog|popup|lightbox)($|[\s_-])/i;
    const shown = new WeakSet();

    function isOverlay(el) {
        if (!el || el.nodeType !== 1 || el === document.body || el === document.documentElement) return false;
        const cls = typeof el.className === 'string' ? el.className : '';
        if (el.tagName !== 'DIALOG' && !NAME_RE.test(cls)) return false;
        const cs = getComputedStyle(el);
        // شفافیت بررسی نمی‌شود: پاپ‌آپ‌ها با محو شدن (opacity) باز می‌شوند و لحظه‌ی اول هنوز شفاف‌اند
        if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden' || cs.pointerEvents === 'none') return false;
        const r = el.getBoundingClientRect();
        return r.width >= window.innerWidth * 0.85 && r.height >= window.innerHeight * 0.85;
    }
    const zOf = el => { const z = parseInt(getComputedStyle(el).zIndex, 10); return isNaN(z) ? 0 : z; };
    // لایه‌های تمام‌صفحه‌ی نمایانِ دیگر
    function others(except) {
        const out = [];
        document.querySelectorAll('body *').forEach(el => {
            if (el === except || el.contains(except) || except.contains(el)) return;
            if (isOverlay(el)) out.push(el);
        });
        return out;
    }
    function raise(el) {
        if (!isOverlay(el)) { shown.delete(el); return; }
        if (shown.has(el)) return;   // همین حالا هم باز بوده؛ دوباره جابه‌جا نشود
        shown.add(el);
        const list = others(el);
        if (!list.length) return;
        const top = Math.max(...list.map(zOf));
        if (zOf(el) <= top) el.style.zIndex = String(top + 1);
    }
    // هر تغییر: لایه‌های تازه (اضافه‌شده) یا لایه‌هایی که کلاس/نمایششان عوض شده (مثلاً modal-overlay + active)
    let queue = new Set(), timer = 0;
    const flush = () => {
        timer = 0;
        const q = queue; queue = new Set();
        q.forEach(el => { if (el.isConnected) raise(el); else shown.delete(el); });
    };
    const push = el => { queue.add(el); if (!timer) timer = requestAnimationFrame(flush); };
    const scan = node => {
        if (node.nodeType !== 1) return;
        const cls = typeof node.className === 'string' ? node.className : '';
        if (node.tagName === 'DIALOG' || NAME_RE.test(cls)) push(node);
        // پاپ‌آپی که داخلِ یک ظرفِ تازه آمده (یک یا دو لایه پایین‌تر)
        for (const c of node.children || []) {
            const cc = typeof c.className === 'string' ? c.className : '';
            if (c.tagName === 'DIALOG' || NAME_RE.test(cc)) push(c);
        }
    };
    function start() {
        new MutationObserver(muts => {
            for (const m of muts) {
                if (m.type === 'childList') m.addedNodes.forEach(scan);
                else if (m.target.nodeType === 1) {
                    const cls = typeof m.target.className === 'string' ? m.target.className : '';
                    if (m.target.tagName === 'DIALOG' || NAME_RE.test(cls)) push(m.target);
                }
            }
        }).observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden', 'open']});
        // لایه‌هایی که از اول نمایان‌اند حسابِ «باز» دارند
        document.querySelectorAll('body *').forEach(el => { if (isOverlay(el)) shown.add(el); });
    }
    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
    window.ModalStack = {raise: el => { shown.delete(el); raise(el); }};
})();
