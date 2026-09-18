<?php
// فایل: company-portal/index.php
// پنل وب ثبت‌کننده‌ی شرکت درخواست‌کننده: ثبت درخواست بیمه + آپلود تدریجیِ مدارک.
// یک کاربر می‌تواند به چند شرکت دسترسی داشته باشد؛ اگر فقط یکی دارد، پنل قفل روی همان می‌ماند.
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../api/_case_helpers.php';
require __DIR__ . '/../api/_company_helpers.php';

company_portal_session_start();
$loggedIn = !empty($_SESSION['company_user_id']);
$companies = [];
if ($loggedIn) {
    $stmt = $pdo->prepare("SELECT c.id, c.name FROM company_portal_user_companies cpuc
                            JOIN companies c ON c.id = cpuc.company_id
                            WHERE cpuc.portal_user_id = ? ORDER BY c.name");
    $stmt->execute([$_SESSION['company_user_id']]);
    $companies = $stmt->fetchAll();
    if (!$companies) { $_SESSION = []; session_destroy(); $loggedIn = false; }
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
    .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.6); z-index: 999; display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition:.2s; }
    .modal-overlay.active { opacity:1; pointer-events:auto; }
    .modal-content { background:#fff; border-radius: 20px; max-width: 520px; width: 92%; max-height: 88vh; overflow-y:auto; transform: scale(.96); transition:.2s; }
    .modal-overlay.active .modal-content { transform: scale(1); }
    .status-badge { font-size: 11px; font-weight: bold; padding: 4px 10px; border-radius: 999px; }
    .toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(100px); background:#1e293b; color:#fff; padding: 12px 22px; border-radius: 12px; font-size: 13px; font-weight: bold; z-index: 9999; transition:.3s; opacity:0; }
    .toast.show { transform: translateX(-50%) translateY(0); opacity:1; }
</style>
</head>
<body class="min-h-screen">

<?php if (!$loggedIn): ?>
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="card w-full max-w-sm p-8">
        <h1 class="text-xl font-black text-center mb-1">پنل شرکت‌ها</h1>
        <p class="text-xs text-slate-400 text-center mb-6">بیمه با ما</p>
        <div id="login-error" class="hidden bg-red-50 text-red-600 text-xs font-bold rounded-xl p-3 mb-4"></div>
        <div class="float-input">
            <label>نام کاربری</label>
            <input type="text" id="login-username" dir="ltr">
        </div>
        <div class="float-input">
            <label>رمز عبور</label>
            <input type="password" id="login-password" dir="ltr">
        </div>
        <button onclick="doLogin()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition-colors">ورود</button>
    </div>
</div>
<script>
async function doLogin() {
    const username = document.getElementById('login-username').value.trim();
    const password = document.getElementById('login-password').value;
    const errBox = document.getElementById('login-error');
    errBox.classList.add('hidden');
    try {
        const res = await fetch('../api/company_portal_auth.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'login', username, password})
        });
        const data = await res.json();
        if (data.ok) { location.reload(); }
        else { errBox.textContent = data.error || 'خطا در ورود.'; errBox.classList.remove('hidden'); }
    } catch (e) { errBox.textContent = 'خطا در ارتباط با سرور.'; errBox.classList.remove('hidden'); }
}
</script>

<?php else: ?>
<div class="max-w-3xl mx-auto p-4 pb-20">
    <div class="flex items-center justify-between py-5">
        <div>
            <h1 class="font-black text-lg"><?php echo count($companies) === 1 ? htmlspecialchars($companies[0]['name']) : 'چند شرکت'; ?></h1>
            <p class="text-xs text-slate-400"><?php echo htmlspecialchars($_SESSION['company_user_full_name']); ?></p>
        </div>
        <button onclick="doLogout()" class="text-xs font-bold text-red-500 hover-target"><i class="fas fa-sign-out-alt ml-1"></i>خروج</button>
    </div>

    <button onclick="openNewRequestModal()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 rounded-2xl shadow-lg shadow-blue-500/20 mb-6 transition-colors">
        <i class="fas fa-plus ml-2"></i> ثبت درخواست جدید
    </button>

    <h2 class="text-sm font-bold text-slate-500 mb-3">درخواست‌های من</h2>
    <div id="requests-list" class="space-y-3"></div>
</div>

<button onclick="openChatModal()" class="fixed bottom-5 left-5 w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-xl flex items-center justify-center text-xl z-50">
    <i class="fas fa-comments"></i>
</button>

<!-- مودال چت با بیمه با ما -->
<div id="chat-modal" class="modal-overlay">
    <div class="modal-content p-0 flex flex-col" style="height: 70vh;">
        <div class="p-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-lg">چت با بیمه با ما</h3>
            <button onclick="closeModal('chat-modal')" class="text-slate-400"><i class="fas fa-times"></i></button>
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
            <label class="text-slate-400 hover:text-blue-500 text-lg cursor-pointer">
                <i class="fas fa-paperclip"></i>
                <input type="file" id="chat-file" class="hidden" onchange="sendChatFile()">
            </label>
            <input type="text" id="chat-input" placeholder="پیام خود را بنویسید..." class="flex-1 border rounded-xl px-3 py-2 text-sm">
            <button onclick="sendChatMessage()" class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-bold hover:bg-blue-700">ارسال</button>
        </div>
    </div>
</div>

<!-- مودال درخواست جدید -->
<div id="new-request-modal" class="modal-overlay">
    <div class="modal-content p-6">
        <h3 class="font-bold text-lg mb-4">ثبت درخواست جدید</h3>
        <?php if (count($companies) > 1): ?>
        <div class="float-input">
            <label>برای کدام شرکت؟</label>
            <select id="nr-company"><?php foreach ($companies as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?></select>
        </div>
        <?php else: ?>
        <input type="hidden" id="nr-company" value="<?php echo $companies[0]['id']; ?>">
        <?php endif; ?>
        <div class="float-input">
            <label>بیمه‌گر</label>
            <select id="nr-insurer"><option value="PASARGAD">پاسارگاد</option><option value="IRAN">ایران</option></select>
        </div>
        <div class="float-input">
            <label>توضیح درخواست</label>
            <textarea id="nr-text" rows="4" placeholder="مثلاً: بیمه شخص ثالث برای ۳ دستگاه خودرو..."></textarea>
        </div>
        <div class="float-input">
            <label>فایل نامه (اختیاری - PDF یا عکس)</label>
            <input type="file" id="nr-letter" accept=".pdf,.jpg,.jpeg,.png,.webp">
        </div>
        <div class="flex gap-3 mt-2">
            <button onclick="closeModal('new-request-modal')" class="flex-1 bg-slate-100 hover:bg-slate-200 font-bold py-2.5 rounded-xl">انصراف</button>
            <button onclick="submitNewRequest()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl">ثبت</button>
        </div>
    </div>
</div>

<!-- مودال جزئیات درخواست -->
<div id="request-detail-modal" class="modal-overlay">
    <div class="modal-content p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-lg">جزئیات درخواست</h3>
            <button onclick="closeModal('request-detail-modal')" class="text-slate-400"><i class="fas fa-times"></i></button>
        </div>
        <div id="request-detail-body"></div>
        <div class="border-t mt-4 pt-4">
            <label class="text-xs font-bold text-slate-500 block mb-2">افزودن مدرک جدید به این درخواست</label>
            <p class="text-[11px] text-slate-400 mb-2">اگر می‌دانید این مدرک برای کدام پلاک است، وارد کنید تا سریع‌تر بررسی شود (اختیاری).</p>
            <div class="grid grid-cols-4 gap-2 mb-2" dir="ltr">
                <input type="text" id="du-p1" maxlength="2" placeholder="۱۲" class="text-center border rounded-lg p-2 text-xs">
                <input type="text" id="du-p2" maxlength="3" placeholder="۳۴۵" class="text-center border rounded-lg p-2 text-xs">
                <input type="text" id="du-letter" maxlength="3" placeholder="الف" class="text-center border rounded-lg p-2 text-xs">
                <input type="text" id="du-p4" maxlength="2" placeholder="۶۷" class="text-center border rounded-lg p-2 text-xs">
            </div>
            <input type="text" id="du-doc-type" placeholder="نوع مدرک (اختیاری، مثلاً: کارت ماشین)" class="w-full border rounded-lg p-2 text-xs mb-2">
            <input type="file" id="doc-upload-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.zip" class="mb-2 w-full text-xs">
            <button onclick="uploadDocument()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl text-sm">
                <i class="fas fa-upload ml-1"></i> آپلود مدرک
            </button>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
let activeRequestId = null;
const STATUS_FA = {NEW:'جدید', DOCS_PENDING:'در انتظار مدارک', DOCS_REVIEW:'در حال بررسی', READY_FOR_ISSUE:'آماده‌ی صدور', ISSUED:'صادر شده', CANCELLED:'لغو شده'};
const STATUS_COLOR = {NEW:'bg-blue-100 text-blue-700', DOCS_PENDING:'bg-amber-100 text-amber-700', DOCS_REVIEW:'bg-purple-100 text-purple-700', READY_FOR_ISSUE:'bg-cyan-100 text-cyan-700', ISSUED:'bg-emerald-100 text-emerald-700', CANCELLED:'bg-red-100 text-red-700'};

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
}
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function openNewRequestModal() { openModal('new-request-modal'); }

async function doLogout() {
    await fetch('../api/company_portal_auth.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'logout'})});
    location.reload();
}

async function loadRequests() {
    const res = await fetch('../api/company_portal_actions.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'my_requests'})});
    const data = await res.json();
    const box = document.getElementById('requests-list');
    if (!data.ok || !data.requests.length) { box.innerHTML = '<p class="text-center text-xs text-slate-400 py-10">هنوز درخواستی ثبت نکرده‌اید.</p>'; return; }
    box.innerHTML = data.requests.map(r => `
        <div class="card p-4 cursor-pointer hover:shadow-md transition-shadow" onclick="openRequestDetail(${r.id})">
            <div class="flex items-center justify-between mb-1">
                <span class="font-bold text-sm">درخواست #${r.id}${r.company_name ? ' - ' + r.company_name : ''}</span>
                <span class="status-badge ${STATUS_COLOR[r.status] || ''}">${STATUS_FA[r.status] || r.status}</span>
            </div>
            <p class="text-xs text-slate-500 line-clamp-2">${r.request_text ? r.request_text : '(بدون توضیح متنی)'}</p>
            <p class="text-[10px] text-slate-400 mt-2">${r.insurer === 'IRAN' ? 'بیمه ایران' : 'بیمه پاسارگاد'} · ثبت: ${r.created_at_jalali} · آخرین ویرایش: ${r.updated_at_jalali}</p>
        </div>
    `).join('');
}

async function submitNewRequest() {
    const fd = new FormData();
    fd.append('action', 'submit_request');
    fd.append('company_id', document.getElementById('nr-company').value);
    fd.append('insurer', document.getElementById('nr-insurer').value);
    fd.append('request_text', document.getElementById('nr-text').value.trim());
    const fileInput = document.getElementById('nr-letter');
    if (fileInput.files[0]) fd.append('letter_file', fileInput.files[0]);
    try {
        const res = await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd});
        const data = await res.json();
        if (data.ok) {
            showToast('درخواست با موفقیت ثبت شد.');
            closeModal('new-request-modal');
            document.getElementById('nr-text').value = ''; fileInput.value = '';
            loadRequests();
        } else { showToast(data.error || 'خطا در ثبت درخواست.'); }
    } catch (e) { showToast('خطا در ارتباط با سرور.'); }
}

async function openRequestDetail(id) {
    activeRequestId = id;
    const res = await fetch('../api/company_portal_actions.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'request_detail', request_id: id})});
    const data = await res.json();
    if (!data.ok) { showToast(data.error || 'خطا در دریافت اطلاعات.'); return; }
    const r = data.request;
    const docsHtml = data.documents.length ? data.documents.map(d => `
        <div class="flex items-center justify-between text-xs bg-slate-50 rounded-lg p-2.5 mb-2">
            <span><i class="fas fa-file ml-1 text-slate-400"></i> ${d.orig_name || 'فایل'}</span>
            <span class="text-[10px] ${d.status === 'ASSIGNED' ? 'text-emerald-600' : 'text-amber-500'} font-bold">${d.status === 'ASSIGNED' ? 'بررسی‌شده' : 'در انتظار بررسی'}</span>
        </div>`).join('') : '<p class="text-xs text-slate-400">هنوز مدرکی آپلود نشده.</p>';
    const platesHtml = data.plates.length ? data.plates.map(p => `
        <div class="flex items-center justify-between text-xs bg-slate-50 rounded-lg p-2.5 mb-2">
            <span>${[p.plate_p1, p.plate_p2, p.plate_letter, p.plate_p4].filter(Boolean).join(' ') || 'پلاک نامشخص'} ${p.insurance_type ? '(' + (p.insurance_type === 'BODY' ? 'بدنه' : 'ثالث') + ')' : ''}</span>
            <span class="text-[10px] font-bold ${p.status === 'ISSUED' ? 'text-emerald-600' : 'text-slate-400'}">${p.status === 'ISSUED' ? 'صادر شده' : 'در جریان'}</span>
        </div>`).join('') : '';
    document.getElementById('request-detail-body').innerHTML = `
        <div class="flex items-center gap-2 mb-3">
            <span class="status-badge ${STATUS_COLOR[r.status] || ''}">${STATUS_FA[r.status] || r.status}</span>
            <span class="text-xs text-slate-400">${r.insurer === 'IRAN' ? 'بیمه ایران' : 'بیمه پاسارگاد'}</span>
        </div>
        <p class="text-[10px] text-slate-400 mb-2">ثبت: ${r.created_at_jalali} · آخرین ویرایش: ${r.updated_at_jalali}</p>
        <p class="text-sm text-slate-600 mb-4">${r.request_text || '(بدون توضیح متنی)'}</p>
        ${platesHtml ? '<h4 class="text-xs font-bold text-slate-500 mb-2">پلاک‌ها</h4>' + platesHtml : ''}
        <h4 class="text-xs font-bold text-slate-500 mb-2">مدارک ارسالی</h4>
        ${docsHtml}
    `;
    openModal('request-detail-modal');
}

async function uploadDocument() {
    const fileInput = document.getElementById('doc-upload-input');
    if (!fileInput.files[0]) { showToast('یک فایل انتخاب کنید.'); return; }
    const fd = new FormData();
    fd.append('action', 'upload_document');
    fd.append('request_id', activeRequestId);
    fd.append('plate_p1', document.getElementById('du-p1').value.trim());
    fd.append('plate_p2', document.getElementById('du-p2').value.trim());
    fd.append('plate_letter', document.getElementById('du-letter').value.trim());
    fd.append('plate_p4', document.getElementById('du-p4').value.trim());
    fd.append('doc_type', document.getElementById('du-doc-type').value.trim());
    fd.append('file', fileInput.files[0]);
    try {
        const res = await fetch('../api/company_portal_actions.php', {method: 'POST', body: fd});
        const data = await res.json();
        if (data.ok) {
            showToast('مدرک آپلود شد.'); fileInput.value = '';
            ['du-p1','du-p2','du-letter','du-p4','du-doc-type'].forEach(id => document.getElementById(id).value = '');
            openRequestDetail(activeRequestId);
        } else { showToast(data.error || 'خطا در آپلود.'); }
    } catch (e) { showToast('خطا در ارتباط با سرور.'); }
}

function fmtBubbleTime(ts) { return new Date(ts.replace(' ', 'T')).toLocaleString('fa-IR'); }

function openChatModal() { openModal('chat-modal'); loadChatMessages(); }

function renderBubble(isMine, msg) {
    const align = isMine ? 'justify-end' : 'justify-start';
    const color = isMine ? 'bg-blue-600 text-white' : 'bg-white text-slate-700 border';
    let fileHtml = '';
    if (msg.file_path) {
        const isImg = /\.(jpg|jpeg|png|webp|gif)$/i.test(msg.file_path);
        fileHtml = isImg ? `<img src="../${msg.file_path}" class="rounded-lg max-w-full mt-2">` : `<a href="../${msg.file_path}" target="_blank" class="underline text-xs block mt-2"><i class="fas fa-paperclip ml-1"></i>مشاهده فایل</a>`;
    }
    return `<div class="flex ${align}"><div class="${color} rounded-2xl px-4 py-2 text-sm max-w-[75%]">${msg.message || ''}${fileHtml}<div class="text-[10px] opacity-60 mt-1" dir="ltr">${fmtBubbleTime(msg.created_at)}</div></div></div>`;
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
</script>
<?php endif; ?>
</body>
</html>
