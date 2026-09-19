<?php
session_start();
// این صفحه مدام در حال تغییر است؛ بدون این هدرها، مرورگر ممکن است نسخه‌ی قدیمیِ
// کش‌شده را نشان بدهد و دکمه‌های جدید (مثل انتخاب درگاه ورود) کار نکنند
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require 'config/db.php'; // اتصال به دیتابیس

// اگر کاربر قبلاً لاگین کرده باشد، مستقیم به داشبورد منتقل می‌شود
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

require_once __DIR__ . '/api/otp_core.php';

$error = '';
$notice = '';

// مرحله‌ی دوم ورود: پس از تایید رمز، شناسه‌ی کاربر موقتاً در نشست نگه داشته می‌شود
// تا کد یک‌بارمصرف بررسی شود. تا وقتی کد تایید نشده، user_id ست نمی‌شود.
$otpStage  = !empty($_SESSION['otp_pending_user']);
$otpMasked = $_SESSION['otp_masked'] ?? '';
$otpTtl    = intval($_SESSION['otp_ttl'] ?? 120);

function otp_finish_login($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) return false;
    session_regenerate_id(true);   // جلوگیری از تثبیت نشست
    $_SESSION['user_id']   = $u['id'];
    $_SESSION['full_name'] = $u['full_name'];
    $_SESSION['role']      = $u['role'];
    unset($_SESSION['otp_pending_user'], $_SESSION['otp_masked'], $_SESSION['otp_ttl']);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $step = $_POST['step'] ?? 'login';

    // ---------- انصراف از مرحله‌ی کد ----------
    if ($step === 'cancel') {
        unset($_SESSION['otp_pending_user'], $_SESSION['otp_masked'], $_SESSION['otp_ttl']);
        header('Location: index.php');
        exit;
    }

    // ---------- ارسال دوباره‌ی کد ----------
    if ($step === 'resend' && $otpStage) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['otp_pending_user']]);
        $u = $stmt->fetch();
        $res = $u ? otp_issue($pdo, $u, $_SERVER['REMOTE_ADDR'] ?? null) : ['ok' => false, 'error' => 'کاربر یافت نشد.'];
        if ($res['ok']) {
            $_SESSION['otp_masked'] = $res['masked'];
            $_SESSION['otp_ttl']    = $res['ttl'];
            $otpMasked = $res['masked'];
            $otpTtl     = $res['ttl'];
            $notice = 'کد جدید ارسال شد.';
        } else {
            $error = $res['error'];
        }
    }

    // ---------- بررسی کد ----------
    elseif ($step === 'otp' && $otpStage) {
        $code = trim($_POST['otp_code'] ?? '');
        $res  = otp_verify($pdo, $_SESSION['otp_pending_user'], $code);
        if ($res['ok']) {
            if (otp_finish_login($pdo, $_SESSION['otp_pending_user'])) {
                header('Location: dashboard.php');
                exit;
            }
            $error = 'خطا در تکمیل ورود.';
        } else {
            $error = $res['error'];
        }
    }

    // ---------- مرحله‌ی اول: نام کاربری و رمز ----------
    else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'لطفاً کد ملی/نام کاربری و رمز عبور را وارد کنید.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            // درگاهِ انتخاب‌شده باید با نقشِ واقعیِ حساب کاربری هم‌خوانی داشته باشد
            $gate = $_POST['login_gate'] ?? 'STAFF';
            $gateOk = $user && (
                ($gate === 'LIAISON' && $user['role'] === 'COMPANY_LIAISON') ||
                ($gate !== 'LIAISON' && in_array($user['role'], ['ADMIN', 'OPERATOR', 'FINANCE'], true))
            );

            if ($user && password_verify($password, $user['password_hash']) && !$gateOk) {
                $error = 'درگاه ورود را درست انتخاب کنید.';
            } elseif ($user && password_verify($password, $user['password_hash'])) {
                if (otp_required($pdo, $user)) {
                    $res = otp_issue($pdo, $user, $_SERVER['REMOTE_ADDR'] ?? null);
                    if ($res['ok']) {
                        $_SESSION['otp_pending_user'] = $user['id'];
                        $_SESSION['otp_masked'] = $res['masked'];
                        $_SESSION['otp_ttl']    = $res['ttl'];
                        $otpStage  = true;
                        $otpMasked = $res['masked'];
                        $otpTtl    = $res['ttl'];
                        $notice = 'کد ورود به بله‌ی شما ارسال شد.';
                    } else {
                        // اگر ارسال کد ممکن نشد، ورود انجام نمی‌شود تا امنیت حفظ بماند
                        $error = $res['error'];
                    }
                } else {
                    otp_finish_login($pdo, $user['id']);
                    header("Location: dashboard.php");
                    exit;
                }
            } else {
                $error = 'نام کاربری یا رمز عبور اشتباه است.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پورتال یکپارچه خدمات بیمه</title>
    
    <!-- اتصال سریع به سرورها -->
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- پیکربندی Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Vazir', 'sans-serif'], },
                    colors: {
                        brand: { dark: '#1e293b', light: '#f8fafc', accent: '#00d2ff' }
                    }
                }
            }
        }
    </script>
    
    <style>
        /* ================= فونت‌ها ================= */
        @font-face { font-family: 'Vazir'; src: url('https://cdn.fontcdn.ir/Font/Persian/Vazir/Vazir-Regular.woff2') format('woff2'); font-weight: normal; font-style: normal; font-display: swap; }
        @font-face { font-family: 'Vazir'; src: url('https://cdn.fontcdn.ir/Font/Persian/Vazir/Vazir-Bold.woff2') format('woff2'); font-weight: bold; font-style: normal; font-display: swap; }
        @font-face { font-family: 'Vazir'; src: url('https://cdn.fontcdn.ir/Font/Persian/Vazir/Vazir-Black.woff2') format('woff2'); font-weight: 900; font-style: normal; font-display: swap; }

        /* ================= موس اختصاصی ================= */
        /* روی دستگاهِ لمسی نشانگرِ اختصاصی روشن نمی‌شود (هم بی‌معنی است، هم cursor:none
           روی موبایل آزاردهنده است و هم بی‌خود هزینه دارد) */
        * { user-select: none; }
        .cursor-dot, .cursor-outline { display: none; }
        @media (hover: hover) and (pointer: fine) {
            * { cursor: none !important; }
            .cursor-dot, .cursor-outline { display: block; }
        }

        /* موقعیتِ نشانگر با متغیرهای CSS (--cx/--cy/--ox/--oy) و transform تنظیم می‌شود، نه
           left/top؛ چون تغییرِ left/top روی هر حرکتِ موس باعثِ layout+paint کامل صفحه
           می‌شود ولی transform فقط composite (GPU) است - همان جلوه، بدون افتِ سرعت. */
        .cursor-dot {
            position: fixed; top: 0; left: 0; width: 8px; height: 8px;
            background-color: #00d2ff; border-radius: 50%; pointer-events: none;
            z-index: 99999999 !important; transform: translate(var(--cx, -100px), var(--cy, -100px)) translate(-50%, -50%);
            box-shadow: 0 0 10px rgba(0, 210, 255, 0.6);
            transition: background-color 0.15s; will-change: transform;
        }

        /* شعاعِ حلقه کوچک‌تر شد تا نشانگر چسبیده‌تر و سریع‌تر حس شود.
           backdrop-filter از روی حلقه برداشته شد: این تنها بلورِ صفحه بود که هر فریم
           جابه‌جا می‌شد (گران‌ترین حالتِ ممکن) و با آلفای ۰.۱ عملاً دیده نمی‌شد. */
        .cursor-outline {
            position: fixed; top: 0; left: 0; width: 26px; height: 26px;
            border: 2px solid rgba(255, 255, 255, 0.5); background: rgba(255, 255, 255, 0.1);
            border-radius: 50%; pointer-events: none; z-index: 99999998 !important;
            transform: translate(var(--ox, -100px), var(--oy, -100px)) translate(-50%, -50%); transition: opacity 0.2s ease-out; will-change: transform;
        }

        /* کلاسِ حالت روی خودِ نشانگر می‌نشیند نه روی body، تا تغییرش استایلِ کلِ صفحه را باطل نکند */
        .cursor-outline.is-hover { opacity: 0; transform: translate(var(--ox, -100px), var(--oy, -100px)) translate(-50%, -50%) scale(0.5); }
        .cursor-dot.is-hover { transform: translate(var(--cx, -100px), var(--cy, -100px)) translate(-50%, -50%) scale(1.6); background-color: #ffffff; box-shadow: 0 0 15px rgba(255, 255, 255, 0.8); }
        .cursor-outline.is-active { opacity: 0 !important; }
        .cursor-dot.is-active { transform: translate(var(--cx, -100px), var(--cy, -100px)) translate(-50%, -50%) scale(2.2) !important; background-color: #00ffcc; box-shadow: 0 0 20px rgba(0, 255, 204, 0.9); }

        /* ================= پس‌زمینه ================= */
        .hero-bg {
            background-color: #1a252f; background-image: url('Image/asli1.jpg'); 
            background-size: cover; background-position: center; will-change: transform;
        }
        .hero-bg::after { content: ""; position: absolute; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1; }

        .glass-panel {
            background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2); box-shadow: 0 25px 50px rgba(0,0,0,0.3), inset 0 0 0 1px rgba(255, 255, 255, 0.1);
            color: #fff; transform: translateZ(0);
        }

        .form-section { display: block; animation: fadeIn 0.4s ease-out; }
        .hidden-form { display: none !important; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }

        .win11-menu {
            background: rgba(20, 20, 20, 0.85); backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.15); box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6); border-radius: 12px;
        }

        /* ================= سیستم نوتیفیکیشن شیشه‌ای (Toast) ================= */
        .toast {
            padding: 12px 24px; border-radius: 12px; font-weight: bold; font-size: 13px;
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.3);
            display: flex; align-items: center; gap: 10px; white-space: nowrap;
            animation: toastEnter 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }
        .toast-exit { animation: toastExit 0.4s ease-in forwards !important; }
        @keyframes toastEnter { from { opacity: 0; transform: translateY(-20px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes toastExit { from { opacity: 1; transform: translateY(0) scale(1); } to { opacity: 0; transform: translateY(-10px) scale(0.9); } }

        .toast-error { background: rgba(239, 68, 68, 0.85); border-color: rgba(255,100,100,0.5); color: #fff; }
        .toast-success { background: rgba(34, 197, 94, 0.85); border-color: rgba(100,255,100,0.5); color: #fff; }
        .toast-warning { background: rgba(234, 179, 8, 0.85); border-color: rgba(255,220,100,0.5); color: #111; }
        .toast-info { background: rgba(255, 255, 255, 0.9); border-color: rgba(255,255,255,0.5); color: #111; }

        /* ================= کپچای هوشمند و انیمیشن خطا ================= */
        .captcha-line {
            position: absolute; top: 0; bottom: 0; width: 6px; border-radius: 3px; z-index: 5;
            box-shadow: 0 0 5px rgba(255,255,255,0.5);
        }
        @keyframes shakeError {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }
        .captcha-error-anim {
            animation: shakeError 0.5s ease-in-out;
            border-color: #ef4444 !important;
            box-shadow: 0 0 15px rgba(239, 68, 68, 0.6) !important;
            background: rgba(239, 68, 68, 0.15) !important;
        }

        /* ================= فوتر و هدر اطلاعات ================= */
        .top-info-bar {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 50;
            background: rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 8px 16px;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 11px;
            font-weight: bold;
            display: flex;
            flex-direction: column;
            gap: 4px;
            pointer-events: none;
        }

        .footer-credit {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 50;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        
        .footer-box {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 10px 24px;
            border-radius: 16px;
            color: #fff;
            font-size: 13px;
            font-weight: bold;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .copyright-text {
            color: rgba(255, 255, 255, 0.5);
            font-size: 10px;
            font-weight: normal;
        }

        /* ================= دکمه‌های انتخاب درگاه ورود ================= */
        .gate-btn { background: rgba(0,0,0,0.2); border-color: rgba(255,255,255,0.15); color: #9ca3af; }
        .gate-btn.active { background: rgba(0,210,255,0.15); border-color: #00d2ff; color: #fff; box-shadow: 0 0 12px rgba(0,210,255,0.25); }

    </style>
</head>
<body class="font-sans antialiased flex flex-col items-center justify-center min-h-screen selection:bg-brand-accent selection:text-white overflow-hidden text-gray-100">

    <!-- موس اختصاصی -->
    <div class="cursor-dot"></div>
    <div class="cursor-outline"></div>

    <!-- سیستم اعلان‌ها -->
    <div id="toast-container" class="fixed top-6 left-1/2 -translate-x-1/2 z-[9999999] flex flex-col gap-3 pointer-events-none"></div>

    <!-- هدر اطلاعات -->
    <div class="top-info-bar">
        <div class="flex items-center gap-2">
            <i class="fas fa-map-marker-alt text-brand-accent opacity-80"></i>
            <span>پورتال مرکزی بیمه اشخاص</span>
        </div>
        <div class="flex items-center gap-2 mt-1 border-t border-white/10 pt-1">
            <i class="far fa-clock text-emerald-400 opacity-80"></i>
            <span id="live-datetime" dir="ltr" class="tracking-widest">در حال بارگذاری زمان...</span>
        </div>
    </div>

    <!-- منوی راست‌کلیک هوشمند -->
    <div id="contextMenu" class="win11-menu fixed z-[999999] w-56 py-2 opacity-0 pointer-events-none transform scale-95 transition-all duration-150 origin-top-left text-white">
        <ul class="text-[13px] font-bold">
            <li class="px-3 py-2 mx-1.5 my-0.5 rounded-lg hover:bg-white/10 flex items-center justify-between transition-colors group hover-target" onmousedown="event.preventDefault()" onclick="executeMenuAction('copy')">
                <div class="flex items-center gap-3"><i class="far fa-copy text-gray-400 w-4 text-center group-hover:text-white"></i> کپی</div>
                <span class="text-[10px] text-gray-500">Ctrl+C</span>
            </li>
            <li class="px-3 py-2 mx-1.5 my-0.5 rounded-lg hover:bg-white/10 flex items-center justify-between transition-colors group hover-target" onmousedown="event.preventDefault()" onclick="executeMenuAction('cut')">
                <div class="flex items-center gap-3"><i class="fas fa-cut text-gray-400 w-4 text-center group-hover:text-white"></i> برش</div>
                <span class="text-[10px] text-gray-500">Ctrl+X</span>
            </li>
            <li class="px-3 py-2 mx-1.5 my-0.5 rounded-lg hover:bg-white/10 flex items-center justify-between transition-colors group hover-target" onmousedown="event.preventDefault()" onclick="executeMenuAction('paste')">
                <div class="flex items-center gap-3"><i class="fas fa-paste text-gray-400 w-4 text-center group-hover:text-white"></i> چسباندن</div>
                <span class="text-[10px] text-gray-500">Ctrl+V</span>
            </li>
            <li class="border-t border-white/10 my-1.5 hover-target"></li>
            <li class="px-3 py-2 mx-1.5 my-0.5 rounded-lg hover:bg-white/10 flex items-center gap-3 transition-colors group hover-target" onclick="closeContextMenu(); location.reload()">
                <i class="fas fa-sync-alt text-gray-400 w-4 text-center group-hover:text-brand-accent"></i> بارگذاری مجدد
            </li>
        </ul>
    </div>

    <!-- پس‌زمینه و ذرات -->
    <div class="hero-bg fixed inset-0 z-0 pointer-events-none"></div>
    <div id="tsparticles" class="fixed inset-0 z-[1] pointer-events-none"></div>

    <!-- پنل اصلی لاگین -->
    <div class="relative z-10 glass-panel rounded-[2rem] w-[90%] max-w-md p-8 sm:p-10 transform transition-all duration-500 flex flex-col items-center" id="main-panel">
        
        <div class="text-center mb-8 w-full cursor-default">
            <div class="w-16 h-16 bg-white/10 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-white/20 shadow-lg backdrop-blur-md">
                <i class="fas fa-shield-halved text-3xl text-brand-accent"></i>
            </div>
            <h2 class="text-2xl font-black text-white drop-shadow-md">خدمات بیمه اشخاص</h2>
            <p class="text-sm text-gray-300 font-bold mt-2">پورتال یکپارچه همکاران و مشتریان</p>
        </div>

        <!-- پیام مسدودی -->
        <div id="block-message" class="hidden-form w-full text-center">
            <div class="w-16 h-16 bg-red-500/20 text-red-400 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-500/50">
                <i class="fas fa-lock text-2xl"></i>
            </div>
            <h3 class="text-red-400 font-bold text-lg mb-2">دسترسی مسدود شد</h3>
            <p class="text-sm text-gray-300 leading-relaxed">به دلیل خطاهای متعدد، سیستم جهت حفظ امنیت، ورود شما را به مدت <strong class="text-white">۱۰ دقیقه</strong> محدود کرده است.</p>
        </div>

        <div id="login-section" class="form-section w-full">
            <!-- درگاه ورود: هرکسی اول مشخص می‌کند چه‌جور کاربری است، تا با اطلاعاتِ
                 همان جدولِ کاربری وارد شود و اشتباهی به پنل دیگری هدایت نشود -->
            <div class="mb-5" id="gate-selector">
                <p class="text-[11px] font-bold text-gray-400 mb-2 text-center">من وارد می‌شوم به‌عنوان:</p>
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" data-gate="STAFF" onclick="selectGate('STAFF')" class="gate-btn py-2.5 px-1 text-[11px] font-bold rounded-xl border-2 transition-all hover-target">صدور / مدیریت</button>
                    <button type="button" data-gate="COMPANY" onclick="selectGate('COMPANY')" class="gate-btn py-2.5 px-1 text-[11px] font-bold rounded-xl border-2 transition-all hover-target">همکار شرکت‌ها</button>
                    <button type="button" data-gate="LIAISON" onclick="selectGate('LIAISON')" class="gate-btn py-2.5 px-1 text-[11px] font-bold rounded-xl border-2 transition-all hover-target">کاربر همکار بیمه با ما</button>
                </div>
            </div>

            <div class="flex bg-black/30 rounded-xl p-1 mb-6 relative z-20">
                <button class="flex-1 py-2 text-sm font-bold bg-white/20 text-white rounded-lg shadow-sm hover-target transition-all">رمز عبور</button>
                <button type="button" class="flex-1 py-2 text-sm font-bold text-gray-400 cursor-not-allowed relative group hover-target" onclick="showToast('ورود با پیامک به زودی فعال می‌شود.', 'warning')">
                    پیامک (OTP)
                </button>
            </div>

            <!-- فرم واقعی متصل به بک‌اند PHP (برای درگاه شرکتی، ارسالش با جاوااسکریپت
                 به سمت api/company_portal_auth.php تغییر مسیر داده می‌شود) -->
            <form id="login-form" method="POST" action="" class="flex-col gap-4 w-full" style="display:<?= $otpStage ? 'none' : 'flex' ?>;">
                <input type="hidden" name="login_gate" id="login-gate" value="STAFF">
                <div class="relative group/input">
                    <input type="text" name="username" id="login-nid" placeholder=" " autocomplete="off" class="peer w-full bg-black/20 border-2 border-white/20 focus:border-brand-accent outline-none rounded-xl py-3.5 pr-12 pl-4 text-sm font-bold text-white transition-all hover-target shadow-inner focus:bg-black/40">
                    <label class="absolute right-10 top-3.5 text-gray-400 text-sm font-bold transition-all duration-300 pointer-events-none peer-focus:-translate-y-[22px] peer-focus:right-4 peer-focus:text-[12px] peer-focus:text-brand-accent peer-focus:bg-[#1a252f] peer-focus:px-2 peer-focus:rounded-md peer-[:not(:placeholder-shown)]:-translate-y-[22px] peer-[:not(:placeholder-shown)]:right-4 peer-[:not(:placeholder-shown)]:text-[12px] peer-[:not(:placeholder-shown)]:text-gray-300 peer-[:not(:placeholder-shown)]:bg-[#1a252f] peer-[:not(:placeholder-shown)]:px-2 peer-[:not(:placeholder-shown)]:rounded-md">کد ملی / نام کاربری</label>
                    <i class="far fa-user absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 peer-focus:text-brand-accent transition-colors"></i>
                </div>
                
                <div class="relative group/input">
                    <input type="password" name="password" id="login-pass" placeholder=" " class="peer w-full bg-black/20 border-2 border-white/20 focus:border-brand-accent outline-none rounded-xl py-3.5 pr-12 pl-10 text-sm font-bold text-white transition-all hover-target shadow-inner focus:bg-black/40">
                    <label class="absolute right-10 top-3.5 text-gray-400 text-sm font-bold transition-all duration-300 pointer-events-none peer-focus:-translate-y-[22px] peer-focus:right-4 peer-focus:text-[12px] peer-focus:text-brand-accent peer-focus:bg-[#1a252f] peer-focus:px-2 peer-focus:rounded-md peer-[:not(:placeholder-shown)]:-translate-y-[22px] peer-[:not(:placeholder-shown)]:right-4 peer-[:not(:placeholder-shown)]:text-[12px] peer-[:not(:placeholder-shown)]:text-gray-300 peer-[:not(:placeholder-shown)]:bg-[#1a252f] peer-[:not(:placeholder-shown)]:px-2 peer-[:not(:placeholder-shown)]:rounded-md">رمز عبور</label>
                    <i class="fas fa-lock absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 peer-focus:text-brand-accent transition-colors"></i>
                    <!-- نمایش/پنهان‌سازی رمز: آیکونِ درون‌خطی (SVG) تا اگر CDN فونت‌آوسام نیامد هم دکمه ناحیه‌ی کلیک داشته باشد -->
                    <button type="button" id="togglePass" aria-label="نمایش رمز عبور" title="نمایش / پنهان‌سازی رمز عبور" class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white hover-target transition-colors">
                        <svg id="togglePass-on" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg id="togglePass-off" style="display:none" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
                    </button>
                </div>

                <!-- کپچای هوشمند -->
                <div class="flex flex-col w-full mt-1">
                    <div id="captcha-box" class="relative bg-black/30 border border-white/20 rounded-xl h-10 w-full flex items-center justify-center overflow-hidden hover-target transition-all duration-300">
                        <div id="captcha-track" class="absolute right-0 top-0 bottom-0 bg-gradient-to-r from-emerald-500/40 to-teal-400/40 w-0 z-0 transition-all duration-100"></div>
                        <div id="line-1" class="captcha-line hidden"></div>
                        <div id="line-2" class="captcha-line hidden"></div>
                        <div id="captcha-handle" class="absolute right-1 top-1 bottom-1 w-12 bg-white/90 backdrop-blur-md rounded-lg flex items-center justify-center z-20 shadow-[0_0_8px_rgba(0,0,0,0.5)] transition-all duration-100 hover:bg-white cursor-none">
                            <i class="fas fa-angle-double-left text-brand-dark text-lg pointer-events-none"></i>
                        </div>
                    </div>
                    <div id="captcha-text" class="w-full text-center text-[12.5px] font-bold text-gray-300 drop-shadow-md transition-all mt-3" style="word-spacing: 2px;">
                        در حال ساخت کپچا...
                    </div>
                </div>

                <button type="submit" id="login-btn" class="w-full bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-500 hover:to-cyan-400 text-white font-black py-3.5 rounded-xl shadow-lg hover:shadow-cyan-500/30 transition-all duration-300 hover-target transform hover:-translate-y-1 mt-1">
                    ورود به سیستم
                </button>
            </form>

            <!-- ======================= مرحله‌ی دوم: کد یک‌بارمصرف ======================= -->
            <form id="otp-form" method="POST" action="" class="flex-col gap-4 w-full" style="display:<?= $otpStage ? 'flex' : 'none' ?>;">
                <input type="hidden" name="step" value="otp">

                <div class="text-center">
                    <div class="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-br from-blue-500/30 to-cyan-400/20 border border-white/20 flex items-center justify-center mb-3">
                        <i class="fas fa-comment-dots text-2xl text-brand-accent"></i>
                    </div>
                    <p class="text-sm font-black text-white">کد ورود را وارد کنید</p>
                    <p class="text-[12px] text-gray-400 mt-1">کد شش‌رقمی به بله‌ی شماره <b dir="ltr" class="text-brand-accent"><?= htmlspecialchars($otpMasked) ?></b> ارسال شد.</p>
                </div>

                <input type="text" name="otp_code" id="otp-code" inputmode="numeric" maxlength="6"
                       autocomplete="one-time-code" placeholder="- - - - - -"
                       class="w-full bg-black/25 border-2 border-white/20 focus:border-brand-accent outline-none rounded-xl py-3.5 text-center text-2xl font-black text-white tracking-[0.5em] transition-all shadow-inner focus:bg-black/40"
                       dir="ltr">

                <button type="submit" class="w-full bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-500 hover:to-teal-400 text-white font-black py-3.5 rounded-xl shadow-lg transition-all duration-300 hover-target transform hover:-translate-y-1">
                    تایید و ورود
                </button>

                <div class="flex items-center justify-between text-[12px]">
                    <button type="button" id="otp-resend" disabled
                            class="text-gray-400 disabled:opacity-50 hover:text-brand-accent transition-colors font-bold">
                        ارسال دوباره‌ی کد <span id="otp-timer" dir="ltr"></span>
                    </button>
                    <button type="button" onclick="document.getElementById('cancel-form').submit()"
                            class="text-gray-400 hover:text-red-400 transition-colors font-bold">بازگشت</button>
                </div>
            </form>

            <form id="resend-form" method="POST" action="" class="hidden"><input type="hidden" name="step" value="resend"></form>
            <form id="cancel-form" method="POST" action="" class="hidden"><input type="hidden" name="step" value="cancel"></form>
        </div>
    </div>

    <!-- فوتر -->
    <div class="footer-credit">
        <div class="footer-box group/author hover-target">
            <span>طراحی و اجرا :</span>
            <span class="text-brand-accent font-black mr-1 relative inline-block cursor-none">
                گروه توسعه بـــرگه
            </span>
            <span class="text-gray-300 text-xs ml-1" style="font-family: Arial, sans-serif;">(MMBehzadi)</span>
        </div>
        <p class="copyright-text">تمامی حقوق مادی و معنوی محفوظ می‌باشد - ۲۰۲۶ ©</p>
    </div>

    <!-- بارگذاری ذرات -->
    <script src="https://cdn.jsdelivr.net/npm/tsparticles@2.12.0/tsparticles.bundle.min.js"></script>

    <script>
        // نمایش خطای لاگین پی‌اچ‌پی با Toast
        // نکته: از json_encode استفاده می‌شود تا اگر متن پیام شامل کوتیشن یا خط جدید بود،
        // کد جاوااسکریپت نشکند
        <?php if (!empty($error)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                showToast(<?php echo json_encode($error, JSON_UNESCAPED_UNICODE); ?>, "error");
            });
        <?php endif; ?>
        <?php if (!empty($notice)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                showToast(<?php echo json_encode($notice, JSON_UNESCAPED_UNICODE); ?>, "success");
            });
        <?php endif; ?>

        // ======================= مرحله‌ی کد یک‌بارمصرف =======================
        <?php if ($otpStage): ?>
        (function () {
            const input  = document.getElementById('otp-code');
            const btn    = document.getElementById('otp-resend');
            const timerEl= document.getElementById('otp-timer');
            const form   = document.getElementById('otp-form');

            if (input) {
                input.focus();
                // فقط رقم بپذیر (ارقام فارسی هم به لاتین تبدیل شوند) و با تکمیل شدن، خودکار ارسال کن
                input.addEventListener('input', () => {
                    const fa = '۰۱۲۳۴۵۶۷۸۹';
                    let v = input.value.replace(/[۰-۹]/g, d => fa.indexOf(d)).replace(/\D/g, '');
                    input.value = v.slice(0, 6);
                    if (input.value.length === 6) form.submit();
                });
            }

            // شمارش معکوس تا فعال‌شدن «ارسال دوباره»
            let left = <?= max(30, (int)$otpTtl) ?>;
            const tick = () => {
                if (left <= 0) {
                    btn.disabled = false;
                    timerEl.textContent = '';
                    return;
                }
                const m = String(Math.floor(left / 60)).padStart(2, '0');
                const s = String(left % 60).padStart(2, '0');
                timerEl.textContent = `(${m}:${s})`;
                left--;
                setTimeout(tick, 1000);
            };
            tick();

            btn.addEventListener('click', () => {
                if (!btn.disabled) document.getElementById('resend-form').submit();
            });
        })();
        <?php endif; ?>

        // ۱. ساعت و تاریخ شمسی
        function updateDateTime() {
            const now = new Date();
            const formatter = new Intl.DateTimeFormat('fa-IR', {
                calendar: 'persian', numberingSystem: 'latn',
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
            });
            const e2p = s => s.replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
            const parts = formatter.formatToParts(now);
            
            let year, month, day, hour, minute, second;
            parts.forEach(p => {
                if(p.type === 'year') year = p.value;
                if(p.type === 'month') month = p.value;
                if(p.type === 'day') day = p.value;
                if(p.type === 'hour') hour = p.value;
                if(p.type === 'minute') minute = p.value;
                if(p.type === 'second') second = p.value;
            });
            
            const dateStr = e2p(`${year}/${month}/${day}`);
            const timeStr = e2p(`${hour}:${minute}:${second}`);
            document.getElementById('live-datetime').innerHTML = `${timeStr} - ${dateStr}`;
        }
        setInterval(updateDateTime, 1000); updateDateTime();

        // ۲. سیستم اعلان‌ها
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            let icon = 'fa-info-circle';
            if(type === 'error') icon = 'fa-times-circle';
            if(type === 'success') icon = 'fa-check-circle';
            if(type === 'warning') icon = 'fa-exclamation-triangle';

            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas ${icon} text-lg"></i> <span>${message}</span>`;
            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('toast-exit');
                setTimeout(() => toast.remove(), 400); 
            }, 3500);
        }

        // ۳. موس گرافیکی
        (function initCustomCursor() {
            const dot = document.querySelector('.cursor-dot');
            const outline = document.querySelector('.cursor-outline');
            if (!dot || !outline) return;
            if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

            // -100 یعنی پیش از اولین حرکتِ موس هر دو بخش بیرونِ صفحه‌اند
            let mx = -100, my = -100, ox = -100, oy = -100, running = false;

            // همه‌ی نوشتن‌ها یک بار در هر فریم انجام می‌شود (نه در هر رویدادِ mousemove که
            // روی موس‌های پرسرعت چند بار در یک فریم می‌آید)، و حلقه به‌محض رسیدنِ حلقه‌ی
            // بیرونی به موس می‌خوابد - پس در حالتِ بی‌حرکت هیچ کاری انجام نمی‌شود.
            const EASE = 0.35; // تندتر از قبل (۰.۲)
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
            window.addEventListener('mousedown', () => { dot.classList.add('is-active'); outline.classList.add('is-active'); });
            window.addEventListener('mouseup', () => { dot.classList.remove('is-active'); outline.classList.remove('is-active'); });

            // یک شنونده‌ی واگذارشده روی document، به‌جای دو شنونده به‌ازای هر عنصر؛
            // این‌طور هر چیزی که بعداً ساخته شود هم جلوه را می‌گیرد.
            const HOVER_SELECTOR = 'a, button, input, select, textarea, label, li, summary, .hover-target, [onclick], [role="button"]';
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

        // ۴. ذرات پس‌زمینه
        window.addEventListener('load', () => {
            tsParticles.load("tsparticles", {
                fullScreen: { enable: false },
                particles: {
                    number: { value: 40, density: { enable: true, area: 900 } },
                    color: { value: "#00d2ff" }, shape: { type: "circle" },
                    opacity: { value: 0.3, random: true }, size: { value: 3, random: true },
                    links: { enable: true, distance: 150, color: "#ffffff", opacity: 0.1, width: 1 },
                    move: { enable: true, speed: 0.6, direction: "none", outModes: "bounce" }
                },
                interactivity: { detectsOn: "window", events: { onHover: { enable: true, mode: "grab" }, resize: true }, modes: { grab: { distance: 180, links: { opacity: 0.4, color: "#00d2ff" } } } },
                detectRetina: true
            });
        });

        // ۵. منوی راست‌کلیک
        const contextMenu = document.getElementById('contextMenu');
        function closeContextMenu() {
            contextMenu.classList.remove('opacity-100', 'scale-100', 'pointer-events-auto');
            contextMenu.classList.add('opacity-0', 'scale-95', 'pointer-events-none');
        }
        function executeMenuAction(action) {
            if (action === 'copy') document.execCommand('copy');
            else if (action === 'cut') document.execCommand('cut');
            else if (action === 'paste') {
                if (navigator.clipboard && navigator.clipboard.readText) {
                    navigator.clipboard.readText().then(text => {
                        const activeEl = document.activeElement;
                        if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) {
                            const start = activeEl.selectionStart; const end = activeEl.selectionEnd;
                            const val = activeEl.value;
                            activeEl.value = val.slice(0, start) + text + val.slice(end);
                            activeEl.selectionStart = activeEl.selectionEnd = start + text.length;
                            activeEl.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }).catch(err => showToast('دسترسی به کلیپ‌بورد مسدود است.', 'error'));
                }
            }
            closeContextMenu();
        }

        window.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            let menuWidth = 224, menuHeight = 220; 
            let posX = e.clientX, posY = e.clientY;
            if (posX + menuWidth > window.innerWidth) posX = window.innerWidth - menuWidth - 5;
            if (posY + menuHeight > window.innerHeight) posY = window.innerHeight - menuHeight - 5;
            contextMenu.style.left = `${posX}px`; contextMenu.style.top = `${posY}px`;
            contextMenu.classList.add('opacity-100', 'scale-100', 'pointer-events-auto');
            contextMenu.classList.remove('opacity-0', 'scale-95', 'pointer-events-none');
        });
        window.addEventListener('click', (e) => { if(!contextMenu.contains(e.target)) closeContextMenu(); });

        // ۶. کپچای هوشمند
        const captchaBox = document.getElementById('captcha-box');
        const captchaHandle = document.getElementById('captcha-handle');
        const captchaTrack = document.getElementById('captcha-track');
        const captchaText = document.getElementById('captcha-text');
        const line1 = document.getElementById('line-1');
        const line2 = document.getElementById('line-2');
        
        const captchaColors = [
            { name: 'قرمز', code: '#ef4444' }, { name: 'سبز', code: '#10b981' },
            { name: 'آبی', code: '#3b82f6' }, { name: 'زرد', code: '#eab308' },
            { name: 'بنفش', code: '#a855f7' }, { name: 'نارنجی', code: '#f97316' }
        ];

        let isDragging = false, isVerified = false, targetLinePos = 0, failedAttempts = 0;

        function initCaptcha() {
            isVerified = false;
            captchaBox.className = "relative bg-black/30 border border-white/20 rounded-xl h-10 w-full flex items-center justify-center overflow-hidden hover-target transition-all duration-300";
            captchaHandle.style.transition = 'all 0.3s ease'; captchaTrack.style.transition = 'all 0.3s ease';
            captchaHandle.style.right = '4px'; captchaTrack.style.width = '0px';
            captchaHandle.innerHTML = '<i class="fas fa-angle-double-left text-brand-dark text-lg pointer-events-none"></i>';

            let shuffled = [...captchaColors].sort(() => 0.5 - Math.random());
            let targetColor = shuffled[0], fakeColor = shuffled[1];
            let posA = Math.floor(Math.random() * (55 - 40 + 1) + 40);
            let posB = Math.floor(Math.random() * (90 - 75 + 1) + 75);
            let isTargetFirst = Math.random() > 0.5;
            targetLinePos = isTargetFirst ? posA : posB;

            line1.style.right = `${posA}%`; line1.style.backgroundColor = isTargetFirst ? targetColor.code : fakeColor.code; line1.classList.remove('hidden');
            line2.style.right = `${posB}%`; line2.style.backgroundColor = isTargetFirst ? fakeColor.code : targetColor.code; line2.classList.remove('hidden');

            captchaText.className = 'w-full text-center text-[12.5px] font-bold text-gray-300 drop-shadow-md transition-all mt-3';
            captchaText.innerHTML = `لطفاً دکمه را روی خط <span style="color:${targetColor.code}; font-size:14px; margin: 0 4px;">${targetColor.name}</span> رها کنید`;
        }

        const getClientX = (e) => e.touches ? e.touches[0].clientX : e.clientX;
        const startDrag = () => { if (isVerified) return; isDragging = true; captchaHandle.style.transition = 'none'; captchaTrack.style.transition = 'none'; captchaBox.classList.remove('captcha-error-anim'); };
        
        const onDrag = (e) => {
            if (!isDragging || isVerified) return;
            const boxRect = captchaBox.getBoundingClientRect();
            let draggedDist = boxRect.right - getClientX(e) - (captchaHandle.offsetWidth / 2);
            const maxDist = boxRect.width - captchaHandle.offsetWidth - 8;
            if (draggedDist < 0) draggedDist = 0; if (draggedDist > maxDist) draggedDist = maxDist;
            captchaHandle.style.right = `${draggedDist + 4}px`; captchaTrack.style.width = `${draggedDist + (captchaHandle.offsetWidth / 2)}px`;
        };

        const endDrag = () => {
            if (!isDragging || isVerified) return;
            isDragging = false;
            const boxWidth = captchaBox.offsetWidth;
            const currentRightPx = parseInt(captchaHandle.style.right) || 0;
            const currentPercent = ((currentRightPx + (captchaHandle.offsetWidth / 2)) / boxWidth) * 100;

            if (Math.abs(currentPercent - targetLinePos) <= 8) {
                isVerified = true; failedAttempts = 0;
                captchaHandle.style.transition = 'all 0.3s ease';
                captchaHandle.innerHTML = '<i class="fas fa-check text-emerald-600 text-lg"></i>';
                captchaText.innerText = 'تایید انسان بودن موفق بود!';
                captchaText.classList.replace('text-gray-300', 'text-white');
                captchaBox.classList.replace('bg-black/30', 'bg-emerald-500/20');
                captchaBox.classList.replace('border-white/20', 'border-emerald-500/50');
            } else {
                failedAttempts++; showToast(`تشخیص اشتباه! (${failedAttempts}/5)`, 'error');
                captchaBox.classList.add('captcha-error-anim'); setTimeout(() => captchaBox.classList.remove('captcha-error-anim'), 500);
                if (failedAttempts >= 5) {
                    showToast('دسترسی شما مسدود شد.', 'error');
                    document.getElementById('login-section').classList.add('hidden-form');
                    document.getElementById('block-message').classList.remove('hidden-form');
                    return;
                }
                initCaptcha();
            }
        };

        captchaHandle.addEventListener('mousedown', startDrag); captchaHandle.addEventListener('touchstart', startDrag, {passive: true});
        window.addEventListener('mousemove', onDrag); window.addEventListener('touchmove', onDrag, {passive: true});
        window.addEventListener('mouseup', endDrag); window.addEventListener('touchend', endDrag);
        initCaptcha();

        // ۰. انتخاب درگاه ورود (صدور/مدیریت - همکار شرکت‌ها - کاربر شرکتی)
        function selectGate(gate) {
            document.getElementById('login-gate').value = gate;
            document.querySelectorAll('.gate-btn').forEach(b => b.classList.toggle('active', b.dataset.gate === gate));
        }
        selectGate('STAFF');

        // ورود کاربرِ شرکتی از جدول جدایی (company_portal_users) می‌آید و OTP بله ندارد؛
        // به‌جای ارسال فرم اصلی، مستقیم به بک‌اندِ پنل شرکت‌ها وصل می‌شویم
        async function submitCompanyGateLogin() {
            const username = document.getElementById('login-nid').value.trim();
            const password = document.getElementById('login-pass').value;
            if (!username || !password) { showToast('نام کاربری و رمز عبور را وارد کنید.', 'error'); return; }
            loginBtn.disabled = true;
            loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin ml-2"></i> در حال احراز هویت...';
            try {
                const res = await fetch('api/company_portal_auth.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'login', username, password})
                });
                const data = await res.json();
                if (data.ok) { location.href = 'company-portal/index.php'; }
                else {
                    showToast(data.error || 'خطا در ورود.', 'error');
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = 'ورود به سیستم';
                }
            } catch (e) {
                showToast('خطا در ارتباط با سرور.', 'error');
                loginBtn.disabled = false;
                loginBtn.innerHTML = 'ورود به سیستم';
            }
        }

        // ۷. منطق فرم و نمایش رمز عبور
        const togglePass = document.getElementById('togglePass');
        const loginPass = document.getElementById('login-pass');
        const loginForm = document.getElementById('login-form');
        const loginBtn = document.getElementById('login-btn');

        const togglePassOn = document.getElementById('togglePass-on');
        const togglePassOff = document.getElementById('togglePass-off');

        togglePass.addEventListener('click', () => {
            const type = loginPass.getAttribute('type') === 'password' ? 'text' : 'password';
            loginPass.setAttribute('type', type);
            const shown = type === 'text';
            togglePassOn.style.display = shown ? 'none' : '';
            togglePassOff.style.display = shown ? '' : 'none';
            togglePass.setAttribute('aria-label', shown ? 'پنهان‌سازی رمز عبور' : 'نمایش رمز عبور');
        });

        // جلوگیری از ارسال فرم در صورت حل نشدن کپچا
        loginForm.addEventListener('submit', (e) => {
            if(!isVerified) {
                e.preventDefault(); // فرم ارسال نشود
                showToast("ابتدا تست امنیتی (کپچا) را انجام دهید.", "error");
                captchaBox.classList.add('captcha-error-anim');
                setTimeout(() => captchaBox.classList.remove('captcha-error-anim'), 500);
            } else if (document.getElementById('login-gate').value === 'COMPANY') {
                // درگاه شرکتی از بک‌اند جدایی می‌آید؛ فرم اصلی (که برای users/OTP است) ارسال نشود
                e.preventDefault();
                submitCompanyGateLogin();
            } else {
                loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin ml-2"></i> در حال احراز هویت...';
            }
        });
    </script>
</body>
</html>