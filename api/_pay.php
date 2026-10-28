<?php
// فایل: api/_pay.php
// پرداختِ آنلاین با درگاه‌های قابلِ تنظیم (فعلاً برای خریدِ اشتراکِ هوشیکس؛ برای کارهای دیگر هم قابلِ استفاده است)
//   زرین‌پال، زیبال، دیجی‌پی، اسنپ‌پی (اقساطی)، سامان (SEP)، ملت (به‌پرداخت)، ملی (سداد)، پاسارگاد (PEP) و «آزمایشی» (بدونِ پول، فقط مدیر کل)
//  - تنظیمِ درگاه‌ها در system_settings.pay_gateways (چند درگاه، هر کدام روشن/خاموش، ترتیب = ترتیبِ نمایش)
//  - هر پرداخت یک ردیف در pay_tx؛ مبلغ همیشه از همین جدول خوانده می‌شود (نه از مرورگر یا جوابِ درگاه)
//  - بازگشت از درگاه: api/pay_callback.php?tx=ID&s=امضا (HMAC) — بدونِ نیاز به نشستِ کاربر؛ تأییدِ نهایی همیشه سمتِ سرور با API‌ِ خودِ درگاه
//    و فقط یک بار (قفلِ ردیف با status)؛ مبلغِ تأییدشده با مبلغِ ردیف مقایسه می‌شود.
// مبلغ‌ها: amount = ریال (درگاه‌ها ریال می‌گیرند)؛ نمایش به تومان.
require_once __DIR__ . '/_apis.php';

const PAY_TYPES = [
    'zarinpal' => ['fa' => 'زرین‌پال', 'icon' => 'fa-gem', 'sandbox' => true, 'fields' => [['merchant_id', 'مرچنت کد (Merchant ID)', 'secret']]],
    'zibal' => ['fa' => 'زیبال', 'icon' => 'fa-bolt', 'sandbox' => false, 'fields' => [['merchant', 'مرچنت (برای آزمایش: zibal)', 'secret']]],
    'digipay' => ['fa' => 'دیجی‌پی', 'icon' => 'fa-wallet', 'sandbox' => false, 'fields' => [['username', 'نام کاربری', 'text'], ['password', 'رمز', 'secret'], ['client_id', 'Client ID', 'text'],
                                                                                        ['client_secret', 'Client Secret', 'secret'], ['base_url', 'آدرسِ API (خالی = https://api.mydigipay.com)', 'text'], ['type', 'نوعِ تیکت (پیش‌فرض ۱۱)', 'text']]],
    'snapppay' => ['fa' => 'اسنپ‌پی (اقساطی)', 'icon' => 'fa-calendar-check', 'sandbox' => false, 'fields' => [['username', 'نام کاربری', 'text'], ['password', 'رمز', 'secret'], ['client_id', 'Client ID', 'text'],
                                                                                        ['client_secret', 'Client Secret', 'secret'], ['base_url', 'آدرسِ API (از اسنپ‌پی بگیرید)', 'text']]],
    'sep' => ['fa' => 'بانک سامان (سپ)', 'icon' => 'fa-building-columns', 'sandbox' => false, 'fields' => [['terminal_id', 'شماره ترمینال', 'text']]],
    'mellat' => ['fa' => 'بانک ملت (به‌پرداخت)', 'icon' => 'fa-building-columns', 'sandbox' => false, 'fields' => [['terminal_id', 'شماره ترمینال', 'text'], ['username', 'نام کاربری', 'text'], ['password', 'رمز', 'secret']]],
    'sadad' => ['fa' => 'بانک ملی (سداد)', 'icon' => 'fa-building-columns', 'sandbox' => false, 'fields' => [['merchant_id', 'شماره پذیرنده (MerchantId)', 'text'], ['terminal_id', 'شماره ترمینال', 'text'], ['key', 'کلیدِ ترمینال (Base64)', 'secret']]],
    'pasargad' => ['fa' => 'بانک پاسارگاد (PEP)', 'icon' => 'fa-building-columns', 'sandbox' => false, 'fields' => [['username', 'نام کاربری', 'text'], ['password', 'رمز', 'secret'], ['terminal', 'شماره ترمینال', 'text']]],
    'test' => ['fa' => 'آزمایشی (بدونِ پول؛ فقط مدیر کل)', 'icon' => 'fa-flask', 'sandbox' => false, 'fields' => []],
];
const PAY_ST_FA = ['NEW' => 'ساخته شد', 'REDIRECT' => 'در درگاه', 'VERIFYING' => 'در حالِ تأیید', 'PAID' => 'پرداخت‌شده', 'FAILED' => 'ناموفق', 'CANCELLED' => 'لغو شد'];

function pay_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pay_tx (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(20) NOT NULL, user_id INT NOT NULL, gateway_id VARCHAR(16) NOT NULL, gateway_type VARCHAR(16) NOT NULL,
            amount BIGINT NOT NULL, title VARCHAR(190) NULL, status VARCHAR(12) NOT NULL DEFAULT 'NEW', authority VARCHAR(190) NULL, ref_no VARCHAR(120) NULL, card VARCHAR(40) NULL,
            meta TEXT NULL, error VARCHAR(500) NULL, raw TEXT NULL, mobile VARCHAR(20) NULL, ip VARCHAR(45) NULL, created_at DATETIME NULL, updated_at DATETIME NULL, paid_at DATETIME NULL, done_at DATETIME NULL,
            KEY k_user (user_id, id), KEY k_status (status, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('[pay_ensure] ' . $e->getMessage()); }
}

// درگاه‌ها: [{id, type, title, enabled, sandbox, cfg: {...}}]
function pay_gateways($pdo, $onlyEnabled = false) {
    $list = json_decode((string)sms_get($pdo, 'pay_gateways', '[]'), true);
    $out = [];
    foreach (is_array($list) ? $list : [] as $g) {
        if (!is_array($g) || !isset(PAY_TYPES[$g['type'] ?? '']) || empty($g['id'])) continue;
        $g = ['id' => (string)$g['id'], 'type' => $g['type'], 'title' => trim((string)($g['title'] ?? '')) ?: PAY_TYPES[$g['type']]['fa'], 'enabled' => !empty($g['enabled']),
              'sandbox' => !empty($g['sandbox']), 'cfg' => is_array($g['cfg'] ?? null) ? $g['cfg'] : []];
        if ($onlyEnabled && !$g['enabled']) continue;
        $out[$g['id']] = $g;
    }
    return $out;
}
const PAY_MASK = '••••••';
// برای مرورگر: کلیدها فقط ۴ نویسه‌ی آخر
function pay_gateways_public($pdo) {
    $out = [];
    foreach (pay_gateways($pdo) as $g) {
        foreach (PAY_TYPES[$g['type']]['fields'] as $f) if ($f[2] === 'secret' && !empty($g['cfg'][$f[0]])) $g['cfg'][$f[0]] = PAY_MASK . mb_substr((string)$g['cfg'][$f[0]], -4);
        $out[] = $g;
    }
    return $out;
}
function pay_save_gateways($pdo, array $in) {
    $old = pay_gateways($pdo);
    $out = [];
    foreach ($in as $g) {
        if (!is_array($g) || !isset(PAY_TYPES[$g['type'] ?? ''])) continue;
        $id = preg_replace('/[^a-z0-9]/', '', (string)($g['id'] ?? ''));
        if ($id === '' || isset($out[$id])) $id = 'g' . bin2hex(random_bytes(3));
        $prev = $old[$id]['cfg'] ?? [];
        $cfg = [];
        foreach (PAY_TYPES[$g['type']]['fields'] as $f) {
            $v = trim((string)($g['cfg'][$f[0]] ?? ''));
            if ($f[2] === 'secret' && strpos($v, PAY_MASK) === 0) $v = (string)($prev[$f[0]] ?? '');   // دست‌نخورده = همان قبلی
            if ($f[0] === 'base_url' && $v !== '' && !preg_match('~^https://~i', $v)) throw new Exception('آدرسِ API باید با https:// شروع شود.');
            $cfg[$f[0]] = mb_substr($v, 0, 600);
        }
        $out[$id] = ['id' => $id, 'type' => $g['type'], 'title' => mb_substr(trim((string)($g['title'] ?? '')), 0, 60), 'enabled' => !empty($g['enabled']) ? 1 : 0,
                     'sandbox' => !empty($g['sandbox']) && PAY_TYPES[$g['type']]['sandbox'] ? 1 : 0, 'cfg' => $cfg];
    }
    sms_set($pdo, 'pay_gateways', json_encode(array_values($out), JSON_UNESCAPED_UNICODE));
    return pay_gateways($pdo);
}

// امضای آدرسِ بازگشت (تا کسی نتواند برای پرداختِ دیگری درخواست بفرستد)
function pay_secret($pdo) {
    $s = (string)sms_get($pdo, 'pay_secret', '');
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(32)); sms_set($pdo, 'pay_secret', $s); }
    return $s;
}
function pay_sig($pdo, $id) { return substr(hash_hmac('sha256', 'pay-tx:' . intval($id), pay_secret($pdo)), 0, 32); }
function pay_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')))), '/');
}
function pay_cb_url($pdo, $base, $id) { return $base . '/api/pay_callback.php?tx=' . intval($id) . '&s=' . pay_sig($pdo, $id); }

function pay_get($pdo, $id) {
    pay_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM pay_tx WHERE id = ?");
    $st->execute([intval($id)]);
    $t = $st->fetch();
    if ($t) $t['meta'] = json_decode((string)$t['meta'], true) ?: [];
    return $t ?: null;
}
// ساختِ پرداخت (مبلغ به ریال) => id
function pay_create($pdo, $kind, $uid, $gwId, $amountRial, $title, array $meta, $mobile = '') {
    pay_ensure($pdo);
    $g = pay_gateways($pdo, true)[$gwId] ?? null;
    if (!$g) throw new Exception('درگاهِ پرداخت پیدا نشد یا خاموش است.');
    if ($amountRial < 10000) throw new Exception('مبلغ کمتر از حداقلِ درگاه است.');
    $pdo->prepare("INSERT INTO pay_tx (kind, user_id, gateway_id, gateway_type, amount, title, meta, mobile, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
        ->execute([$kind, intval($uid), $g['id'], $g['type'], intval($amountRial), mb_substr((string)$title, 0, 190), json_encode($meta, JSON_UNESCAPED_UNICODE),
                   mb_substr((string)$mobile, 0, 20), mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    return intval($pdo->lastInsertId());
}
// رفتن به درگاه: ['ok', 'url', 'method' => GET|POST, 'fields' => [...]]
function pay_start($pdo, $id, $base = null) {
    $t = pay_get($pdo, $id);
    if (!$t || $t['status'] !== 'NEW') return ['ok' => false, 'error' => 'این پرداخت قبلاً شروع شده است.'];
    $g = pay_gateways($pdo)[$t['gateway_id']] ?? null;
    if (!$g) return ['ok' => false, 'error' => 'درگاهِ پرداخت پیدا نشد.'];
    $cb = pay_cb_url($pdo, $base ?: pay_base_url(), $id);
    $fn = 'pay_drv_' . $g['type'] . '_start';
    try { $r = $fn($pdo, $g, $t, $cb); } catch (Throwable $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
    if (empty($r['ok'])) {
        $pdo->prepare("UPDATE pay_tx SET status = 'FAILED', error = ?, raw = ?, updated_at = NOW() WHERE id = ?")->execute([mb_substr((string)($r['error'] ?? 'خطا'), 0, 500), mb_substr((string)($r['raw'] ?? ''), 0, 4000), $id]);
        return ['ok' => false, 'error' => 'درگاه پرداخت را نپذیرفت: ' . ($r['error'] ?? 'خطا')];
    }
    $meta = $t['meta'] + (array)($r['meta'] ?? []);
    $pdo->prepare("UPDATE pay_tx SET status = 'REDIRECT', authority = ?, meta = ?, updated_at = NOW() WHERE id = ?")->execute([mb_substr((string)$r['authority'], 0, 190), json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
    return ['ok' => true, 'url' => $r['url'], 'method' => $r['method'] ?? 'GET', 'fields' => (array)($r['fields'] ?? [])];
}
// بازگشت از درگاه: تأیید (فقط یک بار) و انجامِ کار (مثلاً فعال‌کردنِ اشتراک)
function pay_verify($pdo, $id, array $req) {
    $t = pay_get($pdo, $id);
    if (!$t) return ['ok' => false, 'error' => 'پرداخت پیدا نشد.'];
    $lock = $pdo->prepare("UPDATE pay_tx SET status = 'VERIFYING', updated_at = NOW() WHERE id = ? AND status = 'REDIRECT'");
    $lock->execute([$id]);
    if (!$lock->rowCount()) {
        // VERIFYING ِ ماندگار (قطعیِ اینترنت وسطِ تأیید) بعد از ۲ دقیقه دوباره امتحان می‌شود
        $re = $pdo->prepare("UPDATE pay_tx SET updated_at = NOW() WHERE id = ? AND status = 'VERIFYING' AND updated_at < (NOW() - INTERVAL 2 MINUTE)");
        $re->execute([$id]);
        if (!$re->rowCount()) return ['ok' => $t['status'] === 'PAID', 'tx' => $t, 'already' => true, 'error' => $t['status'] === 'PAID' ? '' : ($t['error'] ?: PAY_ST_FA[$t['status']] ?? '')];
    }
    $g = pay_gateways($pdo)[$t['gateway_id']] ?? ['type' => $t['gateway_type'], 'cfg' => [], 'sandbox' => false, 'id' => $t['gateway_id']];
    $fn = 'pay_drv_' . $t['gateway_type'] . '_verify';
    try { $r = $fn($pdo, $g, $t, $req); } catch (Throwable $e) { $r = ['ok' => false, 'retry' => true, 'error' => $e->getMessage()]; }
    if (!empty($r['ok'])) {
        $pdo->prepare("UPDATE pay_tx SET status = 'PAID', ref_no = ?, card = ?, raw = ?, error = NULL, paid_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([mb_substr((string)($r['ref'] ?? ''), 0, 120), mb_substr((string)($r['card'] ?? ''), 0, 40), mb_substr((string)($r['raw'] ?? ''), 0, 4000), $id]);
        pay_fulfil($pdo, $id);
    } elseif (!empty($r['retry'])) {
        // جوابِ قطعی از درگاه نیامد: دوباره قابلِ تأیید (با بازگشتِ دوباره یا «بررسیِ دوباره»ی مدیر)
        $pdo->prepare("UPDATE pay_tx SET status = 'REDIRECT', error = ?, updated_at = NOW() WHERE id = ?")->execute([mb_substr((string)($r['error'] ?? ''), 0, 500), $id]);
    } else {
        $pdo->prepare("UPDATE pay_tx SET status = ?, error = ?, raw = ?, updated_at = NOW() WHERE id = ?")
            ->execute([!empty($r['cancel']) ? 'CANCELLED' : 'FAILED', mb_substr((string)($r['error'] ?? 'ناموفق'), 0, 500), mb_substr((string)($r['raw'] ?? ''), 0, 4000), $id]);
    }
    $t = pay_get($pdo, $id);
    return ['ok' => $t['status'] === 'PAID', 'tx' => $t, 'error' => $t['status'] === 'PAID' ? '' : ($t['error'] ?: 'پرداخت انجام نشد.')];
}
// کارِ بعد از پرداخت (یک بار): kind = hx_sub => فعال‌کردنِ اشتراکِ هوشیکس
function pay_fulfil($pdo, $id) {
    $up = $pdo->prepare("UPDATE pay_tx SET done_at = NOW() WHERE id = ? AND status = 'PAID' AND done_at IS NULL");
    $up->execute([$id]);
    if (!$up->rowCount()) return false;
    $t = pay_get($pdo, $id);
    try {
        if ($t['kind'] === 'hx_sub') { require_once __DIR__ . '/_hooshix.php'; hx_pay_fulfil($pdo, $t); }
    } catch (Throwable $e) {
        error_log('[pay_fulfil] ' . $e->getMessage());
        $pdo->prepare("UPDATE pay_tx SET done_at = NULL, error = ? WHERE id = ?")->execute([mb_substr('پرداخت انجام شد ولی فعال‌سازی خطا داد: ' . $e->getMessage(), 0, 500), $id]);
        return false;
    }
    return true;
}

// ---------------- کمکی‌ها ----------------
function pay_json($method, $url, $body, array $headers = [], $timeout = 30) {
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    $r = sms_http($method, $url, $body === null ? null : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $h, $timeout);
    return ['http' => intval($r['http']), 'error' => (string)$r['error'], 'body' => (string)$r['body'], 'j' => json_decode((string)$r['body'], true)];
}
function pay_form($url, array $fields, array $headers = [], $timeout = 30) {
    $r = sms_http('POST', $url, http_build_query($fields), array_merge(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $headers), $timeout);
    return ['http' => intval($r['http']), 'error' => (string)$r['error'], 'body' => (string)$r['body'], 'j' => json_decode((string)$r['body'], true)];
}
function pay_raw($r) { return mb_substr(($r['error'] !== '' ? 'ERR ' . $r['error'] . ' ' : '') . 'HTTP ' . $r['http'] . ' ' . $r['body'], 0, 3000); }
// جوابِ قطعی نیامد (قطعیِ اینترنت / خطای ۵xx)
function pay_unsure($r) { return $r['error'] !== '' || $r['http'] === 0 || $r['http'] >= 500; }
function pay_mobile($m) { $m = preg_replace('/\D/', '', p2e_digits((string)$m)); if (preg_match('/^(?:98|0)?(9\d{9})$/', $m, $x)) return '0' . $x[1]; return ''; }
function pay_rq(array $req, $k) { foreach ([$k, strtolower($k), ucfirst($k)] as $kk) if (isset($req[$kk]) && is_scalar($req[$kk])) return trim((string)$req[$kk]); return ''; }

// ---------------- زرین‌پال (v4) ----------------
function pay_zp_host(array $g) { return $g['sandbox'] ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com'; }
function pay_drv_zarinpal_start($pdo, array $g, array $t, $cb) {
    $m = trim((string)($g['cfg']['merchant_id'] ?? ''));
    if ($m === '') return ['ok' => false, 'error' => 'مرچنت کد وارد نشده.'];
    $body = ['merchant_id' => $m, 'amount' => intval($t['amount']), 'currency' => 'IRR', 'callback_url' => $cb, 'description' => $t['title'] ?: 'پرداخت'];
    if ($t['mobile']) $body['metadata'] = ['mobile' => $t['mobile']];
    $r = pay_json('POST', pay_zp_host($g) . '/pg/v4/payment/request.json', $body);
    $d = $r['j']['data'] ?? [];
    if (intval($d['code'] ?? 0) !== 100 || empty($d['authority'])) return ['ok' => false, 'error' => pay_zp_err($r), 'raw' => pay_raw($r)];
    return ['ok' => true, 'authority' => $d['authority'], 'url' => pay_zp_host($g) . '/pg/StartPay/' . rawurlencode($d['authority']), 'method' => 'GET'];
}
function pay_zp_err($r) { $e = $r['j']['errors'] ?? []; return is_array($e) && !empty($e['message']) ? $e['message'] . ' (' . ($e['code'] ?? '') . ')' : ($r['error'] ?: 'HTTP ' . $r['http']); }
function pay_drv_zarinpal_verify($pdo, array $g, array $t, array $req) {
    if (pay_rq($req, 'Status') !== 'OK') return ['ok' => false, 'cancel' => true, 'error' => 'پرداخت لغو شد یا ناموفق بود.'];
    if (pay_rq($req, 'Authority') !== (string)$t['authority']) return ['ok' => false, 'error' => 'شناسه‌ی پرداخت با درخواست یکی نیست.'];
    $r = pay_json('POST', pay_zp_host($g) . '/pg/v4/payment/verify.json', ['merchant_id' => trim((string)$g['cfg']['merchant_id']), 'amount' => intval($t['amount']), 'authority' => $t['authority']]);
    if (pay_unsure($r)) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تأیید از زرین‌پال نرسید.'];
    $d = $r['j']['data'] ?? [];
    if (!in_array(intval($d['code'] ?? 0), [100, 101], true)) return ['ok' => false, 'error' => pay_zp_err($r), 'raw' => pay_raw($r)];
    return ['ok' => true, 'ref' => (string)($d['ref_id'] ?? ''), 'card' => (string)($d['card_pan'] ?? ''), 'raw' => pay_raw($r)];
}

// ---------------- زیبال ----------------
function pay_drv_zibal_start($pdo, array $g, array $t, $cb) {
    $m = trim((string)($g['cfg']['merchant'] ?? ''));
    if ($m === '') return ['ok' => false, 'error' => 'مرچنت وارد نشده.'];
    $body = ['merchant' => $m, 'amount' => intval($t['amount']), 'callbackUrl' => $cb, 'orderId' => 'HX' . $t['id'], 'description' => $t['title'] ?: 'پرداخت'];
    if ($t['mobile']) $body['mobile'] = $t['mobile'];
    $r = pay_json('POST', 'https://gateway.zibal.ir/v1/request', $body);
    if (intval($r['j']['result'] ?? 0) !== 100 || empty($r['j']['trackId'])) return ['ok' => false, 'error' => (string)($r['j']['message'] ?? ($r['error'] ?: 'HTTP ' . $r['http'])), 'raw' => pay_raw($r)];
    return ['ok' => true, 'authority' => (string)$r['j']['trackId'], 'url' => 'https://gateway.zibal.ir/start/' . rawurlencode((string)$r['j']['trackId']), 'method' => 'GET'];
}
function pay_drv_zibal_verify($pdo, array $g, array $t, array $req) {
    if (pay_rq($req, 'success') !== '1') return ['ok' => false, 'cancel' => true, 'error' => 'پرداخت لغو شد یا ناموفق بود.'];
    if (pay_rq($req, 'trackId') !== (string)$t['authority']) return ['ok' => false, 'error' => 'شناسه‌ی پرداخت با درخواست یکی نیست.'];
    $r = pay_json('POST', 'https://gateway.zibal.ir/v1/verify', ['merchant' => trim((string)$g['cfg']['merchant']), 'trackId' => $t['authority']]);
    if (pay_unsure($r)) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تأیید از زیبال نرسید.'];
    $j = $r['j'] ?: [];
    if (!in_array(intval($j['result'] ?? 0), [100, 201], true)) return ['ok' => false, 'error' => (string)($j['message'] ?? 'تأیید نشد'), 'raw' => pay_raw($r)];
    if (isset($j['amount']) && intval($j['amount']) !== intval($t['amount'])) return ['ok' => false, 'error' => 'مبلغِ پرداخت‌شده با مبلغِ سفارش یکی نیست.', 'raw' => pay_raw($r)];
    return ['ok' => true, 'ref' => (string)($j['refNumber'] ?? ''), 'card' => (string)($j['cardNumber'] ?? ''), 'raw' => pay_raw($r)];
}

// ---------------- دیجی‌پی ----------------
function pay_dg_base(array $g) { return rtrim(trim((string)($g['cfg']['base_url'] ?? '')) ?: 'https://api.mydigipay.com', '/'); }
function pay_dg_token(array $g) {
    $c = $g['cfg'];
    $r = pay_form(pay_dg_base($g) . '/digipay/api/oauth/token', ['username' => (string)($c['username'] ?? ''), 'password' => (string)($c['password'] ?? ''), 'grant_type' => 'password'],
                  ['Authorization: Basic ' . base64_encode(($c['client_id'] ?? '') . ':' . ($c['client_secret'] ?? ''))]);
    if ($r['http'] !== 200 || empty($r['j']['access_token'])) throw new Exception($r['http'] === 401 ? 'نام کاربری/رمز یا Client دیجی‌پی درست نیست.' : 'ورود به دیجی‌پی ممکن نشد (' . ($r['error'] ?: 'HTTP ' . $r['http']) . ').');
    return (string)$r['j']['access_token'];
}
function pay_drv_digipay_start($pdo, array $g, array $t, $cb) {
    $tok = pay_dg_token($g);
    $type = intval($g['cfg']['type'] ?? 0) ?: 11;
    $pid = 'HX' . $t['id'] . 'T' . time();
    $body = ['amount' => intval($t['amount']), 'cellNumber' => $t['mobile'] ?: '', 'providerId' => $pid, 'callbackUrl' => $cb];
    $r = pay_json('POST', pay_dg_base($g) . '/digipay/api/tickets/business?type=' . $type, $body, ['Authorization: Bearer ' . $tok, 'Agent: WEB', 'Digipay-Version: 2022-02-02']);
    if ($r['http'] !== 200 || empty($r['j']['redirectUrl'])) return ['ok' => false, 'error' => (string)($r['j']['result']['message'] ?? ($r['error'] ?: 'HTTP ' . $r['http'])), 'raw' => pay_raw($r)];
    return ['ok' => true, 'authority' => $pid, 'url' => (string)$r['j']['redirectUrl'], 'method' => 'GET', 'meta' => ['dg_ticket' => (string)($r['j']['ticket'] ?? '')]];
}
function pay_drv_digipay_verify($pdo, array $g, array $t, array $req) {
    $res = strtoupper(pay_rq($req, 'result'));
    if ($res !== '' && $res !== 'SUCCESS') return ['ok' => false, 'cancel' => true, 'error' => 'پرداخت لغو شد یا ناموفق بود.'];
    if (pay_rq($req, 'providerId') !== (string)$t['authority']) return ['ok' => false, 'error' => 'شناسه‌ی پرداخت با درخواست یکی نیست.'];
    if (pay_rq($req, 'amount') !== '' && intval(pay_rq($req, 'amount')) !== intval($t['amount'])) return ['ok' => false, 'error' => 'مبلغِ پرداخت‌شده با مبلغِ سفارش یکی نیست.'];
    $tc = pay_rq($req, 'trackingCode');
    if ($tc === '' || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $tc)) return ['ok' => false, 'error' => 'کدِ پیگیری از دیجی‌پی نرسید.'];
    $type = preg_replace('/\D/', '', pay_rq($req, 'type')) ?: (intval($g['cfg']['type'] ?? 0) ?: 11);
    $tok = pay_dg_token($g);
    $r = pay_json('POST', pay_dg_base($g) . '/digipay/api/purchases/verify/' . rawurlencode($tc) . '?type=' . $type, '{}', ['Authorization: Bearer ' . $tok]);
    if (pay_unsure($r)) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تأیید از دیجی‌پی نرسید.'];
    if ($r['http'] !== 200) return ['ok' => false, 'error' => (string)($r['j']['result']['message'] ?? 'تأیید نشد'), 'raw' => pay_raw($r)];
    if (isset($r['j']['amount']) && intval($r['j']['amount']) !== intval($t['amount'])) return ['ok' => false, 'error' => 'مبلغِ تأییدشده با مبلغِ سفارش یکی نیست.', 'raw' => pay_raw($r)];
    return ['ok' => true, 'ref' => (string)($r['j']['trackingCode'] ?? $tc), 'card' => (string)($r['j']['maskedPan'] ?? ''), 'raw' => pay_raw($r)];
}

// ---------------- اسنپ‌پی (اقساطی) ----------------
function pay_sp_base(array $g) { return rtrim(trim((string)($g['cfg']['base_url'] ?? '')), '/'); }
function pay_sp_token(array $g) {
    $c = $g['cfg'];
    if (pay_sp_base($g) === '') throw new Exception('آدرسِ API اسنپ‌پی وارد نشده.');
    $r = pay_form(pay_sp_base($g) . '/api/online/v1/oauth/token', ['grant_type' => 'password', 'scope' => 'online-merchant', 'username' => (string)($c['username'] ?? ''), 'password' => (string)($c['password'] ?? '')],
                  ['Authorization: Basic ' . base64_encode(($c['client_id'] ?? '') . ':' . ($c['client_secret'] ?? ''))], 15);
    if ($r['http'] !== 200 || empty($r['j']['access_token'])) throw new Exception('ورود به اسنپ‌پی ممکن نشد (' . ($r['error'] ?: 'HTTP ' . $r['http']) . ').');
    return (string)$r['j']['access_token'];
}
function pay_drv_snapppay_start($pdo, array $g, array $t, $cb) {
    if (!$t['mobile']) return ['ok' => false, 'error' => 'برای اسنپ‌پی شماره‌ی موبایلِ شما لازم است (در پروفایلِ کاربری ثبت کنید).'];
    $tok = pay_sp_token($g);
    $a = intval($t['amount']);
    $tid = 'HX' . $t['id'] . 'T' . time();
    $body = ['amount' => $a, 'discountAmount' => 0, 'externalSourceAmount' => 0, 'mobile' => '+98' . substr($t['mobile'], 1), 'paymentMethodTypeDto' => 'INSTALLMENT', 'returnURL' => $cb, 'transactionId' => $tid,
             'cartList' => [['cartId' => 1, 'cartItems' => [['amount' => $a, 'category' => 'اشتراک', 'count' => 1, 'id' => 1, 'name' => $t['title'] ?: 'اشتراک', 'commissionType' => 100]],
                             'isShipmentIncluded' => false, 'isTaxIncluded' => true, 'shippingAmount' => 0, 'taxAmount' => 0, 'totalAmount' => $a]]];
    $r = pay_json('POST', pay_sp_base($g) . '/api/online/payment/v1/token', $body, ['Authorization: Bearer ' . $tok]);
    $d = $r['j']['response'] ?? [];
    if ($r['http'] !== 200 || (isset($r['j']['successful']) && !$r['j']['successful']) || empty($d['paymentToken']) || empty($d['paymentPageUrl']))
        return ['ok' => false, 'error' => (string)($r['j']['errorData']['message'] ?? ($r['error'] ?: 'HTTP ' . $r['http'])), 'raw' => pay_raw($r)];
    return ['ok' => true, 'authority' => (string)$d['paymentToken'], 'url' => (string)$d['paymentPageUrl'], 'method' => 'GET', 'meta' => ['sp_tid' => $tid]];
}
function pay_drv_snapppay_verify($pdo, array $g, array $t, array $req) {
    $state = strtoupper(pay_rq($req, 'state'));
    if ($state !== '' && $state !== 'OK') return ['ok' => false, 'cancel' => true, 'error' => 'پرداخت لغو شد یا ناموفق بود.'];
    $tok = pay_sp_token($g);
    $H = ['Authorization: Bearer ' . $tok];
    $r = pay_json('POST', pay_sp_base($g) . '/api/online/payment/v1/verify', ['paymentToken' => $t['authority']], $H, 60);
    if (pay_unsure($r)) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تأیید از اسنپ‌پی نرسید.'];
    if ($r['http'] !== 200 || (isset($r['j']['successful']) && !$r['j']['successful'])) return ['ok' => false, 'error' => (string)($r['j']['errorData']['message'] ?? 'تأیید نشد'), 'raw' => pay_raw($r)];
    $s = pay_json('POST', pay_sp_base($g) . '/api/online/payment/v1/settle', ['paymentToken' => $t['authority']], $H, 60);
    if ($s['http'] !== 200 || (isset($s['j']['successful']) && !$s['j']['successful'])) {
        if (!pay_unsure($s)) pay_json('POST', pay_sp_base($g) . '/api/online/payment/v1/revert', ['paymentToken' => $t['authority']], $H, 30);
        return ['ok' => false, 'retry' => pay_unsure($s), 'error' => 'تسویه‌ی اسنپ‌پی انجام نشد.', 'raw' => pay_raw($s)];
    }
    return ['ok' => true, 'ref' => (string)($r['j']['response']['transactionId'] ?? ''), 'raw' => pay_raw($s)];
}

// ---------------- بانک سامان (SEP) ----------------
function pay_drv_sep_start($pdo, array $g, array $t, $cb) {
    $tid = trim((string)($g['cfg']['terminal_id'] ?? ''));
    if ($tid === '') return ['ok' => false, 'error' => 'شماره ترمینال وارد نشده.'];
    $body = ['action' => 'token', 'TerminalId' => $tid, 'Amount' => intval($t['amount']), 'ResNum' => 'HX' . $t['id'], 'RedirectUrl' => $cb];
    if ($t['mobile']) $body['CellNumber'] = $t['mobile'];
    $r = pay_json('POST', 'https://sep.shaparak.ir/onlinepg/onlinepg', $body);
    if (intval($r['j']['status'] ?? 0) !== 1 || empty($r['j']['token'])) return ['ok' => false, 'error' => (string)($r['j']['errorDesc'] ?? ($r['error'] ?: 'HTTP ' . $r['http'])), 'raw' => pay_raw($r)];
    return ['ok' => true, 'authority' => (string)$r['j']['token'], 'url' => 'https://sep.shaparak.ir/OnlinePG/OnlinePG', 'method' => 'POST', 'fields' => ['Token' => (string)$r['j']['token'], 'GetMethod' => 'false']];
}
function pay_drv_sep_verify($pdo, array $g, array $t, array $req) {
    if (intval(pay_rq($req, 'Status')) !== 2) return ['ok' => false, 'cancel' => true, 'error' => 'پرداخت لغو شد یا ناموفق بود (' . pay_rq($req, 'State') . ').'];
    if (pay_rq($req, 'Token') !== '' && pay_rq($req, 'Token') !== (string)$t['authority']) return ['ok' => false, 'error' => 'شناسه‌ی پرداخت با درخواست یکی نیست.'];
    if (pay_rq($req, 'ResNum') !== '' && pay_rq($req, 'ResNum') !== 'HX' . $t['id']) return ['ok' => false, 'error' => 'شماره‌ی سفارش با درخواست یکی نیست.'];
    $ref = pay_rq($req, 'RefNum');
    if ($ref === '') return ['ok' => false, 'error' => 'شماره‌ی مرجع از بانک نرسید.'];
    $r = pay_json('POST', 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction', ['RefNum' => $ref, 'TerminalNumber' => intval($g['cfg']['terminal_id'] ?? 0)]);
    if (pay_unsure($r)) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تأیید از بانک سامان نرسید.'];
    $j = $r['j'] ?: [];
    if (intval($j['ResultCode'] ?? -1) !== 0) return ['ok' => false, 'error' => (string)($j['ResultDescription'] ?? 'تأیید نشد'), 'raw' => pay_raw($r)];
    $d = $j['TransactionDetail'] ?? [];
    if (isset($d['AffectiveAmount']) && intval($d['AffectiveAmount']) !== intval($t['amount'])) return ['ok' => false, 'error' => 'مبلغِ پرداخت‌شده با مبلغِ سفارش یکی نیست.', 'raw' => pay_raw($r)];
    return ['ok' => true, 'ref' => (string)($d['RRN'] ?? $ref), 'card' => (string)($d['MaskedPan'] ?? pay_rq($req, 'SecurePan')), 'raw' => pay_raw($r)];
}

// ---------------- بانک ملت (به‌پرداخت، SOAP) ----------------
function pay_bpm_call($method, array $params) {
    $x = '';
    foreach ($params as $k => $v) $x .= '<' . $k . '>' . htmlspecialchars((string)$v, ENT_XML1) . '</' . $k . '>';
    $env = '<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:int="http://interfaces.core.sw.bps.com/"><soapenv:Body>'
         . '<int:' . $method . '>' . $x . '</int:' . $method . '></soapenv:Body></soapenv:Envelope>';
    $r = sms_http('POST', 'https://bpm.shaparak.ir/pgwchannel/services/pgw', $env, ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'], 30);
    $ret = preg_match('~<return>([^<]*)</return>~', (string)$r['body'], $m) ? trim($m[1]) : null;
    return ['http' => intval($r['http']), 'error' => (string)$r['error'], 'body' => (string)$r['body'], 'ret' => $ret];
}
function pay_drv_mellat_start($pdo, array $g, array $t, $cb) {
    $c = $g['cfg'];
    if (trim((string)($c['terminal_id'] ?? '')) === '') return ['ok' => false, 'error' => 'شماره ترمینال وارد نشده.'];
    $tz = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $r = pay_bpm_call('bpPayRequest', ['terminalId' => $c['terminal_id'], 'userName' => $c['username'] ?? '', 'userPassword' => $c['password'] ?? '', 'orderId' => intval($t['id']), 'amount' => intval($t['amount']),
                                       'localDate' => $tz->format('Ymd'), 'localTime' => $tz->format('His'), 'additionalData' => mb_substr((string)$t['title'], 0, 100), 'callBackUrl' => $cb, 'payerId' => 0]);
    $p = explode(',', (string)$r['ret']);
    if ($r['ret'] === null || $p[0] !== '0' || empty($p[1])) return ['ok' => false, 'error' => $r['ret'] !== null ? 'کدِ خطای بانک ملت: ' . $p[0] : ($r['error'] ?: 'HTTP ' . $r['http']), 'raw' => mb_substr($r['body'], 0, 2000)];
    $f = ['RefId' => $p[1]];
    if ($t['mobile']) $f['MobileNo'] = '98' . substr($t['mobile'], 1);
    return ['ok' => true, 'authority' => $p[1], 'url' => 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat', 'method' => 'POST', 'fields' => $f];
}
function pay_drv_mellat_verify($pdo, array $g, array $t, array $req) {
    if (pay_rq($req, 'ResCode') !== '0') return ['ok' => false, 'cancel' => true, 'error' => 'پرداخت لغو شد یا ناموفق بود (کد ' . pay_rq($req, 'ResCode') . ').'];
    if (pay_rq($req, 'RefId') !== (string)$t['authority'] || pay_rq($req, 'SaleOrderId') !== (string)$t['id']) return ['ok' => false, 'error' => 'شناسه‌ی پرداخت با درخواست یکی نیست.'];
    $c = $g['cfg']; $sr = pay_rq($req, 'SaleReferenceId');
    if (!preg_match('/^\d{1,30}$/', $sr)) return ['ok' => false, 'error' => 'شماره‌ی مرجع از بانک نرسید.'];
    $P = ['terminalId' => $c['terminal_id'] ?? '', 'userName' => $c['username'] ?? '', 'userPassword' => $c['password'] ?? '', 'orderId' => intval($t['id']), 'saleOrderId' => intval($t['id']), 'saleReferenceId' => $sr];
    $v = pay_bpm_call('bpVerifyRequest', $P);
    if ($v['ret'] === null) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تأیید از بانک ملت نرسید.'];
    if (!in_array($v['ret'], ['0', '43'], true)) { pay_bpm_call('bpReversalRequest', $P); return ['ok' => false, 'error' => 'تأییدِ بانک ملت انجام نشد (کد ' . $v['ret'] . ').', 'raw' => mb_substr($v['body'], 0, 2000)]; }
    $s = pay_bpm_call('bpSettleRequest', $P);
    if ($s['ret'] === null) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تسویه از بانک ملت نرسید.'];
    if (!in_array($s['ret'], ['0', '45'], true)) { pay_bpm_call('bpReversalRequest', $P); return ['ok' => false, 'error' => 'تسویه‌ی بانک ملت انجام نشد (کد ' . $s['ret'] . ').']; }
    return ['ok' => true, 'ref' => $sr, 'card' => pay_rq($req, 'CardHolderPan'), 'raw' => 'verify=' . $v['ret'] . ' settle=' . $s['ret']];
}

// ---------------- بانک ملی (سداد) ----------------
function pay_sadad_sign(array $g, $s) {
    $key = base64_decode((string)($g['cfg']['key'] ?? ''), true);
    if (!$key) throw new Exception('کلیدِ ترمینالِ سداد درست نیست (باید Base64 باشد).');
    $e = openssl_encrypt($s, 'DES-EDE3', $key, OPENSSL_RAW_DATA);
    if ($e === false) throw new Exception('رمزنگاریِ سداد (3DES) روی این هاست ممکن نشد.');
    return base64_encode($e);
}
function pay_drv_sadad_start($pdo, array $g, array $t, $cb) {
    $c = $g['cfg']; $a = intval($t['amount']); $oid = intval($t['id']);
    $tz = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $body = ['MerchantId' => (string)($c['merchant_id'] ?? ''), 'TerminalId' => (string)($c['terminal_id'] ?? ''), 'Amount' => $a, 'OrderId' => $oid, 'LocalDateTime' => $tz->format('m/d/Y g:i:s a'),
             'ReturnUrl' => $cb, 'SignData' => pay_sadad_sign($g, ($c['terminal_id'] ?? '') . ';' . $oid . ';' . $a), 'AdditionalData' => mb_substr((string)$t['title'], 0, 100)];
    if ($t['mobile']) $body['UserId'] = $t['mobile'];
    $r = pay_json('POST', 'https://sadad.shaparak.ir/api/v0/Request/PaymentRequest', $body);
    if ((string)($r['j']['ResCode'] ?? '') !== '0' || empty($r['j']['Token'])) return ['ok' => false, 'error' => (string)($r['j']['Description'] ?? ($r['error'] ?: 'HTTP ' . $r['http'])), 'raw' => pay_raw($r)];
    return ['ok' => true, 'authority' => (string)$r['j']['Token'], 'url' => 'https://sadad.shaparak.ir/Purchase?Token=' . rawurlencode((string)$r['j']['Token']), 'method' => 'GET'];
}
function pay_drv_sadad_verify($pdo, array $g, array $t, array $req) {
    if (pay_rq($req, 'ResCode') !== '0') return ['ok' => false, 'cancel' => true, 'error' => 'پرداخت لغو شد یا ناموفق بود.'];
    if (pay_rq($req, 'token') !== '' && pay_rq($req, 'token') !== (string)$t['authority']) return ['ok' => false, 'error' => 'شناسه‌ی پرداخت با درخواست یکی نیست.'];
    $r = pay_json('POST', 'https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', ['Token' => $t['authority'], 'SignData' => pay_sadad_sign($g, (string)$t['authority'])]);
    if (pay_unsure($r)) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تأیید از سداد نرسید.'];
    $j = $r['j'] ?: [];
    if ((string)($j['ResCode'] ?? '') !== '0') return ['ok' => false, 'error' => (string)($j['Description'] ?? 'تأیید نشد'), 'raw' => pay_raw($r)];
    if (isset($j['Amount']) && intval($j['Amount']) !== intval($t['amount'])) return ['ok' => false, 'error' => 'مبلغِ پرداخت‌شده با مبلغِ سفارش یکی نیست.', 'raw' => pay_raw($r)];
    if (isset($j['OrderId']) && (string)$j['OrderId'] !== (string)$t['id']) return ['ok' => false, 'error' => 'شماره‌ی سفارش با درخواست یکی نیست.', 'raw' => pay_raw($r)];
    return ['ok' => true, 'ref' => (string)($j['RetrivalRefNo'] ?? ($j['SystemTraceNo'] ?? '')), 'raw' => pay_raw($r)];
}

// ---------------- بانک پاسارگاد (PEP) ----------------
function pay_pep_token(array $g) {
    $r = pay_json('POST', 'https://pep.shaparak.ir/dorsa1/token/getToken', ['username' => (string)($g['cfg']['username'] ?? ''), 'password' => (string)($g['cfg']['password'] ?? '')]);
    if (intval($r['j']['resultCode'] ?? -1) !== 0 || empty($r['j']['token'])) throw new Exception('ورود به درگاهِ پاسارگاد ممکن نشد (' . ($r['j']['resultMsg'] ?? ($r['error'] ?: 'HTTP ' . $r['http'])) . ').');
    return (string)$r['j']['token'];
}
function pay_drv_pasargad_start($pdo, array $g, array $t, $cb) {
    $tok = pay_pep_token($g);
    $inv = $t['id'] . date('His');
    $tz = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $body = ['invoice' => $inv, 'invoiceDate' => $tz->format('Y/m/d H:i:s'), 'amount' => intval($t['amount']), 'callbackApi' => $cb, 'serviceCode' => '8', 'serviceType' => 'PURCHASE',
             'terminalNumber' => intval($g['cfg']['terminal'] ?? 0), 'description' => mb_substr((string)$t['title'], 0, 100)];
    if ($t['mobile']) $body['mobileNumber'] = $t['mobile'];
    $r = pay_json('POST', 'https://pep.shaparak.ir/dorsa1/api/payment/purchase', $body, ['Authorization: Bearer ' . $tok]);
    if (intval($r['j']['resultCode'] ?? -1) !== 0 || empty($r['j']['data']['url'])) return ['ok' => false, 'error' => (string)($r['j']['resultMsg'] ?? ($r['error'] ?: 'HTTP ' . $r['http'])), 'raw' => pay_raw($r)];
    return ['ok' => true, 'authority' => $inv, 'url' => (string)$r['j']['data']['url'], 'method' => 'GET', 'meta' => ['pep_url_id' => (string)($r['j']['data']['urlId'] ?? '')]];
}
function pay_drv_pasargad_verify($pdo, array $g, array $t, array $req) {
    $inv = pay_rq($req, 'invoiceId');
    if ($inv !== '' && $inv !== (string)$t['authority']) return ['ok' => false, 'error' => 'شماره‌ی سفارش با درخواست یکی نیست.'];
    $st = strtolower(pay_rq($req, 'status'));
    if ($st !== '' && !in_array($st, ['success', '0', 'ok'], true)) return ['ok' => false, 'cancel' => true, 'error' => 'پرداخت لغو شد یا ناموفق بود.'];
    $tok = pay_pep_token($g);
    $H = ['Authorization: Bearer ' . $tok];
    $q = pay_json('POST', 'https://pep.shaparak.ir/dorsa1/api/payment/payment-inquiry', ['invoiceId' => $t['authority']], $H);
    if (pay_unsure($q)) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ استعلام از بانک پاسارگاد نرسید.'];
    $d = $q['j']['data'] ?? [];
    if (intval($q['j']['resultCode'] ?? -1) !== 0 || intval($d['status'] ?? -1) !== 0) return ['ok' => false, 'error' => (string)($q['j']['resultMsg'] ?? 'پرداخت انجام نشده.'), 'raw' => pay_raw($q)];
    if (isset($d['amount']) && intval($d['amount']) !== intval($t['amount'])) return ['ok' => false, 'error' => 'مبلغِ پرداخت‌شده با مبلغِ سفارش یکی نیست.', 'raw' => pay_raw($q)];
    $c = pay_json('POST', 'https://pep.shaparak.ir/dorsa1/api/payment/confirm-transactions', ['invoice' => $t['authority'], 'urlId' => (string)($d['url'] ?? ($t['meta']['pep_url_id'] ?? ''))], $H);
    if (pay_unsure($c)) return ['ok' => false, 'retry' => true, 'error' => 'جوابِ تأیید از بانک پاسارگاد نرسید.'];
    if (intval($c['j']['resultCode'] ?? -1) !== 0) return ['ok' => false, 'error' => (string)($c['j']['resultMsg'] ?? 'تأیید نشد'), 'raw' => pay_raw($c)];
    return ['ok' => true, 'ref' => (string)($d['referenceNumber'] ?? ($d['trackId'] ?? '')), 'card' => (string)($c['j']['data']['maskedCardNumber'] ?? ''), 'raw' => pay_raw($c)];
}

// ---------------- آزمایشی (بدونِ پول؛ فقط مدیر کل می‌تواند بسازد) ----------------
function pay_drv_test_start($pdo, array $g, array $t, $cb) {
    return ['ok' => true, 'authority' => 'T' . $t['id'] . bin2hex(random_bytes(4)), 'url' => $cb . '&test=1', 'method' => 'GET'];
}
function pay_drv_test_verify($pdo, array $g, array $t, array $req) {
    if (pay_rq($req, 'test_result') !== 'ok') return ['ok' => false, 'cancel' => true, 'error' => 'پرداختِ آزمایشی لغو شد.'];
    return ['ok' => true, 'ref' => 'TEST-' . date('ymdHis'), 'card' => '6037-****-****-0000', 'raw' => 'test'];
}
