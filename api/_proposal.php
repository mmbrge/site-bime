<?php
// فایل: api/_proposal.php
// «پیشنهاد / پیش‌نویسِ بیمه‌نامه» در صدورِ کارکنان: خواندن با api/py/proposal_reader.py، بایگانی کنارِ مدارکِ درخواست
// و نگه‌داشتن در policy_cases.proposal_json (ستون خودکار ساخته می‌شود؛ به داده‌های قبلی دست نمی‌زند).
// از این اطلاعات: فرمِ صدور پیش‌پر می‌شود، فایلِ بیمه‌نامه با آن مقایسه می‌شود و بعد از صدور در جزئیات دیده می‌شود.

function proposal_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try { if (!$pdo->query("SHOW COLUMNS FROM policy_cases LIKE 'proposal_json'")->fetch()) $pdo->exec("ALTER TABLE policy_cases ADD COLUMN proposal_json MEDIUMTEXT NULL"); }
    catch (Throwable $e) { error_log('[proposal_ensure] ' . $e->getMessage()); }
}

function proposal_get($pdo, $caseId) {
    proposal_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT proposal_json FROM policy_cases WHERE id = ?");
        $st->execute([intval($caseId)]);
        $j = json_decode((string)$st->fetchColumn(), true);
        return is_array($j) ? $j : null;
    } catch (Throwable $e) { return null; }
}

// اجرای خواننده؛ مسیرِ فایل باید انگلیسی و بدونِ فاصله باشد (پایتونِ هاست با مسیرِ فارسی مشکل دارد)
function proposal_run($pdo, $absPdf) {
    if (!function_exists('proc_open')) return ['ok' => false, 'error' => 'اجرای پایتون روی این سرور مجاز نیست (proc_open غیرفعال است).'];
    if (!function_exists('pdf_split_python')) require_once __DIR__ . '/_pdf_split.php';
    $home = getenv('HOME');
    if (!$home && preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m)) $home = $m[1];
    $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $home ?: sys_get_temp_dir(), 'PYTHONWARNINGS' => 'ignore'];
    $proc = @proc_open([pdf_split_python($pdo), __DIR__ . '/py/proposal_reader.py', $absPdf], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'اجرای پایتون ممکن نشد.'];
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $line = trim((string)substr($out, strrpos("\n" . trim($out), "\n")));
    $j = json_decode($line, true);
    return is_array($j) ? $j : ['ok' => false, 'error' => 'خروجیِ خواننده‌ی پیشنهاد خوانده نشد.', 'debug' => mb_substr(trim($err ?: $out), -400)];
}

// نسبتِ بیمه‌گذار و مالک با پرسنلِ معرفی‌نامه از روی پیشنهاد:
//   self_owner   = بیمه‌گذار خودِ پرسنل و مالک هم خودش
//   self_relative= بیمه‌گذار خودِ پرسنل، مالک یکی از بستگان (نامِ مالک = «ذینفع»)
//   relative     = بیمه‌گذار خودِ بستگان (نسبت را کارشناس انتخاب می‌کند)
function proposal_relation(array $d, array $holder) {
    $nid = preg_replace('/\D/', '', (string)($d['national_id'] ?? ''));
    $hn = preg_replace('/\D/', '', (string)($holder['national_code'] ?? ''));
    $insuredIsHolder = $nid !== '' ? $nid === $hn : (trim((string)($d['insured_name'] ?? '')) === trim((string)($holder['full_name'] ?? '')));
    // «پیش‌نویس» سؤالِ «بیمه‌گذار مالک است؟» ندارد: اگر ذینفعی جدا از بیمه‌گذار دارد، مالک همان ذینفع است
    $ben = trim((string)($d['beneficiary'] ?? ''));
    $isOwner = isset($d['insured_is_owner']) ? (bool)$d['insured_is_owner'] : ($ben === '' || $ben === trim((string)($d['insured_name'] ?? '')));
    if ($insuredIsHolder && $isOwner) return ['mode' => 'self_owner', 'owner_name' => $d['insured_name'] ?? ''];
    if ($insuredIsHolder) return ['mode' => 'self_relative', 'owner_name' => $d['beneficiary'] ?? '', 'owner_gender' => $d['beneficiary_gender'] ?? ''];
    return ['mode' => 'relative', 'owner_name' => $isOwner ? ($d['insured_name'] ?? '') : ($d['beneficiary'] ?? ''), 'insured_gender' => $d['insured_gender'] ?? ''];
}
