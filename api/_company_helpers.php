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

// =====================================================================
//  نوعِ درخواست: صدور جدید / الحاقیه / فسخ
// =====================================================================
function company_request_kinds() {
    return [
        'NEW_POLICY'   => 'صدور بیمه‌نامه‌ی جدید',
        'ENDORSEMENT'  => 'صدور الحاقیه',
        'CANCELLATION' => 'فسخ بیمه‌نامه',
    ];
}

function company_request_kind_fa($kind) {
    return company_request_kinds()[$kind] ?? company_request_kinds()['NEW_POLICY'];
}

function company_valid_kind($kind) {
    return array_key_exists((string)$kind, company_request_kinds()) ? $kind : 'NEW_POLICY';
}

// دلیل‌های فسخ که ثبت‌کننده از میانشان انتخاب می‌کند
function company_cancellation_reasons() {
    return [
        'فروش خودرو / انتقال مالکیت',
        'تعویض یا فک پلاک',
        'اسقاط خودرو',
        'سرقت خودرو',
        'صدور بیمه‌نامه‌ی جدید به‌جای این بیمه‌نامه',
        'درخواست بیمه‌گذار',
        'سایر (در توضیح بنویسید)',
    ];
}

// برچسبی که در نامِ پوشه‌ها و نامِ نامه استفاده می‌شود:
//   صدور جدید => «بدنه» یا «ثالث»   |   الحاقیه => «الحاقیه بدنه»   |   فسخ => «فسخ ثالث»
function company_kind_folder_label($kind, $insuranceTypeEnum = null) {
    $typeFa = $insuranceTypeEnum ? insurance_type_fa($insuranceTypeEnum) : '';
    if ($kind === 'ENDORSEMENT')  return trim('الحاقیه ' . $typeFa);
    if ($kind === 'CANCELLATION') return trim('فسخ ' . $typeFa);
    return $typeFa ?: 'ثالث';
}

// الحاقیه و فسخ هرگز به «بایگانی صادره» کپی نمی‌شوند؛ فقط داخل بایگانی شرکتی
// می‌مانند (خواسته‌ی صریح کارفرما). بایگانی صادره مخصوصِ بیمه‌نامه‌های صادرشده است.
function company_kind_goes_to_sadere($kind) {
    return $kind === 'NEW_POLICY';
}

// شناسه‌ی نمایشیِ یک ردیف: اگر پلاک دارد پلاک، وگرنه شماره شاسی (لیفتراک‌ها و
// خودروهای صفرکیلومتر پلاک ندارند و فقط با شماره شاسی شناخته می‌شوند). همه‌جا -
// جدول‌ها، نام پوشه، نام فایل و فهرست متنی - از همین استفاده می‌شود.
// «1405/07» (یا ۱۴۰۵/۰۷) => ['2026-09-23', '2026-10-22'] بازه‌ی میلادیِ همان ماهِ شمسی؛ نامعتبر => null
function company_month_range($m) {
    $m = p2e_digits(trim((string)$m));
    if (!preg_match('/^(1[34]\d{2})\D+(\d{1,2})$/', $m, $mm)) return null;
    $jy = intval($mm[1]); $jm = intval($mm[2]);
    if ($jm < 1 || $jm > 12) return null;
    $start = jalali_to_gregorian_ts($jy, $jm, 1);
    $next = $jm === 12 ? jalali_to_gregorian_ts($jy + 1, 1, 1) : jalali_to_gregorian_ts($jy, $jm + 1, 1);
    return [date('Y-m-d', $start), date('Y-m-d', $next - 86400)];
}

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
function company_request_base_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum = null, $kind = 'NEW_POLICY') {
    return company_request_date_folder($siteRoot, $requestCreatedTimestamp, $companyName)
        . '/' . sanitize_folder_name(company_kind_folder_label($kind, $insuranceTypeEnum));
}

// نام پوشه‌ی هر ردیف، پیش از صدور: «{روزِ ماهِ تاریخ انقضا}(بدنه|ثالث) پلاک»
// روزِ انقضا داخلِ آکولاد نوشته می‌شود: «{30}(بدنه) 21ایران - 515 ع 44».
// خودروهای صفرکیلومتر تاریخ انقضا ندارند، پس آکولاد هم ندارند و با شماره شاسی
// نوشته می‌شوند: «(بدنه) SGG10197IRF553».
function company_plate_folder_name($expiryDate, $insuranceTypeEnum, $plateDisplay, $kind = 'NEW_POLICY') {
    $dayPart = '';
    if ($expiryDate) {
        $ts = is_numeric($expiryDate) ? $expiryDate : strtotime($expiryDate);
        if ($ts) { [, , $jd] = jalali_from_gregorian_ts($ts); $dayPart = '{' . sprintf('%02d', intval($jd)) . '}'; }
    }
    $prefix = $dayPart . '(' . company_kind_folder_label($kind, $insuranceTypeEnum) . ')';
    return sanitize_folder_name($prefix . ' ' . ($plateDisplay ?: 'بدون‌پلاک'));
}

// مسیر کامل پوشه‌ی یک پلاک پیش از صدور (بدون نیاز به دانستن insurance_type دوباره،
// چون از خودِ رکورد پلاک گرفته می‌شود - نگاه کنید به فراخوانی‌ها در company_actions.php)
function build_company_plate_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum, $expiryDate, $plateDisplay, $kind = 'NEW_POLICY') {
    return company_request_base_folder($siteRoot, $requestCreatedTimestamp, $companyName, $insuranceTypeEnum, $kind)
        . '/' . company_plate_folder_name($expiryDate, $insuranceTypeEnum, $plateDisplay, $kind);
}

// پوشه‌ی نهایی پس از صدور: «پلاک - نام شرکت - شماره بیمه‌نامه - VIN»،
// دقیقاً هم‌الگو با build_case_folder_name_issued پرسنلی ولی با نام شرکت به‌جای بیمه‌گذار
function build_company_issued_folder_name($plateDisplay, $companyName, $policyNum, $vin) {
    return sanitize_folder_name(name_join([plate_for_filename($plateDisplay) ?: 'بدون‌پلاک', $companyName, policy_number_for_filename($policyNum), $vin]));
}

// نامِ نهاییِ پوشه‌ی یک ردیف پس از صدور. برای الحاقیه و فسخ، برچسبِ نوع هم جلویش
// می‌آید تا در پوشه با یک نگاه معلوم باشد چه چیزی صادر شده:
//   «(الحاقیه بدنه) 21ایران 989 ع 41 - دیلی مارکت - الحاقیه-۱۲۳»
function company_issued_folder_name_for_kind($kind, $insuranceTypeEnum, $plateDisplay, $companyName, $policyNum, $vin) {
    $name = build_company_issued_folder_name($plateDisplay, $companyName, $policyNum, $vin);
    if ($kind === 'NEW_POLICY') return $name;
    return sanitize_folder_name('(' . company_kind_folder_label($kind, $insuranceTypeEnum) . ')') . ' ' . $name;
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
        // مخصوصِ الحاقیه و فسخ: خودِ بیمه‌نامه‌ی فعلی، و کارت/سندِ تازه‌ی خودرو
        'policy_doc'        => 'بیمه‌نامه',
        'new_car_card'      => 'کارت ماشین جدید',
        'new_ownership_doc' => 'سند جدید',
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
function company_plate_checklist($insuranceTypeEnum, $skipHealthInspection, array $assignedDocTypes, $hasPrevBody = null, $kind = 'NEW_POLICY') {
    $has = fn($k) => in_array($k, $assignedDocTypes, true);
    $items = [];

    // ---- الحاقیه: خودِ بیمه‌نامه‌ی فعلی + کارت یا سندِ جدیدِ خودرو ----
    if ($kind === 'ENDORSEMENT') {
        return [
            [
                'key' => 'policy_doc', 'label' => 'بیمه‌نامه',
                'required' => true, 'satisfied' => $has('policy_doc'),
                'upload_types' => ['policy_doc'],
                'hint' => 'همان بیمه‌نامه‌ای که می‌خواهید برایش الحاقیه صادر شود',
            ],
            [
                'key' => 'new_car_card_or_title', 'label' => 'کارت ماشین یا سند جدید',
                'required' => true,
                'satisfied' => $has('new_car_card') || $has('new_ownership_doc'),
                'upload_types' => ['new_car_card', 'new_ownership_doc'],
                'hint' => 'مدرکِ تازه‌ای که تغییر را نشان می‌دهد',
            ],
        ];
    }

    // ---- فسخ: خودِ بیمه‌نامه اجباری، مدرکِ اثباتِ دلیل اختیاری ----
    if ($kind === 'CANCELLATION') {
        return [
            [
                'key' => 'policy_doc', 'label' => 'بیمه‌نامه',
                'required' => true, 'satisfied' => $has('policy_doc'),
                'upload_types' => ['policy_doc'],
                'hint' => 'بیمه‌نامه‌ای که می‌خواهید فسخ شود',
            ],
            [
                'key' => 'cancellation_proof', 'label' => 'مدرک مربوط به دلیل فسخ',
                'required' => false,
                'satisfied' => $has('new_ownership_doc') || $has('new_car_card') || $has('other'),
                'upload_types' => ['new_ownership_doc', 'new_car_card', 'other'],
                'hint' => 'مثلاً سند فروش یا مدرک فک پلاک - اگر دارید',
            ],
        ];
    }

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
        // «نیاز به بازدید ندارد»: نه عکسِ بازدید خواسته می‌شود نه گزارش (مگر از قبل بارگذاری شده باشد)
        if (!$healthNeeded && !$has('health_inspection') && !$has('health_report')) return $items;
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
function company_plate_missing_docs($insuranceTypeEnum, $skipHealthInspection, array $assignedDocTypes, $hasPrevBody = null, $kind = 'NEW_POLICY') {
    $missing = [];
    foreach (company_plate_checklist($insuranceTypeEnum, $skipHealthInspection, $assignedDocTypes, $hasPrevBody, $kind) as $it) {
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
function company_plate_status_fa($status, $kind = 'NEW_POLICY') {
    $map = [
        'PENDING'         => 'در انتظار مدارک',
        'READY_FOR_ISSUE' => 'مدارک کامل - آماده‌ی صدور',
        'WITH_BOSS'       => 'ارسال‌شده برای رئیس',
        'IN_ISSUANCE'     => 'در حال صدور',
        'ISSUED'          => 'صادر شد',
        'CANCELLED'       => 'لغو شد',
    ];
    // «صادر شد» برای الحاقیه و فسخ معنیِ دیگری دارد
    if ($kind === 'ENDORSEMENT')  { $map['READY_FOR_ISSUE'] = 'مدارک کامل - آماده‌ی الحاقیه'; $map['IN_ISSUANCE'] = 'در حال صدور الحاقیه'; $map['ISSUED'] = 'الحاقیه صادر شد'; }
    if ($kind === 'CANCELLATION') { $map['READY_FOR_ISSUE'] = 'مدارک کامل - آماده‌ی فسخ';   $map['IN_ISSUANCE'] = 'در حال فسخ';        $map['ISSUED'] = 'فسخ انجام شد'; }
    return $map[$status] ?? $status;
}

// وضعیت هر ردیف بر اساس کامل‌بودن مدارک به‌طور خودکار بین «در انتظار مدارک» و
// «آماده‌ی صدور» جابه‌جا می‌شود؛ مرحله‌های دستی (ارسال به رئیس / در حال صدور /
// صادر شد / لغو) دست‌نخورده می‌مانند.
function company_sync_plate_status($pdo, $plateId) {
    $stmt = $pdo->prepare("SELECT crp.insurance_type, crp.skip_health_inspection, crp.has_prev_body, crp.status,
                                  cr.request_kind
                             FROM company_request_plates crp
                             JOIN company_requests cr ON cr.id = crp.request_id
                            WHERE crp.id = ?");
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch();
    if (!$plate) return null;
    if (!in_array($plate['status'], ['PENDING', 'READY_FOR_ISSUE'], true)) return $plate['status'];

    $stmt = $pdo->prepare("SELECT doc_type FROM company_documents WHERE plate_id = ? AND status = 'ASSIGNED'");
    $stmt->execute([$plateId]);
    $types = array_filter(array_column($stmt->fetchAll(), 'doc_type'));
    $missing = company_plate_missing_docs($plate['insurance_type'], (bool)$plate['skip_health_inspection'], $types,
                                          $plate['has_prev_body'], $plate['request_kind'] ?? 'NEW_POLICY');
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
    $stmt = $pdo->prepare("SELECT MAX(cr.request_kind) AS request_kind, GROUP_CONCAT(DISTINCT crp.insurance_type) AS types
                             FROM company_requests cr
                             LEFT JOIN company_request_plates crp ON crp.request_id = cr.id
                            WHERE cr.id = ? GROUP BY cr.id");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    if (!$row) return '';
    $kind = $row['request_kind'] ?? 'NEW_POLICY';
    $types = array_filter(explode(',', (string)$row['types']));
    if (!$types) $types = company_types_from_requested_counts($pdo, $requestId);
    if (!$types) return $kind === 'NEW_POLICY' ? '' : company_kind_folder_label($kind);
    $fa = array_map(fn($t) => company_kind_folder_label($kind, $t), $types);
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
    $kind = $req['request_kind'] ?? 'NEW_POLICY';

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
    $lines[] = 'شرکت ' . $req['company_name'] . ' - نامه‌ی ' . jalali_from_gregorian_ts_dotted(strtotime($req['created_at']))
             . ' - ' . company_request_kind_fa($kind);
    $lines[] = '';
    foreach ($plates as $p) {
        $types = array_values(array_filter($byPlate[$p['id']] ?? []));
        $display = company_row_label($p);
        $present = company_plate_present_labels($types);
        $line = $display . ' (' . company_kind_folder_label($kind, $p['insurance_type']) . ')';
        if (!empty($p['ref_policy_number'])) $line .= ' [بیمه‌نامه ' . $p['ref_policy_number'] . ']';
        $line .= $present ? ' ( ' . implode(' ، ', $present) . ' )' : ' ( بدون مدرک )';
        $lines[] = $line;
        if ($kind === 'ENDORSEMENT' && !empty($p['endorsement_request'])) $lines[] = '    خواسته: ' . $p['endorsement_request'];
        if ($kind === 'CANCELLATION' && !empty($p['cancellation_reason'])) $lines[] = '    دلیل فسخ: ' . $p['cancellation_reason'];
        $missing = company_plate_missing_docs($p['insurance_type'], (bool)$p['skip_health_inspection'], $types, $p['has_prev_body'] ?? null, $kind);
        if ($missing) $lines[] = '    ناموجود: ' . implode('، ', array_values($missing));
    }

    $total = count($plates);
    $body = 0; $third = 0; $issued = 0;
    foreach ($plates as $p) {
        if ($p['insurance_type'] === 'BODY') $body++; else $third++;
        if ($p['status'] === 'ISSUED') $issued++;
    }
    $lines[] = '';
    $doneFa = $kind === 'CANCELLATION' ? 'فسخ‌شده' : ($kind === 'ENDORSEMENT' ? 'الحاقیه‌ی صادرشده' : 'صادرشده');
    $lines[] = "جمع: {$total} ردیف ({$body} بدنه، {$third} ثالث) - {$doneFa}: {$issued}";
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
    $stmt = $pdo->prepare("SELECT crp.*, cr.company_id, cr.created_at AS request_created_at, cr.request_kind, c.name AS company_name
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
    $desired = build_company_plate_folder($siteRoot, strtotime($plate['request_created_at']), $plate['company_name'],
                                          $plate['insurance_type'], $plate['expiry_date'], $plateDisplay,
                                          $plate['request_kind'] ?? 'NEW_POLICY');

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
    // حالت ۲ب: نوشتارِ رایجِ نامه‌ها «۷۹۸ع۸۱-۱۱» (سه رقم، حرف، دو رقم، خط تیره، کد ایران)
    if (preg_match('/(\d{3})\s*([^\d\s\-]{1,3})\s*(\d{2})\s*-\s*(?:ایران\s*)?(\d{2})(?!\d)/u', $t, $m)) {
        return ['p2' => $m[1], 'letter' => trim($m[2]), 'p4' => $m[3], 'p1' => $m[4]];
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
    return money_to_int($text) ?: null;
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
    $givenCounts = null;
    if ($raw[0] === '{' || $raw[0] === '[') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $parsed = isset($json['rows']) && is_array($json['rows']) ? $json['rows'] : $json;
            // نوعِ نامه و تعدادِ درخواستی از هر نوع: {"letter": {"counts": {"ثالث": 3, "بدنه": 2, "تغییر اطلاعات": 1, "فسخ": 0}}}
            $cIn = $json['letter']['counts'] ?? ($json['counts'] ?? null);
            if (is_array($cIn)) $givenCounts = company_counts_from_labels($cIn);
        }
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
        // پلاکِ چهاربخشی (دقیق‌ترین حالت؛ هوش مصنوعی دیگر دو رقم و کد ایران را جابه‌جا نمی‌کند):
        //   plate_two (دو رقمِ اول)، plate_letter (حرف)، plate_three (سه رقم)، plate_iran (کد ایران)
        //   یا به شکلِ "plate": {"two": "81", "letter": "ع", "three": "798", "iran": "11"}
        $pObj = is_array($r['plate'] ?? null) ? $r['plate'] : [];
        $pTwo = p2e_digits(trim((string)($r['plate_two'] ?? ($pObj['two'] ?? ($r['plate_p4'] ?? '')))));
        $pLet = trim((string)($r['plate_letter'] ?? ($pObj['letter'] ?? '')));
        $pThree = p2e_digits(trim((string)($r['plate_three'] ?? ($pObj['three'] ?? ($r['plate_p2'] ?? '')))));
        $pIran = p2e_digits(trim((string)($r['plate_iran'] ?? ($pObj['iran'] ?? ($r['plate_p1'] ?? '')))));
        $plate = null;
        if (preg_match('/^\d{2}$/', $pTwo) && preg_match('/^\d{3}$/', $pThree) && preg_match('/^\d{2}$/', $pIran) && $pLet !== '') {
            $plate = ['p4' => $pTwo, 'letter' => $pLet === 'الف' ? 'الف' : mb_substr($pLet, 0, 3), 'p2' => $pThree, 'p1' => $pIran];
            $plateText = "$pTwo $pLet $pThree ایران $pIran";
        } else {
            $plateText = is_string($r['plate'] ?? null) ? $r['plate'] : trim("$pTwo $pLet $pThree ایران $pIran");
            $plate = company_parse_plate_text($plateText);
            if (($pTwo !== '' || $pThree !== '' || $pIran !== '') && !$plate) $errors[] = "ردیف {$lineNo}: بخش‌های پلاک کامل نیست (دو رقم، حرف، سه رقم، کد ایران).";
        }
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

        // نوعِ درخواستِ این ردیف: صدورِ جدید، الحاقیه‌ی تغییر اطلاعات، یا فسخ
        $reqKind = company_parse_row_request($r['request'] ?? ($r['request_type'] ?? ($r['نوع درخواست'] ?? '')));
        $endorseText = trim((string)($r['endorsement_request'] ?? ($r['change_request'] ?? '')));
        $cancelReason = trim((string)($r['cancellation_reason'] ?? ''));
        if ($reqKind === 'CANCELLATION' && $cancelReason === '') $cancelReason = trim((string)($r['note'] ?? '')) ?: 'سایر';
        if ($reqKind === 'ENDORSEMENT' && $endorseText === '') $endorseText = trim((string)($r['note'] ?? '')) ?: 'تغییر اطلاعات بیمه‌نامه';

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
            'model_year' => p2e_digits(trim((string)($r['model_year'] ?? ($r['model'] ?? '')))) ?: null,
            'prev_policy_number' => p2e_digits(trim((string)($r['prev_policy_number'] ?? ''))) ?: null,
            'row_note' => trim((string)($r['note'] ?? '')) ?: null,
            // مخصوصِ الحاقیه و فسخ؛ برای صدورِ جدید (تمدید) شماره‌ی بیمه‌نامه‌ی قبلی همین‌جا می‌نشیند
            'ref_policy_number' => trim(p2e_digits((string)($r['ref_policy_number'] ?? ($r['policy_number'] ?? ($r['prev_policy_number'] ?? ''))))) ?: null,
            'endorsement_request' => $endorseText ?: null,
            'cancellation_reason' => $cancelReason ?: null,
            'has_prev_body' => company_parse_yes_no($r['has_prev_body'] ?? ''),
            // «بازدید سلامت لازم نیست» => skip_health_inspection = 1.
            // خودروی صفرکیلومتر هم طبعاً بازدید سلامت نمی‌خواهد.
            'skip_health_inspection' => ($isNew || company_parse_yes_no($r['health_inspection'] ?? '') === 'NO') ? 1 : 0,
        ];
        $base['plate_display'] = company_row_label($base);
        // مدل و بیمه‌نامه‌ی قبلی در «اطلاعات صدور»ِ ردیف هم می‌نشیند تا در پنجره‌ی صدور آماده باشد
        $ii = array_filter(['car_model_year' => $base['model_year'], 'prev_policy_number' => $base['prev_policy_number'],
                            'chassis_no' => $base['chassis_no'], 'engine_no' => $base['engine_no'],
                            'prev_insurer' => trim((string)($r['prev_insurer'] ?? '')) ?: null]);
        $base['issue_info'] = $ii ? json_encode($ii, JSON_UNESCAPED_UNICODE) : null;

        foreach ($type === 'BOTH' ? ['THIRDPARTY', 'BODY'] : [$type] as $t) {
            $rows[] = array_merge($base, ['insurance_type' => $t, 'insurance_type_fa' => insurance_type_fa($t)]);
        }
    }

    // تعدادها: اگر خودِ متن داده بود همان، وگرنه از روی ردیف‌ها شمرده می‌شود
    $counts = $givenCounts;
    if (!$counts && $rows) {
        $c = ['THIRDPARTY' => 0, 'BODY' => 0, 'ENDORSEMENT' => 0, 'CANCELLATION' => 0];
        foreach ($rows as $row) {
            if (!empty($row['cancellation_reason'])) $c['CANCELLATION']++;
            elseif (!empty($row['endorsement_request'])) $c['ENDORSEMENT']++;
            else $c[$row['insurance_type']]++;
        }
        $counts = company_normalize_requested_counts($c);
    }
    return ['rows' => $rows, 'errors' => $errors, 'counts' => $counts, 'counts_fa' => company_requested_counts_fa($counts)];
}

// «صدور» / «تغییر اطلاعات» / «الحاقیه» / «فسخ» => NEW_POLICY / ENDORSEMENT / CANCELLATION
function company_parse_row_request($text) {
    $t = str_replace(['ي', 'ك', '‌', ' '], ['ی', 'ک', '', ''], mb_strtolower(trim((string)$text)));
    if ($t === '') return 'NEW_POLICY';
    if (mb_strpos($t, 'فسخ') !== false || $t === 'cancellation' || $t === 'cancel') return 'CANCELLATION';
    if (mb_strpos($t, 'الحاق') !== false || mb_strpos($t, 'تغییر') !== false || $t === 'endorsement') return 'ENDORSEMENT';
    return 'NEW_POLICY';
}

// کلیدهای فارسی یا انگلیسیِ تعدادها => کلیدهای داخلی
function company_counts_from_labels($in) {
    $out = ['THIRDPARTY' => 0, 'BODY' => 0, 'ENDORSEMENT' => 0, 'CANCELLATION' => 0];
    foreach ($in as $k => $v) {
        $kk = str_replace(['ي', 'ك', '‌', ' ', '_', '-'], ['ی', 'ک', '', '', '', ''], mb_strtolower((string)$k));
        $key = null;
        if (in_array($kk, ['thirdparty', 'third', 'ثالث', 'شخصثالث'], true)) $key = 'THIRDPARTY';
        elseif (in_array($kk, ['body', 'بدنه'], true)) $key = 'BODY';
        elseif (mb_strpos($kk, 'فسخ') !== false || $kk === 'cancellation') $key = 'CANCELLATION';
        elseif (mb_strpos($kk, 'الحاق') !== false || mb_strpos($kk, 'تغییر') !== false || $kk === 'endorsement') $key = 'ENDORSEMENT';
        if ($key) $out[$key] += intval(money_to_int($v) ?? 0);
    }
    return company_normalize_requested_counts($out);
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
        'expiry_date'    => ['تاریخ انقضا', 'انقضا', 'انقضاء', 'تاریخ انقضاء', 'سررسید', 'تاریخ اتمام بیمه نامه', 'تاریخ اتمام', 'expiry'],
        'car_name'       => ['خودرو', 'نام خودرو', 'نوع خودرو', 'سیستم', 'تیپ', 'car'],
        'model_year'     => ['مدل', 'سال ساخت', 'مدل خودرو', 'model'],
        'prev_policy_number' => ['شماره بیمه نامه قبلی', 'شماره بیمه‌نامه قبلی', 'بیمه نامه قبلی', 'شماره بیمه نامه سال قبل'],
        'plate_two'      => ['دو رقم پلاک', 'دو رقم'],
        'plate_letter'   => ['حرف پلاک', 'حرف'],
        'plate_three'    => ['سه رقم پلاک', 'سه رقم'],
        'plate_iran'     => ['کد ایران', 'ایران', 'کد شهر'],
        'has_prev_body'  => ['بیمه بدنه قبل', 'بدنه قبل', 'بیمه قبلی'],
        'health_inspection' => ['بازدید سلامت', 'بازدید'],
        'is_new_vehicle' => ['صفر کیلومتر', 'صفرکیلومتر', 'خودرو صفر', 'صفر'],
        'ref_policy_number' => ['شماره بیمه نامه', 'شماره بیمه‌نامه', 'بیمه نامه', 'شماره بیمهنامه', 'policy', 'policy no'],
        'endorsement_request' => ['خواسته', 'درخواست الحاقیه', 'موضوع الحاقیه', 'شرح الحاقیه'],
        'cancellation_reason' => ['دلیل فسخ', 'علت فسخ', 'دلیل'],
        'request'        => ['نوع درخواست', 'درخواست', 'request'],
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
    $stmt = $pdo->prepare("SELECT cr.created_at, cr.request_kind, c.name AS company_name FROM company_requests cr
                            JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req || !is_file($absSource)) return null;

    $ts = strtotime($req['created_at']);
    $kind = $req['request_kind'] ?? 'NEW_POLICY';
    $dateDir = company_request_date_folder($siteRoot, $ts, $req['company_name']);

    $stmt = $pdo->prepare("SELECT DISTINCT insurance_type FROM company_request_plates WHERE request_id = ? AND insurance_type IS NOT NULL");
    $stmt->execute([$requestId]);
    $types = array_column($stmt->fetchAll(), 'insurance_type');
    // هنوز ردیفی ثبت نشده؟ نوع‌ها را از «تعداد درخواستی» (ثالث/بدنه) بگیر تا نامه همین حالا
    // سرِ جایش بنشیند - مثلاً نامه‌ی ۳ ثالث و ۲ بدنه در هر دو پوشه‌ی ثالث و بدنه
    if (!$types) $types = company_types_from_requested_counts($pdo, $requestId);

    $typesFa = company_request_types_fa($pdo, $requestId);
    $fileName = company_letter_filename($req['company_name'], $ts, $typesFa, $ext);

    // مقصدها: پوشه‌ی هر نوعی که این درخواست دارد. اگر یک نامه هم بدنه خواسته هم
    // ثالث، در هر دو پوشه یک نسخه می‌نشیند؛ اگر فقط یکی را خواسته، فقط همان‌جا.
    // و اگر هنوز ردیفی ثبت نشده، فعلاً در خودِ پوشه‌ی تاریخ می‌ماند.
    $targets = [];
    foreach ($types as $t) $targets[] = $dateDir . '/' . sanitize_folder_name(company_kind_folder_label($kind, $t));
    $targets = array_values(array_unique($targets));
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

// ---- تعدادِ درخواستی از هر نوع (ستون requested_counts، مایگریشن ۰۱۱) ----
// کلیدها: THIRDPARTY (ثالث)، BODY (بدنه)، ENDORSEMENT (الحاقیه‌ی تغییر اطلاعات)، CANCELLATION (فسخ)
function company_requested_count_labels() {
    return ['THIRDPARTY' => 'ثالث', 'BODY' => 'بدنه', 'ENDORSEMENT' => 'الحاقیه (تغییر اطلاعات بیمه‌نامه)', 'CANCELLATION' => 'فسخ بیمه‌نامه'];
}

// ورودیِ کاربر (آرایه یا JSON) را تمیز می‌کند؛ اگر همه صفر بود null برمی‌گرداند
function company_normalize_requested_counts($input) {
    if (is_string($input)) $input = json_decode($input, true);
    if (!is_array($input)) return null;
    $out = [];
    foreach (array_keys(company_requested_count_labels()) as $k) {
        $out[$k] = max(0, min(9999, intval(money_to_int($input[$k] ?? 0) ?? 0)));
    }
    return array_sum($out) > 0 ? $out : null;
}

// نوعِ درخواست از روی تعدادها: صدورِ جدید اگر ثالث/بدنه دارد، وگرنه الحاقیه، وگرنه فسخ.
// (نوع روی کلِ درخواست است؛ نامه‌ای که هم صدور جدید و هم الحاقیه دارد «صدور جدید» حساب می‌شود.)
function company_kind_from_counts($counts, $fallback = 'NEW_POLICY') {
    if (!$counts) return $fallback;
    if (($counts['THIRDPARTY'] ?? 0) + ($counts['BODY'] ?? 0) > 0) return 'NEW_POLICY';
    if (($counts['ENDORSEMENT'] ?? 0) > 0) return 'ENDORSEMENT';
    if (($counts['CANCELLATION'] ?? 0) > 0) return 'CANCELLATION';
    return $fallback;
}

// نوع‌های بیمه (THIRDPARTY/BODY) که در «تعداد درخواستی» یک درخواست آمده‌اند
function company_types_from_requested_counts($pdo, $requestId) {
    try {
        $stmt = $pdo->prepare("SELECT requested_counts FROM company_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $c = json_decode((string)$stmt->fetchColumn(), true);
    } catch (Throwable $e) { return []; }
    if (!is_array($c)) return [];
    return array_values(array_filter(['THIRDPARTY', 'BODY'], fn($t) => !empty($c[$t])));
}

// متنِ خوانا: «۳ ثالث، ۲ بدنه، ۱ فسخ بیمه‌نامه»
function company_requested_counts_fa($json) {
    $c = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($c)) return '';
    $parts = [];
    foreach (company_requested_count_labels() as $k => $label) {
        if (!empty($c[$k])) $parts[] = fa_digits((string)intval($c[$k])) . ' ' . $label;
    }
    return implode('، ', $parts);
}

// بررسیِ یک‌باره‌ی اینکه مایگریشن‌های لازم روی دیتابیس اجرا شده‌اند یا نه.
// اگر فایل‌های PHP جایگزین شوند ولی مایگریشن اجرا نشده باشد، کوئری‌ها روی ستونِ
// نبوده می‌شکنند و کاربر فقط «خطای سرور» می‌بیند و نمی‌داند چرا. این تابع به‌جای
// آن پیامِ روشن می‌دهد که کدام فایل را باید در phpMyAdmin اجرا کند.
function company_schema_problem($pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $need = [
        'company_requests'       => ['request_kind' => '010_request_kinds.sql', 'requested_counts' => '011_requested_counts.sql'],
        'company_request_plates' => [
            'has_prev_body'     => '008_company_rows_checklist.sql',
            'chassis_no'        => '009_vin_rows_and_values.sql',
            'ref_policy_number' => '010_request_kinds.sql',
        ],
    ];
    foreach ($need as $table => $cols) {
        try {
            $have = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $c) {
                $have[] = $c['Field'] ?? ($c[0] ?? '');
            }
            foreach ($cols as $col => $file) {
                if (!in_array($col, $have, true)) {
                    return $cached = 'مایگریشن اجرا نشده است: لطفاً فایل migrations/' . $file
                        . ' را در phpMyAdmin اجرا کنید (ستون «' . $col . '» در جدول «' . $table . '» وجود ندارد).';
                }
            }
        } catch (Throwable $e) {
            // اگر SHOW COLUMNS کار نکرد (مثلاً SQLite در تست‌ها)، مانعِ کار نشو
            return $cached = null;
        }
    }
    return $cached = null;
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
    // حسابی که غیرفعال یا حذف شده، با نشستِ قبلی‌اش هم دیگر کار نمی‌کند
    $st = $pdo->prepare("SELECT * FROM company_portal_users WHERE id = ?");
    $st->execute([$_SESSION['company_user_id']]);
    $cu = $st->fetch();
    if (function_exists('sec_session_guard')) sec_session_guard($pdo);   // خروجِ خودکار/اجباریِ کاربرِ شرکت
    if (!$cu || empty($cu['is_active']) || !empty($cu['is_deleted'])) {
        $_SESSION = [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'حساب کاربری شما غیرفعال شده است.'], JSON_UNESCAPED_UNICODE);
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
        if (strpos($entryName, '..') !== false || substr($entryName, 0, 1) === '/' || preg_match('/^[a-zA-Z]:/', $entryName)) continue;
        $targetPath = $destReal . '/' . ltrim($entryName, '/');
        if (substr($entryName, -1) === '/') { @mkdir($targetPath, 0755, true); continue; }
        // فقط عکس و سند؛ هیچ فایلِ اجرایی (php، html، ...) از داخلِ زیپ بیرون نمی‌آید
        if (!preg_match('/\.(jpe?g|png|webp|gif|heic|bmp|tiff?|pdf|docx?|xlsx?|txt|csv)$/i', $entryName) || preg_match('/\.(php\d?|phtml|phar|pht|html?|svg|js|htaccess|ini|sh|py|pl|cgi)(\.|$)/i', basename($entryName))) continue;
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

    // سررسیدها از «تاریخ صدورِ داخلِ بیمه‌نامه»؛ اگر خوانده نشده بود، روزِ ثبتِ صدور در سایت
    $pj = (function_exists('fin_parse_jalali') && !empty($plate['policy_issue_date'])) ? fin_parse_jalali($plate['policy_issue_date']) : null;
    if ($pj) {
        [$jy, $jm, $jd] = $pj;
    } else {
        $issuedTs = $plate['issued_at'] ? strtotime($plate['issued_at']) : time();
        [$jy, $jm, $jd] = jalali_from_gregorian_ts($issuedTs);
    }
    [$fy, $fm] = company_add_months_jalali($jy, $jm, intval($comp['first_due_offset_months'] ?? 0));
    $fd = min($jd, company_jalali_month_len($fy, $fm));
    $firstTs = jalali_to_gregorian_ts($fy, $fm, $fd) + (intval($comp['first_due_offset_days'] ?? 0) * 86400);
    [$fy, $fm, $fd] = jalali_from_gregorian_ts($firstTs);

    // «فرمول ماموت»: همان روش رُندکردنِ اقساط که برای پرسنل استفاده می‌شود
    $parts = fin_split_installments(money_to_int($plate['total_premium']), $count, 'mamut');

    if (function_exists('fin_ensure_schema')) fin_ensure_schema($pdo);
    $hasTrack = function_exists('fin_tracking_code') && $pdo->query("SHOW COLUMNS FROM company_installments LIKE 'tracking_code'")->fetch();
    $pdo->prepare("DELETE FROM company_installments WHERE plate_id = ?")->execute([$plateId]);
    $ins = $hasTrack
        ? $pdo->prepare("INSERT INTO company_installments (plate_id, inst_number, amount, due_jalali, due_date, tracking_code) VALUES (?, ?, ?, ?, ?, ?)")
        : $pdo->prepare("INSERT INTO company_installments (plate_id, inst_number, amount, due_jalali, due_date) VALUES (?, ?, ?, ?, ?)");
    $cy = $fy; $cm = $fm;
    foreach ($parts as $i => $amount) {
        if ($i > 0) [$cy, $cm] = company_add_months_jalali($cy, $cm, 1);
        $cd = min($fd, company_jalali_month_len($cy, $cm));
        $dueJ = sprintf('%04d/%02d/%02d', $cy, $cm, $cd);
        $dueG = date('Y-m-d', jalali_to_gregorian_ts($cy, $cm, $cd));
        $ins->execute($hasTrack ? [$plateId, $i + 1, $amount, $dueJ, $dueG, fin_tracking_code($pdo, 'company_installments')] : [$plateId, $i + 1, $amount, $dueJ, $dueG]);
    }
    return ['ok' => true, 'count' => count($parts)];
}

// =====================================================================
//  پوشش‌های درخواستیِ بیمه بدنه برای هر ردیف شرکتی (همان فهرست پوشش‌های پرسنلی)
//  ذخیره: JSON {کلید: سطح یا true}؛ «{}» یعنی صریحاً «فقط پوشش پایه»، NULL یعنی مشخص نشده
// =====================================================================
function company_ensure_coverage_column($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM company_request_plates LIKE 'selected_coverages'")->fetch()) {
            $pdo->exec("ALTER TABLE company_request_plates ADD COLUMN selected_coverages TEXT NULL");
        }
    } catch (Throwable $e) { error_log('[company coverages column] ' . $e->getMessage()); }
}

// «اطلاعات صدور» (مالک، بیمه‌گذار، مشخصاتِ کاملِ خودرو) که کارشناس هنگام صدور تکمیل می‌کند؛
// برای ردیف شرکتی و پرونده‌ی کارکنان در یک ستونِ JSON ذخیره می‌شود (خودکار ساخته می‌شود)
function company_ensure_issue_info_cols($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['company_request_plates', 'policy_cases'] as $t) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE 'issue_info'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN issue_info TEXT NULL");
        } catch (Throwable $e) { error_log('[issue_info column] ' . $e->getMessage()); }
    }
}

// فیلدهای مجازِ «اطلاعات صدور»
function issue_info_fields() {
    return [
        'owner_name' => 'نام مالک', 'owner_national_id' => 'کد/شناسه ملی مالک', 'owner_phone' => 'تلفن مالک', 'owner_address' => 'نشانی مالک',
        'insured_name' => 'نام بیمه‌گذار', 'insured_national_id' => 'کد/شناسه ملی بیمه‌گذار', 'insured_phone' => 'تلفن بیمه‌گذار',
        'insured_postal_code' => 'کد پستی بیمه‌گذار', 'insured_address' => 'نشانی بیمه‌گذار', 'insured_birth_date' => 'تاریخ تولد بیمه‌گذار',
        'car_system' => 'سیستم', 'car_type' => 'تیپ', 'car_kind' => 'نوع خودرو', 'car_model_year' => 'مدل (سال ساخت)', 'car_color' => 'رنگ',
        'car_usage' => 'کاربری', 'car_capacity' => 'ظرفیت', 'car_cylinders' => 'تعداد سیلندر', 'chassis_no' => 'شماره شاسی',
        'engine_no' => 'شماره موتور', 'vin' => 'VIN', 'prev_insurer' => 'بیمه‌گر قبلی', 'prev_policy_number' => 'شماره بیمه‌نامه‌ی قبلی',
        'no_claim_years' => 'سال‌های عدم خسارت', 'note' => 'یادداشت صدور',
    ];
}

function issue_info_decode($json) {
    $d = json_decode((string)$json, true);
    return is_array($d) ? $d : [];
}

// ورودی: آرایه/JSON پوشش‌ها، یا 'none' (فقط پوشش پایه)؛ خروجی: JSON تمیز یا null
function company_clean_coverages($input) {
    if ($input === 'none') return '{}';
    if (is_string($input)) $input = json_decode($input, true);
    if (!is_array($input)) return null;
    $opts = body_coverage_options();
    $clean = [];
    foreach ($input as $k => $v) {
        if (!isset($opts[$k])) continue;
        $clean[$k] = $opts[$k]['tiers']
            ? (isset($opts[$k]['tiers'][(string)$v]) ? (string)$v : array_key_first($opts[$k]['tiers']))
            : true;
    }
    return json_encode((object)$clean, JSON_UNESCAPED_UNICODE);
}

// برچسب‌های خوانای پوشش‌ها: «نوسان قیمت: ۵۰ درصد افزایش ارزش»؛ برای «{}» => فقط پوشش پایه
function company_coverages_fa($json) {
    if ($json === null || $json === '') return [];
    $cov = json_decode((string)$json, true);
    if (!is_array($cov)) return [];
    if (!$cov) return ['فقط پوشش پایه (بدون پوشش تکمیلی)'];
    $opts = body_coverage_options();
    $out = [];
    foreach ($cov as $k => $v) {
        if (!isset($opts[$k])) continue;
        $out[] = $opts[$k]['label'] . (($opts[$k]['tiers'] && isset($opts[$k]['tiers'][(string)$v])) ? ': ' . $opts[$k]['tiers'][(string)$v] : '');
    }
    return $out;
}

// آخرین پوشش‌ها و تعهد مالیِ درخواست‌شده‌ی یک شرکت (پیش‌فرضِ فرم ثبت دستی)
function company_last_request_defaults($pdo, $companyId) {
    $out = ['coverages' => null, 'coverages_from' => null, 'liability_limit' => null, 'liability_from' => null];
    try {
        $st = $pdo->prepare("SELECT crp.selected_coverages, cr.id, cr.created_at FROM company_request_plates crp
                             JOIN company_requests cr ON cr.id = crp.request_id
                             WHERE cr.company_id = ? AND crp.insurance_type = 'BODY' AND crp.selected_coverages IS NOT NULL
                             ORDER BY cr.created_at DESC, crp.id DESC LIMIT 1");
        $st->execute([$companyId]);
        if ($r = $st->fetch()) {
            $out['coverages'] = json_decode($r['selected_coverages'], true);
            $out['coverages_from'] = ['request_id' => (int)$r['id'], 'date' => jalali_from_gregorian_ts_dotted(strtotime($r['created_at']))];
        }
        $st = $pdo->prepare("SELECT crp.liability_limit, cr.id, cr.created_at FROM company_request_plates crp
                             JOIN company_requests cr ON cr.id = crp.request_id
                             WHERE cr.company_id = ? AND crp.insurance_type = 'THIRDPARTY' AND crp.liability_limit > 0
                             ORDER BY cr.created_at DESC, crp.id DESC LIMIT 1");
        $st->execute([$companyId]);
        if ($r = $st->fetch()) {
            $out['liability_limit'] = (int)$r['liability_limit'];
            $out['liability_from'] = ['request_id' => (int)$r['id'], 'date' => jalali_from_gregorian_ts_dotted(strtotime($r['created_at']))];
        }
    } catch (Throwable $e) {}
    return $out;
}

// =====================================================================
//  ورودِ شرکت‌ها از اکسل: ستون‌ها (برای راهنما، فایلِ نمونه و تشخیصِ سرستون)
//  key => [عنوانِ سرستون در فایلِ نمونه, نام‌های قابل‌قبولِ دیگر, الزامی؟, توضیح, نمونه‌ی ۱, نمونه‌ی ۲]
// =====================================================================
function company_import_columns() {
    return [
        'name'            => ['نام شرکت', ['شرکت', 'نام', 'company', 'name', 'عنوان شرکت'], true, 'نامِ کاملِ شرکت؛ اگر از قبل ثبت شده باشد، همان شرکت به‌روز می‌شود.', 'شرکت نمونه‌ی صنعتی آرین', 'شرکت آرین پخش'],
        'economic_code'   => ['کد اقتصادی', ['شناسه ملی', 'کد اقتصادی/شناسه ملی', 'economic code', 'شناسه'], false, 'کد اقتصادی یا شناسه‌ی ملیِ شرکت.', '411111111111', '14000000000'],
        'phone'           => ['تلفن', ['شماره تماس', 'تلفن تماس', 'phone', 'موبایل'], false, 'شماره‌ی تماسِ شرکت.', '02188888888', '09121234567'],
        'address'         => ['آدرس', ['نشانی', 'address'], false, 'نشانیِ شرکت.', 'تهران، خیابان ولیعصر، پلاک ۱', 'کرج، شهرک صنعتی'],
        'parent'          => ['شرکت مادر', ['مادر', 'شرکت اصلی', 'parent'], false, 'اگر زیرمجموعه است، نامِ شرکتِ مادر (ثبت‌شده یا در همین فایل). خالی = مستقل.', '', 'شرکت نمونه‌ی صنعتی آرین'],
        'payment_terms'   => ['نحوه تسویه', ['تسویه', 'نحوه پرداخت', 'payment'], false, 'یکی از: «قسطی»، «نقدی ۳۰ روزه»، «نقدی فوری». خالی = مشخص نشده.', 'قسطی', 'نقدی ۳۰ روزه'],
        'allowed_insurers'=> ['بیمه‌گر مجاز', ['بیمه گر مجاز', 'بیمه‌گر', 'بیمه گر', 'insurer'], false, 'یکی از: «پاسارگاد»، «ایران»، «هردو». خالی = هردو.', 'هردو', 'پاسارگاد'],
        'installment_count' => ['تعداد اقساط', ['اقساط', 'تعداد قسط', 'installments'], false, 'عدد؛ برای تسویه‌ی قسطی (مثلاً ۹).', '9', ''],
        'first_due_offset_months' => ['سررسید اول (ماه بعد)', ['سررسید اول', 'ماه سررسید', 'فاصله ماه'], false, 'اولین قسط چند ماه بعد از تاریخ صدور است (۰ = همان ماه).', '1', '0'],
        'first_due_offset_days'   => ['روز اضافه', ['+ روز', 'روز', 'فاصله روز'], false, 'چند روز به سررسیدِ اول اضافه شود.', '0', '0'],
        'bale_group_chat_id' => ['شناسه گروه بله', ['گروه بله', 'شناسه گروه', 'chat id'], false, 'اختیاری؛ شناسه‌ی گروهِ بله‌ی شرکت.', '', ''],
    ];
}

function company_import_norm_value($key, $v) {
    $v = trim(p2e_digits((string)$v));
    $n = company_normalize_header($v);
    if ($key === 'payment_terms') {
        if ($v === '') return null;
        if (mb_strpos($n, 'قسط') !== false || $n === 'installment') return 'INSTALLMENT';
        if (mb_strpos($n, 'فوری') !== false || $n === 'cash_immediate' || $n === 'نقدی') return 'CASH_IMMEDIATE';
        if (mb_strpos($n, '30') !== false || mb_strpos($n, 'مهلت') !== false || $n === 'cash_net30') return 'CASH_NET30';
        return false;
    }
    if ($key === 'allowed_insurers') {
        if ($v === '') return null;   // خالی: شرکتِ تازه «هردو»، شرکتِ موجود بدونِ تغییر
        $p = mb_strpos($n, 'پاسارگاد') !== false || $n === 'pasargad'; $i = mb_strpos($n, 'ایران') !== false || $n === 'iran';
        if (mb_strpos($n, 'هردو') !== false || mb_strpos($n, 'هر دو') !== false || $n === 'both' || ($p && $i)) return 'BOTH';
        if ($p) return 'PASARGAD';
        if ($i) return 'IRAN';
        return false;
    }
    if (in_array($key, ['installment_count', 'first_due_offset_months', 'first_due_offset_days'], true)) {
        if ($v === '') return null;
        return preg_match('/^\d{1,3}$/', $v) ? intval($v) : false;
    }
    if ($key === 'phone') {   // اکسل صفرِ اولِ شماره‌ای که عدد تایپ شده را می‌اندازد
        $v = preg_replace('/[\s\-]+/', '', $v);
        if (preg_match('/^[1-9]\d{9}$/', $v)) $v = '0' . $v;
        return $v === '' ? null : $v;
    }
    if (in_array($key, ['economic_code', 'bale_group_chat_id'], true)) return $v === '' ? null : preg_replace('/\s+/', '', $v);
    return $v === '' ? null : preg_replace('/\s+/u', ' ', $v);
}

// خواندن و بررسیِ فایل: ['rows' => [[line, data, status (new|update|error|dup), errors, warnings], ...], 'columns' => [...], 'unknown' => [...]]
function company_import_parse($pdo, $path) {
    if (!function_exists('fin_read_spreadsheet')) return ['error' => 'ماژول خواندن اکسل در دسترس نیست.'];
    $table = fin_read_spreadsheet($path);
    if (!$table || count($table) < 2) return ['error' => 'فایل خوانده نشد یا ردیفی ندارد. سرستون‌ها باید در ردیفِ اول باشند (از فایلِ نمونه استفاده کنید).'];
    $cols = company_import_columns();
    $lookup = [];
    foreach ($cols as $k => $c) { $lookup[company_normalize_header($c[0])] = $k; foreach ($c[1] as $a) $lookup[company_normalize_header($a)] = $k; }
    $header = array_shift($table);
    $idx = []; $unknown = [];
    foreach ($header as $i => $h) {
        $nh = company_normalize_header(preg_replace('/\s*\*\s*$/u', '', (string)$h));
        if ($nh === '') continue;
        if (isset($lookup[$nh]) && !isset($idx[$lookup[$nh]])) $idx[$lookup[$nh]] = $i; else $unknown[] = (string)$h;
    }
    if (!isset($idx['name'])) return ['error' => 'ستونِ «نام شرکت» پیدا نشد. سرستون‌ها را مطابقِ راهنما بنویسید.', 'unknown' => $unknown];
    $existing = [];
    foreach ($pdo->query("SELECT id, name FROM companies") as $r) $existing[company_normalize_header($r['name'])] = (int)$r['id'];
    $rows = []; $seen = []; $fileNames = [];
    foreach ($table as $li => $cells) {
        if (!array_filter(array_map(fn($x) => trim((string)$x), $cells), 'strlen')) continue;
        $d = []; $errors = []; $warn = [];
        foreach ($idx as $k => $i) {
            $val = company_import_norm_value($k, $cells[$i] ?? '');
            if ($val === false) { $errors[] = '«' . $cols[$k][0] . '» نامعتبر است: ' . trim((string)($cells[$i] ?? '')); $val = null; }
            $d[$k] = $val;
        }
        if (empty($d['name'])) $errors[] = 'نام شرکت خالی است.';
        $nk = company_normalize_header($d['name'] ?? '');
        $status = $errors ? 'error' : (isset($existing[$nk]) ? 'update' : 'new');
        if (!$errors && isset($seen[$nk])) { $status = 'dup'; $errors[] = 'این شرکت در ردیفِ ' . fa_digits($seen[$nk]) . ' همین فایل هم آمده.'; }
        if (!$errors) { $seen[$nk] = $li + 2; $fileNames[$nk] = true; }
        if (($d['payment_terms'] ?? null) === 'INSTALLMENT' && empty($d['installment_count'])) $warn[] = 'تسویه قسطی است ولی تعداد اقساط خالی است.';
        $rows[] = ['line' => $li + 2, 'data' => $d, 'status' => $status, 'errors' => $errors, 'warnings' => $warn, 'existing_id' => $existing[$nk] ?? null];
    }
    // شرکتِ مادر باید ثبت‌شده یا در همین فایل باشد
    foreach ($rows as &$r) {
        $p = $r['data']['parent'] ?? null;
        if (!$p || $r['status'] === 'error' || $r['status'] === 'dup') continue;
        $pk = company_normalize_header($p);
        if ($pk === company_normalize_header($r['data']['name'])) { $r['errors'][] = 'شرکت نمی‌تواند مادرِ خودش باشد.'; $r['status'] = 'error'; }
        elseif (!isset($existing[$pk]) && !isset($fileNames[$pk])) $r['warnings'][] = 'شرکتِ مادر «' . $p . '» پیدا نشد؛ بدونِ مادر ثبت می‌شود.';
    }
    unset($r);
    return ['rows' => $rows, 'columns' => array_keys($idx), 'unknown' => $unknown];
}

// ثبت: شرکت‌های تازه ساخته و موجودها (اگر $update) فقط با خانه‌های پرِ فایل به‌روز می‌شوند؛ بعد شرکتِ مادر وصل می‌شود
function company_import_commit($pdo, array $rows, $update) {
    $created = 0; $updated = 0; $skipped = 0; $ids = [];
    $fields = ['economic_code', 'phone', 'address', 'payment_terms', 'allowed_insurers', 'installment_count', 'first_due_offset_months', 'first_due_offset_days', 'bale_group_chat_id'];
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            if (!in_array($r['status'], ['new', 'update'], true)) { $skipped++; continue; }
            $d = $r['data'];
            $nk = company_normalize_header($d['name']);
            if ($r['status'] === 'new') {
                $pdo->prepare("INSERT INTO companies (name, kind, payment_terms, economic_code, address, phone, bale_group_chat_id, allowed_insurers, installment_count, first_due_offset_months, first_due_offset_days)
                               VALUES (?, 'INSURANCE_CLIENT', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$d['name'], $d['payment_terms'] ?? null, $d['economic_code'] ?? null, $d['address'] ?? null, $d['phone'] ?? null, $d['bale_group_chat_id'] ?? null,
                               $d['allowed_insurers'] ?? 'BOTH', $d['installment_count'] ?? null, intval($d['first_due_offset_months'] ?? 0), intval($d['first_due_offset_days'] ?? 0)]);
                $ids[$nk] = (int)$pdo->lastInsertId(); $created++;
            } else {
                $id = (int)$r['existing_id']; $ids[$nk] = $id;
                if (!$update) { $skipped++; continue; }
                $set = ["kind = IF(kind = 'PERSONNEL_EMPLOYER', 'BOTH', kind)"]; $vals = [];
                foreach ($fields as $f) if (isset($d[$f])) { $set[] = "`$f` = ?"; $vals[] = $d[$f]; }
                $vals[] = $id;
                $pdo->prepare("UPDATE companies SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
                $updated++;
            }
        }
        // شرکتِ مادر
        $all = [];
        foreach ($pdo->query("SELECT id, name FROM companies") as $c) $all[company_normalize_header($c['name'])] = (int)$c['id'];
        $setParent = $pdo->prepare("UPDATE companies SET parent_id = ? WHERE id = ?");
        foreach ($rows as $r) {
            if (empty($r['data']['parent']) || !in_array($r['status'], ['new', 'update'], true)) continue;
            if ($r['status'] === 'update' && !$update) continue;
            $cid = $ids[company_normalize_header($r['data']['name'])] ?? null;
            $pid = $all[company_normalize_header($r['data']['parent'])] ?? null;
            if ($cid && $pid && $cid !== $pid) $setParent->execute([$pid, $cid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[company_import_commit] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'ثبت در دیتابیس ممکن نشد.'];
    }
    return ['ok' => true, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped];
}
