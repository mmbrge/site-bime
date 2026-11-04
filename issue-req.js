// فایل: issue-req.js
// «درخواستِ صدور» از هشدارهای داشبورد: برای یک یا چند ردیف (شرکتی C / کارکنان P) به یک یا چند نفر.
// گیرنده‌ها را مدیر کل برای هر کاربر در «دسترسی‌ها» تعیین می‌کند؛ درخواست در «کارهای من»ِ گیرنده با نامِ فرستنده می‌آید
// و با صدورِ بیمه‌نامه خودکار «انجام شد» می‌شود. فرستنده وضعیت را روی همان ردیف‌ها و در «کارهای من» (ارسالی‌ها) می‌بیند.
// API: api/staff_tasks_actions.php (ir_targets, ir_send, ir_for_rows, ir_sent, ir_cancel)
(function () {
    'use strict';
    if (window.IssueReq) return;
    const API = 'api/staff_tasks_actions.php';
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => FA[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    async function post(b) { try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(b)})).json(); } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; } }
    let targets = null;
    async function loadTargets() {
        if (targets) return targets;
        const r = await post({action: 'ir_targets'});
        targets = r.ok ? (r.users || []) : [];
        return targets;
    }
    const ST = {OPEN: ['#f1f5f9', '#475569', 'fa-hourglass-half'], DOING: ['#dbeafe', '#1d4ed8', 'fa-person-running'], DONE: ['#dcfce7', '#15803d', 'fa-circle-check']};
    function chip(x) {
        const s = ST[x.status] || ST.OPEN;
        return `<span class="ir-chip" style="background:${s[0]};color:${s[1]}" title="${esc('از طرفِ ' + (x.by || '') + ' · ' + fa(x.at || ''))}"><i class="fas ${s[2]}"></i>${esc(x.to || '')}: ${esc(x.status_fa || '')}</span>`;
    }
    function css() {
        if (document.getElementById('ir-css')) return;
        const s = document.createElement('style'); s.id = 'ir-css';
        s.textContent = `.ir-chip{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:1px 8px;font-size:10px;font-weight:800;margin-left:4px}
        .ir-btn{border-radius:9px;padding:3px 8px;font-size:10px;font-weight:800;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;white-space:nowrap}
        .ir-btn:hover{background:#d1fae5}.ir-cb{width:16px;height:16px;accent-color:#059669;flex:none}
        .ir-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 12px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;font-size:11px;font-weight:800;color:#065f46}
        .ir-ov{position:fixed;inset:0;z-index:1000006;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:14px}
        .ir-box{width:min(560px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:22px;box-shadow:0 40px 80px -30px rgba(0,0,0,.5)}
        .ir-u{display:flex;align-items:center;gap:6px;border:1.5px solid #e2e8f0;border-radius:12px;padding:6px 10px;font-size:12px;font-weight:700;cursor:pointer}
        .ir-u:has(input:checked){border-color:#059669;background:#ecfdf5}`;
        document.head.appendChild(s);
    }
    // rows: [{src:'C'|'P', id, label}]
    async function send(rows, onDone) {
        css();
        rows = (rows || []).filter(r => r && r.id);
        if (!rows.length) return toast('ردیفی انتخاب نشده.', 'warning');
        const us = await loadTargets();
        if (!us.length) return toast('برای فرستادنِ درخواستِ صدور گیرنده‌ای برایتان تعیین نشده؛ از مدیر کل بخواهید در «دسترسی‌ها» تنظیم کند.', 'warning');
        const ov = document.createElement('div'); ov.className = 'ir-ov';
        ov.innerHTML = `<div class="ir-box" dir="rtl"><div class="p-4 text-white rounded-t-[22px]" style="background:linear-gradient(120deg,#065f46,#059669 60%,#0891b2)">
                <b class="text-[15px]"><i class="fas fa-paper-plane ml-1"></i>درخواستِ صدور</b><p class="text-[11px] opacity-85 mt-0.5">${fa(rows.length)} ردیف · در «کارهای من»ِ گیرنده با نامِ شما می‌آید و با صدورِ بیمه‌نامه خودکار «انجام شد» می‌شود.</p></div>
            <div class="p-4 space-y-3">
                <div class="max-h-28 overflow-auto text-[11px] text-slate-600 bg-slate-50 rounded-xl p-2">${rows.map(r => `<div>• ${r.label || ('#' + fa(r.id))}</div>`).join('')}</div>
                <div><p class="text-[11px] font-black text-slate-600 mb-1.5">برای چه کسانی؟</p><div class="flex flex-wrap gap-1.5">${us.map(u => `<label class="ir-u"><input type="checkbox" class="accent-emerald-600" value="${u.id}">${esc(u.name || '')}</label>`).join('')}</div></div>
                <label class="block text-[11px] font-black text-slate-600">توضیح (اختیاری)<textarea data-note rows="2" class="w-full border border-slate-200 rounded-xl p-2 mt-1 text-[12px]" placeholder="مثلاً: قبل از سررسید صادر شود"></textarea></label>
                <label class="block text-[11px] font-black text-slate-600">مهلت (اختیاری)<input data-due data-jdate dir="ltr" class="w-40 border border-slate-200 rounded-xl p-2 mt-1 text-[12px] block" placeholder="۱۴۰۵/۰۸/۲۰"></label>
                <div class="flex gap-2 justify-end pt-1"><button data-x class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-xs font-bold">انصراف</button>
                    <button data-go class="px-5 py-2 rounded-xl text-white text-xs font-black" style="background:linear-gradient(120deg,#047857,#0891b2)"><i class="fas fa-paper-plane ml-1"></i>فرستادن</button></div></div></div>`;
        document.body.appendChild(ov);
        const close = () => ov.remove();
        ov.querySelector('[data-x]').onclick = close;
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        ov.querySelector('[data-go]').onclick = async () => {
            const to = [...ov.querySelectorAll('.ir-u input:checked')].map(c => Number(c.value));
            if (!to.length) return toast('دست‌کم یک نفر را انتخاب کنید.', 'warning');
            const b = ov.querySelector('[data-go]'); b.disabled = true;
            const r = await post({action: 'ir_send', to, rows: rows.map(x => ({src: x.src, id: x.id})), note: ov.querySelector('[data-note]').value.trim(),
                                  due_date: String(ov.querySelector('[data-due]').value || '').replace(/[۰-۹]/g, d => FA.indexOf(d))});
            b.disabled = false;
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            close();
            toast(`درخواستِ صدور فرستاده شد (${fa(r.made)} مورد${r.skipped ? '، ' + fa(r.skipped) + ' مورد تکراری یا صادرشده کنار گذاشته شد' : ''}).`, 'success');
            if (onDone) onDone();
        };
    }
    // نشان دادنِ وضعیتِ درخواست‌ها روی ردیف‌ها: هر عنصر با data-ir="C:12" یک جای [data-ir-st] دارد
    async function decorate(root) {
        css();
        const els = [...(root || document).querySelectorAll('[data-ir]')];
        if (!els.length) return;
        const q = {C: [], P: []};
        els.forEach(e => { const [s, id] = e.dataset.ir.split(':'); (q[s] = q[s] || []).push(Number(id)); });
        const r = await post(Object.assign({action: 'ir_for_rows'}, q));
        if (!r.ok) return;
        els.forEach(e => {
            const [s, id] = e.dataset.ir.split(':');
            const list = ((r.map || {})[s] || {})[id] || [];
            const box = e.querySelector('[data-ir-st]');
            if (box) box.innerHTML = list.slice(0, 4).map(chip).join('');
        });
    }
    // «ارسالی‌های من» برای «کارهای من»
    async function sentHtml() {
        const r = await post({action: 'ir_sent'});
        if (!r.ok || !(r.items || []).length) return '';
        const open = r.items.filter(t => t.status === 'OPEN' || t.status === 'DOING').length;
        return `<details class="mt-5" ${open ? 'open' : ''}><summary class="text-xs font-black text-emerald-700 cursor-pointer"><i class="fas fa-paper-plane ml-1"></i>درخواست‌های صدوری که فرستاده‌ام (${fa(r.items.length)} · باز ${fa(open)})</summary>
            <div class="grid gap-2 md:grid-cols-2 mt-3">${r.items.map(t => { const s = ST[t.status] || ['#f1f5f9', '#94a3b8', 'fa-ban']; return `<div class="bg-white border border-slate-200 rounded-2xl p-3 text-[11px]">
                <b class="text-slate-800 text-[12px]">${esc(t.title)}</b>
                <p class="text-slate-500 mt-0.5"><i class="fas fa-user ml-1"></i>برای ${esc(t.assignee)} · ${fa(t.created_j)}</p>
                <div class="flex flex-wrap items-center gap-1.5 mt-1.5"><span class="ir-chip" style="background:${s[0]};color:${s[1]}"><i class="fas ${s[2]}"></i>${esc(t.status_fa)}</span>
                    ${t.done_j ? `<span class="text-emerald-700 font-bold">${fa(t.done_j)}</span>` : ''}${t.done_note ? `<span class="text-slate-500">${esc(t.done_note)}</span>` : ''}
                    ${t.ref_src ? `<button class="ir-btn" data-perm-skip onclick="IssueReq.openRef('${t.ref_src}', ${Number(t.ref_id)})"><i class="fas fa-up-right-from-square ml-1"></i>ردیف</button>` : ''}
                    ${t.status === 'OPEN' || t.status === 'DOING' ? `<button class="ir-btn" data-perm-skip style="background:#fff1f2;color:#be123c;border-color:#fecdd3" onclick="IssueReq.cancel(${t.id}, this)">لغو</button>` : ''}</div></div>`; }).join('')}</div></details>`;
    }
    // «همه‌ی درخواست‌های صدور» (مدیر کل / مدیر عامل / مدیرِ کارهای پرسنل): فرستنده ← گیرنده، وضعیت، جستجو
    async function allHtml() {
        const r = await post({action: 'ir_all'});
        if (!r.ok || !(r.items || []).length) return '';
        const open = r.items.filter(t => t.status === 'OPEN' || t.status === 'DOING').length;
        const card = t => { const s = ST[t.status] || ['#f1f5f9', '#94a3b8', 'fa-ban']; return `<div class="ir-all-c bg-white border border-slate-200 rounded-2xl p-3 text-[11px]" data-st="${esc(t.status)}" data-q="${esc((t.title + ' ' + t.creator + ' ' + t.assignee).toLowerCase())}">
                <b class="text-slate-800 text-[12px]">${esc(t.title)}</b>
                <p class="text-slate-500 mt-0.5"><i class="fas fa-user-pen ml-1"></i>${esc(t.creator || '—')} <i class="fas fa-arrow-left mx-1 text-slate-300"></i> <i class="fas fa-user ml-1"></i>${esc(t.assignee || '—')} · ${fa(t.created_j)}</p>
                <div class="flex flex-wrap items-center gap-1.5 mt-1.5"><span class="ir-chip" style="background:${s[0]};color:${s[1]}"><i class="fas ${s[2]}"></i>${esc(t.status_fa)}</span>
                    ${t.overdue ? '<span class="ir-chip" style="background:#fee2e2;color:#b91c1c">مهلت گذشته</span>' : ''}${t.due_j ? `<span class="text-slate-500">مهلت ${fa(t.due_j)}</span>` : ''}
                    ${t.done_j ? `<span class="text-emerald-700 font-bold">${fa(t.done_j)}</span>` : ''}${t.done_note ? `<span class="text-slate-500">${esc(t.done_note)}</span>` : ''}
                    ${t.ref_src ? `<button class="ir-btn" data-perm-skip onclick="IssueReq.openRef('${t.ref_src}', ${Number(t.ref_id)})"><i class="fas fa-up-right-from-square ml-1"></i>ردیف</button>` : ''}
                    ${r.can_cancel && (t.status === 'OPEN' || t.status === 'DOING') ? `<button class="ir-btn" data-perm-skip style="background:#fff1f2;color:#be123c;border-color:#fecdd3" onclick="IssueReq.cancel(${t.id}, this)">لغو</button>` : ''}</div></div>`; };
        return `<details class="mt-5 ir-all" ${open ? 'open' : ''}><summary class="text-xs font-black text-indigo-700 cursor-pointer"><i class="fas fa-list-check ml-1"></i>همه‌ی درخواست‌های صدور (${fa(r.items.length)} · باز ${fa(open)}) — ${fa(r.days)} روزِ اخیر و همه‌ی بازها</summary>
            <div class="flex flex-wrap gap-2 mt-3"><input class="border border-slate-200 rounded-xl px-3 py-1.5 text-[12px] flex-1" style="min-width:200px" placeholder="جستجو: فرستنده، گیرنده، پلاک…" oninput="IssueReq.allFilter(this)">
                <select class="border border-slate-200 rounded-xl px-2 py-1.5 text-[12px]" onchange="IssueReq.allFilter(this)"><option value="">همه‌ی وضعیت‌ها</option><option value="OPEN">در انتظار</option><option value="DOING">در حالِ انجام</option><option value="DONE">انجام شد</option><option value="CANCELLED">لغو شد</option></select></div>
            <div class="grid gap-2 md:grid-cols-2 mt-3">${r.items.map(card).join('')}</div></details>`;
    }
    function allFilter(el) {
        const box = el.closest('.ir-all'); if (!box) return;
        const q = (box.querySelector('input').value || '').trim().toLowerCase(), st = box.querySelector('select').value;
        box.querySelectorAll('.ir-all-c').forEach(c => { c.style.display = (!q || c.dataset.q.indexOf(q) >= 0) && (!st || c.dataset.st === st) ? '' : 'none'; });
    }
    async function openRef(src, id) {
        if (src === 'P') { if (window.dashOpenCase) dashOpenCase(id); return; }
        if (window.IssuePanel) { IssuePanel.open({type: 'C', id}); return; }
    }
    async function cancel(id, btn) {
        if (window.uiConfirm && !(await uiConfirm('لغوِ درخواست', 'این درخواستِ صدور لغو شود؟', {ok: 'لغو شود', danger: true}))) return;
        const r = await post({action: 'ir_cancel', id});
        if (!r.ok) return toast(r.error || 'خطا', 'error');
        const card = btn && btn.closest('div.bg-white'); if (card) card.style.opacity = '.5';
        toast('لغو شد.', 'success');
    }
    window.IssueReq = {send, decorate, sentHtml, allHtml, allFilter, openRef, cancel, chip};
})();
