<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>آزمایشگاه هوش مصنوعی | بیمه ماموت</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Regular.woff2') format('woff2'); font-weight: normal; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Bold.woff2') format('woff2'); font-weight: bold; }
        @font-face { font-family: 'Vazir'; src: url('Font/Vazir-Black.woff2') format('woff2'); font-weight: 900; }
        body { font-family: 'Vazir', sans-serif; background: #0f172a; color: #e2e8f0; }

        .glass-card { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.1); border-radius: 1.25rem; }

        .toast { padding: 12px 24px; border-radius: 12px; font-weight: bold; font-size: 13px; backdrop-filter: blur(16px); box-shadow: 0 10px 30px rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; gap: 10px; animation: toastEnter 0.4s cubic-bezier(0.2,0.8,0.2,1) forwards; }
        .toast-exit { animation: toastExit 0.4s ease-in forwards !important; }
        @keyframes toastEnter { from { opacity: 0; transform: translateY(-20px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes toastExit { from { opacity: 1; transform: translateY(0); } to { opacity: 0; transform: translateY(-10px) scale(0.9); } }

        .float-input { position: relative; margin-bottom: 1.5rem; transition: all 0.3s; }
        .float-input input, .float-input textarea { width: 100%; border: 1px solid rgba(255,255,255,0.2); border-radius: 12px; padding: 14px 16px; outline: none; transition: all 0.3s; background: rgba(0,0,0,0.2); color: #fff; font-weight: bold; }
        .float-input input:focus, .float-input textarea:focus { border-color: #00d2ff; box-shadow: 0 0 0 4px rgba(0, 210, 255, 0.1); }
        .float-input label { position: absolute; right: 16px; top: 14px; color: #94a3b8; transition: all 0.3s; pointer-events: none; background: transparent; padding: 0 4px; border-radius: 4px; font-size: 13px; font-weight: bold; }
        .float-input input:focus ~ label, .float-input input:not(:placeholder-shown) ~ label, .float-input textarea:focus ~ label, .float-input textarea:not(:placeholder-shown) ~ label { transform: translateY(-24px) scale(0.9); color: #00d2ff; background: #1e293b; }

        .section-divider { grid-column: 1 / -1; margin: .5rem 0 .25rem; font-size: 12px; font-weight: 900; color: #64748b; letter-spacing: .05em; }
        .fields-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 1rem; }
        @media (max-width: 640px) { .fields-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="p-6">

    <div id="toast-container" class="fixed top-6 left-1/2 -translate-x-1/2 z-[9999999] flex flex-col gap-3 pointer-events-none"></div>

    <div class="max-w-6xl mx-auto space-y-6">
        <div class="flex justify-between items-center glass-card p-4">
            <h1 class="text-2xl font-black text-white"><i class="fas fa-flask text-cyan-400 ml-2"></i>آزمایشگاه عیب‌یابی استخراج داده</h1>
            <a href="dashboard.php" class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg font-bold text-sm transition-colors">بازگشت به داشبورد <i class="fas fa-arrow-left mr-1"></i></a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="glass-card p-6 h-fit">
                <h2 class="text-lg font-bold text-cyan-400 mb-4">۱. آپلود مدرک جهت تست</h2>
                <input type="file" id="test-file" accept=".pdf" class="block w-full text-sm text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-cyan-500/10 file:text-cyan-400 hover:file:bg-cyan-500/20 mb-4 cursor-pointer">

                <button id="btn-test" onclick="runTest()" class="w-full bg-cyan-500 hover:bg-cyan-600 text-slate-900 font-black py-3 rounded-xl transition-all">
                    <i class="fas fa-microchip ml-1"></i> پردازش سریع فایل
                </button>

                <div id="loading" class="hidden mt-6 text-center text-cyan-400 font-bold">
                    <i class="fas fa-spinner fa-spin text-2xl mb-2"></i><p>در حال تحلیل...</p>
                </div>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-bold text-emerald-400 mb-4">۲. نتایج استخراج شده (Regex)</h2>

                <div class="fields-grid">
                    <div class="float-input"><input type="text" id="res-type" readonly placeholder=" "><label>نوع مدرک تشخیص داده شده</label></div>
                    <div class="float-input"><input type="text" id="res-companyname" readonly placeholder=" "><label>شرکت بیمه‌گر</label></div>
                    <div class="float-input"><input type="text" id="res-name" readonly placeholder=" "><label>نام شخص / بیمه‌گذار</label></div>
                    <div class="float-input"><input type="text" id="res-national" readonly placeholder=" " dir="ltr"><label>کد ملی</label></div>

                    <!-- فیلدهای اختصاصی معرفی‌نامه -->
                    <div class="float-input" id="box-personnel"><input type="text" id="res-personnel" readonly placeholder=" " dir="ltr"><label>کد پرسنلی</label></div>
                    <div class="float-input" id="box-company"><input type="text" id="res-company" readonly placeholder=" "><label>نام شرکت (معرفی‌نامه)</label></div>
                    <div class="float-input" id="box-date"><input type="text" id="res-date" readonly placeholder=" " dir="ltr"><label>تاریخ صدور نامه</label></div>

                    <!-- فیلدهای مشترک بیمه نامه (بدنه/ثالث) -->
                    <div class="float-input" id="box-policy"><input type="text" id="res-policy" readonly placeholder=" " dir="ltr"><label>شماره بیمه‌نامه</label></div>
                    <div class="float-input" id="box-uniquecode"><input type="text" id="res-uniquecode" readonly placeholder=" " dir="ltr"><label>شماره یکتا بیمه مرکزی</label></div>
                    <div class="float-input" id="box-plate"><input type="text" id="res-plate" readonly placeholder=" " dir="ltr"><label>پلاک</label></div>
                    <div class="float-input" id="box-phone"><input type="text" id="res-phone" readonly placeholder=" " dir="ltr"><label>شماره تماس بیمه‌گذار</label></div>
                    <div class="float-input" id="box-issuedate"><input type="text" id="res-issuedate" readonly placeholder=" " dir="ltr"><label>تاریخ صدور بیمه‌نامه</label></div>
                    <div class="float-input" id="box-renewal"><input type="text" id="res-renewal" readonly placeholder=" "><label>وضعیت تمدید</label></div>

                    <div class="float-input" id="box-cartype"><input type="text" id="res-cartype" readonly placeholder=" "><label>سیستم / تیپ خودرو</label></div>
                    <div class="float-input" id="box-model"><input type="text" id="res-model" readonly placeholder=" " dir="ltr"><label>مدل (سال ساخت)</label></div>
                    <div class="float-input" id="box-color"><input type="text" id="res-color" readonly placeholder=" "><label>رنگ خودرو</label></div>
                    <div class="float-input" id="box-engine"><input type="text" id="res-engine" readonly placeholder=" " dir="ltr"><label>شماره موتور</label></div>
                    <div class="float-input" id="box-chassis"><input type="text" id="res-chassis" readonly placeholder=" " dir="ltr"><label>شماره شاسی</label></div>
                    <div class="float-input" id="box-vin"><input type="text" id="res-vin" readonly placeholder=" " dir="ltr"><label>VIN</label></div>
                    <div class="float-input" id="box-carvalue"><input type="text" id="res-carvalue" readonly placeholder=" " dir="ltr"><label>ارزش خودرو (ریال)</label></div>
                    <div class="float-input" id="box-premium"><input type="text" id="res-premium" readonly placeholder=" " dir="ltr"><label>حق بیمه کل (ریال)</label></div>

                    <div class="float-input" id="box-coverages" style="grid-column: 1 / -1;">
                        <textarea id="res-coverages" rows="4" readonly placeholder=" " class="font-mono text-xs leading-relaxed"></textarea>
                        <label>پوشش‌های درخواستی</label>
                    </div>
                </div>
            </div>

            <div class="glass-card p-6 lg:col-span-2">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold text-amber-400">۳. متن خام استخراج شده</h2>
                    <span id="method-badge" class="bg-slate-800 text-xs px-3 py-1 rounded-lg text-slate-300">موتور پردازش: نامشخص</span>
                </div>
                <div class="float-input mb-0">
                    <textarea id="raw-text" rows="12" placeholder=" " readonly class="font-mono text-sm leading-relaxed text-amber-100"></textarea>
                    <label>خروجی خام PyMuPDF</label>
                </div>
            </div>

        </div>
    </div>

    <script>
        // شناسه تمام باکس‌های اختصاصی که بسته به نوع مدرک نمایش/مخفی می‌شوند
        const ALL_DYNAMIC_BOXES = [
            'box-personnel', 'box-company', 'box-date',
            'box-policy', 'box-uniquecode', 'box-plate', 'box-phone', 'box-issuedate', 'box-renewal',
            'box-cartype', 'box-model', 'box-color', 'box-engine', 'box-chassis', 'box-vin',
            'box-carvalue', 'box-premium', 'box-coverages'
        ];
        const ALL_RESULT_FIELDS = [
            'res-type','res-companyname','res-name','res-national','res-personnel','res-company','res-date',
            'res-policy','res-uniquecode','res-plate','res-phone','res-issuedate','res-renewal',
            'res-cartype','res-model','res-color','res-engine','res-chassis','res-vin',
            'res-carvalue','res-premium','res-coverages'
        ];

        function showToast(msg, type='info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast bg-slate-800 text-white`;
            if (type==='error') toast.classList.add('!bg-red-500');
            if (type==='success') toast.classList.add('!bg-emerald-500');
            toast.innerHTML = `<i class="fas fa-info-circle text-lg"></i> <span>${msg}</span>`;
            container.appendChild(toast);
            setTimeout(() => { toast.classList.add('toast-exit'); setTimeout(()=>toast.remove(), 400); }, 3500);
        }

        function setField(id, value) {
            const el = document.getElementById(id);
            if (el) el.value = value || '';
        }

        function formatCoverages(coverages) {
            if (!coverages || typeof coverages !== 'object' || Object.keys(coverages).length === 0) return '';
            return Object.entries(coverages).map(([k, v]) => `${k}: ${v} ریال`).join('\n');
        }

        async function runTest() {
            const fileInput = document.getElementById('test-file');
            if (!fileInput.files[0]) {
                showToast('لطفاً یک فایل انتخاب کنید.', 'error');
                return;
            }

            document.getElementById('btn-test').classList.add('hidden');
            document.getElementById('loading').classList.remove('hidden');

            // پاک کردن و مخفی‌سازی همه‌ی باکس‌های اختصاصی حین پردازش
            ALL_DYNAMIC_BOXES.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
            ALL_RESULT_FIELDS.forEach(id => setField(id, ''));
            document.getElementById('raw-text').value = '';
            document.getElementById('method-badge').innerText = 'در حال پردازش...';

            const formData = new FormData();
            formData.append('file', fileInput.files[0]);

            try {
                const res = await fetch('api/test_ocr_api.php', { method: 'POST', body: formData });
                const data = await res.json();

                document.getElementById('btn-test').classList.remove('hidden');
                document.getElementById('loading').classList.add('hidden');

                if (data.ok) {
                    showToast('پردازش با موفقیت انجام شد.', 'success');
                    document.getElementById('method-badge').innerText = 'موتور: ' + data.method;
                    document.getElementById('raw-text').value = data.raw_text || 'متنی یافت نشد!';

                    const ext = data.extracted || {};
                    setField('res-type', ext.ins_type);
                    setField('res-companyname', ext.ins_company);
                    setField('res-name', ext.insured_name);
                    setField('res-national', ext.national_id);
                    setField('res-personnel', ext.personnel_id);
                    setField('res-company', ext.company_name);
                    setField('res-date', ext.letter_date);

                    setField('res-policy', ext.policy_num);
                    setField('res-uniquecode', ext.unique_code);
                    setField('res-plate', ext.plate);
                    setField('res-phone', ext.phone);
                    setField('res-issuedate', ext.issue_date);
                    setField('res-renewal', ext.renewal_status || (ext.prev_policy ? 'تمدیدی' : ''));

                    const carTypeCombined = [ext.car_system, ext.car_type].filter(Boolean).join(' ');
                    setField('res-cartype', carTypeCombined);
                    setField('res-model', ext.model_year);
                    setField('res-color', ext.car_color);
                    setField('res-engine', ext.engine_num);
                    setField('res-chassis', ext.chassis_num);
                    setField('res-vin', ext.vin);
                    setField('res-carvalue', ext.car_value);
                    setField('res-premium', ext.total_premium);
                    setField('res-coverages', formatCoverages(ext.coverages));

                    // نمایش هوشمند باکس‌ها بر اساس نوع مدرک
                    const showBoxes = (ids) => ids.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'block'; });

                    if (ext.ins_type === 'معرفی‌نامه') {
                        showBoxes(['box-personnel', 'box-company', 'box-date', 'box-national']);
                    } else if (ext.ins_type === 'بدنه' || ext.ins_type === 'ثالث') {
                        showBoxes([
                            'box-policy', 'box-uniquecode', 'box-plate', 'box-phone', 'box-issuedate', 'box-renewal',
                            'box-cartype', 'box-model', 'box-color', 'box-engine', 'box-chassis', 'box-vin',
                            'box-premium', 'box-coverages'
                        ]);
                        if (ext.ins_type === 'بدنه' && ext.car_value) {
                            showBoxes(['box-carvalue']);
                        }
                    } else {
                        // نوع نامشخص: فقط فیلدهای پایه (نام/کد ملی) که همیشه نمایش داده می‌شوند
                    }
                } else {
                    showToast(data.error || 'خطا در پردازش', 'error');
                    if (data.raw) document.getElementById('raw-text').value = "خطای سرور:\n" + data.raw;
                }
            } catch (e) {
                document.getElementById('btn-test').classList.remove('hidden');
                document.getElementById('loading').classList.add('hidden');
                showToast('خطای شبکه یا تایم‌اوت.', 'error');
            }
        }
    </script>
</body>
</html>
