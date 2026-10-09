<?php
// فایل: api/_chat_profile.php
// «پروفایل» در پیام‌رسان: درباره‌ی من، سمت، تخصصِ بیمه‌ای، شماره‌ی داخلی، شماره‌ی تماس و ساعتِ پاسخگویی برای کاربرانِ پنل (users).
// خودِ کاربر از پیام‌رسان ویرایش می‌کند و مدیر کل هم از «کاربران» (فرمِ تعریفِ کاربر) یا از همان پنجره‌ی پروفایل.
// نام کاربری/شناسه هیچ‌جا نشان داده نمی‌شود. برای کارکنان (وب‌اپ) و شرکت‌ها فقط کارتِ ساده (نام، نوع، حضور) دیده می‌شود.

const CPROF_FIELDS = [
    'title'     => ['prof_title', 120, 'سمت'],
    'specialty' => ['prof_specialty', 255, 'تخصصِ بیمه‌ای'],
    'ext'       => ['prof_ext', 20, 'شماره‌ی داخلی'],
    'phone'     => ['prof_phone', 30, 'شماره‌ی تماس'],
    'hours'     => ['prof_hours', 120, 'ساعتِ پاسخگویی'],
    'bio'       => ['prof_bio', 500, 'درباره‌ی من'],
];

function cprof_ensure($pdo) {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $have = [];
        foreach ($pdo->query("SHOW COLUMNS FROM users") as $c) $have[$c['Field']] = true;
        foreach (CPROF_FIELDS as [$col, $len]) if (!isset($have[$col])) $pdo->exec("ALTER TABLE users ADD COLUMN `$col` VARCHAR($len) NULL");
        $done = true;
    } catch (Throwable $e) { error_log('[cprof_ensure] ' . $e->getMessage()); $done = false; }
    return $done;
}

function cprof_get($pdo, $userId) {
    $out = array_fill_keys(array_keys(CPROF_FIELDS), '');
    if (!cprof_ensure($pdo)) return $out;
    $cols = implode(', ', array_map(function ($f) { return '`' . $f[0] . '`'; }, CPROF_FIELDS));
    $st = $pdo->prepare("SELECT $cols FROM users WHERE id = ?");
    $st->execute([intval($userId)]);
    $r = $st->fetch();
    if ($r) foreach (CPROF_FIELDS as $k => [$col]) $out[$k] = (string)($r[$col] ?? '');
    return $out;
}

// ورودیِ فرم => ذخیره (فقط فیلدهایی که فرستاده شده‌اند)
function cprof_save($pdo, $userId, array $in) {
    if (!cprof_ensure($pdo)) return ['ok' => false, 'error' => 'ستون‌های پروفایل ساخته نشد.'];
    $set = []; $args = [];
    foreach (CPROF_FIELDS as $k => [$col, $len, $label]) {
        if (!array_key_exists($k, $in)) continue;
        $v = trim(preg_replace('/[ \t]+/u', ' ', str_replace(["\r", "\0"], '', (string)$in[$k])));
        if ($k !== 'bio') $v = str_replace("\n", ' ', $v);
        if (in_array($k, ['ext', 'phone'], true)) {
            $v = p2e_digits($v);
            if ($v !== '' && !preg_match('/^[\d\s\-+()،,\/]{2,30}$/u', $v)) return ['ok' => false, 'error' => '«' . $label . '» فقط عدد باشد.'];
        }
        if (mb_strlen($v) > $len) return ['ok' => false, 'error' => '«' . $label . '» حداکثر ' . $len . ' حرف است.'];
        $set[] = "`$col` = ?"; $args[] = $v === '' ? null : $v;
    }
    if (!$set) return ['ok' => true];
    $args[] = intval($userId);
    $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
    return ['ok' => true];
}

// کارتِ پروفایل برای پیام‌رسان: $key = 'S:5' | 'P:12' | 'C:3' | 'T:..' | '' (خودم)
function cprof_card($pdo, array $actor, $key) {
    $key = (string)$key;
    $me = $actor['kind'] === 'STAFF' ? intval($actor['id']) : 0;
    $isAdmin = $actor['kind'] === 'STAFF' && ($actor['role'] ?? '') === 'ADMIN';
    if ($key === '' || $key === 'me') {
        if ($actor['kind'] !== 'STAFF') return ['ok' => false, 'error' => 'پروفایل فقط برای کاربرانِ پنل است.'];
        $key = 'S:' . $me;
    }
    [$type, $id] = array_pad(explode(':', $key, 2), 2, '');
    $id = intval($id);
    if ($type === 'S') {
        // کاربرانِ بیرونی (کارکنان/شرکت) فقط «پشتیبانی» را می‌بینند، نه پروفایلِ تک‌تکِ همکاران
        if ($actor['kind'] !== 'STAFF') return ['ok' => false, 'error' => 'دسترسی ندارید.'];
        $st = $pdo->prepare("SELECT id, full_name, role, created_at, COALESCE(is_deleted, 0) AS is_deleted, " . prof_cols($pdo, 'users') . " FROM users WHERE id = ?");
        try { $st->execute([$id]); $u = $st->fetch(); }
        catch (Throwable $e) { $st = $pdo->prepare("SELECT id, full_name, role, created_at, 0 AS is_deleted, " . prof_cols($pdo, 'users') . " FROM users WHERE id = ?"); $st->execute([$id]); $u = $st->fetch(); }
        if (!$u) return ['ok' => false, 'error' => 'کاربر پیدا نشد.'];
        $p = cprof_get($pdo, $id);
        $spec = array_values(array_filter(array_map('trim', preg_split('/[،,\n]+/u', $p['specialty'])), 'strlen'));
        require_once __DIR__ . '/_name_honor.php';
        return ['ok' => true, 'kind' => 'STAFF', 'key' => 'S:' . $id, 'name' => honor_name($pdo, 'S', $id, $u['full_name']), 'avatar' => $u['avatar'] ?? null,
                'role_fa' => function_exists('chat_role_fa') ? chat_role_fa($u['role']) : $u['role'], 'presence' => prof_presence($u),
                'since' => $u['created_at'] ? str_replace('.', '/', jalali_from_gregorian_ts_dotted(strtotime($u['created_at']))) : '',
                'deleted' => !empty($u['is_deleted']), 'profile' => $p, 'specialties' => $spec,
                'me' => $id === $me, 'editable' => $id === $me || $isAdmin, 'labels' => array_map(function ($f) { return $f[2]; }, CPROF_FIELDS),
                'can_chat' => $id !== $me];
    }
    // کارکنان (وب‌اپ) و شرکت‌ها: کارتِ ساده با اطلاعاتی که همکار برای کارش لازم دارد
    if (!function_exists('chat_can') || !chat_can($pdo, $actor, $type, $id)) return ['ok' => false, 'error' => 'دسترسی ندارید.'];
    $head = chat_head($pdo, $actor, $type, $id);
    $rows = [];
    if ($type === 'P' && $actor['kind'] === 'STAFF') {
        $st = $pdo->prepare("SELECT p.full_name, p.mobile_number, p.national_code, p.personnel_code, c.name AS company FROM persons p LEFT JOIN companies c ON c.id = p.company_id WHERE p.id = ?");
        $st->execute([$id]);
        if ($r = $st->fetch()) {
            if ($r['company']) $rows[] = ['محلِ کار', $r['company']];
            if ($r['personnel_code']) $rows[] = ['کد پرسنلی', $r['personnel_code']];
            if ($r['mobile_number']) $rows[] = ['موبایل', $r['mobile_number']];
        }
    } elseif ($type === 'C' && $actor['kind'] === 'STAFF') {
        $st = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $st->execute([$id]);
        if ($n = $st->fetchColumn()) $rows[] = ['شرکت', $n];
        $st = $pdo->prepare("SELECT GROUP_CONCAT(full_name SEPARATOR '، ') FROM company_portal_users WHERE COALESCE(is_deleted, 0) = 0 AND (company_id = ? OR id IN (SELECT portal_user_id FROM company_portal_user_companies WHERE company_id = ?))");
        try { $st->execute([$id, $id]); if ($n = $st->fetchColumn()) $rows[] = ['کاربرانِ پنل', $n]; } catch (Throwable $e) {}
    }
    return ['ok' => true, 'kind' => $head['type'] ?? $type, 'key' => $key, 'name' => $head['title'] ?? '', 'sub' => $head['sub'] ?? '', 'avatar' => $head['avatar'] ?? null,
            'presence' => $head['presence'] ?? null, 'rows' => $rows, 'editable' => false, 'me' => false, 'can_chat' => true];
}
