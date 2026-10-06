<?php
// فایل: api/_life_import.php
// ورودِ اکسلِ «گزارشِ اقساطِ وصول‌نشده»: پیش‌نمایش (تطبیقِ بیمه‌نامه با شماره و کد ملی، تطبیقِ اقساط با شماره‌ی قسط،
// شماره‌ی داخلی و شناسه‌ی واریز، مغایرت‌ها، «حذفِ قسط‌اولی‌ها»، اقساطی که دیگر در گزارش نیستند) و ثبتِ نهایی.

// فیلدهای سطحِ بیمه‌نامه که از فایل خوانده می‌شوند
const LIFE_POLICY_FIELDS = [
    'holder_name' => 'بیمه‌گذار', 'holder_nid' => 'کد ملی', 'holder_mobile' => 'موبایل', 'insured_name' => 'بیمه‌شده', 'insured_nid' => 'کد ملی بیمه‌شده',
    'agent' => 'نماینده', 'branch' => 'شعبه', 'contract_name' => 'قرارداد', 'variant' => 'طرح', 'field_name' => 'رشته', 'issue_j' => 'تاریخ صدور',
    'start_j' => 'شروع', 'end_j' => 'پایان', 'duration' => 'مدت', 'policy_year' => 'سال بیمه‌ای', 'pay_method' => 'روش پرداخت',
    'policy_status' => 'وضعیت بیمه‌نامه', 'channel' => 'کانال فروش', 'pay_id' => 'شناسه واریز',
];
// فیلدهای قسط که اختلافشان «مغایرت» است (وصول/بدهی فقط به‌روزرسانی است)
const LIFE_INST_FIELDS = ['amount' => 'مبلغ قسط', 'due_j' => 'سررسید', 'internal_no' => 'شماره داخلی', 'pay_id' => 'شناسه واریز'];

function life_tmp_dir() { $d = life_root() . '/tmp_ocr/life'; life_mkdir($d); return $d; }
function life_tmp_path($token) { return life_tmp_dir() . '/import_' . preg_replace('/[^a-f0-9]/', '', (string)$token) . '.json'; }
function life_tmp_gc() {
    foreach ((array)glob(life_tmp_dir() . '/import_*.json') as $f) if (@filemtime($f) < time() - 86400) @unlink($f);
}
function life_pno_key($pno) { return preg_replace('/\s+/', '', p2e_digits((string)$pno)); }

// پیش‌نمایش: هیچ چیزی در دیتابیس عوض نمی‌شود
function life_import_preview($pdo, $path, $fileName, $dropFirst) {
    $parsed = life_parse_file($pdo, $path);
    if (isset($parsed['error'])) return $parsed;
    [$from, $to] = $parsed['range'];

    // گروه‌بندی بر اساسِ شماره‌ی بیمه‌نامه
    $groups = [];
    foreach ($parsed['rows'] as $r) {
        $k = life_pno_key($r['policy_no']);
        if (!isset($groups[$k])) $groups[$k] = ['rows' => []];
        $groups[$k]['rows'][] = $r;
    }
    // بیمه‌نامه‌ها و اقساطِ موجود
    $keys = array_keys($groups);
    $existing = [];
    if ($keys) {
        foreach (array_chunk($keys, 400) as $chunk) {
            $st = $pdo->prepare("SELECT * FROM life_policies WHERE REPLACE(policy_no, ' ', '') IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
            $st->execute($chunk);
            foreach ($st->fetchAll() as $p) $existing[life_pno_key($p['policy_no'])] = $p;
        }
    }
    $insts = []; $polPaid = [];
    if ($existing) {
        $ids = array_map(function ($p) { return intval($p['id']); }, $existing);
        $st = $pdo->query("SELECT i.*, " . life_sql_paid('i') . " AS eff_paid FROM life_installments i WHERE i.policy_id IN (" . implode(',', $ids) . ")");
        foreach ($st->fetchAll() as $i) {
            $insts[intval($i['policy_id'])][intval($i['inst_no'])] = $i;
            $polPaid[intval($i['policy_id'])] = ($polPaid[intval($i['policy_id'])] ?? 0) + max(intval($i['excel_collected']), intval($i['paid_amount']));
        }
    }
    // شماره‌ی داخلیِ قسط => قسطِ موجود (برای پیدا کردنِ قسطی که شماره یا بیمه‌نامه‌اش فرق کرده)
    $byInternal = [];
    $internals = [];
    foreach ($parsed['rows'] as $r) if ($r['internal_no'] !== '') $internals[] = $r['internal_no'];
    foreach (array_chunk(array_values(array_unique($internals)), 500) as $chunk) {
        $st = $pdo->prepare("SELECT i.id, i.policy_id, i.inst_no, i.internal_no, p.policy_no FROM life_installments i JOIN life_policies p ON p.id = i.policy_id WHERE i.internal_no IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $x) $byInternal[$x['internal_no']] = $x;
    }

    $out = []; $sum = ['policies' => 0, 'new_policies' => 0, 'existing' => 0, 'inst_new' => 0, 'inst_same' => 0, 'inst_update' => 0, 'inst_conflict' => 0, 'dropped' => 0, 'dropped_insts' => 0, 'policy_conflicts' => 0];
    foreach ($groups as $k => $g) {
        $rows = $g['rows'];
        usort($rows, function ($a, $b) { return $a['inst_no'] <=> $b['inst_no'] ?: $a['line'] <=> $b['line']; });
        $f = $rows[0];
        $ex = $existing[$k] ?? null;
        $nids = array_values(array_unique(array_filter(array_column($rows, 'holder_nid'), 'strlen')));
        $pol = ['key' => $k, 'policy_no' => $f['policy_no'], 'existing_id' => $ex ? intval($ex['id']) : null, 'issues' => [], 'nid_conflict' => false,
                'dropped' => false, 'drop_reason' => '', 'phone' => ($ex['holder_mobile'] ?? '') ?: $f['holder_mobile'], 'phones_db' => $ex['phones'] ?? ''];   // شماره‌ای که کاربر ثبت کرده مقدم است
        foreach (LIFE_POLICY_FIELDS as $fk => $fa) $pol[$fk] = $f[$fk] ?? null;
        $pol['insured_name'] = $f['insured_name'];
        if (count($nids) > 1) { $pol['nid_conflict'] = true; $pol['issues'][] = ['field' => 'holder_nid', 'label' => 'کد ملی', 'db' => '', 'file' => implode(' / ', $nids), 'note' => 'در فایل برای این بیمه‌نامه چند کد ملیِ مختلف آمده است.']; }
        if ($ex) {
            if ($ex['holder_nid'] && $f['holder_nid'] && $ex['holder_nid'] !== $f['holder_nid']) { $pol['nid_conflict'] = true; $pol['issues'][] = ['field' => 'holder_nid', 'label' => 'کد ملی', 'db' => $ex['holder_nid'], 'file' => $f['holder_nid'], 'note' => 'کد ملیِ بیمه‌گذار با اطلاعاتِ ثبت‌شده فرق دارد.']; }
            foreach (LIFE_POLICY_FIELDS as $fk => $fa) {
                if ($fk === 'holder_nid' || $fk === 'holder_mobile') continue;
                $a = (string)($ex[$fk] ?? ''); $b = (string)($f[$fk] ?? '');
                if ($b !== '' && $a !== '' && $a !== $b) $pol['issues'][] = ['field' => $fk, 'label' => $fa, 'db' => $a, 'file' => $b];
            }
        }
        // «حذفِ قسط‌اولی‌ها»: قسطِ ۱ در گزارش هست (یعنی پرداخت نشده) و هیچ وصولی در هیچ‌کدام از ردیف‌ها (و نزدِ ما) ندارد
        $hasFirst = false; $collected = 0;
        foreach ($rows as $r) { if ($r['inst_no'] === 1) $hasFirst = true; $collected += $r['collected']; }
        if ($ex) $collected += $polPaid[intval($ex['id'])] ?? 0;
        $pol['first_unpaid'] = $hasFirst && $collected === 0;
        // بیمه‌نامه‌ای که قبلاً ثبت شده کنار گذاشته نمی‌شود (برای پاک کردنش «اعتبارسنجی و پاک‌سازی» هست)
        if ($dropFirst && $pol['first_unpaid'] && !$pol['nid_conflict'] && !$ex) {
            $pol['dropped'] = true;
            $pol['drop_reason'] = 'قسطِ اول پرداخت نشده و هیچ‌یک از اقساط وصول نشده‌اند' . ($nids ? ' (کد ملی ' . $nids[0] . ')' : '');
        }
        // اقساط
        $seen = [];
        $pol['insts'] = [];
        foreach ($rows as $r) {
            $n = $r['inst_no'];
            $row = ['inst_no' => $n, 'line' => $r['line'], 'internal_no' => $r['internal_no'], 'pay_id' => $r['pay_id'], 'due_j' => $r['due_j'], 'amount' => $r['amount'],
                    'collected' => $r['collected'], 'debt' => $r['debt'], 'status' => 'new', 'diffs' => [], 'db' => null, 'note' => ''];
            if ($n <= 0 || !$r['due_j'] || $r['amount'] <= 0) { $row['status'] = 'conflict'; $row['note'] = 'شماره‌ی قسط، سررسید یا مبلغ نامعتبر است.'; }
            if (isset($seen[$n])) { $row['status'] = 'conflict'; $row['note'] = 'این شماره‌ی قسط در فایل تکراری است (ردیف ' . $seen[$n] . ').'; }
            $seen[$n] = $r['line'];
            $di = $ex ? ($insts[intval($ex['id'])][$n] ?? null) : null;
            if ($di) {
                $row['db'] = ['id' => intval($di['id']), 'amount' => intval($di['amount']), 'due_j' => $di['due_j'], 'internal_no' => $di['internal_no'], 'pay_id' => $di['pay_id'],
                              'collected' => intval($di['excel_collected']), 'paid' => intval($di['paid_amount']), 'cleared' => intval($di['cleared'])];
                foreach (LIFE_INST_FIELDS as $fk => $fa) {
                    $a = (string)($di[$fk] ?? ''); $b = (string)($r[$fk] ?? '');
                    if ($b !== '' && $a !== '' && $a !== $b) $row['diffs'][] = ['field' => $fk, 'label' => $fa, 'db' => $a, 'file' => $b];
                }
                if (intval($di['cleared']) === 1) $row['diffs'][] = ['field' => 'cleared', 'label' => 'تسویه', 'db' => 'تسویه‌شده', 'file' => 'در گزارشِ وصول‌نشده‌ها آمده'];
                if ($row['status'] !== 'conflict') {
                    if ($row['diffs']) $row['status'] = 'conflict';
                    elseif (intval($di['excel_collected']) !== $r['collected'] || ($r['debt'] !== null && (string)$di['excel_debt'] !== (string)$r['debt'])) $row['status'] = 'update';
                    else $row['status'] = 'same';
                }
            } elseif ($r['internal_no'] !== '' && isset($byInternal[$r['internal_no']])) {
                $x = $byInternal[$r['internal_no']];
                $row['status'] = 'conflict';
                $row['note'] = 'شماره‌ی داخلیِ این قسط قبلاً برای قسطِ ' . $x['inst_no'] . ' بیمه‌نامه‌ی ' . $x['policy_no'] . ' ثبت شده است.';
            }
            if ($row['status'] === 'new' && $ex && $ex['pay_id'] && $r['pay_id'] && $ex['pay_id'] !== $r['pay_id']) {
                $row['status'] = 'conflict'; $row['note'] = 'شناسه‌ی واریزِ این قسط با شناسه‌ی بیمه‌نامه (' . $ex['pay_id'] . ') یکی نیست.';
            }
            $pol['insts'][] = $row;
            if ($pol['dropped']) continue;
            $sum['inst_' . $row['status']]++;
        }
        $sum['policies']++;
        if ($pol['dropped']) { $sum['dropped']++; $sum['dropped_insts'] += count($rows); }
        elseif ($ex) $sum['existing']++; else $sum['new_policies']++;
        if ($pol['nid_conflict']) $sum['policy_conflicts']++;
        $out[] = $pol;
    }
    // اقساطی که در بازه‌ی گزارش بوده‌اند ولی دیگر در فهرستِ وصول‌نشده‌ها نیستند => احتمالاً پرداخت شده‌اند
    $missing = [];
    if ($from && $to) {
        $inFile = [];
        foreach ($parsed['rows'] as $r) $inFile[life_pno_key($r['policy_no']) . '#' . $r['inst_no']] = 1;
        $st = $pdo->prepare("SELECT i.id, i.policy_id, i.inst_no, i.due_j, i.amount, " . life_sql_rem('i') . " AS rem, p.policy_no, p.holder_name, p.holder_nid
                               FROM life_installments i JOIN life_policies p ON p.id = i.policy_id
                              WHERE i.cleared = 0 AND i.due_j >= ? AND i.due_j <= ? HAVING rem > 0 ORDER BY i.due_j, p.policy_no");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $m) {
            if (isset($inFile[life_pno_key($m['policy_no']) . '#' . intval($m['inst_no'])])) continue;
            $missing[] = ['id' => intval($m['id']), 'policy_id' => intval($m['policy_id']), 'policy_no' => $m['policy_no'], 'holder_name' => $m['holder_name'],
                          'holder_nid' => $m['holder_nid'], 'inst_no' => intval($m['inst_no']), 'due_j' => $m['due_j'], 'amount' => intval($m['amount']), 'rem' => intval($m['rem'])];
        }
    }
    $sum['missing'] = count($missing);
    $token = bin2hex(random_bytes(12));
    life_tmp_gc();
    $state = ['file_name' => $fileName, 'range' => [$from, $to], 'sheet' => $parsed['sheet'], 'drop_first' => (bool)$dropFirst, 'rows' => $parsed['rows'],
              'policies' => $out, 'missing' => $missing, 'summary' => $sum, 'created' => time(), 'uid' => intval($_SESSION['user_id'] ?? 0)];
    file_put_contents(life_tmp_path($token), json_encode($state, JSON_UNESCAPED_UNICODE));
    unset($state['rows'], $state['uid']);
    $state['token'] = $token;
    $state['total_rows'] = count($parsed['rows']);
    return $state;
}

// ثبتِ نهایی با تصمیم‌های کاربر
// $dec = ['policies' => [key => ['include' => bool, 'phone' => '', 'policy' => 'file'|'keep', 'insts' => [inst_no => ['act' => add|file|keep|skip|edit, 'amount', 'due_j', 'collected']]]],
//         'clear_missing' => [inst_id, ...]]
function life_import_commit($pdo, $token, array $dec, $uid) {
    $path = life_tmp_path($token);
    if (!is_file($path)) return ['error' => 'پیش‌نمایش منقضی شده است؛ فایل را دوباره انتخاب کنید.'];
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state)) return ['error' => 'پیش‌نمایش خراب است؛ دوباره امتحان کنید.'];
    $raw = [];
    foreach ($state['rows'] as $r) $raw[life_pno_key($r['policy_no']) . '#' . $r['inst_no']] = $r;
    $polDec = (array)($dec['policies'] ?? []);
    $stats = ['policies_new' => 0, 'policies_updated' => 0, 'inst_new' => 0, 'inst_updated' => 0, 'inst_kept' => 0, 'inst_skipped' => 0, 'policies_skipped' => 0, 'cleared' => 0];
    $touched = [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO life_imports (file_name, created_by, created_at, range_from, range_to) VALUES (?, ?, NOW(), ?, ?)")
            ->execute([$state['file_name'], $uid, $state['range'][0], $state['range'][1]]);
        $importId = intval($pdo->lastInsertId());
        foreach ($state['policies'] as $pol) {
            $d = (array)($polDec[$pol['key']] ?? []);
            $include = array_key_exists('include', $d) ? !empty($d['include']) : !$pol['dropped'];
            if (!$include) { $stats['policies_skipped']++; continue; }
            $phone = life_mobile($d['phone'] ?? $pol['phone']);
            $first = null;
            foreach ($state['rows'] as $r) if (life_pno_key($r['policy_no']) === $pol['key']) { $first = $r; break; }
            if (!$first) continue;
            $vals = [];
            foreach (LIFE_POLICY_FIELDS as $fk => $fa) $vals[$fk] = ($first[$fk] ?? null) === '' ? null : ($first[$fk] ?? null);
            $vals['issue_g'] = $first['issue_g'];
            $vals['holder_mobile'] = $phone ?: ($vals['holder_mobile'] ?: null);
            $extra = $first['raw']; unset($extra['ردیف']);
            foreach (['شماره داخلی قسط', 'شماره قسط', 'تاریخ سررسید', 'مبلغ قسط', 'مبلغ وصول شده', 'مبلغ بدهی'] as $x) unset($extra[$x]);
            $pid = $pol['existing_id'];
            if ($pid && !life_policy_row($pdo, $pid)) $pid = null;
            $instDec = (array)($d['insts'] ?? []);
            $added = 0; $upd = 0;
            if (!$pid) {
                $cols = array_keys($vals);
                $pdo->prepare("INSERT INTO life_policies (policy_no, " . implode(', ', $cols) . ", extra_json, import_id, created_at, created_by, updated_at) VALUES (?, "
                              . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, NOW(), ?, NOW())")
                    ->execute(array_merge([$pol['policy_no']], array_values($vals), [json_encode($extra, JSON_UNESCAPED_UNICODE), $importId, $uid]));
                $pid = intval($pdo->lastInsertId());
                $stats['policies_new']++;
                life_sys_note($pdo, $pid, 'بیمه‌نامه از فایلِ «' . $state['file_name'] . '» ساخته شد.', $uid);
            } else {
                $ex = life_policy_row($pdo, $pid);
                $mode = $d['policy'] ?? ($pol['nid_conflict'] ? 'keep' : 'file');
                $set = []; $args = [];
                foreach ($vals as $fk => $v) {
                    if ($v === null || $v === '') continue;
                    if ($fk === 'holder_mobile') continue;   // پایین‌تر، بدونِ گم شدنِ شماره‌های قبلی
                    if ($mode === 'keep' && (string)$ex[$fk] !== '') continue;
                    if ((string)$ex[$fk] !== (string)$v) { $set[] = "$fk = ?"; $args[] = $v; }
                }
                // موبایل: شماره‌ی انتخابیِ پیش‌نمایش موبایلِ اصلی می‌شود؛ موبایلِ قبلی و موبایلِ فایل (اگر فرق دارند) در «شماره‌های دیگر» می‌مانند
                $known = array_column(life_phone_list($ex), 'phone');
                $extraPh = [];
                if ($phone && $phone !== (string)$ex['holder_mobile']) {
                    $set[] = 'holder_mobile = ?'; $args[] = $phone;
                    if ($ex['holder_mobile']) $extraPh[] = $ex['holder_mobile'];
                }
                $fm = life_mobile($first['holder_mobile'] ?? '');
                if ($fm && $fm !== $phone && !in_array($fm, $known, true)) $extraPh[] = $fm . '|طبقِ اکسلِ بیمه‌گر';
                $others = array_map(function ($l) { return life_mobile(explode('|', $l)[0]); }, array_filter(preg_split('/\n/', (string)$ex['phones'])));
                $extraPh = array_values(array_filter($extraPh, function ($x) use ($others, $phone) { $n = explode('|', $x)[0]; return $n !== $phone && !in_array($n, $others, true); }));
                if ($extraPh) { $set[] = 'phones = ?'; $args[] = trim(implode("\n", array_merge(array_filter(preg_split('/\n/', (string)$ex['phones'])), $extraPh))); }
                if ($set) {
                    $set[] = 'extra_json = ?'; $args[] = json_encode($extra, JSON_UNESCAPED_UNICODE);
                    $args[] = $pid;
                    $pdo->prepare("UPDATE life_policies SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ?")->execute($args);
                    $stats['policies_updated']++;
                }
            }
            foreach ($pol['insts'] as $ri) {
                $n = intval($ri['inst_no']);
                $id = (array)($instDec[$n] ?? []);
                $def = $ri['status'] === 'new' ? 'add' : ($ri['status'] === 'conflict' ? 'keep' : ($ri['status'] === 'update' ? 'file' : 'keep'));
                if ($ri['status'] === 'conflict' && !$ri['db']) $def = 'skip';
                $act = (string)($id['act'] ?? $def);
                if ($act === 'skip' || ($act === 'keep' && !$ri['db'])) { $stats['inst_skipped']++; continue; }
                if ($n <= 0) { $stats['inst_skipped']++; continue; }
                $src = $raw[$pol['key'] . '#' . $n] ?? null;
                if (!$src) continue;
                $amount = $src['amount']; $dueJ = $src['due_j']; $dueG = $src['due_g']; $coll = $src['collected'];
                if ($act === 'edit') {
                    if (isset($id['amount'])) $amount = life_int($id['amount']);
                    if (!empty($id['due_j'])) { [$jj, $gg] = life_jdate($id['due_j']); if ($jj) { $dueJ = $jj; $dueG = $gg; } }
                    if (isset($id['collected'])) $coll = life_int($id['collected']);
                }
                if ($amount <= 0 || !$dueJ) { $stats['inst_skipped']++; continue; }
                $cur = $pdo->prepare("SELECT * FROM life_installments WHERE policy_id = ? AND inst_no = ?");
                $cur->execute([$pid, $n]);
                $cur = $cur->fetch();
                if ($cur) {
                    if ($act === 'keep') {
                        // فقط وصولِ اکسل و آخرین ورود به‌روز می‌شود؛ اطلاعاتِ قسط دست نمی‌خورد
                        $pdo->prepare("UPDATE life_installments SET excel_collected = GREATEST(excel_collected, ?), last_import = ?, updated_at = NOW() WHERE id = ?")->execute([$coll, $importId, $cur['id']]);
                        $stats['inst_kept']++;
                        continue;
                    }
                    $pdo->prepare("UPDATE life_installments SET internal_no = ?, pay_id = ?, due_j = ?, due_g = ?, amount = ?, excel_collected = ?, excel_debt = ?, cleared = 0, cleared_note = NULL,
                                   last_import = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$src['internal_no'] ?: $cur['internal_no'], $src['pay_id'] ?: $cur['pay_id'], $dueJ, $dueG, $amount, $coll, $src['debt'], $importId, $cur['id']]);
                    if ($ri['status'] === 'conflict' || $act === 'edit') life_sys_note($pdo, $pid, 'قسطِ ' . $n . ' طبقِ فایلِ «' . $state['file_name'] . '» اصلاح شد' . ($act === 'edit' ? ' (با ویرایشِ دستی)' : '') . '.', $uid, intval($cur['id']));
                    $stats['inst_updated']++; $upd++;
                } else {
                    $pdo->prepare("INSERT INTO life_installments (policy_id, inst_no, internal_no, pay_id, due_j, due_g, amount, excel_collected, excel_debt, last_import, created_at, updated_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$pid, $n, $src['internal_no'] ?: null, $src['pay_id'] ?: null, $dueJ, $dueG, $amount, $coll, $src['debt'], $importId]);
                    $stats['inst_new']++; $added++;
                }
            }
            if ($pol['existing_id'] && ($added || $upd)) life_sys_note($pdo, $pid, 'ورود از اکسلِ «' . $state['file_name'] . '»: ' . life_fa($added) . ' قسطِ تازه، ' . life_fa($upd) . ' قسطِ به‌روزشده.', $uid);
            $touched[$pid] = 1;
        }
        // تسویه‌ی اقساطی که دیگر در گزارش نیستند
        $allowed = array_column($state['missing'], null, 'id');
        $note = 'در گزارشِ وصول‌نشده‌های ' . ($state['range'][0] ?: '') . ' تا ' . ($state['range'][1] ?: '') . ' نبود (پرداخت‌شده نزدِ بیمه‌گر)';
        foreach ((array)($dec['clear_missing'] ?? []) as $iid) {
            $iid = intval($iid);
            if (!isset($allowed[$iid])) continue;
            $pdo->prepare("UPDATE life_installments SET cleared = 1, cleared_note = ?, last_import = ?, updated_at = NOW() WHERE id = ? AND cleared = 0")->execute([$note, $importId, $iid]);
            life_sys_note($pdo, $allowed[$iid]['policy_id'], 'قسطِ ' . $allowed[$iid]['inst_no'] . ' تسویه شد: ' . $note . '.', $uid, $iid);
            $stats['cleared']++;
            $touched[$allowed[$iid]['policy_id']] = 1;
        }
        $pdo->prepare("UPDATE life_imports SET stats_json = ? WHERE id = ?")->execute([json_encode($stats + ['summary' => $state['summary']], JSON_UNESCAPED_UNICODE), $importId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[life_import_commit] ' . $e->getMessage());
        return ['error' => 'ثبت انجام نشد: ' . $e->getMessage()];
    }
    @unlink($path);
    foreach (array_keys($touched) as $pid) { try { life_write_archive($pdo, $pid); } catch (Throwable $e) { error_log('[life archive] ' . $e->getMessage()); } }
    return ['stats' => $stats, 'import_id' => $importId];
}
