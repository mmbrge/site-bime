// فایل: table-ctx.js
// منوی کلیک‌راستِ یکسان برای «همه‌ی جدول‌های سایت» (پنل، بیمه عمر، مالی، نامه‌ها، پورتالِ شرکت‌ها):
//   ۱) کارهای خودِ همان ردیف: «باز کردن» (اگر ردیف کلیک‌پذیر است) و همه‌ی دکمه‌ها/پیوندهای داخلِ ردیف — خودکار، پس هر جدول گزینه‌های مخصوصِ خودش را دارد
//   ۲) گزینه‌های اختصاصیِ ثبت‌شده برای آن جدول: TableCtx.register(selector, (row, info) => [{icon, label, run, cls, danger}])
//   ۳) گزینه‌های ثابتِ همه‌ی جدول‌ها: کپیِ خانه/ردیف/کلِ جدول، خروجیِ اکسلِ همین جدول، جستجو در جدول، مرتب‌سازی با همین ستون
//   ۴) گزینه‌های عمومی (کپی/چسباندن، بارگذاریِ دوباره، خروج) — از window.TABLE_CTX_GENERAL
// پیام‌رسان و بخش‌هایی که منوی خودشان را دارند (data-noctx یا preventDefaultِ خودشان) دست نمی‌خورند.
(function () {
    'use strict';
    if (window.TableCtx) return;
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => FA.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const toast = (m, t) => (typeof window.showToast === 'function' ? window.showToast(m, t || 'success') : null);
    const REG = [];
    let menu = null, last = null;

    function css() {
        if (document.getElementById('tcx-css')) return;
        const s = document.createElement('style'); s.id = 'tcx-css';
        s.textContent = `
        .tcx{position:fixed;z-index:2147483600;min-width:230px;max-width:300px;max-height:calc(100vh - 16px);overflow-y:auto;padding:6px 0;border-radius:14px;direction:rtl;font-family:inherit;
            background:rgba(20,20,24,.92);color:#e5e7eb;border:1px solid rgba(255,255,255,.14);box-shadow:0 16px 44px rgba(0,0,0,.55);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);animation:tcxIn .1s ease-out}
        @keyframes tcxIn{from{transform:scale(.96);opacity:0}}
        .tcx-h{padding:6px 14px 8px;font-size:11px;font-weight:800;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:4px}
        .tcx-h b{color:#e2e8f0}
        .tcx-g{padding:4px 14px 2px;font-size:10px;font-weight:800;color:#64748b}
        .tcx-i{display:flex;align-items:center;gap:10px;padding:8px 12px;margin:1px 6px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .tcx-i:hover,.tcx-i.on{background:rgba(255,255,255,.1);color:#fff}
        .tcx-i i{width:16px;text-align:center;flex-shrink:0;opacity:.9}
        .tcx-i.blue{color:#93c5fd}.tcx-i.green{color:#86efac}.tcx-i.amber{color:#fcd34d}.tcx-i.red{color:#fca5a5}.tcx-i.muted{color:#cbd5e1}
        .tcx-d{border-top:1px solid rgba(255,255,255,.08);margin:5px 0}
        .tcx-filter{display:flex;align-items:center;gap:8px;margin:0 0 8px;padding:6px 10px;border:1px solid #c7d2fe;border-radius:12px;background:#eef2ff;font-size:12px;color:#3730a3}
        .tcx-filter input{flex:1;border:0;background:transparent;outline:0;font:inherit;font-size:12.5px;color:#1e1b4b;min-width:60px}
        .tcx-filter button{border:0;background:#c7d2fe;color:#3730a3;border-radius:8px;padding:2px 8px;cursor:pointer;font:inherit}
        tr.tcx-hit>td{background:rgba(99,102,241,.08)!important}`;
        document.head.appendChild(s);
    }
    function hide() { if (menu) { menu.remove(); menu = null; } if (last && last.row) last.row.classList.remove('tcx-hit'); last = null; }
    const visible = el => !!(el && (el.offsetParent || el.getClientRects().length));
    const cellText = c => (c ? (c.innerText || c.textContent || '') : '').replace(/[ \t]+/g, ' ').replace(/\n+/g, ' ').trim();
    const rowsOf = t => Array.prototype.filter.call(t.querySelectorAll(':scope > tbody > tr, :scope > tr'), r => !r.closest('thead'));
    const dataRow = r => r && r.cells && !(r.cells.length === 1 && r.cells[0].colSpan > 1);
    async function copy(text, what) {
        try { await navigator.clipboard.writeText(text); toast((what || 'متن') + ' کپی شد.'); }
        catch (e) {
            const t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); toast((what || 'متن') + ' کپی شد.'); } catch (e2) { toast('کلیپ‌بورد در دسترس نیست.', 'error'); }
            t.remove();
        }
    }
    function headers(t) {
        const h = t.querySelector('thead tr') || null;
        return h ? Array.prototype.map.call(h.cells, cellText) : [];
    }
    function tsv(t) {
        const out = [];
        const hs = headers(t); if (hs.length) out.push(hs.join('\t'));
        rowsOf(t).filter(r => dataRow(r) && r.style.display !== 'none').forEach(r => out.push(Array.prototype.map.call(r.cells, cellText).join('\t')));
        return out.join('\n');
    }
    function exportXls(t) {
        const hs = headers(t);
        const rows = rowsOf(t).filter(r => dataRow(r) && r.style.display !== 'none').map(r => Array.prototype.map.call(r.cells, cellText));
        const cell = v => `<td style="mso-number-format:'\\@'">${esc(v)}</td>`;
        const html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body dir="rtl"><table border="1">`
            + (hs.length ? `<tr>${hs.map(h => `<th style="background:#e0e7ff">${esc(h)}</th>`).join('')}</tr>` : '')
            + rows.map(r => `<tr>${r.map(cell).join('')}</tr>`).join('') + '</table></body></html>';
        const title = (document.querySelector('.tab-content:not(.hidden) h2, .tab-content:not(.hidden) h3') || {}).textContent || 'جدول';
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['\ufeff' + html], {type: 'application/vnd.ms-excel'}));
        a.download = (title.trim().slice(0, 40) || 'جدول') + '.xls';
        document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 800);
        toast(rows.length + ' ردیف به اکسل رفت.');
    }
    function filterBar(t) {
        css();
        let bar = t.__tcxBar;
        if (!bar || !bar.isConnected) {
            bar = document.createElement('div'); bar.className = 'tcx-filter';
            bar.innerHTML = '<i class="fas fa-filter"></i><input placeholder="جستجو در همین جدول (Esc: پاک کردن)"><span data-n></span><button type="button" title="بستن">×</button>';
            const host = t.parentElement && /overflow|scroll|wrap/i.test(t.parentElement.className + ' ' + (t.parentElement.getAttribute('style') || '')) ? t.parentElement : t;
            host.parentElement.insertBefore(bar, host);
            t.__tcxBar = bar;
            const inp = bar.querySelector('input'), n = bar.querySelector('[data-n]');
            const run = () => {
                const q = en(inp.value).trim().toLowerCase(); let c = 0;
                rowsOf(t).forEach(r => { if (!dataRow(r)) return; const ok = !q || en(cellText(r)).toLowerCase().includes(q); r.style.display = ok ? '' : 'none'; if (ok) c++; });
                n.textContent = q ? en(c) .replace(/\d/g, d => FA[d]) + ' ردیف' : '';
            };
            inp.oninput = run;
            inp.onkeydown = e => { if (e.key === 'Escape') { inp.value = ''; run(); } };
            bar.querySelector('button').onclick = () => { inp.value = ''; run(); bar.remove(); t.__tcxBar = null; };
        }
        bar.querySelector('input').focus();
    }
    function sortBy(t, idx, dir) {
        const body = t.tBodies[0] || t;
        const rows = rowsOf(t).filter(dataRow);
        const val = r => cellText(r.cells[idx]);
        const num = s => { const x = en(s).replace(/[,٬،\s]/g, ''); return /^-?\d+(\.\d+)?$/.test(x) ? parseFloat(x) : null; };
        rows.sort((a, b) => {
            const A = val(a), B = val(b), na = num(A), nb = num(B);
            const r = na !== null && nb !== null ? na - nb : en(A).localeCompare(en(B), 'fa');
            return dir * r;
        });
        rows.forEach(r => body.appendChild(r));
        toast('مرتب شد (فقط نمایش؛ با بارگذاریِ دوباره به ترتیبِ اول برمی‌گردد).', 'info');
    }
    function rowActions(row) {
        const out = [], seen = new Set();
        if (row.onclick || row.getAttribute('onclick')) {
            out.push({icon: 'fa-up-right-from-square', label: 'باز کردن / جزئیات', cls: 'blue', run: () => { const td = Array.prototype.find.call(row.cells, c => !c.querySelector('input,button,a,select')) || row; td.click(); }});
        }
        row.querySelectorAll('button, a[href], [role="button"], [onclick]:not(tr):not(td)').forEach(el => {
            if (el.disabled || !visible(el) || el.closest('[data-noctx]') || el.matches('input,select,textarea,label')) return;
            if (el.tagName === 'A' && /^#?$/.test(el.getAttribute('href') || '') && !el.getAttribute('onclick')) return;
            let label = (el.getAttribute('title') || el.getAttribute('aria-label') || cellText(el) || '').trim();
            if (!label || label.length > 48) label = label.slice(0, 46);
            // برچسبِ کوتاه و کلی («کپی»، «باز کردن»): نامِ ستونش کنارش می‌آید تا معلوم باشد کدام است
            if (label && label.length < 8) {
                const td = el.closest('td'), t = el.closest('table'), hd = t && t.querySelector('thead tr');
                const hn = td && hd && hd.cells[td.cellIndex] ? cellText(hd.cells[td.cellIndex]) : '';
                if (hn && hn !== label) label += ' (' + hn.slice(0, 20) + ')';
            }
            if (!label || seen.has(label)) return;
            seen.add(label);
            const ic = el.querySelector('i[class*="fa-"]');
            const icon = ic ? (ic.className.match(/fa-(?!solid|regular|fw|ml|mr)[a-z0-9-]+/g) || []).pop() : 'fa-hand-pointer';
            const danger = /حذف|ابطال|رد |لغو|delete|trash/i.test(label + ' ' + (ic ? ic.className : ''));
            out.push({icon: icon || 'fa-hand-pointer', label, cls: danger ? 'red' : '', run: () => el.click()});
        });
        return out.slice(0, 10);
    }
    function build(e, t, row, cell) {
        const items = [];
        const sel = String(window.getSelection ? window.getSelection() : '').trim();
        const isHead = !!(cell && cell.closest('thead'));
        if (row && !isHead && dataRow(row)) {
            const title = Array.prototype.map.call(row.cells, cellText).filter(x => x && x.length > 1 && !/^[\d۰-۹]+$/.test(x)).slice(0, 2).join(' · ');
            items.push({head: title});
            const ra = rowActions(row);
            if (ra.length) { items.push({group: 'کارهای این ردیف'}); items.push(...ra); }
            REG.forEach(([selr, fn]) => {
                if (!t.matches(selr) && !t.closest(selr)) return;
                try { const x = fn(row, {table: t, cell, event: e}) || []; if (x.length) { items.push({div: 1}); items.push(...x); } } catch (err) { console.error(err); }
            });
            items.push({div: 1}, {group: 'همه‌ی جدول‌ها'});
            if (sel) items.push({icon: 'fa-copy', label: 'کپیِ متنِ انتخاب‌شده', run: () => copy(sel)});
            if (cell && cellText(cell)) items.push({icon: 'fa-clone', label: 'کپیِ همین خانه: «' + cellText(cell).slice(0, 24) + (cellText(cell).length > 24 ? '…' : '') + '»', run: () => copy(cellText(cell), 'خانه')});
            items.push({icon: 'fa-grip-lines', label: 'کپیِ کلِ این ردیف', run: () => copy(Array.prototype.map.call(row.cells, cellText).join('\t'), 'ردیف')});
        } else {
            items.push({head: 'جدول'});
            if (sel) items.push({icon: 'fa-copy', label: 'کپیِ متنِ انتخاب‌شده', run: () => copy(sel)});
        }
        if (cell && isHead) {
            const idx = Array.prototype.indexOf.call(cell.parentElement.cells, cell);
            items.push({icon: 'fa-arrow-down-short-wide', label: 'مرتب‌سازی: «' + cellText(cell).slice(0, 18) + '» صعودی', run: () => sortBy(t, idx, 1)});
            items.push({icon: 'fa-arrow-down-wide-short', label: 'مرتب‌سازی: «' + cellText(cell).slice(0, 18) + '» نزولی', run: () => sortBy(t, idx, -1)});
        } else if (cell && t.querySelector('thead')) {
            const idx = Array.prototype.indexOf.call(cell.parentElement.cells, cell);
            const hd = t.querySelector('thead tr'); const hn = hd && hd.cells[idx] ? cellText(hd.cells[idx]) : '';
            if (hn) items.push({icon: 'fa-arrow-down-a-z', label: 'مرتب‌سازی با ستونِ «' + hn.slice(0, 18) + '»', run: () => sortBy(t, idx, t.__tcxDir = -(t.__tcxDir || -1))});
        }
        items.push({icon: 'fa-magnifying-glass', label: 'جستجو در همین جدول', run: () => filterBar(t)});
        items.push({icon: 'fa-table', label: 'کپیِ کلِ جدول (برای اکسل)', run: () => copy(tsv(t), 'جدول')});
        items.push({icon: 'fa-file-excel', label: 'خروجیِ اکسلِ همین جدول', cls: 'green', run: () => exportXls(t)});
        const gen = window.TABLE_CTX_GENERAL;
        if (gen && gen.length) { items.push({div: 1}); items.push(...gen); }
        return items;
    }
    function open(e, items, row) {
        css(); hide();
        menu = document.createElement('div'); menu.className = 'tcx'; menu.setAttribute('data-noctx', '');
        menu.innerHTML = items.map((it, i) => it.head !== undefined ? (it.head ? `<div class="tcx-h"><b>${esc(it.head)}</b></div>` : '')
            : it.group ? `<div class="tcx-g">${esc(it.group)}</div>` : it.div ? '<div class="tcx-d"></div>'
            : `<div class="tcx-i ${it.cls || ''}" data-i="${i}"><i class="fas ${esc(it.icon || 'fa-circle')}"></i><span>${esc(it.label)}</span></div>`).join('');
        document.body.appendChild(menu);
        menu.querySelectorAll('[data-i]').forEach(el => el.onclick = ev => { ev.stopPropagation(); const it = items[+el.dataset.i]; hide(); try { it.run(); } catch (err) { console.error(err); } });
        const mw = menu.offsetWidth, mh = menu.offsetHeight;
        let x = e.clientX, y = e.clientY;
        if (x + mw > innerWidth - 8) x = Math.max(8, x - mw);
        if (y + mh > innerHeight - 8) y = Math.max(8, innerHeight - mh - 8);
        menu.style.left = x + 'px'; menu.style.top = y + 'px';
        last = {row};
        if (row) row.classList.add('tcx-hit');
    }
    // از منوی کلیک‌راستِ صفحه صدا زده می‌شود؛ اگر روی جدول بود منو را خودش باز می‌کند و true برمی‌گرداند
    function handle(e) {
        const tgt = e.target instanceof Element ? e.target : null;
        if (!tgt || e.defaultPrevented && !e.__tcx) return false;
        if (tgt.closest('[data-noctx], .cx, .tcx, input, textarea, select, [contenteditable="true"]')) return false;
        const t = tgt.closest('table');
        if (!t || t.closest('[data-noctx]')) return false;
        const cell = tgt.closest('td, th'), row = tgt.closest('tr');
        e.preventDefault();
        open(e, build(e, t, row, cell), row && !(cell && cell.closest('thead')) ? row : null);
        return true;
    }
    document.addEventListener('click', e => { if (menu && !menu.contains(e.target)) hide(); }, true);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && menu) { e.stopPropagation(); hide(); } }, true);
    window.addEventListener('scroll', () => hide(), true);
    window.addEventListener('blur', hide);
    // صفحه‌هایی که منوی کلیک‌راستِ عمومیِ خودشان را ندارند (پورتال، ...): خودش گوش می‌دهد
    if (window.TABLE_CTX_STANDALONE) document.addEventListener('contextmenu', e => { handle(e); });
    window.TableCtx = {
        register(selector, fn) { REG.push([selector, fn]); },
        handle, hide, copy
    };
})();
