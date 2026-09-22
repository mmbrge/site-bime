<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// نقش «همکار شرکت‌ها»: فقط بخش شرکت‌ها و گزارش مالی مربوطه را می‌بیند
$isLiaison = ($_SESSION['role'] ?? '') === 'COMPANY_LIAISON';
$canSeeCompanies = $isLiaison || ($_SESSION['role'] ?? '') === 'ADMIN';

$toast_message = '';
$toast_type = '';

// آپدیت پروفایل
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $new_name = trim($_POST['full_name']);
    $new_password = $_POST['new_password'];
    $uid = $_SESSION['user_id'];
    
    if (!empty($new_name)) {
        try {
            if (!empty($new_password)) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$new_name, $hash, $uid]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?");
                $stmt->execute([$new_name, $uid]);
            }
            $_SESSION['full_name'] = $new_name;
            $toast_message = "پروفایل شما با موفقیت بروزرسانی شد!";
            $toast_type = "success";
        } catch (PDOException $e) {
            $toast_message = "خطا در بروزرسانی اطلاعات.";
            $toast_type = "error";
        }
    }
}

// خواندن توکن فعلی ربات برای نمایش در تنظیمات
$bot_token_masked = '';
if (($_SESSION['role'] ?? '') === 'ADMIN') {
    try {
        $stmtToken = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bot_token'");
        $tokenVal = $stmtToken->fetchColumn();
        if ($tokenVal) {
            $parts = explode(':', $tokenVal);
            if (count($parts) == 2) {
                $bot_token_masked = $parts[0] . ':' . substr($parts[1], 0, 4) . '***' . substr($parts[1], -4) . ' (توکن فعلی)';
            } else {
                $bot_token_masked = substr($tokenVal, 0, 8) . '*** (توکن فعلی)';
            }
        }
    } catch (Exception $e) {}
}

// همینطور برای ربات جداگانه‌ی «درخواست‌های شرکتی» (فاز ۲)
$company_bot_token_masked = '';
if (($_SESSION['role'] ?? '') === 'ADMIN') {
    try {
        $stmtToken2 = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_bot_token'");
        $tokenVal2 = $stmtToken2->fetchColumn();
        if ($tokenVal2) {
            $parts2 = explode(':', $tokenVal2);
            if (count($parts2) == 2) {
                $company_bot_token_masked = $parts2[0] . ':' . substr($parts2[1], 0, 4) . '***' . substr($parts2[1], -4) . ' (توکن فعلی)';
            } else {
                $company_bot_token_masked = substr($tokenVal2, 0, 8) . '*** (توکن فعلی)';
            }
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پورتال مدیریت | بیمه با ما</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Regular.woff2') format('woff2'); font-weight: normal; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Bold.woff2') format('woff2'); font-weight: bold; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Black.woff2') format('woff2'); font-weight: 900; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Light.woff2') format('woff2'); font-weight: 300; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Medium.woff2') format('woff2'); font-weight: 500; }
        
        body { font-family: 'Vazir', sans-serif; overflow-x: hidden; color: #334155; margin: 0; }
        
        .hero-bg { position: fixed; inset: 0; z-index: -2; background-image: url('Image/asli1.jpg'); background-size: cover; background-position: center; }
        /* این لایه زیرِ یک پرده‌ی سفیدِ ۸۵٪ است، پس بلورش عملاً دیده نمی‌شد و فقط هزینه داشت */
        .hero-bg::after { content: ""; position: absolute; inset: 0; background: rgba(241, 245, 249, 0.85); z-index: -1; }
        #tsparticles { position: fixed; inset: 0; z-index: -1; pointer-events: none; }

        /* نشانگرِ اختصاصی فقط روی دستگاهی که موسِ واقعی دارد روشن می‌شود؛ روی موبایل و
           تبلت هم بی‌معنی است و هم بی‌خود هزینه دارد (و cursor:none آزاردهنده است). */
        .cursor-dot, .cursor-outline { display: none; }
        @media (hover: hover) and (pointer: fine) {
            * { cursor: none !important; }
            .cursor-dot, .cursor-outline { display: block; }
        }
        /* موقعیتِ نشانگر با متغیرهای CSS (--cx/--cy/--ox/--oy) و transform تنظیم می‌شود، نه
           left/top؛ چون تغییرِ left/top روی هر حرکتِ موس باعثِ layout+paint کامل صفحه
           می‌شود ولی transform فقط composite (GPU) است - همان جلوه، بدون افتِ سرعتِ پنل.
           کلاسِ حالتِ «روی چیزِ قابل‌کلیک» هم روی خودِ همین دو عنصر گذاشته می‌شود، نه روی
           body؛ چون عوض‌کردنِ کلاسِ body باعثِ بازمحاسبه‌ی استایلِ کلِ صفحه می‌شد. */
        .cursor-dot { position: fixed; left: 0; top: 0; width: 8px; height: 8px; background: #3b82f6; border-radius: 50%; pointer-events: none; z-index: 99999999 !important; transform: translate(var(--cx, -100px), var(--cy, -100px)) translate(-50%, -50%); transition: background 0.2s; box-shadow: 0 0 10px rgba(59,130,246,0.5); will-change: transform; }
        .cursor-outline { position: fixed; left: 0; top: 0; width: 24px; height: 24px; border: 2px solid rgba(59, 130, 246, 0.5); border-radius: 50%; pointer-events: none; z-index: 99999998 !important; transform: translate(var(--ox, -100px), var(--oy, -100px)) translate(-50%, -50%); transition: opacity 0.2s; will-change: transform; }
        .cursor-outline.is-hover { transform: translate(var(--ox, -100px), var(--oy, -100px)) translate(-50%, -50%) scale(0.5); opacity: 0; }
        .cursor-dot.is-hover { transform: translate(var(--cx, -100px), var(--cy, -100px)) translate(-50%, -50%) scale(1.8); background: #2563eb; }

        .glass-header { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255,255,255,0.4); box-shadow: 0 4px 30px rgba(0,0,0,0.03); }
        .card { background: rgba(255, 255, 255, 0.94); border-radius: 1.25rem; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid rgba(255,255,255,0.4); }
        
        .win11-menu { background: rgba(20, 20, 20, 0.85); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.15); box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6); border-radius: 12px; color: white; display: none; }
        .win11-menu.active { display: block; animation: popIn 0.1s ease-out forwards; }
        @keyframes popIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .ctx-item { padding: 10px 15px; margin: 2px 6px; border-radius: 8px; font-size: 12px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 10px; transition: all 0.2s; }
        .ctx-item:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .ctx-divider { border-top: 1px solid rgba(255,255,255,0.1); margin: 6px 0; }

        /* دلیلِ اصلیِ کندیِ پنل همین‌جا بود: ۲۵ مودالِ تمام‌صفحه همیشه در DOM هستند و
           فقط opacity:0 دارند. opacity صفر، عنصر را از مدارِ رندر بیرون نمی‌برد - یعنی
           مرورگر ۲۵ ناحیه‌ی backdrop-filter تمام‌صفحه را زنده نگه می‌داشت و هر فریمی که
           نشانگر موس حرکت می‌کرد، همه‌ی آن‌ها دوباره بلور می‌شدند (اندازه‌گیری‌شده:
           ~۱۵۸ میلی‌ثانیه برای هر فریم، یعنی حدود ۶ فریم در ثانیه).
           راه‌حل: visibility:hidden تا مودالِ بسته کاملاً از مدارِ رندر بیرون بماند، و
           backdrop-filter فقط روی مودالی که واقعاً باز است اعمال شود. ظاهر و همان
           محو‌شدن تدریجی دست‌نخورده می‌ماند. */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); z-index: 999999; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; pointer-events: none; transition: opacity 0.3s, visibility 0.3s; }
        /* پلاک، متن ترکیبی فارسی+عدد است که باید دقیقاً به همان ترتیب کاراکترها (چپ‌به‌راست) نمایش داده شود؛
           dir="ltr" به‌تنهایی کافی نیست چون الگوریتم bidi مرورگر رشته‌های فارسی داخلش را دوباره می‌چیند */
        .plate-display { direction: ltr; unicode-bidi: bidi-override; display: inline-block; }
        /* مودالِ باز هم بلورِ پس‌زمینه ندارد: اندازه‌گیری نشان داد فقط وجودِ یک ناحیه‌ی
           backdrop-filter تمام‌صفحه (با هر شعاعی، حتی ۳px) هر فریم ~۳۰ میلی‌ثانیه می‌گیرد
           و پنل را روی ~۲۱ فریم در ثانیه نگه می‌داشت. به‌جایش پرده‌ی تیره کمی غلیظ‌تر شد
           که همان حسِ «جدا شدن از پس‌زمینه» را می‌دهد، با ۶۰ فریم در ثانیه. */
        .modal-overlay.active { opacity: 1; visibility: visible; pointer-events: auto; background: rgba(15, 23, 42, 0.78); transition: opacity 0.3s; }
        .modal-content { background: rgba(255,255,255,0.98); border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.3); transform: scale(0.95); transition: transform 0.3s; overflow: hidden; }
        .modal-overlay.active .modal-content { transform: scale(1); }

        .float-input { position: relative; margin-bottom: 1.5rem; }
        .float-input input, .float-input select { width: 100%; border: 1px solid #cbd5e1; border-radius: 12px; padding: 14px 16px; outline: none; transition: all 0.3s; background: transparent; font-weight: bold; }
        .float-input input:focus, .float-input select:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .float-input label { position: absolute; right: 16px; top: 14px; color: #94a3b8; transition: all 0.3s; pointer-events: none; background: white; padding: 0 4px; border-radius: 4px; font-size: 13px; font-weight: bold; }
        .float-input input:focus ~ label, .float-input input:not(:placeholder-shown) ~ label, .float-input select:focus ~ label, .float-input select:not(:placeholder-shown) ~ label { transform: translateY(-24px) scale(0.9); color: #3b82f6; }

        .footer-credit { display: flex; flex-direction: column; align-items: center; gap: 8px; margin-top: 60px; padding-top: 24px; border-top: 1px solid rgba(148,163,184,0.15); margin-bottom: 20px; }
        .footer-box { background: rgba(255, 255, 255, 0.72); border: 1px solid rgba(255, 255, 255, 0.4); padding: 10px 24px; border-radius: 16px; color: #334155; font-size: 13px; font-weight: bold; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        /* اعداد همه‌جای پنل باید با فونت وزیر باشند، نه فونت پیش‌فرض مونواسپیس تیلویند */
        .font-mono { font-family: 'Vazir', monospace !important; }

        .pulse-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; position: relative; }
        .pulse-dot.online { background-color: #10b981; box-shadow: 0 0 10px #10b981; }
        .pulse-dot.offline { background-color: #ef4444; box-shadow: 0 0 10px #ef4444; }
    
        /* ===== منوی درختی ===== */
        .menu-group { position: relative; }
        .menu-trigger { display: flex; align-items: center; gap: 2px; font-weight: 700; font-size: inherit;
                        color: inherit; background: none; border: none; cursor: pointer; padding: 8px 0;
                        white-space: nowrap; transition: color .15s; }
        .menu-trigger:hover, .menu-group.open .menu-trigger { color: #2563eb; }
        .menu-group.open .menu-trigger .fa-chevron-down { transform: rotate(180deg); }
        .menu-trigger .fa-chevron-down { transition: transform .2s; }
        .menu-panel { display: none; }
        .menu-group.open .menu-panel { display: block; }
        /* جداکننده‌ی داخلِ منو (مثلاً بینِ کارهای روزمره‌ی مالی و «تنظیمات مالی») */
        .menu-sep { height: 1px; background: #e2e8f0; margin: 5px 8px; }

        /* چک‌لیستِ مدارکِ هر ردیف: خانه‌ها کنارِ هم و در یک ردیف پخش می‌شوند، نه
           زیر هم. عرضِ خانه‌ها با auto-fit تنظیم می‌شود تا در پنجره‌ی پهن چند‌تایی
           در یک سطر جا بگیرند و در پنجره‌ی باریک خودشان بشکنند. */
        /* پشتیبانِ محلیِ .hidden: نشان‌دادن/پنهان‌کردنِ فیلدها (مثلاً فیلدهای مخصوصِ
           الحاقیه و فسخ) به این کلاس وابسته است و اگر CDNِ Tailwind نیاید، بدون این
           قاعده همه‌ی فیلدها با هم دیده می‌شوند. !important دارد تا ترتیبِ تزریقِ
           استایلِ Tailwind در زمان اجرا هم مشکلی نسازد. */
        .hidden { display: none !important; }
        .checklist-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(128px, 1fr)); gap: 6px; align-items: start; }
        /* ستونِ چک‌لیست باید بیشترِ عرضِ جدول را بگیرد تا خانه‌هایش در یک سطر کنار هم
           جا شوند؛ بقیه‌ی ستون‌ها باریک و whitespace-nowrap هستند. با درصدِ ثابت
           نوشته شده (نه کلاس‌های Tailwind) تا مستقل از CDN هم درست کار کند. */
        .rows-table { width: 100%; }
        .rows-table .col-checklist { width: 52%; min-width: 430px; }
        /* عرضِ مودالِ جزئیاتِ درخواست - بدون وابستگی به کلاس‌های دلخواهِ Tailwind */
        .creq-wide-modal { width: 96vw; max-width: 1400px; }

        /* اعلان‌ها: گوشه‌ی پایینِ سمتِ راستِ سایت، روی هم انباشته، هرکدام بعد از ۵ ثانیه خودش می‌رود */
        #notif-stack { position: fixed; bottom: 20px; right: 20px; z-index: 9999990; display: flex; flex-direction: column; gap: 8px;
                       max-width: min(340px, calc(100vw - 40px)); pointer-events: none; }
        .notif-card { background: #fff; border: 1px solid #e2e8f0; border-right: 4px solid #3b82f6; border-radius: 14px;
                      padding: 10px 13px; box-shadow: 0 10px 28px rgba(15,23,42,.16); cursor: pointer; pointer-events: auto;
                      opacity: 0; transform: translateX(24px); transition: opacity .3s, transform .3s; }
        .notif-card.show { opacity: 1; transform: translateX(0); }
        .notif-card.t-company_request { border-right-color: #6366f1; }
        .notif-card.t-company_doc     { border-right-color: #14b8a6; }
        .notif-card.t-company_chat    { border-right-color: #f59e0b; }
        .notif-card.t-case            { border-right-color: #3b82f6; }
        .notif-card.t-staff_chat      { border-right-color: #8b5cf6; }
        .notif-card.t-ticket          { border-right-color: #ec4899; }
        .menu-link { display: block; padding: 7px 10px; border-radius: 8px; color: #475569;
                     transition: background .12s, color .12s; white-space: nowrap; }
        .menu-link:hover { background: #eff6ff; color: #2563eb; }
        .menu-link.active-sub { background: #dbeafe; color: #1d4ed8; }
        @media (min-width: 1024px) {
            .menu-panel { position: absolute; top: 100%; right: 0; min-width: 210px; background: #fff;
                          border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 12px 32px rgba(15,23,42,.14);
                          padding: 7px; z-index: 60; }
            /* پلِ نامرئی بینِ سرمنو و پنل: بدون این، حرکتِ موس از سرمنو به سمتِ زیرمنو
               ممکن است از یک شکافِ یک‌پیکسلی رد شود و منو بسته شود */
            .menu-panel::before { content: ""; position: absolute; top: -8px; right: 0; left: 0; height: 8px; }
            /* بازشدن با هاور، بدون نیاز به کلیک (فقط روی دستگاهی که موس واقعی دارد).
               حالتِ کلیکی هم دست‌نخورده می‌ماند تا صفحه‌کلید و لمس هم کار کند. */
            @media (hover: hover) and (pointer: fine) {
                .menu-group:hover .menu-panel { display: block; }
                .menu-group:hover .menu-trigger { color: #2563eb; }
                .menu-group:hover .menu-trigger .fa-chevron-down { transform: rotate(180deg); }
            }
            /* بازشدن با صفحه‌کلید (Tab) هم پشتیبانی می‌شود */
            .menu-group:focus-within .menu-panel { display: block; }
        }
        @media (max-width: 1023px) {
            .menu-panel { padding-right: 14px; border-right: 2px solid #e2e8f0; margin: 2px 6px 6px 0; }
        }
</style>
</head>
<body class="h-screen flex flex-col">

    <div class="hero-bg"></div>
    <div id="tsparticles"></div>
    <div id="notif-stack" aria-live="polite"></div>
    <div class="cursor-dot"></div>
    <div class="cursor-outline"></div>
    <div id="toast-container" class="fixed top-6 left-1/2 -translate-x-1/2 z-[99999999] flex flex-col gap-3 pointer-events-none"></div>

    <!-- منوی راست کلیک -->
    <div id="contextMenu" class="win11-menu fixed z-[9999999] w-56 py-2">
        <div id="ctx-general" class="hidden">
            <div class="ctx-item text-gray-300 hover-target" onclick="ctxExecute('copy')"><i class="far fa-copy w-4 text-center"></i> کپی (Copy)</div>
            <div class="ctx-item text-gray-300 hover-target" onclick="ctxExecute('cut')"><i class="fas fa-cut w-4 text-center"></i> برش (Cut)</div>
            <div class="ctx-item text-gray-300 hover-target" onclick="ctxExecute('paste')"><i class="fas fa-paste w-4 text-center"></i> چسباندن (Paste)</div>
            <div class="ctx-divider"></div>
        </div>
        
        <div id="ctx-row-actions" class="hidden">
            <div class="ctx-item text-blue-400 hover-target" onclick="openEditModal()"><i class="fas fa-edit w-4 text-center"></i> بررسی و ویرایش پرونده</div>
            <div class="ctx-item text-gray-300 hover-target" onclick="ctxExecuteRow('copy-national')"><i class="far fa-copy w-4 text-center"></i> کپی کد ملی</div>
            <div class="ctx-item text-emerald-400 hover-target" onclick="ctxExecuteRow('mark-issued')"><i class="fas fa-check-circle w-4 text-center"></i> علامت‌گذاری «صادر شده»</div>
            <div class="ctx-item text-amber-400 hover-target" onclick="ctxExecuteRow('mark-waiting')"><i class="fas fa-hourglass-half w-4 text-center"></i> علامت‌گذاری «منتظر مدارک»</div>
            <div class="ctx-item text-red-400 hover-target" onclick="confirmDeleteRow()"><i class="fas fa-trash-alt w-4 text-center"></i> حذف پرونده از سیستم</div>
            <div class="ctx-divider"></div>
        </div>

        <div class="ctx-item hover-target" onclick="location.reload()"><i class="fas fa-sync-alt w-4 text-center text-gray-400"></i> بارگذاری مجدد صفحه</div>
        <div class="ctx-item text-red-300 hover-target" onclick="window.location.href='logout.php'"><i class="fas fa-power-off w-4 text-center"></i> خروج امن</div>
    </div>

    <!-- هدر -->
    <header class="glass-header z-40 px-6 py-3 flex justify-between items-center shrink-0 relative">
        <div class="flex items-center gap-4 lg:gap-10">
            <button id="mobile-menu-btn" onclick="toggleMobileMenu()" class="lg:hidden text-slate-600 text-xl hover-target">
                <i class="fas fa-bars"></i>
            </button>
            <div class="flex items-center gap-2.5 font-black text-lg lg:text-xl hover-target bg-gradient-to-l from-blue-600 to-indigo-600 bg-clip-text text-transparent">
                <span class="flex items-center justify-center w-9 h-9 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 shadow-lg shadow-blue-500/30 text-white text-base"><i class="fas fa-shield-check"></i></span>
                <span class="hidden sm:inline tracking-tight">بیمه با ما</span>
            </div>
            <nav id="main-nav" class="hidden lg:flex flex-col lg:flex-row gap-1 lg:gap-5 font-bold text-xs text-slate-500 absolute lg:static top-full right-0 left-0 lg:top-auto bg-white lg:bg-transparent shadow-xl lg:shadow-none p-4 lg:p-0 z-50 max-h-[75vh] overflow-y-auto lg:overflow-visible rounded-b-2xl lg:rounded-none">

                <a href="#" onclick="switchTab('dashboard')" id="nav-dashboard" class="nav-item text-blue-600 hover-target transition-colors block lg:inline py-2 lg:py-0"><i class="fas fa-home ml-1"></i> داشبورد</a>

                <?php if (!$isLiaison): ?>
                <!-- ===== عملیات بیمه: خطِ کارِ پرسنلی، از پرونده تا صدور ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-file-shield ml-1"></i> عملیات بیمه
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                        <span id="ops-badge" class="hidden bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full mr-1"></span>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('records')" id="nav-records" class="nav-item menu-link"><i class="fas fa-folder-open ml-2"></i> مدیریت پرونده‌ها</a>
                        <a href="#" onclick="switchTab('health')" id="nav-health" class="nav-item menu-link"><i class="fas fa-heart-pulse ml-2"></i> بازدید سلامت و مدارک</a>
                        <a href="#" onclick="switchTab('cases')" id="nav-cases" class="nav-item menu-link"><i class="fas fa-file-signature ml-2"></i> صدور بیمه‌نامه</a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($canSeeCompanies): ?>
                <!-- ===== شرکت‌ها: خطِ کارِ شرکتی، کنارِ عملیات بیمه چون هر دو «مسیرِ رسیدن به صدور»اند ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-building ml-1"></i> شرکت‌ها
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('companies-requests')" id="nav-companies-requests" class="nav-item menu-link"><i class="fas fa-file-lines ml-2"></i> درخواست‌های شرکتی</a>
                        <a href="#" onclick="switchTab('companies-inbox')" id="nav-companies-inbox" class="nav-item menu-link"><i class="fas fa-inbox ml-2"></i> صندوق ورودی مدارک</a>
                        <a href="#" onclick="switchTab('companies-finance')" id="nav-companies-finance" class="nav-item menu-link"><i class="fas fa-sack-dollar ml-2"></i> گزارش مالی شرکت‌ها</a>
                        <?php if (!$isLiaison): ?>
                        <a href="#" onclick="switchTab('companies-manage')" id="nav-companies-manage" class="nav-item menu-link"><i class="fas fa-gear ml-2"></i> مدیریت شرکت‌ها</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($canSeeCompanies): ?>
                <!-- ===== صدور و صادره‌ها: لیست صدورِ شرکتی، و فهرست یکپارچه‌ی
                     صادره‌های شرکتی و کارکنان ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-stamp ml-1"></i> صدور و صادره‌ها
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('issue-queue')" id="nav-issue-queue" class="nav-item menu-link"><i class="fas fa-list-check ml-2"></i> لیست صدور</a>
                        <a href="#" onclick="switchTab('issued-list')" id="nav-issued-list" class="nav-item menu-link"><i class="fas fa-file-circle-check ml-2"></i> صادره‌ها</a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ===== حسابداری: همه‌ی مالی یک‌جا، «تنظیمات مالی» هم که قبلاً زیر «سامانه» بود
                     به همین‌جا آمد تا کنارِ بقیه‌ی مالی باشد (فقط مدیر کل می‌بیند) ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-calculator ml-1"></i> حسابداری
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('fin-dashboard')" id="nav-fin-dashboard" class="nav-item menu-link"><i class="fas fa-chart-pie ml-2"></i> داشبورد مالی</a>
                        <a href="#" onclick="switchTab('fin-installments')" id="nav-fin-installments" class="nav-item menu-link"><i class="fas fa-list-ol ml-2"></i> اقساط بیمه‌نامه‌ها</a>
                        <a href="#" onclick="switchTab('fin-invoices')" id="nav-fin-invoices" class="nav-item menu-link"><i class="fas fa-file-invoice ml-2"></i> صورتحساب‌ها</a>
                        <a href="#" onclick="switchTab('fin-payments')" id="nav-fin-payments" class="nav-item menu-link"><i class="fas fa-hand-holding-dollar ml-2"></i> دریافت‌ها و چک‌ها</a>
                        <a href="#" onclick="switchTab('fin-pasargad')" id="nav-fin-pasargad" class="nav-item menu-link"><i class="fas fa-building-columns ml-2"></i> تسویه با پاسارگاد</a>
                        <a href="#" onclick="switchTab('fin-reconcile')" id="nav-fin-reconcile" class="nav-item menu-link"><i class="fas fa-scale-balanced ml-2"></i> مغایرت‌گیری با اکسل</a>
                        <?php if($_SESSION['role'] === 'ADMIN'): ?>
                        <div class="menu-sep"></div>
                        <a href="#" onclick="switchTab('fin-settings')" id="nav-fin-settings" class="nav-item menu-link"><i class="fas fa-money-check-dollar ml-2"></i> تنظیمات مالی</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ===== بایگانی: گروهِ مستقل برای همه (قبلاً زیر «سامانه» بود که جایش نبود،
                     و برای همکار شرکت‌ها یک گروهِ تکراری جدا داشت) ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-archive ml-1"></i> بایگانی
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('filemanager')" id="nav-filemanager" class="nav-item menu-link"><i class="fas fa-folder-tree ml-2"></i> بایگانی فایل‌ها</a>
                    </div>
                </div>

                <?php if (!$isLiaison): ?>
                <!-- ===== ارتباطات و کاربران: هر دو فهرستِ «آدم‌ها» (مشتری‌های ربات و کاربران
                     داخلیِ پنل) کنار هم آمدند؛ قبلاً در دو منوی جدا بودند ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-comments ml-1"></i> ارتباطات و کاربران
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                        <span id="tickets-badge" class="hidden bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full mr-1"></span>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('tickets')" id="nav-tickets" class="nav-item menu-link"><i class="fas fa-comments ml-2"></i> گفتگوها</a>
                        <a href="#" onclick="switchTab('users')" id="nav-users" class="nav-item menu-link"><i class="fas fa-users ml-2"></i> کاربران ربات بله</a>
                        <?php if($_SESSION['role'] === 'ADMIN'): ?>
                        <a href="#" onclick="switchTab('staff-users')" id="nav-staff-users" class="nav-item menu-link"><i class="fas fa-user-shield ml-2"></i> کاربران پنل (داخلی)</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ===== سامانه: فقط چیزهای فنیِ خودِ سیستم ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-sliders ml-1"></i> سامانه
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('queue')" id="nav-queue" class="nav-item menu-link"><i class="fas fa-microchip ml-2"></i> صف پردازش OCR</a>
                        <?php if($_SESSION['role'] === 'ADMIN'): ?>
                        <a href="#" onclick="switchTab('settings')" id="nav-settings" class="nav-item menu-link"><i class="fas fa-cogs ml-2"></i> تنظیمات سیستم</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </nav>
        </div>

        <div class="flex items-center gap-3 lg:gap-5">
            <a href="logout.php" class="text-red-400 hover:text-red-600 transition-colors hover-target text-xl" title="خروج از سیستم"><i class="fas fa-power-off"></i></a>
            <div class="flex items-center gap-3 border-r border-slate-300 pr-5">
                <button onclick="document.getElementById('profile-modal').classList.add('active')" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-blue-100 hover:text-blue-600 flex items-center justify-center transition-all hover-target shadow-sm" title="ویرایش پروفایل"><i class="fas fa-pen text-xs"></i></button>
                <div class="flex flex-col text-right justify-center">
                    <p class="text-sm font-black text-slate-700 leading-tight"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-0.5" dir="ltr"><?php echo htmlspecialchars($_SESSION['role']); ?></p>
                </div>
                <div class="w-11 h-11 bg-gradient-to-tr from-blue-500 to-cyan-400 text-white rounded-full flex items-center justify-center text-xl shadow-md shadow-blue-500/30"><i class="fas fa-user"></i></div>
            </div>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto p-6 lg:p-8 flex flex-col z-10">
        
        <!-- ======================= تب داشبورد و آمار ======================= -->
        <div id="tab-dashboard" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-800">داشبورد و وضعیت هوشمند سیستم</h1>
                    <p class="text-xs text-slate-400 mt-1">خلاصه وضعیت پرونده‌ها، فرآیند صدور و مانیتورینگ زنده ربات بله</p>
                </div>
                
                <div id="bot-status-card" class="card px-4 py-2.5 flex items-center gap-4 border border-slate-200">
                    <div class="flex items-center gap-2">
                        <span id="bot-pulse-dot" class="pulse-dot offline"></span>
                        <span id="bot-status-text" class="text-xs font-bold text-slate-600">استعلام وضعیت...</span>
                    </div>
                    
                    <button id="btn-toggle-bot" onclick="toggleBotPower()" class="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-white text-[11px] font-bold rounded-lg transition-colors hover-target flex items-center gap-1.5">
                        <i class="fas fa-power-off text-xs"></i> <span>تغییر وضعیت</span>
                    </button>
                </div>
            </div>

            <!-- کارت‌های آمار ۴ گانه -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mt-4">
                <div class="card p-5 border-t-4 border-blue-500">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold text-slate-500">کل پرونده‌ها</span>
                        <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-sm"><i class="fas fa-folder"></i></div>
                    </div>
                    <h3 class="text-2xl font-black text-slate-800 mt-2" id="stat-total">۰</h3>
                    <p class="text-[11px] text-slate-400 mt-1">کل معرفی‌نامه‌های دریافتی</p>
                </div>

                <div class="card p-5 border-t-4 border-amber-400">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold text-slate-500">پرونده‌های جدید</span>
                        <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center text-sm"><i class="fas fa-clock"></i></div>
                    </div>
                    <h3 class="text-2xl font-black text-slate-800 mt-2" id="stat-new">۰</h3>
                    <p class="text-[11px] text-slate-400 mt-1">منتظر اولین پیام پرسنل</p>
                </div>

                <div class="card p-5 border-t-4 border-cyan-500">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold text-slate-500">در انتظار تکمیل مدارک</span>
                        <div class="w-8 h-8 rounded-lg bg-cyan-50 text-cyan-600 flex items-center justify-center text-sm"><i class="fas fa-id-card"></i></div>
                    </div>
                    <h3 class="text-2xl font-black text-slate-800 mt-2" id="stat-pending">۰</h3>
                    <p class="text-[11px] text-slate-400 mt-1">در حال گفتگو و ارسال مدرک</p>
                </div>

                <div class="card p-5 border-t-4 border-emerald-500">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold text-slate-500">بیمه‌نامه‌های صادر شده</span>
                        <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <h3 class="text-2xl font-black text-slate-800 mt-2" id="stat-issued">۰</h3>
                    <p class="text-[11px] text-slate-400 mt-1">فرآیند صدور نهایی شده</p>
                </div>
            </div>

            <div class="card p-6 flex flex-col md:flex-row justify-between items-center gap-4 bg-gradient-to-r from-blue-50/50 via-white to-indigo-50/50 mt-6 border border-blue-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-600 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-blue-500/30"><i class="fas fa-bolt"></i></div>
                    <div>
                        <h4 class="text-base font-black text-slate-800">کارتابل معرفی‌نامه‌ها و کنترل مدارک</h4>
                        <p class="text-xs text-slate-500 mt-0.5">مشاهده مشخصات پرسنل، ویرایش، تایید مدارک و تغییر وضعیت بیمه‌ها</p>
                    </div>
                </div>
                <button onclick="switchTab('records')" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl shadow-lg shadow-blue-500/30 transition-all hover-target whitespace-nowrap">
                    ورود به کارتابل پرونده‌ها <i class="fas fa-arrow-left mr-2"></i>
                </button>
            </div>
        </div>

        <!-- ======================= تب مدیریت پرونده‌ها ======================= -->
        <div id="tab-records" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-folder-open text-amber-500 ml-2"></i>کارتابل صدور و پرونده‌ها</h1>
                    <p class="text-xs text-slate-400 mt-1">با کلیک راست روی هر ردیف می‌توانید آن را مدیریت کنید.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="openManualCreateModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold text-sm shadow-md transition-colors hover-target"><i class="fas fa-plus ml-1"></i> ثبت دستی پرونده</button>
                    <button onclick="loadRecords()" class="bg-blue-50 text-blue-600 hover:bg-blue-100 px-4 py-2 rounded-lg font-bold text-sm hover-target transition-colors"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی جدول</button>
                </div>
            </div>

            <div class="card p-3">
                <input type="text" id="records-search-input" placeholder="جستجو با نام، کد ملی، کد پرسنلی، شرکت یا وضعیت..." class="w-full border rounded-lg px-3 py-2 text-xs font-bold outline-none focus:border-amber-500">
            </div>
            
            <div class="card overflow-hidden">
                <div class="overflow-x-auto max-h-[500px]">
                    <table class="w-full text-right">
                        <thead class="bg-slate-50 text-slate-500 text-xs font-bold border-b border-slate-200 sticky top-0 shadow-sm">
                            <tr>
                                <th class="p-4">ردیف</th>
                                <th class="p-4">نام کامل پرسنل</th>
                                <th class="p-4">کد ملی</th>
                                <th class="p-4">کد پرسنلی</th>
                                <th class="p-4">بیمه‌نامه‌های صادرشده</th>
                                <th class="p-4">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="records-body" class="text-sm font-bold divide-y divide-slate-100">
                            <tr><td colspan="6" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin text-xl"></i> در حال بارگذاری پرونده‌ها...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======================= تب صف پردازش OCR ======================= -->
        <!-- ======================= تب کاربران ======================= -->
        <div id="tab-users" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3 mb-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-users text-blue-500 ml-2"></i>کاربران ربات بله</h1>
                    <p class="text-xs text-slate-400 mt-1">کاربرانی که با ربات تعامل داشته یا پرونده دارند.</p>
                </div>
                <div class="flex items-center gap-2 w-full md:w-auto">
                    <input type="text" id="user-search-input" placeholder="جستجو با نام، کد ملی، کد پرسنلی یا شماره تماس..." class="border rounded-lg px-3 py-2 text-xs font-bold flex-1 md:w-72 outline-none focus:border-blue-500">
                    <button onclick="searchUsers()" class="bg-blue-600 text-white px-3 py-2 rounded-lg text-xs font-bold hover:bg-blue-700"><i class="fas fa-search"></i></button>
                    <button onclick="loadUsers()" class="bg-blue-50 text-blue-600 hover:bg-blue-100 px-3 py-2 rounded-lg font-bold text-xs transition-colors hover-target"><i class="fas fa-sync-alt"></i></button>
                </div>
            </div>

            <div class="card overflow-hidden border-blue-100">
                <div class="overflow-x-auto max-h-[600px]">
                    <table class="w-full text-right">
                        <thead class="bg-blue-50 text-blue-700 text-xs font-bold sticky top-0">
                            <tr>
                                <th class="p-4">نام</th>
                                <th class="p-4">کد ملی</th>
                                <th class="p-4">کد پرسنلی</th>
                                <th class="p-4">شرکت</th>
                                <th class="p-4">شماره تماس</th>
                                <th class="p-4">تاریخ عضویت</th>
                                <th class="p-4">وضعیت مکالمه</th>
                                <th class="p-4">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="users-body" class="text-sm font-bold divide-y divide-slate-100">
                            <tr><td colspan="8" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======================= مودال چت مستقیم با کاربر ======================= -->
        <div id="user-chat-modal" class="fixed inset-0 bg-black/50 z-[1200] hidden items-start justify-center pt-24 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-lg h-[640px] flex flex-col overflow-hidden relative">
                <div class="p-4 border-b flex justify-between items-center bg-blue-50">
                    <h3 class="font-bold text-blue-700"><i class="fas fa-comment-dots ml-1"></i> گفتگو با <span id="user-chat-name"></span></h3>
                    <button onclick="document.getElementById('user-chat-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                </div>
                <div id="user-chat-body" class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50"></div>
                <div id="user-emoji-panel" class="hidden absolute bottom-16 right-3 bg-white border rounded-xl shadow-lg p-2 grid grid-cols-8 gap-1 z-10"></div>
                <div class="px-3 pt-2 flex gap-1 border-t">
                    <button id="dest-tab-bot" onclick="setUserMsgDest('bot')" class="flex-1 text-[11px] font-bold py-1.5 rounded-lg bg-blue-600 text-white">ارسال به ربات کاربر</button>
                    <button id="dest-tab-app" onclick="setUserMsgDest('app')" class="flex-1 text-[11px] font-bold py-1.5 rounded-lg bg-slate-100 text-slate-600">ارسال به مینی‌اپ کاربر</button>
                </div>
                <div class="p-3 flex gap-2 items-center">
                    <button onclick="toggleEmojiPanel('user-emoji-panel')" class="text-slate-400 hover:text-amber-500 text-lg"><i class="fas fa-face-smile"></i></button>
                    <label class="text-slate-400 hover:text-blue-500 text-lg cursor-pointer">
                        <i class="fas fa-paperclip"></i>
                        <input type="file" id="user-chat-file" class="hidden" onchange="sendUserFile()">
                    </label>
                    <input type="text" id="user-chat-input" placeholder="پیام خود را بنویسید..." class="flex-1 border rounded-xl px-3 py-2 text-sm">
                    <button onclick="sendUserMessage()" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-blue-700">ارسال</button>
                </div>
                <input type="hidden" id="user-chat-person-id">
            </div>
        </div>

        <!-- ======================= تب گفتگوها (تیکت پشتیبانی) ======================= -->
        <div id="tab-tickets" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-comments text-emerald-500 ml-2"></i>گفتگوهای پشتیبانی</h1>
                    <p class="text-xs text-slate-400 mt-1">پیام‌های کاربرانی که از «ارتباط با کارشناس» استفاده کرده‌اند.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="openNewChatPicker()" class="bg-emerald-600 text-white hover:bg-emerald-700 px-4 py-2 rounded-lg font-bold text-sm transition-colors hover-target"><i class="fas fa-plus ml-1"></i> گفتگوی جدید</button>
                    <button onclick="loadTickets()" class="bg-emerald-50 text-emerald-600 hover:bg-emerald-100 px-4 py-2 rounded-lg font-bold text-sm transition-colors hover-target"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
                </div>
            </div>

            <div class="flex gap-2 mb-4 flex-wrap">
                <button onclick="switchGoftegoSub('tickets')" id="goftego-sub-tickets" class="px-4 py-2 rounded-lg text-xs font-bold bg-emerald-600 text-white">🎫 تیکت‌های پشتیبانی</button>
                <button onclick="switchGoftegoSub('botchats')" id="goftego-sub-botchats" class="px-4 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-500">🤖 چت‌های ربات (آرشیو کامل)</button>
                <button onclick="switchGoftegoSub('staffchat')" id="goftego-sub-staffchat" class="px-4 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-500">👥 چت داخلی</button>
                <?php if ($canSeeCompanies): ?>
                <button onclick="switchGoftegoSub('companychat')" id="goftego-sub-companychat" class="px-4 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-500">🏢 چت با شرکت‌ها</button>
                <?php endif; ?>
            </div>

            <div id="goftego-panel-tickets">
            <div class="card overflow-hidden border-emerald-100 flex relative" style="height: 640px;">
                <div class="w-1/3 border-l flex flex-col">
                    <div class="p-2 border-b"><input type="text" id="ticket-search-input" placeholder="جستجو با نام، کد ملی یا متن پیام..." class="w-full border rounded-lg px-3 py-1.5 text-xs font-bold outline-none focus:border-emerald-500"></div>
                    <div class="flex-1 overflow-y-auto" id="tickets-list"></div>
                </div>
                <div class="w-2/3 flex flex-col relative">
                    <div id="ticket-chat-header" class="p-4 border-b bg-emerald-50 font-bold text-emerald-700 hidden">
                        <span id="ticket-chat-title"></span>
                        <button onclick="closeCurrentTicket()" class="float-left bg-red-100 text-red-600 px-3 py-1 rounded-lg text-xs hover:bg-red-200">بستن گفتگو</button>
                    </div>
                    <div id="ticket-chat-body" class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50">
                        <p class="text-center text-slate-400 text-sm mt-10">یک گفتگو را از لیست انتخاب کنید.</p>
                    </div>
                    <div id="ticket-typing-indicator" class="hidden text-[11px] text-slate-400 px-4 pb-1">کاربر در حال نوشتن است...</div>
                    <div id="ticket-emoji-panel" class="hidden absolute bottom-16 right-3 bg-white border rounded-xl shadow-lg p-2 grid grid-cols-8 gap-1 z-10"></div>
                    <div id="ticket-chat-input-row" class="p-3 border-t flex gap-2 items-center hidden">
                        <button onclick="toggleEmojiPanel('ticket-emoji-panel')" class="text-slate-400 hover:text-amber-500 text-lg"><i class="fas fa-face-smile"></i></button>
                        <label class="text-slate-400 hover:text-emerald-500 text-lg cursor-pointer">
                            <i class="fas fa-paperclip"></i>
                            <input type="file" id="ticket-chat-file" class="hidden" onchange="sendTicketFile()">
                        </label>
                        <input type="text" id="ticket-chat-input" placeholder="پاسخ خود را بنویسید..." class="flex-1 border rounded-xl px-3 py-2 text-sm" oninput="onAdminTyping()">
                        <button onclick="sendTicketReply()" class="bg-emerald-600 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-emerald-700">ارسال</button>
                    </div>
                </div>
            </div>
            </div>

            <div id="goftego-panel-botchats" class="hidden">
                <div class="card overflow-hidden border-slate-200">
                    <div class="p-3 border-b">
                        <input type="text" id="botchat-search-input" placeholder="جستجو با نام، کد ملی یا شماره تماس..." class="border rounded-lg px-3 py-2 text-xs font-bold w-full md:w-80 outline-none focus:border-emerald-500">
                    </div>
                    <div class="overflow-x-auto max-h-[560px]">
                        <table class="w-full text-right">
                            <thead class="bg-slate-50 text-slate-600 text-xs font-bold sticky top-0">
                                <tr><th class="p-4">نام</th><th class="p-4">کد ملی</th><th class="p-4">شماره تماس</th><th class="p-4">وضعیت فعلی</th><th class="p-4">عملیات</th></tr>
                            </thead>
                            <tbody id="botchats-body" class="text-sm font-bold divide-y divide-slate-100">
                                <tr><td colspan="5" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="goftego-panel-staffchat" class="hidden">
                <div class="card overflow-hidden border-blue-100 flex relative" style="height: 640px;">
                    <div class="w-1/3 border-l flex flex-col overflow-y-auto" id="staffchat-list"></div>
                    <div class="w-2/3 flex flex-col relative">
                        <div id="staffchat-header" class="p-4 border-b bg-blue-50 font-bold text-blue-700 hidden"><span id="staffchat-title"></span></div>
                        <div id="staffchat-body" class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50">
                            <p class="text-center text-slate-400 text-sm mt-10">یک همکار را از لیست انتخاب کنید.</p>
                        </div>
                        <div id="staffchat-input-row" class="p-3 border-t flex gap-2 items-center hidden">
                            <label class="text-slate-400 hover:text-blue-500 text-lg cursor-pointer">
                                <i class="fas fa-paperclip"></i>
                                <input type="file" id="staffchat-file" class="hidden" onchange="sendStaffChatFile()">
                            </label>
                            <input type="text" id="staffchat-input" placeholder="پیام خود را بنویسید..." class="flex-1 border rounded-xl px-3 py-2 text-sm">
                            <button onclick="sendStaffChatMessage()" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-blue-700">ارسال</button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($canSeeCompanies): ?>
            <div id="goftego-panel-companychat" class="hidden">
                <div class="card overflow-hidden border-cyan-100 flex relative" style="height: 640px;">
                    <div class="w-1/3 border-l flex flex-col">
                        <div class="p-2 border-b bg-white">
                            <input type="text" id="companychat-search" oninput="debouncedCompanyChatSearch()" placeholder="جستجوی نام شخص یا شرکت..." class="w-full border rounded-xl px-3 py-2 text-xs">
                        </div>
                        <div class="flex-1 overflow-y-auto" id="companychat-list"></div>
                    </div>
                    <div class="w-2/3 flex flex-col relative">
                        <div id="companychat-header" class="p-4 border-b bg-cyan-50 font-bold text-cyan-700 hidden"><span id="companychat-title"></span></div>
                        <div id="companychat-body" class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50">
                            <p class="text-center text-slate-400 text-sm mt-10">یک شرکت را از لیست انتخاب کنید.</p>
                        </div>
                        <div id="companychat-input-row" class="p-3 border-t flex gap-2 items-center hidden">
                            <label class="text-slate-400 hover:text-cyan-500 text-lg cursor-pointer">
                                <i class="fas fa-paperclip"></i>
                                <input type="file" id="companychat-file" class="hidden" onchange="sendCompanyChatFile()">
                            </label>
                            <input type="text" id="companychat-input" placeholder="پیام خود را بنویسید..." class="flex-1 border rounded-xl px-3 py-2 text-sm">
                            <button onclick="sendCompanyChatMessage()" class="bg-cyan-600 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-cyan-700">ارسال</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>


        <!-- ======================= تب صدور بیمه‌نامه ======================= -->
        <div id="tab-cases" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3 mb-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-file-signature text-indigo-500 ml-2"></i>صدور بیمه‌نامه</h1>
                    <p class="text-xs text-slate-400 mt-1">پرونده‌های صدور بیمه (ثالث/بدنه) و بررسی مدارک هر پرونده.</p>
                </div>
                <button onclick="loadCases()" class="bg-indigo-50 text-indigo-600 hover:bg-indigo-100 px-4 py-2 rounded-lg font-bold text-sm transition-colors hover-target"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
            </div>

            <div class="card p-4 border-indigo-100 grid grid-cols-2 md:grid-cols-5 gap-2">
                <input type="text" id="case-search-code" placeholder="شناسه پرونده" class="border rounded-lg px-3 py-2 text-xs" dir="ltr">
                <input type="text" id="case-search-plate" placeholder="پلاک" class="border rounded-lg px-3 py-2 text-xs" dir="ltr">
                <input type="text" id="case-search-name" placeholder="نام بیمه‌گذار / دارنده" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="case-search-nid" placeholder="کد ملی" class="border rounded-lg px-3 py-2 text-xs" dir="ltr">
                <select id="case-search-status" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="REGISTERED">ثبت شده</option>
                    <option value="DOCS_PENDING">در انتظار اصلاح</option>
                    <option value="DOCS_REVIEW">در انتظار تایید مدارک</option>
                    <option value="ISSUING">در حال صدور</option>
                    <option value="ISSUED">صادر شده</option>
                </select>
            </div>

            <div class="card overflow-hidden border-indigo-100">
                <div class="overflow-x-auto max-h-[600px]">
                    <table class="w-full text-right">
                        <thead class="bg-indigo-50 text-indigo-700 text-xs font-bold sticky top-0">
                            <tr>
                                <th class="p-4">شناسه پرونده</th>
                                <th class="p-4">بیمه‌گذار</th>
                                <th class="p-4">نوع</th>
                                <th class="p-4">پلاک</th>
                                <th class="p-4">مدارک</th>
                                <th class="p-4">وضعیت</th>
                                <th class="p-4">تاریخ ثبت</th>
                                <th class="p-4">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="cases-body" class="text-sm font-bold divide-y divide-slate-100">
                            <tr><td colspan="8" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======================= تب بازدید سلامت خودرو ======================= -->
        <div id="tab-health" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-heart-pulse text-rose-500 ml-2"></i>بازدید سلامت و مدارک</h1>
                    <p class="text-xs text-slate-400 mt-1">بررسی و تایید مدارک ارسالی و بازدیدهای ۱۴مرحله‌ای، قبل از رفتن به مرحله‌ی صدور.</p>
                </div>
                <button onclick="loadHealthTab(); loadDocsReviewList();" class="bg-rose-50 text-rose-600 hover:bg-rose-100 px-4 py-2 rounded-lg font-bold text-sm transition-colors hover-target"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
            </div>

            <div class="card p-4 border-indigo-100">
                <h2 class="font-bold text-indigo-700 text-sm mb-3"><i class="fas fa-file-lines ml-1"></i>مدارک در انتظار بررسی</h2>
                <div id="docs-review-body" class="space-y-2"></div>
            </div>

            <div class="card p-4 border-rose-100">
                <h2 class="font-bold text-rose-700 text-sm mb-3"><i class="fas fa-car ml-1"></i>بازدیدهای سلامت خودرو</h2>
                <div id="health-tab-body" class="space-y-3"></div>
            </div>
        </div>

        <!-- ======================= مودال جزئیات/بررسی پرونده ======================= -->
        <div id="case-detail-modal" class="fixed inset-0 bg-black/50 z-[999] hidden items-start justify-center pt-24 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-3xl max-h-[85vh] flex flex-col overflow-hidden">
                <div class="p-4 border-b flex justify-between items-center bg-indigo-50">
                    <h3 class="font-bold text-indigo-700"><i class="fas fa-folder-open ml-1"></i> پرونده <span id="case-detail-code"></span></h3>
                    <button onclick="document.getElementById('case-detail-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                </div>
                <div id="case-detail-body" class="flex-1 overflow-y-auto p-5 space-y-4"></div>
            </div>
        </div>

        <!-- ======================= مودال جزئیات پرونده (سطح معرفی‌نامه) - نمای تجمیعی همه‌ی بیمه‌نامه‌ها ======================= -->
        <div id="intro-detail-modal" class="fixed inset-0 bg-black/50 z-[998] hidden items-start justify-center pt-24 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
                <div class="p-4 border-b flex justify-between items-center bg-amber-50">
                    <h3 class="font-bold text-amber-700"><i class="fas fa-id-card ml-1"></i> پرونده <span id="intro-detail-name"></span></h3>
                    <button onclick="document.getElementById('intro-detail-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                </div>
                <div id="intro-detail-body" class="flex-1 overflow-y-auto p-5 space-y-3"></div>
            </div>
        </div>

        <!-- ======================= مودال دلیل رد مدرک ======================= -->
        <!-- ======================= مودال بزرگ‌نمایی عکس ======================= -->
        <!-- صدور صورتحساب جدید -->
        <div id="inv-new-modal" class="fixed inset-0 bg-black/50 z-[1100] hidden items-start justify-center pt-20 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-3xl">
                <div class="p-4 border-b flex justify-between items-center bg-blue-50">
                    <h3 class="font-bold text-blue-700"><i class="fas fa-file-invoice ml-1"></i> صدور صورتحساب</h3>
                    <button onclick="document.getElementById('inv-new-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                </div>
                <div class="p-4 space-y-3">
                    <div class="grid grid-cols-3 gap-2">
                        <label class="border rounded-xl p-3 cursor-pointer hover:bg-blue-50 flex gap-2 items-start">
                            <input type="radio" name="inv-kind" value="SUMMARY" onchange="onInvKindChange()" class="mt-1">
                            <span><b class="text-xs">تجمیعی</b><br><span class="text-[10px] text-slate-500">یک سطر برای هر شرکت</span></span>
                        </label>
                        <label class="border rounded-xl p-3 cursor-pointer hover:bg-blue-50 flex gap-2 items-start">
                            <input type="radio" name="inv-kind" value="PERSONNEL" onchange="onInvKindChange()" class="mt-1">
                            <span><b class="text-xs">تلفیقی (ریز پرسنل)</b><br><span class="text-[10px] text-slate-500">هر بیمه‌نامه یک سطر، بدون پلاک</span></span>
                        </label>
                        <label class="border rounded-xl p-3 cursor-pointer hover:bg-blue-50 flex gap-2 items-start">
                            <input type="radio" name="inv-kind" value="DETAILED" onchange="onInvKindChange()" class="mt-1" checked>
                            <span><b class="text-xs">تفکیکی (یک شرکت)</b><br><span class="text-[10px] text-slate-500">با پلاک و شماره بیمه‌نامه</span></span>
                        </label>
                    </div>
                    <div class="flex gap-3 items-end flex-wrap">
                        <div><label class="text-[10px] text-slate-500 block mb-1">دوره</label><select id="inv-new-period" class="border rounded-lg px-3 py-2 text-xs"></select></div>
                        <div id="inv-company-wrap"><label class="text-[10px] text-slate-500 block mb-1">شرکت</label><select id="inv-new-company" class="border rounded-lg px-3 py-2 text-xs"></select></div>
                        <div id="inv-manual-wrap" style="display:none;">
                            <label class="text-[10px] text-slate-500 block mb-1">نام شرکتِ مخاطبِ نامه (دستی)</label>
                            <input type="text" id="inv-manual-company" class="border rounded-lg px-3 py-2 text-xs" placeholder="مثلاً دنیای ماموت">
                        </div>
                        <button onclick="previewInvoice()" class="bg-slate-700 text-white px-4 py-2 rounded-lg text-xs font-bold">پیش‌نمایش</button>
                    </div>
                    <div id="inv-new-preview"></div>
                </div>
            </div>
        </div>

        <!-- ثبت دریافت جدید -->
        <div id="pay-new-modal" class="fixed inset-0 bg-black/50 z-[1100] hidden items-start justify-center pt-20 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-xl">
                <div class="p-4 border-b flex justify-between items-center bg-emerald-50">
                    <h3 class="font-bold text-emerald-700"><i class="fas fa-hand-holding-dollar ml-1"></i> ثبت دریافت از شرکت</h3>
                    <button onclick="document.getElementById('pay-new-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                </div>
                <form id="pay-new-form" class="p-4 space-y-3" onsubmit="return false;">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[10px] text-slate-500 block mb-1">شرکت *</label>
                            <select name="company_id" id="pay-new-company" onchange="loadPayInvoices()" class="border rounded-lg px-3 py-2 text-xs w-full"></select>
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-500 block mb-1">صورتحساب مرتبط</label>
                            <select name="invoice_id" id="pay-invoice" class="border rounded-lg px-3 py-2 text-xs w-full"></select>
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-500 block mb-1">مبلغ (ریال) *</label>
                            <input type="text" name="amount" class="border rounded-lg px-3 py-2 text-xs w-full" dir="ltr" placeholder="مثلاً 50000000">
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-500 block mb-1">روش پرداخت</label>
                            <select name="method" id="pay-method" onchange="onPayMethodChange()" class="border rounded-lg px-3 py-2 text-xs w-full">
                                <option value="TRANSFER">واریز / حواله</option>
                                <option value="CHEQUE">چک</option>
                                <option value="CASH">نقدی</option>
                                <option value="PAYROLL">کسر از حقوق</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-500 block mb-1">تاریخ پرداخت (شمسی)</label>
                            <input type="text" name="paid_jalali" class="border rounded-lg px-3 py-2 text-xs w-full" dir="ltr" placeholder="۱۴۰۵/۰۷/۱۵">
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-500 block mb-1">شماره فیش / پیگیری</label>
                            <input type="text" name="reference_no" class="border rounded-lg px-3 py-2 text-xs w-full" dir="ltr">
                        </div>
                    </div>

                    <div id="pay-cheque-fields" class="grid-cols-3 gap-3" style="display:none;">
                        <div><label class="text-[10px] text-slate-500 block mb-1">شماره چک</label>
                            <input type="text" name="cheque_no" class="border rounded-lg px-3 py-2 text-xs w-full" dir="ltr"></div>
                        <div><label class="text-[10px] text-slate-500 block mb-1">بانک</label>
                            <input type="text" name="bank_name" class="border rounded-lg px-3 py-2 text-xs w-full"></div>
                        <div><label class="text-[10px] text-slate-500 block mb-1">سررسید چک</label>
                            <input type="text" name="cheque_due" class="border rounded-lg px-3 py-2 text-xs w-full" dir="ltr" placeholder="۱۴۰۵/۰۸/۱۰"></div>
                    </div>

                    <div>
                        <label class="text-[10px] text-slate-500 block mb-1">توضیحات</label>
                        <textarea name="note" rows="2" class="border rounded-lg px-3 py-2 text-xs w-full"></textarea>
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 block mb-1">فیش‌ها / تصاویر (چند فایل مجاز است)</label>
                        <input type="file" name="receipts[]" multiple accept="image/*,application/pdf" class="text-xs w-full">
                    </div>
                    <p class="text-[10px] text-slate-400">مبلغ به‌صورت خودکار و به ترتیب سررسید به اقساط باز این شرکت تخصیص می‌یابد.</p>
                    <button type="button" onclick="submitPayment()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl text-xs">
                        <i class="fas fa-check ml-1"></i> ثبت دریافت و تخصیص خودکار
                    </button>
                </form>
            </div>
        </div>

        <!-- جزئیات صورتحساب -->
        <div id="inv-detail-modal" class="fixed inset-0 bg-black/50 z-[1100] hidden items-start justify-center pt-20 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-3xl">
                <div class="p-4 border-b flex justify-between items-center bg-slate-50">
                    <h3 class="font-bold text-slate-700">صورتحساب <span id="inv-detail-title" dir="ltr"></span></h3>
                    <button onclick="document.getElementById('inv-detail-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                </div>
                <div id="inv-detail-body" class="p-4"></div>
            </div>
        </div>

        <!-- انتخاب کاربر برای شروع گفتگوی جدید (جستجو با نام / کد ملی / کد پرسنلی) -->
        <div id="new-chat-modal" class="fixed inset-0 bg-black/50 z-[1300] hidden items-start justify-center pt-24 px-4 pb-4">
            <div class="bg-white rounded-2xl w-full max-w-md flex flex-col overflow-hidden" style="max-height:70vh;">
                <div class="p-4 border-b flex justify-between items-center bg-emerald-50">
                    <h3 class="font-bold text-emerald-700"><i class="fas fa-user-plus ml-1"></i> شروع گفتگوی جدید</h3>
                    <button onclick="document.getElementById('new-chat-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                </div>
                <div class="p-3 border-b">
                    <input type="text" id="new-chat-search" placeholder="جستجو با نام، کد ملی یا کد پرسنلی..." class="w-full border rounded-xl px-3 py-2 text-sm" oninput="searchUsersForChat()">
                    <p class="text-[10px] text-slate-400 mt-1">پیام این گفتگو فقط به مینی‌اپ کاربر («ارتباط با کارشناس») ارسال می‌شود.</p>
                </div>
                <div id="new-chat-results" class="flex-1 overflow-y-auto p-3 space-y-2"></div>
            </div>
        </div>

        <div id="image-zoom-modal" class="fixed inset-0 bg-black/80 z-[99999] hidden items-center justify-center p-4 overflow-hidden" onclick="if(event.target===this) closeImageZoom()">
            <div class="absolute top-4 left-4 flex gap-2 z-10">
                <button onclick="event.stopPropagation(); zoomImage(0.25)" class="w-9 h-9 bg-white/20 hover:bg-white/30 text-white rounded-lg text-lg">+</button>
                <button onclick="event.stopPropagation(); zoomImage(-0.25)" class="w-9 h-9 bg-white/20 hover:bg-white/30 text-white rounded-lg text-lg">−</button>
                <button onclick="event.stopPropagation(); closeImageZoom()" class="w-9 h-9 bg-white/20 hover:bg-white/30 text-white rounded-lg"><i class="fas fa-times"></i></button>
            </div>
            <img id="image-zoom-content" src="" class="rounded-lg select-none" style="max-width:100%; max-height:100%; transition: transform .15s; cursor: grab;" onwheel="event.stopPropagation(); onImageZoomWheel(event)" onmousedown="onImageZoomDragStart(event)" ondblclick="event.stopPropagation(); resetImageZoom()">
        </div>

        <div id="reject-doc-modal" class="fixed inset-0 bg-black/50 z-[9999] hidden items-start justify-center pt-24 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-sm p-6">
                <h3 id="reject-modal-title" class="font-bold text-red-600 mb-3"><i class="fas fa-times-circle ml-1"></i> دلیل رد</h3>
                <textarea id="reject-reason-input" rows="3" class="w-full border rounded-xl p-3 text-sm mb-4" placeholder="مثلاً: عکس واضح نیست، لطفاً مجدد ارسال کنید"></textarea>
                <div class="flex gap-2">
                    <button onclick="confirmRejectGeneric()" class="flex-1 bg-red-500 hover:bg-red-600 text-white font-bold py-2.5 rounded-xl text-xs">ثبت رد</button>
                    <button onclick="document.getElementById('reject-doc-modal').classList.add('hidden')" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl text-xs">انصراف</button>
                </div>
                <input type="hidden" id="reject-doc-id">
                <input type="hidden" id="reject-doc-type" value="doc">
            </div>
        </div>

        <div id="tab-queue" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-microchip text-purple-500 ml-2"></i>صف پردازش هوشمند</h1>
                    <p class="text-xs text-slate-400 mt-1">مدارک ارسالی در این بخش توسط هوش مصنوعی خوانده شده و منتظر تایید شما هستند.</p>
                </div>
                <button onclick="loadQueue()" class="bg-purple-50 text-purple-600 hover:bg-purple-100 px-4 py-2 rounded-lg font-bold text-sm transition-colors hover-target"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی صف</button>
            </div>

            <div class="card p-3">
                <input type="text" id="queue-search-input" placeholder="جستجو با کد ملی، نام شخص، نام فایل، فرستنده یا نام گروه..." class="w-full border rounded-lg px-3 py-2 text-xs font-bold outline-none focus:border-purple-500">
            </div>
            
            <div class="card overflow-hidden border-purple-100">
                <div class="overflow-x-auto max-h-[500px]">
                    <table class="w-full text-right">
                        <thead class="bg-purple-50 text-purple-700 text-xs font-bold sticky top-0">
                            <tr>
                                <th class="p-4">شناسه صف</th>
                                <th class="p-4">نام فایل</th>
                                <th class="p-4">فرستنده</th>
                                <th class="p-4">نام / کد ملی شناسایی‌شده</th>
                                <th class="p-4">زمان بارگذاری</th>
                                <th class="p-4">وضعیت هوش مصنوعی</th>
                                <th class="p-4">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="queue-body" class="text-sm font-bold divide-y divide-slate-100">
                            <tr><td colspan="7" class="text-center p-8 text-slate-400">در حال بررسی صف پردازش...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======================= تب بایگانی فایل‌ها ======================= -->
        <div id="tab-filemanager" class="tab-content max-w-7xl mx-auto w-full space-y-4 flex-1 hidden">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-folder-tree text-emerald-500 ml-2"></i>بایگانی و مدیریت فایل‌ها</h1>
                    <p class="text-xs text-slate-400 mt-1">مرور پوشه‌ها، دانلود تکی یا فشرده، و خروجی‌گیری بر اساس بازه‌ی زمانی.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span id="fm-sync-indicator" class="text-[10px] text-emerald-500 flex items-center gap-1"><i class="fas fa-circle text-[6px] animate-pulse"></i>همگام‌سازی خودکار فعال</span>
                    <button onclick="fmRefresh()" class="px-3 py-2 bg-emerald-50 text-emerald-600 hover:bg-emerald-100 rounded-lg text-xs font-bold transition-all hover-target"><i class="fas fa-sync-alt ml-1"></i>همگام‌سازی</button>
                </div>
            </div>

            <!-- نوار جستجو + خروجی بازه‌ی زمانی -->
            <div class="card p-4 grid grid-cols-1 md:grid-cols-4 gap-2 items-center">
                <div class="relative md:col-span-2">
                    <input type="text" id="fm-search-input" placeholder="جستجوی نام شخص، پلاک، شرکت یا فایل..." class="w-full border rounded-lg pl-3 pr-9 py-2 text-xs font-bold outline-none focus:border-emerald-500">
                    <i class="fas fa-search absolute right-3 top-2.5 text-slate-300 text-xs"></i>
                </div>
                <input type="text" id="fm-from" placeholder="از تاریخ (۱۴۰۵/۰۱/۰۱)" dir="ltr" class="border rounded-lg px-3 py-2 text-xs font-bold">
                <div class="flex gap-2">
                    <input type="text" id="fm-to" placeholder="تا تاریخ (۱۴۰۵/۱۲/۲۹)" dir="ltr" class="flex-1 border rounded-lg px-3 py-2 text-xs font-bold">
                    <button onclick="fmDownloadRangeZip()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg text-xs font-bold whitespace-nowrap"><i class="fas fa-file-zipper ml-1"></i>دانلود بازه</button>
                </div>
            </div>

            <!-- مسیر (breadcrumb) -->
            <div id="fm-breadcrumb" class="flex items-center gap-1 flex-wrap text-xs font-bold text-slate-500 bg-white rounded-xl px-4 py-3 border border-slate-100"></div>

            <!-- محتوای پوشه -->
            <div id="fm-content" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div class="col-span-full card p-10 text-center text-slate-400"><i class="fas fa-spinner fa-spin text-2xl mb-2"></i></div>
            </div>

            <!-- نتایج جستجو (وقتی جستجو فعاله) -->
            <div id="fm-search-results" class="hidden space-y-2"></div>
        </div>


        <!-- ======================= داشبورد مالی ======================= -->
        <div id="tab-fin-dashboard" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-chart-pie text-emerald-500 ml-2"></i>داشبورد مالی</h1>
                    <p class="text-xs text-slate-400 mt-1">نمای کلی بدهی به پاسارگاد و طلب از شرکت‌ها</p>
                </div>
                <select id="fin-dash-period" onchange="loadFinDashboard()" class="border rounded-lg px-3 py-2 text-xs font-bold"></select>
            </div>
            <div id="fin-dash-cards" class="grid grid-cols-2 md:grid-cols-4 gap-4"></div>
            <div class="card p-4">
                <h3 class="font-bold text-sm text-slate-700 mb-3"><i class="fas fa-triangle-exclamation text-amber-500 ml-1"></i> اقساط سررسیدگذشته و پرداخت‌نشده</h3>
                <div id="fin-dash-overdue" class="overflow-x-auto"></div>
            </div>
            <div class="card p-4">
                <h3 class="font-bold text-sm text-slate-700 mb-3"><i class="fas fa-building ml-1"></i> وضعیت شرکت‌ها در این دوره</h3>
                <div id="fin-dash-companies" class="overflow-x-auto"></div>
            </div>
        </div>

        <!-- ======================= اقساط بیمه‌نامه‌ها ======================= -->
        <div id="tab-fin-installments" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-list-ol text-indigo-500 ml-2"></i>اقساط بیمه‌نامه‌ها</h1>
                    <p class="text-xs text-slate-400 mt-1">بدهی ما به پاسارگاد؛ وضعیت پرداخت هر قسط از سوی شرکت‌ها</p>
                </div>
                <button onclick="loadFinInstallments()" class="bg-indigo-50 text-indigo-600 hover:bg-indigo-100 px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
            </div>
            <div class="card p-4 flex flex-wrap gap-3 items-end">
                <div><label class="text-[10px] text-slate-500 block mb-1">دوره</label><select id="inst-f-period" class="border rounded-lg px-3 py-2 text-xs"></select></div>
                <div><label class="text-[10px] text-slate-500 block mb-1">شرکت</label><select id="inst-f-company" class="border rounded-lg px-3 py-2 text-xs"></select></div>
                <div><label class="text-[10px] text-slate-500 block mb-1">وضعیت</label>
                    <select id="inst-f-status" class="border rounded-lg px-3 py-2 text-xs">
                        <option value="">همه</option><option value="UNPAID">پرداخت‌نشده</option>
                        <option value="PARTIAL">ناقص</option><option value="PAID">تسویه‌شده</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[180px]"><label class="text-[10px] text-slate-500 block mb-1">جستجو (نام/پلاک/بیمه‌نامه)</label>
                    <input type="text" id="inst-f-q" class="border rounded-lg px-3 py-2 text-xs w-full" oninput="debouncedInstallments()"></div>
                <button onclick="loadFinInstallments()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold">اعمال فیلتر</button>
                <a id="inst-export" href="#" onclick="exportInstallments(event)" class="bg-emerald-50 text-emerald-700 px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-file-excel ml-1"></i>خروجی اکسل</a>
            </div>
            <div class="card p-0 overflow-x-auto"><div id="fin-installments-body"></div></div>
        </div>

        <!-- ======================= مغایرت‌گیری ======================= -->
        <div id="tab-fin-reconcile" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div>
                <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-scale-balanced text-amber-500 ml-2"></i>مغایرت‌گیری با اکسل پاسارگاد</h1>
                <p class="text-xs text-slate-400 mt-1">پیش از صدور صورتحساب، فهرست پاسارگاد را با اطلاعات پنل تطبیق دهید.</p>
            </div>
            <div class="card p-5">
                <div class="flex flex-wrap gap-3 items-end">
                    <div><label class="text-[10px] text-slate-500 block mb-1">دوره</label><select id="rec-period" class="border rounded-lg px-3 py-2 text-xs"></select></div>
                    <label class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-lg text-xs font-bold cursor-pointer">
                        <i class="fas fa-file-excel ml-1"></i> انتخاب فایل اکسل و بررسی
                        <input type="file" id="rec-file" class="hidden" accept=".xlsx,.xls,.csv" onchange="runReconcile()">
                    </label>
                </div>
                <p class="text-[10px] text-slate-400 mt-3">ستون‌های موردنیاز: شماره بیمه‌نامه، حق بیمه. ستون‌های پلاک و نام بیمه‌گذار در صورت وجود برای تطبیق دقیق‌تر استفاده می‌شوند.</p>
            </div>
            <div id="rec-result" class="space-y-4"></div>
        </div>

        <!-- ======================= صورتحساب‌ها ======================= -->
        <div id="tab-fin-invoices" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-file-invoice text-blue-500 ml-2"></i>صورتحساب‌ها</h1>
                    <p class="text-xs text-slate-400 mt-1">صدور صورتحساب کلی دوره و تفکیکی هر شرکت</p>
                </div>
                <button onclick="openNewInvoice()" class="bg-blue-600 text-white hover:bg-blue-700 px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-plus ml-1"></i> صدور صورتحساب</button>
            </div>
            <div class="card p-4 flex flex-wrap gap-3 items-end">
                <div><label class="text-[10px] text-slate-500 block mb-1">دوره</label><select id="inv-f-period" onchange="loadInvoices()" class="border rounded-lg px-3 py-2 text-xs"></select></div>
                <div><label class="text-[10px] text-slate-500 block mb-1">شرکت</label><select id="inv-f-company" onchange="loadInvoices()" class="border rounded-lg px-3 py-2 text-xs"></select></div>
            </div>
            <div class="card p-0 overflow-x-auto"><div id="fin-invoices-body"></div></div>
        </div>

        <!-- ======================= دریافت‌ها و چک‌ها ======================= -->
        <div id="tab-fin-payments" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-hand-holding-dollar text-emerald-500 ml-2"></i>دریافت‌ها و چک‌ها</h1>
                    <p class="text-xs text-slate-400 mt-1">ثبت پرداخت شرکت‌ها و پیگیری وضعیت چک‌ها</p>
                </div>
                <button onclick="openNewPayment()" class="bg-emerald-600 text-white hover:bg-emerald-700 px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-plus ml-1"></i> ثبت دریافت</button>
            </div>
            <div class="card p-4 flex flex-wrap gap-3 items-end">
                <div><label class="text-[10px] text-slate-500 block mb-1">شرکت</label><select id="pay-f-company" onchange="loadPayments()" class="border rounded-lg px-3 py-2 text-xs"></select></div>
            </div>
            <div class="card p-0 overflow-x-auto"><div id="fin-payments-body"></div></div>
            <div class="card p-4">
                <h3 class="font-bold text-sm text-slate-700 mb-3"><i class="fas fa-money-check ml-1"></i> چک‌ها</h3>
                <div id="fin-cheques-body" class="overflow-x-auto"></div>
            </div>
        </div>

        <!-- ======================= تسویه با پاسارگاد ======================= -->
        <div id="tab-fin-pasargad" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-building-columns text-purple-500 ml-2"></i>تسویه با پاسارگاد</h1>
                    <p class="text-xs text-slate-400 mt-1">اقساطی که از شرکت‌ها دریافت شده و باید به پاسارگاد پرداخت شود</p>
                </div>
                <button onclick="loadPasargad()" class="bg-purple-50 text-purple-600 hover:bg-purple-100 px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
            </div>
            <div class="card p-4 flex flex-wrap gap-3 items-end">
                <div><label class="text-[10px] text-slate-500 block mb-1">دوره</label><select id="psg-f-period" onchange="loadPasargad()" class="border rounded-lg px-3 py-2 text-xs"></select></div>
                <div><label class="text-[10px] text-slate-500 block mb-1">شرکت</label><select id="psg-f-company" onchange="loadPasargad()" class="border rounded-lg px-3 py-2 text-xs"></select></div>
                <button onclick="settleSelectedPasargad()" class="bg-purple-600 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-check-double ml-1"></i> تسویه انتخاب‌شده‌ها</button>
            </div>
            <div class="card p-0 overflow-x-auto"><div id="fin-pasargad-body"></div></div>
            <div class="card p-4">
                <h3 class="font-bold text-sm text-slate-700 mb-3">تسویه‌های انجام‌شده</h3>
                <div id="fin-psg-history" class="overflow-x-auto"></div>
            </div>
        </div>

        <!-- ======================= تب تنظیمات و ریست سیستم ======================= -->
        <?php if($_SESSION['role'] === 'ADMIN'): ?>
        <div id="tab-settings" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-cogs text-purple-500 ml-2"></i>تنظیمات و مدیریت سیستم</h1>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- ورود دومرحله‌ای با کد بله -->
                <div class="card p-6 border-blue-100 bg-gradient-to-br from-white to-blue-50/30">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-blue-100 pb-3"><i class="fas fa-shield-halved text-blue-500 ml-2"></i>ورود دومرحله‌ای (کد بله)</h3>
                    <p class="text-[11px] text-slate-500 mb-4">کد یک‌بارمصرف از طریق سرویس سفیر بله برای کاربران پنل ارسال می‌شود.</p>

                    <div class="border rounded-xl p-4 flex items-center justify-between mb-3">
                        <div>
                            <span class="text-xs font-bold text-slate-700">فعال‌سازی کلی</span>
                            <p class="text-[10px] text-slate-400 mt-1">اگر خاموش باشد، هیچ کدی ارسال نمی‌شود</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer shrink-0">
                            <input type="checkbox" id="otp-enabled" class="sr-only peer">
                            <div class="w-10 h-5 bg-slate-200 peer-checked:bg-blue-500 rounded-full transition-all"></div>
                            <div class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-all peer-checked:-translate-x-5"></div>
                        </label>
                    </div>

                    <div class="space-y-3">
                        <div class="float-input">
                            <input type="text" id="safir-key" placeholder=" " dir="ltr">
                            <label>api-access-key سرویس سفیر</label>
                        </div>
                        <div class="float-input">
                            <input type="text" id="safir-bot" placeholder=" " dir="ltr">
                            <label>شناسه‌ی بات (bot_id)</label>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="float-input">
                                <input type="number" id="otp-ttl" placeholder=" " dir="ltr" min="60" max="600">
                                <label>اعتبار کد (ثانیه)</label>
                            </div>
                            <div class="float-input">
                                <input type="number" id="otp-attempts" placeholder=" " dir="ltr" min="3" max="10">
                                <label>حداکثر تلاش</label>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2 mt-4">
                        <button onclick="saveOtpSettings()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-xs">
                            <i class="fas fa-save ml-1"></i> ذخیره
                        </button>
                        <button onclick="testOtp()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 rounded-xl text-xs">
                            <i class="fas fa-paper-plane ml-1"></i> ارسال کد آزمایشی
                        </button>
                    </div>

                    <div class="mt-4 pt-4 border-t">
                        <p class="text-xs font-bold text-slate-600 mb-2">کاربران پنل</p>
                        <div id="otp-users" class="space-y-2"></div>
                    </div>
                </div>

                <div class="card p-6 border-emerald-100 bg-gradient-to-br from-white to-emerald-50/30">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-emerald-100 pb-3"><i class="fas fa-robot text-emerald-500 ml-2"></i>تنظیمات ربات بله</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">برای تغییر توکن متصل به سامانه می‌توانید از این بخش استفاده کنید.</p>
                    
                    <div class="float-input">
                        <input type="text" id="bot-token-input" placeholder="<?php echo $bot_token_masked ? htmlspecialchars($bot_token_masked) : ' '; ?>" dir="ltr" autocomplete="off">
                        <label>توکن ربات (از BotFather@ بله)</label>
                    </div>
                    
                    <button onclick="saveBotSettings()" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-save ml-2"></i> ذخیره تنظیمات ربات
                    </button>
                </div>

                <div class="card p-6 border-cyan-100 bg-gradient-to-br from-white to-cyan-50/30">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-cyan-100 pb-3"><i class="fas fa-robot text-cyan-500 ml-2"></i>ربات درخواست‌های شرکتی</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">این یک ربات کاملاً جدا از ربات پرسنل است، مخصوص شرکت‌های درخواست‌کننده. توکن ربات را اینجا وارد کنید تا به آن وصل شود.</p>

                    <div class="float-input">
                        <input type="text" id="company-bot-token-input" placeholder="<?php echo $company_bot_token_masked ? htmlspecialchars($company_bot_token_masked) : ' '; ?>" dir="ltr" autocomplete="off">
                        <label>توکن ربات شرکتی (از BotFather@ بله)</label>
                    </div>

                    <button onclick="saveCompanyBotToken()" class="w-full bg-cyan-500 hover:bg-cyan-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-cyan-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-save ml-2"></i> ذخیره توکن ربات شرکتی
                    </button>
                </div>

                <div class="card p-6 border-blue-100 bg-gradient-to-br from-white to-blue-50/30">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-blue-100 pb-3"><i class="fas fa-layer-group text-blue-500 ml-2"></i>سقف بیمه‌نامه به ازای هر معرفی‌نامه</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">حداکثر تعداد بیمه‌نامه‌ای که با یک معرفی‌نامه قابل صدور است (پیش‌فرض: ۴ فقره).</p>
                    <div class="float-input">
                        <input type="number" id="quota-input" min="1" placeholder=" " dir="ltr">
                        <label>تعداد فقره مجاز</label>
                    </div>
                    <button onclick="saveQuotaSetting()" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-blue-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-save ml-2"></i> ذخیره سقف سهمیه
                    </button>
                </div>

                <div class="card p-6 border-violet-100 bg-gradient-to-br from-white to-violet-50/30">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-violet-100 pb-3"><i class="fas fa-globe text-violet-500 ml-2"></i>آدرس دامنه‌ی سایت</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">برای ساخت لینک مینی‌اپ «بازدید سلامت» در بله لازم است (مثال: https://mammut.bbama.ir بدون اسلش انتهایی).</p>
                    <div class="float-input">
                        <input type="text" id="site-url-input" placeholder=" " dir="ltr">
                        <label>آدرس سایت</label>
                    </div>
                    <button onclick="saveSiteUrlSetting()" class="w-full bg-violet-500 hover:bg-violet-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-violet-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-save ml-2"></i> ذخیره آدرس سایت
                    </button>
                </div>

                <div class="card p-6 border-amber-100 bg-gradient-to-br from-white to-amber-50/30">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-amber-100 pb-3"><i class="fas fa-bell text-amber-500 ml-2"></i>اطلاع‌رسانی فعالیت‌ها به مدیر</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">آیدی عددی چتِ شما در بله. برای فعال‌شدن، باید حتماً قبلاً ربات را استارت کرده باشید.</p>
                    <div class="float-input">
                        <input type="text" id="admin-chatid-input" placeholder=" " dir="ltr">
                        <label>آیدی عددی مدیر</label>
                    </div>
                    <button onclick="saveAdminChatIdSetting()" class="w-full bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-amber-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-save ml-2"></i> ذخیره آیدی مدیر
                    </button>
                </div>

                <div class="card p-6 border-teal-100 bg-gradient-to-br from-white to-teal-50/30 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-teal-100 pb-3"><i class="fas fa-calculator text-teal-500 ml-2"></i>تنظیمات استعلام حق بیمه (ثالث)</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">این تخفیف‌ها روی محاسبه‌ی آنلاین حق بیمه‌ی ثالث اثر می‌گذارند. غیرفعال‌کردن هرکدام یعنی آن تخفیف اصلاً در محاسبه لحاظ نمی‌شود.</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="border rounded-xl p-4 flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-700">تخفیف قرارداد گروهی</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="premium-group-discount-enabled" class="sr-only peer">
                                    <div class="w-10 h-5 bg-slate-200 peer-checked:bg-teal-500 rounded-full transition-all"></div>
                                    <div class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-all peer-checked:-translate-x-5"></div>
                                </label>
                            </div>
                            <div class="float-input">
                                <input type="number" step="0.1" id="premium-group-discount-percent" placeholder=" " dir="ltr">
                                <label>درصد تخفیف (پیش‌فرض 2.5)</label>
                            </div>
                        </div>
                        <div class="border rounded-xl p-4 flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-700">تخفیف مازاد تعهد ۵۰۰م-۱میلیارد</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="premium-excess-discount-enabled" class="sr-only peer">
                                    <div class="w-10 h-5 bg-slate-200 peer-checked:bg-teal-500 rounded-full transition-all"></div>
                                    <div class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-all peer-checked:-translate-x-5"></div>
                                </label>
                            </div>
                            <div class="float-input">
                                <input type="number" step="0.1" id="premium-excess-discount-percent" placeholder=" " dir="ltr">
                                <label>درصد تخفیف (پیش‌فرض 15)</label>
                            </div>
                        </div>
                        <div class="border rounded-xl p-4 flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-700">تخفیف صفر کیلومتر</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="premium-zero-km-discount-enabled" class="sr-only peer">
                                    <div class="w-10 h-5 bg-slate-200 peer-checked:bg-teal-500 rounded-full transition-all"></div>
                                    <div class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-all peer-checked:-translate-x-5"></div>
                                </label>
                            </div>
                            <div class="float-input">
                                <input type="number" step="0.1" id="premium-zero-km-discount-percent" placeholder=" " dir="ltr">
                                <label>درصد تخفیف (پیش‌فرض 5)</label>
                            </div>
                        </div>
                    </div>
                    <button onclick="savePremiumSettings()" class="w-full mt-4 bg-teal-500 hover:bg-teal-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-teal-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-save ml-2"></i> ذخیره تنظیمات استعلام
                    </button>
                </div>

                <div class="card p-6 border-red-100 bg-gradient-to-br from-white to-red-50/30">
                    <h3 class="font-bold text-red-600 mb-2 border-b border-red-100 pb-3"><i class="fas fa-exclamation-triangle ml-2"></i>پاکسازی و بازنشانی داده‌ها</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">برای پاکسازی تمام پرونده‌ها، پیام‌ها و معرفی‌نامه‌های آزمایشی جهت راه‌اندازی واقعی از دکمه زیر استفاده کنید. (حساب مدیران پاک نخواهد شد).</p>
                    <button onclick="document.getElementById('reset-modal').classList.add('active')" class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-red-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-trash-restore ml-2"></i> بازنشانی و حذف کلیه پرونده‌ها
                    </button>
                </div>

                <div class="card p-6 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-2 border-b pb-3"><i class="fas fa-info-circle text-blue-500 ml-2"></i>اطلاعات سیستم</h3>
                    <ul class="text-xs font-bold text-slate-500 space-y-3 mt-4 flex flex-col md:flex-row md:gap-8">
                        <li class="flex items-center gap-2"><span>نسخه سامانه:</span> <span class="text-slate-700 font-mono" dir="ltr">Version 2.0.0</span></li>
                        <li class="flex items-center gap-2"><span>محل بایگانی مدارک:</span> <span class="text-emerald-600 font-mono">/بایگانی (هاست ابری)</span></li>
                        <li class="flex items-center gap-2"><span>موتور هوش مصنوعی:</span> <span class="text-blue-600">PyMuPDF + Regex</span></li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- ======================= تنظیمات مالی ======================= -->
        <div id="tab-fin-settings" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-money-check-dollar text-emerald-500 ml-2"></i>تنظیمات مالی</h1>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card p-6">
                    <h3 class="font-bold text-slate-700 mb-4 border-b pb-3"><i class="fas fa-calendar-days text-indigo-500 ml-2"></i>دوره مالی و اقساط</h3>
                    <div class="space-y-3">
                        <div class="float-input">
                            <input type="number" id="fs-cutoff" placeholder=" " dir="ltr" min="1" max="31">
                            <label>روز شروع دوره مالی (پیش‌فرض ۲۵)</label>
                        </div>
                        <p class="text-[10px] text-slate-400 -mt-1">بیمه‌نامه‌ای که از این روز به بعد صادر شود، در دوره‌ی ماه بعد قرار می‌گیرد.</p>
                        <div class="float-input">
                            <input type="number" id="fs-inst-count" placeholder=" " dir="ltr" min="1" max="36">
                            <label>تعداد اقساط پیش‌فرض</label>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600 block mb-2">روش تقسیم اقساط</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="border rounded-xl p-3 cursor-pointer hover:bg-emerald-50 flex gap-2 items-start">
                                    <input type="radio" name="fs-method" value="easy" class="mt-1">
                                    <span><b class="text-xs">راحت</b><br><span class="text-[10px] text-slate-500">تقسیم دقیق؛ باقیمانده به قسط اول</span></span>
                                </label>
                                <label class="border rounded-xl p-3 cursor-pointer hover:bg-emerald-50 flex gap-2 items-start">
                                    <input type="radio" name="fs-method" value="mamut" class="mt-1">
                                    <span><b class="text-xs">ماموت</b><br><span class="text-[10px] text-slate-500">اقساط رُند به هزار؛ اختلاف روی قسط اول</span></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card p-6">
                    <h3 class="font-bold text-slate-700 mb-4 border-b pb-3"><i class="fas fa-lock text-amber-500 ml-2"></i>قوانین کنترلی</h3>
                    <div class="space-y-4">
                        <div class="border rounded-xl p-4 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-slate-700">تسویه‌ی پیوسته</span>
                                <p class="text-[10px] text-slate-400 mt-1">قسط بعدی تا تسویه‌ی کامل قسط قبلی پرداخت نشود</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                <input type="checkbox" id="fs-rule-seq" class="sr-only peer">
                                <div class="w-10 h-5 bg-slate-200 peer-checked:bg-emerald-500 rounded-full transition-all"></div>
                                <div class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-all peer-checked:-translate-x-5"></div>
                            </label>
                        </div>
                        <div class="border rounded-xl p-4 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-slate-700">اول دریافت، بعد پرداخت به پاسارگاد</span>
                                <p class="text-[10px] text-slate-400 mt-1">قسطی که شرکت نپرداخته، به پاسارگاد تسویه نشود</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                <input type="checkbox" id="fs-rule-collect" class="sr-only peer">
                                <div class="w-10 h-5 bg-slate-200 peer-checked:bg-emerald-500 rounded-full transition-all"></div>
                                <div class="absolute right-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-all peer-checked:-translate-x-5"></div>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card p-6">
                    <h3 class="font-bold text-slate-700 mb-4 border-b pb-3"><i class="fas fa-hashtag text-blue-500 ml-2"></i>شماره‌گذاری صورتحساب</h3>
                    <div class="float-input">
                        <input type="text" id="fs-inv-prefix" placeholder=" " dir="ltr">
                        <label>پیشوند ثابت</label>
                    </div>
                    <p class="text-[10px] text-slate-400 mt-2">قالب نهایی: <b dir="ltr" id="fs-inv-sample">SM49357-05-00001</b> (پیشوند - دو رقم سال - شمارنده ۵ رقمی)</p>
                </div>

                <div class="card p-6">
                    <h3 class="font-bold text-slate-700 mb-4 border-b pb-3"><i class="fas fa-file-word text-indigo-500 ml-2"></i>قالب صورتحساب (Word)</h3>
                    <div class="space-y-3">
                        <div class="border rounded-xl p-3">
                            <p class="text-xs font-bold mb-2">قالب کلی (کل دوره)</p>
                            <div id="tpl-summary-current" class="text-[10px] text-slate-500 mb-2">—</div>
                            <label class="bg-indigo-50 text-indigo-700 px-3 py-1.5 rounded-lg text-[11px] font-bold cursor-pointer">
                                انتخاب فایل .docx
                                <input type="file" class="hidden" accept=".docx" onchange="uploadTemplate('SUMMARY', this)">
                            </label>
                        </div>
                        <div class="border rounded-xl p-3">
                            <p class="text-xs font-bold mb-2">قالب تلفیقی (ریز پرسنل)</p>
                            <div id="tpl-personnel-current" class="text-[10px] text-slate-500 mb-2">—</div>
                            <label class="bg-indigo-50 text-indigo-700 px-3 py-1.5 rounded-lg text-[11px] font-bold cursor-pointer">
                                انتخاب فایل .docx
                                <input type="file" class="hidden" accept=".docx" onchange="uploadTemplate('PERSONNEL', this)">
                            </label>
                        </div>
                        <div class="border rounded-xl p-3">
                            <p class="text-xs font-bold mb-2">قالب تفکیکی (هر شرکت)</p>
                            <div id="tpl-detailed-current" class="text-[10px] text-slate-500 mb-2">—</div>
                            <label class="bg-indigo-50 text-indigo-700 px-3 py-1.5 rounded-lg text-[11px] font-bold cursor-pointer">
                                انتخاب فایل .docx
                                <input type="file" class="hidden" accept=".docx" onchange="uploadTemplate('DETAILED', this)">
                            </label>
                        </div>
                    </div>
                    <details class="mt-4">
                        <summary class="text-xs font-bold text-blue-600 cursor-pointer">راهنمای کدهای جایگزینی</summary>
                        <div class="text-[10.5px] text-slate-600 mt-2 space-y-1 leading-6">
                            <p><b>عمومی:</b> <code dir="ltr">{{شماره_صورتحساب}} {{تاریخ_صدور}} {{دوره}} {{نام_شرکت}} {{جمع_کل}} {{جمع_کل_حروف}} {{تعداد_بیمه_نامه}} {{قسط_ماهانه}} {{تعداد_اقساط}}</code></p>
                            <p><b>جدول:</b> <code dir="ltr">{{جدول}}</code> — در هر سه نوع یکسان است؛ ستون‌ها خودکار بر اساس نوع صورتحسابِ انتخابی چیده می‌شوند. اگر رکوردی نباشد، حذف می‌شود.</p>
                            <p class="text-amber-600">نکته: کدها را در Notepad بنویسید و کپی کنید تا Word آن‌ها را تکه‌تکه نکند.</p>
                        </div>
                    </details>
                </div>
            </div>

            <button onclick="saveFinanceSettings()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl shadow-lg text-sm">
                <i class="fas fa-save ml-2"></i> ذخیره تنظیمات مالی
            </button>
        </div>

        <!-- ======================= کاربران داخلیِ پنل (ADMIN/OPERATOR/FINANCE/COMPANY_LIAISON) ======================= -->
        <div id="tab-staff-users" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-user-shield text-blue-500 ml-2"></i>کاربران پنل (داخلی)</h1>
                    <p class="text-xs text-slate-400 mt-1">این بخش برای حساب‌های کاربریِ خودمان (مدیر، اپراتور، مالی، همکار شرکت‌ها) است - نه پرسنل یا شرکت‌های درخواست‌کننده.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="loadStaffUsers()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt"></i> بروزرسانی</button>
                    <button onclick="openModal('add-staff-user-modal')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-plus ml-1"></i>افزودن عضو</button>
                </div>
            </div>
            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3 text-right">نام کاربری</th><th class="p-3 text-right">نام</th>
                        <th class="p-3 text-right">نقش</th><th class="p-3 text-right">موبایل</th><th class="p-3 text-right"></th>
                    </tr></thead>
                    <tbody id="su-body"><tr><td colspan="5" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canSeeCompanies): ?>
        <!-- ======================= تب درخواست‌های شرکتی ======================= -->
        <!-- ======================= لیست صدور (شرکتی) ======================= -->
        <div id="tab-issue-queue" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-list-check text-teal-500 ml-2"></i>لیست صدور</h1>
                    <p class="text-xs text-slate-400 mt-1">همه‌ی ردیف‌هایی که هنوز صادر نشده‌اند. با صدور هر ردیف، از این فهرست بیرون می‌رود.</p>
                </div>
                <button onclick="loadIssueQueue()" class="bg-teal-50 text-teal-600 hover:bg-teal-100 px-4 py-2 rounded-lg font-bold text-sm transition-colors"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-center"><p class="text-[11px] text-slate-500">کل ردیف‌ها</p><p id="iq-c-total" class="font-black text-xl text-slate-700">۰</p></div>
                <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-center"><p class="text-[11px] text-emerald-700">آماده‌ی صدور</p><p id="iq-c-ready" class="font-black text-xl text-emerald-700">۰</p></div>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-center"><p class="text-[11px] text-amber-700">منتظر مدارک</p><p id="iq-c-waiting" class="font-black text-xl text-amber-700">۰</p></div>
            </div>

            <div class="card p-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-2">
                <input type="search" id="iq-q" oninput="debouncedIssueQueue()" placeholder="جستجو..." class="border rounded-lg px-3 py-2 text-xs col-span-2">
                <select id="iq-company" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی شرکت‌ها</option></select>
                <select id="iq-readiness" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="">آماده و غیرآماده</option>
                    <option value="READY">فقط آماده‌ی صدور</option>
                    <option value="WAITING">فقط منتظر مدارک</option>
                </select>
                <select id="iq-missing" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="">کمبودِ هر مدرکی</option>
                    <option value="car_card_or_title">کارت ماشین یا سند</option>
                    <option value="prev_body_policy">بیمه بدنه قبل</option>
                    <option value="health_inspection">بازدید سلامت</option>
                    <option value="health_report">گزارش بازدید</option>
                    <option value="policy_doc">بیمه‌نامه</option>
                    <option value="new_car_card_or_title">کارت یا سند جدید</option>
                </select>
                <select id="iq-type" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="">بدنه و ثالث</option><option value="BODY">بدنه</option><option value="THIRDPARTY">ثالث</option>
                </select>
                <select id="iq-kind" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="">همه‌ی انواع درخواست</option>
                    <option value="NEW_POLICY">صدور جدید</option><option value="ENDORSEMENT">الحاقیه</option><option value="CANCELLATION">فسخ</option>
                </select>
                <select id="iq-stage" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="">همه‌ی مرحله‌ها</option>
                    <option value="PENDING">در انتظار مدارک</option><option value="READY_FOR_ISSUE">آماده‌ی صدور</option>
                    <option value="WITH_BOSS">ارسال‌شده برای رئیس</option><option value="IN_ISSUANCE">در حال صدور</option>
                </select>
                <input type="text" id="iq-exp-from" oninput="debouncedIssueQueue()" placeholder="انقضا از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="iq-exp-to" oninput="debouncedIssueQueue()" placeholder="انقضا تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto max-h-[62vh]">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-teal-50 text-teal-800 font-bold sticky top-0 z-10"><tr>
                            <th class="p-3">#</th><th class="p-3">شرکت</th><th class="p-3">پلاک / شماره شاسی</th>
                            <th class="p-3">نوع</th><th class="p-3">درخواست</th><th class="p-3">انقضا</th>
                            <th class="p-3">مدارک</th><th class="p-3">مرحله</th><th class="p-3"></th>
                        </tr></thead>
                        <tbody id="iq-body"><tr><td colspan="9" class="p-8 text-center text-slate-400">در حال بارگذاری...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======================= صادره‌ها (شرکتی + کارکنان) ======================= -->
        <div id="tab-issued-list" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-file-circle-check text-emerald-500 ml-2"></i>صادره‌ها</h1>
                    <p class="text-xs text-slate-400 mt-1">همه‌ی بیمه‌نامه‌های صادرشده - شرکتی و کارکنان - با همه‌ی اطلاعاتی که داریم.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="exportIssuedExcel()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-file-excel ml-1"></i> خروجی اکسل</button>
                    <button onclick="loadIssuedList()" class="bg-slate-100 text-slate-600 hover:bg-slate-200 px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-sync-alt"></i></button>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-center"><p class="text-[11px] text-slate-500">کل صادره</p><p id="il-c-total" class="font-black text-xl text-slate-700">۰</p></div>
                <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-3 text-center"><p class="text-[11px] text-indigo-700">شرکتی</p><p id="il-c-company" class="font-black text-xl text-indigo-700">۰</p></div>
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 text-center"><p class="text-[11px] text-blue-700">کارکنان</p><p id="il-c-personnel" class="font-black text-xl text-blue-700">۰</p></div>
                <div class="bg-cyan-50 border border-cyan-200 rounded-xl p-3 text-center"><p class="text-[11px] text-cyan-700">بدنه / ثالث</p><p id="il-c-types" class="font-black text-xl text-cyan-700">۰ / ۰</p></div>
                <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-center"><p class="text-[11px] text-emerald-700">جمع حق بیمه (ریال)</p><p id="il-c-premium" class="font-black text-lg text-emerald-700">۰</p></div>
            </div>

            <div class="card p-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                <input type="search" id="il-q" oninput="debouncedIssuedList()" placeholder="جستجو در همه‌ی ستون‌ها..." class="border rounded-lg px-3 py-2 text-xs lg:col-span-2">
                <select id="il-source" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="ALL">شرکتی و کارکنان</option>
                    <option value="COMPANY">فقط شرکتی</option>
                    <option value="PERSONNEL">فقط کارکنان</option>
                </select>
                <select id="il-type" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="">بدنه و ثالث</option><option value="BODY">بدنه</option><option value="THIRDPARTY">ثالث</option>
                </select>
                <input type="text" id="il-from" oninput="debouncedIssuedList()" placeholder="صدور از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="il-to" oninput="debouncedIssuedList()" placeholder="صدور تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto max-h-[62vh]">
                    <table class="w-full text-right text-xs whitespace-nowrap">
                        <thead class="bg-emerald-50 text-emerald-800 font-bold sticky top-0 z-10"><tr>
                            <th class="p-3">#</th><th class="p-3">منبع</th><th class="p-3">نوع درخواست</th>
                            <th class="p-3">بیمه‌گذار</th><th class="p-3">شرکت</th><th class="p-3">پلاک / شاسی</th>
                            <th class="p-3">نوع</th><th class="p-3">شماره بیمه‌نامه</th><th class="p-3">خودرو</th>
                            <th class="p-3">حق بیمه</th><th class="p-3">تاریخ درخواست</th><th class="p-3">انقضا</th>
                            <th class="p-3">تاریخ صدور</th><th class="p-3">وضعیت</th><th class="p-3">فایل</th>
                        </tr></thead>
                        <tbody id="il-body"><tr><td colspan="15" class="p-8 text-center text-slate-400">در حال بارگذاری...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-companies-requests" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h1 class="text-2xl font-black text-slate-800">درخواست‌های بیمه‌ی شرکتی</h1>
                <div class="flex items-center gap-2">
                    <select id="creq-status-filter" onchange="loadCompanyRequests()" class="text-xs font-bold border rounded-xl px-3 py-2">
                        <option value="">همه‌ی وضعیت‌ها</option>
                        <option value="NEW">جدید</option>
                        <option value="DOCS_REVIEW">در حال بررسی</option>
                        <option value="READY_FOR_ISSUE">آماده‌ی صدور</option>
                        <option value="ISSUED">صادر شده</option>
                        <option value="CANCELLED">لغو شده</option>
                    </select>
                    <button onclick="loadCompanyRequests()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt"></i></button>
                    <button onclick="openAdminNewRequestModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-plus ml-1"></i>ثبت دستی درخواست</button>
                </div>
            </div>
            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3 text-right">شماره</th><th class="p-3 text-right">شرکت</th>
                        <th class="p-3 text-right">بیمه‌گر</th><th class="p-3 text-right">ردیف‌ها (درخواستی / صادره)</th>
                        <th class="p-3 text-right">وضعیت</th>
                        <th class="p-3 text-right">تاریخ ثبت</th><th class="p-3 text-right"></th>
                    </tr></thead>
                    <tbody id="creq-body"><tr><td colspan="6" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ======================= تب صندوق ورودی مدارک شرکتی ======================= -->
        <div id="tab-companies-inbox" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-800">صندوق ورودی مدارک و نامه‌های شرکتی</h1>
                    <p class="text-xs text-slate-400 mt-1">هر مدرک تازه‌آپلودشده اینجا می‌نشیند تا پلاک، نوع مدرک و درخواستِ مربوطه به‌صورت دستی مشخص شود.</p>
                </div>
                <button onclick="loadCompanyInbox()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt"></i> بروزرسانی</button>
            </div>
            <div id="cinbox-list" class="space-y-3"></div>
        </div>

        <!-- ======================= تب گزارش مالی شرکت‌ها ======================= -->
        <div id="tab-companies-finance" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h1 class="text-2xl font-black text-slate-800">گزارش مالی شرکت‌ها</h1>
                <div class="flex items-center gap-2 flex-wrap">
                    <select id="cfin-chart-company" onchange="loadFinanceChart()" class="text-xs font-bold border rounded-xl px-3 py-2">
                        <option value="">همه‌ی شرکت‌ها</option>
                    </select>
                    <select id="cfin-chart-range" onchange="loadFinanceChart()" class="text-xs font-bold border rounded-xl px-3 py-2">
                        <option value="MONTH">یک ماه اخیر</option>
                        <option value="3M">۳ ماه اخیر</option>
                        <option value="6M" selected>۶ ماه اخیر</option>
                        <option value="YEAR">یک سال اخیر</option>
                        <option value="ALL">کل بازه</option>
                    </select>
                    <button onclick="loadCompanyFinance()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt"></i> بروزرسانی</button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="card p-5 md:col-span-3">
                    <p class="text-xs font-bold text-slate-500 mb-3"><i class="fas fa-chart-column text-blue-500 ml-1"></i>نمودار فروش (جمع صورتحساب به تفکیک ماه)</p>
                    <div id="cfin-chart-box" class="w-full" style="min-height:220px;"></div>
                </div>
                <div class="grid grid-cols-1 gap-4">
                    <div class="card p-4">
                        <p class="text-[11px] text-slate-400 font-bold mb-1">تعداد بیمه‌نامه‌ی صادره</p>
                        <p class="text-xl font-black text-slate-800" id="cfin-stat-count">—</p>
                    </div>
                    <div class="card p-4">
                        <p class="text-[11px] text-slate-400 font-bold mb-1">مجموع حق بیمه</p>
                        <p class="text-xl font-black text-blue-600" id="cfin-stat-premium">—</p>
                    </div>
                    <div class="card p-4">
                        <p class="text-[11px] text-slate-400 font-bold mb-1">مبلغ بدهی</p>
                        <p class="text-xl font-black text-rose-600" id="cfin-stat-debt">—</p>
                    </div>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3 text-right">شرکت</th><th class="p-3 text-right">مجموع صورتحساب</th>
                        <th class="p-3 text-right">مجموع دریافتی</th><th class="p-3 text-right">مانده</th>
                    </tr></thead>
                    <tbody id="cfin-body"><tr><td colspan="4" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                </table>
            </div>
        </div>

        <?php if (!$isLiaison): ?>
        <!-- ======================= تب مدیریت شرکت‌ها (فقط ادمین) ======================= -->
        <div id="tab-companies-manage" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h1 class="text-2xl font-black text-slate-800">مدیریت شرکت‌های درخواست‌کننده</h1>
                <div class="flex gap-2">
                    <button onclick="loadCompanyManage()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt"></i> بروزرسانی</button>
                    <button onclick="resetCompanyForm(); openModal('add-company-modal')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-plus ml-1"></i>افزودن شرکت</button>
                    <button onclick="openAddPortalUserModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-user-plus ml-1"></i>افزودن عضو</button>
                </div>
            </div>
            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3 text-right">شرکت</th><th class="p-3 text-right">شرکت مادر</th>
                        <th class="p-3 text-right">نحوه‌ی تسویه</th><th class="p-3 text-right">اقساط</th>
                        <th class="p-3 text-right">بیمه‌گر مجاز</th><th class="p-3 text-right">تاریخ ایجاد</th>
                        <th class="p-3 text-right"></th>
                    </tr></thead>
                    <tbody id="cm-companies-body"><tr><td colspan="4" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                </table>
            </div>
            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3 text-right">نام کاربری</th><th class="p-3 text-right">نام</th>
                        <th class="p-3 text-right">شرکت(ها)</th><th class="p-3 text-right">موبایل</th><th class="p-3 text-right"></th>
                    </tr></thead>
                    <tbody id="cm-portal-users-body"><tr><td colspan="5" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div class="footer-credit mt-auto">
            <div class="footer-box group/author hover-target relative">
                <span>طراحی و توسعه سیستم :</span>
                <span class="text-blue-600 font-black mr-1 relative inline-block cursor-none">گروه توسعه بـــرگه</span>
                <span class="text-slate-400 text-xs ml-1" style="font-family: Arial, sans-serif;">(MMBehzadi)</span>
            </div>
            <p class="text-slate-500 text-[10px]">تمامی حقوق مادی و معنوی سیستم محفوظ می‌باشد - ۲۰۲۶ ©</p>
        </div>
    </main>

    <!-- مودال جزئیات درخواست شرکتی -->
    <div id="creq-detail-modal" class="modal-overlay">
        <!-- عرضِ این مودال عمداً تا نزدیکِ کلِ پنجره باز است: جدولِ ریزِ درخواست یک
             ستونِ چک‌لیست دارد که باید خانه‌هایش در یک ردیف کنار هم جا شوند -->
        <div class="modal-content creq-wide-modal p-6 relative max-h-[90vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('creq-detail-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4">جزئیات درخواست شرکتی</h3>
            <div id="creq-detail-body" class="text-sm"></div>
        </div>
    </div>

    <!-- مودال ثبت دستیِ یک درخواست شرکتی (توسط خودمان، بدون نیاز به ثبت‌کننده‌ی شرکت) -->
    <div id="admin-new-creq-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" onclick="document.getElementById('admin-new-creq-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4"><span id="ancr-modal-title">ثبت دستی درخواست شرکتی</span></h3>
            <input type="hidden" id="ancr-request-id">
            <div class="float-input">
                <select id="ancr-company" onchange="ancrUpdateInsurerOptions()"><option value="">-- انتخاب شرکت --</option></select>
                <label>شرکت</label>
            </div>
            <div class="float-input">
                <select id="ancr-insurer"></select>
                <label>بیمه‌گر</label>
            </div>
            <label class="text-xs font-bold text-slate-500 block mb-2">توضیح / متن نامه (اختیاری)</label>
            <div class="float-input">
                <select id="ancr-kind" onchange="onAncrKindChange()">
                    <option value="NEW_POLICY">صدور بیمه‌نامه‌ی جدید</option>
                    <option value="ENDORSEMENT">صدور الحاقیه</option>
                    <option value="CANCELLATION">فسخ بیمه‌نامه</option>
                </select>
                <label>نوع درخواست</label>
            </div>
            <p id="ancr-kind-hint" class="text-[11px] text-slate-400 mb-2"></p>
            <textarea id="ancr-text" rows="3" class="w-full border rounded-xl p-3 text-sm mb-4"></textarea>
            <label class="text-xs font-bold text-slate-500 block mb-2">فایل نامه (اختیاری)</label>
            <input type="file" id="ancr-letter-file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="w-full border rounded-xl p-2 text-xs mb-4">
            <button onclick="submitAdminNewRequest()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت درخواست</button>
        </div>
    </div>

    <!-- مودال افزودن دستیِ یک پلاک به درخواست جاری (بدون نیاز به مدرک) -->
    <div id="admin-add-plate-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" onclick="document.getElementById('admin-add-plate-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4">افزودن پلاک</h3>
            <input type="hidden" id="aap-request-id">
            <!-- ترتیبِ نمایشی چپ‌به‌راست: [۲ رقم] [حرف] [۳ رقم] [ایران + ۲ رقمِ کد شهر].
                 شناسه‌ها عمداً عوض نشده‌اند: aap-p1 همان دو رقمِ کنارِ «ایران» است. -->
            <div class="grid grid-cols-4 gap-2 mb-3 items-center" dir="ltr">
                <input type="text" id="aap-p4" maxlength="2" placeholder="۱۲" class="text-center border rounded-lg p-2 text-sm">
                <input type="text" id="aap-letter" maxlength="3" placeholder="الف" class="text-center border rounded-lg p-2 text-sm">
                <input type="text" id="aap-p2" maxlength="3" placeholder="۳۴۵" class="text-center border rounded-lg p-2 text-sm">
                <div class="flex items-center gap-1 border rounded-lg px-1">
                    <span class="text-[10px] text-slate-400 whitespace-nowrap">ایران</span>
                    <input type="text" id="aap-p1" maxlength="2" placeholder="۶۷" class="text-center text-sm w-full outline-none p-2">
                </div>
            </div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600 mb-2 cursor-pointer">
                <input type="checkbox" id="aap-isnew" onchange="toggleNoPlate('aap')"> پلاک ندارد (لیفتراک یا خودروی صفرکیلومتر)
            </label>
            <div id="aap-chassis-box" class="grid grid-cols-2 gap-3 hidden">
                <div class="float-input"><input type="text" id="aap-chassis" dir="ltr" placeholder=" "><label>شماره شاسی</label></div>
                <div class="float-input"><input type="text" id="aap-engine" dir="ltr" placeholder=" "><label>شماره موتور</label></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input"><input type="text" id="aap-carvalue" dir="ltr" inputmode="numeric" placeholder=" "><label>ارزش خودرو (ریال) - بدنه</label></div>
                <div class="float-input"><input type="text" id="aap-liability" dir="ltr" inputmode="numeric" placeholder=" "><label>سقف تعهد مالی (ریال) - ثالث</label></div>
            </div>
            <div class="float-input"><input type="text" id="aap-refpolicy" dir="ltr" placeholder=" "><label>شماره بیمه‌نامه (اختیاری - برای الحاقیه و فسخ لازم است)</label></div>
            <div class="float-input"><input type="text" id="aap-endorse" placeholder=" "><label>خواسته‌ی الحاقیه (چه تغییری می‌خواهند؟)</label></div>
            <div class="float-input">
                <select id="aap-cancelreason">
                    <option value="">دلیل فسخ (اگر درخواست فسخ است)</option>
                </select>
                <label>دلیل فسخ</label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input">
                    <select id="aap-insurance-type"><option value="">نامشخص</option><option value="THIRDPARTY">ثالث</option><option value="BODY">بدنه</option></select>
                    <label>نوع بیمه</label>
                </div>
                <div class="float-input"><input type="date" id="aap-expiry" placeholder=" "><label>تاریخ انقضا</label></div>
            </div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-500 mb-4">
                <input type="checkbox" id="aap-skip-health"> این پلاک نیاز به بازدید سلامت ندارد
            </label>
            <button onclick="submitAdminAddPlate()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">افزودن پلاک</button>
        </div>
    </div>

    <!-- مودال «بررسی مدارک» یک درخواست مشخص -->
    <div id="creq-docs-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-lg p-6 relative max-h-[85vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('creq-docs-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4">بررسی مدارک درخواست <span id="creq-docs-req-id"></span></h3>
            <div id="creq-docs-list" class="space-y-3"></div>
        </div>
    </div>

    <!-- مودال تگ‌گذاریِ یک مدرک صندوق ورودی -->
    <div id="cinbox-tag-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-lg p-6 relative max-h-[90vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('cinbox-tag-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1">ثبت اطلاعات مدرک (تگ‌گذاری)</h3>
            <p class="text-[11px] text-slate-400 mb-3">فایل را همین‌جا ببینید، بعد مشخص کنید مالِ کدام خودروی این درخواست و چه نوع مدرکی است؛ سیستم خودش نام‌گذاری می‌کند و در پوشه‌ی همان ردیف می‌گذارد.</p>
            <input type="hidden" id="cit-doc-id">

            <!-- پیش‌نمایشِ خودِ فایل، تا بشود دید این مدرک چیست و مالِ کدام پلاک است -->
            <div id="cit-preview" class="mb-3 rounded-xl border border-slate-200 bg-slate-50 overflow-hidden"></div>

            <div class="float-input"><select id="cit-request" onchange="onCitRequestChange()"></select><label>درخواست مربوطه</label></div>

            <!-- فهرستِ خودروهای همین درخواست، برای انتخابِ سریع -->
            <div id="cit-plate-chips" class="flex flex-wrap gap-1 mb-3"></div>

            <div class="float-input">
                <select id="cit-existing-plate" onchange="onCitPlateSelectChange()"><option value="">-- پلاک جدید --</option></select>
                <label>پلاک</label>
            </div>
            <div class="float-input">
                <select id="cit-doc-type" onchange="document.getElementById('cit-doc-type-other').classList.toggle('hidden', this.value !== 'other')">
                    <option value="ownership_doc">سند</option>
                    <option value="car_card_front">کارت ماشین رو</option>
                    <option value="car_card_back">کارت ماشین پشت</option>
                    <option value="prev_third_policy">بیمه ثالث قبل</option>
                    <option value="prev_body_policy">بیمه بدنه قبل</option>
                    <option value="health_inspection">بازدید سلامت (زیپ/عکس)</option>
                    <option value="health_report">گزارش بازدید</option>
                    <option value="policy_doc">بیمه‌نامه (برای الحاقیه و فسخ)</option>
                    <option value="new_car_card">کارت ماشین جدید (برای الحاقیه)</option>
                    <option value="new_ownership_doc">سند جدید (برای الحاقیه)</option>
                    <option value="letter">نامه‌ی درخواست (روی خودِ درخواست می‌نشیند، نه یک پلاک)</option>
                    <option value="other">سایر مدارک</option>
                </select>
                <label>نوع مدرک</label>
                <input type="text" id="cit-doc-type-other" placeholder="نوع مدرک را بنویسید" class="mt-2 hidden">
            </div>
            <label class="text-xs font-bold text-slate-500 block mb-2">پلاک (اگر «پلاک جدید» را انتخاب کرده‌اید)</label>
            <!-- ترتیبِ نمایشی چپ‌به‌راست: [۲ رقم] [حرف] [۳ رقم] [ایران + ۲ رقمِ کد شهر].
                 شناسه‌ها عمداً عوض نشده‌اند: cit-p1 همان دو رقمِ کنارِ «ایران» است. -->
            <div class="grid grid-cols-4 gap-2 mb-4 items-center" dir="ltr">
                <input type="text" id="cit-p4" maxlength="2" placeholder="۱۲" class="text-center border rounded-lg p-2 text-sm">
                <input type="text" id="cit-letter" maxlength="3" placeholder="الف" class="text-center border rounded-lg p-2 text-sm">
                <input type="text" id="cit-p2" maxlength="3" placeholder="۳۴۵" class="text-center border rounded-lg p-2 text-sm">
                <div class="flex items-center gap-1 border rounded-lg px-1">
                    <span class="text-[10px] text-slate-400 whitespace-nowrap">ایران</span>
                    <input type="text" id="cit-p1" maxlength="2" placeholder="۶۷" class="text-center text-sm w-full outline-none p-2">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input">
                    <select id="cit-insurance-type"><option value="">نامشخص</option><option value="THIRDPARTY">ثالث</option><option value="BODY">بدنه</option></select>
                    <label>نوع بیمه</label>
                </div>
                <div class="float-input"><input type="date" id="cit-expiry" placeholder=" "><label>تاریخ انقضا</label></div>
            </div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-500 mb-4">
                <input type="checkbox" id="cit-skip-health"> این پلاک نیاز به بازدید سلامت ندارد (طبق روال این شرکت)
            </label>
            <div class="flex gap-2">
                <button onclick="submitAssignDocument()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت اطلاعات (تگ‌گذاری)</button>
                <button onclick="rejectDocument(document.getElementById('cit-doc-id').value)" class="bg-red-50 hover:bg-red-100 text-red-600 font-bold py-2.5 px-4 rounded-xl text-sm whitespace-nowrap">رد کردن</button>
            </div>
        </div>
    </div>

    <!-- مودال ثبت صدور نهایی یک پلاک شرکتی -->
    <div id="cissue-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" onclick="document.getElementById('cissue-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1">ثبت صدور بیمه‌نامه</h3>
            <p class="text-[11px] text-slate-400 mb-3">اول فایل بیمه‌نامه را بدهید تا اعتبارسنجی شود (پلاک و نوع بیمه با این ردیف مقایسه می‌شود)، بعد ثبت کنید.</p>
            <input type="hidden" id="cis-plate-id">
            <p id="cis-plate-label" class="text-xs font-bold text-slate-600 mb-2"></p>
            <div class="float-input"><input type="file" id="cis-file" accept=".pdf,.jpg,.jpeg,.png" placeholder=" "><label>فایل بیمه‌نامه‌ی صادرشده</label></div>
            <button onclick="validateCompanyPolicy()" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2 rounded-xl text-xs mb-2"><i class="fas fa-magnifying-glass ml-1"></i>اعتبارسنجی و شناسایی فایل</button>
            <div id="cis-ocr-note" class="hidden text-[11px] rounded-lg p-2 mb-2"></div>
            <div class="float-input"><input type="text" id="cis-policy-number" dir="ltr" placeholder=" "><label>شماره بیمه‌نامه</label></div>
            <div class="float-input"><input type="text" id="cis-vin" dir="ltr" placeholder=" "><label>شماره شاسی (VIN)</label></div>
            <div class="float-input"><input type="number" id="cis-premium" dir="ltr" placeholder=" "><label>حق بیمه (ریال)</label></div>
            <button onclick="submitMarkIssued()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت صدور</button>
        </div>
    </div>

    <!-- مودال جزئیاتِ یک ردیفِ لیست صدور -->
    <div id="iq-detail-modal" class="modal-overlay">
        <div class="modal-content creq-wide-modal p-6 relative max-h-[92vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('iq-detail-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1">پرونده‌ی صدور</h3>
            <p class="text-[11px] text-slate-400 mb-4">اطلاعات شرکت و ردیف، مدارک، و خودِ صدور - همه یک‌جا.</p>
            <div id="iq-detail-body"></div>
        </div>
    </div>

    <!-- مودال جزئیاتِ یک بیمه‌نامه‌ی صادرشده -->
    <div id="il-detail-modal" class="modal-overlay">
        <div class="modal-content creq-wide-modal p-6 relative max-h-[92vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('il-detail-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4">جزئیات بیمه‌نامه‌ی صادرشده</h3>
            <div id="il-detail-body"></div>
        </div>
    </div>

    <!-- مودال ویرایش یک ردیفِ درخواست -->
    <div id="crow-edit-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative max-h-[85vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('crow-edit-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4">ویرایش ردیف</h3>
            <input type="hidden" id="cre-plate-id">
            <label class="text-xs font-bold text-slate-500 block mb-2">پلاک</label>
            <div class="grid grid-cols-4 gap-2 mb-3 items-center" dir="ltr">
                <input type="text" id="cre-p4" maxlength="2" placeholder="۱۲" class="text-center border rounded-lg p-2 text-sm">
                <input type="text" id="cre-letter" maxlength="3" placeholder="الف" class="text-center border rounded-lg p-2 text-sm">
                <input type="text" id="cre-p2" maxlength="3" placeholder="۳۴۵" class="text-center border rounded-lg p-2 text-sm">
                <div class="flex items-center gap-1 border rounded-lg px-1">
                    <span class="text-[10px] text-slate-400 whitespace-nowrap">ایران</span>
                    <input type="text" id="cre-p1" maxlength="2" placeholder="۶۷" class="text-center text-sm w-full outline-none p-2">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input">
                    <select id="cre-insurance-type"><option value="">نامشخص</option><option value="THIRDPARTY">ثالث</option><option value="BODY">بدنه</option></select>
                    <label>نوع بیمه</label>
                </div>
                <div class="float-input"><input type="text" id="cre-expiry" dir="ltr" placeholder=" "><label>تاریخ انقضا (۱۴۰۵/۰۷/۳۰)</label></div>
            </div>
            <div class="float-input">
                <select id="cre-has-prev-body">
                    <option value="">مشخص نشده</option>
                    <option value="YES">بیمه بدنه قبلی دارد (اجباری می‌شود)</option>
                    <option value="NO">بیمه بدنه قبلی ندارد</option>
                </select>
                <label>بیمه بدنه قبل</label>
            </div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600 mb-2 cursor-pointer">
                <input type="checkbox" id="cre-isnew" onchange="toggleNoPlate('cre')"> پلاک ندارد (لیفتراک یا خودروی صفرکیلومتر)
            </label>
            <div id="cre-chassis-box" class="grid grid-cols-2 gap-3 hidden">
                <div class="float-input"><input type="text" id="cre-chassis" dir="ltr" placeholder=" "><label>شماره شاسی</label></div>
                <div class="float-input"><input type="text" id="cre-engine" dir="ltr" placeholder=" "><label>شماره موتور</label></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input"><input type="text" id="cre-carvalue" dir="ltr" inputmode="numeric" placeholder=" "><label>ارزش خودرو (ریال) - بدنه</label></div>
                <div class="float-input"><input type="text" id="cre-liability" dir="ltr" inputmode="numeric" placeholder=" "><label>سقف تعهد مالی (ریال) - ثالث</label></div>
            </div>
            <div class="float-input"><input type="text" id="cre-refpolicy" dir="ltr" placeholder=" "><label>شماره بیمه‌نامه (اختیاری - برای الحاقیه و فسخ لازم است)</label></div>
            <div class="float-input"><input type="text" id="cre-endorse" placeholder=" "><label>خواسته‌ی الحاقیه (چه تغییری می‌خواهند؟)</label></div>
            <div class="float-input">
                <select id="cre-cancelreason">
                    <option value="">دلیل فسخ (اگر درخواست فسخ است)</option>
                </select>
                <label>دلیل فسخ</label>
            </div>
            <div class="float-input"><input type="text" id="cre-car-name" placeholder=" "><label>نام/تیپ خودرو</label></div>
            <div class="float-input"><input type="text" id="cre-note" placeholder=" "><label>توضیح این ردیف</label></div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600 mb-4 cursor-pointer">
                <input type="checkbox" id="cre-skip-health"> این خودرو نیاز به بازدید سلامت ندارد
            </label>
            <button onclick="submitRowEdit()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">ذخیره</button>
        </div>
    </div>

    <!-- مودال فهرست متنیِ ریزِ درخواست -->
    <div id="crows-text-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-lg p-6 relative">
            <button type="button" onclick="document.getElementById('crows-text-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1">فهرست متنی ریز درخواست</h3>
            <p class="text-[11px] text-slate-400 mb-3">هر ردیف با مدارکی که دارد، و زیرش مدارکِ ناموجودش.</p>
            <textarea id="crows-text-content" rows="14" dir="rtl" class="w-full text-[11px] font-mono border rounded-xl p-3 bg-slate-50" readonly></textarea>
            <button onclick="copyRowsText()" class="w-full mt-3 bg-slate-800 hover:bg-slate-900 text-white font-bold py-2.5 rounded-xl text-sm"><i class="fas fa-copy ml-1"></i>کپی کردن</button>
        </div>
    </div>

    <!-- مودال ورودِ ردیف‌ها از نامه (خروجی هوش مصنوعی) -->
    <div id="letter-import-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-2xl p-6 relative max-h-[90vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('letter-import-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1">ورود ردیف‌ها از نامه</h3>
            <p class="text-[11px] text-slate-400 mb-3">نامه را به هوش مصنوعی بدهید و بگویید با یکی از این دو قالب خروجی بدهد، بعد خروجی را اینجا بچسبانید (یا فایلش را انتخاب کنید).</p>
            <input type="hidden" id="cli-request-id">

            <details class="bg-slate-50 border border-slate-200 rounded-xl p-3 mb-3">
                <summary class="text-xs font-bold text-slate-600 cursor-pointer">قالبِ خروجی که باید به هوش مصنوعی بگویید (کلیک کنید)</summary>
                <p class="text-[11px] font-bold text-slate-600 mt-2 mb-1">قالب ۱ - JSON (دقیق‌تر، پیشنهاد می‌شود):</p>
<pre class="text-[10px] bg-white border rounded-lg p-2 overflow-x-auto" dir="ltr">{
  "rows": [
    {
      "plate": "31 ع 693 ایران 21",
      "insurance_type": "بدنه",
      "expiry_date": "1405/07/30",
      "car_name": "پژو 206",
      "has_prev_body": "بله",
      "health_inspection": "لازم",
      "note": ""
    }
  ]
}</pre>
                <p class="text-[11px] font-bold text-slate-600 mt-2 mb-1">قالب ۲ - فایل اکسل (xlsx یا csv):</p>
                <p class="text-[10px] text-slate-500 leading-relaxed mb-1">
                    کافی است فایل اکسل را همین‌جا بدهید. ستون‌ها با <b>نامِ سرستون</b> شناخته می‌شوند،
                    پس ترتیبشان مهم نیست و ستون‌های اضافه نادیده گرفته می‌شوند. این نام‌ها شناخته می‌شوند:
                </p>
<pre class="text-[10px] bg-white border rounded-lg p-2 overflow-x-auto">پلاک | شماره شاسی | شماره موتور | ارزش خودرو | تعهد مالی | نوع بیمه نامه | تاریخ انقضا | خودرو | بیمه بدنه قبل | بازدید سلامت | توضیحات</pre>
                <p class="text-[10px] text-slate-500 mt-1">
                    برای لیفتراک و خودروی صفرکیلومتر که پلاک ندارند، ستونِ «پلاک» را خالی بگذارید و فقط
                    «شماره شاسی» را پر کنید؛ تاریخ انقضا هم لازم نیست.
                </p>
                <p class="text-[11px] font-bold text-slate-600 mt-2 mb-1">قالب ۳ - متنِ خط‌به‌خط (هر خط یک ردیف، با «|»):</p>
<pre class="text-[10px] bg-white border rounded-lg p-2 overflow-x-auto" dir="ltr">31 ع 693 ایران 21 | بدنه | 1405/07/30 | پژو 206
12 ن 152 ایران 77 | هردو | 1406/01/05 | پراید</pre>
                <p class="text-[10px] text-slate-500 mt-2 leading-relaxed">
                    • <b>insurance_type</b> می‌تواند «بدنه»، «ثالث» یا «هردو» باشد؛ «هردو» خودش به دو ردیف جدا تبدیل می‌شود.<br>
                    • تاریخ‌ها شمسی‌اند (۱۴۰۵/۰۷/۳۰ یا ۱۴۰۵.۰۷.۳۰).<br>
                    • <b>has_prev_body</b>: «بله» یا «خیر» — برای بدنه، اگر بیمه بدنه قبلی دارد، بارگذاریش اجباری می‌شود.<br>
                    • <b>health_inspection</b>: «لازم» یا «لازم نیست» — برای خودروهایی که بازدید سلامت نمی‌خواهند «لازم نیست» بنویسد.<br>
                    • پلاک را به هر شکلی بنویسد (با یا بدون «ایران») سیستم خودش تشخیص می‌دهد.
                </p>
            </details>

            <textarea id="cli-raw" rows="7" dir="ltr" placeholder='{ "rows": [ ... ] }  یا هر خط: پلاک | نوع | انقضا' class="w-full text-[11px] font-mono border rounded-xl p-3 mb-2"></textarea>
            <div class="float-input"><input type="file" id="cli-file" accept=".json,.txt,.tex,.csv,.xlsx,.xls" placeholder=" "><label>یا فایل: اکسل (xlsx/csv)، JSON یا متن</label></div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600 mb-3 cursor-pointer">
                <input type="checkbox" id="cli-replace"> ردیف‌های قبلیِ صادرنشده را پاک کن و از نو بساز
            </label>
            <div class="flex gap-2">
                <button onclick="previewLetterRows()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 rounded-xl text-sm"><i class="fas fa-eye ml-1"></i>پیش‌نمایش</button>
                <button id="cli-commit-btn" onclick="commitLetterRows()" class="flex-1 hidden bg-violet-600 hover:bg-violet-700 text-white font-bold py-2.5 rounded-xl text-sm"><i class="fas fa-check ml-1"></i>ساختن ردیف‌ها</button>
            </div>
            <div id="cli-preview" class="mt-3"></div>
        </div>
    </div>

    <!-- مودال افزودن/ویرایش شرکت -->
    <div id="add-company-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-lg p-6 relative max-h-[85vh] overflow-y-auto">
            <button type="button" onclick="closeModal('add-company-modal')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4"><span id="cm-form-title">افزودن شرکت جدید</span></h3>
            <input type="hidden" id="cm-company-id">
            <div class="float-input"><input type="text" id="cm-company-name" placeholder=" "><label>نام شرکت</label></div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input"><input type="text" id="cm-economic-code" dir="ltr" placeholder=" "><label>کد اقتصادی</label></div>
                <div class="float-input"><input type="text" id="cm-phone" dir="ltr" placeholder=" "><label>شماره تماس</label></div>
            </div>
            <div class="float-input"><input type="text" id="cm-address" placeholder=" "><label>آدرس</label></div>
            <div class="float-input">
                <select id="cm-parent-company"><option value="">شرکت مستقل / خودش مادر است</option></select>
                <label>شرکت مادر (اگر زیرمجموعه است)</label>
            </div>
            <div class="float-input">
                <select id="cm-group-select" onchange="document.getElementById('cm-group-manual').value = this.value === '__manual__' ? '' : this.value">
                    <option value="">بدون گروه (بعداً قابل تنظیم است)</option>
                    <option value="__manual__">-- وارد کردن دستی شناسه‌ی گروه --</option>
                </select>
                <label>گروه بله‌ی مرتبط</label>
                <input type="text" id="cm-group-manual" placeholder="اگر گروه در لیست نبود، شناسه‌ی چت را اینجا بنویسید" dir="ltr" class="mt-2">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input">
                    <select id="cm-payment-terms">
                        <option value="">مشخص نشده</option>
                        <option value="INSTALLMENT">قسطی</option>
                        <option value="CASH_NET30">نقدی - مهلت ۳۰ روزه</option>
                        <option value="CASH_IMMEDIATE">نقدی - فوری</option>
                    </select>
                    <label>نحوه‌ی تسویه</label>
                </div>
                <div class="float-input">
                    <select id="cm-allowed-insurers">
                        <option value="BOTH">پاسارگاد و ایران (هردو)</option>
                        <option value="PASARGAD">فقط پاسارگاد</option>
                        <option value="IRAN">فقط ایران</option>
                    </select>
                    <label>بیمه‌گر(های) مجاز برای این شرکت</label>
                </div>
            </div>
            <p class="text-xs font-bold text-slate-500 mb-2">تنظیمات قسط‌بندی</p>
            <div class="grid grid-cols-3 gap-3">
                <div class="float-input"><input type="number" id="cm-inst-count" min="1" placeholder=" "><label>تعداد اقساط</label></div>
                <div class="float-input"><input type="number" id="cm-offset-months" min="0" value="0" placeholder=" "><label>سررسید اول (ماه بعد)</label></div>
                <div class="float-input"><input type="number" id="cm-offset-days" min="0" value="0" placeholder=" "><label>+ روز</label></div>
            </div>
            <div class="flex gap-2">
                <button onclick="createCompany()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت شرکت</button>
                <button onclick="resetCompanyForm()" class="px-4 bg-slate-100 hover:bg-slate-200 rounded-xl text-xs font-bold">فرم جدید</button>
            </div>
        </div>
    </div>

    <!-- مودال افزودن حساب کاربری ثبت‌کننده -->
    <div id="add-portal-user-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" onclick="closeModal('add-portal-user-modal')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4"><span id="cm-pu-modal-title">افزودن عضو - حساب کاربری ثبت‌کننده</span></h3>
            <input type="hidden" id="cm-pu-id">
            <p class="text-[11px] text-slate-400 mb-2">اگر کاربر به چند شرکت دسترسی داشته باشد، در پنلش انتخابگر شرکت نشان داده می‌شود؛ اگر فقط یکی انتخاب شود، پنلش قفل روی همان می‌ماند.</p>
            <label class="text-xs font-bold text-slate-500 block mb-2">شرکت(ها)</label>
            <div id="cm-pu-companies" class="border rounded-xl p-3 mb-4 max-h-32 overflow-y-auto text-xs"></div>
            <div class="float-input"><input type="text" id="cm-pu-fullname" placeholder=" "><label>نام و نام‌خانوادگی</label></div>
            <div class="float-input"><input type="text" id="cm-pu-username" dir="ltr" placeholder=" "><label>نام کاربری</label></div>
            <div class="float-input"><input type="text" id="cm-pu-password" dir="ltr" placeholder=" "><label id="cm-pu-password-label">رمز عبور</label></div>
            <div class="float-input"><input type="text" id="cm-pu-mobile" dir="ltr" placeholder=" "><label>شماره موبایل (اختیاری)</label></div>
            <button onclick="submitPortalUser()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl text-sm"><span id="cm-pu-submit-label">ساخت حساب کاربری</span></button>
        </div>
    </div>

    <!-- مودال افزودن عضو داخلی جدید -->
    <div id="add-staff-user-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" onclick="closeModal('add-staff-user-modal')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4">افزودن کاربر داخلی جدید</h3>
            <div class="float-input"><input type="text" id="su-fullname" placeholder=" "><label>نام و نام‌خانوادگی</label></div>
            <div class="float-input"><input type="text" id="su-username" dir="ltr" placeholder=" "><label>نام کاربری</label></div>
            <div class="float-input"><input type="text" id="su-password" dir="ltr" placeholder=" "><label>رمز عبور</label></div>
            <div class="float-input"><input type="text" id="su-mobile" dir="ltr" placeholder=" "><label>شماره موبایل (اختیاری)</label></div>
            <div class="float-input">
                <select id="su-role">
                    <option value="OPERATOR">اپراتور</option>
                    <option value="FINANCE">مالی</option>
                    <option value="COMPANY_LIAISON">همکار بخش شرکت‌ها</option>
                    <option value="ADMIN">مدیر کل</option>
                </select>
                <label>نقش</label>
            </div>
            <button onclick="createStaffUser()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت کاربر</button>
        </div>
    </div>

    <!-- مودال تغییر رمز عبور کاربر داخلی -->
    <div id="reset-password-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-sm p-6 relative">
            <button type="button" onclick="document.getElementById('reset-password-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4">تغییر رمز عبور</h3>
            <div class="float-input"><input type="text" id="rp-password" dir="ltr" placeholder=" "><label>رمز عبور جدید</label></div>
            <button onclick="submitResetPassword()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت رمز جدید</button>
        </div>
    </div>

    <!-- مودال ثبت دریافت از شرکت (مالی شرکتی) -->
    <div id="cpay-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" onclick="closeModal('cpay-modal')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-4">ثبت دریافت از شرکت</h3>
            <input type="hidden" id="cpay-request-id">
            <div class="float-input"><input type="text" id="cpay-amount" dir="ltr" placeholder=" "><label>مبلغ (ریال)</label></div>
            <div class="float-input">
                <select id="cpay-method"><option value="TRANSFER">انتقال بانکی</option><option value="CHEQUE">چک</option><option value="CASH">نقد</option><option value="PAYROLL">کسر از حقوق</option></select>
                <label>روش پرداخت</label>
            </div>
            <div class="float-input"><input type="text" id="cpay-jalali" dir="ltr" placeholder=" "><label>تاریخ (شمسی)</label></div>
            <div class="float-input"><input type="text" id="cpay-ref" dir="ltr" placeholder=" "><label>شماره پیگیری/مرجع</label></div>
            <div class="float-input"><input type="text" id="cpay-note" placeholder=" "><label>توضیحات</label></div>
            <label class="text-xs font-bold text-slate-500 block mb-2">فیش‌ها (چند فایل مجاز)</label>
            <input type="file" id="cpay-files" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" class="mb-4 w-full text-xs">
            <button onclick="submitCompanyPayment()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت دریافتی</button>
        </div>
    </div>

    <!-- مودال ثبت تسویه با پاسارگاد برای اقساط شرکتی -->
    <div id="cpsg-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" onclick="closeModal('cpsg-modal')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1">ثبت تسویه با پاسارگاد</h3>
            <p id="cpsg-summary" class="text-xs text-slate-500 mb-4"></p>
            <div class="float-input"><input type="text" id="cpsg-ref" dir="ltr" placeholder=" "><label>شماره پیگیری/مرجع</label></div>
            <div class="float-input"><input type="text" id="cpsg-jalali" dir="ltr" placeholder=" "><label>تاریخ پرداخت (شمسی)</label></div>
            <div class="float-input"><input type="text" id="cpsg-note" placeholder=" "><label>توضیحات</label></div>
            <label class="text-xs font-bold text-slate-500 block mb-2">فیش‌های پرداختی (چند فایل مجاز)</label>
            <input type="file" id="cpsg-files" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" class="mb-4 w-full text-xs">
            <button onclick="submitCompanyPasargad()" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت تسویه</button>
        </div>
    </div>

    <!-- مودال ثبت تسویه با پاسارگاد (همراه با فیش) -->
    <div id="psg-settle-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative">
            <button type="button" onclick="closeModal('psg-settle-modal')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1">ثبت تسویه با پاسارگاد</h3>
            <p id="psg-settle-summary" class="text-xs text-slate-500 mb-4"></p>
            <div class="float-input"><input type="text" id="psg-settle-ref" dir="ltr" placeholder=" "><label>شماره پیگیری/مرجع</label></div>
            <div class="float-input"><input type="text" id="psg-settle-jalali" dir="ltr" placeholder=" "><label>تاریخ پرداخت (شمسی)</label></div>
            <div class="float-input"><input type="text" id="psg-settle-note" placeholder=" "><label>توضیحات</label></div>
            <label class="text-xs font-bold text-slate-500 block mb-2">فیش‌های پرداختی (چند فایل مجاز)</label>
            <input type="file" id="psg-settle-files" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" class="mb-4 w-full text-xs">
            <button onclick="submitPasargadSettle()" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت تسویه</button>
        </div>
    </div>

    <!-- مودال ثبت دستی پرونده جدید -->
    <div id="manual-create-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-8 relative">
            <button type="button" onclick="document.getElementById('manual-create-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h2 class="text-xl font-black mb-6 text-slate-800 text-center border-b border-slate-100 pb-3"><i class="fas fa-user-plus text-blue-500 ml-2"></i>ثبت دستی پرونده جدید</h2>
            
            <p class="text-[11px] text-slate-500 mb-5 text-center bg-slate-50 p-2 rounded-lg border border-slate-100">
                برای زمانی که تشخیص خودکار PDF انجام نشده یا درخواست فیزیکی است.
            </p>

            <div class="float-input">
                <input type="text" id="mc-full-name" placeholder=" " autocomplete="off">
                <label>نام و نام خانوادگی</label>
            </div>
            <div class="float-input">
                <input type="text" id="mc-national-code" placeholder=" " dir="ltr" maxlength="10" autocomplete="off">
                <label>کد ملی (۱۰ رقم)</label>
            </div>
            <div class="float-input">
                <input type="text" id="mc-personnel-code" placeholder=" " dir="ltr" autocomplete="off">
                <label>کد پرسنلی</label>
            </div>
            <div class="float-input">
                <input type="text" id="mc-company-name" placeholder=" " autocomplete="off">
                <label>شرکت (مثال: ماموت)</label>
            </div>
            
            <p class="text-[11px] text-slate-400 mb-5 text-center">
                نوع بیمه‌نامه اینجا مشخص نمی‌شود؛ بعد از ثبت، از داخل جزئیات معرفی‌نامه‌ی همین شخص، برای هر درخواست/پرونده به‌طور جداگانه نوع بیمه (ثالث یا بدنه) انتخاب می‌شود.
            </p>

            <button onclick="submitManualCreate()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-3 rounded-xl shadow-lg shadow-blue-500/30 hover-target transition-colors">ثبت و ایجاد معرفی‌نامه</button>
        </div>
    </div>

    <!-- مودال ویرایش پروفایل -->
    <div id="profile-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-8 relative">
            <button type="button" onclick="document.getElementById('profile-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h2 class="text-xl font-black mb-6 text-slate-800 text-center border-b border-slate-100 pb-3"><i class="fas fa-user-edit text-blue-500 ml-2"></i>ویرایش پروفایل من</h2>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_profile">
                <div class="float-input">
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($_SESSION['full_name']); ?>" placeholder=" " required autocomplete="off">
                    <label>نام کامل شما</label>
                </div>
                <div class="float-input">
                    <input type="password" name="new_password" placeholder=" " autocomplete="off">
                    <label>رمز عبور جدید (اختیاری)</label>
                </div>
                <div class="text-[11px] text-slate-500 mb-5 text-center bg-slate-50 p-2 rounded-lg border border-slate-100">
                    <i class="fas fa-info-circle text-blue-500"></i> سطح دسترسی شما (<strong class="text-blue-600" dir="ltr"><?php echo htmlspecialchars($_SESSION['role']); ?></strong>) غیرقابل تغییر است.
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-3 rounded-xl shadow-lg shadow-blue-500/30 transition-colors hover-target">ذخیره تغییرات</button>
            </form>
        </div>
    </div>

    <!-- مودال ویرایش پرونده -->
    <div id="edit-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-8 relative">
            <button type="button" onclick="document.getElementById('edit-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h2 class="text-xl font-black mb-6 text-slate-800 text-center border-b pb-3"><i class="fas fa-edit text-blue-500 ml-2"></i>ویرایش پرونده</h2>
            
            <div class="float-input">
                <input type="text" id="edit-full-name" placeholder=" " autocomplete="off">
                <label>نام و نام خانوادگی</label>
            </div>
            <div class="float-input">
                <input type="text" id="edit-national-code" placeholder=" " autocomplete="off">
                <label>کد ملی</label>
            </div>
            <div class="float-input">
                <input type="text" id="edit-personnel-code" placeholder=" " autocomplete="off">
                <label>کد پرسنلی</label>
            </div>
            <div class="float-input">
                <select id="edit-insurance-type" class="!bg-white">
                    <option value="در انتظار انتخاب">در انتظار انتخاب</option>
                    <option value="بیمه شخص ثالث">بیمه شخص ثالث</option>
                    <option value="بیمه بدنه">بیمه بدنه</option>
                    <option value="الحاقیه">الحاقیه</option>
                </select>
                <label>نوع درخواست</label>
            </div>
            <div class="float-input">
                <select id="edit-status" class="!bg-white text-blue-600">
                    <option value="NEW">پرونده جدید</option>
                    <option value="WAITING_FOR_DOCUMENTS">در انتظار مدارک</option>
                    <option value="DOCUMENTS_REVIEW">بررسی مدارک</option>
                    <option value="DOCUMENTS_APPROVED">تأیید مدارک</option>
                    <option value="QUOTE_ANNOUNCED">نرخ اعلام شده</option>
                    <option value="READY_FOR_ISSUE">آماده صدور</option>
                    <option value="ISSUED">بیمه‌نامه صادر شده</option>
                    <option value="CANCELLED">لغو شده</option>
                </select>
                <label>وضعیت فعلی سیستم</label>
            </div>
            
            <button type="button" onclick="saveRecordEdit()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-3 rounded-xl shadow-lg shadow-blue-500/30 hover-target transition-colors">ذخیره تغییرات</button>
        </div>
    </div>

    <!-- دیالوگ تایید حذف یک ردیف -->
    <!-- دیالوگ تایید عمومی (جایگزین confirm() مرورگر) -->
    <div id="generic-confirm-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-sm p-6 relative text-center">
            <button type="button" onclick="document.getElementById('generic-confirm-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <div class="w-16 h-16 bg-amber-100 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-inner"><i class="fas fa-question"></i></div>
            <h3 id="generic-confirm-title" class="text-lg font-black text-slate-800 mb-2">تایید عملیات</h3>
            <p id="generic-confirm-message" class="text-xs text-slate-500 mb-6"></p>
            <div class="flex gap-3">
                <button type="button" onclick="executeGenericConfirm()" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs">بله، مطمئنم</button>
                <button type="button" onclick="document.getElementById('generic-confirm-modal').classList.remove('active')" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl transition-colors hover-target text-xs">انصراف</button>
            </div>
        </div>
    </div>

    <!-- دیالوگ ورودی متن (جایگزین prompt() مرورگر) -->
    <div id="generic-prompt-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-sm p-6 relative">
            <button type="button" onclick="document.getElementById('generic-prompt-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 id="generic-prompt-title" class="text-lg font-black text-slate-800 mb-4">ویرایش</h3>
            <textarea id="generic-prompt-input" rows="3" class="w-full border rounded-xl p-3 text-sm mb-4"></textarea>
            <div class="flex gap-3">
                <button type="button" onclick="executeGenericPrompt()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs">ذخیره</button>
                <button type="button" onclick="document.getElementById('generic-prompt-modal').classList.remove('active')" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl transition-colors hover-target text-xs">انصراف</button>
            </div>
        </div>
    </div>

    <div id="confirm-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-sm p-6 relative text-center">
            <button type="button" onclick="document.getElementById('confirm-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <div class="w-16 h-16 bg-red-100 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-inner"><i class="fas fa-trash-alt"></i></div>
            <h3 class="text-lg font-black text-slate-800 mb-2">تایید حذف پرونده</h3>
            <p class="text-xs text-slate-500 mb-6">آیا از انتقال این پرونده به سطل حذف اطمینان دارید؟</p>
            <div class="flex gap-3">
                <button type="button" onclick="executeDeleteRow()" class="flex-1 bg-red-500 hover:bg-red-600 text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs">بله، حذف شود</button>
                <button type="button" onclick="document.getElementById('confirm-modal').classList.remove('active')" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl transition-colors hover-target text-xs">انصراف</button>
            </div>
        </div>
    </div>

    <!-- دیالوگ تایید ریست کل سیستم -->
    <div id="reset-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-sm p-6 relative text-center border border-red-200">
            <button type="button" onclick="document.getElementById('reset-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <div class="w-16 h-16 bg-red-500 text-white rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-lg shadow-red-500/30"><i class="fas fa-radiation-alt"></i></div>
            <h3 class="text-lg font-black text-red-600 mb-2">هشدار پاکسازی کامل</h3>
            <p class="text-xs text-slate-500 mb-4 leading-relaxed">تمام معرفی‌نامه‌ها، پرونده‌ها، مدارک، تیکت‌ها، اعلان‌های اپ، نشست‌های ورود به مینی‌اپ، چت‌های ذخیره‌شده‌ی بات و کل بایگانی فایل‌های هاست برای همیشه پاک می‌شوند (سایت دقیقاً مثل روز اول می‌شود). حساب‌های مدیر و تنظیمات بات دست‌نخورده می‌مانند.</p>
            <input type="password" id="reset-password-input" placeholder="رمز تایید را وارد کنید" class="w-full border rounded-xl px-3 py-2.5 text-sm text-center mb-4" dir="ltr">
            <div class="flex gap-3">
                <button type="button" onclick="executeResetAll()" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs">بله، همه‌چیز پاک شود</button>
                <button type="button" onclick="document.getElementById('reset-modal').classList.remove('active')" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl transition-colors hover-target text-xs">انصراف</button>
            </div>
        </div>
    </div>

    <!-- مودال بررسی و تایید OCR (طراحی دو تکه و ریسپانسیو) -->
    <div id="ocr-review-modal" class="modal-overlay">
        <div class="modal-content w-full h-[95vh] max-w-6xl flex flex-col md:flex-row relative !p-0">
            <button type="button" onclick="document.getElementById('ocr-review-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-2xl z-50 bg-white rounded-full w-8 h-8 flex items-center justify-center shadow hover-target"><i class="fas fa-times"></i></button>
            
            <!-- بخش نمایش فایل (راست) -->
            <div class="w-full md:w-1/2 h-1/2 md:h-full bg-slate-100 border-b md:border-b-0 md:border-l border-slate-300 p-2">
                <iframe id="ocr-file-viewer" src="" class="w-full h-full rounded-xl border border-slate-300 shadow-inner bg-white"></iframe>
            </div>

            <!-- بخش فرم دیتا (چپ) -->
            <div class="w-full md:w-1/2 h-1/2 md:h-full overflow-y-auto p-6 lg:p-8 bg-white">
                <h2 class="text-xl font-black mb-2 text-slate-800"><i class="fas fa-check-double text-emerald-500 ml-2"></i>تایید اطلاعات استخراج شده</h2>
                <p class="text-xs text-slate-500 mb-6 pb-4 border-b border-slate-100">در صورت وجود خطای تشخیصی در متن خوانده شده توسط هوش مصنوعی، آن را اصلاح کرده و سپس روی تایید کلیک کنید تا وارد پرونده اصلی شود.</p>
                
                <input type="hidden" id="review-queue-id">

                <div class="float-input">
                    <input type="text" id="review-sender" placeholder=" " readonly disabled class="!bg-slate-50 !text-slate-500">
                    <label>فرستنده (بله)</label>
                </div>

                <div class="float-input">
                    <select id="review-doc-type" class="!bg-white text-blue-600" onchange="applyReviewFieldVisibility()">
                        <option value="معرفی‌نامه">معرفی‌نامه پرسنلی</option>
                        <option value="ثالث">بیمه شخص ثالث</option>
                        <option value="بدنه">بیمه بدنه</option>
                        <option value="کارت ماشین">کارت ماشین</option>
                        <option value="ناشناخته">سایر مدارک / ناشناخته</option>
                    </select>
                    <label>نوع مدرک تشخیص داده شده</label>
                </div>

                <div class="float-input"><input type="text" id="review-full-name" placeholder=" " autocomplete="off"><label>نام شخص / بیمه‌گذار</label></div>
                <div class="float-input"><input type="text" id="review-national-code" placeholder=" " dir="ltr" autocomplete="off"><label>کد ملی</label></div>

                <!-- فیلدهای اختصاصی معرفی‌نامه -->
                <div class="float-input" id="rbox-personnel"><input type="text" id="review-personnel-code" placeholder=" " dir="ltr" autocomplete="off"><label>کد پرسنلی</label></div>
                <div class="float-input" id="rbox-companyname"><input type="text" id="review-company" placeholder=" " autocomplete="off"><label>نام شرکت</label></div>
                <div class="float-input" id="rbox-letterdate"><input type="text" id="review-letterdate" placeholder=" " dir="ltr" autocomplete="off"><label>تاریخ صدور معرفی‌نامه</label></div>

                <!-- فیلدهای مشترک بیمه‌نامه (بدنه/ثالث) -->
                <div class="float-input" id="rbox-policy"><input type="text" id="review-policy" placeholder=" " dir="ltr" autocomplete="off"><label>شماره بیمه‌نامه</label></div>
                <div class="float-input" id="rbox-uniquecode"><input type="text" id="review-uniquecode" placeholder=" " dir="ltr" autocomplete="off"><label>شماره یکتا بیمه مرکزی</label></div>
                <div class="float-input" id="rbox-plate"><input type="text" id="review-plate" placeholder=" " dir="ltr" autocomplete="off" class="plate-display"><label>پلاک خودرو</label></div>
                <div class="float-input" id="rbox-phone"><input type="text" id="review-phone" placeholder=" " dir="ltr" autocomplete="off"><label>شماره تماس بیمه‌گذار</label></div>
                <div class="float-input" id="rbox-issuedate"><input type="text" id="review-issuedate" placeholder=" " dir="ltr" autocomplete="off"><label>تاریخ صدور بیمه‌نامه</label></div>
                <div class="float-input" id="rbox-renewal"><input type="text" id="review-renewal" placeholder=" " autocomplete="off"><label>وضعیت تمدید</label></div>
                <div class="float-input" id="rbox-cartype"><input type="text" id="review-cartype" placeholder=" " autocomplete="off"><label>سیستم / تیپ خودرو</label></div>
                <div class="float-input" id="rbox-model"><input type="text" id="review-model" placeholder=" " dir="ltr" autocomplete="off"><label>مدل (سال ساخت)</label></div>
                <div class="float-input" id="rbox-color"><input type="text" id="review-color" placeholder=" " autocomplete="off"><label>رنگ خودرو</label></div>
                <div class="float-input" id="rbox-engine"><input type="text" id="review-engine" placeholder=" " dir="ltr" autocomplete="off"><label>شماره موتور</label></div>
                <div class="float-input" id="rbox-chassis"><input type="text" id="review-chassis" placeholder=" " dir="ltr" autocomplete="off"><label>شماره شاسی</label></div>
                <div class="float-input" id="rbox-vin"><input type="text" id="review-vin" placeholder=" " dir="ltr" autocomplete="off"><label>شماره شاسی (VIN)</label></div>
                <div class="float-input" id="rbox-carvalue"><input type="text" id="review-carvalue" placeholder=" " dir="ltr" autocomplete="off"><label>ارزش خودرو (ریال)</label></div>
                <div class="float-input" id="rbox-premium"><input type="text" id="review-premium" placeholder=" " dir="ltr" autocomplete="off"><label>حق بیمه کل (ریال)</label></div>

                <div class="flex gap-3 mt-6">
                    <button onclick="approveOcrData()" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white font-black py-3.5 rounded-xl shadow-lg shadow-emerald-500/30 transition-colors hover-target">تایید نهایی و بایگانی</button>
                    <button onclick="rejectOcrData()" class="px-6 bg-red-50 hover:bg-red-100 text-red-600 font-bold py-3.5 rounded-xl transition-colors hover-target">رد فایل</button>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال پاپ‌آپ رد و حذف فایل در صف OCR -->
    <div id="ocr-reject-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-sm p-6 relative text-center">
            <button type="button" onclick="document.getElementById('ocr-reject-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <div class="w-16 h-16 bg-red-100 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-inner"><i class="fas fa-trash-alt"></i></div>
            <h3 class="text-lg font-black text-slate-800 mb-2">تایید رد فایل</h3>
            <p class="text-xs text-slate-500 mb-6">آیا از رد و حذف این فایل اطمینان دارید؟ این عمل غیرقابل بازگشت است.</p>
            <div class="flex gap-3">
                <button type="button" onclick="executeRejectOcr()" class="flex-1 bg-red-500 hover:bg-red-600 text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs">بله، حذف شود</button>
                <button type="button" onclick="document.getElementById('ocr-reject-modal').classList.remove('active')" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl transition-colors hover-target text-xs">انصراف</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/tsparticles@2.12.0/tsparticles.bundle.min.js"></script>
    <script>
        // این ثابت باید همین بالا تعریف شود: loadCompanyInbox() در ادامه‌ی همین اسکریپت
        // بلافاصله پس از لود صدا زده می‌شود، ولی تعریفش پایین‌تر بود و const در ناحیه‌ی
        // مرده‌ی زمانی (TDZ) است - نتیجه‌اش ReferenceError بود و صندوق ورودی مدارک و
        // شمارنده‌اش هرگز موقعِ باز شدنِ پنل بار نمی‌شد (و برای همکار شرکت‌ها که مستقیم
        // روی تبِ شرکت‌ها می‌نشیند، همان اول کار پنل با خطا بالا می‌آمد).
        const COMPANY_API = 'api/company_actions.php';

        let currentRecordsData = [];
        let activeRowId = null;
        let activeRowNational = '';
        let isBotEnabledState = true;
        let currentQueueData = [];
        
        // متغیرهای ذخیره موقت وضعیت برای منوی راست کلیک
        let ctxSelectedText = '';
        let ctxActiveElement = null;

        const e2p = s => s ? s.toString().replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]) : '-';
        // برای شمارش‌ها، صفر باید «۰» نشان داده شود نه «-» (e2p صفر را falsy می‌گیرد)
        const e2pNum = n => e2p(String(Number(n) || 0));
        // قالب‌بندی مبلغ: همیشه سه‌رقم‌سه‌رقم جداشده و با ارقام فارسی - برای همه‌ی مبالغ پنل
        // (حق بیمه، ارزش خودرو، سقف تعهد مالی و هر مبلغ دیگر) از همین تابع استفاده می‌شود
        const money = v => {
            if (v === null || v === undefined || v === '') return '-';
            const n = Number(String(v).replace(/[^\d.-]/g, ''));
            if (isNaN(n)) return e2p(v);
            return e2p(n.toLocaleString('en-US'));
        };
        // پلاک را با چیدمان DOM (اسپن‌های جدا داخل فلکس) می‌سازیم، نه صرفاً با CSS bidi-override؛
        // چون bidi-override به‌تنهایی می‌تواند شکل حروف فارسی/عربی را هم به‌هم بریزد.
        function formatPlateHtml(plate) {
            if (!plate) return '-';
            const m = plate.match(/^(\d{2})ایران\s*-\s*(\d{3})\s*(\S+)\s*(\d{2})$/);
            if (!m) return plate;
            const [, region, suffix, letter, prefix] = m;
            const seg = s => `<span>${e2p(s)}</span>`;
            return `<span style="display:inline-flex;direction:ltr;gap:4px;align-items:center;unicode-bidi:isolate">` +
                seg(prefix) + `<span>${letter}</span>` + seg(suffix) + `<span>ایران</span>` + seg(region) +
                `</span>`;
        }
        // مسیرهای ذخیره‌شده ممکن است شامل فاصله، پرانتز یا حروف فارسی باشند (نام پوشه‌های بایگانی)؛
        // برای اینکه مرورگر/سرور همیشه درست بارشان کند، هر بخش از مسیر را جداگانه encode می‌کنیم
        const encodeFilePath = p => (p || '').split('/').map(encodeURIComponent).join('/');

        // ---- تبدیل هر تاریخ (میلادی/رشته‌ی MySQL) به شمسی، برای نمایش یکدست در کل پنل ----
        function toJalali(input, withTime = true) {
            if (!input) return '-';
            // اگر از قبل به فرم "1405/06/10" (رشته‌ی جلالی خام ذخیره‌شده در introductions.letter_date) باشد، همان را برگردان
            if (typeof input === 'string' && /^1[34]\d{2}\/\d{1,2}\/\d{1,2}/.test(input)) return e2p(input);

            const d = new Date(input.replace ? input.replace(' ', 'T') : input);
            if (isNaN(d.getTime())) return e2p(input);

            let gy = d.getFullYear(), gm = d.getMonth() + 1, gd = d.getDate();
            const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            let gy2 = (gm > 2) ? (gy + 1) : gy;
            let days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
            let jy = -1595 + (33 * Math.floor(days / 12053));
            days %= 12053;
            jy += 4 * Math.floor(days / 1461);
            days %= 1461;
            if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
            let jm, jd;
            if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
            else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }

            let datePart = `${jy}/${String(jm).padStart(2,'0')}/${String(jd).padStart(2,'0')}`;
            if (!withTime) return e2p(datePart);
            let timePart = `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
            return e2p(`${datePart} - ${timePart}`);
        }

        // ---- مودال تایید عمومی (جایگزین confirm() مرورگر با استایل شیشه‌ای سایت) ----
        let _confirmCallback = null;
        function showConfirm(title, message, onConfirm) {
            document.getElementById('generic-confirm-title').innerText = title;
            document.getElementById('generic-confirm-message').innerText = message;
            _confirmCallback = onConfirm;
            document.getElementById('generic-confirm-modal').classList.add('active');
        }
        function executeGenericConfirm() {
            document.getElementById('generic-confirm-modal').classList.remove('active');
            if (typeof _confirmCallback === 'function') _confirmCallback();
        }

        // ---- دیالوگ ورودی متن (جایگزین prompt() مرورگر) ----
        let _promptCallback = null;
        function showPrompt(title, currentValue, onSubmit) {
            document.getElementById('generic-prompt-title').innerText = title;
            document.getElementById('generic-prompt-input').value = currentValue || '';
            _promptCallback = onSubmit;
            document.getElementById('generic-prompt-modal').classList.add('active');
            setTimeout(() => document.getElementById('generic-prompt-input').focus(), 50);
        }
        function executeGenericPrompt() {
            const value = document.getElementById('generic-prompt-input').value;
            document.getElementById('generic-prompt-modal').classList.remove('active');
            if (typeof _promptCallback === 'function') _promptCallback(value);
        }
        const p2e = s => s ? s.toString().replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)) : '';

        // رویدادهای مربوط به کپی ارقام
        document.addEventListener('copy', (event) => {
            const selection = document.getSelection();
            if (!selection.isCollapsed) {
                const text = p2e(selection.toString());
                event.clipboardData.setData('text/plain', text);
                event.preventDefault();
                showToast('متن با ارقام انگلیسی در کلیپ‌بورد کپی شد.', 'info');
            }
        });

        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });

        async function loadStats() {
            try {
                const res = await fetch('api/record_actions.php?action=stats');
                const data = await res.json();
                if (data.ok && data.stats) {
                    document.getElementById('stat-total').innerText = e2p(data.stats.total);
                    document.getElementById('stat-new').innerText = e2p(data.stats.new);
                    document.getElementById('stat-pending').innerText = e2p(data.stats.pending);
                    document.getElementById('stat-issued').innerText = e2p(data.stats.issued);
                }
            } catch (e) {}
        }

        async function checkBotStatus() {
            try {
                const res = await fetch('api/bot_status.php');
                const data = await res.json();
                const dot = document.getElementById('bot-pulse-dot');
                const txt = document.getElementById('bot-status-text');
                const btnToggle = document.getElementById('btn-toggle-bot');

                if (data.ok) {
                    isBotEnabledState = data.is_enabled;
                    if (!data.is_enabled) {
                        dot.className = 'pulse-dot offline';
                        txt.className = 'text-xs font-bold text-amber-500';
                        txt.innerText = 'ربات بله: متوقف شده از پنل (Mute)';
                        btnToggle.innerHTML = '<i class="fas fa-play text-xs text-emerald-400"></i> <span>روشن کردن</span>';
                    } else if (data.is_online) {
                        dot.className = 'pulse-dot online';
                        txt.className = 'text-xs font-bold text-emerald-600';
                        txt.innerText = 'ربات بله: آنلاین و فعال';
                        btnToggle.innerHTML = '<i class="fas fa-pause text-xs text-amber-400"></i> <span>خاموش کردن</span>';
                    } else {
                        dot.className = 'pulse-dot offline';
                        txt.className = 'text-xs font-bold text-red-500';
                        txt.innerText = 'ربات بله: غیرفعال / منتظر اجرا';
                        btnToggle.innerHTML = '<i class="fas fa-power-off text-xs"></i> <span>تغییر وضعیت</span>';
                    }
                }
            } catch (e) {}
        }

        async function toggleBotPower() {
            try {
                const nextState = !isBotEnabledState;
                const res = await fetch('api/bot_status.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'toggle_power', enabled: nextState})
                });
                const data = await res.json();
                if (data.ok) {
                    showToast(nextState ? 'ربات با موفقیت فعال شد.' : 'فعالیت ربات موقتاً متوقف شد.', 'info');
                    checkBotStatus();
                }
            } catch (e) {
                showToast('خطا در تغییر وضعیت ربات', 'error');
            }
        }

        setInterval(checkBotStatus, 5000);
        checkBotStatus();
        loadStats();
        <?php if ($canSeeCompanies): ?>
        loadCompanyInbox();
        setInterval(loadCompanyInbox, 30000);
        // همگام‌سازی خودکار: هر تبی که همین الان باز است، هر ۲۰ ثانیه خودش را تازه می‌کند
        setInterval(() => {
            const visible = id => document.getElementById('tab-' + id) && !document.getElementById('tab-' + id).classList.contains('hidden');
            if (visible('companies-requests')) loadCompanyRequests();
            if (visible('companies-finance')) loadCompanyFinance();
            if (visible('companies-manage')) loadCompanyManage();
            if (visible('staff-users')) loadStaffUsers();
        }, 20000);
        <?php endif; ?>
        <?php if ($isLiaison): ?>
        switchTab('companies-requests');
        <?php endif; ?>

        async function loadRecords() {
            loadStats();
            const tbody = document.getElementById('records-body');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin text-xl"></i> در حال خواندن اطلاعات از دیتابیس...</td></tr>';
            
            try {
                const res = await fetch('api/records.php');
                const data = await res.json();
                if (data.ok) { currentRecordsData = data.data; renderRecordsTable(currentRecordsData); }
                else tbody.innerHTML = '<tr><td colspan="6" class="text-center p-8 text-red-500">خطا در خواندن اطلاعات.</td></tr>';
            } catch (error) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center p-8 text-red-500">خطا در برقراری ارتباط با سرور.</td></tr>';
            }
        }

        function renderRecordsTable(list) {
            const tbody = document.getElementById('records-body');
            if (list.length === 0) { tbody.innerHTML = '<tr><td colspan="6" class="text-center p-8 text-slate-400">موردی یافت نشد.</td></tr>'; return; }
            tbody.innerHTML = '';
            list.forEach((row, index) => {
                tbody.innerHTML += `
                    <tr class="hover:bg-blue-50/50 transition cursor-pointer border-b border-slate-50 hover-target" onclick="openIntroDetail(${row.introduction_id}, '${(row.full_name||'').replace(/'/g,"")}')">
                        <td class="p-4 font-mono text-slate-400">${e2p(index + 1)}</td>
                        <td class="p-4 text-slate-700">${row.full_name || '-'}</td>
                        <td class="p-4 text-slate-500 font-bold" dir="ltr">${e2p(row.national_code) || '-'}</td>
                        <td class="p-4 text-slate-500 font-bold" dir="ltr">${e2p(row.personnel_code) || '-'}</td>
                        <td class="p-4 text-blue-600 text-xs">${row.issued_summary || '-'} <span class="text-slate-400">(سهمیه: ${e2p(row.quota_summary)})</span><br><span class="text-[10px] text-slate-400">${row.relationship_summary || ''}</span></td>
                        <td class="p-4"><button class="bg-indigo-100 text-indigo-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-indigo-200"><i class="fas fa-folder-open ml-1"></i>جزئیات (${e2p(row.total_cases)} بیمه‌نامه)</button></td>
                    </tr>
                `;
            });
        }

        document.getElementById('records-search-input').addEventListener('input', e => {
            const q = e.target.value.trim().toLowerCase();
            if (!q) { renderRecordsTable(currentRecordsData); return; }
            const filtered = currentRecordsData.filter(row => {
                const haystack = [
                    row.full_name, row.national_code, row.personnel_code, row.company_name,
                    row.status_farsi, row.issued_summary, row.relationship_summary,
                ].filter(Boolean).join(' ').toLowerCase();
                return haystack.includes(q);
            });
            renderRecordsTable(filtered);
        });

        // ======================= بایگانی و مدیریت فایل‌ها =======================
        let fmCurrentPath = '';
        let fmSyncInterval = null;

        function fmIconFor(type, icon) {
            if (type === 'dir') return '<i class="fas fa-folder text-amber-400 text-3xl"></i>';
            if (icon === 'image') return '<i class="fas fa-file-image text-blue-400 text-3xl"></i>';
            if (icon === 'pdf') return '<i class="fas fa-file-pdf text-red-400 text-3xl"></i>';
            return '<i class="fas fa-file text-slate-400 text-3xl"></i>';
        }

        function fmRenderBreadcrumb(path) {
            const bc = document.getElementById('fm-breadcrumb');
            const parts = path ? path.split('/') : [];
            let html = `<span class="cursor-pointer hover:text-emerald-600" onclick="fmOpen('')"><i class="fas fa-warehouse ml-1"></i>بایگانی</span>`;
            let accum = '';
            parts.forEach(p => {
                accum += (accum ? '/' : '') + p;
                const target = accum;
                html += ` <i class="fas fa-chevron-left text-[9px] text-slate-300"></i> <span class="cursor-pointer hover:text-emerald-600" onclick="fmOpen('${target.replace(/'/g,"")}')">${p}</span>`;
            });
            bc.innerHTML = html;
        }

        async function fmOpen(path) {
            fmCurrentPath = path;
            document.getElementById('fm-search-results').classList.add('hidden');
            document.getElementById('fm-search-input').value = '';
            document.getElementById('fm-content').classList.remove('hidden');
            fmRenderBreadcrumb(path);
            const content = document.getElementById('fm-content');
            // بدون اسپینر: محتوای قبلی تا رسیدن داده‌ی جدید همان‌جا می‌ماند، بدون پرش/چشمک لود
            try {
                const res = await fetch('api/file_manager.php?action=list&path=' + encodeURIComponent(path));
                const data = await res.json();
                if (!data.ok) { content.innerHTML = `<div class="col-span-full card p-10 text-center text-red-500">${data.error}</div>`; return; }
                if (data.items.length === 0) { content.innerHTML = '<div class="col-span-full card p-10 text-center text-slate-400"><i class="fas fa-inbox text-3xl mb-2 block opacity-50"></i>این پوشه خالی است.</div>'; return; }

                content.innerHTML = data.items.map(item => {
                    if (item.type === 'dir') {
                        return `<div class="card p-4 hover:shadow-lg transition-all border border-slate-100 fm-item" oncontextmenu="fmContextMenu(event, 'dir', '${item.path.replace(/'/g,"")}')">
                            <div class="cursor-pointer flex-1" ondblclick="fmOpen('${item.path.replace(/'/g,"")}')" title="برای ورود دوبار کلیک کنید">
                                ${fmIconFor('dir')}
                                <p class="text-xs font-bold text-slate-700 mt-2 break-words">${item.name}</p>
                                <p class="text-[10px] text-slate-400 mt-1">${e2p(item.file_count)} فایل</p>
                                <p class="text-[9px] text-slate-400">ویرایش: <span dir="ltr">${toJalali(item.modified, false)}</span></p>
                            </div>
                            <a href="api/file_manager.php?action=zip_folder&path=${encodeURIComponent(item.path)}" class="mt-3 block text-center bg-emerald-50 text-emerald-600 hover:bg-emerald-100 rounded-lg py-1.5 text-[11px] font-bold"><i class="fas fa-file-zipper ml-1"></i>دانلود ZIP این پوشه</a>
                        </div>`;
                    } else {
                        const isImg = item.icon === 'image';
                        const preview = isImg ? `<img src="/Archive/بایگانی/${encodeFilePath(item.path)}" onclick="openImageZoom('/Archive/بایگانی/${encodeFilePath(item.path)}')" class="w-full h-24 object-cover rounded-lg cursor-zoom-in border">` : `<div class="h-24 flex items-center justify-center">${fmIconFor('file', item.icon)}</div>`;
                        return `<div class="card p-4 border border-slate-100 fm-item" oncontextmenu="fmContextMenu(event, 'file', '${item.path.replace(/'/g,"")}')">
                            ${preview}
                            <p class="text-[11px] font-bold text-slate-700 mt-2 break-words" dir="ltr">${item.name}</p>
                            <p class="text-[10px] text-slate-400">${item.size}</p>
                            <p class="text-[9px] text-slate-400">ایجاد: <span dir="ltr">${toJalali(item.created, false)}</span></p>
                            <p class="text-[9px] text-slate-400">ویرایش: <span dir="ltr">${toJalali(item.modified, false)}</span></p>
                            <a href="${item.download_url}" class="mt-2 block text-center bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-lg py-1.5 text-[11px] font-bold"><i class="fas fa-download ml-1"></i>دانلود</a>
                        </div>`;
                    }
                }).join('');
            } catch(e) { content.innerHTML = '<div class="col-span-full card p-10 text-center text-red-500">خطا در اتصال به سرور.</div>'; }
        }

        // ---- منوی راست‌کلیک برای پوشه/فایل ----
        function fmContextMenu(ev, type, path) {
            ev.preventDefault();
            let menu = document.getElementById('fm-context-menu');
            if (!menu) {
                menu = document.createElement('div');
                menu.id = 'fm-context-menu';
                menu.className = 'fixed bg-white shadow-xl rounded-xl border py-1 z-[9999] text-xs font-bold min-w-[140px]';
                document.body.appendChild(menu);
            }
            const items = type === 'dir'
                ? [
                    ['<i class="fas fa-door-open ml-2 text-emerald-500"></i>ورود به پوشه', () => fmOpen(path)],
                    ['<i class="fas fa-file-zipper ml-2 text-blue-500"></i>دانلود ZIP', () => window.open('api/file_manager.php?action=zip_folder&path=' + encodeURIComponent(path), '_blank')],
                  ]
                : [
                    ['<i class="fas fa-eye ml-2 text-slate-500"></i>باز کردن', () => window.open('api/file_manager.php?action=download&path=' + encodeURIComponent(path), '_blank')],
                    ['<i class="fas fa-download ml-2 text-blue-500"></i>دانلود', () => window.open('api/file_manager.php?action=download&path=' + encodeURIComponent(path), '_blank')],
                  ];
            menu.innerHTML = items.map((it, i) => `<div class="px-4 py-2 hover:bg-slate-50 cursor-pointer" data-i="${i}">${it[0]}</div>`).join('');
            menu.style.left = ev.pageX + 'px';
            menu.style.top = ev.pageY + 'px';
            menu.style.display = 'block';
            items.forEach((it, i) => { menu.children[i].onclick = () => { it[1](); menu.style.display = 'none'; }; });
            document.addEventListener('click', function hideMenu() { menu.style.display = 'none'; document.removeEventListener('click', hideMenu); }, { once: true });
        }

        function fmRefresh() { fmOpen(fmCurrentPath); }

        async function fmSearch(q) {
            const resultsBox = document.getElementById('fm-search-results');
            const content = document.getElementById('fm-content');
            if (!q || q.length < 2) { resultsBox.classList.add('hidden'); content.classList.remove('hidden'); return; }
            content.classList.add('hidden');
            resultsBox.classList.remove('hidden');
            resultsBox.innerHTML = '<div class="card p-6 text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i></div>';
            try {
                const res = await fetch('api/file_manager.php?action=search&q=' + encodeURIComponent(q));
                const data = await res.json();
                if (!data.ok || data.data.length === 0) { resultsBox.innerHTML = '<div class="card p-6 text-center text-slate-400">نتیجه‌ای یافت نشد.</div>'; return; }
                resultsBox.innerHTML = data.data.map(r => `
                    <div onclick="fmOpen('${r.path.replace(/'/g,"")}')" class="card p-3 flex items-center gap-3 cursor-pointer hover:bg-emerald-50/50">
                        <i class="fas ${r.type === 'dir' ? 'fa-folder text-amber-400' : 'fa-file text-slate-400'} text-lg"></i>
                        <div><p class="text-xs font-bold">${r.name}</p><p class="text-[10px] text-slate-400" dir="ltr">${r.path}</p></div>
                    </div>`).join('');
            } catch(e) { resultsBox.innerHTML = '<div class="card p-6 text-center text-red-500">خطا در جستجو.</div>'; }
        }
        document.getElementById('fm-search-input').addEventListener('input', e => fmSearch(e.target.value.trim()));

        function fmDownloadRangeZip() {
            const from = document.getElementById('fm-from').value.trim();
            const to = document.getElementById('fm-to').value.trim();
            let url = 'api/file_manager.php?action=zip_range';
            if (from) url += '&from=' + encodeURIComponent(from);
            if (to) url += '&to=' + encodeURIComponent(to);
            window.open(url, '_blank');
        }

        // همگام‌سازی خودکار هر ۱۰ ثانیه، فقط وقتی همین تب باز است
        function fmStartAutoSync() {
            if (fmSyncInterval) clearInterval(fmSyncInterval);
            fmSyncInterval = setInterval(() => {
                if (!document.getElementById('tab-filemanager').classList.contains('hidden')) fmOpen(fmCurrentPath);
            }, 10000);
        }
        fmStartAutoSync();

        async function loadQueue() {
            const tbody = document.getElementById('queue-body');
            tbody.innerHTML = '<tr><td colspan="7" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin text-xl"></i> در حال بارگذاری صف...</td></tr>';
            try {
                const res = await fetch('api/queue_actions.php?action=list');
                const data = await res.json();
                if (data.ok) { currentQueueData = data.data; renderQueueTable(currentQueueData); }
                else tbody.innerHTML = '<tr><td colspan="7" class="text-center p-8 text-red-500">خطا در دریافت صف.</td></tr>';
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center p-8 text-red-500">خطا در اتصال به سرور.</td></tr>';
            }
        }

        function renderQueueTable(list) {
            const tbody = document.getElementById('queue-body');
            if (list.length === 0) { tbody.innerHTML = '<tr><td colspan="7" class="text-center p-8 text-slate-400">موردی یافت نشد.</td></tr>'; return; }
            tbody.innerHTML = '';
            list.forEach((row) => {
                let statusColor = row.status === 'PENDING' ? 'text-amber-500' : (row.status === 'PROCESSING' ? 'text-blue-500' : (row.status === 'DONE' ? 'text-emerald-500' : 'text-red-500'));
                let btnHtml = (row.status === 'DONE' || row.status === 'FAILED') ? `<button onclick="openOcrReview(${row.id})" class="bg-purple-100 text-purple-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-purple-200 transition-colors hover-target">بررسی و تایید</button>` : `<span class="text-xs text-slate-400">صبر کنید...</span>`;

                let senderLabel = row.sender_name || row.sender_username || '—';
                if (row.chat_type && row.chat_type !== 'private' && row.chat_title) {
                    senderLabel += ` <span class="text-[10px] text-slate-400">(گروه: ${row.chat_title})</span>`;
                }

                let identifiedHtml = '<span class="text-slate-300">—</span>';
                let ext = {};
                try { ext = JSON.parse(row.extracted_data || '{}'); } catch(e) {}
                if (ext.insured_name || ext.national_id) {
                    identifiedHtml = `<div>${ext.insured_name || ''}</div><div class="text-[10px] text-slate-400 font-mono" dir="ltr">${e2p(ext.national_id || '')}</div>`;
                }

                tbody.innerHTML += `
                    <tr class="border-b border-slate-50 hover:bg-purple-50/30 transition">
                        <td class="p-4 font-mono text-slate-400">#${row.id}</td>
                        <td class="p-4 text-slate-700 font-mono text-xs" dir="ltr">${row.file_name}</td>
                        <td class="p-4 text-slate-600 text-xs">${senderLabel}</td>
                        <td class="p-4 text-xs">${identifiedHtml}</td>
                        <td class="p-4 text-slate-500" dir="ltr">${toJalali(row.uploaded_at)}</td>
                        <td class="p-4 font-bold ${statusColor}">${row.status}</td>
                        <td class="p-4">${btnHtml}</td>
                    </tr>
                `;
            });
        }

        document.getElementById('queue-search-input').addEventListener('input', e => {
            const q = e.target.value.trim().toLowerCase();
            if (!q) { renderQueueTable(currentQueueData); return; }
            const filtered = currentQueueData.filter(row => {
                let ext = {};
                try { ext = JSON.parse(row.extracted_data || '{}'); } catch(err) {}
                const haystack = [
                    row.file_name, row.sender_name, row.sender_username, row.chat_title,
                    ext.insured_name, ext.national_id, ext.company_name,
                ].filter(Boolean).join(' ').toLowerCase();
                return haystack.includes(q);
            });
            renderQueueTable(filtered);
        });

        // شناسه‌ی باکس‌هایی که بسته به نوع مدرک نمایش/مخفی می‌شوند
        const REVIEW_DYNAMIC_BOXES = [
            'rbox-personnel', 'rbox-companyname', 'rbox-letterdate',
            'rbox-policy', 'rbox-uniquecode', 'rbox-plate', 'rbox-phone', 'rbox-issuedate', 'rbox-renewal',
            'rbox-cartype', 'rbox-model', 'rbox-color', 'rbox-engine', 'rbox-chassis', 'rbox-vin',
            'rbox-carvalue', 'rbox-premium'
        ];

        function applyReviewFieldVisibility() {
            const docType = document.getElementById('review-doc-type').value;
            REVIEW_DYNAMIC_BOXES.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });

            if (docType === 'معرفی‌نامه') {
                ['rbox-personnel', 'rbox-companyname', 'rbox-letterdate'].forEach(id => { document.getElementById(id).style.display = 'block'; });
            } else if (docType === 'ثالث' || docType === 'بدنه') {
                [
                    'rbox-policy', 'rbox-uniquecode', 'rbox-plate', 'rbox-phone', 'rbox-issuedate', 'rbox-renewal',
                    'rbox-cartype', 'rbox-model', 'rbox-color', 'rbox-engine', 'rbox-chassis', 'rbox-vin', 'rbox-premium'
                ].forEach(id => { document.getElementById(id).style.display = 'block'; });
                if (docType === 'بدنه') document.getElementById('rbox-carvalue').style.display = 'block';
            } else if (docType === 'کارت ماشین') {
                ['rbox-plate', 'rbox-vin', 'rbox-chassis', 'rbox-engine', 'rbox-model', 'rbox-color', 'rbox-cartype'].forEach(id => { document.getElementById(id).style.display = 'block'; });
            }
            // «ناشناخته»: فقط فیلدهای پایه (نام/کد ملی) که همیشه نمایش داده می‌شوند
        }

        function openOcrReview(queueId) {
            const task = currentQueueData.find(q => q.id == queueId);
            if (!task) return;

            document.getElementById('review-queue-id').value = task.id;
            document.getElementById('ocr-file-viewer').src = '/' + task.file_path;

            let senderLabel = task.sender_name || task.sender_username || '—';
            if (task.chat_type && task.chat_type !== 'private' && task.chat_title) {
                senderLabel += ` (گروه: ${task.chat_title})`;
            }
            document.getElementById('review-sender').value = senderLabel;

            let json = {};
            try { json = JSON.parse(task.extracted_data || '{}'); } catch(e){}

            document.getElementById('review-doc-type').value = json.ins_type || 'ناشناخته';
            document.getElementById('review-full-name').value = json.insured_name || '';
            document.getElementById('review-national-code').value = json.national_id || '';
            document.getElementById('review-personnel-code').value = json.personnel_id || '';
            document.getElementById('review-company').value = json.company_name || '';
            document.getElementById('review-letterdate').value = json.letter_date || '';
            document.getElementById('review-policy').value = json.policy_num || '';
            document.getElementById('review-uniquecode').value = json.unique_code || '';
            document.getElementById('review-plate').value = json.plate || '';
            document.getElementById('review-phone').value = json.phone || '';
            document.getElementById('review-issuedate').value = json.issue_date || '';
            document.getElementById('review-renewal').value = json.renewal_status || '';
            document.getElementById('review-cartype').value = [json.car_system, json.car_type].filter(Boolean).join(' ');
            document.getElementById('review-model').value = json.model_year || '';
            document.getElementById('review-color').value = json.car_color || '';
            document.getElementById('review-engine').value = json.engine_num || '';
            document.getElementById('review-chassis').value = json.chassis_num || '';
            document.getElementById('review-vin').value = json.vin || '';
            document.getElementById('review-carvalue').value = json.car_value || '';
            document.getElementById('review-premium').value = json.total_premium || '';

            applyReviewFieldVisibility();
            document.getElementById('ocr-review-modal').classList.add('active');
        }

        async function approveOcrData() {
            const payload = {
                action: 'approve',
                queue_id: document.getElementById('review-queue-id').value,
                ins_type: document.getElementById('review-doc-type').value,
                insured_name: document.getElementById('review-full-name').value,
                national_id: document.getElementById('review-national-code').value,
                personnel_id: document.getElementById('review-personnel-code').value,
                company_name: document.getElementById('review-company').value,
                letter_date: document.getElementById('review-letterdate').value,
                plate: document.getElementById('review-plate').value,
                vin: document.getElementById('review-vin').value,
                policy_num: document.getElementById('review-policy').value,
                unique_code: document.getElementById('review-uniquecode').value,
                phone: document.getElementById('review-phone').value,
                issue_date: document.getElementById('review-issuedate').value,
                renewal_status: document.getElementById('review-renewal').value,
                model_year: document.getElementById('review-model').value,
                car_color: document.getElementById('review-color').value,
                engine_num: document.getElementById('review-engine').value,
                chassis_num: document.getElementById('review-chassis').value,
                car_value: document.getElementById('review-carvalue').value,
                total_premium: document.getElementById('review-premium').value
            };

            try {
                const res = await fetch('api/queue_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.ok) {
                    showToast('اطلاعات تایید و بایگانی شد.', 'success');
                    document.getElementById('ocr-review-modal').classList.remove('active');
                    loadQueue();
                } else {
                    showToast(data.error || 'خطا در تایید', 'error');
                }
            } catch(e) { showToast('خطای شبکه', 'error'); }
        }

        function rejectOcrData() {
            // نمایش مودال تایید برای رد کردن
            document.getElementById('ocr-reject-modal').classList.add('active');
        }

        async function executeRejectOcr() {
            const qid = document.getElementById('review-queue-id').value;
            try {
                const res = await fetch('api/queue_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'reject', queue_id: qid})
                });
                const data = await res.json();
                if (data.ok) {
                    showToast('فایل با موفقیت رد و حذف شد.', 'success');
                    document.getElementById('ocr-reject-modal').classList.remove('active');
                    document.getElementById('ocr-review-modal').classList.remove('active');
                    loadQueue();
                } else {
                    showToast(data.error || 'خطا در حذف فایل', 'error');
                }
            } catch(e) { showToast('خطا در ارتباط با سرور', 'error'); }
        }

        function toggleMobileMenu() {
            document.getElementById('main-nav').classList.toggle('hidden');
        }

        // باز/بسته‌کردن گروه‌های منوی درختی (هر بار فقط یکی باز می‌ماند)
        // ===== اعلان‌ها =====
        // هر اتفاقِ تازه (درخواست شرکتی، مدرک تازه، پیام، پرونده‌ی جدیدِ کارکنان و ...)
        // گوشه‌ی پایینِ راست نشان داده می‌شود و بعد از ۵ ثانیه خودش می‌رود. با کلیک
        // روی اعلان، مستقیم به همان بخش می‌رویم.
        let notifSince = null;
        function pushNotification(ev) {
            const box = document.getElementById('notif-stack');
            if (!box) return;
            const el = document.createElement('div');
            el.className = 'notif-card t-' + (ev.type || 'info');
            el.innerHTML = `<p class="font-bold text-xs mb-0.5 text-slate-700">${ev.title || 'اعلان'}</p>
                            <p class="text-[11px] text-slate-500 leading-relaxed">${ev.body || ''}</p>`;
            el.onclick = () => { if (ev.tab) switchTab(ev.tab); el.remove(); };
            box.appendChild(el);
            requestAnimationFrame(() => el.classList.add('show'));
            setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); }, 5000);
        }

        async function pollNotifications() {
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'notifications_feed', since: notifSince})});
                const data = await res.json();
                if (!data.ok) return;
                (data.events || []).forEach(pushNotification);
                notifSince = data.now;
                // اگر مدرک یا درخواستِ تازه‌ای آمد، شمارنده‌ها و فهرست‌ها هم تازه شوند
                if ((data.events || []).some(e => e.type === 'company_doc' || e.type === 'company_request')) {
                    if (typeof loadCompanyInbox === 'function') loadCompanyInbox();
                    const t = document.getElementById('tab-companies-requests');
                    if (t && !t.classList.contains('hidden')) loadCompanyRequests();
                }
            } catch (e) { /* قطعیِ لحظه‌ای نباید چیزی را خراب کند */ }
        }
        pollNotifications();
        setInterval(pollNotifications, 15000);

        // ===== منوها =====
        // روی دسکتاپ زیرمنو با هاور باز می‌شود (خودِ بازشدن کارِ CSS است، این‌جا فقط
        // یک تاخیرِ کوچکِ بسته‌شدن مدیریت می‌شود تا اگر موس کمی کج از سرمنو به زیرمنو
        // رفت، منو زیر دست بسته نشود). روی موبایل و لمسی همان کلیک کار می‌کند.
        const CLOSE_DELAY = 220;
        let menuCloseTimer = null;

        function closeAllMenuGroups() {
            document.querySelectorAll('.menu-group.open').forEach(g => {
                g.classList.remove('open');
                const t = g.querySelector('.menu-trigger');
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        }

        function openMenuGroup(group) {
            clearTimeout(menuCloseTimer);
            if (group.classList.contains('open')) return;
            closeAllMenuGroups();
            group.classList.add('open');
            const t = group.querySelector('.menu-trigger');
            if (t) t.setAttribute('aria-expanded', 'true');
        }

        function toggleMenuGroup(btn) {
            const group = btn.closest('.menu-group');
            if (group.classList.contains('open')) closeAllMenuGroups();
            else openMenuGroup(group);
        }

        (function initMenuHover() {
            const desktopHover = window.matchMedia('(min-width: 1024px) and (hover: hover) and (pointer: fine)');
            document.querySelectorAll('.menu-group').forEach(group => {
                group.addEventListener('mouseenter', () => { if (desktopHover.matches) openMenuGroup(group); });
                group.addEventListener('mouseleave', () => {
                    if (!desktopHover.matches) return;
                    clearTimeout(menuCloseTimer);
                    menuCloseTimer = setTimeout(closeAllMenuGroups, CLOSE_DELAY);
                });
            });
            // انتخابِ یک گزینه باید منو را ببندد (قبلاً بعد از رفتن به تب، منو باز می‌ماند).
            // blur هم لازم است: وگرنه فوکوس روی همان لینک می‌ماند و قاعده‌ی
            // :focus-within (که برای کاربرِ صفحه‌کلید گذاشته شده) زیرمنو را باز نگه می‌دارد.
            document.querySelectorAll('.menu-panel .menu-link').forEach(link => {
                link.addEventListener('click', () => { closeAllMenuGroups(); link.blur(); });
            });
            // کلیک بیرون از منو، همه را می‌بندد (فقط دسکتاپ)
            document.addEventListener('click', (e) => {
                if (window.innerWidth < 1024) return;
                if (!e.target.closest('.menu-group')) closeAllMenuGroups();
            });
            // Esc هم ببندد
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllMenuGroups(); });
        })();

        // ======================= ماژول شرکت‌ها =======================
        // (COMPANY_API بالای همین اسکریپت تعریف شده - نگاه کنید به توضیحش همان‌جا)
        const CREQ_STATUS_FA = {NEW:'جدید', DOCS_PENDING:'در انتظار مدارک', DOCS_REVIEW:'در حال بررسی', READY_FOR_ISSUE:'آماده‌ی صدور', ISSUED:'صادر شده', CANCELLED:'لغو شده'};
        const CREQ_STATUS_COLOR = {NEW:'bg-blue-100 text-blue-700', DOCS_PENDING:'bg-amber-100 text-amber-700', DOCS_REVIEW:'bg-purple-100 text-purple-700', READY_FOR_ISSUE:'bg-cyan-100 text-cyan-700', ISSUED:'bg-emerald-100 text-emerald-700', CANCELLED:'bg-red-100 text-red-700'};
        let companyRequestsCache = [];

        // معنیِ ستون‌های پلاک در دیتابیس، دقیقاً هم‌شکل با پلاک‌های پرسنلی
        // («{p1}ایران - {p2} {letter} {p4}» در api/_company_helpers.php و همان چیزی که
        //  api/webapp_app.php با نام region/suffix/letter/prefix می‌سازد):
        //    plate_p1 = دو رقمِ کنارِ «ایران» (کد شهر)   plate_p2 = سه رقمِ وسط
        //    plate_letter = حرف                          plate_p4 = دو رقمِ ابتدای پلاک
        // پس ترتیبِ نمایشیِ چپ‌به‌راست می‌شود: p4 - حرف - p2 - ایران - p1
        // (اگر این ترتیب جابه‌جا شود، نامِ فایل‌ها و مقایسه‌ی پلاکِ OCR غلط از آب درمی‌آید)
        // متنِ ساده‌ی پلاک (برای جایی که HTML نمی‌شود گذاشت، مثل option های یک select)
        function fmtPlate(p) {
            if (!p.plate_p1 && !p.plate_p2 && !p.plate_letter && !p.plate_p4) return '—';
            return `${p.plate_p4 || ''} ${p.plate_letter || ''} ${p.plate_p2 || ''} ایران ${p.plate_p1 || ''}`;
        }

        // نمایشِ پلاک از چپ به راست: [۲ رقم] [حرف] [۳ رقم] [ایران] [۲ رقم].
        // از inline-flex و unicode-bidi:isolate استفاده می‌شود چون در متنِ راست‌به‌چپ،
        // مرورگر ترتیبِ عدد و حرف فارسی را جابه‌جا می‌کند و پلاک به‌هم می‌ریزد.
        function fmtPlateHtml(p) {
            if (!p.plate_p1 && !p.plate_p2 && !p.plate_letter && !p.plate_p4) return '—';
            const seg = v => `<span>${e2p(v || '')}</span>`;
            return `<span style="display:inline-flex;direction:ltr;gap:4px;align-items:center;unicode-bidi:isolate">`
                + seg(p.plate_p4) + `<span>${p.plate_letter || ''}</span>` + seg(p.plate_p2)
                + `<span>ایران</span>` + seg(p.plate_p1) + `</span>`;
        }

        async function loadCompanyRequests() {
            const status = document.getElementById('creq-status-filter').value;
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_requests', status})});
            const data = await res.json();
            const tbody = document.getElementById('creq-body');
            if (!data.ok) { tbody.innerHTML = `<tr><td colspan="7" class="text-center p-6 text-red-500">${data.error || 'خطا'}</td></tr>`; return; }
            companyRequestsCache = data.requests;
            if (!data.requests.length) { tbody.innerHTML = '<tr><td colspan="7" class="text-center p-8 text-slate-400">درخواستی یافت نشد.</td></tr>'; return; }
            tbody.innerHTML = data.requests.map(r => `
                <tr class="border-t border-slate-100 hover:bg-slate-50">
                    <td class="p-3">#${r.id}</td>
                    <td class="p-3 font-bold">${r.company_name}</td>
                    <td class="p-3">${r.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد'}
                        ${r.request_kind && r.request_kind !== 'NEW_POLICY'
                            ? `<span class="block text-[10px] font-bold ${r.request_kind === 'ENDORSEMENT' ? 'text-violet-600' : 'text-rose-600'}">${r.request_kind_fa}</span>` : ''}</td>
                    <td class="p-3 text-[11px] whitespace-nowrap">
                        <span class="inline-block bg-cyan-50 text-cyan-700 rounded px-1.5 py-0.5 ml-1">بدنه ${e2pNum(r.body_count)} / ${e2pNum(r.body_issued)}</span>
                        <span class="inline-block bg-blue-50 text-blue-700 rounded px-1.5 py-0.5">ثالث ${e2pNum(r.third_count)} / ${e2pNum(r.third_issued)}</span>
                    </td>
                    <td class="p-3"><span class="status-badge ${CREQ_STATUS_COLOR[r.status] || ''} text-[10px] font-bold px-2 py-1 rounded-full">${CREQ_STATUS_FA[r.status] || r.status}</span></td>
                    <td class="p-3 text-slate-400" dir="ltr">${toJalali(r.created_at)}</td>
                    <td class="p-3 flex items-center gap-2">
                        <button onclick="openCompanyRequestDetail(${r.id})" class="text-blue-600 hover:underline text-xs font-bold">مشاهده</button>
                        <button onclick="openRequestDocsReview(${r.id})" class="relative bg-slate-100 hover:bg-slate-200 text-slate-600 text-[11px] font-bold px-3 py-1.5 rounded-lg">
                            بررسی مدارک
                            ${r.pending_docs_count ? `<span class="absolute -top-1.5 -left-1.5 bg-red-500 text-white text-[9px] w-4 h-4 flex items-center justify-center rounded-full">${r.pending_docs_count}</span>` : ''}
                        </button>
                        <button onclick="openEditRequestModal(${r.id})" class="text-amber-600 hover:underline text-xs font-bold">ویرایش</button>
                        <button onclick="deleteRequestRow(${r.id})" class="text-red-500 hover:underline text-xs font-bold">حذف</button>
                    </td>
                </tr>`).join('');
        }

        // نوعِ درخواست، متنِ راهنما و برچسبِ توضیح را عوض می‌کند
        function onAncrKindChange() {
            const k = document.getElementById('ancr-kind').value;
            const hint = document.getElementById('ancr-kind-hint');
            if (hint) hint.textContent = k === 'ENDORSEMENT'
                ? 'برای هر ردیف، شماره‌ی بیمه‌نامه‌ی فعلی و اینکه چه تغییری می‌خواهند را بنویسید.'
                : (k === 'CANCELLATION'
                    ? 'برای هر ردیف، شماره‌ی بیمه‌نامه و دلیل فسخ را مشخص کنید.'
                    : 'درخواست صدور بیمه‌نامه‌ی جدید برای خودروهای این نامه.');
        }

        // ---------- ثبت دستی درخواست شرکتی توسط خودمان (همین مودال برای ویرایش هم استفاده می‌شود) ----------
        let ancrCompaniesCache = [];
        async function loadAncrCompanies() {
            const sel = document.getElementById('ancr-company');
            sel.innerHTML = '<option value="">در حال بارگذاری...</option>';
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_companies'})});
            const data = await res.json();
            ancrCompaniesCache = data.ok ? data.companies : [];
            sel.innerHTML = '<option value="">-- انتخاب شرکت --</option>' + ancrCompaniesCache.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
        }
        async function openAdminNewRequestModal() {
            document.getElementById('ancr-modal-title').textContent = 'ثبت دستی درخواست شرکتی';
            document.getElementById('ancr-request-id').value = '';
            document.getElementById('ancr-text').value = '';
            document.getElementById('ancr-letter-file').value = '';
            document.getElementById('ancr-kind').value = 'NEW_POLICY';
            onAncrKindChange();
            document.getElementById('ancr-company').disabled = false;
            await loadAncrCompanies();
            ancrUpdateInsurerOptions();
            openModal('admin-new-creq-modal');
        }
        async function openEditRequestModal(requestId) {
            document.getElementById('ancr-modal-title').textContent = `ویرایش درخواست #${requestId}`;
            document.getElementById('ancr-request-id').value = requestId;
            document.getElementById('ancr-letter-file').value = '';
            await loadAncrCompanies();
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'request_detail', request_id: requestId})});
            const data = await res.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            document.getElementById('ancr-company').value = data.request.company_id;
            document.getElementById('ancr-company').disabled = true;
            ancrUpdateInsurerOptions();
            document.getElementById('ancr-insurer').value = data.request.insurer;
            document.getElementById('ancr-kind').value = data.request.request_kind || 'NEW_POLICY';
            onAncrKindChange();
            document.getElementById('ancr-text').value = data.request.request_text || '';
            openModal('admin-new-creq-modal');
        }
        function ancrUpdateInsurerOptions() {
            const companyId = Number(document.getElementById('ancr-company').value);
            const company = ancrCompaniesCache.find(c => String(c.id) === String(companyId));
            const allowed = (company && company.allowed_insurers) || 'BOTH';
            const options = allowed === 'BOTH' ? ['PASARGAD', 'IRAN'] : [allowed];
            document.getElementById('ancr-insurer').innerHTML = options.map(o => `<option value="${o}">${o === 'IRAN' ? 'ایران' : 'پاسارگاد'}</option>`).join('');
        }
        function deleteRequestRow(requestId) {
            showConfirm('حذف درخواست', `درخواست #${requestId} و همه‌ی پلاک‌ها، مدارک و اطلاعات مالیِ آن برای همیشه حذف می‌شود. این کار قابل بازگشت نیست.`, async () => {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'delete_request', request_id: requestId})});
                const data = await res.json();
                if (data.ok) { showToast('درخواست حذف شد.', 'success'); loadCompanyRequests(); }
                else showToast(data.error || 'خطا', 'error');
            });
        }
        async function submitAdminNewRequest() {
            const requestId = document.getElementById('ancr-request-id').value;
            const companyId = document.getElementById('ancr-company').value;
            if (!companyId) { showToast('شرکت را انتخاب کنید.', 'error'); return; }
            const payload = requestId
                ? { action: 'edit_request', request_id: requestId,
                    insurer: document.getElementById('ancr-insurer').value,
                    request_kind: document.getElementById('ancr-kind').value,
                    request_text: document.getElementById('ancr-text').value.trim() }
                : { action: 'admin_create_request', company_id: companyId,
                    insurer: document.getElementById('ancr-insurer').value,
                    request_kind: document.getElementById('ancr-kind').value,
                    request_text: document.getElementById('ancr-text').value.trim() };
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
            const data = await res.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            if (requestId) { data.request_id = requestId; }

            const letterFile = document.getElementById('ancr-letter-file').files[0];
            if (letterFile) {
                const fd = new FormData();
                fd.append('action', 'admin_upload_plate_doc');
                fd.append('request_id', data.request_id);
                fd.append('doc_type', 'letter');
                fd.append('file', letterFile);
                try { await fetch(COMPANY_API, {method: 'POST', body: fd}); } catch (e) { /* درخواست ثبت شد، فقط نامه آپلود نشد */ }
            }

            showToast(requestId ? 'درخواست ویرایش شد.' : 'درخواست ثبت شد.', 'success');
            document.getElementById('admin-new-creq-modal').classList.remove('active');
            loadCompanyRequests();
            openCompanyRequestDetail(data.request_id);
        }

        // ---------- افزودن دستیِ پلاک به یک درخواست ----------
        function openAdminAddPlateModal(requestId) {
            fillCancelReasons('aap', '');
            ['aap-refpolicy', 'aap-endorse'].forEach(i => { const e = document.getElementById(i); if (e) e.value = ''; });
            // فیلدهای بی‌ربط به نوعِ این درخواست پنهان می‌شوند تا فرم شلوغ نشود
            applyKindFieldVisibility('aap');
            document.getElementById('aap-request-id').value = requestId;
            ['aap-p1','aap-p2','aap-letter','aap-p4','aap-expiry'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('aap-insurance-type').value = '';
            document.getElementById('aap-skip-health').checked = false;
            openModal('admin-add-plate-modal');
        }
        async function submitAdminAddPlate() {
            const payload = {
                action: 'admin_add_plate',
                request_id: document.getElementById('aap-request-id').value,
                plate_p1: document.getElementById('aap-p1').value.trim(),
                plate_p2: document.getElementById('aap-p2').value.trim(),
                plate_letter: document.getElementById('aap-letter').value.trim(),
                plate_p4: document.getElementById('aap-p4').value.trim(),
                ref_policy_number: document.getElementById('aap-refpolicy').value.trim(),
                endorsement_request: document.getElementById('aap-endorse').value.trim(),
                cancellation_reason: document.getElementById('aap-cancelreason').value,
                chassis_no: document.getElementById('aap-chassis').value.trim(),
                engine_no: document.getElementById('aap-engine').value.trim(),
                is_new_vehicle: document.getElementById('aap-isnew').checked ? 1 : 0,
                car_value: document.getElementById('aap-carvalue').value.trim(),
                liability_limit: document.getElementById('aap-liability').value.trim(),
                insurance_type: document.getElementById('aap-insurance-type').value,
                expiry_date: document.getElementById('aap-expiry').value,
                skip_health_inspection: document.getElementById('aap-skip-health').checked ? 1 : 0,
            };
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
            const data = await res.json();
            if (data.ok) {
                showToast('پلاک اضافه شد.', 'success');
                document.getElementById('admin-add-plate-modal').classList.remove('active');
                if (currentRequestId) openCompanyRequestDetail(currentRequestId);
            } else showToast(data.error || 'خطا', 'error');
        }

        // برچسبِ فارسیِ همه‌ی انواع مدرک (هم‌نام با company_doc_types در بک‌اند)
        const DOC_TYPE_FA = {
            car_card_front: 'کارت ماشین رو', car_card_back: 'کارت ماشین پشت', ownership_doc: 'سند',
            prev_third_policy: 'بیمه ثالث قبل', prev_body_policy: 'بیمه بدنه قبل',
            health_inspection: 'بازدید سلامت', health_report: 'گزارش بازدید',
            policy_doc: 'بیمه‌نامه', new_car_card: 'کارت ماشین جدید', new_ownership_doc: 'سند جدید',
            other: 'سایر مدارک', car_card_or_title: 'کارت ماشین یا سند', letter: 'نامه‌ی درخواست',
        };
        const ROW_STATUS_COLOR = {
            PENDING: 'bg-amber-100 text-amber-700', READY_FOR_ISSUE: 'bg-cyan-100 text-cyan-700',
            WITH_BOSS: 'bg-indigo-100 text-indigo-700', IN_ISSUANCE: 'bg-purple-100 text-purple-700',
            ISSUED: 'bg-emerald-100 text-emerald-700', CANCELLED: 'bg-red-100 text-red-700',
        };
        const FOLDER_STATUS_FA = {PENDING: ['در انتظار صدور', 'text-slate-400'], TRANSFERRED: ['منتقل شد', 'text-emerald-600'], FAILED: ['ناموفق - نیاز به تلاش دوباره', 'text-red-600']};
        let currentRequestId = null;
        let currentRequestRows = [];
        let currentRequestKind = 'NEW_POLICY';
        let cancellationReasons = [];

        // پرکردنِ کشویِ «دلیل فسخ» در مودال‌های افزودن/ویرایش ردیف
        function fillCancelReasons(prefix, selected) {
            const el = document.getElementById(prefix + '-cancelreason');
            if (!el) return;
            el.innerHTML = '<option value="">- انتخاب کنید -</option>'
                + cancellationReasons.map(r => `<option value="${r}">${r}</option>`).join('');
            if (selected) el.value = selected;
        }

        // یک خانه‌ی چک‌لیست: تیک اگر داریم، ضربدر اگر نداریم (و اگر اجباری بود قرمز).
        // وقتی نداریم، همان‌جا دکمه‌ی آپلود هست؛ وقتی داریم، لینکِ بازکردنِ خودِ فایل.
        function checklistChip(plate, item, canEdit) {
            const ok = item.satisfied;
            const tone = ok ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
                            : (item.required ? 'bg-red-50 border-red-200 text-red-700'
                                             : 'bg-slate-50 border-slate-200 text-slate-400');
            const mark = ok ? '✓' : '✗';
            const star = item.required ? '<span class="text-red-400">*</span>' : '';
            const links = (item.docs || []).map(d => d.is_dir
                ? `<a href="${COMPANY_API}?action=download_folder_zip&doc_id=${d.id}" class="underline hover:no-underline" title="دانلود زیپِ این پوشه">${d.label} (پوشه)</a>`
                : `<a href="../${d.file_path}" target="_blank" class="underline hover:no-underline" title="باز کردن فایل">${d.label}</a>`
            ).join('<span class="text-slate-300 mx-1">|</span>');
            const del = canEdit ? (item.docs || []).map(d =>
                `<button onclick="deleteRowDoc(${d.id})" title="حذف این مدرک" class="text-red-400 hover:text-red-600 mr-1">&times;</button>`).join('') : '';

            let uploader = '';
            if (canEdit && !ok) {
                const types = item.upload_types || [item.key];
                const sel = types.length > 1
                    ? `<select id="rowdoc-type-${plate.id}-${item.key}" class="text-[10px] border rounded px-1 py-0.5 bg-white">
                           ${types.map(t => `<option value="${t}">${DOC_TYPE_FA[t] || t}</option>`).join('')}
                       </select>`
                    : `<input type="hidden" id="rowdoc-type-${plate.id}-${item.key}" value="${types[0]}">`;
                const accept = item.key === 'health_inspection' ? '.zip,.pdf,.jpg,.jpeg,.png,.webp' : '.pdf,.jpg,.jpeg,.png,.webp';
                uploader = `<span class="inline-flex items-center gap-1 mt-1">${sel}
                    <label class="text-[10px] font-bold text-white bg-teal-600 hover:bg-teal-700 px-2 py-0.5 rounded cursor-pointer whitespace-nowrap">
                        بارگذاری<input type="file" class="hidden" accept="${accept}" onchange="uploadRowDoc(${plate.id}, 'rowdoc-type-${plate.id}-${item.key}', this)">
                    </label></span>`;
            }

            return `<div class="border ${tone} rounded-lg px-2 py-1 text-[10px] leading-relaxed" title="${item.hint || ''}">
                <div class="font-bold whitespace-nowrap">${mark} ${item.label}${star}</div>
                ${links ? `<div class="mt-0.5">${links}${del}</div>` : ''}
                ${uploader}
            </div>`;
        }

        // شناسه‌ی ردیف: اگر پلاک دارد پلاک، وگرنه شماره شاسی (لیفتراک و خودروی صفرکیلومتر)
        function rowIdentityHtml(p) {
            if (p.plate_p1 || p.plate_p2 || p.plate_letter || p.plate_p4) {
                return `<span class="plate-display font-bold text-xs">${fmtPlateHtml(p)}</span>`;
            }
            if (p.chassis_no) {
                return `<span class="font-bold text-xs" dir="ltr" title="شماره شاسی">${p.chassis_no}</span>
                        <span class="text-[9px] text-slate-400 block">شماره شاسی</span>`;
            }
            return '<span class="text-slate-400 text-xs">بدون شناسه</span>';
        }


        function rowStageButtons(p, canEdit) {
            if (!canEdit || p.status === 'ISSUED') return '';
            const btn = (stage, label, cls) => `<button onclick="setRowStage(${p.id}, '${stage}')" class="${cls} text-[10px] font-bold px-2 py-0.5 rounded">${label}</button>`;
            const parts = [];
            if (p.status !== 'WITH_BOSS') parts.push(btn('WITH_BOSS', 'ارسال به رئیس', 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'));
            if (p.status !== 'IN_ISSUANCE') parts.push(btn('IN_ISSUANCE', 'در حال صدور', 'bg-purple-50 text-purple-700 hover:bg-purple-100'));
            if (p.status === 'WITH_BOSS' || p.status === 'IN_ISSUANCE') parts.push(btn('PENDING', 'برگشت', 'bg-slate-100 text-slate-600 hover:bg-slate-200'));
            return `<div class="flex flex-wrap gap-1 mt-1">${parts.join('')}</div>`;
        }

        async function openCompanyRequestDetail(id) {
            currentRequestId = id;
            let data;
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'request_detail', request_id: id})});
                data = await res.json();
            } catch (e) { showToast('خطا در ارتباط با سرور. دوباره تلاش کنید.', 'error'); return; }
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            const r = data.request;
            const isAdmin = <?php echo $isLiaison ? 'false' : 'true'; ?>;
            currentRequestRows = data.plates || [];
            currentRequestKind = data.request_kind || 'NEW_POLICY';
            if (data.cancellation_reasons) cancellationReasons = data.cancellation_reasons;
            const c = data.counts || {body: 0, third: 0, body_issued: 0, third_issued: 0};

            // ---- مدارکِ سطحِ درخواست (نامه و هر چیزی که به ردیف خاصی وصل نیست) ----
            const requestLevelDocs = data.documents.filter(d => !d.plate_id);
            const docsHtml = requestLevelDocs.length ? requestLevelDocs.map(d => `
                <div class="flex items-center justify-between text-xs bg-slate-50 rounded-lg p-2 mb-1.5">
                    <a href="../${d.file_path}" target="_blank" class="text-blue-600 hover:underline"><i class="fas fa-file ml-1"></i>${d.orig_name || 'فایل'}</a>
                    <span class="text-[10px] ${d.status === 'ASSIGNED' ? 'text-emerald-600' : 'text-amber-600'}">${d.doc_type_label || '—'} · ${d.status === 'ASSIGNED' ? 'تگ‌گذاری‌شده' : 'در انتظار تگ‌گذاری'}</span>
                </div>`).join('') : '<p class="text-xs text-slate-400">مدرک عمومیِ بدون پلاک ثبت نشده.</p>';

            // ---- جدولِ ریزِ ردیف‌ها: هر ردیف یک بیمه‌نامه‌ی درخواستی ----
            const rowsHtml = currentRequestRows.length ? currentRequestRows.map((p, idx) => {
                const fs = FOLDER_STATUS_FA[p.folder_status] || ['—', 'text-slate-400'];
                const missing = Object.values(p.missing_docs || {});
                const chips = (p.checklist || []).map(it => checklistChip(p, it, isAdmin)).join('');
                return `
                <tr class="border-t border-slate-100 align-top">
                    <td class="p-2 text-[10px] text-slate-400">${e2pNum(idx + 1)}</td>
                    <td class="p-2">
                        ${rowIdentityHtml(p)}
                        ${p.car_name ? `<p class="text-[10px] text-slate-400 mt-0.5">${p.car_name}</p>` : ''}
                        ${p.engine_no ? `<p class="text-[10px] text-slate-400" dir="ltr">موتور: ${p.engine_no}</p>` : ''}
                        ${p.row_note ? `<p class="text-[10px] text-slate-400">${p.row_note}</p>` : ''}
                        ${p.ref_policy_number ? `<p class="text-[10px] text-slate-500" dir="ltr" title="شماره بیمه‌نامه‌ی مرجع">بیمه‌نامه: ${p.ref_policy_number}</p>` : ''}
                        ${p.endorsement_request ? `<p class="text-[10px] text-violet-600 mt-0.5">خواسته: ${p.endorsement_request}</p>` : ''}
                        ${p.cancellation_reason ? `<p class="text-[10px] text-rose-600 mt-0.5">دلیل فسخ: ${p.cancellation_reason}</p>` : ''}
                    </td>
                    <td class="p-2 text-[11px] whitespace-nowrap">${p.insurance_type === 'BODY' ? 'بدنه' : (p.insurance_type === 'THIRDPARTY' ? 'ثالث' : '—')}
                        ${p.insurance_type === 'BODY' && p.car_value ? `<p class="text-[9px] text-slate-500">ارزش: ${money(p.car_value)} ریال</p>` : ''}
                        ${p.insurance_type === 'THIRDPARTY' && p.liability_limit ? `<p class="text-[9px] text-slate-500">تعهد: ${money(p.liability_limit)} ریال</p>` : ''}
                        ${p.insurance_type === 'BODY' && Number(p.skip_health_inspection) ? '<p class="text-[9px] text-slate-400">بدون بازدید</p>' : ''}
                    </td>
                    <td class="p-2 text-[11px] whitespace-nowrap">${p.expiry_date_jalali || (Number(p.is_new_vehicle) ? '<span class="text-slate-400">صفر کیلومتر</span>' : '—')}</td>
                    <td class="p-2 col-checklist"><div class="checklist-grid">${chips || '<span class="text-[10px] text-slate-400">—</span>'}</div></td>
                    <td class="p-2 whitespace-nowrap">
                        <span class="status-badge ${ROW_STATUS_COLOR[p.status] || 'bg-slate-100 text-slate-600'} text-[10px] font-bold px-2 py-1 rounded-full">${p.status_fa || p.status}</span>
                        ${missing.length ? `<p class="text-[9px] text-amber-600 mt-1">کم دارد: ${missing.join('، ')}</p>` : ''}
                        ${p.status === 'ISSUED' ? `<p class="text-[9px] ${fs[1]} mt-1">بایگانی: ${fs[0]}${p.folder_status === 'FAILED' && isAdmin ? ` <button onclick="retryFolderTransfer(${p.id})" class="text-blue-600 underline">تلاش دوباره</button>` : ''}</p>` : ''}
                        ${rowStageButtons(p, isAdmin)}
                    </td>
                    <td class="p-2 whitespace-nowrap text-[11px]">
                        ${p.status === 'ISSUED'
                            ? `<p class="text-[10px] text-slate-500">${p.policy_number || ''}</p>
                               ${p.issued_file_path ? `<a href="../${p.issued_file_path}" target="_blank" class="text-blue-600 hover:underline text-[10px]">فایل بیمه‌نامه</a>` : ''}`
                            : (isAdmin ? `<button onclick="openMarkIssued(${p.id})" class="text-emerald-600 hover:underline font-bold">ثبت صدور</button>` : '<span class="text-[10px] text-slate-400">در انتظار صدور</span>')}
                        ${isAdmin ? `<div class="flex gap-2 mt-1">
                            <button onclick="openRowEdit(${p.id})" class="text-blue-600 hover:underline text-[10px]">ویرایش</button>
                            ${p.status !== 'ISSUED' ? `<button onclick="deleteRow(${p.id})" class="text-red-500 hover:underline text-[10px]">حذف</button>` : ''}
                        </div>` : ''}
                    </td>
                </tr>`;
            }).join('') : '<tr><td colspan="7" class="p-6 text-center text-xs text-slate-400">هنوز ردیفی برای این درخواست ثبت نشده. می‌توانید دستی اضافه کنید یا ردیف‌ها را از نامه وارد کنید.</td></tr>';

            const hasIssuedFiles = currentRequestRows.some(p => p.has_issued_file || p.status === 'ISSUED');

            document.getElementById('creq-detail-body').innerHTML = `
                <div class="flex items-center gap-2 mb-3 flex-wrap">
                    <span class="status-badge ${CREQ_STATUS_COLOR[r.status] || ''} text-[10px] font-bold px-2 py-1 rounded-full">${CREQ_STATUS_FA[r.status] || r.status}</span>
                    ${r.request_kind && r.request_kind !== 'NEW_POLICY'
                        ? `<span class="status-badge text-[10px] font-bold px-2 py-1 rounded-full ${r.request_kind === 'ENDORSEMENT' ? 'bg-violet-100 text-violet-700' : 'bg-rose-100 text-rose-700'}">${r.request_kind_fa}</span>` : ''}
                    <span class="text-xs text-slate-400">${r.company_name} · ${r.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد'}</span>
                </div>
                <p class="text-[10px] text-slate-400 mb-2">ثبت: ${r.created_at_jalali} · آخرین ویرایش: ${r.updated_at_jalali}</p>

                <!-- شمارشِ خواسته‌شده: چند بدنه و چند ثالث درخواست شده و چند تا صادر شده -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
                    <div class="bg-cyan-50 border border-cyan-100 rounded-xl p-2 text-center"><p class="text-[10px] text-cyan-700">بدنه درخواستی</p><p class="font-black text-cyan-800">${e2pNum(c.body)}</p></div>
                    <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-2 text-center"><p class="text-[10px] text-emerald-700">بدنه صادرشده</p><p class="font-black text-emerald-800">${e2pNum(c.body_issued)}</p></div>
                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-2 text-center"><p class="text-[10px] text-blue-700">ثالث درخواستی</p><p class="font-black text-blue-800">${e2pNum(c.third)}</p></div>
                    <div class="bg-teal-50 border border-teal-100 rounded-xl p-2 text-center"><p class="text-[10px] text-teal-700">ثالث صادرشده</p><p class="font-black text-teal-800">${e2pNum(c.third_issued)}</p></div>
                </div>

                <p class="text-sm text-slate-600 mb-3">${r.request_text || '(بدون توضیح متنی)'}</p>

                <div class="flex flex-wrap gap-2 mb-4">
                    <button onclick="showRequestRowsText(${r.id})" class="text-[11px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-xl"><i class="fas fa-list ml-1"></i>فهرست متنی ریز درخواست</button>
                    ${isAdmin ? `<button onclick="openLetterImport(${r.id})" class="text-[11px] font-bold bg-violet-50 hover:bg-violet-100 text-violet-700 px-3 py-1.5 rounded-xl"><i class="fas fa-wand-magic-sparkles ml-1"></i>ورود ردیف‌ها از نامه</button>` : ''}
                    ${isAdmin ? `<button onclick="openAdminAddPlateModal(${r.id})" class="text-[11px] font-bold bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1.5 rounded-xl"><i class="fas fa-plus ml-1"></i>افزودن ردیف دستی</button>` : ''}
                    ${hasIssuedFiles ? `<a href="${COMPANY_API}?action=download_issued_zip&request_id=${r.id}" class="text-[11px] font-bold bg-slate-800 text-white px-3 py-1.5 rounded-xl"><i class="fas fa-file-zipper ml-1"></i>دانلود همه‌ی صادره‌ها</a>` : ''}
                </div>

                <div class="overflow-x-auto border border-slate-100 rounded-xl mb-4">
                    <table class="rows-table text-right">
                        <thead class="bg-slate-50 text-[10px] text-slate-500">
                            <tr><th class="p-2">#</th><th class="p-2">پلاک / شماره شاسی</th><th class="p-2">نوع</th><th class="p-2">انقضا</th><th class="p-2 col-checklist">مدارک (چک‌لیست)</th><th class="p-2">وضعیت / مرحله</th><th class="p-2">صدور</th></tr>
                        </thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>
                </div>

                <h4 class="text-xs font-bold text-slate-500 mb-2">مدارک عمومی درخواست (نامه و موارد بدون پلاک مشخص)</h4>
                ${docsHtml}
                ${isAdmin ? `
                <label class="text-[10px] font-bold text-teal-700 bg-teal-50 border border-teal-100 rounded-lg px-3 py-2 mt-2 flex items-center justify-center gap-1.5 cursor-pointer hover:bg-teal-100">
                    <i class="fas fa-cloud-arrow-up"></i>بارگذاری نامه/مدرک عمومی (بدون پلاک مشخص)
                    <input type="file" class="hidden" onchange="uploadPlateDocDirect(null, this)">
                </label>` : ''}
                <button onclick="toggleCreqFinance(${r.id})" class="w-full mt-4 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2 rounded-xl text-xs"><i class="fas fa-sack-dollar ml-1"></i>وضعیت مالی این درخواست</button>
                <div id="creq-finance-panel" class="hidden mt-3"></div>
            `;
            document.getElementById('creq-detail-modal').classList.add('active');
        }

        // ---- آپلودِ هدفمندِ یک مدرک روی یک ردیف (از دکمه‌ی همان خانه‌ی چک‌لیست) ----
        async function uploadRowDoc(plateId, typeElId, input) {
            if (!input.files[0]) return;
            const typeEl = document.getElementById(typeElId);
            const fd = new FormData();
            fd.append('action', 'upload_row_doc');
            fd.append('plate_id', plateId);
            fd.append('doc_type', typeEl ? typeEl.value : 'other');
            fd.append('doc_file', input.files[0]);
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', body: fd});
                const data = await res.json();
                showToast(data.ok ? 'مدرک ثبت و نام‌گذاری شد.' : (data.error || 'خطا'), data.ok ? 'success' : 'error');
                if (data.ok && currentRequestId) openCompanyRequestDetail(currentRequestId);
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
            input.value = '';
        }

        function deleteRowDoc(docId) {
            showConfirm('حذف مدرک', 'این مدرک از پرونده و از روی سرور حذف می‌شود. مطمئنید؟', async () => {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'delete_row_doc', doc_id: docId})});
                const data = await res.json();
                showToast(data.ok ? 'مدرک حذف شد.' : (data.error || 'خطا'), data.ok ? 'success' : 'error');
                if (data.ok && currentRequestId) openCompanyRequestDetail(currentRequestId);
            });
        }

        async function setRowStage(plateId, stage) {
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'set_row_stage', plate_id: plateId, stage})});
            const data = await res.json();
            showToast(data.ok ? ('مرحله: ' + data.status_fa) : (data.error || 'خطا'), data.ok ? 'success' : 'error');
            if (data.ok && currentRequestId) openCompanyRequestDetail(currentRequestId);
        }

        function deleteRow(plateId) {
            showConfirm('حذف ردیف', 'این ردیف و چک‌لیستش حذف می‌شود (مدارکش هم از این ردیف جدا می‌شوند). مطمئنید؟', async () => {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'delete_row', plate_id: plateId})});
                const data = await res.json();
                showToast(data.ok ? 'ردیف حذف شد.' : (data.error || 'خطا'), data.ok ? 'success' : 'error');
                if (data.ok && currentRequestId) openCompanyRequestDetail(currentRequestId);
            });
        }

        // ---- ویرایش یک ردیف ----
        // بسته به نوعِ درخواست، فقط فیلدهای مربوط به همان نوع نشان داده می‌شوند:
        // «خواسته‌ی الحاقیه» فقط برای الحاقیه، «دلیل فسخ» فقط برای فسخ، و مبالغ و
        // بازدید سلامت فقط برای صدور بیمه‌نامه‌ی جدید.
        function applyKindFieldVisibility(prefix) {
            const k = currentRequestKind;
            const box = id => document.getElementById(id)?.closest('.float-input');
            const show = (id, on) => { const b = box(id); if (b) b.classList.toggle('hidden', !on); };
            show(prefix + '-endorse', k === 'ENDORSEMENT');
            show(prefix + '-cancelreason', k === 'CANCELLATION');
            show(prefix + '-carvalue', k === 'NEW_POLICY');
            show(prefix + '-liability', k === 'NEW_POLICY');
        }

        // «پلاک ندارد»: خانه‌های پلاک غیرفعال می‌شوند و به‌جایش شماره شاسی/موتور گرفته می‌شود
        function toggleNoPlate(prefix) {
            const on = document.getElementById(prefix + '-isnew').checked;
            document.getElementById(prefix + '-chassis-box').classList.toggle('hidden', !on);
            ['p1', 'p2', 'p4', 'letter'].forEach(k => {
                const el = document.getElementById(prefix + '-' + k);
                if (!el) return;
                el.disabled = on;
                el.classList.toggle('opacity-40', on);
                if (on) el.value = '';
            });
        }

        function openRowEdit(plateId) {
            const p = currentRequestRows.find(x => String(x.id) === String(plateId));
            if (!p) { showToast('ردیف پیدا نشد.', 'error'); return; }
            document.getElementById('cre-plate-id').value = p.id;
            document.getElementById('cre-p4').value = p.plate_p4 || '';
            document.getElementById('cre-letter').value = p.plate_letter || '';
            document.getElementById('cre-p2').value = p.plate_p2 || '';
            document.getElementById('cre-p1').value = p.plate_p1 || '';
            document.getElementById('cre-insurance-type').value = p.insurance_type || '';
            document.getElementById('cre-expiry').value = p.expiry_date_jalali || '';
            document.getElementById('cre-has-prev-body').value = p.has_prev_body || '';
            document.getElementById('cre-skip-health').checked = !!Number(p.skip_health_inspection);
            fillCancelReasons('cre', p.cancellation_reason || '');
            document.getElementById('cre-refpolicy').value = p.ref_policy_number || '';
            document.getElementById('cre-endorse').value = p.endorsement_request || '';
            applyKindFieldVisibility('cre');
            document.getElementById('cre-chassis').value = p.chassis_no || '';
            document.getElementById('cre-engine').value = p.engine_no || '';
            document.getElementById('cre-carvalue').value = p.car_value || '';
            document.getElementById('cre-liability').value = p.liability_limit || '';
            document.getElementById('cre-isnew').checked = !!Number(p.is_new_vehicle);
            toggleNoPlate('cre');
            document.getElementById('cre-car-name').value = p.car_name || '';
            document.getElementById('cre-note').value = p.row_note || '';
            document.getElementById('crow-edit-modal').classList.add('active');
        }

        async function submitRowEdit() {
            const payload = {
                action: 'update_row',
                plate_id: document.getElementById('cre-plate-id').value,
                plate_p1: document.getElementById('cre-p1').value.trim(),
                plate_p2: document.getElementById('cre-p2').value.trim(),
                plate_letter: document.getElementById('cre-letter').value.trim(),
                plate_p4: document.getElementById('cre-p4').value.trim(),
                insurance_type: document.getElementById('cre-insurance-type').value,
                expiry_date: document.getElementById('cre-expiry').value.trim(),
                has_prev_body: document.getElementById('cre-has-prev-body').value,
                skip_health_inspection: document.getElementById('cre-skip-health').checked ? 1 : 0,
                ref_policy_number: document.getElementById('cre-refpolicy').value.trim(),
                endorsement_request: document.getElementById('cre-endorse').value.trim(),
                cancellation_reason: document.getElementById('cre-cancelreason').value,
                chassis_no: document.getElementById('cre-chassis').value.trim(),
                engine_no: document.getElementById('cre-engine').value.trim(),
                is_new_vehicle: document.getElementById('cre-isnew').checked ? 1 : 0,
                car_value: document.getElementById('cre-carvalue').value.trim(),
                liability_limit: document.getElementById('cre-liability').value.trim(),
                car_name: document.getElementById('cre-car-name').value.trim(),
                row_note: document.getElementById('cre-note').value.trim(),
            };
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
            const data = await res.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            showToast('ردیف ویرایش شد.', 'success');
            document.getElementById('crow-edit-modal').classList.remove('active');
            if (currentRequestId) openCompanyRequestDetail(currentRequestId);
        }

        // ---- فهرست متنیِ ریزِ درخواست ----
        async function showRequestRowsText(requestId) {
            const box = document.getElementById('crows-text-content');
            box.value = 'در حال آماده‌سازی...';
            document.getElementById('crows-text-modal').classList.add('active');
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'request_rows_text', request_id: requestId})});
            const data = await res.json();
            box.value = data.ok ? data.text : (data.error || 'خطا');
        }

        function copyRowsText() {
            const box = document.getElementById('crows-text-content');
            box.select();
            navigator.clipboard.writeText(box.value)
                .then(() => showToast('فهرست کپی شد.', 'success'))
                .catch(() => showToast('کپی نشد؛ متن انتخاب شده، دستی کپی کنید.', 'error'));
        }

        // ---- ورودِ ردیف‌ها از نامه (خروجی هوش مصنوعی) ----
        function openLetterImport(requestId) {
            document.getElementById('cli-request-id').value = requestId;
            document.getElementById('cli-raw').value = '';
            document.getElementById('cli-file').value = '';
            document.getElementById('cli-replace').checked = false;
            document.getElementById('cli-preview').innerHTML = '';
            document.getElementById('letter-import-modal').classList.add('active');
        }

        async function previewLetterRows() {
            const raw = document.getElementById('cli-raw').value.trim();
            const file = document.getElementById('cli-file').files[0];
            const box = document.getElementById('cli-preview');
            if (!raw && !file) { showToast('متن یا فایل را بدهید.', 'error'); return; }
            box.innerHTML = '<p class="text-xs text-slate-400">در حال بررسی...</p>';
            let data;
            try {
                if (file) {
                    const fd = new FormData();
                    fd.append('action', 'preview_letter_rows');
                    fd.append('import_file', file);
                    data = await (await fetch(COMPANY_API, {method: 'POST', body: fd})).json();
                } else {
                    data = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'preview_letter_rows', raw})})).json();
                }
            } catch (e) { box.innerHTML = '<p class="text-xs text-red-500">خطا در ارتباط با سرور.</p>'; return; }
            if (!data.ok) { box.innerHTML = `<p class="text-xs text-red-500">${data.error || 'خطا'}</p>`; return; }

            const rows = data.rows || [];
            box.innerHTML = `
                ${(data.errors || []).length ? `<div class="bg-amber-50 border border-amber-200 rounded-lg p-2 mb-2 text-[10px] text-amber-700">${data.errors.map(x => '• ' + x).join('<br>')}</div>` : ''}
                <p class="text-xs font-bold text-slate-600 mb-1">${e2pNum(rows.length)} ردیف شناسایی شد:</p>
                <div class="max-h-52 overflow-y-auto border border-slate-100 rounded-lg">
                    <table class="w-full text-right text-[10px]">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="p-1.5">پلاک</th><th class="p-1.5">نوع</th><th class="p-1.5">انقضا</th><th class="p-1.5">خودرو</th><th class="p-1.5">بدنه قبل</th><th class="p-1.5">بازدید</th></tr></thead>
                        <tbody>${rows.map(x => `<tr class="border-t border-slate-100">
                            <td class="p-1.5">${rowIdentityHtml(x)}</td>
                            <td class="p-1.5">${x.insurance_type_fa}</td>
                            <td class="p-1.5">${x.expiry_date_jalali || '—'}</td>
                            <td class="p-1.5">${x.car_name || '—'}</td>
                            <td class="p-1.5">${x.has_prev_body === 'YES' ? 'دارد' : (x.has_prev_body === 'NO' ? 'ندارد' : '—')}</td>
                            <td class="p-1.5">${Number(x.skip_health_inspection) ? 'لازم نیست' : 'لازم'}</td>
                        </tr>`).join('')}</tbody>
                    </table>
                </div>`;
            document.getElementById('cli-commit-btn').classList.toggle('hidden', rows.length === 0);
        }

        async function commitLetterRows() {
            const requestId = document.getElementById('cli-request-id').value;
            const raw = document.getElementById('cli-raw').value.trim();
            const file = document.getElementById('cli-file').files[0];
            const replace = document.getElementById('cli-replace').checked;
            if (!raw && !file) { showToast('متن یا فایل را بدهید.', 'error'); return; }
            // فایل اکسل باینری است و نمی‌شود به متن تبدیلش کرد؛ خودِ فایل فرستاده می‌شود
            let res;
            if (file) {
                const fd = new FormData();
                fd.append('action', 'import_letter_rows');
                fd.append('request_id', requestId);
                fd.append('replace_existing', replace ? '1' : '');
                fd.append('import_file', file);
                res = await fetch(COMPANY_API, {method: 'POST', body: fd});
            } else {
                res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'import_letter_rows', request_id: requestId, raw, replace_existing: replace})});
            }
            const data = await res.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            showToast(`${e2pNum(data.created)} ردیف ساخته شد${data.duplicates ? ` (${e2pNum(data.duplicates)} ردیف تکراری رد شد)` : ''}.`, 'success');
            document.getElementById('letter-import-modal').classList.remove('active');
            openCompanyRequestDetail(requestId);
        }

        // ===================== لیست صدور =====================
        let issueQueueCache = [];
        let iqTimer = null;
        function debouncedIssueQueue() { clearTimeout(iqTimer); iqTimer = setTimeout(loadIssueQueue, 350); }

        // کپیِ یک مقدار با یک کلیک - کارفرما خواسته بتواند اطلاعات را سریع بردارد
        function copyValue(text, label) {
            navigator.clipboard.writeText(String(text))
                .then(() => showToast((label || 'مقدار') + ' کپی شد.', 'success'))
                .catch(() => showToast('کپی نشد.', 'error'));
        }
        // یک «خانه‌ی اطلاعات» با دکمه‌ی کپی
        function infoCell(label, value, opts = {}) {
            if (value === null || value === undefined || value === '') return '';
            const safe = String(value).replace(/'/g, "\\'");
            return `<div class="bg-slate-50 border border-slate-100 rounded-lg px-2.5 py-1.5">
                <p class="text-[9px] text-slate-400">${label}</p>
                <p class="text-[11px] font-bold text-slate-700 break-words" ${opts.ltr ? 'dir="ltr"' : ''}>${value}
                    <button onclick="copyValue('${safe}', '${label}')" title="کپی ${label}" class="text-slate-300 hover:text-blue-500 mr-1">
                        <i class="far fa-copy text-[10px]"></i></button>
                </p></div>`;
        }

        // کشویِ «همه‌ی شرکت‌ها» یک‌بار پر می‌شود
        let iqCompaniesLoaded = false;
        async function loadIssueQueueCompanies() {
            if (iqCompaniesLoaded) return;
            try {
                const data = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'list_companies'})})).json();
                if (!data.ok) return;
                const sel = document.getElementById('iq-company');
                sel.innerHTML = '<option value="">همه‌ی شرکت‌ها</option>'
                    + (data.companies || []).map(c => `<option value="${c.id}">${c.name}</option>`).join('');
                iqCompaniesLoaded = true;
            } catch (e) { /* بی‌خیال؛ فیلترِ شرکت اختیاری است */ }
        }

        async function loadIssueQueue() {
            const tbody = document.getElementById('iq-body');
            tbody.innerHTML = '<tr><td colspan="9" class="p-8 text-center text-slate-400">در حال بارگذاری...</td></tr>';
            const payload = {
                action: 'issue_queue',
                q: document.getElementById('iq-q').value.trim(),
                company_id: document.getElementById('iq-company').value,
                readiness: document.getElementById('iq-readiness').value,
                missing_doc: document.getElementById('iq-missing').value,
                insurance_type: document.getElementById('iq-type').value,
                request_kind: document.getElementById('iq-kind').value,
                stage: document.getElementById('iq-stage').value,
                expiry_from: document.getElementById('iq-exp-from').value.trim(),
                expiry_to: document.getElementById('iq-exp-to').value.trim(),
            };
            let data;
            try {
                data = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)})).json();
            } catch (e) { tbody.innerHTML = '<tr><td colspan="9" class="p-8 text-center text-red-500">خطا در ارتباط با سرور.</td></tr>'; return; }
            if (!data.ok) { tbody.innerHTML = `<tr><td colspan="9" class="p-8 text-center text-red-500">${data.error || 'خطا'}</td></tr>`; return; }

            issueQueueCache = data.rows || [];
            document.getElementById('iq-c-total').textContent = e2pNum(data.counts.total);
            document.getElementById('iq-c-ready').textContent = e2pNum(data.counts.ready);
            document.getElementById('iq-c-waiting').textContent = e2pNum(data.counts.waiting);

            if (!issueQueueCache.length) { tbody.innerHTML = '<tr><td colspan="9" class="p-8 text-center text-slate-400">ردیفی با این فیلترها پیدا نشد.</td></tr>'; return; }

            tbody.innerHTML = issueQueueCache.map((r, i) => {
                const missing = Object.values(r.missing_docs || {});
                // رنگِ هشدارِ نزدیکیِ انقضا
                const d = r.days_to_expiry;
                const expCls = d === null ? 'text-slate-400' : (d < 0 ? 'text-red-600 font-bold' : (d <= 7 ? 'text-amber-600 font-bold' : 'text-slate-600'));
                const expNote = d === null ? '' : (d < 0 ? `(${e2pNum(Math.abs(d))} روز گذشته)` : `(${e2pNum(d)} روز مانده)`);
                return `
                <tr class="border-t border-slate-100 hover:bg-teal-50/40 cursor-pointer" onclick="openIssueQueueDetail(${r.id})">
                    <td class="p-3 text-slate-400">${e2pNum(i + 1)}</td>
                    <td class="p-3 font-bold">${r.company_name}</td>
                    <td class="p-3">${rowIdentityHtml(r)}${r.car_name ? `<p class="text-[10px] text-slate-400">${r.car_name}</p>` : ''}</td>
                    <td class="p-3">${r.insurance_type_fa}
                        ${r.request_kind !== 'NEW_POLICY' ? `<p class="text-[10px] ${r.request_kind === 'ENDORSEMENT' ? 'text-violet-600' : 'text-rose-600'}">${r.request_kind_fa}</p>` : ''}</td>
                    <td class="p-3 text-slate-500">${r.request_date_jalali}</td>
                    <td class="p-3 ${expCls}">${r.expiry_date_jalali || (Number(r.is_new_vehicle) ? 'صفر کیلومتر' : '—')}
                        ${expNote ? `<span class="block text-[9px]">${expNote}</span>` : ''}</td>
                    <td class="p-3">${r.is_ready
                        ? '<span class="bg-emerald-100 text-emerald-700 text-[10px] font-bold px-2 py-1 rounded-full">✓ مدارک کامل</span>'
                        : `<span class="bg-amber-100 text-amber-700 text-[10px] font-bold px-2 py-1 rounded-full">کم دارد</span>
                           <p class="text-[9px] text-amber-600 mt-1">${missing.join('، ')}</p>`}</td>
                    <td class="p-3"><span class="status-badge ${ROW_STATUS_COLOR[r.status] || 'bg-slate-100 text-slate-600'} text-[10px] font-bold px-2 py-1 rounded-full">${r.status_fa}</span></td>
                    <td class="p-3"><span class="text-blue-600 font-bold">مشاهده و صدور</span></td>
                </tr>`;
            }).join('');
        }

        function openIssueQueueDetail(rowId) {
            const r = issueQueueCache.find(x => String(x.id) === String(rowId));
            if (!r) { showToast('ردیف پیدا نشد.', 'error'); return; }
            // برای اینکه دکمه‌های آپلود و صدور همان توابع موجود را صدا بزنند
            currentRequestId = r.request_id;
            currentRequestKind = r.request_kind || 'NEW_POLICY';
            currentRequestRows = [r];

            const docsHtml = (r.all_docs || []).length
                ? r.all_docs.map(d => `
                    <div class="flex items-center justify-between gap-2 bg-white border border-slate-100 rounded-lg p-2">
                        <span class="text-[11px] font-bold text-slate-600">${d.label}</span>
                        <span class="flex items-center gap-2">
                            ${d.is_dir
                                ? `<a href="${COMPANY_API}?action=download_folder_zip&doc_id=${d.id}" class="text-blue-600 hover:underline text-[11px]">دانلود پوشه</a>`
                                : `<a href="../${d.file_path}" target="_blank" class="text-blue-600 hover:underline text-[11px]">مشاهده</a>`}
                            <button onclick="rejectDocument(${d.id})" class="text-red-500 hover:underline text-[11px]">رد</button>
                        </span>
                    </div>`).join('')
                : '<p class="text-[11px] text-slate-400">هنوز مدرکی برای این ردیف ثبت نشده.</p>';

            const chips = (r.checklist || []).map(it => checklistChip(r, it, true)).join('');

            document.getElementById('iq-detail-body').innerHTML = `
                <div class="grid md:grid-cols-2 gap-4 mb-4">
                    <div class="border border-slate-200 rounded-xl p-3">
                        <h4 class="text-xs font-bold text-slate-500 mb-2"><i class="fas fa-building ml-1 text-slate-300"></i>شرکت درخواست‌دهنده</h4>
                        <div class="grid grid-cols-2 gap-1.5">
                            ${infoCell('نام شرکت', r.company_name)}
                            ${infoCell('کد اقتصادی', r.economic_code, {ltr: true})}
                            ${infoCell('تلفن', r.company_phone, {ltr: true})}
                            ${infoCell('بیمه‌گر', r.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد')}
                        </div>
                        ${r.company_address ? `<p class="text-[10px] text-slate-400 mt-2">${r.company_address}</p>` : ''}
                    </div>
                    <div class="border border-slate-200 rounded-xl p-3">
                        <h4 class="text-xs font-bold text-slate-500 mb-2"><i class="fas fa-file-lines ml-1 text-slate-300"></i>درخواست</h4>
                        <div class="grid grid-cols-2 gap-1.5">
                            ${infoCell('شماره درخواست', '#' + e2pNum(r.request_id))}
                            ${infoCell('نوع درخواست', r.request_kind_fa)}
                            ${infoCell('تاریخ ثبت', r.request_date_jalali)}
                            ${infoCell('مرحله', r.status_fa)}
                        </div>
                        ${r.request_text ? `<p class="text-[10px] text-slate-500 mt-2">${r.request_text}</p>` : ''}
                        ${r.letter_file_path
                            ? `<a href="../${r.letter_file_path}" target="_blank" class="inline-block mt-2 text-[11px] font-bold text-white bg-slate-700 hover:bg-slate-800 px-3 py-1.5 rounded-lg"><i class="fas fa-file-pdf ml-1"></i>نامه‌ی این درخواست</a>`
                            : '<p class="text-[10px] text-amber-600 mt-2">نامه‌ای برای این درخواست ثبت نشده.</p>'}
                    </div>
                </div>

                <div class="border border-slate-200 rounded-xl p-3 mb-4">
                    <h4 class="text-xs font-bold text-slate-500 mb-2"><i class="fas fa-car ml-1 text-slate-300"></i>اطلاعات این ردیف</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-1.5">
                        ${infoCell('پلاک / شماره شاسی', r.plate_display, {ltr: true})}
                        ${infoCell('نوع بیمه', r.insurance_type_fa)}
                        ${infoCell('تاریخ انقضا', r.expiry_date_jalali || (Number(r.is_new_vehicle) ? 'صفر کیلومتر' : ''))}
                        ${infoCell('خودرو', r.car_name)}
                        ${infoCell('شماره موتور', r.engine_no, {ltr: true})}
                        ${infoCell('ارزش خودرو', r.car_value ? money(r.car_value) + ' ریال' : '')}
                        ${infoCell('تعهد مالی', r.liability_limit ? money(r.liability_limit) + ' ریال' : '')}
                        ${infoCell('شماره بیمه‌نامه‌ی مرجع', r.ref_policy_number, {ltr: true})}
                    </div>
                    ${r.endorsement_request ? `<p class="text-[11px] text-violet-700 bg-violet-50 border border-violet-100 rounded-lg p-2 mt-2">خواسته‌ی الحاقیه: ${r.endorsement_request}</p>` : ''}
                    ${r.cancellation_reason ? `<p class="text-[11px] text-rose-700 bg-rose-50 border border-rose-100 rounded-lg p-2 mt-2">دلیل فسخ: ${r.cancellation_reason}</p>` : ''}
                </div>

                <div class="grid md:grid-cols-2 gap-4 mb-4">
                    <div class="border border-slate-200 rounded-xl p-3">
                        <h4 class="text-xs font-bold text-slate-500 mb-2"><i class="fas fa-list-check ml-1 text-slate-300"></i>چک‌لیست مدارک</h4>
                        <div class="checklist-grid">${chips}</div>
                    </div>
                    <div class="border border-slate-200 rounded-xl p-3">
                        <h4 class="text-xs font-bold text-slate-500 mb-2"><i class="fas fa-paperclip ml-1 text-slate-300"></i>مدارک ثبت‌شده (تک‌به‌تک)</h4>
                        <div class="space-y-1.5">${docsHtml}</div>
                    </div>
                </div>

                <div class="border-2 ${r.is_ready ? 'border-emerald-200 bg-emerald-50/40' : 'border-amber-200 bg-amber-50/40'} rounded-xl p-3">
                    <h4 class="text-xs font-bold ${r.is_ready ? 'text-emerald-700' : 'text-amber-700'} mb-2">
                        <i class="fas fa-stamp ml-1"></i>${r.is_ready ? 'مدارک کامل است - آماده‌ی صدور' : 'هنوز مدارک کامل نیست'}</h4>
                    ${!r.is_ready ? `<p class="text-[11px] text-amber-700 mb-2">کم دارد: ${Object.values(r.missing_docs).join('، ')}</p>` : ''}
                    <div class="flex flex-wrap gap-2">
                        <button onclick="openMarkIssued(${r.id})" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-xl text-xs"><i class="fas fa-check ml-1"></i>ثبت صدور</button>
                        <button onclick="openRowEdit(${r.id})" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2 rounded-xl text-xs">ویرایش اطلاعات ردیف</button>
                        <button onclick="openCompanyRequestDetail(${r.request_id})" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2 rounded-xl text-xs">دیدن کلِ درخواست</button>
                    </div>
                </div>`;
            document.getElementById('iq-detail-modal').classList.add('active');
        }

        // ===================== صادره‌ها =====================
        let issuedListCache = [];
        let ilTimer = null;
        function debouncedIssuedList() { clearTimeout(ilTimer); ilTimer = setTimeout(loadIssuedList, 350); }

        function issuedFilters() {
            return {
                q: document.getElementById('il-q').value.trim(),
                source: document.getElementById('il-source').value,
                insurance_type: document.getElementById('il-type').value,
                issued_from: document.getElementById('il-from').value.trim(),
                issued_to: document.getElementById('il-to').value.trim(),
            };
        }

        async function loadIssuedList() {
            const tbody = document.getElementById('il-body');
            tbody.innerHTML = '<tr><td colspan="15" class="p-8 text-center text-slate-400">در حال بارگذاری...</td></tr>';
            let data;
            try {
                data = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'issued_list', ...issuedFilters()})})).json();
            } catch (e) { tbody.innerHTML = '<tr><td colspan="15" class="p-8 text-center text-red-500">خطا در ارتباط با سرور.</td></tr>'; return; }
            if (!data.ok) { tbody.innerHTML = `<tr><td colspan="15" class="p-8 text-center text-red-500">${data.error || 'خطا'}</td></tr>`; return; }

            issuedListCache = data.rows || [];
            const c = data.counts;
            document.getElementById('il-c-total').textContent = e2pNum(c.total);
            document.getElementById('il-c-company').textContent = e2pNum(c.company);
            document.getElementById('il-c-personnel').textContent = e2pNum(c.personnel);
            document.getElementById('il-c-types').textContent = e2pNum(c.body) + ' / ' + e2pNum(c.third);
            document.getElementById('il-c-premium').textContent = money(c.premium);

            if (!issuedListCache.length) { tbody.innerHTML = '<tr><td colspan="15" class="p-8 text-center text-slate-400">با این فیلترها بیمه‌نامه‌ای پیدا نشد.</td></tr>'; return; }

            tbody.innerHTML = issuedListCache.map((r, i) => `
                <tr class="border-t border-slate-100 hover:bg-emerald-50/40 cursor-pointer" onclick="openIssuedDetail(${i})">
                    <td class="p-3 text-slate-400">${e2pNum(i + 1)}</td>
                    <td class="p-3"><span class="text-[10px] font-bold px-2 py-1 rounded-full ${r.source === 'COMPANY' ? 'bg-indigo-100 text-indigo-700' : 'bg-blue-100 text-blue-700'}">${r.source_fa}</span></td>
                    <td class="p-3">${r.request_kind_fa}</td>
                    <td class="p-3 font-bold">${r.insured_name || '—'}</td>
                    <td class="p-3">${r.company_name || '—'}</td>
                    <td class="p-3" dir="ltr">${r.plate || r.chassis_no || '—'}</td>
                    <td class="p-3">${r.insurance_type_fa}</td>
                    <td class="p-3 font-mono" dir="ltr">${r.policy_number || '—'}</td>
                    <td class="p-3">${r.car_name || '—'}</td>
                    <td class="p-3">${r.total_premium ? money(r.total_premium) : '—'}</td>
                    <td class="p-3 text-slate-500">${r.request_date_jalali || '—'}</td>
                    <td class="p-3 text-slate-500">${r.expiry_date_jalali || '—'}</td>
                    <td class="p-3 text-slate-500">${r.issued_at_jalali || '—'}</td>
                    <td class="p-3"><span class="bg-emerald-100 text-emerald-700 text-[10px] font-bold px-2 py-1 rounded-full">${r.status_fa}</span></td>
                    <td class="p-3">${r.issued_file_path ? `<a href="../${r.issued_file_path}" target="_blank" onclick="event.stopPropagation()" class="text-blue-600 hover:underline">باز کردن</a>` : '<span class="text-slate-300">—</span>'}</td>
                </tr>`).join('');
        }

        function openIssuedDetail(idx) {
            const r = issuedListCache[idx];
            if (!r) return;
            document.getElementById('il-detail-body').innerHTML = `
                <div class="flex items-center gap-2 mb-3 flex-wrap">
                    <span class="text-[10px] font-bold px-2 py-1 rounded-full ${r.source === 'COMPANY' ? 'bg-indigo-100 text-indigo-700' : 'bg-blue-100 text-blue-700'}">${r.source_fa}</span>
                    <span class="text-[10px] font-bold px-2 py-1 rounded-full bg-slate-100 text-slate-700">${r.request_kind_fa}</span>
                    <span class="text-[10px] font-bold px-2 py-1 rounded-full bg-emerald-100 text-emerald-700">${r.status_fa}</span>
                </div>

                <h4 class="text-xs font-bold text-slate-500 mb-2">بیمه‌گذار و شرکت</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-1.5 mb-4">
                    ${infoCell('بیمه‌گذار', r.insured_name)}
                    ${infoCell('شرکت / کارفرما', r.company_name)}
                    ${infoCell('کد ملی / اقتصادی', r.national_id, {ltr: true})}
                    ${infoCell('تلفن', r.phone, {ltr: true})}
                </div>

                <h4 class="text-xs font-bold text-slate-500 mb-2">بیمه‌نامه</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-1.5 mb-4">
                    ${infoCell('شماره بیمه‌نامه', r.policy_number, {ltr: true})}
                    ${infoCell('نوع بیمه', r.insurance_type_fa)}
                    ${infoCell('بیمه‌گر', r.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد')}
                    ${infoCell('حق بیمه (ریال)', r.total_premium ? money(r.total_premium) : '')}
                    ${infoCell('شماره بیمه‌نامه‌ی مرجع', r.ref_policy_number, {ltr: true})}
                    ${infoCell('کد یکتای مرکزی', r.central_unique_code, {ltr: true})}
                    ${infoCell('شناسه پرونده', r.unique_code, {ltr: true})}
                    ${infoCell('وضعیت بایگانی', r.folder_status === 'TRANSFERRED' ? 'منتقل شد' : (r.folder_status === 'FAILED' ? 'ناموفق' : ''))}
                </div>

                <h4 class="text-xs font-bold text-slate-500 mb-2">خودرو</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-1.5 mb-4">
                    ${infoCell('پلاک', r.plate, {ltr: true})}
                    ${infoCell('شماره شاسی', r.chassis_no, {ltr: true})}
                    ${infoCell('شماره موتور', r.engine_no, {ltr: true})}
                    ${infoCell('VIN', r.vin, {ltr: true})}
                    ${infoCell('خودرو', r.car_name)}
                    ${infoCell('سیستم', r.car_system)}
                    ${infoCell('تیپ', r.car_type)}
                    ${infoCell('مدل', r.car_model_year, {ltr: true})}
                    ${infoCell('رنگ', r.car_color)}
                    ${infoCell('کاربری', r.car_usage)}
                    ${infoCell('ارزش خودرو (ریال)', r.car_value ? money(r.car_value) : '')}
                    ${infoCell('تعهد مالی (ریال)', r.liability_limit ? money(r.liability_limit) : '')}
                </div>

                <h4 class="text-xs font-bold text-slate-500 mb-2">تاریخ‌ها و درخواست</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-1.5 mb-4">
                    ${infoCell('تاریخ درخواست', r.request_date_jalali)}
                    ${infoCell('تاریخ انقضا', r.expiry_date_jalali)}
                    ${infoCell('تاریخ صدور', r.issued_at_jalali)}
                    ${infoCell('تاریخ صدور روی بیمه‌نامه', r.policy_issue_date, {ltr: true})}
                </div>
                ${r.endorsement_request ? `<p class="text-[11px] text-violet-700 bg-violet-50 border border-violet-100 rounded-lg p-2 mb-2">خواسته‌ی الحاقیه: ${r.endorsement_request}</p>` : ''}
                ${r.cancellation_reason ? `<p class="text-[11px] text-rose-700 bg-rose-50 border border-rose-100 rounded-lg p-2 mb-2">دلیل فسخ: ${r.cancellation_reason}</p>` : ''}
                ${r.request_text ? `<p class="text-[11px] text-slate-500 bg-slate-50 rounded-lg p-2 mb-2">${r.request_text}</p>` : ''}

                <div class="flex flex-wrap gap-2 mt-3">
                    ${r.issued_file_path ? `<a href="../${r.issued_file_path}" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2 rounded-xl text-xs"><i class="fas fa-file-pdf ml-1"></i>مشاهده‌ی فایل بیمه‌نامه</a>` : ''}
                    ${r.source === 'COMPANY' ? `<button onclick="openCompanyRequestDetail(${r.request_id}); document.getElementById('il-detail-modal').classList.remove('active');" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2 rounded-xl text-xs">دیدن درخواست و مدارکش</button>` : ''}
                    ${r.source === 'COMPANY' ? `<a href="${COMPANY_API}?action=download_issued_zip&request_id=${r.request_id}" class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2 rounded-xl text-xs"><i class="fas fa-file-zipper ml-1"></i>دانلود صادره‌های این درخواست</a>` : ''}
                </div>`;
            document.getElementById('il-detail-modal').classList.add('active');
        }

        // خروجی اکسل، دقیقاً با همان فیلترهایی که الان روی جدول اعمال شده
        function exportIssuedExcel() {
            const f = issuedFilters();
            const qs = new URLSearchParams({action: 'issued_list', export: '1', ...f}).toString();
            window.location.href = COMPANY_API + '?' + qs;
            showToast('فایل اکسل در حال آماده‌سازی است...', 'success');
        }

        const CINST_STATUS_FA = {0: 'وصول‌نشده', 1: 'تسویه با پاسارگاد'};

        function toggleCreqFinance(requestId) {
            const panel = document.getElementById('creq-finance-panel');
            if (!panel.classList.contains('hidden')) { panel.classList.add('hidden'); return; }
            panel.classList.remove('hidden');
            loadCreqFinance(requestId);
        }

        async function loadCreqFinance(requestId) {
            const panel = document.getElementById('creq-finance-panel');
            panel.dataset.requestId = requestId;
            panel.innerHTML = '<p class="text-xs text-slate-400 p-2">در حال بارگذاری...</p>';
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_company_installments', request_id: requestId})});
            const data = await res.json();
            if (!data.ok) { panel.innerHTML = `<p class="text-red-500 text-xs p-2">${data.error || 'خطا'}</p>`; return; }
            if (!data.installments.length) { panel.innerHTML = '<p class="text-slate-400 text-xs p-2">هنوز هیچ پلاکی صادر (و در نتیجه قسط‌بندی) نشده.</p>'; return; }
            const rows = data.installments.map(i => `
                <tr class="border-t border-slate-100 text-[11px]">
                    <td class="p-2"><input type="checkbox" class="cinst-cb" value="${i.id}" data-amount="${i.amount - i.settled_amount}" ${i.settled_to_pasargad || i.collected < i.amount ? 'disabled' : ''}></td>
                    <td class="p-2 plate-display">${i.plate_display || '—'}</td>
                    <td class="p-2">#${i.inst_number}</td>
                    <td class="p-2 font-mono">${Number(i.amount).toLocaleString('fa-IR')}</td>
                    <td class="p-2 font-mono ${i.collected >= i.amount ? 'text-emerald-600' : 'text-amber-600'}">${Number(i.collected).toLocaleString('fa-IR')}</td>
                    <td class="p-2">${i.settled_to_pasargad ? '<span class="text-emerald-600 font-bold">تسویه شد</span>' : '<span class="text-slate-400">—</span>'}</td>
                </tr>`).join('');
            panel.innerHTML = `
                <div class="flex gap-2 mb-2">
                    <button onclick="openCompanyPaymentModal(${requestId})" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-2 rounded-lg">ثبت دریافت از شرکت</button>
                    <button onclick="openCompanyPasargadModal()" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold py-2 rounded-lg">ثبت تسویه با پاسارگاد</button>
                </div>
                <table class="w-full text-xs border rounded-lg overflow-hidden">
                    <thead class="bg-slate-50 text-slate-500"><tr><th class="p-2"></th><th class="p-2">پلاک</th><th class="p-2">قسط</th><th class="p-2">مبلغ</th><th class="p-2">دریافتی</th><th class="p-2">وضعیت</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;
        }

        function openCompanyPaymentModal(requestId) {
            document.getElementById('cpay-request-id').value = requestId;
            ['cpay-amount','cpay-ref','cpay-note'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('cpay-jalali').value = todayJalali();
            document.getElementById('cpay-files').value = '';
            openModal('cpay-modal');
        }

        async function submitCompanyPayment() {
            const fd = new FormData();
            fd.append('action', 'create_company_payment');
            fd.append('request_id', document.getElementById('cpay-request-id').value);
            fd.append('amount', document.getElementById('cpay-amount').value.replace(/\D/g, ''));
            fd.append('method', document.getElementById('cpay-method').value);
            fd.append('paid_jalali', document.getElementById('cpay-jalali').value.trim());
            fd.append('reference_no', document.getElementById('cpay-ref').value.trim());
            fd.append('note', document.getElementById('cpay-note').value.trim());
            const files = document.getElementById('cpay-files').files;
            for (let i = 0; i < files.length; i++) fd.append('company_receipts[]', files[i]);
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', body: fd});
                const data = await res.json();
                if (data.ok) { showToast('دریافتی ثبت شد.', 'success'); closeModal('cpay-modal'); loadCreqFinance(document.getElementById('cpay-request-id').value); }
                else showToast(data.error || 'خطا', 'error');
            } catch (e) { showToast('خطا در ارتباط با سرور', 'error'); }
        }

        function openCompanyPasargadModal() {
            const ids = Array.from(document.querySelectorAll('.cinst-cb:checked')).map(cb => Number(cb.value));
            if (!ids.length) { showToast('حداقل یک قسطِ کاملاً وصول‌شده را انتخاب کنید.', 'error'); return; }
            window.cpsgPendingIds = ids;
            const total = Array.from(document.querySelectorAll('.cinst-cb:checked')).reduce((s, cb) => s + Number(cb.dataset.amount), 0);
            document.getElementById('cpsg-summary').textContent = `${ids.length} قسط به مبلغ ${total.toLocaleString('fa-IR')} ریال`;
            ['cpsg-ref','cpsg-note'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('cpsg-jalali').value = todayJalali();
            document.getElementById('cpsg-files').value = '';
            openModal('cpsg-modal');
        }

        async function submitCompanyPasargad() {
            const fd = new FormData();
            fd.append('action', 'settle_company_pasargad');
            fd.append('installment_ids', JSON.stringify(window.cpsgPendingIds || []));
            fd.append('reference_no', document.getElementById('cpsg-ref').value.trim());
            fd.append('paid_jalali', document.getElementById('cpsg-jalali').value.trim());
            fd.append('note', document.getElementById('cpsg-note').value.trim());
            const files = document.getElementById('cpsg-files').files;
            for (let i = 0; i < files.length; i++) fd.append('company_pasargad_receipts[]', files[i]);
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', body: fd});
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
                closeModal('cpsg-modal');
                showToast(`${data.settled} قسط تسویه شد.`, 'success');
                if (currentRequestId) { openCompanyRequestDetail(currentRequestId); }
            } catch (e) { showToast('خطا در ارتباط با سرور', 'error'); }
        }

        async function retryFolderTransfer(plateId) {
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'retry_folder_transfer', plate_id: plateId})});
            const data = await res.json();
            showToast(data.ok ? 'با موفقیت منتقل شد.' : (data.error || 'باز هم ناموفق بود.'), data.ok ? 'success' : 'error');
            if (currentRequestId) openCompanyRequestDetail(currentRequestId);
        }

        async function uploadPlateDocDirect(plateId, input) {
            if (!input.files[0]) return;
            const typeSel = plateId ? document.getElementById(`pdoc-type-${plateId}`) : null;
            const fd = new FormData();
            fd.append('action', 'admin_upload_plate_doc');
            fd.append('request_id', currentRequestId);
            if (plateId) fd.append('plate_id', plateId);
            fd.append('doc_type', typeSel ? typeSel.value : 'letter');
            fd.append('file', input.files[0]);
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', body: fd});
                const data = await res.json();
                showToast(data.ok ? 'مدرک بارگذاری و ثبت شد.' : (data.error || 'خطا'), data.ok ? 'success' : 'error');
                if (data.ok && currentRequestId) openCompanyRequestDetail(currentRequestId);
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }

        let companyInboxCache = [];
        let currentDocsReviewRequestId = null;

        async function openRequestDocsReview(requestId) {
            currentDocsReviewRequestId = requestId;
            document.getElementById('creq-docs-req-id').textContent = '#' + requestId;
            document.getElementById('creq-docs-list').innerHTML = '<p class="text-center text-xs text-slate-400 py-6">در حال بارگذاری...</p>';
            document.getElementById('creq-docs-modal').classList.add('active');
            await loadRequestDocsReview();
        }

        async function loadRequestDocsReview() {
            const requestId = currentDocsReviewRequestId;
            const box = document.getElementById('creq-docs-list');
            let data;
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_request_inbox', request_id: requestId})});
                data = await res.json();
            } catch (e) { box.innerHTML = '<p class="text-center text-red-500 text-xs p-6">خطا در ارتباط با سرور. دوباره تلاش کنید.</p>'; return; }
            if (!data.ok) { box.innerHTML = `<p class="text-center text-red-500 text-xs p-6">${data.error || 'خطا'}</p>`; return; }
            companyInboxCache = data.documents;
            if (!data.documents.length) { box.innerHTML = '<p class="text-center text-xs text-slate-400 py-10">مدرک تخصیص‌نیافته‌ای برای این درخواست نیست.</p>'; return; }
            box.innerHTML = data.documents.map(d => `
                <div class="card p-4 flex items-center justify-between gap-3">
                    <div>
                        <a href="../${d.file_path}" target="_blank" class="font-bold text-sm text-blue-600 hover:underline"><i class="fas fa-file ml-1"></i>${d.orig_name || 'فایل'}</a>
                        <p class="text-[11px] text-slate-400 mt-1">${d.file_kind === 'LETTER' ? 'نامه' : 'مدرک'} · ${d.uploaded_at_jalali}
                        ${d.plate_p1 || d.doc_type ? ' · <span class="text-blue-500">پیشنهاد ثبت‌کننده: ' + fmtPlate(d) + ' ' + (DOC_TYPE_FA[d.doc_type] || d.doc_type || '') + '</span>' : ''}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button onclick="openInboxTagModal(${d.id})" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-xl whitespace-nowrap">ثبت اطلاعات (تگ‌گذاری)</button>
                        <button onclick="rejectDocument(${d.id})" title="رد کردن و حذف، تا شرکت دوباره بفرستد" class="bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold px-3 py-2 rounded-xl whitespace-nowrap">رد کردن</button>
                    </div>
                </div>`).join('');
        }

        async function loadCompanyInbox() {
            const box = document.getElementById('cinbox-list');

            let data;
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_inbox'})});
                data = await res.json();
            } catch (e) { box.innerHTML = '<p class="text-center text-red-500 text-xs p-6">خطا در ارتباط با سرور. دوباره تلاش کنید.</p>'; return; }
            if (!data.ok) { box.innerHTML = `<p class="text-center text-red-500 text-xs p-6">${data.error || 'خطا'}</p>`; return; }
            companyInboxCache = data.documents;
            if (!data.documents.length) { box.innerHTML = '<p class="text-center text-xs text-slate-400 py-10">صندوق ورودی خالی است.</p>'; return; }
            box.innerHTML = data.documents.map(d => `
                <div class="card p-4 flex items-center justify-between gap-3">
                    <div>
                        <a href="../${d.file_path}" target="_blank" class="font-bold text-sm text-blue-600 hover:underline"><i class="fas fa-file ml-1"></i>${d.orig_name || 'فایل'}</a>
                        <p class="text-[11px] text-slate-400 mt-1">${d.company_name} · ${d.file_kind === 'LETTER' ? 'نامه' : 'مدرک'} · ${d.uploaded_at_jalali}
                        ${d.plate_p1 || d.doc_type ? ' · <span class="text-blue-500">پیشنهاد ثبت‌کننده: ' + fmtPlate(d) + ' ' + (DOC_TYPE_FA[d.doc_type] || d.doc_type || '') + '</span>' : ''}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button onclick="openInboxTagModal(${d.id})" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-xl whitespace-nowrap">تگ‌گذاری</button>
                        <button onclick="rejectDocument(${d.id})" title="رد کردن و حذف، تا شرکت دوباره بفرستد" class="bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold px-3 py-2 rounded-xl whitespace-nowrap">رد کردن</button>
                    </div>
                </div>`).join('');
        }

        // پیش‌نمایشِ خودِ فایلِ مدرک داخل مودال تگ‌گذاری (PDF در iframe، عکس به‌صورت
        // تصویر، و پوشه/زیپ فقط با لینک دانلود چون قابل نمایش درون‌خطی نیست)
        function renderCitPreview(doc) {
            const box = document.getElementById('cit-preview');
            const url = '../' + doc.file_path;
            const ext = (doc.file_path || '').split('.').pop().toLowerCase();
            if (['jpg','jpeg','png','webp','gif'].includes(ext)) {
                box.innerHTML = `<img src="${url}" alt="پیش‌نمایش مدرک" class="w-full max-h-72 object-contain bg-white">
                    <a href="${url}" target="_blank" class="block text-center text-[11px] text-blue-600 hover:underline py-1.5">باز کردن در تب جدید</a>`;
            } else if (ext === 'pdf') {
                box.innerHTML = `<iframe src="${url}" title="پیش‌نمایش مدرک" class="w-full h-72 bg-white"></iframe>
                    <a href="${url}" target="_blank" class="block text-center text-[11px] text-blue-600 hover:underline py-1.5">باز کردن در تب جدید</a>`;
            } else {
                box.innerHTML = `<div class="p-3 text-center text-[11px] text-slate-500">
                    این فایل قابل نمایش درون‌خطی نیست (${ext || 'نامشخص'}).
                    <a href="${url}" target="_blank" class="text-blue-600 hover:underline mr-1">دانلود/باز کردن</a></div>`;
            }
        }

        async function loadPlatesForCitRequest(requestId, preselectPlateId) {
            const plateSel = document.getElementById('cit-existing-plate');
            const chips = document.getElementById('cit-plate-chips');
            plateSel.innerHTML = '<option value="">-- پلاک جدید --</option>';
            chips.innerHTML = '';
            if (!requestId) return;
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'request_detail', request_id: requestId})});
            const data = await res.json();
            if (!data.ok) return;
            data.plates.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = fmtPlate(p) + (p.insurance_type ? ' (' + (p.insurance_type === 'BODY' ? 'بدنه' : 'ثالث') + ')' : '');
                if (preselectPlateId && String(p.id) === String(preselectPlateId)) opt.selected = true;
                plateSel.appendChild(opt);
            });
            // همان فهرست به‌صورت دکمه‌های قابل کلیک، تا انتخابِ پلاک سریع‌تر باشد
            chips.innerHTML = data.plates.length
                ? '<span class="text-[10px] text-slate-400 w-full">خودروهای این درخواست (برای انتخاب کلیک کنید):</span>'
                  + data.plates.map(p => `<button type="button" onclick="pickCitPlate(${p.id})"
                        class="border border-slate-200 hover:border-blue-400 hover:bg-blue-50 rounded-lg px-2 py-1 text-[10px]">
                        <span class="plate-display">${fmtPlateHtml(p)}</span>
                        <span class="text-slate-400 mr-1">${p.insurance_type === 'BODY' ? 'بدنه' : 'ثالث'}</span></button>`).join('')
                : '<span class="text-[10px] text-amber-600">این درخواست هنوز ردیفی ندارد؛ پلاک را دستی وارد کنید (ردیف جدید ساخته می‌شود).</span>';
            onCitPlateSelectChange();
        }

        function pickCitPlate(plateId) {
            document.getElementById('cit-existing-plate').value = String(plateId);
            onCitPlateSelectChange();
        }

        function onCitRequestChange() { loadPlatesForCitRequest(document.getElementById('cit-request').value, null); }

        function onCitPlateSelectChange() {
            const usingExisting = !!document.getElementById('cit-existing-plate').value;
            ['cit-p1','cit-p2','cit-letter','cit-p4'].forEach(id => { document.getElementById(id).disabled = usingExisting; if (usingExisting) document.getElementById(id).value = ''; });
        }

        async function openInboxTagModal(docId) {
            const doc = companyInboxCache.find(d => String(d.id) === String(docId));
            if (!doc) return;
            document.getElementById('cit-doc-id').value = docId;
            const sel = document.getElementById('cit-request');
            sel.innerHTML = '<option>در حال بارگذاری...</option>';
            let data;
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_requests'})});
                data = await res.json();
            } catch (e) { sel.innerHTML = '<option value="">خطا در ارتباط با سرور - دوباره تلاش کنید</option>'; return; }
            const forCompany = (data.requests || []).filter(r => r.company_id == doc.company_id);
            sel.innerHTML = forCompany.map(r => `<option value="${r.id}" ${r.id === doc.request_id ? 'selected' : ''}>درخواست #${r.id} (${CREQ_STATUS_FA[r.status] || r.status})</option>`).join('') || '<option value="">درخواستی برای این شرکت یافت نشد</option>';

            renderCitPreview(doc);
            const KNOWN_TYPES = ['ownership_doc','car_card_front','car_card_back','prev_third_policy','prev_body_policy',
                                'health_inspection','health_report','policy_doc','new_car_card','new_ownership_doc','other','car_card_or_title'];
            document.getElementById('cit-doc-type').value = KNOWN_TYPES.includes(doc.doc_type) && doc.doc_type !== 'car_card_or_title'
                ? doc.doc_type : (doc.doc_type ? 'other' : 'ownership_doc');
            document.getElementById('cit-doc-type-other').value = document.getElementById('cit-doc-type').value === 'other' ? (doc.doc_type || '') : '';
            document.getElementById('cit-doc-type-other').classList.toggle('hidden', document.getElementById('cit-doc-type').value !== 'other');
            document.getElementById('cit-p1').value = doc.plate_p1 || '';
            document.getElementById('cit-p2').value = doc.plate_p2 || '';
            document.getElementById('cit-letter').value = doc.plate_letter || '';
            document.getElementById('cit-p4').value = doc.plate_p4 || '';
            document.getElementById('cit-insurance-type').value = '';
            document.getElementById('cit-expiry').value = '';
            document.getElementById('cit-skip-health').checked = false;
            await loadPlatesForCitRequest(sel.value, null);
            document.getElementById('cinbox-tag-modal').classList.add('active');
        }

        // رد کردنِ یک مدرکِ ارسالیِ شرکت: فایل حذف می‌شود و دلیلش به‌صورت پیام در چتِ
        // همان شرکت می‌نشیند تا بداند چرا و دوباره درست بفرستد.
        function rejectDocument(docId) {
            showPrompt('دلیل رد شدن مدرک (برای شرکت پیام می‌رود - می‌توانید خالی بگذارید)', '', async (reason) => {
                showConfirm('رد کردن مدرک', 'این مدرک حذف می‌شود و شرکت باید دوباره بفرستد. مطمئنید؟', async () => {
                    const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({action: 'reject_document', doc_id: docId, reason: reason || ''})});
                    const data = await res.json();
                    showToast(data.ok ? 'مدرک رد شد و به شرکت اطلاع داده شد.' : (data.error || 'خطا'), data.ok ? 'success' : 'error');
                    if (!data.ok) return;
                    document.getElementById('cinbox-tag-modal').classList.remove('active');
                    if (document.getElementById('creq-docs-modal').classList.contains('active')) loadRequestDocsReview();
                    else loadCompanyInbox();
                    if (currentRequestId && document.getElementById('creq-detail-modal').classList.contains('active')) {
                        openCompanyRequestDetail(currentRequestId);
                    }
                });
            });
        }

        async function submitAssignDocument() {
            const docTypeSel = document.getElementById('cit-doc-type').value;
            const payload = {
                action: 'assign_document',
                doc_id: document.getElementById('cit-doc-id').value,
                request_id: document.getElementById('cit-request').value,
                plate_id: document.getElementById('cit-existing-plate').value || undefined,
                doc_type: docTypeSel === 'other' ? document.getElementById('cit-doc-type-other').value.trim() : docTypeSel,
                plate_p1: document.getElementById('cit-p1').value.trim(),
                plate_p2: document.getElementById('cit-p2').value.trim(),
                plate_letter: document.getElementById('cit-letter').value.trim(),
                plate_p4: document.getElementById('cit-p4').value.trim(),
                insurance_type: document.getElementById('cit-insurance-type').value,
                expiry_date: document.getElementById('cit-expiry').value,
                skip_health_inspection: document.getElementById('cit-skip-health').checked ? 1 : 0,
            };
            if (!payload.request_id) { showToast('یک درخواست انتخاب کنید.', 'error'); return; }
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
                const data = await res.json();
                if (data.ok) {
                    showToast('اطلاعات ثبت شد.', 'success');
                    document.getElementById('cinbox-tag-modal').classList.remove('active');
                    if (document.getElementById('creq-docs-modal').classList.contains('active')) loadRequestDocsReview();
                    else loadCompanyInbox();
                    loadCompanyRequests();
                }
                else showToast(data.error || 'خطا در تخصیص مدرک', 'error');
            } catch (e) { showToast('خطا در ارتباط با سرور', 'error'); }
        }

        function openMarkIssued(plateId) {
            document.getElementById('cis-plate-id').value = plateId;
            ['cis-policy-number','cis-vin','cis-premium'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('cis-file').value = '';
            const note = document.getElementById('cis-ocr-note');
            note.className = 'hidden'; note.innerHTML = '';
            const p = currentRequestRows.find(x => String(x.id) === String(plateId));
            document.getElementById('cis-plate-label').innerHTML = p
                ? `ردیف: <span class="plate-display">${fmtPlateHtml(p)}</span> · ${p.insurance_type === 'BODY' ? 'بدنه' : 'ثالث'}` : '';
            document.getElementById('cissue-modal').classList.add('active');
        }

        // ---- اعتبارسنجیِ فایل بیمه‌نامه، همان روالِ بخش پرسنلی: اگر پلاک یا نوع بیمه‌ی
        //      فایل با این ردیف نخواند، سرور اجازه نمی‌دهد و خطای صریح می‌دهد ----
        async function validateCompanyPolicy() {
            const fileInput = document.getElementById('cis-file');
            const note = document.getElementById('cis-ocr-note');
            if (!fileInput.files[0]) { showToast('اول فایل بیمه‌نامه را انتخاب کنید.', 'error'); return; }
            note.className = 'text-[11px] rounded-lg p-2 mb-2 bg-slate-50 text-slate-500';
            note.innerHTML = 'در حال شناسایی فایل...';
            const fd = new FormData();
            fd.append('action', 'ocr_preview_company_policy');
            fd.append('plate_id', document.getElementById('cis-plate-id').value);
            fd.append('policy_file', fileInput.files[0]);
            let data;
            try { data = await (await fetch(COMPANY_API, {method: 'POST', body: fd})).json(); }
            catch (e) { note.className = 'text-[11px] rounded-lg p-2 mb-2 bg-red-50 text-red-600'; note.innerHTML = 'خطا در ارتباط با سرور.'; return; }

            if (!data.ok) {
                note.className = 'text-[11px] rounded-lg p-2 mb-2 bg-red-50 text-red-600 font-bold';
                note.innerHTML = data.error || 'اعتبارسنجی ناموفق بود.';
                return;
            }
            const o = data.ocr || {};
            if (o.policy_number) document.getElementById('cis-policy-number').value = o.policy_number;
            if (o.vin) document.getElementById('cis-vin').value = o.vin;
            if (o.total_premium) document.getElementById('cis-premium').value = String(o.total_premium).replace(/\D/g, '');
            note.className = 'text-[11px] rounded-lg p-2 mb-2 ' + (data.ocr_used ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700');
            note.innerHTML = data.ocr_used
                ? `✓ فایل با این ردیف مطابقت دارد (پلاک ${o.plate || data.expected_plate || ''} · ${o.ins_type || ''}). فیلدهای شناسایی‌شده پر شدند؛ بررسی کنید و ثبت کنید.`
                : `فایل ذخیره شد ولی شناسایی خودکار انجام نشد${data.ocr_debug ? ' (' + data.ocr_debug + ')' : ''}. فیلدها را دستی پر کنید.`;
        }

        async function submitMarkIssued() {
            const fd = new FormData();
            fd.append('action', 'mark_issued');
            fd.append('plate_id', document.getElementById('cis-plate-id').value);
            fd.append('policy_number', document.getElementById('cis-policy-number').value.trim());
            fd.append('vin', document.getElementById('cis-vin').value.trim());
            fd.append('total_premium', document.getElementById('cis-premium').value);
            // اگر مرحله‌ی اعتبارسنجی انجام شده باشد، فایل از قبل روی سرور است؛ وگرنه
            // همین فایل مستقیم فرستاده می‌شود (مثلاً وقتی بیمه‌نامه عکس است و OCR ندارد)
            const fileInput = document.getElementById('cis-file');
            if (fileInput.files[0]) fd.append('issued_file', fileInput.files[0]);
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', body: fd});
                const data = await res.json();
                if (data.ok) {
                    showToast(`صدور ثبت شد. پوشه: ${data.issued_folder || ''}${data.installments ? ` · ${e2pNum(data.installments)} قسط ساخته شد` : ''}`, 'success');
                    document.getElementById('cissue-modal').classList.remove('active');
                    loadCompanyRequests();
                    if (currentRequestId) openCompanyRequestDetail(currentRequestId);
                } else showToast(data.error || 'خطا در ثبت صدور', 'error');
            } catch (e) { showToast('خطا در ارتباط با سرور', 'error'); }
        }

        async function loadCompanyFinance() {
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'finance_summary'})});
            const data = await res.json();
            const tbody = document.getElementById('cfin-body');
            if (!data.ok) { tbody.innerHTML = `<tr><td colspan="4" class="text-center p-6 text-red-500">${data.error || 'خطا'}</td></tr>`; return; }
            if (!data.companies.length) { tbody.innerHTML = '<tr><td colspan="4" class="text-center p-8 text-slate-400">شرکتی ثبت نشده.</td></tr>'; return; }
            tbody.innerHTML = data.companies.map(c => `
                <tr class="border-t border-slate-100">
                    <td class="p-3 font-bold">${c.name}</td>
                    <td class="p-3 font-mono">${c.total_invoiced.toLocaleString('fa-IR')}</td>
                    <td class="p-3 font-mono">${c.total_paid.toLocaleString('fa-IR')}</td>
                    <td class="p-3 font-mono ${c.outstanding > 0 ? 'text-red-600' : 'text-emerald-600'}">${c.outstanding.toLocaleString('fa-IR')}</td>
                </tr>`).join('');

            const companySel = document.getElementById('cfin-chart-company');
            if (companySel.options.length <= 1) {
                companySel.innerHTML = '<option value="">همه‌ی شرکت‌ها</option>' + data.companies.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
            }
            loadFinanceChart();
        }

        async function loadFinanceChart() {
            const companyId = document.getElementById('cfin-chart-company').value;
            const range = document.getElementById('cfin-chart-range').value;
            const box = document.getElementById('cfin-chart-box');
            box.innerHTML = '<p class="text-center text-slate-400 text-xs py-16"><i class="fas fa-spinner fa-spin ml-1"></i>در حال بارگذاری...</p>';
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'finance_chart', company_id: companyId, range})});
                const data = await res.json();
                if (!data.ok) { box.innerHTML = `<p class="text-center text-red-500 text-xs py-16">${data.error || 'خطا'}</p>`; return; }
                document.getElementById('cfin-stat-count').textContent = e2p(data.issued_count) + ' فقره';
                document.getElementById('cfin-stat-premium').textContent = money(data.total_premium) + ' ریال';
                document.getElementById('cfin-stat-debt').textContent = money(data.total_debt) + ' ریال';
                renderSalesChart(data.monthly);
            } catch (e) { box.innerHTML = '<p class="text-center text-red-500 text-xs py-16">خطا در ارتباط با سرور.</p>'; }
        }

        // نمودار میله‌ای ساده و سبک (بدون کتابخانه‌ی خارجی، تا وابسته به CDN نباشد) -
        // یک سری (رنگ آبیِ برند سایت)، میله‌های نازک با لبه‌ی گرد بالا، خط پایه، و
        // برچسبِ مقدار روی هاور
        function renderSalesChart(monthly) {
            const box = document.getElementById('cfin-chart-box');
            if (!monthly || !monthly.length) { box.innerHTML = '<p class="text-center text-slate-400 text-xs py-16">داده‌ای برای این بازه/شرکت یافت نشد.</p>'; return; }

            const W = Math.max(560, monthly.length * 80), H = 220;
            const padL = 8, padR = 8, padTop = 24, padBottom = 34;
            const plotW = W - padL - padR, plotH = H - padTop - padBottom;
            const maxVal = Math.max(...monthly.map(m => m.amount), 1);
            const barGap = 14;
            const barW = Math.min(46, (plotW / monthly.length) - barGap);

            const bars = monthly.map((m, i) => {
                const slot = plotW / monthly.length;
                const x = padL + i * slot + (slot - barW) / 2;
                const h = Math.max(2, (m.amount / maxVal) * plotH);
                const y = padTop + (plotH - h);
                return `
                    <g class="sales-bar-g" data-amount="${m.amount}" data-label="${m.label}">
                        <rect x="${x}" y="${y}" width="${barW}" height="${h}" rx="4" ry="4" fill="#3b82f6" class="sales-bar" style="transition:opacity .15s"/>
                        <text x="${x + barW / 2}" y="${H - 12}" text-anchor="middle" font-size="10" fill="#94a3b8" font-weight="bold">${m.label.replace(/\d+/g, d => d.replace(/\d/g, dd => '۰۱۲۳۴۵۶۷۸۹'[dd]))}</text>
                    </g>`;
            }).join('');

            box.innerHTML = `
                <div class="relative">
                    <svg viewBox="0 0 ${W} ${H}" class="w-full" style="max-height:240px" id="sales-chart-svg">
                        <line x1="${padL}" y1="${padTop + plotH}" x2="${W - padR}" y2="${padTop + plotH}" stroke="#e2e8f0" stroke-width="1"/>
                        ${bars}
                    </svg>
                    <div id="sales-chart-tooltip" class="absolute hidden bg-slate-800 text-white text-[11px] font-bold px-2.5 py-1.5 rounded-lg pointer-events-none whitespace-nowrap" style="transform:translate(-50%,-110%)"></div>
                </div>`;

            const tooltip = document.getElementById('sales-chart-tooltip');
            document.querySelectorAll('.sales-bar-g').forEach(g => {
                g.addEventListener('mouseenter', () => { g.querySelector('.sales-bar').style.opacity = '0.8'; });
                g.addEventListener('mouseleave', () => { g.querySelector('.sales-bar').style.opacity = '1'; tooltip.classList.add('hidden'); });
                g.addEventListener('mousemove', (ev) => {
                    const svg = document.getElementById('sales-chart-svg');
                    const rect = svg.getBoundingClientRect();
                    const rectBox = g.querySelector('.sales-bar').getBoundingClientRect();
                    tooltip.style.left = (rectBox.left - rect.left + rectBox.width / 2) + 'px';
                    tooltip.style.top = (rectBox.top - rect.top) + 'px';
                    tooltip.textContent = g.dataset.label + ': ' + money(g.dataset.amount) + ' ریال';
                    tooltip.classList.remove('hidden');
                });
            });
        }

        let companiesCache = [];
        let portalUsersCache = [];
        const PAY_FA = {INSTALLMENT: 'قسطی', CASH_NET30: 'نقدی - ۳۰ روزه', CASH_IMMEDIATE: 'نقدی - فوری'};
        const CM_INSURER_FA = {BOTH: 'پاسارگاد و ایران', PASARGAD: 'فقط پاسارگاد', IRAN: 'فقط ایران'};

        async function loadCompanyManage() {
            const [compRes, groupRes, usersRes] = await Promise.all([
                fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_companies'})}),
                fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_bot_groups'})}),
                fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_portal_users'})}),
            ]);
            const data = await compRes.json();
            const groupData = await groupRes.json();
            const usersData = await usersRes.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            companiesCache = data.companies;

            const tbody = document.getElementById('cm-companies-body');
            tbody.innerHTML = data.companies.length ? data.companies.map(c => `
                <tr class="border-t border-slate-100">
                    <td class="p-3 font-bold">${c.name}</td>
                    <td class="p-3 text-slate-500">${c.parent_name || '—'}</td>
                    <td class="p-3 text-slate-500">${PAY_FA[c.payment_terms] || '—'}</td>
                    <td class="p-3 text-slate-500">${c.installment_count ? c.installment_count + ' قسط' : '—'}</td>
                    <td class="p-3 text-slate-500">${CM_INSURER_FA[c.allowed_insurers] || 'پاسارگاد و ایران'}</td>
                    <td class="p-3 text-slate-400 font-mono">${c.created_at_jalali || '—'}</td>
                    <td class="p-3 flex gap-2">
                        <button onclick="editCompany(${c.id})" class="text-blue-600 hover:underline text-xs font-bold">ویرایش</button>
                        <button onclick="deleteCompanyRow(${c.id}, '${(c.name||'').replace(/'/g,"")}')" class="text-red-500 hover:underline text-xs font-bold">حذف</button>
                    </td>
                </tr>
            `).join('') : '<tr><td colspan="7" class="text-center p-6 text-slate-400">شرکتی ثبت نشده.</td></tr>';

            const parentSel = document.getElementById('cm-parent-company');
            parentSel.innerHTML = '<option value="">شرکت مستقل / خودش مادر است</option>' + data.companies.map(c => `<option value="${c.id}">${c.name}</option>`).join('');

            if (groupData.ok) {
                const groupSel = document.getElementById('cm-group-select');
                groupSel.innerHTML = '<option value="">بدون گروه (بعداً قابل تنظیم است)</option>' +
                    groupData.groups.map(g => `<option value="${g.chat_id}">${g.title || g.chat_id}</option>`).join('') +
                    '<option value="__manual__">-- وارد کردن دستی شناسه‌ی گروه --</option>';
            }

            const puBox = document.getElementById('cm-pu-companies');
            puBox.innerHTML = data.companies.map(c => `
                <label class="flex items-center gap-2 py-1"><input type="checkbox" class="cm-pu-company-cb" value="${c.id}"> ${c.name}</label>
            `).join('') || '<p class="text-slate-400">ابتدا یک شرکت ثبت کنید.</p>';

            if (usersData.ok) {
                portalUsersCache = usersData.users;
                const utbody = document.getElementById('cm-portal-users-body');
                utbody.innerHTML = usersData.users.length ? usersData.users.map(u => `
                    <tr class="border-t border-slate-100">
                        <td class="p-3 font-mono">${u.username}</td><td class="p-3">${u.full_name}</td>
                        <td class="p-3 text-slate-500">${u.company_names || '—'}</td><td class="p-3 font-mono text-slate-400">${u.mobile_number || '—'}</td>
                        <td class="p-3 flex gap-2">
                            <button onclick="editPortalUser(${u.id})" class="text-blue-600 hover:underline text-xs font-bold">ویرایش</button>
                            <button onclick="deletePortalUserRow(${u.id}, '${(u.full_name||'').replace(/'/g,"")}')" class="text-red-500 hover:underline text-xs font-bold">حذف</button>
                        </td>
                    </tr>`).join('') : '<tr><td colspan="5" class="text-center p-6 text-slate-400">کاربری ثبت نشده.</td></tr>';
            }
        }

        function resetCompanyForm() {
            document.getElementById('cm-form-title').textContent = 'افزودن شرکت جدید';
            document.getElementById('cm-company-id').value = '';
            ['cm-company-name','cm-economic-code','cm-phone','cm-address','cm-group-manual'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('cm-parent-company').value = '';
            document.getElementById('cm-group-select').value = '';
            document.getElementById('cm-payment-terms').value = '';
            document.getElementById('cm-allowed-insurers').value = 'BOTH';
            document.getElementById('cm-inst-count').value = '';
            document.getElementById('cm-offset-months').value = '0';
            document.getElementById('cm-offset-days').value = '0';
        }

        function editCompany(id) {
            const c = companiesCache.find(x => String(x.id) === String(id));
            if (!c) return;
            document.getElementById('cm-form-title').textContent = `ویرایش «${c.name}»`;
            document.getElementById('cm-company-id').value = c.id;
            document.getElementById('cm-company-name').value = c.name;
            document.getElementById('cm-economic-code').value = c.economic_code || '';
            document.getElementById('cm-phone').value = c.phone || '';
            document.getElementById('cm-address').value = c.address || '';
            document.getElementById('cm-parent-company').value = c.parent_id || '';
            document.getElementById('cm-payment-terms').value = c.payment_terms || '';
            document.getElementById('cm-allowed-insurers').value = c.allowed_insurers || 'BOTH';
            document.getElementById('cm-inst-count').value = c.installment_count || '';
            document.getElementById('cm-offset-months').value = c.first_due_offset_months || 0;
            document.getElementById('cm-offset-days').value = c.first_due_offset_days || 0;
            const groupSel = document.getElementById('cm-group-select');
            if (c.bale_group_chat_id && [...groupSel.options].some(o => o.value == c.bale_group_chat_id)) {
                groupSel.value = c.bale_group_chat_id;
            } else if (c.bale_group_chat_id) {
                groupSel.value = '__manual__';
                document.getElementById('cm-group-manual').value = c.bale_group_chat_id;
            }
            openModal('add-company-modal');
        }

        async function createCompany() {
            const name = document.getElementById('cm-company-name').value.trim();
            if (!name) { showToast('نام شرکت را وارد کنید.', 'error'); return; }
            const companyId = document.getElementById('cm-company-id').value;
            const groupSelVal = document.getElementById('cm-group-select').value;
            const groupChatId = groupSelVal === '__manual__' ? document.getElementById('cm-group-manual').value.trim() : groupSelVal;
            const payload = {
                action: companyId ? 'update_company' : 'create_company',
                id: companyId || undefined,
                name,
                payment_terms: document.getElementById('cm-payment-terms').value,
                allowed_insurers: document.getElementById('cm-allowed-insurers').value,
                economic_code: document.getElementById('cm-economic-code').value.trim(),
                phone: document.getElementById('cm-phone').value.trim(),
                address: document.getElementById('cm-address').value.trim(),
                parent_company_id: document.getElementById('cm-parent-company').value,
                bale_group_chat_id: groupChatId,
                installment_count: document.getElementById('cm-inst-count').value,
                first_due_offset_months: document.getElementById('cm-offset-months').value,
                first_due_offset_days: document.getElementById('cm-offset-days').value,
            };
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
            const data = await res.json();
            if (data.ok) { showToast(companyId ? 'شرکت به‌روزرسانی شد.' : 'شرکت ثبت شد.', 'success'); resetCompanyForm(); closeModal('add-company-modal'); loadCompanyManage(); }
            else showToast(data.error || 'خطا', 'error');
        }

        function resetPortalUserForm() {
            document.getElementById('cm-pu-modal-title').textContent = 'افزودن عضو - حساب کاربری ثبت‌کننده';
            document.getElementById('cm-pu-submit-label').textContent = 'ساخت حساب کاربری';
            document.getElementById('cm-pu-password-label').textContent = 'رمز عبور';
            document.getElementById('cm-pu-id').value = '';
            ['cm-pu-fullname','cm-pu-username','cm-pu-password','cm-pu-mobile'].forEach(id => document.getElementById(id).value = '');
            document.querySelectorAll('.cm-pu-company-cb:checked').forEach(cb => cb.checked = false);
        }
        function openAddPortalUserModal() {
            resetPortalUserForm();
            openModal('add-portal-user-modal');
        }
        function editPortalUser(id) {
            const u = portalUsersCache.find(x => String(x.id) === String(id));
            if (!u) return;
            document.getElementById('cm-pu-modal-title').textContent = `ویرایش «${u.full_name}»`;
            document.getElementById('cm-pu-submit-label').textContent = 'ذخیره تغییرات';
            document.getElementById('cm-pu-password-label').textContent = 'رمز عبور (اختیاری - فقط برای تغییر رمز پر کنید)';
            document.getElementById('cm-pu-id').value = u.id;
            document.getElementById('cm-pu-fullname').value = u.full_name || '';
            document.getElementById('cm-pu-username').value = u.username || '';
            document.getElementById('cm-pu-password').value = '';
            document.getElementById('cm-pu-mobile').value = u.mobile_number || '';
            const ids = (u.company_ids || '').split(',').filter(Boolean);
            document.querySelectorAll('.cm-pu-company-cb').forEach(cb => cb.checked = ids.includes(cb.value));
            openModal('add-portal-user-modal');
        }
        async function submitPortalUser() {
            const userId = document.getElementById('cm-pu-id').value;
            const companyIds = [...document.querySelectorAll('.cm-pu-company-cb:checked')].map(cb => cb.value);
            const payload = {
                action: userId ? 'update_portal_user' : 'create_portal_user',
                id: userId || undefined,
                company_ids: companyIds,
                full_name: document.getElementById('cm-pu-fullname').value.trim(),
                username: document.getElementById('cm-pu-username').value.trim(),
                password: document.getElementById('cm-pu-password').value,
                mobile_number: document.getElementById('cm-pu-mobile').value.trim(),
            };
            if (!companyIds.length || !payload.full_name || !payload.username || (!userId && !payload.password)) {
                showToast('همه‌ی فیلدها الزامی هستند و حداقل یک شرکت باید انتخاب شود.', 'error'); return;
            }
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
            const data = await res.json();
            if (data.ok) {
                showToast(userId ? 'حساب کاربری ویرایش شد.' : 'حساب کاربری ساخته شد.', 'success');
                resetPortalUserForm();
                closeModal('add-portal-user-modal');
                loadCompanyManage();
            } else showToast(data.error || 'خطا', 'error');
        }

        function deletePortalUserRow(id, name) {
            showConfirm('حذف حساب کاربری', `حساب کاربری «${name}» حذف می‌شود.`, async () => {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'delete_portal_user', id})});
                const data = await res.json();
                if (data.ok) { showToast('حساب کاربری حذف شد.', 'success'); loadCompanyManage(); }
                else showToast(data.error || 'خطا', 'error');
            });
        }

        function deleteCompanyRow(id, name) {
            showConfirm('حذف شرکت', `شرکت «${name}» و همه‌ی درخواست‌ها، مدارک و اطلاعات مالیِ آن برای همیشه حذف می‌شود. این کار قابل بازگشت نیست.`, async () => {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'delete_company', id})});
                const data = await res.json();
                if (data.ok) { showToast('شرکت حذف شد.', 'success'); loadCompanyManage(); }
                else showToast(data.error || 'خطا', 'error');
            });
        }

        // ======================= کاربران داخلیِ پنل =======================
        const STAFF_API = 'api/staff_users_actions.php';
        const ROLE_FA = {ADMIN: 'مدیر کل', OPERATOR: 'اپراتور', FINANCE: 'مالی', COMPANY_LIAISON: 'همکار شرکت‌ها'};

        async function loadStaffUsers() {
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list'})});
            const data = await res.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            document.getElementById('su-body').innerHTML = data.users.map(u => `
                <tr class="border-t border-slate-100">
                    <td class="p-3 font-mono">${u.username}</td>
                    <td class="p-3">${u.full_name}</td>
                    <td class="p-3">
                        <select onchange="changeStaffRole(${u.id}, this.value)" class="border rounded-lg px-2 py-1 text-xs">
                            ${Object.keys(ROLE_FA).map(r => `<option value="${r}" ${r === u.role ? 'selected' : ''}>${ROLE_FA[r]}</option>`).join('')}
                        </select>
                    </td>
                    <td class="p-3 font-mono text-slate-400">${u.mobile_number || '—'}</td>
                    <td class="p-3"><button onclick="openResetPasswordModal(${u.id})" class="text-blue-600 hover:underline text-xs font-bold">تغییر رمز</button></td>
                </tr>`).join('') || '<tr><td colspan="5" class="text-center p-6 text-slate-400">کاربری ثبت نشده.</td></tr>';
        }

        async function createStaffUser() {
            const payload = {
                action: 'create',
                full_name: document.getElementById('su-fullname').value.trim(),
                username: document.getElementById('su-username').value.trim(),
                password: document.getElementById('su-password').value,
                mobile_number: document.getElementById('su-mobile').value.trim(),
                role: document.getElementById('su-role').value,
            };
            if (!payload.full_name || !payload.username || !payload.password) { showToast('همه‌ی فیلدها الزامی هستند.', 'error'); return; }
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
            const data = await res.json();
            if (data.ok) {
                showToast('کاربر ثبت شد.', 'success');
                ['su-fullname','su-username','su-password','su-mobile'].forEach(id => document.getElementById(id).value = '');
                closeModal('add-staff-user-modal');
                loadStaffUsers();
            } else showToast(data.error || 'خطا', 'error');
        }

        async function changeStaffRole(id, role) {
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'update_role', id, role})});
            const data = await res.json();
            showToast(data.ok ? 'نقش به‌روزرسانی شد.' : (data.error || 'خطا'), data.ok ? 'success' : 'error');
            if (!data.ok) loadStaffUsers();
        }

        let resetPasswordUserId = null;
        function openResetPasswordModal(id) {
            resetPasswordUserId = id;
            document.getElementById('rp-password').value = '';
            document.getElementById('reset-password-modal').classList.add('active');
        }
        async function submitResetPassword() {
            const password = document.getElementById('rp-password').value;
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'reset_password', id: resetPasswordUserId, password})});
            const data = await res.json();
            if (data.ok) { showToast('رمز عبور تغییر کرد.', 'success'); document.getElementById('reset-password-modal').classList.remove('active'); }
            else showToast(data.error || 'خطا', 'error');
        }

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            const target = document.getElementById('tab-' + tabId);
            if (!target) { showToast('این بخش هنوز در دسترس نیست.', 'error'); return; }
            target.classList.remove('hidden');

            document.querySelectorAll('.nav-item').forEach(el => {
                el.classList.remove('text-blue-600', 'active-sub');
                el.classList.add('hover:text-blue-600');
            });
            const activeNav = document.getElementById('nav-' + tabId);
            if(activeNav) {
                activeNav.classList.remove('hover:text-blue-600');
                activeNav.classList.add(activeNav.classList.contains('menu-link') ? 'active-sub' : 'text-blue-600');
                // گروهِ والد را هم برجسته کن
                const grp = activeNav.closest('.menu-group');
                if (grp) grp.querySelector('.menu-trigger').classList.add('text-blue-600');
            }
            // گروه‌های باز را ببند تا منو تمیز بماند
            document.querySelectorAll('.menu-group').forEach(g => {
                g.classList.remove('open');
                const t = g.querySelector('.menu-trigger');
                if (!g.contains(activeNav)) t.classList.remove('text-blue-600');
            });
            // در موبایل، بعد از انتخاب یک تب، منو خودکار بسته شود
            if (window.innerWidth < 1024) document.getElementById('main-nav').classList.add('hidden');
            // نکته: بارگذاریِ همه‌ی تب‌های مالی از یک جا انجام می‌شود، با
            // initFinance(tabId) در پایینِ همین تابع (که اول bootstrap را می‌گیرد و
            // کشویی‌های دوره/شرکت را پر می‌کند). قبلاً همین‌جا یک فهرستِ تکراری هم بود
            // که سه اسمِ تابعِ منقضی داشت (loadReconcilePage / loadPasargadSettle /
            // loadFinSettings که هیچ‌کدام وجود ندارند) و باعث می‌شد سه تبِ «مغایرت‌گیری
            // با اکسل»، «تسویه با پاسارگاد» و «تنظیمات مالی» با ReferenceError متوقف
            // شوند و هیچ‌وقت داده‌شان بار نشود؛ چهار تبِ دیگر هم دو بار بار می‌شدند.
            if (tabId === 'records') loadRecords();
            if (tabId === 'dashboard') loadStats();
            if (tabId === 'filemanager') fmOpen(fmCurrentPath);
            if (tabId === 'queue') loadQueue();
            if (tabId === 'users') loadUsers();
            if (tabId === 'tickets') loadTickets();
            if (tabId === 'settings') { loadQuotaSetting(); loadOtpSettings(); }
            if (tabId === 'cases') loadCases();
            if (tabId === 'health') { loadHealthTab(); loadDocsReviewList(); }
            if (tabId.startsWith('fin-')) initFinance(tabId);
            if (tabId === 'issue-queue') { loadIssueQueueCompanies(); loadIssueQueue(); }
            if (tabId === 'issued-list') loadIssuedList();
            if (tabId === 'companies-requests') loadCompanyRequests();
            if (tabId === 'companies-inbox') loadCompanyInbox();
            if (tabId === 'companies-finance') loadCompanyFinance();
            if (tabId === 'companies-manage') loadCompanyManage();
            if (tabId === 'staff-users') loadStaffUsers();
        }

        // ======================= بخش مالی =======================
        const FIN_API = 'api/finance_actions.php';
        let finBoot = null;
        const INST_ST = {
            PAID:    ['تسویه‌شده',   'bg-emerald-100 text-emerald-700'],
            PARTIAL: ['ناقص',        'bg-amber-100 text-amber-700'],
            UNPAID:  ['پرداخت‌نشده', 'bg-slate-100 text-slate-600'],
        };

        async function ensureFinBoot() {
            if (finBoot) return finBoot;
            const res = await fetch(`${FIN_API}?action=bootstrap`);
            finBoot = await res.json();
            if (!finBoot.ok) { showToast(finBoot.error || 'خطا در بارگذاری بخش مالی', 'error'); return null; }
            // پرکردن همه‌ی کشویی‌های دوره و شرکت
            const periodOpts = finBoot.periods.map(p =>
                `<option value="${p.id}">${p.title}${p.status === 'CLOSED' ? ' (بسته)' : ''}</option>`).join('');
            const companyOpts = '<option value="">همه شرکت‌ها</option>' +
                finBoot.companies.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
            ['fin-dash-period','inst-f-period','rec-period','inv-f-period','psg-f-period'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.innerHTML = (id === 'inst-f-period' ? '<option value="">همه دوره‌ها</option>' : '') + periodOpts;
                          if (finBoot.current_period_id) el.value = finBoot.current_period_id; }
            });
            ['inst-f-company','inv-f-company','pay-f-company','psg-f-company'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.innerHTML = companyOpts;
            });
            return finBoot;
        }

        async function initFinance(tabId) {
            const b = await ensureFinBoot();
            if (!b) return;
            if (tabId === 'fin-dashboard')    loadFinDashboard();
            if (tabId === 'fin-installments') loadFinInstallments();
            if (tabId === 'fin-settings')     loadFinanceSettings();
            if (tabId === 'fin-invoices')     loadInvoices();
            if (tabId === 'fin-payments')     { loadPayments(); loadCheques(); }
            if (tabId === 'fin-pasargad')     loadPasargad();
        }

        // ---------- داشبورد مالی ----------
        async function loadFinDashboard() {
            const pid = document.getElementById('fin-dash-period').value || '';
            const cards = document.getElementById('fin-dash-cards');
            cards.innerHTML = '<p class="col-span-4 text-center text-slate-400 text-sm p-4"><i class="fas fa-spinner fa-spin"></i></p>';
            try {
                const res = await fetch(`${FIN_API}?action=dashboard&period_id=${pid}`);
                const d = await res.json();
                if (!d.ok) { cards.innerHTML = `<p class="col-span-4 text-red-500 text-sm">${d.error}</p>`; return; }
                const s = d.summary;
                const card = (t, v, cls, icon) => `
                    <div class="card p-4">
                        <p class="text-[11px] text-slate-400 mb-1"><i class="fas ${icon} ml-1"></i>${t}</p>
                        <p class="text-lg font-black ${cls}">${money(v)}</p>
                        <p class="text-[9px] text-slate-400">ریال</p>
                    </div>`;
                cards.innerHTML =
                    card('بدهی کل به پاسارگاد', s.total_debt, 'text-slate-700', 'fa-building-columns') +
                    card('دریافتی از شرکت‌ها', s.total_collected, 'text-emerald-600', 'fa-hand-holding-dollar') +
                    card('مانده‌ی وصول‌نشده', s.remaining, 'text-amber-600', 'fa-hourglass-half') +
                    card('تسویه‌شده با پاسارگاد', s.settled_pasargad, 'text-blue-600', 'fa-check-double');

                const ob = document.getElementById('fin-dash-overdue');
                ob.innerHTML = !d.overdue.length
                    ? '<p class="text-center text-slate-400 text-xs p-4">قسط سررسیدگذشته‌ای وجود ندارد.</p>'
                    : `<table class="w-full text-[11px]"><thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-2 text-right">شرکت</th><th class="p-2 text-right">بیمه‌گذار</th><th class="p-2">پلاک</th>
                        <th class="p-2">قسط</th><th class="p-2">سررسید</th><th class="p-2">مبلغ</th><th class="p-2">مانده</th></tr></thead><tbody>` +
                      d.overdue.map(r => `<tr class="border-b hover:bg-red-50">
                        <td class="p-2">${r.company_name || '-'}</td><td class="p-2">${r.insured_name || '-'}</td>
                        <td class="p-2 text-center" dir="ltr">${r.plate || '-'}</td>
                        <td class="p-2 text-center">${e2p(r.inst_number)}</td>
                        <td class="p-2 text-center text-red-600" dir="ltr">${e2p(r.due_jalali)}</td>
                        <td class="p-2 text-center">${money(r.amount)}</td>
                        <td class="p-2 text-center font-bold text-red-600">${money(r.amount - r.paid)}</td></tr>`).join('') + '</tbody></table>';

                const cb = document.getElementById('fin-dash-companies');
                cb.innerHTML = !d.companies.length
                    ? '<p class="text-center text-slate-400 text-xs p-4">داده‌ای نیست.</p>'
                    : `<table class="w-full text-[11px]"><thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-2 text-right">شرکت</th><th class="p-2">تعداد بیمه‌نامه</th><th class="p-2">جمع اقساط</th>
                        <th class="p-2">پرداخت‌شده</th><th class="p-2">مانده</th><th class="p-2">پیشرفت</th></tr></thead><tbody>` +
                      d.companies.map(r => {
                        const rem = r.total_amount - r.paid_amount;
                        const pct = r.total_amount > 0 ? Math.round((r.paid_amount / r.total_amount) * 100) : 0;
                        return `<tr class="border-b hover:bg-slate-50">
                            <td class="p-2 font-bold">${r.name}</td>
                            <td class="p-2 text-center">${e2p(r.policy_count)}</td>
                            <td class="p-2 text-center">${money(r.total_amount)}</td>
                            <td class="p-2 text-center text-emerald-600">${money(r.paid_amount)}</td>
                            <td class="p-2 text-center ${rem > 0 ? 'text-amber-600 font-bold' : 'text-slate-400'}">${money(rem)}</td>
                            <td class="p-2"><div class="bg-slate-200 rounded-full h-2 w-20 mx-auto"><div class="bg-emerald-500 h-2 rounded-full" style="width:${pct}%"></div></div>
                                <span class="text-[9px] text-slate-400">${e2p(pct)}٪</span></td></tr>`;
                      }).join('') + '</tbody></table>';
            } catch(e) { cards.innerHTML = '<p class="col-span-4 text-red-500 text-sm">خطا در اتصال.</p>'; }
        }

        // ---------- اقساط ----------
        let instTimer = null;
        function debouncedInstallments() { clearTimeout(instTimer); instTimer = setTimeout(loadFinInstallments, 400); }

        async function loadFinInstallments() {
            const body = document.getElementById('fin-installments-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6"><i class="fas fa-spinner fa-spin"></i></p>';
            const q = new URLSearchParams({
                action: 'installments',
                period_id: document.getElementById('inst-f-period').value || '',
                company_id: document.getElementById('inst-f-company').value || '',
                status: document.getElementById('inst-f-status').value || '',
                q: document.getElementById('inst-f-q').value || '',
            });
            try {
                const res = await fetch(`${FIN_API}?${q}`);
                const d = await res.json();
                if (!d.ok) { body.innerHTML = `<p class="text-red-500 text-sm p-4">${d.error}</p>`; return; }
                if (!d.data.length) { body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6">قسطی با این فیلترها یافت نشد.</p>'; return; }

                const totals = d.data.reduce((a, r) => { a.amount += r.amount; a.paid += r.paid; return a; }, {amount:0, paid:0});
                body.innerHTML = `
                    <div class="p-3 bg-slate-50 text-[11px] flex gap-6 flex-wrap border-b">
                        <span>تعداد: <b>${e2p(d.data.length)}</b></span>
                        <span>جمع اقساط: <b>${money(totals.amount)}</b> ریال</span>
                        <span class="text-emerald-600">پرداخت‌شده: <b>${money(totals.paid)}</b></span>
                        <span class="text-amber-600">مانده: <b>${money(totals.amount - totals.paid)}</b></span>
                    </div>
                    <table class="w-full text-[11px]">
                        <thead class="bg-slate-100 text-slate-600"><tr>
                            <th class="p-2 text-right">شرکت</th><th class="p-2 text-right">بیمه‌گذار</th>
                            <th class="p-2">پلاک</th><th class="p-2">نوع</th><th class="p-2">شماره بیمه‌نامه</th>
                            <th class="p-2">قسط</th><th class="p-2">سررسید</th><th class="p-2">مبلغ</th>
                            <th class="p-2">پرداخت‌شده</th><th class="p-2">مانده</th><th class="p-2">وضعیت</th><th class="p-2">پاسارگاد</th><th class="p-2">صورتحساب</th>
                        </tr></thead><tbody>` +
                    d.data.map(r => {
                        const st = INST_ST[r.pay_status];
                        return `<tr class="border-b hover:bg-indigo-50">
                            <td class="p-2">${r.company_name || '-'}</td>
                            <td class="p-2">${r.insured_name || r.holder_name || '-'}</td>
                            <td class="p-2 text-center" dir="ltr">${r.plate || '-'}</td>
                            <td class="p-2 text-center">${insurance_type_fa_js(r.insurance_type)}</td>
                            <td class="p-2 text-center" dir="ltr">${r.policy_number || '-'}</td>
                            <td class="p-2 text-center font-bold">${e2p(r.inst_number)}</td>
                            <td class="p-2 text-center" dir="ltr">${e2p(r.due_jalali)}</td>
                            <td class="p-2 text-center">${money(r.amount)}</td>
                            <td class="p-2 text-center text-emerald-600">${money(r.paid)}</td>
                            <td class="p-2 text-center ${r.remaining > 0 ? 'text-amber-600 font-bold' : 'text-slate-300'}">${money(r.remaining)}</td>
                            <td class="p-2 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${st[1]}">${st[0]}</span></td>
                            <td class="p-2 text-center">${r.settled_to_pasargad == 1 ? '<span class="text-blue-600">✓ تسویه</span>' : '<span class="text-slate-300">—</span>'}</td>
                            <td class="p-2 text-center">${r.is_invoiced == 1 ? '<span class="text-emerald-600">دارای صورتحساب</span>' : '<span class="text-slate-300">بدون صورتحساب</span>'}</td>
                        </tr>`;
                    }).join('') + '</tbody></table>';
            } catch(e) { body.innerHTML = '<p class="text-red-500 text-sm p-4">خطا در اتصال.</p>'; }
        }

        function exportInstallments(ev) {
            ev.preventDefault();
            const q = new URLSearchParams({
                action: 'export_installments',
                period_id: document.getElementById('inst-f-period').value || '',
                company_id: document.getElementById('inst-f-company').value || '',
                status: document.getElementById('inst-f-status').value || '',
                q: document.getElementById('inst-f-q').value || '',
            });
            window.open(`${FIN_API}?${q}`, '_blank');
        }

        // ---------- تنظیمات مالی ----------
        async function loadFinanceSettings() {
            try {
                const res = await fetch(`${FIN_API}?action=get_settings`);
                const d = await res.json();
                if (!d.ok) return;
                const s = d.settings;
                document.getElementById('fs-cutoff').value = s.period_cutoff_day;
                document.getElementById('fs-inst-count').value = s.default_installments;
                document.querySelectorAll('input[name="fs-method"]').forEach(r => r.checked = (r.value === s.installment_method));
                document.getElementById('fs-rule-seq').checked = s.rule_sequential_payment === '1';
                document.getElementById('fs-rule-collect').checked = s.rule_collect_before_pay === '1';
                document.getElementById('fs-inv-prefix').value = s.invoice_prefix;
                (d.templates || []).forEach(t => {
                    const map = { SUMMARY: 'tpl-summary-current', PERSONNEL: 'tpl-personnel-current', DETAILED: 'tpl-detailed-current' };
                    const el = document.getElementById(map[t.kind]);
                    if (el) el.innerHTML = `<span class="text-emerald-600">✓ بارگذاری‌شده</span> — ${toJalali(t.uploaded_at)}`;
                });
            } catch(e) {}
        }

        async function saveFinanceSettings() {
            try {
                const res = await fetch(FIN_API, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'save_settings',
                        cutoff: document.getElementById('fs-cutoff').value,
                        inst_count: document.getElementById('fs-inst-count').value,
                        method: (document.querySelector('input[name="fs-method"]:checked') || {}).value || 'mamut',
                        rule_seq: document.getElementById('fs-rule-seq').checked,
                        rule_collect: document.getElementById('fs-rule-collect').checked,
                        inv_prefix: document.getElementById('fs-inv-prefix').value,
                    })
                });
                const d = await res.json();
                if (!d.ok) { showToast(d.error || 'خطا در ذخیره', 'error'); return; }
                finBoot = null;   // تنظیمات عوض شد، بارگذاری مجدد لازم است
                showToast('تنظیمات مالی ذخیره شد.', 'success');
            } catch(e) { showToast('خطا در ذخیره‌سازی', 'error'); }
        }

        // ---------- بخش‌هایی که در بسته‌ی بعدی تکمیل می‌شوند ----------
        const finSoon = (elId, title) => {
            const el = document.getElementById(elId);
            if (el) el.innerHTML = `<p class="text-center text-slate-400 text-sm p-8"><i class="fas fa-screwdriver-wrench ml-2"></i>${title} در بسته‌ی بعدی فعال می‌شود.</p>`;
        };
        // ---------- صورتحساب‌ها ----------
        const INV_KIND = { SUMMARY: 'تجمیعی (کل دوره)', PERSONNEL: 'تلفیقی (ریز پرسنل)', DETAILED: 'تفکیکی (یک شرکت)' };
        const PAY_METHOD = { TRANSFER: 'واریز/حواله', CHEQUE: 'چک', CASH: 'نقدی', PAYROLL: 'کسر از حقوق' };

        async function loadInvoices() {
            const body = document.getElementById('fin-invoices-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6"><i class="fas fa-spinner fa-spin"></i></p>';
            const q = new URLSearchParams({
                action: 'invoices',
                period_id: document.getElementById('inv-f-period').value || '',
                company_id: document.getElementById('inv-f-company').value || '',
            });
            try {
                const res = await fetch(`${FIN_API}?${q}`);
                const d = await res.json();
                if (!d.ok) { body.innerHTML = `<p class="text-red-500 text-sm p-4">${d.error}</p>`; return; }
                if (!d.data.length) { body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6">صورتحسابی صادر نشده است.</p>'; return; }
                body.innerHTML = `<table class="w-full text-[11px]">
                    <thead class="bg-slate-100 text-slate-600"><tr>
                        <th class="p-2">شماره صورتحساب</th><th class="p-2">نوع</th><th class="p-2 text-right">شرکت</th>
                        <th class="p-2">دوره</th><th class="p-2">ردیف</th><th class="p-2">جمع کل</th>
                        <th class="p-2">پرداخت‌شده</th><th class="p-2">مانده</th><th class="p-2">تاریخ</th><th class="p-2">عملیات</th>
                    </tr></thead><tbody>` +
                    d.data.map(r => {
                        const rem = r.total_amount - r.paid_amount;
                        return `<tr class="border-b hover:bg-blue-50">
                            <td class="p-2 text-center font-bold" dir="ltr">${r.invoice_no}</td>
                            <td class="p-2 text-center">${INV_KIND[r.kind] || r.kind}</td>
                            <td class="p-2">${r.company_name || '—'}</td>
                            <td class="p-2 text-center">${r.period_title || '-'}</td>
                            <td class="p-2 text-center">${e2p(r.line_count)}</td>
                            <td class="p-2 text-center font-bold">${money(r.total_amount)}</td>
                            <td class="p-2 text-center text-emerald-600">${money(r.paid_amount)}</td>
                            <td class="p-2 text-center ${rem > 0 ? 'text-amber-600 font-bold' : 'text-slate-300'}">${money(rem)}</td>
                            <td class="p-2 text-center" dir="ltr">${toJalali(r.created_at)}</td>
                            <td class="p-2 text-center whitespace-nowrap">
                                <button onclick="openInvoiceDetail(${r.id})" class="text-blue-600 underline">مشاهده</button>
                                ${r.docx_path ? `<a href="/${encodeFilePath(r.docx_path)}" target="_blank" class="text-indigo-600 underline mr-2">Word</a>` : ''}
                                ${r.pdf_path ? `<a href="/${encodeFilePath(r.pdf_path)}" target="_blank" class="text-red-600 underline mr-2">PDF</a>` : ''}
                            </td></tr>`;
                    }).join('') + '</tbody></table>';
            } catch(e) { body.innerHTML = '<p class="text-red-500 text-sm p-4">خطا در اتصال.</p>'; }
        }

        function openNewInvoice() {
            document.getElementById('inv-new-modal').classList.remove('hidden');
            document.getElementById('inv-new-modal').classList.add('flex');
            // کپی گزینه‌های دوره و شرکت از فیلترها
            document.getElementById('inv-new-period').innerHTML = document.getElementById('inv-f-period').innerHTML;
            document.getElementById('inv-new-company').innerHTML = document.getElementById('inv-f-company').innerHTML;
            document.getElementById('inv-new-preview').innerHTML = '';
            onInvKindChange();
        }
        function onInvKindChange() {
            const kind = document.querySelector('input[name="inv-kind"]:checked').value;
            // تفکیکی به یک شرکتِ ثبت‌شده وصل است؛ دو نوع دیگر روی کل دوره‌اند و نام شرکتِ
            // مخاطبِ نامه دستی وارد می‌شود
            document.getElementById('inv-company-wrap').style.display = kind === 'DETAILED' ? 'block' : 'none';
            document.getElementById('inv-manual-wrap').style.display = kind === 'DETAILED' ? 'none' : 'block';
            document.getElementById('inv-new-preview').innerHTML = '';
        }

        async function previewInvoice() {
            const kind = document.querySelector('input[name="inv-kind"]:checked').value;
            const box = document.getElementById('inv-new-preview');
            box.innerHTML = '<p class="text-center text-slate-400 text-xs p-4"><i class="fas fa-spinner fa-spin"></i></p>';
            const q = new URLSearchParams({
                action: 'invoice_preview', kind,
                period_id: document.getElementById('inv-new-period').value,
                company_id: kind === 'DETAILED' ? document.getElementById('inv-new-company').value : '',
                manual_company: kind === 'DETAILED' ? '' : (document.getElementById('inv-manual-company').value || ''),
            });
            try {
                const res = await fetch(`${FIN_API}?${q}`);
                const d = await res.json();
                if (!d.ok) { box.innerHTML = `<p class="text-red-600 text-xs p-3">${d.error}</p>`; return; }

                const recWarn = d.reconcile_ok ? '' : `
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-3">
                        <p class="text-[11px] font-bold text-amber-700">⚠️ مغایرت‌گیری این دوره تایید نشده است.</p>
                        ${d.reconcile ? `<p class="text-[10px] text-amber-600 mt-1">
                            فقط در اکسل: ${e2p(d.reconcile.only_excel)} | فقط در پنل: ${e2p(d.reconcile.only_panel)} | اختلاف مبلغ: ${e2p(d.reconcile.mismatched)}</p>
                            <button onclick="overrideReconcile(${d.reconcile.id})" class="mt-2 bg-amber-500 text-white text-[10px] font-bold px-3 py-1.5 rounded-lg">تایید دستی با ثبت دلیل</button>`
                          : '<p class="text-[10px] text-amber-600 mt-1">برای این دوره هنوز مغایرت‌گیری انجام نشده است.</p>'}
                    </div>`;

                const cols = kind === 'SUMMARY'
                    ? [['نام شرکت','company_name'],['تعداد بیمه‌نامه','policy_count'],['جمع حق بیمه','total_premium'],['قسط ماهانه','monthly_amount']]
                    : kind === 'PERSONNEL'
                    ? [['نام پرسنل','person_name'],['نام شرکت','company_name'],['کد پرسنلی','personnel_code'],['کد ملی','national_code'],['نوع بیمه‌نامه','insurance_type'],['مبلغ حق بیمه','total_premium']]
                    : [['نام پرسنل','person_name'],['کد ملی','national_code'],['پلاک','plate'],['نوع','insurance_type'],['شماره بیمه‌نامه','policy_number'],['حق بیمه','total_premium'],['قسط ماهانه','monthly_amount']];

                box.innerHTML = recWarn + `
                    <div class="flex gap-4 text-[11px] mb-2">
                        <span>تعداد ردیف: <b>${e2p(d.count)}</b></span>
                        <span>جمع کل: <b>${money(d.total)}</b> ریال</span>
                        <span>قسط ماهانه: <b>${money(d.monthly)}</b></span>
                    </div>
                    <div class="max-h-60 overflow-y-auto border rounded-xl">
                        <table class="w-full text-[10.5px]"><thead class="bg-slate-50 sticky top-0"><tr>` +
                            cols.map(x => `<th class="p-2">${x[0]}</th>`).join('') + `</tr></thead><tbody>` +
                        d.lines.map(l => '<tr class="border-b">' + cols.map(x => {
                            const isNum = ['total_premium','monthly_amount'].includes(x[1]);
                            return `<td class="p-2 text-center">${isNum ? money(l[x[1]]) : (l[x[1]] || '-')}</td>`;
                        }).join('') + '</tr>').join('') + `</tbody></table>
                    </div>
                    <button onclick="doCreateInvoice()" ${d.reconcile_ok ? '' : 'disabled'}
                        class="w-full mt-3 ${d.reconcile_ok ? 'bg-blue-600 hover:bg-blue-700' : 'bg-slate-300 cursor-not-allowed'} text-white font-bold py-2.5 rounded-xl text-xs">
                        <i class="fas fa-file-invoice ml-1"></i> صدور نهایی صورتحساب
                    </button>`;
            } catch(e) { box.innerHTML = '<p class="text-red-600 text-xs p-3">خطا در اتصال.</p>'; }
        }

        async function overrideReconcile(id) {
            const reason = prompt('دلیل تایید دستی مغایرت را بنویسید:');
            if (!reason || !reason.trim()) return;
            try {
                const res = await fetch(FIN_API, { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'override_reconcile', id, reason }) });
                const d = await res.json();
                if (!d.ok) { showToast(d.error, 'error'); return; }
                showToast('مغایرت تایید دستی شد.', 'success');
                previewInvoice();
            } catch(e) { showToast('خطا در اتصال', 'error'); }
        }

        async function doCreateInvoice() {
            const kind = document.querySelector('input[name="inv-kind"]:checked').value;
            showToast('در حال صدور صورتحساب...', 'info');
            try {
                const res = await fetch(FIN_API, { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'create_invoice', kind,
                        period_id: document.getElementById('inv-new-period').value,
                        company_id: kind === 'DETAILED' ? document.getElementById('inv-new-company').value : null,
                        manual_company: kind === 'DETAILED' ? null : (document.getElementById('inv-manual-company').value || null) }) });
                const d = await res.json();
                if (!d.ok) { showToast(d.error, 'error'); return; }
                let msg = `صورتحساب ${d.invoice_no} صادر شد.`;
                if (d.generated && d.generated.note) msg += '\n' + d.generated.note;
                alert(msg);
                document.getElementById('inv-new-modal').classList.add('hidden');
                loadInvoices();
            } catch(e) { showToast('خطا در اتصال', 'error'); }
        }

        async function openInvoiceDetail(id) {
            try {
                const res = await fetch(`${FIN_API}?action=invoice_detail&id=${id}`);
                const d = await res.json();
                if (!d.ok) { showToast(d.error, 'error'); return; }
                const i = d.invoice;
                const paid = d.payments.reduce((s, p) => s + Number(p.amount), 0);
                document.getElementById('inv-detail-title').innerText = i.invoice_no;
                document.getElementById('inv-detail-body').innerHTML = `
                    <div class="grid grid-cols-2 gap-2 text-[11px] bg-slate-50 rounded-xl p-3 mb-3">
                        <div>نوع: <b>${INV_KIND[i.kind]}</b></div>
                        <div>دوره: <b>${i.period_title || '-'}</b></div>
                        <div>شرکت: <b>${i.company_name || 'کل دوره'}</b></div>
                        <div>تاریخ صدور: <b dir="ltr">${toJalali(i.created_at)}</b></div>
                        <div>جمع کل: <b>${money(i.total_amount)}</b> ریال</div>
                        <div>مانده: <b class="${i.total_amount - paid > 0 ? 'text-amber-600' : 'text-emerald-600'}">${money(i.total_amount - paid)}</b></div>
                    </div>
                    <div class="max-h-72 overflow-y-auto border rounded-xl mb-3">
                        <table class="w-full text-[10.5px]"><thead class="bg-slate-100 sticky top-0"><tr>
                            <th class="p-2">ردیف</th><th class="p-2">${i.kind === 'SUMMARY' ? 'شرکت' : 'پرسنل'}</th>
                            ${i.kind === 'SUMMARY' ? '<th class="p-2">تعداد</th>'
                              : i.kind === 'PERSONNEL' ? '<th class="p-2">شرکت</th><th class="p-2">کد پرسنلی</th><th class="p-2">کد ملی</th>'
                              : '<th class="p-2">پلاک</th><th class="p-2">بیمه‌نامه</th>'}
                            <th class="p-2">حق بیمه</th><th class="p-2">قسط ماهانه</th></tr></thead><tbody>` +
                        d.lines.map((l, n) => `<tr class="border-b">
                            <td class="p-2 text-center">${e2p(n+1)}</td>
                            <td class="p-2">${l.person_name || '-'}</td>
                            ${i.kind === 'SUMMARY' ? `<td class="p-2 text-center">${e2p(l.policy_count)}</td>`
                              : i.kind === 'PERSONNEL' ? `<td class="p-2 text-center">${l.company_name || '-'}</td><td class="p-2 text-center" dir="ltr">${e2p(l.personnel_code) || '-'}</td><td class="p-2 text-center" dir="ltr">${e2p(l.national_code) || '-'}</td>`
                              : `<td class="p-2 text-center" dir="ltr">${l.plate || '-'}</td><td class="p-2 text-center" dir="ltr">${l.policy_number || '-'}</td>`}
                            <td class="p-2 text-center">${money(l.total_premium)}</td>
                            <td class="p-2 text-center">${money(l.monthly_amount)}</td></tr>`).join('') + `</tbody></table>
                    </div>
                    <p class="text-[11px] font-bold mb-2">پرداخت‌های ثبت‌شده</p>
                    ${d.payments.length ? d.payments.map(p => `
                        <div class="border rounded-lg p-2 mb-1 text-[10.5px] flex justify-between">
                            <span>${PAY_METHOD[p.method] || p.method} ${p.reference_no ? '— ' + p.reference_no : ''}</span>
                            <span class="font-bold text-emerald-600">${money(p.amount)}</span>
                        </div>`).join('') : '<p class="text-[10.5px] text-slate-400">هنوز پرداختی ثبت نشده است.</p>'}
                    <div class="flex gap-2 mt-3">
                        ${i.docx_path ? `<a href="/${encodeFilePath(i.docx_path)}" target="_blank" class="flex-1 text-center bg-indigo-50 text-indigo-700 py-2 rounded-lg text-[11px] font-bold">دانلود Word</a>` : ''}
                        ${i.pdf_path ? `<a href="/${encodeFilePath(i.pdf_path)}" target="_blank" class="flex-1 text-center bg-red-50 text-red-700 py-2 rounded-lg text-[11px] font-bold">دانلود PDF</a>` : ''}
                    </div>`;
                document.getElementById('inv-detail-modal').classList.remove('hidden');
                document.getElementById('inv-detail-modal').classList.add('flex');
            } catch(e) { showToast('خطا در اتصال', 'error'); }
        }
        // ---------- دریافت‌ها ----------
        const CHQ_ST = { PENDING: ['در جریان','bg-amber-100 text-amber-700'], CLEARED: ['وصول‌شده','bg-emerald-100 text-emerald-700'], BOUNCED: ['برگشتی','bg-red-100 text-red-700'] };

        async function loadPayments() {
            const body = document.getElementById('fin-payments-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6"><i class="fas fa-spinner fa-spin"></i></p>';
            const cid = document.getElementById('pay-f-company').value || '';
            try {
                const res = await fetch(`${FIN_API}?action=payments&company_id=${cid}`);
                const d = await res.json();
                if (!d.ok) { body.innerHTML = `<p class="text-red-500 text-sm p-4">${d.error}</p>`; return; }
                if (!d.data.length) { body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6">دریافتی ثبت نشده است.</p>'; return; }
                body.innerHTML = `<table class="w-full text-[11px]">
                    <thead class="bg-slate-100 text-slate-600"><tr>
                        <th class="p-2 text-right">شرکت</th><th class="p-2">صورتحساب</th><th class="p-2">مبلغ</th>
                        <th class="p-2">روش</th><th class="p-2">تاریخ</th><th class="p-2">مرجع</th>
                        <th class="p-2">تخصیص‌یافته</th><th class="p-2">فیش</th></tr></thead><tbody>` +
                    d.data.map(r => {
                        const un = r.amount - r.allocated;
                        let files = '—';
                        try { const fs = JSON.parse(r.receipts || '[]');
                              if (fs.length) files = fs.map((f,i) => `<a href="/${encodeFilePath(f)}" target="_blank" class="text-blue-600 underline">${e2p(i+1)}</a>`).join(' '); } catch(e){}
                        return `<tr class="border-b hover:bg-emerald-50">
                            <td class="p-2">${r.company_name || '-'}</td>
                            <td class="p-2 text-center" dir="ltr">${r.invoice_no || '—'}</td>
                            <td class="p-2 text-center font-bold text-emerald-600">${money(r.amount)}</td>
                            <td class="p-2 text-center">${PAY_METHOD[r.method] || r.method}</td>
                            <td class="p-2 text-center" dir="ltr">${e2p(r.paid_jalali) || '-'}</td>
                            <td class="p-2 text-center" dir="ltr">${r.reference_no || '-'}</td>
                            <td class="p-2 text-center">${money(r.allocated)}${un > 0 ? `<br><span class="text-[9px] text-amber-600">تخصیص‌نیافته: ${money(un)}</span>` : ''}</td>
                            <td class="p-2 text-center">${files}</td></tr>`;
                    }).join('') + '</tbody></table>';
            } catch(e) { body.innerHTML = '<p class="text-red-500 text-sm p-4">خطا در اتصال.</p>'; }
        }

        async function loadCheques() {
            const body = document.getElementById('fin-cheques-body');
            try {
                const res = await fetch(`${FIN_API}?action=cheques`);
                const d = await res.json();
                if (!d.ok || !d.data.length) { body.innerHTML = '<p class="text-center text-slate-400 text-xs p-4">چکی ثبت نشده است.</p>'; return; }
                body.innerHTML = `<table class="w-full text-[11px]">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-2 text-right">شرکت</th><th class="p-2">شماره چک</th><th class="p-2">بانک</th>
                        <th class="p-2">مبلغ</th><th class="p-2">سررسید</th><th class="p-2">وضعیت</th><th class="p-2">تغییر وضعیت</th></tr></thead><tbody>` +
                    d.data.map(r => {
                        const st = CHQ_ST[r.status] || CHQ_ST.PENDING;
                        return `<tr class="border-b">
                            <td class="p-2">${r.company_name || '-'}</td>
                            <td class="p-2 text-center" dir="ltr">${r.cheque_no}</td>
                            <td class="p-2 text-center">${r.bank_name || '-'}</td>
                            <td class="p-2 text-center">${money(r.amount)}</td>
                            <td class="p-2 text-center" dir="ltr">${e2p(r.due_jalali) || '-'}</td>
                            <td class="p-2 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${st[1]}">${st[0]}</span></td>
                            <td class="p-2 text-center">
                                <select onchange="setChequeStatus(${r.id}, this.value)" class="border rounded px-1 py-0.5 text-[10px]">
                                    <option value="PENDING" ${r.status==='PENDING'?'selected':''}>در جریان</option>
                                    <option value="CLEARED" ${r.status==='CLEARED'?'selected':''}>وصول‌شده</option>
                                    <option value="BOUNCED" ${r.status==='BOUNCED'?'selected':''}>برگشتی</option>
                                </select></td></tr>`;
                    }).join('') + '</tbody></table>';
            } catch(e) { body.innerHTML = '<p class="text-red-500 text-xs p-4">خطا در اتصال.</p>'; }
        }

        async function setChequeStatus(id, status) {
            try {
                await fetch(FIN_API, { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'set_cheque_status', id, status }) });
                showToast('وضعیت چک به‌روز شد.', 'success');
            } catch(e) { showToast('خطا در به‌روزرسانی', 'error'); }
        }

        function openNewPayment() {
            document.getElementById('pay-new-modal').classList.remove('hidden');
            document.getElementById('pay-new-modal').classList.add('flex');
            document.getElementById('pay-new-company').innerHTML = document.getElementById('pay-f-company').innerHTML;
            document.getElementById('pay-new-form').reset();
            onPayMethodChange();
            loadPayInvoices();
        }

        function onPayMethodChange() {
            const m = document.getElementById('pay-method').value;
            document.getElementById('pay-cheque-fields').style.display = m === 'CHEQUE' ? 'grid' : 'none';
        }

        async function loadPayInvoices() {
            const cid = document.getElementById('pay-new-company').value;
            const sel = document.getElementById('pay-invoice');
            sel.innerHTML = '<option value="">— بدون صورتحساب (علی‌الحساب) —</option>';
            if (!cid) return;
            try {
                const res = await fetch(`${FIN_API}?action=invoices&company_id=${cid}`);
                const d = await res.json();
                if (d.ok) sel.innerHTML += d.data.map(i =>
                    `<option value="${i.id}">${i.invoice_no} — ${i.period_title || ''} — مانده ${Number(i.total_amount - i.paid_amount).toLocaleString('en-US')}</option>`).join('');
            } catch(e) {}
        }

        async function submitPayment() {
            const fd = new FormData(document.getElementById('pay-new-form'));
            fd.append('action', 'create_payment');
            if (!fd.get('company_id') || !fd.get('amount')) { showToast('شرکت و مبلغ الزامی است.', 'error'); return; }
            showToast('در حال ثبت...', 'info');
            try {
                const res = await fetch(FIN_API, { method:'POST', body: fd });
                const d = await res.json();
                if (!d.ok) { showToast(d.error, 'error'); return; }
                let msg = `دریافت ثبت شد.\nتخصیص‌یافته به اقساط: ${Number(d.allocated).toLocaleString('en-US')} ریال`;
                if (d.unallocated > 0) msg += `\n⚠️ تخصیص‌نیافته: ${Number(d.unallocated).toLocaleString('en-US')} ریال (قسط بازی برای تخصیص نمانده یا قانون تسویه‌ی پیوسته مانع شده)`;
                alert(msg);
                document.getElementById('pay-new-modal').classList.add('hidden');
                loadPayments(); loadCheques();
            } catch(e) { showToast('خطا در اتصال', 'error'); }
        }

        // ---------- تسویه با پاسارگاد ----------
        async function loadPasargad() {
            const body = document.getElementById('fin-pasargad-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6"><i class="fas fa-spinner fa-spin"></i></p>';
            const q = new URLSearchParams({
                action: 'pasargad_pending',
                period_id: document.getElementById('psg-f-period').value || '',
                company_id: document.getElementById('psg-f-company').value || '',
            });
            try {
                const res = await fetch(`${FIN_API}?${q}`);
                const d = await res.json();
                if (!d.ok) { body.innerHTML = `<p class="text-red-500 text-sm p-4">${d.error}</p>`; return; }
                if (!d.data.length) { body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6">قسط تسویه‌نشده‌ای وجود ندارد.</p>'; return; }

                const ready = d.data.filter(r => r.can_settle);
                const sumReady = ready.reduce((s, r) => s + Number(r.amount), 0);
                body.innerHTML = `
                    <div class="p-3 bg-slate-50 text-[11px] flex gap-6 flex-wrap border-b">
                        <span>کل اقساط تسویه‌نشده: <b>${e2p(d.data.length)}</b></span>
                        <span class="text-emerald-600">آماده‌ی تسویه: <b>${e2p(ready.length)}</b> — ${money(sumReady)} ریال</span>
                        ${d.rule_collect_before_pay ? '<span class="text-amber-600">قانون «اول دریافت، بعد پرداخت» فعال است</span>' : ''}
                    </div>
                    <table class="w-full text-[11px]">
                    <thead class="bg-slate-100 text-slate-600"><tr>
                        <th class="p-2"><input type="checkbox" onclick="togglePsgAll(this)"></th>
                        <th class="p-2 text-right">شرکت</th><th class="p-2 text-right">بیمه‌گذار</th><th class="p-2">پلاک</th>
                        <th class="p-2">شماره بیمه‌نامه</th><th class="p-2">قسط</th><th class="p-2">سررسید</th>
                        <th class="p-2">مبلغ</th><th class="p-2">وصول‌شده</th><th class="p-2">وضعیت</th></tr></thead><tbody>` +
                    d.data.map(r => `<tr class="border-b ${r.can_settle ? 'hover:bg-purple-50' : 'bg-slate-50 opacity-70'}">
                        <td class="p-2 text-center">${r.can_settle ? `<input type="checkbox" class="psg-cb" value="${r.id}" data-amount="${r.amount}">` : '<span class="text-slate-300">—</span>'}</td>
                        <td class="p-2">${r.company_name || '-'}</td>
                        <td class="p-2">${r.insured_name || '-'}</td>
                        <td class="p-2 text-center" dir="ltr">${r.plate || '-'}</td>
                        <td class="p-2 text-center" dir="ltr">
                            <span id="psg-pol-${r.id}">${r.policy_number || '-'}</span>
                            ${r.policy_number ? `<button onclick="copyToClipboard('${r.policy_number}','شماره بیمه‌نامه')" class="text-slate-400 hover:text-indigo-600 mr-1"><i class="fas fa-copy"></i></button>` : ''}
                        </td>
                        <td class="p-2 text-center font-bold">${e2p(r.inst_number)}</td>
                        <td class="p-2 text-center" dir="ltr">${e2p(r.due_jalali)}</td>
                        <td class="p-2 text-center">${money(r.amount)}</td>
                        <td class="p-2 text-center ${r.fully_collected ? 'text-emerald-600' : 'text-amber-600'}">${money(r.paid)}</td>
                        <td class="p-2 text-center">${r.can_settle
                            ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">آماده</span>'
                            : '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-500">وصول نشده</span>'}</td>
                    </tr>`).join('') + '</tbody></table>';
                loadPsgHistory();
            } catch(e) { body.innerHTML = '<p class="text-red-500 text-sm p-4">خطا در اتصال.</p>'; }
        }

        function togglePsgAll(el) { document.querySelectorAll('.psg-cb').forEach(cb => cb.checked = el.checked); }

        let psgPendingIds = [];
        function settleSelectedPasargad() {
            psgPendingIds = Array.from(document.querySelectorAll('.psg-cb:checked')).map(cb => Number(cb.value));
            if (!psgPendingIds.length) { showToast('قسطی انتخاب نشده است.', 'error'); return; }
            const total = Array.from(document.querySelectorAll('.psg-cb:checked')).reduce((s, cb) => s + Number(cb.dataset.amount), 0);
            document.getElementById('psg-settle-summary').textContent = `${psgPendingIds.length} قسط به مبلغ ${total.toLocaleString('en-US')} ریال`;
            ['psg-settle-ref','psg-settle-note'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('psg-settle-jalali').value = todayJalali();
            document.getElementById('psg-settle-files').value = '';
            openModal('psg-settle-modal');
        }

        async function submitPasargadSettle() {
            const fd = new FormData();
            fd.append('action', 'settle_pasargad_with_receipt');
            fd.append('installment_ids', JSON.stringify(psgPendingIds));
            fd.append('reference_no', document.getElementById('psg-settle-ref').value.trim());
            fd.append('paid_jalali', document.getElementById('psg-settle-jalali').value.trim());
            fd.append('note', document.getElementById('psg-settle-note').value.trim());
            fd.append('period_id', document.getElementById('psg-f-period').value || '');
            fd.append('company_id', document.getElementById('psg-f-company').value || '');
            const files = document.getElementById('psg-settle-files').files;
            for (let i = 0; i < files.length; i++) fd.append('pasargad_receipts[]', files[i]);
            try {
                const res = await fetch(FIN_API, {method: 'POST', body: fd});
                const d = await res.json();
                if (!d.ok) { showToast(d.error, 'error'); return; }
                closeModal('psg-settle-modal');
                let msg = `${d.settled} قسط تسویه شد (${Number(d.total).toLocaleString('en-US')} ریال).`;
                if (d.blocked) msg += ` ${d.blocked} قسط به‌دلیل وصول‌نشدن، تسویه نشد.`;
                showToast(msg, 'success');
                loadPasargad();
            } catch(e) { showToast('خطا در اتصال', 'error'); }
        }

        function todayJalali() {
            try { return new Intl.DateTimeFormat('fa-IR-u-nu-latn', {year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()).replace(/\//g, '/'); }
            catch(e) { return ''; }
        }

        async function loadPsgHistory() {
            const body = document.getElementById('fin-psg-history');
            try {
                const res = await fetch(`${FIN_API}?action=pasargad_history`);
                const d = await res.json();
                if (!d.ok || !d.data.length) { body.innerHTML = '<p class="text-center text-slate-400 text-xs p-4">تسویه‌ای ثبت نشده است.</p>'; return; }
                body.innerHTML = `<table class="w-full text-[11px]">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-2">تاریخ</th><th class="p-2 text-right">شرکت</th><th class="p-2">دوره</th>
                        <th class="p-2">تعداد قسط</th><th class="p-2">مبلغ</th><th class="p-2">مرجع</th></tr></thead><tbody>` +
                    d.data.map(r => `<tr class="border-b">
                        <td class="p-2 text-center" dir="ltr">${e2p(r.paid_jalali) || toJalali(r.created_at)}</td>
                        <td class="p-2">${r.company_name || 'همه'}</td>
                        <td class="p-2 text-center">${r.period_title || '-'}</td>
                        <td class="p-2 text-center">${e2p(r.line_count)}</td>
                        <td class="p-2 text-center font-bold">${money(r.amount)}</td>
                        <td class="p-2 text-center" dir="ltr">${r.reference_no || '-'}</td></tr>`).join('') + '</tbody></table>';
            } catch(e) {}
        }

        // ---------- مغایرت‌گیری ----------
        async function runReconcile() {
            const input = document.getElementById('rec-file');
            const file = input.files[0];
            if (!file) return;
            input.value = '';
            const box = document.getElementById('rec-result');
            box.innerHTML = '<div class="card p-6 text-center text-slate-400 text-sm"><i class="fas fa-spinner fa-spin ml-2"></i>در حال تطبیق...</div>';
            const fd = new FormData();
            fd.append('action', 'reconcile');
            fd.append('period_id', document.getElementById('rec-period').value);
            fd.append('file', file);
            try {
                const res = await fetch(FIN_API, { method: 'POST', body: fd });
                const d = await res.json();
                if (!d.ok) { box.innerHTML = `<div class="card p-5 text-red-600 text-sm">${d.error}</div>`; return; }
                renderReconcile(d);
            } catch(e) { box.innerHTML = '<div class="card p-5 text-red-600 text-sm">خطا در اتصال.</div>'; }
        }

        function renderReconcile(d) {
            const c = d.counts, r = d.result;
            const clean = (c.only_excel + c.only_panel + c.mismatched) === 0;
            const cardBox = (t, n, cls, icon) => `
                <div class="card p-4 text-center">
                    <p class="text-[11px] text-slate-400 mb-1"><i class="fas ${icon} ml-1"></i>${t}</p>
                    <p class="text-xl font-black ${cls}">${e2p(n)}</p>
                </div>`;
            const tbl = (rows, cols) => !rows.length ? '' :
                `<table class="w-full text-[11px]"><thead class="bg-slate-50 text-slate-500"><tr>` +
                cols.map(x => `<th class="p-2">${x[0]}</th>`).join('') + `</tr></thead><tbody>` +
                rows.map(row => `<tr class="border-b">` + cols.map(x => {
                    const v = row[x[1]];
                    const isNum = ['premium','panel_premium','excel_premium','diff'].includes(x[1]);
                    return `<td class="p-2 text-center">${isNum ? money(v) : (v || '-')}</td>`;
                }).join('') + `</tr>`).join('') + '</tbody></table>';

            document.getElementById('rec-result').innerHTML = `
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    ${cardBox('منطبق', c.matched, 'text-emerald-600', 'fa-circle-check')}
                    ${cardBox('در اکسل، نه در پنل', c.only_excel, 'text-amber-600', 'fa-file-circle-question')}
                    ${cardBox('در پنل، نه در اکسل', c.only_panel, 'text-blue-600', 'fa-database')}
                    ${cardBox('اختلاف مبلغ', c.mismatched, 'text-red-600', 'fa-scale-unbalanced')}
                </div>
                <div class="card p-4 ${clean ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'}">
                    <p class="text-sm font-bold ${clean ? 'text-emerald-700' : 'text-amber-700'}">
                        ${clean ? '✅ هیچ مغایرتی یافت نشد — می‌توانید صورتحساب صادر کنید.'
                                : '⚠️ مغایرت وجود دارد. تا رفع یا تایید دستی، صدور صورتحساب این دوره قفل است.'}
                    </p>
                </div>
                ${c.mismatched ? `<div class="card p-4"><h3 class="font-bold text-sm text-red-600 mb-3">اختلاف مبلغ حق بیمه</h3><div class="overflow-x-auto">` +
                    tbl(r.mismatched, [['شماره بیمه‌نامه','policy_number'],['شرکت','company'],['بیمه‌گذار','name'],['پلاک','plate'],
                                       ['مبلغ در پنل','panel_premium'],['مبلغ در اکسل','excel_premium'],['اختلاف','diff']]) + '</div></div>' : ''}
                ${c.only_excel ? `<div class="card p-4"><h3 class="font-bold text-sm text-amber-600 mb-3">در اکسل هست، در پنل نیست (احتمالاً خارج از سامانه صادر شده)</h3><div class="overflow-x-auto">` +
                    tbl(r.only_excel, [['شماره بیمه‌نامه','policy_number'],['بیمه‌گذار','name'],['پلاک','plate'],['حق بیمه','premium']]) + '</div></div>' : ''}
                ${c.only_panel ? `<div class="card p-4"><h3 class="font-bold text-sm text-blue-600 mb-3">در پنل هست، در اکسل نیست (شاید پاسارگاد هنوز ثبت نکرده)</h3><div class="overflow-x-auto">` +
                    tbl(r.only_panel, [['شماره بیمه‌نامه','policy_number'],['شرکت','company'],['بیمه‌گذار','name'],['پلاک','plate'],['حق بیمه','premium']]) + '</div></div>' : ''}
            `;
        }

        async function uploadTemplate(kind, input) {
            const file = input.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('action', 'upload_template');
            fd.append('kind', kind);
            fd.append('file', file);
            showToast('در حال بارگذاری قالب...', 'info');
            try {
                const res = await fetch(FIN_API, { method: 'POST', body: fd });
                const d = await res.json();
                if (!d.ok) { showToast(d.error || 'خطا در بارگذاری', 'error'); return; }
                showToast('قالب بارگذاری شد.', 'success');
                loadFinanceSettings();
            } catch(e) { showToast('خطا در اتصال', 'error'); }
        }

        // ======================= صدور بیمه‌نامه =======================
        const CASE_STATUS_FA = {
            REGISTERED: 'ثبت شده', AWAITING_DOCS: 'در انتظار بارگذاری مدارک', DOCS_PENDING: 'در انتظار مدارک', DOCS_REVIEW: 'در انتظار تایید مدارک',
            ISSUING: 'در حال صدور', ISSUED: 'صادر شده', REJECTED: 'رد شده'
        };
        const CASE_STATUS_COLOR = {
            REGISTERED: 'text-slate-500', AWAITING_DOCS: 'text-amber-500', DOCS_PENDING: 'text-amber-500', DOCS_REVIEW: 'text-blue-500',
            ISSUING: 'text-indigo-500', ISSUED: 'text-emerald-600', REJECTED: 'text-red-500'
        };

        let allCasesData = [];

        async function loadCases() {
            const tbody = document.getElementById('cases-body');
            tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</td></tr>';
            try {
                const res = await fetch('api/case_actions.php?action=list');
                const data = await res.json();
                if (data.ok) { allCasesData = data.data; renderCasesTable(allCasesData); }
            } catch(e) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-red-500">خطا در اتصال به سرور.</td></tr>';
            }
        }

        function renderCasesTable(list) {
            const tbody = document.getElementById('cases-body');
            if (list.length > 0) {
                tbody.innerHTML = '';
                list.forEach(c => {
                    tbody.innerHTML += `
                        <tr class="hover:bg-indigo-50/30">
                            <td class="p-4 font-mono text-indigo-600" dir="ltr">${c.unique_code}</td>
                            <td class="p-4">${c.insured_name || c.holder_name}</td>
                            <td class="p-4">${c.insurance_type === 'BODY' ? 'بدنه' : 'ثالث'}</td>
                            <td class="p-4 font-mono">${formatPlateHtml(c.plate)}</td>
                            <td class="p-4 text-xs">${e2p(c.docs_approved)}/${e2p(c.docs_total)} تایید${c.docs_rejected > 0 ? ` <span class="text-red-500">(${e2p(c.docs_rejected)} رد)</span>` : ''}</td>
                            <td class="p-4 text-xs font-bold ${CASE_STATUS_COLOR[c.status] || ''}">${CASE_STATUS_FA[c.status] || c.status}</td>
                            <td class="p-4 text-xs text-slate-500" dir="ltr">${toJalali(c.created_at, false)}</td>
                            <td class="p-4"><button onclick="openCase(${c.id}, 'issue')" class="bg-indigo-100 text-indigo-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-indigo-200">مشاهده / صدور</button></td>
                        </tr>`;
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-slate-400">پرونده‌ای مطابق فیلتر یافت نشد.</td></tr>';
            }
        }

        function applyCaseFilters() {
            const code = document.getElementById('case-search-code').value.trim().toLowerCase();
            const plate = document.getElementById('case-search-plate').value.trim().replace(/\s/g, '');
            const name = document.getElementById('case-search-name').value.trim().toLowerCase();
            const nid = document.getElementById('case-search-nid').value.trim();
            const status = document.getElementById('case-search-status').value;
            const filtered = allCasesData.filter(c => {
                if (code && !(c.unique_code || '').toLowerCase().includes(code)) return false;
                if (plate && !(c.plate || '').replace(/\s/g, '').includes(plate)) return false;
                if (name && !((c.insured_name || '') + (c.holder_name || '')).toLowerCase().includes(name)) return false;
                if (nid && !(c.insured_national_id || '').includes(nid) && !(c.holder_national_code || '').includes(nid)) return false;
                if (status && c.status !== status) return false;
                return true;
            });
            renderCasesTable(filtered);
        }
        ['case-search-code','case-search-plate','case-search-name','case-search-nid'].forEach(id => {
            document.getElementById(id).addEventListener('input', applyCaseFilters);
        });
        document.getElementById('case-search-status').addEventListener('change', applyCaseFilters);

        let currentCaseId = null;
        let currentCaseMode = 'review'; // 'review' = بررسی و تایید مدارک (تب بازدید سلامت و مدارک) | 'issue' = فقط نمایش (تب صدور بیمه‌نامه)

        // ======================= جزئیات تجمیعیِ یک معرفی‌نامه (همه‌ی بیمه‌نامه‌هایش با هم) =======================
        async function openIntroDetail(introId, name) {
            document.getElementById('intro-detail-name').textContent = name || '';
            document.getElementById('intro-detail-modal').classList.remove('hidden');
            document.getElementById('intro-detail-modal').classList.add('flex');
            const body = document.getElementById('intro-detail-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6"><i class="fas fa-spinner fa-spin"></i></p>';

            const introRow = currentRecordsData.find(r => r.introduction_id == introId) || {};

            try {
                const res = await fetch('api/case_actions.php?action=list&introduction_id=' + introId);
                const data = await res.json();
                if (!data.ok) { body.innerHTML = '<p class="text-center text-red-500 text-sm p-6">خطا در دریافت اطلاعات.</p>'; return; }

                const header = `
                    <div class="grid grid-cols-2 gap-3 text-xs bg-slate-50 rounded-xl p-4">
                        <div><span class="text-slate-400">نام:</span> <b>${introRow.full_name || '-'}</b></div>
                        <div><span class="text-slate-400">کد ملی:</span> <b dir="ltr">${e2p(introRow.national_code) || '-'}</b></div>
                        <div><span class="text-slate-400">کد پرسنلی:</span> <b dir="ltr">${e2p(introRow.personnel_code) || '-'}</b></div>
                        <div><span class="text-slate-400">شرکت:</span> <b>${introRow.company_name || '-'}</b></div>
                        <div><span class="text-slate-400">سهمیه:</span> <b>${e2p(introRow.quota_summary)}</b></div>
                        <div><span class="text-slate-400">تفکیک:</span> <b class="text-[11px]">${introRow.relationship_summary || ''}</b></div>
                    </div>`;

                const addCaseHtml = `
                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 mt-3 flex items-center gap-2">
                        <select id="intro-new-case-type" class="border rounded-lg px-2 py-1.5 text-xs flex-1">
                            <option value="THIRDPARTY">ثالث</option>
                            <option value="BODY">بدنه</option>
                        </select>
                        <button onclick="createCaseForIntro(${introId})" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg whitespace-nowrap"><i class="fas fa-plus ml-1"></i>افزودن پرونده‌ی جدید</button>
                    </div>`;

                if (data.data.length === 0) {
                    body.innerHTML = header + addCaseHtml + '<p class="text-center text-slate-400 text-sm p-6 mt-3">هنوز هیچ بیمه‌نامه‌ای برای این معرفی‌نامه ثبت نشده.</p>';
                    return;
                }

                const cardsHtml = data.data.map(c => {
                    const pendingBadge = c.docs_pending > 0 ? `<span class="text-amber-500 text-[10px]">(${e2p(c.docs_pending)} مدرک در انتظار)</span>` : '';
                    const rejectedBadge = c.docs_rejected > 0 ? `<span class="text-red-500 text-[10px]">(${e2p(c.docs_rejected)} مدرک رد‌شده)</span>` : '';
                    return `
                    <div class="border rounded-xl p-3 flex justify-between items-center">
                        <div>
                            <p class="font-bold text-sm">${insurance_type_fa_js(c.insurance_type)} — ${c.plate ? formatPlateHtml(c.plate) : 'بدون پلاک'}</p>
                            <p class="text-[10px] text-slate-400">شناسه: ${c.unique_code} — ${e2p(c.docs_approved)}/${e2p(c.docs_total)} مدرک تایید‌شده ${pendingBadge} ${rejectedBadge}</p>
                            <b class="text-xs ${CASE_STATUS_COLOR[c.status]||''}">${CASE_STATUS_FA[c.status]||c.status}</b>
                        </div>
                        <button onclick="openCase(${c.id}, 'review')" class="bg-indigo-600 text-white text-xs font-bold px-3 py-2 rounded-lg hover:bg-indigo-700 whitespace-nowrap"><i class="fas fa-arrow-left ml-1"></i>جزئیات و عملیات</button>
                    </div>`;
                }).join('');

                body.innerHTML = header + addCaseHtml + '<div class="space-y-2 mt-3">' + cardsHtml + '</div>';
            } catch (e) { body.innerHTML = '<p class="text-center text-red-500 text-sm p-6">خطا در اتصال به سرور.</p>'; }
        }
        function insurance_type_fa_js(t) { return t === 'BODY' ? 'بدنه' : 'ثالث'; }

        async function createCaseForIntro(introId) {
            const insuranceType = document.getElementById('intro-new-case-type').value;
            try {
                const res = await fetch('api/record_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'create_case_for_intro', introduction_id: introId, insurance_type: insuranceType })
                });
                const data = await res.json();
                if (data.ok) {
                    showToast('پرونده‌ی جدید ایجاد شد.', 'success');
                    openIntroDetail(introId, document.getElementById('intro-detail-name').textContent);
                    loadRecords();
                } else showToast(data.error || 'خطا در ایجاد پرونده.', 'error');
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }

        async function openCase(caseId, mode) {
            currentCaseId = caseId;
            if (mode) currentCaseMode = mode;
            document.getElementById('case-detail-modal').classList.remove('hidden');
            document.getElementById('case-detail-modal').classList.add('flex');
            const body = document.getElementById('case-detail-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm">در حال بارگذاری...</p>';
            try {
                const res = await fetch('api/case_actions.php?action=detail&case_id=' + caseId);
                const data = await res.json();
                if (!data.ok) { body.innerHTML = `<p class="text-red-500 text-sm">${data.error}</p>`; return; }
                const c = data.case;
                const isIssueMode = currentCaseMode === 'issue';
                document.getElementById('case-detail-code').innerText = c.unique_code + (isIssueMode ? ' — صدور بیمه‌نامه' : ' — بررسی مدارک');

                // در حالت «صدور»، فقط مدارکِ تایید‌شده نمایش داده می‌شود و هیچ دکمه‌ی تایید/ردی نیست
                const docsForDisplay = isIssueMode ? data.documents.filter(d => d.status === 'APPROVED') : data.documents;

                let docsHtml = docsForDisplay.map(d => {
                    const statusBadge = d.status === 'APPROVED' ? '<span class="text-emerald-600 text-xs font-bold">✅ تایید شده</span>'
                        : d.status === 'REJECTED' ? `<span class="text-red-500 text-xs font-bold">❌ رد شده - ${d.reject_reason || ''}</span>`
                        : '<span class="text-amber-500 text-xs font-bold">⏳ در انتظار بررسی</span>';
                    const isImg = /\.(jpg|jpeg|png|webp|gif)$/i.test(d.file_path || '');
                    const preview = d.file_path
                        ? (isImg ? `<img src="/${encodeFilePath(d.file_path)}" onclick="openImageZoom('/${encodeFilePath(d.file_path)}')" class="w-24 h-24 object-cover rounded-lg border cursor-zoom-in hover:opacity-80">` : `<a href="/${encodeFilePath(d.file_path)}" target="_blank" class="text-blue-600 underline text-xs">مشاهده فایل</a>`)
                        : '';
                    const actions = (!isIssueMode && d.status === 'PENDING')
                        ? `<div class="flex gap-2 mt-2">
                             <button onclick="approveDoc(${d.id})" class="bg-emerald-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-emerald-600">تایید</button>
                             <button onclick="openRejectModal(${d.id})" class="bg-red-100 text-red-600 px-3 py-1 rounded-lg text-xs hover:bg-red-200">رد</button>
                           </div>` : '';
                    return `<div class="border rounded-xl p-3 flex gap-3 items-start">
                        <input type="checkbox" class="doc-select-cb mt-2" value="${d.id}">
                        <div>${preview}</div>
                        <div class="flex-1">
                            <p class="font-bold text-sm">${d.doc_label}</p>
                            ${statusBadge}
                            <p class="text-[10px] text-slate-400 mt-1" dir="ltr">${toJalali(d.uploaded_at)}</p>
                            ${actions}
                        </div>
                    </div>`;
                }).join('') || `<p class="text-slate-400 text-sm">${isIssueMode ? 'هنوز مدرک تایید‌شده‌ای موجود نیست.' : 'هنوز موردی ارسال نشده است.'}</p>`;

                const pendingCount = isIssueMode ? 0 : data.documents.filter(d => d.status === 'PENDING').length;
                // اگر همه‌ی مدارک تایید شده‌اند (چیزی برای اصلاح نمانده)، دیگر بخش «درخواست اصلاح» معنا ندارد
                const hasIncompleteDocsInReview = !isIssueMode && data.documents.some(d => d.status !== 'APPROVED');
                const copyBtn = (val, label) => `<button onclick="copyToClipboard('${(val||'').toString().replace(/'/g,"")}', '${label}')" class="text-slate-400 hover:text-indigo-600 mr-1"><i class="fas fa-copy"></i></button>`;
                const fixFieldList = [
                    ['plate','پلاک خودرو'], ['insured_name','نام بیمه‌گذار'], ['insured_national_id','کد ملی بیمه‌گذار'],
                    ['insured_birth_date','تاریخ تولد'], ['insured_address','آدرس'], ['insured_phone','شماره موبایل'], ['insured_postal_code','کد پستی'],
                ];
                const fixFieldOptions = fixFieldList.map(([v,l]) =>
                    `<label class="flex items-center gap-1.5 text-xs bg-white border rounded-lg px-2 py-1.5 cursor-pointer hover:bg-rose-50">
                        <input type="checkbox" class="fix-field-cb" value="${v}" data-label="${l}"> ${l}
                    </label>`
                ).join('');

                // اطلاعات اختصاصیِ بیمه‌نامه‌ی درخواستی - برای ثالث فقط تعهد انتخابی، برای بدنه همه‌ی
                // فیلدهایی که کاربر طی ویزارد پر کرده (این بخش دقیقاً باید هرجای دیگر پنل که این
                // اطلاعات نشان داده می‌شود هم به همین شکل کامل باشد)
                let policyFieldsHtml = '';
                if (c.insurance_type === 'THIRDPARTY') {
                    policyFieldsHtml += `<div><span class="text-slate-400">مدرک مالکیت:</span> <b>${c.ownership_choice || '-'}</b></div>`;
                    policyFieldsHtml += c.liability_limit
                        ? `<div><span class="text-slate-400">سقف تعهد مالی:</span> <b dir="ltr">${money(c.liability_limit/10)} تومان</b></div>`
                        : `<div><span class="text-slate-400">سقف تعهد مالی:</span> <b class="text-amber-500">هنوز انتخاب نشده</b></div>`;
                } else if (c.insurance_type === 'BODY') {
                    policyFieldsHtml += `<div><span class="text-slate-400">مدرک مالکیت:</span> <b>${c.ownership_choice || '-'}</b></div>`;
                    policyFieldsHtml += `<div><span class="text-slate-400">بیمه بدنه‌ی قبلی:</span> <b>${c.prev_body_insurance || '-'}</b></div>`;
                    if (c.prev_body_insurance === 'بله') {
                        policyFieldsHtml += `<div><span class="text-slate-400">خسارت بیمه بدنه‌ی قبلی:</span> <b>${c.prev_body_claim || '-'}</b></div>`;
                    }
                    if (c.prev_body_claim === 'خیر' && c.no_claim_years !== null && c.no_claim_years !== undefined) {
                        policyFieldsHtml += `<div><span class="text-slate-400">سابقه‌ی بدون خسارت:</span> <b>${e2p(c.no_claim_years)} سال</b></div>`;
                    }
                    if (c.selected_coverages) {
                        try {
                            const cov = JSON.parse(c.selected_coverages);
                            const covLabels = data.coverage_labels || {};
                            // نمایش نام فارسیِ هر پوشش (نه کلید انگلیسی)، و اگر سطح/درصد داشت آن را هم ذکر کن
                            const labels = Object.keys(cov).map(k => {
                                const name = covLabels[k] || k;
                                const tier = cov[k];
                                return (tier && tier !== true && tier !== '1') ? `${name} (${e2p(tier)})` : name;
                            });
                            if (labels.length) policyFieldsHtml += `<div class="col-span-2"><span class="text-slate-400">پوشش‌های تکمیلی:</span> <b>${labels.join('، ')}</b></div>`;
                        } catch(e) {}
                    }
                    policyFieldsHtml += c.estimated_car_value
                        ? `<div><span class="text-slate-400">ارزش خودرو:</span> <b dir="ltr">${money(c.estimated_car_value)} تومان</b></div>`
                        : `<div><span class="text-slate-400">ارزش خودرو:</span> <b class="text-amber-500">محاسبه‌ی خودکار توسط کارشناس</b></div>`;
                }

                body.innerHTML = `
                    <div class="grid grid-cols-2 gap-2 text-[11px] bg-slate-50 rounded-xl p-4">
                        <p class="col-span-2 font-bold text-slate-500 text-xs mb-1"><i class="fas fa-user ml-1"></i>اطلاعات شخص / بیمه‌گذار</p>
                        <div><span class="text-slate-400">بیمه‌گذار:</span> <b>${c.insured_name || '-'}</b> ${copyBtn(c.insured_name,'نام بیمه‌گذار')}</div>
                        <div><span class="text-slate-400">کد ملی بیمه‌گذار:</span> <b dir="ltr">${e2p(c.insured_national_id) || '-'}</b> ${copyBtn(c.insured_national_id,'کد ملی')}</div>
                        <div><span class="text-slate-400">تاریخ تولد:</span> <b dir="ltr">${e2p(c.insured_birth_date) || '-'}</b> ${copyBtn(c.insured_birth_date,'تاریخ تولد')}</div>
                        <div><span class="text-slate-400">موبایل:</span> <b dir="ltr">${e2p(c.insured_phone) || '-'}</b> ${copyBtn(c.insured_phone,'موبایل')}</div>
                        <div><span class="text-slate-400">آدرس:</span> <b>${c.insured_address || '-'}</b> ${copyBtn(c.insured_address,'آدرس')}</div>
                        <div><span class="text-slate-400">کد پستی:</span> <b dir="ltr">${e2p(c.insured_postal_code) || '-'}</b> ${copyBtn(c.insured_postal_code,'کد پستی')}</div>
                        <div><span class="text-slate-400">دارنده معرفی‌نامه:</span> <b>${c.holder_name}</b></div>
                        <div><span class="text-slate-400">شرکت:</span> <b>${c.company_name || '-'}</b></div>
                        <hr class="col-span-2 my-1 border-slate-200">
                        <p class="col-span-2 font-bold text-slate-500 text-xs mb-1"><i class="fas fa-file-shield ml-1"></i>اطلاعات بیمه‌نامه‌ی درخواستی</p>
                        <div><span class="text-slate-400">نوع بیمه:</span> <b>${insurance_type_fa_js(c.insurance_type)}</b></div>
                        <div><span class="text-slate-400">پلاک:</span> <b dir="ltr">${c.plate ? formatPlateHtml(c.plate) : '-'}</b> ${copyBtn(c.plate,'پلاک')}</div>
                        ${policyFieldsHtml}
                        <div><span class="text-slate-400">وضعیت پرونده:</span> <b class="${CASE_STATUS_COLOR[c.status]||''}">${CASE_STATUS_FA[c.status]||c.status}</b></div>
                        <div><span class="text-slate-400">سهمیه معرفی‌نامه:</span> <b>${e2p(c.used_quota)}/${e2p(c.max_quota)}</b></div>
                    </div>

                    <div class="flex gap-2">
                        <button onclick="openUserChat(${c.person_id}, '${(c.holder_name||'').replace(/'/g,"")}')" class="flex-1 bg-blue-50 text-blue-700 text-xs font-bold py-2 rounded-lg hover:bg-blue-100"><i class="fas fa-comment-dots ml-1"></i>گفتگو با کاربر</button>
                        <a href="api/case_actions.php?action=download_docs_zip&case_id=${caseId}" class="flex-1 text-center bg-slate-100 text-slate-700 text-xs font-bold py-2 rounded-lg hover:bg-slate-200"><i class="fas fa-file-zipper ml-1"></i>دانلود همه مدارک (زیپ)</a>
                        <button onclick="downloadSelectedDocs(${caseId})" class="flex-1 bg-slate-100 text-slate-700 text-xs font-bold py-2 rounded-lg hover:bg-slate-200"><i class="fas fa-check-square ml-1"></i>دانلود انتخابی‌ها</button>
                    </div>

                    ${hasIncompleteDocsInReview ? `
                    <div class="bg-rose-50 border border-rose-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-rose-700 mb-2"><i class="fas fa-triangle-exclamation ml-1"></i> درخواست اصلاح از کاربر (چند مورد قابل انتخاب است)</p>
                        <div class="grid grid-cols-2 gap-2 mb-2">${fixFieldOptions}</div>
                        <input type="text" id="fix-note-input" placeholder="توضیح (اختیاری)" class="border rounded-lg px-2 py-1.5 text-xs w-full mb-2">
                        <button onclick="requestFix(${caseId})" class="bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold px-4 py-1.5 rounded-lg">ارسال درخواست اصلاح به کاربر</button>
                        ${c.last_status_note ? `<p class="text-[10px] text-slate-500 mt-2">آخرین یادداشت: ${c.last_status_note}</p>` : ''}
                    </div>` : ''}

                    ${isIssueMode ? `
                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-amber-700 mb-3"><i class="fas fa-pen ml-1"></i> فیلدهای نام‌گذاری (اختیاری، دستی) - اگر خالی بماند بعد از دریافت فایل بیمه‌نامه به‌صورت خودکار تکمیل می‌شود</p>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" id="naming-plate" placeholder="پلاک" value="${c.plate || ''}" class="border rounded-lg px-2 py-1.5 text-xs plate-display" dir="ltr">
                            <input type="text" id="naming-insured-name" placeholder="نام بیمه‌گذار" value="${c.insured_name || ''}" class="border rounded-lg px-2 py-1.5 text-xs">
                        </div>
                        <button onclick="saveNaming()" class="mt-2 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold px-4 py-1.5 rounded-lg">ذخیره</button>
                    </div>

                    <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4" id="final-policy-section">
                        <p class="text-xs font-bold text-emerald-700 mb-3"><i class="fas fa-file-circle-check ml-1"></i> صدور نهایی بیمه‌نامه</p>
                        ${c.status === 'ISSUED' ? `
                            <p class="text-xs text-emerald-700 font-bold mb-1">✅ این پرونده صادر شده است.</p>
                            <div class="text-[11px] text-slate-500 space-y-1">
                                ${c.policy_number ? `<div>شماره بیمه‌نامه: <b dir="ltr">${e2p(c.policy_number)}</b> ${copyBtn(c.policy_number,'شماره بیمه‌نامه')}</div>` : ''}
                                ${c.vin ? `<div>VIN: <b dir="ltr">${e2p(c.vin)}</b> ${copyBtn(c.vin,'VIN')}</div>` : ''}
                                ${c.chassis_num ? `<div>شماره شاسی: <b dir="ltr">${e2p(c.chassis_num)}</b> ${copyBtn(c.chassis_num,'شماره شاسی')}</div>` : ''}
                                ${c.engine_num ? `<div>شماره موتور: <b dir="ltr">${e2p(c.engine_num)}</b> ${copyBtn(c.engine_num,'شماره موتور')}</div>` : ''}
                                ${c.total_premium ? `<div>حق بیمه کل: <b dir="ltr">${money(c.total_premium)} ریال</b></div>` : ''}
                                ${c.central_unique_code ? `<div>کد یکتا بیمه مرکزی: <b dir="ltr">${e2p(c.central_unique_code)}</b> ${copyBtn(c.central_unique_code,'کد یکتا')}</div>` : ''}
                                ${c.car_system ? `<div>سیستم/تیپ: <b>${c.car_system}${c.car_type && c.car_type !== c.car_system ? ' / '+c.car_type : ''}</b></div>` : ''}
                                ${c.car_model_year ? `<div>مدل: <b dir="ltr">${e2p(c.car_model_year)}</b></div>` : ''}
                                ${c.car_color ? `<div>رنگ: <b>${c.car_color}</b></div>` : ''}
                                ${c.car_usage ? `<div>مورد استفاده: <b>${c.car_usage}</b></div>` : ''}
                                ${c.car_value ? `<div>ارزش خودرو: <b dir="ltr">${money(c.car_value)} ریال</b></div>` : ''}
                                ${c.policy_issue_date ? `<div>تاریخ صدور: <b dir="ltr">${e2p(c.policy_issue_date)}</b></div>` : ''}
                            </div>
                            ${c.issued_file_path ? `<a href="/${encodeFilePath(c.issued_file_path)}" target="_blank" class="inline-block mt-2 text-blue-600 underline text-xs">مشاهده فایل بیمه‌نامه‌ی صادرشده</a>` : ''}
                        ` : `
                            <label class="block border-2 border-dashed border-emerald-300 rounded-lg text-center p-3 text-xs text-emerald-700 cursor-pointer hover:bg-emerald-100" id="policy-upload-label">
                                <i class="fas fa-cloud-arrow-up ml-1"></i> انتخاب فایل بیمه‌نامه (PDF) برای شناسایی خودکار
                                <input type="file" id="final-policy-file" class="hidden" onchange="ocrPreviewPolicy(${caseId})">
                            </label>
                            <div id="policy-ocr-status" class="text-[11px] text-slate-400 mt-2"></div>

                            <div id="policy-fields-wrap" class="mt-3" style="display:none;">
                                <div class="grid grid-cols-2 gap-2 mb-2">
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">نوع بیمه (تشخیص‌داده‌شده)</label>
                                        <input type="text" id="final-ins-type" readonly class="border rounded-lg px-2 py-1.5 text-xs w-full bg-slate-100" dir="rtl">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">پلاک (تشخیص‌داده‌شده)</label>
                                        <input type="text" id="final-plate" readonly class="border rounded-lg px-2 py-1.5 text-xs w-full bg-slate-100" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">نام بیمه‌گذار (تشخیص‌داده‌شده)</label>
                                        <input type="text" id="final-insured-name" readonly class="border rounded-lg px-2 py-1.5 text-xs w-full bg-slate-100" dir="rtl">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">شماره بیمه‌نامه</label>
                                        <input type="text" id="final-policy-number" placeholder="شماره بیمه‌نامه" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">VIN</label>
                                        <input type="text" id="final-policy-vin" placeholder="VIN" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">شماره شاسی</label>
                                        <input type="text" id="final-chassis-num" placeholder="شماره شاسی" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">شماره موتور</label>
                                        <input type="text" id="final-engine-num" placeholder="شماره موتور" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">کد یکتا بیمه مرکزی</label>
                                        <input type="text" id="final-unique-code" placeholder="کد یکتا" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div class="col-span-2">
                                        <label class="text-[10px] text-slate-500 block mb-1">حق بیمه کل (ریال)</label>
                                        <input type="text" id="final-total-premium" placeholder="حق بیمه کل" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">سیستم خودرو</label>
                                        <input type="text" id="final-car-system" placeholder="سیستم" class="border rounded-lg px-2 py-1.5 text-xs w-full">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">تیپ</label>
                                        <input type="text" id="final-car-type" placeholder="تیپ" class="border rounded-lg px-2 py-1.5 text-xs w-full">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">مدل (سال ساخت)</label>
                                        <input type="text" id="final-car-model-year" placeholder="مدل" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">رنگ</label>
                                        <input type="text" id="final-car-color" placeholder="رنگ" class="border rounded-lg px-2 py-1.5 text-xs w-full">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">مورد استفاده</label>
                                        <input type="text" id="final-car-usage" placeholder="مورد استفاده" class="border rounded-lg px-2 py-1.5 text-xs w-full">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">تلفن (از بیمه‌نامه)</label>
                                        <input type="text" id="final-ocr-phone" placeholder="تلفن" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">تاریخ صدور</label>
                                        <input type="text" id="final-issue-date" placeholder="تاریخ صدور" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                    <div>
                                        <label class="text-[10px] text-slate-500 block mb-1">ارزش خودرو (ریال) - بدنه</label>
                                        <input type="text" id="final-car-value" placeholder="ارزش خودرو" class="border rounded-lg px-2 py-1.5 text-xs w-full" dir="ltr">
                                    </div>
                                </div>
                                <div id="policy-mismatch-warning" class="hidden text-[11px] text-amber-600 bg-amber-50 border border-amber-200 rounded-lg p-2 mb-2"></div>
                                <button onclick="confirmIssuePolicy(${caseId})" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2.5 rounded-lg"><i class="fas fa-check-circle ml-1"></i>تایید نهایی و صدور</button>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-2">پیش‌نیاز: همه‌ی مدارک باید تایید شده باشند؛ برای بیمه‌ی بدنه، گزارش کارشناسِ بازدید سلامت هم باید قبلاً بارگذاری شده باشد.</p>

                        `}
                    </div>` : ''}

                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <p class="text-xs font-bold text-slate-600">${isIssueMode ? 'مدارک تایید‌شده‌ی این پرونده:' : 'مدارک این پرونده:'}</p>
                            ${pendingCount > 1 ? `<button onclick="approveAllDocs(${caseId})" class="bg-emerald-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg hover:bg-emerald-700"><i class="fas fa-check-double ml-1"></i>تایید گروهی همه (${e2p(pendingCount)})</button>` : ''}
                        </div>
                        <div class="space-y-2">${docsHtml}</div>
                    </div>

                    ${!isIssueMode ? `
                    <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-indigo-700 mb-3"><i class="fas fa-user-pen ml-1"></i>تکمیل/ویرایش دستیِ اطلاعات (برای پرونده‌های ثبتِ دستی - چون خودمان وارد می‌کنیم)</p>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" id="adm-plate" placeholder="پلاک" value="${c.plate || ''}" class="border rounded-lg px-2 py-1.5 text-xs plate-display" dir="ltr">
                            <input type="text" id="adm-insured-name" placeholder="نام بیمه‌گذار" value="${c.insured_name || ''}" class="border rounded-lg px-2 py-1.5 text-xs">
                            <input type="text" id="adm-insured-nid" placeholder="کد ملی بیمه‌گذار" value="${c.insured_national_id || ''}" class="border rounded-lg px-2 py-1.5 text-xs" dir="ltr">
                            <select id="adm-ownership" class="border rounded-lg px-2 py-1.5 text-xs">
                                <option value="">مدرک مالکیت؟</option>
                                <option value="کارت ماشین" ${c.ownership_choice === 'کارت ماشین' ? 'selected' : ''}>کارت ماشین</option>
                                <option value="سند" ${c.ownership_choice === 'سند' ? 'selected' : ''}>سند</option>
                            </select>
                            ${c.insurance_type === 'BODY' ? `
                            <select id="adm-prev-body" class="border rounded-lg px-2 py-1.5 text-xs col-span-2">
                                <option value="">بیمه بدنه‌ی قبلی داشته؟</option>
                                <option value="بله" ${c.prev_body_insurance === 'بله' ? 'selected' : ''}>بله</option>
                                <option value="خیر" ${c.prev_body_insurance === 'خیر' ? 'selected' : ''}>خیر</option>
                            </select>` : ''}
                        </div>
                        <button onclick="saveAdminCaseInfo(${caseId})" class="mt-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-4 py-1.5 rounded-lg">ذخیره اطلاعات</button>
                    </div>

                    <div class="bg-teal-50 border border-teal-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-teal-700 mb-3"><i class="fas fa-cloud-arrow-up ml-1"></i>بارگذاری مستقیمِ مدرک توسط خودمان (نیازی به تاییدِ جداگانه ندارد)</p>
                        <div class="grid grid-cols-1 gap-2">
                            <select id="adm-doc-key" class="border rounded-lg px-2 py-1.5 text-xs">
                                ${Object.entries(data.required_docs || {}).map(([k, l]) => `<option value="${k}">${l}</option>`).join('') || ''}
                                <option value="other">سایر مدارک</option>
                            </select>
                            <input type="file" id="adm-doc-file" class="border rounded-lg px-2 py-1.5 text-xs">
                        </div>
                        <button onclick="uploadAdminCaseDoc(${caseId})" class="mt-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold px-4 py-1.5 rounded-lg">بارگذاری و تایید خودکار</button>
                    </div>` : ''}
                `;
            } catch(e) { body.innerHTML = '<p class="text-red-500 text-sm">خطا در دریافت اطلاعات پرونده.</p>'; }
        }

        async function ocrPreviewPolicy(caseId) {
            const input = document.getElementById('final-policy-file');
            const file = input.files[0];
            if (!file) return;
            const statusEl = document.getElementById('policy-ocr-status');
            statusEl.textContent = '⏳ در حال شناسایی اطلاعات فایل...';
            document.getElementById('policy-upload-label').style.pointerEvents = 'none';
            const fd = new FormData();
            fd.append('action', 'ocr_preview_policy');
            fd.append('case_id', caseId);
            fd.append('file', file);
            try {
                const res = await fetch('api/case_actions.php', { method: 'POST', body: fd });
                const data = await res.json();
                document.getElementById('policy-upload-label').style.pointerEvents = '';
                if (!data.ok) { statusEl.textContent = ''; showToast(data.error || 'خطا در پردازش فایل.', 'error'); return; }

                document.getElementById('policy-fields-wrap').style.display = 'block';
                const o = data.ocr || {};
                if (data.ocr_used) {
                    statusEl.innerHTML = '✅ اطلاعات فایل شناسایی شد - لطفاً بررسی/ویرایش کنید، سپس تایید نهایی بزنید.';
                    document.getElementById('final-ins-type').value = `${o.ins_type || '-'} (${o.ins_company || '-'})`;
                    document.getElementById('final-plate').value = o.plate || '';
                    document.getElementById('final-insured-name').value = o.insured_name || '';
                    document.getElementById('final-policy-number').value = o.policy_num || '';
                    document.getElementById('final-policy-vin').value = o.vin || '';
                    document.getElementById('final-chassis-num').value = o.chassis_num || '';
                    document.getElementById('final-engine-num').value = o.engine_num || '';
                    document.getElementById('final-unique-code').value = o.unique_code || '';
                    document.getElementById('final-total-premium').value = o.total_premium || '';
                    document.getElementById('final-car-system').value = o.car_system || '';
                    document.getElementById('final-car-type').value = o.car_type || '';
                    document.getElementById('final-car-model-year').value = o.model_year || '';
                    document.getElementById('final-car-color').value = o.car_color || '';
                    document.getElementById('final-car-usage').value = o.car_usage || '';
                    document.getElementById('final-ocr-phone').value = o.phone || '';
                    document.getElementById('final-issue-date').value = o.issue_date || '';
                    document.getElementById('final-car-value').value = o.car_value || '';
                } else {
                    statusEl.innerHTML = `⚠️ فایل شناسایی نشد - فیلدها را دستی پر کنید.<br><span class="text-[10px] text-slate-400">علت: ${data.ocr_debug || 'نامشخص'}</span>`;
                    document.getElementById('final-ins-type').value = 'شناسایی نشد';
                }
            } catch(e) {
                document.getElementById('policy-upload-label').style.pointerEvents = '';
                statusEl.textContent = '';
                showToast('خطا در اتصال به سرور.', 'error');
            }
        }

        async function confirmIssuePolicy(caseId) {
            const body = {
                action: 'confirm_issue_policy', case_id: caseId,
                policy_number: document.getElementById('final-policy-number').value.trim(),
                vin: document.getElementById('final-policy-vin').value.trim(),
                chassis_num: document.getElementById('final-chassis-num').value.trim(),
                engine_num: document.getElementById('final-engine-num').value.trim(),
                unique_code: document.getElementById('final-unique-code').value.trim(),
                total_premium: document.getElementById('final-total-premium').value.trim(),
                car_system: document.getElementById('final-car-system').value.trim(),
                car_type: document.getElementById('final-car-type').value.trim(),
                model_year: document.getElementById('final-car-model-year').value.trim(),
                car_color: document.getElementById('final-car-color').value.trim(),
                car_usage: document.getElementById('final-car-usage').value.trim(),
                phone: document.getElementById('final-ocr-phone').value.trim(),
                issue_date: document.getElementById('final-issue-date').value.trim(),
                car_value: document.getElementById('final-car-value').value.trim(),
            };
            showToast('در حال بررسی نهایی و صدور...', 'info');
            try {
                const res = await fetch('api/case_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
                });
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا در صدور.', 'error'); return; }
                if (data.name_mismatch) showToast('⚠️ توجه: کد ملی استخراج‌شده با پرونده یکی نبود، لطفاً بعداً بررسی کنید.', 'error');
                showToast('بیمه‌نامه با موفقیت صادر شد.', 'success');
                openCase(caseId);
                loadCases();
            } catch(e) { showToast('خطا در اتصال به سرور.', 'error'); }
        }

        function copyToClipboard(val, label) {
            if (!val) return;
            navigator.clipboard.writeText(val).then(() => showToast(label + ' کپی شد.', 'success'));
        }

        function downloadSelectedDocs(caseId) {
            const ids = Array.from(document.querySelectorAll('.doc-select-cb:checked')).map(cb => cb.value);
            if (ids.length === 0) { showToast('هیچ موردی انتخاب نشده.', 'error'); return; }
            window.open('api/case_actions.php?action=download_docs_zip&case_id=' + caseId + '&doc_ids=' + ids.join(','), '_blank');
        }

        async function requestFix(caseId) {
            const checked = Array.from(document.querySelectorAll('.fix-field-cb:checked'));
            if (checked.length === 0) { showToast('حداقل یک مورد را انتخاب کنید.', 'error'); return; }
            const fields = checked.map(cb => cb.value);
            const note = document.getElementById('fix-note-input').value.trim();
            await fetch('api/case_actions.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'request_fix', case_id: caseId, fields, note })
            });
            showToast('درخواست اصلاح برای کاربر ارسال شد.', 'success');
            openCase(caseId);
            loadCases();
            loadDocsReviewList();
        }

        async function approveAllDocs(caseId) {
            showConfirm('تایید گروهی', 'همه‌ی مدارکِ در انتظار این پرونده تایید شوند؟', async () => {
                await fetch('api/case_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'approve_all_docs', case_id: caseId })
                });
                showToast('همه‌ی مدارک تایید شدند.', 'success');
                openCase(caseId);
                loadCases();
                loadDocsReviewList();
            });
        }

        function openImageZoom(src) {
            document.getElementById('image-zoom-content').src = src;
            document.getElementById('image-zoom-modal').classList.remove('hidden');
            document.getElementById('image-zoom-modal').classList.add('flex');
            resetImageZoom();
        }
        function closeImageZoom() { document.getElementById('image-zoom-modal').classList.add('hidden'); }

        // ======================= زوم/جابه‌جاییِ تصویر در حالت بزرگ‌نمایی =======================
        let imgZoomScale = 1, imgZoomX = 0, imgZoomY = 0, imgZoomDragging = false, imgZoomDragStart = null;
        function applyImageZoomTransform() {
            const img = document.getElementById('image-zoom-content');
            img.style.transform = `translate(${imgZoomX}px, ${imgZoomY}px) scale(${imgZoomScale})`;
            img.style.cursor = imgZoomScale > 1 ? 'grab' : 'zoom-in';
        }
        function resetImageZoom() { imgZoomScale = 1; imgZoomX = 0; imgZoomY = 0; applyImageZoomTransform(); }
        function zoomImage(delta) {
            imgZoomScale = Math.min(5, Math.max(1, imgZoomScale + delta));
            if (imgZoomScale === 1) { imgZoomX = 0; imgZoomY = 0; }
            applyImageZoomTransform();
        }
        function onImageZoomWheel(ev) {
            ev.preventDefault();
            zoomImage(ev.deltaY < 0 ? 0.25 : -0.25);
        }
        function onImageZoomDragStart(ev) {
            if (imgZoomScale <= 1) return;
            ev.preventDefault();
            imgZoomDragging = true;
            imgZoomDragStart = { x: ev.clientX - imgZoomX, y: ev.clientY - imgZoomY };
            document.getElementById('image-zoom-content').style.cursor = 'grabbing';
        }
        document.addEventListener('mousemove', (ev) => {
            if (!imgZoomDragging) return;
            imgZoomX = ev.clientX - imgZoomDragStart.x;
            imgZoomY = ev.clientY - imgZoomDragStart.y;
            applyImageZoomTransform();
        });
        document.addEventListener('mouseup', () => {
            imgZoomDragging = false;
            const img = document.getElementById('image-zoom-content');
            if (img) img.style.cursor = imgZoomScale > 1 ? 'grab' : 'zoom-in';
        });

        async function approveDoc(docId) {
            await fetch('api/case_actions.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'approve_doc', doc_id: docId })
            });
            showToast('مدرک تایید شد.', 'success');
            openCase(currentCaseId);
            loadCases();
            loadDocsReviewList();
        }

        function openRejectModal(docId) {
            document.getElementById('reject-doc-id').value = docId;
            document.getElementById('reject-doc-type').value = 'doc';
            document.getElementById('reject-modal-title').innerHTML = '<i class="fas fa-times-circle ml-1"></i> دلیل رد مدرک';
            document.getElementById('reject-reason-input').value = '';
            document.getElementById('reject-doc-modal').classList.remove('hidden');
            document.getElementById('reject-doc-modal').classList.add('flex');
        }

        function openRejectHealthModal(id) {
            document.getElementById('reject-doc-id').value = id;
            document.getElementById('reject-doc-type').value = 'health';
            document.getElementById('reject-modal-title').innerHTML = '<i class="fas fa-times-circle ml-1"></i> دلیل رد بازدید سلامت';
            document.getElementById('reject-reason-input').value = '';
            document.getElementById('reject-doc-modal').classList.remove('hidden');
            document.getElementById('reject-doc-modal').classList.add('flex');
        }

        async function confirmRejectGeneric() {
            const id = document.getElementById('reject-doc-id').value;
            const type = document.getElementById('reject-doc-type').value;
            const reason = document.getElementById('reject-reason-input').value.trim();
            if (!reason) { showToast('لطفاً دلیل رد را بنویسید.', 'error'); return; }
            document.getElementById('reject-doc-modal').classList.add('hidden');

            if (type === 'doc') {
                await fetch('api/case_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'reject_doc', doc_id: id, reason })
                });
                showToast('مدرک رد شد و به کاربر اطلاع داده شد.', 'success');
                openCase(currentCaseId);
                loadCases();
                loadDocsReviewList();
            } else {
                await fetch('api/case_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'reject_health', id, reason })
                });
                showToast('بازدید رد شد و به کاربر اطلاع داده شد.', 'success');
                loadHealthTab();
            }
        }

        async function saveNaming() {
            const plate = document.getElementById('naming-plate').value.trim();
            const insuredName = document.getElementById('naming-insured-name').value.trim();
            await fetch('api/case_actions.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'set_naming', case_id: currentCaseId, plate, insured_name: insuredName })
            });
            showToast('فیلدهای نام‌گذاری ذخیره شد.', 'success');
            loadCases();
        }

        async function saveAdminCaseInfo(caseId) {
            const payload = {
                action: 'set_naming', case_id: caseId,
                plate: document.getElementById('adm-plate').value.trim(),
                insured_name: document.getElementById('adm-insured-name').value.trim(),
                insured_national_id: document.getElementById('adm-insured-nid').value.trim(),
                ownership_choice: document.getElementById('adm-ownership').value,
            };
            const prevBodyEl = document.getElementById('adm-prev-body');
            if (prevBodyEl) payload.prev_body_insurance = prevBodyEl.value;
            try {
                const res = await fetch('api/case_actions.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
                const data = await res.json();
                if (data.ok) { showToast('اطلاعات ذخیره شد.', 'success'); openCase(caseId); loadCases(); }
                else showToast(data.error || 'خطا', 'error');
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }

        async function uploadAdminCaseDoc(caseId) {
            const fileInput = document.getElementById('adm-doc-file');
            if (!fileInput.files[0]) { showToast('یک فایل انتخاب کنید.', 'error'); return; }
            const docKeySel = document.getElementById('adm-doc-key');
            const fd = new FormData();
            fd.append('action', 'admin_upload_case_doc');
            fd.append('case_id', caseId);
            fd.append('doc_key', docKeySel.value);
            fd.append('doc_label', docKeySel.options[docKeySel.selectedIndex].textContent);
            fd.append('file', fileInput.files[0]);
            try {
                const res = await fetch('api/case_actions.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) { showToast('مدرک بارگذاری و تایید شد.', 'success'); openCase(caseId); loadCases(); }
                else showToast(data.error || 'خطا', 'error');
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }

        // ======================= بازدیدهای سلامت خودرو =======================
        // فهرست پرونده‌هایی که مدرکِ در انتظار بررسی یا رد‌شده دارند - این‌جا (نه در تب صدور) تایید/رد می‌شوند
        async function loadDocsReviewList() {
            const box = document.getElementById('docs-review-body');
            box.innerHTML = '<p class="text-center text-slate-400 text-sm p-4"><i class="fas fa-spinner fa-spin"></i></p>';
            try {
                const res = await fetch('api/case_actions.php?action=list');
                const data = await res.json();
                if (!data.ok) { box.innerHTML = '<p class="text-center text-red-500 text-sm p-4">خطا در اتصال به سرور.</p>'; return; }
                const needsReview = data.data.filter(c => (c.docs_pending > 0 || c.docs_rejected > 0) && c.status !== 'ISSUED');
                if (needsReview.length === 0) { box.innerHTML = '<p class="text-center text-slate-400 text-sm p-4">مدرکی در انتظار بررسی نیست.</p>'; return; }
                box.innerHTML = needsReview.map(c => {
                    const pendingBadge = c.docs_pending > 0 ? `<span class="text-amber-600 text-[10px] font-bold">${e2p(c.docs_pending)} در انتظار</span>` : '';
                    const rejectedBadge = c.docs_rejected > 0 ? `<span class="text-red-500 text-[10px] font-bold">${e2p(c.docs_rejected)} نیاز به اصلاح کاربر</span>` : '';
                    return `<div class="border rounded-xl p-3 flex justify-between items-center">
                        <div>
                            <p class="font-bold text-xs">${c.insured_name || c.holder_name} — ${insurance_type_fa_js(c.insurance_type)} — <span dir="ltr">${formatPlateHtml(c.plate)}</span></p>
                            <p class="text-[10px] text-slate-400 mt-1" dir="ltr">${c.unique_code}</p>
                            <div class="flex gap-2 mt-1">${pendingBadge}${rejectedBadge}</div>
                        </div>
                        <button onclick="openCase(${c.id}, 'review')" class="bg-indigo-600 text-white text-xs font-bold px-3 py-2 rounded-lg hover:bg-indigo-700 whitespace-nowrap"><i class="fas fa-file-lines ml-1"></i>بررسی مدارک</button>
                    </div>`;
                }).join('');
            } catch(e) { box.innerHTML = '<p class="text-center text-red-500 text-sm p-4">خطا در اتصال به سرور.</p>'; }
        }

        async function loadHealthTab() {
            const body = document.getElementById('health-tab-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm p-8"><i class="fas fa-spinner fa-spin"></i></p>';
            try {
                const res = await fetch('api/case_actions.php?action=health_list');
                const data = await res.json();
                if (!data.ok || data.data.length === 0) { body.innerHTML = '<p class="text-center text-slate-400 text-sm p-8">بازدیدی ثبت نشده است.</p>'; return; }
                const stepLabels = {'1':'نمای جلو','2':'جلو راست ۴۵','3':'جلو چپ ۴۵','4':'نمای عقب','5':'عقب راست ۴۵','6':'عقب چپ ۴۵','7':'کاپوت باز','8':'صندوق باز','9':'داخل کابین','10':'سقف','11':'لاستیک زاپاس','12':'شماره شاسی','13':'سانروف','14':'آسیب‌دیدگی'};
                body.innerHTML = data.data.map(h => {
                    const photos = JSON.parse(h.photos || '{}');
                    const photosHtml = Object.entries(photos).map(([step, p]) => {
                        const stepKey = step.split('-')[0];
                        return `<div class="text-center"><img src="/${encodeFilePath(p)}" onclick="openImageZoom('/${encodeFilePath(p)}')" class="w-16 h-16 object-cover rounded-lg border cursor-zoom-in"><p class="text-[9px] text-slate-400">${stepLabels[stepKey]||step}</p></div>`;
                    }).join('');
                    const statusBadge = h.status === 'APPROVED' ? '<span class="text-emerald-600 text-xs font-bold">✅ تایید شده</span>'
                        : h.status === 'REJECTED' ? `<span class="text-red-500 text-xs font-bold">❌ رد شده - ${h.reject_reason||''}</span>`
                        : '<span class="text-amber-500 text-xs font-bold">⏳ در انتظار بررسی</span>';
                    const actions = h.status === 'PENDING'
                        ? `<div class="flex gap-2 mt-2">
                             <button onclick="approveHealth(${h.id})" class="bg-emerald-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-emerald-600">تایید</button>
                             <button onclick="rejectHealth(${h.id})" class="bg-red-100 text-red-600 px-3 py-1 rounded-lg text-xs hover:bg-red-200">رد</button>
                           </div>` : '';
                    const reportHtml = h.status !== 'APPROVED' ? '' : (h.report_file_path
                        ? `<a href="/${h.report_file_path}" target="_blank" class="text-blue-600 underline text-xs">مشاهده گزارش کارشناس</a>`
                        : `<label class="text-xs text-white bg-blue-600 hover:bg-blue-700 px-3 py-1.5 rounded-lg cursor-pointer font-bold">📄 بارگذاری گزارش کارشناس (برای رفتن به مرحله‌ی صدور لازم است)<input type="file" class="hidden" onchange="uploadHealthReport(${h.id}, this)"></label>`);
                    return `<div class="card border-rose-100 p-4">
                        <div class="flex justify-between items-start">
                            <p class="font-bold text-sm">${h.holder_name} — ${h.plate ? formatPlateHtml(h.plate) : h.unique_code} — بازدید شماره ${e2p(h.inspection_number)}</p>
                            <a href="api/case_actions.php?action=download_health_zip&inspection_id=${h.id}" class="bg-rose-50 text-rose-600 px-3 py-1 rounded-lg text-xs font-bold hover:bg-rose-100"><i class="fas fa-file-zipper ml-1"></i>دانلود زیپ</a>
                        </div>
                        <div class="flex gap-2 flex-wrap my-3">${photosHtml}</div>
                        <div class="flex justify-between items-center">
                            ${statusBadge}
                            ${reportHtml}
                        </div>
                        ${actions}
                    </div>`;
                }).join('');
            } catch(e) { body.innerHTML = '<p class="text-center text-red-500 text-sm p-8">خطا در دریافت اطلاعات.</p>'; }
        }

        async function uploadHealthReport(inspectionId, input) {
            if (!input.files[0]) return;
            const fd = new FormData();
            fd.append('action', 'upload_health_report');
            fd.append('inspection_id', inspectionId);
            fd.append('file', input.files[0]);
            await fetch('api/case_actions.php', { method: 'POST', body: fd });
            showToast('گزارش آپلود شد.', 'success');
            loadHealthTab();
        }

        async function approveHealth(id) {
            await fetch('api/case_actions.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'approve_health', id }) });
            showToast('بازدید تایید شد.', 'success');
            loadHealthTab();
        }

        function rejectHealth(id) {
            openRejectHealthModal(id);
        }

        // ======================= ورود دومرحله‌ای =======================
        async function loadOtpSettings() {
            try {
                const res = await fetch('api/settings_actions.php?action=get_otp');
                const d = await res.json();
                if (!d.ok) return;
                document.getElementById('otp-enabled').checked = d.settings.otp_enabled === '1';
                document.getElementById('safir-key').value  = d.settings.safir_api_key || '';
                document.getElementById('safir-bot').value  = d.settings.safir_bot_id || '';
                document.getElementById('otp-ttl').value    = d.settings.otp_ttl_seconds || 120;
                document.getElementById('otp-attempts').value = d.settings.otp_max_attempts || 5;

                document.getElementById('otp-users').innerHTML = d.users.map(u => `
                    <div class="border rounded-lg p-2.5 flex items-center gap-2">
                        <div class="flex-1">
                            <p class="text-[11.5px] font-bold">${u.full_name || u.username}</p>
                            <p class="text-[10px] text-slate-400" dir="ltr">${u.role}</p>
                        </div>
                        <input type="text" value="${u.mobile_number || ''}" placeholder="09..."
                               onchange="saveUserOtp(${u.id}, this.value, null)"
                               class="border rounded-lg px-2 py-1 text-[11px] w-28" dir="ltr">
                        <label class="relative inline-flex items-center cursor-pointer shrink-0" title="ورود دومرحله‌ای این کاربر">
                            <input type="checkbox" ${u.otp_enabled == 1 ? 'checked' : ''}
                                   onchange="saveUserOtp(${u.id}, null, this.checked)" class="sr-only peer">
                            <div class="w-9 h-4.5 bg-slate-200 peer-checked:bg-blue-500 rounded-full transition-all" style="height:18px;width:34px;"></div>
                            <div class="absolute right-0.5 top-0.5 w-3.5 h-3.5 bg-white rounded-full transition-all peer-checked:-translate-x-4"></div>
                        </label>
                    </div>`).join('') || '<p class="text-[11px] text-slate-400">کاربری یافت نشد.</p>';
            } catch(e) {}
        }

        async function saveOtpSettings() {
            try {
                const res = await fetch('api/settings_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'save_otp',
                        otp_enabled: document.getElementById('otp-enabled').checked,
                        safir_api_key: document.getElementById('safir-key').value.trim(),
                        safir_bot_id: document.getElementById('safir-bot').value.trim(),
                        otp_ttl_seconds: document.getElementById('otp-ttl').value,
                        otp_max_attempts: document.getElementById('otp-attempts').value })
                });
                const d = await res.json();
                showToast(d.ok ? 'تنظیمات ذخیره شد.' : (d.error || 'خطا'), d.ok ? 'success' : 'error');
            } catch(e) { showToast('خطا در ذخیره‌سازی', 'error'); }
        }

        async function saveUserOtp(userId, mobile, enabled) {
            try {
                const body = { action: 'save_user_otp', user_id: userId };
                if (mobile !== null) body.mobile_number = mobile;
                if (enabled !== null) body.otp_enabled = enabled;
                const res = await fetch('api/settings_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body) });
                const d = await res.json();
                showToast(d.ok ? 'ذخیره شد.' : (d.error || 'خطا'), d.ok ? 'success' : 'error');
                if (!d.ok) loadOtpSettings();
            } catch(e) { showToast('خطا در ذخیره‌سازی', 'error'); }
        }

        async function testOtp() {
            try {
                const res = await fetch('api/settings_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'test_otp' }) });
                const d = await res.json();
                if (d.ok) alert('کد آزمایشی به شماره ' + d.masked + ' ارسال شد.');
                else showToast(d.error || 'خطا در ارسال', 'error');
            } catch(e) { showToast('خطا در اتصال', 'error'); }
        }

        async function loadQuotaSetting() {
            try {
                const res = await fetch('api/settings_actions.php?action=get_quota');
                const data = await res.json();
                if (data.ok) document.getElementById('quota-input').value = data.quota;
            } catch(e) {}
            try {
                const res2 = await fetch('api/settings_actions.php?action=get_site_url');
                const data2 = await res2.json();
                if (data2.ok) document.getElementById('site-url-input').value = data2.site_url;
            } catch(e) {}
            try {
                const res3 = await fetch('api/settings_actions.php?action=get_admin_chat_id');
                const data3 = await res3.json();
                if (data3.ok) document.getElementById('admin-chatid-input').value = data3.admin_chat_id;
            } catch(e) {}
            try {
                const res4 = await fetch('api/settings_actions.php?action=get_premium_settings');
                const data4 = await res4.json();
                if (data4.ok) {
                    document.getElementById('premium-group-discount-enabled').checked = data4.group_discount_enabled;
                    document.getElementById('premium-group-discount-percent').value = data4.group_discount_percent;
                    document.getElementById('premium-excess-discount-enabled').checked = data4.excess_discount_enabled;
                    document.getElementById('premium-excess-discount-percent').value = data4.excess_discount_percent;
                    document.getElementById('premium-zero-km-discount-enabled').checked = data4.zero_km_discount_enabled;
                    document.getElementById('premium-zero-km-discount-percent').value = data4.zero_km_discount_percent;
                }
            } catch(e) {}
        }

        async function savePremiumSettings() {
            try {
                await fetch('api/settings_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'save_premium_settings',
                        group_discount_enabled: document.getElementById('premium-group-discount-enabled').checked,
                        group_discount_percent: parseFloat(document.getElementById('premium-group-discount-percent').value) || 2.5,
                        excess_discount_enabled: document.getElementById('premium-excess-discount-enabled').checked,
                        excess_discount_percent: parseFloat(document.getElementById('premium-excess-discount-percent').value) || 15,
                        zero_km_discount_enabled: document.getElementById('premium-zero-km-discount-enabled').checked,
                        zero_km_discount_percent: parseFloat(document.getElementById('premium-zero-km-discount-percent').value) || 5,
                    })
                });
                showToast('تنظیمات استعلام حق بیمه ذخیره شد.', 'success');
            } catch(e) { showToast('خطا در ذخیره‌سازی', 'error'); }
        }

        async function saveSiteUrlSetting() {
            const site_url = document.getElementById('site-url-input').value.trim();
            try {
                await fetch('api/settings_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'save_site_url', site_url })
                });
                showToast('آدرس سایت ذخیره شد.', 'success');
            } catch(e) { showToast('خطا در ذخیره‌سازی', 'error'); }
        }

        async function saveAdminChatIdSetting() {
            const admin_chat_id = document.getElementById('admin-chatid-input').value.trim();
            try {
                await fetch('api/settings_actions.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'save_admin_chat_id', admin_chat_id })
                });
                showToast('آیدی مدیر ذخیره شد.', 'success');
            } catch(e) { showToast('خطا در ذخیره‌سازی', 'error'); }
        }

        async function saveQuotaSetting() {
            const quota = parseInt(document.getElementById('quota-input').value) || 4;
            try {
                const res = await fetch('api/settings_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'save_quota', quota })
                });
                const data = await res.json();
                if (data.ok) showToast('سقف سهمیه ذخیره شد.', 'success');
                else showToast(data.error || 'خطا در ذخیره', 'error');
            } catch(e) { showToast('خطا در اتصال به سرور', 'error'); }
        }

        document.getElementById('user-search-input').addEventListener('keydown', e => { if (e.key === 'Enter') searchUsers(); });

        // بروزرسانی خودکار هر ۱۵ ثانیه: اگر تب گفتگوها بازه لیست کامل رو رفرش کن،
        // وگرنه فقط بج تعداد نخوانده رو (بدون بهم‌ریختن تب فعلی کاربر) بروز کن
        async function pollTicketsBadge() {
            try {
                const res = await fetch('api/ticket_actions.php?action=list');
                const data = await res.json();
                if (!data.ok) return;
                const ticketsTabActive = !document.getElementById('tab-tickets').classList.contains('hidden');
                if (ticketsTabActive) {
                    loadTickets();
                } else {
                    let totalUnread = 0;
                    data.data.forEach(t => totalUnread += parseInt(t.unread_count || 0));
                    const badge = document.getElementById('tickets-badge');
                    if (totalUnread > 0) { badge.innerText = e2p(totalUnread); badge.classList.remove('hidden'); }
                    else { badge.classList.add('hidden'); }
                }
            } catch(e) {}
        }
        setInterval(pollTicketsBadge, 15000);

        // ======================= کاربران =======================
        function renderUsers(users) {
            const tbody = document.getElementById('users-body');
            if (users.length > 0) {
                tbody.innerHTML = '';
                users.forEach(u => {
                    const chatBtn = u.bale_chat_id
                        ? `<button onclick="openUserChat(${u.id}, '${(u.full_name||'').replace(/'/g,"")}')" class="bg-blue-100 text-blue-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-blue-200">مشاهده / ارسال پیام</button>`
                        : `<span class="text-xs text-slate-400">بدون چت بله</span>`;
                    tbody.innerHTML += `
                        <tr class="hover:bg-blue-50/30">
                            <td class="p-4">${u.full_name || '-'}</td>
                            <td class="p-4 font-mono" dir="ltr">${e2p(u.national_code) || '-'}</td>
                            <td class="p-4 font-mono" dir="ltr">${e2p(u.personnel_code) || '-'}</td>
                            <td class="p-4">${u.company_name || '-'}</td>
                            <td class="p-4 font-mono" dir="ltr">${e2p(u.mobile_number) || '-'}</td>
                            <td class="p-4 text-xs text-slate-500" dir="ltr">${toJalali(u.created_at, false)}</td>
                            <td class="p-4 text-xs text-slate-500">${u.conversation_state || '-'}</td>
                            <td class="p-4">${chatBtn}</td>
                        </tr>`;
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-slate-400">کاربری یافت نشد.</td></tr>';
            }
        }

        async function loadUsers() {
            document.getElementById('user-search-input').value = '';
            const tbody = document.getElementById('users-body');
            tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</td></tr>';
            try {
                const res = await fetch('api/user_actions.php?action=list');
                const data = await res.json();
                if (data.ok) renderUsers(data.data);
            } catch(e) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-red-500">خطا در اتصال به سرور.</td></tr>';
            }
        }

        async function searchUsers() {
            const q = document.getElementById('user-search-input').value.trim();
            const tbody = document.getElementById('users-body');
            tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin"></i> در حال جستجو...</td></tr>';
            try {
                const res = await fetch('api/user_actions.php?action=search&q=' + encodeURIComponent(q));
                const data = await res.json();
                if (data.ok) renderUsers(data.data);
            } catch(e) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-red-500">خطا در اتصال به سرور.</td></tr>';
            }
        }

        // ---- ایموجی‌های پرکاربرد ----
        const EMOJI_LIST = ['😀','😁','😂','🙂','😉','😍','🤔','😢','😡','👍','👎','🙏','👌','✅','❌','🔥','🎉','📌','📄','📷','⏳','💬','📞','🚗','🛡️','⭐','❤️','😴','🤝','👋'];
        function buildEmojiPanel(panelId, inputId) {
            const panel = document.getElementById(panelId);
            panel.innerHTML = EMOJI_LIST.map(e => `<span class="cursor-pointer hover:scale-125 transition-transform text-lg" onclick="insertEmoji('${inputId}','${e}')">${e}</span>`).join('');
        }
        function toggleEmojiPanel(panelId) {
            const inputId = panelId === 'user-emoji-panel' ? 'user-chat-input' : 'ticket-chat-input';
            buildEmojiPanel(panelId, inputId);
            document.getElementById(panelId).classList.toggle('hidden');
        }
        function insertEmoji(inputId, emoji) {
            const input = document.getElementById(inputId);
            input.value += emoji;
            input.focus();
        }

        // scope مشخص می‌کند این حباب متعلق به چت ربات است یا رشته‌ی تیکت (مینی‌اپ) - چون
        // ویرایش/حذف هرکدام روی جدول متفاوتی عمل می‌کند
        function renderChatBubble(isMine, text, time, filePath, msgId, isRead, scope) {
            scope = scope || 'bot';
            const align = isMine ? 'justify-end' : 'justify-start';
            const color = isMine ? 'bg-blue-600 text-white' : 'bg-white text-slate-700 border';
            let fileHtml = '';
            if (filePath) {
                const isImg = /\.(jpg|jpeg|png|webp|gif)$/i.test(filePath);
                fileHtml = isImg
                    ? `<img src="/${encodeFilePath(filePath)}" class="rounded-lg max-w-full mt-2" />`
                    : `<a href="/${filePath}" target="_blank" class="underline text-xs block mt-2"><i class="fas fa-paperclip ml-1"></i>مشاهده فایل</a>`;
            }
            let controls = '';
            if (isMine && msgId) {
                controls = `<div class="flex gap-2 mt-1 opacity-70">
                    <button onclick="editAdminMessage(${msgId}, '${scope}')" class="text-[10px] underline hover:opacity-100">ویرایش</button>
                    <button onclick="deleteAdminMessage(${msgId}, this, '${scope}')" class="text-[10px] underline hover:opacity-100">حذف</button>
                </div>`;
            }
            const readTick = isMine ? `<span class="text-[9px] opacity-70">${isRead == 1 ? '✓✓ دیده شد' : '✓ ارسال شد'}</span>` : '';
            return `<div class="flex ${align}" id="msg-row-${msgId || ''}"><div class="${color} rounded-2xl px-4 py-2 text-sm max-w-[75%]"><span id="msg-text-${msgId || ''}">${text || ''}</span>${fileHtml}<div class="text-[10px] opacity-60 mt-1 flex justify-between gap-2" dir="ltr"><span>${time ? toJalali(time) : ''}</span>${readTick}</div>${controls}</div></div>`;
        }

        // هر بخش چت، اندپوینت و نام اکشن خودش را دارد
        function chatMsgEndpoint(scope) {
            if (scope === 'ticket')      return ['api/ticket_actions.php', 'edit_ticket_message', 'delete_ticket_message'];
            if (scope === 'companychat') return ['api/company_chat_actions.php', 'edit_message', 'delete_message'];
            if (scope === 'staffchat')   return ['api/staff_chat_actions.php', 'edit_message', 'delete_message'];
            return ['api/user_actions.php', 'edit_message', 'delete_message'];
        }

        function editAdminMessage(msgId, scope) {
            const current = document.getElementById('msg-text-' + msgId).innerText;
            showPrompt('ویرایش پیام', current, async (updated) => {
                if (!updated.trim() || updated === current) return;
                const [url, editAction] = chatMsgEndpoint(scope);
                try {
                    const res = await fetch(url, {
                        method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ action: editAction, message_id: msgId, message: updated })
                    });
                    const data = await res.json();
                    if (data && data.ok === false) { showToast(data.error || 'خطا در ویرایش پیام', 'error'); return; }
                    document.getElementById('msg-text-' + msgId).innerText = updated;
                    showToast('پیام ویرایش شد.', 'success');
                } catch(e) { showToast('خطا در ویرایش پیام', 'error'); }
            });
        }

        function deleteAdminMessage(msgId, btnEl, scope) {
            const [url, , deleteAction] = chatMsgEndpoint(scope);
            const note = scope === 'ticket' ? 'این پیام از رشته‌ی گفتگوی کاربر حذف شود؟'
                       : scope === 'companychat' ? 'این پیام از گفتگو با شرکت حذف شود؟'
                       : scope === 'staffchat' ? 'این پیام از گفتگوی داخلی حذف شود؟'
                       : 'این پیام هم از پنل و هم از چت کاربر در بله حذف شود؟';
            showConfirm('حذف پیام', note, async () => {
                try {
                    const res = await fetch(url, {
                        method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ action: deleteAction, message_id: msgId })
                    });
                    const data = await res.json();
                    if (data && data.ok === false) { showToast(data.error || 'خطا در حذف پیام', 'error'); return; }
                    const row = document.getElementById('msg-row-' + msgId);
                    if (row) row.remove();
                    showToast('پیام حذف شد.', 'success');
                } catch(e) { showToast('خطا در حذف پیام', 'error'); }
            });
        }

        async function openUserChat(personId, fullName) {
            document.getElementById('user-chat-person-id').value = personId;
            document.getElementById('user-chat-name').innerText = fullName || 'کاربر';
            document.getElementById('user-chat-modal').classList.remove('hidden');
            document.getElementById('user-chat-modal').classList.add('flex');
            document.getElementById('user-emoji-panel').classList.add('hidden');
            setUserMsgDest('bot'); // هر بار باز شدن، مقصد پیش‌فرض به «ربات» برمی‌گردد
            const body = document.getElementById('user-chat-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm">در حال بارگذاری...</p>';
            try {
                const res = await fetch('api/user_actions.php?action=history&person_id=' + personId);
                const data = await res.json();
                body.innerHTML = '';
                if (data.ok) {
                    data.data.forEach(m => {
                        const prefix = m.sender_type === 'BOT' ? '🤖 ' : (m.sender_type === 'CUSTOMER' ? '👤 ' : '');
                        body.innerHTML += renderChatBubble(m.sender_type === 'ADMIN', prefix + (m.message || ''), m.created_at, m.file_path, m.sender_type === 'ADMIN' ? m.id : null);
                    });
                }
                body.scrollTop = body.scrollHeight;
            } catch(e) { body.innerHTML = '<p class="text-center text-red-500 text-sm">خطا در دریافت پیام‌ها.</p>'; }
        }

        // مقصد پیامِ کارشناس: «ربات کاربر» (پیام مستقیم در چت بله) یا «مینی‌اپ کاربر»
        // (ثبت در رشته‌ی ارتباط با کارشناس + یادآوری کوتاه در ربات)
        let userMsgDest = 'bot';
        function setUserMsgDest(dest) {
            userMsgDest = dest;
            const on = 'flex-1 text-[11px] font-bold py-1.5 rounded-lg bg-blue-600 text-white';
            const off = 'flex-1 text-[11px] font-bold py-1.5 rounded-lg bg-slate-100 text-slate-600';
            document.getElementById('dest-tab-bot').className = dest === 'bot' ? on : off;
            document.getElementById('dest-tab-app').className = dest === 'app' ? on : off;
            document.getElementById('user-chat-input').placeholder =
                dest === 'app' ? 'پیام به مینی‌اپ کاربر (ارتباط با کارشناس)...' : 'پیام مستقیم در ربات کاربر...';
        }

        async function sendUserMessage() {
            const personId = document.getElementById('user-chat-person-id').value;
            const input = document.getElementById('user-chat-input');
            const text = input.value.trim();
            if (!text) return;
            input.value = '';
            try {
                const res = await fetch('api/user_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'send_message', person_id: personId, message: text, dest: userMsgDest })
                });
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا در ارسال پیام', 'error'); return; }
                if (userMsgDest === 'app') {
                    showToast('پیام در مینی‌اپ کاربر ثبت شد.', 'success');
                    document.getElementById('user-chat-body').innerHTML += renderChatBubble(true, '📱 (به مینی‌اپ) ' + text, new Date().toISOString(), null, null, 0);
                } else {
                    document.getElementById('user-chat-body').innerHTML += renderChatBubble(true, text, new Date().toISOString(), null, data.id || null);
                }
                document.getElementById('user-chat-body').scrollTop = document.getElementById('user-chat-body').scrollHeight;
            } catch(e) { showToast('خطا در ارسال پیام', 'error'); }
        }

        // ======================= شروع گفتگوی جدید با یک کاربر =======================
        function openNewChatPicker() {
            document.getElementById('new-chat-modal').classList.remove('hidden');
            document.getElementById('new-chat-modal').classList.add('flex');
            document.getElementById('new-chat-search').value = '';
            document.getElementById('new-chat-results').innerHTML = '<p class="text-center text-slate-400 text-xs p-4">برای جستجو تایپ کنید.</p>';
            document.getElementById('new-chat-search').focus();
        }

        let newChatSearchTimer = null;
        function searchUsersForChat() {
            clearTimeout(newChatSearchTimer);
            newChatSearchTimer = setTimeout(async () => {
                const q = document.getElementById('new-chat-search').value.trim();
                const box = document.getElementById('new-chat-results');
                if (q.length < 2) { box.innerHTML = '<p class="text-center text-slate-400 text-xs p-4">حداقل ۲ نویسه وارد کنید.</p>'; return; }
                box.innerHTML = '<p class="text-center text-slate-400 text-xs p-4"><i class="fas fa-spinner fa-spin"></i></p>';
                try {
                    const res = await fetch('api/user_actions.php?action=search&q=' + encodeURIComponent(q));
                    const data = await res.json();
                    if (!data.ok || !data.data.length) { box.innerHTML = '<p class="text-center text-slate-400 text-xs p-4">کاربری یافت نشد.</p>'; return; }
                    box.innerHTML = data.data.map(u => `
                        <div class="border rounded-xl p-3 flex justify-between items-center hover:bg-emerald-50">
                            <div>
                                <p class="font-bold text-xs">${u.full_name || '-'}</p>
                                <p class="text-[10px] text-slate-400" dir="ltr">${e2p(u.national_code) || '-'} ${u.personnel_code ? '| پرسنلی: ' + e2p(u.personnel_code) : ''}</p>
                                ${u.company_name ? `<p class="text-[10px] text-slate-400">${u.company_name}</p>` : ''}
                            </div>
                            <button onclick="startChatWithUser(${u.id}, '${(u.full_name||'').replace(/'/g,'')}')" class="bg-emerald-600 text-white text-[11px] font-bold px-3 py-1.5 rounded-lg hover:bg-emerald-700">شروع گفتگو</button>
                        </div>`).join('');
                } catch(e) { box.innerHTML = '<p class="text-center text-red-500 text-xs p-4">خطا در جستجو.</p>'; }
            }, 350);
        }

        function startChatWithUser(personId, fullName) {
            document.getElementById('new-chat-modal').classList.add('hidden');
            openUserChat(personId, fullName);
            // گفتگوی ایجادشده از این مسیر، طبق تصمیم، فقط به مینی‌اپ کاربر می‌رود
            setTimeout(() => setUserMsgDest('app'), 100);
        }

        async function sendUserFile() {
            const personId = document.getElementById('user-chat-person-id').value;
            const fileInput = document.getElementById('user-chat-file');
            if (!fileInput.files[0]) return;
            const fd = new FormData();
            fd.append('action', 'send_file');
            fd.append('person_id', personId);
            fd.append('file', fileInput.files[0]);
            document.getElementById('user-chat-body').innerHTML += renderChatBubble(true, '📎 در حال ارسال فایل...', new Date().toISOString());
            try {
                await fetch('api/user_actions.php', { method: 'POST', body: fd });
                showToast('فایل ارسال شد.', 'success');
            } catch(e) { showToast('خطا در ارسال فایل', 'error'); }
            fileInput.value = '';
        }

        document.getElementById('user-chat-input').addEventListener('keydown', e => { if (e.key === 'Enter') sendUserMessage(); });

        // ======================= تیکت‌های پشتیبانی =======================
        let currentTicketId = null;

        // ======================= چت‌های ربات (آرشیو کامل، کنار تیکت‌های پشتیبانی) =======================
        function switchGoftegoSub(which) {
            ['tickets','botchats','staffchat','companychat'].forEach(k => {
                const panel = document.getElementById('goftego-panel-' + k);
                if (panel) panel.classList.toggle('hidden', which !== k);
                const btn = document.getElementById('goftego-sub-' + k);
                if (btn) btn.className = 'px-4 py-2 rounded-lg text-xs font-bold ' + (which === k ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500');
            });
            if (which === 'botchats') loadBotChats();
            if (which === 'staffchat') loadStaffChatList();
            if (which === 'companychat') loadCompanyChatList();
        }

        async function loadBotChats(q) {
            const tbody = document.getElementById('botchats-body');
            tbody.innerHTML = '<tr><td colspan="5" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin"></i></td></tr>';
            try {
                const url = q ? 'api/user_actions.php?action=search&q=' + encodeURIComponent(q) : 'api/user_actions.php?action=list';
                const res = await fetch(url);
                const data = await res.json();
                if (data.ok && data.data.length > 0) {
                    tbody.innerHTML = data.data.map(u => `
                        <tr class="hover:bg-emerald-50/30">
                            <td class="p-4">${u.full_name || '-'}</td>
                            <td class="p-4 font-mono" dir="ltr">${e2p(u.national_code) || '-'}</td>
                            <td class="p-4 font-mono" dir="ltr">${e2p(u.mobile_number) || '-'}</td>
                            <td class="p-4 text-xs text-slate-500">${u.conversation_state || '-'}</td>
                            <td class="p-4"><button onclick="openUserChat(${u.id}, '${(u.full_name||'').replace(/'/g,"")}')" class="bg-emerald-100 text-emerald-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-emerald-200">مشاهده آرشیو کامل</button></td>
                        </tr>`).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center p-8 text-slate-400">کاربری یافت نشد.</td></tr>';
                }
            } catch(e) { tbody.innerHTML = '<tr><td colspan="5" class="text-center p-8 text-red-500">خطا در اتصال.</td></tr>'; }
        }
        document.getElementById('botchat-search-input').addEventListener('keydown', e => { if (e.key === 'Enter') loadBotChats(e.target.value.trim()); });

        let currentTicketsData = [];

        async function loadTickets() {
            const list = document.getElementById('tickets-list');
            list.innerHTML = '<p class="text-center text-slate-400 text-sm p-4"><i class="fas fa-spinner fa-spin"></i></p>';
            try {
                const res = await fetch('api/ticket_actions.php?action=list');
                const data = await res.json();
                if (data.ok) {
                    currentTicketsData = data.data;
                    renderTicketsList(currentTicketsData);
                    const totalUnread = currentTicketsData.reduce((s, t) => s + parseInt(t.unread_count || 0), 0);
                    const badge = document.getElementById('tickets-badge');
                    if (totalUnread > 0) { badge.innerText = e2p(totalUnread); badge.classList.remove('hidden'); }
                    else { badge.classList.add('hidden'); }
                } else {
                    list.innerHTML = '<p class="text-center text-slate-400 text-sm p-4">خطا در دریافت گفتگوها.</p>';
                }
            } catch(e) { list.innerHTML = '<p class="text-center text-red-500 text-sm p-4">خطا در اتصال.</p>'; }
        }

        function renderTicketsList(items) {
            const list = document.getElementById('tickets-list');
            if (items.length === 0) { list.innerHTML = '<p class="text-center text-slate-400 text-sm p-4">موردی یافت نشد.</p>'; return; }
            list.innerHTML = '';
            items.forEach(t => {
                const statusColor = t.status === 'OPEN' ? 'text-emerald-600' : 'text-slate-400';
                const unreadBadge = t.unread_count > 0 ? `<span class="bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full">${t.unread_count}</span>` : '';
                list.innerHTML += `
                    <div onclick="openTicket(${t.id}, '${(t.full_name || t.sender_name || 'کاربر').replace(/'/g,"")}')" class="p-3 border-b cursor-pointer hover:bg-emerald-50/50 ${currentTicketId===t.id ? 'bg-emerald-50' : ''}">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-sm">${t.full_name || t.sender_name || 'کاربر ناشناس'}</span>
                            ${unreadBadge}
                        </div>
                        <p class="text-[10px] text-slate-400" dir="ltr">${e2p(t.national_code) || ''}</p>
                        <p class="text-xs text-slate-400 truncate">${t.last_message || ''}</p>
                        <div class="flex justify-between items-center mt-1">
                            <span class="text-[10px] font-bold ${statusColor}">${t.status === 'OPEN' ? 'باز' : 'بسته'}</span>
                            <span class="text-[10px] text-slate-400" dir="ltr">${toJalali(t.last_message_at || t.created_at)}</span>
                        </div>
                    </div>`;
            });
        }

        document.getElementById('ticket-search-input').addEventListener('input', e => {
            const q = e.target.value.trim().toLowerCase();
            if (!q) { renderTicketsList(currentTicketsData); return; }
            const filtered = currentTicketsData.filter(t => {
                const haystack = [t.full_name, t.sender_name, t.sender_username, t.national_code, t.last_message, t.subject]
                    .filter(Boolean).join(' ').toLowerCase();
                return haystack.includes(q);
            });
            renderTicketsList(filtered);
        });

        let ticketPollTimer = null;
        async function openTicket(ticketId, title) {
            currentTicketId = ticketId;
            document.getElementById('ticket-chat-header').classList.remove('hidden');
            document.getElementById('ticket-chat-input-row').classList.remove('hidden');
            document.getElementById('ticket-emoji-panel').classList.add('hidden');
            document.getElementById('ticket-chat-title').innerText = title;
            await refreshTicketMessages(true);
            loadTickets(); // برای بروزرسانی شمارنده‌ی نخوانده‌ها
            if (ticketPollTimer) clearInterval(ticketPollTimer);
            ticketPollTimer = setInterval(() => refreshTicketMessages(false), 4000);
        }

        async function refreshTicketMessages(showLoading) {
            if (!currentTicketId) return;
            const body = document.getElementById('ticket-chat-body');
            if (showLoading) body.innerHTML = '<p class="text-center text-slate-400 text-sm">در حال بارگذاری...</p>';
            try {
                const res = await fetch('api/ticket_actions.php?action=messages&ticket_id=' + currentTicketId);
                const data = await res.json();
                if (data.ok) {
                    const wasAtBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 30;
                    body.innerHTML = '';
                    data.data.forEach(m => body.innerHTML += renderChatBubble(m.sender_type === 'ADMIN', m.message, m.created_at, m.file_path, m.sender_type === 'ADMIN' ? m.id : null, m.is_read, 'ticket'));
                    if (wasAtBottom || showLoading) body.scrollTop = body.scrollHeight;
                    const typingEl = document.getElementById('ticket-typing-indicator');
                    if (typingEl) typingEl.classList.toggle('hidden', !data.customer_typing);
                }
            } catch(e) { if (showLoading) body.innerHTML = '<p class="text-center text-red-500 text-sm">خطا در دریافت پیام‌ها.</p>'; }
        }

        function onAdminTyping() {
            if (!currentTicketId || window._adminTypingSent) return;
            window._adminTypingSent = true;
            fetch('api/ticket_actions.php?action=typing&ticket_id=' + currentTicketId).catch(()=>{});
            setTimeout(() => { window._adminTypingSent = false; }, 3000);
        }

        async function sendTicketReply() {
            if (!currentTicketId) return;
            const input = document.getElementById('ticket-chat-input');
            const text = input.value.trim();
            if (!text) return;
            input.value = '';
            try {
                await fetch('api/ticket_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'reply', ticket_id: currentTicketId, message: text })
                });
                refreshTicketMessages(false);
            } catch(e) { showToast('خطا در ارسال پاسخ', 'error'); }
        }

        async function sendTicketFile() {
            if (!currentTicketId) return;
            const fileInput = document.getElementById('ticket-chat-file');
            if (!fileInput.files[0]) return;
            const fd = new FormData();
            fd.append('action', 'send_file');
            fd.append('ticket_id', currentTicketId);
            fd.append('file', fileInput.files[0]);
            document.getElementById('ticket-chat-body').innerHTML += renderChatBubble(true, '📎 در حال ارسال فایل...', new Date().toISOString());
            try {
                await fetch('api/ticket_actions.php', { method: 'POST', body: fd });
                showToast('فایل ارسال شد.', 'success');
            } catch(e) { showToast('خطا در ارسال فایل', 'error'); }
            fileInput.value = '';
        }

        document.getElementById('ticket-chat-input').addEventListener('keydown', e => { if (e.key === 'Enter') sendTicketReply(); });

        // ======================= چت داخلی (بین کاربران پنل) =======================
        const STAFFCHAT_API = 'api/staff_chat_actions.php';
        let currentStaffChatUserId = null;

        async function loadStaffChatList() {
            const res = await fetch(STAFFCHAT_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_conversations'})});
            const data = await res.json();
            const box = document.getElementById('staffchat-list');
            if (!data.ok) { box.innerHTML = `<p class="text-center text-red-500 text-xs p-4">${data.error || 'خطا'}</p>`; return; }
            box.innerHTML = data.conversations.map(u => `
                <div onclick="openStaffChatWith(${u.id}, '${u.full_name}')" class="p-3 border-b cursor-pointer hover:bg-slate-50 ${currentStaffChatUserId === u.id ? 'bg-blue-50' : ''}">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-sm">${u.full_name}</span>
                        ${u.unread > 0 ? `<span class="bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full">${u.unread}</span>` : ''}
                    </div>
                    <p class="text-[10px] text-slate-400 mt-1">${u.role} ${u.last_message ? '· ' + u.last_message.slice(0, 30) : ''}</p>
                </div>`).join('') || '<p class="text-center text-xs text-slate-400 p-4">همکاری یافت نشد.</p>';
        }

        async function openStaffChatWith(userId, fullName) {
            currentStaffChatUserId = userId;
            document.getElementById('staffchat-header').classList.remove('hidden');
            document.getElementById('staffchat-title').textContent = fullName;
            document.getElementById('staffchat-input-row').classList.remove('hidden');
            await refreshStaffChatMessages();
            loadStaffChatList();
        }

        async function refreshStaffChatMessages() {
            if (!currentStaffChatUserId) return;
            const res = await fetch(STAFFCHAT_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'get_messages', with_user_id: currentStaffChatUserId})});
            const data = await res.json();
            if (!data.ok) return;
            const myId = <?php echo intval($_SESSION['user_id']); ?>;
            const body = document.getElementById('staffchat-body');
            body.innerHTML = data.messages.length ? data.messages.map(m => renderChatBubble(m.from_user_id == myId, m.message, m.created_at, m.file_path, null, m.is_read, 'staffchat')).join('') : '<p class="text-center text-slate-400 text-sm mt-10">هنوز پیامی رد و بدل نشده.</p>';
            body.scrollTop = body.scrollHeight;
        }

        async function sendStaffChatMessage() {
            if (!currentStaffChatUserId) return;
            const input = document.getElementById('staffchat-input');
            const text = input.value.trim();
            if (!text) return;
            input.value = '';
            await fetch(STAFFCHAT_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'send_message', to_user_id: currentStaffChatUserId, message: text})});
            refreshStaffChatMessages();
        }

        async function sendStaffChatFile() {
            if (!currentStaffChatUserId) return;
            const fileInput = document.getElementById('staffchat-file');
            if (!fileInput.files[0]) return;
            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('to_user_id', currentStaffChatUserId);
            fd.append('file', fileInput.files[0]);
            try {
                await fetch(STAFFCHAT_API, {method: 'POST', body: fd});
                refreshStaffChatMessages();
            } catch (e) { showToast('خطا در ارسال فایل', 'error'); }
            fileInput.value = '';
        }

        document.getElementById('staffchat-input').addEventListener('keydown', e => { if (e.key === 'Enter') sendStaffChatMessage(); });

        // ======================= چت با شرکت‌های درخواست‌کننده =======================
        const COMPANYCHAT_API = 'api/company_chat_actions.php';
        let currentCompanyChatId = null;

        let companyChatSearchTimer = null;
        function debouncedCompanyChatSearch() {
            clearTimeout(companyChatSearchTimer);
            companyChatSearchTimer = setTimeout(loadCompanyChatList, 300);
        }

        async function loadCompanyChatList() {
            const box = document.getElementById('companychat-list');
            const searchEl = document.getElementById('companychat-search');
            const q = searchEl ? searchEl.value.trim() : '';
            let data;
            try {
                const res = await fetch(COMPANYCHAT_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_conversations', q})});
                data = await res.json();
            } catch (e) { box.innerHTML = '<p class="text-center text-red-500 text-xs p-4">خطا در ارتباط با سرور.</p>'; return; }
            if (!data.ok) { box.innerHTML = `<p class="text-center text-red-500 text-xs p-4">${data.error || 'خطا'}</p>`; return; }
            box.innerHTML = data.conversations.map(c => `
                <div onclick="openCompanyChatWith(${c.id}, '${(c.name||'').replace(/'/g,'')}')" class="p-3 border-b cursor-pointer hover:bg-slate-50 ${String(currentCompanyChatId) === String(c.id) ? 'bg-cyan-50' : ''}">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-sm">${c.name}</span>
                        ${c.unread > 0 ? `<span class="bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full">${c.unread}</span>` : ''}
                    </div>
                    ${c.members ? `<p class="text-[10px] text-slate-500 mt-0.5">${c.members}</p>` : ''}
                    <p class="text-[10px] text-slate-400 mt-1">${c.last_message ? c.last_message.slice(0, 30) : 'گفتگویی ثبت نشده'}</p>
                </div>`).join('') || `<p class="text-center text-xs text-slate-400 p-4">${q ? 'موردی با این نام پیدا نشد.' : 'شرکتی یافت نشد.'}</p>`;
        }

        async function openCompanyChatWith(companyId, name) {
            currentCompanyChatId = companyId;
            document.getElementById('companychat-header').classList.remove('hidden');
            document.getElementById('companychat-title').textContent = name;
            document.getElementById('companychat-input-row').classList.remove('hidden');
            await refreshCompanyChatMessages();
            loadCompanyChatList();
        }

        async function refreshCompanyChatMessages() {
            if (!currentCompanyChatId) return;
            const res = await fetch(COMPANYCHAT_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'get_messages', company_id: currentCompanyChatId})});
            const data = await res.json();
            if (!data.ok) return;
            const body = document.getElementById('companychat-body');
            body.innerHTML = data.messages.length ? data.messages.map(m => renderChatBubble(m.sender_type === 'ADMIN', (m.sender_type === 'COMPANY' && m.sender_portal_name ? `<b>${m.sender_portal_name}:</b> ` : '') + (m.message || ''), m.created_at, m.file_path, m.sender_type === 'ADMIN' ? m.id : null, m.is_read, 'companychat')).join('') : '<p class="text-center text-slate-400 text-sm mt-10">هنوز پیامی رد و بدل نشده.</p>';
            body.scrollTop = body.scrollHeight;
        }

        async function sendCompanyChatMessage() {
            if (!currentCompanyChatId) return;
            const input = document.getElementById('companychat-input');
            const text = input.value.trim();
            if (!text) return;
            input.value = '';
            await fetch(COMPANYCHAT_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'send_message', company_id: currentCompanyChatId, message: text})});
            refreshCompanyChatMessages();
        }

        async function sendCompanyChatFile() {
            if (!currentCompanyChatId) return;
            const fileInput = document.getElementById('companychat-file');
            if (!fileInput.files[0]) return;
            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('company_id', currentCompanyChatId);
            fd.append('file', fileInput.files[0]);
            try {
                await fetch(COMPANYCHAT_API, {method: 'POST', body: fd});
                refreshCompanyChatMessages();
            } catch (e) { showToast('خطا در ارسال فایل', 'error'); }
            fileInput.value = '';
        }

        const companychatInputEl = document.getElementById('companychat-input');
        if (companychatInputEl) companychatInputEl.addEventListener('keydown', e => { if (e.key === 'Enter') sendCompanyChatMessage(); });

        async function closeCurrentTicket() {
            if (!currentTicketId) return;
            showConfirm('بستن گفتگو', 'این گفتگوی پشتیبانی بسته شود؟ کاربر به‌طور خودکار به منوی اصلی بازمی‌گردد.', async () => {
                await fetch('api/ticket_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'close', ticket_id: currentTicketId })
                });
                loadTickets();
            });
        }

        const ctxMenu = document.getElementById('contextMenu');
        
        document.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            
            // ذخیره متن انتخاب شده و فیلدی که فوکوس دارد قبل از اینکه منو باز شود
            ctxSelectedText = window.getSelection().toString();
            ctxActiveElement = document.activeElement;

            const row = e.target.closest('tr[data-id]');
            document.getElementById('ctx-general').classList.add('hidden');
            document.getElementById('ctx-row-actions').classList.add('hidden');

            if(row) {
                activeRowId = row.dataset.id;
                activeRowNational = row.dataset.national || '';
                document.getElementById('ctx-row-actions').classList.remove('hidden');
                row.classList.add('bg-blue-100/50'); 
                setTimeout(()=>row.classList.remove('bg-blue-100/50'), 800);
            } else {
                document.getElementById('ctx-general').classList.remove('hidden');
            }
            
            ctxMenu.classList.add('active');
            let x = e.clientX, y = e.clientY;
            if(x + 224 > window.innerWidth) x = window.innerWidth - 224;
            if(y + 200 > window.innerHeight) y = window.innerHeight - 200;
            ctxMenu.style.left = `${x}px`; ctxMenu.style.top = `${y}px`;
        });

        document.addEventListener('click', (e) => { 
            if(!ctxMenu.contains(e.target) || e.target.classList.contains('ctx-item')) ctxMenu.classList.remove('active'); 
        });

        // توابع کپی/کات/پیست پیشرفته و ایمن
        function ctxExecute(action) {
            ctxMenu.classList.remove('active');

            if(action === 'copy') {
                if (ctxSelectedText) {
                    navigator.clipboard.writeText(ctxSelectedText)
                        .then(() => showToast('متن کپی شد.', 'success'))
                        .catch(() => showToast('دسترسی کلیپ‌بورد در مرورگر مسدود است.', 'error'));
                } else {
                    showToast('ابتدا متنی را انتخاب کنید.', 'warning');
                }
            }
            
            if(action === 'cut') {
                if (ctxSelectedText && ctxActiveElement && (ctxActiveElement.tagName === 'INPUT' || ctxActiveElement.tagName === 'TEXTAREA')) {
                    navigator.clipboard.writeText(ctxSelectedText).then(() => {
                        const start = ctxActiveElement.selectionStart;
                        const end = ctxActiveElement.selectionEnd;
                        ctxActiveElement.value = ctxActiveElement.value.slice(0, start) + ctxActiveElement.value.slice(end);
                        showToast('متن برش داده شد.', 'success');
                    }).catch(() => showToast('خطا در دسترسی به کلیپ‌بورد.', 'error'));
                } else {
                    showToast('برای برش، متنی را داخل فیلد تایپ انتخاب کنید.', 'warning');
                }
            }
            
            if(action === 'paste') {
                if (ctxActiveElement && (ctxActiveElement.tagName === 'INPUT' || ctxActiveElement.tagName === 'TEXTAREA')) {
                    navigator.clipboard.readText().then(text => {
                        const start = ctxActiveElement.selectionStart || 0;
                        const end = ctxActiveElement.selectionEnd || 0;
                        ctxActiveElement.value = ctxActiveElement.value.slice(0, start) + text + ctxActiveElement.value.slice(end);
                        showToast('متن جایگذاری شد.', 'success');
                    }).catch(() => showToast('دسترسی کلیپ‌بورد در مرورگر مسدود است.', 'warning'));
                } else {
                    showToast('ابتدا روی یک کادر متنی کلیک کنید.', 'warning');
                }
            }
        }

        // توابع منوی کلیک راست پرونده‌ها
        async function ctxExecuteRow(action) {
            ctxMenu.classList.remove('active');
            if(!activeRowId) return;

            if (action === 'copy-national') {
                if (activeRowNational) {
                    const tempInput = document.createElement("input");
                    tempInput.value = activeRowNational;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    try {
                        document.execCommand("copy");
                        showToast('کد ملی کپی شد.', 'success');
                    } catch(e) {
                        showToast('دسترسی کلیپ‌بورد مسدود است.', 'error');
                    }
                    document.body.removeChild(tempInput);
                } else {
                    showToast('کد ملی در این ردیف یافت نشد.', 'warning');
                }
                return;
            }

            if (action === 'mark-issued' || action === 'mark-waiting') {
                const status = action === 'mark-issued' ? 'ISSUED' : 'WAITING_FOR_DOCUMENTS';
                try {
                    const res = await fetch('api/record_actions.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({action: 'change_status', id: activeRowId, status: status})
                    });
                    const data = await res.json();
                    if(data.ok) {
                        showToast('وضعیت پرونده تغییر کرد.', 'success');
                        loadRecords();
                    } else {
                        showToast(data.error || 'خطا در تغییر وضعیت', 'error');
                    }
                } catch (e) { showToast('خطا در ارتباط با سرور', 'error'); }
                return;
            }
        }

        /* مودال ثبت دستی پرونده */
        function openManualCreateModal() {
            document.getElementById('mc-full-name').value = '';
            document.getElementById('mc-national-code').value = '';
            document.getElementById('mc-personnel-code').value = '';
            document.getElementById('mc-company-name').value = '';
            document.getElementById('manual-create-modal').classList.add('active');
        }

        async function submitManualCreate() {
            const payload = {
                action: 'create_manual',
                full_name: document.getElementById('mc-full-name').value.trim(),
                national_code: p2e(document.getElementById('mc-national-code').value.trim()),
                personnel_code: p2e(document.getElementById('mc-personnel-code').value.trim()),
                company_name: document.getElementById('mc-company-name').value.trim(),
            };

            if (!payload.full_name || !/^\d{10}$/.test(payload.national_code)) {
                showToast('نام و کد ملی معتبر (۱۰ رقمی) را وارد کنید.', 'warning');
                return;
            }

            try {
                const res = await fetch('api/record_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.ok) {
                    showToast('معرفی‌نامه ثبت شد؛ حالا نوع اولین پرونده را انتخاب کنید.', 'success');
                    document.getElementById('manual-create-modal').classList.remove('active');
                    loadRecords();
                    if (data.intro_id) openIntroDetail(data.intro_id, payload.full_name);
                } else {
                    showToast(data.error || 'خطا در ثبت پرونده.', 'error');
                }
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }

        /* ذخیره تنظیمات توکن بات */
        async function saveBotSettings() {
            const tokenInput = document.getElementById('bot-token-input');
            const token = tokenInput.value.trim();
            if (!token) {
                showToast('لطفاً توکن را به درستی وارد کنید.', 'warning');
                return;
            }
            try {
                const res = await fetch('api/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_token', token: token })
                });
                const data = await res.json();
                if (data.ok) {
                    showToast('توکن با موفقیت در دیتابیس ذخیره شد.', 'success');
                    tokenInput.value = '';
                    setTimeout(() => location.reload(), 1500); // برای آپدیت شدن placeholder
                } else {
                    showToast(data.error || 'خطا در ذخیره تنظیمات.', 'error');
                }
            } catch (e) { showToast('خطا در ارتباط با سرور (مطمئن شوید فایل api/settings.php وجود دارد).', 'error'); }
        }

        async function saveCompanyBotToken() {
            const tokenInput = document.getElementById('company-bot-token-input');
            const token = tokenInput.value.trim();
            if (!token) { showToast('لطفاً توکن را به درستی وارد کنید.', 'warning'); return; }
            try {
                const res = await fetch('api/settings.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_company_bot_token', token: token })
                });
                const data = await res.json();
                if (data.ok) {
                    showToast('توکن ربات شرکتی ذخیره شد.', 'success');
                    tokenInput.value = '';
                    setTimeout(() => location.reload(), 1500);
                } else { showToast(data.error || 'خطا در ذخیره تنظیمات.', 'error'); }
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }

        function openEditModal() {
            ctxMenu.classList.remove('active');
            if(!activeRowId) return;
            const record = currentRecordsData.find(r => r.id == activeRowId);
            if(record) {
                document.getElementById('edit-full-name').value = record.full_name || '';
                document.getElementById('edit-national-code').value = record.national_code || '';
                document.getElementById('edit-personnel-code').value = record.personnel_code || '';
                document.getElementById('edit-insurance-type').value = record.insurance_type || 'در انتظار انتخاب';
                document.getElementById('edit-status').value = record.status || 'NEW';
                document.getElementById('edit-modal').classList.add('active');
            }
        }

        async function saveRecordEdit() {
            const payload = {
                action: 'edit',
                id: activeRowId,
                full_name: document.getElementById('edit-full-name').value,
                national_code: p2e(document.getElementById('edit-national-code').value),
                personnel_code: p2e(document.getElementById('edit-personnel-code').value),
                insurance_type: document.getElementById('edit-insurance-type').value,
                status: document.getElementById('edit-status').value
            };

            try {
                const res = await fetch('api/record_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if(data.ok) {
                    showToast('تغییرات پرونده با موفقیت ذخیره شد.', 'success');
                    document.getElementById('edit-modal').classList.remove('active');
                    loadRecords();
                } else {
                    showToast(data.error || 'خطا در ویرایش پرونده', 'error');
                }
            } catch (e) {
                showToast('خطا در برقراری ارتباط با سرور', 'error');
            }
        }

        function confirmDeleteRow() {
            ctxMenu.classList.remove('active');
            if(!activeRowId) return;
            document.getElementById('confirm-modal').classList.add('active');
        }

        async function executeDeleteRow() {
            try {
                const res = await fetch('api/record_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'delete', id: activeRowId})
                });
                const data = await res.json();
                if(data.ok) {
                    showToast(data.message, 'success');
                    document.getElementById('confirm-modal').classList.remove('active');
                    loadRecords();
                } else {
                    showToast(data.error || 'خطا در حذف پرونده', 'error');
                }
            } catch (e) {
                showToast('خطا در ارتباط با سرور', 'error');
            }
        }

        async function executeResetAll() {
            const password = document.getElementById('reset-password-input').value.trim();
            if (!password) { showToast('لطفاً رمز تایید را وارد کنید.', 'error'); return; }
            try {
                const res = await fetch('api/record_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'reset_all', password})
                });
                const data = await res.json();
                if (data.ok) {
                    showToast(data.message, 'success');
                    document.getElementById('reset-modal').classList.remove('active');
                    document.getElementById('reset-password-input').value = '';
                    loadRecords();
                    switchTab('dashboard');
                } else {
                    showToast(data.error || 'خطا در بازنشانی سیستم', 'error');
                }
            } catch (e) {
                showToast('خطا در برقراری ارتباط با سرور', 'error');
            }
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function showToast(msg, type='info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            let icon = type==='error' ? 'fa-times-circle text-red-500' : (type==='warning' ? 'fa-exclamation-triangle text-amber-500' : 'fa-check-circle text-emerald-500');
            toast.className = `bg-slate-800 text-white px-5 py-3 rounded-xl shadow-2xl font-bold text-sm flex items-center gap-3 transform translate-y-[-20px] opacity-0 transition-all z-[9999]`;
            toast.innerHTML = `<i class="fas ${icon} text-lg"></i> ${msg}`;
            container.appendChild(toast);
            requestAnimationFrame(() => toast.classList.remove('translate-y-[-20px]', 'opacity-0'));
            setTimeout(() => { toast.classList.add('opacity-0'); setTimeout(()=>toast.remove(), 300); }, 3000);
        }

        window.addEventListener('load', () => {
            tsParticles.load("tsparticles", {
                fullScreen: { enable: false },
                particles: { number: { value: 30 }, color: { value: "#ffffff" }, opacity: { value: 0.5, random: true }, size: { value: 3 }, links: { enable: true, distance: 150, color: "#ffffff", opacity: 0.3 }, move: { enable: true, speed: 1 } }
            });
        });

        // ================= نشانگرِ اختصاصیِ موس =================
        (function initCustomCursor() {
            const dot = document.querySelector('.cursor-dot');
            const outline = document.querySelector('.cursor-outline');
            if (!dot || !outline) return;
            // روی دستگاهِ لمسی نه نشانگر لازم است نه هزینه‌اش
            if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

            // -100 یعنی پیش از اولین حرکتِ موس، هر دو بخش بیرونِ صفحه‌اند
            let mx = -100, my = -100, ox = -100, oy = -100, running = false;

            // همه‌ی نوشتن‌ها در یک فریم جمع می‌شوند: mousemove فقط مختصات را نگه می‌دارد
            // (موس‌های پرسرعت چند بار در یک فریم رویداد می‌دهند) و نوشتن روی DOM دقیقاً
            // یک بار در هر فریم انجام می‌شود. حلقه هم به‌محض رسیدنِ حلقه‌ی بیرونی به موس
            // می‌خوابد، نه اینکه بی‌دلیل همیشه ۶۰ بار در ثانیه کار کند.
            const EASE = 0.35; // تندتر از قبل (۰.۲) تا نشانگر چسبیده‌تر و سریع‌تر حس شود
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

            // تشخیصِ «روی چیزِ قابل‌کلیک هستیم» با یک شنونده‌ی واگذارشده روی document،
            // نه دو شنونده به‌ازای هر دکمه. این‌طور هر دکمه‌ای که بعداً به‌صورت داینامیک
            // ساخته می‌شود هم خودش جلوه را می‌گیرد (قبلاً فقط دکمه‌های لحظه‌ی لود را می‌گرفت).
            const HOVER_SELECTOR = 'a, button, input, select, textarea, label, summary, .hover-target, [onclick], [role="button"]';
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
</body>
</html>