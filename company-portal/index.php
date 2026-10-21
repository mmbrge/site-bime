<?php
// فایل: company-portal/index.php
// پنل وب ثبت‌کننده‌ی شرکت درخواست‌کننده: ثبت درخواست بیمه + آپلود تدریجیِ مدارک.
// یک کاربر می‌تواند به چند شرکت دسترسی داشته باشد؛ اگر فقط یکی دارد، پنل قفل روی همان می‌ماند.
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../api/_case_helpers.php';
require __DIR__ . '/../api/_company_helpers.php';

company_portal_session_start();
sec_session_guard($pdo);   // خروجِ خودکار بعد از عدمِ فعالیت / خروجِ اجباری توسطِ مدیر
// ورودِ مستقیم از این صفحه دیگر مجاز نیست - همه باید از درگاهِ یکپارچه‌ی خودمان
// (index.php، گزینه‌ی «همکار شرکت‌ها») وارد شوند تا اشتباهیِ درگاه پیش نیاید
if (empty($_SESSION['company_user_id'])) {
    header('Location: ../index.php');
    exit;
}
// حسابِ غیرفعال یا حذف‌شده با نشستِ قبلی‌اش هم دیگر وارد نمی‌شود
$stCu = $pdo->prepare("SELECT * FROM company_portal_users WHERE id = ?");
$stCu->execute([$_SESSION['company_user_id']]);
$cuRow = $stCu->fetch();
if (!$cuRow || empty($cuRow['is_active']) || !empty($cuRow['is_deleted'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT c.id, c.name, c.allowed_insurers FROM company_portal_user_companies cpuc
                        JOIN companies c ON c.id = cpuc.company_id
                        WHERE cpuc.portal_user_id = ? ORDER BY c.name");
$stmt->execute([$_SESSION['company_user_id']]);
$companies = $stmt->fetchAll();

// اگر دسترسیِ این کاربر به همه‌ی شرکت‌ها برداشته شده باشد، نشست باطل و به همان
// درگاهِ یکپارچه برمی‌گردد (نه فرم ورودِ جداگانه‌ی این صفحه)
if (!$companies) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="../cursor-pref.js?v=1"></script>
<script>window.MAINT_BASE = '../';</script>
<script src="../maint-watch.js?v=1"></script>
<script>window.TABLE_CTX_STANDALONE = true;</script>
<script src="../table-ctx.js?v=1"></script>
<title>پنل ثبت درخواست بیمه | بیمه با ما</title>
<?php require_once __DIR__ . '/../api/_brand.php'; echo brand_favicon_tag($pdo, '../'); ?>
<script src="https://cdn.tailwindcss.com"></script>
<script src="../notif-bell.js?v=3"></script>
<script src="../party-ui.js?v=2"></script>
<script src="../upload-kit.js?v=2"></script>
<script>window.CF_CONFIG = {api: '../api/comfort_actions.php?portal=1', portal: true, bottom: 88, zoomTargets: 'body > .max-w-5xl, body > .modal-overlay > *'};</script>
<script>window.NET_WATCH_PING = '../net-watch.js';</script>
<script src="../net-watch.js?v=5"></script>
<script src="../cursor-fx.js?v=2" defer></script>
<script src="../tools.js?v=4"></script>
<script src="../comfort.js?v=17"></script>
<script>window.ANN_CONFIG = {api: '../api/announce_actions.php?portal=1'};</script>
<script src="../announce.js?v=3"></script>
<script src="zerokm.js?v=3"></script>
<script>window.__SRV = {s: <?php echo (int)round(microtime(true) * 1000); ?>, c: Date.now()};</script>
<script src="../iran-time.js?v=2"></script>
<script src="../jdate-picker.js?v=1" defer></script>
<script>window.IDLE_GUARD = {alive: '../api/alive.php?ctx=company', login: '../index.php?idle=1'};</script>
<script src="../idle-guard.js?v=2"></script>
<link rel="stylesheet" href="../plate.css?v=4">
<script src="../chat-ui.js?v=16"></script>
<script src="../money-input.js?v=1"></script>
<script src="../letters-portal.js?v=2" defer></script>
<script src="../reg-link.js?v=1" defer></script>
<script src="../registry.js?v=4" defer></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    @font-face { font-family: 'Vazir'; src: url('../Font/Vazir-Regular.woff2') format('woff2'); font-weight: normal; font-display: swap; }
    @font-face { font-family: 'Vazir'; src: url('../Font/Vazir-Bold.woff2') format('woff2'); font-weight: bold; font-display: swap; }
    @font-face { font-family: 'Vazir'; src: url('../Font/Vazir-Medium.woff2') format('woff2'); font-weight: 500; font-display: swap; }
    body { font-family: 'Vazir', Tahoma, sans-serif; background: #f1f5f9; color: #334155; margin: 0; }
        button, input, select, textarea { font-family: inherit; }
    .card { background: #fff; border-radius: 1.25rem; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: 1.5px solid #dbe3ee; }
    /* ردیف‌های ریزِ درخواست: کادرِ پررنگ‌تر و منظم */
    .prow { background:#fff; border:2px solid #cbd5e1; border-radius:16px; margin-bottom:10px; overflow:hidden; box-shadow:0 2px 8px rgba(15,23,42,.05); }
    .prow-head { background:linear-gradient(90deg,#f8fafc,#f1f5f9); border-bottom:2px solid #e2e8f0; padding:10px 12px; }
    .prow-body { padding:10px 12px; }
    .prow-docs { border-top:1.5px dashed #cbd5e1; margin-top:10px; padding-top:8px; }
    .prow-doc { display:flex; align-items:center; gap:8px; border:1.5px solid #e2e8f0; border-radius:10px; padding:6px 9px; background:#fff; transition:all .15s; }
    .prow-doc:hover { border-color:#93c5fd; background:#eff6ff; }
    .gdoc { border:1.5px solid #dbe3ee !important; }
    .float-input { position: relative; margin-bottom: 1.25rem; }
    .float-input input, .float-input select, .float-input textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 12px; padding: 12px 14px; outline: none; transition: all .2s; }
    .float-input input:focus, .float-input select:focus, .float-input textarea:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,.1); }
    .float-input label { display:block; font-size: 12px; font-weight: bold; color: #64748b; margin-bottom: 6px; }
    /* مودالِ بسته باید کاملاً از مدارِ رندر بیرون باشد؛ opacity:0 تنها کافی نیست و
       مرورگر همه‌ی مودال‌های تمام‌صفحه را زنده نگه می‌دارد (همان چیزی که پنل ادمین را کُند کرده بود) */
    .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.6); z-index: 999; display:flex; align-items:center; justify-content:center; opacity:0; visibility:hidden; pointer-events:none; transition: opacity .2s, visibility .2s; }
    .modal-overlay.active { opacity:1; visibility:visible; pointer-events:auto; transition: opacity .2s; }

    /* ================= نشانگرِ اختصاصیِ موس (هم‌شکل با پنل خودمان) =================
       فقط روی دستگاهی که موسِ واقعی دارد روشن می‌شود - روی موبایل نه لازم است نه
       درست (چون cursor:none آزاردهنده می‌شود). موقعیت با متغیرهای CSS و transform
       تنظیم می‌شود، نه left/top؛ چون transform فقط composite است و layout نمی‌خواهد.
       کلاسِ حالتِ hover هم روی خودِ نشانگر می‌نشیند نه روی body، تا استایلِ کلِ صفحه
       دوباره محاسبه نشود. */
    .cursor-dot, .cursor-outline { display: none; }
    @media (hover: hover) and (pointer: fine) {
        html:not(.cur-native) * { cursor: none !important; }
        .cursor-dot, .cursor-outline { display: block; }
    }
    .cursor-dot { position: fixed; left: 0; top: 0; width: 8px; height: 8px; background: #3b82f6; border-radius: 50%; pointer-events: none; z-index: 2147483647; transform: translate(var(--cx, -100px), var(--cy, -100px)) translate(-50%, -50%); transition: background .2s; box-shadow: 0 0 10px rgba(59,130,246,.5); will-change: transform; }
    .cursor-outline { position: fixed; left: 0; top: 0; width: 24px; height: 24px; border: 2px solid rgba(59,130,246,.5); border-radius: 50%; pointer-events: none; z-index: 2147483646; transform: translate(var(--ox, -100px), var(--oy, -100px)) translate(-50%, -50%); transition: opacity .2s; will-change: transform; }
    .cursor-dot.is-hover { transform: translate(var(--cx, -100px), var(--cy, -100px)) translate(-50%, -50%) scale(1.8); background: #2563eb; }
    .cursor-outline.is-hover { transform: translate(var(--ox, -100px), var(--oy, -100px)) translate(-50%, -50%) scale(.5); opacity: 0; }
    .modal-content { background:#fff; border-radius: 20px; max-width: 520px; width: 92%; max-height: 88vh; overflow-y:auto; transform: scale(.96); transition:.2s; }
    /* مودالِ جزئیاتِ درخواست پهن‌تر است تا چک‌لیستِ مدارک در یک ردیف جا شود */
    .modal-content.wide { max-width: 1100px; }
    /* چک‌لیستِ هر ردیف: خانه‌ها کنارِ هم پخش می‌شوند، نه زیرِ هم */
    /* پشتیبانِ محلیِ .hidden: نشان‌دادن/پنهان‌کردنِ فیلدها به این کلاس وابسته است و اگر
       CDNِ Tailwind نیاید، بدون این قاعده همه‌ی فیلدها با هم دیده می‌شوند.
       مهم: المان‌هایی که «hidden» را با یک نمایشِ واکنش‌گرا جفت کرده‌اند (مثلاً منوی
       اصلی: hidden lg:flex) از این قاعده بیرون‌اند؛ وگرنه !important جلوی lg:flex را
       می‌گیرد و منو در دسکتاپ اصلاً دیده نمی‌شود. آن‌ها را خودِ Tailwind مدیریت می‌کند. */
    .hidden:not([class~="sm:flex"], [class~="sm:inline-flex"], [class~="sm:block"], [class~="sm:inline-block"], [class~="sm:inline"], [class~="sm:grid"], [class~="sm:inline-grid"], [class~="sm:table"], [class~="sm:table-cell"], [class~="sm:table-row"], [class~="sm:contents"], [class~="md:flex"], [class~="md:inline-flex"], [class~="md:block"], [class~="md:inline-block"], [class~="md:inline"], [class~="md:grid"], [class~="md:inline-grid"], [class~="md:table"], [class~="md:table-cell"], [class~="md:table-row"], [class~="md:contents"], [class~="lg:flex"], [class~="lg:inline-flex"], [class~="lg:block"], [class~="lg:inline-block"], [class~="lg:inline"], [class~="lg:grid"], [class~="lg:inline-grid"], [class~="lg:table"], [class~="lg:table-cell"], [class~="lg:table-row"], [class~="lg:contents"], [class~="xl:flex"], [class~="xl:inline-flex"], [class~="xl:block"], [class~="xl:inline-block"], [class~="xl:inline"], [class~="xl:grid"], [class~="xl:inline-grid"], [class~="xl:table"], [class~="xl:table-cell"], [class~="xl:table-row"], [class~="xl:contents"], [class~="2xl:flex"], [class~="2xl:inline-flex"], [class~="2xl:block"], [class~="2xl:inline-block"], [class~="2xl:inline"], [class~="2xl:grid"], [class~="2xl:inline-grid"], [class~="2xl:table"], [class~="2xl:table-cell"], [class~="2xl:table-row"], [class~="2xl:contents"]) { display: none !important; }
    .checklist-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 6px; align-items: start; }
    .modal-overlay.active .modal-content { transform: scale(1); }
    .status-badge { font-size: 11px; font-weight: bold; padding: 4px 10px; border-radius: 999px; }
    .toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(100px); background:#1e293b; color:#fff; padding: 12px 22px; border-radius: 12px; font-size: 13px; font-weight: bold; z-index: 9999; transition:.3s; opacity:0; }
    .toast.show { transform: translateX(-50%) translateY(0); opacity:1; }
    .toast { display:flex; align-items:center; gap:10px; padding-inline-end:10px; pointer-events:none; }
    .toast.show { pointer-events:auto; }
    .toast-x { border:0; background:rgba(255,255,255,.12); color:#cbd5e1; width:26px; height:26px; border-radius:8px; flex:none; cursor:pointer; }
    .notif-card { position: relative; padding-left: 40px; }
    .notif-card .nc-x { position:absolute; top:10px; left:10px; border:0; background:transparent; color:#94a3b8; width:24px; height:24px; border-radius:8px; cursor:pointer; }
    .notif-card .nc-x:hover { background:#f1f5f9; color:#475569; }

    /* اعلان‌ها: گوشه‌ی پایینِ سمتِ راست، روی هم انباشته، هرکدام با ضربدر یا بعد از ۱۰ ثانیه می‌رود */
    #notif-stack { position: fixed; bottom: 20px; right: 20px; z-index: 9998; display: flex; flex-direction: column; gap: 10px;
                   width: min(390px, calc(100vw - 32px)); pointer-events: none; }
    .notif-card { background: #fff; border: 1px solid #e2e8f0; border-right: 5px solid #3b82f6; border-radius: 18px;
                  padding: 14px 16px; box-shadow: 0 10px 28px rgba(15,23,42,.14); cursor: pointer; pointer-events: auto;
                  opacity: 0; transform: translateX(24px); transition: opacity .3s, transform .3s; }
    .notif-card.show { opacity: 1; transform: translateX(0); }
    /* ===== طراحیِ تازه‌ی پنل ===== */
    .pt-hero { position:relative; overflow:hidden; display:flex; align-items:center; justify-content:space-between; gap:12px; margin:14px 0 14px; padding:18px 18px; border-radius:26px; color:#fff;
               background:linear-gradient(120deg,#0f172a,#1d4ed8 55%,#0891b2); box-shadow:0 24px 44px -30px #1d4ed8; }
    .pt-hero::after { content:''; position:absolute; width:300px; height:300px; border-radius:50%; left:-90px; top:-170px; background:rgba(255,255,255,.09); pointer-events:none; }
    .pt-hero::before { content:''; position:absolute; width:160px; height:160px; border-radius:50%; left:28%; bottom:-110px; background:rgba(34,211,238,.18); pointer-events:none; }
    .pt-out { background:rgba(255,255,255,.14); color:#fff; font-weight:800; font-size:12px; border-radius:12px; padding:8px 12px; border:0; }
    .pt-bell button { color:#fff !important; }
    .pt-tabs { display:flex; gap:6px; background:#fff; border:1.5px solid #dbe3ee; border-radius:20px; padding:5px; margin-bottom:14px; box-shadow:0 8px 24px -20px rgba(15,23,42,.4); }
    .pt-tabs button { flex:1; display:flex; align-items:center; justify-content:center; gap:7px; border:0; background:transparent; border-radius:15px; padding:11px 8px; font-family:inherit; font-weight:800; font-size:13px; color:#475569; transition:.15s; }
    .pt-tabs button.on { background:linear-gradient(120deg,#1d4ed8,#0891b2); color:#fff; box-shadow:0 10px 20px -14px #1d4ed8; }
    .pt-tabs b { font-size:10.5px; min-width:20px; height:20px; padding:0 6px; border-radius:99px; background:rgba(15,23,42,.08); display:inline-flex; align-items:center; justify-content:center; }
    .pt-tabs button.on b { background:rgba(255,255,255,.22); }
    .pt-tabs b.red { background:#ef4444 !important; color:#fff; }
    .pt-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; margin-bottom:14px; }
    .pt-stat { background:#fff; border:1.5px solid #dbe3ee; border-radius:18px; padding:12px 14px; display:flex; align-items:center; gap:10px; }
    .pt-stat .i { width:38px; height:38px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
    .pt-stat b { display:block; font-size:19px; font-weight:900; line-height:1.15; color:#0f172a; } .pt-stat small { font-size:11px; font-weight:700; color:#64748b; }
    .pt-new { background:linear-gradient(120deg,#2563eb,#0891b2); color:#fff; font-weight:800; font-size:13px; border-radius:16px; padding:12px 18px; box-shadow:0 14px 26px -18px #2563eb; display:inline-flex; align-items:center; border:0; }
    .pt-chips { display:flex; gap:6px; overflow-x:auto; padding-bottom:4px; margin-bottom:12px; }
    .pt-chips button { flex-shrink:0; border:1.5px solid #dbe3ee; background:#fff; color:#475569; font-family:inherit; font-weight:800; font-size:11.5px; border-radius:12px; padding:7px 11px; display:inline-flex; gap:6px; align-items:center; }
    .pt-chips button.on { border-color:#2563eb; background:#eff6ff; color:#1d4ed8; }
    .pt-chips button i { font-style:normal; font-size:10px; background:#f1f5f9; border-radius:8px; padding:1px 6px; }
    .pt-req-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .pt-req { background:#fff; border:1.5px solid #dbe3ee; border-radius:20px; padding:14px 15px; cursor:pointer; transition:.15s; position:relative; overflow:hidden; }
    .pt-req:hover { border-color:#93c5fd; box-shadow:0 14px 30px -24px rgba(29,78,216,.6); transform:translateY(-1px); }
    .pt-req::before { content:''; position:absolute; right:0; top:0; bottom:0; width:5px; background:var(--c,#3b82f6); }
    .pt-steps { display:flex; gap:4px; margin-top:10px; }
    .pt-steps i { flex:1; height:6px; border-radius:99px; background:#e2e8f0; }
    .pt-steps i.on { background:var(--c,#3b82f6); }
    .pt-prog { height:6px; border-radius:99px; background:#f1f5f9; overflow:hidden; margin-top:6px; } .pt-prog i { display:block; height:100%; background:linear-gradient(90deg,#10b981,#22c55e); border-radius:99px; }
    @media (max-width: 767px) { .pt-req-grid { grid-template-columns:minmax(0,1fr); } .pt-hero { border-radius:22px; padding:15px; } .pt-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }

/* خانه‌های چک‌لیستِ مدارکِ هر ردیف: دکمه‌ها زیرِ هم و در عرضِ خانه (روی هم نمی‌افتند) */
.checklist-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(158px,1fr));gap:7px;align-items:stretch}
.ck-chip{border-radius:12px;padding:7px 8px;font-size:10px;line-height:1.6;display:flex;flex-direction:column;gap:3px;min-width:0}
.ck-chip .ck-t{font-weight:800;display:flex;align-items:flex-start;gap:4px;word-break:break-word}
.up-stack{display:flex;flex-direction:column;gap:4px;margin-top:auto;padding-top:3px}
.up-row{display:flex;gap:4px;flex-wrap:wrap}
.up-sel,.up-in{width:100%;font-size:10px;border:1px solid #cbd5e1;border-radius:8px;padding:3px 6px;background:#fff;color:#334155}
.up-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;flex:1 1 auto;font-size:10.5px;font-weight:800;color:#fff;padding:5px 10px;border-radius:9px;border:0;cursor:pointer;white-space:nowrap;background:linear-gradient(135deg,#2563eb,#0891b2);box-shadow:0 3px 8px -4px rgba(37,99,235,.7);transition:transform .12s}
.up-btn:hover{transform:translateY(-1px)}
.up-btn.soft{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;box-shadow:none}
</style>
<link rel="stylesheet" href="../ui-scroll.css?v=3">
</head>
<body class="min-h-screen">
<?php require_once __DIR__ . '/../api/_loader.php'; site_loader($pdo, '../', 'در حالِ آماده‌سازیِ پنلِ شرکت'); ?>
<div class="cursor-dot"></div>
<div class="cursor-outline"></div>

<div class="max-w-5xl mx-auto p-4 pb-24">
    <!-- سربرگِ پنل: شرکت، کاربر، اعلان‌ها -->
    <div class="pt-hero">
        <div class="flex items-center gap-3 min-w-0" style="z-index:1">
            <!-- عکسِ پروفایل: با کلیک عوض می‌شود و در گفتگو با «بیمه با ما» دیده می‌شود -->
            <button type="button" id="me-avatar" onclick="changeMyAvatar()" title="عکسِ پروفایل" class="relative hover:scale-105 transition-transform shrink-0"></button>
            <div class="min-w-0">
                <h1 class="font-black text-lg truncate"><?php echo count($companies) === 1 ? htmlspecialchars($companies[0]['name']) : 'پنلِ شرکت‌ها (' . count($companies) . ' شرکت)'; ?></h1>
                <p class="text-xs opacity-80 truncate"><?php require_once __DIR__ . '/../api/_name_honor.php'; echo htmlspecialchars(name_with_honor($_SESSION['company_user_full_name'], $cuRow['honor'] ?? null)); ?> · پنلِ همکارانِ «بیمه با ما»</p>
            </div>
        </div>
        <div class="flex items-center gap-2" style="z-index:1">
            <span id="notif-bell-mount" class="inline-flex pt-bell"></span>
            <button onclick="doLogout()" class="pt-out hover-target">خروج</button>
        </div>
    </div>

    <!-- زبانه‌ها -->
    <div class="pt-tabs" role="tablist">
        <button type="button" data-ptab="requests" class="on" onclick="showPortalTab('requests')"><i class="fas fa-clipboard-list"></i>درخواست‌ها<b id="pt-req-count"></b></button>
        <button type="button" data-ptab="vehicles" onclick="showPortalTab('vehicles')"><i class="fas fa-car-side"></i>خودروها</button>
        <button type="button" data-ptab="letters" onclick="showPortalTab('letters')"><i class="fas fa-envelope-open-text"></i>اتوماسیون نامه‌ها<b id="pt-letters-badge" class="hidden red"></b></button>
    </div>

    <!-- ================= درخواست‌ها ================= -->
    <section id="pt-requests">
        <div id="req-stats" class="pt-stats"></div>
        <div class="flex gap-2 items-center flex-wrap mb-3">
            <button onclick="openNewRequestModal()" class="pt-new hover-target"><span class="ml-1 text-lg leading-none">+</span> ثبت درخواست جدید</button>
            <div class="relative flex-1 min-w-[200px]">
                <input type="search" id="req-search" oninput="debouncedRequestSearch()" placeholder="جستجو: شماره درخواست، پلاک، شماره شاسی، خودرو یا متن..."
                       class="w-full border border-slate-200 bg-white rounded-2xl py-3 pr-10 pl-3 text-xs outline-none focus:border-blue-500">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                     class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
                </svg>
            </div>
        </div>
        <div id="req-chips" class="pt-chips"></div>
        <div class="flex justify-end mb-2"><button type="button" onclick="openBulkPolicyDownload()" class="text-[11px] font-bold bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl px-3 py-2"><i class="fas fa-file-zipper ml-1"></i>دانلودِ گروهیِ بیمه‌نامه‌ها</button></div>
        <div id="requests-list" class="pt-req-grid"></div>
    </section>

    <!-- ================= اتوماسیون نامه‌ها (letters-portal.js) ================= -->
    <section id="pt-letters" class="hidden"><div id="letters-root"></div></section>

    <!-- ================= پرونده‌ی خودروهای شرکت (registry.js) ================= -->
    <section id="pt-vehicles" class="hidden"><div id="vh-root"></div></section>
</div>

<button onclick="openChatModal()" title="گفتگو با پشتیبانی" class="fixed bottom-5 left-5 w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-xl flex items-center justify-center z-50">
    <!-- شمارِ پیام‌های خوانده‌نشده؛ وقتی صفر است پنهان می‌ماند -->
    <span id="chat-unread-badge" class="hidden absolute -top-1 -right-1 min-w-[22px] h-[22px] px-1 rounded-full bg-red-500 text-white text-[11px] font-black flex items-center justify-center ring-2 ring-white"></span>
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
    </svg>
</button>

<!-- مودال چت با بیمه با ما: پیام‌رسانِ مشترک (chat-ui.js) - فایل، پاسخ، ویرایش، حذف و موضوعِ پیام (کدام درخواست) -->
<div id="chat-modal" class="modal-overlay">
    <div class="modal-content p-0 flex flex-col overflow-hidden" style="width: min(820px, 96vw); max-width: none; height: min(86vh, 800px);">
        <div class="px-4 py-3 border-b flex items-center justify-between gap-3">
            <h3 class="font-black text-base">گفتگو با «بیمه با ما»</h3>
            <?php if (count($companies) > 1): ?>
            <select id="chat-company" onchange="mountPortalChat()" class="flex-1 max-w-xs border rounded-lg p-2 text-sm">
                <?php foreach ($companies as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="hidden" id="chat-company" value="<?php echo $companies[0]['id']; ?>">
            <?php endif; ?>
            <button onclick="closeChatModal()" aria-label="بستن" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors shrink-0">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="portal-chat" class="flex-1 min-h-0 p-2 bg-slate-100"></div>
    </div>
</div>

<!-- مودال درخواست جدید -->
<div id="new-request-modal" class="modal-overlay">
    <div class="modal-content p-6" style="max-height: 88vh; overflow-y: auto;">
        <h3 class="font-bold text-lg mb-4">ثبت درخواست جدید</h3>
        <?php if (count($companies) > 1): ?>
        <div class="float-input">
            <label>برای کدام شرکت؟</label>
            <select id="nr-company" onchange="updateInsurerOptions()"><?php foreach ($companies as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?></select>
        </div>
        <?php else: ?>
        <input type="hidden" id="nr-company" value="<?php echo $companies[0]['id']; ?>">
        <?php endif; ?>
        <div class="float-input">
            <label>نوع درخواست</label>
            <select id="nr-kind" onchange="onNrKindChange()">
                <option value="NEW_POLICY">صدور بیمه‌نامه‌ی جدید</option>
                <option value="ENDORSEMENT">الحاقیه (تغییر اطلاعات بیمه‌نامه یا فسخ)</option>
            </select>
            <p id="nr-kind-hint" class="text-[11px] text-slate-400 mt-1.5"></p>
        </div>
        <!-- تعدادِ درخواستی از هر نوع - برای صدورِ جدید ثالث/بدنه، برای الحاقیه تغییر اطلاعات/فسخ -->
        <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <p class="text-xs font-bold text-slate-600 mb-2">تعداد درخواستی از هر نوع</p>
            <div id="nr-counts-new" class="grid grid-cols-2 gap-2">
                <label class="text-[11px] font-bold text-slate-600">ثالث
                    <input type="text" inputmode="numeric" id="nr-cnt-THIRDPARTY" class="mt-1 w-full border rounded-lg p-2 text-sm text-center bg-white" placeholder="۰"></label>
                <label class="text-[11px] font-bold text-slate-600">بدنه
                    <input type="text" inputmode="numeric" id="nr-cnt-BODY" class="mt-1 w-full border rounded-lg p-2 text-sm text-center bg-white" placeholder="۰"></label>
            </div>
            <div id="nr-counts-endorse" class="grid grid-cols-2 gap-2 hidden">
                <label class="text-[11px] font-bold text-violet-700">تغییر اطلاعات بیمه‌نامه
                    <input type="text" inputmode="numeric" id="nr-cnt-ENDORSEMENT" class="mt-1 w-full border rounded-lg p-2 text-sm text-center bg-white" placeholder="۰"></label>
                <label class="text-[11px] font-bold text-rose-700">فسخ بیمه‌نامه
                    <input type="text" inputmode="numeric" id="nr-cnt-CANCELLATION" class="mt-1 w-full border rounded-lg p-2 text-sm text-center bg-white" placeholder="۰"></label>
            </div>
        </div>
        <div class="float-input">
            <label>بیمه‌گر</label>
            <select id="nr-insurer"></select>
        </div>
        <div class="float-input">
            <label>توضیح درخواست</label>
            <textarea id="nr-text" rows="4" placeholder="مثلاً: بیمه شخص ثالث برای ۳ دستگاه خودرو..."></textarea>
        </div>
        <div class="float-input">
            <label>فایل نامه (اختیاری - PDF یا عکس)</label>
            <input type="file" id="nr-letter" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif">
        </div>

        <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 mb-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div><b class="text-[12px] text-emerald-800">ورودِ خودروها از اکسل</b><p class="text-[10.5px] text-slate-500">فهرستِ خودروها را در اکسل بدهید؛ ردیف‌ها همین‌جا پر می‌شوند و می‌توانید قبل از ثبت ویرایششان کنید.</p></div>
                <div class="flex gap-2"><a href="../api/company_portal_actions.php?action=excel_sample" class="text-[11px] font-bold bg-white border border-emerald-300 text-emerald-700 rounded-lg px-3 py-1.5">دانلودِ نمونه‌ی اکسل</a>
                    <label class="text-[11px] font-bold bg-emerald-600 text-white rounded-lg px-3 py-1.5 cursor-pointer">انتخابِ فایلِ اکسل<input type="file" id="nr-excel" accept=".xlsx,.csv" class="hidden" onchange="importPlatesExcel(this)"></label></div>
            </div>
            <div id="nr-excel-msg" class="text-[11px] mt-1"></div>
        </div>
        <div class="rounded-xl border border-indigo-200 bg-indigo-50/60 p-3 mb-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div><b class="text-[12px] text-indigo-800">🚚 خودروهای صفر کیلومتر با فاکتور فروش</b><p class="text-[10.5px] text-slate-500">اکسلِ خودروها و PDFِ فاکتورهای فروش (چند فاکتور پشتِ سرِ هم) را بدهید؛ هر فاکتور جدا خوانده و با اکسل تطبیق داده می‌شود و برای هر خودرو یک ردیف با فاکتورش ساخته می‌شود.</p></div>
                <button type="button" onclick="ZeroKm.open()" class="text-[11px] font-bold bg-indigo-600 text-white rounded-lg px-3 py-1.5">شروع</button>
            </div>
            <div id="zk-summary"></div>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-3 mb-3">
            <b class="text-[12px] text-amber-800">مالک / بیمه‌گذار / ذینفع (برای همه‌ی خودروها)</b>
            <p class="text-[10.5px] text-slate-500 mb-2">خودرو به نامِ خودِ شرکت است، یا به نامِ شخص/شرکتِ دیگری که «ذینفع» می‌شود و شرکت بیمه‌گذار است، یا به نامِ دیگری که خودش بیمه‌گذار می‌شود. برای خودروی متفاوت، در همان ردیف «مالک/بیمه‌گذارِ این خودرو فرق دارد» را بزنید.</p>
            <div id="nr-party"></div>
        </div>
        <label class="text-xs font-bold text-slate-500 block mb-2">پلاک‌های این درخواست (اختیاری - می‌توانید چند پلاک اضافه کنید)</label>
        <div id="nr-plates-list" class="space-y-2 mb-2"></div>
        <button type="button" onclick="addPlateRow('nr-plates-list')" class="text-xs font-bold text-blue-600 mb-4"><i class="fas fa-plus ml-1"></i>افزودن پلاک</button>

        <div class="flex gap-3 mt-2">
            <button onclick="closeModal('new-request-modal')" class="flex-1 bg-slate-100 hover:bg-slate-200 font-bold py-2.5 rounded-xl">انصراف</button>
            <button onclick="submitNewRequest()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl">ثبت</button>
        </div>
    </div>
</div>

<!-- مودال جزئیات درخواست -->
<div id="request-detail-modal" class="modal-overlay">
    <div class="modal-content wide p-6" style="max-height: 88vh; overflow-y: auto;">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-black text-lg flex items-center gap-2">جزئیات درخواست</h3>
            <button onclick="closeModal('request-detail-modal')" aria-label="بستن" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors shrink-0">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="request-detail-body"></div>
        <div id="du-off-note" class="hidden bg-amber-50 border border-amber-100 rounded-2xl mt-4 p-4 text-xs font-bold text-amber-800"><i class="fas fa-circle-info ml-1"></i>مدارکِ هر خودرو را از چک‌لیستِ همان خودرو (بالا، در ردیف‌ها) بارگذاری کنید؛ ردیف‌ها را کارشناسِ «بیمه با ما» می‌سازد.</div>
        <div id="du-box" class="bg-slate-50 border border-slate-100 rounded-2xl mt-4 p-4">
            <label class="text-xs font-bold text-slate-600 flex items-center gap-1.5 mb-1"><i class="fas fa-cloud-arrow-up text-blue-400"></i>افزودن مدارک جدید به این درخواست</label>
            <p class="text-[11px] text-slate-400 mb-3">چند فایل، فایلِ زیپ یا یک پوشه را انتخاب کنید: زیپ و پوشه کامل باز می‌شوند، PDFهای چندصفحه‌ای صفحه‌به‌صفحه جدا و همه از ۱ شماره‌گذاری می‌شوند و برای بررسی فرستاده می‌شوند. اگر نوعِ فایلی را می‌دانید انتخاب کنید؛ وگرنه «سایر مدارک» بماند.</p>
            <div class="grid grid-cols-2 gap-2 mb-2">
                <label class="cursor-pointer text-center text-xs font-bold bg-white border-2 border-dashed border-blue-200 hover:border-blue-400 text-blue-700 rounded-xl py-3"><i class="fas fa-file-circle-plus ml-1"></i>انتخابِ فایل‌ها / زیپ
                    <input type="file" id="doc-upload-input" multiple data-uk-bulk accept=".pdf,.jpg,.jpeg,.png,.webp,.zip,.heic,.heif" onchange="duAddFiles(this)" class="hidden"></label>
                <label class="cursor-pointer text-center text-xs font-bold bg-white border-2 border-dashed border-violet-200 hover:border-violet-400 text-violet-700 rounded-xl py-3"><i class="fas fa-folder-open ml-1"></i>انتخابِ پوشه
                    <input type="file" id="doc-upload-folder" webkitdirectory directory multiple data-uk-bulk onchange="duAddFiles(this)" class="hidden"></label>
            </div>
            <div id="doc-upload-rows" class="space-y-2 mb-2"></div>
            <button onclick="uploadDocuments()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors">
                <i class="fas fa-upload ml-1"></i> آپلود مدارک
            </button>
        </div>
    </div>
</div>

<!-- دیالوگ تایید -->
<div id="portal-confirm-modal" class="modal-overlay">
    <div class="modal-content p-6 text-center" style="max-width:360px">
        <h3 id="portal-confirm-title" class="text-base font-black text-slate-800 mb-2"></h3>
        <p id="portal-confirm-message" class="text-xs text-slate-500 mb-6"></p>
        <div class="flex gap-3">
            <button type="button" id="portal-confirm-yes" class="flex-1 bg-red-500 hover:bg-red-600 text-white font-bold py-2.5 rounded-xl text-xs transition-colors">بله، مطمئنم</button>
            <button type="button" id="portal-confirm-no" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl text-xs transition-colors">انصراف</button>
        </div>
    </div>
</div>

<!-- دیالوگ ورودی متن -->
<div id="portal-prompt-modal" class="modal-overlay">
    <div class="modal-content p-6" style="max-width:360px">
        <h3 id="portal-prompt-title" class="text-base font-black text-slate-800 mb-4"></h3>
        <textarea id="portal-prompt-input" rows="3" class="w-full border rounded-xl p-3 text-sm mb-4"></textarea>
        <div class="flex gap-3">
            <button type="button" id="portal-prompt-save" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-xs transition-colors">ذخیره</button>
            <button type="button" id="portal-prompt-cancel" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl text-xs transition-colors">انصراف</button>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>
<div id="notif-stack" aria-live="polite"></div>

<script>
let activeRequestId = null;
const STATUS_FA = {NEW:'جدید', DOCS_PENDING:'در انتظار مدارک', DOCS_REVIEW:'در حال بررسی', READY_FOR_ISSUE:'آماده‌ی صدور', ISSUED:'صادر شده', CANCELLED:'لغو شده'};
const STATUS_COLOR = {NEW:'bg-blue-100 text-blue-700', DOCS_PENDING:'bg-amber-100 text-amber-700', DOCS_REVIEW:'bg-purple-100 text-purple-700', READY_FOR_ISSUE:'bg-cyan-100 text-cyan-700', ISSUED:'bg-emerald-100 text-emerald-700', CANCELLED:'bg-red-100 text-red-700'};

// همه‌ی اعلان‌ها: دکمه‌ی ضربدر + بسته‌شدنِ خودکار بعد از ۱۰ ثانیه
function showToast(msg) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.innerHTML = '';
    const s = document.createElement('span'); s.textContent = msg;
    const x = document.createElement('button'); x.type = 'button'; x.className = 'toast-x'; x.setAttribute('aria-label', 'بستن'); x.innerHTML = '<i class="fas fa-xmark"></i>';
    x.onclick = () => { clearTimeout(t._h); t.classList.remove('show'); };
    t.append(s, x); t.classList.add('show');
    clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 10000);
}

// ---- ارقام فارسی: همه‌ی عددهای نمایشیِ پنل باید فارسی باشند ----
const faNum = v => (v === null || v === undefined || v === '') ? '' : String(v).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
const faMoney = v => v ? faNum(Number(v).toLocaleString('en-US')) + ' ریال' : '—';

// ---- جستجو در درخواست‌های خودِ شرکت ----
let reqSearchTimer = null;
function debouncedRequestSearch() {
    clearTimeout(reqSearchTimer);
    reqSearchTimer = setTimeout(loadRequests, 300);
}

// ---- اعلان‌ها: هر پیام تازه از ما یا بیمه‌نامه‌ی تازه صادرشده، گوشه‌ی پایین
//      نشان داده می‌شود و بعد از ۵ ثانیه خودش می‌رود ----
let notifSince = null;
// زنگوله‌ی کنارِ دکمه‌ی خروج: اعلان‌هایی که گوشه‌ی صفحه می‌آیند، آن‌جا هم می‌مانند
let notifAfterId = null;   // آخرین اعلانی که دیده شده (حالتِ ماندگار)
async function notifFetch(extra) {
    const res = await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(Object.assign({action: 'notifications_feed', since: notifSince, after_id: notifAfterId}, extra || {}))});
    return res.json();
}
function applyNotifResponse(data) {
    if (!data || !data.ok) return [];
    setChatUnread(data.unread_chat);
    if (data.persisted) {
        if (window.NotifBell) NotifBell.setServerItems(data.items || []);
        const fresh = notifAfterId === null ? [] : (data.new || []);
        notifAfterId = data.max_id;
        return fresh;
    }
    notifSince = data.now;
    return data.events || [];
}
if (window.NotifBell) NotifBell.init({
    mount: document.getElementById('notif-bell-mount'),
    storageKey: 'notif:portal:<?php echo intval($_SESSION['company_user_id']); ?>',
    onOpenItem: it => { if (it.type === 'chat') openChatModal(); else if (it.type === 'letter') showPortalTab('letters'); },
    onServerAction: async (act, id) => {
        const data = await notifFetch({notif_action: act, id});
        applyNotifResponse(data).forEach(e => pushNotification(e.title, e.body, e.type, e.id));
        return data && data.persisted ? data.items : null;
    },
});
function pushNotification(title, body, type, id) {
    // پیامِ گفتگویی که همین الان باز است: اعلان نمی‌خواهد و خوانده‌شده حساب می‌شود
    if (type === 'chat' && window.ChatUI && ChatUI.isViewing && ChatUI.isViewing()) {
        if (id) notifFetch({notif_action: 'read', id}).then(applyNotifResponse).catch(() => {});
        return;
    }
    if (type === 'letter' && window.PortalLetters) PortalLetters.refresh();
    if (window.NotifBell) NotifBell.add({title, body, type});
    const box = document.getElementById('notif-stack');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'notif-card';
    el.innerHTML = `<button type="button" class="nc-x" aria-label="بستن"><i class="fas fa-xmark"></i></button><p class="font-bold text-[13.5px] mb-0.5">${title}</p>
                    <p class="text-[12.5px] text-slate-500 leading-relaxed">${body || ''}</p>`;
    const close = () => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); };
    el.onclick = close;
    box.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(close, 10000);
}

async function pollNotifications() {
    try {
        const data = await notifFetch();
        if (!data.ok) return;
        const events = applyNotifResponse(data);
        events.forEach(e => pushNotification(e.title, e.body, e.type, e.id));
        // اگر پیام تازه‌ای آمد و مودال چت باز است، همان‌جا هم تازه شود
        if (events.some(e => e.type === 'chat')
            && document.getElementById('chat-modal').classList.contains('active') && portalChat) portalChat.poll();
    } catch (e) { /* قطعیِ لحظه‌ای نباید چیزی را خراب کند */ }
}
// فقط شمارِ خوانده‌نشده‌ها (بدون رویداد) - since خالی یعنی «رویداد نفرست»
async function refreshChatUnread() {
    try {
        const res = await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'notifications_feed', since: ''})});
        const data = await res.json();
        if (data.ok) setChatUnread(data.unread_chat);
    } catch (e) {}
}
function setChatUnread(n) {
    const badge = document.getElementById('chat-unread-badge');
    if (!badge) return;
    n = Number(n) || 0;
    badge.textContent = n > 99 ? '+۹۹' : faNum(n);
    badge.classList.toggle('hidden', n === 0);
}
pollNotifications();
setInterval(pollNotifications, 15000);
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function openNewRequestModal() {
    document.getElementById('nr-plates-list').innerHTML = '';
    if (window.PartyUI) document.getElementById('nr-party').innerHTML = PartyUI.editor({mode: 'COMPANY'});
    if (window.ZeroKm) ZeroKm.clear();
    document.getElementById('nr-kind').value = 'NEW_POLICY';
    onNrKindChange();
    updateInsurerOptions();
    openModal('new-request-modal');
}

async function doLogout() {
    await fetch('../api/company_portal_auth.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'logout'})});
    location.reload();
}

// ---- زبانه‌های پنل: درخواست‌ها | خودروها | اتوماسیون نامه‌ها ----
function showPortalTab(t) {
    document.querySelectorAll('[data-ptab]').forEach(b => b.classList.toggle('on', b.dataset.ptab === t));
    document.getElementById('pt-requests').classList.toggle('hidden', t !== 'requests');
    document.getElementById('pt-letters').classList.toggle('hidden', t !== 'letters');
    if (t === 'letters' && window.PortalLetters) PortalLetters.open(document.getElementById('letters-root'));
    document.getElementById('pt-vehicles').classList.toggle('hidden', t !== 'vehicles');
    if (t === 'vehicles' && window.Vehicles) Vehicles.init(document.getElementById('vh-root'), {portal: true, api: '../api/vehicle_actions.php?portal=1'});
    try { sessionStorage.setItem('pt-tab', t); } catch (e) {}
}
const REQ_STEPS = ['NEW', 'DOCS_PENDING', 'DOCS_REVIEW', 'READY_FOR_ISSUE', 'ISSUED'];
const REQ_HEX = {NEW: '#3b82f6', DOCS_PENDING: '#f59e0b', DOCS_REVIEW: '#8b5cf6', READY_FOR_ISSUE: '#06b6d4', ISSUED: '#10b981', CANCELLED: '#ef4444'};
const REQ_ICON = {NEW: '🆕', DOCS_PENDING: '📎', DOCS_REVIEW: '🔎', READY_FOR_ISSUE: '🖨️', ISSUED: '✅', CANCELLED: '⛔'};
let reqAll = [], reqFilter = '';
async function loadRequests() {
    const q = (document.getElementById('req-search')?.value || '').trim();
    const res = await fetch('../api/company_portal_actions.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'my_requests', q})});
    const data = await res.json();
    reqAll = data.ok ? data.requests : [];
    // کارت‌های آمار و تراشه‌های وضعیت (با همان وضعیت‌های قبلی)
    const cnt = {}; reqAll.forEach(r => { cnt[r.status] = (cnt[r.status] || 0) + 1; });
    const rows = reqAll.reduce((a, r) => a + r.body_count + r.third_count, 0), issued = reqAll.reduce((a, r) => a + r.body_issued + r.third_issued, 0);
    const stat = (ic, bg, v, l) => `<div class="pt-stat"><span class="i" style="background:${bg}">${ic}</span><span><b>${faNum(v)}</b><small>${l}</small></span></div>`;
    document.getElementById('req-stats').innerHTML = q ? '' : stat('📋', '#eff6ff', reqAll.length, 'کلِ درخواست‌ها')
        + stat('⏳', '#fffbeb', (cnt.NEW || 0) + (cnt.DOCS_PENDING || 0) + (cnt.DOCS_REVIEW || 0) + (cnt.READY_FOR_ISSUE || 0), 'در جریان')
        + stat('📎', '#fef3c7', cnt.DOCS_PENDING || 0, 'منتظرِ مدارکِ شما') + stat('✅', '#ecfdf5', issued, 'بیمه‌نامه‌ی صادره') + stat('🚗', '#f0f9ff', rows, 'ردیفِ خودرو');
    document.getElementById('pt-req-count').textContent = faNum(reqAll.length);
    document.getElementById('req-chips').innerHTML = [['', 'همه', reqAll.length]].concat(Object.keys(STATUS_FA).filter(k => cnt[k]).map(k => [k, STATUS_FA[k], cnt[k]]))
        .map(([k, l, n]) => `<button type="button" class="${reqFilter === k ? 'on' : ''}" onclick="reqFilter='${k}';renderRequests()">${k ? REQ_ICON[k] + ' ' : ''}${l}<i>${faNum(n)}</i></button>`).join('');
    renderRequests(q);
}
// دانلودِ گروهی: چند درخواست (یا همه) => یک ZIP؛ هر درخواست یک پوشه
function openBulkPolicyDownload() {
    const list = (reqAll || []).filter(r => (r.body_issued || 0) + (r.third_issued || 0) > 0);
    let m = document.getElementById('bulkdl-modal');
    if (!m) { m = document.createElement('div'); m.id = 'bulkdl-modal'; m.className = 'fixed inset-0 bg-slate-900/60 flex items-center justify-center p-3'; m.style.zIndex = '100050'; document.body.appendChild(m); }
    m.innerHTML = `<div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[88vh] flex flex-col">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between"><h3 class="font-black text-sm"><i class="fas fa-file-zipper ml-1 text-emerald-600"></i>دانلودِ گروهیِ بیمه‌نامه‌های صادرشده</h3>
            <button type="button" onclick="document.getElementById('bulkdl-modal').remove()" class="text-slate-400 hover:text-slate-700 text-lg">✕</button></div>
        <div class="p-4 overflow-y-auto flex-1">${list.length ? `<label class="flex items-center gap-2 text-xs font-bold mb-2"><input type="checkbox" onchange="document.querySelectorAll('#bulkdl-modal .bd-cb').forEach(c => c.checked = this.checked)" checked> همه</label>`
            + list.map(r => `<label class="flex items-center gap-2 text-xs border border-slate-100 rounded-lg p-2 mb-1.5"><input type="checkbox" class="bd-cb" value="${r.id}" checked>
                <span class="flex-1">${r.ref_code ? 'شناسه‌ی ' + faNum(r.ref_code) : 'درخواست #' + faNum(r.id)}${r.company_name && COMPANIES_DATA.length > 1 ? ' · ' + r.company_name : ''}</span>
                <span class="text-[10px] font-bold text-emerald-700">${faNum((r.body_issued || 0) + (r.third_issued || 0))} بیمه‌نامه</span></label>`).join('')
            : '<p class="text-center text-xs text-slate-400 py-8">هنوز بیمه‌نامه‌ی صادرشده‌ای نیست.</p>'}</div>
        ${list.length ? `<div class="p-3 border-t border-slate-100"><button type="button" onclick="bulkPolicyDownloadGo()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl text-xs"><i class="fas fa-download ml-1"></i>دانلودِ ZIP</button></div>` : ''}</div>`;
}
function bulkPolicyDownloadGo() {
    const ids = [...document.querySelectorAll('#bulkdl-modal .bd-cb:checked')].map(c => c.value);
    if (!ids.length) { showToast('حداقل یک درخواست را انتخاب کنید.'); return; }
    const all = ids.length === document.querySelectorAll('#bulkdl-modal .bd-cb').length;
    window.location.href = '../api/company_portal_actions.php?action=download_issued_multi&ids=' + (all ? 'all' : ids.join(','));
    document.getElementById('bulkdl-modal').remove();
}
function renderRequests(q) {
    q = q === undefined ? (document.getElementById('req-search')?.value || '').trim() : q;
    document.querySelectorAll('#req-chips button').forEach((b, i) => b.classList.toggle('on', (b.getAttribute('onclick') || '').indexOf(`reqFilter='${reqFilter}'`) === 0));
    const box = document.getElementById('requests-list');
    const list = reqAll.filter(r => !reqFilter || r.status === reqFilter);
    if (!list.length) {
        box.innerHTML = `<div class="card p-10 text-center text-xs text-slate-400" style="grid-column:1/-1"><div class="text-3xl mb-2">🗂️</div>${q ? 'برای این جستجو درخواستی پیدا نشد.' : (reqFilter ? 'درخواستی با این وضعیت نیست.' : 'هنوز درخواستی ثبت نکرده‌اید. با «ثبت درخواست جدید» شروع کنید.')}</div>`;
        return;
    }
    box.innerHTML = list.map(r => {
        const c = REQ_HEX[r.status] || '#3b82f6', step = REQ_STEPS.indexOf(r.status), total = r.body_count + r.third_count, iss = r.body_issued + r.third_issued;
        return `<div class="pt-req" style="--c:${c}" onclick="openRequestDetail(${r.id})">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0"><div class="font-black text-[13.5px]">${r.ref_code ? 'شناسه‌ی ' + faNum(r.ref_code) : 'درخواست #' + faNum(r.id)}</div>
                    ${r.company_name && COMPANIES_DATA.length > 1 ? `<div class="text-[11px] text-slate-500 truncate">${r.company_name}</div>` : ''}</div>
                <span class="flex items-center gap-1 flex-wrap justify-end">
                    ${r.request_kind && r.request_kind !== 'NEW_POLICY' ? `<span class="status-badge ${r.request_kind === 'ENDORSEMENT' ? 'bg-violet-50 text-violet-600' : 'bg-rose-50 text-rose-600'}">${r.request_kind_fa}</span>` : ''}
                    <span class="status-badge ${STATUS_COLOR[r.status] || ''}">${REQ_ICON[r.status] || ''} ${STATUS_FA[r.status] || r.status}</span></span>
            </div>
            <p class="text-xs text-slate-500 line-clamp-2 mt-1.5 leading-6">${r.request_text ? r.request_text : '(بدون توضیح متنی)'}</p>
            ${r.requested_counts_fa ? `<p class="text-[11px] font-bold text-indigo-600 mt-1">درخواستی: ${r.requested_counts_fa}</p>` : ''}
            <div class="flex flex-wrap gap-1 mt-2">
                ${r.body_count ? `<span class="text-[10px] font-bold bg-cyan-50 text-cyan-700 rounded-lg px-2 py-0.5">بدنه: ${faNum(r.body_issued)} از ${faNum(r.body_count)} صادر شده</span>` : ''}
                ${r.third_count ? `<span class="text-[10px] font-bold bg-blue-50 text-blue-700 rounded-lg px-2 py-0.5">ثالث: ${faNum(r.third_issued)} از ${faNum(r.third_count)} صادر شده</span>` : ''}
            </div>
            ${total ? `<div class="pt-prog" title="پیشرفتِ صدور"><i style="width:${Math.round(iss * 100 / total)}%"></i></div>` : ''}
            ${r.status !== 'CANCELLED' ? `<div class="pt-steps" title="مرحله‌ی درخواست">${REQ_STEPS.map((s, i) => `<i class="${i <= step ? 'on' : ''}"></i>`).join('')}</div>` : ''}
            <p class="text-[10px] text-slate-400 mt-2">${r.insurer === 'IRAN' ? 'بیمه ایران' : 'بیمه پاسارگاد'} · ثبت: ${faNum(r.created_at_jalali)} · آخرین تغییر: ${faNum(r.updated_at_jalali)}</p>
        </div>`;
    }).join('');
}

const COMPANIES_DATA = <?php echo json_encode($companies, JSON_UNESCAPED_UNICODE); ?>;
const INSURER_FA = {PASARGAD: 'پاسارگاد', IRAN: 'ایران'};
let currentRequestPlates = [];

function updateInsurerOptions() {
    const companyId = Number(document.getElementById('nr-company').value);
    const company = COMPANIES_DATA.find(c => String(c.id) === String(companyId)) || COMPANIES_DATA[0];
    const allowed = (company && company.allowed_insurers) || 'BOTH';
    const options = allowed === 'BOTH' ? ['PASARGAD', 'IRAN'] : [allowed];
    const sel = document.getElementById('nr-insurer');
    sel.innerHTML = options.map(o => `<option value="${o}">${INSURER_FA[o]}</option>`).join('');
}
updateInsurerOptions();

// پلاک به ترتیب نمایشِ خواسته‌شده: [۲رقم] [حرف] [۳رقم] [ایران + ۲رقم آخر]
function addPlateRow(containerId) {
    const container = document.getElementById(containerId);
    const row = document.createElement('div');
    row.className = 'plate-row border rounded-lg p-2 mb-1';
    row.innerHTML = `
        <div class="flex items-center gap-2">
            <div class="plate-mt mt-pin hidden-pk flex-1" dir="ltr" style="height:58px"><span class="ip-flag"></span><span class="mt-rows">
                <input class="plate-mcity" maxlength="3" inputmode="numeric" placeholder="کد شهر ۵۸۳" oninput="plateMotoInput(this)">
                <input class="plate-mnum" maxlength="5" inputmode="numeric" placeholder="۷۶۲۲۴" oninput="plateMotoInput(this)"></span></div>
            <div class="plate-car ir-pin sm flex-1" dir="ltr">
                <span class="ip-flag"></span>
                <input class="plate-p4 ip-cell" maxlength="2" inputmode="numeric" placeholder="۱۲">
                <input class="plate-letter ip-cell ip-letter" maxlength="3" placeholder="الف">
                <input class="plate-p2 ip-cell ip-wide" maxlength="3" inputmode="numeric" placeholder="۳۴۵">
                <span class="ip-ir"><small>ایران</small><input class="plate-p1" maxlength="2" inputmode="numeric" placeholder="۶۷"></span>
            </div>
            <button type="button" onclick="this.closest('.plate-row').remove()" aria-label="حذف این پلاک" title="حذف این پلاک" class="text-red-400 hover:text-red-600 shrink-0">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
            </button>
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1.5">
            <label class="flex items-center gap-1.5 text-[11px] font-bold text-blue-700 cursor-pointer">
                <input type="checkbox" class="plate-moto" onchange="togglePlateNoPlate(this)"> 🏍 موتورسیکلت
            </label>
            <label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-500 cursor-pointer">
                <input type="checkbox" class="plate-noplate" onchange="togglePlateNoPlate(this)"> پلاک ندارد (لیفتراک / ماشین‌آلات)
            </label>
            <label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-500 cursor-pointer">
                <input type="checkbox" class="plate-isnew" onchange="togglePlateNoPlate(this)"> صفر کیلومتر
            </label>
        </div>
        <div class="plate-chassis-box hidden grid grid-cols-2 gap-1.5 mt-1.5">
            <input class="plate-chassis border rounded-lg p-2 text-xs" placeholder="شماره شاسی (VIN)" dir="ltr">
            <input class="plate-engine border rounded-lg p-2 text-xs" placeholder="شماره موتور" dir="ltr">
        </div>
        <div class="grid grid-cols-2 gap-1.5 mt-1.5">
            <input class="plate-carname border rounded-lg p-2 text-xs" placeholder="نام خودرو (مثلاً پژو ۲۰۶)">
            <input class="plate-cartype border rounded-lg p-2 text-xs" placeholder="تیپ (مثلاً تیپ ۵)">
        </div>
        <input class="plate-expiry border rounded-lg p-2 text-xs w-full mt-1.5" placeholder="تاریخ انقضای بیمه‌نامه‌ی قبلی (مثلاً ۱۴۰۵/۰۹/۱۵)" dir="ltr">
        <select class="plate-instype text-xs border rounded-lg p-1.5 w-full mt-1.5" onchange="togglePlateValueBox(this)">
            <option value="THIRDPARTY">بیمه‌ی درخواستی: ثالث</option>
            <option value="BODY">بیمه‌ی درخواستی: بدنه</option>
            <option value="BOTH">بیمه‌ی درخواستی: ثالث و بدنه (هردو)</option>
        </select>
        <div class="plate-value-box grid grid-cols-2 gap-1.5 mt-1.5">
            <input class="plate-carvalue money-input border rounded-lg p-2 text-xs hidden" placeholder="ارزش خودرو (ریال)" inputmode="numeric" dir="ltr">
            <input class="plate-liability money-input border rounded-lg p-2 text-xs" placeholder="سقف تعهد مالی (ریال)" inputmode="numeric" dir="ltr">
        </div>
        <select class="plate-endorsetype border rounded-lg p-2 text-xs w-full mt-1.5 hidden" onchange="applyKindToPlateRow(this.closest('.plate-row'))">
            <option value="ENDORSEMENT">نوع الحاقیه‌ی این خودرو: تغییر اطلاعات بیمه‌نامه</option>
            <option value="CANCELLATION">نوع الحاقیه‌ی این خودرو: فسخ بیمه‌نامه</option>
        </select>
        <input class="plate-refpolicy border rounded-lg p-2 text-xs w-full mt-1.5" placeholder="شماره بیمه‌نامه (اختیاری)" dir="ltr">
        <input class="plate-endorse border rounded-lg p-2 text-xs w-full mt-1.5 hidden" placeholder="چه تغییری می‌خواهید؟ (خواسته‌ی الحاقیه)">
        ${window.PartyUI ? `<label class="flex items-center gap-1.5 text-[11px] font-bold text-amber-800 cursor-pointer mt-1.5">
            <input type="checkbox" class="plate-party-on" onchange="this.closest('.plate-row').querySelector('.plate-party').classList.toggle('hidden', !this.checked)"> مالک/بیمه‌گذارِ این خودرو فرق دارد</label>
        <div class="plate-party hidden mt-1">${PartyUI.editor({}, {allowInherit: true})}</div>` : ''}
        <select class="plate-cancelreason border rounded-lg p-2 text-xs w-full mt-1.5 hidden">
            <option value="">دلیل فسخ را انتخاب کنید</option>
            <?php foreach (company_cancellation_reasons() as $r): ?>
            <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option>
            <?php endforeach; ?>
        </select>
    `;
    container.appendChild(row);
    togglePlateValueBox(row.querySelector('.plate-instype'));
    applyKindToPlateRow(row);
}

// بسته به نوعِ درخواست، فقط فیلدهای مربوط به همان نوع در هر ردیف دیده می‌شوند
function applyKindToPlateRow(row) {
    const k = document.getElementById('nr-kind')?.value || 'NEW_POLICY';
    const isEndorse = k !== 'NEW_POLICY';
    // در الحاقیه، هر خودرو یا «تغییر اطلاعات» است (خواسته‌اش نوشته می‌شود) یا «فسخ» (دلیلش انتخاب می‌شود)
    const sub = row.querySelector('.plate-endorsetype')?.value || 'ENDORSEMENT';
    row.querySelector('.plate-endorsetype')?.classList.toggle('hidden', !isEndorse);
    row.querySelector('.plate-endorse').classList.toggle('hidden', !(isEndorse && sub === 'ENDORSEMENT'));
    row.querySelector('.plate-cancelreason').classList.toggle('hidden', !(isEndorse && sub === 'CANCELLATION'));
    row.querySelector('.plate-value-box').classList.toggle('hidden', isEndorse);
}

function onNrKindChange() {
    const k = document.getElementById('nr-kind').value;
    document.getElementById('nr-kind-hint').textContent = k === 'ENDORSEMENT'
        ? 'تعدادِ هر نوع الحاقیه را بنویسید. برای هر خودرو هم مشخص کنید تغییر اطلاعات است یا فسخ، با شماره‌ی بیمه‌نامه‌ی فعلی.'
        : 'صدور بیمه‌نامه‌ی جدید برای خودروهای این درخواست.';
    document.getElementById('nr-counts-new').classList.toggle('hidden', k !== 'NEW_POLICY');
    document.getElementById('nr-counts-endorse').classList.toggle('hidden', k === 'NEW_POLICY');
    document.querySelectorAll('#nr-plates-list .plate-row').forEach(applyKindToPlateRow);
}

// تعدادها (رقم فارسی هم قبول است؛ سرور تمیزش می‌کند)
function collectNrCounts() {
    const out = {};
    ['THIRDPARTY', 'BODY', 'ENDORSEMENT', 'CANCELLATION'].forEach(k => { out[k] = document.getElementById('nr-cnt-' + k).value.trim(); });
    return out;
}

// «پلاک ندارد»: به‌جای خانه‌های پلاک، شماره شاسی و موتور گرفته می‌شود.
// «صفر کیلومتر»: شماره شاسی لازم است (پلاک اختیاری)، تاریخِ انقضای بیمه‌نامه‌ی قبلی ندارد و بازدیدِ سلامت نمی‌خواهد.
// پلاکِ موتور: فقط رقم (فارسی نمایش داده می‌شود)؛ کد شهر که پر شد، می‌رود سراغِ شماره
function plateMotoInput(el) {
    el.value = String(el.value).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/\D/g, '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    if (el.classList.contains('plate-mcity') && el.value.length >= 3) el.closest('.plate-row').querySelector('.plate-mnum').focus();
}
function togglePlateNoPlate(cb) {
    const row = cb.closest('.plate-row');
    const noPlate = row.querySelector('.plate-noplate').checked, isNew = row.querySelector('.plate-isnew').checked;
    const moto = !!(row.querySelector('.plate-moto') || {}).checked;
    row.querySelector('.plate-car').classList.toggle('hidden-pk', moto);
    row.querySelector('.plate-mt').classList.toggle('hidden-pk', !moto);
    row.querySelectorAll('.plate-mcity, .plate-mnum').forEach(i => { i.disabled = noPlate; i.classList.toggle('opacity-40', noPlate); if (noPlate) i.value = ''; });
    row.querySelector('.plate-chassis-box').classList.toggle('hidden', !(noPlate || isNew));
    row.querySelector('.plate-expiry').classList.toggle('hidden', isNew);
    if (isNew) row.querySelector('.plate-expiry').value = '';
    row.querySelectorAll('.plate-p1, .plate-p2, .plate-p4, .plate-letter').forEach(i => {
        i.disabled = noPlate;
        i.classList.toggle('opacity-40', noPlate);
        if (noPlate) i.value = '';
    });
}
// ---- هشدارِ تکراری در فرمِ ثبت: هر ردیف (شاسی/موتور/پلاک + نوع) با بیمه‌نامه‌های صادرشده و درخواست‌های باز ----
let nrDupTimer = null;
function nrDupSchedule() { clearTimeout(nrDupTimer); nrDupTimer = setTimeout(nrDupCheck, 700); }
async function nrDupCheck() {
    const kind = document.getElementById('nr-kind')?.value || 'NEW_POLICY';
    const rows = Array.from(document.querySelectorAll('#nr-plates-list .plate-row'));
    if (!rows.length) return;
    if (kind !== 'NEW_POLICY') { rows.forEach(r => { const b = r.querySelector('.plate-dup'); if (b) b.remove(); }); return; }
    const enD = v => String(v || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim();
    const payload = rows.map(row => {
        const moto = row.querySelector('.plate-moto')?.checked;   // موتورسیکلت: کد شهر و شماره
        return {p1: moto ? enD(row.querySelector('.plate-mcity').value) : row.querySelector('.plate-p1').value.trim(),
            p2: moto ? enD(row.querySelector('.plate-mnum').value) : row.querySelector('.plate-p2').value.trim(),
            letter: moto ? 'موتور' : row.querySelector('.plate-letter').value.trim(), p4: moto ? '' : row.querySelector('.plate-p4').value.trim(),
            chassis_no: (row.querySelector('.plate-chassis')?.value || '').trim(), engine_no: (row.querySelector('.plate-engine')?.value || '').trim(),
            type: row.querySelector('.plate-instype').value};
    });
    let r;
    try { r = await (await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'dup_check', rows: payload})})).json(); } catch (e) { return; }
    if (!r.ok) return;
    rows.forEach((row, i) => {
        let box = row.querySelector('.plate-dup');
        const list = (r.dups[i] || []);
        if (!list.length) { if (box) box.remove(); return; }
        if (!box) { box = document.createElement('div'); box.className = 'plate-dup mt-1.5'; row.appendChild(box); }
        box.innerHTML = portalDupHtml(list);
    });
}
function portalDupHtml(list) {
    return list.slice(0, 3).map(m => {
        const t = m.type === 'BODY' ? 'بدنه' : 'ثالث', bad = m.state !== 'history';
        const txt = m.state === 'open' ? `برای همین خودرو (${m.match}) درخواستِ ${t}ِ دیگری در جریان است${m.own && m.request_id ? ' (درخواست #' + faNum(m.request_id) + ')' : ''}`
            : `این خودرو (${m.match}) قبلاً بیمه‌نامه‌ی ${t}${m.own && m.policy_number ? ' شماره ' + escH(faNum(m.policy_number)) : ''}${m.own && m.issued_j ? ' صادره ' + faNum(m.issued_j) : ''} دارد${m.end_j ? ' (اعتبار تا ' + faNum(m.end_j) + ')' : ''}${bad ? '' : ' — نزدیکِ پایان/منقضی؛ تمدید مجاز است'}`;
        return `<div class="text-[11px] rounded-lg px-2.5 py-1.5 mb-1 ${bad ? 'bg-rose-50 border border-rose-200 text-rose-800' : 'bg-amber-50 border border-amber-200 text-amber-800'}">${bad ? '⛔' : '⚠️'} ${txt}
            ${m.own && m.has_file ? `<a class="font-bold text-blue-600 underline mr-1" href="../api/company_portal_actions.php?action=download_issued_policy&plate_id=${m.plate_id}">دانلودِ بیمه‌نامه</a>` : ''}${bad ? '<div class="text-[10px] opacity-80">این ردیف تکراری است و با درخواست ثبت نمی‌شود؛ حذفش کنید.</div>' : ''}</div>`;
    }).join('');
}
document.addEventListener('input', e => { if (e.target.closest && e.target.closest('#nr-plates-list')) nrDupSchedule(); });
// ---- پرونده‌ی خودرو (reg-link.js): همین خودرو قبلاً در درخواست‌های شرکتِ شما بوده؟ اطلاعات و مدارکش پیشنهاد می‌شود ----
function nrRegSchedule(row) {
    if (!window.RegLink || !row) return;
    clearTimeout(row._regT);
    row._regT = setTimeout(async () => {
        const enD = v => String(v || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim();
        const moto = row.querySelector('.plate-moto')?.checked;
        const q = moto ? {vin: row.querySelector('.plate-chassis')?.value, engine: row.querySelector('.plate-engine')?.value}
            : {p1: enD(row.querySelector('.plate-p1').value), p2: enD(row.querySelector('.plate-p2').value), letter: row.querySelector('.plate-letter').value.trim(), p4: enD(row.querySelector('.plate-p4').value),
               vin: row.querySelector('.plate-chassis')?.value, engine: row.querySelector('.plate-engine')?.value};
        const v = await RegLink.vehicle(q, {portal: true});
        const R = row._reg || (row._reg = {v: null, sel: new Set(), applied: false, dismiss: ''});
        if ((v && v.key) !== (R.v && R.v.key)) { R.v = v; R.applied = false; R.sel = new Set((v ? v.docs : []).map((d, i) => i)); }
        nrRegRender(row);
    }, 650);
}
function nrRegRender(row) {
    const R = row._reg;
    let box = row.querySelector('.plate-reg');
    if (!R || !R.v || R.dismiss === R.v.key) { if (box) box.remove(); return; }
    if (!box) { box = document.createElement('div'); box.className = 'plate-reg'; row.querySelector('.plate-chassis-box').before(box); }
    box.innerHTML = RegLink.vehicleCard(R.v, R.sel, {applied: R.applied, portal: true});
    box.querySelector('[data-reg-fill]').onclick = () => {
        const f = R.v.fill || {}, sm = R.v.summary || {};
        const set = (cls, val) => { const e = row.querySelector(cls); if (e && !e.value.trim() && val) e.value = val; };
        set('.plate-carname', f.car_name); set('.plate-cartype', f.car_type); set('.plate-chassis', f.chassis_no); set('.plate-engine', f.engine_no);
        const t = row.querySelector('.plate-instype').value, end = t === 'BODY' ? sm.body_end : (t === 'THIRDPARTY' ? sm.third_end : (sm.third_end || sm.body_end));
        if (end && !row.querySelector('.plate-isnew')?.checked) set('.plate-expiry', faNum(end));
        R.applied = true; nrRegRender(row); showToast('اطلاعاتِ خودرو از درخواست‌های قبلی نشست؛ بررسی کنید.');
    };
    box.querySelector('[data-reg-dismiss]').onclick = () => { R.dismiss = R.v.key; R.sel = new Set(); box.remove(); };
    box.querySelectorAll('[data-reg-doc]').forEach(cb => cb.onchange = () => { const i = Number(cb.dataset.regDoc); cb.checked ? R.sel.add(i) : R.sel.delete(i); });
}
document.addEventListener('input', e => {
    const t = e.target;
    if (t && t.closest && t.closest('#nr-plates-list') && /plate-(p1|p2|p4|letter|chassis|engine)\b/.test(t.className || '')) nrRegSchedule(t.closest('.plate-row'));
});
document.addEventListener('change', e => { if (e.target.closest && e.target.closest('#nr-plates-list')) nrDupSchedule(); });

// اکسلِ خودروها => ردیف‌های همین فرم (برای بازبینی پیش از ثبت)
async function importPlatesExcel(inp) {
    const f = inp.files[0]; if (!f) return;
    const msg = document.getElementById('nr-excel-msg');
    msg.innerHTML = '<span class="text-slate-500">در حال خواندن…</span>';
    const fd = new FormData(); fd.append('action', 'preview_excel_rows'); fd.append('file', f);
    try {
        const r = await (await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd})).json();
        inp.value = '';
        if (!r.ok) { msg.innerHTML = `<span class="text-rose-600 font-bold">${escH(r.error || 'خطا')}</span>`; return; }
        // ثالث و بدنه‌ی یک خودرو (دو ردیفِ پشتِ هم) در فرم یک ردیفِ «هردو» می‌شود
        const merged = [];
        r.rows.forEach(x => { const prev = merged[merged.length - 1]; const key = [x.p1, x.p2, x.letter, x.p4, x.chassis_no].join('|');
            if (prev && prev.key === key && prev.insurance_type !== x.insurance_type) { prev.insurance_type = 'BOTH'; prev.car_value = prev.car_value || x.car_value; prev.liability_limit = prev.liability_limit || x.liability_limit; }
            else merged.push(Object.assign({key}, x)); });
        const list = document.getElementById('nr-plates-list');
        merged.forEach(x => {
            addPlateRow('nr-plates-list');
            const row = list.lastElementChild, set = (c, v) => { const el = row.querySelector(c); if (el && v !== null && v !== undefined && v !== '') el.value = v; };
            if (!x.p1 && !x.p2 && x.chassis_no) row.querySelector('.plate-noplate').checked = true;
            if (x.letter === 'موتور') { row.querySelector('.plate-moto').checked = true; row.querySelector('.plate-mcity').value = x.p1 || ''; row.querySelector('.plate-mnum').value = x.p2 || ''; x.p1 = x.p2 = x.letter = x.p4 = ''; plateMotoInput(row.querySelector('.plate-mnum')); plateMotoInput(row.querySelector('.plate-mcity')); }
            if (Number(x.is_new_vehicle)) row.querySelector('.plate-isnew').checked = true;
            togglePlateNoPlate(row.querySelector('.plate-isnew'));
            set('.plate-p1', x.p1); set('.plate-p2', x.p2); set('.plate-letter', x.letter); set('.plate-p4', x.p4);
            set('.plate-chassis', x.chassis_no); set('.plate-engine', x.engine_no); set('.plate-carname', x.car_name); set('.plate-cartype', x.car_type); set('.plate-expiry', x.expiry_j);
            set('.plate-instype', x.insurance_type); togglePlateValueBox(row.querySelector('.plate-instype'));
            set('.plate-carvalue', x.car_value ? Number(x.car_value).toLocaleString('en-US') : ''); set('.plate-liability', x.liability_limit ? Number(x.liability_limit).toLocaleString('en-US') : '');
            set('.plate-refpolicy', x.ref_policy_number); set('.plate-endorse', x.endorsement_request);
        });
        nrDupSchedule();
        msg.innerHTML = `<span class="text-emerald-700 font-bold">${merged.length.toLocaleString('fa-IR')} خودرو از اکسل اضافه شد؛ بازبینی و بعد «ثبت» را بزنید.</span>` + (r.errors && r.errors.length ? `<div class="text-amber-700 mt-1">${r.errors.slice(0, 6).map(escH).join('<br>')}</div>` : '');
    } catch (e) { msg.innerHTML = '<span class="text-rose-600">خطا در ارتباط با سرور.</span>'; }
}
function escH(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])); }

// ارزش خودرو فقط برای بدنه، سقف تعهد مالی فقط برای ثالث
function togglePlateValueBox(sel) {
    const row = sel.closest('.plate-row');
    const t = sel.value;
    row.querySelector('.plate-carvalue').classList.toggle('hidden', !(t === 'BODY' || t === 'BOTH'));
    row.querySelector('.plate-liability').classList.toggle('hidden', !(t === 'THIRDPARTY' || t === 'BOTH'));
}

function collectPlateRows(containerId) {
    const enD = v => String(v || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim();
    const motoParts = row => {   // موتورسیکلت: p1 = کد شهر، p2 = شماره، حرف = «موتور»
        const c = enD(row.querySelector('.plate-mcity').value), n = enD(row.querySelector('.plate-mnum').value);
        return {p1: c, p2: n, letter: (c || n) ? 'موتور' : '', p4: '', vehicle_kind: 'MOTOR'};
    };
    const rows = Array.from(document.getElementById(containerId).querySelectorAll('.plate-row')).map(row => Object.assign({
        p1: row.querySelector('.plate-p1').value.trim(),
        p2: row.querySelector('.plate-p2').value.trim(),
        letter: row.querySelector('.plate-letter').value.trim(),
        p4: row.querySelector('.plate-p4').value.trim(),
        chassis_no: (row.querySelector('.plate-chassis')?.value || '').trim(),
        engine_no: (row.querySelector('.plate-engine')?.value || '').trim(),
        is_new_vehicle: row.querySelector('.plate-isnew')?.checked ? 1 : 0,
        car_name: (row.querySelector('.plate-carname')?.value || '').trim(),
        car_type: (row.querySelector('.plate-cartype')?.value || '').trim(),
        expiry_j: row.querySelector('.plate-isnew')?.checked ? '' : (row.querySelector('.plate-expiry')?.value || '').trim(),
        car_value: (row.querySelector('.plate-carvalue')?.value || '').trim(),
        liability_limit: (row.querySelector('.plate-liability')?.value || '').trim(),
        ref_policy_number: (row.querySelector('.plate-refpolicy')?.value || '').trim(),
        // فقط فیلدِ همان نوعِ الحاقیه‌ای که برای این خودرو انتخاب شده فرستاده می‌شود
        endorsement_request: !row.querySelector('.plate-endorse').classList.contains('hidden') ? (row.querySelector('.plate-endorse')?.value || '').trim() : '',
        cancellation_reason: !row.querySelector('.plate-cancelreason').classList.contains('hidden') ? (row.querySelector('.plate-cancelreason')?.value || '').trim() : '',
        insurance_type: row.querySelector('.plate-instype').value,
        // مدارکِ انتخاب‌شده از پرونده‌ی همین خودرو (درخواست‌ها/بیمه‌نامه‌های قبلیِ شرکت) — بعد از ثبت روی ردیف می‌نشینند
        reg_docs: row._reg && row._reg.v && row._reg.dismiss !== row._reg.v.key ? [...row._reg.sel].map(i => row._reg.v.docs[i]).filter(Boolean).map(d => ({src: d.src, id: d.id, type: d.type})) : [],
        ...(row.querySelector('.plate-party-on')?.checked && window.PartyUI ? PartyUI.collect(row.querySelector('.plate-party')) : {}),
    // ردیف یا پلاک دارد یا شماره شاسی (لیفتراک و خودروی صفرکیلومتر پلاک ندارند)
    }, row.querySelector('.plate-moto')?.checked ? motoParts(row) : {})).filter(p => p.p1 || p.p2 || p.letter || p.p4 || p.chassis_no);
    // اگر «هردو» انتخاب شده، همین پلاک به دو ردیفِ جدا (ثالث + بدنه) تبدیل می‌شود
    const out = [];
    const keep = (docs, t) => (docs || []).filter(d => !((d.type === 'prev_body_policy' && t !== 'BODY') || (d.type === 'prev_third_policy' && t !== 'THIRDPARTY')));
    rows.forEach(p => {
        if (p.insurance_type === 'BOTH') {
            out.push({...p, insurance_type: 'THIRDPARTY', reg_docs: keep(p.reg_docs, 'THIRDPARTY')});
            out.push({...p, insurance_type: 'BODY', reg_docs: keep(p.reg_docs, 'BODY')});
        } else out.push({...p, reg_docs: keep(p.reg_docs, p.insurance_type)});
    });
    return out;
}

async function submitNewRequest() {
    const fd = new FormData();
    fd.append('action', 'submit_request');
    fd.append('company_id', document.getElementById('nr-company').value);
    fd.append('insurer', document.getElementById('nr-insurer').value);
    fd.append('request_kind', document.getElementById('nr-kind').value);
    fd.append('request_text', document.getElementById('nr-text').value.trim());
    fd.append('requested_counts', JSON.stringify(collectNrCounts()));
    if (window.PartyUI && document.querySelector('#nr-party .pty-ed')) {
        const pv = PartyUI.collect(document.getElementById('nr-party'));
        fd.append('party_mode', pv.party_mode || 'COMPANY');
        if (pv.party) fd.append('party', JSON.stringify(pv.party));
    }
    const zk = window.ZeroKm ? ZeroKm.payload() : null;   // خودروهای صفر کیلومتر با فاکتور (company-portal/zerokm.js)
    fd.append('plates', JSON.stringify(collectPlateRows('nr-plates-list').concat(zk ? zk.plates : [])));
    if (zk && zk.token) fd.append('zk_token', zk.token);
    const fileInput = document.getElementById('nr-letter');
    if (fileInput.files[0]) fd.append('letter_file', fileInput.files[0]);
    try {
        const res = await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd});
        const data = await res.json();
        if (data.ok) {
            showToast('درخواست با موفقیت ثبت شد.');
            closeModal('new-request-modal');
            document.getElementById('nr-text').value = ''; fileInput.value = '';
            ['THIRDPARTY', 'BODY', 'ENDORSEMENT', 'CANCELLATION'].forEach(k => { document.getElementById('nr-cnt-' + k).value = ''; });
            document.getElementById('nr-plates-list').innerHTML = '';
            loadRequests();
        } else { showToast(data.error || 'خطا در ثبت درخواست.'); }
    } catch (e) { showToast('خطا در ارتباط با سرور.'); }
}

// یک خانه‌ی چک‌لیست در پنل شرکت: تیک اگر دارند، ضربدر اگر ندارند (قرمز اگر اجباری
// است) و همان‌جا دکمه‌ی آپلود، تا خودِ ثبت‌کننده هم بتواند کم‌وکسری را کامل کند.
function portalChecklistChip(plate, item) {
    const ok = item.satisfied;
    const tone = ok ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
                    : (item.internal && plate.report_pending) ? 'bg-cyan-50 border-cyan-200 text-cyan-700'
                    : (item.required ? 'bg-red-50 border-red-200 text-red-600'
                                     : 'bg-slate-50 border-slate-200 text-slate-400');
    const star = item.required ? '<span class="text-red-400">*</span>' : '';
    const links = (item.docs || []).map(d => d.is_dir
        ? `<span class="text-slate-500">${d.label} (پوشه)</span>`
        : `<a href="../${d.file_path}" target="_blank" class="underline hover:no-underline">${d.label}</a>`
    ).join('<span class="text-slate-300 mx-1">|</span>');

    let uploader = '';
    if (item.custom && plate.status !== 'ISSUED') {
        // «مدرکِ دیگر»: نامش را خودتان می‌نویسید (چند مدرک هم می‌شود زد)
        uploader = `<span class="up-stack"><input id="pr-label-${plate.id}" maxlength="80" placeholder="نامِ مدرک (خالی = نامِ فایل)" title="هر بار یک یا چند فایل با نامِ دلخواه" class="up-in">
            <input type="hidden" id="pr-type-${plate.id}-other" value="other">
            <label class="up-btn"><i class="fas fa-plus"></i>افزودنِ مدرک<input type="file" class="hidden" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="portalUploadRowDoc(${plate.id}, 'pr-type-${plate.id}-other', this, 'pr-label-${plate.id}')"></label></span>`;
    } else if (!ok && item.internal) {
        // بازدید رسیده؛ گزارشِ بازدید را کارشناسِ ما می‌سازد
        uploader = `<span class="block mt-1 text-[9.5px] ${plate.report_pending ? 'text-cyan-700 font-bold' : 'text-slate-400'}">${plate.report_pending ? 'بازدید رسیده؛ گزارش را ما می‌سازیم' : 'بعد از رسیدنِ بازدید، گزارش را ما می‌سازیم'}</span>`;
    } else if (!ok && plate.status !== 'ISSUED' && item.key === 'health_inspection') {
        // بازدیدی که خودتان انجام داده‌اید: زیپ، RAR، چند عکس یا یک پوشه
        uploader = `<span class="up-row" style="margin-top:auto;padding-top:3px">
            <label class="up-btn" title="زیپ، RAR یا یک یا چند عکس/PDF"><i class="fas fa-file-zipper"></i>زیپ/RAR/عکس<input type="file" class="hidden" multiple accept=".zip,.rar,.7z,.pdf,.jpg,.jpeg,.png,.webp" data-uk-skip onchange="portalUploadInspection(${plate.id}, this)"></label>
            <label class="up-btn soft" title="همه‌ی فایل‌های یک پوشه"><i class="fas fa-folder-open"></i>پوشه<input type="file" class="hidden" webkitdirectory multiple data-uk-skip onchange="portalUploadInspection(${plate.id}, this)"></label></span>`;
    } else if (!ok && plate.status !== 'ISSUED') {
        const types = item.upload_types || [item.key];
        const sel = types.length > 1
            ? `<select id="pr-type-${plate.id}-${item.key}" class="up-sel">
                   ${types.map(t => `<option value="${t}">${PORTAL_DOC_TYPE_FA[t] || t}</option>`).join('')}
               </select>`
            : `<input type="hidden" id="pr-type-${plate.id}-${item.key}" value="${types[0]}">`;
        const accept = item.key === 'health_inspection' ? '.zip,.pdf,.jpg,.jpeg,.png,.webp' : '.pdf,.jpg,.jpeg,.png,.webp';
        uploader = `<span class="up-stack">${sel}
            <label class="up-btn"><i class="fas fa-cloud-arrow-up"></i>بارگذاری<input type="file" class="hidden" accept="${accept}" onchange="portalUploadRowDoc(${plate.id}, 'pr-type-${plate.id}-${item.key}', this)"></label></span>`;
    }

    return `<div class="ck-chip border ${tone}" title="${item.hint || ''}">
        <div class="ck-t"><span>${ok ? '✓' : (item.internal && plate.report_pending ? '◐' : '✗')}</span><span>${item.label}${star}</span></div>
        ${links ? `<div style="word-break:break-word">${links}</div>` : ''}
        ${uploader}
    </div>`;
}

const PORTAL_ROW_STATUS_COLOR = {
    PENDING: 'bg-amber-50 text-amber-600', READY_FOR_ISSUE: 'bg-cyan-50 text-cyan-600',
    WITH_BOSS: 'bg-indigo-50 text-indigo-600', IN_ISSUANCE: 'bg-violet-50 text-violet-600',
    ISSUED: 'bg-emerald-50 text-emerald-600', CANCELLED: 'bg-red-50 text-red-600',
};
const PORTAL_DOC_TYPE_FA = {
    car_card_front: 'روی کارت ماشین', car_card_back: 'پشت کارت ماشین', ownership_doc: 'سند', sales_invoice: 'فاکتور فروش',
    prev_third_policy: 'بیمه ثالث قبل', prev_body_policy: 'بیمه بدنه قبل',
    health_inspection: 'بازدید سلامت', health_report: 'گزارش بازدید',
    policy_doc: 'بیمه‌نامه', new_car_card: 'کارت ماشین جدید', new_ownership_doc: 'سند جدید',
    other: 'سایر مدارک', car_card_or_title: 'کارت ماشین یا سند', letter: 'نامه‌ی درخواست', no_claim_report: 'گزارش عدم خسارت',
};

async function openRequestDetail(id) {
    activeRequestId = id;
    const res = await fetch('../api/company_portal_actions.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'request_detail', request_id: id})});
    const data = await res.json();
    if (!data.ok) { showToast(data.error || 'خطا در دریافت اطلاعات.'); return; }
    const r = data.request;
    currentRequestPlates = data.plates;
    const c = data.counts || {body: 0, third: 0, body_issued: 0, third_issued: 0};

    const fileIcon = (name) => {
        const ext = (name || '').split('.').pop().toLowerCase();
        if (['jpg','jpeg','png','webp'].includes(ext)) return ['fa-file-image', 'text-purple-500'];
        if (ext === 'pdf') return ['fa-file-pdf', 'text-red-500'];
        if (ext === 'zip') return ['fa-file-zipper', 'text-amber-500'];
        return ['fa-file', 'text-slate-400'];
    };
    const generalDocs = data.documents.filter(d => !d.plate_id || !data.plates.some(p => String(p.id) === String(d.plate_id)));
    const docsHtml = generalDocs.length ? generalDocs.map(d => {
        const [icon, color] = fileIcon(d.orig_name || d.file_path);
        const assigned = d.status === 'ASSIGNED';
        return `
        <a href="../${d.file_path}" target="_blank" class="gdoc flex items-center justify-between gap-2 bg-white border rounded-xl p-3 mb-2 hover:shadow-md hover:border-blue-200 transition-all group">
            <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center shrink-0 group-hover:bg-blue-50 transition-colors"><i class="fas ${icon} ${color}"></i></div>
                <div class="min-w-0">
                    <span class="text-xs font-bold text-slate-700 truncate block">${d.orig_name || 'فایل'}</span>
                    <span class="text-[10px] text-slate-400">${d.doc_type_label || ''}${faNum(d.uploaded_at_jalali) ? ' · ' + faNum(d.uploaded_at_jalali) : ''}</span>
                </div>
            </div>
            <span class="text-[10px] font-bold px-2.5 py-1 rounded-full shrink-0 ${assigned ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'}">${assigned ? '✓ بررسی‌شده' : 'در انتظار بررسی'}</span>
        </a>`;
    }).join('') : '<p class="text-xs text-slate-400 bg-slate-50 rounded-xl p-4 text-center">مدرکِ کلی (بدونِ ردیف) ندارد؛ مدارکِ هر ردیف داخلِ همان ردیف آمده است.</p>';

    // ---- ریزِ درخواست: یک ردیف به ازای هر بیمه‌نامه‌ی درخواستی، با چک‌لیست و مدارکِ همان ردیف ----
    const rowDocHtml = d => {
        const [icon, color] = fileIcon(d.orig_name || d.file_path);
        const assigned = d.status === 'ASSIGNED';
        return `<a href="../${d.file_path}" target="_blank" class="prow-doc">
            <i class="fas ${icon} ${color}"></i>
            <span class="min-w-0 flex-1"><span class="text-[11px] font-bold text-slate-700 truncate block">${d.doc_type_label || d.orig_name || 'فایل'}</span>
                <span class="text-[9.5px] text-slate-400 truncate block">${d.orig_name || ''}${faNum(d.uploaded_at_jalali) ? ' · ' + faNum(d.uploaded_at_jalali) : ''}</span></span>
            <span class="text-[9.5px] font-bold px-2 py-0.5 rounded-full shrink-0 ${assigned ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'}">${assigned ? '✓ بررسی‌شده' : 'در انتظار'}</span></a>`;
    };
    const rowsHtml = data.plates.length ? data.plates.map((p, idx) => {
        const missing = Object.values(p.missing_docs || {});
        const chips = (p.checklist || []).map(it => portalChecklistChip(p, it)).join('');
        const myDocs = data.documents.filter(d => String(d.plate_id || '') === String(p.id));
        return `
        <div class="prow">
            <div class="prow-head flex items-center justify-between gap-2 flex-wrap">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <span class="text-[10px] text-slate-300 font-bold">${faNum(idx + 1)}</span>
                    ${(p.plate_p1 || p.plate_p2 || p.plate_letter || p.plate_p4)
                        ? plateHtml(p)
                        : `<div class="border-2 border-dashed border-slate-400 rounded-md px-2.5 py-1 font-black text-xs text-slate-600" dir="ltr" title="شماره شاسی">${p.chassis_no || 'بدون شناسه'}</div>`}
                    <span class="text-[10px] font-bold text-slate-400">${p.insurance_type === 'BODY' ? 'بیمه بدنه' : 'بیمه ثالث'}</span>
                    ${p.car_name || p.car_type ? `<span class="text-[10px] font-bold text-slate-600">${escH([p.car_name, p.car_type].filter(Boolean).join(' · '))}</span>` : ''}
                    ${faNum(p.expiry_date_jalali) ? `<span class="text-[10px] text-slate-400">انقضا: ${faNum(p.expiry_date_jalali)}</span>`
                        : (Number(p.is_new_vehicle) ? '<span class="text-[10px] text-slate-400">صفر کیلومتر</span>' : '')}
                    ${p.insurance_type === 'BODY' && p.car_value ? `<span class="text-[10px] text-slate-500">ارزش: ${faMoney(p.car_value)}</span>` : ''}
                    ${p.insurance_type === 'THIRDPARTY' && p.liability_limit ? `<span class="text-[10px] text-slate-500">تعهد: ${faMoney(p.liability_limit)}</span>` : ''}
                </div>
                <span class="text-[10px] font-bold px-2.5 py-1 rounded-full ${p.dup ? 'bg-rose-100 text-rose-700' : (PORTAL_ROW_STATUS_COLOR[p.status] || 'bg-slate-100 text-slate-500')}">${p.dup ? '⛔ تکراری' : (p.status_fa || p.status)}</span>
            </div>
            <div class="prow-body">
            ${p.dup ? `<div class="text-[11px] rounded-lg bg-rose-50 border border-rose-200 text-rose-800 px-2.5 py-1.5 mb-1.5">این خودرو برای همین نوعِ بیمه قبلاً بیمه‌نامه دارد و این ردیف صادر نمی‌شود.
                ${p.dup.plate_id ? `<br>بیمه‌نامه‌ی قبلی${p.dup.policy_number ? ' شماره <b dir="ltr">' + escH(faNum(p.dup.policy_number)) + '</b>' : ''}${p.dup.issued_j ? ' (صادره ' + faNum(p.dup.issued_j) + ')' : ''}
                    <a class="font-bold text-blue-600 underline mr-1" href="../api/company_portal_actions.php?action=download_issued_policy&plate_id=${p.dup.plate_id}">دانلود</a>` : (p.dup.end_j ? '<br>بیمه‌نامه‌ی قبلی معتبر تا ' + faNum(p.dup.end_j) : '')}</div>` : ''}
            ${window.PartyUI && p.party ? `<div class="mb-1.5">${PartyUI.chip(p.party, {onEdit: ['ISSUED', 'IN_ISSUANCE'].includes(p.status) ? '' : `portalEditParty(${p.id})`})}</div>` : ''}
            ${p.car_name ? `<p class="text-[10px] text-slate-400 mb-1">${p.car_name}</p>` : ''}
            ${p.engine_no ? `<p class="text-[10px] text-slate-400 mb-1" dir="ltr">شماره موتور: ${p.engine_no}</p>` : ''}
            ${p.ref_policy_number ? `<p class="text-[10px] text-slate-500 mb-1" dir="ltr">شماره بیمه‌نامه: ${p.ref_policy_number}</p>` : ''}
            ${p.endorsement_request ? `<p class="text-[10px] text-violet-600 mb-1">خواسته: ${p.endorsement_request}</p>` : ''}
            ${p.cancellation_reason ? `<p class="text-[10px] text-rose-600 mb-1">دلیل فسخ: ${p.cancellation_reason}</p>` : ''}
            <div class="checklist-grid">${chips}</div>
            ${missing.length
                ? `<p class="text-[10px] text-amber-600 mt-2"><i class="fas fa-triangle-exclamation ml-1"></i>مدارک ناموجود: ${missing.join('، ')}</p>`
                : '<p class="text-[10px] text-emerald-600 mt-2"><i class="fas fa-check ml-1"></i>مدارک این ردیف کامل است</p>'}
            ${p.status === 'ISSUED' ? `
                <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between gap-2 flex-wrap">
                    <span class="text-[10px] text-slate-500">${p.policy_number ? 'شماره بیمه‌نامه: ' + p.policy_number : ''}${faNum(p.issued_at_jalali) ? ' · ' + faNum(p.issued_at_jalali) : ''}</span>
                    ${p.has_issued_file ? `<a href="../api/company_portal_actions.php?action=download_issued_policy&plate_id=${p.id}"
                        class="text-[10px] font-bold text-white bg-emerald-600 hover:bg-emerald-700 px-2.5 py-1 rounded-lg">دانلود بیمه‌نامه</a>` : ''}
                </div>` : ''}
            <div class="prow-docs">
                <p class="text-[10.5px] font-black text-slate-500 mb-1.5"><i class="fas fa-paperclip ml-1 text-slate-300"></i>مدارکِ این ردیف ${myDocs.length ? '(' + faNum(myDocs.length) + ')' : ''}</p>
                ${myDocs.length ? `<div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">${myDocs.map(rowDocHtml).join('')}</div>` : '<p class="text-[10px] text-slate-400">هنوز مدرکی برای این ردیف ثبت نشده.</p>'}
            </div>
            </div>
        </div>`;
    }).join('') : '<p class="text-xs text-slate-400 bg-slate-50 rounded-xl p-4 text-center">ریزِ درخواست هنوز ثبت نشده؛ به‌محض ثبت، هر ردیف و مدارک لازمش همین‌جا نمایش داده می‌شود.</p>';

    document.getElementById('request-detail-body').innerHTML = `
        <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-5 mb-4 text-white relative overflow-hidden">
            <div class="absolute -left-6 -top-6 w-28 h-28 bg-white/10 rounded-full"></div>
            <div class="absolute -left-2 -bottom-8 w-20 h-20 bg-white/10 rounded-full"></div>
            <div class="relative flex items-center justify-between mb-3">
                <span class="flex items-center gap-1.5">
                    ${r.ref_code ? `<span class="status-badge bg-white text-indigo-700 font-black">شناسه‌ی ${faNum(r.ref_code)}</span>` : ''}
                    <span class="status-badge bg-white/20 backdrop-blur-sm">${STATUS_FA[r.status] || r.status}</span>
                    ${r.request_kind && r.request_kind !== 'NEW_POLICY'
                        ? `<span class="status-badge bg-white/30">${r.request_kind_fa}</span>` : ''}
                </span>
                <span class="text-xs font-bold opacity-90">${INSURER_FA[r.insurer] ? 'بیمه ' + INSURER_FA[r.insurer] : ''}</span>
            </div>
            <p class="relative text-sm leading-relaxed font-bold">${r.request_text || '(بدون توضیح متنی)'}</p>
            ${r.requested_counts_fa ? `<p class="relative text-[11px] font-bold mt-2 opacity-90">درخواستی: ${r.requested_counts_fa}</p>` : ''}
            <div class="relative flex gap-4 mt-3 text-[10.5px] opacity-80">
                <span><i class="far fa-calendar-plus ml-1"></i>ثبت: ${faNum(r.created_at_jalali)}</span>
                <span><i class="far fa-clock ml-1"></i>ویرایش: ${faNum(r.updated_at_jalali)}</span>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
            <div class="bg-cyan-50 border border-cyan-100 rounded-xl p-2 text-center"><p class="text-[10px] text-cyan-700">بدنه درخواستی</p><p class="font-black text-cyan-800">${faNum(c.body || 0)}</p></div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-2 text-center"><p class="text-[10px] text-emerald-700">بدنه صادرشده</p><p class="font-black text-emerald-800">${faNum(c.body_issued || 0)}</p></div>
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-2 text-center"><p class="text-[10px] text-blue-700">ثالث درخواستی</p><p class="font-black text-blue-800">${faNum(c.third || 0)}</p></div>
            <div class="bg-teal-50 border border-teal-100 rounded-xl p-2 text-center"><p class="text-[10px] text-teal-700">ثالث صادرشده</p><p class="font-black text-teal-800">${faNum(c.third_issued || 0)}</p></div>
        </div>

        ${((c.body_issued || 0) + (c.third_issued || 0)) > 0
            ? `<a href="../api/company_portal_actions.php?action=download_issued_zip&request_id=${r.id}" class="flex items-center justify-center gap-2 w-full mb-4 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl text-xs">
                   <i class="fas fa-file-zipper"></i>دانلود دسته‌جمعی بیمه‌نامه‌های صادرشده (${faNum((c.body_issued || 0) + (c.third_issued || 0))} صادرشده تا این لحظه، زیپ)</a>`
            : ''}

        <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">
            <h4 class="text-xs font-bold text-slate-500 flex items-center gap-1.5"><i class="fas fa-car text-slate-300"></i>ریز درخواست و مدارک هر ردیف</h4>
            ${window.PartyUI && data.plates.some(p => !['ISSUED', 'IN_ISSUANCE'].includes(p.status)) ? `<button onclick="portalEditAllParty()" class="text-[11px] font-bold bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 px-3 py-1.5 rounded-lg">مالک / بیمه‌گذار برای همه‌ی ردیف‌ها</button>` : ''}
        </div>
        ${rowsHtml}

        <h4 class="text-xs font-bold text-slate-500 mb-2 mt-4 flex items-center gap-1.5"><i class="fas fa-paperclip text-slate-300"></i>مدارکِ کلیِ درخواست (نامه و مدارکی که به ردیفِ خاصی وصل نیستند)</h4>
        ${docsHtml}
    `;
    window.portalPartyDefault = r.party_default || null;
    { const uo = data.upload_opts || {general: true}; document.getElementById('du-box').classList.toggle('hidden', !uo.general); document.getElementById('du-off-note').classList.toggle('hidden', !!uo.general); }
    document.getElementById('doc-upload-input').value = '';
    duFiles = []; renderDocUploadRows();
    openModal('request-detail-modal');
}

// ---- مالک / بیمه‌گذار / ذینفع (party-ui.js): یک ردیف یا همه‌ی ردیف‌های درخواست ----
async function portalPartySave(body) {
    let d;
    try { d = await (await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({action: 'set_party', request_id: activeRequestId}, body))})).json(); }
    catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
    if (!d.ok) { showToast(d.error || 'خطا'); return false; }
    showToast('ذخیره شد' + (d.locked ? ` (${faNum(d.locked)} ردیفِ صادرشده یا در حالِ صدور عوض نشد؛ برای آن‌ها به کارشناس پیام دهید)` : '') + '.');
    if (activeRequestId) openRequestDetail(activeRequestId);
    return true;
}
function portalEditParty(plateId) {
    const p = (currentRequestPlates || []).find(x => String(x.id) === String(plateId));
    if (!p) return;
    PartyUI.open({title: 'مالک / بیمه‌گذار / ذینفعِ این خودرو', value: {mode: (p.party || {}).mode || 'COMPANY', party: (p.party || {}).other},
                  onSave: v => portalPartySave(Object.assign({plate_ids: [plateId]}, v))});
}
function portalEditAllParty() {
    const def = window.portalPartyDefault || {};
    PartyUI.open({bulk: true, title: 'مالک / بیمه‌گذار برای همه‌ی ردیف‌ها', sub: 'برای همه‌ی ردیف‌هایی که هنوز صادر نشده‌اند اعمال می‌شود؛ بعد می‌توانید هر ردیف را جدا عوض کنید.',
                  value: {mode: def.mode || 'COMPANY', party: def.party}, onSave: v => portalPartySave(Object.assign({all: 1}, v))});
}

// آپلودِ هدفمندِ یک مدرک روی یک ردیف، از دکمه‌ی همان خانه‌ی چک‌لیست
// «مدرکِ دیگر» می‌تواند چند فایل باشد: هر فایل یک مدرکِ جدا با نامِ نوشته‌شده («نام ۱»، «نام ۲») یا نامِ خودِ فایل
async function portalUploadRowDoc(plateId, typeElId, input, labelElId) {
    const files = [...(input.files || [])];
    input.value = '';
    if (!files.length) return;
    const typeEl = document.getElementById(typeElId);
    const lb = labelElId ? document.getElementById(labelElId) : null, typed = lb ? lb.value.trim() : '';
    let ok = 0, last = null;
    for (let i = 0; i < files.length; i++) {
        const f = files[i];
        const fd = new FormData();
        fd.append('action', 'upload_row_doc');
        fd.append('plate_id', plateId);
        fd.append('doc_type', typeEl ? typeEl.value : 'other');
        if (labelElId) fd.append('doc_label', typed ? (files.length > 1 ? typed + ' ' + faNum(i + 1) : typed) : f.name.replace(/\.[^.]+$/, '').slice(0, 80));
        fd.append('doc_file', f, f.name);
        try {
            const data = await (await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd})).json();
            if (data.ok) ok++; else last = data.error || 'خطا در بارگذاری.';
        } catch (e) { last = 'خطا در ارتباط با سرور.'; }
    }
    showToast(ok ? (files.length > 1 ? faNum(ok) + ' مدرک ثبت شد.' : 'مدرک ثبت شد.') + (last ? ' (بقیه: ' + last + ')' : '') : (last || 'خطا'));
    if (ok && activeRequestId) openRequestDetail(activeRequestId);
}
// بازدیدی که شرکت خودش انجام داده: زیپ/RAR همان‌طور؛ پوشه یا چند عکس در مرورگر یک زیپ می‌شود
async function portalUploadInspection(plateId, input) {
    const files = [...(input.files || [])].filter(f => !/^\./.test(f.name));
    const isDir = !!input.webkitdirectory;
    input.value = '';
    if (!files.length) return;
    let file = files[0];
    if (files.length > 1 || isDir) {
        try {
            showToast('در حالِ آماده‌سازیِ ' + faNum(files.length) + ' فایل…');
            const JSZip = await UploadKit.ensure('jszip');
            const z = new JSZip();
            files.forEach(f => z.file((f.webkitRelativePath || f.name).replace(/^[^/]*\//, '') || f.name, f));
            file = new File([await z.generateAsync({type: 'blob'})], 'inspection.zip', {type: 'application/zip'});
        } catch (e) { showToast('ساختِ زیپ ممکن نشد: ' + (e.message || e)); return; }
    }
    const fd = new FormData();
    fd.append('action', 'upload_row_doc'); fd.append('plate_id', plateId); fd.append('doc_type', 'health_inspection'); fd.append('doc_file', file, file.name);
    try {
        const data = await (await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd})).json();
        showToast(data.ok ? 'بازدید ثبت شد؛ گزارشِ بازدید را کارشناسِ ما می‌سازد.' : (data.error || 'خطا در بارگذاری.'));
        if (data.ok && activeRequestId) openRequestDetail(activeRequestId);
    } catch (e) { showToast('خطا در ارتباط با سرور (حجمِ فایل را بررسی کنید).'); }
}

const DOC_TYPE_OPTIONS = [
    ['letter', 'نامه‌ی درخواست (اگر موقع ثبت درخواست نامه ارسال نشده بود)'],
    ['mixed', 'چند مدرکِ مختلف در یک PDF (هر صفحه جدا بررسی می‌شود)'],
    ['ownership_doc', 'سند'],
    ['car_card_front', 'روی کارت ماشین'],
    ['car_card_back', 'پشت کارت ماشین'],
    ['sales_invoice', 'فاکتور فروش (خودروی صفر کیلومتر)'],
    ['prev_third_policy', 'بیمه ثالث قبل'],
    ['prev_body_policy', 'بیمه بدنه قبل'],
    ['health_inspection', 'بازدید سلامت (زیپ/عکس)'],
    ['health_report', 'گزارش بازدید'],
    ['policy_doc', 'بیمه‌نامه (برای الحاقیه و فسخ)'],
    ['new_car_card', 'کارت ماشین جدید (برای الحاقیه)'],
    ['new_ownership_doc', 'سند جدید (برای الحاقیه)'],
    ['other', 'سایر مدارک'],
];

// معنیِ ستون‌های پلاک در دیتابیس، دقیقاً هم‌شکل با پلاک‌های پرسنلی
// («{p1}ایران - {p2} {letter} {p4}» در api/_company_helpers.php و همان چیزی که
//  api/webapp_app.php با نام region/suffix/letter/prefix می‌سازد):
//    plate_p1 = دو رقمِ کنارِ «ایران» (کد شهر)   plate_p2 = سه رقمِ وسط
//    plate_letter = حرف  plate_p4 = دو رقمِ ابتدای پلاک
// پس ترتیبِ نمایشیِ چپ‌به‌راست می‌شود: p4 - حرف - p2 - ایران - p1
// (اگر این ترتیب جابه‌جا شود، نامِ فایل‌ها و مقایسه‌ی پلاکِ OCR غلط از آب درمی‌آید)
function plateOptionLabel(p) {
    const parts = [p.plate_p4, p.plate_letter, p.plate_p2].filter(Boolean).join(' ');
    return (parts || 'بدون‌شماره') + (p.plate_p1 ? ' ایران ' + p.plate_p1 : '');
}

// نمایشِ پلاک از چپ به راست: [۲ رقم] [حرف] [۳ رقم] [ایران] [۲ رقم].
// inline-flex + unicode-bidi:isolate لازم است، وگرنه مرورگر در متنِ راست‌به‌چپ
// ترتیبِ عدد و حرف فارسی را جابه‌جا می‌کند و پلاک به‌هم می‌ریزد.
function plateHtml(p) {
    if (!p.plate_p1 && !p.plate_p2 && !p.plate_letter && !p.plate_p4) return 'پلاک نامشخص';
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fa = v => String(v ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    if (p.plate_letter === 'موتور') return `<span class="mt-plate"><span class="flag"></span><span class="mt-n"><b>${fa(p.plate_p1 || '---')}</b><span>${fa(p.plate_p2 || '-----')}</span></span><span class="mt-ic">🏍</span></span>`;
    return `<span class="ir-plate"><span class="flag"></span><span>${fa(p.plate_p4 || '--')}</span><span>${esc(p.plate_letter || '-')}</span><span>${fa(p.plate_p2 || '---')}</span><span class="ir"><small>ایران</small>${fa(p.plate_p1 || '--')}</span></span>`;
}

// فایل‌های آماده‌ی ارسال (از چند انتخابِ پشتِ‌سرِهم جمع می‌شوند؛ upload-kit.js زیپ/پوشه/PDF را از قبل باز و جدا کرده)
let duFiles = [];
function duAddFiles(input) {
    const add = Array.from(input.files || []);
    input.value = '';
    if (!add.length) return;
    duFiles = duFiles.concat(add);
    // شماره‌گذاریِ ۱ تا N روی کلِ فهرست (وقتی چند بار انتخاب شود هم پیوسته بماند)
    if (duFiles.length > 1 && window.UploadKit) duFiles = duFiles.map((f, i) => UploadKit.mkFile(f, (i + 1) + '.' + (UploadKit.ext(f.name) || 'pdf')));
    renderDocUploadRows();
}
function duRemove(i) { duFiles.splice(i, 1); renderDocUploadRows(); }
function renderDocUploadRows() {
    const box = document.getElementById('doc-upload-rows');
    if (!duFiles.length) { box.innerHTML = ''; return; }
    const bulk = duFiles.length > 1;
    const plateOptions = '<option value="">بدون پلاک مشخص</option>' + currentRequestPlates.map((p, i) => `<option value="${i}">${plateOptionLabel(p)}</option>`).join('');
    const typeOpts = DOC_TYPE_OPTIONS.filter(([v]) => !bulk || v !== 'mixed').map(([v, l]) => `<option value="${v}" ${bulk && v === 'other' ? 'selected' : ''}>${l}</option>`).join('');
    const total = duFiles.reduce((a, f) => a + f.size, 0);
    box.innerHTML = `<div class="flex items-center justify-between text-[11px] font-bold text-slate-500"><span>${faNum(duFiles.length)} فایل · ${faNum((total / 1048576).toFixed(1))} مگابایت</span>
            ${bulk ? `<span class="flex items-center gap-1">نوعِ همه: <select class="border border-slate-200 rounded-lg p-1" onchange="document.querySelectorAll('#doc-upload-rows .du-type').forEach(s => s.value = this.value)">${typeOpts}</select></span>` : ''}
            <button type="button" onclick="duFiles = []; renderDocUploadRows()" class="text-red-500">پاک کردنِ فهرست</button></div>`
        + duFiles.map((f, i) => `
        <div class="bg-white border border-slate-200 rounded-xl p-2.5 text-xs du-row" data-index="${i}">
            <p class="font-bold mb-1.5 truncate text-slate-700 flex items-center gap-1"><i class="fas fa-paperclip text-slate-300"></i><span class="flex-1 truncate" dir="auto">${f.name}</span><small class="text-slate-400">${faNum((f.size / 1048576).toFixed(1))} مگ</small><button type="button" onclick="duRemove(${i})" class="text-red-400 hover:text-red-600 px-1">&times;</button></p>
            <div class="grid grid-cols-2 gap-2">
                <select class="du-type border border-slate-200 rounded-lg p-1.5">${typeOpts}</select>
                <select class="du-plate border border-slate-200 rounded-lg p-1.5">${plateOptions}</select>
            </div>
        </div>`).join('');
}

// ارسال در پس‌زمینه (upload-kit.js): نوارِ پیشرفت پایینِ صفحه می‌ماند و با بستنِ این پنجره یا رفتن به بخشِ دیگر ادامه دارد
async function uploadDocuments() {
    if (!duFiles.length) { showToast('حداقل یک فایل انتخاب کنید.'); return; }
    const rows = document.querySelectorAll('#doc-upload-rows .du-row');
    const reqId = activeRequestId;
    const items = duFiles.map((f, i) => {
        const row = rows[i];
        const fd = new FormData();
        fd.append('action', 'upload_document');
        fd.append('request_id', reqId);
        fd.append('doc_type', row ? row.querySelector('.du-type').value : 'other');
        const plateIdx = row ? row.querySelector('.du-plate').value : '';
        const plate = plateIdx !== '' ? currentRequestPlates[Number(plateIdx)] : null;
        fd.append('plate_p1', plate ? (plate.plate_p1 || '') : '');
        fd.append('plate_p2', plate ? (plate.plate_p2 || '') : '');
        fd.append('plate_letter', plate ? (plate.plate_letter || '') : '');
        fd.append('plate_p4', plate ? (plate.plate_p4 || '') : '');
        fd.append('file', f, f.name);
        return {name: f.name, size: f.size, form: fd};
    });
    duFiles = []; renderDocUploadRows();
    if (!window.UploadKit) { showToast('ابزارِ ارسال بارگذاری نشد؛ صفحه را تازه کنید.'); return; }
    UploadKit.enqueue({title: 'مدارکِ درخواست #' + faNum(reqId), url: '../api/company_portal_actions.php', items,
        onDone: sum => {
            showToast(`${faNum(sum.ok)} از ${faNum(sum.total)} فایل ارسال شد` + (sum.fail ? ` (${faNum(sum.fail)} خطا)` : '') + '؛ شناساییِ خودکار شروع شد…');
            if (activeRequestId === reqId && document.getElementById('request-detail-modal').classList.contains('active')) openRequestDetail(reqId);
            if (sum.ok) docAiLoop(reqId);
        }});
    showToast('ارسال در پس‌زمینه شروع شد؛ پیشرفت پایینِ صفحه دیده می‌شود.');
}

// شناساییِ خودکارِ صفحه‌ها (فاکتور فروش، سند، کارت، بیمه‌نامه‌ی قبلی): هر بار یک صفحه، تا تمام شود.
// صفحه‌ای که شناخته شد روی ردیفِ همان خودرو (یا ردیفِ تازه) می‌نشیند؛ بقیه برای بررسیِ کارشناس می‌ماند.
let docAiBusy = false;
async function docAiLoop(reqId) {
    if (docAiBusy) return;
    docAiBusy = true;
    let bar = document.getElementById('doc-ai-bar');
    if (!bar) { bar = document.createElement('div'); bar.id = 'doc-ai-bar'; bar.style.cssText = 'position:fixed;bottom:16px;left:16px;z-index:9999;max-width:340px'; document.body.appendChild(bar); }
    let auto = 0, rest = 0, total = 0;
    try {
        for (let i = 0; i < 80; i++) {
            const r = await (await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'doc_ai_step'})})).json();
            if (!r.ok) break;
            (r.done || []).forEach(x => { total++; if (x.status === 'AUTO' || x.status === 'AUTO_NEW') auto++; else rest++; });
            bar.innerHTML = `<div class="card p-3 text-xs shadow-lg border border-indigo-100"><b class="text-indigo-700">🔎 شناساییِ خودکارِ مدارک</b><div class="mt-1 text-slate-600">${faNum(total)} صفحه خوانده شد · ${faNum(auto)} خودکار روی ردیف نشست${rest ? ' · ' + faNum(rest) + ' برای بررسیِ کارشناس' : ''}${r.pending ? ' · ' + faNum(r.pending) + ' صفحه‌ی دیگر…' : ''}</div></div>`;
            if (!r.pending) break;
        }
    } catch (e) {}
    docAiBusy = false;
    setTimeout(() => { bar.remove(); }, 6000);
    if (total && reqId) openRequestDetail(reqId);
}


// دیالوگ‌های تایید/ورودی با استایل خودِ پنل (نه پنجره‌ی پیش‌فرض مرورگر)
function showPortalConfirm(title, message) {
    return new Promise(resolve => {
        document.getElementById('portal-confirm-title').textContent = title;
        document.getElementById('portal-confirm-message').textContent = message;
        const modal = document.getElementById('portal-confirm-modal');
        const done = (val) => { modal.classList.remove('active'); resolve(val); };
        document.getElementById('portal-confirm-yes').onclick = () => done(true);
        document.getElementById('portal-confirm-no').onclick = () => done(false);
        modal.classList.add('active');
    });
}

function showPortalPrompt(title, currentValue) {
    return new Promise(resolve => {
        document.getElementById('portal-prompt-title').textContent = title;
        const input = document.getElementById('portal-prompt-input');
        input.value = currentValue || '';
        const modal = document.getElementById('portal-prompt-modal');
        const done = (val) => { modal.classList.remove('active'); resolve(val); };
        document.getElementById('portal-prompt-save').onclick = () => done(input.value);
        document.getElementById('portal-prompt-cancel').onclick = () => done(null);
        modal.classList.add('active');
        setTimeout(() => input.focus(), 50);
    });
}

// ---- گفتگو با «بیمه با ما» (پیام‌رسانِ مشترک: ../chat-ui.js) ----
let portalChat = null;
const chatB64 = o => btoa(unescape(encodeURIComponent(JSON.stringify(o || {}))));
const chatCompanyId = () => document.getElementById('chat-company').value;
async function portalChatCall(action, data) {
    const res = await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action, p: chatB64(Object.assign({company_id: chatCompanyId()}, data || {}))})});
    return res.json();
}
async function portalChatUpload(action, fd) {
    fd.append('action', action); fd.append('company_id', chatCompanyId());
    const res = await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd});
    return res.json();
}
function mountPortalChat() {
    if (portalChat) portalChat.destroy();
    portalChat = window.ChatUI ? ChatUI.mount(document.getElementById('portal-chat'), {mode: 'single', theme: 'light', call: portalChatCall, upload: portalChatUpload, toast: showToast}) : null;
    setTimeout(refreshChatUnread, 1500); // با باز شدنِ گفتگو پیام‌ها خوانده می‌شوند؛ عددِ روی آیکن هم به‌روز شود
}
function openChatModal() {
    openModal('chat-modal');
    if (!portalChat) mountPortalChat(); else { portalChat.poll(); setTimeout(refreshChatUnread, 1500); }
}
function closeChatModal() { closeModal('chat-modal'); refreshChatUnread(); }

// «آنلاین / آخرین بازدید»: حضور هر ۱۰ ثانیه ثبت می‌شود و با بستنِ صفحه «آفلاین»
if (window.ChatUI) ChatUI.heartbeat({
    ping: () => portalChatCall('chat_ping', {}),
    offline: () => { const fd = new FormData(); fd.append('action', 'chat_offline'); navigator.sendBeacon && navigator.sendBeacon('../api/company_portal_actions.php', fd); },
    every: 10000,
});
// ---- عکسِ پروفایل ----
let myAvatar = null;
const myName = <?php echo json_encode($_SESSION['company_user_full_name'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
function paintMyAvatar() {
    const el = document.getElementById('me-avatar');
    if (el && window.ChatUI) el.innerHTML = ChatUI.avatarHtml(myAvatar, myName, {size: 46}) + '<span class="absolute -bottom-1 -left-1 w-5 h-5 rounded-full bg-blue-600 text-white flex items-center justify-center text-[9px] shadow ring-2 ring-white"><i class="fas fa-camera"></i></span>';
}
async function loadMyAvatar() { try { const d = await portalChatCall('chat_me', {}); if (d.ok) { myAvatar = d.avatar; paintMyAvatar(); } } catch (e) {} }
async function changeMyAvatar() {
    const r = await ChatUI.pickAvatar({current: myAvatar || '', name: myName});
    if (!r) return;
    let d;
    try {
        if (r.file) { const fd = new FormData(); fd.append('avatar', r.file, 'avatar.jpg'); d = await portalChatUpload('chat_set_avatar', fd); }
        else d = await portalChatCall('chat_set_avatar', {avatar: r.avatar});
    } catch (e) { d = {ok: false, error: 'خطا در اتصال به سرور.'}; }
    if (!d.ok) { showToast(d.error || 'ذخیره نشد.'); return; }
    myAvatar = d.avatar; paintMyAvatar(); showToast('عکسِ پروفایل ذخیره شد.');
}
paintMyAvatar(); loadMyAvatar();

loadRequests();
// زبانه‌ی آخر (بعد از تازه‌سازیِ صفحه) و شمارِ نامه‌های خوانده‌نشده
window.addEventListener('load', () => {
    let t = 'requests'; try { t = sessionStorage.getItem('pt-tab') || 'requests'; } catch (e) {}
    if (location.hash === '#letters') t = 'letters';
    if (location.hash === '#vehicles') t = 'vehicles';
    if (t === 'letters' || t === 'vehicles') showPortalTab(t);
    if (window.PortalLetters) PortalLetters.badge();
});

// ================= نشانگرِ اختصاصیِ موس =================
// دقیقاً همان پیاده‌سازیِ پنل خودمان: همه‌ی نوشتن‌ها یک بار در هر فریم انجام می‌شود،
// حلقه به‌محض رسیدنِ حلقه‌ی بیرونی به موس می‌خوابد (پس در حالت بی‌حرکت هیچ کاری
// نمی‌کند)، و تشخیصِ hover با یک شنونده‌ی واگذارشده روی document است تا دکمه‌هایی
// که بعداً داینامیک ساخته می‌شوند هم جلوه را بگیرند.
(function initCustomCursor() {
    const dot = document.querySelector('.cursor-dot');
    const outline = document.querySelector('.cursor-outline');
    if (!dot || !outline) return;
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    let mx = -100, my = -100, ox = -100, oy = -100, running = false;
    const EASE = 0.35;
    function frame() {
        ox += (mx - ox) * EASE;
        oy += (my - oy) * EASE;
        if (Math.abs(mx - ox) < 0.15 && Math.abs(my - oy) < 0.15) { ox = mx; oy = my; }
        dot.style.setProperty('--cx', mx + 'px');
        dot.style.setProperty('--cy', my + 'px');
        outline.style.setProperty('--ox', ox + 'px');
        outline.style.setProperty('--oy', oy + 'px');
        if (ox === mx && oy === my) { running = false; return; }
        requestAnimationFrame(frame);
    }
    function wake() { if (!running) { running = true; requestAnimationFrame(frame); } }
    window.addEventListener('mousemove', (e) => { mx = e.clientX; my = e.clientY; wake(); }, { passive: true });

    const HOVER_SELECTOR = 'a, button, input, select, textarea, label, summary, [onclick], [role="button"]';
    let hovering = false;
    function setHover(on) {
        if (on === hovering) return;
        hovering = on;
        dot.classList.toggle('is-hover', on);
        outline.classList.toggle('is-hover', on);
    }
    document.addEventListener('mouseover', (e) => {
        setHover(!!(e.target instanceof Element && e.target.closest(HOVER_SELECTOR)));
    }, { passive: true });
    document.addEventListener('mouseout', (e) => { if (!e.relatedTarget) setHover(false); }, { passive: true });
    window.addEventListener('blur', () => setHover(false));
})();
</script>
<script>window.PAGE_GUARD_DETECT = <?= (sec_settings($pdo)['sec_devtools_guard'] ?? '1') === '1' ? 'true' : 'false' ?>;</script><script src="../page-guard.js?v=1"></script>
</body>
</html>
