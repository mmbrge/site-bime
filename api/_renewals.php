<?php
// فایل: api/_renewals.php
// فهرستِ «اعلام تمدید» (صادره‌های شرکتی، وارداتی، کارکنان و بردرو) با تاریخ‌ها، وضعیتِ تمدید، پیگیری‌ها و درخواستِ تمدید؛
// مشترکِ پنلِ ما (api/renewal_actions.php) و پنلِ شرکت‌ها («تمدیدی‌ها»، فقط ردیف‌های شرکت‌های خودِ کاربر و اطلاعاتِ کلی).
// نیاز: _case_helpers، _company_helpers، finance_core

function rn_ensure($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_notices (
        id INT AUTO_INCREMENT PRIMARY KEY, src CHAR(1) NOT NULL, row_id INT NOT NULL, channel VARCHAR(20) NOT NULL, ok TINYINT(1) NOT NULL DEFAULT 1,
        recipient VARCHAR(200) NULL, note VARCHAR(255) NULL, message TEXT NULL, created_by INT NULL, created_at DATETIME NULL, KEY idx_row (src, row_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
const RN_CHANNELS = ['request' => 'درخواستِ تمدید', 'bot' => 'ربات بله', 'phone' => 'تماس تلفنی', 'sms' => 'پیامک', 'letter' => 'نامه', 'visit' => 'حضوری', 'other' => 'سایر', 'declined' => 'تمدید نمی‌کند', 'renewed_out' => 'جای دیگر تمدید کرد'];

// تاریخِ شمسیِ «۱۴۰۵/۰۷/۱۳» (هر شکلی) => [jalali 'Y/m/d', ts] یا null
function rn_j($v) {
    $v = p2e_digits(trim((string)$v));
    if (!preg_match('/(1[34]\d{2})\D+(\d{1,2})\D+(\d{1,2})/', $v, $m) || $m[2] < 1 || $m[2] > 12 || $m[3] < 1 || $m[3] > 31) return null;
    return [sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]), jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]))];
}
function rn_plus_year($j) { [$y, $m, $d] = array_map('intval', explode('/', $j)); $d = min($d, company_jalali_month_len($y + 1, $m)); return sprintf('%04d/%02d/%02d', $y + 1, $m, $d); }
function rn_pick(array $srcs, $keys) { foreach ($srcs as $s) foreach ((array)$keys as $k) if (isset($s[$k]) && is_scalar($s[$k]) && trim((string)$s[$k]) !== '') return trim((string)$s[$k]); return ''; }
function rn_key_vin($v) { $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); return strlen($v) >= 6 ? $v : ''; }

// بردرو و درخواستِ تمدید (همان فایل‌هایی که create_requests لازم دارد)
function rn_require_brd() {
    require_once __DIR__ . '/_import_archive.php'; require_once __DIR__ . '/_bordereau.php'; require_once __DIR__ . '/_vehicles.php';
    require_once __DIR__ . '/_registry_link.php'; require_once __DIR__ . '/_renew_request.php';
}
const RN_ORIGINS = ['panel' => 'پنل', 'brd' => 'بردرو', 'both' => 'پنل + بردرو'];

// همه‌ی صادره‌ها با اطلاعاتِ کامل
function rn_rows($pdo) {
    $today = strtotime(date('Y-m-d'));
    $contracts = [];
    try { foreach ($pdo->query("SELECT id, name FROM fin_contracts") as $c) $contracts[(int)$c['id']] = $c['name']; } catch (Throwable $e) {}
    $rows = [];
    $st = $pdo->query("SELECT crp.*, cr.request_kind, cr.insurer, cr.is_import, cr.company_id, cr.created_at AS request_created_at, c.name AS company_name,
                              c.economic_code, c.phone AS company_phone, c.contract_id
                         FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                        WHERE crp.status = 'ISSUED' AND cr.request_kind = 'NEW_POLICY'");
    foreach ($st->fetchAll() as $r) {
        $ii = issue_info_decode($r['issue_info']); $oc = json_decode((string)$r['ocr_extracted_data'], true) ?: []; $im = json_decode((string)($r['import_json'] ?? ''), true) ?: [];
        $S = [$ii, $oc, $im];
        $imp = !empty($r['is_import']);
        $rows[] = [
            'src' => $imp ? 'I' : 'C', 'id' => (int)$r['id'], 'source_fa' => $imp ? 'وارداتی' : 'شرکتی', 'owner_id' => (int)$r['company_id'],
            'insured' => rn_pick($S, 'insured_name') ?: $r['company_name'], 'company' => $r['company_name'],
            'contract' => $imp ? ($im['contract_party'] ?? '') : ($contracts[(int)$r['contract_id']] ?? rn_pick([$oc], 'contract_no')),
            'national_id' => rn_pick($S, ['insured_national_id', 'national_id']) ?: $r['economic_code'], 'phone' => rn_pick($S, ['insured_phone', 'phone']) ?: $r['company_phone'],
            'address' => rn_pick($S, ['insured_address', 'address']), 'owner_name' => rn_pick($S, 'owner_name'),
            'plate' => company_plate_display($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) ?: '', 'chassis' => $r['chassis_no'] ?: ($r['vin'] ?: rn_pick($S, 'vin')),
            'engine' => $r['engine_no'] ?: rn_pick($S, ['engine_no']), 'type' => $r['insurance_type'], 'car' => $r['car_name'] ?: car_join([rn_pick($S, 'car_system'), rn_pick($S, ['car_type', 'car_tip'])]),
            'model_year' => rn_pick($S, ['car_model_year', 'model_year']), 'color' => rn_pick($S, ['car_color', 'color']), 'usage' => rn_pick($S, ['car_usage', 'usage']),
            'policy' => $r['policy_number'] ?: ($r['import_policy_number'] ?? ''), 'central_no' => rn_pick($S, ['central_unique_code', 'central_no']),
            'premium' => (int)$r['total_premium'], 'car_value' => (int)$r['car_value'], 'liability' => (int)$r['liability_limit'], 'insurer' => $r['insurer'] === 'IRAN' ? 'ایران' : 'پاسارگاد',
            'prev_insurer' => rn_pick($S, 'prev_insurer'), 'prev_policy' => rn_pick($S, ['prev_policy_number', 'prev_policy']),
            '_issue' => $r['policy_issue_date'] ?: (($r['import_issue_date'] ?? '') ?: rn_pick($S, 'issue_date')), '_start' => rn_pick($S, 'start_date'), '_end' => rn_pick($S, 'end_date'),
            '_issued_at' => $r['issued_at'], 'file' => $r['issued_file_path'], 'request_id' => (int)$r['request_id'],
        ];
    }
    try {
        $st = $pdo->query("SELECT pc.*, per.full_name, per.national_code, per.mobile_number, per.personnel_code, per.bale_chat_id, per.id AS pid, co.name AS employer, co.contract_id
                             FROM policy_cases pc LEFT JOIN persons per ON per.id = pc.person_id LEFT JOIN companies co ON co.id = per.company_id
                            WHERE pc.status = 'ISSUED'");
        foreach ($st->fetchAll() as $r) {
            $ii = issue_info_decode($r['issue_info']); $oc = json_decode((string)$r['ocr_extracted_data'], true) ?: [];
            $S = [$ii, $oc];
            $rows[] = [
                'src' => 'P', 'id' => (int)$r['id'], 'source_fa' => 'کارکنان', 'owner_id' => (int)$r['pid'],
                'insured' => $r['insured_name'] ?: $r['full_name'], 'company' => $r['employer'] ?: '', 'holder' => $r['full_name'], 'personnel_code' => $r['personnel_code'],
                'holder_nid' => $r['national_code'], 'relationship' => $r['insured_relationship'] ?? '',
                'contract' => $contracts[(int)$r['contract_id']] ?? rn_pick([$oc], 'contract_no'),
                'national_id' => $r['insured_national_id'] ?: $r['national_code'], 'phone' => $r['insured_phone'] ?: ($r['ocr_phone'] ?: $r['mobile_number']),
                'address' => $r['insured_address'] ?: rn_pick($S, ['insured_address', 'address']), 'owner_name' => rn_pick($S, 'owner_name'),
                'plate' => $r['plate'] ?: '', 'chassis' => $r['chassis_num'] ?: $r['vin'], 'engine' => $r['engine_num'], 'type' => $r['insurance_type'],
                'car' => car_join([($r['car_system'] ?? ''), ($r['car_type'] ?? '')]), 'model_year' => $r['car_model_year'], 'color' => $r['car_color'], 'usage' => $r['car_usage'],
                'policy' => $r['policy_number'], 'central_no' => $r['central_unique_code'], 'premium' => (int)$r['total_premium'], 'car_value' => (int)$r['car_value'],
                'liability' => (int)preg_replace('/\D/', '', (string)$r['liability_limit']), 'insurer' => 'پاسارگاد',
                'prev_insurer' => rn_pick($S, 'prev_insurer'), 'prev_policy' => rn_pick($S, ['prev_policy_number', 'prev_policy']),
                '_issue' => $r['policy_issue_date'] ?: rn_pick($S, 'issue_date'), '_start' => rn_pick($S, 'start_date'), '_end' => rn_pick($S, 'end_date'),
                '_issued_at' => $r['issued_at'], 'file' => $r['issued_file_path'], 'has_bot' => !empty($r['bale_chat_id']),
            ];
        }
    } catch (Throwable $e) { error_log('[renewal personnel] ' . $e->getMessage()); }
    foreach ($rows as &$r) $r['origin'] = 'panel';
    unset($r);

    // «بردروی پاسارگاد» هم در همین فهرست (منبع B، برچسبِ «بردرو»)؛ بیمه‌نامه‌ای که در پنل هم صادر شده یک ردیف می‌ماند با برچسبِ «پنل + بردرو»
    rn_require_brd();
    $polKey = function ($p) { $p = preg_replace('/\D+/', '', p2e_digits((string)$p)); return strlen($p) >= 6 ? $p : ''; };
    $byPol = [];
    foreach ($rows as $i => $r) if (($k = $polKey($r['policy'])) !== '') $byPol[$k] = $i;
    try {
        foreach (brd_rows($pdo) as $b) {
            if ($b['state'] === 'cancelled') continue;
            if (($k = $polKey($b['policy'])) !== '' && isset($byPol[$k])) {
                $i = $byPol[$k];
                $rows[$i]['origin'] = 'both'; $rows[$i]['brd_id'] = $b['id'];
                continue;
            }
            $rows[] = [
                'src' => 'B', 'id' => (int)$b['id'], 'source_fa' => 'بردرو', 'origin' => 'brd', 'owner_id' => (int)($b['company_id'] ?? 0),
                'insured' => $b['insured'] ?: $b['group'], 'company' => $b['company_name'] ?: $b['group'], 'contract' => $b['contract_no'] ?: $b['contract_party'],
                'national_id' => $b['insured_id'], 'phone' => $b['phone'], 'address' => $b['address'], 'owner_name' => $b['owner_name'],
                'plate' => $b['plate'], 'chassis' => $b['vin'], 'engine' => $b['engine'], 'type' => $b['type'], 'car' => car_join([$b['car'], $b['car_system'], $b['car_tip']]),
                'model_year' => $b['model_year'], 'color' => $b['color'], 'usage' => $b['usage'], 'policy' => $b['policy'], 'central_no' => $b['central_no'],
                'premium' => (int)$b['premium'], 'car_value' => (int)$b['car_value'], 'liability' => (int)$b['liability'], 'insurer' => 'پاسارگاد',
                'prev_insurer' => $b['prev_insurer'], 'prev_policy' => $b['prev_policy'], 'endorsed' => !empty($b['endorsed']), 'insured_kind_fa' => $b['insured_kind_fa'],
                '_issue' => $b['issue_date'], '_start' => $b['start_date'], '_end' => $b['end_date'], '_issued_at' => null, 'file' => null,
                '_brd_renewed' => $b['renewed_by'], '_brd_note' => $b['notes'] ? end($b['notes']) : null,
            ];
        }
    } catch (Throwable $e) { error_log('[renewal bordereau] ' . $e->getMessage()); }

    // تاریخ‌ها: صدور، شروع، انقضا (اگر انقضا خوانده نشده: یک سال بعد از شروع/صدور = «تخمینی»)
    foreach ($rows as &$r) {
        $iss = rn_j($r['_issue']) ?: ($r['_issued_at'] ? rn_j(jalali_from_gregorian_ts_dotted(strtotime($r['_issued_at']))) : null);
        $sta = rn_j($r['_start']);
        $end = rn_j($r['_end']);
        $est = false;
        if (!$end && ($sta || $iss)) { $end = rn_j(rn_plus_year(($sta ?: $iss)[0])); $est = true; }
        $r['issue_date'] = $iss[0] ?? ''; $r['issue_ts'] = $iss[1] ?? 0; $r['start_date'] = $sta[0] ?? '';
        $r['end_date'] = $end[0] ?? ''; $r['end_ts'] = $end[1] ?? 0; $r['end_est'] = $est;
        $r['days_left'] = $end ? (int)floor(($end[1] - $today) / 86400) : null;
        $r['type_fa'] = insurance_type_fa($r['type']);
        $r['key'] = $r['src'] . ':' . $r['id'];
        $r['origin_fa'] = RN_ORIGINS[$r['origin']] ?? '';
        unset($r['_issue'], $r['_start'], $r['_end'], $r['_issued_at']);
    }
    unset($r);

    // «تمدیدشده»: بیمه‌نامه‌ی تازه‌ترِ همان نوع برای همان شاسی یا پلاک
    $by = [];
    foreach ($rows as $i => $r) {
        $ks = array_filter([rn_key_vin($r['chassis']) ? 'v' . rn_key_vin($r['chassis']) : '', $r['plate'] !== '' ? 'p' . plate_core($r['plate']) : '']);
        foreach ($ks as $k) $by[$r['type'] . '|' . $k][] = $i;
    }
    foreach ($rows as $i => &$r) {
        $r['renewed_by'] = !empty($r['_brd_renewed']['policy']) || !empty($r['_brd_renewed']['issue']) ? $r['_brd_renewed'] : null;
        $ks = array_filter([rn_key_vin($r['chassis']) ? 'v' . rn_key_vin($r['chassis']) : '', $r['plate'] !== '' ? 'p' . plate_core($r['plate']) : '']);
        foreach ($ks as $k) foreach ($by[$r['type'] . '|' . $k] ?? [] as $j) {
            if ($j === $i) continue;
            $o = $rows[$j];
            // بیمه‌نامه‌ی بعدی: از ۴ ماه پیش از انقضا به بعد صادر شده و همان بیمه‌نامه نیست
            if ($o['issue_ts'] > $r['issue_ts'] && $r['end_ts'] && $o['issue_ts'] >= $r['end_ts'] - 120 * 86400 && ((string)$o['policy'] === '' || (string)$o['policy'] !== (string)$r['policy'])) { $r['renewed_by'] = ['policy' => $o['policy'], 'issue_date' => $o['issue_date'], 'key' => $o['key']]; break 2; }
        }
    }
    unset($r);

    // پیگیری‌ها
    $notes = [];
    foreach ($pdo->query("SELECT src, row_id, channel, ok, note, created_at FROM renewal_notices ORDER BY id") as $n) {
        $k = $n['src'] . ':' . $n['row_id'];
        $notes[$k]['count'] = ($notes[$k]['count'] ?? 0) + ($n['ok'] ? 1 : 0);
        if ($n['ok']) $notes[$k]['last'] = ['channel' => $n['channel'], 'channel_fa' => RN_CHANNELS[$n['channel']] ?? $n['channel'], 'note' => $n['note'],
                                           'at' => jalali_from_gregorian_ts_dotted(strtotime($n['created_at'])), 'ts' => strtotime($n['created_at'])];
    }
    $links = rnr_links($pdo);
    foreach ($rows as &$r) {
        $n = $notes[$r['key']] ?? ($notes['B:' . ($r['brd_id'] ?? 0)] ?? null);
        $r['notice'] = $n['last'] ?? null; $r['notice_count'] = $n['count'] ?? 0;
        // پیگیری‌هایی که قبلاً در خودِ بردرو ثبت شده
        if (!$r['notice'] && !empty($r['_brd_note'])) {
            $bn = $r['_brd_note'];
            $r['notice'] = ['channel' => $bn['channel'] === 'renewed' ? 'other' : $bn['channel'], 'channel_fa' => $bn['channel_fa'], 'note' => $bn['note'], 'at' => $bn['at'], 'ts' => 0];
            $r['notice_count'] = 1;
            if ($bn['channel'] === 'renewed' && !$r['renewed_by']) $r['renewed_by'] = ['policy' => '', 'issue' => $bn['at'], 'src' => 'ثبتِ دستی در بردرو'];
        }
        unset($r['_brd_renewed'], $r['_brd_note']);
        // درخواستِ تمدیدِ ثبت‌شده از همین ردیف (و بیمه‌نامه‌ی تازه‌اش وقتی صادر شد)
        $lk = $links[$r['key']] ?? (!empty($r['brd_id']) ? ($links['B:' . $r['brd_id']] ?? null) : null);
        $r['renewal'] = $lk;
        if ($lk && $lk['issued']) $r['renewed_by'] = ['policy' => $lk['policy'], 'issue' => $lk['issue_date'], 'src' => 'درخواستِ تمدید ' . $lk['ref'], 'file' => $lk['file']];
        if ($r['renewed_by']) $r['state'] = 'renewed';
        elseif ($lk) $r['state'] = 'requested';
        elseif ($r['notice'] && in_array($r['notice']['channel'], ['declined', 'renewed_out'], true)) $r['state'] = 'closed';
        elseif ($r['notice']) $r['state'] = 'notified';
        else $r['state'] = 'none';
        $r['state_fa'] = ['renewed' => 'تمدید شد', 'requested' => 'درخواستِ تمدید ثبت شد', 'closed' => $r['notice']['channel_fa'] ?? 'بسته شد', 'notified' => 'اعلام شد', 'none' => 'اعلام نشده'][$r['state']];
    }
    unset($r);
    return $rows;
}

function rn_by_keys($pdo, array $keys) {
    $want = array_flip(array_map('strval', $keys));
    $map = [];
    foreach (rn_rows($pdo) as $r) if (isset($want[$r['key']])) $map[$r['key']] = $r;
    $out = [];
    foreach ($keys as $k) if (isset($map[$k])) $out[] = $map[$k];
    return $out;
}

// گیرنده‌ی هر ردیف: شرکت (ربات شرکت‌ها) / شخص (ربات اصلی) / بدونِ ربات

function rn_log($pdo, $keys, $channel, $ok, $recipient, $note, $message, $uid) {
    $ins = $pdo->prepare("INSERT INTO renewal_notices (src, row_id, channel, ok, recipient, note, message, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    foreach ($keys as $k) {
        if (!preg_match('/^([CIPB]):(\d+)$/', (string)$k, $m)) continue;
        $ins->execute([$m[1], (int)$m[2], $channel, $ok ? 1 : 0, mb_substr((string)$recipient, 0, 200), mb_substr((string)$note, 0, 255) ?: null, $message, $uid ?: null]);
    }
}
