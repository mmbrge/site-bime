<?php
// فایل: api/mk_actions.php
// API بخشِ «بازاریابی و فروش»: داشبورد، فروش‌ها، افراد، تنظیمات (انواعِ بیمه‌نامه، پاداش‌ها، سطح‌ها، حقوق)، درآمد، اکسل و PDF.
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_mk.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_xlsx_writer.php';

$out = function ($a) { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$fail = function ($m) use ($out) { $out(['ok' => false, 'error' => $m]); };
if (empty($_SESSION['user_id']) || session_name() === 'bime_company_portal') { http_response_code(401); $fail('ابتدا وارد پنل شوید.'); }
$uid = intval($_SESSION['user_id']);

function mk_perm_set($pdo) {
    static $p = null;
    if ($p !== null) return $p;
    if (perm_real_role() === 'ADMIN') return $p = 'ALL';
    $c = perm_user_custom($pdo, $_SESSION['user_id']);
    return $p = ($c !== null ? $c : perm_role_defaults(perm_real_role()));
}
function mk_can($pdo, $spec) { $p = mk_perm_set($pdo); return $p === 'ALL' || perm_allows($p, $spec); }

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
$need = [
    'bootstrap' => 'mk-dash:view|mk-sales:view|mk-people:view|mk-settings:view', 'dash' => 'mk-dash:view', 'income' => 'mk-dash:view|mk-people:view',
    'sales_list' => 'mk-sales:view', 'sale_save' => 'mk-sales:create|mk-sales:edit', 'sale_void' => 'mk-sales:delete', 'sale_delete' => 'mk-sales:delete',
    'people_list' => 'mk-people:view|mk-dash:view', 'person_save' => 'mk-people:create|mk-people:edit', 'person_delete' => 'mk-people:delete', 'person_unlink' => 'mk-people:edit',
    'settings_get' => 'mk-settings:view|mk-sales:view', 'settings_save' => 'mk-settings:edit', 'types_save' => 'mk-settings:edit', 'levels_save' => 'mk-settings:edit',
    'rewards_save' => 'mk-settings:edit', 'level_image' => 'mk-settings:edit', 'calc' => 'mk-sales:view|mk-settings:view', 'reward_calc' => 'mk-sales:view|mk-settings:view',
    'export' => 'mk-sales:export|mk-dash:export', 'pdf' => 'mk-sales:export|mk-dash:export',
];
if (!isset($need[$action])) $fail('درخواست نامعتبر است.');
if (!mk_can($pdo, $need[$action])) $fail('شما به این بخش دسترسی ندارید. از مدیر کل بخواهید دسترسی‌تان را تنظیم کند.');
mk_ensure($pdo);

$jyNow = mk_now_jm()[0]; $jmNow = mk_now_jm()[1];
$jy = intval($data['jy'] ?? 0) ?: $jyNow;
$jm = isset($data['jm']) && $data['jm'] !== '' ? max(0, min(12, intval($data['jm']))) : 0;

function mk_person_out($pdo, array $p, $jy, $jm) {
    $inc = mk_income($pdo, $p, $jy, $jm);
    return ['id' => intval($p['id']), 'full_name' => $p['full_name'], 'mobile' => $p['mobile'], 'kind' => $p['kind'], 'kind_fa' => MK_KINDS[$p['kind']] ?? '', 'national_id' => $p['national_id'],
            'level_id' => $p['level_id'] ? intval($p['level_id']) : null, 'base_salary' => $p['base_salary'] === null ? null : intval($p['base_salary']),
            'base_floor' => $p['base_floor'] === null ? null : intval($p['base_floor']), 'target' => $p['target'] === null ? null : intval($p['target']),
            'over_pct' => $p['over_pct'] === null ? null : floatval($p['over_pct']), 'volume' => json_decode((string)$p['volume_json'], true) ?: [],
            'active' => intval($p['active']), 'note' => $p['note'], 'linked' => !empty($p['bale_chat_id']) && !empty($p['bot_linked_at']),
            'linked_at' => $p['bot_linked_at'] ? mk_fa(life_like_g2j($p['bot_linked_at'])) : null,
            'month' => ['n' => $inc['data']['n'], 'premium' => $inc['data']['premium'], 'fee' => $inc['data']['fee'], 'total' => $inc['total'], 'target' => $inc['rules']['target'],
                        'target_pct' => $inc['target_pct'], 'base_ok' => $inc['base_ok'], 'level' => $inc['rules']['level'] ? ['name' => $inc['rules']['level']['name'], 'color' => $inc['rules']['level']['color'], 'emoji' => $inc['rules']['level']['emoji'], 'image' => $inc['rules']['level']['image_path']] : null]];
}
function life_like_g2j($dt) { [$y, $m, $d] = jalali_from_gregorian_ts(strtotime($dt)); return sprintf('%04d/%02d/%02d', $y, $m, $d); }

switch ($action) {
case 'bootstrap':
    $perms = [];
    foreach (perm_pages() as $k => $v) if (strpos($k, 'mk-') === 0) { $p = mk_perm_set($pdo); $perms[$k] = $p === 'ALL' ? $v[1] : array_values(array_intersect($v[1], (array)($p[$k] ?? []))); }
    $years = array_map('intval', $pdo->query("SELECT DISTINCT jy FROM mk_sales ORDER BY jy DESC")->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array($jyNow, $years, true)) array_unshift($years, $jyNow);
    $out(['ok' => true, 'perms' => $perms, 'jy' => $jyNow, 'jm' => $jmNow, 'today_j' => mk_today_j(), 'years' => $years, 'types' => mk_types($pdo), 'levels' => mk_levels($pdo),
          'settings' => mk_settings($pdo), 'people' => array_map(function ($p) { return ['id' => intval($p['id']), 'name' => $p['full_name'], 'kind' => $p['kind'], 'active' => intval($p['active'])]; }, mk_people($pdo, true)),
          'kinds' => MK_KINDS, 'pay' => MK_PAY, 'cust' => MK_CUST, 'reward_kinds' => MK_REWARD_KINDS]);

case 'dash':
    $pid = intval($data['person_id'] ?? 0);
    $tot = mk_month_data($pdo, $pid, $jy, $jm ?: null);
    // ماه به ماهِ سالِ انتخابی
    $w = "status = 'OK' AND jy = ?" . ($pid ? " AND person_id = " . $pid : '');
    $st = $pdo->prepare("SELECT jm, COUNT(*) n, SUM(premium) premium, SUM(fee_amount) fee FROM mk_sales WHERE $w GROUP BY jm");
    $st->execute([$jy]);
    $bm = []; foreach ($st->fetchAll() as $r) $bm[intval($r['jm'])] = $r;
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[] = ['m' => $m, 'label' => jalali_month_name($m), 'n' => intval($bm[$m]['n'] ?? 0), 'premium' => intval($bm[$m]['premium'] ?? 0), 'fee' => intval($bm[$m]['fee'] ?? 0)];
    // جدولِ افراد
    $board = [];
    foreach (mk_people($pdo, true) as $p) {
        if ($pid && intval($p['id']) !== $pid) continue;
        if ($jm) { $o = mk_person_out($pdo, $p, $jy, $jm); }
        else {
            $d = mk_month_data($pdo, $p['id'], $jy);
            $o = mk_person_out($pdo, $p, $jy, $jmNow);
            $o['month'] = ['n' => $d['n'], 'premium' => $d['premium'], 'fee' => $d['fee'], 'total' => null, 'target' => $o['month']['target'] * 12, 'target_pct' => $o['month']['target'] ? round($d['premium'] * 100 / ($o['month']['target'] * 12), 1) : 0, 'level' => null, 'base_ok' => null];
        }
        $board[] = $o;
    }
    usort($board, function ($a, $b) { return $b['month']['premium'] <=> $a['month']['premium']; });
    $st = $pdo->prepare("SELECT s.*, p.full_name FROM mk_sales s JOIN mk_people p ON p.id = s.person_id WHERE s.status = 'OK' AND s.jy = ?" . ($jm ? " AND s.jm = $jm" : '') . ($pid ? " AND s.person_id = $pid" : '') . " ORDER BY s.id DESC LIMIT 10");
    $st->execute([$jy]);
    $recent = $st->fetchAll();
    $target = 0;
    foreach ($board as $b) $target += intval($b['month']['target']);
    $out(['ok' => true, 'jy' => $jy, 'jm' => $jm, 'total' => $tot, 'months' => $months, 'board' => $board, 'recent' => $recent, 'target' => $target,
          'people_n' => count($board), 'active_sellers' => count(array_filter($board, function ($b) { return $b['month']['n'] > 0; }))]);

case 'income':
    $p = mk_person($pdo, $data['person_id'] ?? 0);
    if (!$p) $fail('شخص پیدا نشد.');
    $res = [];
    foreach ($jm ? [$jm] : range(1, 12) as $m) { $inc = mk_income($pdo, $p, $jy, $m); if ($jm || $inc['data']['n']) $res[] = $inc; }
    $out(['ok' => true, 'person' => mk_person_out($pdo, $p, $jy, $jm ?: $jmNow), 'months' => $res]);

case 'sales_list':
    $w = ["s.jy = ?"]; $a = [$jy];
    if ($jm) { $w[] = "s.jm = ?"; $a[] = $jm; }
    if (intval($data['person_id'] ?? 0)) { $w[] = "s.person_id = ?"; $a[] = intval($data['person_id']); }
    if (intval($data['type_id'] ?? 0)) { $w[] = "s.type_id = ?"; $a[] = intval($data['type_id']); }
    $stt = (string)($data['status'] ?? 'OK');
    if ($stt === 'OK' || $stt === 'VOID') { $w[] = "s.status = ?"; $a[] = $stt; }
    if (($q = trim(p2e_digits((string)($data['q'] ?? '')))) !== '') { $w[] = "(s.customer_name LIKE ? OR s.customer_mobile LIKE ? OR s.policy_no LIKE ? OR p.full_name LIKE ?)"; for ($i = 0; $i < 4; $i++) $a[] = "%$q%"; }
    $st = $pdo->prepare("SELECT s.*, p.full_name, u.full_name AS by_name, v.full_name AS void_by_name FROM mk_sales s JOIN mk_people p ON p.id = s.person_id LEFT JOIN users u ON u.id = s.created_by_user
                         LEFT JOIN users v ON v.id = s.voided_by WHERE " . implode(' AND ', $w) . " ORDER BY s.sale_g DESC, s.id DESC LIMIT 1000");
    $st->execute($a);
    $rows = $st->fetchAll();
    foreach ($rows as &$x) $x['created_at_j'] = $x['created_at'] ? life_like_g2j($x['created_at']) . ' ' . substr($x['created_at'], 11, 5) : '';
    unset($x);
    $ok = array_filter($rows, function ($r) { return $r['status'] === 'OK'; });
    $out(['ok' => true, 'rows' => $rows, 'sum' => ['n' => count($ok), 'premium' => array_sum(array_column($ok, 'premium')), 'fee' => array_sum(array_column($ok, 'fee_amount')), 'comm' => array_sum(array_column($ok, 'commission_amount'))]]);

case 'sale_save':
    $id = intval($data['id'] ?? 0);
    if ($id && !mk_can($pdo, 'mk-sales:edit')) $fail('اجازه‌ی ویرایشِ فروش ندارید.');
    if (!$id && !mk_can($pdo, 'mk-sales:create')) $fail('اجازه‌ی ثبتِ فروش ندارید.');
    $p = mk_person($pdo, $data['person_id'] ?? 0);
    $t = mk_type($pdo, $data['type_id'] ?? 0);
    if (!$p || !$t) $fail('بازاریاب و نوعِ بیمه‌نامه را انتخاب کنید.');
    $premium = money_to_int($data['premium'] ?? 0);
    $saleJ = trim(p2e_digits((string)($data['sale_j'] ?? ''))) ?: mk_today_j();
    if (preg_match('/^(1[34]\d{2})\D(\d{1,2})\D(\d{1,2})$/', $saleJ, $m)) $saleJ = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    $extra = ['sale_j' => $saleJ, 'customer_name' => trim((string)($data['customer_name'] ?? '')), 'customer_mobile' => auth_norm_phone($data['customer_mobile'] ?? '') ?: trim((string)($data['customer_mobile'] ?? '')),
              'policy_no' => trim(p2e_digits((string)($data['policy_no'] ?? ''))), 'note' => trim((string)($data['note'] ?? '')), 'source' => 'PANEL', 'by' => $uid];
    if ($id) {
        $st = $pdo->prepare("SELECT * FROM mk_sales WHERE id = ?");
        $st->execute([$id]);
        if (!$st->fetch()) $fail('فروش پیدا نشد.');
        $f = mk_fee($t, $premium);
        if (!preg_match('/^(1[34]\d{2})\/(\d{2})\/(\d{2})$/', $saleJ, $m)) $fail('تاریخ درست نیست.');
        $pdo->prepare("UPDATE mk_sales SET person_id = ?, type_id = ?, type_name = ?, premium = ?, commission_pct = ?, commission_amount = ?, share_pct = ?, fee_amount = ?, customer_name = ?,
                       customer_mobile = ?, policy_no = ?, note = ?, sale_j = ?, sale_g = ?, jy = ?, jm = ? WHERE id = ?")
            ->execute([$p['id'], $t['id'], $t['name'], $premium, $t['commission_pct'], $f['commission'], $f['share'], $f['fee'], $extra['customer_name'] ?: null, $extra['customer_mobile'] ?: null,
                       $extra['policy_no'] ?: null, $extra['note'] ?: null, $saleJ, date('Y-m-d', jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]))), intval($m[1]), intval($m[2]), $id]);
        $out(['ok' => true, 'id' => $id, 'fee' => $f]);
    }
    $r = mk_sale_add($pdo, $p, $t, $premium, $extra);
    if (isset($r['error'])) $fail($r['error']);
    // اطلاع به بازاریاب در ربات (اگر وصل است)
    if (!empty($data['notify']) && $p['bale_chat_id'] && function_exists('cbot_send')) cbot_send($pdo, $p['bale_chat_id'], "🧾 یک فروش به نامِ شما در پنل ثبت شد.\n\n" . mk_sale_message($pdo, $p, $t, $premium, $r['fee'], $r['jy'], $r['jm']));
    $out(['ok' => true, 'id' => $r['id'], 'fee' => $r['fee']]);

case 'sale_void':
    $pdo->prepare("UPDATE mk_sales SET status = 'VOID', voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ? AND status = 'OK'")
        ->execute([$uid, mb_substr(trim((string)($data['reason'] ?? '')), 0, 300) ?: null, intval($data['id'] ?? 0)]);
    $out(['ok' => true]);

case 'sale_delete':
    // حذفِ کامل (برخلافِ ابطال، در گزارش‌ها هم دیده نمی‌شود)
    $st = $pdo->prepare("SELECT s.*, p.full_name FROM mk_sales s JOIN mk_people p ON p.id = s.person_id WHERE s.id = ?");
    $st->execute([intval($data['id'] ?? 0)]);
    $sale = $st->fetch();
    if (!$sale) $fail('فروش پیدا نشد.');
    $pdo->prepare("DELETE FROM mk_sales WHERE id = ?")->execute([$sale['id']]);
    if (function_exists('sec_event')) sec_event($pdo, 'ADMIN_ACTION', 'حذفِ فروشِ بازاریاب: ' . $sale['full_name'] . ' · ' . $sale['type_name'] . ' · ' . $sale['premium'] . ' ریال · ' . $sale['sale_j']);
    $out(['ok' => true]);

case 'people_list':
    $o = [];
    foreach (mk_people($pdo, true) as $p) $o[] = mk_person_out($pdo, $p, $jy, $jm ?: $jmNow);
    $out(['ok' => true, 'people' => $o]);

case 'person_save':
    $id = intval($data['id'] ?? 0);
    if ($id && !mk_can($pdo, 'mk-people:edit')) $fail('اجازه‌ی ویرایش ندارید.');
    if (!$id && !mk_can($pdo, 'mk-people:create')) $fail('اجازه‌ی افزودن ندارید.');
    $name = trim((string)($data['full_name'] ?? ''));
    $mobile = auth_norm_phone($data['mobile'] ?? '');
    if ($name === '' || !preg_match('/^09\d{9}$/', $mobile)) $fail('نام و موبایلِ درست (۰۹۱۲...) لازم است.');
    foreach ($pdo->query("SELECT id, mobile FROM mk_people WHERE is_deleted = 0")->fetchAll() as $x) if (intval($x['id']) !== $id && auth_norm_phone($x['mobile']) === $mobile) $fail('این موبایل برای شخصِ دیگری ثبت شده است.');
    $nul = function ($k, $money = true) use ($data) { $v = trim((string)($data[$k] ?? '')); return $v === '' ? null : ($money ? money_to_int($v) : floatval(p2e_digits($v))); };
    $vol = mk_clean_volume($data['volume'] ?? []);
    $vals = [$name, $mobile, array_key_exists((string)($data['kind'] ?? ''), MK_KINDS) ? $data['kind'] : 'MARKETER', trim(p2e_digits((string)($data['national_id'] ?? ''))) ?: null,
             intval($data['level_id'] ?? 0) ?: null, $nul('base_salary'), $nul('base_floor'), $nul('target'), $nul('over_pct', false), $vol ? json_encode($vol) : null,
             !isset($data['active']) || !empty($data['active']) ? 1 : 0, trim((string)($data['note'] ?? '')) ?: null];
    if ($id) {
        $old = mk_person($pdo, $id);
        if (!$old) $fail('شخص پیدا نشد.');
        $pdo->prepare("UPDATE mk_people SET full_name = ?, mobile = ?, kind = ?, national_id = ?, level_id = ?, base_salary = ?, base_floor = ?, target = ?, over_pct = ?, volume_json = ?, active = ?, note = ? WHERE id = ?")
            ->execute(array_merge($vals, [$id]));
        // تغییرِ موبایل یا غیرفعال شدن => اتصالِ ربات برداشته می‌شود
        if ($old['bale_chat_id'] && (auth_norm_phone($old['mobile']) !== $mobile || !$vals[10])) {
            $pdo->prepare("UPDATE mk_people SET bale_chat_id = NULL, bot_linked_at = NULL WHERE id = ?")->execute([$id]);
            if (function_exists('cbot_send')) cbot_send($pdo, $old['bale_chat_id'], 'اتصالِ حسابِ بازاریابیِ شما به ربات برداشته شد. برای ورودِ دوباره شماره‌تان را بفرستید.');
        }
    } else {
        $pdo->prepare("INSERT INTO mk_people (full_name, mobile, kind, national_id, level_id, base_salary, base_floor, target, over_pct, volume_json, active, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)")
            ->execute(array_merge($vals, [$uid]));
        $id = intval($pdo->lastInsertId());
    }
    $out(['ok' => true, 'id' => $id]);

case 'person_delete':
    $p = mk_person($pdo, $data['id'] ?? 0);
    if (!$p) $fail('شخص پیدا نشد.');
    $pdo->prepare("UPDATE mk_people SET is_deleted = 1, active = 0, bale_chat_id = NULL, bot_linked_at = NULL WHERE id = ?")->execute([$p['id']]);
    $out(['ok' => true]);

case 'person_unlink':
    $p = mk_person($pdo, $data['id'] ?? 0);
    if (!$p) $fail('شخص پیدا نشد.');
    $pdo->prepare("UPDATE mk_people SET bale_chat_id = NULL, bot_linked_at = NULL WHERE id = ?")->execute([$p['id']]);
    $out(['ok' => true]);

case 'settings_get':
    $out(['ok' => true, 'settings' => mk_settings($pdo), 'types' => mk_types($pdo), 'levels' => mk_levels($pdo),
          'rewards' => array_map(function ($r) { foreach (['id', 'min_premium', 'min_count', 'stackable', 'priority', 'active'] as $k) $r[$k] = intval($r[$k]); $r['type_id'] = $r['type_id'] ? intval($r['type_id']) : null;
              $r['max_premium'] = $r['max_premium'] === null ? null : intval($r['max_premium']); $r['max_amount'] = $r['max_amount'] === null ? null : intval($r['max_amount']); $r['value'] = floatval($r['value']); return $r; },
              $pdo->query("SELECT * FROM mk_rewards ORDER BY COALESCE(type_id, 0), priority DESC, id")->fetchAll())]);

case 'settings_save':
    $out(['ok' => true, 'settings' => mk_settings_save($pdo, (array)($data['settings'] ?? []))]);

case 'types_save':
    $keep = [];
    foreach ((array)($data['types'] ?? []) as $k => $t) {
        $name = trim((string)($t['name'] ?? ''));
        if ($name === '') continue;
        $vals = [mb_substr($name, 0, 120), mb_substr(trim((string)($t['icon'] ?? '')), 0, 16) ?: null, max(0, min(100, floatval(p2e_digits((string)($t['commission_pct'] ?? 0))))),
                 json_encode(mk_clean_tiers($t['tiers'] ?? [])), money_to_int($t['min_premium'] ?? 0), trim((string)($t['max_premium'] ?? '')) === '' ? null : money_to_int($t['max_premium']),
                 !isset($t['active']) || !empty($t['active']) ? 1 : 0, $k + 1, mb_substr(trim((string)($t['note'] ?? '')), 0, 500) ?: null];
        if (intval($t['id'] ?? 0)) { $pdo->prepare("UPDATE mk_types SET name = ?, icon = ?, commission_pct = ?, tiers_json = ?, min_premium = ?, max_premium = ?, active = ?, sort = ?, note = ? WHERE id = ?")->execute(array_merge($vals, [intval($t['id'])])); $keep[] = intval($t['id']); }
        else { $pdo->prepare("INSERT INTO mk_types (name, icon, commission_pct, tiers_json, min_premium, max_premium, active, sort, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($vals); $keep[] = intval($pdo->lastInsertId()); }
    }
    if ($keep) { $pdo->exec("DELETE FROM mk_types WHERE id NOT IN (" . implode(',', $keep) . ")"); $pdo->exec("DELETE FROM mk_rewards WHERE type_id IS NOT NULL AND type_id NOT IN (" . implode(',', $keep) . ")"); }
    $out(['ok' => true, 'types' => mk_types($pdo)]);

case 'levels_save':
    $keep = [];
    foreach ((array)($data['levels'] ?? []) as $k => $l) {
        $name = trim((string)($l['name'] ?? ''));
        if ($name === '') continue;
        $n = function ($key, $money = true) use ($l) { $v = trim((string)($l[$key] ?? '')); return $v === '' ? null : ($money ? money_to_int($v) : floatval(p2e_digits($v))); };
        $vals = [mb_substr($name, 0, 80), preg_match('/^#[0-9a-f]{6}$/i', (string)($l['color'] ?? '')) ? $l['color'] : '#64748b', money_to_int($l['min_sales'] ?? 0), $n('max_sales'),
                 $n('base_salary'), $n('base_floor'), $n('target'), $n('over_pct', false), $n('fee_bonus_pct', false), mb_substr(trim((string)($l['perks'] ?? '')), 0, 500) ?: null, $k + 1];
        if (intval($l['id'] ?? 0)) { $pdo->prepare("UPDATE mk_levels SET name = ?, color = ?, min_sales = ?, max_sales = ?, base_salary = ?, base_floor = ?, target = ?, over_pct = ?, fee_bonus_pct = ?, perks = ?, sort = ? WHERE id = ?")->execute(array_merge($vals, [intval($l['id'])])); $keep[] = intval($l['id']); }
        else { $pdo->prepare("INSERT INTO mk_levels (name, color, min_sales, max_sales, base_salary, base_floor, target, over_pct, fee_bonus_pct, perks, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($vals); $keep[] = intval($pdo->lastInsertId()); }
    }
    $pdo->exec("DELETE FROM mk_levels" . ($keep ? " WHERE id NOT IN (" . implode(',', $keep) . ")" : ''));
    $pdo->exec("UPDATE mk_people SET level_id = NULL WHERE level_id IS NOT NULL" . ($keep ? " AND level_id NOT IN (" . implode(',', $keep) . ")" : ''));
    $out(['ok' => true, 'levels' => mk_levels($pdo)]);

case 'level_image':
    $id = intval($data['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM mk_levels WHERE id = ?");
    $st->execute([$id]);
    $l = $st->fetch();
    if (!$l) $fail('سطح پیدا نشد.');
    if (!empty($data['remove'])) { if ($l['image_path']) @unlink(dirname(__DIR__) . '/' . $l['image_path']); $pdo->prepare("UPDATE mk_levels SET image_path = NULL WHERE id = ?")->execute([$id]); $out(['ok' => true]); }
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) $fail('تصویری انتخاب نشده است.');
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || $_FILES['file']['size'] > 3 * 1048576 || !@getimagesize($_FILES['file']['tmp_name'])) $fail('فقط تصویرِ PNG/JPG/WEBP تا ۳ مگابایت.');
    $dir = dirname(__DIR__) . '/uploads/mk_levels';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if ($l['image_path']) @unlink(dirname(__DIR__) . '/' . $l['image_path']);
    $rel = 'uploads/mk_levels/level_' . $id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], dirname(__DIR__) . '/' . $rel)) $fail('ذخیره‌ی تصویر ممکن نشد.');
    $pdo->prepare("UPDATE mk_levels SET image_path = ? WHERE id = ?")->execute([$rel, $id]);
    $out(['ok' => true, 'image_path' => $rel]);

case 'rewards_save':
    $keep = [];
    foreach ((array)($data['rewards'] ?? []) as $r) {
        $title = trim((string)($r['title'] ?? ''));
        if ($title === '') continue;
        $kind = array_key_exists((string)($r['reward_kind'] ?? ''), MK_REWARD_KINDS) ? $r['reward_kind'] : 'percent';
        $vals = [intval($r['type_id'] ?? 0) ?: null, mb_substr($title, 0, 190), money_to_int($r['min_premium'] ?? 0), trim((string)($r['max_premium'] ?? '')) === '' ? null : money_to_int($r['max_premium']),
                 array_key_exists((string)($r['pay_mode'] ?? ''), MK_PAY) ? $r['pay_mode'] : 'any', array_key_exists((string)($r['customer'] ?? ''), MK_CUST) ? $r['customer'] : 'any',
                 max(1, intval(p2e_digits((string)($r['min_count'] ?? 1)))), $kind, $kind === 'percent' ? max(0, min(100, floatval(p2e_digits((string)($r['value'] ?? 0))))) : ($kind === 'amount' ? money_to_int($r['value'] ?? 0) : 0),
                 trim((string)($r['max_amount'] ?? '')) === '' ? null : money_to_int($r['max_amount']), mb_substr(trim((string)($r['gift_text'] ?? '')), 0, 300) ?: null,
                 !empty($r['stackable']) ? 1 : 0, intval($r['priority'] ?? 0), !isset($r['active']) || !empty($r['active']) ? 1 : 0, mb_substr(trim((string)($r['note'] ?? '')), 0, 500) ?: null];
        if ($kind === 'gift' && !$vals[10]) continue;
        if (intval($r['id'] ?? 0)) { $pdo->prepare("UPDATE mk_rewards SET type_id = ?, title = ?, min_premium = ?, max_premium = ?, pay_mode = ?, customer = ?, min_count = ?, reward_kind = ?, value = ?, max_amount = ?, gift_text = ?, stackable = ?, priority = ?, active = ?, note = ? WHERE id = ?")->execute(array_merge($vals, [intval($r['id'])])); $keep[] = intval($r['id']); }
        else { $pdo->prepare("INSERT INTO mk_rewards (type_id, title, min_premium, max_premium, pay_mode, customer, min_count, reward_kind, value, max_amount, gift_text, stackable, priority, active, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($vals); $keep[] = intval($pdo->lastInsertId()); }
    }
    $pdo->exec("DELETE FROM mk_rewards" . ($keep ? " WHERE id NOT IN (" . implode(',', $keep) . ")" : ''));
    $out(['ok' => true]);

case 'calc':
    $t = mk_type($pdo, $data['type_id'] ?? 0);
    if (!$t) $fail('نوعِ بیمه‌نامه را انتخاب کنید.');
    $out(['ok' => true, 'fee' => mk_fee($t, money_to_int($data['premium'] ?? 0))]);

case 'reward_calc':
    $t = mk_type($pdo, $data['type_id'] ?? 0);
    if (!$t) $fail('نوعِ بیمه‌نامه را انتخاب کنید.');
    $premium = money_to_int($data['premium'] ?? 0);
    $pay = array_key_exists((string)($data['pay'] ?? ''), MK_PAY) ? $data['pay'] : 'any';
    $cust = array_key_exists((string)($data['customer'] ?? ''), MK_CUST) ? $data['customer'] : 'any';
    $cnt = max(1, intval($data['count'] ?? 1));
    $fee = mk_fee($t, $premium);
    $c = mk_reward_calc($pdo, $t['id'], $premium, $pay, $cust, $cnt, $fee['commission']);
    $out(['ok' => true, 'result' => $c, 'text' => mk_reward_text($t, $premium, $c, $pay, $cust, $cnt), 'fee' => $fee]);

case 'export':
    $kind = ($data['kind'] ?? '') === 'summary' ? 'summary' : 'sales';
    $pid = intval($data['person_id'] ?? 0);
    $label = $jm ? mk_month_label($jy, $jm) : 'سال ' . $jy;
    if ($kind === 'sales') {
        $w = "s.status = 'OK' AND s.jy = ?" . ($jm ? " AND s.jm = $jm" : '') . ($pid ? " AND s.person_id = $pid" : '') . (intval($data['type_id'] ?? 0) ? " AND s.type_id = " . intval($data['type_id']) : '');
        $st = $pdo->prepare("SELECT s.*, p.full_name, p.kind, u.full_name AS by_name FROM mk_sales s JOIN mk_people p ON p.id = s.person_id LEFT JOIN users u ON u.id = s.created_by_user WHERE $w ORDER BY s.sale_g, s.id");
        $st->execute([$jy]);
        $rows = array_map(function ($r) { return [$r['sale_j'], $r['full_name'], MK_KINDS[$r['kind']] ?? '', $r['type_name'], intval($r['premium']), floatval($r['commission_pct']), intval($r['commission_amount']),
            floatval($r['share_pct']), intval($r['fee_amount']), $r['customer_name'], $r['customer_mobile'], $r['policy_no'], $r['source'] === 'BOT' ? 'ربات بله' : 'پنل', $r['by_name'], $r['note']]; }, $st->fetchAll());
        $path = xlsx_build(['تاریخ', 'بازاریاب', 'نقش', 'نوع بیمه‌نامه', 'حق‌بیمه (ریال)', 'کارمزد٪', 'کارمزد (ریال)', 'سهم بازاریاب٪', 'حق بازاریابی (ریال)', 'مشتری', 'موبایل مشتری',
                            'شماره بیمه‌نامه', 'ثبت از', 'ثبت‌کننده‌ی پنل', 'توضیح'], $rows, 'فروش‌ها', [4, 5, 6, 7, 8]);
        xlsx_send($path, 'فروش بازاریابان - ' . $label . '.xlsx');
        exit;
    }
    $rows = [];
    $people = $pid ? array_filter([mk_person($pdo, $pid)]) : mk_people($pdo, true);
    foreach ($people as $p) foreach ($jm ? [$jm] : range(1, 12) as $m) {
        $inc = mk_income($pdo, $p, $jy, $m);
        if (!$jm && !$inc['data']['n']) continue;
        $rows[] = [$p['full_name'], MK_KINDS[$p['kind']] ?? '', mk_month_label($jy, $m), $inc['data']['n'], $inc['data']['premium'], $inc['data']['fee'], $inc['base'], $inc['over'], $inc['volume'],
                   $inc['level_bonus'], $inc['total'], $inc['rules']['target'], $inc['target_pct'], $inc['rules']['level']['name'] ?? '', $inc['base_ok'] ? 'بله' : 'خیر'];
    }
    $path = xlsx_build(['نام', 'نقش', 'ماه', 'تعداد فروش', 'جمع حق‌بیمه', 'حق بازاریابی', 'حقوق ثابت', 'اضافه‌ی بالای کف', 'پاداش حجم', 'پاداش سطح', 'کل درآمد', 'هدف ماهانه', 'درصد هدف', 'سطح', 'رسیدن به کف'],
                       $rows, 'درآمد', [3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
    xlsx_send($path, 'درآمد بازاریابان - ' . $label . '.xlsx');
    exit;

case 'pdf':
    @set_time_limit(120);
    $file = mk_pdf($pdo, intval($data['person_id'] ?? 0), $jy, $jm ?: null);
    header('Content-Type: application/pdf');
    header("Content-Disposition: " . (!empty($data['inline']) ? 'inline' : 'attachment') . "; filename=\"report.pdf\"; filename*=UTF-8''" . rawurlencode(basename($file)));
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
$fail('درخواست نامعتبر است.');
