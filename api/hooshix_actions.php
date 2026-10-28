<?php
// فایل: api/hooshix_actions.php
// «هوشیکس X ai» (hooshix.js، hooshix-admin.js): گفتگوها (با کدِ یکتا)، فرستادنِ پیام و فایل با سهمیه، صدا (گفتار↔متن)،
// خریدِ اشتراک (api/_pay.php)، و تنظیماتِ مدیر کل (سرویس‌دهنده و مدل، سهمیه‌ها، پلن‌ها، تخفیف‌ها، درگاه‌ها، تاریخچه‌ی گفتگوها،
// پرداخت‌ها، صفر کردنِ مصرف و فعال‌سازیِ دستیِ اشتراک). منطق: api/_hooshix.php
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_hooshix.php';
require_once __DIR__ . '/_pay.php';

function hxo($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$uid = intval($_SESSION['user_id'] ?? 0);
if (!$uid) { http_response_code(401); hxo(['ok' => false, 'error' => 'ابتدا وارد شوید.', 'session_expired' => true]); }
if (!auth_staff_is_active($pdo, $uid)) hxo(['ok' => false, 'error' => 'حساب غیرفعال است.']);
$isAdmin = (perm_real_role() ?: ($_SESSION['role'] ?? '')) === 'ADMIN';
$userName = (string)($_SESSION['full_name'] ?? '');
$raw = (string)file_get_contents('php://input');
// بدنه‌ی بزرگ‌تر از post_max_sizeِ هاست خالی می‌رسد (مثلاً فایلِ پیوستِ خیلی بزرگ)
if ($raw === '' && intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST)) hxo(['ok' => false, 'error' => 'حجمِ پیام (فایل‌ها) بیشتر از حدِ مجازِ هاست است (post_max_size = ' . ini_get('post_max_size') . '). فایلِ کوچک‌تر بفرستید.']);
$in = (json_decode($raw, true) ?: []) + $_POST + $_GET;
$action = (string)($in['action'] ?? '');
hx_ensure($pdo);
$C = hx_cfg($pdo);
$S = hx_user_state($pdo, $uid, $isAdmin, $C);
$jd = function ($dt) { if (!$dt) return ''; $ts = strtotime($dt); return vsprintf('%04d/%02d/%02d', jalali_from_gregorian_ts($ts)) . ' ' . date('H:i', $ts); };
$thread = function ($id) use ($pdo, $uid) {
    $st = $pdo->prepare("SELECT * FROM hx_threads WHERE id = ? AND user_id = ? AND is_deleted = 0");
    $st->execute([intval($id), $uid]);
    $t = $st->fetch();
    return $t ?: null;
};
$myThreads = function () use ($pdo, $uid, $jd) {
    $st = $pdo->prepare("SELECT id, code, title, model, updated_at FROM hx_threads WHERE user_id = ? AND is_deleted = 0 ORDER BY updated_at DESC, id DESC LIMIT 100");
    $st->execute([$uid]);
    return array_map(function ($t) use ($jd) { return ['id' => intval($t['id']), 'code' => (string)$t['code'], 'title' => $t['title'], 'model' => $t['model'], 'at' => $jd($t['updated_at'])]; }, $st->fetchAll());
};
// پیام‌های یک گفتگو با ریزِ هر پیام (زمان، مدتِ پاسخ، توکن، مدل، سیستم، فایل‌ها)
$threadMsgs = function ($tid, $admin = false) use ($pdo, $jd) {
    require_once __DIR__ . '/_ollama.php';
    $nodes = ol_cfg($pdo)['nodes'];
    $st = $pdo->prepare("SELECT id, role, content, tokens, billed, ms, model, node, files, created_at FROM hx_messages WHERE thread_id = ? ORDER BY id");
    $st->execute([$tid]);
    $rows = $st->fetchAll();
    $fids = []; foreach ($rows as $m) if ($m['files']) $fids[] = $m['files'];
    $files = []; foreach (hx_files_by_ids($pdo, implode(',', $fids)) as $f) $files[$f['id']] = ['id' => intval($f['id']), 'name' => $f['name'], 'kind' => $f['kind'], 'size' => intval($f['size']), 'pages' => intval($f['pages'])];
    return array_map(function ($m) use ($jd, $files, $nodes) {
        $fl = []; foreach (array_filter(explode(',', (string)$m['files'])) as $i) if (isset($files[intval($i)])) $fl[] = $files[intval($i)];
        return ['id' => intval($m['id']), 'role' => $m['role'], 'text' => $m['content'], 'tokens' => intval($m['tokens']), 'billed' => $m['billed'] !== null ? intval($m['billed']) : intval($m['tokens']),
                'ms' => intval($m['ms']), 'model' => (string)$m['model'], 'node' => $m['node'] ? ($nodes[$m['node']]['name'] ?? $m['node']) : '', 'at' => $jd($m['created_at']), 'files' => $fl];
    }, $rows);
};

// ---------------- تنظیماتِ مدیر کل ----------------
if (strpos($action, 'adm_') === 0) {
    if (!$isAdmin) hxo(['ok' => false, 'error' => 'فقط مدیر کل.']);
    if ($action === 'adm_get') {
        $raw = json_decode((string)sms_get($pdo, 'hooshix_cfg', '{}'), true) ?: [];
        $c = $C; $c['token'] = ''; $c['has_token'] = trim((string)($raw['token'] ?? '')) !== ''; $c['models'] = implode(', ', $C['models']);
        $c['enabled'] = $C['enabled'] ? '1' : '0'; $c['mask'] = $C['mask'] ? '1' : '0'; $c['voice'] = $C['voice'] ? '1' : '0'; $c['voice_reply'] = $C['voice_reply'] ? '1' : '0';
        require_once __DIR__ . '/_ollama.php';
        $c['ollama_on'] = $C['ollama_on'] ? '1' : '0'; $c['ollama_models'] = implode(', ', $C['ollama_models']);
        $c['files_default'] = $C['files_default'] ? '1' : '0'; $c['shop'] = $C['shop'] ? '1' : '0';
        $ow = ol_worker($pdo);
        hxo(['ok' => true, 'cfg' => $c, 'providers' => HX_PROVIDERS, 'voices' => HX_VOICES, 'ready' => hx_ready($pdo, $C),
             'ollama' => ['enabled' => ol_enabled($pdo), 'why' => ol_enabled($pdo) ? '' : ol_why($pdo, 'chat', '-'), 'online' => $ow['online'], 'models' => $ow['models'], 'ago' => ol_ago_fa($ow['ago'])]]);
    }
    if ($action === 'adm_save') {
        $raw = json_decode((string)sms_get($pdo, 'hooshix_cfg', '{}'), true) ?: [];
        $n = (array)($in['cfg'] ?? []);
        $keys = ['enabled', 'provider', 'base_url', 'models', 'temperature', 'max_reply', 'history', 'system', 'access', 'def_daily', 'def_month_tokens', 'mask', 'voice', 'voice_reply', 'stt_model', 'tts_model', 'tts_voice', 'timeout', 'ollama_on', 'ollama_models',
                 'def_day_tokens', 'def_week_tokens', 'ollama_rate', 'files_default', 'file_max_mb', 'file_max_count', 'shop'];
        foreach ($keys as $k) if (array_key_exists($k, $n)) $raw[$k] = is_scalar($n[$k]) ? (string)$n[$k] : '';
        if (trim((string)($n['token'] ?? '')) !== '') $raw['token'] = trim((string)$n['token']);
        if (!empty($n['clear_token'])) $raw['token'] = '';
        sms_set($pdo, 'hooshix_cfg', json_encode($raw, JSON_UNESCAPED_UNICODE));
        hxo(['ok' => true, 'ready' => hx_ready($pdo)]);
    }
    if ($action === 'adm_users') {
        $ms = hx_month_start();
        $sql = "SELECT u.id, u.full_name, u.role, h.enabled, h.daily_msgs, h.month_tokens,
                (SELECT COUNT(*) FROM hx_messages m WHERE m.user_id = u.id AND m.role = 'user' AND m.created_at >= " . $pdo->quote(date('Y-m-d 00:00:00')) . ") AS today,
                (SELECT COALESCE(SUM(m.tokens), 0) FROM hx_messages m WHERE m.user_id = u.id AND m.created_at >= " . $pdo->quote($ms) . ") AS month_used
            FROM users u LEFT JOIN hx_users h ON h.user_id = u.id";
        try { $rows = $pdo->query($sql . " WHERE COALESCE(u.is_deleted, 0) = 0 ORDER BY u.full_name")->fetchAll(); }
        catch (Throwable $e) { $rows = $pdo->query($sql . " ORDER BY u.full_name")->fetchAll(); }
        hxo(['ok' => true, 'users' => array_map(function ($r) use ($C) {
            $adm = $r['role'] === 'ADMIN';
            return ['id' => intval($r['id']), 'name' => $r['full_name'], 'role' => $r['role'], 'admin' => $adm, 'has_row' => $r['enabled'] !== null,
                    'enabled' => $adm || ($r['enabled'] !== null ? !empty($r['enabled']) : $C['access'] === 'all'),
                    'daily' => $r['daily_msgs'] !== null ? intval($r['daily_msgs']) : null, 'month_tokens' => $r['month_tokens'] !== null ? intval($r['month_tokens']) : null,
                    'today' => intval($r['today']), 'month_used' => intval($r['month_used'])];
        }, $rows), 'defaults' => ['daily' => $C['def_daily'], 'month_tokens' => $C['def_month_tokens']]]);
    }
    if ($action === 'adm_user_save') {
        $id = intval($in['user_id'] ?? 0);
        if (!$id) hxo(['ok' => false, 'error' => 'کاربر؟']);
        $num = function ($v) { $v = trim(p2e_digits((string)$v)); return $v === '' ? null : max(0, intval(preg_replace('/\D/', '', $v))); };
        $pdo->prepare("INSERT INTO hx_users (user_id, enabled, daily_msgs, month_tokens, updated_at) VALUES (?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), daily_msgs = VALUES(daily_msgs), month_tokens = VALUES(month_tokens), updated_at = NOW()")
            ->execute([$id, !empty($in['enabled']) ? 1 : 0, $num($in['daily'] ?? ''), $num($in['month_tokens'] ?? '')]);
        hxo(['ok' => true]);
    }
    // ---- سهمیه‌ها، پلن‌ها، تخفیف‌ها، درگاه‌ها ----
    $offersOut = function () use ($pdo) {
        return array_map(function ($o) { $o['start_j'] = hx_jdt($o['start_at']); $o['end_j'] = hx_jdt($o['end_at']); $o['plan_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)$o['plan_ids'])))); return $o; },
                         $pdo->query("SELECT * FROM hx_offers ORDER BY id DESC")->fetchAll());
    };
    if ($action === 'adm_shop_get') {
        hxo(['ok' => true, 'plans' => hx_plans($pdo, false), 'offers' => $offersOut(), 'gateways' => pay_gateways_public($pdo), 'pay_types' => PAY_TYPES, 'callback' => pay_base_url() . '/api/pay_callback.php',
             'stats' => $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) s FROM pay_tx WHERE status = 'PAID'")->fetch() ?: ['n' => 0, 's' => 0]]);
    }
    if ($action === 'adm_plans_save') {
        $keep = [];
        foreach ((array)($in['plans'] ?? []) as $p) {
            if (!is_array($p) || trim((string)($p['name'] ?? '')) === '') continue;
            $v = [mb_substr(trim((string)$p['name']), 0, 80), max(0, intval($p['price'] ?? 0)), max(1, min(3650, intval($p['days'] ?? 30))), max(0, intval($p['day_tokens'] ?? 0)), max(0, intval($p['week_tokens'] ?? 0)),
                  max(0, intval($p['month_tokens'] ?? 0)), max(0, intval($p['daily_msgs'] ?? 0)), !empty($p['files']) ? 1 : 0, mb_substr(trim((string)($p['badge'] ?? '')), 0, 40),
                  preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($p['color'] ?? '')) ? $p['color'] : null, mb_substr(trim((string)($p['descr'] ?? '')), 0, 300), intval($p['sort'] ?? 0), !empty($p['active']) ? 1 : 0];
            if (!empty($p['id']) && hx_plan($pdo, $p['id'])) {
                $pdo->prepare("UPDATE hx_plans SET name = ?, price = ?, days = ?, day_tokens = ?, week_tokens = ?, month_tokens = ?, daily_msgs = ?, files = ?, badge = ?, color = ?, descr = ?, sort = ?, active = ? WHERE id = ?")
                    ->execute(array_merge($v, [intval($p['id'])]));
                $keep[] = intval($p['id']);
            } else {
                $pdo->prepare("INSERT INTO hx_plans (name, price, days, day_tokens, week_tokens, month_tokens, daily_msgs, files, badge, color, descr, sort, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute($v);
                $keep[] = intval($pdo->lastInsertId());
            }
        }
        // پلنِ حذف‌شده: اگر خریده شده بود فقط غیرفعال می‌شود (سابقه می‌ماند)
        foreach (hx_plans($pdo, false) as $p) if (!in_array(intval($p['id']), $keep, true)) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM hx_subs WHERE plan_id = ?"); $q->execute([$p['id']]);
            if (intval($q->fetchColumn())) $pdo->prepare("UPDATE hx_plans SET active = 0 WHERE id = ?")->execute([$p['id']]);
            else $pdo->prepare("DELETE FROM hx_plans WHERE id = ?")->execute([$p['id']]);
        }
        hxo(['ok' => true, 'plans' => hx_plans($pdo, false)]);
    }
    if ($action === 'adm_offers_save') {
        $keep = [];
        $jg = function ($v, $end) { $v = trim(p2e_digits((string)$v)); if (!preg_match('~^(\d{4})/(\d{1,2})/(\d{1,2})$~', $v, $m)) return null; $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])); return $ts ? date('Y-m-d', $ts) . ($end ? ' 23:59:59' : ' 00:00:00') : null; };
        $md = function ($v) { $v = trim(p2e_digits((string)$v)); return preg_match('~^(\d{1,2})/(\d{1,2})$~', $v, $m) && $m[1] >= 1 && $m[1] <= 12 && $m[2] >= 1 && $m[2] <= 31 ? sprintf('%02d/%02d', $m[1], $m[2]) : null; };
        foreach ((array)($in['offers'] ?? []) as $o) {
            if (!is_array($o) || trim((string)($o['name'] ?? '')) === '') continue;
            $kind = in_array($o['kind'] ?? '', ['range', 'yearly', 'birthday'], true) ? $o['kind'] : 'range';
            $pids = implode(',', array_values(array_filter(array_map('intval', (array)($o['plan_ids'] ?? [])))));
            $v = [mb_substr(trim((string)$o['name']), 0, 80), $kind, max(0, min(100, intval($o['percent'] ?? 0))), $pids !== '' ? $pids : null,
                  $kind === 'range' ? $jg($o['start'] ?? '', false) : null, $kind === 'range' ? $jg($o['end'] ?? '', true) : null,
                  $kind === 'yearly' ? $md($o['md_from'] ?? '') : null, $kind === 'yearly' ? $md($o['md_to'] ?? '') : null,
                  max(0, min(60, intval($o['days_before'] ?? 0))), max(0, min(60, intval($o['days_after'] ?? 7))), $kind === 'birthday' && intval($o['gift_plan_id'] ?? 0) ? intval($o['gift_plan_id']) : null,
                  !empty($o['active']) ? 1 : 0, mb_substr(trim((string)($o['note'] ?? '')), 0, 300)];
            if ($kind === 'range' && (!$v[4] || !$v[5])) hxo(['ok' => false, 'error' => 'برای «' . $v[0] . '» تاریخِ شروع و پایان را درست بنویسید (مثلاً ۱۴۰۵/۰۸/۰۱).']);
            if ($kind === 'yearly' && (!$v[6] || !$v[7])) hxo(['ok' => false, 'error' => 'برای «' . $v[0] . '» ماه/روزِ شروع و پایان را بنویسید (مثلاً ۰۸/۰۱).']);
            if (!empty($o['id'])) { $pdo->prepare("UPDATE hx_offers SET name = ?, kind = ?, percent = ?, plan_ids = ?, start_at = ?, end_at = ?, md_from = ?, md_to = ?, days_before = ?, days_after = ?, gift_plan_id = ?, active = ?, note = ? WHERE id = ?")
                                         ->execute(array_merge($v, [intval($o['id'])])); $keep[] = intval($o['id']); }
            else { $pdo->prepare("INSERT INTO hx_offers (name, kind, percent, plan_ids, start_at, end_at, md_from, md_to, days_before, days_after, gift_plan_id, active, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute($v);
                   $keep[] = intval($pdo->lastInsertId()); }
        }
        $pdo->exec("DELETE FROM hx_offers" . ($keep ? " WHERE id NOT IN (" . implode(',', $keep) . ")" : ''));
        hxo(['ok' => true, 'offers' => $offersOut()]);
    }
    if ($action === 'adm_gw_save') {
        try { pay_save_gateways($pdo, (array)($in['gateways'] ?? [])); } catch (Throwable $e) { hxo(['ok' => false, 'error' => $e->getMessage()]); }
        hxo(['ok' => true, 'gateways' => pay_gateways_public($pdo)]);
    }
    // پرداخت‌ها
    if ($action === 'adm_payments') {
        pay_ensure($pdo);
        $w = []; $a = [];
        if (!empty($in['status'])) { $w[] = 't.status = ?'; $a[] = (string)$in['status']; }
        if (!empty($in['user_id'])) { $w[] = 't.user_id = ?'; $a[] = intval($in['user_id']); }
        $st = $pdo->prepare("SELECT t.id, t.user_id, u.full_name, t.gateway_type, t.amount, t.title, t.status, t.ref_no, t.card, t.error, t.created_at, t.paid_at, t.done_at
                             FROM pay_tx t LEFT JOIN users u ON u.id = t.user_id" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY t.id DESC LIMIT 300");
        $st->execute($a);
        hxo(['ok' => true, 'rows' => array_map(function ($r) use ($jd) { return ['id' => intval($r['id']), 'user' => (string)$r['full_name'], 'user_id' => intval($r['user_id']), 'gw' => PAY_TYPES[$r['gateway_type']]['fa'] ?? $r['gateway_type'],
                                         'toman' => intval($r['amount'] / 10), 'title' => $r['title'], 'status' => $r['status'], 'status_fa' => PAY_ST_FA[$r['status']] ?? $r['status'], 'ref' => (string)$r['ref_no'], 'card' => (string)$r['card'],
                                         'error' => (string)$r['error'], 'at' => $jd($r['created_at']), 'paid_at' => $jd($r['paid_at']), 'fulfilled' => !empty($r['done_at'])]; }, $st->fetchAll())]);
    }
    if ($action === 'adm_pay_fulfil') {
        $t = pay_get($pdo, $in['id'] ?? 0);
        if (!$t || $t['status'] !== 'PAID') hxo(['ok' => false, 'error' => 'فقط پرداختِ موفق.']);
        if (!empty($t['done_at'])) hxo(['ok' => true, 'msg' => 'قبلاً فعال شده بود.']);
        hxo(pay_fulfil($pdo, $t['id']) ? ['ok' => true, 'msg' => 'اشتراک فعال شد.'] : ['ok' => false, 'error' => (pay_get($pdo, $t['id'])['error'] ?? 'خطا')]);
    }
    // تاریخچه‌ی گفتگوها (همه‌ی کاربران)
    if ($action === 'adm_threads') {
        $w = ['1 = 1']; $a = [];
        $q = trim(p2e_digits((string)($in['q'] ?? '')));
        if ($q !== '') { $w[] = '(t.code LIKE ? OR t.title LIKE ? OR u.full_name LIKE ?)'; $a[] = '%' . $q . '%'; $a[] = '%' . $q . '%'; $a[] = '%' . $q . '%'; }
        if (!empty($in['user_id'])) { $w[] = 't.user_id = ?'; $a[] = intval($in['user_id']); }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $v = trim(p2e_digits((string)($in[$k] ?? '')));
            if (preg_match('~^(\d{4})/(\d{1,2})/(\d{1,2})$~', $v, $m) && ($ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])))) { $w[] = "t.updated_at $op ?"; $a[] = date('Y-m-d', $ts) . ($k === 'to' ? ' 23:59:59' : ' 00:00:00'); }
        }
        $page = max(0, intval($in['page'] ?? 0));
        $st = $pdo->prepare("SELECT t.id, t.code, t.title, t.model, t.user_id, t.is_deleted, t.created_at, t.updated_at, u.full_name,
                                    COUNT(m.id) n, COALESCE(SUM(m.tokens), 0) tok, COALESCE(SUM(COALESCE(m.billed, m.tokens)), 0) billed, COALESCE(AVG(CASE WHEN m.role = 'assistant' THEN m.ms END), 0) avg_ms
                             FROM hx_threads t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN hx_messages m ON m.thread_id = t.id
                             WHERE " . implode(' AND ', $w) . " GROUP BY t.id ORDER BY t.updated_at DESC, t.id DESC LIMIT 101 OFFSET " . ($page * 100));
        $st->execute($a);
        $rows = $st->fetchAll();
        $more = count($rows) > 100;
        $users = $pdo->query("SELECT DISTINCT u.id, u.full_name FROM hx_threads t JOIN users u ON u.id = t.user_id ORDER BY u.full_name")->fetchAll();
        hxo(['ok' => true, 'more' => $more, 'users' => array_map(function ($u) { return ['id' => intval($u['id']), 'name' => $u['full_name']]; }, $users),
             'rows' => array_map(function ($t) use ($jd) { return ['id' => intval($t['id']), 'code' => (string)$t['code'], 'title' => $t['title'], 'model' => (string)$t['model'], 'user' => (string)$t['full_name'], 'user_id' => intval($t['user_id']),
                                                         'deleted' => !empty($t['is_deleted']), 'started' => $jd($t['created_at']), 'last' => $jd($t['updated_at']), 'live' => strtotime((string)$t['updated_at']) > time() - 180,
                                                         'msgs' => intval($t['n']), 'tokens' => intval($t['tok']), 'billed' => intval($t['billed']), 'avg_ms' => intval($t['avg_ms'])]; }, array_slice($rows, 0, 100))]);
    }
    if ($action === 'adm_thread') {
        $st = $pdo->prepare("SELECT t.*, u.full_name FROM hx_threads t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
        $st->execute([intval($in['id'] ?? 0)]);
        $t = $st->fetch();
        if (!$t) hxo(['ok' => false, 'error' => 'گفتگو پیدا نشد.']);
        hxo(['ok' => true, 'thread' => ['id' => intval($t['id']), 'code' => (string)$t['code'], 'title' => $t['title'], 'model' => (string)$t['model'], 'user' => (string)$t['full_name'],
                                        'started' => $jd($t['created_at']), 'last' => $jd($t['updated_at']), 'deleted' => !empty($t['is_deleted'])], 'messages' => $threadMsgs(intval($t['id']), true)]);
    }
    // یک کاربر: وضعیتِ سهمیه و اشتراک‌ها، صفر کردنِ مصرف، فعال‌سازیِ دستیِ اشتراک، لغوِ اشتراک
    $uState = function ($id) use ($pdo, $C, $jd) {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?"); $st->execute([$id]); $role = (string)$st->fetchColumn();
        $q = $pdo->prepare("SELECT * FROM hx_subs WHERE user_id = ? ORDER BY id DESC LIMIT 30"); $q->execute([$id]);
        return ['state' => hx_user_state($pdo, $id, $role === 'ADMIN', $C), 'plans' => array_map(function ($p) { return ['id' => intval($p['id']), 'name' => $p['name'], 'days' => intval($p['days'])]; }, hx_plans($pdo, false)),
                'subs' => array_map(function ($s) use ($jd) { return ['id' => intval($s['id']), 'name' => $s['name'], 'source' => $s['source'], 'starts' => $jd($s['starts_at']), 'ends' => $jd($s['ends_at']),
                                                                    'active' => !$s['cancelled'] && strtotime($s['ends_at']) > time() && strtotime($s['starts_at']) <= time(), 'future' => !$s['cancelled'] && strtotime($s['starts_at']) > time(),
                                                                    'cancelled' => !empty($s['cancelled']), 'note' => (string)$s['note'], 'price' => intval($s['price_paid'])]; }, $q->fetchAll()),
                'birth' => hx_birth_md($pdo, $id)];
    };
    if ($action === 'adm_user_state') hxo(['ok' => true] + $uState(intval($in['user_id'] ?? 0)));
    if ($action === 'adm_user_reset') {
        $id = intval($in['user_id'] ?? 0);
        $cols = ['day' => ['reset_day_at'], 'week' => ['reset_week_at'], 'month' => ['reset_month_at'], 'all' => ['reset_day_at', 'reset_week_at', 'reset_month_at']][(string)($in['what'] ?? '')] ?? null;
        if (!$id || !$cols) hxo(['ok' => false, 'error' => 'ورودیِ نامعتبر.']);
        // ردیفِ تازه با همان دسترسیِ فعلی (تا روشن/خاموش بودنِ کاربر عوض نشود)
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?"); $st->execute([$id]); $role = (string)$st->fetchColumn();
        $cur = hx_user_state($pdo, $id, $role === 'ADMIN', $C);
        $pdo->prepare("INSERT IGNORE INTO hx_users (user_id, enabled, updated_at) VALUES (?, ?, NOW())")->execute([$id, $cur['allowed'] ? 1 : 0]);
        $pdo->prepare("UPDATE hx_users SET " . implode(', ', array_map(function ($c) { return "$c = NOW()"; }, $cols)) . ", updated_at = NOW() WHERE user_id = ?")->execute([$id]);
        hxo(['ok' => true] + $uState($id));
    }
    if ($action === 'adm_user_sub') {
        $id = intval($in['user_id'] ?? 0);
        $plan = hx_plan($pdo, $in['plan_id'] ?? 0);
        if (!$id || !$plan) hxo(['ok' => false, 'error' => 'کاربر و پلن را انتخاب کنید.']);
        $days = intval(p2e_digits((string)($in['days'] ?? ''))) ?: intval($plan['days']);
        hx_grant_sub($pdo, $id, $plan, 'admin', ['days' => max(1, min(3650, $days)), 'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 200) ?: 'فعال‌سازیِ دستی', 'by' => $uid]);
        hxo(['ok' => true] + $uState($id));
    }
    if ($action === 'adm_sub_cancel') {
        $st = $pdo->prepare("SELECT user_id FROM hx_subs WHERE id = ?"); $st->execute([intval($in['id'] ?? 0)]); $su = $st->fetchColumn();
        if (!$su) hxo(['ok' => false, 'error' => 'اشتراک پیدا نشد.']);
        $pdo->prepare("UPDATE hx_subs SET cancelled = 1, note = CONCAT(COALESCE(note, ''), ' (لغو توسطِ مدیر)') WHERE id = ?")->execute([intval($in['id'])]);
        hxo(['ok' => true] + $uState(intval($su)));
    }
    if ($action === 'adm_test') {
        if (!hx_ready($pdo, $C)) hxo(['ok' => false, 'error' => 'سرویس‌دهنده (آدرس و کلید) کامل نیست یا هوشیکس خاموش است.']);
        $e = hx_endpoint($pdo, $C);
        // فقط سیستمِ داخلی: آزمایش با اولین مدلِ آن
        if ($e['base'] === '' || $e['token'] === '') {
            require_once __DIR__ . '/_ollama.php';
            $lm = array_values(array_filter(array_keys(hx_all_models($pdo, $C)), 'hx_is_local'));
            if (!$lm) hxo(['ok' => false, 'error' => 'نه سرویسِ آنلاین تنظیم شده، نه مدلی از سیستمِ داخلی.']);
            $t0 = microtime(true);
            $r = ol_call($pdo, 'chat', substr($lm[0], 7), [['role' => 'user', 'content' => 'فقط بنویس: سلام، هوشیکس آماده است. (' . date('H:i:s') . ')']], ['num_predict' => 40], 90);
            hxo(['ok' => true, 'result' => ['ok' => !empty($r['ok']), 'ms' => intval((microtime(true) - $t0) * 1000), 'http' => 0, 'text' => !empty($r['ok']) ? $r['text'] : ($r['error'] ?? 'خطا')]]);
        }
        $t0 = microtime(true);
        $res = sms_http('POST', $e['base'] . '/chat/completions', json_encode(['model' => $C['models'][0], 'max_tokens' => 40, 'messages' => [['role' => 'user', 'content' => 'فقط بنویس: سلام، هوشیکس آماده است.']]], JSON_UNESCAPED_UNICODE),
                        ['Content-Type: application/json', 'Authorization: Bearer ' . $e['token']], 40);
        $j = json_decode((string)$res['body'], true);
        $ok = $res['http'] >= 200 && $res['http'] < 300 && !empty($j['choices']);
        hxo(['ok' => true, 'result' => ['ok' => $ok, 'ms' => intval((microtime(true) - $t0) * 1000), 'http' => $res['http'],
             'text' => $ok ? (string)($j['choices'][0]['message']['content'] ?? '') : ($res['error'] ?: mb_substr((string)($j['error']['message'] ?? $res['body']), 0, 300))]]);
    }
    hxo(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
}

// ---------------- کاربر ----------------
$UM = hx_user_models($pdo, $uid, $isAdmin, $C);   // مدل‌های مجازِ همین کاربر [id => برچسب]
if ($action === 'boot') {
    $gift = $S['allowed'] ? hx_birthday_gift($pdo, $uid) : '';
    if ($gift !== '') $S = hx_user_state($pdo, $uid, $isAdmin, $C);
    hxo(['ok' => true, 'name' => HX_NAME, 'allowed' => $S['allowed'] && $C['enabled'], 'enabled' => $C['enabled'], 'ready' => hx_ready($pdo, $C), 'is_admin' => $isAdmin,
         'me' => $S, 'models' => array_keys($UM), 'model_labels' => $UM, 'voice' => $C['voice'], 'voice_reply' => $C['voice_reply'], 'mask' => $C['mask'], 'user' => $userName,
         'shop' => $C['shop'], 'files' => $S['files'], 'file_max_mb' => $C['file_max_mb'], 'file_max_count' => $C['file_max_count'], 'file_ext' => HX_FILE_EXT,
         'gift' => $gift, 'ollama_rate' => $C['ollama_rate'], 'threads' => $S['allowed'] ? $myThreads() : []]);
}
// عکسِ پیوست (صاحبِ گفتگو یا مدیر کل)
if ($action === 'file') {
    $st = $pdo->prepare("SELECT * FROM hx_files WHERE id = ?");
    $st->execute([intval($in['id'] ?? 0)]);
    $f = $st->fetch();
    if (!$f || ($f['user_id'] != $uid && !$isAdmin) || $f['kind'] !== 'image') { http_response_code(404); exit; }
    $b = hx_file_bytes($f);
    if ($b === '') { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=86400'); header("Content-Security-Policy: sandbox; default-src 'none'");
    echo $b; exit;
}
if (!$C['enabled'] && !$isAdmin) hxo(['ok' => false, 'error' => HX_NAME . ' فعلاً خاموش است.']);
if (!$S['allowed']) hxo(['ok' => false, 'error' => 'دسترسی به ' . HX_NAME . ' برای شما فعال نیست؛ از مدیر کل بخواهید.', 'denied' => true]);

if ($action === 'threads') hxo(['ok' => true, 'threads' => $myThreads(), 'me' => $S]);
if ($action === 'thread') {
    $t = $thread($in['id'] ?? 0);
    if (!$t) hxo(['ok' => false, 'error' => 'گفتگو پیدا نشد.']);
    hxo(['ok' => true, 'thread' => ['id' => intval($t['id']), 'code' => (string)$t['code'], 'title' => $t['title'], 'model' => $t['model'], 'started' => $jd($t['created_at'])], 'messages' => $threadMsgs(intval($t['id']))]);
}
if ($action === 'rename' || $action === 'delete') {
    $t = $thread($in['id'] ?? 0);
    if (!$t) hxo(['ok' => false, 'error' => 'گفتگو پیدا نشد.']);
    if ($action === 'delete') $pdo->prepare("UPDATE hx_threads SET is_deleted = 1 WHERE id = ?")->execute([$t['id']]);
    else $pdo->prepare("UPDATE hx_threads SET title = ? WHERE id = ?")->execute([mb_substr(trim((string)($in['title'] ?? '')), 0, 190) ?: $t['title'], $t['id']]);
    hxo(['ok' => true, 'threads' => $myThreads()]);
}
if ($action === 'send') {
    if (!$UM) hxo(['ok' => false, 'error' => 'هیچ مدلی برای شما فعال نیست؛ از مدیر کل بخواهید.']);
    $model = isset($UM[$in['model'] ?? '']) ? (string)$in['model'] : array_key_first($UM);
    if (!hx_ready($pdo, $C, $model)) {
        $why = '';
        if (hx_is_local($model)) { require_once __DIR__ . '/_ollama.php'; $why = ol_why($pdo, 'chat', substr($model, 7)); }
        hxo(['ok' => false, 'error' => hx_is_local($model) ? ($why ?: 'سیستمِ داخلی (Ollama) خاموش است.') : 'سرویس‌دهنده‌ی هوشِ مصنوعی هنوز تنظیم نشده (تنظیماتِ هوشیکس).']);
    }
    $text = trim(str_replace("\r", '', (string)($in['text'] ?? '')));
    $files = array_values(array_filter((array)($in['files'] ?? []), 'is_array'));
    if ($text === '' && !$files) hxo(['ok' => false, 'error' => 'پیام خالی است.']);
    if (mb_strlen($text) > 8000) hxo(['ok' => false, 'error' => 'پیام خیلی طولانی است (حداکثر ۸۰۰۰ نویسه).']);
    if (($qb = hx_quota_block($S, $C)) !== '') hxo(['ok' => false, 'quota' => true, 'error' => $qb, 'me' => $S]);
    if ($files && !$S['files']) hxo(['ok' => false, 'error' => 'فرستادنِ فایل برای شما فعال نیست' . ($C['shop'] ? ' (با اشتراکِ «طلایی» یا از مدیر کل بخواهید).' : '؛ از مدیر کل بخواهید.')]);
    if (count($files) > $C['file_max_count']) hxo(['ok' => false, 'error' => 'حداکثر ' . $C['file_max_count'] . ' فایل در هر پیام.']);
    $t = !empty($in['thread_id']) ? $thread($in['thread_id']) : null;
    // هر گفتگو یک مدل: پیام با مدلِ دیگر = گفتگوی تازه
    if ($t && (string)$t['model'] !== '' && (string)$t['model'] !== $model) $t = null;
    @set_time_limit(200);
    sess_release();
    // فایل‌ها (بررسیِ امنیتی + استخراجِ متن)
    $fids = []; $fmeta = [];
    foreach ($files as $f) {
        $data = (string)($f['data'] ?? '');
        $bin = preg_match('~^data:[^;,]*(?:;[^,]*)?;base64,(.+)$~s', $data, $m) ? base64_decode($m[1], true) : false;
        try {
            if ($bin === false) throw new Exception('فایلِ «' . mb_substr((string)($f['name'] ?? ''), 0, 60) . '» نرسید.');
            $r = hx_file_accept($pdo, $C, $uid, (string)($f['name'] ?? ''), $bin);
            $fids[] = $r['id']; $fmeta[] = $r;
        } catch (Throwable $e) {
            hx_files_drop($pdo, $fids);
            hxo(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    if (!$t) {
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $text !== '' ? $text : ('فایل: ' . ($fmeta[0]['name'] ?? '')))), 0, 60);
        $pdo->prepare("INSERT INTO hx_threads (user_id, title, model, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())")->execute([$uid, $title, $model]);
        $tid = intval($pdo->lastInsertId());
        $pdo->prepare("UPDATE hx_threads SET code = ? WHERE id = ?")->execute(['HX-' . str_pad((string)$tid, 6, '0', STR_PAD_LEFT), $tid]);
        $t = $thread($tid);
    }
    if ($fids) $pdo->exec("UPDATE hx_files SET thread_id = " . intval($t['id']) . " WHERE id IN (" . implode(',', $fids) . ")");
    $pdo->prepare("INSERT INTO hx_messages (thread_id, user_id, role, content, files, created_at) VALUES (?, ?, 'user', ?, ?, NOW())")->execute([$t['id'], $uid, $text, $fids ? implode(',', $fids) : null]);
    $userMsgId = intval($pdo->lastInsertId());
    $r = hx_complete($pdo, $C, intval($t['id']), $model, $userName, hx_is_local($model) ? (string)$t['node'] : '');
    if (!$r['ok']) {
        // پیامِ بی‌پاسخ از سهمیه کم نشود (با فرستادنِ دوباره، فایل‌ها هم دوباره فرستاده می‌شوند)
        $pdo->prepare("DELETE FROM hx_messages WHERE id = ?")->execute([$userMsgId]);
        hx_files_drop($pdo, $fids);
        hxo(['ok' => false, 'error' => $r['error'], 'pending' => !empty($r['pending']), 'thread_id' => intval($t['id']), 'code' => (string)$t['code']]);
    }
    $pdo->prepare("INSERT INTO hx_messages (thread_id, user_id, role, content, tokens, billed, ms, model, node, created_at) VALUES (?, ?, 'assistant', ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$t['id'], $uid, $r['text'], $r['tokens'], $r['billed'], $r['ms'], mb_substr($r['model'], 0, 120), $r['node'] !== '' ? mb_substr($r['node'], 0, 16) : null]);
    $pdo->prepare("UPDATE hx_threads SET updated_at = NOW(), model = ?" . ($r['node'] !== '' && !$t['node'] ? ", node = " . $pdo->quote($r['node']) : '') . " WHERE id = ?")->execute([$model, $t['id']]);
    hxo(['ok' => true, 'thread_id' => intval($t['id']), 'code' => (string)$t['code'], 'title' => $t['title'], 'reply' => $r['text'], 'tokens' => $r['tokens'], 'billed' => $r['billed'], 'ms' => $r['ms'],
         'files' => $fmeta, 'me' => hx_user_state($pdo, $uid, $isAdmin, $C), 'threads' => $myThreads()]);
}
// ---- اشتراک: پلن‌ها با قیمتِ نهایی (تخفیفِ امروز)، درگاه‌ها، اشتراک‌ها و پرداخت‌های من ----
if ($action === 'shop') {
    if (!$C['shop']) hxo(['ok' => false, 'error' => 'خریدِ اشتراک فعلاً بسته است.']);
    $plans = [];
    foreach (hx_plans($pdo) as $p) $plans[] = ['id' => intval($p['id']), 'name' => $p['name'], 'days' => intval($p['days']), 'day_tokens' => intval($p['day_tokens']), 'week_tokens' => intval($p['week_tokens']),
                                               'month_tokens' => intval($p['month_tokens']), 'daily_msgs' => intval($p['daily_msgs']), 'files' => !empty($p['files']), 'badge' => (string)$p['badge'], 'color' => (string)$p['color'],
                                               'descr' => (string)$p['descr']] + hx_price($pdo, $p, $uid);
    $gws = [];
    foreach (pay_gateways($pdo, true) as $g) if ($g['type'] !== 'test' || $isAdmin) $gws[] = ['id' => $g['id'], 'type' => $g['type'], 'title' => $g['title'], 'icon' => PAY_TYPES[$g['type']]['icon']];
    pay_ensure($pdo);
    $q = $pdo->prepare("SELECT id, title, amount, status, ref_no, created_at FROM pay_tx WHERE user_id = ? AND kind = 'hx_sub' ORDER BY id DESC LIMIT 20");
    $q->execute([$uid]);
    $q2 = $pdo->prepare("SELECT name, source, starts_at, ends_at, cancelled FROM hx_subs WHERE user_id = ? ORDER BY id DESC LIMIT 20");
    $q2->execute([$uid]);
    $offers = array_map(function ($o) { return ['name' => $o['name'], 'percent' => intval($o['percent']), 'kind' => $o['kind']]; }, hx_offers_now($pdo, $uid));
    hxo(['ok' => true, 'plans' => $plans, 'gateways' => $gws, 'offers' => $offers, 'me' => $S, 'birth' => hx_birth_md($pdo, $uid) !== '',
         'payments' => array_map(function ($r) use ($jd) { return ['id' => intval($r['id']), 'title' => $r['title'], 'toman' => intval($r['amount'] / 10), 'status' => PAY_ST_FA[$r['status']] ?? $r['status'], 'ok' => $r['status'] === 'PAID', 'ref' => (string)$r['ref_no'], 'at' => $jd($r['created_at'])]; }, $q->fetchAll()),
         'subs' => array_map(function ($s) use ($jd) { return ['name' => $s['name'], 'source' => $s['source'], 'starts' => $jd($s['starts_at']), 'ends' => $jd($s['ends_at']), 'active' => !$s['cancelled'] && strtotime($s['ends_at']) > time()]; }, $q2->fetchAll())]);
}
if ($action === 'buy') {
    if (!$C['shop']) hxo(['ok' => false, 'error' => 'خریدِ اشتراک فعلاً بسته است.']);
    $plan = hx_plan($pdo, $in['plan_id'] ?? 0);
    if (!$plan || empty($plan['active'])) hxo(['ok' => false, 'error' => 'این پلن دیگر فروخته نمی‌شود.']);
    $pr = hx_price($pdo, $plan, $uid);
    // تخفیفِ ۱۰۰٪: بدونِ درگاه فعال می‌شود
    if ($pr['final'] <= 0) {
        hx_grant_sub($pdo, $uid, $plan, 'gift', ['offer_id' => $pr['offer']['id'] ?? null, 'note' => 'رایگان با تخفیفِ ' . ($pr['offer']['name'] ?? '')]);
        hxo(['ok' => true, 'free' => true, 'me' => hx_user_state($pdo, $uid, $isAdmin, $C)]);
    }
    $g = pay_gateways($pdo, true)[(string)($in['gateway_id'] ?? '')] ?? null;
    if (!$g || ($g['type'] === 'test' && !$isAdmin)) hxo(['ok' => false, 'error' => 'درگاهِ پرداخت را انتخاب کنید.']);
    $mobile = '';
    try { $st = $pdo->prepare("SELECT mobile_number FROM users WHERE id = ?"); $st->execute([$uid]); $mobile = pay_mobile((string)$st->fetchColumn()); } catch (Throwable $e) {}
    try {
        $id = pay_create($pdo, 'hx_sub', $uid, $g['id'], $pr['final'] * 10, 'اشتراکِ هوشیکس: ' . $plan['name'], ['plan_id' => intval($plan['id']), 'offer_id' => $pr['offer']['id'] ?? null, 'price' => $pr['price']], $mobile);
    } catch (Throwable $e) { hxo(['ok' => false, 'error' => $e->getMessage()]); }
    $r = pay_start($pdo, $id);
    if (!$r['ok']) hxo($r);
    hxo(['ok' => true, 'pay' => ['url' => $r['url'], 'method' => $r['method'], 'fields' => (object)$r['fields']]]);
}
if ($action === 'stt') {
    if (!$C['voice']) hxo(['ok' => false, 'error' => 'صدا خاموش است.']);
    if (($qb = hx_quota_block($S, $C)) !== '') hxo(['ok' => false, 'error' => $qb]);
    sess_release();
    hxo(hx_stt($pdo, $C, (string)($in['audio'] ?? '')));
}
if ($action === 'tts') {
    if (!$C['voice_reply']) hxo(['ok' => false, 'error' => 'خواندنِ پاسخ با صدا خاموش است (تنظیماتِ هوشیکس).']);
    sess_release();
    $r = hx_tts($pdo, $C, (string)($in['text'] ?? ''));
    if (!$r['ok']) hxo($r);
    header('Content-Type: audio/mpeg');
    header('Cache-Control: no-store');
    echo $r['audio'];
    exit;
}
hxo(['ok' => false, 'error' => 'عملیاتِ نامعتبر.']);
