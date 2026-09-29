// فایل: visit-reports-editor.js
// ویرایشگرِ گرافیکیِ قالبِ گزارش (فقط مدیر کل): صفحه‌ی فرم با همه‌ی کادرهای متنیِ قالبِ Word نمایش داده می‌شود؛
// کادرها با موس/لمس کشیده و جابه‌جا می‌شوند (تکی یا چندتایی)، با کلیدهای جهت دقیق جابه‌جا می‌شوند، عددِ افقی/عمودی
// خودکار نوشته می‌شود (و دستی هم قابلِ تغییر است)، و برای هر کادر قلم، اندازه و متن/متغیر عوض می‌شود.
// همه‌ی این‌ها فقط روی PDF اثر دارند؛ فایلِ Wordِ خروجی همان قالبِ اصلی است.
(function () {
    'use strict';
    const VR = window.VR;
    const { api, modal, fa, en, esc, API } = VR;
    const toast = (m, t = 'info') => (typeof window.showToast === 'function' ? window.showToast(m, t) : console.log(m));
    const confirmBox = (t, m, o) => (window.uiConfirm ? uiConfirm(t, m, o) : Promise.resolve(confirm(m)));
    const round = v => Math.round(v * 2) / 2;   // گامِ نیم‌پوینت
    const PROPS = ['dx', 'dy', 'hidden', 'font', 'fsize', 'text', 'showif'];

    const CSS = `
    .vre-wrap{display:grid;grid-template-columns:1fr 20rem;gap:1rem;height:calc(100vh - 9rem)}
    @media(max-width:1024px){.vre-wrap{grid-template-columns:1fr;height:auto}}
    .vre-stage{overflow:auto;background:#e2e8f0;border-radius:1rem;position:relative;padding:1.5rem;touch-action:none}
    .vre-page{position:relative;background:#fff;box-shadow:0 10px 40px rgba(15,23,42,.18);margin:0 auto;direction:ltr;user-select:none;-webkit-user-select:none}
    .vre-img{position:absolute;overflow:hidden;pointer-events:none}
    .vre-img img{position:absolute;max-width:none}
    .vre-box{position:absolute;border:1px dashed rgba(100,116,139,.45);cursor:move;line-height:1.15;font-family:Vazir,Tahoma,sans-serif;color:#1e293b;word-break:break-word;transition:box-shadow .12s,background .12s}
    .vre-box.var{border-color:rgba(79,70,229,.7);background:rgba(99,102,241,.08);color:#3730a3}
    .vre-box.chg{background:rgba(245,158,11,.14);border-color:#f59e0b}
    .vre-box.off{opacity:.35;border-color:#ef4444;background:repeating-linear-gradient(45deg,rgba(239,68,68,.1) 0 4px,transparent 4px 8px)}
    .vre-box.sel{outline:2px solid #6366f1;outline-offset:1px;box-shadow:0 0 0 4px rgba(99,102,241,.2);z-index:5;background:rgba(99,102,241,.16)}
    .vre-box:hover{box-shadow:0 0 0 2px rgba(99,102,241,.35)}
    .vre-band{position:absolute;border:1.5px solid #6366f1;background:rgba(99,102,241,.1);pointer-events:none;z-index:10}
    .vre-hide-static .vre-box:not(.var):not(.chg):not(.shape){display:none}
    .vre-box.shape{border-style:solid}
    .vre-box.cond::after{content:'?';position:absolute;top:-8px;left:-8px;width:12px;height:12px;border-radius:50%;background:#7c3aed;color:#fff;font:bold 9px/12px sans-serif;text-align:center}
    .vre-sample .vre-box.cond-off{opacity:.12}
    /* «نمایش با نمونه»: کادرها مثلِ خروجیِ واقعی (متنِ پُرشده، بدونِ رنگِ زمینه) ولی همچنان قابلِ کشیدن */
    .vre-sample .vre-box{display:flex!important;flex-direction:column;background:transparent;color:#0b1220;border:1px dashed rgba(148,163,184,.35);overflow:visible}
    .vre-sample .vre-box.var{background:transparent;color:#0b1220;border-color:rgba(99,102,241,.35)}
    .vre-sample .vre-box.chg{background:transparent;border-color:#f59e0b}
    .vre-sample .vre-box.off{opacity:.25}
    .vre-sample .vre-box.sel{background:rgba(99,102,241,.08)}
    .vre-sample .vre-box > span{display:block;width:100%}
    .vre-panel{overflow:auto;display:flex;flex-direction:column;gap:.75rem}
    .vre-kbd{display:inline-block;background:#f1f5f9;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:.35rem;padding:0 .3rem;font-size:.62rem;font-family:monospace}
    `;

    VR.openLayoutEditor = async function (cat, onSaved) {
        if (!document.getElementById('vre-css')) { const s = document.createElement('style'); s.id = 'vre-css'; s.textContent = CSS; document.head.appendChild(s); }
        const m = modal({ title: 'ویرایشگرِ قالب', icon: 'fa-object-group', width: '98vw', html: '<div class="vr-skel h-96"></div>', noBackdropClose: true });
        m.setTitle(`ویرایشگرِ قالب · ${esc(cat.name)}`, 'کادرها را با موس بکشید؛ Ctrl/Shift+کلیک یا کشیدنِ کادرِ انتخاب برای چند کادر؛ کلیدهای جهت برای جابه‌جاییِ دقیق');
        const d = await api('set_layout_editor', { id: cat.id });
        if (!d.ok) { m.body.innerHTML = `<p class="text-red-500 font-bold text-sm p-6">${esc(d.error)}</p>`; return; }

        const st = {
            sample: false, values: d.sample || {},
            page: 0, zoom: 1, scale: 1, sel: new Set(), undo: [], redo: [], dirty: false, showStatic: false, fontAll: d.font_all || '',
            items: d.items, boxes: d.items.filter(i => i.type === 'box' && (!i.empty || i.fill || i.line)), W: d.page.w, H: d.page.h,
        };
        st.boxes.forEach(b => { b.showif = b.showif || ''; b.shape = !!b.empty; });
        st.boxes.forEach(b => { b._init = JSON.stringify(PROPS.map(k => b[k] ?? null)); });
        const byIdx = new Map(st.boxes.map(b => [b.index, b]));
        const snapshot = () => JSON.stringify(st.boxes.map(b => PROPS.map(k => b[k] ?? null)));
        const restore = snap => { JSON.parse(snap).forEach((vals, i) => PROPS.forEach((k, j) => { st.boxes[i][k] = vals[j]; })); };
        const pushUndo = () => { st.undo.push(snapshot()); if (st.undo.length > 100) st.undo.shift(); st.redo = []; st.dirty = true; };
        const changed = b => JSON.stringify(PROPS.map(k => b[k] ?? null)) !== JSON.stringify([0, 0, false, '', null, null, '']);

        const fontOpts = (cur, withDefault) => (withDefault ? `<option value="">${withDefault}</option>` : '') + d.fonts.map(f => `<option value="${esc(f.value)}" ${f.value === cur ? 'selected' : ''}>${esc(f.label)}</option>`).join('');
        m.body.innerHTML = `
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <div class="vr-seg vre-pages">${Array.from({ length: d.pages }, (_, i) => `<button type="button" data-p="${i}" class="${i === 0 ? 'on-ok' : ''}">صفحه ${fa(i + 1)}</button>`).join('')}</div>
                <div class="vr-seg"><button type="button" class="vre-zo">−</button><button type="button" class="vre-zf">اندازه‌ی صفحه</button><button type="button" class="vre-zi">+</button></div>
                <label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-600 cursor-pointer"><input type="checkbox" class="accent-indigo-600 vre-static"> نمایشِ کادرهای متنِ ثابت</label>
                <label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-600 cursor-pointer"><input type="checkbox" class="accent-indigo-600 vre-imgs" checked> پس‌زمینه</label>
                <label class="flex items-center gap-1.5 text-[11px] font-black text-violet-700 bg-violet-50 rounded-lg px-2 py-1 cursor-pointer"><input type="checkbox" class="accent-violet-600 vre-samp"> نمایش با نمونه</label>
                <select class="vr-in !py-1 !text-[11px] vre-src hidden" style="width:auto;max-width:18rem"><option value="">دادهٔ نمونه (ساختگی)</option>${(d.reports || []).map(r => `<option value="${r.id}">${esc(r.report_no)} · ${esc(r.insured_name || '')}</option>`).join('')}</select>
                <span class="flex-1"></span>
                <button type="button" class="vr-btn vr-btn-s !py-1.5 !text-[11px] vre-undo" title="Ctrl+Z"><i class="fas fa-rotate-left"></i> برگشت</button>
                <button type="button" class="vr-btn vr-btn-s !py-1.5 !text-[11px] vre-redo" title="Ctrl+Y"><i class="fas fa-rotate-right"></i> جلو</button>
                <button type="button" class="vr-btn vr-btn-s !py-1.5 !text-[11px] vre-prev"><i class="fas fa-eye"></i> ذخیره و پیش‌نمایش</button>
                <button type="button" class="vr-btn vr-btn-p !py-1.5 !text-[11px] vre-save"><i class="fas fa-floppy-disk"></i> ذخیره</button>
            </div>
            <div class="vre-wrap">
                <div class="vre-stage vre-hide-static"><div class="vre-page"></div></div>
                <div class="vre-panel">
                    <div class="vr-card p-4"><label class="vr-lbl">قلمِ همه‌ی کادرها (مگر کادری که قلمِ جدا دارد)</label>
                        <select class="vr-in !py-2 vre-fontall">${fontOpts(st.fontAll, 'همان قلم‌های قالبِ Word')}</select></div>
                    <div class="vr-card p-4 vre-props"></div>
                    <div class="vr-card p-4 text-[10px] font-bold text-slate-500 leading-6">
                        <p><span class="vre-kbd">کلیک</span> انتخاب · <span class="vre-kbd">Ctrl</span>/<span class="vre-kbd">Shift</span>+کلیک افزودن به انتخاب</p>
                        <p>کشیدن روی فضای خالی: انتخابِ چند کادر با هم</p>
                        <p><span class="vre-kbd">←↑→↓</span> نیم‌پوینت · با <span class="vre-kbd">Shift</span> پنج پوینت</p>
                        <p><span class="vre-kbd">Ctrl+A</span> همه · <span class="vre-kbd">Esc</span> لغوِ انتخاب · <span class="vre-kbd">Ctrl+Z</span> برگشت</p>
                        <p class="text-slate-400 mt-1">این تنظیم‌ها فقط روی PDF اثر دارند؛ فایلِ Wordِ خروجی همان قالبِ اصلی است. با آپلودِ قالبِ تازه، تنظیمِ کادرهای هم‌جا حفظ می‌شود.</p>
                    </div>
                </div>
            </div>`;
        const stage = m.body.querySelector('.vre-stage'), pageEl = m.body.querySelector('.vre-page'), props = m.body.querySelector('.vre-props');

        function fitScale() { const w = stage.clientWidth - 48; st.scale = Math.max(0.3, (w > 200 ? w : 800) / st.W) * st.zoom; }
        // قلمِ نمایشی نزدیک به PDF: قلم‌های لاتین با Helvetica/Arial، بقیه وزیر
        function boxFont(b) {
            const f = b.font || st.fontAll || b.wfont || '';
            if (/narrow/i.test(f)) return "font-family:'Arial Narrow',Helvetica,Arial,Vazir,sans-serif;font-stretch:condensed;";
            if (/arial|helvetica|times|calibri|tahoma|verdana|segoe|cambria|courier|garamond/i.test(f)) return 'font-family:Helvetica,Arial,Vazir,sans-serif;';
            return 'font-family:Vazir,Tahoma,sans-serif;';
        }
        // شکلِ بی‌متن (مثلاً دایره‌ی توپُرِ «خوب»): رنگ و خط‌دور و گردیِ خودش را دارد
        function shapeStyle(b) {
            if (!b.shape) return '';
            return `background:${b.fill || 'transparent'};border-color:${b.line || '#94a3b8'};${b.geom === 'ellipse' ? 'border-radius:50%;' : ''}`;
        }
        function boxStyle(b) { return boxStyleBase(b) + shapeStyle(b); }
        function boxStyleBase(b) {
            const s = st.scale, pt = b.fsize || b.size || 10;
            if (st.sample) {
                const [il, it, ir, ib] = b.ins || [0, 0, 0, 0];
                return `left:${(b.x + (b.dx || 0)) * s}px;top:${(b.y + (b.dy || 0)) * s}px;width:${b.w * s}px;height:${b.h * s}px;font-size:${pt * s}px;line-height:1.1;` +
                       `padding:${it * s}px ${ir * s}px ${ib * s}px ${il * s}px;justify-content:${b.anchor === 'ctr' ? 'center' : b.anchor === 'b' ? 'flex-end' : 'flex-start'};` +
                       `white-space:pre-wrap;${boxFont(b)}text-align:${b.align === 'center' ? 'center' : b.align === 'left' ? 'left' : 'right'};direction:rtl;${b.rot ? `transform:rotate(${b.rot}deg);` : ''}`;
            }
            const multi = b.h > pt * 2.2;   // کادرِ چندخطی (مثل آدرس) شکسته نمایش داده شود، کادرِ کوچک یک‌خطی
            const size = Math.max(6, Math.min(pt * s, multi ? pt * s : b.h * s * 0.85));
            return `left:${(b.x + (b.dx || 0)) * s}px;top:${(b.y + (b.dy || 0)) * s}px;width:${b.w * s}px;height:${b.h * s}px;font-size:${size}px;` +
                   `white-space:${multi ? 'pre-wrap' : 'nowrap'};overflow:hidden;text-overflow:ellipsis;` +
                   `text-align:${b.align === 'center' ? 'center' : b.align === 'left' ? 'left' : 'right'};direction:rtl;${b.rot ? `transform:rotate(${b.rot}deg);` : ''}`;
        }
        // در حالتِ نمونه، کادری که شرطِ نمایشش برقرار نیست کم‌رنگ دیده می‌شود (در PDF چاپ نمی‌شود)
        const condOff = b => st.sample && !!b.showif && String(st.values[b.showif] ?? '').trim() === '';
        function boxText(b) { return (b.text !== null && b.text !== undefined && b.text !== '') ? b.text : b.orig; }
        // نمایشِ جمع‌وجور در ویرایشگر: {{ c1_k }} => c1_k
        // مثلِ PDF: قلمِ فارسی => رقم فارسی؛ قلمِ لاتین => رقم لاتین (کلمه‌ی دارای حرفِ لاتین مثل شماره شاسی دست نمی‌خورد)
        const LATIN_FONT = /arial|helvetica|times|calibri|tahoma|verdana|segoe|cambria|courier|garamond|georgia|consolas|narrow|roboto|open sans|century|trebuchet/i;
        function localDigits(b, t) {
            const f = String(b.font || st.fontAll || b.wfont || '').trim();
            const latin = f !== '' && !/[\u0600-\u06FF]/.test(f) && !/^(b |iran|vazir|yekan|nazanin|titr|lotus|mitra|zar)/i.test(f) && LATIN_FONT.test(f);
            return t.replace(/\S+/g, w => latin ? (/[\u0621-\u064A\u067E-\u06D3]/.test(w) ? w : en(w)) : (/[A-Za-z]/.test(w) ? w : fa(w)));
        }
        function boxLabel(b) {
            if (st.sample) return b.hidden ? '' : localDigits(b, ((b.text !== null && b.text !== undefined && b.text !== '') ? b.text : (b.printed ?? b.orig)).replace(/\{\{\s*([^}]+?)\s*\}\}/g, (m, k) => String(st.values[k.trim()] ?? '')));
            return boxText(b).replace(/\{\{\s*([^}]+?)\s*\}\}/g, '$1');
        }
        function drawPage() {
            fitScale();
            const s = st.scale;
            pageEl.style.width = st.W * s + 'px'; pageEl.style.height = st.H * s + 'px';
            if (!pageEl.querySelector('.vre-boxl')) pageEl.innerHTML = '<div class="vre-bgl"></div><div class="vre-boxl"></div>';
            // پس‌زمینه فقط وقتی صفحه/بزرگ‌نمایی عوض شود دوباره ساخته می‌شود (تا با هر کلیک چشمک نزند)
            const showImg = m.body.querySelector('.vre-imgs').checked;
            const bgKey = `${st.page}|${s}|${showImg}`;
            const bgl = pageEl.querySelector('.vre-bgl');
            if (bgl.dataset.key !== bgKey) {
                bgl.dataset.key = bgKey;
                bgl.innerHTML = !showImg ? '' : st.items.filter(it => it.type === 'image' && it.page === st.page).map(it => {
                    const [cl, ct, cr, cb] = it.crop || [0, 0, 0, 0];
                    const fw = it.w / Math.max(0.01, 1 - cl - cr), fh = it.h / Math.max(0.01, 1 - ct - cb);
                    return `<div class="vre-img" style="left:${(it.x + it.dx) * s}px;top:${(it.y + it.dy) * s}px;width:${it.w * s}px;height:${it.h * s}px">
                        <img src="${API}?action=set_layout_asset&id=${cat.id}&file=${encodeURIComponent(it.file)}" style="left:${-cl * fw * s}px;top:${-ct * fh * s}px;width:${fw * s}px;height:${fh * s}px" draggable="false"></div>`;
                }).join('');
            }
            pageEl.querySelector('.vre-boxl').innerHTML = st.boxes.filter(b => b.page === st.page).map(b => {
                const isVar = /\{\{/.test(boxText(b));
                return `<div class="vre-box ${isVar ? 'var' : ''} ${b.shape ? 'shape' : ''} ${b.showif ? 'cond' : ''} ${condOff(b) ? 'cond-off' : ''} ${changed(b) ? 'chg' : ''} ${b.hidden ? 'off' : ''} ${st.sel.has(b.index) ? 'sel' : ''}" data-i="${b.index}" style="${boxStyle(b)}" title="${esc(b.shape ? 'شکل' + (b.showif ? ' · فقط وقتی ' + b.showif + ' تیک خورده' : '') : boxText(b))}"><span>${esc(b.shape ? '' : boxLabel(b))}</span></div>`;
            }).join('');
            m.body.querySelectorAll('.vre-pages button').forEach(x => x.className = Number(x.dataset.p) === st.page ? 'on-ok' : '');
        }
        // فقط موقعیت/ظاهرِ کادرهای انتخاب‌شده عوض شود (موقعِ کشیدن سریع بماند)
        function refreshBoxes(idxs) {
            idxs.forEach(i => {
                const el = pageEl.querySelector(`.vre-box[data-i="${i}"]`), b = byIdx.get(i);
                if (!el) return;
                el.setAttribute('style', boxStyle(b));
                el.innerHTML = `<span>${esc(boxLabel(b))}</span>`;
                el.title = boxText(b);
                el.classList.toggle('chg', changed(b)); el.classList.toggle('off', !!b.hidden); el.classList.toggle('sel', st.sel.has(i));
                el.classList.toggle('var', /\{\{/.test(boxText(b)));
                el.classList.toggle('cond', !!b.showif); el.classList.toggle('cond-off', condOff(b));
                if (b.shape) el.innerHTML = '<span></span>';
            });
        }

        function renderProps() {
            const sel = [...st.sel].map(i => byIdx.get(i)).filter(Boolean);
            if (!sel.length) {
                const n = st.boxes.filter(changed).length;
                props.innerHTML = `<p class="vr-sec-title !text-xs mb-2"><i class="fas fa-arrow-pointer text-indigo-400"></i> کادری انتخاب نشده</p>
                    <p class="text-[11px] text-slate-500 font-bold leading-6">روی یک کادر کلیک کنید یا چند کادر را انتخاب کنید. ${n ? `${fa(n)} کادر تغییر کرده (نارنجی).` : ''}</p>
                    ${n ? '<button type="button" class="vr-btn vr-btn-s w-full mt-2 !text-[11px] vre-selchg">انتخابِ کادرهای تغییرکرده</button>' : ''}`;
                const sc = props.querySelector('.vre-selchg');
                if (sc) sc.onclick = () => { st.boxes.filter(changed).forEach(b => st.sel.add(b.index)); const p = byIdx.get([...st.sel][0]); if (p) st.page = p.page; drawPage(); renderProps(); };
                return;
            }
            const one = sel.length === 1 ? sel[0] : null;
            const same = k => sel.every(b => (b[k] ?? '') === (sel[0][k] ?? '')) ? (sel[0][k] ?? '') : null;
            props.innerHTML = `
                <p class="vr-sec-title !text-xs mb-3"><i class="fas fa-vector-square text-indigo-500"></i> ${one ? 'کادرِ انتخاب‌شده' : fa(sel.length) + ' کادرِ انتخاب‌شده'}</p>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="vr-lbl">افقی (پوینت)</label><input class="vr-in !py-1.5 text-center vre-dx" dir="ltr" value="${one ? fa(one.dx || 0) : ''}" placeholder="${one ? '' : 'گروهی'}"></div>
                    <div><label class="vr-lbl">عمودی (پوینت)</label><input class="vr-in !py-1.5 text-center vre-dy" dir="ltr" value="${one ? fa(one.dy || 0) : ''}" placeholder="${one ? '' : 'گروهی'}"></div>
                </div>
                <div class="grid grid-cols-4 gap-1 mt-2">${[['←', -1, 0], ['→', 1, 0], ['↑', 0, -1], ['↓', 0, 1]].map(([t, x, y]) => `<button type="button" class="vr-btn vr-btn-s !py-1 vre-nudge" data-x="${x}" data-y="${y}">${t}</button>`).join('')}</div>
                <p class="text-[10px] text-slate-400 font-bold mt-1">${one ? `جای نهایی: افقی ${fa(round(one.x + (one.dx || 0)))}، عمودی ${fa(round(one.y + (one.dy || 0)))} پوینت از گوشه‌ی بالا-چپ` : 'عددِ واردشده به همه‌ی کادرهای انتخابی اضافه می‌شود.'}</p>
                <div class="mt-3"><label class="vr-lbl">قلم</label><select class="vr-in !py-2 vre-font">${fontOpts(same('font'), same('font') === null ? '— متفاوت —' : 'همان قلمِ قالب')}${same('font') === null ? '<option value="__keep" selected>— بدونِ تغییر —</option>' : ''}</select></div>
                <div class="mt-2"><label class="vr-lbl">اندازه‌ی قلم (پوینت) ${one ? `<span class="text-slate-400">· قالب: ${fa(one.size)}</span>` : ''}</label><input class="vr-in !py-1.5 text-center vre-fsize" dir="ltr" value="${same('fsize') ? fa(same('fsize')) : ''}" placeholder="همان اندازه‌ی قالب"></div>
                ${one ? `<div class="mt-2"><label class="vr-lbl">متن / متغیرِ کادر</label>
                    <textarea class="vr-in !text-xs font-mono vre-text" dir="ltr" rows="3">${esc(boxText(one))}</textarea>
                    <div class="flex gap-1 mt-1"><input class="vr-in !py-1 !text-[11px] font-mono vre-var" dir="ltr" list="vre-vars" placeholder="نامِ متغیر"><button type="button" class="vr-btn vr-btn-s !py-1 !text-[10px] vre-setvar" title="متنِ کادر فقط همین متغیر شود">جایگزینی</button><button type="button" class="vr-btn vr-btn-s !py-1 !text-[10px] vre-addvar">افزودن</button></div>
                    <datalist id="vre-vars">${d.vars.map(v => `<option value="${esc(v)}">`).join('')}</datalist>
                    ${one.text ? `<p class="text-[10px] text-amber-600 font-bold mt-1">متنِ اصلی: <span dir="ltr">${esc(one.orig)}</span></p>` : ''}</div>` : ''}
                <div class="mt-3"><label class="vr-lbl">شرطِ نمایش: فقط وقتی این متغیر تیک/پر است چاپ شود</label>
                    <input class="vr-in !py-1.5 !text-[11px] font-mono vre-showif" dir="ltr" list="vre-vars2" value="${esc(same('showif') ?? '')}" placeholder="${same('showif') === null ? '— متفاوت —' : 'بدونِ شرط (همیشه)'}">
                    <datalist id="vre-vars2">${d.vars.map(v => `<option value="${esc(v)}">`).join('')}</datalist>
                    <p class="text-[10px] text-slate-400 mt-1 leading-5">مثلاً برای دایره‌ی توپُرِ «خوب»، متغیرِ تیکِ گزینه‌ی «خوب» (مثل overall_good) را بنویسید تا فقط برای وضعیتِ خوب کشیده شود.</p></div>
                <label class="flex items-center gap-2 text-[11px] font-bold text-slate-600 mt-3 cursor-pointer"><input type="checkbox" class="accent-red-500 vre-hid" ${sel.every(b => b.hidden) ? 'checked' : ''}> پنهان (در PDF چاپ نشود)</label>
                <button type="button" class="vr-btn vr-btn-s w-full mt-3 !text-[11px] vre-reset"><i class="fas fa-eraser"></i> برگرداندن به حالتِ قالب</button>
                ${one ? `<div class="grid grid-cols-2 gap-2 mt-2"><button type="button" class="vr-btn vr-btn-s !text-[11px] vre-clone" title="یک کپی از این کادر/شکل کنارش ساخته می‌شود (مثلاً دایره‌ی تیک برای گزینه‌ی دیگر)"><i class="fas fa-clone"></i> کپی این کادر</button>
                    ${one.clone ? '<button type="button" class="vr-btn vr-btn-s !text-[11px] !text-red-600 vre-delclone"><i class="fas fa-trash"></i> حذف کپی</button>' : ''}</div>` : ''}`;
            const apply = (fn) => { pushUndo(); sel.forEach(fn); refreshBoxes(sel.map(b => b.index)); renderPropsSoft(); };
            const num = v => { const n = parseFloat(en(String(v)).replace(/[^\d.\-]/g, '')); return isNaN(n) ? null : n; };
            const dxI = props.querySelector('.vre-dx'), dyI = props.querySelector('.vre-dy');
            const onNum = (inp, k) => inp.addEventListener('change', () => {
                const v = num(inp.value); if (v === null) return;
                if (one) apply(b => { b[k] = round(v); }); else { apply(b => { b[k] = round((b[k] || 0) + v); }); inp.value = ''; }
            });
            onNum(dxI, 'dx'); onNum(dyI, 'dy');
            props.querySelectorAll('.vre-nudge').forEach(x => x.onclick = () => apply(b => { b.dx = round((b.dx || 0) + Number(x.dataset.x) * 0.5); b.dy = round((b.dy || 0) + Number(x.dataset.y) * 0.5); }));
            props.querySelector('.vre-font').onchange = e => { if (e.target.value === '__keep') return; apply(b => { b.font = e.target.value || ''; }); };
            props.querySelector('.vre-fsize').onchange = e => { const v = num(e.target.value); apply(b => { b.fsize = v && v >= 3 && v <= 72 ? v : null; }); };
            props.querySelector('.vre-hid').onchange = e => apply(b => { b.hidden = e.target.checked; });
            props.querySelector('.vre-showif').onchange = e => {
                const v = e.target.value.trim();
                if (v !== '' && !/^[A-Za-z][A-Za-z0-9_]*$/.test(v)) { toast('نامِ متغیر فقط حروف و عدد لاتین و _ است.', 'error'); return; }
                apply(b => { b.showif = v; }); drawPage();
            };
            props.querySelector('.vre-reset').onclick = () => { apply(b => { b.dx = 0; b.dy = 0; b.hidden = false; b.font = ''; b.fsize = null; b.text = null; b.showif = ''; }); renderProps(); };
            // کپی/حذفِ کادر: اول تغییرها ذخیره و بعد ویرایشگر با چیدمانِ تازه دوباره باز می‌شود
            const reopen = async (payload) => {
                if (!await save()) return;
                const r = await api('set_layout_clone', { id: cat.id, index: one.index, ...payload });
                if (!r.ok) { toast(r.error, 'error'); return; }
                document.removeEventListener('keydown', onKey); m.close();
                VR.openLayoutEditor(cat, onSaved);
                toast(payload.delete ? 'کپی حذف شد.' : 'کپی ساخته شد؛ آن را روی جای درست بکشید و «شرطِ نمایش» بدهید.', 'info');
            };
            const cl = props.querySelector('.vre-clone'); if (cl) cl.onclick = () => reopen({});
            const dc = props.querySelector('.vre-delclone'); if (dc) dc.onclick = () => reopen({ delete: 1 });
            if (one) {
                const ta = props.querySelector('.vre-text');
                ta.onchange = () => apply(b => { b.text = ta.value.trim() === '' || ta.value === b.orig ? null : ta.value; });
                const vi = props.querySelector('.vre-var');
                const nameOk = () => { const v = vi.value.trim(); if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(v)) { toast('نامِ متغیر فقط حروف و عدد لاتین و _ است.', 'error'); return null; } return v; };
                props.querySelector('.vre-setvar').onclick = () => { const v = nameOk(); if (v) { apply(b => { b.text = `{{ ${v} }}`; }); renderProps(); } };
                props.querySelector('.vre-addvar').onclick = () => { const v = nameOk(); if (v) { apply(b => { b.text = (boxText(b) + ` {{ ${v} }}`).trim(); }); renderProps(); } };
            }
        }
        // در حینِ کشیدن فقط عددها به‌روز شوند (بدونِ ساختنِ دوباره‌ی پنل)
        function renderPropsSoft() {
            const sel = [...st.sel].map(i => byIdx.get(i)).filter(Boolean);
            if (sel.length !== 1) return;
            const dxI = props.querySelector('.vre-dx'), dyI = props.querySelector('.vre-dy');
            if (dxI && document.activeElement !== dxI) dxI.value = fa(sel[0].dx || 0);
            if (dyI && document.activeElement !== dyI) dyI.value = fa(sel[0].dy || 0);
        }

        // ---------------- موس و لمس ----------------
        let drag = null;
        pageEl.addEventListener('pointerdown', e => {
            if (e.button !== 0) return;
            const boxEl = e.target.closest('.vre-box');
            const add = e.ctrlKey || e.metaKey || e.shiftKey;
            const rect = pageEl.getBoundingClientRect();
            if (boxEl) {
                const i = Number(boxEl.dataset.i);
                if (add) { st.sel.has(i) ? st.sel.delete(i) : st.sel.add(i); drawPage(); renderProps(); return; }
                if (!st.sel.has(i)) { st.sel = new Set([i]); drawPage(); renderProps(); }
                drag = { mode: 'move', x0: e.clientX, y0: e.clientY, start: [...st.sel].map(j => [j, byIdx.get(j).dx || 0, byIdx.get(j).dy || 0]), snap: snapshot(), moved: false };
            } else {
                if (!add) st.sel.clear();
                const band = document.createElement('div'); band.className = 'vre-band'; pageEl.appendChild(band);
                drag = { mode: 'band', bx: e.clientX - rect.left, by: e.clientY - rect.top, band, add, keep: new Set(st.sel) };
                drawPage(); pageEl.appendChild(band); renderProps();
            }
            pageEl.setPointerCapture(e.pointerId);
            e.preventDefault();
        });
        pageEl.addEventListener('pointermove', e => {
            if (!drag) return;
            if (drag.mode === 'move') {
                const ddx = (e.clientX - drag.x0) / st.scale, ddy = (e.clientY - drag.y0) / st.scale;
                if (!drag.moved && Math.abs(ddx) + Math.abs(ddy) < 1) return;
                drag.moved = true;
                drag.start.forEach(([j, x, y]) => { const b = byIdx.get(j); b.dx = round(x + ddx); b.dy = round(y + ddy); });
                refreshBoxes(drag.start.map(s => s[0])); renderPropsSoft();
            } else {
                const rect = pageEl.getBoundingClientRect();
                const cx = e.clientX - rect.left, cy = e.clientY - rect.top;
                const l = Math.min(cx, drag.bx), t = Math.min(cy, drag.by), w = Math.abs(cx - drag.bx), h = Math.abs(cy - drag.by);
                Object.assign(drag.band.style, { left: l + 'px', top: t + 'px', width: w + 'px', height: h + 'px' });
                drag.rect = [l / st.scale, t / st.scale, (l + w) / st.scale, (t + h) / st.scale];
            }
        });
        const endDrag = () => {
            if (!drag) return;
            if (drag.mode === 'move' && drag.moved) { st.undo.push(drag.snap); st.redo = []; st.dirty = true; renderProps(); }
            if (drag.mode === 'band') {
                drag.band.remove();
                if (drag.rect) {
                    const [l, t, r, b] = drag.rect;
                    st.sel = new Set(drag.add ? drag.keep : []);
                    const showStatic = !stage.classList.contains('vre-hide-static');
                    st.boxes.forEach(bx => {
                        if (bx.page !== st.page) return;
                        if (!showStatic && !/\{\{/.test(boxText(bx)) && !changed(bx)) return;
                        const x = bx.x + (bx.dx || 0), y = bx.y + (bx.dy || 0);
                        if (x < r && x + bx.w > l && y < b && y + bx.h > t) st.sel.add(bx.index);
                    });
                }
                drawPage(); renderProps();
            }
            drag = null;
        };
        pageEl.addEventListener('pointerup', endDrag);
        pageEl.addEventListener('pointercancel', endDrag);

        const onKey = e => {
            if (!document.body.contains(m.el)) { document.removeEventListener('keydown', onKey); return; }
            if (/INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '')) return;
            const k = e.key;
            if ((e.ctrlKey || e.metaKey) && k.toLowerCase() === 'z') { e.preventDefault(); doUndo(); return; }
            if ((e.ctrlKey || e.metaKey) && k.toLowerCase() === 'y') { e.preventDefault(); doRedo(); return; }
            if ((e.ctrlKey || e.metaKey) && k.toLowerCase() === 'a') {
                e.preventDefault();
                const showStatic = !stage.classList.contains('vre-hide-static');
                st.sel = new Set(st.boxes.filter(b => b.page === st.page && (showStatic || /\{\{/.test(boxText(b)) || changed(b))).map(b => b.index));
                drawPage(); renderProps(); return;
            }
            if (k === 'Escape' && st.sel.size) { e.stopPropagation(); st.sel.clear(); drawPage(); renderProps(); return; }
            const dir = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[k];
            if (dir && st.sel.size) {
                e.preventDefault();
                const step = e.shiftKey ? 5 : 0.5;
                if (!e.repeat) pushUndo(); else st.dirty = true;
                st.sel.forEach(i => { const b = byIdx.get(i); b.dx = round((b.dx || 0) + dir[0] * step); b.dy = round((b.dy || 0) + dir[1] * step); });
                refreshBoxes([...st.sel]); renderPropsSoft();
            }
        };
        document.addEventListener('keydown', onKey);

        function doUndo() { if (!st.undo.length) return; st.redo.push(snapshot()); restore(st.undo.pop()); st.dirty = true; drawPage(); renderProps(); }
        function doRedo() { if (!st.redo.length) return; st.undo.push(snapshot()); restore(st.redo.pop()); st.dirty = true; drawPage(); renderProps(); }

        // ---------------- نوار ابزار ----------------
        m.body.querySelectorAll('.vre-pages button').forEach(x => x.onclick = () => { st.page = Number(x.dataset.p); st.sel.clear(); drawPage(); renderProps(); });
        m.body.querySelector('.vre-zi').onclick = () => { st.zoom = Math.min(4, st.zoom * 1.25); drawPage(); };
        m.body.querySelector('.vre-zo').onclick = () => { st.zoom = Math.max(0.4, st.zoom / 1.25); drawPage(); };
        m.body.querySelector('.vre-zf').onclick = () => { st.zoom = 1; drawPage(); };
        m.body.querySelector('.vre-static').onchange = e => { stage.classList.toggle('vre-hide-static', !e.target.checked && !st.sample); };
        // حالتِ نمونه: همه‌ی کادرها (حتی متنِ ثابت) مثلِ خروجی دیده می‌شوند و همچنان جابه‌جا می‌شوند
        const srcSel = m.body.querySelector('.vre-src');
        m.body.querySelector('.vre-samp').onchange = e => {
            st.sample = e.target.checked;
            stage.classList.toggle('vre-sample', st.sample);
            stage.classList.toggle('vre-hide-static', !st.sample && !m.body.querySelector('.vre-static').checked);
            srcSel.classList.toggle('hidden', !st.sample);
            drawPage();
        };
        srcSel.onchange = async () => {
            if (!srcSel.value) { st.values = d.sample || {}; drawPage(); return; }
            const r = await api('set_layout_sample', { id: cat.id, report_id: Number(srcSel.value) });
            if (!r.ok) { toast(r.error, 'error'); return; }
            st.values = r.values; drawPage();
        };
        m.body.querySelector('.vre-imgs').onchange = drawPage;
        m.body.querySelector('.vre-undo').onclick = doUndo;
        m.body.querySelector('.vre-redo').onclick = doRedo;
        m.body.querySelector('.vre-fontall').onchange = e => { st.fontAll = e.target.value; st.dirty = true; };
        const save = async () => {
            const items = st.boxes.filter(b => changed(b) || b._init !== JSON.stringify(PROPS.map(k => b[k] ?? null)))
                .map(b => ({ index: b.index, dx: b.dx || 0, dy: b.dy || 0, hidden: !!b.hidden, font: b.font || '', fsize: b.fsize || null, text: b.text ?? null, orig: b.orig, showif: b.showif || '' }));
            const r = await api('set_layout_adjust', { id: cat.id, items, font_all: st.fontAll });
            if (!r.ok) { toast(r.error, 'error'); return false; }
            st.boxes.forEach(b => { b._init = JSON.stringify(PROPS.map(k => b[k] ?? null)); });
            st.dirty = false;
            toast('تنظیم‌های قالب ذخیره شد.', 'info');
            if (onSaved) onSaved();
            return true;
        };
        m.body.querySelector('.vre-save').onclick = save;
        m.body.querySelector('.vre-prev').onclick = async () => { if (await save()) window.open(`${API}?action=set_template_preview&id=${cat.id}&t=${Date.now()}`, '_blank'); };
        // بستن با تغییراتِ ذخیره‌نشده
        const closeBtn = m.el.querySelector('.vr-m-close');
        closeBtn.onclick = async () => {
            if (st.dirty && !await confirmBox('تغییراتِ ذخیره‌نشده', 'تغییراتِ ویرایشگر ذخیره نشده است. بدونِ ذخیره بسته شود؟', { danger: true, ok: 'بستن بدونِ ذخیره' })) return;
            document.removeEventListener('keydown', onKey); m.close();
        };
        window.addEventListener('resize', () => { if (document.body.contains(m.el)) drawPage(); });
        drawPage(); renderProps();
    };
})();
