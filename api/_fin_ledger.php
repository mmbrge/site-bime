<?php
// فایل: api/_fin_ledger.php
// «مرکز اقساط و پرداخت»: هر قسط (کارکنان و شرکتی) دو طرف دارد، مثل برنامه‌ی مالیِ ویندوزی (ماموت):
//   ۱) دریافت از بیمه‌گذار/شرکت (طرفِ «ما»)       ← payments/payment_allocations یا company_payments(US)
//   ۲) پرداخت به بیمه‌گر (پاسارگاد یا هر بیمه‌گرِ دیگر) ← pasargad_settlements/lines یا company_payments(PASARGAD)
// هر ثبتِ تازه یک «سند» در fin_receipts دارد (با ریزش در fin_receipt_lines) و همان مبالغ در جدول‌های قبلی هم
// نوشته می‌شود تا داشبورد، صورتحساب‌ها، گزارش‌ها و پورتال شرکت‌ها بی‌تغییر درست بمانند. پرداخت می‌تواند
// جزئی باشد؛ قسط وقتی «تسویه با بیمه‌گر» می‌شود که جمعِ پرداخت‌هایش به مبلغِ قسط برسد.

const FIN_METHODS = [
    'CASH' => 'نقد', 'CARD' => 'کارت به کارت / کارتخوان', 'TRANSFER' => 'حواله / انتقال بانکی', 'SLIP' => 'فیش بانکی',
    'CHEQUE' => 'چک', 'PAYROLL' => 'کسر از حقوق', 'OFFSET' => 'تهاتر', 'OTHER' => 'سایر',
];
const FIN_INSURERS = ['PASARGAD' => 'بیمه پاسارگاد', 'IRAN' => 'بیمه ایران', 'ASIA' => 'بیمه آسیا', 'DANA' => 'بیمه دانا',
                      'ALBORZ' => 'بیمه البرز', 'PARSIAN' => 'بیمه پارسیان', 'SAMAN' => 'بیمه سامان', 'OTHER' => 'سایر'];

function fin_method_fa($m) { return FIN_METHODS[$m] ?? ($m ?: '—'); }
function fin_insurer_fa($i) { return FIN_INSURERS[$i] ?? ($i ?: 'پاسارگاد'); }
// روشِ جدول‌های قبلی فقط چهار مقدار می‌پذیرد
function fin_legacy_method($m) {
    return ['CASH' => 'CASH', 'CHEQUE' => 'CHEQUE', 'PAYROLL' => 'PAYROLL'][$m] ?? 'TRANSFER';
}

function fin_ledger_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_receipts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            direction VARCHAR(10) NOT NULL,
            party VARCHAR(255) NULL,
            company_id BIGINT NULL,
            insurer VARCHAR(30) NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            method VARCHAR(20) NOT NULL DEFAULT 'TRANSFER',
            paid_jalali VARCHAR(12) NULL,
            paid_date DATE NULL,
            reference_no VARCHAR(80) NULL,
            cheque_no VARCHAR(40) NULL,
            cheque_bank VARCHAR(80) NULL,
            cheque_due_jalali VARCHAR(12) NULL,
            cheque_due_date DATE NULL,
            cheque_status VARCHAR(12) NULL,
            legacy_cheque_id BIGINT NULL,
            files TEXT NULL,
            note TEXT NULL,
            items_count INT NOT NULL DEFAULT 0,
            status VARCHAR(10) NOT NULL DEFAULT 'ACTIVE',
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voided_at DATETIME NULL,
            KEY idx_dir (direction), KEY idx_date (paid_date)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fin_receipt_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            receipt_id BIGINT NOT NULL,
            source CHAR(1) NOT NULL,
            installment_id BIGINT NOT NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            legacy_kind VARCHAR(10) NULL,
            legacy_id BIGINT NULL,
            legacy_line_id BIGINT NULL,
            KEY idx_receipt (receipt_id), KEY idx_inst (source, installment_id), KEY idx_legacy (legacy_kind, legacy_id)
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('[fin_ledger_ensure] ' . $e->getMessage()); }
}

// مبلغِ پرداخت‌شده به بیمه‌گر برای هر قسط: ['P' => [id => amount], 'C' => [...]]
function fin_ledger_insurer_paid($pdo) {
    $out = ['P' => [], 'C' => []];
    try {
        foreach ($pdo->query("SELECT installment_id, SUM(amount) s FROM pasargad_settlement_lines GROUP BY installment_id") as $r) $out['P'][(int)$r['installment_id']] = (int)$r['s'];
    } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT a.installment_id, SUM(a.amount) s FROM company_payment_allocations a JOIN company_payments cp ON cp.id = a.payment_id
                              WHERE cp.target = 'PASARGAD' GROUP BY a.installment_id") as $r) $out['C'][(int)$r['installment_id']] = (int)$r['s'];
    } catch (Throwable $e) {}
    return $out;
}

// ردیف‌های «مرکز اقساط»: همان نمای یکپارچه + طرفِ بیمه‌گر، مرحله و روزِ تأخیر
function fin_ledger_rows($pdo, array $f) {
    $rule = (fin_settings($pdo)['rule_collect_before_pay'] ?? '0') === '1';
    $base = fin_unified_installments($pdo, $f);
    $paidIns = fin_ledger_insurer_paid($pdo);
    $today = strtotime('today');
    $out = [];
    foreach ($base as $r) {
        $pi = $paidIns[$r['source']][$r['id']] ?? 0;
        if ($r['settled'] && $pi < $r['amount']) $pi = $r['amount'];   // تسویه‌های قدیمی که ریزِ مبلغ نداشتند
        $r['paid_insurer'] = $pi;
        $r['insurer_remaining'] = max(0, $r['amount'] - $pi);
        $r['insurer_payable'] = max(0, ($rule ? $r['paid'] : $r['amount']) - $pi);
        $r['stage'] = $pi >= $r['amount'] ? 'SETTLED' : ($r['remaining'] <= 0 ? 'INSURER' : 'US');
        $r['delay_days'] = ($r['remaining'] > 0 && $r['due_date'] && strtotime($r['due_date']) < $today) ? (int)floor(($today - strtotime($r['due_date'])) / 86400) : 0;
        $r['insurer_fa'] = fin_insurer_fa($r['insurer'] ?? 'PASARGAD');
        $st = $f['stage'] ?? '';
        if ($st !== '' && $st !== 'ALL' && $r['stage'] !== $st) continue;
        if (!empty($f['insurer']) && ($r['insurer'] ?? 'PASARGAD') !== $f['insurer']) continue;
        if (($f['insurer_status'] ?? '') === 'unpaid' && $pi > 0) continue;
        if (($f['insurer_status'] ?? '') === 'partial' && !($pi > 0 && $pi < $r['amount'])) continue;
        if (($f['insurer_status'] ?? '') === 'paid' && $pi < $r['amount']) continue;
        if (!empty($f['min_delay']) && $r['delay_days'] < intval($f['min_delay'])) continue;
        $out[] = $r;
    }
    return $out;
}

function fin_ledger_summary(array $rows) {
    $s = ['count' => count($rows), 'amount' => 0, 'paid' => 0, 'remaining' => 0, 'overdue' => 0, 'overdue_count' => 0,
          'insurer_paid' => 0, 'insurer_payable' => 0, 'stage' => ['US' => 0, 'INSURER' => 0, 'SETTLED' => 0]];
    foreach ($rows as $r) {
        $s['amount'] += $r['amount']; $s['paid'] += $r['paid']; $s['remaining'] += $r['remaining'];
        if ($r['overdue']) { $s['overdue'] += $r['remaining']; $s['overdue_count']++; }
        $s['insurer_paid'] += $r['paid_insurer']; $s['insurer_payable'] += $r['insurer_payable'];
        $s['stage'][$r['stage']]++;
    }
    return $s;
}

// ذخیره‌ی فایل‌های فیش/چک
function fin_ledger_save_files($pdo, $field, $direction, $paidJalali, $companyName, $ref, $amount) {
    $saved = [];
    if (empty($_FILES[$field])) return $saved;
    $siteRoot = dirname(__DIR__);
    [$py, $pm, $pd] = fin_jalali_parts($paidJalali);
    $period = fin_resolve_period_for_date($pdo, $py, $pm, $pd);
    $dir = fin_receipt_folder($siteRoot, $period, $companyName, $direction === 'OUT' ? 'pasargad' : 'us');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $files = $_FILES[$field];
    $n = is_array($files['name']) ? count($files['name']) : 0;
    for ($i = 0; $i < $n; $i++) {
        if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) continue;
        $base = sanitize_folder_name(p2e_digits($paidJalali ?: jalali_from_gregorian_ts_dotted(time())) . ' - ' . ($ref ?: 'فیش') . ' - ' . number_format($amount));
        $dest = unique_dest_path($dir . '/' . $base . '.' . $ext);
        if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && function_exists('compress_image_if_needed')) compress_image_if_needed($dest);
            $saved[] = ltrim(str_replace($siteRoot, '', $dest), '/');
        }
    }
    return $saved;
}

// وضعیتِ «تسویه با بیمه‌گر»ی یک قسط را با جمعِ پرداخت‌هایش هماهنگ می‌کند
function fin_ledger_sync_settled($pdo, $source, $instId) {
    if ($source === 'P') {
        $st = $pdo->prepare("SELECT pi.amount, COALESCE((SELECT SUM(amount) FROM pasargad_settlement_lines WHERE installment_id = pi.id),0) s FROM policy_installments pi WHERE pi.id = ?");
        $st->execute([$instId]); $r = $st->fetch();
        if ($r) $pdo->prepare("UPDATE policy_installments SET settled_to_pasargad = ? WHERE id = ?")->execute([intval($r['s']) >= intval($r['amount']) ? 1 : 0, $instId]);
    } else {
        $st = $pdo->prepare("SELECT ci.amount, COALESCE((SELECT SUM(a.amount) FROM company_payment_allocations a JOIN company_payments cp ON cp.id = a.payment_id
                                                          WHERE a.installment_id = ci.id AND cp.target = 'PASARGAD'),0) s FROM company_installments ci WHERE ci.id = ?");
        $st->execute([$instId]); $r = $st->fetch();
        if ($r) $pdo->prepare("UPDATE company_installments SET settled_to_pasargad = ? WHERE id = ?")->execute([intval($r['s']) >= intval($r['amount']) ? 1 : 0, $instId]);
    }
}

// =====================================================================
//  ثبتِ یک سند: دریافت از بیمه‌گذار (IN) یا پرداخت به بیمه‌گر (OUT) روی قسط‌های انتخاب‌شده
//  $items: [['source' => 'P'|'C', 'id' => installment_id, 'amount' => int], ...]
// =====================================================================
function fin_ledger_register($pdo, array $in, array $items, $userId) {
    fin_ledger_ensure($pdo);
    $dir = ($in['direction'] ?? '') === 'OUT' ? 'OUT' : 'IN';
    $method = isset(FIN_METHODS[$in['method'] ?? '']) ? $in['method'] : 'TRANSFER';
    $paidJ = p2e_digits(trim((string)($in['paid_jalali'] ?? '')));
    $paidDate = fin_jalali_to_date($paidJ) ?: date('Y-m-d');
    if ($paidJ === '') $paidJ = str_replace('.', '/', jalali_from_gregorian_ts_dotted(time()));
    $ref = trim((string)($in['reference_no'] ?? ''));
    $note = trim((string)($in['note'] ?? ''));
    $insurer = $dir === 'OUT' ? (isset(FIN_INSURERS[$in['insurer'] ?? '']) ? $in['insurer'] : 'PASARGAD') : null;
    $rule = (fin_settings($pdo)['rule_collect_before_pay'] ?? '0') === '1';
    if ($method === 'CHEQUE' && trim((string)($in['cheque_no'] ?? '')) === '') return ['ok' => false, 'error' => 'برای چک، شماره‌ی چک لازم است.'];

    // ---- اعتبارسنجیِ تک‌تکِ ردیف‌ها با وضعیتِ همین لحظه‌ی دیتابیس ----
    $paidIns = fin_ledger_insurer_paid($pdo);
    $applied = []; $rejected = [];
    foreach ($items as $it) {
        $src = ($it['source'] ?? '') === 'C' ? 'C' : 'P';
        $id = intval($it['id'] ?? 0);
        $amt = intval(money_to_int($it['amount'] ?? 0));
        if (!$id || $amt <= 0) continue;
        if ($src === 'P') {
            $st = $pdo->prepare("SELECT pi.id, pi.amount, pi.inst_number, COALESCE(pi.settled_to_pasargad,0) settled, pc.insured_name, per.full_name,
                                        per.company_id, co.name AS company_name, NULL AS request_id,
                                        COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.installment_id = pi.id),0) paid
                                   FROM policy_installments pi JOIN policy_cases pc ON pc.id = pi.case_id JOIN persons per ON per.id = pc.person_id
                                   LEFT JOIN companies co ON co.id = per.company_id WHERE pi.id = ?");
        } else {
            $st = $pdo->prepare("SELECT ci.id, ci.amount, ci.inst_number, COALESCE(ci.settled_to_pasargad,0) settled, c.name AS insured_name, c.name AS full_name,
                                        cr.company_id, c.name AS company_name, cr.id AS request_id,
                                        COALESCE((SELECT SUM(a.amount) FROM company_payment_allocations a JOIN company_payments cp ON cp.id = a.payment_id
                                                  WHERE a.installment_id = ci.id AND cp.target = 'US'),0) paid
                                   FROM company_installments ci JOIN company_request_plates crp ON crp.id = ci.plate_id
                                   JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE ci.id = ?");
        }
        $st->execute([$id]);
        $r = $st->fetch();
        $label = $r ? (($r['insured_name'] ?: $r['full_name']) . ' / قسط ' . $r['inst_number']) : ('#' . $id);
        if (!$r) { $rejected[] = ['source' => $src, 'id' => $id, 'label' => $label, 'reason' => 'قسط پیدا نشد.']; continue; }
        $amount = intval($r['amount']); $paid = intval($r['paid']);
        $pi = $paidIns[$src][$id] ?? 0;
        if (intval($r['settled']) && $pi < $amount) $pi = $amount;
        if ($dir === 'IN') {
            $max = max(0, $amount - $paid);
            if ($amt > $max) { $rejected[] = ['source' => $src, 'id' => $id, 'label' => $label, 'reason' => $max ? 'بیش از مانده‌ی قسط (' . number_format($max) . ' ریال) است.' : 'این قسط قبلاً کامل دریافت شده.']; continue; }
        } else {
            $max = max(0, ($rule ? $paid : $amount) - $pi);
            if ($amt > $max) {
                $rejected[] = ['source' => $src, 'id' => $id, 'label' => $label,
                               'reason' => $max ? 'بیش از قابلِ پرداخت به بیمه‌گر (' . number_format($max) . ' ریال) است.' : ($rule && $pi < $amount ? 'طبقِ قانونِ «اول دریافت، بعد پرداخت»، هنوز از بیمه‌گذار دریافت نشده.' : 'این قسط قبلاً کامل به بیمه‌گر پرداخت شده.')];
                continue;
            }
        }
        $applied[] = ['source' => $src, 'id' => $id, 'amount' => $amt, 'company_id' => $r['company_id'] ? intval($r['company_id']) : null,
                      'company_name' => $r['company_name'], 'request_id' => $r['request_id'] ? intval($r['request_id']) : null, 'label' => $label];
    }
    if (!$applied) return ['ok' => false, 'error' => $rejected ? 'هیچ ردیفی قابلِ ثبت نبود.' : 'ردیفی انتخاب نشده.', 'rejected' => $rejected];

    $total = array_sum(array_column($applied, 'amount'));
    $companies = array_values(array_unique(array_filter(array_column($applied, 'company_name'))));
    $party = trim((string)($in['party'] ?? '')) ?: ($dir === 'OUT' ? fin_insurer_fa($insurer) : (count($companies) === 1 ? $companies[0] : 'چند شرکت / بیمه‌گذار'));
    $companyId = count(array_unique(array_filter(array_column($applied, 'company_id')))) === 1 ? $applied[0]['company_id'] : null;
    $files = fin_ledger_save_files($pdo, 'files', $dir, $paidJ, count($companies) === 1 ? $companies[0] : null, $ref, $total);
    $filesJson = $files ? json_encode($files, JSON_UNESCAPED_UNICODE) : null;
    $legacyMethod = fin_legacy_method($method);
    $legacyNote = trim('[' . fin_method_fa($method) . ($dir === 'OUT' ? ' · ' . fin_insurer_fa($insurer) : '') . '] ' . $note);

    $pdo->beginTransaction();
    try {
        // چکِ دریافتی در جدولِ چک‌های قبلی هم ثبت می‌شود تا در گزارش‌های قبلی دیده شود
        $legacyChequeId = null;
        if ($method === 'CHEQUE' && $dir === 'IN') {
            try {
                $pdo->prepare("INSERT INTO cheques (company_id, cheque_no, bank_name, amount, due_jalali, due_date, status, note) VALUES (?,?,?,?,?,?, 'PENDING', ?)")
                    ->execute([$companyId, trim((string)$in['cheque_no']), trim((string)($in['cheque_bank'] ?? '')), $total,
                               p2e_digits(trim((string)($in['cheque_due'] ?? ''))), fin_jalali_to_date($in['cheque_due'] ?? ''), $note]);
                $legacyChequeId = $pdo->lastInsertId();
            } catch (Throwable $e) { $legacyChequeId = null; }
        }
        $pdo->prepare("INSERT INTO fin_receipts (direction, party, company_id, insurer, amount, method, paid_jalali, paid_date, reference_no,
                                                  cheque_no, cheque_bank, cheque_due_jalali, cheque_due_date, cheque_status, legacy_cheque_id, files, note, items_count, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$dir, $party, $companyId, $insurer, $total, $method, $paidJ, $paidDate, $ref ?: null,
                       $method === 'CHEQUE' ? trim((string)$in['cheque_no']) : null, $method === 'CHEQUE' ? trim((string)($in['cheque_bank'] ?? '')) : null,
                       $method === 'CHEQUE' ? p2e_digits(trim((string)($in['cheque_due'] ?? ''))) : null, $method === 'CHEQUE' ? fin_jalali_to_date($in['cheque_due'] ?? '') : null,
                       $method === 'CHEQUE' ? 'PENDING' : null, $legacyChequeId, $filesJson, $note ?: null, count($applied), $userId]);
        $rid = intval($pdo->lastInsertId());
        $insLine = $pdo->prepare("INSERT INTO fin_receipt_lines (receipt_id, source, installment_id, amount, legacy_kind, legacy_id, legacy_line_id) VALUES (?,?,?,?,?,?,?)");

        // گروه‌بندی برای جدول‌های قبلی: کارکنان به ازای هر شرکت، شرکتی به ازای هر درخواست
        $groups = [];
        foreach ($applied as $a) {
            $key = $a['source'] . ':' . ($a['source'] === 'P' ? ($a['company_id'] ?? 0) : $a['request_id']);
            $groups[$key][] = $a;
        }
        foreach ($groups as $key => $grp) {
            $sum = array_sum(array_column($grp, 'amount'));
            $src = $grp[0]['source'];
            if ($dir === 'IN' && $src === 'P') {
                $pdo->prepare("INSERT INTO payments (company_id, invoice_id, amount, method, paid_jalali, paid_at, reference_no, cheque_id, receipts, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$grp[0]['company_id'], null, $sum, $legacyMethod, $paidJ, $paidDate, $ref, $legacyChequeId, $filesJson, $legacyNote, $userId]);
                $pid = $pdo->lastInsertId(); $kind = 'P_PAY';
                $alloc = $pdo->prepare("INSERT INTO payment_allocations (payment_id, installment_id, amount) VALUES (?,?,?)");
            } elseif ($dir === 'IN') {
                $pdo->prepare("INSERT INTO company_payments (request_id, target, amount, method, paid_jalali, paid_at, reference_no, receipts, note, created_by) VALUES (?, 'US', ?,?,?,?,?,?,?,?)")
                    ->execute([$grp[0]['request_id'], $sum, $legacyMethod, $paidJ, $paidDate, $ref, $filesJson, $legacyNote, $userId]);
                $pid = $pdo->lastInsertId(); $kind = 'C_PAY';
                $alloc = $pdo->prepare("INSERT INTO company_payment_allocations (payment_id, installment_id, amount) VALUES (?,?,?)");
            } elseif ($src === 'P') {
                $pdo->prepare("INSERT INTO pasargad_settlements (period_id, company_id, invoice_id, amount, paid_jalali, reference_no, receipts, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([null, $grp[0]['company_id'], null, $sum, $paidJ, $ref, $filesJson, $legacyNote, $userId]);
                $pid = $pdo->lastInsertId(); $kind = 'PSG';
                $alloc = $pdo->prepare("INSERT INTO pasargad_settlement_lines (settlement_id, installment_id, amount) VALUES (?,?,?)");
            } else {
                $pdo->prepare("INSERT INTO company_payments (request_id, target, amount, method, paid_jalali, paid_at, reference_no, receipts, note, created_by) VALUES (?, 'PASARGAD', ?,?,?,?,?,?,?,?)")
                    ->execute([$grp[0]['request_id'], $sum, $legacyMethod, $paidJ, $paidDate, $ref, $filesJson, $legacyNote, $userId]);
                $pid = $pdo->lastInsertId(); $kind = 'C_PSG';
                $alloc = $pdo->prepare("INSERT INTO company_payment_allocations (payment_id, installment_id, amount) VALUES (?,?,?)");
            }
            foreach ($grp as $a) {
                $alloc->execute([$pid, $a['id'], $a['amount']]);
                $insLine->execute([$rid, $a['source'], $a['id'], $a['amount'], $kind, $pid, $pdo->lastInsertId()]);
                if ($dir === 'OUT') fin_ledger_sync_settled($pdo, $a['source'], $a['id']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[fin_ledger_register] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'ثبت در دیتابیس ممکن نشد.'];
    }
    return ['ok' => true, 'receipt_id' => $rid, 'total' => $total, 'applied' => count($applied), 'rejected' => $rejected, 'files' => count($files)];
}

// باطل‌کردنِ یک سند: همه‌ی تخصیص‌ها و ردیف‌های قبلیِ ساخته‌شده پاک و وضعیتِ تسویه دوباره حساب می‌شود
function fin_ledger_void($pdo, $id) {
    fin_ledger_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM fin_receipts WHERE id = ?");
    $st->execute([$id]);
    $rc = $st->fetch();
    if (!$rc) return ['ok' => false, 'error' => 'سند پیدا نشد.'];
    if ($rc['status'] === 'VOID') return ['ok' => false, 'error' => 'این سند قبلاً باطل شده.'];
    $lines = $pdo->prepare("SELECT * FROM fin_receipt_lines WHERE receipt_id = ?");
    $lines->execute([$id]);
    $lines = $lines->fetchAll();
    $pdo->beginTransaction();
    try {
        $parents = [];
        foreach ($lines as $l) {
            $tbl = ['P_PAY' => 'payment_allocations', 'C_PAY' => 'company_payment_allocations', 'PSG' => 'pasargad_settlement_lines', 'C_PSG' => 'company_payment_allocations'][$l['legacy_kind']] ?? null;
            if ($tbl && $l['legacy_line_id']) $pdo->prepare("DELETE FROM `$tbl` WHERE id = ?")->execute([$l['legacy_line_id']]);
            $parents[$l['legacy_kind'] . ':' . $l['legacy_id']] = [$l['legacy_kind'], $l['legacy_id']];
        }
        foreach ($parents as [$k, $pid]) {
            $tbl = ['P_PAY' => 'payments', 'C_PAY' => 'company_payments', 'PSG' => 'pasargad_settlements', 'C_PSG' => 'company_payments'][$k] ?? null;
            if ($tbl && $pid) $pdo->prepare("DELETE FROM `$tbl` WHERE id = ?")->execute([$pid]);
        }
        foreach ($lines as $l) if ($rc['direction'] === 'OUT') fin_ledger_sync_settled($pdo, $l['source'], $l['installment_id']);
        if ($rc['legacy_cheque_id']) { try { $pdo->prepare("DELETE FROM cheques WHERE id = ?")->execute([$rc['legacy_cheque_id']]); } catch (Throwable $e) {} }
        $pdo->prepare("UPDATE fin_receipts SET status = 'VOID', voided_at = NOW() WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[fin_ledger_void] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'باطل‌کردن ممکن نشد.'];
    }
    return ['ok' => true];
}

// سابقه‌ی دریافت و پرداختِ یک قسط (همه‌ی منابع، قدیمی و تازه)
function fin_ledger_history($pdo, $source, $id) {
    fin_ledger_ensure($pdo);
    $out = [];
    $link = $pdo->prepare("SELECT r.id, r.method, r.cheque_no, r.cheque_status, r.files, r.status FROM fin_receipt_lines l JOIN fin_receipts r ON r.id = l.receipt_id WHERE l.legacy_kind = ? AND l.legacy_line_id = ?");
    $add = function ($dir, $kind, $row) use (&$out, $link) {
        $link->execute([$kind, $row['line_id']]);
        $rc = $link->fetch();
        $out[] = ['direction' => $dir, 'amount' => intval($row['amount']), 'paid_jalali' => $row['paid_jalali'], 'reference_no' => $row['reference_no'],
                  'method' => $rc ? $rc['method'] : ($row['method'] ?? null), 'method_fa' => fin_method_fa($rc ? $rc['method'] : ($row['method'] ?? '')),
                  'cheque_no' => $rc['cheque_no'] ?? null, 'cheque_status' => $rc['cheque_status'] ?? null, 'receipt_id' => $rc['id'] ?? null,
                  'files' => json_decode((string)($rc['files'] ?? $row['receipts'] ?? ''), true) ?: [], 'note' => $row['note'] ?? '', 'created_at' => $row['created_at'] ?? null];
    };
    try {
        if ($source === 'P') {
            $st = $pdo->prepare("SELECT pa.id line_id, pa.amount, pm.paid_jalali, pm.reference_no, pm.method, pm.receipts, pm.note, NULL created_at FROM payment_allocations pa JOIN payments pm ON pm.id = pa.payment_id WHERE pa.installment_id = ? ORDER BY pa.id");
            $st->execute([$id]); foreach ($st->fetchAll() as $r) $add('IN', 'P_PAY', $r);
            $st = $pdo->prepare("SELECT l.id line_id, l.amount, s.paid_jalali, s.reference_no, NULL method, s.receipts, s.note, NULL created_at FROM pasargad_settlement_lines l JOIN pasargad_settlements s ON s.id = l.settlement_id WHERE l.installment_id = ? ORDER BY l.id");
            $st->execute([$id]); foreach ($st->fetchAll() as $r) $add('OUT', 'PSG', $r);
        } else {
            $st = $pdo->prepare("SELECT a.id line_id, a.amount, cp.paid_jalali, cp.reference_no, cp.method, cp.receipts, cp.note, cp.created_at, cp.target FROM company_payment_allocations a JOIN company_payments cp ON cp.id = a.payment_id WHERE a.installment_id = ? ORDER BY a.id");
            $st->execute([$id]);
            foreach ($st->fetchAll() as $r) $add($r['target'] === 'US' ? 'IN' : 'OUT', $r['target'] === 'US' ? 'C_PAY' : 'C_PSG', $r);
        }
    } catch (Throwable $e) { error_log('[fin_ledger_history] ' . $e->getMessage()); }
    return $out;
}

// دفترِ دریافت‌ها و پرداخت‌ها: سندهای تازه + ثبت‌های قدیمی‌ای که سندِ تازه ندارند
function fin_ledger_list($pdo, array $f) {
    fin_ledger_ensure($pdo);
    $dirF = $f['direction'] ?? '';
    $from = fin_jalali_to_date($f['from'] ?? ''); $to = fin_jalali_to_date($f['to'] ?? '');
    $cid = intval($f['company_id'] ?? 0);
    $rows = [];
    $linked = ['P_PAY' => [], 'C_PAY' => [], 'PSG' => [], 'C_PSG' => []];
    foreach ($pdo->query("SELECT DISTINCT legacy_kind, legacy_id FROM fin_receipt_lines l JOIN fin_receipts r ON r.id = l.receipt_id") as $l) $linked[$l['legacy_kind']][(int)$l['legacy_id']] = true;

    $st = $pdo->query("SELECT r.*, (SELECT GROUP_CONCAT(DISTINCT l.source) FROM fin_receipt_lines l WHERE l.receipt_id = r.id) AS sources FROM fin_receipts r ORDER BY r.id DESC LIMIT 2000");
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['key' => 'R' . $r['id'], 'id' => (int)$r['id'], 'is_new' => true, 'direction' => $r['direction'], 'party' => $r['party'], 'company_id' => $r['company_id'],
                   'insurer' => $r['insurer'], 'amount' => (int)$r['amount'], 'method' => $r['method'], 'paid_jalali' => $r['paid_jalali'], 'paid_date' => $r['paid_date'],
                   'reference_no' => $r['reference_no'], 'cheque_no' => $r['cheque_no'], 'cheque_bank' => $r['cheque_bank'], 'cheque_due_jalali' => $r['cheque_due_jalali'],
                   'cheque_status' => $r['cheque_status'], 'files' => json_decode((string)$r['files'], true) ?: [], 'note' => $r['note'],
                   'items_count' => (int)$r['items_count'], 'status' => $r['status'], 'sources' => $r['sources']];
    }
    $legacy = function ($sql, $kind, $dir, $srcCode) use ($pdo, &$rows, $linked) {
        try {
            foreach ($pdo->query($sql) as $r) {
                if (isset($linked[$kind][(int)$r['id']])) continue;
                $rows[] = ['key' => $kind . $r['id'], 'id' => (int)$r['id'], 'is_new' => false, 'direction' => $dir, 'party' => $r['party'] ?: ($dir === 'OUT' ? 'بیمه پاسارگاد' : '—'),
                           'company_id' => $r['company_id'] ?? null, 'insurer' => $dir === 'OUT' ? 'PASARGAD' : null, 'amount' => (int)$r['amount'],
                           'method' => $r['method'] ?? 'TRANSFER', 'paid_jalali' => $r['paid_jalali'], 'paid_date' => $r['paid_at'] ?? fin_jalali_to_date($r['paid_jalali'] ?? ''),
                           'reference_no' => $r['reference_no'], 'cheque_no' => $r['cheque_no'] ?? null, 'cheque_bank' => $r['bank_name'] ?? null,
                           'cheque_due_jalali' => $r['cheque_due'] ?? null, 'cheque_status' => $r['cheque_status'] ?? null,
                           'files' => json_decode((string)($r['receipts'] ?? ''), true) ?: [], 'note' => $r['note'], 'items_count' => (int)($r['items'] ?? 0),
                           'status' => 'ACTIVE', 'sources' => $srcCode];
            }
        } catch (Throwable $e) { error_log('[fin_ledger_list ' . $kind . '] ' . $e->getMessage()); }
    };
    $legacy("SELECT pm.id, pm.company_id, c.name party, pm.amount, pm.method, pm.paid_jalali, pm.paid_at, pm.reference_no, pm.receipts, pm.note,
                    ch.cheque_no, ch.bank_name, ch.due_jalali cheque_due, ch.status cheque_status,
                    (SELECT COUNT(*) FROM payment_allocations WHERE payment_id = pm.id) items
               FROM payments pm LEFT JOIN companies c ON c.id = pm.company_id LEFT JOIN cheques ch ON ch.id = pm.cheque_id ORDER BY pm.id DESC LIMIT 2000", 'P_PAY', 'IN', 'P');
    $legacy("SELECT cp.id, cr.company_id, c.name party, cp.amount, cp.method, cp.paid_jalali, cp.paid_at, cp.reference_no, cp.receipts, cp.note,
                    (SELECT COUNT(*) FROM company_payment_allocations WHERE payment_id = cp.id) items
               FROM company_payments cp JOIN company_requests cr ON cr.id = cp.request_id JOIN companies c ON c.id = cr.company_id WHERE cp.target = 'US' ORDER BY cp.id DESC LIMIT 2000", 'C_PAY', 'IN', 'C');
    $legacy("SELECT s.id, s.company_id, 'بیمه پاسارگاد' party, s.amount, 'TRANSFER' method, s.paid_jalali, NULL paid_at, s.reference_no, s.receipts, s.note,
                    (SELECT COUNT(*) FROM pasargad_settlement_lines WHERE settlement_id = s.id) items
               FROM pasargad_settlements s ORDER BY s.id DESC LIMIT 2000", 'PSG', 'OUT', 'P');
    $legacy("SELECT cp.id, cr.company_id, 'بیمه پاسارگاد' party, cp.amount, cp.method, cp.paid_jalali, cp.paid_at, cp.reference_no, cp.receipts, cp.note,
                    (SELECT COUNT(*) FROM company_payment_allocations WHERE payment_id = cp.id) items
               FROM company_payments cp JOIN company_requests cr ON cr.id = cp.request_id WHERE cp.target = 'PASARGAD' ORDER BY cp.id DESC LIMIT 2000", 'C_PSG', 'OUT', 'C');

    $q = mb_strtolower(trim(p2e_digits((string)($f['q'] ?? ''))));
    $out = [];
    foreach ($rows as $r) {
        if ($dirF && $r['direction'] !== $dirF) continue;
        if (!empty($f['method']) && $r['method'] !== $f['method']) continue;
        if (!empty($f['insurer']) && ($r['insurer'] ?? '') !== $f['insurer']) continue;
        if ($cid && intval($r['company_id']) !== $cid) continue;
        if (!empty($f['cheque_only']) && !$r['cheque_no']) continue;
        if (!empty($f['cheque_status']) && ($r['cheque_status'] ?? '') !== $f['cheque_status']) continue;
        if (($f['status'] ?? 'ACTIVE') === 'ACTIVE' && $r['status'] !== 'ACTIVE') continue;
        $pd = $r['paid_date'] ?: fin_jalali_to_date($r['paid_jalali'] ?? '');
        if ($from && (!$pd || $pd < $from)) continue;
        if ($to && (!$pd || $pd > $to)) continue;
        if ($q !== '') {
            $hay = mb_strtolower(p2e_digits(implode(' ', [$r['party'], $r['reference_no'], $r['cheque_no'], $r['note'], $r['amount'], $r['paid_jalali']])));
            if (mb_strpos($hay, $q) === false) continue;
        }
        $r['method_fa'] = fin_method_fa($r['method']);
        $r['insurer_fa'] = $r['insurer'] ? fin_insurer_fa($r['insurer']) : '';
        $r['sort'] = $pd ?: '0000-00-00';
        $out[] = $r;
    }
    usort($out, fn($a, $b) => [$b['sort'], $b['key']] <=> [$a['sort'], $a['key']]);
    return $out;
}
