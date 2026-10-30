<?php
// فایل: api/_md_bill.php
// «درمان و عمرِ گروهی» — حساب‌ها:
//   • حق‌بیمه و سهمِ شرکت/شخصِ هر نفر (از طرح، نرخِ اختصاصیِ شرکت و سهمِ درصدی یا مبلغِ ثابت)
//   • مغایرت‌گیری با «سند حقوق» (هر شیت یک شرکت؛ ستون‌ها از روی عنوان، با هر ستونی که هست): پیش از صورت‌حساب الزامی است.
//     شرکتی که در سند نبود => «در سند حقوق نبود» تا با سندِ بعدی بررسی شود؛ شرکتِ تکراری در سندِ بعدی رد می‌شود؛ ابطال و مغایرت‌گیریِ دوباره.
//     شیتِ هر شرکت بعد از مغایرت‌گیری به‌صورتِ یک اکسلِ جدا در پوشه‌ی همان شرکت و همان ماه بایگانی می‌شود.
//   • صورت‌حسابِ ماهانه‌ی هر شرکت و صورت‌حسابِ تجمیعی (شماره‌گذاری و قالبِ قابلِ تنظیم، PDF با سربرگ)
//   • دریافت‌ها (به ما یا مستقیم به بیمه‌گر)، تخصیص به صورت‌حساب‌ها، واریز به بیمه‌گر، صندوقِ رسیدهای ربات
//   • بایگانی، خروجیِ بازه‌ای با ستون‌های دلخواه، داشبورد، هشدارها و یادآورها

require_once __DIR__ . '/_md_lists.php';

const MD_RECON_STATUS = ['MISSING' => 'در سند حقوق نبود (مغایرت‌گیری نشده)', 'OPEN' => 'در حالِ بررسی', 'CONFIRMED' => 'تأییدشده', 'VOID' => 'ابطال‌شده'];
const MD_PAYEES = ['US' => 'به ما (کارگزاری)', 'INSURER' => 'مستقیم به بیمه‌گر'];

function md_bill_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    md_ops_ensure($pdo);
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS md_payrolls (id INT AUTO_INCREMENT PRIMARY KEY, period CHAR(7) NOT NULL, file_name VARCHAR(250) NULL, file_rel VARCHAR(600) NULL, result TEXT NULL,
            created_by INT NULL, created_name VARCHAR(190) NULL, created_at DATETIME NULL, KEY k_p (period)) $o",
        "CREATE TABLE IF NOT EXISTS md_payroll_alias (sheet VARCHAR(190) NOT NULL PRIMARY KEY, company_id INT NOT NULL) $o",
        "CREATE TABLE IF NOT EXISTS md_recon (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, period CHAR(7) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'OPEN',
            payroll_id INT NULL, sheet VARCHAR(190) NULL, cols TEXT NULL, rows_json LONGTEXT NULL, items LONGTEXT NULL, stats TEXT NULL, history TEXT NULL, file_rel VARCHAR(600) NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL, confirmed_at DATETIME NULL, confirmed_name VARCHAR(190) NULL, voided_at DATETIME NULL, voided_name VARCHAR(190) NULL, void_reason VARCHAR(400) NULL,
            KEY k_cp (company_id, period), KEY k_st (status)) $o",
        "CREATE TABLE IF NOT EXISTS md_bills (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, kind VARCHAR(8) NOT NULL, period CHAR(7) NOT NULL, no VARCHAR(40) NULL,
            issue_j CHAR(10) NULL, due_j CHAR(10) NULL, headcount INT NOT NULL DEFAULT 0, detail TEXT NULL, amount BIGINT NOT NULL DEFAULT 0, vat BIGINT NOT NULL DEFAULT 0, total BIGINT NOT NULL DEFAULT 0,
            employer BIGINT NOT NULL DEFAULT 0, employee BIGINT NOT NULL DEFAULT 0, status VARCHAR(8) NOT NULL DEFAULT 'ISSUED', pdf_rel VARCHAR(600) NULL, note VARCHAR(500) NULL,
            created_by INT NULL, created_name VARCHAR(190) NULL, created_at DATETIME NULL, voided_at DATETIME NULL, void_reason VARCHAR(400) NULL,
            KEY k_cp (company_id, kind, period), KEY k_st (status)) $o",
        "CREATE TABLE IF NOT EXISTS md_cbills (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(8) NOT NULL, period CHAR(7) NOT NULL, no VARCHAR(40) NULL, issue_j CHAR(10) NULL, title VARCHAR(250) NULL,
            to_company_id INT NULL, bill_ids TEXT NULL, total BIGINT NOT NULL DEFAULT 0, status VARCHAR(8) NOT NULL DEFAULT 'ISSUED', pdf_rel VARCHAR(600) NULL,
            created_name VARCHAR(190) NULL, created_at DATETIME NULL, void_reason VARCHAR(400) NULL) $o",
        "CREATE TABLE IF NOT EXISTS md_payments (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, kind VARCHAR(8) NULL, amount BIGINT NOT NULL, pay_j CHAR(10) NULL,
            payee VARCHAR(8) NOT NULL DEFAULT 'US', insurer VARCHAR(80) NULL, method VARCHAR(60) NULL, ref_no VARCHAR(80) NULL, file_rel VARCHAR(600) NULL, inbox_id INT NULL, note VARCHAR(500) NULL,
            status VARCHAR(8) NOT NULL DEFAULT 'OK', created_by INT NULL, created_name VARCHAR(190) NULL, created_at DATETIME NULL, void_reason VARCHAR(400) NULL, KEY k_co (company_id)) $o",
        "CREATE TABLE IF NOT EXISTS md_alloc (id INT AUTO_INCREMENT PRIMARY KEY, payment_id INT NOT NULL, bill_id INT NOT NULL, amount BIGINT NOT NULL, KEY k_p (payment_id), KEY k_b (bill_id)) $o",
        "CREATE TABLE IF NOT EXISTS md_settle (id INT AUTO_INCREMENT PRIMARY KEY, insurer VARCHAR(80) NOT NULL, kind VARCHAR(8) NULL, amount BIGINT NOT NULL, pay_j CHAR(10) NULL, ref_no VARCHAR(80) NULL,
            period_from CHAR(7) NULL, period_to CHAR(7) NULL, file_rel VARCHAR(600) NULL, note VARCHAR(500) NULL, status VARCHAR(8) NOT NULL DEFAULT 'OK',
            created_name VARCHAR(190) NULL, created_at DATETIME NULL) $o",
        "CREATE TABLE IF NOT EXISTS md_inbox (id INT AUTO_INCREMENT PRIMARY KEY, source VARCHAR(8) NOT NULL DEFAULT 'BOT', chat_id VARCHAR(40) NULL, chat_title VARCHAR(190) NULL, sender VARCHAR(190) NULL,
            message_id VARCHAR(40) NULL, caption TEXT NULL, file_rel VARCHAR(600) NULL, received_at DATETIME NULL, status VARCHAR(8) NOT NULL DEFAULT 'NEW', payment_id INT NULL, note VARCHAR(300) NULL,
            KEY k_st (status), KEY k_msg (chat_id, message_id)) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[md_bill_ensure] ' . $e->getMessage()); } }
}

// ---------------- حق‌بیمه و سهم‌ها ----------------
function md_contract_cache($pdo, $id) {
    static $c = [];
    $id = (int)$id; if (!$id) return null;
    if (!array_key_exists($id, $c)) { $st = $pdo->prepare("SELECT * FROM md_contracts WHERE id = ?"); $st->execute([$id]); $c[$id] = $st->fetch() ?: null; }
    return $c[$id];
}
// حق‌بیمه‌ی ماهانه‌ی یک نفر
function md_premium($pdo, array $m, array $eff) {
    $pi = md_plan_index($pdo);
    $p = $m['plan_id'] && isset($pi['byId'][(int)$m['plan_id']]) ? $pi['byId'][(int)$m['plan_id']] : null;
    if (!$p) return 0;
    if ($m['kind'] === 'LIFE') return (int)($p['variable_amount'] && $m['life_amount'] ? $m['life_amount'] : ($m['life_amount'] ?: $p['p_flat']));
    $rate = $eff['rates'][(int)$p['id']] ?? null;
    if ($rate) return (int)$rate;
    $c = md_contract_cache($pdo, $p['contract_id']);
    if ($c && $c['premium_mode'] === 'REL') { $k = md_rel_key((int)$m['rel']); return (int)$p['p_' . $k]; }
    return (int)$p['p_flat'];
}
// سهمِ شرکت از حق‌بیمه‌ی یک نفر
function md_employer_share($pdo, array $m, array $eff, $premium) {
    $pi = md_plan_index($pdo);
    $p = $m['plan_id'] && isset($pi['byId'][(int)$m['plan_id']]) ? $pi['byId'][(int)$m['plan_id']] : null;
    $c = $p ? md_contract_cache($pdo, $p['contract_id']) : null;
    $main = (int)$m['rel'] === 1;
    if ($m['kind'] === 'LIFE') {
        if ($eff['life_share_mode'] === 'amount') return min($premium, (int)$eff['life_share']);
        $pct = $eff['life_share_mode'] === 'pct' ? (float)$eff['life_share'] : ($c ? (float)$c['share_main_pct'] : 100);
        return (int)round($premium * $pct / 100);
    }
    if ($eff['share_mode'] === 'amount') return min($premium, (int)($main ? $eff['share_main'] : $eff['share_family']));
    $pct = $eff['share_mode'] === 'pct' ? (float)($main ? $eff['share_main'] : $eff['share_family']) : ($c ? (float)($main ? $c['share_main_pct'] : $c['share_family_pct']) : 100);
    return (int)round($premium * $pct / 100);
}
// افرادِ قابلِ حسابِ یک شرکت در یک ماه: کسی که هم‌زمان در دو شرکت فعال است فقط در شرکتِ دوم (شروعِ دیرتر) حساب می‌شود
function md_billable($pdo, $companyId, $kind, $period) {
    $act = md_active($pdo, $companyId, $kind, $period);
    if (!$act) return [];
    $nids = array_keys($act);
    $in = implode(',', array_fill(0, count($nids), '?'));
    $st = $pdo->prepare("SELECT nid, company_id, start_p, id FROM md_members WHERE kind = ? AND company_id <> ? AND start_p <= ? AND (end_p IS NULL OR end_p > ?) AND nid IN ($in)");
    $st->execute(array_merge([$kind, (int)$companyId, $period, $period], $nids));
    foreach ($st->fetchAll() as $o) {
        $m = $act[$o['nid']] ?? null; if (!$m) continue;
        if ($o['start_p'] > $m['start_p'] || ($o['start_p'] === $m['start_p'] && (int)$o['id'] > (int)$m['id'])) unset($act[$o['nid']]);
    }
    return $act;
}
// جمعِ حسابِ یک شرکت در یک ماه: به تفکیکِ طرح و برای هر بیمه‌شده‌ی اصلی (برای مغایرت‌گیری)
function md_calc($pdo, $companyId, $kind, $period, array $eff = null) {
    if ($eff === null) $eff = md_effective(md_companies($pdo), (int)$companyId);
    $pi = md_plan_index($pdo);
    $mem = md_billable($pdo, $companyId, $kind, $period);
    $plans = []; $fam = []; $tot = ['count' => 0, 'amount' => 0, 'employer' => 0, 'employee' => 0, 'no_plan' => 0];
    foreach ($mem as $m) {
        $prem = md_premium($pdo, $m, $eff); $er = md_employer_share($pdo, $m, $eff, $prem);
        $pid = (int)$m['plan_id'];
        if (!$pid) $tot['no_plan']++;
        if (!isset($plans[$pid])) $plans[$pid] = ['plan_id' => $pid ?: null, 'name' => $pid && isset($pi['byId'][$pid]) ? $pi['byId'][$pid]['name'] : 'بدونِ طرح', 'no' => $pid && isset($pi['byId'][$pid]) ? (int)$pi['byId'][$pid]['no'] : 0, 'count' => 0, 'amount' => 0, 'rates' => []];
        $plans[$pid]['count']++; $plans[$pid]['amount'] += $prem; $plans[$pid]['rates'][$prem] = ($plans[$pid]['rates'][$prem] ?? 0) + 1;
        $tot['count']++; $tot['amount'] += $prem; $tot['employer'] += $er; $tot['employee'] += $prem - $er;
        $k = $m['main_nid'];
        if (!isset($fam[$k])) $fam[$k] = ['count' => 0, 'amount' => 0, 'employer' => 0, 'plan_no' => 0, 'name' => '', 'pno' => ''];
        $fam[$k]['count']++; $fam[$k]['amount'] += $prem; $fam[$k]['employer'] += $er;
        if ((int)$m['rel'] === 1 || !$fam[$k]['plan_no']) { $fam[$k]['plan_no'] = $pid && isset($pi['byId'][$pid]) ? (int)$pi['byId'][$pid]['no'] : 0; }
        if ((int)$m['rel'] === 1) { $fam[$k]['name'] = trim($m['first_name'] . ' ' . $m['last_name']); $fam[$k]['pno'] = (string)$m['personnel_no']; }
        elseif ($fam[$k]['name'] === '') $fam[$k]['name'] = '(خانواده‌ی ' . $k . ')';
    }
    foreach ($plans as &$p) { ksort($p['rates']); $p['rate'] = count($p['rates']) === 1 ? (int)array_key_first($p['rates']) : null; }
    unset($p);
    usort($plans, function ($a, $b) { return $a['no'] <=> $b['no']; });
    return ['plans' => array_values($plans), 'fam' => $fam, 'total' => $tot];
}

// ---------------- سند حقوق ----------------
function md_payroll_cols() {
    // ترتیب مهم است: «سهم کارگر+کارفرما» پیش از «سهم کارفرما»، «flag» پیش از «بیمه تکمیلی»
    return [['nid', '/کد\s*ملی|کدملی|شماره\s*ملی/u'], ['pno', '/(شماره|کد)\s*پرسنلی/u'], ['name', '/^نام\s*و\s*نام\s*خانوادگی$|^نام\s*کامل$|^نام$/u'],
            ['plan', '/flag|^طرح$|^گروه$|نوع\s*طرح|^کد\s*طرح$/iu'], ['total', '/کارگر\s*\+\s*کارفرما|کارمند\s*\+\s*کارفرما|کارگر\s*و\s*کارفرما|^جمع(\s*کل)?(\s*حق\s*بیمه)?$|^مجموع/u'],
            ['employer', '/سهم\s*کارفرما|سهم\s*شرکت/u'], ['count', '/^تعداد/u'], ['life', '/عمر/u'],
            ['employee', '/^(ب)?یمه\s*تکمیلی$|سهم\s*(کارگر|کارمند|پرسنل|بیمه\s*شده)$|کسر\s*از\s*حقوق|^تکمیلی$/u']];
}
function md_payroll_detect(array $rows) {
    $best = -1; $hr = 0; $map = [];
    foreach (array_slice($rows, 0, 10, true) as $i => $r) {
        $m = []; $n = 0;
        foreach ($r as $ci => $h) {
            $h = md_norm($h); if ($h === '') continue;
            foreach (md_payroll_cols() as [$k, $re]) if (!isset($m[$k]) && preg_match($re, $h)) { $m[$k] = $ci; $n++; break; }
        }
        if ($n > $best) { $best = $n; $hr = $i; $map = $m; }
    }
    return ['header_row' => $hr, 'map' => $map, 'headers' => array_map('md_norm', $rows[$hr] ?? [])];
}
function md_payroll_rows(array $rows, array $det) {
    $M = $det['map']; $out = [];
    if (!isset($M['nid']) && !isset($M['pno'])) return [];
    foreach ($rows as $i => $r) {
        if ($i <= $det['header_row']) continue;
        $nid = isset($M['nid']) ? md_nid($r[$M['nid']] ?? '') : '';
        $pno = isset($M['pno']) ? md_norm($r[$M['pno']] ?? '') : '';
        if ($nid === '' && ($pno === '' || !preg_match('/\d/', $pno))) continue;
        $o = ['row' => $i + 1, 'nid' => $nid, 'pno' => $pno, 'name' => isset($M['name']) ? md_norm($r[$M['name']] ?? '') : ''];
        foreach (['plan', 'total', 'employer', 'count', 'life', 'employee'] as $k) if (isset($M[$k])) { $v = trim(md_digits((string)($r[$M[$k]] ?? ''))); $o[$k] = $v === '' ? null : md_money($v); }   // خانه‌ی خالی = داده نشده (بررسی نمی‌شود)
        $out[] = $o;
    }
    return $out;
}
// شرکت‌هایی که در این ماه درمان/عمر دارند (عضوِ فعال)
function md_companies_with_members($pdo, $period) {
    $st = $pdo->prepare("SELECT DISTINCT company_id FROM md_members WHERE start_p <= ? AND (end_p IS NULL OR end_p > ?)");
    $st->execute([$period, $period]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
function md_recon_current($pdo, $companyId, $period) {
    $st = $pdo->prepare("SELECT * FROM md_recon WHERE company_id = ? AND period = ? AND status <> 'VOID' ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$companyId, $period]);
    return $st->fetch() ?: null;
}
// پیش‌نمایشِ سند: هر شیت => شرکتِ پیشنهادی؛ شرکت‌های بدونِ شیت؛ شرکت‌های تکراری
function md_payroll_preview($pdo, $period, $path, $ext) {
    md_bill_ensure($pdo);
    if (!function_exists('xlsx_read_sheets')) require_once __DIR__ . '/_xlsx_writer.php';
    $sheets = xlsx_read_sheets($path, $ext);
    if (!$sheets) throw new RuntimeException('اکسلِ سند حقوق خوانده نشد.');
    $names = md_company_names($pdo); $norm = []; foreach ($names as $id => $n) $norm[$id] = md_norm($n);
    $alias = []; foreach ($pdo->query("SELECT sheet, company_id FROM md_payroll_alias") as $a) $alias[$a['sheet']] = (int)$a['company_id'];
    $out = [];
    foreach ($sheets as $sn => $rows) {
        $det = md_payroll_detect($rows); $pr = md_payroll_rows($rows, $det);
        $key = md_norm($sn); $cid = $alias[$key] ?? null;
        if (!$cid) foreach ($norm as $id => $n) if ($n === $key) { $cid = $id; break; }
        if (!$cid) foreach ($norm as $id => $n) if ($n !== '' && $key !== '' && (mb_strpos($n, $key) !== false || mb_strpos($key, $n) !== false)) { $cid = $id; break; }
        $cols = array_keys($det['map']);
        $out[] = ['sheet' => (string)$sn, 'company_id' => $cid, 'rows' => count($pr), 'cols' => $cols, 'usable' => count($pr) > 0];
    }
    $withMem = md_companies_with_members($pdo, $period);
    $status = [];
    foreach (array_unique(array_merge($withMem, array_filter(array_column($out, 'company_id')))) as $cid) {
        $r = md_recon_current($pdo, $cid, $period);
        $status[$cid] = ['company_id' => $cid, 'company' => $names[$cid] ?? '', 'recon' => $r ? $r['status'] : null, 'has_members' => in_array($cid, $withMem, true)];
    }
    return ['sheets' => $out, 'companies' => array_values($status)];
}
// ثبتِ سند: $map = [نامِ شیت => company_id یا 0 (رد)]
function md_payroll_commit($pdo, $period, $path, $ext, $fileName, array $map) {
    md_bill_ensure($pdo);
    $sheets = xlsx_read_sheets($path, $ext);
    if (!$sheets) throw new RuntimeException('اکسلِ سند حقوق خوانده نشد.');
    $names = md_company_names($pdo);
    // فایلِ اصلی در بایگانی (پوشه‌ی کلیِ سندهای حقوق)
    $dir = md_archive_root() . '/_سند حقوق/' . str_replace('/', '-', $period) . ' ' . md_period_fa($period);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $dest = md_unique($dir, 'سند حقوق - ' . pathinfo($fileName, PATHINFO_FILENAME), $ext);
    @copy($path, $dest);
    $pdo->prepare("INSERT INTO md_payrolls (period, file_name, file_rel, created_by, created_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
        ->execute([$period, mb_substr($fileName, 0, 250), md_rel_path($dest), $_SESSION['user_id'] ?? null, md_user_name()]);
    $pid = (int)$pdo->lastInsertId();
    $res = ['done' => [], 'duplicate' => [], 'missing' => [], 'ignored' => [], 'payroll_id' => $pid];
    $used = [];
    foreach ($sheets as $sn => $rows) {
        $cid = (int)($map[(string)$sn] ?? 0);
        if (!$cid || !isset($names[$cid])) { $res['ignored'][] = (string)$sn; continue; }
        $pdo->prepare("INSERT INTO md_payroll_alias (sheet, company_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE company_id = VALUES(company_id)")->execute([mb_substr(md_norm($sn), 0, 190), $cid]);
        if (isset($used[$cid])) { $res['duplicate'][] = ['company' => $names[$cid], 'sheet' => (string)$sn, 'msg' => 'دو شیت برای یک شرکت؛ فقط اولی بررسی شد']; continue; }
        $used[$cid] = 1;
        $cur = md_recon_current($pdo, $cid, $period);
        if ($cur && $cur['status'] !== 'MISSING') { $res['duplicate'][] = ['company' => $names[$cid], 'sheet' => (string)$sn, 'msg' => 'قبلاً مغایرت‌گیری شده (' . MD_RECON_STATUS[$cur['status']] . ')؛ رد شد. برای بررسیِ دوباره اول مغایرت‌گیریِ قبلی را ابطال کنید.']; continue; }
        $det = md_payroll_detect($rows); $pr = md_payroll_rows($rows, $det);
        $hist = trim(($cur ? (string)$cur['history'] . "\n" : '') . md_today_j() . ' · ' . md_user_name() . ' · بررسی با «' . $fileName . '» (شیتِ ' . $sn . ')');
        $raw = array_map(function ($r) { return array_map(function ($c) { return (string)$c; }, $r); }, array_values($rows));
        if ($cur) {
            $pdo->prepare("UPDATE md_recon SET status = 'OPEN', payroll_id = ?, sheet = ?, cols = ?, rows_json = ?, history = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$pid, mb_substr((string)$sn, 0, 190), json_encode(array_keys($det['map'])), json_encode(['rows' => $pr, 'raw' => $raw], JSON_UNESCAPED_UNICODE), $hist, (int)$cur['id']]);
            $rid = (int)$cur['id'];
        } else {
            $pdo->prepare("INSERT INTO md_recon (company_id, period, status, payroll_id, sheet, cols, rows_json, history, created_at, updated_at) VALUES (?, ?, 'OPEN', ?, ?, ?, ?, ?, NOW(), NOW())")
                ->execute([$cid, $period, $pid, mb_substr((string)$sn, 0, 190), json_encode(array_keys($det['map'])), json_encode(['rows' => $pr, 'raw' => $raw], JSON_UNESCAPED_UNICODE), $hist]);
            $rid = (int)$pdo->lastInsertId();
        }
        $st = md_recon_compute($pdo, $rid);
        $res['done'][] = ['company' => $names[$cid], 'sheet' => (string)$sn, 'recon_id' => $rid, 'issues' => $st['open']];
    }
    // شرکت‌هایی که عضوِ فعال دارند ولی در این سند نبودند
    foreach (md_companies_with_members($pdo, $period) as $cid) {
        if (isset($used[$cid])) continue;
        $cur = md_recon_current($pdo, $cid, $period);
        $line = md_today_j() . ' · در سند حقوقِ «' . $fileName . '» نبود؛ مغایرت‌گیری نشد';
        if (!$cur) {
            $pdo->prepare("INSERT INTO md_recon (company_id, period, status, history, created_at, updated_at) VALUES (?, ?, 'MISSING', ?, NOW(), NOW())")->execute([$cid, $period, $line]);
            $res['missing'][] = $names[$cid] ?? ('#' . $cid);
        } elseif ($cur['status'] === 'MISSING') {
            $pdo->prepare("UPDATE md_recon SET history = ?, updated_at = NOW() WHERE id = ?")->execute([trim((string)$cur['history'] . "\n" . $line), (int)$cur['id']]);
            $res['missing'][] = $names[$cid] ?? ('#' . $cid);
        }
    }
    $pdo->prepare("UPDATE md_payrolls SET result = ? WHERE id = ?")->execute([json_encode($res, JSON_UNESCAPED_UNICODE), $pid]);
    md_log($pdo, 'PAYROLL', 'payroll:' . $pid, md_period_fa($period) . ' · ' . count($res['done']) . ' شرکت · ' . count($res['missing']) . ' نبود · ' . count($res['duplicate']) . ' تکراری');
    return $res;
}
const MD_RECON_TYPES = ['NOT_IN_PAYROLL' => 'در لیست هست، در سند حقوق نیست', 'NOT_IN_LIST' => 'در سند حقوق هست، در لیستِ درمان نیست', 'PLAN' => 'طرحِ متفاوت',
    'COUNT' => 'تعدادِ نفراتِ متفاوت', 'TOTAL' => 'جمعِ حق‌بیمه‌ی متفاوت', 'EMPLOYEE' => 'سهمِ شخص (کسر از حقوق) متفاوت', 'EMPLOYER' => 'سهمِ شرکت متفاوت',
    'LIFE' => 'مبلغِ بیمه‌ی عمر متفاوت', 'LIFE_NOT_IN_LIST' => 'در سند عمر دارد، در لیستِ عمر نیست', 'LIFE_MISSING' => 'در لیستِ عمر هست، در سند عمر ندارد'];
// محاسبه‌ی مغایرت‌ها (تصمیم‌های قبلیِ کاربر روی هر ردیف حفظ می‌شود)
function md_recon_compute($pdo, $rid) {
    $st = $pdo->prepare("SELECT * FROM md_recon WHERE id = ?"); $st->execute([(int)$rid]); $R = $st->fetch();
    if (!$R) throw new RuntimeException('مغایرت‌گیری پیدا نشد.');
    $cols = json_decode((string)$R['cols'], true) ?: [];
    $pay = (json_decode((string)$R['rows_json'], true) ?: [])['rows'] ?? [];
    $old = []; foreach (json_decode((string)$R['items'], true) ?: [] as $it) $old[$it['key']] = $it;
    $cid = (int)$R['company_id']; $P = $R['period'];
    $eff = md_effective(md_companies($pdo), $cid);
    $H = md_calc($pdo, $cid, 'HEALTH', $P, $eff); $Lf = md_calc($pdo, $cid, 'LIFE', $P, $eff);
    // کدِ پرسنلی => کدِ ملی (برای سندهایی که کدِ ملی ندارند)
    $byPno = [];
    foreach (array_merge(md_active($pdo, $cid, 'HEALTH', $P), md_active($pdo, $cid, 'LIFE', $P)) as $m) if ($m['personnel_no'] !== null && $m['personnel_no'] !== '' && (int)$m['rel'] === 1) $byPno[md_norm($m['personnel_no'])] = $m['nid'];
    $items = []; $seenPay = [];
    $add = function ($nid, $name, $pno, $type, $exp, $got) use (&$items, $old) {
        $key = $nid . '|' . $type;
        $items[] = ['key' => $key, 'nid' => $nid, 'name' => $name, 'pno' => $pno, 'type' => $type, 'type_fa' => MD_RECON_TYPES[$type], 'exp' => $exp, 'pay' => $got,
                    'res' => $old[$key]['res'] ?? '', 'note' => $old[$key]['note'] ?? '', 'by' => $old[$key]['by'] ?? ''];
    };
    $payBy = [];
    foreach ($pay as $r) { $nid = $r['nid'] ?: ($byPno[md_norm($r['pno'])] ?? ''); if ($nid === '') $nid = 'P:' . $r['pno']; if (!isset($payBy[$nid])) $payBy[$nid] = $r; else foreach (['total', 'employer', 'employee', 'count', 'life'] as $k) if (isset($r[$k])) $payBy[$nid][$k] = ($payBy[$nid][$k] ?? 0) + $r[$k]; }
    $has = function ($k, $r = null) use ($cols) { return in_array($k, $cols, true) && ($r === null || (isset($r[$k]) && $r[$k] !== null)); };
    $tol = 10;
    foreach ($H['fam'] as $nid => $f) {
        $r = $payBy[$nid] ?? null; $exp = ['plan' => $f['plan_no'], 'count' => $f['count'], 'total' => $f['amount'], 'employer' => $f['employer'], 'employee' => $f['amount'] - $f['employer']];
        if (!$r) { if ($f['amount'] > 0 || $f['count'] > 0) $add($nid, $f['name'], $f['pno'], 'NOT_IN_PAYROLL', $exp, null); continue; }
        $seenPay[$nid] = 1;
        $nm = $f['name'] ?: $r['name'];
        if ($has('plan', $r) && (int)$r['plan'] !== (int)$f['plan_no']) $add($nid, $nm, $r['pno'], 'PLAN', $f['plan_no'], (int)$r['plan']);
        if ($has('count', $r) && (int)$r['count'] !== (int)$f['count']) $add($nid, $nm, $r['pno'], 'COUNT', $f['count'], (int)$r['count']);
        if ($has('total', $r) && abs((int)$r['total'] - $exp['total']) > $tol) $add($nid, $nm, $r['pno'], 'TOTAL', $exp['total'], (int)$r['total']);
        if ($has('employee', $r) && abs((int)$r['employee'] - $exp['employee']) > $tol) $add($nid, $nm, $r['pno'], 'EMPLOYEE', $exp['employee'], (int)$r['employee']);
        if ($has('employer', $r) && abs((int)$r['employer'] - $exp['employer']) > $tol) $add($nid, $nm, $r['pno'], 'EMPLOYER', $exp['employer'], (int)$r['employer']);
    }
    // عمر: سهمِ شخص از حق‌بیمه‌ی عمرِ اصلی (کسر از حقوق)
    $lifeExp = [];
    foreach ($Lf['fam'] as $nid => $f) $lifeExp[$nid] = ['ded' => $f['amount'] - $f['employer'], 'name' => $f['name'], 'pno' => $f['pno']];
    if ($has('life')) {
        foreach ($lifeExp as $nid => $e) {
            $r = $payBy[$nid] ?? null;
            if (!$r || (int)($r['life'] ?? 0) === 0) { if ($e['ded'] > 0 || !$r) $add($nid, $e['name'], $e['pno'], 'LIFE_MISSING', $e['ded'], $r ? 0 : null); }
            elseif (abs((int)$r['life'] - $e['ded']) > $tol) $add($nid, $e['name'], $e['pno'], 'LIFE', $e['ded'], (int)$r['life']);
        }
    }
    foreach ($payBy as $nid => $r) {
        $healthy = (int)($r['plan'] ?? 0) > 0 || (int)($r['total'] ?? 0) > 0 || (int)($r['employee'] ?? 0) > 0 || (int)($r['count'] ?? 0) > 0;
        if (!isset($H['fam'][$nid]) && $healthy) $add($nid, $r['name'], $r['pno'], 'NOT_IN_LIST', null, ['plan' => $r['plan'] ?? null, 'count' => $r['count'] ?? null, 'total' => $r['total'] ?? null, 'employee' => $r['employee'] ?? null]);
        if ($has('life') && (int)($r['life'] ?? 0) > 0 && !isset($lifeExp[$nid])) $add($nid, $r['name'], $r['pno'], 'LIFE_NOT_IN_LIST', null, (int)$r['life']);
    }
    $open = 0; foreach ($items as $it) if ($it['res'] === '') $open++;
    $stats = ['items' => count($items), 'open' => $open, 'payroll_rows' => count($pay), 'families' => count($H['fam']), 'life' => count($lifeExp),
              'health_total' => $H['total']['amount'], 'life_total' => $Lf['total']['amount'], 'cols' => $cols];
    $status = $R['status'] === 'CONFIRMED' && $open === 0 ? 'CONFIRMED' : ($R['status'] === 'MISSING' ? 'MISSING' : 'OPEN');
    $pdo->prepare("UPDATE md_recon SET items = ?, stats = ?, status = ?, updated_at = NOW() WHERE id = ?")->execute([json_encode($items, JSON_UNESCAPED_UNICODE), json_encode($stats, JSON_UNESCAPED_UNICODE), $status, (int)$rid]);
    md_recon_archive($pdo, (int)$rid);
    return $stats;
}
// اکسلِ جدای هر شرکت در پوشه‌ی همان شرکت و ماه: شیتِ سند حقوقِ همان شرکت + گزارشِ مغایرت‌ها
function md_recon_archive($pdo, $rid) {
    $st = $pdo->prepare("SELECT * FROM md_recon WHERE id = ?"); $st->execute([(int)$rid]); $R = $st->fetch();
    if (!$R || $R['status'] === 'MISSING') return;
    $raw = (json_decode((string)$R['rows_json'], true) ?: [])['raw'] ?? [];
    if (!$raw) return;
    if (!function_exists('xlsx_build_book')) require_once __DIR__ . '/_xlsx_writer.php';
    $names = md_company_names($pdo); $co = $names[(int)$R['company_id']] ?? '';
    $w = 0; foreach ($raw as $r) $w = max($w, count($r));
    $raw = array_map(function ($r) use ($w) { return array_pad(array_values($r), $w, ''); }, $raw);
    $h = array_shift($raw);
    $items = json_decode((string)$R['items'], true) ?: [];
    $fmt = function ($v) { return is_array($v) ? implode(' · ', array_map(function ($k, $x) { return $k . ': ' . $x; }, array_keys($v), $v)) : ($v === null ? '—' : (string)$v); };
    $rep = array_map(function ($it) use ($fmt) { return [$it['nid'], $it['name'], $it['pno'], $it['type_fa'], $fmt($it['exp']), $fmt($it['pay']), $it['res'] === '' ? 'باز' : ($it['res'] === 'OK' ? 'پذیرفته' : 'اصلاح شد'), $it['note']]; }, $items);
    $path = xlsx_build_book([
        ['name' => mb_substr('سند حقوق', 0, 31), 'headers' => $h, 'rows' => $raw, 'title' => 'سند حقوق - ' . $co . ' - ' . md_period_fa($R['period'])],
        ['name' => 'مغایرت‌ها', 'headers' => ['کد ملی', 'نام', 'کد پرسنلی', 'مغایرت', 'لیستِ درمان', 'سند حقوق', 'وضعیت', 'توضیح'], 'rows' => $rep ?: [['—', 'مغایرتی نیست', '', '', '', '', '', '']],
         'title' => 'مغایرت‌گیری - ' . MD_RECON_STATUS[$R['status']]],
    ]);
    if (!$path) return;
    $dir = md_company_dir($co, $R['period']) . '/سند حقوق';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $dest = $dir . '/' . md_safe_name('سند حقوق و مغایرت‌گیری - ' . $co . ' - ' . str_replace('/', '-', $R['period'])) . '.xlsx';
    if (@rename($path, $dest) || (@copy($path, $dest) && @unlink($path))) $pdo->prepare("UPDATE md_recon SET file_rel = ? WHERE id = ?")->execute([md_rel_path($dest), (int)$rid]);
}
function md_recon_out($pdo, array $R, $full = false) {
    $names = md_company_names($pdo);
    $o = ['id' => (int)$R['id'], 'company_id' => (int)$R['company_id'], 'company' => $names[(int)$R['company_id']] ?? '', 'period' => $R['period'], 'period_fa' => md_period_fa($R['period']),
          'status' => $R['status'], 'status_fa' => MD_RECON_STATUS[$R['status']] ?? $R['status'], 'sheet' => (string)$R['sheet'], 'stats' => json_decode((string)$R['stats'], true) ?: null,
          'history' => (string)$R['history'], 'has_file' => (bool)$R['file_rel'], 'confirmed_at' => (string)$R['confirmed_at'], 'confirmed_name' => (string)$R['confirmed_name'],
          'voided_at' => (string)$R['voided_at'], 'void_reason' => (string)$R['void_reason'], 'updated_at' => (string)$R['updated_at']];
    if ($full) { $o['items'] = json_decode((string)$R['items'], true) ?: []; $o['cols'] = json_decode((string)$R['cols'], true) ?: []; }
    return $o;
}
function md_recon_list($pdo, array $f) {
    md_bill_ensure($pdo);
    $w = ['1=1']; $a = [];
    if (md_period_ok($f['period'] ?? '')) { $w[] = 'period = ?'; $a[] = $f['period']; }
    if (!empty($f['company_id'])) { $w[] = 'company_id = ?'; $a[] = (int)$f['company_id']; }
    if (empty($f['with_void'])) $w[] = "status <> 'VOID'";
    $st = $pdo->prepare("SELECT * FROM md_recon WHERE " . implode(' AND ', $w) . " ORDER BY period DESC, id DESC LIMIT 1000");
    $st->execute($a);
    return array_map(function ($R) use ($pdo) { return md_recon_out($pdo, $R); }, $st->fetchAll());
}
// تصمیم روی یک ردیف: OK = پذیرفته (با توضیح) | FIXED = اصلاح شد | '' = باز
function md_recon_resolve($pdo, $rid, array $keys, $res, $note) {
    $st = $pdo->prepare("SELECT * FROM md_recon WHERE id = ?"); $st->execute([(int)$rid]); $R = $st->fetch();
    if (!$R || !in_array($R['status'], ['OPEN', 'CONFIRMED'], true)) throw new RuntimeException('این مغایرت‌گیری باز نیست.');
    $items = json_decode((string)$R['items'], true) ?: [];
    $res = in_array($res, ['OK', 'FIXED', ''], true) ? $res : 'OK';
    foreach ($items as &$it) if (in_array($it['key'], $keys, true)) { $it['res'] = $res; $it['note'] = mb_substr(trim((string)$note), 0, 300); $it['by'] = md_user_name(); }
    unset($it);
    $pdo->prepare("UPDATE md_recon SET items = ?, status = IF(status = 'CONFIRMED' AND ? = '', 'OPEN', status), updated_at = NOW() WHERE id = ?")->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $res, (int)$rid]);
    return md_recon_compute($pdo, $rid);
}
function md_recon_confirm($pdo, $rid, $acceptRest, $note) {
    $st = md_recon_compute($pdo, $rid);
    $q = $pdo->prepare("SELECT * FROM md_recon WHERE id = ?"); $q->execute([(int)$rid]); $R = $q->fetch();
    if ($R['status'] === 'MISSING') throw new RuntimeException('این شرکت در سند حقوق نبود؛ اول سند حقوقی که این شرکت را دارد بارگذاری کنید (یا «بدونِ سند حقوق» را بزنید).');
    if ($st['open'] > 0) {
        if (!$acceptRest) throw new RuntimeException(md_fa($st['open']) . ' مغایرتِ باز مانده؛ هر کدام را اصلاح یا با توضیح بپذیرید.');
        if (trim((string)$note) === '') throw new RuntimeException('برای پذیرفتنِ همه‌ی مغایرت‌های باقی‌مانده توضیح بنویسید.');
        $items = json_decode((string)$R['items'], true) ?: [];
        foreach ($items as &$it) if ($it['res'] === '') { $it['res'] = 'OK'; $it['note'] = mb_substr(trim((string)$note), 0, 300); $it['by'] = md_user_name(); }
        unset($it);
        $pdo->prepare("UPDATE md_recon SET items = ? WHERE id = ?")->execute([json_encode($items, JSON_UNESCAPED_UNICODE), (int)$rid]);
    }
    $pdo->prepare("UPDATE md_recon SET status = 'CONFIRMED', confirmed_at = NOW(), confirmed_name = ?, history = CONCAT(COALESCE(history, ''), ?), updated_at = NOW() WHERE id = ?")
        ->execute([md_user_name(), "\n" . md_today_j() . ' · ' . md_user_name() . ' · تأیید شد', (int)$rid]);
    md_recon_archive($pdo, (int)$rid);
    md_log($pdo, 'RECON_CONFIRM', 'recon:' . (int)$rid, '');
}
// شرکتی که سند حقوق ندارد (کسر از حقوق نمی‌کند): تأیید بدونِ سند با توضیحِ الزامی
function md_recon_nopayroll($pdo, $companyId, $period, $note) {
    md_bill_ensure($pdo);
    if (trim((string)$note) === '') throw new RuntimeException('دلیلِ «بدونِ سند حقوق» را بنویسید.');
    $cur = md_recon_current($pdo, $companyId, $period);
    if ($cur && $cur['status'] !== 'MISSING') throw new RuntimeException('این شرکت در این ماه مغایرت‌گیری دارد؛ اول آن را ابطال کنید.');
    $line = md_today_j() . ' · ' . md_user_name() . ' · تأیید بدونِ سند حقوق: ' . trim($note);
    if ($cur) $pdo->prepare("UPDATE md_recon SET status = 'CONFIRMED', confirmed_at = NOW(), confirmed_name = ?, history = CONCAT(COALESCE(history, ''), ?), stats = ?, updated_at = NOW() WHERE id = ?")
                  ->execute([md_user_name(), "\n" . $line, json_encode(['nopayroll' => 1, 'note' => trim($note)], JSON_UNESCAPED_UNICODE), (int)$cur['id']]);
    else $pdo->prepare("INSERT INTO md_recon (company_id, period, status, history, stats, confirmed_at, confirmed_name, created_at, updated_at) VALUES (?, ?, 'CONFIRMED', ?, ?, NOW(), ?, NOW(), NOW())")
             ->execute([(int)$companyId, $period, $line, json_encode(['nopayroll' => 1, 'note' => trim($note)], JSON_UNESCAPED_UNICODE), md_user_name()]);
}
function md_recon_void($pdo, $rid, $reason) {
    if (trim((string)$reason) === '') throw new RuntimeException('دلیلِ ابطال را بنویسید.');
    $st = $pdo->prepare("SELECT * FROM md_recon WHERE id = ?"); $st->execute([(int)$rid]); $R = $st->fetch();
    if (!$R || $R['status'] === 'VOID') throw new RuntimeException('مغایرت‌گیری پیدا نشد یا قبلاً ابطال شده.');
    $b = $pdo->prepare("SELECT COUNT(*) FROM md_bills WHERE company_id = ? AND period = ? AND status = 'ISSUED'"); $b->execute([(int)$R['company_id'], $R['period']]);
    if ((int)$b->fetchColumn()) throw new RuntimeException('برای این شرکت و ماه صورت‌حساب صادر شده؛ اول صورت‌حساب را ابطال کنید.');
    $pdo->prepare("UPDATE md_recon SET status = 'VOID', voided_at = NOW(), voided_name = ?, void_reason = ?, history = CONCAT(COALESCE(history, ''), ?) WHERE id = ?")
        ->execute([md_user_name(), mb_substr(trim($reason), 0, 400), "\n" . md_today_j() . ' · ' . md_user_name() . ' · ابطال: ' . trim($reason), (int)$rid]);
    md_log($pdo, 'RECON_VOID', 'recon:' . (int)$rid, $reason);
}
// اصلاح در جا از روی ردیفِ مغایرت
function md_recon_fix($pdo, $rid, $key, $what) {
    $st = $pdo->prepare("SELECT * FROM md_recon WHERE id = ?"); $st->execute([(int)$rid]); $R = $st->fetch();
    if (!$R || $R['status'] === 'VOID' || $R['status'] === 'MISSING') throw new RuntimeException('این مغایرت‌گیری باز نیست.');
    $it = null; foreach (json_decode((string)$R['items'], true) ?: [] as $x) if ($x['key'] === $key) $it = $x;
    if (!$it) throw new RuntimeException('ردیفِ مغایرت پیدا نشد؛ دوباره بررسی کنید.');
    $cid = (int)$R['company_id']; $P = $R['period']; $nid = $it['nid'];
    $eff = md_effective(md_companies($pdo), $cid);
    if ($what === 'plan_from_payroll' && $it['type'] === 'PLAN') {
        $pi = md_plan_index($pdo); $ct = $eff['health_contract_id'];
        $pid = $ct ? ($pi['byContract'][$ct][(int)$it['pay']] ?? null) : null;
        if (!$pid) throw new RuntimeException('طرحِ ' . md_fa($it['pay']) . ' در قراردادِ درمانِ این شرکت نیست.');
        foreach (md_active($pdo, $cid, 'HEALTH', $P) as $m) if ($m['main_nid'] === $nid) md_member_set($pdo, (int)$m['id'], ['plan_id' => $pid], $P);
    } elseif ($what === 'end_member' && in_array($it['type'], ['NOT_IN_PAYROLL', 'LIFE_MISSING'], true)) {
        $kind = $it['type'] === 'LIFE_MISSING' ? 'LIFE' : 'HEALTH';
        foreach (md_active($pdo, $cid, $kind, $P) as $m) if ($m['main_nid'] === $nid) md_member_end($pdo, null, (int)$m['id'], $P);
    } elseif ($what === 'add_member' && in_array($it['type'], ['NOT_IN_LIST', 'LIFE_NOT_IN_LIST'], true)) {
        if (!preg_match('/^\d{10}$/', $nid)) throw new RuntimeException('کدِ ملی در سند نیست؛ از «بیمه‌شدگان» دستی اضافه کنید.');
        $kind = $it['type'] === 'LIFE_NOT_IN_LIST' ? 'LIFE' : 'HEALTH';
        $pi = md_plan_index($pdo); $ct = $kind === 'HEALTH' ? $eff['health_contract_id'] : $eff['life_contract_id'];
        $pno = is_array($it['pay']) ? (int)($it['pay']['plan'] ?? 0) : 0;
        $pid = $ct ? ($pi['byContract'][$ct][$pno] ?? ($kind === 'HEALTH' ? $eff['health_plan_id'] : $eff['life_plan_id'])) : null;
        $sp = explode(' ', $it['name'], 2);
        md_member_insert($pdo, null, $cid, $kind, ['nid' => $nid, 'main_nid' => $nid, 'rel' => 1, 'first_name' => $sp[0] ?? '', 'last_name' => $sp[1] ?? '', 'father' => '', 'gender' => null, 'birth_j' => null, 'id_no' => '',
                                                    'mobile' => '', 'sheba' => '', 'bank' => '', 'personnel_no' => $it['pno'], 'plan_id' => $pid, 'life_amount' => null, 'start_j' => null], $P);
        try { md_person_link($pdo, $nid, $it['name'], $cid, $P . '/01', '', $it['pno']); } catch (Throwable $e) {}
    } else throw new RuntimeException('این اصلاح برای این نوع مغایرت ممکن نیست.');
    md_log($pdo, 'RECON_FIX', 'recon:' . (int)$rid, $it['type_fa'] . ' · ' . $nid . ' · ' . $what);
    // ردیف اصلاح‌شده علامت می‌خورد؛ اگر شرطش دیگر برقرار نباشد خودش از فهرست می‌رود
    return md_recon_resolve($pdo, $rid, [$key], 'FIXED', 'اصلاح در لیست');
}
// تغییرِ یک عضو از یک ماه به بعد (ماه‌های قبل دست نمی‌خورد): اگر عضویت از همین ماه شروع شده، در جا؛ وگرنه بسته و باز
function md_member_set($pdo, $memberId, array $set, $fromP) {
    $st = $pdo->prepare("SELECT * FROM md_members WHERE id = ?"); $st->execute([(int)$memberId]); $m = $st->fetch();
    if (!$m) throw new RuntimeException('عضو پیدا نشد.');
    $billing = array_intersect_key($set, array_flip(['plan_id', 'rel', 'main_nid', 'life_amount']));
    $info = array_intersect_key($set, array_flip(['first_name', 'last_name', 'father', 'gender', 'birth_j', 'id_no', 'mobile', 'sheba', 'bank', 'personnel_no', 'note']));
    if ($info) { $s = []; $a = []; foreach ($info as $k => $v) { $s[] = "`$k` = ?"; $a[] = $v === '' ? null : $v; } $a[] = (int)$m['id']; $pdo->prepare("UPDATE md_members SET " . implode(', ', $s) . ", updated_at = NOW() WHERE id = ?")->execute($a); }
    $diff = false; foreach ($billing as $k => $v) if ((string)$m[$k] !== (string)$v) $diff = true;
    if (!$diff) return (int)$m['id'];
    if (!$fromP || $m['start_p'] >= $fromP) {
        $s = []; $a = []; foreach ($billing as $k => $v) { $s[] = "`$k` = ?"; $a[] = $v === '' ? null : $v; } $a[] = (int)$m['id'];
        $pdo->prepare("UPDATE md_members SET " . implode(', ', $s) . ", updated_at = NOW() WHERE id = ?")->execute($a);
        return (int)$m['id'];
    }
    md_member_end($pdo, null, (int)$m['id'], $fromP);
    $st->execute([(int)$memberId]); $m = $st->fetch();
    $row = array_merge(md_member_row($m), $info, $billing);
    $row['start_j'] = null;
    return md_member_insert($pdo, null, (int)$m['company_id'], $m['kind'], $row, $fromP);
}

// ---------------- صورت‌حساب ----------------
function md_next_no($pdo, $issueJ) {
    $S = md_settings($pdo);
    $yy = substr($issueJ, 0, 4);
    $key = 'md_no_' . $yy;
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, '0') ON DUPLICATE KEY UPDATE setting_key = setting_key")->execute([$key]);
    $pdo->prepare("UPDATE system_settings SET setting_value = LAST_INSERT_ID(CAST(setting_value AS UNSIGNED) + 1) WHERE setting_key = ?")->execute([$key]);
    $n = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    return strtr($S['no_format'] ?: '{yy}/{n}', ['{yyyy}' => $yy, '{yy}' => substr($yy, 2), '{mm}' => substr($issueJ, 5, 2), '{n}' => str_pad((string)$n, max(1, (int)$S['no_pad']), '0', STR_PAD_LEFT)]);
}
function md_bill_paid($pdo, array $billIds) {
    if (!$billIds) return [];
    $in = implode(',', array_map('intval', $billIds));
    $o = []; foreach ($pdo->query("SELECT a.bill_id, SUM(a.amount) s FROM md_alloc a JOIN md_payments p ON p.id = a.payment_id AND p.status = 'OK' WHERE a.bill_id IN ($in) GROUP BY a.bill_id") as $r) $o[(int)$r['bill_id']] = (int)$r['s'];
    return $o;
}
function md_bill_out($pdo, array $b, $paid = null) {
    $names = md_company_names($pdo);
    $paid = $paid === null ? (md_bill_paid($pdo, [(int)$b['id']])[(int)$b['id']] ?? 0) : $paid;
    $today = md_today_j();
    return ['id' => (int)$b['id'], 'company_id' => (int)$b['company_id'], 'company' => $names[(int)$b['company_id']] ?? '', 'kind' => $b['kind'], 'kind_fa' => MD_KINDS[$b['kind']] ?? '',
            'period' => $b['period'], 'period_fa' => md_period_fa($b['period']), 'no' => (string)$b['no'], 'issue_j' => (string)$b['issue_j'], 'due_j' => (string)$b['due_j'],
            'headcount' => (int)$b['headcount'], 'detail' => json_decode((string)$b['detail'], true) ?: [], 'amount' => (int)$b['amount'], 'vat' => (int)$b['vat'], 'total' => (int)$b['total'],
            'employer' => (int)$b['employer'], 'employee' => (int)$b['employee'], 'paid' => $paid, 'remain' => max(0, (int)$b['total'] - $paid), 'status' => $b['status'],
            'overdue' => $b['status'] === 'ISSUED' && (int)$b['total'] > $paid && $b['due_j'] && $b['due_j'] < $today, 'has_pdf' => (bool)$b['pdf_rel'],
            'created_name' => (string)$b['created_name'], 'created_at' => (string)$b['created_at'], 'void_reason' => (string)$b['void_reason'], 'note' => (string)$b['note']];
}
function md_bills_list($pdo, array $f) {
    md_bill_ensure($pdo);
    $w = ['1=1']; $a = [];
    if (!empty($f['company_id'])) { $w[] = 'company_id = ?'; $a[] = (int)$f['company_id']; }
    if (!empty($f['kind'])) { $w[] = 'kind = ?'; $a[] = $f['kind']; }
    if (md_period_ok($f['period'] ?? '')) { $w[] = 'period = ?'; $a[] = $f['period']; }
    if (md_period_ok($f['from'] ?? '')) { $w[] = 'period >= ?'; $a[] = $f['from']; }
    if (md_period_ok($f['to'] ?? '')) { $w[] = 'period <= ?'; $a[] = $f['to']; }
    $w[] = empty($f['with_void']) ? "status = 'ISSUED'" : '1=1';
    $st = $pdo->prepare("SELECT * FROM md_bills WHERE " . implode(' AND ', $w) . " ORDER BY period DESC, id DESC LIMIT 2000");
    $st->execute($a);
    $rows = $st->fetchAll(); $paid = md_bill_paid($pdo, array_column($rows, 'id'));
    $out = array_map(function ($b) use ($pdo, $paid) { return md_bill_out($pdo, $b, $paid[(int)$b['id']] ?? 0); }, $rows);
    if (($f['state'] ?? '') === 'unpaid') $out = array_values(array_filter($out, function ($b) { return $b['remain'] > 0; }));
    if (($f['state'] ?? '') === 'overdue') $out = array_values(array_filter($out, function ($b) { return $b['overdue']; }));
    return $out;
}
// وضعیتِ «آماده‌ی صورت‌حساب» هر شرکت در یک ماه
function md_bill_board($pdo, $period) {
    md_bill_ensure($pdo);
    $names = md_company_names($pdo); $by = md_companies($pdo);
    $out = [];
    foreach (md_companies_with_members($pdo, $period) as $cid) {
        $r = md_recon_current($pdo, $cid, $period); $eff = md_effective($by, $cid);
        foreach (['HEALTH', 'LIFE'] as $k) {
            $c = md_calc($pdo, $cid, $k, $period, $eff);
            if (!$c['total']['count']) continue;
            $b = $pdo->prepare("SELECT * FROM md_bills WHERE company_id = ? AND kind = ? AND period = ? AND status = 'ISSUED' LIMIT 1"); $b->execute([$cid, $k, $period]); $bill = $b->fetch();
            $out[] = ['company_id' => $cid, 'company' => $names[$cid] ?? '', 'kind' => $k, 'kind_fa' => MD_KINDS[$k], 'count' => $c['total']['count'], 'amount' => $c['total']['amount'], 'no_plan' => $c['total']['no_plan'],
                      'plans' => $c['plans'], 'recon' => $r ? $r['status'] : null, 'recon_id' => $r ? (int)$r['id'] : null, 'bill' => $bill ? md_bill_out($pdo, $bill) : null];
        }
    }
    usort($out, function ($a, $b) { return strcmp($a['company'], $b['company']) ?: strcmp($a['kind'], $b['kind']); });
    return $out;
}
function md_bill_create($pdo, $companyId, $kind, $period, $issueJ = null, $note = '') {
    md_bill_ensure($pdo);
    if (!isset(MD_KINDS[$kind]) || !md_period_ok($period)) throw new RuntimeException('ورودیِ نامعتبر.');
    $r = md_recon_current($pdo, $companyId, $period);
    if (!$r || $r['status'] !== 'CONFIRMED') throw new RuntimeException('پیش از صورت‌حساب، مغایرت‌گیری با سند حقوقِ این شرکت برای ' . md_period_fa($period) . ' باید تأیید شود' . ($r ? ' (وضعیتِ فعلی: ' . MD_RECON_STATUS[$r['status']] . ')' : '') . '.');
    $ex = $pdo->prepare("SELECT no FROM md_bills WHERE company_id = ? AND kind = ? AND period = ? AND status = 'ISSUED'"); $ex->execute([(int)$companyId, $kind, $period]);
    if ($n = $ex->fetchColumn()) throw new RuntimeException('برای این ماه صورت‌حسابِ شماره‌ی ' . $n . ' صادر شده؛ برای صدورِ دوباره اول آن را ابطال کنید.');
    $eff = md_effective(md_companies($pdo), (int)$companyId);
    $c = md_calc($pdo, $companyId, $kind, $period, $eff);
    if (!$c['total']['count']) throw new RuntimeException('در این ماه بیمه‌شده‌ی فعالی نیست.');
    if ($c['total']['no_plan']) throw new RuntimeException(md_fa($c['total']['no_plan']) . ' نفر طرح ندارند؛ اول طرحشان را در «بیمه‌شدگان» تعیین کنید.');
    $ct = md_contract_cache($pdo, $kind === 'HEALTH' ? $eff['health_contract_id'] : $eff['life_contract_id']);
    $vat = $ct ? (int)round($c['total']['amount'] * (float)$ct['vat_pct'] / 100) : 0;
    $issueJ = md_jdate_loose($issueJ) ?: md_today_j();
    $dueTs = jalali_to_gregorian_ts((int)substr($issueJ, 0, 4), (int)substr($issueJ, 5, 2), (int)substr($issueJ, 8, 2)) + 86400 * max(0, (int)md_settings($pdo)['due_days']);
    [$y, $m, $d] = jalali_from_gregorian_ts($dueTs); $due = sprintf('%04d/%02d/%02d', $y, $m, $d);
    $no = md_next_no($pdo, $issueJ);
    $detail = ['plans' => $c['plans'], 'contract_id' => $ct ? (int)$ct['id'] : null, 'insurer' => $ct ? $ct['insurer'] : ''];
    $pdo->prepare("INSERT INTO md_bills (company_id, kind, period, no, issue_j, due_j, headcount, detail, amount, vat, total, employer, employee, status, note, created_by, created_name, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ISSUED', ?, ?, ?, NOW())")
        ->execute([(int)$companyId, $kind, $period, $no, $issueJ, $due, $c['total']['count'], json_encode($detail, JSON_UNESCAPED_UNICODE), $c['total']['amount'], $vat, $c['total']['amount'] + $vat,
                   $c['total']['employer'], $c['total']['employee'], mb_substr(trim((string)$note), 0, 500) ?: null, $_SESSION['user_id'] ?? null, md_user_name()]);
    $id = (int)$pdo->lastInsertId();
    // دریافت‌های تخصیص‌نیافته‌ی قبلی به این صورت‌حساب می‌نشیند
    md_alloc_auto($pdo, (int)$companyId);
    try { md_bill_pdf($pdo, $id, true); } catch (Throwable $e) { error_log('[md bill pdf] ' . $e->getMessage()); }
    md_log($pdo, 'BILL', 'bill:' . $id, $no . ' · ' . md_period_fa($period));
    return $id;
}
function md_bill_void($pdo, $id, $reason) {
    if (trim((string)$reason) === '') throw new RuntimeException('دلیلِ ابطال را بنویسید.');
    $st = $pdo->prepare("SELECT * FROM md_bills WHERE id = ?"); $st->execute([(int)$id]); $b = $st->fetch();
    if (!$b || $b['status'] !== 'ISSUED') throw new RuntimeException('صورت‌حساب پیدا نشد یا قبلاً ابطال شده.');
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM md_alloc WHERE bill_id = ?")->execute([(int)$id]);   // دریافت‌ها آزاد و دوباره تخصیص داده می‌شوند
    $pdo->prepare("UPDATE md_bills SET status = 'VOID', voided_at = NOW(), void_reason = ? WHERE id = ?")->execute([mb_substr(trim($reason), 0, 400), (int)$id]);
    $pdo->commit();
    md_alloc_auto($pdo, (int)$b['company_id']);
    md_log($pdo, 'BILL_VOID', 'bill:' . (int)$id, $b['no'] . ' · ' . $reason);
}

// ---------------- PDF ----------------
function md_pdf_new($pdo) {
    require_once __DIR__ . '/_lt_pdf.php';
    $S = md_settings($pdo);
    $pdf = new LtPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('بیمه با ما'); $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $lh = $S['letterhead'] ? md_abs_path($S['letterhead']) : null;
    $pdf->ltBg = $lh && is_file($lh) ? $lh : null; $pdf->ltBgFirst = false; $pdf->ltFooter = ''; $pdf->ltPageNo = false;
    $pdf->setPrintHeader(true); $pdf->setPrintFooter(false);
    $pdf->SetMargins((float)$S['m_side'], (float)$S['m_top'], (float)$S['m_side']); $pdf->SetHeaderMargin(0); $pdf->SetAutoPageBreak(true, 28);
    $pdf->AddPage(); $pdf->setRTL(true); $pdf->SetFont('vazir', '', (float)$S['font_size']);
    return $pdf;
}
function md_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function md_money_fa($n) { return md_fa(number_format((int)$n)); }
function md_pdf_head($pdo, $no, $dateJ, $to) {
    $S = md_settings($pdo);
    $h = '<table cellpadding="1"><tr><td width="60%"></td><td width="40%" style="font-size:9pt;color:#334155">شماره: ' . md_h(md_fa($no)) . '<br>تاریخ: ' . md_h(md_fa($dateJ)) . '</td></tr></table>';
    return $h . '<p style="text-align:center;font-weight:bold">بسمه تعالی</p><p style="font-weight:bold;font-size:12pt">' . md_h($to) . '</p>';
}
function md_pdf_tail($pdo) {
    $S = md_settings($pdo);
    $t = '<p style="text-align:justify;line-height:1.9">' . nl2br(md_h($S['outro']));
    if ($S['sheba']) $t .= ' شماره‌ی شبا: <span dir="ltr">' . md_h($S['sheba']) . '</span>' . ($S['account_name'] ? ' به نامِ ' . md_h($S['account_name']) : '');
    if ($S['email']) $t .= ' — ایمیل: <span dir="ltr">' . md_h($S['email']) . '</span>';
    $t .= '</p><br><table><tr><td width="55%"></td><td width="45%" style="text-align:center;font-weight:bold">با تشکر<br>' . md_h($S['signer']);
    $sig = $S['signature'] ? md_abs_path($S['signature']) : null;
    if ($sig && is_file($sig)) $t .= '<br><img src="' . md_h($sig) . '" width="120">';
    return $t . '</td></tr></table>';
}
// صورت‌حسابِ یک شرکت: همه‌ی صورت‌حساب‌های پرداخت‌نشده‌ی تا این ماه (مثلِ نمونه)، با حق‌بیمه‌ی هر طرح
function md_bill_pdf($pdo, $id, $store = false) {
    $st = $pdo->prepare("SELECT * FROM md_bills WHERE id = ?"); $st->execute([(int)$id]); $b = $st->fetch();
    if (!$b) throw new RuntimeException('صورت‌حساب پیدا نشد.');
    $S = md_settings($pdo); $names = md_company_names($pdo);
    $q = $pdo->prepare("SELECT * FROM md_bills WHERE company_id = ? AND kind = ? AND status = 'ISSUED' AND period <= ? ORDER BY period"); $q->execute([(int)$b['company_id'], $b['kind'], $b['period']]);
    $all = $q->fetchAll(); $paid = md_bill_paid($pdo, array_column($all, 'id'));
    $rows = array_values(array_filter($all, function ($x) use ($paid, $b) { return (int)$x['id'] === (int)$b['id'] || (int)$x['total'] > ($paid[(int)$x['id']] ?? 0); }));
    $planCols = [];
    foreach ($rows as $x) foreach ((json_decode((string)$x['detail'], true) ?: [])['plans'] ?? [] as $p) $planCols[(int)$p['no']] = $p['name'];
    ksort($planCols);
    $html = md_pdf_head($pdo, $b['no'], $b['issue_j'], 'شرکتِ محترم ' . ($names[(int)$b['company_id']] ?? ''));
    $html .= '<p style="line-height:1.9">' . nl2br(md_h($b['kind'] === 'LIFE' ? $S['intro_life'] : $S['intro_health'])) . '</p>';
    $th = 'style="background-color:#e2e8f0;font-weight:bold;text-align:center"';
    $html .= '<table border="1" cellpadding="4" style="font-size:9.5pt;text-align:center"><tr><td ' . $th . '>ماه</td><td ' . $th . '>تعداد کل بیمه‌شدگان</td>';
    foreach ($planCols as $no => $nm) $html .= '<td ' . $th . '>حق بیمه ماهانه ' . md_h($nm) . ' هر نفر</td>';
    $html .= '<td ' . $th . '>مبلغ قابل پرداخت</td><td ' . $th . '>مبلغ پرداختی</td><td ' . $th . '>مبلغ قابل پرداخت نهایی</td></tr>';
    $sum = 0;
    foreach ($rows as $x) {
        $d = json_decode((string)$x['detail'], true) ?: []; $pr = []; foreach ($d['plans'] ?? [] as $p) $pr[(int)$p['no']] = $p;
        $pd = $paid[(int)$x['id']] ?? 0; $rem = max(0, (int)$x['total'] - $pd); $sum += $rem;
        $html .= '<tr><td>' . md_h(MD_MONTHS[(int)substr($x['period'], 5, 2)] . ' ' . md_fa(substr($x['period'], 0, 4))) . '</td><td>' . md_fa($x['headcount']) . '</td>';
        foreach ($planCols as $no => $nm) { $p = $pr[$no] ?? null; $html .= '<td>' . ($p ? ($p['rate'] !== null ? md_money_fa($p['rate']) : 'متغیر') : '—') . '</td>'; }
        $html .= '<td>' . md_money_fa($x['total']) . '</td><td>' . md_money_fa($pd) . '</td><td>' . md_money_fa($rem) . '</td></tr>';
    }
    $span = 4 + count($planCols);
    $html .= '<tr><td colspan="' . $span . '" style="text-align:left;font-weight:bold">جمع کل</td><td style="font-weight:bold">' . md_money_fa($sum) . '</td></tr></table>';
    if ((int)$b['vat'] > 0) $html .= '<p style="font-size:9pt;color:#475569">مبالغ با احتسابِ مالیات بر ارزش افزوده است.</p>';
    $html .= md_pdf_tail($pdo);
    $pdf = md_pdf_new($pdo);
    $pdf->writeHTML($html, true, false, true, false, 'R');
    $bytes = $pdf->Output('bill.pdf', 'S');
    if ($store) {
        $dir = md_company_dir($names[(int)$b['company_id']] ?? '', $b['period']) . '/صورت‌حساب‌ها';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $p = md_unique($dir, 'صورت‌حساب ' . MD_KINDS[$b['kind']] . ' - ' . str_replace('/', '-', $b['no']), 'pdf');
        if (@file_put_contents($p, $bytes)) $pdo->prepare("UPDATE md_bills SET pdf_rel = ? WHERE id = ?")->execute([md_rel_path($p), (int)$id]);
    }
    return ['bytes' => $bytes, 'name' => 'صورت‌حساب ' . MD_KINDS[$b['kind']] . ' ' . ($names[(int)$b['company_id']] ?? '') . ' ' . str_replace('/', '-', $b['no']) . '.pdf'];
}
// صورت‌حسابِ تجمیعی: جدولِ شرکت‌ها با تعداد و حق‌بیمه (صورت‌حساب‌های صادرشده‌ی همان ماه)
function md_cbill_create($pdo, $kind, $period, array $companyIds, $title, $toCompanyId = null) {
    md_bill_ensure($pdo);
    $ids = array_values(array_filter(array_map('intval', $companyIds)));
    if (!$ids) throw new RuntimeException('شرکت‌ها را انتخاب کنید.');
    $in = implode(',', $ids);
    $st = $pdo->prepare("SELECT id, total FROM md_bills WHERE kind = ? AND period = ? AND status = 'ISSUED' AND company_id IN ($in)"); $st->execute([$kind, $period]);
    $bills = $st->fetchAll();
    if (!$bills) throw new RuntimeException('برای این شرکت‌ها در ' . md_period_fa($period) . ' صورت‌حسابی صادر نشده؛ اول صورت‌حسابِ هر شرکت را صادر کنید.');
    $issue = md_today_j(); $no = md_next_no($pdo, $issue);
    $pdo->prepare("INSERT INTO md_cbills (kind, period, no, issue_j, title, to_company_id, bill_ids, total, status, created_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ISSUED', ?, NOW())")
        ->execute([$kind, $period, $no, $issue, mb_substr(trim((string)$title), 0, 250) ?: null, $toCompanyId ?: null, json_encode(array_map('intval', array_column($bills, 'id'))), array_sum(array_column($bills, 'total')), md_user_name()]);
    $id = (int)$pdo->lastInsertId();
    try { md_cbill_pdf($pdo, $id, true); } catch (Throwable $e) { error_log('[md cbill pdf] ' . $e->getMessage()); }
    md_log($pdo, 'CBILL', 'cbill:' . $id, $no);
    return $id;
}
function md_cbill_pdf($pdo, $id, $store = false) {
    $st = $pdo->prepare("SELECT * FROM md_cbills WHERE id = ?"); $st->execute([(int)$id]); $c = $st->fetch();
    if (!$c) throw new RuntimeException('صورت‌حسابِ تجمیعی پیدا نشد.');
    $S = md_settings($pdo); $names = md_company_names($pdo);
    $ids = array_map('intval', json_decode((string)$c['bill_ids'], true) ?: [0]);
    $bills = $pdo->query("SELECT * FROM md_bills WHERE id IN (" . implode(',', $ids) . ") ORDER BY company_id")->fetchAll();
    $paid = md_bill_paid($pdo, $ids);
    $planCols = []; foreach ($bills as $b) foreach ((json_decode((string)$b['detail'], true) ?: [])['plans'] ?? [] as $p) $planCols[(int)$p['no']] = $p['name'];
    ksort($planCols);
    $to = $c['title'] ?: ($c['to_company_id'] ? 'شرکتِ محترم ' . ($names[(int)$c['to_company_id']] ?? '') : 'صورت‌حسابِ تجمیعی');
    $html = md_pdf_head($pdo, $c['no'], $c['issue_j'], $to);
    $html .= '<p style="line-height:1.9">' . nl2br(md_h($c['kind'] === 'LIFE' ? $S['intro_life'] : $S['intro_health'])) . ' (' . md_h(md_period_fa($c['period'])) . ')</p>';
    $th = 'style="background-color:#e2e8f0;font-weight:bold;text-align:center"';
    $html .= '<table border="1" cellpadding="4" style="font-size:9pt;text-align:center"><tr><td ' . $th . '>ردیف</td><td ' . $th . '>شرکت</td><td ' . $th . '>تعداد کل</td>';
    foreach ($planCols as $no => $nm) $html .= '<td ' . $th . '>تعداد ' . md_h($nm) . '</td>';
    $html .= '<td ' . $th . '>مبلغ قابل پرداخت</td><td ' . $th . '>پرداختی</td><td ' . $th . '>مانده</td></tr>';
    $i = 0; $tt = ['h' => 0, 't' => 0, 'p' => 0, 'r' => 0]; $tp = [];
    foreach ($bills as $b) {
        $d = json_decode((string)$b['detail'], true) ?: []; $pr = []; foreach ($d['plans'] ?? [] as $p) $pr[(int)$p['no']] = $p;
        $pd = $paid[(int)$b['id']] ?? 0; $rem = max(0, (int)$b['total'] - $pd);
        $html .= '<tr><td>' . md_fa(++$i) . '</td><td>' . md_h($names[(int)$b['company_id']] ?? '') . '</td><td>' . md_fa($b['headcount']) . '</td>';
        foreach ($planCols as $no => $nm) { $n = (int)($pr[$no]['count'] ?? 0); $tp[$no] = ($tp[$no] ?? 0) + $n; $html .= '<td>' . md_fa($n) . '</td>'; }
        $html .= '<td>' . md_money_fa($b['total']) . '</td><td>' . md_money_fa($pd) . '</td><td>' . md_money_fa($rem) . '</td></tr>';
        $tt['h'] += (int)$b['headcount']; $tt['t'] += (int)$b['total']; $tt['p'] += $pd; $tt['r'] += $rem;
    }
    $html .= '<tr style="font-weight:bold"><td colspan="2">جمع کل</td><td>' . md_fa($tt['h']) . '</td>';
    foreach ($planCols as $no => $nm) $html .= '<td>' . md_fa($tp[$no] ?? 0) . '</td>';
    $html .= '<td>' . md_money_fa($tt['t']) . '</td><td>' . md_money_fa($tt['p']) . '</td><td>' . md_money_fa($tt['r']) . '</td></tr></table>';
    $html .= md_pdf_tail($pdo);
    $pdf = md_pdf_new($pdo);
    $pdf->writeHTML($html, true, false, true, false, 'R');
    $bytes = $pdf->Output('cbill.pdf', 'S');
    if ($store) {
        $dir = md_archive_root() . '/_صورت‌حساب‌های تجمیعی/' . str_replace('/', '-', $c['period']) . ' ' . md_period_fa($c['period']);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $p = md_unique($dir, 'تجمیعی ' . MD_KINDS[$c['kind']] . ' - ' . str_replace('/', '-', $c['no']) . ($c['title'] ? ' - ' . $c['title'] : ''), 'pdf');
        if (@file_put_contents($p, $bytes)) $pdo->prepare("UPDATE md_cbills SET pdf_rel = ? WHERE id = ?")->execute([md_rel_path($p), (int)$id]);
        // کپی در پوشه‌ی شرکتِ گیرنده
        if ($c['to_company_id']) { $d2 = md_company_dir($names[(int)$c['to_company_id']] ?? '', $c['period']) . '/صورت‌حساب‌ها'; if (!is_dir($d2)) @mkdir($d2, 0775, true); @copy($p, md_unique($d2, basename($p, '.pdf'), 'pdf')); }
    }
    return ['bytes' => $bytes, 'name' => 'صورت‌حساب تجمیعی ' . str_replace('/', '-', $c['no']) . '.pdf'];
}
function md_cbills_list($pdo, array $f) {
    md_bill_ensure($pdo);
    $w = ['1=1']; $a = [];
    if (md_period_ok($f['period'] ?? '')) { $w[] = 'period = ?'; $a[] = $f['period']; }
    $st = $pdo->prepare("SELECT * FROM md_cbills WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT 300"); $st->execute($a);
    $names = md_company_names($pdo);
    return array_map(function ($c) use ($names) { return ['id' => (int)$c['id'], 'kind' => $c['kind'], 'kind_fa' => MD_KINDS[$c['kind']] ?? '', 'period' => $c['period'], 'period_fa' => md_period_fa($c['period']),
        'no' => (string)$c['no'], 'issue_j' => (string)$c['issue_j'], 'title' => (string)$c['title'], 'to' => $names[(int)$c['to_company_id']] ?? '', 'bills' => count(json_decode((string)$c['bill_ids'], true) ?: []),
        'total' => (int)$c['total'], 'status' => $c['status'], 'void_reason' => (string)$c['void_reason']]; }, $st->fetchAll());
}

// ---------------- دریافت‌ها، تخصیص، واریز به بیمه‌گر ----------------
// تخصیصِ خودکارِ دریافت‌های آزاد به قدیمی‌ترین صورت‌حساب‌های باز (اگر دریافت نوعِ بیمه دارد، فقط همان نوع)
function md_alloc_auto($pdo, $companyId) {
    $pays = $pdo->prepare("SELECT p.*, p.amount - COALESCE((SELECT SUM(a.amount) FROM md_alloc a WHERE a.payment_id = p.id), 0) AS free FROM md_payments p WHERE p.company_id = ? AND p.status = 'OK' ORDER BY p.pay_j, p.id");
    $pays->execute([(int)$companyId]);
    foreach ($pays->fetchAll() as $p) {
        $free = (int)$p['free']; if ($free <= 0) continue;
        $q = "SELECT b.id, b.total - COALESCE((SELECT SUM(a.amount) FROM md_alloc a JOIN md_payments pp ON pp.id = a.payment_id AND pp.status = 'OK' WHERE a.bill_id = b.id), 0) AS rem
              FROM md_bills b WHERE b.company_id = ? AND b.status = 'ISSUED'" . ($p['kind'] ? ' AND b.kind = ?' : '') . " ORDER BY b.period, b.id";
        $bs = $pdo->prepare($q); $bs->execute($p['kind'] ? [(int)$companyId, $p['kind']] : [(int)$companyId]);
        foreach ($bs->fetchAll() as $b) {
            if ($free <= 0) break;
            $x = min($free, (int)$b['rem']); if ($x <= 0) continue;
            $pdo->prepare("INSERT INTO md_alloc (payment_id, bill_id, amount) VALUES (?, ?, ?)")->execute([(int)$p['id'], (int)$b['id'], $x]);
            $free -= $x;
        }
    }
}
function md_payment_save($pdo, array $in) {
    md_bill_ensure($pdo);
    $cid = (int)($in['company_id'] ?? 0); $amt = md_money($in['amount'] ?? 0);
    if (!$cid || $amt <= 0) throw new RuntimeException('شرکت و مبلغ را وارد کنید.');
    $payee = ($in['payee'] ?? '') === 'INSURER' ? 'INSURER' : 'US';
    $kind = isset(MD_KINDS[$in['kind'] ?? '']) ? $in['kind'] : null;
    $payJ = md_jdate_loose($in['pay_j'] ?? '') ?: md_today_j();
    $insurer = mb_substr(trim((string)($in['insurer'] ?? '')), 0, 80);
    if ($payee === 'INSURER' && $insurer === '') { $eff = md_effective(md_companies($pdo), $cid); $ct = md_contract_cache($pdo, $kind === 'LIFE' ? $eff['life_contract_id'] : $eff['health_contract_id']); $insurer = $ct ? $ct['insurer'] : ''; }
    $fileRel = null; $names = md_company_names($pdo);
    $dir = md_company_dir($names[$cid] ?? '', substr($payJ, 0, 7)) . '/رسیدها';
    if (!empty($in['inbox_id'])) {
        $q = $pdo->prepare("SELECT * FROM md_inbox WHERE id = ? AND status = 'NEW'"); $q->execute([(int)$in['inbox_id']]); $ib = $q->fetch();
        if (!$ib) throw new RuntimeException('رسیدِ صندوق پیدا نشد یا قبلاً ثبت شده.');
        $src = md_abs_path($ib['file_rel']);
        if ($src && is_file($src)) { if (!is_dir($dir)) @mkdir($dir, 0775, true); $dest = md_unique($dir, 'رسید ' . str_replace('/', '-', $payJ) . ' - ' . md_money_fa($amt), strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg'); if (@copy($src, $dest)) $fileRel = md_rel_path($dest); }
    } elseif (!empty($in['file_token'])) {
        $tok = preg_replace('/[^a-f0-9]/', '', (string)$in['file_token']); $t = md_tmp_dir();
        $ext = preg_replace('/[^a-z0-9]/', '', (string)@file_get_contents("$t/$tok.ext"));
        if ($tok !== '' && $ext !== '' && is_file("$t/$tok.$ext")) { if (!is_dir($dir)) @mkdir($dir, 0775, true); $dest = md_unique($dir, 'رسید ' . str_replace('/', '-', $payJ) . ' - ' . md_money_fa($amt), $ext); if (@rename("$t/$tok.$ext", $dest)) { $fileRel = md_rel_path($dest); @unlink("$t/$tok.ext"); } }
    }
    $pdo->prepare("INSERT INTO md_payments (company_id, kind, amount, pay_j, payee, insurer, method, ref_no, file_rel, inbox_id, note, status, created_by, created_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OK', ?, ?, NOW())")
        ->execute([$cid, $kind, $amt, $payJ, $payee, $insurer ?: null, mb_substr(trim((string)($in['method'] ?? '')), 0, 60) ?: null, mb_substr(trim(md_digits((string)($in['ref_no'] ?? ''))), 0, 80) ?: null,
                   $fileRel, !empty($in['inbox_id']) ? (int)$in['inbox_id'] : null, mb_substr(trim((string)($in['note'] ?? '')), 0, 500) ?: null, $_SESSION['user_id'] ?? null, md_user_name()]);
    $pid = (int)$pdo->lastInsertId();
    if (!empty($in['inbox_id'])) $pdo->prepare("UPDATE md_inbox SET status = 'USED', payment_id = ? WHERE id = ?")->execute([$pid, (int)$in['inbox_id']]);
    // تخصیص: صورت‌حساب‌های انتخابی (به ترتیب) یا خودکار
    $free = $amt;
    foreach ((array)($in['bill_ids'] ?? []) as $bid) {
        if ($free <= 0) break;
        $b = $pdo->prepare("SELECT b.id, b.total - COALESCE((SELECT SUM(a.amount) FROM md_alloc a JOIN md_payments pp ON pp.id = a.payment_id AND pp.status = 'OK' WHERE a.bill_id = b.id), 0) rem FROM md_bills b WHERE b.id = ? AND b.company_id = ? AND b.status = 'ISSUED'");
        $b->execute([(int)$bid, $cid]); $r = $b->fetch(); if (!$r) continue;
        $x = min($free, (int)$r['rem']); if ($x <= 0) continue;
        $pdo->prepare("INSERT INTO md_alloc (payment_id, bill_id, amount) VALUES (?, ?, ?)")->execute([$pid, (int)$r['id'], $x]); $free -= $x;
    }
    md_alloc_auto($pdo, $cid);
    md_log($pdo, 'PAYMENT', 'pay:' . $pid, ($names[$cid] ?? '') . ' · ' . md_money_fa($amt) . ' · ' . MD_PAYEES[$payee]);
    return $pid;
}
function md_payment_void($pdo, $id, $reason) {
    if (trim((string)$reason) === '') throw new RuntimeException('دلیلِ ابطال را بنویسید.');
    $st = $pdo->prepare("SELECT * FROM md_payments WHERE id = ? AND status = 'OK'"); $st->execute([(int)$id]); $p = $st->fetch();
    if (!$p) throw new RuntimeException('دریافت پیدا نشد یا قبلاً ابطال شده.');
    $pdo->prepare("DELETE FROM md_alloc WHERE payment_id = ?")->execute([(int)$id]);
    $pdo->prepare("UPDATE md_payments SET status = 'VOID', void_reason = ? WHERE id = ?")->execute([mb_substr(trim($reason), 0, 400), (int)$id]);
    if ($p['inbox_id']) $pdo->prepare("UPDATE md_inbox SET status = 'NEW', payment_id = NULL WHERE id = ?")->execute([(int)$p['inbox_id']]);
    md_alloc_auto($pdo, (int)$p['company_id']);
    md_log($pdo, 'PAYMENT_VOID', 'pay:' . (int)$id, $reason);
}
function md_payments_list($pdo, array $f) {
    md_bill_ensure($pdo);
    $w = ['1=1']; $a = [];
    if (!empty($f['company_id'])) { $w[] = 'p.company_id = ?'; $a[] = (int)$f['company_id']; }
    if (!empty($f['payee'])) { $w[] = 'p.payee = ?'; $a[] = $f['payee']; }
    if (empty($f['with_void'])) $w[] = "p.status = 'OK'";
    if (($fj = md_jdate_loose($f['from_j'] ?? '')) !== null) { $w[] = 'p.pay_j >= ?'; $a[] = $fj; }
    if (($tj = md_jdate_loose($f['to_j'] ?? '')) !== null) { $w[] = 'p.pay_j <= ?'; $a[] = $tj; }
    $st = $pdo->prepare("SELECT p.*, COALESCE((SELECT SUM(x.amount) FROM md_alloc x WHERE x.payment_id = p.id), 0) alloc FROM md_payments p WHERE " . implode(' AND ', $w) . " ORDER BY p.pay_j DESC, p.id DESC LIMIT 2000");
    $st->execute($a);
    $names = md_company_names($pdo);
    return array_map(function ($p) use ($names) { return ['id' => (int)$p['id'], 'company_id' => (int)$p['company_id'], 'company' => $names[(int)$p['company_id']] ?? '', 'kind' => (string)$p['kind'], 'kind_fa' => MD_KINDS[$p['kind']] ?? 'همه',
        'amount' => (int)$p['amount'], 'alloc' => (int)$p['alloc'], 'free' => (int)$p['amount'] - (int)$p['alloc'], 'pay_j' => (string)$p['pay_j'], 'payee' => $p['payee'], 'payee_fa' => MD_PAYEES[$p['payee']] ?? '',
        'insurer' => (string)$p['insurer'], 'method' => (string)$p['method'], 'ref_no' => (string)$p['ref_no'], 'has_file' => (bool)$p['file_rel'], 'note' => (string)$p['note'], 'status' => $p['status'],
        'created_name' => (string)$p['created_name'], 'void_reason' => (string)$p['void_reason']]; }, $st->fetchAll());
}
function md_settle_save($pdo, array $in) {
    md_bill_ensure($pdo);
    $amt = md_money($in['amount'] ?? 0); $ins = mb_substr(trim((string)($in['insurer'] ?? '')), 0, 80);
    if ($amt <= 0 || $ins === '') throw new RuntimeException('بیمه‌گر و مبلغ را وارد کنید.');
    $payJ = md_jdate_loose($in['pay_j'] ?? '') ?: md_today_j();
    $fileRel = null;
    if (!empty($in['file_token'])) {
        $tok = preg_replace('/[^a-f0-9]/', '', (string)$in['file_token']); $t = md_tmp_dir(); $ext = preg_replace('/[^a-z0-9]/', '', (string)@file_get_contents("$t/$tok.ext"));
        $dir = md_archive_root() . '/_واریز به بیمه‌گر/' . md_safe_name($ins); if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if ($tok !== '' && $ext !== '' && is_file("$t/$tok.$ext")) { $dest = md_unique($dir, 'واریز ' . str_replace('/', '-', $payJ) . ' - ' . md_money_fa($amt), $ext); if (@rename("$t/$tok.$ext", $dest)) $fileRel = md_rel_path($dest); }
    }
    $pdo->prepare("INSERT INTO md_settle (insurer, kind, amount, pay_j, ref_no, period_from, period_to, file_rel, note, status, created_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'OK', ?, NOW())")
        ->execute([$ins, isset(MD_KINDS[$in['kind'] ?? '']) ? $in['kind'] : null, $amt, $payJ, mb_substr(trim(md_digits((string)($in['ref_no'] ?? ''))), 0, 80) ?: null,
                   md_period_ok($in['period_from'] ?? '') ? $in['period_from'] : null, md_period_ok($in['period_to'] ?? '') ? $in['period_to'] : null, $fileRel,
                   mb_substr(trim((string)($in['note'] ?? '')), 0, 500) ?: null, md_user_name()]);
    $id = (int)$pdo->lastInsertId();
    md_log($pdo, 'SETTLE', 'settle:' . $id, $ins . ' · ' . md_money_fa($amt));
    return $id;
}
function md_settle_list($pdo) {
    md_bill_ensure($pdo);
    $rows = $pdo->query("SELECT * FROM md_settle ORDER BY pay_j DESC, id DESC LIMIT 1000")->fetchAll();
    // ماندهِ هر بیمه‌گر: دریافت‌های «به ما» − واریزها
    $bal = [];
    foreach ($pdo->query("SELECT COALESCE(insurer, '') ins, SUM(amount) s FROM md_payments WHERE status = 'OK' AND payee = 'US' GROUP BY ins") as $r) $bal[$r['ins'] ?: 'نامشخص']['in'] = (int)$r['s'];
    foreach ($pdo->query("SELECT insurer, SUM(amount) s FROM md_settle WHERE status = 'OK' GROUP BY insurer") as $r) $bal[$r['insurer']]['out'] = (int)$r['s'];
    $b = []; foreach ($bal as $k => $v) $b[] = ['insurer' => $k, 'in' => $v['in'] ?? 0, 'out' => $v['out'] ?? 0, 'balance' => ($v['in'] ?? 0) - ($v['out'] ?? 0)];
    return ['items' => array_map(function ($s) { return ['id' => (int)$s['id'], 'insurer' => $s['insurer'], 'kind' => (string)$s['kind'], 'amount' => (int)$s['amount'], 'pay_j' => (string)$s['pay_j'], 'ref_no' => (string)$s['ref_no'],
        'period_from' => (string)$s['period_from'], 'period_to' => (string)$s['period_to'], 'has_file' => (bool)$s['file_rel'], 'note' => (string)$s['note'], 'status' => $s['status'], 'created_name' => (string)$s['created_name']]; }, $rows), 'balance' => $b];
}

// ---------------- صندوقِ رسیدها (گروه‌های ربات) ----------------
function md_is_receipt_group($pdo, $chatId) { foreach ((array)md_settings($pdo)['receipt_groups'] as $g) if ((string)$g['chat_id'] === (string)$chatId) return $g; return null; }
function md_inbox_add($pdo, $tmpAbs, array $meta) {
    md_bill_ensure($pdo);
    $ext = strtolower(pathinfo($tmpAbs, PATHINFO_EXTENSION)) ?: 'jpg';
    $dir = md_archive_root() . '/_رسیدهای دریافتی/' . str_replace('/', '-', md_period_now());
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $dest = md_unique($dir, 'رسید ' . str_replace('/', '-', md_today_j()) . ' ساعت ' . date('H-i-s') . (!empty($meta['chat_title']) ? ' - ' . mb_substr($meta['chat_title'], 0, 40) : ''), $ext);
    if (!@rename($tmpAbs, $dest)) { if (!@copy($tmpAbs, $dest)) return 0; @unlink($tmpAbs); }
    $pdo->prepare("INSERT INTO md_inbox (source, chat_id, chat_title, sender, message_id, caption, file_rel, received_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'NEW')")
        ->execute([$meta['source'] ?? 'BOT', $meta['chat_id'] ?? null, mb_substr((string)($meta['chat_title'] ?? ''), 0, 190) ?: null, mb_substr((string)($meta['sender'] ?? ''), 0, 190) ?: null,
                   $meta['message_id'] ?? null, mb_substr((string)($meta['caption'] ?? ''), 0, 2000) ?: null, md_rel_path($dest)]);
    return (int)$pdo->lastInsertId();
}
function md_inbox_list($pdo, $status = 'NEW') {
    md_bill_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM md_inbox WHERE status = ? ORDER BY id DESC LIMIT 500"); $st->execute([in_array($status, ['NEW', 'USED', 'IGNORED'], true) ? $status : 'NEW']);
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'source' => $r['source'], 'chat_title' => (string)$r['chat_title'], 'sender' => (string)$r['sender'], 'caption' => (string)$r['caption'],
        'received_at' => (string)$r['received_at'], 'status' => $r['status'], 'payment_id' => $r['payment_id'] ? (int)$r['payment_id'] : null, 'ext' => strtolower(pathinfo((string)$r['file_rel'], PATHINFO_EXTENSION)), 'note' => (string)$r['note']]; }, $st->fetchAll());
}

// ---------------- بایگانی ----------------
function md_archive_ls($rel) {
    $root = md_archive_root(); if (!is_dir($root)) @mkdir($root, 0775, true);
    $rel = trim(str_replace('\\', '/', (string)$rel), '/');
    if (strpos($rel, '..') !== false) throw new RuntimeException('مسیرِ نامعتبر.');
    $abs = $rel === '' ? $root : "$root/$rel";
    if (!is_dir($abs)) throw new RuntimeException('پوشه پیدا نشد.');
    $o = [];
    foreach (scandir($abs) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.') continue;
        $p = "$abs/$f";
        $o[] = ['name' => $f, 'rel' => ltrim("$rel/$f", '/'), 'dir' => is_dir($p), 'size' => is_file($p) ? filesize($p) : 0, 'at' => date('Y-m-d H:i', filemtime($p))];
    }
    usort($o, function ($a, $b) { return ($b['dir'] <=> $a['dir']) ?: strcmp($a['name'], $b['name']); });
    return $o;
}
// جدولِ تعدادِ ماهانه‌ی هر شرکت (درمان/عمر) در یک بازه
function md_headcount_table($pdo, $from, $to, $kind = 'HEALTH') {
    md_bill_ensure($pdo);
    $ps = md_period_range($from, $to, 36); $names = md_company_names($pdo); $t = [];
    foreach ($ps as $p) {
        $st = $pdo->prepare("SELECT company_id, COUNT(*) n FROM md_members WHERE kind = ? AND start_p <= ? AND (end_p IS NULL OR end_p > ?) GROUP BY company_id");
        $st->execute([$kind, $p, $p]);
        foreach ($st->fetchAll() as $r) $t[(int)$r['company_id']][$p] = (int)$r['n'];
    }
    $rows = [];
    foreach ($t as $cid => $m) { $r = ['company_id' => $cid, 'company' => $names[$cid] ?? '', 'months' => []]; foreach ($ps as $p) $r['months'][] = $m[$p] ?? 0; $rows[] = $r; }
    usort($rows, function ($a, $b) { return strcmp($a['company'], $b['company']); });
    return ['periods' => $ps, 'labels' => array_map('md_period_fa', $ps), 'rows' => $rows];
}

// ---------------- خروجیِ بازه‌ای با ستون‌های دلخواه ----------------
function md_export_catalog() {
    return ['members' => ['period' => 'ماه', 'company' => 'شرکت', 'kind' => 'نوع', 'nid' => 'کد ملی', 'name' => 'نام و نام خانوادگی', 'first_name' => 'نام', 'last_name' => 'نام خانوادگی',
                          'rel' => 'نسبت', 'main_nid' => 'کد ملی بیمه‌شده‌ی اصلی', 'main_name' => 'بیمه‌شده‌ی اصلی', 'plan' => 'طرح', 'premium' => 'حق بیمه ماهانه', 'employer' => 'سهم شرکت', 'employee' => 'سهم شخص',
                          'father' => 'نام پدر', 'gender' => 'جنسیت', 'birth_j' => 'تاریخ تولد', 'personnel_no' => 'کد پرسنلی', 'mobile' => 'موبایل', 'sheba' => 'شبا', 'start_j' => 'تاریخ شروع', 'end_j' => 'تاریخ پایان', 'contract' => 'قرارداد'],
            'bills' => ['no' => 'شماره', 'issue_j' => 'تاریخ', 'period' => 'ماه', 'company' => 'شرکت', 'kind' => 'نوع', 'headcount' => 'تعداد', 'amount' => 'حق بیمه', 'vat' => 'مالیات', 'total' => 'جمع',
                        'employer' => 'سهم شرکت', 'employee' => 'سهم پرسنل', 'paid' => 'پرداختی', 'remain' => 'مانده', 'due_j' => 'سررسید'],
            'payments' => ['pay_j' => 'تاریخ', 'company' => 'شرکت', 'kind' => 'نوع', 'amount' => 'مبلغ', 'payee' => 'دریافت‌کننده', 'insurer' => 'بیمه‌گر', 'method' => 'روش', 'ref_no' => 'شماره پیگیری', 'alloc' => 'تخصیص‌یافته', 'note' => 'توضیح']];
}
function md_export_rows($pdo, array $f) {
    md_bill_ensure($pdo);
    $type = isset(md_export_catalog()[$f['type'] ?? '']) ? $f['type'] : 'members';
    $cat = md_export_catalog()[$type];
    $cols = array_values(array_filter((array)($f['cols'] ?? []), function ($c) use ($cat) { return isset($cat[$c]); })) ?: array_keys($cat);
    $from = md_period_ok($f['from'] ?? '') ? $f['from'] : md_period_now(); $to = md_period_ok($f['to'] ?? '') ? $f['to'] : $from;
    if ($to < $from) [$from, $to] = [$to, $from];
    $names = md_company_names($pdo); $out = [];
    if ($type === 'members') {
        $by = md_companies($pdo); $pi = md_plan_index($pdo); $cts = [];
        foreach ($pdo->query("SELECT id, title, policy_no FROM md_contracts") as $c) $cts[(int)$c['id']] = $c['title'] ?: $c['policy_no'];
        $mainNames = [];
        foreach (md_period_range($from, $to, 36) as $p) {
            $w = ['start_p <= ?', '(end_p IS NULL OR end_p > ?)']; $a = [$p, $p];
            if (!empty($f['company_id'])) { $w[] = 'company_id = ?'; $a[] = (int)$f['company_id']; }
            if (!empty($f['kind'])) { $w[] = 'kind = ?'; $a[] = $f['kind']; }
            if (!empty($f['plan_id'])) { $w[] = 'plan_id = ?'; $a[] = (int)$f['plan_id']; }
            $st = $pdo->prepare("SELECT * FROM md_members WHERE " . implode(' AND ', $w) . " ORDER BY company_id, main_nid, rel"); $st->execute($a);
            $rows = $st->fetchAll();
            foreach ($rows as $m) if ((int)$m['rel'] === 1) $mainNames[$m['nid']] = trim($m['first_name'] . ' ' . $m['last_name']);
            foreach ($rows as $m) {
                $eff = md_effective($by, (int)$m['company_id']); $prem = md_premium($pdo, $m, $eff); $er = md_employer_share($pdo, $m, $eff, $prem);
                $plan = $m['plan_id'] && isset($pi['byId'][(int)$m['plan_id']]) ? $pi['byId'][(int)$m['plan_id']] : null;
                $v = ['period' => md_period_fa($p), 'company' => $names[(int)$m['company_id']] ?? '', 'kind' => MD_KINDS[$m['kind']], 'nid' => $m['nid'], 'name' => trim($m['first_name'] . ' ' . $m['last_name']),
                      'first_name' => (string)$m['first_name'], 'last_name' => (string)$m['last_name'], 'rel' => MD_REL_CODES[(int)$m['rel']] ?? '', 'main_nid' => $m['main_nid'], 'main_name' => $mainNames[$m['main_nid']] ?? '',
                      'plan' => $plan ? $plan['name'] : '', 'premium' => $prem, 'employer' => $er, 'employee' => $prem - $er, 'father' => (string)$m['father'], 'gender' => $m['gender'] === null ? '' : ((int)$m['gender'] ? 'زن' : 'مرد'),
                      'birth_j' => (string)$m['birth_j'], 'personnel_no' => (string)$m['personnel_no'], 'mobile' => (string)$m['mobile'], 'sheba' => (string)$m['sheba'], 'start_j' => $m['start_j'] ?: $m['start_p'] . '/01',
                      'end_j' => $m['end_j'] ?: ($m['end_p'] ? $m['end_p'] . '/01' : ''), 'contract' => $plan ? ($cts[(int)$plan['contract_id']] ?? '') : ''];
                $out[] = array_map(function ($c) use ($v) { return $v[$c]; }, $cols);
                if (count($out) > 200000) break 2;
            }
        }
    } elseif ($type === 'bills') {
        foreach (md_bills_list($pdo, ['from' => $from, 'to' => $to, 'company_id' => $f['company_id'] ?? 0, 'kind' => $f['kind'] ?? '']) as $b) {
            $v = $b; $v['period'] = $b['period_fa']; $v['kind'] = $b['kind_fa'];
            $out[] = array_map(function ($c) use ($v) { return $v[$c]; }, $cols);
        }
    } else {
        $fj = $from . '/01'; $tj = $to . '/31';
        foreach (md_payments_list($pdo, ['company_id' => $f['company_id'] ?? 0, 'from_j' => $fj, 'to_j' => $tj]) as $p) {
            $v = $p; $v['kind'] = $p['kind_fa']; $v['payee'] = $p['payee_fa'];
            $out[] = array_map(function ($c) use ($v) { return $v[$c]; }, $cols);
        }
    }
    $num = []; foreach ($cols as $i => $c) if (in_array($c, ['premium', 'employer', 'employee', 'amount', 'vat', 'total', 'paid', 'remain', 'alloc', 'headcount'], true)) $num[] = $i;
    return ['headers' => array_map(function ($c) use ($cat) { return $cat[$c]; }, $cols), 'rows' => $out, 'numeric' => $num, 'type' => $type, 'from' => $from, 'to' => $to];
}
// اکسلِ کلی (هر شرکت یک شیت) یا یک شرکت: بیمه‌شدگانِ یک ماه با حق‌بیمه
function md_company_book($pdo, $period, $companyId = 0) {
    $names = md_company_names($pdo); $sheets = [];
    $ids = $companyId ? [(int)$companyId] : md_companies_with_members($pdo, $period);
    foreach ($ids as $cid) {
        $x = md_export_rows($pdo, ['type' => 'members', 'from' => $period, 'to' => $period, 'company_id' => $cid, 'cols' => ['kind', 'nid', 'name', 'rel', 'main_nid', 'plan', 'premium', 'employer', 'employee', 'personnel_no', 'start_j', 'end_j']]);
        if (!$x['rows']) continue;
        $sheets[] = ['name' => mb_substr(md_safe_name($names[$cid] ?? ('شرکت ' . $cid)), 0, 31), 'headers' => $x['headers'], 'rows' => $x['rows'], 'numeric' => $x['numeric'], 'title' => ($names[$cid] ?? '') . ' - ' . md_period_fa($period)];
    }
    if (!$sheets) throw new RuntimeException('در این ماه بیمه‌شده‌ای نیست.');
    if (!function_exists('xlsx_build_book')) require_once __DIR__ . '/_xlsx_writer.php';
    return xlsx_build_book($sheets);
}

// ---------------- داشبورد، هشدارها و یادآورها ----------------
function md_warnings($pdo, $period = null) {
    md_bill_ensure($pdo);
    $P = md_period_ok($period) ? $period : md_period_now(); $S = md_settings($pdo); $names = md_company_names($pdo);
    $w = [];
    // یک کدِ ملی در دو شرکت
    $st = $pdo->prepare("SELECT nid, kind, GROUP_CONCAT(DISTINCT company_id) cs, MAX(CONCAT(first_name, ' ', last_name)) nm FROM md_members WHERE start_p <= ? AND (end_p IS NULL OR end_p > ?) GROUP BY nid, kind HAVING COUNT(DISTINCT company_id) > 1 LIMIT 300");
    $st->execute([$P, $P]);
    foreach ($st->fetchAll() as $r) $w[] = ['type' => 'dup', 'level' => 'r', 'nid' => $r['nid'], 'name' => $r['nm'], 'msg' => 'هم‌زمان در چند شرکت (' . MD_KINDS[$r['kind']] . '): ' . implode('، ', array_map(function ($c) use ($names) { return $names[(int)$c] ?? $c; }, explode(',', $r['cs']))) . ' — فقط در شرکتِ دوم حساب می‌شود.'];
    // فرزندِ بالای سنِ مجاز
    $max = max(1, (int)$S['child_max_age']); $py = (int)substr($P, 0, 4); $pm = (int)substr($P, 5, 2);
    $st = $pdo->prepare("SELECT nid, company_id, first_name, last_name, birth_j FROM md_members WHERE rel = 3 AND birth_j IS NOT NULL AND start_p <= ? AND (end_p IS NULL OR end_p > ?)");
    $st->execute([$P, $P]);
    foreach ($st->fetchAll() as $r) { $by = (int)substr($r['birth_j'], 0, 4); $bm = (int)substr($r['birth_j'], 5, 2); $age = $py - $by - ($pm < $bm ? 1 : 0); if ($by && $age >= $max) $w[] = ['type' => 'age', 'level' => 'a', 'nid' => $r['nid'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'msg' => 'فرزند با ' . md_fa($age) . ' سال (بیش از سقفِ ' . md_fa($max) . ' سال) در ' . ($names[(int)$r['company_id']] ?? '')]; }
    // کدِ ملیِ نامعتبر و بدونِ طرح
    $st = $pdo->prepare("SELECT nid, company_id, first_name, last_name, plan_id FROM md_members WHERE start_p <= ? AND (end_p IS NULL OR end_p > ?)"); $st->execute([$P, $P]);
    $np = [];
    foreach ($st->fetchAll() as $r) { if (!md_nid_valid($r['nid'])) $w[] = ['type' => 'nid', 'level' => 'a', 'nid' => $r['nid'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'msg' => 'کدِ ملیِ نامعتبر (' . ($names[(int)$r['company_id']] ?? '') . ')']; if (!$r['plan_id']) $np[(int)$r['company_id']] = ($np[(int)$r['company_id']] ?? 0) + 1; }
    foreach ($np as $cid => $n) $w[] = ['type' => 'plan', 'level' => 'r', 'company_id' => $cid, 'name' => $names[$cid] ?? '', 'msg' => md_fa($n) . ' نفر طرح ندارند'];
    // شرکتِ دارای درمان/عمر بدونِ قرارداد
    $by = md_companies($pdo);
    foreach ($by as $cid => $c) { $e = md_effective($by, $cid); foreach (['health', 'life'] as $k) if ($e[$k . '_mode'] !== 'none' && !$e[$k . '_contract_id']) $w[] = ['type' => 'contract', 'level' => 'a', 'company_id' => $cid, 'name' => $c['name'], 'msg' => MD_KINDS[strtoupper($k)] . ' دارد ولی قرارداد ندارد']; }
    // مغایرت‌گیریِ انجام‌نشده / در سند نبود
    foreach (md_companies_with_members($pdo, $P) as $cid) { $r = md_recon_current($pdo, $cid, $P); if (!$r || $r['status'] !== 'CONFIRMED') $w[] = ['type' => 'recon', 'level' => $r && $r['status'] === 'MISSING' ? 'r' : 'a', 'company_id' => $cid, 'name' => $names[$cid] ?? '', 'msg' => 'مغایرت‌گیری با سند حقوقِ ' . md_period_fa($P) . ': ' . ($r ? MD_RECON_STATUS[$r['status']] : 'انجام نشده')]; }
    // لیستِ اعمال‌شده که واردِ پاسارگاد نشده
    foreach ($pdo->query("SELECT * FROM md_lists WHERE status = 'APPLIED' AND ((pas_add = 'NONE' AND add_n + trans_n > 0) OR (pas_rem = 'NONE' AND rem_n > 0)) ORDER BY id DESC LIMIT 100") as $L)
        $w[] = ['type' => 'pas', 'level' => 'a', 'company_id' => (int)$L['company_id'], 'name' => $names[(int)$L['company_id']] ?? '', 'list_id' => (int)$L['id'], 'msg' => MD_LIST_TYPES[$L['type']] . ' ' . md_period_fa($L['period']) . ' هنوز «ورودی به پاسارگاد» نگرفته'];
    return $w;
}
function md_reminders($pdo) {
    md_bill_ensure($pdo);
    $S = md_settings($pdo); $today = md_today_j();
    $ts = time() + 86400 * max(0, (int)$S['remind_days']); [$y, $m, $d] = jalali_from_gregorian_ts($ts); $soon = sprintf('%04d/%02d/%02d', $y, $m, $d);
    $o = [];
    foreach (md_bills_list($pdo, ['state' => 'unpaid']) as $b) if ($b['due_j'] && $b['due_j'] <= $soon) $o[] = $b + ['when' => $b['due_j'] < $today ? 'overdue' : 'soon'];
    usort($o, function ($a, $b) { return strcmp($a['due_j'], $b['due_j']); });
    return $o;
}
function md_dashboard($pdo, $period = null) {
    md_bill_ensure($pdo);
    $P = md_period_ok($period) ? $period : md_period_now();
    $k = ['health' => 0, 'life' => 0, 'companies' => 0];
    $st = $pdo->prepare("SELECT kind, COUNT(*) n, COUNT(DISTINCT company_id) c FROM md_members WHERE start_p <= ? AND (end_p IS NULL OR end_p > ?) GROUP BY kind"); $st->execute([$P, $P]);
    foreach ($st->fetchAll() as $r) $k[strtolower($r['kind'])] = (int)$r['n'];
    $k['companies'] = count(md_companies_with_members($pdo, $P));
    $months = md_period_range(md_period_add($P, -11), $P);
    $series = [];
    foreach ($months as $p) {
        $row = ['period' => $p, 'label' => md_period_fa($p), 'health' => 0, 'life' => 0, 'billed' => 0, 'paid' => 0];
        $q = $pdo->prepare("SELECT kind, COUNT(*) n FROM md_members WHERE start_p <= ? AND (end_p IS NULL OR end_p > ?) GROUP BY kind"); $q->execute([$p, $p]);
        foreach ($q->fetchAll() as $r) $row[strtolower($r['kind'])] = (int)$r['n'];
        $series[$p] = $row;
    }
    $bills = md_bills_list($pdo, ['from' => $months[0], 'to' => $P]);
    foreach ($bills as $b) if (isset($series[$b['period']])) { $series[$b['period']]['billed'] += $b['total']; $series[$b['period']]['paid'] += $b['paid']; }
    $all = md_bills_list($pdo, ['state' => 'unpaid']);
    $out = 0; $byCo = []; $over = 0;
    foreach ($all as $b) { $out += $b['remain']; $byCo[$b['company']] = ($byCo[$b['company']] ?? 0) + $b['remain']; if ($b['overdue']) $over++; }
    arsort($byCo);
    $cur = array_values(array_filter($bills, function ($b) use ($P) { return $b['period'] === $P; }));
    $k['billed'] = array_sum(array_column($cur, 'total')); $k['paid'] = array_sum(array_column($cur, 'paid')); $k['outstanding'] = $out; $k['overdue'] = $over;
    $rc = ['CONFIRMED' => 0, 'OPEN' => 0, 'MISSING' => 0, 'NONE' => 0];
    foreach (md_companies_with_members($pdo, $P) as $cid) { $r = md_recon_current($pdo, $cid, $P); $rc[$r ? $r['status'] : 'NONE']++; }
    $plans = [];
    $q = $pdo->prepare("SELECT plan_id, COUNT(*) n FROM md_members WHERE kind = 'HEALTH' AND start_p <= ? AND (end_p IS NULL OR end_p > ?) GROUP BY plan_id"); $q->execute([$P, $P]);
    $pi = md_plan_index($pdo);
    foreach ($q->fetchAll() as $r) $plans[] = ['name' => $r['plan_id'] && isset($pi['byId'][(int)$r['plan_id']]) ? $pi['byId'][(int)$r['plan_id']]['name'] : 'بدونِ طرح', 'n' => (int)$r['n']];
    $inbox = (int)$pdo->query("SELECT COUNT(*) FROM md_inbox WHERE status = 'NEW'")->fetchColumn();
    return ['period' => $P, 'period_fa' => md_period_fa($P), 'kpi' => $k + ['inbox' => $inbox], 'series' => array_values($series), 'outstanding_by_company' => array_slice(array_map(function ($n, $v) { return ['company' => $n, 'remain' => $v]; }, array_keys($byCo), $byCo), 0, 10),
            'recon' => $rc, 'plans' => $plans, 'warnings' => md_warnings($pdo, $P), 'reminders' => md_reminders($pdo)];
}
