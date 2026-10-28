<?php
// صفحه‌ی پنل بیش از ۱ مگابایت است؛ فشرده (gzip) فرستاده می‌شود تا با چند کاربرِ هم‌زمان سریع‌تر باز شود
if (extension_loaded('zlib') && !ini_get('zlib.output_compression') && !headers_sent()) @ini_set('zlib.output_compression', '1');
session_start();
require 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
// شاخص‌های دیتابیس برای سرعتِ صفحه‌ها (یک بار، در پس‌زمینه بعد از فرستادنِ صفحه؛ api/_perf_indexes.php)
if (($_SESSION['role'] ?? '') === 'ADMIN') { require_once __DIR__ . '/api/_perf_indexes.php'; perf_indexes_maybe($pdo); }
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
require_once __DIR__ . '/api/_brand.php';   // لوگو و فاوآیکنِ تنظیم‌شده
$permBoot = perm_page_boot($pdo);
$realRole = perm_real_role();
// ثبت دستیِ معرفی‌نامه و درخواستِ کارکنان: مدیر کل، یا کاربری که در دسترسی‌های سفارشی «صدورِ بیمه‌نامه‌ی کارکنان: ویرایش»،
// «پرونده‌ها و معرفی‌نامه‌ها: ثبت» یا «در حال صدور: ویرایش» دارد (سرور هم همین را بررسی می‌کند - api/record_actions.php)
$canManualPersonnel = ($_SESSION['role'] ?? '') === 'ADMIN'
    || (!empty($permBoot['custom']) && (in_array('edit', (array)($permBoot['p']['cases'] ?? []), true) || in_array('create', (array)($permBoot['p']['records'] ?? []), true)
        || in_array('edit', (array)($permBoot['p']['issue-queue'] ?? []), true)));
// نامِ کاربر با عنوانِ «آقای / خانم» (تنظیم در «کاربران»)
require_once __DIR__ . '/api/_name_honor.php';
$hdrName = honor_name($pdo, 'S', intval($_SESSION['user_id'] ?? 0), (string)($_SESSION['full_name'] ?? ''));
// زیرِ نام در هدر: فقط «سمتی» که برای کاربر نوشته شده (پروفایل)، نه نقشِ سیستمیِ سایت
$hdrTitle = '';
try { $hdrSt = $pdo->prepare("SELECT prof_title FROM users WHERE id = ?"); $hdrSt->execute([intval($_SESSION['user_id'] ?? 0)]); $hdrTitle = trim((string)$hdrSt->fetchColumn()); } catch (Throwable $e) {}
// نقش «همکار شرکت‌ها»: فقط بخش شرکت‌ها و گزارش مالی مربوطه را می‌بیند
$isLiaison = ($_SESSION['role'] ?? '') === 'COMPANY_LIAISON';
$canSeeCompanies = $isLiaison || ($_SESSION['role'] ?? '') === 'ADMIN';
// نقش «کاربر پارسیان»: فقط ساخت/دیدن/ویرایشِ گزارش‌های بازدیدِ خودش (هیچ بخشِ دیگری از پنل را نمی‌بیند)
$isParsian = ($_SESSION['role'] ?? '') === 'PARSIAN';
// نقش «کاربر بیمه عمر»: فقط بخشِ بیمه عمر (داشبورد، بیمه‌نامه‌ها و اقساط، ورودِ اکسل، بایگانیِ عمر) و گفتگو
$isLife = ($_SESSION['role'] ?? '') === 'LIFE';
// «میز کار من» (داشبوردِ شخصی، home.js): صفحه‌ی شروعِ همه‌ی نقش‌ها؛ مدیر کل همچنان با «داشبوردِ سامانه» شروع می‌کند
$homeFirst = (function_exists('perm_real_role') ? perm_real_role() : ($_SESSION['role'] ?? '')) !== 'ADMIN';   // نقشِ واقعی (دسترسیِ سفارشی موقتاً «مدیر» می‌شود)
$homeNavHtml = '<a href="#" onclick="switchTab(\'home\')" id="nav-home" class="nav-item hover-target transition-colors block lg:inline py-2 lg:py-0' . ($homeFirst ? ' text-blue-600' : '') . '"><i class="fas fa-mug-hot ml-1"></i> میز کار من</a>';
// منوی «بیمه عمر»: مدیر کل، کاربر بیمه عمر و هر کسی که دسترسیِ سفارشی دارد (منوهای بی‌اجازه در مرورگر پنهان می‌شوند)
$lifeAccess = $isLife || ($_SESSION['role'] ?? '') === 'ADMIN' || !empty($permBoot['custom']);
// منوی «بازاریابی و فروش»: مدیر کل و دسترسی‌های سفارشی (منوهای بی‌اجازه در مرورگر پنهان می‌شوند)
$mkAccess = ($_SESSION['role'] ?? '') === 'ADMIN' || !empty($permBoot['custom']);
$mkMenuHtml = !$mkAccess ? '' : '
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-bullhorn ml-1"></i> بازاریابی
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab(\'mk-dash\')" id="nav-mk-dash" class="nav-item menu-link font-black text-orange-700"><i class="fas fa-chart-line ml-2"></i> داشبورد فروش <small class="text-[10px] text-slate-400 font-bold mr-1">کلی و هر بازاریاب</small></a>
                        <a href="#" onclick="switchTab(\'mk-sales\')" id="nav-mk-sales" class="nav-item menu-link"><i class="fas fa-receipt ml-2"></i> فروش‌ها <small class="text-[10px] text-slate-400 font-bold mr-1">ثبت، محاسبه‌گر، اکسل</small></a>
                        <a href="#" onclick="switchTab(\'mk-people\')" id="nav-mk-people" class="nav-item menu-link"><i class="fas fa-user-tie ml-2"></i> بازاریابان و کارشناسانِ تماس</a>
                        <div class="menu-sep"></div>
                        <a href="#" onclick="switchTab(\'mk-settings\')" id="nav-mk-settings" class="nav-item menu-link"><i class="fas fa-sliders ml-2"></i> تنظیمات بازاریابی <small class="text-[10px] text-slate-400 font-bold mr-1">کارمزد، پاداش، سطح، حقوق</small></a>
                    </div>
                </div>
';
// «استعلامات»: مدیر کل، یا دسترسیِ سفارشی به یکی از بخش‌های استعلام (تکی/گروهی/تاریخچه)
$inqAccess = ($_SESSION['role'] ?? '') === 'ADMIN' && empty($permBoot['custom']);
if (!empty($permBoot['custom'])) foreach ((array)($permBoot['p'] ?? []) as $__k => $__ops) if (strpos((string)$__k, 'inq-') === 0 && in_array('view', (array)$__ops, true)) $inqAccess = true;
// «هوشیکس X ai»: مدیر کل، یا کاربری که در تنظیماتِ هوشیکس دسترسی دارد (api/_hooshix.php)
$hxAccess = ($_SESSION['role'] ?? '') === 'ADMIN';
if (!$hxAccess) { try { require_once __DIR__ . '/api/_hooshix.php'; $__hc = hx_cfg($pdo); $hxAccess = $__hc['enabled'] && hx_user_state($pdo, intval($_SESSION['user_id']), false, $__hc)['allowed']; } catch (Throwable $e) { $hxAccess = false; } }
// «پرونده‌ها و بردرو» (خودروها، پرسنل، بردروی پاسارگاد): مدیر کل و دسترسی‌های سفارشی (لینکِ بی‌اجازه را permCan پنهان می‌کند)
$regAccess = ($_SESSION['role'] ?? '') === 'ADMIN' || !empty($permBoot['custom']);
// منوی «اتوماسیون نامه‌ها»: مدیر کل و دسترسی‌های سفارشی
$ltAccess = ($_SESSION['role'] ?? '') === 'ADMIN' || !empty($permBoot['custom']);
$ltMenuHtml = !$ltAccess ? '' : '
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-envelope-open-text ml-1"></i> نامه‌ها
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab(\'lt-box\')" id="nav-lt-box" class="nav-item menu-link font-black text-indigo-700"><i class="fas fa-inbox ml-2"></i> کارتابل نامه‌ها <small class="text-[10px] text-slate-400 font-bold mr-1">صادره، وارده، ارجاع</small></a>
                        <a href="#" onclick="switchTab(\'lt-box\'); window.Letters && Letters.compose({}); return false;" class="nav-item menu-link"><i class="fas fa-pen-nib ml-2"></i> نامه‌ی جدید <small class="text-[10px] text-slate-400 font-bold mr-1">با سربرگ و شماره‌ی خودکار</small></a>
                        <div class="menu-sep"></div>
                        <a href="#" onclick="switchTab(\'lt-settings\')" id="nav-lt-settings" class="nav-item menu-link"><i class="fas fa-sliders ml-2"></i> تنظیمات نامه‌ها <small class="text-[10px] text-slate-400 font-bold mr-1">شماره، سربرگ، قالب، امضا</small></a>
                    </div>
                </div>
';
// «مالیِ درمان تکمیلی»: مدیر کل و دسترسی‌های سفارشی
$hlAccess = ($_SESSION['role'] ?? '') === 'ADMIN' || !empty($permBoot['custom']);
$lifeMenuHtml = !$lifeAccess ? '' : '
                <div class="menu-group">
                    <button type="button" class="menu-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-heart-pulse ml-1"></i> بیمه عمر
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                        <span id="life-nav-badge" class="hidden bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full mr-1"></span>
                    </button>
                    <div class="menu-panel">
                        <a href="#" onclick="switchTab(\'life-dash\')" id="nav-life-dash" class="nav-item menu-link font-black text-cyan-700"><i class="fas fa-chart-pie ml-2"></i> داشبورد بیمه عمر <small class="text-[10px] text-slate-400 font-bold mr-1">وصول، معوق، فروش</small></a>
                        <a href="#" onclick="switchTab(\'life-policies\')" id="nav-life-policies" class="nav-item menu-link"><i class="fas fa-file-medical ml-2"></i> بیمه‌نامه‌ها و اقساط <small class="text-[10px] text-slate-400 font-bold mr-1">پرداخت، تماس، رسید</small></a>
                        <a href="#" onclick="switchTab(\'life-import\')" id="nav-life-import" class="nav-item menu-link"><i class="fas fa-file-import ml-2"></i> ورود از اکسل <small class="text-[10px] text-slate-400 font-bold mr-1">گزارشِ اقساطِ بیمه‌گر</small></a>
                        <a href="#" onclick="switchTab(\'life-archive\')" id="nav-life-archive" class="nav-item menu-link"><i class="fas fa-box-archive ml-2"></i> بایگانی بیمه عمر</a>
                        <div class="menu-sep"></div>
                        <a href="#" onclick="switchTab(\'life-settings\')" id="nav-life-settings" class="nav-item menu-link"><i class="fas fa-sliders ml-2"></i> تنظیمات بیمه عمر <small class="text-[10px] text-slate-400 font-bold mr-1">ستون‌ها، قالب رسید</small></a>
                    </div>
                </div>
';
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
                        <a href="#" onclick="switchTab(\'my-work\')" id="nav-my-work" class="nav-item menu-link font-black text-indigo-700"><i class="fas fa-calendar-check ml-2"></i> کارکرد من <small class="text-[10px] text-slate-400 font-bold mr-1">حضور، شرح کار، مرخصی</small></a>
                        <a href="#" onclick="switchTab(\'my-tasks\')" id="nav-my-tasks" class="nav-item menu-link"><i class="fas fa-list-check ml-2"></i> کارهای من <small class="text-[10px] text-slate-400 font-bold mr-1">کارهایی که مدیر تعریف کرده</small></a>
                        <a href="#" onclick="switchTab(\'tools\')" id="nav-tools" class="nav-item menu-link" style="display:none"><i class="fas fa-toolbox ml-2"></i> ابزارها <small class="text-[10px] text-slate-400 font-bold mr-1">عکس، PDF، تاریخِ قسط، اقساط</small></a>'
    . ((($_SESSION['role'] ?? '') === 'ADMIN') ? '
                        <a href="#" onclick="switchTab(\'staff-tasks\')" id="nav-staff-tasks" class="nav-item menu-link"><i class="fas fa-users-gear ml-2"></i> کارهای پرسنل <small class="text-[10px] text-slate-400 font-bold mr-1">تعریفِ کار، مهلت، پیگیری</small></a>
                        <a href="#" onclick="switchTab(\'staff-work\')" id="nav-staff-work" class="nav-item menu-link"><i class="fas fa-users-viewfinder ml-2"></i> کارکرد پرسنل <small class="text-[10px] text-slate-400 font-bold mr-1">همه، تأییدِ مرخصی، تنظیمات</small></a>
                        <a href="#" onclick="switchTab(\'service-report\')" id="nav-service-report" class="nav-item menu-link"><i class="fas fa-van-shuttle ml-2"></i> گزارش سرویس‌ها <small class="text-[10px] text-slate-400 font-bold mr-1">رفت‌وآمدِ هر نفر، تعریفِ سرویس، رسید</small></a>' : '') . '
                    </div>
                </div>
';
// نوارِ زبانه‌های «مرکز صدور»: سه بخشِ در حال صدور، صادره‌ها و صدور گروهی در یک صفحه
function issuance_nav($active, $isLiaison) {
    $items = [];
    if (!$isLiaison) $items[] = ['issue-queue', 'fa-list-check', 'در حال صدور', 'iss-n-queue'];
    $items[] = ['issued-list', 'fa-file-circle-check', 'صادره‌ها', 'iss-n-issued'];
    $items[] = ['endorsements', 'fa-file-pen', 'الحاقیه‌ها', 'iss-n-endo'];
    if (!$isLiaison) $items[] = ['issue-group', 'fa-layer-group', 'صدور گروهی', 'iss-n-group'];
    if (!$isLiaison) $items[] = ['import-archive', 'fa-box-archive', 'بایگانی وارداتی', 'iss-n-import'];
    if (!$isLiaison) $items[] = ['pasargad-recon', 'fa-scale-unbalanced', 'مغایرت با پاسارگاد', 'iss-n-recon'];
    if (($_SESSION['role'] ?? '') === 'ADMIN') $items[] = ['legacy-import', 'fa-boxes-packing', 'ورودِ بایگانی قبلی', 'iss-n-legacy'];
    if (!$isLiaison) $items[] = ['renewals', 'fa-bell', 'اعلام تمدید', 'iss-n-renew'];
    if (($_SESSION['role'] ?? '') === 'ADMIN') $items[] = ['premium-inquiries', 'fa-calculator', 'استعلام‌ها', 'iss-n-pqi'];
    $h = '<div class="iss-hub"><div class="iss-hub-title"><span class="iss-hub-ic"><i class="fas fa-stamp"></i></span><div><b>مرکز صدور</b><small>همه‌ی ردیف‌های شرکتی و کارکنان، از درخواست تا صادره</small></div></div><div class="iss-nav">';
    foreach ($items as [$tab, $ic, $label, $badge]) {
        $h .= '<button type="button" onclick="switchTab(\'' . $tab . '\')" class="' . ($active === $tab ? 'on' : '') . '"><i class="fas ' . $ic . '"></i>' . $label . ' <span class="iss-n ' . $badge . '"></span></button>';
    }
    // تنظیمِ کلیِ «فیش‌های بیمه‌نامه وقتی با اقساطِ قرارداد فرق داشت» (کارکنان و شرکتی)
    if (!$isLiaison) $h .= '<button type="button" onclick="window.FinContracts && FinContracts.slipSettings()" title="فیش‌های بیمه‌نامه وقتی با اقساطِ قرارداد فرق داشت، و بیشترین تعهدِ مالیِ ثالث برای کارکنان و شرکت‌ها"><i class="fas fa-sliders"></i>تنظیماتِ صدور</button>';
    return $h . '</div></div>';
}
// نوارِ زبانه‌های «مرکز مالی»: همه‌ی بخش‌های مالی در یک جا
function finance_nav($active, $canSeeCompanies = true) {
    $items = [['fin-dashboard', 'fa-chart-pie', 'داشبورد'], ['fin-installments', 'fa-list-ol', 'مرکز اقساط'], ['fin-payments', 'fa-arrow-right-arrow-left', 'دریافت و پرداخت'],
              ['fin-tracking', 'fa-magnifying-glass-location', 'پیگیری'], ['fin-invoices', 'fa-file-invoice', 'صورتحساب‌ها'], ['fin-reconcile', 'fa-scale-balanced', 'مغایرت‌گیری']];
    if ($canSeeCompanies) $items[] = ['companies-finance', 'fa-sack-dollar', 'گزارش شرکت‌ها'];
    $items[] = ['fin-contracts', 'fa-file-contract', 'قراردادها'];
    if (($_SESSION['role'] ?? '') === 'ADMIN') $items[] = ['fin-settings', 'fa-sliders', 'تنظیمات'];
    $h = '<div class="iss-hub fin-hub"><div class="iss-hub-title"><span class="iss-hub-ic"><i class="fas fa-calculator"></i></span><div><b>مرکز مالی</b><small>اقساطِ کارکنان و شرکتی، دریافت از بیمه‌گذار، پرداخت به بیمه‌گر، چک‌ها و صورتحساب</small></div></div><div class="iss-nav">';
    foreach ($items as [$tab, $ic, $label]) $h .= '<button type="button" onclick="switchTab(\'' . $tab . '\')" class="' . ($active === $tab ? 'on' : '') . '"><i class="fas ' . $ic . '"></i>' . $label . '</button>';
    return $h . '</div></div>';
}
// نامِ فارسیِ نقش‌ها - هیچ‌جای پنل نقش با اسم انگلیسی نشان داده نمی‌شود
function role_fa($role) {
    return ['ADMIN' => 'مدیر کل', 'CEO' => 'مدیر عامل شرکت', 'OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی', 'COMPANY_LIAISON' => 'کارمند بیمه با ما', 'PARSIAN' => 'کارمند بیمه با ما (پارسیان)', 'LIFE' => 'کاربر بیمه عمر'][$role] ?? $role;
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
    <?php require_once __DIR__ . '/api/_assets.php'; echo asset_js(['cursor-pref.js', 'maint-watch.js']); ?>
    <title>پورتال مدیریت | بیمه با ما</title>
    <?php echo brand_favicon_tag($pdo); ?>
    <!-- CSS و فونت‌ها داخلیِ خودِ سایت (vendor-css؛ هیچ CDNی) تا با قطعیِ اینترنتِ بین‌الملل ظاهر به هم نریزد -->
    <?php echo asset_css(['vendor-css/tailwind.css']); /* نسخه‌ی خودکار (پس از build-css.sh دیگر لازم نیست ?v= را دستی عوض کنید) */ ?>
    <link href="vendor-css/fontawesome/css/all.min.css?v=1" rel="stylesheet">
    
    <style>
        /* فاصله‌ی یکسان بینِ کارت‌ها و جدول‌های پشتِ‌سرِهم در بدنه‌ی صفحه‌های ماژولی (عمر، بازاریابی، درمان، نامه‌ها) - مثلِ بقیه‌ی صفحه‌ها */
        :is(.lf, .mk, .hl, .lt) :is([data-body], #lfd-body):not([contenteditable]) { display: flex; flex-direction: column; gap: 16px; }
        :is(.lf, .mk, .hl, .lt) [data-list] > :is(.hl-card, .mk-card, .lf-card, .lt-card) + :is(.hl-card, .mk-card, .lf-card, .lt-card) { margin-top: 16px; }
        /* لوگوی سربرگ: هم‌اندازه‌ی «مربع + بیمه با ما» (ارتفاعِ ۳۶ پیکسل) */
        .brand-logo { display: block; height: 36px; width: auto; max-width: 190px; object-fit: contain; }
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
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Regular.woff2') format('woff2'); font-weight: normal; font-display: swap; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Bold.woff2') format('woff2'); font-weight: bold; font-display: swap; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Black.woff2') format('woff2'); font-weight: 900; font-display: swap; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Light.woff2') format('woff2'); font-weight: 300; font-display: swap; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Medium.woff2') format('woff2'); font-weight: 500; font-display: swap; }
        
        body { font-family: 'Vazir', Tahoma, sans-serif; overflow-x: hidden; color: #334155; margin: 0; }
        button, input, select, textarea { font-family: inherit; }
        
        .hero-bg { position: fixed; inset: 0; z-index: -2; background-image: url('Image/asli1.jpg'); background-size: cover; background-position: center; }
        /* این لایه زیرِ یک پرده‌ی سفیدِ ۸۵٪ است، پس بلورش عملاً دیده نمی‌شد و فقط هزینه داشت */
        .hero-bg::after { content: ""; position: absolute; inset: 0; background: rgba(241, 245, 249, 0.85); z-index: -1; }
        #tsparticles { position: fixed; inset: 0; z-index: -1; pointer-events: none; }

        /* نشانگرِ اختصاصی فقط روی دستگاهی که موسِ واقعی دارد روشن می‌شود؛ روی موبایل و
           تبلت هم بی‌معنی است و هم بی‌خود هزینه دارد (و cursor:none آزاردهنده است). */
        .cursor-dot, .cursor-outline { display: none; }
        @media (hover: hover) and (pointer: fine) {
            html:not(.cur-native) * { cursor: none !important; }
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
        /* کلاس‌های اسکرولِ خودِ پنجره (overflow-y-auto و …) بر overflow:hiddenِ بالا مقدم باشند؛ وگرنه پنجره‌های بلند اسکرول نمی‌خورند */
        .modal-content.overflow-y-auto { overflow-y: auto; overscroll-behavior: contain; } .modal-content.overflow-auto { overflow: auto; overscroll-behavior: contain; }

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
        .menu-group.menu-hush .menu-panel, .menu-group.menu-hush .menu-subpanel { display: none !important; }
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
        @media (max-width: 640px) { .su-seg { width: 100%; overflow-x: auto; } .su-seg button { white-space: nowrap; flex: 1 0 auto; padding: 6px 10px; } }
        .su-chip { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px; margin-block: 2px; border-radius: 999px; background: #f1f5f9; color: #475569; }
        .su-chip.ad { background: #eef2ff; color: #4338ca; }
        .su-chip.co { background: #ecfdf5; color: #047857; }
        /* کاربران › کارکنانِ ربات بله */
        .sb-hero { display: flex; align-items: center; gap: 14px; color: #fff; border-radius: 18px; padding: 16px 18px; position: relative; overflow: hidden;
                   background: radial-gradient(circle at 12% 120%, rgba(56,189,248,.35), transparent 45%), linear-gradient(120deg, #0f172a, #1e3a8a 55%, #0e7490); box-shadow: 0 10px 30px -12px rgba(30,58,138,.55); }
        .sb-hero-ic { width: 48px; height: 48px; border-radius: 15px; background: rgba(255,255,255,.14); display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .sb-rules { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .sb-rules span { font-size: 10.5px; font-weight: 700; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18); border-radius: 999px; padding: 3px 10px; }
        .sb-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; }
        @media (max-width: 900px) { .sb-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .sb-stats .sb-stat:first-child { grid-column: span 2; } }
        .sb-stat { --c: #2563eb; position: relative; text-align: right; background: #fff; border: 1px solid #e2e8f0; border-radius: 15px; padding: 10px 14px; transition: all .18s; overflow: hidden; }
        .sb-stat::before { content: ''; position: absolute; inset-inline-start: 0; top: 0; bottom: 0; width: 4px; background: var(--c); opacity: .85; }
        .sb-stat i { position: absolute; left: 12px; top: 12px; color: var(--c); opacity: .22; font-size: 22px; }
        .sb-stat b { display: block; font-size: 21px; font-weight: 900; color: #0f172a; line-height: 1.3; }
        .sb-stat small { font-size: 11px; font-weight: 700; color: #64748b; }
        .sb-stat:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -10px rgba(15,23,42,.35); }
        .sb-stat.on { border-color: var(--c); box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 18%, transparent); }
        .sb-stat.g { --c: #16a34a; } .sb-stat.s { --c: #64748b; } .sb-stat.i { --c: #4f46e5; } .sb-stat.a { --c: #d97706; }
        .sb-adm { display: flex; align-items: center; gap: 10px; background: linear-gradient(90deg, #fffbeb, #fff); border: 1px solid #fde68a; border-radius: 14px; padding: 10px 12px; font-size: 12px; }
        .sb-adm-ic { width: 36px; height: 36px; border-radius: 11px; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .sb-st { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 800; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }
        .sb-st.in { background: #dcfce7; color: #15803d; } .sb-st.out { background: #f1f5f9; color: #64748b; } .sb-st.ad { background: #fef3c7; color: #b45309; }
        .sb-lock { display: inline-flex; align-items: center; gap: 5px; font-family: ui-monospace, monospace; font-size: 11px; font-weight: 800; background: #eef2ff; color: #4338ca; border-radius: 8px; padding: 2px 8px; }
        .sb-lock.off { font-family: inherit; background: #f8fafc; color: #94a3b8; font-weight: 700; }
        .sb-cnt { display: inline-flex; gap: 4px; }
        .sb-cnt span { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; border-radius: 8px; padding: 2px 7px; background: #f1f5f9; color: #475569; }
        .sb-cnt span.ok { background: #ecfdf5; color: #047857; }
        .sb-act button { font-size: 11px; font-weight: 800; border-radius: 9px; padding: 5px 9px; margin-inline-start: 4px; transition: background .15s; }
        .sb-act .c { background: #eff6ff; color: #1d4ed8; } .sb-act .c:hover { background: #dbeafe; }
        .sb-act .o { background: #fff7ed; color: #c2410c; } .sb-act .o:hover { background: #ffedd5; }
        .sb-act .u { background: #f5f3ff; color: #6d28d9; } .sb-act .u:hover { background: #ede9fe; }
        @media (max-width: 640px) {   /* موبایل: هر کارمند یک کارت */
            .sb-table thead { display: none; }
            .sb-table, .sb-table tbody, .sb-table tr, .sb-table td { display: block; }
            .sb-table tr { border: 1px solid #e2e8f0 !important; border-radius: 14px; margin: 10px; padding: 4px 2px; background: #fff; }
            .sb-table td { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 6px 12px !important; text-align: left; }
            .sb-table td[data-l]::before { content: attr(data-l); font-size: 10.5px; font-weight: 700; color: #94a3b8; text-align: right; flex-shrink: 0; }
            .sb-table td:first-child { justify-content: flex-start; text-align: right; border-bottom: 1px dashed #e2e8f0; padding-bottom: 10px !important; }
            .sb-table td.sb-act { justify-content: flex-start; flex-wrap: wrap; gap: 6px; padding-top: 10px !important; }
            .sb-table td.sb-act:empty { display: none; }
            .sb-table td.sb-act button { margin: 0; }
            .sb-table td[colspan] { display: block; text-align: center; }
        }
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
        .cit-ws { width: min(1500px, 97vw); max-width: none; height: 93vh; max-height: 93vh; display: flex; flex-direction: column; overflow: hidden; }
        .cit-top { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #eef2f7; }
        .cit-nb { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border-radius: 11px; background: #f1f5f9; color: #334155; font-weight: 800; font-size: 12px; }
        .cit-nb:hover:not(:disabled) { background: #e2e8f0; } .cit-nb:disabled { opacity: .4; cursor: default; }
        .cit-body { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(340px, 1fr); }
        .cit-view { position: relative; min-height: 0; background: #e2e8f0; border-left: 1px solid #e2e8f0; display: flex; flex-direction: column; }
        .cit-pv { flex: 1; min-height: 0; overflow: auto; display: flex; align-items: flex-start; justify-content: center; }
        .cit-pv iframe { width: 100%; height: 100%; border: 0; background: #fff; }
        .cit-pv img { transform-origin: center center; transition: transform .2s; background: #fff; box-shadow: 0 4px 20px -8px rgba(0,0,0,.35); margin: 12px auto; max-width: none; }
        .cit-tools { position: absolute; top: 10px; left: 10px; z-index: 2; display: flex; gap: 4px; background: rgba(15,23,42,.6); padding: 4px; border-radius: 12px; }
        .cit-tools button, .cit-tools a { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; color: #fff; border-radius: 8px; font-size: 12px; }
        .cit-tools button:hover, .cit-tools a:hover { background: rgba(255,255,255,.18); }
        .cit-form { min-height: 0; overflow-y: auto; padding: 14px 18px 8px; }
        @media (max-width: 900px) { .cit-body { grid-template-columns: 1fr; grid-template-rows: 44vh 1fr; } .cit-view { border-left: 0; border-bottom: 1px solid #e2e8f0; } }
        .checklist-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(158px, 1fr)); gap: 7px; align-items: stretch; }
        /* خانه‌های چک‌لیست: عنوان می‌شکند و دکمه‌ها زیرِ هم در عرضِ خانه می‌نشینند (روی هم نمی‌افتند) */
        .ck-chip { border-radius: 12px; padding: 7px 8px; font-size: 10px; line-height: 1.6; display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .ck-chip .ck-t { font-weight: 800; display: flex; align-items: flex-start; gap: 4px; word-break: break-word; }
        .ck-chip .ck-links a { word-break: break-word; }
        .up-stack { display: flex; flex-direction: column; gap: 4px; margin-top: auto; padding-top: 3px; }
        .up-row { display: flex; gap: 4px; flex-wrap: wrap; }
        .up-sel { width: 100%; font-size: 10px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 3px 6px; background: #fff; color: #334155; }
        .up-btn { display: inline-flex; align-items: center; justify-content: center; gap: 5px; flex: 1 1 auto; font-size: 10.5px; font-weight: 800; color: #fff; padding: 5px 10px;
                  border-radius: 9px; border: 0; cursor: pointer; white-space: nowrap; background: linear-gradient(135deg, #0d9488, #0891b2); box-shadow: 0 3px 8px -4px rgba(8,145,178,.7); transition: transform .12s, box-shadow .12s; }
        .up-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -6px rgba(8,145,178,.8); }
        .up-btn.soft { background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; box-shadow: none; }
        .up-btn.violet { background: linear-gradient(135deg, #4f46e5, #7c3aed); box-shadow: 0 3px 8px -4px rgba(99,102,241,.7); }
        .up-in { width: 100%; font-size: 10px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 3px 6px; background: #fff; }
        /* ستونِ چک‌لیست باید بیشترِ عرضِ جدول را بگیرد تا خانه‌هایش در یک سطر کنار هم
           جا شوند؛ بقیه‌ی ستون‌ها باریک و whitespace-nowrap هستند. با درصدِ ثابت
           نوشته شده (نه کلاس‌های Tailwind) تا مستقل از CDN هم درست کار کند. */
        .rows-table { width: 100%; }
        .creq-scopes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; max-width: 720px; }
        .creq-scopes button { text-align: right; border-radius: 16px; padding: 10px 14px; border: 2px solid #e2e8f0; background: #fff; color: #475569; font-size: 12.5px; font-weight: 900; transition: .15s; }
        .creq-scopes button small { display: block; font-size: 10px; font-weight: 600; color: #94a3b8; margin-top: 2px; }
        .creq-scopes button b { display: inline-block; min-width: 22px; text-align: center; font-size: 10.5px; background: #f1f5f9; border-radius: 999px; padding: 0 6px; margin-right: 4px; }
        .creq-scopes button.on { border-color: transparent; background: linear-gradient(120deg, #1d4ed8, #0e7490); color: #fff; box-shadow: 0 10px 24px -14px #1d4ed8; }
        .creq-scopes button.on small { color: rgba(255,255,255,.8); }
        .creq-scopes button.on b { background: rgba(255,255,255,.22); }
        .preq-hero { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 18px; border-radius: 22px; color: #fff;
                     background: linear-gradient(120deg, #4338ca, #7c3aed 50%, #db2777); box-shadow: 0 14px 34px -16px rgba(124,58,237,.6); }
        .rows-table .col-checklist { width: 34%; min-width: 230px; }
        /* ردیف‌های جمع‌شونده‌ی درخواست (هر بار فقط یکی باز) */
        .cq-sum { cursor: pointer; transition: background .15s; }
        .cq-sum:hover { background: #f8fafc; }
        .cq-sum.on { background: linear-gradient(90deg, #ecfeff, #eef2ff); }
        .cq-sum td { vertical-align: middle; }
        .cq-chev { width: 28px; height: 28px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; background: #f1f5f9; color: #475569; font-size: 11px; transition: transform .2s, background .2s; }
        .cq-chev:hover { background: #e2e8f0; }
        .cq-sum.on .cq-chev { transform: rotate(180deg); background: #0e7490; color: #fff; }
        .cq-pill { display: inline-flex; align-items: center; gap: 2px; font-size: 9.5px; font-weight: 800; padding: 1px 6px; border-radius: 999px; border: 1px solid; white-space: nowrap; line-height: 1.6; }
        .cq-pill.ok { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .cq-pill.no { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        /* ردیفی که مدیر کل با وجودِ بیمه‌نامه‌ی فعال ساخته (تکراریِ تأییدشده): زرد */
        .cq-pill.cq-pill-dupack { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        tr.cq-sum.cq-dupack > td { background: #fffbeb !important; }
        tr.cq-sum.cq-dupack > td:first-child { box-shadow: inset -3px 0 0 #f59e0b; }
        /* پیش‌نمایشِ ورودِ ردیف‌ها: ردیفِ دارای بیمه‌نامه‌ی فعال */
        tr.cli-dup-tr > td { background: #fff1f2; }
        .cli-dup { margin-top: 3px; white-space: normal; min-width: 190px; font-size: 9.5px; color: #9f1239; line-height: 1.6; }
        .cli-dup-ok { display: flex; align-items: center; gap: 4px; margin-top: 2px; color: #92400e; font-weight: 800; cursor: pointer; }
        .cli-dup-ok input { accent-color: #d97706; }
        /* پنجره‌ی «بیمه‌نامه‌ی فعال هست» هنگامِ ساختِ ردیف */
        .dupc-ov { position: fixed; inset: 0; z-index: 1000001; background: rgba(15,23,42,.6); display: flex; align-items: center; justify-content: center; padding: 14px; }
        .dupc-box { width: min(720px, 100%); max-height: 90vh; overflow: auto; background: #f8fafc; border-radius: 22px; box-shadow: 0 30px 70px -20px rgba(0,0,0,.5); padding-bottom: 12px; }
        .dupc-h { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px; background: linear-gradient(120deg, #9f1239, #e11d48); color: #fff; border-radius: 22px 22px 0 0; position: sticky; top: 0; z-index: 1; }
        .dupc-h > i { font-size: 20px; margin-top: 2px; }
        .dupc-h b { display: block; font-size: 14px; font-weight: 900; }
        .dupc-h small { display: block; font-size: 11px; opacity: .9; margin-top: 2px; }
        .dupc-list { padding: 12px 16px 6px; }
        .dupc-row { background: #fff; border: 1px solid #fecdd3; border-radius: 14px; padding: 8px 10px; margin-bottom: 8px; }
        .dupc-lab { font-size: 12px; font-weight: 900; color: #334155; }
        .dupc-f { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; padding: 10px 16px 0; }
        .dupc-b { font-size: 12px; font-weight: 800; border-radius: 12px; padding: 8px 14px; background: #fff; border: 1px solid #e2e8f0; color: #475569; }
        .dupc-b.ok { background: #0d9488; border-color: #0d9488; color: #fff; }
        .dupc-b.warn { background: #f59e0b; border-color: #f59e0b; color: #fff; }
        .cq-pill.op { background: #f8fafc; color: #94a3b8; border-color: #e2e8f0; }
        .cq-pill.in { background: #ecfeff; color: #0e7490; border-color: #a5f3fc; }
        .cq-det > td { background: linear-gradient(180deg, #f8fafc, #fff); border-bottom: 2px solid #a5f3fc; }
        .cq-det-in { display: grid; grid-template-columns: minmax(220px, 300px) 1fr; gap: 12px; padding: 10px 12px; animation: cqIn .18s ease-out; }
        .cq-det-side { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .cq-det-main { min-width: 0; }
        @media (max-width: 900px) { .cq-det-in { grid-template-columns: 1fr; } }
        @keyframes cqIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
        .ck-auto { display: inline-block; font-size: 8.5px; font-weight: 900; background: #ccfbf1; color: #0f766e; border-radius: 6px; padding: 0 4px; margin-right: 3px; }
        .ck-auto-x { display: inline-flex; align-items: center; cursor: pointer; color: #0f766e; font-size: 9.5px; margin-right: 4px; }
        .ck-auto-x:hover { color: #be123c; }
        .cq-hist { margin-top: 10px; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 8px; background: #fff; }
        .cq-hist-i { font-size: 10px; display: flex; flex-direction: column; border: 1px solid #e2e8f0; border-radius: 9px; padding: 4px 7px; background: #f8fafc; }
        .cq-hist-i.me { border-color: #99f6e4; background: #f0fdfa; }
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
        /* موبایل: زبانه‌های مرکز صدور/مالی در یک ردیفِ کشیدنی (به‌جای چند ردیف که نصفِ صفحه را می‌گرفت) */
        @media (max-width: 640px) {
            .iss-hub { padding: 12px; gap: 10px; border-radius: 18px; }
            .iss-hub-title b { font-size: 16px; }
            .iss-hub-title small { font-size: 10.5px; }
            .iss-hub-ic { width: 38px; height: 38px; font-size: 16px; border-radius: 12px; }
            .iss-nav { flex-wrap: nowrap; overflow-x: auto; width: 100%; scrollbar-width: none; -webkit-overflow-scrolling: touch; scroll-snap-type: x proximity; }
            .iss-nav::-webkit-scrollbar { display: none; }
            .iss-nav button { white-space: nowrap; flex-shrink: 0; padding: 8px 12px; font-size: 12px; scroll-snap-align: center; }
        }
        .fh-stages button { font-size: 12px; font-weight: 800; padding: 8px 14px; border-radius: 12px; background: #f1f5f9; color: #475569; transition: .15s; }
        .fh-stages button:hover { background: #e2e8f0; }
        .fh-stages button.on { background: #1e1b4b; color: #fff; box-shadow: 0 4px 12px -4px rgba(30,27,75,.5); }
        .fh-stages .fh-n { font-size: 10px; background: rgba(148,163,184,.3); border-radius: 999px; padding: 1px 7px; margin-right: 4px; }
        .fh-bar { position: sticky; top: 8px; z-index: 20; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 14px; border: 1px solid #c7d2fe; background: rgba(255,255,255,.96); backdrop-filter: blur(4px); }
        .fh-table thead tr { background: #eef2ff; color: #3730a3; font-weight: 800; }
        .fh-prog { height: 4px; border-radius: 999px; background: #f1f5f9; overflow: hidden; margin-top: 3px; }
        .fh-prog span { display: block; height: 100%; border-radius: 999px; }
        .fh-overdue td:first-child { box-shadow: inset -3px 0 0 #ef4444; }
        .fh-views { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .fh-views button { display: flex; align-items: center; gap: 10px; text-align: right; padding: 10px 14px; border-radius: 16px; border: 2px solid #e2e8f0; background: #fff; transition: .15s; }
        .fh-views button:hover { border-color: #c7d2fe; }
        .fh-views button.on { border-color: #4f46e5; background: linear-gradient(120deg, #eef2ff, #f0f9ff); box-shadow: 0 8px 20px -14px #4f46e5; }
        .fh-views .v-ic { width: 36px; height: 36px; border-radius: 12px; flex: none; display: flex; align-items: center; justify-content: center; background: #f1f5f9; color: #475569; }
        .fh-views button.on .v-ic { background: #4f46e5; color: #fff; }
        .fh-views b { display: block; font-size: 12.5px; color: #1e1b4b; }
        .fh-views small { display: block; font-size: 10.5px; color: #64748b; }
        .fh-grp-row { cursor: pointer; }
        .fh-chip { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 800; border-radius: 999px; padding: 2px 7px; }
        @media (max-width: 640px) { .fh-views { grid-template-columns: 1fr; } }
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

        /* اعلان‌ها: گوشه‌ی پایینِ سمتِ راستِ سایت، روی هم انباشته، هرکدام با ضربدر یا بعد از ۱۰ ثانیه می‌رود */
        #notif-stack { position: fixed; bottom: 20px; right: 20px; z-index: 9999990; display: flex; flex-direction: column; gap: 10px;
                       width: min(400px, calc(100vw - 32px)); pointer-events: none; }
        .notif-card { background: #fff; border: 1px solid #e2e8f0; border-right: 5px solid #3b82f6; border-radius: 18px; display: flex; align-items: flex-start; gap: 12px;
                      padding: 14px 16px; box-shadow: 0 18px 40px -14px rgba(15,23,42,.35); cursor: pointer; pointer-events: auto;
                      opacity: 0; transform: translateX(40px) scale(.96); transition: opacity .3s, transform .35s cubic-bezier(.2,.9,.3,1.2); }
        .notif-card.show { opacity: 1; transform: none; }
        .notif-card .nc-ic { width: 40px; height: 40px; border-radius: 14px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px;
                             background: linear-gradient(135deg, var(--c), color-mix(in srgb, var(--c) 60%, #7c3aed)); box-shadow: 0 8px 16px -8px var(--c); }
        .notif-card .nc-t { font-weight: 900; font-size: 13.5px; color: #0f172a; margin-bottom: 2px; }
        .notif-card .nc-b { font-size: 12.5px; color: #475569; line-height: 1.8; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
        .su-inq { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; color: #6d28d9; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 999px; padding: 2px 8px; }
        .su-inq.full { color: #be123c; background: #fff1f2; border-color: #fecdd3; }
        .su-dev { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 999px; padding: 2px 8px; }
        .su-dev:hover { background: #ccfbf1; }
        .su-dev.over { color: #be123c; background: #fff1f2; border-color: #fecdd3; }
        .dev-row { display: flex; align-items: center; gap: 10px; border: 1px solid #e2e8f0; border-radius: 14px; padding: 10px 12px; background: #fff; }
        .dev-row.dead { opacity: .6; background: #f8fafc; }
        .dev-ic { width: 36px; height: 36px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #e0e7ff, #ccfbf1); color: #3730a3; flex-shrink: 0; }
        .dev-end { border: 0; background: #fff1f2; color: #be123c; font-size: 11px; font-weight: 800; border-radius: 10px; padding: 6px 10px; white-space: nowrap; }
        .dev-end:hover { background: #ffe4e6; }
        .dev-max { display: flex; align-items: center; gap: 12px; background: linear-gradient(120deg, #eef2ff, #f0fdfa); border-radius: 16px; padding: 12px 14px; }
        .dev-step { display: flex; align-items: center; gap: 4px; background: #fff; border: 1px solid #c7d2fe; border-radius: 12px; padding: 3px; }
        .dev-step button { width: 30px; height: 30px; border: 0; border-radius: 9px; background: #eef2ff; color: #3730a3; font-weight: 900; font-size: 16px; }
        .dev-step b { min-width: 28px; text-align: center; font-size: 16px; color: #1e1b4b; }
        .dev-note { background: #fefce8; border: 1px solid #fde68a; color: #854d0e; border-radius: 14px; padding: 10px 12px; font-size: 12px; font-weight: 700; }
        .notif-card .nc-x { border: 0; background: transparent; color: #94a3b8; width: 24px; height: 24px; border-radius: 8px; flex-shrink: 0; }
        .notif-card .nc-x:hover { background: #f1f5f9; color: #475569; }
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
        /* ---- «بیمه با ما»: منوی بزرگ به تفکیکِ رشته‌ی بیمه ---- */
        .bm-trigger { background: linear-gradient(120deg, #2563eb, #7c3aed 55%, #db2777); color: #fff !important; padding: 7px 13px !important; border-radius: 999px; box-shadow: 0 8px 18px -10px rgba(124,58,237,.8); }
        .bm-trigger:hover, .bm-mega.open .bm-trigger { filter: brightness(1.08); color: #fff !important; }
        .bm-panel .bm-top { display: flex; align-items: baseline; gap: 10px; padding: 4px 8px 10px; flex-wrap: wrap; }
        .bm-panel .bm-top b { font-size: 12.5px; font-weight: 900; color: #1e293b; } .bm-panel .bm-top small { font-size: 10.5px; color: #94a3b8; font-weight: 700; }
        .bm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
        .bm-sec { border: 1px solid #eef2f7; border-radius: 18px; padding: 8px; background: linear-gradient(180deg, color-mix(in srgb, var(--c1) 6%, #fff), #fff 60%); display: flex; flex-direction: column; }
        .bm-head { display: flex; align-items: center; gap: 10px; padding: 4px 4px 8px; border-bottom: 1px dashed color-mix(in srgb, var(--c1) 30%, #e2e8f0); margin-bottom: 4px; }
        .bm-ic { width: 38px; height: 38px; border-radius: 13px; background: linear-gradient(135deg, var(--c1), var(--c2)); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; box-shadow: 0 8px 16px -10px var(--c1); }
        .bm-head b { display: block; font-size: 13px; font-weight: 900; color: #0f172a; } .bm-head small { display: block; font-size: 10px; color: #64748b; font-weight: 700; }
        .bm-sec .menu-link { white-space: normal; }
        .bm-sec .menu-link .bm-l { display: flex; flex-direction: column; line-height: 1.35; } .bm-sec .menu-link .bm-l small { font-size: 9.5px; color: #94a3b8; font-weight: 600; }
        .bm-sec .menu-link:hover > i:first-child, .bm-sec .menu-link.active-sub > i:first-child { background: linear-gradient(135deg, var(--c1), var(--c2)); color: #fff; }
        .bm-sec .menu-link.active-sub { background: color-mix(in srgb, var(--c1) 8%, #fff); }
        .bm-set { margin-top: auto; padding-top: 4px; border-top: 1px dashed #e2e8f0; }
        .bm-grp { padding: 2px 0 4px; } .bm-grp + .bm-grp { border-top: 1px dashed color-mix(in srgb, var(--c1) 18%, #eef2f7); padding-top: 6px; }
        .bm-gh { display: flex; align-items: center; gap: 6px; font-size: 10.5px; font-weight: 900; color: color-mix(in srgb, var(--c1) 75%, #0f172a); padding: 4px 6px 2px; }
        .bm-gh i { font-size: 10px; opacity: .8; }
        .bm-search { margin-right: auto; border: 1px solid #e2e8f0; border-radius: 999px; padding: 6px 12px; font-size: 11.5px; font-family: inherit; width: min(300px, 100%); background: #f8fafc; }
        .bm-search:focus { outline: 2px solid #c7d2fe; background: #fff; }
        .bm-sec.bm-hide, .bm-grp.bm-hide, .bm-sec .menu-link.bm-hide { display: none !important; }
        @media (min-width: 1024px) {
            .bm-sec.bm-wide { grid-column: span 2; }
            .bm-sec.bm-wide .bm-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 10px; align-items: start; }
            .bm-sec.bm-wide .bm-grp + .bm-grp { border-top: 0; }
            .bm-sec.bm-wide .bm-grp { border-right: 1px dashed color-mix(in srgb, var(--c1) 18%, #eef2f7); padding-right: 6px; }
        }
        .bm-set .menu-link > i:first-child { background: #f8fafc; }
        @media (min-width: 1024px) {
            #main-nav .menu-group.bm-mega .menu-trigger.bm-trigger, #main-nav .menu-group.bm-mega.open .menu-trigger.bm-trigger, #main-nav .menu-group.bm-mega:hover .menu-trigger.bm-trigger {
                background: linear-gradient(120deg, #2563eb, #7c3aed 55%, #db2777); color: #fff !important; box-shadow: 0 8px 18px -10px rgba(124,58,237,.8); }
            .bm-mega > .bm-panel { position: fixed; top: 78px; left: 0; right: 0; margin: 0 auto; width: min(1120px, 94vw); padding: 12px; border-radius: 22px; max-height: calc(100vh - 100px); overflow: auto; }
            .bm-mega > .bm-panel::before { top: -14px; height: 14px; }
            .bm-mega > .bm-panel::after { display: none; }
        }
        /* دسکتاپ: همه‌ی بخش‌ها بدونِ اسکرول در یک نگاه (ستون‌های بیشتر، گزینه‌های کوتاه‌تر؛ توضیحِ کوچک هر گزینه در راهنمای موس) */
        @media (min-width: 1024px) {
            .bm-mega > .bm-panel { width: min(1320px, 97vw); top: 74px; padding: 10px 12px; max-height: calc(100vh - 86px); }
            .bm-panel .bm-top { padding: 0 6px 8px; }
            .bm-grid { grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 8px; }
            .bm-set { display: flex; flex-wrap: wrap; gap: 2px 4px; }
            .bm-set .menu-link { flex: 1 1 auto; }
            .bm-sec { padding: 6px; border-radius: 16px; }
            .bm-sec.bm-wide { grid-column: span 3; }
            .bm-sec.bm-wide .bm-links { display: block; column-count: 3; column-gap: 8px; }
            .bm-sec.bm-wide .bm-grp { break-inside: avoid; border-right: 0; padding-right: 0; }
            .bm-head { padding: 2px 2px 6px; margin-bottom: 2px; gap: 8px; }
            .bm-ic { width: 32px; height: 32px; font-size: 13px; border-radius: 11px; }
            .bm-head b { font-size: 12.5px; } .bm-head small { font-size: 9.5px; }
            .bm-sec .menu-link { padding: 4px 6px; gap: 8px; font-size: 12px; border-radius: 9px; }
            .bm-sec .menu-link > i:first-child { width: 24px; height: 24px; font-size: 11px; border-radius: 8px; }
            .bm-sec .menu-link .bm-l small { display: none; }
            .bm-gh { padding: 2px 4px 1px; font-size: 10px; }
            .bm-grp { padding: 1px 0 3px; }
        }
        @media (max-width: 1023px) {
            .bm-trigger { margin: 4px 0; }
            .bm-grid { grid-template-columns: minmax(0, 1fr); }
        }
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
            .menu-panel::after { content: ''; pointer-events: none; position: absolute; top: -6px; right: 24px; width: 11px; height: 11px; background: #fff;
                                 border-left: 1px solid #eef2f7; border-top: 1px solid #eef2f7; transform: rotate(45deg); }
            .menu-group.open .menu-panel, .menu-group:focus-within .menu-panel { animation: menuIn .18s ease-out; }
            /* پنجره‌های کم‌عرض‌تر: فاصله‌ها کمتر، نامِ برند و روزِ هفته‌ی ساعت پنهان تا سربرگ در یک خط بماند */
            @media (max-width: 1279px) {
                #main-nav > a.nav-item, #main-nav .menu-trigger { padding: 6px 7px !important; gap: 4px; }
                .brand-name { display: none !important; }
                .brand-logo { max-width: 130px !important; }
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
        .mi-head::after { content: ''; pointer-events: none; position: absolute; width: 200px; height: 200px; border-radius: 50%; background: rgba(255,255,255,.1); left: -60px; top: -90px; }
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
        .rec-card::after { content: ''; pointer-events: none; position: absolute; width: 170px; height: 170px; border-radius: 50%; background: rgba(255,255,255,.12); left: -50px; top: -70px; }
        .rec-card::before { content: ''; pointer-events: none; position: absolute; width: 110px; height: 110px; border-radius: 50%; background: rgba(255,255,255,.08); left: 40px; bottom: -60px; }
        .rec-ic { position: absolute; left: 16px; top: 16px; font-size: 26px; opacity: .85; z-index: 1; }
        .rec-t { font-size: 12px; font-weight: 800; opacity: .9; position: relative; z-index: 1; }
        .rec-v { font-size: 26px; font-weight: 900; margin-top: 6px; position: relative; z-index: 1; letter-spacing: -.5px; }
        .rec-v small { font-size: 12px; font-weight: 700; opacity: .85; margin-right: 4px; }
        .rec-s { font-size: 11px; opacity: .88; margin-top: 4px; position: relative; z-index: 1; min-height: 16px; }
        @keyframes recIn { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: none; } }
        /* اعلان‌های داشبورد (انقضای نزدیک / کارکنانِ صادرنشده) */
        /* کارت‌های آماریِ داشبورد */
        .ds-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-top: 16px; }
        @media (max-width: 1100px) { .ds-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 560px) { .ds-grid { grid-template-columns: 1fr; } }
        .ds-card { position: relative; overflow: hidden; border-radius: 22px; padding: 16px 18px 14px; background: rgba(255,255,255,.92); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
                   border: 1px solid rgba(226,232,240,.9); box-shadow: 0 14px 34px -24px rgba(15,23,42,.45); transition: transform .18s, box-shadow .18s; display: flex; flex-direction: column; gap: 4px; min-height: 148px; }
        .ds-card::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 4px; background: linear-gradient(90deg, var(--a), var(--b)); }
        .ds-card::after { content: ''; position: absolute; width: 150px; height: 150px; border-radius: 50%; left: -50px; bottom: -70px; background: radial-gradient(circle, color-mix(in srgb, var(--b) 28%, transparent), transparent 70%); pointer-events: none; }
        .ds-card:hover { transform: translateY(-3px); box-shadow: 0 22px 40px -24px color-mix(in srgb, var(--a) 60%, #0f172a); }
        .ds-click { cursor: pointer; }
        .ds-top { display: flex; align-items: center; gap: 10px; }
        .ds-ic { width: 38px; height: 38px; border-radius: 13px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 15px; flex-shrink: 0;
                 background: linear-gradient(135deg, var(--a), var(--b)); box-shadow: 0 8px 18px -8px var(--a); }
        .ds-lb { font-size: 12px; font-weight: 900; color: #475569; line-height: 1.5; flex: 1; }
        .ds-go { color: #cbd5e1; font-size: 12px; transition: transform .15s, color .15s; }
        .ds-card:hover .ds-go { color: var(--a); transform: translateX(-3px); }
        .ds-n { font-size: 30px; font-weight: 900; color: #0f172a; line-height: 1.25; margin-top: 6px; letter-spacing: -.5px; }
        .ds-n small { font-size: 12px; font-weight: 800; color: #94a3b8; letter-spacing: 0; }
        .ds-n.ds-money { font-size: 23px; }
        .ds-sub { font-size: 10.8px; color: #64748b; font-weight: 600; line-height: 1.7; }
        .ds-bar { height: 6px; border-radius: 9px; background: #f1f5f9; overflow: hidden; margin-top: auto; }
        .ds-bar > i { display: block; height: 100%; width: 0; border-radius: 9px; background: linear-gradient(90deg, var(--a), var(--b)); transition: width .8s cubic-bezier(.2,.8,.2,1); }
        .ds-split { display: flex; height: 7px; border-radius: 9px; overflow: hidden; background: #f1f5f9; margin-top: 4px; gap: 2px; }
        .ds-split > i { display: block; height: 100%; transition: width .8s; }
        .ds-mini { display: flex; justify-content: space-between; gap: 6px; font-size: 10.5px; color: #64748b; font-weight: 700; margin-top: 4px; }
        .ds-mini b { color: #0f172a; }
        .ds-leg { display: inline-flex; align-items: center; gap: 3px; margin-left: 8px; }
        .ds-leg i { width: 8px; height: 8px; border-radius: 3px; display: inline-block; }
        #da-filters { border-radius: 18px; background: rgba(255,255,255,.92); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); }
        #da-filters select, #da-filters input { background: #fff; border-color: #e2e8f0; min-height: 34px; border-radius: 11px; }
        #da-filters select { max-width: 220px; }
        .da-head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; color: #fff; background: linear-gradient(135deg, var(--c1), var(--c2)); }
        .da-head h2 { font-size: 14px; font-weight: 900; }
        .da-head p { font-size: 10.5px; opacity: .88; margin-top: 2px; }
        .da-ic { width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
        .da-badge { min-width: 36px; height: 30px; padding: 0 10px; border-radius: 999px; background: rgba(255,255,255,.95); color: #0f172a; font-weight: 900; font-size: 13px; display: flex; align-items: center; justify-content: center; }
        .da-list { max-height: 400px; overflow-y: auto; }
        .da-row { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background .15s; position: relative; }
        .da-row:hover { background: #f8fafc; }
        .da-row::before { content: ''; pointer-events: none; position: absolute; right: 0; top: 8px; bottom: 8px; width: 4px; border-radius: 4px 0 0 4px; background: var(--u, #cbd5e1); }
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
            /* سربرگ هیچ‌وقت از عرضِ گوشی بیرون نزند: دکمه‌ی منو همیشه دیده و لمس‌شدنی است */
            header.glass-header { padding-top: 8px !important; padding-bottom: 8px !important; gap: 8px; }
            header.glass-header > div:first-child { min-width: 0; flex-shrink: 1; gap: 8px !important; }
            header.glass-header > div:last-child { flex-shrink: 0; gap: 6px !important; }
            #mobile-menu-btn { width: 40px; height: 40px; flex-shrink: 0; border-radius: 13px; background: #f1f5f9; color: #334155; font-size: 17px;
                               display: inline-flex; align-items: center; justify-content: center; }
            #mobile-menu-btn:active { transform: scale(.94); }
            #hdr-brand { min-width: 0; flex-shrink: 1; }
            .brand-logo { max-width: 92px !important; height: 30px; }
            #me-avatar, #me-avatar > span { width: 38px !important; height: 38px !important; }
        }
        #m-quick { display: grid; grid-template-columns: repeat(auto-fit, minmax(88px, 1fr)); gap: 8px; margin-bottom: 10px; }
        #m-quick button { display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 10px 6px; border-radius: 16px; border: 0; color: #fff; font: 800 11.5px inherit;
                          font-family: inherit; background: linear-gradient(135deg, var(--c1), var(--c2)); box-shadow: 0 10px 20px -12px var(--c1); }
        #m-quick button i { font-size: 17px; }
        #m-quick button:active { transform: scale(.96); }
        @media (min-width: 1024px) { #m-quick { display: none !important; } }
        /* موبایل: سرِ صفحه‌ها (عنوان + دکمه‌ها) اگر جا نشد دکمه‌ها به خطِ بعد بروند، نه روی عنوان */
        @media (max-width: 640px) {
            .tab-content > .flex.justify-between { flex-wrap: wrap; gap: 10px; }
            .tab-content > .flex.justify-between > div:last-child:not(:first-child) { flex-wrap: wrap; }
            .tab-content > .flex.justify-between h1 { font-size: 20px; line-height: 1.6; }
            /* کارت‌ها و فیلدهای داخلِ گرید از عرضِ صفحه بیرون نزنند (مثلاً منوی کشوییِ با گزینه‌ی بلند) */
            .tab-content .grid > * { min-width: 0; }
            /* فیلترهای داشبوردِ مالی: دو ستونِ مرتب به‌جای ردیف‌های نامنظم */
            .fd-bar { display: grid !important; grid-template-columns: 1fr 1fr; gap: 10px !important; }
            .fd-bar > div { min-width: 0; }
            .fd-bar select, .fd-bar input { width: 100% !important; min-width: 0 !important; }
            .fd-bar > div:nth-last-child(-n+2) { grid-column: span 2; }
            .fd-bar > div:last-child { margin-right: 0 !important; justify-content: flex-start; }
            .tab-content select, .tab-content input:not([type="checkbox"]):not([type="radio"]), .tab-content textarea { max-width: 100%; }
        }
        /* گوشی‌های معمولی (تا ۴۳۰ پیکسل): نام/نقش/ساعت جا نمی‌شود؛ فقط عکسِ پروفایل (که پروفایل را باز می‌کند) */
        @media (max-width: 430px) {
            header.glass-header > div:last-child > div:last-child { border-right: 0 !important; padding-right: 0 !important; }
            header.glass-header > div:last-child > div:last-child > .flex-col { display: none !important; }
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
    .ii-w.pf-red,.ii-ro.pf-red{border-color:#f43f5e !important;background:#fff1f2 !important;box-shadow:0 0 0 2px #ffe4e6}
    .ii-w.pf-yel,.ii-ro.pf-yel{border-color:#f59e0b !important;background:#fffbeb !important}
    .pf-hint{font-size:9.5px;font-weight:700;margin-top:2px;line-height:1.5;word-break:break-word}
    .prop-drop{border:2px dashed #a5b4fc;border-radius:14px;padding:10px;background:#eef2ff;font-size:12px}
    .prop-drop:hover{background:#e0e7ff}
    .prop-card{border:1px solid #c7d2fe;border-radius:16px;padding:12px;background:linear-gradient(160deg,#eef2ff,#fff 60%)}
    .prop-chip{display:inline-flex;gap:4px;align-items:center;font-size:10.5px;background:#fff;border:1px solid #e0e7ff;border-radius:999px;padding:2px 8px}
    .prop-chip i{font-style:normal;color:#94a3b8}.prop-chip b{color:#1e293b}
    .iic{border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 4px 18px -10px rgba(15,23,42,.25)}
    .iic-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 14px;color:#fff;background:linear-gradient(120deg,#0f766e,#4f46e5)}
    .iic-pill{display:inline-flex;align-items:center;font-size:10.5px;font-weight:800;border-radius:999px;padding:3px 10px;max-width:100%}
    .iic-sec{border:1px solid #f1f5f9;border-radius:14px;padding:9px 10px;background:#fbfdff}
    .iic-sec h5{font-size:11px;font-weight:900;margin-bottom:6px}
    .iic-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px}
    .iic-cell{background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:5px 8px;min-width:0}
    .iic-cell span{display:block;font-size:9.5px;color:#94a3b8;font-weight:700}
    .iic-cell b{font-size:11.5px;color:#0f172a;word-break:break-word}
    .iic-wide{grid-column:1/-1}
    .iic-cov{font-size:10.5px;background:#ecfdf5;border:1px solid #d1fae5;color:#065f46;border-radius:999px;padding:1px 8px}
</style>
<script>window.__SRV = {s: <?php echo (int)round(microtime(true) * 1000); ?>, c: Date.now()};</script>
<?php echo asset_js(['iran-time.js']); ?>
<?php echo asset_js(['jdate-picker.js'], 'defer'); ?>
<script>window.IDLE_GUARD = {alive: 'api/alive.php', login: 'index.php?idle=1'};</script>
<?php echo asset_js(['idle-guard.js']); ?>
<?php echo asset_css(['plate.css', 'ui-scroll.css']); ?>
</head>
<body class="h-screen flex flex-col">
<?php require_once __DIR__ . '/api/_loader.php'; site_loader($pdo); ?>

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
            <div class="ctx-item text-gray-300 hover-target" onmousedown="event.preventDefault()" onclick="ctxExecute('copy')"><i class="far fa-copy w-4 text-center"></i> کپی (Copy)</div>
            <div class="ctx-item text-gray-300 hover-target" onmousedown="event.preventDefault()" onclick="ctxExecute('cut')"><i class="fas fa-cut w-4 text-center"></i> برش (Cut)</div>
            <div class="ctx-item text-gray-300 hover-target" onmousedown="event.preventDefault()" onclick="ctxExecute('paste')"><i class="fas fa-paste w-4 text-center"></i> چسباندن (Paste)</div>
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
            <div id="hdr-brand" class="flex items-center gap-2.5 font-black text-lg lg:text-xl hover-target bg-gradient-to-l from-blue-600 to-indigo-600 bg-clip-text text-transparent">
                <?php if ($brandLogo = brand_url($pdo, 'logo')): ?>
                <img src="<?php echo htmlspecialchars($brandLogo); ?>" alt="بیمه با ما" class="brand-logo">
                <?php else: ?>
                <span class="flex items-center justify-center w-9 h-9 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 shadow-lg shadow-blue-500/30 text-white text-base"><i class="fas fa-shield-halved"></i></span>
                <span class="brand-name hidden sm:inline tracking-tight whitespace-nowrap" style="white-space:nowrap">بیمه با ما</span>
                <?php endif; ?>
            </div>
            <!-- منوی اصلی: پنج بخش؛ کارهای مرتبط داخلِ زیرمنوهای درختی (menu-sub) کنارِ هم آمده‌اند -->
            <nav id="main-nav" class="hidden lg:flex flex-col lg:flex-row gap-1 lg:gap-5 font-bold text-xs text-slate-500 absolute lg:static top-full right-0 left-0 lg:top-auto bg-white lg:bg-transparent shadow-xl lg:shadow-none p-4 lg:p-0 z-50 max-h-[75vh] overflow-y-auto lg:overflow-visible rounded-b-2xl lg:rounded-none">

                <?php if ($isParsian) echo $homeNavHtml . $vrMenuHtml . $chatNavHtml . $personnelMenuHtml; ?>
                <?php if ($isLife) echo $homeNavHtml . $lifeMenuHtml . $chatNavHtml . $personnelMenuHtml; ?>
                <?php if (!$isParsian && !$isLife): ?>

                <?php echo $homeNavHtml; ?>
                <?php if (!$isLiaison): ?>
                <a href="#" onclick="switchTab('dashboard')" id="nav-dashboard" class="nav-item <?php echo $homeFirst ? '' : 'text-blue-600'; ?> hover-target transition-colors block lg:inline py-2 lg:py-0"><i class="fas fa-home ml-1"></i> داشبورد</a>
                <?php endif; ?>
                <?php echo $chatNavHtml; ?>
                <?php if ($inqAccess): ?><a href="#" onclick="switchTab('inquiries')" id="nav-inquiries" class="nav-item hover-target transition-colors block lg:inline py-2 lg:py-0" data-perm-skip><i class="fas fa-user-check ml-1"></i> استعلامات</a><?php endif; ?>
                <?php if ($hxAccess): ?><a href="#" onclick="switchTab('hooshix')" id="nav-hooshix" class="nav-item hover-target transition-colors block lg:inline py-2 lg:py-0" data-perm-skip><i class="fas fa-robot ml-1" style="background:linear-gradient(90deg,#7c3aed,#06b6d4);-webkit-background-clip:text;background-clip:text;color:transparent"></i> هوشیکس X ai</a><?php endif; ?>

                <!-- ===== «بیمه با ما»: منوی درختیِ بزرگ به تفکیکِ رشته‌ی بیمه (هر بخش با کارها و تنظیماتِ خودش) ===== -->
                <?php
                $bmLink = function ($tab, $icon, $label, $hint = '', $id = true, $extra = '') {
                    return '<a href="#" onclick="switchTab(\'' . $tab . '\')' . $extra . '" ' . ($id ? 'id="nav-' . $tab . '"' : 'data-bm="' . $tab . '"') . ' class="nav-item menu-link"' . ($hint !== '' ? ' title="' . htmlspecialchars(strip_tags($hint)) . '"' : '') . '><i class="fas ' . $icon . ' ml-2"></i><span class="bm-l">' . $label . ($hint !== '' ? '<small>' . $hint . '</small>' : '') . '</span></a>';
                };
                // زیرگروهِ درختی داخلِ یک بخش (مثلاً «مرکز صدور» داخلِ «ثالث و بدنه»)
                $bmGroup = function ($title, $icon, $links) { return $links === '' ? '' : '<div class="bm-grp"><div class="bm-gh"><i class="fas ' . $icon . '"></i>' . $title . '</div>' . $links . '</div>'; };
                $isAdminRole = ($_SESSION['role'] ?? '') === 'ADMIN';
                $bmSecs = [];
                if ($canSeeCompanies || !$isLiaison) {
                    // ثالث و بدنه: همه‌ی کارهای صدور، شرکت‌ها، کارکنان، بازدید و مالیِ شرکت‌ها (منوهای جداگانه‌ی «صدور بیمه» و «مالی» حذف شدند)
                    $g1 = ($canSeeCompanies && !$isLiaison ? $bmLink('issue-queue', 'fa-list-check', 'در حال صدور', 'ثبتِ صدور و بیمه‌نامه') : '')
                        . ($canSeeCompanies ? $bmLink('issued-list', 'fa-file-circle-check', 'صادره‌ها', 'جستجو، اکسل، فایل') . $bmLink('endorsements', 'fa-file-pen', 'الحاقیه‌ها', 'اضافی، برگشتی، فسخ و اثرِ مالی') : '')
                        . ($canSeeCompanies && !$isLiaison ? $bmLink('issue-group', 'fa-layer-group', 'صدورِ گروهی', 'فایلِ PDFِ بیمه‌گر') . $bmLink('import-archive', 'fa-box-archive', 'بایگانیِ وارداتی', 'اکسلِ بیمه‌گر') . $bmLink('renewals', 'fa-bell', 'اعلامِ تمدید', 'شرکت‌ها و اشخاص') : '')
                        . ($canSeeCompanies && !$isLiaison ? $bmLink('pasargad-recon', 'fa-scale-unbalanced', 'مغایرت‌گیری با پاسارگاد', 'خروجیِ هفتگی/ماهانه با سایت') : '')
                        . ($isAdminRole ? $bmLink('legacy-import', 'fa-boxes-packing', 'ورودِ بایگانی‌های قبلی', 'اکسل‌ها، پوشه‌ها، ادغام') : '')
                        . ($isAdminRole ? $bmLink('premium-inquiries', 'fa-calculator', 'استعلام‌های حق بیمه', 'لاگِ استعلام‌های مینی‌اپ و پاسخ به کاربر') : '');
                    $g2 = $canSeeCompanies ? $bmLink('companies-requests', 'fa-building', 'درخواست‌های شرکت‌ها', 'گروهی و تکی؛ ردیف‌ها، مدارک، صدور') . ($isLiaison ? '' : $bmLink('personal-requests', 'fa-user', 'درخواست‌های شخصی', 'اشخاص و شرکت‌های بی‌قرارداد')) . $bmLink('companies-inbox', 'fa-inbox', 'صندوقِ ورودیِ مدارک', 'مدارکِ تگ‌نشده') : '';
                    $g3 = !$isLiaison ? $bmLink('records', 'fa-folder-open', 'پرونده‌ها و معرفی‌نامه‌ها') . $bmLink('health', 'fa-car-burst', 'بازدیدِ سلامت و مدارک') . $bmLink('approved-reviews', 'fa-circle-check', 'بازدیدهای تأییدشده')
                        . $bmLink('cases', 'fa-file-signature', 'صدورِ بیمه‌نامه‌ی کارکنان') . $bmLink('queue', 'fa-microchip', 'صفِ پردازشِ OCR', 'درخواست‌های ربات')
                        . ($isAdminRole ? $bmLink('contacts', 'fa-address-book', 'بانک مخاطبین', 'ورود و خروجِ اکسل، شماره‌ها، لاگِ کدِ تأیید') : '') : '';
                    $g4 = $vrAccess ? $bmLink('vr-build', 'fa-file-circle-plus', 'ساختِ گزارشِ بازدید') . $bmLink('vr-list', 'fa-folder-open', 'گزارش‌های صادرشده') : '';
                    $g5 = $bmLink('fin-dashboard', 'fa-chart-pie', 'داشبوردِ مالی') . $bmLink('fin-installments', 'fa-list-ol', 'مرکزِ اقساط', 'دریافت و پرداخت روی هر قسط') . $bmLink('fin-payments', 'fa-arrow-right-arrow-left', 'دریافت‌ها، پرداخت‌ها و چک‌ها')
                        . $bmLink('fin-tracking', 'fa-magnifying-glass-location', 'پیگیری', 'کدِ رهگیریِ قسط') . $bmLink('fin-invoices', 'fa-file-invoice', 'صورتحساب‌ها') . $bmLink('fin-reconcile', 'fa-scale-balanced', 'مغایرت‌گیری با اکسل')
                        . ($canSeeCompanies ? $bmLink('companies-finance', 'fa-sack-dollar', 'گزارشِ مالیِ شرکت‌ها') : '') . ($isAdminRole ? $bmLink('fin-contracts', 'fa-file-contract', 'قراردادهای پرداخت') : '');
                    $x = $bmGroup('مرکزِ صدور', 'fa-stamp', $g1) . $bmGroup('شرکت‌ها', 'fa-building', $g2) . $bmGroup('کارکنان (کسر از حقوق)', 'fa-id-card', $g3)
                        . $bmGroup('گزارشِ بازدید', 'fa-clipboard-check', $g4) . $bmGroup('مالیِ شرکت‌ها و اقساط', 'fa-coins', $g5);
                    $set = '';
                    if ($canSeeCompanies && !$isLiaison) $set .= $bmLink('companies-manage', 'fa-gear', 'مدیریتِ شرکت‌ها', 'اطلاعات، ستون‌های اکسل، کاربران');
                    if ($isAdminRole) $set .= $bmLink('fin-settings', 'fa-money-check-dollar', 'تنظیماتِ مالی') . ($vrAccess ? $bmLink('vr-settings', 'fa-sliders', 'تنظیماتِ گزارشِ بازدید') : '');
                    $bmSecs[] = ['ثالث و بدنه', 'صدور، شرکت‌ها، کارکنان، بازدید، مالی و اقساط', 'fa-car-side', '#2563eb', '#06b6d4', $x, $set, true];
                }
                if ($lifeAccess) $bmSecs[] = ['بیمه عمر', 'اقساط، وصول، تماس، رسید', 'fa-heart-pulse', '#db2777', '#f97316',
                    $bmLink('life-dash', 'fa-chart-pie', 'داشبوردِ بیمه عمر', 'وصول، معوق، همکاران') . $bmLink('life-policies', 'fa-file-medical', 'بیمه‌نامه‌ها و اقساط', 'پرداخت، تماس، رسید')
                    . $bmLink('life-import', 'fa-file-import', 'ورود از اکسل', 'گزارشِ اقساطِ بیمه‌گر') . $bmLink('life-archive', 'fa-box-archive', 'بایگانیِ بیمه عمر'),
                    $bmLink('life-settings', 'fa-sliders', 'تنظیماتِ بیمه عمر', 'ستون‌ها، رسید، ربات')];
                if ($hlAccess) $bmSecs[] = ['درمان تکمیلی', 'صورتحساب ماهانه، وصول، رسیدها، پاسارگاد', 'fa-stethoscope', '#059669', '#0e7490',
                    $bmLink('hl-dash', 'fa-chart-pie', 'داشبوردِ مالیِ درمان', 'وصول، معوق، نمودار') . $bmLink('hl-ledger', 'fa-table-list', 'دفترِ ماهانه', 'صورتحساب، پرسنل، وصول، واریز')
                    . $bmLink('hl-receipts', 'fa-inbox', 'رسیدهای دریافتی', 'عکس‌های گروه‌های ربات') . $bmLink('hl-contracts', 'fa-file-contract', 'قراردادها و الحاقیه‌ها', 'بردرو، طرح‌ها، صورت‌حساب')
                    . $bmLink('hl-members', 'fa-users', 'بیمه‌شدگان', 'لیستِ بیمه‌گر') . $bmLink('hl-pay', 'fa-hand-holding-dollar', 'دریافت‌ها', 'به ما یا مستقیم به بیمه‌گر') . $bmLink('hl-settle', 'fa-building-columns', 'واریز به پاسارگاد', 'تحویل و تسویه‌ی وجوه'),
                    $bmLink('hl-settings', 'fa-sliders', 'تنظیماتِ درمان تکمیلی', 'گروه‌های ربات، روش‌ها، مهلت‌ها')];
                if ($mkAccess) $bmSecs[] = ['بازاریابی و فروش', 'بازاریابان، فروش، پاداش، حقوق', 'fa-bullhorn', '#c2410c', '#be185d',
                    $bmLink('mk-dash', 'fa-chart-line', 'داشبوردِ فروش', 'کلی و هر بازاریاب') . $bmLink('mk-sales', 'fa-receipt', 'فروش‌ها', 'ثبت، محاسبه‌گر، اکسل') . $bmLink('mk-people', 'fa-user-tie', 'بازاریابان و کارشناسانِ تماس'),
                    $bmLink('mk-settings', 'fa-sliders', 'تنظیماتِ بازاریابی', 'کارمزد، پاداش، سطح')];
                if ($ltAccess) $bmSecs[] = ['اتوماسیونِ نامه‌ها', 'صادره، وارده، ارجاع، کارتابلِ شرکت‌ها', 'fa-envelope-open-text', '#4338ca', '#0891b2',
                    $bmLink('lt-box', 'fa-inbox', 'کارتابلِ نامه‌ها', 'صادره، وارده، ارجاع') . $bmLink('lt-box', 'fa-pen-nib', 'نامه‌ی جدید', 'با سربرگ و شماره‌ی خودکار', false, '; window.Letters && Letters.compose({}); return false;'),
                    $bmLink('lt-settings', 'fa-sliders', 'تنظیماتِ نامه‌ها', 'شماره، سربرگ، قالب، امضا')];
                if ($regAccess && !$isLiaison) $bmSecs[] = ['پرونده‌ها و بردرو', 'هر پلاک و هر پرسنل یک پرونده؛ بردروی پاسارگاد', 'fa-folder-tree', '#0f766e', '#4f46e5',
                    $bmLink('vehicles', 'fa-car-side', 'پرونده‌ی خودروها', 'هر پلاک: مدارک، بیمه‌نامه‌ها، مالک، گزارشِ PDF') . $bmLink('personnel', 'fa-id-badge', 'پرونده‌ی پرسنل', 'شرکتِ فعلی، جابه‌جایی، خانواده، بیمه‌نامه‌ها')
                    . $bmLink('bordereau', 'fa-table-list', 'بردروی پاسارگاد', 'تمدیدها به تفکیکِ شرکت؛ جدا از مالی و صادره‌ها'), ''];
                ?>
                <?php if ($bmSecs): ?>
                <div class="menu-group bm-mega">
                    <button type="button" class="menu-trigger bm-trigger" aria-expanded="false" onclick="toggleMenuGroup(this)">
                        <i class="fas fa-shield-heart ml-1"></i> بیمه با ما
                        <i class="fas fa-chevron-down text-[9px] mr-1"></i>
                    </button>
                    <div class="menu-panel bm-panel">
                        <div class="bm-top"><b><i class="fas fa-layer-group"></i> همه‌ی بخش‌ها به تفکیکِ رشته‌ی بیمه</b><small>هر بخش کارها و تنظیماتِ خودش را دارد؛ تنظیماتِ همه‌ی بخش‌ها در «تنظیماتِ سامانه» هم هست</small>
                            <input type="search" class="bm-search" placeholder="جستجو در منو… (مثلاً صادره، اقساط، رسید)" oninput="bmFilter(this.value)" onclick="event.stopPropagation()"></div>
                        <div class="bm-grid">
                        <?php foreach ($bmSecs as $__sec): [$t, $sub, $ic, $c1, $c2, $links, $set] = $__sec; ?>
                            <div class="bm-sec<?php echo !empty($__sec[7]) ? ' bm-wide' : ''; ?>" style="--c1:<?php echo $c1; ?>;--c2:<?php echo $c2; ?>">
                                <div class="bm-head"><span class="bm-ic"><i class="fas <?php echo $ic; ?>"></i></span><div><b><?php echo $t; ?></b><small><?php echo $sub; ?></small></div></div>
                                <div class="bm-links"><?php echo $links; ?></div>
                                <?php if ($set !== ''): ?><div class="bm-set"><?php echo $set; ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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
                            <button type="button" class="menu-sub-trigger" aria-expanded="false" onclick="toggleMenuSub(this, event)"><i class="fas fa-users ml-2"></i><span class="ms-t">کاربران<small>کارکنانِ ربات بله، پنل و شرکت‌ها، لاگ ورود</small></span><i class="fas fa-chevron-left ms-arrow"></i></button>
                            <div class="menu-subpanel">
                                <div class="ms-head">کاربران</div>
                                <a href="#" onclick="switchTab('staff-users')" id="nav-staff-users" class="nav-item menu-link"><i class="fas fa-user-shield ml-2"></i> کاربران <small class="text-[10px] text-slate-400 font-bold mr-1">کارکنانِ ربات بله<?php if($_SESSION['role'] === 'ADMIN'): ?>، داخلی و شرکتی<?php endif; ?></small></a>
                                <?php if($_SESSION['role'] === 'ADMIN'): ?><a href="#" onclick="switchTab('login-logs')" id="nav-login-logs" class="nav-item menu-link"><i class="fas fa-right-to-bracket ml-2"></i> لاگ ورود و خروج</a><?php endif; ?>
                            </div>
                        </div>
                        <?php if($_SESSION['role'] === 'ADMIN'): ?>
                        <div class="menu-sep"></div>
                        <?php if ($realRole === 'ADMIN' && empty($permBoot['custom'])): ?>
                        <div class="menu-sub">
                            <button type="button" class="menu-sub-trigger" aria-expanded="false" onclick="toggleMenuSub(this, event)"><i class="fas fa-cogs ml-2"></i><span class="ms-t">تنظیمات<small>سامانه، سپر امنیتی</small></span><i class="fas fa-chevron-left ms-arrow"></i></button>
                            <div class="menu-subpanel">
                                <div class="ms-head">تنظیمات</div>
                                <a href="#" onclick="switchTab('settings')" id="nav-settings" class="nav-item menu-link"><i class="fas fa-sliders ml-2"></i> تنظیماتِ سامانه <small class="text-[10px] text-slate-400 font-bold mr-1">همه‌ی بخش‌ها، ربات، پشتیبان‌گیری</small></a>
                                <a href="#" onclick="switchTab('announcements')" id="nav-announcements" class="nav-item menu-link"><i class="fas fa-bullhorn ml-2"></i> اعلان‌های پاپ‌آپ <small class="text-[10px] text-slate-400 font-bold mr-1">هشدار، اطلاعیه، قوانین، تولد</small></a>
                                <a href="#" onclick="switchTab('security')" id="nav-security" class="nav-item menu-link"><i class="fas fa-shield-halved ml-2"></i> سپر امنیتی <small class="text-[10px] text-slate-400 font-bold mr-1">گزارش، نشست‌ها، رخدادها</small></a>
                            </div>
                        </div>
                        <?php else: ?>
                        <a href="#" onclick="switchTab('settings')" id="nav-settings" class="nav-item menu-link"><i class="fas fa-cogs ml-2"></i> تنظیماتِ سامانه <small class="text-[10px] text-slate-400 font-bold mr-1">همه‌ی بخش‌ها، ربات، پشتیبان‌گیری</small></a>
                        <a href="#" onclick="switchTab('announcements')" id="nav-announcements" class="nav-item menu-link"><i class="fas fa-bullhorn ml-2"></i> اعلان‌های پاپ‌آپ <small class="text-[10px] text-slate-400 font-bold mr-1">هشدار، اطلاعیه، قوانین، تولد</small></a>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; /* !$isParsian && !$isLife */ ?>
            </nav>
        </div>

        <div class="flex items-center gap-3 lg:gap-5">
            <span id="notif-bell-mount" class="inline-flex"></span>
            <a href="logout.php" class="text-red-400 hover:text-red-600 transition-colors hover-target text-xl" title="خروج از سیستم"><i class="fas fa-power-off"></i></a>
            <div class="flex items-center gap-3 border-r border-slate-300 pr-5">
                <button onclick="document.getElementById('profile-modal').classList.add('active')" class="hdr-edit-btn w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-blue-100 hover:text-blue-600 flex items-center justify-center transition-all hover-target shadow-sm" title="ویرایش پروفایل"><i class="fas fa-pen text-xs"></i></button>
                <div class="flex flex-col text-right justify-center">
                    <p class="hdr-user-name text-sm font-black text-slate-700 leading-tight"><?php echo htmlspecialchars($hdrName); ?></p>
                    <p class="hdr-user-title text-[10px] font-bold text-slate-400 mt-0.5"><?php echo htmlspecialchars($hdrTitle); ?><?php if (!empty($permBoot['custom'])): ?> <span class="text-[9px] bg-violet-100 text-violet-700 rounded-full px-1.5 py-px mr-0.5" title="مدیر کل دسترسیِ صفحه‌به‌صفحه برای شما تعیین کرده است">دسترسی سفارشی</span><?php endif; ?></p>
                    <p id="hdr-clock" class="hdr-clock" title="تاریخ و ساعتِ ایران (ساعتِ سرور)"><i class="far fa-clock"></i><span class="hc-d"></span><span class="hc-sep">-</span><span class="hc-t"></span></p>
                </div>
                <button type="button" id="me-avatar" onclick="document.getElementById('profile-modal').classList.add('active')" title="عکس و پروفایل" class="w-11 h-11 rounded-2xl overflow-visible flex items-center justify-center hover:scale-105 transition-transform"><span class="w-11 h-11 bg-gradient-to-tr from-blue-500 to-cyan-400 text-white rounded-full flex items-center justify-center text-xl shadow-md shadow-blue-500/30"><i class="fas fa-user"></i></span></button>
            </div>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto p-6 lg:p-8 flex flex-col z-10">
        <!-- ===== اطلاعات پرسنلی ===== -->
        <div id="tab-my-work" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="wk-my-root"></div></div>
        <div id="tab-tools" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="tools-root"></div></div>
        <div id="tab-staff-work" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="wk-staff-root"></div></div>
        <div id="tab-my-tasks" class="tab-content max-w-6xl mx-auto w-full flex-1 hidden"><div id="stk-my-root"></div></div>
        <div id="tab-staff-tasks" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="stk-admin-root"></div></div>
        <div id="tab-service-report" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="wk-svc-root"></div></div>
        <div id="tab-announcements" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="ann-root"></div></div>
        <?php if ($realRole === 'ADMIN' && empty($permBoot['custom'])): ?><div id="tab-security" class="tab-content max-w-7xl mx-auto w-full flex-1 hidden"><div id="sec-root"></div></div><?php endif; ?>
        
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

        <div id="tab-home" class="tab-content max-w-7xl mx-auto w-full flex-1 <?php echo $homeFirst ? '' : 'hidden'; ?>"><div id="home-root"></div></div>

        <div id="tab-dashboard" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 <?php echo $homeFirst ? 'hidden' : ''; ?>">
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

            <!-- کارت‌های آمار: پرونده‌ها و صدور، درخواست‌ها (گروهی/تکی/شخصی) و حق‌بیمه -->
            <div class="ds-grid">
                <div class="ds-card" style="--a:#2563eb;--b:#60a5fa">
                    <div class="ds-top"><span class="ds-ic"><i class="fas fa-folder-open"></i></span><span class="ds-lb">کل پرونده‌های کارکنان</span></div>
                    <b class="ds-n" id="stat-total">۰</b>
                    <p class="ds-sub">کل معرفی‌نامه‌های دریافتی</p>
                </div>
                <div class="ds-card" style="--a:#f59e0b;--b:#fbbf24">
                    <div class="ds-top"><span class="ds-ic"><i class="fas fa-clock"></i></span><span class="ds-lb">پرونده‌های جدید</span></div>
                    <b class="ds-n" id="stat-new">۰</b>
                    <p class="ds-sub">معرفی‌نامه‌هایی که هنوز درخواست ندارند</p>
                </div>
                <div class="ds-card" style="--a:#0891b2;--b:#22d3ee">
                    <div class="ds-top"><span class="ds-ic"><i class="fas fa-id-card"></i></span><span class="ds-lb">در انتظار تکمیل مدارک</span></div>
                    <b class="ds-n" id="stat-pending">۰</b>
                    <p class="ds-sub" id="stat-pending-sub">در حال تکمیلِ مدارک تا صدور</p>
                    <div class="ds-bar"><i id="stat-pending-bar"></i></div>
                </div>
                <div class="ds-card" style="--a:#059669;--b:#34d399">
                    <div class="ds-top"><span class="ds-ic"><i class="fas fa-circle-check"></i></span><span class="ds-lb">بیمه‌نامه‌های صادر شده</span></div>
                    <b class="ds-n" id="stat-issued">۰</b>
                    <p class="ds-sub" id="stat-issued-sub">کارکنان و شرکتی</p>
                    <div class="ds-bar"><i id="stat-issued-bar"></i></div>
                </div>
                <div class="ds-card ds-click" style="--a:#4f46e5;--b:#818cf8" onclick="switchTab('companies-requests'); if (window.creqSetScope) creqSetScope('group');" title="رفتن به درخواست‌های گروهی">
                    <div class="ds-top"><span class="ds-ic"><i class="fas fa-layer-group"></i></span><span class="ds-lb">درخواست‌های شرکتیِ گروهی</span><i class="fas fa-arrow-left ds-go"></i></div>
                    <b class="ds-n"><span id="stat-rg-open">۰</span><small> باز از <span id="stat-rg-all">۰</span></small></b>
                    <p class="ds-sub">نامه و چند خودرو؛ در جریانِ مدارک و صدور</p>
                    <div class="ds-bar"><i id="stat-rg-bar"></i></div>
                </div>
                <div class="ds-card ds-click" style="--a:#0d9488;--b:#2dd4bf" onclick="switchTab('companies-requests'); if (window.creqSetScope) creqSetScope('single');" title="رفتن به درخواست‌های تکی">
                    <div class="ds-top"><span class="ds-ic"><i class="fas fa-car"></i></span><span class="ds-lb">درخواست‌های شرکتیِ تکی</span><i class="fas fa-arrow-left ds-go"></i></div>
                    <b class="ds-n"><span id="stat-rs-open">۰</span><small> باز از <span id="stat-rs-all">۰</span></small></b>
                    <p class="ds-sub">یک خودرو؛ با نامه یا اسکرین‌شاتِ چت</p>
                    <div class="ds-bar"><i id="stat-rs-bar"></i></div>
                </div>
                <div class="ds-card ds-click" style="--a:#9333ea;--b:#e879f9" onclick="switchTab('personal-requests')" title="رفتن به درخواست‌های شخصی">
                    <div class="ds-top"><span class="ds-ic"><i class="fas fa-user-shield"></i></span><span class="ds-lb">درخواست‌های شخصی</span><i class="fas fa-arrow-left ds-go"></i></div>
                    <b class="ds-n"><span id="stat-rp-open">۰</span><small> باز از <span id="stat-rp-all">۰</span></small></b>
                    <p class="ds-sub" id="stat-rp-sub">اشخاص و شرکت‌های بی‌قرارداد</p>
                    <div class="ds-bar"><i id="stat-rp-bar"></i></div>
                </div>
                <div class="ds-card ds-prem" style="--a:#be123c;--b:#fb7185">
                    <div class="ds-top"><span class="ds-ic"><i class="fas fa-sack-dollar"></i></span><span class="ds-lb">حق‌بیمه‌ی صادره‌های <span id="stat-pm-month">این ماه</span></span></div>
                    <b class="ds-n ds-money"><span id="stat-pm-val">۰</span><small> ریال</small></b>
                    <p class="ds-sub" id="stat-pm-sub">—</p>
                    <div class="ds-split" id="stat-pm-split"></div>
                    <div class="ds-mini"><span>امروز: <b id="stat-pm-today">۰</b></span><span>امسال: <b id="stat-pm-year">۰</b></span></div>
                </div>
            </div>

            <!-- ===== اعلان‌های صدور: (۱) انقضای نزدیکِ بیمه‌نامه‌های شرکتیِ صادرنشده  (۲) درخواست‌های کارکنانِ صادرنشده ===== -->
            <div id="da-filters" class="card p-3 flex flex-wrap items-center gap-2 text-xs">
                <span class="font-black text-slate-600"><i class="fas fa-filter text-rose-500 ml-1"></i>هشدارها:</span>
                <select id="da-f-company" onchange="loadDashAlerts()" class="border rounded-lg px-2 py-1.5 font-bold"><option value="">همه‌ی شرکت‌ها</option></select>
                <select id="da-f-type" onchange="loadDashAlerts()" class="border rounded-lg px-2 py-1.5 font-bold"><option value="">بدنه و ثالث</option><option value="BODY">بدنه</option><option value="THIRDPARTY">ثالث</option></select>
                <select id="da-f-days" onchange="loadDashAlerts()" class="border rounded-lg px-2 py-1.5 font-bold"><option value="7">تا ۷ روزِ آینده</option><option value="15" selected>تا ۱۵ روزِ آینده</option><option value="30">تا ۳۰ روزِ آینده</option><option value="60">تا ۶۰ روزِ آینده</option></select>
                <input id="da-f-from" data-jdate placeholder="از تاریخ" dir="ltr" class="border rounded-lg px-2 py-1.5 w-28" onchange="loadDashAlerts()">
                <input id="da-f-to" data-jdate placeholder="تا تاریخ" dir="ltr" class="border rounded-lg px-2 py-1.5 w-28" onchange="loadDashAlerts()">
                <button type="button" onclick="['da-f-company','da-f-type','da-f-from','da-f-to'].forEach(i => document.getElementById(i).value = ''); document.getElementById('da-f-days').value = '15'; loadDashAlerts();" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold px-3 py-1.5 rounded-lg"><i class="fas fa-eraser ml-1"></i>پاک‌کردن</button>
                <span class="text-[10.5px] text-slate-400">با «از/تا تاریخ»، انقضای ردیف‌های شرکتی و تاریخِ ثبتِ درخواست‌های کارکنان در همان بازه نشان داده می‌شود.</span>
            </div>
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
                    <?php if ($canManualPersonnel): // مدیر کل یا دسترسیِ ثبت/ویرایشِ صدورِ کارکنان ?>
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
        <!-- ======================= کاربران: کارکنانِ ربات بله (همه‌ی نقش‌های دارای دسترسی) + کاربرانِ داخلی و شرکتی (مدیر کل) ======================= -->
        <?php if (!$isLiaison): $suAdmin = ($_SESSION['role'] ?? '') === 'ADMIN'; ?>
        <div id="tab-staff-users" class="tab-content max-w-7xl mx-auto w-full space-y-4 flex-1 hidden">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-user-shield text-blue-500 ml-2"></i>کاربران</h1>
                    <p class="text-xs text-slate-400 mt-1">کارکنانِ ربات بله<?php if ($suAdmin): ?>، کاربرانِ داخلیِ پنل (مدیر کل، کارشناس صدور، کارشناس مالی، کارمند بیمه با ما) و کاربرانِ شرکت‌ها - همه یک‌جا. ویرایش و حذف با رمزِ خودتان تایید می‌شود<?php else: ?>: وضعیتِ ورود به ربات، شماره‌ی قفل‌شده و پرونده‌ها<?php endif; ?>.</p>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <?php if ($suAdmin): ?><button onclick="switchTab('login-logs')" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-right-to-bracket ml-1"></i>لاگ ورود و خروج</button><?php endif; ?>
                    <button onclick="loadStaffUsers()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold"><i class="fas fa-sync-alt"></i> بروزرسانی</button>
                    <?php if ($suAdmin): ?><button onclick="openAddUserModal()" class="su-staff-only bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-plus ml-1"></i>افزودن کاربر</button><?php endif; ?>
                </div>
            </div>
            <p id="su-schema-note" class="su-staff-only hidden text-[11px] text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">برای ویرایش، حذف، لاگ ورود و ورود با ربات، مایگریشن migrations/016_users_bot_login.sql را اجرا کنید.</p>

            <!-- جستجو و فیلتر -->
            <div class="card p-3 flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-[220px]">
                    <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                    <input type="search" id="su-q" oninput="renderStaffUsers()" placeholder="جستجو: نام، کد ملی، کد پرسنلی، موبایل، شرکت، نقش..." class="w-full border rounded-xl pr-8 pl-3 py-2 text-xs">
                </div>
                <div class="su-seg" id="su-type-seg">
                    <button type="button" data-v="BOT" <?php if (!$suAdmin): ?>class="active"<?php endif; ?> onclick="setUserTypeFilter('BOT')"><i class="fas fa-robot ml-1"></i>کارکنان (ربات بله) <span id="su-n-bot"></span></button>
                    <?php if ($suAdmin): ?>
                    <button type="button" data-v="" class="active" onclick="setUserTypeFilter('')">همه <span id="su-n-all"></span></button>
                    <button type="button" data-v="STAFF" onclick="setUserTypeFilter('STAFF')"><i class="fas fa-user-tie ml-1"></i>داخلی <span id="su-n-staff"></span></button>
                    <button type="button" data-v="COMPANY" onclick="setUserTypeFilter('COMPANY')"><i class="fas fa-building ml-1"></i>شرکتی <span id="su-n-company"></span></button>
                    <?php endif; ?>
                </div>
                <?php if ($suAdmin): ?>
                <select id="su-role-filter" onchange="renderStaffUsers()" class="su-staff-only border rounded-xl px-2 py-2 text-xs font-bold">
                    <option value="">همه‌ی نقش‌ها</option>
                    <optgroup label="کارکنانِ بیمه با ما"><option value="ADMIN">مدیر کل</option><option value="OPERATOR">کارشناس صدور</option><option value="FINANCE">کارشناس مالی</option>
                    <option value="LIAISON_ALL">کارمند بیمه با ما (عادی و پارسیان)</option><option value="COMPANY_LIAISON">کارمند بیمه با ما (عادی)</option><option value="PARSIAN">کارمند بیمه با ما (پارسیان)</option><option value="LIFE">کاربر بیمه عمر</option></optgroup>
                    <optgroup label="بیرون از مجموعه"><option value="COMPANY">کاربر شرکت</option></optgroup>
                </select>
                <?php endif; ?>
                <select id="sb-sort" onchange="renderBotUsers()" class="su-bot-only hidden border rounded-xl px-2 py-2 text-xs font-bold">
                    <option value="">مرتب‌سازی: آخرین ورود</option><option value="name">نام</option><option value="cases">بیشترین پرونده</option><option value="seen">آخرین بازدید</option>
                </select>
            </div>

            <?php if ($suAdmin): ?>
            <div id="su-staff-wrap" class="space-y-4">
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
            <?php endif; ?>

            <!-- کارکنانِ ربات بله: قفلِ شماره ↔ کد ملی، ورود/خروج، سوییچِ مدیر کل (api/_bot_identity.php) -->
            <div id="su-bot-wrap" data-perm-skip class="<?php echo $suAdmin ? 'hidden ' : ''; ?>space-y-3">
                <div class="sb-hero">
                    <span class="sb-hero-ic"><i class="fas fa-robot"></i></span>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-black text-[15px]">کارکنانِ ربات بله</h3>
                        <div class="sb-rules">
                            <span><i class="fas fa-lock ml-1"></i>هر شماره فقط به یک کد ملی قفل می‌شود</span>
                            <span><i class="fas fa-id-card ml-1"></i>هر کد ملی فقط با همان شماره وارد می‌شود</span>
                            <span><i class="fas fa-right-from-bracket ml-1"></i>کاربر می‌تواند از حسابش خارج و دوباره وارد شود</span>
                            <span><i class="fas fa-crown ml-1"></i>فقط شماره‌ی مدیر کل روی همه سوییچ می‌کند</span>
                        </div>
                    </div>
                </div>
                <div class="sb-stats" id="sb-stats"></div>
                <div id="sb-admins" class="space-y-2"></div>
                <div class="card overflow-x-auto">
                    <table class="w-full text-xs sb-table">
                        <thead class="bg-slate-50 text-slate-500"><tr>
                            <th class="p-3 text-right">کارمند</th><th class="p-3 text-right">کد ملی / پرسنلی</th><th class="p-3 text-right">شرکت</th>
                            <th class="p-3 text-right">شماره‌ی قفل‌شده</th><th class="p-3 text-right">وضعیت ربات</th><th class="p-3 text-right">ورود / خروج</th>
                            <th class="p-3 text-right">پرونده‌ها</th><th class="p-3 text-right"></th>
                        </tr></thead>
                        <tbody id="sb-body"><tr><td colspan="8" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
                        <input type="file" data-uk-skip id="user-chat-file" class="hidden" onchange="sendUserFile()">
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
                <?php /* آرشیوِ چت‌های ربات و بایگانیِ همه‌ی گفتگوها فقط برای مدیر کلِ واقعی (نه هیچ نقش یا دسترسیِ سفارشیِ دیگری) */
                      $chatArchiveOk = $realRole === 'ADMIN' && empty($permBoot['custom']); ?>
                <?php if ($chatArchiveOk): ?>
                <div class="flex gap-2">
                    <button onclick="switchGoftegoSub('messenger')" id="goftego-sub-messenger" class="px-4 py-2 rounded-lg text-xs font-bold bg-emerald-600 text-white">💬 پیام‌رسان</button>
                    <button onclick="switchGoftegoSub('botchats')" id="goftego-sub-botchats" class="px-4 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-500">🤖 آرشیو کاملِ چت‌های ربات</button>
                    <button onclick="switchGoftegoSub('archive')" id="goftego-sub-archive" class="px-4 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-500">🗄 بایگانیِ همه‌ی گفتگوها</button>
                    <button onclick="switchGoftegoSub('access')" id="goftego-sub-access" class="px-4 py-2 rounded-lg text-xs font-bold bg-slate-100 text-slate-500">🔐 دسترسیِ گفتگو</button>
                </div>
                <?php endif; ?>
            </div>

            <div id="goftego-panel-messenger"><div id="chat-root" style="height: calc(100vh - 200px); min-height: 520px;"></div></div>
            <?php if ($chatArchiveOk): ?><div id="goftego-panel-archive" class="hidden"><div id="chat-archive-root"></div></div>
            <div id="goftego-panel-access" class="hidden"><div id="chat-access-root"></div></div><?php endif; ?>

            <?php if ($chatArchiveOk): ?>
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
            <?php endif; ?>

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
                            <input type="file" data-uk-skip id="ticket-chat-file" class="hidden" onchange="sendTicketFile()">
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
                                <input type="file" data-uk-skip id="staffchat-file" class="hidden" onchange="sendStaffChatFile()">
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
                                <input type="file" data-uk-skip id="companychat-file" class="hidden" onchange="sendCompanyChatFile()">
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

        <!-- ======================= بانک مخاطبین - contacts.js ======================= -->
        <div id="tab-contacts" class="tab-content max-w-[1700px] mx-auto w-full space-y-4 flex-1 hidden">
            <div id="ct-root"></div>
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

            <!-- نوبت‌های بازدیدِ خودرو (بدنه): تقویمِ کاری، فهرست، نوبتِ دستی، صدورِ گزارش از روی نوبت (visit-appt.js) -->
            <div class="card p-4 border-sky-100" id="va-root"></div>

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
                <div id="case-lock" data-lock="" class="px-5 pt-3 empty:hidden"></div>
                <div id="case-detail-body" class="flex-1 overflow-y-auto p-5 space-y-4"></div>
            </div>
        </div>

        <!-- ======================= مودال جزئیات پرونده (سطح معرفی‌نامه) - نمای تجمیعی همه‌ی بیمه‌نامه‌ها ======================= -->
        <div id="intro-detail-modal" class="fixed inset-0 bg-black/50 z-[998] hidden items-start justify-center pt-24 px-4 pb-4 overflow-y-auto">
            <div class="bg-white rounded-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
                <div class="p-4 border-b flex justify-between items-center bg-amber-50">
                    <h3 class="font-bold text-amber-700"><i class="fas fa-id-card ml-1"></i> پرونده <span id="intro-detail-name"></span></h3>
                    <span class="flex items-center gap-2">
                        <?php if ($canManualPersonnel ?? false): ?><button id="intro-edit-btn" onclick="openIntroEdit(window._introDetailId)" class="bg-white border border-amber-200 text-amber-700 hover:bg-amber-100 text-xs font-bold px-3 py-1.5 rounded-lg"><i class="fas fa-pen ml-1"></i>ویرایش معرفی‌نامه</button><?php endif; ?>
                        <button onclick="document.getElementById('intro-detail-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700"><i class="fas fa-times"></i></button>
                    </span>
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
            <div data-scat="work" class="card p-6 border-indigo-100 bg-gradient-to-br from-white to-indigo-50/30">
                <h3 class="font-bold text-slate-700 mb-3 border-b border-indigo-100 pb-3"><i class="fas fa-calendar-check text-indigo-500 ml-2"></i>تنظیماتِ کارکرد و مرخصی <small class="text-[11px] text-slate-400 font-bold mr-1">مرخصیِ ماهانه، ساعتِ کار، تعطیلات</small></h3>
                <div id="wk-settings-root"><p class="text-xs text-slate-400">در حالِ بارگذاری…</p></div>
            </div>
            
            <?php if ($realRole === 'ADMIN'): ?>
            <div data-scat="comfort" class="card p-6 border-fuchsia-100 bg-gradient-to-br from-white to-fuchsia-50/30">
                <h3 class="font-bold text-slate-700 mb-3 border-b border-fuchsia-100 pb-3"><i class="fas fa-compact-disc text-fuchsia-500 ml-2"></i>کتابخانه‌ی موسیقی <small class="text-[11px] text-slate-400 font-bold mr-1">پخش‌کننده‌ی پایین سمت چپ · آپلودِ تکی و زیپ · آهنگ‌های کاربران · سقفِ حجم</small></h3>
                <div id="cf-lib-root"><p class="text-xs text-slate-400">در حالِ بارگذاری…</p></div>
            </div>
            <div data-scat="tools" class="card p-6 border-pink-100 bg-gradient-to-br from-white to-pink-50/30">
                <h3 class="font-bold text-slate-700 mb-3 border-b border-pink-100 pb-3"><i class="fas fa-toolbox text-pink-500 ml-2"></i>ابزارها <small class="text-[11px] text-slate-400 font-bold mr-1">انواعِ «تاریخِ قسط»، پیش‌فرض‌های عکس و اقساط، سقفِ PDF · دسترسیِ هر ابزار از «دسترسی‌ها»ی هر کاربر</small></h3>
                <div id="tools-set-root"><p class="text-xs text-slate-400">در حالِ بارگذاری…</p></div>
            </div>
            <?php endif; ?>
            <div data-scat="general" class="card p-6 border-violet-100 bg-gradient-to-br from-white to-violet-50/30">
                <h3 class="font-bold text-slate-700 mb-3 border-b border-violet-100 pb-3"><i class="fas fa-comment-sms text-violet-500 ml-2"></i>پیامک و ورود با کدِ تأیید (OTP) <small class="text-[11px] text-slate-400 font-bold mr-1">راهِ ارسالِ کد (ربات / پنلِ پیامک / پیامکی OTP) برای ورود در ربات و ورود به پنل، چند پنلِ پیامک (فعال + پشتیبان)، متنِ الگو، ارسالِ آزمایشی</small></h3>
                <div id="sms-otp-root"><p class="text-xs text-slate-400">در حالِ بارگذاری…</p></div>
            </div>
            <?php if ($realRole === 'ADMIN'): ?>
            <div data-scat="general" class="card p-6 border-sky-100 bg-gradient-to-br from-white to-sky-50/30">
                <h3 class="font-bold text-slate-700 mb-3 border-b border-sky-100 pb-3"><i class="fas fa-key text-sky-500 ml-2"></i>کلیدهای API (وب‌سرویس‌ها) <small class="text-[11px] text-slate-400 font-bold mr-1">s.api.ir: کلیدِ مشترک، پیامکی OTP، استعلامِ شخصِ حقوقی · کلیدِ جدا و تنظیمِ هر سرویس · آزمایش</small></h3>
                <div id="api-keys-root"><p class="text-xs text-slate-400">در حالِ بارگذاری…</p></div>
            </div>
            <?php endif; ?>
            <?php if ($realRole === 'ADMIN'): ?>
            <div data-scat="general" class="card p-6 border-violet-100 bg-gradient-to-br from-white to-violet-50/30">
                <h3 class="font-bold text-slate-700 mb-3 border-b border-violet-100 pb-3"><i class="fas fa-shoe-prints text-violet-500 ml-2"></i>فوتر (توسعه‌دهنده و نسخه) <small class="text-[11px] text-slate-400 font-bold mr-1">متن، رنگ و نمایش/پنهانِ پایینِ پنل و صفحه‌ی ورود</small></h3>
                <div id="footer-settings-root"><p class="text-xs text-slate-400">در حالِ بارگذاری…</p></div>
            </div>
            <?php endif; ?>
            <div data-scat="general" class="card p-6 border-violet-100 bg-gradient-to-br from-white to-violet-50/30">
                <h3 class="font-bold text-slate-700 mb-3 border-b border-violet-100 pb-3"><i class="fas fa-palette text-violet-500 ml-2"></i>لوگو و فاوآیکن <small class="text-[11px] text-slate-400 font-bold mr-1">لوگوی سربرگ پنل و آیکونِ تبِ مرورگر</small></h3>
                <div id="brand-settings-root"><p class="text-xs text-slate-400">در حالِ بارگذاری…</p></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
                <!-- بازدید سلامت: بازدید توسط کاربر روشن/خاموش، نوبت‌دهی، و ورودِ مخفیِ مدیر به مینی‌اپ (api/webapp_admin.php) -->
                <div data-scat="bots" class="card p-6 border-indigo-100 bg-gradient-to-br from-white to-indigo-50/40 md:col-span-2" id="hadm-card">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-indigo-100 pb-3"><i class="fas fa-car-on text-indigo-500 ml-2"></i>بازدید سلامت، نوبت‌دهی و مینی‌اپِ مدیر</h3>
                    <div class="grid md:grid-cols-2 gap-5 mt-3">
                        <div>
                            <label class="flex items-center gap-3 bg-white border border-slate-200 rounded-xl p-3 cursor-pointer">
                                <input type="checkbox" id="hadm-self" class="w-5 h-5 accent-indigo-600">
                                <span><b class="text-sm text-slate-700">بازدید توسط خودِ کاربران</b><small class="block text-[11px] text-slate-500 leading-5">خاموش: کاربران در ربات/مینی‌اپ بازدید نمی‌فرستند و طبقِ برنامه‌ی زیر نوبتِ بازدید می‌گیرند. بازدیدِ مدیر از مینی‌اپ همیشه فعال است.</small></span>
                            </label>
                            <p class="text-xs font-bold text-slate-600 mt-4 mb-2">برنامه‌ی نوبت‌دهی</p>
                            <div id="hadm-days" class="flex flex-wrap gap-1.5 mb-3"></div>
                            <div class="grid grid-cols-2 gap-2 text-[11px]">
                                <label class="font-bold text-slate-500">از ساعت<input id="hadm-start" dir="ltr" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs" placeholder="09:00"></label>
                                <label class="font-bold text-slate-500">تا ساعت<input id="hadm-end" dir="ltr" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs" placeholder="14:00"></label>
                                <label class="font-bold text-slate-500">طولِ هر نوبت (دقیقه)<input id="hadm-slot" dir="ltr" inputmode="numeric" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs"></label>
                                <label class="font-bold text-slate-500">خودرو در هر نوبت<input id="hadm-cap" dir="ltr" inputmode="numeric" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs"></label>
                                <label class="font-bold text-slate-500">اولین نوبت چند روز بعد<input id="hadm-lead" dir="ltr" inputmode="numeric" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs"></label>
                                <label class="font-bold text-slate-500 col-span-2">محل/توضیحِ بازدید (در پیامِ نوبت)<input id="hadm-place" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs" placeholder="مثلاً: پارکینگ دفتر، خیابان ..."></label>
                                <label class="font-bold text-slate-500 col-span-2 flex items-center gap-2 mt-1"><input type="checkbox" id="hadm-pick" class="w-4 h-4 accent-indigo-600"><span>کاربر بعد از ارسالِ مدارک، خودش زمانِ بازدید را از نوبت‌های آزاد انتخاب کند (خاموش = نوبتِ خودکار)</span></label>
                                <label class="font-bold text-slate-500">انتخابِ نوبت تا چند روزِ آینده<input id="hadm-window" dir="ltr" inputmode="numeric" class="w-full border rounded-lg px-2 py-1.5 mt-1 text-xs" placeholder="7"></label>
                            </div>
                            <p class="text-[10.5px] text-slate-400 mt-2 leading-5">روزهای «تعطیلات» (تنظیماتِ کارکرد) نوبت نمی‌گیرند. نوبتِ هر درخواست را در مینی‌اپِ مدیر می‌شود دستی عوض کرد (به کاربر خبر داده می‌شود).</p>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-600 mb-2"><i class="fas fa-user-secret text-indigo-500 ml-1"></i>دستورِ مخفیِ ورودِ مدیر در ربات</p>
                            <div class="flex gap-2"><input id="hadm-cmd" dir="ltr" class="flex-1 border rounded-lg px-3 py-2 text-sm font-mono" placeholder="/my_secret_123" autocomplete="off">
                                <button type="button" onclick="hadmGenCmd()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold px-3 rounded-lg" title="ساختِ دستورِ تصادفی"><i class="fas fa-dice"></i></button></div>
                            <div class="mt-3 bg-white border border-indigo-100 rounded-xl p-3 text-[11.5px] text-slate-600 leading-7">
                                <b class="text-indigo-700"><i class="fas fa-book-open ml-1"></i>راهنمای ورود به مینی‌اپِ مدیر</b>
                                <ol class="list-decimal pr-5 mt-1">
                                    <li>یک دستورِ مخفی (فقط حروف/رقمِ انگلیسی، مثلاً <code dir="ltr">/kz_admin_7391</code>) بالا بنویسید و ذخیره کنید؛ آن را به کسی نگویید.</li>
                                    <li>در ربات بله‌ی کارکنان همان دستور را بفرستید. ربات <b>نام کاربری</b> و بعد <b>رمزِ سایت</b> را می‌پرسد (پیامِ رمز بلافاصله از چت پاک می‌شود).</li>
                                    <li>فقط حسابِ «مدیر کل» وارد می‌شود؛ دکمه‌ی «🛡 ورود به پنلِ مدیر» می‌آید که ۱۰ دقیقه و فقط یک بار معتبر است.</li>
                                    <li>در مینی‌اپ: <b>صفِ بازدید</b> (درخواست‌های بدنه به ترتیبِ نوبت، با اطلاعاتِ درخواست و معرفی‌نامه)، <b>بازدیدِ آزاد</b> (هر پلاکی)، و <b>ثبتِ درخواست</b> از روی عکسِ معرفی‌نامه.</li>
                                    <li>بازدیدی که مدیر ثبت می‌کند تایید لازم ندارد و مستقیم در «بازدیدهای تاییدشده» برای صدورِ گزارشِ بازدید آماده است.</li>
                                    <li>۵ رمزِ اشتباه = ۱۵ دقیقه قفل. برای لغوِ ورود در ربات <code dir="ltr">/cancel</code> را بفرستید. برای غیرفعال‌کردن، دستور را خالی کنید.</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    <button type="button" onclick="hadmSave()" class="mt-4 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold px-6 py-2.5 rounded-xl"><i class="fas fa-check ml-1"></i>ذخیره</button>
                </div>
                <?php endif; ?>
                <div data-scat="bots" class="card p-6 border-emerald-100 bg-gradient-to-br from-white to-emerald-50/30">
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

                <div data-scat="bots" class="card p-6 border-cyan-100 bg-gradient-to-br from-white to-cyan-50/30">
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

                <div data-scat="webapp" class="card p-6 border-violet-100 bg-gradient-to-br from-white to-violet-50/30 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-violet-100 pb-3"><i class="fas fa-business-time text-violet-500 ml-2"></i>ساعتِ کاریِ ثبتِ درخواستِ آنلاین (مینی‌اپ)</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-3">برای هر روزِ هفته ساعتِ شروع و پایان را تعیین کنید. بیرون از این ساعت‌ها «ثبت درخواست بیمه» و بارگذاریِ مدارک بسته است و صفحه‌ی متحرکِ پایانِ ساعتِ کاری با برنامه‌ی هفته و زمانِ بعدی نشان داده می‌شود؛ بیمه‌نامه‌های من، پلاک‌های من، ارتباط با کارشناس و دیدنِ وضعیتِ مدارک همیشه باز است.</p>
                    <div id="ma-hours"></div>
                </div>
                <div data-scat="webapp" class="card p-6 border-indigo-100 bg-gradient-to-br from-white to-indigo-50/30 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-indigo-100 pb-3"><i class="fas fa-car-side text-indigo-500 ml-2"></i>انواعِ وسیله‌ی نقلیه (ثبتِ درخواست و استعلام)</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-3">فهرستِ «نوع ← گروه ← مدل» که کاربر در مینی‌اپ (ثبتِ درخواست و استعلامِ حق بیمه) از آن انتخاب می‌کند. «رده‌ی استعلام» تعیین می‌کند مبلغِ ثالث با کدام رده حساب شود؛ گروه/مدلی که رده ندارد در استعلام به کارشناس ارجاع می‌شود. وسیله‌ی انتخاب‌شده روی درخواست ثبت و در صفحه‌ی صدور دیده می‌شود.</p>
                    <div id="ma-vehicles"></div>
                </div>
                <div data-scat="webapp" class="card p-6 border-rose-100 bg-gradient-to-br from-white to-rose-50/30 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-rose-100 pb-3"><i class="fas fa-bug text-rose-500 ml-2"></i>گزارشِ خطاهای مینی‌اپ (از گوشیِ کاربران)</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-3">اگر مینی‌اپ روی گوشیِ کاربری (داخلِ بله) درست اجرا نشود، متنِ خطا و مشخصاتِ دستگاه (نسخه‌ی مرورگر، اندازه‌ی صفحه) خودکار اینجا ثبت می‌شود. برای عیب‌یابیِ دستی در ربات <b>/debug</b> را بفرستید: صفحه‌ها با کادرِ «اطلاعاتِ دستگاه» باز می‌شوند و گزارش اینجا هم می‌آید. فقط ۳۰ روزِ اخیر نگه داشته می‌شود.</p>
                    <div id="ma-client-errors"></div>
                </div>

                <div data-scat="issue" class="card p-6 border-blue-100 bg-gradient-to-br from-white to-blue-50/30">
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

                <div data-scat="issue" class="card p-6 border-rose-100 bg-gradient-to-br from-white to-rose-50/30">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-rose-100 pb-3"><i class="fas fa-file-pdf text-rose-500 ml-2"></i>تعدادِ صفحه‌های فایلِ بیمه‌نامه</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">هنگامِ صدور (تکی، گروهی و کارکنان) فایلِ بیمه‌نامه فقط همین تعداد صفحه‌ی اول را نگه می‌دارد (پیش‌فرض: ۱). بقیه‌ی صفحه‌ها گم نمی‌شوند و فایلی جدا کنارِ بیمه‌نامه می‌شوند: بدنه ← «شرایط عمومی و خصوصی»، بقیه ← «صفحه‌های تکمیلی». در صدورِ گروهی اگر «صفحه‌های پرداخت هم بیاید» زده شود، فایل کامل می‌ماند. بیمه‌نامه‌هایی که قبلاً صادر شده‌اند دست نمی‌خورند.</p>
                    <div class="float-input">
                        <input type="text" inputmode="numeric" id="policy-pages-input" placeholder=" " dir="ltr" maxlength="2">
                        <label>تعدادِ صفحه (۱ = فقط صفحه‌ی بیمه‌نامه، ۲ = بیمه‌نامه و صفحه‌ی بعدش)</label>
                    </div>
                    <button onclick="savePolicyPagesSetting()" class="w-full bg-rose-500 hover:bg-rose-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-rose-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-save ml-2"></i> ذخیره تعدادِ صفحه
                    </button>
                </div>

                <div data-scat="prereq" class="card p-6 border-slate-200 bg-gradient-to-br from-white to-slate-50 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-slate-200 pb-3"><i class="fas fa-font text-slate-500 ml-2"></i>فونتِ نویسه‌ی «∕» در نامِ بیمه‌نامه‌ها (ویندوز)</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-3">ویندوز «/» را در نامِ فایل قبول نمی‌کند؛ برای همین در نامِ فایل‌ها و پوشه‌های بایگانی شماره‌ی بیمه‌نامه با «∕» (U+2215، «اسلشِ تقسیم») نوشته می‌شود (مثل 1∕49357∕5051-0∕1405∕354). در دیتابیس و پنل همان «/» می‌ماند. این فونت نویسه‌ی «∕» و حروفِ فارسی را دارد.</p>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <a href="Font/prereq/DejaVuSans.ttf" download="DejaVuSans.ttf" class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2.5 rounded-xl text-xs"><i class="fas fa-download ml-1"></i>دانلودِ فونت (DejaVu Sans، TTF)</a>
                        <a href="api/settings_actions.php?action=prereq_test_zip" class="bg-white border border-slate-300 hover:bg-slate-100 text-slate-700 font-bold px-4 py-2.5 rounded-xl text-xs"><i class="fas fa-file-zipper ml-1"></i>فایلِ آزمایشیِ نام (ZIP)</a>
                        <a href="Font/prereq/LICENSE-DejaVu.txt" target="_blank" class="text-slate-500 hover:underline text-[11px] self-center">مجوزِ فونت (رایگان)</a>
                    </div>
                    <ol class="text-xs text-slate-600 leading-7 list-decimal pr-5">
                        <li>فایلِ <b dir="ltr">DejaVuSans.ttf</b> را دانلود کنید، رویش راست‌کلیک کنید و <b>Install for all users</b> (یا Install) را بزنید.</li>
                        <li>«فایلِ آزمایشیِ نام» را دانلود و از ZIP بیرون بیاورید (Extract All). اگر در نامش «∕» درست دیده شد، نامِ بیمه‌نامه‌ها هم درست نشان داده می‌شود.</li>
                        <li>فایلِ تکیِ بیمه‌نامه را با دکمه‌ی «نامِ دقیق» بگیرید؛ مرورگر در دانلودِ تکی این نویسه را از نام حذف می‌کند، ولی داخلِ ZIP نام دقیقاً همان نامِ بایگانی است.</li>
                    </ol>
                </div>

                <div data-scat="general" class="card p-6 border-violet-100 bg-gradient-to-br from-white to-violet-50/30">
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

                <div data-scat="issue" class="card p-6 border-emerald-100 bg-gradient-to-br from-white to-emerald-50/30 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-emerald-100 pb-3 flex items-center flex-wrap gap-2"><span><i class="fas fa-file-excel text-emerald-500 ml-2"></i>ستون‌های اکسلِ درخواستِ شرکت‌ها</span>
                        <a href="api/company_actions.php?action=excel_sample" class="mr-auto text-[11px] font-bold bg-white border border-emerald-200 text-emerald-700 rounded-lg px-3 py-1.5"><i class="fas fa-download ml-1"></i>فایلِ نمونه (همان که شرکت‌ها در پنلشان دارند)</a></h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-3">اکسلی که شرکت‌ها برای درخواست می‌دهند با نامِ سرستون‌ها خوانده می‌شود (ترتیب مهم نیست). برای هر فیلد، نام‌هایی را که شرکت‌ها در فایلشان می‌نویسند اضافه کنید (با «،» جدا کنید)؛ یا فایلِ یک شرکت را بدهید تا سرستون‌هایش را به فیلدها وصل کنید. همین ستون‌ها در ورودِ اکسل در پنل و در پنلِ شرکت‌ها به کار می‌رود.</p>
                    <div id="cxl-root" class="text-xs text-slate-400">در حال بارگذاری…</div>
                </div>

                <div data-scat="issue" class="card p-6 border-teal-100 bg-gradient-to-br from-white to-teal-50/30 md:col-span-2">
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

                <?php if (perm_real_role() === 'ADMIN'): ?>
                <div data-scat="data" class="card p-6 md:col-span-2 border-rose-100 bg-gradient-to-br from-white to-rose-50/30">
                    <h3 class="font-bold text-slate-700 mb-2 border-b border-rose-100 pb-3"><i class="fas fa-trash-can-arrow-up text-rose-500 ml-2"></i>حذف‌شده‌ها <small class="text-[11px] text-slate-400 font-bold mr-1">هر «حذفِ کامل» این‌جا می‌ماند: برگرداندن با همه‌ی جزئیات یا پاک کردنِ دائمی</small></h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-3">معرفی‌نامه، درخواستِ کارکنان، درخواستِ شرکتی و بیمه‌نامه‌ی صادره‌ای که «حذفِ کامل» شده، با اطلاعات، اقساط و تخصیصِ دریافت‌ها، الحاقیه‌ها، مدارک و پوشه‌های بایگانی‌اش این‌جا نگه داشته می‌شود. «برگرداندن» همه را با همان شناسه‌ها سرِ جایش برمی‌گرداند؛ «پاک کردنِ دائمی» فایل‌ها را هم از روی هاست پاک می‌کند.</p>
                    <div id="trash-root"></div>
                </div>
                <?php endif; ?>
                <div data-scat="data" class="card p-0 overflow-hidden md:col-span-2 border-indigo-100">
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

                <div data-scat="data" class="card p-6 border-red-100 bg-gradient-to-br from-white to-red-50/30">
                    <h3 class="font-bold text-red-600 mb-2 border-b border-red-100 pb-3"><i class="fas fa-exclamation-triangle ml-2"></i>پاکسازی و بازنشانی داده‌ها</h3>
                    <p class="text-xs text-slate-500 leading-relaxed mt-2 mb-4">همه‌ی اطلاعات دیتابیس (پرونده‌ها، شرکت‌ها، مالی، اقساط، صورتحساب‌ها، گزارش‌ها، چت‌ها و لاگ‌ها) و همه‌ی فایل‌های بایگانی پاک می‌شوند؛ <b>پیش از حذف، خودکار یک بکاپ کامل در پوشه‌ی backup ساخته می‌شود</b> و اگر بکاپ ساخته نشود چیزی پاک نمی‌شود. (حساب مدیران و تنظیمات پاک نخواهد شد).</p>
                    <button onclick="openResetModal()" class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-red-500/20 hover-target transition-all text-xs">
                        <i class="fas fa-trash-restore ml-2"></i> بازنشانی و حذف کلیه پرونده‌ها
                    </button>
                </div>

                <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
                <div data-scat="general" class="card p-0 md:col-span-2 overflow-hidden" id="mt-card">
                    <div class="p-5 text-white relative overflow-hidden" style="background:linear-gradient(120deg,#0f172a,#3730a3 55%,#0e7490)">
                        <div class="absolute -left-10 -top-12 w-44 h-44 rounded-full" style="background:rgba(255,255,255,.08)"></div>
                        <h3 class="font-black text-base relative"><i class="fas fa-screwdriver-wrench ml-2"></i>حالتِ به‌روزرسانی (بستنِ سایت برای کاربران)</h3>
                        <p class="text-[11.5px] leading-6 mt-1 relative" style="color:rgba(255,255,255,.8)">وقتی فایل‌ها را روی هاست عوض می‌کنید یا تغییرِ بزرگی می‌دهید، سایت را ببندید: همه (همکاران، شرکت‌ها، کارکنان در وب‌اپ) صفحه‌ی «در حالِ به‌روزرسانی» را می‌بینند و فقط مدیر کل کار می‌کند. با «شروع با تأخیر» اول به کاربرانِ آنلاین شمارشِ معکوس نشان داده می‌شود تا کارشان را ذخیره کنند. ربات‌ها و یادآورها کار می‌کنند.</p>
                        <div class="relative mt-3 flex flex-wrap gap-2 items-center" id="mt-state"></div>
                    </div>
                    <div class="p-5 grid md:grid-cols-3 gap-3 text-xs">
                        <label class="md:col-span-3 flex flex-col gap-1 font-bold text-slate-500">پیامِ صفحه (اختیاری)<textarea id="mt-msg" rows="2" maxlength="600" class="border rounded-xl px-3 py-2 text-sm font-normal" placeholder="مثلاً: در حالِ اضافه کردنِ بخشِ تازه‌ی الحاقیه‌ها هستیم."></textarea></label>
                        <label class="flex flex-col gap-1 font-bold text-slate-500">زمانِ تقریبیِ بازگشت (اختیاری)<input id="mt-until" maxlength="60" class="border rounded-xl px-3 py-2 text-sm font-normal" placeholder="مثلاً ساعت ۱۴:۳۰"></label>
                        <label class="flex flex-col gap-1 font-bold text-slate-500">شروعِ بستن<select id="mt-delay" class="border rounded-xl px-3 py-2 text-sm font-normal"><option value="0">همین الان</option><option value="2">۲ دقیقه‌ی دیگر (با شمارشِ معکوس)</option><option value="5">۵ دقیقه‌ی دیگر</option><option value="10">۱۰ دقیقه‌ی دیگر</option><option value="15">۱۵ دقیقه‌ی دیگر</option><option value="30">۳۰ دقیقه‌ی دیگر</option></select></label>
                        <div class="flex items-end gap-2 flex-wrap">
                            <button type="button" id="mt-on" onclick="maintSave(true)" class="bg-rose-600 hover:bg-rose-700 text-white font-black px-4 py-2.5 rounded-xl"><i class="fas fa-lock ml-1"></i>بستنِ سایت</button>
                            <button type="button" id="mt-off" onclick="maintSave(false)" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black px-4 py-2.5 rounded-xl"><i class="fas fa-lock-open ml-1"></i>بازکردنِ سایت</button>
                            <a href="errors/maintenance.php" target="_blank" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2.5 rounded-xl"><i class="fas fa-eye ml-1"></i>پیش‌نمایش</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div data-scat="general" class="card p-6 md:col-span-2">
                    <h3 class="font-bold text-slate-700 mb-2 border-b pb-3"><i class="fas fa-info-circle text-blue-500 ml-2"></i>اطلاعات سیستم</h3>
                    <ul class="text-xs font-bold text-slate-500 space-y-3 mt-4 flex flex-col md:flex-row md:gap-8">
                        <li class="flex items-center gap-2"><span>نسخه سامانه:</span> <span class="text-slate-700 font-mono" dir="ltr">Version 2.0.0</span></li>
                        <li class="flex items-center gap-2"><span>محل بایگانی مدارک:</span> <span class="text-emerald-600 font-mono">/بایگانی (هاست ابری)</span></li>
                        <li class="flex items-center gap-2"><span>موتور هوش مصنوعی:</span> <span class="text-blue-600">PyMuPDF + Regex</span></li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- ======================= قراردادهای پرداخت ======================= -->
        <div id="tab-fin-contracts" class="tab-content max-w-7xl mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo finance_nav('fin-contracts', $canSeeCompanies); ?>
            <div id="fin-contracts-root"></div>
        </div>

        <!-- ======================= تنظیمات مالی ======================= -->
        <div id="tab-fin-settings" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <?php echo finance_nav('fin-settings', $canSeeCompanies); ?>
            <h1 class="text-2xl font-black text-slate-800"><i class="fas fa-money-check-dollar text-emerald-500 ml-2"></i>تنظیمات مالی</h1>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card p-6">
                    <h3 class="font-bold text-slate-700 mb-4 border-b pb-3"><i class="fas fa-calendar-days text-indigo-500 ml-2"></i>دوره مالی و اقساط</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="text-xs font-bold text-slate-600 block mb-2">نوع دوره‌ی مالی</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="border rounded-xl p-3 cursor-pointer hover:bg-indigo-50 flex gap-2 items-start">
                                    <input type="radio" name="fs-pmode" value="cutoff" class="mt-1" onchange="fsPeriodHint()">
                                    <span><b class="text-xs">دوره‌ای</b><br><span class="text-[10px] text-slate-500">از روزِ مشخصِ ماهِ قبل تا همان روزِ این ماه (مثلِ ماموت)</span></span>
                                </label>
                                <label class="border rounded-xl p-3 cursor-pointer hover:bg-indigo-50 flex gap-2 items-start">
                                    <input type="radio" name="fs-pmode" value="calendar" class="mt-1" onchange="fsPeriodHint()">
                                    <span><b class="text-xs">ماه به ماه</b><br><span class="text-[10px] text-slate-500">از ۱ تا آخرِ همان ماه (۳۱، ۳۰ یا ۲۹ روزه)</span></span>
                                </label>
                            </div>
                        </div>
                        <div class="float-input" id="fs-cutoff-wrap">
                            <input type="number" id="fs-cutoff" placeholder=" " dir="ltr" min="1" max="31" oninput="fsPeriodHint()">
                            <label>روزِ پایانِ دوره در هر ماه (پیش‌فرض ۲۵)</label>
                        </div>
                        <p id="fs-period-hint" class="text-[11px] leading-6 text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2 -mt-1"></p>
                        <p class="text-[10px] text-slate-400 -mt-1">همین دوره مبنای «ماهِ معرفی‌نامه»، بایگانیِ کسر از حقوق (ماهِ معرفی‌نامه و ماهِ صدور) و دوره‌های مالی/اقساط است. با تغییرش، دوره‌های باز و پوشه‌های بایگانیِ کسر از حقوق خودکار دوباره مرتب می‌شوند.</p>
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
                            <div class="mt-3 border rounded-xl p-3 bg-sky-50/60 border-sky-200">
                                <label class="text-xs font-bold text-sky-800 block mb-2"><i class="fas fa-building-user ml-1"></i>سررسیدِ ردیف‌های «کارکنان به تفکیکِ شرکت» (مرکز اقساط)</label>
                                <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-600">
                                    قسطِ ۱ = روزِ <input type="number" id="fs-pg-day" min="1" max="31" class="border rounded-lg px-2 py-1 w-16 text-center text-xs font-bold">
                                    از <input type="number" id="fs-pg-offset" min="0" max="12" class="border rounded-lg px-2 py-1 w-16 text-center text-xs font-bold"> ماه بعد از دوره‌ی مالی؛ قسط‌های بعدی هر ماه همان روز
                                </div>
                                <p class="text-[10px] text-slate-400 mt-1">مثلاً «۱۵» و «۱»: بیمه‌نامه‌های دوره‌ی آبانِ شرکتِ فلان => قسطِ ۱ در ۱۵ آذر، قسطِ ۲ در ۱۵ دی، ...</p>
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
                    <?php if ($canManualPersonnel): ?>
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
                <select id="iq-veh" onchange="loadIssueQueue()" class="border rounded-lg px-3 py-2 text-xs" title="خودرو یا موتورسیکلت">
                    <option value="">خودرو و موتورسیکلت</option><option value="CAR">فقط خودرو</option><option value="MOTOR">فقط موتورسیکلت</option>
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
                    <button onclick="downloadIssuedBulk()" title="فایلِ همه‌ی بیمه‌نامه‌های همین نتیجه‌ی فیلتر در یک ZIP (پوشه‌ی هر شرکت و «کارکنان»)" class="bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-lg font-bold text-sm"><i class="fas fa-file-zipper ml-1"></i> دانلود گروهیِ فایل‌ها</button>
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
                <select id="il-veh" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs" title="خودرو یا موتورسیکلت">
                    <option value="">خودرو و موتورسیکلت</option><option value="CAR">فقط خودرو</option><option value="MOTOR">فقط موتورسیکلت</option>
                </select>
                <select id="il-company" onchange="ilRequestOptions(); loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی شرکت‌ها</option></select>
                <select id="il-request" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs max-w-[230px]" title="فقط بیمه‌نامه‌های یک نامه / درخواست"><option value="">همه‌ی نامه‌ها / درخواست‌ها</option></select>
                <select id="il-month" onchange="jMonthToRange(this.value, 'il-from', 'il-to'); loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs" title="ماهِ صدور"><option value="">همه‌ی ماه‌ها (صدور)</option></select>
                <input type="text" id="il-from" oninput="debouncedIssuedList()" placeholder="صدور از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="il-to" oninput="debouncedIssuedList()" placeholder="صدور تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <select id="il-kind" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی انواع درخواست</option><option value="NEW_POLICY">صدور جدید</option><option value="ENDORSEMENT">الحاقیه</option><option value="CANCELLATION">فسخ</option></select>
                <select id="il-insurer" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs"><option value="">همه‌ی بیمه‌گرها</option><option value="PASARGAD">پاسارگاد</option><option value="IRAN">ایران</option></select>
                <select id="il-import" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs" title="برچسبِ بایگانی وارداتی"><option value="">با و بدونِ «بایگانی وارداتی»</option><option value="1">فقط بایگانی وارداتی</option><option value="0">بدونِ بایگانی وارداتی</option></select>
                <select id="il-customer" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs" title="مشتریِ شخصی یا شرکت"><option value="">شرکت‌ها و شخصی</option><option value="C">فقط شرکت‌ها</option><option value="P">فقط درخواست‌های شخصی</option></select>
                <select id="il-endo" onchange="loadIssuedList()" class="border rounded-lg px-3 py-2 text-xs" title="الحاقیه"><option value="">با و بدونِ الحاقیه</option><option value="has">دارای الحاقیه</option><option value="cancelled">فسخ‌شده</option><option value="none">بدونِ الحاقیه</option></select>
                <input type="text" id="il-exp-from" oninput="debouncedIssuedList()" placeholder="انقضای قبلی از ۱۴۰۵/۰۷/۰۱" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
                <input type="text" id="il-exp-to" oninput="debouncedIssuedList()" placeholder="انقضای قبلی تا ۱۴۰۵/۰۷/۳۰" dir="ltr" class="border rounded-lg px-3 py-2 text-xs">
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto max-h-[62vh]">
                    <table class="w-full text-right text-xs whitespace-nowrap">
                        <thead class="bg-emerald-50 text-emerald-800 font-bold sticky top-0 z-10"><tr>
                            <th class="p-3">#</th><th class="p-3">منبع</th><th class="p-3">نوع درخواست</th>
                            <th class="p-3">بیمه‌گذار</th><th class="p-3">پرسنل / شرکت و درخواست</th><th class="p-3">پلاک / شاسی</th>
                            <th class="p-3">نوع</th><th class="p-3">شماره بیمه‌نامه</th><th class="p-3">خودرو</th>
                            <th class="p-3">حق بیمه</th><th class="p-3">تاریخ درخواست</th><th class="p-3">انقضا</th>
                            <th class="p-3">تاریخ صدور</th><th class="p-3">وضعیت</th><th class="p-3">فایل</th>
                            <?php if (perm_real_role() === 'ADMIN'): ?><th class="p-3">عملیات</th><?php endif; ?>
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

        <?php if (!$isLiaison): ?>
        <!-- ======================= بایگانی وارداتی (اکسلِ بیمه‌گر) - import-archive.js ======================= -->
        <div id="tab-import-archive" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('import-archive', $isLiaison); ?>
            <div id="imp-root"></div>
        </div>
        <!-- ======================= مغایرت‌گیری با پاسارگاد - recon.js ======================= -->
        <!-- استعلامات: شخص حقیقی، کد پستی، شخص حقوقی؛ تکی، گروهی، تاریخچه، تنظیمات (inquiry.js) -->
        <div id="tab-inquiries" class="tab-content max-w-[1400px] mx-auto w-full flex-1 hidden" data-perm-skip><div id="inq-root"></div></div>
        <!-- هوشیکس X ai: گفتگو با هوشِ مصنوعی (hooshix.js) -->
        <div id="tab-hooshix" class="tab-content max-w-[1400px] mx-auto w-full flex-1 hidden" data-perm-skip><div id="hx-root"></div></div>
        <!-- پرونده‌ی خودروها، پرسنل و بردروی پاسارگاد (registry.js) -->
        <div id="tab-vehicles" class="tab-content max-w-[1600px] mx-auto w-full flex-1 hidden"><div id="vh-root"></div></div>
        <div id="tab-personnel" class="tab-content max-w-[1600px] mx-auto w-full flex-1 hidden"><div id="per-root"></div></div>
        <div id="tab-bordereau" class="tab-content max-w-[1600px] mx-auto w-full flex-1 hidden"><div id="brd-root"></div></div>
        <div id="tab-pasargad-recon" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('pasargad-recon', $isLiaison); ?>
            <div id="recon-root" class="space-y-4"></div>
        </div>
        <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
        <!-- ======================= ورودِ بایگانی‌های قبلی - legacy-import.js ======================= -->
        <div id="tab-legacy-import" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('legacy-import', $isLiaison); ?>
            <div id="legacy-root" class="space-y-4"></div>
        </div>
        <?php endif; ?>
        <!-- ======================= الحاقیه‌ها - endorse.js ======================= -->
        <div id="tab-endorsements" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('endorsements', $isLiaison); ?>
            <div id="endo-root" class="space-y-4"></div>
        </div>
        <!-- ======================= استعلام‌های حق بیمه‌ی مینی‌اپ - miniapp-admin.js ======================= -->
        <div id="tab-premium-inquiries" class="tab-content max-w-[1600px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('premium-inquiries', $isLiaison); ?>
            <div id="pqi-root"></div>
        </div>
        <!-- ======================= اعلام تمدید - renewals.js ======================= -->
        <div id="tab-renewals" class="tab-content max-w-[1700px] mx-auto w-full space-y-4 flex-1 hidden">
            <?php echo issuance_nav('renewals', $isLiaison); ?>
            <div id="rn-root"></div>
        </div>
        <?php endif; ?>

        <!-- درخواست‌های شخصی: اشخاصِ حقیقی و شرکت‌هایی که هنوز قرارداد ندارند (بایگانی شخصی، مالیِ جدا؛ بعد از صدور در بایگانی صادره هم) -->
        <div id="tab-personal-requests" class="tab-content max-w-7xl mx-auto w-full space-y-6 flex-1 hidden">
            <div class="preq-hero">
                <div><h1 class="text-2xl font-black"><i class="fas fa-user-shield ml-2"></i>درخواست‌های شخصی</h1>
                    <p class="text-[11.5px] opacity-90 mt-1 leading-6">مشتریانِ شخصی (اشخاص و شرکت‌هایی که هنوز با ما قرارداد ندارند): هر درخواست در «بایگانی شخصی» پوشه می‌گیرد، مالیِ هر مشتری جداست و بعد از صدور، بیمه‌نامه در «بایگانی صادره» هم می‌نشیند.</p></div>
                <div class="flex items-center gap-2 flex-wrap">
                    <button onclick="loadPersonalRequests()" class="bg-white/15 hover:bg-white/25 text-white px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-sync-alt"></i></button>
                    <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
                    <button data-perm="create" onclick="CompanyManualRequest.open({personal: true})" class="bg-white text-indigo-700 hover:bg-indigo-50 px-4 py-2 rounded-xl text-xs font-black shadow"><i class="fas fa-plus ml-1"></i>درخواستِ شخصیِ تازه</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card p-4 space-y-3">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                    <div class="relative lg:col-span-2"><i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                        <input type="search" id="preq-q" oninput="clearTimeout(window.__preqT); window.__preqT = setTimeout(loadPersonalRequests, 350)" placeholder="نام یا کد ملیِ مشتری، پلاک، شاسی، بیمه‌نامه..." class="w-full border rounded-xl pr-8 pl-3 py-2 text-xs"></div>
                    <select id="preq-company" onchange="loadPersonalRequests()" class="border rounded-xl px-3 py-2 text-xs font-bold"><option value="">همه‌ی مشتری‌ها</option></select>
                    <select id="preq-month" onchange="loadPersonalRequests()" class="border rounded-xl px-3 py-2 text-xs font-bold"><option value="">همه‌ی ماه‌ها</option></select>
                    <select id="preq-status-filter" onchange="loadPersonalRequests()" class="border rounded-xl px-3 py-2 text-xs font-bold">
                        <option value="">همه‌ی وضعیت‌ها</option><option value="NEW">جدید</option><option value="DOCS_REVIEW">در حال بررسی</option><option value="READY_FOR_ISSUE">آماده‌ی صدور</option><option value="ISSUED">صادر شده</option><option value="CANCELLED">لغو شده</option></select>
                    <select id="preq-insurer" onchange="loadPersonalRequests()" class="border rounded-xl px-3 py-2 text-xs font-bold">
                        <option value="">پاسارگاد و ایران</option><option value="PASARGAD">پاسارگاد</option><option value="IRAN">ایران</option></select>
                </div>
                <div id="preq-summary" class="flex flex-wrap items-center gap-2 text-[11px]"></div>
            </div>
            <div class="card overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-slate-500 text-[11px]"><tr>
                        <th class="p-3 text-right">درخواست و مشتری</th><th class="p-3 text-right">پیشرفتِ صدور</th>
                        <th class="p-3 text-right">مرحله‌ی ردیف‌ها</th>
                        <th class="p-3 text-right">وضعیت و تاریخ</th><th class="p-3 text-left"></th>
                    </tr></thead>
                    <tbody id="preq-body" class="creq-list"><tr><td colspan="5" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
                </table>
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
                    <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): // ثبت دستی فقط برای مدیر کل ?>
                    <button data-perm="create" onclick="CompanyManualRequest.open(CREQ_SCOPE === 'single' ? {single: true} : {})" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fas fa-plus ml-1"></i>ثبت دستی درخواستِ گروهی</button>
                    <button data-perm="create" onclick="CompanyManualRequest.open({single: true})" class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg text-xs font-bold" title="یک خودرو؛ نامه اختیاری (نامه یا اسکرین‌شاتِ چت)"><i class="fas fa-car ml-1"></i>درخواستِ تکی</button>
                    <button onclick="openAdminNewRequestModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-lg text-xs font-bold" title="فقط ساخت درخواست خالی (ردیف‌ها و مدارک را بعداً اضافه می‌کنید)"><i class="fas fa-file ml-1"></i>درخواست خالی</button>
                    <?php endif; ?>
                </div>
            </div>
            <!-- درخواست‌های گروهی (نامه و چند خودرو) / تکی (یک خودرو؛ نامه اختیاری) -->
            <div class="creq-scopes" id="creq-scopes">
                <button type="button" data-scope="group" onclick="creqSetScope('group')" class="on"><i class="fas fa-layer-group ml-1"></i>درخواست‌های گروهی <b data-sc="group"></b><small>نامه و چند خودرو</small></button>
                <button type="button" data-scope="single" onclick="creqSetScope('single')"><i class="fas fa-car ml-1"></i>درخواست‌های تکی <b data-sc="single"></b><small>یک خودرو؛ نامه یا اسکرین‌شاتِ چت</small></button>
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
                    <thead class="bg-slate-50 text-slate-500 text-[11px]"><tr>
                        <th class="p-3 text-right">درخواست</th><th class="p-3 text-right">پیشرفتِ صدور</th>
                        <th class="p-3 text-right">مرحله‌ی ردیف‌ها</th>
                        <th class="p-3 text-right">وضعیت و تاریخ</th><th class="p-3 text-left"></th>
                    </tr></thead>
                    <tbody id="creq-body" class="creq-list"><tr><td colspan="5" class="text-center p-8 text-slate-400">در حال بارگذاری...</td></tr></tbody>
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
                <select id="cm-f-info" onchange="renderCompanyCards()" class="border-2 border-slate-200 rounded-xl px-3 py-2 text-xs font-bold"><option value="">همه (کامل و ناقص)</option><option value="INCOMPLETE">اطلاعات ناقص</option><option value="INTRO">ساخته‌شده از معرفی‌نامه</option></select>
                <button onclick="loadCompanyManage()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-sync-alt ml-1"></i>بروزرسانی</button>
                <button onclick="switchTab('staff-users'); setUserTypeFilter('COMPANY')" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 px-3 py-2 rounded-xl text-xs font-bold"><i class="fas fa-users ml-1"></i>کاربرانِ شرکت‌ها</button>
                <span id="cm-count" class="mr-auto text-[11px] font-bold text-slate-400"></span>
            </div>
            <div id="cm-cards" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4"><p class="text-center text-slate-400 text-xs py-10 col-span-full">در حال بارگذاری...</p></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($lifeAccess): ?>
        <!-- ======================= بیمه عمر - life.js ======================= -->
        <?php foreach (array_merge(['life-dash', 'life-policies', 'life-import', 'life-archive', 'life-settings'], $mkAccess ? ['mk-dash', 'mk-sales', 'mk-people', 'mk-settings'] : []) as $__lt): ?>
        <div id="tab-<?php echo $__lt; ?>" class="tab-content max-w-[1700px] mx-auto w-full space-y-4 flex-1 hidden"><div id="<?php echo $__lt; ?>-root" class="life-root"></div></div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($hlAccess): ?>
        <!-- ======================= مالیِ درمان تکمیلی - health.js ======================= -->
        <?php foreach (['hl-dash', 'hl-ledger', 'hl-receipts', 'hl-members', 'hl-contracts', 'hl-pay', 'hl-settle', 'hl-settings'] as $__lt): ?>
        <div id="tab-<?php echo $__lt; ?>" class="tab-content max-w-[1700px] mx-auto w-full space-y-4 flex-1 hidden"><div id="<?php echo $__lt; ?>-root"></div></div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($ltAccess): ?>
        <!-- ======================= اتوماسیون نامه‌ها - letters.js ======================= -->
        <?php foreach (['lt-box', 'lt-settings'] as $__lt): ?>
        <div id="tab-<?php echo $__lt; ?>" class="tab-content max-w-[1700px] mx-auto w-full space-y-4 flex-1 hidden"><div id="<?php echo $__lt; ?>-root"></div></div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php // فوتر: متن، رنگ و نمایشش از «تنظیمات ← فوتر» (api/_footer.php)؛ نسخه‌ی جاری همین‌جا نوشته می‌شود
        require_once __DIR__ . '/api/_footer.php'; $__ver = 'Version:1.4.0'; $__ft = footer_html($pdo, 'panel', $__ver); ?>
        <div class="footer-credit mt-auto" id="site-footer"<?php echo $__ft === '' ? ' style="display:none"' : ''; ?>><?php echo $__ft; ?></div>
        <script>window.SITE_VERSION = <?php echo json_encode($__ver); ?>;</script>
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
            <div id="creq-lock" data-lock=""></div>
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
                        <input type="file" data-uk-skip id="bdl-file" accept="application/pdf,.pdf" class="hidden" onchange="bundlePicked(this)">
                    </label>
                    <button id="bdl-analyze-btn" onclick="bundleAnalyze()" disabled class="w-full mt-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 text-white font-bold py-2.5 rounded-xl text-sm"><i class="fas fa-magnifying-glass-chart ml-1"></i>بررسی و شناسایی</button>
                </div>
                <div id="bdl-progress" class="hidden text-center py-10">
                    <div class="bdl-spinner mx-auto mb-4"></div>
                    <p class="font-black text-slate-700" id="bdl-progress-text">در حالِ جدا کردنِ صفحه‌ها و شناسایی…</p>
                    <div class="max-w-md mx-auto mt-3 h-2.5 rounded-full bg-slate-100 overflow-hidden"><div id="bdl-progress-bar" class="h-full bg-gradient-to-l from-indigo-600 to-violet-500 transition-all" style="width:0%"></div></div>
                    <p class="text-[11px] text-slate-400 mt-2">می‌توانید این پنجره را ببندید و کارِ دیگری بکنید؛ پیشرفت پایینِ صفحه دیده می‌شود و وقتی تمام شد خبر می‌دهد.</p>
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
                    <input type="file" id="ancr-letter-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif" class="w-full border-2 border-dashed border-slate-300 rounded-xl p-2.5 text-xs bg-slate-50"></label>
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
            <div class="pk-switch" data-vkfor="aap"><button type="button" data-vk="car" class="on">🚗 خودرو</button><button type="button" data-vk="moto">🏍 موتورسیکلت</button></div>
            <div id="aap-moto" class="mt-pin mb-3 hidden-pk" dir="ltr"><span class="ip-flag"></span><span class="mt-rows">
                <input type="text" id="aap-mcity" maxlength="3" inputmode="numeric" placeholder="کد شهر ۵۸۳" class="vk-digits">
                <input type="text" id="aap-mnum" maxlength="5" inputmode="numeric" placeholder="۷۶۲۲۴" class="vk-digits"></span></div>
            <div class="ir-pin mb-3" dir="ltr" id="aap-carpin">
                <span class="ip-flag"></span>
                <input type="text" id="aap-p4" maxlength="2" inputmode="numeric" placeholder="۱۲" class="ip-cell">
                <input type="text" id="aap-letter" maxlength="3" placeholder="الف" list="plate-letters-dl" class="ip-cell ip-letter">
                <input type="text" id="aap-p2" maxlength="3" inputmode="numeric" placeholder="۳۴۵" class="ip-cell ip-wide">
                <span class="ip-ir"><small>ایران</small><input type="text" id="aap-p1" maxlength="2" inputmode="numeric" placeholder="۶۷"></span>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 mb-2">
                <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer"><input type="checkbox" id="aap-noplate" onchange="toggleNoPlate('aap')"> پلاک ندارد (لیفتراک / ماشین‌آلات)</label>
                <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer"><input type="checkbox" id="aap-isnew" onchange="toggleNoPlate('aap')"> صفر کیلومتر</label>
            </div>
            <div id="aap-chassis-box" class="grid grid-cols-2 gap-3 hidden">
                <div class="float-input"><input type="text" id="aap-chassis" dir="ltr" placeholder=" "><label>شماره شاسی</label></div>
                <div class="float-input"><input type="text" id="aap-engine" dir="ltr" placeholder=" "><label>شماره موتور</label></div>
            </div>
            <div id="aap-reg"></div>
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input"><input type="text" id="aap-car-name" placeholder=" "><label>نام خودرو (مثلاً پژو ۲۰۶)</label></div>
                <div class="float-input"><input type="text" id="aap-car-type" placeholder=" "><label>تیپ خودرو (مثلاً تیپ ۵)</label></div>
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
                <div class="float-input" id="aap-expiry-box"><input type="text" id="aap-expiry" dir="ltr" inputmode="numeric" placeholder=" "><label>تاریخ انقضا (شمسی، مثلاً ۱۴۰۵/۰۷/۳۰)</label></div>
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
    <!-- میزِ بررسیِ مدارک: فایل سمتِ راست، فیلدها سمتِ چپ؛ «بعدی/قبلی» بدونِ بستنِ پنجره، و هر وقت بستید بقیه سرِ جایش می‌ماند -->
    <div id="cinbox-tag-modal" class="modal-overlay">
        <div class="modal-content cit-ws relative p-0">
            <div class="cit-top">
                <div class="min-w-0 flex-1">
                    <h3 class="font-black text-base flex items-center gap-2"><i class="fas fa-file-circle-check text-blue-500"></i>بررسیِ مدارک <span id="cit-counter" class="text-[11px] font-bold bg-blue-50 text-blue-700 rounded-full px-2.5 py-0.5"></span></h3>
                    <p id="cit-docname" class="text-[11px] text-slate-400 truncate" dir="auto"></p>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" id="cit-prev" onclick="citNav(-1)" class="cit-nb" title="مدرکِ قبلی"><i class="fas fa-chevron-right"></i><span class="hidden sm:inline">قبلی</span></button>
                    <button type="button" id="cit-next" onclick="citNav(1)" class="cit-nb" title="مدرکِ بعدی (بدونِ ثبت)"><span class="hidden sm:inline">بعدی</span><i class="fas fa-chevron-left"></i></button>
                    <button type="button" onclick="citClose()" class="cit-nb" title="بستن؛ بقیه‌ی مدارک برای بعد می‌ماند"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="cit-body">
            <div class="cit-view">
                <div class="cit-tools">
                    <button type="button" onclick="citZoom(1)" title="بزرگ‌تر"><i class="fas fa-magnifying-glass-plus"></i></button>
                    <button type="button" onclick="citZoom(-1)" title="کوچک‌تر"><i class="fas fa-magnifying-glass-minus"></i></button>
                    <button type="button" onclick="citRotate()" title="چرخاندن"><i class="fas fa-rotate-right"></i></button>
                    <a id="cit-open-tab" href="#" target="_blank" title="باز کردن در تبِ جدید"><i class="fas fa-up-right-from-square"></i></a>
                </div>
                <!-- پیش‌نمایشِ خودِ فایل، تا بشود دید این مدرک چیست و مالِ کدام پلاک است -->
                <div id="cit-preview" class="cit-pv"></div>
            </div>
            <div class="cit-form">
            <p class="text-[11px] text-slate-400 mb-3">مشخص کنید این مدرک مالِ کدام خودروی درخواست و چه نوع مدرکی است؛ سیستم خودش نام‌گذاری می‌کند و در پوشه‌ی همان ردیف می‌گذارد. با «ثبت و بعدی» مدرکِ بعدی همین‌جا باز می‌شود.</p>
            <input type="hidden" id="cit-doc-id">
            <!-- PDFِ چندصفحه‌ای: پیشنهادِ جدا کردنِ صفحه‌ها (هر صفحه یک مدرکِ جدا برای تگ‌گذاری) -->
            <div id="cit-split" class="hidden mb-3 rounded-xl border border-amber-200 bg-amber-50 p-2.5 text-[11px] text-amber-800 flex items-center justify-between gap-2"></div>
            <!-- نتیجه‌ی شناساییِ خودکار (فاکتور فروش، سند، کارت، بیمه‌نامه‌ی قبلی) + ساختِ ردیف از همین اطلاعات -->
            <div id="cit-ai" class="hidden mb-3"></div>

            <div class="float-input"><select id="cit-request" onchange="onCitRequestChange()"></select><label>درخواست مربوطه</label></div>

            <div class="float-input">
                <select id="cit-doc-type" onchange="onCitDocTypeChange()">
                    <option value="">-- اول نوع فایل را انتخاب کنید --</option>
                    <option value="letter">نامه‌ی درخواست (روی خودِ درخواست می‌نشیند، نه یک پلاک)</option>
                    <option value="ownership_doc">سند</option>
                    <option value="car_card_front">روی کارت ماشین</option>
                    <option value="car_card_back">پشت کارت ماشین</option>
                    <option value="sales_invoice">فاکتور فروش (صفر کیلومتر)</option>
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

            <div class="flex gap-2 sticky bottom-0 bg-white pt-2 pb-1">
                <button onclick="submitAssignDocument()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl text-sm" title="Ctrl+Enter"><i class="fas fa-check ml-1"></i><span id="cit-submit-lbl">ثبت و بعدی</span></button>
                <button onclick="rejectDocument(document.getElementById('cit-doc-id').value)" class="bg-red-50 hover:bg-red-100 text-red-600 font-bold py-2.5 px-4 rounded-xl text-sm whitespace-nowrap">رد کردن</button>
            </div>
            </div>
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
                        <input type="file" id="cis-file" accept=".pdf,.jpg,.jpeg,.png,.heic,.heif" class="hidden" onchange="document.getElementById('cis-file-name').textContent = this.files[0] ? this.files[0].name : 'انتخابِ فایل'; if (this.files[0]) validateCompanyPolicy();">
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
            <div id="cre-lock" data-lock=""></div>
            <input type="hidden" id="cre-plate-id">
            <label class="text-xs font-bold text-slate-500 block mb-2">پلاک</label>
            <div class="pk-switch" data-vkfor="cre"><button type="button" data-vk="car" class="on">🚗 خودرو</button><button type="button" data-vk="moto">🏍 موتورسیکلت</button></div>
            <div id="cre-moto" class="mt-pin mb-3 hidden-pk" dir="ltr"><span class="ip-flag"></span><span class="mt-rows">
                <input type="text" id="cre-mcity" maxlength="3" inputmode="numeric" placeholder="کد شهر ۵۸۳" class="vk-digits">
                <input type="text" id="cre-mnum" maxlength="5" inputmode="numeric" placeholder="۷۶۲۲۴" class="vk-digits"></span></div>
            <div class="ir-pin mb-3" dir="ltr" id="cre-carpin">
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
                <div class="float-input" id="cre-expiry-box"><input type="text" id="cre-expiry" dir="ltr" placeholder=" "><label>تاریخ انقضا (۱۴۰۵/۰۷/۳۰)</label></div>
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
                <input type="checkbox" id="cre-noplate" onchange="toggleNoPlate('cre')"> پلاک ندارد (لیفتراک / ماشین‌آلات)</label>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-600 mb-2 cursor-pointer">
                <input type="checkbox" id="cre-isnew" onchange="toggleNoPlate('cre')"> صفر کیلومتر (بدونِ بیمه‌نامه‌ی قبلی و بازدیدِ سلامت)
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
            <div class="grid grid-cols-2 gap-3">
                <div class="float-input"><input type="text" id="cre-car-name" placeholder=" "><label>نام خودرو</label></div>
                <div class="float-input"><input type="text" id="cre-car-type" placeholder=" "><label>تیپ خودرو</label></div>
            </div>
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
            <div class="flex gap-1.5 mb-2 text-[11px] font-bold" id="crows-text-mode">
                <button type="button" data-m="0" onclick="setRowsTextMode(0)" class="px-3 py-1.5 rounded-lg bg-slate-800 text-white">همه‌ی ردیف‌ها</button>
                <button type="button" data-m="1" onclick="setRowsTextMode(1)" class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600">فقط ردیف‌های دارای کسری</button>
            </div>
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
                <p id="cli-ai-prompt" class="text-[10.5px] text-slate-600 leading-relaxed"><?php require_once __DIR__ . '/api/_doc_smart.php'; echo nl2br(htmlspecialchars(ds_prompt($pdo, 'letter'), ENT_QUOTES, 'UTF-8')); ?></p>
            </div>
            <input type="hidden" id="cli-request-id">
            <div class="rounded-xl border border-fuchsia-200 bg-gradient-to-l from-fuchsia-50 to-violet-50 p-3 mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="text-[11px] text-slate-600 leading-6"><b class="text-fuchsia-800"><i class="fas fa-brain ml-1"></i>خواندنِ هوشمندِ نامه:</b> نامه‌ی همین درخواست (یا فایلی که انتخاب می‌کنید) OCR می‌شود، متنش به هوشِ مصنوعی داده می‌شود و ردیف‌ها در پیش‌نمایش می‌آیند؛ بعد از بررسی «ساختن ردیف‌ها» را بزنید.
                    <span id="cli-smart-st" class="block text-[10.5px] text-fuchsia-700 font-bold"></span></div>
                <span class="flex gap-2"><input type="file" id="cli-smart-file" accept="application/pdf,image/*" multiple class="hidden" onchange="cliSmartRead(this.files)">
                    <button type="button" id="cli-smart-btn" onclick="cliSmartRead()" class="bg-fuchsia-600 hover:bg-fuchsia-700 text-white text-[11px] font-black px-3 py-2 rounded-xl"><i class="fas fa-wand-magic-sparkles ml-1"></i>خواندنِ نامه‌ی درخواست</button>
                    <button type="button" onclick="document.getElementById('cli-smart-file').click()" class="bg-white border border-fuchsia-200 text-fuchsia-700 text-[11px] font-bold px-3 py-2 rounded-xl"><i class="fas fa-file-arrow-up ml-1"></i>فایلِ دیگر</button></span>
            </div>

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
      "car_name": "پژو 206", "car_type": "تیپ 5", "model_year": "1400",
      "car_value": "5,000,000,000",
      "has_prev_body": "بله",
      "health_inspection": "لازم"
    },
    {
      "request": "صدور",
      "moto_city": "583", "moto_number": "76224",
      "insurance_type": "ثالث",
      "expiry_date": "1405/08/12",
      "car_name": "موتورسیکلت هوندا 125"
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
            <p class="text-[10.5px] text-slate-500 -mt-2 mb-3">ستون‌های اکسل از «تنظیمات ← صدور و استعلام ← ستون‌های اکسلِ شرکت‌ها» قابلِ تعریف است. <a href="api/company_actions.php?action=excel_sample" class="text-blue-600 font-bold">دانلودِ فایلِ نمونه</a></p>
            <div data-cli-def class="rounded-xl border border-indigo-100 bg-indigo-50/50 px-3 py-2 mb-3 flex items-center justify-between gap-2 flex-wrap">
                <div class="text-[10.5px] text-slate-600 leading-6"><span class="font-black text-indigo-700"><i class="fas fa-sliders ml-1"></i>پیش‌فرضِ ردیف‌ها (اگر در نامه نیامده):</span> <span id="cli-def-summary">—</span></div>
                <button type="button" onclick="cliPickDefaults()" class="text-[10.5px] font-bold bg-white border border-indigo-200 text-indigo-700 hover:bg-indigo-50 rounded-lg px-2.5 py-1"><i class="fas fa-pen ml-1"></i>تغییر</button>
            </div>
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
                    <h4><i class="fas fa-building-circle-check ml-1"></i>مشخصاتِ ثبتی <small class="text-[10.5px] text-slate-400 font-bold">استعلام (پاسارگاد، بعد سرویسِ API) یا ورودِ دستی</small></h4>
                    <div class="flex gap-2 items-start">
                        <div class="float-input flex-1"><input type="text" id="cm-national-id" dir="ltr" inputmode="numeric" maxlength="14" placeholder=" "><label>شناسه‌ی ملیِ شرکت (۱۱ رقم)</label></div>
                        <button type="button" id="cm-inquiry-btn" onclick="cmInquiry()" class="shrink-0 bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold px-4 rounded-xl h-[46px]"><i class="fas fa-magnifying-glass ml-1"></i>استعلام از سامانه</button>
                    </div>
                    <div id="cm-inquiry-msg" class="hidden text-[11px] rounded-xl p-2.5 mb-3 leading-6"></div>
                    <div id="cm-inquiry-cap" class="mb-3"></div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="float-input"><input type="text" id="cm-company-type" placeholder=" "><label>نوعِ شرکت</label></div>
                        <div class="float-input"><input type="text" id="cm-register-number" dir="ltr" placeholder=" "><label>شماره‌ی ثبت</label></div>
                        <div class="float-input"><input type="text" id="cm-register-date" dir="ltr" placeholder=" "><label>تاریخِ ثبت (۱۴۰۰/۰۱/۰۱)</label></div>
                        <div class="float-input"><input type="text" id="cm-postal-code" dir="ltr" inputmode="numeric" placeholder=" "><label>کدپستی</label></div>
                        <div class="float-input"><input type="text" id="cm-province" placeholder=" "><label>استان</label></div>
                        <div class="float-input"><input type="text" id="cm-city" placeholder=" "><label>شهر</label></div>
                    </div>
                    <div class="float-input !mb-0">
                        <select id="cm-legal-active"><option value="">نامشخص</option><option value="1">فعال</option><option value="0">غیرفعال (منحل / تعلیق)</option></select>
                        <label>وضعیتِ ثبتی</label>
                    </div>
                    <input type="hidden" id="cm-legal-checked" value="">
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
                    <h4><i class="fas fa-sack-dollar ml-1"></i>قراردادِ پرداخت و بیمه‌گر</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="float-input">
                            <select id="cm-contract" onchange="cmContractHint()"><option value="">— بدونِ قرارداد —</option></select>
                            <label>قراردادِ پرداخت</label>
                        </div>
                        <div class="float-input">
                            <select id="cm-allowed-insurers">
                                <option value="BOTH">پاسارگاد و ایران (هردو)</option>
                                <option value="PASARGAD">فقط پاسارگاد</option>
                                <option value="IRAN">فقط ایران</option>
                            </select>
                            <label>بیمه‌گر(های) مجاز</label>
                        </div>
                        <div class="float-input sm:col-span-2">
                            <select id="cm-other-owner">
                                <option value="">ذینفع شود · بیمه‌گذار: خودِ شرکت (پیش‌فرض)</option>
                                <option value="OWNER">بیمه‌گذار: همان مالکِ مدرک</option>
                            </select>
                            <label>اگر مالکِ سند/فاکتورِ خودرو کسِ دیگری بود</label>
                        </div>
                        <div class="sm:col-span-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 space-y-1.5">
                            <p class="text-[11px] font-black text-slate-600"><i class="fas fa-cloud-arrow-up ml-1 text-blue-500"></i>بارگذاریِ مدارک در پنلِ این شرکت</p>
                            <label class="flex items-start gap-2 text-[11px] font-bold text-slate-600 cursor-pointer"><input type="checkbox" id="cm-portal-general" class="mt-0.5 accent-blue-600" checked>
                                <span>می‌توانند «مدارکِ کلی» بفرستند (چند فایل، زیپ، پوشه، بدونِ ردیفِ مشخص)<small class="block text-slate-400 font-normal">خاموش = فقط بعد از اینکه ردیف‌ها را ساختید، مدارکِ هر خودرو را در چک‌لیستِ همان خودرو بارگذاری می‌کنند</small></span></label>
                            <label class="flex items-start gap-2 text-[11px] font-bold text-slate-600 cursor-pointer"><input type="checkbox" id="cm-portal-custom" class="mt-0.5 accent-blue-600" checked>
                                <span>می‌توانند برای هر خودرو «مدرکِ دیگر» با نامِ دلخواه (جز مدارکِ پیش‌فرض) اضافه کنند</span></label>
                            <label class="block text-[11px] font-bold text-slate-600">خواندنِ خودکارِ مدارکِ این شرکت
                                <select id="cm-doc-read" class="mt-1 w-full border border-slate-200 rounded-lg px-2 py-1.5 text-[11px] bg-white">
                                    <option value="0">خاموش — فقط بارگذاری و بایگانی (نوع‌گذاریِ دستی)</option>
                                    <option value="1">الگوریتمِ سایت — PDFِ متنی (و اسکن با «خواندنِ اسکن» اگر تنظیم شده)</option>
                                    <option value="2">پردازشِ هوشمند — OCR و بعد پرامپتِ مخصوصِ هر نوع مدرک (کارت، سند، فاکتور، بیمه‌نامه)</option></select>
                                <small class="block text-slate-400 font-normal mt-1">در هر حالت دکمه‌های «هوشمند» و «شناساییِ دوباره» در صندوقِ مدارک دستی کار می‌کنند. موتورها و پرامپت‌ها: استعلامات ← تنظیمات ← پردازش فایل هوشمند.</small></label>
                        </div>
                        <div class="sm:col-span-2 rounded-xl border border-indigo-100 bg-indigo-50/50 px-3 py-2.5">
                            <div class="flex items-center justify-between gap-2 flex-wrap">
                                <p class="text-[11px] font-black text-indigo-700"><i class="fas fa-sliders ml-1"></i>پیش‌فرضِ درخواست‌های این شرکت</p>
                                <button type="button" onclick="cmPickDefaults()" class="text-[10.5px] font-bold bg-white border border-indigo-200 text-indigo-700 hover:bg-indigo-50 rounded-lg px-2.5 py-1"><i class="fas fa-pen ml-1"></i>تعیین / تغییر</button>
                            </div>
                            <p id="cm-def-summary" class="text-[10.5px] text-slate-600 mt-1.5 leading-6">—</p>
                            <p class="text-[10px] text-slate-400">در ثبتِ دستی، ورود از نامه و ردیف‌هایی که شرکت در پنلش می‌سازد، تعهد/پوششِ خالی از همین پر می‌شود؛ هر درخواست و هر ردیف را هم می‌شود جدا عوض کرد.</p>
                        </div>
                    </div>
                    <div class="float-input mt-3">
                        <select id="cm-slip-mode">
                            <option value="">پیش‌فرضِ کلیِ صدور (مرکز صدور ← فیش‌ها)</option>
                            <option value="auto">طبقِ فیش‌های بیمه‌نامه (اگر قرارداد یکی بود)</option>
                            <option value="site">اگر فیش فرق داشت: اقساط طبقِ قرارداد و فیش را سامانه بسازد</option>
                            <option value="site_all">اگر فیش فرق داشت یا فیشی نبود: سامانه فیش بسازد</option>
                        </select>
                        <label>وقتی فیش‌های بیمه‌نامه با اقساطِ قرارداد فرق داشت</label>
                    </div>
                    <p id="cm-contract-hint" class="text-[10.5px] text-slate-500 bg-slate-50 rounded-xl px-3 py-2 leading-6">نقد/اقساط، تعداد قسط و سررسیدِ اول از قراردادِ انتخاب‌شده می‌آید.</p>
                    <p class="text-[10.5px] text-slate-400 mt-1">قراردادها را از <a href="#" onclick="switchTab('fin-contracts');return false" class="text-indigo-600 font-bold">مالی ← قراردادهای پرداخت</a> تعریف کنید. برای هر بیمه‌نامه هم در پاپ‌آپِ صدور می‌شود روشِ دیگری گذاشت.</p>
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
            <label class="flex items-start gap-2 text-[11px] font-bold text-slate-600 cursor-pointer mb-3"><input type="checkbox" id="cm-pu-renewals" class="mt-0.5 accent-emerald-600">
                <span>دسترسی به «تمدیدی‌ها»<small class="block text-slate-400 font-normal">بیمه‌نامه‌های قبلیِ شرکت‌های همین کاربر (صادره‌ها و بردرو) را با اطلاعاتِ کلی (پلاک/شاسی، شرکت، شروع/انقضا، حق بیمه‌ی قبلی) می‌بیند، جستجو/فیلتر می‌کند و برای چند تا یک‌جا «درخواستِ تمدید» می‌فرستد.</small></span></label>
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
                        <div class="uf-rgrp"><span class="uf-gate"><i class="fas fa-user-shield"></i>کارکنانِ «بیمه با ما» · ورود از درگاهِ «همکارِ بیمه»</span></div>
                        <button type="button" data-r="ADMIN" style="--c:#d97706" onclick="ufPickRole('ADMIN')"><i class="fas fa-crown"></i><span><b>مدیر کل</b><small>دسترسیِ کامل به همه‌ی بخش‌ها، کاربران، تنظیمات و سپر امنیتی</small></span></button>
                        <button type="button" data-r="CEO" style="--c:#0f766e" onclick="ufPickRole('CEO')"><i class="fas fa-user-tie"></i><span><b>مدیر عامل شرکت</b><small>همه‌ی بخش‌های بیمه‌نامه، صدور و درخواست‌ها: دیدن، جستجو و خروجیِ اکسل (بدونِ ثبت و ویرایش)؛ تنظیمات و تعریفِ بازاریابان؛ کاربرانِ هم‌سطح و پایین‌تر؛ کارهای پرسنل. استعلامات و هوشیکس را از «دسترسی‌ها» می‌دهید.</small></span></button>
                        <button type="button" data-r="OPERATOR" style="--c:#2563eb" onclick="ufPickRole('OPERATOR')"><i class="fas fa-file-signature"></i><span><b>کارشناس صدور</b><small>پرونده‌های پرسنل، بازدیدِ سلامت، بایگانی، کاربرانِ ربات و گفتگو</small></span></button>
                        <button type="button" data-r="FINANCE" style="--c:#059669" onclick="ufPickRole('FINANCE')"><i class="fas fa-calculator"></i><span><b>کارشناس مالی</b><small>همه‌ی بخش‌های کارشناسِ صدور + اقساط، دریافت، پرداخت و صورتحساب</small></span></button>
                        <button type="button" data-r="COMPANY_LIAISON" style="--c:#7c3aed" onclick="ufPickRole('COMPANY_LIAISON')"><i class="fas fa-user-tie"></i><span><b>کارمند بیمه با ما <em>(عادی)</em></b><small>درخواست‌های شرکت‌ها، صادره‌ها، مالی (بدونِ حذف)، بایگانی و گفتگو</small></span></button>
                        <button type="button" data-r="PARSIAN" style="--c:#c026d3" onclick="ufPickRole('PARSIAN')"><i class="fas fa-file-circle-check"></i><span><b>کارمند بیمه با ما <em>(پارسیان)</em></b><small>فقط ساختِ گزارشِ بازدید و گزارش‌های صادره‌ی خودش</small></span></button>
                        <button type="button" data-r="LIFE" style="--c:#0891b2" onclick="ufPickRole('LIFE')"><i class="fas fa-heart-pulse"></i><span><b>کاربر بیمه عمر</b><small>فقط بخشِ بیمه عمر (داشبورد، بیمه‌نامه‌ها و اقساط، تماس، پرداخت، بایگانیِ عمر) و گفتگو</small></span></button>
                        <div class="uf-rgrp"><span class="uf-gate" style="--g:#0d9488"><i class="fas fa-building"></i>بیرون از مجموعه · ورود از درگاهِ «کارشناسِ شرکت‌ها»</span></div>
                        <button type="button" data-r="COMPANY" style="--c:#0d9488" onclick="ufPickRole('COMPANY')"><i class="fas fa-building"></i><span><b>کاربر شرکت</b><small>پنلِ شرکت: ثبتِ درخواستِ بیمه و مدارک، فقط برای شرکت(های) انتخاب‌شده</small></span></button>
                    </div>
                    <p id="uf-role-note" class="hidden text-[10.5px] text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-3 py-2 mt-2"></p>
                </section>
                <div id="uf-rest" class="hidden">
                    <section class="uf-sec">
                        <h4><span class="uf-step">۲</span>مشخصات و ورود</h4>
                        <div class="uf-grid">
                            <label class="uf-f"><span>نام و نام‌خانوادگی *</span><div class="uf-inline"><select id="uf-honor" title="عنوان: پیش از نام در پیامِ صبح‌بخیر، سربرگ و گفتگوها نوشته می‌شود" style="max-width:96px;flex:none"><option value="">عنوان…</option><option value="M">آقای</option><option value="F">خانم</option></select><input id="uf-fullname" oninput="paintUserAvatar('uf')" placeholder="مثلاً علی رضایی"></div></label>
                            <label class="uf-f"><span>نام کاربری * <em id="uf-user-lock" class="hidden">(ثابت)</em></span><input id="uf-username" dir="ltr" placeholder="ali.rezaei" autocomplete="off"></label>
                            <label class="uf-f"><span id="uf-pass-lbl">رمز عبور * (حداقل ۶)</span>
                                <div class="uf-inline"><input id="uf-password" dir="ltr" autocomplete="new-password" placeholder="••••••"><button type="button" onclick="ufGenPass()" title="ساختِ رمزِ تصادفی"><i class="fas fa-dice"></i></button></div></label>
                            <label class="uf-f"><span>شماره موبایل <em>(برای ورود با کدِ بله)</em></span><input id="uf-mobile" dir="ltr" inputmode="numeric" placeholder="09xxxxxxxxx"></label>
                            <label class="uf-f" id="uf-personnel-box"><span>کد پرسنلی <em>(اختیاری)</em></span><input id="uf-personnel" dir="ltr"></label>
                            <label class="uf-f"><span>سقفِ حجمِ فایل در گفتگو <em>(مگابایت؛ خالی = پیش‌فرض ۲۰، ۰ = بسته)</em></span><input id="uf-chatmb" type="number" min="0" max="2048" dir="ltr" placeholder="۲۰"><em id="uf-chatmb-host" class="text-[10px] text-slate-400"></em></label>
                            <label class="uf-f"><span>سقفِ استعلامِ روزانه <em>(خالی = پیش‌فرض، ۰ = بدونِ سقف)</em></span>
                                <div class="uf-inline"><input id="uf-inq" type="number" min="0" max="10000" dir="ltr" placeholder="پیش‌فرض"><button type="button" id="uf-inq-reset" class="hidden" onclick="ufInqReset()" title="صفر کردنِ شمارشِ امروز"><i class="fas fa-rotate-left"></i></button></div>
                                <em id="uf-inq-used" class="text-[10px] text-slate-400"></em></label>
                        </div>
                        <p id="uf-mobile-err" class="hidden text-[11px] text-red-600 font-bold mt-2"></p>
                    </section>
                    <section class="uf-sec" id="uf-company-sec">
                        <h4><span class="uf-step">۳</span>شرکت(ها)ی این کاربر *</h4>
                        <div class="msd" id="uf-companies"></div>
                        <p class="text-[10.5px] text-slate-400 mt-1.5">می‌توانید چند شرکت انتخاب کنید؛ کاربر در پنلش بین آن‌ها جابه‌جا می‌شود.</p>
                    </section>
                    <section class="uf-sec" id="uf-prof-sec">
                        <h4><i class="fas fa-id-card text-sky-500"></i>پروفایل در گفتگو <em class="text-[10px] text-slate-400 font-bold">(همکاران با زدن روی نام/عکس می‌بینند؛ خودِ کاربر هم ویرایش می‌کند)</em></h4>
                        <div class="uf-grid">
                            <label class="uf-f"><span>سمت</span><input id="uf-pf-title" maxlength="120" placeholder="مثلاً کارشناسِ ارشدِ صدور"></label>
                            <label class="uf-f"><span>تخصصِ بیمه‌ای <em>(با ، جدا کنید)</em></span><input id="uf-pf-specialty" maxlength="255" placeholder="بدنه، ثالث، عمر"></label>
                            <label class="uf-f"><span>شماره‌ی داخلی</span><input id="uf-pf-ext" maxlength="20" dir="ltr" inputmode="numeric" placeholder="۱۲۳"></label>
                            <label class="uf-f"><span>شماره‌ی تماس</span><input id="uf-pf-phone" maxlength="30" dir="ltr" inputmode="tel" placeholder="۰۲۱۱۲۳۴۵۶۷۸"></label>
                            <label class="uf-f"><span>ساعتِ پاسخگویی</span><input id="uf-pf-hours" maxlength="120" placeholder="شنبه تا چهارشنبه ۸ تا ۱۶"></label>
                            <label class="uf-f" style="grid-column:1/-1"><span>درباره‌ی من (بیو)</span><textarea id="uf-pf-bio" maxlength="500" rows="2" class="border rounded-xl px-3 py-2 text-sm" placeholder="چند خط درباره‌ی کار و حوزه‌ی پاسخگویی"></textarea></label>
                        </div>
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
        .uf-roles { display: grid; grid-template-columns: 1fr; gap: 8px; }
        @media (min-width: 640px) { .uf-roles { grid-template-columns: 1fr 1fr; } }
        .uf-rgrp { grid-column: 1 / -1; margin-top: 4px; }
        .uf-roles button[data-r="ADMIN"], .uf-roles button[data-r="COMPANY"] { grid-column: 1 / -1; }
        .uf-gate { --g: #4f46e5; display: inline-flex; align-items: center; gap: 6px; font-size: 10.5px; font-weight: 900; color: var(--g); background: color-mix(in srgb, var(--g) 9%, white); border-radius: 999px; padding: 4px 11px; }
        .uf-roles button { --c: #4f46e5; position: relative; display: flex; align-items: center; gap: 11px; border: 2px solid #e2e8f0; border-radius: 16px; padding: 11px 12px; background: #fff; text-align: right; transition: .18s; }
        .uf-roles button > i { flex: none; display: flex; width: 40px; height: 40px; border-radius: 12px; align-items: center; justify-content: center; font-size: 16px; color: var(--c); background: color-mix(in srgb, var(--c) 12%, white); }
        .uf-roles button span { min-width: 0; padding-left: 18px; }
        .uf-roles button b { display: block; font-size: 12.5px; color: #1e293b; } .uf-roles button b em { font-style: normal; color: var(--c); }
        .uf-roles button small { display: block; font-size: 10px; color: #94a3b8; font-weight: 700; margin-top: 2px; line-height: 1.6; }
        .uf-roles button:hover:not(:disabled) { border-color: var(--c); transform: translateY(-2px); box-shadow: 0 10px 22px -14px var(--c); }
        .uf-roles button.on { border-color: var(--c); background: color-mix(in srgb, var(--c) 7%, white); box-shadow: 0 0 0 4px color-mix(in srgb, var(--c) 15%, transparent); }
        .uf-roles button.on::after { content: '✓'; position: absolute; top: 50%; margin-top: -10px; left: 10px; width: 20px; height: 20px; border-radius: 50%; background: var(--c); color: #fff; font-size: 11px; line-height: 20px; text-align: center; }
        .uf-roles button:disabled { opacity: .35; cursor: not-allowed; filter: grayscale(.6); }
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
            <div class="flex-1 overflow-y-auto px-6 py-4 no-count pm-scroll">
                <div id="pm-body"></div>
                <div id="pm-vr"></div>
                <div class="pm-sep"><span><i class="fas fa-star ml-1"></i>امکانات، ابزارها و سرویس — جدا از نقش، برای همین کاربر</span></div>
                <div id="pm-hx"></div>
                <div id="pm-comfort"></div>
                <div id="pm-svc"></div>
                <div id="pm-fm"></div>
            </div>
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
        .pm-grp { border: 1px solid #e8ecf4; border-radius: 18px; overflow: hidden; margin-bottom: 12px; background: #fff; box-shadow: 0 10px 24px -22px rgba(15,23,42,.5); }
        .pm-grp h4 { background: linear-gradient(90deg, #f8fafc, #fff); padding: 9px 12px; font-size: 12.5px; font-weight: 900; color: #1e293b; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #eef2f7; }
        .pm-grp h4 .pm-ic { width: 28px; height: 28px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; background: linear-gradient(135deg, var(--a, #6366f1), var(--b, #8b5cf6)); box-shadow: 0 6px 14px -8px var(--a, #6366f1); flex-shrink: 0; }
        .pm-grp h4 small { font-size: 10px; color: #94a3b8; font-weight: 700; }
        .pm-sep { display: flex; align-items: center; gap: 10px; margin: 16px 0 10px; color: #7c3aed; font-size: 11px; font-weight: 900; }
        .pm-sep::before, .pm-sep::after { content: ''; pointer-events: none; flex: 1; height: 1px; background: linear-gradient(90deg, transparent, #ddd6fe, transparent); }
        .pm-sw { appearance: none; -webkit-appearance: none; width: 38px; height: 22px; border-radius: 999px; background: #e2e8f0; position: relative; cursor: pointer; transition: background .2s; vertical-align: middle; }
        .pm-sw::after { content: ''; pointer-events: none; position: absolute; top: 3px; right: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: right .2s; }
        .pm-sw:checked { background: linear-gradient(120deg, #7c3aed, #4f46e5); } .pm-sw:checked::after { right: 19px; }
        .pm-sw:disabled { opacity: .4; cursor: not-allowed; }
        .pm-tbl tr.pm-off td { opacity: .45; }
        .pmf-body { padding: 10px 12px 12px; } .pmf-hint { font-size: 10.5px; color: #64748b; line-height: 1.9; margin-bottom: 8px; }
        .pmf-row { display: flex; align-items: center; gap: 6px; padding: 5px 6px; border-radius: 10px; font-size: 11.5px; } .pmf-row:hover { background: #f8fafc; }
        .pmf-row .nm { flex: 1; min-width: 0; font-weight: 800; color: #334155; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pmf-row .tg { width: 22px; height: 22px; border-radius: 7px; border: 0; background: #f1f5f9; color: #64748b; cursor: pointer; font-size: 10px; flex: none; }
        .pmf-row select { border: 1px solid #e2e8f0; border-radius: 9px; font-size: 11px; padding: 3px 6px; font-family: inherit; background: #fff; max-width: 150px; }
        .pmf-row select.set { border-color: #a5b4fc; background: #eef2ff; font-weight: 800; }
        .pmf-eff { font-size: 9.5px; font-weight: 900; border-radius: 99px; padding: 1px 7px; flex: none; }
        .pmf-eff.none { background: #ffe4e6; color: #9f1239; } .pmf-eff.view { background: #fef3c7; color: #92400e; } .pmf-eff.download { background: #dcfce7; color: #166534; }
        .pm-tbl td .pm-hint { display: block; font-size: 10px; color: #94a3b8; font-weight: 700; }
        .pm-chg { font-size: 9.5px; color: #d97706; font-weight: 800; margin-right: 6px; }
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
                    <input type="file" id="mi-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif" hidden>
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
                    <label class="text-[11px] font-bold text-slate-500 block md:col-span-2">فایل معرفی‌نامه (اختیاری)<input type="file" id="mc-intro-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif" class="mt-1 w-full text-xs"></label>
                </div>
                <datalist id="mc-companies-dl"></datalist>
                <div id="mc-reg-person"></div>
            </div>
            <div id="mc-intro-summary" class="hidden text-xs font-bold text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-xl px-3 py-2 mb-3"></div>

            <!-- ۲. اطلاعات درخواست - فیلدها بر اساس نوع بیمه و نسبت، دقیقاً مثل ربات -->
            <div id="mc-request-section" class="border border-slate-200 rounded-xl p-3 mb-3">
                <h4 class="text-xs font-bold text-slate-600 mb-2"><i class="fas fa-car ml-1 text-slate-300"></i>اطلاعات درخواست</h4>
                <div id="mc-prop-box" class="mb-3 rounded-xl border-2 border-dashed border-indigo-200 bg-indigo-50/40 p-2.5 text-[11px]">
                    <label class="flex items-center gap-2 cursor-pointer font-bold text-indigo-700"><i class="fas fa-file-signature"></i>بارگذاریِ «پیشنهاد» یا «پیش‌نویس» (PDFِ سایتِ بیمه‌گر) — هر اطلاعاتی که در آن هست خودکار در فرم می‌نشیند
                        <input type="file" data-uk-skip id="mc-prop-file" accept=".pdf,application/pdf" class="hidden" onchange="mcProposalPick(this)"></label>
                    <div id="mc-prop-status" class="text-slate-500 mt-1 leading-5">اختیاری است؛ بعد از ثبت، خودِ فایل هم روی همین درخواست بایگانی می‌شود و فرمِ صدور با آن پر و چک می‌شود.</div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mb-2">
                    <label class="text-[11px] font-bold text-slate-500 block">نوع بیمه *
                        <select id="mc-insurance-type" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option value="THIRDPARTY">ثالث</option><option value="BODY">بدنه</option></select></label>
                    <label class="text-[11px] font-bold text-slate-500 block">بیمه‌گذار (نسبت با پرسنل) *
                        <select id="mc-relationship" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option>خودم</option><option>پدر</option><option>مادر</option><option>همسر</option><option>فرزند</option></select></label>
                    <label class="text-[11px] font-bold text-slate-500 block">مدرک مالکیت خودرو *
                        <select id="mc-ownership" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option value="کارت ماشین">کارت ماشین</option><option value="سند">سند</option></select></label>
                    <label class="text-[11px] font-bold text-slate-500 block mc-self-only">مالکِ خودرو
                        <select id="mc-owner-kind" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" onchange="mcRefresh()"><option value="self">خودِ پرسنل (همان بیمه‌گذار)</option><option value="other">همسر یا بستگان (ذینفع)</option></select></label>
                    <label class="text-[11px] font-bold text-slate-500 block mc-owner-only hidden">نسبتِ مالک با پرسنل *
                        <select id="mc-owner-rel" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white"><option value="">انتخاب…</option><option>همسر</option><option>پدر</option><option>مادر</option><option>فرزند</option><option>سایر</option></select></label>
                    <label class="text-[11px] font-bold text-slate-500 block mc-owner-only hidden">نامِ مالک (ذینفع) *<input type="text" id="mc-owner-name" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white"></label>
                    <label class="text-[11px] font-bold text-slate-500 block mc-owner-only hidden">کد ملیِ مالک<input type="text" id="mc-owner-nid" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" maxlength="10"></label>
                    <label class="text-[11px] font-bold text-slate-500 block mc-rel-only hidden">نام و نام خانوادگی بیمه‌گذار *<input type="text" id="mc-insured-name" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white"></label>
                    <label class="text-[11px] font-bold text-slate-500 block mc-rel-only hidden">کد ملی بیمه‌گذار *<input type="text" id="mc-insured-nid" class="mt-1 w-full border border-slate-300 rounded-lg p-2 text-sm bg-white" dir="ltr" inputmode="numeric" maxlength="10"></label>
                </div>
                <label class="text-[11px] font-bold text-slate-500 block mb-1">پلاک *</label>
                <div id="mc-plate-mount" class="mb-3 max-w-md"></div>
                <div id="mc-reg-veh" class="mb-3"></div>

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
                    <div id="mc-sanhab" class="mt-2 rounded-xl bg-cyan-50/70 border border-cyan-100 px-2 py-1.5"></div>
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
                    <i class="fas fa-info-circle text-blue-500"></i> <?php if ($hdrTitle !== ''): ?>سمتِ شما: <strong class="text-blue-600"><?php echo htmlspecialchars($hdrTitle); ?></strong> - <?php endif; ?>دسترسی‌ها را فقط مدیر کل تغییر می‌دهد.
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
                <!-- هشدارهای معرفی‌نامه: تکراری (قرمز) و تاریخِ متفاوت با امروز (زرد) -->
                <div id="review-intro-warn" class="space-y-2 mb-4"></div>

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

    <?php // اسکریپت‌های پنل در چند بسته‌ی فشرده و کش‌شدنی (asset.php)؛ ترتیب همان ترتیبِ قبلی است
    $__js = ['vendor-js/tsparticles.bundle.min.js', 'notif-bell.js', 'net-watch.js', 'tools.js', 'comfort.js', 'settings-nav.js', 'miniapp-admin.js', 'sms-otp.js', 'api-keys.js',
             'visit-appt.js', 'contacts.js', 'trash.js', 'announce.js', 'home.js', 'announce-admin.js'];
    if (($realRole ?? '') === 'ADMIN') { $__js[] = 'chat-access.js'; $__js[] = 'comfort-admin.js'; }
    array_push($__js, 'chat-ui.js', 'upload-kit.js', 'smart-ocr.js');
    if ($hxAccess) $__js[] = 'hooshix.js';
    array_push($__js, 'inquiry.js', 'registry.js', 'sanhab-ui.js', 'pbody-ui.js', 'issue-panel.js', 'reg-link.js', 'renew-req.js');
    echo asset_js($__js); ?>
    <?php echo asset_js(['cursor-fx.js'], 'defer'); ?>
    <script>
    // ثبتِ دستیِ درخواستِ کارکنان: «استعلامِ نام» کنارِ نام‌ها و «استعلامِ کد پستی» کنارِ کد پستی (دستی نوشتن هم آزاد است)
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.Inquiry) return;
        const g = id => document.getElementById(id);
        if (!g('mc-postal')) return;
        const selfRel = () => (g('mc-relationship') || {}).value === 'خودم';
        // بیمه‌گذار = خودِ پرسنل: کد ملیِ فرم یا (درخواستِ تازه روی معرفی‌نامه) کد ملیِ صاحبِ معرفی‌نامه؛ بستگان: کد ملیِ خودِ بیمه‌گذار
        const selfNid = () => g('mc-national-code').value.trim() || window.MC_INTRO_NID || '';
        Inquiry.attachForm({ nid: selfNid, birth: g('mc-birth'), name: g('mc-full-name') });
        Inquiry.attachForm({ nid: () => selfRel() ? selfNid() : g('mc-insured-nid').value, birth: g('mc-birth'), postal: g('mc-postal'), name: g('mc-insured-name'),
                             watch: [g('mc-national-code'), g('mc-insured-nid'), g('mc-relationship')] });
        // استعلامِ سنهابِ بیمه‌نامه‌ی ثالثِ قبلی (با پلاکِ همین فرم)
        if (window.SanhabUI && g('mc-sanhab')) SanhabUI.button(g('mc-sanhab'), {lookup: false, source: 'form', label: 'استعلامِ سنهاب (عدمِ خسارت و تخفیف‌ها)',
            getPrefill: () => ({plate: (g('mc-plate') || {}).value || '', nid: (selfRel() ? selfNid() : (g('mc-insured-nid') || {}).value || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))}),
            title: 'درخواستِ کارکنان'});
    });
    </script>
    <script>window.TP_SID = <?php echo json_encode(substr(md5(session_id() . 'tp'), 0, 10)); ?>;</script>
    <?php echo asset_js(['table-count.js']); ?>
    <script>
        // ساعتِ سربرگ: «چهارشنبه ۱۴۰۵/۰۷/۰۹ - ۱۴:۰۵:۲۳» به وقتِ ایران، ثانیه‌به‌ثانیه
        if (window.IrTime) IrTime.mountClock(document.getElementById('hdr-clock'), {render: (el, p, date, time) => {
            const d = el.querySelector('.hc-d'), t = el.querySelector('.hc-t'), dh = `<span class="hc-w">${p.wd} </span>${date}`;
            if (d.innerHTML !== dh) d.innerHTML = dh;
            t.textContent = time;
        }});
    </script>
    <?php $__js = ['money-input.js', 'finance-ui.js', 'finance-hub.js', 'work-log.js', 'staff-tasks.js', 'import-archive.js', 'renewals.js', 'endorse.js', 'party-ui.js', 'company-pick.js',
                   'legacy-import.js', 'edit-lock.js', 'table-ctx.js', 'recon.js'];
    if ($lifeAccess) $__js[] = 'life.js';
    if ($mkAccess) $__js[] = 'marketing.js';
    if ($ltAccess) $__js[] = 'letters.js';
    if ($hlAccess) $__js[] = 'health.js';
    $__js[] = 'fin-contracts.js';
    if ($realRole === 'ADMIN' && empty($permBoot['custom'])) $__js[] = 'security-shield.js';
    $__js[] = 'chat-archive.js';
    if ($vrAccess) {
        array_push($__js, 'visit-reports.js', 'visit-reports-list.js');
        if (($_SESSION['role'] ?? '') === 'ADMIN') array_push($__js, 'visit-reports-settings.js', 'visit-reports-editor.js');
    }
    // ثبتِ دستیِ درخواست (گروهی، تکی، شخصی) مستقل از دسترسیِ گزارشِ بازدید
    if (($_SESSION['role'] ?? '') === 'ADMIN') array_push($__js, 'backup-settings.js', 'company-manual-request.js', 'footer-settings.js');
    echo asset_js($__js); ?>
    <script>
        // مقادیرِ وابسته به کاربرِ همین صفحه برای dashboard-main.js
        window.__DB = <?php echo json_encode([
            'isAdmin' => ($_SESSION['role'] ?? '') === 'ADMIN', 'realAdmin' => perm_real_role() === 'ADMIN', 'realRoleAdmin' => $realRole === 'ADMIN',
            'isFinance' => ($_SESSION['role'] ?? '') === 'FINANCE', 'canManual' => (bool)$canManualPersonnel, 'perm' => $permBoot,
            'uid' => intval($_SESSION['user_id']), 'fullName' => (string)($_SESSION['full_name'] ?? ''), 'notLiaison' => !$isLiaison,
            'staffDash' => !$isLiaison && !$isParsian && !$isLife, 'homeFirst' => (bool)$homeFirst, 'canSeeCompanies' => (bool)$canSeeCompanies,
        ], JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <?php require_once __DIR__ . '/api/_assets.php'; echo asset_js(['dashboard-main.js']); ?>
<script>window.PAGE_GUARD_DETECT = <?= (sec_settings($pdo)['sec_devtools_guard'] ?? '1') === '1' ? 'true' : 'false' ?>;</script><?php echo asset_js(['page-guard.js']); ?>
</body>
</html>