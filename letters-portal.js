// فایل: letters-portal.js
// «اتوماسیون نامه‌ها» در پنلِ شرکت: فهرستِ نامه‌هایی که «بیمه با ما» برای شرکت فرستاده (جدید، منتظرِ پاسخ، پاسخ‌داده‌شده، فوری)،
// دیدنِ نامه (متن، PDF با سربرگ، نسخه‌ی امضاشده، پیوست‌ها، پاسخ‌های قبلی) و فقط «پاسخ»: متن + بارگذاریِ نامه/فایل + دسته‌بندی.
(function () {
    'use strict';
    const API = '../api/lt_portal_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = v => (v === null || v === undefined) ? '' : String(v).replace(/\d/g, d => FA[d]);
    const en = v => String(v ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d));
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = m => (typeof showToast === 'function' ? showToast(m) : alert(m));
    const kb = n => n > 1048576 ? fa((n / 1048576).toFixed(1)) + ' مگابایت' : fa(Math.max(1, Math.round(n / 1024))) + ' کیلوبایت';
    const P = {root: null, boot: null, f: {filter: 'all', q: '', company_id: '', category_id: ''}};
    async function api(action, body = {}, form = null) {
        let res;
        try {
            if (form) { form.append('action', action); res = await fetch(API, {method: 'POST', body: form, credentials: 'same-origin'}); }
            else res = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(Object.assign({action}, body))});
        } catch (e) { throw new Error('اتصال به سرور برقرار نشد.'); }
        const j = await res.json().catch(() => ({ok: false, error: 'پاسخِ نامعتبر از سرور'}));
        if (!j.ok) throw new Error(j.error || 'خطا');
        return j;
    }
    const url = (action, o = {}) => API + '?' + Object.entries(Object.assign({action}, o)).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&');
    const STYLE = `
    .ltp{direction:rtl;font-size:12.5px;color:#0f172a}.ltp *{box-sizing:border-box}
    .ltp-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
    .ltp-in,.ltp-sel,.ltp-ta{border:1.5px solid #dbe3ee;border-radius:14px;padding:10px 12px;font-size:12.5px;font-family:inherit;background:#fff;width:100%;color:#0f172a;outline:none}
    .ltp-in:focus,.ltp-sel:focus,.ltp-ta:focus{border-color:#3b82f6;box-shadow:0 0 0 4px rgba(59,130,246,.1)}
    .ltp-chips{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;margin-bottom:12px}
    .ltp-chips button{flex-shrink:0;border:1.5px solid #dbe3ee;background:#fff;color:#475569;font-family:inherit;font-weight:800;font-size:11.5px;border-radius:12px;padding:7px 11px;display:inline-flex;gap:6px;align-items:center;cursor:pointer}
    .ltp-chips button.on{border-color:#4338ca;background:#eef2ff;color:#3730a3}.ltp-chips i{font-style:normal;font-size:10px;background:#f1f5f9;border-radius:8px;padding:1px 6px}
    .ltp-chips button.red i{background:#ef4444;color:#fff}
    .ltp-list{display:flex;flex-direction:column;gap:10px}
    .ltp-card{background:#fff;border:1.5px solid #dbe3ee;border-radius:20px;padding:13px 15px;cursor:pointer;display:flex;gap:12px;align-items:flex-start;transition:.15s;position:relative}
    .ltp-card:hover{border-color:#a5b4fc;box-shadow:0 14px 30px -24px rgba(67,56,202,.6)}
    .ltp-card.unread{border-color:#818cf8;background:linear-gradient(90deg,#fff,#f5f7ff)}.ltp-card.unread .s{font-weight:900}
    .ltp-card .av{width:44px;height:44px;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;background:#eef2ff;color:#4338ca}
    .ltp-card .dot{position:absolute;top:12px;left:12px;width:10px;height:10px;border-radius:50%;background:#4338ca;box-shadow:0 0 0 4px #e0e7ff}
    .ltp-tag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;border-radius:9px;padding:2px 8px;background:#f1f5f9;color:#475569;white-space:nowrap}
    .ltp-tag.r{background:#ffe4e6;color:#9f1239}.ltp-tag.g{background:#dcfce7;color:#166534}.ltp-tag.a{background:#fef3c7;color:#92400e}.ltp-tag.b{background:#e0e7ff;color:#3730a3}.ltp-tag.v{background:#ede9fe;color:#5b21b6}
    .ltp-num{direction:ltr;unicode-bidi:isolate;font-weight:900;color:#312e81}
    .ltp-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:13px;padding:9px 14px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap}
    .ltp-btn.p{background:linear-gradient(120deg,#4338ca,#0891b2);color:#fff}.ltp-btn.s{background:#f1f5f9;color:#334155}.ltp-btn.o{background:#fff;border:1.5px solid #dbe3ee;color:#334155}.ltp-btn:disabled{opacity:.5}
    .ltp-ov{position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);display:flex;align-items:flex-start;justify-content:center;padding:20px 10px;overflow:auto}
    .ltp-md{background:#f8fafc;border-radius:24px;width:100%;max-width:900px;box-shadow:0 30px 60px -25px rgba(0,0,0,.5)}
    .ltp-mh{display:flex;align-items:center;gap:10px;padding:14px 16px;background:#fff;border-radius:24px 24px 0 0;border-bottom:1px solid #eef0f6}
    .ltp-mh h3{font-size:14px;font-weight:900;flex:1;min-width:0}.ltp-x{width:36px;height:36px;border-radius:12px;background:#f1f5f9;border:0;cursor:pointer;font-size:16px;flex-shrink:0}
    .ltp-mb{padding:14px 16px;display:flex;flex-direction:column;gap:12px}
    .ltp-paper{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:22px 24px;line-height:2.1;font-size:13.5px;box-shadow:0 10px 26px -22px rgba(15,23,42,.5)}
    .ltp-paper p{margin:0 0 .4em}.ltp-paper table{border-collapse:collapse;width:100%}.ltp-paper td,.ltp-paper th{border:1px solid #cbd5e1;padding:4px 7px}.ltp-paper table[data-lt-sign] td{border:0}
    .ltp-paper ul{list-style:disc;padding-right:22px}.ltp-paper ol{list-style:decimal;padding-right:22px}
    .ltp-meta{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:11.5px;color:#475569;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:10px 14px}.ltp-meta b{color:#0f172a}
    .ltp-box{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:13px 14px}.ltp-box h4{font-size:12.5px;font-weight:900;margin-bottom:8px;display:flex;align-items:center;gap:6px}
    .ltp-file{display:flex;align-items:center;gap:8px;border:1px solid #eef0f6;border-radius:12px;padding:7px 10px;font-size:11.5px;margin-bottom:5px}.ltp-file .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:800}
    .ltp-rep{border-right:4px solid #0891b2;background:#f0fdff;border-radius:14px;padding:10px 12px;margin-bottom:8px}.ltp-rep.us{border-right-color:#4338ca;background:#f5f7ff}
    .ltp-drop{border:2px dashed #c7d2fe;border-radius:16px;padding:14px;text-align:center;cursor:pointer;background:#fff;color:#4338ca;font-weight:800;display:block}
    .ltp-empty{text-align:center;color:#94a3b8;padding:40px 10px;font-weight:700;background:#fff;border:1.5px solid #dbe3ee;border-radius:20px}
    .ltp-tg{display:flex;gap:4px;background:#f1f5f9;border-radius:12px;padding:3px}.ltp-tg button{flex:1;border:0;background:transparent;border-radius:9px;padding:7px;font-family:inherit;font-weight:800;font-size:11.5px;cursor:pointer;color:#475569}
    .ltp-tg button.on{background:#fff;color:#3730a3;box-shadow:0 2px 6px -3px rgba(0,0,0,.3)}
    @media (max-width:640px){.ltp-ov{padding:0;align-items:flex-end}.ltp-md{border-radius:22px 22px 0 0;max-height:95vh;overflow:auto}.ltp-paper{padding:16px}}`;
    const css = () => { if (!document.getElementById('ltp-css')) { const s = document.createElement('style'); s.id = 'ltp-css'; s.textContent = STYLE; document.head.appendChild(s); } };
    const catOf = id => (P.boot ? P.boot.all_categories : []).find(c => c.id === id);
    const catTag = id => { const c = catOf(id); return c ? `<span class="ltp-tag" style="background:${c.color}1a;color:${c.color}">${esc(c.name)}</span>` : ''; };
    const stTag = r => `<span class="ltp-tag ${r.status === 'REPLIED' ? 'g' : (r.unread ? 'b' : '')}">${esc(r.status_fa)}</span>${r.overdue ? '<span class="ltp-tag r">مهلتِ پاسخ گذشته</span>' : ''}`;

    async function boot() { if (!P.boot) P.boot = await api('bootstrap'); return P.boot; }
    async function badge() {
        try { const r = await api('unread'); const b = document.getElementById('pt-letters-badge'); if (b) { b.textContent = fa(r.unread); b.classList.toggle('hidden', !r.unread); } } catch (e) {}
    }
    async function open(root) {
        css();
        P.root = root;
        try { await boot(); } catch (e) { root.innerHTML = `<div class="ltp-empty">${esc(e.message)}</div>`; return; }
        const B = P.boot;
        root.innerHTML = `<div class="ltp"><div class="ltp-top">
                <div style="flex:1;min-width:200px"><input class="ltp-in" data-q placeholder="جستجو در شماره یا موضوعِ نامه…" value="${esc(P.f.q)}"></div>
                ${B.companies.length > 1 ? `<select class="ltp-sel" data-co style="max-width:220px"><option value="">همه‌ی شرکت‌ها</option>${B.companies.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>` : ''}
            </div><div class="ltp-chips" data-chips></div><div class="ltp-list" data-list><div class="ltp-empty">در حال بارگذاری…</div></div></div>`;
        let tm;
        root.querySelector('[data-q]').oninput = e => { clearTimeout(tm); tm = setTimeout(() => { P.f.q = e.target.value; load(); }, 300); };
        const co = root.querySelector('[data-co]'); if (co) co.onchange = () => { P.f.company_id = co.value; load(); };
        load();
    }
    async function load() {
        const root = P.root;
        if (!root || !root.querySelector('[data-list]')) return;
        let r;
        try { r = await api('list', P.f); } catch (e) { root.querySelector('[data-list]').innerHTML = `<div class="ltp-empty">${esc(e.message)}</div>`; return; }
        const C = r.counts || {};
        root.querySelector('[data-chips]').innerHTML = [['all', 'همه', C.n_all], ['new', 'جدید (خوانده‌نشده)', C.n_new, 'red'], ['reply', 'منتظرِ پاسخِ شما', C.n_reply], ['replied', 'پاسخ داده‌شده', C.n_replied], ['urgent', 'فوری', C.n_urgent]]
            .map(([k, l, n, cl]) => `<button class="${P.f.filter === k ? 'on' : ''} ${cl && n ? cl : ''}" data-fl="${k}">${l}<i>${fa(n || 0)}</i></button>`).join('');
        root.querySelectorAll('[data-fl]').forEach(b => b.onclick = () => { P.f.filter = b.dataset.fl; load(); });
        const list = root.querySelector('[data-list]');
        if (!r.rows.length) { list.innerHTML = '<div class="ltp-empty"><div style="font-size:30px">📭</div>نامه‌ای در این بخش نیست.</div>'; return; }
        list.innerHTML = r.rows.map(x => `<div class="ltp-card ${x.unread ? 'unread' : ''}" data-id="${x.id}">${x.unread ? '<span class="dot"></span>' : ''}
            <span class="av">${x.priority !== 'normal' ? '⚡' : (x.status === 'REPLIED' ? '✅' : '✉️')}</span>
            <div style="flex:1;min-width:0"><div class="flex flex-wrap gap-2 items-center"><span class="ltp-num">${fa(x.number)}</span><span class="text-[11px] text-slate-500">${fa(x.letter_j)}</span>${P.boot.companies.length > 1 ? `<span class="text-[11px] text-slate-500">· ${esc(x.company_name)}</span>` : ''}</div>
                <div class="s text-[13.5px] mt-1" style="font-weight:800">${esc(x.subject)}</div>
                <div class="flex flex-wrap gap-1 mt-2">${stTag(x)}${catTag(x.category_id)}${x.priority !== 'normal' ? `<span class="ltp-tag r">${esc(x.priority_fa)}</span>` : ''}${x.conf !== 'normal' ? `<span class="ltp-tag v">${esc(x.conf_fa)}</span>` : ''}
                ${x.needs_reply && x.status !== 'REPLIED' ? `<span class="ltp-tag a">پاسخ${x.reply_due_j ? ' تا ' + fa(x.reply_due_j) : ' لازم است'}</span>` : ''}${x.files_n ? `<span class="ltp-tag">📎 ${fa(x.files_n)}</span>` : ''}${x.replies_n ? `<span class="ltp-tag g">↩ ${fa(x.replies_n)} پاسخ</span>` : ''}</div></div></div>`).join('');
        list.querySelectorAll('[data-id]').forEach(c => c.onclick = () => detail(+c.dataset.id));
    }
    function modal(title, body) {
        const ov = document.createElement('div');
        ov.className = 'ltp ltp-ov';
        ov.innerHTML = `<div class="ltp-md"><div class="ltp-mh"><h3>${title}</h3><button class="ltp-x" data-x>✕</button></div><div class="ltp-mb">${body}</div></div>`;
        const close = () => ov.remove();
        ov.addEventListener('mousedown', e => { ov._d = e.target === ov; });
        ov.addEventListener('click', e => { if (e.target === ov && ov._d) close(); });
        ov.querySelector('[data-x]').onclick = close;
        document.body.appendChild(ov);
        ov.close = close;
        return ov;
    }
    async function detail(id) {
        css(); await boot();
        let L;
        try { L = (await api('detail', {id})).letter; } catch (e) { toast(e.message); return; }
        const fileRow = f => `<div class="ltp-file">📄<span class="n" title="${esc(f.name)}">${esc(f.name)}</span><span class="text-[10px] text-slate-400">${kb(f.size)}</span>
            <a class="ltp-btn s" style="padding:5px 9px" href="${url('file', {id: f.id, inline: 1})}" target="_blank">مشاهده</a><a class="ltp-btn s" style="padding:5px 9px" href="${url('file', {id: f.id})}">دانلود</a></div>`;
        const ours = L.files.filter(f => !f.mine);
        const cats = P.boot.categories;
        const m = modal(`<span class="ltp-num">${fa(L.number)}</span> - ${esc(L.subject)}`, `
            <div class="flex flex-wrap gap-2">${L.direction === 'OUT' ? `<a class="ltp-btn p" href="${url('pdf', {id, inline: 1})}" target="_blank">📄 نامه با سربرگ (PDF)</a><a class="ltp-btn o" href="${url('pdf', {id})}">⬇ دانلودِ PDF</a>` : ''}
                ${L.has_signed ? `<a class="ltp-btn o" href="${url('signed', {id, inline: 1})}" target="_blank">✍ نسخه‌ی امضاشده</a>` : ''}${L.direction === 'OUT' ? '<button class="ltp-btn o" data-goreply>↩ پاسخ به این نامه</button>' : ''}</div>
            <div class="ltp-meta"><span>شماره: <b class="ltp-num">${fa(L.number)}</b></span><span>تاریخ: <b>${fa(L.letter_j)}</b></span><span>شرکت: <b>${esc(L.company_name)}</b></span>${L.attach_note ? `<span>پیوست: <b>${esc(L.attach_note)}</b></span>` : ''}
                <span>وضعیت: ${stTag(L)}</span>${catTag(L.category_id)}${L.priority !== 'normal' ? `<span class="ltp-tag r">${esc(L.priority_fa)}</span>` : ''}${L.needs_reply ? `<span class="ltp-tag a">مهلتِ پاسخ: ${L.reply_due_j ? fa(L.reply_due_j) : '—'}</span>` : ''}</div>
            <div class="ltp-paper">${L.html || '<span class="text-slate-400">متنی ندارد.</span>'}</div>
            ${ours.length ? `<div class="ltp-box"><h4>📎 پیوست‌ها</h4>${ours.map(fileRow).join('')}</div>` : ''}
            ${L.replies.length ? `<div class="ltp-box"><h4>↩ پاسخ‌ها و نامه‌های بعدی</h4>${L.replies.map(r => `<div class="ltp-rep ${r.direction === 'OUT' ? 'us' : ''}"><div class="flex flex-wrap gap-2 items-center"><b>${r.direction === 'OUT' ? 'بیمه با ما' : esc(r.by)}</b><span class="ltp-num">${fa(r.number)}</span><span class="text-[11px] text-slate-500">${fa(r.created_at_j || r.sent_at_j)}</span>${catTag(r.category_id)}</div>
                <div class="font-bold mt-1">${esc(r.subject)}</div>${r.text ? `<div class="text-[12.5px] mt-1 leading-7" style="white-space:pre-wrap">${esc(r.text)}</div>` : ''}${r.files.length ? '<div class="mt-2">' + r.files.map(fileRow).join('') + '</div>' : ''}
                ${r.direction === 'OUT' ? `<button class="ltp-btn s mt-2" data-open="${r.id}">مشاهده‌ی نامه</button>` : ''}</div>`).join('')}</div>` : ''}
            ${L.direction === 'OUT' ? `<div class="ltp-box" data-reply><h4>✍ پاسخ به این نامه</h4>
                <div class="flex flex-col gap-2">
                    <label><span class="text-[11px] font-bold text-slate-500">موضوعِ پاسخ</span><input class="ltp-in" data-k="subject" value="${esc('پاسخ به نامه‌ی ' + fa(L.number) + ' - ' + L.subject)}"></label>
                    <div class="grid gap-2" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
                        <label><span class="text-[11px] font-bold text-slate-500">دسته‌بندی</span><select class="ltp-sel" data-k="category_id">${cats.map(c => `<option value="${c.id}" ${c.id === L.category_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
                        <label><span class="text-[11px] font-bold text-slate-500">شماره‌ی نامه‌ی شما (اختیاری)</span><input class="ltp-in" data-k="ext_number" dir="ltr"></label>
                        <label><span class="text-[11px] font-bold text-slate-500">تاریخِ نامه‌ی شما (اختیاری)</span><input class="ltp-in" data-k="ext_j" dir="ltr" placeholder="۱۴۰۵/۰۷/۲۰"></label>
                    </div>
                    <div><span class="text-[11px] font-bold text-slate-500">فوریت</span><div class="ltp-tg" data-prio><button class="on" data-v="normal">عادی</button><button data-v="urgent">فوری</button></div></div>
                    <label><span class="text-[11px] font-bold text-slate-500">متنِ پاسخ</span><textarea class="ltp-ta" rows="5" data-k="text" placeholder="متنِ پاسخ را بنویسید (یا فقط فایلِ نامه را بارگذاری کنید)"></textarea></label>
                    <label class="ltp-drop" data-drop>⬆ فایلِ نامه یا مدارک را این‌جا رها کنید یا بزنید<br><small class="text-slate-500 font-normal">PDF، تصویر، Word، Excel یا ZIP - حداکثر ۱۰ فایل</small><input type="file" multiple hidden data-files></label>
                    <div data-picked></div>
                    <div><button class="ltp-btn p" data-send>📨 ارسالِ پاسخ</button></div>
                </div></div>` : ''}`);
        m.querySelectorAll('[data-open]').forEach(b => b.onclick = () => { m.close(); detail(+b.dataset.open); });
        badge(); load();
        const rep = m.querySelector('[data-reply]');
        if (!rep) return;
        const go = m.querySelector('[data-goreply]'); if (go) go.onclick = () => rep.scrollIntoView({behavior: 'smooth', block: 'start'});
        let prio = 'normal';
        rep.querySelectorAll('[data-prio] button').forEach(b => b.onclick = () => { prio = b.dataset.v; rep.querySelectorAll('[data-prio] button').forEach(x => x.classList.toggle('on', x === b)); });
        const files = [];
        const drawPicked = () => { rep.querySelector('[data-picked]').innerHTML = files.map((f, i) => `<div class="ltp-file">📎<span class="n">${esc(f.name)}</span><span class="text-[10px] text-slate-400">${kb(f.size)}</span><button class="ltp-btn s" style="padding:4px 8px" data-rm="${i}">✕</button></div>`).join('');
            rep.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { files.splice(+b.dataset.rm, 1); drawPicked(); }); };
        rep.querySelector('[data-files]').onchange = e => { [...e.target.files].forEach(f => files.length < 10 && files.push(f)); e.target.value = ''; drawPicked(); };
        const dz = rep.querySelector('[data-drop]');
        dz.addEventListener('dragover', e => { e.preventDefault(); dz.style.background = '#eef2ff'; });
        dz.addEventListener('dragleave', () => { dz.style.background = '#fff'; });
        dz.addEventListener('drop', e => { e.preventDefault(); dz.style.background = '#fff'; [...e.dataTransfer.files].forEach(f => files.length < 10 && files.push(f)); drawPicked(); });
        rep.querySelector('[data-send]').onclick = async e => {
            const b = e.currentTarget;
            const v = k => rep.querySelector(`[data-k=${k}]`).value;
            if (!v('text').trim() && !files.length) { toast('متنِ پاسخ را بنویسید یا فایلی بارگذاری کنید.'); return; }
            const fd = new FormData();
            fd.append('parent_id', id); fd.append('subject', v('subject')); fd.append('category_id', v('category_id')); fd.append('text', v('text'));
            fd.append('ext_number', en(v('ext_number'))); fd.append('ext_j', en(v('ext_j'))); fd.append('priority', prio);
            files.forEach(f => fd.append('files[]', f));
            b.disabled = true; b.textContent = 'در حال ارسال…';
            try { const r = await api('reply', {}, fd); m.close(); toast('پاسخ ارسال شد. شماره‌ی ثبت در «بیمه با ما»: ' + fa(r.number)); load(); detail(id); }
            catch (er) { toast(er.message); b.disabled = false; b.textContent = '📨 ارسالِ پاسخ'; }
        };
    }
    window.PortalLetters = {open, refresh: () => { badge(); load(); }, badge, detail};
})();
