<?php
// فایل: api/_recon.php
// «مغایرت‌گیری با پاسارگاد»: خروجیِ دوره‌ایِ پاسارگاد (اکسلِ صدورِ بدنه/ثالث و اکسلِ الحاقیه‌ها؛ همان قالبِ «ورودِ بایگانی») با سایت مقایسه می‌شود:
//   MATCH   در هر دو هست و یکی است
//   DIFF    در هر دو هست ولی فیلدی فرق دارد (حق بیمه، تاریخ‌ها، پلاک، شاسی، بیمه‌گذار، نوع، الحاقیه‌ها/حق بیمه‌ی نهایی/فسخ)
//   ONLY_P  فقط در پاسارگاد (=> «ورود به سایت» با همان سازوکارِ ورودِ بایگانی)
//   ONLY_S  فقط در سایت (در بازه‌ی تاریخِ صدورِ همان خروجی و از همان نوع‌ها)
// برای هر فیلدِ مغایر انتخاب می‌شود «سایت درست است» یا «پاسارگاد درست است» (مقدارِ پاسارگاد روی سایت می‌نشیند) یا مقدارِ دستی.
// نیاز: _legacy.php (و وابستگی‌هایش) و _endorse.php

function recon_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS recon_runs (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NULL, files TEXT NULL, period_from VARCHAR(10) NULL, period_to VARCHAR(10) NULL,
        types VARCHAR(60) NULL, has_endo TINYINT(1) NOT NULL DEFAULT 0, counts TEXT NULL, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS recon_items (
        id INT AUTO_INCREMENT PRIMARY KEY, run_id INT NOT NULL, category VARCHAR(8) NOT NULL, policy_key VARCHAR(40) NULL, policy_number VARCHAR(60) NULL,
        source CHAR(1) NULL, ref_id INT NULL, label VARCHAR(255) NULL, insured VARCHAR(255) NULL, ins_type VARCHAR(20) NULL, issue_date VARCHAR(10) NULL,
        diffs MEDIUMTEXT NULL, pas_json MEDIUMTEXT NULL, status VARCHAR(10) NOT NULL DEFAULT 'OPEN', note VARCHAR(500) NULL,
        resolved_by INT NULL, resolved_at DATETIME NULL,
        INDEX (run_id, category), INDEX (policy_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // فایل‌های اصلیِ هر مغایرت‌گیری نگه داشته می‌شوند تا «خروجیِ اصلاح‌شده» با همان ستون‌ها ساخته شود
    try { if (!$pdo->query("SHOW COLUMNS FROM recon_runs LIKE 'src_files'")->fetch()) $pdo->exec("ALTER TABLE recon_runs ADD COLUMN src_files TEXT NULL"); } catch (Throwable $e) { error_log('[recon src_files] ' . $e->getMessage()); }
}

function recon_cat_fa($c) {
    return ['MATCH' => 'منطبق', 'DIFF' => 'مغایر', 'ONLY_P' => 'فقط در پاسارگاد', 'ONLY_S' => 'فقط در سایت'][$c] ?? $c;
}
function recon_field_fa($f) {
    return ['type' => 'نوع بیمه‌نامه', 'issue' => 'تاریخ صدور', 'start' => 'تاریخ شروع', 'end' => 'تاریخ انقضا', 'premium' => 'حق بیمه', 'plate' => 'پلاک',
            'vin' => 'شماره شاسی', 'insured' => 'بیمه‌گذار', 'endo' => 'الحاقیه‌ها', 'final' => 'حق بیمه‌ی نهایی', 'cancel' => 'فسخ'][$f] ?? $f;
}

// ---- یکسان‌سازی برای مقایسه ----
function recon_date($v) {
    $v = p2e_digits(trim((string)$v));
    if (!preg_match('#(1[34]\d{2})\D(\d{1,2})\D(\d{1,2})#', $v, $m)) return '';
    return sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
}
function recon_name($v) {
    $v = str_replace(['ي', 'ك', 'ة', 'ۀ', "\xE2\x80\x8C", "\xE2\x80\x8E", 'ـ'], ['ی', 'ک', 'ه', 'ه', '', '', ''], (string)$v);
    $v = preg_replace('/^(شرکت|شركت)\s+/u', '', trim($v));
    return preg_replace('/[\s\.\-\(\)«»"\']+/u', '', $v);
}
function recon_vin($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); }
function recon_plate_core($p1, $p2, $l, $p4) { return ($p2 && $p1) ? p2e_digits($p1 . $p2 . $l . $p4) : ''; }
function recon_plate_text_core($t) {
    $t = p2e_digits((string)$t);
    return preg_match('/(\d{2})\s*ایران\s*-?\s*(\d{3})\s*([آ-ی])\s*(\d{2})/u', $t, $m) ? $m[1] . $m[2] . $m[3] . $m[4] : '';
}

// همه‌ی بیمه‌نامه‌های صادرشده‌ی سایت (شرکتی و کارکنان، وارداتی و قبلی هم) به کلیدِ شماره‌ی بیمه‌نامه
function recon_site_index($pdo) {
    endo_ensure($pdo);
    $idx = [];
    $sql = "SELECT crp.*, cr.company_id, cr.is_import, c.name AS company_name FROM company_request_plates crp
              JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
             WHERE (crp.status = 'ISSUED' OR COALESCE(cr.is_import, 0) = 1) AND cr.request_kind = 'NEW_POLICY'";
    foreach ($pdo->query($sql) as $r) {
        $pol = $r['policy_number'] ?: $r['import_policy_number'];
        $k = endo_policy_key($pol);
        if ($k === '') continue;
        $ii = issue_info_decode($r['issue_info']);
        $ij = json_decode((string)$r['import_json'], true) ?: [];   // ردیفِ «بایگانی وارداتی» که هنوز صادر نشده: اطلاعات از اکسلِ ورود
        $prem = money_to_int($r['total_premium']) ?: money_to_int($ij['premium'] ?? 0);
        $plateDisp = company_plate_display($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']);
        $idx[$k] = ['source' => 'C', 'ref_id' => (int)$r['id'], 'policy' => $pol, 'type' => $r['insurance_type'],
                    'issue' => recon_date($r['policy_issue_date'] ?: $r['import_issue_date']), 'start' => recon_date(($ii['start_date'] ?? '') ?: ($ij['start_date'] ?? '')),
                    'end' => recon_date(($ii['end_date'] ?? '') ?: ($ij['end_date'] ?? '')), 'premium' => $prem,
                    'final' => $r['final_premium'] !== null ? (int)$r['final_premium'] : $prem, 'endo_count' => (int)$r['endo_count'],
                    'cancelled' => (int)$r['is_cancelled'], 'plate' => recon_plate_core($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']),
                    'plate_text' => $plateDisp, 'vin' => recon_vin($r['vin'] ?: $r['chassis_no']), 'vin_text' => $r['vin'] ?: $r['chassis_no'],
                    'insured' => ($ii['insured_name'] ?? '') ?: $r['company_name'], 'company' => $r['company_name'],
                    'label' => ($plateDisp ?: ($r['chassis_no'] ?: '')) . ' · ' . $r['company_name'] . ($r['status'] !== 'ISSUED' ? ' (بایگانی وارداتی، هنوز صادر نشده)' : ''), 'is_import' => (int)$r['is_import']];
    }
    foreach ($pdo->query("SELECT pc.*, per.full_name AS holder_name FROM policy_cases pc LEFT JOIN persons per ON per.id = pc.person_id WHERE pc.status = 'ISSUED'") as $r) {
        $k = endo_policy_key($r['policy_number']);
        if ($k === '') continue;
        $ii = issue_info_decode($r['issue_info'] ?? '');
        $idx[$k] = ['source' => 'P', 'ref_id' => (int)$r['id'], 'policy' => $r['policy_number'], 'type' => $r['insurance_type'],
                    'issue' => recon_date($r['policy_issue_date']), 'start' => recon_date($ii['start_date'] ?? ''), 'end' => recon_date($ii['end_date'] ?? ''),
                    'premium' => money_to_int($r['total_premium']), 'final' => $r['final_premium'] !== null ? (int)$r['final_premium'] : money_to_int($r['total_premium']),
                    'endo_count' => (int)$r['endo_count'], 'cancelled' => (int)$r['is_cancelled'], 'plate' => recon_plate_text_core($r['plate']),
                    'plate_text' => (string)$r['plate'], 'vin' => recon_vin($r['vin'] ?: $r['chassis_num']), 'vin_text' => $r['vin'] ?: $r['chassis_num'],
                    'insured' => (string)$r['insured_name'], 'company' => (string)$r['holder_name'],
                    'label' => ($r['plate'] ?: $r['vin']) . ' · ' . $r['insured_name'], 'is_import' => 0];
    }
    // شماره‌ی الحاقیه‌های ثبت‌شده‌ی هر بیمه‌نامه
    foreach ($pdo->query("SELECT policy_key, endorse_no FROM policy_endorsements") as $e) {
        if (isset($idx[$e['policy_key']])) $idx[$e['policy_key']]['endos'][] = (string)(int)p2e_digits((string)$e['endorse_no']);
    }
    return $idx;
}

// مقایسه‌ی یک ردیفِ پاسارگاد با سایت => فهرستِ فیلدهای مغایر
function recon_compare(array $p, array $s, $hasEndo, array $pEndos) {
    $d = [];
    $add = function ($f, $sv, $pv, $sRaw = null, $pRaw = null, $apply = true) use (&$d) {
        $d[] = ['f' => $f, 'label' => recon_field_fa($f), 'site' => $sRaw ?? $sv, 'pas' => $pRaw ?? $pv, 'apply' => $apply];
    };
    if ($p['type'] && $s['type'] && $p['type'] !== $s['type']) $add('type', $s['type'], $p['type'], insurance_type_fa($s['type']), insurance_type_fa($p['type']), false);
    foreach (['issue', 'start', 'end'] as $f) {
        $pv = recon_date($p[$f] ?? '');
        if ($pv !== '' && $pv !== $s[$f]) $add($f, $s[$f], $pv);
    }
    if ((int)$p['premium'] > 0 && (int)$p['premium'] !== (int)$s['premium']) $add('premium', (int)$s['premium'], (int)$p['premium']);
    $pPlate = $p['plate'] ? recon_plate_core($p['plate']['p1'] ?? '', $p['plate']['p2'] ?? '', $p['plate']['letter'] ?? '', $p['plate']['p4'] ?? '') : '';
    if ($pPlate !== '' && $pPlate !== $s['plate']) $add('plate', $s['plate'], $pPlate, $s['plate_text'], $p['plate_text']);
    $pVin = recon_vin($p['vin']);
    if ($pVin !== '' && $pVin !== $s['vin']) $add('vin', $s['vin'], $pVin, $s['vin_text'], $p['vin']);
    if (recon_name($p['insured']) !== '' && recon_name($p['insured']) !== recon_name($s['insured'])) $add('insured', $s['insured'], $p['insured']);
    if ($hasEndo) {
        $sNos = array_values(array_unique($s['endos'] ?? []));
        $pNos = array_values(array_unique(array_map(fn($e) => (string)(int)p2e_digits((string)$e['endorse_no']), $pEndos)));
        $missing = array_values(array_diff($pNos, $sNos));
        $extra = array_values(array_diff($sNos, $pNos));
        if ($missing || $extra) {
            $add('endo', implode('، ', $sNos) ?: '—', implode('، ', $pNos) ?: '—',
                 ($sNos ? count($sNos) . ' الحاقیه (' . implode('، ', $sNos) . ')' : 'بدونِ الحاقیه') . ($extra ? ' — فقط در سایت: ' . implode('، ', $extra) : ''),
                 ($pNos ? count($pNos) . ' الحاقیه (' . implode('، ', $pNos) . ')' : 'بدونِ الحاقیه') . ($missing ? ' — در سایت نیست: ' . implode('، ', $missing) : ''), (bool)$missing);
        }
        $pFinal = (int)$p['premium'];
        $pCancel = 0;
        foreach ($pEndos as $e) { $pFinal += endo_sign($e['kind']) * (int)$e['gross']; if ($e['kind'] === 'CANCELLATION') $pCancel = 1; }
        if ($pFinal !== (int)$s['final']) $add('final', (int)$s['final'], $pFinal, null, null, false);
        if ($pCancel !== (int)$s['cancelled']) $add('cancel', $s['cancelled'] ? 'فسخ‌شده' : 'فعال', $pCancel ? 'فسخ‌شده' : 'فعال', null, null, false);
    }
    return $d;
}

// اجرای یک مغایرت‌گیری روی فایل‌های بارگذاری‌شده
// $mode = '' : اگر همین فایل قبلاً مغایرت‌گیری شده و فایلِ اصلی‌اش روی سایت نیست، اول پرسیده می‌شود (need_choice) که به همان وصل شود یا نه
//         'new': مغایرت‌گیریِ تازه در هر حال
function recon_run($pdo, $siteRoot, array $files, $title, $userId, $mode = '') {
    recon_ensure($pdo);
    $pv = legacy_preview($pdo, $siteRoot, $files);
    @unlink(legacy_tmp_dir($siteRoot) . '/p_' . $pv['token'] . '.json');
    $rows = $pv['rows'] ?? [];
    $endos = $pv['endorsements'] ?? [];
    if (!$rows && !$endos) return ['ok' => false, 'error' => implode(' ', $pv['errors'] ?? []) ?: 'ردیفی در فایل‌ها پیدا نشد.'];
    if ($mode !== 'new' && ($m = recon_find_old_run($pdo, $pv))) return ['ok' => false, 'need_choice' => true, 'match' => $m];
    $hasEndo = (bool)array_filter($pv['files'] ?? [], fn($f) => $f['kind'] === 'endorse');
    $endoBy = [];
    foreach ($endos as $e) if (!empty($e['ok'])) $endoBy[$e['key']][] = $e;
    $site = recon_site_index($pdo);

    $from = ''; $to = ''; $types = [];
    foreach ($rows as $r) {
        $d = recon_date($r['issue']);
        if ($d !== '') { if ($from === '' || $d < $from) $from = $d; if ($to === '' || $d > $to) $to = $d; }
        if ($r['type']) $types[$r['type']] = true;
    }
    $pdo->prepare("INSERT INTO recon_runs (title, files, period_from, period_to, types, has_endo, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([mb_substr($title ?: ('خروجیِ پاسارگاد ' . $from . ' تا ' . $to), 0, 250), json_encode(array_column($pv['files'], 'name'), JSON_UNESCAPED_UNICODE),
                   $from ?: null, $to ?: null, implode(',', array_keys($types)), $hasEndo ? 1 : 0, $userId ?: null]);
    $run = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO recon_items (run_id, category, policy_key, policy_number, source, ref_id, label, insured, ins_type, issue_date, diffs, pas_json, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $cnt = ['MATCH' => 0, 'DIFF' => 0, 'ONLY_P' => 0, 'ONLY_S' => 0, 'fields' => []];
    $seen = [];
    foreach ($rows as $r) {
        if ($r['key'] === '' || isset($seen[$r['key']])) continue;
        $seen[$r['key']] = true;
        $pEndos = $endoBy[$r['key']] ?? [];
        $pas = ['row' => $r, 'endos' => $pEndos];
        $s = $site[$r['key']] ?? null;
        if (!$s) {
            $ins->execute([$run, 'ONLY_P', $r['key'], $r['policy'], null, null, $r['plate_text'] ?: $r['vin'], $r['insured'], $r['type'], recon_date($r['issue']),
                           null, json_encode($pas, JSON_UNESCAPED_UNICODE), 'OPEN']);
            $cnt['ONLY_P']++;
            continue;
        }
        $diffs = recon_compare($r, $s, $hasEndo, $pEndos);
        $cat = $diffs ? 'DIFF' : 'MATCH';
        foreach ($diffs as $x) $cnt['fields'][$x['f']] = ($cnt['fields'][$x['f']] ?? 0) + 1;
        $ins->execute([$run, $cat, $r['key'], $r['policy'], $s['source'], $s['ref_id'], $s['label'], $s['insured'], $s['type'], $s['issue'],
                       $diffs ? json_encode($diffs, JSON_UNESCAPED_UNICODE) : null, $diffs ? json_encode($pas, JSON_UNESCAPED_UNICODE) : null, $diffs ? 'OPEN' : 'RESOLVED']);
        $cnt[$cat]++;
    }
    // الحاقیه‌هایی که بیمه‌نامه‌شان در این خروجی نیست ولی در سایت هست: فقط الحاقیه‌ها مقایسه می‌شوند
    foreach ($endoBy as $k => $list) {
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $s = $site[$k] ?? null;
        if (!$s) {
            // الحاقیه‌ی بیمه‌نامه‌ای که در سایت نیست؛ اگر خودِ الحاقیه‌ها قبلاً «خارج از سایت» ثبت شده‌اند گفته می‌شود
            $xs = $pdo->prepare("SELECT COUNT(*) FROM policy_endorsements WHERE policy_key = ?");
            $xs->execute([$k]);
            $known = (int)$xs->fetchColumn();
            $lbl = 'فقط الحاقیه (' . count($list) . ')' . ($known >= count($list) ? ' — الحاقیه‌ها در سایت ثبت‌اند، بیمه‌نامه نیست' : '');
            $ins->execute([$run, 'ONLY_P', $k, $list[0]['policy'], null, null, $lbl, '', null, recon_date($list[0]['issue_date']),
                           null, json_encode(['row' => null, 'endos' => $list], JSON_UNESCAPED_UNICODE), 'OPEN']);
            $cnt['ONLY_P']++;
            continue;
        }
        $sNos = array_values(array_unique($s['endos'] ?? []));
        $missing = array_values(array_diff(array_map(fn($e) => (string)(int)p2e_digits((string)$e['endorse_no']), $list), $sNos));
        if (!$missing) continue;
        $diffs = [['f' => 'endo', 'label' => recon_field_fa('endo'), 'site' => $sNos ? implode('، ', $sNos) : 'بدونِ الحاقیه', 'pas' => 'در سایت نیست: ' . implode('، ', $missing), 'apply' => true]];
        $cnt['fields']['endo'] = ($cnt['fields']['endo'] ?? 0) + 1;
        $ins->execute([$run, 'DIFF', $k, $s['policy'], $s['source'], $s['ref_id'], $s['label'], $s['insured'], $s['type'], $s['issue'],
                       json_encode($diffs, JSON_UNESCAPED_UNICODE), json_encode(['row' => null, 'endos' => $list], JSON_UNESCAPED_UNICODE), 'OPEN']);
        $cnt['DIFF']++;
    }
    // فقط در سایت: در بازه‌ی تاریخِ صدورِ همین خروجی و از همان نوع‌ها
    if ($from !== '' && $types) {
        foreach ($site as $k => $s) {
            if (isset($seen[$k]) || $s['issue'] === '' || $s['issue'] < $from || $s['issue'] > $to || !isset($types[$s['type']])) continue;
            $ins->execute([$run, 'ONLY_S', $k, $s['policy'], $s['source'], $s['ref_id'], $s['label'], $s['insured'], $s['type'], $s['issue'], null, null, 'OPEN']);
            $cnt['ONLY_S']++;
        }
    }
    $pdo->prepare("UPDATE recon_runs SET counts = ? WHERE id = ?")->execute([json_encode($cnt, JSON_UNESCAPED_UNICODE), $run]);
    $stored = recon_store_src($siteRoot, $run, $files, $pv, '');
    $pdo->prepare("UPDATE recon_runs SET src_files = ? WHERE id = ?")->execute([json_encode($stored, JSON_UNESCAPED_UNICODE), $run]);
    return ['ok' => true, 'run_id' => $run, 'src_files' => recon_src_list($stored, $title ?: ('خروجیِ پاسارگاد ' . $from . ' تا ' . $to)), 'counts' => $cnt, 'period_from' => $from, 'period_to' => $to, 'errors' => $pv['errors'] ?? []];
}

// نگه‌داشتنِ فایل‌های اصلی (نوعِ بیمه‌نامه‌ی هر فایل از ردیف‌هایش)
function recon_store_src($siteRoot, $run, array $files, array $pv, $prefix) {
    $stored = [];
    $dir = recon_store_dir($siteRoot, $run);
    foreach ($files as $k => $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $dst = $dir . '/' . $prefix . $k . '.' . $ext;
        if (!@copy($f['path'], $dst)) continue;
        $kind = 'policy';
        foreach ($pv['files'] ?? [] as $fi) if ($fi['name'] === $f['name']) $kind = $fi['kind'];
        $ft = [];
        foreach ($pv['rows'] ?? [] as $r) if (($r['file'] ?? '') === $f['name'] && $r['type']) $ft[$r['type']] = true;
        foreach ($pv['endorsements'] ?? [] as $e) if (($e['file'] ?? '') === $f['name'] && !empty($e['type'])) $ft[$e['type']] = true;
        $stored[] = ['name' => $f['name'], 'file' => basename($dst), 'ext' => $ext, 'kind' => $kind, 'types' => array_keys($ft)];
    }
    return $stored;
}

function recon_src_list(array $stored, $title) {
    $out = [];
    foreach (array_values($stored) as $i => $f) $out[] = ['idx' => $i, 'name' => $f['name'], 'kind' => $f['kind'], 'label' => recon_export_label($f, (string)$title), 'can' => $f['ext'] === 'xlsx'];
    return $out;
}

function recon_file_keys(array $pv) {
    $keys = [];
    foreach ($pv['rows'] ?? [] as $r) if (($r['key'] ?? '') !== '') $keys[$r['key']] = true;
    foreach ($pv['endorsements'] ?? [] as $e) if (($e['key'] ?? '') !== '') $keys[$e['key']] = true;
    return $keys;
}

// مغایرت‌گیریِ قبلیِ بدونِ فایلِ اصلی که تقریباً همین بیمه‌نامه‌ها را دارد (دست‌کم ۸۰٪ از هر دو طرف)
function recon_find_old_run($pdo, array $pv) {
    $keys = recon_file_keys($pv);
    if (!$keys) return null;
    $best = null;
    $q = $pdo->prepare("SELECT policy_key FROM recon_items WHERE run_id = ? AND category <> 'ONLY_S'");
    foreach ($pdo->query("SELECT id, title, created_at FROM recon_runs WHERE src_files IS NULL OR src_files IN ('', '[]') ORDER BY id DESC LIMIT 50")->fetchAll() as $r) {
        $q->execute([$r['id']]);
        $rk = array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN), true);
        if (!$rk) continue;
        $hit = count(array_intersect_key($keys, $rk));
        if ($hit < 0.8 * count($keys) || $hit < 0.8 * count($rk)) continue;
        if (!$best || $hit > $best['matched']) $best = ['id' => (int)$r['id'], 'title' => $r['title'], 'matched' => $hit, 'total' => count($keys),
            'created_jalali' => jalali_from_gregorian_ts_dotted(strtotime($r['created_at'])) . ' ' . date('H:i', strtotime($r['created_at']))];
    }
    return $best;
}

// برای مغایرت‌گیری‌های قدیمی (پیش از نگه‌داشتنِ فایل): همان فایلِ اکسلِ بیمه‌گر به همین مغایرت‌گیری وصل می‌شود تا «خروجیِ اصلاح‌شده»
// ساخته شود، بدونِ اجرای دوباره. فایل باید بیمه‌نامه‌های همین مغایرت‌گیری را داشته باشد؛ تصمیم‌های قبلی همان می‌مانند.
function recon_attach_src($pdo, $siteRoot, $runId, array $files) {
    recon_ensure($pdo);
    $st = $pdo->prepare("SELECT id, title, src_files FROM recon_runs WHERE id = ?");
    $st->execute([$runId]);
    $run = $st->fetch();
    if (!$run) return ['ok' => false, 'error' => 'مغایرت‌گیری پیدا نشد.'];
    $pv = legacy_preview($pdo, $siteRoot, $files);
    @unlink(legacy_tmp_dir($siteRoot) . '/p_' . $pv['token'] . '.json');
    $keys = recon_file_keys($pv);
    if (!$keys) return ['ok' => false, 'error' => implode(' ', $pv['errors'] ?? []) ?: 'ردیفی با شماره‌ی بیمه‌نامه در فایل پیدا نشد.'];
    $q = $pdo->prepare("SELECT policy_key FROM recon_items WHERE run_id = ? AND category <> 'ONLY_S'");
    $q->execute([$runId]);
    $runKeys = array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN), true);
    $hit = count(array_intersect_key($keys, $runKeys));
    if (!$hit) return ['ok' => false, 'error' => 'بیمه‌نامه‌های این فایل در این مغایرت‌گیری نیستند؛ همان فایلی را بزنید که این مغایرت‌گیری با آن انجام شده بود.'];
    $old = json_decode((string)($run['src_files'] ?? ''), true) ?: [];
    $new = recon_store_src($siteRoot, $runId, $files, $pv, 'a' . time() . '_');
    if (!$new) return ['ok' => false, 'error' => 'فایل روی هاست ذخیره نشد.'];
    $all = array_merge($old, $new);
    $pdo->prepare("UPDATE recon_runs SET src_files = ? WHERE id = ?")->execute([json_encode($all, JSON_UNESCAPED_UNICODE), $runId]);
    return ['ok' => true, 'run_id' => (int)$runId, 'title' => (string)$run['title'], 'matched' => $hit, 'total' => count($keys), 'files' => count($new), 'src_files' => recon_src_list($all, (string)$run['title'])];
}

function recon_runs($pdo) {
    recon_ensure($pdo);
    $rows = $pdo->query("SELECT r.*, u.full_name AS creator,
                                (SELECT COUNT(*) FROM recon_items i WHERE i.run_id = r.id AND i.status = 'OPEN') AS open_count
                           FROM recon_runs r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.id DESC LIMIT 200")->fetchAll();
    foreach ($rows as &$r) {
        $r['files'] = json_decode((string)$r['files'], true) ?: [];
        $r['counts'] = json_decode((string)$r['counts'], true) ?: [];
        $r['src_files'] = recon_src_list(json_decode((string)($r['src_files'] ?? ''), true) ?: [], (string)$r['title']);
        $r['created_jalali'] = jalali_from_gregorian_ts_dotted(strtotime($r['created_at'])) . ' ' . date('H:i', strtotime($r['created_at']));
    }
    return $rows;
}

function recon_items($pdo, $runId, array $f) {
    recon_ensure($pdo);
    $w = ['run_id = ?']; $a = [$runId];
    if (!empty($f['category'])) { $w[] = 'category = ?'; $a[] = $f['category']; }
    if (!empty($f['status'])) { $w[] = 'status = ?'; $a[] = $f['status']; }
    if (!empty($f['field'])) { $w[] = 'diffs LIKE ?'; $a[] = '%"f":"' . preg_replace('/\W/', '', $f['field']) . '"%'; }
    if (!empty($f['q'])) {
        $q = '%' . p2e_digits(trim($f['q'])) . '%';
        $w[] = '(policy_number LIKE ? OR label LIKE ? OR insured LIKE ? OR policy_key LIKE ?)';
        array_push($a, $q, $q, $q, '%' . endo_policy_key($f['q']) . '%');
    }
    $st = $pdo->prepare("SELECT * FROM recon_items WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(category, 'DIFF', 'ONLY_P', 'ONLY_S', 'MATCH'), issue_date, id LIMIT 2000");
    $st->execute($a);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $r['diffs'] = json_decode((string)$r['diffs'], true) ?: [];
        $pas = json_decode((string)$r['pas_json'], true) ?: [];
        unset($r['pas_json']);
        $r['pas_endos'] = array_map(fn($e) => ['no' => $e['endorse_no'], 'kind' => $e['kind_raw'] ?? $e['kind'], 'gross' => (int)$e['gross'], 'issue' => $e['issue_date']], $pas['endos'] ?? []);
        if (!empty($pas['row'])) {
            $p = $pas['row'];
            $r['pas'] = ['policy' => $p['policy'], 'type_fa' => $p['type_fa'], 'issue' => $p['issue'], 'start' => $p['start'], 'end' => $p['end'], 'premium' => (int)$p['premium'],
                         'insured' => $p['insured'], 'plate_text' => $p['plate_text'], 'vin' => $p['vin'], 'target' => $p['target'], 'holder' => $p['holder'], 'contract' => $p['contract']];
        }
        $r['category_fa'] = recon_cat_fa($r['category']);
        $r['type_fa'] = $r['ins_type'] ? insurance_type_fa($r['ins_type']) : '';
        $out[] = $r;
    }
    $sum = $pdo->prepare("SELECT category, status, COUNT(*) n FROM recon_items WHERE run_id = ? GROUP BY category, status");
    $sum->execute([$runId]);
    return ['rows' => $out, 'summary' => $sum->fetchAll()];
}

// اعمالِ مقدارِ پاسارگاد (یا مقدارِ دستی) روی سایت برای یک فیلد
function recon_apply_field($pdo, $siteRoot, array $item, $field, $value, $userId) {
    $src = $item['source']; $id = (int)$item['ref_id'];
    $tbl = $src === 'C' ? 'company_request_plates' : ($src === 'P' ? 'policy_cases' : null);
    if (!$tbl || !$id) return ['ok' => false, 'error' => 'بیمه‌نامه‌ی سایت پیدا نشد.'];
    $pas = json_decode((string)$item['pas_json'], true) ?: [];
    $setInfo = function ($k, $v) use ($pdo, $tbl, $id) {
        $cur = $pdo->query("SELECT issue_info FROM $tbl WHERE id = " . $id)->fetchColumn();
        $ii = issue_info_decode($cur);
        $ii[$k] = $v;
        $pdo->prepare("UPDATE $tbl SET issue_info = ? WHERE id = ?")->execute([json_encode($ii, JSON_UNESCAPED_UNICODE), $id]);
    };
    switch ($field) {
        case 'issue':
            $v = recon_date($value);
            if ($v === '') return ['ok' => false, 'error' => 'تاریخ نامعتبر است.'];
            $pdo->prepare("UPDATE $tbl SET policy_issue_date = ? WHERE id = ?")->execute([$v, $id]);
            if ($src === 'C') $pdo->prepare("UPDATE company_request_plates SET import_issue_date = ? WHERE id = ? AND import_issue_date IS NOT NULL")->execute([$v, $id]);
            break;
        case 'start':
        case 'end':
            $v = recon_date($value);
            if ($v === '') return ['ok' => false, 'error' => 'تاریخ نامعتبر است.'];
            $setInfo($field === 'start' ? 'start_date' : 'end_date', $v);
            if ($field === 'end' && $src === 'C') $pdo->prepare("UPDATE company_request_plates SET expiry_date = ? WHERE id = ?")->execute([fin_jalali_to_date($v), $id]);
            break;
        case 'premium':
            $v = (int)preg_replace('/\D/', '', p2e_digits((string)$value));
            if ($v <= 0) return ['ok' => false, 'error' => 'مبلغ نامعتبر است.'];
            $pdo->prepare("UPDATE $tbl SET total_premium = ? WHERE id = ?")->execute([(string)$v, $id]);
            endo_recompute($pdo, $src, $id);
            break;
        case 'plate':
            $raw = (string)$value;
            $core = recon_plate_text_core($raw) ?: (preg_match('/^(\d{2})(\d{3})([آ-ی])(\d{2})$/u', p2e_digits(preg_replace('/\s+/u', '', $raw)), $m) ? $m[0] : '');
            if ($core === '' || !preg_match('/^(\d{2})(\d{3})([آ-ی])(\d{2})$/u', $core, $m)) return ['ok' => false, 'error' => 'پلاک نامعتبر است.'];
            if ($src === 'C') $pdo->prepare("UPDATE company_request_plates SET plate_p1 = ?, plate_p2 = ?, plate_letter = ?, plate_p4 = ? WHERE id = ?")->execute([$m[1], $m[2], $m[3], $m[4], $id]);
            else $pdo->prepare("UPDATE policy_cases SET plate = ? WHERE id = ?")->execute([company_plate_display($m[1], $m[2], $m[3], $m[4]), $id]);
            break;
        case 'vin':
            $v = recon_vin($value);
            if ($v === '') return ['ok' => false, 'error' => 'شماره شاسی خالی است.'];
            if ($src === 'C') $pdo->prepare("UPDATE company_request_plates SET vin = ?, chassis_no = ? WHERE id = ?")->execute([$v, $v, $id]);
            else $pdo->prepare("UPDATE policy_cases SET vin = ?, chassis_num = ? WHERE id = ?")->execute([$v, $v, $id]);
            break;
        case 'insured':
            $v = trim((string)$value);
            if ($v === '') return ['ok' => false, 'error' => 'نام خالی است.'];
            if ($src === 'C') $setInfo('insured_name', $v);
            else $pdo->prepare("UPDATE policy_cases SET insured_name = ? WHERE id = ?")->execute([mb_substr($v, 0, 100), $id]);
            break;
        case 'endo':
            // الحاقیه‌هایی از خروجیِ پاسارگاد که در سایت نیستند ثبت می‌شوند (بدونِ اثر روی اقساط؛ اثرِ مالی را بعداً از «الحاقیه‌ها» می‌شود عوض کرد)
            $n = 0;
            foreach ($pas['endos'] ?? [] as $e) {
                if (empty($e['ok'])) continue;
                $r = endo_create($pdo, $siteRoot, ['source' => $src, 'ref_id' => $id, 'policy_number' => $e['policy'], 'endorse_no' => $e['endorse_no'], 'kind' => $e['kind'],
                                                   'issue_date' => $e['issue_date'], 'effect_date' => $e['effect_date'], 'new_end_date' => $e['kind'] === 'CORRECTIVE' ? '' : $e['end_date'],
                                                   'gross' => $e['gross'], 'net' => $e['net'], 'tax' => $e['tax'], 'reason' => $e['reason'], 'description' => $e['description'],
                                                   'finance_mode' => 'NONE', 'origin' => 'RECON', 'insurance_type' => $e['type']], null, $userId);
                if (!empty($r['ok'])) $n++;
            }
            return ['ok' => true, 'created' => $n];
        default:
            return ['ok' => false, 'error' => 'این فیلد از اینجا قابلِ اعمال نیست؛ در خودِ بیمه‌نامه ویرایش کنید.'];
    }
    // فایلِ «اطلاعات درخواستی» پرونده‌ی کارکنان به‌روز می‌شود
    if ($src === 'P' && function_exists('write_case_info_file')) {
        $dir = $pdo->query("SELECT folder_path FROM policy_cases WHERE id = " . $id)->fetchColumn();
        if ($dir && is_dir($dir)) { try { write_case_info_file($pdo, $dir, $id); } catch (Throwable $e) {} }
    }
    return ['ok' => true];
}

// تصمیم برای یک فیلد: SITE (سایت درست است) | PASARGAD (مقدارِ پاسارگاد روی سایت) | CUSTOM (مقدارِ دستی روی سایت)
function recon_resolve($pdo, $siteRoot, $itemId, $field, $choice, $value, $note, $userId) {
    recon_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM recon_items WHERE id = ?");
    $st->execute([$itemId]);
    $item = $st->fetch();
    if (!$item) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    $diffs = json_decode((string)$item['diffs'], true) ?: [];
    $res = ['ok' => true];
    foreach ($diffs as &$d) {
        if ($d['f'] !== $field) continue;
        if ($choice === 'PASARGAD' || $choice === 'CUSTOM') {
            if (empty($d['apply']) && $choice === 'PASARGAD') return ['ok' => false, 'error' => '«' . $d['label'] . '» خودکار اعمال نمی‌شود؛ از خودِ بیمه‌نامه یا «الحاقیه‌ها» درست کنید و بعد «سایت درست است» بزنید.'];
            $v = $choice === 'CUSTOM' ? (string)$value : (string)$d['pas'];
            if ($choice === 'PASARGAD' && in_array($field, ['plate', 'vin'], true)) $v = (string)$d['pas'];
            $res = recon_apply_field($pdo, $siteRoot, $item, $field, $v, $userId);
            if (empty($res['ok'])) return $res;
        }
        $d['res'] = $choice;
        $d['res_value'] = $choice === 'CUSTOM' ? (string)$value : null;
        $d['res_by'] = $userId;
        $d['res_at'] = date('Y-m-d H:i:s');
    }
    unset($d);
    $open = array_filter($diffs, fn($d) => empty($d['res']));
    $pdo->prepare("UPDATE recon_items SET diffs = ?, status = ?, note = COALESCE(NULLIF(?, ''), note), resolved_by = ?, resolved_at = IF(? = 'RESOLVED', NOW(), resolved_at) WHERE id = ?")
        ->execute([json_encode($diffs, JSON_UNESCAPED_UNICODE), $open ? 'OPEN' : 'RESOLVED', (string)$note, $userId ?: null, $open ? 'OPEN' : 'RESOLVED', $itemId]);
    return $res + ['status' => $open ? 'OPEN' : 'RESOLVED'];
}

// ردیفِ «فقط در سایت» یا «فقط در پاسارگاد»: بررسی شد (با توضیح) / دوباره باز
function recon_mark($pdo, $itemId, $status, $note, $userId) {
    recon_ensure($pdo);
    $pdo->prepare("UPDATE recon_items SET status = ?, note = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?")
        ->execute([$status === 'OPEN' ? 'OPEN' : 'RESOLVED', mb_substr((string)$note, 0, 500) ?: null, $userId ?: null, $itemId]);
    return ['ok' => true];
}

// ردیف‌های «فقط در پاسارگاد» => ورود به سایت با سازوکارِ «ورودِ بایگانی» (یک دسته‌ی برگشت‌پذیر)
function recon_import($pdo, $siteRoot, array $itemIds, array $opts, $userId) {
    recon_ensure($pdo);
    $itemIds = array_values(array_filter(array_map('intval', $itemIds)));
    if (!$itemIds) return ['ok' => false, 'error' => 'ردیفی انتخاب نشده.'];
    $st = $pdo->prepare("SELECT * FROM recon_items WHERE category = 'ONLY_P' AND status = 'OPEN' AND id IN (" . implode(',', array_fill(0, count($itemIds), '?')) . ")");
    $st->execute($itemIds);
    $rows = []; $endos = []; $companies = []; $employers = []; $ids = [];
    foreach ($st->fetchAll() as $it) {
        $pas = json_decode((string)$it['pas_json'], true) ?: [];
        foreach ($pas['endos'] ?? [] as $e) $endos[] = $e;
        $ids[] = (int)$it['id'];
        if (empty($pas['row'])) continue;
        $r = $pas['row'];
        $r['i'] = count($rows);
        $r['dup'] = null;
        $r['ok'] = true;
        $rows[] = $r;
    }
    if (!$rows && !$endos) return ['ok' => false, 'error' => 'ردیفِ بازِ «فقط در پاسارگاد» انتخاب نشده.'];
    $tok = bin2hex(random_bytes(10));
    file_put_contents(legacy_tmp_dir($siteRoot) . '/p_' . $tok . '.json', json_encode(['files' => [['name' => 'مغایرت‌گیری با پاسارگاد']], 'errors' => [], 'rows' => $rows,
                                                                                       'endorsements' => $endos, 'orphans' => [], 'companies' => $companies, 'employers' => $employers], JSON_UNESCAPED_UNICODE));
    $choice = ['finance' => ($opts['finance'] ?? 'PREMIUM') === 'INSTALLMENTS' ? 'INSTALLMENTS' : 'PREMIUM', 'title' => 'ورود از مغایرت‌گیری', 'quota' => (int)($opts['quota'] ?? 0)];
    $res = legacy_commit($pdo, $siteRoot, $tok, $choice, $userId);
    if (!empty($res['ok'])) {
        $pdo->prepare("UPDATE recon_items SET status = 'RESOLVED', note = ?, resolved_by = ?, resolved_at = NOW() WHERE id IN (" . implode(',', $ids) . ")")
            ->execute(['به سایت وارد شد (دسته‌ی ' . $res['batch_id'] . ')', $userId ?: null]);
    }
    return $res;
}

function recon_delete_run($pdo, $runId, $siteRoot = null) {
    recon_ensure($pdo);
    if ($siteRoot) {
        $d = rtrim($siteRoot, '/') . '/_recon_files/run_' . intval($runId);
        if (is_dir($d)) { foreach ((array)glob($d . '/*') as $f) @unlink($f); @rmdir($d); }
    }
    $pdo->prepare("DELETE FROM recon_items WHERE run_id = ?")->execute([$runId]);
    $pdo->prepare("DELETE FROM recon_runs WHERE id = ?")->execute([$runId]);
    return ['ok' => true];
}

// =====================================================================
//  «خروجیِ اصلاح‌شده»: همان اکسلِ بیمه‌گر (همان ستون‌ها، ترتیب و قالب؛ فقط مقدارِ خانه‌ها) با اطلاعاتِ درستِ سایت:
//   - برای بیمه‌نامه‌های کارکنان: «کد پرسنلی» و «کد پرسنلی پرداخت کننده» از کد پرسنلیِ دارنده‌ی معرفی‌نامه، و
//     «نام / نام خانوادگی / کد ملی پرداخت کننده» از همان شخص
//   - برای هر فیلدی که در این مغایرت‌گیری تصمیم گرفته شد (سایت درست است / پاسارگاد / دستی): مقدارِ نهاییِ سایت
//     (تاریخ صدور، شروع، انقضا، حق بیمه، پلاک، شاسی، بیمه‌گذار)
//  فایلِ اصلی در _recon_files/run_<id>/ (بسته از وب) نگه داشته می‌شود؛ خانه‌ها مستقیم در XMLِ شیت عوض می‌شوند.
// =====================================================================
function recon_store_dir($siteRoot, $runId) {
    $base = rtrim($siteRoot, '/') . '/_recon_files';
    if (!is_dir($base)) { @mkdir($base, 0775, true); @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($base . '/index.html', ''); }
    $d = $base . '/run_' . intval($runId);
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function recon_export_label(array $f, $title) {
    if (($f['kind'] ?? '') === 'endorse') $t = 'الحاقیه';
    else { $t = implode(' و ', array_unique(array_map('insurance_type_fa', $f['types'] ?? []))) ?: 'بیمه‌نامه'; }
    return $t . ' - ' . trim($title);
}
function recon_hnorm($s) { return mb_strtolower(preg_replace('/[\s\x{200B}-\x{200F}\x{202A}-\x{202E}:]+/u', '', str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$s))); }
function recon_col_idx($letters) { $n = 0; foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64); return $n; }
function recon_col_letter($n) { $s = ''; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; }
// نامِ کامل → [نام، نام خانوادگی] (نام‌های دوبخشیِ رایج مثلِ «محمد علی»، «سید محمد» با هم می‌مانند)
function recon_split_name($full) {
    $t = array_values(array_filter(preg_split('/\s+/u', trim(str_replace("\xE2\x80\x8C", ' ', (string)$full))), 'strlen'));
    if (count($t) < 2) return [implode(' ', $t), ''];
    $pre = ['سید', 'سیده', 'میر', 'ابو', 'عبد', 'غلام', 'محمد', 'علی', 'امیر', 'حاج'];
    $second = ['علی', 'حسین', 'حسن', 'رضا', 'مهدی', 'جواد', 'صادق', 'اکبر', 'اصغر', 'باقر', 'کاظم', 'تقی', 'هادی', 'امین', 'محمد', 'عباس', 'الله', 'اله', 'الدین', 'ابراهیم', 'اسماعیل', 'قاسم', 'جعفر', 'مصطفی', 'مرتضی', 'حمید', 'سعید', 'مجید', 'رسول', 'کریم', 'رحیم', 'یوسف'];
    $n = 1;
    if (count($t) >= 3 && in_array($t[0], $pre, true) && (in_array($t[1], $second, true) || in_array($t[0], ['سید', 'سیده', 'میر', 'حاج'], true))) $n = 2;
    return [implode(' ', array_slice($t, 0, $n)), implode(' ', array_slice($t, $n))];
}
function recon_plate_insurer($core, $sample) {
    if (!preg_match('/^(\d{2})(\d{3})(\D+)(\d{2})$/u', (string)$core, $m)) return '';
    $fa = function ($s) { return $s; };
    return mb_strpos((string)$sample, '- ایران -') !== false ? "{$m[2]}{$m[3]}{$m[4]} - ایران - {$m[1]}(ایران)" : "{$m[2]}{$m[3]}{$m[4]} - {$m[1]}(ایران)";
}

// $dry = true: فقط فهرستِ تغییرات (برای پیش‌نمایش)؛ false: فایلِ ساخته‌شده هم برگردانده می‌شود
function recon_corrected($pdo, $siteRoot, $runId, $idx, $dry = true) {
    recon_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM recon_runs WHERE id = ?");
    $st->execute([$runId]);
    $run = $st->fetch();
    if (!$run) return ['ok' => false, 'error' => 'مغایرت‌گیری پیدا نشد.'];
    $files = json_decode((string)($run['src_files'] ?? ''), true) ?: [];
    if (!$files) return ['ok' => false, 'error' => 'فایلِ اصلیِ این مغایرت‌گیری نگه داشته نشده (پیش از این امکان انجام شده)؛ با دکمه‌ی «بارگذاریِ فایلِ اصلی» همان اکسلِ بیمه‌گر را به این مغایرت‌گیری وصل کنید.'];
    $f = $files[intval($idx)] ?? null;
    if (!$f) return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    if ($f['ext'] !== 'xlsx') return ['ok' => false, 'error' => 'خروجیِ اصلاح‌شده فقط برای فایلِ xlsx ساخته می‌شود.'];
    $src = recon_store_dir($siteRoot, $runId) . '/' . $f['file'];
    if (!is_file($src) || !class_exists('ZipArchive')) return ['ok' => false, 'error' => 'فایلِ اصلی روی هاست پیدا نشد.'];
    $tmp = tempnam(sys_get_temp_dir(), 'rcx') . '.xlsx';
    copy($src, $tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) return ['ok' => false, 'error' => 'فایلِ اکسل باز نشد.'];
    // شیتِ اول
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = (string)$zip->getFromName('xl/workbook.xml');
    $rels = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
    if (preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m) && preg_match('/<Relationship\b[^>]*Id="' . preg_quote($m[1], '/') . '"[^>]*Target="([^"]+)"/', $rels, $t)) {
        $sheetPath = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', $t[1]), '/');
    }
    $xml = $zip->getFromName($sheetPath);
    if ($xml === false) { $zip->close(); @unlink($tmp); return ['ok' => false, 'error' => 'شیتِ اکسل پیدا نشد.']; }
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && preg_match_all('/<si>(.*?)<\/si>/s', $ss, $sm)) foreach ($sm[1] as $si) { preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm); $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'); }

    $NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    if (!@$doc->loadXML($xml)) { $zip->close(); @unlink($tmp); return ['ok' => false, 'error' => 'شیتِ اکسل خوانده نشد.']; }
    $xp = new DOMXPath($doc); $xp->registerNamespace('m', $NS);
    $val = function ($c) use ($xp, $shared) {
        $t = $c->getAttribute('t');
        if ($t === 's') { $v = $xp->query('m:v', $c)->item(0); return $v ? (string)($shared[(int)$v->textContent] ?? '') : ''; }
        if ($t === 'inlineStr') { $o = ''; foreach ($xp->query('.//m:t', $c) as $n) $o .= $n->textContent; return $o; }
        $v = $xp->query('m:v', $c)->item(0); return $v ? $v->textContent : '';
    };
    $rowsEl = []; foreach ($xp->query('//m:sheetData/m:row') as $r) $rowsEl[] = $r;
    // سرستون‌ها: ردیفی که «شماره بیمه‌نامه» دارد
    $hdrRow = null; $cols = [];
    foreach (array_slice($rowsEl, 0, 12) as $r) {
        $map = [];
        foreach ($xp->query('m:c', $r) as $c) { if (!preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $cm)) continue; $h = recon_hnorm($val($c)); if ($h !== '' && !isset($map[$h])) $map[$h] = $cm[1]; }
        if (isset($map[recon_hnorm('شماره بیمه‌نامه')])) { $hdrRow = $r; $cols = $map; break; }
    }
    if (!$hdrRow) { $zip->close(); @unlink($tmp); return ['ok' => false, 'error' => 'ستونِ «شماره بیمه‌نامه» در اکسل پیدا نشد.']; }
    $C = function ($name) use ($cols) { return $cols[recon_hnorm($name)] ?? null; };
    $colPol = $C('شماره بیمه‌نامه');

    // اطلاعاتِ سایت (بعد از اصلاح‌ها) + دارنده‌ی معرفی‌نامه برای بیمه‌نامه‌های کارکنان + تصمیم‌های همین مغایرت‌گیری
    $site = recon_site_index($pdo);
    $person = [];
    $pids = []; foreach ($site as $x) if ($x['source'] === 'P') $pids[] = (int)$x['ref_id'];
    if ($pids) {
        $q = $pdo->query("SELECT pc.id, per.full_name, per.national_code, per.personnel_code FROM policy_cases pc JOIN persons per ON per.id = pc.person_id WHERE pc.id IN (" . implode(',', array_unique($pids)) . ")");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $person[(int)$r['id']] = $r;
    }
    $resolved = [];
    $openDiffs = 0;   // مغایرت‌هایی که هنوز تصمیمی برایشان گرفته نشده (در خروجی همان مقدارِ بیمه‌گر می‌مانند)
    $q = $pdo->prepare("SELECT policy_key, diffs, status FROM recon_items WHERE run_id = ? AND diffs IS NOT NULL");
    $q->execute([$runId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $it) foreach (json_decode((string)$it['diffs'], true) ?: [] as $d) {
        if (!empty($d['res'])) $resolved[$it['policy_key']][$d['f']] = $d['res'];
        elseif ($it['status'] === 'OPEN' && ($d['f'] ?? '') !== 'endo') $openDiffs++;
    }

    $setCell = function ($rowEl, $col, $value) use ($doc, $xp, $NS) {
        $rn = $rowEl->getAttribute('r');
        $ref = $col . $rn;
        $cell = null; $before = null;
        foreach ($xp->query('m:c', $rowEl) as $c) {
            $cr = $c->getAttribute('r');
            if ($cr === $ref) { $cell = $c; break; }
            if (preg_match('/^([A-Z]+)/', $cr, $cm) && recon_col_idx($cm[1]) > recon_col_idx($col) && !$before) $before = $c;
        }
        if (!$cell) { $cell = $doc->createElementNS($NS, 'c'); $cell->setAttribute('r', $ref); $before ? $rowEl->insertBefore($cell, $before) : $rowEl->appendChild($cell); }
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
        $cell->setAttribute('t', 'inlineStr');
        $is = $doc->createElementNS($NS, 'is'); $t = $doc->createElementNS($NS, 't');
        $t->setAttribute('xml:space', 'preserve'); $t->appendChild($doc->createTextNode((string)$value));
        $is->appendChild($t); $cell->appendChild($is);
    };
    $changes = []; $stat = ['rows' => 0, 'matched' => 0, 'payer' => 0, 'fixed' => 0];
    $hdrIdx = (int)$hdrRow->getAttribute('r');
    foreach ($rowsEl as $r) {
        $rn = (int)$r->getAttribute('r');
        if ($rn <= $hdrIdx) continue;
        $cells = [];
        foreach ($xp->query('m:c', $r) as $c) if (preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $cm)) $cells[$cm[1]] = $c;
        $pol = isset($cells[$colPol]) ? trim($val($cells[$colPol])) : '';
        if ($pol === '') continue;
        $stat['rows']++;
        $key = endo_policy_key($pol);
        $s = $site[$key] ?? null;
        if (!$s) continue;
        $stat['matched']++;
        $want = [];
        if ($s['source'] === 'P' && isset($person[(int)$s['ref_id']])) {
            $pr = $person[(int)$s['ref_id']];
            [$fn, $ln] = recon_split_name($pr['full_name']);
            $pc = trim((string)$pr['personnel_code']);
            if ($pc !== '') { $want['کد پرسنلی'] = $pc; $want['کد پرسنلی پرداخت کننده'] = $pc; }
            if ($fn !== '') $want['نام پرداخت کننده'] = $fn;
            if ($ln !== '') $want['نام خانوادگی پرداخت کننده'] = $ln;
            if (trim((string)$pr['national_code']) !== '') $want['کد ملی پرداخت کننده'] = trim((string)$pr['national_code']);
        }
        $payerKeys = array_keys($want);
        foreach ($resolved[$key] ?? [] as $fld => $res) {
            switch ($fld) {
                case 'issue': if ($s['issue'] !== '') $want['تاریخ صدور'] = $s['issue']; break;
                case 'start': if ($s['start'] !== '') $want['تاریخ شروع'] = $s['start']; break;
                case 'end': if ($s['end'] !== '') $want['تاریخ انقضا'] = $s['end']; break;
                case 'premium': if ((int)$s['premium'] > 0) { $want['حق بیمه'] = (string)(int)$s['premium']; $want['حق بیمه به عدد'] = number_format((int)$s['premium']); } break;
                case 'plate':
                    $sample = ($cp = $C('شماره انتظامی')) && isset($cells[$cp]) ? $val($cells[$cp]) : '';
                    if (($pt = recon_plate_insurer($s['plate'], $sample)) !== '') $want['شماره انتظامی'] = $pt;
                    break;
                case 'vin': if (trim((string)$s['vin_text']) !== '') $want['شماره شاسی'] = trim((string)$s['vin_text']); break;
                case 'insured': if (trim((string)$s['insured']) !== '') $want['بیمه گذار'] = trim((string)$s['insured']); break;
            }
        }
        $rowChanged = false;
        foreach ($want as $hdr => $v) {
            $col = $C($hdr);
            if (!$col) continue;
            $old = isset($cells[$col]) ? trim($val($cells[$col])) : '';
            $cmp = function ($x) { return preg_replace('/[\s,٬]+/u', '', p2e_digits(str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$x))); };
            if ($cmp($old) === $cmp($v)) continue;
            $setCell($r, $col, $v);
            $changes[] = ['row' => $rn, 'policy' => $pol, 'col' => $hdr, 'old' => $old, 'new' => (string)$v, 'payer' => in_array($hdr, $payerKeys, true)];
            $rowChanged = true;
            $stat[in_array($hdr, $payerKeys, true) ? 'payer' : 'fixed']++;
        }
    }
    $name = recon_export_label($f, (string)$run['title']) . '.xlsx';
    $stat['open'] = $openDiffs;
    if ($dry) { $zip->close(); @unlink($tmp); return ['ok' => true, 'name' => $name, 'stats' => $stat, 'changes' => array_slice($changes, 0, 1000), 'total_changes' => count($changes)]; }
    $zip->addFromString($sheetPath, $doc->saveXML());
    $zip->close();
    return ['ok' => true, 'path' => $tmp, 'name' => $name, 'stats' => $stat];
}
