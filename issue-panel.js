// فایل: issue-panel.js
// «پنلِ صدور»: پاپ‌آپی روی هر ردیفِ شرکتی یا درخواستِ کارکنان با همه‌ی اطلاعاتِ لازم برای صدور —
// بیمه‌گذار، مالک، ذینفع، خودرو، درخواست — هر خانه با دکمه‌ی کپی (و کپیِ هر بخش / همه)، و نمایشگرِ مدارک کنارش.
//   IssuePanel.open({type: 'C'|'P', id, onIssue, onChange})   onIssue(data): دکمه‌ی «ثبت صدور» (اگر داده شود)؛ onChange: بعد از «استخراج اطلاعات» که چیزی روی ردیف نشست
// نمایشگرِ مدارک: بزرگنمایی (دکمه‌ها، چرخِ ماوس، دوبار کلیک، دو انگشت)، جابه‌جایی با کشیدن و چرخاندنِ عکس؛ PDF با بزرگنماییِ نمایشگرِ مرورگر
// «استخراج اطلاعات»: متنِ مدرک (OCR) ← پرامپتِ همان نوعِ مدرک برای همان شرکت ← فقط خانه‌های خالیِ ردیف پر می‌شود
// ردیفِ ثالث: بخشِ «استعلامِ سنهاب» (عدمِ خسارت / خسارت، تخفیف‌ها، بیمه‌گر و انقضا + جزئیات) با sanhab-ui.js
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
        .ipn-stage{flex:1;display:flex;align-items:center;justify-content:center;min-height:0;position:relative;overflow:hidden}
        .ipn-stage img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;transform-origin:center;cursor:zoom-in;user-select:none;-webkit-user-drag:none;touch-action:none}
        .ipn-stage img.zoomed{cursor:grab}.ipn-stage img.drag{cursor:grabbing}
        .ipn-zm{display:inline-flex;align-items:center;gap:2px;background:rgba(255,255,255,.08);border-radius:10px;padding:2px}
        .ipn-zm button{width:26px;height:24px;border-radius:8px;color:#e2e8f0;font-size:11px;display:inline-flex;align-items:center;justify-content:center}
        .ipn-zm button:hover{background:rgba(255,255,255,.18)}
        .ipn-zm span{min-width:44px;text-align:center;font-size:10.5px;color:#cbd5e1}
        .ipn-btn.x{background:linear-gradient(120deg,#7c3aed,#c026d3);color:#fff}.ipn-btn.x:hover{filter:brightness(1.1)}
        .ipn-xres{position:absolute;left:10px;right:10px;bottom:10px;max-height:62%;overflow:auto;background:#fff;border-radius:14px;padding:10px 12px;box-shadow:0 20px 50px -10px rgba(0,0,0,.6);z-index:3;font-size:11.5px;color:#334155}
        .ipn-xres table{width:100%;border-collapse:collapse}.ipn-xres td{border-bottom:1px solid #f1f5f9;padding:4px 6px;vertical-align:top}.ipn-xres td:first-child{color:#64748b;font-weight:700;white-space:nowrap;width:1%}
        .ipn-stage iframe{position:absolute;inset:0;width:100%;height:100%;border:0;background:#fff}
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

    // بخشِ «استعلامِ خسارت»: ثالث = سنهاب (ذخیره از «جزئیات») یا فایل؛ بدنه = فایل/عکس (+ دکمه‌ی استعلامِ خودکار، بعداً)
    function dmgHtml(dm) {
        if (!dm) return '';
        if (!dm.applicable) return `<div class="ipn-sec" style="background:#f8fafc"><h4><span><i class="fas fa-file-shield text-slate-400 ml-1"></i>استعلامِ خسارت</span></h4><p class="text-[11px] text-slate-500">لازم نیست: ${esc(dm.why)}.</p></div>`;
        const files = dm.files || [];
        return `<div class="ipn-sec" style="border-color:${files.length ? '#a7f3d0' : '#fde68a'};background:${files.length ? '#f0fdf4' : '#fffbeb'}">
            <h4><span><i class="fas fa-file-shield ${files.length ? 'text-emerald-600' : 'text-amber-600'} ml-1"></i>${esc(dm.label)} ${files.length ? '<span class="text-[10px] text-emerald-700">✓ ثبت شده</span>' : '<span class="text-[10px] text-amber-700">ثبت نشده</span>'}</span></h4>
            ${files.map(f => `<a href="${url(f.path)}" target="_blank" class="flex items-center gap-1.5 text-[11px] font-bold text-blue-700 hover:underline mb-1"><i class="fas ${isImg(f.path) ? 'fa-image' : 'fa-file-pdf'}"></i>${esc(fa(f.name))}</a>`).join('')}
            ${dm.can_edit ? `<div class="flex flex-wrap gap-1.5 mt-1">
                ${dm.body ? '<button type="button" class="ipn-btn s" data-dmgbody title="به‌زودی"><i class="fas fa-magnifying-glass"></i> استعلامِ خسارتِ بدنه</button>' : '<span class="text-[10.5px] text-slate-500 self-center">سنهاب: بعد از استعلام، در «جزئیات» دکمه‌ی «ذخیره‌ی خروجیِ سنهاب» را بزنید؛ یا</span>'}
                <label class="ipn-btn s cursor-pointer" data-dmglbl><i class="fas fa-upload"></i> بارگذاریِ فایل/عکسِ استعلام<input type="file" accept=".pdf,image/*" class="hidden" data-dmgup></label></div>
                ${dm.stamp ? '' : '<p class="text-[10px] text-amber-700 mt-1"><i class="fas fa-triangle-exclamation ml-1"></i>مهرِ استعلامات هنوز در «استعلامات ← تنظیمات» بارگذاری نشده؛ فایل بدونِ مهر ذخیره می‌شود.</p>'}` : ''}</div>`;
    }
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
                    ${d.sanhab && d.sanhab.third && window.SanhabUI ? '<div class="ipn-sec" style="border-color:#a5f3fc;background:#f0fdff"><h4><span><i class="fas fa-shield-halved text-cyan-600 ml-1"></i>استعلامِ سنهاب (بیمه‌نامه‌ی ثالثِ قبلی)</span></h4><div data-sh></div></div>' : ''}
                    ${dmgHtml(d.damage)}
                    ${(d.sections || []).map((s, si) => `<div class="ipn-sec"><h4><span><i class="fas ${esc(s.icon)} text-blue-600 ml-1"></i>${esc(s.title)}</span>
                        <button class="ipn-btn s" data-sec="${si}" style="padding:3px 8px;font-size:10px"><i class="far fa-copy"></i> کپیِ بخش</button></h4>
                        <div class="ipn-grid">${s.fields.map((f, fi) => `<div class="ipn-f" data-f="${si}:${fi}" title="کپیِ ${esc(f.label)}"><i class="far fa-copy ipn-cp"></i><small>${esc(f.label)}</small><b ${f.ltr ? 'dir="ltr" style="text-align:right"' : ''}>${esc(fa(f.value))}</b></div>`).join('')}</div></div>`).join('')
                      || '<p class="text-xs text-slate-400 p-6 text-center">اطلاعاتی ثبت نشده.</p>'}
                </div>
                <div class="ipn-viewer">
                    ${files.length ? `<div class="flex items-center justify-between gap-2 px-3 py-2 text-white text-[11px] font-bold" style="background:#1e293b">
                        <span class="truncate" data-cap></span>
                        <span class="flex flex-wrap items-center gap-1.5 justify-end">
                            <span class="ipn-zm"><button data-zo title="کوچک‌نمایی (-)"><i class="fas fa-magnifying-glass-minus"></i></button><span data-zl>100%</span><button data-zi title="بزرگ‌نمایی (+)"><i class="fas fa-magnifying-glass-plus"></i></button>
                                <button data-zf title="اندازه‌ی صفحه (0)"><i class="fas fa-expand"></i></button><button data-zr title="چرخاندن"><i class="fas fa-rotate-right"></i></button></span>
                            ${d.can_extract ? '<button class="ipn-btn x" data-xt style="padding:3px 8px" title="خواندنِ این مدرک با پرامپتِ همین نوعِ مدرک برای همین شرکت و پر کردنِ خانه‌های خالیِ ردیف"><i class="fas fa-wand-magic-sparkles"></i> استخراج اطلاعات</button>' : ''}
                            <a class="ipn-btn w" data-open target="_blank" style="padding:3px 8px"><i class="fas fa-up-right-from-square"></i> باز کردن</a><a class="ipn-btn w" data-dl download style="padding:3px 8px"><i class="fas fa-download"></i></a></span></div>
                        <div class="ipn-stage" data-stage></div>
                        <div class="ipn-thumbs">${files.map((f, i) => `<span class="ipn-th" data-i="${i}" title="${esc(f.label)}"><i class="fas ${isImg(f.path) ? 'fa-image' : 'fa-file-pdf'} ml-1"></i>${esc(fa(f.label))}</span>`).join('')}</div>`
                      : '<div class="ipn-stage text-slate-400 text-sm font-bold"><span><i class="fas fa-folder-open ml-1"></i>مدرکی برای این ردیف بارگذاری نشده.</span></div>'}
                </div>
            </div>`;
        const box = ov.querySelector('.ipn-box');
        // بزرگنمایی: عکس با transform (z = ضریب، rot = چرخش، tx/ty = جابه‌جایی)؛ PDF با #zoom=درصد (۰ = اندازه‌ی عرضِ صفحه)
        const PZ = [0, 50, 75, 100, 125, 150, 200, 300, 400];
        let z = 1, rot = 0, tx = 0, ty = 0, pz = 0;
        const zl = () => box.querySelector('[data-zl]');
        const img = () => box.querySelector('[data-stage] img');
        const paintZ = () => {
            const im = img();
            if (im) {
                im.style.transform = `translate(${tx}px,${ty}px) scale(${z}) rotate(${rot}deg)`;
                im.classList.toggle('zoomed', z > 1);
                if (zl()) zl().textContent = fa(Math.round(z * 100)) + '٪';
            } else if (zl()) zl().textContent = pz ? fa(pz) + '٪' : 'عرضِ صفحه';
        };
        const zoomAt = (nz, px, py) => {
            const st = box.querySelector('[data-stage]'); if (!st) return;
            nz = Math.max(1, Math.min(8, nz));
            if (px != null) {
                const rc = st.getBoundingClientRect(), cx = px - rc.left - rc.width / 2, cy = py - rc.top - rc.height / 2;
                tx = cx - (nz / z) * (cx - tx); ty = cy - (nz / z) * (cy - ty);
            } else { tx *= nz / z; ty *= nz / z; }
            z = nz;
            if (z === 1) { tx = 0; ty = 0; }
            paintZ();
        };
        const pdfZoom = n => {
            const f = files[cur], fr = box.querySelector('[data-stage] iframe'); if (!fr) return;
            pz = n;
            const nf = document.createElement('iframe');
            nf.src = url(f.path) + (pz ? '#zoom=' + pz : '#view=FitH');
            fr.replaceWith(nf);
            paintZ();
        };
        const zoomStep = dir => {
            if (img()) return zoomAt(dir > 0 ? z * 1.25 : z / 1.25);
            const i = PZ.indexOf(pz), base = i < 0 ? 0 : i;
            pdfZoom(PZ[Math.max(0, Math.min(PZ.length - 1, (pz === 0 && dir > 0 ? PZ.indexOf(100) : base + dir)))]);
        };
        const zoomFit = () => { if (img()) { rot = 0; zoomAt(1); } else pdfZoom(0); };
        const bindImg = () => {
            const st = box.querySelector('[data-stage]'), im = img(); if (!st || !im) return;
            st.onwheel = e => { e.preventDefault(); zoomAt(e.deltaY < 0 ? z * 1.15 : z / 1.15, e.clientX, e.clientY); };
            im.ondblclick = e => { if (z > 1) zoomAt(1); else zoomAt(2.5, e.clientX, e.clientY); };
            const pts = new Map(); let last = null, pinch = 0;
            im.onpointerdown = e => { im.setPointerCapture(e.pointerId); pts.set(e.pointerId, [e.clientX, e.clientY]); last = [e.clientX, e.clientY]; if (pts.size === 2) { const [a, b] = [...pts.values()]; pinch = Math.hypot(a[0] - b[0], a[1] - b[1]); } im.classList.add('drag'); };
            im.onpointermove = e => {
                if (!pts.has(e.pointerId)) return;
                pts.set(e.pointerId, [e.clientX, e.clientY]);
                if (pts.size === 2) {
                    const [a, b] = [...pts.values()], dd = Math.hypot(a[0] - b[0], a[1] - b[1]);
                    if (pinch) zoomAt(z * dd / pinch, (a[0] + b[0]) / 2, (a[1] + b[1]) / 2);
                    pinch = dd; return;
                }
                if (z > 1 && last) { tx += e.clientX - last[0]; ty += e.clientY - last[1]; paintZ(); }
                last = [e.clientX, e.clientY];
            };
            im.onpointerup = im.onpointercancel = e => { pts.delete(e.pointerId); pinch = 0; last = pts.size ? [...pts.values()][0] : null; if (!pts.size) im.classList.remove('drag'); };
        };
        const show = i => {
            if (!files.length) return;
            cur = (i + files.length) % files.length;
            z = 1; rot = 0; tx = 0; ty = 0; pz = 0;
            const f = files[cur], u = url(f.path);
            box.querySelector('[data-cap]').textContent = `${fa(cur + 1)} از ${fa(files.length)} · ${f.group ? f.group + ' · ' : ''}${f.label}`;
            box.querySelector('[data-open]').href = u; box.querySelector('[data-dl]').href = u;
            box.querySelector('[data-stage]').innerHTML = (isImg(f.path) ? `<img src="${u}" alt="">` : `<iframe src="${u}#view=FitH"></iframe>`)
                + (files.length > 1 ? '<span class="ipn-nav" data-prev style="right:10px"><i class="fas fa-chevron-right"></i></span><span class="ipn-nav" data-next style="left:10px"><i class="fas fa-chevron-left"></i></span>' : '');
            box.querySelectorAll('.ipn-th').forEach(t => t.classList.toggle('on', Number(t.dataset.i) === cur));
            const th = box.querySelector(`.ipn-th[data-i="${cur}"]`); if (th) th.scrollIntoView({block: 'nearest', inline: 'nearest'});
            const pv = box.querySelector('[data-prev]'), nx = box.querySelector('[data-next]');
            if (pv) pv.onclick = () => show(cur - 1); if (nx) nx.onclick = () => show(cur + 1);
            const zr = box.querySelector('[data-zr]'); if (zr) zr.style.display = isImg(f.path) ? '' : 'none';
            const xt = box.querySelector('[data-xt]'); if (xt) xt.style.display = f.ext ? '' : 'none';
            if (isImg(f.path)) bindImg(); else { const st = box.querySelector('[data-stage]'); if (st) st.onwheel = null; }
            paintZ();
        };
        if (files.length) {
            box.querySelector('[data-zi]').onclick = () => zoomStep(1);
            box.querySelector('[data-zo]').onclick = () => zoomStep(-1);
            box.querySelector('[data-zf]').onclick = zoomFit;
            box.querySelector('[data-zr]').onclick = () => { rot = (rot + 90) % 360; paintZ(); };
        }
        // «استخراج اطلاعات» از مدرکِ فعلی
        const KINDS = {'': 'خودکار (طبقِ نوعِ مدرک)', car_card: 'کارت خودرو', ownership_doc: 'سند مالکیت (برگ سبز)', sales_invoice: 'سند / فاکتور فروش', policy: 'بیمه‌نامه‌ی خودرو', personnel: 'معرفی‌نامه / مدرکِ پرسنلی', generic: 'متنِ آزاد (فقط نمایش)'};
        const api = async b => { try { return await (await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(Object.assign({type: d.type, id: d.id}, b))})).json(); } catch (e) { return {ok: false, error: 'خطا در ارتباط با سرور.'}; } };
        const mmss = s => fa(Math.floor(s / 60)) + ':' + fa(String(Math.floor(s % 60)).padStart(2, '0'));
        const extract = () => {
            const f = files[cur], st = box.querySelector('[data-stage]');
            let rb = st.querySelector('.ipn-xres'); if (rb) rb.remove();
            rb = document.createElement('div'); rb.className = 'ipn-xres'; st.appendChild(rb);
            rb.onwheel = e => e.stopPropagation();
            rb.innerHTML = `<div class="flex items-center justify-between gap-2 mb-2"><b class="text-[12px] text-violet-800"><i class="fas fa-wand-magic-sparkles ml-1"></i>استخراجِ اطلاعات · ${esc(fa(f.label))}</b><button class="ipn-btn s" data-xc style="padding:2px 8px"><i class="fas fa-times"></i></button></div>
                <p class="text-[10.5px] text-slate-500 leading-6 mb-2">متنِ مدرک خوانده می‌شود و با پرامپتِ همین نوعِ مدرک (اگر برای این شرکت پرامپتِ اختصاصی تنظیم شده، همان) اطلاعاتش استخراج می‌شود؛ فقط خانه‌های <b>خالیِ</b> ردیف پر می‌شوند و هیچ مقدارِ ثبت‌شده‌ای عوض نمی‌شود.</p>
                <div class="flex flex-wrap items-end gap-2"><label class="text-[10.5px] font-bold text-slate-500">نوعِ مدرک<select data-xk class="block border border-slate-200 rounded-lg px-2 py-1 text-[11.5px] mt-0.5">${Object.entries(KINDS).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
                    <label class="text-[10.5px] font-bold text-slate-500">صفحه‌ها (PDF)<select data-xp class="block border border-slate-200 rounded-lg px-2 py-1 text-[11.5px] mt-0.5"><option value="1">فقط صفحه‌ی ۱</option><option value="2">۱ تا ۲</option><option value="3">۱ تا ۳</option></select></label>
                    <button class="ipn-btn x" data-xgo><i class="fas fa-play"></i> شروع</button></div>
                <div data-xout class="mt-2"></div>`;
            if (isImg(f.path)) rb.querySelector('[data-xp]').parentNode.style.display = 'none';
            rb.querySelector('[data-xc]').onclick = () => rb.remove();
            rb.querySelector('[data-xgo]').onclick = async () => {
                const go = rb.querySelector('[data-xgo]'), out = rb.querySelector('[data-xout]'), kind = rb.querySelector('[data-xk]').value;
                let want = isImg(f.path) ? 1 : Number(rb.querySelector('[data-xp]').value), msg = '', t0 = Date.now();
                go.disabled = true;
                const tick = () => { out.innerHTML = `<p class="text-[11px] text-violet-700"><i class="fas fa-spinner fa-spin ml-1"></i>${msg} <span class="text-slate-400">${mmss((Date.now() - t0) / 1000)}</span></p>`; };
                const tm = setInterval(tick, 500);
                const fail = e => { clearInterval(tm); go.disabled = false; out.innerHTML = `<p class="text-[11px] text-rose-600"><i class="fas fa-circle-exclamation ml-1"></i>${esc(e)}</p>`; };
                const texts = [];
                for (let p = 1; p <= want; p++) {
                    msg = `خواندنِ متنِ ${want > 1 ? 'صفحه‌ی ' + fa(p) + ' از ' + fa(want) : 'مدرک'}…`; tick();
                    const r = await api({action: 'doc_text', path: f.path, page: p});
                    if (!r.ok) { if (!texts.length) return fail(r.error || 'متنی خوانده نشد.'); break; }
                    texts.push(r.text_verbatim || '');
                    if (r.pages && r.pages < want) want = r.pages;
                }
                msg = 'استخراجِ اطلاعات با پرامپتِ مدرک…'; tick();
                const x = await api({action: 'extract', path: f.path, text: texts.join('\n\n'), doc_kind: kind});
                if (!x.ok) return fail(x.error || 'استخراج نشد.');
                clearInterval(tm); go.disabled = false;
                const fl = Object.entries(x.fields_verbatim || {}), ap = Object.entries(x.applied_verbatim || {});
                out.innerHTML = `<p class="text-[11px] font-black text-emerald-700 mb-1"><i class="fas fa-circle-check ml-1"></i>${esc(x.type_fa || x.type)} · ${mmss((Date.now() - t0) / 1000)}</p>
                    ${fl.length ? `<p class="text-[10px] text-slate-400 mb-1">روی هر فیلد بزنید تا کپی شود.</p><table>${fl.map(([k, v], i) => `<tr data-xcf="${i}" style="cursor:pointer" title="کپی"><td>${esc(k)}</td><td dir="auto"><i class="far fa-copy text-slate-300 ml-1"></i><b>${esc(/[A-Za-z]/.test(v) ? v : fa(v))}</b></td></tr>`).join('')}</table>` : '<p class="text-slate-400">اطلاعاتی پیدا نشد.</p>'}
                    <div class="mt-2 rounded-lg ${ap.length ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-50 text-slate-500'} p-2 text-[11px]">${ap.length
                        ? `<b><i class="fas fa-check-double ml-1"></i>روی ${d.type === 'P' ? 'درخواست' : 'ردیف'} نشست:</b> ${ap.map(([k, v]) => `${esc(k)}: <b dir="auto">${esc(/[A-Za-z]/.test(v) ? v : fa(v))}</b>`).join(' · ')}`
                        : (x.type === 'generic' ? 'متنِ آزاد فقط نمایش داده می‌شود.' : 'چیزی تغییر نکرد (خانه‌های مربوط از قبل پر بودند یا در مدرک نبود).')}</div>
                    ${ap.length ? '<button class="ipn-btn g mt-2" data-xrf><i class="fas fa-rotate"></i> نمایشِ اطلاعاتِ به‌روز</button>' : ''}`;
                out.querySelectorAll('[data-xcf]').forEach(tr => tr.onclick = async () => { const [k, v] = fl[+tr.dataset.xcf]; if (await copy(String(v))) toast(k + ' کپی شد.', 'success'); });
                const rf = out.querySelector('[data-xrf]');
                if (rf) rf.onclick = () => { close(); open(Object.assign({}, opts, {start: f.path})); };
                if (ap.length && typeof opts.onChange === 'function') { try { opts.onChange(x); } catch (e) {} }
            };
        };
        const xb = box.querySelector('[data-xt]'); if (xb) xb.onclick = extract;
        box.querySelectorAll('.ipn-th').forEach(t => t.onclick = () => show(Number(t.dataset.i)));
        const shHost = box.querySelector('[data-sh]');
        const reopen = () => { close(); open(Object.assign({}, opts, {start: files[cur] ? files[cur].path : undefined})); if (typeof opts.onChange === 'function') try { opts.onChange(); } catch (e) {} };
        const dmgSave = d.damage && d.damage.applicable && d.damage.can_edit ? Object.assign({}, d.damage.save, {onSaved: reopen}) : null;
        if (shHost) SanhabUI.button(shHost, {prefill: d.sanhab.prefill, ref: d.sanhab.ref, source: 'form', title: d.title, label: 'استعلام از سنهاب (عدمِ خسارت و تخفیف‌ها)', save: dmgSave});
        // استعلامِ خسارت: بارگذاریِ فایل/عکس (با مهرِ استعلامات)
        const du = box.querySelector('[data-dmgup]');
        if (du) du.onchange = async () => {
            const f = du.files[0]; du.value = ''; if (!f) return;
            const lb = box.querySelector('[data-dmglbl]'); const h0 = lb.innerHTML; lb.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حالِ ذخیره و مهر…';
            const fd = new FormData(); fd.append('action', 'dmg_upload'); fd.append('type', d.type); fd.append('id', d.id); fd.append('file', f, f.name);
            let r; try { r = await (await fetch(API, {method: 'POST', body: fd, credentials: 'same-origin'})).json(); } catch (e) { r = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
            lb.innerHTML = h0;
            if (!r.ok) return toast(r.error || 'خطا', 'error');
            toast(`ذخیره شد: ${r.name}${r.stamped ? ' (با مهر)' : ''}`, 'success');
            reopen();
        };
        const db = box.querySelector('[data-dmgbody]'); if (db) db.onclick = () => toast('استعلامِ خودکارِ خسارتِ بدنه هنوز راه‌اندازی نشده؛ فعلاً فایل یا عکسِ استعلام را بارگذاری کنید.', 'info');
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
            if (e.key === '+' || e.key === '=') zoomStep(1);
            if (e.key === '-') zoomStep(-1);
            if (e.key === '0') zoomFit();
        }
        document.addEventListener('keydown', onKey);
        show(Math.max(0, files.findIndex(f => f.path === opts.start)));
    }
    window.IssuePanel = { open };
})();
