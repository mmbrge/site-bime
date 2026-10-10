<?php
// فایل: api/finance_core.php
// موتور مالی: دوره‌بندی، تولید اقساط، شماره‌گذاری صورتحساب و محاسبات مانده
require_once __DIR__ . '/_dl_name.php';   // نامِ درستِ فایل‌های دانلودی (بدونِ نویسه‌های نامرئی و «(ماه)»)
require_once __DIR__ . '/_case_helpers.php';

// =====================================================================
//  تنظیمات
// =====================================================================
function fin_settings($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows = $pdo->query("SELECT setting_key, setting_value FROM finance_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = array_merge([
        'period_cutoff_day' => '25',
        'period_mode' => 'cutoff',   // cutoff: از روزِ X ماهِ قبل تا روزِ X | calendar: ماه به ماه (۱ تا آخرِ ماه)
        'default_installments' => '9',
        'installment_method' => 'mamut',
        'rule_sequential_payment' => '1',
        'rule_collect_before_pay' => '1',
        'invoice_prefix' => 'SM49357',
        'invoice_counter' => '0',
        'invoice_counter_year' => '',
    ], $rows ?: []);
    return $cache;
}

function fin_set($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO finance_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$key, $value, $value]);
}

// =====================================================================
//  کمکی‌های تاریخ شمسی
// =====================================================================
function fin_jalali_month_len($jy, $jm) {
    if ($jm <= 6) return 31;
    if ($jm <= 11) return 30;
    // اسفند: بستگی به کبیسه دارد
    $r = (($jy - ($jy > 0 ? 474 : 473)) % 2820) + 474;
    return ((($r + 38) * 682) % 2816 < 682) ? 30 : 29;
}

// ماهِ بعدی را برمی‌گرداند
function fin_next_month($jy, $jm, $step = 1) {
    $total = ($jy * 12) + ($jm - 1) + $step;
    return [intdiv($total, 12), ($total % 12) + 1];
}

function fin_month_name($jm) {
    $names = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    return $names[$jm - 1] ?? '';
}

// =====================================================================
//  دوره‌های مالی (هم‌شکل با calculate_period برنامه‌ی ماموت)
//  قانون: از روزِ (cutoff+1) ماه قبل تا روزِ cutoff همین ماه متعلق به دوره‌ی همین ماه است؛
//  یعنی اگر بیمه‌نامه بعد از روز cutoff (مثلاً ۲۶ام به بعد) صادر شده باشد، به دوره‌ی ماه بعد می‌رود.
// =====================================================================
function fin_resolve_period_for_date($pdo, $jy, $jm, $jd) {
    [$jy, $jm] = period_month_of($jy, $jm, $jd, $pdo);   // «دوره‌ای» یا «ماه به ماه» (_case_helpers.php)
    return fin_ensure_period($pdo, $jy, $jm);
}

// بازه‌ی میلادیِ یک دوره
function fin_period_range($pdo, $jy, $jm) {
    $cutoff = period_cutoff_eff($pdo);   // ماه به ماه = ۳۱ => از ۱ تا آخرِ همان ماه
    [$py, $pm] = fin_next_month($jy, $jm, -1);
    $startTs = jalali_to_gregorian_ts($py, $pm, min($cutoff, fin_jalali_month_len($py, $pm))) + 86400;
    $endTs   = jalali_to_gregorian_ts($jy, $jm, min($cutoff, fin_jalali_month_len($jy, $jm)));
    return [date('Y-m-d', $startTs), date('Y-m-d', $endTs)];
}

// دوره را پیدا یا ایجاد می‌کند (و دوره‌های گذشته را خودکار می‌بندد)
function fin_ensure_period($pdo, $jy, $jm) {
    $stmt = $pdo->prepare("SELECT * FROM billing_periods WHERE jalali_year = ? AND jalali_month = ?");
    $stmt->execute([$jy, $jm]);
    $p = $stmt->fetch();
    if ($p) { fin_auto_close_periods($pdo); return $p; }

    [$start, $end] = fin_period_range($pdo, $jy, $jm);
    $title = fin_month_name($jm) . ' ' . $jy;
    $pdo->prepare("INSERT INTO billing_periods (jalali_year, jalali_month, title, starts_at, ends_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([$jy, $jm, $title, $start, $end]);

    fin_auto_close_periods($pdo);
    $stmt->execute([$jy, $jm]);
    return $stmt->fetch();
}

// هر دوره‌ای که تاریخ پایانش گذشته، خودکار بسته می‌شود
function fin_auto_close_periods($pdo) {
    $pdo->prepare("UPDATE billing_periods SET status = 'CLOSED', closed_at = NOW()
                   WHERE status = 'OPEN' AND ends_at < CURDATE()")->execute();
}

// دوره‌ی جاری (بر اساس امروز)
function fin_current_period($pdo) {
    $tz = new DateTimeZone('Asia/Tehran');
    $now = new DateTime('now', $tz);
    [$jy, $jm, $jd] = jalali_from_gregorian_ts($now->getTimestamp());
    return fin_resolve_period_for_date($pdo, $jy, $jm, $jd);
}

// =====================================================================
//  تقسیم مبلغ به اقساط - دقیقاً هم‌شکل با InstallmentManager.calculate برنامه‌ی ماموت
//  در هر دو روش، جمعِ اقساط دقیقاً برابرِ مبلغ کل است (حتی یک ریال اختلاف نباشد).
//   mamut : قسط اول = کفِ رُند (به هزار) + همه‌ی خرده‌ها، رُندشده؛ بقیه‌ی اقساط مساوی و رُند به هزار؛
//           اختلافِ نهایی روی «قسط آخر» اعمال می‌شود.
//   easy  : تقسیم مساوی؛ باقیمانده یک‌ریال‌یک‌ریال به اقساطِ اول اضافه می‌شود (روش «عادی» ماموت).
// =====================================================================
function fin_split_installments($total, $count, $method = 'mamut') {
    $total = (int)$total;
    $count = max(1, (int)$count);
    if ($total <= 0) return array_fill(0, $count, 0);

    $even = function () use ($total, $count) {
        $base = intdiv($total, $count);
        $rem = $total % $count;
        $parts = [];
        for ($i = 0; $i < $count; $i++) $parts[] = $base + ($i < $rem ? 1 : 0);
        return $parts;
    };
    if ($method === 'easy' || $method === 'normal') return $even();
    if ($count === 1) return [$total];

    $baseFloor = intdiv(intdiv($total, $count), 1000) * 1000;
    if ($baseFloor === 0) return $even();

    $remTotal = $total - $baseFloor * $count;
    $first = (int)(fin_round_half_even(($baseFloor + $remTotal) / 1000) * 1000);
    $first = max($baseFloor, min($first, $total));
    $other = max(0, (int)(fin_round_half_even((($total - $first) / ($count - 1)) / 1000) * 1000));

    $parts = [$first];
    for ($i = 1; $i < $count; $i++) $parts[] = $other;
    $parts[$count - 1] += $total - array_sum($parts);
    foreach ($parts as &$p) if ($p < 0) $p = 0;
    unset($p);
    return $parts;
}

// round پایتون (بانکی: نیمه به نزدیک‌ترین زوج) - تا عددها ریال‌به‌ریال با ماموت یکی باشد
function fin_round_half_even($x) {
    $f = floor($x);
    $d = $x - $f;
    if (abs($d - 0.5) < 1e-9) return fmod($f, 2) == 0 ? $f : $f + 1;
    return round($x);
}

// «yyyy/mm/dd» (با ارقام فارسی/انگلیسی، با / یا - یا .) => [y, m, d] یا null
function fin_parse_jalali($s) {
    $s = p2e_digits(trim((string)$s));
    if (!preg_match('/(1[34]\d\d)\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})/', $s, $m)) return null;
    $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
    return [$y, $mo, min($d, fin_jalali_month_len($y, $mo))];
}

// تاریخ صدورِ مبنا: تاریخ صدورِ روی بیمه‌نامه (OCR)؛ اگر نبود، زمانِ صدور در پنل
function fin_issue_jalali($policyIssueDate, $issuedAt) {
    $j = fin_parse_jalali($policyIssueDate);
    if ($j) return $j;
    $ts = $issuedAt ? strtotime($issuedAt) : false;
    return jalali_from_gregorian_ts($ts ?: time());
}

// سررسید قسطِ n: همان روزِ صدور، n ماه بعد (روز برای ماه‌های کوتاه‌تر اصلاح می‌شود) - هم‌شکل با ماموت
function fin_due_after($jy, $jm, $jd, $months) {
    [$y, $m] = fin_next_month($jy, $jm, $months);
    $d = min($jd, fin_jalali_month_len($y, $m));
    return [$y, $m, $d];
}

// ستون‌های «فیشِ اقساط» و «تاریخ صدورِ داخلِ بیمه‌نامه» (خودکار، یک بار)
function fin_ensure_receipt_cols($pdo) {
    static $done = false;
    if ($done || $pdo->inTransaction()) return;
    $done = true;
    foreach ([['company_request_plates', 'receipts_json', 'TEXT NULL'], ['company_request_plates', 'statement_file', 'VARCHAR(500) NULL'],
              ['company_request_plates', 'policy_issue_date', 'VARCHAR(12) NULL'],
              ['policy_cases', 'receipts_json', 'TEXT NULL'], ['policy_cases', 'statement_file', 'VARCHAR(500) NULL']] as [$t, $c, $def]) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        } catch (Throwable $e) { error_log('[fin_ensure_receipt_cols] ' . $e->getMessage()); }
    }
}

// جفت‌کردنِ اقساط با فیش‌های فایلِ بیمه‌نامه: اگر تعدادشان برابر است به ترتیب، وگرنه نزدیک‌ترین تاریخِ سررسید
// $insts: [[id, inst_number, due_jalali], ...] ؛ خروجی: [id => receipt]
function fin_pair_receipts(array $insts, $receiptsJson) {
    $rc = is_array($receiptsJson) ? $receiptsJson : (json_decode((string)$receiptsJson, true) ?: []);
    if (!$rc || !$insts) return [];
    usort($insts, fn($a, $b) => $a['inst_number'] <=> $b['inst_number']);
    usort($rc, fn($a, $b) => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
    $out = [];
    if (count($rc) === count($insts)) {
        foreach ($insts as $k => $i) $out[$i['id']] = $rc[$k];
        return $out;
    }
    $toDays = function ($j) { $p = fin_parse_jalali($j); return $p ? $p[0] * 372 + $p[1] * 31 + $p[2] : null; };
    $used = [];
    foreach ($insts as $i) {
        $di = $toDays($i['due_jalali'] ?? ''); $best = null; $bd = PHP_INT_MAX;
        foreach ($rc as $k => $r) {
            if (isset($used[$k])) continue;
            $dr = $toDays($r['date'] ?? '');
            $dist = ($di !== null && $dr !== null) ? abs($di - $dr) : 100000 + $k;
            if ($dist < $bd) { $bd = $dist; $best = $k; }
        }
        if ($best !== null && $bd <= 45) { $out[$i['id']] = $rc[$best]; $used[$best] = 1; }
    }
    return $out;
}

// کد رهگیری ۱۰ رقمیِ یکتا برای هر قسط (مثل tracking_id ماموت)
function fin_tracking_code($pdo, $table = 'policy_installments') {
    $chk = $pdo->prepare("SELECT 1 FROM `$table` WHERE tracking_code = ? LIMIT 1");
    for ($i = 0; $i < 20; $i++) {
        $code = (string)random_int(1000000000, 9999999999);
        $chk->execute([$code]);
        if (!$chk->fetchColumn()) return $code;
    }
    return (string)random_int(1000000000, 9999999999);
}

// =====================================================================
//  ستون‌های تازه‌ی ماژول مالی - خودکار و فقط یک بار اضافه می‌شوند (نیازی به اجرای SQL دستی نیست)
// =====================================================================
function fin_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $s = fin_settings($pdo);
    if (($s['schema_v'] ?? '') === '2') return;
    $add = [
        ['policy_installments', 'tracking_code', "VARCHAR(12) NULL"],
        ['company_installments', 'tracking_code', "VARCHAR(12) NULL"],
        ['invoices', 'status', "VARCHAR(12) NOT NULL DEFAULT 'ISSUED'"],
        ['invoices', 'deactivated_at', "DATETIME NULL"],
        ['invoices', 'options_json', "TEXT NULL"],
        ['invoice_lines', 'issue_date', "VARCHAR(12) NULL"],
        ['invoice_lines', 'third_count', "INT NULL"],
        ['invoice_lines', 'body_count', "INT NULL"],
    ];
    foreach ($add as [$t, $c, $def]) {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch();
            if (!$has) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        } catch (Throwable $e) { error_log('[fin_ensure_schema] ' . $e->getMessage()); return; }
    }
    // اگر ستون نوع صورتحساب/قالب ENUM قدیمی بدون PERSONNEL است، متنی می‌شود تا نوع «تلفیقی» هم ذخیره شود
    foreach (['invoices', 'invoice_templates'] as $t) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'kind'")->fetch();
            if ($col && stripos($col['Type'], 'enum') === 0 && stripos($col['Type'], 'PERSONNEL') === false) {
                $pdo->exec("ALTER TABLE `$t` MODIFY `kind` VARCHAR(20) NOT NULL DEFAULT 'DETAILED'");
            }
        } catch (Throwable $e) { error_log('[fin_ensure_schema kind] ' . $e->getMessage()); }
    }
    // کد رهگیری برای اقساطِ قبلی
    foreach (['policy_installments', 'company_installments'] as $t) {
        try {
            $ids = $pdo->query("SELECT id FROM `$t` WHERE tracking_code IS NULL OR tracking_code = ''")->fetchAll(PDO::FETCH_COLUMN);
            $up = $pdo->prepare("UPDATE `$t` SET tracking_code = ? WHERE id = ?");
            foreach ($ids as $id) $up->execute([fin_tracking_code($pdo, $t), $id]);
        } catch (Throwable $e) {}
    }
    fin_set($pdo, 'schema_v', '2');
}

// =====================================================================
//  تولید اقساط یک بیمه‌نامه‌ی پرسنلی (بعد از صدور) - هم‌شکل با ماموت:
//   - دوره: از تاریخ صدور و روزِ cutoff
//   - سررسید قسطِ n: تاریخ صدور + n ماه (due_mode=issue، پیش‌فرض ماموت)
//     یا پانزدهمِ n امین ماه بعد از دوره (due_mode=period15، روش قبلی سایت)
//   - اگر برای این پرونده پرداختی/تسویه‌ای ثبت شده باشد، اقساط دوباره ساخته نمی‌شوند
//     (مگر $force) تا دریافتی‌ها یتیم نشوند.
// =====================================================================
function fin_generate_installments($pdo, $caseId, $count = null, $method = null, $force = false) {
    fin_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
    if (!$case || $case['status'] !== 'ISSUED') return ['ok' => false, 'error' => 'پرونده صادر نشده است.'];
    if (!empty($case['no_installments'])) return ['ok' => false, 'error' => 'این بیمه‌نامه «بدونِ قسط» ثبت شده (بایگانیِ قبلی).'];
    if (!function_exists('endo_has_finance')) require_once __DIR__ . '/_endorse.php';
    if (endo_has_finance($pdo, 'P', $caseId)) return ['ok' => false, 'error' => 'این بیمه‌نامه الحاقیه‌ی دارای اثرِ مالی دارد؛ برای ساختِ دوباره‌ی اقساط اول آن الحاقیه را حذف کنید.'];
    $premium = money_to_int($case['total_premium']);
    if (!$premium) return ['ok' => false, 'error' => 'حق بیمه‌ی این پرونده ثبت نشده است.'];

    if (!$force) {
        $paid = $pdo->prepare("SELECT (SELECT COUNT(*) FROM payment_allocations pa JOIN policy_installments pi ON pi.id = pa.installment_id WHERE pi.case_id = ?)
                                    + (SELECT COUNT(*) FROM policy_installments WHERE case_id = ? AND COALESCE(settled_to_pasargad,0) = 1)");
        $paid->execute([$caseId, $caseId]);
        if (intval($paid->fetchColumn()) > 0) {
            return ['ok' => false, 'error' => 'برای اقساط این پرونده دریافتی یا تسویه‌ی پاسارگاد ثبت شده؛ برای جلوگیری از به‌هم‌ریختن حساب‌ها، اقساط دوباره ساخته نشد.'];
        }
    }

    // روشِ پرداخت: قراردادِ کارکنان / تنظیماتِ مالی / روشِ اختصاصیِ همین پرونده؛ فیش‌های فایلِ بیمه‌نامه اولویت دارند
    fin_contracts_ensure($pdo);
    $plan = fin_plan_resolve($pdo, 'P', $case);
    if ($count) { $plan['type'] = 'INSTALLMENT'; $plan['count'] = max(1, intval($count)); $plan['prefer'] = 'formula'; }
    if ($method) $plan['method'] = $method === 'mamut' ? 'mamut' : 'even';

    [$jy, $jm, $jd] = fin_issue_jalali($case['policy_issue_date'] ?? null, $case['issued_at']);
    $period = fin_resolve_period_for_date($pdo, $jy, $jm, $jd);
    $built = fin_plan_build($premium, [$jy, $jm, $jd], $plan, $case['receipts_json'] ?? null,
                            fin_detected_contract_key($case['ocr_extracted_data'] ?? null, $case['policy_number'] ?? ''), $period);

    $pdo->prepare("DELETE FROM policy_installments WHERE case_id = ?")->execute([$caseId]);
    $ins = $pdo->prepare("INSERT INTO policy_installments (case_id, period_id, inst_number, amount, due_jalali, due_date, tracking_code, slip_json, slip_file)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($built['rows'] as $r) {
        $ins->execute([$caseId, $period['id'], $r['n'], $r['amount'], $r['due'], $r['due_g'], fin_tracking_code($pdo),
                       !empty($r['slip']) ? json_encode($r['slip'], JSON_UNESCAPED_UNICODE) : null, $r['slip_file'] ?? null]);
    }
    $parts = array_column($built['rows'], 'amount');
    try { $pdo->prepare("UPDATE policy_cases SET pay_check_json = ? WHERE id = ?")->execute([json_encode($built['check'], JSON_UNESCAPED_UNICODE), $caseId]); } catch (Throwable $e) {}

    $pdo->prepare("UPDATE policy_cases SET installments_generated = 1 WHERE id = ?")->execute([$caseId]);
    return ['ok' => true, 'count' => count($parts), 'period' => $period['title'], 'parts' => $parts, 'check' => $built['check']];
}

// =====================================================================
//  همگام‌سازی هوشمند اقساط (پرسنلی + شرکتی):
//   - هر بیمه‌نامه‌ی صادرشده‌ای که قسط ندارد، قسط می‌گیرد
//   - اگر حق بیمه بعد از ساخت اقساط عوض شده (جمع اقساط ≠ حق بیمه) و هنوز پرداختی ندارد، اقساط از نو ساخته می‌شود
//   - اقساط شرکتی از روی تنظیمات همان شرکت (تعداد اقساط و فاصله‌ی اولین سررسید) ساخته می‌شوند
// =====================================================================
function fin_sync_installments($pdo) {
    fin_ensure_schema($pdo);
    // بیمه‌نامه‌ای که الحاقیه دارد (اقساطش عمداً با حق بیمه‌ی اصلی برابر نیست) یا «بدونِ قسط» ثبت شده، دوباره ساخته نمی‌شود
    if (!function_exists('endo_ensure')) require_once __DIR__ . '/_endorse.php';
    endo_ensure($pdo);
    $out = ['personnel' => 0, 'company' => 0];
    try {
        $rows = $pdo->query("
            SELECT pc.id, pc.total_premium, COALESCE(x.cnt,0) AS cnt, COALESCE(x.total,0) AS total, COALESCE(x.touched,0) AS touched
            FROM policy_cases pc
            LEFT JOIN (SELECT pi.case_id, COUNT(*) AS cnt, SUM(pi.amount) AS total,
                              SUM(CASE WHEN COALESCE(pi.settled_to_pasargad,0) = 1 OR EXISTS (SELECT 1 FROM payment_allocations pa WHERE pa.installment_id = pi.id) THEN 1 ELSE 0 END) AS touched
                       FROM policy_installments pi GROUP BY pi.case_id) x ON x.case_id = pc.id
            WHERE pc.status = 'ISSUED' AND COALESCE(pc.is_direct_payment,0) = 0 AND COALESCE(pc.endo_count,0) = 0 AND COALESCE(pc.no_installments,0) = 0
        ")->fetchAll();
        foreach ($rows as $r) {
            $premium = money_to_int($r['total_premium']);
            if (!$premium) continue;
            if ((int)$r['cnt'] === 0 || ((int)$r['total'] !== $premium && (int)$r['touched'] === 0 && !fin_slips_applied($pdo, 'policy_cases', $r['id']))) {
                $res = fin_generate_installments($pdo, $r['id']);
                if (!empty($res['ok'])) $out['personnel']++;
            }
        }
    } catch (Throwable $e) { error_log('[fin_sync personnel] ' . $e->getMessage()); }

    try {
        if (!function_exists('company_generate_installments')) require_once __DIR__ . '/_company_helpers.php';
        imp_ensure($pdo);
        $rows = $pdo->query("
            SELECT crp.id, crp.total_premium, COALESCE(x.cnt,0) AS cnt, COALESCE(x.total,0) AS total, COALESCE(x.touched,0) AS touched
            FROM company_request_plates crp
            LEFT JOIN (SELECT ci.plate_id, COUNT(*) AS cnt, SUM(ci.amount) AS total,
                              SUM(CASE WHEN ci.settled_to_pasargad = 1 OR EXISTS (SELECT 1 FROM company_payment_allocations a WHERE a.installment_id = ci.id) THEN 1 ELSE 0 END) AS touched
                       FROM company_installments ci GROUP BY ci.plate_id) x ON x.plate_id = crp.id
            WHERE crp.status = 'ISSUED' AND COALESCE(crp.endo_count,0) = 0 AND COALESCE(crp.no_installments,0) = 0
              AND EXISTS (SELECT 1 FROM company_requests cri WHERE cri.id = crp.request_id AND cri.is_import = 0 AND cri.request_kind = 'NEW_POLICY')
        ")->fetchAll();
        foreach ($rows as $r) {
            $premium = money_to_int($r['total_premium']);
            if (!$premium) continue;
            if ((int)$r['cnt'] === 0 || ((int)$r['total'] !== $premium && (int)$r['touched'] === 0 && !fin_slips_applied($pdo, 'company_request_plates', $r['id']))) {
                $res = company_generate_installments($pdo, $r['id']);
                if (!empty($res['ok'])) $out['company']++;
            }
        }
    } catch (Throwable $e) { error_log('[fin_sync company] ' . $e->getMessage()); }
    return $out;
}

// =====================================================================
//  محاسبه‌ی پرداختی و مانده‌ی هر قسط (همیشه محاسبه‌ای، نه ذخیره‌شده)
// =====================================================================
function fin_installment_paid($pdo, $installmentId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE installment_id = ?");
    $stmt->execute([$installmentId]);
    return intval($stmt->fetchColumn());
}

// اقساط یک پرونده همراه با پرداختی و مانده
function fin_case_installments($pdo, $caseId) {
    $stmt = $pdo->prepare("
        SELECT pi.*, COALESCE(SUM(pa.amount), 0) AS paid
        FROM policy_installments pi
        LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
        WHERE pi.case_id = ?
        GROUP BY pi.id
        ORDER BY pi.inst_number ASC
    ");
    $stmt->execute([$caseId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['paid'] = intval($r['paid']);
        $r['remaining'] = max(0, intval($r['amount']) - $r['paid']);
        $r['pay_status'] = $r['remaining'] <= 0 ? 'PAID' : ($r['paid'] > 0 ? 'PARTIAL' : 'UNPAID');
    }
    return $rows;
}

// =====================================================================
//  شماره‌گذاری صورتحساب: SM49357-05-00001
//  شمارنده اتمیک افزایش می‌یابد تا دو صورتحساب هم‌شماره نشوند.
// =====================================================================
function fin_next_invoice_no($pdo) {
    $s = fin_settings($pdo);
    $tz = new DateTimeZone('Asia/Tehran');
    $now = new DateTime('now', $tz);
    [$jy, , ] = jalali_from_gregorian_ts($now->getTimestamp());
    $yy = substr((string)$jy, -2);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM finance_settings WHERE setting_key IN ('invoice_counter','invoice_counter_year') FOR UPDATE");
        $stmt->execute();
        $rows = $pdo->query("SELECT setting_key, setting_value FROM finance_settings WHERE setting_key IN ('invoice_counter','invoice_counter_year')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $counter = intval($rows['invoice_counter'] ?? 0);
        $cyear   = $rows['invoice_counter_year'] ?? '';
        if ($cyear !== $yy) { $counter = 0; }   // با تغییر سال، شمارنده از نو شروع می‌شود
        $counter++;
        fin_set($pdo, 'invoice_counter', (string)$counter);
        fin_set($pdo, 'invoice_counter_year', $yy);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return sprintf('%s-%s-%05d', $s['invoice_prefix'], $yy, $counter);
}

// =====================================================================
//  تخصیص خودکار یک پرداخت به اقساط (به ترتیب سررسید)
//  قانون «تسویه‌ی پیوسته» در صورت فعال‌بودن رعایت می‌شود.
// =====================================================================
function fin_auto_allocate($pdo, $paymentId, $caseIds, $amount) {
    $s = fin_settings($pdo);
    $sequential = $s['rule_sequential_payment'] === '1';
    $remaining = intval($amount);
    if ($remaining <= 0 || !$caseIds) return 0;

    $place = implode(',', array_fill(0, count($caseIds), '?'));
    $stmt = $pdo->prepare("
        SELECT pi.id, pi.case_id, pi.inst_number, pi.amount, COALESCE(SUM(pa.amount),0) AS paid
        FROM policy_installments pi
        LEFT JOIN payment_allocations pa ON pa.installment_id = pi.id
        WHERE pi.case_id IN ($place)
        GROUP BY pi.id
        HAVING paid < pi.amount
        ORDER BY pi.due_date ASC, pi.case_id ASC, pi.inst_number ASC
    ");
    $stmt->execute($caseIds);
    $open = $stmt->fetchAll();

    $alloc = $pdo->prepare("INSERT INTO payment_allocations (payment_id, installment_id, amount) VALUES (?, ?, ?)");
    $allocated = 0;
    $blockedCases = [];

    foreach ($open as $inst) {
        if ($remaining <= 0) break;
        // اگر قانون پیوستگی فعال است و قسطِ قبلیِ همین پرونده هنوز باز مانده، از این پرونده رد شو
        if ($sequential && isset($blockedCases[$inst['case_id']])) continue;

        $due = intval($inst['amount']) - intval($inst['paid']);
        $take = min($due, $remaining);
        if ($take <= 0) continue;

        $alloc->execute([$paymentId, $inst['id'], $take]);
        $remaining -= $take;
        $allocated += $take;

        // اگر این قسط کامل تسویه نشد و قانون پیوستگی فعال است، اقساط بعدیِ همین پرونده قفل می‌شوند
        if ($sequential && $take < $due) $blockedCases[$inst['case_id']] = true;
    }
    return $allocated;
}

// =====================================================================
//  خروجی اکسل بدون وابستگی به کتابخانه
//  از فرمت SpreadsheetML 2003 استفاده می‌شود: یک XML ساده که اکسل آن را با فرمت‌بندی
//  کامل باز می‌کند - راست‌چین، فونت B Nazanin، سربرگ بولد با پس‌زمینه، و جداکننده‌ی
//  سه‌رقمی برای ستون‌های مبلغ.
//  $numericCols: شماره‌ی ستون‌هایی (از صفر) که باید عدد و سه‌رقم‌سه‌رقم باشند.
// =====================================================================
function fin_send_excel($fileName, array $headers, array $rows, array $numericCols = []) {
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');

    while (ob_get_level() > 0) { ob_end_clean(); }
    header_remove('Content-Type');
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report.xls"; filename*=UTF-8\'\'' . rawurlencode(dl_name($fileName . '.xls')));
    header('Cache-Control: max-age=0');

    echo "\xEF\xBB\xBF";
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

    echo '<Styles>
  <Style ss:ID="Default" ss:Name="Normal">
    <Alignment ss:Vertical="Center" ss:Horizontal="Center" ss:ReadingOrder="RightToLeft"/>
    <Font ss:FontName="B Nazanin" x:Family="Swiss" ss:Size="11"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
    </Borders>
  </Style>
  <Style ss:ID="hdr">
    <Alignment ss:Vertical="Center" ss:Horizontal="Center" ss:ReadingOrder="RightToLeft" ss:WrapText="1"/>
    <Font ss:FontName="B Nazanin" x:Family="Swiss" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#4F46E5" ss:Pattern="Solid"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#312E81"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#312E81"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#312E81"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#312E81"/>
    </Borders>
  </Style>
  <Style ss:ID="num">
    <Alignment ss:Vertical="Center" ss:Horizontal="Center" ss:ReadingOrder="LeftToRight"/>
    <Font ss:FontName="B Nazanin" x:Family="Swiss" ss:Size="11"/>
    <NumberFormat ss:Format="#,##0"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9D9D9"/>
    </Borders>
  </Style>
</Styles>' . "\n";

    echo '<Worksheet ss:Name="گزارش">' . "\n";
    echo '<Table ss:DefaultRowHeight="18">' . "\n";
    foreach ($headers as $h) {
        // طول رشته‌ی فارسی: اگر mbstring نصب نباشد، تخمین بر پایه‌ی بایت‌ها
        $len = function_exists('mb_strlen') ? mb_strlen((string)$h, 'UTF-8') : intdiv(strlen((string)$h), 2);
        $w = max(70, min(220, $len * 12 + 40));
        echo '<Column ss:AutoFitWidth="0" ss:Width="' . $w . '"/>' . "\n";
    }

    echo '<Row ss:Height="30">';
    foreach ($headers as $h) echo '<Cell ss:StyleID="hdr"><Data ss:Type="String">' . $esc($h) . '</Data></Cell>';
    echo '</Row>' . "\n";

    foreach ($rows as $row) {
        echo '<Row>';
        foreach (array_values($row) as $idx => $val) {
            if (in_array($idx, $numericCols, true)) {
                echo '<Cell ss:StyleID="num"><Data ss:Type="Number">' . intval($val) . '</Data></Cell>';
            } else {
                echo '<Cell><Data ss:Type="String">' . $esc($val) . '</Data></Cell>';
            }
        }
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
  <DisplayRightToLeft/><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal>
  <TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane>
</WorksheetOptions>' . "\n";
    echo '</Worksheet></Workbook>';
}

// =====================================================================
//  نرمال‌سازی شماره‌ی بیمه‌نامه برای تطبیق
//  شماره‌ها ممکن است با / یا ∕ یا خط‌تیره یا فاصله نوشته شده باشند؛ برای مقایسه فقط
//  رقم‌ها و حروف نگه داشته می‌شوند تا تفاوت‌های نگارشی باعث مغایرتِ کاذب نشود.
// =====================================================================
function fin_norm_policy($s) {
    $s = (string)$s;
    // ارقام فارسی/عربی به لاتین
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($fa, $en, $s);
    return preg_replace('/[^0-9A-Za-z]/u', '', $s);
}

// =====================================================================
//  خواندن فایل جدولی (xlsx یا csv) بدون کتابخانه‌ی بیرونی
//  xlsx در واقع یک فایل zip است؛ با ZipArchive بازش می‌کنیم و sharedStrings و sheet1
//  را مستقیم از XML می‌خوانیم. اگر نشد، null برمی‌گردانیم تا کاربر CSV بدهد.
//  خروجی: آرایه‌ای از سطرها (هر سطر آرایه‌ای از مقادیر ستون‌ها)
// =====================================================================
function fin_read_spreadsheet($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'csv') {
        $rows = [];
        if (($h = fopen($path, 'r')) !== false) {
            // حذف BOM در صورت وجود
            $first = fgets($h);
            rewind($h);
            if (substr($first, 0, 3) === "\xEF\xBB\xBF") { fseek($h, 3); }
            while (($row = fgetcsv($h, 0, ',')) !== false) $rows[] = $row;
            fclose($h);
        }
        return $rows ?: null;
    }

    // xlsx نیازمند ZipArchive است. اگر روی سرور فعال نباشد، به‌جای شکستِ خاموش، با
    // خواندن مستقیم فایل zip تلاش می‌کنیم؛ و اگر آن هم نشد null برمی‌گردد تا به کاربر
    // پیام روشن بدهیم که فایل را CSV کند.
    if (!class_exists('ZipArchive')) return fin_read_xlsx_fallback($path);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return fin_read_xlsx_fallback($path);

    // رشته‌های مشترک
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        if (preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) {
            foreach ($m[1] as $si) {
                // متن ممکن است در چند <t> تکه شده باشد
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
                $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }

    // نام واقعی اولین شیت
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $sheet = $zip->getFromName($sheetPath);
    if ($sheet === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, 'xl/worksheets/') === 0 && substr($n, -4) === '.xml') { $sheet = $zip->getFromName($n); break; }
        }
    }
    $zip->close();
    if ($sheet === false || $sheet === null) return null;

    $rows = [];
    if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm)) {
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            // سلولِ خالی در اکسل خودبسته است (<c r="E2" s="1"/>) و نباید سلولِ بعدی را ببلعد
            if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $cell) {
                    $attr = $cell[1]; $inner = $cell[2] ?? '';
                    // شماره‌ی ستون از مرجع سلول (مثل C5) استخراج می‌شود تا سلول‌های خالی جا نیفتند
                    $colIdx = 0;
                    if (preg_match('/r="([A-Z]+)\d+"/', $attr, $rmch)) {
                        $letters = $rmch[1]; $colIdx = 0;
                        for ($i = 0; $i < strlen($letters); $i++) $colIdx = $colIdx * 26 + (ord($letters[$i]) - 64);
                        $colIdx--;
                    } else { $colIdx = count($cells); }

                    $val = '';
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) $val = $vm[1];
                    elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm2)) $val = $tm2[1];

                    if (strpos($attr, 't="s"') !== false) {
                        $val = $shared[intval($val)] ?? '';
                    } else {
                        $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                    $cells[$colIdx] = $val;
                }
            }
            if ($cells) {
                ksort($cells);
                $max = max(array_keys($cells));
                $norm = [];
                for ($i = 0; $i <= $max; $i++) $norm[$i] = $cells[$i] ?? '';
                $rows[] = $norm;
            }
        }
    }
    return $rows ?: null;
}

// مسیر پشتیبان برای خواندن xlsx بدون ZipArchive: استفاده از استریمِ zip در PHP
// (روی بیشتر سرورها فعال است حتی وقتی افزونه‌ی zip نصب نیست)
function fin_read_xlsx_fallback($path) {
    if (!in_array('zip', stream_get_wrappers(), true)) return null;
    $shared = [];
    $ss = @file_get_contents('zip://' . $path . '#xl/sharedStrings.xml');
    if ($ss !== false && preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) {
        foreach ($m[1] as $si) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
            $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $sheet = @file_get_contents('zip://' . $path . '#xl/worksheets/sheet1.xml');
    if ($sheet === false) return null;

    $rows = [];
    if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm)) {
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            // سلولِ خالی در اکسل خودبسته است (<c r="E2" s="1"/>) و نباید سلولِ بعدی را ببلعد
            if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $cell) {
                    $attr = $cell[1]; $inner = $cell[2] ?? '';
                    $colIdx = count($cells);
                    if (preg_match('/r="([A-Z]+)\d+"/', $attr, $rmch)) {
                        $letters = $rmch[1]; $colIdx = 0;
                        for ($i = 0; $i < strlen($letters); $i++) $colIdx = $colIdx * 26 + (ord($letters[$i]) - 64);
                        $colIdx--;
                    }
                    $val = '';
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) $val = $vm[1];
                    elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm2)) $val = $tm2[1];
                    if (strpos($attr, 't="s"') !== false) $val = $shared[intval($val)] ?? '';
                    else $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $cells[$colIdx] = $val;
                }
            }
            if ($cells) {
                ksort($cells);
                $max = max(array_keys($cells));
                $norm = [];
                for ($i = 0; $i <= $max; $i++) $norm[$i] = $cells[$i] ?? '';
                $rows[] = $norm;
            }
        }
    }
    return $rows ?: null;
}

// =====================================================================
//  بایگانی مالی
//  ساختار: Archive/بایگانی/مالی/{سال}/{ماه}/[{شرکت}/]{شماره صورتحساب}/
//  صورتحسابِ «کلی» مستقیم زیر ماه قرار می‌گیرد (چون به شرکت خاصی تعلق ندارد).
// =====================================================================
function finance_archive_root($siteRoot) { return archive_root($siteRoot) . '/مالی'; }

function fin_invoice_folder($siteRoot, $period, $companyName, $invoiceNo) {
    $base = finance_archive_root($siteRoot) . '/' . $period['jalali_year'] . '/' . fin_month_name($period['jalali_month']);
    if ($companyName) $base .= '/' . sanitize_folder_name($companyName);
    return $base . '/' . sanitize_folder_name($invoiceNo);
}

// فیش‌های دریافتی (از شرکت) و پرداختی (به پاسارگاد) در دو زیرپوشه‌ی جدا نگه داشته
// می‌شوند - هم‌ساختار با پوشه‌ی صورتحساب‌ها (سال/ماه/شرکت)، برای اینکه هم‌جا پیدا شوند
function fin_receipt_folder($siteRoot, $period, $companyName, $target) {
    $base = finance_archive_root($siteRoot) . '/' . $period['jalali_year'] . '/' . fin_month_name($period['jalali_month']);
    if ($companyName) $base .= '/' . sanitize_folder_name($companyName);
    return $base . '/' . ($target === 'pasargad' ? 'فیش‌های پاسارگاد' : 'فیش‌های کارگزاری');
}

// =====================================================================
//  تبدیل عدد به حروف فارسی (برای «جمع کل به حروف» در صورتحساب)
// =====================================================================
function fin_num_to_words($num) {
    $num = (int)$num;
    if ($num === 0) return 'صفر';
    $yekan = ['','یک','دو','سه','چهار','پنج','شش','هفت','هشت','نه'];
    $dahgan = ['','','بیست','سی','چهل','پنجاه','شصت','هفتاد','هشتاد','نود'];
    $dahyek = ['ده','یازده','دوازده','سیزده','چهارده','پانزده','شانزده','هفده','هجده','نوزده'];
    $sadgan = ['','صد','دویست','سیصد','چهارصد','پانصد','ششصد','هفتصد','هشتصد','نهصد'];
    $scales = ['', ' هزار', ' میلیون', ' میلیارد', ' هزار میلیارد'];

    $three = function($n) use ($yekan, $dahgan, $dahyek, $sadgan) {
        $parts = [];
        $s = intdiv($n, 100); $r = $n % 100;
        if ($s) $parts[] = $sadgan[$s];
        if ($r >= 10 && $r <= 19) $parts[] = $dahyek[$r - 10];
        else {
            $d = intdiv($r, 10); $y = $r % 10;
            if ($d) $parts[] = $dahgan[$d];
            if ($y) $parts[] = $yekan[$y];
        }
        return implode(' و ', $parts);
    };

    $groups = [];
    while ($num > 0) { $groups[] = $num % 1000; $num = intdiv($num, 1000); }
    $out = [];
    for ($i = count($groups) - 1; $i >= 0; $i--) {
        if ($groups[$i] === 0) continue;
        $out[] = $three($groups[$i]) . ($scales[$i] ?? '');
    }
    return implode(' و ', $out);
}

// =====================================================================
//  جمع‌آوری سطرهای صورتحساب یک دوره
//  خروجی: آرایه‌ای از پرونده‌های صادرشده‌ی آن دوره با قسط ماهانه
//  پرونده‌هایی که پرداخت مستقیم نقدی بوده‌اند، وارد صورتحساب نمی‌شوند.
// =====================================================================
function fin_collect_invoice_rows($pdo, $periodId, $companyId = null, $mode = 'uninvoiced') {
    // حالت‌ها (هم‌شکل با فیلتر صدور صورتحساب ماموت):
    //  uninvoiced: فقط بیمه‌نامه‌های بدون صورتحساب (پیش‌فرض؛ جلوگیری از صدور دوبرابری)
    //  invoiced  : فقط بیمه‌نامه‌هایی که قبلاً صورتحساب خورده‌اند (فقط با گزینه‌ی «چند صورتحساب برای یک بیمه‌نامه»)
    //  all       : همه (همان شرط)
    $where = ["pi.period_id = ?", "pc.status = 'ISSUED'", "COALESCE(pc.is_direct_payment,0) = 0"];
    if ($mode === 'invoiced') $where[] = "COALESCE(pc.is_invoiced,0) = 1";
    elseif ($mode !== 'all')  $where[] = "COALESCE(pc.is_invoiced,0) = 0";
    $params = [$periodId];
    if ($companyId) { $where[] = "p.company_id = ?"; $params[] = $companyId; }

    $stmt = $pdo->prepare("
        SELECT pc.id AS case_id, pc.plate, pc.insurance_type, pc.policy_number, pc.insured_name,
               pc.total_premium, pc.policy_issue_date, pc.issued_at, COALESCE(pc.is_invoiced,0) AS is_invoiced,
               p.full_name AS holder_name, p.national_code, p.personnel_code,
               c.id AS company_id, c.name AS company_name,
               MIN(pi.amount) AS monthly_amount, COUNT(pi.id) AS inst_count
        FROM policy_installments pi
        JOIN policy_cases pc ON pi.case_id = pc.id
        JOIN persons p ON pc.person_id = p.id
        LEFT JOIN companies c ON p.company_id = c.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY pc.id
        ORDER BY c.name ASC, COALESCE(NULLIF(pc.insured_name,''), p.full_name) ASC, pc.policy_number ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['total_premium'] = (int)money_to_int($r['total_premium']);
        [$y, $m, $d] = fin_issue_jalali($r['policy_issue_date'], $r['issued_at']);
        $r['issue_date'] = sprintf('%04d/%02d/%02d', $y, $m, $d);
    }
    unset($r);
    return $rows;
}

// =====================================================================
//  ساخت فایل متنی اطلاعات صورتحساب (کنار docx/pdf در بایگانی)
// =====================================================================
function fin_write_invoice_info($folder, $invoice, $rows, $companyName, $periodTitle) {
    $nl = "\r\n";
    $t  = "════════════════════════════════════════" . $nl;
    $t .= "            اطلاعات صورتحساب" . $nl;
    $t .= "════════════════════════════════════════" . $nl . $nl;
    $t .= "  شماره صورتحساب : " . $invoice['invoice_no'] . $nl;
    $t .= "  نوع            : " . ($invoice['kind'] === 'SUMMARY' ? 'کلی (کل دوره)' : 'تفکیکی') . $nl;
    $t .= "  دوره           : " . $periodTitle . $nl;
    if ($companyName) $t .= "  شرکت           : " . $companyName . $nl;
    $t .= "  تعداد ردیف     : " . count($rows) . $nl;
    $t .= "  جمع کل         : " . number_format((int)$invoice['total_amount']) . " ریال" . $nl;
    $t .= "  به حروف        : " . fin_num_to_words((int)$invoice['total_amount']) . " ریال" . $nl;
    $t .= "  تاریخ صدور     : " . jalali_from_gregorian_ts_dotted(time()) . $nl;
    $t .= $nl . "────────────────────────────────────────" . $nl;
    @file_put_contents($folder . '/اطلاعات صورتحساب.txt', "\xEF\xBB\xBF" . $t);
}

// =====================================================================
//  تولید فایل Word و PDF صورتحساب از قالب آپلودشده
//  قالب docx یک فایل zip است؛ document.xml را می‌خوانیم، کدهای {{...}} را جایگزین
//  می‌کنیم و دوباره بسته‌بندی می‌کنیم. تولید PDF به اسکریپت پایتون سپرده می‌شود
//  (همان زیرساختی که برای OCR داریم) چون کیفیت خروجی‌اش به‌مراتب بهتر است.
// =====================================================================
function fin_render_invoice_files($pdo, $invoice, $folder, $companyName, $period) {
    $siteRoot = dirname(__DIR__);
    $out = ['docx' => null, 'pdf' => null, 'note' => null];

    $stmt = $pdo->prepare("SELECT docx_path FROM invoice_templates WHERE kind = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$invoice['kind']]);
    $tplRel = $stmt->fetchColumn();
    if (!$tplRel) { $out['note'] = 'قالب Word برای این نوع صورتحساب بارگذاری نشده است.'; return $out; }

    $tplAbs = $siteRoot . '/' . $tplRel;
    if (!file_exists($tplAbs)) { $out['note'] = 'فایل قالب روی سرور پیدا نشد.'; return $out; }

    $lines = $pdo->prepare("SELECT il.*, c.name AS company_name FROM invoice_lines il
                             LEFT JOIN companies c ON c.id = il.company_id WHERE il.invoice_id = ? ORDER BY il.id");
    $lines->execute([$invoice['id']]);
    $rows = $lines->fetchAll();

    $vars = fin_invoice_vars($invoice, $rows, $companyName, $period['title'], intval(fin_settings($pdo)['default_installments']));

    $opts = json_decode((string)($invoice['options_json'] ?? ''), true) ?: [];
    $opts['period_title'] = $period['title'];
    $destDocx = $folder . '/صورتحساب.docx';
    $okDocx = fin_fill_docx($tplAbs, $destDocx, $vars, $invoice['kind'], $rows, $opts);
    if (!$okDocx) { $out['note'] = 'پرکردن قالب Word ممکن نشد (ZipArchive در دسترس نیست).'; return $out; }
    $out['docx'] = ltrim(str_replace($siteRoot, '', $destDocx), '/');

    // تبدیل به PDF - سه مسیر به‌ترتیب امتحان می‌شود تا یکی جواب دهد
    $destPdf = $folder . '/صورتحساب.pdf';
    $out['pdf'] = fin_docx_to_pdf($destDocx, $folder, $destPdf, $siteRoot);
    if (!$out['pdf']) {
        $out['note'] = 'فایل Word ساخته شد، ولی تبدیل خودکار به PDF ممکن نشد. می‌توانید فایل Word را باز و دستی PDF بگیرید.';
    }
    return $out;
}

// مقدارِ کدهای {{...}}ِ قالبِ صورتحساب (هم صدورِ واقعی و هم پیش‌نمایشِ قالب از همین تابع می‌خوانند)
function fin_invoice_vars($invoice, array $rows, $companyName, $periodTitle, $instCount) {
    $monthly = array_sum(array_map(fn($r) => intval($r['monthly_amount'] ?? 0), $rows));
    if ($invoice['kind'] === 'SUMMARY') {
        $third = array_sum(array_map(fn($r) => intval($r['third_count'] ?? 0), $rows));
        $body  = array_sum(array_map(fn($r) => intval($r['body_count'] ?? 0), $rows));
        $count = array_sum(array_map(fn($r) => intval($r['policy_count'] ?? 0), $rows));
    } else {
        $body  = count(array_filter($rows, fn($r) => fin_is_body($r['insurance_type'] ?? '')));
        $count = count($rows);
        $third = $count - $body;
    }
    return [
        'شماره_صورتحساب' => $invoice['invoice_no'],
        'تاریخ_صدور'     => jalali_from_gregorian_ts_dotted(time()),
        'دوره'           => $periodTitle,
        'نام_شرکت'       => $companyName ?: 'کل دوره',
        'نوع_صورتحساب'   => fin_kind_fa($invoice['kind']),
        'جمع_کل'         => number_format((int)$invoice['total_amount']),
        'جمع_کل_حروف'    => fin_num_to_words((int)$invoice['total_amount']),
        'تعداد_بیمه_نامه'=> (string)$count,
        'تعداد_ثالث'     => (string)$third,
        'تعداد_بدنه'     => (string)$body,
        'قسط_ماهانه'     => number_format($monthly),
        'قسط_ماهانه_حروف'=> fin_num_to_words($monthly),
        'تعداد_اقساط'    => (string)$instCount,
    ];
}

// فهرستِ همه‌ی کدهای قابلِ استفاده در قالبِ صورتحساب، با توضیح (برای راهنما و بررسیِ قالب)
function fin_invoice_var_catalog() {
    return [
        'شماره_صورتحساب'  => 'شماره‌ی صورتحساب (مثلاً SM49357-05-00012)',
        'تاریخ_صدور'      => 'تاریخِ صدورِ صورتحساب (شمسی)',
        'دوره'            => 'عنوانِ دوره‌ی مالی (مثلاً آبان ۱۴۰۵)',
        'نام_شرکت'        => 'نامِ شرکت (در تجمیعی/تلفیقی: نامِ واردشده یا «کل دوره»)',
        'نوع_صورتحساب'    => 'تجمیعی / تفکیکی / تلفیقی',
        'جمع_کل'          => 'جمعِ کلِ حق بیمه (سه‌رقم‌سه‌رقم)',
        'جمع_کل_حروف'     => 'جمعِ کل به حروف',
        'تعداد_بیمه_نامه' => 'تعدادِ بیمه‌نامه‌ها',
        'تعداد_ثالث'      => 'تعدادِ بیمه‌نامه‌های ثالث',
        'تعداد_بدنه'      => 'تعدادِ بیمه‌نامه‌های بدنه',
        'قسط_ماهانه'      => 'جمعِ قسطِ ماهانه',
        'قسط_ماهانه_حروف' => 'جمعِ قسطِ ماهانه به حروف',
        'تعداد_اقساط'     => 'تعدادِ اقساطِ پیش‌فرض',
        'جدول'            => 'جدولِ ریزِ صورتحساب (ستون‌ها طبقِ تنظیماتِ همان نوع) - کلِ پاراگرافِ کد با جدول جایگزین می‌شود',
    ];
}
// کدهای قدیمیِ جدول که هنوز پشتیبانی می‌شوند
function fin_invoice_legacy_codes() { return ['جدول_کلی', 'جدول_تفکیکی', 'جدول_پرسنلی']; }

// Word گاهی یک کد را در چند تکه (run) می‌شکند (غلط‌یاب، تغییرِ قلم، bookmark و ...). اگر متنِ یک پاراگراف
// کدی دارد که در XML یک‌تکه نیست، کلِ متنِ آن پاراگراف در اولین تکه می‌نشیند تا کد قابلِ جایگزینی شود.
function fin_docx_heal_codes($xml) {
    $xml = preg_replace('/<\/w:t>\s*<\/w:r>\s*<w:r[^>]*>\s*(?:<w:rPr>.*?<\/w:rPr>)?\s*<w:t[^>]*>/s', '', $xml);
    return preg_replace_callback('/<w:p\b[^>]*>.*?<\/w:p>/s', function ($m) {
        $p = $m[0];
        if (strpos($p, '{') === false) return $p;
        if (!preg_match_all('/<w:t(?:\s[^>]*)?>([^<]*)<\/w:t>/', $p, $tm)) return $p;
        $text = implode('', $tm[1]);
        if (!preg_match_all('/\{\{[^{}]{1,80}\}\}/u', $text, $codes)) return $p;
        $broken = false;
        foreach ($codes[0] as $c) if (strpos($p, $c) === false) { $broken = true; break; }
        if (!$broken) return $p;
        $i = 0;
        return preg_replace_callback('/<w:t(?:\s[^>]*)?>([^<]*)<\/w:t>/', function ($t) use (&$i, $text) {
            return $i++ === 0 ? '<w:t xml:space="preserve">' . $text . '</w:t>' : '<w:t></w:t>';
        }, $p);
    }, $xml);
}

// بخش‌های متنیِ قالب که کدها در آن‌ها جایگزین می‌شوند: بدنه + سربرگ‌ها + پاورقی‌ها
function fin_docx_parts(ZipArchive $zip) {
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if ($n === 'word/document.xml' || preg_match('#^word/(header|footer)\d*\.xml$#', $n)) $parts[] = $n;
    }
    return $parts;
}

// خواندن و بررسیِ قالب: کدهای به‌کاررفته، کدهای ناشناخته، وجودِ جدول و هشدارها
function fin_tpl_analyze($docxAbs) {
    $out = ['ok' => false, 'vars' => [], 'known' => [], 'unknown' => [], 'has_table' => false, 'warnings' => [], 'pages_hint' => null];
    if (!class_exists('ZipArchive')) { $out['error'] = 'ZipArchive روی سرور فعال نیست.'; return $out; }
    if (!is_file($docxAbs)) { $out['error'] = 'فایلِ قالب پیدا نشد.'; return $out; }
    $zip = new ZipArchive();
    if ($zip->open($docxAbs) !== true) { $out['error'] = 'فایل docx معتبر نیست.'; return $out; }
    if ($zip->locateName('word/document.xml') === false) { $zip->close(); $out['error'] = 'این فایل سندِ Word نیست (document.xml ندارد).'; return $out; }
    $catalog = fin_invoice_var_catalog();
    $legacy = fin_invoice_legacy_codes();
    $found = [];
    $inHeader = [];
    foreach (fin_docx_parts($zip) as $part) {
        $xml = fin_docx_heal_codes((string)$zip->getFromName($part));
        if (!preg_match_all('/\{\{([^{}<>]{1,80})\}\}/u', $xml, $m)) continue;
        foreach ($m[1] as $v) {
            $v = trim($v);
            $found[$v] = true;
            if ($part !== 'word/document.xml' && in_array($v, array_merge(['جدول'], $legacy), true)) $inHeader[] = $v;
        }
    }
    $zip->close();
    $out['ok'] = true;
    $out['vars'] = array_keys($found);
    foreach ($out['vars'] as $v) {
        if (isset($catalog[$v]) || in_array($v, $legacy, true)) $out['known'][] = $v;
        else $out['unknown'][] = $v;
    }
    $out['has_table'] = (bool)array_intersect($out['vars'], array_merge(['جدول'], $legacy));
    if (!$out['has_table']) $out['warnings'][] = 'کدِ {{جدول}} در قالب نیست؛ صورتحساب بدونِ جدولِ ریز ساخته می‌شود.';
    if ($inHeader) $out['warnings'][] = 'کدِ جدول در سربرگ/پاورقی است؛ جدول فقط در بدنه‌ی سند درج می‌شود.';
    if ($out['unknown']) $out['warnings'][] = 'کدهای ناشناخته در خروجی همان‌طور خام می‌مانند: ' . implode('، ', $out['unknown']);
    if (!in_array('جمع_کل', $out['vars'], true)) $out['warnings'][] = 'کدِ {{جمع_کل}} در قالب نیست.';
    return $out;
}

// داده‌ی نمونه برای پیش‌نمایشِ قالب (شکلِ ردیف‌ها همان invoice_lines است)
function fin_tpl_sample_rows($kind) {
    if ($kind === 'SUMMARY') {
        return [
            ['person_name' => 'شرکت نمونه الف', 'company_name' => 'شرکت نمونه الف', 'policy_count' => 12, 'third_count' => 9, 'body_count' => 3, 'total_premium' => 1850000000, 'monthly_amount' => 205555556],
            ['person_name' => 'شرکت نمونه ب', 'company_name' => 'شرکت نمونه ب', 'policy_count' => 5, 'third_count' => 5, 'body_count' => 0, 'total_premium' => 640000000, 'monthly_amount' => 71111111],
            ['person_name' => 'شرکت نمونه ج', 'company_name' => 'شرکت نمونه ج', 'policy_count' => 3, 'third_count' => 1, 'body_count' => 2, 'total_premium' => 925000000, 'monthly_amount' => 102777778],
        ];
    }
    $base = [
        ['علی رضایی', '0012345678', '1024', '12ایران - 345 ب 66', 'ثالث', '2/49357/5051-0/1405/876', 98500000],
        ['مریم احمدی', '0023456789', '1031', '44ایران - 555 د 11', 'بدنه', '2/49357/5052-0/1405/877', 154000000],
        ['حسین کریمی', '0034567890', '1047', '13ایران - 111 ج 22', 'ثالث', '2/49357/5051-0/1405/878', 87250000],
        ['زهرا موسوی', '0045678901', '1052', '20ایران - 742 ص 35', 'ثالث', '2/49357/5051-0/1405/879', 102300000],
    ];
    $rows = [];
    foreach ($base as $i => $b) {
        $rows[] = ['person_name' => $b[0], 'national_code' => $b[1], 'personnel_code' => $b[2], 'plate' => $b[3], 'insurance_type' => $b[4],
                   'policy_number' => $b[5], 'total_premium' => $b[6], 'monthly_amount' => intdiv($b[6], 9), 'issue_date' => '1405/08/' . sprintf('%02d', 3 + $i * 4),
                   'company_name' => $kind === 'PERSONNEL' && $i >= 2 ? 'شرکت نمونه ب' : 'شرکت نمونه الف'];
    }
    return $rows;
}

// ساختِ پیش‌نمایشِ قالب با داده‌ی نمونه و تنظیماتِ فعلیِ ستون‌ها؛ خروجی در $dir
function fin_tpl_render_preview($pdo, $kind, $tplAbs, $dir) {
    $s = fin_settings($pdo);
    $rows = fin_tpl_sample_rows($kind);
    $total = array_sum(array_map(fn($r) => intval($r['total_premium']), $rows));
    $opts = [
        'columns' => fin_invoice_columns($kind, json_decode((string)($s['inv_cols_' . $kind] ?? ''), true) ?: []),
        'full_policy' => ($s['inv_full_policy'] ?? '1') === '1',
        'company_subtotal' => ($s['inv_company_subtotal'] ?? '0') === '1',
        'merge_company' => ($s['inv_merge_company'] ?? '0') === '1',
        'period_title' => 'آبان ۱۴۰۵ (نمونه)',
    ];
    $invoice = ['id' => 0, 'kind' => $kind, 'invoice_no' => ($s['invoice_prefix'] ?? 'SM49357') . '-05-00000', 'total_amount' => $total];
    $vars = fin_invoice_vars($invoice, $rows, $kind === 'DETAILED' ? 'شرکت نمونه الف' : 'شرکت نمونه (دستی)', $opts['period_title'], intval($s['default_installments'] ?? 9));
    $docx = $dir . '/preview.docx';
    if (!fin_fill_docx($tplAbs, $docx, $vars, $kind, $rows, $opts)) return ['ok' => false, 'error' => 'پرکردنِ قالب ممکن نشد (ZipArchive در دسترس نیست یا قالب خراب است).'];
    $siteRoot = dirname(__DIR__);
    $pdf = fin_docx_to_pdf($docx, $dir, $dir . '/preview.pdf', $siteRoot);
    return ['ok' => true, 'docx' => $docx, 'pdf' => $pdf ? $dir . '/preview.pdf' : null, 'html' => fin_docx_to_html($docx), 'vars' => $vars];
}

// نمایشِ HTMLِ سادهٔ یک docx (پاراگراف، تراز، پررنگ، اندازه، جدول با ادغامِ خانه‌ها) برای پیش‌نمایش در پنل،
// بدون نیاز به LibreOffice. هدف شبیه‌بودنِ کلیِ چیدمان است، نه کپیِ پیکسلی.
function fin_docx_to_html($docxAbs) {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($docxAbs) !== true) return '';
    $xml = (string)$zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === '') return '';
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) return '';
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', $W);
    $attr = function ($el, $q) use ($xp, $W) {
        $n = $xp->query($q, $el)->item(0);
        return $n ? $n->getAttributeNS($W, 'val') : null;
    };
    $para = function ($p) use ($xp, $attr) {
        $jc = $attr($p, 'w:pPr/w:jc');
        $align = ['center' => 'center', 'left' => 'left', 'right' => 'right', 'both' => 'justify', 'start' => 'right', 'end' => 'left'][$jc] ?? '';
        $html = '';
        foreach ($xp->query('.//w:r', $p) as $r) {
            $t = '';
            foreach ($xp->query('w:t|w:tab|w:br', $r) as $c) {
                if ($c->localName === 't') $t .= htmlspecialchars($c->textContent, ENT_QUOTES, 'UTF-8');
                elseif ($c->localName === 'tab') $t .= '&emsp;';
                else $t .= '<br>';
            }
            if ($t === '') continue;
            $st = '';
            if ($xp->query('w:rPr/w:b|w:rPr/w:bCs', $r)->length) $st .= 'font-weight:700;';
            $sz = $attr($r, 'w:rPr/w:szCs') ?: $attr($r, 'w:rPr/w:sz');
            if ($sz) $st .= 'font-size:' . round(intval($sz) / 2 * 1.333, 1) . 'px;';
            $col = $attr($r, 'w:rPr/w:color');
            if ($col && $col !== 'auto' && preg_match('/^[0-9A-Fa-f]{6}$/', $col)) $st .= 'color:#' . $col . ';';
            $html .= $st ? '<span style="' . $st . '">' . $t . '</span>' : $t;
        }
        return '<p style="margin:0 0 4px;min-height:1em;' . ($align ? 'text-align:' . $align . ';' : '') . '">' . ($html === '' ? '&nbsp;' : $html) . '</p>';
    };
    $out = '';
    $body = $xp->query('/w:document/w:body')->item(0);
    if (!$body) return '';
    foreach ($body->childNodes as $node) {
        if (!($node instanceof DOMElement)) continue;
        if ($node->localName === 'p') { $out .= $para($node); continue; }
        if ($node->localName !== 'tbl') continue;
        $out .= '<table style="border-collapse:collapse;margin:6px auto;width:100%">';
        $rowsEl = $xp->query('w:tr', $node);
        $grid = [];
        foreach ($rowsEl as $ri => $tr) {
            $ci = 0;
            foreach ($xp->query('w:tc', $tr) as $tc) {
                $span = max(1, intval($attr($tc, 'w:tcPr/w:gridSpan') ?: 1));
                $vm = $xp->query('w:tcPr/w:vMerge', $tc)->item(0);
                $grid[$ri][$ci] = ['tc' => $tc, 'span' => $span, 'vm' => $vm ? ($vm->getAttributeNS($W, 'val') === 'restart' ? 'restart' : 'continue') : null];
                $ci += $span;
            }
        }
        foreach ($grid as $ri => $cells) {
            $out .= '<tr>';
            foreach ($cells as $ci => $c) {
                if ($c['vm'] === 'continue') continue;
                $rs = 1;
                if ($c['vm'] === 'restart') { for ($k = $ri + 1; isset($grid[$k][$ci]) && $grid[$k][$ci]['vm'] === 'continue'; $k++) $rs++; }
                $shd = $xp->query('w:tcPr/w:shd', $c['tc'])->item(0);
                $bg = $shd ? $shd->getAttributeNS($W, 'fill') : '';
                $inner = '';
                foreach ($xp->query('w:p', $c['tc']) as $p) $inner .= $para($p);
                $out .= '<td' . ($c['span'] > 1 ? ' colspan="' . $c['span'] . '"' : '') . ($rs > 1 ? ' rowspan="' . $rs . '"' : '')
                      . ' style="border:1px solid #333;padding:2px 4px;vertical-align:middle;' . ($bg && $bg !== 'auto' && preg_match('/^[0-9A-Fa-f]{6}$/', $bg) ? 'background:#' . $bg . ';' : '') . '">' . $inner . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</table>';
    }
    return $out;
}

// قالبِ آماده‌ی شروع: یک docx ساده با سربرگ، کدهای اصلی و {{جدول}} که در Word قابلِ ویرایش است
function fin_tpl_starter_docx($kind, $dest) {
    if (!class_exists('ZipArchive')) return false;
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $p = function ($text, $opts = []) use ($esc) {
        $sz = $opts['sz'] ?? 24; $font = $opts['font'] ?? 'B Nazanin';
        $rpr = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="' . $font . '" w:hint="cs"/>' . (!empty($opts['b']) ? '<w:b/><w:bCs/>' : '')
             . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>';
        return '<w:p><w:pPr><w:bidi/><w:spacing w:after="80"/><w:jc w:val="' . ($opts['jc'] ?? 'right') . '"/></w:pPr>'
             . ($text === '' ? '' : '<w:r><w:rPr>' . $rpr . '<w:rtl/></w:rPr><w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r>') . '</w:p>';
    };
    $title = ['SUMMARY' => 'صورتحسابِ تجمیعیِ حق بیمه', 'DETAILED' => 'صورتحسابِ حق بیمه', 'PERSONNEL' => 'صورتحسابِ تلفیقیِ حق بیمه'][$kind] ?? 'صورتحساب';
    $body = $p('شماره: {{شماره_صورتحساب}}', ['jc' => 'left', 'sz' => 20])
          . $p('تاریخ: {{تاریخ_صدور}}', ['jc' => 'left', 'sz' => 20])
          . $p($title, ['jc' => 'center', 'sz' => 32, 'b' => true, 'font' => 'B Titr'])
          . $p('دوره‌ی {{دوره}}', ['jc' => 'center', 'sz' => 22])
          . $p('')
          . $p('مدیریتِ محترمِ {{نام_شرکت}}', ['b' => true, 'sz' => 26])
          . $p('با سلام؛ احتراماً صورتحسابِ حق بیمه‌ی بیمه‌نامه‌های صادرشده در دوره‌ی {{دوره}} به شرحِ جدولِ زیر تقدیم می‌گردد:', ['sz' => 24])
          . $p('{{جدول}}')
          . $p('')
          . $p('تعدادِ بیمه‌نامه‌ها: {{تعداد_بیمه_نامه}} (ثالث: {{تعداد_ثالث}} · بدنه: {{تعداد_بدنه}})', ['sz' => 22])
          . $p('جمعِ کلِ حق بیمه: {{جمع_کل}} ریال ({{جمع_کل_حروف}} ریال)', ['sz' => 22, 'b' => true])
          . $p('قسطِ ماهانه: {{قسط_ماهانه}} ریال در {{تعداد_اقساط}} قسط', ['sz' => 22])
          . $p('')
          . $p('با تشکر', ['jc' => 'left', 'sz' => 24, 'b' => true]);
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
         . '<w:body>' . $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1000" w:bottom="1134" w:left="1000" w:header="708" w:footer="708" w:gutter="0"/><w:bidi/></w:sectPr></w:body></w:document>';
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
    $zip->addFromString('word/document.xml', $doc);
    $zip->close();
    return true;
}

// آیا روی سرور ابزاری برای تبدیلِ docx به pdf هست؟ (برای پیام در صفحه‌ی قالب‌ها)
function fin_pdf_converter_available() {
    if (!function_exists('shell_exec')) return false;
    foreach (['soffice', 'libreoffice'] as $bin) { $w = @shell_exec("command -v $bin 2>/dev/null"); if ($w && trim($w)) return true; }
    return file_exists('/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3') && file_exists('/home/besiteir/ocr-paddle/docx_to_pdf.py');
}

// تبدیل docx به pdf - چند روش به‌ترتیب اولویت
function fin_docx_to_pdf($docxPath, $folder, $destPdf, $siteRoot) {
    if (!function_exists('shell_exec')) return null;

    // روش ۱: LibreOffice (بهترین کیفیت، اگر نصب باشد)
    foreach (['soffice', 'libreoffice'] as $bin) {
        $which = @shell_exec("command -v $bin 2>/dev/null");
        if ($which && trim($which)) {
            $home = escapeshellarg(sys_get_temp_dir());
            @shell_exec("HOME=$home " . trim($which) . ' --headless --convert-to pdf --outdir '
                        . escapeshellarg($folder) . ' ' . escapeshellarg($docxPath) . ' 2>&1');
            if (file_exists($destPdf)) return ltrim(str_replace($siteRoot, '', $destPdf), '/');
        }
    }

    // روش ۲: پایتون با docx2pdf یا docx→pdf از طریق همان محیط مجازیِ OCR
    $pythonPath = '/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3';
    $converter  = '/home/besiteir/ocr-paddle/docx_to_pdf.py';
    if (file_exists($pythonPath) && file_exists($converter)) {
        @shell_exec('export PYTHONIOENCODING=utf8; ' . escapeshellarg($pythonPath) . ' '
                    . escapeshellarg($converter) . ' ' . escapeshellarg($docxPath) . ' '
                    . escapeshellarg($destPdf) . ' 2>&1');
        if (file_exists($destPdf)) return ltrim(str_replace($siteRoot, '', $destPdf), '/');
    }

    return null;
}

// پرکردن قالب docx
function fin_fill_docx($tplPath, $destPath, array $vars, $kind, array $rows, array $opts = []) {
    if (!class_exists('ZipArchive')) return false;
    if (!@copy($tplPath, $destPath)) return false;

    $zip = new ZipArchive();
    if ($zip->open($destPath) !== true) return false;
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) { $zip->close(); return false; }

    // Word گاهی کدها را بین چند <w:t> تکه می‌کند؛ تکه‌ها به‌هم چسبانده می‌شوند (fin_docx_heal_codes)
    $xml = fin_docx_heal_codes($xml);

    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    foreach ($vars as $k => $v) {
        $xml = str_replace('{{' . $k . '}}', $esc($v), $xml);
    }
    // کدهای سربرگ و پاورقی هم پر می‌شوند
    foreach (fin_docx_parts($zip) as $part) {
        if ($part === 'word/document.xml') continue;
        $hx = fin_docx_heal_codes((string)$zip->getFromName($part));
        foreach ($vars as $k => $v) $hx = str_replace('{{' . $k . '}}', $esc($v), $hx);
        $zip->addFromString($part, $hx);
    }

    // ------------------------------------------------------------------
    //  جدول پویا
    //  کد یکسان {{جدول}} در همه‌ی قالب‌ها کار می‌کند و جدول بر اساس نوعِ انتخاب‌شده‌ی
    //  صورتحساب ساخته می‌شود. کدهای اختصاصیِ قدیمی هم برای سازگاری پشتیبانی می‌شوند،
    //  تا قالب‌هایی که قبلاً ساخته شده‌اند از کار نیفتند.
    // ------------------------------------------------------------------
    $tableXml = $rows ? fin_build_docx_table($kind, $rows, $opts) : '';
    $codes = ['{{جدول}}', '{{جدول_کلی}}', '{{جدول_تفکیکی}}', '{{جدول_پرسنلی}}'];
    foreach ($codes as $code) {
        if (strpos($xml, $code) === false) continue;
        // کد داخل یک پاراگراف است؛ کل آن پاراگراف با جدول جایگزین می‌شود
        $pattern = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*' . preg_quote($code, '/') . '(?:(?!<\/w:p>).)*<\/w:p>/s';
        if (preg_match($pattern, $xml)) $xml = preg_replace($pattern, $tableXml, $xml, 1);
        else $xml = str_replace($code, $tableXml, $xml);
        $tableXml = '';   // جدول فقط یک‌بار درج می‌شود؛ کدهای تکراری بعدی حذف می‌شوند
    }

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    return true;
}

// =====================================================================
//  ساخت XML جدول برای درج در docx
//  سبک جدول عیناً از نمونه‌ی صورتحسابِ شرکت گرفته شده است:
//    - سربرگ: فونت B Titr، اندازه ۸pt
//    - بدنه:  فونت B Nazanin، اندازه ۱۰pt
//    - حاشیه: تک‌خط نازک مشکی، بدون رنگ پس‌زمینه
//    - همه‌ی سلول‌ها وسط‌چین (افقی و عمودی)، راست‌به‌چپ
//  عرض هر ستون متناسب با نوع محتوایش تعیین می‌شود و اندازه‌ی فونت بر اساس تعداد ستون‌ها
//  کم می‌شود تا محتوای هر سلول در یک خط جا شود و ظاهر جدول به‌هم نریزد.
// =====================================================================
// ستون‌هایی که در جدول صورتحساب قابل انتخاب‌اند (هم‌شکل با INVOICE_COLUMN_POOL ماموت):
// کلید => [عنوان, وزنِ عرض, فقط برای کدام نوع‌ها]
function fin_invoice_column_pool() {
    $per = ['DETAILED', 'PERSONNEL'];
    $all = ['DETAILED', 'PERSONNEL', 'SUMMARY'];
    return [
        'company_name'   => ['نام شرکت', 18, $all],
        'period'         => ['دوره مالی', 10, $all],
        'person_name'    => ['نام پرسنل / بیمه‌گذار', 18, $per],
        'personnel_code' => ['کد پرسنلی', 9, $per],
        'national_code'  => ['کد ملی', 12, $per],
        'plate'          => ['شماره انتظامی', 14, $per],
        'insurance_type' => ['نوع بیمه', 8, $per],
        'policy_number'  => ['شماره بیمه‌نامه', 20, $per],
        'issue_date'     => ['تاریخ صدور', 10, $per],
        'policy_count'   => ['تعداد بیمه‌نامه', 9, ['SUMMARY']],
        'third_count'    => ['تعداد ثالث', 8, ['SUMMARY']],
        'body_count'     => ['تعداد بدنه', 8, ['SUMMARY']],
        'total_premium'  => ['حق بیمه', 13, $all],
        'monthly_amount' => ['قسط ماهانه', 12, $all],
    ];
}

// ستون‌های پیش‌فرض هر نوع (همان جدول‌های قبلیِ سایت)
function fin_invoice_default_columns($kind) {
    if ($kind === 'SUMMARY')   return ['company_name', 'third_count', 'body_count', 'policy_count', 'total_premium', 'monthly_amount'];
    if ($kind === 'PERSONNEL') return ['person_name', 'company_name', 'personnel_code', 'national_code', 'insurance_type', 'total_premium'];
    return ['person_name', 'national_code', 'personnel_code', 'plate', 'insurance_type', 'policy_number', 'total_premium', 'monthly_amount'];
}

// ستون‌های معتبرِ انتخاب‌شده برای یک نوع (به همان ترتیب انتخاب)
function fin_invoice_columns($kind, $cols) {
    $pool = fin_invoice_column_pool();
    $out = [];
    foreach ((array)$cols as $k) {
        if (isset($pool[$k]) && in_array($kind, $pool[$k][2], true) && !in_array($k, $out, true)) $out[] = $k;
    }
    return $out ?: fin_invoice_default_columns($kind);
}

// شماره بیمه‌نامه‌ی کوتاه: فقط دو بخش آخر (مثلاً از «2/49357/5051-0/1405/876» فقط «1405/876»)
function fin_format_policy_number($s, $full = true) {
    $s = trim((string)$s);
    if ($full || $s === '') return $s;
    $parts = array_values(array_filter(explode('/', $s), function ($p) { return $p !== ''; }));
    return count($parts) >= 2 ? implode('/', array_slice($parts, -2)) : $s;
}

// =====================================================================
//  ساخت XML جدول برای درج در docx
//  سبک جدول عیناً از نمونه‌ی صورتحسابِ شرکت گرفته شده است:
//    - سربرگ: فونت B Titr؛ بدنه: فونت B Nazanin؛ حاشیه‌ی تک‌خط نازک؛ همه‌ی سلول‌ها وسط‌چین و راست‌به‌چپ
//  $opts: columns (ترتیب و انتخاب ستون‌ها)، full_policy (شماره کامل بیمه‌نامه)،
//         company_subtotal (ردیف جمع هر شرکت - تفکیکی/تلفیقی)، merge_company (ادغام عمودی نام شرکت)، period_title
// =====================================================================
function fin_build_docx_table($kind, array $rows, array $opts = []) {
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $pool = fin_invoice_column_pool();
    $keys = fin_invoice_columns($kind, $opts['columns'] ?? []);
    $fullPolicy = !isset($opts['full_policy']) || !empty($opts['full_policy']);
    $subtotal = !empty($opts['company_subtotal']) && $kind !== 'SUMMARY';
    $merge = !empty($opts['merge_company']) && in_array('company_name', $keys, true);

    $cols = [['ردیف', 'row_no', 5]];
    foreach ($keys as $k) $cols[] = [$pool[$k][0], $k, $pool[$k][1]];

    $n = count($cols);
    $totalW = 9900;
    $sumWeight = array_sum(array_column($cols, 2));
    if ($n <= 5)      { $hSize = 16; $bSize = 20; }
    elseif ($n <= 7)  { $hSize = 14; $bSize = 18; }
    elseif ($n <= 9)  { $hSize = 12; $bSize = 14; }
    else              { $hSize = 11; $bSize = 12; }

    $border = '<w:tcBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '</w:tcBorders>';

    // $vmerge: null | 'restart' | 'continue' ؛ $shade: رنگ پس‌زمینه‌ی ردیف جمع شرکت
    $cell = function ($text, $w, $font, $size, $bold = false, $span = 1, $wrap = false, $vmerge = null, $shade = null) use ($esc, $border) {
        $gridSpan = $span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '';
        $vm = $vmerge === 'restart' ? '<w:vMerge w:val="restart"/>' : ($vmerge === 'continue' ? '<w:vMerge/>' : '');
        $sh = $shade ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $shade . '"/>' : '';
        $b = $bold ? '<w:b/><w:bCs/>' : '';
        $rpr = '<w:rFonts w:ascii="Arial" w:eastAsia="Times New Roman" w:hAnsi="Arial" w:cs="' . $font . '" w:hint="cs"/>'
             . $b . '<w:color w:val="000000"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';
        $margins = '<w:tcMar><w:top w:w="0" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/>'
                 . '<w:left w:w="28" w:type="dxa"/><w:right w:w="28" w:type="dxa"/></w:tcMar>';
        return '<w:tc><w:tcPr>'
             . '<w:tcW w:w="' . $w . '" w:type="dxa"/>' . $gridSpan . $vm . $border . $sh
             . ($wrap ? '' : '<w:noWrap/>') . $margins . '<w:vAlign w:val="center"/></w:tcPr>'   // ترتیب عناصر طبق استاندارد OOXML
             . '<w:p><w:pPr><w:bidi/><w:spacing w:after="0" w:line="60" w:lineRule="atLeast"/>'
             . '<w:jc w:val="center"/><w:rPr>' . $rpr . '</w:rPr></w:pPr>'
             . ($text === '' || $text === null ? '' : '<w:r><w:rPr>' . $rpr . '<w:rtl/></w:rPr><w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r>')
             . '</w:p></w:tc>';
    };

    $widths = [];
    foreach ($cols as $k => $cdef) $widths[$k] = (int) round($totalW * $cdef[2] / $sumWeight);

    $xml  = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:bidiVisual/>'
          . '<w:tblW w:w="' . $totalW . '" w:type="dxa"/><w:jc w:val="center"/>'
          . '<w:tblBorders>'
          . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
          . '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>';
    $xml .= '<w:tblGrid>';
    foreach ($widths as $w) $xml .= '<w:gridCol w:w="' . $w . '"/>';
    $xml .= '</w:tblGrid>';

    $xml .= '<w:tr><w:trPr><w:tblHeader/><w:jc w:val="center"/></w:trPr>';
    foreach ($cols as $k => $cdef) $xml .= $cell($cdef[0], $widths[$k], 'B Titr', $hSize, true, 1, true);
    $xml .= '</w:tr>';

    $numericKeys = ['total_premium', 'monthly_amount', 'policy_count', 'third_count', 'body_count'];
    $moneyKeys = ['total_premium', 'monthly_amount'];
    $value = function ($r, $key) use ($fullPolicy, $opts, $kind) {
        if ($key === 'policy_number') return fin_format_policy_number($r['policy_number'] ?? '', $fullPolicy);
        if ($key === 'company_name')  return $kind === 'SUMMARY' ? ($r['person_name'] ?? '') : ($r['company_name'] ?? '');
        if ($key === 'period')        return $opts['period_title'] ?? '';
        if (in_array($key, ['total_premium', 'monthly_amount'], true)) return number_format((int)($r[$key] ?? 0));
        return $r[$key] ?? '';
    };

    // گروه‌بندی بر اساس شرکت (برای ردیف جمع و ادغام نام شرکت، ردیف‌های پشت‌سرهمِ یک شرکت)
    $groups = [];
    foreach ($rows as $r) {
        $ck = trim((string)($kind === 'SUMMARY' ? ($r['person_name'] ?? '') : ($r['company_name'] ?? ''))) ?: 'نامشخص';
        if ($groups && $groups[count($groups) - 1][0] === $ck) $groups[count($groups) - 1][1][] = $r;
        else $groups[] = [$ck, [$r]];
    }

    // «جمع کل» روی ستون‌های غیرعددیِ ابتدایی ادغام می‌شود
    $firstNumeric = $n;
    foreach ($cols as $k => $cdef) { if (in_array($cdef[1], $numericKeys, true)) { $firstNumeric = $k; break; } }
    $spanCount = max(1, $firstNumeric);
    $spanW = 0;
    for ($k = 0; $k < $spanCount; $k++) $spanW += $widths[$k];
    $sumRow = function ($label, array $sums, $shade) use ($cell, $cols, $n, $spanCount, $spanW, $widths, $hSize, $moneyKeys) {
        $x = '<w:tr><w:trPr><w:cantSplit/><w:jc w:val="center"/></w:trPr>';
        $x .= $cell($label, $spanW, 'B Titr', $hSize, true, $spanCount, false, null, $shade);
        for ($k = $spanCount; $k < $n; $k++) {
            $key = $cols[$k][1];
            $v = array_key_exists($key, $sums) ? (in_array($key, $moneyKeys, true) ? number_format($sums[$key]) : $sums[$key]) : '';
            $x .= $cell($v, $widths[$k], 'B Titr', $hSize, true, 1, false, null, $shade);
        }
        return $x . '</w:tr>';
    };

    $i = 1;
    $total = array_fill_keys($numericKeys, 0);
    foreach ($groups as [$ck, $grp]) {
        $gSum = array_fill_keys($numericKeys, 0);
        foreach ($grp as $gi => $r) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/><w:jc w:val="center"/></w:trPr>';
            foreach ($cols as $k => $cdef) {
                $key = $cdef[1];
                $v = $key === 'row_no' ? $i : $value($r, $key);
                $vm = null;
                if ($merge && $key === 'company_name' && count($grp) > 1) {
                    $vm = $gi === 0 ? 'restart' : 'continue';
                    if ($gi > 0) $v = '';
                }
                $xml .= $cell($v, $widths[$k], 'B Nazanin', $bSize, false, 1, false, $vm);
            }
            $xml .= '</w:tr>';
            foreach ($numericKeys as $nk) { $gSum[$nk] += (int)($r[$nk] ?? 0); $total[$nk] += (int)($r[$nk] ?? 0); }
            $i++;
        }
        if ($subtotal) $xml .= $sumRow('جمع ' . $ck, $gSum, 'F2F2F2');
    }
    $xml .= $sumRow('جمع کل', $total, null);
    $xml .= '</w:tbl><w:p/>';
    return $xml;
}

// تبدیل تاریخ شمسی «۱۴۰۵/۰۶/۲۱» به تاریخ میلادیِ Y-m-d برای ذخیره در ستون DATE
function fin_jalali_to_date($s) {
    $s = (string)$s;
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], $en, $s);
    $s = str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'], $en, $s);
    if (!preg_match('/(\d{4})\D(\d{1,2})\D(\d{1,2})/', $s, $m)) return null;
    return date('Y-m-d', jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])));
}

// [سال، ماه، روز]ِ شمسی از رشته‌ای که کاربر تایپ کرده (رقم فارسی/عربی/لاتین، هر جداکننده‌ای).
// اگر خالی یا نامعتبر بود، امروز. (قبلاً intval روی رقم فارسی صفر می‌داد و فیش در دوره‌ی «سال ۰» می‌نشست.)
function fin_jalali_parts($s) {
    $d = fin_jalali_to_date($s);
    return jalali_from_gregorian_ts($d ? strtotime($d) : time());
}

// اعتبارسنجی نوع صورتحساب (سه نوع پشتیبانی می‌شود)
function fin_kind($k) {
    return in_array($k, ['SUMMARY', 'DETAILED', 'PERSONNEL'], true) ? $k : 'DETAILED';
}

// عنوان فارسی نوع صورتحساب
function fin_kind_fa($k) {
    return ['SUMMARY' => 'تجمیعی (کل دوره)', 'DETAILED' => 'تفکیکی (یک شرکت)',
            'PERSONNEL' => 'تلفیقی (ریز پرسنل)'][$k] ?? $k;
}

// =====================================================================
//  نمای یکپارچه‌ی اقساط: پرسنلی (policy_installments) + شرکتی (company_installments)
//  هر ردیف خروجی شکلِ یکسان دارد تا داشبورد، نمودارها، هشدارها، فهرست اقساط و اکسل جامع
//  از یک منبع بخوانند. فیلترها ($f):
//   source (P|C|'')، company_id، period (YYYY-MM)، year، from/to (تاریخ شمسی) روی date_field (due|issue)،
//   insurance_type (THIRD_PARTY|BODY|...)، status (PAID|PARTIAL|UNPAID|OVERDUE|UPCOMING|OPEN)،
//   pasargad (settled|open)، invoiced (1|0)، q
// =====================================================================
function fin_unified_installments($pdo, array $f = []) {
    fin_ensure_schema($pdo);
    fin_ensure_receipt_cols($pdo);
    $receiptsOf = ['P' => [], 'C' => []];   // فیش‌های فایلِ بیمه‌نامه (برای دکمه‌ی «فیش پرداختی» روی هر قسط)
    $cutoff = period_cutoff_eff($pdo);
    $today = date('Y-m-d');
    $near = date('Y-m-d', strtotime('+30 days'));
    $src = $f['source'] ?? '';
    $cid = intval($f['company_id'] ?? 0);
    $rows = [];

    $periodOf = function ($jy, $jm, $jd) use ($cutoff) {
        if ($jd > $cutoff) [$jy, $jm] = fin_next_month($jy, $jm);
        return [$jy, $jm];
    };

    if ($src === '' || $src === 'P') {
        $w = ["pc.status = 'ISSUED'"]; $p = [];
        if ($cid) { $w[] = "p.company_id = ?"; $p[] = $cid; }
        $st = $pdo->prepare("
            SELECT pi.id, pi.case_id AS ref_id, pi.inst_number, pi.amount, pi.due_jalali, pi.due_date, pi.tracking_code,
                   COALESCE(pi.settled_to_pasargad,0) AS settled, pc.plate, pc.insurance_type, pc.policy_number,
                   pc.insured_name, pc.total_premium, pc.issued_at, pc.policy_issue_date, pc.receipts_json, COALESCE(pc.is_invoiced,0) AS is_invoiced,
                   p.full_name AS holder_name, p.personnel_code, p.national_code,
                   c.id AS company_id, c.name AS company_name,
                   COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.installment_id = pi.id),0) AS paid
            FROM policy_installments pi
            JOIN policy_cases pc ON pc.id = pi.case_id
            JOIN persons p ON p.id = pc.person_id
            LEFT JOIN companies c ON c.id = p.company_id
            WHERE " . implode(' AND ', $w));
        $st->execute($p);
        foreach ($st->fetchAll() as $r) {
            if (!empty($r['receipts_json'])) $receiptsOf['P'][(int)$r['ref_id']] = $r['receipts_json'];
            [$iy, $im, $id] = fin_issue_jalali($r['policy_issue_date'], $r['issued_at']);
            [$py, $pm] = $periodOf($iy, $im, $id);
            $rows[] = [
                'source' => 'P', 'id' => (int)$r['id'], 'ref_id' => (int)$r['ref_id'], 'inst_number' => (int)$r['inst_number'],
                'amount' => (int)$r['amount'], 'paid' => (int)$r['paid'], 'settled' => (int)$r['settled'],
                'due_jalali' => $r['due_jalali'], 'due_date' => substr((string)$r['due_date'], 0, 10), 'tracking_code' => $r['tracking_code'],
                'company_id' => $r['company_id'] ? (int)$r['company_id'] : null, 'company_name' => $r['company_name'] ?: 'بدون شرکت',
                'insured' => $r['insured_name'] ?: $r['holder_name'], 'holder_name' => $r['holder_name'],
                'personnel_code' => $r['personnel_code'], 'national_code' => $r['national_code'],
                'plate' => $r['plate'], 'insurance_type' => $r['insurance_type'], 'policy_number' => $r['policy_number'],
                'premium' => (int)money_to_int($r['total_premium']), 'is_invoiced' => (int)$r['is_invoiced'],
                'issue_jalali' => sprintf('%04d/%02d/%02d', $iy, $im, $id), 'period' => sprintf('%04d-%02d', $py, $pm),
                'insurer' => 'PASARGAD',
            ];
        }
    }

    if ($src === '' || $src === 'C') {
        try {
            $w = ["crp.status = 'ISSUED'"]; $p = [];
            if ($cid) { $w[] = "cr.company_id = ?"; $p[] = $cid; }
            $st = $pdo->prepare("
                SELECT ci.id, ci.plate_id AS ref_id, ci.inst_number, ci.amount, ci.due_jalali, ci.due_date, ci.tracking_code,
                       COALESCE(ci.settled_to_pasargad,0) AS settled, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no,
                       crp.insurance_type, crp.policy_number, crp.total_premium, crp.issued_at, crp.policy_issue_date, crp.receipts_json, cr.id AS request_id, cr.insurer,
                       c.id AS company_id, c.name AS company_name,
                       COALESCE((SELECT SUM(a.amount) FROM company_payment_allocations a JOIN company_payments cp ON cp.id = a.payment_id
                                 WHERE a.installment_id = ci.id AND cp.target = 'US'),0) AS paid
                FROM company_installments ci
                JOIN company_request_plates crp ON crp.id = ci.plate_id
                JOIN company_requests cr ON cr.id = crp.request_id
                LEFT JOIN companies c ON c.id = cr.company_id
                WHERE " . implode(' AND ', $w));
            $st->execute($p);
            foreach ($st->fetchAll() as $r) {
                if (!empty($r['receipts_json'])) $receiptsOf['C'][(int)$r['ref_id']] = $r['receipts_json'];
                [$iy, $im, $id] = fin_issue_jalali($r['policy_issue_date'] ?? null, $r['issued_at']);
                [$py, $pm] = $periodOf($iy, $im, $id);
                $plate = function_exists('company_row_label') ? company_row_label($r)
                       : trim("{$r['plate_p1']}ایران - {$r['plate_p2']} {$r['plate_letter']} {$r['plate_p4']}");
                $rows[] = [
                    'source' => 'C', 'id' => (int)$r['id'], 'ref_id' => (int)$r['ref_id'], 'inst_number' => (int)$r['inst_number'],
                    'amount' => (int)$r['amount'], 'paid' => (int)$r['paid'], 'settled' => (int)$r['settled'],
                    'due_jalali' => $r['due_jalali'], 'due_date' => substr((string)$r['due_date'], 0, 10), 'tracking_code' => $r['tracking_code'],
                    'company_id' => $r['company_id'] ? (int)$r['company_id'] : null, 'company_name' => $r['company_name'] ?: 'بدون شرکت',
                    'insured' => $r['company_name'], 'holder_name' => $r['company_name'], 'personnel_code' => null, 'national_code' => null,
                    'plate' => $plate, 'insurance_type' => $r['insurance_type'], 'policy_number' => $r['policy_number'],
                    'premium' => (int)money_to_int($r['total_premium']), 'is_invoiced' => 0, 'request_id' => (int)$r['request_id'],
                    'insurer' => $r['insurer'] ?: 'PASARGAD',
                    'issue_jalali' => sprintf('%04d/%02d/%02d', $iy, $im, $id), 'period' => sprintf('%04d-%02d', $py, $pm),
                ];
            }
        } catch (Throwable $e) { error_log('[fin_unified company] ' . $e->getMessage()); }
    }

    // وضعیت هر قسط
    $out = [];
    $from = fin_parse_jalali($f['from'] ?? ''); $to = fin_parse_jalali($f['to'] ?? '');
    $fromS = $from ? sprintf('%04d/%02d/%02d', ...$from) : null;
    $toS = $to ? sprintf('%04d/%02d/%02d', ...$to) : null;
    $dateField = ($f['date_field'] ?? 'due') === 'issue' ? 'issue_jalali' : 'due_jalali';
    $q = trim(p2e_digits((string)($f['q'] ?? '')));
    // فیشِ هر قسط: اقساطِ هر بیمه‌نامه با فیش‌های داخلِ فایلش جفت می‌شوند
    $pairs = [];
    if ($receiptsOf['P'] || $receiptsOf['C']) {
        $groups = [];
        foreach ($rows as $r) if (isset($receiptsOf[$r['source']][$r['ref_id']])) $groups[$r['source'] . ':' . $r['ref_id']][] = ['id' => $r['id'], 'inst_number' => $r['inst_number'], 'due_jalali' => $r['due_jalali']];
        foreach ($groups as $gk => $insts) {
            [$gs, $gid] = explode(':', $gk);
            foreach (fin_pair_receipts($insts, $receiptsOf[$gs][(int)$gid]) as $iid => $rc) $pairs[$gs . ':' . $iid] = $rc;
        }
    }
    $reqF = intval($f['request_id'] ?? 0);   // اقساطِ یک درخواستِ شرکتی (برای جزئیاتِ درخواست)
    foreach ($rows as $r) {
        if ($reqF && ($r['source'] !== 'C' || intval($r['request_id'] ?? 0) !== $reqF)) continue;
        $r['receipt'] = $pairs[$r['source'] . ':' . $r['id']] ?? null;
        $r['remaining'] = max(0, $r['amount'] - $r['paid']);
        $r['pay_status'] = $r['remaining'] <= 0 ? 'PAID' : ($r['paid'] > 0 ? 'PARTIAL' : 'UNPAID');
        $r['overdue'] = $r['remaining'] > 0 && $r['due_date'] !== '' && $r['due_date'] < $today;
        $r['upcoming'] = $r['remaining'] > 0 && $r['due_date'] >= $today && $r['due_date'] <= $near;
        $r['due_jalali'] = p2e_digits((string)$r['due_jalali']);

        if (!empty($f['period']) && $r['period'] !== $f['period']) continue;
        if (!empty($f['year']) && substr($r['period'], 0, 4) !== (string)$f['year']) continue;
        if ($fromS && strcmp($r[$dateField], $fromS) < 0) continue;
        if ($toS && strcmp($r[$dateField], $toS) > 0) continue;
        if (!empty($f['insurance_type']) && $r['insurance_type'] !== $f['insurance_type']) continue;
        $s = $f['status'] ?? '';
        if ($s === 'OVERDUE' && !$r['overdue']) continue;
        if ($s === 'UPCOMING' && !$r['upcoming']) continue;
        if ($s === 'OPEN' && $r['remaining'] <= 0) continue;
        if (in_array($s, ['PAID', 'PARTIAL', 'UNPAID'], true) && $r['pay_status'] !== $s) continue;
        if (($f['pasargad'] ?? '') === 'settled' && !$r['settled']) continue;
        if (($f['pasargad'] ?? '') === 'open' && $r['settled']) continue;
        if (isset($f['invoiced']) && $f['invoiced'] !== '' && (int)$r['is_invoiced'] !== (int)$f['invoiced']) continue;
        if ($q !== '') {
            $hay = p2e_digits(implode(' ', [$r['insured'], $r['holder_name'], $r['company_name'], $r['plate'], $r['policy_number'], $r['tracking_code'], $r['national_code'], $r['personnel_code']]));
            if (mb_stripos($hay, $q) === false) continue;
        }
        $out[] = $r;
    }
    usort($out, function ($a, $b) {
        return [$a['due_date'], $a['company_name'], $a['insured'], $a['inst_number']] <=> [$b['due_date'], $b['company_name'], $b['insured'], $b['inst_number']];
    });
    return $out;
}

// بدنه یا ثالث؟ (برای شمارش «تعداد ثالث/بدنه» در صورتحساب تجمیعی)
function fin_is_body($type) {
    $t = strtoupper((string)$type);
    return strpos($t, 'BODY') !== false || mb_strpos((string)$type, 'بدنه') !== false;
}

// حالتِ فیلتر صدور: uninvoiced | invoiced | all؛ دو حالت آخر فقط با تنظیم «چند صورتحساب برای یک بیمه‌نامه»
function fin_invoice_mode($pdo, $mode) {
    $mode = in_array($mode, ['uninvoiced', 'invoiced', 'all'], true) ? $mode : 'uninvoiced';
    if ($mode !== 'uninvoiced' && (fin_settings($pdo)['allow_multiple_invoices'] ?? '0') !== '1') {
        return ['ok' => false, 'error' => 'صدور صورتحساب برای بیمه‌نامه‌هایی که قبلاً صورتحساب خورده‌اند، در تنظیمات مالی غیرفعال است (گزینه‌ی «چند صورتحساب برای یک بیمه‌نامه»).'];
    }
    return $mode;
}

// =====================================================================
//  قراردادهای مالی و «روشِ پرداختِ» هر بیمه‌نامه
//   - قرارداد: شماره (مثلاً 5051/30000/1404، کلید = 5051)، نام، نقد/اقساط، تعداد قسط، سررسیدِ اول نسبت به تاریخ صدور
//   - هر شرکت یک قرارداد دارد؛ کارکنان قراردادِ پیش‌فرضِ خودشان (5051) یا تنظیماتِ مالیِ خودشان
//   - هر بیمه‌نامه (ردیفِ شرکتی / پرونده‌ی کارکنان) می‌تواند روشِ دیگری بگیرد (pay_plan_json)
//   - فیش‌های داخلِ فایلِ بیمه‌نامه با اقساطِ محاسبه‌شده مغایرت‌گیری می‌شوند؛ اولویت با فیش است
//     مگر شماره‌ی قراردادِ بیمه‌نامه با قراردادِ تعیین‌شده یکی نباشد (آن‌وقت هشدار و انتخابِ روش)
// =====================================================================
function fin_contracts_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_contracts (
            id INT AUTO_INCREMENT PRIMARY KEY, contract_no VARCHAR(60) NULL, contract_key VARCHAR(20) NULL, name VARCHAR(150) NOT NULL,
            pay_type VARCHAR(12) NOT NULL DEFAULT 'INSTALLMENT', installment_count INT NOT NULL DEFAULT 1,
            first_due_months INT NOT NULL DEFAULT 1, first_due_days INT NOT NULL DEFAULT 0, interval_months INT NOT NULL DEFAULT 1,
            split_method VARCHAR(12) NOT NULL DEFAULT 'mamut', note VARCHAR(500) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, KEY k_key (contract_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('[fin_contracts_ensure] ' . $e->getMessage()); return; }
    foreach ([['companies', 'contract_id', 'INT NULL'],
              ['policy_cases', 'pay_plan_json', 'TEXT NULL'], ['policy_cases', 'pay_check_json', 'MEDIUMTEXT NULL'],
              ['company_request_plates', 'pay_plan_json', 'TEXT NULL'], ['company_request_plates', 'pay_check_json', 'MEDIUMTEXT NULL'],
              ['policy_installments', 'slip_json', 'TEXT NULL'], ['policy_installments', 'slip_file', 'VARCHAR(500) NULL'],
              ['company_installments', 'slip_json', 'TEXT NULL'], ['company_installments', 'slip_file', 'VARCHAR(500) NULL']] as [$t, $c, $def]) {
        try { if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); }
        catch (Throwable $e) { error_log('[fin_contracts_ensure col] ' . $e->getMessage()); }
    }
    if (function_exists('fin_ensure_receipt_cols')) fin_ensure_receipt_cols($pdo);
    $s = fin_settings($pdo);
    try {
        // قراردادِ کارکنان (۵۰۵۱) از روی تنظیماتِ فعلیِ اقساطِ کارکنان
        if (!intval($pdo->query("SELECT COUNT(*) FROM fin_contracts")->fetchColumn())) {
            $pdo->prepare("INSERT INTO fin_contracts (contract_no, contract_key, name, pay_type, installment_count, first_due_months, first_due_days, split_method, created_at)
                           VALUES ('5051', '5051', 'قرارداد کارکنان (کسر از حقوق)', 'INSTALLMENT', ?, 1, 0, ?, NOW())")
                ->execute([max(1, intval($s['default_installments'] ?? 9)), ($s['installment_method'] ?? 'mamut') === 'mamut' ? 'mamut' : 'even']);
            fin_set($pdo, 'personnel_contract_id', (string)$pdo->lastInsertId());
            if (!isset($s['personnel_use_contract'])) fin_set($pdo, 'personnel_use_contract', '1');
        }
        // شرکت‌هایی که قبلاً تعداد قسط/فاصله داشتند: برای هر ترکیب یک قرارداد ساخته و به شرکت وصل می‌شود (یک بار)
        if (($s['contracts_migrated'] ?? '') !== '1') {
            $rows = $pdo->query("SELECT id, payment_terms, installment_count, first_due_offset_months, first_due_offset_days FROM companies
                                  WHERE contract_id IS NULL AND (installment_count IS NOT NULL OR payment_terms IS NOT NULL)")->fetchAll();
            $made = [];
            foreach ($rows as $r) {
                $cash = in_array($r['payment_terms'], ['CASH_IMMEDIATE', 'CASH_NET30'], true);
                $cnt = $cash ? 1 : max(1, intval($r['installment_count'] ?: 1));
                $m = $cash ? 0 : intval($r['first_due_offset_months']); $d = $r['payment_terms'] === 'CASH_NET30' ? 30 : intval($r['first_due_offset_days']);
                $k = ($cash ? 'C' : 'I') . "-$cnt-$m-$d";
                if (!isset($made[$k])) {
                    $name = $cash ? ('نقدی' . ($d ? " ({$d} روزه)" : ' فوری')) : ("اقساط {$cnt} قسطی" . ($m || $d ? " (سررسید اول {$m} ماه" . ($d ? " و {$d} روز" : '') . ' بعد)' : ''));
                    $pdo->prepare("INSERT INTO fin_contracts (name, pay_type, installment_count, first_due_months, first_due_days, split_method, note, created_at)
                                   VALUES (?, ?, ?, ?, ?, 'mamut', 'ساخته‌شده از تنظیماتِ قبلیِ شرکت‌ها؛ شماره‌ی قرارداد را وارد کنید.', NOW())")
                        ->execute([$name, $cash ? 'CASH' : 'INSTALLMENT', $cnt, $m, $d]);
                    $made[$k] = intval($pdo->lastInsertId());
                }
                $pdo->prepare("UPDATE companies SET contract_id = ? WHERE id = ?")->execute([$made[$k], $r['id']]);
            }
            fin_set($pdo, 'contracts_migrated', '1');
        }
    } catch (Throwable $e) { error_log('[fin_contracts seed] ' . $e->getMessage()); }
}

function fin_contract_key($no) {
    $no = p2e_digits(trim((string)$no));
    return preg_match('/^\s*(\d{3,6})/', $no, $m) ? $m[1] : null;
}
function fin_contracts($pdo, $activeOnly = false) {
    fin_contracts_ensure($pdo);
    return $pdo->query("SELECT * FROM fin_contracts" . ($activeOnly ? " WHERE is_active = 1" : '') . " ORDER BY is_active DESC, contract_key, name")->fetchAll();
}
function fin_contract($pdo, $id) {
    if (!$id) return null;
    fin_contracts_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM fin_contracts WHERE id = ?");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function fin_contract_by_key($pdo, $key) {
    if (!$key) return null;
    $st = $pdo->prepare("SELECT * FROM fin_contracts WHERE contract_key = ? AND is_active = 1 ORDER BY id LIMIT 1");
    $st->execute([(string)$key]);
    return $st->fetch() ?: null;
}

// برنامه‌ی پرداخت: type (CASH/INSTALLMENT)، count، first_m، first_d، interval، method، contract_*، source، prefer (auto/slips/formula)
function fin_plan_from_contract($c, $source) {
    $cash = ($c['pay_type'] ?? '') === 'CASH';
    return ['source' => $source, 'contract_id' => intval($c['id']), 'contract_key' => (string)($c['contract_key'] ?? ''), 'contract_no' => (string)($c['contract_no'] ?? ''),
            'contract_name' => (string)$c['name'], 'type' => $cash ? 'CASH' : 'INSTALLMENT', 'count' => $cash ? 1 : max(1, intval($c['installment_count'])),
            'first_m' => intval($c['first_due_months']), 'first_d' => intval($c['first_due_days']), 'interval' => max(1, intval($c['interval_months'] ?? 1)),
            'method' => ($c['split_method'] ?? 'mamut') === 'even' ? 'even' : 'mamut', 'prefer' => 'auto'];
}
function fin_plan_label($p) {
    if (!$p) return '';
    $t = $p['type'] === 'CASH' ? 'نقد' : ($p['count'] . ' قسط');
    $first = $p['first_m'] || $p['first_d'] ? ('سررسید اول ' . ($p['first_m'] ? $p['first_m'] . ' ماه' : '') . ($p['first_m'] && $p['first_d'] ? ' و ' : '') . ($p['first_d'] ? $p['first_d'] . ' روز' : '') . ' بعد از صدور') : 'سررسید اول همان روزِ صدور';
    return ($p['contract_name'] ? $p['contract_name'] . ($p['contract_key'] ? ' (' . $p['contract_key'] . ')' : '') . ' · ' : '') . $t . ' · ' . $first;
}
// برنامه‌ی پیش‌فرض (بدونِ تغییرِ دستیِ همین بیمه‌نامه)
//   $kind: C (ردیفِ شرکتی؛ $row باید company_id داشته باشد) | P (پرونده‌ی کارکنان)
function fin_plan_default($pdo, $kind, $row) {
    fin_contracts_ensure($pdo);
    $s = fin_settings($pdo);
    if ($kind === 'C') {
        $st = $pdo->prepare("SELECT name, contract_id, payment_terms, installment_count, first_due_offset_months, first_due_offset_days FROM companies WHERE id = ?");
        $st->execute([intval($row['company_id'] ?? 0)]);
        $co = $st->fetch() ?: [];
        if (!empty($co['contract_id']) && ($c = fin_contract($pdo, $co['contract_id']))) return fin_plan_from_contract($c, 'company');
        // شرکتِ بدونِ قرارداد: همان تنظیماتِ قبلیِ شرکت
        return ['source' => 'company_legacy', 'contract_id' => 0, 'contract_key' => '', 'contract_no' => '', 'contract_name' => '',
                'type' => 'INSTALLMENT', 'count' => max(1, intval($co['installment_count'] ?? 0) ?: 1), 'first_m' => intval($co['first_due_offset_months'] ?? 0),
                'first_d' => intval($co['first_due_offset_days'] ?? 0), 'interval' => 1, 'method' => 'mamut', 'prefer' => 'auto'];
    }
    if (($s['personnel_use_contract'] ?? '1') === '1' && ($c = fin_contract($pdo, intval($s['personnel_contract_id'] ?? 0)))) return fin_plan_from_contract($c, 'personnel');
    // تنظیماتِ مالیِ کارکنان (بدونِ قرارداد): همان روشِ قبلی
    return ['source' => 'personnel_settings', 'contract_id' => 0, 'contract_key' => '', 'contract_no' => '', 'contract_name' => 'تنظیماتِ مالیِ کارکنان',
            'type' => 'INSTALLMENT', 'count' => max(1, intval($s['default_installments'] ?? 9)), 'first_m' => 1, 'first_d' => 0, 'interval' => 1,
            'method' => ($s['installment_method'] ?? 'mamut') === 'mamut' ? 'mamut' : 'even', 'prefer' => 'auto',
            'due_mode' => ($s['due_mode'] ?? 'issue') === 'period15' ? 'period15' : 'issue'];
}
// برنامه‌ی نهایی: تغییرِ دستیِ همین بیمه‌نامه (اگر بود) روی پیش‌فرض
function fin_plan_resolve($pdo, $kind, $row) {
    $def = fin_plan_default($pdo, $kind, $row);
    $cu = json_decode((string)($row['pay_plan_json'] ?? ''), true);
    if (!is_array($cu) || !$cu) return $def + ['custom' => false];
    $prefer = in_array($cu['prefer'] ?? '', ['slips', 'formula'], true) ? $cu['prefer'] : 'auto';
    if (($cu['mode'] ?? '') === 'contract' && ($c = fin_contract($pdo, intval($cu['contract_id'] ?? 0)))) {
        return ['prefer' => $prefer] + fin_plan_from_contract($c, 'chosen') + ['custom' => true];
    }
    if (($cu['mode'] ?? '') === 'custom') {
        $cash = ($cu['type'] ?? '') === 'CASH';
        return ['source' => 'custom', 'contract_id' => 0, 'contract_key' => $def['contract_key'], 'contract_no' => $def['contract_no'], 'contract_name' => 'روشِ اختصاصیِ همین بیمه‌نامه',
                'type' => $cash ? 'CASH' : 'INSTALLMENT', 'count' => $cash ? 1 : max(1, min(60, intval($cu['count'] ?? 1))), 'first_m' => max(0, intval($cu['first_m'] ?? 0)),
                'first_d' => max(0, intval($cu['first_d'] ?? 0)), 'interval' => 1, 'method' => ($cu['method'] ?? 'mamut') === 'even' ? 'even' : 'mamut',
                'prefer' => $prefer, 'custom' => true];
    }
    return ['prefer' => $prefer] + $def + ['custom' => $prefer !== 'auto'];
}

// جدولِ اقساط طبقِ برنامه: [['n', 'amount', 'due' (jalali), 'due_g'], ...]
function fin_plan_schedule($premium, $jy, $jm, $jd, array $plan, $period = null) {
    $count = $plan['type'] === 'CASH' ? 1 : max(1, intval($plan['count']));
    $parts = fin_split_installments((int)$premium, $count, $plan['method'] === 'even' ? 'even' : 'mamut');
    $out = [];
    if (($plan['due_mode'] ?? 'issue') === 'period15' && $period) {
        foreach ($parts as $i => $amount) {
            [$dy, $dm] = fin_next_month($period['jalali_year'], $period['jalali_month'], $i + 1);
            $dd = min(15, fin_jalali_month_len($dy, $dm));
            $out[] = ['n' => $i + 1, 'amount' => $amount, 'due' => sprintf('%04d/%02d/%02d', $dy, $dm, $dd), 'due_g' => date('Y-m-d', jalali_to_gregorian_ts($dy, $dm, $dd))];
        }
        return $out;
    }
    // سررسیدِ اول = تاریخ صدور + first_m ماه + first_d روز؛ بعدی‌ها هر interval ماه
    [$fy, $fm, $fd] = fin_due_after($jy, $jm, $jd, intval($plan['first_m']));
    if (intval($plan['first_d'])) [$fy, $fm, $fd] = jalali_from_gregorian_ts(jalali_to_gregorian_ts($fy, $fm, $fd) + intval($plan['first_d']) * 86400);
    foreach ($parts as $i => $amount) {
        [$dy, $dm, $dd] = fin_due_after($fy, $fm, $fd, $i * max(1, intval($plan['interval'] ?? 1)));
        $out[] = ['n' => $i + 1, 'amount' => $amount, 'due' => sprintf('%04d/%02d/%02d', $dy, $dm, $dd), 'due_g' => date('Y-m-d', jalali_to_gregorian_ts($dy, $dm, $dd))];
    }
    return $out;
}

// فیش‌های ذخیره‌شده (receipts_json) => ردیف‌های مرتب‌شده با سررسید
function fin_slip_list($receiptsJson) {
    $rc = is_array($receiptsJson) ? $receiptsJson : (json_decode((string)$receiptsJson, true) ?: []);
    $rc = array_values(array_filter($rc, fn($r) => !empty($r['date']) && fin_parse_jalali($r['date']) && !empty($r['amount'])));
    usort($rc, fn($a, $b) => strcmp(vsprintf('%04d/%02d/%02d', fin_parse_jalali($a['date'])), vsprintf('%04d/%02d/%02d', fin_parse_jalali($b['date']))));
    return $rc;
}
function fin_slip_meta($r) {
    $keep = [];
    foreach (['date', 'amount', 'fish', 'shenase', 'account', 'bank', 'kind', 'payer', 'payer_nid'] as $k) if (isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null) $keep[$k] = $r[$k];
    return $keep;
}

// ساختِ اقساطِ نهایی یک بیمه‌نامه + گزارشِ مغایرت
//   $detectedKey: کلیدِ قراردادی که از خودِ بیمه‌نامه خوانده شده (از شماره‌ی قرارداد یا شماره‌ی بیمه‌نامه)
//   خروجی: ['rows' => [...با slip/slip_file], 'check' => [...]]
function fin_plan_build($premium, $issueJ, array $plan, $receiptsJson, $detectedKey, $period = null) {
    [$jy, $jm, $jd] = $issueJ;
    $computed = fin_plan_schedule($premium, $jy, $jm, $jd, $plan, $period);
    $slips = fin_slip_list($receiptsJson);
    $check = ['at' => date('Y-m-d H:i:s'), 'plan' => $plan, 'plan_label' => fin_plan_label($plan), 'issue_date' => vsprintf('%04d/%02d/%02d', $issueJ),
              'premium' => (int)$premium, 'detected_key' => $detectedKey ?: null, 'expected_key' => $plan['contract_key'] ?: null,
              'computed' => array_map(fn($r) => ['n' => $r['n'], 'amount' => $r['amount'], 'due' => $r['due']], $computed),
              'slips' => array_map(fn($r) => fin_slip_meta($r) + ['file' => $r['file'] ?? null], $slips), 'diffs' => [], 'messages' => []];
    $contractOk = !$detectedKey || !$plan['contract_key'] || (string)$detectedKey === (string)$plan['contract_key'];
    $check['contract_ok'] = $contractOk;
    if ($slips) {
        // مغایرت‌گیری: تعداد، مبلغ و سررسیدِ هر قسط
        if (count($slips) !== count($computed)) $check['diffs'][] = ['type' => 'count', 'computed' => count($computed), 'slip' => count($slips)];
        foreach ($slips as $k => $s) {
            $c = $computed[$k] ?? null;
            if (!$c) continue;
            $sd = vsprintf('%04d/%02d/%02d', fin_parse_jalali($s['date']));
            if ((int)$s['amount'] !== (int)$c['amount']) $check['diffs'][] = ['type' => 'amount', 'n' => $k + 1, 'computed' => (int)$c['amount'], 'slip' => (int)$s['amount']];
            if ($sd !== $c['due']) $check['diffs'][] = ['type' => 'due', 'n' => $k + 1, 'computed' => $c['due'], 'slip' => $sd];
        }
        $sum = array_sum(array_map(fn($s) => (int)$s['amount'], $slips));
        if ($premium && $sum !== (int)$premium) $check['diffs'][] = ['type' => 'total', 'computed' => (int)$premium, 'slip' => $sum];
    }
    $useSlips = $slips && ($plan['prefer'] === 'slips' || ($plan['prefer'] === 'auto' && $contractOk));
    $rows = [];
    if ($useSlips) {
        foreach ($slips as $k => $s) {
            [$y, $m, $d] = fin_parse_jalali($s['date']);
            $rows[] = ['n' => $k + 1, 'amount' => (int)$s['amount'], 'due' => sprintf('%04d/%02d/%02d', $y, $m, $d), 'due_g' => date('Y-m-d', jalali_to_gregorian_ts($y, $m, $d)),
                       'slip' => fin_slip_meta($s), 'slip_file' => $s['file'] ?? null];
        }
        $check['applied'] = 'slips';
    } else {
        $rows = $computed;
        // فیش‌ها (اگر بود) کنارِ قسطِ هم‌تاریخ‌شان نشان داده می‌شوند، ولی مبلغ/سررسید طبقِ فرمول می‌ماند
        if ($slips) {
            $pair = fin_pair_receipts(array_map(fn($r) => ['id' => $r['n'], 'inst_number' => $r['n'], 'due_jalali' => $r['due']], $computed), $slips);
            foreach ($rows as &$r) if (isset($pair[$r['n']])) { $r['slip'] = fin_slip_meta($pair[$r['n']]); $r['slip_file'] = $pair[$r['n']]['file'] ?? null; }
            unset($r);
        }
        $check['applied'] = 'formula';
    }
    if (!$contractOk && $plan['prefer'] === 'auto') $check['status'] = 'contract_mismatch';
    elseif (!$slips) $check['status'] = 'no_slips';
    elseif ($check['diffs']) $check['status'] = $useSlips ? 'diff_slips_applied' : 'diff_formula_applied';
    else $check['status'] = 'ok';
    $msg = ['no_slips' => 'فیشی در فایلِ بیمه‌نامه پیدا نشد؛ اقساط طبقِ ' . fin_plan_label($plan) . ' ساخته شد.',
            'contract_mismatch' => 'شماره‌ی قراردادِ بیمه‌نامه (' . $detectedKey . ') با قراردادِ تعیین‌شده (' . $plan['contract_key'] . ') یکی نیست؛ اقساط طبقِ فرمولِ قراردادِ تعیین‌شده ساخته شد' . ($slips ? '' : ' (فیشی هم در فایلِ بیمه‌نامه پیدا نشد)') . '. با فرمولِ همین قرارداد محاسبه شود؟',
            'diff_slips_applied' => 'اقساطِ محاسبه‌شده با فیش‌ها مغایرت داشت؛ مبلغ و سررسید طبقِ فیش‌ها ثبت شد.',
            'diff_formula_applied' => 'فیش‌ها با فرمول مغایرت دارند؛ طبقِ انتخابِ شما اقساط طبقِ فرمول ثبت شد.',
            'ok' => 'فیش‌ها با اقساطِ محاسبه‌شده کاملاً یکی است.'][$check['status']];
    $check['messages'][] = $msg;
    return ['rows' => $rows, 'check' => $check];
}

// کلیدِ قراردادِ خوانده‌شده از بیمه‌نامه (ocr_extracted_data): شماره‌ی قرارداد، وگرنه بخشِ سومِ شماره‌ی بیمه‌نامه
// اقساط طبقِ فیش‌ها نشسته‌اند (جمعشان ممکن است با حق بیمه‌ی واردشده فرق کند؛ همگام‌سازی دوباره نمی‌سازدشان)
function fin_slips_applied($pdo, $table, $id) {
    try {
        $st = $pdo->prepare("SELECT pay_check_json FROM `$table` WHERE id = ?");
        $st->execute([intval($id)]);
        $c = json_decode((string)$st->fetchColumn(), true);
        return is_array($c) && ($c['applied'] ?? '') === 'slips';
    } catch (Throwable $e) { return false; }
}

function fin_detected_contract_key($ocrJson, $policyNumber = '') {
    $o = is_array($ocrJson) ? $ocrJson : (json_decode((string)$ocrJson, true) ?: []);
    if (!empty($o['contract_key'])) return (string)$o['contract_key'];
    if (!empty($o['contract_no']) && ($k = fin_contract_key($o['contract_no']))) return $k;
    $pn = p2e_digits((string)($policyNumber ?: ($o['policy_num'] ?? '')));
    return preg_match('/\d+\/\d+\/(\d{3,6})\s*-/', $pn, $m) ? $m[1] : null;
}
