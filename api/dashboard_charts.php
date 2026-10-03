<?php
// فایل: api/dashboard_charts.php
// داده‌ی دو نمودارِ داشبورد: «تعداد بیمه‌نامه‌های صادره» و «مبلغ فروش حق بیمه».
//   منبع: پرسنلی (policy_cases صادرشده) و شرکتی (company_request_plates صادرشده)
//   بازه: امروز (ساعتی) | ۳۰ روز اخیر (روزانه) | ۳ ماه (هفتگی) | ۶ ماه و یک سال (ماهِ شمسی)
//         | یک سالِ شمسیِ خاص | بازه‌ی دلخواهِ شمسی (از ماه ... تا ماه ...)
// همه‌ی برچسب‌ها شمسی‌اند. جمعِ دوره‌ی قبلِ هم‌طول هم برای مقایسه برگردانده می‌شود.
// شرکتی‌ها را فقط مدیر کل می‌بیند (بقیه‌ی نقش‌های داشبورد به ماژول شرکت‌ها دسترسی ندارند).
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';

function out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'COMPANY_LIAISON') out(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$seesCompanies = ($_SESSION['role'] ?? '') === 'ADMIN';
$source = in_array($data['source'] ?? '', ['ALL', 'COMPANY', 'PERSONNEL'], true) ? $data['source'] : 'ALL';
if (!$seesCompanies) $source = 'PERSONNEL';
$period = in_array($data['period'] ?? '', ['TODAY', 'MONTH', '3M', '6M', 'YEAR', 'JYEAR', 'RANGE'], true) ? $data['period'] : '6M';

// ---------- کمکی‌های تاریخ ----------
function jmonth_start($jy, $jm) { return jalali_to_gregorian_ts($jy, $jm, 1); }
function jmonth_add($jy, $jm, $n) { return company_add_months_jalali($jy, $jm, $n); }
function jlabel_day($ts) { [, $jm, $jd] = jalali_from_gregorian_ts($ts); return fa_digits($jd) . ' ' . jalali_month_name($jm); }

$now = time();
$today = strtotime('today', $now);
[$cy, $cm] = jalali_from_gregorian_ts($now);

// ---------- بازه و نوعِ دسته‌بندی ----------
$bucket = 'month';
switch ($period) {
    case 'TODAY': $start = $today; $end = strtotime('+1 day', $today); $bucket = 'hour'; break;
    case 'MONTH': $start = strtotime('-29 days', $today); $end = strtotime('+1 day', $today); $bucket = 'day'; break;
    case '3M':
        // ۱۳ هفته‌ی اخیر؛ هفته از شنبه شروع می‌شود
        $sinceSat = (intval(date('w', $today)) + 1) % 7;
        $start = strtotime('-' . ($sinceSat + 12 * 7) . ' days', $today); $end = strtotime('+1 day', $today); $bucket = 'week'; break;
    case '6M': case 'YEAR':
        $n = $period === '6M' ? 6 : 12;
        [$fy, $fm] = jmonth_add($cy, $cm, -($n - 1));
        $start = jmonth_start($fy, $fm); [$ny, $nm] = jmonth_add($cy, $cm, 1); $end = jmonth_start($ny, $nm); break;
    case 'JYEAR':
        $jy = intval($data['year'] ?? $cy); if ($jy < 1390 || $jy > $cy + 1) $jy = $cy;
        $start = jmonth_start($jy, 1); $end = jmonth_start($jy + 1, 1); break;
    case 'RANGE':
        $fy = intval($data['from_y'] ?? $cy); $fm = max(1, min(12, intval($data['from_m'] ?? 1)));
        $ty = intval($data['to_y'] ?? $cy);   $tm = max(1, min(12, intval($data['to_m'] ?? $cm)));
        if ($fy < 1390 || $fy > $cy + 1) $fy = $cy;
        if ($ty < 1390 || $ty > $cy + 1) $ty = $cy;
        if ($fy * 12 + $fm > $ty * 12 + $tm) { [$fy, $fm, $ty, $tm] = [$ty, $tm, $fy, $fm]; }
        $span = ($ty * 12 + $tm) - ($fy * 12 + $fm) + 1;   // تعداد ماه‌ها
        if ($span > 60) out(['ok' => false, 'error' => 'بازه حداکثر ۵ سال (۶۰ ماه) می‌تواند باشد.']);
        $start = jmonth_start($fy, $fm); [$ny, $nm] = jmonth_add($ty, $tm, 1); $end = jmonth_start($ny, $nm);
        $bucket = $span === 1 ? 'day' : ($span <= 3 ? 'week' : 'month');
        if ($bucket === 'week') { $sinceSat = (intval(date('w', $start)) + 1) % 7; $start = strtotime("-$sinceSat days", $start); }
        break;
}

// ---------- ساختنِ دسته‌ها (شروعِ هر دسته + برچسب شمسی) ----------
$buckets = [];
if ($bucket === 'hour') {
    for ($h = 0; $h < 24; $h++) $buckets[] = ['t' => strtotime("+$h hours", $start), 'label' => fa_digits(sprintf('%02d', $h)), 'sub' => ''];
} elseif ($bucket === 'day' || $bucket === 'week') {
    $step = $bucket === 'day' ? '+1 day' : '+7 days';
    for ($t = $start; $t < $end; $t = strtotime($step, $t)) {
        [$jy, $jm, $jd] = jalali_from_gregorian_ts($t);
        $buckets[] = ['t' => $t, 'label' => fa_digits($jd), 'sub' => jalali_month_name($jm),
                      'full' => ($bucket === 'week' ? 'هفته‌ی ' : '') . jlabel_day($t) . ' ' . fa_digits($jy)];
    }
} else {
    [$jy, $jm] = jalali_from_gregorian_ts($start);
    for ($t = $start; $t < $end; ) {
        $buckets[] = ['t' => $t, 'label' => jalali_month_name($jm), 'sub' => fa_digits($jy), 'full' => jalali_month_name($jm) . ' ' . fa_digits($jy)];
        [$jy, $jm] = jmonth_add($jy, $jm, 1);
        $t = jmonth_start($jy, $jm);
    }
}
foreach ($buckets as &$b) { $b += ['full' => $b['label'] . ':۰۰', 'p_count' => 0, 'c_count' => 0, 'p_amount' => 0, 'c_amount' => 0]; }
unset($b);
$starts = array_column($buckets, 't');

function bucket_index($starts, $ts) {
    $lo = 0; $hi = count($starts) - 1; $ans = -1;
    while ($lo <= $hi) { $mid = ($lo + $hi) >> 1; if ($starts[$mid] <= $ts) { $ans = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }
    return $ans;
}
function money_int($v) { $d = preg_replace('/\D/', '', p2e_digits((string)$v)); return $d === '' ? 0 : intval($d); }

// ---------- داده ----------
function issued_rows($pdo, $kind, $from, $to) {
    $f = date('Y-m-d H:i:s', $from); $t = date('Y-m-d H:i:s', $to);
    try {
        if ($kind === 'P') {
            $st = $pdo->prepare("SELECT COALESCE(NULLIF(issued_at, ''), updated_at) AS at, total_premium FROM policy_cases
                                  WHERE status = 'ISSUED' AND COALESCE(NULLIF(issued_at, ''), updated_at) >= ? AND COALESCE(NULLIF(issued_at, ''), updated_at) < ?");
        } else {
            $st = $pdo->prepare("SELECT issued_at AS at, total_premium FROM company_request_plates WHERE status = 'ISSUED' AND issued_at >= ? AND issued_at < ?");
        }
        $st->execute([$f, $t]);
        return $st->fetchAll();
    } catch (Throwable $e) { error_log('[dashboard_charts] ' . $e->getMessage()); return []; }
}

$totals = ['p_count' => 0, 'c_count' => 0, 'p_amount' => 0, 'c_amount' => 0];
$prev = $totals;
$prevStart = $start - ($end - $start);
foreach (['P' => 'p', 'C' => 'c'] as $kind => $k) {
    if ($source === 'PERSONNEL' && $kind === 'C') continue;
    if ($source === 'COMPANY' && $kind === 'P') continue;
    foreach (issued_rows($pdo, $kind, $start, $end) as $r) {
        $i = bucket_index($starts, strtotime($r['at']));
        if ($i < 0) continue;
        $amt = money_int($r['total_premium']);
        $buckets[$i][$k . '_count']++; $buckets[$i][$k . '_amount'] += $amt;
        $totals[$k . '_count']++; $totals[$k . '_amount'] += $amt;
    }
    foreach (issued_rows($pdo, $kind, $prevStart, $start) as $r) { $prev[$k . '_count']++; $prev[$k . '_amount'] += money_int($r['total_premium']); }
}
foreach ($buckets as &$b) unset($b['t']);
unset($b);

// سال‌هایی که در انتخابگرِ «سال شمسی» نشان داده می‌شوند: از اولین صدور تا امسال
$firstYear = $cy;
try {
    $min = $pdo->query("SELECT MIN(x) FROM (SELECT MIN(COALESCE(NULLIF(issued_at, ''), updated_at)) AS x FROM policy_cases WHERE status = 'ISSUED'
                        UNION ALL SELECT MIN(issued_at) FROM company_request_plates WHERE status = 'ISSUED') m")->fetchColumn();
    if ($min && ($ts = strtotime($min))) $firstYear = min($cy, jalali_from_gregorian_ts($ts)[0]);
} catch (Throwable $e) {}

[$sy, $sm, $sd] = jalali_from_gregorian_ts($start);
[$ey, $em, $ed] = jalali_from_gregorian_ts($end - 1);
out(['ok' => true, 'bucket' => $bucket, 'buckets' => $buckets, 'totals' => $totals, 'prev' => $prev, 'source' => $source,
     'sees_companies' => $seesCompanies,
     'range_label' => fa_digits("$sd ") . jalali_month_name($sm) . fa_digits(" $sy") . ' تا ' . fa_digits("$ed ") . jalali_month_name($em) . fa_digits(" $ey"),
     'years' => range($cy, max(1390, min($firstYear, $cy - 2))), 'current' => ['y' => $cy, 'm' => $cm]]);
