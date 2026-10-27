<?php
// فایل: api/_dup.php
// تشخیصِ تکراری: آیا برای همین خودرو (شاسی/VIN، موتور یا پلاک) و همین نوعِ بیمه (ثالث/بدنه جدا) قبلاً بیمه‌نامه صادر شده
// یا درخواستِ بازِ دیگری هست؟ هم ردیف‌های شرکتی و هم درخواست‌های کارکنان گشته می‌شوند.
//  - «فعال»: پایانِ بیمه‌نامه بیش از ۳۰ روز بعد است (یا پایان ثبت نشده و کمتر از ۳۳۵ روز از صدور گذشته) => تکراری
//  - کمتر از ۳۰ روز تا پایان یا منقضی => «سابقه» (تمدید مجاز است)
// ستون‌های dup_* روی ردیف‌های شرکتی خودکار ساخته می‌شوند؛ داده‌های قبلی دست نمی‌خورد.

function dup_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM company_request_plates")->fetchAll(), 'Field');
        if (!in_array('dup_of_source', $cols, true))
            $pdo->exec("ALTER TABLE company_request_plates ADD COLUMN dup_of_source CHAR(1) NULL, ADD COLUMN dup_of_id BIGINT NULL, ADD COLUMN dup_at DATETIME NULL, ADD COLUMN dup_by INT NULL");
    } catch (Throwable $e) { error_log('[dup_ensure] ' . $e->getMessage()); }
}

function dup_clean($s) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$s))); }
function dup_sql_clean($col) {
    return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col, ''), '-', ''), '.', ''), '*', ''), ' ', ''), '/', ''))";
}
// کلیدِ پلاک: گروه‌های رقمی (مرتب) + حرف — مستقل از ترتیبِ نوشتن («۶۷ایران ۳۴۷ ب ۱۲» یا «۱۲ ب ۳۴۷ - ۶۷»)
function dup_plate_key($s) {
    $s = str_replace(['ي', 'ك', 'ایران', 'ايران'], ['ی', 'ک', ' ', ' '], p2e_digits((string)$s));
    preg_match_all('/\d+/', $s, $m);
    $d = $m[0];
    // موتورسیکلت: کد شهر ۳ رقم + شماره‌ی ۵ رقم (حرف ندارد)
    if (count($d) === 2 && ((strlen($d[0]) === 3 && strlen($d[1]) === 5) || (strlen($d[0]) === 5 && strlen($d[1]) === 3)))
        return 'M|' . (strlen($d[0]) === 3 ? $d[0] . '-' . $d[1] : $d[1] . '-' . $d[0]);
    if (count($d) < 3) return '';
    sort($d);
    preg_match('/[آ-ی]/u', $s, $l);
    return implode('-', $d) . '|' . ($l[0] ?? '');
}
function dup_plate_key_parts($p1, $p2, $letter, $p4) {
    if (!$p1 && !$p2 && !$p4) return '';
    if (trim((string)$letter) === 'موتور') return dup_plate_key("$p1 $p2");
    return dup_plate_key("$p1 $p2 $letter $p4");
}

// پایانِ بیمه‌نامه (شمسی) و وضعیت
function dup_state($endJ, $issuedAt, $margin = 30) {
    $endJ = trim(str_replace(['-', '.'], '/', p2e_digits((string)$endJ)));
    if (preg_match('#^(1[34]\d\d)/(\d{1,2})/(\d{1,2})#', $endJ, $m)) {
        $end = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
        [$y, $mo, $d] = jalali_from_gregorian_ts(time() + $margin * 86400);
        return [$end, strcmp($end, sprintf('%04d/%02d/%02d', $y, $mo, $d)) > 0 ? 'active' : 'history'];
    }
    $ts = $issuedAt ? strtotime((string)$issuedAt) : 0;
    return [null, $ts && $ts > time() - (365 - $margin) * 86400 ? 'active' : 'history'];
}

// $v: ['vin'|'chassis', 'engine', 'plate' (متن) یا p1,p2,letter,p4, 'type' => THIRDPARTY|BODY, 'exclude_plate' , 'exclude_case', 'company_id']
// خروجی: [{source C|P, id, state active|history|open, policy_number, type, issued_j, end_j, owner, company_id, file, request_id, match}]
function dup_find($pdo, array $v) {
    dup_ensure($pdo);
    $vin = dup_clean($v['vin'] ?? ($v['chassis'] ?? ''));
    $eng = dup_clean($v['engine'] ?? '');
    $pk = !empty($v['plate']) ? dup_plate_key($v['plate']) : dup_plate_key_parts($v['p1'] ?? '', $v['p2'] ?? '', $v['letter'] ?? '', $v['p4'] ?? '');
    $type = in_array($v['type'] ?? '', ['THIRDPARTY', 'BODY'], true) ? $v['type'] : null;
    $margin = isset($v['margin_days']) ? max(0, intval($v['margin_days'])) : 30;
    if (strlen($vin) < 6 && strlen($eng) < 6 && $pk === '') return [];
    $out = [];
    $jd = function ($ts) { if (!$ts) return null; [$y, $m, $d] = jalali_from_gregorian_ts(strtotime($ts)); return sprintf('%04d/%02d/%02d', $y, $m, $d); };

    // ---- ردیف‌های شرکتی ----
    $w = []; $a = [];
    if (strlen($vin) >= 6) { $w[] = dup_sql_clean('crp.chassis_no') . " = ?"; $a[] = $vin; $w[] = dup_sql_clean('crp.vin') . " = ?"; $a[] = $vin; }
    if (strlen($eng) >= 6) { $w[] = dup_sql_clean('crp.engine_no') . " = ?"; $a[] = $eng; }
    if ($pk !== '') {
        $pp = [];
        preg_match_all('/\d+/', explode('|', $pk)[0], $mm);
        foreach ($mm[0] as $x) if (strlen($x) === 3) $pp[] = $x;
        if ($pp) { $w[] = "crp.plate_p2 = ?"; $a[] = $pp[0]; }
    }
    if ($w) {
        $sql = "SELECT crp.*, cr.company_id, cr.status AS req_status, c.name AS company_name FROM company_request_plates crp
                  JOIN company_requests cr ON cr.id = crp.request_id LEFT JOIN companies c ON c.id = cr.company_id
                 WHERE (" . implode(' OR ', $w) . ")" . ($type ? " AND crp.insurance_type = ?" : '') . (!empty($v['exclude_plate']) ? " AND crp.id <> ?" : '') . " ORDER BY crp.id DESC LIMIT 30";
        if ($type) $a[] = $type;
        if (!empty($v['exclude_plate'])) $a[] = intval($v['exclude_plate']);
        try { $st = $pdo->prepare($sql); $st->execute($a); $rows = $st->fetchAll(); } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $r) {
            $match = '';
            if (strlen($vin) >= 6 && (dup_clean($r['chassis_no']) === $vin || dup_clean($r['vin']) === $vin)) $match = 'شاسی';
            elseif (strlen($eng) >= 6 && dup_clean($r['engine_no']) === $eng) $match = 'موتور';
            elseif ($pk !== '' && dup_plate_key_parts($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) === $pk) $match = 'پلاک';
            if (!$match || !empty($r['dup_of_id'])) continue;   // ردیفی که خودش «تکراری» زده شده حساب نمی‌شود
            if ($r['status'] === 'ISSUED') {
                $ii = json_decode((string)($r['issue_info'] ?? ''), true) ?: [];
                [$end, $state] = dup_state($r['end_date_j'] ?: ($ii['end_date'] ?? ''), $r['issued_at'], $margin);
            } elseif (!in_array($r['status'], ['CANCELLED'], true) && $r['req_status'] !== 'CANCELLED') {
                $end = null; $state = 'open';
            } else continue;
            $out[] = ['source' => 'C', 'id' => intval($r['id']), 'state' => $state, 'match' => $match, 'policy_number' => $r['policy_number'], 'type' => $r['insurance_type'],
                      'issued_j' => $jd($r['issued_at']), 'end_j' => $end, 'owner' => $r['company_name'], 'company_id' => intval($r['company_id']),
                      'file' => $r['status'] === 'ISSUED' ? $r['issued_file_path'] : null, 'request_id' => intval($r['request_id'])];
        }
    }
    // ---- درخواست‌های کارکنان ----
    $w = []; $a = [];
    if (strlen($vin) >= 6) { $w[] = dup_sql_clean('pc.vin') . " = ?"; $a[] = $vin; $w[] = dup_sql_clean('pc.chassis_num') . " = ?"; $a[] = $vin; }
    if (strlen($eng) >= 6) { $w[] = dup_sql_clean('pc.engine_num') . " = ?"; $a[] = $eng; }
    if ($pk !== '') {
        preg_match_all('/\d+/', explode('|', $pk)[0], $mm);
        foreach ($mm[0] as $x) if (strlen($x) === 3) { $w[] = "pc.plate LIKE ?"; $a[] = '%' . $x . '%'; break; }
    }
    if ($w) {
        $sql = "SELECT pc.*, p.full_name AS holder_name FROM policy_cases pc LEFT JOIN persons p ON p.id = pc.person_id
                 WHERE (" . implode(' OR ', $w) . ")" . ($type ? " AND pc.insurance_type = ?" : '') . (!empty($v['exclude_case']) ? " AND pc.id <> ?" : '') . " ORDER BY pc.id DESC LIMIT 30";
        if ($type) $a[] = $type;
        if (!empty($v['exclude_case'])) $a[] = intval($v['exclude_case']);
        try { $st = $pdo->prepare($sql); $st->execute($a); $rows = $st->fetchAll(); } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $r) {
            $match = '';
            if (strlen($vin) >= 6 && (dup_clean($r['vin']) === $vin || dup_clean($r['chassis_num']) === $vin)) $match = 'شاسی';
            elseif (strlen($eng) >= 6 && dup_clean($r['engine_num']) === $eng) $match = 'موتور';
            elseif ($pk !== '' && dup_plate_key($r['plate']) === $pk) $match = 'پلاک';
            if (!$match) continue;
            if ($r['status'] === 'ISSUED') {
                $ii = json_decode((string)($r['issue_info'] ?? ''), true) ?: [];
                [$end, $state] = dup_state($ii['end_date'] ?? '', $r['issued_at'] ?: $r['updated_at'] ?? null, $margin);
            } elseif (!in_array($r['status'], ['WITHDRAWN', 'CANCELLED', 'REJECTED'], true)) {
                $end = null; $state = 'open';
            } else continue;
            $out[] = ['source' => 'P', 'id' => intval($r['id']), 'state' => $state, 'match' => $match, 'policy_number' => $r['policy_number'], 'type' => $r['insurance_type'],
                      'issued_j' => $jd($r['issued_at'] ?? null), 'end_j' => $end, 'owner' => $r['insured_name'] ?: $r['holder_name'], 'company_id' => null,
                      'file' => $r['status'] === 'ISSUED' ? $r['issued_file_path'] : null, 'code' => $r['unique_code'] ?? null];
        }
    }
    // فعال‌ها اول، بعد درخواست‌های باز، بعد سابقه
    $rank = ['active' => 0, 'open' => 1, 'history' => 2];
    usort($out, function ($x, $y) use ($rank) { return ($rank[$x['state']] <=> $rank[$y['state']]) ?: strcmp((string)$y['issued_j'], (string)$x['issued_j']); });
    return $out;
}

// متنِ کوتاهِ فارسی برای پیام‌ها
function dup_text(array $m) {
    $t = $m['type'] === 'BODY' ? 'بدنه' : 'ثالث';
    if ($m['state'] === 'open') return "برای همین خودرو ({$m['match']}) درخواستِ {$t}ِ دیگری در جریان است" . (!empty($m['request_id']) ? ' (درخواست #' . $m['request_id'] . ')' : (!empty($m['code']) ? ' (' . $m['code'] . ')' : ''));
    return "برای همین خودرو ({$m['match']}) قبلاً بیمه‌نامه‌ی {$t}" . ($m['policy_number'] ? ' شماره ' . $m['policy_number'] : '') . ($m['issued_j'] ? ' در ' . $m['issued_j'] : '') . ' صادر شده'
         . ($m['end_j'] ? ' (اعتبار تا ' . $m['end_j'] . ')' : '') . ($m['state'] === 'history' ? ' — منقضی یا نزدیکِ پایان؛ تمدید مجاز است' : '');
}

// نسخه‌ی شرکت: جزئیاتِ بیمه‌نامه‌ی شرکتِ دیگر یا کارکنان نشان داده نمی‌شود
function dup_for_company(array $list, array $companyIds) {
    $out = [];
    foreach ($list as $m) {
        $own = $m['source'] === 'C' && in_array($m['company_id'], $companyIds, true);
        $x = ['state' => $m['state'], 'type' => $m['type'], 'match' => $m['match'], 'own' => $own];
        if ($own) $x += ['plate_id' => $m['id'], 'policy_number' => $m['policy_number'], 'issued_j' => $m['issued_j'], 'end_j' => $m['end_j'], 'request_id' => $m['request_id'], 'has_file' => !empty($m['file'])];
        else $x += ['end_j' => $m['end_j']];
        $out[] = $x;
    }
    return $out;
}

// ======================================================================================
// هنگامِ ساختِ ردیف: بیمه‌نامه‌ی «فعال» = صادرشده و هنوز منقضی نشده (پایانش امروز یا بعد)، همان نوعِ بیمه
// (ثالث با ثالث، بدنه با بدنه). فقط مدیر کل می‌تواند ردیفِ تکراری را آگاهانه بسازد (با برچسبِ زردِ «تکراری»)؛
// بقیه فقط می‌بینند/دانلود می‌کنند و آن ردیف ساخته نمی‌شود.
//  - تمدیدِ همان بیمه‌نامه تکراری نیست: شماره‌ی «بیمه‌نامه‌ی قبلی»ِ ردیف همان بیمه‌نامه است، یا تاریخِ انقضای ردیف همان پایانِ بیمه‌نامه
//  - ردیفِ الحاقیه/فسخ (به بیمه‌نامه‌ی موجود اشاره دارد) بررسی نمی‌شود
// ======================================================================================
function dup_ack_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM company_request_plates LIKE 'dup_ack_json'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE company_request_plates ADD COLUMN dup_ack_json TEXT NULL, ADD COLUMN dup_ack_by INT NULL, ADD COLUMN dup_ack_at DATETIME NULL");
    } catch (Throwable $e) { error_log('[dup_ack_ensure] ' . $e->getMessage()); }
}

// ورودی با نام‌های ستونِ ردیف (plate_p1…/chassis_no/vin/engine_no) یا نام‌های کوتاهِ پورتال (p1…)
function dup_row_norm(array $r) {
    $g = function ($a, $b = null) use ($r) { $x = $r[$a] ?? ($b !== null ? ($r[$b] ?? '') : ''); return trim(p2e_digits((string)$x)); };
    return ['p1' => $g('plate_p1', 'p1'), 'p2' => $g('plate_p2', 'p2'), 'letter' => trim((string)($r['plate_letter'] ?? ($r['letter'] ?? ''))), 'p4' => $g('plate_p4', 'p4'),
            'chassis' => $g('chassis_no'), 'vin' => $g('vin'), 'engine' => $g('engine_no'), 'type' => (string)($r['insurance_type'] ?? ($r['type'] ?? '')),
            'ref' => $g('ref_policy_number'), 'expiry' => $g('expiry_date'), 'expiry_j' => $g('expiry_date_jalali', 'expiry_j'),
            'end_req' => trim((string)($r['endorsement_request'] ?? '')) !== '' || trim((string)($r['cancellation_reason'] ?? '')) !== ''];
}
// کلیدِ ثابتِ هر ردیف (بین پیش‌نمایش و ساخت، برای «با وجودِ تکراری بساز»ِ مدیر)
function dup_row_key(array $r) {
    $n = dup_row_norm($r);
    return $n['type'] . '|' . dup_plate_key_parts($n['p1'], $n['p2'], $n['letter'], $n['p4']) . '|' . dup_clean($n['vin'] ?: $n['chassis']) . '|' . dup_clean($n['engine']);
}
function dup_row_label(array $r) {
    $n = dup_row_norm($r);
    $pl = $n['letter'] === 'موتور' ? trim($n['p1'] . '-' . $n['p2']) : trim(implode(' ', array_filter([$n['p2'], $n['letter'], $n['p4'], $n['p1'] !== '' ? 'ایران ' . $n['p1'] : ''], 'strlen')));
    return $pl !== '' ? $pl : ($n['vin'] ?: $n['chassis'] ?: $n['engine'] ?: '—');
}

// همه‌ی سوابقِ همین خودرو و نوع با معیارِ «منقضی‌نشده»؛ بیمه‌نامه‌ای که این ردیف تمدیدش است state=history و renewal=true می‌گیرد
function dup_find_for_row($pdo, array $r) {
    $x = dup_active_for_row($pdo, $r, true);
    return $x['all'];
}
// ['hits' => [بیمه‌نامه‌های فعالِ صادرشده], 'renewal' => [همان‌هایی که این ردیف تمدیدشان است]]
function dup_active_for_row($pdo, array $r, $withAll = false) {
    $n = dup_row_norm($r);
    if ($n['end_req'] || !in_array($n['type'], ['THIRDPARTY', 'BODY'], true)) return ['hits' => [], 'renewal' => [], 'all' => []];
    $list = dup_find($pdo, ['vin' => $n['vin'] ?: $n['chassis'], 'engine' => $n['engine'], 'p1' => $n['p1'], 'p2' => $n['p2'], 'letter' => $n['letter'], 'p4' => $n['p4'],
                            'type' => $n['type'], 'margin_days' => 0, 'exclude_plate' => intval($r['_exclude_plate'] ?? 0)]);
    // پایانِ ردیف (شمسی) برای تشخیصِ تمدید
    $rowEnd = '';
    if ($n['expiry_j'] !== '' && preg_match('#(1[34]\d\d)\D(\d{1,2})\D(\d{1,2})#', $n['expiry_j'], $m)) $rowEnd = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $n['expiry'], $m) && intval($m[1]) > 1900) { [$y, $mo, $d] = jalali_from_gregorian_ts(strtotime($n['expiry'])); $rowEnd = sprintf('%04d/%02d/%02d', $y, $mo, $d); }
    elseif ($n['expiry'] !== '' && preg_match('#(1[34]\d\d)\D(\d{1,2})\D(\d{1,2})#', $n['expiry'], $m)) $rowEnd = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    $ref = dup_clean($n['ref']);
    $hits = []; $ren = [];
    foreach ($list as &$m) {
        if ($m['state'] !== 'active') continue;
        $isRen = ($ref !== '' && $m['policy_number'] && dup_clean($m['policy_number']) === $ref) || ($rowEnd !== '' && $m['end_j'] === $rowEnd);
        if ($isRen) { $ren[] = $m; $m['state'] = 'history'; $m['renewal'] = true; } else $hits[] = $m;
    }
    unset($m);
    // نزدیک‌ترین پایان اول
    usort($hits, function ($a, $b) { return strcmp((string)$a['end_j'], (string)$b['end_j']); });
    return ['hits' => $hits, 'renewal' => $ren, 'all' => $withAll ? $list : []];
}
// خلاصه‌ی امن برای مرورگر (کارکنان)
function dup_hits_public(array $hits) {
    return array_values(array_map(function ($m) {
        return ['source' => $m['source'], 'id' => $m['id'], 'policy_number' => $m['policy_number'], 'type' => $m['type'], 'match' => $m['match'],
                'issued_j' => $m['issued_j'], 'end_j' => $m['end_j'], 'owner' => $m['owner'], 'file' => $m['file'] ?: null, 'request_id' => $m['request_id'] ?? null];
    }, array_slice($hits, 0, 5)));
}
// متنِ کوتاه برای پیامِ خطا
function dup_hits_text(array $hits) {
    $m = $hits[0] ?? null;
    if (!$m) return '';
    return 'بیمه‌نامه‌ی فعالِ ' . ($m['type'] === 'BODY' ? 'بدنه' : 'ثالث') . ($m['policy_number'] ? ' ' . $m['policy_number'] : '') . ($m['end_j'] ? ' (تا ' . $m['end_j'] . ')' : '') . ' — یکسان در ' . $m['match'];
}
// ردیفی که مدیر کل آگاهانه با وجودِ بیمه‌نامه‌ی فعال ساخته
function dup_ack_mark($pdo, $plateId, array $hits, $userId) {
    dup_ack_ensure($pdo);
    $pdo->prepare("UPDATE company_request_plates SET dup_ack_json = ?, dup_ack_by = ?, dup_ack_at = NOW() WHERE id = ?")
        ->execute([json_encode(dup_hits_public($hits), JSON_UNESCAPED_UNICODE), $userId ?: null, $plateId]);
}
