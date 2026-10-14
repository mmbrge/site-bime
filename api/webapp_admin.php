<?php
// فایل: api/webapp_admin.php
// مینی‌اپِ مدیر در ربات بله (webapp/admin/index.html) و تنظیماتِ «بازدید سلامت» در پنل.
//  - login: توکنِ یک‌بارمصرفی که ربات بعد از دستورِ مخفی + نام کاربری/رمز فرستاده => همان نشستِ معمولیِ پنل (مثلِ index.php)
//    تا مینی‌اپ بتواند از همان APIهای پنل (ثبتِ معرفی‌نامه و درخواست، ...) استفاده کند.
//  - queue / start / start_free: صفِ بازدید (به ترتیبِ نوبت) و شروعِ بازدیدِ کارشناس (بدونِ نیاز به تایید)
//  - settings_get / settings_save / set_appt: تنظیماتِ بازدید و نوبت (فقط مدیر کل)
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_health_admin.php';
require_once __DIR__ . '/_auth_helpers.php';

function wa_out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$data = json_decode(file_get_contents('php://input'), true) ?: ($_POST ?: []);
$action = (string)($data['action'] ?? ($_GET['action'] ?? ''));
hadm_ensure($pdo);

try {
    if ($action === 'login') {
        $t = preg_replace('/[^a-f0-9]/', '', (string)($data['t'] ?? ''));
        $st = $pdo->prepare("SELECT * FROM admin_app_tokens WHERE token = ? AND used_at IS NULL AND expires_at > NOW()");
        $st->execute([$t]);
        $row = $st->fetch();
        if (!$row) wa_out(['ok' => false, 'error' => 'لینکِ ورود منقضی شده یا قبلاً استفاده شده است. در ربات دوباره دستورِ ورود را بفرستید.']);
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'ADMIN' AND COALESCE(is_deleted, 0) = 0");
        $st->execute([$row['user_id']]);
        $u = $st->fetch();
        if (!$u) wa_out(['ok' => false, 'error' => 'حساب معتبر نیست.']);
        $pdo->prepare("UPDATE admin_app_tokens SET used_at = NOW() WHERE token = ?")->execute([$t]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['full_name'] = $u['full_name'];
        $_SESSION['role'] = $u['role'];
        $_SESSION['via_bot_app'] = 1;
        try { auth_log_login($pdo, 'STAFF', $u, 'PASSWORD', true, 'ورود از مینی‌اپِ مدیر (ربات)'); } catch (Throwable $e) {}
        if (is_file(__DIR__ . '/_security.php')) { require_once __DIR__ . '/_security.php'; if (function_exists('sec_on_login')) { try { sec_on_login($pdo, 'STAFF', $u['id'], $u['full_name']); } catch (Throwable $e) {} } }
        wa_out(['ok' => true, 'name' => $u['full_name']]);
    }

    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') wa_out(['ok' => false, 'auth' => false, 'error' => 'ابتدا از ربات وارد شوید.']);
    $uid = intval($_SESSION['user_id']);

    if ($action === 'me') {
        $s = hadm_settings($pdo);
        wa_out(['ok' => true, 'name' => $_SESSION['full_name'] ?? '', 'self_enabled' => $s['hadm_self_enabled'] !== '0', 'site' => get_site_url($pdo)]);
    }

    if ($action === 'queue') wa_out(['ok' => true, 'rows' => hadm_queue($pdo)]);

    // شروعِ بازدیدِ کارشناس برای یک درخواست (یا پلاکِ آزاد) => آدرسِ صفحه‌ی بازدید
    if ($action === 'start' || $action === 'start_free') {
        $personId = null; $caseId = null; $plate = null; $mode = 'case';
        if ($action === 'start') {
            $st = $pdo->prepare("SELECT id, person_id FROM policy_cases WHERE id = ? AND COALESCE(status, '') NOT IN ('WITHDRAWN', 'CANCELLED')");
            $st->execute([intval($data['case_id'] ?? 0)]);
            $c = $st->fetch();
            if (!$c) wa_out(['ok' => false, 'error' => 'درخواست پیدا نشد.']);
            $personId = intval($c['person_id']); $caseId = intval($c['id']);
        } else {
            $mode = 'free';
            $plate = trim(preg_replace('/\s+/u', ' ', p2e_digits((string)($data['plate'] ?? ''))));
            if (mb_strlen($plate) < 4) wa_out(['ok' => false, 'error' => 'پلاک (یا شماره‌ی شاسی) را کامل وارد کنید.']);
            // اگر این پلاک مالِ شخصی در سامانه است، بازدید در پوشه‌ی معرفی‌نامه‌ی همان شخص می‌نشیند
            $st = $pdo->prepare("SELECT person_id FROM policy_cases WHERE REPLACE(REPLACE(plate, ' ', ''), '-', '') = REPLACE(REPLACE(?, ' ', ''), '-', '') ORDER BY id DESC LIMIT 1");
            $st->execute([$plate]);
            $personId = intval($st->fetchColumn()) ?: null;
        }
        $token = create_webapp_session($pdo, $personId, $caseId, $mode, $plate, 180);
        $pdo->prepare("UPDATE webapp_sessions SET by_staff = ? WHERE token = ?")->execute([$uid, $token]);
        wa_out(['ok' => true, 'url' => '/webapp/bazdid/index_bazdid.html?t=' . $token]);
    }

    if ($action === 'set_appt') {
        $caseId = intval($data['case_id'] ?? 0);
        $d = p2e_digits(trim((string)($data['date'] ?? ''))); $tm = p2e_digits(trim((string)($data['time'] ?? '')));
        if (!preg_match('/^(1[34]\d\d)\D(\d{1,2})\D(\d{1,2})$/', $d, $m) || !preg_match('/^(\d{1,2}):(\d{2})$/', $tm, $hm)) wa_out(['ok' => false, 'error' => 'تاریخ و ساعت را درست وارد کنید (۱۴۰۵/۰۷/۲۰ و ۱۰:۳۰).']);
        $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])) + intval($hm[1]) * 3600 + intval($hm[2]) * 60;
        $pdo->prepare("INSERT INTO health_appointments (case_id, slot_at, notified) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE slot_at = VALUES(slot_at), notified = 0")
            ->execute([$caseId, date('Y-m-d H:i:s', $ts)]);
        // خبر به کاربر در ربات
        try {
            $st = $pdo->prepare("SELECT p.bale_chat_id, pc.plate FROM policy_cases pc JOIN persons p ON p.id = pc.person_id WHERE pc.id = ?");
            $st->execute([$caseId]);
            $r = $st->fetch();
            if ($r && $r['bale_chat_id']) bale_curl_call('sendMessage', ['chat_id' => $r['bale_chat_id'], 'text' => "🗓 نوبتِ بازدیدِ سلامتِ خودروی " . ($r['plate'] ?: '') . ":\n" . hadm_appt_fa($pdo, hadm_appt_get($pdo, $caseId))], bale_get_token($pdo));
        } catch (Throwable $e) {}
        wa_out(['ok' => true, 'slot_fa' => hadm_appt_fa($pdo, hadm_appt_get($pdo, $caseId))]);
    }

    if ($action === 'settings_get') {
        $s = hadm_settings($pdo);
        wa_out(['ok' => true, 'settings' => ['self_enabled' => $s['hadm_self_enabled'] !== '0', 'days' => array_map('intval', array_filter(explode(',', $s['hadm_days']), 'strlen')),
            'start' => $s['hadm_start'], 'end' => $s['hadm_end'], 'slot' => intval($s['hadm_slot']), 'cap' => intval($s['hadm_cap']), 'lead' => intval($s['hadm_lead']),
            'place' => $s['hadm_place'], 'bot_cmd' => $s['hadm_bot_cmd']]]);
    }
    if ($action === 'settings_save') wa_out(hadm_save_settings($pdo, is_array($data['settings'] ?? null) ? $data['settings'] : []));

    wa_out(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[webapp_admin] ' . $e->getMessage());
    wa_out(['ok' => false, 'error' => 'خطای سرور.']);
}
