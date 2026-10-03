<?php
// فایل: api/records.php
// «کارتابل صدور و پرونده‌ها» (معرفی‌نامه‌های کارکنان):
//   GET                     => فهرست معرفی‌نامه‌ها با تاریخ صدور/ثبت، ماهِ معرفی‌نامه، صادره‌ها و حق بیمه
//   GET  ?action=stats      => چهار کارتِ بالای صفحه برای یک بازه (from/to شمسی؛ خالی = کل)
//   POST action=export      => خروجی اکسلِ همان ردیف‌هایی که در جدول (با فیلتر/جستجو) دیده می‌شوند، با جزئیاتِ هر بیمه‌نامه
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require __DIR__ . '/_case_helpers.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.']);
    exit;
}

// «همکار شرکت‌ها» (COMPANY_LIAISON) فقط به شرکت‌ها، بایگانی و مالی دسترسی دارد؛
// منوی این بخش برایش پنهان است و اینجا هم مستقیم جلویش گرفته می‌شود.
if (($_SESSION['role'] ?? '') === 'COMPANY_LIAISON') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// تاریخِ شمسیِ «۱۴۰۵/۰۷/۰۵» (هر رقم و جداکننده‌ای) => «1405/07/05» یا null
function rec_jalali_norm($s) {
    $s = p2e_digits(trim((string)$s));
    return preg_match('/^(\d{4})\D+(\d{1,2})\D+(\d{1,2})$/', $s, $m) ? sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]) : null;
}
function rec_jalali_of_ts($ts) {
    if (!$ts) return null;
    [$y, $m, $d] = jalali_from_gregorian_ts($ts);
    return sprintf('%04d/%02d/%02d', $y, $m, $d);
}
// تاریخِ صدورِ معرفی‌نامه (شمسی) - از خودِ نامه؛ اگر ثبت نشده، null
function rec_letter_date($intro) {
    return !empty($intro['letter_date']) && preg_match('/^(1[34]\d\d)-(\d{1,2})-(\d{1,2})/', (string)$intro['letter_date'], $m)
        ? sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]) : null;
}

// همه‌ی ردیف‌های جدول (هر درخواست/معرفی‌نامه)
function rec_rows($pdo) {
    $records = $pdo->query("
        SELECT ir.id, ir.introduction_id, p.id AS person_id, p.full_name, p.national_code, p.personnel_code, p.mobile_number,
               c.name AS company_name, ir.created_at, i.created_at AS intro_created_at, i.letter_date, i.max_quota, i.used_quota, i.file_path AS intro_file
          FROM insurance_requests ir
          JOIN persons p ON ir.person_id = p.id
          LEFT JOIN companies c ON p.company_id = c.id
          LEFT JOIN introductions i ON ir.introduction_id = i.id
         ORDER BY ir.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // صادره‌ها و حق بیمه‌ی هر معرفی‌نامه یک‌جا (به‌جای چند کوئری برای هر ردیف)
    $cases = [];
    foreach ($pdo->query("SELECT introduction_id, insurance_type, insured_relationship, status, total_premium, issued_at, updated_at FROM policy_cases WHERE COALESCE(status, '') <> 'WITHDRAWN'")->fetchAll() as $c)
        $cases[intval($c['introduction_id'])][] = $c;

    foreach ($records as &$row) {
        $intro = ['letter_date' => $row['letter_date'], 'created_at' => $row['intro_created_at'] ?: $row['created_at']];
        [$ly, $lm] = intro_letter_month($intro);
        $row['letter_date_j'] = rec_letter_date($intro);
        $row['registered_j'] = rec_jalali_of_ts(strtotime((string)$intro['created_at']));
        $row['letter_year'] = $ly;
        $row['letter_month'] = $lm;
        $row['letter_month_fa'] = jalali_month_name($lm);
        // بازه‌ی فیلتر: تاریخِ نامه، وگرنه تاریخِ ثبت
        $row['filter_date'] = $row['letter_date_j'] ?: $row['registered_j'];

        $list = $cases[intval($row['introduction_id'])] ?? [];
        $issued = array_values(array_filter($list, fn($c) => $c['status'] === 'ISSUED'));
        $row['total_cases'] = count($list);
        $row['issued_total'] = count($issued);
        $row['issued_body'] = count(array_filter($issued, fn($c) => $c['insurance_type'] === 'BODY'));
        $row['issued_third'] = $row['issued_total'] - $row['issued_body'];
        // «شمارنده»ی معرفی‌نامه: صادره‌های همان ماهی که معرفی‌نامه صادر شده
        $row['issued_in_letter_month'] = count(array_filter($issued, function ($c) use ($ly, $lm) { [$y, $m] = case_issue_month($c); return $y === $ly && $m === $lm; }));
        $row['premium_total'] = array_sum(array_map(fn($c) => (int)money_to_int($c['total_premium']), $issued));
        $self = count(array_filter($issued, fn($c) => $c['insured_relationship'] === 'خودم'));
        $row['issued_summary'] = $issued ? implode(' / ', array_filter([$row['issued_third'] ? $row['issued_third'] . ' فقره ثالث' : '', $row['issued_body'] ? $row['issued_body'] . ' فقره بدنه' : ''])) : '۰ فقره';
        $row['relationship_summary'] = "خودش: {$self} فقره / بستگان: " . ($row['issued_total'] - $self) . " فقره";
        $row['quota_summary'] = ($row['used_quota'] ?? 0) . ' / ' . ($row['max_quota'] ?? 4);
        // فایلِ معرفی‌نامه در بایگانی هست؟ (مسیر به فرانت داده نمی‌شود؛ دانلود از download_intro)
        $row['has_intro_file'] = !empty($row['intro_file']) && $row['intro_file'] !== 'ثبت_دستی' && is_file(dirname(__DIR__) . '/' . ltrim($row['intro_file'], '/'));
        unset($row['intro_file']);
    }
    unset($row);
    return $records;
}

try {
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');

    // ---------------- دانلودِ فایلِ معرفی‌نامه ----------------
    if ($action === 'download_intro') {
        $st = $pdo->prepare("SELECT i.file_path FROM introductions i WHERE i.id = ?");
        $st->execute([intval($_GET['intro_id'] ?? 0)]);
        $rel = (string)$st->fetchColumn();
        $abs = realpath(dirname(__DIR__) . '/' . ltrim($rel, '/'));
        $root = realpath(archive_root(dirname(__DIR__)));
        if (!$rel || !$abs || !$root || strpos($abs, $root) !== 0 || !is_file($abs)) { echo json_encode(['ok' => false, 'error' => 'فایلِ معرفی‌نامه پیدا نشد.'], JSON_UNESCAPED_UNICODE); exit; }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        header('Content-Type: ' . (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream'));
        header("Content-Disposition: attachment; filename=\"intro.$ext\"; filename*=UTF-8''" . rawurlencode(basename($abs)));
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
        exit;
    }

    // ---------------- کارت‌های بالای صفحه ----------------
    if ($action === 'stats') {
        require_once __DIR__ . '/finance_core.php';
        $from = rec_jalali_norm($_GET['from'] ?? '');
        $to = rec_jalali_norm($_GET['to'] ?? '');
        $gFrom = $from ? fin_jalali_to_date($from) : null;
        $gTo = $to ? fin_jalali_to_date($to) : null;

        // ۱) معرفی‌نامه‌های صادرشده در بازه (تاریخِ نامه؛ اگر نبود تاریخِ ثبت)
        $letters = 0;
        foreach ($pdo->query("SELECT letter_date, created_at FROM introductions")->fetchAll() as $i) {
            $d = rec_letter_date($i) ?: rec_jalali_of_ts(strtotime((string)$i['created_at']));
            if ((!$from || strcmp($d, $from) >= 0) && (!$to || strcmp($d, $to) <= 0)) $letters++;
        }
        // ۲ و ۳) بیمه‌نامه‌های صادرشده و حق بیمه‌ی ایجادشده (تاریخِ صدور)
        $w = ["status = 'ISSUED'"]; $p = [];
        if ($gFrom) { $w[] = "DATE(COALESCE(issued_at, updated_at)) >= ?"; $p[] = $gFrom; }
        if ($gTo)   { $w[] = "DATE(COALESCE(issued_at, updated_at)) <= ?"; $p[] = $gTo; }
        $st = $pdo->prepare("SELECT insurance_type, total_premium FROM policy_cases WHERE " . implode(' AND ', $w));
        $st->execute($p);
        $body = 0; $third = 0; $premium = 0; $premBody = 0; $premThird = 0;
        foreach ($st->fetchAll() as $c) {
            $v = (int)money_to_int($c['total_premium']);
            $premium += $v;
            if ($c['insurance_type'] === 'BODY') { $body++; $premBody += $v; } else { $third++; $premThird += $v; }
        }
        // ۴) اقساطِ معوقِ کارکنان (سررسیدِ گذشته، هنوز پرداخت‌نشده) - سررسید در بازه
        $overdue = 0; $overdueCount = 0; $overduePeople = [];
        try {
            foreach (fin_unified_installments($pdo, ['source' => 'P', 'status' => 'OVERDUE', 'from' => $from ?: '', 'to' => $to ?: '', 'date_field' => 'due']) as $r) {
                $overdue += (int)$r['remaining']; $overdueCount++; $overduePeople[$r['holder_name'] . '|' . $r['national_code']] = 1;
            }
        } catch (Throwable $e) { error_log('[records stats overdue] ' . $e->getMessage()); }

        echo json_encode(['ok' => true, 'from' => $from, 'to' => $to,
            'letters' => $letters, 'issued' => $body + $third, 'issued_body' => $body, 'issued_third' => $third,
            'premium' => $premium, 'premium_body' => $premBody, 'premium_third' => $premThird,
            'overdue_amount' => $overdue, 'overdue_count' => $overdueCount, 'overdue_people' => count($overduePeople)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------- خروجی اکسل (همان ردیف‌های جدول) ----------------
    if ($action === 'export') {
        require_once __DIR__ . '/_xlsx_writer.php';
        $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
        $ids = array_flip(array_map('intval', is_array($ids) ? $ids : []));
        $rows = array_values(array_filter(rec_rows($pdo), fn($r) => isset($ids[intval($r['id'])])));
        $typeFa = ['BODY' => 'بدنه', 'THIRDPARTY' => 'ثالث'];
        $statusFa = ['REGISTERED' => 'ثبت شده', 'DOCS_PENDING' => 'در انتظار اصلاح', 'DOCS_REVIEW' => 'در انتظار تایید مدارک', 'REJECTED' => 'نیاز به اصلاح',
                     'ISSUING' => 'در حال صدور', 'ISSUED' => 'صادر شده', 'WITHDRAWN' => 'خارج از فاز عملیاتی'];
        $headers = ['ردیف', 'نام پرسنل', 'کد ملی', 'کد پرسنلی', 'موبایل', 'شرکت', 'تاریخ صدور معرفی‌نامه', 'ماه معرفی‌نامه', 'تاریخ ثبت',
                    'سهمیه', 'صادره‌ی کل', 'صادره در ماه معرفی‌نامه', 'ثالث', 'بدنه', 'حق بیمه‌ی کل (ریال)',
                    'شناسه پرونده', 'نوع بیمه', 'وضعیت', 'بیمه‌گذار', 'نسبت', 'کد ملی بیمه‌گذار', 'پلاک', 'خودرو', 'شماره بیمه‌نامه', 'تاریخ صدور بیمه‌نامه', 'حق بیمه (ریال)'];
        $out = []; $n = 0;
        $cs = $pdo->prepare("SELECT * FROM policy_cases WHERE introduction_id = ? ORDER BY id");
        foreach ($rows as $r) {
            $base = [$r['full_name'], $r['national_code'], $r['personnel_code'], $r['mobile_number'], $r['company_name'],
                     $r['letter_date_j'] ? fa_digits($r['letter_date_j']) : '-', $r['letter_month_fa'] . ' ' . fa_digits($r['letter_year']), fa_digits($r['registered_j']), fa_digits($r['quota_summary']),
                     $r['issued_total'], $r['issued_in_letter_month'], $r['issued_third'], $r['issued_body'], $r['premium_total']];
            $cs->execute([$r['introduction_id']]);
            $cases = $cs->fetchAll();
            if (!$cases) { $out[] = array_merge([++$n], $base, array_fill(0, 11, '')); continue; }
            foreach ($cases as $c) {
                $out[] = array_merge([++$n], $base, [
                    $c['unique_code'], $typeFa[$c['insurance_type']] ?? $c['insurance_type'], $statusFa[$c['status']] ?? $c['status'],
                    $c['insured_name'], $c['insured_relationship'], $c['insured_national_id'], $c['plate'],
                    trim(($c['car_system'] ?? '') . ' ' . ($c['car_type'] ?? '')), $c['policy_number'],
                    $c['issued_at'] ? fa_digits(rec_jalali_of_ts(strtotime($c['issued_at']))) : '', (int)money_to_int($c['total_premium']) ?: '']);
            }
        }
        $path = xlsx_build($headers, $out, 'پرونده‌ها', [9, 10, 11, 12, 13, 25]);
        xlsx_send($path, 'پرونده های کارکنان ' . fa_digits(str_replace('/', '-', rec_jalali_of_ts(time()))) . '.xlsx');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['ok' => true, 'data' => rec_rows($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    error_log('[records] ' . $e->getMessage() . ' @' . $e->getLine());
    echo json_encode(['ok' => false, 'error' => 'خطا در دیتابیس.']);
}
