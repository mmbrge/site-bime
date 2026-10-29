// فایل: hooshix.js
// «هوشیکس X ai»: گفتگو با هوشِ مصنوعی در پنل — گفتگوهای ذخیره‌شده (هر کدام با کدِ یکتا)، انتخابِ مدل، سهمیه‌ی روزانه/هفتگی/ماهانه،
// اشتراک (خرید با درگاه)، فرستادنِ فایل (برای کاربرانِ مجاز)، صدا (میکروفون ← متن، و خواندنِ پاسخ با صدا) و «گفتگوی صوتی».
// مدیر کل: تنظیمات، پلن‌ها، تخفیف‌ها، درگاه‌ها، تاریخچه‌ی گفتگوها و پرداخت‌ها در hooshix-admin.js. منطق: api/hooshix_actions.php و api/_hooshix.php
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
    const H = { el: null, boot: null, tid: 0, code: '', msgs: [], busy: false, model: '', voiceMode: false, rec: null, audio: null, q: '', att: [] };
    // لوگوی هوشیکس (SVG): کاشیِ گرادیانی، «X» با گره‌های عصبی و ستاره‌ی درخشان
    let LG = 0;
    function logo(sz, cls) {
        const i = 'hxl' + (++LG);
        return `<svg class="hx-svg ${cls || ''}" viewBox="0 0 64 64" width="${sz}" height="${sz}" aria-hidden="true"><defs>
            <linearGradient id="${i}a" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c4b5fd"/><stop offset=".45" stop-color="#7c3aed"/><stop offset="1" stop-color="#06b6d4"/></linearGradient>
            <linearGradient id="${i}b" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#e0f2fe"/></linearGradient>
            <radialGradient id="${i}c" cx=".3" cy=".25" r=".8"><stop offset="0" stop-color="#fff" stop-opacity=".55"/><stop offset=".6" stop-color="#fff" stop-opacity="0"/></radialGradient></defs>
            <rect x="3" y="3" width="58" height="58" rx="19" fill="url(#${i}a)"/><rect x="3" y="3" width="58" height="58" rx="19" fill="url(#${i}c)"/>
            <rect x="3.75" y="3.75" width="56.5" height="56.5" rx="18.3" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="1.5"/>
            <path d="M21 21 L43 43 M43 21 L21 43" stroke="url(#${i}b)" stroke-width="7.5" stroke-linecap="round"/>
            <circle cx="21" cy="21" r="4.6" fill="#0f172a" stroke="#fff" stroke-width="2.6"/><circle cx="43" cy="43" r="4.6" fill="#0f172a" stroke="#fff" stroke-width="2.6"/>
            <circle cx="21" cy="43" r="3.4" fill="#fff"/><circle cx="32" cy="32" r="3" fill="#22d3ee"/>
            <path class="hx-spark" d="M48.5 9.5 l1.9 4.6 4.6 1.9 -4.6 1.9 -1.9 4.6 -1.9 -4.6 -4.6 -1.9 4.6 -1.9z" fill="#fff"/></svg>`;
    }
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
        .hx-logo{width:56px;height:56px;border-radius:20px;display:grid;place-items:center;position:relative;filter:drop-shadow(0 12px 22px rgba(6,182,212,.35))}
        .hx-svg{display:block}.hx-spark{transform-origin:48.5px 16px;animation:hxtw 2.6s ease-in-out infinite}.hx-logo.live .hx-spark{animation-duration:.8s}
        @keyframes hxtw{0%,100%{transform:scale(.7) rotate(0);opacity:.7}50%{transform:scale(1.15) rotate(45deg);opacity:1}}
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
        .hx-code{margin:8px 0;border-radius:14px;overflow:hidden;background:linear-gradient(160deg,#0f172a,#1e1b4b);box-shadow:0 14px 30px -18px #1e1b4b;border:1px solid #312e81}
        .hx-code-h{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 8px 6px 12px;background:rgba(255,255,255,.05);border-bottom:1px solid rgba(255,255,255,.08);direction:ltr}
        .hx-code-h span{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;font-weight:800;color:#a5b4fc;font-family:ui-monospace,Consolas,monospace;letter-spacing:.3px}
        .hx-code-h span:before{content:'';display:inline-flex;width:34px;height:8px;background:radial-gradient(circle at 4px 4px,#f87171 3.2px,transparent 3.6px),radial-gradient(circle at 17px 4px,#fbbf24 3.2px,transparent 3.6px),radial-gradient(circle at 30px 4px,#34d399 3.2px,transparent 3.6px)}
        .hx-cp{display:inline-flex;align-items:center;gap:5px;border-radius:9px;padding:4px 10px;font-size:10.5px;font-weight:800;color:#e0e7ff;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);cursor:pointer;font-family:inherit;transition:.15s}
        .hx-cp:hover{background:rgba(255,255,255,.16);color:#fff}.hx-cp.ok{background:#059669;border-color:#10b981;color:#fff}
        .hx-code pre{margin:0;border-radius:0;background:transparent;padding:12px 14px;font-family:ui-monospace,SFMono-Regular,Consolas,'Liberation Mono',monospace;font-size:12.5px;max-height:460px}
        .hx-b table{border-collapse:collapse;margin:6px 0;font-size:12px;display:block;overflow-x:auto}.hx-b th,.hx-b td{border:1px solid #e2e8f0;padding:4px 8px}.hx-b th{background:#f5f3ff}
        .hx-tools{display:flex;gap:4px;margin-top:4px;opacity:.55}.hx-m:hover .hx-tools{opacity:1}
        .hx-tools button{font-size:11px;color:#64748b;border-radius:8px;padding:2px 7px}.hx-tools button:hover{background:#ede9fe;color:#6d28d9}
        .hx-tools small{font-size:10px;color:#94a3b8;align-self:center}
        .hx-caret{display:inline-block;width:2px;height:1.1em;margin:0 2px -3px;border-radius:2px;background:linear-gradient(180deg,var(--hx1),var(--hx2));animation:hxblink .8s steps(2) infinite;vertical-align:baseline}
        @keyframes hxblink{50%{opacity:0}}
        .hx-dots{display:inline-flex;gap:4px}.hx-dots i{width:7px;height:7px;border-radius:50%;background:var(--hx1);animation:hxdot 1s infinite}.hx-dots i:nth-child(2){animation-delay:.15s;background:#8b5cf6}.hx-dots i:nth-child(3){animation-delay:.3s;background:var(--hx2)}
        @keyframes hxdot{0%,80%,100%{transform:scale(.6);opacity:.5}40%{transform:scale(1);opacity:1}}
        .hx-hello{margin:auto;text-align:center;padding:20px;max-width:640px}
        .hx-orb{width:104px;height:104px;margin:0 auto 14px;border-radius:50%;position:relative;display:grid;place-items:center}
        .hx-orb::before{content:'';position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 0deg,var(--hx1),var(--hx2),var(--hx3),var(--hx1));animation:hxspin 6s linear infinite;box-shadow:0 20px 50px -18px var(--hx1)}
        .hx-orb::after{content:'';position:absolute;inset:6px;border-radius:50%;background:#0f172a}
        .hx-orb .hx-svg{position:relative;z-index:1;animation:hxfl 3.2s ease-in-out infinite}@keyframes hxfl{50%{transform:translateY(-4px)}}
        .hx-m.a .hx-av{background:none;padding:0}
        .hx-att{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px}.hx-att span{display:inline-flex;align-items:center;gap:6px;border-radius:12px;background:#f5f3ff;border:1px solid #ddd6fe;padding:4px 8px;font-size:11px;color:#4c1d95;max-width:220px}
        .hx-att span b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:800}.hx-att img{width:28px;height:28px;border-radius:7px;object-fit:cover}.hx-att button{color:#be123c}
        .hx-mf{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px}.hx-mf a,.hx-mf span{display:inline-flex;align-items:center;gap:5px;font-size:11px;border-radius:10px;background:#f1f5f9;padding:3px 8px;color:#334155}
        .hx-mf img{max-width:180px;max-height:140px;border-radius:12px;display:block}
        .hx-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 14px;border-bottom:1px solid #f1f0fb;font-size:11px;color:#64748b;background:rgba(255,255,255,.7)}
        .hx-head code{background:#ede9fe;color:#5b21b6;border-radius:8px;padding:1px 8px;font-weight:900;direction:ltr}
        .hx-plans{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}
        .hx-plan{position:relative;border:2px solid #ede9fe;border-radius:22px;padding:16px;background:#fff;transition:.15s;cursor:pointer}.hx-plan:hover{transform:translateY(-3px);box-shadow:0 20px 40px -28px #4c1d95}
        .hx-plan.on{border-color:#7c3aed;box-shadow:0 0 0 4px #ede9fe}.hx-plan .bd{position:absolute;top:-10px;left:14px;font-size:10.5px;font-weight:900;color:#fff;border-radius:999px;padding:2px 10px}
        .hx-plan .pr{font-size:22px;font-weight:900;color:#0f172a}.hx-plan del{font-size:12px;color:#94a3b8;margin-left:6px}.hx-plan li{font-size:11.5px;color:#475569;line-height:2}
        .hx-gw{display:flex;flex-wrap:wrap;gap:8px}.hx-gw label{display:flex;align-items:center;gap:6px;border:1.5px solid #e2e8f0;border-radius:14px;padding:8px 12px;font-size:12px;font-weight:800;cursor:pointer}
        .hx-gw input{accent-color:#7c3aed}.hx-gw label:has(input:checked){border-color:#7c3aed;background:#f5f3ff}
        .hx-sug{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:16px}@media(max-width:600px){.hx-sug{grid-template-columns:1fr}}
        .hx-sug button{text-align:right;border:1.5px solid #ede9fe;background:#fff;border-radius:14px;padding:10px 12px;font-size:12px;color:#334155;transition:.15s}
        .hx-sug button:hover{border-color:#c4b5fd;transform:translateY(-2px);box-shadow:0 12px 24px -20px var(--hx1)}
        .hx-comp{padding:10px 12px 12px;border-top:1px solid #f1f0fb;background:rgba(255,255,255,.85);backdrop-filter:blur(6px)}
        .hx-box{display:flex;align-items:flex-end;gap:8px;border:1.5px solid #ddd6fe;border-radius:20px;padding:6px 6px 6px 8px;background:#fff;transition:.15s}
        .hx-box:focus-within{border-color:var(--hx1);box-shadow:0 0 0 4px #ede9fe}
        .hx-box textarea{flex:1;resize:none;border:0;outline:none;font-family:inherit;font-size:14px;line-height:1.9;max-height:180px;padding:6px 8px;background:transparent}
        .hx-ib{width:40px;height:40px;border-radius:14px;flex:none;display:grid;place-items:center;font-size:15px;color:#6d28d9;background:#f5f3ff}
        .hx-mdl{position:relative;flex:none;display:inline-flex;align-items:center;gap:6px;height:40px;max-width:210px;border-radius:14px;padding:0 10px;background:#f5f3ff;border:1px solid #ede9fe;color:#5b21b6;font-size:11.5px;font-weight:800;cursor:pointer}
        .hx-mdl:hover{background:#ede9fe}.hx-mdl select{appearance:none;-webkit-appearance:none;background:transparent;border:0;outline:none;font:inherit;color:inherit;cursor:pointer;max-width:150px;padding-left:14px;text-overflow:ellipsis}
        .hx-mdl span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:150px}.hx-mdl-c{position:absolute;left:10px;font-size:9px;opacity:.6;pointer-events:none}
        @media(max-width:600px){.hx-mdl{max-width:120px}.hx-mdl select,.hx-mdl span{max-width:80px}}
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
        // بلوکِ کد: سربرگ با نامِ زبان و دکمه‌ی کپی؛ بلوکی که هنوز بسته نشده (وسطِ تایپ) هم کد نشان داده می‌شود
        const code = (l, c) => { blocks.push(`<div class="hx-code"><div class="hx-code-h"><span>${l || 'code'}</span><button type="button" class="hx-cp" data-cp><i class="far fa-copy"></i>کپی</button></div><pre><code>${c.replace(/\n$/, '')}</code></pre></div>`); return `\n\u0000${blocks.length - 1}\u0000\n`; };
        let t = esc(src).replace(/```([\w+#.-]*)[^\n`]*\n?([\s\S]*?)```/g, (m, l, c) => code(l, c)).replace(/```([\w+#.-]*)[^\n`]*\n?([\s\S]*)$/, (m, l, c) => code(l, c));
        const inline = s => s.replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<i>$2</i>');
        const out = []; const lines = t.split('\n'); let i = 0;
        while (i < lines.length) {
            const L = lines[i];
            if (/^\u0000\d+\u0000$/.test(L.trim())) { out.push(L.trim()); i++; continue; }
            if (/^\s*\|.*\|\s*$/.test(L) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
                const row = r => r.trim().replace(/^\||\|$/g, '').split('|').map(x => inline(x.trim()));
                let h = `<table class="no-count no-page"><thead><tr>${row(L).map(x => `<th>${x}</th>`).join('')}</tr></thead><tbody>`; i += 2;
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

    // دکمه‌ی «کپی»ِ بلوک‌های کد (پاسخ‌هایی که دوباره کشیده می‌شوند هم کار می‌کند)
    document.addEventListener('click', async e => {
        const b = e.target.closest && e.target.closest('.hx-cp'); if (!b) return;
        const c = b.closest('.hx-code'); const txt = c ? c.querySelector('code').textContent : '';
        let ok = false;
        try { await navigator.clipboard.writeText(txt); ok = true; } catch (x) {
            const ta = document.createElement('textarea'); ta.value = txt; ta.style.cssText = 'position:fixed;opacity:0;top:0'; document.body.appendChild(ta); ta.select();
            try { ok = document.execCommand('copy'); } catch (y) {} ta.remove();
        }
        b.classList.toggle('ok', ok); b.innerHTML = ok ? '<i class="fas fa-check"></i>کپی شد' : 'کپی نشد';
        setTimeout(() => { if (b.isConnected) { b.classList.remove('ok'); b.innerHTML = '<i class="far fa-copy"></i>کپی'; } }, 1600);
    });

    async function init(el) {
        css(); H.el = el;
        el.innerHTML = '<div class="hx"><div class="hx-hero"><div class="relative" style="z-index:1"><i class="fas fa-spinner fa-spin ml-1"></i>در حالِ بیدار کردنِ هوشیکس…</div></div></div>';
        const b = await post({ action: 'boot' });
        if (!b.ok) { el.innerHTML = `<div class="hx"><div class="hx-hero"><div class="relative" style="z-index:1">${esc(b.error)}</div></div></div>`; return; }
        H.boot = b;
        if (b.gift) setTimeout(() => toast('🎂 تولدتان مبارک! اشتراکِ «' + b.gift + '» هدیه‌ی ما برای شما فعال شد.', 'success'), 500);
        if (!b.models.includes(H.model)) H.model = b.models[0] || '';
        render();
        if (H.tid && b.threads.some(t => t.id === H.tid)) openThread(H.tid);
    }
    function usageHtml() {
        const m = H.boot.me || {};
        const bar = (u, l) => l ? `<span class="hx-bar"><i style="width:${Math.min(100, Math.round(u * 100 / l))}%"></i></span>` : '';
        const sub = (m.subs || [])[0];
        return `${m.daily ? `<span class="hx-chip" title="پیام‌های امروز"><i class="fas fa-comment-dots"></i>امروز ${fa(m.used_today)} از ${fa(m.daily)}${bar(m.used_today, m.daily)}</span>` : ''}
            <span class="hx-chip" title="توکنِ امروز (هر روز صفر می‌شود)"><i class="fas fa-sun"></i>${m.day_tokens ? `امروز ${num(m.used_day)} از ${num(m.day_tokens)}` : `امروز ${num(m.used_day)} توکن`}${bar(m.used_day, m.day_tokens)}</span>
            <span class="hx-chip" title="توکنِ این هفته (هر شنبه صفر می‌شود)${m.month_tokens ? ' · این ماه ' + num(m.used_month) + ' از ' + num(m.month_tokens) : ''}"><i class="fas fa-calendar-week"></i>${m.week_tokens ? `هفته ${num(m.used_week)} از ${num(m.week_tokens)}` : `هفته ${num(m.used_week)}`}${bar(m.used_week, m.week_tokens)}</span>
            ${sub ? `<span class="hx-chip" style="background:rgba(250,204,21,.18);border-color:rgba(250,204,21,.4)" title="اشتراکِ فعال"><i class="fas fa-crown" style="color:#facc15"></i>${esc(sub.name)} · ${fa(sub.days_left)} روز</span>` : ''}
            ${H.boot.shop ? `<button class="hx-chip" data-shop style="background:linear-gradient(120deg,rgba(124,58,237,.55),rgba(6,182,212,.55))"><i class="fas fa-bolt"></i>${sub ? 'تمدید / ارتقا' : 'خریدِ اشتراک'}</button>` : ''}`;
    }
    function bindUsage() { const b = H.el.querySelector('[data-shop]'); if (b) b.onclick = shop; }
    function paintUsage() { const u = H.el.querySelector('[data-usage]'); if (u) { u.innerHTML = usageHtml(); bindUsage(); } }
    function render() {
        const b = H.boot;
        H.el.innerHTML = `<div class="hx">
            <div class="hx-hero"><div class="relative flex flex-wrap items-center justify-between gap-3" style="z-index:1">
                <div class="flex items-center gap-3"><div class="hx-logo ${H.busy ? 'live' : ''}" data-logo>${logo(56)}</div>
                    <div><h2 class="font-black text-xl leading-7">هوشیکس <span style="background:linear-gradient(90deg,#a78bfa,#22d3ee);-webkit-background-clip:text;background-clip:text;color:transparent">X ai</span></h2>
                    <p class="text-[11px] opacity-75">دستیارِ هوشمندِ شما — بپرسید، بنویسید یا با صدا حرف بزنید</p></div></div>
                <div class="flex flex-wrap items-center gap-2">
                    ${b.allowed ? `<span data-usage class="contents">${usageHtml()}</span>
` : ''}
                    <span class="hx-chip" title="هوشِ مصنوعی به هیچ اطلاعاتی از سایت (پرونده‌ها، مشتریان، فایل‌ها) دسترسی ندارد${b.mask ? '؛ کد ملی، موبایل و شماره کارت هم پیش از ارسال پوشانده می‌شود' : ''}"><i class="fas fa-shield-halved" style="color:#34d399"></i>بدونِ دسترسی به اطلاعاتِ سایت</span>
                    ${b.is_admin ? '<button class="hx-chip" data-set style="background:rgba(255,255,255,.2)"><i class="fas fa-sliders"></i>تنظیمات</button>' : ''}
                    <button class="hx-chip md:hidden" data-sidebtn><i class="fas fa-list"></i>گفتگوها</button></div></div></div>
            ${!b.allowed ? `<div class="hx-chat"><div class="hx-hello"><div class="hx-orb">${logo(64)}</div><h3 class="font-black text-lg text-slate-800">${b.enabled ? 'دسترسی برای شما فعال نیست' : 'هوشیکس فعلاً خاموش است'}</h3><p class="text-xs text-slate-500 mt-2 leading-7">${b.enabled ? 'از مدیر کل بخواهید در «تنظیماتِ هوشیکس» برای شما دسترسی و سهمیه تعیین کند.' : 'مدیر کل می‌تواند از «تنظیمات» روشنش کند.'}</p></div></div>` : `
            <div class="hx-main">
                <aside class="hx-side" data-side><button class="hx-new" data-new><i class="fas fa-plus ml-1"></i>گفتگوی تازه</button>
                    <input class="hx-in !mt-0" data-q placeholder="جستجو در گفتگوها…" value="${esc(H.q)}"><div class="flex-1 overflow-y-auto space-y-1" data-threads></div></aside>
                <section class="hx-chat"><div class="hx-head" data-head style="display:none"></div><div class="hx-msgs" data-msgs></div>
                    <div class="hx-comp"><div class="hx-att" data-att></div><div class="hx-box">
                        ${b.files ? `<button class="hx-ib" data-clip title="پیوستِ فایل (عکس، PDF، Word، Excel، متن؛ حداکثر ${fa(b.file_max_count)} فایل، هر کدام ${fa(b.file_max_mb)} مگابایت)"><i class="fas fa-paperclip"></i></button>
                            <input type="file" data-file hidden multiple accept="${(b.file_ext || []).map(x => '.' + x).join(',')}">` : ''}
                        <textarea rows="1" data-in placeholder="از هوشیکس بپرسید… (Enter = ارسال، Shift+Enter = خطِ تازه)"></textarea>
                        ${b.voice ? '<button class="hx-ib" data-mic title="حرف بزنید (دوباره بزنید تا تمام شود)"><i class="fas fa-microphone"></i></button>' : ''}
                        <label class="hx-mdl" title="مدلِ این گفتگو (عوض کردنش گفتگوی تازه باز می‌کند)"><i class="fas fa-microchip"></i>${b.models.length > 1
                            ? `<select data-model>${b.models.map(m => `<option value="${esc(m)}" ${m === H.model ? 'selected' : ''}>${esc(mLabel(m))}</option>`).join('')}</select><i class="fas fa-chevron-down hx-mdl-c"></i>`
                            : `<span>${esc(mLabel(H.model))}</span>`}</label>
                        <button class="hx-ib go" data-send title="ارسال"><i class="fas fa-paper-plane"></i></button></div>
                    <div class="hx-foot"><span>${b.ready ? 'هوشیکس ممکن است اشتباه کند؛ پاسخ‌های مهم را بررسی کنید.' : '<b class="text-rose-500">سرویس‌دهنده هنوز تنظیم نشده.</b>'}</span>
                        ${b.voice && b.voice_reply ? `<label class="hx-sw"><input type="checkbox" data-vm ${H.voiceMode ? 'checked' : ''}><i class="fas fa-headset"></i>گفتگوی صوتی (پاسخ را بخواند و دوباره گوش دهد)</label>` : ''}</div></div></section></div>`}</div>`;
        const q = s => H.el.querySelector(s);
        if (q('[data-set]')) q('[data-set]').onclick = settings;
        if (!b.allowed) return;
        // هر گفتگو یک مدل دارد: با عوض کردنِ مدل وسطِ گفتگو، گفتگوی تازه باز می‌شود
        q('[data-model]') && (q('[data-model]').onchange = e => {
            H.model = e.target.value; try { localStorage.setItem('hx_model', H.model); } catch (x) {}
            if (H.tid || H.msgs.length) { H.tid = 0; H.code = ''; H.msgs = []; paintThreads(); paintMsgs(); toast('گفتگوی تازه با مدلِ «' + mLabel(H.model) + '» باز شد.', 'info'); }
            q('[data-in]').focus();
        });
        q('[data-new]').onclick = () => { H.tid = 0; H.code = ''; H.msgs = []; paintThreads(); paintMsgs(); q('[data-in]').focus(); q('[data-side]').classList.remove('open'); };
        q('[data-sidebtn]').onclick = () => q('[data-side]').classList.toggle('open');
        q('[data-q]').oninput = e => { H.q = e.target.value; paintThreads(); };
        const ta = q('[data-in]');
        const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(180, ta.scrollHeight) + 'px'; };
        ta.oninput = grow;
        ta.onkeydown = e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); } };
        q('[data-send]').onclick = () => send();
        if (q('[data-mic]')) q('[data-mic]').onclick = () => (H.rec ? stopRec() : startRec());
        if (q('[data-vm]')) q('[data-vm]').onchange = e => { H.voiceMode = e.target.checked; try { localStorage.setItem('hx_voice', H.voiceMode ? '1' : '0'); } catch (x) {} if (!H.voiceMode) stopAudio(); };
        if (q('[data-clip]')) { q('[data-clip]').onclick = () => q('[data-file]').click(); q('[data-file]').onchange = e => { addFiles([...e.target.files]); e.target.value = ''; }; }
        // کشیدن و رها کردن / چسباندنِ عکس
        if (b.files) {
            const chat = q('.hx-chat');
            chat.ondragover = e => { e.preventDefault(); }; chat.ondrop = e => { e.preventDefault(); addFiles([...(e.dataTransfer.files || [])]); };
            ta.onpaste = e => { const f = [...((e.clipboardData && e.clipboardData.files) || [])]; if (f.length) { e.preventDefault(); addFiles(f); } };
        }
        bindUsage(); paintThreads(); paintMsgs(); paintAtt();
    }
    // ---- پیوست: عکس‌ها در مرورگر کوچک می‌شوند (حداکثر ۲۰۰۰ پیکسل)، بقیه همان‌طور؛ بررسیِ اصلیِ امنیتی سمتِ سرور ----
    const fileIcon = n => /\.pdf$/i.test(n) ? 'fa-file-pdf' : (/\.docx$/i.test(n) ? 'fa-file-word' : (/\.xlsx$/i.test(n) ? 'fa-file-excel' : (/\.(jpe?g|png|webp|gif)$/i.test(n) ? 'fa-image' : 'fa-file-lines')));
    async function shrinkImg(f) {
        if (!/^image\/(jpeg|png|webp)$/.test(f.type) || f.size < 600 * 1024) return null;
        try {
            const bm = await createImageBitmap(f), k = Math.min(1, 2000 / Math.max(bm.width, bm.height));
            const c = document.createElement('canvas'); c.width = Math.round(bm.width * k); c.height = Math.round(bm.height * k);
            const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(bm, 0, 0, c.width, c.height);
            return c.toDataURL('image/jpeg', .88);
        } catch (e) { return null; }
    }
    const readUrl = f => new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(f); });
    async function addFiles(list) {
        const b = H.boot, ok = new RegExp('\\.(' + (b.file_ext || []).join('|') + ')$', 'i');
        for (const f of list) {
            if (H.att.length >= b.file_max_count) { toast(`حداکثر ${fa(b.file_max_count)} فایل در هر پیام.`, 'warning'); break; }
            if (!ok.test(f.name)) { toast(`«${f.name}» از نوع‌های مجاز نیست.`, 'error'); continue; }
            if (f.size > b.file_max_mb * 1048576) { toast(`«${f.name}» بیش از ${fa(b.file_max_mb)} مگابایت است.`, 'error'); continue; }
            const data = (await shrinkImg(f)) || (await readUrl(f));
            H.att.push({ name: /\.(png|webp)$/i.test(f.name) && /^data:image\/jpeg/.test(data) ? f.name.replace(/\.\w+$/, '.jpg') : f.name, size: f.size, data, img: /^data:image\//.test(data) ? data : '' });
        }
        paintAtt();
    }
    function paintAtt() {
        const box = H.el && H.el.querySelector('[data-att]'); if (!box) return;
        box.innerHTML = H.att.map((a, i) => `<span>${a.img ? `<img src="${a.img}" alt="">` : `<i class="fas ${fileIcon(a.name)}"></i>`}<b title="${esc(a.name)}">${esc(a.name)}</b><button data-rma="${i}" title="حذف"><i class="fas fa-xmark"></i></button></span>`).join('');
        box.querySelectorAll('[data-rma]').forEach(x => x.onclick = () => { H.att.splice(+x.dataset.rma, 1); paintAtt(); });
    }
    function paintThreads() {
        const box = H.el.querySelector('[data-threads]'); if (!box) return;
        const list = (H.boot.threads || []).filter(t => !H.q || t.title.includes(H.q) || (t.code || '').toLowerCase().includes(H.q.toLowerCase()));
        box.innerHTML = list.length ? list.map(t => `<div class="hx-th ${t.id === H.tid ? 'on' : ''}" data-t="${t.id}"><i class="fas fa-message text-[10px] text-violet-400"></i><b title="${esc(t.title)} — ${esc(t.code || '')}">${esc(t.title || 'گفتگو')}</b>
            <button data-ren="${t.id}" title="تغییرِ نام"><i class="fas fa-pen"></i></button><button data-del="${t.id}" title="حذف"><i class="fas fa-trash"></i></button></div>`).join('')
            : '<p class="text-center text-[11px] text-slate-400 py-6">هنوز گفتگویی نیست.</p>';
        box.querySelectorAll('[data-t]').forEach(x => x.onclick = e => { if (e.target.closest('button')) return; openThread(+x.dataset.t); H.el.querySelector('[data-side]').classList.remove('open'); });
        box.querySelectorAll('[data-del]').forEach(x => x.onclick = async () => {
            if (!(window.uiConfirm ? await uiConfirm('حذفِ گفتگو', 'این گفتگو حذف شود؟', { danger: true, ok: 'حذف' }) : confirm('حذف شود؟'))) return;
            const r = await post({ action: 'delete', id: +x.dataset.del }); if (!r.ok) return toast(r.error, 'error');
            H.boot.threads = r.threads; if (H.tid === +x.dataset.del) { H.tid = 0; H.code = ''; H.msgs = []; paintMsgs(); } paintThreads();
        });
        box.querySelectorAll('[data-ren]').forEach(x => x.onclick = async () => {
            const t = H.boot.threads.find(y => y.id === +x.dataset.ren); const v = window.uiPrompt ? await uiPrompt('تغییرِ نامِ گفتگو', t ? t.title : '', { placeholder: 'نامِ گفتگو', ok: 'ذخیره', required: true }) : prompt('نامِ گفتگو:', t ? t.title : ''); if (!v || !String(v).trim()) return;
            const r = await post({ action: 'rename', id: +x.dataset.ren, title: v }); if (r.ok) { H.boot.threads = r.threads; paintThreads(); }
        });
    }
    async function openThread(id) {
        const r = await post({ action: 'thread', id }); if (!r.ok) return toast(r.error, 'error');
        H.tid = id; H.code = r.thread.code || ''; H.started = r.thread.started || ''; H.msgs = r.messages;
        if (r.thread.model && H.boot.models.includes(r.thread.model)) { H.model = r.thread.model; const sel = H.el.querySelector('[data-model]'); if (sel) sel.value = H.model; }
        paintThreads(); paintMsgs();
    }
    function paintHead() {
        const h = H.el.querySelector('[data-head]'); if (!h) return;
        const tok = H.msgs.reduce((a, m) => a + (m.billed || 0), 0), n = H.msgs.length;
        h.style.display = H.tid && H.code ? '' : 'none';
        h.innerHTML = H.tid ? `<span>کدِ گفتگو <code>${esc(H.code)}</code></span>${H.started ? `<span><i class="far fa-clock ml-1"></i>شروع ${esc(fa(H.started))}</span>` : ''}<span>${fa(n)} پیام · ${num(tok)} توکن</span>
            <button class="mr-auto text-violet-600 font-bold" data-cpcode title="کپیِ کد"><i class="far fa-copy"></i></button>` : '';
        const cb = h.querySelector('[data-cpcode]'); if (cb) cb.onclick = async () => { try { await navigator.clipboard.writeText(H.code); toast('کدِ گفتگو کپی شد.', 'success'); } catch (e) {} };
    }
    const SUG = [['fa-envelope-open-text', 'یک نامه‌ی رسمی برای پیگیریِ پرداختِ خسارت بنویس'], ['fa-scale-balanced', 'فرقِ بیمه‌ی بدنه و ثالث را ساده توضیح بده'],
                 ['fa-language', 'این متن را به انگلیسیِ رسمی ترجمه کن: '], ['fa-list-check', 'برای جلسه‌ی فردا یک چک‌لیستِ کوتاه آماده کن']];
    function msgHtml(m, i) {
        const a = m.role === 'assistant';
        const fl = (m.files || []).map(f => f.kind === 'image' && f.id ? `<a href="${API}?action=file&id=${f.id}" target="_blank" rel="noopener"><img src="${API}?action=file&id=${f.id}" alt="${esc(f.name)}" loading="lazy"></a>`
            : `<span><i class="fas ${fileIcon(f.name)}"></i>${esc(f.name)}${f.pages ? ' · ' + fa(f.pages) + ' صفحه' : ''}</span>`).join('');
        const det = a ? [m.at ? fa(m.at) : '', m.ms ? '⏱ ' + fa((m.ms / 1000).toFixed(1)) + ' ث' : '', m.tokens ? num(m.tokens) + ' توکن' + (m.billed != null && m.billed !== m.tokens ? ' (حساب‌شده ' + num(m.billed) + ')' : '') : '',
                         m.model ? mLabel(m.model) : '', m.node ? '🖥 ' + m.node : ''].filter(Boolean).join(' · ') : (m.at ? fa(m.at) : '');
        return `<div class="hx-m ${a ? 'a' : 'u'}"><div class="hx-av">${a ? logo(32) : '<i class="fas fa-user"></i>'}</div><div class="min-w-0">
            ${fl ? `<div class="hx-mf">${fl}</div>` : ''}${m.text ? `<div class="hx-b" dir="auto">${a ? md(m.text) : esc(m.text)}</div>` : ''}
            <div class="hx-tools">${a ? `<button data-cp="${i}" title="کپی"><i class="fas fa-copy"></i></button>${H.boot.voice_reply ? `<button data-say="${i}" title="خواندن با صدا"><i class="fas fa-volume-high"></i></button>` : ''}`
                : `<button data-cp="${i}" title="کپی"><i class="fas fa-copy"></i></button><button data-ed="${i}" title="ویرایش و ارسالِ دوباره"><i class="fas fa-pen"></i></button>`}${det ? `<small>${esc(det)}</small>` : ''}</div></div></div>`;
    }
    function paintMsgs(typing) {
        const box = H.el.querySelector('[data-msgs]'); if (!box) return;
        if (!H.msgs.length && !typing) {
            paintHead();
            box.innerHTML = `<div class="hx-hello"><div class="hx-orb">${logo(64)}</div><h3 class="font-black text-lg text-slate-800">سلام${H.boot.user ? ' ' + esc(H.boot.user.split(' ')[0]) : ''}! من هوشیکسم 👋</h3>
                <p class="text-xs text-slate-500 mt-1 leading-7">هر سؤالی دارید بپرسید؛ متن بنویسم، خلاصه کنم، ترجمه کنم یا ایده بدهم.${H.boot.voice ? ' می‌توانید با میکروفون هم حرف بزنید.' : ''}<br><span class="text-[10.5px] text-emerald-600"><i class="fas fa-shield-halved ml-1"></i>به اطلاعاتِ سایت دسترسی ندارم و فقط همین گفتگو را می‌بینم.</span></p>
                <div class="hx-sug">${SUG.map(s => `<button data-sug="${esc(s[1])}"><i class="fas ${s[0]} ml-1 text-violet-500"></i>${esc(s[1])}</button>`).join('')}</div></div>`;
            box.querySelectorAll('[data-sug]').forEach(x => x.onclick = () => { const ta = H.el.querySelector('[data-in]'); ta.value = x.dataset.sug; ta.focus(); ta.oninput(); if (!/: $/.test(x.dataset.sug)) send(); });
            return;
        }
        paintHead();
        box.innerHTML = H.msgs.map(msgHtml).join('') + (typing ? `<div class="hx-m a" data-livebox><div class="hx-av">${logo(32, 'live')}</div><div class="min-w-0"><div class="hx-b" dir="auto" data-live><span class="hx-dots"><i></i><i></i><i></i></span> <small class="text-slate-400 mr-1" data-lstat>${esc(typing === true ? 'هوشیکس در حالِ فکر کردن…' : typing)}</small></div></div></div>` : '');
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
    // برچسبِ مدل: نامِ نمایشیِ دلخواهِ مدیر، وگرنه خودِ نامِ مدل
    const mLabel = m => ((H.boot && H.boot.model_labels) || {})[m] || m;
    // ---- نمایشِ تایپی: متن با سرعتی متناسب با عقب‌ماندگی ظاهر می‌شود (جوابِ کلمه‌به‌کلمه‌ی سیستمِ داخلی یا جوابِ کاملِ سرویسِ آنلاین) ----
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    function liveStat(t) { const s = H.el && H.el.querySelector('[data-lstat]'); if (s) s.textContent = t; }
    function typer() {
        const T = { target: '', shown: 0, done: false, raf: 0, last: 0, started: false };
        let resolve; T.finished = new Promise(r => { resolve = r; });
        const caret = '<span class="hx-caret"></span>';
        const paint = () => {
            const el = H.el && H.el.querySelector('[data-live]'); if (!el) return;
            let h = md(T.target.slice(0, T.shown));
            // نشانگرِ تایپ داخلِ آخرین بند
            const m = /<\/(p|li|h3|h4|td|code)>((?:<\/(?:ul|ol|tbody|table|pre|div)>)*)$/.exec(h);
            h = m ? h.slice(0, m.index) + caret + h.slice(m.index) : h + caret;
            el.innerHTML = h;
            const box = H.el.querySelector('[data-msgs]'); if (box && box.scrollHeight - box.scrollTop - box.clientHeight < 160) box.scrollTop = box.scrollHeight;
        };
        const step = ts => {
            const back = T.target.length - T.shown;
            const dt = T.last ? Math.min(80, ts - T.last) : 16; T.last = ts;
            if (back > 0) {
                // هر چه عقب‌تر، تندتر؛ در پایان تا حدودِ یک ثانیه تمام می‌شود
                const cps = T.done ? Math.max(120, back * 1.2) : Math.max(40, back / 1.6);
                T.shown = Math.min(T.target.length, T.shown + Math.max(1, Math.round(cps * dt / 1000)));
                // نصفه‌ی یک کلمه نماند
                if (T.shown < T.target.length && !T.done) { const sp = T.target.indexOf(' ', T.shown); if (sp > 0 && sp - T.shown < 8) T.shown = sp; }
                paint();
            } else if (T.done) { T.raf = 0; const el = H.el && H.el.querySelector('[data-live]'); if (el) el.innerHTML = md(T.target); resolve(); return; }
            T.raf = requestAnimationFrame(step);
        };
        T.set = (txt, done) => {
            txt = String(txt || '');
            if (txt.length >= T.target.length || done) T.target = txt;
            if (T.shown > T.target.length) T.shown = T.target.length;
            if (done) T.done = true;
            if (T.target && !T.started) { T.started = true; const lb = H.el && H.el.querySelector('[data-livebox] .hx-av'); if (lb) lb.innerHTML = logo(32, 'live'); }
            if (!T.raf && (T.target || T.done)) T.raf = requestAnimationFrame(step);
        };
        T.stop = () => { if (T.raf) cancelAnimationFrame(T.raf); T.raf = 0; resolve(); };
        return T;
    }
    async function send(text) {
        const ta = H.el.querySelector('[data-in]');
        text = (text != null ? text : ta.value).trim();
        const att = H.att.slice();
        if ((!text && !att.length) || H.busy) return;
        ta.value = ''; ta.style.height = 'auto'; H.att = []; paintAtt();
        const now = new Date(), stamp = fa(now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }));
        H.msgs.push({ role: 'user', text, files: att.map(a => ({ name: a.name, kind: a.img ? 'image' : 'file' })), at: stamp }); setBusy(true); paintMsgs(att.length ? 'در حالِ بررسی و خواندنِ فایل‌ها…' : true);
        const body = () => ({ action: 'send', thread_id: H.tid || 0, text, model: H.model, stream: 1, files: att.map(a => ({ name: a.name, data: a.data })) });
        const tp = typer();
        let r = await post(body());
        // سیستمِ داخلی (کامپیوترِ دفتر): جواب کلمه‌به‌کلمه از partial می‌رسد؛ وقتی تمام شد همان پیام دوباره فرستاده می‌شود تا (از کش) ذخیره شود
        const t0 = Date.now();
        for (let round = 0; !r.ok && r.pending && document.body.contains(H.el) && Date.now() - t0 < 15 * 60000; round++) {
            if (r.thread_id && !H.tid) { H.tid = r.thread_id; H.code = r.code || ''; }
            if (r.job_id) {
                let st = '', fails = 0;
                while (document.body.contains(H.el) && Date.now() - t0 < 15 * 60000) {
                    const p = await post({ action: 'partial', job_id: r.job_id });
                    if (!p.ok) { if (++fails > 5) break; await sleep(1500); continue; }
                    st = p.status;
                    if (p.text) tp.set(p.text);
                    else liveStat(st === 'QUEUED' ? 'در صفِ سیستمِ داخلی…' : 'سیستمِ داخلی در حالِ خواندنِ پیام…');
                    if (st === 'DONE' || st === 'FAIL') break;
                    await sleep(600);
                }
            } else { liveStat('سیستمِ داخلی در حالِ نوشتنِ جواب است…'); await sleep(2500); }
            r = await post(body());
        }
        setBusy(false);
        if (!r.ok) {
            tp.stop();
            H.msgs.pop(); paintMsgs(); ta.value = text; ta.oninput(); H.att = att; paintAtt();
            if (r.me) { H.boot.me = r.me; paintUsage(); }
            if (r.thread_id && !H.tid) { H.tid = r.thread_id; H.code = r.code || ''; }
            toast(r.error || 'خطا', 'error');
            if (r.quota && H.boot.shop) setTimeout(shop, 600);
            return;
        }
        if (H.tid !== r.thread_id) H.started = stamp;
        H.tid = r.thread_id; H.code = r.code || H.code; H.boot.threads = r.threads; H.boot.me = r.me;
        H.msgs[H.msgs.length - 1].files = r.files || [];
        // بقیه‌ی جواب با همان حالتِ تایپ، بعد پیام ثبت می‌شود
        tp.set(r.reply, true);
        await tp.finished;
        H.msgs.push({ role: 'assistant', text: r.reply, tokens: r.tokens, billed: r.billed, ms: r.ms, model: H.model, at: fa(new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' })) });
        paintMsgs(); paintThreads(); paintUsage();
        if (H.voiceMode && H.boot.voice_reply) speak(r.reply, () => { if (H.voiceMode && document.body.contains(H.el)) startRec(true); });
    }

    // ---------------- اشتراک: پلن‌ها (با تخفیفِ امروز)، درگاه‌ها، اشتراک‌ها و پرداخت‌های من ----------------
    async function shop() {
        const d = await post({ action: 'shop' }); if (!d.ok) return toast(d.error, 'error');
        const m = document.createElement('div'); m.className = 'hx-modal';
        const tk = v => v ? num(v) : 'نامحدود';
        let pick = null;
        m.innerHTML = `<div><div class="flex items-center justify-between p-4 border-b"><b class="text-sm flex items-center gap-2">${logo(26)}اشتراکِ هوشیکس</b><button class="hx-btn bg-slate-100" data-x>بستن</button></div>
            <div class="p-4 overflow-y-auto space-y-4">
                ${(d.offers || []).length ? `<div class="rounded-2xl p-3 text-white text-[12.5px] font-bold" style="background:linear-gradient(120deg,#db2777,#7c3aed)">🎁 ${d.offers.map(o => `${esc(o.name)}: ${fa(o.percent)}٪ تخفیف`).join(' · ')}</div>` : ''}
                ${(d.me.subs || []).length ? `<div class="rounded-2xl bg-amber-50 border border-amber-200 p-3 text-[12px]"><i class="fas fa-crown text-amber-500 ml-1"></i>اشتراکِ فعال: ${d.me.subs.map(s => `<b>${esc(s.name)}</b> تا ${esc(fa(s.ends))}`).join('، ')} — خریدِ همان پلن از پایانش تمدید می‌شود.</div>` : ''}
                <div class="hx-plans">${d.plans.map(p => `<div class="hx-plan" data-plan="${p.id}" style="${p.color ? 'border-top:5px solid ' + esc(p.color) : ''}">
                    ${p.badge ? `<span class="bd" style="background:${esc(p.color || '#7c3aed')}">${esc(p.badge)}</span>` : ''}
                    <b class="block text-[14px] text-slate-800">${esc(p.name)}</b>
                    <div class="mt-2"><span class="pr">${num(p.final)}</span> <small class="text-slate-500">تومان</small>${p.offer ? `<del>${num(p.price)}</del><span class="text-[11px] font-black text-pink-600">${fa(p.offer.percent)}٪ ${esc(p.offer.name)}</span>` : ''}</div>
                    <ul class="mt-2"><li><i class="fas fa-calendar text-violet-400 ml-1"></i>${fa(p.days)} روز</li><li><i class="fas fa-sun text-amber-400 ml-1"></i>روزانه ${tk(p.day_tokens)} توکن</li>
                        <li><i class="fas fa-calendar-week text-cyan-500 ml-1"></i>هفتگی ${tk(p.week_tokens)} توکن</li>${p.month_tokens ? `<li><i class="fas fa-calendar-days text-slate-400 ml-1"></i>ماهانه ${num(p.month_tokens)}</li>` : ''}
                        ${p.daily_msgs ? `<li><i class="fas fa-comment text-slate-400 ml-1"></i>روزی ${fa(p.daily_msgs)} پیام</li>` : ''}<li>${p.files ? '<i class="fas fa-paperclip text-emerald-500 ml-1"></i>فرستادنِ فایل' : '<i class="fas fa-paperclip text-slate-300 ml-1"></i><span class="text-slate-400">بدونِ فایل</span>'}</li></ul>
                    ${p.descr ? `<p class="text-[10.5px] text-slate-400 mt-1 leading-5">${esc(p.descr)}</p>` : ''}</div>`).join('') || '<p class="text-xs text-slate-400">فعلاً پلنی برای فروش نیست.</p>'}</div>
                <p class="text-[10.5px] text-slate-500"><i class="fas fa-server text-emerald-600 ml-1"></i>توکنِ مدل‌های «سیستمِ داخلی» ${H.boot.ollama_rate < 1 ? fa(Math.round(H.boot.ollama_rate * 100)) + '٪' : 'کامل'} حساب می‌شود؛ یعنی با همان اشتراک بیشتر می‌توانید گفتگو کنید.${d.birth ? '' : ' تولدتان را در «تنظیماتِ من» ثبت کنید تا تخفیفِ تولد بگیرید 🎂'}</p>
                <div data-pay style="display:none" class="rounded-2xl border border-violet-100 bg-violet-50/40 p-3 space-y-2"><b class="text-[12px]">درگاهِ پرداخت</b>
                    <div class="hx-gw">${d.gateways.map((g, i) => `<label><input type="radio" name="hxgw" value="${esc(g.id)}" ${i ? '' : 'checked'}><i class="fas ${esc(g.icon)} text-violet-500"></i>${esc(g.title)}</label>`).join('') || '<span class="text-[11px] text-rose-600">هنوز درگاهی تنظیم نشده؛ به مدیر کل خبر دهید.</span>'}</div>
                    <button class="hx-btn text-white" style="background:linear-gradient(120deg,#7c3aed,#06b6d4)" data-buy ${d.gateways.length ? '' : 'disabled'}><i class="fas fa-lock ml-1"></i>پرداخت و فعال‌سازی</button></div>
                ${(d.subs || []).length || (d.payments || []).length ? `<details class="text-[11.5px]"><summary class="cursor-pointer font-bold text-slate-600">سابقه‌ی اشتراک‌ها و پرداخت‌های من</summary>
                    <div class="grid md:grid-cols-2 gap-3 mt-2"><div>${(d.subs || []).map(s => `<div class="py-1 border-b border-slate-100">${s.active ? '🟢' : '⚪'} <b>${esc(s.name)}</b> <span class="text-slate-400">${esc(fa(s.starts))} تا ${esc(fa(s.ends))} · ${{ pay: 'خرید', admin: 'از طرفِ مدیر', gift: 'هدیه' }[s.source] || ''}</span></div>`).join('')}</div>
                    <div>${(d.payments || []).map(x => `<div class="py-1 border-b border-slate-100">${x.ok ? '✅' : '•'} ${num(x.toman)} تومان <span class="text-slate-400">${esc(fa(x.at))} · ${esc(x.status)}${x.ref ? ' · کدِ پیگیری ' + esc(fa(x.ref)) : ''}</span></div>`).join('')}</div></div></details>` : ''}
            </div></div>`;
        m.onclick = e => { if (e.target === m || e.target.closest('[data-x]')) m.remove(); };
        document.body.appendChild(m);
        m.querySelectorAll('[data-plan]').forEach(c => c.onclick = () => {
            pick = +c.dataset.plan; m.querySelectorAll('[data-plan]').forEach(x => x.classList.toggle('on', x === c));
            const pl = d.plans.find(p => p.id === pick), pay = m.querySelector('[data-pay]');
            pay.style.display = ''; pay.querySelector('[data-buy]').innerHTML = `<i class="fas fa-lock ml-1"></i>${pl.final ? 'پرداختِ ' + num(pl.final) + ' تومان و فعال‌سازی' : 'فعال‌سازیِ رایگان'}`;
            pay.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
        const bb = m.querySelector('[data-buy]');
        if (bb) bb.onclick = async () => {
            const gw = (m.querySelector('[name="hxgw"]:checked') || {}).value;
            bb.disabled = true; bb.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ رفتن به درگاه…';
            const r = await post({ action: 'buy', plan_id: pick, gateway_id: gw });
            if (!r.ok) { bb.disabled = false; bb.innerHTML = '<i class="fas fa-lock ml-1"></i>دوباره'; return toast(r.error, 'error'); }
            if (r.free) { toast('اشتراک فعال شد 🎉', 'success'); m.remove(); H.boot.me = r.me; paintUsage(); return; }
            const p = r.pay;
            if (p.method === 'POST') {
                const f = document.createElement('form'); f.method = 'POST'; f.action = p.url;
                Object.entries(p.fields || {}).forEach(([k, v]) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); });
                document.body.appendChild(f); f.submit();
            } else location.href = p.url;
        };
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

    // ---------------- تنظیمات (مدیر کل): hooshix-admin.js ----------------
    function settings() {
        if (!window.HooshixAdmin) return toast('بخشِ تنظیماتِ هوشیکس بارگذاری نشد؛ صفحه را تازه کنید.', 'error');
        HooshixAdmin.open({ post, esc, fa, num, toast, md, logo, reinit: () => init(H.el) });
    }
    window.Hooshix = { init };
})();
