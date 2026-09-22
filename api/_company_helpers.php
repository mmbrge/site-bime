<?php
// فایل: api/_company_helpers.php
// توابع مربوط به ماژول «شرکت‌ها»: نام‌گذاری/مسیر بایگانی و کنترل دسترسی.
// برای اجزای مشترک (تاریخ جلالی، sanitize_folder_name، name_join و ...)
// به‌جای تکرار، از api/_case_helpers.php استفاده می‌شود (باید قبل از این فایل include شده باشد).

// نام کامل نمایشی پلاک از روی ۴ بخش، هم‌شکل با پلاک‌های پرسنلی («۱۲ایران - ۳۴۵ الف ۶۷»)
function company_plate_display($p1, $p2, $letter, $p4) {
    $p1 = trim((string)$p1); $p2 = trim((string)$p2); $letter = trim((string)$letter); $p4 = trim((string)$p4);
    if ($p1 === '' && $p2 === '' && $letter === '' && $p4 === '') return null;
    return "{$p1}ایران - {$p2} {$letter} {$p4}";
}

// شناسه‌ی نمایشیِ یک ردیف: اگر پلاک دارد پلاک، وگرنه شماره شاسی (لیفتراک‌ها و
// خودروهای صفرکیلومتر پلاک ندارند و فقط با شماره شاسی شناخته می‌شوند). همه‌جا -
// جدول‌ها، نام پوشه، نام فایل و فهرست متنی - از همین استفاده می‌شود.
function company_row_label($row) {
    $plate = company_plate_display($row['plate_p1'] ?? '', $row['plate_p2'] ?? '', $row['plate_letter'] ?? '', $row['plate_p4'] ?? '');
    if ($plate) return $plate;
    $chassis = trim((string)($row['chassis_no'] ?? ''));
    return $chassis !== '' ? $chassis : 'بدون‌پلاک';
}

// آیا این ردیف با شماره شاسی شناخته می‌شود (نه پلاک)؟
function company_row_is_chassis($row) {
    return !company_plate_display($row['plate_p1'] ?? '', $row['plate_p2'] ?? '', $row['plate_letter'] ?? '', $row['plate_p4'] ?? '')
        && trim((string)($row['chassis_no'] ?? '')) !== '';
}

// ریشه‌ی بایگانی شرکتی، کاملاً جدا از شخصِ «بایگانی صادره»ی پرسنلی (بعد از صدور
// یک کپی هم به بایگانی صادره‌ی مشترک می‌رود - نگاه کنید به company_copy_to_shared_sadere)
function company_archive_root($siteRoot) {
    return archive_root($siteRoot) . '/بایگانی شرکتی';
}

// ساختار پوشه‌بندیِ یک درخواست، طبق آخرین خواسته‌ی کارفرما، از بیرون به داخل:
//   بایگانی شرکتی / {سال درخواست مثلاً ۱۴۰۵} / {نام شرکت} / {ماه درخواست به حروف مثلاً شهریور}
//   / {تاریخ درخواست مثلاً ۱۴۰۵.۰۶.۱۲} / {پوشه‌ی هر پلاک}
// «نام شرکت» بین سال و ماه نگه داشته شده چون بدون آن، دو شرکت که در یک روز نامه
// می‌دهند روی هم می‌افتند. سطح «بدنه/ثالث» حذف شده چون خودِ نامِ پوشه‌ی پلاک از
// قبل با «(بدنه)» یا «(ثالث)» شروع می‌شود و آن سطح اضافه و زائد بود.
function company_request_date_folder($siteRoot, $requestCreatedTimestamp, $companyName) {
    [$jy, $jm, ] = jalali_from_gregorian_ts($requestCreatedTimestamp);
    $letterDate = jalali_from_gregorian_ts_dotted($requestCreatedTimestamp);
    return company_archive_root($siteRoot) . '/' . $jy . '/' . sanitize_folder_name($companyName)
        . '/' . jalali_month_name($jm) . '/' . $letterDate;
}

// پوشه‌ی موقتِ مدارکِ تخصیص‌نیافته‌ی یک شرکت (پیش از تگ‌گذاری دستی)
function company_unassigned_temp_path($siteRoot, $companyName) {
    return temp_archive_root($siteRoot) . '/شرکت‌ها/' . sanitize_folder_name($companyName) . '/نامرتب';
}

// داخلِ پوشه‌ی تاریخِ درخواست، اول پوشه‌ی «بدنه» و «ثالث» می‌آید و پوشه‌ی هر ردیف
// (و نامه‌ی همان نوع) داخلِ آن می‌نشیند - طبق ساختار خواسته‌شده:
//   {تاریخ}/بدنه/(نامه‌ی درخواست) ....pdf
//   {تاریخ}/بدنه/{۳۰}(بدنه) ۲۱ایران - ۹۸۹ ع ۴۱/
function company_request_base_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum = null) {
    return company_request_date_folder($siteRoot, $requestCreatedTimestamp, $companyName)
        . '/' . insurance_type_fa($insuranceTypeEnum);
}

// نام پوشه‌ی هر ردیف، پیش از صدور: «{روزِ ماهِ تاریخ انقضا}(بدنه|ثالث) پلاک»
// روزِ انقضا داخلِ آکولاد نوشته می‌شود: «{30}(بدنه) 21ایران - 515 ع 44».
// خودروهای صفرکیلومتر تاریخ انقضا ندارند، پس آکولاد هم ندارند و با شماره شاسی
// نوشته می‌شوند: «(بدنه) SGG10197IRF553».
function company_plate_folder_name($expiryDate, $insuranceTypeEnum, $plateDisplay) {
    $dayPart = '';
    if ($expiryDate) {
        $ts = is_numeric($expiryDate) ? $expiryDate : strtotime($expiryDate);
        if ($ts) { [, , $jd] = jalali_from_gregorian_ts($ts); $dayPart = '{' . sprintf('%02d', intval($jd)) . '}'; }
    }
    $prefix = $dayPart . '(' . insurance_type_fa($insuranceTypeEnum) . ')';
    return sanitize_folder_name($prefix . ' ' . ($plateDisplay ?: 'بدون‌پلاک'));
}

// مسیر کامل پوشه‌ی یک پلاک پیش از صدور (بدون نیاز به دانستن insurance_type دوباره،
// چون از خودِ رکورد پلاک گرفته می‌شود - نگاه کنید به فراخوانی‌ها در company_actions.php)
function build_company_plate_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum, $expiryDate, $plateDisplay) {
    return company_request_base_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum)
        . '/' . company_plate_folder_name($expiryDate, $insuranceTypeEnum, $plateDisplay);
}

// پوشه‌ی نهایی پس از صدور: «پلاک - نام شرکت - شماره بیمه‌نامه - VIN»،
// دقیقاً هم‌الگو با build_case_folder_name_issued پرسنلی ولی با نام شرکت به‌جای بیمه‌گذار
function build_company_issued_folder_name($plateDisplay, $companyName, $policyNum, $vin) {
    return sanitize_folder_name(name_join([plate_for_filename($plateDisplay) ?: 'بدون‌پلاک', $companyName, policy_number_for_filename($policyNum), $vin]));
}

// پس از صدور، علاوه بر تغییرِ نامِ پوشه‌ی خودِ بایگانی شرکتی، یک *کپی* (نه انتقال)
// هم داخل بایگانی صادره‌ی مشترک (همان‌جایی که بیمه‌نامه‌های پرسنلی هم هستند) قرار
// می‌گیرد تا یک‌جا هم بشود همه‌ی صادره‌های شرکتی و پرسنلی را با هم دید.
function company_copy_to_shared_sadere($siteRoot, $issuedFolderAbsPath, $issuedTimestamp, $insuranceTypeEnum, $issuedFolderName) {
    $dest = build_sadere_base_path($siteRoot, $issuedTimestamp, $insuranceTypeEnum) . '/' . $issuedFolderName;
    if (is_dir($issuedFolderAbsPath)) {
        copy_dir_recursive($issuedFolderAbsPath, $dest);
        return is_dir($dest);
    }
    return false;
}

// =====================================================================
//  فهرست انواع مدرکِ ماژول شرکت‌ها + چک‌لیستِ هر ردیف (هر پلاک) بر اساس نوع بیمه
// =====================================================================

// کلید => برچسب فارسی. همین برچسب هم در نام‌گذاری فایل استفاده می‌شود:
// «(سند) 21ایران - 693 ع 31»
function company_doc_types() {
    return [
        'car_card_front'    => 'کارت ماشین رو',
        'car_card_back'     => 'کارت ماشین پشت',
        'ownership_doc'     => 'سند',
        'prev_third_policy' => 'بیمه ثالث قبل',
        'prev_body_policy'  => 'بیمه بدنه قبل',
        'health_inspection' => 'بازدید سلامت',
        'health_report'     => 'گزارش بازدید',
        'other'             => 'سایر مدارک',
        // کلید قدیمیِ باقی‌مانده از نسخه‌های قبل (داده‌ی زنده دارد، پس پاک نمی‌شود)
        'car_card_or_title' => 'کارت ماشین یا سند',
    ];
}

function company_doc_type_label($key) {
    return company_doc_types()[$key] ?? ($key ?: 'نامشخص');
}

// مدرکی که به‌صورت «پوشه» ذخیره می‌شود (زیپِ چندفایلی) نه یک فایل تکی
function company_doc_type_is_folder($key) {
    return $key === 'health_inspection';
}

// چک‌لیستِ یک ردیف. هر آیتم یک «گروه» است، چون مثلاً «کارت ماشین پشت و رو یا سند»
// با دو راهِ مختلف کامل می‌شود. خروجی برای هر آیتم:
//   key, label, required (bool), satisfied (bool), upload_types (کلیدهایی که
//   می‌توان برایش آپلود کرد), hint (توضیح)
// قواعد (طبق توضیح کارفرما):
//   - ثالث: «کارت ماشین پشت و رو یا سند» اجباری؛ «بیمه ثالث قبل» اختیاری.
//   - بدنه: «کارت ماشین پشت و رو یا سند» اجباری؛ «بیمه بدنه قبل» در صورت وجود
//     اجباری (با has_prev_body مشخص می‌شود)؛ «بازدید سلامت» برای بعضی خودروها
//     اجباری است و برای بعضی نه (skip_health_inspection)؛ و «گزارش بازدید» در
//     صورت وجودِ بازدید سلامت اجباری است.
function company_plate_checklist($insuranceTypeEnum, $skipHealthInspection, array $assignedDocTypes, $hasPrevBody = null) {
    $has = fn($k) => in_array($k, $assignedDocTypes, true);
    $items = [];

    // ---- گروه ۱: کارت ماشین (پشت و رو) یا سند - همیشه اجباری ----
    $cardOk = ($has('car_card_front') && $has('car_card_back')) || $has('ownership_doc') || $has('car_card_or_title');
    $items[] = [
        'key' => 'car_card_or_title',
        'label' => 'کارت ماشین (پشت و رو) یا سند',
        'required' => true,
        'satisfied' => $cardOk,
        'upload_types' => ['car_card_front', 'car_card_back', 'ownership_doc'],
        'hint' => 'یا هر دو روی کارت ماشین، یا سند مالکیت',
    ];

    if ($insuranceTypeEnum === 'BODY') {
        // ---- بیمه بدنه قبل: در صورت وجود، اجباری ----
        $items[] = [
            'key' => 'prev_body_policy',
            'label' => 'بیمه بدنه قبل',
            'required' => ($hasPrevBody === 'YES'),
            'satisfied' => $has('prev_body_policy'),
            'upload_types' => ['prev_body_policy'],
            'hint' => $hasPrevBody === 'NO' ? 'این خودرو بیمه بدنه قبلی ندارد' : 'در صورت وجود، اجباری است',
        ];

        // ---- بازدید سلامت: برای بعضی خودروها اجباری، برای بعضی نه ----
        $healthNeeded = !$skipHealthInspection;
        $items[] = [
            'key' => 'health_inspection',
            'label' => 'بازدید سلامت',
            'required' => $healthNeeded,
            'satisfied' => $has('health_inspection'),
            'upload_types' => ['health_inspection'],
            'hint' => $healthNeeded ? 'عکس‌های بازدید (می‌توانید زیپ بفرستید)' : 'این خودرو نیاز به بازدید سلامت ندارد',
        ];

        // ---- گزارش بازدید: فقط اگر بازدید سلامت در جریان/موجود باشد ----
        if ($healthNeeded || $has('health_inspection')) {
            $items[] = [
                'key' => 'health_report',
                'label' => 'گزارش بازدید',
                'required' => true,
                'satisfied' => $has('health_report'),
                'upload_types' => ['health_report'],
                'hint' => 'در صورت وجود بازدید سلامت، گزارشش هم اجباری است',
            ];
        }
    } else {
        // ---- ثالث: بیمه ثالث قبل اختیاری ----
        $items[] = [
            'key' => 'prev_third_policy',
            'label' => 'بیمه ثالث قبل',
            'required' => false,
            'satisfied' => $has('prev_third_policy'),
            'upload_types' => ['prev_third_policy'],
            'hint' => 'اختیاری است',
        ];
    }

    return $items;
}

// فهرستِ «چه چیزی کم دارد» - فقط آیتم‌های اجباریِ تکمیل‌نشده
function company_plate_missing_docs($insuranceTypeEnum, $skipHealthInspection, array $assignedDocTypes, $hasPrevBody = null) {
    $missing = [];
    foreach (company_plate_checklist($insuranceTypeEnum, $skipHealthInspection, $assignedDocTypes, $hasPrevBody) as $it) {
        if ($it['required'] && !$it['satisfied']) $missing[$it['key']] = $it['label'];
    }
    return $missing;
}

// مدارکی که این ردیف دارد، به‌صورت برچسب فارسی (برای فهرست متنیِ خروجی)
function company_plate_present_labels(array $assignedDocTypes) {
    $labels = [];
    foreach ($assignedDocTypes as $k) {
        if ($k === null || $k === '') continue;
        $labels[company_doc_type_label($k)] = true;
    }
    return array_keys($labels);
}

// برچسب فارسیِ وضعیت هر ردیف
function company_plate_status_fa($status) {
    return [
        'PENDING'         => 'در انتظار مدارک',
        'READY_FOR_ISSUE' => 'مدارک کامل - آماده‌ی صدور',
        'WITH_BOSS'       => 'ارسال‌شده برای رئیس',
        'IN_ISSUANCE'     => 'در حال صدور',
        'ISSUED'          => 'صادر شد',
        'CANCELLED'       => 'لغو شد',
    ][$status] ?? $status;
}

// وضعیت هر ردیف بر اساس کامل‌بودن مدارک به‌طور خودکار بین «در انتظار مدارک» و
// «آماده‌ی صدور» جابه‌جا می‌شود؛ مرحله‌های دستی (ارسال به رئیس / در حال صدور /
// صادر شد / لغو) دست‌نخورده می‌مانند.
function company_sync_plate_status($pdo, $plateId) {
    $stmt = $pdo->prepare("SELECT insurance_type, skip_health_inspection, has_prev_body, status FROM company_request_plates WHERE id = ?");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch();
    if (!$plate) return null;
    if (!in_array($plate['status'], ['PENDING', 'READY_FOR_ISSUE'], true)) return $plate['status'];

    $stmt = $pdo->prepare("SELECT doc_type FROM company_documents WHERE plate_id = ? AND status = 'ASSIGNED'");
    $stmt->execute([$plateId]);
    $types = array_filter(array_column($stmt->fetchAll(), 'doc_type'));
    $missing = company_plate_missing_docs($plate['insurance_type'], (bool)$plate['skip_health_inspection'], $types, $plate['has_prev_body']);
    $newStatus = $missing ? 'PENDING' : 'READY_FOR_ISSUE';
    if ($newStatus !== $plate['status']) {
        $pdo->prepare("UPDATE company_request_plates SET status = ? WHERE id = ?")->execute([$newStatus, $plateId]);
    }
    return $newStatus;
}

// نامِ فایلِ نامه‌ی درخواست. قبلاً چند نامه در یک روز فقط با «_1» و «_2» از هم جدا
// می‌شدند؛ حالا نوعِ بیمه‌ی درخواستی داخلِ نام می‌آید تا خوانا باشد:
//   «(نامه‌ی درخواست) دیلی مارکت - 1405.06.29 - بدنه.pdf»
function company_letter_filename($companyName, $requestCreatedTs, $typesFa, $ext) {
    $base = '(نامه‌ی درخواست) ' . $companyName . ' - ' . jalali_from_gregorian_ts_dotted($requestCreatedTs);
    if ($typesFa) $base .= ' - ' . $typesFa;
    return sanitize_folder_name($base) . ($ext ? '.' . strtolower($ext) : '');
}

// نوع(های) بیمه‌ی یک درخواست به فارسی: «بدنه»، «ثالث» یا «بدنه و ثالث»
function company_request_types_fa($pdo, $requestId) {
    $stmt = $pdo->prepare("SELECT DISTINCT insurance_type FROM company_request_plates WHERE request_id = ?");
    $stmt->execute([$requestId]);
    $types = array_filter(array_column($stmt->fetchAll(), 'insurance_type'));
    if (!$types) return '';
    $fa = array_map('insurance_type_fa', $types);
    sort($fa);
    return implode(' و ', array_unique($fa));
}

// نام فایلِ هر مدرکِ بارگزاری‌شده، دقیقاً طبق قالبِ خواسته‌شده:
//   «(سند) 21ایران - 693 ع 31»   /   «(بیمه بدنه قبل) 21ایران - 693 ع 31»
// (خط‌تیره‌ی داخلیِ خودِ پلاک عمداً حفظ می‌شود - در نام‌گذاریِ مدارک، برخلاف نام
//  پوشه‌ی بیمه‌نامه‌ی صادره، پلاک همان شکل خوانای معمولش را دارد)
function company_doc_filename($docTypeKey, $plateDisplay, $ext) {
    $base = '(' . company_doc_type_label($docTypeKey) . ') ' . ($plateDisplay ?: 'بدون‌پلاک');
    return sanitize_folder_name($base) . ($ext ? '.' . strtolower($ext) : '');
}

// نامِ پوشه‌ی مدرکِ پوشه‌ای (بازدید سلامت) - همان قالب، ولی بدون پسوند
function company_doc_foldername($docTypeKey, $plateDisplay) {
    return sanitize_folder_name('(' . company_doc_type_label($docTypeKey) . ') ' . ($plateDisplay ?: 'بدون‌پلاک'));
}

// فهرست متنیِ ریزِ یک درخواست - همان قالبی که کارفرما خواسته:
//   55ایران - 456 ص 25 ( بیمه بدنه قبل ، سند )
//       ناموجود: بازدید سلامت، گزارش بازدید
function company_request_rows_text_report($pdo, $requestId) {
    $stmt = $pdo->prepare("SELECT cr.*, c.name AS company_name FROM company_requests cr
                            JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) return null;

    $stmt = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ? ORDER BY id");
    $stmt->execute([$requestId]);
    $plates = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT plate_id, doc_type FROM company_documents WHERE request_id = ? AND status = 'ASSIGNED'");
    $stmt->execute([$requestId]);
    $byPlate = [];
    foreach ($stmt->fetchAll() as $d) {
        if (!$d['plate_id']) continue;
        $byPlate[$d['plate_id']][] = $d['doc_type'];
    }

    $lines = [];
    $lines[] = 'شرکت ' . $req['company_name'] . ' - نامه‌ی ' . jalali_from_gregorian_ts_dotted(strtotime($req['created_at']));
    $lines[] = '';
    foreach ($plates as $p) {
        $types = array_values(array_filter($byPlate[$p['id']] ?? []));
        $display = company_row_label($p);
        $present = company_plate_present_labels($types);
        $line = $display . ' (' . insurance_type_fa($p['insurance_type']) . ')';
        $line .= $present ? ' ( ' . implode(' ، ', $present) . ' )' : ' ( بدون مدرک )';
        $lines[] = $line;
        $missing = company_plate_missing_docs($p['insurance_type'], (bool)$p['skip_health_inspection'], $types, $p['has_prev_body'] ?? null);
        if ($missing) $lines[] = '    ناموجود: ' . implode('، ', array_values($missing));
    }

    $total = count($plates);
    $body = 0; $third = 0; $issued = 0;
    foreach ($plates as $p) {
        if ($p['insurance_type'] === 'BODY') $body++; else $third++;
        if ($p['status'] === 'ISSUED') $issued++;
    }
    $lines[] = '';
    $lines[] = "جمع: {$total} ردیف ({$body} بدنه، {$third} ثالث) - صادرشده: {$issued}";
    return implode("\n", $lines);
}

// مسیر پوشه‌ی «نامه»ی یک درخواست (سطح بالاتر از تفکیک بدنه/ثالث، چون یک نامه
// می‌تواند چند پلاک از هر دو نوع را همزمان پوشش بدهد)
function company_request_letter_folder($siteRoot, $requestCreatedTs, $companyName) {
    return company_request_date_folder($siteRoot, $requestCreatedTs, $companyName);
}

// پوشه‌ی مالیِ یک درخواست (فیش‌های کارگزاری/پاسارگاد این درخواست، جدا از پوشه‌ی
// مالیِ پرسنلی که سراسری است - طبق خواسته‌ی صریح کارفرما، بایگانی مالی شرکتی
// باید داخل خودِ پوشه‌بندیِ همان درخواست بماند)
function company_request_finance_folder($siteRoot, $requestCreatedTs, $companyName, $target) {
    return company_request_letter_folder($siteRoot, $requestCreatedTs, $companyName)
        . '/مالی/' . ($target === 'PASARGAD' ? 'فیش‌های پاسارگاد' : 'فیش‌های کارگزاری');
}

// پوشه‌ی یک پلاک را (بر اساس وضعیت فعلی‌اش) محاسبه می‌کند و اگر پوشه‌ی قبلی‌اش
// جای دیگری بود (مثلاً چون insurance_type بعداً عوض شده) آن را به مسیر جدید
// منتقل می‌کند - خودترمیم‌گر، هم برای اولین بار و هم برای اصلاحات بعدی مناسب است.
function ensure_plate_folder($pdo, $siteRoot, $plateId) {
    $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, cr.created_at AS request_created_at, c.name AS company_name
                            FROM company_request_plates crp
                            JOIN company_requests cr ON cr.id = crp.request_id
                            JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch();
    if (!$plate) return null;

    // باگ واقعیِ رفع‌شده: بعد از صدور، نامِ پوشه همان «پلاک - شرکت - شماره بیمه‌نامه -
    // VIN» است؛ اگر این تابع بعد از صدور هم دوباره صدا زده می‌شد (مثلاً برای آپلود یک
    // مدرک تکمیلی) پوشه را به نامِ پیش‌از‌صدور برمی‌گرداند و نام‌گذاریِ صادره از بین
    // می‌رفت. پس پوشه‌ی پلاکِ صادرشده هرگز تغییر نام داده نمی‌شود.
    if ($plate['status'] === 'ISSUED' && $plate['folder_path']) {
        if (!is_dir($plate['folder_path'])) @mkdir($plate['folder_path'], 0755, true);
        return $plate['folder_path'];
    }

    $plateDisplay = company_row_label($plate);
    $desired = build_company_plate_folder($siteRoot, strtotime($plate['request_created_at']), $plate['company_name'], $plate['insurance_type'], $plate['expiry_date'], $plateDisplay);

    if ($plate['folder_path'] && $plate['folder_path'] !== $desired && is_dir($plate['folder_path'])) {
        if (!is_dir(dirname($desired))) @mkdir(dirname($desired), 0755, true);
        if (!is_dir($desired) && @rename($plate['folder_path'], $desired)) {
            // مسیرهای ذخیره‌شده‌ی مدارک هم باید با نام/محلِ جدیدِ پوشه هماهنگ شوند،
            // وگرنه لینکِ مدارک بعد از جابه‌جایی پوشه می‌شکند
            company_rewrite_doc_paths($pdo, $siteRoot, $plateId, $plate['folder_path'], $desired);
        }
    }
    if (!is_dir($desired)) @mkdir($desired, 0755, true);
    if ($plate['folder_path'] !== $desired) {
        $pdo->prepare("UPDATE company_request_plates SET folder_path = ? WHERE id = ?")->execute([$desired, $plateId]);
    }
    return $desired;
}

// وقتی پوشه‌ی یک پلاک جابه‌جا/تغییرنام می‌شود، file_path مدارکِ همان پلاک (و فایل
// بیمه‌نامه‌ی صادرشده‌اش) هم باید با مسیر جدید هماهنگ شود.
function company_rewrite_doc_paths($pdo, $siteRoot, $plateId, $oldAbs, $newAbs) {
    $oldRel = ltrim(str_replace($siteRoot, '', $oldAbs), '/');
    $newRel = ltrim(str_replace($siteRoot, '', $newAbs), '/');
    if ($oldRel === '' || $oldRel === $newRel) return;
    $pdo->prepare("UPDATE company_documents SET file_path = REPLACE(file_path, ?, ?) WHERE plate_id = ?")
        ->execute([$oldRel, $newRel, $plateId]);
    $pdo->prepare("UPDATE company_request_plates SET issued_file_path = REPLACE(issued_file_path, ?, ?) WHERE id = ? AND issued_file_path IS NOT NULL")
        ->execute([$oldRel, $newRel, $plateId]);
}

// =====================================================================
//  بایگانی‌کردنِ یک مدرک روی پوشه‌ی ردیفِ خودش، با نام‌گذاریِ استاندارد
// =====================================================================
// ساختار نهایی داخل پوشه‌ی هر پلاک:
//   (سند) 21ایران - 693 ع 31.pdf
//   (بیمه بدنه قبل) 21ایران - 693 ع 31.pdf
//   (بازدید سلامت) 21ایران - 693 ع 31/            <-- پوشه (زیپِ بازدید اینجا باز می‌شود)
//        (گزارش بازدید) 21ایران - 693 ع 31.pdf     <-- گزارش داخل همان پوشه
// خروجی: مسیر نسبیِ جدیدِ مدرک (یا مسیر قبلی، اگر جابه‌جایی ممکن نشد)
function company_place_document($pdo, $siteRoot, $docId, $plateId, $docType) {
    $stmt = $pdo->prepare("SELECT * FROM company_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc) return null;

    $stmt = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch();
    if (!$plate) return $doc['file_path'];

    $plateFolder = ensure_plate_folder($pdo, $siteRoot, $plateId);
    if (!$plateFolder) return $doc['file_path'];
    $plateDisplay = company_row_label($plate);

    $absOld = $siteRoot . '/' . $doc['file_path'];
    $newRel = $doc['file_path'];

    // پوشه‌ی «بازدید سلامت» این پلاک - هم مقصدِ خودِ بازدید و هم جایی که گزارش بازدید می‌نشیند
    $healthDir = $plateFolder . '/' . company_doc_foldername('health_inspection', $plateDisplay);

    if ($docType === 'health_inspection') {
        if (!is_dir($healthDir)) @mkdir($healthDir, 0755, true);
        if (is_file($absOld) && strtolower(pathinfo($absOld, PATHINFO_EXTENSION)) === 'zip') {
            // زیپِ بازدید داخل همان پوشه باز می‌شود (خودِ زیپ نگه داشته نمی‌شود)
            if (company_extract_zip_to_dir($absOld, $healthDir) !== false) {
                @unlink($absOld);
                $newRel = ltrim(str_replace($siteRoot, '', $healthDir), '/');
            }
        } elseif (is_dir($absOld) && realpath($absOld) !== realpath($healthDir)) {
            copy_dir_recursive($absOld, $healthDir);
            $newRel = ltrim(str_replace($siteRoot, '', $healthDir), '/');
        } elseif (is_file($absOld)) {
            // یک عکس/فایل تکی از بازدید: داخل همان پوشه با نام استاندارد می‌نشیند
            $ext = strtolower(pathinfo($absOld, PATHINFO_EXTENSION)) ?: 'jpg';
            $dest = unique_dest_path($healthDir . '/' . company_doc_filename('health_inspection', $plateDisplay, $ext));
            if (@rename($absOld, $dest)) $newRel = ltrim(str_replace($siteRoot, '', $dest), '/');
        } else {
            $newRel = ltrim(str_replace($siteRoot, '', $healthDir), '/');
        }
    } elseif ($docType === 'health_report') {
        // گزارش بازدید کنار عکس‌های بازدید، داخل همان پوشه‌ی «بازدید سلامت»
        if (!is_dir($healthDir)) @mkdir($healthDir, 0755, true);
        if (is_file($absOld)) {
            $ext = strtolower(pathinfo($absOld, PATHINFO_EXTENSION)) ?: 'pdf';
            $dest = unique_dest_path($healthDir . '/' . company_doc_filename('health_report', $plateDisplay, $ext));
            if (@rename($absOld, $dest)) $newRel = ltrim(str_replace($siteRoot, '', $dest), '/');
        }
    } elseif (is_file($absOld)) {
        // بقیه‌ی مدارک: مستقیم داخل پوشه‌ی خودِ پلاک، با نام «(نوع مدرک) پلاک»
        $ext = strtolower(pathinfo($absOld, PATHINFO_EXTENSION)) ?: 'pdf';
        $dest = unique_dest_path($plateFolder . '/' . company_doc_filename($docType ?: 'other', $plateDisplay, $ext));
        if (realpath($absOld) !== realpath($dest) && @rename($absOld, $dest)) {
            $newRel = ltrim(str_replace($siteRoot, '', $dest), '/');
        }
    }

    $pdo->prepare("UPDATE company_documents SET file_path = ? WHERE id = ?")->execute([$newRel, $docId]);
    return $newRel;
}

// =====================================================================
//  ورودِ ردیف‌های یک نامه از خروجیِ هوش مصنوعی (JSON یا متنِ خط‌به‌خط)
// =====================================================================
// قالبِ JSON که باید به هوش مصنوعی گفته شود تولید کند (قالب رسمیِ همین سیستم):
// {
//   "rows": [
//     { "plate": "31 ع 693 ایران 21", "insurance_type": "بدنه",
//       "expiry_date": "1405/07/30", "car_name": "پژو ۲۰۶",
//       "has_prev_body": "بله", "health_inspection": "لازم", "note": "" }
//   ]
// }
// قالبِ متنیِ جایگزین (هر خط یک ردیف، جداکننده «|»):
//   31 ع 693 ایران 21 | بدنه | 1405/07/30 | پژو 206
// نوع بیمه می‌تواند «بدنه»، «ثالث» یا «هردو» باشد؛ «هردو» به دو ردیفِ جدا تبدیل می‌شود.

// پلاک را از هر نوشتاری که هوش مصنوعی بدهد به چهار بخشِ دیتابیس تبدیل می‌کند.
// خروجی: ['p1' => کد شهر, 'p2' => سه رقم, 'letter' => حرف, 'p4' => دو رقم] یا null
function company_parse_plate_text($text) {
    $t = p2e_digits(trim((string)$text));
    $t = str_replace(['ـ', '–', '—'], '-', $t);
    // حالت ۱: «31 ع 693 ایران 21» یا «31ع693ایران21» (ترتیب خوانا، ایران در انتها)
    if (preg_match('/(\d{2})\s*([^\d\s\-]{1,3})\s*(\d{3})\s*ایران\s*(\d{2})/u', $t, $m)) {
        return ['p4' => $m[1], 'letter' => trim($m[2]), 'p2' => $m[3], 'p1' => $m[4]];
    }
    // حالت ۲: قالبِ ذخیره‌سازیِ خودمان «21ایران - 693 ع 31»
    if (preg_match('/(\d{2})\s*ایران\s*-?\s*(\d{3})\s*([^\d\s\-]{1,3})\s*(\d{2})/u', $t, $m)) {
        return ['p1' => $m[1], 'p2' => $m[2], 'letter' => trim($m[3]), 'p4' => $m[4]];
    }
    // حالت ۳: بدون «ایران»، فقط «31 ع 693 21»
    if (preg_match('/(\d{2})\s*([^\d\s\-]{1,3})\s*(\d{3})\s*[-\s]\s*(\d{2})/u', $t, $m)) {
        return ['p4' => $m[1], 'letter' => trim($m[2]), 'p2' => $m[3], 'p1' => $m[4]];
    }
    return null;
}

// نوع بیمه‌ی نوشته‌شده به enum. «هردو» با مقدار BOTH برگردانده می‌شود تا فراخوان
// بداند باید دو ردیف بسازد.
function company_parse_insurance_type($text) {
    $t = trim((string)$text);
    if ($t === '') return null;
    if (in_array(strtoupper($t), ['BODY', 'THIRDPARTY', 'BOTH'], true)) return strtoupper($t);
    $hasBody = mb_strpos($t, 'بدنه') !== false;
    $hasThird = mb_strpos($t, 'ثالث') !== false;
    // «هردو» / «هر دو» / «both» هم به‌معنی هر دو نوع است، حتی اگر اسم هیچ‌کدام نوشته نشده باشد
    if (($hasBody && $hasThird) || mb_strpos($t, 'هردو') !== false || mb_strpos($t, 'هر دو') !== false) return 'BOTH';
    if ($hasBody) return 'BODY';
    if ($hasThird) return 'THIRDPARTY';
    return null;
}

// «بله/خیر» را از هر نوشتاری تشخیص می‌دهد. نکته‌ی مهم: عبارت‌های منفی *اول* چک
// می‌شوند، وگرنه «لازم نیست» به‌خاطر وجودِ «لازم» اشتباهاً «بله» خوانده می‌شود.
// مبلغ (ریال) را از هر نوشتاری بیرون می‌کشد: «۴۰,۰۰۰,۰۰۰,۰۰۰ ریال» یا «40000000000»
function company_parse_money($text) {
    if (is_int($text) || is_float($text)) return intval($text) ?: null;
    $t = p2e_digits(trim((string)$text));
    $digits = preg_replace('/\D/', '', $t);
    return $digits === '' ? null : intval($digits);
}

function company_parse_yes_no($text) {
    if (is_bool($text)) return $text ? 'YES' : 'NO';
    if (is_int($text)) return $text ? 'YES' : 'NO';
    $t = trim((string)$text);
    if ($t === '') return null;
    if (in_array(strtoupper($t), ['YES', 'NO'], true)) return strtoupper($t);
    if ($t === '1') return 'YES';
    if ($t === '0') return 'NO';
    foreach (['ندارد', 'نیست', 'لازم نیست', 'خیر', 'نه', 'بدون'] as $neg) {
        if (mb_strpos($t, $neg) !== false) return 'NO';
    }
    foreach (['بله', 'دارد', 'لازم', 'اجباری', 'هست'] as $pos) {
        if (mb_strpos($t, $pos) !== false) return 'YES';
    }
    return null;
}

// متنِ ورودی (JSON یا خط‌به‌خط) را به فهرستِ ردیف‌های آماده‌ی درج تبدیل می‌کند.
// خروجی: ['rows' => [...], 'errors' => [...]]
function company_parse_letter_rows($raw) {
    $raw = trim((string)$raw);
    $rows = []; $errors = [];
    if ($raw === '') return ['rows' => [], 'errors' => ['متنی برای تبدیل داده نشد.']];

    $parsed = null;
    if ($raw[0] === '{' || $raw[0] === '[') {
        $json = json_decode($raw, true);
        if (is_array($json)) $parsed = isset($json['rows']) && is_array($json['rows']) ? $json['rows'] : $json;
        else $errors[] = 'فایل JSON خوانده نشد (ساختارش درست نیست)؛ به‌عنوان متنِ خط‌به‌خط بررسی می‌شود.';
    }

    if ($parsed === null) {
        // متنِ خط‌به‌خط: «پلاک | نوع بیمه | تاریخ انقضا | نام خودرو | توضیح»
        $parsed = [];
        foreach (preg_split('/\R/u', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || mb_substr($line, 0, 1) === '#') continue;
            $cols = array_map('trim', preg_split('/\s*[|،,;\t]\s*/u', $line));
            $parsed[] = [
                'plate' => $cols[0] ?? '',
                'insurance_type' => $cols[1] ?? '',
                'expiry_date' => $cols[2] ?? '',
                'car_name' => $cols[3] ?? '',
                'note' => $cols[4] ?? '',
            ];
        }
    }

    foreach ($parsed as $i => $r) {
        if (!is_array($r)) continue;
        $lineNo = $i + 1;

        // ---- شناسه‌ی ردیف: یا پلاک، یا شماره شاسی ----
        // لیفتراک‌ها اصلاً پلاک ندارند و خودروهای صفرکیلومتر هنوز پلاک نگرفته‌اند؛
        // این‌ها فقط با شماره شاسی شناخته می‌شوند، پس نبودِ پلاک خطا نیست.
        $plateText = $r['plate'] ?? trim(($r['plate_p4'] ?? '') . ' ' . ($r['plate_letter'] ?? '') . ' ' . ($r['plate_p2'] ?? '') . ' ایران ' . ($r['plate_p1'] ?? ''));
        $plate = company_parse_plate_text($plateText);
        $chassis = trim((string)($r['chassis_no'] ?? ($r['chassis'] ?? ($r['vin'] ?? ''))));
        // اگر پلاک خوانده نشد ولی متنش شبیه شماره شاسی بود، همان را شاسی حساب کن
        if (!$plate && $chassis === '' && trim((string)$plateText) !== '' && preg_match('/^[A-Za-z0-9\-]{6,}$/', trim((string)$plateText))) {
            $chassis = trim((string)$plateText);
        }
        if (!$plate && $chassis === '') {
            $errors[] = "ردیف {$lineNo}: نه پلاک خوانده شد و نه شماره شاسی («" . mb_substr((string)$plateText, 0, 40) . "»).";
            continue;
        }

        $type = company_parse_insurance_type($r['insurance_type'] ?? ($r['type'] ?? ''));
        if (!$type) { $errors[] = "ردیف {$lineNo}: نوع بیمه (بدنه/ثالث/هردو) مشخص نیست."; continue; }

        // خودروی صفرکیلومتر تاریخ انقضا ندارد، پس نبودش هم خطا نیست
        $expiryRaw = trim((string)($r['expiry_date'] ?? ($r['expiry'] ?? '')));
        $expiry = $expiryRaw === '' ? null : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryRaw) ? $expiryRaw : fin_jalali_to_date($expiryRaw));
        if ($expiryRaw !== '' && !$expiry) $errors[] = "ردیف {$lineNo}: تاریخ انقضای «{$expiryRaw}» خوانده نشد (این ردیف بدون تاریخ ساخته می‌شود).";

        $isNew = company_parse_yes_no($r['is_new_vehicle'] ?? ($r['new'] ?? '')) === 'YES';
        // اگر پلاک ندارد و تاریخ انقضا هم ندارد، عملاً خودروی صفرکیلومتر است
        if (!$plate && !$expiry) $isNew = true;

        $base = [
            'plate_p1' => $plate ? $plate['p1'] : null, 'plate_p2' => $plate ? $plate['p2'] : null,
            'plate_letter' => $plate ? $plate['letter'] : null, 'plate_p4' => $plate ? $plate['p4'] : null,
            'chassis_no' => $chassis ?: null,
            'engine_no' => trim((string)($r['engine_no'] ?? ($r['engine'] ?? ''))) ?: null,
            'is_new_vehicle' => $isNew ? 1 : 0,
            'car_value' => company_parse_money($r['car_value'] ?? ($r['value'] ?? '')),
            'liability_limit' => company_parse_money($r['liability_limit'] ?? ($r['liability'] ?? '')),
            'expiry_date' => $expiry,
            // همه‌ی تاریخ‌های نمایشیِ سایت شمسی‌اند؛ تاریخ میلادی فقط برای ذخیره در دیتابیس است
            'expiry_date_jalali' => $expiry ? jalali_from_gregorian_ts_dotted(strtotime($expiry)) : null,
            'car_name' => trim((string)($r['car_name'] ?? ($r['car'] ?? ''))) ?: null,
            'row_note' => trim((string)($r['note'] ?? '')) ?: null,
            'has_prev_body' => company_parse_yes_no($r['has_prev_body'] ?? ''),
            // «بازدید سلامت لازم نیست» => skip_health_inspection = 1.
            // خودروی صفرکیلومتر هم طبعاً بازدید سلامت نمی‌خواهد.
            'skip_health_inspection' => ($isNew || company_parse_yes_no($r['health_inspection'] ?? '') === 'NO') ? 1 : 0,
        ];
        $base['plate_display'] = company_row_label($base);

        foreach ($type === 'BOTH' ? ['THIRDPARTY', 'BODY'] : [$type] as $t) {
            $rows[] = array_merge($base, ['insurance_type' => $t, 'insurance_type_fa' => insurance_type_fa($t)]);
        }
    }

    return ['rows' => $rows, 'errors' => $errors];
}

// =====================================================================
//  خواندنِ ردیف‌ها از فایل اکسل/CSV
// =====================================================================
// ستون‌ها با نامِ سرستونشان شناخته می‌شوند (نه با جایشان)، پس ترتیبِ ستون‌ها مهم
// نیست و ستون‌های اضافه هم نادیده گرفته می‌شوند. برای هر فیلد چند نامِ رایج پذیرفته
// می‌شود تا کاربر مجبور نباشد فایلش را بازنویسی کند.
function company_excel_column_map() {
    return [
        'plate'          => ['پلاک', 'شماره پلاک', 'شمارهپلاک', 'plate'],
        'chassis_no'     => ['شماره شاسی', 'شمارهشاسی', 'شاسی', 'vin', 'chassis', 'chassis no', 'شماره شاسی/vin'],
        'engine_no'      => ['شماره موتور', 'شمارهموتور', 'موتور', 'engine', 'engine no'],
        'car_value'      => ['ارزش خودرو', 'ارزش', 'ارزش روز', 'قیمت خودرو', 'car value', 'value'],
        'liability_limit'=> ['تعهد مالی', 'سقف تعهد', 'سقف تعهد مالی', 'تعهد', 'liability'],
        'insurance_type' => ['نوع بیمه', 'نوع بیمه نامه', 'نوع بیمه‌نامه', 'نوع بیمهنامه', 'نوع', 'type'],
        'expiry_date'    => ['تاریخ انقضا', 'انقضا', 'انقضاء', 'تاریخ انقضاء', 'سررسید', 'expiry'],
        'car_name'       => ['خودرو', 'نام خودرو', 'سیستم', 'تیپ', 'مدل', 'car'],
        'has_prev_body'  => ['بیمه بدنه قبل', 'بدنه قبل', 'بیمه قبلی'],
        'health_inspection' => ['بازدید سلامت', 'بازدید'],
        'is_new_vehicle' => ['صفر کیلومتر', 'صفرکیلومتر', 'خودرو صفر', 'صفر'],
        'note'           => ['توضیح', 'توضیحات', 'ملاحظات', 'note'],
    ];
}

// نرمال‌سازیِ نامِ سرستون برای مقایسه: ارقام انگلیسی، حذف فاصله‌ها و نویسه‌های
// نامرئی، و یکدست‌کردنِ «ی» و «ک» عربی/فارسی
function company_normalize_header($h) {
    $h = mb_strtolower(trim((string)$h));
    $h = str_replace(['ي', 'ك', 'ـ', "\xE2\x80\x8C", "\xE2\x80\x8E", "\xE2\x80\x8F"], ['ی', 'ک', '', '', '', ''], $h);
    return preg_replace('/\s+/u', '', $h);
}

// از یک فایل اکسل/CSV، فهرستِ ردیف‌های آماده‌ی company_parse_letter_rows را می‌سازد.
// خروجی: ['rows' => [...], 'errors' => [...]] یا ['error' => '...'] در صورت شکست.
function company_rows_from_spreadsheet($path) {
    if (!function_exists('fin_read_spreadsheet')) {
        return ['error' => 'ماژول خواندن اکسل در دسترس نیست.'];
    }
    $table = fin_read_spreadsheet($path);
    if (!$table) return ['error' => 'فایل خوانده نشد. اگر xlsx است، یک‌بار با فرمت CSV ذخیره کنید و دوباره بدهید.'];

    $map = company_excel_column_map();
    // نرمال‌شده‌ی همه‌ی نام‌های مجاز، برای جستجوی سریع
    $lookup = [];
    foreach ($map as $field => $names) {
        foreach ($names as $n) $lookup[company_normalize_header($n)] = $field;
    }

    // ردیفِ سرستون را پیدا کن: اولین ردیفی که حداقل دو ستونِ شناخته‌شده دارد
    $headerIdx = -1; $cols = [];
    foreach ($table as $idx => $row) {
        if (!is_array($row)) continue;
        $found = [];
        foreach ($row as $c => $cell) {
            $key = company_normalize_header($cell);
            if ($key !== '' && isset($lookup[$key])) $found[$c] = $lookup[$key];
        }
        if (count($found) >= 2) { $headerIdx = $idx; $cols = $found; break; }
        if ($idx > 20) break; // سرستون معمولاً در ۲۰ ردیف اول است
    }
    if ($headerIdx < 0) {
        return ['error' => 'سرستون‌ها شناسایی نشدند. حداقل دو ستون از این‌ها لازم است: پلاک یا شماره شاسی، و نوع بیمه.'];
    }

    $out = [];
    foreach (array_slice($table, $headerIdx + 1) as $row) {
        if (!is_array($row)) continue;
        $rec = [];
        foreach ($cols as $c => $field) {
            $val = isset($row[$c]) ? trim((string)$row[$c]) : '';
            if ($val !== '') $rec[$field] = $val;
        }
        // ردیفِ کاملاً خالی را رد کن
        if (!$rec) continue;
        if (empty($rec['plate']) && empty($rec['chassis_no'])) continue;
        $out[] = $rec;
    }
    if (!$out) return ['error' => 'زیر سرستون‌ها هیچ ردیفِ پُری پیدا نشد.'];
    return ['rows' => $out];
}

// ورودیِ «ورود ردیف‌ها» می‌تواند متنِ چسبانده‌شده باشد یا فایل (JSON/متن/اکسل/CSV).
// این تابع هر سه را یکدست می‌کند و همیشه خروجیِ company_parse_letter_rows می‌دهد.
function company_read_import_input($data, $file) {
    $raw = (string)($data['raw'] ?? '');
    $source = 'text';

    if (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
        if (($file['size'] ?? 0) > 5242880) return ['error' => 'حجم فایل بیشتر از ۵ مگابایت است.'];
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            // اکسل/CSV باید با پسوندِ درست روی دیسک باشد تا خواننده بتواند تشخیص دهد
            $tmp = sys_get_temp_dir() . '/' . uniqid('cimport_') . '.' . $ext;
            if (!@copy($file['tmp_name'], $tmp)) return ['error' => 'فایل روی سرور ذخیره نشد.'];
            $res = company_rows_from_spreadsheet($tmp);
            @unlink($tmp);
            if (isset($res['error'])) return $res;
            $parsed = company_parse_letter_rows(json_encode(['rows' => $res['rows']], JSON_UNESCAPED_UNICODE));
            $parsed['raw'] = json_encode(['rows' => $res['rows']], JSON_UNESCAPED_UNICODE);
            $parsed['source'] = 'excel';
            return $parsed;
        }
        $raw = (string)file_get_contents($file['tmp_name']);
        $source = 'file';
    }

    $parsed = company_parse_letter_rows($raw);
    $parsed['raw'] = $raw;
    $parsed['source'] = $source;
    return $parsed;
}

// نامه‌ی درخواست باید داخلِ پوشه‌ی هر نوعِ بیمه‌ای که این درخواست دارد بنشیند
// (بدنه و/یا ثالث)، طبق ساختار خواسته‌شده. اگر هنوز هیچ ردیفی ثبت نشده، فعلاً در
// پوشه‌ی تاریخ می‌ماند و بارِ بعد که ردیف اضافه شد سرِ جایش می‌رود.
// خروجی: مسیرِ نسبیِ اولین نسخه (همان که در دیتابیس ذخیره می‌شود).
function company_place_letter($pdo, $siteRoot, $requestId, $absSource, $ext) {
    $stmt = $pdo->prepare("SELECT cr.created_at, c.name AS company_name FROM company_requests cr
                            JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req || !is_file($absSource)) return null;

    $ts = strtotime($req['created_at']);
    $dateDir = company_request_date_folder($siteRoot, $ts, $req['company_name']);

    $stmt = $pdo->prepare("SELECT DISTINCT insurance_type FROM company_request_plates WHERE request_id = ? AND insurance_type IS NOT NULL");
    $stmt->execute([$requestId]);
    $types = array_column($stmt->fetchAll(), 'insurance_type');

    $typesFa = company_request_types_fa($pdo, $requestId);
    $fileName = company_letter_filename($req['company_name'], $ts, $typesFa, $ext);

    // مقصدها: پوشه‌ی هر نوعِ بیمه؛ و اگر هنوز ردیفی نیست، خودِ پوشه‌ی تاریخ
    $targets = [];
    foreach ($types as $t) $targets[] = $dateDir . '/' . insurance_type_fa($t);
    if (!$targets) $targets[] = $dateDir;

    $firstRel = null;
    foreach ($targets as $i => $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $dest = $dir . '/' . $fileName;
        // نسخه‌ی قبلیِ همین نامه (اگر بود) بازنویسی می‌شود تا کپی تکراری جمع نشود
        if ($i === count($targets) - 1) { if (!@rename($absSource, $dest)) @copy($absSource, $dest); }
        else { @copy($absSource, $dest); }
        if ($firstRel === null) $firstRel = ltrim(str_replace($siteRoot, '', $dest), '/');
    }
    return $firstRel;
}

// =====================================================================
//  کنترل دسترسی
// =====================================================================

// سشن پنل ثبت‌کننده‌ی شرکت (وب) - کاملاً جدا از سشن پنل ادمین (نام کوکی متفاوت)
function company_portal_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('bime_company_portal');
        session_start();
    }
}

// $pdo باید از قبل به دیتابیس وصل باشد؛ فهرست شرکت‌های مجاز از جدول واسط خوانده
// می‌شود (نه از ستون تکی قدیمی) چون یک کاربر می‌تواند به چند شرکت دسترسی داشته باشد.
function require_company_portal_session($pdo) {
    company_portal_session_start();
    if (empty($_SESSION['company_user_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز. لطفاً دوباره وارد شوید.']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.allowed_insurers FROM company_portal_user_companies cpuc
                            JOIN companies c ON c.id = cpuc.company_id
                            WHERE cpuc.portal_user_id = ? ORDER BY c.name");
    $stmt->execute([$_SESSION['company_user_id']]);
    $companies = $stmt->fetchAll();
    return [
        'company_user_id' => $_SESSION['company_user_id'],
        'companies' => $companies,
        'company_ids' => array_column($companies, 'id'),
        'full_name' => $_SESSION['company_user_full_name'] ?? '',
    ];
}

// برای اکشن‌های پنل ادمین که مخصوص ماژول شرکت‌هاست: هم ADMIN و هم COMPANY_LIAISON مجازند
function require_admin_or_liaison() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'COMPANY_LIAISON'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
        exit;
    }
    return ['user_id' => $_SESSION['user_id'], 'role' => $_SESSION['role']];
}

// اکشن‌های حساس (مثل ثبت صدور نهایی) فقط برای ADMIN
function require_admin_only() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'این عملیات فقط برای مدیر کل مجاز است.']);
        exit;
    }
    return ['user_id' => $_SESSION['user_id']];
}

// اعتبارسنجی/ذخیره‌ی فایل آپلودی برای ماژول شرکت‌ها (PDF یا عکس، یا زیپ اگر
// $allowZip=true - برای گزارش‌های بازدید سلامت که معمولاً زیپ چند فایلی هستند؛
// عمداً استخراج نمی‌شود، خودِ زیپ همان‌طور داخل پوشه‌ی بازدید سلامت نگه داشته می‌شود)
// اگر $extractZip=true و فایل زیپ بود، به‌جای ذخیره‌ی خودِ زیپ، محتوایش مستقیم
// داخل $destDir استخراج می‌شود (برای گزارش‌های بازدید سلامت که معمولاً چند فایلی‌اند)
// و path برگشتی خودِ $destDir خواهد بود (is_dir=true)؛ در غیر این صورت مثل قبل یک فایل تکی.
function company_store_uploaded_file($fileArr, $destDir, $maxBytes = 15728640, $allowZip = false, $extractZip = false) {
    if (empty($fileArr) || !is_uploaded_file($fileArr['tmp_name'] ?? '')) {
        return ['ok' => false, 'error' => 'فایلی دریافت نشد.'];
    }
    if (($fileArr['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'حجم فایل بیشتر از حد مجاز (۱۵ مگابایت) است.'];
    }
    $ext = strtolower(pathinfo($fileArr['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if ($allowZip) $allowedExts[] = 'zip';
    if (!in_array($ext, $allowedExts, true)) {
        return ['ok' => false, 'error' => $allowZip ? 'فقط فایل PDF، عکس یا زیپ مجاز است.' : 'فقط فایل PDF یا عکس مجاز است.'];
    }
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return ['ok' => false, 'error' => 'خطا در آماده‌سازی پوشه‌ی ذخیره‌سازی.'];
    }

    if ($extractZip && $ext === 'zip') {
        $tmpZipPath = sys_get_temp_dir() . '/' . uniqid('company_zip_') . '.zip';
        if (!move_uploaded_file($fileArr['tmp_name'], $tmpZipPath)) {
            return ['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل روی سرور.'];
        }
        $extracted = company_extract_zip_to_dir($tmpZipPath, $destDir);
        @unlink($tmpZipPath);
        if ($extracted === false) {
            return ['ok' => false, 'error' => 'فایل زیپ خراب است یا قابل استخراج نیست.'];
        }
        return ['ok' => true, 'path' => $destDir, 'orig_name' => $fileArr['name'], 'is_dir' => true, 'extracted_count' => $extracted];
    }

    $destPath = unique_dest_path($destDir . '/' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext);
    if (!move_uploaded_file($fileArr['tmp_name'], $destPath)) {
        return ['ok' => false, 'error' => 'خطا در ذخیره‌ی فایل روی سرور.'];
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && function_exists('compress_image_if_needed')) {
        compress_image_if_needed($destPath);
    }
    return ['ok' => true, 'path' => $destPath, 'orig_name' => $fileArr['name'], 'is_dir' => false];
}

// استخراج امنِ یک زیپ داخل یک پوشه‌ی مقصد: هر ورودی که بخواهد از مقصد بیرون بزند
// (مثلاً با «..» یا مسیر مطلق) نادیده گرفته می‌شود تا zip-slip ممکن نباشد.
// خروجی: تعداد فایل‌های استخراج‌شده، یا false در صورت خرابی زیپ.
function company_extract_zip_to_dir($zipPath, $destDir) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return false;
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $destReal = realpath($destDir) ?: $destDir;
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if ($entryName === false) continue;
        // نام‌های حاوی «..» یا مسیر مطلق را رد کن (zip-slip)
        if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || preg_match('/^[a-zA-Z]:/', $entryName)) continue;
        $targetPath = $destReal . '/' . ltrim($entryName, '/');
        if (substr($entryName, -1) === '/') { @mkdir($targetPath, 0755, true); continue; }
        if (!is_dir(dirname($targetPath))) @mkdir(dirname($targetPath), 0755, true);
        $stream = $zip->getStream($entryName);
        if ($stream === false) continue;
        $out = fopen(unique_dest_path($targetPath), 'wb');
        if ($out) { stream_copy_to_stream($stream, $out); fclose($out); $count++; }
        fclose($stream);
    }
    $zip->close();
    return $count;
}

// زیپ‌کردنِ محتوای یک پوشه (بازگشتی) برای دانلود آنی - نتیجه فایل موقتی است که
// پس از ارسال باید حذف شود (مثل build_zip_from_files از _case_helpers.php)
function company_zip_folder($folderPath) {
    if (!is_dir($folderPath)) return null;
    $zipPath = sys_get_temp_dir() . '/' . uniqid('folderzip_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) return null;
    $base = realpath($folderPath);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $localName = ltrim(str_replace($base, '', $file->getPathname()), '/');
        if ($file->isDir()) $zip->addEmptyDir($localName);
        else $zip->addFile($file->getPathname(), $localName);
    }
    $zip->close();
    return $zipPath;
}

// =====================================================================
//  اقساط شرکتی (کاملاً جدا از موتور مالی پرسنلی؛ فقط از fin_split_installments
//  که یک تابع محضِ ریاضی است و به هیچ جدولی وابسته نیست، استفاده مجدد می‌شود)
// =====================================================================
function company_add_months_jalali($jy, $jm, $step) {
    $total = ($jy * 12) + ($jm - 1) + $step;
    return [intdiv($total, 12), ($total % 12) + 1];
}

function company_jalali_month_len($jy, $jm) {
    if ($jm <= 6) return 31;
    if ($jm <= 11) return 30;
    $r = (($jy - ($jy > 0 ? 474 : 473)) % 2820) + 474;
    return ((($r + 38) * 682) % 2816 < 682) ? 30 : 29;
}

// اولین سررسید = تاریخ صدور + first_due_offset_months ماه + first_due_offset_days روز؛
// اقساط بعدی هرکدام دقیقاً یک ماه بعد از قبلی (با تنظیم روز برای ماه‌های کوتاه‌تر).
// نیازمند fin_split_installments از api/finance_core.php (باید include شده باشد).
function company_generate_installments($pdo, $plateId) {
    $stmt = $pdo->prepare("SELECT crp.*, cr.company_id FROM company_request_plates crp
                            JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch();
    if (!$plate || $plate['status'] !== 'ISSUED' || empty($plate['total_premium'])) {
        return ['ok' => false, 'error' => 'پلاک صادر نشده یا حق بیمه ثبت نشده است.'];
    }

    $stmt = $pdo->prepare("SELECT installment_count, first_due_offset_months, first_due_offset_days FROM companies WHERE id = ?");
    $stmt->execute([$plate['company_id']]);
    $comp = $stmt->fetch();
    $count = intval($comp['installment_count'] ?? 0) ?: 1;

    $issuedTs = $plate['issued_at'] ? strtotime($plate['issued_at']) : time();
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($issuedTs);
    [$fy, $fm] = company_add_months_jalali($jy, $jm, intval($comp['first_due_offset_months'] ?? 0));
    $fd = min($jd, company_jalali_month_len($fy, $fm));
    $firstTs = jalali_to_gregorian_ts($fy, $fm, $fd) + (intval($comp['first_due_offset_days'] ?? 0) * 86400);
    [$fy, $fm, $fd] = jalali_from_gregorian_ts($firstTs);

    // «فرمول ماموت»: همان روش رُندکردنِ اقساط که برای پرسنل استفاده می‌شود
    $parts = fin_split_installments($plate['total_premium'], $count, 'mamut');

    $pdo->prepare("DELETE FROM company_installments WHERE plate_id = ?")->execute([$plateId]);
    $ins = $pdo->prepare("INSERT INTO company_installments (plate_id, inst_number, amount, due_jalali, due_date) VALUES (?, ?, ?, ?, ?)");
    $cy = $fy; $cm = $fm;
    foreach ($parts as $i => $amount) {
        if ($i > 0) [$cy, $cm] = company_add_months_jalali($cy, $cm, 1);
        $cd = min($fd, company_jalali_month_len($cy, $cm));
        $dueJ = sprintf('%04d/%02d/%02d', $cy, $cm, $cd);
        $dueG = date('Y-m-d', jalali_to_gregorian_ts($cy, $cm, $cd));
        $ins->execute([$plateId, $i + 1, $amount, $dueJ, $dueG]);
    }
    return ['ok' => true, 'count' => count($parts)];
}
