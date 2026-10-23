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
require_once __DIR__ . '/_renewals.php';   // rn_rows و ... (مشترک با پنلِ شرکت‌ها)

$actor = require_admin_or_liaison();
if (($actor['role'] ?? '') !== 'ADMIN') { echo json_encode(['ok' => false, 'error' => 'دسترسی به «اعلام تمدید» ندارید.'], JSON_UNESCAPED_UNICODE); exit; }
company_ensure_issue_info_cols($pdo);
imp_ensure($pdo);
rn_ensure($pdo);

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = $data['action'] ?? '';
$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };

function rn_recipients($pdo, array $rows, $tpl) {
    $groups = [];
    foreach ($rows as $r) {
        if ($r['src'] === 'C' || ($r['src'] === 'B' && $r['owner_id'])) $g = 'C' . $r['owner_id'];
        elseif ($r['src'] === 'P') $g = 'P' . $r['owner_id'];
        else $g = 'I' . md5($r['insured'] . '|' . $r['national_id']);
        $gk = $g[0] === 'C' ? 'C' : ($r['src'] === 'B' ? 'I' : $r['src']);
        if (!isset($groups[$g])) $groups[$g] = ['gid' => $g, 'kind' => $gk, 'owner_id' => $r['owner_id'], 'name' => $gk === 'C' ? $r['company'] : $r['insured'],
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
        } else $g['bot_fa'] = 'بایگانی وارداتی / بردروی بدونِ شرکت؛ ربات ندارد';
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
        if (!isset(RN_CHANNELS[$ch]) || $ch === 'bot' || $ch === 'request') $out(['ok' => false, 'error' => 'نوعِ پیگیری نامعتبر است.']);
        $keys = array_values(array_filter((array)($data['keys'] ?? []), 'is_string'));
        if (!$keys) $out(['ok' => false, 'error' => 'ردیفی انتخاب نشده.']);
        rn_log($pdo, $keys, $ch, true, '', trim((string)($data['note'] ?? '')), trim((string)($data['message'] ?? '')) ?: null, intval($actor['user_id'] ?? 0));
        $out(['ok' => true, 'count' => count($keys)]);
    }

    // تاریخچه‌ی پیگیریِ یک ردیف
    if ($action === 'history') {
        if (!preg_match('/^([CIPB]):(\d+)$/', (string)($data['key'] ?? ''), $m)) $out(['ok' => false, 'error' => 'ردیف نامعتبر.']);
        $st = $pdo->prepare("SELECT n.*, u.full_name AS by_name FROM renewal_notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.src = ? AND n.row_id = ? ORDER BY n.id DESC");
        try { $st->execute([$m[1], (int)$m[2]]); $list = $st->fetchAll(); }
        catch (Throwable $e) { $st = $pdo->prepare("SELECT * FROM renewal_notices WHERE src = ? AND row_id = ? ORDER BY id DESC"); $st->execute([$m[1], (int)$m[2]]); $list = $st->fetchAll(); }
        foreach ($list as &$n) { $n['channel_fa'] = RN_CHANNELS[$n['channel']] ?? $n['channel']; $n['at'] = jalali_from_gregorian_ts_dotted(strtotime($n['created_at'])) . ' ' . date('H:i', strtotime($n['created_at'])); }
        unset($n);
        // ردیفِ بردرو: پیگیری‌هایی که در خودِ بردرو ثبت شده هم
        if ($m[1] === 'B') {
            rn_require_brd(); brd_ensure($pdo);
            $bs = $pdo->prepare("SELECT channel, note, created_at, created_by_name FROM brd_notes WHERE row_id = ? ORDER BY id DESC");
            $bs->execute([(int)$m[2]]);
            foreach ($bs->fetchAll() as $b) $list[] = ['channel' => $b['channel'], 'channel_fa' => (BRD_CHANNELS[$b['channel']] ?? $b['channel']) . ' (بردرو)', 'ok' => 1, 'note' => $b['note'], 'message' => null,
                                                      'by_name' => $b['created_by_name'], 'at' => jalali_from_gregorian_ts_dotted(strtotime($b['created_at'])) . ' ' . date('H:i', strtotime($b['created_at'])), 'created_at' => $b['created_at']];
            usort($list, function ($a, $b) { return strcmp((string)$b['created_at'], (string)$a['created_at']); });
        }
        $out(['ok' => true, 'rows' => $list]);
    }

    // اکسل: دقیقاً همان ردیف‌ها و همان ترتیبِ صفحه (keys)
    // ---- «درخواستِ تمدید»: درخواستِ صدورِ تازه از روی بیمه‌نامه‌های قبلی (شرکتی/وارداتی/بردرو) با مدارکِ موجود ----
    if ($action === 'create_requests') {
        require_once __DIR__ . '/_import_archive.php'; require_once __DIR__ . '/_bordereau.php'; require_once __DIR__ . '/_vehicles.php';
        require_once __DIR__ . '/_registry_link.php'; require_once __DIR__ . '/_renew_request.php';
        brd_ensure($pdo);
        $items = is_array($data['items'] ?? null) ? $data['items'] : (json_decode((string)($data['items'] ?? '[]'), true) ?: []);
        if (!$items) $out(['ok' => false, 'error' => 'ردیفی انتخاب نشده.']);
        @set_time_limit(300);
        $r = rnr_create($pdo, dirname(__DIR__), $items, intval($actor['user_id'] ?? 0) ?: null,
                        ['company_id' => intval($data['company_id'] ?? 0), 'values' => ($data['values'] ?? '') === 'company' ? 'company' : 'prev', 'text' => (string)($data['text'] ?? '')]);
        // روی ردیفِ مبدأ هم ثبت می‌شود (پیگیریِ «درخواستِ تمدید»)
        foreach ($r['created'] as $c) {
            if (empty($c['src_key'])) continue;
            $ref = ref_code_of($pdo, 'company_requests', (int)$c['request_id']) ?: ('#' . $c['request_id']);
            rn_log($pdo, [$c['src_key']], 'request', true, $c['company'], 'درخواستِ تمدید ' . $ref . ' ثبت شد', null, intval($actor['user_id'] ?? 0));
        }
        $out(['ok' => true] + $r);
    }
    // فهرستِ شرکت‌ها برای «شرکتِ درخواست‌دهنده» در پنجره‌ی درخواستِ تمدید
    if ($action === 'companies') {
        $out(['ok' => true, 'companies' => $pdo->query("SELECT id, name FROM companies WHERE is_import = 0 ORDER BY name")->fetchAll()]);
    }
    if ($action === 'export') {
        $keys = json_decode((string)($data['keys'] ?? '[]'), true);
        $rows = is_array($keys) && $keys ? rn_by_keys($pdo, $keys) : rn_rows($pdo);
        require_once __DIR__ . '/_xlsx_writer.php';
        $headers = ['ردیف', 'منبع', 'بیمه‌گذار', 'شرکت / کارفرما', 'قرارداد', 'کد / شناسه ملی', 'تلفن', 'نشانی', 'پلاک', 'شماره شاسی', 'شماره موتور', 'نوع بیمه', 'خودرو', 'مدل',
                    'رنگ', 'کاربری', 'شماره بیمه‌نامه', 'کد یکتا', 'بیمه‌گر', 'تاریخ صدور', 'شروع', 'انقضا', 'روزِ مانده', 'حق بیمه (ریال)', 'ارزش خودرو (ریال)', 'تعهد مالی (ریال)',
                    'بیمه‌گرِ قبلی', 'بیمه‌نامه‌ی قبلی', 'وضعیتِ تمدید', 'آخرین پیگیری', 'تمدید با بیمه‌نامه', 'ورودی', 'درخواست و تمدید'];
        $xr = [];
        foreach ($rows as $i => $r) {
            $xr[] = [$i + 1, $r['source_fa'], $r['insured'], $r['company'], $r['contract'], $r['national_id'], $r['phone'], $r['address'], $r['plate'], $r['chassis'], $r['engine'],
                     $r['type_fa'], $r['car'], $r['model_year'], $r['color'], $r['usage'], $r['policy'], $r['central_no'], $r['insurer'], fa_digits($r['issue_date']),
                     fa_digits($r['start_date']), fa_digits($r['end_date']) . ($r['end_est'] ? ' (تخمینی)' : ''), $r['days_left'] === null ? '' : $r['days_left'],
                     $r['premium'] ?: '', $r['car_value'] ?: '', $r['liability'] ?: '', $r['prev_insurer'], $r['prev_policy'], $r['state_fa'],
                     $r['notice'] ? fa_digits($r['notice']['at']) . ' - ' . $r['notice']['channel_fa'] . ($r['notice']['note'] ? ' (' . $r['notice']['note'] . ')' : '') : '',
                     $r['renewed_by'] ? $r['renewed_by']['policy'] : '', $r['origin_fa'] ?? '', $r['renewal']['note'] ?? ''];
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
