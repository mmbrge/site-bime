// فایل: psg-issue.js
// «پنلِ صدورِ پاسارگاد» (فازِ ۱): ساختِ پیش‌نویس در سامانه‌ی پاسارگاد از روی ردیفِ درخواست.
//   PsgIssueUI.open(plateId, {title, onDone})     — یک ردیف: آماده‌سازی → پیش‌نمایشِ تغییرات و پیام‌های سامانه → تأیید → ثبت
//   PsgIssueUI.group(rows, {onDone})               — چند ردیف پشتِ سرِ هم با همان نمونه/الگو (توقف روی خطای قرمز)
// اولویت: «کپی از پیش‌نویسِ نمونه» (شماره‌ی هم‌رشته‌ی همان شرکت؛ خودکار پیشنهاد می‌شود) و اگر نبود «الگوی یادگرفته».
// API: api/psg_issue_actions.php
(function () {
    'use strict';
    if (window.PsgIssueUI) return;
    const API = 'api/psg_issue_actions.php';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t || 'info') : alert(m));
    async function post(body) {
        try { const r = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}); return await r.json(); }
        catch (e) { return {ok: false, error: 'ارتباط با سرور برقرار نشد.'}; }
    }
    function css() {
        if (document.getElementById('psgi-css')) return;
        const s = document.createElement('style'); s.id = 'psgi-css';
        s.textContent = `.psgi-ov{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10060;display:flex;align-items:center;justify-content:center;padding:12px}
.psgi-box{background:#fff;border-radius:20px;width:min(860px,100%);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(0,0,0,.45);direction:rtl}
.psgi-hd{background:linear-gradient(120deg,#0f766e,#0369a1);color:#fff;padding:14px 18px;display:flex;align-items:center;gap:10px}
.psgi-bd{padding:14px 18px;overflow:auto;font-size:12.5px;color:#334155}
.psgi-ft{padding:10px 18px;border-top:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f8fafc}
.psgi-btn{border:0;border-radius:11px;padding:8px 14px;font-weight:800;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.psgi-btn[disabled]{opacity:.55;cursor:wait}
.psgi-in{border:1px solid #cbd5e1;border-radius:10px;padding:6px 9px;font-size:12px;outline:none}
.psgi-in:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.15)}
.psgi-card{border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px;margin-bottom:10px}
.psgi-tbl{width:100%;border-collapse:collapse;font-size:11.5px}.psgi-tbl th,.psgi-tbl td{border-bottom:1px solid #f1f5f9;padding:5px 6px;text-align:right;vertical-align:top}
.psgi-tbl th{background:#f8fafc;color:#64748b;font-weight:800;position:sticky;top:0}
.psgi-n{border-radius:10px;padding:6px 10px;margin:4px 0;font-size:11.5px}.psgi-n.y{background:#fefce8;color:#854d0e;border:1px solid #fde68a}.psgi-n.r{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.psgi-n.g{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.psgi-n.i{background:#f1f5f9;color:#475569}
.psgi-chip{display:inline-block;border-radius:999px;padding:2px 9px;font-size:10.5px;font-weight:800}`;
        document.head.appendChild(s);
    }
    function shell(title, sub) {
        css();
        const ov = document.createElement('div'); ov.className = 'psgi-ov';
        ov.innerHTML = `<div class="psgi-box"><div class="psgi-hd"><i class="fas fa-file-signature text-xl"></i><div class="min-w-0 flex-1"><p class="text-[10.5px] opacity-80">پنلِ صدورِ پاسارگاد · ساختِ پیش‌نویس در سامانه</p>
            <h3 class="text-base font-black truncate">${esc(fa(title || ''))}</h3>${sub ? `<p class="text-[11px] opacity-85 truncate">${esc(fa(sub))}</p>` : ''}</div>
            <button class="psgi-btn" style="background:rgba(255,255,255,.18);color:#fff" data-x><i class="fas fa-times"></i></button></div><div class="psgi-bd"></div><div class="psgi-ft"></div></div>`;
        document.body.appendChild(ov);
        const close = () => ov.remove();
        ov.querySelector('[data-x]').onclick = close;
        ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
        return {ov, bd: ov.querySelector('.psgi-bd'), ft: ov.querySelector('.psgi-ft'), close};
    }
    const noteHtml = (n) => {
        if (!n) return '';
        let h = '';
        (n.notes || []).forEach(t => { h += `<div class="psgi-n ${/^⚠️/.test(t) ? 'y' : 'i'}">${esc(fa(t))}</div>`; });
        (n.warnings || []).forEach(t => { h += `<div class="psgi-n y">🟡 ${esc(t)}</div>`; });
        (n.errors || []).forEach(t => { h += `<div class="psgi-n r">🔴 ${esc(t)}</div>`; });
        (n.save || []).forEach(s => {
            (s.warnings || []).forEach(t => { h += `<div class="psgi-n y">ثبتِ ${fa(s.try)}: 🟡 ${esc(t)}</div>`; });
            (s.errors || []).forEach(t => { h += `<div class="psgi-n r">ثبتِ ${fa(s.try)}: 🔴 ${esc(t)}</div>`; });
        });
        return h;
    };
    const jobChip = j => ({DONE: ['پیش‌نویس شد', '#dcfce7', '#166534'], PREPARED: ['آماده (ثبت نشده)', '#e0f2fe', '#075985'], RUNNING: ['در حالِ ثبت', '#fef9c3', '#854d0e'],
                           ERROR: ['ثبت نشد', '#fee2e2', '#991b1b'], LOGIN: ['نیازمندِ ورود', '#fee2e2', '#991b1b']}[j.status] || [j.status, '#f1f5f9', '#475569']);

    async function open(plateId, opts) {
        opts = opts || {};
        const S = {plateId, info: null, job: null, busy: false};
        const ui = shell(opts.title || ('ردیفِ ' + plateId), '');
        ui.bd.innerHTML = '<p class="text-center text-slate-400 py-8"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ خواندن…</p>';
        const info = await post({action: 'suggest', plate_id: plateId});
        if (!info.ok) { ui.bd.innerHTML = `<div class="psgi-n r">${esc(info.error || 'خطا')}</div>`; return; }
        S.info = info;
        if (info.insurer === 'IRAN') { ui.bd.innerHTML = '<div class="psgi-n r">بیمه‌گرِ این درخواست ایران است؛ این پنل فقط برای پاسارگاد است.</div>'; return; }
        renderStart();

        function renderStart() {
            const i = S.info, sm = i.sample, tp = i.template;
            ui.bd.innerHTML = `
                <div class="psgi-card"><div class="flex flex-wrap gap-2 items-center text-[11.5px]">
                    <span class="psgi-chip" style="background:#e0f2fe;color:#075985">${esc(i.kind_fa)}</span>
                    <span>بیمه‌گذار: <b>${esc(fa(i.insured || '—'))}</b>${i.insured_nid ? ` <span class="text-slate-400" dir="ltr">${fa(i.insured_nid)}</span>` : ''}</span>
                    <span>خودرو: <b>${esc(i.car || '—')}</b></span>
                    ${i.draft_no ? `<span class="psgi-chip" style="background:#dcfce7;color:#166534">پیش‌نویس شد: ${esc(fa(i.draft_no))}</span>` : ''}</div></div>
                <div class="psgi-card">
                    <p class="font-black text-slate-700 mb-2"><i class="fas fa-copy ml-1 text-teal-600"></i>اولویتِ ۱ — کپی از پیش‌نویسِ نمونه (هم‌رشته‌ی همین شرکت)</p>
                    <div class="flex flex-wrap gap-2 items-center"><label class="text-[11.5px]">شماره‌ی پیش‌نویسِ نمونه</label>
                        <input class="psgi-in" dir="ltr" style="width:190px" data-sample placeholder="1/49357/1405/523" value="${esc(sm ? sm.draft_no : '')}">
                        ${sm ? `<span class="text-[10.5px] text-slate-500">پیشنهاد از فهرستِ پیش‌نویس‌ها: ${esc(fa(sm.insured))} · ${esc(sm.car || '')} · ${esc(fa(String(sm.created_j || '').slice(0, 10)))}${sm.same_car ? ' · <b class="text-emerald-600">همان خودرو</b>' : ''}</span>` : '<span class="text-[10.5px] text-slate-400">نمونه‌ای در فهرستِ پیش‌نویس‌ها پیدا نشد؛ شماره را دستی بدهید.</span>'}</div>
                    <p class="text-[10.5px] text-slate-400 mt-1">بیمه‌گذار، پوشش‌ها، تخفیف‌ها و تنظیمات از نمونه می‌آید؛ تیپ/نوعِ خودرو/اتاقِ بار از خودِ نمونه (فقط خوانده می‌شود) و الگو؛ پلاک، شاسی، موتور، سال، رنگ و تاریخ‌ها از همین ردیف.</p>
                </div>
                <div class="psgi-card">
                    <p class="font-black text-slate-700 mb-1"><i class="fas fa-wand-magic-sparkles ml-1 text-indigo-600"></i>اولویتِ ۲ — از ابتدا با الگوی یادگرفته</p>
                    <p class="text-[11px] ${tp ? 'text-slate-600' : 'text-slate-400'}">${tp ? `الگو هست (${fa(tp.count)} خانه، از پیش‌نویسِ ${esc(fa(tp.source || '—'))} · ${esc(fa(tp.at || ''))}${tp.car ? ' · مخصوصِ همین خودرو' : ''}). اگر شماره‌ی نمونه را خالی بگذارید، فرمِ «ایجاد» با این الگو پر می‌شود.` : 'هنوز الگویی نیست؛ اولین ثبتِ موفق (با کپی از نمونه) الگو را می‌سازد.'}</p>
                </div>
                ${(i.jobs || []).length ? `<div class="psgi-card"><p class="font-black text-slate-700 mb-1">سابقه‌ی همین ردیف</p>${i.jobs.map(j => { const c = jobChip(j); return `<div class="flex flex-wrap gap-2 items-center text-[11px] py-1 border-b border-slate-100">
                    <span class="psgi-chip" style="background:${c[1]};color:${c[2]}">${c[0]}</span><span>${j.mode === 'copy' ? 'کپی از ' + esc(fa(j.sample || '')) : 'الگو'}</span>
                    ${j.draft_no ? `<b dir="ltr">${esc(fa(j.draft_no))}</b>` : ''}<span class="text-slate-400">${esc(fa(j.at || ''))} · ${esc(j.by || '')}</span>${j.message ? `<span class="text-slate-500">${esc(fa(j.message))}</span>` : ''}
                    ${j.status === 'DONE' && j.cargo_used && j.cargo_orig ? `<button class="psgi-btn" style="background:#fef3c7;color:#92400e;padding:3px 9px" data-restore="${j.id}">برگرداندنِ اتاقِ بار به نوعِ اصلی</button>` : ''}
                    ${j.status === 'DONE' && j.draft_no ? `<button class="psgi-btn" style="background:#e0f2fe;color:#075985;padding:3px 9px" data-up="${j.id}">بارگذاریِ مدارک روی پیش‌نویس</button>` : ''}</div>`; }).join('')}</div>` : ''}`;
            ui.ft.innerHTML = `<button class="psgi-btn text-white" style="background:#0f766e" data-prep><i class="fas fa-magnifying-glass"></i>آماده‌سازی و پیش‌نمایش</button>
                ${i.draft_no ? '<label class="text-[11px] inline-flex items-center gap-1"><input type="checkbox" data-again>این ردیف پیش‌نویس دارد؛ باز هم بساز</label>' : ''}
                <span class="flex-1"></span><span class="text-[10.5px] text-slate-400">در این مرحله چیزی در سامانه ثبت نمی‌شود.</span>`;
            ui.ft.querySelector('[data-prep]').onclick = prepare;
            ui.bd.querySelectorAll('[data-restore]').forEach(b => b.onclick = async () => { b.disabled = true; const r = await post({action: 'restore_cargo', job_id: Number(b.dataset.restore)}); b.disabled = false; toast(r.ok ? 'اتاقِ بار در پیش‌نویس به نوعِ اصلی برگشت.' : (r.error || 'نشد'), r.ok ? 'success' : 'error'); if (r.need_login) login(); });
            ui.bd.querySelectorAll('[data-up]').forEach(b => b.onclick = async () => { b.disabled = true; const r = await post({action: 'upload_docs', job_id: Number(b.dataset.up)}); b.disabled = false; upToast(r); });
        }
        function login() { if (window.Inquiry && Inquiry.login) Inquiry.login(() => {}); else toast('اول از «استعلامات» وارد سامانه‌ی پاسارگاد شوید.', 'warning'); }
        function upToast(r) {
            if (!r.ok) { toast(r.error || 'بارگذاری نشد.', 'error'); if (r.need_login) login(); return; }
            const f = r.files || []; const ok = f.filter(x => x.ok).length;
            toast(f.length ? `${fa(ok)} از ${fa(f.length)} مدرک روی پیش‌نویس بارگذاری شد.` : (r.note || 'مدرکی نبود.'), ok === f.length ? 'success' : 'warning');
        }
        async function prepare() {
            const b = ui.ft.querySelector('[data-prep]'); b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ پر کردنِ فرم روی سامانه…';
            const r = await post({action: 'prepare', plate_id: plateId, sample: (ui.bd.querySelector('[data-sample]') || {}).value || '', again: !!(ui.ft.querySelector('[data-again]') || {}).checked});
            if (!r.ok) {
                b.disabled = false; b.innerHTML = '<i class="fas fa-magnifying-glass"></i>آماده‌سازی و پیش‌نمایش';
                if (r.need_login) { login(); return; }
                toast(r.error || 'آماده نشد.', 'error'); return;
            }
            S.job = r.job; renderPreview();
        }
        function renderPreview() {
            const j = S.job;
            ui.bd.innerHTML = `
                <div class="psgi-card"><p class="font-black text-slate-700 mb-1">${j.mode === 'copy' ? 'کپی از پیش‌نویسِ ' + esc(fa(j.sample)) : 'فرمِ «ایجاد» با الگو'} — پیش‌نمایش (هنوز ثبت نشده)</p>${noteHtml(j.notices) || '<div class="psgi-n g">پیامی از سامانه نیامد.</div>'}</div>
                <div class="psgi-card"><p class="font-black text-slate-700 mb-2">خانه‌هایی که پر/عوض می‌شوند <span class="text-[10.5px] text-slate-400 font-normal">(مقدار را می‌شود همین‌جا اصلاح کرد؛ تیکِ «در الگو» یعنی برای ردیف‌های بعدی هم یاد گرفته شود)</span></p>
                <div style="max-height:46vh;overflow:auto"><table class="no-count psgi-tbl"><thead><tr><th>خانه</th><th>قبلی (نمونه/فرم)</th><th>تازه</th><th class="text-center">در الگو</th></tr></thead><tbody>
                ${(j.changes || []).map((c, k) => `<tr><td>${esc(c.label)}</td><td class="text-slate-400">${esc(fa(c.old || '—'))}</td>
                    <td>${/^(rang|seryalPelak|shomarehEntezami2|otaghBar|noePelak|moredEstefadehKhodro)$/.test(c.key) || /tarikh/.test(c.key) ? esc(fa(c.new)) : `<input class="psgi-in" style="width:100%" data-ed="${k}" value="${esc(c.new)}" dir="auto">`}</td>
                    <td class="text-center">${c.row ? '<span class="text-[10px] text-slate-400">مخصوصِ ردیف</span>' : `<input type="checkbox" data-tpl="${k}" checked>`}</td></tr>`).join('')}
                </tbody></table></div></div>`;
            ui.ft.innerHTML = `<button class="psgi-btn text-white" style="background:#16a34a" data-go><i class="fas fa-check"></i>تأیید و ثبت در پاسارگاد</button>
                <label class="text-[11px] inline-flex items-center gap-1"><input type="checkbox" data-docs checked>مدارکِ ردیف هم روی پیش‌نویس بارگذاری شود</label>
                <button class="psgi-btn bg-slate-100 text-slate-600" data-back><i class="fas fa-arrow-right"></i>برگشت</button>
                <span class="flex-1"></span><span class="text-[10.5px] text-slate-400">🟡 هشدار ← خودکار دوباره «ثبت» · 🔴 خطا ← توقف و نمایش</span>`;
            ui.ft.querySelector('[data-back]').onclick = renderStart;
            ui.ft.querySelector('[data-go]').onclick = submit;
        }
        async function submit() {
            const j = S.job, edits = {}, exclude = [];
            ui.bd.querySelectorAll('[data-ed]').forEach(el => { const c = j.changes[Number(el.dataset.ed)]; if (c && el.value !== c.new) edits[c.key] = el.value.trim(); });
            ui.bd.querySelectorAll('[data-tpl]').forEach(el => { if (!el.checked) exclude.push(j.changes[Number(el.dataset.tpl)].key); });
            const b = ui.ft.querySelector('[data-go]'); b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ ثبت در سامانه…';
            const r = await post({action: 'submit', job_id: j.id, edits, exclude, upload_docs: !!ui.ft.querySelector('[data-docs]').checked});
            if (r.need_login) { login(); b.disabled = false; b.innerHTML = '<i class="fas fa-check"></i>تأیید و ثبت در پاسارگاد'; return; }
            if (!r.ok) {
                ui.bd.insertAdjacentHTML('afterbegin', `<div class="psgi-n r"><b>ثبت نشد:</b> ${esc(r.error || '')}</div>${r.job ? noteHtml({save: (r.job.notices || {}).save}) : ''}`);
                ui.ft.innerHTML = `<button class="psgi-btn text-white" style="background:#0f766e" data-again2><i class="fas fa-rotate"></i>اصلاح و آماده‌سازیِ دوباره</button><span class="flex-1"></span>`;
                ui.ft.querySelector('[data-again2]').onclick = renderStart;
                return;
            }
            S.job = r.job;
            const jj = r.job;
            ui.bd.innerHTML = `<div class="psgi-n g" style="font-size:13px"><i class="fas fa-circle-check ml-1"></i><b>پیش‌نویس ثبت شد:</b> <span dir="ltr" class="font-black">${esc(fa(jj.draft_no || '—'))}</span> — وضعیتِ ردیف «پیش‌نویس شد».</div>
                ${jj.cargo_used ? `<div class="psgi-n y">برای ثبت، «اتاق بار» با نوعِ جایگزین ثبت شد (سامانه برای نوعِ اصلی اطلاعاتِ پایه نداشت). می‌توانید همین حالا به نوعِ اصلی برگردانید: <button class="psgi-btn" style="background:#fef3c7;color:#92400e;padding:3px 9px" data-restore>برگرداندن</button></div>` : ''}
                ${noteHtml({save: (jj.notices || {}).save})}
                ${r.upload ? `<div class="psgi-n ${r.upload.ok ? 'i' : 'r'}">${r.upload.ok ? 'مدارک: ' + ((r.upload.files || []).map(f => (f.ok ? '✅ ' : '❌ ') + esc(f.name)).join(' · ') || esc(r.upload.note || 'مدرکی نبود')) : esc(r.upload.error || 'بارگذاریِ مدارک نشد')}</div>` : ''}`;
            const rb = ui.bd.querySelector('[data-restore]');
            if (rb) rb.onclick = async () => { rb.disabled = true; const x = await post({action: 'restore_cargo', job_id: jj.id}); rb.disabled = false; toast(x.ok ? 'به نوعِ اصلی برگشت.' : (x.error || 'نشد'), x.ok ? 'success' : 'error'); };
            ui.ft.innerHTML = '<button class="psgi-btn bg-slate-100 text-slate-700" data-done>بستن</button>';
            ui.ft.querySelector('[data-done]').onclick = () => { ui.close(); if (opts.onDone) opts.onDone(jj); };
            toast('پیش‌نویس ثبت شد: ' + fa(jj.draft_no || ''), 'success');
        }
    }

    // ---- گروهی: ردیف‌ها یکی‌یکی (آماده‌سازی + ثبت) با همان نمونه؛ خطای قرمز ← توقف ----
    async function group(rows, opts) {
        opts = opts || {};
        rows = (rows || []).filter(r => r && r.id);
        if (!rows.length) { toast('ردیفی انتخاب نشده.', 'warning'); return; }
        const ui = shell('پیش‌نویسِ گروهی — ' + fa(rows.length) + ' ردیف', 'هر ردیف: آماده‌سازی و ثبت با همان نمونه/الگو');
        const st = {stop: false, i: 0};
        ui.bd.innerHTML = `<div class="psgi-card"><div class="flex flex-wrap gap-2 items-center"><label class="text-[11.5px]">شماره‌ی پیش‌نویسِ نمونه (خالی = الگو / پیشنهادِ خودکار برای هر ردیف)</label>
                <input class="psgi-in" dir="ltr" style="width:190px" data-sample placeholder="1/49357/1405/523"></div>
                <label class="text-[11px] inline-flex items-center gap-1 mt-2"><input type="checkbox" data-docs checked>مدارکِ هر ردیف هم روی پیش‌نویسش بارگذاری شود</label>
                <label class="text-[11px] inline-flex items-center gap-1 mt-2 mr-3"><input type="checkbox" data-stopred checked>با اولین خطای قرمز متوقف شود</label>
                <p class="text-[10.5px] text-slate-400 mt-1">ردیف‌هایی که قبلاً پیش‌نویس شده‌اند رد می‌شوند. اولین ثبتِ موفق الگو را برای بقیه یاد می‌گیرد.</p></div>
            <table class="no-count psgi-tbl"><thead><tr><th>#</th><th>ردیف</th><th>وضعیت</th></tr></thead><tbody>${rows.map((r, k) => `<tr><td>${fa(k + 1)}</td><td>${esc(fa(r.title || r.id))}</td><td data-st="${k}" class="text-slate-400">در صف</td></tr>`).join('')}</tbody></table>`;
        ui.ft.innerHTML = `<button class="psgi-btn text-white" style="background:#0f766e" data-run><i class="fas fa-play"></i>شروع</button><button class="psgi-btn bg-slate-100 text-slate-600" data-stop disabled><i class="fas fa-stop"></i>توقف</button><span class="flex-1"></span>`;
        const cell = k => ui.bd.querySelector(`[data-st="${k}"]`);
        ui.ft.querySelector('[data-stop]').onclick = () => { st.stop = true; };
        ui.ft.querySelector('[data-run]').onclick = async () => {
            const sample = ui.bd.querySelector('[data-sample]').value.trim(), docs = ui.bd.querySelector('[data-docs]').checked, stopRed = ui.bd.querySelector('[data-stopred]').checked;
            ui.ft.querySelector('[data-run]').disabled = true; ui.ft.querySelector('[data-stop]').disabled = false;
            let ok = 0, bad = 0;
            for (let k = 0; k < rows.length; k++) {
                if (st.stop) { cell(k).textContent = 'متوقف شد'; continue; }
                cell(k).innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>آماده‌سازی…';
                let smp = sample;
                if (!smp) { const s = await post({action: 'suggest', plate_id: rows[k].id}); if (s.ok && s.draft_no) { cell(k).innerHTML = `<span class="text-slate-500">قبلاً پیش‌نویس شده: ${esc(fa(s.draft_no))}</span>`; continue; } if (s.ok && s.sample && !s.template) smp = s.sample.draft_no; }
                const p = await post({action: 'prepare', plate_id: rows[k].id, sample: smp});
                if (p.need_login) { cell(k).innerHTML = '<span class="text-red-600">نیازمندِ ورود به سامانه</span>'; if (window.Inquiry && Inquiry.login) Inquiry.login(() => {}); break; }
                if (!p.ok) { bad++; cell(k).innerHTML = `<span class="${p.exists ? 'text-slate-500' : 'text-red-600'}">${esc(p.error || 'آماده نشد')}</span>`; if (!p.exists && stopRed) break; continue; }
                if ((p.job.notices.errors || []).length && stopRed) { bad++; cell(k).innerHTML = `<span class="text-red-600">🔴 ${esc(p.job.notices.errors.join(' — '))}</span>`; break; }
                cell(k).innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>ثبت…';
                const r = await post({action: 'submit', job_id: p.job.id, upload_docs: docs});
                if (r.ok) { ok++; cell(k).innerHTML = `<span class="text-emerald-700 font-bold">پیش‌نویس شد: ${esc(fa(r.job.draft_no || ''))}</span>${r.job.cargo_used ? ' <span class="text-amber-600">(اتاقِ بارِ جایگزین)</span>' : ''}${r.upload && r.upload.files ? ` · مدارک ${fa((r.upload.files || []).filter(f => f.ok).length)}/${fa((r.upload.files || []).length)}` : ''}`; }
                else { bad++; cell(k).innerHTML = `<span class="text-red-600">${r.red ? '🔴 ' : ''}${esc(r.error || 'ثبت نشد')}</span>`; if (r.need_login || (r.red && stopRed)) break; }
            }
            ui.ft.querySelector('[data-stop]').disabled = true;
            toast(`${fa(ok)} پیش‌نویس ثبت شد${bad ? '، ' + fa(bad) + ' ردیف ناموفق' : ''}.`, bad ? 'warning' : 'success');
            if (opts.onDone) opts.onDone();
        };
    }
    // ---- استعلامِ حق بیمه‌ی بدنه‌ی پاسارگاد: فرم از خودِ سامانه، «استعلام» و «چاپ» (PDFِ سامانه) ----
    async function premium(prefill) {
        prefill = prefill || {};
        const ui = shell('استعلامِ حق بیمه‌ی بدنه (پاسارگاد)', 'فقط استعلام است؛ پیش‌نویس یا بیمه‌نامه‌ای ساخته نمی‌شود');
        ui.ov.querySelector('.psgi-hd').style.background = 'linear-gradient(120deg,#7c3aed,#0369a1)';
        ui.bd.innerHTML = '<p class="text-center text-slate-400 py-8"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ باز کردنِ فرمِ استعلام از سامانه…</p>';
        const b = await post({action: 'prem_boot'});
        if (!b.ok) { ui.bd.innerHTML = `<div class="psgi-n r">${esc(b.error || 'باز نشد')}</div>`; if (b.need_login && window.Inquiry && Inquiry.login) Inquiry.login(() => { ui.close(); premium(prefill); }); return; }
        const F = b.fields || [], COV = b.coverages || [], VM = b.vehicle_map || {};
        const pre = (f) => { if (/salSakht/.test(f.name) && prefill.year) return prefill.year; if (/arzeshVasilehNaghliyeh/.test(f.name) && prefill.value) return String(prefill.value).replace(/\B(?=(\d{3})+(?!\d))/g, ','); return f.value || ''; };
        const ctl = (f, k) => {
            if (f.type === 'select') return `<select class="psgi-in" style="width:100%" data-k="${k}">${f.options.map(o => `<option value="${esc(o[0])}" ${o[0] === f.value ? 'selected' : ''}>${esc(o[1])}</option>`).join('')}</select>`;
            if (f.type === 'lov') return `<div class="flex gap-1"><input class="psgi-in" style="flex:1" data-lovq="${k}" placeholder="جستجو…" value="${esc(f.desc || '')}"><button class="psgi-btn bg-slate-100 text-slate-700" data-lovb="${k}"><i class="fas fa-magnifying-glass"></i></button></div>
                <input type="hidden" data-k="${k}" value="${esc(f.value)}"><div class="text-[10.5px] text-slate-500 mt-1" data-lovsel="${k}">${f.value ? 'انتخاب‌شده: ' + esc(f.desc || f.value) : ''}</div><div data-lovres="${k}"></div>`;
            return `<input class="psgi-in" style="width:100%" data-k="${k}" dir="${f.type === 'date' || /arzesh|salSakht|takhfif|moddat/.test(f.name) ? 'ltr' : 'auto'}" value="${esc(fa(f.type === 'date' ? String(f.value).split(' ')[0].split(';')[0] : pre(f)))}" ${f.type === 'date' ? 'data-jdate' : ''}>`;
        };
        const vmKeys = Object.keys(VM);
        ui.bd.innerHTML = `<div class="psgi-card"><div class="grid gap-2" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr))">
                ${F.map((f, k) => `<label class="text-[11px] text-slate-600"><span class="block font-bold mb-0.5">${esc(f.label)}</span>${ctl(f, k)}</label>`).join('')}
                ${vmKeys.length ? `<label class="text-[11px] text-slate-600"><span class="block font-bold mb-0.5">نوعِ وسیله‌ی نقلیه (گروهِ تیپ)</span><select class="psgi-in" style="width:100%" data-veh><option value="">— از تیپ —</option>${vmKeys.map(id => `<option value="${esc(id)}" ${String(prefill.veh || '') === id ? 'selected' : ''}>گروهِ ${fa(id)} (${esc((VM[id] || []).slice(0, 3).map(x => x[1]).join('، '))}…)</option>`).join('')}</select></label>` : ''}
            </div></div>
            <div class="psgi-card"><p class="font-black text-slate-700 mb-1">پوشش‌های اضافی</p><div class="grid gap-1" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
                ${COV.map((c, k) => `<label class="text-[11px] flex items-center gap-1"><input type="checkbox" data-cov="${k}" ${c.checked ? 'checked' : ''}><span class="flex-1">${esc(c.label)}</span>${c.select ? `<select class="psgi-in" style="max-width:110px" data-covs="${k}">${c.select.options.map(o => `<option value="${esc(o[0])}" ${o[0] === c.select.value ? 'selected' : ''}>${esc(o[1])}</option>`).join('')}</select>` : ''}</label>`).join('')}
            </div></div><div data-res></div>`;
        if (window.JDatePick && JDatePick.attach) ui.bd.querySelectorAll('[data-jdate]').forEach(el => { try { JDatePick.attach(el); } catch (e) {} });
        ui.bd.querySelectorAll('[data-lovb]').forEach(btn => btn.onclick = async () => {
            const k = Number(btn.dataset.lovb), f = F[k], q = ui.bd.querySelector(`[data-lovq="${k}"]`).value.trim(), box = ui.bd.querySelector(`[data-lovres="${k}"]`);
            box.innerHTML = '<p class="text-[10.5px] text-slate-400"><i class="fas fa-spinner fa-spin ml-1"></i>جستجو…</p>';
            const r = await post({action: 'prem_lov', lov: f.lov, q});
            if (!r.ok) { box.innerHTML = `<p class="text-[10.5px] text-red-600">${esc(r.error || 'نشد')}</p>`; return; }
            box.innerHTML = (r.items || []).length ? `<div style="max-height:150px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px">${r.items.map(it => `<div class="px-2 py-1 text-[11px] hover:bg-sky-50 cursor-pointer" data-pick="${esc(it.id)}" data-desc="${esc(it.desc)}">${esc(it.desc)} <span class="text-slate-400">${fa(it.id)}</span></div>`).join('')}</div>` : '<p class="text-[10.5px] text-slate-400">چیزی پیدا نشد.</p>';
            box.querySelectorAll('[data-pick]').forEach(el => el.onclick = () => { ui.bd.querySelector(`[data-k="${k}"]`).value = el.dataset.pick; f.desc = el.dataset.desc; ui.bd.querySelector(`[data-lovsel="${k}"]`).textContent = 'انتخاب‌شده: ' + el.dataset.desc; box.innerHTML = ''; });
        });
        ui.ft.innerHTML = `<button class="psgi-btn text-white" style="background:#7c3aed" data-calc><i class="fas fa-calculator"></i>استعلام حق بیمه</button>
            <label class="text-[11px] inline-flex items-center gap-1"><input type="checkbox" data-print checked>چاپِ سامانه (PDF) هم گرفته شود</label><span class="flex-1"></span>`;
        ui.ft.querySelector('[data-calc]').onclick = async () => {
            const vals = {};
            F.forEach((f, k) => { const el = ui.bd.querySelector(`[data-k="${k}"]`); if (!el) return; let v = el.value.trim().replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); vals[f.name] = v; if (f.type === 'lov') vals[f.name + '.desc'] = f.desc || ''; });
            const veh = ui.bd.querySelector('[data-veh]'); if (veh && veh.value) { vals.noeVasilehNaghleyeh = veh.value; vals.noeVasilehNaghleyeh_inputHidden = veh.value; }
            COV.forEach((c, k) => { vals[c.name] = !!ui.bd.querySelector(`[data-cov="${k}"]`).checked; const s2 = ui.bd.querySelector(`[data-covs="${k}"]`); if (s2 && c.select) vals[c.select.name] = s2.value; });
            const btn = ui.ft.querySelector('[data-calc]'); btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>در حالِ استعلام…';
            const r = await post({action: 'prem_calc', values: vals, print: ui.ft.querySelector('[data-print]').checked});
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-calculator"></i>استعلام حق بیمه';
            const res = ui.bd.querySelector('[data-res]');
            if (r.need_login && window.Inquiry && Inquiry.login) { Inquiry.login(() => {}); return; }
            if (r.expired) { toast(r.error, 'warning'); ui.close(); premium(prefill); return; }
            res.innerHTML = `<div class="psgi-card">${(r.warnings || []).map(t => `<div class="psgi-n y">🟡 ${esc(t)}</div>`).join('')}${(r.errors || []).map(t => `<div class="psgi-n r">🔴 ${esc(t)}</div>`).join('')}
                ${r.ok ? (r.tables || []).map(tb => `<table class="no-count psgi-tbl mb-2">${tb.map((row, i) => `<tr>${row.map(c => i === 0 && tb.length > 2 && row.length > 2 ? `<th>${esc(fa(c))}</th>` : `<td>${esc(fa(c))}</td>`).join('')}</tr>`).join('')}</table>`).join('') : `<div class="psgi-n r">${esc(r.error || 'جوابی نیامد')}</div>`}
                ${r.pdf ? `<div class="flex gap-2 mt-2"><a class="psgi-btn text-white" style="background:#0369a1" target="_blank" href="${API}?action=prem_pdf&t=${r.pdf}"><i class="fas fa-print"></i>چاپ / مشاهده‌ی PDFِ سامانه</a><a class="psgi-btn bg-slate-100 text-slate-700" href="${API}?action=prem_pdf&dl=1&t=${r.pdf}"><i class="fas fa-download"></i>دانلود</a></div>` : (r.pdf_error ? `<div class="psgi-n y">${esc(r.pdf_error)}</div>` : '')}</div>`;
            res.scrollIntoView({behavior: 'smooth', block: 'start'});
        };
    }
    window.PsgIssueUI = {open, group, premium};
})();
