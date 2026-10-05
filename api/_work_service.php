<?php
// فایل: api/_work_service.php
// «سرویسِ رفت‌وآمد» برای هر نفر: هر کاربر در «کارکرد من» برای هر روز مشخص می‌کند با سرویس رفت، برگشت یا هر دو.
// تعریفِ سرویس‌ها (نام، راننده، نرخِ هر مسیر و کاربرانی که اجازه‌ی انتخابش را دارند) فقط با مدیر کل و در «گزارش سرویس‌ها» است.
// جمعِ ماهانه‌ی هر نفر برای هر سرویس، ثبتِ پرداخت و رسیدِ PDF. جدول‌ها خودکار ساخته/به‌روز می‌شوند.

require_once __DIR__ . '/_work.php';

function ws_has_col($pdo, $table, $col) {
    try { $pdo->query("SELECT `$col` FROM `$table` LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}
function ws_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        driver VARCHAR(120) NULL,
        phone VARCHAR(30) NULL,
        car VARCHAR(160) NULL,
        price_go BIGINT NOT NULL DEFAULT 0,
        price_back BIGINT NOT NULL DEFAULT 0,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        assigned_users VARCHAR(2000) NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // هر نفر، هر روز: سرویسِ رفت و سرویسِ برگشت (خالی یعنی با سرویس نبود) و مبلغِ همان روز (نرخِ زمانِ ثبت)
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_service_days (
        user_id INT NOT NULL,
        wdate DATE NOT NULL,
        go_service_id INT NULL,
        back_service_id INT NULL,
        amount_go BIGINT NOT NULL DEFAULT 0,
        amount_back BIGINT NOT NULL DEFAULT 0,
        note VARCHAR(300) NULL,
        updated_at DATETIME NULL,
        updated_by INT NULL,
        PRIMARY KEY (user_id, wdate),
        KEY idx_go (go_service_id), KEY idx_back (back_service_id), KEY idx_date (wdate)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_service_pays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL DEFAULT 0,
        service_id INT NOT NULL,
        jy SMALLINT NOT NULL,
        jm TINYINT NOT NULL,
        amount BIGINT NOT NULL DEFAULT 0,
        paid_date DATE NULL,
        method VARCHAR(60) NULL,
        ref VARCHAR(120) NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NULL,
        created_by INT NULL,
        UNIQUE KEY uq_user_month (user_id, service_id, jy, jm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // نسخه‌ی قبلی (سرویسِ کلِ دفتر، بدونِ کاربر): ردیف‌ها به کسی که ثبتشان کرده بود داده می‌شوند
    try {
        if (!ws_has_col($pdo, 'work_services', 'assigned_users')) $pdo->exec("ALTER TABLE work_services ADD COLUMN assigned_users VARCHAR(2000) NULL AFTER is_active");
        if (!ws_has_col($pdo, 'work_service_days', 'user_id')) {
            $pdo->exec("ALTER TABLE work_service_days ADD COLUMN user_id INT NOT NULL DEFAULT 0 FIRST");
            $pdo->exec("UPDATE work_service_days SET user_id = COALESCE(updated_by, 0)");
            $pdo->exec("ALTER TABLE work_service_days DROP PRIMARY KEY, ADD PRIMARY KEY (user_id, wdate), ADD KEY idx_date (wdate)");
        }
        if (!ws_has_col($pdo, 'work_service_pays', 'user_id')) {
            $pdo->exec("ALTER TABLE work_service_pays ADD COLUMN user_id INT NOT NULL DEFAULT 0 AFTER id");
            $pdo->exec("UPDATE work_service_pays SET user_id = COALESCE(created_by, 0)");
            $pdo->exec("ALTER TABLE work_service_pays DROP INDEX uq_month, ADD UNIQUE KEY uq_user_month (user_id, service_id, jy, jm)");
        }
    } catch (Throwable $e) { error_log('[ws_ensure] ' . $e->getMessage()); }
}

function ws_services($pdo, $activeOnly = false) {
    ws_ensure($pdo);
    $rows = $pdo->query("SELECT * FROM work_services" . ($activeOnly ? " WHERE is_active = 1" : '') . " ORDER BY is_active DESC, is_default DESC, name")->fetchAll();
    return array_map('ws_service_out', $rows);
}
function ws_service($pdo, $id) {
    ws_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM work_services WHERE id = ?");
    $st->execute([intval($id)]);
    $r = $st->fetch();
    return $r ? ws_service_out($r) : null;
}
function ws_service_out($r) {
    $users = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($r['assigned_users'] ?? ''))))));
    return ['id' => intval($r['id']), 'name' => (string)$r['name'], 'driver' => (string)$r['driver'], 'phone' => (string)$r['phone'], 'car' => (string)$r['car'],
            'price_go' => intval($r['price_go']), 'price_back' => intval($r['price_back']), 'is_default' => (bool)$r['is_default'], 'is_active' => (bool)$r['is_active'],
            'users' => $users, 'note' => (string)$r['note']];
}
// سرویس‌هایی که این کاربر می‌تواند انتخاب کند: فعال و (بدونِ محدودیت یا اختصاص‌یافته به او)
function ws_allowed($pdo, $uid) {
    return array_values(array_filter(ws_services($pdo, true), function ($s) use ($uid) { return !$s['users'] || in_array(intval($uid), $s['users'], true); }));
}
// پیش‌فرضِ هر نفر: سرویسی که به خودش اختصاص داده شده، وگرنه پیش‌فرضِ کلی، وگرنه اولین سرویسِ مجاز
function ws_default_for($pdo, $uid, $allowed = null) {
    $allowed = $allowed ?? ws_allowed($pdo, $uid);
    $mine = array_values(array_filter($allowed, function ($s) use ($uid) { return in_array(intval($uid), $s['users'], true); }));
    usort($mine, function ($a, $b) { return intval($b['is_default']) - intval($a['is_default']); });
    if ($mine) return $mine[0]['id'];
    foreach ($allowed as $s) if ($s['is_default']) return $s['id'];
    return $allowed ? $allowed[0]['id'] : 0;
}
// ردیف‌های ثبت‌شده‌ی یک نفر در یک بازه: [تاریخ => ردیف]
function ws_user_days($pdo, $uid, $from, $to) {
    ws_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM work_service_days WHERE user_id = ? AND wdate BETWEEN ? AND ?");
    $st->execute([intval($uid), $from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['wdate']] = $r;
    return $out;
}

// یک نفر، یک ماه: همه‌ی روزها (حتی خالی) + جمعِ هر سرویس + پرداخت‌ها
function ws_month($pdo, $uid, $jy, $jm) {
    ws_ensure($pdo);
    [$from, $to] = wk_month_range($jy, $jm);
    $saved = ws_user_days($pdo, $uid, $from, $to);
    $all = [];
    foreach (ws_services($pdo) as $s) $all[$s['id']] = $s;
    $allowed = ws_allowed($pdo, $uid);
    $days = [];
    $tot = [];
    $used = [];
    for ($d = strtotime($from . ' 12:00:00'), $e = strtotime($to . ' 12:00:00'); $d <= $e; $d += 86400) {
        $g = date('Y-m-d', $d);
        $r = $saved[$g] ?? null;
        $go = $r ? intval($r['go_service_id']) : 0; $back = $r ? intval($r['back_service_id']) : 0;
        $D = ['date' => $g, 'jdate' => wk_g2j($g), 'dow' => intval(date('w', $d)), 'off' => wk_is_off($pdo, $g), 'saved' => (bool)$r,
              'go' => $go, 'back' => $back, 'amount_go' => $go ? intval($r['amount_go']) : 0, 'amount_back' => $back ? intval($r['amount_back']) : 0,
              'note' => $r ? (string)$r['note'] : ''];
        $D['amount'] = $D['amount_go'] + $D['amount_back'];
        $days[] = $D;
        foreach (['go', 'back'] as $k) {
            if (!$D[$k]) continue;
            $sid = $D[$k];
            $used[$sid] = true;
            if (!isset($tot[$sid])) $tot[$sid] = ['service_id' => $sid, 'go' => 0, 'back' => 0, 'days' => 0, 'both' => 0, 'amount' => 0, '_d' => []];
            $tot[$sid][$k]++;
            $tot[$sid]['amount'] += $D['amount_' . $k];
            $tot[$sid]['_d'][$g] = true;
        }
        if ($D['go'] && $D['go'] === $D['back']) $tot[$D['go']]['both']++;
    }
    foreach ($tot as &$t) { $t['days'] = count($t['_d']); unset($t['_d']); $t['name'] = $all[$t['service_id']]['name'] ?? ('سرویس #' . $t['service_id']); }
    unset($t);
    $st = $pdo->prepare("SELECT * FROM work_service_pays WHERE user_id = ? AND jy = ? AND jm = ?");
    $st->execute([intval($uid), $jy, $jm]);
    $pays = [];
    foreach ($st->fetchAll() as $p) $pays[intval($p['service_id'])] = ws_pay_out($p);
    // سرویس‌های قابلِ انتخاب + سرویس‌هایی که در همین ماه استفاده شده‌اند (حتی اگر بعداً غیرفعال/محدود شده باشند)
    $services = [];
    foreach ($allowed as $s) $services[$s['id']] = $s + ['selectable' => true];
    foreach (array_keys($used) as $sid) if (!isset($services[$sid]) && isset($all[$sid])) $services[$sid] = $all[$sid] + ['selectable' => false];
    return ['jy' => $jy, 'jm' => $jm, 'month_name' => jalali_month_name($jm), 'user_id' => intval($uid), 'days' => $days, 'totals' => array_values($tot),
            'pays' => (object)$pays, 'services' => array_values($services), 'default_id' => ws_default_for($pdo, $uid, $allowed)];
}
function ws_pay_out($p) {
    return ['id' => intval($p['id']), 'service_id' => intval($p['service_id']), 'amount' => intval($p['amount']), 'paid_date' => $p['paid_date'] ? wk_g2j($p['paid_date']) : '',
            'method' => (string)$p['method'], 'ref' => (string)$p['ref'], 'note' => (string)$p['note']];
}

// ذخیره‌ی چند روزِ یک نفر. فقط سرویس‌های مجازِ همان نفر (یا سرویسی که همان روز از قبل ثبت شده) پذیرفته می‌شود.
// نرخ: اگر سرویسِ همان مسیر عوض نشده باشد مبلغِ قبلی می‌ماند (مگر reprice)، وگرنه نرخِ فعلیِ سرویس.
// اگر «note» فرستاده نشود، توضیحِ قبلیِ آن روز می‌ماند.
function ws_days_save($pdo, $uid, array $items, $me, $reprice = false) {
    ws_ensure($pdo);
    $uid = intval($uid);
    $all = [];
    foreach (ws_services($pdo) as $s) $all[$s['id']] = $s;
    $ok = [];
    foreach (ws_allowed($pdo, $uid) as $s) $ok[$s['id']] = true;
    $get = $pdo->prepare("SELECT * FROM work_service_days WHERE user_id = ? AND wdate = ?");
    $up = $pdo->prepare("INSERT INTO work_service_days (user_id, wdate, go_service_id, back_service_id, amount_go, amount_back, note, updated_at, updated_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                         ON DUPLICATE KEY UPDATE go_service_id = VALUES(go_service_id), back_service_id = VALUES(back_service_id), amount_go = VALUES(amount_go),
                                                 amount_back = VALUES(amount_back), note = VALUES(note), updated_at = NOW(), updated_by = VALUES(updated_by)");
    $del = $pdo->prepare("DELETE FROM work_service_days WHERE user_id = ? AND wdate = ?");
    $n = 0;
    foreach ($items as $it) {
        $g = wk_j2g($it['date'] ?? '') ?: (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($it['date'] ?? '')) ? $it['date'] : null);
        if (!$g) continue;
        $get->execute([$uid, $g]);
        $old = $get->fetch() ?: null;
        $go = intval($it['go'] ?? 0); $back = intval($it['back'] ?? 0);
        if ($go && !(isset($ok[$go]) || ($old && intval($old['go_service_id']) === $go))) throw new RuntimeException('سرویسِ «' . ($all[$go]['name'] ?? $go) . '» برای این کاربر مجاز نیست.');
        if ($back && !(isset($ok[$back]) || ($old && intval($old['back_service_id']) === $back))) throw new RuntimeException('سرویسِ «' . ($all[$back]['name'] ?? $back) . '» برای این کاربر مجاز نیست.');
        $note = array_key_exists('note', $it) ? trim(mb_substr((string)$it['note'], 0, 300)) : ($old ? (string)$old['note'] : '');
        if (!$go && !$back && $note === '') { $del->execute([$uid, $g]); $n++; continue; }
        $aGo = $go ? (($old && intval($old['go_service_id']) === $go && !$reprice) ? intval($old['amount_go']) : intval($all[$go]['price_go'] ?? 0)) : 0;
        $aBack = $back ? (($old && intval($old['back_service_id']) === $back && !$reprice) ? intval($old['amount_back']) : intval($all[$back]['price_back'] ?? 0)) : 0;
        $up->execute([$uid, $g, $go ?: null, $back ?: null, $aGo, $aBack, $note !== '' ? $note : null, $me]);
        $n++;
        if ($n >= 400) break;
    }
    return $n;
}

// نمای کلیِ یک ماه برای «گزارش سرویس‌ها»: هر نفر ← روزها، رفت، برگشت، مبلغ، سرویس‌ها و پرداخت
function ws_overview($pdo, $jy, $jm, array $users) {
    ws_ensure($pdo);
    [$from, $to] = wk_month_range($jy, $jm);
    $names = [];
    foreach (ws_services($pdo) as $s) $names[$s['id']] = $s['name'];
    $agg = [];
    $st = $pdo->prepare("SELECT * FROM work_service_days WHERE wdate BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $u = intval($r['user_id']);
        $a = &$agg[$u];
        if (!$a) $a = ['days' => 0, 'go' => 0, 'back' => 0, 'amount' => 0, 'svc' => []];
        if ($r['go_service_id'] || $r['back_service_id']) $a['days']++;
        foreach (['go' => 'go_service_id', 'back' => 'back_service_id'] as $k => $c) {
            if (!$r[$c]) continue;
            $a[$k]++; $a['amount'] += intval($r['amount_' . $k]);
            $sid = intval($r[$c]);
            $a['svc'][$sid] = ($a['svc'][$sid] ?? 0) + intval($r['amount_' . $k]);
        }
        unset($a);
    }
    $paid = [];
    $st = $pdo->prepare("SELECT user_id, service_id, amount, paid_date FROM work_service_pays WHERE jy = ? AND jm = ?");
    $st->execute([$jy, $jm]);
    foreach ($st->fetchAll() as $p) $paid[intval($p['user_id'])][intval($p['service_id'])] = intval($p['amount']);
    $rows = [];
    foreach ($users as $u) {
        $id = intval($u['id']);
        $a = $agg[$id] ?? ['days' => 0, 'go' => 0, 'back' => 0, 'amount' => 0, 'svc' => []];
        $svc = [];
        foreach ($a['svc'] as $sid => $amt) $svc[] = ['id' => $sid, 'name' => $names[$sid] ?? ('#' . $sid), 'amount' => $amt, 'paid' => $paid[$id][$sid] ?? null];
        $paidSum = array_sum($paid[$id] ?? []);
        $rows[] = ['user_id' => $id, 'name' => $u['full_name'], 'role' => $u['role'], 'days' => $a['days'], 'go' => $a['go'], 'back' => $a['back'],
                   'amount' => $a['amount'], 'services' => $svc, 'paid' => $paidSum,
                   'pay_status' => !$a['amount'] ? ($paidSum ? 'paid' : 'none') : ($paidSum >= $a['amount'] ? 'paid' : ($paidSum ? 'partial' : 'unpaid'))];
    }
    return $rows;
}

// ---------------------------------------------------------------------
//  رسیدِ PDF پرداخت به سرویس (یک سرویس، یک ماه)
// ---------------------------------------------------------------------
function ws_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function ws_money($n) { return ws_fa(number_format(intval($n))); }

// ابزارِ مشترکِ PDFها: صفحه‌ی A4، قلمِ وزیر، متنِ راست‌به‌چپ با مختصاتِ معمولی (x از چپ) و جعبه‌ی گرد
function ws_pdf_kit($title) {
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0); $pdf->SetAutoPageBreak(false, 0);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->SetCreator('بیمه با ما'); $pdf->SetAuthor('بیمه با ما');
    $pdf->SetTitle($title);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->AddPage();
    $W = 210;
    $hex = function ($h) { $h = ltrim($h, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; };
    $T = function ($x, $y, $w, $h, $text, $size = 9, $style = '', $color = '#0f172a', $align = 'R') use ($pdf, $W, $hex) {
        $pdf->setRTL(true);
        $pdf->SetFont('vazir', $style, $size);
        $pdf->SetTextColor(...$hex($color));
        // در حالتِ راست‌به‌چپ «R» یعنی ابتدای سطر (راست) و «L» انتهای آن (چپ)؛ نشانه‌ی RLM جلوی جابه‌جاشدنِ عددِ اولِ سطر را می‌گیرد
        $pdf->SetXY($W - ($x + $w), $y);
        $pdf->Cell($w, $h, (preg_match("/[\x{0600}-\x{06EF}\x{06FA}-\x{06FF}]/u", $text) ? "\u{200F}" : "") . $text, 0, 0, $align === 'C' ? 'C' : ($align === 'L' ? 'R' : 'L'), false, '', 0, false, 'T', 'M');
        $pdf->setRTL(false);
    };
    $box = function ($x, $y, $w, $h, $fill, $r = 3, $border = null) use ($pdf, $hex) {
        $pdf->SetFillColor(...$hex($fill));
        if ($border) { $pdf->SetDrawColor(...$hex($border)); $pdf->SetLineWidth(0.25); }
        $pdf->RoundedRect($x, $y, $w, $h, $r, '1111', $border ? 'DF' : 'F');
    };
    return [$pdf, $T, $box, $hex, $W];
}

function ws_receipt_pdf($pdo, $uid, $sid, $jy, $jm) {
    $svc = ws_service($pdo, $sid);
    if (!$svc) throw new RuntimeException('سرویس پیدا نشد.');
    $st = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $st->execute([intval($uid)]);
    $person = (string)($st->fetchColumn() ?: '');
    $M = ws_month($pdo, $uid, $jy, $jm);
    $pays = (array)$M['pays'];
    $pay = $pays[$sid] ?? null;
    $tot = ['go' => 0, 'back' => 0, 'days' => 0, 'both' => 0, 'amount' => 0];
    foreach ($M['totals'] as $t) if ($t['service_id'] === $sid) $tot = $t;

    [$pdf, $T, $box, $hex, $W] = ws_pdf_kit('رسید سرویس ' . $svc['name'] . ' ' . $person . ' ' . jalali_month_name($jm) . ' ' . $jy);

    // ---- سربرگ ----
    $pdf->LinearGradient(0, 0, $W, 40, $hex('#312e81'), $hex('#0e7490'), [0, 0, 1, 0]);
    $pdf->SetAlpha(0.12); $pdf->SetFillColor(255, 255, 255);
    $pdf->Circle(18, 6, 22, 0, 360, 'F'); $pdf->Circle(190, 42, 26, 0, 360, 'F'); $pdf->Circle(120, -8, 14, 0, 360, 'F');
    $pdf->SetAlpha(1);
    $T(70, 8, 128, 10, 'رسیدِ پرداختِ سرویسِ رفت‌وآمد', 19, 'B', '#ffffff');
    $T(70, 19, 128, 7, 'کارکردِ ' . jalali_month_name($jm) . ' ' . ws_fa($jy) . ' · سرویسِ «' . $svc['name'] . '»', 11, '', '#e0e7ff');
    $T(70, 27, 128, 6, ($person !== '' ? 'همکار: ' . $person . ' · ' : '') . 'بیمه با ما', 9, 'B', '#a5f3fc');
    // برچسبِ شماره و تاریخ
    $box(12, 9, 52, 22, '#ffffff', 4);
    $T(14, 11, 48, 6, 'شماره‌ی رسید', 7.5, '', '#64748b', 'C');
    $T(14, 16.5, 48, 6, ws_fa(sprintf('SV-%d-%d-%04d%02d', $sid, $uid, $jy, $jm)), 10, 'B', '#312e81', 'C');
    $T(14, 23, 48, 6, 'صدور: ' . ws_fa(wk_g2j(date('Y-m-d'))), 8, '', '#475569', 'C');

    // ---- کارت‌های اطلاعات ----
    $y = 46;
    $cards = [
        ['همکار / سرویس', ($person !== '' ? $person . ' · ' : '') . $svc['name'], '#eef2ff', '#4338ca'],
        ['راننده', $svc['driver'] ?: '—', '#ecfeff', '#0e7490'],
        ['تلفن / خودرو', trim(ws_fa($svc['phone']) . ($svc['car'] ? ' · ' . $svc['car'] : '')) ?: '—', '#f0fdf4', '#15803d'],
        ['نرخِ هر مسیر (ریال)', 'رفت ' . ws_money($svc['price_go']) . ' · برگشت ' . ws_money($svc['price_back']), '#fff7ed', '#c2410c'],
    ];
    $cw = (186 - 3 * 3) / 4;
    foreach ($cards as $i => $c) {
        $x = 12 + (3 - $i) * ($cw + 3);   // از راست به چپ
        $box($x, $y, $cw, 17, $c[2], 3);
        $T($x + 3, $y + 2, $cw - 6, 5, $c[0], 7.5, '', '#64748b');
        $T($x + 3, $y + 8, $cw - 6, 7, $c[1], mb_strlen($c[1]) > 26 ? 7.5 : 9.5, 'B', $c[3]);
    }

    // ---- جدولِ روزها ----
    $y = 68;
    $cols = [['روز', 14], ['تاریخ', 26], ['روزِ هفته', 24], ['رفت', 30], ['برگشت', 30], ['وضعیت', 30], ['مبلغِ روز (ریال)', 32]];
    $tx = function ($idx) use ($cols) { $x = 198; for ($i = 0; $i <= $idx; $i++) $x -= $cols[$i][1]; return $x; };
    $box(12, $y, 186, 7, '#1e293b', 2);
    foreach ($cols as $i => $c) $T($tx($i), $y, $c[1], 7, $c[0], 8, 'B', '#ffffff', 'C');
    $y += 7.6;
    $dowFa = [6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه'];
    $rh = count($M['days']) > 30 ? 4.75 : 4.9;
    foreach ($M['days'] as $k => $D) {
        $go = $D['go'] === $sid; $back = $D['back'] === $sid;
        $amt = ($go ? $D['amount_go'] : 0) + ($back ? $D['amount_back'] : 0);
        $bg = ($go || $back) ? ($k % 2 ? '#f5f7ff' : '#ffffff') : ($D['off'] ? '#fff1f2' : '#f8fafc');
        $pdf->SetFillColor(...$hex($bg));
        $pdf->Rect(12, $y, 186, $rh, 'F');
        $dim = !$go && !$back;
        $T($tx(0), $y, $cols[0][1], $rh, ws_fa(intval(substr($D['jdate'], 8, 2))), 8, 'B', $D['off'] ? '#e11d48' : '#334155', 'C');
        $T($tx(1), $y, $cols[1][1], $rh, ws_fa($D['jdate']), 7.5, '', '#475569', 'C');
        $T($tx(2), $y, $cols[2][1], $rh, $dowFa[$D['dow']] . ($D['off'] ? ' (تعطیل)' : ''), 7.5, '', $D['off'] ? '#e11d48' : '#475569', 'C');
        // نشانِ رفت/برگشت
        foreach ([[3, $go, '#0284c7'], [4, $back, '#7c3aed']] as [$ci, $on, $clr]) {
            $cx = $tx($ci) + $cols[$ci][1] / 2;
            if ($on) {
                $pdf->SetFillColor(...$hex($clr));
                $pdf->RoundedRect($cx - 7, $y + 0.8, 14, $rh - 1.6, 1.4, '1111', 'F');
                // تیک (با خط؛ قلمِ وزیر نشانه‌ی ✓ ندارد)
                $pdf->SetDrawColor(255, 255, 255); $pdf->SetLineWidth(0.45);
                $my = $y + $rh / 2;
                $pdf->Line($cx - 1.6, $my, $cx - 0.4, $my + 1.1); $pdf->Line($cx - 0.4, $my + 1.1, $cx + 1.8, $my - 1.2);
            } else {
                $T($tx($ci), $y, $cols[$ci][1], $rh, '—', 8, '', '#cbd5e1', 'C');
            }
        }
        $st = $go && $back ? ['رفت و برگشت', '#047857'] : ($go ? ['فقط رفت', '#0369a1'] : ($back ? ['فقط برگشت', '#6d28d9'] : [$D['off'] ? 'تعطیل' : 'بدونِ سرویس', '#94a3b8']));
        $T($tx(5), $y, $cols[5][1], $rh, $st[0], 7.5, $dim ? '' : 'B', $st[1], 'C');
        $T($tx(6), $y, $cols[6][1], $rh, $amt ? ws_money($amt) : '—', 8, $amt ? 'B' : '', $amt ? '#0f172a' : '#cbd5e1', 'C');
        $y += $rh;
    }
    $pdf->SetDrawColor(...$hex('#e2e8f0')); $pdf->SetLineWidth(0.3);
    $pdf->Rect(12, 75.6, 186, $y - 75.6, 'D');

    // ---- جمع‌ها ----
    $y += 4;
    $sum = [
        ['روزهای استفاده', ws_fa($tot['days']) . ' روز', '#4338ca'],
        ['مسیرِ رفت', ws_fa($tot['go']) . ' بار', '#0284c7'],
        ['مسیرِ برگشت', ws_fa($tot['back']) . ' بار', '#7c3aed'],
        ['رفت و برگشت', ws_fa($tot['both']) . ' روز', '#047857'],
    ];
    $sw = 26;
    foreach ($sum as $i => $s) {
        $x = 198 - ($i + 1) * $sw - $i * 2;
        $box($x, $y, $sw, 17, '#f8fafc', 3, '#e2e8f0');
        $T($x + 1, $y + 2, $sw - 2, 5, $s[0], 7, '', '#64748b', 'C');
        $T($x + 1, $y + 8, $sw - 2, 7, $s[1], 10, 'B', $s[2], 'C');
    }
    // جمعِ کل
    $gx = 12; $gw = 198 - 4 * $sw - 3 * 2 - 3 - $gx;
    $pdf->LinearGradient($gx, $y, $gw, 17, $hex('#047857'), $hex('#0d9488'), [1, 0, 0, 0]);
    $T($gx + 4, $y + 1.5, $gw - 8, 6, 'مبلغِ قابلِ پرداخت', 8, '', '#d1fae5');
    $T($gx + 4, $y + 7, $gw - 8, 8, ws_money($tot['amount']) . ' ریال', 14, 'B', '#ffffff');
    $T($gx + 4, $y + 7.5, $gw - 8, 8, 'معادلِ ' . ws_money(intdiv($tot['amount'], 10)) . ' تومان', 7.5, '', '#a7f3d0', 'L');
    $y += 21;

    // ---- وضعیتِ پرداخت ----
    if ($pay && $pay['paid_date']) {
        $box(12, $y, 186, 9, '#ecfdf5', 2.5, '#a7f3d0');
        $line = 'پرداخت شد · ' . ws_fa($pay['paid_date']) . ' · مبلغ ' . ws_money($pay['amount']) . ' ریال'
              . ($pay['method'] ? ' · ' . $pay['method'] : '') . ($pay['ref'] ? ' · پیگیری ' . ws_fa($pay['ref']) : '') . ($pay['note'] ? ' · ' . $pay['note'] : '');
        $T(16, $y, 178, 9, $line, 8.5, 'B', '#047857');
    } else {
        $box(12, $y, 186, 9, '#fffbeb', 2.5, '#fde68a');
        $T(16, $y, 178, 9, 'هنوز پرداختی برای این ماه ثبت نشده است.', 8.5, 'B', '#b45309');
    }
    $y += 12;

    // ---- امضاها ----
    $sh = min(22, 290 - $y);
    if ($sh >= 14) {
        foreach ([['تحویل‌گیرنده (راننده‌ی سرویس)', 105], ['پرداخت‌کننده', 12]] as [$lbl, $x]) {
            $pdf->SetDrawColor(...$hex('#cbd5e1')); $pdf->SetLineWidth(0.3);
            $pdf->SetLineStyle(['dash' => '1,1']);
            $pdf->RoundedRect($x, $y, 93, $sh, 3, '1111', 'D');
            $pdf->SetLineStyle(['dash' => 0]);
            $T($x + 4, $y + 2, 85, 6, $lbl . ' — نام و امضا', 8, 'B', '#475569');
        }
    }
    $T(12, 291, 186, 4, 'این رسید از «گزارش سرویس‌ها / کارکرد من» در پنلِ بیمه با ما ساخته شده است.', 6.5, '', '#94a3b8', 'C');
    return $pdf->Output('', 'S');
}

// ---------------------------------------------------------------------
//  گزارش و رسیدِ ماهانه‌ی یک سرویس برای همه‌ی همکاران (جمعِ هزینه‌ها)
// ---------------------------------------------------------------------
// داده‌ی یک سرویس در یک ماه: هر روز ← چه کسانی رفت/برگشت، مبلغِ روز؛ هر نفر ← جمع و پرداخت
function ws_service_month($pdo, $sid, $jy, $jm) {
    ws_ensure($pdo);
    [$from, $to] = wk_month_range($jy, $jm);
    $st = $pdo->prepare("SELECT d.*, u.full_name FROM work_service_days d LEFT JOIN users u ON u.id = d.user_id
                         WHERE d.wdate BETWEEN ? AND ? AND (d.go_service_id = ? OR d.back_service_id = ?) ORDER BY u.full_name");
    $st->execute([$from, $to, $sid, $sid]);
    $byDate = []; $people = [];
    foreach ($st->fetchAll() as $r) {
        $uid = intval($r['user_id']);
        $go = intval($r['go_service_id']) === $sid; $back = intval($r['back_service_id']) === $sid;
        $amt = ($go ? intval($r['amount_go']) : 0) + ($back ? intval($r['amount_back']) : 0);
        $byDate[$r['wdate']][] = ['uid' => $uid, 'name' => (string)($r['full_name'] ?: ('#' . $uid)), 'go' => $go, 'back' => $back, 'amount' => $amt];
        $p = &$people[$uid];
        if (!$p) $p = ['uid' => $uid, 'name' => (string)($r['full_name'] ?: ('#' . $uid)), 'days' => 0, 'go' => 0, 'back' => 0, 'amount' => 0, 'paid' => 0];
        $p['days']++; $p['go'] += $go ? 1 : 0; $p['back'] += $back ? 1 : 0; $p['amount'] += $amt;
        unset($p);
    }
    $st = $pdo->prepare("SELECT user_id, SUM(amount) a FROM work_service_pays WHERE service_id = ? AND jy = ? AND jm = ? GROUP BY user_id");
    $st->execute([$sid, $jy, $jm]);
    foreach ($st->fetchAll() as $r) if (isset($people[intval($r['user_id'])])) $people[intval($r['user_id'])]['paid'] = intval($r['a']);
    $days = [];
    $tot = ['days' => 0, 'go' => 0, 'back' => 0, 'amount' => 0, 'paid' => 0, 'people' => count($people)];
    for ($d = strtotime($from . ' 12:00:00'), $e = strtotime($to . ' 12:00:00'); $d <= $e; $d += 86400) {
        $g = date('Y-m-d', $d);
        $rows = $byDate[$g] ?? [];
        $D = ['date' => $g, 'jdate' => wk_g2j($g), 'dow' => intval(date('w', $d)), 'off' => wk_is_off($pdo, $g), 'rows' => $rows,
              'go' => count(array_filter($rows, fn($x) => $x['go'])), 'back' => count(array_filter($rows, fn($x) => $x['back'])),
              'amount' => array_sum(array_column($rows, 'amount'))];
        if ($rows) $tot['days']++;
        $tot['go'] += $D['go']; $tot['back'] += $D['back']; $tot['amount'] += $D['amount'];
        $days[] = $D;
    }
    $tot['paid'] = array_sum(array_column($people, 'paid'));
    usort($people, fn($a, $b) => $b['amount'] <=> $a['amount']);
    return ['days' => $days, 'people' => array_values($people), 'totals' => $tot];
}

function ws_service_pdf($pdo, $sid, $jy, $jm) {
    $svc = ws_service($pdo, $sid);
    if (!$svc) throw new RuntimeException('سرویس پیدا نشد.');
    $M = ws_service_month($pdo, $sid, $jy, $jm);
    $tot = $M['totals'];
    [$pdf, $T, $box, $hex, $W] = ws_pdf_kit('گزارش سرویس ' . $svc['name'] . ' ' . jalali_month_name($jm) . ' ' . $jy);
    $header = function ($cont = false) use ($pdf, $T, $box, $hex, $W, $svc, $jy, $jm, $sid, $tot) {
        $h = $cont ? 22 : 36;
        $pdf->LinearGradient(0, 0, $W, $h, $hex('#0f766e'), $hex('#312e81'), [0, 0, 1, 0]);
        $pdf->SetAlpha(0.12); $pdf->SetFillColor(255, 255, 255);
        $pdf->Circle(18, 6, 22, 0, 360, 'F'); $pdf->Circle(190, $h + 2, 26, 0, 360, 'F');
        $pdf->SetAlpha(1);
        if ($cont) { $T(12, 7, 186, 9, 'گزارشِ ماهانه‌ی سرویسِ «' . $svc['name'] . '» · ' . jalali_month_name($jm) . ' ' . ws_fa($jy) . ' (ادامه)', 12, 'B', '#ffffff'); return 30; }
        $T(70, 8, 128, 10, 'گزارش و رسیدِ ماهانه‌ی سرویس', 19, 'B', '#ffffff');
        $T(70, 19, 128, 7, 'سرویسِ «' . $svc['name'] . '» · کارکردِ ' . jalali_month_name($jm) . ' ' . ws_fa($jy), 11, '', '#ccfbf1');
        $T(70, 27, 128, 6, 'جمعِ ' . ws_fa($tot['people']) . ' همکار · بیمه با ما', 9, 'B', '#a5f3fc');
        $box(12, 9, 52, 22, '#ffffff', 4);
        $T(14, 11, 48, 6, 'شماره‌ی گزارش', 7.5, '', '#64748b', 'C');
        $T(14, 16.5, 48, 6, ws_fa(sprintf('SVA-%d-%04d%02d', $sid, $jy, $jm)), 10, 'B', '#0f766e', 'C');
        $T(14, 23, 48, 6, 'صدور: ' . ws_fa(wk_g2j(date('Y-m-d'))), 8, '', '#475569', 'C');
        return 41;
    };
    $y = $header();
    // ---- کارت‌ها ----
    $cards = [
        ['سرویس', $svc['name'], '#ecfdf5', '#047857'],
        ['راننده', $svc['driver'] ?: '—', '#ecfeff', '#0e7490'],
        ['تلفن / خودرو', trim(ws_fa($svc['phone']) . ($svc['car'] ? ' · ' . $svc['car'] : '')) ?: '—', '#eef2ff', '#4338ca'],
        ['نرخِ هر مسیر (ریال)', 'رفت ' . ws_money($svc['price_go']) . ' · برگشت ' . ws_money($svc['price_back']), '#fff7ed', '#c2410c'],
    ];
    $cw = (186 - 9) / 4;
    foreach ($cards as $i => $c) {
        $x = 12 + (3 - $i) * ($cw + 3);
        $box($x, $y, $cw, 14, $c[2], 3);
        $T($x + 3, $y + 1.5, $cw - 6, 5, $c[0], 7.2, '', '#64748b');
        $T($x + 3, $y + 6.5, $cw - 6, 6, $c[1], mb_strlen($c[1]) > 26 ? 7.5 : 9.5, 'B', $c[3]);
    }
    $y += 18;

    // ---- جدولِ روزها: چند نفر رفت/برگشت، چه کسانی، مبلغِ روز ----
    $cols = [['روز', 11], ['تاریخ', 22], ['روزِ هفته', 22], ['رفت (نفر)', 19], ['برگشت (نفر)', 19], ['همکاران', 63], ['مبلغِ روز (ریال)', 30]];
    $tx = function ($idx) use ($cols) { $x = 198; for ($i = 0; $i <= $idx; $i++) $x -= $cols[$i][1]; return $x; };
    $box(12, $y, 186, 7, '#1e293b', 2);
    foreach ($cols as $i => $c) $T($tx($i), $y, $c[1], 7, $c[0], 7.5, 'B', '#ffffff', 'C');
    $y += 7.6; $top = $y;
    $dowFa = [6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه'];
    $rh = count($M['days']) > 30 ? 4.3 : 4.4;
    foreach ($M['days'] as $k => $D) {
        $used = (bool)$D['rows'];
        $pdf->SetFillColor(...$hex($used ? ($k % 2 ? '#f0fdfa' : '#ffffff') : ($D['off'] ? '#fff1f2' : '#f8fafc')));
        $pdf->Rect(12, $y, 186, $rh, 'F');
        $T($tx(0), $y, $cols[0][1], $rh, ws_fa(intval(substr($D['jdate'], 8, 2))), 8, 'B', $D['off'] ? '#e11d48' : '#334155', 'C');
        $T($tx(1), $y, $cols[1][1], $rh, ws_fa($D['jdate']), 7.3, '', '#475569', 'C');
        $T($tx(2), $y, $cols[2][1], $rh, $dowFa[$D['dow']] . ($D['off'] ? ' (تعطیل)' : ''), 7.3, '', $D['off'] ? '#e11d48' : '#475569', 'C');
        foreach ([[3, $D['go'], '#0284c7'], [4, $D['back'], '#7c3aed']] as [$ci, $n, $clr]) {
            if ($n) {
                $cx = $tx($ci) + $cols[$ci][1] / 2;
                $pdf->SetFillColor(...$hex($clr));
                $pdf->RoundedRect($cx - 5, $y + 0.7, 10, $rh - 1.4, 1.4, '1111', 'F');
                $T($cx - 5, $y, 10, $rh, ws_fa($n), 8, 'B', '#ffffff', 'C');
            } else $T($tx($ci), $y, $cols[$ci][1], $rh, '—', 8, '', '#cbd5e1', 'C');
        }
        // نامِ همکاران (رفت ← / برگشت → کنارِ هر نام)؛ اگر جا نشد کوتاه می‌شود
        $names = implode('، ', array_map(fn($x) => $x['name'] . ($x['go'] && $x['back'] ? '' : ($x['go'] ? ' (رفت)' : ' (برگشت)')), $D['rows']));
        if (mb_strlen($names) > 58) $names = mb_substr($names, 0, 56) . '…';
        $T($tx(5) + 1, $y, $cols[5][1] - 2, $rh, $used ? $names : ($D['off'] ? 'تعطیل' : 'بدونِ سرویس'), 6.8, '', $used ? '#334155' : '#94a3b8', 'C');
        $T($tx(6), $y, $cols[6][1], $rh, $D['amount'] ? ws_money($D['amount']) : '—', 8, $D['amount'] ? 'B' : '', $D['amount'] ? '#0f172a' : '#cbd5e1', 'C');
        $y += $rh;
    }
    $pdf->SetDrawColor(...$hex('#e2e8f0')); $pdf->SetLineWidth(0.3);
    $pdf->Rect(12, $top, 186, $y - $top, 'D');
    // ردیفِ جمعِ جدول
    $box(12, $y + 0.6, 186, 6.5, '#0f172a', 1.5);
    $T($tx(2), $y + 0.6, $cols[0][1] + $cols[1][1] + $cols[2][1], 6.5, 'جمعِ ماه', 8.5, 'B', '#ffffff', 'C');
    $T($tx(3), $y + 0.6, $cols[3][1], 6.5, ws_fa($tot['go']), 8.5, 'B', '#7dd3fc', 'C');
    $T($tx(4), $y + 0.6, $cols[4][1], 6.5, ws_fa($tot['back']), 8.5, 'B', '#c4b5fd', 'C');
    $T($tx(5), $y + 0.6, $cols[5][1], 6.5, ws_fa($tot['days']) . ' روز با سرویس · ' . ws_fa($tot['people']) . ' همکار', 7.5, '', '#e2e8f0', 'C');
    $T($tx(6), $y + 0.6, $cols[6][1], 6.5, ws_money($tot['amount']), 8.5, 'B', '#6ee7b7', 'C');
    $y += 11;

    // ---- جمع‌ها ----
    if ($y + 18 > 287) { $pdf->AddPage(); $y = $header(true); }
    $sum = [['روزهای سرویس', ws_fa($tot['days']) . ' روز', '#0f766e'], ['همکاران', ws_fa($tot['people']) . ' نفر', '#4338ca'],
            ['مسیرِ رفت', ws_fa($tot['go']) . ' بار', '#0284c7'], ['مسیرِ برگشت', ws_fa($tot['back']) . ' بار', '#7c3aed']];
    $sw = 26;
    foreach ($sum as $i => $s) {
        $x = 198 - ($i + 1) * $sw - $i * 2;
        $box($x, $y, $sw, 15, '#f8fafc', 3, '#e2e8f0');
        $T($x + 1, $y + 1.5, $sw - 2, 5, $s[0], 7, '', '#64748b', 'C');
        $T($x + 1, $y + 7, $sw - 2, 7, $s[1], 10, 'B', $s[2], 'C');
    }
    $gx = 12; $gw = 198 - 4 * $sw - 3 * 2 - 3 - $gx;
    $pdf->LinearGradient($gx, $y, $gw, 15, $hex('#047857'), $hex('#0d9488'), [1, 0, 0, 0]);
    $T($gx + 4, $y + 1, $gw - 8, 6, 'جمعِ قابلِ پرداخت به سرویس', 8, '', '#d1fae5');
    $T($gx + 4, $y + 6, $gw - 8, 8, ws_money($tot['amount']) . ' ریال', 14, 'B', '#ffffff');
    $T($gx + 4, $y + 6.5, $gw - 8, 8, 'معادلِ ' . ws_money(intdiv($tot['amount'], 10)) . ' تومان', 7.5, '', '#a7f3d0', 'L');
    $y += 19;

    // ---- سهمِ هر همکار ----
    $pc = [['ردیف', 12], ['همکار', 54], ['روزها', 18], ['رفت', 16], ['برگشت', 16], ['مبلغ (ریال)', 26], ['پرداخت‌شده', 22], ['مانده', 22]];
    $px = function ($idx) use ($pc) { $x = 198; for ($i = 0; $i <= $idx; $i++) $x -= $pc[$i][1]; return $x; };
    $need = 8 + 7 + max(1, count($M['people'])) * 5.2 + 7;
    if ($y + min($need, 40) > 287) { $pdf->AddPage(); $y = $header(true); }
    $T(12, $y, 186, 6, 'سهمِ هر همکار', 10, 'B', '#0f172a');
    $y += 7;
    $drawHead = function ($y) use ($box, $T, $pc, $px) {
        $box(12, $y, 186, 7, '#334155', 2);
        foreach ($pc as $i => $c) $T($px($i), $y, $c[1], 7, $c[0], 7.5, 'B', '#ffffff', 'C');
        return $y + 7.6;
    };
    $y = $drawHead($y);
    if (!$M['people']) { $T(12, $y, 186, 7, 'در این ماه کسی با این سرویس ثبت نکرده است.', 8.5, '', '#94a3b8', 'C'); $y += 8; }
    foreach ($M['people'] as $i => $p) {
        if ($y + 5.2 > 287) { $pdf->AddPage(); $y = $drawHead($header(true)); }
        $pdf->SetFillColor(...$hex($i % 2 ? '#f8fafc' : '#ffffff'));
        $pdf->Rect(12, $y, 186, 5.2, 'F');
        $left = $p['amount'] - $p['paid'];
        $vals = [ws_fa($i + 1), $p['name'], ws_fa($p['days']), ws_fa($p['go']), ws_fa($p['back']), ws_money($p['amount']), $p['paid'] ? ws_money($p['paid']) : '—', $left > 0 ? ws_money($left) : ($left < 0 ? 'بیشتر: ' . ws_money(-$left) : 'تسویه')];
        $clr = ['#64748b', '#0f172a', '#334155', '#0369a1', '#6d28d9', '#047857', '#0f766e', $left > 0 ? '#b45309' : '#047857'];
        foreach ($vals as $j => $v) $T($px($j) + ($j === 1 ? 2 : 0), $y, $pc[$j][1] - ($j === 1 ? 2 : 0), 5.2, $v, $j === 1 ? 8 : 7.8, in_array($j, [1, 5], true) ? 'B' : '', $clr[$j], $j === 1 ? 'R' : 'C');
        $y += 5.2;
    }
    // مانده = جمعِ بدهیِ هر نفر (پرداختِ اضافه‌ی یک نفر بدهیِ دیگری را صاف نمی‌کند)
    $left = array_sum(array_map(fn($p) => max(0, $p['amount'] - $p['paid']), $M['people']));
    $box(12, $y + 0.6, 186, 6.5, '#0f172a', 1.5);
    $T($px(1), $y + 0.6, $pc[0][1] + $pc[1][1], 6.5, 'جمع', 8.5, 'B', '#ffffff', 'C');
    foreach ([[2, ws_fa(array_sum(array_column($M['people'], 'days')))], [3, ws_fa($tot['go'])], [4, ws_fa($tot['back'])], [5, ws_money($tot['amount'])], [6, ws_money($tot['paid'])], [7, $left > 0 ? ws_money($left) : 'تسویه']] as [$j, $v])
        $T($px($j), $y + 0.6, $pc[$j][1], 6.5, $v, 8.2, 'B', $j === 5 ? '#6ee7b7' : '#ffffff', 'C');
    $y += 9.5;

    // ---- امضاها ----
    if ($y + 14 > 289) { $pdf->AddPage(); $y = $header(true); }
    $sh = max(14, min(18, 289 - $y));
    foreach ([['تحویل‌گیرنده (راننده‌ی سرویس)', 105], ['تأییدکننده / پرداخت‌کننده', 12]] as [$lbl, $x]) {
        $pdf->SetDrawColor(...$hex('#cbd5e1')); $pdf->SetLineWidth(0.3);
        $pdf->SetLineStyle(['dash' => '1,1']);
        $pdf->RoundedRect($x, $y, 93, $sh, 3, '1111', 'D');
        $pdf->SetLineStyle(['dash' => 0]);
        $T($x + 4, $y + 2, 85, 6, $lbl . ' — نام و امضا', 8, 'B', '#475569');
    }
    $T(12, 291, 186, 4, 'این گزارش از «گزارش سرویس‌ها» در پنلِ بیمه با ما ساخته شده و جمعِ داده‌هایی است که هر همکار ثبت کرده است.', 6.5, '', '#94a3b8', 'C');
    return $pdf->Output('', 'S');
}
