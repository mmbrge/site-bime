<?php
// فایل: api/_health_admin.php
// بازدید سلامت: «بازدید توسط کاربر» روشن/خاموش، نوبت‌دهیِ خودکار، و مینی‌اپِ مدیر در ربات بله.
//  - وقتی بازدید توسط کاربر خاموش است، کاربر در ربات/مینی‌اپ نمی‌تواند بازدید بفرستد و به‌جایش نوبتِ بازدید
//    (طبقِ برنامه‌ی تنظیمات: روزها، ساعت‌ها، طولِ هر نوبت، ظرفیت) خودکار می‌گیرد.
//  - مدیر با دستورِ مخفیِ ربات (فقط خودش می‌داند) و نام کاربری/رمزِ سایت وارد مینی‌اپِ مدیر می‌شود؛ بازدیدی که مدیر
//    ثبت می‌کند تایید لازم ندارد و مستقیم «آماده‌ی صدورِ گزارشِ بازدید» می‌شود (حتی وقتی بازدیدِ کاربر روشن است).
// همه‌ی جدول/ستون‌ها خودکار ساخته می‌شوند (افزودنی؛ به داده‌های قبلی دست نمی‌زند).

const HADM_DEFAULTS = [
    'hadm_self_enabled' => '1',          // بازدید توسط کاربران
    'hadm_days' => '0,1,2,3,4',          // روزهای نوبت‌دهی (۰ = شنبه ... ۶ = جمعه)
    'hadm_start' => '09:00', 'hadm_end' => '14:00',
    'hadm_slot' => '30',                 // دقیقه
    'hadm_cap' => '1',                   // چند خودرو در هر نوبت
    'hadm_lead' => '1',                  // اولین نوبت چند روز بعد
    'hadm_place' => '',                  // نشانی/توضیحِ محلِ بازدید
    'hadm_bot_cmd' => '',                // دستورِ مخفیِ ورودِ مدیر در ربات (خالی = غیرفعال)
];
const HADM_WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

function hadm_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $opt = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS health_appointments (id INT AUTO_INCREMENT PRIMARY KEY, case_id BIGINT NOT NULL, slot_at DATETIME NOT NULL,
            notified TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_case (case_id), KEY k_slot (slot_at)) $opt",
        "CREATE TABLE IF NOT EXISTS admin_bot_logins (chat_id BIGINT PRIMARY KEY, state VARCHAR(16) NOT NULL, username VARCHAR(120) NULL,
            tries INT NOT NULL DEFAULT 0, locked_until DATETIME NULL, updated_at DATETIME NOT NULL) $opt",
        "CREATE TABLE IF NOT EXISTS admin_app_tokens (token VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, chat_id BIGINT NULL,
            expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) $opt",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[hadm_ensure] ' . $e->getMessage()); } }
    foreach ([['webapp_sessions', 'by_staff'], ['health_inspections', 'by_staff']] as [$t, $c]) {
        try { if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` INT NULL"); }
        catch (Throwable $e) { error_log('[hadm_ensure col] ' . $e->getMessage()); }
    }
}

function hadm_settings($pdo) {
    $s = HADM_DEFAULTS;
    try {
        $in = implode(',', array_fill(0, count($s), '?'));
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(array_keys($s));
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $s[$k] = (string)$v;
    } catch (Throwable $e) {}
    return $s;
}
function hadm_save_settings($pdo, array $in) {
    $cur = hadm_settings($pdo);
    $hm = function ($v, $d) { $v = p2e_digits(trim((string)$v)); return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : $d; };
    $out = [
        'hadm_self_enabled' => !empty($in['self_enabled']) ? '1' : '0',
        'hadm_days' => implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($in['days'] ?? [])), function ($d) { return $d >= 0 && $d <= 6; })))),
        'hadm_start' => $hm($in['start'] ?? '', $cur['hadm_start']), 'hadm_end' => $hm($in['end'] ?? '', $cur['hadm_end']),
        'hadm_slot' => (string)max(5, min(240, intval(p2e_digits((string)($in['slot'] ?? $cur['hadm_slot']))))),
        'hadm_cap' => (string)max(1, min(50, intval(p2e_digits((string)($in['cap'] ?? $cur['hadm_cap']))))),
        'hadm_lead' => (string)max(0, min(30, intval(p2e_digits((string)($in['lead'] ?? $cur['hadm_lead']))))),
        'hadm_place' => mb_substr(trim((string)($in['place'] ?? $cur['hadm_place'])), 0, 300),
    ];
    if (array_key_exists('bot_cmd', $in)) {
        $cmd = trim((string)$in['bot_cmd']);
        if ($cmd !== '' && !preg_match('#^/?[A-Za-z0-9_]{6,40}$#', $cmd)) return ['ok' => false, 'error' => 'دستورِ مخفی فقط حروف و رقمِ انگلیسی (۶ تا ۴۰ نویسه) باشد؛ مثلاً /kz_admin_7391'];
        if ($cmd !== '' && $cmd[0] !== '/') $cmd = '/' . $cmd;
        if (in_array(strtolower($cmd), ['/start', '/logout', '/switch', '/done', '/help'], true)) return ['ok' => false, 'error' => 'این دستور مالِ ربات است؛ دستورِ دیگری انتخاب کنید.'];
        $out['hadm_bot_cmd'] = $cmd;
    }
    if ($out['hadm_end'] <= $out['hadm_start']) return ['ok' => false, 'error' => 'ساعتِ پایان باید بعد از ساعتِ شروع باشد.'];
    $st = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($out as $k => $v) $st->execute([$k, $v]);
    return ['ok' => true];
}
function hadm_self_enabled($pdo) { return hadm_settings($pdo)['hadm_self_enabled'] !== '0'; }

// ---------------- نوبت‌دهی ----------------
// روزِ هفته‌ی شمسی: ۰ = شنبه
function hadm_weekday($ts) { return ((int)date('w', $ts) + 1) % 7; }

function hadm_appt_get($pdo, $caseId) {
    hadm_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM health_appointments WHERE case_id = ?");
    $st->execute([intval($caseId)]);
    return $st->fetch() ?: null;
}

// نوبتِ این درخواست (اگر ندارد، اولین نوبتِ آزاد طبقِ برنامه ساخته می‌شود)؛ null یعنی برنامه نوبتی ندارد
function hadm_appt_assign($pdo, $caseId) {
    $cur = hadm_appt_get($pdo, $caseId);
    if ($cur && strtotime($cur['slot_at']) >= strtotime('today')) return $cur;
    $s = hadm_settings($pdo);
    $days = array_map('intval', array_filter(explode(',', $s['hadm_days']), 'strlen'));
    if (!$days) return $cur;
    $slot = max(5, intval($s['hadm_slot'])) * 60; $cap = max(1, intval($s['hadm_cap']));
    [$sh, $sm] = array_map('intval', explode(':', $s['hadm_start'])); [$eh, $em] = array_map('intval', explode(':', $s['hadm_end']));
    $q = $pdo->prepare("SELECT COUNT(*) FROM health_appointments WHERE slot_at = ?");
    $holidays = [];
    try { $hs = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'work_holidays'")->fetchColumn(); foreach (preg_split('/[\s,،]+/u', (string)$hs) as $h) if ($h !== '') $holidays[p2e_digits($h)] = true; } catch (Throwable $e) {}
    $day0 = strtotime('today') + max(0, intval($s['hadm_lead'])) * 86400;
    for ($d = 0; $d < 90; $d++) {
        $dayTs = $day0 + $d * 86400 + 3600 * 12;   // ظهرِ آن روز (برای تغییرِ ساعتِ تابستانی)
        $dayTs = strtotime(date('Y-m-d', $dayTs));
        if (!in_array(hadm_weekday($dayTs), $days, true)) continue;
        [$jy, $jm, $jd] = jalali_from_gregorian_ts($dayTs + 43200);
        if (isset($holidays[sprintf('%04d/%02d/%02d', $jy, $jm, $jd)])) continue;
        for ($t = $dayTs + $sh * 3600 + $sm * 60; $t + $slot <= $dayTs + $eh * 3600 + $em * 60; $t += $slot) {
            if ($t <= time()) continue;
            $at = date('Y-m-d H:i:s', $t);
            $q->execute([$at]);
            if (intval($q->fetchColumn()) >= $cap) continue;
            $pdo->prepare("INSERT INTO health_appointments (case_id, slot_at, notified) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE slot_at = VALUES(slot_at), notified = 0")
                ->execute([intval($caseId), $at]);
            return hadm_appt_get($pdo, $caseId);
        }
    }
    return $cur;
}

function hadm_appt_fa($pdo, $appt) {
    if (!$appt) return '';
    $ts = strtotime($appt['slot_at']);
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($ts);
    $place = trim(hadm_settings($pdo)['hadm_place']);
    return fa_digits(HADM_WEEKDAYS[hadm_weekday($ts)] . ' ' . sprintf('%04d/%02d/%02d', $jy, $jm, $jd) . ' ساعت ' . date('H:i', $ts)) . ($place !== '' ? ' - ' . $place : '');
}

// حالتِ نوبتی (بازدید توسط کاربر خاموش) فقط «بازدیدِ سلامتِ خودرو» را حضوری می‌کند؛ مدارک را خودِ کاربر
// مثلِ قبل (عکس/فایل) بارگذاری می‌کند. خروجی: null یعنی حالتِ عادی، وگرنه نوبتِ این درخواستِ بدنه.
function hadm_case_appt_info($pdo, $case) {
    if (($case['insurance_type'] ?? '') !== 'BODY' || hadm_self_enabled($pdo)) return null;
    if (in_array((string)($case['status'] ?? ''), ['ISSUED', 'WITHDRAWN', 'CANCELLED', 'REGISTERED'], true)) return null;
    $a = hadm_appt_assign($pdo, $case['id']);
    return ['slot_at' => $a['slot_at'] ?? null, 'slot_fa' => $a ? hadm_appt_fa($pdo, $a) : ''];
}
const HADM_DOCS_HINT = 'بازدیدِ سلامتِ خودرو حضوری و با نوبت است؛ مدارک را (با عکس یا فایل) از «بازدید مدارک» همین حالا بارگذاری کنید تا روزِ نوبت فقط خودرو بازدید شود و مدرکی جا نماند.';

// ---------------- صفِ بازدیدِ مینی‌اپِ مدیر ----------------
// درخواست‌های بدنه‌ی بازی که بازدیدِ تاییدشده ندارند، با اطلاعاتِ درخواست و معرفی‌نامه؛ مرتب به ترتیبِ نوبت
function hadm_queue($pdo) {
    hadm_ensure($pdo);
    $rows = $pdo->query("
        SELECT pc.id, pc.unique_code, pc.status, pc.plate, pc.insured_name, pc.insured_national_id, pc.estimated_car_value, pc.created_at,
               p.full_name AS holder_name, p.national_code AS holder_nid, p.personnel_code, p.mobile_number, c.name AS company_name,
               i.letter_date, i.id AS intro_id,
               (SELECT hi.status FROM health_inspections hi WHERE hi.case_id = pc.id ORDER BY hi.id DESC LIMIT 1) AS last_insp,
               ha.slot_at
          FROM policy_cases pc
          JOIN persons p ON p.id = pc.person_id
          LEFT JOIN companies c ON c.id = p.company_id
          LEFT JOIN introductions i ON i.id = pc.introduction_id
          LEFT JOIN health_appointments ha ON ha.case_id = pc.id
         WHERE pc.insurance_type = 'BODY' AND pc.status NOT IN ('ISSUED', 'WITHDRAWN', 'CANCELLED')
         ORDER BY pc.id DESC LIMIT 400")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if (in_array($r['last_insp'], ['APPROVED', 'PHOTOS_APPROVED'], true)) continue;
        if (!$r['slot_at']) { $a = hadm_appt_assign($pdo, $r['id']); $r['slot_at'] = $a['slot_at'] ?? null; }
        $r['_docs'] = null;
        if (function_exists('compute_docs_status')) {
            try {
                $full = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?"); $full->execute([$r['id']]);
                if ($fc = $full->fetch()) $r['_docs'] = compute_docs_status($pdo, $fc);
            } catch (Throwable $e) {}
        }
        $out[] = [
            'case_id' => intval($r['id']), 'code' => $r['unique_code'], 'status' => $r['status'], 'status_fa' => function_exists('case_status_fa') ? case_status_fa($r['status']) : $r['status'],
            'plate' => $r['plate'], 'insured' => $r['insured_name'] ?: $r['holder_name'], 'insured_nid' => $r['insured_national_id'],
            'holder' => $r['holder_name'], 'holder_nid' => $r['holder_nid'], 'personnel_code' => $r['personnel_code'], 'mobile' => $r['mobile_number'],
            'company' => $r['company_name'], 'car_value' => $r['estimated_car_value'] ? (int)$r['estimated_car_value'] : null,
            'letter_date' => $r['letter_date'] ? str_replace('-', '/', $r['letter_date']) : null,
            'last_insp' => $r['last_insp'], 'slot_at' => $r['slot_at'],
            'docs_status' => $r['_docs'],
            'slot_fa' => $r['slot_at'] ? hadm_appt_fa($pdo, ['slot_at' => $r['slot_at']]) : '',
        ];
    }
    usort($out, function ($a, $b) { return strcmp((string)($a['slot_at'] ?: '9999'), (string)($b['slot_at'] ?: '9999')); });
    return $out;
}

// بازدیدی که مدیر/کارشناس ثبت کرد: همه‌ی عکس‌ها همان لحظه تایید می‌شوند => «آماده‌ی گزارشِ بازدید»
function hadm_staff_approve_inspection($pdo, $siteRoot, $inspId, $staffId) {
    $st = $pdo->prepare("SELECT photos FROM health_inspections WHERE id = ?");
    $st->execute([intval($inspId)]);
    $photos = json_decode((string)$st->fetchColumn(), true) ?: [];
    foreach (array_keys($photos) as $k) {
        try { health_review_photo($pdo, $siteRoot, intval($inspId), (string)$k, 'APPROVED', '', $staffId); }
        catch (Throwable $e) { error_log('[hadm approve] ' . $e->getMessage()); }
    }
    try { $pdo->prepare("UPDATE health_inspections SET by_staff = ? WHERE id = ?")->execute([$staffId, $inspId]); } catch (Throwable $e) {}
}

// پوشه‌ی بازدیدِ آزادی که مدیر برای پلاکی بدونِ پرونده/شخص می‌گیرد
function hadm_free_folder($siteRoot, $plate) {
    [$jy, $jm] = jalali_from_gregorian_ts(time());
    $dir = archive_root($siteRoot) . '/بازدیدهای آزاد/' . $jy . '/' . jalali_month_name($jm) . '/' . sanitize_folder_name($plate ?: 'بدون‌پلاک');
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}
