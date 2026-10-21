// فایل: issue-panel.js
// «پنلِ صدور»: پاپ‌آپی روی هر ردیفِ شرکتی یا درخواستِ کارکنان با همه‌ی اطلاعاتِ لازم برای صدور —
// بیمه‌گذار، مالک، ذینفع، خودرو، درخواست — هر خانه با دکمه‌ی کپی (و کپیِ هر بخش / همه)، و نمایشگرِ مدارک کنارش.
//   IssuePanel.open({type: 'C'|'P', id, onIssue})   onIssue(data): دکمه‌ی «ثبت صدور» (اگر داده شود)
(function () {
    'use strict';
    if (window.IssuePanel) return;
    const API = 'api/issue_panel_actions.php';
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const url = p => '/' + String(p || '').split('/').map(encodeURIComponent).join('/');
    const isImg = p => /\.(jpe?g|png|webp|gif)$/i.test(p || '');
    let css = false;
    function injectCss() {
        if (css) return; css = true;
        const st = document.createElement('style');
        st.textContent = `
        .ipn-ov{position:fixed;inset:0;z-index:1000003;background:rgba(15,23,42,.6);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:12px}
        .ipn-box{background:#f8fafc;border-radius:24px;width:100%;max-width:1400px;height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(0,0,0,.5)}
        .ipn-head{background:linear-gradient(120deg,#0f172a,#1e3a8a 55%,#0e7490);color:#fff;padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
        .ipn-body{flex:1;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:12px;padding:12px;min-height:0}
        @media(max-width:900px){.ipn-body{grid-template-columns:1fr;overflow:auto}.ipn-col{overflow:visible!important}.ipn-viewer{min-height:60vh}}
        .ipn-col{overflow:auto;min-height:0}
        .ipn-sec{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:10px 12px;margin-bottom:10px}
        .ipn-sec h4{font-size:12px;font-weight:900;color:#334155;display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px}
        .ipn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px}
        .ipn-f{border:1px solid #f1f5f9;background:#f8fafc;border-radius:10px;padding:5px 8px;cursor:pointer;transition:.12s;position:relative}
        .ipn-f:hover{border-color:#93c5fd;background:#eff6ff}
        .ipn-f small{display:block;font-size:9.5px;color:#94a3b8;font-weight:700}
        .ipn-f b{display:block;font-size:12px;color:#0f172a;word-break:break-word}
        .ipn-f i.ipn-cp{position:absolute;left:7px;top:7px;font-size:10px;color:#cbd5e1}
        .ipn-f:hover i.ipn-cp{color:#3b82f6}
        .ipn-f.done{border-color:#86efac;background:#f0fdf4}.ipn-f.done i.ipn-cp{color:#16a34a}
        .ipn-btn{border-radius:10px;padding:6px 11px;font-size:11px;font-weight:800;display:inline-flex;align-items:center;gap:5px;cursor:pointer;white-space:nowrap}
        .ipn-btn.w{background:rgba(255,255,255,.14);color:#fff}.ipn-btn.w:hover{background:rgba(255,255,255,.25)}
        .ipn-btn.g{background:#10b981;color:#fff}.ipn-btn.g:hover{background:#059669}
        .ipn-btn.s{background:#f1f5f9;color:#334155}.ipn-btn.s:hover{background:#e2e8f0}
        .ipn-viewer{background:#0f172a;border-radius:16px;display:flex;flex-direction:column;overflow:hidden;min-height:0}
        .ipn-stage{flex:1;display:flex;align-items:center;justify-content:center;min-height:0;position:relative}
        .ipn-stage img{max-width:100%;max-height:100%;object-fit:contain}
        .ipn-stage iframe{width:100%;height:100%;border:0;background:#fff}
        .ipn-thumbs{display:flex;gap:6px;overflow-x:auto;padding:8px;background:#1e293b}
        .ipn-th{flex:none;border-radius:8px;padding:5px 9px;font-size:10.5px;font-weight:800;color:#cbd5e1;background:#334155;cursor:pointer;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ipn-th.on{background:#3b82f6;color:#fff}
        .ipn-nav{position:absolute;top:50%;transform:translateY(-50%);width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
        .ipn-nav:hover{background:rgba(255,255,255,.3)}`;
        document.head.appendChild(st);
    }
    async function copy(text) {
        try { await navigator.clipboard.writeText(text); return true; }
        catch (e) {
            const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
            let ok = false; try { ok = document.execCommand('copy'); } catch (x) {} t.remove(); return ok;
        }
    }
    const secText = s => s.title + '\n' + s.fields.map(f => `${f.label}: ${f.value}`).join('\n');

    async function open(opts) {
        injectCss();
        const ov = document.createElement('div');
        ov.className = 'ipn-ov';
        ov.innerHTML = '<div class="ipn-box"><div class="ipn-head"><b><i class="fas fa-circle-notch fa-spin ml-1"></i> در حالِ بارگذاری…</b></div></div>';
        document.body.appendChild(ov);
        const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); };
        ov.addEventListener('click', e => { if (e.target === ov) close(); });
        let d;
        try { d = await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify({action: 'get', type: opts.type, id: opts.id})})).json(); }
        catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
        if (!d.ok) { close(); toast(d.error || 'خطا', 'error'); return; }
        const files = d.files || [];
        let cur = 0;
        const canVeh = d.vehicle_key && window.Vehicles && (!window.permCan || permCan('vehicles'));
        ov.querySelector('.ipn-box').innerHTML = `
            <div class="ipn-head">
                <div class="min-w-0"><p class="text-[10.5px] opacity-80"><i class="fas fa-stamp ml-1"></i>پنلِ صدور · ${d.type === 'P' ? 'کارکنان' : 'شرکتی'}${d.status_fa ? ' · ' + esc(d.status_fa) : ''}</p>
                    <h3 class="text-lg font-black truncate">${esc(fa(d.title))}</h3><p class="text-[11px] opacity-85 truncate">${esc(fa(d.subtitle || ''))}</p></div>
                <div class="flex flex-wrap gap-2">
                    <button class="ipn-btn w" data-all><i class="far fa-copy"></i> کپیِ همه‌ی اطلاعات</button>
                    ${canVeh ? '<button class="ipn-btn w" data-veh><i class="fas fa-car-side"></i> پرونده‌ی خودرو</button>' : ''}
                    ${d.can_issue && typeof opts.onIssue === 'function' ? '<button class="ipn-btn g" data-issue><i class="fas fa-stamp"></i> ثبت صدور</button>' : ''}
                    <button class="ipn-btn w" data-x><i class="fas fa-times"></i> بستن</button>
                </div>
            </div>
            <div class="ipn-body">
                <div class="ipn-col">
                    <p class="text-[10.5px] text-slate-500 font-bold mb-2"><i class="fas fa-hand-pointer ml-1"></i>روی هر خانه بزنید تا کپی شود؛ برای هر بخش هم «کپیِ بخش» هست.</p>
                    ${(d.sections || []).map((s, si) => `<div class="ipn-sec"><h4><span><i class="fas ${esc(s.icon)} text-blue-600 ml-1"></i>${esc(s.title)}</span>
                        <button class="ipn-btn s" data-sec="${si}" style="padding:3px 8px;font-size:10px"><i class="far fa-copy"></i> کپیِ بخش</button></h4>
                        <div class="ipn-grid">${s.fields.map((f, fi) => `<div class="ipn-f" data-f="${si}:${fi}" title="کپیِ ${esc(f.label)}"><i class="far fa-copy ipn-cp"></i><small>${esc(f.label)}</small><b ${f.ltr ? 'dir="ltr" style="text-align:right"' : ''}>${esc(fa(f.value))}</b></div>`).join('')}</div></div>`).join('')
                      || '<p class="text-xs text-slate-400 p-6 text-center">اطلاعاتی ثبت نشده.</p>'}
                </div>
                <div class="ipn-viewer">
                    ${files.length ? `<div class="flex items-center justify-between gap-2 px-3 py-2 text-white text-[11px] font-bold" style="background:#1e293b">
                        <span class="truncate" data-cap></span>
                        <span class="flex gap-1.5"><a class="ipn-btn w" data-open target="_blank" style="padding:3px 8px"><i class="fas fa-up-right-from-square"></i> باز کردن</a><a class="ipn-btn w" data-dl download style="padding:3px 8px"><i class="fas fa-download"></i></a></span></div>
                        <div class="ipn-stage" data-stage></div>
                        <div class="ipn-thumbs">${files.map((f, i) => `<span class="ipn-th" data-i="${i}" title="${esc(f.label)}"><i class="fas ${isImg(f.path) ? 'fa-image' : 'fa-file-pdf'} ml-1"></i>${esc(fa(f.label))}</span>`).join('')}</div>`
                      : '<div class="ipn-stage text-slate-400 text-sm font-bold"><span><i class="fas fa-folder-open ml-1"></i>مدرکی برای این ردیف بارگذاری نشده.</span></div>'}
                </div>
            </div>`;
        const box = ov.querySelector('.ipn-box');
        const show = i => {
            if (!files.length) return;
            cur = (i + files.length) % files.length;
            const f = files[cur], u = url(f.path);
            box.querySelector('[data-cap]').textContent = `${fa(cur + 1)} از ${fa(files.length)} · ${f.group ? f.group + ' · ' : ''}${f.label}`;
            box.querySelector('[data-open]').href = u; box.querySelector('[data-dl]').href = u;
            box.querySelector('[data-stage]').innerHTML = (isImg(f.path) ? `<img src="${u}" alt="">` : `<iframe src="${u}#view=FitH"></iframe>`)
                + (files.length > 1 ? '<span class="ipn-nav" data-prev style="right:10px"><i class="fas fa-chevron-right"></i></span><span class="ipn-nav" data-next style="left:10px"><i class="fas fa-chevron-left"></i></span>' : '');
            box.querySelectorAll('.ipn-th').forEach(t => t.classList.toggle('on', Number(t.dataset.i) === cur));
            const th = box.querySelector(`.ipn-th[data-i="${cur}"]`); if (th) th.scrollIntoView({block: 'nearest', inline: 'nearest'});
            const pv = box.querySelector('[data-prev]'), nx = box.querySelector('[data-next]');
            if (pv) pv.onclick = () => show(cur - 1); if (nx) nx.onclick = () => show(cur + 1);
        };
        box.querySelectorAll('.ipn-th').forEach(t => t.onclick = () => show(Number(t.dataset.i)));
        box.querySelectorAll('[data-f]').forEach(el => el.onclick = async () => {
            const [si, fi] = el.dataset.f.split(':').map(Number), f = d.sections[si].fields[fi];
            if (await copy(f.value)) { el.classList.add('done'); setTimeout(() => el.classList.remove('done'), 1200); toast(`${f.label} کپی شد.`, 'success'); }
        });
        box.querySelectorAll('[data-sec]').forEach(b => b.onclick = async () => { if (await copy(secText(d.sections[Number(b.dataset.sec)]))) toast('بخش کپی شد.', 'success'); });
        box.querySelector('[data-all]').onclick = async () => { if (await copy(`${d.title}\n${d.subtitle || ''}\n\n` + d.sections.map(secText).join('\n\n'))) toast('همه‌ی اطلاعات کپی شد.', 'success'); };
        box.querySelector('[data-x]').onclick = close;
        const vb = box.querySelector('[data-veh]'); if (vb) vb.onclick = () => { close(); Vehicles.open(d.vehicle_key); };
        const ib = box.querySelector('[data-issue]'); if (ib) ib.onclick = () => { close(); opts.onIssue(d); };
        function onKey(e) {
            if (e.key === 'Escape') close();
            if (!files.length || /input|textarea|select/i.test((e.target && e.target.tagName) || '')) return;
            if (e.key === 'ArrowLeft') show(cur + 1);
            if (e.key === 'ArrowRight') show(cur - 1);
        }
        document.addEventListener('keydown', onKey);
        show(0);
    }
    window.IssuePanel = { open };
})();
