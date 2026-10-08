<?php
// فایل: api/_loader.php
// لودرِ شروعِ صفحه («بیمه با ما»): بلافاصله بعد از <body> چاپ می‌شود تا تا وقتی صفحه کامل بارگذاری نشده (فونت، Tailwind، اسکریپت‌ها)
// کاربر به‌جای صفحه‌ی نیمه‌کاره و پرش‌دار یک صفحه‌ی آراسته با لوگو ببیند. با رویدادِ load محو می‌شود؛ حداکثر بعد از ۸ ثانیه هم کنار می‌رود.
// site_loader($pdo, $prefix = '', $sub = '')   $prefix: '' برای صفحه‌های ریشه، '../' برای company-portal
function site_loader($pdo, $prefix = '', $sub = '') {
    $logo = '';
    try {
        $lg = json_decode((string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'brand_logo'")->fetchColumn(), true);
        if (is_array($lg) && !empty($lg['file'])) $logo = $prefix . 'uploads/brand/' . rawurlencode(basename($lg['file'])) . '?v=' . intval($lg['v'] ?? 0);
    } catch (Throwable $e) {}
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $sub = $sub !== '' ? $sub : 'در حالِ آماده‌سازیِ میزِ کار';
    ?>
<div id="site-loader" aria-hidden="true">
<style>
#site-loader{position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:18px;direction:rtl;font-family:Vazir,Tahoma,sans-serif;color:#e2e8f0;
  background:radial-gradient(900px 600px at 85% -10%,rgba(99,102,241,.45),transparent 60%),radial-gradient(800px 600px at -10% 110%,rgba(6,182,212,.35),transparent 60%),linear-gradient(160deg,#070b1c,#111a3a);
  transition:opacity .45s ease,visibility .45s ease}
#site-loader.sl-out{opacity:0;visibility:hidden;pointer-events:none}
#site-loader .sl-em{position:relative;width:118px;height:118px}
#site-loader .sl-r{position:absolute;inset:0;border-radius:50%;border:3px solid transparent;border-top-color:#818cf8;border-right-color:#22d3ee;animation:slSpin 1.6s linear infinite}
#site-loader .sl-r.b{inset:12px;border-top-color:#c084fc;border-left-color:#818cf8;animation-duration:2.4s;animation-direction:reverse}
#site-loader .sl-c{position:absolute;inset:26px;border-radius:22px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(99,102,241,.4),rgba(6,182,212,.3));box-shadow:0 0 36px rgba(99,102,241,.5);animation:slPulse 1.8s ease-in-out infinite}
#site-loader .sl-c img{max-width:46px;max-height:46px;object-fit:contain}
#site-loader .sl-c svg{width:34px;height:34px}
#site-loader .sl-t{font-size:18px;font-weight:900;letter-spacing:.3px;background:linear-gradient(90deg,#fff,#c7d2fe,#a5f3fc);-webkit-background-clip:text;background-clip:text;color:transparent}
#site-loader .sl-s{font-size:12px;color:#94a3b8;margin-top:-10px}
#site-loader .sl-s i{display:inline-block;width:4px;height:4px;border-radius:50%;background:#94a3b8;margin-right:3px;animation:slDot 1.2s infinite}
#site-loader .sl-s i:nth-child(2){animation-delay:.2s}#site-loader .sl-s i:nth-child(3){animation-delay:.4s}
#site-loader .sl-b{width:180px;height:5px;border-radius:999px;background:rgba(148,163,184,.18);overflow:hidden;position:relative}
#site-loader .sl-b span{position:absolute;inset:0;width:45%;border-radius:999px;background:linear-gradient(90deg,#6366f1,#22d3ee,#a855f7);animation:slLoad 1.3s ease-in-out infinite}
@keyframes slSpin{to{transform:rotate(360deg)}}@keyframes slPulse{50%{transform:scale(1.07)}}
@keyframes slDot{0%,80%,100%{opacity:.25}40%{opacity:1}}@keyframes slLoad{0%{transform:translateX(130%)}100%{transform:translateX(-230%)}}
@media (prefers-reduced-motion:reduce){#site-loader *{animation-duration:3s!important}}
html.sl-lock,html.sl-lock body{overflow:hidden}
</style>
<div class="sl-em"><div class="sl-r"></div><div class="sl-r b"></div>
  <div class="sl-c"><?php if ($logo): ?><img src="<?= $h($logo) ?>" alt=""><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg><?php endif; ?></div></div>
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
