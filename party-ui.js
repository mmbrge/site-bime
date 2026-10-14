// فایل: party-ui.js
// «مالک / بیمه‌گذار / ذینفع»ِ ردیف‌های درخواستِ شرکتی - مشترکِ پنلِ ما، فرمِ ثبتِ دستی و پنلِ شرکت‌ها.
//   COMPANY      خودرو به نامِ خودِ شرکت  => مالک و بیمه‌گذار: شرکت
//   BENEFICIARY  خودرو به نامِ دیگری     => بیمه‌گذار: شرکت، مالک «ذینفع»
//   OWNER        خودرو به نامِ دیگری     => مالک و بیمه‌گذار: همان شخص/شرکت
(function () {
    if (window.PartyUI) return;
    const MODES = {
        COMPANY: 'خودرو به نامِ خودِ شرکت (مالک و بیمه‌گذار: شرکت)',
        BENEFICIARY: 'به نامِ شخص/شرکتِ دیگر · بیمه‌گذار: شرکت · مالک = ذینفع',
        OWNER: 'به نامِ شخص/شرکتِ دیگر · بیمه‌گذار: همان مالک',
    };
    const SHORT = {COMPANY: 'خودِ شرکت', BENEFICIARY: 'ذینفع', OWNER: 'مالک = بیمه‌گذار'};
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const en = s => String(s ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));

    const css = `
    .pty-chip{display:inline-flex;flex-wrap:wrap;align-items:center;gap:4px;font-size:10px;line-height:1.7;margin-top:3px}
    .pty-chip .pty-b{border-radius:999px;padding:0 7px;font-weight:800;border:1px solid}
    .pty-chip .m-COMPANY{background:#f1f5f9;color:#475569;border-color:#e2e8f0}
    .pty-chip .m-BENEFICIARY{background:#fef3c7;color:#92400e;border-color:#fde68a}
    .pty-chip .m-OWNER{background:#e0e7ff;color:#3730a3;border-color:#c7d2fe}
    .pty-chip .pty-n{color:#64748b}.pty-chip .pty-n b{color:#334155}
    .pty-chip .pty-warn{color:#b45309;font-weight:800}
    .pty-chip button{color:#2563eb;text-decoration:underline;font-size:10px;background:none;border:0;cursor:pointer;padding:0}
    .pty-ed{border:1px solid #e2e8f0;border-radius:12px;padding:8px;background:#f8fafc;font-size:12px}
    .pty-ed select,.pty-ed input{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:6px 8px;font-size:12px;background:#fff;color:#0f172a;font-family:inherit;box-sizing:border-box}
    .pty-ed label{display:block;font-size:10.5px;color:#64748b;font-weight:700;margin:6px 0 3px}
    .pty-ed .pty-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:6px}
    .pty-ed .pty-kind{display:flex;gap:10px;margin-top:6px;font-size:11.5px}
    .pty-ed .pty-kind label{display:inline-flex;align-items:center;gap:4px;margin:0;color:#334155}
    .pty-ed .pty-kind input{width:auto}
    .pty-ed .pty-hint{font-size:10.5px;color:#64748b;margin-top:6px;line-height:1.8}
    .pty-ov{position:fixed;inset:0;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;padding:12px;z-index:1000050}
    .pty-box{background:#fff;color:#0f172a;border-radius:18px;box-shadow:0 25px 60px rgba(0,0,0,.35);width:100%;max-width:560px;max-height:92vh;overflow:auto;padding:16px;direction:rtl;text-align:right}
    .pty-box h3{font-weight:900;font-size:14px;margin:0 0 4px}
    .pty-box .pty-sub{font-size:11px;color:#64748b;margin:0 0 10px;line-height:1.9}
    .pty-box .pty-btns{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
    .pty-box .pty-btns button{border:0;border-radius:11px;padding:8px 16px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit}
    .pty-box .pty-ok{background:#0f766e;color:#fff}.pty-box .pty-cancel{background:#f1f5f9;color:#334155}
    .pty-box .pty-same{display:flex;gap:6px;align-items:flex-start;font-size:11px;color:#334155;margin-top:8px;line-height:1.8}
    @media (prefers-color-scheme: dark){ html.dark .pty-box{background:#0f172a;color:#e2e8f0} }`;
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

    // نمایشِ کوتاه روی هر ردیف. p: خروجیِ party از سرور (mode, owner, insured, beneficiary, mismatch, explicit)
    function chip(p, opts = {}) {
        if (!p) return '';
        const name = v => typeof v === 'string' ? v : (v && v.name) || '';
        const owner = name(p.owner), insured = name(p.insured), ben = name(p.beneficiary);
        const parts = [];
        if (p.mode === 'COMPANY') parts.push(`<span class="pty-n">مالک و بیمه‌گذار: <b>${esc(insured || 'شرکت')}</b></span>`);
        else {
            parts.push(`<span class="pty-n">بیمه‌گذار: <b>${esc(insured || '—')}</b></span>`);
            parts.push(`<span class="pty-n">مالک${p.mode === 'BENEFICIARY' ? ' (ذینفع)' : ''}: <b>${esc(owner || 'از مدارک')}</b></span>`);
        }
        const warn = p.mismatch ? `<span class="pty-warn" title="مالکِ کارت/سند: ${esc(name(p.doc_owner))}">⚠ مالکِ مدرک فرق دارد${p.doc_owner ? ' (' + esc(name(p.doc_owner)) + ')' : ''}</span>` : '';
        const btn = opts.onEdit ? `<button type="button" onclick="${opts.onEdit}">تعیین</button>` : '';
        return `<span class="pty-chip"><span class="pty-b m-${p.mode}" title="${esc(MODES[p.mode] || '')}">${SHORT[p.mode] || ''}${p.explicit ? '' : ' (طبقِ کلی)'}</span>${parts.join(' · ')}${warn}${btn}</span>`;
    }

    // ویرایشگرِ درون‌فرم: value = {mode, party:{kind,name,national_id,phone,address}}
    function editor(value = {}, opts = {}) {
        const mode = value.mode || value.party_mode || opts.defaultMode || '';
        const pt = value.party || value.other || {};
        const id = 'pty' + Math.random().toString(36).slice(2, 8);
        const opt = (k, l) => `<option value="${k}" ${mode === k ? 'selected' : ''}>${l}</option>`;
        return `<div class="pty-ed" data-pty="${id}">
            <select data-pty-mode onchange="PartyUI._toggle(this)">
                ${opts.allowInherit ? opt('', opts.inheritLabel || 'طبقِ انتخابِ کلی (همه‌ی ردیف‌ها)') : ''}
                ${opt('COMPANY', MODES.COMPANY)}${opt('BENEFICIARY', MODES.BENEFICIARY)}${opt('OWNER', MODES.OWNER)}
            </select>
            <div data-pty-other style="display:${mode === 'BENEFICIARY' || mode === 'OWNER' ? '' : 'none'}">
                <div class="pty-kind"><span style="color:#64748b">مالک:</span>
                    <label><input type="radio" name="${id}-k" value="PERSON" ${pt.kind !== 'COMPANY' ? 'checked' : ''}> شخص</label>
                    <label><input type="radio" name="${id}-k" value="COMPANY" ${pt.kind === 'COMPANY' ? 'checked' : ''}> شرکت</label></div>
                <div class="pty-grid">
                    <div><label>نامِ مالک</label><input data-pty-f="name" value="${esc(pt.name || '')}" placeholder="مثلاً علی رضایی / شرکت ..."></div>
                    <div><label>کد ملی / شناسه ملی</label><input data-pty-f="national_id" dir="ltr" inputmode="numeric" value="${esc(fa(pt.national_id || ''))}"></div>
                    <div><label>تلفن (اختیاری)</label><input data-pty-f="phone" dir="ltr" inputmode="tel" value="${esc(fa(pt.phone || ''))}"></div>
                </div>
                <p class="pty-hint">اگر خالی بماند، مالک از کارت/سندِ بارگذاری‌شده‌ی همان ردیف خوانده می‌شود.</p>
            </div>
        </div>`;
    }
    function _toggle(sel) {
        const o = sel.closest('.pty-ed').querySelector('[data-pty-other]');
        if (o) o.style.display = ['BENEFICIARY', 'OWNER'].includes(sel.value) ? '' : 'none';
    }
    // خواندنِ مقدار از ویرایشگر: {party_mode, party}
    function collect(root) {
        const ed = root && (root.matches && root.matches('.pty-ed') ? root : root.querySelector('.pty-ed'));
        if (!ed) return {party_mode: '', party: null};
        const mode = ed.querySelector('[data-pty-mode]').value;
        if (!['BENEFICIARY', 'OWNER'].includes(mode)) return {party_mode: mode, party: null};
        const g = k => en((ed.querySelector(`[data-pty-f="${k}"]`) || {}).value || '').trim();
        const kind = (ed.querySelector('input[type=radio]:checked') || {}).value || 'PERSON';
        const party = {kind, name: g('name'), national_id: g('national_id').replace(/\D/g, ''), phone: g('phone')};
        return {party_mode: mode, party: party.name || party.national_id ? party : null};
    }

    // پنجره برای یک ردیف یا همه‌ی ردیف‌ها. o: {title, sub, value, bulk, onSave: async ({mode, party, keep_party}) => true|false}
    function open(o = {}) {
        const ov = document.createElement('div');
        ov.className = 'pty-ov';
        ov.innerHTML = `<div class="pty-box">
            <h3>${esc(o.title || 'مالک / بیمه‌گذار / ذینفع')}</h3>
            <p class="pty-sub">${o.sub || 'تعیین کنید خودرو به نامِ کیست و بیمه‌نامه به نامِ چه کسی صادر شود.'}</p>
            ${editor(o.value || {mode: 'COMPANY'})}
            ${o.bulk ? `<label class="pty-same"><input type="checkbox" data-pty-same ${o.value && o.value.party ? 'checked' : ''}> مالکِ همه‌ی ردیف‌ها همین شخص/شرکت است (اگر تیک نخورد، فقط نوع عوض می‌شود و مالکِ هر ردیف از اطلاعات/مدارکِ خودش می‌آید)</label>` : ''}
            <div class="pty-btns"><button class="pty-cancel" type="button">انصراف</button><button class="pty-ok" type="button">ذخیره</button></div>
        </div>`;
        document.body.appendChild(ov);
        const close = () => ov.remove();
        ov.addEventListener('click', e => { if (e.target === ov) close(); });
        ov.querySelector('.pty-cancel').onclick = close;
        ov.querySelector('.pty-ok').onclick = async function () {
            const v = collect(ov);
            const same = ov.querySelector('[data-pty-same]');
            const keep = o.bulk && v.party_mode !== 'COMPANY' && !(same && same.checked);
            if (o.bulk && same && same.checked && !v.party) { alert('نام یا کد/شناسه ملیِ مالک را وارد کنید.'); return; }
            this.disabled = true;
            let ok = true;
            try { ok = await o.onSave({mode: v.party_mode, party: keep ? null : v.party, keep_party: keep ? 1 : 0}); } catch (e) { ok = false; }
            this.disabled = false;
            if (ok !== false) close();
        };
        return ov;
    }

    // ---------------- کارکنان: معرفی‌نامه همیشه به نامِ پرسنل ----------------
    const PMODES = {
        self_owner: 'بیمه‌گذار و مالک: خودِ پرسنل',
        self_relative: 'بیمه‌گذار: خودِ پرسنل · مالک (ذینفع): همسر یا بستگان',
        relative: 'بیمه‌گذار و مالک: همسر یا بستگانِ پرسنل',
    };
    const PSHORT = {self_owner: 'خودِ پرسنل', self_relative: 'مالک = ذینفع', relative: 'بستگان'};
    const PREL = {self_relative: ['همسر', 'پدر', 'مادر', 'فرزند', 'سایر'], relative: ['همسر', 'پدر', 'مادر', 'فرزند']};
    function personnelEditor(v = {}) {
        const mode = v.mode || 'self_owner', own = (mode === 'relative' ? v.insured : v.owner) || {};
        const opt = k => `<option value="${k}" ${mode === k ? 'selected' : ''}>${PMODES[k]}</option>`;
        return `<div class="pty-ed" data-ppty>
            <select data-pp-mode onchange="PartyUI._ptoggle(this)">${opt('self_owner')}${opt('self_relative')}${opt('relative')}</select>
            <div data-pp-other style="display:${mode === 'self_owner' ? 'none' : ''}">
                <div class="pty-grid">
                    <div><label data-pp-rl>${mode === 'relative' ? 'نسبتِ بیمه‌گذار با پرسنل' : 'نسبتِ مالک با پرسنل'}</label><select data-pp-f="relation">${relOpts(mode, own.relation)}</select></div>
                    <div><label data-pp-nl>${mode === 'relative' ? 'نامِ بیمه‌گذار و مالک' : 'نامِ مالک (ذینفع)'}</label><input data-pp-f="name" value="${esc(own.name || '')}"></div>
                    <div><label>کد ملی</label><input data-pp-f="national_id" dir="ltr" inputmode="numeric" value="${esc(fa(own.national_id || ''))}"></div>
                </div>
                <p class="pty-hint" data-pp-hint>${mode === 'relative' ? 'معرفی‌نامه به نامِ پرسنل می‌ماند؛ بیمه‌نامه به نامِ همین شخص صادر می‌شود.' : 'بیمه‌نامه به نامِ پرسنل صادر می‌شود و مالکِ خودرو «ذینفع» نوشته می‌شود.'}</p>
            </div></div>`;
    }
    function relOpts(mode, sel) {
        return '<option value="">انتخاب…</option>' + (PREL[mode] || PREL.self_relative).map(r => `<option ${r === sel ? 'selected' : ''}>${r}</option>`).join('');
    }
    function _ptoggle(sel) {
        const ed = sel.closest('[data-ppty]'), m = sel.value, other = ed.querySelector('[data-pp-other]');
        other.style.display = m === 'self_owner' ? 'none' : '';
        const rs = ed.querySelector('[data-pp-f="relation"]'), cur = rs.value;
        rs.innerHTML = relOpts(m, cur);
        ed.querySelector('[data-pp-rl]').textContent = m === 'relative' ? 'نسبتِ بیمه‌گذار با پرسنل' : 'نسبتِ مالک با پرسنل';
        ed.querySelector('[data-pp-nl]').textContent = m === 'relative' ? 'نامِ بیمه‌گذار و مالک' : 'نامِ مالک (ذینفع)';
        ed.querySelector('[data-pp-hint]').textContent = m === 'relative' ? 'معرفی‌نامه به نامِ پرسنل می‌ماند؛ بیمه‌نامه به نامِ همین شخص صادر می‌شود.' : 'بیمه‌نامه به نامِ پرسنل صادر می‌شود و مالکِ خودرو «ذینفع» نوشته می‌شود.';
    }
    function personnelCollect(root) {
        const ed = root && (root.matches && root.matches('[data-ppty]') ? root : root.querySelector('[data-ppty]'));
        if (!ed) return null;
        const g = k => en((ed.querySelector(`[data-pp-f="${k}"]`) || {}).value || '').trim();
        const mode = ed.querySelector('[data-pp-mode]').value;
        return mode === 'self_owner' ? {mode} : {mode, owner_name: g('name'), owner_national_id: g('national_id').replace(/\D/g, ''), owner_relation: g('relation')};
    }
    function personnelChip(p) {
        if (!p) return '';
        const n = x => (x && x.name) || '';
        const parts = p.mode === 'self_owner' ? `<span class="pty-n">بیمه‌گذار و مالک: <b>${esc(n(p.insured) || 'پرسنل')}</b></span>`
            : `<span class="pty-n">بیمه‌گذار: <b>${esc(n(p.insured) || '—')}</b></span> · <span class="pty-n">مالک${p.mode === 'self_relative' ? ' (ذینفع)' : ''}: <b>${esc(n(p.owner) || '—')}</b>${p.owner && p.owner.relation ? ' (' + esc(p.owner.relation) + ')' : ''}</span>`;
        const cls = {self_owner: 'COMPANY', self_relative: 'BENEFICIARY', relative: 'OWNER'}[p.mode] || 'COMPANY';
        return `<span class="pty-chip"><span class="pty-b m-${cls}">${PSHORT[p.mode] || ''}</span>${parts}</span>`;
    }

    window.PartyUI = {MODES, SHORT, chip, editor, collect, open, _toggle, PMODES, personnelEditor, personnelCollect, personnelChip, _ptoggle};
})();
