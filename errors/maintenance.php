<?php
// فایل: errors/maintenance.php
// صفحه‌ی «سایت در حالِ به‌روزرسانی است» — وقتی مدیر کل از «تنظیمات ← حالتِ به‌روزرسانی» سایت را بسته است، هر کسی جز مدیر کل این را می‌بیند.
// از api/_security.php (sec_maint_respond) include می‌شود؛ متغیرها: $mMsg (پیام)، $mUntil (زمانِ تقریبیِ پایان، متن)، $mLogo (آدرسِ لوگو یا '')
if (!isset($mMsg)) { http_response_code(503); $mMsg = ''; $mUntil = ''; $mLogo = ''; }
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex"><meta name="theme-color" content="#0b1023">
<title>در حالِ به‌روزرسانی | بیمه با ما</title>
<style>
@font-face{font-family:Vazir;src:url('/Font/Vazir-Regular.woff2') format('woff2');font-weight:400;font-display:swap}
@font-face{font-family:Vazir;src:url('/Font/Vazir-Bold.woff2') format('woff2');font-weight:700;font-display:swap}
@font-face{font-family:Vazir;src:url('/Font/Vazir-Black.woff2') format('woff2');font-weight:900;font-display:swap}
*{box-sizing:border-box;margin:0;padding:0}
:root{--a:#6366f1;--b:#06b6d4;--c:#a855f7}
html,body{height:100%}
body{font-family:Vazir,Tahoma,sans-serif;direction:rtl;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;overflow:hidden;position:relative;
  background:radial-gradient(1100px 700px at 85% -10%,rgba(99,102,241,.45),transparent 60%),radial-gradient(900px 600px at -10% 110%,rgba(6,182,212,.35),transparent 60%),linear-gradient(160deg,#070b1c,#111a3a)}
.grid{position:fixed;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:44px 44px;mask-image:radial-gradient(circle at 50% 45%,#000 0,transparent 70%);animation:pan 30s linear infinite}
@keyframes pan{to{background-position:44px 44px,44px 44px}}
.orb{position:fixed;border-radius:50%;filter:blur(70px);opacity:.4;pointer-events:none;animation:drift 16s ease-in-out infinite alternate}
.o1{width:360px;height:360px;background:var(--a);top:-110px;left:-90px}.o2{width:300px;height:300px;background:var(--b);bottom:-100px;right:-60px;animation-delay:-6s}.o3{width:220px;height:220px;background:var(--c);top:40%;right:12%;animation-delay:-11s;opacity:.25}
@keyframes drift{to{transform:translate(50px,30px) scale(1.15)}}
.card{position:relative;z-index:2;width:min(560px,100%);text-align:center;padding:34px 26px 28px;border-radius:30px;background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.18);
  box-shadow:0 40px 80px -30px rgba(0,0,0,.7),inset 0 1px 0 rgba(255,255,255,.06);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);animation:rise .8s cubic-bezier(.2,.9,.3,1) both}
@keyframes rise{from{transform:translateY(24px) scale(.97);opacity:0}}
.emblem{position:relative;width:150px;height:150px;margin:0 auto 18px}
.ring{position:absolute;inset:0;border-radius:50%;border:3px solid transparent;border-top-color:var(--a);border-right-color:var(--b);animation:spin 2.4s linear infinite}
.ring.r2{inset:14px;border-top-color:var(--c);border-left-color:var(--a);animation-duration:3.6s;animation-direction:reverse}
.ring.r3{inset:28px;border-width:2px;border-bottom-color:var(--b);border-top-color:transparent;animation-duration:1.8s}
@keyframes spin{to{transform:rotate(360deg)}}
.core{position:absolute;inset:40px;border-radius:24px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(99,102,241,.35),rgba(6,182,212,.25));box-shadow:0 0 40px rgba(99,102,241,.45);animation:pulse 2.4s ease-in-out infinite}
.core img{max-width:52px;max-height:52px;object-fit:contain;filter:drop-shadow(0 4px 10px rgba(0,0,0,.4))}
.core svg{width:40px;height:40px}
@keyframes pulse{50%{transform:scale(1.07);box-shadow:0 0 60px rgba(6,182,212,.55)}}
.gear{position:absolute;width:30px;height:30px;color:#a5b4fc;opacity:.85}
.g1{top:-4px;right:6px;animation:spin 5s linear infinite}.g2{bottom:0;left:2px;width:24px;height:24px;color:#67e8f9;animation:spin 4s linear infinite reverse}
.tag{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:900;color:#67e8f9;background:rgba(6,182,212,.12);border:1px solid rgba(6,182,212,.3);border-radius:999px;padding:4px 14px;margin-bottom:12px}
.tag i{width:8px;height:8px;border-radius:50%;background:#22d3ee;box-shadow:0 0 0 0 rgba(34,211,238,.7);animation:ping 1.6s infinite}
@keyframes ping{70%{box-shadow:0 0 0 10px rgba(34,211,238,0)}100%{box-shadow:0 0 0 0 rgba(34,211,238,0)}}
h1{font-size:clamp(20px,5vw,27px);font-weight:900;line-height:1.6;background:linear-gradient(90deg,#fff,#c7d2fe,#a5f3fc);-webkit-background-clip:text;background-clip:text;color:transparent}
p.msg{margin-top:10px;font-size:13.5px;line-height:2.1;color:#cbd5e1;white-space:pre-line}
.bar{position:relative;height:8px;border-radius:999px;background:rgba(148,163,184,.15);overflow:hidden;margin:22px auto 10px;max-width:380px}
.bar i{position:absolute;inset:0;width:40%;border-radius:999px;background:linear-gradient(90deg,var(--a),var(--b),var(--c));animation:load 2.2s ease-in-out infinite}
@keyframes load{0%{transform:translateX(160%)}100%{transform:translateX(-260%)}}
.until{font-size:12px;color:#94a3b8}.until b{color:#e2e8f0}
.steps{display:flex;justify-content:center;gap:8px;margin-top:18px;flex-wrap:wrap}
.steps span{font-size:11px;font-weight:700;color:#a5b4fc;background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.25);border-radius:12px;padding:5px 11px;opacity:.4;animation:stp 6s infinite}
.steps span:nth-child(2){animation-delay:2s}.steps span:nth-child(3){animation-delay:4s}
@keyframes stp{0%,10%{opacity:.35}20%,40%{opacity:1;transform:translateY(-2px)}55%,100%{opacity:.35}}
.btn{margin-top:20px;display:inline-flex;align-items:center;gap:8px;border:0;border-radius:16px;padding:11px 22px;font:900 13px Vazir,Tahoma,sans-serif;color:#fff;cursor:pointer;background:linear-gradient(135deg,var(--a),var(--b));box-shadow:0 14px 30px -12px rgba(99,102,241,.8);transition:transform .2s}
.btn:hover{transform:translateY(-2px)}
.foot{margin-top:16px;font-size:10.5px;color:#64748b}.foot b{color:#94a3b8}
@media (prefers-reduced-motion:reduce){*{animation:none!important}}
</style></head>
<body>
<div class="grid"></div><div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div>
<main class="card">
    <div class="emblem">
        <div class="ring"></div><div class="ring r2"></div><div class="ring r3"></div>
        <svg class="gear g1" viewBox="0 0 24 24" fill="currentColor"><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.4-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3h-4l-.4 2.6a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.4L6.6 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.4 2.5-1a7.6 7.6 0 0 0 1.7 1L11 21h4l.4-2.6a7.6 7.6 0 0 0 1.7-1l2.5 1 2-3.4zM13 15.5A3.5 3.5 0 1 1 13 8.5a3.5 3.5 0 0 1 0 7z"/></svg>
        <svg class="gear g2" viewBox="0 0 24 24" fill="currentColor"><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.4-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3h-4l-.4 2.6a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.4L6.6 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.4 2.5-1a7.6 7.6 0 0 0 1.7 1L11 21h4l.4-2.6a7.6 7.6 0 0 0 1.7-1l2.5 1 2-3.4zM13 15.5A3.5 3.5 0 1 1 13 8.5a3.5 3.5 0 0 1 0 7z"/></svg>
        <div class="core"><?php if (!empty($mLogo)): ?><img src="<?= $h($mLogo) ?>" alt=""><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg><?php endif; ?></div>
    </div>
    <span class="tag"><i></i>بیمه با ما</span>
    <h1>سایت در حالِ به‌روزرسانی است</h1>
    <p class="msg"><?= $mMsg !== '' ? $h($mMsg) : "در حالِ اضافه کردنِ امکاناتِ تازه و بهتر کردنِ سرعت هستیم.\nچند دقیقه‌ی دیگر دوباره سر بزنید؛ اطلاعاتِ شما سرِ جایش امن است." ?></p>
    <div class="bar"><i></i></div>
    <div class="until"><?= $mUntil !== '' ? 'زمانِ تقریبیِ بازگشت: <b>' . $h($mUntil) . '</b>' : 'به‌محض پایان، این صفحه خودش باز می‌شود.' ?></div>
    <div class="steps"><span>پشتیبان‌گیری</span><span>نصبِ به‌روزرسانی</span><span>بررسیِ نهایی</span></div>
    <button class="btn">دوباره امتحان کن</button>
    <div class="foot">این صفحه هر ۳۰ ثانیه خودش بررسی می‌کند · <b>بیمه با ما</b></div>
</main>
<script>
// هر ۳۰ ثانیه: اگر سایت باز شد، صفحه‌ی قبلی (همان‌جا که کاربر بود) دوباره باز می‌شود
(function () {
    var back = '';
    try { back = new URLSearchParams(location.search).get('back') || ''; } catch (e) {}
    if (!/^\/(?![\/\\])/.test(back)) back = '';   // فقط مسیرِ داخلیِ همین سایت
    var go = function () { if (back) location.href = back; else if (/maintenance\.php/.test(location.pathname)) location.href = '/'; else location.reload(); };
    document.querySelector('.btn').onclick = go;
    var check = function () {
        fetch('/api/maint_status.php', {cache: 'no-store', credentials: 'same-origin'}).then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.ok && !d.blocked) go(); }).catch(function () {});
    };
    setInterval(check, 30000);
})();
</script>
</body></html>
