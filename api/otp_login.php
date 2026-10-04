<?php
// فایل: api/otp_login.php
// ورود با شماره تلفن و کدِ ۵ رقمی که با ربات بله‌ی شرکت‌ها فرستاده می‌شود.
//   send   : شماره را می‌گیرد، کاربر را پیدا می‌کند (کاربر پنل یا کاربر شرکت) و اگر قبلاً در
//            ربات با همین شماره وارد شده باشد، کد را برایش در ربات می‌فرستد.
//   verify : کد را بررسی می‌کند و نشستِ مناسب را می‌سازد (پنل ما یا پنل شرکت‌ها).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
function out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (!auth_schema_ready($pdo)) out(['ok' => false, 'error' => 'ورود با کد هنوز راه‌اندازی نشده است. (' . AUTH_SCHEMA_MSG . ')']);
if (!cbot_token($pdo)) out(['ok' => false, 'error' => 'ربات بله‌ی شرکت‌ها هنوز در تنظیمات وصل نشده است.']);

$phone = auth_norm_phone($data['phone'] ?? '');
if ($phone === '') out(['ok' => false, 'code' => 'BAD_PHONE', 'error' => 'شماره موبایل را درست وارد کنید (۱۱ رقم، با ۰۹).']);

function pick_account($accounts, $key) {
    foreach ($accounts as $a) if (auth_account_key($a) === $key) return $a;
    return count($accounts) === 1 ? $accounts[0] : null;
}
function acc_public($a) {
    return ['key' => auth_account_key($a), 'name' => $a['user']['full_name'], 'role_fa' => auth_role_fa($a['type'], $a['user']['role'] ?? null),
            'type' => $a['type'], 'linked' => auth_account_linked($a)];
}

try {
    if ($action === 'send') {
        // محدودیت: حداکثر ۱۵ بار جستجوی شماره در ۱۰ دقیقه از هر مرورگر
        session_start();
        $now = time();
        $_SESSION['otp_lookups'] = array_values(array_filter($_SESSION['otp_lookups'] ?? [], fn($t) => $t > $now - 600));
        if (count($_SESSION['otp_lookups']) >= 15) out(['ok' => false, 'error' => 'تعداد درخواست‌ها زیاد بود؛ چند دقیقه‌ی دیگر امتحان کنید.']);
        $_SESSION['otp_lookups'][] = $now;
        session_write_close();

        $accounts = auth_accounts_by_phone($pdo, $phone);
        if (!$accounts) out(['ok' => false, 'code' => 'NOT_FOUND', 'error' => 'کاربری با این شماره یافت نشد.']);
        // درگاهِ انتخاب‌شده در صفحه‌ی ورود: «همکارِ بیمه» = حساب‌های داخلی، «کارشناسِ شرکت‌ها» = کاربرانِ شرکت
        $gate = ($data['gate'] ?? '') === 'COMPANY' ? 'COMPANY' : (($data['gate'] ?? '') === 'STAFF' ? 'STAFF' : '');
        if ($gate !== '') {
            $inGate = array_values(array_filter($accounts, function ($a) use ($gate) { return $a['type'] === $gate; }));
            if (!$inGate) out(['ok' => false, 'code' => 'OTHER_GATE', 'error' => $gate === 'COMPANY'
                ? 'این شماره مالِ «همکارِ بیمه» است؛ بالای فرم «همکارِ بیمه» را انتخاب کنید.'
                : 'این شماره مالِ «کاربرِ شرکت» است؛ بالای فرم «کارشناسِ شرکت‌ها» را انتخاب کنید.']);
            $accounts = $inGate;
        }
        $acc = pick_account($accounts, (string)($data['account'] ?? ''));
        if (!$acc) out(['ok' => true, 'choose' => array_map('acc_public', $accounts)]);   // یک شماره، چند حساب
        $pub = acc_public($acc);
        if (!$pub['linked']) {
            $botUser = '';
            try { $botUser = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_bot_username'")->fetchColumn(); } catch (Throwable $e) {}
            out(['ok' => false, 'code' => 'NOT_LINKED', 'account' => $pub,
                 'error' => $pub['name'] . ' عزیز، لطفاً ابتدا در ربات بله‌ی شرکت با شماره‌ی خود وارد شوید و سپس برای ورود اقدام کنید.',
                 'bot_url' => $botUser ? 'https://ble.ir/' . ltrim($botUser, '@') : null]);
        }
        $r = otp5_issue($pdo, $acc, $_SERVER['REMOTE_ADDR'] ?? null);
        if (!$r['ok']) out(['ok' => false, 'error' => $r['error'], 'account' => $pub]);
        out(['ok' => true, 'sent' => true, 'account' => $pub, 'ttl' => $r['ttl']]);
    }

    if ($action === 'verify') {
        $key = (string)($data['account'] ?? '');
        [$type, $id] = array_pad(explode(':', $key, 2), 2, 0);
        if (!in_array($type, ['STAFF', 'COMPANY'], true)) out(['ok' => false, 'error' => 'حساب نامعتبر است.']);
        $acc = auth_load_account($pdo, $type, intval($id));
        // کلیدِ حساب باید واقعاً مالِ همین شماره باشد
        if (!$acc || auth_norm_phone($acc['user']['mobile_number']) !== $phone) out(['ok' => false, 'error' => 'حساب نامعتبر است.']);
        $r = otp5_verify($pdo, $acc, $data['code'] ?? '');
        if (!$r['ok']) {
            auth_log_login($pdo, $type, $acc['user'], 'OTP', false, 'کد اشتباه');
            out($r);
        }
        $u = $acc['user'];
        if ($type === 'STAFF') {
            session_start();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['full_name'] = $u['full_name'];
            $_SESSION['role'] = $u['role'];
            auth_log_login($pdo, 'STAFF', $u, 'OTP', true);
            sec_on_login($pdo, 'STAFF', $u['id'], $u['full_name']);
            out(['ok' => true, 'redirect' => 'dashboard.php', 'name' => $u['full_name']]);
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM company_portal_user_companies WHERE portal_user_id = ?");
        $st->execute([$u['id']]);
        if (!intval($st->fetchColumn())) out(['ok' => false, 'error' => 'این حساب به هیچ شرکتی وصل نیست. با ما تماس بگیرید.']);
        company_portal_session_start_named();
        session_regenerate_id(true);
        $_SESSION['company_user_id'] = $u['id'];
        $_SESSION['company_user_full_name'] = $u['full_name'];
        auth_log_login($pdo, 'COMPANY', $u, 'OTP', true);
        sec_on_login($pdo, 'COMPANY', $u['id'], $u['full_name']);
        out(['ok' => true, 'redirect' => 'company-portal/index.php', 'name' => $u['full_name']]);
    }

    out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[otp_login] ' . $e->getMessage());
    out(['ok' => false, 'error' => 'خطای سرور.']);
}

// همان نامِ نشستِ پنل شرکت‌ها (api/_company_helpers.php)
function company_portal_session_start_named() {
    if (session_status() === PHP_SESSION_NONE) { session_name('bime_company_portal'); session_start(); }
}
