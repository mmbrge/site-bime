<?php
// فایل: company-portal/index.php
// پنل وب ثبت‌کننده‌ی شرکت درخواست‌کننده: ثبت درخواست بیمه + آپلود تدریجیِ مدارک.
// یک کاربر می‌تواند به چند شرکت دسترسی داشته باشد؛ اگر فقط یکی دارد، پنل قفل روی همان می‌ماند.
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../api/_case_helpers.php';
require __DIR__ . '/../api/_company_helpers.php';

company_portal_session_start();
// ورودِ مستقیم از این صفحه دیگر مجاز نیست - همه باید از درگاهِ یکپارچه‌ی خودمان
// (index.php، گزینه‌ی «همکار شرکت‌ها») وارد شوند تا اشتباهیِ درگاه پیش نیاید
if (empty($_SESSION['company_user_id'])) {
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
<title>پنل ثبت درخواست بیمه | بیمه با ما</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    @font-face { font-family: 'Vazir'; src: url('../Font/Vazir-Regular.woff2') format('woff2'); font-weight: normal; }
    @font-face { font-family: 'Vazir'; src: url('../Font/Vazir-Bold.woff2') format('woff2'); font-weight: bold; }
    @font-face { font-family: 'Vazir'; src: url('../Font/Vazir-Medium.woff2') format('woff2'); font-weight: 500; }
    body { font-family: 'Vazir', sans-serif; background: #f1f5f9; color: #334155; margin: 0; }
    .card { background: #fff; border-radius: 1.25rem; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: 1px solid #eef2f7; }
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
        * { cursor: none !important; }
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
    /* پشتیبانِ محلیِ .hidden: نشان‌دادن/پنهان‌کردنِ فیلدها (مثلاً فیلدهای مخصوصِ
       الحاقیه و فسخ) به این کلاس وابسته است و اگر CDNِ Tailwind نیاید، بدون این
       قاعده همه‌ی فیلدها با هم دیده می‌شوند. !important دارد تا ترتیبِ تزریقِ
       استایلِ Tailwind در زمان اجرا هم مشکلی نسازد. */
    .hidden { display: none !important; }
    .checklist-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 6px; align-items: start; }
    .modal-overlay.active .modal-content { transform: scale(1); }
    .status-badge { font-size: 11px; font-weight: bold; padding: 4px 10px; border-radius: 999px; }
    .toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(100px); background:#1e293b; color:#fff; padding: 12px 22px; border-radius: 12px; font-size: 13px; font-weight: bold; z-index: 9999; transition:.3s; opacity:0; }
    .toast.show { transform: translateX(-50%) translateY(0); opacity:1; }

    /* اعلان‌ها: گوشه‌ی پایینِ سمتِ راست، روی هم انباشته، و هرکدام بعد از ۵ ثانیه خودش می‌رود */
    #notif-stack { position: fixed; bottom: 20px; right: 20px; z-index: 9998; display: flex; flex-direction: column; gap: 8px;
                   max-width: min(330px, calc(100vw - 40px)); pointer-events: none; }
    .notif-card { background: #fff; border: 1px solid #e2e8f0; border-right: 4px solid #3b82f6; border-radius: 14px;
                  padding: 10px 13px; box-shadow: 0 10px 28px rgba(15,23,42,.14); cursor: pointer; pointer-events: auto;
                  opacity: 0; transform: translateX(24px); transition: opacity .3s, transform .3s; }
    .notif-card.show { opacity: 1; transform: translateX(0); }
</style>
</head>
<body class="min-h-screen">
<div class="cursor-dot"></div>
<div class="cursor-outline"></div>

<div class="max-w-3xl mx-auto p-4 pb-20">
    <div class="flex items-center justify-between py-5">
        <div>
            <h1 class="font-black text-lg"><?php echo count($companies) === 1 ? htmlspecialchars($companies[0]['name']) : 'چند شرکت'; ?></h1>
            <p class="text-xs text-slate-400"><?php echo htmlspecialchars($_SESSION['company_user_full_name']); ?></p>
        </div>
        <button onclick="doLogout()" class="text-xs font-bold text-red-500 hover-target">خروج</button>
    </div>

    <button onclick="openNewRequestModal()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 rounded-2xl shadow-lg shadow-blue-500/20 mb-6 transition-colors">
        <span class="ml-2">+</span> ثبت درخواست جدید
    </button>

    <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
        <h2 class="text-sm font-bold text-slate-500">درخواست‌های من</h2>
        <div class="relative flex-1 min-w-[180px]">
            <input type="search" id="req-search" oninput="debouncedRequestSearch()" placeholder="جستجو: شماره درخواست، پلاک، شماره شاسی، خودرو یا متن..."
                   class="w-full border border-slate-200 rounded-xl py-2 pr-9 pl-3 text-xs outline-none focus:border-blue-500">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                 class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true">
                <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
            </svg>
        </div>
    </div>
    <div id="requests-list" class="space-y-3"></div>
</div>

<button onclick="openChatModal()" title="گفتگو با پشتیبانی" class="fixed bottom-5 left-5 w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-xl flex items-center justify-center z-50">
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
    </svg>
</button>

<!-- مودال چت با بیمه با ما -->
<div id="chat-modal" class="modal-overlay">
    <div class="modal-content p-0 flex flex-col" style="height: 70vh;">
        <div class="p-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-lg">چت با بیمه با ما</h3>
            <button onclick="closeModal('chat-modal')" aria-label="بستن" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors shrink-0">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <?php if (count($companies) > 1): ?>
        <div class="p-3 border-b">
            <select id="chat-company" onchange="loadChatMessages()" class="w-full border rounded-lg p-2 text-sm">
                <?php foreach ($companies as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" id="chat-company" value="<?php echo $companies[0]['id']; ?>">
        <?php endif; ?>
        <div id="chat-body" class="flex-1 overflow-y-auto p-4 space-y-2 bg-slate-50"></div>
        <div class="p-3 border-t flex gap-2 items-center">
            <label title="پیوست فایل" class="text-slate-400 hover:text-blue-500 cursor-pointer shrink-0">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                <input type="file" id="chat-file" class="hidden" onchange="sendChatFile()">
            </label>
            <input type="text" id="chat-input" placeholder="پیام خود را بنویسید..." class="flex-1 border rounded-xl px-3 py-2 text-sm">
            <button onclick="sendChatMessage()" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-blue-700">ارسال</button>
        </div>
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
                <option value="ENDORSEMENT">صدور الحاقیه</option>
                <option value="CANCELLATION">فسخ بیمه‌نامه</option>
            </select>
            <p id="nr-kind-hint" class="text-[11px] text-slate-400 mt-1.5"></p>
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
            <input type="file" id="nr-letter" accept=".pdf,.jpg,.jpeg,.png,.webp">
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
<!-- مودال فهرست متنیِ ریزِ درخواست -->
<div id="prows-text-modal" class="modal-overlay">
    <div class="modal-content w-full max-w-lg p-5 relative">
        <button type="button" onclick="closeModal('prows-text-modal')" aria-label="بستن" title="بستن" class="absolute top-4 left-4 text-slate-400 hover:text-red-500">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
        <h3 class="font-black text-base mb-1">فهرست متنی ریز درخواست</h3>
        <p class="text-[11px] text-slate-400 mb-3">هر ردیف با مدارکی که دارد، و زیرش مدارکِ ناموجودش.</p>
        <textarea id="prows-text-content" rows="14" dir="rtl" class="w-full text-[11px] font-mono border rounded-xl p-3 bg-slate-50" readonly></textarea>
        <button onclick="copyPortalRowsText()" class="w-full mt-3 bg-slate-800 hover:bg-slate-900 text-white font-bold py-2.5 rounded-xl text-sm">کپی کردن</button>
    </div>
</div>

<div id="request-detail-modal" class="modal-overlay">
    <div class="modal-content wide p-6" style="max-height: 88vh; overflow-y: auto;">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-black text-lg flex items-center gap-2">جزئیات درخواست</h3>
            <button onclick="closeModal('request-detail-modal')" aria-label="بستن" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors shrink-0">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="request-detail-body"></div>
        <div class="bg-slate-50 border border-slate-100 rounded-2xl mt-4 p-4">
            <label class="text-xs font-bold text-slate-600 flex items-center gap-1.5 mb-1"><i class="fas fa-cloud-arrow-up text-blue-400"></i>افزودن مدارک جدید به این درخواست</label>
            <p class="text-[11px] text-slate-400 mb-3">می‌توانید چند فایل با هم انتخاب کنید؛ فقط برای هر فایل نوعش را مشخص کنید.</p>
            <input type="file" id="doc-upload-input" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.zip" onchange="renderDocUploadRows()" class="mb-2 w-full text-xs bg-white border border-slate-200 rounded-lg p-2">
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

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
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
function pushNotification(title, body) {
    const box = document.getElementById('notif-stack');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'notif-card';
    el.innerHTML = `<p class="font-bold text-xs mb-0.5">${title}</p>
                    <p class="text-[11px] text-slate-500 leading-relaxed">${body || ''}</p>`;
    el.onclick = () => el.remove();
    box.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); }, 5000);
}

async function pollNotifications() {
    try {
        const res = await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'notifications_feed', since: notifSince})});
        const data = await res.json();
        if (!data.ok) return;
        (data.events || []).forEach(e => pushNotification(e.title, e.body));
        notifSince = data.now;
        // اگر پیام تازه‌ای آمد و مودال چت باز است، همان‌جا هم تازه شود
        if ((data.events || []).some(e => e.type === 'chat')
            && document.getElementById('chat-modal').classList.contains('active')) loadChatMessages();
    } catch (e) { /* قطعیِ لحظه‌ای نباید چیزی را خراب کند */ }
}
pollNotifications();
setInterval(pollNotifications, 15000);
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function openNewRequestModal() {
    document.getElementById('nr-plates-list').innerHTML = '';
    document.getElementById('nr-kind').value = 'NEW_POLICY';
    onNrKindChange();
    updateInsurerOptions();
    openModal('new-request-modal');
}

async function doLogout() {
    await fetch('../api/company_portal_auth.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'logout'})});
    location.reload();
}

async function loadRequests() {
    const q = (document.getElementById('req-search')?.value || '').trim();
    const res = await fetch('../api/company_portal_actions.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'my_requests', q})});
    const data = await res.json();
    const box = document.getElementById('requests-list');
    if (!data.ok || !data.requests.length) {
        box.innerHTML = `<p class="text-center text-xs text-slate-400 py-10">${q ? 'برای این جستجو درخواستی پیدا نشد.' : 'هنوز درخواستی ثبت نکرده‌اید.'}</p>`;
        return;
    }
    box.innerHTML = data.requests.map(r => `
        <div class="card p-4 cursor-pointer hover:shadow-md transition-shadow" onclick="openRequestDetail(${r.id})">
            <div class="flex items-center justify-between mb-1">
                <span class="font-bold text-sm">درخواست #${faNum(r.id)}${r.company_name ? ' - ' + r.company_name : ''}</span>
                <span class="flex items-center gap-1.5">
                    ${r.request_kind && r.request_kind !== 'NEW_POLICY'
                        ? `<span class="status-badge ${r.request_kind === 'ENDORSEMENT' ? 'bg-violet-50 text-violet-600' : 'bg-rose-50 text-rose-600'}">${r.request_kind_fa}</span>` : ''}
                    <span class="status-badge ${STATUS_COLOR[r.status] || ''}">${STATUS_FA[r.status] || r.status}</span>
                </span>
            </div>
            <p class="text-xs text-slate-500 line-clamp-2">${r.request_text ? r.request_text : '(بدون توضیح متنی)'}</p>
            <div class="flex flex-wrap gap-1 mt-2">
                ${r.body_count ? `<span class="text-[10px] font-bold bg-cyan-50 text-cyan-700 rounded px-1.5 py-0.5">بدنه: ${faNum(r.body_count)} درخواستی / ${faNum(r.body_issued)} صادره</span>` : ''}
                ${r.third_count ? `<span class="text-[10px] font-bold bg-blue-50 text-blue-700 rounded px-1.5 py-0.5">ثالث: ${faNum(r.third_count)} درخواستی / ${faNum(r.third_issued)} صادره</span>` : ''}
            </div>
            <p class="text-[10px] text-slate-400 mt-2">${r.insurer === 'IRAN' ? 'بیمه ایران' : 'بیمه پاسارگاد'} · ثبت: ${r.created_at_jalali} · آخرین ویرایش: ${r.updated_at_jalali}</p>
        </div>
    `).join('');
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
            <div class="grid grid-cols-4 gap-1 flex-1" dir="ltr">
                <input class="plate-p4 text-center border rounded-lg p-2 text-xs" maxlength="2" placeholder="۱۲">
                <input class="plate-letter text-center border rounded-lg p-2 text-xs" maxlength="3" placeholder="الف">
                <input class="plate-p2 text-center border rounded-lg p-2 text-xs" maxlength="3" placeholder="۳۴۵">
                <div class="flex items-center gap-1 border rounded-lg px-1">
                    <span class="text-[10px] text-slate-400 whitespace-nowrap">ایران</span>
                    <input class="plate-p1 text-center text-xs w-full outline-none" maxlength="2" placeholder="۶۷">
                </div>
            </div>
            <button type="button" onclick="this.closest('.plate-row').remove()" aria-label="حذف این پلاک" title="حذف این پلاک" class="text-red-400 hover:text-red-600 shrink-0">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
            </button>
        </div>
        <label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-500 mt-1.5 cursor-pointer">
            <input type="checkbox" class="plate-isnew" onchange="togglePlateNoPlate(this)">
            پلاک ندارد (لیفتراک یا خودروی صفرکیلومتر)
        </label>
        <div class="plate-chassis-box hidden grid grid-cols-2 gap-1.5 mt-1.5">
            <input class="plate-chassis border rounded-lg p-2 text-xs" placeholder="شماره شاسی" dir="ltr">
            <input class="plate-engine border rounded-lg p-2 text-xs" placeholder="شماره موتور" dir="ltr">
        </div>
        <select class="plate-instype text-xs border rounded-lg p-1.5 w-full mt-1.5" onchange="togglePlateValueBox(this)">
            <option value="THIRDPARTY">بیمه‌ی درخواستی: ثالث</option>
            <option value="BODY">بیمه‌ی درخواستی: بدنه</option>
            <option value="BOTH">بیمه‌ی درخواستی: ثالث و بدنه (هردو)</option>
        </select>
        <div class="plate-value-box grid grid-cols-2 gap-1.5 mt-1.5">
            <input class="plate-carvalue border rounded-lg p-2 text-xs hidden" placeholder="ارزش خودرو (ریال)" inputmode="numeric">
            <input class="plate-liability border rounded-lg p-2 text-xs" placeholder="سقف تعهد مالی (ریال)" inputmode="numeric">
        </div>
        <input class="plate-refpolicy border rounded-lg p-2 text-xs w-full mt-1.5" placeholder="شماره بیمه‌نامه (اختیاری)" dir="ltr">
        <input class="plate-endorse border rounded-lg p-2 text-xs w-full mt-1.5 hidden" placeholder="چه تغییری می‌خواهید؟ (خواسته‌ی الحاقیه)">
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
    row.querySelector('.plate-endorse').classList.toggle('hidden', k !== 'ENDORSEMENT');
    row.querySelector('.plate-cancelreason').classList.toggle('hidden', k !== 'CANCELLATION');
    row.querySelector('.plate-value-box').classList.toggle('hidden', k !== 'NEW_POLICY');
}

function onNrKindChange() {
    const k = document.getElementById('nr-kind').value;
    document.getElementById('nr-kind-hint').textContent = k === 'ENDORSEMENT'
        ? 'برای هر خودرو، شماره‌ی بیمه‌نامه‌ی فعلی و تغییری که می‌خواهید را بنویسید.'
        : (k === 'CANCELLATION'
            ? 'برای هر خودرو، شماره‌ی بیمه‌نامه و دلیل فسخ را مشخص کنید.'
            : 'صدور بیمه‌نامه‌ی جدید برای خودروهای این درخواست.');
    document.querySelectorAll('#nr-plates-list .plate-row').forEach(applyKindToPlateRow);
}

// «پلاک ندارد»: به‌جای خانه‌های پلاک، شماره شاسی و موتور گرفته می‌شود
function togglePlateNoPlate(cb) {
    const row = cb.closest('.plate-row');
    row.querySelector('.plate-chassis-box').classList.toggle('hidden', !cb.checked);
    row.querySelectorAll('.plate-p1, .plate-p2, .plate-p4, .plate-letter').forEach(i => {
        i.disabled = cb.checked;
        i.classList.toggle('opacity-40', cb.checked);
        if (cb.checked) i.value = '';
    });
}

// ارزش خودرو فقط برای بدنه، سقف تعهد مالی فقط برای ثالث
function togglePlateValueBox(sel) {
    const row = sel.closest('.plate-row');
    const t = sel.value;
    row.querySelector('.plate-carvalue').classList.toggle('hidden', !(t === 'BODY' || t === 'BOTH'));
    row.querySelector('.plate-liability').classList.toggle('hidden', !(t === 'THIRDPARTY' || t === 'BOTH'));
}

function collectPlateRows(containerId) {
    const rows = Array.from(document.getElementById(containerId).querySelectorAll('.plate-row')).map(row => ({
        p1: row.querySelector('.plate-p1').value.trim(),
        p2: row.querySelector('.plate-p2').value.trim(),
        letter: row.querySelector('.plate-letter').value.trim(),
        p4: row.querySelector('.plate-p4').value.trim(),
        chassis_no: (row.querySelector('.plate-chassis')?.value || '').trim(),
        engine_no: (row.querySelector('.plate-engine')?.value || '').trim(),
        is_new_vehicle: row.querySelector('.plate-isnew')?.checked ? 1 : 0,
        car_value: (row.querySelector('.plate-carvalue')?.value || '').trim(),
        liability_limit: (row.querySelector('.plate-liability')?.value || '').trim(),
        ref_policy_number: (row.querySelector('.plate-refpolicy')?.value || '').trim(),
        endorsement_request: (row.querySelector('.plate-endorse')?.value || '').trim(),
        cancellation_reason: (row.querySelector('.plate-cancelreason')?.value || '').trim(),
        insurance_type: row.querySelector('.plate-instype').value,
    // ردیف یا پلاک دارد یا شماره شاسی (لیفتراک و خودروی صفرکیلومتر پلاک ندارند)
    })).filter(p => p.p1 || p.p2 || p.letter || p.p4 || p.chassis_no);
    // اگر «هردو» انتخاب شده، همین پلاک به دو ردیفِ جدا (ثالث + بدنه) تبدیل می‌شود
    const out = [];
    rows.forEach(p => {
        if (p.insurance_type === 'BOTH') {
            out.push({...p, insurance_type: 'THIRDPARTY'});
            out.push({...p, insurance_type: 'BODY'});
        } else out.push(p);
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
    fd.append('plates', JSON.stringify(collectPlateRows('nr-plates-list')));
    const fileInput = document.getElementById('nr-letter');
    if (fileInput.files[0]) fd.append('letter_file', fileInput.files[0]);
    try {
        const res = await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd});
        const data = await res.json();
        if (data.ok) {
            showToast('درخواست با موفقیت ثبت شد.');
            closeModal('new-request-modal');
            document.getElementById('nr-text').value = ''; fileInput.value = '';
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
                    : (item.required ? 'bg-red-50 border-red-200 text-red-600'
                                     : 'bg-slate-50 border-slate-200 text-slate-400');
    const star = item.required ? '<span class="text-red-400">*</span>' : '';
    const links = (item.docs || []).map(d => d.is_dir
        ? `<span class="text-slate-500">${d.label} (پوشه)</span>`
        : `<a href="../${d.file_path}" target="_blank" class="underline hover:no-underline">${d.label}</a>`
    ).join('<span class="text-slate-300 mx-1">|</span>');

    let uploader = '';
    if (!ok && plate.status !== 'ISSUED') {
        const types = item.upload_types || [item.key];
        const sel = types.length > 1
            ? `<select id="pr-type-${plate.id}-${item.key}" class="text-[10px] border rounded px-1 py-0.5 bg-white">
                   ${types.map(t => `<option value="${t}">${PORTAL_DOC_TYPE_FA[t] || t}</option>`).join('')}
               </select>`
            : `<input type="hidden" id="pr-type-${plate.id}-${item.key}" value="${types[0]}">`;
        const accept = item.key === 'health_inspection' ? '.zip,.pdf,.jpg,.jpeg,.png,.webp' : '.pdf,.jpg,.jpeg,.png,.webp';
        uploader = `<span class="inline-flex items-center gap-1 mt-1">${sel}
            <label class="text-[10px] font-bold text-white bg-blue-600 hover:bg-blue-700 px-2 py-0.5 rounded cursor-pointer whitespace-nowrap">
                بارگذاری<input type="file" class="hidden" accept="${accept}" onchange="portalUploadRowDoc(${plate.id}, 'pr-type-${plate.id}-${item.key}', this)">
            </label></span>`;
    }

    return `<div class="border ${tone} rounded-lg px-2 py-1 text-[10px] leading-relaxed" title="${item.hint || ''}">
        <div class="font-bold whitespace-nowrap">${ok ? '✓' : '✗'} ${item.label}${star}</div>
        ${links ? `<div class="mt-0.5">${links}</div>` : ''}
        ${uploader}
    </div>`;
}

const PORTAL_ROW_STATUS_COLOR = {
    PENDING: 'bg-amber-50 text-amber-600', READY_FOR_ISSUE: 'bg-cyan-50 text-cyan-600',
    WITH_BOSS: 'bg-indigo-50 text-indigo-600', IN_ISSUANCE: 'bg-violet-50 text-violet-600',
    ISSUED: 'bg-emerald-50 text-emerald-600', CANCELLED: 'bg-red-50 text-red-600',
};
const PORTAL_DOC_TYPE_FA = {
    car_card_front: 'کارت ماشین رو', car_card_back: 'کارت ماشین پشت', ownership_doc: 'سند',
    prev_third_policy: 'بیمه ثالث قبل', prev_body_policy: 'بیمه بدنه قبل',
    health_inspection: 'بازدید سلامت', health_report: 'گزارش بازدید',
    policy_doc: 'بیمه‌نامه', new_car_card: 'کارت ماشین جدید', new_ownership_doc: 'سند جدید',
    other: 'سایر مدارک', car_card_or_title: 'کارت ماشین یا سند', letter: 'نامه‌ی درخواست',
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
    const docsHtml = data.documents.length ? data.documents.map(d => {
        const [icon, color] = fileIcon(d.orig_name || d.file_path);
        const assigned = d.status === 'ASSIGNED';
        return `
        <a href="../${d.file_path}" target="_blank" class="flex items-center justify-between gap-2 bg-white border border-slate-100 rounded-xl p-3 mb-2 hover:shadow-md hover:border-blue-200 transition-all group">
            <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center shrink-0 group-hover:bg-blue-50 transition-colors"><i class="fas ${icon} ${color}"></i></div>
                <div class="min-w-0">
                    <span class="text-xs font-bold text-slate-700 truncate block">${d.orig_name || 'فایل'}</span>
                    <span class="text-[10px] text-slate-400">${d.doc_type_label || ''}${d.uploaded_at_jalali ? ' · ' + d.uploaded_at_jalali : ''}</span>
                </div>
            </div>
            <span class="text-[10px] font-bold px-2.5 py-1 rounded-full shrink-0 ${assigned ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'}">${assigned ? '✓ بررسی‌شده' : 'در انتظار بررسی'}</span>
        </a>`;
    }).join('') : '<p class="text-xs text-slate-400 bg-slate-50 rounded-xl p-4 text-center">هنوز مدرکی آپلود نشده.</p>';

    // ---- ریزِ درخواست: یک ردیف به ازای هر بیمه‌نامه‌ی درخواستی، با چک‌لیستِ مدارکش ----
    const rowsHtml = data.plates.length ? data.plates.map((p, idx) => {
        const missing = Object.values(p.missing_docs || {});
        const chips = (p.checklist || []).map(it => portalChecklistChip(p, it)).join('');
        return `
        <div class="bg-white border border-slate-100 rounded-xl p-3 mb-2">
            <div class="flex items-center justify-between gap-2 flex-wrap mb-2">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <span class="text-[10px] text-slate-300 font-bold">${faNum(idx + 1)}</span>
                    ${(p.plate_p1 || p.plate_p2 || p.plate_letter || p.plate_p4)
                        ? `<div class="border-2 border-slate-700 rounded-md px-2.5 py-1 font-black text-xs text-slate-700" dir="ltr">${plateHtml(p)}</div>`
                        : `<div class="border-2 border-dashed border-slate-400 rounded-md px-2.5 py-1 font-black text-xs text-slate-600" dir="ltr" title="شماره شاسی">${p.chassis_no || 'بدون شناسه'}</div>`}
                    <span class="text-[10px] font-bold text-slate-400">${p.insurance_type === 'BODY' ? 'بیمه بدنه' : 'بیمه ثالث'}</span>
                    ${p.expiry_date_jalali ? `<span class="text-[10px] text-slate-400">انقضا: ${p.expiry_date_jalali}</span>`
                        : (Number(p.is_new_vehicle) ? '<span class="text-[10px] text-slate-400">صفر کیلومتر</span>' : '')}
                    ${p.insurance_type === 'BODY' && p.car_value ? `<span class="text-[10px] text-slate-500">ارزش: ${faMoney(p.car_value)}</span>` : ''}
                    ${p.insurance_type === 'THIRDPARTY' && p.liability_limit ? `<span class="text-[10px] text-slate-500">تعهد: ${faMoney(p.liability_limit)}</span>` : ''}
                </div>
                <span class="text-[10px] font-bold px-2.5 py-1 rounded-full ${PORTAL_ROW_STATUS_COLOR[p.status] || 'bg-slate-100 text-slate-500'}">${p.status_fa || p.status}</span>
            </div>
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
                    <span class="text-[10px] text-slate-500">${p.policy_number ? 'شماره بیمه‌نامه: ' + p.policy_number : ''}${p.issued_at_jalali ? ' · ' + p.issued_at_jalali : ''}</span>
                    ${p.has_issued_file ? `<a href="../api/company_portal_actions.php?action=download_issued_policy&plate_id=${p.id}"
                        class="text-[10px] font-bold text-white bg-emerald-600 hover:bg-emerald-700 px-2.5 py-1 rounded-lg">دانلود بیمه‌نامه</a>` : ''}
                </div>` : ''}
        </div>`;
    }).join('') : '<p class="text-xs text-slate-400 bg-slate-50 rounded-xl p-4 text-center">ریزِ درخواست هنوز ثبت نشده؛ به‌محض ثبت، هر ردیف و مدارک لازمش همین‌جا نمایش داده می‌شود.</p>';

    document.getElementById('request-detail-body').innerHTML = `
        <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-5 mb-4 text-white relative overflow-hidden">
            <div class="absolute -left-6 -top-6 w-28 h-28 bg-white/10 rounded-full"></div>
            <div class="absolute -left-2 -bottom-8 w-20 h-20 bg-white/10 rounded-full"></div>
            <div class="relative flex items-center justify-between mb-3">
                <span class="flex items-center gap-1.5">
                    <span class="status-badge bg-white/20 backdrop-blur-sm">${STATUS_FA[r.status] || r.status}</span>
                    ${r.request_kind && r.request_kind !== 'NEW_POLICY'
                        ? `<span class="status-badge bg-white/30">${r.request_kind_fa}</span>` : ''}
                </span>
                <span class="text-xs font-bold opacity-90">${INSURER_FA[r.insurer] ? 'بیمه ' + INSURER_FA[r.insurer] : ''}</span>
            </div>
            <p class="relative text-sm leading-relaxed font-bold">${r.request_text || '(بدون توضیح متنی)'}</p>
            <div class="relative flex gap-4 mt-3 text-[10.5px] opacity-80">
                <span><i class="far fa-calendar-plus ml-1"></i>ثبت: ${r.created_at_jalali}</span>
                <span><i class="far fa-clock ml-1"></i>ویرایش: ${r.updated_at_jalali}</span>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
            <div class="bg-cyan-50 border border-cyan-100 rounded-xl p-2 text-center"><p class="text-[10px] text-cyan-700">بدنه درخواستی</p><p class="font-black text-cyan-800">${faNum(c.body || 0)}</p></div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-2 text-center"><p class="text-[10px] text-emerald-700">بدنه صادرشده</p><p class="font-black text-emerald-800">${faNum(c.body_issued || 0)}</p></div>
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-2 text-center"><p class="text-[10px] text-blue-700">ثالث درخواستی</p><p class="font-black text-blue-800">${faNum(c.third || 0)}</p></div>
            <div class="bg-teal-50 border border-teal-100 rounded-xl p-2 text-center"><p class="text-[10px] text-teal-700">ثالث صادرشده</p><p class="font-black text-teal-800">${faNum(c.third_issued || 0)}</p></div>
        </div>

        <button onclick="showPortalRowsText(${r.id})" class="w-full mb-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2 rounded-xl text-xs"><i class="fas fa-list ml-1"></i>فهرست متنی ریز درخواست</button>

        <h4 class="text-xs font-bold text-slate-500 mb-2 flex items-center gap-1.5"><i class="fas fa-car text-slate-300"></i>ریز درخواست و مدارک هر ردیف</h4>
        ${rowsHtml}

        <h4 class="text-xs font-bold text-slate-500 mb-2 mt-4 flex items-center gap-1.5"><i class="fas fa-paperclip text-slate-300"></i>همه‌ی مدارک ارسالی</h4>
        ${docsHtml}
    `;
    document.getElementById('doc-upload-input').value = '';
    document.getElementById('doc-upload-rows').innerHTML = '';
    openModal('request-detail-modal');
}

// آپلودِ هدفمندِ یک مدرک روی یک ردیف، از دکمه‌ی همان خانه‌ی چک‌لیست
async function portalUploadRowDoc(plateId, typeElId, input) {
    if (!input.files[0]) return;
    const typeEl = document.getElementById(typeElId);
    const fd = new FormData();
    fd.append('action', 'upload_row_doc');
    fd.append('plate_id', plateId);
    fd.append('doc_type', typeEl ? typeEl.value : 'other');
    fd.append('doc_file', input.files[0]);
    try {
        const res = await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd});
        const data = await res.json();
        showToast(data.ok ? 'مدرک ثبت شد.' : (data.error || 'خطا در بارگذاری.'));
        if (data.ok && activeRequestId) openRequestDetail(activeRequestId);
    } catch (e) { showToast('خطا در ارتباط با سرور.'); }
    input.value = '';
}

// فهرست متنیِ ریزِ درخواست - همان چیزی که ما هم می‌بینیم
async function showPortalRowsText(requestId) {
    const box = document.getElementById('prows-text-content');
    box.value = 'در حال آماده‌سازی...';
    openModal('prows-text-modal');
    const res = await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'request_rows_text', request_id: requestId})});
    const data = await res.json();
    box.value = data.ok ? data.text : (data.error || 'خطا');
}

function copyPortalRowsText() {
    const box = document.getElementById('prows-text-content');
    box.select();
    navigator.clipboard.writeText(box.value)
        .then(() => showToast('فهرست کپی شد.'))
        .catch(() => showToast('کپی نشد؛ متن انتخاب شده، دستی کپی کنید.'));
}

const DOC_TYPE_OPTIONS = [
    ['letter', 'نامه‌ی درخواست (اگر موقع ثبت درخواست نامه ارسال نشده بود)'],
    ['ownership_doc', 'سند'],
    ['car_card_front', 'کارت ماشین رو'],
    ['car_card_back', 'کارت ماشین پشت'],
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
    const seg = v => `<span>${v || ''}</span>`;
    return `<span style="display:inline-flex;direction:ltr;gap:4px;align-items:center;unicode-bidi:isolate">`
        + seg(p.plate_p4 || '--') + `<span>${p.plate_letter || '-'}</span>` + seg(p.plate_p2 || '---')
        + `<span class="text-slate-400">ایران</span>` + seg(p.plate_p1 || '--') + `</span>`;
}

function renderDocUploadRows() {
    const files = document.getElementById('doc-upload-input').files;
    const box = document.getElementById('doc-upload-rows');
    const plateOptions = '<option value="">بدون پلاک مشخص</option>' + currentRequestPlates.map((p, i) => `<option value="${i}">${plateOptionLabel(p)}</option>`).join('');
    box.innerHTML = Array.from(files).map((f, i) => `
        <div class="bg-white border border-slate-200 rounded-xl p-2.5 text-xs du-row" data-index="${i}">
            <p class="font-bold mb-1.5 truncate text-slate-700"><i class="fas fa-paperclip text-slate-300 ml-1"></i>${f.name}</p>
            <div class="grid grid-cols-2 gap-2">
                <select class="du-type border border-slate-200 rounded-lg p-1.5">${DOC_TYPE_OPTIONS.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select>
                <select class="du-plate border border-slate-200 rounded-lg p-1.5">${plateOptions}</select>
            </div>
        </div>`).join('');
}

async function uploadDocuments() {
    const files = document.getElementById('doc-upload-input').files;
    if (!files.length) { showToast('حداقل یک فایل انتخاب کنید.'); return; }
    const rows = document.querySelectorAll('#doc-upload-rows .du-row');
    let okCount = 0;
    for (let i = 0; i < files.length; i++) {
        const row = rows[i];
        const fd = new FormData();
        fd.append('action', 'upload_document');
        fd.append('request_id', activeRequestId);
        fd.append('doc_type', row ? row.querySelector('.du-type').value : '');
        const plateIdx = row ? row.querySelector('.du-plate').value : '';
        const plate = plateIdx !== '' ? currentRequestPlates[Number(plateIdx)] : null;
        fd.append('plate_p1', plate ? (plate.plate_p1 || '') : '');
        fd.append('plate_p2', plate ? (plate.plate_p2 || '') : '');
        fd.append('plate_letter', plate ? (plate.plate_letter || '') : '');
        fd.append('plate_p4', plate ? (plate.plate_p4 || '') : '');
        fd.append('file', files[i]);
        try {
            const res = await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd});
            const data = await res.json();
            if (data.ok) okCount++;
        } catch (e) { /* ادامه با فایل بعدی */ }
    }
    showToast(`${okCount} از ${files.length} فایل آپلود شد.`);
    document.getElementById('doc-upload-input').value = '';
    document.getElementById('doc-upload-rows').innerHTML = '';
    openRequestDetail(activeRequestId);
}

function fmtBubbleTime(ts) { return new Date(ts.replace(' ', 'T')).toLocaleString('fa-IR'); }

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

function openChatModal() { openModal('chat-modal'); loadChatMessages(); }

function renderBubble(isMine, msg) {
    const align = isMine ? 'justify-end' : 'justify-start';
    const color = isMine ? 'bg-blue-600 text-white' : 'bg-white text-slate-700 border';
    let fileHtml = '';
    if (msg.file_path) {
        const isImg = /\.(jpg|jpeg|png|webp|gif)$/i.test(msg.file_path);
        fileHtml = isImg ? `<img src="../${msg.file_path}" class="rounded-lg max-w-full mt-2">` : `<a href="../${msg.file_path}" target="_blank" class="underline text-xs block mt-2"><i class="fas fa-paperclip ml-1"></i>مشاهده فایل</a>`;
    }
    // پیام‌های خودِ شرکت قابل ویرایش و حذف‌اند (پیام‌های ما نه)
    const controls = isMine ? `
        <div class="flex gap-2 mt-1 opacity-70">
            <button onclick="editMyChatMessage(${msg.id})" class="text-[10px] underline hover:opacity-100">ویرایش</button>
            <button onclick="deleteMyChatMessage(${msg.id})" class="text-[10px] underline hover:opacity-100">حذف</button>
        </div>` : '';
    return `<div class="flex ${align}"><div class="${color} rounded-2xl px-4 py-2 text-sm max-w-[75%]"><span id="pmsg-text-${msg.id}">${msg.message || ''}</span>${fileHtml}<div class="text-[10px] opacity-60 mt-1" dir="ltr">${fmtBubbleTime(msg.created_at)}</div>${controls}</div></div>`;
}

async function editMyChatMessage(msgId) {
    const current = document.getElementById('pmsg-text-' + msgId).innerText;
    const updated = await showPortalPrompt('ویرایش پیام', current);
    if (updated === null || !updated.trim() || updated === current) return;
    const res = await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'chat_edit_message', message_id: msgId, message: updated})});
    const data = await res.json();
    if (data.ok) { showToast('پیام ویرایش شد.'); loadChatMessages(); }
    else showToast(data.error || 'خطا در ویرایش پیام.');
}

async function deleteMyChatMessage(msgId) {
    if (!await showPortalConfirm('حذف پیام', 'این پیام برای همیشه حذف می‌شود.')) return;
    const res = await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'chat_delete_message', message_id: msgId})});
    const data = await res.json();
    if (data.ok) { showToast('پیام حذف شد.'); loadChatMessages(); }
    else showToast(data.error || 'خطا در حذف پیام.');
}

async function loadChatMessages() {
    const companyId = document.getElementById('chat-company').value;
    const res = await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'chat_get_messages', company_id: companyId})});
    const data = await res.json();
    const body = document.getElementById('chat-body');
    if (!data.ok) { body.innerHTML = `<p class="text-center text-red-500 text-xs">${data.error || 'خطا'}</p>`; return; }
    body.innerHTML = data.messages.length ? data.messages.map(m => renderBubble(m.sender_type === 'COMPANY', m)).join('') : '<p class="text-center text-slate-400 text-xs mt-10">هنوز پیامی رد و بدل نشده.</p>';
    body.scrollTop = body.scrollHeight;
}

async function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    await fetch('../api/company_portal_actions.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'chat_send_message', company_id: document.getElementById('chat-company').value, message: text})});
    loadChatMessages();
}

async function sendChatFile() {
    const fileInput = document.getElementById('chat-file');
    if (!fileInput.files[0]) return;
    const fd = new FormData();
    fd.append('action', 'chat_send_message');
    fd.append('company_id', document.getElementById('chat-company').value);
    fd.append('file', fileInput.files[0]);
    try { await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd}); loadChatMessages(); }
    catch (e) { showToast('خطا در ارسال فایل.'); }
    fileInput.value = '';
}

document.getElementById('chat-input').addEventListener('keydown', e => { if (e.key === 'Enter') sendChatMessage(); });

loadRequests();

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
</body>
</html>
