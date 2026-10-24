// فایل: hooshix.js
// «هوشیکس X ai»: گفتگو با هوشِ مصنوعی در پنل — گفتگوهای ذخیره‌شده، انتخابِ مدل، سهمیه‌ی روزانه/ماهانه،
// صدا (میکروفون ← متن، و خواندنِ پاسخ با صدا) و «گفتگوی صوتی» (پاسخ خوانده می‌شود و دوباره گوش می‌دهد).
// مدیر کل: تنظیماتِ سرویس‌دهنده/مدل، دسترسی و سهمیه‌ی هر کاربر. منطق: api/hooshix_actions.php و api/_hooshix.php
// هوشِ مصنوعی به هیچ اطلاعاتی از سایت دسترسی ندارد؛ فقط همین گفتگو را می‌بیند.
(function () {
    if (window.Hooshix) return;
    const API = 'api/hooshix_actions.php';
    const fa = s => String(s == null ? '' : s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (m, t) => (window.showToast ? showToast(m, t) : alert(m));
    const num = n => fa(Number(n || 0).toLocaleString('en-US'));
    async function post(body) {
        try { return await (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) })).json(); }
        catch (e) { return { ok: false, error: 'خطا در ارتباط با سرور.' }; }
    }
    const H = { el: null, boot: null, tid: 0, msgs: [], busy: false, model: '', voiceMode: false, rec: null, audio: null, q: '' };
    try { H.voiceMode = localStorage.getItem('hx_voice') === '1'; H.model = localStorage.getItem('hx_model') || ''; } catch (e) {}

    function css() {
        if (document.getElementById('hx-css')) return;
        const s = document.createElement('style'); s.id = 'hx-css';
        s.textContent = `
        .hx{--hx1:#7c3aed;--hx2:#06b6d4;--hx3:#ec4899;display:flex;flex-direction:column;gap:14px}
        .hx-hero{position:relative;overflow:hidden;border-radius:26px;padding:18px 20px;color:#fff;background:radial-gradient(120% 140% at 0% 0%,#312e81 0%,#0f172a 45%,#020617 100%);box-shadow:0 30px 60px -36px #4c1d95}
        .hx-hero::before{content:'';position:absolute;width:340px;height:340px;border-radius:50%;left:-90px;top:-200px;background:conic-gradient(from 90deg,var(--hx1),var(--hx2),var(--hx3),var(--hx1));filter:blur(70px);opacity:.45;animation:hxspin 14s linear infinite;pointer-events:none}
        .hx-hero::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.12) 1px,transparent 1px);background-size:18px 18px;mask-image:linear-gradient(90deg,transparent,#000 60%);pointer-events:none}
        .hx-hero>div{position:relative;z-index:1}
        @keyframes hxspin{to{transform:rotate(360deg)}}
        .hx-logo{width:52px;height:52px;border-radius:18px;display:grid;place-items:center;font-weight:900;font-size:24px;color:#fff;background:linear-gradient(135deg,var(--hx1),var(--hx2));box-shadow:0 0 0 3px rgba(255,255,255,.12),0 12px 30px -10px var(--hx2);position:relative}
        .hx-logo span{font-family:system-ui,sans-serif;letter-spacing:-1px}
        .hx-logo.live::after{content:'';position:absolute;inset:-6px;border-radius:22px;border:2px solid var(--hx2);animation:hxpulse 1.4s ease-out infinite}
        @keyframes hxpulse{from{opacity:1;transform:scale(.9)}to{opacity:0;transform:scale(1.25)}}
        .hx-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 11px;font-size:11px;font-weight:800;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.14);backdrop-filter:blur(6px)}
        .hx-chip select{background:transparent;color:#fff;font-weight:800;outline:none}.hx-chip select option{color:#0f172a}
        .hx-bar{height:5px;border-radius:9px;background:rgba(255,255,255,.15);overflow:hidden;width:70px}.hx-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--hx2),var(--hx1))}
        .hx-main{display:grid;grid-template-columns:260px 1fr;gap:14px;min-height:calc(100vh - 260px)}
        @media(max-width:900px){.hx-main{grid-template-columns:1fr}.hx-side{display:none}.hx-side.open{display:flex;position:fixed;inset:70px 12px 12px;z-index:60}}
        .hx-side{background:#fff;border:1.5px solid #ece9fb;border-radius:22px;padding:12px;display:flex;flex-direction:column;gap:8px;max-height:calc(100vh - 200px)}
        .hx-new{border-radius:14px;padding:10px;font-weight:900;font-size:12.5px;color:#fff;background:linear-gradient(120deg,var(--hx1),var(--hx2));box-shadow:0 12px 24px -16px var(--hx1)}
        .hx-th{display:flex;align-items:center;gap:6px;border-radius:12px;padding:8px 10px;font-size:12px;color:#334155;cursor:pointer;border:1px solid transparent}
        .hx-th:hover{background:#f5f3ff}.hx-th.on{background:linear-gradient(120deg,#ede9fe,#e0f2fe);border-color:#ddd6fe;color:#3b0764;font-weight:800}
        .hx-th b{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:inherit}.hx-th button{opacity:0;color:#94a3b8;font-size:11px}.hx-th:hover button{opacity:1}
        .hx-chat{position:relative;display:flex;flex-direction:column;background:linear-gradient(180deg,#fbfaff,#f8fafc);border:1.5px solid #ece9fb;border-radius:24px;overflow:hidden;min-height:520px;max-height:calc(100vh - 200px)}
        .hx-msgs{flex:1;overflow-y:auto;padding:18px 16px 10px;display:flex;flex-direction:column;gap:14px;scroll-behavior:smooth}
        .hx-m{display:flex;gap:10px;align-items:flex-start;max-width:min(860px,100%);animation:hxin .25s ease-out}
        @keyframes hxin{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
        .hx-m.u{align-self:flex-start;flex-direction:row}.hx-m.a{align-self:flex-end;flex-direction:row-reverse}
        .hx-av{width:32px;height:32px;border-radius:11px;flex:none;display:grid;place-items:center;font-size:13px;font-weight:900;color:#fff}
        .hx-m.u .hx-av{background:#334155}.hx-m.a .hx-av{background:linear-gradient(135deg,var(--hx1),var(--hx2))}
        .hx-b{border-radius:18px;padding:11px 14px;font-size:13.5px;line-height:2;color:#0f172a;word-break:break-word;min-width:0}
        .hx-m.u .hx-b{background:#fff;border:1px solid #e2e8f0;border-top-right-radius:6px;white-space:pre-wrap}
        .hx-m.a .hx-b{background:#fff;border:1px solid #e9d5ff;border-top-left-radius:6px;box-shadow:0 14px 30px -26px var(--hx1)}
        .hx-b p{margin:0 0 6px}.hx-b p:last-child{margin:0}.hx-b ul,.hx-b ol{margin:4px 0 8px;padding-right:20px}.hx-b ul{list-style:disc}.hx-b ol{list-style:decimal}
        .hx-b h3,.hx-b h4{font-weight:900;margin:6px 0 4px;color:#4c1d95}.hx-b code{background:#f1f5f9;border-radius:6px;padding:1px 6px;font-size:12px;direction:ltr;unicode-bidi:embed}
        .hx-b pre{direction:ltr;text-align:left;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:10px 12px;overflow-x:auto;font-size:12px;line-height:1.7;margin:6px 0}.hx-b pre code{background:none;padding:0;color:inherit}
        .hx-b table{border-collapse:collapse;margin:6px 0;font-size:12px;display:block;overflow-x:auto}.hx-b th,.hx-b td{border:1px solid #e2e8f0;padding:4px 8px}.hx-b th{background:#f5f3ff}
        .hx-tools{display:flex;gap:4px;margin-top:4px;opacity:.55}.hx-m:hover .hx-tools{opacity:1}
        .hx-tools button{font-size:11px;color:#64748b;border-radius:8px;padding:2px 7px}.hx-tools button:hover{background:#ede9fe;color:#6d28d9}
        .hx-tools small{font-size:10px;color:#94a3b8;align-self:center}
        .hx-dots{display:inline-flex;gap:4px}.hx-dots i{width:7px;height:7px;border-radius:50%;background:var(--hx1);animation:hxdot 1s infinite}.hx-dots i:nth-child(2){animation-delay:.15s;background:#8b5cf6}.hx-dots i:nth-child(3){animation-delay:.3s;background:var(--hx2)}
        @keyframes hxdot{0%,80%,100%{transform:scale(.6);opacity:.5}40%{transform:scale(1);opacity:1}}
        .hx-hello{margin:auto;text-align:center;padding:20px;max-width:640px}
        .hx-orb{width:96px;height:96px;margin:0 auto 14px;border-radius:50%;background:conic-gradient(from 0deg,var(--hx1),var(--hx2),var(--hx3),var(--hx1));animation:hxspin 6s linear infinite;position:relative;box-shadow:0 20px 50px -18px var(--hx1)}
        .hx-orb::after{content:'X';position:absolute;inset:7px;border-radius:50%;background:#0f172a;color:#fff;display:grid;place-items:center;font:900 34px system-ui,sans-serif;animation:hxspin 6s linear infinite reverse}
        .hx-sug{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:16px}@media(max-width:600px){.hx-sug{grid-template-columns:1fr}}
        .hx-sug button{text-align:right;border:1.5px solid #ede9fe;background:#fff;border-radius:14px;padding:10px 12px;font-size:12px;color:#334155;transition:.15s}
        .hx-sug button:hover{border-color:#c4b5fd;transform:translateY(-2px);box-shadow:0 12px 24px -20px var(--hx1)}
        .hx-comp{padding:10px 12px 12px;border-top:1px solid #f1f0fb;background:rgba(255,255,255,.85);backdrop-filter:blur(6px)}
        .hx-box{display:flex;align-items:flex-end;gap:8px;border:1.5px solid #ddd6fe;border-radius:20px;padding:6px 6px 6px 8px;background:#fff;transition:.15s}
        .hx-box:focus-within{border-color:var(--hx1);box-shadow:0 0 0 4px #ede9fe}
        .hx-box textarea{flex:1;resize:none;border:0;outline:none;font-family:inherit;font-size:14px;line-height:1.9;max-height:180px;padding:6px 8px;background:transparent}
        .hx-ib{width:40px;height:40px;border-radius:14px;flex:none;display:grid;place-items:center;font-size:15px;color:#6d28d9;background:#f5f3ff}
        .hx-ib:hover{background:#ede9fe}.hx-ib.go{color:#fff;background:linear-gradient(135deg,var(--hx1),var(--hx2))}.hx-ib:disabled{opacity:.45}
        .hx-ib.rec{color:#fff;background:#e11d48;animation:hxrec 1s infinite}@keyframes hxrec{50%{box-shadow:0 0 0 8px rgba(225,29,72,.18)}}
        .hx-foot{display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px;margin-top:6px;font-size:10.5px;color:#94a3b8}
        .hx-sw{display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-weight:800;color:#6d28d9}.hx-sw input{accent-color:#7c3aed}
        .hx-wave{display:inline-flex;gap:2px;align-items:center;height:14px}.hx-wave i{width:3px;background:#e11d48;border-radius:2px;animation:hxwave .8s infinite ease-in-out}
        .hx-wave i:nth-child(2){animation-delay:.1s}.hx-wave i:nth-child(3){animation-delay:.2s}.hx-wave i:nth-child(4){animation-delay:.3s}
        @keyframes hxwave{0%,100%{height:3px}50%{height:14px}}
        .hx-modal{position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:14px}
        .hx-modal>div{background:#fff;border-radius:22px;width:min(980px,100%);max-height:92vh;display:flex;flex-direction:column;overflow:hidden}
        .hx-f{display:block;font-size:11px;font-weight:800;color:#475569}.hx-in{margin-top:4px;width:100%;border:1.5px solid #e2e8f0;border-radius:12px;padding:8px 10px;font-size:12.5px;background:#f8fafc;outline:none}
        .hx-in:focus{border-color:#8b5cf6;background:#fff}
        .hx-tbl{width:100%;font-size:11.5px}.hx-tbl th{background:#f8fafc;color:#64748b;font-weight:800;padding:7px;text-align:right;position:sticky;top:0}.hx-tbl td{padding:6px 7px;border-top:1px solid #f1f5f9}
        .hx-tbl input[type=text]{width:84px;border:1px solid #e2e8f0;border-radius:8px;padding:4px 6px;font-size:11.5px;text-align:center}
        .hx-btn{border-radius:12px;padding:8px 14px;font-size:12px;font-weight:900}
        `;
        document.head.appendChild(s);
    }

    // ---- Markdownِ سبک برای پاسخ‌ها (اول escape، بعد قالب) ----
    function md(src) {
        const blocks = [];
        let t = esc(src).replace(/```(\w*)\n?([\s\S]*?)```/g, (m, l, c) => { blocks.push(`<pre><code>${c.replace(/\n$/, '')}</code></pre>`); return `\u0000${blocks.length - 1}\u0000`; });
        const inline = s => s.replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<i>$2</i>');
        const out = []; const lines = t.split('\n'); let i = 0;
        while (i < lines.length) {
            const L = lines[i];
            if (/^\u0000\d+\u0000$/.test(L.trim())) { out.push(L.trim()); i++; continue; }
            if (/^\s*\|.*\|\s*$/.test(L) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
                const row = r => r.trim().replace(/^\||\|$/g, '').split('|').map(x => inline(x.trim()));
                let h = `<table><thead><tr>${row(L).map(x => `<th>${x}</th>`).join('')}</tr></thead><tbody>`; i += 2;
                while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { h += `<tr>${row(lines[i]).map(x => `<td>${x}</td>`).join('')}</tr>`; i++; }
                out.push(h + '</tbody></table>'); continue;
            }
            if (/^\s*([-*•]|\d+[.)])\s+/.test(L)) {
                const ol = /^\s*\d/.test(L); let h = ol ? '<ol>' : '<ul>';
                while (i < lines.length && /^\s*([-*•]|\d+[.)])\s+/.test(lines[i])) { h += `<li>${inline(lines[i].replace(/^\s*([-*•]|\d+[.)])\s+/, ''))}</li>`; i++; }
                out.push(h + (ol ? '</ol>' : '</ul>')); continue;
            }
            const hm = /^\s*(#{1,4})\s+(.*)$/.exec(L);
            if (hm) { out.push(`<h${hm[1].length < 3 ? 3 : 4}>${inline(hm[2])}</h${hm[1].length < 3 ? 3 : 4}>`); i++; continue; }
            if (L.trim() === '') { i++; continue; }
            let p = [L]; i++;
            while (i < lines.length && lines[i].trim() !== '' && !/^\s*([-*•]|\d+[.)]|#{1,4}|\|)\s*/.test(lines[i]) && !/^\u0000/.test(lines[i].trim())) { p.push(lines[i]); i++; }
            out.push(`<p>${inline(p.join('<br>'))}</p>`);
        }
        return out.join('').replace(/\u0000(\d+)\u0000/g, (m, n) => blocks[+n]);
    }

    async function init(el) {
        css(); H.el = el;
        el.innerHTML = '<div class="hx"><div class="hx-hero"><div class="relative" style="z-index:1"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ بیدار کردنِ هوشیکس…</div></div></div>';
        const b = await post({ action: 'boot' });
        if (!b.ok) { el.innerHTML = `<div class="hx"><div class="hx-hero"><div class="relative" style="z-index:1">${esc(b.error)}</div></div></div>`; return; }
        H.boot = b;
        if (!b.models.includes(H.model)) H.model = b.models[0] || '';
        render();
        if (H.tid && b.threads.some(t => t.id === H.tid)) openThread(H.tid);
    }
    function usageHtml() {
        const m = H.boot.me || {};
        const bar = (u, l) => l ? `<span class="hx-bar"><i style="width:${Math.min(100, Math.round(u * 100 / l))}%"></i></span>` : '';
        return `<span class="hx-chip" title="پیام‌های امروز"><i class="fas fa-comment-dots"></i>${m.daily ? `امروز ${fa(m.used_today)} از ${fa(m.daily)}` : 'پیامِ نامحدود'}${bar(m.used_today, m.daily)}</span>
            <span class="hx-chip" title="توکن‌های این ماه"><i class="fas fa-coins"></i>${m.month_tokens ? `توکن ${num(m.used_month)} از ${num(m.month_tokens)}` : `توکنِ این ماه ${num(m.used_month)}`}${bar(m.used_month, m.month_tokens)}</span>`;
    }
    function render() {
        const b = H.boot;
        H.el.innerHTML = `<div class="hx">
            <div class="hx-hero"><div class="relative flex flex-wrap items-center justify-between gap-3" style="z-index:1">
                <div class="flex items-center gap-3"><div class="hx-logo ${H.busy ? 'live' : ''}" data-logo><span>X</span></div>
                    <div><h2 class="font-black text-xl leading-7">هوشیکس <span style="background:linear-gradient(90deg,#a78bfa,#22d3ee);-webkit-background-clip:text;background-clip:text;color:transparent">X ai</span></h2>
                    <p class="text-[11px] opacity-75">دستیارِ هوشمندِ شما — بپرسید، بنویسید یا با صدا حرف بزنید</p></div></div>
                <div class="flex flex-wrap items-center gap-2">
                    ${b.allowed ? `<span data-usage class="contents">${usageHtml()}</span>
                    ${b.models.length > 1 ? `<span class="hx-chip"><i class="fas fa-microchip"></i><select data-model>${b.models.map(m => `<option ${m === H.model ? 'selected' : ''}>${esc(m)}</option>`).join('')}</select></span>` : `<span class="hx-chip"><i class="fas fa-microchip"></i>${esc(H.model)}</span>`}` : ''}
                    <span class="hx-chip" title="هوشِ مصنوعی به هیچ اطلاعاتی از سایت (پرونده‌ها، مشتریان، فایل‌ها) دسترسی ندارد${b.mask ? '؛ کد ملی، موبایل و شماره کارت هم پیش از ارسال پوشانده می‌شود' : ''}"><i class="fas fa-shield-halved" style="color:#34d399"></i>بدونِ دسترسی به اطلاعاتِ سایت</span>
                    ${b.is_admin ? '<button class="hx-chip" data-set style="background:rgba(255,255,255,.2)"><i class="fas fa-sliders"></i>تنظیمات</button>' : ''}
                    <button class="hx-chip md:hidden" data-sidebtn><i class="fas fa-list"></i>گفتگوها</button></div></div></div>
            ${!b.allowed ? `<div class="hx-chat"><div class="hx-hello"><div class="hx-orb"></div><h3 class="font-black text-lg text-slate-800">${b.enabled ? 'دسترسی برای شما فعال نیست' : 'هوشیکس فعلاً خاموش است'}</h3><p class="text-xs text-slate-500 mt-2 leading-7">${b.enabled ? 'از مدیر کل بخواهید در «تنظیماتِ هوشیکس» برای شما دسترسی و سهمیه تعیین کند.' : 'مدیر کل می‌تواند از «تنظیمات» روشنش کند.'}</p></div></div>` : `
            <div class="hx-main">
                <aside class="hx-side" data-side><button class="hx-new" data-new><i class="fas fa-plus ml-1"></i>گفتگوی تازه</button>
                    <input class="hx-in !mt-0" data-q placeholder="جستجو در گفتگوها…" value="${esc(H.q)}"><div class="flex-1 overflow-y-auto space-y-1" data-threads></div></aside>
                <section class="hx-chat"><div class="hx-msgs" data-msgs></div>
                    <div class="hx-comp"><div class="hx-box">
                        <textarea rows="1" data-in placeholder="از هوشیکس بپرسید… (Enter = ارسال، Shift+Enter = خطِ تازه)"></textarea>
                        ${b.voice ? '<button class="hx-ib" data-mic title="حرف بزنید (دوباره بزنید تا تمام شود)"><i class="fas fa-microphone"></i></button>' : ''}
                        <button class="hx-ib go" data-send title="ارسال"><i class="fas fa-paper-plane"></i></button></div>
                    <div class="hx-foot"><span>${b.ready ? 'هوشیکس ممکن است اشتباه کند؛ پاسخ‌های مهم را بررسی کنید.' : '<b class="text-rose-500">سرویس‌دهنده هنوز تنظیم نشده.</b>'}</span>
                        ${b.voice && b.voice_reply ? `<label class="hx-sw"><input type="checkbox" data-vm ${H.voiceMode ? 'checked' : ''}><i class="fas fa-headset"></i>گفتگوی صوتی (پاسخ را بخواند و دوباره گوش دهد)</label>` : ''}</div></div></section></div>`}</div>`;
        const q = s => H.el.querySelector(s);
        if (q('[data-set]')) q('[data-set]').onclick = settings;
        if (!b.allowed) return;
        q('[data-model]') && (q('[data-model]').onchange = e => { H.model = e.target.value; try { localStorage.setItem('hx_model', H.model); } catch (x) {} });
        q('[data-new]').onclick = () => { H.tid = 0; H.msgs = []; paintThreads(); paintMsgs(); q('[data-in]').focus(); q('[data-side]').classList.remove('open'); };
        q('[data-sidebtn]').onclick = () => q('[data-side]').classList.toggle('open');
        q('[data-q]').oninput = e => { H.q = e.target.value; paintThreads(); };
        const ta = q('[data-in]');
        const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(180, ta.scrollHeight) + 'px'; };
        ta.oninput = grow;
        ta.onkeydown = e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); } };
        q('[data-send]').onclick = () => send();
        if (q('[data-mic]')) q('[data-mic]').onclick = () => (H.rec ? stopRec() : startRec());
        if (q('[data-vm]')) q('[data-vm]').onchange = e => { H.voiceMode = e.target.checked; try { localStorage.setItem('hx_voice', H.voiceMode ? '1' : '0'); } catch (x) {} if (!H.voiceMode) stopAudio(); };
        paintThreads(); paintMsgs();
    }
    function paintThreads() {
        const box = H.el.querySelector('[data-threads]'); if (!box) return;
        const list = (H.boot.threads || []).filter(t => !H.q || t.title.includes(H.q));
        box.innerHTML = list.length ? list.map(t => `<div class="hx-th ${t.id === H.tid ? 'on' : ''}" data-t="${t.id}"><i class="fas fa-message text-[10px] text-violet-400"></i><b title="${esc(t.title)}">${esc(t.title || 'گفتگو')}</b>
            <button data-ren="${t.id}" title="تغییرِ نام"><i class="fas fa-pen"></i></button><button data-del="${t.id}" title="حذف"><i class="fas fa-trash"></i></button></div>`).join('')
            : '<p class="text-center text-[11px] text-slate-400 py-6">هنوز گفتگویی نیست.</p>';
        box.querySelectorAll('[data-t]').forEach(x => x.onclick = e => { if (e.target.closest('button')) return; openThread(+x.dataset.t); H.el.querySelector('[data-side]').classList.remove('open'); });
        box.querySelectorAll('[data-del]').forEach(x => x.onclick = async () => {
            if (!(window.uiConfirm ? await uiConfirm('حذفِ گفتگو', 'این گفتگو حذف شود؟', { danger: true, ok: 'حذف' }) : confirm('حذف شود؟'))) return;
            const r = await post({ action: 'delete', id: +x.dataset.del }); if (!r.ok) return toast(r.error, 'error');
            H.boot.threads = r.threads; if (H.tid === +x.dataset.del) { H.tid = 0; H.msgs = []; paintMsgs(); } paintThreads();
        });
        box.querySelectorAll('[data-ren]').forEach(x => x.onclick = async () => {
            const t = H.boot.threads.find(y => y.id === +x.dataset.ren); const v = prompt('نامِ گفتگو:', t ? t.title : ''); if (!v) return;
            const r = await post({ action: 'rename', id: +x.dataset.ren, title: v }); if (r.ok) { H.boot.threads = r.threads; paintThreads(); }
        });
    }
    async function openThread(id) {
        const r = await post({ action: 'thread', id }); if (!r.ok) return toast(r.error, 'error');
        H.tid = id; H.msgs = r.messages; paintThreads(); paintMsgs();
    }
    const SUG = [['fa-envelope-open-text', 'یک نامه‌ی رسمی برای پیگیریِ پرداختِ خسارت بنویس'], ['fa-scale-balanced', 'فرقِ بیمه‌ی بدنه و ثالث را ساده توضیح بده'],
                 ['fa-language', 'این متن را به انگلیسیِ رسمی ترجمه کن: '], ['fa-list-check', 'برای جلسه‌ی فردا یک چک‌لیستِ کوتاه آماده کن']];
    function msgHtml(m, i) {
        const a = m.role === 'assistant';
        return `<div class="hx-m ${a ? 'a' : 'u'}"><div class="hx-av">${a ? 'X' : '<i class="fas fa-user"></i>'}</div><div class="min-w-0">
            <div class="hx-b" dir="auto">${a ? md(m.text) : esc(m.text)}</div>
            <div class="hx-tools">${a ? `<button data-cp="${i}" title="کپی"><i class="fas fa-copy"></i></button>${H.boot.voice_reply ? `<button data-say="${i}" title="خواندن با صدا"><i class="fas fa-volume-high"></i></button>` : ''}${m.tokens ? `<small>${num(m.tokens)} توکن${m.ms ? ' · ' + fa((m.ms / 1000).toFixed(1)) + ' ث' : ''}</small>` : ''}`
                : `<button data-cp="${i}" title="کپی"><i class="fas fa-copy"></i></button><button data-ed="${i}" title="ویرایش و ارسالِ دوباره"><i class="fas fa-pen"></i></button>`}</div></div></div>`;
    }
    function paintMsgs(typing) {
        const box = H.el.querySelector('[data-msgs]'); if (!box) return;
        if (!H.msgs.length && !typing) {
            box.innerHTML = `<div class="hx-hello"><div class="hx-orb"></div><h3 class="font-black text-lg text-slate-800">سلام${H.boot.user ? ' ' + esc(H.boot.user.split(' ')[0]) : ''}! من هوشیکسم 👋</h3>
                <p class="text-xs text-slate-500 mt-1 leading-7">هر سؤالی دارید بپرسید؛ متن بنویسم، خلاصه کنم، ترجمه کنم یا ایده بدهم.${H.boot.voice ? ' می‌توانید با میکروفون هم حرف بزنید.' : ''}<br><span class="text-[10.5px] text-emerald-600"><i class="fas fa-shield-halved ml-1"></i>به اطلاعاتِ سایت دسترسی ندارم و فقط همین گفتگو را می‌بینم.</span></p>
                <div class="hx-sug">${SUG.map(s => `<button data-sug="${esc(s[1])}"><i class="fas ${s[0]} ml-1 text-violet-500"></i>${esc(s[1])}</button>`).join('')}</div></div>`;
            box.querySelectorAll('[data-sug]').forEach(x => x.onclick = () => { const ta = H.el.querySelector('[data-in]'); ta.value = x.dataset.sug; ta.focus(); ta.oninput(); if (!/: $/.test(x.dataset.sug)) send(); });
            return;
        }
        box.innerHTML = H.msgs.map(msgHtml).join('') + (typing ? `<div class="hx-m a"><div class="hx-av">X</div><div class="hx-b"><span class="hx-dots"><i></i><i></i><i></i></span> <small class="text-slate-400 mr-1">${esc(typing === true ? 'هوشیکس در حالِ فکر کردن…' : typing)}</small></div></div>` : '');
        box.querySelectorAll('[data-cp]').forEach(x => x.onclick = async () => { try { await navigator.clipboard.writeText(H.msgs[+x.dataset.cp].text); toast('کپی شد.', 'success'); } catch (e) {} });
        box.querySelectorAll('[data-say]').forEach(x => x.onclick = () => speak(H.msgs[+x.dataset.say].text));
        box.querySelectorAll('[data-ed]').forEach(x => x.onclick = () => { const ta = H.el.querySelector('[data-in]'); ta.value = H.msgs[+x.dataset.ed].text; ta.focus(); ta.oninput(); });
        box.scrollTop = box.scrollHeight;
    }
    function setBusy(on) {
        H.busy = on;
        const s = H.el.querySelector('[data-send]'), l = H.el.querySelector('[data-logo]');
        if (s) s.disabled = on; if (l) l.classList.toggle('live', on);
    }
    async function send(text) {
        const ta = H.el.querySelector('[data-in]');
        text = (text != null ? text : ta.value).trim();
        if (!text || H.busy) return;
        ta.value = ''; ta.style.height = 'auto';
        H.msgs.push({ role: 'user', text }); setBusy(true); paintMsgs(true);
        const r = await post({ action: 'send', thread_id: H.tid || 0, text, model: H.model });
        setBusy(false);
        if (!r.ok) {
            H.msgs.pop(); paintMsgs(); ta.value = text; ta.oninput();
            if (r.me) { H.boot.me = r.me; const u = H.el.querySelector('[data-usage]'); if (u) u.innerHTML = usageHtml(); }
            if (r.thread_id && !H.tid) { H.tid = r.thread_id; }
            toast(r.error || 'خطا', 'error'); return;
        }
        H.tid = r.thread_id; H.boot.threads = r.threads; H.boot.me = r.me;
        H.msgs.push({ role: 'assistant', text: r.reply, tokens: r.tokens, ms: r.ms });
        paintMsgs(); paintThreads();
        const u = H.el.querySelector('[data-usage]'); if (u) u.innerHTML = usageHtml();
        if (H.voiceMode && H.boot.voice_reply) speak(r.reply, () => { if (H.voiceMode && document.body.contains(H.el)) startRec(true); });
    }

    // ---------------- صدا ----------------
    function stopAudio() { if (H.audio) { try { H.audio.pause(); } catch (e) {} H.audio = null; } try { speechSynthesis.cancel(); } catch (e) {} }
    async function speak(text, done) {
        stopAudio();
        try {
            const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'tts', text }) });
            if ((res.headers.get('Content-Type') || '').indexOf('audio') === 0) {
                const url = URL.createObjectURL(await res.blob());
                H.audio = new Audio(url);
                H.audio.onended = () => { URL.revokeObjectURL(url); H.audio = null; if (done) done(); };
                await H.audio.play(); return;
            }
        } catch (e) {}
        // جایگزین: صدای خودِ مرورگر (اگر صدای فارسی داشته باشد)
        try {
            const u = new SpeechSynthesisUtterance(text.replace(/[*#`|>_]/g, ' '));
            u.lang = 'fa-IR'; const v = speechSynthesis.getVoices().find(x => /^fa/i.test(x.lang)); if (v) u.voice = v;
            u.onend = () => { if (done) done(); }; speechSynthesis.speak(u);
        } catch (e) { toast('پخشِ صدا ممکن نشد.', 'error'); }
    }
    async function startRec(auto) {
        if (H.rec || H.busy) return;
        stopAudio();
        let stream;
        try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
        catch (e) { toast('دسترسی به میکروفون داده نشد.', 'error'); return; }
        const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg', 'audio/mp4'].find(m => window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m)) || '';
        const mr = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined), chunks = [];
        H.rec = { mr, stream, t0: Date.now() };
        mr.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        mr.onstop = async () => {
            stream.getTracks().forEach(t => t.stop());
            const blob = new Blob(chunks, { type: mr.mimeType || 'audio/webm' });
            H.rec = null; micUi();
            if (blob.size < 1500) return;
            const dataUrl = await new Promise(r => { const fr = new FileReader(); fr.onload = () => r(fr.result); fr.readAsDataURL(blob); });
            setBusy(true); paintMsgs('در حالِ شنیدنِ صدای شما…');
            const r = await post({ action: 'stt', audio: dataUrl });
            setBusy(false); paintMsgs();
            if (!r.ok || !r.text) { toast(r.error || 'صدایی شناخته نشد.', 'error'); return; }
            send(r.text);
        };
        // تشخیصِ سکوت: بعد از ۱.۶ ثانیه سکوت (پس از شروعِ حرف) خودکار تمام می‌شود
        try {
            const ac = new (window.AudioContext || window.webkitAudioContext)(), an = ac.createAnalyser(), buf = new Uint8Array(512);
            ac.createMediaStreamSource(stream).connect(an); an.fftSize = 1024;
            let spoke = false, quiet = 0, last = Date.now();
            const loop = () => {
                if (!H.rec || H.rec.mr !== mr) { ac.close(); return; }
                an.getByteTimeDomainData(buf); let mx = 0; for (let i = 0; i < buf.length; i++) mx = Math.max(mx, Math.abs(buf[i] - 128));
                const now = Date.now(), dt = now - last; last = now;
                if (mx > 12) { spoke = true; quiet = 0; } else if (spoke) quiet += dt;
                if ((spoke && quiet > 1600) || now - H.rec.t0 > 60000 || (!spoke && auto && now - H.rec.t0 > 9000)) { stopRec(); ac.close(); return; }
                requestAnimationFrame(loop);
            };
            loop();
        } catch (e) {}
        mr.start(250); micUi();
    }
    function stopRec() { if (H.rec && H.rec.mr.state !== 'inactive') H.rec.mr.stop(); }
    function micUi() {
        const b = H.el && H.el.querySelector('[data-mic]'); if (!b) return;
        b.classList.toggle('rec', !!H.rec);
        b.innerHTML = H.rec ? '<span class="hx-wave"><i></i><i></i><i></i><i></i></span>' : '<i class="fas fa-microphone"></i>';
    }

    // ---------------- تنظیمات (مدیر کل) ----------------
    async function settings() {
        const g = await post({ action: 'adm_get' }); if (!g.ok) return toast(g.error, 'error');
        const c = g.cfg;
        const m = document.createElement('div'); m.className = 'hx-modal';
        const f = (k, l, extra, type) => `<label class="hx-f">${l}<input class="hx-in" data-k="${k}" type="${type || 'text'}" value="${esc(c[k] == null ? '' : c[k])}" ${extra || ''}></label>`;
        const yn = (k, l) => `<label class="hx-f flex items-center gap-2 mt-5"><input type="checkbox" data-k="${k}" ${c[k] === '1' ? 'checked' : ''} style="accent-color:#7c3aed"> ${l}</label>`;
        m.innerHTML = `<div><div class="flex items-center justify-between p-4 border-b"><b class="text-sm"><i class="fas fa-sliders ml-1 text-violet-600"></i>تنظیماتِ هوشیکس X ai ${g.ready ? '<span class="text-emerald-600 text-[11px]">● آماده</span>' : '<span class="text-rose-500 text-[11px]">● تنظیم نشده</span>'}</b><button class="hx-btn bg-slate-100" data-x>بستن</button></div>
            <div class="p-4 overflow-y-auto space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="hx-f">سرویس‌دهنده<select class="hx-in" data-k="provider">${Object.entries(g.providers).map(([k, l]) => `<option value="${k}" ${c.provider === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
                    <div data-custom class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">${f('base_url', 'آدرسِ API (مثلاً https://api.openai.com/v1)', 'dir="ltr"')}${f('token', 'کلیدِ API' + (c.has_token ? ' (ذخیره شده؛ خالی = همان)' : ''), 'dir="ltr" autocomplete="off"', 'password')}</div>
                    <label class="hx-f md:col-span-3">مدل‌ها (با کاما؛ اولی پیش‌فرض است و کاربر از بینِ همین‌ها انتخاب می‌کند)<input class="hx-in" data-k="models" dir="ltr" value="${esc(c.models)}" placeholder="gpt-4o-mini, gpt-4o, claude-sonnet-4, gemini-2.0-flash"></label>
                    ${f('temperature', 'خلاقیت (۰ تا ۱.۵)', 'dir="ltr"')}${f('max_reply', 'سقفِ طولِ هر پاسخ (توکن)', 'dir="ltr"')}${f('history', 'تعدادِ پیام‌های قبلی که همراه فرستاده می‌شود', 'dir="ltr"')}
                    <label class="hx-f md:col-span-3">دستورِ اختصاصی برای سبکِ پاسخ‌ها (اختیاری)<textarea class="hx-in" data-k="system" rows="3" placeholder="مثلاً: همیشه کوتاه و با لحنِ صمیمی جواب بده؛ اصطلاحاتِ بیمه را ساده کن.">${esc(c.system)}</textarea></label>
                </div>
                <div class="rounded-2xl border border-violet-100 bg-violet-50/50 p-3 grid grid-cols-1 md:grid-cols-4 gap-3">
                    <label class="hx-f">چه کسانی دسترسی دارند؟<select class="hx-in" data-k="access"><option value="list" ${c.access === 'list' ? 'selected' : ''}>فقط کاربرانی که روشن کنم</option><option value="all" ${c.access === 'all' ? 'selected' : ''}>همه‌ی کاربرانِ پنل (با سهمیه‌ی پیش‌فرض)</option></select></label>
                    ${f('def_daily', 'سهمیه‌ی پیش‌فرضِ پیام در روز (۰ = نامحدود)', 'dir="ltr"')}${f('def_month_tokens', 'سهمیه‌ی پیش‌فرضِ توکن در ماه (۰ = نامحدود)', 'dir="ltr"')}
                    <div>${yn('enabled', 'هوشیکس روشن است')}${yn('mask', 'پوشاندنِ کد ملی/موبایل/کارت پیش از ارسال')}</div>
                </div>
                <div class="rounded-2xl border border-cyan-100 bg-cyan-50/40 p-3 grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>${yn('voice', 'میکروفون (حرف بزنید، متن می‌شود)')}${yn('voice_reply', 'خواندنِ پاسخ با صدا و «گفتگوی صوتی»')}</div>${f('stt_model', 'مدلِ گفتار به متن', 'dir="ltr"')}${f('tts_model', 'مدلِ متن به گفتار', 'dir="ltr"')}
                    <label class="hx-f">صدای گوینده<select class="hx-in" data-k="tts_voice">${g.voices.map(v => `<option ${c.tts_voice === v ? 'selected' : ''}>${v}</option>`).join('')}</select></label></div>
                <p class="text-[10.5px] text-slate-500 leading-6"><i class="fas fa-shield-halved text-emerald-600 ml-1"></i>هوشیکس هیچ ابزار یا دسترسی‌ای به دیتابیس، پرونده‌ها و فایل‌های سایت ندارد؛ فقط پیام‌های همان گفتگو (که سرور خودش می‌سازد) فرستاده می‌شود.</p>
                <div class="flex flex-wrap gap-2"><button class="hx-btn text-white" style="background:linear-gradient(120deg,#7c3aed,#06b6d4)" data-save><i class="fas fa-floppy-disk ml-1"></i>ذخیره</button><button class="hx-btn bg-slate-100" data-test><i class="fas fa-plug ml-1"></i>آزمایشِ اتصال</button><span data-tr class="text-[11px] self-center"></span></div>
                <div><b class="text-xs text-slate-700"><i class="fas fa-users ml-1"></i>دسترسی و سهمیه‌ی کاربران</b> <small class="text-slate-400">(خالی = پیش‌فرض؛ ۰ = نامحدود)</small>
                    <div class="mt-2 max-h-80 overflow-auto border rounded-xl" data-users><p class="text-xs text-slate-400 p-4">در حالِ بارگذاری…</p></div></div>
            </div></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) { m.remove(); init(H.el); } };
        document.body.appendChild(m);
        const cust = () => { m.querySelector('[data-custom]').style.display = m.querySelector('[data-k="provider"]').value === 'custom' ? '' : 'none'; };
        m.querySelector('[data-k="provider"]').onchange = cust; cust();
        const collect = () => { const o = {}; m.querySelectorAll('[data-k]').forEach(x => { o[x.dataset.k] = x.type === 'checkbox' ? (x.checked ? '1' : '0') : x.value; }); return o; };
        m.querySelector('[data-save]').onclick = async () => { const r = await post({ action: 'adm_save', cfg: collect() }); if (!r.ok) return toast(r.error, 'error'); toast('ذخیره شد.', 'success'); loadUsers(); };
        m.querySelector('[data-test]').onclick = async () => {
            const tr = m.querySelector('[data-tr]'); tr.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            await post({ action: 'adm_save', cfg: collect() });
            const r = await post({ action: 'adm_test' });
            tr.innerHTML = !r.ok ? `<span class="text-rose-600">${esc(r.error)}</span>` : `<span class="${r.result.ok ? 'text-emerald-600' : 'text-rose-600'}">${r.result.ok ? '✅' : '❌'} ${esc(r.result.text)} <small class="text-slate-400">(${fa(r.result.ms)} ms)</small></span>`;
        };
        async function loadUsers() {
            const r = await post({ action: 'adm_users' }); const box = m.querySelector('[data-users]');
            if (!r.ok) { box.innerHTML = `<p class="text-xs text-rose-600 p-3">${esc(r.error)}</p>`; return; }
            box.innerHTML = `<table class="hx-tbl"><thead><tr><th>کاربر</th><th>دسترسی</th><th>پیام در روز</th><th>توکن در ماه</th><th>مصرفِ امروز</th><th>توکنِ این ماه</th><th></th></tr></thead><tbody>
                ${r.users.map(u => `<tr data-u="${u.id}"><td><b>${esc(u.name)}</b>${u.admin ? ' <small class="text-violet-600">مدیر کل</small>' : ''}</td>
                    <td><input type="checkbox" data-en ${u.enabled ? 'checked' : ''} ${u.admin ? 'disabled' : ''} style="accent-color:#7c3aed"></td>
                    <td><input type="text" data-d value="${u.daily == null ? '' : fa(u.daily)}" placeholder="${fa(r.defaults.daily)}"></td>
                    <td><input type="text" data-mt value="${u.month_tokens == null ? '' : fa(u.month_tokens)}" placeholder="${num(r.defaults.month_tokens)}"></td>
                    <td>${fa(u.today)}</td><td>${num(u.month_used)}</td><td><button class="hx-btn bg-violet-50 text-violet-700 !py-1" data-us>ثبت</button></td></tr>`).join('')}</tbody></table>`;
            box.querySelectorAll('[data-us]').forEach(b => b.onclick = async () => {
                const tr = b.closest('tr'), en = s => s.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/,/g, '');
                const r2 = await post({ action: 'adm_user_save', user_id: +tr.dataset.u, enabled: tr.querySelector('[data-en]').checked ? 1 : 0,
                                        daily: en(tr.querySelector('[data-d]').value), month_tokens: en(tr.querySelector('[data-mt]').value) });
                toast(r2.ok ? 'ثبت شد.' : r2.error, r2.ok ? 'success' : 'error');
            });
        }
        loadUsers();
    }
    window.Hooshix = { init };
})();
