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
    'hadm_pick' => '1',                  // ۱ = کاربر خودش زمانِ بازدید را از نوبت‌های آزاد انتخاب می‌کند؛ ۰ = نوبتِ خودکار (روشِ قبلی)
    'hadm_window' => '7',                // کاربر تا چند روزِ آینده می‌تواند نوبت انتخاب کند
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
    // نوبتِ بازدید: چه کسی گرفت (USER = خودِ کاربر، STAFF = دستی در پنل، خالی = خودکار)، وضعیت (BOOKED | DONE | CANCELED) و گزارشِ بازدیدِ مربوط
    try {
        if (!$pdo->query("SHOW COLUMNS FROM health_appointments LIKE 'status'")->fetch())
            $pdo->exec("ALTER TABLE health_appointments ADD COLUMN source VARCHAR(8) NULL, ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'BOOKED', ADD COLUMN staff_id INT NULL,
                        ADD COLUMN note VARCHAR(300) NULL, ADD COLUMN vr_id INT NULL, ADD COLUMN done_at DATETIME NULL, ADD COLUMN updated_at DATETIME NULL, ADD KEY k_status (status, slot_at)");
    } catch (Throwable $e) { error_log('[hadm_ensure appt cols] ' . $e->getMessage()); }
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
        'hadm_pick' => array_key_exists('pick', $in) ? (!empty($in['pick']) ? '1' : '0') : $cur['hadm_pick'],
        'hadm_window' => (string)max(1, min(30, intval(p2e_digits((string)($in['window'] ?? $cur['hadm_window']))))),
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
function hadm_user_picks($pdo) { return hadm_settings($pdo)['hadm_pick'] !== '0'; }
// نوبتِ فعال (لغوشده حساب نمی‌شود)
function hadm_appt_active($pdo, $caseId) {
    $a = hadm_appt_get($pdo, $caseId);
    return $a && ($a['status'] ?? 'BOOKED') !== 'CANCELED' ? $a : null;
}

// نوبتِ این درخواست (اگر ندارد، اولین نوبتِ آزاد طبقِ برنامه ساخته می‌شود)؛ null یعنی برنامه نوبتی ندارد
// در حالتِ «انتخابِ نوبت توسط کاربر» نوبتِ خودکار ساخته نمی‌شود (تا کاربر یا کارشناس انتخاب کند)
function hadm_appt_assign($pdo, $caseId) {
    if (hadm_user_picks($pdo)) return hadm_appt_active($pdo, $caseId);
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
    $pick = hadm_user_picks($pdo);
    $past = $a && strtotime($a['slot_at']) < time() && ($a['status'] ?? 'BOOKED') === 'BOOKED';
    return ['slot_at' => $a['slot_at'] ?? null, 'slot_fa' => $a ? hadm_appt_fa($pdo, $a) : '', 'status' => $a['status'] ?? null, 'source' => $a['source'] ?? null,
            'can_pick' => $pick && (!$a || ($a['status'] ?? 'BOOKED') === 'BOOKED'), 'need_pick' => $pick && (!$a || $past), 'past' => $past];
}

// ---------------- انتخابِ نوبت (تقویمِ کاری) ----------------
// روزهای کاری از «اولین نوبت چند روز بعد» تا «چند روزِ آینده» با همه‌ی نوبت‌های هر روز و ظرفیتِ خالی
// $staff = true: همه‌ی روزها (حتی تعطیل) و نوبت‌های پر هم برای نوبتِ دستی برمی‌گردد
function hadm_slots($pdo, $excludeCase = 0, $fromDays = null, $days = null, $staff = false) {
    hadm_ensure($pdo);
    $s = hadm_settings($pdo);
    $work = array_map('intval', array_filter(explode(',', $s['hadm_days']), 'strlen'));
    $slot = max(5, intval($s['hadm_slot'])) * 60; $cap = max(1, intval($s['hadm_cap']));
    [$sh, $sm] = array_map('intval', explode(':', $s['hadm_start'])); [$eh, $em] = array_map('intval', explode(':', $s['hadm_end']));
    $holidays = [];
    try { $hs = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'work_holidays'")->fetchColumn(); foreach (preg_split('/[\s,،]+/u', (string)$hs) as $h) if ($h !== '') $holidays[p2e_digits($h)] = true; } catch (Throwable $e) {}
    $from = $fromDays === null ? max(0, intval($s['hadm_lead'])) : intval($fromDays);
    $n = $days === null ? max(1, intval($s['hadm_window'])) : intval($days);
    $t0 = strtotime('today') + $from * 86400;
    $t1 = $t0 + $n * 86400;
    $q = $pdo->prepare("SELECT slot_at, COUNT(*) n FROM health_appointments WHERE status = 'BOOKED' AND slot_at >= ? AND slot_at < ? AND case_id <> ? GROUP BY slot_at");
    $q->execute([date('Y-m-d H:i:s', $t0), date('Y-m-d H:i:s', $t1), intval($excludeCase)]);
    $used = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    for ($d = 0; $d < $n; $d++) {
        $dayTs = strtotime(date('Y-m-d', $t0 + $d * 86400 + 43200));
        [$jy, $jm, $jd] = jalali_from_gregorian_ts($dayTs + 43200);
        $jdate = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        $wd = hadm_weekday($dayTs);
        $closed = !in_array($wd, $work, true) ? 'روزِ کاری نیست' : (isset($holidays[$jdate]) ? 'تعطیل' : '');
        $slots = [];
        if ($closed === '' || $staff) {
            for ($t = $dayTs + $sh * 3600 + $sm * 60; $t + $slot <= $dayTs + $eh * 3600 + $em * 60; $t += $slot) {
                $at = date('Y-m-d H:i:s', $t);
                $u = intval($used[$at] ?? 0);
                $slots[] = ['at' => $at, 'time' => date('H:i', $t), 'used' => $u, 'cap' => $cap, 'free' => $u < $cap && $t > time() && $closed === ''];
            }
        }
        $out[] = ['date' => date('Y-m-d', $dayTs), 'jdate' => $jdate, 'weekday' => HADM_WEEKDAYS[$wd], 'day' => $jd, 'month_fa' => jalali_month_name($jm), 'closed' => $closed, 'slots' => $slots,
                  'free' => count(array_filter($slots, fn($x) => $x['free']))];
    }
    return ['days' => $out, 'place' => trim($s['hadm_place']), 'slot_min' => intval($s['hadm_slot'])];
}

// ثبتِ نوبت. $source: USER (کاربر از نوبت‌های آزاد) | STAFF (کارشناس؛ هر زمانی، حتی خارج از برنامه/پر)
function hadm_book($pdo, $caseId, $at, $source, $staffId = null, $note = '') {
    hadm_ensure($pdo);
    $st = $pdo->prepare("SELECT pc.id, pc.status, pc.insurance_type, pc.plate, p.bale_chat_id, p.id AS person_id FROM policy_cases pc JOIN persons p ON p.id = pc.person_id WHERE pc.id = ?");
    $st->execute([intval($caseId)]);
    $c = $st->fetch();
    if (!$c) return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    if ($c['insurance_type'] !== 'BODY') return ['ok' => false, 'error' => 'نوبتِ بازدید فقط برای بیمه‌ی بدنه است.'];
    if (in_array($c['status'], ['ISSUED', 'WITHDRAWN', 'CANCELLED'], true)) return ['ok' => false, 'error' => 'این درخواست بسته شده است.'];
    $ts = strtotime((string)$at);
    if (!$ts) return ['ok' => false, 'error' => 'زمانِ نوبت نامعتبر است.'];
    $at = date('Y-m-d H:i:s', $ts);
    if ($source === 'USER') {
        $cur = hadm_appt_active($pdo, $caseId);
        if ($cur && ($cur['status'] ?? '') === 'DONE') return ['ok' => false, 'error' => 'بازدیدِ این خودرو انجام شده است.'];
        $ok = false;
        foreach (hadm_slots($pdo, $caseId)['days'] as $d) foreach ($d['slots'] as $x) if ($x['at'] === $at && $x['free']) $ok = true;
        if (!$ok) return ['ok' => false, 'error' => 'این نوبت دیگر آزاد نیست؛ زمانِ دیگری انتخاب کنید.'];
    } elseif ($ts < time() - 86400 * 60) return ['ok' => false, 'error' => 'تاریخِ نوبت خیلی قدیمی است.'];
    $pdo->prepare("INSERT INTO health_appointments (case_id, slot_at, notified, source, status, staff_id, note, vr_id, done_at, updated_at) VALUES (?, ?, 0, ?, 'BOOKED', ?, ?, NULL, NULL, NOW())
                   ON DUPLICATE KEY UPDATE slot_at = VALUES(slot_at), notified = 0, source = VALUES(source), status = 'BOOKED', staff_id = VALUES(staff_id), note = VALUES(note), vr_id = NULL, done_at = NULL, updated_at = NOW()")
        ->execute([intval($caseId), $at, $source === 'STAFF' ? 'STAFF' : 'USER', $staffId ?: null, mb_substr(trim((string)$note), 0, 300) ?: null]);
    $a = hadm_appt_get($pdo, $caseId);
    $fa = hadm_appt_fa($pdo, $a);
    // نوبتِ دستیِ کارشناس: به کاربر خبر داده می‌شود
    if ($source === 'STAFF' && $c['bale_chat_id'] && function_exists('bale_curl_call') && function_exists('bale_get_token')) {
        try { bale_curl_call('sendMessage', ['chat_id' => $c['bale_chat_id'], 'text' => "🗓 نوبتِ بازدیدِ خودروی " . ($c['plate'] ?: '') . " ثبت شد:
" . $fa . "

صدورِ بیمه‌نامه‌ی بدنه منوط به انجامِ بازدیدِ خودرو است."], bale_get_token($pdo)); } catch (Throwable $e) {}
    }
    return ['ok' => true, 'appointment' => $a, 'slot_fa' => $fa];
}
function hadm_cancel($pdo, $caseId) {
    hadm_ensure($pdo);
    $pdo->prepare("UPDATE health_appointments SET status = 'CANCELED', updated_at = NOW() WHERE case_id = ? AND status = 'BOOKED'")->execute([intval($caseId)]);
    return ['ok' => true];
}
// گزارشِ بازدیدِ این درخواست صادر و وصل شد => نوبت «انجام شد» (اگر نوبتی نداشت، یک ردیفِ «انجام شد» برای سابقه ساخته می‌شود)
function hadm_mark_done($pdo, $caseId, $vrId = null) {
    hadm_ensure($pdo);
    $pdo->prepare("INSERT INTO health_appointments (case_id, slot_at, notified, source, status, vr_id, done_at, updated_at) VALUES (?, NOW(), 1, 'STAFF', 'DONE', ?, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE status = 'DONE', vr_id = COALESCE(VALUES(vr_id), vr_id), done_at = NOW(), updated_at = NOW()")->execute([intval($caseId), $vrId ?: null]);
}
const HADM_DOCS_HINT = 'بازدیدِ خودرو حضوری و با نوبت است؛ مدارک را (با عکس یا فایل) از «بارگزاری مدارک» همین حالا ارسال کنید تا روزِ نوبت فقط خودرو بازدید شود و مدرکی جا نماند.';

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
          LEFT JOIN health_appointments ha ON ha.case_id = pc.id AND COALESCE(ha.status, 'BOOKED') <> 'CANCELED'
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
