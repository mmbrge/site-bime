<?php
// فایل: api/hooshix_actions.php
// «هوشیکس X ai» (hooshix.js): گفتگوها، فرستادنِ پیام با سهمیه، صدا (گفتار↔متن)، و تنظیماتِ مدیر کل
// (سرویس‌دهنده و مدل، دسترسی و سهمیه‌ی هر کاربر). منطق: api/_hooshix.php
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_hooshix.php';

function hxo($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$uid = intval($_SESSION['user_id'] ?? 0);
if (!$uid) { http_response_code(401); hxo(['ok' => false, 'error' => 'ابتدا وارد شوید.', 'session_expired' => true]); }
if (!auth_staff_is_active($pdo, $uid)) hxo(['ok' => false, 'error' => 'حساب غیرفعال است.']);
$isAdmin = (perm_real_role() ?: ($_SESSION['role'] ?? '')) === 'ADMIN';
$userName = (string)($_SESSION['full_name'] ?? '');
$in = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
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
    $st = $pdo->prepare("SELECT id, title, model, updated_at FROM hx_threads WHERE user_id = ? AND is_deleted = 0 ORDER BY updated_at DESC, id DESC LIMIT 100");
    $st->execute([$uid]);
    return array_map(function ($t) use ($jd) { return ['id' => intval($t['id']), 'title' => $t['title'], 'model' => $t['model'], 'at' => $jd($t['updated_at'])]; }, $st->fetchAll());
};

// ---------------- تنظیماتِ مدیر کل ----------------
if (strpos($action, 'adm_') === 0) {
    if (!$isAdmin) hxo(['ok' => false, 'error' => 'فقط مدیر کل.']);
    if ($action === 'adm_get') {
        $raw = json_decode((string)sms_get($pdo, 'hooshix_cfg', '{}'), true) ?: [];
        $c = $C; $c['token'] = ''; $c['has_token'] = trim((string)($raw['token'] ?? '')) !== ''; $c['models'] = implode(', ', $C['models']);
        $c['enabled'] = $C['enabled'] ? '1' : '0'; $c['mask'] = $C['mask'] ? '1' : '0'; $c['voice'] = $C['voice'] ? '1' : '0'; $c['voice_reply'] = $C['voice_reply'] ? '1' : '0';
        hxo(['ok' => true, 'cfg' => $c, 'providers' => HX_PROVIDERS, 'voices' => HX_VOICES, 'ready' => hx_ready($pdo, $C)]);
    }
    if ($action === 'adm_save') {
        $raw = json_decode((string)sms_get($pdo, 'hooshix_cfg', '{}'), true) ?: [];
        $n = (array)($in['cfg'] ?? []);
        $keys = ['enabled', 'provider', 'base_url', 'models', 'temperature', 'max_reply', 'history', 'system', 'access', 'def_daily', 'def_month_tokens', 'mask', 'voice', 'voice_reply', 'stt_model', 'tts_model', 'tts_voice', 'timeout'];
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
    if ($action === 'adm_test') {
        if (!hx_ready($pdo, $C)) hxo(['ok' => false, 'error' => 'سرویس‌دهنده (آدرس و کلید) کامل نیست یا هوشیکس خاموش است.']);
        $e = hx_endpoint($pdo, $C);
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
if ($action === 'boot') {
    hxo(['ok' => true, 'name' => HX_NAME, 'allowed' => $S['allowed'] && $C['enabled'], 'enabled' => $C['enabled'], 'ready' => hx_ready($pdo, $C), 'is_admin' => $isAdmin,
         'me' => $S, 'models' => $C['models'], 'voice' => $C['voice'], 'voice_reply' => $C['voice_reply'], 'mask' => $C['mask'], 'user' => $userName, 'threads' => $S['allowed'] ? $myThreads() : []]);
}
if (!$C['enabled'] && !$isAdmin) hxo(['ok' => false, 'error' => HX_NAME . ' فعلاً خاموش است.']);
if (!$S['allowed']) hxo(['ok' => false, 'error' => 'دسترسی به ' . HX_NAME . ' برای شما فعال نیست؛ از مدیر کل بخواهید.', 'denied' => true]);

if ($action === 'threads') hxo(['ok' => true, 'threads' => $myThreads(), 'me' => $S]);
if ($action === 'thread') {
    $t = $thread($in['id'] ?? 0);
    if (!$t) hxo(['ok' => false, 'error' => 'گفتگو پیدا نشد.']);
    $st = $pdo->prepare("SELECT id, role, content, tokens, ms, model, created_at FROM hx_messages WHERE thread_id = ? ORDER BY id");
    $st->execute([$t['id']]);
    hxo(['ok' => true, 'thread' => ['id' => intval($t['id']), 'title' => $t['title'], 'model' => $t['model']],
         'messages' => array_map(function ($m) use ($jd) { return ['id' => intval($m['id']), 'role' => $m['role'], 'text' => $m['content'], 'tokens' => intval($m['tokens']), 'ms' => intval($m['ms']), 'at' => $jd($m['created_at'])]; }, $st->fetchAll())]);
}
if ($action === 'rename' || $action === 'delete') {
    $t = $thread($in['id'] ?? 0);
    if (!$t) hxo(['ok' => false, 'error' => 'گفتگو پیدا نشد.']);
    if ($action === 'delete') $pdo->prepare("UPDATE hx_threads SET is_deleted = 1 WHERE id = ?")->execute([$t['id']]);
    else $pdo->prepare("UPDATE hx_threads SET title = ? WHERE id = ?")->execute([mb_substr(trim((string)($in['title'] ?? '')), 0, 190) ?: $t['title'], $t['id']]);
    hxo(['ok' => true, 'threads' => $myThreads()]);
}
if ($action === 'send') {
    if (!hx_ready($pdo, $C)) hxo(['ok' => false, 'error' => 'سرویس‌دهنده‌ی هوشِ مصنوعی هنوز تنظیم نشده (تنظیماتِ هوشیکس).']);
    $text = trim(str_replace("\r", '', (string)($in['text'] ?? '')));
    if ($text === '') hxo(['ok' => false, 'error' => 'پیام خالی است.']);
    if (mb_strlen($text) > 8000) hxo(['ok' => false, 'error' => 'پیام خیلی طولانی است (حداکثر ۸۰۰۰ نویسه).']);
    if ($S['daily'] && $S['used_today'] >= $S['daily']) hxo(['ok' => false, 'quota' => 'daily', 'error' => 'سهمیه‌ی پیامِ امروزِ شما تمام شد (' . $S['daily'] . ' پیام). فردا دوباره می‌توانید.', 'me' => $S]);
    if ($S['month_tokens'] && $S['used_month'] >= $S['month_tokens']) hxo(['ok' => false, 'quota' => 'month', 'error' => 'سهمیه‌ی توکنِ این ماهِ شما تمام شد؛ از مدیر کل بخواهید افزایشش دهد.', 'me' => $S]);
    $model = in_array($in['model'] ?? '', $C['models'], true) ? $in['model'] : $C['models'][0];
    $t = !empty($in['thread_id']) ? $thread($in['thread_id']) : null;
    if (!$t) {
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, 60);
        $pdo->prepare("INSERT INTO hx_threads (user_id, title, model, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())")->execute([$uid, $title, $model]);
        $t = $thread($pdo->lastInsertId());
    }
    @set_time_limit(200);
    sess_release();
    $pdo->prepare("INSERT INTO hx_messages (thread_id, user_id, role, content, created_at) VALUES (?, ?, 'user', ?, NOW())")->execute([$t['id'], $uid, $text]);
    $userMsgId = intval($pdo->lastInsertId());
    $r = hx_complete($pdo, $C, intval($t['id']), $model, $userName);
    if (!$r['ok']) {
        // پیامِ بی‌پاسخ از سهمیه کم نشود
        $pdo->prepare("DELETE FROM hx_messages WHERE id = ?")->execute([$userMsgId]);
        hxo(['ok' => false, 'error' => $r['error'], 'thread_id' => intval($t['id'])]);
    }
    $pdo->prepare("INSERT INTO hx_messages (thread_id, user_id, role, content, tokens, ms, model, created_at) VALUES (?, ?, 'assistant', ?, ?, ?, ?, NOW())")
        ->execute([$t['id'], $uid, $r['text'], $r['tokens'], $r['ms'], mb_substr($r['model'], 0, 120)]);
    $pdo->prepare("UPDATE hx_threads SET updated_at = NOW(), model = ? WHERE id = ?")->execute([$model, $t['id']]);
    hxo(['ok' => true, 'thread_id' => intval($t['id']), 'title' => $t['title'], 'reply' => $r['text'], 'tokens' => $r['tokens'], 'ms' => $r['ms'],
         'me' => hx_user_state($pdo, $uid, $isAdmin, $C), 'threads' => $myThreads()]);
}
if ($action === 'stt') {
    if (!$C['voice']) hxo(['ok' => false, 'error' => 'صدا خاموش است.']);
    if ($S['daily'] && $S['used_today'] >= $S['daily']) hxo(['ok' => false, 'error' => 'سهمیه‌ی امروزِ شما تمام شد.']);
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
