// فایل: notif-bell.js
// زنگوله‌ی اعلان‌ها - مشترک بین پنل مدیریت (dashboard.php) و پنل شرکت‌ها (company-portal).
//
// هر اعلانی که گوشه‌ی صفحه ظاهر می‌شود، این‌جا هم ثبت می‌شود. با کلیک روی زنگوله، پنجره‌ی
// کوچکی زیرِ همان باز می‌شود با فهرست اعلان‌ها: حذفِ تکی، پاک‌کردنِ همه، و «همه خوانده شد».
//
// دو حالت دارد:
//  - «سرور» (وقتی مایگریشن ۰۱۲ اجرا شده): فهرست در دیتابیس است و با هر بار پرسیدنِ اعلان‌ها
//    از سرور می‌آید (setServerItems)؛ حذف/خواندن هم روی سرور انجام می‌شود (onServerAction)،
//    پس همان کاربر در هر مرورگر و دستگاهی همان فهرست را می‌بیند.
//  - «محلی» (پشتیبان): فهرست در localStorage همین مرورگر، جدا برای هر کاربر و هر پنل.
//
// استفاده:
//   NotifBell.init({ mount: el, storageKey: 'notif:admin:12', onOpenItem: item => ..., onServerAction: (act, id) => Promise<items> });
//   NotifBell.setServerItems(items)   // حالتِ سرور
//   NotifBell.add({ title, body, type, tab })   // حالتِ محلی
(function () {
    const MAX_ITEMS = 60;
    const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => String(v).replace(/\d/g, d => FA_DIGITS[d]);
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    let items = [];
    let storageKey = null;
    let onOpenItem = null;
    let onServerAction = null;
    let mode = 'local';   // 'local' | 'server'
    let root, btn, badge, panel, list;

    function load() {
        try { items = JSON.parse(localStorage.getItem(storageKey) || '[]') || []; } catch (e) { items = items || []; }
        if (!Array.isArray(items)) items = [];
    }
    function save() {
        if (mode === 'server') return;
        try { localStorage.setItem(storageKey, JSON.stringify(items.slice(0, MAX_ITEMS))); } catch (e) { /* فقط در حافظه */ }
    }
    // در حالتِ سرور، هر تغییر به سرور هم می‌رود و فهرستِ تازه از همان‌جا جایگزین می‌شود
    function server(act, id) {
        if (mode !== 'server' || !onServerAction) return;
        Promise.resolve(onServerAction(act, id)).then(list => { if (Array.isArray(list)) setServerItems(list); }).catch(() => {});
    }
    function setServerItems(list) {
        mode = 'server';
        items = (list || []).map(i => ({ id: String(i.id), title: i.title, body: i.body || '', type: i.type || 'info', tab: i.tab || null,
                                          at: (Number(i.at_ts) || 0) * 1000 || Date.now(), read: !!i.read }));
        render();
    }

    // زمانِ اعلان به شمسی و با رقم فارسی: «همین الان»، «۵ دقیقه پیش»، یا «۴ مهر، ۱۰:۲۰»
    function timeFa(ms) {
        const diff = Math.floor((Date.now() - ms) / 1000);
        if (diff < 60) return 'همین الان';
        if (diff < 3600) return fa(Math.floor(diff / 60)) + ' دقیقه پیش';
        try {
            return new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-arabext', {month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false}).format(new Date(ms));
        } catch (e) { return ''; }
    }

    function injectStyles() {
        if (document.getElementById('notif-bell-style')) return;
        const st = document.createElement('style');
        st.id = 'notif-bell-style';
        st.textContent = `
        .nb-root { position: relative; display: inline-flex; }
        .nb-btn { position: relative; width: 38px; height: 38px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
                  color: #64748b; background: #f1f5f9; border: 0; cursor: pointer; transition: background .15s, color .15s; }
        .nb-btn:hover, .nb-root.open .nb-btn { background: #dbeafe; color: #2563eb; }
        .nb-badge { position: absolute; top: -5px; right: -5px; min-width: 19px; height: 19px; padding: 0 4px; border-radius: 999px;
                    background: #ef4444; color: #fff; font-size: 10px; font-weight: 900; display: flex; align-items: center; justify-content: center;
                    box-shadow: 0 0 0 2px #fff; }
        .nb-badge[hidden] { display: none; }
        .nb-panel { position: absolute; top: calc(100% + 10px); left: 0; width: 340px; max-width: calc(100vw - 32px); background: #fff;
                    border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 18px 40px -12px rgba(15,23,42,.28); z-index: 9999991;
                    display: none; overflow: hidden; direction: rtl; text-align: right; }
        .nb-root.open .nb-panel { display: block; }
        .nb-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; border-bottom: 1px solid #f1f5f9; }
        .nb-head b { font-size: 13px; color: #334155; }
        .nb-actions { display: flex; gap: 4px; }
        .nb-actions button { font-size: 10.5px; font-weight: 700; color: #2563eb; background: #eff6ff; border: 0; border-radius: 8px; padding: 4px 8px; cursor: pointer; }
        .nb-actions button.nb-danger { color: #dc2626; background: #fef2f2; }
        .nb-actions button:disabled { opacity: .45; cursor: default; }
        .nb-list { max-height: 360px; overflow-y: auto; }
        .nb-item { display: flex; gap: 8px; align-items: flex-start; padding: 10px 12px; border-bottom: 1px solid #f8fafc; cursor: pointer; }
        .nb-item:hover { background: #f8fafc; }
        .nb-item.unread { background: #f0f7ff; }
        .nb-dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex: none; background: transparent; }
        .nb-item.unread .nb-dot { background: #3b82f6; }
        .nb-text { flex: 1; min-width: 0; }
        .nb-title { font-size: 12px; font-weight: 800; color: #334155; }
        .nb-body { font-size: 11px; color: #64748b; line-height: 1.6; margin-top: 2px; overflow-wrap: anywhere; }
        .nb-time { font-size: 10px; color: #94a3b8; margin-top: 3px; }
        .nb-del { flex: none; width: 22px; height: 22px; border-radius: 7px; border: 0; background: transparent; color: #cbd5e1; cursor: pointer; font-size: 15px; line-height: 1; }
        .nb-del:hover { background: #fee2e2; color: #dc2626; }
        .nb-empty { padding: 28px 12px; text-align: center; font-size: 12px; color: #94a3b8; }
        @media (max-width: 640px) { .nb-panel { position: fixed; top: 64px; left: 16px; right: 16px; width: auto; max-width: none; } }`;
        document.head.appendChild(st);
    }

    function render() {
        if (!root) return;
        const unread = items.filter(i => !i.read).length;
        badge.hidden = unread === 0;
        badge.textContent = unread > 99 ? '+۹۹' : fa(unread);
        btn.setAttribute('aria-label', unread ? `اعلان‌ها (${fa(unread)} خوانده‌نشده)` : 'اعلان‌ها');
        panel.querySelector('[data-nb="read"]').disabled = unread === 0;
        panel.querySelector('[data-nb="clear"]').disabled = items.length === 0;
        list.innerHTML = items.length ? items.map(i => `
            <div class="nb-item ${i.read ? '' : 'unread'}" data-id="${esc(i.id)}">
                <span class="nb-dot"></span>
                <div class="nb-text">
                    <div class="nb-title">${esc(i.title || 'اعلان')}</div>
                    ${i.body ? `<div class="nb-body">${esc(i.body)}</div>` : ''}
                    <div class="nb-time">${timeFa(i.at)}</div>
                </div>
                <button type="button" class="nb-del hover-target" data-del="${esc(i.id)}" title="حذف این اعلان" aria-label="حذف این اعلان">×</button>
            </div>`).join('') : '<div class="nb-empty">اعلانی ندارید.</div>';
    }

    function toggle(open) {
        const willOpen = open === undefined ? !root.classList.contains('open') : open;
        root.classList.toggle('open', willOpen);
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) render(); // زمان‌های «... دقیقه پیش» تازه شوند
    }

    function init(opts) {
        storageKey = opts.storageKey || 'notif:default';
        onOpenItem = opts.onOpenItem || null;
        onServerAction = opts.onServerAction || null;
        load();
        injectStyles();

        root = document.createElement('div');
        root.className = 'nb-root';
        root.innerHTML = `
            <button type="button" class="nb-btn hover-target" aria-haspopup="true" aria-expanded="false" title="اعلان‌ها">
                <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span class="nb-badge" hidden></span>
            </button>
            <div class="nb-panel" role="dialog" aria-label="اعلان‌ها">
                <div class="nb-head">
                    <b>اعلان‌ها</b>
                    <div class="nb-actions">
                        <button type="button" class="hover-target" data-nb="read">همه خوانده شد</button>
                        <button type="button" class="nb-danger hover-target" data-nb="clear">پاک کردن همه</button>
                    </div>
                </div>
                <div class="nb-list"></div>
            </div>`;
        btn = root.querySelector('.nb-btn');
        badge = root.querySelector('.nb-badge');
        panel = root.querySelector('.nb-panel');
        list = root.querySelector('.nb-list');
        opts.mount.appendChild(root);

        btn.addEventListener('click', e => { e.stopPropagation(); toggle(); });
        panel.addEventListener('click', e => {
            e.stopPropagation();
            const del = e.target.closest('[data-del]');
            if (del) { items = items.filter(i => String(i.id) !== del.dataset.del); save(); render(); server('delete', del.dataset.del); return; }
            const act = e.target.closest('[data-nb]');
            if (act) {
                if (act.dataset.nb === 'read') { items.forEach(i => { i.read = true; }); server('read_all'); }
                if (act.dataset.nb === 'clear') { items = []; server('clear'); }
                save(); render(); return;
            }
            const row = e.target.closest('.nb-item');
            if (row) {
                const it = items.find(i => String(i.id) === row.dataset.id);
                if (!it) return;
                if (!it.read) server('read', it.id);
                it.read = true; save(); render();
                if (onOpenItem) { toggle(false); onOpenItem(it); }
            }
        });
        document.addEventListener('click', () => { if (root.classList.contains('open')) toggle(false); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && root.classList.contains('open')) toggle(false); });
        // اگر پنل در چند تب باز است، فهرست همه‌جا یکی بماند
        window.addEventListener('storage', e => { if (mode === 'local' && e.key === storageKey) { load(); render(); } });
        render();
    }

    function add(ev) {
        if (mode === 'server') return; // فهرست از سرور می‌آید
        items.unshift({
            id: Date.now().toString(36) + Math.random().toString(36).slice(2, 7),
            title: ev.title || 'اعلان', body: ev.body || '', type: ev.type || 'info', tab: ev.tab || null,
            at: Date.now(), read: false,
        });
        if (items.length > MAX_ITEMS) items.length = MAX_ITEMS;
        save(); render();
    }

    window.NotifBell = { init, add, setServerItems };
})();
