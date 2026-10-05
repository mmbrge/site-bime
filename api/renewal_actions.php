<?php
// فایل: api/renewal_actions.php
// «اعلام تمدید»: همه‌ی بیمه‌نامه‌های صادرشده (شرکتی، بایگانی وارداتی و کارکنان) در یک فهرست با تاریخ انقضا،
// تشخیصِ خودکارِ «تمدیدشده» (بیمه‌نامه‌ی تازه‌تر برای همان شاسی/پلاک و نوع)، ثبتِ پیگیری‌ها، ارسالِ اعلامِ تمدید
// با ربات بله (کاربرانِ شرکت با ربات شرکت‌ها، کارکنان با ربات اصلی) و خروجیِ اکسل به همان ترتیب و فیلترِ صفحه.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require __DIR__ . '/finance_core.php';

$actor = require_admin_or_liaison();
if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'دسترسی به «اعلام تمدید» ندارید.'], JSON_UNESCAPED_UNICODE); exit; }
company_ensure_issue_info_cols($pdo);
imp_ensure($pdo);
rn_ensure($pdo);

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = $data['action'] ?? '';
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };

function rn_ensure($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_notices (
        id INT AUTO_INCREMENT PRIMARY KEY, src CHAR(1) NOT NULL, row_id INT NOT NULL, channel VARCHAR(20) NOT NULL, ok TINYINT(1) NOT NULL DEFAULT 1,
        recipient VARCHAR(200) NULL, note VARCHAR(255) NULL, message TEXT NULL, created_by INT NULL, created_at DATETIME NULL, KEY idx_row (src, row_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
const RN_CHANNELS = ['bot' => 'ربات بله', 'phone' => 'تماس تلفنی', 'sms' => 'پیامک', 'letter' => 'نامه', 'visit' => 'حضوری', 'other' => 'سایر', 'declined' => 'تمدید نمی‌کند', 'renewed_out' => 'جای دیگر تمدید کرد'];

// تاریخِ شمسیِ «۱۴۰۵/۰۷/۱۳» (هر شکلی) => [jalali 'Y/m/d', ts] یا null
function rn_j($v) {
    $v = p2e_digits(trim((string)$v));
    if (!preg_match('/(1[34]\d{2})\D+(\d{1,2})\D+(\d{1,2})/', $v, $m) || $m[2] < 1 || $m[2] > 12 || $m[3] < 1 || $m[3] > 31) return null;
    return [sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]), jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]))];
}
function rn_plus_year($j) { [$y, $m, $d] = array_map('intval', explode('/', $j)); $d = min($d, company_jalali_month_len($y + 1, $m)); return sprintf('%04d/%02d/%02d', $y + 1, $m, $d); }
function rn_pick(array $srcs, $keys) { foreach ($srcs as $s) foreach ((array)$keys as $k) if (isset($s[$k]) && is_scalar($s[$k]) && trim((string)$s[$k]) !== '') return trim((string)$s[$k]); return ''; }
function rn_key_vin($v) { $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); return strlen($v) >= 6 ? $v : ''; }

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
            'engine' => $r['engine_no'] ?: rn_pick($S, ['engine_no']), 'type' => $r['insurance_type'], 'car' => $r['car_name'] ?: trim(rn_pick($S, 'car_system') . ' ' . rn_pick($S, ['car_type', 'car_tip'])),
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
                'contract' => $contracts[(int)$r['contract_id']] ?? rn_pick([$oc], 'contract_no'),
                'national_id' => $r['insured_national_id'] ?: $r['national_code'], 'phone' => $r['insured_phone'] ?: ($r['ocr_phone'] ?: $r['mobile_number']),
                'address' => $r['insured_address'] ?: rn_pick($S, ['insured_address', 'address']), 'owner_name' => rn_pick($S, 'owner_name'),
                'plate' => $r['plate'] ?: '', 'chassis' => $r['chassis_num'] ?: $r['vin'], 'engine' => $r['engine_num'], 'type' => $r['insurance_type'],
                'car' => trim(($r['car_system'] ?? '') . ' ' . ($r['car_type'] ?? '')), 'model_year' => $r['car_model_year'], 'color' => $r['car_color'], 'usage' => $r['car_usage'],
                'policy' => $r['policy_number'], 'central_no' => $r['central_unique_code'], 'premium' => (int)$r['total_premium'], 'car_value' => (int)$r['car_value'],
                'liability' => (int)preg_replace('/\D/', '', (string)$r['liability_limit']), 'insurer' => 'پاسارگاد',
                'prev_insurer' => rn_pick($S, 'prev_insurer'), 'prev_policy' => rn_pick($S, ['prev_policy_number', 'prev_policy']),
                '_issue' => $r['policy_issue_date'] ?: rn_pick($S, 'issue_date'), '_start' => rn_pick($S, 'start_date'), '_end' => rn_pick($S, 'end_date'),
                '_issued_at' => $r['issued_at'], 'file' => $r['issued_file_path'], 'has_bot' => !empty($r['bale_chat_id']),
            ];
        }
    } catch (Throwable $e) { error_log('[renewal personnel] ' . $e->getMessage()); }

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
        $r['renewed_by'] = null;
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
    foreach ($rows as &$r) {
        $n = $notes[$r['key']] ?? null;
        $r['notice'] = $n['last'] ?? null; $r['notice_count'] = $n['count'] ?? 0;
        if ($r['renewed_by']) $r['state'] = 'renewed';
        elseif ($r['notice'] && in_array($r['notice']['channel'], ['declined', 'renewed_out'], true)) $r['state'] = 'closed';
        elseif ($r['notice']) $r['state'] = 'notified';
        else $r['state'] = 'none';
        $r['state_fa'] = ['renewed' => 'تمدید شد', 'closed' => $r['notice']['channel_fa'] ?? 'بسته شد', 'notified' => 'اعلام شد', 'none' => 'اعلام نشده'][$r['state']];
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
function rn_recipients($pdo, array $rows, $tpl) {
    $groups = [];
    foreach ($rows as $r) {
        if ($r['src'] === 'C') $g = 'C' . $r['owner_id'];
        elseif ($r['src'] === 'P') $g = 'P' . $r['owner_id'];
        else $g = 'I' . md5($r['insured'] . '|' . $r['national_id']);
        if (!isset($groups[$g])) $groups[$g] = ['gid' => $g, 'kind' => $r['src'], 'owner_id' => $r['owner_id'], 'name' => $r['src'] === 'C' ? $r['company'] : $r['insured'],
                                               'phone' => $r['phone'], 'rows' => [], 'bot' => false, 'bot_fa' => ''];
        $groups[$g]['rows'][] = $r;
    }
    foreach ($groups as &$g) {
        if ($g['kind'] === 'C') {
            $n = 0;
            try {
                $st = $pdo->prepare("SELECT COUNT(DISTINCT cpu.id) FROM company_portal_users cpu JOIN company_portal_user_companies cpuc ON cpuc.portal_user_id = cpu.id
                                      WHERE cpuc.company_id = ? AND cpu.is_active = 1 AND COALESCE(cpu.is_deleted, 0) = 0 AND cpu.bale_chat_id IS NOT NULL AND cpu.bot_linked_at IS NOT NULL");
                $st->execute([$g['owner_id']]); $n = (int)$st->fetchColumn();
            } catch (Throwable $e) {}
            $g['bot'] = $n > 0 && cbot_token($pdo); $g['bot_fa'] = $n ? fa_digits($n) . ' کاربرِ شرکت در ربات شرکت‌ها' : 'هیچ کاربرِ این شرکت به ربات وصل نیست';
        } elseif ($g['kind'] === 'P') {
            $g['bot'] = !empty($g['rows'][0]['has_bot']) && rn_main_token($pdo); $g['bot_fa'] = !empty($g['rows'][0]['has_bot']) ? 'ربات بله (پرسنل)' : 'به ربات بله وصل نیست';
        } else $g['bot_fa'] = 'بایگانی وارداتی؛ ربات ندارد';
        $g['message'] = rn_message($tpl, $g);
        $g['keys'] = array_map(function ($r) { return $r['key']; }, $g['rows']);
        $g['count'] = count($g['rows']);
        unset($g['rows']);
    }
    unset($g);
    return array_values($groups);
}
function rn_message($tpl, $g) {
    $lines = [];
    foreach ($g['rows'] as $r) {
        $id = $r['plate'] !== '' ? fa_digits($r['plate']) : ('شاسی ' . $r['chassis']);
        $left = $r['days_left'] === null ? '' : ($r['days_left'] < 0 ? ' (منقضی شده)' : ($r['days_left'] === 0 ? ' (امروز)' : ' (' . fa_digits($r['days_left']) . ' روز دیگر)'));
        $lines[] = '• ' . $r['type_fa'] . ' ' . $id . ($r['car'] ? ' - ' . $r['car'] : '') . ' | بیمه‌نامه ' . $r['policy'] . ' | انقضا ' . fa_digits($r['end_date']) . $left;
    }
    $first = $g['rows'][0];
    $msg = strtr($tpl, ['{نام}' => $g['name'], '{لیست}' => implode("\n", $lines), '{تعداد}' => fa_digits(count($lines)),
                        '{انقضا}' => fa_digits($first['end_date']), '{پلاک}' => $first['plate'] ?: $first['chassis'], '{نوع}' => $first['type_fa'], '{شماره}' => $first['policy']]);
    return trim($msg);
}
function rn_main_token($pdo) {
    try { $st = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bot_token'"); return $st->fetchColumn() ?: null; } catch (Throwable $e) { return null; }
}
function rn_send_main($pdo, $chat, $text) {
    $token = rn_main_token($pdo);
    if (!$token || !$chat) return false;
    $ch = curl_init(cbot_base() . '/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chat, 'text' => $text], JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return is_array($res) && !empty($res['ok']);
}
function rn_log($pdo, $keys, $channel, $ok, $recipient, $note, $message, $uid) {
    $ins = $pdo->prepare("INSERT INTO renewal_notices (src, row_id, channel, ok, recipient, note, message, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    foreach ($keys as $k) {
        if (!preg_match('/^([CIP]):(\d+)$/', (string)$k, $m)) continue;
        $ins->execute([$m[1], (int)$m[2], $channel, $ok ? 1 : 0, mb_substr((string)$recipient, 0, 200), mb_substr((string)$note, 0, 255) ?: null, $message, $uid ?: null]);
    }
}
const RN_TPL = "با سلام و احترام\n{نام} گرامی\nبیمه‌نامه‌های زیر به‌زودی منقضی می‌شوند؛ برای تمدید و حفظِ تخفیف‌ها لطفاً با ما در تماس باشید:\n{لیست}\n\nبیمه با ما";

try {
    if ($action === 'list') {
        $rows = rn_rows($pdo);
        $tpl = null;
        try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'renewal_template'"); $st->execute(); $tpl = $st->fetchColumn() ?: null; } catch (Throwable $e) {}
        $out(['ok' => true, 'rows' => $rows, 'channels' => RN_CHANNELS, 'template' => $tpl ?: RN_TPL, 'today' => jalali_from_gregorian_ts_dotted(time())]);
    }

    // پیش‌نمایشِ پیام‌ها به تفکیکِ گیرنده (چیزی فرستاده نمی‌شود)
    if ($action === 'preview') {
        $rows = rn_by_keys($pdo, (array)($data['keys'] ?? []));
        if (!$rows) $out(['ok' => false, 'error' => 'ردیفی انتخاب نشده.']);
        $tpl = trim((string)($data['template'] ?? '')) ?: RN_TPL;
        $out(['ok' => true, 'groups' => rn_recipients($pdo, $rows, $tpl)]);
    }

    // ارسال با ربات (برای گیرنده‌هایی که ربات دارند) + ثبتِ پیگیری؛ متنِ هر گروه می‌تواند ویرایش شده باشد
    if ($action === 'send') {
        $tpl = trim((string)($data['template'] ?? '')) ?: RN_TPL;
        if (!empty($data['save_template'])) {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('renewal_template', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$tpl]);
        }
        $edits = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        $only = isset($data['gids']) ? array_flip((array)$data['gids']) : null;
        $groups = rn_recipients($pdo, rn_by_keys($pdo, (array)($data['keys'] ?? [])), $tpl);
        $sent = 0; $failed = []; $uid = intval($actor['user_id'] ?? 0);
        foreach ($groups as $g) {
            if ($only !== null && !isset($only[$g['gid']])) continue;
            $msg = trim((string)($edits[$g['gid']] ?? '')) ?: $g['message'];
            if (!$g['bot']) { $failed[] = ['gid' => $g['gid'], 'name' => $g['name'], 'error' => $g['bot_fa']]; continue; }
            $ok = false;
            if ($g['kind'] === 'C') {
                $st = $pdo->prepare("SELECT DISTINCT cpu.bale_chat_id FROM company_portal_users cpu JOIN company_portal_user_companies cpuc ON cpuc.portal_user_id = cpu.id
                                      WHERE cpuc.company_id = ? AND cpu.is_active = 1 AND COALESCE(cpu.is_deleted, 0) = 0 AND cpu.bale_chat_id IS NOT NULL AND cpu.bot_linked_at IS NOT NULL");
                $st->execute([$g['owner_id']]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chat) { $r = cbot_send($pdo, $chat, $msg); if ($r) $ok = true; }
            } else {
                $st = $pdo->prepare("SELECT bale_chat_id FROM persons WHERE id = ?");
                $st->execute([$g['owner_id']]);
                $ok = rn_send_main($pdo, $st->fetchColumn(), $msg);
            }
            rn_log($pdo, $g['keys'], 'bot', $ok, $g['name'], $ok ? null : 'ارسال ناموفق', $msg, $uid);
            if ($ok) $sent++; else $failed[] = ['gid' => $g['gid'], 'name' => $g['name'], 'error' => 'ارسال با ربات ناموفق بود'];
        }
        $out(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
    }

    // ثبتِ دستیِ پیگیری (تماس، پیامک، نامه، «تمدید نمی‌کند»، ...)
    if ($action === 'mark') {
        $ch = (string)($data['channel'] ?? '');
        if (!isset(RN_CHANNELS[$ch]) || $ch === 'bot') $out(['ok' => false, 'error' => 'نوعِ پیگیری نامعتبر است.']);
        $keys = array_values(array_filter((array)($data['keys'] ?? []), 'is_string'));
        if (!$keys) $out(['ok' => false, 'error' => 'ردیفی انتخاب نشده.']);
        rn_log($pdo, $keys, $ch, true, '', trim((string)($data['note'] ?? '')), trim((string)($data['message'] ?? '')) ?: null, intval($actor['user_id'] ?? 0));
        $out(['ok' => true, 'count' => count($keys)]);
    }

    // تاریخچه‌ی پیگیریِ یک ردیف
    if ($action === 'history') {
        if (!preg_match('/^([CIP]):(\d+)$/', (string)($data['key'] ?? ''), $m)) $out(['ok' => false, 'error' => 'ردیف نامعتبر.']);
        $st = $pdo->prepare("SELECT n.*, u.full_name AS by_name FROM renewal_notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.src = ? AND n.row_id = ? ORDER BY n.id DESC");
        try { $st->execute([$m[1], (int)$m[2]]); $list = $st->fetchAll(); }
        catch (Throwable $e) { $st = $pdo->prepare("SELECT * FROM renewal_notices WHERE src = ? AND row_id = ? ORDER BY id DESC"); $st->execute([$m[1], (int)$m[2]]); $list = $st->fetchAll(); }
        foreach ($list as &$n) { $n['channel_fa'] = RN_CHANNELS[$n['channel']] ?? $n['channel']; $n['at'] = jalali_from_gregorian_ts_dotted(strtotime($n['created_at'])) . ' ' . date('H:i', strtotime($n['created_at'])); }
        unset($n);
        $out(['ok' => true, 'rows' => $list]);
    }

    // اکسل: دقیقاً همان ردیف‌ها و همان ترتیبِ صفحه (keys)
    if ($action === 'export') {
        $keys = json_decode((string)($data['keys'] ?? '[]'), true);
        $rows = is_array($keys) && $keys ? rn_by_keys($pdo, $keys) : rn_rows($pdo);
        require_once __DIR__ . '/_xlsx_writer.php';
        $headers = ['ردیف', 'منبع', 'بیمه‌گذار', 'شرکت / کارفرما', 'قرارداد', 'کد / شناسه ملی', 'تلفن', 'نشانی', 'پلاک', 'شماره شاسی', 'شماره موتور', 'نوع بیمه', 'خودرو', 'مدل',
                    'رنگ', 'کاربری', 'شماره بیمه‌نامه', 'کد یکتا', 'بیمه‌گر', 'تاریخ صدور', 'شروع', 'انقضا', 'روزِ مانده', 'حق بیمه (ریال)', 'ارزش خودرو (ریال)', 'تعهد مالی (ریال)',
                    'بیمه‌گرِ قبلی', 'بیمه‌نامه‌ی قبلی', 'وضعیتِ تمدید', 'آخرین پیگیری', 'تمدید با بیمه‌نامه'];
        $xr = [];
        foreach ($rows as $i => $r) {
            $xr[] = [$i + 1, $r['source_fa'], $r['insured'], $r['company'], $r['contract'], $r['national_id'], $r['phone'], $r['address'], $r['plate'], $r['chassis'], $r['engine'],
                     $r['type_fa'], $r['car'], $r['model_year'], $r['color'], $r['usage'], $r['policy'], $r['central_no'], $r['insurer'], fa_digits($r['issue_date']),
                     fa_digits($r['start_date']), fa_digits($r['end_date']) . ($r['end_est'] ? ' (تخمینی)' : ''), $r['days_left'] === null ? '' : $r['days_left'],
                     $r['premium'] ?: '', $r['car_value'] ?: '', $r['liability'] ?: '', $r['prev_insurer'], $r['prev_policy'], $r['state_fa'],
                     $r['notice'] ? fa_digits($r['notice']['at']) . ' - ' . $r['notice']['channel_fa'] . ($r['notice']['note'] ? ' (' . $r['notice']['note'] . ')' : '') : '',
                     $r['renewed_by'] ? $r['renewed_by']['policy'] : ''];
        }
        $path = xlsx_build($headers, $xr, 'اعلام تمدید', [0, 22, 23, 24, 25]);
        header_remove('Content-Type');
        xlsx_send($path, 'اعلام تمدید ' . fa_digits(str_replace('/', '-', jalali_from_gregorian_ts_dotted(time()))) . '.xlsx');
        exit;
    }

    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[renewal_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
