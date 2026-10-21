<?php
// فایل: api/_personnel.php
// «پرونده‌ی پرسنل»: هر شخص (جدولِ persons) با شرکتِ فعلی، سابقه‌ی نقل‌وانتقال، عکس، مشخصاتِ تکمیلی، اعضای خانواده،
// خودروها و بیمه‌نامه‌های اتومبیل (کارکنان + بردرو)، بیمه‌ی عمر، درمانِ تکمیلی، اقساطِ معوق، مدارک و معرفی‌نامه‌ها.
// ورودِ «لیستِ پرسنلی» از اکسل: هر کس در کدام شرکت است؛ تغییرِ شرکت => رکوردِ انتقال با تاریخ؛ نفرِ تازه => ساختِ شخص.
// نیاز: _case_helpers.php، _company_helpers.php، finance_core.php، _import_archive.php، _bordereau.php، _vehicles.php

function per_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS per_profiles (person_id INT NOT NULL PRIMARY KEY, photo_path VARCHAR(400) NULL, father_name VARCHAR(120) NULL, birth_date VARCHAR(10) NULL,
            gender VARCHAR(10) NULL, job_title VARCHAR(150) NULL, hire_date VARCHAR(10) NULL, phone2 VARCHAR(40) NULL, address VARCHAR(600) NULL, postal_code VARCHAR(12) NULL,
            note TEXT NULL, updated_by VARCHAR(190) NULL, updated_at DATETIME NULL)$T",
        "CREATE TABLE IF NOT EXISTS per_transfers (id INT AUTO_INCREMENT PRIMARY KEY, person_id INT NOT NULL, from_company_id INT NULL, to_company_id INT NULL,
            from_name VARCHAR(190) NULL, to_name VARCHAR(190) NULL, date_j VARCHAR(10) NULL, source VARCHAR(10) NOT NULL DEFAULT 'MANUAL', batch_id INT NULL, note VARCHAR(500) NULL,
            created_by_name VARCHAR(190) NULL, created_at DATETIME NOT NULL, KEY idx_person (person_id), KEY idx_batch (batch_id))$T",
        "CREATE TABLE IF NOT EXISTS per_family (id INT AUTO_INCREMENT PRIMARY KEY, person_id INT NOT NULL, full_name VARCHAR(190) NOT NULL, relation VARCHAR(40) NULL,
            national_code VARCHAR(12) NULL, birth_date VARCHAR(10) NULL, mobile VARCHAR(30) NULL, note VARCHAR(300) NULL, created_at DATETIME NOT NULL, KEY idx_person (person_id))$T",
        "CREATE TABLE IF NOT EXISTS per_roster_batches (id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(255) NULL, date_j VARCHAR(10) NULL, rows_total INT NOT NULL DEFAULT 0,
            rows_new INT NOT NULL DEFAULT 0, rows_moved INT NOT NULL DEFAULT 0, rows_same INT NOT NULL DEFAULT 0, created_by_name VARCHAR(190) NULL, created_at DATETIME NOT NULL)$T",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[per_ensure] ' . $e->getMessage()); } }
}
function per_photo_dir() { $d = __DIR__ . '/data/person_photos'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
const PER_SRC_FA = ['MANUAL' => 'ثبتِ دستی', 'ROSTER' => 'لیستِ پرسنلی', 'EDIT' => 'ویرایشِ پرونده'];

// اقساطِ معوقِ بیمه‌ی عمر: پرداخت = تسویه‌شده یا بیشترِ وصولِ اکسل و پرداخت‌های ثبت‌شده
function per_life_paid_sql($a = 'i') { return "(CASE WHEN $a.cleared = 1 THEN $a.amount ELSE LEAST($a.amount, GREATEST($a.excel_collected, $a.paid_amount)) END)"; }

// فهرستِ همه‌ی اشخاص با شمارنده‌ها
function per_list($pdo, array $f = []) {
    per_ensure($pdo);
    $rows = $pdo->query("SELECT p.id, p.full_name, p.national_code, p.personnel_code, p.mobile_number, p.company_id, c.name AS company_name, p.bale_chat_id IS NOT NULL AS on_bot
                           FROM persons p LEFT JOIN companies c ON c.id = p.company_id ORDER BY p.full_name")->fetchAll();
    $cnt = function ($sql) use ($pdo) { try { return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR)); } catch (Throwable $e) { return []; } };
    $auto = $cnt("SELECT person_id, COUNT(*) FROM policy_cases WHERE status = 'ISSUED' GROUP BY person_id");
    $cars = $cnt("SELECT person_id, COUNT(DISTINCT COALESCE(NULLIF(plate, ''), chassis_num, vin)) FROM policy_cases WHERE COALESCE(status, '') NOT IN ('WITHDRAWN', 'CANCELLED') GROUP BY person_id");
    $over = $cnt("SELECT pc.person_id, COUNT(*) FROM policy_installments i JOIN policy_cases pc ON pc.id = i.case_id
                   WHERE i.due_date < CURDATE() AND (SELECT COALESCE(SUM(a.amount), 0) FROM payment_allocations a WHERE a.installment_id = i.id) < i.amount GROUP BY pc.person_id");
    $life = $cnt("SELECT holder_nid, COUNT(*) FROM life_policies WHERE holder_nid IS NOT NULL GROUP BY holder_nid");
    $lifeI = $cnt("SELECT insured_nid, COUNT(*) FROM life_policies WHERE insured_nid IS NOT NULL AND insured_nid <> COALESCE(holder_nid, '') GROUP BY insured_nid");
    $lifeOver = $cnt("SELECT p.holder_nid, COUNT(*) FROM life_installments i JOIN life_policies p ON p.id = i.policy_id WHERE i.due_g < CURDATE() AND " . per_life_paid_sql() . " < i.amount GROUP BY p.holder_nid");
    $health = $cnt("SELECT nid, COUNT(*) FROM hl_members WHERE nid IS NOT NULL GROUP BY nid");
    $brd = $cnt("SELECT insured_id, COUNT(*) FROM brd_rows WHERE insured_id IS NOT NULL GROUP BY insured_id");
    $photos = $cnt("SELECT person_id, 1 FROM per_profiles WHERE photo_path IS NOT NULL");
    $lastT = [];
    try { foreach ($pdo->query("SELECT t.person_id, t.date_j, t.to_name FROM per_transfers t JOIN (SELECT person_id, MAX(id) mid FROM per_transfers GROUP BY person_id) x ON x.mid = t.id") as $t) $lastT[(int)$t['person_id']] = $t; } catch (Throwable $e) {}
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id']; $nid = (string)$r['national_code'];
        $out[] = ['id' => $id, 'name' => $r['full_name'], 'nid' => $nid, 'pcode' => $r['personnel_code'], 'mobile' => $r['mobile_number'], 'company_id' => $r['company_id'] ? (int)$r['company_id'] : null,
                  'company' => $r['company_name'], 'on_bot' => (bool)$r['on_bot'], 'auto' => ($auto[$id] ?? 0) + ($brd[$nid] ?? 0), 'cars' => $cars[$id] ?? 0,
                  'life' => ($life[$nid] ?? 0) + ($lifeI[$nid] ?? 0), 'health' => $health[$nid] ?? 0, 'overdue' => ($over[$id] ?? 0) + ($lifeOver[$nid] ?? 0),
                  'photo' => !empty($photos[$id]), 'last_transfer' => isset($lastT[$id]) ? ['date' => $lastT[$id]['date_j'], 'to' => $lastT[$id]['to_name']] : null];
    }
    return $out;
}

function per_company_name($pdo, $id) {
    if (!$id) return null;
    $st = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
    $st->execute([intval($id)]);
    return $st->fetchColumn() ?: null;
}
// انتقال به شرکتِ دیگر (persons.company_id هم عوض می‌شود)
function per_transfer($pdo, $personId, $toCompanyId, $dateJ, $source, $note, $byName, $batchId = null) {
    $st = $pdo->prepare("SELECT id, company_id FROM persons WHERE id = ?");
    $st->execute([intval($personId)]);
    $p = $st->fetch();
    if (!$p) return ['ok' => false, 'error' => 'شخص پیدا نشد.'];
    $from = $p['company_id'] ? (int)$p['company_id'] : null; $to = $toCompanyId ? (int)$toCompanyId : null;
    if ($from === $to) return ['ok' => false, 'error' => 'شرکتِ مقصد همان شرکتِ فعلی است.'];
    $pdo->prepare("INSERT INTO per_transfers (person_id, from_company_id, to_company_id, from_name, to_name, date_j, source, batch_id, note, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$p['id'], $from, $to, per_company_name($pdo, $from), per_company_name($pdo, $to), imp_jdate($dateJ) ?: jalali_from_gregorian_ts_dotted(time()), $source, $batchId,
                   mb_substr(trim((string)$note), 0, 500) ?: null, mb_substr((string)$byName, 0, 190)]);
    $pdo->prepare("UPDATE persons SET company_id = ? WHERE id = ?")->execute([$to, $p['id']]);
    return ['ok' => true];
}

// پرونده‌ی کامل
function per_detail($pdo, $id) {
    per_ensure($pdo);
    $st = $pdo->prepare("SELECT p.*, c.name AS company_name FROM persons p LEFT JOIN companies c ON c.id = p.company_id WHERE p.id = ?");
    $st->execute([intval($id)]);
    $p = $st->fetch();
    if (!$p) return null;
    $nid = (string)$p['national_code'];
    $prof = $pdo->prepare("SELECT * FROM per_profiles WHERE person_id = ?"); $prof->execute([$p['id']]); $prof = $prof->fetch() ?: [];
    $saved = json_decode((string)($p['saved_profile'] ?? ''), true) ?: [];
    $root = dirname(__DIR__);
    $rel = function ($x) use ($root) { return $x ? ltrim(str_replace($root, '', (string)$x), '/') : null; };
    // نقل‌وانتقال
    $tr = $pdo->prepare("SELECT * FROM per_transfers WHERE person_id = ? ORDER BY COALESCE(date_j, ''), id");
    $tr->execute([$p['id']]);
    $transfers = array_map(function ($t) { return ['id' => (int)$t['id'], 'from' => $t['from_name'], 'to' => $t['to_name'], 'date' => $t['date_j'], 'source' => $t['source'],
                                                   'source_fa' => PER_SRC_FA[$t['source']] ?? $t['source'], 'note' => $t['note'], 'by' => $t['created_by_name']]; }, $tr->fetchAll());
    // خانواده: ثبت‌شده + از بیمه‌نامه‌های کارکنان (بستگان) + افرادِ تحتِ تکفل در درمان
    $fam = $pdo->prepare("SELECT * FROM per_family WHERE person_id = ? ORDER BY id"); $fam->execute([$p['id']]);
    $family = array_map(function ($f) { return ['id' => (int)$f['id'], 'name' => $f['full_name'], 'relation' => $f['relation'], 'nid' => $f['national_code'], 'birth' => $f['birth_date'],
                                                'mobile' => $f['mobile'], 'note' => $f['note'], 'src' => 'دستی']; }, $fam->fetchAll());
    $seen = []; foreach ($family as $f) if ($f['nid']) $seen[$f['nid']] = 1;
    try {
        $st = $pdo->prepare("SELECT DISTINCT insured_name, insured_national_id, insured_relationship FROM policy_cases WHERE person_id = ? AND insured_relationship IS NOT NULL AND insured_relationship NOT IN ('', 'خودم') AND insured_name IS NOT NULL");
        $st->execute([$p['id']]);
        foreach ($st->fetchAll() as $f) { $k = (string)$f['insured_national_id']; if ($k !== '' && isset($seen[$k])) continue; $seen[$k] = 1;
            $family[] = ['id' => null, 'name' => $f['insured_name'], 'relation' => $f['insured_relationship'], 'nid' => $f['insured_national_id'], 'birth' => '', 'mobile' => '', 'note' => '', 'src' => 'بیمه‌ی اتومبیل']; }
    } catch (Throwable $e) {}
    if ($nid !== '') try {
        $st = $pdo->prepare("SELECT first_name, last_name, nid, relation, birth_j FROM hl_members WHERE main_nid = ? AND COALESCE(nid, '') <> ?");
        $st->execute([$nid, $nid]);
        foreach ($st->fetchAll() as $f) { $k = (string)$f['nid']; if ($k !== '' && isset($seen[$k])) continue; $seen[$k] = 1;
            $family[] = ['id' => null, 'name' => trim($f['first_name'] . ' ' . $f['last_name']), 'relation' => $f['relation'], 'nid' => $f['nid'], 'birth' => $f['birth_j'], 'mobile' => '', 'note' => '', 'src' => 'درمانِ تکمیلی']; }
    } catch (Throwable $e) {}
    // خودروها و بیمه‌ی اتومبیل
    $recs = vh_records($pdo);
    $mine = function ($r) use ($p, $nid) { return (!empty($r['person_id']) && (int)$r['person_id'] === (int)$p['id']) || ($nid !== '' && (string)$r['insured_id'] === $nid); };
    $vehicles = []; $autoPol = [];
    foreach (vh_group($recs) as $idx) {
        $rs = array_map(function ($i) use ($recs) { return $recs[$i]; }, $idx);
        $hits = array_values(array_filter($rs, $mine));
        if (!$hits) continue;
        $vehicles[] = vh_summary($pdo, $rs);
        foreach ($hits as $r) if ($r['issued']) $autoPol[] = ['src' => $r['src'], 'type_fa' => insurance_type_fa($r['type']), 'policy' => $r['policy'], 'plate' => $r['plate'], 'car' => $r['car'],
                                                              'issue' => $r['issue_date'], 'end' => $r['end_date'], 'end_est' => $r['end_est'], 'days_left' => $r['days_left'], 'premium' => $r['premium'], 'file' => $r['file'],
                                                              'vkey' => $r['vin'] !== '' ? 'v:' . $r['vin'] : 'p:' . $r['plate_core']];
    }
    usort($autoPol, function ($a, $b) { return strcmp((string)$b['issue'], (string)$a['issue']); });
    // اقساطِ بیمه‌ی اتومبیلِ کارکنان
    $inst = [];
    try {
        $st = $pdo->prepare("SELECT i.inst_number, i.amount, i.due_jalali, i.due_date, pc.policy_number, pc.plate, (SELECT COALESCE(SUM(a.amount), 0) FROM payment_allocations a WHERE a.installment_id = i.id) AS paid
                               FROM policy_installments i JOIN policy_cases pc ON pc.id = i.case_id WHERE pc.person_id = ? ORDER BY i.due_date");
        $st->execute([$p['id']]);
        foreach ($st->fetchAll() as $i) {
            $amt = (int)$i['amount']; $paid = (int)$i['paid']; $over = $paid < $amt && $i['due_date'] && strtotime($i['due_date']) < strtotime('today');
            $inst[] = ['line' => 'اتومبیل', 'policy' => $i['policy_number'], 'ref' => $i['plate'], 'n' => (int)$i['inst_number'], 'due' => $i['due_jalali'], 'amount' => $amt, 'paid' => $paid,
                       'state' => $paid >= $amt && $amt > 0 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : ($over ? 'OVERDUE' : 'UNPAID')), 'delay' => $over ? (int)floor((strtotime('today') - strtotime($i['due_date'])) / 86400) : 0];
        }
    } catch (Throwable $e) {}
    // بیمه‌ی عمر
    $life = [];
    if ($nid !== '') try {
        $st = $pdo->prepare("SELECT * FROM life_policies WHERE holder_nid = ? OR insured_nid = ? ORDER BY issue_g DESC");
        $st->execute([$nid, $nid]);
        foreach ($st->fetchAll() as $l) {
            $is = $pdo->prepare("SELECT i.inst_no, i.amount, i.due_j, i.due_g, " . per_life_paid_sql() . " AS paid FROM life_installments i WHERE i.policy_id = ? ORDER BY i.inst_no");
            $is->execute([$l['id']]);
            $over = 0; $rem = 0;
            foreach ($is->fetchAll() as $i) {
                $amt = (int)$i['amount']; $paid = (int)$i['paid']; $o = $paid < $amt && $i['due_g'] && strtotime($i['due_g']) < strtotime('today');
                if ($o) { $over++; $inst[] = ['line' => 'عمر', 'policy' => $l['policy_no'], 'ref' => '', 'n' => (int)$i['inst_no'], 'due' => $i['due_j'], 'amount' => $amt, 'paid' => $paid, 'state' => 'OVERDUE',
                                              'delay' => (int)floor((strtotime('today') - strtotime($i['due_g'])) / 86400)]; }
                if ($i['due_g'] && strtotime($i['due_g']) < strtotime('today')) $rem += max(0, $amt - $paid);
            }
            $life[] = ['policy' => $l['policy_no'], 'holder' => $l['holder_name'], 'insured' => $l['insured_name'], 'role' => $l['holder_nid'] === $nid ? 'بیمه‌گذار' : 'بیمه‌شده',
                       'plan' => trim(($l['contract_name'] ?? '') . ' ' . ($l['variant'] ?? '')), 'issue' => $l['issue_j'], 'start' => $l['start_j'], 'end' => $l['end_j'], 'status' => $l['policy_status'],
                       'overdue' => $over, 'overdue_amount' => $rem, 'folder' => $rel($l['archive_dir'])];
        }
    } catch (Throwable $e) {}
    // درمانِ تکمیلی
    $health = [];
    if ($nid !== '') try {
        $st = $pdo->prepare("SELECT m.*, c.title, c.policy_no, c.company_name FROM hl_members m LEFT JOIN hl_contracts c ON c.id = m.contract_id WHERE m.nid = ? OR m.main_nid = ? ORDER BY m.start_g DESC");
        $st->execute([$nid, $nid]);
        foreach ($st->fetchAll() as $m) $health[] = ['contract' => $m['title'] ?: $m['company_name'], 'policy' => $m['policy_no'], 'name' => trim($m['first_name'] . ' ' . $m['last_name']),
                                                    'relation' => $m['relation'], 'plan' => $m['plan'], 'start' => $m['start_j'], 'end' => $m['end_j'], 'premium' => (int)$m['premium'],
                                                    'self' => (string)$m['nid'] === $nid];
    } catch (Throwable $e) {}
    // مدارک: مدارکِ پرونده‌های کارکنان، معرفی‌نامه‌ها، فایلِ بیمه‌نامه‌ها
    $docs = [];
    try {
        $st = $pdo->prepare("SELECT d.*, pc.policy_number, pc.plate FROM case_documents d JOIN policy_cases pc ON pc.id = d.case_id WHERE pc.person_id = ? ORDER BY d.id DESC");
        $st->execute([$p['id']]);
        foreach ($st->fetchAll() as $d) if ($d['file_path']) $docs[] = ['label' => ($d['doc_label'] ?: $d['doc_key']) . ($d['plate'] ? ' · ' . $d['plate'] : ''), 'file' => $rel($d['file_path']), 'name' => basename((string)$d['file_path'])];
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT * FROM introductions WHERE person_id = ? ORDER BY id DESC");
        $st->execute([$p['id']]);
        foreach ($st->fetchAll() as $d) {
            $fp = (string)$d['file_path'];
            if ($fp !== '' && strpos($fp, '/') !== false && is_file($root . '/' . ltrim($rel($fp), '/'))) $docs[] = ['label' => 'معرفی‌نامه' . ($d['letter_date'] ? ' ' . $d['letter_date'] : ''), 'file' => $rel($fp), 'name' => basename($fp)];
        }
    } catch (Throwable $e) {}
    foreach ($autoPol as $a) if ($a['file']) $docs[] = ['label' => 'بیمه‌نامه‌ی ' . $a['type_fa'] . ' ' . $a['policy'], 'file' => $a['file'], 'name' => basename($a['file'])];
    $person = ['id' => (int)$p['id'], 'name' => $p['full_name'], 'nid' => $nid, 'pcode' => $p['personnel_code'], 'mobile' => $p['mobile_number'], 'company_id' => $p['company_id'] ? (int)$p['company_id'] : null,
               'company' => $p['company_name'], 'on_bot' => !empty($p['bale_chat_id']), 'photo' => !empty($prof['photo_path']) && is_file($prof['photo_path']),
               'father' => $prof['father_name'] ?? '', 'birth' => ($prof['birth_date'] ?? '') ?: ($saved['birth_date'] ?? ''), 'gender' => $prof['gender'] ?? '', 'job' => $prof['job_title'] ?? '',
               'hire' => $prof['hire_date'] ?? '', 'phone2' => ($prof['phone2'] ?? '') ?: ($saved['phone'] ?? ''), 'address' => ($prof['address'] ?? '') ?: ($saved['address'] ?? ''),
               'postal' => ($prof['postal_code'] ?? '') ?: ($saved['postal_code'] ?? ''), 'note' => $prof['note'] ?? ''];
    usort($inst, function ($a, $b) { return ($a['state'] === 'OVERDUE' ? 0 : 1) <=> ($b['state'] === 'OVERDUE' ? 0 : 1) ?: strcmp((string)$a['due'], (string)$b['due']); });
    return ['person' => $person, 'transfers' => $transfers, 'family' => $family, 'vehicles' => $vehicles, 'tpl_svg' => function_exists('vh_svg_templates') ? json_encode(vh_svg_templates()) : '', 'auto' => $autoPol, 'life' => $life, 'health' => $health,
            'installments' => $inst, 'docs' => $docs];
}

// ذخیره‌ی پرونده (persons + per_profiles)؛ عوض شدنِ شرکت => رکوردِ انتقال
function per_save($pdo, $id, array $d, $byName) {
    $st = $pdo->prepare("SELECT * FROM persons WHERE id = ?");
    $st->execute([intval($id)]);
    $p = $st->fetch();
    if (!$p) return ['ok' => false, 'error' => 'شخص پیدا نشد.'];
    $name = trim((string)($d['name'] ?? $p['full_name']));
    if ($name === '') return ['ok' => false, 'error' => 'نام را وارد کنید.'];
    $pdo->prepare("UPDATE persons SET full_name = ?, personnel_code = ?, mobile_number = ? WHERE id = ?")
        ->execute([mb_substr($name, 0, 190), mb_substr(p2e_digits(trim((string)($d['pcode'] ?? $p['personnel_code']))), 0, 40) ?: null,
                   mb_substr(p2e_digits(trim((string)($d['mobile'] ?? $p['mobile_number']))), 0, 20) ?: null, $p['id']]);
    $f = function ($k, $len) use ($d) { $v = trim(p2e_digits((string)($d[$k] ?? ''))); return $v === '' ? null : mb_substr($v, 0, $len); };
    $pdo->prepare("INSERT INTO per_profiles (person_id, father_name, birth_date, gender, job_title, hire_date, phone2, address, postal_code, note, updated_by, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE father_name = VALUES(father_name), birth_date = VALUES(birth_date), gender = VALUES(gender), job_title = VALUES(job_title),
                   hire_date = VALUES(hire_date), phone2 = VALUES(phone2), address = VALUES(address), postal_code = VALUES(postal_code), note = VALUES(note),
                   updated_by = VALUES(updated_by), updated_at = NOW()")
        ->execute([$p['id'], $f('father', 120), imp_jdate($d['birth'] ?? '') ?: null, $f('gender', 10), $f('job', 150), imp_jdate($d['hire'] ?? '') ?: null, $f('phone2', 40),
                   $f('address', 600), $f('postal', 12), trim((string)($d['note'] ?? '')) ?: null, mb_substr((string)$byName, 0, 190)]);
    if (array_key_exists('company_id', $d) && intval($d['company_id'] ?: 0) !== intval($p['company_id'] ?: 0)) {
        $r = per_transfer($pdo, $p['id'], intval($d['company_id']) ?: null, $d['transfer_date'] ?? '', 'EDIT', $d['transfer_note'] ?? '', $byName);
        if (!$r['ok']) return $r;
    }
    return ['ok' => true];
}

// ---------------- لیستِ پرسنلی (اکسل) ----------------
function per_roster_cols() {
    return ['nid' => ['کد ملی', 'کدملی', 'شماره ملی', 'national code'], 'name' => ['نام و نام خانوادگی', 'نام ونام خانوادگی', 'نام کامل', 'نام کارمند', 'نام پرسنل'],
            'first' => ['نام'], 'last' => ['نام خانوادگی', 'نام خانوادگي', 'فامیلی'], 'pcode' => ['کد پرسنلی', 'شماره پرسنلی', 'کدپرسنلی'],
            'company' => ['شرکت', 'نام شرکت', 'محل خدمت', 'محل کار', 'واحد'], 'mobile' => ['موبایل', 'تلفن همراه', 'شماره همراه', 'شماره موبایل', 'همراه']];
}
function per_nid_ok($c) {
    if (!preg_match('/^\d{10}$/', $c) || preg_match('/^(\d)\1{9}$/', $c)) return false;
    $s = 0; for ($i = 0; $i < 9; $i++) $s += intval($c[$i]) * (10 - $i);
    $r = $s % 11;
    return intval($c[9]) === ($r < 2 ? $r : 11 - $r);
}
// پیش‌نمایش: هر ردیف => NEW (شخصِ تازه) | MOVE (انتقال) | SAME (همان شرکت) | ERR
function per_roster_parse($pdo, $path, $defaultCompanyId) {
    $table = fin_read_spreadsheet($path);
    if (!$table || count($table) < 2) return ['error' => 'فایل خوانده نشد یا ردیفی ندارد.'];
    $lookup = [];
    foreach (per_roster_cols() as $k => $names) foreach ($names as $n) $lookup[company_normalize_header($n)] = $k;
    $hi = -1; $cols = [];
    foreach ($table as $i => $row) {
        $found = [];
        foreach ((array)$row as $c => $cell) { $nh = company_normalize_header($cell); if ($nh !== '' && isset($lookup[$nh]) && !in_array($lookup[$nh], $found, true)) $found[$c] = $lookup[$nh]; }
        if (in_array('nid', $found, true)) { $hi = $i; $cols = $found; break; }
        if ($i > 10) break;
    }
    if ($hi < 0) return ['error' => 'ستونِ «کد ملی» پیدا نشد (ستون‌ها: کد ملی، نام و نام خانوادگی، کد پرسنلی، شرکت، موبایل).'];
    $cidx = brd_company_index($pdo);
    $defName = per_company_name($pdo, $defaultCompanyId);
    $byNid = $pdo->prepare("SELECT p.id, p.full_name, p.company_id, c.name AS company_name FROM persons p LEFT JOIN companies c ON c.id = p.company_id WHERE p.national_code = ? LIMIT 1");
    $out = []; $seen = [];
    foreach (array_slice($table, $hi + 1) as $li => $cells) {
        $r = [];
        foreach ($cols as $c => $k) $r[$k] = trim(str_replace(['ي', 'ك'], ['ی', 'ک'], (string)($cells[$c] ?? '')));
        if (!array_filter($r, 'strlen')) continue;
        $line = $hi + $li + 2;
        $nid = preg_replace('/\D/', '', p2e_digits($r['nid'] ?? ''));
        if (strlen($nid) >= 8 && strlen($nid) < 10) $nid = str_pad($nid, 10, '0', STR_PAD_LEFT);   // صفرهای اولِ کد ملی که اکسل انداخته
        $name = ($r['name'] ?? '') ?: trim(($r['first'] ?? '') . ' ' . ($r['last'] ?? ''));
        $row = ['line' => $line, 'nid' => $nid, 'name' => $name, 'pcode' => p2e_digits($r['pcode'] ?? ''), 'mobile' => p2e_digits($r['mobile'] ?? ''), 'errors' => []];
        if (!per_nid_ok($nid)) $row['errors'][] = 'کد ملی نامعتبر است.';
        if (isset($seen[$nid])) $row['errors'][] = 'تکراری (ردیفِ ' . fa_digits($seen[$nid]) . ').';
        $seen[$nid] = $line;
        // شرکت: ستونِ شرکت (با نام/شناسه) یا شرکتِ انتخاب‌شده در فرم
        $cid = null; $cname = trim((string)($r['company'] ?? ''));
        if ($cname !== '') { $cid = $cidx['name'][brd_norm($cname)] ?? null; if (!$cid) $row['errors'][] = 'شرکتِ «' . $cname . '» در سامانه پیدا نشد.'; }
        else { $cid = $defaultCompanyId ? intval($defaultCompanyId) : null; $cname = (string)$defName; if (!$cid) $row['errors'][] = 'شرکت مشخص نیست (ستونِ «شرکت» یا شرکتِ پیش‌فرض را انتخاب کنید).'; }
        $row['to_id'] = $cid; $row['to'] = $cid ? per_company_name($pdo, $cid) : $cname;
        $byNid->execute([$nid]);
        $p = $byNid->fetch();
        if ($p) { $row['person_id'] = (int)$p['id']; $row['from_id'] = $p['company_id'] ? (int)$p['company_id'] : null; $row['from'] = $p['company_name']; if ($name === '') $row['name'] = $p['full_name']; }
        else { $row['person_id'] = null; $row['from_id'] = null; $row['from'] = null; if ($name === '') $row['errors'][] = 'شخصِ تازه است و نام ندارد.'; }
        $row['action'] = $row['errors'] ? 'ERR' : (!$p ? 'NEW' : ((int)$row['from_id'] === (int)$cid ? 'SAME' : 'MOVE'));
        $out[] = $row;
    }
    if (!$out) return ['error' => 'زیرِ سرستون‌ها ردیفی پیدا نشد.'];
    return ['rows' => $out];
}
function per_roster_commit($pdo, array $rows, $fileName, $dateJ, $byName) {
    $dateJ = imp_jdate($dateJ) ?: jalali_from_gregorian_ts_dotted(time());
    $pdo->prepare("INSERT INTO per_roster_batches (file_name, date_j, rows_total, created_by_name, created_at) VALUES (?, ?, ?, ?, NOW())")
        ->execute([mb_substr((string)$fileName, 0, 250), $dateJ, count($rows), mb_substr((string)$byName, 0, 190)]);
    $bid = (int)$pdo->lastInsertId();
    $n = ['NEW' => 0, 'MOVE' => 0, 'SAME' => 0];
    foreach ($rows as $r) {
        if ($r['action'] === 'NEW') {
            $pdo->prepare("INSERT INTO persons (national_code, personnel_code, full_name, company_id, mobile_number) VALUES (?, ?, ?, ?, ?)")
                ->execute([$r['nid'], $r['pcode'] ?: null, mb_substr($r['name'], 0, 190), $r['to_id'], $r['mobile'] ?: null]);
            $pid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO per_transfers (person_id, from_company_id, to_company_id, from_name, to_name, date_j, source, batch_id, note, created_by_name, created_at) VALUES (?, NULL, ?, NULL, ?, ?, 'ROSTER', ?, 'ثبتِ اولیه از لیستِ پرسنلی', ?, NOW())")
                ->execute([$pid, $r['to_id'], $r['to'], $dateJ, $bid, mb_substr((string)$byName, 0, 190)]);
            $n['NEW']++;
        } elseif ($r['action'] === 'MOVE') {
            per_transfer($pdo, $r['person_id'], $r['to_id'], $dateJ, 'ROSTER', 'از لیستِ پرسنلی', $byName, $bid);
            $n['MOVE']++;
        } elseif ($r['action'] === 'SAME') $n['SAME']++;
        // کد پرسنلی و موبایلِ تازه (اگر داده شده) روی شخصِ موجود
        if ($r['action'] !== 'NEW' && $r['action'] !== 'ERR' && $r['person_id']) {
            if ($r['pcode'] !== '') $pdo->prepare("UPDATE persons SET personnel_code = ? WHERE id = ?")->execute([$r['pcode'], $r['person_id']]);
            if ($r['mobile'] !== '') $pdo->prepare("UPDATE persons SET mobile_number = COALESCE(NULLIF(mobile_number, ''), ?) WHERE id = ?")->execute([$r['mobile'], $r['person_id']]);
        }
    }
    $pdo->prepare("UPDATE per_roster_batches SET rows_new = ?, rows_moved = ?, rows_same = ? WHERE id = ?")->execute([$n['NEW'], $n['MOVE'], $n['SAME'], $bid]);
    return ['ok' => true, 'batch_id' => $bid] + array_change_key_case($n, CASE_LOWER);
}

// PDFِ پرونده‌ی شخص
function per_report_pdf($pdo, array $d, $dest) {
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $p = $d['person'];
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12); $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->setRTL(true); $pdf->SetFont('vazir', '', 9);
    $pdf->AddPage();
    $e = function ($v) { return htmlspecialchars(fa_digits((string)$v), ENT_QUOTES, 'UTF-8'); };
    $pdf->SetFillColor(76, 29, 149); $pdf->Rect(0, 0, 210, 44, 'F');
    $pdf->SetFillColor(190, 24, 93); $pdf->Rect(0, 38, 210, 6, 'F');
    $st = $pdo->prepare("SELECT photo_path FROM per_profiles WHERE person_id = ?"); $st->execute([$p['id']]); $ph = $st->fetchColumn();
    $pdf->setRTL(false);
    if ($ph && is_file($ph)) { try { $pdf->Image($ph, 14, 5, 28, 30, '', '', '', true, 200, '', false, false, 1, 'CM'); } catch (Throwable $ex) {} }
    $pdf->setRTL(true);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->writeHTMLCell(150, 0, 48, 8, '<b style="font-size:17pt">پرونده‌ی پرسنل</b><br/><span style="font-size:13pt">' . $e($p['name']) . '</span><br/><span style="font-size:9.5pt">کد ملی ' . $e($p['nid'])
        . ($p['pcode'] ? ' · کد پرسنلی ' . $e($p['pcode']) : '') . ' · ' . $e($p['company'] ?: 'بدونِ شرکت') . '</span><br/><span style="font-size:8.5pt;color:#ddd6fe">تاریخِ گزارش: ' . $e(jalali_from_gregorian_ts_dotted(time())) . '</span>', 0, 1, false, true, 'R');
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetY(50);
    $sec = function ($title, $head, $rows) use ($e) {
        if (!$rows) return '';
        $w = round(100 / count($head), 2);
        $h = '<table cellpadding="3.5" style="font-size:8pt;"><tr><td colspan="' . count($head) . '" style="background-color:#f5f3ff;color:#5b21b6;font-weight:bold;font-size:10pt;">' . $title . '</td></tr><tr style="background-color:#f1f5f9;font-weight:bold;">';
        foreach ($head as $c) $h .= '<td width="' . $w . '%">' . $c . '</td>';
        $h .= '</tr>';
        foreach ($rows as $i => $r) { $h .= '<tr style="background-color:' . ($i % 2 ? '#f8fafc' : '#ffffff') . ';">'; foreach ($r as $c) $h .= '<td width="' . $w . '%">' . $e($c) . '</td>'; $h .= '</tr>'; }
        return $h . '</table><br/>';
    };
    $html = $sec('مشخصات', ['موبایل', 'نام پدر', 'تولد', 'سمت', 'استخدام'], [[$p['mobile'], $p['father'], $p['birth'], $p['job'], $p['hire']]]);
    $html .= $sec('نقل‌وانتقال', ['تاریخ', 'از', 'به', 'روش'], array_map(function ($t) { return [$t['date'], $t['from'] ?: '—', $t['to'] ?: '—', $t['source_fa']]; }, $d['transfers']));
    $html .= $sec('اعضای خانواده', ['نام', 'نسبت', 'کد ملی', 'منبع'], array_map(function ($f) { return [$f['name'], $f['relation'], $f['nid'], $f['src']]; }, $d['family']));
    $html .= $sec('خودروها', ['پلاک', 'خودرو', 'مدل', 'بدنه تا', 'ثالث تا'], array_map(function ($v) { return [$v['plate'] ?: $v['vin'], $v['car'], $v['model_year'], $v['body_end'], $v['third_end']]; }, $d['vehicles']));
    $html .= $sec('بیمه‌ی اتومبیل', ['نوع', 'شماره', 'پلاک', 'صدور', 'انقضا', 'حق بیمه'], array_map(function ($a) { return [$a['type_fa'], $a['policy'], $a['plate'], $a['issue'], $a['end'], $a['premium'] ? number_format($a['premium']) : '']; }, $d['auto']));
    $html .= $sec('بیمه‌ی عمر', ['شماره', 'نقش', 'طرح', 'شروع', 'پایان', 'معوق'], array_map(function ($l) { return [$l['policy'], $l['role'], $l['plan'], $l['start'], $l['end'], $l['overdue'] ? $l['overdue'] . ' قسط' : '—']; }, $d['life']));
    $html .= $sec('درمانِ تکمیلی', ['قرارداد', 'بیمه‌شده', 'نسبت', 'طرح', 'از', 'تا'], array_map(function ($h) { return [$h['contract'], $h['name'], $h['relation'], $h['plan'], $h['start'], $h['end']]; }, $d['health']));
    $over = array_values(array_filter($d['installments'], function ($i) { return $i['state'] === 'OVERDUE'; }));
    $html .= $sec('اقساطِ معوق', ['بیمه', 'بیمه‌نامه', 'قسط', 'سررسید', 'مبلغ', 'تأخیر (روز)'], array_map(function ($i) { return [$i['line'], $i['policy'], $i['n'], $i['due'], number_format(max(0, $i['amount'] - $i['paid'])), $i['delay']]; }, $over));
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($dest, 'F');
    return is_file($dest);
}
