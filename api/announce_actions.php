<?php
// فایل: api/announce_actions.php
// «اعلان‌های پاپ‌آپ»: مدیر (یا کسی که دسترسیِ «اعلان‌های پاپ‌آپ» دارد) با طرح‌های آماده (اطلاع‌رسانی، هشدار، اخطار، قوانین، تبریک،
// خبر، به‌روزرسانیِ سامانه، تعطیلی، تولد) برای همه یا گروه/افرادِ خاص اعلان می‌فرستد؛ اعلان در پنلِ کاربران و پنلِ شرکت‌ها
// به‌صورتِ پاپ‌آپ نشان داده می‌شود. «خواندم» / «می‌پذیرم» ثبت می‌شود. تولدِ همکاران هم خودکار اعلان می‌شود.
// پنلِ شرکت‌ها: ?portal=1. جدول‌ها خودکار ساخته می‌شوند.
if (($_GET['portal'] ?? '') === '1') session_name('bime_company_portal');
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php';
require_once __DIR__ . '/_comfort.php';

function ano($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$A = cf_actor();
if (!$A) { http_response_code(401); ano(['ok' => false, 'error' => 'ابتدا وارد شوید.', 'session_expired' => true]); }
$raw = json_decode(file_get_contents('php://input'), true) ?: [];
$data = $raw + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
[$AT, $AID] = [$A[0], $A[1]];
$role = $A[3];

const ANN_TEMPLATES = ['info', 'warning', 'danger', 'rules', 'celebrate', 'news', 'maintenance', 'holiday', 'birthday'];
const ANN_ROLES = ['ADMIN' => 'مدیر کل', 'OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی', 'COMPANY_LIAISON' => 'کارمند بیمه با ما', 'PARSIAN' => 'کارمند پارسیان'];
const ANN_BDAY_DEFAULTS = ['ann_bday_self' => '1', 'ann_bday_all' => '1',
    'ann_bday_self_title' => 'تولدت مبارک {name}! 🎂', 'ann_bday_self_body' => "امروز روزِ توست!\nاز طرفِ همه‌ی ما در «بیمه با ما» صمیمانه تولدت را تبریک می‌گوییم.\nسالی پر از سلامتی، شادی و موفقیت برایت آرزو می‌کنیم. 🎉",
    'ann_bday_all_title' => 'امروز تولدِ {name} است 🎉', 'ann_bday_all_body' => "به {name} تبریک بگوییم و روزش را شیرین کنیم!\nاز بخشِ گفتگوها برایش پیامِ تبریک بفرستید. 🎈"];

function ann_ensure($pdo) {
    static $d = false;
    if ($d) return;
    $d = true;
    cf_ensure($pdo);
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec("CREATE TABLE IF NOT EXISTS ann_items (
        id INT AUTO_INCREMENT PRIMARY KEY, template VARCHAR(20) NOT NULL, title VARCHAR(200) NOT NULL, body TEXT NULL, icon VARCHAR(16) NULL,
        button_text VARCHAR(60) NULL, require_ack TINYINT(1) NOT NULL DEFAULT 0, audience TEXT NULL, start_at DATETIME NULL, end_at DATETIME NULL,
        active TINYINT(1) NOT NULL DEFAULT 1, auto_key VARCHAR(80) NULL, created_by INT NULL, created_by_name VARCHAR(120) NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
        UNIQUE KEY uq_auto (auto_key), KEY idx_active (active, start_at))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ann_reads (ann_id INT NOT NULL, actor_type CHAR(1) NOT NULL, actor_id INT NOT NULL, seen_at DATETIME NULL, ack_at DATETIME NULL,
        PRIMARY KEY (ann_id, actor_type, actor_id))$T");
}
function ann_setting($pdo, $k) { $v = cf_setting($pdo, $k, null); return $v === null ? (ANN_BDAY_DEFAULTS[$k] ?? '') : $v; }
// «۱۴۰۵/۰۷/۱۴ ۰۹:۳۰» → «2026-10-06 09:30:00»
function ann_dt($s, $endOfDay = false) {
    $s = trim(p2e_digits((string)$s));
    if ($s === '') return null;
    if (!preg_match('/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})(?:\s+(\d{1,2}):(\d{2}))?$/', $s, $m)) return false;
    $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]));
    if (!$ts) return false;
    $t = isset($m[4]) && $m[4] !== '' ? sprintf('%02d:%02d:00', $m[4], $m[5]) : ($endOfDay ? '23:59:59' : '00:00:00');
    return date('Y-m-d', $ts) . ' ' . $t;
}
function ann_jdt($g) {
    if (!$g) return '';
    $ts = strtotime($g);
    [$y, $m, $d] = jalali_from_gregorian_ts($ts);
    return sprintf('%04d/%02d/%02d %s', $y, $m, $d, date('H:i', $ts));
}
// آیا این اعلان برای این کاربر است؟
function ann_targets($aud, $type, $id, $role) {
    $a = is_array($aud) ? $aud : (json_decode((string)$aud, true) ?: ['type' => 'all']);
    $key = $type . ':' . $id;
    if (in_array($key, (array)($a['exclude'] ?? []), true)) return false;
    switch ($a['type'] ?? 'all') {
        case 'all': return true;
        case 'staff': return $type === 'S';
        case 'company': return $type === 'C';
        case 'roles': return $type === 'S' && in_array($role, (array)($a['roles'] ?? []), true);
        case 'users': return in_array($key, (array)($a['users'] ?? []), true);
    }
    return false;
}
// همه‌ی گیرندگان (برای آمار)
function ann_people($pdo) {
    static $p = null;
    if ($p !== null) return $p;
    $p = [];
    $del = cf_col_exists($pdo, 'users', 'is_deleted') ? " WHERE COALESCE(is_deleted, 0) = 0" : '';
    foreach ($pdo->query("SELECT id, full_name, role FROM users$del ORDER BY full_name")->fetchAll() as $u) $p[] = ['t' => 'S', 'id' => intval($u['id']), 'name' => $u['full_name'], 'role' => $u['role']];
    try {
        foreach ($pdo->query("SELECT id, full_name FROM company_portal_users WHERE COALESCE(is_deleted, 0) = 0 AND COALESCE(is_active, 1) = 1 ORDER BY full_name")->fetchAll() as $u)
            $p[] = ['t' => 'C', 'id' => intval($u['id']), 'name' => $u['full_name'], 'role' => 'COMPANY'];
    } catch (Throwable $e) {}
    return $p;
}
function ann_out($r, $withAud = false) {
    $o = ['id' => intval($r['id']), 'template' => $r['template'], 'title' => $r['title'], 'body' => (string)$r['body'], 'icon' => (string)$r['icon'],
          'button' => (string)$r['button_text'], 'require_ack' => (bool)$r['require_ack'], 'from' => (string)$r['created_by_name'], 'at' => ann_jdt($r['start_at'] ?: $r['created_at'])];
    if ($withAud) $o += ['audience' => json_decode((string)$r['audience'], true) ?: ['type' => 'all'], 'start' => $r['start_at'] ? ann_jdt($r['start_at']) : '', 'end' => $r['end_at'] ? ann_jdt($r['end_at']) : '',
                         'active' => (bool)$r['active'], 'auto' => $r['auto_key'] !== null, 'created' => ann_jdt($r['created_at'])];
    return $o;
}
// اعلانِ خودکارِ تولد (روزی یک بار، با کلیدِ یکتا تا تکراری ساخته نشود)
function ann_birthdays($pdo) {
    $today = date('Y-m-d');
    if (cf_setting($pdo, 'ann_bday_done', '') === $today) return;
    cf_setting_set($pdo, 'ann_bday_done', $today);
    [, $m, $d] = jalali_from_gregorian_ts(time());
    $md = sprintf('%02d/%02d', $m, $d);
    $rows = $pdo->query("SELECT p.actor_id, p.prefs, u.full_name FROM cf_prefs p JOIN users u ON u.id = p.actor_id WHERE p.actor_type = 'S'" . (cf_col_exists($pdo, 'users', 'is_deleted') ? " AND COALESCE(u.is_deleted, 0) = 0" : ''))->fetchAll();
    $ins = $pdo->prepare("INSERT IGNORE INTO ann_items (template, title, body, icon, button_text, require_ack, audience, start_at, end_at, active, auto_key, created_by_name, created_at)
                          VALUES ('birthday', ?, ?, '🎂', ?, 0, ?, NOW(), ?, 1, ?, 'بیمه با ما', NOW())");
    foreach ($rows as $r) {
        $p = json_decode((string)$r['prefs'], true) ?: [];
        if (empty($p['birth']['md']) || $p['birth']['md'] !== $md) continue;
        $uid = intval($r['actor_id']); $name = $r['full_name'];
        $fill = function ($s) use ($name) { return str_replace('{name}', $name, $s); };
        if (ann_setting($pdo, 'ann_bday_self') === '1')
            $ins->execute([$fill(ann_setting($pdo, 'ann_bday_self_title')), $fill(ann_setting($pdo, 'ann_bday_self_body')), 'ممنونم 🥳',
                           json_encode(['type' => 'users', 'users' => ['S:' . $uid]]), $today . ' 23:59:59', 'bday:self:' . $today . ':' . $uid]);
        if (ann_setting($pdo, 'ann_bday_all') === '1' && !empty($p['birth']['show']))
            $ins->execute([$fill(ann_setting($pdo, 'ann_bday_all_title')), $fill(ann_setting($pdo, 'ann_bday_all_body')), 'تبریک می‌گویم 🎉',
                           json_encode(['type' => 'staff', 'exclude' => ['S:' . $uid], 'birthday_of' => $uid]), $today . ' 23:59:59', 'bday:all:' . $today . ':' . $uid]);
    }
}

ann_ensure($pdo);
// مدیرِ اعلان‌ها: مدیر کل، یا کاربرِ پنل با دسترسیِ سفارشیِ «اعلان‌های پاپ‌آپ»
$custom = ($AT === 'S' && $role !== 'ADMIN') ? perm_user_custom($pdo, $AID) : null;
$can = function ($op) use ($AT, $role, $custom) { return $AT === 'S' && ($role === 'ADMIN' || ($custom && in_array($op, $custom['announcements'] ?? [], true))); };

try {
    // ================= گیرنده =================
    if ($action === 'pending') {
        ann_birthdays($pdo);
        $st = $pdo->query("SELECT * FROM ann_items WHERE active = 1 AND (start_at IS NULL OR start_at <= NOW()) AND (end_at IS NULL OR end_at >= NOW()) ORDER BY COALESCE(start_at, created_at) DESC LIMIT 60");
        $items = array_values(array_filter($st->fetchAll(), function ($r) use ($AT, $AID, $role) { return ann_targets($r['audience'], $AT, $AID, $role); }));
        $out = [];
        if ($items) {
            $ids = implode(',', array_map(function ($r) { return intval($r['id']); }, $items));
            $rd = $pdo->prepare("SELECT ann_id, seen_at, ack_at FROM ann_reads WHERE actor_type = ? AND actor_id = ? AND ann_id IN ($ids)");
            $rd->execute([$AT, $AID]);
            $reads = [];
            foreach ($rd->fetchAll() as $x) $reads[intval($x['ann_id'])] = $x;
            foreach (array_reverse($items) as $r) {
                $x = $reads[intval($r['id'])] ?? null;
                if ($x && (!$r['require_ack'] || $x['ack_at'])) continue;
                $out[] = ann_out($r);
            }
        }
        ano(['ok' => true, 'items' => $out]);
    }
    if ($action === 'seen' || $action === 'ack') {
        $id = intval($data['id'] ?? 0);
        $pdo->prepare("INSERT INTO ann_reads (ann_id, actor_type, actor_id, seen_at, ack_at) VALUES (?, ?, ?, NOW(), " . ($action === 'ack' ? 'NOW()' : 'NULL') . ")
                       ON DUPLICATE KEY UPDATE seen_at = COALESCE(seen_at, NOW())" . ($action === 'ack' ? ', ack_at = COALESCE(ack_at, NOW())' : ''))->execute([$id, $AT, $AID]);
        ano(['ok' => true]);
    }
    // اعلان‌هایی که قبلاً دیده‌ام (برای دیدنِ دوباره)
    if ($action === 'mine') {
        $st = $pdo->query("SELECT * FROM ann_items WHERE (start_at IS NULL OR start_at <= NOW()) AND created_at >= NOW() - INTERVAL 60 DAY ORDER BY COALESCE(start_at, created_at) DESC LIMIT 100");
        ano(['ok' => true, 'items' => array_values(array_map('ann_out', array_filter($st->fetchAll(), function ($r) use ($AT, $AID, $role) { return ann_targets($r['audience'], $AT, $AID, $role); })))]);
    }

    // ================= مدیرِ اعلان‌ها =================
    if (!$can('view')) ano(['ok' => false, 'error' => 'به «اعلان‌های پاپ‌آپ» دسترسی ندارید.']);
    if ($action === 'list') {
        $people = ann_people($pdo);
        $rows = $pdo->query("SELECT * FROM ann_items ORDER BY id DESC LIMIT 300")->fetchAll();
        $cnt = [];
        foreach ($pdo->query("SELECT ann_id, COUNT(*) seen, SUM(ack_at IS NOT NULL) ack FROM ann_reads GROUP BY ann_id")->fetchAll() as $c) $cnt[intval($c['ann_id'])] = $c;
        $now = date('Y-m-d H:i:s');
        $out = [];
        foreach ($rows as $r) {
            $targets = 0;
            foreach ($people as $p) if (ann_targets($r['audience'], $p['t'], $p['id'], $p['role'])) $targets++;
            $state = !$r['active'] ? 'off' : ($r['start_at'] && $r['start_at'] > $now ? 'scheduled' : ($r['end_at'] && $r['end_at'] < $now ? 'ended' : 'live'));
            $out[] = ann_out($r, true) + ['targets' => $targets, 'seen' => intval($cnt[intval($r['id'])]['seen'] ?? 0), 'acked' => intval($cnt[intval($r['id'])]['ack'] ?? 0), 'state' => $state];
        }
        ano(['ok' => true, 'items' => $out, 'roles' => ANN_ROLES, 'people' => $people, 'can' => ['create' => $can('create'), 'edit' => $can('edit'), 'delete' => $can('delete')]]);
    }
    if ($action === 'reads') {
        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM ann_items WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) ano(['ok' => false, 'error' => 'پیدا نشد.']);
        $rd = $pdo->prepare("SELECT * FROM ann_reads WHERE ann_id = ?");
        $rd->execute([$id]);
        $map = [];
        foreach ($rd->fetchAll() as $x) $map[$x['actor_type'] . ':' . $x['actor_id']] = $x;
        $rows = [];
        foreach (ann_people($pdo) as $p) {
            if (!ann_targets($r['audience'], $p['t'], $p['id'], $p['role'])) continue;
            $x = $map[$p['t'] . ':' . $p['id']] ?? null;
            $rows[] = ['name' => $p['name'], 'kind' => $p['t'] === 'C' ? 'شرکت' : (ANN_ROLES[$p['role']] ?? $p['role']), 'seen' => $x ? ann_jdt($x['seen_at']) : '', 'ack' => $x && $x['ack_at'] ? ann_jdt($x['ack_at']) : ''];
        }
        usort($rows, function ($a, $b) { return strcmp($b['seen'], $a['seen']); });
        ano(['ok' => true, 'rows' => $rows, 'require_ack' => (bool)$r['require_ack']]);
    }
    if ($action === 'save') {
        $id = intval($data['id'] ?? 0);
        if (!$can($id ? 'edit' : 'create')) ano(['ok' => false, 'error' => 'اجازه‌ی این کار را ندارید.']);
        $tpl = in_array($data['template'] ?? '', ANN_TEMPLATES, true) ? $data['template'] : 'info';
        $title = trim(mb_substr((string)($data['title'] ?? ''), 0, 200));
        if ($title === '') ano(['ok' => false, 'error' => 'عنوانِ اعلان را بنویسید.']);
        $body = trim(mb_substr((string)($data['body'] ?? ''), 0, 6000));
        $aud = (array)($data['audience'] ?? []);
        $type = in_array($aud['type'] ?? '', ['all', 'staff', 'company', 'roles', 'users'], true) ? $aud['type'] : 'all';
        $audJ = ['type' => $type];
        if ($type === 'roles') { $audJ['roles'] = array_values(array_intersect(array_keys(ANN_ROLES), (array)($aud['roles'] ?? []))); if (!$audJ['roles']) ano(['ok' => false, 'error' => 'دست‌کم یک نقش انتخاب کنید.']); }
        if ($type === 'users') { $audJ['users'] = array_values(array_unique(array_filter((array)($aud['users'] ?? []), function ($k) { return preg_match('/^[SC]:\d+$/', (string)$k); }))); if (!$audJ['users']) ano(['ok' => false, 'error' => 'دست‌کم یک نفر انتخاب کنید.']); }
        $start = ann_dt($data['start'] ?? ''); $end = ann_dt($data['end'] ?? '', true);
        if ($start === false || $end === false) ano(['ok' => false, 'error' => 'تاریخ را مثلِ «۱۴۰۵/۰۷/۱۴ ۰۹:۳۰» وارد کنید.']);
        if ($start && $end && $end <= $start) ano(['ok' => false, 'error' => 'پایانِ نمایش باید بعد از شروع باشد.']);
        $vals = [$tpl, $title, $body, mb_substr(trim((string)($data['icon'] ?? '')), 0, 16) ?: null, mb_substr(trim((string)($data['button'] ?? '')), 0, 60) ?: null,
                 !empty($data['require_ack']) ? 1 : 0, json_encode($audJ, JSON_UNESCAPED_UNICODE), $start ?: date('Y-m-d H:i:s'), $end ?: null];
        if ($id) {
            $pdo->prepare("UPDATE ann_items SET template=?, title=?, body=?, icon=?, button_text=?, require_ack=?, audience=?, start_at=?, end_at=?, updated_at=NOW() WHERE id=?")->execute([...$vals, $id]);
            // «ارسالِ دوباره»: همه دوباره می‌بینند
            if (!empty($data['resend'])) $pdo->prepare("DELETE FROM ann_reads WHERE ann_id = ?")->execute([$id]);
        } else {
            $pdo->prepare("INSERT INTO ann_items (template, title, body, icon, button_text, require_ack, audience, start_at, end_at, active, created_by, created_by_name, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW())")->execute([...$vals, $AID, $A[2] ?: 'مدیر']);
            $id = intval($pdo->lastInsertId());
        }
        ano(['ok' => true, 'id' => $id]);
    }
    if ($action === 'toggle' || $action === 'resend') {
        if (!$can('edit')) ano(['ok' => false, 'error' => 'اجازه‌ی این کار را ندارید.']);
        $id = intval($data['id'] ?? 0);
        if ($action === 'toggle') $pdo->prepare("UPDATE ann_items SET active = ? WHERE id = ?")->execute([!empty($data['active']) ? 1 : 0, $id]);
        else { $pdo->prepare("DELETE FROM ann_reads WHERE ann_id = ?")->execute([$id]); $pdo->prepare("UPDATE ann_items SET active = 1, start_at = LEAST(COALESCE(start_at, NOW()), NOW()), end_at = IF(end_at < NOW(), NULL, end_at) WHERE id = ?")->execute([$id]); }
        ano(['ok' => true]);
    }
    if ($action === 'delete') {
        if (!$can('delete')) ano(['ok' => false, 'error' => 'اجازه‌ی حذف ندارید.']);
        $id = intval($data['id'] ?? 0);
        $pdo->prepare("DELETE FROM ann_reads WHERE ann_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ann_items WHERE id = ?")->execute([$id]);
        ano(['ok' => true]);
    }
    // ---- تولدها: تنظیماتِ اعلانِ خودکار و تاریخِ تولدِ همکاران ----
    if ($action === 'bday_get') {
        $s = [];
        foreach (array_keys(ANN_BDAY_DEFAULTS) as $k) $s[$k] = ann_setting($pdo, $k);
        $rows = [];
        $prefs = [];
        foreach ($pdo->query("SELECT actor_id, prefs FROM cf_prefs WHERE actor_type = 'S'")->fetchAll() as $p) $prefs[intval($p['actor_id'])] = json_decode((string)$p['prefs'], true) ?: [];
        [, $tm, $td] = jalali_from_gregorian_ts(time());
        foreach (ann_people($pdo) as $p) {
            if ($p['t'] !== 'S') continue;
            $b = $prefs[$p['id']]['birth'] ?? null;
            $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'role' => ANN_ROLES[$p['role']] ?? $p['role'], 'md' => $b['md'] ?? '', 'show' => !empty($b['show'])];
        }
        ano(['ok' => true, 'settings' => $s, 'users' => $rows, 'today_md' => sprintf('%02d/%02d', $tm, $td)]);
    }
    if ($action === 'bday_save') {
        if (!$can('edit')) ano(['ok' => false, 'error' => 'اجازه‌ی این کار را ندارید.']);
        foreach (array_keys(ANN_BDAY_DEFAULTS) as $k) {
            if (!array_key_exists($k, (array)($data['settings'] ?? []))) continue;
            $v = $data['settings'][$k];
            cf_setting_set($pdo, $k, in_array($k, ['ann_bday_self', 'ann_bday_all'], true) ? (!empty($v) ? '1' : '0') : mb_substr(trim((string)$v), 0, 2000));
        }
        foreach ((array)($data['users'] ?? []) as $u) {
            $md = trim(p2e_digits((string)($u['md'] ?? '')));
            cf_prefs_save($pdo, 'S', intval($u['id'] ?? 0), ['birth' => $md === '' ? [] : ['md' => $md, 'show' => !empty($u['show'])]]);
        }
        // اگر تولدِ امروز تازه وارد شد، همین امروز هم اعلان ساخته شود
        cf_setting_set($pdo, 'ann_bday_done', '');
        ano(['ok' => true]);
    }
    ano(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[announce_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    ano(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
