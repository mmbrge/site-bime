<?php
// فایل: api/_endorse.php
// «الحاقیه»ها (اضافی / برگشتی / فسخ / اصلاحی) روی هر بیمه‌نامه‌ی صادرشده (شرکتی یا کارکنان):
//   - خواندنِ فایلِ PDFِ الحاقیه (api/py/endorse_reader.py) و پیدا کردنِ بیمه‌نامه با شماره‌اش
//   - ثبت، و اثرِ مالی به انتخاب: سرشکن روی اقساطِ باقی‌مانده / اقساطِ جدا / نقد / بدونِ اثر
//     (برگشتی: کسر از آخرین اقساط / کسرِ یکسان / استردادِ نقدی (بستانکار) / بدونِ اثر)
//   - حق بیمه‌ی نهایی = حق بیمه‌ی اصلی + جمعِ الحاقیه‌های اضافی − جمعِ برگشتی‌ها (روی خودِ بیمه‌نامه ذخیره می‌شود
//     تا در جستجو، صادره‌ها و مغایرت‌گیری دیده شود)
//   - هر تغییرِ مالی ثبت می‌شود تا حذفِ الحاقیه همه‌چیز را برگرداند
// منابع: 'C' = ردیفِ شرکتی (company_request_plates)، 'P' = پرونده‌ی کارکنان (policy_cases)، 'X' = بیمه‌نامه‌ای که در سایت نیست

function endo_kinds() {
    return ['ADDITIONAL' => 'اضافی', 'REFUND' => 'برگشتی', 'CANCELLATION' => 'فسخ', 'CORRECTIVE' => 'اصلاحی'];
}
function endo_kind_fa($k) { return endo_kinds()[$k] ?? $k; }
function endo_sign($kind) { return $kind === 'ADDITIONAL' ? 1 : (in_array($kind, ['REFUND', 'CANCELLATION'], true) ? -1 : 0); }
function endo_modes_fa() {
    return ['SPREAD' => 'سرشکن روی اقساطِ باقی‌مانده', 'SEPARATE' => 'اقساطِ جدا', 'CASH' => 'نقد (یک‌جا)',
            'LAST' => 'کسر از آخرین اقساط', 'EVEN' => 'کسرِ یکسان از اقساطِ باقی‌مانده', 'REFUND_CASH' => 'استردادِ نقدی (بستانکار)', 'NONE' => 'بدونِ اثرِ مالی'];
}

function endo_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS policy_endorsements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source CHAR(1) NOT NULL DEFAULT 'X',
        ref_id BIGINT NULL,
        policy_number VARCHAR(60) NULL,
        policy_key VARCHAR(60) NULL,
        endorse_no VARCHAR(10) NULL,
        kind VARCHAR(16) NOT NULL,
        reason VARCHAR(255) NULL,
        description TEXT NULL,
        issue_date VARCHAR(10) NULL,
        effect_date VARCHAR(10) NULL,
        new_end_date VARCHAR(10) NULL,
        gross BIGINT NOT NULL DEFAULT 0,
        net BIGINT NULL,
        tax BIGINT NULL,
        signed_amount BIGINT NOT NULL DEFAULT 0,
        central_no VARCHAR(20) NULL,
        insurance_type VARCHAR(12) NULL,
        file_path VARCHAR(500) NULL,
        finance_mode VARCHAR(16) NOT NULL DEFAULT 'NONE',
        finance_json MEDIUMTEXT NULL,
        credit BIGINT NOT NULL DEFAULT 0,
        credit_paid TINYINT NOT NULL DEFAULT 0,
        request_plate_id BIGINT NULL,
        origin VARCHAR(12) NOT NULL DEFAULT 'MANUAL',
        batch_id INT NULL,
        data_json MEDIUMTEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_ref (source, ref_id), KEY k_pol (policy_key), KEY k_req (request_plate_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $add = [
        ['company_request_plates', 'endo_count', "INT NOT NULL DEFAULT 0"], ['company_request_plates', 'endo_total', "BIGINT NOT NULL DEFAULT 0"],
        ['company_request_plates', 'final_premium', "BIGINT NULL"], ['company_request_plates', 'is_cancelled', "TINYINT NOT NULL DEFAULT 0"],
        ['company_request_plates', 'cancelled_on', "VARCHAR(10) NULL"], ['company_request_plates', 'end_date_j', "VARCHAR(10) NULL"],
        ['company_request_plates', 'no_installments', "TINYINT NOT NULL DEFAULT 0"],
        ['policy_cases', 'endo_count', "INT NOT NULL DEFAULT 0"], ['policy_cases', 'endo_total', "BIGINT NOT NULL DEFAULT 0"],
        ['policy_cases', 'final_premium', "BIGINT NULL"], ['policy_cases', 'is_cancelled', "TINYINT NOT NULL DEFAULT 0"],
        ['policy_cases', 'cancelled_on', "VARCHAR(10) NULL"], ['policy_cases', 'end_date_j', "VARCHAR(10) NULL"],
        ['policy_cases', 'no_installments', "TINYINT NOT NULL DEFAULT 0"],
        ['policy_installments', 'endorsement_id', "INT NULL"], ['company_installments', 'endorsement_id', "INT NULL"],
    ];
    foreach ($add as [$t, $c, $def]) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        } catch (Throwable $e) { error_log('[endo_ensure] ' . $t . '.' . $c . ': ' . $e->getMessage()); }
    }
}

// کلیدِ مقایسه‌ی شماره‌ی بیمه‌نامه (فقط رقم‌ها و حروفِ لاتین، با رقمِ انگلیسی)
function endo_policy_key($s) {
    $s = strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '∕' => '/']);
    return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $s));
}

function endo_jdate($s) {
    $s = function_exists('p2e_digits') ? p2e_digits((string)$s) : (string)$s;
    return preg_match('/(1[34]\d{2})\D+(\d{1,2})\D+(\d{1,2})/', $s, $m) ? sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]) : '';
}

// اجرای پایتون (همان مسیرِ تنظیماتِ گزارش‌ها) => JSONِ آخرین خط
function endo_py($pdo, $script, array $args, $timeout = 120) {
    if (!function_exists('pdf_split_python')) require_once __DIR__ . '/_pdf_split.php';
    if (!function_exists('proc_open')) return ['ok' => false, 'error' => 'اجرای پایتون روی این سرور مجاز نیست (proc_open غیرفعال است).'];
    $home = getenv('HOME');
    if (!$home && preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m)) $home = $m[1];
    $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $home ?: sys_get_temp_dir(), 'PYTHONWARNINGS' => 'ignore'];
    $proc = @proc_open(array_merge([pdf_split_python($pdo), __DIR__ . '/py/' . $script], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'اجرای پایتون ممکن نشد.'];
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $start = time();
    while (true) {
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        $s = proc_get_status($proc);
        if (!$s['running']) break;
        if (time() - $start > $timeout) { proc_terminate($proc, 9); return ['ok' => false, 'error' => 'خواندنِ فایل بیش از حد طول کشید.']; }
        usleep(50000);
    }
    $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $j = json_decode(trim((string)substr($out, strrpos("\n" . trim($out), "\n"))), true);
    return is_array($j) ? $j : ['ok' => false, 'error' => 'خروجیِ خواننده‌ی فایل خوانده نشد.', 'debug' => mb_substr(trim($err ?: $out), -400)];
}

function endo_read_pdf($pdo, $abs) { return endo_py($pdo, 'endorse_reader.py', [$abs]); }

// ---------------------------------------------------------------------
//  پیدا کردنِ بیمه‌نامه
// ---------------------------------------------------------------------
// خروجی: فهرستِ [source, ref_id, label, policy_number, insured, company, premium, final_premium, type, issue_date]
function endo_find_policies($pdo, $policyNumber, $limit = 10) {
    endo_ensure($pdo);
    $key = endo_policy_key($policyNumber);
    if (strlen($key) < 6) return [];
    $out = [];
    $sqlKey = function ($col) { return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col, '/', ''), '-', ''), ' ', ''), '∕', ''), '.', ''))"; };
    $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.request_kind, cr.is_import, c.name AS company_name FROM company_request_plates crp
                           JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                          WHERE crp.status = 'ISSUED' AND cr.request_kind = 'NEW_POLICY'
                            AND (" . $sqlKey('crp.policy_number') . " = ? OR " . $sqlKey('crp.import_policy_number') . " = ?) LIMIT " . intval($limit));
    $st->execute([$key, $key]);
    foreach ($st->fetchAll() as $r) $out[] = endo_policy_row('C', $r);
    try {
        $st = $pdo->prepare("SELECT pc.*, per.full_name AS holder_name, co.name AS company_name FROM policy_cases pc
                               LEFT JOIN persons per ON per.id = pc.person_id LEFT JOIN companies co ON co.id = per.company_id
                              WHERE pc.status = 'ISSUED' AND " . $sqlKey('pc.policy_number') . " = ? LIMIT " . intval($limit));
        $st->execute([$key]);
        foreach ($st->fetchAll() as $r) $out[] = endo_policy_row('P', $r);
    } catch (Throwable $e) {}
    return $out;
}

function endo_policy_row($source, array $r) {
    $prem = function_exists('money_to_int') ? money_to_int($r['total_premium'] ?? 0) : intval(preg_replace('/\D/', '', (string)($r['total_premium'] ?? 0)));
    if ($source === 'C') {
        return ['source' => 'C', 'ref_id' => (int)$r['id'], 'label' => function_exists('company_row_label') ? company_row_label($r) : '',
                'policy_number' => $r['policy_number'] ?: ($r['import_policy_number'] ?? ''), 'insured' => $r['company_name'] ?? '',
                'company' => $r['company_name'] ?? '', 'company_id' => (int)($r['company_id'] ?? 0), 'premium' => $prem,
                'final_premium' => $r['final_premium'] !== null && isset($r['final_premium']) ? (int)$r['final_premium'] : $prem,
                'endo_count' => (int)($r['endo_count'] ?? 0), 'is_cancelled' => (int)($r['is_cancelled'] ?? 0),
                'type' => $r['insurance_type'] ?? '', 'type_fa' => function_exists('insurance_type_fa') ? insurance_type_fa($r['insurance_type'] ?? '') : '',
                'issue_date' => $r['policy_issue_date'] ?? ($r['import_issue_date'] ?? ''), 'is_import' => (int)($r['is_import'] ?? 0), 'source_fa' => 'شرکتی'];
    }
    return ['source' => 'P', 'ref_id' => (int)$r['id'], 'label' => (string)($r['plate'] ?? ''), 'policy_number' => $r['policy_number'] ?? '',
            'insured' => $r['insured_name'] ?: ($r['holder_name'] ?? ''), 'company' => $r['company_name'] ?? '', 'company_id' => 0, 'premium' => $prem,
            'final_premium' => isset($r['final_premium']) && $r['final_premium'] !== null ? (int)$r['final_premium'] : $prem,
            'endo_count' => (int)($r['endo_count'] ?? 0), 'is_cancelled' => (int)($r['is_cancelled'] ?? 0),
            'type' => $r['insurance_type'] ?? '', 'type_fa' => function_exists('insurance_type_fa') ? insurance_type_fa($r['insurance_type'] ?? '') : '',
            'issue_date' => $r['policy_issue_date'] ?? '', 'is_import' => 0, 'source_fa' => 'کارکنان'];
}

function endo_load_policy($pdo, $source, $refId) {
    endo_ensure($pdo);
    if ($source === 'C') {
        $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.request_kind, cr.is_import, c.name AS company_name FROM company_request_plates crp
                               JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE crp.id = ?");
    } elseif ($source === 'P') {
        $st = $pdo->prepare("SELECT pc.*, per.full_name AS holder_name, co.name AS company_name FROM policy_cases pc
                               LEFT JOIN persons per ON per.id = pc.person_id LEFT JOIN companies co ON co.id = per.company_id WHERE pc.id = ?");
    } else return null;
    $st->execute([$refId]);
    $r = $st->fetch();
    return $r ? ['row' => $r, 'info' => endo_policy_row($source, $r)] : null;
}

// ---------------------------------------------------------------------
//  اقساط
// ---------------------------------------------------------------------
function endo_inst_tables($source) {
    return $source === 'C'
        ? ['t' => 'company_installments', 'fk' => 'plate_id', 'alloc' => 'company_payment_allocations']
        : ['t' => 'policy_installments', 'fk' => 'case_id', 'alloc' => 'payment_allocations'];
}

// اقساطِ یک بیمه‌نامه با پرداختی و مانده‌ی هر قسط
function endo_installments($pdo, $source, $refId) {
    if (!in_array($source, ['C', 'P'], true) || !$refId) return [];
    $T = endo_inst_tables($source);
    $st = $pdo->prepare("SELECT i.*, COALESCE((SELECT SUM(a.amount) FROM {$T['alloc']} a WHERE a.installment_id = i.id), 0) AS paid
                           FROM {$T['t']} i WHERE i.{$T['fk']} = ? ORDER BY i.due_date, i.id");
    $st->execute([$refId]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $amt = (int)$r['amount']; $paid = (int)$r['paid'];
        $settled = (int)($r['settled_to_pasargad'] ?? 0) === 1;
        $rows[] = ['id' => (int)$r['id'], 'n' => (int)$r['inst_number'], 'amount' => $amt, 'paid' => $paid, 'remaining' => max(0, $amt - $paid),
                   'open' => !$settled && $paid < $amt, 'due' => (string)$r['due_jalali'], 'due_g' => substr((string)$r['due_date'], 0, 10),
                   'endorsement_id' => isset($r['endorsement_id']) ? (int)$r['endorsement_id'] : 0, 'period_id' => $r['period_id'] ?? null];
    }
    return $rows;
}

function endo_jalali_add_months($j, $k) {
    [$y, $m, $d] = array_map('intval', explode('/', $j));
    $m += $k;
    while ($m > 12) { $m -= 12; $y++; }
    $len = function_exists('fin_jalali_month_len') ? fin_jalali_month_len($y, $m) : ($m <= 6 ? 31 : ($m <= 11 ? 30 : 29));
    return sprintf('%04d/%02d/%02d', $y, $m, min($d, $len));
}
function endo_j2g($j) { return function_exists('fin_jalali_to_date') ? fin_jalali_to_date($j) : null; }

// برنامه‌ی اثرِ مالی (بدونِ ثبت): ['changes' => [[id, before, after]], 'new' => [[amount, due]], 'deleted' => [], 'credit' => n, 'note' => '']
function endo_plan_finance($pdo, $source, $refId, $kind, $amount, $mode, array $opt = []) {
    $amount = abs((int)$amount);
    $plan = ['changes' => [], 'new' => [], 'deleted' => [], 'credit' => 0, 'note' => ''];
    $sign = endo_sign($kind);
    if (!$amount || !$sign || $mode === 'NONE' || !in_array($source, ['C', 'P'], true)) return $plan;
    $insts = endo_installments($pdo, $source, $refId);
    $open = array_values(array_filter($insts, fn($i) => $i['open']));
    $eff = endo_jdate($opt['effect_date'] ?? '') ?: (function_exists('jalali_from_gregorian_ts_dotted') ? str_replace('.', '/', jalali_from_gregorian_ts_dotted(time())) : '');

    if ($sign > 0) {
        if ($mode === 'SPREAD') {
            // روی اقساطِ باز از تاریخِ اثر به بعد؛ اگر نبود همه‌ی اقساطِ باز؛ اگر هیچ قسطِ بازی نبود => نقد
            $tgt = array_values(array_filter($open, fn($i) => $i['due'] >= $eff)) ?: $open;
            if (!$tgt) { $mode = 'CASH'; $plan['note'] = 'قسطِ بازی نبود؛ نقد ثبت شد.'; }
            else {
                $n = count($tgt); $each = intdiv($amount, $n); $rest = $amount - $each * $n;
                foreach ($tgt as $k => $i) {
                    $add = $each + ($k === $n - 1 ? $rest : 0);
                    $plan['changes'][] = ['id' => $i['id'], 'n' => $i['n'], 'due' => $i['due'], 'before' => $i['amount'], 'after' => $i['amount'] + $add];
                }
                return $plan;
            }
        }
        if ($mode === 'SEPARATE') {
            $sched = array_values(array_filter((array)($opt['schedule'] ?? []), fn($x) => (int)($x['amount'] ?? 0) > 0 && endo_jdate($x['due'] ?? '')));
            if ($sched && array_sum(array_map(fn($x) => (int)$x['amount'], $sched)) === $amount) {
                foreach ($sched as $x) $plan['new'][] = ['amount' => (int)$x['amount'], 'due' => endo_jdate($x['due'])];
                return $plan;
            }
            $cnt = max(1, min(36, (int)($opt['count'] ?? 1)));
            $first = endo_jdate($opt['first_due'] ?? '') ?: $eff;
            $each = intdiv($amount, $cnt); $rest = $amount - $each * $cnt;
            for ($k = 0; $k < $cnt; $k++) $plan['new'][] = ['amount' => $each + ($k === 0 ? $rest : 0), 'due' => endo_jalali_add_months($first, $k)];
            return $plan;
        }
        // CASH
        $plan['new'][] = ['amount' => $amount, 'due' => endo_jdate($opt['first_due'] ?? '') ?: $eff];
        return $plan;
    }

    // ---- برگشتی / فسخ ----
    if ($mode === 'REFUND_CASH') { $plan['credit'] = $amount; return $plan; }
    $left = $amount;
    if ($mode === 'EVEN' && $open) {
        $totalRem = array_sum(array_column($open, 'remaining'));
        foreach ($open as $k => $i) {
            if ($left <= 0) break;
            $cut = $k === count($open) - 1 ? min($left, $i['remaining']) : min($i['remaining'], (int)floor($amount * $i['remaining'] / max(1, $totalRem)));
            if ($cut <= 0) continue;
            $plan['changes'][] = ['id' => $i['id'], 'n' => $i['n'], 'due' => $i['due'], 'before' => $i['amount'], 'after' => $i['amount'] - $cut];
            $left -= $cut;
        }
    } else {
        // LAST (پیش‌فرض): از آخرین قسطِ باز به عقب
        foreach (array_reverse($open) as $i) {
            if ($left <= 0) break;
            $cut = min($left, $i['remaining']);
            if ($cut <= 0) continue;
            $plan['changes'][] = ['id' => $i['id'], 'n' => $i['n'], 'due' => $i['due'], 'before' => $i['amount'], 'after' => $i['amount'] - $cut];
            $left -= $cut;
        }
    }
    if ($left > 0) { $plan['credit'] = $left; $plan['note'] = 'مبلغِ برگشتی از مانده‌ی اقساط بیشتر بود؛ مابقی بستانکار (استردادِ نقدی) شد.'; }
    return $plan;
}

// اجرای برنامه‌ی مالی روی جدول‌ها => همان برنامه با شناسه‌ی اقساطِ ساخته/حذف‌شده (برای برگرداندن)
function endo_apply_finance($pdo, $source, $refId, $endoId, array $plan) {
    if (!in_array($source, ['C', 'P'], true)) return $plan;
    $T = endo_inst_tables($source);
    $up = $pdo->prepare("UPDATE {$T['t']} SET amount = ? WHERE id = ?");
    foreach ($plan['changes'] as &$c) {
        if ((int)$c['after'] <= 0) {
            // قسطی که به صفر رسید و پرداختی ندارد حذف می‌شود (ردیفش برای برگرداندن نگه داشته می‌شود)
            $row = $pdo->query("SELECT * FROM {$T['t']} WHERE id = " . intval($c['id']))->fetch(PDO::FETCH_ASSOC);
            $hasPay = (int)$pdo->query("SELECT COUNT(*) FROM {$T['alloc']} WHERE installment_id = " . intval($c['id']))->fetchColumn();
            if ($row && !$hasPay) { $pdo->exec("DELETE FROM {$T['t']} WHERE id = " . intval($c['id'])); $plan['deleted'][] = $row; $c['deleted'] = 1; continue; }
        }
        $up->execute([(int)$c['after'], (int)$c['id']]);
    }
    unset($c);
    if ($plan['new']) {
        $maxN = (int)$pdo->query("SELECT COALESCE(MAX(inst_number), 0) FROM {$T['t']} WHERE {$T['fk']} = " . intval($refId))->fetchColumn();
        $periodId = null;
        if ($source === 'P') $periodId = $pdo->query("SELECT period_id FROM policy_installments WHERE case_id = " . intval($refId) . " ORDER BY id LIMIT 1")->fetchColumn() ?: null;
        foreach ($plan['new'] as $k => &$nw) {
            $g = endo_j2g($nw['due']);
            $code = function_exists('fin_tracking_code') ? fin_tracking_code($pdo, $T['t']) : null;
            if ($source === 'C') {
                $pdo->prepare("INSERT INTO company_installments (plate_id, inst_number, amount, due_jalali, due_date, tracking_code, endorsement_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$refId, min(127, $maxN + $k + 1), $nw['amount'], $nw['due'], $g, $code, $endoId]);
            } else {
                if (!$periodId && function_exists('fin_resolve_period_for_date')) {
                    [$y, $m, $d] = array_map('intval', explode('/', $nw['due']));
                    $periodId = fin_resolve_period_for_date($pdo, $y, $m, $d)['id'] ?? null;
                }
                $pdo->prepare("INSERT INTO policy_installments (case_id, period_id, inst_number, amount, due_jalali, due_date, tracking_code, endorsement_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$refId, $periodId, $maxN + $k + 1, $nw['amount'], $nw['due'], $g, $code, $endoId]);
            }
            $nw['id'] = (int)$pdo->lastInsertId();
        }
        unset($nw);
    }
    return $plan;
}

// آیا این بیمه‌نامه الحاقیه‌ای دارد که اقساطش را تغییر داده؟ (ساختِ دوباره‌ی اقساط آن تغییرها را پاک می‌کرد)
function endo_has_finance($pdo, $source, $refId) {
    try {
        endo_ensure($pdo);
        $st = $pdo->prepare("SELECT COUNT(*) FROM policy_endorsements WHERE source = ? AND ref_id = ? AND finance_mode NOT IN ('NONE', 'REFUND_CASH')");
        $st->execute([$source, $refId]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

// برگرداندنِ اثرِ مالیِ یک الحاقیه؛ اگر روی قسطِ ساخته‌شده پرداختی ثبت شده باشد، برنمی‌گردد
function endo_revert_finance($pdo, array $endo) {
    $source = $endo['source'];
    if (!in_array($source, ['C', 'P'], true)) return ['ok' => true];
    $plan = json_decode((string)$endo['finance_json'], true) ?: [];
    $T = endo_inst_tables($source);
    foreach ((array)($plan['new'] ?? []) as $nw) {
        if (empty($nw['id'])) continue;
        if ((int)$pdo->query("SELECT COUNT(*) FROM {$T['alloc']} WHERE installment_id = " . intval($nw['id']))->fetchColumn()) {
            return ['ok' => false, 'error' => 'روی قسطِ ساخته‌شده‌ی این الحاقیه دریافتی ثبت شده؛ اول آن دریافتی را باطل کنید.'];
        }
    }
    foreach ((array)($plan['new'] ?? []) as $nw) if (!empty($nw['id'])) $pdo->exec("DELETE FROM {$T['t']} WHERE id = " . intval($nw['id']));
    $up = $pdo->prepare("UPDATE {$T['t']} SET amount = ? WHERE id = ?");
    foreach ((array)($plan['changes'] ?? []) as $c) if (empty($c['deleted'])) $up->execute([(int)$c['before'], (int)$c['id']]);
    foreach ((array)($plan['deleted'] ?? []) as $row) {
        $row = array_intersect_key($row, array_flip(array_filter(array_keys($row), 'is_string')));
        $cols = array_keys($row);
        try {
            $pdo->prepare("INSERT INTO {$T['t']} (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($row));
        } catch (Throwable $e) { error_log('[endo_revert] ' . $e->getMessage()); }
    }
    return ['ok' => true];
}

// خلاصه‌ی الحاقیه‌ها روی خودِ بیمه‌نامه (تعداد، جمع، حق بیمه‌ی نهایی، فسخ، تاریخِ پایانِ تازه)
function endo_recompute($pdo, $source, $refId) {
    if (!in_array($source, ['C', 'P'], true) || !$refId) return;
    $st = $pdo->prepare("SELECT kind, signed_amount, effect_date, new_end_date FROM policy_endorsements WHERE source = ? AND ref_id = ? ORDER BY effect_date, id");
    $st->execute([$source, $refId]);
    $rows = $st->fetchAll();
    $cnt = count($rows); $tot = array_sum(array_map(fn($r) => (int)$r['signed_amount'], $rows));
    $cancel = null; $end = null;
    foreach ($rows as $r) {
        if ($r['kind'] === 'CANCELLATION') $cancel = $r['effect_date'] ?: $cancel;
        if ($r['new_end_date']) $end = $r['new_end_date'];
    }
    $tbl = $source === 'C' ? 'company_request_plates' : 'policy_cases';
    $prem = (int)money_to_int($pdo->query("SELECT total_premium FROM $tbl WHERE id = " . intval($refId))->fetchColumn());
    $pdo->prepare("UPDATE $tbl SET endo_count = ?, endo_total = ?, final_premium = ?, is_cancelled = ?, cancelled_on = ?, end_date_j = ? WHERE id = ?")
        ->execute([$cnt, $tot, $cnt ? $prem + $tot : null, $cancel ? 1 : 0, $cancel, $end, $refId]);
}

// جای فایلِ الحاقیه: پوشه‌ی خودِ بیمه‌نامه؛ وگرنه پوشه‌ی موقتِ «الحاقیه‌ها»
function endo_policy_folder($pdo, $siteRoot, $source, $row) {
    if ($source === 'C' && !empty($row['folder_path']) && is_dir($row['folder_path'])) return $row['folder_path'];
    if (!empty($row['issued_file_path'])) {
        $d = dirname($siteRoot . '/' . ltrim($row['issued_file_path'], '/'));
        if (is_dir($d)) return $d;
    }
    $d = (function_exists('temp_archive_root') ? temp_archive_root($siteRoot) : $siteRoot . '/موقت') . '/الحاقیه‌ها';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

// ---------------------------------------------------------------------
//  ثبت
// ---------------------------------------------------------------------
// $in: source, ref_id, policy_number, endorse_no, kind, issue_date, effect_date, new_end_date, gross, net, tax, central_no,
//      description, reason, insurance_type, finance_mode, finance_opt{count, first_due, schedule}, request_plate_id, origin, batch_id, data
// $srcFile: مسیرِ فایلِ PDF (اختیاری) — به پوشه‌ی بیمه‌نامه منتقل/کپی می‌شود
function endo_create($pdo, $siteRoot, array $in, $srcFile = null, $userId = null, $moveFile = true) {
    endo_ensure($pdo);
    $kind = strtoupper((string)($in['kind'] ?? ''));
    if (!isset(endo_kinds()[$kind])) return ['ok' => false, 'error' => 'نوعِ الحاقیه را انتخاب کنید.'];
    $source = in_array($in['source'] ?? '', ['C', 'P'], true) ? $in['source'] : 'X';
    $refId = $source === 'X' ? null : intval($in['ref_id'] ?? 0);
    $pol = null;
    if ($source !== 'X') {
        $pol = endo_load_policy($pdo, $source, $refId);
        if (!$pol) return ['ok' => false, 'error' => 'بیمه‌نامه پیدا نشد.'];
    }
    $policyNumber = trim((string)($in['policy_number'] ?? '')) ?: ($pol['info']['policy_number'] ?? '');
    if ($source === 'X' && endo_policy_key($policyNumber) === '') return ['ok' => false, 'error' => 'شماره‌ی بیمه‌نامه را بنویسید.'];
    $gross = abs((int)preg_replace('/\D/', '', (string)($in['gross'] ?? 0)));
    if ($kind !== 'CORRECTIVE' && !$gross) return ['ok' => false, 'error' => 'مبلغِ الحاقیه را وارد کنید.'];
    $no = trim(function_exists('p2e_digits') ? p2e_digits((string)($in['endorse_no'] ?? '')) : (string)($in['endorse_no'] ?? ''));
    // تکراری: همان بیمه‌نامه + همان شماره‌ی الحاقیه
    if ($no !== '') {
        $d = $pdo->prepare("SELECT id FROM policy_endorsements WHERE policy_key = ? AND endorse_no = ? LIMIT 1");
        $d->execute([endo_policy_key($policyNumber), $no]);
        if ($dupId = $d->fetchColumn()) return ['ok' => false, 'error' => 'الحاقیه‌ی شماره‌ی ' . $no . ' این بیمه‌نامه قبلاً ثبت شده (#' . $dupId . ').', 'duplicate' => (int)$dupId];
    }
    $mode = strtoupper((string)($in['finance_mode'] ?? 'NONE'));
    if (!isset(endo_modes_fa()[$mode])) $mode = 'NONE';
    if ($source === 'X') $mode = 'NONE';
    $signed = endo_sign($kind) * $gross;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO policy_endorsements (source, ref_id, policy_number, policy_key, endorse_no, kind, reason, description, issue_date, effect_date, new_end_date,
                         gross, net, tax, signed_amount, central_no, insurance_type, finance_mode, request_plate_id, origin, batch_id, data_json, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$source, $refId, mb_substr($policyNumber, 0, 60), endo_policy_key($policyNumber), $no !== '' ? mb_substr($no, 0, 10) : null, $kind,
                       mb_substr(trim((string)($in['reason'] ?? '')), 0, 250) ?: null, trim((string)($in['description'] ?? '')) ?: null,
                       endo_jdate($in['issue_date'] ?? '') ?: null, endo_jdate($in['effect_date'] ?? '') ?: (endo_jdate($in['issue_date'] ?? '') ?: null),
                       endo_jdate($in['new_end_date'] ?? '') ?: null, $gross, intval(preg_replace('/\D/', '', (string)($in['net'] ?? ''))) ?: null,
                       intval(preg_replace('/\D/', '', (string)($in['tax'] ?? ''))) ?: null, $signed, mb_substr((string)($in['central_no'] ?? ''), 0, 20) ?: null,
                       mb_substr((string)($in['insurance_type'] ?? ($pol['info']['type_fa'] ?? '')), 0, 12) ?: null, $mode,
                       intval($in['request_plate_id'] ?? 0) ?: null, mb_substr((string)($in['origin'] ?? 'MANUAL'), 0, 12), intval($in['batch_id'] ?? 0) ?: null,
                       !empty($in['data']) ? json_encode($in['data'], JSON_UNESCAPED_UNICODE) : null, $userId ?: null]);
        $id = (int)$pdo->lastInsertId();
        $plan = endo_plan_finance($pdo, $source, $refId, $kind, $gross, $mode,
                                  ['effect_date' => $in['effect_date'] ?? ($in['issue_date'] ?? ''), 'count' => $in['finance_opt']['count'] ?? 1,
                                   'first_due' => $in['finance_opt']['first_due'] ?? '', 'schedule' => $in['finance_opt']['schedule'] ?? []]);
        $plan = endo_apply_finance($pdo, $source, $refId, $id, $plan);
        $pdo->prepare("UPDATE policy_endorsements SET finance_json = ?, credit = ? WHERE id = ?")
            ->execute([json_encode($plan, JSON_UNESCAPED_UNICODE), (int)$plan['credit'], $id]);
        endo_recompute($pdo, $source, $refId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[endo_create] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'ثبتِ الحاقیه ممکن نشد: ' . $e->getMessage()];
    }

    // فایل: «(الحاقیه ۳ - اضافی) پلاک.pdf» داخلِ پوشه‌ی بیمه‌نامه
    $rel = null;
    if ($srcFile && is_file($srcFile)) {
        $dir = endo_policy_folder($pdo, $siteRoot, $source, $pol['row'] ?? []);
        $label = $pol ? ($pol['info']['label'] ?: $pol['info']['policy_number']) : $policyNumber;
        $name = 'الحاقیه' . ($no !== '' ? ' ' . $no : '') . ' - ' . endo_kind_fa($kind);
        $base = '(' . $name . ') ' . (function_exists('plate_for_filename') ? plate_for_filename($label) : $label);
        $base = function_exists('sanitize_folder_name') ? sanitize_folder_name($base) : preg_replace('#[\\\\/:*?"<>|]#u', ' ', $base);
        $dest = function_exists('unique_dest_path') ? unique_dest_path($dir . '/' . $base . '.pdf') : $dir . '/' . $base . '.pdf';
        $ok = $moveFile ? (@rename($srcFile, $dest) || (@copy($srcFile, $dest) && @unlink($srcFile))) : @copy($srcFile, $dest);
        if ($ok) {
            $rel = ltrim(str_replace($siteRoot, '', $dest), '/');
            $pdo->prepare("UPDATE policy_endorsements SET file_path = ? WHERE id = ?")->execute([$rel, $id]);
        }
    }
    return ['ok' => true, 'id' => $id, 'plan' => $plan, 'file_path' => $rel, 'final_premium' => $pol ? endo_final_premium($pdo, $source, $refId) : null];
}

function endo_final_premium($pdo, $source, $refId) {
    $tbl = $source === 'C' ? 'company_request_plates' : 'policy_cases';
    $r = $pdo->query("SELECT total_premium, final_premium FROM $tbl WHERE id = " . intval($refId))->fetch();
    return $r ? (int)($r['final_premium'] ?? money_to_int($r['total_premium'])) : null;
}

function endo_delete($pdo, $id, $deleteFile = true) {
    endo_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM policy_endorsements WHERE id = ?");
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) return ['ok' => false, 'error' => 'الحاقیه پیدا نشد.'];
    $pdo->beginTransaction();
    try {
        $r = endo_revert_finance($pdo, $e);
        if (empty($r['ok'])) { $pdo->rollBack(); return $r; }
        $pdo->prepare("DELETE FROM policy_endorsements WHERE id = ?")->execute([$id]);
        endo_recompute($pdo, $e['source'], (int)$e['ref_id']);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'حذف ممکن نشد: ' . $ex->getMessage()];
    }
    if ($deleteFile && $e['file_path']) @unlink(dirname(__DIR__) . '/' . $e['file_path']);
    return ['ok' => true];
}

// بیمه‌نامه‌ای که بعداً در سایت ثبت شد: الحاقیه‌های «بیرونی» (X) با همان شماره به آن وصل می‌شوند (بدونِ اثرِ مالی)
function endo_attach_orphans($pdo, $source, $refId, $policyNumber) {
    endo_ensure($pdo);
    $key = endo_policy_key($policyNumber);
    if (strlen($key) < 6) return 0;
    $st = $pdo->prepare("UPDATE policy_endorsements SET source = ?, ref_id = ? WHERE source = 'X' AND policy_key = ?");
    $st->execute([$source, $refId, $key]);
    $n = $st->rowCount();
    if ($n) endo_recompute($pdo, $source, $refId);
    return $n;
}

// فهرست برای صفحه‌ی «الحاقیه‌ها»
function endo_list($pdo, array $f = []) {
    endo_ensure($pdo);
    $w = ['1=1']; $p = [];
    if (!empty($f['kind']) && isset(endo_kinds()[$f['kind']])) { $w[] = 'e.kind = ?'; $p[] = $f['kind']; }
    if (!empty($f['source']) && in_array($f['source'], ['C', 'P', 'X'], true)) { $w[] = 'e.source = ?'; $p[] = $f['source']; }
    if (!empty($f['from'])) { $w[] = 'e.issue_date >= ?'; $p[] = endo_jdate($f['from']); }
    if (!empty($f['to'])) { $w[] = 'e.issue_date <= ?'; $p[] = endo_jdate($f['to']); }
    if (!empty($f['company_id'])) { $w[] = "((e.source = 'C' AND cr.company_id = ?) OR (e.source = 'P' AND per.company_id = ?))"; $p[] = intval($f['company_id']); $p[] = intval($f['company_id']); }
    if (!empty($f['ref'])) { $w[] = 'e.source = ? AND e.ref_id = ?'; $p[] = $f['ref'][0]; $p[] = intval($f['ref'][1]); }
    $q = trim(function_exists('p2e_digits') ? p2e_digits((string)($f['q'] ?? '')) : (string)($f['q'] ?? ''));
    if ($q !== '') {
        $w[] = "(e.policy_number LIKE ? OR e.policy_key LIKE ? OR c.name LIKE ? OR pc.insured_name LIKE ? OR pc.plate LIKE ? OR crp.chassis_no LIKE ?
                 OR CONCAT(crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.plate_p1) LIKE ?)";
        $like = '%' . $q . '%';
        array_push($p, $like, '%' . endo_policy_key($q) . '%', $like, $like, $like, $like, '%' . preg_replace('/\s+/u', '', $q) . '%');
    }
    $st = $pdo->prepare("SELECT e.*, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no, crp.insurance_type AS c_type, crp.total_premium AS c_premium,
                                crp.final_premium AS c_final, cr.company_id AS c_company_id, c.name AS c_company,
                                pc.plate AS p_plate, pc.insured_name AS p_insured, pc.insurance_type AS p_type, pc.total_premium AS p_premium, pc.final_premium AS p_final,
                                per.full_name AS p_holder, pco.name AS p_company, u.full_name AS creator
                           FROM policy_endorsements e
                           LEFT JOIN company_request_plates crp ON e.source = 'C' AND crp.id = e.ref_id
                           LEFT JOIN company_requests cr ON cr.id = crp.request_id
                           LEFT JOIN companies c ON c.id = cr.company_id
                           LEFT JOIN policy_cases pc ON e.source = 'P' AND pc.id = e.ref_id
                           LEFT JOIN persons per ON per.id = pc.person_id
                           LEFT JOIN companies pco ON pco.id = per.company_id
                           LEFT JOIN users u ON u.id = e.created_by
                          WHERE " . implode(' AND ', $w) . " ORDER BY e.issue_date DESC, e.id DESC LIMIT 2000");
    $st->execute($p);
    $rows = [];
    $sum = ['count' => 0, 'add' => 0, 'ref' => 0, 'credit' => 0];
    foreach ($st->fetchAll() as $r) {
        $isC = $r['source'] === 'C';
        $label = $isC ? (function_exists('company_plate_display') && $r['plate_p2'] ? company_plate_display($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) : ($r['chassis_no'] ?: '')) : (string)$r['p_plate'];
        $prem = $isC ? money_to_int($r['c_premium']) : money_to_int($r['p_premium']);
        $final = $isC ? $r['c_final'] : $r['p_final'];
        $plan = json_decode((string)$r['finance_json'], true) ?: [];
        $rows[] = [
            'id' => (int)$r['id'], 'source' => $r['source'], 'source_fa' => $isC ? 'شرکتی' : ($r['source'] === 'P' ? 'کارکنان' : 'خارج از سایت'), 'ref_id' => $r['ref_id'] ? (int)$r['ref_id'] : null,
            'policy_number' => $r['policy_number'], 'endorse_no' => $r['endorse_no'], 'kind' => $r['kind'], 'kind_fa' => endo_kind_fa($r['kind']),
            'reason' => $r['reason'], 'description' => $r['description'], 'issue_date' => $r['issue_date'], 'effect_date' => $r['effect_date'], 'new_end_date' => $r['new_end_date'],
            'gross' => (int)$r['gross'], 'net' => $r['net'] !== null ? (int)$r['net'] : null, 'tax' => $r['tax'] !== null ? (int)$r['tax'] : null, 'signed' => (int)$r['signed_amount'],
            'finance_mode' => $r['finance_mode'], 'finance_fa' => endo_modes_fa()[$r['finance_mode']] ?? $r['finance_mode'], 'credit' => (int)$r['credit'], 'credit_paid' => (int)$r['credit_paid'],
            'plan' => $plan, 'file_path' => $r['file_path'], 'origin' => $r['origin'], 'creator' => $r['creator'], 'created_at' => $r['created_at'],
            'label' => $label, 'company' => $isC ? $r['c_company'] : $r['p_company'], 'insured' => $isC ? $r['c_company'] : ($r['p_insured'] ?: $r['p_holder']),
            'type' => $isC ? $r['c_type'] : $r['p_type'], 'premium' => $prem ?: null, 'final_premium' => $final !== null ? (int)$final : ($prem ?: null),
            'request_plate_id' => $r['request_plate_id'] ? (int)$r['request_plate_id'] : null,
        ];
        $sum['count']++;
        if ((int)$r['signed_amount'] > 0) $sum['add'] += (int)$r['signed_amount']; else $sum['ref'] += -(int)$r['signed_amount'];
        if (!(int)$r['credit_paid']) $sum['credit'] += (int)$r['credit'];
    }
    return ['rows' => $rows, 'summary' => $sum];
}
