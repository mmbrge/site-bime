<?php
// فایل: api/_vdoc.php
// شناساییِ خودکارِ مدارکِ خودرو (فاکتور/سند فروش، سند مالکیت، کارت خودرو، بیمه‌نامه‌ی قبلی) با api/_vdoc_reader.php (فقط PDFِ متنی)
//  - مدارکِ تازه‌ی شرکت‌ها (هر صفحه‌ی PDF جدا) با ai_status = 'PENDING' صف می‌شوند و یکی‌یکی (هر درخواست یک صفحه) خوانده می‌شوند
//  - شناخته‌شده و هم‌خوان با یک ردیف (شاسی/موتور) => خودکار روی همان ردیف می‌نشیند (AUTO)
//  - فاکتور/سندِ کامل که ردیفی ندارد => ردیفِ تازه ساخته می‌شود و مدرک رویش می‌نشیند (AUTO_NEW)
//  - ناقص => در صندوقِ مدارک با پیشنهاد می‌ماند تا کارشناس کاملش کند (PARTIAL)؛ شناخته‌نشده => صندوقِ مدارک (NONE)
// ستون‌ها خودکار ساخته می‌شوند؛ مدارکِ قدیمی (ai_status خالی) دست نمی‌خورند.

function vdoc_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM company_documents")->fetchAll(), 'Field');
        if (!in_array('ai_status', $cols, true)) $pdo->exec("ALTER TABLE company_documents ADD COLUMN ai_status VARCHAR(12) NULL, ADD COLUMN ai_json MEDIUMTEXT NULL, ADD COLUMN ai_at DATETIME NULL");
    } catch (Throwable $e) { error_log('[vdoc_ensure] ' . $e->getMessage()); }
}

// شماره موتور/شاسی: فقط حروف و ارقامِ لاتین، بزرگ (. * - / و فاصله پاک می‌شوند)
function vdoc_clean_code($s) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$s)));
}
// همان پاک‌سازی در SQL (برای مقایسه با ستون‌های ردیف)
function vdoc_sql_clean($col) {
    return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col, ''), '-', ''), '.', ''), '*', ''), ' ', ''), '/', ''), '_', ''))";
}

// اجرای خواننده روی یک فایل (PHP: api/_vdoc_reader.php). فقط PDFِ متنی خوانده می‌شود؛ عکس/اسکن => «خوانده نشد» (ورودِ دستی)
//   $opt['smart']: true = اگر کامل خوانده نشد «پردازشِ هوشمند» (OCR + پرامپتِ نوعِ مدرک)؛ پیش‌فرض = هرگز
//                  (فقط دستی یا طبقِ «خواندنِ مدارک = پردازشِ هوشمند» در تعریفِ شرکت)؛ $opt['hint'] = نوعِ پیشنهادیِ مدرک
function vdoc_run($pdo, $abs, $page = 1, $timeout = 280, array $opt = []) {
    if (!is_file($abs)) return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    $ext = upload_ext($abs);
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'bmp'], true)) return ['ok' => false, 'error' => 'این نوع فایل خوانده نمی‌شود.'];
    require_once __DIR__ . '/_vdoc_reader.php';
    try { $r = vdr_read($abs, intval($page) ?: 1); }
    catch (Throwable $e) { error_log('[vdoc_run] ' . $e->getMessage()); $r = ['ok' => false, 'error' => 'خواندنِ فایل ممکن نشد.', 'scan' => $ext !== 'pdf']; }
    $smart = !empty($opt['smart']);
    $full = !empty($r['ok']) && ($r['status'] ?? '') === 'full';
    if ($pdo && !$full && $smart) {
        try {
            require_once __DIR__ . '/_doc_smart.php';
            if (ds_smart_available($pdo)) {
                $s = ds_smart_file($pdo, $abs, intval($page) ?: 1, (string)($opt['hint'] ?? ''), intval($opt['company_id'] ?? 0));
                $rank = ['full' => 3, 'partial' => 2, 'none' => 1];
                if (!empty($s['ok']) && (empty($r['ok']) || ($rank[$s['status'] ?? 'none'] ?? 0) >= ($rank[$r['status'] ?? 'none'] ?? 0))) {
                    if (!empty($r['ok'])) $s['data'] += array_diff_key((array)$r['data'], $s['data']);   // جاهای خالی از خواننده‌ی متنی
                    return $s;
                }
            }
        } catch (Throwable $e) { error_log('[vdoc smart] ' . $e->getMessage()); }
    }
    // اسکن/عکس (بی‌متن): اگر سرویسِ «خواندنِ اسکن (هوش مصنوعی)» تنظیم شده، با همان خوانده می‌شود
    if (empty($r['ok']) && !empty($r['scan']) && $pdo) {
        try { require_once __DIR__ . '/_vdoc_ai.php'; if (vdoc_ai_ready($pdo)) return vdoc_ai_read($pdo, $abs, intval($page) ?: 1); }
        catch (Throwable $e) { error_log('[vdoc_ai] ' . $e->getMessage()); }
    }
    return $r;
}

// چند فایلِ تک‌صفحه‌ای => [i => نتیجه]
function vdoc_run_many($pdo, array $files, $timeout = 420) {
    $out = [];
    foreach ($files as $i => $f) $out[$i] = vdoc_run($pdo, $f);
    return $out;
}

// ردیفِ هم‌خوان با شاسی/موتورِ خوانده‌شده (در همان درخواست؛ اگر درخواست مشخص نیست، درخواست‌های بازِ همان شرکت)
function vdoc_match_row($pdo, $companyId, $requestId, array $d) {
    $vin = vdoc_clean_code(($d['vin'] ?? '') ?: ($d['chassis_no'] ?? ''));
    $ch = vdoc_clean_code($d['chassis_no'] ?? '');
    $eng = vdoc_clean_code($d['engine_no'] ?? '');
    $pp = $d['plate_parts'] ?? null;
    $plateOk = is_array($pp) && preg_match('/^\d{3}$/', (string)($pp['three'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['iran'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['two'] ?? ''));
    if (strlen($vin) < 6 && strlen($eng) < 6 && !$plateOk) return null;
    $w = []; $a = [];
    foreach (array_unique(array_filter([$vin, $ch], fn($x) => strlen($x) >= 6)) as $v) { $w[] = vdoc_sql_clean('crp.chassis_no') . " = ?"; $a[] = $v; $w[] = vdoc_sql_clean('crp.vin') . " = ?"; $a[] = $v; }
    if (strlen($eng) >= 6) { $w[] = vdoc_sql_clean('crp.engine_no') . " = ?"; $a[] = $eng; }
    // پلاکِ کامل (سه رقم، دو رقم و کدِ ایران) هم ردیف را پیدا می‌کند — ردیف‌هایی که از نامه فقط با پلاک ساخته شده‌اند
    if ($plateOk) { $w[] = "(crp.plate_p2 = ? AND crp.plate_p1 = ? AND crp.plate_p4 = ?)"; array_push($a, $pp['three'], $pp['iran'], $pp['two']); }
    $scope = $requestId ? "crp.request_id = ?" : "cr.company_id = ? AND cr.status NOT IN ('ISSUED', 'CANCELLED')";
    $st = $pdo->prepare("SELECT crp.* FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                          WHERE $scope AND COALESCE(crp.status, 'PENDING') NOT IN ('ISSUED', 'CANCELLED') AND (" . implode(' OR ', $w) . ") ORDER BY crp.id LIMIT 4");
    $st->execute(array_merge([$requestId ?: $companyId], $a));
    $rows = $st->fetchAll();
    return $rows ?: null;   // ممکن است ثالث و بدنه‌ی یک خودرو دو ردیف باشند
}

// نوعِ مدرک در چک‌لیستِ ردیف
function vdoc_doc_type($kind, array $d, array $row) {
    if ($kind === 'sales_invoice') return 'sales_invoice';
    if ($kind === 'ownership_doc') return 'ownership_doc';
    if ($kind === 'car_card') {
        // پشتِ کارت: مالک و پلاک ندارد ولی کاربری/موتور/شماره مسلسل دارد
        $front = trim((string)($d['owner_name'] ?? '')) !== '' || !empty(($d['plate_parts'] ?? [])['three']);
        $back = trim((string)($d['engine_no'] ?? '')) !== '' || trim((string)($d['usage'] ?? '')) !== '' || trim((string)($d['card_serial'] ?? '')) !== '';
        return !$front && $back ? 'car_card_back' : 'car_card_front';
    }
    if ($kind === 'national_card') return 'national_card';
    if ($kind === 'policy') return ($d['policy_type'] ?? '') === 'BODY' ? 'prev_body_policy' : 'prev_third_policy';
    return null;
}

// نقشه‌ی «کلیدِ مدرک ← خانه‌ی اطلاعاتِ صدورِ ردیف» برای هر نوعِ مدرک (در پر کردنِ جاهای خالی و در مقایسه با مقدارهای ثبت‌شده)
function vdoc_field_map($kind, array $d) {
    $map = ['owner_name' => 'owner_name', 'national_id' => 'owner_national_id', 'mobile' => 'owner_phone', 'address' => 'owner_address',
            'postal_code' => 'owner_postal_code', 'father_name' => 'owner_father', 'birth_date' => 'owner_birth_date',
            'car_system' => 'car_system', 'car_tip' => 'car_type', 'usage' => 'car_kind', 'model_year' => 'car_model_year', 'color' => 'car_color',
            'capacity' => 'car_capacity', 'cylinders' => 'car_cylinders', 'engine_no' => 'engine_no', 'vin' => 'vin', 'chassis_no' => 'chassis_no',
            'fuel' => 'car_fuel', 'axles' => 'car_axles', 'wheels' => 'car_wheels', 'cab_no' => 'cab_no', 'card_serial' => 'card_serial', 'country' => 'car_country'];
    // «نوعِ وسیله» جدا آمده: کاربری در car_usage
    if (trim((string)($d['car_kind'] ?? '')) !== '') { $map['car_kind'] = 'car_kind'; $map['usage'] = 'car_usage'; }
    // تلفنِ ثابت وقتی موبایل نیامده
    if (trim((string)($d['mobile'] ?? '')) === '' && trim((string)($d['phone'] ?? '')) !== '') $map['phone'] = 'owner_phone';
    // بیمه‌نامه‌ی قبلی (بدنه/ثالث): بیمه‌گرِ قبلی، شماره، انقضا، تخفیفِ عدمِ خسارت، ارزشِ بیمه‌شده و پوشش‌ها — مالک/بیمه‌گذارِ آن بیمه‌نامه روی ردیف نمی‌نشیند
    if ($kind === 'policy') {
        $map = ['car_system' => 'car_system', 'car_tip' => 'car_type', 'model_year' => 'car_model_year', 'color' => 'car_color', 'capacity' => 'car_capacity',
                'engine_no' => 'engine_no', 'vin' => 'vin', 'chassis_no' => 'chassis_no', 'fuel' => 'car_fuel',
                'policy_number' => 'prev_policy_number', 'insurer' => 'prev_insurer', 'end_date' => 'prev_expiry', 'no_claim_years' => 'no_claim_years',
                'no_claim_percent' => 'prev_no_claim_percent', 'car_value' => 'prev_car_value', 'covers' => 'prev_covers', 'unique_code' => 'prev_unique_code'];
    }
    // کارتِ ملی: فقط اطلاعاتِ شخص (مالک) اگر خالی است
    if ($kind === 'national_card') $map = ['owner_name' => 'owner_name', 'national_id' => 'owner_national_id', 'father_name' => 'owner_father', 'birth_date' => 'owner_birth_date'];
    return $map;
}

// اطلاعاتِ خوانده‌شده روی ردیف: فقط خانه‌های خالی پر می‌شوند (چیزی که ثبت شده عوض نمی‌شود)
function vdoc_apply_to_row($pdo, $plateId, array $d, $kind = '') {
    if (!function_exists('company_ensure_issue_info_cols')) require_once __DIR__ . '/_company_helpers.php';
    company_ensure_issue_info_cols($pdo);
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
    $st->execute([$plateId]);
    $r = $st->fetch();
    if (!$r) return;
    if ($kind === 'national_card') unset($d['vin'], $d['chassis_no'], $d['engine_no'], $d['plate_parts']);
    // «شماره شاسی»ِ مدرک در شاسیِ ردیف؛ VIN (۱۷ رقمی) در VIN — هیچ مقدارِ موجودی عوض نمی‌شود
    $chassis = vdoc_clean_code(($d['chassis_no'] ?? '') ?: ($d['vin'] ?? ''));
    $vin = vdoc_clean_code($d['vin'] ?? '');
    $carName = trim(($d['car_system'] ?? '') . ' ' . ($d['car_tip'] ?? '')) ?: ($d['usage'] ?? '');
    // VIN فقط وقتی پر می‌شود که با شاسیِ ردیف یکی باشد (مدرکِ خودروی دیگر، VINِ ناجور روی ردیف نگذارد)
    $pdo->prepare("UPDATE company_request_plates SET chassis_no = COALESCE(NULLIF(chassis_no, ''), NULLIF(?, '')), vin = COALESCE(NULLIF(vin, ''), IF(" . vdoc_sql_clean('chassis_no') . " = ?, NULLIF(?, ''), NULL)),
                   engine_no = COALESCE(NULLIF(engine_no, ''), NULLIF(?, '')), car_name = COALESCE(NULLIF(car_name, ''), NULLIF(?, '')), car_type = COALESCE(NULLIF(car_type, ''), NULLIF(?, '')) WHERE id = ?")
        ->execute([$chassis, strlen($vin) === 17 ? $vin : '', strlen($vin) === 17 ? $vin : '', vdoc_clean_code($d['engine_no'] ?? ''), mb_substr($carName, 0, 100), mb_substr((string)($d['usage'] ?? ''), 0, 120), $plateId]);
    $info = issue_info_decode($r['issue_info'] ?? null);
    $map = vdoc_field_map($kind, $d);
    $ch = false;
    foreach ($map as $from => $to) {
        $v = trim((string)($d[$from] ?? ''));
        if ($v !== '' && trim((string)($info[$to] ?? '')) === '') { $info[$to] = mb_substr($v, 0, 250); $ch = true; }
    }
    if ($ch) $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), $plateId]);
    if (($r['status'] ?? '') === 'ISSUED') return;   // ردیفِ صادرشده: فقط خانه‌های خالیِ اطلاعات (بالا)؛ پلاک/نقش‌ها دست نمی‌خورد
    $hasPlate = trim((string)($r['plate_p2'] ?? '')) !== '' || trim((string)($r['plate_p1'] ?? '')) !== '';
    // پلاکِ خوانده‌شده از بارکدِ سند/کارت: فقط وقتی ردیف پلاک ندارد و حرفِ پلاک هم شناخته شده
    $pp = $d['plate_parts'] ?? null;
    if (!$hasPlate && is_array($pp) && !empty($pp['letter']) && preg_match('/^\d{3}$/', (string)($pp['three'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['two'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['iran'] ?? ''))) {
        // plate_p4 = دو رقمِ اول، plate_p2 = سه رقم، plate_p1 = کدِ ایران (مثلِ فرم‌های ثبت)
        $pdo->prepare("UPDATE company_request_plates SET plate_p4 = ?, plate_letter = ?, plate_p2 = ?, plate_p1 = ? WHERE id = ? AND COALESCE(plate_p2, '') = '' AND COALESCE(plate_p1, '') = ''")
            ->execute([$pp['two'], $pp['letter'], $pp['three'], $pp['iran'], $plateId]);
        $hasPlate = true;
    }
    // فاکتورِ فروش و بی‌پلاک = صفرکیلومتر (بازدیدِ سلامت لازم نیست)
    if ($kind === 'sales_invoice' && !$hasPlate && empty($r['is_new_vehicle'])) {
        $pdo->prepare("UPDATE company_request_plates SET is_new_vehicle = 1, skip_health_inspection = 1 WHERE id = ?")->execute([$plateId]);
    }
    // مالکِ مدرک با شرکت فرق دارد => نقشِ «ذینفع» یا «مالک = بیمه‌گذار» طبقِ تنظیمِ شرکت (فقط اگر نقشِ ردیف/درخواست تعیین نشده)
    if (!in_array($kind, ['policy', 'national_card'], true)) { try { vdoc_owner_party($pdo, $r, $d); } catch (Throwable $e) { error_log('[vdoc_owner_party] ' . $e->getMessage()); } }
}

// مالکِ مدرک ≠ شرکت؟ (نام ساده‌شده یا شناسه‌ی ملی = کد اقتصادی) => null اگر اطلاعات کافی نیست
function vdoc_owner_differs(array $d, array $company) {
    $name = trim((string)($d['owner_name'] ?? '')); $nid = preg_replace('/\D/', '', (string)($d['national_id'] ?? ''));
    if (($name === '' && $nid === '') || trim((string)($company['name'] ?? '')) === '') return null;
    $eco = preg_replace('/\D/', '', (string)($company['economic_code'] ?? ''));
    if ($nid !== '' && $eco !== '' && $nid === $eco) return false;
    if (!function_exists('company_name_key')) require_once __DIR__ . '/_case_helpers.php';
    $a = company_name_key($name); $b = company_name_key($company['name']);
    if ($a !== '' && $b !== '' && (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false)) return false;
    return $name !== '' || $nid !== '' ? true : null;
}

function vdoc_owner_party($pdo, array $row, array $d) {
    if (!function_exists('party_ensure')) require_once __DIR__ . '/_party.php';
    party_ensure($pdo);
    if (party_valid_mode($row['party_mode'] ?? '')) return;          // نقشِ ردیف را کاربر تعیین کرده
    $st = $pdo->prepare("SELECT cr.party_mode, c.* FROM company_requests cr JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
    $st->execute([intval($row['request_id'])]);
    $c = $st->fetch();
    if (!$c) return;
    $reqMode = party_valid_mode($c['party_mode'] ?? '');
    if ($reqMode && $reqMode !== 'COMPANY') return;                  // پیش‌فرضِ درخواست «به نامِ دیگری» است
    if (vdoc_owner_differs($d, $c) !== true) return;
    $mode = ($c['other_owner_mode'] ?? '') === 'OWNER' ? 'OWNER' : 'BENEFICIARY';
    party_set_rows($pdo, [intval($row['id'])], $mode, ['name' => $d['owner_name'] ?? '', 'national_id' => $d['national_id'] ?? '', 'phone' => $d['mobile'] ?? '',
                                                       'address' => $d['address'] ?? '', 'postal_code' => $d['postal_code'] ?? '']);
}

// نوعِ بیمه‌ی ردیفِ تازه: اگر درخواست فقط یک نوع خواسته (تعدادها یا ردیف‌های موجود)
function vdoc_request_type($pdo, $requestId) {
    $st = $pdo->prepare("SELECT requested_counts FROM company_requests WHERE id = ?");
    $st->execute([$requestId]);
    $c = json_decode((string)$st->fetchColumn(), true) ?: [];
    $t = intval($c['THIRDPARTY'] ?? 0); $b = intval($c['BODY'] ?? 0);
    if ($t && !$b) return 'THIRDPARTY';
    if ($b && !$t) return 'BODY';
    $st = $pdo->prepare("SELECT DISTINCT insurance_type FROM company_request_plates WHERE request_id = ? AND insurance_type IS NOT NULL");
    $st->execute([$requestId]);
    $types = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($types) === 1 ? $types[0] : null;
}

// پردازشِ یک مدرکِ صف‌شده
// مدارکِ خودرو که اطلاعاتشان روی ردیف می‌نشیند (کارت، سند، فاکتورِ فروش)؛ وقتی مستقیم روی یک ردیف بارگذاری می‌شوند هم خوانده می‌شوند
function vdoc_vehicle_types() { return ['car_card_front', 'car_card_back', 'ownership_doc', 'sales_invoice', 'new_car_card', 'new_ownership_doc', 'car_card_or_title']; }

// خواندن با «پردازشِ هوشمند» اول (متنِ PDF یا OCR ← تشخیصِ نوع یا نوعِ انتخاب‌شده ← پرامپتِ همان نوع)؛ جاهای خالی از خواننده‌ی متنیِ سایت،
// و اگر پردازشِ هوشمند تنظیم نشده یا نشد، همان خواننده‌ی سایت (و برای اسکن «خواندنِ اسکن (هوش مصنوعی)»)
function vdoc_smart_first($pdo, $abs, $hint, $companyId) {
    require_once __DIR__ . '/_doc_smart.php';
    if (ds_smart_available($pdo)) {
        try { $s = ds_smart_file($pdo, $abs, 1, (string)$hint, intval($companyId)); } catch (Throwable $e) { error_log('[vdoc_smart_first] ' . $e->getMessage()); $s = ['ok' => false]; }
        if (!empty($s['ok'])) {
            if (upload_ext($abs) === 'pdf') {
                try { require_once __DIR__ . '/_vdoc_reader.php'; $r = vdr_read($abs, 1); if (!empty($r['ok']) && ($r['kind'] ?? '') === ($s['kind'] ?? '')) $s['data'] += array_diff_key((array)$r['data'], (array)$s['data']); } catch (Throwable $e) {}
            }
            return $s;
        }
    }
    return vdoc_run($pdo, $abs, 1, 280, []);
}
// نوعی که کارشناس/ثبت‌کننده برای مدرک زده، اگر با نوعِ شناخته‌شده جور است، می‌ماند (مثلاً «پشت کارت ماشین»)
function vdoc_keep_type($docType, $kind, $auto) {
    $ok = ['car_card' => ['car_card_front', 'car_card_back', 'new_car_card'], 'ownership_doc' => ['ownership_doc', 'new_ownership_doc'], 'sales_invoice' => ['sales_invoice'],
           'policy' => ['prev_body_policy', 'prev_third_policy', 'policy_doc'], 'national_card' => ['national_card']];
    return in_array((string)$docType, $ok[$kind] ?? [], true) ? (string)$docType : $auto;
}
// مدرکی که ردیف ندارد، ردیفِ تازه بسازد؟ فاکتورِ فروش و سند (با شاسی)، و کارتِ ماشین (با شاسی و پلاکِ کامل)
function vdoc_can_create_row($kind, array $d, array $res) {
    $vin = vdoc_clean_code(($d['vin'] ?? '') ?: ($d['chassis_no'] ?? ''));
    if (strlen($vin) < 6) return in_array($kind, ['sales_invoice', 'ownership_doc'], true) && ($res['status'] ?? '') === 'full';
    if (in_array($kind, ['sales_invoice', 'ownership_doc'], true)) return true;
    $pp = $d['plate_parts'] ?? null;
    return $kind === 'car_card' && is_array($pp) && !empty($pp['letter']) && preg_match('/^\d{3}$/', (string)($pp['three'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['iran'] ?? ''));
}
// بعد از ساختِ ردیفِ تازه: صفحه‌های «ناقص»ِ همان درخواست که حالا ردیفشان پیدا می‌شود (مثلاً پشتِ کارت پیش از روی کارت خوانده شده بود) روی ردیف می‌نشینند
function vdoc_rematch_partials($pdo, $siteRoot, $requestId) {
    static $busy = false;
    if ($busy || !$requestId) return;
    $busy = true;
    try {
        $st = $pdo->prepare("SELECT id, ai_json FROM company_documents WHERE request_id = ? AND status = 'UNASSIGNED' AND ai_status = 'PARTIAL'");
        $st->execute([$requestId]);
        foreach ($st->fetchAll() as $x) {
            $res = json_decode((string)$x['ai_json'], true);
            if (!is_array($res) || empty($res['ok']) || empty($res['data']) || !vdoc_match_row($pdo, 0, intval($requestId), (array)$res['data'])) continue;
            $pdo->prepare("UPDATE company_documents SET ai_status = 'PENDING' WHERE id = ? AND ai_status = 'PARTIAL'")->execute([$x['id']]);
            vdoc_process_company_doc($pdo, $siteRoot, intval($x['id']), $res);
        }
    } catch (Throwable $e) { error_log('[vdoc_rematch] ' . $e->getMessage()); }
    $busy = false;
}

// \$pre: نتیجه‌ی آماده (مثلاً «خواندنِ هوشمند» = OCR + هوشِ مصنوعی در api/_doc_smart.php) به‌جای خواندنِ دوباره‌ی فایل
function vdoc_process_company_doc($pdo, $siteRoot, $docId, $pre = null) {
    vdoc_ensure($pdo);
    // برداشتنِ اتمی از صف (دو درخواستِ هم‌زمان یک مدرک را دو بار نمی‌خوانند)
    $up = $pdo->prepare("UPDATE company_documents SET ai_status = 'RUNNING', ai_at = NOW() WHERE id = ? AND ai_status = 'PENDING'");
    $up->execute([$docId]);
    if (!$up->rowCount()) return ['ok' => false, 'skipped' => true];
    $st = $pdo->prepare("SELECT cd.*, c.name AS company_name FROM company_documents cd JOIN companies c ON c.id = cd.company_id WHERE cd.id = ?");
    $st->execute([$docId]);
    $doc = $st->fetch();
    $save = function ($status, $res) use ($pdo, $docId) {
        $pdo->prepare("UPDATE company_documents SET ai_status = ?, ai_json = ?, ai_at = NOW() WHERE id = ?")->execute([$status, json_encode($res, JSON_UNESCAPED_UNICODE), $docId]);
        return ['ok' => true, 'status' => $status, 'result' => $res];
    };
    $onRow = $doc && $doc['status'] === 'ASSIGNED' && $doc['plate_id'] && in_array($doc['doc_type'], vdoc_vehicle_types(), true);
    if (!$doc || ($doc['status'] !== 'UNASSIGNED' && !$onRow)) return $save('NONE', ['ok' => false, 'error' => 'مدرک دیگر در صندوق نیست.']);
    $abs = $siteRoot . '/' . ltrim($doc['file_path'], '/');
    // «پردازشِ هوشمند»ِ این شرکت (تعریفِ شرکت ← خواندنِ مدارک = ۲): OCR + پرامپتِ نوعِ مدرک
    $mode = 1;
    try { $m = $pdo->prepare("SELECT doc_read FROM companies WHERE id = ?"); $m->execute([$doc['company_id']]); $mode = intval($m->fetchColumn()); } catch (Throwable $e) {}
    // «بررسیِ خودکار»ِ ارسالِ گروهی / بررسیِ دستی (want_smart) یا شرکتِ «پردازشِ هوشمند»: اول پردازشِ هوشمند
    $aj0 = json_decode((string)($doc['ai_json'] ?? ''), true) ?: [];
    $wantSmart = $mode === 2 || !empty($aj0['want_smart']);
    $hint = in_array((string)$doc['doc_type'], ['', 'other'], true) ? '' : (string)$doc['doc_type'];
    $res = is_array($pre) ? $pre : ($wantSmart ? vdoc_smart_first($pdo, $abs, $hint, intval($doc['company_id']))
                                              : vdoc_run($pdo, $abs, 1, 280, ['smart' => false, 'hint' => $hint, 'company_id' => intval($doc['company_id'])]));
    if ($wantSmart && !empty($res['ok'])) $res['want_smart'] = 1;   // «شناساییِ دوباره» هم با همین روش
    if (empty($res['ok'])) return $save('FAIL', $res);
    $d = $res['data'] ?? [];
    // مدرکی که از قبل روی یک ردیف است: فقط اطلاعاتش روی همان ردیف می‌نشیند (خانه‌های خالی)
    if ($onRow) {
        if (!empty($d)) vdoc_apply_to_row($pdo, intval($doc['plate_id']), $d, $res['kind'] ?? '');
        $res['plate_id'] = intval($doc['plate_id']);
        return $save(empty($d) ? 'NONE' : (($res['status'] ?? '') === 'full' ? 'AUTO' : 'PARTIAL'), $res);
    }
    $kind = $res['kind'] ?? 'unknown';
    $hasPlate = !empty(($d['plate_parts'] ?? [])['three']);
    if ($kind === 'unknown' || (empty($d['vin']) && empty($d['chassis_no']) && empty($d['engine_no']) && !$hasPlate)) return $save($kind === 'unknown' && empty($d) ? 'NONE' : 'PARTIAL', $res);

    $rows = vdoc_match_row($pdo, intval($doc['company_id']), $doc['request_id'] ? intval($doc['request_id']) : null, $d);
    $created = false;
    if (!$rows && $doc['request_id'] && vdoc_can_create_row($kind, $d, $res)) {
        // فاکتور/سندِ کامل که هنوز ردیفی ندارد => ردیفِ تازه (صفر کیلومتر برای فاکتورِ فروش: بازدید لازم نیست)
        $isNew = $kind === 'sales_invoice' ? 1 : 0;
        $vin = vdoc_clean_code(($d['vin'] ?? '') ?: ($d['chassis_no'] ?? ''));
        $type = vdoc_request_type($pdo, intval($doc['request_id']));
        $pdo->prepare("INSERT INTO company_request_plates (request_id, chassis_no, vin, engine_no, is_new_vehicle, insurance_type, skip_health_inspection, car_name, row_note, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')")
            ->execute([$doc['request_id'], $vin ?: null, strlen($vin) === 17 ? $vin : null, vdoc_clean_code($d['engine_no'] ?? '') ?: null, $isNew, $type, $isNew,
                       mb_substr(trim(($d['car_system'] ?? '') . ' ' . ($d['car_tip'] ?? '')) ?: ($d['usage'] ?? ''), 0, 100) ?: null,
                       $type ? null : 'ساخته‌شده از روی ' . ($res['kind_fa'] ?? 'مدرک') . '؛ نوعِ بیمه را مشخص کنید']);
        $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
        $st->execute([intval($pdo->lastInsertId())]);
        $rows = [$st->fetch()];
        $created = true;
    }
    if (!$rows) return $save('PARTIAL', $res);
    if (!function_exists('company_place_document')) require_once __DIR__ . '/_company_helpers.php';
    // روی ردیفِ اول می‌نشیند؛ اگر ثالث و بدنه‌ی همین خودرو دو ردیف‌اند، برای ردیفِ دوم یک کپی
    $first = $rows[0];
    $type = vdoc_keep_type($doc['doc_type'] ?? '', $kind, vdoc_doc_type($kind, $d, $first));
    $pdo->prepare("UPDATE company_documents SET request_id = ?, plate_id = ?, doc_type = ?, status = 'ASSIGNED', assigned_at = NOW() WHERE id = ?")
        ->execute([$first['request_id'], $first['id'], $type, $docId]);
    vdoc_apply_to_row($pdo, intval($first['id']), $d, $kind);   // اول اطلاعات (پلاک) تا نامِ فایل/پوشه با پلاک ساخته شود
    $newRel = company_place_document($pdo, $siteRoot, $docId, intval($first['id']), $type);
    company_sync_plate_status($pdo, intval($first['id']));
    foreach (array_slice($rows, 1) as $r2) {
        $src = $siteRoot . '/' . ltrim((string)$newRel, '/');
        if (!is_file($src)) break;
        $dest = dirname($src) . '/' . pathinfo($src, PATHINFO_FILENAME) . '_' . $r2['id'] . '.' . pathinfo($src, PATHINFO_EXTENSION);
        if (!@copy($src, $dest)) continue;
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status, assigned_at, ai_status)
                       VALUES (?, ?, ?, ?, ?, ?, 'SUPPORTING_DOC', ?, 'ASSIGNED', NOW(), 'AUTO')")
            ->execute([$doc['company_id'], $r2['request_id'], $r2['id'], $doc['uploaded_by'], ltrim(str_replace($siteRoot, '', $dest), '/'), $doc['orig_name'], $type]);
        $d2 = intval($pdo->lastInsertId());
        company_place_document($pdo, $siteRoot, $d2, intval($r2['id']), $type);
        vdoc_apply_to_row($pdo, intval($r2['id']), $d, $kind);
        company_sync_plate_status($pdo, intval($r2['id']));
    }
    $res['plate_id'] = intval($first['id']);
    $res['row_created'] = $created;
    $out = $save($created ? 'AUTO_NEW' : 'AUTO', $res);
    if ($doc['request_id']) vdoc_rematch_partials($pdo, $siteRoot, intval($doc['request_id']));
    return $out;
}

// ---- «پردازش»ِ دستیِ مدارکِ یک ردیف (دکمه‌ی کنارِ هر مدرک، «پردازشِ مدارکِ ردیف» و «پردازشِ همه‌ی مدارکِ درخواست») ----
// متنِ فایل (متنِ PDF یا OCR) ← پرامپتِ مخصوصِ همان نوعِ مدرک ← اطلاعات روی همان ردیف (فقط خانه‌های خالی؛ چیزی که ثبت شده عوض نمی‌شود)
// کارتِ ماشین: روی و پشتِ کارتِ همان ردیف با هم خوانده می‌شوند تا همه‌ی اطلاعات (مالک، پلاک، VIN، سیستم، تیپ، رنگ، سوخت…) کامل بیاید
function vdoc_processable_types() {
    return ['car_card_front', 'car_card_back', 'new_car_card', 'car_card_or_title', 'ownership_doc', 'new_ownership_doc', 'sales_invoice',
            'prev_body_policy', 'prev_third_policy', 'policy_doc', 'national_card'];
}
// برچسبِ خانه‌هایی که با پردازش پر شدند (برای نمایش به کارشناس)
function vdoc_fill_labels() {
    return ['chassis_no' => 'شماره شاسی', 'vin' => 'VIN', 'engine_no' => 'شماره موتور', 'car_name' => 'نام خودرو', 'car_type' => 'تیپ', 'plate_p2' => 'پلاک', 'is_new_vehicle' => 'صفر کیلومتر',
            'party_mode' => 'نقشِ مالک / ذینفع', 'owner_name' => 'نام مالک', 'owner_national_id' => 'کد / شناسه‌ی ملیِ مالک', 'owner_phone' => 'تلفنِ مالک', 'owner_address' => 'نشانیِ مالک',
            'owner_postal_code' => 'کد پستیِ مالک', 'owner_father' => 'نام پدر', 'owner_birth_date' => 'تاریخ تولد / ثبت', 'car_system' => 'سیستم', 'car_kind' => 'نوع', 'car_usage' => 'کاربری',
            'car_model_year' => 'مدل', 'car_color' => 'رنگ', 'car_capacity' => 'ظرفیت', 'car_cylinders' => 'تعداد سیلندر', 'car_fuel' => 'نوع سوخت', 'car_axles' => 'تعداد محور',
            'car_wheels' => 'تعداد چرخ', 'cab_no' => 'شماره اتاق', 'card_serial' => 'شماره مسلسل کارت', 'car_country' => 'کشور سازنده', 'prev_policy_number' => 'بیمه‌نامه‌ی قبلی',
            'prev_insurer' => 'بیمه‌گرِ قبلی', 'prev_expiry' => 'انقضای بیمه‌نامه‌ی قبلی', 'no_claim_years' => 'سال‌های عدم خسارت', 'prev_no_claim_percent' => 'تخفیف عدم خسارت',
            'prev_car_value' => 'مبلغ بیمه‌شده‌ی قبلی', 'prev_covers' => 'پوشش‌های قبلی', 'prev_unique_code' => 'کد یکتای بیمه‌نامه‌ی قبلی'];
}
function vdoc_row_snapshot($pdo, $plateId) {
    $s = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
    $s->execute([$plateId]);
    $r = $s->fetch() ?: [];
    $ii = issue_info_decode($r['issue_info'] ?? null);
    unset($r['issue_info']);
    return $ii + $r;
}
// متنِ صفحه‌ی اولِ یک مدرک (اگر قبلاً پردازش شده، همان متنِ ذخیره‌شده؛ دوباره OCR نمی‌شود)
function vdoc_doc_text($pdo, $siteRoot, array $doc, $reuse = true) {
    $aj = json_decode((string)($doc['ai_json'] ?? ''), true) ?: [];
    if ($reuse && !empty($aj['ocr_text'])) return ['ok' => true, 'text' => (string)$aj['ocr_text'], 'engine' => (string)($aj['ocr_engine'] ?? 'cache')];
    $abs = $siteRoot . '/' . ltrim((string)$doc['file_path'], '/');
    if (!is_file($abs)) return ['ok' => false, 'error' => 'فایلِ مدرک روی هاست پیدا نشد.'];
    return ds_page_text($pdo, $abs, 1);
}
// خروجی: ok, status (AUTO/PARTIAL/NONE/FAIL), kind_fa, fields (خوانده‌شده)، filled (خانه‌هایی که روی ردیف پر شد)، pair (شناسه‌ی روی/پشتِ دیگرِ کارت)
function vdoc_process_row_doc($pdo, $siteRoot, $docId, $force = false) {
    vdoc_ensure($pdo);
    @set_time_limit(300);
    require_once __DIR__ . '/_doc_smart.php';
    $st = $pdo->prepare("SELECT * FROM company_documents WHERE id = ?");
    $st->execute([$docId]);
    $doc = $st->fetch();
    if (!$doc || !$doc['plate_id'] || $doc['status'] !== 'ASSIGNED') return ['ok' => false, 'error' => 'مدرک روی ردیفی ننشسته است (از «بررسی مدارک» تخصیصش دهید).'];
    if (!in_array($doc['doc_type'], vdoc_processable_types(), true)) return ['ok' => false, 'error' => 'این نوع مدرک اطلاعاتی برای خواندن روی ردیف ندارد.'];
    $abs = $siteRoot . '/' . ltrim((string)$doc['file_path'], '/');
    if (!is_file($abs)) return ['ok' => false, 'error' => 'فایلِ مدرک روی هاست پیدا نشد.'];
    $plateId = intval($doc['plate_id']);
    $type = (string)$doc['doc_type'];
    // روی/پشتِ دیگرِ کارتِ همین ردیف (تازه‌ترین)
    $pair = null;
    if (in_array($type, ['car_card_front', 'car_card_back'], true)) {
        $q = $pdo->prepare("SELECT * FROM company_documents WHERE plate_id = ? AND status = 'ASSIGNED' AND doc_type = ? ORDER BY id DESC LIMIT 1");
        $q->execute([$plateId, $type === 'car_card_front' ? 'car_card_back' : 'car_card_front']);
        $pair = $q->fetch() ?: null;
        if ($pair && !is_file($siteRoot . '/' . ltrim((string)$pair['file_path'], '/'))) $pair = null;
    }
    $res = null; $fields = []; $err = '';
    if (ds_smart_available($pdo)) {
        $t = vdoc_doc_text($pdo, $siteRoot, $doc, false);
        if (!empty($t['ok'])) {
            $text = (string)$t['text'];
            if ($pair) {
                $t2 = vdoc_doc_text($pdo, $siteRoot, $pair);
                if (!empty($t2['ok'])) {
                    $front = $type === 'car_card_front' ? $text : (string)$t2['text'];
                    $back = $type === 'car_card_front' ? (string)$t2['text'] : $text;
                    $text = "=== روی کارت ===\n" . trim($front) . "\n\n=== پشت کارت ===\n" . trim($back);
                }
            }
            $x = ds_extract($pdo, ds_type_of_doc($type) ?: 'auto', ds_clean_text($text), intval($doc['company_id']));
            if (!empty($x['ok']) && !empty($x['result'])) {
                $res = $x['result'];
                $fields = (array)($x['fields'] ?? []);
                $res['ocr_text'] = mb_substr((string)$t['text'], 0, 8000);
                $res['ocr_engine'] = (string)($t['engine'] ?? '');
            } else $err = $x['error'] ?? 'این فایل مدرکِ خودرو شناخته نشد.';
        } else $err = $t['error'] ?? 'متنی از فایل خوانده نشد.';
    }
    // بدونِ پردازشِ هوشمند (یا ناموفق): خواننده‌ی سایت برای PDFِ متنی، و برای اسکن/عکس سرویسِ «خواندنِ اسکن (هوش مصنوعی)»
    if (!$res) {
        $r = vdoc_run($pdo, $abs, 1, 280, []);
        if (!empty($r['ok'])) $res = $r;
        else return ['ok' => false, 'error' => $err ?: ($r['error'] ?? 'خوانده نشد.'), 'status' => 'FAIL'];
    }
    $d = (array)($res['data'] ?? []);
    $kind = (string)($res['kind'] ?? '');
    if ($type === 'policy_doc') {
        // بیمه‌نامه‌ی فعلیِ الحاقیه/فسخ: «بیمه‌نامه‌ی قبلی» نیست؛ فقط مشخصاتِ خودرو
        $d = array_diff_key($d, array_flip(['policy_number', 'insurer', 'end_date', 'no_claim_years', 'no_claim_percent', 'car_value', 'covers', 'unique_code', 'owner_name', 'national_id', 'mobile', 'phone', 'address', 'postal_code']));
    }
    $save = $pdo->prepare("UPDATE company_documents SET ai_status = ?, ai_json = ?, ai_at = NOW() WHERE id = ?");
    $res['plate_id'] = $plateId; $res['by'] = 'row_process';
    // مدرکِ خودروی دیگر (شاسی/موتور/پلاکش با ردیف نمی‌خواند و هیچ‌کدام یکی نیست): هیچ چیز روی ردیف نمی‌نشیند تا کارشناس تصمیم بگیرد
    if (!$force && $d && in_array($kind, ['car_card', 'ownership_doc', 'sales_invoice', 'policy'], true)) {
        $rowNow = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?"); $rowNow->execute([$plateId]);
        $vc = vdoc_vehicle_check($rowNow->fetch() ?: [], $d);
        if ($vc['conflict'] && !$vc['match']) {
            $save->execute(['MISMATCH', json_encode($res + ['mismatch' => $vc['conflict']], JSON_UNESCAPED_UNICODE), $docId]);
            if ($pair) $save->execute(['MISMATCH', json_encode(['ok' => true, 'kind' => $kind, 'data' => $d, 'mismatch' => $vc['conflict'], 'by' => 'row_process'], JSON_UNESCAPED_UNICODE), intval($pair['id'])]);   // روی و پشت با هم خوانده شدند
            if (!$fields) foreach ($d as $k => $v) if (is_scalar($v) && trim((string)$v) !== '') $fields[$k] = (string)$v;
            return ['ok' => true, 'status' => 'MISMATCH', 'doc_id' => intval($docId), 'kind_fa' => (string)($res['kind_fa'] ?? ''), 'mismatch' => $vc['conflict'], 'fields' => $fields,
                    'filled' => [], 'conflicts' => [], 'pair' => $pair ? intval($pair['id']) : 0, 'plate_id' => $plateId];
        }
    }
    $before = vdoc_row_snapshot($pdo, $plateId);
    if ($d) vdoc_apply_to_row($pdo, $plateId, $d, $kind);
    $conflicts = $d ? vdoc_conflicts($pdo, $plateId, $d, $kind) : [];
    $after = vdoc_row_snapshot($pdo, $plateId);
    $lab = vdoc_fill_labels(); $filled = [];
    foreach ($after as $k => $v) {
        if (!isset($lab[$k]) || !is_scalar($v) || trim((string)$v) === '' || (string)$v === '0') continue;
        if (trim((string)($before[$k] ?? '')) !== '' && (string)($before[$k] ?? '') !== '0') continue;
        $filled[$lab[$k]] = $k === 'is_new_vehicle' ? 'بله' : ($k === 'party_mode' && defined('PARTY_MODES_SHORT') ? (PARTY_MODES_SHORT[$v] ?? (string)$v) : (string)$v);
    }
    if (isset($filled[$lab['plate_p2']]) && function_exists('company_row_label')) $filled[$lab['plate_p2']] = company_row_label($after);
    if (!$fields) foreach ($d as $k => $v) if (is_scalar($v) && trim((string)$v) !== '') $fields[$k] = (string)$v;
    $status = empty($d) ? 'NONE' : (($res['status'] ?? '') === 'full' ? 'AUTO' : 'PARTIAL');
    $save->execute([$status, json_encode($res, JSON_UNESCAPED_UNICODE), $docId]);
    if ($pair) {
        // کارتِ دیگر هم همان اطلاعاتِ ترکیبی را دارد (پنلِ صدور و پرونده‌ی خودرو از مدارکِ ردیف می‌خوانند)؛ متنِ خودش می‌ماند
        $paj = json_decode((string)($pair['ai_json'] ?? ''), true) ?: [];
        $res2 = $res; unset($res2['ocr_text'], $res2['ocr_engine']);
        if (!empty($paj['ocr_text'])) { $res2['ocr_text'] = $paj['ocr_text']; $res2['ocr_engine'] = $paj['ocr_engine'] ?? ''; }
        $save->execute([$status, json_encode($res2, JSON_UNESCAPED_UNICODE), intval($pair['id'])]);
    }
    if (!function_exists('company_sync_plate_status')) require_once __DIR__ . '/_company_helpers.php';
    $rowStatus = company_sync_plate_status($pdo, $plateId);
    return ['ok' => true, 'status' => $status, 'doc_id' => intval($docId), 'kind_fa' => (string)($res['kind_fa'] ?? ''), 'fields' => $fields, 'filled' => $filled, 'conflicts' => $conflicts,
            'pair' => $pair ? intval($pair['id']) : 0, 'plate_id' => $plateId, 'row_status' => $rowStatus];
}
// ---- مقایسه‌ی مدرک با ردیف: «مدرکِ خودروی دیگر» و «خانه‌ی پرشده‌ای که با مدرک نمی‌خواند» ----
function vdoc_cmp_norm($v) {
    $v = p2e_digits(str_replace(['ي', 'ك', "\u{200C}", 'آ'], ['ی', 'ک', ' ', 'ا'], (string)$v));
    return mb_strtolower(preg_replace('/[\s\-_\/.,،*()«»"\']+/u', '', $v));
}
// دو شماره شاسی/موتور یکی‌اند؟ (با چشم‌پوشی از خطای کوچکِ OCR: هشت رقمِ آخرِ یکسان یا حداکثر دو نویسه اختلاف) — null = یکی خالی است
function vdoc_code_like($a, $b) {
    $a = vdoc_clean_code($a); $b = vdoc_clean_code($b);
    if ($a === '' || $b === '') return null;
    if ($a === $b) return true;
    $n = min(strlen($a), strlen($b));
    if ($n >= 8 && substr($a, -8) === substr($b, -8)) return true;
    return $n >= 10 && levenshtein($a, $b) <= 2;
}
// مدرک مالِ همین خودرو است؟ => ['match' => [...], 'conflict' => [[label, row, doc]]]؛ مدرکِ خودروی دیگر = conflict دارد و هیچ match ندارد
function vdoc_vehicle_check(array $r, array $d) {
    $out = ['match' => [], 'conflict' => []];
    $docV = array_values(array_unique(array_filter([vdoc_clean_code($d['vin'] ?? ''), vdoc_clean_code($d['chassis_no'] ?? '')])));
    $rowV = array_values(array_unique(array_filter([vdoc_clean_code($r['chassis_no'] ?? ''), vdoc_clean_code($r['vin'] ?? '')])));
    if ($docV && $rowV) {
        $m = false; foreach ($docV as $x) foreach ($rowV as $y) if (vdoc_code_like($x, $y)) $m = true;
        $out[$m ? 'match' : 'conflict'][] = ['label' => 'شماره شاسی / VIN', 'row' => $rowV[0], 'doc' => $docV[0]];
    }
    $m = vdoc_code_like($d['engine_no'] ?? '', $r['engine_no'] ?? '');
    if ($m !== null) $out[$m ? 'match' : 'conflict'][] = ['label' => 'شماره موتور', 'row' => vdoc_clean_code($r['engine_no']), 'doc' => vdoc_clean_code($d['engine_no'])];
    $pp = $d['plate_parts'] ?? null;
    if (is_array($pp) && preg_match('/^\d{3}$/', (string)($pp['three'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['iran'] ?? '')) && trim((string)($r['plate_p2'] ?? '')) !== '' && trim((string)($r['plate_p1'] ?? '')) !== '') {
        $same = p2e_digits((string)$r['plate_p2']) === $pp['three'] && p2e_digits((string)$r['plate_p1']) === $pp['iran'] && (($pp['two'] ?? '') === '' || p2e_digits((string)$r['plate_p4']) === $pp['two']);
        $fmt = function ($two, $l, $three, $iran) { return trim("$two $l $three - ایران $iran"); };
        $out[$same ? 'match' : 'conflict'][] = ['label' => 'پلاک', 'row' => $fmt(p2e_digits((string)$r['plate_p4']), $r['plate_letter'], p2e_digits((string)$r['plate_p2']), p2e_digits((string)$r['plate_p1'])),
                                                'doc' => $fmt($pp['two'] ?? '', $pp['letter'] ?? '', $pp['three'], $pp['iran'])];
    }
    return $out;
}
// دو مقدارِ یک خانه عملاً یکی‌اند؟ («۷ نفر» = 7، «شرکت الف» ⊃ «الف»)
function vdoc_same_value($a, $b) {
    $x = vdoc_cmp_norm($a); $y = vdoc_cmp_norm($b);
    if ($x === $y) return true;
    $dx = preg_replace('/\D/', '', $x); $dy = preg_replace('/\D/', '', $y);
    if ($dx !== '' && $dx === $dy && (preg_match('/^\d+$/', $x) || preg_match('/^\d+$/', $y))) return true;
    $s = mb_strlen($x) <= mb_strlen($y) ? $x : $y; $l = $s === $x ? $y : $x;
    return mb_strlen($s) >= 4 && mb_strpos($l, $s) !== false;
}
// خانه‌هایی که از قبل پر بوده‌اند و با مدرک فرق دارند: [[field, label, old, new]]
function vdoc_conflicts($pdo, $plateId, array $d, $kind) {
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
    $st->execute([$plateId]);
    $r = $st->fetch();
    if (!$r) return [];
    $info = issue_info_decode($r['issue_info'] ?? null);
    $lab = vdoc_fill_labels(); $out = [];
    if ($kind !== 'national_card') {
        $dc = vdoc_clean_code(($d['chassis_no'] ?? '') ?: ($d['vin'] ?? ''));
        $rc = vdoc_clean_code(($r['chassis_no'] ?? '') ?: ($r['vin'] ?? ''));
        if ($dc !== '' && $rc !== '' && $dc !== $rc) $out[] = ['field' => '__chassis', 'label' => 'شماره شاسی / VIN', 'old' => $rc, 'new' => $dc];
        $de = vdoc_clean_code($d['engine_no'] ?? ''); $re = vdoc_clean_code($r['engine_no'] ?? '');
        if ($de !== '' && $re !== '' && $de !== $re) $out[] = ['field' => '__engine', 'label' => 'شماره موتور', 'old' => $re, 'new' => $de];
        $vc = vdoc_vehicle_check($r, ['plate_parts' => $d['plate_parts'] ?? null]);
        foreach ($vc['conflict'] as $c) if ($c['label'] === 'پلاک' && !empty(($d['plate_parts'] ?? [])['letter']) && preg_match('/^\d{2}$/', (string)($d['plate_parts']['two'] ?? '')))
            $out[] = ['field' => '__plate', 'label' => 'پلاک', 'old' => $c['row'], 'new' => $c['doc']];
    }
    foreach (vdoc_field_map($kind, $d) as $from => $to) {
        if (in_array($to, ['engine_no', 'vin', 'chassis_no'], true)) continue;
        $new = trim((string)($d[$from] ?? '')); $old = trim((string)($info[$to] ?? ''));
        if ($new === '' || $old === '' || vdoc_same_value($old, $new)) continue;
        $out[] = ['field' => $to, 'label' => $lab[$to] ?? $to, 'old' => $old, 'new' => mb_substr($new, 0, 250)];
    }
    return $out;
}
// جایگزینیِ خانه‌های انتخاب‌شده با مقدارِ مدرک (فقط همان‌هایی که الان هم اختلاف دارند)
function vdoc_replace_fields($pdo, $plateId, array $d, $kind, array $fields) {
    $want = array_flip(array_map('strval', $fields)); $done = [];
    $st = $pdo->prepare("SELECT issue_info FROM company_request_plates WHERE id = ?"); $st->execute([$plateId]);
    $info = issue_info_decode($st->fetchColumn()); $infoCh = false;
    foreach (vdoc_conflicts($pdo, $plateId, $d, $kind) as $c) {
        if (!isset($want[$c['field']])) continue;
        if ($c['field'] === '__chassis') {
            $pdo->prepare("UPDATE company_request_plates SET chassis_no = ?, vin = ? WHERE id = ?")->execute([$c['new'], strlen($c['new']) === 17 ? $c['new'] : null, $plateId]);
            foreach (['chassis_no', 'vin'] as $k) if (isset($info[$k])) { $info[$k] = $c['new']; $infoCh = true; }
        } elseif ($c['field'] === '__engine') {
            $pdo->prepare("UPDATE company_request_plates SET engine_no = ? WHERE id = ?")->execute([$c['new'], $plateId]);
            if (isset($info['engine_no'])) { $info['engine_no'] = $c['new']; $infoCh = true; }
        } elseif ($c['field'] === '__plate') {
            $pp = $d['plate_parts'];
            $pdo->prepare("UPDATE company_request_plates SET plate_p4 = ?, plate_letter = ?, plate_p2 = ?, plate_p1 = ? WHERE id = ?")->execute([$pp['two'], $pp['letter'], $pp['three'], $pp['iran'], $plateId]);
        } else { $info[$c['field']] = $c['new']; $infoCh = true; }
        $done[$c['label']] = $c['new'];
    }
    if ($infoCh) $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), $plateId]);
    return $done;
}
// داده‌ی ذخیره‌شده‌ی یک مدرکِ پردازش‌شده (همان برش‌هایی که هنگامِ پردازش اعمال شد)
function vdoc_doc_saved_data(array $doc) {
    $res = json_decode((string)($doc['ai_json'] ?? ''), true) ?: [];
    $d = (array)($res['data'] ?? []);
    if (($doc['doc_type'] ?? '') === 'policy_doc')
        $d = array_diff_key($d, array_flip(['policy_number', 'insurer', 'end_date', 'no_claim_years', 'no_claim_percent', 'car_value', 'covers', 'unique_code', 'owner_name', 'national_id', 'mobile', 'phone', 'address', 'postal_code']));
    return [$d, (string)($res['kind'] ?? '')];
}

// مدارکِ قابلِ پردازشِ یک درخواست یا یک ردیف (روی/پشتِ کارتِ یک ردیف یک بار، چون با هم خوانده می‌شوند)
function vdoc_row_docs_to_process($pdo, $requestId, $plateId = 0) {
    $in = implode(',', array_fill(0, count(vdoc_processable_types()), '?'));
    $sql = "SELECT cd.id, cd.plate_id, cd.doc_type FROM company_documents cd JOIN company_request_plates crp ON crp.id = cd.plate_id
             WHERE cd.status = 'ASSIGNED' AND cd.doc_type IN ($in) AND " . ($plateId ? "cd.plate_id = ?" : "cd.request_id = ?") . " ORDER BY cd.plate_id, cd.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge(vdoc_processable_types(), [$plateId ?: $requestId]));
    $out = []; $cardSeen = [];
    foreach ($st->fetchAll() as $r) {
        if (in_array($r['doc_type'], ['car_card_front', 'car_card_back'], true)) {
            if (isset($cardSeen[$r['plate_id']])) continue;
            $cardSeen[$r['plate_id']] = 1;
        }
        $out[] = ['id' => intval($r['id']), 'plate_id' => intval($r['plate_id']), 'doc_type' => $r['doc_type']];
    }
    return $out;
}

// صف را جلو می‌برد: حداکثر $max مدرک (پیش‌فرض یکی، چون هر صفحه با OCR چند ثانیه طول می‌کشد)
function vdoc_step($pdo, $siteRoot, $companyIds = null, $max = 1) {
    vdoc_ensure($pdo);
    // «در حال خواندن»ِ ماندگار (قطعِ درخواست) دوباره به صف برمی‌گردد
    try { $pdo->exec("UPDATE company_documents SET ai_status = 'PENDING' WHERE ai_status = 'RUNNING' AND ai_at < (NOW() - INTERVAL 10 MINUTE)"); } catch (Throwable $e) {}
    $w = "ai_status = 'PENDING' AND (status = 'UNASSIGNED' OR (status = 'ASSIGNED' AND plate_id IS NOT NULL))"; $a = [];
    if (is_array($companyIds)) { if (!$companyIds) return ['done' => [], 'pending' => 0]; $w .= " AND company_id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")"; $a = array_values(array_map('intval', $companyIds)); }
    $done = [];
    for ($i = 0; $i < $max; $i++) {
        $st = $pdo->prepare("SELECT id FROM company_documents WHERE $w ORDER BY id LIMIT 1");
        $st->execute($a);
        $id = $st->fetchColumn();
        if (!$id) break;
        $r = vdoc_process_company_doc($pdo, $siteRoot, intval($id));
        if (!empty($r['skipped'])) continue;
        $done[] = ['doc_id' => intval($id), 'status' => $r['status'] ?? 'FAIL', 'plate_id' => $r['result']['plate_id'] ?? null, 'kind_fa' => $r['result']['kind_fa'] ?? '', 'vin' => $r['result']['data']['vin'] ?? '',
                   'owner' => $r['result']['data']['owner_name'] ?? '', 'missing' => $r['result']['missing'] ?? []];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM company_documents WHERE ai_status IN ('PENDING', 'RUNNING') AND (status = 'UNASSIGNED' OR (status = 'ASSIGNED' AND plate_id IS NOT NULL))" . (is_array($companyIds) ? " AND company_id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")" : ''));
    $st->execute($a);
    return ['done' => $done, 'pending' => intval($st->fetchColumn())];
}

// مدرکِ تازه‌ی شرکت را در صفِ شناسایی می‌گذارد (فقط مدارکِ پشتیبان؛ نامه نه)
//   شرکتی که در تعریفش «خواندنِ خودکارِ مدارک» خاموش است: مدارکش فقط بارگذاری می‌شود (مگر $force = درخواستِ دستیِ کارشناس)
//   $smart: خواندن با «پردازشِ هوشمند» (OCR + تشخیصِ نوع + پرامپتِ همان نوع) — ارسالِ گروهی با «بررسیِ خودکار» و بررسی‌های دستیِ کارشناس
function vdoc_queue($pdo, array $docIds, $force = false, $smart = false) {
    vdoc_ensure($pdo);
    $docIds = array_values(array_filter(array_map('intval', $docIds)));
    if (!$docIds) return 0;
    if (!$force) {
        try {
            $off = $pdo->query("SELECT d.id FROM company_documents d JOIN companies c ON c.id = d.company_id WHERE c.doc_read = 0 AND d.id IN (" . implode(',', $docIds) . ")")->fetchAll(PDO::FETCH_COLUMN);
            if ($off) $docIds = array_values(array_diff($docIds, array_map('intval', $off)));
        } catch (Throwable $e) {}   // ستون هنوز ساخته نشده => همه خوانده می‌شوند (مثلِ قبل)
        if (!$docIds) return 0;
    }
    $vt = vdoc_vehicle_types();
    $pdo->prepare("UPDATE company_documents SET ai_status = 'PENDING', ai_at = NOW()" . ($smart ? ", ai_json = '{\"want_smart\":1}'" : "") . " WHERE id IN (" . implode(',', array_fill(0, count($docIds), '?')) . ") AND file_kind = 'SUPPORTING_DOC'
                     AND (status = 'UNASSIGNED' OR (status = 'ASSIGNED' AND plate_id IS NOT NULL AND doc_type IN (" . implode(',', array_fill(0, count($vt), '?')) . ")))")
        ->execute(array_merge($docIds, $vt));
    return count($docIds);
}

// =====================================================================
//  ثبتِ دستیِ درخواستِ شرکتی از پنل: فاکتور/سند/کارت (یک یا چند فایل، هر فایل چند صفحه) صفحه‌به‌صفحه خوانده می‌شود؛
//  پنل از روی نتیجه برای هر خودرو یک ردیف می‌سازد و بعد از ثبت، هر صفحه روی ردیفِ خودش بایگانی و اطلاعاتش روی ردیف می‌نشیند.
//  کارِ جداسازی با مالکِ 'md'+شناسه‌ی کاربر نگه داشته می‌شود (api/_pdf_split.php)
// =====================================================================
function vdoc_job_read($pdo, $siteRoot, $token, $owner, $n) {
    if (!function_exists('pdf_split_job_page_path')) require_once __DIR__ . '/_pdf_split.php';
    $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
    if (!$p) return ['ok' => false, 'error' => 'صفحه پیدا نشد (شاید زمانش گذشته؛ دوباره بارگذاری کنید).'];
    $cache = dirname($p) . sprintf('/r%03d.json', $n);
    if (is_file($cache)) { $j = json_decode((string)file_get_contents($cache), true); if (is_array($j)) return $j; }
    $r = vdoc_run($pdo, $p);
    if (!empty($r['ok'])) @file_put_contents($cache, json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r;
}

// چند صفحه از یک فایلِ بارگذاری‌شده با هم (نتیجه‌ی قبلی از کش) => [n => نتیجه]
function vdoc_job_read_many($pdo, $siteRoot, $token, $owner, array $ns) {
    if (!function_exists('pdf_split_job_page_path')) require_once __DIR__ . '/_pdf_split.php';
    $out = []; $todo = [];
    foreach (array_slice(array_unique(array_map('intval', $ns)), 0, 6) as $n) {
        $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
        if (!$p) { $out[$n] = ['ok' => false, 'error' => 'صفحه پیدا نشد (شاید زمانش گذشته؛ دوباره بارگذاری کنید).']; continue; }
        $cache = dirname($p) . sprintf('/r%03d.json', $n);
        if (is_file($cache) && is_array($j = json_decode((string)file_get_contents($cache), true))) { $out[$n] = $j; continue; }
        $todo[$n] = $p;
    }
    if ($todo) {
        foreach (vdoc_run_many($pdo, $todo) as $n => $r) {
            $out[$n] = $r;
            if (!empty($r['ok'])) @file_put_contents(dirname($todo[$n]) . sprintf('/r%03d.json', $n), json_encode($r, JSON_UNESCAPED_UNICODE));
        }
    }
    ksort($out);
    return $out;
}

// صفحه‌ی n را روی ردیف بایگانی می‌کند (نوعِ مدرک از نتیجه‌ی شناسایی یا $docType) و اطلاعاتش را روی ردیف می‌نشاند
function vdoc_job_attach($pdo, $siteRoot, $token, $owner, $n, $plateId, $userId, $docType = '') {
    if (!function_exists('pdf_split_job_page_path')) require_once __DIR__ . '/_pdf_split.php';
    if (!function_exists('company_place_document')) require_once __DIR__ . '/_company_helpers.php';
    $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
    if (!$p) return ['ok' => false, 'error' => 'صفحه پیدا نشد.'];
    $st = $pdo->prepare("SELECT crp.*, cr.company_id, c.name AS company_name FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
    $st->execute([intval($plateId)]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    $res = json_decode((string)@file_get_contents(dirname($p) . sprintf('/r%03d.json', $n)), true) ?: [];
    $d = (array)($res['data'] ?? []);
    $type = $docType !== '' && array_key_exists($docType, company_doc_types()) ? $docType : (vdoc_doc_type($res['kind'] ?? '', $d, $row) ?: 'other');
    $tmpDir = company_unassigned_temp_path($siteRoot, $row['company_name'] ?: 'نامشخص');
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $label = company_doc_type_label($type);
    $dest = unique_dest_path($tmpDir . '/' . pdf_split_safe_name($label . ' - صفحه ' . intval($n)) . '.pdf');
    if (!@copy($p, $dest)) return ['ok' => false, 'error' => 'کپیِ صفحه ممکن نشد.'];
    vdoc_ensure($pdo);
    $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status, assigned_by, assigned_at, ai_status, ai_json)
                   VALUES (?, ?, ?, NULL, ?, ?, 'SUPPORTING_DOC', ?, 'ASSIGNED', ?, NOW(), ?, ?)")
        ->execute([$row['company_id'], $row['request_id'], $row['id'], ltrim(str_replace($siteRoot, '', $dest), '/'), $label . ' - صفحه ' . intval($n) . '.pdf', $type, $userId,
                   $res ? 'AUTO' : null, $res ? json_encode($res, JSON_UNESCAPED_UNICODE) : null]);
    $docId = intval($pdo->lastInsertId());
    company_place_document($pdo, $siteRoot, $docId, intval($row['id']), $type);
    if ($d) vdoc_apply_to_row($pdo, intval($row['id']), $d, $res['kind'] ?? '');
    company_sync_plate_status($pdo, intval($row['id']));
    return ['ok' => true, 'doc_id' => $docId, 'doc_type' => $type];
}
