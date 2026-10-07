// فایل: webapp/doc-split.js
// «چند مدرک در یک PDF» برای مینی‌اپ (index_docs.html و index_order.html):
// PDF روی سرور صفحه‌به‌صفحه جدا می‌شود، پیش‌نمایشِ هر صفحه نشان داده می‌شود و کاربر نوعِ مدرکِ هر صفحه را
// انتخاب می‌کند. چند صفحه با یک نوع (مثلاً رو و پشتِ کارت) یک فایل می‌شوند؛ «این صفحه نه» ذخیره نمی‌شود.
//   DocSplit.start({api, token, caseId, docs: [{key,label,status}], file, onDone, toast})
(function () {
    const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    let st = null;

    function css() {
        if (document.getElementById('ds-css')) return;
        const s = document.createElement('style');
        s.id = 'ds-css';
        s.textContent = `
        #ds-ov { position: fixed; inset: 0; z-index: 9800; background: #020617; color: #f1f5f9; display: flex; flex-direction: column; font-family: 'Vazir', Tahoma, sans-serif; direction: rtl; }
        #ds-ov .ds-head { padding: 14px 16px 10px; border-bottom: 1px solid rgba(255,255,255,.1); }
        #ds-ov .ds-title { font-size: 14px; font-weight: 900; margin: 0 0 4px; }
        #ds-ov .ds-sub { font-size: 11.5px; color: #94a3b8; line-height: 1.8; margin: 0; }
        #ds-ov .ds-body { flex: 1; overflow-y: auto; padding: 12px 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; align-content: start; }
        #ds-ov .ds-card { background: rgba(255,255,255,.06); border: 2px solid rgba(255,255,255,.12); border-radius: 14px; padding: 8px; display: flex; flex-direction: column; gap: 6px; }
        #ds-ov .ds-card.on { border-color: #10b981; background: rgba(16,185,129,.1); }
        #ds-ov .ds-num { font-size: 11px; font-weight: 800; color: #cbd5e1; }
        #ds-ov .ds-card img { width: 100%; aspect-ratio: 3/4; object-fit: contain; background: #fff; border-radius: 10px; }
        #ds-ov select { width: 100%; background: #0f172a; color: #f1f5f9; border: 1px solid rgba(255,255,255,.2); border-radius: 10px; padding: 8px; font-size: 11.5px; font-family: inherit; }
        #ds-ov .ds-foot { padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); border-top: 1px solid rgba(255,255,255,.1); display: flex; gap: 8px; align-items: center; }
        #ds-ov .ds-sum { flex: 1; font-size: 11px; color: #94a3b8; }
        #ds-ov .ds-btn { border: none; border-radius: 14px; padding: 12px 16px; font-size: 13px; font-weight: 800; cursor: pointer; font-family: inherit; }
        #ds-ov .ds-ok { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
        #ds-ov .ds-no { background: rgba(255,255,255,.12); color: #e2e8f0; }
        #ds-ov .ds-ok:disabled { opacity: .5; }
        #ds-zoom { position: fixed; inset: 0; z-index: 9900; background: rgba(0,0,0,.92); display: flex; align-items: center; justify-content: center; }
        #ds-zoom img { max-width: 100%; max-height: 100%; }
        #ds-busy { position: fixed; inset: 0; z-index: 9850; background: rgba(2,6,23,.85); display: flex; align-items: center; justify-content: center; color: #fff; font-family: 'Vazir', Tahoma, sans-serif; font-size: 13px; font-weight: 800; direction: rtl; }
        @media (min-width: 640px) { #ds-ov .ds-body { grid-template-columns: repeat(4, 1fr); } }`;
        document.head.appendChild(s);
    }
    function busy(on, text) {
        let b = document.getElementById('ds-busy');
        if (!on) { if (b) b.remove(); return; }
        if (!b) { b = document.createElement('div'); b.id = 'ds-busy'; document.body.appendChild(b); }
        b.textContent = text || '⏳ لطفاً صبر کنید...';
    }
    function pageUrl(n, kind) {
        return `${st.api}${st.api.includes('?') ? '&' : '?'}action=split_page&token=${encodeURIComponent(st.token)}&job=${st.job}&n=${n}&kind=${kind}`;
    }

    async function start(o) {
        css();
        st = Object.assign({}, o);
        const toast = st.toast || (m => alert(m));
        const fd = new FormData();
        fd.append('action', 'split_upload'); fd.append('token', st.token); fd.append('case_id', st.caseId);
        fd.append('file', st.file, st.file.name || 'docs.pdf');
        busy(true, '⏳ در حال ارسال و جدا کردنِ صفحه‌ها...');
        let d;
        try { d = await (await fetch(`${st.api}${st.api.includes('?') ? '&' : '?'}action=split_upload`, { method: 'POST', body: fd })).json(); }
        catch (e) { d = { ok: false, error: 'خطا در اتصال به سرور.' }; }
        busy(false);
        if (!d.ok) { toast(d.error || 'خطا'); return; }
        st.job = d.token; st.pages = d.pages;
        render();
    }

    function render() {
        const open = st.docs.filter(x => x.status !== 'APPROVED' && x.status !== 'PENDING');
        const opts = '<option value="">— این صفحه نه —</option>' + open.map(x => `<option value="${x.key}">${x.label}</option>`).join('');
        const ov = document.createElement('div');
        ov.id = 'ds-ov';
        ov.innerHTML = `
            <div class="ds-head"><p class="ds-title">📚 کدام صفحه چه مدرکی است؟</p>
                <p class="ds-sub">${fa(st.pages)} صفحه دریافت شد. زیرِ هر صفحه نوعِ مدرک را انتخاب کنید. اگر یک مدرک چند صفحه است (مثلاً رو و پشتِ کارت)، همه را همان نوع بزنید. برای دیدنِ بزرگ، روی صفحه بزنید.</p></div>
            <div class="ds-body">${Array.from({ length: st.pages }, (_, i) => i + 1).map(n => `
                <div class="ds-card" data-n="${n}"><span class="ds-num">صفحه‌ی ${fa(n)}</span>
                    <img src="${pageUrl(n, 'png')}" alt="صفحه ${n}" loading="lazy">
                    <select>${opts}</select></div>`).join('')}</div>
            <div class="ds-foot"><span class="ds-sum" id="ds-sum"></span>
                <button class="ds-btn ds-no" id="ds-cancel">انصراف</button>
                <button class="ds-btn ds-ok" id="ds-save">✅ ارسال</button></div>`;
        document.body.appendChild(ov);
        ov.querySelectorAll('.ds-card img').forEach(img => img.onclick = () => {
            const z = document.createElement('div'); z.id = 'ds-zoom';
            z.innerHTML = `<img src="${img.src}">`; z.onclick = () => z.remove(); document.body.appendChild(z);
        });
        // پیشنهادِ اولیه: صفحه‌ها به‌ترتیب روی مدارکِ باقی‌مانده (کاربر می‌تواند عوض کند)
        ov.querySelectorAll('.ds-card select').forEach((s, i) => {
            if (open[i]) s.value = open[i].key;
            s.onchange = sum;
        });
        ov.querySelector('#ds-cancel').onclick = close;
        ov.querySelector('#ds-save').onclick = save;
        sum();
    }
    function sum() {
        const cards = [...document.querySelectorAll('#ds-ov .ds-card')];
        cards.forEach(c => c.classList.toggle('on', !!c.querySelector('select').value));
        const n = cards.filter(c => c.querySelector('select').value).length;
        document.getElementById('ds-sum').textContent = `${fa(n)} از ${fa(st.pages)} صفحه انتخاب شده`;
        document.getElementById('ds-save').disabled = !n;
    }
    function close() { const ov = document.getElementById('ds-ov'); if (ov) ov.remove(); }

    async function save() {
        const toast = st.toast || (m => alert(m));
        const items = [...document.querySelectorAll('#ds-ov .ds-card')].map(c => ({ n: Number(c.dataset.n), doc_key: c.querySelector('select').value })).filter(x => x.doc_key);
        if (!items.length) return;
        busy(true, '⏳ در حال ثبتِ مدارک...');
        let d;
        try {
            d = await (await fetch(`${st.api}${st.api.includes('?') ? '&' : '?'}action=split_assign`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'split_assign', token: st.token, case_id: st.caseId, job: st.job, items }) })).json();
        } catch (e) { d = { ok: false, error: 'خطا در اتصال به سرور.' }; }
        busy(false);
        if (!d.ok) { toast(d.error || 'خطا'); return; }
        close();
        toast('✅ ثبت شد: ' + (d.saved || []).map(x => x.label).join('، ') + ((d.locked || []).length ? ' · قبلاً ارسال شده: ' + d.locked.join('، ') : ''));
        if (typeof st.onDone === 'function') st.onDone(d);
    }

    window.DocSplit = { start };
})();
