<?php
// فایل: api/pay_callback.php
// بازگشت از درگاهِ پرداخت (api/_pay.php): ?tx=ID&s=امضا — بدونِ نیاز به نشستِ کاربر (درگاه‌ها با POSTِ میان‌سایتی برمی‌گردند و کوکی نمی‌آید).
// امنیت: امضای HMACِ همین پرداخت + تأییدِ نهایی با APIِ خودِ درگاه سمتِ سرور (فقط یک بار) + مقایسه‌ی مبلغ. خروجی: صفحه‌ی نتیجه.
ini_set('display_errors', '0');
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_pay.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; font-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$id = intval($_GET['tx'] ?? 0);
$sig = (string)($_GET['s'] ?? '');
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$fa = function ($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); };
$page = function ($ok, $title, $body, $actions = '') use ($h) {
    $c = $ok === null ? '#7c3aed' : ($ok ? '#059669' : '#e11d48');
    $ic = $ok === null ? '🧪' : ($ok ? '✓' : '✕');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($title) . '</title><style>'
       . "@font-face{font-family:Vazir;src:url('../Font/Vazir-Variable.woff2') format('woff2');font-weight:100 900}"
       . 'body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Vazir,Tahoma,sans-serif;background:radial-gradient(120% 120% at 0% 0%,#312e81,#0f172a 55%,#020617);color:#0f172a;padding:16px;box-sizing:border-box}'
       . '.c{background:#fff;border-radius:26px;max-width:440px;width:100%;padding:28px 24px;text-align:center;box-shadow:0 40px 80px -40px #000}'
       . '.i{width:72px;height:72px;border-radius:50%;margin:0 auto 14px;display:grid;place-items:center;font-size:34px;font-weight:900;color:#fff;background:' . $c . ';box-shadow:0 0 0 8px ' . $c . '22}'
       . 'h1{font-size:19px;margin:0 0 8px}p{font-size:13px;line-height:2;color:#475569;margin:4px 0}table{width:100%;margin:14px 0;border-collapse:collapse;font-size:12.5px}td{padding:7px 4px;border-top:1px solid #f1f5f9}td:first-child{color:#64748b}td:last-child{font-weight:800;text-align:left}'
       . '.b{display:inline-block;margin:6px 4px 0;padding:11px 18px;border-radius:14px;font-weight:900;font-size:13px;text-decoration:none;color:#fff;background:linear-gradient(120deg,#7c3aed,#06b6d4);border:0;cursor:pointer;font-family:inherit}.b.g{background:#f1f5f9;color:#334155}'
       . '</style></head><body><div class="c"><div class="i">' . $ic . '</div><h1>' . $h($title) . '</h1>' . $body . $actions . '</div></body></html>';
    exit;
};
$back = '<a class="b" href="../dashboard.php#hooshix">بازگشت به هوشیکس</a>';
if (!$id || !hash_equals(pay_sig($pdo, $id), $sig)) { http_response_code(403); $page(false, 'پیوندِ پرداخت نامعتبر است', '<p>این آدرس معتبر نیست.</p>', $back); }
$t = pay_get($pdo, $id);
if (!$t) { http_response_code(404); $page(false, 'پرداخت پیدا نشد', '', $back); }

// درگاهِ آزمایشی: صفحه‌ی انتخابِ نتیجه (بدونِ پول)
if ($t['gateway_type'] === 'test' && !empty($_GET['test']) && !isset($_GET['test_result']) && $t['status'] === 'REDIRECT') {
    $u = 'pay_callback.php?tx=' . $id . '&s=' . $h($sig);
    $page(null, 'پرداختِ آزمایشی', '<p>این درگاه پول جابه‌جا نمی‌کند و فقط برای آزمایشِ روندِ خرید است.</p><p><b>' . $h($t['title']) . '</b> — ' . $fa(number_format($t['amount'] / 10)) . ' تومان</p>',
          '<a class="b" href="' . $u . '&test_result=ok">پرداختِ موفق</a><a class="b g" href="' . $u . '&test_result=fail">لغو</a>');
}

$req = $_POST + $_GET;
unset($req['tx'], $req['s'], $req['test']);
@set_time_limit(90);
$r = pay_verify($pdo, $id, $req);
$t = $r['tx'] ?? $t;
$rows = '<table><tr><td>بابتِ</td><td>' . $h($t['title']) . '</td></tr><tr><td>مبلغ</td><td>' . $fa(number_format($t['amount'] / 10)) . ' تومان</td></tr>'
      . ($t['ref_no'] ? '<tr><td>کدِ پیگیری</td><td dir="ltr">' . $h($fa($t['ref_no'])) . '</td></tr>' : '')
      . ($t['card'] ? '<tr><td>کارت</td><td dir="ltr">' . $h($fa($t['card'])) . '</td></tr>' : '')
      . '<tr><td>شماره‌ی سفارش</td><td>' . $fa('HX' . $t['id']) . '</td></tr></table>';
if (!empty($r['ok'])) {
    $note = !empty($t['done_at']) ? '<p>اشتراکِ شما فعال شد؛ از همین حالا سهمیه‌ی بیشتر دارید.</p>' : '<p>پرداخت انجام شد. اگر اشتراک فعال نشد، با کدِ پیگیری به مدیر خبر دهید.</p>';
    $page(true, 'پرداخت موفق بود', $note . $rows, $back);
}
$page(false, $t['status'] === 'CANCELLED' ? 'پرداخت لغو شد' : 'پرداخت ناموفق بود', '<p>' . $h($r['error'] ?? '') . '</p><p>اگر مبلغی از حسابتان کم شده، طیِ حداکثر ۷۲ ساعت خودکار برمی‌گردد.</p>' . $rows, $back);
