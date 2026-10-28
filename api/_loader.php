<?php
// فایل: api/_loader.php
// لودرِ شروعِ صفحه («بیمه با ما»): بلافاصله بعد از <body> چاپ می‌شود تا تا وقتی صفحه کامل بارگذاری نشده (فونت، Tailwind، اسکریپت‌ها)
// کاربر به‌جای صفحه‌ی نیمه‌کاره و پرش‌دار یک صفحه‌ی آراسته با لوگو ببیند. با رویدادِ load محو می‌شود؛ حداکثر بعد از ۸ ثانیه هم کنار می‌رود.
// site_loader($pdo, $prefix = '', $sub = '')   $prefix: '' برای صفحه‌های ریشه، '../' برای company-portal
// نمادِ وسط فقط CSS/SVG است (بدونِ عکس و بدونِ خواندنِ دیتابیس) تا همان لحظه‌ی اول کامل دیده شود.
function site_loader($pdo, $prefix = '', $sub = '') {
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $sub = $sub !== '' ? $sub : 'در حالِ آماده‌سازیِ میزِ کار';
    ?>
<div id="site-loader" aria-hidden="true">
<style>
#site-loader{position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:18px;direction:rtl;font-family:Vazir,Tahoma,sans-serif;color:#e2e8f0;
  background:radial-gradient(900px 600px at 85% -10%,rgba(99,102,241,.45),transparent 60%),radial-gradient(800px 600px at -10% 110%,rgba(6,182,212,.35),transparent 60%),linear-gradient(160deg,#070b1c,#111a3a);
  transition:opacity .45s ease,visibility .45s ease}
#site-loader.sl-out{opacity:0;visibility:hidden;pointer-events:none}
#site-loader .sl-orb{position:relative;width:168px;height:168px;perspective:600px;margin-bottom:6px}
#site-loader .sl-glow{position:absolute;inset:-40px;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,.55),rgba(34,211,238,.2) 45%,transparent 70%);filter:blur(10px);animation:slPulse 2.4s ease-in-out infinite}
#site-loader .sl-3d{position:absolute;inset:0;transform-style:preserve-3d;animation:slTilt 9s ease-in-out infinite}
#site-loader .sl-ring{position:absolute;inset:0;border-radius:50%;border:3px solid rgba(129,140,248,.22);transform-style:preserve-3d;box-shadow:0 0 14px rgba(129,140,248,.25)}
#site-loader .sl-ring::before{content:'';position:absolute;top:-6px;left:50%;width:10px;height:10px;margin-left:-5px;border-radius:50%;background:#fff;box-shadow:0 0 12px 3px currentColor,0 0 26px 6px currentColor}
#site-loader .sl-ring.r1{color:#22d3ee;border-top-color:#22d3ee;border-left-color:rgba(34,211,238,.6);transform:rotateX(70deg);animation:slR1 2.6s linear infinite}
#site-loader .sl-ring.r2{color:#a855f7;border-top-color:#a855f7;border-right-color:rgba(168,85,247,.6);transform:rotateY(70deg);animation:slR2 3.4s linear infinite}
#site-loader .sl-ring.r3{color:#f472b6;inset:14px;border-bottom-color:#f472b6;border-left-color:rgba(244,114,182,.6);transform:rotateX(60deg) rotateY(-55deg);animation:slR3 4.2s linear infinite}
#site-loader .sl-core{position:absolute;inset:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  background:radial-gradient(circle at 35% 30%,#a5b4fc 0%,#6366f1 38%,#312e81 75%,#1e1b4b 100%);box-shadow:0 0 30px rgba(99,102,241,.9),0 0 70px rgba(34,211,238,.35),inset 0 0 18px rgba(255,255,255,.25);animation:slBeat 1.8s ease-in-out infinite}
#site-loader .sl-core svg{width:40px;height:40px;overflow:visible}
#site-loader .sl-core path{fill:none;stroke:#fff;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;animation:slDraw 2.4s ease-in-out infinite}
#site-loader .sl-core path.k{stroke-dasharray:12;stroke-dashoffset:12;stroke-width:2.4;animation-name:slDrawK}
#site-loader .sl-sp{position:absolute;width:4px;height:4px;border-radius:50%;background:#e0e7ff;box-shadow:0 0 8px #a5b4fc;opacity:0;animation:slSpark 2.8s ease-in-out infinite}
#site-loader .sl-sp:nth-of-type(1){top:6%;left:18%}#site-loader .sl-sp:nth-of-type(2){top:78%;left:8%;animation-delay:.7s}#site-loader .sl-sp:nth-of-type(3){top:14%;left:86%;animation-delay:1.3s}
#site-loader .sl-sp:nth-of-type(4){top:88%;left:74%;animation-delay:1.9s}#site-loader .sl-sp:nth-of-type(5){top:46%;left:-6%;animation-delay:2.3s}#site-loader .sl-sp:nth-of-type(6){top:40%;left:104%;animation-delay:.4s}
#site-loader .sl-t{font-size:23px;font-weight:900;letter-spacing:.3px;margin-top:6px;background:linear-gradient(90deg,#fff 0%,#c7d2fe 30%,#a5f3fc 50%,#c7d2fe 70%,#fff 100%);background-size:200% auto;-webkit-background-clip:text;background-clip:text;color:transparent;animation:slText 3s linear infinite}
#site-loader .sl-s{font-size:12px;color:#94a3b8;margin-top:-10px}
#site-loader .sl-s i{display:inline-block;width:4px;height:4px;border-radius:50%;background:#94a3b8;margin-right:3px;animation:slDot 1.2s infinite}
#site-loader .sl-s i:nth-child(2){animation-delay:.2s}#site-loader .sl-s i:nth-child(3){animation-delay:.4s}
#site-loader .sl-b{width:200px;height:5px;border-radius:999px;background:rgba(148,163,184,.18);overflow:hidden;position:relative}
#site-loader .sl-b span{position:absolute;inset:0;width:45%;border-radius:999px;background:linear-gradient(90deg,#6366f1,#22d3ee,#a855f7);animation:slLoad 1.3s ease-in-out infinite}
@keyframes slSpin{to{transform:rotate(360deg)}}@keyframes slPulse{50%{transform:scale(1.08);opacity:.8}}
@keyframes slR1{from{transform:rotateX(70deg) rotateZ(0)}to{transform:rotateX(70deg) rotateZ(360deg)}}@keyframes slR2{from{transform:rotateY(70deg) rotateZ(0)}to{transform:rotateY(70deg) rotateZ(-360deg)}}
@keyframes slR3{from{transform:rotateX(60deg) rotateY(-55deg) rotateZ(0)}to{transform:rotateX(60deg) rotateY(-55deg) rotateZ(360deg)}}@keyframes slTilt{50%{transform:rotateZ(18deg) rotateX(10deg)}}
@keyframes slBeat{0%,100%{transform:scale(1)}50%{transform:scale(1.09)}}@keyframes slDraw{0%{stroke-dashoffset:60}45%,75%{stroke-dashoffset:0}100%{stroke-dashoffset:-60}}
@keyframes slDrawK{0%,35%{stroke-dashoffset:12}60%,80%{stroke-dashoffset:0}100%{stroke-dashoffset:-12}}@keyframes slSpark{0%,100%{opacity:0;transform:scale(.4)}50%{opacity:1;transform:scale(1.3)}}@keyframes slText{to{background-position:-200% center}}
@keyframes slDot{0%,80%,100%{opacity:.25}40%{opacity:1}}@keyframes slLoad{0%{transform:translateX(130%)}100%{transform:translateX(-230%)}}
@media (prefers-reduced-motion:reduce){#site-loader *{animation-duration:3s!important}}
html.sl-lock,html.sl-lock body{overflow:hidden}
</style>
<div class="sl-orb"><div class="sl-glow"></div>
  <div class="sl-3d"><div class="sl-ring r1"></div><div class="sl-ring r2"></div><div class="sl-ring r3"></div></div>
  <div class="sl-core"><svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path class="k" d="M9 12l2 2 4-4"/></svg></div>
  <i class="sl-sp"></i><i class="sl-sp"></i><i class="sl-sp"></i><i class="sl-sp"></i><i class="sl-sp"></i><i class="sl-sp"></i>
</div>
<div class="sl-t">بیمه با ما</div>
<div class="sl-s"><?= $h($sub) ?> <i></i><i></i><i></i></div>
<div class="sl-b"><span></span></div>
<script>
(function () {
    var el = document.getElementById('site-loader'), done = false;
    document.documentElement.classList.add('sl-lock');
    function hide() {
        if (done || !el) return; done = true;
        el.classList.add('sl-out');
        document.documentElement.classList.remove('sl-lock');
        setTimeout(function () { if (el && el.parentNode) el.parentNode.removeChild(el); }, 600);
    }
    window.addEventListener('load', function () { setTimeout(hide, 120); });
    // اگر چیزی (مثلاً یک CDNِ بیرونی) دیر کرد، صفحه بیشتر از این پشتِ لودر نمی‌ماند
    document.addEventListener('DOMContentLoaded', function () { setTimeout(hide, 4500); });
    setTimeout(hide, 8000);
    window.SiteLoader = {hide: hide};
})();
</script>
</div>
<?php
}
