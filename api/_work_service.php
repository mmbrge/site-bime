<?php
// فایل: api/_work_service.php
// «سرویسِ رفت‌وآمد» در کارکرد پرسنل: تعریفِ سرویس‌ها (نام، راننده، نرخِ هر مسیر)، ثبتِ روزبه‌روزِ رفت/برگشت با سرویس،
// جمعِ ماهانه برای هر سرویس، ثبتِ پرداخت و رسیدِ PDF. جدول‌ها خودکار ساخته می‌شوند.

require_once __DIR__ . '/_work.php';

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
        note VARCHAR(500) NULL,
        created_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // هر روز: سرویسِ رفت و سرویسِ برگشت (۰ یعنی با سرویس نیامدیم/نرفتیم) و مبلغِ همان روز (نرخِ زمانِ ثبت)
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_service_days (
        wdate DATE NOT NULL PRIMARY KEY,
        go_service_id INT NULL,
        back_service_id INT NULL,
        amount_go BIGINT NOT NULL DEFAULT 0,
        amount_back BIGINT NOT NULL DEFAULT 0,
        note VARCHAR(300) NULL,
        updated_at DATETIME NULL,
        updated_by INT NULL,
        KEY idx_go (go_service_id), KEY idx_back (back_service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_service_pays (
        id INT AUTO_INCREMENT PRIMARY KEY,
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
        UNIQUE KEY uq_month (service_id, jy, jm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
    return ['id' => intval($r['id']), 'name' => (string)$r['name'], 'driver' => (string)$r['driver'], 'phone' => (string)$r['phone'], 'car' => (string)$r['car'],
            'price_go' => intval($r['price_go']), 'price_back' => intval($r['price_back']), 'is_default' => (bool)$r['is_default'], 'is_active' => (bool)$r['is_active'],
            'note' => (string)$r['note']];
}

// یک ماه: همه‌ی روزها (حتی خالی) + جمعِ هر سرویس + پرداخت‌ها
function ws_month($pdo, $jy, $jm) {
    ws_ensure($pdo);
    [$from, $to] = wk_month_range($jy, $jm);
    $st = $pdo->prepare("SELECT * FROM work_service_days WHERE wdate BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $saved = [];
    foreach ($st->fetchAll() as $r) $saved[$r['wdate']] = $r;
    $services = [];
    foreach (ws_services($pdo) as $s) $services[$s['id']] = $s;
    $days = [];
    $tot = [];
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
            if (!isset($tot[$sid])) $tot[$sid] = ['service_id' => $sid, 'go' => 0, 'back' => 0, 'days' => 0, 'both' => 0, 'amount' => 0, '_d' => []];
            $tot[$sid][$k]++;
            $tot[$sid]['amount'] += $D['amount_' . $k];
            $tot[$sid]['_d'][$g] = true;
        }
        if ($D['go'] && $D['go'] === $D['back']) $tot[$D['go']]['both']++;
    }
    foreach ($tot as &$t) { $t['days'] = count($t['_d']); unset($t['_d']); $t['name'] = $services[$t['service_id']]['name'] ?? ('سرویس #' . $t['service_id']); }
    unset($t);
    $st = $pdo->prepare("SELECT * FROM work_service_pays WHERE jy = ? AND jm = ?");
    $st->execute([$jy, $jm]);
    $pays = [];
    foreach ($st->fetchAll() as $p) $pays[intval($p['service_id'])] = ws_pay_out($p);
    return ['jy' => $jy, 'jm' => $jm, 'month_name' => jalali_month_name($jm), 'days' => $days, 'totals' => array_values($tot), 'pays' => (object)$pays,
            'services' => array_values($services)];
}
function ws_pay_out($p) {
    return ['id' => intval($p['id']), 'service_id' => intval($p['service_id']), 'amount' => intval($p['amount']), 'paid_date' => $p['paid_date'] ? wk_g2j($p['paid_date']) : '',
            'method' => (string)$p['method'], 'ref' => (string)$p['ref'], 'note' => (string)$p['note']];
}

// ذخیره‌ی چند روز با هم. نرخ: اگر سرویسِ همان مسیر عوض نشده باشد مبلغِ قبلی می‌ماند (مگر reprice)، وگرنه نرخِ فعلیِ سرویس
function ws_days_save($pdo, array $items, $me, $reprice = false) {
    ws_ensure($pdo);
    $services = [];
    foreach (ws_services($pdo) as $s) $services[$s['id']] = $s;
    $get = $pdo->prepare("SELECT * FROM work_service_days WHERE wdate = ?");
    $up = $pdo->prepare("INSERT INTO work_service_days (wdate, go_service_id, back_service_id, amount_go, amount_back, note, updated_at, updated_by)
                         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                         ON DUPLICATE KEY UPDATE go_service_id = VALUES(go_service_id), back_service_id = VALUES(back_service_id), amount_go = VALUES(amount_go),
                                                 amount_back = VALUES(amount_back), note = VALUES(note), updated_at = NOW(), updated_by = VALUES(updated_by)");
    $del = $pdo->prepare("DELETE FROM work_service_days WHERE wdate = ?");
    $n = 0;
    foreach ($items as $it) {
        $g = wk_j2g($it['date'] ?? '') ?: (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($it['date'] ?? '')) ? $it['date'] : null);
        if (!$g) continue;
        $go = intval($it['go'] ?? 0); $back = intval($it['back'] ?? 0);
        if ($go && !isset($services[$go])) $go = 0;
        if ($back && !isset($services[$back])) $back = 0;
        $note = trim(mb_substr((string)($it['note'] ?? ''), 0, 300));
        if (!$go && !$back && $note === '') { $del->execute([$g]); $n++; continue; }
        $get->execute([$g]);
        $old = $get->fetch() ?: null;
        $aGo = $go ? (($old && intval($old['go_service_id']) === $go && !$reprice) ? intval($old['amount_go']) : $services[$go]['price_go']) : 0;
        $aBack = $back ? (($old && intval($old['back_service_id']) === $back && !$reprice) ? intval($old['amount_back']) : $services[$back]['price_back']) : 0;
        $up->execute([$g, $go ?: null, $back ?: null, $aGo, $aBack, $note !== '' ? $note : null, $me]);
        $n++;
        if ($n >= 400) break;
    }
    return $n;
}

// ---------------------------------------------------------------------
//  رسیدِ PDF پرداخت به سرویس (یک سرویس، یک ماه)
// ---------------------------------------------------------------------
function ws_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function ws_money($n) { return ws_fa(number_format(intval($n))); }

function ws_receipt_pdf($pdo, $sid, $jy, $jm) {
    $svc = ws_service($pdo, $sid);
    if (!$svc) throw new RuntimeException('سرویس پیدا نشد.');
    $M = ws_month($pdo, $jy, $jm);
    $pays = (array)$M['pays'];
    $pay = $pays[$sid] ?? null;
    $tot = ['go' => 0, 'back' => 0, 'days' => 0, 'both' => 0, 'amount' => 0];
    foreach ($M['totals'] as $t) if ($t['service_id'] === $sid) $tot = $t;

    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0); $pdf->SetAutoPageBreak(false, 0);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->SetCreator('بیمه با ما'); $pdf->SetAuthor('بیمه با ما');
    $pdf->SetTitle('رسید سرویس ' . $svc['name'] . ' ' . jalali_month_name($jm) . ' ' . $jy);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->AddPage();
    $W = 210;
    $hex = function ($h) { $h = ltrim($h, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; };
    // متنِ راست‌به‌چپ در جعبه‌ای با مختصاتِ معمولی (x از چپ)
    $T = function ($x, $y, $w, $h, $text, $size = 9, $style = '', $color = '#0f172a', $align = 'R') use ($pdf, $W, $hex) {
        $pdf->setRTL(true);
        $pdf->SetFont('vazir', $style, $size);
        $pdf->SetTextColor(...$hex($color));
        // در حالتِ راست‌به‌چپ «R» یعنی ابتدای سطر (راست) و «L» انتهای آن (چپ)
        $pdf->SetXY($W - ($x + $w), $y);
        $pdf->Cell($w, $h, $text, 0, 0, $align === 'C' ? 'C' : ($align === 'L' ? 'R' : 'L'), false, '', 0, false, 'T', 'M');
        $pdf->setRTL(false);
    };
    $box = function ($x, $y, $w, $h, $fill, $r = 3, $border = null) use ($pdf, $hex) {
        $pdf->SetFillColor(...$hex($fill));
        if ($border) { $pdf->SetDrawColor(...$hex($border)); $pdf->SetLineWidth(0.25); }
        $pdf->RoundedRect($x, $y, $w, $h, $r, '1111', $border ? 'DF' : 'F');
    };

    // ---- سربرگ ----
    $pdf->LinearGradient(0, 0, $W, 40, $hex('#312e81'), $hex('#0e7490'), [0, 0, 1, 0]);
    $pdf->SetAlpha(0.12); $pdf->SetFillColor(255, 255, 255);
    $pdf->Circle(18, 6, 22, 0, 360, 'F'); $pdf->Circle(190, 42, 26, 0, 360, 'F'); $pdf->Circle(120, -8, 14, 0, 360, 'F');
    $pdf->SetAlpha(1);
    $T(70, 8, 128, 10, 'رسیدِ پرداختِ سرویسِ رفت‌وآمد', 19, 'B', '#ffffff');
    $T(70, 19, 128, 7, 'کارکردِ ' . jalali_month_name($jm) . ' ' . ws_fa($jy) . ' · سرویسِ «' . $svc['name'] . '»', 11, '', '#e0e7ff');
    $T(70, 27, 128, 6, 'بیمه با ما', 9, 'B', '#a5f3fc');
    // برچسبِ شماره و تاریخ
    $box(12, 9, 52, 22, '#ffffff', 4);
    $T(14, 11, 48, 6, 'شماره‌ی رسید', 7.5, '', '#64748b', 'C');
    $T(14, 16.5, 48, 6, ws_fa(sprintf('SV-%d-%04d%02d', $sid, $jy, $jm)), 10, 'B', '#312e81', 'C');
    $T(14, 23, 48, 6, 'صدور: ' . ws_fa(wk_g2j(date('Y-m-d'))), 8, '', '#475569', 'C');

    // ---- کارت‌های اطلاعات ----
    $y = 46;
    $cards = [
        ['سرویس', $svc['name'], '#eef2ff', '#4338ca'],
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
    $T(12, 291, 186, 4, 'این رسید از «کارکرد پرسنل / سرویسِ رفت‌وآمد» در پنلِ بیمه با ما ساخته شده است.', 6.5, '', '#94a3b8', 'C');
    return $pdf->Output('', 'S');
}
