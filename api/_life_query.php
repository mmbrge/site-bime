<?php
// فایل: api/_life_query.php
// فهرستِ بیمه‌نامه‌ها با فیلتر، جزئیات، آمارِ داشبورد، خروجیِ اکسل و شناساییِ بیمه‌نامه‌های «قسطِ اولِ پرداخت‌نشده».

// تجمیعِ اقساطِ هر بیمه‌نامه (زیرپرس‌وجو)
function life_agg_sql() {
    $paid = life_sql_paid('i'); $rem = life_sql_rem('i');
    return "SELECT i.policy_id, COUNT(*) AS inst_count, MAX(i.inst_no) AS max_inst, SUM(i.amount) AS total_amount, SUM($paid) AS total_paid, SUM($rem) AS total_rem,
                   SUM(CASE WHEN $rem > 0 AND i.due_g < :today1 THEN 1 ELSE 0 END) AS overdue_count,
                   SUM(CASE WHEN $rem > 0 AND i.due_g < :today2 THEN $rem ELSE 0 END) AS overdue_amount,
                   MIN(CASE WHEN $rem > 0 THEN i.due_j END) AS next_due,
                   MIN(CASE WHEN $rem > 0 AND i.due_g < :today3 THEN i.due_g END) AS oldest_overdue_g,
                   SUM(CASE WHEN i.inst_no = 1 THEN 1 ELSE 0 END) AS has_first,
                   SUM(CASE WHEN i.inst_no = 1 THEN $paid ELSE 0 END) AS first_paid,
                   MIN(i.amount) AS min_amount,
                   SUBSTRING_INDEX(GROUP_CONCAT(i.amount ORDER BY i.inst_no ASC), ',', 1) AS first_amount,
                   SUBSTRING_INDEX(GROUP_CONCAT(i.amount ORDER BY i.inst_no DESC), ',', 1) AS last_amount
              FROM life_installments i GROUP BY i.policy_id";
}

// شرط‌های فیلتر => [where[], params[]]
function life_filter_sql(array $f, &$params) {
    $w = [];
    $q = trim(p2e_digits((string)($f['q'] ?? '')));
    if ($q !== '') {
        $w[] = "(p.policy_no LIKE :q1 OR p.holder_name LIKE :q2 OR p.holder_nid LIKE :q3 OR p.holder_mobile LIKE :q4 OR p.phones LIKE :q5 OR p.insured_name LIKE :q6 OR p.pay_id LIKE :q7)";
        for ($k = 1; $k <= 7; $k++) $params[":q$k"] = '%' . str_replace(['ي', 'ك'], ['ی', 'ک'], $q) . '%';
    }
    $mon = function ($v) { $v = p2e_digits((string)$v); return preg_match('/^(1[34]\d{2})\/(\d{1,2})$/', $v, $m) ? sprintf('%04d/%02d', $m[1], $m[2]) : ''; };
    if (($a = $mon($f['issue_from'] ?? '')) !== '') { $w[] = "p.issue_j >= :if"; $params[':if'] = $a . '/01'; }
    if (($a = $mon($f['issue_to'] ?? '')) !== '') { $w[] = "p.issue_j <= :it"; $params[':it'] = $a . '/31'; }
    $df = $mon($f['due_from'] ?? ''); $dt = $mon($f['due_to'] ?? '');
    if ($df !== '' || $dt !== '') {
        $c = "EXISTS (SELECT 1 FROM life_installments d WHERE d.policy_id = p.id";
        if ($df !== '') { $c .= " AND d.due_j >= :df"; $params[':df'] = $df . '/01'; }
        if ($dt !== '') { $c .= " AND d.due_j <= :dt"; $params[':dt'] = $dt . '/31'; }
        if (in_array($f['status'] ?? '', ['unpaid', 'overdue'], true)) $c .= " AND " . life_sql_rem('d') . " > 0";
        $w[] = $c . ")";
    }
    if (($pm = trim((string)($f['pay_method'] ?? ''))) !== '') { $w[] = "p.pay_method = :pm"; $params[':pm'] = $pm; }
    if (($ps = trim((string)($f['policy_status'] ?? ''))) !== '') { $w[] = "p.policy_status = :ps"; $params[':ps'] = $ps; }
    switch ($f['status'] ?? '') {
        case 'overdue': $w[] = "COALESCE(a.overdue_count, 0) > 0"; break;
        case 'unpaid': $w[] = "COALESCE(a.total_rem, 0) > 0"; break;
        case 'paid': $w[] = "COALESCE(a.total_rem, 0) = 0 AND COALESCE(a.inst_count, 0) > 0"; break;
        case 'first_unpaid': $w[] = "a.has_first > 0 AND COALESCE(a.first_paid, 0) = 0 AND COALESCE(a.total_paid, 0) = 0"; break;
    }
    $oc = trim((string)($f['overdue_count'] ?? ''));
    if ($oc !== '') {
        if (substr($oc, -1) === '+') { $w[] = "COALESCE(a.overdue_count, 0) >= :oc"; $params[':oc'] = intval($oc); }
        else { $w[] = "COALESCE(a.overdue_count, 0) = :oc"; $params[':oc'] = intval($oc); }
    }
    if (!empty($f['followup'])) {
        $w[] = "EXISTS (SELECT 1 FROM life_notes n WHERE n.policy_id = p.id AND n.next_g IS NOT NULL AND n.next_g <= :fu
                AND n.id = (SELECT MAX(n2.id) FROM life_notes n2 WHERE n2.policy_id = p.id AND n2.kind = 'call'))";
        $params[':fu'] = date('Y-m-d');
    }
    // پیگیری‌کننده: شناسه‌ی کاربر، یا «none» برای بیمه‌نامه‌های بی‌پیگیری‌کننده
    $fol = (string)($f['follower'] ?? '');
    if ($fol === 'none') $w[] = "NOT EXISTS (SELECT 1 FROM life_followers lf WHERE lf.policy_id = p.id)";
    elseif (intval($fol) > 0) { $w[] = "EXISTS (SELECT 1 FROM life_followers lf WHERE lf.policy_id = p.id AND lf.user_id = :fol)"; $params[':fol'] = intval($fol); }
    if (!empty($f['no_phone'])) $w[] = "(COALESCE(p.holder_mobile, '') = '' AND COALESCE(p.phones, '') = '')";
    if (!empty($f['ids']) && is_array($f['ids'])) { $ids = array_filter(array_map('intval', $f['ids'])); if ($ids) $w[] = "p.id IN (" . implode(',', $ids) . ")"; }
    return $w;
}

function life_list($pdo, array $f, $page = 1, $per = 30, $all = false) {
    $params = [':today1' => date('Y-m-d'), ':today2' => date('Y-m-d'), ':today3' => date('Y-m-d')];
    $w = life_filter_sql($f, $params);
    $from = "FROM life_policies p LEFT JOIN (" . life_agg_sql() . ") a ON a.policy_id = p.id" . ($w ? " WHERE " . implode(' AND ', $w) : '');
    $sorts = ['overdue' => 'COALESCE(a.overdue_amount, 0) DESC, p.id DESC', 'issue' => 'p.issue_j DESC, p.id DESC', 'issue_asc' => 'p.issue_j ASC, p.id', 'name' => 'p.holder_name, p.id',
              'next_due' => 'a.next_due IS NULL, a.next_due, p.id', 'rem' => 'COALESCE(a.total_rem, 0) DESC, p.id DESC', 'recent' => 'p.updated_at DESC, p.id DESC'];
    $order = $sorts[$f['sort'] ?? ''] ?? $sorts['overdue'];
    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(a.total_amount), 0), COALESCE(SUM(a.total_paid), 0), COALESCE(SUM(a.total_rem), 0), COALESCE(SUM(a.overdue_amount), 0), COALESCE(SUM(a.overdue_count), 0) $from");
    $st->execute($params);
    [$total, $sAmount, $sPaid, $sRem, $sOver, $sOverN] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
    $per = max(5, min(500, intval($per)));
    $page = max(1, intval($page));
    $lim = $all ? '' : " LIMIT $per OFFSET " . (($page - 1) * $per);
    $st = $pdo->prepare("SELECT p.*, COALESCE(a.inst_count, 0) AS inst_count, COALESCE(a.max_inst, 0) AS max_inst, COALESCE(a.total_amount, 0) AS total_amount,
                                COALESCE(a.total_paid, 0) AS total_paid, COALESCE(a.total_rem, 0) AS total_rem, COALESCE(a.overdue_count, 0) AS overdue_count,
                                COALESCE(a.overdue_amount, 0) AS overdue_amount, a.next_due, a.oldest_overdue_g, a.has_first, a.first_paid, a.min_amount,
                                (SELECT n.at FROM life_notes n WHERE n.policy_id = p.id AND n.kind = 'call' ORDER BY n.at DESC, n.id DESC LIMIT 1) AS last_call,
                                (SELECT n.result FROM life_notes n WHERE n.policy_id = p.id AND n.kind = 'call' ORDER BY n.at DESC, n.id DESC LIMIT 1) AS last_result,
                                (SELECT n.next_j FROM life_notes n WHERE n.policy_id = p.id AND n.kind = 'call' ORDER BY n.at DESC, n.id DESC LIMIT 1) AS next_follow,
                                (SELECT COUNT(*) FROM life_notes n WHERE n.policy_id = p.id AND n.kind <> 'sys') AS notes_count,
                                (SELECT u.full_name FROM life_notes n JOIN users u ON u.id = n.created_by WHERE n.policy_id = p.id AND n.kind = 'call' ORDER BY n.at DESC, n.id DESC LIMIT 1) AS last_call_by,
                                (SELECT GROUP_CONCAT(u.full_name ORDER BY lf.auto, lf.added_at SEPARATOR '، ') FROM life_followers lf JOIN users u ON u.id = lf.user_id WHERE lf.policy_id = p.id) AS followers,
                                a.first_amount, a.last_amount
                         $from ORDER BY $order$lim");
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll() as $r) $rows[] = life_list_row($r);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per,
            'sum' => ['amount' => $sAmount, 'paid' => $sPaid, 'rem' => $sRem, 'overdue' => $sOver, 'overdue_count' => $sOverN]];
}
function life_list_row(array $r) {
    $o = [];
    foreach (['id', 'policy_no', 'holder_name', 'holder_nid', 'holder_mobile', 'phones', 'insured_name', 'insured_nid', 'issue_j', 'start_j', 'end_j', 'pay_method', 'policy_status',
              'field_name', 'variant', 'agent', 'branch', 'contract_name', 'channel', 'pay_id', 'duration', 'policy_year', 'next_due', 'last_result', 'next_follow', 'note', 'last_call_by', 'followers'] as $k) $o[$k] = $r[$k] ?? null;
    foreach (['inst_count', 'max_inst', 'total_amount', 'total_paid', 'total_rem', 'overdue_count', 'overdue_amount', 'notes_count'] as $k) $o[$k] = intval($r[$k] ?? 0);
    $o['last_call'] = !empty($r['last_call']) ? life_g2j(substr($r['last_call'], 0, 10)) . ' ' . substr($r['last_call'], 11, 5) : null;
    $o['overdue_days'] = !empty($r['oldest_overdue_g']) ? max(0, intval((strtotime(date('Y-m-d')) - strtotime($r['oldest_overdue_g'])) / 86400)) : 0;
    $o['first_unpaid'] = intval($r['has_first'] ?? 0) > 0 && intval($r['first_paid'] ?? 0) === 0 && intval($r['total_paid'] ?? 0) === 0;
    // حق‌بیمه‌ی کلِ یک سال: سالِ جاری بر اساسِ آخرین قسط، سالِ اول بر اساسِ قسطِ اول (حق‌بیمه‌ی عمر هر سال افزایش دارد)
    $o['annual_premium'] = life_annual($r['last_amount'] ?? 0, $r['pay_method'] ?? '');
    $o['first_year_premium'] = life_annual($r['first_amount'] ?? 0, $r['pay_method'] ?? '');
    $o['annual_est'] = $o['annual_premium'];
    $o['per_year'] = life_per_year($r['pay_method'] ?? '');
    return $o;
}

// جزئیاتِ کاملِ یک بیمه‌نامه
function life_detail($pdo, $id) {
    $p = life_policy_row($pdo, $id);
    if (!$p) return null;
    $today = date('Y-m-d');
    $pay = $pdo->prepare("SELECT * FROM life_payments WHERE policy_id = ? ORDER BY paid_g, id");
    $pay->execute([$id]);
    $pays = [];
    foreach ($pay->fetchAll() as $x) {
        $pays[intval($x['installment_id'])][] = ['id' => intval($x['id']), 'amount' => intval($x['amount']), 'paid_j' => $x['paid_j'], 'method' => $x['method'], 'ref_no' => $x['ref_no'],
            'note' => $x['note'], 'file' => $x['file_path'] ? basename($x['file_path']) : null, 'has_file' => (bool)$x['file_path'], 'voided' => (bool)$x['voided_at'],
            'by' => life_user_name($pdo, $x['created_by']), 'at' => $x['created_at'] ? life_g2j(substr($x['created_at'], 0, 10)) . ' ' . substr($x['created_at'], 11, 5) : ''];
    }
    $st = $pdo->prepare("SELECT * FROM life_installments WHERE policy_id = ? ORDER BY inst_no");
    $st->execute([$id]);
    $insts = []; $sum = ['amount' => 0, 'paid' => 0, 'rem' => 0, 'overdue' => 0, 'overdue_count' => 0];
    foreach ($st->fetchAll() as $i) {
        $s = life_inst_status($i, $today);
        $eff = !empty($i['cleared']) ? intval($i['amount']) : min(intval($i['amount']), max(intval($i['excel_collected']), intval($i['paid_amount'])));
        $rem = intval($i['amount']) - $eff;
        $files = [];
        $dir = life_inst_dir($p, $i);
        if (is_dir($dir)) foreach (scandir($dir) as $fn) if ($fn[0] !== '.' && is_file($dir . '/' . $fn)) $files[] = ['name' => $fn, 'size' => filesize($dir . '/' . $fn)];
        $insts[] = ['id' => intval($i['id']), 'inst_no' => intval($i['inst_no']), 'internal_no' => $i['internal_no'], 'pay_id' => $i['pay_id'], 'due_j' => $i['due_j'],
            'amount' => intval($i['amount']), 'excel_collected' => intval($i['excel_collected']), 'excel_debt' => $i['excel_debt'] === null ? null : intval($i['excel_debt']),
            'paid_amount' => intval($i['paid_amount']), 'eff_paid' => $eff, 'rem' => $rem, 'cleared' => intval($i['cleared']), 'cleared_note' => $i['cleared_note'],
            'status' => $s, 'status_fa' => LIFE_STATUS_FA[$s], 'note' => $i['note'], 'payments' => $pays[intval($i['id'])] ?? [], 'files' => $files,
            'days' => $i['due_g'] ? intval((strtotime($today) - strtotime($i['due_g'])) / 86400) : null];
        $sum['amount'] += intval($i['amount']); $sum['paid'] += $eff; $sum['rem'] += $rem;
        if ($s === 'OVERDUE' || ($s === 'PARTIAL' && $i['due_g'] < $today)) { $sum['overdue'] += $rem; $sum['overdue_count']++; }
    }
    $nt = $pdo->prepare("SELECT * FROM life_notes WHERE policy_id = ? ORDER BY at DESC, id DESC");
    $nt->execute([$id]);
    $notes = [];
    foreach ($nt->fetchAll() as $n) {
        $notes[] = ['id' => intval($n['id']), 'installment_id' => $n['installment_id'] ? intval($n['installment_id']) : null, 'kind' => $n['kind'],
            'at_j' => life_g2j(substr($n['at'], 0, 10)), 'at_t' => substr($n['at'], 11, 5), 'phone' => $n['phone'], 'result' => $n['result'], 'next_j' => $n['next_j'],
            'next_due' => $n['next_g'] && $n['next_g'] <= $today, 'text' => $n['text'], 'by' => life_user_name($pdo, $n['created_by']), 'by_id' => intval($n['created_by'])];
    }
    $out = [];
    foreach ($p as $k => $v) if (!in_array($k, ['extra_json', 'archive_dir'], true)) $out[$k] = $v;
    $out['id'] = intval($p['id']);
    $out['extra'] = json_decode((string)$p['extra_json'], true) ?: (object)[];
    $out['phones_list'] = life_phone_list($p);
    $out['archive'] = $p['archive_dir'];
    $out['per_year'] = life_per_year($p['pay_method']);
    $out['annual_premium'] = $insts ? life_annual(end($insts)['amount'], $p['pay_method']) : 0;
    $out['first_year_premium'] = $insts ? life_annual($insts[0]['amount'], $p['pay_method']) : 0;
    $out['annual_est'] = $out['annual_premium'];
    $out['followers'] = life_followers($pdo, $id);
    $out['total_all'] = array_sum(array_column($insts, 'amount'));
    $out['first_unpaid'] = false;
    foreach ($insts as $i) if ($i['inst_no'] === 1 && $i['eff_paid'] === 0) $out['first_unpaid'] = $sum['paid'] === 0;
    return ['policy' => $out, 'insts' => $insts, 'notes' => $notes, 'sum' => $sum];
}
// همه‌ی شماره‌های یک بیمه‌نامه: موبایلِ اصلی + شماره‌های ثبت‌شده (برای کپی و تماس)
function life_phone_list(array $p) {
    $o = [];
    if (!empty($p['holder_mobile'])) $o[] = ['phone' => $p['holder_mobile'], 'label' => 'موبایل بیمه‌گذار'];
    foreach (preg_split('/[\n,،]+/u', (string)($p['phones'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode('|', $line, 2);
        $ph = life_mobile($parts[0]) ?: trim($parts[0]);
        if ($ph === '' || in_array($ph, array_column($o, 'phone'), true)) continue;
        $o[] = ['phone' => $ph, 'label' => trim($parts[1] ?? '')];
    }
    return $o;
}

// ---------------------------------------------------------------------
//  داشبورد
// ---------------------------------------------------------------------
function life_stats($pdo, array $f) {
    $today = date('Y-m-d');
    $params = [':today1' => $today, ':today2' => $today, ':today3' => $today];
    $w = life_filter_sql(array_intersect_key($f, array_flip(['pay_method', 'policy_status', 'issue_from', 'issue_to', 'q'])), $params);
    $pw = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $from = "FROM life_policies p LEFT JOIN (" . life_agg_sql() . ") a ON a.policy_id = p.id" . $pw;
    $st = $pdo->prepare("SELECT COUNT(*) AS n, SUM(CASE WHEN COALESCE(a.overdue_count, 0) > 0 THEN 1 ELSE 0 END) AS n_over,
                                SUM(CASE WHEN a.has_first > 0 AND COALESCE(a.first_paid, 0) = 0 AND COALESCE(a.total_paid, 0) = 0 THEN 1 ELSE 0 END) AS n_first,
                                SUM(CASE WHEN COALESCE(a.total_rem, 0) = 0 AND COALESCE(a.inst_count, 0) > 0 THEN 1 ELSE 0 END) AS n_clean,
                                SUM(CASE WHEN COALESCE(p.holder_mobile, '') = '' AND COALESCE(p.phones, '') = '' THEN 1 ELSE 0 END) AS n_nophone $from");
    $st->execute($params);
    $pc = $st->fetch();
    // اقساط (با فیلترِ بیمه‌نامه و بازه‌ی سررسید)
    $ip = [];
    $iw = [];
    $mon = function ($v) { $v = p2e_digits((string)$v); return preg_match('/^(1[34]\d{2})\/(\d{1,2})$/', $v, $m) ? sprintf('%04d/%02d', $m[1], $m[2]) : ''; };
    if (($a = $mon($f['due_from'] ?? '')) !== '') { $iw[] = "i.due_j >= :df"; $ip[':df'] = $a . '/01'; }
    if (($a = $mon($f['due_to'] ?? '')) !== '') { $iw[] = "i.due_j <= :dt"; $ip[':dt'] = $a . '/31'; }
    $polIds = "SELECT p.id $from";
    $paid = life_sql_paid('i'); $rem = life_sql_rem('i');
    $iwhere = " WHERE i.policy_id IN ($polIds)" . ($iw ? ' AND ' . implode(' AND ', $iw) : '');
    $allP = $params + $ip;
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(i.amount), 0) AS amount, COALESCE(SUM($paid), 0) AS paid, COALESCE(SUM($rem), 0) AS rem,
                                SUM(CASE WHEN $rem > 0 AND i.due_g < :t4 THEN 1 ELSE 0 END) AS n_over, COALESCE(SUM(CASE WHEN $rem > 0 AND i.due_g < :t5 THEN $rem ELSE 0 END), 0) AS over_amount,
                                SUM(CASE WHEN $rem = 0 THEN 1 ELSE 0 END) AS n_paid, SUM(CASE WHEN $rem > 0 AND $paid > 0 THEN 1 ELSE 0 END) AS n_partial,
                                SUM(CASE WHEN $rem > 0 AND i.due_g >= :t6 THEN 1 ELSE 0 END) AS n_due, COALESCE(SUM(CASE WHEN $rem > 0 AND i.due_g >= :t7 THEN $rem ELSE 0 END), 0) AS due_amount,
                                SUM(CASE WHEN $rem > 0 AND i.due_g >= :t8 AND i.due_g <= :t9 THEN 1 ELSE 0 END) AS n_soon,
                                COALESCE(SUM(CASE WHEN $rem > 0 AND i.due_g >= :t10 AND i.due_g <= :t11 THEN $rem ELSE 0 END), 0) AS soon_amount
                           FROM life_installments i $iwhere");
    $soon = date('Y-m-d', strtotime('+30 days'));
    $st->execute($allP + [':t4' => $today, ':t5' => $today, ':t6' => $today, ':t7' => $today, ':t8' => $today, ':t9' => $soon, ':t10' => $today, ':t11' => $soon]);
    $ic = $st->fetch();
    // ماه به ماه بر اساسِ سررسید: مبلغِ سررسید و وصول‌شده
    $st = $pdo->prepare("SELECT SUBSTRING(i.due_j, 1, 7) AS m, SUM(i.amount) AS amount, SUM($paid) AS paid, COUNT(*) AS n,
                                SUM(CASE WHEN $rem > 0 AND i.due_g < :t4 THEN $rem ELSE 0 END) AS over_amount
                           FROM life_installments i $iwhere GROUP BY m ORDER BY m");
    $st->execute($allP + [':t4' => $today]);
    $byDue = array_map(function ($r) { return ['m' => $r['m'], 'amount' => intval($r['amount']), 'paid' => intval($r['paid']), 'n' => intval($r['n']), 'over' => intval($r['over_amount'])]; }, $st->fetchAll());
    // فروش: صدور ماه به ماه (تعداد و برآوردِ حق‌بیمه‌ی سالانه)
    $st = $pdo->prepare("SELECT p.issue_j, p.pay_method, COALESCE(a.first_amount, 0) AS min_amount $from");
    $st->execute($params);
    $sales = []; $salesTotal = 0; $methods = [];
    foreach ($st->fetchAll() as $r) {
        $m = $r['issue_j'] ? substr($r['issue_j'], 0, 7) : '—';
        $est = intval($r['min_amount']) * life_per_year($r['pay_method']);
        if (!isset($sales[$m])) $sales[$m] = ['m' => $m, 'n' => 0, 'premium' => 0];
        $sales[$m]['n']++; $sales[$m]['premium'] += $est; $salesTotal += $est;
        $pm = $r['pay_method'] ?: 'نامشخص';
        if (!isset($methods[$pm])) $methods[$pm] = ['label' => $pm, 'n' => 0, 'premium' => 0];
        $methods[$pm]['n']++; $methods[$pm]['premium'] += $est;
    }
    ksort($sales);
    unset($sales['—']);
    usort($methods, function ($a, $b) { return $b['n'] <=> $a['n']; });
    // سنِ معوقات (روز از سررسید)
    $st = $pdo->prepare("SELECT DATEDIFF(:t4, i.due_g) AS d, $rem AS r FROM life_installments i $iwhere AND $rem > 0 AND i.due_g < :t5");
    $st->execute($allP + [':t4' => $today, ':t5' => $today]);
    $aging = [['label' => '۱ تا ۳۰ روز', 'n' => 0, 'amount' => 0], ['label' => '۳۱ تا ۶۰ روز', 'n' => 0, 'amount' => 0], ['label' => '۶۱ تا ۹۰ روز', 'n' => 0, 'amount' => 0], ['label' => 'بیش از ۹۰ روز', 'n' => 0, 'amount' => 0]];
    foreach ($st->fetchAll() as $r) { $d = intval($r['d']); $k = $d <= 30 ? 0 : ($d <= 60 ? 1 : ($d <= 90 ? 2 : 3)); $aging[$k]['n']++; $aging[$k]['amount'] += intval($r['r']); }
    // بیشترین بدهی‌ها و پیگیری‌های امروز
    $top = life_list($pdo, array_merge(array_intersect_key($f, array_flip(['pay_method', 'policy_status', 'issue_from', 'issue_to'])), ['status' => 'overdue', 'sort' => 'overdue']), 1, 8)['rows'];
    $fu = life_list($pdo, array_merge(array_intersect_key($f, array_flip(['pay_method', 'policy_status', 'issue_from', 'issue_to'])), ['followup' => 1, 'sort' => 'next_due']), 1, 8);
    // تماس‌های این ماه
    [$jy, $jm] = jalali_from_gregorian_ts(time());
    $mStart = date('Y-m-d', jalali_to_gregorian_ts($jy, $jm, 1));
    $calls = $pdo->prepare("SELECT COUNT(*) FROM life_notes WHERE kind = 'call' AND at >= ?");
    $calls->execute([$mStart]);
    $methodsList = $pdo->query("SELECT DISTINCT pay_method FROM life_policies WHERE pay_method IS NOT NULL AND pay_method <> '' ORDER BY pay_method")->fetchAll(PDO::FETCH_COLUMN);
    $statusList = $pdo->query("SELECT DISTINCT policy_status FROM life_policies WHERE policy_status IS NOT NULL AND policy_status <> '' ORDER BY policy_status")->fetchAll(PDO::FETCH_COLUMN);
    $last = $pdo->query("SELECT id, file_name, created_at, range_from, range_to FROM life_imports ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    if ($last) $last['at_j'] = life_g2j(substr($last['created_at'], 0, 10)) . ' ' . substr($last['created_at'], 11, 5);
    $amount = intval($ic['amount']);
    return [
        'policies' => ['n' => intval($pc['n']), 'overdue' => intval($pc['n_over']), 'first_unpaid' => intval($pc['n_first']), 'clean' => intval($pc['n_clean']), 'no_phone' => intval($pc['n_nophone'])],
        'insts' => ['n' => intval($ic['n']), 'amount' => $amount, 'paid' => intval($ic['paid']), 'rem' => intval($ic['rem']), 'n_over' => intval($ic['n_over']), 'over_amount' => intval($ic['over_amount']),
                    'n_paid' => intval($ic['n_paid']), 'n_partial' => intval($ic['n_partial']), 'n_due' => intval($ic['n_due']), 'due_amount' => intval($ic['due_amount']),
                    'n_soon' => intval($ic['n_soon']), 'soon_amount' => intval($ic['soon_amount']), 'rate' => $amount ? round(intval($ic['paid']) * 100 / $amount, 1) : 0],
        'by_due' => $byDue, 'sales' => array_values($sales), 'sales_total' => $salesTotal, 'methods' => array_values($methods), 'aging' => $aging,
        'top' => $top, 'followups' => $fu['rows'], 'followups_total' => $fu['total'], 'calls_month' => intval($calls->fetchColumn()),
        'lists' => ['pay_methods' => $methodsList, 'policy_status' => $statusList], 'last_import' => $last, 'today_j' => life_today_j(),
    ];
}

// ---------------------------------------------------------------------
//  خروجیِ اکسل با ستون‌های انتخابی
// ---------------------------------------------------------------------
function life_export_columns() {
    // کلید => [عنوان، سطح (p = بیمه‌نامه، i = قسط)، عددی؟]
    return [
        'policy_no' => ['شماره بیمه‌نامه', 'p', false], 'holder_name' => ['بیمه‌گذار', 'p', false], 'holder_nid' => ['کد ملی بیمه‌گذار', 'p', false],
        'holder_mobile' => ['موبایل', 'p', false], 'phones' => ['شماره‌های دیگر', 'p', false], 'insured_name' => ['بیمه‌شده‌ی اصلی', 'p', false], 'insured_nid' => ['کد ملی بیمه‌شده', 'p', false],
        'issue_j' => ['تاریخ صدور', 'p', false], 'start_j' => ['تاریخ شروع', 'p', false], 'end_j' => ['تاریخ پایان', 'p', false], 'duration' => ['مدت (سال)', 'p', true],
        'policy_year' => ['سال بیمه‌ای', 'p', true], 'pay_method' => ['روش پرداخت', 'p', false], 'policy_status' => ['وضعیت بیمه‌نامه', 'p', false], 'field_name' => ['رشته', 'p', false],
        'variant' => ['طرح', 'p', false], 'contract_name' => ['قرارداد', 'p', false], 'agent' => ['نماینده', 'p', false], 'branch' => ['شعبه', 'p', false], 'channel' => ['کانال فروش', 'p', false],
        'pay_id' => ['شناسه واریز', 'p', false], 'annual_premium' => ['حق‌بیمه‌ی کلِ یک سال (سالِ جاری)', 'p', true], 'first_year_premium' => ['حق‌بیمه‌ی سالِ اول', 'p', true],
        'per_year' => ['تعداد قسط در سال', 'p', true], 'inst_count' => ['تعداد اقساط ثبت‌شده', 'p', true],
        'total_amount' => ['جمع مبلغ اقساط', 'p', true], 'total_paid' => ['جمع پرداختی', 'p', true], 'total_rem' => ['جمع مانده', 'p', true],
        'overdue_count' => ['تعداد اقساط معوق', 'p', true], 'overdue_amount' => ['مبلغ معوق', 'p', true], 'next_due' => ['نزدیک‌ترین سررسیدِ پرداخت‌نشده', 'p', false],
        'followers' => ['پیگیری‌کنندگان', 'p', false], 'last_call' => ['آخرین تماس', 'p', false], 'last_call_by' => ['آخرین تماس توسط', 'p', false], 'last_result' => ['نتیجه‌ی آخرین تماس', 'p', false], 'next_follow' => ['پیگیری بعدی', 'p', false], 'note' => ['یادداشت بیمه‌نامه', 'p', false],
        'inst_no' => ['شماره قسط', 'i', true], 'internal_no' => ['شماره داخلی قسط', 'i', false], 'due_j' => ['تاریخ سررسید', 'i', false], 'amount' => ['مبلغ قسط', 'i', true],
        'excel_collected' => ['وصول طبق اکسل بیمه‌گر', 'i', true], 'paid_amount' => ['پرداخت‌های ثبت‌شده', 'i', true], 'eff_paid' => ['پرداختی قسط', 'i', true], 'rem' => ['مانده قسط', 'i', true],
        'status_fa' => ['وضعیت قسط', 'i', false], 'days' => ['روز از سررسید', 'i', true], 'pay_dates' => ['تاریخ‌های پرداخت', 'i', false], 'pay_refs' => ['شماره‌های پیگیری', 'i', false], 'pay_by' => ['ثبت پرداخت توسط', 'i', false],
        'cleared_note' => ['توضیح تسویه', 'i', false],
    ];
}
function life_export($pdo, array $f, $mode, array $cols) {
    $cat = life_export_columns();
    $cols = array_values(array_filter($cols, function ($c) use ($cat, $mode) { return isset($cat[$c]) && ($mode === 'inst' || $cat[$c][1] === 'p'); }));
    if (!$cols) $cols = $mode === 'inst' ? ['policy_no', 'holder_name', 'holder_nid', 'holder_mobile', 'inst_no', 'due_j', 'amount', 'eff_paid', 'rem', 'status_fa']
                                        : ['policy_no', 'holder_name', 'holder_nid', 'holder_mobile', 'issue_j', 'pay_method', 'total_amount', 'total_paid', 'total_rem', 'overdue_count', 'overdue_amount'];
    $list = life_list($pdo, $f, 1, 500, true)['rows'];
    $headers = array_map(function ($c) use ($cat) { return $cat[$c][0]; }, $cols);
    $numeric = [];
    foreach ($cols as $k => $c) if ($cat[$c][2]) $numeric[] = $k;
    $rows = [];
    if ($mode !== 'inst') {
        foreach ($list as $p) $rows[] = array_map(function ($c) use ($p) { return $p[$c] ?? ''; }, $cols);
        return [$headers, $rows, $numeric];
    }
    if (!$list) return [$headers, [], $numeric];
    $byId = array_column($list, null, 'id');
    $today = date('Y-m-d');
    $mon = function ($v) { $v = p2e_digits((string)$v); return preg_match('/^(1[34]\d{2})\/(\d{1,2})$/', $v, $m) ? sprintf('%04d/%02d', $m[1], $m[2]) : ''; };
    $iw = ["i.policy_id IN (" . implode(',', array_map('intval', array_keys($byId))) . ")"]; $ip = [];
    if (($a = $mon($f['due_from'] ?? '')) !== '') { $iw[] = "i.due_j >= ?"; $ip[] = $a . '/01'; }
    if (($a = $mon($f['due_to'] ?? '')) !== '') { $iw[] = "i.due_j <= ?"; $ip[] = $a . '/31'; }
    $is = $f['inst_status'] ?? (in_array($f['status'] ?? '', ['unpaid', 'overdue'], true) ? $f['status'] : '');
    if ($is === 'unpaid') $iw[] = life_sql_rem('i') . " > 0";
    if ($is === 'overdue') { $iw[] = life_sql_rem('i') . " > 0 AND i.due_g < ?"; $ip[] = $today; }
    if ($is === 'paid') $iw[] = life_sql_rem('i') . " = 0";
    $st = $pdo->prepare("SELECT i.* FROM life_installments i WHERE " . implode(' AND ', $iw) . " ORDER BY i.policy_id, i.inst_no");
    $st->execute($ip);
    $insts = $st->fetchAll();
    $pays = [];
    if ($insts) {
        $ids = implode(',', array_map(function ($i) { return intval($i['id']); }, $insts));
        foreach ($pdo->query("SELECT p.installment_id, p.paid_j, p.ref_no, u.full_name AS by_name FROM life_payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.voided_at IS NULL AND p.installment_id IN ($ids) ORDER BY p.paid_g, p.id") as $x) $pays[intval($x['installment_id'])][] = $x;
    }
    // ترتیبِ بیمه‌نامه‌ها همان ترتیبِ فهرست
    $order = array_flip(array_keys($byId));
    usort($insts, function ($a, $b) use ($order) { return ($order[$a['policy_id']] ?? 0) <=> ($order[$b['policy_id']] ?? 0) ?: $a['inst_no'] <=> $b['inst_no']; });
    foreach ($insts as $i) {
        $p = $byId[intval($i['policy_id'])];
        $s = life_inst_status($i, $today);
        $eff = !empty($i['cleared']) ? intval($i['amount']) : min(intval($i['amount']), max(intval($i['excel_collected']), intval($i['paid_amount'])));
        $px = $pays[intval($i['id'])] ?? [];
        $v = $p + ['inst_no' => intval($i['inst_no']), 'internal_no' => $i['internal_no'], 'due_j' => $i['due_j'], 'amount' => intval($i['amount']), 'excel_collected' => intval($i['excel_collected']),
                   'paid_amount' => intval($i['paid_amount']), 'eff_paid' => $eff, 'rem' => intval($i['amount']) - $eff, 'status_fa' => LIFE_STATUS_FA[$s],
                   'days' => $i['due_g'] ? intval((strtotime($today) - strtotime($i['due_g'])) / 86400) : '', 'pay_dates' => implode('، ', array_column($px, 'paid_j')),
                   'pay_refs' => implode('، ', array_filter(array_column($px, 'ref_no'))), 'pay_by' => implode('، ', array_unique(array_filter(array_column($px, 'by_name')))), 'cleared_note' => $i['cleared_note']];
        if ($i['pay_id']) $v['pay_id'] = $i['pay_id'];
        $rows[] = array_map(function ($c) use ($v) { return $v[$c] ?? ''; }, $cols);
    }
    return [$headers, $rows, $numeric];
}

// ---------------------------------------------------------------------
//  «اعتبارسنجی»: بیمه‌نامه‌هایی که قسطِ ۱ دارند و هیچ پرداختی ندارند
// ---------------------------------------------------------------------
function life_purge_candidates($pdo) {
    $r = life_list($pdo, ['status' => 'first_unpaid', 'sort' => 'issue'], 1, 500, true)['rows'];
    // هیچ پرداختِ ثبت‌شده (حتی باطل‌نشده) نداشته باشد
    $out = [];
    $chk = $pdo->prepare("SELECT COUNT(*) FROM life_payments WHERE policy_id = ? AND voided_at IS NULL");
    foreach ($r as $p) { $chk->execute([$p['id']]); if (!intval($chk->fetchColumn())) $out[] = $p; }
    return $out;
}
function life_delete_policy($pdo, $id, $withArchive = true) {
    $p = life_policy_row($pdo, $id);
    if (!$p) return false;
    $pdo->prepare("DELETE FROM life_payments WHERE policy_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM life_notes WHERE policy_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM life_installments WHERE policy_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM life_policies WHERE id = ?")->execute([$id]);
    if ($withArchive) {
        $dir = life_policy_dir($p);
        if (strpos(realpath($dir) ?: '', realpath(life_archive_root()) ?: '//') === 0) life_rrmdir($dir);
        // پوشه‌های خالیِ ماه و سال
        $up = dirname($dir);
        for ($k = 0; $k < 2; $k++) { if (is_dir($up) && count(scandir($up)) <= 2) @rmdir($up); $up = dirname($up); }
    }
    return true;
}

// ---------------------------------------------------------------------
//  وصولِ ماه به ماه به تفکیکِ همکار (پرداخت‌های ثبت‌شده بر اساسِ تاریخِ پرداخت + تعدادِ تماس‌ها)
// ---------------------------------------------------------------------
function life_collect_report($pdo, $jy) {
    $jy = intval($jy);
    $users = [];
    $row = function ($uid, $name) use (&$users) {
        if (!isset($users[$uid])) $users[$uid] = ['id' => $uid, 'name' => $name ?: 'نامشخص', 'months' => array_fill(1, 12, ['amount' => 0, 'n' => 0, 'calls' => 0, 'policies' => 0]), 'total' => 0, 'n' => 0, 'calls' => 0];
    };
    $st = $pdo->prepare("SELECT p.created_by AS uid, u.full_name, CAST(SUBSTRING(p.paid_j, 6, 2) AS UNSIGNED) AS m, SUM(p.amount) AS amount, COUNT(*) AS n, COUNT(DISTINCT p.policy_id) AS pol
                           FROM life_payments p LEFT JOIN users u ON u.id = p.created_by
                          WHERE p.voided_at IS NULL AND p.paid_j LIKE ? GROUP BY p.created_by, u.full_name, m");
    $st->execute([$jy . '/%']);
    foreach ($st->fetchAll() as $r) {
        $uid = intval($r['uid']); $m = intval($r['m']);
        if ($m < 1 || $m > 12) continue;
        $row($uid, $r['full_name']);
        $users[$uid]['months'][$m]['amount'] = intval($r['amount']); $users[$uid]['months'][$m]['n'] = intval($r['n']); $users[$uid]['months'][$m]['policies'] = intval($r['pol']);
        $users[$uid]['total'] += intval($r['amount']); $users[$uid]['n'] += intval($r['n']);
    }
    // تماس‌ها (بر اساسِ ماهِ شمسیِ زمانِ تماس)
    $from = date('Y-m-d', jalali_to_gregorian_ts($jy, 1, 1)); $to = date('Y-m-d', jalali_to_gregorian_ts($jy + 1, 1, 1));
    $st = $pdo->prepare("SELECT n.created_by AS uid, u.full_name, n.at FROM life_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.kind = 'call' AND n.at >= ? AND n.at < ? AND n.created_by IS NOT NULL");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        [, $m] = jalali_from_gregorian_ts(strtotime($r['at']));
        $uid = intval($r['uid']);
        $row($uid, $r['full_name']);
        $users[$uid]['months'][$m]['calls']++; $users[$uid]['calls']++;
    }
    // بیمه‌نامه‌هایی که پیگیری می‌کنند و معوقِ فعلی‌شان
    foreach ($users as $uid => &$u) {
        if (!$uid) continue;
        $l = life_list($pdo, ['follower' => $uid, 'status' => 'overdue'], 1, 5);
        $u['following'] = intval($pdo->query("SELECT COUNT(*) FROM life_followers WHERE user_id = " . intval($uid))->fetchColumn());
        $u['overdue_now'] = $l['sum']['overdue']; $u['overdue_policies'] = $l['total'];
    }
    unset($u);
    $list = array_values($users);
    usort($list, function ($a, $b) { return $b['total'] <=> $a['total']; });
    $tot = array_fill(1, 12, 0);
    foreach ($list as $u) foreach ($u['months'] as $m => $x) $tot[$m] += $x['amount'];
    return ['jy' => $jy, 'users' => $list, 'month_totals' => $tot, 'total' => array_sum($tot)];
}
