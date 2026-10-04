<?php
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
// کاربری که از پنل حذف شده، با نشستِ قبلی‌اش هم دیگر وارد نمی‌شود
require_once __DIR__ . '/api/_auth_helpers.php';
if (!auth_staff_is_active($pdo, $_SESSION['user_id'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// دسترسیِ سفارشیِ صفحه به صفحه (api/_perm.php): اگر مدیر کل برای این کاربر تعیین کرده باشد، صفحه با چیدمانِ
// کامل ساخته می‌شود و منوها و دکمه‌هایی که اجازه ندارد در مرورگر پنهان می‌شوند (بررسیِ اصلی در سرور است)
require_once __DIR__ . '/api/_perm.php';
$permBoot = perm_page_boot($pdo);
$realRole = perm_real_role();
// نقش «همکار شرکت‌ها»: فقط بخش شرکت‌ها و گزارش مالی مربوطه را می‌بیند
$isLiaison = ($_SESSION['role'] ?? '') === 'COMPANY_LIAISON';
$canSeeCompanies = $isLiaison || ($_SESSION['role'] ?? '') === 'ADMIN';
// نقش «کاربر پارسیان»: فقط ساخت/دیدن/ویرایشِ گزارش‌های بازدیدِ خودش (هیچ بخشِ دیگری از پنل را نمی‌بیند)
$isParsian = ($_SESSION['role'] ?? '') === 'PARSIAN';
// منوی «گزارش بازدید»: مدیر کل، کاربر پارسیان، و هر کسی که در «تنظیمات گزارش» اجازه‌ی صدورِ حداقل یک نوع را دارد
$vrAccess = false;
try {
    if (($_SESSION['role'] ?? '') === 'ADMIN' || $isParsian) {
        $pdo->query("SELECT 1 FROM report_categories LIMIT 1");
        $vrAccess = true;
    } else {
        foreach ($pdo->query("SELECT access_json FROM report_categories WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $aj) {
            $a = json_decode((string)$aj, true) ?: [];
            if (in_array($_SESSION['role'] ?? '', (array)($a['roles'] ?? []), true) || in_array(intval($_SESSION['user_id']), array_map('intval', (array)($a['users'] ?? [])), true)) { $vrAccess = true; break; }
        }
    }
} catch (Throwable $e) { $vrAccess = $isParsian; }
// HTML منوی «گزارش بازدید» (برای کاربر پارسیان تنها منوی پنل است؛ برای بقیه کنارِ «عملیات بیمه»)
$vrMenuHtml = '';
if ($vrAccess) {
    $vrMenuHtml = <<<'HTML'
                <!-- ===== گزارش بازدید: ساخت گزارش، گزارش‌های صادرشده و (فقط مدیر کل) تنظیمات ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-file-circle-check ml-1"></i> گزارش بازدید
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('vr-build')" id="nav-vr-build" class="nav-item menu-link"><i class="fas fa-file-circle-plus ml-2"></i> ساخت گزارش بازدید</a>
                        <a href="#" onclick="switchTab('vr-list')" id="nav-vr-list" class="nav-item menu-link"><i class="fas fa-folder-open ml-2"></i> گزارش‌های صادرشده</a>
                        {{VR_SETTINGS}}
                    </div>
                </div>
HTML;
    $vrMenuHtml = str_replace('{{VR_SETTINGS}}', ($_SESSION['role'] ?? '') === 'ADMIN'
        ? '<div class="menu-sep"></div><a href="#" onclick="switchTab(\'vr-settings\')" id="nav-vr-settings" class="nav-item menu-link"><i class="fas fa-sliders ml-2"></i> تنظیمات گزارش</a>' : '', $vrMenuHtml) . "\n";
}
// همان «گزارش بازدید» به شکلِ زیرمنوی درختیِ «صدور بیمه» (برای همه به‌جز کاربر پارسیان)
$vrSubHtml = !$vrAccess ? '' : '                        <div class="menu-sub">
                            <button type="button" class="menu-sub-trigger" aria-expanded="false" onclick="toggleMenuSub(this, event)"><i class="fas fa-file-circle-check ml-2"></i><span class="ms-t">گزارش بازدید<small>ساخت، صادرشده، تنظیمات</small></span><i class="fas fa-chevron-left ms-arrow"></i></button>
                            <div class="menu-subpanel">
                                <div class="ms-head">گزارش بازدید</div>
                                <a href="#" onclick="switchTab(\'vr-build\')" id="nav-vr-build" class="nav-item menu-link"><i class="fas fa-file-circle-plus ml-2"></i> ساخت گزارش بازدید</a>
                                <a href="#" onclick="switchTab(\'vr-list\')" id="nav-vr-list" class="nav-item menu-link"><i class="fas fa-folder-open ml-2"></i> گزارش‌های صادرشده</a>'
    . (($_SESSION['role'] ?? '') === 'ADMIN' ? '
                                <a href="#" onclick="switchTab(\'vr-settings\')" id="nav-vr-settings" class="nav-item menu-link"><i class="fas fa-sliders ml-2"></i> تنظیمات گزارش</a>' : '') . '
                            </div>
                        </div>
';
// منوی «گفتگوها»: پیام‌رسانِ یکپارچه برای همه‌ی نقش‌ها (مدیر، اپراتور، مالی، همکار، پارسیان)
$chatNavHtml = '<a href="#" onclick="switchTab(\'tickets\')" id="nav-tickets" class="nav-item hover-target transition-colors block lg:inline py-2 lg:py-0"><i class="fas fa-comments ml-1"></i> گفتگوها'
    . ' <span id="chat-nav-badge" class="hidden bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full mr-1"></span></a>' . "\n";
// منوی «اطلاعات پرسنلی»: کارکرد من (همه) و کارکرد پرسنل (مدیر کل؛ برای دسترسیِ سفارشی در مرورگر کنترل می‌شود)
$personnelMenuHtml = '
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-id-badge ml-1"></i> اطلاعات پرسنلی
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab(\'my-work\')" id="nav-my-work" class="nav-item menu-link font-black text-indigo-700"><i class="fas fa-calendar-check ml-2"></i> کارکرد من <small class="text-[10px] text-slate-400 font-bold mr-1">حضور، شرح کار، مرخصی</small></a>'
    . ((($_SESSION['role'] ?? '') === 'ADMIN') ? '
                        <a href="#" onclick="switchTab(\'staff-work\')" id="nav-staff-work" class="nav-item menu-link"><i class="fas fa-users-viewfinder ml-2"></i> کارکرد پرسنل <small class="text-[10px] text-slate-400 font-bold mr-1">همه، تأییدِ مرخصی، تنظیمات</small></a>' : '') . '
                    </div>
                </div>
';
// نوارِ زبانه‌های «مرکز صدور»: سه بخشِ در حال صدور، صادره‌ها و صدور گروهی در یک صفحه
function issuance_nav($active, $isLiaison) {
    $items = [];
    if (!$isLiaison) $items[] = ['issue-queue', 'fa-list-check', 'در حال صدور', 'iss-n-queue'];
    $items[] = ['issued-list', 'fa-file-circle-check', 'صادره‌ها', 'iss-n-issued'];
    if (!$isLiaison) $items[] = ['issue-group', 'fa-layer-group', 'صدور گروهی', 'iss-n-group'];
    $h = '<div class="iss-hub"><div class="iss-hub-title"><span class="iss-hub-ic"><i class="fas fa-stamp"></i></span><div><b>مرکز صدور</b><small>همه‌ی ردیف‌های شرکتی و کارکنان، از درخواست تا صادره</small></div></div><div class="iss-nav">';
    foreach ($items as [$tab, $ic, $label, $badge]) {
        $h .= '<button type="button" onclick="switchTab(\'' . $tab . '\')" class="' . ($active === $tab ? 'on' : '') . '"><i class="fas ' . $ic . '"></i>' . $label . ' <span class="iss-n ' . $badge . '"></span></button>';
    }
    return $h . '</div></div>';
}
// نوارِ زبانه‌های «مرکز مالی»: همه‌ی بخش‌های مالی در یک جا
function finance_nav($active, $canSeeCompanies = true) {
    $items = [['fin-dashboard', 'fa-chart-pie', 'داشبورد'], ['fin-installments', 'fa-list-ol', 'مرکز اقساط'], ['fin-payments', 'fa-arrow-right-arrow-left', 'دریافت و پرداخت'],
              ['fin-tracking', 'fa-magnifying-glass-location', 'پیگیری'], ['fin-invoices', 'fa-file-invoice', 'صورتحساب‌ها'], ['fin-reconcile', 'fa-scale-balanced', 'مغایرت‌گیری']];
    if ($canSeeCompanies) $items[] = ['companies-finance', 'fa-sack-dollar', 'گزارش شرکت‌ها'];
    if (($_SESSION['role'] ?? '') === 'ADMIN') $items[] = ['fin-settings', 'fa-sliders', 'تنظیمات'];
    $h = '<div class="iss-hub fin-hub"><div class="iss-hub-title"><span class="iss-hub-ic"><i class="fas fa-calculator"></i></span><div><b>مرکز مالی</b><small>اقساطِ کارکنان و شرکتی، دریافت از بیمه‌گذار، پرداخت به بیمه‌گر، چک‌ها و صورتحساب</small></div></div><div class="iss-nav">';
    foreach ($items as [$tab, $ic, $label]) $h .= '<button type="button" onclick="switchTab(\'' . $tab . '\')" class="' . ($active === $tab ? 'on' : '') . '"><i class="fas ' . $ic . '"></i>' . $label . '</button>';
    return $h . '</div></div>';
}
// نامِ فارسیِ نقش‌ها - هیچ‌جای پنل نقش با اسم انگلیسی نشان داده نمی‌شود
function role_fa($role) {
    return ['ADMIN' => 'مدیر کل', 'OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی', 'COMPANY_LIAISON' => 'کارمند بیمه با ما', 'PARSIAN' => 'کارمند بیمه با ما (پارسیان)'][$role] ?? $role;
}

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
        /* داشبورد مالی: پویانمایی ستون‌ها، نوارها و کارت‌ها */
        @keyframes finRise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
        @keyframes finGrow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
        @keyframes finPop { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        @keyframes finDraw { from { stroke-dasharray: 0 4000; } to { stroke-dasharray: 4000 0; } }
        .fin-bar { transform-box: fill-box; transform-origin: bottom; animation: finRise .6s cubic-bezier(.2,.8,.2,1) both; }
        .fin-grow { transform-origin: right; animation: finGrow .8s cubic-bezier(.2,.8,.2,1) both; }
        .fin-pop { animation: finPop .45s ease both; }
        .fin-line:not([stroke-dasharray]) { animation: finDraw 1.4s ease both; }
        .fin-slice { transition: opacity .2s, transform .2s; transform-box: fill-box; transform-origin: center; cursor: pointer; }
        .fin-slice:hover { opacity: .85; transform: scale(1.04); }
        .fin-bar:hover { filter: brightness(1.1); }
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
        .cursor-dot { position: fixed; left: 0; top: 0; width: 8px; height: 8px; background: #3b82f6; border-radius: 50%; pointer-events: none; z-index: 2147483647 !important; transform: translate(var(--cx, -100px), var(--cy, -100px)) translate(-50%, -50%); transition: background 0.2s; box-shadow: 0 0 10px rgba(59,130,246,0.5); will-change: transform; }
        .cursor-outline { position: fixed; left: 0; top: 0; width: 24px; height: 24px; border: 2px solid rgba(59, 130, 246, 0.5); border-radius: 50%; pointer-events: none; z-index: 2147483646 !important; transform: translate(var(--ox, -100px), var(--oy, -100px)) translate(-50%, -50%); transition: opacity 0.2s; will-change: transform; }
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
        /* پشتیبانِ محلیِ .hidden: نشان‌دادن/پنهان‌کردنِ فیلدها به این کلاس وابسته است و اگر
           CDNِ Tailwind نیاید، بدون این قاعده همه‌ی فیلدها با هم دیده می‌شوند.
           مهم: المان‌هایی که «hidden» را با یک نمایشِ واکنش‌گرا جفت کرده‌اند (مثلاً منوی
           اصلی: hidden lg:flex) از این قاعده بیرون‌اند؛ وگرنه !important جلوی lg:flex را
           می‌گیرد و منو در دسکتاپ اصلاً دیده نمی‌شود. آن‌ها را خودِ Tailwind مدیریت می‌کند. */
        .hidden:not([class~="sm:flex"], [class~="sm:inline-flex"], [class~="sm:block"], [class~="sm:inline-block"], [class~="sm:inline"], [class~="sm:grid"], [class~="sm:inline-grid"], [class~="sm:table"], [class~="sm:table-cell"], [class~="sm:table-row"], [class~="sm:contents"], [class~="md:flex"], [class~="md:inline-flex"], [class~="md:block"], [class~="md:inline-block"], [class~="md:inline"], [class~="md:grid"], [class~="md:inline-grid"], [class~="md:table"], [class~="md:table-cell"], [class~="md:table-row"], [class~="md:contents"], [class~="lg:flex"], [class~="lg:inline-flex"], [class~="lg:block"], [class~="lg:inline-block"], [class~="lg:inline"], [class~="lg:grid"], [class~="lg:inline-grid"], [class~="lg:table"], [class~="lg:table-cell"], [class~="lg:table-row"], [class~="lg:contents"], [class~="xl:flex"], [class~="xl:inline-flex"], [class~="xl:block"], [class~="xl:inline-block"], [class~="xl:inline"], [class~="xl:grid"], [class~="xl:inline-grid"], [class~="xl:table"], [class~="xl:table-cell"], [class~="xl:table-row"], [class~="xl:contents"], [class~="2xl:flex"], [class~="2xl:inline-flex"], [class~="2xl:block"], [class~="2xl:inline-block"], [class~="2xl:inline"], [class~="2xl:grid"], [class~="2xl:inline-grid"], [class~="2xl:table"], [class~="2xl:table-cell"], [class~="2xl:table-row"], [class~="2xl:contents"]) { display: none !important; }
        /* ---- نمایشگرِ بزرگِ عکس‌های بازدید سلامت: چیدمانِ اصلی با CSS خودمان (مستقل از CDNِ Tailwind) ---- */
        #hv-modal { position: fixed; inset: 0; z-index: 9999980; background: #020617; color: #fff; flex-direction: column; }
        #hv-modal:not(.hidden) { display: flex; }
        #hv-modal .hv-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 16px; border-bottom: 1px solid rgba(255,255,255,.1); }
        #hv-modal .hv-main { flex: 1; display: flex; min-height: 0; }
        #hv-stage { position: relative; flex: 1; min-height: 0; overflow: hidden; background: #000; }
        #hv-img { position: absolute; inset: 0; margin: auto; max-width: 100%; max-height: 100%; }
        #hv-modal .hv-side { width: 320px; flex-shrink: 0; background: #0f172a; padding: 16px; display: flex; flex-direction: column; gap: 12px; border-right: 1px solid rgba(255,255,255,.1); overflow-y: auto; }
        #hv-modal .hv-side textarea { width: 100%; background: #1e293b; color: #fff; border: 1px solid rgba(255,255,255,.12); border-radius: 10px; padding: 8px; font-family: inherit; }
        #hv-modal .hv-btn { border-radius: 12px; padding: 10px; font-weight: 700; font-size: 13px; color: #fff; }
        #hv-modal .hv-ok { background: #059669; } #hv-modal .hv-ok:hover { background: #047857; }
        #hv-modal .hv-no { background: #e11d48; } #hv-modal .hv-no:hover { background: #be123c; }
        #hv-modal .hv-ctrl { background: rgba(0,0,0,.6); color: #fff; border-radius: 10px; }
        #hv-strip { display: flex; gap: 6px; overflow-x: auto; padding: 8px 12px; border-top: 1px solid rgba(255,255,255,.1); background: #020617; }
        @media (max-width: 767px) { #hv-modal .hv-main { flex-direction: column; } #hv-modal .hv-side { width: auto; max-height: 45vh; border-right: 0; border-top: 1px solid rgba(255,255,255,.1); } }
        .bot-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.15); vertical-align: middle; margin-left: 4px; }
        .bot-dot.on { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
        /* صفحه‌ی کاربران: فیلترِ نوع، چیپِ نقش، انتخابِ نقش در فرمِ افزودن */
        .su-seg { display: inline-flex; background: #f1f5f9; border-radius: 12px; padding: 3px; gap: 2px; }
        .su-seg button { font-size: 11px; font-weight: 700; color: #64748b; padding: 6px 12px; border-radius: 9px; transition: all .2s; }
        .su-seg button.active { background: #fff; color: #1d4ed8; box-shadow: 0 1px 3px rgba(15,23,42,.12); }
        .su-chip { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; }
        .su-chip.ad { background: #eef2ff; color: #4338ca; }
        .su-chip.co { background: #ecfdf5; color: #047857; }
        .su-role-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .su-role-grid button { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 12px; font-weight: 700; color: #475569;
            border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 10px 8px; background: #fff; transition: all .2s; }
        .su-role-grid button:hover { border-color: #93c5fd; background: #f8fafc; }
        .su-role-grid button.active { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
        .su-role-grid .col-span-2 { grid-column: span 2; }
        .su-fade { animation: suFade .3s ease; }
        /* نمودارهای داشبورد */
        .dc-wrap { background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%); }
        .dc-seg { display: inline-flex; flex-wrap: wrap; background: #f1f5f9; border-radius: 12px; padding: 3px; gap: 2px; }
        .dc-seg button { font-size: 11px; font-weight: 700; color: #64748b; padding: 6px 11px; border-radius: 9px; transition: all .25s; display: inline-flex; align-items: center; gap: 5px; }
        .dc-seg button:hover { color: #334155; }
        .dc-seg button.active { background: #fff; color: #4338ca; box-shadow: 0 1px 4px rgba(15,23,42,.12); }
        .dc-sw { width: 9px; height: 9px; border-radius: 3px; display: inline-block; }
        .dc-sw.p { background: linear-gradient(135deg, #3b82f6, #6366f1); }
        .dc-sw.c { background: linear-gradient(135deg, #10b981, #14b8a6); }
        .dc-pop { display: none; }
        .dc-pop.show { display: inline-flex; animation: suFade .25s ease; }
        .dc-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; }
        @media (min-width: 1024px) { .dc-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
        .dc-panel { border: 1px solid #eef2f7; border-radius: 18px; padding: 16px; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.03); }
        .dc-legend { display: flex; gap: 12px; font-size: 10px; font-weight: 700; color: #64748b; margin: 8px 0 2px; min-height: 14px; }
        .dc-legend span { display: inline-flex; align-items: center; gap: 4px; }
        .dc-chart { position: relative; height: 230px; }
        .dc-chart svg { width: 100%; height: 100%; overflow: visible; }
        .dc-chart .dc-tip { position: absolute; pointer-events: none; background: #0f172a; color: #fff; font-size: 11px; line-height: 1.7; border-radius: 10px;
            padding: 6px 10px; white-space: nowrap; transform: translate(-50%, -100%); opacity: 0; transition: opacity .15s; z-index: 5; box-shadow: 0 8px 20px rgba(15,23,42,.25); }
        .dc-chart .dc-tip.on { opacity: 1; }
        .dc-delta { font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 999px; white-space: nowrap; }
        .dc-delta.up { background: #ecfdf5; color: #047857; } .dc-delta.down { background: #fef2f2; color: #b91c1c; } .dc-delta.flat { background: #f1f5f9; color: #64748b; }
        .dc-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #94a3b8; pointer-events: none; }
        @keyframes suFade { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        .checklist-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(128px, 1fr)); gap: 6px; align-items: start; }
        /* ستونِ چک‌لیست باید بیشترِ عرضِ جدول را بگیرد تا خانه‌هایش در یک سطر کنار هم
           جا شوند؛ بقیه‌ی ستون‌ها باریک و whitespace-nowrap هستند. با درصدِ ثابت
           نوشته شده (نه کلاس‌های Tailwind) تا مستقل از CDN هم درست کار کند. */
        .rows-table { width: 100%; }
        .rows-table .col-checklist { width: 52%; min-width: 430px; }
        /* عرضِ مودالِ جزئیاتِ درخواست - بدون وابستگی به کلاس‌های دلخواهِ Tailwind */
        .creq-wide-modal { width: 96vw; max-width: 1400px; }
        /* مرکز صدور: سرتیتر و زبانه‌ها */
        .iss-hub { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-radius: 20px;
                   background: linear-gradient(120deg, #0f766e, #0d9488 45%, #4f46e5); color: #fff; box-shadow: 0 10px 30px -12px rgba(13,148,136,.55); }
        .iss-hub-title { display: flex; align-items: center; gap: 12px; }
        .iss-hub-title b { display: block; font-size: 19px; font-weight: 900; }
        .iss-hub-title small { display: block; font-size: 11px; opacity: .85; }
        .iss-hub-ic { width: 44px; height: 44px; border-radius: 14px; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; font-size: 19px; }
        .iss-nav { display: flex; gap: 4px; background: rgba(255,255,255,.16); padding: 4px; border-radius: 14px; flex-wrap: wrap; }
        .iss-nav button { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 800; color: #fff; padding: 8px 14px; border-radius: 11px; transition: .15s; }
        .iss-nav button:hover { background: rgba(255,255,255,.14); }
        .iss-nav button.on { background: #fff; color: #0f766e; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
        .iss-nav .iss-n:empty { display: none; }
        .iss-nav .iss-n { font-size: 10px; background: rgba(255,255,255,.25); border-radius: 999px; padding: 1px 7px; }
        .iss-nav button.on .iss-n { background: #ccfbf1; color: #0f766e; }
        .fin-hub { background: linear-gradient(120deg, #1e1b4b, #4338ca 50%, #be185d); box-shadow: 0 10px 30px -12px rgba(67,56,202,.55); }
        .fin-hub .iss-nav button.on { color: #4338ca; }
        .fh-stages button { font-size: 12px; font-weight: 800; padding: 8px 14px; border-radius: 12px; background: #f1f5f9; color: #475569; transition: .15s; }
        .fh-stages button:hover { background: #e2e8f0; }
        .fh-stages button.on { background: #1e1b4b; color: #fff; box-shadow: 0 4px 12px -4px rgba(30,27,75,.5); }
        .fh-stages .fh-n { font-size: 10px; background: rgba(148,163,184,.3); border-radius: 999px; padding: 1px 7px; margin-right: 4px; }
        .fh-bar { position: sticky; top: 8px; z-index: 20; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 14px; border: 1px solid #c7d2fe; background: rgba(255,255,255,.96); backdrop-filter: blur(4px); }
        .fh-table thead tr { background: #eef2ff; color: #3730a3; font-weight: 800; }
        .fh-prog { height: 4px; border-radius: 999px; background: #f1f5f9; overflow: hidden; margin-top: 3px; }
        .fh-prog span { display: block; height: 100%; border-radius: 999px; }
        .fh-overdue td:first-child { box-shadow: inset -3px 0 0 #ef4444; }
        .iss-chips button { font-size: 11px; font-weight: 800; padding: 5px 11px; border-radius: 999px; background: #f1f5f9; color: #475569; border: 1px solid transparent; }
        .iss-chips button.on { background: #fff7ed; color: #c2410c; border-color: #fdba74; }
        /* کارت «اطلاعات صدور» در پاپ‌آپ‌ها */
        .ii-sec { border: 1px solid #e2e8f0; border-radius: 14px; padding: 10px 12px; background: #fff; }
        .ii-sec h5 { font-size: 11.5px; font-weight: 900; color: #334155; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .ii-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 6px 10px; }
        .ii-f label { display: block; font-size: 9.5px; color: #94a3b8; font-weight: 700; margin-bottom: 2px; }
        .ii-f .ii-w { display: flex; align-items: center; border: 1px solid #e2e8f0; border-radius: 9px; background: #f8fafc; }
        .ii-f .ii-w:focus-within { border-color: #14b8a6; background: #fff; }
        .ii-f input { flex: 1; min-width: 0; background: transparent; border: 0; outline: 0; font-size: 11.5px; font-weight: 700; color: #0f172a; padding: 5px 8px; }
        .ii-f button { color: #cbd5e1; padding: 0 7px; font-size: 11px; }
        .ii-f button:hover { color: #0d9488; }
        .ii-ro { font-size: 11.5px; font-weight: 700; color: #0f172a; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 9px; padding: 5px 8px; display: flex; justify-content: space-between; gap: 6px; min-height: 30px; align-items: center; }
        .itpl-paper { background: #fff; width: 794px; max-width: 100%; min-height: 1000px; margin: 0 auto; padding: 56px 52px; box-shadow: 0 4px 18px rgba(15,23,42,.12);
                      font-family: 'B Nazanin', 'Vazirmatn', Tahoma, sans-serif; font-size: 15px; color: #000; line-height: 1.7; }
        .itpl-paper table td { font-size: 12px; text-align: center; }

        /* صدورِ گروهی */
        .bdl-hero { position: relative; padding: 20px 22px; color: #fff; background: linear-gradient(120deg, #4338ca, #7c3aed 60%, #db2777); border-radius: inherit; border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
        .bdl-hero-ic { width: 46px; height: 46px; border-radius: 14px; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .bdl-drop { display: flex; flex-direction: column; align-items: center; text-align: center; padding: 34px 16px; border: 2px dashed #c7d2fe; border-radius: 18px; background: #f8faff; cursor: pointer; transition: .2s; }
        .bdl-drop:hover, .bdl-drop.drag { border-color: #6366f1; background: #eef2ff; }
        .bdl-spinner { width: 46px; height: 46px; border-radius: 50%; border: 4px solid #e0e7ff; border-top-color: #6366f1; animation: bdlspin 0.9s linear infinite; }
        @keyframes bdlspin { to { transform: rotate(360deg); } }
        .bdl-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(118px, 1fr)); gap: 8px; }
        .bdl-stat { border-radius: 14px; padding: 10px 12px; border: 1px solid; }
        .bdl-stat p:first-child { font-size: 10.5px; font-weight: 700; }
        .bdl-stat p:last-child { font-size: 20px; font-weight: 900; line-height: 1.2; }
        .bdl-card { border: 1px solid #e2e8f0; border-radius: 16px; padding: 12px 14px; background: #fff; display: grid; grid-template-columns: auto 1fr; gap: 12px; transition: .2s; }
        .bdl-card.sel { border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
        .bdl-card.st-ready { border-right: 5px solid #10b981; }
        .bdl-card.st-not_ready { border-right: 5px solid #f59e0b; background: #fffdf5; }
        .bdl-card.st-issued { border-right: 5px solid #64748b; background: #f8fafc; }
        .bdl-card.st-no_match, .bdl-card.st-unknown { border-right: 5px solid #ef4444; background: #fff8f8; }
        .bdl-card.st-mismatch, .bdl-card.st-duplicate { border-right: 5px solid #f43f5e; background: #fff7f9; }
        .bdl-card.res-ok { border-right-color: #059669; background: #ecfdf5; }
        .bdl-card.res-err { border-right-color: #dc2626; }
        .bdl-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
        .bdl-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 6px 14px; margin-top: 8px; }
        .bdl-kv > div { font-size: 11px; color: #334155; min-width: 0; }
        .bdl-kv .k { display: block; font-size: 9.5px; color: #94a3b8; font-weight: 700; }
        .bdl-kv input { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 3px 6px; font-size: 11px; font-weight: 700; }
        .bdl-kv input:focus { outline: none; border-color: #6366f1; }
        .bdl-match { margin-top: 8px; border-radius: 12px; padding: 8px 10px; background: #f1f5f9; font-size: 11px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .bdl-chk { width: 20px; height: 20px; accent-color: #4f46e5; cursor: pointer; margin-top: 2px; }
        .bdl-filters button { font-size: 11px; font-weight: 800; padding: 5px 11px; border-radius: 999px; background: #f1f5f9; color: #475569; }
        .bdl-filters button.on { background: #312e81; color: #fff; }
        .bdl-bar { position: sticky; bottom: -20px; margin: 14px -20px -20px; padding: 12px 20px; background: rgba(255,255,255,.96); border-top: 1px solid #e2e8f0; backdrop-filter: blur(4px);
                   display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }

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
        /* ===== ظاهرِ منوی بالا: نوارِ قرصی، دکمه‌های نرم، بخشِ فعال با رنگِ برند، زیرمنوی کارتی با آیکونِ قاب‌دار ===== */
        .menu-link { display: flex; align-items: center; gap: 10px; padding: 6px 8px; border-radius: 11px; font-weight: 700; }
        .menu-link > i:first-child { margin: 0 !important; width: 28px; height: 28px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center;
                                     font-size: 12px; background: #f1f5f9; color: #64748b; flex-shrink: 0; transition: background .2s, color .2s, transform .2s; }
        .menu-link:hover { background: #f8fafc; }
        .menu-link:hover > i:first-child { background: #dbeafe; color: #2563eb; transform: scale(1.07); }
        .menu-link.active-sub { background: #eff6ff; }
        .menu-link.active-sub > i:first-child { background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; box-shadow: 0 4px 10px -4px rgba(37,99,235,.6); }
        .menu-sep { margin: 6px 10px; background: linear-gradient(90deg, transparent, #e2e8f0, transparent); }
        @keyframes menuIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        /* ---- زیرمنوی درختی (menu-sub): در دسکتاپ کنارِ منو باز می‌شود، در موبایل زیرِ خودش و تو‌رفته ---- */
        .menu-sub { position: relative; }
        .menu-sub-trigger { width: 100%; display: flex; align-items: center; gap: 10px; padding: 6px 8px; border-radius: 11px; font-weight: 700; color: #475569;
                            background: none; border: 0; cursor: pointer; text-align: right; white-space: nowrap; font-size: inherit; font-family: inherit; transition: background .15s, color .15s; }
        .menu-sub-trigger > i:first-child { margin: 0 !important; width: 28px; height: 28px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center;
                                            font-size: 12px; background: #f1f5f9; color: #64748b; flex-shrink: 0; transition: background .2s, color .2s, transform .2s; }
        .menu-sub-trigger .ms-t { flex: 1; display: flex; flex-direction: column; line-height: 1.4; }
        .menu-sub-trigger .ms-t small { font-size: 9.5px; font-weight: 600; color: #94a3b8; }
        .menu-sub-trigger .ms-arrow { font-size: 9px; opacity: .45; transition: transform .2s, opacity .2s; margin-right: 6px; }
        .menu-sub-trigger:hover, .menu-sub.open > .menu-sub-trigger { background: #f8fafc; color: #2563eb; }
        .menu-sub-trigger:hover > i:first-child, .menu-sub.open > .menu-sub-trigger > i:first-child { background: #dbeafe; color: #2563eb; transform: scale(1.07); }
        .menu-sub.open > .menu-sub-trigger .ms-arrow { opacity: .9; }
        .menu-sub-trigger.sub-active { color: #1d4ed8; }
        .menu-sub-trigger.sub-active > i:first-child, .menu-sub.open > .menu-sub-trigger.sub-active > i:first-child { background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; }
        .menu-sub-trigger.sub-active .ms-t small { color: #60a5fa; }
        .menu-subpanel { display: none; }
        .menu-sub.open > .menu-subpanel { display: block; }
        @keyframes subIn { from { opacity: 0; transform: translateX(8px); } to { opacity: 1; transform: none; } }
        @keyframes subInFlip { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: none; } }
        @media (min-width: 1024px) {
            .menu-subpanel { position: absolute; top: -8px; right: calc(100% + 10px); min-width: 220px; background: #fff; border: 1px solid #eef2f7; border-radius: 16px;
                             padding: 8px; z-index: 61; box-shadow: 0 22px 48px -14px rgba(15,23,42,.28), 0 0 0 1px rgba(15,23,42,.03); }
            .menu-sub.open > .menu-subpanel { animation: subIn .16s ease-out; }
            /* پلِ نامرئی بینِ گزینه و زیرمنو تا با حرکتِ موس بسته نشود */
            .menu-subpanel::before { content: ''; position: absolute; top: 0; bottom: 0; right: -12px; width: 12px; }
            .menu-sub.flip > .menu-subpanel { right: auto; left: calc(100% + 10px); }
            .menu-sub.flip > .menu-subpanel::before { right: auto; left: -12px; }
            .menu-sub.flip.open > .menu-subpanel { animation-name: subInFlip; }
            .menu-sub.flip .ms-arrow { transform: rotate(180deg); }
            .menu-subpanel .ms-head { font-size: 10px; font-weight: 800; color: #94a3b8; padding: 2px 8px 6px; border-bottom: 1px dashed #e2e8f0; margin-bottom: 4px; }
        }
        @media (max-width: 1023px) {
            .menu-sub.open > .menu-subpanel { margin: 2px 24px 6px 0; padding-right: 10px; border-right: 2px dashed #cbd5e1; }
            .menu-sub.open > .menu-sub-trigger .ms-arrow { transform: rotate(-90deg); }
            .menu-subpanel .ms-head { display: none; }
        }
        @media (min-width: 1024px) {
            #main-nav { align-items: center; gap: 3px !important; padding: 4px !important; border-radius: 16px;
                        background: rgba(241,245,249,.78); border: 1px solid rgba(226,232,240,.95); box-shadow: inset 0 1px 0 rgba(255,255,255,.85); }
            #main-nav > a.nav-item, #main-nav .menu-trigger { display: inline-flex; align-items: center; gap: 6px; padding: 7px 11px !important; border-radius: 12px;
                        color: #475569; white-space: nowrap; transition: background .2s, color .2s, box-shadow .2s; }
            #main-nav > a.nav-item > i:first-child, #main-nav .menu-trigger > i:first-child { margin: 0 !important; font-size: 12px; }
            #main-nav .menu-trigger .fa-chevron-down { margin: 0 !important; opacity: .55; }
            #main-nav > a.nav-item:hover, #main-nav .menu-trigger:hover, #main-nav .menu-group:hover .menu-trigger, #main-nav .menu-group.open .menu-trigger {
                        background: #fff; color: #2563eb; box-shadow: 0 2px 8px rgba(15,23,42,.08); }
            /* بخشِ فعال (خودِ لینک یا گروهی که زیرمنوی فعال دارد) */
            #main-nav > a.nav-item.text-blue-600, #main-nav .menu-group .menu-trigger.text-blue-600, #main-nav .menu-group:hover .menu-trigger.text-blue-600 {
                        background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff !important; box-shadow: 0 6px 16px -6px rgba(37,99,235,.65); }
            .menu-panel { min-width: 240px; padding: 8px; border-radius: 18px; border-color: #eef2f7;
                          box-shadow: 0 22px 48px -14px rgba(15,23,42,.28), 0 0 0 1px rgba(15,23,42,.03); }
            .menu-panel::after { content: ''; position: absolute; top: -6px; right: 24px; width: 11px; height: 11px; background: #fff;
                                 border-left: 1px solid #eef2f7; border-top: 1px solid #eef2f7; transform: rotate(45deg); }
            .menu-group.open .menu-panel, .menu-group:focus-within .menu-panel { animation: menuIn .18s ease-out; }
            /* پنجره‌های کم‌عرض‌تر: فاصله‌ها کمتر، نامِ برند و روزِ هفته‌ی ساعت پنهان تا سربرگ در یک خط بماند */
            @media (max-width: 1279px) {
                #main-nav > a.nav-item, #main-nav .menu-trigger { padding: 6px 7px !important; gap: 4px; }
                .brand-name { display: none !important; }
                .hdr-clock .hc-w { display: none; }
            }
            @media (max-width: 1100px) { #main-nav .menu-trigger .fa-chevron-down { display: none; } }
            @media (max-width: 1140px) {
                header.glass-header { padding-left: 12px !important; padding-right: 12px !important; }
                header.glass-header > div:first-child { gap: 10px !important; }
                #main-nav { font-size: 11px !important; }
                #main-nav > a.nav-item, #main-nav .menu-trigger { padding: 6px 5px !important; }
                .hdr-user-name { max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            }
            @media (hover: hover) and (pointer: fine) { .menu-group:hover .menu-panel { animation: menuIn .18s ease-out; } }
        }
        /* ---- ثبت دستیِ معرفی‌نامه / انتخابِ معرفی‌نامه ---- */
        .mi-box { padding: 0 !important; overflow: hidden; display: flex; flex-direction: column; max-height: 92vh; border-radius: 26px; }
        .mi-head { position: relative; display: flex; align-items: center; gap: 14px; padding: 20px 22px; color: #fff; background: linear-gradient(135deg, #4f46e5, #2563eb 60%, #0ea5e9); overflow: hidden; }
        .mi-head.teal { background: linear-gradient(135deg, #0d9488, #059669 60%, #10b981); }
        .mi-head::after { content: ''; position: absolute; width: 200px; height: 200px; border-radius: 50%; background: rgba(255,255,255,.1); left: -60px; top: -90px; }
        .mi-head h2 { font-size: 17px; font-weight: 900; }
        .mi-head p { font-size: 11.5px; opacity: .85; margin-top: 2px; }
        .mi-ic { width: 48px; height: 48px; border-radius: 16px; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; font-size: 21px; flex-shrink: 0; box-shadow: inset 0 0 0 1px rgba(255,255,255,.25); }
        .mi-x { position: absolute; left: 16px; top: 16px; width: 32px; height: 32px; border-radius: 10px; background: rgba(255,255,255,.18); color: #fff; z-index: 30; transition: .2s; }
        .mi-head::after { pointer-events: none; }
        /* دکمه‌ی بستنِ (×) پنجره‌ها همیشه رویِ همه: متنِ کم‌رنگ (opacity) یا تزئینِ سرتیتر که بعد از دکمه آمده، رویش نمی‌افتد و کلیک را نمی‌گیرد */
        .modal-overlay button.absolute, .modal-content > button.absolute, button.absolute:has(> i.fa-times), button.absolute:has(> i.fa-xmark) { z-index: 30; }
        .mi-x:hover { background: rgba(255,255,255,.32); transform: rotate(90deg); }
        .mi-body { padding: 18px 22px; overflow-y: auto; flex: 1; min-height: 0; }
        .mi-foot { display: flex; gap: 8px; padding: 14px 22px; border-top: 1px solid #eef2f7; background: #fbfcfe; }
        .mi-btn { flex: 1; border: 0; border-radius: 14px; padding: 12px; font-weight: 900; font-size: 13px; color: #fff; background: linear-gradient(135deg, #4f46e5, #2563eb); box-shadow: 0 12px 22px -14px #2563eb; transition: transform .15s, filter .2s; }
        .mi-btn:hover { filter: brightness(1.08); } .mi-btn:active { transform: scale(.98); } .mi-btn:disabled { opacity: .6; }
        .mi-btn.ghost { flex: 0 0 auto; padding: 12px 18px; background: #f1f5f9; color: #475569; box-shadow: none; }
        .mi-drop { display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center; padding: 22px 16px; border: 2px dashed #c7d2fe; border-radius: 20px;
                   background: linear-gradient(180deg, #f8faff, #eef2ff); cursor: pointer; transition: .25s; }
        .mi-drop:hover, .mi-drop.over { border-color: #6366f1; background: #eef2ff; transform: translateY(-2px); }
        .mi-drop b { font-size: 13px; color: #3730a3; } .mi-drop small { font-size: 11px; color: #64748b; }
        .mi-drop-ic { width: 54px; height: 54px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; background: linear-gradient(135deg, #6366f1, #0ea5e9); box-shadow: 0 12px 24px -12px #6366f1; animation: miFloat 3s ease-in-out infinite; }
        .mi-scan { display: flex; align-items: center; gap: 12px; padding: 16px; border-radius: 18px; background: #eef2ff; color: #3730a3; animation: recIn .3s both; }
        .mi-scan b { font-size: 12.5px; display: block; } .mi-scan small { font-size: 11px; color: #64748b; }
        .mi-spin { width: 34px; height: 34px; border-radius: 50%; border: 3px solid #c7d2fe; border-top-color: #4f46e5; animation: miSpin .8s linear infinite; flex-shrink: 0; }
        .mi-attached { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 16px; background: linear-gradient(135deg, #ecfdf5, #f0fdfa); border: 1px solid #a7f3d0; color: #065f46; font-size: 12px; font-weight: 800; animation: recIn .35s both; }
        .mi-attached i.fa-file-circle-check { font-size: 22px; color: #10b981; }
        .mi-attached button { margin-right: auto; font-size: 11px; color: #0f766e; background: #fff; border-radius: 10px; padding: 5px 10px; box-shadow: 0 2px 6px rgba(0,0,0,.06); }
        .mi-warn { margin-top: 10px; padding: 11px 14px; border-radius: 14px; font-size: 12px; font-weight: 800; display: flex; gap: 8px; align-items: flex-start; animation: recIn .3s both; }
        .mi-warn.err { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; } .mi-warn.info { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .mi-f { display: block; font-size: 11px; font-weight: 800; color: #64748b; }
        .mi-f input { display: block; width: 100%; margin-top: 5px; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; font-size: 13px; font-weight: 700; color: #0f172a; background: #fff; outline: none; transition: .2s; }
        .mi-f input:focus { border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99,102,241,.12); }
        .mi-f input.auto { border-color: #34d399; background: #f0fdf4; animation: miGlow 1.2s ease-out; }
        .mi-f input.bad { border-color: #f43f5e; background: #fff1f2; }
        .ip-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1.5px solid #eef2f7; border-radius: 16px; cursor: pointer; transition: .2s; animation: recIn .3s both; }
        .ip-item:hover { border-color: #14b8a6; background: #f0fdfa; transform: translateX(-3px); }
        .ip-item.full { opacity: .5; cursor: not-allowed; }
        .ip-av { width: 42px; height: 42px; border-radius: 14px; background: linear-gradient(135deg, #14b8a6, #0ea5e9); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 900; flex-shrink: 0; }
        .mc-body > div[id^="mc-"][class*="border"] { border-radius: 18px !important; box-shadow: 0 6px 18px -14px rgba(15,23,42,.35); background-color: #fff; }
        .mc-body h4 { font-size: 12.5px !important; }
        .mc-body input:not([type=checkbox]):not([type=file]), .mc-body select { border-radius: 12px !important; border-color: #e2e8f0 !important; }
        .mc-body input:focus, .mc-body select:focus { border-color: #14b8a6 !important; box-shadow: 0 0 0 4px rgba(20,184,166,.12); outline: none; }
        #mc-submit-btn { flex: 1; border-radius: 14px; background: linear-gradient(135deg, #0d9488, #059669); box-shadow: 0 12px 22px -14px #059669; }
        @keyframes miFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        @keyframes miSpin { to { transform: rotate(360deg); } }
        @keyframes miGlow { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.5); } 100% { box-shadow: 0 0 0 8px rgba(16,185,129,0); } }

        /* ---- کارتابل پرونده‌ها: کارت‌های آمار و انتخابِ بازه ---- */
        .rec-seg { display: inline-flex; flex-wrap: wrap; gap: 4px; background: #f1f5f9; padding: 4px; border-radius: 14px; }
        .rec-seg button { border: 0; background: transparent; padding: 6px 12px; border-radius: 10px; font-size: 11px; font-weight: 800; color: #64748b; transition: .2s; }
        .rec-seg button:hover { color: #d97706; }
        .rec-seg button.on { background: linear-gradient(135deg, #f59e0b, #ea580c); color: #fff; box-shadow: 0 6px 14px -8px #ea580c; }
        .rec-card { position: relative; overflow: hidden; border-radius: 22px; padding: 18px 18px 16px; color: #fff; background: linear-gradient(135deg, var(--c1), var(--c2));
                    box-shadow: 0 18px 34px -20px var(--c2); transition: transform .25s, box-shadow .25s; animation: recIn .5s both; }
        .rec-card:nth-child(2) { animation-delay: .06s } .rec-card:nth-child(3) { animation-delay: .12s } .rec-card:nth-child(4) { animation-delay: .18s }
        .rec-card:hover { transform: translateY(-4px); box-shadow: 0 24px 40px -20px var(--c2); }
        .rec-card::after { content: ''; position: absolute; width: 170px; height: 170px; border-radius: 50%; background: rgba(255,255,255,.12); left: -50px; top: -70px; }
        .rec-card::before { content: ''; position: absolute; width: 110px; height: 110px; border-radius: 50%; background: rgba(255,255,255,.08); left: 40px; bottom: -60px; }
        .rec-ic { position: absolute; left: 16px; top: 16px; font-size: 26px; opacity: .85; z-index: 1; }
        .rec-t { font-size: 12px; font-weight: 800; opacity: .9; position: relative; z-index: 1; }
        .rec-v { font-size: 26px; font-weight: 900; margin-top: 6px; position: relative; z-index: 1; letter-spacing: -.5px; }
        .rec-v small { font-size: 12px; font-weight: 700; opacity: .85; margin-right: 4px; }
        .rec-s { font-size: 11px; opacity: .88; margin-top: 4px; position: relative; z-index: 1; min-height: 16px; }
        @keyframes recIn { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: none; } }
        /* اعلان‌های داشبورد (انقضای نزدیک / کارکنانِ صادرنشده) */
        .da-head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; color: #fff; background: linear-gradient(135deg, var(--c1), var(--c2)); }
        .da-head h2 { font-size: 14px; font-weight: 900; }
        .da-head p { font-size: 10.5px; opacity: .88; margin-top: 2px; }
        .da-ic { width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
        .da-badge { min-width: 36px; height: 30px; padding: 0 10px; border-radius: 999px; background: rgba(255,255,255,.95); color: #0f172a; font-weight: 900; font-size: 13px; display: flex; align-items: center; justify-content: center; }
        .da-list { max-height: 400px; overflow-y: auto; }
        .da-row { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background .15s; position: relative; }
        .da-row:hover { background: #f8fafc; }
        .da-row::before { content: ''; position: absolute; right: 0; top: 8px; bottom: 8px; width: 4px; border-radius: 4px 0 0 4px; background: var(--u, #cbd5e1); }
        .da-row .da-main { flex: 1; min-width: 0; }
        .da-row .da-t { font-size: 12px; font-weight: 900; color: #1e293b; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .da-row .da-s { font-size: 10.5px; color: #64748b; font-weight: 700; margin-top: 3px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .da-pill { flex-shrink: 0; text-align: center; border-radius: 12px; padding: 5px 9px; font-weight: 900; font-size: 11px; line-height: 1.25; min-width: 64px; }
        .da-pill small { display: block; font-size: 9px; font-weight: 700; opacity: .85; }
        .da-chip { font-size: 9.5px; font-weight: 800; border-radius: 999px; padding: 1px 7px; }
        .da-empty { text-align: center; color: #94a3b8; font-size: 12px; font-weight: 700; padding: 34px 10px; }
        /* ساعتِ ایران زیرِ نام و نقشِ کاربر (iran-time.js، بر اساسِ ساعتِ سرور) */
        .hdr-clock { display: inline-flex; align-items: center; gap: 4px; margin-top: 3px; padding: 1px 7px; border-radius: 999px; width: max-content;
                     font-size: 10px; font-weight: 800; color: #0369a1; background: linear-gradient(90deg, #e0f2fe, #ede9fe); border: 1px solid #bae6fd;
                     font-variant-numeric: tabular-nums; white-space: nowrap; direction: rtl; }
        .hdr-clock i { font-size: 9px; color: #0284c7; }
        .hdr-clock .hc-sep { color: #94a3b8; }
        .hdr-clock .hc-t { color: #4338ca; min-width: 46px; display: inline-block; text-align: left; direction: ltr; }
        @media (max-width: 640px) { .hdr-clock .hc-w { display: none; } }
        /* موبایل: سربرگ در عرضِ صفحه جا شود (دکمه‌ی ویرایش پنهان؛ خودِ عکسِ پروفایل هم همان را باز می‌کند) */
        @media (max-width: 640px) {
            header.glass-header { padding-left: 10px !important; padding-right: 10px !important; }
            header.glass-header > div:first-child { gap: 10px !important; }
            header.glass-header > div:last-child { gap: 8px !important; }
            header.glass-header > div:last-child > div:last-child { padding-right: 8px !important; gap: 6px !important; }
            .hdr-edit-btn { display: none !important; }
            .hdr-user-name { max-width: 104px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .hdr-clock { font-size: 9px; padding: 1px 5px; }
        }
    .ancr-cnt{display:flex;flex-direction:column;gap:4px;background:#fff;border:2px solid #e0e7ff;border-radius:14px;padding:8px 10px}
    .ancr-cnt span{font-size:11px;font-weight:800;color:#4338ca}
    .ancr-cnt input{border:0;outline:0;font-size:18px;font-weight:900;color:#1e293b;width:100%;text-align:center;background:transparent}
    .ancr-cnt:focus-within{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
    .trk-table thead th{background:#f1f5f9;color:#475569;font-weight:800;padding:8px 10px;border-bottom:2px solid #cbd5e1}
    .trk-table tbody td{padding:8px 10px;border-top:1px solid #e2e8f0}
    .trk-table tbody tr:hover{background:#f8fafc}
    /* جدول‌های داخلِ پنجره‌های صدور: کادرِ پررنگ‌تر و منظم برای هر ردیف */
    #iq-detail-modal table, #il-detail-modal table, #creq-detail-modal table, #bundle-modal table, #cissue-modal table {
        border-collapse: separate; border-spacing: 0; border: 2px solid #cbd5e1; border-radius: 14px; overflow: hidden; }
    #iq-detail-modal table thead th, #il-detail-modal table thead th, #creq-detail-modal table thead th, #bundle-modal table thead th {
        background: #f1f5f9; color: #334155; font-weight: 800; border-bottom: 2px solid #cbd5e1; }
    #iq-detail-modal table tbody td, #il-detail-modal table tbody td, #creq-detail-modal table tbody td, #bundle-modal table tbody td {
        border-top: 1.5px solid #e2e8f0; }
    #iq-detail-modal table th + th, #iq-detail-modal table td + td, #il-detail-modal table th + th, #il-detail-modal table td + td,
    #creq-detail-modal table th + th, #creq-detail-modal table td + td, #bundle-modal table th + th, #bundle-modal table td + td {
        border-right: 1px solid #edf1f6; }
    #iq-detail-modal table tbody tr:nth-child(even), #il-detail-modal table tbody tr:nth-child(even), #creq-detail-modal table tbody tr:nth-child(even) { background: #fbfcfe; }
    #iq-detail-modal table tbody tr:hover, #il-detail-modal table tbody tr:hover, #creq-detail-modal table tbody tr:hover { background: #eef2ff; }
    /* صفحه‌ی شرکت‌ها */
    .cmx-hero{position:relative;overflow:hidden;border-radius:24px;padding:22px;color:#fff;background:linear-gradient(125deg,#1e1b4b 0%,#3730a3 45%,#0e7490 100%);box-shadow:0 18px 40px -18px rgba(55,48,163,.55)}
    .cmx-hero:before{content:'';position:absolute;left:-60px;top:-80px;width:260px;height:260px;border-radius:50%;background:rgba(255,255,255,.08)}
    .cmx-hero:after{content:'';position:absolute;left:120px;bottom:-110px;width:220px;height:220px;border-radius:50%;background:rgba(34,211,238,.15)}
    .cmx-hero-ic{width:52px;height:52px;border-radius:16px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:22px;backdrop-filter:blur(4px)}
    .cmx-btn{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:900;padding:9px 14px;border-radius:12px;transition:all .15s}
    .cmx-btn-w{background:#fff;color:#3730a3}.cmx-btn-w:hover{background:#eef2ff}
    .cmx-btn-g{background:#10b981;color:#fff}.cmx-btn-g:hover{background:#059669}
    .cmx-btn-t{background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.3)}.cmx-btn-t:hover{background:rgba(255,255,255,.24)}
    .cmx-kpi{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:16px;padding:10px 14px}
    .cmx-kpi b{display:block;font-size:22px;font-weight:900}.cmx-kpi span{font-size:11px;opacity:.85}
    .cmx-card{background:#fff;border:2px solid #e2e8f0;border-radius:20px;overflow:hidden;transition:all .18s;display:flex;flex-direction:column}
    .cmx-card:hover{border-color:#a5b4fc;box-shadow:0 14px 30px -16px rgba(79,70,229,.45);transform:translateY(-2px)}
    .cmx-card-top{padding:14px 16px;display:flex;gap:12px;align-items:center;border-bottom:2px solid #f1f5f9;background:linear-gradient(180deg,#f8fafc,#fff)}
    .cmx-av{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:18px;flex-shrink:0}
    .cmx-chip{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:800;padding:3px 9px;border-radius:999px}
    .cmx-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:10px 12px}
    .cmx-stat{background:#f8fafc;border:1px solid #eef2f7;border-radius:12px;text-align:center;padding:6px 2px}
    .cmx-stat b{display:block;font-size:15px;font-weight:900;color:#1e293b}.cmx-stat span{font-size:9.5px;color:#64748b;font-weight:700}
    .cmx-act{display:flex;gap:6px;padding:10px 12px;border-top:2px solid #f1f5f9;margin-top:auto}
    .cmx-act button{flex:1;font-size:11px;font-weight:800;padding:7px;border-radius:10px}
    .cmx-sec{border:2px solid #eef2f7;border-radius:16px;padding:12px;margin-bottom:12px}
    .cmx-sec h4{font-size:12px;font-weight:900;color:#4338ca;margin-bottom:10px}
    .cmi-step{display:inline-flex;width:20px;height:20px;border-radius:50%;background:#059669;color:#fff;align-items:center;justify-content:center;font-size:10px;margin-left:4px}
    .cmi-cols td,.cmi-cols th{padding:7px 9px;border-top:1px solid #e2e8f0;vertical-align:top}
    .cmi-cols th{background:#f1f5f9;font-weight:800;color:#334155;border-top:0}
    .ii-w.pf-miss{border-color:#fda4af !important;background:#fff1f2 !important}
</style>
<script>window.__SRV = {s: <?php echo (int)round(microtime(true) * 1000); ?>, c: Date.now()};</script>
<script src="iran-time.js?v=1"></script>
<link rel="stylesheet" href="plate.css?v=2">
<link rel="stylesheet" href="ui-scroll.css?v=1">
</head>
<body class="h-screen flex flex-col">

    <div class="hero-bg"></div>
    <div id="tsparticles"></div>
    <div id="notif-stack" aria-live="polite"></div>
    <datalist id="plate-letters-dl"><?php foreach (['الف','ب','پ','ت','ث','ج','د','ز','ژ','س','ش','ص','ط','ع','ف','ق','ک','گ','ل','م','ن','و','ه','ی'] as $pl) echo '<option value="' . $pl . '">'; ?></datalist>
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
            <?php $ctxAdmin = ($_SESSION['role'] ?? '') === 'ADMIN'; // ویرایش، تغییر وضعیت و حذف فقط برای مدیر کل ?>
            <?php if ($ctxAdmin): ?>
            <div class="ctx-item text-blue-400 hover-target" onclick="openEditModal()"><i class="fas fa-edit w-4 text-center"></i> بررسی و ویرایش پرونده</div>
            <?php endif; ?>
            <div class="ctx-item text-gray-300 hover-target" onclick="ctxExecuteRow('copy-national')"><i class="far fa-copy w-4 text-center"></i> کپی کد ملی</div>
            <?php if ($ctxAdmin): ?>
            <div class="ctx-item text-emerald-400 hover-target" onclick="ctxExecuteRow('mark-issued')"><i class="fas fa-check-circle w-4 text-center"></i> علامت‌گذاری «صادر شده»</div>
            <div class="ctx-item text-amber-400 hover-target" onclick="ctxExecuteRow('mark-waiting')"><i class="fas fa-hourglass-half w-4 text-center"></i> علامت‌گذاری «منتظر مدارک»</div>
            <div class="ctx-item text-red-400 hover-target" onclick="confirmDeleteRow()"><i class="fas fa-trash-alt w-4 text-center"></i> حذف پرونده از سیستم</div>
            <?php endif; ?>
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
                <span class="brand-name hidden sm:inline tracking-tight whitespace-nowrap" style="white-space:nowrap">بیمه با ما</span>
            </div>
            <!-- منوی اصلی: پنج بخش؛ کارهای مرتبط داخلِ زیرمنوهای درختی (menu-sub) کنارِ هم آمده‌اند -->
            <nav id="main-nav" class="hidden lg:flex flex-col lg:flex-row gap-1 lg:gap-5 font-bold text-xs text-slate-500 absolute lg:static top-full right-0 left-0 lg:top-auto bg-white lg:bg-transparent shadow-xl lg:shadow-none p-4 lg:p-0 z-50 max-h-[75vh] overflow-y-auto lg:overflow-visible rounded-b-2xl lg:rounded-none">

                <?php if ($isParsian) echo $vrMenuHtml . $chatNavHtml . $personnelMenuHtml; ?>
                <?php if (!$isParsian): ?>

                <?php if (!$isLiaison): ?>
                <a href="#" onclick="switchTab('dashboard')" id="nav-dashboard" class="nav-item text-blue-600 hover-target transition-colors block lg:inline py-2 lg:py-0"><i class="fas fa-home ml-1"></i> داشبورد</a>
                <?php endif; ?>
                <?php echo $chatNavHtml; ?>

                <!-- ===== صدور بیمه: کارکنان، شرکت‌ها، لیست صدور/صادره‌ها و گزارش بازدید ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-file-shield ml-1"></i> صدور بیمه
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                        <span id="ops-badge" class="hidden bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full mr-1"></span>
                    </button>
                    <div class="menu-panel">
                        <?php if ($canSeeCompanies): ?>
                        <a href="#" onclick="switchTab('<?php echo $isLiaison ? 'issued-list' : 'issue-queue'; ?>')" class="nav-item menu-link font-black text-teal-700"><i class="fas fa-stamp ml-2"></i> مرکز صدور <small class="text-[10px] text-slate-400 font-bold mr-1">در حال صدور · صادره · گروهی</small></a>
                        <div class="menu-sep"></div>
                        <?php endif; ?>
                        <?php if (!$isLiaison): ?>
                        <div class="menu-sub">
                            <button type="button" class="menu-sub-trigger" aria-expanded="false" onclick="toggleMenuSub(this, event)"><i class="fas fa-id-card ml-2"></i><span class="ms-t">کارکنان (کسر از حقوق)<small>پرونده، بازدید سلامت، صدور</small></span><i class="fas fa-chevron-left ms-arrow"></i></button>
                            <div class="menu-subpanel">
                                <div class="ms-head">کارکنان (کسر از حقوق)</div>
                                <a href="#" onclick="switchTab('records')" id="nav-records" class="nav-item menu-link"><i class="fas fa-folder-open ml-2"></i> مدیریت پرونده‌ها</a>
                                <a href="#" onclick="switchTab('health')" id="nav-health" class="nav-item menu-link"><i class="fas fa-heart-pulse ml-2"></i> بازدید سلامت و مدارک</a>
                                <a href="#" onclick="switchTab('approved-reviews')" id="nav-approved-reviews" class="nav-item menu-link"><i class="fas fa-circle-check ml-2"></i> بازدیدهای تاییدشده</a>
                                <a href="#" onclick="switchTab('cases')" id="nav-cases" class="nav-item menu-link"><i class="fas fa-file-signature ml-2"></i> صدور بیمه‌نامه</a>
                                <a href="#" onclick="switchTab('queue')" id="nav-queue" class="nav-item menu-link"><i class="fas fa-microchip ml-2"></i> صف پردازش OCR <small class="text-[10px] text-slate-400 font-bold mr-1">درخواست‌های ربات</small></a>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($canSeeCompanies): ?>
                        <div class="menu-sub">
                            <button type="button" class="menu-sub-trigger" aria-expanded="false" onclick="toggleMenuSub(this, event)"><i class="fas fa-building ml-2"></i><span class="ms-t">شرکت‌ها<small>درخواست، مدارک، مدیریت</small></span><i class="fas fa-chevron-left ms-arrow"></i></button>
                            <div class="menu-subpanel">
                                <div class="ms-head">شرکت‌ها</div>
                                <a href="#" onclick="switchTab('companies-requests')" id="nav-companies-requests" class="nav-item menu-link"><i class="fas fa-file-lines ml-2"></i> درخواست‌های شرکتی</a>
                                <a href="#" onclick="switchTab('companies-inbox')" id="nav-companies-inbox" class="nav-item menu-link"><i class="fas fa-inbox ml-2"></i> صندوق ورودی مدارک</a>
                                <?php if (!$isLiaison): ?><a href="#" onclick="switchTab('companies-manage')" id="nav-companies-manage" class="nav-item menu-link"><i class="fas fa-gear ml-2"></i> مدیریت شرکت‌ها</a><?php endif; ?>
                            </div>
                        </div>
                        <div class="menu-sub">
                            <button type="button" class="menu-sub-trigger" aria-expanded="false" onclick="toggleMenuSub(this, event)"><i class="fas fa-stamp ml-2"></i><span class="ms-t">صدور و صادره‌ها<small>لیست صدور و بیمه‌نامه‌های صادره</small></span><i class="fas fa-chevron-left ms-arrow"></i></button>
                            <div class="menu-subpanel">
                                <div class="ms-head">صدور و صادره‌ها</div>
                                <?php if (!$isLiaison): ?><a href="#" onclick="switchTab('issue-queue')" id="nav-issue-queue" class="nav-item menu-link"><i class="fas fa-list-check ml-2"></i> در حال صدور</a><?php endif; ?>
                                <a href="#" onclick="switchTab('issued-list')" id="nav-issued-list" class="nav-item menu-link"><i class="fas fa-file-circle-check ml-2"></i> صادره‌ها</a>
                                <?php if (!$isLiaison): ?><a href="#" onclick="switchTab('issue-group')" id="nav-issue-group" class="nav-item menu-link"><i class="fas fa-layer-group ml-2"></i> صدور گروهی (فایل بیمه‌گر)</a><?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
<?php echo $vrSubHtml; ?>
                    </div>
                </div>

                <!-- ===== مالی: داشبورد، اقساط و دریافت‌ها، تسویه و گزارش‌ها ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-calculator ml-1"></i> مالی
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('fin-dashboard')" id="nav-fin-dashboard" class="nav-item menu-link"><i class="fas fa-chart-pie ml-2"></i> داشبورد مالی</a>
                        <a href="#" onclick="switchTab('fin-installments')" id="nav-fin-installments" class="nav-item menu-link font-black text-indigo-700"><i class="fas fa-list-ol ml-2"></i> مرکز اقساط <small class="text-[10px] text-slate-400 font-bold mr-1">دریافت و پرداخت روی هر قسط</small></a>
                        <a href="#" onclick="switchTab('fin-payments')" id="nav-fin-payments" class="nav-item menu-link"><i class="fas fa-arrow-right-arrow-left ml-2"></i> دریافت‌ها، پرداخت‌ها و چک‌ها</a>
                        <a href="#" onclick="switchTab('fin-tracking')" id="nav-fin-tracking" class="nav-item menu-link"><i class="fas fa-magnifying-glass-location ml-2"></i> پیگیری <small class="text-[10px] text-slate-400 font-bold mr-1">کد رهگیریِ قسط یا شناسه‌ی درخواست</small></a>
                        <a href="#" onclick="switchTab('fin-invoices')" id="nav-fin-invoices" class="nav-item menu-link"><i class="fas fa-file-invoice ml-2"></i> صورتحساب‌ها</a>
                        <div class="menu-sub">
                            <button type="button" class="menu-sub-trigger" aria-expanded="false" onclick="toggleMenuSub(this, event)"><i class="fas fa-scale-balanced ml-2"></i><span class="ms-t">گزارش‌ها و ابزار<small>مغایرت، شرکت‌ها، تسویه‌های قبلی</small></span><i class="fas fa-chevron-left ms-arrow"></i></button>
                            <div class="menu-subpanel">
                                <div class="ms-head">گزارش‌ها و ابزار</div>
                                <a href="#" onclick="switchTab('fin-reconcile')" id="nav-fin-reconcile" class="nav-item menu-link"><i class="fas fa-scale-balanced ml-2"></i> مغایرت‌گیری با اکسل</a>
                                <?php if ($canSeeCompanies): ?><a href="#" onclick="switchTab('companies-finance')" id="nav-companies-finance" class="nav-item menu-link"><i class="fas fa-sack-dollar ml-2"></i> گزارش مالی شرکت‌ها</a><?php endif; ?>
                            </div>
                        </div>
                        <?php if($_SESSION['role'] === 'ADMIN'): ?>
                        <div class="menu-sep"></div>
                        <a href="#" onclick="switchTab('fin-settings')" id="nav-fin-settings" class="nav-item menu-link"><i class="fas fa-money-check-dollar ml-2"></i> تنظیمات مالی</a>
                        <?php endif; ?>
                    </div>
                </div>

<?php echo $personnelMenuHtml; ?>
                <!-- ===== بایگانی و مدیریت: بایگانی، کاربران و سامانه ===== -->
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-sliders ml-1"></i> بایگانی و مدیریت
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab('filemanager')" id="nav-filemanager" class="nav-item menu-link"><i class="fas fa-folder-tree ml-2"></i> بایگانی فایل‌ها</a>
                        <?php if (!$isLiaison): ?>
                        <div class="menu-sub">
                            <button type="button" class="menu-sub-trigger" aria-expanded="false" onclick="toggleMenuSub(this, event)"><i class="fas fa-users ml-2"></i><span class="ms-t">کاربران<small>ربات بله، کاربران پنل، لاگ ورود</small></span><i class="fas fa-chevron-left ms-arrow"></i></button>
                            <div class="menu-subpanel">
                                <div class="ms-head">کاربران</div>
                                <a href="#" onclick="switchTab('users')" id="nav-users" class="nav-item menu-link"><i class="fas fa-robot ml-2"></i> کاربران ربات بله</a>
                                <?php if($_SESSION['role'] === 'ADMIN'): ?><a href="#" onclick="switchTab('staff-users')" id="nav-staff-users" class="nav-item menu-link"><i class="fas fa-user-shield ml-2"></i> کاربران (داخلی و شرکتی)</a><a href="#" onclick="switchTab('login-logs')" id="nav-login-logs" class="nav-item menu-link"><i class="fas fa-right-to-bracket ml-2"></i> لاگ ورود و خروج</a><?php endif; ?>
                            </div>
                        </div>
                        <?php if($_SESSION['role'] === 'ADMIN'): ?>
                        <div class="menu-sep"></div>
                        <a href="#" onclick="switchTab('settings')" id="nav-settings" class="nav-item menu-link"><i class="fas fa-cogs ml-2"></i> تنظیمات سیستم <small class="text-[10px] text-slate-400 font-bold mr-1">ربات، مرخصی، پشتیبان‌گیری</small></a>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; /* !$isParsian */ ?>
            </nav>
        </div>

        <div class="flex items-center gap-3 lg:gap-5">
            <span id="notif-bell-mount" class="inline-flex"></span>
            <a href="logout.php" class="text-red-400 hover:text-red-600 transition-colors hover-target text-xl" title="خروج از سیستم"><i class="fas fa-power-off"></i></a>
            <div class="flex items-center gap-3 border-r border-slate-300 pr-5">
                <button onclick="document.getElementById('profile-modal').classList.add('active')" class="hdr-edit-btn w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-blue-100 hover:text-blue-600 flex items-center justify-center transition-all hover-target shadow-sm" title="ویرایش پروفایل"><i class="fas fa-pen text-xs"></i></button>
                <div class="flex flex-col text-right justify-center">
                    <p class="hdr-user-name text-sm font-black text-slate-700 leading-tight"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                    <p class="text-[10px] font-bold text-slate-400 mt-0.5"><?php echo htmlspecialchars(role_fa($realRole)); ?><?php if (!empty($permBoot['custom'])): ?> <span class="text-[9px] bg-violet-100 text-violet-700 rounded-full px-1.5 py-px mr-0.5" title="مدیر کل دسترسیِ صفحه‌به‌صفحه برای شما تعیین کرده است">دسترسی سفارشی</span><?php endif; ?></p>
                    <p id="hdr-clock" class="hdr-clock" title="تاریخ و ساعتِ ایران (ساعتِ سرور)"><i class="far fa-clock"></i><span class="hc-d"></span><span class="hc-sep">-</span><span class="hc-t"></span></p>
                </div>
                <button type="button" id="me-avatar" onclick="document.getElementById('profile-modal').classList.add('active')" title="عکس و پروفایل" class="w-11 h-11 rounded-2xl overflow-visible flex items-center justify-center hover:scale-105 transition-transform"><span class="w-11 h-11 bg-gradient-to-tr from-blue-500 to-cyan-400 text-white rounded-full flex items-center justify-center text-xl shadow-md shadow-blue-500/30"><i class="fas fa-user"></i></span></button>
            </div>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto p-6 lg:p-8 flex flex-col z-10">
        <!-- ===== اطلاعات پرسنلی ===== -->
        <div id="tab-my-work" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="wk-my-root"></div></div>
        <div id="tab-staff-work" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="wk-staff-root"></div></div>
        
        <!-- ======================= تب داشبورد و آمار ======================= -->
        <?php if ($vrAccess): ?>
        <!-- ======================= گزارش بازدید (visit-reports*.js) ======================= -->
        <div id="tab-vr-build" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden">
            <div class="flex items-center gap-3 mb-4"><span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white flex items-center justify-center shadow-lg shadow-indigo-500/30"><i class="fas fa-file-circle-plus"></i></span>
                <div><h2 class="text-lg font-black text-slate-800">ساخت گزارش بازدید</h2><p class="text-[11px] font-bold text-slate-400">PDF و Word مستقیم روی همین سرور ساخته و بایگانی می‌شود</p></div></div>
            <div id="vr-build-root"></div>
        </div>
        <div id="tab-vr-list" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden">
            <div class="flex items-center gap-3 mb-4"><span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-white flex items-center justify-center shadow-lg shadow-sky-500/30"><i class="fas fa-folder-open"></i></span>
                <div><h2 class="text-lg font-black text-slate-800">گزارش‌های صادرشده</h2><p class="text-[11px] font-bold text-slate-400"><?php echo ($_SESSION['role'] ?? '') === 'ADMIN' ? 'همه‌ی گزارش‌های بازدید' : 'گزارش‌هایی که خودتان صادر کرده‌اید'; ?></p></div></div>
            <div id="vr-list-root"></div>
        </div>
        <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
        <div id="tab-vr-settings" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden">
            <div class="flex items-center gap-3 mb-4"><span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-slate-600 to-slate-900 text-white flex items-center justify-center shadow-lg"><i class="fas fa-sliders"></i></span>
                <div><h2 class="text-lg font-black text-slate-800">تنظیمات گزارش</h2><p class="text-[11px] font-bold text-slate-400">انواع گزارش، قالب‌ها، الگوریتم‌های استخراج، فیلدها، بازدیدکننده‌ها و بیمه‌گذاران</p></div></div>
            <div id="vr-settings-root"></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

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

            <!-- ===== اعلان‌های صدور: (۱) انقضای نزدیکِ بیمه‌نامه‌های شرکتیِ صادرنشده  (۲) درخواست‌های کارکنانِ صادرنشده ===== -->
            <div id="dash-alerts" class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="card p-0 overflow-hidden da-card">
                    <div class="da-head" style="--c1:#f43f5e;--c2:#f97316">
                        <span class="da-ic"><i class="fas fa-hourglass-half"></i></span>
                        <div class="flex-1 min-w-0"><h2>انقضای نزدیکِ بیمه‌نامه‌های شرکتی</h2><p>صادرنشده‌هایی که تا <b id="da-days">۱۵</b> روزِ دیگر منقضی می‌شوند (یا گذشته‌اند) · روی هر ردیف بزنید</p></div>
                        <span class="da-badge" id="da-c-count">—</span>
                    </div>
                    <div id="da-company" class="da-list"><p class="da-empty"><i class="fas fa-spinner fa-spin"></i></p></div>
                </div>
                <div class="card p-0 overflow-hidden da-card" id="da-personnel-card">
                    <div class="da-head" style="--c1:#6366f1;--c2:#0ea5e9">
                        <span class="da-ic"><i class="fas fa-user-clock"></i></span>
                        <div class="flex-1 min-w-0"><h2>درخواست‌های کارکنانِ صادرنشده</h2><p>به ترتیبِ زمانِ ثبت (قدیمی‌ترها اول) · روی هر ردیف بزنید تا صفحه‌ی صدور باز شود</p></div>
                        <span class="da-badge" id="da-p-count">—</span>
                    </div>
                    <div id="da-personnel" class="da-list"><p class="da-empty"><i class="fas fa-spinner fa-spin"></i></p></div>
                </div>
            </div>

            <!-- ===== نمودارهای صدور و فروش (بازه‌ی شمسی + منبع) ===== -->
            <div class="card p-4 md:p-5 dc-wrap">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="font-black text-slate-800 text-base"><i class="fas fa-chart-column text-indigo-500 ml-1"></i>گزارش صدور و فروش</h2>
                        <p id="dc-range" class="text-[11px] text-slate-400 mt-0.5">&nbsp;</p>
                    </div>
                    <div class="dc-seg" id="dc-source" <?php echo ($_SESSION['role'] ?? '') === 'ADMIN' ? '' : 'style="display:none"'; ?>>
                        <button type="button" data-v="ALL" class="active" onclick="setDcSource('ALL')">همه</button>
                        <button type="button" data-v="PERSONNEL" onclick="setDcSource('PERSONNEL')"><span class="dc-sw p"></span>پرسنلی</button>
                        <button type="button" data-v="COMPANY" onclick="setDcSource('COMPANY')"><span class="dc-sw c"></span>شرکتی</button>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 mb-4">
                    <div class="dc-seg dc-period" id="dc-period">
                        <button type="button" data-v="TODAY" onclick="setDcPeriod('TODAY')">امروز</button>
                        <button type="button" data-v="MONTH" onclick="setDcPeriod('MONTH')">یک ماه</button>
                        <button type="button" data-v="3M" onclick="setDcPeriod('3M')">سه ماه</button>
                        <button type="button" data-v="6M" class="active" onclick="setDcPeriod('6M')">شش ماه</button>
                        <button type="button" data-v="YEAR" onclick="setDcPeriod('YEAR')">یک سال</button>
                        <button type="button" data-v="JYEAR" onclick="setDcPeriod('JYEAR')"><i class="far fa-calendar ml-1"></i>سال شمسی</button>
                        <button type="button" data-v="RANGE" onclick="setDcPeriod('RANGE')"><i class="fas fa-sliders ml-1"></i>بازه‌ی دلخواه</button>
                    </div>
                    <div id="dc-jyear-box" class="dc-pop">
                        <select id="dc-year" onchange="loadDashCharts()" class="border rounded-lg px-2 py-1.5 text-xs font-bold"></select>
                    </div>
                    <div id="dc-range-box" class="dc-pop flex-wrap items-center gap-1 text-[11px] font-bold text-slate-500">
                        از <select id="dc-fm" class="border rounded-lg px-1.5 py-1 text-xs"></select><select id="dc-fy" class="border rounded-lg px-1.5 py-1 text-xs"></select>
                        تا <select id="dc-tm" class="border rounded-lg px-1.5 py-1 text-xs"></select><select id="dc-ty" class="border rounded-lg px-1.5 py-1 text-xs"></select>
                        <button type="button" onclick="loadDashCharts()" class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg px-3 py-1 text-xs">نمایش</button>
                    </div>
                </div>
                <div class="dc-grid">
                    <div class="dc-panel">
                        <div class="flex items-start justify-between gap-2">
                            <div><p class="text-xs font-bold text-slate-500">تعداد بیمه‌نامه‌های صادره</p>
                                <p class="text-2xl font-black text-slate-800 mt-1"><span id="dc-count-total">۰</span> <span class="text-xs text-slate-400 font-bold">فقره</span></p></div>
                            <span id="dc-count-delta" class="dc-delta"></span>
                        </div>
                        <div class="dc-legend" id="dc-count-legend"></div>
                        <div class="dc-chart" id="dc-count-chart"></div>
                    </div>
                    <div class="dc-panel">
                        <div class="flex items-start justify-between gap-2">
                            <div><p class="text-xs font-bold text-slate-500">مبلغ فروش حق بیمه</p>
                                <p class="text-2xl font-black text-slate-800 mt-1"><span id="dc-amount-total">۰</span> <span class="text-xs text-slate-400 font-bold">ریال</span></p></div>
                            <span id="dc-amount-delta" class="dc-delta"></span>
                        </div>
                        <div class="dc-legend" id="dc-amount-legend"></div>
                        <div class="dc-chart" id="dc-amount-chart"></div>
                    </div>
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
                    <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): // ثبت دستی فقط برای مدیر کل ?>
                    <button onclick="openManualIntroModal()" class="bg-gradient-to-l from-indigo-600 to-blue-600 hover:from-indigo-700 hover:to-blue-700 text-white px-4 py-2 rounded-xl font-bold text-sm shadow-lg shadow-indigo-500/30 transition-all hover-target"><i class="fas fa-envelope-open-text ml-1"></i> ثبت دستی معرفی‌نامه</button>
                    <?php endif; ?>
                    <button onclick="loadRecords()" class="bg-blue-50 text-blue-600 hover:bg-blue-100 px-4 py-2 rounded-lg font-bold text-sm hover-target transition-colors"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی جدول</button>
                </div>
            </div>

            <!-- بازه‌ی زمانی: روی کارت‌ها و جدول با هم اثر دارد -->
            <div class="card p-3 flex flex-wrap items-center gap-2">
                <span class="text-xs font-black text-slate-500 ml-1"><i class="fas fa-calendar-days text-amber-500 ml-1"></i>بازه:</span>
                <div id="rec-period" class="rec-seg">
                    <button data-p="month">این ماه</button><button data-p="3">۳ ماه</button><button data-p="6">۶ ماه</button><button data-p="12">۱ سال</button><button data-p="all" class="on">کل</button><button data-p="custom"><i class="fas fa-sliders ml-1"></i>تاریخ دلخواه</button>
                </div>
                <div id="rec-custom" class="hidden flex items-center gap-1 text-xs font-bold text-slate-500">
                    از <input id="rec-from" placeholder="۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-2 py-1.5 w-28 text-center">
                    تا <input id="rec-to" placeholder="۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-2 py-1.5 w-28 text-center">
                    <button onclick="applyRecordsPeriod()" class="bg-amber-500 text-white rounded-lg px-3 py-1.5"><i class="fas fa-check"></i></button>
                </div>
                <span id="rec-range-label" class="text-[11px] font-bold text-slate-400 mr-auto"></span>
            </div>

            <!-- چهار کارت: معرفی‌نامه‌ها، بیمه‌نامه‌ها، حق بیمه، اقساطِ معوق -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="rec-card" style="--c1:#6366f1;--c2:#8b5cf6"><i class="fas fa-envelope-open-text rec-ic"></i>
                    <p class="rec-t">معرفی‌نامه‌های صادرشده</p><p class="rec-v" id="rc-letters">—</p><p class="rec-s" id="rc-letters-s">&nbsp;</p></div>
                <div class="rec-card" style="--c1:#0ea5e9;--c2:#2563eb"><i class="fas fa-file-shield rec-ic"></i>
                    <p class="rec-t">بیمه‌نامه‌های صادرشده</p><p class="rec-v" id="rc-issued">—</p><p class="rec-s" id="rc-issued-s">&nbsp;</p></div>
                <div class="rec-card" style="--c1:#10b981;--c2:#059669"><i class="fas fa-sack-dollar rec-ic"></i>
                    <p class="rec-t">حق بیمه‌ی ایجادشده</p><p class="rec-v" id="rc-premium">—</p><p class="rec-s" id="rc-premium-s">&nbsp;</p></div>
                <div class="rec-card" style="--c1:#f43f5e;--c2:#e11d48"><i class="fas fa-hourglass-end rec-ic"></i>
                    <p class="rec-t">اقساطِ معوق (سررسید گذشته)</p><p class="rec-v" id="rc-overdue">—</p><p class="rec-s" id="rc-overdue-s">&nbsp;</p></div>
            </div>

            <div class="card p-3 flex flex-wrap items-center gap-2">
                <input type="text" id="records-search-input" placeholder="جستجو: نام، کد ملی، کد پرسنلی، شرکت، ماه، تاریخ..." class="flex-1 min-w-[220px] border rounded-lg px-3 py-2 text-xs font-bold outline-none focus:border-amber-500">
                <select id="rec-month" class="border rounded-lg px-2 py-2 text-xs font-bold"><option value="">همه‌ی ماه‌های معرفی‌نامه</option></select>
                <select id="rec-issued-f" class="border rounded-lg px-2 py-2 text-xs font-bold">
                    <option value="">همه</option><option value="yes">دارای صادره</option><option value="no">بدون صادره</option><option value="month">صادره در ماهِ معرفی‌نامه</option></select>
                <button onclick="exportRecordsExcel()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-bold text-xs"><i class="fas fa-file-excel ml-1"></i> خروجی اکسل (همین فهرست)</button>
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto max-h-[560px]">
                    <table class="w-full text-right">
                        <thead class="bg-slate-50 text-slate-500 text-xs font-bold border-b border-slate-200 sticky top-0 shadow-sm">
                            <tr>
                                <th class="p-4">ردیف</th>
                                <th class="p-4">نام کامل پرسنل</th>
                                <th class="p-4">کد ملی</th>
                                <th class="p-4">کد پرسنلی</th>
                                <th class="p-4">تاریخ صدور معرفی‌نامه<br><span class="font-normal text-slate-400">تاریخ ثبت</span></th>
                                <th class="p-4">ماه معرفی‌نامه</th>
                                <th class="p-4">بیمه‌نامه‌های صادرشده</th>
                                <th class="p-4">حق بیمه</th>
                                <th class="p-4">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="records-body" class="text-sm font-bold divide-y divide-slate-100">
                            <tr><td colspan="9" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin text-xl"></i> در حال بارگذاری پرونده‌ها...</td></tr>
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

        <!-- ======================= تب گفتگوها (پیام‌رسانِ یکپارچه، chat-ui.js) =======================
             همه‌ی گفتگوها یک‌جا: کارکنان (ربات/وب‌اپ)، شرکت‌ها و همکارانِ داخلی. هر نقش فقط
             گفتگوهایی را می‌بیند که سرور (api/_chat_core.php) اجازه می‌دهد. -->
        <div id="tab-tickets" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden">
            <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
                <div class="flex items-center gap-3">
                    <span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30"><i class="fas fa-comments"></i></span>
                    <div><h1 class="text-lg font-black text-slate-800">گفتگوها</h1>
                    <p class="text-[11px] font-bold text-slate-400">پیام‌ها، فایل‌ها و موضوعِ هر پیام (درخواستِ مربوط) - کلیک‌راست روی هر پیام برای پاسخ، ویرایش و حذف</p></div>
                </div>
                <?php if (!$isParsian && !$isLiaison): ?>
                <div class="flex gap-2">
                    <button onclick="switchGoftegoSub('messenger')" id="goftego-sub-messenger" class="px-4 py-2 rounded-lg text-xs font-bold bg-emerald-600 text-white">💬 پیام‌رسان</button>
                    <button onclick="switchGoftegoSub('botchats')" id="goftego-sub-botchats" class="px-4 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-500">🤖 آرشیو کاملِ چت‌های ربات</button>
                    <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?><button onclick="switchGoftegoSub('archive')" id="goftego-sub-archive" class="px-4 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-500">🗄 بایگانیِ همه‌ی گفتگوها</button><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div id="goftego-panel-messenger"><div id="chat-root" style="height: calc(100vh - 200px); min-height: 520px;"></div></div>
            <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?><div id="goftego-panel-archive" class="hidden"><div id="chat-archive-root"></div></div><?php endif; ?>

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

            <!-- بخش‌های قدیمیِ گفتگو: دیگر نمایش داده نمی‌شوند (پیام‌رسانِ بالا جایشان را گرفته)،
                 ولی عناصرشان می‌مانند چون اسکریپت‌های قدیمیِ همین صفحه به آن‌ها گوش می‌دهند -->
            <div id="goftego-legacy" class="hidden" aria-hidden="true">
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
        </div>


        <!-- ======================= تب صدور بیمه‌نامه ======================= -->
        <!-- ======================= بازدیدهای تاییدشده ======================= -->
        <div id="tab-approved-reviews" class="tab-content max-w-7xl mx-auto w-full space-y-4 flex-1 hidden">
            <div class="flex justify-between items-center flex-wrap gap-2">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-circle-check text-emerald-500 ml-2"></i>بازدیدهای تاییدشده</h1>
                    <p class="text-xs text-slate-400 mt-1">هر درخواست یک ردیف: چه چیزی، کی و توسط چه کسی تایید شد، و اگر قبلاً رد شده بود، علتش.</p>
                </div>
                <span class="flex gap-2">
                    <button onclick="switchTab('health')" class="bg-rose-50 text-rose-600 hover:bg-rose-100 px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-hourglass-half ml-1"></i> در انتظار تایید</button>
                    <button onclick="loadApprovedReviews()" class="bg-emerald-50 text-emerald-700 hover:bg-emerald-100 px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
                </span>
            </div>
            <div class="card p-3 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2 items-end">
                <label class="text-[11px] font-bold text-slate-500 col-span-2">جستجو
                    <input id="ar-q" oninput="renderApprovedReviews()" placeholder="نام، کد ملی، شناسه، پلاک، شرکت..." class="mt-1 w-full border rounded-lg px-3 py-2 text-xs"></label>
                <label class="text-[11px] font-bold text-slate-500">منبع
                    <select id="ar-source" onchange="renderApprovedReviews()" class="mt-1 w-full border rounded-lg px-2 py-2 text-xs">
                        <option value="">همه</option><option value="PERSONNEL">کارکنان</option><option value="COMPANY">شرکت‌ها</option><option value="FREE">بازدید آزاد</option></select></label>
                <label class="text-[11px] font-bold text-slate-500">نوع بیمه
                    <select id="ar-type" onchange="renderApprovedReviews()" class="mt-1 w-full border rounded-lg px-2 py-2 text-xs">
                        <option value="">همه</option><option value="THIRDPARTY">ثالث</option><option value="BODY">بدنه</option></select></label>
                <label class="text-[11px] font-bold text-slate-500">وضعیت بررسی
                    <select id="ar-complete" onchange="renderApprovedReviews()" class="mt-1 w-full border rounded-lg px-2 py-2 text-xs">
                        <option value="">همه</option><option value="1">کامل (مدارک + بازدید)</option><option value="0">بخشی</option><option value="rej">با سابقه‌ی رد</option></select></label>
                <label class="text-[11px] font-bold text-slate-500">تایید از تاریخ
                    <input id="ar-from" oninput="renderApprovedReviews()" placeholder="۱۴۰۵/۰۷/۰۱" dir="ltr" class="mt-1 w-full border rounded-lg px-2 py-2 text-xs"></label>
                <label class="text-[11px] font-bold text-slate-500">تا تاریخ
                    <input id="ar-to" oninput="renderApprovedReviews()" placeholder="۱۴۰۵/۰۷/۳۰" dir="ltr" class="mt-1 w-full border rounded-lg px-2 py-2 text-xs"></label>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <div class="bg-white border rounded-xl p-3 text-center"><p class="text-[11px] text-slate-500">همه (با فیلتر)</p><p id="ar-c-all" class="font-black text-xl">۰</p></div>
                <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 text-center"><p class="text-[11px] text-emerald-700">کامل</p><p id="ar-c-done" class="font-black text-xl text-emerald-700">۰</p></div>
                <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-center"><p class="text-[11px] text-amber-700">بخشی</p><p id="ar-c-part" class="font-black text-xl text-amber-700">۰</p></div>
                <div class="bg-rose-50 border border-rose-100 rounded-xl p-3 text-center"><p class="text-[11px] text-rose-700">با سابقه‌ی رد</p><p id="ar-c-rej" class="font-black text-xl text-rose-700">۰</p></div>
            </div>
            <p id="ar-history-note" class="hidden text-[11px] text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">برای ثبتِ تاریخچه‌ی کاملِ رد و تاییدها، مایگریشن migrations/015_review_log.sql را اجرا کنید. فعلاً فقط وضعیتِ فعلی نمایش داده می‌شود.</p>
            <div class="card overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3">#</th><th class="p-3">منبع</th><th class="p-3">شناسه</th><th class="p-3">بیمه‌گذار / شخص</th><th class="p-3">شرکت</th>
                        <th class="p-3">نوع</th><th class="p-3">پلاک</th><th class="p-3">مدارک</th><th class="p-3">بازدید سلامت</th><th class="p-3">آخرین تایید</th>
                        <th class="p-3">سابقه‌ی رد</th><th class="p-3">وضعیت پرونده</th><th class="p-3"></th></tr></thead>
                    <tbody id="ar-body"></tbody>
                </table>
            </div>
        </div>

        <!-- جزئیاتِ یک بازدیدِ تاییدشده -->
        <div id="ar-detail-modal" class="modal-overlay">
            <div class="modal-content w-full max-w-4xl p-5 relative" style="max-height:92vh; overflow-y:auto; margin:0 12px;">
                <button type="button" onclick="document.getElementById('ar-detail-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl"><i class="fas fa-times"></i></button>
                <h2 class="text-lg font-black text-slate-800 mb-3"><i class="fas fa-circle-check text-emerald-500 ml-2"></i><span id="ar-d-title">جزئیات</span></h2>
                <div id="ar-d-body" class="space-y-4"></div>
            </div>
        </div>

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
                    <option value="WITHDRAWN">خارج از فاز عملیاتی</option>
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
                <span class="flex gap-2 flex-wrap">
                    <button onclick="switchTab('approved-reviews')" class="bg-emerald-50 text-emerald-700 hover:bg-emerald-100 px-4 py-2 rounded-lg font-bold text-sm transition-colors hover-target"><i class="fas fa-circle-check ml-1"></i> بازدیدهای تاییدشده</button>
                    <button onclick="loadHealthTab(); loadDocsReviewList();" class="bg-rose-50 text-rose-600 hover:bg-rose-100 px-4 py-2 rounded-lg font-bold text-sm transition-colors hover-target"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
                </span>
            </div>

            <!-- خلاصه -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3 text-center"><p class="text-[11px] text-indigo-700">مدارک در انتظار بررسی</p><p id="hs-docs" class="font-black text-xl text-indigo-700">۰</p></div>
                <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-center"><p class="text-[11px] text-amber-700">بازدیدِ در انتظار بررسی</p><p id="hs-pending" class="font-black text-xl text-amber-700">۰</p></div>
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-center"><p class="text-[11px] text-blue-700">منتظر گزارش برای تایید نهایی</p><p id="hs-report" class="font-black text-xl text-blue-700">۰</p></div>
                <div class="bg-rose-50 border border-rose-100 rounded-xl p-3 text-center"><p class="text-[11px] text-rose-700">ناقص (منتظر ارسالِ دوباره‌ی کاربر)</p><p id="hs-rejected" class="font-black text-xl text-rose-700">۰</p></div>
            </div>

            <div class="card p-4 border-indigo-100">
                <h2 class="font-bold text-indigo-700 text-sm mb-3"><i class="fas fa-file-lines ml-1"></i>مدارک در انتظار بررسی <span class="text-[11px] font-normal text-slate-400">(هر مدرک جداگانه تایید یا رد می‌شود)</span></h2>
                <div id="docs-review-body" class="grid md:grid-cols-2 xl:grid-cols-3 gap-2"></div>
            </div>

            <div class="card p-4 border-rose-100">
                <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                    <h2 class="font-bold text-rose-700 text-sm"><i class="fas fa-car ml-1"></i>بازدیدهای سلامت در انتظار <span class="text-[11px] font-normal text-slate-400">(تاییدشده‌ها به صفحه‌ی «بازدیدهای تاییدشده» می‌روند)</span></h2>
                    <select id="health-filter" onchange="renderHealthList()" class="border rounded-lg px-3 py-1.5 text-xs">
                        <option value="TODO" selected>کارهای من (بررسی عکس + گزارش)</option>
                        <option value="PENDING">در انتظار بررسیِ عکس‌ها</option>
                        <option value="PHOTOS_APPROVED">منتظر گزارش برای تایید نهایی</option>
                        <option value="REJECTED">ناقص - منتظر ارسالِ دوباره‌ی کاربر</option>
                    </select>
                </div>
                <div id="health-tab-body" class="grid md:grid-cols-2 gap-3"></div>
            </div>
        </div>

        <!-- ======================= نمایشگرِ بزرگِ عکس‌های بازدید (بررسیِ تک‌تک) ======================= -->
        <div id="hv-modal" class="fixed inset-0 z-[1000] bg-slate-950/95 hidden flex-col text-white" dir="rtl">
            <div class="hv-head">
                <div class="min-w-0">
                    <p class="font-bold text-sm truncate" id="hv-title"></p>
                    <p class="text-[11px] text-slate-400" id="hv-sub"></p>
                </div>
                <div class="flex items-center gap-2 text-[11px]">
                    <span id="hv-counts" class="hidden md:inline"></span>
                    <label class="flex items-center gap-1 bg-white/10 rounded-lg px-2 py-1 cursor-pointer"><input type="checkbox" id="hv-only-pending" checked onchange="hvRenderStrip(); hvGo(0, true)"> فقط در انتظار</label>
                    <button onclick="hvClose()" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="hv-main">
                <!-- تصویر + زوم -->
                <div class="relative flex-1 min-h-0 overflow-hidden bg-black" id="hv-stage" style="touch-action:none;">
                    <img id="hv-img" class="absolute inset-0 m-auto max-w-full max-h-full select-none" style="transform-origin:0 0;will-change:transform;" draggable="false" alt="">
                    <div class="absolute flex gap-1.5" style="bottom:12px;left:12px;">
                        <button onclick="hvZoomBy(1.4)" class="hv-ctrl w-9 h-9" title="بزرگ‌نمایی"><i class="fas fa-plus"></i></button>
                        <button onclick="hvZoomBy(1/1.4)" class="hv-ctrl w-9 h-9" title="کوچک‌نمایی"><i class="fas fa-minus"></i></button>
                        <button onclick="hvResetZoom()" class="hv-ctrl w-9 h-9" title="اندازه‌ی اصلی"><i class="fas fa-compress"></i></button>
                        <a id="hv-open" target="_blank" class="hv-ctrl w-9 h-9 flex items-center justify-center" title="باز کردن فایل"><i class="fas fa-up-right-from-square"></i></a>
                    </div>
                    <button onclick="hvGo(-1)" class="hv-ctrl absolute w-10 h-14" style="right:8px;top:50%;transform:translateY(-50%)" title="قبلی"><i class="fas fa-chevron-right"></i></button>
                    <button onclick="hvGo(1)" class="hv-ctrl absolute w-10 h-14" style="left:8px;top:50%;transform:translateY(-50%)" title="بعدی"><i class="fas fa-chevron-left"></i></button>
                </div>
                <!-- پنلِ تصمیم -->
                <div class="hv-side">
                    <div>
                        <p class="text-[11px] text-slate-400" id="hv-pos"></p>
                        <p class="font-black text-base" id="hv-label"></p>
                        <span id="hv-status" class="inline-block mt-1 text-[11px] font-bold px-2 py-0.5 rounded-full"></span>
                    </div>
                    <label class="text-[11px] text-slate-300">توضیح (خسارتِ دیده‌شده، یا علتِ رد)
                        <textarea id="hv-note" rows="3" class="mt-1 w-full rounded-lg bg-slate-800 border border-white/10 p-2 text-sm text-white" placeholder="مثلاً: خط‌وخش روی درب جلو راست"></textarea></label>
                    <div class="grid grid-cols-2 gap-2">
                        <button onclick="hvDecide('APPROVED')" class="hv-btn hv-ok"><i class="fas fa-check ml-1"></i>تایید</button>
                        <button onclick="hvDecide('REJECTED')" class="hv-btn hv-no"><i class="fas fa-xmark ml-1"></i>رد (دوباره بگیرد)</button>
                    </div>
                    <button onclick="hvDecide('PENDING')" class="text-[11px] text-slate-400 hover:text-white underline self-start">برگرداندن به «در انتظار»</button>
                    <div id="hv-summary" class="text-[11px] leading-relaxed rounded-lg p-2 hidden"></div>
                    <p class="text-[10px] text-slate-500 mt-auto">کلیدهای ← و → برای عکس بعدی/قبلی. با چرخِ موس یا دو انگشت زوم کنید و با کشیدن جابه‌جا شوید. عکسِ تاییدشده همان لحظه با نامِ درست بایگانی می‌شود.</p>
                </div>
            </div>
            <div id="hv-strip" class="flex gap-1.5 overflow-x-auto px-3 py-2 border-t border-white/10 bg-slate-950"></div>
        </div>

        <!-- ======================= مودال جزئیات/بررسی پرونده ======================= -->
        <div id="case-detail-modal" class="fixed inset-0 bg-black/50 z-[999] hidden items-start justify-center pt-24 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-3xl max-h-[85vh] flex flex-col overflow-hidden">
                <div class="p-4 border-b flex justify-between items-center bg-indigo-50">
                    <h3 class="font-bold text-indigo-700"><i class="fas fa-folder-open ml-1"></i> پرونده <span id="case-detail-code"></span></h3>
                    <span class="flex items-center gap-3">
                        <button id="case-delete-btn" type="button" class="hidden text-xs font-bold text-red-500 hover:text-red-700 bg-white border border-red-100 rounded-lg px-2.5 py-1"><i class="fas fa-trash-alt ml-1"></i>حذف این درخواست</button>
                        <button onclick="document.getElementById('case-detail-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                    </span>
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
                        <div><label class="text-[10px] text-slate-500 block mb-1">بیمه‌نامه‌ها</label>
                            <select id="inv-new-mode" class="border rounded-lg px-3 py-2 text-xs">
                                <option value="uninvoiced">فقط بدون صورتحساب</option>
                                <option value="invoiced">فقط دارای صورتحساب</option>
                                <option value="all">همه</option>
                            </select></div>
                        <button onclick="previewInvoice()" class="bg-slate-700 text-white px-4 py-2 rounded-lg text-xs font-bold">پیش‌نمایش</button>
                    </div>
                    <div class="border rounded-xl p-3 bg-slate-50/60 space-y-2">
                        <div class="flex flex-wrap gap-4 text-[11px] font-bold text-slate-600">
                            <label class="flex items-center gap-1.5"><input type="checkbox" id="inv-opt-full"> شماره‌ی کامل بیمه‌نامه</label>
                            <label class="flex items-center gap-1.5" id="inv-opt-subtotal-wrap"><input type="checkbox" id="inv-opt-subtotal"> ردیف جمع حق بیمه‌ی هر شرکت</label>
                            <label class="flex items-center gap-1.5"><input type="checkbox" id="inv-opt-merge"> ادغام ستون نام شرکت‌های تکراری</label>
                        </div>
                        <div><p class="text-[10px] text-slate-500 mb-1.5"><i class="fas fa-table-columns ml-1"></i>ستون‌های جدول صورتحساب (تیک = نمایش؛ با فلش‌ها ترتیب را عوض کنید)</p>
                            <div id="inv-cols-picker"></div></div>
                    </div>
                    <div id="inv-new-preview"></div>
                </div>
            </div>
        </div>

        <!-- ثبت دریافت جدید -->


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

        <!-- ======================= نمایشگرِ عکس (گالری با زوم) - مشترک: صدور، بازدیدهای تاییدشده، مدارک ======================= -->
        <style>
            #gv-modal { position: fixed; inset: 0; z-index: 9999990; background: #020617; display: none; flex-direction: column; color: #fff; direction: rtl; }
            #gv-modal.open { display: flex; }
            #gv-modal .gv-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,.1); }
            #gv-modal .gv-title { font-size: 13px; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            #gv-modal .gv-sub { font-size: 11px; color: #94a3b8; }
            #gv-stage { position: relative; flex: 1; min-height: 0; overflow: hidden; background: #000; touch-action: none; cursor: grab; }
            #gv-img { position: absolute; inset: 0; margin: auto; max-width: 100%; max-height: 100%; transform-origin: 0 0; user-select: none; -webkit-user-drag: none; }
            #gv-modal .gv-btn { background: rgba(255,255,255,.14); color: #fff; border: 0; border-radius: 10px; min-width: 36px; height: 36px; cursor: pointer; font-size: 15px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
            #gv-modal .gv-btn:hover { background: rgba(255,255,255,.26); }
            #gv-modal .gv-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 42px; height: 58px; }
            #gv-modal .gv-tools { position: absolute; bottom: 12px; left: 12px; display: flex; gap: 6px; }
            #gv-strip { display: flex; gap: 6px; overflow-x: auto; padding: 8px 12px; border-top: 1px solid rgba(255,255,255,.1); }
            #gv-strip button { flex: none; border: 2px solid transparent; border-radius: 8px; padding: 0; overflow: hidden; background: #0f172a; cursor: pointer; }
            #gv-strip button.on { border-color: #fff; }
            #gv-strip img { width: 64px; height: 48px; object-fit: cover; display: block; }
        </style>
        <div id="gv-modal" role="dialog" aria-label="نمایش عکس">
            <div class="gv-head">
                <div style="min-width:0"><div class="gv-title" id="gv-title"></div><div class="gv-sub" id="gv-sub"></div></div>
                <button class="gv-btn" onclick="gvClose()" title="بستن (Esc)"><i class="fas fa-times"></i></button>
            </div>
            <div id="gv-stage">
                <img id="gv-img" alt="" draggable="false">
                <button class="gv-btn gv-nav" style="right:8px" onclick="gvGo(-1)" title="قبلی"><i class="fas fa-chevron-right"></i></button>
                <button class="gv-btn gv-nav" style="left:8px" onclick="gvGo(1)" title="بعدی"><i class="fas fa-chevron-left"></i></button>
                <div class="gv-tools">
                    <button class="gv-btn" onclick="gvZoomBy(1.4)" title="بزرگ‌نمایی"><i class="fas fa-plus"></i></button>
                    <button class="gv-btn" onclick="gvZoomBy(1/1.4)" title="کوچک‌نمایی"><i class="fas fa-minus"></i></button>
                    <button class="gv-btn" onclick="gvReset()" title="اندازه‌ی اصلی"><i class="fas fa-compress"></i></button>
                    <button class="gv-btn" onclick="gvRotate()" title="چرخاندن"><i class="fas fa-rotate-left"></i></button>
                    <a class="gv-btn" id="gv-open" target="_blank" title="باز کردن فایل"><i class="fas fa-up-right-from-square"></i></a>
                </div>
            </div>
            <div id="gv-strip"></div>
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
            <?php echo finance_nav('fin-dashboard', $canSeeCompanies); ?>
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-l from-slate-900 via-indigo-900 to-violet-900 p-6 md:p-8 text-white shadow-2xl">
                <div class="absolute -left-16 -top-16 w-64 h-64 rounded-full bg-indigo-500/20 blur-2xl"></div>
                <div class="absolute left-40 -bottom-20 w-56 h-56 rounded-full bg-fuchsia-500/20 blur-2xl"></div>
                <div class="relative flex flex-wrap justify-between items-center gap-4">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-black"><i class="fas fa-chart-pie text-emerald-300 ml-2"></i>داشبورد مالی</h1>
                        <p class="text-xs md:text-sm text-white/70 mt-2 leading-6">اقساط پرسنلی (کسر از حقوق) و شرکتی در یک نما؛ بدهی به پاسارگاد، دریافتی از شرکت‌ها، معوقات و پیش‌بینی جریان نقدی — با فیلترهای کامل</p>
                    </div>
                    <div class="flex gap-2 flex-wrap">
                        <button onclick="switchTab('fin-invoices')" class="bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2 rounded-xl text-xs font-bold"><i class="fas fa-file-invoice ml-1"></i>صورتحساب‌ها</button>
                        <button onclick="switchTab('fin-installments')" class="bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2 rounded-xl text-xs font-bold"><i class="fas fa-list-ol ml-1"></i>اقساط</button>
                        <button onclick="loadFinDashboard()" class="bg-emerald-400 hover:bg-emerald-300 text-emerald-950 px-4 py-2 rounded-xl text-xs font-black"><i class="fas fa-rotate ml-1"></i>بروزرسانی</button>
                    </div>
                </div>
            </div>
            <div id="fin-dash-root" class="space-y-6"></div>
        </div>

        <!-- ======================= اقساط بیمه‌نامه‌ها ======================= -->
        <div id="tab-fin-installments" class="tab-content max-w-[1700px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo finance_nav('fin-installments', $canSeeCompanies); ?>
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2">
                <div>
                    <h2 class="text-lg font-black text-slate-800"><i class="fas fa-list-ol text-indigo-500 ml-2"></i>مرکز اقساط</h2>
                    <p class="text-xs text-slate-400 mt-1">همه‌ی اقساطِ کارکنان (کسر از حقوق) و شرکتی، ردیف‌به‌ردیف. هر قسط اول از بیمه‌گذار دریافت می‌شود و بعد به بیمه‌گر (پاسارگاد یا هر بیمه‌گرِ دیگر) پرداخت می‌شود؛ روی هر ردیف یا چند ردیف با هم، دریافت یا پرداخت (کامل یا جزئی) ثبت کنید.</p>
                </div>
                <button onclick="FinHub.reloadInstallments()" class="bg-indigo-50 text-indigo-600 hover:bg-indigo-100 px-4 py-2 rounded-lg font-bold text-sm self-start"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
            </div>
            <div id="fh-root" class="space-y-3"></div>
        </div>
        <!-- ======================= پیگیری ======================= -->
        <div id="tab-fin-tracking" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <?php echo finance_nav('fin-tracking', $canSeeCompanies); ?>
            <div>
                <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-magnifying-glass-location text-indigo-500 ml-2"></i>پیگیری</h1>
                <p class="text-xs text-slate-400 mt-1">کدِ رهگیریِ قسط (۱۰ رقمی)، شناسه‌ی درخواست (۵ یا ۶ رقمی)، شماره‌ی پرونده‌ی کارکنان یا شماره‌ی بیمه‌نامه را بزنید؛ چند کد را با فاصله، ویرگول یا خطِ جدید جدا کنید.</p>
            </div>
            <div id="trk-root" class="space-y-4"></div>
        </div>
        <!-- ======================= مغایرت‌گیری ======================= -->
        <div id="tab-fin-reconcile" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <?php echo finance_nav('fin-reconcile', $canSeeCompanies); ?>
            <div>
                <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-scale-balanced text-amber-500 ml-2"></i>مغایرت‌گیری با اکسل پاسارگاد</h1>
                <p class="text-xs text-slate-400 mt-1">پیش از صدور صورتحساب، فهرست پاسارگاد را با اطلاعات پنل تطبیق دهید.</p>
            </div>
            <div class="card p-5">
                <div class="flex flex-wrap gap-3 items-end">
                    <div><label class="text-[10px] text-slate-500 block mb-1">دوره</label><select id="recon-period" class="border rounded-lg px-3 py-2 text-xs"></select></div>
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
            <?php echo finance_nav('fin-invoices', $canSeeCompanies); ?>
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
                <div><label class="text-[10px] text-slate-500 block mb-1">وضعیت</label><select id="inv-f-status" onchange="loadInvoices()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="ISSUED">فعال</option><option value="INACTIVE">غیرفعال</option><option value="">همه</option></select></div>
                <div class="flex-1 min-w-[160px]"><label class="text-[10px] text-slate-500 block mb-1">جستجو (شماره/شرکت)</label><input id="inv-f-q" oninput="clearTimeout(window._invQ); window._invQ = setTimeout(loadInvoices, 400)" class="border rounded-lg px-3 py-2 text-xs w-full"></div>
            </div>
            <div class="card p-0 overflow-x-auto"><div id="fin-invoices-body"></div></div>
        </div>

        <!-- ======================= دریافت‌ها و چک‌ها ======================= -->
        <div id="tab-fin-payments" class="tab-content max-w-[1700px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo finance_nav('fin-payments', $canSeeCompanies); ?>
            <div>
                <h2 class="text-lg font-black text-slate-800"><i class="fas fa-arrow-right-arrow-left text-rose-500 ml-2"></i>دریافت‌ها، پرداخت‌ها و چک‌ها</h2>
                <p class="text-xs text-slate-400 mt-1">دو دفتر: دریافت از بیمه‌گذار/شرکت، و پرداخت به بیمه‌گر. ثبت‌های قبلیِ سایت هم این‌جا دیده می‌شوند. وضعیتِ هر چک را همین‌جا عوض کنید.</p>
            </div>
            <div id="fl-root" class="space-y-3"></div>
        </div>


        <!-- ======================= تب تنظیمات و ریست سیستم ======================= -->
        <?php if($_SESSION['role'] === 'ADMIN'): ?>
        <div id="tab-settings" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-cogs text-purple-500 ml-2"></i>تنظیمات و مدیریت سیستم</h1>
            <div class="card p-6 border-indigo-100 bg-gradient-to-br from-white to-indigo-50/30">
                <h3 class="font-bold text-slate-700 mb-3 border-b border-indigo-100 pb-3"><i class="fas fa-calendar-check text-indigo-500 ml-2"></i>تنظیماتِ کارکرد و مرخصی <small class="text-[11px] text-slate-400 font-bold mr-1">مرخصیِ ماهانه، ساعتِ کار، تعطیلات</small></h3>
                <div id="wk-settings-root"><p class="text-xs text-slate-400">در حالِ بارگذاری…</p></div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

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

                <div class="card p-0 overflow-hidden md:col-span-2 border-indigo-100">
                    <div class="bg-gradient-to-l from-indigo-600 via-violet-600 to-fuchsia-600 p-6 text-white relative overflow-hidden">
                        <div class="absolute -left-10 -top-10 w-40 h-40 rounded-full bg-white/10"></div>
                        <div class="absolute left-24 -bottom-16 w-32 h-32 rounded-full bg-white/10"></div>
                        <h3 class="font-black text-lg relative"><i class="fas fa-database ml-2"></i>پشتیبان‌گیری، خروجی (Export) و ورود اطلاعات (Import)</h3>
                        <p class="text-xs text-white/80 leading-6 mt-2 relative max-w-3xl">بکاپ کامل شامل <b>همه‌ی جدول‌های دیتابیس</b> و <b>همه‌ی فایل‌ها</b> (بایگانی مدارک و بیمه‌نامه‌ها، صورتحساب‌های Word/PDF، فیش‌ها، اکسل‌ها، گزارش‌های بازدید، قالب‌ها و قلم‌ها) است و در پوشه‌ی <b dir="ltr">backup/تاریخ-امروز</b> ذخیره می‌شود. با «بازیابی» همه‌چیز ریزبه‌ریز به همان لحظه برمی‌گردد. هر بار «حذف اطلاعات» زده شود، اول خودکار یک بکاپ کامل گرفته می‌شود. فایل <span dir="ltr">database.sql</span> داخل ZIP را در phpMyAdmin هم می‌شود ایمپورت کرد.</p>
                        <div class="flex flex-wrap gap-2 mt-4 relative">
                            <button onclick="BackupUI.create('full')" class="bg-white text-indigo-700 hover:bg-indigo-50 font-black px-4 py-2.5 rounded-xl text-xs shadow-lg"><i class="fas fa-file-zipper ml-1"></i> بکاپ کامل (دیتابیس + فایل‌ها)</button>
                            <button onclick="BackupUI.create('db')" class="bg-white/15 hover:bg-white/25 border border-white/30 font-bold px-4 py-2.5 rounded-xl text-xs"><i class="fas fa-database ml-1"></i> بکاپ فقط دیتابیس</button>
                            <label class="bg-amber-400 hover:bg-amber-300 text-amber-950 font-black px-4 py-2.5 rounded-xl text-xs cursor-pointer shadow-lg"><i class="fas fa-file-import ml-1"></i> ورود اطلاعات از فایل ZIP (Import)
                                <input type="file" accept=".zip" class="hidden" onchange="BackupUI.uploadRestore(this)"></label>
                            <button onclick="BackupUI.load()" class="bg-white/15 hover:bg-white/25 border border-white/30 font-bold px-3 py-2.5 rounded-xl text-xs" title="بازخوانی"><i class="fas fa-rotate ml-1"></i>بازخوانی</button>
                        </div>
                    </div>
                    <div id="bk-info" class="flex flex-wrap gap-4 px-6 py-3 bg-indigo-50/60 text-[11px] text-slate-600 border-b border-indigo-100"></div>
                    <div id="bk-list" class="overflow-x-auto max-h-[420px] overflow-y-auto"></div>
                </div>

                <div class="card p-6 border-red-100 bg-gradient-to-br from-white to-red-50/30">
                    <h3 class="font-bold text-red-600 mb-2 border-b border-red-100 pb-3"><i class="fas fa-exclamation-triangle ml-2"></i>پاکسازی و بازنشانی داده‌ها</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">همه‌ی اطلاعات دیتابیس (پرونده‌ها، شرکت‌ها، مالی، اقساط، صورتحساب‌ها، گزارش‌ها، چت‌ها و لاگ‌ها) و همه‌ی فایل‌های بایگانی پاک می‌شوند؛ <b>پیش از حذف، خودکار یک بکاپ کامل در پوشه‌ی backup ساخته می‌شود</b> و اگر بکاپ ساخته نشود چیزی پاک نمی‌شود. (حساب مدیران و تنظیمات پاک نخواهد شد).</p>
                    <button onclick="openResetModal()" class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-red-500/20 hover-target transition-all text-xs">
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
            <?php echo finance_nav('fin-settings', $canSeeCompanies); ?>
            <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-money-check-dollar text-emerald-500 ml-2"></i>تنظیمات مالی</h1>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card p-6">
                    <h3 class="font-bold text-slate-700 mb-4 border-b pb-3"><i class="fas fa-calendar-days text-indigo-500 ml-2"></i>دوره مالی و اقساط</h3>
                    <div class="space-y-3">
                        <div class="float-input">
                            <input type="number" id="fs-cutoff" placeholder=" " dir="ltr" min="1" max="31">
                            <label>روز شروع دوره مالی (پیش‌فرض ۲۵)</label>
                        </div>
                        <p class="text-[10px] text-slate-400 -mt-1">هم‌شکل با برنامه‌ی ماموت: از روزِ بعد از این روز در ماه قبل تا همین روز در این ماه، دوره‌ی همین ماه است (مثلاً ۲۶ مهر تا ۲۵ آبان = دوره‌ی آبان).</p>
                        <div class="float-input">
                            <input type="number" id="fs-inst-count" placeholder=" " dir="ltr" min="1" max="36">
                            <label>تعداد اقساط پیش‌فرض</label>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600 block mb-2">روش تقسیم اقساط</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="border rounded-xl p-3 cursor-pointer hover:bg-emerald-50 flex gap-2 items-start">
                                    <input type="radio" name="fs-method" value="easy" class="mt-1">
                                    <span><b class="text-xs">عادی</b><br><span class="text-[10px] text-slate-500">تقسیم مساوی؛ باقیمانده ریال‌به‌ریال روی اقساط اول</span></span>
                                </label>
                                <label class="border rounded-xl p-3 cursor-pointer hover:bg-emerald-50 flex gap-2 items-start">
                                    <input type="radio" name="fs-method" value="mamut" class="mt-1">
                                    <span><b class="text-xs">ماموت</b><br><span class="text-[10px] text-slate-500">قسط اول = رُند + خرده‌ها؛ بقیه رُند به هزار؛ اختلاف نهایی روی قسط آخر</span></span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600 block mb-2">سررسید اقساط</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="border rounded-xl p-3 cursor-pointer hover:bg-emerald-50 flex gap-2 items-start">
                                    <input type="radio" name="fs-due" value="issue" class="mt-1">
                                    <span><b class="text-xs">از تاریخ صدور (ماموت)</b><br><span class="text-[10px] text-slate-500">قسط n = همان روزِ صدور، n ماه بعد</span></span>
                                </label>
                                <label class="border rounded-xl p-3 cursor-pointer hover:bg-emerald-50 flex gap-2 items-start">
                                    <input type="radio" name="fs-due" value="period15" class="mt-1">
                                    <span><b class="text-xs">پانزدهم ماه</b><br><span class="text-[10px] text-slate-500">قسط n = ۱۵امِ n امین ماه بعد از دوره</span></span>
                                </label>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-2">اقساط هر بیمه‌نامه‌ی صادرشده خودکار ساخته می‌شود؛ اگر حق بیمه بعداً اصلاح شود و هنوز پرداختی ثبت نشده باشد، اقساط هوشمند دوباره ساخته می‌شوند. اقساط شرکتی طبق تعداد اقساط و فاصله‌ی اولین سررسیدِ هر شرکت ساخته می‌شوند.</p>
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

                <div class="card p-6 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-4 border-b pb-3"><i class="fas fa-table-columns text-violet-500 ml-2"></i>صدور صورتحساب (پیش‌فرض‌ها)</h3>
                    <div class="grid md:grid-cols-2 gap-3 mb-4">
                        <label class="border rounded-xl p-3 flex items-center justify-between gap-3 cursor-pointer"><span><b class="text-xs">چند صورتحساب برای یک بیمه‌نامه</b><br><span class="text-[10px] text-slate-400">اجازه‌ی صدور برای بیمه‌نامه‌هایی که قبلاً صورتحساب خورده‌اند (با تایید)</span></span><input type="checkbox" id="fs-allow-multi" class="w-4 h-4"></label>
                        <label class="border rounded-xl p-3 flex items-center justify-between gap-3 cursor-pointer"><span><b class="text-xs">شماره‌ی کامل بیمه‌نامه</b><br><span class="text-[10px] text-slate-400">خاموش = فقط دو بخش آخر (مثلاً ۱۴۰۵/۸۷۶)</span></span><input type="checkbox" id="fs-inv-full" class="w-4 h-4"></label>
                        <label class="border rounded-xl p-3 flex items-center justify-between gap-3 cursor-pointer"><span><b class="text-xs">ردیف جمع حق بیمه‌ی هر شرکت</b><br><span class="text-[10px] text-slate-400">در صورتحساب تفکیکی و تلفیقی</span></span><input type="checkbox" id="fs-inv-subtotal" class="w-4 h-4"></label>
                        <label class="border rounded-xl p-3 flex items-center justify-between gap-3 cursor-pointer"><span><b class="text-xs">ادغام ستون نام شرکت</b><br><span class="text-[10px] text-slate-400">نام شرکت‌های تکراریِ پشت‌سرهم در یک خانه</span></span><input type="checkbox" id="fs-inv-merge" class="w-4 h-4"></label>
                    </div>
                    <p class="text-[11px] font-bold text-slate-600 mb-2">ستون‌های پیش‌فرض جدول هر نوع صورتحساب</p>
                    <div class="space-y-3">
                        <div><p class="text-[10px] text-slate-500 mb-1">تجمیعی (یک سطر برای هر شرکت)</p><div id="fs-cols-SUMMARY"></div></div>
                        <div><p class="text-[10px] text-slate-500 mb-1">تلفیقی (ریز پرسنل)</p><div id="fs-cols-PERSONNEL"></div></div>
                        <div><p class="text-[10px] text-slate-500 mb-1">تفکیکی (یک شرکت)</p><div id="fs-cols-DETAILED"></div></div>
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

                <div class="card p-6 md:col-span-2" id="inv-tpl-card">
                    <div class="flex items-center justify-between flex-wrap gap-2 mb-4 border-b pb-3">
                        <h3 class="font-bold text-slate-700"><i class="fas fa-file-word text-indigo-500 ml-2"></i>قالب‌های صورتحساب (Word)</h3>
                        <div class="flex gap-1.5 flex-wrap" id="itpl-tabs"></div>
                    </div>
                    <div id="itpl-body"><p class="text-xs text-slate-400">در حال بارگذاری…</p></div>
                </div>
            </div>

            <button onclick="saveFinanceSettings()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl shadow-lg text-sm">
                <i class="fas fa-save ml-2"></i> ذخیره تنظیمات مالی
            </button>
        </div>

        <!-- ======================= کاربران داخلیِ پنل (ADMIN/OPERATOR/FINANCE/COMPANY_LIAISON) ======================= -->
        <div id="tab-staff-users" class="tab-content max-w-7xl mx-auto w-full space-y-4 flex-1 hidden">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-user-shield text-blue-500 ml-2"></i>کاربران</h1>
                    <p class="text-xs text-slate-400 mt-1">کاربرانِ داخلیِ پنل (مدیر کل، کارشناس صدور، کارشناس مالی، کارمند بیمه با ما) و کاربرانِ شرکت‌ها. ویرایش و حذف با رمزِ خودتان تایید می‌شود.</p>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <button onclick="switchTab('login-logs')" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-right-to-bracket ml-1"></i>لاگ ورود و خروج</button>
                    <button onclick="loadStaffUsers()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt"></i> بروزرسانی</button>
                    <button onclick="openAddUserModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-plus ml-1"></i>افزودن کاربر</button>
                </div>
            </div>
            <p id="su-schema-note" class="hidden text-[11px] text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">برای ویرایش، حذف، لاگ ورود و ورود با ربات، مایگریشن migrations/016_users_bot_login.sql را اجرا کنید.</p>

            <!-- جستجو و فیلتر -->
            <div class="card p-3 flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-[220px]">
                    <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                    <input type="search" id="su-q" oninput="renderStaffUsers()" placeholder="جستجو: نام، نام کاربری، موبایل، شرکت، نقش..." class="w-full border rounded-xl pr-8 pl-3 py-2 text-xs">
                </div>
                <div class="su-seg" id="su-type-seg">
                    <button type="button" data-v="" class="active" onclick="setUserTypeFilter('')">همه <span id="su-n-all"></span></button>
                    <button type="button" data-v="STAFF" onclick="setUserTypeFilter('STAFF')"><i class="fas fa-user-tie ml-1"></i>داخلی <span id="su-n-staff"></span></button>
                    <button type="button" data-v="COMPANY" onclick="setUserTypeFilter('COMPANY')"><i class="fas fa-building ml-1"></i>شرکتی <span id="su-n-company"></span></button>
                </div>
                <select id="su-role-filter" onchange="renderStaffUsers()" class="border rounded-xl px-2 py-2 text-xs font-bold">
                    <option value="">همه‌ی نقش‌ها</option><option value="ADMIN">مدیر کل</option><option value="OPERATOR">کارشناس صدور</option>
                    <option value="FINANCE">کارشناس مالی</option><option value="LIAISON_ALL">کارمند بیمه با ما (همه)</option><option value="COMPANY_LIAISON">— پنل عادی</option><option value="PARSIAN">— پنل پارسیان</option><option value="COMPANY">کاربر شرکت</option>
                </select>
            </div>

            <div class="card overflow-x-auto">
                <div class="flex items-center justify-between px-3 pt-3 text-[11px] text-slate-500 flex-wrap gap-2">
                    <span><span class="bot-dot on"></span> وصل به ربات بله &nbsp;&nbsp; <span class="bot-dot"></span> هنوز وارد ربات نشده</span>
                    <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="su-show-deleted" onchange="loadStaffUsers()"> نمایش حذف‌شده‌ها</label>
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3 text-right">نام</th><th class="p-3 text-right">نام کاربری</th><th class="p-3 text-right">نقش</th>
                        <th class="p-3 text-right">شرکت / کد پرسنلی</th><th class="p-3 text-right">موبایل</th><th class="p-3 text-right">آخرین بازدید</th><th class="p-3 text-right"></th>
                    </tr></thead>
                    <tbody id="su-body"><tr><td colspan="7" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                </table>
            </div>

            <!-- درخواست‌های بازیابی رمز (از ربات بله‌ی شرکت‌ها) - تاییدشده/ردشده‌ها ۳۰ روز بعد خودکار پاک می‌شوند -->
            <div id="su-resets-card" class="card p-4 border-amber-100 hidden">
                <h2 class="font-bold text-amber-700 text-sm mb-1"><i class="fas fa-key ml-1"></i>درخواست‌های بازیابی رمز <span id="su-resets-count" class="text-[11px] bg-amber-100 rounded-full px-2 py-0.5"></span></h2>
                <p class="text-[10px] text-slate-400 mb-2">درخواست‌های تاییدشده یا ردشده، ۳۰ روز بعد از بررسی خودکار پاک می‌شوند.</p>
                <div id="su-resets" class="space-y-2"></div>
            </div>
        </div>

        <!-- ======================= لاگ ورود ======================= -->
        <div id="tab-login-logs" class="tab-content max-w-7xl mx-auto w-full space-y-4 flex-1 hidden">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-right-to-bracket text-indigo-500 ml-2"></i>لاگ ورود و خروج</h1>
                    <p class="text-xs text-slate-400 mt-1">هر ورود و خروجِ پنل ما و پنل شرکت‌ها: تاریخ و ساعت، روش (رمز / کد بله / خروج)، مرورگر و دستگاه.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="switchTab('staff-users')" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-user-shield ml-1"></i>کاربران</button>
                    <button onclick="loadLoginLogs()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt ml-1"></i>بروزرسانی</button>
                </div>
            </div>
            <div class="card p-3 grid grid-cols-2 md:grid-cols-5 gap-2 items-end">
                <label class="text-[11px] font-bold text-slate-500 col-span-2">جستجو (نام، نقش، تاریخ، مرورگر، دستگاه، IP)<input id="ll-q" type="search" oninput="renderLoginLogs()" placeholder="مثلاً نام کاربر یا ۱۴۰۵.۰۷" class="mt-1 w-full border rounded-lg px-3 py-2 text-xs"></label>
                <label class="text-[11px] font-bold text-slate-500">نوع کاربر
                    <select id="ll-type" onchange="renderLoginLogs()" class="mt-1 w-full border rounded-lg px-2 py-2 text-xs"><option value="">همه</option><option value="STAFF">کاربران پنل</option><option value="COMPANY">کاربران شرکت‌ها</option></select></label>
                <label class="text-[11px] font-bold text-slate-500">روش
                    <select id="ll-method" onchange="renderLoginLogs()" class="mt-1 w-full border rounded-lg px-2 py-2 text-xs"><option value="">همه</option><option value="IN">فقط ورودها</option><option value="PASSWORD">ورود با رمز عبور</option><option value="OTP">ورود با کد بله</option><option value="LOGOUT">فقط خروج‌ها</option></select></label>
                <label class="text-[11px] font-bold text-slate-500">نتیجه
                    <select id="ll-ok" onchange="renderLoginLogs()" class="mt-1 w-full border rounded-lg px-2 py-2 text-xs"><option value="">همه</option><option value="1">موفق</option><option value="0">ناموفق</option></select></label>
            </div>
            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3 text-right">تاریخ و ساعت</th><th class="p-3 text-right">کاربر</th><th class="p-3 text-right">نقش</th><th class="p-3 text-right">روش</th>
                        <th class="p-3 text-right">نتیجه</th><th class="p-3 text-right">مرورگر</th><th class="p-3 text-right">سیستم‌عامل</th><th class="p-3 text-right">دستگاه</th><th class="p-3 text-right">IP</th>
                    </tr></thead>
                    <tbody id="ll-body"><tr><td colspan="9" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canSeeCompanies): ?>
        <!-- ======================= تب درخواست‌های شرکتی ======================= -->
        <!-- ============ لیست صدور (جامع: ردیف‌های شرکتی + پرونده‌های کارکنان) ============ -->
        <div id="tab-issue-queue" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('issue-queue', $isLiaison); ?>
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                <div>
                    <h2 class="text-lg font-black text-slate-800"><i class="fas fa-list-check text-teal-500 ml-2"></i>در حال صدور</h2>
                    <p class="text-xs text-slate-400 mt-1">همه‌ی ردیف‌هایی که هنوز صادر نشده‌اند - شرکتی و کارکنان. روی هر ردیف بزنید تا پنجره‌ی همان نوع (کارکنان یا شرکتی) با همه‌ی اطلاعات برای صدور باز شود.</p>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <button onclick="exportIssueQueueExcel()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-file-excel ml-1"></i> خروجی اکسل</button>
                    <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
                    <button onclick="openIntroPicker()" class="bg-gradient-to-l from-teal-600 to-emerald-600 text-white px-4 py-2 rounded-xl font-bold text-sm shadow-lg shadow-teal-500/30 hover:brightness-110 transition-all"><i class="fas fa-plus ml-1"></i> درخواست جدید کارکنان</button>
                    <?php endif; ?>
                    <button onclick="loadIssueQueue()" class="bg-teal-50 text-teal-600 hover:bg-teal-100 px-4 py-2 rounded-lg font-bold text-sm transition-colors"><i class="fas fa-sync-alt ml-1"></i> بروزرسانی</button>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-center"><p class="text-[11px] text-slate-500">کل ردیف‌ها</p><p id="iq-c-total" class="font-black text-xl text-slate-700">۰</p></div>
                <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-center"><p class="text-[11px] text-emerald-700">آماده‌ی صدور</p><p id="iq-c-ready" class="font-black text-xl text-emerald-700">۰</p></div>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-center"><p class="text-[11px] text-amber-700">منتظر مدارک</p><p id="iq-c-waiting" class="font-black text-xl text-amber-700">۰</p></div>
            </div>

            <div class="card p-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-2">
                <input type="search" id="iq-q" oninput="debouncedIssueQueue()" placeholder="جستجو..." class="border rounded-lg px-3 py-2 text-xs col-span-2">
                <select id="iq-source" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs font-bold">
                    <?php if ($isLiaison): ?>
                    <!-- همکار شرکت‌ها به بیمه‌ی کارکنان دسترسی ندارد -->
                    <option value="COMPANY">فقط شرکتی</option>
                    <?php else: ?>
                    <option value="ALL">شرکتی و کارکنان</option>
                    <option value="COMPANY">فقط شرکتی</option>
                    <option value="PERSONNEL">فقط کارکنان</option>
                    <?php endif; ?>
                </select>
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
                    <option value="case_docs">مدارک پرونده (کارکنان)</option>
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
                    <optgroup label="ردیف‌های شرکتی">
                        <option value="PENDING">در انتظار مدارک</option><option value="READY_FOR_ISSUE">آماده‌ی صدور</option>
                        <option value="WITH_BOSS">ارسال‌شده برای رئیس</option><option value="IN_ISSUANCE">در حال صدور</option>
                    </optgroup>
                    <optgroup label="پرونده‌های کارکنان">
                        <option value="REGISTERED">ثبت شده</option><option value="AWAITING_DOCS">در انتظار بارگذاری مدارک</option>
                        <option value="DOCS_PENDING">در انتظار مدارک</option><option value="DOCS_REVIEW">در انتظار تایید مدارک</option>
                        <option value="ISSUING">در حال صدور</option>
                    </optgroup>
                </select>
                <select id="iq-month" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs" title="ماهِ ثبتِ درخواست"><option value="">همه‌ی ماه‌ها (ثبت درخواست)</option></select>
                <input type="text" id="iq-exp-from" oninput="debouncedIssueQueue()" placeholder="انقضا از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="iq-exp-to" oninput="debouncedIssueQueue()" placeholder="انقضا تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <select id="iq-insurer" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی بیمه‌گرها</option><option value="PASARGAD">پاسارگاد</option><option value="IRAN">ایران</option></select>
                <div class="col-span-2 md:col-span-4 lg:col-span-8 flex flex-wrap items-center gap-2 iss-chips" id="iq-expq">
                    <span class="text-[11px] font-black text-slate-500"><i class="fas fa-hourglass-half ml-1 text-orange-500"></i>انقضا:</span>
                    <button type="button" data-v="" class="on" onclick="setIqExpQuick(this)">همه</button>
                    <button type="button" data-v="expired" onclick="setIqExpQuick(this)">منقضی‌شده</button>
                    <button type="button" data-v="7" onclick="setIqExpQuick(this)">تا ۷ روز</button>
                    <button type="button" data-v="15" onclick="setIqExpQuick(this)">تا ۱۵ روز</button>
                    <button type="button" data-v="30" onclick="setIqExpQuick(this)">تا ۳۰ روز</button>
                    <button type="button" data-v="60" onclick="setIqExpQuick(this)">تا ۶۰ روز</button>
                    <button type="button" onclick="resetIssueQueueFilters()" class="!bg-white !text-slate-500 border !border-slate-200 mr-auto"><i class="fas fa-eraser ml-1"></i>پاک‌کردنِ فیلترها</button>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto max-h-[62vh]">
                    <table class="w-full text-right text-xs whitespace-nowrap">
                        <thead class="bg-teal-50 text-teal-800 font-bold sticky top-0 z-10"><tr>
                            <th class="p-3">#</th><th class="p-3">منبع</th><th class="p-3">شرکت / کارفرما</th>
                            <th class="p-3">بیمه‌گذار</th><th class="p-3">پرسنل</th><th class="p-3">کد پرسنلی</th>
                            <th class="p-3">پلاک / شماره شاسی</th><th class="p-3">نوع</th>
                            <th class="p-3">دوره مالی</th><th class="p-3">تاریخ درخواست</th><th class="p-3">انقضا</th>
                            <th class="p-3">مدارک</th><th class="p-3">مرحله</th><th class="p-3"></th>
                        </tr></thead>
                        <tbody id="iq-body"><tr><td colspan="14" class="p-8 text-center text-slate-400">در حال بارگذاری...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======================= صادره‌ها (شرکتی + کارکنان) ======================= -->
        <div id="tab-issued-list" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('issued-list', $isLiaison); ?>
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                <div>
                    <h2 class="text-lg font-black text-slate-800"><i class="fas fa-file-circle-check text-emerald-500 ml-2"></i>صادره‌ها</h2>
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
                    <?php if ($isLiaison): ?>
                    <option value="COMPANY">فقط شرکتی</option>
                    <?php else: ?>
                    <option value="ALL">شرکتی و کارکنان</option>
                    <option value="COMPANY">فقط شرکتی</option>
                    <option value="PERSONNEL">فقط کارکنان</option>
                    <?php endif; ?>
                </select>
                <select id="il-type" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs">
                    <option value="">بدنه و ثالث</option><option value="BODY">بدنه</option><option value="THIRDPARTY">ثالث</option>
                </select>
                <select id="il-company" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی شرکت‌ها</option></select>
                <select id="il-month" onchange="jMonthToRange(this.value, 'il-from', 'il-to'); loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs" title="ماهِ صدور"><option value="">همه‌ی ماه‌ها (صدور)</option></select>
                <input type="text" id="il-from" oninput="debouncedIssuedList()" placeholder="صدور از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="il-to" oninput="debouncedIssuedList()" placeholder="صدور تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <select id="il-kind" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی انواع درخواست</option><option value="NEW_POLICY">صدور جدید</option><option value="ENDORSEMENT">الحاقیه</option><option value="CANCELLATION">فسخ</option></select>
                <select id="il-insurer" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی بیمه‌گرها</option><option value="PASARGAD">پاسارگاد</option><option value="IRAN">ایران</option></select>
                <input type="text" id="il-exp-from" oninput="debouncedIssuedList()" placeholder="انقضای قبلی از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="il-exp-to" oninput="debouncedIssuedList()" placeholder="انقضای قبلی تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
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

        <?php if (!$isLiaison): ?>
        <!-- ======================= صدور گروهی (فایلِ خروجیِ بیمه‌گر) ======================= -->
        <div id="tab-issue-group" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('issue-group', $isLiaison); ?>
            <div class="grid lg:grid-cols-3 gap-4">
                <div class="card p-5 lg:col-span-2">
                    <h2 class="text-lg font-black text-slate-800 mb-1"><i class="fas fa-layer-group text-indigo-500 ml-2"></i>صدور گروهی از فایلِ خروجیِ بیمه‌گر</h2>
                    <p class="text-xs text-slate-500 leading-6">درخواستِ شرکت را انتخاب کنید و فایلِ PDFی را که سایتِ بیمه‌گر داده (چند بیمه‌نامه پشتِ سرِ هم) بارگذاری کنید. صفحه‌های هر بیمه‌نامه جدا و شناسایی می‌شود، روی ردیفِ همان درخواست می‌نشیند، مغایرت‌ها نشان داده می‌شود و ردیف‌هایی که به مرحله‌ی صدور رسیده‌اند یک‌جا صادر و بایگانی می‌شوند.</p>
                </div>
                <div class="card p-5 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-xl bg-indigo-50 p-2"><p class="text-[10px] font-bold text-indigo-700">درخواست باز</p><p id="ig-c-req" class="text-xl font-black text-indigo-800">۰</p></div>
                    <div class="rounded-xl bg-slate-50 p-2"><p class="text-[10px] font-bold text-slate-600">ردیفِ صادرنشده</p><p id="ig-c-rows" class="text-xl font-black text-slate-700">۰</p></div>
                    <div class="rounded-xl bg-emerald-50 p-2"><p class="text-[10px] font-bold text-emerald-700">آماده‌ی صدور</p><p id="ig-c-ready" class="text-xl font-black text-emerald-700">۰</p></div>
                </div>
            </div>
            <div class="card p-4 flex flex-wrap gap-2 items-center">
                <input type="search" id="ig-q" oninput="clearTimeout(window._igT); window._igT = setTimeout(loadIssueGroup, 350)" placeholder="جستجو: شرکت، شماره‌ی درخواست..." class="border rounded-lg px-3 py-2 text-xs flex-1 min-w-[200px]">
                <select id="ig-company" onchange="loadIssueGroup()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی شرکت‌ها</option></select>
                <select id="ig-insurer" onchange="loadIssueGroup()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی بیمه‌گرها</option><option value="PASARGAD">پاسارگاد</option><option value="IRAN">ایران</option></select>
                <button onclick="loadIssueGroup()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt"></i></button>
            </div>
            <div id="ig-body" class="grid md:grid-cols-2 xl:grid-cols-3 gap-3"></div>
        </div>
        <?php endif; ?>

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
                    <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): // ثبت دستی فقط برای مدیر کل ?>
                    <button onclick="CompanyManualRequest.open()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-plus ml-1"></i>ثبت دستی درخواست</button>
                    <button onclick="openAdminNewRequestModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold" title="فقط ساخت درخواست خالی (ردیف‌ها و مدارک را بعداً اضافه می‌کنید)"><i class="fas fa-file ml-1"></i>درخواست خالی</button>
                    <?php endif; ?>
                </div>
            </div>
            <!-- فیلتر: هر شرکت، هر ماه، هر نوع؛ با خلاصه‌ی مرحله‌ی همه‌ی ردیف‌ها -->
            <div class="card p-4 space-y-3">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                    <div class="relative lg:col-span-2"><i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                        <input type="search" id="creq-q" oninput="debouncedCompanyRequests()" placeholder="شماره درخواست، شرکت، پلاک، شاسی، بیمه‌نامه..." class="w-full border rounded-xl pr-8 pl-3 py-2 text-xs"></div>
                    <select id="creq-company" onchange="loadCompanyRequests()" class="border rounded-xl px-3 py-2 text-xs font-bold"><option value="">همه‌ی شرکت‌ها</option></select>
                    <select id="creq-month" onchange="loadCompanyRequests()" class="border rounded-xl px-3 py-2 text-xs font-bold"><option value="">همه‌ی ماه‌ها</option></select>
                    <select id="creq-kind" onchange="loadCompanyRequests()" class="border rounded-xl px-3 py-2 text-xs font-bold">
                        <option value="">همه‌ی انواع درخواست</option><option value="NEW_POLICY">صدور بیمه‌نامه‌ی جدید</option><option value="ENDORSEMENT">الحاقیه</option><option value="CANCELLATION">فسخ</option></select>
                    <select id="creq-insurer" onchange="loadCompanyRequests()" class="border rounded-xl px-3 py-2 text-xs font-bold">
                        <option value="">پاسارگاد و ایران</option><option value="PASARGAD">پاسارگاد</option><option value="IRAN">ایران</option></select>
                </div>
                <div id="creq-summary" class="flex flex-wrap items-center gap-2 text-[11px]"></div>
            </div>
            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500"><tr>
                        <th class="p-3 text-right">شماره</th><th class="p-3 text-right">شرکت</th>
                        <th class="p-3 text-right">بیمه‌گر</th><th class="p-3 text-right">ردیف‌ها (درخواستی / صادره)</th>
                        <th class="p-3 text-right">مرحله‌ی ردیف‌ها</th>
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
            <?php echo finance_nav('companies-finance', $canSeeCompanies); ?>
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
        <div id="tab-companies-manage" class="tab-content max-w-7xl mx-auto w-full space-y-5 flex-1 hidden">
            <div class="cmx-hero">
                <div class="flex items-start justify-between gap-4 flex-wrap relative">
                    <div class="flex items-center gap-3">
                        <span class="cmx-hero-ic"><i class="fas fa-building"></i></span>
                        <div><h1 class="text-2xl font-black">شرکت‌های طرف قرارداد</h1>
                            <p class="text-[12px] opacity-85 mt-0.5">تعریفِ شرکت‌ها، شرکتِ مادر، نحوه‌ی تسویه، قسط‌بندی و بیمه‌گرِ مجاز؛ یکی‌یکی یا یک‌جا از اکسل</p></div>
                    </div>
                    <div class="flex gap-2 flex-wrap">
                        <button onclick="resetCompanyForm(); openModal('add-company-modal')" class="cmx-btn cmx-btn-w"><i class="fas fa-plus"></i>افزودن شرکت</button>
                        <button onclick="openCompanyImport()" class="cmx-btn cmx-btn-g"><i class="fas fa-file-excel"></i>ورود از اکسل</button>
                        <a href="api/company_actions.php?action=company_import_sample" class="cmx-btn cmx-btn-t"><i class="fas fa-download"></i>اکسلِ نمونه</a>
                    </div>
                </div>
                <div id="cm-kpis" class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 relative"></div>
            </div>
            <div class="card p-3 flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-[220px]"><i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                    <input type="search" id="cm-q" oninput="renderCompanyCards()" placeholder="جستجو: نام شرکت، کد اقتصادی، تلفن، شرکت مادر..." class="w-full border-2 border-slate-200 focus:border-indigo-400 rounded-xl pr-8 pl-3 py-2 text-xs"></div>
                <select id="cm-f-pay" onchange="renderCompanyCards()" class="border-2 border-slate-200 rounded-xl px-3 py-2 text-xs font-bold"><option value="">همه‌ی روش‌های تسویه</option><option value="INSTALLMENT">قسطی</option><option value="CASH_NET30">نقدی ۳۰ روزه</option><option value="CASH_IMMEDIATE">نقدی فوری</option><option value="NONE">مشخص نشده</option></select>
                <select id="cm-f-ins" onchange="renderCompanyCards()" class="border-2 border-slate-200 rounded-xl px-3 py-2 text-xs font-bold"><option value="">همه‌ی بیمه‌گرها</option><option value="BOTH">هردو</option><option value="PASARGAD">فقط پاسارگاد</option><option value="IRAN">فقط ایران</option></select>
                <button onclick="loadCompanyManage()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-sync-alt ml-1"></i>بروزرسانی</button>
                <button onclick="switchTab('staff-users'); setUserTypeFilter('COMPANY')" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-users ml-1"></i>کاربرانِ شرکت‌ها</button>
                <span id="cm-count" class="mr-auto text-[11px] font-bold text-slate-400"></span>
            </div>
            <div id="cm-cards" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4"><p class="text-center text-slate-400 text-xs py-10 col-span-full">در حال بارگذاری...</p></div>
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
    <script>
        // پنجره‌های تمام‌صفحه‌ای که داخلِ <main> نوشته شده‌اند (نمایشگرِ عکس‌های بازدید، گالری، بزرگ‌نمایی، جزئیاتِ پرونده و ...)
        // زیرِ سرتیترِ بالای صفحه می‌افتادند (main با z-10 لایه‌ی جدا می‌سازد) و دکمه‌ی بستنشان کلیک نمی‌خورد؛ به بدنه‌ی صفحه منتقل می‌شوند
        document.querySelectorAll('main [id$="-modal"]').forEach(m => {
            if (m.classList.contains('fixed') || m.classList.contains('modal-overlay') || getComputedStyle(m).position === 'fixed') document.body.appendChild(m);
        });
    </script>

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

    <!-- مودالِ پیش‌نمایشِ قالبِ صورتحساب با داده‌ی نمونه -->
    <div id="itpl-preview-modal" class="modal-overlay">
        <div class="modal-content creq-wide-modal p-6 relative max-h-[92vh] overflow-y-auto" style="max-width: 1100px;">
            <button type="button" onclick="document.getElementById('itpl-preview-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1"><i class="fas fa-eye text-indigo-500 ml-1"></i>پیش‌نمایشِ قالب با داده‌ی نمونه</h3>
            <p class="text-[11px] text-slate-400 mb-3">داده‌ها ساختگی‌اند؛ ستون‌ها و گزینه‌های جدول طبقِ «تنظیماتِ صدورِ صورتحساب» همین صفحه چیده شده‌اند (برای دیدنِ تغییرِ ستون‌ها اول ذخیره کنید).</p>
            <div id="itpl-preview-body"></div>
        </div>
    </div>

    <!-- مودالِ صدورِ گروهی: فایلِ خروجیِ سایتِ بیمه‌گر (چند بیمه‌نامه در یک PDF) -->
    <div id="bundle-modal" class="modal-overlay" style="z-index: 1000000;">
        <div class="modal-content creq-wide-modal p-0 relative max-h-[92vh] overflow-y-auto">
            <div class="bdl-hero">
                <button type="button" onclick="closeBundleModal()" class="absolute top-4 left-4 text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
                <div class="flex items-center gap-3">
                    <div class="bdl-hero-ic"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <h3 class="font-black text-lg">صدورِ گروهی</h3>
                        <p class="text-[11px] text-white/80" id="bdl-sub">فایلِ خروجیِ سایتِ بیمه‌گر را بارگذاری کنید؛ بیمه‌نامه‌ها جدا، شناسایی و روی ردیف‌های همین درخواست نشانده می‌شوند.</p>
                    </div>
                </div>
            </div>
            <div class="p-5">
                <div id="bdl-upload">
                    <label id="bdl-drop" class="bdl-drop">
                        <i class="fas fa-file-pdf text-4xl text-rose-500 mb-2"></i>
                        <span class="font-black text-slate-700">فایلِ PDFِ بیمه‌نامه‌ها را اینجا رها کنید یا کلیک کنید</span>
                        <span class="text-[11px] text-slate-400 mt-1">هر بیمه‌نامه چند صفحه دارد (بیمه‌نامه + صفحه‌های پرداخت و فیش)؛ صفحه‌ی بیمه‌نامه‌ی هر کدام خودکار پیدا می‌شود.</span>
                        <span id="bdl-fname" class="text-xs font-bold text-blue-700 mt-2"></span>
                        <input type="file" id="bdl-file" accept="application/pdf,.pdf" class="hidden" onchange="bundlePicked(this)">
                    </label>
                    <button id="bdl-analyze-btn" onclick="bundleAnalyze()" disabled class="w-full mt-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 text-white font-bold py-2.5 rounded-xl text-sm"><i class="fas fa-magnifying-glass-chart ml-1"></i>بررسی و شناسایی</button>
                </div>
                <div id="bdl-progress" class="hidden text-center py-10">
                    <div class="bdl-spinner mx-auto mb-4"></div>
                    <p class="font-black text-slate-700" id="bdl-progress-text">در حالِ جدا کردنِ صفحه‌ها و شناسایی…</p>
                    <p class="text-[11px] text-slate-400 mt-1">بسته به تعدادِ صفحه‌ها ممکن است تا چند دقیقه طول بکشد؛ صفحه را نبندید.</p>
                </div>
                <div id="bdl-error" class="hidden"></div>
                <div id="bdl-result" class="hidden"></div>
            </div>
        </div>
    </div>

    <!-- مودال ثبت دستیِ یک درخواست شرکتی (توسط خودمان، بدون نیاز به ثبت‌کننده‌ی شرکت) -->
    <div id="admin-new-creq-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-xl p-0 relative overflow-hidden">
            <div class="p-5 text-white relative" style="background:linear-gradient(120deg,#1e3a8a,#4f46e5 60%,#7c3aed)">
                <button type="button" onclick="document.getElementById('admin-new-creq-modal').classList.remove('active')" class="absolute top-4 left-4 text-white/70 hover:text-white hover-target text-xl"><i class="fas fa-times"></i></button>
                <p class="text-[11px] opacity-80" id="ancr-modal-sub">درخواستِ شرکت را مثلِ نامه‌ی رسمی‌اش ثبت کنید</p>
                <h3 class="font-black text-lg"><i class="fas fa-file-signature ml-1"></i><span id="ancr-modal-title">ثبت دستی درخواست شرکتی</span></h3>
                <span id="ancr-code" class="hidden mt-2 inline-block text-[11px] font-black bg-white/20 rounded-full px-3 py-1"></span>
            </div>
            <div class="p-5 space-y-4 max-h-[75vh] overflow-y-auto">
                <input type="hidden" id="ancr-request-id">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="block"><span class="text-[11px] font-bold text-slate-500 block mb-1">شرکت</span>
                        <select id="ancr-company" onchange="ancrUpdateInsurerOptions()" class="w-full border-2 border-slate-200 focus:border-indigo-400 rounded-xl px-3 py-2.5 text-sm font-bold"><option value="">-- انتخاب شرکت --</option></select></label>
                    <label class="block"><span class="text-[11px] font-bold text-slate-500 block mb-1">بیمه‌گر</span>
                        <select id="ancr-insurer" class="w-full border-2 border-slate-200 focus:border-indigo-400 rounded-xl px-3 py-2.5 text-sm font-bold"></select></label>
                </div>
                <label class="block"><span class="text-[11px] font-bold text-slate-500 block mb-1">نوع درخواست</span>
                    <select id="ancr-kind" onchange="onAncrKindChange()" class="w-full border-2 border-slate-200 focus:border-indigo-400 rounded-xl px-3 py-2.5 text-sm font-bold">
                        <option value="NEW_POLICY">صدور بیمه‌نامه‌ی جدید</option>
                        <option value="ENDORSEMENT">صدور الحاقیه</option>
                        <option value="CANCELLATION">فسخ بیمه‌نامه</option>
                    </select></label>
                <p id="ancr-kind-hint" class="text-[11px] text-slate-400 -mt-2"></p>
                <div class="rounded-2xl border-2 border-indigo-100 bg-indigo-50/40 p-3">
                    <p class="text-[12px] font-black text-indigo-800 mb-2"><i class="fas fa-list-ol ml-1"></i>تعدادِ درخواستی (طبقِ نامه)</p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <label class="ancr-cnt"><span>ثالث</span><input type="number" min="0" max="9999" id="ancr-cnt-THIRDPARTY" placeholder="۰"></label>
                        <label class="ancr-cnt"><span>بدنه</span><input type="number" min="0" max="9999" id="ancr-cnt-BODY" placeholder="۰"></label>
                        <label class="ancr-cnt"><span>الحاقیه</span><input type="number" min="0" max="9999" id="ancr-cnt-ENDORSEMENT" placeholder="۰"></label>
                        <label class="ancr-cnt"><span>فسخ</span><input type="number" min="0" max="9999" id="ancr-cnt-CANCELLATION" placeholder="۰"></label>
                    </div>
                    <p class="text-[10.5px] text-slate-500 mt-2" id="ancr-cnt-sum"></p>
                </div>
                <label class="block"><span class="text-[11px] font-bold text-slate-500 block mb-1">توضیح / متن نامه (اختیاری)</span>
                    <textarea id="ancr-text" rows="3" class="w-full border-2 border-slate-200 focus:border-indigo-400 rounded-xl p-3 text-sm"></textarea></label>
                <label class="block"><span class="text-[11px] font-bold text-slate-500 block mb-1">فایل نامه (اختیاری)</span>
                    <input type="file" id="ancr-letter-file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="w-full border-2 border-dashed border-slate-300 rounded-xl p-2.5 text-xs bg-slate-50"></label>
            </div>
            <div class="px-5 pb-5 pt-1 flex gap-2">
                <button type="button" onclick="document.getElementById('admin-new-creq-modal').classList.remove('active')" class="px-5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl text-sm">انصراف</button>
                <button onclick="submitAdminNewRequest()" id="ancr-submit" class="flex-1 text-white font-black py-2.5 rounded-xl text-sm shadow-lg" style="background:linear-gradient(120deg,#4338ca,#6366f1)"><i class="fas fa-check ml-1"></i>ثبت درخواست</button>
            </div>
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
            <div class="ir-pin mb-3" dir="ltr">
                <span class="ip-flag"></span>
                <input type="text" id="aap-p4" maxlength="2" inputmode="numeric" placeholder="۱۲" class="ip-cell">
                <input type="text" id="aap-letter" maxlength="3" placeholder="الف" list="plate-letters-dl" class="ip-cell ip-letter">
                <input type="text" id="aap-p2" maxlength="3" inputmode="numeric" placeholder="۳۴۵" class="ip-cell ip-wide">
                <span class="ip-ir"><small>ایران</small><input type="text" id="aap-p1" maxlength="2" inputmode="numeric" placeholder="۶۷"></span>
            </div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600 mb-2 cursor-pointer">
                <input type="checkbox" id="aap-isnew" onchange="toggleNoPlate('aap')"> پلاک ندارد (لیفتراک یا خودروی صفرکیلومتر)
            </label>
            <div id="aap-chassis-box" class="grid grid-cols-2 gap-3 hidden">
                <div class="float-input"><input type="text" id="aap-chassis" dir="ltr" placeholder=" "><label>شماره شاسی</label></div>
                <div class="float-input"><input type="text" id="aap-engine" dir="ltr" placeholder=" "><label>شماره موتور</label></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input"><input type="text" id="aap-carvalue" class="money-input" dir="ltr" inputmode="numeric" placeholder=" "><label>ارزش خودرو (ریال) - بدنه</label></div>
                <div class="float-input"><input type="text" id="aap-liability" class="money-input" dir="ltr" inputmode="numeric" placeholder=" "><label>سقف تعهد مالی (ریال) - ثالث</label></div>
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
                <div class="float-input"><input type="text" id="aap-expiry" dir="ltr" inputmode="numeric" placeholder=" "><label>تاریخ انقضا (شمسی، مثلاً ۱۴۰۵/۰۷/۳۰)</label></div>
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

            <div class="float-input">
                <select id="cit-doc-type" onchange="onCitDocTypeChange()">
                    <option value="">-- اول نوع فایل را انتخاب کنید --</option>
                    <option value="letter">نامه‌ی درخواست (روی خودِ درخواست می‌نشیند، نه یک پلاک)</option>
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
                    <option value="other">سایر مدارک</option>
                </select>
                <label>نوع فایل</label>
                <input type="text" id="cit-doc-type-other" placeholder="نوع مدرک را بنویسید" class="mt-2 hidden">
            </div>
            <!-- فیلدهای خودرو: فقط وقتی نوعِ فایل یکی از مدارکِ خودرو باشد -->
            <div id="cit-vehicle-fields" class="hidden">
            <!-- فهرستِ خودروهای همین درخواست، برای انتخابِ سریع -->
            <div id="cit-plate-chips" class="flex flex-wrap gap-1 mb-3"></div>

            <div class="float-input">
                <select id="cit-existing-plate" onchange="onCitPlateSelectChange()"><option value="">-- پلاک جدید --</option></select>
                <label>پلاک</label>
            </div>
            <label class="text-xs font-bold text-slate-500 block mb-2">پلاک (اگر «پلاک جدید» را انتخاب کرده‌اید)</label>
            <!-- ترتیبِ نمایشی چپ‌به‌راست: [۲ رقم] [حرف] [۳ رقم] [ایران + ۲ رقمِ کد شهر].
                 شناسه‌ها عمداً عوض نشده‌اند: cit-p1 همان دو رقمِ کنارِ «ایران» است. -->
            <div class="ir-pin mb-4" dir="ltr">
                <span class="ip-flag"></span>
                <input type="text" id="cit-p4" maxlength="2" inputmode="numeric" placeholder="۱۲" class="ip-cell">
                <input type="text" id="cit-letter" maxlength="3" placeholder="الف" list="plate-letters-dl" class="ip-cell ip-letter">
                <input type="text" id="cit-p2" maxlength="3" inputmode="numeric" placeholder="۳۴۵" class="ip-cell ip-wide">
                <span class="ip-ir"><small>ایران</small><input type="text" id="cit-p1" maxlength="2" inputmode="numeric" placeholder="۶۷"></span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input">
                    <select id="cit-insurance-type"><option value="">نامشخص</option><option value="THIRDPARTY">ثالث</option><option value="BODY">بدنه</option></select>
                    <label>نوع بیمه</label>
                </div>
                <div class="float-input"><input type="text" id="cit-expiry" dir="ltr" inputmode="numeric" placeholder=" "><label>تاریخ انقضا (شمسی، مثلاً ۱۴۰۵/۰۷/۳۰)</label></div>
            </div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-500 mb-4">
                <input type="checkbox" id="cit-skip-health"> این پلاک نیاز به بازدید سلامت ندارد (طبق روال این شرکت)
            </label>
            </div>

            <!-- نامه‌ی درخواست: نوعِ نامه و تعدادِ درخواستی از هر نوع (به‌جای پلاک و ...) -->
            <div id="cit-letter-fields" class="hidden mb-4 rounded-xl border border-indigo-100 bg-indigo-50/40 p-3">
                <p class="text-xs font-bold text-indigo-800 mb-1">این نامه چه درخواستی دارد و از هر نوع چند تا؟</p>
                <p class="text-[10px] text-slate-500 mb-3">نوعِ درخواست از روی همین تعدادها تعیین می‌شود. اگر نامه هم صدور جدید و هم الحاقیه دارد، بهتر است الحاقیه‌ها درخواستِ جدا باشند.</p>
                <div class="grid grid-cols-2 gap-2">
                    <label class="text-[11px] font-bold text-slate-600">ثالث
                        <input type="text" inputmode="numeric" id="cit-cnt-THIRDPARTY" class="cit-cnt mt-1 w-full border rounded-lg p-2 text-sm text-center" placeholder="۰"></label>
                    <label class="text-[11px] font-bold text-slate-600">بدنه
                        <input type="text" inputmode="numeric" id="cit-cnt-BODY" class="cit-cnt mt-1 w-full border rounded-lg p-2 text-sm text-center" placeholder="۰"></label>
                    <label class="text-[11px] font-bold text-violet-700">الحاقیه - تغییر اطلاعات بیمه‌نامه
                        <input type="text" inputmode="numeric" id="cit-cnt-ENDORSEMENT" class="cit-cnt mt-1 w-full border rounded-lg p-2 text-sm text-center" placeholder="۰"></label>
                    <label class="text-[11px] font-bold text-rose-700">الحاقیه - فسخ بیمه‌نامه
                        <input type="text" inputmode="numeric" id="cit-cnt-CANCELLATION" class="cit-cnt mt-1 w-full border rounded-lg p-2 text-sm text-center" placeholder="۰"></label>
                </div>
            </div>

            <div class="flex gap-2">
                <button onclick="submitAssignDocument()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm">ثبت اطلاعات (تگ‌گذاری)</button>
                <button onclick="rejectDocument(document.getElementById('cit-doc-id').value)" class="bg-red-50 hover:bg-red-100 text-red-600 font-bold py-2.5 px-4 rounded-xl text-sm whitespace-nowrap">رد کردن</button>
            </div>
        </div>
    </div>

    <!-- مودال ثبت صدور نهایی یک پلاک شرکتی -->
    <div id="cissue-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-4xl p-0 relative max-h-[92vh] overflow-y-auto">
            <div class="p-5 text-white relative" style="background:linear-gradient(120deg,#064e3b,#059669 55%,#0d9488)">
                <button type="button" onclick="document.getElementById('cissue-modal').classList.remove('active')" class="absolute top-4 left-4 text-white/70 hover:text-white hover-target text-xl"><i class="fas fa-times"></i></button>
                <p class="text-[11px] opacity-85">فایلِ بیمه‌نامه را بدهید تا شناسایی و با این ردیف مقایسه شود؛ همه‌ی فیلدها بعد از شناسایی قابلِ ویرایش‌اند.</p>
                <h3 class="font-black text-lg"><i class="fas fa-stamp ml-1"></i>ثبت صدور بیمه‌نامه</h3>
                <p id="cis-plate-label" class="text-xs font-bold mt-1"></p>
            </div>
            <div class="p-5 space-y-3">
                <input type="hidden" id="cis-plate-id">
                <div class="ii-sec flex flex-wrap items-center gap-2">
                    <label class="flex-1 min-w-[240px] flex items-center gap-2 border-2 border-dashed border-emerald-300 rounded-xl px-3 py-2.5 cursor-pointer hover:bg-emerald-50 text-xs font-bold text-emerald-700">
                        <i class="fas fa-cloud-arrow-up"></i><span id="cis-file-name">انتخابِ فایلِ بیمه‌نامه‌ی صادرشده (PDF یا عکس)</span>
                        <input type="file" id="cis-file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" onchange="document.getElementById('cis-file-name').textContent = this.files[0] ? this.files[0].name : 'انتخابِ فایل'; if (this.files[0]) validateCompanyPolicy();">
                    </label>
                    <button onclick="validateCompanyPolicy()" class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2.5 rounded-xl text-xs"><i class="fas fa-magnifying-glass ml-1"></i>شناسایی دوباره</button>
                </div>
                <div id="cis-ocr-note" class="hidden text-[11px] rounded-lg p-2"></div>
                <div id="cis-form"></div>
                <button onclick="submitMarkIssued()" class="w-full text-white font-black py-3 rounded-xl text-sm shadow-lg" style="background:linear-gradient(120deg,#047857,#10b981)"><i class="fas fa-check-circle ml-1"></i>ثبت صدور و بایگانی</button>
            </div>
        </div>
    </div>

    <!-- مودال جزئیاتِ یک ردیفِ لیست صدور -->
    <div id="iq-detail-modal" class="modal-overlay">
        <div class="modal-content creq-wide-modal p-6 relative max-h-[92vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('iq-detail-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <div id="iq-detail-head" class="rounded-2xl p-4 mb-4 text-white" style="background:linear-gradient(120deg,#4338ca,#6366f1)">
                <h3 class="font-black text-lg" id="iq-detail-title">پرونده‌ی صدور</h3>
                <p class="text-[11px] opacity-90" id="iq-detail-sub">اطلاعات، مدارک، و خودِ صدور - همه یک‌جا.</p>
            </div>
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
            <div class="ir-pin mb-3" dir="ltr">
                <span class="ip-flag"></span>
                <input type="text" id="cre-p4" maxlength="2" inputmode="numeric" placeholder="۱۲" class="ip-cell">
                <input type="text" id="cre-letter" maxlength="3" placeholder="الف" list="plate-letters-dl" class="ip-cell ip-letter">
                <input type="text" id="cre-p2" maxlength="3" inputmode="numeric" placeholder="۳۴۵" class="ip-cell ip-wide">
                <span class="ip-ir"><small>ایران</small><input type="text" id="cre-p1" maxlength="2" inputmode="numeric" placeholder="۶۷"></span>
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
                <div class="float-input"><input type="text" id="cre-carvalue" class="money-input" dir="ltr" inputmode="numeric" placeholder=" "><label>ارزش خودرو (ریال) - بدنه</label></div>
                <div class="float-input"><input type="text" id="cre-liability" class="money-input" dir="ltr" inputmode="numeric" placeholder=" "><label>سقف تعهد مالی (ریال) - ثالث</label></div>
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
        <div class="modal-content w-full max-w-4xl p-6 relative max-h-[90vh] overflow-y-auto">
            <button type="button" onclick="document.getElementById('letter-import-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1">ورود ردیف‌ها از نامه</h3>
            <p class="text-[11px] text-slate-400 mb-3">نامه را به هوش مصنوعی بدهید و بگویید با یکی از این قالب‌ها خروجی بدهد، بعد خروجی را اینجا بچسبانید (یا فایلش را انتخاب کنید).</p>
            <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3 mb-3">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-[11px] font-bold text-indigo-800">متنِ آماده برای هوش مصنوعی (همراهِ فایل نامه بفرستید):</p>
                    <button type="button" onclick="copyValue(document.getElementById('cli-ai-prompt').innerText, 'متن')" class="text-[10px] font-bold text-indigo-700 bg-white border border-indigo-200 rounded-lg px-2 py-0.5"><i class="far fa-copy ml-1"></i>کپی</button>
                </div>
                <p id="cli-ai-prompt" class="text-[10.5px] text-slate-600 leading-relaxed">این نامه‌ی درخواست بیمه را بخوان و فقط یک JSON (بدون هیچ توضیح اضافه) با همان ساختارِ نمونه برگردان. در «letter.counts» تعدادِ درخواستی از هر نوع را با کلیدهای «ثالث»، «بدنه»، «تغییر اطلاعات» و «فسخ» بنویس. در «rows» برای هر خودرو یک ردیف بساز با: request («صدور»، «تغییر اطلاعات» یا «فسخ»)؛ پلاک در چهار فیلدِ جدا: plate_two (دو رقمِ سمتِ چپِ پلاک)، plate_letter (حرف)، plate_three (سه رقم)، plate_iran (دو رقمِ کدِ ایران، کنارِ کلمه‌ی «ایران»). مهم: در نامه‌ها پلاک معمولاً به شکلِ «۷۹۸ع۸۱-۱۱» نوشته می‌شود که یعنی plate_three=798، plate_letter=ع، plate_two=81 و عددِ بعد از خط تیره plate_iran=11؛ یا به شکلِ «۸۱ ع ۷۹۸ ایران ۱۱» که همان پلاک است. هیچ‌وقت کدِ ایران را با دو رقمِ اول جابه‌جا نکن. اگر خودرو پلاک ندارد، فیلدهای پلاک را خالی بگذار و chassis_no را بنویس. بقیه‌ی فیلدها: insurance_type («ثالث»، «بدنه» یا «هردو»)، expiry_date (تاریخِ انقضا/اتمامِ بیمه‌نامه‌ی فعلی به شمسی مثل 1405/07/30)، car_name (نوع خودرو)، model_year (مدل/سال ساخت)، chassis_no، engine_no، prev_policy_number (شماره‌ی بیمه‌نامه‌ی قبلی)، prev_insurer (بیمه‌گرِ قبلی)؛ فقط برای ثالث: liability_limit (تعهد مالی به ریال)؛ فقط برای بدنه: car_value (ارزش خودرو به ریال)، has_prev_body («بله»/«خیر»)، health_inspection («لازم»/«لازم نیست»)؛ برای تغییر اطلاعات و فسخ: policy_number، change_request (چه تغییری) یا cancellation_reason (دلیل)؛ و note برای هر توضیحِ دیگر. هر فیلدی که در نامه نیست یا به آن نوع بیمه مربوط نیست را نگذار یا خالی بگذار؛ چیزی حدس نزن.</p>
            </div>
            <input type="hidden" id="cli-request-id">

            <details class="bg-slate-50 border border-slate-200 rounded-xl p-3 mb-3">
                <summary class="text-xs font-bold text-slate-600 cursor-pointer">قالبِ خروجی که باید به هوش مصنوعی بگویید (کلیک کنید)</summary>
                <p class="text-[11px] font-bold text-slate-600 mt-2 mb-1">قالب ۱ - JSON (دقیق‌تر، پیشنهاد می‌شود):</p>
<pre class="text-[10px] bg-white border rounded-lg p-2 overflow-x-auto" dir="ltr">{
  "letter": {
    "counts": { "ثالث": 2, "بدنه": 1, "تغییر اطلاعات": 1, "فسخ": 1 }
  },
  "rows": [
    {
      "request": "صدور",
      "plate_two": "81", "plate_letter": "ع", "plate_three": "798", "plate_iran": "11",
      "insurance_type": "ثالث",
      "expiry_date": "1405/07/06",
      "car_name": "پیلسان", "model_year": "1401", "chassis_no": "1000623",
      "prev_policy_number": "1404/503",
      "liability_limit": "8,000,000,000"
    },
    {
      "request": "صدور",
      "plate_two": "31", "plate_letter": "ع", "plate_three": "693", "plate_iran": "21",
      "insurance_type": "بدنه",
      "expiry_date": "1405/07/30",
      "car_name": "پژو 206", "model_year": "1400",
      "car_value": "5,000,000,000",
      "has_prev_body": "بله",
      "health_inspection": "لازم"
    },
    {
      "request": "صدور",
      "plate_two": "", "plate_letter": "", "plate_three": "", "plate_iran": "",
      "chassis_no": "NAAP13FE9KJ555555",
      "insurance_type": "هردو",
      "car_name": "لیفتراک", "car_value": "9,000,000,000", "liability_limit": "2,000,000,000"
    },
    {
      "request": "تغییر اطلاعات",
      "plate_two": "44", "plate_letter": "ج", "plate_three": "321", "plate_iran": "12",
      "insurance_type": "بدنه",
      "policy_number": "1405/123456",
      "change_request": "افزایش سرمایه به ۶ میلیارد"
    },
    {
      "request": "فسخ",
      "plate_two": "53", "plate_letter": "ب", "plate_three": "128", "plate_iran": "74",
      "insurance_type": "ثالث",
      "policy_number": "1405/654321",
      "cancellation_reason": "فروش خودرو"
    }
  ]
}</pre>
                <p class="text-[11px] font-bold text-slate-600 mt-2 mb-1">قالب ۲ - فایل اکسل (xlsx یا csv):</p>
                <p class="text-[10px] text-slate-500 leading-relaxed mb-1">
                    کافی است فایل اکسل را همین‌جا بدهید. ستون‌ها با <b>نامِ سرستون</b> شناخته می‌شوند،
                    پس ترتیبشان مهم نیست و ستون‌های اضافه نادیده گرفته می‌شوند. این نام‌ها شناخته می‌شوند:
                </p>
<pre class="text-[10px] bg-white border rounded-lg p-2 overflow-x-auto">نوع درخواست | پلاک (یا: دو رقم | حرف | سه رقم | کد ایران) | شماره شاسی | شماره موتور | نوع خودرو | مدل | ارزش خودرو | تعهد مالی | نوع بیمه نامه | تاریخ انقضا | شماره بیمه نامه قبلی | بیمه بدنه قبل | بازدید سلامت | شماره بیمه نامه | خواسته | دلیل فسخ | توضیحات</pre>
                <p class="text-[10px] text-slate-500 mt-1">
                    برای لیفتراک و خودروی صفرکیلومتر که پلاک ندارند، ستونِ «پلاک» را خالی بگذارید و فقط
                    «شماره شاسی» را پر کنید؛ تاریخ انقضا هم لازم نیست.
                </p>
                <p class="text-[11px] font-bold text-slate-600 mt-2 mb-1">قالب ۳ - متنِ خط‌به‌خط (هر خط یک ردیف، با «|»):</p>
<pre class="text-[10px] bg-white border rounded-lg p-2 overflow-x-auto" dir="ltr">31 ع 693 ایران 21 | بدنه | 1405/07/30 | پژو 206
12 ن 152 ایران 77 | هردو | 1406/01/05 | پراید</pre>
                <p class="text-[10px] text-slate-500 mt-2 leading-relaxed">
                    • <b>letter.counts</b>: نوعِ نامه و تعدادِ درخواستی از هر نوع - «ثالث»، «بدنه»، «تغییر اطلاعات» (الحاقیه) و «فسخ». اگر نیاید، سیستم از روی ردیف‌ها خودش می‌شمارد.<br>
                    • <b>request</b> برای هر ردیف: «صدور» (بیمه‌نامه‌ی جدید)، «تغییر اطلاعات» (الحاقیه) یا «فسخ». برای «تغییر اطلاعات» <b>change_request</b> (چه تغییری) و برای «فسخ» <b>cancellation_reason</b> (دلیل) را بنویسد؛ در هر دو <b>policy_number</b> (شماره بیمه‌نامه‌ی فعلی) لازم است.<br>
                    • <b>car_value</b> (ارزش خودرو، برای بدنه) و <b>liability_limit</b> (سقف تعهد مالی، برای ثالث) به ریال - با یا بدون جداکننده.<br>
                    • <b>insurance_type</b> می‌تواند «بدنه»، «ثالث» یا «هردو» باشد؛ «هردو» خودش به دو ردیف جدا تبدیل می‌شود.<br>
                    • تاریخ‌ها شمسی‌اند (۱۴۰۵/۰۷/۳۰ یا ۱۴۰۵.۰۷.۳۰).<br>
                    • <b>has_prev_body</b>: «بله» یا «خیر» — برای بدنه، اگر بیمه بدنه قبلی دارد، بارگذاریش اجباری می‌شود.<br>
                    • <b>health_inspection</b>: «لازم» یا «لازم نیست» — برای خودروهایی که بازدید سلامت نمی‌خواهند «لازم نیست» بنویسد.<br>
                    • <b>پلاک</b> را با چهار فیلدِ جدا بنویسد: <b>plate_two</b> (دو رقم)، <b>plate_letter</b> (حرف)، <b>plate_three</b> (سه رقم)، <b>plate_iran</b> (کد ایران). مثلاً «۷۹۸ع۸۱-۱۱» در نامه یعنی دو رقم ۸۱، حرف ع، سه رقم ۷۹۸ و کد ایران ۱۱. فیلدِ قدیمیِ <b>plate</b> (یک رشته) هم هنوز پذیرفته می‌شود.<br>
                    • <b>model_year</b> (مدل)، <b>prev_policy_number</b> (بیمه‌نامه‌ی قبلی) و <b>prev_insurer</b> در «اطلاعات صدور»ِ همان ردیف هم ثبت می‌شوند.
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
        <div class="modal-content w-full max-w-2xl p-0 relative max-h-[90vh] overflow-y-auto">
            <div class="p-5 text-white relative" style="background:linear-gradient(125deg,#1e1b4b,#3730a3 55%,#0e7490)">
                <button type="button" onclick="closeModal('add-company-modal')" class="absolute top-4 left-4 text-white/70 hover:text-white hover-target text-xl"><i class="fas fa-times"></i></button>
                <p class="text-[11px] opacity-80">مشخصات، تسویه و قسط‌بندیِ شرکت</p>
                <h3 class="font-black text-lg"><i class="fas fa-building ml-1"></i><span id="cm-form-title">افزودن شرکت جدید</span></h3>
            </div>
            <div class="p-5">
                <input type="hidden" id="cm-company-id">
                <div class="cmx-sec">
                    <h4><i class="fas fa-id-card ml-1"></i>مشخصات</h4>
                    <div class="float-input"><input type="text" id="cm-company-name" placeholder=" "><label>نام شرکت *</label></div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="float-input"><input type="text" id="cm-economic-code" dir="ltr" placeholder=" "><label>کد اقتصادی / شناسه ملی</label></div>
                        <div class="float-input"><input type="text" id="cm-phone" dir="ltr" placeholder=" "><label>شماره تماس</label></div>
                    </div>
                    <div class="float-input !mb-0"><input type="text" id="cm-address" placeholder=" "><label>آدرس</label></div>
                </div>
                <div class="cmx-sec">
                    <h4><i class="fas fa-sitemap ml-1"></i>ارتباط‌ها</h4>
                    <div class="float-input">
                        <select id="cm-parent-company"><option value="">شرکت مستقل / خودش مادر است</option></select>
                        <label>شرکت مادر (اگر زیرمجموعه است)</label>
                    </div>
                    <div class="float-input !mb-0">
                        <select id="cm-group-select" onchange="document.getElementById('cm-group-manual').value = this.value === '__manual__' ? '' : this.value">
                            <option value="">بدون گروه (بعداً قابل تنظیم است)</option>
                            <option value="__manual__">-- وارد کردن دستی شناسه‌ی گروه --</option>
                        </select>
                        <label>گروه بله‌ی مرتبط</label>
                        <input type="text" id="cm-group-manual" placeholder="اگر گروه در لیست نبود، شناسه‌ی چت را اینجا بنویسید" dir="ltr" class="mt-2">
                    </div>
                </div>
                <div class="cmx-sec">
                    <h4><i class="fas fa-sack-dollar ml-1"></i>تسویه، بیمه‌گر و قسط‌بندی</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                            <label>بیمه‌گر(های) مجاز</label>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="float-input !mb-0"><input type="number" id="cm-inst-count" min="1" placeholder=" "><label>تعداد اقساط</label></div>
                        <div class="float-input !mb-0"><input type="number" id="cm-offset-months" min="0" value="0" placeholder=" "><label>سررسید اول (ماه بعد)</label></div>
                        <div class="float-input !mb-0"><input type="number" id="cm-offset-days" min="0" value="0" placeholder=" "><label>+ روز</label></div>
                    </div>
                    <p class="text-[10.5px] text-slate-400 mt-2">سررسیدِ قسطِ اول از «تاریخ صدورِ داخلِ بیمه‌نامه» به‌علاوه‌ی این فاصله حساب می‌شود.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="resetCompanyForm()" class="px-4 bg-slate-100 hover:bg-slate-200 rounded-xl text-xs font-bold">فرم خالی</button>
                    <button onclick="createCompany()" class="flex-1 text-white font-black py-2.5 rounded-xl text-sm shadow-lg" style="background:linear-gradient(120deg,#4338ca,#6366f1)"><i class="fas fa-check ml-1"></i>ذخیره‌ی شرکت</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ورودِ شرکت‌ها از اکسل: راهنمای ستون‌ها، فایلِ نمونه، پیش‌نمایش و ثبت -->
    <div id="company-import-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-4xl p-0 relative max-h-[92vh] overflow-y-auto">
            <div class="p-5 text-white relative" style="background:linear-gradient(125deg,#064e3b,#059669 55%,#0e7490)">
                <button type="button" onclick="closeModal('company-import-modal')" class="absolute top-4 left-4 text-white/70 hover:text-white text-xl"><i class="fas fa-times"></i></button>
                <p class="text-[11px] opacity-85">سه قدم: فایلِ نمونه را بگیرید ← پرش کنید ← بارگذاری و پیش‌نمایش، بعد ثبت</p>
                <h3 class="font-black text-lg"><i class="fas fa-file-excel ml-1"></i>ورودِ شرکت‌ها از اکسل</h3>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid md:grid-cols-3 gap-3">
                    <a href="api/company_actions.php?action=company_import_sample" class="cmx-sec !mb-0 hover:border-emerald-300 hover:bg-emerald-50/40 block">
                        <p class="text-[11px] font-black text-emerald-700"><span class="cmi-step">۱</span>دانلودِ اکسلِ نمونه</p>
                        <p class="text-[10.5px] text-slate-500 mt-1">سرستون‌ها آماده‌اند و دو ردیفِ مثال دارد؛ ردیف‌های مثال را پاک و شرکت‌های خودتان را بنویسید.</p></a>
                    <div class="cmx-sec !mb-0"><p class="text-[11px] font-black text-emerald-700"><span class="cmi-step">۲</span>انتخابِ فایل</p>
                        <input type="file" id="cmi-file" accept=".xlsx,.xls,.csv" class="w-full text-[11px] mt-2"></div>
                    <div class="cmx-sec !mb-0"><p class="text-[11px] font-black text-emerald-700"><span class="cmi-step">۳</span>بررسی و ثبت</p>
                        <label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-600 mt-2"><input type="checkbox" id="cmi-update" checked>شرکت‌های موجود (هم‌نام) به‌روز شوند</label>
                        <button type="button" onclick="companyImport(false)" class="mt-2 w-full text-white font-black rounded-lg py-1.5" style="background:#0e7490;font-size:12px"><i class="fas fa-magnifying-glass ml-1"></i>پیش‌نمایش</button></div>
                </div>
                <div id="cmi-result"></div>
                <details class="cmx-sec !mb-0" open>
                    <summary class="text-[12px] font-black text-indigo-700 cursor-pointer"><i class="fas fa-book-open ml-1"></i>راهنمای ستون‌ها</summary>
                    <p class="text-[11px] text-slate-500 mt-2 mb-2">ردیفِ اول سرستون است؛ ترتیبِ ستون‌ها مهم نیست و ستونِ اضافه نادیده گرفته می‌شود. فقط «نام شرکت» الزامی است. اگر شرکتی با همین نام ثبت شده باشد، به‌روز می‌شود (خانه‌های خالی، مقدارِ قبلی را عوض نمی‌کنند).</p>
                    <div class="overflow-x-auto"><table class="w-full text-[11px] text-right cmi-cols no-count border-2 border-slate-200 rounded-xl overflow-hidden"><thead><tr><th>سرستون</th><th>الزامی</th><th>توضیح و مقدارهای مجاز</th><th>نام‌های قابل‌قبولِ دیگر</th><th>مثال</th></tr></thead><tbody id="cmi-cols"></tbody></table></div>
                </details>
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

    <!-- افزودن کاربر: اول نقش انتخاب می‌شود، بعد فیلدهای همان نقش -->
    <!-- ===== فرمِ یکپارچه‌ی کاربر: ساخت و ویرایش، همه‌ی نقش‌ها (مدیر کل، کارشناس صدور، کارشناس مالی، کارمند بیمه با ما، کاربر شرکت) ===== -->
    <div id="user-form-modal" class="modal-overlay">
        <div class="modal-content uf-wrap relative" style="margin:0 12px;">
            <div class="uf-head">
                <button type="button" onclick="closeModal('user-form-modal')" class="uf-x" title="بستن"><i class="fas fa-times"></i></button>
                <button type="button" id="uf-avatar-pv" class="uf-av" onclick="pickUserAvatar('uf')" title="عکسِ پروفایل"></button>
                <div class="min-w-0">
                    <p class="text-[11px] text-white/75 font-bold" id="uf-kicker">کاربرِ تازه</p>
                    <h3 class="text-lg font-black text-white" id="uf-title">افزودن کاربر</h3>
                    <p class="text-[11px] text-white/70 mt-0.5" id="uf-sub">نقش را انتخاب کنید؛ فقط فیلدهای همان نقش نمایش داده می‌شود.</p>
                </div>
            </div>
            <div class="uf-body">
                <input type="hidden" id="uf-mode"><input type="hidden" id="uf-id"><input type="hidden" id="uf-type"><input type="hidden" id="uf-role">
                <input type="hidden" id="uf-avatar"><input type="hidden" id="uf-avatar-changed">
                <section class="uf-sec">
                    <h4><span class="uf-step">۱</span>نقشِ کاربری</h4>
                    <div class="uf-roles" id="uf-roles">
                        <button type="button" data-r="ADMIN" style="--c:#d97706" onclick="ufPickRole('ADMIN')"><i class="fas fa-crown"></i><b>مدیر کل</b><small>دسترسیِ کامل به همه‌ی بخش‌ها</small></button>
                        <button type="button" data-r="OPERATOR" style="--c:#2563eb" onclick="ufPickRole('OPERATOR')"><i class="fas fa-file-signature"></i><b>کارشناس صدور</b><small>پرونده‌ها، بازدید و صدور</small></button>
                        <button type="button" data-r="FINANCE" style="--c:#059669" onclick="ufPickRole('FINANCE')"><i class="fas fa-calculator"></i><b>کارشناس مالی</b><small>اقساط، دریافت و پرداخت</small></button>
                        <button type="button" data-r="LIAISON" style="--c:#7c3aed" onclick="ufPickRole('LIAISON')"><i class="fas fa-user-tie"></i><b>کارمند بیمه با ما</b><small>پنلِ عادی یا پارسیان</small></button>
                        <button type="button" data-r="COMPANY" style="--c:#0d9488" onclick="ufPickRole('COMPANY')"><i class="fas fa-building"></i><b>کاربر شرکت</b><small>ورود از «کارشناس شرکت‌ها»</small></button>
                    </div>
                    <div id="uf-liaison-sub" class="uf-subroles hidden">
                        <button type="button" data-p="COMPANY_LIAISON" onclick="ufPickPanel('COMPANY_LIAISON')"><i class="fas fa-briefcase"></i><span><b>پنلِ عادی</b><small>شرکت‌ها، صادره‌ها، مالیِ شرکت‌ها و گفتگو</small></span></button>
                        <button type="button" data-p="PARSIAN" onclick="ufPickPanel('PARSIAN')"><i class="fas fa-file-circle-check"></i><span><b>پنلِ پارسیان</b><small>فقط ساختِ گزارشِ بازدید و گزارش‌های صادره‌ی خودش</small></span></button>
                    </div>
                    <p id="uf-role-note" class="hidden text-[10.5px] text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-3 py-2 mt-2"></p>
                </section>
                <div id="uf-rest" class="hidden">
                    <section class="uf-sec">
                        <h4><span class="uf-step">۲</span>مشخصات و ورود</h4>
                        <div class="uf-grid">
                            <label class="uf-f"><span>نام و نام‌خانوادگی *</span><input id="uf-fullname" oninput="paintUserAvatar('uf')" placeholder="مثلاً علی رضایی"></label>
                            <label class="uf-f"><span>نام کاربری * <em id="uf-user-lock" class="hidden">(ثابت)</em></span><input id="uf-username" dir="ltr" placeholder="ali.rezaei" autocomplete="off"></label>
                            <label class="uf-f"><span id="uf-pass-lbl">رمز عبور * (حداقل ۶)</span>
                                <div class="uf-inline"><input id="uf-password" dir="ltr" autocomplete="new-password" placeholder="••••••"><button type="button" onclick="ufGenPass()" title="ساختِ رمزِ تصادفی"><i class="fas fa-dice"></i></button></div></label>
                            <label class="uf-f"><span>شماره موبایل <em>(برای ورود با کدِ بله)</em></span><input id="uf-mobile" dir="ltr" inputmode="numeric" placeholder="09xxxxxxxxx"></label>
                            <label class="uf-f" id="uf-personnel-box"><span>کد پرسنلی <em>(اختیاری)</em></span><input id="uf-personnel" dir="ltr"></label>
                        </div>
                        <p id="uf-mobile-err" class="hidden text-[11px] text-red-600 font-bold mt-2"></p>
                    </section>
                    <section class="uf-sec" id="uf-company-sec">
                        <h4><span class="uf-step">۳</span>شرکت(ها)ی این کاربر *</h4>
                        <div class="msd" id="uf-companies"></div>
                        <p class="text-[10.5px] text-slate-400 mt-1.5">می‌توانید چند شرکت انتخاب کنید؛ کاربر در پنلش بین آن‌ها جابه‌جا می‌شود.</p>
                    </section>
                    <section class="uf-sec" id="uf-chat-sec">
                        <h4><i class="fas fa-comments text-indigo-500"></i>گفتگو با شرکت‌ها</h4>
                        <div class="uf-seg" id="uf-chat-mode">
                            <button type="button" data-v="ROLE" onclick="ufChatMode('ROLE')">طبقِ نقش</button><button type="button" data-v="ALL" onclick="ufChatMode('ALL')">همه‌ی شرکت‌ها</button>
                            <button type="button" data-v="LIST" onclick="ufChatMode('LIST')">شرکت‌های انتخابی</button><button type="button" data-v="NONE" onclick="ufChatMode('NONE')">هیچ شرکتی</button>
                        </div>
                        <div class="msd mt-2 hidden" id="uf-chatcos"></div>
                    </section>
                    <section class="uf-sec" id="uf-rpw-sec">
                        <h4><i class="fas fa-file-pen text-violet-500"></i>رمزِ ویرایشِ گزارش بازدید <span id="uf-rpw-state" class="hidden"></span></h4>
                        <div class="uf-grid">
                            <label class="uf-f"><span>رمزِ جداگانه <em>(خالی = رمزِ پنلِ خودِ کاربر)</em></span><input id="uf-rpw" dir="ltr" autocomplete="off"></label>
                            <label class="flex items-center gap-2 text-[11px] font-bold text-slate-500 cursor-pointer mt-5" id="uf-rpw-clear-box"><input type="checkbox" id="uf-rpw-clear" class="accent-violet-600"> برداشتنِ رمزِ جداگانه</label>
                        </div>
                    </section>
                    <section class="uf-sec uf-confirm" id="uf-admin-sec">
                        <h4><i class="fas fa-shield-halved text-rose-500"></i>تأیید</h4>
                        <label class="uf-f"><span>رمزِ خودتان (مدیر) *</span><input type="password" id="uf-admin-pass" dir="ltr" autocomplete="current-password"></label>
                        <p class="text-[10.5px] text-slate-400 mt-1.5">اگر شماره‌ی موبایل عوض شود، کاربر از ربات بیرون می‌آید و پیام می‌گیرد که با شماره‌ی جدید دوباره وارد شود.</p>
                    </section>
                    <button type="button" id="uf-submit" onclick="ufSubmit()" class="uf-submit"><i class="fas fa-user-check ml-1"></i><span>ثبتِ کاربر</span></button>
                </div>
            </div>
        </div>
    </div>
    <style>
        .uf-wrap { width: 100%; max-width: 760px; padding: 0; overflow: hidden; display: flex; flex-direction: column; max-height: 94vh; }
        .uf-head { position: relative; display: flex; align-items: center; gap: 16px; padding: 22px 24px; background: linear-gradient(125deg, #0f172a, #3730a3 55%, #0e7490); overflow: hidden; }
        .uf-head::after { content: ''; position: absolute; width: 260px; height: 260px; border-radius: 50%; background: radial-gradient(circle, rgba(56,189,248,.35), transparent 70%); left: -60px; top: -120px; pointer-events: none; }
        .uf-x { position: absolute; left: 16px; top: 16px; width: 34px; height: 34px; border-radius: 11px; background: rgba(255,255,255,.14); color: #fff; z-index: 31; }
        .uf-x:hover { background: rgba(255,255,255,.28); }
        .uf-av { width: 62px; height: 62px; border-radius: 20px; background: rgba(255,255,255,.15); border: 2px solid rgba(255,255,255,.35); flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; position: relative; z-index: 2; }
        .uf-head > div { position: relative; z-index: 2; }
        .uf-body { padding: 18px 22px 22px; overflow-y: auto; background: #f8fafc; }
        .uf-sec { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 14px 16px; margin-bottom: 12px; }
        .uf-sec h4 { font-size: 12.5px; font-weight: 900; color: #1e293b; display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .uf-step { width: 22px; height: 22px; border-radius: 8px; background: #4f46e5; color: #fff; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; }
        .uf-roles { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
        @media (min-width: 640px) { .uf-roles { grid-template-columns: repeat(5, 1fr); } }
        .uf-roles button { --c: #4f46e5; position: relative; border: 2px solid #e2e8f0; border-radius: 16px; padding: 12px 8px 10px; background: #fff; text-align: center; transition: .18s; }
        .uf-roles button i { display: flex; width: 38px; height: 38px; margin: 0 auto 6px; border-radius: 12px; align-items: center; justify-content: center; font-size: 16px; color: var(--c); background: color-mix(in srgb, var(--c) 12%, white); }
        .uf-roles button b { display: block; font-size: 12px; color: #1e293b; }
        .uf-roles button small { display: block; font-size: 9.5px; color: #94a3b8; font-weight: 700; margin-top: 2px; line-height: 1.5; }
        .uf-roles button:hover:not(:disabled) { border-color: var(--c); transform: translateY(-2px); box-shadow: 0 10px 22px -14px var(--c); }
        .uf-roles button.on { border-color: var(--c); background: color-mix(in srgb, var(--c) 7%, white); box-shadow: 0 0 0 4px color-mix(in srgb, var(--c) 15%, transparent); }
        .uf-roles button.on::after { content: '✓'; position: absolute; top: 6px; left: 8px; width: 18px; height: 18px; border-radius: 50%; background: var(--c); color: #fff; font-size: 11px; line-height: 18px; }
        .uf-roles button:disabled { opacity: .35; cursor: not-allowed; filter: grayscale(.6); }
        .uf-subroles { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; animation: ufIn .25s ease-out; }
        .uf-subroles button { display: flex; align-items: center; gap: 10px; text-align: right; border: 2px solid #ede9fe; border-radius: 14px; padding: 10px 12px; background: #faf5ff; }
        .uf-subroles button i { color: #7c3aed; font-size: 18px; }
        .uf-subroles button b { display: block; font-size: 12px; color: #4c1d95; } .uf-subroles button small { display: block; font-size: 10px; color: #8b5cf6; font-weight: 700; }
        .uf-subroles button.on { border-color: #7c3aed; background: #f3e8ff; box-shadow: 0 0 0 3px rgba(124,58,237,.15); }
        .uf-grid { display: grid; grid-template-columns: 1fr; gap: 10px; } @media (min-width: 640px) { .uf-grid { grid-template-columns: 1fr 1fr; } }
        .uf-f > span { display: block; font-size: 10.5px; font-weight: 800; color: #64748b; margin-bottom: 4px; } .uf-f em { font-style: normal; color: #94a3b8; font-weight: 700; }
        .uf-f input, .uf-inline { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 9px 12px; font-size: 13px; background: #fff; transition: .15s; }
        .uf-f input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 4px rgba(129,140,248,.18); }
        .uf-f input:disabled { background: #f1f5f9; color: #64748b; }
        .uf-inline { display: flex; padding: 0; overflow: hidden; } .uf-inline input { border: 0; box-shadow: none !important; flex: 1; border-radius: 0; }
        .uf-inline button { padding: 0 12px; color: #6366f1; border-right: 1px solid #e2e8f0; background: #f8fafc; }
        .uf-seg { display: flex; flex-wrap: wrap; gap: 4px; background: #f1f5f9; border-radius: 12px; padding: 3px; }
        .uf-seg button { flex: 1; min-width: 90px; padding: 7px 8px; border-radius: 9px; font-size: 11px; font-weight: 800; color: #475569; }
        .uf-seg button.on { background: #fff; color: #4338ca; box-shadow: 0 2px 8px -3px rgba(15,23,42,.3); }
        .uf-confirm { border-color: #fecdd3; background: #fff7f8; }
        .uf-submit { width: 100%; padding: 13px; border-radius: 16px; color: #fff; font-weight: 900; font-size: 14px; background: linear-gradient(120deg, #4f46e5, #0ea5e9); box-shadow: 0 14px 30px -16px rgba(79,70,229,.8); transition: .2s; }
        .uf-submit:hover { transform: translateY(-1px); } .uf-submit:disabled { opacity: .6; }
        #uf-rest { animation: ufIn .3s ease-out; }
        @keyframes ufIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        /* لیستِ کشوییِ چندانتخابی (شرکت‌ها) */
        .msd { position: relative; }
        .msd-trigger { width: 100%; min-height: 44px; border: 1.5px solid #e2e8f0; border-radius: 13px; padding: 6px 10px 6px 36px; background: #fff; display: flex; flex-wrap: wrap; gap: 5px; align-items: center; text-align: right; position: relative; }
        .msd-trigger::after { content: '▾'; position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; }
        .msd.open .msd-trigger { border-color: #818cf8; box-shadow: 0 0 0 4px rgba(129,140,248,.18); }
        .msd-ph { font-size: 12px; color: #94a3b8; font-weight: 700; padding: 4px 2px; }
        .msd-chip { display: inline-flex; align-items: center; gap: 5px; background: #eef2ff; color: #3730a3; border-radius: 999px; padding: 3px 4px 3px 10px; font-size: 11px; font-weight: 800; }
        .msd-chip i { width: 17px; height: 17px; border-radius: 50%; background: #c7d2fe; display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-style: normal; cursor: pointer; }
        .msd-chip i:hover { background: #a5b4fc; }
        .msd-panel { position: absolute; z-index: 40; top: calc(100% + 6px); right: 0; left: 0; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 24px 50px -20px rgba(15,23,42,.35); padding: 8px; animation: ufIn .15s ease-out; }
        .msd-panel input[type=search] { width: 100%; border: 1px solid #e2e8f0; border-radius: 10px; padding: 7px 10px; font-size: 12px; margin-bottom: 6px; }
        .msd-tools { display: flex; align-items: center; gap: 8px; font-size: 10.5px; font-weight: 800; padding: 0 4px 6px; color: #64748b; }
        .msd-tools button { color: #4f46e5; } .msd-tools span { margin-right: auto; }
        .msd-list { max-height: 210px; overflow-y: auto; }
        .msd-opt { display: flex; align-items: center; gap: 8px; padding: 7px 8px; border-radius: 10px; font-size: 12px; cursor: pointer; }
        .msd-opt:hover { background: #f8fafc; } .msd-opt.on { background: #eef2ff; color: #3730a3; font-weight: 800; }
        .msd-opt .bx { width: 18px; height: 18px; border-radius: 6px; border: 1.5px solid #cbd5e1; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; color: #fff; flex-shrink: 0; }
        .msd-opt.on .bx { background: #4f46e5; border-color: #4f46e5; }
    </style>

    <!-- ویرایش کاربر (همه‌چیز جز نام کاربری) - با رمزِ مدیر -->
    <!-- دسترسیِ صفحه‌به‌صفحه‌ی یک کاربرِ پنل (فقط مدیر کل) -->
    <div id="perm-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-4xl p-0 relative overflow-hidden" style="max-height:94vh; display:flex; flex-direction:column; margin:0 12px;">
            <div class="px-6 py-4 text-white flex items-center gap-3" style="background:linear-gradient(120deg,#4f46e5,#7c3aed)">
                <span class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center text-lg"><i class="fas fa-user-lock"></i></span>
                <div class="flex-1 min-w-0"><h3 class="font-black text-base">دسترسی‌های <span id="pm-name"></span></h3>
                    <p class="text-[11px] text-white/80">برای هر صفحه تعیین کنید چه کاری بتواند بکند. نقش: <b id="pm-role"></b></p></div>
                <button type="button" onclick="closeModal('perm-modal')" class="text-white/80 hover:text-white text-xl"><i class="fas fa-times"></i></button>
            </div>
            <div class="px-6 pt-4 pb-2 border-b border-slate-100 bg-slate-50/60">
                <div class="pm-seg inline-flex rounded-xl bg-white border border-slate-200 p-1 text-xs font-black">
                    <button type="button" data-m="role" onclick="pmSetMode('role')"><i class="fas fa-id-badge ml-1"></i>همان دسترسیِ پیش‌فرضِ نقش</button>
                    <button type="button" data-m="custom" onclick="pmSetMode('custom')"><i class="fas fa-sliders ml-1"></i>سفارشی (صفحه به صفحه)</button>
                </div>
                <div id="pm-tools" class="flex flex-wrap items-center gap-1.5 mt-3 text-[11px]">
                    <span class="text-slate-500 font-bold ml-1">الگوی سریع:</span>
                    <button type="button" onclick="pmPreset('all')" class="pm-chip">همه‌چیز</button>
                    <button type="button" onclick="pmPreset('view')" class="pm-chip">فقط مشاهده‌ی همه</button>
                    <button type="button" onclick="pmPreset('viewexp')" class="pm-chip">مشاهده + خروجیِ همه</button>
                    <button type="button" onclick="pmPreset('role')" class="pm-chip">از پیش‌فرضِ نقش</button>
                    <button type="button" onclick="pmPreset('none')" class="pm-chip text-rose-600">پاک کردنِ همه</button>
                    <select id="pm-copy" onchange="pmCopyFrom(this.value)" class="pm-chip bg-white"><option value="">کپی از کاربرِ دیگر…</option></select>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-4 no-count" id="pm-body"></div>
            <div class="px-6 py-3 border-t border-slate-100 bg-white flex flex-wrap items-center gap-3">
                <p id="pm-summary" class="text-[11px] text-slate-500 flex-1"></p>
                <input type="password" id="pm-admin-pass" placeholder="رمزِ خودتان برای تایید" class="border border-slate-200 rounded-xl px-3 py-2 text-xs w-48" autocomplete="new-password">
                <button type="button" onclick="pmSave()" class="text-white text-xs font-black px-5 py-2.5 rounded-xl" style="background:linear-gradient(120deg,#4f46e5,#7c3aed)"><i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی دسترسی‌ها</button>
            </div>
        </div>
    </div>
    <style>
        .pm-seg button { padding: 6px 12px; border-radius: 9px; color: #64748b; }
        .pm-seg button.on { background: #4f46e5; color: #fff; box-shadow: 0 4px 12px -4px rgba(79,70,229,.6); }
        .pm-chip { border: 1px solid #e2e8f0; background: #fff; border-radius: 999px; padding: 4px 10px; font-weight: 700; color: #334155; }
        .pm-chip:hover { border-color: #a5b4fc; background: #eef2ff; }
        .pm-grp { border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; margin-bottom: 12px; }
        .pm-grp h4 { background: #f8fafc; padding: 8px 12px; font-size: 12px; font-weight: 900; color: #334155; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #e2e8f0; }
        .pm-tbl { width: 100%; font-size: 12px; }
        .pm-tbl th { font-size: 10.5px; color: #64748b; font-weight: 800; padding: 6px 4px; text-align: center; }
        .pm-tbl th:first-child, .pm-tbl td:first-child { text-align: right; padding-right: 12px; }
        .pm-tbl td { padding: 6px 4px; text-align: center; border-top: 1px solid #f1f5f9; }
        .pm-tbl tr:hover td { background: #fafaff; }
        .pm-tbl th button { color: #6366f1; font-size: 10px; display: block; margin: 2px auto 0; }
        .pm-cb { width: 18px; height: 18px; accent-color: #4f46e5; cursor: pointer; }
        .pm-na { color: #cbd5e1; }
        .pm-row-all { font-size: 10px; color: #6366f1; font-weight: 800; margin-right: 6px; }
        #pm-body.pm-locked { opacity: .55; pointer-events: none; filter: grayscale(.4); }
        .su-chip.pc { background: #ede9fe; color: #6d28d9; }
    </style>
    <!-- حذف کاربر - با رمزِ مدیر -->
    <div id="delete-staff-user-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-sm p-6 relative" style="margin:0 12px;">
            <button type="button" onclick="closeModal('delete-staff-user-modal')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-2 text-red-600"><i class="fas fa-user-slash ml-1"></i>حذف کاربر</h3>
            <p class="text-xs text-slate-600 leading-6 mb-3">«<b id="dsu-name"></b>» دیگر نمی‌تواند وارد پنل یا ربات شود. <b>نامش روی همه‌ی کارهایی که قبلاً انجام داده باقی می‌ماند.</b></p>
            <input type="hidden" id="dsu-id"><input type="hidden" id="dsu-type">
            <div class="float-input"><input type="password" id="dsu-admin-pass" dir="ltr" placeholder=" "><label>رمزِ خودتان (مدیر) برای تایید *</label></div>
            <button onclick="confirmDeleteStaffUser()" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-sm">حذف کاربر</button>
        </div>
    </div>

    <!-- پیام مستقیم به یک کاربر -->
    <div id="dm-user-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-6 relative" style="margin:0 12px;">
            <button type="button" onclick="closeModal('dm-user-modal')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 text-xl"><i class="fas fa-times"></i></button>
            <h3 class="font-black text-lg mb-1"><i class="fas fa-paper-plane text-blue-500 ml-1"></i>پیام مستقیم به <span id="dm-name"></span></h3>
            <p id="dm-channel" class="text-[11px] text-slate-500 mb-3 leading-5"></p>
            <input type="hidden" id="dm-id"><input type="hidden" id="dm-type">
            <textarea id="dm-text" rows="5" maxlength="3000" placeholder="متن پیام..." class="w-full border rounded-xl p-3 text-sm outline-none focus:border-blue-400"></textarea>
            <button id="dm-send-btn" onclick="sendUserDM()" class="w-full mt-3 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm"><i class="fas fa-paper-plane ml-1"></i>ارسال</button>
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

    <!-- ثبت دستیِ معرفی‌نامه (فقط معرفی‌نامه؛ درخواست‌ها از «لیست صدور» ثبت می‌شوند) -->
    <div id="manual-intro-modal" class="modal-overlay">
        <div class="modal-content mi-box w-full max-w-2xl relative" style="margin:0 12px;">
            <div class="mi-head">
                <button type="button" onclick="closeManualIntroModal()" class="mi-x"><i class="fas fa-times"></i></button>
                <span class="mi-ic"><i class="fas fa-envelope-open-text"></i></span>
                <div><h2>ثبت دستی معرفی‌نامه</h2><p>فایل را بگذارید تا اطلاعاتش خودکار خوانده شود، یا همه را دستی بنویسید.</p></div>
            </div>
            <div class="mi-body">
                <!-- بارگذاری و تشخیصِ خودکار -->
                <label id="mi-drop" class="mi-drop">
                    <input type="file" id="mi-file" accept=".pdf,.jpg,.jpeg,.png,.webp" hidden>
                    <span class="mi-drop-ic"><i class="fas fa-cloud-arrow-up"></i></span>
                    <b>فایل معرفی‌نامه را اینجا رها کنید یا انتخاب کنید</b>
                    <small>PDF یا عکس · بعد از بارگذاری بررسی می‌شود که معرفی‌نامه باشد و فیلدها خودکار پر می‌شوند</small>
                </label>
                <div id="mi-scan" class="mi-scan hidden"><span class="mi-spin"></span><div><b>در حال خواندن و تشخیصِ معرفی‌نامه...</b><small id="mi-scan-name"></small></div></div>
                <div id="mi-attached" class="mi-attached hidden"></div>
                <div id="mi-alert" class="hidden"></div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                    <label class="mi-f">نام و نام خانوادگی پرسنل *<input id="mi-full-name" autocomplete="off"></label>
                    <label class="mi-f">کد ملی (۱۰ رقم) *<input id="mi-national-code" dir="ltr" inputmode="numeric" maxlength="10" autocomplete="off"></label>
                    <label class="mi-f">کد پرسنلی<input id="mi-personnel-code" dir="ltr" inputmode="numeric" autocomplete="off"></label>
                    <label class="mi-f">شرکت / کارفرما<input id="mi-company-name" list="mi-companies-dl" placeholder="مثلاً ماموت" autocomplete="off"></label>
                    <label class="mi-f">تاریخ صدور معرفی‌نامه (شمسی)<input id="mi-letter-date" dir="ltr" inputmode="numeric" placeholder="۱۴۰۵/۰۷/۰۱"></label>
                    <label class="mi-f">موبایل پرسنل<input id="mi-mobile" dir="ltr" inputmode="numeric" maxlength="11" placeholder="۰۹۱۲..."></label>
                    <label class="mi-f">سهمیه‌ی معرفی‌نامه<input id="mi-quota" dir="ltr" inputmode="numeric" placeholder="پیش‌فرضِ تنظیمات"></label>
                </div>
                <datalist id="mi-companies-dl"></datalist>
                <p class="text-[11px] text-slate-400 mt-3"><i class="fas fa-circle-info ml-1"></i>بعد از ثبت، درخواستِ بیمه (ثالث/بدنه) را از «لیست صدور» ← «درخواست جدید کارکنان» برای همین معرفی‌نامه ثبت کنید.</p>
            </div>
            <div class="mi-foot">
                <button id="mi-submit" onclick="submitManualIntro()" class="mi-btn"><i class="fas fa-check ml-1"></i>ثبت معرفی‌نامه</button>
                <button onclick="closeManualIntroModal()" class="mi-btn ghost">انصراف</button>
            </div>
        </div>
    </div>

    <!-- «درخواست جدید کارکنان» در لیست صدور: اول معرفی‌نامه انتخاب می‌شود -->
    <div id="intro-picker-modal" class="modal-overlay">
        <div class="modal-content mi-box w-full max-w-xl relative" style="margin:0 12px;">
            <div class="mi-head teal">
                <button type="button" onclick="closeModal('intro-picker-modal')" class="mi-x"><i class="fas fa-times"></i></button>
                <span class="mi-ic"><i class="fas fa-file-circle-plus"></i></span>
                <div><h2>درخواست جدید کارکنان</h2><p>این درخواست برای کدام معرفی‌نامه است؟</p></div>
            </div>
            <div class="mi-body">
                <input id="ip-q" class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm mb-3 outline-none focus:border-teal-500" placeholder="جستجو: نام، کد ملی، کد پرسنلی، شرکت، ماه...">
                <div id="ip-list" class="space-y-2"></div>
            </div>
            <div class="mi-foot"><button onclick="closeModal('intro-picker-modal'); openManualIntroModal()" class="mi-btn ghost"><i class="fas fa-envelope-open-text ml-1"></i>معرفی‌نامه‌ی تازه ثبت کنید</button></div>
        </div>
    </div>

    <!-- مودال ثبت دستیِ درخواست کارکنان: پرسنل و معرفی‌نامه -> اطلاعات درخواست -> چک‌لیست مدارک -->
    <div id="manual-create-modal" class="modal-overlay">
        <div class="modal-content mi-box w-full max-w-3xl relative" style="margin:0 12px;">
            <div class="mi-head teal">
                <button type="button" onclick="closeManualCreateModal()" class="mi-x"><i class="fas fa-times"></i></button>
                <span class="mi-ic"><i class="fas fa-file-circle-plus"></i></span>
                <div><h2 id="mc-title">ثبت دستی درخواست کارکنان</h2><p>اطلاعاتی که ربات می‌پرسد و مدارکِ همان درخواست؛ فهرستِ مدارک با هر انتخاب عوض می‌شود.</p></div>
            </div>
            <div class="mi-body mc-body">
            <input type="hidden" id="mc-intro-id">
            <input type="hidden" id="mc-case-id">

            <!-- ۱. پرسنل و معرفی‌نامه -->
            <div id="mc-person-section" class="border border-slate-200 rounded-xl p-3 mb-3">
                <h4 class="text-xs font-bold text-slate-600 mb-2"><i class="fas fa-id-badge ml-1 text-slate-300"></i>پرسنل و معرفی‌نامه</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                    <label class="text-[11px] font-bold text-slate-500 block">نام و نام خانوادگی *<input type="text" id="mc-full-name" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" autocomplete="off"></label>
                    <label class="text-[11px] font-bold text-slate-500 block">کد ملی (۱۰ رقم) *<input type="text" id="mc-national-code" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" maxlength="10" autocomplete="off"></label>
                    <label class="text-[11px] font-bold text-slate-500 block">کد پرسنلی<input type="text" id="mc-personnel-code" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" autocomplete="off"></label>
                    <label class="text-[11px] font-bold text-slate-500 block">شرکت / کارفرما<input type="text" id="mc-company-name" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" list="mc-companies-dl" placeholder="مثلاً ماموت" autocomplete="off"></label>
                    <label class="text-[11px] font-bold text-slate-500 block">موبایل پرسنل<input type="text" id="mc-mobile" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" maxlength="11" placeholder="۰۹۱۲..." autocomplete="off"></label>
                    <label class="text-[11px] font-bold text-slate-500 block">تاریخ معرفی‌نامه (شمسی)<input type="text" id="mc-letter-date" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" placeholder="۱۴۰۵/۰۷/۰۱"></label>
                    <label class="text-[11px] font-bold text-slate-500 block">سهمیه‌ی معرفی‌نامه<input type="text" id="mc-quota" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" placeholder="پیش‌فرض تنظیمات"></label>
                    <label class="text-[11px] font-bold text-slate-500 block md:col-span-2">فایل معرفی‌نامه (اختیاری)<input type="file" id="mc-intro-file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mt-1 w-full text-xs"></label>
                </div>
                <datalist id="mc-companies-dl"></datalist>
            </div>
            <div id="mc-intro-summary" class="hidden text-xs font-bold text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-xl px-3 py-2 mb-3"></div>

            <!-- ۲. اطلاعات درخواست - فیلدها بر اساس نوع بیمه و نسبت، دقیقاً مثل ربات -->
            <div id="mc-request-section" class="border border-slate-200 rounded-xl p-3 mb-3">
                <h4 class="text-xs font-bold text-slate-600 mb-2"><i class="fas fa-car ml-1 text-slate-300"></i>اطلاعات درخواست</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mb-2">
                    <label class="text-[11px] font-bold text-slate-500 block">نوع بیمه *
                        <select id="mc-insurance-type" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option value="THIRDPARTY">ثالث</option><option value="BODY">بدنه</option></select></label>
                    <label class="text-[11px] font-bold text-slate-500 block">بیمه‌گذار (نسبت با پرسنل) *
                        <select id="mc-relationship" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option>خودم</option><option>پدر</option><option>مادر</option><option>همسر</option><option>فرزند</option></select></label>
                    <label class="text-[11px] font-bold text-slate-500 block">مدرک مالکیت خودرو *
                        <select id="mc-ownership" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option value="کارت ماشین">کارت ماشین</option><option value="سند">سند</option></select></label>
                    <label class="text-[11px] font-bold text-slate-500 block mc-rel-only hidden">نام و نام خانوادگی بیمه‌گذار *<input type="text" id="mc-insured-name" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white"></label>
                    <label class="text-[11px] font-bold text-slate-500 block mc-rel-only hidden">کد ملی بیمه‌گذار *<input type="text" id="mc-insured-nid" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" maxlength="10"></label>
                </div>
                <label class="text-[11px] font-bold text-slate-500 block mb-1">پلاک *</label>
                <div id="mc-plate-mount" class="mb-3 max-w-md"></div>

                <p class="text-[11px] font-bold text-slate-600 mb-1 mt-1"><i class="fas fa-user ml-1 text-slate-300"></i>اطلاعات بیمه‌گذار <span id="mc-insured-who" class="text-slate-400 font-normal"></span></p>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mb-3">
                    <label class="text-[11px] font-bold text-slate-500 block">تاریخ تولد (شمسی) *<input type="text" id="mc-birth" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" placeholder="۱۳۷۰/۰۵/۱۲"></label>
                    <label class="text-[11px] font-bold text-slate-500 block">موبایل *<input type="text" id="mc-phone" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" maxlength="11" placeholder="۰۹۱۲..."></label>
                    <label class="text-[11px] font-bold text-slate-500 block">کد پستی (۱۰ رقم) *<input type="text" id="mc-postal" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" maxlength="10"></label>
                    <label class="text-[11px] font-bold text-slate-500 block col-span-2 md:col-span-3">آدرس کامل *<input type="text" id="mc-address" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white"></label>
                </div>

                <!-- ثالث -->
                <div class="mc-third-only">
                    <p class="text-[11px] font-bold text-slate-600 mb-1"><i class="fas fa-shield-halved ml-1 text-slate-300"></i>بیمه ثالث</p>
                    <label class="text-[11px] font-bold text-slate-500 block max-w-xs">سقف تعهد مالی *
                        <select id="mc-liability" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white"><option value="">انتخاب کنید</option></select></label>
                </div>

                <!-- بدنه -->
                <div class="mc-body-only hidden">
                    <p class="text-[11px] font-bold text-slate-600 mb-1"><i class="fas fa-car-burst ml-1 text-slate-300"></i>بیمه بدنه</p>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mb-2">
                        <label class="text-[11px] font-bold text-slate-500 block">بیمه بدنه‌ی قبلی دارد؟ *
                            <select id="mc-prev-body" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option value="خیر">خیر</option><option value="بله">بله</option></select></label>
                        <label class="text-[11px] font-bold text-slate-500 block mc-prev-only hidden">از بیمه‌ی قبلی خسارت گرفته؟ *
                            <select id="mc-prev-claim" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option value="خیر">خیر</option><option value="بله">بله</option></select></label>
                        <label class="text-[11px] font-bold text-slate-500 block mc-noclaim-only hidden">چند سال عدم خسارت؟ *<input type="text" id="mc-no-claim-years" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" maxlength="2"></label>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mb-2 items-end">
                        <label class="text-[11px] font-bold text-slate-500 block">ارزش خودرو (ریال) *<input type="text" id="mc-car-value" class="money-input mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric"></label>
                        <label class="text-[11px] font-bold text-slate-500 flex items-center gap-1.5 pb-2"><input type="checkbox" id="mc-car-value-auto" onchange="mcRefresh()"> محاسبه‌ی ارزش توسط کارشناس</label>
                    </div>
                    <p class="text-[11px] font-bold text-slate-500 mb-1">پوشش‌های اضافی (اختیاری)</p>
                    <div id="mc-coverages" class="grid grid-cols-1 md:grid-cols-2 gap-1.5"></div>
                </div>
            </div>

            <!-- ۳. مدارک لازم - فهرست همان لحظه با انتخاب‌های بالا عوض می‌شود -->
            <div id="mc-pick-section" class="border-2 border-emerald-100 bg-emerald-50/30 rounded-xl p-3 mb-3">
                <h4 class="text-xs font-bold text-emerald-700 mb-1"><i class="fas fa-list-check ml-1"></i>مدارک لازم برای این درخواست</h4>
                <p class="text-[10px] text-slate-500 mb-2">هر مدرک را انتخاب کنید؛ با «ثبت نهایی» همه با نام‌گذاریِ درست بایگانی و تایید می‌شوند. مدرکی که الان ندارید را بعداً هم می‌شود بارگذاری کرد.</p>
                <div id="mc-pick-list" class="checklist-grid"></div>
                <div class="mt-2 rounded-lg border border-slate-200 bg-white p-2">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-[11px] font-bold text-slate-600">سایر مدارک (اختیاری)</span>
                        <button type="button" onclick="mcAddOther()" class="text-[10px] font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 px-2 py-0.5 rounded"><i class="fas fa-plus ml-1"></i>افزودن</button>
                    </div>
                    <div id="mc-other-list" class="space-y-1.5"></div>
                </div>
            </div>

            <!-- ۴. بعد از ثبت: مدارکی که هنوز مانده -->
            <div id="mc-docs-section" class="hidden border-2 border-amber-100 bg-amber-50/30 rounded-xl p-3 mb-3">
                <h4 class="text-xs font-bold text-amber-700 mb-1"><i class="fas fa-list-check ml-1"></i>وضعیت مدارک <span id="mc-case-code" class="text-slate-500 font-normal"></span></h4>
                <p id="mc-result-note" class="text-[11px] text-slate-600 mb-2"></p>
                <div id="mc-docs-list" class="checklist-grid"></div>
            </div>

            </div>
            <div class="mi-foot">
                <button id="mc-submit-btn" onclick="submitManualRequest()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-black py-3 rounded-xl shadow-lg shadow-blue-500/30 hover-target transition-colors"><i class="fas fa-check ml-1"></i>ثبت نهایی درخواست</button>
                <button id="mc-open-case-btn" onclick="mcOpenCreatedCase()" class="hidden flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl">باز کردن پرونده</button>
                <button onclick="closeManualCreateModal()" class="mi-btn ghost">بستن</button>
            </div>
        </div>
    </div>

    <!-- مودال ویرایش پروفایل -->
    <div id="profile-modal" class="modal-overlay">
        <div class="modal-content w-full max-w-md p-8 relative">
            <button type="button" onclick="document.getElementById('profile-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h2 class="text-xl font-black mb-6 text-slate-800 text-center border-b border-slate-100 pb-3"><i class="fas fa-user-edit text-blue-500 ml-2"></i>ویرایش پروفایل من</h2>
            <!-- عکسِ پروفایل: در پیام‌رسان و فهرستِ کاربران همه‌جا همین دیده می‌شود -->
            <div class="flex flex-col items-center gap-2 mb-5">
                <button type="button" id="my-avatar-big" onclick="changeMyAvatar()" class="relative group" title="تغییرِ عکس"></button>
                <button type="button" onclick="changeMyAvatar()" class="text-xs font-bold text-blue-600 hover:underline"><i class="fas fa-camera ml-1"></i>تغییرِ عکسِ پروفایل</button>
            </div>
            
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
                    <i class="fas fa-info-circle text-blue-500"></i> سطح دسترسی شما (<strong class="text-blue-600"><?php echo htmlspecialchars(role_fa($realRole)) . (!empty($permBoot['custom']) ? ' - سفارشی' : ''); ?></strong>) غیرقابل تغییر است.
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
    <!-- دیالوگ‌های عمومیِ سایت (جایگزینِ alert / confirm / prompt مرورگر) - بالاتر از بقیه‌ی مودال‌ها -->
    <div id="generic-confirm-modal" class="modal-overlay" style="z-index:1000005">
        <div class="modal-content w-full max-w-sm p-6 relative text-center" style="margin:0 12px;">
            <button type="button" onclick="cancelGenericConfirm()" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <div id="generic-confirm-icon" class="w-16 h-16 bg-amber-100 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-inner"><i class="fas fa-question"></i></div>
            <h3 id="generic-confirm-title" class="text-lg font-black text-slate-800 mb-2">تایید عملیات</h3>
            <p id="generic-confirm-message" class="text-xs text-slate-500 mb-6 leading-6" style="white-space:pre-line"></p>
            <div class="flex gap-3">
                <button type="button" id="generic-confirm-ok" onclick="executeGenericConfirm()" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs">بله، مطمئنم</button>
                <button type="button" onclick="cancelGenericConfirm()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl transition-colors hover-target text-xs">انصراف</button>
            </div>
        </div>
    </div>

    <div id="generic-prompt-modal" class="modal-overlay" style="z-index:1000005">
        <div class="modal-content w-full max-w-sm p-6 relative" style="margin:0 12px;">
            <button type="button" onclick="cancelGenericPrompt()" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <h3 id="generic-prompt-title" class="text-lg font-black text-slate-800 mb-1 pl-8">ویرایش</h3>
            <p id="generic-prompt-hint" class="hidden text-[11px] text-slate-400 mb-2 leading-5"></p>
            <textarea id="generic-prompt-input" rows="3" class="w-full border rounded-xl p-3 text-sm mb-1 mt-2 outline-none focus:border-blue-400"></textarea>
            <p id="generic-prompt-err" class="hidden text-[11px] text-red-600 font-bold mb-1"></p>
            <div class="flex gap-3 mt-3">
                <button type="button" id="generic-prompt-ok" onclick="executeGenericPrompt()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs">ذخیره</button>
                <button type="button" onclick="cancelGenericPrompt()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2.5 rounded-xl transition-colors hover-target text-xs">انصراف</button>
            </div>
        </div>
    </div>

    <div id="generic-alert-modal" class="modal-overlay" style="z-index:1000006">
        <div class="modal-content w-full max-w-sm p-6 relative text-center" style="margin:0 12px;">
            <div id="generic-alert-icon" class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-inner"></div>
            <h3 id="generic-alert-title" class="text-lg font-black text-slate-800 mb-2"></h3>
            <p id="generic-alert-message" class="text-xs text-slate-600 mb-6 leading-6 text-right" style="white-space:pre-line"></p>
            <button type="button" id="generic-alert-ok" onclick="closeGenericAlert()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-xs">متوجه شدم</button>
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
        <div class="modal-content w-full max-w-lg p-6 relative border border-red-200" style="max-height:94vh; overflow-y:auto; margin:0 12px;">
            <button type="button" onclick="document.getElementById('reset-modal').classList.remove('active')" class="absolute top-4 left-4 text-slate-400 hover:text-red-500 hover-target text-xl"><i class="fas fa-times"></i></button>
            <div class="w-14 h-14 bg-red-500 text-white rounded-full flex items-center justify-center mx-auto mb-3 text-2xl shadow-lg shadow-red-500/30"><i class="fas fa-radiation-alt"></i></div>
            <h3 class="text-lg font-black text-red-600 mb-1 text-center">حذف اطلاعات</h3>
            <p class="text-[11px] text-slate-500 mb-4 text-center">کل سایت به بخش‌هایی تقسیم شده؛ همه را پاک کنید، یا فقط بخش‌هایی را، یا همه به‌جز چند بخش.</p>
            <!-- حالت: همه / فقط انتخاب‌شده‌ها / همه به‌جز انتخاب‌شده‌ها -->
            <div class="grid grid-cols-3 gap-1.5 bg-slate-100 rounded-xl p-1 mb-3 text-[11px] font-bold" id="reset-mode-seg">
                <button type="button" data-mode="all" onclick="setResetMode('all')" class="rounded-lg py-2">همه‌چیز</button>
                <button type="button" data-mode="only" onclick="setResetMode('only')" class="rounded-lg py-2">فقط بخش‌های انتخابی</button>
                <button type="button" data-mode="except" onclick="setResetMode('except')" class="rounded-lg py-2">همه به‌جز انتخابی‌ها</button>
            </div>
            <p id="reset-mode-hint" class="text-[11px] font-bold text-slate-600 mb-2 text-right"></p>
            <div id="reset-sections" class="space-y-1.5 mb-3 text-right"><p class="text-[11px] text-slate-400 text-center py-3">در حال بارگذاری...</p></div>
            <p id="reset-dep-warn" class="hidden text-[11px] leading-5 text-amber-700 bg-amber-50 border border-amber-200 rounded-xl p-2.5 mb-3 text-right"></p>
            <p class="text-[11px] text-slate-500 leading-5 mb-3 text-right"><b class="text-slate-600">همیشه می‌مانند:</b> حساب‌های مدیر کل، تنظیمات سیستم و ربات‌ها، تنظیمات مالی، قالب‌های صورتحساب و تنظیمات گزارش بازدید (انواع، قالب‌ها، فیلدها، بازدیدکننده‌ها و بیمه‌گذاران).</p>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl p-3 text-[11px] leading-6 text-right mb-4"><i class="fas fa-shield-halved ml-1"></i>پیش از حذف، <b>خودکار بکاپ کامل</b> (دیتابیس + همه‌ی فایل‌ها و اکسل‌ها) در پوشه‌ی <b dir="ltr">backup/تاریخ امروز</b> ساخته می‌شود و از «تنظیمات سیستم ← پشتیبان‌گیری» قابل بازگرداندن است.</div>
            <input type="password" id="reset-password-input" placeholder="رمز تایید را وارد کنید" class="w-full border rounded-xl px-3 py-2.5 text-sm text-center mb-4" dir="ltr">
            <div class="flex gap-3">
                <button type="button" id="reset-go-btn" onclick="executeResetAll()" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs">بله، همه‌چیز پاک شود</button>
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
                <div class="mb-4" id="rbox-plate"><label class="text-xs font-bold text-slate-500 block mb-1.5">پلاک خودرو</label><div id="review-plate-mount"></div></div>
                <div class="float-input" id="rbox-phone"><input type="text" id="review-phone" placeholder=" " dir="ltr" autocomplete="off"><label>شماره تماس بیمه‌گذار</label></div>
                <div class="float-input" id="rbox-issuedate"><input type="text" id="review-issuedate" placeholder=" " dir="ltr" autocomplete="off"><label>تاریخ صدور بیمه‌نامه</label></div>
                <div class="float-input" id="rbox-renewal"><input type="text" id="review-renewal" placeholder=" " autocomplete="off"><label>وضعیت تمدید</label></div>
                <div class="float-input" id="rbox-cartype"><input type="text" id="review-cartype" placeholder=" " autocomplete="off"><label>سیستم / تیپ خودرو</label></div>
                <div class="float-input" id="rbox-model"><input type="text" id="review-model" placeholder=" " dir="ltr" autocomplete="off"><label>مدل (سال ساخت)</label></div>
                <div class="float-input" id="rbox-color"><input type="text" id="review-color" placeholder=" " autocomplete="off"><label>رنگ خودرو</label></div>
                <div class="float-input" id="rbox-engine"><input type="text" id="review-engine" placeholder=" " dir="ltr" autocomplete="off"><label>شماره موتور</label></div>
                <div class="float-input" id="rbox-chassis"><input type="text" id="review-chassis" placeholder=" " dir="ltr" autocomplete="off"><label>شماره شاسی</label></div>
                <div class="float-input" id="rbox-vin"><input type="text" id="review-vin" placeholder=" " dir="ltr" autocomplete="off"><label>شماره شاسی (VIN)</label></div>
                <div class="float-input" id="rbox-carvalue"><input type="text" id="review-carvalue" class="money-input" placeholder=" " dir="ltr" inputmode="numeric" autocomplete="off"><label>ارزش خودرو (ریال)</label></div>
                <div class="float-input" id="rbox-premium"><input type="text" id="review-premium" class="money-input" placeholder=" " dir="ltr" inputmode="numeric" autocomplete="off"><label>حق بیمه کل (ریال)</label></div>

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
    <script src="notif-bell.js?v=2"></script>
    <script src="chat-ui.js?v=4"></script>
    <script src="table-count.js?v=3"></script>
    <script>
        // ساعتِ سربرگ: «چهارشنبه ۱۴۰۵/۰۷/۰۹ - ۱۴:۰۵:۲۳» به وقتِ ایران، ثانیه‌به‌ثانیه
        if (window.IrTime) IrTime.mountClock(document.getElementById('hdr-clock'), {render: (el, p, date, time) => {
            const d = el.querySelector('.hc-d'), t = el.querySelector('.hc-t'), dh = `<span class="hc-w">${p.wd} </span>${date}`;
            if (d.innerHTML !== dh) d.innerHTML = dh;
            t.textContent = time;
        }});
    </script>
    <script src="money-input.js?v=1"></script>
    <script src="finance-ui.js?v=4"></script>
    <script src="finance-hub.js?v=3"></script>
    <script src="work-log.js?v=1"></script>
    <script src="chat-archive.js?v=1"></script>
    <?php if ($vrAccess): ?>
    <script src="visit-reports.js?v=10"></script>
    <script src="visit-reports-list.js?v=7"></script>
    <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?><script src="visit-reports-settings.js?v=5"></script><script src="visit-reports-editor.js?v=4"></script><script src="backup-settings.js?v=1"></script><script src="company-manual-request.js?v=3"></script><?php endif; ?>
    <?php endif; ?>
    <script>
        // این ثابت باید همین بالا تعریف شود: loadCompanyInbox() در ادامه‌ی همین اسکریپت
        // بلافاصله پس از لود صدا زده می‌شود، ولی تعریفش پایین‌تر بود و const در ناحیه‌ی
        // مرده‌ی زمانی (TDZ) است - نتیجه‌اش ReferenceError بود و صندوق ورودی مدارک و
        // شمارنده‌اش هرگز موقعِ باز شدنِ پنل بار نمی‌شد (و برای همکار شرکت‌ها که مستقیم
        // روی تبِ شرکت‌ها می‌نشیند، همان اول کار پنل با خطا بالا می‌آمد).
        const COMPANY_API = 'api/company_actions.php';

        let currentRecordsData = [];
        let activeRowId = null;
        const IS_ADMIN = <?php echo ($_SESSION['role'] ?? '') === 'ADMIN' ? 'true' : 'false'; ?>;
        // ---------- دسترسیِ سفارشیِ صفحه به صفحه ----------
        // PERM.custom=false یعنی همان رفتارِ نقش (هیچ چیزی پنهان نمی‌شود). در حالتِ سفارشی، منوی صفحه‌های بدونِ «مشاهده»
        // و دکمه‌های ثبت/ویرایش/حذف/خروجیِ بی‌اجازه پنهان می‌شوند؛ سرور هم همان عملیات را رد می‌کند.
        const PERM = <?php echo json_encode($permBoot, JSON_UNESCAPED_UNICODE); ?>;
        const PERM_TAB_ORDER = ['dashboard', 'tickets', 'records', 'health', 'approved-reviews', 'cases', 'companies-requests', 'companies-inbox', 'companies-manage', 'issue-queue', 'issued-list', 'issue-group',
            'vr-build', 'vr-list', 'vr-settings', 'fin-dashboard', 'fin-installments', 'fin-payments', 'fin-tracking', 'fin-invoices', 'fin-reconcile', 'companies-finance', 'fin-settings',
            'filemanager', 'users', 'staff-users', 'login-logs', 'queue', 'settings', 'my-work', 'staff-work'];
        function permCan(page, op = 'view') { return !PERM.custom || ((PERM.p || {})[page] || []).includes(op); }
        const permFirstTab = () => PERM_TAB_ORDER.find(t => permCan(t) && document.getElementById('tab-' + t)) || null;
        let permTab = 'dashboard';
        window.permCan = permCan;
        const PERM_SKIP = '#main-nav, header, #profile-modal, #generic-confirm-modal, #generic-prompt-modal, #toast-container, .iss-nav, [data-perm-skip], #perm-modal';
        const PERM_DOM_FN = /^(getElementById|querySelector|querySelectorAll|remove|add|toggle|contains|stopPropagation|preventDefault|closeModal|openModal|showToast|focus|click|blur|scrollIntoView|setTimeout|encodeURIComponent|Number|String|parseInt|faDigits|e2p|p2e|if|function|return|switchTab|toggleMenuGroup|toggleMenuSub|window|open|print)$/;
        const PERM_FN_RULES = [
            [/openBundleModal|(^|\s)bundle(Issue|Analyze|Recheck)/, ['issue-group:create']],
            [/openMarkIssued|submitMarkIssued/, ['issue-queue:edit', 'companies-requests:edit']],
            [/openCompanyVisitReport|openCaseVisitReport|buildHealthReport/, ['vr-build:create']],
            [/creqPay|openPayDialog/, ['fin-installments:create', 'fin-payments:create']],
            [/setRowStage/, ['companies-requests:edit', 'issue-queue:edit']],
        ];
        // کدام عملیات لازم است تا این دکمه دیده شود؟ (null = آزاد؛ «op» روی همین صفحه، «page:op» روی صفحه‌ی مشخص)
        function permNeed(el) {
            if (el.dataset.perm) return el.dataset.perm.split('|');
            const oc = el.getAttribute('onclick') || '';
            const fns = []; oc.replace(/([A-Za-z_$][\w$]*)\s*\(/g, (m, f) => { if (!PERM_DOM_FN.test(f)) fns.push(f); return m; });
            const fn = fns.join(' ');
            // کارهایی که مالِ صفحه‌ی دیگری‌اند (مثلاً «صدور گروهی» داخلِ جزئیاتِ درخواست)
            for (const [re, spec] of PERM_FN_RULES) if (re.test(fn)) return spec;
            let txt = (el.textContent || '').replace(/\s+/g, ' ').trim() + ' ' + (el.getAttribute('title') || '');
            const ic = [...el.querySelectorAll('i')].map(i => i.className).join(' ');
            const href = el.tagName === 'A' ? (el.getAttribute('href') || '') : '';
            const hrefAct = (href.match(/[?&]action=([a-z_]+)/) || [])[1] || '';
            if (/حذف|ابطال|باطل کردن/.test(txt) || /(^|\s)(delete|remove|void|purge|deactivate)|Delete|Remove|Void|Purge|Deactivate/.test(fn) || /fa-trash|fa-user-slash/.test(ic) || /delete|void|purge/.test(hrefAct)) return ['delete'];
            if (/خروجی|اکسل|زیپ|\bzip\b|ZIP/i.test(txt) || /export|Export|Excel|Zip|downloadAll/.test(fn) || /fa-file-excel|fa-file-export|fa-file-zipper/.test(ic) || /export|zip|excel/.test(hrefAct)) return ['export'];
            if (/افزودن|جدید|ایمپورت|ورود از اکسل|ثبت دستی|بارگذاری|آپلود|صدورِ انتخاب|صدور و بایگانیِ/.test(txt) || /(^|\s)(create|add|new|import|upload|openAdd|openNew|openCreate|bundleIssue|bundleAnalyze)|Create|Import|Upload|New[A-Z]|Add[A-Z]/.test(fn) || /fa-plus|fa-file-import|fa-upload|fa-file-arrow-up|fa-cloud-arrow-up/.test(ic)) return ['create'];
            if (/ویرایش|ذخیره|تایید|تأیید|(^|\s)رد(\s|$)|رد کردن|ثبت|صدور|تغییر وضعیت|تسویه|بازگردانی|اصلاح/.test(txt) || /(^|\s)(edit|save|update|submit|confirm|approve|reject|mark|change|retry|settle|pay|openEdit|openPay|regenerate|creqPay|restore)|Edit|Save|Update|Confirm|Approve|Reject|Issue/.test(fn) || /fa-pen|fa-floppy-disk|fa-save|fa-stamp/.test(ic)) {
                return /ثبت|صدور/.test(txt) && !/ویرایش|ذخیره/.test(txt) ? ['create', 'edit'] : ['edit'];
            }
            return null;
        }
        const permCache = new WeakMap();
        function permApply() {
            if (!PERM.custom) return;
            // منو و زبانه‌ها
            document.querySelectorAll('[onclick*="switchTab("]').forEach(el => {
                const m = (el.getAttribute('onclick') || '').match(/switchTab\(\s*'([a-z-]+)/);
                if (m && !el.closest('#perm-modal')) el.classList.toggle('perm-hide', !permCan(m[1]));
            });
            document.querySelectorAll('#main-nav .menu-sub').forEach(sub => sub.classList.toggle('perm-hide', !sub.querySelector('.nav-item:not(.perm-hide)')));
            document.querySelectorAll('#main-nav .menu-group').forEach(g => g.classList.toggle('perm-hide', !g.querySelector('.nav-item:not(.perm-hide)')));
            // دکمه‌های عملیات
            document.querySelectorAll('button, a[onclick], a[href*="action="], label[onclick], [role="button"], input[type="submit"], input[type="button"]').forEach(el => {
                if (el.closest(PERM_SKIP) || /switchTab\(/.test(el.getAttribute('onclick') || '')) return;
                let need = permCache.get(el);
                if (need === undefined) { need = permNeed(el); permCache.set(el, need); }
                if (!need) return;
                const tab = el.closest('.tab-content');
                const page = tab ? tab.id.slice(4) : permTab;
                el.classList.toggle('perm-hide', !need.some(op => op.indexOf(':') > 0 ? permCan(op.split(':')[0], op.split(':')[1]) : permCan(page, op)));
            });
        }
        let permQueued = false;
        function permSchedule() { if (permQueued || !PERM.custom) return; permQueued = true; requestAnimationFrame(() => { permQueued = false; permApply(); }); }
        if (PERM.custom) {
            document.head.insertAdjacentHTML('beforeend', '<style>.perm-hide{display:none!important}</style>');
            new MutationObserver(permSchedule).observe(document.body, {childList: true, subtree: true});
            permApply();
        }
        let activeRowNational = '';
        let isBotEnabledState = true;
        let currentQueueData = [];
        
        // متغیرهای ذخیره موقت وضعیت برای منوی راست کلیک
        let ctxSelectedText = '';
        let ctxActiveElement = null;

        const e2p = s => s ? s.toString().replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]) : '-';
        // برای شمارش‌ها، صفر باید «۰» نشان داده شود نه «-» (e2p صفر را falsy می‌گیرد)
        const e2pNum = n => e2p(String(Number(n) || 0));
        // مثل e2p ولی برای مقدارِ خالی رشته‌ی خالی برمی‌گرداند (نه «-») تا با `|| '—'` جفت شود.
        // برای نمایشِ تاریخ‌های شمسی‌ای که سرور با رقم لاتین می‌فرستد.
        // مقداردهیِ برنامه‌ای به فیلدهای مبلغ: سه‌رقم‌سه‌رقم با رقم فارسی (money-input.js)
        const mfmt = v => {
            if (!window.MoneyInput) return v ? String(v) : '';
            const d = MoneyInput.digits(v);
            return d && d !== '0' ? MoneyInput.format(d) : '';
        };
        const faDigits = v => (v === null || v === undefined || v === '') ? '' : String(v).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
        // قالب‌بندی مبلغ: همیشه سه‌رقم‌سه‌رقم جداشده و با ارقام فارسی - برای همه‌ی مبالغ پنل
        // (حق بیمه، ارزش خودرو، سقف تعهد مالی و هر مبلغ دیگر) از همین تابع استفاده می‌شود
        const money = v => {
            if (v === null || v === undefined || v === '') return '-';
            const n = Number(String(v).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[^\d.-]/g, ''));
            if (isNaN(n)) return e2p(v);
            return e2p(n.toLocaleString('en-US'));
        };
        // پلاک را با چیدمان DOM (اسپن‌های جدا داخل فلکس) می‌سازیم، نه صرفاً با CSS bidi-override؛
        // چون bidi-override به‌تنهایی می‌تواند شکل حروف فارسی/عربی را هم به‌هم بریزد.
        // ---- ورودیِ پلاکِ چهارخانه، دقیقاً مثل فرم درخواست شرکت‌ها ----
        // چیدمان چپ‌به‌راست مثل خودِ پلاک: [۲ رقم] [حرف] [۳ رقم] [ایران + ۲ رقم]، و ترتیبِ تایپ هم
        // همین است (با پُر شدنِ هر خانه مکان‌نما خودش به خانه‌ی بعد می‌رود). مقدارِ کامل با همان
        // قالبِ ذخیره‌ی سیستم («68ایران - 711 ب 16») در یک input مخفی با شناسه‌ی قبلی می‌نشیند،
        // پس هر کدی که قبلاً ‎.value‎ آن شناسه را می‌خواند بدون تغییر کار می‌کند.
        const PLATE_LETTERS_FA = ['الف','ب','پ','ت','ث','ج','د','ز','ژ','س','ش','ص','ط','ع','ف','ق','ک','گ','ل','م','ن','و','ه','ی'];
        const plateToEn = v => String(v || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        function parseStoredPlate(v) {
            const m = plateToEn(v).match(/^\s*(\d{2})\s*ایران\s*-?\s*(\d{3})\s*(\S+?)\s*(\d{2})\s*$/);
            return m ? {p1: m[1], p2: m[2], letter: m[3], p4: m[4]} : {p1: '', p2: '', letter: '', p4: ''};
        }
        function plateSplitHtml(id, value) {
            const v = parseStoredPlate(value);
            const fa = x => x ? e2p(x) : '';
            // ظاهرِ پلاکِ واقعی (plate.css): نوارِ آبی، [۲ رقم][حرف][۳ رقم][ایران/۲ رقم]
            return `<div class="psplit ir-pin" dir="ltr" data-target="${id}">
                <span class="ip-flag"></span>
                <input type="text" inputmode="numeric" maxlength="2" data-part="p4" placeholder="۱۲" value="${fa(v.p4)}" class="psplit-part ip-cell" aria-label="دو رقمِ اول">
                <input type="text" maxlength="3" data-part="letter" placeholder="الف" value="${v.letter}" list="plate-letters-dl" class="psplit-part ip-cell ip-letter" aria-label="حرف">
                <input type="text" inputmode="numeric" maxlength="3" data-part="p2" placeholder="۳۴۵" value="${fa(v.p2)}" class="psplit-part ip-cell ip-wide" aria-label="سه رقم">
                <span class="ip-ir"><small>ایران</small><input type="text" inputmode="numeric" maxlength="2" data-part="p1" placeholder="۶۷" value="${fa(v.p1)}" class="psplit-part" aria-label="کدِ ایران"></span>
                <input type="hidden" id="${id}" value="${value ? String(value).replace(/"/g, '') : ''}">
            </div>`;
        }
        function syncPlateSplit(box) {
            const get = k => plateToEn(box.querySelector(`[data-part="${k}"]`).value.trim());
            const p = {p1: get('p1'), p2: get('p2'), letter: box.querySelector('[data-part="letter"]').value.trim().replace(/ي/g, 'ی').replace(/ك/g, 'ک'), p4: get('p4')};
            const hidden = document.getElementById(box.dataset.target);
            if (hidden) hidden.value = (p.p1 || p.p2 || p.letter || p.p4) ? `${p.p1}ایران - ${p.p2} ${p.letter} ${p.p4}` : '';
        }
        function setPlateSplit(id, value) {
            const hidden = document.getElementById(id);
            const box = hidden && hidden.closest('.psplit');
            if (!box) { if (hidden) hidden.value = value || ''; return; }
            const v = parseStoredPlate(value);
            ['p1', 'p2', 'p4'].forEach(k => { box.querySelector(`[data-part="${k}"]`).value = v[k] ? e2p(v[k]) : ''; });
            box.querySelector('[data-part="letter"]').value = v.letter;
            hidden.value = value || '';
        }
        document.addEventListener('input', e => {
            const el = e.target;
            if (!el.classList || !el.classList.contains('psplit-part')) return;
            const box = el.closest('.psplit');
            const part = el.dataset.part;
            if (part !== 'letter') {
                const digits = plateToEn(el.value).replace(/\D/g, '');
                el.value = digits.replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
            }
            syncPlateSplit(box);
            // خانه که پُر شد، برو خانه‌ی بعد: ۲ رقم -> حرف -> ۳ رقم -> ایران
            const order = ['p4', 'letter', 'p2', 'p1'];
            const full = part === 'letter' ? PLATE_LETTERS_FA.includes(el.value.trim()) : el.value.length >= Number(el.maxLength);
            const next = order[order.indexOf(part) + 1];
            if (full && next) box.querySelector(`[data-part="${next}"]`).focus();
        });

        // پلاک با ظاهرِ پلاکِ واقعی (plate.css) در همه‌ی جدول‌ها و کارت‌های پنل
        function irPlateHtml(p4, letter, p2, p1) {
            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            return `<span class="ir-plate"><span class="flag"></span><span>${e2p(String(p4 || ''))}</span><span>${esc(letter)}</span><span>${e2p(String(p2 || ''))}</span><span class="ir"><small>ایران</small>${e2p(String(p1 || ''))}</span></span>`;
        }
        function formatPlateHtml(plate) {
            if (!plate) return '-';
            const m = String(plate).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).match(/^\s*(\d{2})\s*ایران\s*-?\s*(\d{3})\s*(\S+?)\s*(\d{2})\s*$/);
            if (!m) return plate;
            const [, region, suffix, letter, prefix] = m;
            return irPlateHtml(prefix, letter, suffix, region);
        }
        window.formatPlateHtml = formatPlateHtml;
        // مسیرهای ذخیره‌شده ممکن است شامل فاصله، پرانتز یا حروف فارسی باشند (نام پوشه‌های بایگانی)؛
        // برای اینکه مرورگر/سرور همیشه درست بارشان کند، هر بخش از مسیر را جداگانه encode می‌کنیم
        const encodeFilePath = p => (p || '').split('/').map(encodeURIComponent).join('/');

        // «اکنون» به وقتِ ایران و بر اساسِ ساعتِ سرور، به شکلِ رشته‌ی MySQL (برای پیام‌هایی که هنوز از سرور برنگشته‌اند)
        const irNowStr = () => new Date((window.IrTime ? IrTime.now() : Date.now()) + 12600000).toISOString().slice(0, 19).replace('T', ' ');

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

        // ---- دیالوگ‌های عمومیِ سایت: جایگزینِ confirm / prompt / alert مرورگر ----
        // showConfirm(title, message, onConfirm, opts)  opts: {ok, danger, onCancel}
        // showPrompt(title, value, onSubmit, opts)       opts: {ok, placeholder, hint, required, danger, onCancel}
        // showAlert(title, message, type)                 type: success | warning | error | info
        // نسخه‌های Promise برای کدِ async: uiConfirm(...) => true/false ، uiPrompt(...) => متن یا null
        let _confirmCallback = null, _confirmCancel = null;
        function showConfirm(title, message, onConfirm, opts = {}) {
            document.getElementById('generic-confirm-title').innerText = title;
            document.getElementById('generic-confirm-message').innerText = message;
            const ok = document.getElementById('generic-confirm-ok');
            ok.innerText = opts.ok || 'بله، مطمئنم';
            ok.className = `flex-1 ${opts.danger ? 'bg-red-500 hover:bg-red-600' : 'bg-emerald-500 hover:bg-emerald-600'} text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs`;
            document.getElementById('generic-confirm-icon').className = `w-16 h-16 ${opts.danger ? 'bg-red-100 text-red-500' : 'bg-amber-100 text-amber-500'} rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-inner`;
            _confirmCallback = onConfirm; _confirmCancel = opts.onCancel || null;
            document.getElementById('generic-confirm-modal').classList.add('active');
            setTimeout(() => ok.focus(), 50);
        }
        function executeGenericConfirm() {
            document.getElementById('generic-confirm-modal').classList.remove('active');
            const cb = _confirmCallback; _confirmCallback = _confirmCancel = null;
            if (typeof cb === 'function') cb();
        }
        function cancelGenericConfirm() {
            document.getElementById('generic-confirm-modal').classList.remove('active');
            const cb = _confirmCancel; _confirmCallback = _confirmCancel = null;
            if (typeof cb === 'function') cb();
        }

        let _promptCallback = null, _promptCancel = null, _promptRequired = false;
        function showPrompt(title, currentValue, onSubmit, opts = {}) {
            document.getElementById('generic-prompt-title').innerText = title;
            const hint = document.getElementById('generic-prompt-hint');
            hint.innerText = opts.hint || ''; hint.classList.toggle('hidden', !opts.hint);
            const input = document.getElementById('generic-prompt-input');
            input.value = currentValue || '';
            input.placeholder = opts.placeholder || '';
            document.getElementById('generic-prompt-err').classList.add('hidden');
            const ok = document.getElementById('generic-prompt-ok');
            ok.innerText = opts.ok || 'ذخیره';
            ok.className = `flex-1 ${opts.danger ? 'bg-red-500 hover:bg-red-600' : 'bg-blue-600 hover:bg-blue-700'} text-white font-bold py-2.5 rounded-xl shadow-md transition-colors hover-target text-xs`;
            _promptCallback = onSubmit; _promptCancel = opts.onCancel || null; _promptRequired = !!opts.required;
            document.getElementById('generic-prompt-modal').classList.add('active');
            setTimeout(() => input.focus(), 50);
        }
        function executeGenericPrompt() {
            const value = document.getElementById('generic-prompt-input').value;
            if (_promptRequired && !value.trim()) {
                const err = document.getElementById('generic-prompt-err');
                err.innerText = 'این قسمت را پر کنید.'; err.classList.remove('hidden');
                document.getElementById('generic-prompt-input').focus();
                return;
            }
            document.getElementById('generic-prompt-modal').classList.remove('active');
            const cb = _promptCallback; _promptCallback = _promptCancel = null;
            if (typeof cb === 'function') cb(value);
        }
        function cancelGenericPrompt() {
            document.getElementById('generic-prompt-modal').classList.remove('active');
            const cb = _promptCancel; _promptCallback = _promptCancel = null;
            if (typeof cb === 'function') cb();
        }
        // Ctrl+Enter = تایید در کادرِ متن
        document.getElementById('generic-prompt-input').addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) executeGenericPrompt(); });

        const _alertIcons = { success: ['bg-emerald-100 text-emerald-600', 'fa-check'], warning: ['bg-amber-100 text-amber-500', 'fa-exclamation'],
                              error: ['bg-red-100 text-red-500', 'fa-xmark'], info: ['bg-blue-100 text-blue-600', 'fa-info'] };
        let _alertCallback = null;
        function showAlert(title, message, type = 'info', onClose = null) {
            const ic = _alertIcons[type] || _alertIcons.info;
            document.getElementById('generic-alert-icon').className = `w-16 h-16 ${ic[0]} rounded-full flex items-center justify-center mx-auto mb-4 text-2xl shadow-inner`;
            document.getElementById('generic-alert-icon').innerHTML = `<i class="fas ${ic[1]}"></i>`;
            document.getElementById('generic-alert-title').innerText = title;
            const msg = document.getElementById('generic-alert-message');
            msg.innerText = message || ''; msg.classList.toggle('hidden', !message);
            _alertCallback = onClose;
            document.getElementById('generic-alert-modal').classList.add('active');
            setTimeout(() => document.getElementById('generic-alert-ok').focus(), 50);
        }
        function closeGenericAlert() {
            document.getElementById('generic-alert-modal').classList.remove('active');
            const cb = _alertCallback; _alertCallback = null;
            if (typeof cb === 'function') cb();
        }
        const uiConfirm = (title, message, opts = {}) => new Promise(res => showConfirm(title, message, () => res(true), {...opts, onCancel: () => res(false)}));
        const uiPrompt = (title, value = '', opts = {}) => new Promise(res => showPrompt(title, value, v => res(v), {...opts, onCancel: () => res(null)}));
        // در دسترسِ اسکریپت‌های جدا (گزارش بازدید، پیام‌رسان و ...) تا هیچ‌جا پنجره‌ی confirm/prompt مرورگر باز نشود
        window.uiConfirm = uiConfirm; window.uiPrompt = uiPrompt; window.showAlert = showAlert;
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
                if (e.target !== this) return;
                if (this.id === 'generic-confirm-modal') return cancelGenericConfirm();
                if (this.id === 'generic-prompt-modal') return cancelGenericPrompt();
                if (this.id === 'generic-alert-modal') return closeGenericAlert();
                if (e.target === this) this.classList.remove('active');
            });
        });

        // ---- اعلان‌های داشبورد: انقضای نزدیکِ ردیف‌های شرکتی + درخواست‌های کارکنانِ صادرنشده ----
        async function loadDashAlerts() {
            const cBox = document.getElementById('da-company'), pBox = document.getElementById('da-personnel');
            if (!cBox) return;
            let d;
            try { d = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'dashboard_alerts', days: 15})})).json(); }
            catch (e) { cBox.innerHTML = pBox.innerHTML = '<p class="da-empty text-red-400">خطا در ارتباط با سرور.</p>'; return; }
            if (!d.ok) { cBox.innerHTML = pBox.innerHTML = `<p class="da-empty text-red-400">${d.error || 'خطا'}</p>`; return; }
            document.getElementById('da-days').textContent = e2pNum(d.days);
            document.getElementById('da-c-count').textContent = e2pNum(d.company.length);
            const urg = n => n < 0 ? ['#dc2626', 'bg-rose-600 text-white', `${e2pNum(-n)} روز`, 'گذشته'] : n === 0 ? ['#ef4444', 'bg-rose-500 text-white', 'امروز', 'منقضی می‌شود']
                : n <= 3 ? ['#f97316', 'bg-orange-100 text-orange-700', `${e2pNum(n)} روز`, 'مانده'] : n <= 7 ? ['#f59e0b', 'bg-amber-100 text-amber-700', `${e2pNum(n)} روز`, 'مانده'] : ['#10b981', 'bg-emerald-50 text-emerald-700', `${e2pNum(n)} روز`, 'مانده'];
            cBox.innerHTML = d.company.length ? d.company.map(r => {
                const u = urg(r.days_left);
                const ident = (r.plate_p1 || r.plate_p2 || r.plate_p4) ? fmtPlateHtml(r) : `<span class="text-[11px] font-bold" dir="ltr">${r.chassis_no || 'بدون شناسه'}</span>`;
                return `<div class="da-row" style="--u:${u[0]}" onclick="dashOpenCompanyRow(${r.request_id})" title="بازکردنِ درخواست #${r.request_id}">
                    <div class="da-main"><div class="da-t">${ident}<span>${r.company_name || ''}</span></div>
                        <div class="da-s"><span class="da-chip ${r.insurance_type === 'BODY' ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700'}">${r.insurance_type_fa}</span>
                            <span class="da-chip ${CREQ_STAGE_COLOR[r.status] || 'bg-slate-100 text-slate-600'}">${r.status_fa}</span>
                            <span>درخواست #${e2pNum(r.request_id)}</span>${r.car_name ? `<span>· ${r.car_name}</span>` : ''}<span>· انقضا <b dir="ltr">${e2p(String(r.expiry_jalali || '').replace(/\./g, '/'))}</b></span></div></div>
                    <div class="da-pill ${u[1]}">${u[2]}<small>${u[3]}</small></div></div>`;
            }).join('') : '<p class="da-empty"><i class="fas fa-circle-check text-emerald-400 text-2xl block mb-2"></i>بیمه‌نامه‌ی شرکتیِ صادرنشده‌ای در ۱۵ روزِ آینده منقضی نمی‌شود.</p>';
            const pc = document.getElementById('da-personnel-card');
            if (!d.show_personnel) { pc.classList.add('hidden'); document.getElementById('dash-alerts').classList.remove('lg:grid-cols-2'); return; }
            document.getElementById('da-p-count').textContent = e2pNum(d.personnel.length);
            const wait = n => n >= 14 ? ['#dc2626', 'bg-rose-100 text-rose-700'] : n >= 7 ? ['#f59e0b', 'bg-amber-100 text-amber-700'] : ['#6366f1', 'bg-indigo-50 text-indigo-700'];
            pBox.innerHTML = d.personnel.length ? d.personnel.map(r => {
                const w = wait(r.waiting_days);
                return `<div class="da-row" style="--u:${w[0]}" onclick="dashOpenCase(${r.id})" title="بازکردنِ صفحه‌ی صدور">
                    <div class="da-main"><div class="da-t"><span>${r.name || '—'}</span>${r.plate ? formatPlateHtml(r.plate) : ''}</div>
                        <div class="da-s"><span class="da-chip ${r.insurance_type === 'BODY' ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700'}">${r.insurance_type_fa}</span>
                            <span class="da-chip bg-slate-100 text-slate-600">${CASE_STATUS_FA[r.status] || r.status}</span>
                            ${r.employer ? `<span>${r.employer}</span>` : ''}${r.unique_code ? `<span dir="ltr">· ${r.unique_code}</span>` : ''}<span>· ثبت <b dir="ltr">${e2p(String(r.created_jalali || '').replace(/\./g, '/'))}</b></span></div></div>
                    <div class="da-pill ${w[1]}">${r.waiting_days ? e2pNum(r.waiting_days) + ' روز' : 'امروز'}<small>در انتظار</small></div></div>`;
            }).join('') : '<p class="da-empty"><i class="fas fa-circle-check text-emerald-400 text-2xl block mb-2"></i>همه‌ی درخواست‌های کارکنان صادر شده‌اند.</p>';
        }
        function dashOpenCompanyRow(requestId) { openCompanyRequestDetail(requestId); }
        function dashOpenCase(caseId) {
            // مودالِ پرونده داخلِ تبِ دیگری است؛ برای اینکه از داشبورد هم دیده شود به body منتقل می‌شود
            const m = document.getElementById('case-detail-modal');
            if (m && m.closest('.tab-content')) document.body.appendChild(m);
            openCase(caseId);
        }

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

        // ======================= نمودارهای داشبورد =======================
        // دو نمودارِ SVG بدونِ کتابخانه: ستونیِ انباشته (تعداد صادره) و ناحیه‌ایِ انباشته (مبلغ فروش).
        // با هر تغییرِ فیلتر، مقدارها از حالتِ قبلی به حالتِ جدید «جابه‌جا» می‌شوند (انیمیشن).
        // محورِ زمان راست‌به‌چپ است: قدیمی‌ترین دسته سمتِ راست.
        const DC = { period: '6M', source: 'ALL', data: null, shown: null, anim: 0, reqId: 0, inited: false, totC: 0, totA: 0 };
        const DC_MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        const DC_COL = { p: ['#3b82f6', '#6366f1'], c: ['#10b981', '#14b8a6'] };

        function setDcPeriod(v) {
            DC.period = v;
            document.querySelectorAll('#dc-period button').forEach(b => b.classList.toggle('active', b.dataset.v === v));
            document.getElementById('dc-jyear-box').classList.toggle('show', v === 'JYEAR');
            document.getElementById('dc-range-box').classList.toggle('show', v === 'RANGE');
            if (v !== 'RANGE') loadDashCharts();
        }
        function setDcSource(v) {
            DC.source = v;
            document.querySelectorAll('#dc-source button').forEach(b => b.classList.toggle('active', b.dataset.v === v));
            loadDashCharts();
        }
        function dcFillYearSelects(years, cur) {
            if (DC.inited) return;
            DC.inited = true;
            const yOpts = years.map(y => `<option value="${y}">${e2p(y)}</option>`).join('');
            const mOpts = DC_MONTHS.map((m, i) => `<option value="${i + 1}">${m}</option>`).join('');
            ['dc-year', 'dc-fy', 'dc-ty'].forEach(id => document.getElementById(id).innerHTML = yOpts);
            ['dc-fm', 'dc-tm'].forEach(id => document.getElementById(id).innerHTML = mOpts);
            document.getElementById('dc-year').value = cur.y;
            document.getElementById('dc-fy').value = cur.y; document.getElementById('dc-fm').value = 1;
            document.getElementById('dc-ty').value = cur.y; document.getElementById('dc-tm').value = cur.m;
        }
        async function loadDashCharts() {
            if (!document.getElementById('dc-count-chart')) return;
            const body = {period: DC.period, source: DC.source};
            if (DC.inited) {
                body.year = +document.getElementById('dc-year').value;
                body.from_y = +document.getElementById('dc-fy').value; body.from_m = +document.getElementById('dc-fm').value;
                body.to_y = +document.getElementById('dc-ty').value; body.to_m = +document.getElementById('dc-tm').value;
            }
            const my = ++DC.reqId;
            try {
                const res = await fetch('api/dashboard_charts.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
                const data = await res.json();
                if (my !== DC.reqId) return;          // پاسخِ یک درخواستِ قدیمی‌تر
                if (!data.ok) { showToast(data.error || 'خطا در دریافت نمودار', 'error'); return; }
                dcFillYearSelects(data.years, data.current);
                document.getElementById('dc-range').textContent = data.range_label;
                dcAnimateTo(data);
            } catch (e) {}
        }

        // اعدادِ کوتاه برای محور (۱٫۲ میلیارد، ۳۵۰ میلیون، ...)
        function dcShort(n) {
            n = Number(n) || 0;
            const f = (v, u) => e2p((Math.round(v * 10) / 10).toString().replace('.', '٫')) + ' ' + u;
            if (n >= 1e12) return f(n / 1e12, 'هزار میلیارد');
            if (n >= 1e9) return f(n / 1e9, 'میلیارد');
            if (n >= 1e6) return f(n / 1e6, 'میلیون');
            if (n >= 1e3) return f(n / 1e3, 'هزار');
            return e2p(Math.round(n));
        }
        function dcNiceMax(v) {
            if (v <= 0) return 4;
            const e = Math.pow(10, Math.floor(Math.log10(v))), m = v / e;
            const nice = m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10;
            return Math.max(4, nice * e);
        }
        function dcDelta(elId, now, prev) {
            const el = document.getElementById(elId);
            if (!prev && !now) { el.className = 'dc-delta flat'; el.textContent = 'بدون تغییر'; return; }
            if (!prev) { el.className = 'dc-delta up'; el.textContent = '▲ دوره‌ی قبل صفر بود'; return; }
            const pct = Math.round((now - prev) / prev * 100);
            el.className = 'dc-delta ' + (pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat');
            el.textContent = (pct > 0 ? '▲ ' : pct < 0 ? '▼ ' : '') + e2p(Math.abs(pct)) + '٪ نسبت به دوره‌ی قبل';
        }

        // انیمیشن: از مقدارهای نمایش‌داده‌شده‌ی فعلی به مقدارهای جدید (ease-out)
        function dcAnimateTo(data) {
            const n = data.buckets.length;
            const pick = (arr, i, k) => (arr && arr[i] ? Number(arr[i][k]) || 0 : 0);
            const from = DC.shown && DC.shown.length ? DC.shown : null;
            const target = data.buckets.map(b => ({p_count: +b.p_count, c_count: +b.c_count, p_amount: +b.p_amount, c_amount: +b.c_amount}));
            // اگر تعدادِ دسته‌ها عوض شد، مقدارِ قبلی را روی دسته‌های جدید نگاشت می‌کنیم تا حرکت نرم بماند
            const start = target.map((_, i) => {
                if (!from) return {p_count: 0, c_count: 0, p_amount: 0, c_amount: 0};
                const j = Math.min(from.length - 1, Math.round(i * (from.length - 1) / Math.max(1, n - 1)));
                return {p_count: pick(from, j, 'p_count'), c_count: pick(from, j, 'c_count'), p_amount: pick(from, j, 'p_amount'), c_amount: pick(from, j, 'c_amount')};
            });
            const tot = data.totals, prevTot = data.prev;
            const showP = data.source !== 'COMPANY', showC = data.source !== 'PERSONNEL';
            const cNow = (showP ? +tot.p_count : 0) + (showC ? +tot.c_count : 0);
            const aNow = (showP ? +tot.p_amount : 0) + (showC ? +tot.c_amount : 0);
            const cPrev = (showP ? +prevTot.p_count : 0) + (showC ? +prevTot.c_count : 0);
            const aPrev = (showP ? +prevTot.p_amount : 0) + (showC ? +prevTot.c_amount : 0);
            dcDelta('dc-count-delta', cNow, cPrev);
            dcDelta('dc-amount-delta', aNow, aPrev);
            const leg = (showP ? `<span><i class="dc-sw p"></i>پرسنلی</span>` : '') + (showC ? `<span><i class="dc-sw c"></i>شرکتی</span>` : '');
            document.getElementById('dc-count-legend').innerHTML = leg;
            document.getElementById('dc-amount-legend').innerHTML = leg;

            const cFrom = DC.totC, aFrom = DC.totA;
            DC.data = data; DC.totC = cNow; DC.totA = aNow;
            cancelAnimationFrame(DC.anim);
            const t0 = performance.now(), dur = 750;
            const step = now => {
                const k = Math.min(1, Math.max(0, (now - t0) / dur)), e = 1 - Math.pow(1 - k, 3);
                const cur = target.map((t, i) => {
                    const s = start[i], o = {};
                    for (const key in t) o[key] = s[key] + (t[key] - s[key]) * e;
                    return o;
                });
                DC.shown = cur;
                document.getElementById('dc-count-total').textContent = e2p(Math.round(cFrom + (cNow - cFrom) * e).toLocaleString('en-US'));
                document.getElementById('dc-amount-total').textContent = money(Math.round(aFrom + (aNow - aFrom) * e));
                dcRenderBars(cur, data, showP, showC);
                dcRenderArea(cur, data, showP, showC);
                if (k < 1) DC.anim = requestAnimationFrame(step);
            };
            DC.anim = requestAnimationFrame(step);
        }

        // ابعادِ واقعیِ کادر (پیکسل) - تا متن‌ها کش نیایند و در موبایل ریز نشوند
        function dcFrame(n, box, L = 62) {
            const W = Math.max(280, Math.round(box.clientWidth || 560)), H = 230, R = 8, T = 12, B = 34;
            const pw = W - L - R, ph = H - T - B, slot = pw / Math.max(1, n);
            const xAt = i => W - R - slot * (i + 0.5);            // مرکزِ دسته‌ی i (از راست)
            return {W, H, L, R, T, B, pw, ph, slot, xAt};
        }
        function dcAxis(f, max, fmt, data) {
            let g = '';
            for (let i = 0; i <= 4; i++) {
                const v = max * i / 4, y = f.T + f.ph - f.ph * i / 4;
                g += `<line x1="${f.L}" x2="${f.W - f.R}" y1="${y}" y2="${y}" stroke="${i ? '#eef2f7' : '#e2e8f0'}" ${i ? 'stroke-dasharray="3 4"' : ''}/>`;
                g += `<text x="${f.L - 6}" y="${y + 3}" text-anchor="end" font-size="9" fill="#94a3b8">${fmt(v)}</text>`;
            }
            const n = data.buckets.length, every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(f.pw / (data.bucket === 'month' ? 58 : 34)))));
            let lastSub = null;
            data.buckets.forEach((b, i) => {
                if (i % every !== 0 && i !== n - 1) return;
                const x = f.xAt(i);
                g += `<text x="${x}" y="${f.H - f.B + 14}" text-anchor="middle" font-size="9.5" font-weight="700" fill="#64748b">${b.label}</text>`;
                if (b.sub && (b.sub !== lastSub || data.bucket === 'month'))
                    g += `<text x="${x}" y="${f.H - f.B + 26}" text-anchor="middle" font-size="8.5" fill="#94a3b8">${b.sub}</text>`;
                lastSub = b.sub;
            });
            return g;
        }
        function dcGrad(id, c) { return `<linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${c[1]}"/><stop offset="1" stop-color="${c[0]}"/></linearGradient>`; }

        function dcRenderBars(cur, data, showP, showC) {
            const box = document.getElementById('dc-count-chart');
            const f = dcFrame(cur.length, box, 34);
            const tgtMax = Math.max(0, ...data.buckets.map(b => (showP ? +b.p_count : 0) + (showC ? +b.c_count : 0)));
            const max = dcNiceMax(tgtMax);
            const bw = Math.max(3, Math.min(30, f.slot * 0.6));
            let bars = '';
            cur.forEach((v, i) => {
                const pv = showP ? v.p_count : 0, cv = showC ? v.c_count : 0;
                const x = f.xAt(i) - bw / 2, base = f.T + f.ph;
                const hp = f.ph * Math.min(1, pv / max), hc = f.ph * Math.min(1, cv / max);
                if (hp > 0.2) bars += `<rect x="${x}" y="${base - hp}" width="${bw}" height="${hp}" rx="${hc > 0.2 ? 0 : Math.min(6, bw / 2)}" fill="url(#dcgP)"/>`;
                if (hc > 0.2) bars += `<rect x="${x}" y="${base - hp - hc}" width="${bw}" height="${hc}" rx="${Math.min(6, bw / 2)}" fill="url(#dcgC)"/>`;
                if (hp > 0.2 && hc > 0.2) bars += `<rect x="${x}" y="${base - hp - 1}" width="${bw}" height="2" fill="#fff" opacity=".7"/>`;
            });
            const empty = tgtMax === 0 ? '<div class="dc-empty">در این بازه صدوری ثبت نشده</div>' : '';
            box.innerHTML = `<svg viewBox="0 0 ${f.W} ${f.H}"><defs>${dcGrad('dcgP', DC_COL.p)}${dcGrad('dcgC', DC_COL.c)}</defs>
                ${dcAxis(f, max, v => e2p(Math.round(v)), data)}${bars}${dcHoverZones(f, cur.length)}</svg><div class="dc-tip"></div>${empty}`;
            dcBindHover(box, f, data, 'count', showP, showC);
        }

        function dcRenderArea(cur, data, showP, showC) {
            const box = document.getElementById('dc-amount-chart');
            const f = dcFrame(cur.length, box, 70);
            const tgtMax = Math.max(0, ...data.buckets.map(b => (showP ? +b.p_amount : 0) + (showC ? +b.c_amount : 0)));
            const max = dcNiceMax(tgtMax);
            const base = f.T + f.ph, yOf = v => base - f.ph * Math.min(1, v / max);
            const lower = cur.map((v, i) => [f.xAt(i), yOf(showP ? v.p_amount : 0)]);
            const upper = cur.map((v, i) => [f.xAt(i), yOf((showP ? v.p_amount : 0) + (showC ? v.c_amount : 0))]);
            // منحنیِ نرم با نقاطِ کنترلِ افقی (از بالا و پایینِ داده بیرون نمی‌زند)
            const curve = (pts, lead) => pts.map((p, i) => i === 0 ? `${lead}${p[0]},${p[1]}` : `C${(pts[i - 1][0] + p[0]) / 2},${pts[i - 1][1]} ${(pts[i - 1][0] + p[0]) / 2},${p[1]} ${p[0]},${p[1]}`).join(' ');
            const first = lower.length ? lower[0][0] : f.W - f.R, last = lower.length ? lower[lower.length - 1][0] : f.L;
            let g = '';
            if (cur.length === 1) {   // فقط یک دسته: یک ستونِ پهن
                const w = 40, x = f.xAt(0) - w / 2;
                if (showP) g += `<rect x="${x}" y="${lower[0][1]}" width="${w}" height="${base - lower[0][1]}" rx="6" fill="url(#dcaP)"/>`;
                if (showC) g += `<rect x="${x}" y="${upper[0][1]}" width="${w}" height="${lower[0][1] - upper[0][1]}" rx="6" fill="url(#dcaC)"/>`;
            } else {
                if (showP) g += `<path d="${curve(lower, 'M')} L${last},${base} L${first},${base} Z" fill="url(#dcaP)"/>`;
                if (showC) g += `<path d="${curve(upper, 'M')} ${curve(lower.slice().reverse(), 'L')} Z" fill="url(#dcaC)"/>`;
                if (showC) g += `<path d="${curve(upper, 'M')}" fill="none" stroke="${DC_COL.c[0]}" stroke-width="2.2" stroke-linecap="round"/>`;
                if (showP) g += `<path d="${curve(lower, 'M')}" fill="none" stroke="${DC_COL.p[0]}" stroke-width="2.2" stroke-linecap="round"/>`;
            }
            const area = (id, c) => `<linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${c}" stop-opacity=".45"/><stop offset="1" stop-color="${c}" stop-opacity=".04"/></linearGradient>`;
            const empty = tgtMax === 0 ? '<div class="dc-empty">در این بازه فروشی ثبت نشده</div>' : '';
            box.innerHTML = `<svg viewBox="0 0 ${f.W} ${f.H}"><defs>${area('dcaP', DC_COL.p[0])}${area('dcaC', DC_COL.c[0])}</defs>
                ${dcAxis(f, max, dcShort, data)}${g}<line class="dc-cursor" x1="0" x2="0" y1="${f.T}" y2="${base}" stroke="#94a3b8" stroke-dasharray="3 3" opacity="0"/>${dcHoverZones(f, cur.length)}</svg><div class="dc-tip"></div>${empty}`;
            dcBindHover(box, f, data, 'amount', showP, showC);
        }

        let dcResizeT = 0;
        window.addEventListener('resize', () => {
            clearTimeout(dcResizeT);
            dcResizeT = setTimeout(() => {
                if (!DC.data || !DC.shown) return;
                const showP = DC.data.source !== 'COMPANY', showC = DC.data.source !== 'PERSONNEL';
                dcRenderBars(DC.shown, DC.data, showP, showC); dcRenderArea(DC.shown, DC.data, showP, showC);
            }, 150);
        });

        function dcHoverZones(f, n) {
            let z = '';
            for (let i = 0; i < n; i++) z += `<rect class="dc-hz" data-i="${i}" x="${f.xAt(i) - f.slot / 2}" y="${f.T}" width="${f.slot}" height="${f.ph}" fill="transparent"/>`;
            return z;
        }
        function dcBindHover(box, f, data, kind, showP, showC) {
            const tip = box.querySelector('.dc-tip'), svg = box.querySelector('svg'), cursor = box.querySelector('.dc-cursor');
            box.querySelectorAll('.dc-hz').forEach(z => {
                z.addEventListener('mouseenter', () => {
                    const i = +z.dataset.i, b = data.buckets[i];
                    const fmt = kind === 'count' ? (v => e2p(v) + ' فقره') : (v => money(v) + ' ریال');
                    const pv = kind === 'count' ? b.p_count : b.p_amount, cv = kind === 'count' ? b.c_count : b.c_amount;
                    tip.innerHTML = `<b>${b.full || b.label}</b>` + (showP ? `<br><i class="dc-sw p"></i> پرسنلی: ${fmt(pv)}` : '') + (showC ? `<br><i class="dc-sw c"></i> شرکتی: ${fmt(cv)}` : '')
                        + (showP && showC ? `<br>جمع: ${fmt(Number(pv) + Number(cv))}` : '');
                    const r = svg.getBoundingClientRect(), sx = r.width / f.W;
                    tip.style.left = Math.min(r.width - 80, Math.max(80, f.xAt(i) * sx)) + 'px';
                    tip.style.top = (f.T * (r.height / f.H) + 4) + 'px';
                    tip.classList.add('on');
                    if (cursor) { cursor.setAttribute('x1', f.xAt(i)); cursor.setAttribute('x2', f.xAt(i)); cursor.setAttribute('opacity', '1'); }
                });
                z.addEventListener('mouseleave', () => { tip.classList.remove('on'); if (cursor) cursor.setAttribute('opacity', '0'); });
            });
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

        <?php if (!$isLiaison && !$isParsian): ?>
        // وضعیت ربات و آمار پرونده‌های کارکنان: «همکار شرکت‌ها» و «کاربر پارسیان» به این بخش دسترسی ندارند
        setInterval(checkBotStatus, 5000);
        checkBotStatus();
        if (permCan('dashboard')) { loadStats(); loadDashCharts(); loadDashAlerts(); }
        <?php endif; ?>
        <?php if ($canSeeCompanies): ?>
        if (permCan('companies-inbox')) { loadCompanyInbox(); setInterval(loadCompanyInbox, 30000); }
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
        <?php if ($isParsian): ?>
        switchTab('vr-build');
        <?php endif; ?>
        // دسترسیِ سفارشی بدونِ «داشبورد»: اولین صفحه‌ای که اجازه دارد
        if (PERM.custom && !permCan('dashboard')) setTimeout(() => {   // بعد از اجرای کاملِ اسکریپت (ثابت‌های پایین‌تر آماده باشند)
            const ft = permFirstTab();
            if (ft) switchTab(ft);
            else { document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
                   document.getElementById('tab-dashboard').insertAdjacentHTML('beforebegin', '<div class="max-w-xl mx-auto mt-16 text-center bg-white border border-slate-200 rounded-2xl p-8"><i class="fas fa-lock text-3xl text-slate-300 mb-3"></i><p class="font-black text-slate-700">هنوز به هیچ صفحه‌ای دسترسی ندارید.</p><p class="text-xs text-slate-500 mt-1">از مدیر کل بخواهید در «کاربران» دسترسی‌تان را تعیین کند.</p></div>'); }
        }, 0);

        // ======================= کارتابل پرونده‌ها (معرفی‌نامه‌های کارکنان) =======================
        // بازه‌ی زمانی روی کارت‌ها و جدول با هم اثر دارد: جدول بر اساسِ تاریخِ صدورِ معرفی‌نامه (اگر نبود، تاریخِ ثبت)
        let recPeriod = {p: 'all', from: '', to: ''};
        let currentRecordsFiltered = [];
        const recEn = s => String(s || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        // «امروز» و بازه‌ها به وقتِ ایران و بر اساسِ ساعتِ سرور (iran-time.js)، نه ساعتِ سیستمِ کاربر
        const recNow = () => window.IrTime ? IrTime.now() : Date.now();
        const recJ = d => { if (window.IrTime) return IrTime.jdate(+d); try { return recEn(new Intl.DateTimeFormat('fa-IR-u-ca-persian', {year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Tehran'}).format(d)).replace(/[^\d/]/g, ''); } catch (e) { return ''; } };
        const recNorm = s => { const m = /^(\d{4})\D+(\d{1,2})\D+(\d{1,2})$/.exec(recEn(s).trim()); return m ? `${m[1]}/${m[2].padStart(2, '0')}/${m[3].padStart(2, '0')}` : ''; };
        function recPeriodRange(p) {
            const today = recJ(recNow());
            if (p === 'all') return {from: '', to: ''};
            if (p === 'month') return {from: today.slice(0, 8) + '01', to: today};
            const d = new Date(recNow()); d.setUTCMonth(d.getUTCMonth() - Number(p)); d.setUTCDate(d.getUTCDate() + 1);
            return {from: recJ(d), to: today};
        }
        document.querySelectorAll('#rec-period [data-p]').forEach(b => b.onclick = () => {
            document.querySelectorAll('#rec-period [data-p]').forEach(x => x.classList.toggle('on', x === b));
            const p = b.dataset.p;
            document.getElementById('rec-custom').classList.toggle('hidden', p !== 'custom');
            if (p === 'custom') { document.getElementById('rec-from').focus(); return; }
            recPeriod = {p, ...recPeriodRange(p)};
            refreshRecordsPeriod();
        });
        function applyRecordsPeriod() {
            const from = recNorm(document.getElementById('rec-from').value), to = recNorm(document.getElementById('rec-to').value);
            if (!from && !to) { showToast('حداقل یکی از تاریخ‌ها را به شکل ۱۴۰۵/۰۷/۰۱ وارد کنید.', 'warning'); return; }
            recPeriod = {p: 'custom', from, to};
            refreshRecordsPeriod();
        }
        ['rec-from', 'rec-to'].forEach(id => document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') applyRecordsPeriod(); }));
        function refreshRecordsPeriod() {
            document.getElementById('rec-range-label').textContent = recPeriod.from || recPeriod.to
                ? `از ${e2p(recPeriod.from || 'ابتدا')} تا ${e2p(recPeriod.to || 'امروز')}` : 'همه‌ی زمان‌ها';
            loadRecordsStats();
            filterRecordsTable();
        }
        // شمارشِ متحرکِ عددها روی کارت‌ها
        function recCount(el, val, fmt) {
            const start = performance.now(), dur = 700, from = 0;
            const step = t => { const k = Math.min(1, (t - start) / dur), v = Math.round(from + (val - from) * (1 - Math.pow(1 - k, 3))); el.innerHTML = fmt(v); if (k < 1) requestAnimationFrame(step); };
            requestAnimationFrame(step);
        }
        const recMoney = v => e2p(Number(v || 0).toLocaleString('en-US'));
        async function loadRecordsStats() {
            try {
                const qs = new URLSearchParams({action: 'stats', from: recPeriod.from || '', to: recPeriod.to || ''});
                const d = await (await fetch('api/records.php?' + qs)).json();
                if (!d.ok) return;
                recCount(document.getElementById('rc-letters'), d.letters, v => `${e2p(v)}<small>فقره</small>`);
                recCount(document.getElementById('rc-issued'), d.issued, v => `${e2p(v)}<small>فقره</small>`);
                recCount(document.getElementById('rc-premium'), Math.round(d.premium / 10), v => `${recMoney(v)}<small>تومان</small>`);
                recCount(document.getElementById('rc-overdue'), Math.round(d.overdue_amount / 10), v => `${recMoney(v)}<small>تومان</small>`);
                document.getElementById('rc-letters-s').textContent = 'بر اساس تاریخِ صدورِ معرفی‌نامه';
                document.getElementById('rc-issued-s').textContent = `ثالث ${e2p(d.issued_third)} · بدنه ${e2p(d.issued_body)}`;
                document.getElementById('rc-premium-s').textContent = `ثالث ${recMoney(Math.round(d.premium_third / 10))} · بدنه ${recMoney(Math.round(d.premium_body / 10))} تومان`;
                document.getElementById('rc-overdue-s').textContent = d.overdue_count ? `${e2p(d.overdue_count)} قسط از ${e2p(d.overdue_people)} نفر` : 'قسطِ معوقی نیست 🎉';
            } catch (e) {}
        }

        async function loadRecords() {
            loadStats();
            loadRecordsStats();
            const tbody = document.getElementById('records-body');
            tbody.innerHTML = '<tr><td colspan="9" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin text-xl"></i> در حال خواندن اطلاعات از دیتابیس...</td></tr>';
            try {
                const res = await fetch('api/records.php');
                const data = await res.json();
                if (data.ok) {
                    currentRecordsData = data.data;
                    // ماه‌های موجود برای فیلترِ «ماه معرفی‌نامه»
                    const sel = document.getElementById('rec-month'), cur = sel.value;
                    const months = [...new Map(currentRecordsData.map(r => [r.letter_year + '-' + r.letter_month, r])).values()]
                        .sort((a, b) => (b.letter_year * 100 + b.letter_month) - (a.letter_year * 100 + a.letter_month));
                    sel.innerHTML = '<option value="">همه‌ی ماه‌های معرفی‌نامه</option>' + months.map(r => `<option value="${r.letter_year}-${r.letter_month}">${r.letter_month_fa} ${e2p(r.letter_year)}</option>`).join('');
                    sel.value = cur;
                    filterRecordsTable();
                }
                else tbody.innerHTML = '<tr><td colspan="9" class="text-center p-8 text-red-500">خطا در خواندن اطلاعات.</td></tr>';
            } catch (error) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center p-8 text-red-500">خطا در برقراری ارتباط با سرور.</td></tr>';
            }
        }

        function filterRecordsTable() {
            const q = recEn(document.getElementById('records-search-input').value.trim()).toLowerCase();
            const month = document.getElementById('rec-month').value, iss = document.getElementById('rec-issued-f').value;
            currentRecordsFiltered = currentRecordsData.filter(row => {
                const d = row.filter_date || '';
                if (recPeriod.from && d < recPeriod.from) return false;
                if (recPeriod.to && d > recPeriod.to) return false;
                if (month && month !== row.letter_year + '-' + row.letter_month) return false;
                if (iss === 'yes' && !row.issued_total) return false;
                if (iss === 'no' && row.issued_total) return false;
                if (iss === 'month' && !row.issued_in_letter_month) return false;
                if (!q) return true;
                const hay = [row.full_name, row.national_code, row.personnel_code, row.company_name, row.mobile_number, row.letter_month_fa,
                    row.letter_month_fa + ' ' + row.letter_year, row.letter_date_j, row.registered_j, row.issued_summary, row.relationship_summary].filter(Boolean).join(' ');
                return recEn(hay).toLowerCase().includes(q);
            });
            renderRecordsTable(currentRecordsFiltered);
        }
        document.getElementById('records-search-input').addEventListener('input', filterRecordsTable);
        document.getElementById('rec-month').addEventListener('change', filterRecordsTable);
        document.getElementById('rec-issued-f').addEventListener('change', filterRecordsTable);

        function renderRecordsTable(list) {
            const tbody = document.getElementById('records-body');
            if (list.length === 0) { tbody.innerHTML = '<tr><td colspan="9" class="text-center p-8 text-slate-400">موردی یافت نشد.</td></tr>'; return; }
            tbody.innerHTML = list.map((row, index) => `
                    <tr data-id="${row.id}" class="hover:bg-blue-50/50 transition cursor-pointer border-b border-slate-50 hover-target" onclick="openIntroDetail(${row.introduction_id}, '${(row.full_name||'').replace(/'/g,"")}')">
                        <td class="p-4 font-mono text-slate-400">${e2p(index + 1)}</td>
                        <td class="p-4 text-slate-700">${row.full_name || '-'}${row.company_name ? `<span class="block text-[10px] text-slate-400 font-normal">${row.company_name}</span>` : ''}</td>
                        <td class="p-4 text-slate-500 font-bold" dir="ltr">${e2p(row.national_code) || '-'}</td>
                        <td class="p-4 text-slate-500 font-bold" dir="ltr">${e2p(row.personnel_code) || '-'}</td>
                        <td class="p-4 text-xs whitespace-nowrap"><span class="text-slate-700" title="تاریخ صدور معرفی‌نامه">${row.letter_date_j ? e2p(row.letter_date_j) : '<span class="text-slate-300">ثبت نشده</span>'}</span>
                            <span class="block text-[10px] text-slate-400 font-normal mt-0.5" title="تاریخ ثبت در سامانه"><i class="fas fa-clock-rotate-left ml-1"></i>${e2p(row.registered_j || '-')}</span></td>
                        <td class="p-4 whitespace-nowrap"><span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-100 rounded-full px-3 py-1 text-xs">${row.letter_month_fa} ${e2p(row.letter_year)}</span></td>
                        <td class="p-4 text-blue-600 text-xs">${e2p(row.issued_summary) || '-'} <span class="text-slate-400">(سهمیه: ${e2p(row.quota_summary)})</span>
                            <span class="block text-[10px] ${row.issued_in_letter_month ? 'text-emerald-600' : 'text-slate-400'} mt-0.5">در ماهِ معرفی‌نامه: ${e2p(String(row.issued_in_letter_month))} فقره</span>
                            <span class="block text-[10px] text-slate-400">${e2p(row.relationship_summary || '')}</span></td>
                        <td class="p-4 text-xs whitespace-nowrap text-emerald-700">${row.premium_total ? recMoney(Math.round(row.premium_total / 10)) + ' <span class="text-[10px] text-slate-400">تومان</span>' : '<span class="text-slate-300">—</span>'}</td>
                        <td class="p-4 whitespace-nowrap"><button class="bg-indigo-100 text-indigo-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-indigo-200"><i class="fas fa-folder-open ml-1"></i>جزئیات (${e2p(String(row.total_cases))} بیمه‌نامه)</button>
                            ${row.has_intro_file ? `<a href="api/records.php?action=download_intro&intro_id=${row.introduction_id}" onclick="event.stopPropagation()" title="دانلودِ فایلِ معرفی‌نامه" class="mr-1 inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 px-2.5 py-1.5 rounded-lg text-xs font-bold"><i class="fas fa-file-arrow-down"></i>معرفی‌نامه</a>` : ''}
                            ${IS_ADMIN ? `<button onclick="event.stopPropagation(); activeRowId = ${row.id}; confirmDeleteRow();" title="حذف این معرفی‌نامه و درخواست‌هایش" class="mr-1 bg-red-50 text-red-500 hover:bg-red-100 px-2.5 py-1.5 rounded-lg text-xs"><i class="fas fa-trash-alt"></i></button>` : ''}</td>
                    </tr>`).join('');
        }

        // ======================= ثبت دستیِ معرفی‌نامه (با تشخیصِ خودکار از روی فایل) =======================
        let miToken = null;
        const MI_FIELDS = ['full_name', 'national_code', 'personnel_code', 'company_name', 'letter_date', 'mobile', 'quota'];
        const miEl = k => document.getElementById('mi-' + k.replace(/_/g, '-'));
        function miShow(state, html) {
            document.getElementById('mi-drop').classList.toggle('hidden', state !== 'drop');
            document.getElementById('mi-scan').classList.toggle('hidden', state !== 'scan');
            const at = document.getElementById('mi-attached');
            at.classList.toggle('hidden', state !== 'attached');
            if (state === 'attached') at.innerHTML = html;
        }
        function miAlert(kind, text) {
            const a = document.getElementById('mi-alert');
            if (!text) { a.className = 'hidden'; a.innerHTML = ''; return; }
            a.className = 'mi-warn ' + kind;
            a.innerHTML = `<i class="fas ${kind === 'err' ? 'fa-triangle-exclamation' : 'fa-circle-info'} mt-0.5"></i><span></span>`;
            a.querySelector('span').textContent = text;
        }
        function openManualIntroModal() {
            miToken = null;
            MI_FIELDS.forEach(k => { const e = miEl(k); e.value = ''; e.classList.remove('auto', 'bad'); });
            document.getElementById('mi-file').value = '';
            miShow('drop'); miAlert();
            const btn = document.getElementById('mi-submit'); btn.disabled = false;
            fetch('api/finance_actions.php?action=bootstrap').then(r => r.json()).then(d => {
                const names = [...new Set((d.companies || []).map(c => c.name))];
                document.getElementById('mi-companies-dl').innerHTML = names.map(n => `<option value="${n}">`).join('');
            }).catch(() => {});
            openModal('manual-intro-modal');
        }
        function closeManualIntroModal() { closeModal('manual-intro-modal'); }
        async function miUpload(file) {
            if (!file) return;
            miAlert(); miShow('scan'); document.getElementById('mi-scan-name').textContent = file.name;
            const fd = new FormData(); fd.append('action', 'intro_detect'); fd.append('file', file);
            let d;
            try { d = await (await fetch('api/record_actions.php', {method: 'POST', body: fd})).json(); }
            catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
            document.getElementById('mi-file').value = '';
            if (!d.ok) { miShow('drop'); miAlert('err', d.error || 'بارگذاری ممکن نشد.'); if (d.not_intro) showToast(d.error, 'error'); return; }
            miToken = d.token;
            // فایل پذیرفته شد: کادرِ بارگذاری پنهان می‌شود و همین فایل برای بایگانی استفاده می‌شود
            miShow('attached', `<i class="fas fa-file-circle-check"></i><span>${d.detected ? 'معرفی‌نامه تشخیص داده شد' : 'فایل پیوست شد'}: <span class="font-normal"></span></span><button type="button" onclick="miRemoveFile()"><i class="fas fa-rotate ml-1"></i>تعویض فایل</button>`);
            document.querySelector('#mi-attached span span').textContent = d.name;
            if (d.warning) miAlert('info', d.warning);
            if (d.detected) {
                let n = 0;
                Object.entries(d.fields || {}).forEach(([k, v]) => {
                    const e = miEl(k); if (!e || !v) return;
                    e.value = ['national_code', 'personnel_code', 'letter_date', 'mobile'].includes(k) ? e2p(v) : v;
                    e.classList.remove('auto'); void e.offsetWidth; e.classList.add('auto'); n++;
                });
                showToast(n ? `${e2p(n)} فیلد از روی معرفی‌نامه پر شد؛ بقیه را کامل کنید.` : 'معرفی‌نامه تشخیص داده شد ولی فیلدی خوانده نشد؛ دستی وارد کنید.', n ? 'success' : 'warning');
            }
        }
        function miRemoveFile() { miToken = null; miShow('drop'); miAlert(); }
        (function () {
            const drop = document.getElementById('mi-drop'), inp = document.getElementById('mi-file');
            inp.addEventListener('change', () => miUpload(inp.files[0]));
            ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
            ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
            drop.addEventListener('drop', e => { const f = (e.dataTransfer.files || [])[0]; if (f) miUpload(f); });
        })();
        async function submitManualIntro() {
            const v = k => p2e(miEl(k).value.trim());
            MI_FIELDS.forEach(k => miEl(k).classList.remove('bad'));
            const bad = (k, msg) => { miEl(k).classList.add('bad'); miEl(k).focus(); showToast(msg, 'warning'); };
            if (v('full_name').length < 3) return bad('full_name', 'نام و نام خانوادگیِ پرسنل را وارد کنید.');
            if (!/^\d{10}$/.test(v('national_code'))) return bad('national_code', 'کد ملی باید ۱۰ رقم باشد.');
            if (v('letter_date') && !/^1[34]\d\d\D+\d{1,2}\D+\d{1,2}$/.test(v('letter_date'))) return bad('letter_date', 'تاریخ معرفی‌نامه را مثل ۱۴۰۵/۰۷/۰۱ وارد کنید.');
            if (v('mobile') && !/^09\d{9}$/.test(v('mobile'))) return bad('mobile', 'موبایل باید ۱۱ رقم و با ۰۹ شروع شود.');
            if (!miToken && !(await uiConfirm('بدونِ فایل', 'فایلِ معرفی‌نامه بارگذاری نشده. بدون فایل ثبت شود؟', {ok: 'بله، ثبت شود'}))) return;
            const btn = document.getElementById('mi-submit'); btn.disabled = true; const old = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حال ثبت...';
            try {
                const body = {action: 'create_intro', file_token: miToken || '', max_quota: v('quota')};
                MI_FIELDS.filter(k => k !== 'quota').forEach(k => body[k] = v(k));
                const d = await (await fetch('api/record_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})).json();
                if (!d.ok) { if (d.field) bad(d.field, d.error); else showToast(d.error || 'ثبت نشد.', 'error'); return; }
                closeManualIntroModal();
                showToast('معرفی‌نامه ثبت شد' + (d.filed ? ' و فایلش در بایگانی قرار گرفت.' : '.'), 'success');
                loadRecords();
                if (await uiConfirm('ثبت درخواست', 'همین حالا برای این معرفی‌نامه درخواستِ بیمه (ثالث/بدنه) ثبت می‌کنید؟', {ok: 'بله، درخواست جدید'}))
                    openManualCreateModal(d.intro_id, v('full_name'));
            } finally { btn.disabled = false; btn.innerHTML = old; }
        }

        // ======================= «درخواست جدید کارکنان» از لیست صدور: انتخابِ معرفی‌نامه =======================
        let ipData = [];
        async function openIntroPicker() {
            document.getElementById('ip-q').value = '';
            document.getElementById('ip-list').innerHTML = '<p class="text-center text-slate-400 text-xs py-6"><i class="fas fa-spinner fa-spin"></i></p>';
            openModal('intro-picker-modal');
            try { const d = await (await fetch('api/records.php')).json(); ipData = d.ok ? d.data : []; } catch (e) { ipData = []; }
            renderIntroPicker();
            setTimeout(() => document.getElementById('ip-q').focus(), 80);
        }
        function renderIntroPicker() {
            const q = recEn(document.getElementById('ip-q').value.trim()).toLowerCase();
            const list = ipData.filter(r => !q || recEn([r.full_name, r.national_code, r.personnel_code, r.company_name, r.letter_month_fa, r.letter_date_j].join(' ')).toLowerCase().includes(q)).slice(0, 60);
            document.getElementById('ip-list').innerHTML = list.length ? list.map((r, i) => {
                const [used, max] = String(r.quota_summary || '0 / 4').split('/').map(x => parseInt(x));
                const full = used >= max;
                return `<div class="ip-item ${full ? 'full' : ''}" data-i="${ipData.indexOf(r)}" style="animation-delay:${Math.min(i, 10) * 25}ms" title="${full ? 'سهمیه‌ی این معرفی‌نامه پر شده' : ''}">
                    <span class="ip-av">${(r.full_name || '؟').trim().charAt(0)}</span>
                    <div class="flex-1 min-w-0"><b class="text-sm text-slate-700">${r.full_name || '-'}</b>
                        <p class="text-[11px] text-slate-400">${e2p(r.national_code || '')} · ${r.company_name || '—'} · معرفی‌نامه‌ی ${r.letter_month_fa} ${e2p(r.letter_year)}</p></div>
                    <span class="text-[11px] font-black ${full ? 'text-rose-500' : 'text-teal-600'} whitespace-nowrap">سهمیه ${e2p(String(used))}/${e2p(String(max))}</span></div>`;
            }).join('') : '<p class="text-center text-slate-400 text-xs py-6">معرفی‌نامه‌ای پیدا نشد.</p>';
            document.querySelectorAll('#ip-list .ip-item').forEach(el => el.onclick = () => {
                if (el.classList.contains('full')) { showToast('سهمیه‌ی این معرفی‌نامه پر شده است.', 'warning'); return; }
                const r = ipData[Number(el.dataset.i)];
                closeModal('intro-picker-modal');
                openManualCreateModal(r.introduction_id, r.full_name);
            });
        }
        document.getElementById('ip-q').addEventListener('input', renderIntroPicker);

        // خروجی اکسل: دقیقاً همین ردیف‌های فیلترشده، با جزئیاتِ هر بیمه‌نامه‌ی زیرِ هر معرفی‌نامه
        function exportRecordsExcel() {
            if (!currentRecordsFiltered.length) { showToast('ردیفی برای خروجی نیست.', 'warning'); return; }
            const f = document.createElement('form');
            f.method = 'POST'; f.action = 'api/records.php'; f.style.display = 'none';
            f.innerHTML = `<input name="action" value="export"><input name="ids">`;
            f.querySelector('[name=ids]').value = JSON.stringify(currentRecordsFiltered.map(r => r.id));
            document.body.appendChild(f); f.submit(); setTimeout(() => f.remove(), 1000);
            showToast(`خروجی اکسلِ ${e2p(currentRecordsFiltered.length)} ردیف در حال آماده‌سازی است...`, 'success');
        }

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
                                <p class="text-[9px] text-slate-400">ایجاد: <span dir="ltr">${toJalali(item.created)}</span></p>
                                <p class="text-[9px] text-slate-400">ویرایش: <span dir="ltr">${toJalali(item.modified)}</span></p>
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
                            <p class="text-[9px] text-slate-400">ایجاد: <span dir="ltr">${toJalali(item.created)}</span></p>
                            <p class="text-[9px] text-slate-400">ویرایش: <span dir="ltr">${toJalali(item.modified)}</span></p>
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
            // نام‌های فیلد در موتورِ قدیمی و الگوریتمِ جدید فرق دارد (policy_number/policy_num ، total_premium/premium ، ...)؛ هر دو خوانده می‌شود
            // و مقدارِ اشتباهی که از ردیفِ کناریِ PDF آمده (مثلاً «… شماره قرارداد») دور ریخته می‌شود
            const bad = v => /:|شماره|قرارداد|ریال|تعهد|خسارت|\d+\/\d+/.test(String(v || ''));
            const g = (...keys) => { for (const k of keys) { const v = json[k]; if (v !== undefined && v !== null && String(v).trim() !== '') return String(v).trim(); } return ''; };
            const gt = (...keys) => { const v = g(...keys); return bad(v) ? '' : v; };

            document.getElementById('review-doc-type').value = json.ins_type || 'ناشناخته';
            document.getElementById('review-full-name').value = g('insured_name');
            document.getElementById('review-national-code').value = g('national_id', 'national_code');
            document.getElementById('review-personnel-code').value = g('personnel_id', 'personnel_code');
            document.getElementById('review-company').value = g('company_name');
            document.getElementById('review-letterdate').value = faDigits(g('letter_date'));
            document.getElementById('review-policy').value = g('policy_num', 'policy_number');
            document.getElementById('review-uniquecode').value = g('central_no', 'unique_code');
            document.getElementById('review-plate-mount').innerHTML = plateSplitHtml('review-plate', g('plate'));
            document.getElementById('review-phone').value = g('phone');
            document.getElementById('review-issuedate').value = g('issue_date');
            document.getElementById('review-renewal').value = g('renewal_status');
            document.getElementById('review-cartype').value = gt('car_name') || [gt('car_system'), gt('car_tip', 'car_type')].filter(Boolean).join(' ');
            document.getElementById('review-model').value = /^(1[34]\d\d|19\d\d|20\d\d)$/.test(g('model_year')) ? g('model_year') : '';
            document.getElementById('review-color').value = gt('color', 'car_color');
            document.getElementById('review-engine').value = g('engine_no', 'engine_num');
            document.getElementById('review-chassis').value = g('chassis_no', 'chassis_num');
            document.getElementById('review-vin').value = g('vin');
            document.getElementById('review-carvalue').value = mfmt(g('car_value'));
            document.getElementById('review-premium').value = mfmt(g('premium', 'total_premium'));

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
        // زنگوله‌ی کنارِ دکمه‌ی خروج: هر اعلانی که گوشه‌ی صفحه می‌آید، آن‌جا هم می‌ماند
        let notifAfterId = null;   // آخرین اعلانی که دیده شده (حالتِ ماندگار)
        async function notifFetch(extra) {
            const res = await fetch('api/notifications_feed.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(Object.assign({since: notifSince, after_id: notifAfterId}, extra || {}))});
            return res.json();
        }
        // پاسخِ فید را اعمال می‌کند و رویدادهای تازه (برای نمایشِ گوشه‌ی صفحه) را برمی‌گرداند
        function applyNotifResponse(data) {
            if (!data || !data.ok) return [];
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
            storageKey: 'notif:admin:<?php echo intval($_SESSION['user_id']); ?>',
            onOpenItem: it => { if (it.tab) switchTab(it.tab); },
            // حذف/خواندن در حالتِ ماندگار روی سرور انجام می‌شود تا در همه‌ی مرورگرها یکی باشد
            onServerAction: async (act, id) => {
                const data = await notifFetch({notif_action: act, id});
                applyNotifResponse(data).forEach(pushNotification);
                return data && data.persisted ? data.items : null;
            },
        });
        function pushNotification(ev) {
            if (window.NotifBell) NotifBell.add(ev);
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
                // فیدِ جدا برای همه‌ی نقش‌ها (قبلاً داخلِ API شرکت‌ها بود و اپراتور/مالی اعلانی نمی‌گرفتند)
                const data = await notifFetch();
                if (!data.ok) return;
                const events = applyNotifResponse(data);
                events.forEach(pushNotification);
                // اگر مدرک یا درخواستِ تازه‌ای آمد، شمارنده‌ها و فهرست‌ها هم تازه شوند
                if (events.some(e => e.type === 'company_doc' || e.type === 'company_request')) {
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

        // زیرمنوهای درختی: در دسکتاپ با هاور (کمی مکث تا حرکتِ اریبِ موس زیرمنوی دیگری باز نکند) یا کلیک؛
        // اگر کنارِ منو جا نشود، سمتِ دیگر باز می‌شود و از پایینِ صفحه هم بیرون نمی‌زند
        function closeMenuSubs(scope, keep) {
            (scope || document).querySelectorAll('.menu-sub.open').forEach(sb => {
                if (keep && (sb === keep || sb.contains(keep))) return;
                sb.classList.remove('open');
                const t = sb.querySelector('.menu-sub-trigger'); if (t) t.setAttribute('aria-expanded', 'false');
            });
        }
        function openMenuSub(sub) {
            clearTimeout(sub._mt);
            closeMenuSubs(sub.closest('.menu-panel'), sub);
            sub.classList.add('open');
            const t = sub.querySelector('.menu-sub-trigger'); if (t) t.setAttribute('aria-expanded', 'true');
            if (window.innerWidth < 1024) return;
            const sp = sub.querySelector('.menu-subpanel');
            sub.classList.remove('flip'); sp.style.top = '';
            let r = sp.getBoundingClientRect();
            if (r.left < 8) { sub.classList.add('flip'); r = sp.getBoundingClientRect(); }
            const over = r.bottom - (window.innerHeight - 8);
            if (over > 0) sp.style.top = (-8 - over) + 'px';
        }
        function toggleMenuSub(btn, e) {
            if (e) e.stopPropagation();
            const sub = btn.closest('.menu-sub');
            const hover = window.matchMedia('(min-width: 1024px) and (hover: hover) and (pointer: fine)').matches;
            if (sub.classList.contains('open') && !hover) { closeMenuSubs(sub.parentElement); return; }
            openMenuSub(sub);
        }

        function closeAllMenuGroups() {
            closeMenuSubs();
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
            document.querySelectorAll('.menu-sub').forEach(sub => {
                sub.addEventListener('mouseenter', () => {
                    if (!desktopHover.matches) return;
                    clearTimeout(sub._mt); sub._mt = setTimeout(() => openMenuSub(sub), 110);
                });
                sub.addEventListener('mouseleave', () => {
                    if (!desktopHover.matches) return;
                    clearTimeout(sub._mt);
                    sub._mt = setTimeout(() => { sub.classList.remove('open'); }, 260);
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
            return irPlateHtml(p.plate_p4, p.plate_letter, p.plate_p2, p.plate_p1);
        }

        // ماه‌های شمسی (از ماهِ جاری تا ۲۴ ماه قبل) برای فیلترها؛ مقدار: «1405/07»
        const J_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        function jMonthOptions(sel, firstLabel = 'همه‌ی ماه‌ها') {
            if (!sel || sel.dataset.filled) return;
            const now = window.IrTime ? IrTime.parts() : {jy: 1405, jm: 1};
            let y = now.jy, m = now.jm, html = `<option value="">${firstLabel}</option>`;
            for (let i = 0; i < 24; i++) {
                html += `<option value="${y}/${String(m).padStart(2, '0')}">${J_MONTHS[m - 1]} ${e2p(String(y))}</option>`;
                if (--m < 1) { m = 12; y--; }
            }
            sel.innerHTML = html; sel.dataset.filled = '1';
        }
        // ماهِ انتخاب‌شده => دو خانه‌ی «از / تا» (برای فیلترهایی که بازه‌ی تاریخ دارند)
        function jMonthToRange(v, fromId, toId) {
            const f = document.getElementById(fromId), t = document.getElementById(toId);
            if (!v) { f.value = ''; t.value = ''; return; }
            const [y, m] = v.split('/').map(Number);
            f.value = e2p(`${y}/${String(m).padStart(2, '0')}/01`);
            t.value = e2p(`${y}/${String(m).padStart(2, '0')}/${m <= 6 ? 31 : (m <= 11 ? 30 : 29)}`);
        }
        let creqTimer = null;
        function debouncedCompanyRequests() { clearTimeout(creqTimer); creqTimer = setTimeout(loadCompanyRequests, 350); }
        const CREQ_STAGE_COLOR = {PENDING: 'bg-amber-50 text-amber-700', READY_FOR_ISSUE: 'bg-emerald-50 text-emerald-700', WITH_BOSS: 'bg-violet-50 text-violet-700',
                                  IN_ISSUANCE: 'bg-sky-50 text-sky-700', ISSUED: 'bg-teal-100 text-teal-800', CANCELLED: 'bg-slate-100 text-slate-500'};
        const creqStageChips = list => (list || []).map(s => `<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${CREQ_STAGE_COLOR[s.status] || 'bg-slate-100 text-slate-600'}">${s.status_fa} <b>${e2pNum(s.n)}</b></span>`).join(' ');
        async function loadCompanyRequests() {
            const status = document.getElementById('creq-status-filter').value;
            jMonthOptions(document.getElementById('creq-month'));
            const g = id => (document.getElementById(id) || {}).value || '';
            const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_requests', status,
                company_id: g('creq-company'), month: g('creq-month'), request_kind: g('creq-kind'), insurer: g('creq-insurer'), q: g('creq-q').trim()})});
            const data = await res.json();
            const tbody = document.getElementById('creq-body');
            if (!data.ok) { tbody.innerHTML = `<tr><td colspan="8" class="text-center p-6 text-red-500">${data.error || 'خطا'}</td></tr>`; return; }
            const csel = document.getElementById('creq-company');
            if (csel && !csel.dataset.filled && data.companies) {
                csel.innerHTML = '<option value="">همه‌ی شرکت‌ها</option>' + data.companies.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
                csel.dataset.filled = '1';
            }
            const sm = data.summary || {};
            document.getElementById('creq-summary').innerHTML = `<span class="font-black text-slate-600"><i class="fas fa-filter text-indigo-400 ml-1"></i>${e2pNum(sm.requests || 0)} درخواست · ${e2pNum(sm.rows || 0)} ردیف · ${e2pNum(sm.issued || 0)} صادرشده</span>
                <span class="text-slate-300">|</span>${creqStageChips(sm.stages) || '<span class="text-slate-400">ردیفی نیست</span>'}`;
            companyRequestsCache = data.requests;
            if (!data.requests.length) { tbody.innerHTML = '<tr><td colspan="8" class="text-center p-8 text-slate-400">درخواستی یافت نشد.</td></tr>'; return; }
            tbody.innerHTML = data.requests.map(r => `
                <tr class="border-t border-slate-100 hover:bg-slate-50">
                    <td class="p-3">${reqCodeHtml(r)}</td>
                    <td class="p-3 font-bold">${r.company_name}</td>
                    <td class="p-3">${r.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد'}
                        ${r.request_kind && r.request_kind !== 'NEW_POLICY'
                            ? `<span class="block text-[10px] font-bold ${r.request_kind === 'ENDORSEMENT' ? 'text-violet-600' : 'text-rose-600'}">${r.request_kind_fa}</span>` : ''}</td>
                    <td class="p-3 text-[11px] whitespace-nowrap">
                        ${r.requested_counts_fa ? `<span class="block text-[10px] text-indigo-600 font-bold mb-1">درخواستیِ نامه: ${r.requested_counts_fa}</span>` : ''}
                        <span class="inline-block bg-cyan-50 text-cyan-700 rounded px-1.5 py-0.5 ml-1">بدنه ${e2pNum(r.body_count)} / ${e2pNum(r.body_issued)}</span>
                        <span class="inline-block bg-blue-50 text-blue-700 rounded px-1.5 py-0.5">ثالث ${e2pNum(r.third_count)} / ${e2pNum(r.third_issued)}</span>
                    </td>
                    <td class="p-3"><div class="flex flex-wrap gap-1 max-w-[260px]">${creqStageChips(r.row_stages) || '<span class="text-slate-300">—</span>'}</div></td>
                    <td class="p-3"><span class="status-badge ${CREQ_STATUS_COLOR[r.status] || ''} text-[10px] font-bold px-2 py-1 rounded-full">${CREQ_STATUS_FA[r.status] || r.status}</span></td>
                    <td class="p-3 text-slate-400" dir="ltr">${toJalali(r.created_at)}</td>
                    <td class="p-3 flex items-center gap-2">
                        <button onclick="openCompanyRequestDetail(${r.id})" class="text-blue-600 hover:underline text-xs font-bold">مشاهده</button>
                        <button onclick="openRequestDocsReview(${r.id})" class="relative bg-slate-100 hover:bg-slate-200 text-slate-600 text-[11px] font-bold px-3 py-1.5 rounded-lg">
                            بررسی مدارک
                            ${r.pending_docs_count ? `<span class="absolute -top-1.5 -left-1.5 bg-red-500 text-white text-[9px] w-4 h-4 flex items-center justify-center rounded-full">${r.pending_docs_count}</span>` : ''}
                        </button>
                        ${IS_ADMIN ? `<button onclick="openEditRequestModal(${r.id})" class="text-amber-600 hover:underline text-xs font-bold">ویرایش</button>
                        <button onclick="deleteRequestRow(${r.id})" class="text-red-500 hover:underline text-xs font-bold">حذف</button>` : ''}
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
        const ANCR_KEYS = ['THIRDPARTY', 'BODY', 'ENDORSEMENT', 'CANCELLATION'];
        function ancrSetCounts(obj) {
            ANCR_KEYS.forEach(k => { const el = document.getElementById('ancr-cnt-' + k); el.value = obj && Number(obj[k]) ? Number(obj[k]) : ''; el.oninput = ancrCountSum; });
            ancrCountSum();
        }
        function ancrGetCounts() {
            const o = {}; ANCR_KEYS.forEach(k => { o[k] = Math.max(0, parseInt(String(document.getElementById('ancr-cnt-' + k).value || '0').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)), 10) || 0); });
            return o;
        }
        function ancrCountSum() {
            const c = ancrGetCounts(), lab = {THIRDPARTY: 'ثالث', BODY: 'بدنه', ENDORSEMENT: 'الحاقیه', CANCELLATION: 'فسخ'};
            const parts = ANCR_KEYS.filter(k => c[k]).map(k => `${e2pNum(c[k])} ${lab[k]}`);
            document.getElementById('ancr-cnt-sum').textContent = parts.length ? 'جمع: ' + parts.join('، ') : 'اگر نامه تعداد مشخص کرده، این‌جا بنویسید تا با ردیف‌های ثبت‌شده مقایسه شود.';
            // نوعِ درخواست از روی تعدادها پیشنهاد می‌شود
            const kind = document.getElementById('ancr-kind');
            if (c.THIRDPARTY + c.BODY > 0) kind.value = 'NEW_POLICY';
            else if (c.ENDORSEMENT > 0) kind.value = 'ENDORSEMENT';
            else if (c.CANCELLATION > 0) kind.value = 'CANCELLATION';
            onAncrKindChange();
        }
        async function openAdminNewRequestModal() {
            document.getElementById('ancr-modal-title').textContent = 'ثبت دستی درخواست شرکتی';
            document.getElementById('ancr-modal-sub').textContent = 'درخواستِ شرکت را مثلِ نامه‌ی رسمی‌اش ثبت کنید';
            document.getElementById('ancr-code').classList.add('hidden');
            document.getElementById('ancr-submit').innerHTML = '<i class="fas fa-check ml-1"></i>ثبت درخواست';
            document.getElementById('ancr-request-id').value = '';
            document.getElementById('ancr-text').value = '';
            document.getElementById('ancr-letter-file').value = '';
            document.getElementById('ancr-kind').value = 'NEW_POLICY';
            onAncrKindChange();
            ancrSetCounts(null);
            document.getElementById('ancr-company').disabled = false;
            await loadAncrCompanies();
            ancrUpdateInsurerOptions();
            openModal('admin-new-creq-modal');
        }
        // شناسه‌ی ۵ رقمیِ درخواست (با دکمه‌ی کپی)
        function reqCodeHtml(r, big) {
            if (!r.ref_code) return `<span class="font-bold text-slate-500">#${e2pNum(r.id)}</span>`;
            return `<span class="inline-flex items-center gap-1 ${big ? 'text-[12px] px-2.5 py-1' : 'text-[11px] px-2 py-0.5'} font-black rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200 cursor-pointer" title="شناسه‌ی درخواست — برای کپی کلیک کنید" onclick="event.stopPropagation(); copyValue && copyValue('${r.ref_code}')"><i class="fas fa-hashtag text-[9px] opacity-60"></i>${e2pNum(r.ref_code)}</span>`;
        }
        async function openEditRequestModal(requestId) {
            document.getElementById('ancr-modal-title').textContent = 'ویرایش درخواست';
            document.getElementById('ancr-submit').innerHTML = '<i class="fas fa-floppy-disk ml-1"></i>ذخیره‌ی تغییرات';
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
            ancrSetCounts(data.request.requested_counts_obj);
            document.getElementById('ancr-kind').value = data.request.request_kind || 'NEW_POLICY';
            onAncrKindChange();
            const code = document.getElementById('ancr-code');
            code.textContent = data.request.ref_code ? 'شناسه‌ی درخواست: ' + e2pNum(data.request.ref_code) : 'درخواست #' + e2pNum(requestId);
            code.classList.remove('hidden');
            document.getElementById('ancr-modal-sub').textContent = data.request.company_name || '';
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
                    requested_counts: ancrGetCounts(),
                    request_text: document.getElementById('ancr-text').value.trim() }
                : { action: 'admin_create_request', company_id: companyId,
                    insurer: document.getElementById('ancr-insurer').value,
                    request_kind: document.getElementById('ancr-kind').value,
                    requested_counts: ancrGetCounts(),
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

        // چک‌لیستِ یک ردیف: «بازدید سلامت» و «گزارش بازدید» یک خانه‌ی مشترک‌اند با دکمه‌ی «ساخت گزارش بازدید»
        const VISIT_KEYS = ['health_inspection', 'health_report'];
        function rowChecklistHtml(p, canEdit) {
            const items = p.checklist || [];
            const visit = items.filter(it => VISIT_KEYS.includes(it.key));
            return items.filter(it => !VISIT_KEYS.includes(it.key)).map(it => checklistChip(p, it, canEdit)).join('')
                 + (visit.length ? visitChip(p, visit, canEdit) : '');
        }
        function visitChip(p, items, canEdit) {
            const report = items.find(it => it.key === 'health_report');
            const ok = items.every(it => it.satisfied || !it.required);
            const required = items.some(it => it.required);
            const tone = ok ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : (required ? 'bg-red-50 border-red-200 text-red-700' : 'bg-slate-50 border-slate-200 text-slate-400');
            const docs = items.flatMap(it => it.docs || []);
            const links = docs.map(d => d.is_dir
                ? `<a href="${COMPANY_API}?action=download_folder_zip&doc_id=${d.id}" class="underline hover:no-underline" title="دانلود زیپِ عکس‌ها">عکس‌ها (پوشه)</a>`
                : `<a href="../${d.file_path}" target="_blank" class="underline hover:no-underline">${d.label}</a>`).join('<span class="text-slate-300 mx-1">|</span>');
            const del = canEdit ? docs.map(d => `<button onclick="deleteRowDoc(${d.id})" title="حذف این مدرک" class="text-red-400 hover:text-red-600 mr-1">&times;</button>`).join('') : '';
            const canBuild = canEdit && window.VR && VR.openTargetBuilder && p.status !== 'ISSUED';
            const build = canBuild ? `<button onclick="openCompanyVisitReport(${p.id})" class="mt-1 text-[10px] font-bold text-white bg-gradient-to-l from-indigo-600 to-violet-600 hover:shadow px-2 py-0.5 rounded whitespace-nowrap">
                    <i class="fas fa-file-circle-plus ml-1"></i>${report && report.satisfied ? 'ویرایش گزارش بازدید' : 'ساخت گزارش بازدید'}</button>` : '';
            const missing = items.filter(it => !it.satisfied);
            const manual = canEdit && missing.length ? `<details class="mt-1"><summary class="cursor-pointer text-[9.5px] text-slate-500">فایلِ آماده دارم</summary>
                    <div class="flex flex-wrap gap-1 mt-1">${missing.map(it => checklistChip(p, { ...it, docs: [] }, canEdit)).join('')}</div></details>` : '';
            return `<div class="border ${tone} rounded-lg px-2 py-1 text-[10px] leading-relaxed" title="گزارش و عکس‌های بازدید">
                <div class="font-bold whitespace-nowrap">${ok ? '✓' : '✗'} گزارش و عکس‌های بازدید${required ? '<span class="text-red-400">*</span>' : ''}</div>
                ${links ? `<div class="mt-0.5">${links}${del}</div>` : ''}${build}${manual}
            </div>`;
        }
        // پاپ‌آپِ ساختِ گزارش از روی همین ردیف: اطلاعاتِ ردیف و شرکت خودکار، قالبِ سواری/سنگین خودکار؛
        // بعد از صدور، PDF و عکس‌ها خودکار در پوشه‌ی همین ردیف می‌نشینند
        function openCompanyVisitReport(plateId) {
            if (!window.VR || !VR.openTargetBuilder) { showToast('به بخشِ گزارش بازدید دسترسی ندارید.', 'error'); return; }
            VR.openTargetBuilder({ type: 'company', id: plateId }, () => { if (currentRequestId) openCompanyRequestDetail(currentRequestId); });
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
                const chips = rowChecklistHtml(p, isAdmin);
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
                        ${p.insurance_type === 'BODY' ? `<div class="mt-1 whitespace-normal max-w-[220px]">${(p.coverages_fa || []).length
                            ? p.coverages_fa.map(c => `<span class="inline-block text-[9px] bg-violet-50 text-violet-700 border border-violet-100 rounded px-1 mt-0.5 ml-0.5">${c}</span>`).join('')
                            : '<span class="text-[9px] text-amber-600">پوشش‌ها مشخص نشده</span>'}
                            ${isAdmin && p.status !== 'ISSUED' && window.CompanyManualRequest ? `<button onclick='CompanyManualRequest.editCoverages(${p.id}, ${JSON.stringify(p.selected_coverages || null)})' class="text-[9px] text-blue-600 underline mr-1">ویرایش پوشش</button>` : ''}</div>` : ''}
                    </td>
                    <td class="p-2 text-[11px] whitespace-nowrap">${faDigits(p.expiry_date_jalali) || (Number(p.is_new_vehicle) ? '<span class="text-slate-400">صفر کیلومتر</span>' : '—')}</td>
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
                    ${reqCodeHtml(r, true)}
                    <span class="status-badge ${CREQ_STATUS_COLOR[r.status] || ''} text-[10px] font-bold px-2 py-1 rounded-full">${CREQ_STATUS_FA[r.status] || r.status}</span>
                    ${r.request_kind && r.request_kind !== 'NEW_POLICY'
                        ? `<span class="status-badge text-[10px] font-bold px-2 py-1 rounded-full ${r.request_kind === 'ENDORSEMENT' ? 'bg-violet-100 text-violet-700' : 'bg-rose-100 text-rose-700'}">${r.request_kind_fa}</span>` : ''}
                    <span class="text-xs text-slate-400">${r.company_name} · ${r.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد'}</span>
                </div>
                <p class="text-[10px] text-slate-400 mb-2">ثبت: ${faDigits(r.created_at_jalali)} · آخرین ویرایش: ${faDigits(r.updated_at_jalali)}</p>
                ${r.requested_counts_fa ? `<p class="text-[11px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2 mb-3"><i class="fas fa-envelope-open-text ml-1"></i>تعدادِ درخواستیِ نامه: ${r.requested_counts_fa}</p>` : ''}

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
                    ${isAdmin && currentRequestRows.some(p => p.status !== 'ISSUED') ? `<button onclick="openBundleModal(${r.id})" class="text-[11px] font-bold text-white px-3 py-1.5 rounded-xl" style="background:linear-gradient(120deg,#4338ca,#7c3aed)"><i class="fas fa-layer-group ml-1"></i>صدور گروهی (فایلِ خروجیِ بیمه‌گر)</button>` : ''}
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

        // =================================================================
        //  صدورِ گروهی: بارگذاریِ فایلِ خروجیِ بیمه‌گر ← شناسایی ← صدور و بایگانیِ ردیف‌های آماده
        // =================================================================
        let BDL = null;   // {requestId, token, name, pages, items, filter, results:{i: res}}
        const bdlEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        const BDL_STATE = {
            ready:     ['آماده‌ی صدور', 'bg-emerald-100 text-emerald-700', 'fa-circle-check'],
            not_ready: ['نرسیده به صدور', 'bg-amber-100 text-amber-700', 'fa-hourglass-half'],
            issued:    ['قبلاً صادر شده', 'bg-slate-200 text-slate-700', 'fa-box-archive'],
            no_match:  ['درخواستی ندارد', 'bg-red-100 text-red-700', 'fa-link-slash'],
            mismatch:  ['مغایرتِ نوع', 'bg-rose-100 text-rose-700', 'fa-triangle-exclamation'],
            duplicate: ['تکراری در فایل', 'bg-rose-100 text-rose-700', 'fa-clone'],
            unknown:   ['شناسایی نشد', 'bg-red-100 text-red-700', 'fa-circle-question'],
        };

        function openBundleModal(requestId, from) {
            // اگر همین درخواست یک بررسیِ باز دارد، همان را نشان بده (مثلاً بعد از تکمیلِ مراحلِ یک ردیف)
            if (!BDL || BDL.requestId !== requestId) BDL = {requestId, token: null, items: [], filter: 'all', results: {}};
            BDL.returnTo = from === 'group' ? 'group' : 'detail';
            document.getElementById('creq-detail-modal').classList.remove('active');
            document.getElementById('bundle-modal').classList.add('active');
            if (BDL.token) { bundleRender(); bundleShow('result'); }
            else { bundleReset(); }
        }
        function closeBundleModal() {
            document.getElementById('bundle-modal').classList.remove('active');
            if (BDL && BDL.returnTo === 'group') { loadIssueGroup(); return; }
            if (BDL && BDL.requestId) openCompanyRequestDetail(BDL.requestId);
        }
        function bundleShow(part) {
            ['upload', 'progress', 'error', 'result'].forEach(k => document.getElementById('bdl-' + k).classList.toggle('hidden', k !== part && !(part === 'error' && k === 'upload')));
        }
        function bundleReset() {
            if (BDL) { BDL.token = null; BDL.items = []; BDL.results = {}; BDL.filter = 'all'; BDL.expired = false; }
            const f = document.getElementById('bdl-file'); f.value = '';
            document.getElementById('bdl-fname').textContent = '';
            document.getElementById('bdl-analyze-btn').disabled = true;
            document.getElementById('bdl-sub').textContent = 'فایلِ خروجیِ سایتِ بیمه‌گر را بارگذاری کنید؛ بیمه‌نامه‌ها جدا، شناسایی و روی ردیف‌های همین درخواست نشانده می‌شوند.';
            bundleShow('upload');
        }
        function bundlePicked(input) {
            const f = input.files[0];
            document.getElementById('bdl-fname').textContent = f ? `${f.name} · ${faDigits((f.size / 1048576).toFixed(1))} مگابایت` : '';
            document.getElementById('bdl-analyze-btn').disabled = !f;
            document.getElementById('bdl-error').classList.add('hidden');
        }
        (function () {   // کشیدن و رها کردنِ فایل
            const drop = document.getElementById('bdl-drop');
            if (!drop) return;
            ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
            ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
            drop.addEventListener('drop', e => {
                const inp = document.getElementById('bdl-file');
                if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; bundlePicked(inp); }
            });
        })();
        function bundleError(msg) {
            const el = document.getElementById('bdl-error');
            el.innerHTML = `<div class="mt-3 flex gap-3 items-start bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4">
                <i class="fas fa-circle-exclamation text-xl mt-0.5"></i>
                <div><p class="font-black text-sm mb-1">فایل قابلِ استفاده نبود</p><p class="text-xs leading-6">${bdlEsc(msg)}</p></div></div>`;
            bundleShow('error');
        }

        async function bundleAnalyze() {
            const f = document.getElementById('bdl-file').files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('action', 'bundle_analyze');
            fd.append('request_id', BDL.requestId);
            fd.append('bundle', f);
            document.getElementById('bdl-progress-text').textContent = 'در حالِ جدا کردنِ صفحه‌ها و شناسایی…';
            bundleShow('progress');
            let data;
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', body: fd});
                const txt = await res.text();
                try { data = JSON.parse(txt); } catch (e) { data = {ok: false, error: res.ok ? 'پاسخِ نامعتبر از سرور.' : `خطای سرور (${res.status}).`}; }
            } catch (e) { data = {ok: false, error: 'خطا در ارتباط با سرور. دوباره تلاش کنید.'}; }
            if (!data.ok) { bundleError(data.error || 'خطا'); return; }
            bundleLoad(data, f.name);
        }
        function bundleLoad(data, name) {
            BDL.token = data.token; BDL.items = data.items || []; BDL.pages = data.pages; BDL.name = name || data.name || BDL.name || '';
            BDL.results = {}; BDL.expired = false;
            // پیش‌فرض: همه‌ی ردیف‌های آماده انتخاب‌شده
            BDL.items.forEach(it => { it._sel = it.state === 'ready'; it._pay = false; });
            bundleRender();
            bundleShow('result');
        }
        async function bundleRecheck() {
            if (!BDL || !BDL.token) return;
            bundleCollectEdits();
            const keep = {};
            BDL.items.forEach(it => { keep[it.i] = {pn: it._pn, pr: it._pr, pay: it._pay}; });
            document.getElementById('bdl-progress-text').textContent = 'بررسیِ دوباره‌ی وضعیتِ ردیف‌ها…';
            bundleShow('progress');
            let data;
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'bundle_recheck', token: BDL.token})});
                data = await res.json();
            } catch (e) { data = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); if (/منقضی/.test(data.error || '')) bundleReset(); else bundleShow('result'); return; }
            bundleLoad(data);
            BDL.items.forEach(it => { const k = keep[it.i]; if (k) { it._pn = k.pn; it._pr = k.pr; it._pay = k.pay; } });
            bundleRender();
            showToast('وضعیتِ ردیف‌ها به‌روز شد.', 'success');
        }

        function bundleCollectEdits() {
            if (!BDL) return;
            BDL.items.forEach(it => {
                const pn = document.getElementById('bdl-pn-' + it.i), pr = document.getElementById('bdl-pr-' + it.i);
                const sel = document.getElementById('bdl-sel-' + it.i), pay = document.getElementById('bdl-pay-' + it.i);
                if (pn) it._pn = pn.value;
                if (pr) it._pr = pr.value;
                if (sel) it._sel = sel.checked;
                if (pay) it._pay = pay.checked;
                const pf = document.getElementById('bdl-pf-' + it.i);
                if (pf && pf.querySelector('[data-pf]')) { it._f = pfCollect(pf); it._f.policy_num = it._pn !== undefined ? it._pn : it._f.policy_num; if (it._pr !== undefined) it._f.premium = String(it._pr).replace(/[^\d۰-۹]/g, '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); }
                const det = document.getElementById('bdl-det-' + it.i);
                if (det) it._open = det.open;
            });
        }
        function bundleSetFilter(f) { bundleCollectEdits(); BDL.filter = f; bundleRender(); }
        function bundleToggleAll(on) {
            bundleCollectEdits();
            BDL.items.forEach(it => { if (bundleSelectable(it)) it._sel = on; });
            bundleRender();
        }
        function bundleAllPayments(on) { bundleCollectEdits(); BDL.items.forEach(it => { it._pay = on; }); bundleRender(); }
        const bundleSelectable = it => it.state === 'ready' && !(BDL.results[it.i] && BDL.results[it.i].ok);

        function bundleFilterMatch(it) {
            const res = BDL.results[it.i];
            switch (BDL.filter) {
                case 'ready': return it.state === 'ready' && !(res && res.ok);
                case 'not_ready': return it.state === 'not_ready';
                case 'problem': return ['no_match', 'mismatch', 'duplicate'].includes(it.state);
                case 'issued': return it.state === 'issued' || (res && res.ok);
                case 'unknown': return it.state === 'unknown';
                default: return true;
            }
        }

        // مقایسه‌ی فیلد به فیلدِ بیمه‌نامه با ردیفِ درخواست
        function bundleDiffsHtml(it) {
            const diffs = it.diffs || [];
            if (!diffs.length) return '<p class="mt-1.5 text-[10.5px] font-bold text-emerald-700"><i class="fas fa-circle-check ml-1"></i>پلاک/شاسی، نوع و اطلاعاتِ قابلِ مقایسه با درخواست یکی است.</p>';
            const hard = diffs.some(x => x.level === 'hard');
            return `<div class="mt-1.5 rounded-lg border ${hard ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50'} p-2">
                <p class="text-[10.5px] font-black ${hard ? 'text-red-700' : 'text-amber-800'} mb-1"><i class="fas fa-code-compare ml-1"></i>${faDigits(diffs.length)} فیلد با درخواست فرق دارد${hard ? ' — احتمالِ بیمه‌نامه‌ی اشتباه؛ حتماً بررسی کنید' : ''}:</p>
                <div class="grid gap-1">${diffs.map(x => `<div class="text-[10.5px] flex flex-wrap items-center gap-1.5">
                    <span class="font-black ${x.level === 'hard' ? 'text-red-700' : 'text-amber-800'} min-w-[90px]">${bdlEsc(x.label)}</span>
                    <span class="text-slate-500">درخواست:</span><b class="text-slate-700" ${['vin', 'engine'].includes(x.field) ? 'dir="ltr"' : ''}>${x.field === 'plate' && x.row ? formatPlateHtml(x.row) : (['vin', 'engine'].includes(x.field) ? bdlEsc(x.row || '—') : faDigits(bdlEsc(x.row || '—')))}</b>
                    <i class="fas fa-arrow-left text-slate-400 text-[9px]"></i>
                    <span class="text-slate-500">بیمه‌نامه:</span><b class="${x.level === 'hard' ? 'text-red-700' : 'text-amber-900'}" ${['vin', 'engine'].includes(x.field) ? 'dir="ltr"' : ''}>${x.field === 'plate' && /ایران/.test(x.policy) ? formatPlateHtml(x.policy) : (['vin', 'engine'].includes(x.field) ? bdlEsc(x.policy || '—') : faDigits(bdlEsc(x.policy || '—')))}</b>
                </div>`).join('')}</div></div>`;
        }

        function bundleCardHtml(it) {
            const d = it.data || {}, row = it.row, res = BDL.results[it.i];
            const st = BDL_STATE[it.state] || BDL_STATE.unknown;
            const selectable = bundleSelectable(it);
            const done = res && res.ok;
            const pdfLink = file => `${COMPANY_API}?action=bundle_page&token=${BDL.token}&file=${encodeURIComponent(file)}`;
            const pages = it.end > it.page ? `صفحه‌ی ${faDigits(it.page)} تا ${faDigits(it.end)}` : `صفحه‌ی ${faDigits(it.page)}`;
            const pe = it.policy_end || it.page;
            const polPages = pe - it.page + 1, payPages = it.end - pe;
            const prem = it._pr !== undefined ? it._pr : (d.premium ? mfmt(d.premium) : '');
            const pn = it._pn !== undefined ? it._pn : (d.policy_num || '');
            const editable = selectable;
            const val = v => v ? bdlEsc(v) : '<span class="text-slate-300">—</span>';
            let matchHtml = '';
            if (row) {
                const rowStatusCls = ROW_STATUS_COLOR[row.status] || 'bg-slate-100 text-slate-600';
                matchHtml = `<div class="bdl-match">
                    <span class="font-black text-slate-500 text-[10px]"><i class="fas fa-link ml-1"></i>ردیفِ درخواست:</span>
                    ${rowIdentityHtml(row)}
                    <span class="text-[11px] font-bold">${bdlEsc(row.insurance_type_fa || '')}</span>
                    ${row.car_name ? `<span class="text-[10px] text-slate-500">${bdlEsc(row.car_name)}</span>` : ''}
                    <span class="status-badge ${rowStatusCls} text-[10px] font-bold px-2 py-0.5 rounded-full">${bdlEsc(row.status_fa || row.status)}</span>
                    ${row.other_request ? `<span class="bdl-pill bg-violet-100 text-violet-700"><i class="fas fa-share"></i>از درخواستِ دیگرِ همین شرکت (#${faDigits(row.request_id)})</span>` : '<span class="bdl-pill bg-indigo-50 text-indigo-700"><i class="fas fa-check"></i>درخواستش را داریم</span>'}
                    ${it.state === 'not_ready' || row.other_request ? `<button onclick="bundleOpenRow(${row.request_id})" class="text-[10px] font-bold text-blue-700 underline mr-auto">باز کردنِ درخواست برای تکمیلِ مراحل</button>` : ''}
                    <div class="w-full">${bundleDiffsHtml(it)}</div>
                </div>`;
            }
            let missingHtml = '';
            if (it.state === 'not_ready') {
                const steps = (it.missing || []).map(m => `<span class="bdl-pill bg-white border border-amber-200 text-amber-800"><i class="fas fa-xmark text-amber-500"></i>${bdlEsc(m)}</span>`).join(' ');
                missingHtml = `<div class="mt-2 text-[11px] text-amber-800 bg-amber-50 border border-amber-100 rounded-xl p-2">
                    <p class="font-black mb-1"><i class="fas fa-list-check ml-1"></i>مراحلِ باقی‌مانده پیش از صدور:</p>
                    <div class="flex flex-wrap gap-1">${steps || ''}<span class="bdl-pill bg-white border border-amber-200 text-amber-800"><i class="fas fa-arrow-left text-amber-500"></i>رساندنِ ردیف به «آماده‌ی صدور»</span></div></div>`;
            } else if (it.message && !done) {
                const cls = it.state === 'issued' ? 'text-slate-600 bg-slate-50 border-slate-200' : 'text-red-700 bg-red-50 border-red-100';
                missingHtml = `<p class="mt-2 text-[11px] ${cls} border rounded-xl px-3 py-2"><i class="fas fa-circle-info ml-1"></i>${bdlEsc(it.message)}</p>`;
            }
            let resHtml = '';
            if (res) {
                resHtml = res.ok
                    ? `<div class="mt-2 text-[11px] text-emerald-800 bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2">
                        <p class="font-black"><i class="fas fa-circle-check ml-1"></i>صادر شد${res.installments ? ` · ${faDigits(res.installments)} قسط ثبت شد` : ''}</p>
                        <p class="mt-0.5">بایگانی: ${res.folder_status === 'TRANSFERRED' ? '<b>انجام شد</b>' : (res.folder_status === 'FAILED' ? '<b class="text-red-600">ناموفق (از جزئیاتِ درخواست «تلاش دوباره» بزنید)</b>' : bdlEsc(res.folder_status || '—'))}
                        ${res.issued_file ? ` · <a href="../${encodeFilePath(res.issued_file)}" target="_blank" class="text-blue-700 underline">فایلِ بایگانی‌شده</a>` : ''}</p></div>`
                    : `<p class="mt-2 text-[11px] text-red-700 bg-red-50 border border-red-200 rounded-xl px-3 py-2"><i class="fas fa-circle-xmark ml-1"></i>صادر نشد: ${bdlEsc(res.error || 'خطا')}</p>`;
            }
            return `<div class="bdl-card st-${it.state} ${it._sel && selectable ? 'sel' : ''} ${res ? (res.ok ? 'res-ok' : 'res-err') : ''}">
                <div class="pt-0.5">${selectable
                    ? `<input type="checkbox" id="bdl-sel-${it.i}" class="bdl-chk" ${it._sel ? 'checked' : ''} onchange="bundleCollectEdits(); bundleUpdateBar(); this.closest('.bdl-card').classList.toggle('sel', this.checked)">`
                    : `<span class="w-5 h-5 rounded-md flex items-center justify-center ${done ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-300'}" title="${done ? 'صادر شد' : 'قابلِ انتخاب نیست'}"><i class="fas ${done ? 'fa-check' : 'fa-lock'} text-[10px]"></i></span>`}</div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-black text-slate-800 text-sm">بیمه‌نامه‌ی ${faDigits(it.i + 1)}</span>
                        <span class="text-[10px] text-slate-400">${pages}</span>
                        <span class="bdl-pill ${done ? 'bg-emerald-600 text-white' : st[1]}"><i class="fas ${done ? 'fa-circle-check' : st[2]}"></i>${done ? 'صادر و بایگانی شد' : st[0]}</span>
                        ${it.via_ocr ? '<span class="bdl-pill bg-sky-50 text-sky-700"><i class="fas fa-eye"></i>با OCR</span>' : ''}
                        ${BDL.expired ? '' : `<span class="mr-auto flex gap-2 text-[10.5px] font-bold">
                            <a href="${pdfLink(it.file)}" target="_blank" class="text-indigo-700 hover:underline"><i class="fas fa-file-pdf ml-0.5"></i>بیمه‌نامه (${faDigits(polPages)} صفحه)</a>
                            ${it.end > it.page ? `<a href="${pdfLink(it.seg_file)}" target="_blank" class="text-slate-500 hover:underline"><i class="fas fa-file-invoice ml-0.5"></i>همه با پرداخت‌ها (${faDigits(it.end - it.page + 1)} صفحه)</a>` : ''}
                        </span>`}
                    </div>
                    ${it.state === 'unknown' ? '' : `<div class="bdl-kv">
                        <div><span class="k">نوع / بیمه‌گر</span>${val(d.ins_type)} · ${val(d.ins_company)}</div>
                        <div><span class="k">پلاک</span>${d.plate ? formatPlateHtml(d.plate) : val('')}</div>
                        <div><span class="k">شماره شاسی</span><b dir="ltr">${val(d.vin)}</b></div>
                        <div><span class="k">بیمه‌گذار</span>${val(d.insured_name)}</div>
                        <div><span class="k">شماره بیمه‌نامه</span>${editable ? `<input id="bdl-pn-${it.i}" dir="ltr" value="${bdlEsc(pn)}">` : `<b dir="ltr">${val(pn)}</b>`}</div>
                        <div><span class="k">حق بیمه (ریال)</span>${editable ? `<input id="bdl-pr-${it.i}" class="money-input" dir="ltr" inputmode="numeric" value="${bdlEsc(prem)}">` : `<b>${d.premium ? money(d.premium) : val('')}</b>`}</div>
                        ${d.national_id ? `<div><span class="k">کد / شناسه ملی</span><b dir="ltr">${faDigits(bdlEsc(d.national_id))}</b></div>` : ''}
                        ${d.phone ? `<div><span class="k">تلفن بیمه‌گذار</span><b dir="ltr">${faDigits(bdlEsc(d.phone))}</b></div>` : ''}
                        ${d.central_no ? `<div><span class="k">کد یکتای بیمه مرکزی</span><b dir="ltr">${faDigits(bdlEsc(d.central_no))}</b></div>` : ''}
                        ${d.car_name || d.model_year ? `<div><span class="k">خودرو</span>${bdlEsc([d.car_kind, d.car_name].filter(Boolean).join(' · '))}${d.model_year ? ` <span class="text-slate-400">مدل ${faDigits(d.model_year)}</span>` : ''}</div>` : ''}
                        ${d.engine_no ? `<div><span class="k">شماره موتور</span><b dir="ltr">${bdlEsc(d.engine_no)}</b></div>` : ''}
                        ${d.start_date ? `<div><span class="k">مدت بیمه</span>${faDigits(d.start_date)}${d.end_date ? ` تا ${faDigits(d.end_date)}` : ''}</div>` : ''}
                        ${d.car_value ? `<div><span class="k">ارزش خودرو (ریال)</span>${money(d.car_value)}</div>` : ''}
                        ${d.total_value && d.total_value !== d.car_value ? `<div><span class="k">جمعِ سرمایه (ریال)</span>${money(d.total_value)}</div>` : ''}
                        ${d.liability ? `<div><span class="k">تعهد مالی (ریال)</span>${money(d.liability)}</div>` : ''}
                        ${d.diya ? `<div><span class="k">تعهد بدنی / دیه (ریال)</span>${money(d.diya)}</div>` : ''}
                        ${d.driver_cover ? `<div><span class="k">حوادث راننده (ریال)</span>${money(d.driver_cover)}</div>` : ''}
                        ${d.capacity || d.cylinders ? `<div><span class="k">ظرفیت / سیلندر</span>${faDigits(bdlEsc([d.capacity, d.cylinders ? d.cylinders + ' سیلندر' : ''].filter(Boolean).join(' · ')))}</div>` : ''}
                        ${d.address ? `<div style="grid-column:1/-1"><span class="k">نشانی بیمه‌گذار</span>${faDigits(bdlEsc(d.address))}${d.postal_code ? ` <span class="text-slate-400">(کد پستی ${faDigits(bdlEsc(d.postal_code))})</span>` : ''}</div>` : ''}
                        ${d.prev_expiry ? `<div><span class="k">انقضای بیمه‌نامه‌ی قبلی</span>${faDigits(d.prev_expiry)}${d.prev_insurer ? ` <span class="text-slate-400">(${bdlEsc(d.prev_insurer)})</span>` : ''}</div>` : ''}
                        ${d.issue_date ? `<div><span class="k">تاریخ صدور</span>${faDigits(d.issue_date)}</div>` : ''}
                    </div>
                    ${(it.missing_fields || []).length ? `<p class="mt-2 text-[11px] text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-1.5"><i class="fas fa-triangle-exclamation ml-1"></i>از روی بیمه‌نامه خوانده نشد: <b>${it.missing_fields.map(bdlEsc).join('، ')}</b> — پیش‌نمایش را ببینید${editable ? ' و در صورت نیاز دستی وارد کنید' : ''}.</p>` : ''}`}
                    ${(it.receipts || []).length ? `<div class="mt-2 text-[10.5px] text-indigo-700 font-bold"><i class="fas fa-receipt ml-1"></i>${faDigits(it.receipts.length)} فیشِ قسط پیدا شد (جمع ${money(it.receipts.reduce((a, r) => a + (Number(r.amount) || 0), 0))} ریال)؛ با صدور، هر فیش روی ردیفِ قسطِ خودش می‌نشیند و سررسیدها از تاریخ صدورِ ${faDigits(d.issue_date || '—')} حساب می‌شود.
                        <div class="flex flex-wrap gap-1 mt-1 font-normal">${it.receipts.map((r, k) => `<a href="${pdfLink(r.file)}" target="_blank" class="bg-white border border-indigo-100 rounded-full px-2 py-0.5 hover:bg-indigo-50">قسط ${faDigits(k + 1)}: ${faDigits(r.date || '—')}</a>`).join('')}</div></div>` : ''}
                    ${editable ? `<details id="bdl-det-${it.i}" class="mt-2 rounded-xl border border-teal-100 bg-teal-50/30" ${it._open ? 'open' : ''}>
                        <summary class="cursor-pointer select-none px-3 py-1.5 text-[11px] font-black text-teal-800"><i class="fas fa-pen-to-square ml-1"></i>بررسی و ویرایشِ همه‌ی فیلدهای خوانده‌شده</summary>
                        <div id="bdl-pf-${it.i}" class="p-2"></div></details>` : ''}
                    ${editable && payPages > 0 ? `<label class="mt-2 inline-flex items-center gap-1.5 text-[10.5px] text-slate-600 cursor-pointer"><input type="checkbox" id="bdl-pay-${it.i}" ${it._pay ? 'checked' : ''} onchange="bundleCollectEdits()" class="accent-indigo-600">صفحه‌های پرداخت و فیش (${faDigits(payPages)} صفحه) هم در فایلِ بیمه‌نامه‌ی بایگانی بیاید</label>` : ''}
                    ${matchHtml}${missingHtml}${resHtml}
                </div>
            </div>`;
        }

        function bundleRender() {
            const items = BDL.items, cnt = {};
            items.forEach(it => { cnt[it.state] = (cnt[it.state] || 0) + 1; });
            const issuedNow = Object.values(BDL.results).filter(r => r.ok).length;
            const detected = items.length - (cnt.unknown || 0);
            const stat = (label, n, cls) => `<div class="bdl-stat ${cls}"><p>${label}</p><p>${e2pNum(n)}</p></div>`;
            document.getElementById('bdl-sub').textContent = `${BDL.name || 'فایل'} · ${faDigits(BDL.pages || 0)} صفحه · ${faDigits(items.length)} بیمه‌نامه پیدا شد`;
            const fbtn = (k, label, n) => `<button class="${BDL.filter === k ? 'on' : ''}" onclick="bundleSetFilter('${k}')">${label} <span class="opacity-70">${e2pNum(n)}</span></button>`;
            const list = items.filter(bundleFilterMatch);
            document.getElementById('bdl-result').innerHTML = `
                <div class="bdl-stats mb-3">
                    ${stat('شناسایی‌شده', detected, 'bg-indigo-50 border-indigo-100 text-indigo-800')}
                    ${stat('شناسایی‌نشده', cnt.unknown || 0, (cnt.unknown ? 'bg-red-50 border-red-100 text-red-700' : 'bg-slate-50 border-slate-100 text-slate-500'))}
                    ${stat('آماده‌ی صدور', Math.max(0, (cnt.ready || 0) - issuedNow), 'bg-emerald-50 border-emerald-100 text-emerald-800')}
                    ${stat('نرسیده به صدور', cnt.not_ready || 0, 'bg-amber-50 border-amber-100 text-amber-800')}
                    ${stat('بدونِ درخواست / مغایرت', (cnt.no_match || 0) + (cnt.mismatch || 0) + (cnt.duplicate || 0), 'bg-rose-50 border-rose-100 text-rose-700')}
                    ${stat('صادرشده', (cnt.issued || 0) + issuedNow, 'bg-slate-50 border-slate-200 text-slate-700')}
                </div>
                ${cnt.unknown ? `<p class="text-[11px] text-red-700 bg-red-50 border border-red-100 rounded-xl px-3 py-2 mb-3"><i class="fas fa-triangle-exclamation ml-1"></i>${e2pNum(cnt.unknown)} بیمه‌نامه شناسایی نشد؛ پیش‌نمایشِ صفحه‌اش را ببینید و در صورتِ نیاز از «ثبت صدور»ِ همان ردیف دستی صادر کنید.</p>` : ''}
                <div class="bdl-filters flex flex-wrap gap-1.5 mb-3">
                    ${fbtn('all', 'همه', items.length)}
                    ${fbtn('ready', 'آماده', Math.max(0, (cnt.ready || 0) - issuedNow))}
                    ${fbtn('not_ready', 'نرسیده به صدور', cnt.not_ready || 0)}
                    ${fbtn('problem', 'مشکل‌دار', (cnt.no_match || 0) + (cnt.mismatch || 0) + (cnt.duplicate || 0))}
                    ${fbtn('issued', 'صادرشده', (cnt.issued || 0) + issuedNow)}
                    ${fbtn('unknown', 'شناسایی‌نشده', cnt.unknown || 0)}
                </div>
                <div class="flex flex-col gap-2.5">${list.map(bundleCardHtml).join('') || '<p class="text-center text-xs text-slate-400 py-8">موردی در این دسته نیست.</p>'}</div>
                <div class="bdl-bar">
                    <div class="flex flex-wrap items-center gap-3 text-[11px]">
                        <label class="inline-flex items-center gap-1.5 font-bold cursor-pointer"><input type="checkbox" id="bdl-all" class="accent-indigo-600" onchange="bundleToggleAll(this.checked)">انتخابِ همه‌ی آماده‌ها</label>
                        <label class="inline-flex items-center gap-1.5 text-slate-600 cursor-pointer"><input type="checkbox" id="bdl-allpay" class="accent-indigo-600" onchange="bundleAllPayments(this.checked)" ${items.length && items.every(it => it._pay) ? 'checked' : ''}>همه با صفحه‌های پرداخت</label>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button onclick="bundleReset()" class="text-[11px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-2 rounded-xl"><i class="fas fa-file-arrow-up ml-1"></i>فایلِ دیگر</button>
                        ${BDL.expired ? '' : `<button onclick="bundleRecheck()" class="text-[11px] font-bold bg-amber-50 hover:bg-amber-100 text-amber-800 px-3 py-2 rounded-xl"><i class="fas fa-rotate ml-1"></i>بررسیِ دوباره‌ی وضعیت‌ها</button>`}
                        <button id="bdl-issue-btn" onclick="bundleIssue()" class="text-[12px] font-black text-white px-4 py-2 rounded-xl disabled:opacity-40" style="background:linear-gradient(120deg,#059669,#0d9488)"><i class="fas fa-stamp ml-1"></i><span id="bdl-issue-label">صدورِ انتخاب‌شده‌ها</span></button>
                    </div>
                </div>`;
            // فرمِ نسلِ جدید برای هر بیمه‌نامه‌ی قابلِ صدور؛ شماره و حق بیمه با خانه‌های بالای کارت هم‌گام‌اند
            list.filter(bundleSelectable).forEach(it => {
                const box = document.getElementById('bdl-pf-' + it.i);
                if (!box) return;
                const o = Object.assign({}, it.data || {}, it._f || {});
                if (it._pn !== undefined) o.policy_num = it._pn;
                if (it._pr !== undefined) o.premium = String(it._pr).replace(/[^\d۰-۹]/g, '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
                pfRender(box, o, {});
                [['policy_num', 'bdl-pn-'], ['premium', 'bdl-pr-']].forEach(([k, pre]) => {
                    const a = box.querySelector(`input[data-pf="${k}"]`), b = document.getElementById(pre + it.i);
                    if (!a || !b) return;
                    a.addEventListener('input', () => { b.value = a.value; });
                    b.addEventListener('input', () => { a.value = b.value; a.closest('.ii-w').classList.toggle('pf-miss', !a.value.trim()); });
                });
            });
            bundleUpdateBar();
        }
        function bundleUpdateBar() {
            const sel = BDL.items.filter(it => bundleSelectable(it) && (document.getElementById('bdl-sel-' + it.i) ? document.getElementById('bdl-sel-' + it.i).checked : it._sel));
            const selectable = BDL.items.filter(bundleSelectable);
            const btn = document.getElementById('bdl-issue-btn');
            if (!btn) return;
            btn.disabled = !sel.length;
            document.getElementById('bdl-issue-label').textContent = sel.length ? `صدور و بایگانیِ ${faDigits(sel.length)} بیمه‌نامه` : 'موردی انتخاب نشده';
            const all = document.getElementById('bdl-all');
            all.checked = selectable.length > 0 && sel.length === selectable.length;
            all.disabled = !selectable.length;
        }
        function bundleOpenRow(requestId) {
            bundleCollectEdits();
            document.getElementById('bundle-modal').classList.remove('active');
            openCompanyRequestDetail(requestId);
            showToast('بعد از تکمیلِ مراحل، دوباره «صدور گروهی» را بزنید و «بررسیِ دوباره» را انتخاب کنید.', 'info');
        }

        async function bundleIssue() {
            bundleCollectEdits();
            const picks = BDL.items.filter(it => bundleSelectable(it) && it._sel);
            if (!picks.length) return;
            const empty = picks.filter(it => !String(it._pn !== undefined ? it._pn : (it.data && it.data.policy_num) || '').trim());
            if (empty.length) { showToast(`شماره‌ی بیمه‌نامه‌ی ${empty.map(it => faDigits(it.i + 1)).join('، ')} خالی است.`, 'error'); return; }
            const hardN = picks.filter(it => (it.diffs || []).some(x => x.level === 'hard')).length;
            const softN = picks.filter(it => (it.diffs || []).length && !(it.diffs || []).some(x => x.level === 'hard')).length;
            const warn = (hardN ? `\n⚠ ${faDigits(hardN)} مورد مغایرتِ مهم (پلاک/شاسی/نوع) با درخواست دارد!` : '') + (softN ? `\n${faDigits(softN)} مورد مغایرتِ جزئی دارد.` : '');
            if (!(await uiConfirm('صدور و بایگانی', `${faDigits(picks.length)} بیمه‌نامه صادر و بایگانی شود؟${warn}`, {ok: 'بله، صادر شود', danger: hardN > 0}))) return;
            const payload = picks.map(it => ({
                i: it.i,
                policy_number: it._pn !== undefined ? it._pn : (it.data.policy_num || ''),
                total_premium: it._pr !== undefined ? it._pr : (it.data.premium || ''),
                vin: (it._f && it._f.vin !== undefined ? it._f.vin : it.data.vin) || '',
                fields: it._f || undefined,
                with_payments: !!it._pay,
            }));
            const btn = document.getElementById('bdl-issue-btn');
            btn.disabled = true;
            document.getElementById('bdl-issue-label').textContent = 'در حالِ صدور و بایگانی…';
            let data;
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'bundle_issue', token: BDL.token, items: payload})});
                data = await res.json();
            } catch (e) { data = {ok: false, error: 'خطا در ارتباط با سرور. وضعیت را با «بررسیِ دوباره» کنترل کنید.'}; }
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); bundleUpdateBar(); return; }
            (data.results || []).forEach(r => {
                BDL.results[r.i] = r;
                const it = BDL.items.find(x => x.i === r.i);
                if (r.ok && it && it.row) { it.row.status = 'ISSUED'; it.row.status_fa = 'صادر شد'; }
            });
            const okN = (data.results || []).filter(r => r.ok).length, badN = (data.results || []).length - okN;
            showToast(`${faDigits(okN)} بیمه‌نامه صادر و بایگانی شد${badN ? ` · ${faDigits(badN)} مورد ناموفق` : ''}.`, badN ? 'warning' : 'success');
            if (!data.left) BDL.expired = true;   // فایل‌های موقت پاک شدند؛ «بررسیِ دوباره» دیگر لازم نیست
            bundleRender();
            if (typeof loadCompanyRequests === 'function') loadCompanyRequests();
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
            document.getElementById('cre-expiry').value = faDigits(p.expiry_date_jalali);
            document.getElementById('cre-has-prev-body').value = p.has_prev_body || '';
            document.getElementById('cre-skip-health').checked = !!Number(p.skip_health_inspection);
            fillCancelReasons('cre', p.cancellation_reason || '');
            document.getElementById('cre-refpolicy').value = p.ref_policy_number || '';
            document.getElementById('cre-endorse').value = p.endorsement_request || '';
            applyKindFieldVisibility('cre');
            document.getElementById('cre-chassis').value = p.chassis_no || '';
            document.getElementById('cre-engine').value = p.engine_no || '';
            document.getElementById('cre-carvalue').value = mfmt(p.car_value);
            document.getElementById('cre-liability').value = mfmt(p.liability_limit);
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
            // ستون‌ها بر اساسِ نوعِ ردیف‌ها: ثالث ← تعهد مالی؛ بدنه ← ارزش، بدنه‌ی قبل، بازدید؛ هر دو ← همه
            const isEnd = x => !!(x.cancellation_reason || x.endorsement_request);
            const hasThird = rows.some(x => x.insurance_type === 'THIRDPARTY');
            const hasBody = rows.some(x => x.insurance_type === 'BODY');
            const hasEnd = rows.some(isEnd);
            const any = k => rows.some(x => x[k]);
            const dash = '<span class="text-slate-300">—</span>';
            const cols = [
                ['درخواست', x => x.cancellation_reason ? '<span class="text-rose-600 font-bold">فسخ</span>' : (x.endorsement_request ? '<span class="text-violet-600 font-bold">تغییر اطلاعات</span>' : 'صدور')],
                ['پلاک / شاسی', x => rowIdentityHtml(x) + (x.plate_p1 && x.chassis_no ? `<p class="text-[9.5px] text-slate-400" dir="ltr">${x.chassis_no}</p>` : '')],
                ['نوع', x => `<span class="font-bold ${x.insurance_type === 'BODY' ? 'text-cyan-700' : 'text-blue-700'}">${x.insurance_type_fa}</span>`],
                ['انقضا', x => faDigits(x.expiry_date_jalali) || (Number(x.is_new_vehicle) ? 'صفر کیلومتر' : dash)],
                ['خودرو', x => (x.car_name || dash) + (x.model_year ? ` <span class="text-slate-400">مدل ${faDigits(x.model_year)}</span>` : '')],
            ];
            if (hasThird) cols.push(['تعهد مالی (ثالث)', x => x.insurance_type === 'THIRDPARTY' ? (x.liability_limit ? money(x.liability_limit) : '<span class="text-amber-600">نامشخص</span>') : dash]);
            if (hasBody) {
                cols.push(['ارزش خودرو (بدنه)', x => x.insurance_type === 'BODY' ? (x.car_value ? money(x.car_value) : '<span class="text-amber-600">نامشخص</span>') : dash]);
                cols.push(['بدنه‌ی قبل', x => x.insurance_type === 'BODY' ? (x.has_prev_body === 'YES' ? 'دارد' : (x.has_prev_body === 'NO' ? 'ندارد' : dash)) : dash]);
                cols.push(['بازدید سلامت', x => x.insurance_type === 'BODY' ? (Number(x.skip_health_inspection) ? 'لازم نیست' : 'لازم') : dash]);
            }
            if (any('ref_policy_number')) cols.push([hasEnd ? 'بیمه‌نامه‌ی مرجع / قبلی' : 'بیمه‌نامه‌ی قبلی', x => x.ref_policy_number ? `<span dir="ltr">${faDigits(x.ref_policy_number)}</span>` : dash]);
            if (hasEnd) cols.push(['خواسته / دلیل', x => x.endorsement_request || x.cancellation_reason || dash]);
            if (any('row_note')) cols.push(['توضیح', x => x.row_note ? `<span class="whitespace-normal">${x.row_note}</span>` : dash]);
            const tc = rows.reduce((a, x) => { a[x.insurance_type] = (a[x.insurance_type] || 0) + 1; return a; }, {});
            box.innerHTML = `
                ${(data.errors || []).length ? `<div class="bg-amber-50 border border-amber-200 rounded-lg p-2 mb-2 text-[10px] text-amber-700">${data.errors.map(x => '• ' + x).join('<br>')}</div>` : ''}
                ${data.counts_fa ? `<p class="text-[11px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-2 py-1.5 mb-2">تعدادِ درخواستیِ نامه: ${data.counts_fa}</p>` : ''}
                <p class="text-xs font-bold text-slate-600 mb-1">${e2pNum(rows.length)} ردیف شناسایی شد${tc.THIRDPARTY ? `، ${e2pNum(tc.THIRDPARTY)} ثالث` : ''}${tc.BODY ? `، ${e2pNum(tc.BODY)} بدنه` : ''}. پلاک‌ها را با نامه مقایسه کنید:</p>
                <div class="max-h-64 overflow-auto border border-slate-100 rounded-lg">
                    <table class="w-full text-right text-[10px] no-count">
                        <thead class="bg-slate-50 text-slate-500 sticky top-0"><tr>${cols.map(c => `<th class="p-1.5 whitespace-nowrap">${c[0]}</th>`).join('')}</tr></thead>
                        <tbody>${rows.map(x => `<tr class="border-t border-slate-100">${cols.map(c => `<td class="p-1.5 whitespace-nowrap">${c[1](x)}</td>`).join('')}</tr>`).join('')}</tbody>
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
                <p class="text-[11px] font-bold text-slate-700 break-words" ${opts.ltr ? 'dir="ltr"' : ''}>${opts.plate ? formatPlateHtml(value) : (opts.date ? faDigits(value) : value)}
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
            tbody.innerHTML = '<tr><td colspan="14" class="p-8 text-center text-slate-400">در حال بارگذاری...</td></tr>';
            const payload = {
                action: 'issue_queue',
                q: document.getElementById('iq-q').value.trim(),
                source: document.getElementById('iq-source').value,
                company_id: document.getElementById('iq-company').value,
                readiness: document.getElementById('iq-readiness').value,
                missing_doc: document.getElementById('iq-missing').value,
                insurance_type: document.getElementById('iq-type').value,
                request_kind: document.getElementById('iq-kind').value,
                stage: document.getElementById('iq-stage').value,
                expiry_from: document.getElementById('iq-exp-from').value.trim(),
                expiry_to: document.getElementById('iq-exp-to').value.trim(),
                req_month: (document.getElementById('iq-month') || {}).value || '',
                insurer: (document.getElementById('iq-insurer') || {}).value || '',
                expiry_quick: iqExpQuick,
            };
            window._iqLastPayload = payload;
            jMonthOptions(document.getElementById('iq-month'), 'همه‌ی ماه‌ها (ثبت درخواست)');
            let data;
            try {
                data = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)})).json();
            } catch (e) { tbody.innerHTML = '<tr><td colspan="14" class="p-8 text-center text-red-500">خطا در ارتباط با سرور.</td></tr>'; return; }
            if (!data.ok) { tbody.innerHTML = `<tr><td colspan="14" class="p-8 text-center text-red-500">${data.error || 'خطا'}</td></tr>`; return; }

            issueQueueCache = data.rows || [];
            document.querySelectorAll('.iss-n-queue').forEach(el => { el.textContent = data.counts.total ? e2pNum(data.counts.total) : ''; });
            document.getElementById('iq-c-total').textContent = e2pNum(data.counts.total);
            document.getElementById('iq-c-ready').textContent = e2pNum(data.counts.ready);
            document.getElementById('iq-c-waiting').textContent = e2pNum(data.counts.waiting);

            if (!issueQueueCache.length) { tbody.innerHTML = '<tr><td colspan="14" class="p-8 text-center text-slate-400">ردیفی با این فیلترها پیدا نشد.</td></tr>'; return; }

            tbody.innerHTML = issueQueueCache.map((r, i) => {
                const missing = Object.values(r.missing_docs || {});
                // رنگِ هشدارِ نزدیکیِ انقضا
                const d = r.days_to_expiry;
                const expCls = d === null ? 'text-slate-400' : (d < 0 ? 'text-red-600 font-bold' : (d <= 7 ? 'text-amber-600 font-bold' : 'text-slate-600'));
                const expNote = d === null ? '' : (d < 0 ? `(${e2pNum(Math.abs(d))} روز گذشته)` : `(${e2pNum(d)} روز مانده)`);
                const isP = r.source === 'PERSONNEL';
                // وضعیتِ پرونده‌ی کارکنان با همان برچسب‌های بخش «صدور بیمه‌نامه» نوشته می‌شود
                const statusFa = isP ? (CASE_STATUS_FA[r.status] || r.status) : r.status_fa;
                const statusCls = isP ? 'bg-slate-100 text-slate-600' : (ROW_STATUS_COLOR[r.status] || 'bg-slate-100 text-slate-600');
                const dash = '<span class="text-slate-300">—</span>';
                return `
                <tr class="border-t border-slate-100 hover:bg-teal-50/40 cursor-pointer" onclick="openIssueQueueDetail('${r.source}', ${r.id})">
                    <td class="p-3 text-slate-400">${e2pNum(i + 1)}</td>
                    <td class="p-3"><span class="text-[10px] font-bold px-2 py-1 rounded-full ${isP ? 'bg-blue-100 text-blue-700' : 'bg-indigo-100 text-indigo-700'}">${r.source_fa}</span></td>
                    <td class="p-3 font-bold">${r.company_name || dash}</td>
                    <td class="p-3">${r.insured_name || dash}${r.relationship && r.relationship !== 'خودم' ? `<p class="text-[9px] text-slate-400">${r.relationship}</p>` : ''}</td>
                    <td class="p-3">${r.holder_name || dash}</td>
                    <td class="p-3" dir="ltr">${r.personnel_code ? e2p(r.personnel_code) : dash}</td>
                    <td class="p-3">${isP ? `<span class="plate-display font-bold">${formatPlateHtml(r.plate_display)}</span>` : rowIdentityHtml(r)}
                        ${r.car_name ? `<p class="text-[10px] text-slate-400">${r.car_name}</p>` : ''}</td>
                    <td class="p-3">${r.insurance_type_fa}
                        ${r.request_kind !== 'NEW_POLICY' ? `<p class="text-[10px] ${r.request_kind === 'ENDORSEMENT' ? 'text-violet-600' : 'text-rose-600'}">${r.request_kind_fa}</p>` : ''}</td>
                    <td class="p-3 text-slate-500">${faDigits(r.period_title) || dash}</td>
                    <td class="p-3 text-slate-500">${faDigits(r.request_date_jalali)}</td>
                    <td class="p-3 ${expCls}">${faDigits(r.expiry_date_jalali) || (Number(r.is_new_vehicle) ? 'صفر کیلومتر' : dash)}
                        ${expNote ? `<span class="block text-[9px]">${expNote}</span>` : ''}</td>
                    <td class="p-3">${r.is_ready
                        ? '<span class="bg-emerald-100 text-emerald-700 text-[10px] font-bold px-2 py-1 rounded-full">✓ مدارک کامل</span>'
                        : `<span class="bg-amber-100 text-amber-700 text-[10px] font-bold px-2 py-1 rounded-full">کم دارد</span>
                           <p class="text-[9px] text-amber-600 mt-1">${missing.join('، ')}</p>`}</td>
                    <td class="p-3"><span class="status-badge ${statusCls} text-[10px] font-bold px-2 py-1 rounded-full">${statusFa}</span></td>
                    <td class="p-3"><span class="text-blue-600 font-bold">مشاهده و صدور</span></td>
                </tr>`;
            }).join('');
        }

        // انتخابِ سریعِ بازه‌ی انقضا و پاک‌کردنِ فیلترها
        let iqExpQuick = '';
        function setIqExpQuick(btn) {
            iqExpQuick = btn.dataset.v || '';
            document.querySelectorAll('#iq-expq button[data-v]').forEach(b => b.classList.toggle('on', b === btn));
            loadIssueQueue();
        }
        function resetIssueQueueFilters() {
            ['iq-q', 'iq-exp-from', 'iq-exp-to'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
            ['iq-company', 'iq-readiness', 'iq-missing', 'iq-type', 'iq-kind', 'iq-stage', 'iq-month', 'iq-insurer'].forEach(id => { const el = document.getElementById(id); if (el) el.selectedIndex = 0; });
            const all = document.querySelector('#iq-expq button[data-v=""]');
            iqExpQuick = '';
            document.querySelectorAll('#iq-expq button[data-v]').forEach(b => b.classList.toggle('on', b === all));
            loadIssueQueue();
        }
        // خروجی اکسلِ «در حال صدور» با همان فیلترهای فعلی
        function exportIssueQueueExcel() {
            const p = Object.assign({}, window._iqLastPayload || {action: 'issue_queue'}, {export: '1'});
            Object.keys(p).forEach(k => { if (p[k] === '' || p[k] === null || p[k] === undefined) delete p[k]; });
            window.location.href = COMPANY_API + '?' + new URLSearchParams(p).toString();
            showToast('فایل اکسل در حال آماده‌سازی است...', 'success');
        }

        // ===================== صدور گروهی (فهرستِ درخواست‌ها) =====================
        let igCompaniesLoaded = false;
        async function loadIssueGroup() {
            const body = document.getElementById('ig-body');
            if (!body) return;
            if (!igCompaniesLoaded) {
                igCompaniesLoaded = true;
                fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_companies'})}).then(r => r.json()).then(d => {
                    if (d.ok) document.getElementById('ig-company').innerHTML = '<option value="">همه‌ی شرکت‌ها</option>' + (d.companies || []).map(c => `<option value="${c.id}">${c.name}</option>`).join('');
                }).catch(() => { igCompaniesLoaded = false; });
            }
            body.innerHTML = '<p class="col-span-full text-center text-xs text-slate-400 py-10">در حال بارگذاری...</p>';
            let d;
            try {
                d = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({
                    action: 'group_issue_requests', q: document.getElementById('ig-q').value.trim(),
                    company_id: document.getElementById('ig-company').value, insurer: document.getElementById('ig-insurer').value})})).json();
            } catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
            if (!d.ok) { body.innerHTML = `<p class="col-span-full text-center text-xs text-red-500 py-10">${d.error || 'خطا'}</p>`; return; }
            const rows = d.rows || [];
            const sum = k => rows.reduce((a, r) => a + Number(r[k] || 0), 0);
            document.getElementById('ig-c-req').textContent = e2pNum(rows.length);
            document.getElementById('ig-c-rows').textContent = e2pNum(sum('open_rows'));
            document.getElementById('ig-c-ready').textContent = e2pNum(sum('ready_rows'));
            document.querySelectorAll('.iss-n-group').forEach(el => { el.textContent = rows.length ? e2pNum(rows.length) : ''; });
            if (!rows.length) { body.innerHTML = '<div class="col-span-full card p-10 text-center text-slate-400 text-sm"><i class="fas fa-circle-check text-3xl text-emerald-300 mb-2 block"></i>درخواستِ بازی که ردیفِ صادرنشده داشته باشد نیست.</div>'; return; }
            body.innerHTML = rows.map(r => {
                const dte = r.days_to_expiry;
                const expCls = dte === null ? 'bg-slate-100 text-slate-500' : (dte < 0 ? 'bg-red-100 text-red-700' : (dte <= 15 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-50 text-emerald-700'));
                const ready = Number(r.ready_rows || 0), open = Number(r.open_rows || 0);
                return `<div class="card p-4 hover:shadow-lg transition">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0"><p class="font-black text-slate-800 truncate">${r.company_name}</p>
                            <p class="text-[11px] text-slate-400">درخواست ${r.ref_code ? 'شناسه‌ی ' + e2pNum(r.ref_code) : '#' + e2pNum(r.id)} · ${r.request_kind_fa} · ${r.insurer === 'IRAN' ? 'بیمه ایران' : 'بیمه پاسارگاد'} · ${faDigits(r.created_jalali)}</p></div>
                        <span class="text-[10px] font-bold px-2 py-1 rounded-full whitespace-nowrap ${expCls}">${dte === null ? 'بدون انقضا' : (dte < 0 ? `${e2pNum(-dte)} روز گذشته` : `نزدیک‌ترین انقضا: ${e2pNum(dte)} روز`)}</span>
                    </div>
                    <div class="grid grid-cols-4 gap-1.5 my-3 text-center">
                        <div class="rounded-lg bg-slate-50 p-1.5"><p class="text-[9px] text-slate-500">صادرنشده</p><p class="font-black text-slate-700">${e2pNum(open)}</p></div>
                        <div class="rounded-lg bg-emerald-50 p-1.5"><p class="text-[9px] text-emerald-700">آماده</p><p class="font-black text-emerald-700">${e2pNum(ready)}</p></div>
                        <div class="rounded-lg bg-cyan-50 p-1.5"><p class="text-[9px] text-cyan-700">بدنه</p><p class="font-black text-cyan-800">${e2pNum(r.body_rows)}</p></div>
                        <div class="rounded-lg bg-blue-50 p-1.5"><p class="text-[9px] text-blue-700">ثالث</p><p class="font-black text-blue-800">${e2pNum(r.third_rows)}</p></div>
                    </div>
                    <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden mb-3"><div class="h-full bg-gradient-to-l from-emerald-500 to-teal-400" style="width:${open ? Math.round(ready * 100 / open) : 0}%"></div></div>
                    <div class="flex gap-2">
                        <button onclick="openBundleModal(${r.id}, 'group')" class="flex-1 text-[12px] font-black text-white px-3 py-2 rounded-xl" style="background:linear-gradient(120deg,#4338ca,#7c3aed)"><i class="fas fa-layer-group ml-1"></i>صدور گروهی</button>
                        <button onclick="openCompanyRequestDetail(${r.id})" class="text-[11px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-2 rounded-xl">جزئیات</button>
                    </div>
                </div>`;
            }).join('');
        }

        // =================================================================
        //  «اطلاعات صدور»: مالک، بیمه‌گذار، مشخصاتِ کاملِ خودرو و بیمه‌نامه‌ی درخواستی؛
        //  همه قابلِ کپی (تکی یا یک‌جا) و قابلِ تکمیل/ذخیره، برای واردکردن در سایتِ بیمه‌گر
        // =================================================================
        const iiEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        let IIROW = null;
        function iiDefaults(r) {
            const ii = Object.assign({}, r.issue_info || {});
            const isP = r.source === 'PERSONNEL';
            const pick = (k, v) => { if (!ii[k] && v) ii[k] = String(v); };
            pick('insured_name', isP ? r.insured_name : r.company_name);
            pick('insured_national_id', isP ? r.national_id : '');
            pick('insured_phone', isP ? (r.insured_phone || r.mobile_number) : r.company_phone);
            pick('insured_address', isP ? r.insured_address : r.company_address);
            pick('insured_postal_code', r.insured_postal_code);
            pick('insured_birth_date', r.insured_birth_date);
            pick('car_system', r.car_system); pick('car_type', r.car_type); pick('car_model_year', r.car_model_year);
            pick('car_color', r.car_color); pick('car_usage', r.car_usage);
            pick('chassis_no', r.chassis_no); pick('engine_no', r.engine_no); pick('vin', r.vin);
            pick('no_claim_years', r.no_claim_years);
            return ii;
        }
        function iiField(k, label, v, opts = {}) {
            return `<div class="ii-f ${opts.wide ? 'col-span-2' : ''}"><label>${label}</label><div class="ii-w">
                <input data-ii="${k}" value="${iiEsc(v || '')}" ${opts.ltr ? 'dir="ltr"' : ''} placeholder="${iiEsc(opts.ph || '')}">
                <button type="button" title="کپی" onclick="iiCopyOne(this)"><i class="far fa-copy"></i></button></div></div>`;
        }
        function iiRo(label, v, opts = {}) {
            if (v === null || v === undefined || v === '') v = '';
            const shown = opts.html ? v : iiEsc(opts.digits ? faDigits(v) : v);
            return `<div class="ii-f"><label>${label}</label><div class="ii-ro"><span ${opts.ltr ? 'dir="ltr"' : ''}>${shown || '<span class="text-slate-300">—</span>'}</span>
                ${v ? `<button type="button" class="text-slate-300 hover:text-teal-600" title="کپی" onclick="copyValue('${String(opts.copy || v).replace(/'/g, '').replace(/<[^>]*>/g, '')}', '${label}')"><i class="far fa-copy text-[11px]"></i></button>` : ''}</div></div>`;
        }
        function issueInfoCardHtml(r) {
            IIROW = r;
            const ii = iiDefaults(r);
            const isP = r.source === 'PERSONNEL';
            const plate = r.plate_display || '';
            const plateHtml = /ایران/.test(plate) ? formatPlateHtml(plate) : iiEsc(plate);
            const kindLine = [r.insurance_type_fa, r.request_kind_fa && r.request_kind !== 'NEW_POLICY' ? r.request_kind_fa : ''].filter(Boolean).join(' · ');
            return `
            <div class="space-y-3 mb-4" id="ii-card">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <h4 class="text-sm font-black text-slate-700"><i class="fas fa-clipboard-list text-teal-500 ml-1"></i>اطلاعات برای صدور</h4>
                    <div class="flex gap-2">
                        <button type="button" onclick="iiCopyAll()" class="text-[11px] font-bold bg-teal-50 hover:bg-teal-100 text-teal-700 px-3 py-1.5 rounded-xl"><i class="far fa-copy ml-1"></i>کپیِ همه‌ی اطلاعات</button>
                        <button type="button" onclick="iiSave()" class="text-[11px] font-bold bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-xl"><i class="fas fa-save ml-1"></i>ذخیره‌ی تغییرات</button>
                    </div>
                </div>
                <div class="ii-sec" style="background:linear-gradient(180deg,#f0fdfa,#fff)">
                    <h5><i class="fas fa-file-shield text-teal-600"></i>بیمه‌نامه‌ی درخواستی</h5>
                    <div class="ii-grid">
                        ${iiRo('نوع بیمه / درخواست', kindLine)}
                        ${iiRo('بیمه‌گر', r.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد')}
                        ${iiRo('پلاک', plateHtml, {html: true, copy: plate})}
                        ${iiRo('خودرو (درخواست)', r.car_name)}
                        ${r.insurance_type === 'BODY' ? iiRo('ارزش خودرو (ریال)', r.car_value || r.estimated_car_value ? money(r.car_value || r.estimated_car_value) : '', {copy: String(r.car_value || r.estimated_car_value || '')}) : iiRo('تعهد مالی (ریال)', (r.liability_limit || r.liability_limit_case) ? money(r.liability_limit || r.liability_limit_case) : '', {copy: String(r.liability_limit || r.liability_limit_case || '')})}
                        ${iiRo('انقضای بیمه‌نامه‌ی قبلی', r.expiry_date_jalali || (Number(r.is_new_vehicle) ? 'صفر کیلومتر' : ''), {digits: true})}
                        ${r.ref_policy_number ? iiRo('بیمه‌نامه‌ی مرجع', r.ref_policy_number, {ltr: true}) : ''}
                        ${isP && r.prev_body_insurance ? iiRo('بیمه‌ی بدنه‌ی قبلی', r.prev_body_insurance) : ''}
                        ${isP && r.ownership_choice ? iiRo('مدرک مالکیت', r.ownership_choice) : ''}
                    </div>
                    ${(r.coverages_fa || []).length ? `<div class="mt-2"><span class="text-[10px] font-bold text-violet-700 ml-1">پوشش‌ها:</span>${r.coverages_fa.map(c => `<span class="inline-block text-[10.5px] bg-white border border-violet-200 text-violet-800 rounded-lg px-2 py-0.5 ml-1 mb-1">${iiEsc(c)}</span>`).join('')}</div>` : ''}
                    ${r.endorsement_request ? `<p class="text-[11px] text-violet-700 mt-2"><b>خواسته‌ی الحاقیه:</b> ${iiEsc(r.endorsement_request)}</p>` : ''}
                    ${r.cancellation_reason ? `<p class="text-[11px] text-rose-700 mt-2"><b>دلیل فسخ:</b> ${iiEsc(r.cancellation_reason)}</p>` : ''}
                </div>
                <div class="grid lg:grid-cols-2 gap-3">
                    <div class="ii-sec">
                        <h5><i class="fas fa-user-shield text-indigo-500"></i>بیمه‌گذار ${isP && r.relationship ? `<span class="text-[10px] font-bold text-slate-400">(${iiEsc(r.relationship)} پرسنل)</span>` : ''}</h5>
                        <div class="ii-grid">
                            ${iiField('insured_name', 'نام بیمه‌گذار', ii.insured_name)}
                            ${iiField('insured_national_id', isP ? 'کد ملی' : 'شناسه ملی', ii.insured_national_id, {ltr: true})}
                            ${iiField('insured_phone', 'تلفن', ii.insured_phone, {ltr: true})}
                            ${iiField('insured_postal_code', 'کد پستی', ii.insured_postal_code, {ltr: true})}
                            ${isP ? iiField('insured_birth_date', 'تاریخ تولد', ii.insured_birth_date, {ltr: true}) : ''}
                            ${iiField('insured_address', 'نشانی', ii.insured_address, {wide: true})}
                        </div>
                    </div>
                    <div class="ii-sec">
                        <h5><i class="fas fa-id-card text-amber-500"></i>مالک خودرو <span class="text-[10px] font-bold text-slate-400">(از کارت/سند خودرو)</span>
                            <button type="button" onclick="iiOwnerFromInsured()" class="mr-auto text-[10px] font-bold text-amber-700 bg-amber-50 hover:bg-amber-100 px-2 py-0.5 rounded-lg">مالک = بیمه‌گذار</button></h5>
                        <div class="ii-grid">
                            ${iiField('owner_name', 'نام مالک', ii.owner_name)}
                            ${iiField('owner_national_id', 'کد / شناسه ملی مالک', ii.owner_national_id, {ltr: true})}
                            ${iiField('owner_phone', 'تلفن مالک', ii.owner_phone, {ltr: true})}
                            ${iiField('owner_address', 'نشانی مالک', ii.owner_address, {wide: true})}
                        </div>
                    </div>
                </div>
                <div class="ii-sec">
                    <h5><i class="fas fa-car-side text-sky-500"></i>مشخصات کامل خودرو</h5>
                    <div class="ii-grid">
                        ${iiRo('پلاک', plateHtml, {html: true, copy: plate})}
                        ${iiField('car_kind', 'نوع (سواری، وانت...)', ii.car_kind)}
                        ${iiField('car_system', 'سیستم', ii.car_system)}
                        ${iiField('car_type', 'تیپ', ii.car_type)}
                        ${iiField('car_model_year', 'مدل (سال ساخت)', ii.car_model_year, {ltr: true})}
                        ${iiField('car_color', 'رنگ', ii.car_color)}
                        ${iiField('car_usage', 'کاربری', ii.car_usage)}
                        ${iiField('car_capacity', 'ظرفیت', ii.car_capacity, {ltr: true})}
                        ${iiField('car_cylinders', 'تعداد سیلندر', ii.car_cylinders, {ltr: true})}
                        ${iiField('chassis_no', 'شماره شاسی', ii.chassis_no, {ltr: true})}
                        ${iiField('engine_no', 'شماره موتور', ii.engine_no, {ltr: true})}
                        ${iiField('vin', 'VIN', ii.vin, {ltr: true})}
                    </div>
                </div>
                <div class="ii-sec">
                    <h5><i class="fas fa-clock-rotate-left text-rose-500"></i>سابقه و یادداشت</h5>
                    <div class="ii-grid">
                        ${iiField('prev_insurer', 'بیمه‌گر قبلی', ii.prev_insurer)}
                        ${iiField('prev_policy_number', 'شماره بیمه‌نامه‌ی قبلی', ii.prev_policy_number || r.ref_policy_number, {ltr: true})}
                        ${iiField('no_claim_years', 'سال‌های عدم خسارت', ii.no_claim_years, {ltr: true})}
                        ${iiField('note', 'یادداشت صدور', ii.note, {wide: true})}
                    </div>
                </div>
            </div>`;
        }
        function iiCollect() {
            const out = {};
            document.querySelectorAll('#ii-card [data-ii]').forEach(el => { out[el.dataset.ii] = el.value.trim(); });
            return out;
        }
        function iiCopyOne(btn) {
            const inp = btn.parentElement.querySelector('input');
            if (inp && inp.value.trim()) copyValue(inp.value.trim(), btn.closest('.ii-f').querySelector('label').textContent);
        }
        function iiOwnerFromInsured() {
            const v = iiCollect();
            const set = (k, val) => { const el = document.querySelector(`#ii-card [data-ii="${k}"]`); if (el && val) el.value = val; };
            set('owner_name', v.insured_name); set('owner_national_id', v.insured_national_id); set('owner_phone', v.insured_phone); set('owner_address', v.insured_address);
        }
        function iiCopyAll() {
            const r = IIROW || {};
            const v = iiCollect();
            const L = (label, val) => val ? `${label}: ${val}` : '';
            const lines = [
                '— بیمه‌نامه‌ی درخواستی —', L('نوع بیمه', r.insurance_type_fa), L('نوع درخواست', r.request_kind_fa), L('بیمه‌گر', r.insurer === 'IRAN' ? 'ایران' : 'پاسارگاد'),
                L('پلاک', r.plate_display), L('ارزش خودرو', r.car_value ? Number(r.car_value).toLocaleString('en-US') : ''), L('تعهد مالی', r.liability_limit ? Number(r.liability_limit).toLocaleString('en-US') : ''),
                L('انقضای قبلی', r.expiry_date_jalali), (r.coverages_fa || []).length ? L('پوشش‌ها', r.coverages_fa.join('، ')) : '',
                '', '— بیمه‌گذار —', L('نام', v.insured_name), L('کد/شناسه ملی', v.insured_national_id), L('تلفن', v.insured_phone), L('کد پستی', v.insured_postal_code), L('تاریخ تولد', v.insured_birth_date), L('نشانی', v.insured_address),
                '', '— مالک —', L('نام', v.owner_name), L('کد/شناسه ملی', v.owner_national_id), L('تلفن', v.owner_phone), L('نشانی', v.owner_address),
                '', '— خودرو —', L('نوع', v.car_kind), L('سیستم', v.car_system), L('تیپ', v.car_type), L('مدل', v.car_model_year), L('رنگ', v.car_color), L('کاربری', v.car_usage),
                L('ظرفیت', v.car_capacity), L('سیلندر', v.car_cylinders), L('شاسی', v.chassis_no), L('موتور', v.engine_no), L('VIN', v.vin),
                '', L('بیمه‌گر قبلی', v.prev_insurer), L('بیمه‌نامه‌ی قبلی', v.prev_policy_number), L('سال‌های عدم خسارت', v.no_claim_years), L('یادداشت', v.note),
            ].filter((x, i, a) => x !== '' || (a[i - 1] && a[i - 1] !== ''));
            const text = lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
            const done = () => showToast('همه‌ی اطلاعات کپی شد.', 'success');
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done).catch(() => {});
            else { const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} ta.remove(); }
        }
        async function iiSave() {
            const r = IIROW; if (!r) return;
            let d;
            try {
                d = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'save_issue_info', source: r.source, id: r.id, info: iiCollect()})})).json();
            } catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
            if (!d.ok) return showToast(d.error || 'خطا', 'error');
            r.issue_info = d.info || {};
            showToast('اطلاعات صدور ذخیره شد.', 'success');
        }

        function iqSetHead(src, r) {
            const head = document.getElementById('iq-detail-head');
            const isP = src === 'PERSONNEL';
            head.style.background = isP ? 'linear-gradient(120deg,#1d4ed8,#0ea5e9)' : 'linear-gradient(120deg,#4338ca,#7c3aed)';
            document.getElementById('iq-detail-title').innerHTML = isP
                ? `<i class="fas fa-id-card ml-1"></i>پرونده‌ی صدورِ کارکنان — ${iiEsc(r.holder_name || '')}`
                : `<i class="fas fa-building ml-1"></i>پرونده‌ی صدورِ شرکتی — ${iiEsc(r.company_name || '')}`;
            document.getElementById('iq-detail-sub').textContent = isP
                ? 'معرفی‌نامه، اطلاعاتِ پرسنل و بیمه‌گذار، مالک و خودرو، مدارک و صدور - همه یک‌جا.'
                : 'نامه‌ی درخواست، اطلاعاتِ شرکت، مالک و خودرو، مدارک و صدور - همه یک‌جا.';
        }

        function openIssueQueueDetail(source, rowId) {
            const r = issueQueueCache.find(x => String(x.id) === String(rowId) && x.source === source);
            if (!r) { showToast('ردیف پیدا نشد.', 'error'); return; }
            // پرونده‌ی کارکنان ستون‌ها و مسیرِ صدورِ خودش را دارد، پس پاپ‌آپش هم جداست
            if (source === 'PERSONNEL') { openIssueQueuePersonnelDetail(r); return; }
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
            iqSetHead('COMPANY', r);

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
                            ${infoCell('تاریخ ثبت', r.request_date_jalali, {date: true})}
                            ${infoCell('مرحله', r.status_fa)}
                        </div>
                        ${r.request_text ? `<p class="text-[10px] text-slate-500 mt-2">${r.request_text}</p>` : ''}
                        ${r.letter_file_path
                            ? `<a href="../${r.letter_file_path}" target="_blank" class="inline-block mt-2 text-[11px] font-bold text-white bg-slate-700 hover:bg-slate-800 px-3 py-1.5 rounded-lg"><i class="fas fa-file-pdf ml-1"></i>نامه‌ی این درخواست</a>`
                            : '<p class="text-[10px] text-amber-600 mt-2">نامه‌ای برای این درخواست ثبت نشده.</p>'}
                    </div>
                </div>

                ${issueInfoCardHtml(r)}

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

        // پاپ‌آپِ ردیفِ «کارکنان» در همین فهرستِ جامعِ صدور. ستون‌ها و مدارکش با شرکتی
        // فرق دارد (کد پرسنلی، نام پرسنل، بیمه‌گذار و نسبتش، معرفی‌نامه، دوره‌ی مالی)،
        // ولی دکمه‌ها همان کارها را می‌کنند و صدور را به همان مسیرِ پرونده‌ی کارکنان می‌برد.
        function openIssueQueuePersonnelDetail(r) {
            const CASE_DOC_ST = {
                APPROVED: '<span class="text-[10px] font-bold text-emerald-600">✓ تایید شده</span>',
                REJECTED: '<span class="text-[10px] font-bold text-red-500">✗ رد شده</span>',
                PENDING:  '<span class="text-[10px] font-bold text-amber-500">در انتظار تایید</span>',
            };
            const docsHtml = (r.all_docs || []).length
                ? r.all_docs.map(d => `
                    <div class="flex items-center justify-between gap-2 bg-white border border-slate-100 rounded-lg p-2">
                        <span class="text-[11px] font-bold text-slate-600">${d.label}</span>
                        <span class="flex items-center gap-2">
                            ${CASE_DOC_ST[d.status] || ''}
                            ${d.file_path ? `<a href="/${encodeFilePath(d.file_path)}" target="_blank" class="text-blue-600 hover:underline text-[11px]">مشاهده</a>` : ''}
                        </span>
                    </div>`).join('')
                : '<p class="text-[11px] text-slate-400">هنوز مدرکی برای این پرونده ثبت نشده.</p>';

            const dash = '';
            iqSetHead('PERSONNEL', r);
            document.getElementById('iq-detail-body').innerHTML = `
                <div class="grid md:grid-cols-2 gap-4 mb-4">
                    <div class="border border-slate-200 rounded-xl p-3">
                        <h4 class="text-xs font-bold text-slate-500 mb-2"><i class="fas fa-user-tie ml-1 text-slate-300"></i>پرسنل و بیمه‌گذار</h4>
                        <div class="grid grid-cols-2 gap-1.5">
                            ${infoCell('نام پرسنل', r.holder_name)}
                            ${infoCell('کد پرسنلی', r.personnel_code ? e2p(r.personnel_code) : '', {ltr: true})}
                            ${infoCell('نام بیمه‌گذار', r.insured_name)}
                            ${infoCell('نسبت با پرسنل', r.relationship)}
                            ${infoCell('کد ملی بیمه‌گذار', r.national_id ? e2p(r.national_id) : '', {ltr: true})}
                            ${infoCell('کد پیگیری', r.unique_code, {ltr: true})}
                        </div>
                    </div>
                    <div class="border border-slate-200 rounded-xl p-3">
                        <h4 class="text-xs font-bold text-slate-500 mb-2"><i class="fas fa-building ml-1 text-slate-300"></i>شرکت / کارفرما و درخواست</h4>
                        <div class="grid grid-cols-2 gap-1.5">
                            ${infoCell('شرکت', r.company_name)}
                            ${infoCell('تلفن شرکت', r.company_phone, {ltr: true})}
                            ${infoCell('تاریخ ثبت درخواست', r.request_date_jalali, {date: true})}
                            ${infoCell('تاریخ معرفی‌نامه', r.letter_date_jalali, {date: true})}
                            ${infoCell('سهمیه‌ی معرفی‌نامه', r.quota ? e2p(r.quota) : '')}
                            ${infoCell('دوره‌ی مالی', r.period_title, {date: true})}
                        </div>
                        ${r.letter_file_path
                            ? `<a href="/${encodeFilePath(r.letter_file_path)}" target="_blank" class="inline-block mt-2 text-[11px] font-bold text-white bg-slate-700 hover:bg-slate-800 px-3 py-1.5 rounded-lg"><i class="fas fa-file-pdf ml-1"></i>معرفی‌نامه‌ی این پرونده</a>`
                            : '<p class="text-[10px] text-amber-600 mt-2">فایلی برای معرفی‌نامه ثبت نشده.</p>'}
                    </div>
                </div>

                ${issueInfoCardHtml(r)}

                <div class="border border-slate-200 rounded-xl p-3 mb-4">
                    <h4 class="text-xs font-bold text-slate-500 mb-2"><i class="fas fa-paperclip ml-1 text-slate-300"></i>مدارک ثبت‌شده (تک‌به‌تک)</h4>
                    <div class="grid md:grid-cols-2 gap-1.5">${docsHtml}</div>
                </div>

                <div class="border-2 ${r.is_ready ? 'border-emerald-200 bg-emerald-50/40' : 'border-amber-200 bg-amber-50/40'} rounded-xl p-3">
                    <h4 class="text-xs font-bold ${r.is_ready ? 'text-emerald-700' : 'text-amber-700'} mb-2">
                        <i class="fas fa-stamp ml-1"></i>${r.is_ready ? 'مدارک کامل است - آماده‌ی صدور' : 'هنوز مدارک کامل نیست'}</h4>
                    ${!r.is_ready ? `<p class="text-[11px] text-amber-700 mb-2">کم دارد: ${Object.values(r.missing_docs || {}).join('، ')}</p>` : ''}
                    <div class="flex flex-wrap gap-2">
                        <button onclick="iqOpenCase(${r.id}, 'issue')" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-xl text-xs"><i class="fas fa-check ml-1"></i>صدور بیمه‌نامه</button>
                        <button onclick="iqOpenCase(${r.id}, 'review')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2 rounded-xl text-xs">بررسی و تایید مدارک</button>
                        ${r.missing_docs && r.missing_docs.health_report
                            ? `<button onclick="document.getElementById('iq-detail-modal').classList.remove('active'); switchTab('health');" class="bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold px-4 py-2 rounded-xl text-xs">بارگذاری گزارش بازدید</button>`
                            : ''}
                    </div>
                </div>`;
            document.getElementById('iq-detail-modal').classList.add('active');
        }

        // از پاپ‌آپِ فهرست صدور به پرونده‌ی کارکنان
        function iqOpenCase(caseId, mode) {
            document.getElementById('iq-detail-modal').classList.remove('active');
            openCase(caseId, mode);
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
                company_id: (document.getElementById('il-company') || {}).value || '',
                issued_from: document.getElementById('il-from').value.trim(),
                issued_to: document.getElementById('il-to').value.trim(),
                request_kind: (document.getElementById('il-kind') || {}).value || '',
                insurer: (document.getElementById('il-insurer') || {}).value || '',
                expiry_from: ((document.getElementById('il-exp-from') || {}).value || '').trim(),
                expiry_to: ((document.getElementById('il-exp-to') || {}).value || '').trim(),
            };
        }

        let ilCompaniesLoaded = false;
        async function loadIssuedList() {
            jMonthOptions(document.getElementById('il-month'), 'همه‌ی ماه‌ها (صدور)');
            if (!ilCompaniesLoaded) {
                ilCompaniesLoaded = true;
                fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'list_companies'})}).then(r => r.json()).then(d => {
                    if (d.ok) document.getElementById('il-company').innerHTML = '<option value="">همه‌ی شرکت‌ها</option>' + (d.companies || []).map(c => `<option value="${c.id}">${c.name}</option>`).join('');
                }).catch(() => { ilCompaniesLoaded = false; });
            }
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
            document.querySelectorAll('.iss-n-issued').forEach(el => { el.textContent = c.total ? e2pNum(c.total) : ''; });
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
                    <td class="p-3">${r.plate ? formatPlateHtml(r.plate) : `<span dir="ltr">${r.chassis_no || '—'}</span>`}</td>
                    <td class="p-3">${r.insurance_type_fa}</td>
                    <td class="p-3 font-mono" dir="ltr">${r.policy_number || '—'}</td>
                    <td class="p-3">${r.car_name || '—'}</td>
                    <td class="p-3">${r.total_premium ? money(r.total_premium) : '—'}</td>
                    <td class="p-3 text-slate-500">${faDigits(r.request_date_jalali) || '—'}</td>
                    <td class="p-3 text-slate-500">${faDigits(r.expiry_date_jalali) || '—'}</td>
                    <td class="p-3 text-slate-500">${faDigits(r.policy_issue_date || r.issued_at_jalali) || '—'}${r.policy_issue_date && r.issued_at_jalali && r.policy_issue_date.replace(/\//g, '.') !== String(r.issued_at_jalali).replace(/\//g, '.') ? `<p class="text-[9.5px] text-slate-400">ثبت در سایت: ${faDigits(r.issued_at_jalali)}</p>` : ''}</td>
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
                    ${infoCell('پلاک', r.plate, {ltr: true, plate: true})}
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
                    ${infoCell('تاریخ درخواست', r.request_date_jalali, {date: true})}
                    ${infoCell('تاریخ انقضا', r.expiry_date_jalali, {date: true})}
                    ${infoCell('تاریخ صدور (داخلِ بیمه‌نامه)', r.policy_issue_date || r.issued_at_jalali, {date: true})}
                    ${r.policy_issue_date ? infoCell('ثبتِ صدور در سایت', r.issued_at_jalali, {date: true}) : ''}
                    ${infoCell('تاریخ صدور روی بیمه‌نامه', r.policy_issue_date, {ltr: true, date: true})}
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
            Object.keys(f).forEach(k => { if (f[k] === '') delete f[k]; });
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

        // اقساط و دریافت/پرداختِ همین درخواست (همان مرکزِ اقساط: هر ردیف دریافت از شرکت و پرداخت به بیمه‌گر، کامل یا جزئی)
        let CREQ_FIN = [];
        async function loadCreqFinance(requestId) {
            const panel = document.getElementById('creq-finance-panel');
            panel.dataset.requestId = requestId;
            panel.innerHTML = '<p class="text-xs text-slate-400 p-2">در حال بارگذاری...</p>';
            let data;
            try { data = await (await fetch(`api/finance_actions.php?action=ledger_installments&stage=ALL&source=C&request_id=${requestId}`)).json(); } catch (e) { data = {ok: false}; }
            if (!data.ok) { panel.innerHTML = `<p class="text-red-500 text-xs p-2">${data.error || 'خطا'}</p>`; return; }
            CREQ_FIN = data.rows || [];
            if (!CREQ_FIN.length) { panel.innerHTML = '<p class="text-slate-400 text-xs p-2">هنوز هیچ ردیفی صادر (و در نتیجه قسط‌بندی) نشده.</p>'; return; }
            const ST = (window.FinHub && FinHub.STAGE) || {};
            const s = data.summary || {};
            panel.innerHTML = `
                <div class="flex flex-wrap items-center gap-2 mb-2 text-[11px] font-bold">
                    <span class="cmx-chip bg-slate-100 text-slate-700">${e2pNum(CREQ_FIN.length)} قسط | ${money(s.amount)} ریال</span>
                    <span class="cmx-chip bg-emerald-50 text-emerald-700">دریافت‌شده ${money(s.paid)}</span>
                    <span class="cmx-chip bg-rose-50 text-rose-700">مانده ${money(s.remaining)}</span>
                    <span class="cmx-chip bg-indigo-50 text-indigo-700">پرداخت به بیمه‌گر ${money(s.insurer_paid)}</span>
                    <span class="mr-auto flex gap-2">
                        <button onclick="creqPay('IN')" class="text-white px-3 py-1.5 rounded-lg" style="background:linear-gradient(120deg,#e11d48,#f43f5e)"><i class="fas fa-hand-holding-dollar ml-1"></i>ثبت دریافت از شرکت</button>
                        <button onclick="creqPay('OUT')" class="text-white px-3 py-1.5 rounded-lg" style="background:linear-gradient(120deg,#b45309,#f59e0b)"><i class="fas fa-building-columns ml-1"></i>ثبت پرداخت به بیمه‌گر</button>
                    </span>
                </div>
                <p class="text-[10.5px] text-slate-400 mb-1">ردیف‌ها را تیک بزنید (بدونِ انتخاب، همه‌ی ردیف‌ها)؛ مبلغِ هر ردیف در پنجره‌ی ثبت قابلِ تغییر است.</p>
                <div class="overflow-x-auto"><table class="w-full text-[11px] text-right whitespace-nowrap trk-table no-count border-2 border-slate-200 rounded-xl overflow-hidden">
                    <thead><tr><th><input type="checkbox" onchange="document.querySelectorAll('.creqf-cb').forEach(c => c.checked = this.checked)"></th><th>پلاک</th><th>بیمه‌نامه</th><th class="text-center">قسط</th><th>سررسید</th><th>مبلغ</th><th>دریافت‌شده</th><th>مانده</th><th>به بیمه‌گر</th><th>مرحله</th><th></th></tr></thead>
                    <tbody>${CREQ_FIN.map((r, i) => { const st = ST[r.stage] || ['', '']; return `<tr>
                        <td><input type="checkbox" class="creqf-cb" value="${i}"></td><td>${r.plate && /ایران/.test(r.plate) ? formatPlateHtml(r.plate) : (r.plate || '—')}</td>
                        <td dir="ltr" class="font-mono">${r.policy_number || '—'}</td><td class="text-center font-black">${e2pNum(r.inst_number)}</td>
                        <td class="${r.overdue ? 'text-red-600 font-bold' : ''}">${faDigits(r.due_jalali)}</td><td class="font-bold">${money(r.amount)}</td>
                        <td class="text-emerald-700">${money(r.paid)}</td><td class="${r.remaining ? 'text-rose-600 font-bold' : 'text-slate-300'}">${money(r.remaining)}</td>
                        <td class="text-indigo-700">${money(r.paid_insurer)}</td><td><span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${st[1]}">${st[0] || ''}</span></td>
                        <td><div class="flex gap-1"><button onclick="FinHub.openInstDetail(CREQ_FIN[${i}])" class="px-2 py-1 rounded-lg bg-slate-100 hover:bg-slate-200" title="جزئیات و سابقه"><i class="fas fa-circle-info"></i></button>
                            ${r.receipt ? `<a href="/${encodeFilePath(r.receipt.file)}" target="_blank" class="px-2 py-1 rounded-lg bg-sky-50 text-sky-700 font-bold"><i class="fas fa-receipt ml-1"></i>فیش</a>` : ''}</div></td></tr>`; }).join('')}</tbody></table></div>`;
        }
        function creqPay(dir) {
            if (!window.FinHub) return;
            const picked = [...document.querySelectorAll('.creqf-cb:checked')].map(c => CREQ_FIN[Number(c.value)]);
            const rows = picked.length ? picked : CREQ_FIN;
            FinHub.openPayDialog(dir, rows, () => loadCreqFinance(document.getElementById('creq-finance-panel').dataset.requestId));
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
                        <p class="text-[11px] text-slate-400 mt-1">${d.file_kind === 'LETTER' ? 'نامه' : 'مدرک'} · ${faDigits(d.uploaded_at_jalali)}
                        ${d.plate_p1 || d.doc_type ? ' · <span class="text-blue-500">پیشنهاد ثبت‌کننده: ' + fmtPlateHtml(d) + ' ' + (DOC_TYPE_FA[d.doc_type] || d.doc_type || '') + '</span>' : ''}</p>
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
                        <p class="text-[11px] text-slate-400 mt-1">${d.company_name} · ${d.file_kind === 'LETTER' ? 'نامه' : 'مدرک'} · ${faDigits(d.uploaded_at_jalali)}
                        ${d.plate_p1 || d.doc_type ? ' · <span class="text-blue-500">پیشنهاد ثبت‌کننده: ' + fmtPlateHtml(d) + ' ' + (DOC_TYPE_FA[d.doc_type] || d.doc_type || '') + '</span>' : ''}</p>
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
            fillCitCounts(data.request && data.request.requested_counts_obj);
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

        // فیلدهای مودال به نوعِ فایل بستگی دارند: مدارکِ خودرو => پلاک و نوع بیمه و انقضا؛
        // «نامه‌ی درخواست» => نوعِ نامه و تعدادِ درخواستی از هر نوع. تا نوع انتخاب نشده، هیچ‌کدام.
        function onCitDocTypeChange() {
            const t = document.getElementById('cit-doc-type').value;
            document.getElementById('cit-doc-type-other').classList.toggle('hidden', t !== 'other');
            document.getElementById('cit-vehicle-fields').classList.toggle('hidden', !t || t === 'letter');
            document.getElementById('cit-letter-fields').classList.toggle('hidden', t !== 'letter');
        }

        function fillCitCounts(counts) {
            ['THIRDPARTY', 'BODY', 'ENDORSEMENT', 'CANCELLATION'].forEach(k => {
                const v = counts && Number(counts[k]) ? Number(counts[k]) : '';
                document.getElementById('cit-cnt-' + k).value = v ? e2p(v) : '';
            });
        }

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
            // اگر شرکت خودش نوع را زده بود همان، اگر به‌عنوانِ نامه فرستاده بود «نامه»، وگرنه خالی
            // تا اول نوعِ فایل انتخاب شود و بعد فیلدهای مربوط به همان نوع ظاهر شوند
            document.getElementById('cit-doc-type').value = (doc.file_kind === 'LETTER' || doc.doc_type === 'letter') ? 'letter'
                : (KNOWN_TYPES.includes(doc.doc_type) && doc.doc_type !== 'car_card_or_title' ? doc.doc_type : (doc.doc_type ? 'other' : ''));
            document.getElementById('cit-doc-type-other').value = document.getElementById('cit-doc-type').value === 'other' ? (doc.doc_type || '') : '';
            fillCitCounts(null);
            onCitDocTypeChange();
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
            if (!docTypeSel) { showToast('اول نوع فایل را انتخاب کنید.', 'error'); return; }
            if (docTypeSel === 'letter') {
                // برای نامه، پلاک و ... فرستاده نمی‌شود؛ به‌جایش تعدادِ درخواستی از هر نوع
                ['plate_id', 'plate_p1', 'plate_p2', 'plate_letter', 'plate_p4', 'insurance_type', 'expiry_date'].forEach(k => delete payload[k]);
                payload.requested_counts = {};
                ['THIRDPARTY', 'BODY', 'ENDORSEMENT', 'CANCELLATION'].forEach(k => {
                    payload.requested_counts[k] = document.getElementById('cit-cnt-' + k).value.trim();
                });
            } else if (!payload.plate_id && !(payload.plate_p1 || payload.plate_p2 || payload.plate_p4)) {
                showToast('پلاکِ این مدرک را انتخاب یا وارد کنید.', 'error'); return;
            }
            try {
                const res = await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
                const data = await res.json();
                if (data.ok) {
                    showToast(data.note || (data.is_letter && data.requested_counts_fa ? 'نامه ثبت شد: ' + data.requested_counts_fa : 'اطلاعات ثبت شد.'),
                              data.note ? 'warning' : 'success');
                    document.getElementById('cinbox-tag-modal').classList.remove('active');
                    if (document.getElementById('creq-docs-modal').classList.contains('active')) loadRequestDocsReview();
                    else loadCompanyInbox();
                    loadCompanyRequests();
                }
                else showToast(data.error || 'خطا در تخصیص مدرک', 'error');
            } catch (e) { showToast('خطا در ارتباط با سرور', 'error'); }
        }

        // ---------- فرمِ صدور (نسلِ جدید): همه‌ی فیلدهای بیمه‌نامه، قابلِ ویرایش، برای صدورِ شرکتی و کارکنان ----------
        // کلیدها همان خروجیِ الگوریتمِ شناسایی‌اند؛ نام‌های قدیمیِ موتور (policy_number، total_premium، ...) هم خوانده می‌شوند
        const PF_GROUPS = [
            ['fa-file-shield', 'بیمه‌نامه', [['policy_num', 'شماره بیمه‌نامه', {ltr: 1, req: 1}], ['premium', 'حق بیمه‌ی کل (ریال)', {money: 1, req: 1}], ['issue_date', 'تاریخ صدور (سررسیدِ اقساط از این تاریخ)', {ltr: 1, req: 1, ph: '۱۴۰۵/۰۷/۱۰'}],
                ['start_date', 'شروع', {ltr: 1}], ['end_date', 'پایان', {ltr: 1}], ['central_no', 'کد یکتای بیمه مرکزی', {ltr: 1}], ['ins_type', 'نوع (تشخیص)', {ro: 1}], ['ins_company', 'بیمه‌گر (تشخیص)', {ro: 1}]]],
            ['fa-scale-balanced', 'سرمایه و تعهدات', [['car_value', 'ارزش خودرو (ریال)', {money: 1, only: 'بدنه'}], ['total_value', 'جمعِ سرمایه‌ی بیمه (ریال)', {money: 1, only: 'بدنه'}],
                ['trailer_value', 'ارزش یدک (ریال)', {money: 1, only: 'بدنه'}], ['extras_value', 'ارزش وسایل اضافی (ریال)', {money: 1, only: 'بدنه'}],
                ['liability', 'تعهد مالی (ریال)', {money: 1, only: 'ثالث'}], ['diya', 'تعهد بدنی / دیه (ریال)', {money: 1, only: 'ثالث'}], ['driver_cover', 'حوادث راننده (ریال)', {money: 1, only: 'ثالث'}],
                ['ncd', 'تخفیف عدم خسارت', {wide: 1, only: 'ثالث'}], ['covers', 'پوشش‌های بیمه‌نامه', {wide: 1, only: 'بدنه'}]]],
            ['fa-user-tie', 'بیمه‌گذار', [['insured_name', 'نام بیمه‌گذار'], ['national_id', 'کد / شناسه ملی', {ltr: 1}], ['phone', 'تلفن', {ltr: 1}], ['postal_code', 'کد پستی', {ltr: 1}],
                ['address', 'نشانی', {wide: 1}]]],
            ['fa-car-side', 'خودرو', [['plate', 'پلاک (تشخیص)', {ro: 1, plate: 1}], ['vin', 'شاسی (VIN)', {ltr: 1}], ['engine_no', 'شماره موتور', {ltr: 1}], ['car_kind', 'نوع خودرو'], ['car_system', 'سیستم'], ['car_tip', 'تیپ'],
                ['model_year', 'مدل (سال ساخت)', {ltr: 1}], ['color', 'رنگ'], ['usage', 'کاربری'], ['capacity', 'ظرفیت'], ['cylinders', 'تعداد سیلندر', {ltr: 1}], ['cargo', 'اتاق بار']]],
            ['fa-clock-rotate-left', 'بیمه‌نامه‌ی قبلی', [['prev_insurer', 'بیمه‌گرِ قبلی'], ['prev_policy', 'شماره بیمه‌نامه‌ی قبلی', {ltr: 1}], ['prev_expiry', 'انقضای بیمه‌نامه‌ی قبلی', {ltr: 1}],
                ['prev_claims', 'وضعیت خسارتِ سال قبل', {only: 'ثالث'}]]],
        ];
        const PF_ALIAS = {phone: ['insured_phone'], address: ['insured_address'], postal_code: ['insured_postal_code'], liability: ['liability_limit'], policy_num: ['policy_number'], premium: ['total_premium'], engine_no: ['engine_num'], central_no: ['unique_code'], color: ['car_color'], usage: ['car_usage'], car_tip: ['car_type'], vin: ['chassis_num', 'chassis_no']};
        function pfVal(o, k) {
            for (const kk of [k].concat(PF_ALIAS[k] || [])) { const v = o[kk]; if (v !== undefined && v !== null && String(v).trim() !== '') return String(v).trim(); }
            return '';
        }
        // box: محلِ فرم؛ o: داده‌ی شناسایی؛ meta: {receipts, missing, source, layout_debug}
        function pfRender(box, o = {}, meta = {}) {
            const esc = v => String(v ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            // فیلدهای مخصوصِ بدنه/ثالث فقط وقتی نوعِ دیگری تشخیص داده شده و خالی‌اند پنهان می‌شوند
            const insType = String(o.ins_type || o.insurance_type_fa || '');
            const field = (k, label, op = {}) => {
                let v = pfVal(o, k);
                if (op.only && insType && !insType.includes(op.only) && !v) return '';
                if (op.money && v) v = mfmt(v);
                if (op.wide) {
                    return `<div class="ii-f" style="grid-column:1/-1"><label>${label}</label><div class="ii-w"><textarea data-pf="${k}" rows="2" class="w-full bg-transparent outline-none resize-y text-[12px] leading-6">${esc(v)}</textarea></div></div>`;
                }
                if (op.ro) return `<div class="ii-f"><label>${label}</label><div class="ii-ro">${v ? (op.plate && /ایران/.test(v) ? formatPlateHtml(v) : `<span>${esc(faDigits(v))}</span>`) : '<span class="text-slate-300">—</span>'}</div></div>`;
                const miss = op.req && !v;
                return `<div class="ii-f"><label>${label}${op.req ? ' <span class="text-rose-500">*</span>' : ''}</label><div class="ii-w ${miss ? 'pf-miss' : ''}">
                    <input data-pf="${k}" value="${esc(v)}" ${op.ltr || op.money ? 'dir="ltr"' : ''} ${op.money ? 'inputmode="numeric" class="money-input"' : ''} placeholder="${esc(op.ph || '')}"></div></div>`;
            };
            const src = meta.source === 'layout' ? '<span class="cmx-chip bg-emerald-100 text-emerald-700"><i class="fas fa-wand-magic-sparkles"></i>خوانده‌شده با الگوریتمِ جای متن</span>'
                : (meta.source === 'engine' ? `<span class="cmx-chip bg-amber-100 text-amber-800" title="${esc(meta.layout_debug || '')}"><i class="fas fa-triangle-exclamation"></i>با موتورِ قدیمی خوانده شد${meta.layout_debug ? ': ' + esc(meta.layout_debug) : ''}</span>` : '');
            const rc = meta.receipts || [];
            const groupsHtml = PF_GROUPS.map(([ic, title, fields]) => { const inner = fields.map(f => field(f[0], f[1], f[2] || {})).join(''); return inner ? `<div class="ii-sec"><h5><i class="fas ${ic} text-teal-600"></i>${title}</h5><div class="ii-grid">${inner}</div></div>` : ''; }).join('');
            box.innerHTML = `${src || (meta.missing || []).length ? `<div class="flex flex-wrap items-center gap-1.5 mb-2">${src}${(meta.missing || []).length ? `<span class="cmx-chip bg-rose-50 text-rose-700">خوانده نشد: ${meta.missing.join('، ')}</span>` : ''}</div>` : ''}
                <div class="space-y-2">${groupsHtml}</div>
                ${rc.length ? `<div class="ii-sec mt-2 border-indigo-200 bg-indigo-50/40"><h5><i class="fas fa-receipt text-indigo-600"></i>${e2pNum(rc.length)} فیشِ قسط داخلِ فایل</h5>
                    <p class="text-[10.5px] text-slate-500 mb-1">صفحه‌های خودِ بیمه‌نامه جدا بایگانی می‌شوند و هر فیش روی ردیفِ قسطِ خودش می‌نشیند.</p>
                    <div class="flex flex-wrap gap-1">${rc.map((r, i) => `<span class="text-[10px] bg-white border border-indigo-100 rounded-full px-2 py-0.5">قسط ${e2pNum(i + 1)}: ${faDigits(r.date || '—')} | ${r.amount ? money(r.amount) : '—'}</span>`).join('')}</div></div>` : ''}`;
            box.querySelectorAll('[data-pf]').forEach(inp => inp.addEventListener('input', () => inp.closest('.ii-w').classList.remove('pf-miss')));
            if (window.MoneyInput && MoneyInput.apply) try { MoneyInput.apply(box); } catch (e) {}
        }
        function pfCollect(box) {
            const out = {};
            box.querySelectorAll('[data-pf]').forEach(inp => { out[inp.dataset.pf] = inp.value.trim(); });
            ['premium', 'car_value', 'liability', 'total_value', 'trailer_value', 'extras_value', 'diya', 'driver_cover'].forEach(k => { if (out[k] !== undefined) out[k] = String(out[k]).replace(/[^\d۰-۹]/g, '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); });
            ['issue_date', 'start_date', 'end_date', 'prev_expiry', 'model_year', 'national_id', 'phone', 'postal_code', 'policy_num', 'prev_policy', 'central_no', 'cylinders'].forEach(k => { if (out[k]) out[k] = out[k].replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); });
            return out;
        }
        function pfCheck(box) {
            const v = pfCollect(box), miss = [];
            if (!v.policy_num) miss.push('شماره بیمه‌نامه');
            if (!v.premium) miss.push('حق بیمه');
            box.querySelectorAll('input[data-pf="policy_num"],input[data-pf="premium"]').forEach(i => i.closest('.ii-w').classList.toggle('pf-miss', !i.value.trim()));
            return miss;
        }
        function openMarkIssued(plateId) {
            document.getElementById('cis-plate-id').value = plateId;
            document.getElementById('cis-file').value = '';
            document.getElementById('cis-file-name').textContent = 'انتخابِ فایلِ بیمه‌نامه‌ی صادرشده (PDF یا عکس)';
            const note = document.getElementById('cis-ocr-note');
            note.className = 'hidden'; note.innerHTML = '';
            const p = (typeof currentRequestRows !== 'undefined' ? currentRequestRows : []).find(x => String(x.id) === String(plateId));
            document.getElementById('cis-plate-label').innerHTML = p
                ? `ردیف: <span class="plate-display">${fmtPlateHtml(p)}</span> | ${p.insurance_type === 'BODY' ? 'بدنه' : 'ثالث'}` : '';
            // فرم از قبل با اطلاعاتِ ردیف پر می‌شود (برای صدورِ دستی بدونِ فایل هم کار می‌کند)
            pfRender(document.getElementById('cis-form'), p ? {vin: p.chassis_no || '', engine_no: p.engine_no || '', car_name: p.car_name || '', car_value: p.car_value || '', liability: p.liability_limit || ''} : {});
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
            pfRender(document.getElementById('cis-form'), o, {receipts: data.receipts || [], missing: data.missing || [], source: data.source, layout_debug: data.layout_debug});
            note.className = 'text-[11px] rounded-lg p-2 ' + (data.ocr_used ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700');
            note.innerHTML = data.ocr_used
                ? `✓ فایل با این ردیف مطابقت دارد (${(o.plate || data.expected_plate) && /ایران/.test(o.plate || data.expected_plate) ? formatPlateHtml(o.plate || data.expected_plate) : (o.plate || data.expected_plate || '')} | ${o.ins_type || ''}). فیلدها پر شدند؛ بررسی و در صورتِ نیاز اصلاح کنید.`
                : `فایل ذخیره شد ولی شناسایی خودکار انجام نشد${data.ocr_debug ? ' (' + data.ocr_debug + ')' : ''}. فیلدها را دستی پر کنید.`;
        }

        async function submitMarkIssued() {
            const box = document.getElementById('cis-form');
            const miss = pfCheck(box);
            if (miss.length) { showToast(miss.join(' و ') + ' الزامی است.', 'error'); return; }
            const v = pfCollect(box);
            const fd = new FormData();
            fd.append('action', 'mark_issued');
            fd.append('plate_id', document.getElementById('cis-plate-id').value);
            fd.append('policy_number', v.policy_num);
            fd.append('vin', v.vin || '');
            fd.append('total_premium', v.premium);
            fd.append('issue_date', v.issue_date || '');
            fd.append('fields', JSON.stringify(v));
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

            renderCompanyCards();

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
                if (utbody) utbody.innerHTML = usersData.users.length ? usersData.users.map(u => `
                    <tr class="border-t border-slate-100">
                        <td class="p-3 font-mono">${u.username}</td><td class="p-3">${botDot(u.bot_linked)} ${u.full_name}</td>
                        <td class="p-3 text-slate-500">${u.company_names || '—'}</td><td class="p-3 font-mono text-slate-400">${faDigits(u.mobile_number || '') || '—'}</td>
                        <td class="p-3 flex gap-2">
                            <button onclick="editPortalUser(${u.id})" class="text-blue-600 hover:underline text-xs font-bold">ویرایش</button>
                            <button onclick="deletePortalUserRow(${u.id}, '${(u.full_name||'').replace(/'/g,"")}')" class="text-red-500 hover:underline text-xs font-bold">حذف</button>
                        </td>
                    </tr>`).join('') : '<tr><td colspan="5" class="text-center p-6 text-slate-400">کاربری ثبت نشده.</td></tr>';
            }
        }


        // ---------- صفحه‌ی شرکت‌ها: کارت‌ها، آمار و جستجو ----------
        const CMX_COLORS = ['#4f46e5', '#0891b2', '#059669', '#d97706', '#db2777', '#7c3aed', '#0d9488', '#2563eb'];
        function renderCompanyCards() {
            const box = document.getElementById('cm-cards');
            if (!box) return;
            const list = companiesCache || [];
            const n = k => list.reduce((a, c) => a + Number(c[k] || 0), 0);
            const kpi = (v, t, ic) => `<div class="cmx-kpi"><span><i class="fas ${ic} ml-1"></i>${t}</span><b>${e2pNum(v)}</b></div>`;
            const kp = document.getElementById('cm-kpis');
            if (kp) kp.innerHTML = kpi(list.length, 'شرکت', 'fa-building') + kpi(list.filter(c => Number(c.users_count) > 0).length, 'دارای کاربرِ پنل', 'fa-user-check')
                + kpi(n('open_requests'), 'درخواستِ باز', 'fa-folder-open') + kpi(n('issued_count'), 'بیمه‌نامه‌ی صادره', 'fa-file-circle-check');
            const q = (document.getElementById('cm-q').value || '').trim().toLowerCase();
            const fp = document.getElementById('cm-f-pay').value, fi = document.getElementById('cm-f-ins').value;
            const rows = list.filter(c => {
                if (fp === 'NONE' ? c.payment_terms : (fp && c.payment_terms !== fp)) return false;
                if (fi && (c.allowed_insurers || 'BOTH') !== fi) return false;
                if (!q) return true;
                return [c.name, c.economic_code, c.phone, c.parent_name, c.address].join(' ').toLowerCase().includes(q) || faDigits([c.economic_code, c.phone].join(' ')).includes(q);
            });
            document.getElementById('cm-count').textContent = `${e2pNum(rows.length)} از ${e2pNum(list.length)} شرکت`;
            if (!rows.length) { box.innerHTML = `<div class="col-span-full card p-10 text-center text-slate-400 text-sm"><i class="fas fa-building text-3xl mb-2 block text-slate-300"></i>${list.length ? 'شرکتی با این جستجو/فیلتر نیست.' : 'هنوز شرکتی ثبت نشده؛ «افزودن شرکت» یا «ورود از اکسل» را بزنید.'}</div>`; return; }
            const children = {};
            list.forEach(c => { if (c.parent_id) children[c.parent_id] = (children[c.parent_id] || 0) + 1; });
            const ins = {BOTH: ['پاسارگاد + ایران', 'bg-violet-100 text-violet-700'], PASARGAD: ['پاسارگاد', 'bg-sky-100 text-sky-700'], IRAN: ['ایران', 'bg-rose-100 text-rose-700']};
            box.innerHTML = rows.map(c => {
                const col = CMX_COLORS[Number(c.id) % CMX_COLORS.length];
                const iv = ins[c.allowed_insurers || 'BOTH'] || ins.BOTH;
                const safe = (c.name || '').replace(/'/g, '');
                return `<div class="cmx-card">
                    <div class="cmx-card-top">
                        <div class="cmx-av" style="background:linear-gradient(135deg,${col},${col}cc)">${(c.name || '؟').replace(/^شرکت\s*/, '').trim().charAt(0) || '؟'}</div>
                        <div class="min-w-0 flex-1">
                            <p class="font-black text-slate-800 truncate" title="${c.name}">${c.name}</p>
                            <p class="text-[10.5px] text-slate-400 truncate">${c.parent_name ? `<i class="fas fa-sitemap ml-1"></i>زیرمجموعه‌ی ${c.parent_name}` : (children[c.id] ? `<i class="fas fa-crown ml-1 text-amber-500"></i>شرکتِ مادرِ ${e2pNum(children[c.id])} شرکت` : 'شرکتِ مستقل')}</p>
                        </div>
                        ${c.bale_group_chat_id ? '<span class="cmx-chip bg-emerald-50 text-emerald-700" title="گروه بله وصل است"><i class="fas fa-paper-plane"></i>بله</span>' : ''}
                    </div>
                    <div class="px-4 pt-3 flex flex-wrap gap-1.5">
                        <span class="cmx-chip ${c.payment_terms ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-500'}"><i class="fas fa-sack-dollar"></i>${PAY_FA[c.payment_terms] || 'تسویه: مشخص نشده'}</span>
                        ${c.installment_count ? `<span class="cmx-chip bg-indigo-50 text-indigo-700"><i class="fas fa-list-ol"></i>${e2pNum(c.installment_count)} قسط${Number(c.first_due_offset_months) ? ` | اولی ${e2pNum(c.first_due_offset_months)} ماه بعد` : ''}</span>` : ''}
                        <span class="cmx-chip ${iv[1]}"><i class="fas fa-shield-halved"></i>${iv[0]}</span>
                    </div>
                    <div class="px-4 pt-2 text-[10.5px] text-slate-500 space-y-0.5">
                        ${c.economic_code ? `<p><i class="fas fa-hashtag ml-1 text-slate-300"></i>کد اقتصادی: <span dir="ltr">${faDigits(c.economic_code)}</span></p>` : ''}
                        ${c.phone ? `<p><i class="fas fa-phone ml-1 text-slate-300"></i><span dir="ltr">${faDigits(c.phone)}</span></p>` : ''}
                        ${c.address ? `<p class="truncate" title="${c.address}"><i class="fas fa-location-dot ml-1 text-slate-300"></i>${c.address}</p>` : ''}
                    </div>
                    <div class="cmx-stats">
                        <div class="cmx-stat"><b>${e2pNum(c.users_count || 0)}</b><span>کاربر</span></div>
                        <div class="cmx-stat"><b>${e2pNum(c.requests_count || 0)}</b><span>درخواست</span></div>
                        <div class="cmx-stat"><b class="${Number(c.open_requests) ? 'text-amber-600' : ''}">${e2pNum(c.open_requests || 0)}</b><span>باز</span></div>
                        <div class="cmx-stat"><b class="text-emerald-700">${e2pNum(c.issued_count || 0)}</b><span>صادره</span></div>
                    </div>
                    <p class="px-4 text-[10px] text-slate-400">ثبت: ${faDigits(c.created_at_jalali) || '—'}</p>
                    <div class="cmx-act">
                        <button onclick="editCompany(${c.id})" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700"><i class="fas fa-pen ml-1"></i>ویرایش</button>
                        <button onclick="cmOpenRequests(${c.id})" class="bg-slate-100 hover:bg-slate-200 text-slate-700"><i class="fas fa-folder-open ml-1"></i>درخواست‌ها</button>
                        <button onclick="deleteCompanyRow(${c.id}, '${safe}')" class="bg-red-50 hover:bg-red-100 text-red-600" style="flex:0 0 auto;padding:7px 12px" title="حذف"><i class="fas fa-trash-can"></i></button>
                    </div>
                </div>`;
            }).join('');
        }
        function cmOpenRequests(id) {
            switchTab('companies-requests');
            let tries = 0;   // فهرستِ شرکت‌ها در فیلتر بعد از بارگذاریِ تب پر می‌شود
            const t = setInterval(() => {
                const sel = document.getElementById('creq-company');
                if (sel && [...sel.options].some(o => o.value === String(id))) { clearInterval(t); sel.value = String(id); loadCompanyRequests(); }
                else if (++tries > 30) clearInterval(t);
            }, 100);
        }

        // ---------- ورودِ شرکت‌ها از اکسل ----------
        async function openCompanyImport() {
            document.getElementById('cmi-file').value = '';
            document.getElementById('cmi-result').innerHTML = '';
            openModal('company-import-modal');
            const tb = document.getElementById('cmi-cols');
            if (tb.dataset.ok) return;
            const d = await (await fetch(COMPANY_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'company_import_columns'})})).json();
            if (!d.ok) return;
            tb.dataset.ok = '1';
            tb.innerHTML = d.columns.map(c => `<tr><td class="font-black text-slate-800 whitespace-nowrap">${c.title}</td><td>${c.required ? '<span class="text-rose-600 font-black">بله</span>' : '<span class="text-slate-400">خیر</span>'}</td>
                <td class="text-slate-600">${c.note}</td><td class="text-slate-400">${c.aliases.join('، ')}</td><td class="whitespace-nowrap" dir="auto">${c.example ? faDigits(c.example) : '—'}</td></tr>`).join('');
        }
        async function companyImport(commit) {
            const f = document.getElementById('cmi-file').files[0];
            const out = document.getElementById('cmi-result');
            if (!f) { showToast('اول فایلِ اکسل را انتخاب کنید.', 'warning'); return; }
            if (commit && !(await uiConfirm('ثبتِ شرکت‌ها', 'ردیف‌های سالمِ فایل ثبت شوند؟ ردیف‌های دارای خطا کنار گذاشته می‌شوند.', {ok: 'بله، ثبت شود'}))) return;
            out.innerHTML = '<p class="text-center text-xs text-slate-400 py-4"><i class="fas fa-spinner fa-spin ml-1"></i>در حال خواندنِ فایل...</p>';
            const fd = new FormData();
            fd.append('action', 'company_import'); fd.append('file', f);
            if (commit) { fd.append('commit', '1'); if (document.getElementById('cmi-update').checked) fd.append('update_existing', '1'); }
            let d;
            try { d = await (await fetch(COMPANY_API, {method: 'POST', body: fd})).json(); } catch (e) { d = {ok: false, error: 'خطا در ارتباط با سرور.'}; }
            if (!d.ok) { out.innerHTML = `<div class="rounded-xl border-2 border-red-200 bg-red-50 text-red-700 text-xs font-bold p-3">${d.error || 'خطا'}${(d.unknown || []).length ? `<p class="font-normal mt-1">سرستون‌های ناشناخته: ${d.unknown.join('، ')}</p>` : ''}</div>`; return; }
            if (commit) {
                out.innerHTML = `<div class="rounded-xl border-2 border-emerald-200 bg-emerald-50 text-emerald-800 text-sm font-bold p-4"><i class="fas fa-circle-check ml-1"></i>${e2pNum(d.created)} شرکتِ تازه ثبت و ${e2pNum(d.updated)} شرکت به‌روز شد${d.skipped ? `؛ ${e2pNum(d.skipped)} ردیف کنار گذاشته شد` : ''}.</div>`;
                showToast('شرکت‌ها ثبت شدند.', 'success');
                loadCompanyManage();
                return;
            }
            const sm = d.summary || {};
            const ST = {new: ['تازه', 'bg-emerald-100 text-emerald-700'], update: ['به‌روزرسانی', 'bg-sky-100 text-sky-700'], error: ['خطا', 'bg-red-100 text-red-700'], dup: ['تکراری', 'bg-amber-100 text-amber-800']};
            const INS = {BOTH: 'هردو', PASARGAD: 'پاسارگاد', IRAN: 'ایران'};
            const ok = (sm.new || 0) + (sm.update || 0);
            out.innerHTML = `<div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="cmx-chip bg-emerald-100 text-emerald-700">${e2pNum(sm.new || 0)} تازه</span><span class="cmx-chip bg-sky-100 text-sky-700">${e2pNum(sm.update || 0)} به‌روزرسانی</span>
                    <span class="cmx-chip bg-red-100 text-red-700">${e2pNum(sm.error || 0)} خطا</span><span class="cmx-chip bg-amber-100 text-amber-800">${e2pNum(sm.dup || 0)} تکراری</span>
                    ${(d.unknown || []).length ? `<span class="text-[10.5px] text-slate-400">ستون‌های نادیده: ${d.unknown.join('، ')}</span>` : ''}
                    <button type="button" onclick="companyImport(true)" ${ok ? '' : 'disabled'} class="mr-auto text-white text-xs font-black rounded-xl px-4 py-2 disabled:opacity-40" style="background:#059669"><i class="fas fa-check ml-1"></i>ثبتِ ${e2pNum(ok)} شرکت</button></div>
                <div class="overflow-x-auto max-h-[45vh] border-2 border-slate-200 rounded-xl"><table class="w-full text-[11px] text-right whitespace-nowrap cmi-cols no-count"><thead class="sticky top-0"><tr><th>ردیف</th><th>وضعیت</th><th>نام شرکت</th><th>مادر</th><th>تسویه</th><th>اقساط</th><th>بیمه‌گر</th><th>کد اقتصادی</th><th>تلفن</th><th>پیام</th></tr></thead><tbody>
                ${d.rows.map(r => { const x = r.data, st = ST[r.status] || ['', '']; return `<tr class="${r.status === 'error' ? 'bg-red-50/50' : ''}">
                    <td>${e2pNum(r.line)}</td><td><span class="cmx-chip ${st[1]}">${st[0]}</span></td><td class="font-bold">${x.name || '—'}</td><td>${x.parent || '—'}</td>
                    <td>${PAY_FA[x.payment_terms] || '—'}</td><td>${x.installment_count ? e2pNum(x.installment_count) : '—'}</td><td>${INS[x.allowed_insurers] || '—'}</td>
                    <td dir="ltr">${faDigits(x.economic_code || '') || '—'}</td><td dir="ltr">${faDigits(x.phone || '') || '—'}</td>
                    <td class="whitespace-normal min-w-[200px]">${r.errors.map(e => `<p class="text-red-600 font-bold">${e}</p>`).join('')}${r.warnings.map(w => `<p class="text-amber-700">${w}</p>`).join('')}</td></tr>`; }).join('')}
                </tbody></table></div>`;
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
        const ROLE_FA = {ADMIN: 'مدیر کل', OPERATOR: 'کارشناس صدور', FINANCE: 'کارشناس مالی', COMPANY_LIAISON: 'کارمند بیمه با ما', PARSIAN: 'کارمند بیمه با ما (پارسیان)'};
        // دایره‌ی سبز: کاربر با شماره‌اش در ربات بله‌ی شرکت‌ها وارد شده | قرمز: هنوز نه
        function botDot(on) { return `<span class="bot-dot ${on ? 'on' : ''}" title="${on ? 'وصل به ربات بله' : 'هنوز در ربات بله وارد نشده'}"></span>`; }
        let staffUsersCache = [];

        let staffCompanies = [];      // فهرست شرکت‌ها برای انتخابگرِ کاربرانِ شرکتی
        let staffMeId = 0;
        let userTypeFilter = '';
        const userKey = u => u.type + ':' + u.id;
        const findUser = (type, id) => staffUsersCache.find(x => x.type === type && Number(x.id) === Number(id));

        async function loadStaffUsers() {
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'list', include_deleted: document.getElementById('su-show-deleted').checked})});
            const data = await res.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            staffUsersCache = data.users;
            presenceAt = Date.now();
            staffCompanies = data.companies || [];
            staffMeId = Number(data.me);
            document.getElementById('su-schema-note').classList.toggle('hidden', data.schema_ready !== false);
            renderStaffUsers();
            loadResetRequests(data.pending_resets);
        }
        // «آنلاین» سبز یا «آخرین بازدید ...»؛ هر ۵ ثانیه از سرور تازه می‌شود (فقط وقتی همین صفحه باز است)
        let presenceAt = Date.now();
        function presenceCell(p) {
            if (!window.ChatUI || !p) return '<span class="text-slate-300">—</span>';
            const l = ChatUI.seenLabel(p, presenceAt).replace('آخرین بازدید: ', '');
            return p.online ? '<span class="inline-flex items-center gap-1 text-emerald-600 font-black"><span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>آنلاین</span>'
                            : `<span class="text-slate-400">${l}</span>`;
        }
        async function refreshStaffPresence() {
            if (document.getElementById('tab-staff-users')?.classList.contains('hidden') || document.hidden || !staffUsersCache.length) return;
            try {
                const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'presence'})});
                const d = await res.json();
                if (!d.ok) return;
                presenceAt = Date.now();
                staffUsersCache.forEach(u => {
                    const p = d.presence[u.type + ':' + u.id]; if (!p) return;
                    u.presence = p;
                    const td = document.querySelector(`[data-pres="${u.type}:${u.id}"]`);
                    const html = presenceCell(p);
                    if (td && td._h !== html) { td.innerHTML = html; td._h = html; }   // فقط اگر عوض شده (بدونِ چشمک)
                    const dot = td && td.parentElement.querySelector('.cx-av');
                    if (dot) { const on = dot.querySelector('.cx-on'); if (p.online && !on && Number(u.is_deleted) !== 1) dot.insertAdjacentHTML('beforeend', '<b class="cx-on"></b>'); if (!p.online && on) on.remove(); }
                });
            } catch (e) {}
        }
        setInterval(refreshStaffPresence, 5000);
        function setUserTypeFilter(v) {
            userTypeFilter = v;
            document.querySelectorAll('#su-type-seg button').forEach(b => b.classList.toggle('active', b.dataset.v === v));
            if (staffUsersCache.length) renderStaffUsers();
        }
        function renderStaffUsers() {
            const q = p2e(document.getElementById('su-q').value.trim()).toLowerCase();
            const role = document.getElementById('su-role-filter').value;
            const alive = staffUsersCache.filter(u => Number(u.is_deleted) !== 1);
            document.getElementById('su-n-all').textContent = '(' + e2p(alive.length) + ')';
            document.getElementById('su-n-staff').textContent = '(' + e2p(alive.filter(u => u.type === 'STAFF').length) + ')';
            document.getElementById('su-n-company').textContent = '(' + e2p(alive.filter(u => u.type === 'COMPANY').length) + ')';
            const list = staffUsersCache.filter(u => (!userTypeFilter || u.type === userTypeFilter) && (!role || u.role === role || (role === 'LIAISON_ALL' && ['COMPANY_LIAISON', 'PARSIAN'].includes(u.role)))
                && (!q || [u.full_name, u.username, u.mobile_number, u.personnel_code, u.company_names, ROLE_FA[u.role]].join(' ').toLowerCase().includes(q)));
            document.getElementById('su-body').innerHTML = list.map(u => {
                const del = Number(u.is_deleted) === 1, co = u.type === 'COMPANY';
                const roleChip = co ? '<span class="su-chip co"><i class="fas fa-building ml-1"></i>کاربر شرکت</span>'
                                    : u.role === 'PARSIAN' ? '<span class="su-chip">کارمند بیمه با ما</span> <span class="su-chip" style="background:#fff1f2;color:#be123c" title="فقط ساخت گزارش بازدید و گزارش‌های صادره‌ی خودش">پنل پارسیان</span>'
                                    : `<span class="su-chip ${u.role === 'ADMIN' ? 'ad' : ''}">${ROLE_FA[u.role] || u.role}</span>`;
                const permChip = !co && u.perm_custom ? ' <span class="su-chip pc" title="دسترسیِ صفحه‌به‌صفحه تعیین شده"><i class="fas fa-user-lock ml-1"></i>سفارشی</span>' : '';
                const isMe = !co && Number(u.id) === staffMeId;
                return `<tr class="border-t border-slate-100 ${del ? 'opacity-50' : ''}">
                    <td class="p-3 font-bold"><span class="inline-flex items-center gap-2">${window.ChatUI ? ChatUI.avatarHtml(u.avatar, u.full_name, {size: 34, online: !del && u.presence && u.presence.online}) : ''}<span>${del ? '' : botDot(u.bot_linked)} ${u.full_name}${isMe ? ' <span class="text-[10px] text-blue-500 font-normal">(شما)</span>' : ''}${del ? ` <span class="text-[10px] text-red-500 font-normal">(حذف‌شده ${u.deleted_at ? faDigits(toJalali(u.deleted_at)) : ''})</span>` : ''}</span></span></td>
                    <td class="p-3 font-mono">${u.username}</td>
                    <td class="p-3">${roleChip}${permChip}</td>
                    <td class="p-3 text-slate-500">${co ? (u.company_names || '—') : `<span class="font-mono">${faDigits(u.personnel_code || '') || '—'}</span>`}</td>
                    <td class="p-3 font-mono text-slate-500" dir="ltr">${faDigits(u.mobile_number || '') || '—'}</td>
                    <td class="p-3" data-pres="${u.type}:${u.id}" title="${u.last_login_jalali ? 'آخرین ورود: ' + faDigits(u.last_login_jalali) : ''}">${presenceCell(u.presence)}</td>
                    <td class="p-3 whitespace-nowrap">${del ? '' : `
                        ${isMe ? '' : `<button onclick="openUserDM('${u.type}', ${u.id})" class="text-emerald-600 hover:underline text-xs font-bold ml-2" title="پیام مستقیم"><i class="fas fa-paper-plane ml-1"></i>پیام</button>`}
                        <button onclick="openEditStaffUser('${u.type}', ${u.id})" class="text-blue-600 hover:underline text-xs font-bold ml-2"><i class="fas fa-pen ml-1"></i>ویرایش</button>
                        ${!co && REAL_ADMIN && u.role !== 'ADMIN' ? `<button onclick="openPermEditor(${u.id})" class="text-violet-600 hover:underline text-xs font-bold ml-2"><i class="fas fa-user-lock ml-1"></i>دسترسی‌ها</button>` : ''}
                        ${isMe ? '' : `<button onclick="openDeleteStaffUser('${u.type}', ${u.id})" class="text-red-500 hover:underline text-xs font-bold"><i class="fas fa-user-slash ml-1"></i>حذف</button>`}`}</td>
                </tr>`;
            }).join('') || `<tr><td colspan="7" class="text-center p-6 text-slate-400">${staffUsersCache.length ? 'کاربری با این جستجو/فیلتر پیدا نشد.' : 'کاربری ثبت نشده.'}</td></tr>`;
        }

        // ---------- لیستِ کشوییِ چندانتخابی (با جستجو) ----------
        function msdMount(id, items, selected = [], placeholder = 'انتخاب کنید…') {
            const el = document.getElementById(id);
            el._items = items; el._sel = new Set(selected.map(Number)); el._ph = placeholder;
            el.innerHTML = `<button type="button" class="msd-trigger"></button>
                <div class="msd-panel hidden"><input type="search" placeholder="جستجو…"><div class="msd-tools"><button type="button" data-all>انتخابِ همه</button><button type="button" data-none>هیچ</button><span></span></div><div class="msd-list"></div></div>`;
            const trig = el.querySelector('.msd-trigger'), panel = el.querySelector('.msd-panel'), q = panel.querySelector('input');
            const draw = () => {
                const chosen = el._items.filter(it => el._sel.has(Number(it.id)));
                trig.innerHTML = chosen.length ? chosen.map(it => `<span class="msd-chip">${it.name}<i data-rm="${it.id}" title="برداشتن">✕</i></span>`).join('') : `<span class="msd-ph">${el._ph}</span>`;
                const t = q.value.trim().toLowerCase();
                panel.querySelector('.msd-list').innerHTML = el._items.filter(it => !t || String(it.name).toLowerCase().includes(t)).map(it =>
                    `<div class="msd-opt ${el._sel.has(Number(it.id)) ? 'on' : ''}" data-id="${it.id}"><span class="bx">✓</span>${it.name}</div>`).join('')
                    || '<p class="text-center text-[11px] text-slate-400 py-3">موردی پیدا نشد.</p>';
                panel.querySelector('.msd-tools span').textContent = e2p(el._sel.size) + ' انتخاب‌شده از ' + e2p(el._items.length);
            };
            trig.addEventListener('click', e => {
                const rm = e.target.closest('[data-rm]');
                if (rm) { el._sel.delete(Number(rm.dataset.rm)); draw(); e.stopPropagation(); return; }
                const open = panel.classList.contains('hidden');
                document.querySelectorAll('.msd.open').forEach(m => { m.classList.remove('open'); m.querySelector('.msd-panel').classList.add('hidden'); });
                if (open) { el.classList.add('open'); panel.classList.remove('hidden'); q.value = ''; draw(); setTimeout(() => { q.focus(); panel.scrollIntoView({block: 'nearest', behavior: 'smooth'}); }, 30); }
            });
            q.addEventListener('input', draw);
            panel.addEventListener('click', e => {
                const o = e.target.closest('.msd-opt');
                if (o) { const v = Number(o.dataset.id); el._sel.has(v) ? el._sel.delete(v) : el._sel.add(v); draw(); return; }
                if (e.target.closest('[data-all]')) { const t = q.value.trim().toLowerCase(); el._items.forEach(it => { if (!t || String(it.name).toLowerCase().includes(t)) el._sel.add(Number(it.id)); }); draw(); }
                if (e.target.closest('[data-none]')) { el._sel.clear(); draw(); }
            });
            draw();
        }
        const msdValue = id => [...(document.getElementById(id)._sel || [])];
        document.addEventListener('mousedown', e => {
            document.querySelectorAll('.msd.open').forEach(m => { if (!m.contains(e.target)) { m.classList.remove('open'); m.querySelector('.msd-panel').classList.add('hidden'); } });
        });

        function showMobileErr(prefix, msg) {
            const el = document.getElementById(prefix + '-mobile-err');
            el.textContent = msg || ''; el.classList.toggle('hidden', !msg);
            if (msg) document.getElementById(prefix + '-mobile').focus();
        }
        // عکسِ پروفایل (عکسِ گالری همان لحظه بارگذاری و مسیرش در فرم نگه داشته می‌شود)
        function paintUserAvatar(prefix) {
            const pv = document.getElementById(prefix + '-avatar-pv');
            if (pv && window.ChatUI) pv.innerHTML = ChatUI.avatarHtml(document.getElementById(prefix + '-avatar').value || null, document.getElementById(prefix + '-fullname').value || '؟', {size: 58});
        }
        async function pickUserAvatar(prefix) {
            if (!window.ChatUI) return;
            const r = await ChatUI.pickAvatar({current: document.getElementById(prefix + '-avatar').value, name: document.getElementById(prefix + '-fullname').value});
            if (!r) return;
            let val = r.avatar || '';
            if (r.file) {
                const fd = new FormData(); fd.append('avatar', r.file, 'avatar.jpg');
                const d = await chatUpload('chat_avatar_upload', fd);
                if (!d.ok) { showToast(d.error || 'بارگذاریِ عکس ممکن نشد.', 'error'); return; }
                val = d.path;
            }
            document.getElementById(prefix + '-avatar').value = val;
            const ch = document.getElementById(prefix + '-avatar-changed'); if (ch) ch.value = '1';
            paintUserAvatar(prefix);
        }

        // ---------- فرمِ یکپارچه‌ی کاربر (ساخت و ویرایشِ همه‌ی نقش‌ها) ----------
        const ufG = id => document.getElementById(id);
        function ufOpen(mode, type, id) {
            const u = mode === 'edit' ? findUser(type, id) : null;
            if (mode === 'edit' && !u) return;
            const co = u && u.type === 'COMPANY';
            ufG('uf-mode').value = mode; ufG('uf-id').value = u ? u.id : ''; ufG('uf-type').value = u ? u.type : '';
            ufG('uf-role').value = ''; ufG('uf-avatar').value = u ? (u.avatar || '') : ''; ufG('uf-avatar-changed').value = '';
            ufG('uf-fullname').value = u ? (u.full_name || '') : ''; ufG('uf-username').value = u ? u.username : '';
            ufG('uf-username').disabled = !!u; ufG('uf-user-lock').classList.toggle('hidden', !u);
            ufG('uf-password').value = ''; ufG('uf-mobile').value = u ? (u.mobile_number || '') : ''; ufG('uf-personnel').value = u && !co ? (u.personnel_code || '') : '';
            ufG('uf-rpw').value = ''; ufG('uf-rpw-clear').checked = false; ufG('uf-admin-pass').value = '';
            ufG('uf-pass-lbl').textContent = u ? 'رمز عبورِ جدید (خالی = بدون تغییر)' : 'رمز عبور * (حداقل ۶)';
            ufG('uf-kicker').textContent = u ? (co ? 'کاربرِ شرکت' : 'کاربرِ داخلی') : 'کاربرِ تازه';
            ufG('uf-title').textContent = u ? 'ویرایشِ ' + (u.full_name || u.username) : 'افزودن کاربر';
            ufG('uf-sub').textContent = u ? 'نام کاربری ثابت است؛ بقیه‌ی مشخصات، نقش و شرکت‌ها قابلِ تغییرند.' : 'نقش را انتخاب کنید؛ فقط فیلدهای همان نقش نمایش داده می‌شود.';
            ufG('uf-submit').querySelector('span').textContent = u ? 'ذخیره‌ی تغییرات' : 'ثبتِ کاربر';
            ufG('uf-admin-sec').classList.toggle('hidden', !u);
            ufG('uf-rpw-clear-box').classList.toggle('hidden', !u);
            const st = ufG('uf-rpw-state');
            st.classList.toggle('hidden', !u || co);
            if (u && !co) { st.textContent = u.has_report_pw ? 'تعیین شده' : 'همان رمزِ پنل'; st.className = 'text-[10px] font-bold rounded-full px-2 py-0.5 ' + (u.has_report_pw ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-500'); }
            // نقش‌های داخلی و «کاربر شرکت» در دو جدولِ جدا هستند؛ در ویرایش، نوعِ حساب عوض نمی‌شود
            const isMe = u && !co && Number(u.id) === staffMeId;
            ufG('uf-roles').querySelectorAll('button').forEach(b => { b.classList.remove('on'); b.disabled = !!u && ((b.dataset.r === 'COMPANY') !== co || (isMe && b.dataset.r !== 'ADMIN')); });
            const note = ufG('uf-role-note');
            note.classList.toggle('hidden', !u);
            note.textContent = !u ? '' : isMe ? 'نقشِ حسابِ خودتان (مدیر کل) قابلِ تغییر نیست.' : co ? 'این حساب «کاربر شرکت» است و به نقشِ داخلی تبدیل نمی‌شود (برای آن، کاربرِ تازه بسازید).' : 'حسابِ داخلی به «کاربر شرکت» تبدیل نمی‌شود؛ بقیه‌ی نقش‌ها قابلِ انتخاب‌اند.';
            msdMount('uf-companies', staffCompanies, co ? (u.company_ids || []) : [], 'شرکت(ها) را انتخاب کنید…');
            const cc = u && u.chat_companies;
            msdMount('uf-chatcos', staffCompanies, (cc && cc.ids) || [], 'شرکت‌هایی که می‌تواند با آن‌ها گفتگو کند…');
            ufChatMode((cc && cc.mode) || 'ROLE');
            showMobileErr('uf', '');
            paintUserAvatar('uf');
            ufG('uf-rest').classList.add('hidden');
            ufG('uf-liaison-sub').classList.add('hidden');
            if (u) ufPickRole(co ? 'COMPANY' : (['COMPANY_LIAISON', 'PARSIAN'].includes(u.role) ? 'LIAISON' : u.role), u.role);
            openModal('user-form-modal');
        }
        function ufPickRole(r, exact) {
            ufG('uf-roles').querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.r === r));
            const liaison = r === 'LIAISON', co = r === 'COMPANY';
            ufG('uf-liaison-sub').classList.toggle('hidden', !liaison);
            if (liaison) ufPickPanel(exact === 'PARSIAN' ? 'PARSIAN' : 'COMPANY_LIAISON');
            else ufG('uf-role').value = r;
            ufG('uf-company-sec').classList.toggle('hidden', !co);
            ufG('uf-chat-sec').classList.toggle('hidden', co || r === 'ADMIN');
            ufG('uf-rpw-sec').classList.toggle('hidden', co);
            ufG('uf-personnel-box').classList.toggle('hidden', co);
            const rest = ufG('uf-rest');
            if (rest.classList.contains('hidden')) { rest.classList.remove('hidden'); if (ufG('uf-mode').value === 'create') setTimeout(() => ufG('uf-fullname').focus(), 60); }
        }
        function ufPickPanel(p) {
            ufG('uf-role').value = p;
            ufG('uf-liaison-sub').querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.p === p));
        }
        function ufChatMode(m) {
            ufG('uf-chat-mode').dataset.v = m;
            ufG('uf-chat-mode').querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.v === m));
            ufG('uf-chatcos').classList.toggle('hidden', m !== 'LIST');
        }
        function ufGenPass() {
            const c = 'abcdefghjkmnpqrstuvwxyz23456789';
            let p = ''; const a = new Uint32Array(8); crypto.getRandomValues(a); a.forEach(x => { p += c[x % c.length]; });
            ufG('uf-password').value = p; ufG('uf-password').focus(); ufG('uf-password').select();
            showToast('رمزِ تصادفی ساخته شد؛ آن را به کاربر بدهید.', 'info');
        }
        async function ufSubmit() {
            const mode = ufG('uf-mode').value, role = ufG('uf-role').value, edit = mode === 'edit';
            if (!role) { showToast('اول نقشِ کاربر را انتخاب کنید.', 'warning'); return; }
            const co = role === 'COMPANY';
            const body = {full_name: ufG('uf-fullname').value.trim(), mobile_number: p2e(ufG('uf-mobile').value.trim()), password: ufG('uf-password').value};
            if (!body.full_name) { showToast('نامِ کاربر را وارد کنید.', 'error'); ufG('uf-fullname').focus(); return; }
            if (co) {
                body.company_ids = msdValue('uf-companies');
                if (!body.company_ids.length) { showToast('حداقل یک شرکت را انتخاب کنید.', 'warning'); return; }
            } else {
                body.personnel_code = ufG('uf-personnel').value.trim();
                body.report_edit_password = ufG('uf-rpw').value;
                if (role !== 'ADMIN') { const m = ufG('uf-chat-mode').dataset.v || 'ROLE'; body.chat_companies = m === 'LIST' ? msdValue('uf-chatcos') : m; }
            }
            if (ufG('uf-avatar-changed').value || !edit) body.avatar = ufG('uf-avatar').value;
            if (edit) {
                Object.assign(body, {action: 'update', type: ufG('uf-type').value, id: Number(ufG('uf-id').value), admin_password: ufG('uf-admin-pass').value});
                if (!co) { body.role = role; body.report_edit_password_clear = ufG('uf-rpw-clear').checked; }
                if (!body.admin_password) { showToast('برای تأیید، رمزِ خودتان را وارد کنید.', 'warning'); ufG('uf-admin-pass').focus(); return; }
            } else {
                Object.assign(body, {action: 'create', role, username: ufG('uf-username').value.trim()});
                if (!body.username || !body.password) { showToast('نام کاربری و رمز عبور الزامی است.', 'error'); return; }
            }
            showMobileErr('uf', '');
            const btn = ufG('uf-submit'); btn.disabled = true;
            try {
                const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا', 'error'); if (data.field === 'mobile') showMobileErr('uf', data.error); return; }
                showToast(edit ? (data.bot_unlinked ? 'ذخیره شد؛ شماره عوض شد و کاربر از ربات بیرون آمد (پیام برایش فرستاده شد).' : 'تغییرات ذخیره شد.') : 'کاربر ثبت شد.', 'success');
                if (data.warning) showToast(data.warning, 'warning');
                closeModal('user-form-modal');
                loadStaffUsers();
            } catch (e) { showToast('خطا در ارتباط با سرور', 'error'); }
            finally { btn.disabled = false; }
        }
        function openAddUserModal() { ufOpen('create'); }
        function openEditStaffUser(type, id) { ufOpen('edit', type, id); }
        // ---------- ویرایشگرِ دسترسیِ صفحه‌به‌صفحه ----------
        const REAL_ADMIN = <?php echo $realRole === 'ADMIN' ? 'true' : 'false'; ?>;
        let PM = null;   // {user, catalog, ops, role_default, perms, mode}
        async function openPermEditor(id) {
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'perm_get', id})});
            const d = await res.json();
            if (!d.ok) { showToast(d.error || 'خطا', 'error'); return; }
            const clone = o => JSON.parse(JSON.stringify(o || {}));
            PM = {user: d.user, catalog: d.catalog, ops: d.ops, roleDef: clone(d.role_default), perms: d.custom ? clone(d.perms) : clone(d.role_default), mode: d.custom ? 'custom' : 'role'};
            document.getElementById('pm-name').textContent = d.user.name;
            document.getElementById('pm-role').textContent = ROLE_FA[d.user.role] || d.user.role;
            document.getElementById('pm-admin-pass').value = '';
            document.getElementById('pm-copy').innerHTML = '<option value="">کپی از کاربرِ دیگر…</option>' + (d.others || []).map(o => `<option value="${o.id}">${o.name} (${ROLE_FA[o.role] || o.role}${o.custom ? ' · سفارشی' : ''})</option>`).join('');
            pmSetMode(PM.mode);
            openModal('perm-modal');
        }
        function pmSetMode(m) {
            if (!PM) return;
            pmCollect();
            PM.mode = m;
            if (m === 'role') PM.perms = JSON.parse(JSON.stringify(PM.roleDef));
            document.querySelectorAll('.pm-seg button').forEach(b => b.classList.toggle('on', b.dataset.m === m));
            document.getElementById('pm-tools').classList.toggle('hidden', m !== 'custom');
            pmRender();
        }
        function pmRender() {
            const ops = Object.keys(PM.ops);
            const has = (k, op) => (PM.perms[k] || []).includes(op);
            document.getElementById('pm-body').innerHTML = (PM.mode === 'role' ? `<p class="text-[11px] text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-xl px-3 py-2 mb-3"><i class="fas fa-circle-info ml-1"></i>این کاربر همان دسترسی‌هایی را دارد که نقشِ «${ROLE_FA[PM.user.role] || PM.user.role}» دارد (جدولِ زیر فقط نمایش است). برای تغییر، «سفارشی» را بزنید.</p>` : '')
                + PM.catalog.map((g, gi) => `<div class="pm-grp"><h4><i class="fas fa-layer-group text-indigo-500"></i>${g.group}
                    <button type="button" onclick="pmGroup(${gi}, true)" class="pm-row-all mr-auto">همه</button><button type="button" onclick="pmGroup(${gi}, false)" class="pm-row-all text-rose-500">هیچ</button></h4>
                    <table class="pm-tbl"><thead><tr><th>صفحه</th>${ops.map(op => `<th>${PM.ops[op]}<button type="button" onclick="pmCol(${gi}, '${op}')">همه/هیچ</button></th>`).join('')}<th></th></tr></thead><tbody>
                    ${g.pages.map(p => `<tr data-k="${p.key}"><td class="font-bold text-slate-700">${p.title}</td>
                        ${ops.map(op => p.ops.includes(op) ? `<td><input type="checkbox" class="pm-cb" data-k="${p.key}" data-op="${op}" ${has(p.key, op) ? 'checked' : ''} onchange="pmChanged(this)"></td>` : '<td class="pm-na">—</td>').join('')}
                        <td><button type="button" onclick="pmRow('${p.key}')" class="pm-row-all">همه/هیچ</button></td></tr>`).join('')}
                    </tbody></table></div>`).join('');
            document.getElementById('pm-body').classList.toggle('pm-locked', PM.mode !== 'custom');
            pmSummary();
        }
        function pmCollect() {
            if (!PM || PM.mode !== 'custom') return;
            const out = {};
            document.querySelectorAll('#pm-body .pm-cb:checked').forEach(cb => { (out[cb.dataset.k] = out[cb.dataset.k] || []).push(cb.dataset.op); });
            PM.perms = out;
        }
        // هر عملیاتی جز «مشاهده» خودش «مشاهده» را هم روشن می‌کند؛ برداشتنِ «مشاهده» همه‌ی عملیاتِ آن صفحه را برمی‌دارد
        function pmChanged(cb) {
            const row = document.querySelectorAll(`#pm-body .pm-cb[data-k="${cb.dataset.k}"]`);
            if (cb.dataset.op === 'view' && !cb.checked) row.forEach(x => { x.checked = false; });
            if (cb.dataset.op !== 'view' && cb.checked) row.forEach(x => { if (x.dataset.op === 'view') x.checked = true; });
            pmCollect(); pmSummary();
        }
        function pmRow(k) {
            const row = [...document.querySelectorAll(`#pm-body .pm-cb[data-k="${k}"]`)];
            const on = !row.every(x => x.checked);
            row.forEach(x => { x.checked = on; });
            pmCollect(); pmSummary();
        }
        function pmCol(gi, op) {
            const keys = PM.catalog[gi].pages.map(p => p.key);
            const cbs = [...document.querySelectorAll(`#pm-body .pm-cb[data-op="${op}"]`)].filter(x => keys.includes(x.dataset.k));
            const on = !cbs.every(x => x.checked);
            cbs.forEach(x => { x.checked = on; if (on && op !== 'view') { const v = document.querySelector(`#pm-body .pm-cb[data-k="${x.dataset.k}"][data-op="view"]`); if (v) v.checked = true; }
                                               if (!on && op === 'view') document.querySelectorAll(`#pm-body .pm-cb[data-k="${x.dataset.k}"]`).forEach(y => { y.checked = false; }); });
            pmCollect(); pmSummary();
        }
        function pmGroup(gi, on) {
            const keys = PM.catalog[gi].pages.map(p => p.key);
            document.querySelectorAll('#pm-body .pm-cb').forEach(x => { if (keys.includes(x.dataset.k)) x.checked = on; });
            pmCollect(); pmSummary();
        }
        function pmPreset(kind) {
            const out = {};
            PM.catalog.forEach(g => g.pages.forEach(p => {
                if (kind === 'all') out[p.key] = p.ops.slice();
                else if (kind === 'view') out[p.key] = ['view'];
                else if (kind === 'viewexp') out[p.key] = p.ops.filter(o => o === 'view' || o === 'export');
            }));
            PM.perms = kind === 'role' ? JSON.parse(JSON.stringify(PM.roleDef)) : out;
            pmRender();
        }
        async function pmCopyFrom(id) {
            if (!id) return;
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'perm_peek', id: Number(id)})});
            const d = await res.json();
            document.getElementById('pm-copy').value = '';
            if (!d.ok) { showToast(d.error || 'خطا', 'error'); return; }
            PM.perms = JSON.parse(JSON.stringify(d.perms || {}));
            pmRender();
            showToast('دسترسی‌ها کپی شد؛ برای ثبت «ذخیره» را بزنید.', 'info');
        }
        function pmSummary() {
            const pages = Object.keys(PM.perms).filter(k => (PM.perms[k] || []).length);
            const cnt = op => pages.filter(k => PM.perms[k].includes(op)).length;
            document.getElementById('pm-summary').innerHTML = PM.mode !== 'custom' ? 'پیش‌فرضِ نقش' :
                `<b>${e2pNum(pages.length)}</b> صفحه قابلِ مشاهده · ثبت در ${e2pNum(cnt('create'))} · ویرایش در ${e2pNum(cnt('edit'))} · حذف در ${e2pNum(cnt('delete'))} · خروجی در ${e2pNum(cnt('export'))}`;
        }
        async function pmSave() {
            pmCollect();
            const pass = document.getElementById('pm-admin-pass').value;
            if (!pass) { showToast('برای تایید، رمزِ خودتان را وارد کنید.', 'warning'); document.getElementById('pm-admin-pass').focus(); return; }
            if (PM.mode === 'custom' && !Object.keys(PM.perms).length && !(await uiConfirm('بدونِ هیچ دسترسی', 'این کاربر به هیچ صفحه‌ای دسترسی نخواهد داشت. ادامه می‌دهید؟', {ok: 'بله، ذخیره شود', danger: true}))) return;
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'perm_save', id: PM.user.id, mode: PM.mode, perms: PM.perms, admin_password: pass})});
            const d = await res.json();
            if (!d.ok) { showToast(d.error || 'خطا', 'error'); return; }
            showToast(d.custom ? 'دسترسیِ سفارشی ذخیره شد؛ از درخواستِ بعدیِ کاربر اعمال می‌شود.' : 'کاربر به دسترسیِ پیش‌فرضِ نقشش برگشت.', 'success');
            closeModal('perm-modal');
            loadStaffUsers();
        }
        function openDeleteStaffUser(type, id) {
            const u = findUser(type, id);
            if (!u) return;
            document.getElementById('dsu-id').value = u.id;
            document.getElementById('dsu-type').value = type;
            document.getElementById('dsu-name').textContent = u.full_name;
            document.getElementById('dsu-admin-pass').value = '';
            openModal('delete-staff-user-modal');
        }
        async function confirmDeleteStaffUser() {
            const pass = document.getElementById('dsu-admin-pass').value;
            if (!pass) { showToast('برای تایید، رمزِ خودتان را وارد کنید.', 'warning'); return; }
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'delete', type: document.getElementById('dsu-type').value, id: Number(document.getElementById('dsu-id').value), admin_password: pass})});
            const data = await res.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            showToast('کاربر حذف شد؛ نامش روی کارهای قبلی‌اش باقی می‌ماند.', 'success');
            closeModal('delete-staff-user-modal');
            loadStaffUsers();
        }

        // ---- پیام مستقیم ----
        function openUserDM(type, id) {
            const u = findUser(type, id);
            if (!u) return;
            // گفتگو در پیام‌رسانِ یکپارچه: همکارِ داخلی ← گفتگوی مستقیم؛ کاربرِ شرکت با یک شرکت ← گفتگوی آن شرکت
            if (type !== 'COMPANY') { switchTab('tickets', 'S:' + u.id); return; }
            if ((u.company_ids || []).length === 1) { switchTab('tickets', 'C:' + u.company_ids[0]); return; }
            document.getElementById('dm-id').value = u.id;
            document.getElementById('dm-type').value = type;
            document.getElementById('dm-name').textContent = u.full_name;
            document.getElementById('dm-text').value = '';
            document.getElementById('dm-channel').innerHTML = type === 'COMPANY'
                ? (u.bot_linked ? `${botDot(true)} پیام در <b>ربات بله‌ی شرکت‌ها</b> برای کاربر فرستاده می‌شود.` : `${botDot(false)} <span class="text-red-600">این کاربر هنوز وارد ربات بله نشده؛ تا وارد نشود پیام به دستش نمی‌رسد.</span>`)
                : `پیام در <b>چتِ داخلیِ پنل</b> ثبت می‌شود${u.bot_linked ? ' و در <b>ربات بله</b> هم برایش فرستاده می‌شود.' : ' (در ربات بله وارد نشده، پس فقط در پنل می‌بیند).'}`;
            openModal('dm-user-modal');
            setTimeout(() => document.getElementById('dm-text').focus(), 50);
        }
        async function sendUserDM() {
            const text = document.getElementById('dm-text').value.trim();
            if (!text) { showToast('متن پیام را بنویسید.', 'warning'); return; }
            const btn = document.getElementById('dm-send-btn'); btn.disabled = true;
            try {
                const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'send_message', type: document.getElementById('dm-type').value, id: Number(document.getElementById('dm-id').value), message: text})});
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
                showToast(data.bot && data.panel ? 'پیام در پنل ثبت و در ربات بله هم فرستاده شد.' : data.bot ? 'پیام در ربات بله فرستاده شد.' : 'پیام در چتِ داخلیِ پنل ثبت شد.', 'success');
                closeModal('dm-user-modal');
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
            finally { btn.disabled = false; }
        }

        // ---- درخواست‌های بازیابی رمز (از ربات بله) ----
        async function loadResetRequests(pendingCount) {
            const card = document.getElementById('su-resets-card');
            try {
                const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'reset_requests'})});
                const data = await res.json();
                if (!data.ok) { card.classList.add('hidden'); return; }
                const rows = data.rows || [];
                card.classList.toggle('hidden', rows.length === 0);
                const pending = rows.filter(r => r.status === 'PENDING');
                document.getElementById('su-resets-count').textContent = pending.length ? e2p(pending.length) + ' در انتظار' : 'بدون مورد در انتظار';
                const st = {PENDING: ['در انتظار', 'bg-amber-100 text-amber-700'], APPROVED: ['تایید شد', 'bg-emerald-100 text-emerald-700'], REJECTED: ['رد شد', 'bg-red-100 text-red-600']};
                document.getElementById('su-resets').innerHTML = rows.slice(0, 20).map(r => `
                    <div class="border rounded-xl p-3 flex flex-wrap items-center gap-2 text-xs ${r.status === 'PENDING' ? 'bg-amber-50/40 border-amber-200' : 'bg-white'}">
                        <div class="flex-1 min-w-[180px]">
                            <b>${r.full_name || '—'}</b> <span class="text-[10px] text-slate-500">(${r.role_fa} · ${r.username || ''})</span>
                            <div class="text-[10px] text-slate-400">درخواست: ${faDigits(r.requested_jalali || '')}${r.handled_jalali ? ' · بررسی: ' + faDigits(r.handled_jalali) + (r.handled_by_name ? ' توسط ' + r.handled_by_name : '') : ''}${r.note ? ' · ' + r.note : ''}</div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${st[r.status][1]}">${st[r.status][0]}</span>
                        ${r.status === 'PENDING' ? `
                            <input type="text" id="rr-pass-${r.id}" placeholder="رمز جدید (حداقل ۶)" dir="ltr" class="border rounded-lg px-2 py-1 text-xs w-36">
                            <button onclick="genResetPass(${r.id})" class="bg-slate-100 hover:bg-slate-200 px-2 py-1 rounded-lg text-[11px]" title="ساختِ رمزِ تصادفی"><i class="fas fa-dice"></i></button>
                            <button onclick="handleReset(${r.id}, true)" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded-lg text-[11px] font-bold">تایید و ارسال رمز</button>
                            <button onclick="handleReset(${r.id}, false)" class="bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1 rounded-lg text-[11px] font-bold">رد</button>` : ''}
                    </div>`).join('');
            } catch (e) { card.classList.add('hidden'); }
        }
        function genResetPass(id) {
            const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
            let p = ''; const buf = new Uint32Array(8); crypto.getRandomValues(buf);
            buf.forEach(n => p += chars[n % chars.length]);
            document.getElementById('rr-pass-' + id).value = p;
        }
        async function handleReset(id, approve) {
            const body = {action: 'handle_reset', id, approve};
            if (approve) {
                body.new_password = document.getElementById('rr-pass-' + id).value.trim();
                if (body.new_password.length < 6) { showToast('رمزِ جدید حداقل ۶ کاراکتر.', 'warning'); return; }
            } else {
                const note = await uiPrompt('رد درخواست بازیابی رمز', '', {ok: 'رد درخواست', danger: true,
                    hint: 'علتِ رد اختیاری است و در ربات بله برای کاربر فرستاده می‌شود.', placeholder: 'علتِ رد (اختیاری)'});
                if (note === null) return;
                body.note = note;
            }
            const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
            const data = await res.json();
            if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
            showToast((approve ? 'رمز تغییر کرد' : 'درخواست رد شد') + (data.sent ? ' و پیام در ربات برای کاربر فرستاده شد.' : '؛ ولی ارسالِ پیام در ربات ممکن نشد (کاربر به ربات وصل نیست).'), data.sent ? 'success' : 'warning');
            loadStaffUsers();
        }

        // ---- لاگ ورود ----
        let loginLogsCache = [];
        async function loadLoginLogs() {
            const tb = document.getElementById('ll-body');
            tb.innerHTML = '<tr><td colspan="9" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin"></i></td></tr>';
            try {
                const res = await fetch(STAFF_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'login_logs'})});
                const data = await res.json();
                if (!data.ok) { tb.innerHTML = `<tr><td colspan="9" class="text-center p-8 text-amber-600">${data.error || 'خطا'}</td></tr>`; return; }
                loginLogsCache = data.rows || [];
                renderLoginLogs();
            } catch (e) { tb.innerHTML = '<tr><td colspan="9" class="text-center p-8 text-red-500">خطا در اتصال به سرور.</td></tr>'; }
        }
        function renderLoginLogs() {
            const q = p2e(document.getElementById('ll-q').value.trim()).toLowerCase();
            const type = document.getElementById('ll-type').value, method = document.getElementById('ll-method').value, ok = document.getElementById('ll-ok').value;
            const list = loginLogsCache.filter(r => (!type || r.user_type === type)
                && (!method || (method === 'IN' ? r.method !== 'LOGOUT' : r.method === method)) && (ok === '' || String(r.success) === ok)
                && (!q || [r.user_name, r.browser, r.os, r.device, r.ip, r.role_fa, r.at_jalali, faDigits(r.at_jalali || ''), r.method === 'LOGOUT' ? 'خروج' : 'ورود'].join(' ').toLowerCase().includes(q)));
            document.getElementById('ll-body').innerHTML = list.slice(0, 500).map(r => `
                <tr class="border-t border-slate-100 ${Number(r.success) ? (r.method === 'LOGOUT' ? 'bg-slate-50/70' : '') : 'bg-red-50/40'}">
                    <td class="p-3 whitespace-nowrap">${faDigits(r.at_jalali || '')}</td>
                    <td class="p-3 font-bold">${r.user_name || '—'}</td>
                    <td class="p-3 text-slate-500">${r.role_fa}</td>
                    <td class="p-3">${r.method === 'LOGOUT' ? '<span class="text-slate-500 font-bold"><i class="fas fa-right-from-bracket ml-1"></i>خروج</span>' : r.method === 'OTP' ? '<span class="text-cyan-700">ورود · کد بله</span>' : 'ورود · رمز عبور'}</td>
                    <td class="p-3">${r.method === 'LOGOUT' ? '<span class="text-slate-500">↩ خارج شد</span>' : Number(r.success) ? '<span class="text-emerald-600 font-bold">✓ موفق</span>' : `<span class="text-red-500 font-bold">✗ ${r.note || 'ناموفق'}</span>`}</td>
                    <td class="p-3" dir="ltr">${r.browser || '—'}</td>
                    <td class="p-3" dir="ltr">${r.os || '—'}</td>
                    <td class="p-3">${r.device || '—'}</td>
                    <td class="p-3 font-mono text-slate-400" dir="ltr">${r.ip || '—'}</td>
                </tr>`).join('') || '<tr><td colspan="9" class="text-center p-8 text-slate-400">موردی نیست.</td></tr>';
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

        function switchTab(tabId, tabKey) {
            // «tickets:P:12» (از اعلان‌ها) یعنی تبِ گفتگوها و مستقیم همان گفتگو
            if (typeof tabId === 'string' && tabId.indexOf('tickets:') === 0) { tabKey = tabId.slice(8); tabId = 'tickets'; }
            if (PERM.custom && !permCan(tabId)) { showToast('به این بخش دسترسی ندارید.', 'error'); return; }
            permTab = tabId;
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
            // زیرمنوی درختیِ والد هم نشانه‌دار می‌شود
            document.querySelectorAll('.menu-sub-trigger.sub-active').forEach(t => t.classList.remove('sub-active'));
            const activeSub = activeNav && activeNav.closest('.menu-sub');
            if (activeSub) activeSub.querySelector('.menu-sub-trigger').classList.add('sub-active');
            // گروه‌های باز را ببند تا منو تمیز بماند
            document.querySelectorAll('.menu-group').forEach(g => {
                g.classList.remove('open');
                const t = g.querySelector('.menu-trigger');
                if (!g.contains(activeNav)) t.classList.remove('text-blue-600');
            });
            // در موبایل، بعد از انتخاب یک تب، منو خودکار بسته شود
            if (window.innerWidth < 1024) document.getElementById('main-nav').classList.add('hidden');
            // بارگذاریِ همه‌ی تب‌های مالی از یک جا انجام می‌شود: initFinance(tabId) در پایینِ همین تابع
            // (که اول bootstrap را می‌گیرد و کشویی‌های دوره/شرکت را پر می‌کند)
            if (tabId === 'records') loadRecords();
            if (tabId === 'dashboard') { loadStats(); loadDashCharts(); loadDashAlerts(); }
            if (tabId === 'filemanager') fmOpen(fmCurrentPath);
            if (tabId === 'queue') loadQueue();
            if (tabId === 'users') loadUsers();
            if (tabId === 'tickets') openMessenger(tabKey);
            if (tabId === 'settings') { loadQuotaSetting(); if (window.BackupUI) BackupUI.load(); if (window.WorkLog) WorkLog.renderSettings(document.getElementById('wk-settings-root')); }
            if (tabId === 'my-work' && window.WorkLog) WorkLog.initMy();
            if (tabId === 'staff-work' && window.WorkLog) WorkLog.initStaff();
            if (tabId === 'cases') loadCases();
            if (tabId === 'health') { loadHealthTab(); loadDocsReviewList(); }
            if (tabId === 'approved-reviews') loadApprovedReviews();
            if (tabId.startsWith('fin-')) initFinance(tabId);
            if (tabId === 'issue-queue') { loadIssueQueueCompanies(); loadIssueQueue(); }
            if (tabId === 'issued-list') loadIssuedList();
            if (tabId === 'issue-group') loadIssueGroup();
            if (tabId === 'companies-requests') loadCompanyRequests();
            if (tabId === 'companies-inbox') loadCompanyInbox();
            if (tabId === 'companies-finance') loadCompanyFinance();
            if (tabId === 'companies-manage') loadCompanyManage();
            if (tabId === 'staff-users') loadStaffUsers();
            if (tabId === 'login-logs') loadLoginLogs();
            if (tabId === 'vr-build' && window.VR) VR.initBuildTab();
            if (tabId === 'vr-list' && window.VR) VR.initListTab();
            if (tabId === 'vr-settings' && window.VR && VR.initSettingsTab) VR.initSettingsTab();
            permSchedule();
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
                `<option value="${p.id}">${faDigits(p.title)}${p.status === 'CLOSED' ? ' (بسته)' : ''}</option>`).join('');
            const companyOpts = '<option value="">همه شرکت‌ها</option>' +
                finBoot.companies.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
            ['fin-dash-period','recon-period','inv-f-period'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.innerHTML = periodOpts;
                          if (finBoot.current_period_id) el.value = finBoot.current_period_id; }
            });
            ['inv-f-company'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.innerHTML = companyOpts;
            });
            return finBoot;
        }

        async function initFinance(tabId) {
            const b = await ensureFinBoot();
            if (!b) return;
            if (tabId === 'fin-dashboard')    loadFinDashboard();
            if (tabId === 'fin-installments') { if (window.FinHub) FinHub.initInstallments(); }
            if (tabId === 'fin-settings')     loadFinanceSettings();
            if (tabId === 'fin-invoices')     loadInvoices();
            if (tabId === 'fin-payments')     { if (window.FinHub) FinHub.initLedger(); }
            if (tabId === 'fin-tracking')     { if (window.FinHub) FinHub.initTracking(); }
        }

        // ---------- داشبورد مالی ----------
        // داشبورد تحلیلی (کارت‌ها، نمودارها، هشدارها و فیلترها) در finance-ui.js است
        function loadFinDashboard() { if (window.FinUI) FinUI.dashboard(); }

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
                document.querySelectorAll('input[name="fs-due"]').forEach(r => r.checked = (r.value === (s.due_mode || 'issue')));
                document.getElementById('fs-allow-multi').checked = s.allow_multiple_invoices === '1';
                document.getElementById('fs-inv-full').checked = (s.inv_full_policy ?? '1') === '1';
                document.getElementById('fs-inv-subtotal').checked = s.inv_company_subtotal === '1';
                document.getElementById('fs-inv-merge').checked = s.inv_merge_company === '1';
                window._fsCols = {};
                const b = await ensureFinBoot();
                if (b && window.FinUI) ['SUMMARY', 'PERSONNEL', 'DETAILED'].forEach(k => {
                    let cur = null; try { cur = JSON.parse(s['inv_cols_' + k] || 'null'); } catch (e) {}
                    window._fsCols[k] = FinUI.columnPicker(document.getElementById('fs-cols-' + k), k, b.column_pool, (Array.isArray(cur) && cur.length) ? cur : b.default_columns[k]);
                });
                loadInvTemplates();
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
                        due_mode: (document.querySelector('input[name="fs-due"]:checked') || {}).value || 'issue',
                        allow_multi: document.getElementById('fs-allow-multi').checked,
                        inv_full_policy: document.getElementById('fs-inv-full').checked,
                        inv_subtotal: document.getElementById('fs-inv-subtotal').checked,
                        inv_merge: document.getElementById('fs-inv-merge').checked,
                        inv_cols: window._fsCols ? Object.fromEntries(Object.entries(window._fsCols).map(([k, p]) => [k, p.get()])) : undefined,
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
                status: document.getElementById('inv-f-status').value || '',
                q: p2e(document.getElementById('inv-f-q').value || ''),
            });
            try {
                const res = await fetch(`${FIN_API}?${q}`);
                const d = await res.json();
                if (!d.ok) { body.innerHTML = `<p class="text-red-500 text-sm p-4">${d.error}</p>`; return; }
                if (!d.data.length) { body.innerHTML = '<p class="text-center text-slate-400 text-sm p-6">صورتحسابی با این فیلترها نیست.</p>'; return; }
                body.innerHTML = `<table class="w-full text-[11px]">
                    <thead class="bg-slate-100 text-slate-600"><tr>
                        <th class="p-2">شماره صورتحساب</th><th class="p-2">نوع</th><th class="p-2 text-right">شرکت</th>
                        <th class="p-2">دوره</th><th class="p-2">ردیف</th><th class="p-2">جمع کل</th>
                        <th class="p-2">پرداخت‌شده</th><th class="p-2">مانده</th><th class="p-2">تاریخ</th><th class="p-2">عملیات</th>
                    </tr></thead><tbody>` +
                    d.data.map(r => {
                        const rem = r.total_amount - r.paid_amount;
                        const inactive = r.status === 'INACTIVE';
                        return `<tr class="border-b hover:bg-blue-50 ${inactive ? 'opacity-60 bg-slate-50' : ''}">
                            <td class="p-2 text-center font-bold" dir="ltr">${r.invoice_no}${inactive ? '<div class="text-[9px] text-rose-600 font-bold" dir="rtl">غیرفعال</div>' : ''}</td>
                            <td class="p-2 text-center">${INV_KIND[r.kind] || r.kind}</td>
                            <td class="p-2">${r.company_name || '—'}</td>
                            <td class="p-2 text-center">${faDigits(r.period_title) || '-'}</td>
                            <td class="p-2 text-center">${e2p(r.line_count)}</td>
                            <td class="p-2 text-center font-bold">${money(r.total_amount)}</td>
                            <td class="p-2 text-center text-emerald-600">${money(r.paid_amount)}</td>
                            <td class="p-2 text-center ${rem > 0 ? 'text-amber-600 font-bold' : 'text-slate-300'}">${money(rem)}</td>
                            <td class="p-2 text-center" dir="ltr">${toJalali(r.created_at)}</td>
                            <td class="p-2 text-center whitespace-nowrap">
                                <button onclick="openInvoiceDetail(${r.id})" class="text-blue-600 underline">مشاهده</button>
                                ${r.docx_path ? `<a href="/${encodeFilePath(r.docx_path)}" target="_blank" class="text-indigo-600 underline mr-2">Word</a>` : ''}
                                ${r.pdf_path ? `<a href="/${encodeFilePath(r.pdf_path)}" target="_blank" class="text-red-600 underline mr-2">PDF</a>` : ''}
                                ${!inactive && finBoot && (finBoot.is_admin || '<?php echo ($_SESSION['role'] ?? '') === 'FINANCE' ? '1' : ''; ?>') ? `<button onclick="deactivateInvoice(${r.id}, '${r.invoice_no}')" class="text-rose-600 underline mr-2">غیرفعال‌سازی</button>` : ''}
                            </td></tr>`;
                    }).join('') + '</tbody></table>';
            } catch(e) { body.innerHTML = '<p class="text-red-500 text-sm p-4">خطا در اتصال.</p>'; }
        }

        // غیرفعال‌سازی صورتحساب (برگشت‌ناپذیر): فایل‌ها به «صورت حساب های غیر فعال» می‌روند و بیمه‌نامه‌هایش آزاد می‌شوند
        async function deactivateInvoice(id, no) {
            const ok = await uiConfirm('غیرفعال‌سازی صورتحساب ' + no,
                'این کار برگشت‌پذیر نیست: فایل‌های Word/PDF به پوشه‌ی «صورت حساب های غیر فعال» منتقل می‌شوند و بیمه‌نامه‌های این صورتحساب دوباره «بدون صورتحساب» می‌شوند تا بتوانید برای همان دوره صورتحساب تازه صادر کنید.', { danger: true, ok: 'غیرفعال شود' });
            if (!ok) return;
            const password = await FinUI.askPassword('تایید غیرفعال‌سازی ' + no, 'رمز عبور حساب خودتان را وارد کنید.');
            if (!password) return;
            try {
                const res = await fetch(FIN_API, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'deactivate_invoice', id, password }) });
                const d = await res.json();
                if (!d.ok) { showToast(d.error, 'error'); return; }
                showAlert('صورتحساب غیرفعال شد', `${e2p(d.freed)} بیمه‌نامه آزاد شد` + (d.still_invoiced ? ` و ${e2p(d.still_invoiced)} بیمه‌نامه چون صورتحساب فعال دیگری دارند، دارای صورتحساب ماندند` : '') + '.', 'success');
                loadInvoices();
            } catch (e) { showToast('خطا در اتصال', 'error'); }
        }

        function openNewInvoice() {
            document.getElementById('inv-new-modal').classList.remove('hidden');
            document.getElementById('inv-new-modal').classList.add('flex');
            // کپی گزینه‌های دوره و شرکت از فیلترها
            document.getElementById('inv-new-period').innerHTML = document.getElementById('inv-f-period').innerHTML;
            document.getElementById('inv-new-company').innerHTML = document.getElementById('inv-f-company').innerHTML;
            document.getElementById('inv-new-preview').innerHTML = '';
            const st = (finBoot && finBoot.settings) || {};
            const multi = st.allow_multiple_invoices === '1';
            const modeSel = document.getElementById('inv-new-mode');
            modeSel.value = 'uninvoiced';
            [...modeSel.options].forEach(o => { if (o.value !== 'uninvoiced') { o.disabled = !multi; o.title = multi ? '' : 'در تنظیمات مالی «چند صورتحساب برای یک بیمه‌نامه» را فعال کنید'; } });
            document.getElementById('inv-opt-full').checked = (st.inv_full_policy ?? '1') === '1';
            document.getElementById('inv-opt-subtotal').checked = st.inv_company_subtotal === '1';
            document.getElementById('inv-opt-merge').checked = st.inv_merge_company === '1';
            onInvKindChange();
        }
        let invColsPicker = null;
        function invDefaultCols(kind) {
            const st = (finBoot && finBoot.settings) || {};
            try { const c = JSON.parse(st['inv_cols_' + kind] || 'null'); if (Array.isArray(c) && c.length) return c; } catch (e) {}
            return (finBoot && finBoot.default_columns && finBoot.default_columns[kind]) || [];
        }
        function onInvKindChange() {
            const kind = document.querySelector('input[name="inv-kind"]:checked').value;
            // تفکیکی به یک شرکتِ ثبت‌شده وصل است؛ دو نوع دیگر روی کل دوره‌اند و نام شرکتِ
            // مخاطبِ نامه دستی وارد می‌شود
            document.getElementById('inv-company-wrap').style.display = kind === 'DETAILED' ? 'block' : 'none';
            document.getElementById('inv-manual-wrap').style.display = kind === 'DETAILED' ? 'none' : 'block';
            document.getElementById('inv-opt-subtotal-wrap').style.display = kind === 'SUMMARY' ? 'none' : 'flex';
            document.getElementById('inv-new-preview').innerHTML = '';
            if (window.FinUI && finBoot && finBoot.column_pool) {
                invColsPicker = FinUI.columnPicker(document.getElementById('inv-cols-picker'), kind, finBoot.column_pool, invDefaultCols(kind),
                    () => { if (document.getElementById('inv-new-preview').innerHTML) previewInvoice(); });
            }
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
                mode: document.getElementById('inv-new-mode').value,
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

                // ستون‌ها همان‌هایی است که در جدول Word می‌آید (انتخاب و ترتیبِ کاربر)
                const pool = (finBoot && finBoot.column_pool) || {};
                const chosen = invColsPicker ? invColsPicker.get() : invDefaultCols(kind);
                const fullPol = document.getElementById('inv-opt-full').checked;
                const periodTitle = (document.getElementById('inv-new-period').selectedOptions[0] || {}).text || '';
                const shortPol = v => { const p = String(v || '').split('/').filter(Boolean); return !fullPol && p.length >= 2 ? p.slice(-2).join('/') : (v || ''); };
                const cols = [['ردیف', '_n'], ...chosen.map(k => [(pool[k] || [k])[0], k])];
                const cellVal = (l, k, n) => k === '_n' ? e2p(n + 1) : k === 'period' ? periodTitle
                    : k === 'policy_number' ? `<span dir="ltr">${shortPol(l.policy_number)}</span>`
                    : ['total_premium','monthly_amount'].includes(k) ? money(l[k])
                    : ['policy_count','third_count','body_count'].includes(k) ? e2p(l[k] || 0)
                    : (l[k] ? e2p(l[k]) : '-');
                const reWarn = d.already_invoiced ? `<div class="bg-sky-50 border border-sky-200 rounded-xl p-3 mb-3 text-[11px] text-sky-800"><i class="fas fa-circle-info ml-1"></i>${e2p(d.already_invoiced)} بیمه‌نامه از این فهرست قبلاً صورتحساب خورده‌اند؛ هنگام صدور تایید گرفته می‌شود.</div>` : '';

                box.innerHTML = recWarn + reWarn + `
                    <div class="flex gap-4 text-[11px] mb-2">
                        <span>تعداد ردیف: <b>${e2p(d.count)}</b></span>
                        <span>جمع کل: <b>${money(d.total)}</b> ریال</span>
                        <span>قسط ماهانه: <b>${money(d.monthly)}</b></span>
                    </div>
                    <div class="max-h-60 overflow-y-auto border rounded-xl">
                        <table class="w-full text-[10.5px]"><thead class="bg-slate-50 sticky top-0"><tr>` +
                            cols.map(x => `<th class="p-2">${x[0]}</th>`).join('') + `</tr></thead><tbody>` +
                        d.lines.map((l, n) => `<tr class="border-b ${l.is_invoiced ? 'bg-sky-50' : ''}">` + cols.map(x => `<td class="p-2 text-center whitespace-nowrap">${cellVal(l, x[1], n)}</td>`).join('') + '</tr>').join('') + `</tbody></table>
                    </div>
                    <button onclick="doCreateInvoice()" ${d.reconcile_ok ? '' : 'disabled'}
                        class="w-full mt-3 ${d.reconcile_ok ? 'bg-blue-600 hover:bg-blue-700' : 'bg-slate-300 cursor-not-allowed'} text-white font-bold py-2.5 rounded-xl text-xs">
                        <i class="fas fa-file-invoice ml-1"></i> صدور نهایی صورتحساب
                    </button>`;
            } catch(e) { box.innerHTML = '<p class="text-red-600 text-xs p-3">خطا در اتصال.</p>'; }
        }

        async function overrideReconcile(id) {
            const reason = await uiPrompt('تایید دستی مغایرت', '', {ok: 'تایید دستی', required: true, placeholder: 'دلیل تایید دستی مغایرت را بنویسید...'});
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

        async function doCreateInvoice(confirmReinvoice = false) {
            const kind = document.querySelector('input[name="inv-kind"]:checked').value;
            showToast('در حال صدور صورتحساب...', 'info');
            try {
                const res = await fetch(FIN_API, { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'create_invoice', kind,
                        period_id: document.getElementById('inv-new-period').value,
                        company_id: kind === 'DETAILED' ? document.getElementById('inv-new-company').value : null,
                        manual_company: kind === 'DETAILED' ? null : (document.getElementById('inv-manual-company').value || null),
                        mode: document.getElementById('inv-new-mode').value,
                        columns: invColsPicker ? invColsPicker.get() : invDefaultCols(kind),
                        full_policy: document.getElementById('inv-opt-full').checked,
                        company_subtotal: document.getElementById('inv-opt-subtotal').checked,
                        merge_company: document.getElementById('inv-opt-merge').checked,
                        confirm_reinvoice: confirmReinvoice }) });
                const d = await res.json();
                if (!d.ok && d.need_confirm) {
                    if (await uiConfirm('صدور دوباره', `${e2p(d.already_invoiced)} بیمه‌نامه از این فهرست قبلاً صورتحساب دارند. برای این‌ها هم صورتحساب تازه صادر شود؟`)) doCreateInvoice(true);
                    return;
                }
                if (!d.ok) { showToast(d.error, 'error'); return; }
                showAlert(`صورتحساب ${d.invoice_no} صادر شد.`, (d.generated && d.generated.note) || '', 'success');
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
                        <div>دوره: <b>${faDigits(i.period_title) || '-'}</b></div>
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
                              : `<td class="p-2 text-center">${l.plate ? formatPlateHtml(l.plate) : '-'}</td><td class="p-2 text-center" dir="ltr">${l.policy_number || '-'}</td>`}
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
        // تاریخِ امروزِ شمسی (برای فرم‌های دریافت/پرداختِ هر درخواست شرکتی)
        function todayJalali() {
            if (window.IrTime) return IrTime.fa(IrTime.jdate());
            try { return new Intl.DateTimeFormat('fa-IR-u-nu-arabext', {year:'numeric',month:'2-digit',day:'2-digit',timeZone:'Asia/Tehran'}).format(new Date()); }
            catch(e) { return ''; }
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
            fd.append('period_id', document.getElementById('recon-period').value);
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

        // ======================= قالب‌های صورتحساب (مثلِ قالب‌های گزارش بازدید) =======================
        let ITPL = { kind: 'DETAILED', data: null };
        const itplEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        const ITPL_KIND_IC = { SUMMARY: 'fa-layer-group', PERSONNEL: 'fa-users', DETAILED: 'fa-building' };

        async function loadInvTemplates() {
            try {
                const res = await fetch(FIN_API + '?action=tpl_list');
                const d = await res.json();
                if (!d.ok) { document.getElementById('itpl-body').innerHTML = `<p class="text-xs text-red-500">${itplEsc(d.error || 'خطا')}</p>`; return; }
                ITPL.data = d;
                renderInvTemplates();
            } catch (e) { document.getElementById('itpl-body').innerHTML = '<p class="text-xs text-red-500">خطا در ارتباط با سرور.</p>'; }
        }
        function itplSetKind(k) { ITPL.kind = k; renderInvTemplates(); }

        function renderInvTemplates() {
            const d = ITPL.data; if (!d) return;
            document.getElementById('itpl-tabs').innerHTML = d.kinds.map(k => {
                const st = !k.active ? 'bg-red-400' : ((k.active.analysis && (k.active.analysis.unknown || []).length) || (k.active.analysis && !k.active.analysis.has_table) ? 'bg-amber-400' : 'bg-emerald-500');
                return `<button onclick="itplSetKind('${k.kind}')" class="text-[11px] font-bold px-3 py-1.5 rounded-xl flex items-center gap-1.5 ${ITPL.kind === k.kind ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}">
                    <span class="w-2 h-2 rounded-full ${st}"></span><i class="fas ${ITPL_KIND_IC[k.kind]}"></i>${itplEsc(k.kind_fa)}</button>`;
            }).join('');
            const k = d.kinds.find(x => x.kind === ITPL.kind) || d.kinds[0];
            const t = k.active, an = (t && t.analysis) || {};
            const chips = (arr, cls) => (arr && arr.length) ? arr.map(v => `<span class="inline-block text-[10.5px] font-bold px-2 py-0.5 rounded-lg ${cls} ml-1 mb-1">{{${itplEsc(v)}}}</span>`).join('') : '<span class="text-[11px] text-slate-400">—</span>';
            const unusedCodes = d.catalog.map(c => c.code).filter(c => !(an.vars || []).includes(c));
            const hist = k.history.length ? k.history.map(h => `
                <div class="flex items-center gap-2 text-[11px] border-b border-slate-100 py-1.5">
                    <i class="fas fa-file-word text-slate-400"></i>
                    <span class="font-bold text-slate-600 truncate flex-1" title="${itplEsc(h.title)}">${itplEsc(h.title || 'قالب')}</span>
                    <span class="text-slate-400">${faDigits(h.uploaded_jalali || '')}</span>
                    ${h.exists ? `<button onclick="itplPreview(${h.id})" class="text-indigo-600 hover:underline">پیش‌نمایش</button>
                    <a href="${FIN_API}?action=tpl_download&id=${h.id}" class="text-slate-500 hover:underline">دانلود</a>
                    <button onclick="itplActivate(${h.id})" class="text-emerald-600 hover:underline font-bold">فعال کن</button>` : '<span class="text-red-400">فایل نیست</span>'}
                    <button onclick="itplDelete(${h.id}, false)" class="text-red-400 hover:text-red-600" title="حذفِ این نسخه"><i class="fas fa-trash-can"></i></button>
                </div>`).join('') : '<p class="text-[11px] text-slate-400">نسخه‌ی قبلی‌ای نیست.</p>';
            document.getElementById('itpl-body').innerHTML = `
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div class="border border-slate-200 rounded-2xl p-4 space-y-3">
                    <p class="text-xs font-black text-slate-600"><i class="fas fa-file-word text-blue-600 ml-1"></i>قالبِ فعالِ «${itplEsc(k.kind_fa)}»</p>
                    ${t ? `<div class="flex items-center gap-3 bg-slate-50 rounded-xl p-3">
                            <span class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center text-lg"><i class="fas fa-file-word"></i></span>
                            <div class="min-w-0 flex-1"><p class="text-xs font-black text-slate-700 truncate">${itplEsc(t.title || 'قالب')}</p>
                            <p class="text-[10.5px] text-slate-400">${t.exists ? `${faDigits(t.uploaded_jalali || '')} · ${faDigits((t.size / 1024).toFixed(0))} کیلوبایت · ${faDigits((an.vars || []).length)} کد` : '<span class="text-red-500">فایل روی سرور پیدا نشد</span>'}</p></div>
                          </div>
                          <div class="flex flex-wrap gap-2">
                            ${t.exists ? `<button onclick="itplPreview(${t.id})" class="text-[11px] font-bold bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-xl"><i class="fas fa-eye ml-1"></i>پیش‌نمایش با داده‌ی نمونه</button>
                            <a href="${FIN_API}?action=tpl_download&id=${t.id}" class="text-[11px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-xl"><i class="fas fa-download ml-1"></i>دانلودِ قالب</a>` : ''}
                            <button onclick="itplDelete(${t.id}, true)" class="text-[11px] font-bold bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1.5 rounded-xl"><i class="fas fa-trash-can ml-1"></i>حذف</button>
                          </div>`
                        : `<p class="text-xs font-bold text-red-500 bg-red-50 border border-red-100 rounded-xl p-3"><i class="fas fa-triangle-exclamation ml-1"></i>هنوز قالبی برای این نوع بارگذاری نشده؛ صورتحساب‌های این نوع بدونِ فایلِ Word ساخته می‌شوند.</p>`}
                    <label id="itpl-drop" class="flex flex-col items-center gap-1.5 text-center p-5 border-2 border-dashed border-indigo-200 rounded-2xl bg-indigo-50/40 hover:bg-indigo-50 cursor-pointer transition">
                        <i class="fas fa-cloud-arrow-up text-2xl text-indigo-400"></i>
                        <span class="text-xs font-black text-slate-600">${t ? 'جایگزینیِ قالب' : 'بارگذاریِ قالب'} (فایلِ docx را رها کنید یا کلیک کنید)</span>
                        <span class="text-[10px] text-slate-400">قالبِ فعلی پاک نمی‌شود و در «نسخه‌های قبلی» می‌ماند تا هر وقت خواستید برگردید</span>
                        <input type="file" accept=".docx" class="hidden" onchange="itplUpload(this.files[0]); this.value=''">
                    </label>
                    <p id="itpl-up-st" class="text-[11px] font-bold"></p>
                    <a href="${FIN_API}?action=tpl_starter&kind=${k.kind}" class="block text-center text-[11px] font-bold text-violet-700 bg-violet-50 hover:bg-violet-100 rounded-xl py-2"><i class="fas fa-wand-magic-sparkles ml-1"></i>دانلودِ قالبِ آماده‌ی شروع (با همه‌ی کدها) برای ویرایش در Word</a>
                </div>
                <div class="border border-slate-200 rounded-2xl p-4 space-y-3">
                    <p class="text-xs font-black text-slate-600"><i class="fas fa-stethoscope text-emerald-500 ml-1"></i>بررسیِ قالب</p>
                    ${!t ? '<p class="text-[11px] text-slate-400">بعد از بارگذاری، کدهای قالب این‌جا بررسی می‌شوند.</p>' : (an.ok === false ? `<p class="text-[11px] text-red-500">${itplEsc(an.error || '')}</p>` : `
                    <div class="flex flex-wrap gap-2 text-[11px] font-bold">
                        <span class="px-2.5 py-1 rounded-lg ${an.has_table ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}"><i class="fas ${an.has_table ? 'fa-check' : 'fa-xmark'} ml-1"></i>جدولِ ریز ${an.has_table ? 'دارد' : 'ندارد'}</span>
                        <span class="px-2.5 py-1 rounded-lg ${(an.unknown || []).length ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-700'}">${(an.unknown || []).length ? `${faDigits(an.unknown.length)} کدِ ناشناخته` : 'همه‌ی کدها معتبرند'}</span>
                    </div>
                    ${(an.warnings || []).length ? `<ul class="text-[11px] text-amber-700 bg-amber-50 border border-amber-100 rounded-xl p-2.5 space-y-1">${an.warnings.map(w => `<li><i class="fas fa-circle-exclamation ml-1"></i>${itplEsc(w)}</li>`).join('')}</ul>` : ''}
                    <div><p class="text-[11px] font-black text-emerald-700 mb-1">کدهای معتبرِ به‌کاررفته:</p>${chips(an.known, 'bg-emerald-50 text-emerald-700')}</div>
                    <div><p class="text-[11px] font-black text-red-500 mb-1">کدهای ناشناخته (در خروجی خام می‌مانند؛ احتمالاً غلطِ تایپی):</p>${chips(an.unknown, 'bg-red-50 text-red-600')}</div>
                    <div><p class="text-[11px] font-black text-slate-500 mb-1">کدهایی که می‌توانید اضافه کنید:</p>${chips(unusedCodes, 'bg-slate-100 text-slate-500')}</div>`)}
                    <details class="pt-1"><summary class="text-[11px] font-black text-slate-500 cursor-pointer">نسخه‌های قبلی (${faDigits(k.history.length)})</summary><div class="mt-2">${hist}</div></details>
                </div>
            </div>
            <div class="border border-slate-200 rounded-2xl p-4 mt-4">
                <p class="text-xs font-black text-slate-600 mb-2"><i class="fas fa-code text-blue-500 ml-1"></i>راهنمای کدها <span class="text-[10px] font-bold text-slate-400">(روی هر کد بزنید تا کپی شود و در Word بچسبانید)</span></p>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-1.5">
                    ${d.catalog.map(c => `<button type="button" onclick="itplCopy('{{${itplEsc(c.code)}}}')" class="text-right border border-slate-100 hover:border-indigo-200 hover:bg-indigo-50/50 rounded-xl px-2.5 py-1.5">
                        <span class="text-[11px] font-black text-indigo-700">{{${itplEsc(c.code)}}}</span><span class="block text-[10px] text-slate-500">${itplEsc(c.desc)}</span></button>`).join('')}
                </div>
                <p class="text-[10.5px] text-slate-400 mt-2 leading-6">کدها در بدنه، سربرگ و پاورقیِ سند پر می‌شوند. اگر Word کدی را تکه‌تکه کرده باشد خودکار درست می‌شود. ستون‌های جدول، شماره‌ی کامل/کوتاهِ بیمه‌نامه، ردیفِ جمعِ هر شرکت و ادغامِ نامِ شرکت از «صدور صورتحساب (پیش‌فرض‌ها)» بالای همین صفحه می‌آیند.
                ${d.pdf_converter ? '' : '<br><span class="text-amber-600">روی این سرور مبدلِ PDF (LibreOffice) پیدا نشد؛ صورتحساب به‌صورتِ Word ساخته می‌شود و پیش‌نمایش به شکلِ صفحه‌ی وب نمایش داده می‌شود.</span>'}</p>
            </div>`;
            const z = document.getElementById('itpl-drop');
            ['dragenter', 'dragover'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.add('bg-indigo-100'); }));
            ['dragleave', 'drop'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.remove('bg-indigo-100'); }));
            z.addEventListener('drop', e => { if (e.dataTransfer.files[0]) itplUpload(e.dataTransfer.files[0]); });
        }
        function itplCopy(t) {
            const done = () => showToast('کپی شد: ' + t, 'success');
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(done).catch(() => {});
            else { const ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} ta.remove(); }
        }
        async function itplUpload(file) {
            if (!file) return;
            const st = document.getElementById('itpl-up-st');
            if (!/\.docx$/i.test(file.name)) { st.className = 'text-[11px] font-bold text-red-500'; st.textContent = 'فقط فایلِ docx پذیرفته می‌شود.'; return; }
            st.className = 'text-[11px] font-bold text-indigo-500'; st.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حالِ بارگذاری و بررسیِ قالب…';
            const fd = new FormData();
            fd.append('action', 'upload_template'); fd.append('kind', ITPL.kind); fd.append('file', file);
            try {
                const res = await fetch(FIN_API, { method: 'POST', body: fd });
                const d = await res.json();
                if (!d.ok) { st.className = 'text-[11px] font-bold text-red-500'; st.textContent = d.error || 'خطا در بارگذاری'; return; }
                const w = (d.analysis && d.analysis.warnings) || [];
                showToast(w.length ? 'قالب فعال شد؛ هشدارها را ببینید.' : 'قالب بارگذاری و فعال شد.', w.length ? 'warning' : 'success');
                await loadInvTemplates();
            } catch (e) { st.className = 'text-[11px] font-bold text-red-500'; st.textContent = 'خطا در اتصال'; }
        }
        async function itplActivate(id) {
            const res = await fetch(FIN_API, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'tpl_activate', id }) });
            const d = await res.json();
            if (!d.ok) return showToast(d.error || 'خطا', 'error');
            showToast('این نسخه قالبِ فعال شد.', 'success'); loadInvTemplates();
        }
        async function itplDelete(id, isActive) {
            if (!(await uiConfirm('حذف قالب', isActive ? 'قالبِ فعال حذف شود؟ تا قالبِ دیگری فعال نکنید، صورتحساب‌های این نوع بدونِ فایلِ Word ساخته می‌شوند.' : 'این نسخه‌ی قالب حذف شود؟', {ok: 'حذف شود', danger: true}))) return;
            const res = await fetch(FIN_API, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'tpl_delete', id }) });
            const d = await res.json();
            if (!d.ok) return showToast(d.error || 'خطا', 'error');
            showToast('قالب حذف شد.', 'success'); loadInvTemplates();
        }
        async function itplPreview(id) {
            const m = document.getElementById('itpl-preview-modal'), body = document.getElementById('itpl-preview-body');
            body.innerHTML = '<div class="text-center py-16 text-slate-400"><i class="fas fa-spinner fa-spin text-2xl"></i><p class="text-xs mt-2">در حالِ ساختِ پیش‌نمایش…</p></div>';
            m.classList.add('active');
            let d;
            try {
                const res = await fetch(FIN_API, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'tpl_preview', id }) });
                d = await res.json();
            } catch (e) { d = { ok: false, error: 'خطا در ارتباط با سرور.' }; }
            if (!d.ok) { body.innerHTML = `<p class="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl p-4">${itplEsc(d.error || 'خطا')}</p>`; return; }
            const f = fmt => `${FIN_API}?action=tpl_preview_file&token=${d.token}&fmt=${fmt}`;
            const unk = (d.analysis && d.analysis.unknown) || [];
            body.innerHTML = `
                <div class="flex flex-wrap gap-2 mb-3">
                    <a href="${f('docx')}" class="text-[11px] font-bold bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-xl"><i class="fas fa-file-word ml-1"></i>دانلودِ خروجیِ Word</a>
                    ${d.has_pdf ? `<a href="${f('pdf')}" target="_blank" class="text-[11px] font-bold bg-rose-600 hover:bg-rose-700 text-white px-3 py-1.5 rounded-xl"><i class="fas fa-file-pdf ml-1"></i>بازکردنِ PDF</a>` : '<span class="text-[11px] text-slate-400 self-center">PDF روی این سرور ساخته نمی‌شود؛ نمای زیر شبیه‌سازیِ صفحه است.</span>'}
                </div>
                ${unk.length ? `<p class="text-[11px] text-red-600 bg-red-50 border border-red-100 rounded-xl px-3 py-2 mb-3">کدهای ناشناخته که پر نشدند: ${unk.map(u => '{{' + itplEsc(u) + '}}').join('، ')}</p>` : ''}
                ${d.has_pdf ? `<iframe src="${f('pdf')}#toolbar=0" class="w-full rounded-xl border mb-3" style="height:70vh"></iframe>` : ''}
                <div class="bg-slate-200/70 rounded-2xl p-4 overflow-x-auto">
                    <div class="itpl-paper no-count" dir="rtl">${d.html || '<p class="text-slate-400">نمایشی ساخته نشد.</p>'}</div>
                </div>`;
        }

        // ======================= صدور بیمه‌نامه =======================
        const CASE_STATUS_FA = {
            REGISTERED: 'ثبت شده', AWAITING_DOCS: 'در انتظار بارگذاری مدارک', DOCS_PENDING: 'در انتظار مدارک', DOCS_REVIEW: 'در انتظار تایید مدارک',
            ISSUING: 'در حال صدور', ISSUED: 'صادر شده', REJECTED: 'رد شده', WITHDRAWN: 'خارج از فاز عملیاتی'
        };
        const CASE_STATUS_COLOR = {
            REGISTERED: 'text-slate-500', AWAITING_DOCS: 'text-amber-500', DOCS_PENDING: 'text-amber-500', DOCS_REVIEW: 'text-blue-500',
            ISSUING: 'text-indigo-500', ISSUED: 'text-emerald-600', REJECTED: 'text-red-500', WITHDRAWN: 'text-slate-400'
        };
        // دکمه‌ی حذف برای صادرشده‌ها و درخواست‌هایی که قبلاً از فاز عملیاتی خارج شده‌اند نمایش داده نمی‌شود
        const caseDeletable = c => IS_ADMIN && c.status !== 'ISSUED' && c.status !== 'WITHDRAWN';

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
                        <tr class="hover:bg-indigo-50/30 ${c.status === 'WITHDRAWN' ? 'opacity-60' : ''}">
                            <td class="p-4 font-mono text-indigo-600" dir="ltr">${c.unique_code}</td>
                            <td class="p-4">${c.insured_name || c.holder_name}</td>
                            <td class="p-4">${c.insurance_type === 'BODY' ? 'بدنه' : 'ثالث'}</td>
                            <td class="p-4 font-mono">${formatPlateHtml(c.plate)}</td>
                            <td class="p-4 text-xs">${e2pNum(c.docs_approved)}/${e2pNum(c.docs_total)} تایید${c.docs_rejected > 0 ? ` <span class="text-red-500">(${e2p(c.docs_rejected)} رد)</span>` : ''}</td>
                            <td class="p-4 text-xs font-bold ${CASE_STATUS_COLOR[c.status] || ''}">${CASE_STATUS_FA[c.status] || c.status}</td>
                            <td class="p-4 text-xs text-slate-500" dir="ltr">${toJalali(c.created_at, false)}</td>
                            <td class="p-4 whitespace-nowrap"><button onclick="openCase(${c.id}, 'issue')" class="bg-indigo-100 text-indigo-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-indigo-200">مشاهده / صدور</button>
                                ${caseDeletable(c) ? `<button onclick="deleteCase(${c.id}, null)" title="حذف این درخواست" class="mr-1 bg-red-50 text-red-500 hover:bg-red-100 px-2.5 py-1.5 rounded-lg text-xs"><i class="fas fa-trash-alt"></i></button>` : ''}</td>
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

                const addCaseHtml = !IS_ADMIN ? '' : `
                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 mt-3 flex items-center justify-between gap-2">
                        <span class="text-[11px] text-blue-800">درخواستِ تازه برای همین معرفی‌نامه، با همه‌ی اطلاعات و مدارکش:</span>
                        <button onclick="openManualCreateModal(${introId}, '${(introRow.full_name || '').replace(/'/g, '')}')" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg whitespace-nowrap"><i class="fas fa-plus ml-1"></i>افزودن درخواست جدید</button>
                    </div>`;

                if (data.data.length === 0) {
                    body.innerHTML = header + addCaseHtml + '<p class="text-center text-slate-400 text-sm p-6 mt-3">هنوز هیچ بیمه‌نامه‌ای برای این معرفی‌نامه ثبت نشده.</p>';
                    return;
                }

                const cardsHtml = data.data.map(c => {
                    const pendingBadge = c.docs_pending > 0 ? `<span class="text-amber-500 text-[10px]">(${e2p(c.docs_pending)} مدرک در انتظار)</span>` : '';
                    const rejectedBadge = c.docs_rejected > 0 ? `<span class="text-red-500 text-[10px]">(${e2p(c.docs_rejected)} مدرک رد‌شده)</span>` : '';
                    return `
                    <div class="border rounded-xl p-3 flex justify-between items-center ${c.status === 'WITHDRAWN' ? 'bg-slate-50 opacity-70' : ''}">
                        <div>
                            <p class="font-bold text-sm">${insurance_type_fa_js(c.insurance_type)} — ${c.plate ? formatPlateHtml(c.plate) : 'بدون پلاک'}</p>
                            <p class="text-[10px] text-slate-400">شناسه: ${c.unique_code} — ${e2pNum(c.docs_approved)}/${e2pNum(c.docs_total)} مدرک تایید‌شده ${pendingBadge} ${rejectedBadge}</p>
                            <b class="text-xs ${CASE_STATUS_COLOR[c.status]||''}">${CASE_STATUS_FA[c.status]||c.status}</b>
                            ${c.status === 'WITHDRAWN' && c.last_status_note ? `<span class="text-[10px] text-slate-400 mr-1">(${e2p(c.last_status_note)})</span>` : ''}
                        </div>
                        <span class="flex items-center gap-1.5">
                            <button onclick="openCase(${c.id}, 'review')" class="bg-indigo-600 text-white text-xs font-bold px-3 py-2 rounded-lg hover:bg-indigo-700 whitespace-nowrap"><i class="fas fa-arrow-left ml-1"></i>جزئیات و عملیات</button>
                            ${caseDeletable(c) ? `<button onclick="deleteCase(${c.id}, ${introId})" title="حذف این درخواست" class="bg-red-50 text-red-500 hover:bg-red-100 text-xs px-2.5 py-2 rounded-lg"><i class="fas fa-trash-alt"></i></button>` : ''}
                        </span>
                    </div>`;
                }).join('');

                body.innerHTML = header + addCaseHtml + '<div class="space-y-2 mt-3">' + cardsHtml + '</div>';
            } catch (e) { body.innerHTML = '<p class="text-center text-red-500 text-sm p-6">خطا در اتصال به سرور.</p>'; }
        }
        function insurance_type_fa_js(t) { return t === 'BODY' ? 'بدنه' : 'ثالث'; }

        // حذفِ یک درخواستِ کارکنان (چه دستی ثبت شده، چه خودِ پرسنل از ربات). صادرشده‌ها حذف نمی‌شوند.
        function deleteCase(caseId, introId) {
            showConfirm('حذف درخواست', 'اگر هنوز هیچ مدرکی از این درخواست تایید نشده باشد، با مدارک و بازدیدهایش کامل حذف می‌شود. اگر حتی یک مدرک تایید شده باشد، حذف نمی‌شود و فقط از فاز عملیاتی خارج می‌شود (در لیست صدور، صادره‌ها و شمارِ معرفی‌نامه نمی‌آید و مدارکِ بایگانی‌شده سر جایشان می‌مانند). در هر دو حالت یک واحد به سهمیه‌ی معرفی‌نامه برمی‌گردد. ادامه می‌دهید؟', async () => {
                try {
                    const res = await fetch('api/case_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({action: 'delete_case', case_id: caseId})});
                    const data = await res.json();
                    if (!data.ok) { showToast(data.error || 'خطا در حذف.', 'error'); return; }
                    showToast(data.mode === 'withdrawn' ? (data.message || 'درخواست از فاز عملیاتی خارج شد.') : 'درخواست حذف شد.', data.mode === 'withdrawn' ? 'info' : 'success');
                    const cm = document.getElementById('case-detail-modal');
                    if (cm && !cm.classList.contains('hidden')) { cm.classList.add('hidden'); cm.classList.remove('flex'); }
                    if (introId) openIntroDetail(introId, document.getElementById('intro-detail-name').textContent);
                    loadRecords();
                    if (typeof loadCases === 'function' && !document.getElementById('tab-cases').classList.contains('hidden')) loadCases();
                } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
            });
        }


        // «ساخت گزارش بازدید» از روی درخواستِ کارکنان (جزئیاتِ درخواست و ثبتِ دستی)
        function openCaseVisitReport(caseId, after) {
            if (!window.VR || !VR.openTargetBuilder) { showToast('به بخشِ گزارش بازدید دسترسی ندارید.', 'error'); return; }
            VR.openTargetBuilder({ type: 'case', id: caseId }, () => { if (after) after(); else if (currentCaseId == caseId && !document.getElementById('case-detail-modal').classList.contains('hidden')) openCase(caseId); });
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
                const delBtn = document.getElementById('case-delete-btn');
                delBtn.classList.toggle('hidden', !caseDeletable(c));
                delBtn.onclick = () => deleteCase(c.id, c.introduction_id || null);
                document.getElementById('case-detail-code').innerText = c.unique_code + (isIssueMode ? ' — صدور بیمه‌نامه' : ' — بررسی مدارک');

                // در حالت «صدور»، فقط مدارکِ تایید‌شده نمایش داده می‌شود و هیچ دکمه‌ی تایید/ردی نیست
                const docsForDisplay = isIssueMode ? data.documents.filter(d => d.status === 'APPROVED') : data.documents;

                let docsHtml = docsForDisplay.map(d => {
                    const statusBadge = d.status === 'APPROVED' ? '<span class="text-emerald-600 text-xs font-bold">✅ تایید شده</span>'
                        : d.status === 'REJECTED' ? `<span class="text-red-500 text-xs font-bold">❌ رد شده - ${d.reject_reason || ''}</span>`
                        : '<span class="text-amber-500 text-xs font-bold">⏳ در انتظار بررسی</span>';
                    const isImg = /\.(jpg|jpeg|png|webp|gif)$/i.test(d.file_path || '');
                    const preview = d.file_path
                        ? (isImg ? `<img src="/${encodeFilePath(d.file_path)}" data-gv="/${encodeFilePath(d.file_path)}" data-gv-label="${(d.doc_label || '').replace(/"/g, '')}" class="w-24 h-24 object-cover rounded-lg border cursor-zoom-in hover:opacity-80">` : `<a href="/${encodeFilePath(d.file_path)}" target="_blank" class="text-blue-600 underline text-xs">مشاهده فایل</a>`)
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

                body.innerHTML = (c.status === 'WITHDRAWN' ? `
                    <div class="bg-slate-100 border border-slate-300 text-slate-600 rounded-xl p-3 mb-3 text-xs leading-6">
                        <i class="fas fa-box-archive ml-1"></i><b>این درخواست از فاز عملیاتی خارج شده است</b> و فقط برای سابقه نگه داشته می‌شود؛
                        در لیست صدور، صادره‌ها و شمارِ معرفی‌نامه نمی‌آید و عملیاتی روی آن انجام نمی‌شود.
                        ${c.last_status_note ? `<div class="text-[11px] text-slate-500 mt-1">${e2p(c.last_status_note)}</div>` : ''}
                    </div>` : '') + `
                    <div class="grid grid-cols-2 gap-2 text-[11px] bg-slate-50 rounded-xl p-4">
                        <p class="col-span-2 font-bold text-slate-500 text-xs mb-1"><i class="fas fa-user ml-1"></i>اطلاعات شخص / بیمه‌گذار</p>
                        <div><span class="text-slate-400">بیمه‌گذار:</span> <b>${c.insured_name || '-'}</b> ${copyBtn(c.insured_name,'نام بیمه‌گذار')}</div>
                        <div><span class="text-slate-400">کد ملی بیمه‌گذار:</span> <b dir="ltr">${e2p(c.insured_national_id) || '-'}</b> ${copyBtn(c.insured_national_id,'کد ملی')}</div>
                        <div><span class="text-slate-400">تاریخ تولد:</span> <b dir="ltr">${e2p(c.insured_birth_date) || '-'}</b> ${copyBtn(c.insured_birth_date,'تاریخ تولد')}</div>
                        <div><span class="text-slate-400">موبایل:</span> <b dir="ltr">${e2p(c.insured_phone) || '-'}</b> ${copyBtn(c.insured_phone,'موبایل')}</div>
                        <div><span class="text-slate-400">آدرس:</span> <b>${c.insured_address || '-'}</b> ${copyBtn(c.insured_address,'آدرس')}</div>
                        <div><span class="text-slate-400">کد پستی:</span> <b dir="ltr">${e2p(c.insured_postal_code) || '-'}</b> ${copyBtn(c.insured_postal_code,'کد پستی')}</div>
                        <hr class="col-span-2 my-1 border-slate-200">
                        <p class="col-span-2 font-bold text-slate-500 text-xs mb-1"><i class="fas fa-id-badge ml-1"></i>دارنده‌ی معرفی‌نامه و شرکت</p>
                        <div><span class="text-slate-400">دارنده معرفی‌نامه:</span> <b>${c.holder_name}</b> ${copyBtn(c.holder_name,'نام دارنده')}</div>
                        <div><span class="text-slate-400">کد ملی دارنده:</span> <b dir="ltr">${e2p(c.holder_national_code) || '-'}</b> ${copyBtn(c.holder_national_code,'کد ملی دارنده')}</div>
                        <div><span class="text-slate-400">کد پرسنلی:</span> <b dir="ltr">${e2p(c.holder_personnel_code) || '-'}</b></div>
                        <div><span class="text-slate-400">موبایل دارنده:</span> <b dir="ltr">${e2p(c.holder_mobile) || '-'}</b></div>
                        <div><span class="text-slate-400">نسبت بیمه‌گذار با دارنده:</span> <b>${c.insured_relationship || '-'}</b></div>
                        <div><span class="text-slate-400">شرکت:</span> <b>${c.company_name || '-'}</b></div>
                        ${data.company && data.company.phone ? `<div><span class="text-slate-400">تلفن شرکت:</span> <b dir="ltr">${e2p(data.company.phone)}</b></div>` : ''}
                        ${data.company && data.company.economic_code ? `<div><span class="text-slate-400">کد اقتصادی شرکت:</span> <b dir="ltr">${e2p(data.company.economic_code)}</b></div>` : ''}
                        ${data.company && data.company.address ? `<div class="col-span-2"><span class="text-slate-400">آدرس شرکت:</span> <b>${data.company.address}</b></div>` : ''}
                        <div><span class="text-slate-400">تاریخ معرفی‌نامه:</span> <b>${c.letter_date_fa ? e2p(c.letter_date_fa) : '-'}</b></div>
                        <div><span class="text-slate-400">فایل معرفی‌نامه:</span> ${c.intro_file_path && c.intro_file_path !== 'ثبت_دستی'
                            ? `<a href="/${encodeFilePath(c.intro_file_path)}" target="_blank" data-gv="/${encodeFilePath(c.intro_file_path)}" data-gv-label="معرفی‌نامه" class="text-blue-600 underline font-bold">مشاهده</a>` : '<b>-</b>'}</div>
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
                            <div class="col-span-2">${plateSplitHtml('naming-plate', c.plate || '')}</div>
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
                                <div id="final-pf" class="mb-2"></div>
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
                        <div class="space-y-2" data-gv-group="مدارک ${(c.insured_name || '').replace(/"/g, '')}">${docsHtml}</div>
                    </div>

                    ${data.health ? `
                    <div data-gv-group="بازدید سلامت ${(c.insured_name || '').replace(/"/g, '')}">
                        <div class="flex justify-between items-center mb-2 flex-wrap gap-1">
                            <p class="text-xs font-bold text-slate-600"><i class="fas fa-car ml-1 text-rose-400"></i>بازدید سلامت شماره ${e2p(data.health.number)}
                                <span class="font-normal ${data.health.status === 'APPROVED' ? 'text-emerald-600' : 'text-amber-600'}">(${data.health.status === 'APPROVED' ? 'تایید نهایی' + (data.health.approved_jalali ? ' ' + e2p(data.health.approved_jalali) : '') : data.health.status === 'PHOTOS_APPROVED' ? 'عکس‌ها تایید شد، منتظر گزارش' : data.health.status === 'REJECTED' ? 'ناقص' : 'در انتظار بررسی'})</span></p>
                            ${data.health.report ? `<a href="/${encodeFilePath(data.health.report)}" target="_blank" data-gv="/${encodeFilePath(data.health.report)}" data-gv-label="گزارش کارشناس" class="text-[11px] text-blue-600 underline font-bold">📄 گزارش کارشناس</a>` : ''}
                        </div>
                        <div class="grid grid-cols-4 md:grid-cols-7 gap-1.5">${data.health.photos.map(ph => `
                            <div class="rounded-lg border ${ph.status === 'APPROVED' ? 'border-emerald-300' : ph.status === 'REJECTED' ? 'border-rose-300' : 'border-slate-200'} overflow-hidden bg-white" title="${ph.label}${ph.note ? ' — ' + ph.note : ''}">
                                <img src="/${encodeFilePath(ph.path)}" data-gv="/${encodeFilePath(ph.path)}" data-gv-label="${ph.label}${ph.note ? ' — ' + ph.note : ''}" loading="lazy" class="w-full h-14 object-cover cursor-zoom-in">
                                <p class="text-[9px] p-0.5 truncate">${ph.label}</p>
                            </div>`).join('')}</div>
                    </div>` : ''}

                    ${c.insurance_type === 'BODY' && window.VR && VR.openTargetBuilder ? `
                    <div class="bg-violet-50 border border-violet-100 rounded-xl p-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-bold text-violet-700"><i class="fas fa-file-circle-check ml-1"></i>گزارش بازدید
                            <span class="block font-normal text-[10px] text-slate-500 mt-0.5">اطلاعاتِ همین درخواست خودکار در فرم می‌نشیند و قالبِ سواری/سنگین خودکار انتخاب می‌شود؛ اگر بازدیدِ سلامتِ تاییدشده دارد، از همان عکس‌ها ساخته می‌شود.</span></p>
                        <button onclick="openCaseVisitReport(${caseId})" class="text-[11px] font-bold text-white bg-gradient-to-l from-indigo-600 to-violet-600 hover:shadow-lg px-3 py-1.5 rounded-lg whitespace-nowrap"><i class="fas fa-file-circle-plus ml-1"></i>ساخت گزارش بازدید</button>
                    </div>` : ''}

                    ${!isIssueMode && IS_ADMIN ? `
                    <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4">
                        <p class="text-xs font-bold text-indigo-700 mb-3"><i class="fas fa-user-pen ml-1"></i>تکمیل/ویرایش دستیِ اطلاعات (برای پرونده‌های ثبتِ دستی - چون خودمان وارد می‌کنیم)</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="col-span-2">${plateSplitHtml('adm-plate', c.plate || '')}</div>
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
                            <select id="adm-doc-key" class="border rounded-lg px-2 py-1.5 text-xs" onchange="document.getElementById('adm-other-name').classList.toggle('hidden', this.value !== 'other')">
                                ${Object.entries(data.required_docs || {}).map(([k, l]) => `<option value="${k}">${l}</option>`).join('') || ''}
                                <option value="other">سایر مدارک</option>
                            </select>
                            <input type="text" id="adm-other-name" class="hidden border rounded-lg px-2 py-1.5 text-xs" placeholder="نام مدرک (همین نام در بایگانی می‌آید)">
                            <input type="file" id="adm-doc-file" class="border rounded-lg px-2 py-1.5 text-xs">
                        </div>
                        <button onclick="uploadAdminCaseDoc(${caseId})" class="mt-2 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold px-4 py-1.5 rounded-lg">بارگذاری و تایید خودکار</button>
                    </div>` : ''}
                `;
                // درخواستِ خارج از فاز عملیاتی فقط برای دیدن است: دکمه‌های عملیاتی و آپلودها برداشته می‌شوند
                if (c.status === 'WITHDRAWN') {
                    body.querySelectorAll('button[onclick], input[type="file"], select, textarea').forEach(el => {
                        const oc = el.getAttribute('onclick') || '';
                        if (el.tagName === 'BUTTON' && /openUserChat|downloadSelectedDocs|openImageZoom|copy/i.test(oc)) return;
                        el.tagName === 'BUTTON' || el.type === 'file' ? el.remove() : (el.disabled = true);
                    });
                }
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
                    statusEl.innerHTML = '✅ اطلاعات فایل شناسایی شد - لطفاً بررسی/ویرایش کنید، سپس تایید نهایی بزنید.'
                        + ((data.receipts || []).length ? `<br><span class="text-[11px] text-indigo-700 font-bold">${e2pNum(data.receipts.length)} فیشِ قسط داخلِ فایل پیدا شد؛ صفحه‌های بیمه‌نامه جدا ذخیره و هر فیش روی ردیفِ قسطِ خودش گذاشته می‌شود.</span>` : '')
                        + ((data.missing || []).length ? `<br><span class="text-[11px] text-amber-700">خوانده نشد: ${data.missing.join('، ')}</span>` : '');
                    pfRender(document.getElementById('final-pf'), o, {receipts: data.receipts || [], missing: data.missing || [], source: data.source, layout_debug: data.layout_debug});
                } else {
                    statusEl.innerHTML = `⚠️ فایل شناسایی نشد - فیلدها را دستی پر کنید.<br><span class="text-[10px] text-slate-400">علت: ${data.ocr_debug || 'نامشخص'}</span>`;
                    pfRender(document.getElementById('final-pf'), {}, {});
                }
            } catch(e) {
                document.getElementById('policy-upload-label').style.pointerEvents = '';
                statusEl.textContent = '';
                showToast('خطا در اتصال به سرور.', 'error');
            }
        }

        async function confirmIssuePolicy(caseId) {
            const box = document.getElementById('final-pf');
            const miss = pfCheck(box);
            if (miss.length) { showToast(miss.join(' و ') + ' الزامی است.', 'error'); return; }
            const v = pfCollect(box);
            // نام‌هایی که سرورِ صدورِ کارکنان می‌خواند
            const body = {
                action: 'confirm_issue_policy', case_id: caseId,
                policy_number: v.policy_num, vin: v.vin, chassis_num: v.vin, engine_num: v.engine_no, unique_code: v.central_no,
                total_premium: v.premium, car_system: v.car_system, car_type: v.car_tip || v.car_kind, model_year: v.model_year,
                car_color: v.color, car_usage: v.usage, phone: v.phone, issue_date: v.issue_date, car_value: v.car_value, fields: v,
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

        // نمایشِ عکس در همان صفحه (گالری با زوم). اگر عکس داخلِ یک گروهِ [data-gv-group] باشد،
        // عکس‌های دیگرِ همان گروه هم با قبلی/بعدی دیده می‌شوند.
        function openImageZoom(src, label) { gvOpen([{src, label: label || ''}], 0); }

        let gvItems = [], gvIdx = 0, gvZ = 1, gvX = 0, gvY = 0, gvR = 0;
        const GV_IMG_RE = /\.(jpe?g|png|webp|gif|bmp)(\?|$)/i;
        function gvOpen(items, idx = 0, sub = '') {
            gvItems = (items || []).filter(it => it && it.src);
            if (!gvItems.length) return;
            const m = document.getElementById('gv-modal');
            if (m.parentElement !== document.body) document.body.appendChild(m);
            document.getElementById('gv-sub').textContent = sub;
            m.classList.add('open');
            document.getElementById('gv-strip').style.display = gvItems.length > 1 ? 'flex' : 'none';
            document.querySelectorAll('#gv-modal .gv-nav').forEach(b => b.style.display = gvItems.length > 1 ? '' : 'none');
            gvShow(Math.max(0, Math.min(idx, gvItems.length - 1)));
        }
        function gvClose() { document.getElementById('gv-modal').classList.remove('open'); document.getElementById('gv-img').src = ''; }
        function gvShow(i) {
            gvIdx = i;
            const it = gvItems[i];
            document.getElementById('gv-img').src = it.src;
            document.getElementById('gv-open').href = it.src;
            document.getElementById('gv-title').textContent = (it.label || '') + (gvItems.length > 1 ? `  (${e2p(i + 1)} از ${e2p(gvItems.length)})` : '');
            document.getElementById('gv-strip').innerHTML = gvItems.length > 1 ? gvItems.map((x, j) =>
                `<button class="${j === i ? 'on' : ''}" onclick="gvShow(${j})" title="${(x.label || '').replace(/"/g, '')}"><img src="${x.src}" loading="lazy" alt=""></button>`).join('') : '';
            gvR = 0; gvReset();
        }
        function gvGo(step) { if (gvItems.length > 1) gvShow((gvIdx + step + gvItems.length) % gvItems.length); }
        function gvApply() { document.getElementById('gv-img').style.transform = `translate(${gvX}px, ${gvY}px) scale(${gvZ}) rotate(${gvR}deg)`; }
        function gvReset() { gvZ = 1; gvX = 0; gvY = 0; gvApply(); }
        function gvRotate() { gvR = (gvR - 90) % 360; gvZ = 1; gvX = 0; gvY = 0; const img = document.getElementById('gv-img'); img.style.transformOrigin = gvR ? '50% 50%' : '0 0'; gvApply(); }
        function gvZoomAt(f, cx, cy) {
            const img = document.getElementById('gv-img');
            if (gvR) { gvZ = Math.min(8, Math.max(1, gvZ * f)); gvApply(); return; }
            const r = img.getBoundingClientRect();
            const nz = Math.min(8, Math.max(1, gvZ * f));
            const ox = (cx - r.left) / gvZ, oy = (cy - r.top) / gvZ;
            gvX += (cx - r.left) - ox * nz; gvY += (cy - r.top) - oy * nz; gvZ = nz;
            if (gvZ === 1) { gvX = 0; gvY = 0; }
            gvApply();
        }
        function gvZoomBy(f) { const r = document.getElementById('gv-stage').getBoundingClientRect(); gvZoomAt(f, r.left + r.width / 2, r.top + r.height / 2); }
        (function gvBind() {
            const stage = document.getElementById('gv-stage');
            if (!stage) return;
            const pts = new Map(); let last = null, pinch = null;
            stage.addEventListener('wheel', e => { e.preventDefault(); gvZoomAt(e.deltaY < 0 ? 1.15 : 1 / 1.15, e.clientX, e.clientY); }, {passive: false});
            stage.addEventListener('dblclick', e => { if (gvZ > 1) gvReset(); else gvZoomAt(2.5, e.clientX, e.clientY); });
            stage.addEventListener('pointerdown', e => { if (e.target.closest('button,a')) return; stage.setPointerCapture(e.pointerId); pts.set(e.pointerId, {x: e.clientX, y: e.clientY}); last = {x: e.clientX, y: e.clientY}; });
            stage.addEventListener('pointermove', e => {
                if (!pts.has(e.pointerId)) return;
                pts.set(e.pointerId, {x: e.clientX, y: e.clientY});
                if (pts.size === 2) {
                    const [a, b] = [...pts.values()]; const d = Math.hypot(a.x - b.x, a.y - b.y);
                    if (pinch) gvZoomAt(d / pinch, (a.x + b.x) / 2, (a.y + b.y) / 2);
                    pinch = d;
                } else if (last && gvZ > 1) { gvX += e.clientX - last.x; gvY += e.clientY - last.y; gvApply(); }
                last = {x: e.clientX, y: e.clientY};
            });
            const up = e => { pts.delete(e.pointerId); if (pts.size < 2) pinch = null; if (!pts.size) last = null; };
            stage.addEventListener('pointerup', up); stage.addEventListener('pointercancel', up);
            document.addEventListener('keydown', e => {
                if (!document.getElementById('gv-modal').classList.contains('open')) return;
                if (e.key === 'Escape') gvClose(); else if (e.key === 'ArrowLeft') gvGo(1); else if (e.key === 'ArrowRight') gvGo(-1);
            });
            // هر عنصرِ [data-gv] (عکس یا لینک به عکس) با کلیک در همین صفحه باز می‌شود
            document.addEventListener('click', e => {
                const el = e.target.closest('[data-gv]');
                if (!el) return;
                const src = el.getAttribute('data-gv');
                if (!GV_IMG_RE.test(src)) return;   // PDF و فایل‌های دیگر مثل قبل در تبِ جدید
                e.preventDefault(); e.stopPropagation();
                const group = el.closest('[data-gv-group]') || document;
                const all = [...group.querySelectorAll('[data-gv]')].filter(x => GV_IMG_RE.test(x.getAttribute('data-gv')));
                const seen = new Set(); const items = [];
                all.forEach(x => { const u = x.getAttribute('data-gv'); if (!seen.has(u)) { seen.add(u); items.push({src: u, label: x.getAttribute('data-gv-label') || x.getAttribute('title') || ''}); } });
                gvOpen(items, Math.max(0, items.findIndex(x => x.src === src)), group.getAttribute ? (group.getAttribute('data-gv-group') || '') : '');
            }, true);
        })();
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
            if (docKeySel.value === 'other') {
                const nm = document.getElementById('adm-other-name').value.trim();
                if (!nm) { showToast('نام مدرک را بنویسید؛ با همین نام بایگانی می‌شود.', 'warning'); return; }
                fd.append('other_name', nm);
            }
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
            box.innerHTML = '<p class="text-center text-slate-400 text-sm p-4 col-span-full"><i class="fas fa-spinner fa-spin"></i></p>';
            try {
                const res = await fetch('api/case_actions.php?action=list');
                const data = await res.json();
                if (!data.ok) { box.innerHTML = '<p class="text-center text-red-500 text-sm p-4 col-span-full">خطا در اتصال به سرور.</p>'; return; }
                // فقط مدارکی که منتظرِ بررسیِ ما هستند؛ تاییدشده‌ها به «بازدیدهای تاییدشده» می‌روند
                const needsReview = data.data.filter(c => c.docs_pending > 0 && !['ISSUED', 'WITHDRAWN'].includes(c.status));
                document.getElementById('hs-docs').textContent = e2pNum(needsReview.length);
                if (needsReview.length === 0) { box.innerHTML = '<p class="text-center text-slate-400 text-sm p-4 col-span-full">مدرکی در انتظار بررسی نیست.</p>'; return; }
                box.innerHTML = needsReview.map(c => `
                    <div class="border border-slate-200 rounded-xl p-3 flex flex-col gap-2 bg-white">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-bold text-xs truncate">${c.insured_name || c.holder_name}</p>
                                <p class="text-[10px] text-slate-400" dir="ltr">${c.unique_code}</p>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${c.insurance_type === 'BODY' ? 'bg-cyan-50 text-cyan-700' : 'bg-blue-50 text-blue-700'}">${insurance_type_fa_js(c.insurance_type)}</span>
                        </div>
                        <div class="plate-display text-xs">${c.plate ? formatPlateHtml(c.plate) : '<span class="text-slate-400">بدون پلاک</span>'}</div>
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex gap-1.5 flex-wrap">
                                ${c.docs_pending > 0 ? `<span class="bg-amber-50 text-amber-700 text-[10px] font-bold px-2 py-0.5 rounded-full">${e2p(c.docs_pending)} در انتظار</span>` : ''}
                                ${c.docs_rejected > 0 ? `<span class="bg-red-50 text-red-600 text-[10px] font-bold px-2 py-0.5 rounded-full">${e2p(c.docs_rejected)} منتظر اصلاحِ کاربر</span>` : ''}
                                <span class="bg-slate-50 text-slate-500 text-[10px] px-2 py-0.5 rounded-full">${e2p(c.docs_approved)}/${e2p(c.docs_total)} تایید</span>
                            </div>
                            <button onclick="openCase(${c.id}, 'review')" class="bg-indigo-600 text-white text-[11px] font-bold px-3 py-1.5 rounded-lg hover:bg-indigo-700 whitespace-nowrap"><i class="fas fa-file-lines ml-1"></i>بررسی مدارک</button>
                        </div>
                    </div>`).join('');
            } catch(e) { box.innerHTML = '<p class="text-center text-red-500 text-sm p-4 col-span-full">خطا در اتصال به سرور.</p>'; }
        }

        // ===================== بازدید سلامت: فهرست + بررسیِ تک‌تکِ عکس‌ها =====================
        let healthListCache = [], healthPerPhoto = true;
        async function loadHealthTab() {
            const body = document.getElementById('health-tab-body');
            body.innerHTML = '<p class="text-center text-slate-400 text-sm p-8 col-span-full"><i class="fas fa-spinner fa-spin"></i></p>';
            try {
                const res = await fetch('api/case_actions.php?action=health_list');
                const data = await res.json();
                if (!data.ok) { body.innerHTML = '<p class="text-center text-red-500 text-sm p-8 col-span-full">خطا در دریافت اطلاعات.</p>'; return; }
                healthListCache = data.data || [];
                healthPerPhoto = data.per_photo !== false;
                const cnt = st => healthListCache.filter(h => h.status === st).length;
                document.getElementById('hs-pending').textContent = e2pNum(cnt('PENDING'));
                document.getElementById('hs-report').textContent = e2pNum(cnt('PHOTOS_APPROVED'));
                document.getElementById('hs-rejected').textContent = e2pNum(cnt('REJECTED'));
                renderHealthList();
            } catch(e) { body.innerHTML = '<p class="text-center text-red-500 text-sm p-8 col-span-full">خطا در دریافت اطلاعات.</p>'; }
        }

        function renderHealthList() {
            const body = document.getElementById('health-tab-body');
            const f = document.getElementById('health-filter').value;
            // بازدیدهای تاییدِ نهایی این‌جا نمی‌آیند (صفحه‌ی «بازدیدهای تاییدشده»)
            const list = healthListCache.filter(h => h.status !== 'APPROVED' && (f === 'TODO' ? ['PENDING', 'PHOTOS_APPROVED'].includes(h.status) : (!f || h.status === f)));
            if (!list.length) { body.innerHTML = '<p class="text-center text-slate-400 text-sm p-8 col-span-full">بازدیدی با این فیلتر نیست.</p>'; return; }
            body.innerHTML = list.map(h => {
                const photos = Object.values(JSON.parse(h.photos || '{}'));
                const c = h.review_counts || {APPROVED: 0, REJECTED: 0, PENDING: photos.length};
                const pill = h.status === 'PHOTOS_APPROVED' ? ['bg-blue-100 text-blue-700', 'عکس‌ها تایید شد - منتظر گزارش']
                           : h.status === 'REJECTED' ? ['bg-rose-100 text-rose-700', 'ناقص - منتظر ارسالِ دوباره']
                           : ['bg-amber-100 text-amber-700', 'در انتظار بررسی'];
                const thumbs = photos.slice(0, 7).map(p => `<img src="/${encodeFilePath(p)}" loading="lazy" class="w-12 h-12 object-cover rounded-lg border border-slate-200">`).join('')
                    + (photos.length > 7 ? `<span class="w-12 h-12 rounded-lg bg-slate-100 text-slate-500 text-[11px] font-bold flex items-center justify-center">+${e2p(photos.length - 7)}</span>` : '');
                // همه‌ی عکس‌ها تایید شده: با بارگذاریِ فایلِ گزارش، بازدید تاییدِ نهایی می‌شود
                const reportHtml = h.status !== 'PHOTOS_APPROVED' ? '' :
                    (window.VR ? `<button type="button" onclick="buildHealthReport(${h.id})" class="text-[11px] text-white bg-gradient-to-l from-indigo-600 to-violet-600 hover:shadow-lg px-2.5 py-1.5 rounded-lg font-bold"><i class="fas fa-file-circle-plus ml-1"></i>ساخت گزارش بازدید</button>` : '') +
                    `<label class="text-[11px] text-white bg-blue-600 hover:bg-blue-700 px-2.5 py-1.5 rounded-lg cursor-pointer font-bold">📄 بارگذاری گزارش و تایید نهایی<input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.zip,.doc,.docx" onchange="uploadHealthReport(${h.id}, this)"></label>`;
                const legacy = !healthPerPhoto && h.status === 'PENDING'
                    ? `<button onclick="approveHealth(${h.id})" class="bg-emerald-500 text-white px-3 py-1.5 rounded-lg text-[11px] font-bold">تایید کل</button>
                       <button onclick="rejectHealth(${h.id})" class="bg-red-100 text-red-600 px-3 py-1.5 rounded-lg text-[11px] font-bold">رد کل</button>` : '';
                return `<div class="border border-slate-200 rounded-xl p-3 bg-white flex flex-col gap-2">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-bold text-sm truncate">${h.holder_name || '-'}</p>
                            <p class="text-[10px] text-slate-400"><span dir="ltr">${h.unique_code}</span> · بازدید شماره ${e2p(h.inspection_number)} · ${toJalali(h.created_at)}</p>
                        </div>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap ${pill[0]}">${pill[1]}</span>
                    </div>
                    <div class="plate-display text-xs">${h.plate ? formatPlateHtml(h.plate) : ''}</div>
                    <div class="flex gap-1.5 flex-wrap">${thumbs}</div>
                    <div class="flex gap-1.5 flex-wrap text-[10px] font-bold">
                        <span class="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded-full">✓ ${e2pNum(c.APPROVED)} تایید</span>
                        <span class="bg-rose-50 text-rose-700 px-2 py-0.5 rounded-full">✗ ${e2pNum(c.REJECTED)} رد</span>
                        <span class="bg-amber-50 text-amber-700 px-2 py-0.5 rounded-full">${e2pNum(c.PENDING)} در انتظار</span>
                    </div>
                    ${h.status === 'REJECTED' && h.reject_reason ? `<p class="text-[10px] text-rose-600 bg-rose-50 rounded-lg p-2">${h.reject_reason}</p>` : ''}
                    <div class="flex items-center gap-2 flex-wrap">
                        <button onclick="hvOpen(${h.id})" class="bg-rose-600 hover:bg-rose-700 text-white px-3 py-1.5 rounded-lg text-[11px] font-bold"><i class="fas fa-magnifying-glass-plus ml-1"></i>${c.PENDING > 0 ? 'بررسی عکس‌ها' : 'مشاهده‌ی عکس‌ها'}</button>
                        <a href="api/case_actions.php?action=download_health_zip&inspection_id=${h.id}" class="bg-slate-100 text-slate-600 px-3 py-1.5 rounded-lg text-[11px] font-bold hover:bg-slate-200"><i class="fas fa-file-zipper ml-1"></i>زیپ</a>
                        ${legacy}${reportHtml}
                    </div>
                </div>`;
            }).join('');
        }

        // ---- نمایشگر ----
        let hvData = null, hvIdx = 0, hvZ = 1, hvX = 0, hvY = 0;
        const HV_STATUS = {APPROVED: ['bg-emerald-600', 'تایید شده'], REJECTED: ['bg-rose-600', 'رد شده'], PENDING: ['bg-amber-500', 'در انتظار بررسی']};
        function hvVisible() {
            if (!hvData) return [];
            const only = document.getElementById('hv-only-pending').checked;
            const all = hvData.photos.map((p, i) => ({...p, i}));
            const f = all.filter(p => !only || p.status === 'PENDING');
            return f.length ? f : all;
        }
        async function hvOpen(id) {
            try {
                const res = await fetch('api/case_actions.php?action=health_detail&id=' + id);
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
                hvData = data;
                if (!data.per_photo) showToast('برای بررسیِ تک‌تک، مایگریشن ۰۱۳ را اجرا کنید؛ فعلاً فقط مشاهده ممکن است.', 'warning');
                const ins = data.inspection;
                document.getElementById('hv-title').textContent = `${ins.holder_name || '-'} — بازدید شماره ${e2p(ins.number)}`;
                document.getElementById('hv-sub').innerHTML = `<span dir="ltr">${ins.unique_code}</span> · <span class="plate-display">${ins.plate ? formatPlateHtml(ins.plate) : ''}</span>`;
                document.getElementById('hv-only-pending').checked = data.photos.some(p => p.status === 'PENDING');
                const m = document.getElementById('hv-modal');
                // داخلِ محتوای صفحه، لایه‌ی هدر رویش می‌افتاد؛ مستقیم زیرِ body می‌رود تا تمام‌صفحه باشد
                if (m.parentElement !== document.body) document.body.appendChild(m);
                m.classList.remove('hidden'); m.classList.add('flex');
                hvRenderStrip();
                hvGo(0, true);
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }
        function hvClose() {
            const m = document.getElementById('hv-modal');
            m.classList.add('hidden'); m.classList.remove('flex');
            hvData = null;
            loadHealthTab();
        }
        function hvRenderStrip() {
            const vis = hvVisible();
            document.getElementById('hv-strip').innerHTML = vis.map(p => `
                <button onclick="hvShow(${p.i})" class="shrink-0 relative rounded-lg overflow-hidden border-2 ${p.status === 'APPROVED' ? 'border-emerald-500' : p.status === 'REJECTED' ? 'border-rose-500' : 'border-amber-400'} ${p.i === hvIdx ? 'ring-2 ring-white' : ''}" title="${p.label}">
                    <img src="/${encodeFilePath(p.path)}" loading="lazy" class="w-16 h-12 object-cover">
                </button>`).join('');
            const c = {APPROVED: 0, REJECTED: 0, PENDING: 0};
            hvData.photos.forEach(p => c[p.status]++);
            document.getElementById('hv-counts').textContent = `✓ ${e2pNum(c.APPROVED)} · ✗ ${e2pNum(c.REJECTED)} · در انتظار ${e2pNum(c.PENDING)}`;
            const sum = document.getElementById('hv-summary');
            if (c.PENDING === 0) {
                sum.classList.remove('hidden');
                sum.className = 'text-[11px] leading-relaxed rounded-lg p-2 ' + (c.REJECTED ? 'bg-rose-900/60 text-rose-100' : 'bg-emerald-900/60 text-emerald-100');
                const st = hvData.inspection.status;
                if (c.REJECTED) sum.textContent = `بررسی تمام شد: ${e2pNum(c.REJECTED)} عکس رد شد. بازدید «ناقص» است و از کاربر خواسته شد فقط همین‌ها را دوباره بفرستد.`;
                else if (st === 'PHOTOS_APPROVED') sum.innerHTML = `<b>همه‌ی عکس‌ها تایید و بایگانی شد.</b><br>برای تاییدِ نهایی، گزارش بازدید را همین‌جا بسازید یا فایلِ گزارشِ کارشناس را بارگذاری کنید؛ بعد از آن پرونده وارد «در حال صدور» می‌شود.
                    ${window.VR ? `<button type="button" onclick="buildHealthReport(${hvData.inspection.id}, true)" class="mt-2 block w-full text-center text-[12px] text-white bg-gradient-to-l from-indigo-600 to-violet-600 px-3 py-2 rounded-lg font-bold"><i class="fas fa-file-circle-plus ml-1"></i>ساخت گزارش بازدید</button>` : ''}
                    <label class="mt-2 block text-center text-[12px] text-white bg-blue-600 hover:bg-blue-700 px-3 py-2 rounded-lg cursor-pointer font-bold">📄 بارگذاری گزارش و تایید نهایی
                        <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.zip,.doc,.docx" onchange="uploadHealthReport(${hvData.inspection.id}, this, true)"></label>`;
                else sum.innerHTML = 'بازدید به‌طور نهایی تایید شده است.' + (hvData.inspection.report_file_path ? ` <a class="underline" target="_blank" href="/${encodeFilePath(hvData.inspection.report_file_path)}">فایل گزارش</a>` : '')
                    + (window.VR ? ` · <button type="button" class="underline" onclick="buildHealthReport(${hvData.inspection.id}, true)">ساخت/ویرایش گزارش بازدید</button>` : '');
            } else sum.classList.add('hidden');
        }
        function hvShow(i) {
            if (!hvData || !hvData.photos[i]) return;
            hvIdx = i;
            const p = hvData.photos[i];
            const img = document.getElementById('hv-img');
            img.src = '/' + encodeFilePath(p.path);
            document.getElementById('hv-open').href = '/' + encodeFilePath(p.path);
            document.getElementById('hv-label').textContent = p.label;
            document.getElementById('hv-pos').textContent = `عکس ${e2p(i + 1)} از ${e2p(hvData.photos.length)}`;
            const st = document.getElementById('hv-status');
            st.className = 'inline-block mt-1 text-[11px] font-bold px-2 py-0.5 rounded-full ' + HV_STATUS[p.status][0];
            st.textContent = HV_STATUS[p.status][1];
            document.getElementById('hv-note').value = p.note || '';
            hvResetZoom();
            hvRenderStrip();
        }
        // حرکت در عکس‌های قابلِ‌نمایش (با فیلترِ «فقط در انتظار»)
        function hvGo(step, fromStart = false) {
            const vis = hvVisible();
            if (!vis.length) return;
            let pos = fromStart ? 0 : vis.findIndex(p => p.i === hvIdx);
            if (!fromStart) pos = (pos < 0 ? 0 : pos + step + vis.length) % vis.length;
            hvShow(vis[pos].i);
        }
        async function hvDecide(decision) {
            if (!hvData) return;
            const p = hvData.photos[hvIdx];
            const note = document.getElementById('hv-note').value.trim();
            if (decision === 'REJECTED' && !note) { showToast('علتِ رد را بنویسید تا کاربر بداند چه چیزی را درست کند.', 'warning'); document.getElementById('hv-note').focus(); return; }
            try {
                const res = await fetch('api/case_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'review_health_photo', id: hvData.inspection.id, key: p.key, decision, note})});
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا', 'error'); return; }
                p.status = decision; p.note = note; if (data.photo) p.path = data.photo;
                hvData.inspection.status = data.status;
                // برو سراغِ عکسِ بعدیِ در انتظار
                const next = hvData.photos.findIndex((x, i) => i > hvIdx && x.status === 'PENDING');
                const first = hvData.photos.findIndex(x => x.status === 'PENDING');
                if (next >= 0) hvShow(next); else if (first >= 0) hvShow(first); else hvShow(hvIdx);
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }
        // ---- زوم و جابه‌جایی (چرخِ موس، دو انگشت، کشیدن، دابل‌کلیک) ----
        function hvApply() { document.getElementById('hv-img').style.transform = `translate(${hvX}px, ${hvY}px) scale(${hvZ})`; }
        function hvResetZoom() { hvZ = 1; hvX = 0; hvY = 0; hvApply(); }
        function hvZoomAt(factor, cx, cy) {
            const img = document.getElementById('hv-img');
            const r = img.getBoundingClientRect();
            const nz = Math.min(8, Math.max(1, hvZ * factor));
            // نقطه‌ی زیرِ مکان‌نما ثابت بماند
            const ox = (cx - r.left) / hvZ, oy = (cy - r.top) / hvZ;
            hvX += (cx - r.left) - ox * nz; hvY += (cy - r.top) - oy * nz;
            hvZ = nz;
            if (hvZ === 1) { hvX = 0; hvY = 0; }
            hvApply();
        }
        function hvZoomBy(f) { const st = document.getElementById('hv-stage').getBoundingClientRect(); hvZoomAt(f, st.left + st.width / 2, st.top + st.height / 2); }
        (function hvBindGestures() {
            const stage = document.getElementById('hv-stage');
            if (!stage) return;
            const pts = new Map(); let last = null, pinch = null;
            stage.addEventListener('wheel', e => { e.preventDefault(); hvZoomAt(e.deltaY < 0 ? 1.15 : 1 / 1.15, e.clientX, e.clientY); }, {passive: false});
            stage.addEventListener('dblclick', e => { if (hvZ > 1) hvResetZoom(); else hvZoomAt(2.5, e.clientX, e.clientY); });
            stage.addEventListener('pointerdown', e => { if (e.target.closest('button,a')) return; stage.setPointerCapture(e.pointerId); pts.set(e.pointerId, {x: e.clientX, y: e.clientY}); last = {x: e.clientX, y: e.clientY}; });
            stage.addEventListener('pointermove', e => {
                if (!pts.has(e.pointerId)) return;
                pts.set(e.pointerId, {x: e.clientX, y: e.clientY});
                if (pts.size === 2) {
                    const [a, b] = [...pts.values()];
                    const d = Math.hypot(a.x - b.x, a.y - b.y);
                    if (pinch) hvZoomAt(d / pinch, (a.x + b.x) / 2, (a.y + b.y) / 2);
                    pinch = d;
                } else if (last && hvZ > 1) {
                    hvX += e.clientX - last.x; hvY += e.clientY - last.y; hvApply();
                }
                last = {x: e.clientX, y: e.clientY};
            });
            const up = e => { pts.delete(e.pointerId); if (pts.size < 2) pinch = null; if (!pts.size) last = null; };
            stage.addEventListener('pointerup', up); stage.addEventListener('pointercancel', up);
            document.addEventListener('keydown', e => {
                const m = document.getElementById('hv-modal');
                if (!m || m.classList.contains('hidden') || e.target.tagName === 'TEXTAREA') return;
                if (e.key === 'ArrowLeft') hvGo(1); else if (e.key === 'ArrowRight') hvGo(-1); else if (e.key === 'Escape') hvClose();
            });
        })();

        // «ساخت گزارش بازدید» از روی یک بازدید سلامت: همان فرمِ صدور در پاپ‌آپ؛ PDF مستقیم در همین بازدید می‌نشیند.
        // اگر برای این بازدید قبلاً گزارش صادر شده، همان گزارش برای ویرایش باز می‌شود.
        async function buildHealthReport(inspectionId, fromViewer = false) {
            if (!window.VR) { showToast('به بخشِ گزارش بازدید دسترسی ندارید.', 'error'); return; }
            const done = () => {
                if (fromViewer && hvData) { hvData.inspection.status = 'APPROVED'; hvRenderStrip(); }
                loadHealthTab();
            };
            const pre = await VR.api('health_prefill', { id: inspectionId });
            if (!pre.ok) { showToast(pre.error || 'خطا', 'error'); return; }
            if (pre.existing) {
                showToast(`برای این بازدید قبلاً گزارش ${pre.existing.report_no} صادر شده؛ همان برای ویرایش باز شد.`, 'info');
                VR.openEditor(pre.existing.id, done);
                return;
            }
            VR.openHealthBuilder(inspectionId, done);
        }

        async function uploadHealthReport(inspectionId, input, fromViewer = false) {
            if (!input.files[0]) return;
            const fd = new FormData();
            fd.append('action', 'upload_health_report');
            fd.append('inspection_id', inspectionId);
            fd.append('file', input.files[0]);
            try {
                const res = await fetch('api/case_actions.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا در بارگذاری گزارش.', 'error'); input.value = ''; return; }
                showToast(!data.final ? 'فایل گزارش بارگذاری شد.'
                    : data.stage === 'issuing' ? 'بازدید تایید نهایی شد و پرونده وارد «در حال صدور» شد.'
                    : 'بازدید تایید نهایی شد' + (hvData && hvData.inspection.insurance_type ? '؛ بعد از تاییدِ همه‌ی مدارک، پرونده وارد صدور می‌شود.' : '.'), 'success');
                if (fromViewer && hvData) { hvData.inspection.status = 'APPROVED'; hvData.inspection.report_file_path = data.report; hvRenderStrip(); }
                else loadHealthTab();
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
        }

        async function approveHealth(id) {
            const res = await fetch('api/case_actions.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'approve_health', id }) });
            const data = await res.json().catch(() => ({}));
            showToast(data.needs_report ? 'عکس‌ها تایید شد؛ برای تایید نهایی فایل گزارش را بارگذاری کنید.' : 'بازدید تایید شد.', 'success');
            loadHealthTab();
        }

        function rejectHealth(id) {
            openRejectHealthModal(id);
        }


        // ======================= بازدیدهای تاییدشده =======================
        let arRows = [];
        async function loadApprovedReviews() {
            const tb = document.getElementById('ar-body');
            tb.innerHTML = '<tr><td colspan="13" class="text-center p-8 text-slate-400"><i class="fas fa-spinner fa-spin"></i></td></tr>';
            try {
                const res = await fetch('api/case_actions.php?action=approved_reviews');
                const data = await res.json();
                if (!data.ok) { tb.innerHTML = `<tr><td colspan="13" class="text-center p-8 text-red-500">${data.error || 'خطا'}</td></tr>`; return; }
                arRows = data.rows || [];
                document.getElementById('ar-history-note').classList.toggle('hidden', data.history !== false);
                renderApprovedReviews();
            } catch (e) { tb.innerHTML = '<tr><td colspan="13" class="text-center p-8 text-red-500">خطا در اتصال به سرور.</td></tr>'; }
        }
        const arDateKey = v => { const m = p2e(String(v || '')).match(/(\d{4})\D+(\d{1,2})\D+(\d{1,2})/); return m ? m[1] + m[2].padStart(2, '0') + m[3].padStart(2, '0') : ''; };
        function renderApprovedReviews() {
            const q = p2e(document.getElementById('ar-q').value.trim()).toLowerCase();
            const src = document.getElementById('ar-source').value, type = document.getElementById('ar-type').value, comp = document.getElementById('ar-complete').value;
            const from = arDateKey(document.getElementById('ar-from').value), to = arDateKey(document.getElementById('ar-to').value);
            const list = arRows.filter(r => {
                if (src && r.source !== src) return false;
                if (type && r.insurance_type !== type) return false;
                if (comp === '1' && !r.complete) return false;
                if (comp === '0' && r.complete) return false;
                if (comp === 'rej' && !(r.rejections > 0)) return false;
                const d = arDateKey(r.approved_at_jalali);
                if (from && (!d || d < from)) return false;
                if (to && (!d || d > to)) return false;
                if (q) {
                    const hay = [r.unique_code, r.holder_name, r.insured_name, r.national_id, r.personnel_code, r.company_name, r.plate, (r.plate || '').replace(/\s/g, '')].join(' ').toLowerCase();
                    if (!hay.includes(q) && !hay.includes(q.replace(/\s/g, ''))) return false;
                }
                return true;
            });
            document.getElementById('ar-c-all').textContent = e2pNum(list.length);
            document.getElementById('ar-c-done').textContent = e2pNum(list.filter(r => r.complete).length);
            document.getElementById('ar-c-part').textContent = e2pNum(list.filter(r => !r.complete).length);
            document.getElementById('ar-c-rej').textContent = e2pNum(list.filter(r => r.rejections > 0).length);
            const tb = document.getElementById('ar-body');
            if (!list.length) { tb.innerHTML = '<tr><td colspan="13" class="text-center p-8 text-slate-400">موردی با این فیلترها نیست.</td></tr>'; return; }
            const srcCls = {PERSONNEL: 'bg-indigo-50 text-indigo-700', COMPANY: 'bg-cyan-50 text-cyan-700', FREE: 'bg-slate-100 text-slate-600'};
            const hs = r => r.health_status === 'NA' ? '<span class="text-slate-300">—</span>'
                : r.health_status === 'APPROVED' ? '<span class="text-emerald-600 font-bold">✓ تایید نهایی</span>'
                : r.health_status === 'PHOTOS_APPROVED' ? '<span class="text-blue-600 font-bold">منتظر گزارش</span>'
                : r.health_status === 'NONE' ? '<span class="text-slate-400">انجام نشده</span>'
                : r.health_status === 'REJECTED' ? '<span class="text-rose-600">ناقص</span>' : '<span class="text-amber-600">در انتظار</span>';
            tb.innerHTML = list.map((r, i) => `
                <tr class="border-t hover:bg-emerald-50/30">
                    <td class="p-3 text-slate-400">${e2p(i + 1)}</td>
                    <td class="p-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${srcCls[r.source]}">${r.source_fa}</span></td>
                    <td class="p-3 font-mono text-indigo-600" dir="ltr">${e2p(r.unique_code)}</td>
                    <td class="p-3"><b>${r.insured_name || '-'}</b>${r.holder_name && r.holder_name !== r.insured_name ? `<div class="text-[10px] text-slate-400">دارنده: ${r.holder_name}</div>` : ''}${r.national_id ? `<div class="text-[10px] text-slate-400" dir="ltr">${e2p(r.national_id)}</div>` : ''}</td>
                    <td class="p-3">${r.company_name || '<span class="text-slate-300">—</span>'}</td>
                    <td class="p-3">${r.insurance_type ? insurance_type_fa_js(r.insurance_type) : '<span class="text-slate-300">—</span>'}</td>
                    <td class="p-3 whitespace-nowrap">${r.plate ? formatPlateHtml(r.plate) : '—'}</td>
                    <td class="p-3">${r.source === 'PERSONNEL' ? (r.docs_ok ? `<span class="text-emerald-600 font-bold">✓ ${e2pNum(r.docs_approved)}</span>` : `<span class="text-amber-600">${e2pNum(r.docs_approved)}/${e2pNum(r.docs_required)}</span>`) : '<span class="text-slate-300">—</span>'}</td>
                    <td class="p-3">${hs(r)}</td>
                    <td class="p-3 whitespace-nowrap">${r.approved_at_jalali ? e2p(r.approved_at_jalali) : '—'}</td>
                    <td class="p-3">${r.rejections > 0 ? `<span class="bg-rose-50 text-rose-600 px-2 py-0.5 rounded-full text-[10px] font-bold">${e2pNum(r.rejections)} بار رد</span>` : '<span class="text-slate-300">—</span>'}</td>
                    <td class="p-3">${r.complete ? '<span class="text-emerald-700 text-[10px] font-bold">کامل</span> · ' : '<span class="text-amber-600 text-[10px] font-bold">بخشی</span> · '}<span class="text-[10px] text-slate-500">${r.case_status_fa || ''}</span></td>
                    <td class="p-3"><button onclick="openApprovedDetail('${r.source}', ${r.id})" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-[11px] font-bold whitespace-nowrap">جزئیات</button></td>
                </tr>`).join('');
        }

        const AR_DECISION = {APPROVED: ['✓ تایید', 'text-emerald-700 bg-emerald-50'], REJECTED: ['✗ رد', 'text-rose-700 bg-rose-50'],
                             RESUBMITTED: ['↻ ارسالِ دوباره', 'text-blue-700 bg-blue-50'], FINAL_APPROVED: ['✓✓ تاییدِ نهایی', 'text-emerald-800 bg-emerald-100'],
                             UPLOADED: ['📄 بارگذاری', 'text-slate-700 bg-slate-100'], PENDING: ['در انتظار', 'text-amber-700 bg-amber-50']};
        const AR_ENTITY = {CASE_DOC: 'مدرک', HEALTH_PHOTO: 'عکسِ بازدید', HEALTH: 'بازدید', HEALTH_REPORT: 'گزارش بازدید'};
        async function openApprovedDetail(source, id) {
            const m = document.getElementById('ar-detail-modal');
            const body = document.getElementById('ar-d-body');
            body.innerHTML = '<p class="text-center text-slate-400 p-6"><i class="fas fa-spinner fa-spin"></i></p>';
            m.classList.add('active');
            try {
                const res = await fetch(`api/case_actions.php?action=approved_review_detail&source=${source}&id=${id}`);
                const d = await res.json();
                if (!d.ok) { body.innerHTML = `<p class="text-center text-red-500 p-6">${d.error || 'خطا'}</p>`; return; }
                const row = (k, v) => v === null || v === undefined || v === '' ? '' : `<div><span class="text-slate-400">${k}:</span> <b>${v}</b></div>`;
                let info = '';
                if (d.case) {
                    const c = d.case;
                    document.getElementById('ar-d-title').innerHTML = `${c.insured_name || c.holder_name} — <span dir="ltr">${e2p(c.unique_code)}</span>`;
                    info = `<div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-[11px] bg-slate-50 rounded-xl p-3">
                        ${row('نوع بیمه', insurance_type_fa_js(c.insurance_type))}${row('وضعیت پرونده', c.status_fa)}${row('ثبت درخواست', c.created_at_fa ? e2p(c.created_at_fa) : '')}
                        ${row('بیمه‌گذار', c.insured_name)}${row('نسبت', c.insured_relationship)}${row('کد ملی بیمه‌گذار', c.insured_national_id ? `<span dir="ltr">${e2p(c.insured_national_id)}</span>` : '')}
                        ${row('تاریخ تولد', c.insured_birth_date ? e2p(c.insured_birth_date) : '')}${row('موبایل', c.insured_phone ? `<span dir="ltr">${e2p(c.insured_phone)}</span>` : '')}${row('کد پستی', c.insured_postal_code ? e2p(c.insured_postal_code) : '')}
                        <div class="col-span-2 md:col-span-3">${row('آدرس', c.insured_address)}</div>
                        ${row('پلاک', c.plate ? formatPlateHtml(c.plate) : '')}${row('مالکیت', c.ownership_choice)}
                        ${c.insurance_type === 'THIRDPARTY' ? row('سقف تعهد', c.liability_limit ? money(c.liability_limit) + ' ریال' : '') : ''}
                        ${c.insurance_type === 'BODY' ? row('ارزش خودرو', c.estimated_car_value === null ? '' : (Number(c.estimated_car_value) ? money(c.estimated_car_value) + ' ریال' : 'محاسبه توسط کارشناس')) + row('بیمه بدنه قبل', c.prev_body_insurance) + row('خسارت قبلی', c.prev_body_claim) + row('سال عدم خسارت', c.no_claim_years !== null ? e2p(c.no_claim_years) : '') : ''}
                        ${c.coverages_fa && c.coverages_fa.length ? `<div class="col-span-2 md:col-span-3">${row('پوشش‌ها', c.coverages_fa.join('، '))}</div>` : ''}
                        <div class="col-span-2 md:col-span-3 border-t pt-2 mt-1 text-slate-500 font-bold">دارنده‌ی معرفی‌نامه</div>
                        ${row('نام', c.holder_name)}${row('کد ملی', c.holder_nid ? `<span dir="ltr">${e2p(c.holder_nid)}</span>` : '')}${row('کد پرسنلی', c.personnel_code ? e2p(c.personnel_code) : '')}
                        ${row('شرکت', c.employer_name)}${row('تاریخ معرفی‌نامه', c.letter_date_fa ? e2p(c.letter_date_fa) : '')}${row('سهمیه', c.max_quota ? e2p((c.used_quota || 0) + ' / ' + c.max_quota) : '')}
                        ${c.intro_file && c.intro_file !== 'ثبت_دستی' ? `<div><a class="text-blue-600 underline" target="_blank" href="/${encodeFilePath(c.intro_file)}" data-gv="/${encodeFilePath(c.intro_file)}" data-gv-label="معرفی‌نامه">فایل معرفی‌نامه</a></div>` : ''}
                    </div>`;
                } else {
                    const p = d.person || {};
                    document.getElementById('ar-d-title').innerHTML = `${p.name || 'بازدید آزاد'} — بازدید سلامت`;
                    info = `<div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-[11px] bg-slate-50 rounded-xl p-3">
                        ${row('شخص', p.name)}${row('کد ملی', p.national_code && !String(p.national_code).startsWith('GUEST') ? `<span dir="ltr">${e2p(p.national_code)}</span>` : '')}${row('موبایل', p.mobile ? `<span dir="ltr">${e2p(p.mobile)}</span>` : '')}${row('پلاک', p.plate ? formatPlateHtml(p.plate) : '')}</div>`;
                }
                const docsHtml = (d.docs || []).length ? `<div data-gv-group="مدارک">
                    <h3 class="text-xs font-bold text-indigo-700 mb-2"><i class="fas fa-file-lines ml-1"></i>مدارک</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">${d.docs.map(x => {
                        const img = /\.(jpe?g|png|webp|gif)$/i.test(x.path || '');
                        const cls = x.status === 'APPROVED' ? 'border-emerald-200 bg-emerald-50/50' : x.status === 'REJECTED' ? 'border-rose-200 bg-rose-50/50' : 'border-amber-200 bg-amber-50/50';
                        return `<div class="border rounded-xl p-2 text-[11px] ${cls}">
                            ${x.path ? (img ? `<img src="/${encodeFilePath(x.path)}" data-gv="/${encodeFilePath(x.path)}" data-gv-label="${x.label}" loading="lazy" class="w-full h-24 object-cover rounded-lg cursor-zoom-in mb-1">`
                                             : `<a href="/${encodeFilePath(x.path)}" target="_blank" class="block h-24 rounded-lg bg-white border text-center pt-8 text-blue-600 mb-1"><i class="fas fa-file-pdf"></i> فایل</a>`) : ''}
                            <b>${x.label}</b>
                            <div class="${x.status === 'APPROVED' ? 'text-emerald-700' : x.status === 'REJECTED' ? 'text-rose-600' : 'text-amber-600'}">${x.status === 'APPROVED' ? '✓ تایید' : x.status === 'REJECTED' ? '✗ رد' : 'در انتظار'}${x.reviewed_at ? ' · ' + e2p(x.reviewed_at) : ''}</div>
                            ${x.reject_reason && x.status === 'REJECTED' ? `<div class="text-rose-600">علت: ${x.reject_reason}</div>` : ''}
                            <div class="text-slate-400">بارگذاری: ${x.uploaded_at ? e2p(x.uploaded_at) : '—'}</div>
                        </div>`; }).join('')}</div></div>` : '';
                const inspHtml = (d.inspections || []).map(h => `<div data-gv-group="بازدید سلامت شماره ${e2p(h.number)}">
                    <h3 class="text-xs font-bold text-rose-700 mb-2"><i class="fas fa-car ml-1"></i>بازدید سلامت شماره ${e2p(h.number)}
                        <span class="font-normal text-slate-500">· ثبت ${h.created_at ? e2p(h.created_at) : ''}${h.approved_jalali ? ' · تایید ' + e2p(h.approved_jalali) : ''}</span>
                        ${h.report ? `<a class="mr-2 text-blue-600 underline font-normal" target="_blank" href="/${encodeFilePath(h.report)}" data-gv="/${encodeFilePath(h.report)}" data-gv-label="گزارش کارشناس">📄 گزارش کارشناس</a>` : ''}</h3>
                    <div class="grid grid-cols-3 md:grid-cols-6 gap-1.5">${h.photos.map(ph => `
                        <div class="text-[10px] rounded-lg border ${ph.status === 'APPROVED' ? 'border-emerald-300' : ph.status === 'REJECTED' ? 'border-rose-300' : 'border-amber-300'} overflow-hidden bg-white">
                            <img src="/${encodeFilePath(ph.path)}" data-gv="/${encodeFilePath(ph.path)}" data-gv-label="${ph.label}${ph.note ? ' — ' + ph.note : ''}" loading="lazy" class="w-full h-16 object-cover cursor-zoom-in">
                            <div class="p-1"><b>${ph.label}</b>${ph.note ? `<div class="text-slate-500">${ph.note}</div>` : ''}${ph.at ? `<div class="text-slate-400">${e2p(ph.at)}${ph.by ? ' · ' + ph.by : ''}</div>` : ''}</div>
                        </div>`).join('')}</div></div>`).join('');
                const hist = d.history || [];
                const histHtml = `<div><h3 class="text-xs font-bold text-slate-700 mb-2"><i class="fas fa-clock-rotate-left ml-1"></i>تاریخچه‌ی بررسی</h3>
                    ${hist.length ? `<ol class="border-r-2 border-slate-200 pr-3 space-y-1.5">${hist.map(x => `
                        <li class="text-[11px]"><span class="inline-block px-1.5 rounded ${(AR_DECISION[x.decision] || ['', ''])[1]} font-bold">${(AR_DECISION[x.decision] || [x.decision])[0]}</span>
                            <b>${AR_ENTITY[x.entity] || ''} «${x.label || ''}»</b>${x.note ? ` — <span class="text-slate-600">${x.note}</span>` : ''}
                            <span class="text-slate-400">· ${x.at ? e2p(x.at) : ''}${x.by ? ' · ' + x.by : ''}</span></li>`).join('')}</ol>`
                    : '<p class="text-[11px] text-slate-400">تاریخچه‌ای ثبت نشده (رد یا ارسالِ دوباره‌ای نداشته، یا مایگریشن ۰۱۵ هنوز اجرا نشده).</p>'}</div>`;
                body.innerHTML = info + docsHtml + inspHtml + histHtml;
            } catch (e) { body.innerHTML = '<p class="text-center text-red-500 p-6">خطا در اتصال به سرور.</p>'; }
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

        // شمارنده‌ی پیام‌های نخوانده‌ی منوی «گفتگوها»: pollChatBadge (بخشِ پیام‌رسان)

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
                            <td class="p-4"><span class="inline-flex items-center gap-2">${window.ChatUI ? ChatUI.avatarHtml(u.avatar, u.full_name || '؟', {size: 34, online: u.presence && u.presence.online}) : ''}<span>${u.full_name || '-'}${u.presence ? `<span class="block text-[10px] font-normal ${u.presence.online ? 'text-emerald-600 font-bold' : 'text-slate-400'}">${ChatUI.seenLabel(u.presence, Date.now())}</span>` : ''}</span></span></td>
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
                    document.getElementById('user-chat-body').innerHTML += renderChatBubble(true, '📱 (به مینی‌اپ) ' + text, irNowStr(), null, null, 0);
                } else {
                    document.getElementById('user-chat-body').innerHTML += renderChatBubble(true, text, irNowStr(), null, data.id || null);
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
            document.getElementById('user-chat-body').innerHTML += renderChatBubble(true, '📎 در حال ارسال فایل...', irNowStr());
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
        // ======================= پیام‌رسانِ یکپارچه (chat-ui.js + api/chat_actions.php) =======================
        const CHAT_API = 'api/chat_actions.php';
        // بدنه base64 می‌رود تا فایروالِ هاست روی متنِ پیام‌ها حساس نشود
        const chatB64 = o => btoa(unescape(encodeURIComponent(JSON.stringify(o || {}))));
        async function chatCall(action, data) {
            const res = await fetch(CHAT_API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action, p: chatB64(data)})});
            return res.json();
        }
        async function chatUpload(action, fd) {
            fd.append('action', action);
            const res = await fetch(CHAT_API, {method: 'POST', body: fd});
            return res.json();
        }
        function setChatBadge(n) {
            const b = document.getElementById('chat-nav-badge');
            if (!b) return;
            n = parseInt(n || 0);
            if (n > 0) { b.textContent = e2p(n > 99 ? '99+' : n); b.classList.remove('hidden'); } else b.classList.add('hidden');
        }
        let chatUI = null;
        // key مثل «P:12» (کارکنان)، «C:3» (شرکت)، «S:5» (همکار داخلی) - اختیاری
        function openMessenger(key) {
            switchGoftegoSub('messenger');
            if (!window.ChatUI) return;
            if (!chatUI) chatUI = ChatUI.mount(document.getElementById('chat-root'), {mode: 'full', theme: 'light', call: chatCall, upload: chatUpload, openKey: key || null, onUnread: setChatBadge});
            else if (key) chatUI.open(key);
        }
        // «آنلاین / آخرین بازدید»: هر ۱۰ ثانیه حضور را ثبت می‌کند (همان پاسخ شمارِ پیام‌های نخوانده را هم می‌آورد)
        // و با بستنِ صفحه فوراً «آفلاین» می‌شود
        if (window.ChatUI) ChatUI.heartbeat({
            ping: () => chatCall('chat_ping', {}),
            offline: () => { const fd = new FormData(); fd.append('action', 'chat_offline'); navigator.sendBeacon && navigator.sendBeacon(CHAT_API, fd); },
            onUnread: setChatBadge, every: 10000,
        });

        // ---- عکسِ پروفایلِ خودم (هدر و «ویرایش پروفایل») ----
        let myAvatar = null;
        const myName = <?php echo json_encode($_SESSION['full_name'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
        function paintMyAvatar() {
            if (!window.ChatUI) return;
            const h = document.getElementById('me-avatar'), b = document.getElementById('my-avatar-big');
            if (h) h.innerHTML = ChatUI.avatarHtml(myAvatar, myName, {size: 44});
            if (b) b.innerHTML = ChatUI.avatarHtml(myAvatar, myName, {size: 96}) + '<span class="absolute -bottom-1 -left-1 w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center shadow-lg text-xs"><i class="fas fa-camera"></i></span>';
        }
        async function loadMyAvatar() { try { const d = await chatCall('chat_me', {}); if (d.ok) { myAvatar = d.avatar; paintMyAvatar(); } } catch (e) {} }
        async function changeMyAvatar() {
            const r = await ChatUI.pickAvatar({current: myAvatar || '', name: myName});
            if (!r) return;
            let d;
            if (r.file) { const fd = new FormData(); fd.append('avatar', r.file, 'avatar.jpg'); d = await chatUpload('chat_set_avatar', fd); }
            else d = await chatCall('chat_set_avatar', {avatar: r.avatar});
            if (!d.ok) { showToast(d.error || 'ذخیره نشد.', 'error'); return; }
            myAvatar = d.avatar; paintMyAvatar();
            showToast('عکسِ پروفایل ذخیره شد.', 'success');
            if (typeof staffUsersCache !== 'undefined' && staffUsersCache.length) loadStaffUsers();
        }
        loadMyAvatar();

        function switchGoftegoSub(which) {
            ['messenger','tickets','botchats','staffchat','companychat','archive'].forEach(k => {
                const panel = document.getElementById('goftego-panel-' + k);
                if (panel) panel.classList.toggle('hidden', which !== k);
                const btn = document.getElementById('goftego-sub-' + k);
                if (btn) btn.className = 'px-4 py-2 rounded-lg text-xs font-bold ' + (which === k ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500');
            });
            if (which === 'botchats') loadBotChats();
            if (which === 'archive' && window.ChatArchive) ChatArchive.init();
            if (which !== 'messenger') return;
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
            document.getElementById('ticket-chat-body').innerHTML += renderChatBubble(true, '📎 در حال ارسال فایل...', irNowStr());
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
                    <p class="text-[10px] text-slate-400 mt-1">${ROLE_FA[u.role] || u.role} ${u.last_message ? '· ' + u.last_message.slice(0, 30) : ''}</p>
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
            // پیام‌رسان منوی کلیک‌راستِ خودش را دارد
            if (e.target.closest && e.target.closest('.cx-msg, .cx-menu, .cx-th, .cx-body, .cx-head, .cx-selbar')) { ctxMenu.classList.remove('active'); return; }
            
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
            // اندازه‌ی واقعیِ منو (بسته به گزینه‌ها) تا هیچ‌وقت از پایین/کنارِ صفحه بیرون نزند
            const mw = ctxMenu.offsetWidth || 224, mh = ctxMenu.offsetHeight || 260;
            let x = e.clientX, y = e.clientY;
            if (x + mw > window.innerWidth - 8) x = Math.max(8, x - mw);
            if (y + mh > window.innerHeight - 8) y = Math.max(8, window.innerHeight - mh - 8);
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

        /* ===== ثبت دستیِ درخواست کارکنان (یک مرحله: پرسنل + درخواست + مدارک) ===== */
        // سقف‌های تعهد مالی - هم‌خوان با liability_limit_options() در api/_case_helpers.php
        const MC_LIABILITY_OPTIONS = [700000000, 800000000, 1000000000, 2000000000, 2500000000, 3000000000,
                                      4000000000, 5000000000, 6000000000, 7000000000, 8000000000, 9000000000, 10000000000];
        let mcCreated = null;
        let mcFiles = {};          // کلیدِ مدرک => File
        let mcOthers = [];         // [{name, file}]
        let mcCoverageOpts = null; // از سرور (همان فهرستِ ربات)
        let mcSelectedCov = {};

        // هم‌خوان با get_required_docs_v2 در api/_case_helpers.php
        function mcRequiredDocs() {
            const type = document.getElementById('mc-insurance-type').value;
            const own = document.getElementById('mc-ownership').value;
            const prev = document.getElementById('mc-prev-body').value;
            const rel = document.getElementById('mc-relationship').value;
            const d = {};
            if (type === 'BODY' && prev === 'بله') d.prev_body_insurance = 'بیمه بدنه قبل';
            d.identity_doc = 'کارت ملی یا گواهینامه (بیمه‌گذار)';
            if (own === 'سند') d.ownership_doc = 'سند مالکیت';
            else { d.car_card_front = 'کارت ماشین رو'; d.car_card_back = 'کارت ماشین پشت'; }
            if (rel && rel !== 'خودم') {
                d.holder_birth_cert_p1 = 'شناسنامه دارنده معرفی‌نامه - صفحه اول';
                d.holder_birth_cert_relation = 'شناسنامه دارنده معرفی‌نامه - صفحه مربوط به ' + rel;
                d.insured_id_doc = 'کارت ملی یا شناسنامه بیمه‌گذار (نسبت: ' + rel + ')';
            }
            return d;
        }

        // introId: اگر داده شود، درخواست به همان معرفی‌نامه اضافه می‌شود و بخش پرسنل پنهان است
        function openManualCreateModal(introId = null, holderName = '') {
            mcCreated = null; mcFiles = {}; mcOthers = []; mcSelectedCov = {};
            document.getElementById('mc-intro-id').value = introId || '';
            document.getElementById('mc-case-id').value = '';
            ['mc-full-name','mc-national-code','mc-personnel-code','mc-company-name','mc-mobile','mc-letter-date','mc-quota',
             'mc-insured-name','mc-insured-nid','mc-car-value','mc-birth','mc-phone','mc-postal','mc-address','mc-no-claim-years'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('mc-intro-file').value = '';
            document.getElementById('mc-insurance-type').value = 'THIRDPARTY';
            document.getElementById('mc-relationship').value = 'خودم';
            document.getElementById('mc-ownership').value = 'کارت ماشین';
            document.getElementById('mc-prev-body').value = 'خیر';
            document.getElementById('mc-prev-claim').value = 'خیر';
            document.getElementById('mc-car-value-auto').checked = false;
            document.getElementById('mc-liability').innerHTML = '<option value="">انتخاب کنید</option>'
                + MC_LIABILITY_OPTIONS.map(v => `<option value="${v}">${money(v)} ریال</option>`).join('');
            document.getElementById('mc-plate-mount').innerHTML = plateSplitHtml('mc-plate', '');
            document.getElementById('mc-person-section').classList.toggle('hidden', !!introId);
            const sum = document.getElementById('mc-intro-summary');
            sum.classList.toggle('hidden', !introId);
            sum.innerHTML = introId ? `<i class="fas fa-folder-plus ml-1"></i>افزودن درخواستِ تازه به معرفی‌نامه‌ی ${holderName || ('#' + e2p(introId))}` : '';
            document.getElementById('mc-title').textContent = introId ? 'افزودن درخواست به معرفی‌نامه' : 'ثبت دستی درخواست کارکنان';
            document.getElementById('mc-docs-section').classList.add('hidden');
            ['mc-request-section', 'mc-pick-section'].forEach(id => document.getElementById(id).classList.remove('opacity-60', 'pointer-events-none', 'hidden'));
            document.getElementById('mc-submit-btn').classList.remove('hidden');
            document.getElementById('mc-open-case-btn').classList.add('hidden');
            mcRefresh();
            if (!mcCoverageOpts) {
                fetch('api/record_actions.php?action=manual_form_options').then(r => r.json()).then(d => {
                    if (d.ok) { mcCoverageOpts = d.coverages; mcRenderCoverages(); }
                }).catch(() => {});
            } else mcRenderCoverages();
            // پیشنهادِ نام شرکت‌ها
            fetch('api/finance_actions.php?action=bootstrap').then(r => r.json()).then(d => {
                const names = [...new Set((d.companies || []).map(c => c.name))];
                document.getElementById('mc-companies-dl').innerHTML = names.map(n => `<option value="${n}">`).join('');
            }).catch(() => {});
            document.getElementById('manual-create-modal').classList.add('active');
        }

        function closeManualCreateModal() {
            document.getElementById('manual-create-modal').classList.remove('active');
            if (mcCreated) {
                loadRecords();
                if (!document.getElementById('tab-issue-queue')?.classList.contains('hidden') && typeof loadIssueQueue === 'function') loadIssueQueue();
                if (document.getElementById('intro-detail-modal').classList.contains('flex')) {
                    openIntroDetail(mcCreated.intro_id, document.getElementById('intro-detail-name').textContent);
                }
            }
        }

        // با هر تغییرِ نوع بیمه/بیمه‌گذار/مالکیت/سابقه، فیلدها و فهرستِ مدارک همان لحظه عوض می‌شوند
        function mcRefresh() {
            const m = document.getElementById('manual-create-modal');
            const body = document.getElementById('mc-insurance-type').value === 'BODY';
            const rel = document.getElementById('mc-relationship').value;
            const prev = document.getElementById('mc-prev-body').value === 'بله';
            const claim = document.getElementById('mc-prev-claim').value === 'بله';
            m.querySelectorAll('.mc-body-only').forEach(el => el.classList.toggle('hidden', !body));
            m.querySelectorAll('.mc-third-only').forEach(el => el.classList.toggle('hidden', body));
            m.querySelectorAll('.mc-rel-only').forEach(el => el.classList.toggle('hidden', rel === 'خودم'));
            m.querySelectorAll('.mc-prev-only').forEach(el => el.classList.toggle('hidden', !prev));
            m.querySelectorAll('.mc-noclaim-only').forEach(el => el.classList.toggle('hidden', !(prev && !claim)));
            const auto = document.getElementById('mc-car-value-auto').checked;
            const cv = document.getElementById('mc-car-value');
            cv.disabled = auto; cv.classList.toggle('bg-slate-100', auto);
            document.getElementById('mc-insured-who').textContent = rel === 'خودم' ? '(خودِ پرسنل)' : `(${rel})`;
            mcRenderPickList();
        }

        function mcRenderCoverages() {
            const box = document.getElementById('mc-coverages');
            if (!mcCoverageOpts) { box.innerHTML = '<p class="text-[11px] text-slate-400">در حال بارگذاری...</p>'; return; }
            box.innerHTML = Object.entries(mcCoverageOpts).map(([k, o]) => {
                const on = Object.prototype.hasOwnProperty.call(mcSelectedCov, k);
                const tiers = o.tiers ? `<select class="mt-1 border border-slate-300 rounded p-1 text-[11px] bg-white w-full" ${on ? '' : 'disabled'} onchange="mcSelectedCov['${k}']=this.value">
                        ${Object.entries(o.tiers).map(([tk, tl]) => `<option value="${tk}" ${mcSelectedCov[k] === tk ? 'selected' : ''}>${tl}</option>`).join('')}</select>` : '';
                return `<label class="block rounded-lg border ${on ? 'border-blue-300 bg-blue-50' : 'border-slate-200 bg-white'} p-2 text-[11px] cursor-pointer" title="${o.desc}">
                    <span class="flex items-center gap-1.5 font-bold text-slate-700"><input type="checkbox" ${on ? 'checked' : ''} onchange="mcToggleCov('${k}', this.checked)"> ${o.label}</span>${tiers}</label>`;
            }).join('');
        }
        function mcToggleCov(k, on) {
            if (on) mcSelectedCov[k] = mcCoverageOpts[k].tiers ? Object.keys(mcCoverageOpts[k].tiers)[0] : true;
            else delete mcSelectedCov[k];
            mcRenderCoverages();
        }

        function mcRenderPickList() {
            const req = mcRequiredDocs();
            // فایلِ مدرکی که دیگر لازم نیست کنار گذاشته می‌شود
            Object.keys(mcFiles).forEach(k => { if (!req[k]) delete mcFiles[k]; });
            document.getElementById('mc-pick-list').innerHTML = Object.entries(req).map(([key, label]) => {
                const f = mcFiles[key];
                return `<div class="rounded-lg border p-2 text-[11px] ${f ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-white border-slate-200 text-slate-600'}">
                    <div class="font-bold">${f ? '✓' : '○'} ${label}</div>
                    ${f ? `<div class="text-[10px] text-slate-500 truncate" dir="ltr">${f.name}</div>
                           <button type="button" onclick="mcPickFile('${key}', null)" class="text-[10px] text-red-500 underline">حذف</button>` : ''}
                    <label class="inline-block mt-1 text-[10px] font-bold text-white bg-blue-600 hover:bg-blue-700 px-2 py-0.5 rounded cursor-pointer">${f ? 'تغییر فایل' : 'انتخاب فایل'}
                        <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="mcPickFile('${key}', this.files[0])"></label>
                </div>`;
            }).join('');
            document.getElementById('mc-other-list').innerHTML = mcOthers.map((o, i) => `
                <div class="flex flex-wrap items-center gap-1.5">
                    <input type="text" value="${(o.name || '').replace(/"/g, '&quot;')}" oninput="mcOthers[${i}].name=this.value" placeholder="نام مدرک (مثلاً وکالت‌نامه)" class="flex-1 min-w-[140px] border border-slate-300 rounded p-1.5 text-[11px]">
                    <label class="text-[10px] font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 px-2 py-1 rounded cursor-pointer">${o.file ? '✓ ' + o.file.name.slice(0, 18) : 'انتخاب فایل'}
                        <input type="file" class="hidden" onchange="mcOthers[${i}].file=this.files[0]; mcRenderPickList()"></label>
                    <button type="button" onclick="mcOthers.splice(${i},1); mcRenderPickList()" class="text-red-400 hover:text-red-600 text-xs px-1"><i class="fas fa-times"></i></button>
                </div>`).join('') || '<p class="text-[10px] text-slate-400">مدرکِ دیگری لازم نیست؟ خالی بگذارید.</p>';
        }
        function mcPickFile(key, file) { if (file) mcFiles[key] = file; else delete mcFiles[key]; mcRenderPickList(); }
        function mcAddOther() { mcOthers.push({name: '', file: null}); mcRenderPickList(); }

        async function submitManualRequest() {
            const v = id => document.getElementById(id).value.trim();
            const introId = v('mc-intro-id');
            const type = v('mc-insurance-type'), rel = v('mc-relationship');
            const warn = msg => { showToast(msg, 'warning'); return false; };
            if (!introId && (!v('mc-full-name') || !/^\d{10}$/.test(p2e(v('mc-national-code'))))) return warn('نام و کد ملیِ ۱۰ رقمیِ پرسنل را وارد کنید.');
            if (rel !== 'خودم' && (v('mc-insured-name').length < 3 || !/^\d{8,10}$/.test(p2e(v('mc-insured-nid'))))) return warn('نام و کد ملیِ بیمه‌گذار (' + rel + ') را وارد کنید.');
            if (!/^\d{2}ایران - \d{3} \S+ \d{2}$/.test(v('mc-plate'))) return warn('پلاک را کامل وارد کنید (همه‌ی خانه‌ها).');
            if (!/^1[34]\d{2}\D+\d{1,2}\D+\d{1,2}$/.test(p2e(v('mc-birth')))) return warn('تاریخ تولد بیمه‌گذار را درست وارد کنید (مثل ۱۳۷۰/۰۵/۱۲).');
            if (!/^09\d{9}$/.test(p2e(v('mc-phone')))) return warn('موبایل بیمه‌گذار باید ۱۱ رقم و با ۰۹ شروع شود.');
            if (!/^\d{10}$/.test(p2e(v('mc-postal')))) return warn('کد پستی باید ۱۰ رقم باشد.');
            if (v('mc-address').length < 10) return warn('آدرس را کامل‌تر وارد کنید.');
            if (type === 'THIRDPARTY' && !v('mc-liability')) return warn('سقف تعهد مالی را انتخاب کنید.');
            if (type === 'BODY') {
                if (v('mc-prev-body') === 'بله' && v('mc-prev-claim') === 'خیر' && v('mc-no-claim-years') === '') return warn('تعداد سال‌های عدم خسارت را وارد کنید.');
                if (!document.getElementById('mc-car-value-auto').checked && !Number(p2e(v('mc-car-value')).replace(/\D/g, ''))) return warn('ارزش خودرو را وارد کنید یا «محاسبه توسط کارشناس» را بزنید.');
            }
            const othersBad = mcOthers.find(o => o.file && !o.name.trim());
            if (othersBad) return warn('برای «سایر مدارک» نام مدرک را بنویسید.');
            const missing = Object.entries(mcRequiredDocs()).filter(([k]) => !mcFiles[k]).map(([, l]) => l);
            if (missing.length && !(await uiConfirm('مدارک ناقص', `${e2p(missing.length)} مدرک هنوز انتخاب نشده:\n• ${missing.join('\n• ')}\n\nدرخواست ثبت شود و این مدارک را بعداً بارگذاری می‌کنید؟`, {ok: 'بله، ثبت شود'}))) return;

            const fd = new FormData();
            fd.append('action', 'create_manual_request');
            if (introId) fd.append('intro_id', introId);
            else {
                fd.append('full_name', v('mc-full-name')); fd.append('national_code', v('mc-national-code'));
                fd.append('personnel_code', v('mc-personnel-code')); fd.append('company_name', v('mc-company-name'));
                fd.append('mobile', v('mc-mobile')); fd.append('letter_date', v('mc-letter-date')); fd.append('max_quota', v('mc-quota'));
                const f = document.getElementById('mc-intro-file').files[0];
                if (f) fd.append('intro_file', f);
            }
            ['insurance_type:mc-insurance-type', 'relationship:mc-relationship', 'ownership_choice:mc-ownership', 'plate:mc-plate',
             'insured_name:mc-insured-name', 'insured_national_id:mc-insured-nid', 'liability_limit:mc-liability', 'car_value:mc-car-value',
             'prev_body_insurance:mc-prev-body', 'prev_body_claim:mc-prev-claim', 'no_claim_years:mc-no-claim-years',
             'insured_birth_date:mc-birth', 'insured_phone:mc-phone', 'insured_postal_code:mc-postal',
             'insured_address:mc-address'].forEach(pair => { const [k, id] = pair.split(':'); fd.append(k, v(id)); });
            fd.append('car_value_auto', document.getElementById('mc-car-value-auto').checked ? '1' : '');
            fd.append('coverages', JSON.stringify(mcSelectedCov));
            Object.entries(mcFiles).forEach(([k, f]) => fd.append('doc_' + k, f));
            mcOthers.filter(o => o.file).forEach(o => { fd.append('other_files[]', o.file); fd.append('other_names[]', o.name.trim()); });

            const btn = document.getElementById('mc-submit-btn');
            btn.disabled = true; const oldHtml = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>در حال ثبت و بارگذاری مدارک...';
            try {
                const res = await fetch('api/record_actions.php', {method: 'POST', body: fd});
                const data = await res.json();
                if (!data.ok) { showToast(data.error || 'خطا در ثبت.', 'error'); return; }
                mcCreated = data;
                document.getElementById('mc-case-id').value = data.case_id;
                if (data.note) showToast(data.note, 'warning');
                (data.doc_errors || []).forEach(e => showToast(e, 'error'));
                showToast(`درخواست ${data.unique_code} ثبت شد` + (data.uploaded ? ` و ${e2p(data.uploaded)} مدرک بایگانی شد.` : '.'), 'success');
                // اطلاعاتِ ثبت‌شده قفل می‌شود؛ وضعیتِ مدارک (و مدارکِ باقی‌مانده) نمایش داده می‌شود
                document.getElementById('mc-request-section').classList.add('opacity-60', 'pointer-events-none');
                document.getElementById('mc-person-section').classList.add('hidden');
                document.getElementById('mc-pick-section').classList.add('hidden');
                btn.classList.add('hidden');
                document.getElementById('mc-open-case-btn').classList.remove('hidden');
                document.getElementById('mc-docs-section').classList.remove('hidden');
                document.getElementById('mc-case-code').textContent = '(' + data.unique_code + ')';
                document.getElementById('mc-result-note').textContent = data.status === 'ISSUING'
                    ? '✅ همه‌ی مدارک کامل است و درخواست وارد «در حال صدور» شد.'
                    : (type === 'BODY' && !Object.entries(mcRequiredDocs()).some(([k]) => !mcFiles[k])
                        ? 'مدارک کامل است؛ بعد از تاییدِ بازدید سلامت، درخواست وارد «در حال صدور» می‌شود.'
                        : 'مدارکِ باقی‌مانده را همین‌جا بارگذاری کنید.');
                renderMcChecklist();
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
            finally { btn.disabled = false; btn.innerHTML = oldHtml; }
        }

        // چک‌لیستِ مدارکِ لازمِ همین پرونده - هر خانه: تیک اگر هست، ضربدر و دکمه‌ی بارگذاری اگر نیست
        async function renderMcChecklist() {
            const caseId = document.getElementById('mc-case-id').value;
            const box = document.getElementById('mc-docs-list');
            if (!caseId) return;
            box.innerHTML = '<p class="text-[11px] text-slate-400">در حال بارگذاری...</p>';
            try {
                const res = await fetch('api/case_actions.php?action=detail&case_id=' + caseId);
                const data = await res.json();
                if (!data.ok) { box.innerHTML = `<p class="text-[11px] text-red-500">${data.error || 'خطا'}</p>`; return; }
                const docs = data.documents || [];
                box.innerHTML = Object.entries(data.required_docs || {}).map(([key, label]) => {
                    const have = docs.filter(d => d.doc_key === key && d.status !== 'REJECTED');
                    const ok = have.length > 0;
                    return `<div class="rounded-lg border p-2 text-[11px] ${ok ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-600'}">
                        <div class="font-bold">${ok ? '✓' : '✗'} ${label}</div>
                        ${ok ? have.map(d => `<a href="/${encodeFilePath(d.file_path)}" target="_blank" class="underline text-[10px]">مشاهده</a>`).join(' ')
                             : `<label class="inline-block mt-1 text-[10px] font-bold text-white bg-blue-600 hover:bg-blue-700 px-2 py-0.5 rounded cursor-pointer">بارگذاری
                                    <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="mcUploadDoc('${key}', '${label.replace(/'/g, '')}', this)"></label>`}
                    </div>`;
                }).join('') + docs.filter(d => d.doc_key === 'other').map(d => `<div class="rounded-lg border p-2 text-[11px] bg-emerald-50 border-emerald-200 text-emerald-700">
                        <div class="font-bold">✓ ${d.doc_label}</div><a href="/${encodeFilePath(d.file_path)}" target="_blank" class="underline text-[10px]">مشاهده</a></div>`).join('')
                  + (data.case && data.case.insurance_type === 'BODY' && window.VR && VR.openTargetBuilder ? `<div class="rounded-lg border-2 border-violet-200 bg-violet-50 p-2 text-[11px] text-violet-700">
                        <div class="font-bold">گزارش بازدید</div>
                        <button type="button" onclick="openCaseVisitReport(${caseId}, renderMcChecklist)" class="mt-1 text-[10px] font-bold text-white bg-gradient-to-l from-indigo-600 to-violet-600 px-2 py-0.5 rounded"><i class="fas fa-file-circle-plus ml-1"></i>ساخت گزارش بازدید</button></div>` : '')
                  + `<div class="rounded-lg border border-slate-200 bg-white p-2 text-[11px] text-slate-500">
                        <div class="font-bold">سایر مدارک (اختیاری)</div>
                        <input type="text" id="mc-post-other-name" placeholder="نام مدرک" class="mt-1 w-full border border-slate-300 rounded p-1 text-[11px]">
                        <label class="inline-block mt-1 text-[10px] font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 px-2 py-0.5 rounded cursor-pointer">بارگذاری
                            <input type="file" class="hidden" onchange="mcUploadDoc('other', 'سایر مدارک', this)"></label></div>`;
            } catch (e) { box.innerHTML = '<p class="text-[11px] text-red-500">خطا در ارتباط با سرور.</p>'; }
        }

        async function mcUploadDoc(key, label, input) {
            if (!input.files[0]) return;
            const fd = new FormData();
            fd.append('action', 'admin_upload_case_doc');
            fd.append('case_id', document.getElementById('mc-case-id').value);
            fd.append('doc_key', key); fd.append('doc_label', label);
            if (key === 'other') {
                const nm = (document.getElementById('mc-post-other-name') || {}).value || '';
                if (!nm.trim()) { showToast('نام مدرک را بنویسید.', 'warning'); input.value = ''; return; }
                fd.append('other_name', nm.trim()); label = nm.trim();
            }
            fd.append('file', input.files[0]);
            try {
                const res = await fetch('api/case_actions.php', {method: 'POST', body: fd});
                const data = await res.json();
                showToast(data.ok ? `«${label}» بارگذاری و تایید شد.` : (data.error || 'خطا در بارگذاری.'), data.ok ? 'success' : 'error');
            } catch (e) { showToast('خطا در ارتباط با سرور.', 'error'); }
            renderMcChecklist();
        }

        function mcOpenCreatedCase() {
            const id = document.getElementById('mc-case-id').value;
            closeManualCreateModal();
            if (id) openCase(Number(id), 'review');
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
                    showToast(data.webhook ? 'توکن ربات شرکتی ذخیره و ربات به سایت وصل شد' + (data.bot_username ? ` (@${data.bot_username})` : '') + '.'
                                           : 'توکن ذخیره شد، ولی اتصالِ وب‌هوک ممکن نشد؛ توکن را بررسی کنید.', data.webhook ? 'success' : 'warning');
                    tokenInput.value = '';
                    setTimeout(() => location.reload(), 2500);
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

        // ---- حذف اطلاعات: همه / فقط بخش‌های انتخابی / همه به‌جز انتخابی‌ها ----
        let resetMode = 'all', resetSecs = [];
        async function openResetModal() {
            document.getElementById('reset-modal').classList.add('active');
            document.getElementById('reset-password-input').value = '';
            setResetMode('all');
            const box = document.getElementById('reset-sections');
            box.innerHTML = '<p class="text-[11px] text-slate-400 text-center py-3">در حال بارگذاری...</p>';
            try {
                const res = await fetch('api/record_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'reset_sections'})});
                const d = await res.json();
                if (!d.ok) { box.innerHTML = `<p class="text-[11px] text-red-500 text-center">${d.error || 'خطا'}</p>`; return; }
                resetSecs = d.sections;
                box.innerHTML = resetSecs.map(x => `<label class="flex items-start gap-2 border rounded-xl p-2.5 cursor-pointer hover:bg-slate-50" data-rsec="${x.key}">
                    <input type="checkbox" value="${x.key}" class="mt-1" onchange="renderResetState()">
                    <span class="flex-1"><span class="flex justify-between gap-2"><b class="text-xs text-slate-700">${x.label}</b><span class="text-[10px] text-slate-400 whitespace-nowrap">${e2pNum(x.rows)} ردیف</span></span>
                    <span class="block text-[10px] text-slate-400 leading-5 mt-0.5">${x.desc}</span></span></label>`).join('');
                renderResetState();
            } catch (e) { box.innerHTML = '<p class="text-[11px] text-red-500 text-center">خطا در ارتباط با سرور</p>'; }
        }
        function setResetMode(m) {
            resetMode = m;
            document.querySelectorAll('#reset-mode-seg button').forEach(b => b.className = 'rounded-lg py-2 ' + (b.dataset.mode === m ? 'bg-white shadow text-red-600' : 'text-slate-500'));
            renderResetState();
        }
        function resetPicked() { return [...document.querySelectorAll('#reset-sections input:checked')].map(i => i.value); }
        // بخش‌هایی که واقعاً پاک می‌شوند
        function resetTargets() {
            const all = resetSecs.map(x => x.key), picked = resetPicked();
            return resetMode === 'all' ? all : resetMode === 'only' ? picked : all.filter(k => !picked.includes(k));
        }
        function renderResetState() {
            const hint = document.getElementById('reset-mode-hint');
            const inputs = document.querySelectorAll('#reset-sections input');
            inputs.forEach(i => { i.disabled = resetMode === 'all'; if (resetMode === 'all') i.checked = false; });
            hint.textContent = resetMode === 'all' ? 'همه‌ی بخش‌های زیر (و کلِ بایگانی و پوشه‌های موقت) پاک می‌شود.'
                             : resetMode === 'only' ? 'فقط بخش‌هایی که تیک می‌زنید پاک می‌شود؛ بقیه می‌ماند.'
                             : 'بخش‌هایی که تیک می‌زنید می‌ماند؛ همه‌ی بخش‌های دیگر پاک می‌شود.';
            const t = resetTargets();
            document.querySelectorAll('#reset-sections [data-rsec]').forEach(l => {
                // قرمز = پاک می‌شود، سبز = می‌ماند (با استایلِ مستقیم تا به کلاس‌های ساخته‌شده‌ی Tailwind وابسته نباشد)
                const del = t.includes(l.dataset.rsec);
                l.style.borderColor = del ? '#fca5a5' : '#6ee7b7';
                l.style.background = del ? '#fef2f2' : '#ecfdf5';
            });
            // وابستگی‌ها: مالی به پرونده‌ها و شرکت‌ها وصل است
            const warn = [];
            if (!t.includes('finance') && (t.includes('personnel') || t.includes('companies'))) warn.push('«مالی» می‌ماند ولی پرونده‌ها/شرکت‌هایی که اقساط و صورتحساب‌ها به آن‌ها وصل‌اند پاک می‌شوند؛ ردیف‌های مالیِ آن‌ها بی‌صاحب می‌مانند.');
            if (!t.includes('visit_reports') && (t.includes('personnel') || t.includes('companies'))) warn.push('گزارش‌های بازدید می‌مانند ولی اتصالشان به درخواست‌های پاک‌شده برداشته می‌شود.');
            const w = document.getElementById('reset-dep-warn');
            w.innerHTML = warn.map(x => '<i class="fas fa-triangle-exclamation ml-1"></i>' + x).join('<br>'); w.classList.toggle('hidden', !warn.length);
            const btn = document.getElementById('reset-go-btn');
            const full = t.length === resetSecs.length;
            btn.textContent = !t.length ? 'بخشی برای حذف انتخاب نشده' : full ? 'بله، همه‌چیز پاک شود' : `بله، ${e2pNum(t.length)} بخش پاک شود`;
            btn.disabled = !t.length; btn.classList.toggle('opacity-50', !t.length);
        }
        async function executeResetAll() {
            const password = document.getElementById('reset-password-input').value.trim();
            if (!password) { showToast('لطفاً رمز تایید را وارد کنید.', 'error'); return; }
            if (resetMode !== 'all' && !resetPicked().length) { showToast('حداقل یک بخش را تیک بزنید.', 'error'); return; }
            const busyBtn = document.getElementById('reset-go-btn');
            const busyHtml = busyBtn ? busyBtn.innerHTML : '';
            if (busyBtn) { busyBtn.disabled = true; busyBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin ml-1"></i> در حال بکاپ‌گیری و حذف...'; }
            try {
                const res = await fetch('api/record_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'reset_all', password, mode: resetMode, sections: resetPicked()})
                });
                const data = await res.json();
                if (data.ok) {
                    document.getElementById('reset-modal').classList.remove('active');
                    document.getElementById('reset-password-input').value = '';
                    // اعلان‌های ذخیره‌شده در همین مرورگر (زنگوله در حالتِ محلی) هم پاک شود
                    try { Object.keys(localStorage).filter(k => k.startsWith('notif:')).forEach(k => localStorage.removeItem(k)); } catch (e) {}
                    showAlert(data.full ? 'پاکسازی کامل انجام شد' : 'حذف اطلاعات انجام شد', data.message, 'success', () => location.reload());
                } else if (data.error && data.error.indexOf('بکاپ') !== -1) {
                    showAlert('حذف انجام نشد', data.error, 'error');
                } else {
                    showToast(data.error || 'خطا در بازنشانی سیستم', 'error');
                }
            } catch (e) {
                showToast('خطا در برقراری ارتباط با سرور', 'error');
            }
            if (busyBtn) { busyBtn.disabled = false; busyBtn.innerHTML = busyHtml; }
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
            // اگر CDNِ tsParticles نیامد (شبکه‌ی ضعیف)، بی‌صدا رد شو؛ خطایش نباید چیزی را خراب کند
            if (!window.tsParticles || !document.getElementById('tsparticles')) return;
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