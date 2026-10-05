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

require_once __DIR__ . '/api/_case_helpers.php';
require_once __DIR__ . '/api/_auth_helpers.php';

$error = '';
$notice = '';
if (isset($_GET['idle'])) $notice = 'به دلیلِ عدمِ فعالیت از پنل خارج شدید؛ دوباره وارد شوید.';
if (isset($_GET['forced'])) $notice = 'مدیر سامانه نشستِ شما را بست؛ برای ادامه دوباره وارد شوید.';
$authReady = auth_schema_ready($pdo);

// ورود با نام کاربری و رمز. (ورود با کد، جدا و با api/otp_login.php انجام می‌شود.)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'لطفاً کد ملی/نام کاربری و رمز عبور را وارد کنید.';
    } elseif ($lockMin = sec_login_locked($pdo, $username)) {
        // محدودیتِ سمتِ سرور (کپچا فقط در مرورگر است): بعد از چند تلاشِ ناموفق، ورود برای مدتی قفل می‌شود
        $error = 'به دلیلِ تلاش‌های ناموفقِ زیاد، ورود برای ' . strtr((string)$lockMin, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']) . ' دقیقه قفل شد؛ کمی بعد دوباره امتحان کنید.';
    } else {
        // کاربرِ حذف‌شده دیگر نمی‌تواند وارد شود (ولی نامش روی کارهای قبلی‌اش می‌ماند)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?" . ($authReady ? " AND COALESCE(is_deleted, 0) = 0" : ''));
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // درگاهِ «همکار بیمه»: همه‌ی نقش‌های داخلی (مدیر کل، کارشناس صدور، کارشناس مالی، کارمند بیمه با ما - عادی و پارسیان)
        // کاربرِ شرکت از درگاهِ «کارشناس شرکت‌ها» وارد می‌شود (api/company_portal_auth.php)
        $gateOk = $user && in_array($user['role'], ['ADMIN', 'OPERATOR', 'FINANCE', 'COMPANY_LIAISON', 'PARSIAN'], true);

        if ($user && password_verify($password, $user['password_hash']) && !$gateOk) {
            $error = 'نقشِ این حساب برای ورود تعریف نشده است؛ با مدیر کل تماس بگیرید.';
        } elseif (!$user) {
            // شاید کاربرِ شرکت باشد که درگاهِ اشتباه را انتخاب کرده
            $isCompany = false;
            try {
                $st = $pdo->prepare("SELECT password_hash FROM company_portal_users WHERE username = ? AND is_active = 1" . ($authReady ? " AND COALESCE(is_deleted, 0) = 0" : ''));
                $st->execute([$username]);
                $h = $st->fetchColumn();
                $isCompany = $h && password_verify($password, $h);
            } catch (Throwable $e) {}
            if (!$isCompany) sec_login_failed($pdo, $username, 'ورودِ همکارِ بیمه');
            $error = $isCompany ? 'این حساب «کاربر شرکت» است؛ بالای فرم «کارشناس شرکت‌ها» را انتخاب کنید.' : 'نام کاربری یا رمز عبور اشتباه است.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);   // جلوگیری از تثبیت نشست
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            auth_log_login($pdo, 'STAFF', $user, 'PASSWORD', true);
            sec_on_login($pdo, 'STAFF', $user['id'], $user['full_name']);
            header("Location: dashboard.php");
            exit;
        } else {
            if ($user) auth_log_login($pdo, 'STAFF', $user, 'PASSWORD', false, 'رمز اشتباه');
            sec_login_failed($pdo, $username, 'ورودِ همکارِ بیمه');
            $error = 'نام کاربری یا رمز عبور اشتباه است.';
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
    <?php require_once __DIR__ . '/api/_brand.php'; echo brand_favicon_tag($pdo); ?>
    
    <!-- اتصال سریع به سرورها -->
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- پیکربندی Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        if (window.tailwind) tailwind.config = {
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


        /* ================= ورود با کد (بله) ================= */
        .mode-tab { color: #9ca3af; background: transparent; }
        .mode-tab.on { background: rgba(255,255,255,.2); color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
        .otp-wrap { animation: fadeIn .35s ease-out; }
        .otp-hint { font-size: 12px; color: #cbd5e1; text-align: center; line-height: 1.9; margin-bottom: 14px; }
        .phone-box { position: relative; display: flex; align-items: center; justify-content: center; gap: 10px; height: 64px; border-radius: 16px;
                     background: rgba(0,0,0,.25); border: 2px solid rgba(255,255,255,.2); transition: border-color .25s, box-shadow .25s, background .25s; overflow: hidden; }
        .phone-box.focus { border-color: #00d2ff; box-shadow: 0 0 0 4px rgba(0,210,255,.15), 0 0 24px rgba(0,210,255,.25); background: rgba(0,0,0,.4); }
        .phone-box.err { animation: shakeError .5s; border-color: #ef4444; }
        .phone-box.ok { border-color: #22c55e; box-shadow: 0 0 22px rgba(34,197,94,.35); }
        .phone-ico { position: absolute; right: 16px; color: #94a3b8; font-size: 18px; transition: color .25s; }
        .phone-box.focus .phone-ico { color: #00d2ff; }
        .phone-box input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; font-size: 16px; }
        .phone-digits { display: flex; gap: 3px; font: 900 24px/1 Vazir, sans-serif; letter-spacing: 1px; color: #fff; pointer-events: none; }
        .phone-digits .d { display: inline-block; min-width: 15px; text-align: center; animation: digitIn .32s cubic-bezier(.2,1.4,.4,1) both; text-shadow: 0 0 12px rgba(0,210,255,.55); }
        .phone-digits .gap { width: 8px; }
        .phone-digits .ph { color: rgba(255,255,255,.22); animation: none; text-shadow: none; }
        .phone-digits .caret { width: 2px; height: 26px; background: #00d2ff; border-radius: 2px; animation: blink 1s steps(2) infinite; align-self: center; }
        @keyframes digitIn { 0% { opacity: 0; transform: translateY(14px) scale(.4) rotate(-12deg); filter: blur(4px); } 70% { transform: translateY(-3px) scale(1.18); } 100% { opacity: 1; transform: none; filter: none; } }
        @keyframes blink { to { opacity: 0; } }
        .otp-btn { margin-top: 14px; width: 100%; display: flex; align-items: center; justify-content: center; gap: 10px; padding: 14px; border: 0; border-radius: 14px;
                   background: linear-gradient(90deg, #2563eb, #06b6d4); color: #fff; font: 900 15px Vazir, sans-serif; cursor: pointer; transition: transform .2s, box-shadow .2s, opacity .2s; }
        .otp-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(6,182,212,.3); }
        .otp-btn:disabled { opacity: .6; transform: none; }
        .otp-msg { margin-top: 12px; border-radius: 12px; padding: 10px 12px; font-size: 12.5px; line-height: 1.9; text-align: center; animation: fadeIn .3s ease-out; }
        .otp-msg.err { background: rgba(239,68,68,.18); border: 1px solid rgba(239,68,68,.5); color: #fecaca; }
        .otp-msg.warn { background: rgba(234,179,8,.16); border: 1px solid rgba(234,179,8,.5); color: #fde68a; }
        .otp-msg a { color: #67e8f9; font-weight: 900; text-decoration: underline; }
        .otp-choose { margin-top: 12px; display: grid; gap: 8px; }
        .otp-choose button { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,.2);
                             background: rgba(0,0,0,.25); color: #fff; font: 700 13px Vazir, sans-serif; cursor: pointer; }
        .otp-choose button:hover { border-color: #00d2ff; }
        .otp-choose small { color: #67e8f9; font-weight: 900; }
        .otp-who { text-align: center; margin-bottom: 16px; animation: fadeIn .35s ease-out; }
        .otp-role { display: inline-block; font-size: 11px; font-weight: 900; color: #0f172a; background: #67e8f9; border-radius: 999px; padding: 2px 12px; }
        .otp-name { font-size: 20px; font-weight: 900; color: #fff; margin-top: 8px; }
        .otp-sub { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .otp-boxes { display: flex; justify-content: center; gap: 10px; }
        .otp-boxes input { width: 52px; height: 60px; border-radius: 14px; text-align: center; font: 900 26px Vazir, sans-serif; color: #fff; background: rgba(0,0,0,.28);
                           border: 2px solid rgba(255,255,255,.2); outline: none; transition: border-color .2s, box-shadow .2s, transform .2s, background .3s; caret-color: #00d2ff; }
        .otp-boxes input:focus { border-color: #00d2ff; box-shadow: 0 0 0 4px rgba(0,210,255,.15); transform: translateY(-2px); }
        .otp-boxes input.filled { animation: boxPop .25s ease-out; border-color: rgba(0,210,255,.7); }
        .otp-boxes.ok input { border-color: #22c55e; background: rgba(34,197,94,.25); box-shadow: 0 0 18px rgba(34,197,94,.45); animation: boxOk .5s ease-out both; }
        .otp-boxes.ok input:nth-child(2) { animation-delay: .06s; } .otp-boxes.ok input:nth-child(3) { animation-delay: .12s; }
        .otp-boxes.ok input:nth-child(4) { animation-delay: .18s; } .otp-boxes.ok input:nth-child(5) { animation-delay: .24s; }
        .otp-boxes.bad input { border-color: #ef4444; background: rgba(239,68,68,.22); box-shadow: 0 0 16px rgba(239,68,68,.45); }
        .otp-boxes.bad { animation: shakeError .5s ease-in-out; }
        .otp-boxes.busy input { opacity: .6; }
        @keyframes boxPop { 50% { transform: scale(1.14); } }
        @keyframes boxOk { 0% { transform: scale(1); } 40% { transform: scale(1.18) translateY(-4px); } 100% { transform: scale(1); } }
        .otp-foot { display: flex; justify-content: space-between; margin-top: 16px; }
        .otp-link { background: none; border: 0; color: #94a3b8; font: 700 12px Vazir, sans-serif; cursor: pointer; }
        .otp-link:hover:not(:disabled) { color: #67e8f9; }
        .otp-link:disabled { opacity: .5; }
        @media (max-width: 380px) { .otp-boxes input { width: 46px; height: 54px; } }
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

        /* موبایل و صفحه‌های کوتاه: نوارِ بالا و فوتر در جریانِ صفحه می‌آیند (نه ثابت روی فرم) و صفحه اسکرول می‌خورد،
           تا فوتر هیچ‌وقت روی دکمه‌ها نیفتد */
        @media (max-width: 640px), (max-height: 780px) {
            body { overflow-y: auto !important; overflow-x: hidden !important; justify-content: flex-start !important; padding: 14px 0 18px; min-height: 100vh; min-height: 100dvh; }
            .top-info-bar { position: relative; top: auto; right: auto; margin: 0 auto 14px; align-self: center; }
            #main-panel { margin: auto 0; flex-shrink: 0; }
            .footer-credit { position: relative; bottom: auto; left: auto; transform: none; margin: 18px auto 0; padding: 0 12px; flex-shrink: 0; }
            .footer-box { padding: 8px 14px; font-size: 11px; text-align: center; }
        }
        @media (max-width: 640px) {
            #main-panel { width: calc(100% - 28px); padding: 22px 16px; box-sizing: border-box; }
        }

        /* ================= انتخابِ درگاهِ ورود: همکارِ بیمه | کارشناسِ شرکت‌ها ================= */
        :root { --acc: #00d2ff; --acc2: #2563eb; }
        body.gate-company { --acc: #34d399; --acc2: #0d9488; }
        .login-logo { background: linear-gradient(140deg, color-mix(in srgb, var(--acc) 30%, transparent), rgba(255,255,255,.06)); border: 1px solid color-mix(in srgb, var(--acc) 55%, transparent); transition: .4s; }
        .login-logo i { color: var(--acc); transition: .4s; }
        .gate2 { position: relative; display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 6px; border-radius: 20px; background: rgba(0,0,0,.28); border: 1px solid rgba(255,255,255,.1); }
        .gate2-pill { position: absolute; top: 6px; bottom: 6px; width: calc(50% - 9px); right: 6px; border-radius: 15px; background: linear-gradient(135deg, var(--acc2), var(--acc)); box-shadow: 0 10px 26px -10px var(--acc); transition: transform .45s cubic-bezier(.65,0,.35,1), background .4s; }
        body.gate-company .gate2-pill { transform: translateX(calc(-100% - 6px)); }
        .gate2-btn { position: relative; z-index: 1; display: flex; align-items: center; gap: 10px; padding: 11px 12px; border-radius: 15px; text-align: right; color: #cbd5e1; transition: color .3s; }
        .gate2-btn i { width: 34px; height: 34px; border-radius: 11px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.08); font-size: 15px; flex-shrink: 0; transition: .3s; }
        .gate2-btn b { display: block; font-size: 13px; font-weight: 900; } .gate2-btn small { display: block; font-size: 10px; font-weight: 700; opacity: .75; margin-top: 1px; }
        .gate2-btn.active { color: #fff; } .gate2-btn.active i { background: rgba(255,255,255,.22); }
        #login-btn { background: linear-gradient(120deg, var(--acc2), var(--acc)) !important; transition: .4s; }
        #login-mode-tabs .mode-tab.on { background: color-mix(in srgb, var(--acc) 22%, transparent); color: #fff; }
        #main-panel { transition: box-shadow .4s; box-shadow: 0 30px 80px -30px color-mix(in srgb, var(--acc) 45%, transparent); }
        .gate-swap { animation: gateSwap .45s ease-out; }
        @keyframes gateSwap { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        @media (max-width: 380px) { .gate2-btn small { display: none; } }

    </style>
<link rel="stylesheet" href="ui-scroll.css?v=1">
</head>
<body class="scroll-dark font-sans antialiased flex flex-col items-center justify-center min-h-screen selection:bg-brand-accent selection:text-white overflow-hidden text-gray-100">

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
            <div class="login-logo w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg backdrop-blur-md">
                <i id="gate-ico" class="fas fa-shield-halved text-3xl"></i>
            </div>
            <h2 class="text-2xl font-black text-white drop-shadow-md" id="gate-title">ورودِ همکارانِ بیمه با ما</h2>
            <p class="text-sm text-gray-300 font-bold mt-2" id="gate-sub">مدیریت، صدور، مالی و کارمندانِ بیمه با ما</p>
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
            <div class="gate2 mb-5" id="gate-selector">
                <span class="gate2-pill" id="gate-pill"></span>
                <button type="button" data-gate="STAFF" onclick="selectGate('STAFF')" class="gate2-btn hover-target">
                    <i class="fas fa-user-shield"></i><span><b>همکارِ بیمه</b><small>مدیر، صدور، مالی، کارمند</small></span>
                </button>
                <button type="button" data-gate="COMPANY" onclick="selectGate('COMPANY')" class="gate2-btn hover-target">
                    <i class="fas fa-building"></i><span><b>کارشناسِ شرکت‌ها</b><small>کاربرانِ شرکت‌ها</small></span>
                </button>
            </div>

            <div class="flex bg-black/30 rounded-xl p-1 mb-6 relative z-20" id="login-mode-tabs">
                <button type="button" data-mode="pass" onclick="setLoginMode('pass')" class="mode-tab flex-1 py-2 text-sm font-bold rounded-lg transition-all hover-target">رمز عبور</button>
                <button type="button" data-mode="otp" onclick="setLoginMode('otp')" class="mode-tab flex-1 py-2 text-sm font-bold rounded-lg transition-all hover-target">
                    <i class="fas fa-paper-plane ml-1"></i>ورود با کد (بله)
                </button>
            </div>

            <!-- فرم واقعی متصل به بک‌اند PHP (برای درگاه شرکتی، ارسالش با جاوااسکریپت
                 به سمت api/company_portal_auth.php تغییر مسیر داده می‌شود) -->
            <form id="login-form" method="POST" action="" class="flex-col gap-4 w-full" style="display:flex;">
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

            <!-- ======================= ورود با کد (ربات بله‌ی شرکت‌ها) ======================= -->
            <div id="otp-login" class="otp-wrap" style="display:none;">
                <!-- ۱) شماره تلفن -->
                <div id="otp-step-phone">
                    <p class="otp-hint">شماره موبایلی که در پنل برای شما ثبت شده را وارد کنید؛ کد ورود در <b>ربات بله‌ی شرکت</b> برایتان فرستاده می‌شود.</p>
                    <label class="phone-box" id="phone-box" for="otp-phone">
                        <i class="fas fa-mobile-screen-button phone-ico"></i>
                        <span class="phone-digits" id="phone-digits" dir="ltr"></span>
                        <input id="otp-phone" type="tel" inputmode="numeric" autocomplete="tel" maxlength="14" dir="ltr" aria-label="شماره موبایل">
                    </label>
                    <div id="otp-msg" class="otp-msg" style="display:none;"></div>
                    <div id="otp-choose" class="otp-choose" style="display:none;"></div>
                    <button type="button" id="otp-send-btn" class="otp-btn" onclick="otpSend()">
                        <span>ارسال کد</span><i class="fas fa-arrow-left"></i>
                    </button>
                </div>
                <!-- ۲) کد ۵ رقمی -->
                <div id="otp-step-code" style="display:none;">
                    <div class="otp-who">
                        <span class="otp-role" id="otp-role"></span>
                        <div class="otp-name" id="otp-name"></div>
                        <div class="otp-sub">کد ۵ رقمی که در ربات بله برایتان فرستاده شد را وارد کنید</div>
                    </div>
                    <div class="otp-boxes" id="otp-boxes" dir="ltr">
                        <input inputmode="numeric" maxlength="1" autocomplete="one-time-code"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1">
                    </div>
                    <div id="otp-code-msg" class="otp-msg" style="display:none;"></div>
                    <div class="otp-foot">
                        <button type="button" id="otp-resend" class="otp-link" onclick="otpSend(true)" disabled>ارسال دوباره <span id="otp-timer" dir="ltr"></span></button>
                        <button type="button" class="otp-link" onclick="otpBack()"><i class="fas fa-pen ml-1"></i>تغییر شماره</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- فوتر -->
    <div class="footer-credit">
        <div class="footer-box group/author hover-target">
            <span>طراحی و اجرا :</span>
            <span class="text-brand-accent font-black mr-1 relative inline-block cursor-none">
                گروه توسعه بهیکس
            </span>
            <span class="text-gray-300 text-xs ml-1" style="font-family: Arial, sans-serif;">(MMBehzadi)</span>
        </div>
        <p class="copyright-text">تمامی حقوق مادی و معنوی محفوظ می‌باشد - ۲۰۲۶ © <span dir="ltr" style="font-family: Arial, sans-serif;">- Version:1.3.0</span></p>
    </div>

    <!-- بارگذاری ذرات -->
    <script>window.CURSOR_FX_COLOR = '#00d2ff';</script>
    <script src="cursor-fx.js?v=1" defer></script>
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
            if (!window.tsParticles) return; // CDN نیامد؛ پس‌زمینه‌ی ذره‌ای اختیاری است
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
        // دو درگاه: «همکارِ بیمه» (همه‌ی نقش‌های داخلی) و «کارشناسِ شرکت‌ها» (کاربرانِ شرکت)؛ پیش‌فرض همکارِ بیمه
        const GATES = {
            STAFF: {title: 'ورودِ همکارانِ بیمه با ما', sub: 'مدیریت، صدور، مالی و کارمندانِ بیمه با ما', ico: 'fa-shield-halved', user: 'نام کاربری / کد ملی'},
            COMPANY: {title: 'ورودِ کارشناسانِ شرکت‌ها', sub: 'ثبتِ درخواست، مدارک و پیگیریِ صدورِ شرکت شما', ico: 'fa-building-shield', user: 'نام کاربریِ شرکت'},
        };
        function selectGate(gate) {
            const g = GATES[gate] || GATES.STAFF;
            document.getElementById('login-gate').value = gate;
            document.querySelectorAll('.gate2-btn').forEach(b => b.classList.toggle('active', b.dataset.gate === gate));
            document.body.classList.toggle('gate-company', gate === 'COMPANY');
            const t = document.getElementById('gate-title'), sb = document.getElementById('gate-sub');
            t.textContent = g.title; sb.textContent = g.sub;
            document.getElementById('gate-ico').className = 'fas ' + g.ico + ' text-3xl';
            [t, sb].forEach(el => { el.classList.remove('gate-swap'); void el.offsetWidth; el.classList.add('gate-swap'); });
            const lbl = document.querySelector('#login-nid + label'); if (lbl) lbl.textContent = g.user;
            if (document.getElementById('otp-step-code').style.display === 'block') otpBack();
        }
        selectGate('STAFF');

        // ======================= ورود با کد (ربات بله‌ی شرکت‌ها) =======================
        // نقش و مقصد (پنل ما / پنل شرکت‌ها / همکار بیمه با ما) را خودِ سیستم از روی شماره تشخیص می‌دهد
        const FA_D = '۰۱۲۳۴۵۶۷۸۹';
        const toEnD = v => String(v || '').replace(/[۰-۹]/g, d => FA_D.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        const toFaD = v => String(v).replace(/\d/g, d => FA_D[d]);
        let otpAccount = null, otpTimer = null;

        function setLoginMode(mode) {
            const otp = mode === 'otp';
            document.querySelectorAll('.mode-tab').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
            document.getElementById('login-form').style.display = otp ? 'none' : 'flex';
            document.getElementById('otp-login').style.display = otp ? 'block' : 'none';
            try { localStorage.setItem('login_mode', mode); } catch (e) {}
            if (otp) setTimeout(() => document.getElementById('otp-phone').focus(), 50);
        }

        // نمایشِ انیمیشنیِ رقم‌های شماره (۰۹۱۲ ۳۴۵ ۶۷۸۹)
        const phoneInput = document.getElementById('otp-phone');
        const phoneBox = document.getElementById('phone-box');
        let lastPhone = '';
        function normPhoneInput(v) {
            let d = toEnD(v).replace(/\D/g, '');
            if (d.startsWith('0098')) d = d.slice(4); else if (d.startsWith('98') && d.length > 10) d = d.slice(2);
            if (d.length && d[0] === '9') d = '0' + d;
            return d.slice(0, 11);
        }
        function renderPhone() {
            const d = normPhoneInput(phoneInput.value);
            phoneInput.value = d;
            const box = document.getElementById('phone-digits');
            let html = '';
            for (let i = 0; i < 11; i++) {
                if (i === 4 || i === 7) html += '<span class="gap"></span>';
                if (i < d.length) {
                    // فقط رقمِ تازه انیمیشن می‌گیرد؛ بقیه ثابت می‌مانند
                    const fresh = i >= lastPhone.length || lastPhone[i] !== d[i];
                    html += `<span class="d" style="${fresh ? '' : 'animation:none'}">${toFaD(d[i])}</span>`;
                } else {
                    if (i === d.length && phoneBox.classList.contains('focus')) html += '<span class="caret"></span>';
                    html += `<span class="d ph">${i === 0 ? '۰' : i === 1 ? '۹' : '•'}</span>`;
                }
            }
            box.innerHTML = html;
            lastPhone = d;
            phoneBox.classList.remove('err', 'ok');
            if (d.length === 11) phoneBox.classList.add('ok');
        }
        phoneInput.addEventListener('input', renderPhone);
        phoneInput.addEventListener('focus', () => { phoneBox.classList.add('focus'); renderPhone(); });
        phoneInput.addEventListener('blur', () => { phoneBox.classList.remove('focus'); renderPhone(); });
        phoneInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); otpSend(); } });
        renderPhone();

        function otpMsg(id, text, kind = 'err', html = false) {
            const el = document.getElementById(id);
            if (!text) { el.style.display = 'none'; return; }
            el.className = 'otp-msg ' + kind; el.style.display = 'block';
            if (html) el.innerHTML = text; else el.textContent = text;
        }

        async function otpSend(resend = false, accountKey = null) {
            const phone = normPhoneInput(phoneInput.value);
            if (phone.length !== 11 || !phone.startsWith('09')) {
                phoneBox.classList.remove('err'); void phoneBox.offsetWidth; phoneBox.classList.add('err');
                otpMsg('otp-msg', 'شماره موبایل را کامل وارد کنید (۱۱ رقم، با ۰۹).'); return;
            }
            const btn = document.getElementById('otp-send-btn');
            btn.disabled = true; btn.querySelector('span').textContent = 'در حال بررسی...';
            otpMsg('otp-msg', ''); document.getElementById('otp-choose').style.display = 'none';
            try {
                const res = await fetch('api/otp_login.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'send', phone, gate: document.getElementById('login-gate').value, account: accountKey || (resend && otpAccount ? otpAccount.key : '')})});
                const d = await res.json();
                if (d.choose) {
                    // یک شماره با چند حساب (مثلاً هم کاربر پنل، هم کاربر شرکت)
                    const box = document.getElementById('otp-choose');
                    box.innerHTML = '<p class="otp-hint" style="margin:0">با این شماره چند حساب هست؛ کدام؟</p>' + d.choose.map(a =>
                        `<button type="button" onclick="otpSend(false, '${a.key}')"><span>${a.name}</span><small>${a.role_fa}</small></button>`).join('');
                    box.style.display = 'grid'; return;
                }
                if (!d.ok) {
                    if (resend) { otpMsg('otp-code-msg', d.error); return; }
                    phoneBox.classList.remove('err'); void phoneBox.offsetWidth; phoneBox.classList.add('err');
                    if (d.code === 'NOT_LINKED') {
                        otpMsg('otp-msg', `<b>${d.account.name}</b> عزیز، لطفاً ابتدا در <b>ربات بله‌ی شرکت</b> با شماره‌ی خود وارد شوید و سپس برای ورود اقدام کنید.` +
                            (d.bot_url ? `<br><a href="${d.bot_url}" target="_blank">ورود به ربات بله</a>` : ''), 'warn', true);
                    } else if (d.code === 'NOT_FOUND') {
                        otpMsg('otp-msg', 'کاربری با این شماره یافت نشد.');
                    } else if (d.code === 'OTHER_GATE') {
                        otpMsg('otp-msg', d.error, 'warn');
                    } else otpMsg('otp-msg', d.error || 'خطا');
                    return;
                }
                otpAccount = d.account;
                document.getElementById('otp-role').textContent = d.account.role_fa;
                document.getElementById('otp-name').textContent = d.account.name;
                document.getElementById('otp-step-phone').style.display = 'none';
                document.getElementById('otp-step-code').style.display = 'block';
                otpMsg('otp-code-msg', '');
                otpClearBoxes();
                otpStartTimer(d.ttl || 120);
                if (resend) showToast('کد تازه در ربات بله فرستاده شد.', 'success');
            } catch (e) {
                otpMsg(resend ? 'otp-code-msg' : 'otp-msg', 'خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false; btn.querySelector('span').textContent = 'ارسال کد';
            }
        }
        function otpBack() {
            clearInterval(otpTimer);
            document.getElementById('otp-step-code').style.display = 'none';
            document.getElementById('otp-step-phone').style.display = 'block';
            phoneInput.focus();
        }
        function otpStartTimer(sec) {
            const btn = document.getElementById('otp-resend'), t = document.getElementById('otp-timer');
            clearInterval(otpTimer); btn.disabled = true;
            let left = sec;
            const tick = () => {
                if (left <= 0) { clearInterval(otpTimer); btn.disabled = false; t.textContent = ''; return; }
                t.textContent = '(' + toFaD(String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0')) + ')';
                left--;
            };
            tick(); otpTimer = setInterval(tick, 1000);
        }

        // پنج خانه‌ی کد: جلو رفتنِ خودکار، پاک‌کردن با Backspace، و پیست که هر رقم را سرِ جایش می‌نشاند
        const otpBoxWrap = document.getElementById('otp-boxes');
        const otpBoxes = [...otpBoxWrap.querySelectorAll('input')];
        function otpClearBoxes() {
            otpBoxWrap.classList.remove('ok', 'bad', 'busy');
            otpBoxes.forEach(b => { b.value = ''; b.classList.remove('filled'); b.disabled = false; });
            setTimeout(() => otpBoxes[0].focus(), 60);
        }
        function otpFill(start, digits) {
            let i = start;
            for (const ch of digits) { if (i >= 5) break; otpBoxes[i].value = toFaD(ch); otpBoxes[i].classList.remove('filled'); void otpBoxes[i].offsetWidth; otpBoxes[i].classList.add('filled'); i++; }
            otpBoxes[Math.min(i, 4)].focus();
            const code = otpBoxes.map(b => toEnD(b.value)).join('');
            if (/^\d{5}$/.test(code)) otpVerify(code);
        }
        otpBoxes.forEach((b, i) => {
            b.addEventListener('input', () => {
                const d = toEnD(b.value).replace(/\D/g, '');
                otpBoxWrap.classList.remove('bad');
                if (!d) { b.value = ''; b.classList.remove('filled'); return; }
                otpFill(i, d);
            });
            b.addEventListener('keydown', e => {
                if (e.key === 'Backspace' && !b.value && i > 0) { otpBoxes[i - 1].value = ''; otpBoxes[i - 1].classList.remove('filled'); otpBoxes[i - 1].focus(); e.preventDefault(); }
                else if (e.key === 'ArrowLeft' && i < 4) otpBoxes[i + 1].focus();
                else if (e.key === 'ArrowRight' && i > 0) otpBoxes[i - 1].focus();
            });
            b.addEventListener('paste', e => {
                const d = toEnD((e.clipboardData || window.clipboardData).getData('text')).replace(/\D/g, '');
                if (!d) return;
                e.preventDefault(); otpBoxWrap.classList.remove('bad');
                otpFill(d.length >= 5 ? 0 : i, d.slice(0, 5));
            });
            b.addEventListener('focus', () => b.select());
        });

        async function otpVerify(code) {
            if (!otpAccount || otpBoxWrap.classList.contains('busy')) return;
            otpBoxWrap.classList.add('busy'); otpMsg('otp-code-msg', '');
            try {
                const res = await fetch('api/otp_login.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'verify', phone: normPhoneInput(phoneInput.value), account: otpAccount.key, code})});
                const d = await res.json();
                otpBoxWrap.classList.remove('busy');
                if (d.ok) {
                    otpBoxWrap.classList.add('ok');
                    otpBoxes.forEach(x => x.disabled = true);
                    clearInterval(otpTimer);
                    showToast(`${d.name} عزیز، خوش آمدید`, 'success');
                    setTimeout(() => { location.href = d.redirect; }, 900);
                } else {
                    otpBoxWrap.classList.remove('bad'); void otpBoxWrap.offsetWidth; otpBoxWrap.classList.add('bad');
                    otpMsg('otp-code-msg', d.error || 'کد اشتباه است.');
                    if (d.expired) document.getElementById('otp-resend').disabled = false;
                    setTimeout(() => { otpBoxes.forEach(x => { x.value = ''; x.classList.remove('filled'); }); otpBoxes[0].focus(); }, 650);
                }
            } catch (e) { otpBoxWrap.classList.remove('busy'); otpMsg('otp-code-msg', 'خطا در ارتباط با سرور.'); }
        }
        let savedMode = 'pass';
        try { savedMode = localStorage.getItem('login_mode') === 'otp' ? 'otp' : 'pass'; } catch (e) {}
        setLoginMode(savedMode);

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