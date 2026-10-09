<?php
// فایل: api/_chat_core.php
// هسته‌ی پیام‌رسانِ یکپارچه: همه‌ی گفتگوهای سایت (کارکنان/کاربرانِ ربات، شرکت‌ها، همکارانِ داخلی) با یک
// منطق و یک ظاهر. داده‌ها همان جدول‌های قبلی‌اند تا ربات‌ها، اعلان‌ها و شمارنده‌ها همان‌طور کار کنند:
//   P:<person_id> / T:<ticket_id>  =>  tickets + ticket_messages        (کارکنان و کاربرانِ ربات)
//   C:<company_id>                 =>  company_chat_messages             (شرکت‌ها)
//   S:<user_id>                    =>  staff_chat_messages               (همکارانِ داخلی، گفتگوی دونفره)
// «actor» کسی است که درخواست داده:
//   ['kind' => 'STAFF',   'id' => user_id, 'role' => ..., 'name' => ...]
//   ['kind' => 'PERSON',  'id' => person_id, 'name' => ...]                  (وب‌اپ کارکنان)
//   ['kind' => 'COMPANY', 'id' => portal_user_id, 'company_ids' => [...], 'name' => ...]   (پنل شرکت‌ها)
// امکانات: فایل، پاسخ به پیام، ویرایش، حذف، موضوعِ پیام (درخواستِ مربوط)، خوانده‌شدن، «در حال نوشتن».

require_once __DIR__ . '/_profile_core.php';
require_once __DIR__ . '/_import_schema.php';
require_once __DIR__ . '/_chat_state.php';
require_once __DIR__ . '/_chat_access.php';
require_once __DIR__ . '/_chat_profile.php';
require_once __DIR__ . '/_name_honor.php';   // «آقای / خانم» پیش از نامِ همکاران

function chat_ready($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT edited_at, ref_type FROM staff_chat_messages LIMIT 0"); $pdo->query("SELECT edited_at FROM ticket_messages LIMIT 0"); $pdo->query("SELECT edited_at FROM company_chat_messages LIMIT 0"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
const CHAT_SCHEMA_MSG = 'برای پیام‌رسانِ جدید، مایگریشن migrations/021_chat_upgrade.sql را در phpMyAdmin اجرا کنید.';

// دسترسیِ کاربرانِ پنل: گفتگو با کارکنان (P/T)، شرکت‌ها (C)، و همکارانِ داخلی (S) - همه به همکاران دسترسی دارند
function chat_staff_caps($role) {
    return ['person' => in_array($role, ['ADMIN', 'OPERATOR', 'FINANCE'], true),
            'company' => in_array($role, ['ADMIN', 'COMPANY_LIAISON'], true),
            'staff' => true];
}

function chat_jdate($ts) { return $ts ? str_replace('.', '/', jalali_from_gregorian_ts_dotted($ts)) : ''; }
function chat_ts($v) { $t = $v ? strtotime((string)$v) : false; return $t ?: null; }

function chat_parse_key($key) {
    if (!preg_match('/^([PTCS]):(\d+)$/', (string)$key, $m)) return [null, 0];
    return [$m[1], intval($m[2])];
}

// آیا این actor به این گفتگو دسترسی دارد؟
function chat_can($pdo, $actor, $type, $id) {
    if (!$type || !$id) return false;
    if ($actor['kind'] === 'PERSON') return $type === 'P' && $id === intval($actor['id']);
    if ($actor['kind'] === 'COMPANY') return $type === 'C' && in_array($id, array_map('intval', $actor['company_ids']), true);
    $caps = chat_staff_caps($actor['role']);
    if ($type === 'P' || $type === 'T') return $caps['person'];
    if ($type === 'C') return chat_scope_allows(chat_company_scope($pdo, $actor), $id);   // نقش‌ها و افرادی که مدیر برای گفتگو با شرکت‌ها تعیین کرده
    if ($type === 'S') return $id !== intval($actor['id']) && chat_staff_pair_ok($pdo, $actor['id'], $id);   // دسترسیِ گفتگو بینِ همکاران (مدیر کل تعیین می‌کند)
    return false;
}

function chat_file_out($path, $name) {
    if (!$path) return null;
    $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    return ['url' => '/' . implode('/', array_map('rawurlencode', explode('/', ltrim((string)$path, '/')))),
            'name' => $name ?: basename((string)$path), 'ext' => $ext,
            'image' => in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true),
            'audio' => in_array($ext, ['mp3', 'ogg', 'oga', 'm4a', 'wav', 'webm'], true)];
}

// ذخیره‌ی فایلِ پیوستِ پیام (فهرستِ پسوندهای مجاز؛ هیچ فایلِ اجرایی‌ای پذیرفته نمی‌شود)
function chat_store_file($f, $destDir) {
    if (empty($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) return ['ok' => false, 'error' => 'فایلی دریافت نشد.'];
    if (($f['size'] ?? 0) > 20 * 1048576) return ['ok' => false, 'error' => 'حجم فایل بیشتر از ۲۰ مگابایت است.'];
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'txt', 'mp3', 'ogg', 'oga', 'm4a', 'wav', 'webm', 'mp4'];
    if (!in_array($ext, $allowed, true)) return ['ok' => false, 'error' => 'این نوع فایل مجاز نیست (عکس، PDF، Word، Excel، ZIP، صوت).'];
    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) return ['ok' => false, 'error' => 'ساختِ پوشه‌ی فایل‌ها ممکن نشد.'];
    $dest = unique_dest_path($destDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext);
    if (!move_uploaded_file($f['tmp_name'], $dest)) return ['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.'];
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) compress_image_if_needed($dest, 2097152, 2400);
    return ['ok' => true, 'rel' => ltrim(str_replace(dirname(__DIR__), '', $dest), '/'), 'name' => mb_substr((string)$f['name'], 0, 250)];
}

// ---------------------------------------------------------------------
//  فهرستِ گفتگوها (فقط برای کاربرانِ پنل)
// ---------------------------------------------------------------------
// پیش‌نمایشِ پیامِ بی‌متن در فهرستِ گفتگوها: پیامِ صوتی جدا از بقیه‌ی فایل‌ها
function chat_file_preview($name) {
    return preg_match('/^voice-/', (string)$name) ? '🎤 پیامِ صوتی' : '📎 فایل';
}
function chat_threads($pdo, $actor, $q = '') {
    $me = intval($actor['id']);
    $caps = chat_staff_caps($actor['role']);
    $out = [];
    $q = trim((string)$q);
    $match = function ($hay) use ($q) { return $q === '' || mb_stripos(implode(' ', array_map('strval', $hay)), $q) !== false; };

    if ($caps['person']) {
        // تیکت‌های هر شخص یک گفتگو می‌شوند (تیکتِ بدونِ شخص = گفتگوی جدا)
        $agg = [];
        foreach ($pdo->query("SELECT ticket_id, MAX(id) AS last_id, SUM(sender_type = 'CUSTOMER' AND COALESCE(is_read, '0') IN ('0', '')) AS unread
                                FROM ticket_messages WHERE deleted_at IS NULL GROUP BY ticket_id")->fetchAll() as $a) $agg[intval($a['ticket_id'])] = $a;
        $groups = [];
        foreach ($pdo->query("SELECT t.id, t.person_id, t.sender_name, t.customer_typing_at, p.full_name, p.national_code, p.mobile_number, " . prof_cols($pdo, 'p') . "
                                FROM tickets t LEFT JOIN persons p ON p.id = t.person_id")->fetchAll() as $t) {
            $a = $agg[intval($t['id'])] ?? null;
            if (!$a) continue;
            $key = $t['person_id'] && $t['full_name'] !== null ? 'P:' . intval($t['person_id']) : 'T:' . intval($t['id']);
            if (!isset($groups[$key])) $groups[$key] = ['key' => $key, 'type' => 'PERSON', 'title' => $t['full_name'] ?: ($t['sender_name'] ?: 'کاربر ربات'),
                                                        'sub' => trim(($t['national_code'] ?? '') . ' ' . ($t['mobile_number'] ?? '')), 'last_id' => 0, 'unread' => 0,
                                                        'avatar' => $t['avatar'] ?: null, 'presence' => $t['person_id'] ? prof_presence($t) : null];
            $groups[$key]['unread'] += intval($a['unread']);
            $groups[$key]['last_id'] = max($groups[$key]['last_id'], intval($a['last_id']));
        }
        if ($groups) {
            $ids = implode(',', array_map(fn($g) => $g['last_id'], $groups));
            $last = [];
            foreach ($pdo->query("SELECT id, sender_type, message, file_path, file_name, created_at FROM ticket_messages WHERE id IN ($ids)")->fetchAll() as $m) $last[intval($m['id'])] = $m;
            foreach ($groups as $g) {
                $m = $last[$g['last_id']] ?? null;
                if (!$match([$g['title'], $g['sub'], $m['message'] ?? ''])) continue;
                $out[] = $g + ['last' => $m ? ($m['message'] ?: chat_file_preview($m['file_name'])) : '', 'last_mine' => $m && $m['sender_type'] !== 'CUSTOMER', 'last_at' => chat_ts($m['created_at'] ?? null)];
            }
        }
    }
    $cScope = chat_company_scope($pdo, $actor);
    if ($cScope) {
        $st = $pdo->query("SELECT c.id, c.name,
                (SELECT GROUP_CONCAT(cpu.full_name SEPARATOR '، ') FROM company_portal_user_companies cpuc JOIN company_portal_users cpu ON cpu.id = cpuc.portal_user_id WHERE cpuc.company_id = c.id) AS members,
                m.id AS last_msg_id, m.message, m.file_path, m.file_name, m.sender_type, m.created_at,
                (SELECT COUNT(*) FROM company_chat_messages x WHERE x.company_id = c.id AND x.sender_type = 'COMPANY' AND x.is_read = 0 AND x.deleted_at IS NULL) AS unread
              FROM companies c JOIN company_chat_messages m ON m.id = (SELECT MAX(id) FROM company_chat_messages WHERE company_id = c.id AND deleted_at IS NULL)
             WHERE " . chat_scope_sql($cScope, 'c.id'));
        $cp = chat_company_presence_map($pdo);
        foreach ($st->fetchAll() as $c) {
            if (!$match([$c['name'], $c['members'], $c['message']])) continue;
            $out[] = ['key' => 'C:' . intval($c['id']), 'type' => 'COMPANY', 'title' => $c['name'], 'sub' => (string)$c['members'], 'avatar' => null, 'presence' => $cp[intval($c['id'])] ?? null, 'last_msg_id' => intval($c['last_msg_id']),
                      'last' => $c['message'] ?: chat_file_preview($c['file_name']), 'last_mine' => $c['sender_type'] === 'ADMIN', 'last_at' => chat_ts($c['created_at']), 'unread' => intval($c['unread'])];
        }
    }
    $st = $pdo->prepare("SELECT u.id, u.full_name, u.role, m.id AS last_msg_id, m.message, m.file_path, m.file_name, m.from_user_id, m.created_at, " . prof_cols($pdo, 'u') . ",
            (SELECT COUNT(*) FROM staff_chat_messages x WHERE x.from_user_id = u.id AND x.to_user_id = ? AND x.is_read = 0 AND x.deleted_at IS NULL) AS unread
          FROM users u JOIN staff_chat_messages m ON m.id = (SELECT MAX(id) FROM staff_chat_messages
                WHERE deleted_at IS NULL AND ((from_user_id = u.id AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = u.id)))
          WHERE u.id <> ?");
    $st->execute([$me, $me, $me, $me]);
    $blocked = array_flip(chat_staff_blocked_ids($pdo, $me));
    foreach ($st->fetchAll() as $u) {
        if (isset($blocked[intval($u['id'])]) || !$match([$u['full_name'], $u['message']])) continue;
        $out[] = ['key' => 'S:' . intval($u['id']), 'type' => 'STAFF', 'title' => honor_name($pdo, 'S', $u['id'], $u['full_name']), 'sub' => chat_role_fa($u['role']),
                  'avatar' => $u['avatar'] ?: null, 'presence' => prof_presence($u), 'last_msg_id' => intval($u['last_msg_id']), 'last' => $u['message'] ?: chat_file_preview($u['file_name']), 'last_mine' => intval($u['from_user_id']) === $me, 'last_at' => chat_ts($u['created_at']), 'unread' => intval($u['unread'])];
    }
    // تاریخچه‌ای که (برای من یا برای همه) پاک شده، پیش‌نمایشِ آخرین پیام را هم نشان نمی‌دهد؛ و گفتگوی قطع‌شده قفل دارد
    if (chat_state_ready($pdo)) {
        $upto = []; $closed = [];
        $st = $pdo->prepare("SELECT thread, MAX(upto_id) AS u FROM chat_hidden WHERE upto_id IS NOT NULL AND viewer IN (?, '*') GROUP BY thread");
        $st->execute([chat_viewer($actor)]);
        foreach ($st->fetchAll() as $r) $upto[$r['thread']] = intval($r['u']);
        foreach ($pdo->query("SELECT thread, closed_mode FROM chat_state WHERE closed_mode IS NOT NULL")->fetchAll() as $r) $closed[$r['thread']] = $r['closed_mode'];
        foreach ($out as &$t) {
            [$ty, $tid] = chat_parse_key($t['key']);
            $th = chat_thread_id($ty, $tid, $me);
            $t['closed'] = $closed[$th] ?? null;
            $lastId = intval($t['last_msg_id'] ?? ($t['last_id'] ?? 0));
            if (isset($upto[$th]) && $lastId && $lastId <= $upto[$th]) { $t['last'] = 'تاریخچه پاک شد'; $t['last_mine'] = false; $t['unread'] = 0; }
        }
        unset($t);
    }
    usort($out, fn($a, $b) => ($b['last_at'] ?? 0) <=> ($a['last_at'] ?? 0));
    foreach ($out as &$t) { $t['last_date'] = chat_jdate($t['last_at']); $t['last_time'] = $t['last_at'] ? date('H:i', $t['last_at']) : ''; $t['last'] = mb_substr((string)$t['last'], 0, 90); }
    return $out;
}

function chat_role_fa($role) {
    return ['ADMIN' => 'مدیر کل', 'OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی', 'COMPANY_LIAISON' => 'کارمند بیمه با ما',
            'PARSIAN' => 'کارمند بیمه با ما (پارسیان)', 'LIFE' => 'کاربر بیمه عمر'][$role] ?? 'همکار';
}

// «گفتگوی تازه»: جستجو بینِ کارکنان، شرکت‌ها و همکاران
function chat_contacts($pdo, $actor, $q) {
    $caps = chat_staff_caps($actor['role']);
    $q = trim((string)$q); $like = '%' . $q . '%';
    $out = [];
    if ($caps['person']) {
        $st = $pdo->prepare("SELECT x.id, x.full_name, x.national_code, x.mobile_number, " . prof_cols($pdo, 'x') . " FROM persons x WHERE x.full_name LIKE ? OR x.national_code LIKE ? OR x.mobile_number LIKE ? ORDER BY x.id DESC LIMIT 15");
        $st->execute([$like, $like, $like]);
        foreach ($st->fetchAll() as $p) $out[] = ['key' => 'P:' . $p['id'], 'type' => 'PERSON', 'title' => $p['full_name'], 'sub' => trim($p['national_code'] . ' ' . $p['mobile_number']),
                                                  'avatar' => $p['avatar'] ?: null, 'presence' => prof_presence($p)];
    }
    $cScope = chat_company_scope($pdo, $actor);
    if ($cScope) {
        imp_ensure($pdo);
        $st = $pdo->prepare("SELECT id, name FROM companies WHERE name LIKE ? AND is_import = 0 AND " . chat_scope_sql($cScope, 'id') . " ORDER BY name LIMIT 15");
        $st->execute([$like]);
        $cp = chat_company_presence_map($pdo);
        foreach ($st->fetchAll() as $c) $out[] = ['key' => 'C:' . $c['id'], 'type' => 'COMPANY', 'title' => $c['name'], 'sub' => 'شرکت', 'avatar' => null, 'presence' => $cp[intval($c['id'])] ?? null];
    }
    $pc = prof_cols($pdo, 'x');
    $st = $pdo->prepare("SELECT x.id, x.full_name, x.role, $pc FROM users x WHERE x.id <> ? AND COALESCE(x.is_deleted, 0) = 0 AND (x.full_name LIKE ? OR x.username LIKE ?) ORDER BY x.full_name LIMIT 20");
    try { $st->execute([intval($actor['id']), $like, $like]); } catch (Throwable $e) { $st = $pdo->prepare("SELECT x.id, x.full_name, x.role, $pc FROM users x WHERE x.id <> ? AND (x.full_name LIKE ? OR x.username LIKE ?) ORDER BY x.full_name LIMIT 20"); $st->execute([intval($actor['id']), $like, $like]); }
    $blocked = array_flip(chat_staff_blocked_ids($pdo, $actor['id']));
    foreach ($st->fetchAll() as $u) if (!isset($blocked[intval($u['id'])])) $out[] = ['key' => 'S:' . $u['id'], 'type' => 'STAFF', 'title' => honor_name($pdo, 'S', $u['id'], $u['full_name']), 'sub' => chat_role_fa($u['role']),
                                              'avatar' => $u['avatar'] ?: null, 'presence' => prof_presence($u)];
    return $out;
}

// ---------------------------------------------------------------------
//  سرِ گفتگو (نام و مشخصات) و «درخواست‌ها»ی طرفِ گفتگو (برای موضوعِ پیام)
// ---------------------------------------------------------------------
// سرِ گفتگو + عکس و حضورِ طرفِ مقابل
function chat_head($pdo, $actor, $type, $id) {
    $h = chat_head_base($pdo, $actor, $type, $id);
    $h['avatar'] = chat_peer_avatar($pdo, $actor, $type, $id);
    $h['presence'] = chat_peer_presence($pdo, $actor, $type, $id);
    return $h;
}
// عکسِ طرفِ مقابل (پشتیبانی و شرکت عکسِ ثابت ندارند)
function chat_peer_avatar($pdo, $actor, $type, $id) {
    if ($actor['kind'] !== 'STAFF' || !in_array($type, ['P', 'S'], true)) return null;
    $p = prof_get($pdo, $type === 'P' ? 'PERSON' : 'STAFF', $id);
    return $p['avatar'] ?? null;
}
// آنلاین / آخرین بازدیدِ طرفِ مقابل. برای کارکنان و شرکت‌ها «پشتیبانی» یعنی هر کدام از کارشناسانِ مربوط
function chat_peer_presence($pdo, $actor, $type, $id) {
    if (!prof_ready($pdo) || $type === 'T') return null;
    if ($actor['kind'] === 'PERSON') return prof_staff_presence($pdo, ['ADMIN', 'OPERATOR', 'FINANCE']);
    if ($actor['kind'] === 'COMPANY') return prof_staff_presence($pdo, ['ADMIN', 'COMPANY_LIAISON']);
    if ($type === 'C') return prof_company_presence($pdo, $id);
    $p = prof_get($pdo, $type === 'P' ? 'PERSON' : 'STAFF', $id);
    return $p['presence'] ?? null;
}
// حضورِ همه‌ی شرکت‌ها یک‌جا (برای فهرستِ گفتگوها)
function chat_company_presence_map($pdo) {
    if (!prof_ready($pdo)) return [];
    $rows = $pdo->query("SELECT l.company_id, " . prof_cols($pdo, 'x') . " FROM company_portal_users x
                           JOIN (SELECT portal_user_id, company_id FROM company_portal_user_companies
                                 UNION SELECT id, company_id FROM company_portal_users WHERE company_id IS NOT NULL) l ON l.portal_user_id = x.id
                          WHERE COALESCE(x.is_deleted, 0) = 0")->fetchAll();
    $by = [];
    foreach ($rows as $r) $by[intval($r['company_id'])][] = $r;
    return array_map('prof_presence_merge', $by);
}

function chat_head_base($pdo, $actor, $type, $id) {
    if ($type === 'P') {
        $st = $pdo->prepare("SELECT full_name, national_code, mobile_number, bale_chat_id FROM persons WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch();
        if ($actor['kind'] !== 'STAFF') return ['title' => 'پشتیبانی «بیمه با ما»', 'sub' => 'کارشناسان پاسخ می‌دهند', 'type' => 'PERSON'];
        return ['title' => $p['full_name'] ?? 'کاربر', 'sub' => trim(($p['national_code'] ?? '') . ' · ' . ($p['mobile_number'] ?? ''), ' ·'), 'type' => 'PERSON',
                'channel' => !empty($p['bale_chat_id']) ? 'پیام در پنل کاربری (وب‌اپ) نمایش داده می‌شود و در ربات بله فقط خبرش می‌رسد' : 'این کاربر هنوز به ربات بله وصل نشده؛ پیام را وقتی وارد پنل شود می‌بیند'];
    }
    if ($type === 'T') {
        $st = $pdo->prepare("SELECT sender_name, sender_username FROM tickets WHERE id = ?");
        $st->execute([$id]);
        $t = $st->fetch();
        return ['title' => $t['sender_name'] ?? 'کاربر ربات', 'sub' => $t && $t['sender_username'] ? '@' . $t['sender_username'] : 'کاربرِ ربات (ثبت‌نام‌نشده)', 'type' => 'PERSON',
                'channel' => 'این کاربر ثبت‌نام نکرده؛ پیام مستقیم در ربات بله فرستاده می‌شود'];
    }
    if ($type === 'C') {
        $st = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $st->execute([$id]);
        $name = $st->fetchColumn();
        if ($actor['kind'] === 'COMPANY') return ['title' => 'پشتیبانی «بیمه با ما»', 'sub' => 'گفتگوی ' . $name, 'type' => 'COMPANY'];
        $m = $pdo->prepare("SELECT GROUP_CONCAT(cpu.full_name SEPARATOR '، ') FROM company_portal_user_companies cpuc JOIN company_portal_users cpu ON cpu.id = cpuc.portal_user_id WHERE cpuc.company_id = ?");
        $m->execute([$id]);
        return ['title' => $name ?: 'شرکت', 'sub' => (string)$m->fetchColumn(), 'type' => 'COMPANY', 'channel' => 'در پنل شرکت دیده می‌شود و کاربرانِ شرکت در ربات بله خبردار می‌شوند'];
    }
    $st = $pdo->prepare("SELECT full_name, role FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    return ['title' => $u ? honor_name($pdo, 'S', $id, $u['full_name']) : 'همکار', 'sub' => chat_role_fa($u['role'] ?? ''), 'type' => 'STAFF', 'channel' => 'گفتگوی داخلیِ پنل'];
}

// درخواست‌های طرفِ گفتگو: درخواست‌های کارکنان، درخواست‌های شرکت، یا گزارش‌های بازدیدِ همکار
function chat_refs($pdo, $type, $id) {
    $out = [];
    try {
        if ($type === 'P') {
            $st = $pdo->prepare("SELECT id, unique_code, insurance_type, status, plate, insured_name, created_at FROM policy_cases WHERE person_id = ? ORDER BY id DESC LIMIT 100");
            $st->execute([$id]);
            foreach ($st->fetchAll() as $c) {
                $out[] = ['type' => 'CASE', 'id' => intval($c['id']), 'label' => trim(($c['unique_code'] ?: '#' . $c['id']) . ' · ' . ($c['insurance_type'] === 'BODY' ? 'بدنه' : 'ثالث') . ($c['plate'] ? ' · ' . $c['plate'] : '')),
                          'sub' => trim(($c['insured_name'] ?? '') . ' · ' . chat_case_status_fa($c['status']) . ' · ' . chat_jdate(chat_ts($c['created_at'])), ' ·')];
            }
        } elseif ($type === 'C') {
            $st = $pdo->prepare("SELECT cr.id, cr.created_at, cr.request_kind, cr.status, (SELECT COUNT(*) FROM company_request_plates p WHERE p.request_id = cr.id) AS n
                                   FROM company_requests cr WHERE cr.company_id = ? ORDER BY cr.id DESC LIMIT 100");
            $st->execute([$id]);
            $kinds = ['NEW_POLICY' => 'صدور', 'ENDORSEMENT' => 'الحاقیه', 'CANCELLATION' => 'فسخ'];
            foreach ($st->fetchAll() as $r) {
                $out[] = ['type' => 'COMPANY_REQUEST', 'id' => intval($r['id']), 'label' => 'درخواست #' . $r['id'] . ' · ' . ($kinds[$r['request_kind']] ?? 'صدور') . ' · ' . intval($r['n']) . ' خودرو',
                          'sub' => chat_jdate(chat_ts($r['created_at']))];
            }
        } elseif ($type === 'S') {
            $st = $pdo->prepare("SELECT id, report_no, plate_display, insured_name, report_date FROM visit_reports WHERE issuer_user_id = ? AND status = 'ACTIVE' ORDER BY id DESC LIMIT 100");
            $st->execute([$id]);
            foreach ($st->fetchAll() as $r) {
                $out[] = ['type' => 'VISIT_REPORT', 'id' => intval($r['id']), 'label' => 'گزارش ' . $r['report_no'] . ($r['plate_display'] ? ' · ' . $r['plate_display'] : ''),
                          'sub' => trim($r['insured_name'] . ' · ' . str_replace('.', '/', (string)$r['report_date']), ' ·')];
            }
        }
    } catch (Throwable $e) { /* جدولِ آن بخش هنوز نیست */ }
    return $out;
}
function chat_case_status_fa($s) {
    return ['DOCS_REVIEW' => 'بررسی مدارک', 'ISSUING' => 'در حال صدور', 'ISSUED' => 'صادر شد', 'WITHDRAWN' => 'انصراف', 'PENDING_DOCS' => 'در انتظار مدارک',
            'HEALTH' => 'بازدید سلامت', 'REJECTED' => 'رد شد'][$s] ?? ($s ?: 'ثبت شد');
}

// ---------------------------------------------------------------------
//  پیام‌ها
// ---------------------------------------------------------------------
// ستون‌های عکسِ فرستنده (پیش از اجرای مایگریشن ۰۲۲ خالی)
function chat_av_cols($pdo, $staffAlias, $clientAlias) {
    return prof_ready($pdo) ? "$staffAlias.avatar AS staff_av, $clientAlias.avatar AS client_av" : "NULL AS staff_av, NULL AS client_av";
}

// ردیف‌های خامِ پیام‌های یک گفتگو (با نامِ فرستنده)، به ترتیبِ زمان
function chat_raw_rows($pdo, $actor, $type, $id) {
    if ($type === 'P' || $type === 'T') {
        $where = $type === 'P' ? 't.person_id = ?' : 't.id = ?';
        $st = $pdo->prepare("SELECT tm.*, t.person_id, u.full_name AS staff_name, COALESCE(p.full_name, t.sender_name) AS client_name, " . chat_av_cols($pdo, 'u', 'p') . "
                               FROM ticket_messages tm JOIN tickets t ON t.id = tm.ticket_id
                               LEFT JOIN users u ON u.id = tm.admin_id LEFT JOIN persons p ON p.id = t.person_id
                              WHERE $where ORDER BY tm.id");
        $st->execute([$id]);
        return array_map(function ($r) {
            $ours = $r['sender_type'] !== 'CUSTOMER';
            return ['id' => intval($r['id']), 'ours' => $ours, 'author' => $ours ? 'STAFF:' . intval($r['admin_id']) : 'CLIENT',
                    'sender' => $ours ? ($r['staff_name'] ?: 'کارشناس') : ($r['client_name'] ?: 'کاربر'), 'avatar' => ($ours ? $r['staff_av'] : $r['client_av']) ?: null,
                    'text' => $r['message'], 'file' => $r['file_path'], 'file_name' => $r['file_name'], 'reply_to' => $r['reply_to_id'],
                    'ref' => $r['ref_type'] ? ['type' => $r['ref_type'], 'id' => intval($r['ref_id']), 'label' => $r['ref_label']] : null,
                    'edited' => !empty($r['edited_at']), 'deleted' => !empty($r['deleted_at']), 'at' => chat_ts($r['created_at']),
                    'read' => in_array((string)$r['is_read'], ['1'], true)];
        }, $st->fetchAll());
    }
    if ($type === 'C') {
        $st = $pdo->prepare("SELECT m.*, u.full_name AS staff_name, cpu.full_name AS portal_name, " . chat_av_cols($pdo, 'u', 'cpu') . " FROM company_chat_messages m
                               LEFT JOIN users u ON u.id = m.sender_user_id LEFT JOIN company_portal_users cpu ON cpu.id = m.sender_portal_user_id
                              WHERE m.company_id = ? ORDER BY m.id");
        $st->execute([$id]);
        return array_map(function ($r) {
            $ours = $r['sender_type'] === 'ADMIN';
            return ['id' => intval($r['id']), 'ours' => $ours, 'author' => $ours ? 'STAFF:' . intval($r['sender_user_id']) : 'CLIENT:' . intval($r['sender_portal_user_id']),
                    'sender' => $ours ? ($r['staff_name'] ?: 'بیمه با ما') : ($r['portal_name'] ?: 'کاربر شرکت'), 'avatar' => ($ours ? $r['staff_av'] : $r['client_av']) ?: null,
                    'text' => $r['message'], 'file' => $r['file_path'], 'file_name' => $r['file_name'], 'reply_to' => $r['reply_to_id'],
                    'ref' => $r['ref_type'] ? ['type' => $r['ref_type'], 'id' => intval($r['ref_id']), 'label' => $r['ref_label']] : null,
                    'edited' => !empty($r['edited_at']), 'deleted' => !empty($r['deleted_at']), 'at' => chat_ts($r['created_at']), 'read' => !empty($r['is_read'])];
        }, $st->fetchAll());
    }
    $me = intval($actor['id']);
    $st = $pdo->prepare("SELECT m.*, u.full_name AS from_name, " . chat_av_cols($pdo, 'u', 'u') . " FROM staff_chat_messages m LEFT JOIN users u ON u.id = m.from_user_id
                          WHERE (m.from_user_id = ? AND m.to_user_id = ?) OR (m.from_user_id = ? AND m.to_user_id = ?) ORDER BY m.id");
    $st->execute([$me, $id, $id, $me]);
    return array_map(function ($r) use ($me, $pdo) {
        $ours = intval($r['from_user_id']) === $me;
        return ['id' => intval($r['id']), 'ours' => $ours, 'author' => 'STAFF:' . intval($r['from_user_id']), 'sender' => $r['from_name'] ? honor_name($pdo, 'S', $r['from_user_id'], $r['from_name']) : 'همکار', 'avatar' => $r['staff_av'] ?: null,
                'text' => $r['message'], 'file' => $r['file_path'], 'file_name' => $r['file_name'], 'reply_to' => $r['reply_to_id'],
                'ref' => $r['ref_type'] ? ['type' => $r['ref_type'], 'id' => intval($r['ref_id']), 'label' => $r['ref_label']] : null,
                'edited' => !empty($r['edited_at']), 'deleted' => !empty($r['deleted_at']), 'at' => chat_ts($r['created_at']), 'read' => !empty($r['is_read'])];
    }, $st->fetchAll());
}

// «مالِ خودم» از نگاهِ actor: کاربرانِ پنل در گفتگوی کارکنان/شرکت‌ها طرفِ «بیمه با ما» هستند
function chat_is_mine($actor, $row) {
    if ($actor['kind'] === 'STAFF') return $row['ours'];
    return !$row['ours'];
}
// می‌تواند ویرایش/حذف کند؟ فقط پیامِ خودش؛ مدیر کل پیام‌های طرفِ «بیمه با ما» را هم
function chat_can_modify($actor, $type, $row) {
    if ($actor['kind'] === 'STAFF') {
        if ($row['author'] === 'STAFF:' . intval($actor['id'])) return true;
        return $actor['role'] === 'ADMIN' && $type !== 'S' && $row['ours'];
    }
    if ($actor['kind'] === 'PERSON') return $row['author'] === 'CLIENT';
    return strpos($row['author'], 'CLIENT') === 0;
}

// واکنش‌های پیام‌ها: [msg_id => [['e' => '❤️', 'n' => 2, 'mine' => true], ...]]
function chat_reactions_for($pdo, $actor, $type, array $ids) {
    $out = [];
    if (!$ids || !chat_ext_ensure($pdo)) return $out;
    $in = implode(',', array_map('intval', $ids));
    $me = chat_viewer($actor);
    $st = $pdo->prepare("SELECT msg_id, who, emoji FROM chat_reactions WHERE tbl = ? AND msg_id IN ($in) ORDER BY at");
    $st->execute([chat_rtbl($type)]);
    foreach ($st->fetchAll() as $r) {
        $m = intval($r['msg_id']); $e = $r['emoji'];
        if (!isset($out[$m][$e])) $out[$m][$e] = ['e' => $e, 'n' => 0, 'mine' => false];
        $out[$m][$e]['n']++;
        if ($r['who'] === $me) $out[$m][$e]['mine'] = true;
    }
    return array_map('array_values', $out);
}
function chat_react($pdo, $actor, $type, $id, $msgId, $emoji) {
    if (!chat_ext_ensure($pdo)) return ['ok' => false, 'error' => 'واکنش در دسترس نیست.'];
    $row = chat_find_row($pdo, $actor, $type, $id, $msgId);
    if (!$row || $row['deleted']) return ['ok' => false, 'error' => 'پیام پیدا نشد.'];
    $emoji = trim((string)$emoji);
    if ($emoji === '') {
        $pdo->prepare("DELETE FROM chat_reactions WHERE tbl = ? AND msg_id = ? AND who = ?")->execute([chat_rtbl($type), intval($msgId), chat_viewer($actor)]);
        return ['ok' => true];
    }
    if (mb_strlen($emoji) > 8 || preg_match('/[A-Za-z0-9<>"\'&]/', $emoji)) return ['ok' => false, 'error' => 'واکنشِ نامعتبر.'];
    $pdo->prepare("REPLACE INTO chat_reactions (tbl, msg_id, who, emoji, at) VALUES (?, ?, ?, ?, NOW())")->execute([chat_rtbl($type), intval($msgId), chat_viewer($actor), $emoji]);
    return ['ok' => true];
}

function chat_messages($pdo, $actor, $type, $id) {
    $rows = chat_visible_rows($pdo, $actor, $type, $id);
    $byId = [];
    foreach ($rows as $r) $byId[$r['id']] = $r;
    $reacts = chat_reactions_for($pdo, $actor, $type, array_keys($byId));
    $out = [];
    foreach ($rows as $r) {
        $mine = chat_is_mine($actor, $r);
        $rep = null;
        if ($r['reply_to'] && isset($byId[intval($r['reply_to'])])) {
            $o = $byId[intval($r['reply_to'])];
            $rep = ['id' => $o['id'], 'sender' => chat_is_mine($actor, $o) ? 'شما' : $o['sender'],
                    'text' => $o['deleted'] ? 'پیامِ حذف‌شده' : mb_substr((string)($o['text'] ?: ($o['file'] ? (preg_match('/^voice-/', (string)$o['file_name']) ? '🎤 پیامِ صوتی' : '📎 ' . ($o['file_name'] ?: 'فایل')) : '')), 0, 120)];
        } elseif ($r['reply_to']) {
            $rep = ['id' => intval($r['reply_to']), 'sender' => '', 'text' => 'پیامِ پاک‌شده'];
        }
        $out[] = ['id' => $r['id'], 'mine' => $mine, 'can_modify' => !$r['deleted'] && chat_can_modify($actor, $type, $r),
                  'sender' => $r['sender'], 'avatar' => $r['avatar'] ?? null, 'text' => $r['deleted'] ? null : $r['text'], 'file' => $r['deleted'] ? null : chat_file_out($r['file'], $r['file_name']),
                  'reply' => $rep, 'ref' => $r['ref'], 'edited' => $r['edited'], 'deleted' => $r['deleted'], 'reactions' => $r['deleted'] ? [] : ($reacts[$r['id']] ?? []),
                  'ts' => $r['at'], 'date' => chat_jdate($r['at']), 'time' => $r['at'] ? date('H:i', $r['at']) : '',
                  // تیکِ دوتایی: پیامِ من را طرفِ مقابل دیده؟
                  'read' => $mine ? $r['read'] : true];
    }
    return $out;
}

// پیام‌های رسیده‌ی این گفتگو برای این actor «خوانده» می‌شوند
function chat_mark_read($pdo, $actor, $type, $id) {
    if ($type === 'P' || $type === 'T') {
        $where = $type === 'P' ? 'person_id = ?' : 'id = ?';
        $side = $actor['kind'] === 'STAFF' ? "sender_type = 'CUSTOMER'" : "sender_type <> 'CUSTOMER'";
        $pdo->prepare("UPDATE ticket_messages SET is_read = 1 WHERE $side AND ticket_id IN (SELECT id FROM tickets WHERE $where)")->execute([$id]);
    } elseif ($type === 'C') {
        $pdo->prepare("UPDATE company_chat_messages SET is_read = 1 WHERE company_id = ? AND sender_type = ? AND is_read = 0")
            ->execute([$id, $actor['kind'] === 'STAFF' ? 'COMPANY' : 'ADMIN']);
    } else {
        $pdo->prepare("UPDATE staff_chat_messages SET is_read = 1 WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0")->execute([$id, intval($actor['id'])]);
    }
}

// جدول‌های تکمیلی: «در حال نوشتن» برای گفتگوی شرکت‌ها و همکاران، و واکنش (ایموجی) روی پیام‌ها (خودکار ساخته می‌شوند)
function chat_ext_ensure($pdo) {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing (conv VARCHAR(40) NOT NULL, who VARCHAR(30) NOT NULL, at DATETIME NOT NULL, PRIMARY KEY (conv, who))$T");
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reactions (tbl CHAR(1) NOT NULL, msg_id BIGINT NOT NULL, who VARCHAR(30) NOT NULL, emoji VARCHAR(32) NOT NULL, at DATETIME NOT NULL,
                    PRIMARY KEY (tbl, msg_id, who), KEY idx_msg (tbl, msg_id))$T");
        return $done = true;
    } catch (Throwable $e) { error_log('[chat_ext_ensure] ' . $e->getMessage()); return $done = false; }
}
function chat_rtbl($type) { return $type === 'C' ? 'C' : ($type === 'S' ? 'S' : 'T'); }

// «در حال نوشتن»: کارکنان در ستون‌های تیکت؛ شرکت‌ها و همکاران در chat_typing
function chat_set_typing($pdo, $actor, $type, $id) {
    if ($type !== 'P' && $type !== 'T') {
        if (chat_ext_ensure($pdo)) $pdo->prepare("REPLACE INTO chat_typing (conv, who, at) VALUES (?, ?, NOW())")->execute([chat_thread_id($type, $id, $actor['id']), chat_viewer($actor)]);
        return;
    }
    $col = $actor['kind'] === 'STAFF' ? 'admin_typing_at' : 'customer_typing_at';
    $where = $type === 'P' ? 'person_id = ?' : 'id = ?';
    $pdo->prepare("UPDATE tickets SET $col = NOW() WHERE $where ORDER BY id DESC LIMIT 1")->execute([$id]);
}
function chat_clear_typing($pdo, $actor, $type, $id) {
    if ($type === 'P' || $type === 'T' || !chat_ext_ensure($pdo)) return;
    $pdo->prepare("DELETE FROM chat_typing WHERE conv = ? AND who = ?")->execute([chat_thread_id($type, $id, $actor['id']), chat_viewer($actor)]);
}
// گفتگوهایی که طرفِ مقابل همین الان در حالِ نوشتن است (برای فهرستِ گفتگوهای پنل)
function chat_typing_keys($pdo, $actor) {
    $keys = [];
    try {
        foreach ($pdo->query("SELECT id, person_id FROM tickets WHERE customer_typing_at > DATE_SUB(NOW(), INTERVAL 6 SECOND)") as $r)
            $keys[intval($r['person_id']) > 0 ? 'P:' . intval($r['person_id']) : 'T:' . intval($r['id'])] = true;
        if (chat_ext_ensure($pdo)) {
            $me = 'STAFF:' . intval($actor['id']);
            foreach ($pdo->query("SELECT conv, who FROM chat_typing WHERE at > DATE_SUB(NOW(), INTERVAL 6 SECOND)") as $r) {
                if (strpos($r['conv'], 'C:') === 0 && strpos($r['who'], 'COMPANY:') === 0) $keys[$r['conv']] = true;
                elseif (preg_match('/^S:(\d+)-(\d+)$/', $r['conv'], $m) && $r['who'] !== $me && in_array(intval($actor['id']), [intval($m[1]), intval($m[2])], true))
                    $keys['S:' . (intval($m[1]) === intval($actor['id']) ? intval($m[2]) : intval($m[1]))] = true;
            }
        }
    } catch (Throwable $e) {}
    return $keys;
}
function chat_other_typing($pdo, $actor, $type, $id) {
    if ($type !== 'P' && $type !== 'T') {
        if (!chat_ext_ensure($pdo)) return false;
        // شرکت‌ها: طرفِ دیگر (کارکنانِ «بیمه با ما» در برابرِ کاربرانِ شرکت)؛ همکاران: خودِ طرفِ مقابل
        $st = $pdo->prepare("SELECT who FROM chat_typing WHERE conv = ? AND who <> ? AND at > DATE_SUB(NOW(), INTERVAL 6 SECOND)");
        $st->execute([chat_thread_id($type, $id, $actor['id']), chat_viewer($actor)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $w) if ($type === 'S' || strpos($w, $actor['kind'] . ':') !== 0) return true;
        return false;
    }
    $col = $actor['kind'] === 'STAFF' ? 'customer_typing_at' : 'admin_typing_at';
    $where = $type === 'P' ? 'person_id = ?' : 'id = ?';
    $st = $pdo->prepare("SELECT MAX($col) FROM tickets WHERE $where");
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v && (time() - strtotime($v)) < 6;
}

// تیکتِ فعالِ یک شخص (یا ساختنِ تیکتِ تازه)
function chat_person_ticket($pdo, $personId) {
    $st = $pdo->prepare("SELECT id FROM tickets WHERE person_id = ? ORDER BY (status = 'OPEN') DESC, id DESC LIMIT 1");
    $st->execute([$personId]);
    $tid = $st->fetchColumn();
    if ($tid) { $pdo->prepare("UPDATE tickets SET status = 'OPEN' WHERE id = ?")->execute([$tid]); return intval($tid); }
    $p = $pdo->prepare("SELECT full_name, bale_chat_id FROM persons WHERE id = ?");
    $p->execute([$personId]);
    $pp = $p->fetch() ?: ['full_name' => '', 'bale_chat_id' => null];
    $pdo->prepare("INSERT INTO tickets (chat_id, person_id, sender_name, status, created_at, last_message_at) VALUES (?, ?, ?, 'OPEN', NOW(), NOW())")
        ->execute([$pp['bale_chat_id'], $personId, $pp['full_name']]);
    return intval($pdo->lastInsertId());
}

// موضوعِ پیام: فقط یکی از درخواست‌های همان گفتگو
function chat_resolve_ref($pdo, $type, $id, $ref) {
    if (!is_array($ref) || empty($ref['type']) || empty($ref['id'])) return null;
    $refType = $type === 'T' ? null : $type;
    foreach (chat_refs($pdo, $refType, $id) as $r) if ($r['type'] === $ref['type'] && $r['id'] === intval($ref['id'])) return $r;
    return null;
}

function chat_send($pdo, $actor, $type, $id, $text, $file, $replyTo, $ref) {
    if (chat_state_ready($pdo) && !chat_side_can_send($pdo, chat_thread_id($type, $id, $actor['id']), chat_side($actor, $type))) {
        $st = chat_state_out($pdo, $actor, $type, $id);
        return ['ok' => false, 'error' => $st['text'] ?: 'این گفتگو بسته شده است.', 'closed' => true];
    }
    $text = trim(str_replace("\r\n", "\n", (string)$text));
    if (mb_strlen($text) > 4000) return ['ok' => false, 'error' => 'متن پیام خیلی طولانی است.'];
    $root = dirname(__DIR__);
    $refRow = chat_resolve_ref($pdo, $type, $id, $ref);
    $replyTo = intval($replyTo) ?: null;
    $fileRel = null; $fileName = null;
    if ($file) {
        $dir = $type === 'C' ? temp_archive_root($root) . '/چت شرکت‌ها/' . sanitize_folder_name(chat_company_name($pdo, $id))
             : ($type === 'S' ? temp_archive_root($root) . '/چت داخلی' : $root . '/موقت/موقت بایگانی/support/' . ($type === 'P' ? 'p' . $id : 't' . $id));
        $saved = chat_store_file($file, $dir);
        if (!$saved['ok']) return $saved;
        $fileRel = $saved['rel']; $fileName = $saved['name'];
    }
    if ($text === '' && !$fileRel) return ['ok' => false, 'error' => 'پیام خالی است.'];
    $refT = $refRow['type'] ?? null; $refI = $refRow['id'] ?? null; $refL = $refRow['label'] ?? null;
    $preview = $text !== '' ? mb_substr($text, 0, 120) : (preg_match('/^voice-/', (string)$fileName) ? '🎤 پیامِ صوتی' : '📎 ' . $fileName);

    if ($type === 'P' || $type === 'T') {
        $ticketId = $type === 'P' ? chat_person_ticket($pdo, $id) : $id;
        $staff = $actor['kind'] === 'STAFF';
        $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message, file_path, file_name, created_at, admin_id, is_read, reply_to_id, ref_type, ref_id, ref_label)
                       VALUES (?, ?, ?, ?, ?, NOW(), ?, 0, ?, ?, ?, ?)")
            ->execute([$ticketId, $staff ? 'ADMIN' : 'CUSTOMER', $text, $fileRel, $fileName, $staff ? intval($actor['id']) : null, $replyTo, $refT, $refI, $refL]);
        $mid = intval($pdo->lastInsertId());
        $pdo->prepare("UPDATE tickets SET last_message_at = NOW(), " . ($staff ? 'admin_typing_at' : 'customer_typing_at') . " = NULL WHERE id = ?")->execute([$ticketId]);
        if ($staff) chat_notify_client($pdo, $type, $id, $ticketId, $text, $fileRel);
        else { try { notify_admin($pdo, "💬 پیام از {$actor['name']}: " . $preview); } catch (Throwable $e) {} }
        return ['ok' => true, 'id' => $mid];
    }
    if ($type === 'C') {
        $staff = $actor['kind'] === 'STAFF';
        $pdo->prepare("INSERT INTO company_chat_messages (company_id, sender_type, sender_user_id, sender_portal_user_id, message, file_path, file_name, reply_to_id, ref_type, ref_id, ref_label)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$id, $staff ? 'ADMIN' : 'COMPANY', $staff ? intval($actor['id']) : null, $staff ? null : intval($actor['id']), $text !== '' ? $text : null, $fileRel, $fileName, $replyTo, $refT, $refI, $refL]);
        $mid = intval($pdo->lastInsertId());
        try {
            if ($staff) cbot_notify_company($pdo, $id, "💬 پیام تازه از «بیمه با ما»:\n" . $preview, ['inline_keyboard' => [[['text' => '↩️ پاسخ', 'callback_data' => 'chatc:' . $id]]]]);
            else cbot_notify_staff($pdo, ['ADMIN', 'COMPANY_LIAISON'], "💬 پیام تازه از «" . chat_company_name($pdo, $id) . "» ({$actor['name']}):\n" . $preview,
                                   ['inline_keyboard' => [[['text' => '↩️ پاسخ', 'callback_data' => 'lchat:' . $id]]]]);
        } catch (Throwable $e) {}
        return ['ok' => true, 'id' => $mid];
    }
    $pdo->prepare("INSERT INTO staff_chat_messages (from_user_id, to_user_id, message, file_path, file_name, reply_to_id, ref_type, ref_id, ref_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([intval($actor['id']), $id, $text !== '' ? $text : null, $fileRel, $fileName, $replyTo, $refT, $refI, $refL]);
    return ['ok' => true, 'id' => intval($pdo->lastInsertId())];
}

function chat_company_name($pdo, $id) {
    $st = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
    $st->execute([$id]);
    return (string)($st->fetchColumn() ?: 'شرکت');
}

// خبر دادن به کاربرِ کارکنان: متنِ پیام فقط در پنلِ کاربری (وب‌اپ) است؛ در ربات فقط یک خبرِ کوتاه با دکمه‌ی
// «مشاهده در پنل کاربری» می‌رسد (اگر پیامِ خوانده‌نشده‌ی قبلی در ۳ دقیقه‌ی اخیر خبر داده شده، تکرار نمی‌شود).
// کاربرِ ثبت‌نام‌نشده‌ی ربات (T) پنل ندارد؛ پیامش مثلِ قبل مستقیم در ربات فرستاده می‌شود.
function chat_notify_client($pdo, $type, $id, $ticketId, $text, $fileRel) {
    try {
        $bot = bale_get_token($pdo);
        if (!$bot) return;
        if ($type === 'T') {
            $st = $pdo->prepare("SELECT chat_id FROM tickets WHERE id = ?");
            $st->execute([$ticketId]);
            $chat = $st->fetchColumn();
            if ($chat && $text !== '') bale_curl_call('sendMessage', ['chat_id' => $chat, 'text' => "💬 پاسخ کارشناس:\n" . $text], $bot);
            return;
        }
        $st = $pdo->prepare("SELECT bale_chat_id FROM persons WHERE id = ?");
        $st->execute([$id]);
        $chat = $st->fetchColumn();
        if (!$chat) return;
        $recent = $pdo->prepare("SELECT COUNT(*) FROM ticket_messages tm JOIN tickets t ON t.id = tm.ticket_id
                                  WHERE t.person_id = ? AND tm.sender_type = 'ADMIN' AND COALESCE(tm.is_read, '0') IN ('0', '') AND tm.created_at >= (NOW() - INTERVAL 3 MINUTE)");
        $recent->execute([$id]);
        if (intval($recent->fetchColumn()) > 1) return;
        $site = get_site_url($pdo);
        $markup = $site ? ['inline_keyboard' => [[['text' => '💬 مشاهده و پاسخ در پنل کاربری', 'web_app' => ['url' => $site . '/webapp/app/?t=' . get_or_create_main_app_token($pdo, $id) . '&open=support']]]]] : null;
        $payload = ['chat_id' => $chat, 'text' => "📩 پیام تازه از کارشناسِ «بیمه با ما» دارید.\nبرای دیدن و پاسخ، دکمه‌ی زیر را بزنید."];
        if ($markup) $payload['reply_markup'] = $markup;
        bale_curl_call('sendMessage', $payload, $bot);
    } catch (Throwable $e) { error_log('[chat_notify_client] ' . $e->getMessage()); }
}

// ردیفِ یک پیام برای ویرایش/حذف/موضوع (با بررسیِ اینکه واقعاً در همین گفتگوست)
function chat_find_row($pdo, $actor, $type, $id, $msgId) {
    foreach (chat_raw_rows($pdo, $actor, $type, $id) as $r) if ($r['id'] === intval($msgId)) return $r;
    return null;
}
function chat_table($type) { return $type === 'C' ? 'company_chat_messages' : ($type === 'S' ? 'staff_chat_messages' : 'ticket_messages'); }

function chat_edit($pdo, $actor, $type, $id, $msgId, $text) {
    $r = chat_find_row($pdo, $actor, $type, $id, $msgId);
    if (!$r || $r['deleted']) return ['ok' => false, 'error' => 'پیام پیدا نشد.'];
    if (!chat_can_modify($actor, $type, $r)) return ['ok' => false, 'error' => 'فقط پیام‌های خودتان را می‌توانید ویرایش کنید.'];
    $text = trim((string)$text);
    if ($text === '' && !$r['file']) return ['ok' => false, 'error' => 'متن پیام خالی است.'];
    $pdo->prepare("UPDATE " . chat_table($type) . " SET message = ?, edited_at = NOW() WHERE id = ?")->execute([$text, $r['id']]);
    return ['ok' => true];
}
function chat_delete($pdo, $actor, $type, $id, $msgId) {
    $r = chat_find_row($pdo, $actor, $type, $id, $msgId);
    if (!$r || $r['deleted']) return ['ok' => false, 'error' => 'پیام پیدا نشد.'];
    if (!chat_can_modify($actor, $type, $r)) return ['ok' => false, 'error' => 'فقط پیام‌های خودتان را می‌توانید حذف کنید.'];
    chat_mark_deleted($pdo, $actor, $type, [$r['id']]);
    return ['ok' => true];
}
// حذف برای همه: پیام پاک نمی‌شود؛ فقط از دیدِ دو طرف پنهان و برای بایگانیِ مدیر با نامِ حذف‌کننده نگه داشته می‌شود
function chat_mark_deleted($pdo, $actor, $type, array $ids) {
    chat_access_ensure($pdo);
    foreach ($ids as $mid) {
        try { $pdo->prepare("UPDATE " . chat_table($type) . " SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")->execute([chat_actor_label($actor), intval($mid)]); }
        catch (Throwable $e) { $pdo->prepare("UPDATE " . chat_table($type) . " SET deleted_at = NOW() WHERE id = ?")->execute([intval($mid)]); }
    }
}
// موضوعِ یک پیامِ فرستاده‌شده را عوض می‌کند (کاربرانِ پنل روی همه‌ی پیام‌های گفتگوهای کارکنان/شرکت‌ها؛ بقیه فقط روی پیامِ خودشان)
function chat_set_ref($pdo, $actor, $type, $id, $msgId, $ref) {
    $r = chat_find_row($pdo, $actor, $type, $id, $msgId);
    if (!$r || $r['deleted']) return ['ok' => false, 'error' => 'پیام پیدا نشد.'];
    $allowed = ($actor['kind'] === 'STAFF' && $type !== 'S') || chat_can_modify($actor, $type, $r);
    if (!$allowed) return ['ok' => false, 'error' => 'موضوعِ این پیام را نمی‌توانید عوض کنید.'];
    $row = $ref ? chat_resolve_ref($pdo, $type, $id, $ref) : null;
    if ($ref && !$row) return ['ok' => false, 'error' => 'این درخواست مالِ همین گفتگو نیست.'];
    $pdo->prepare("UPDATE " . chat_table($type) . " SET ref_type = ?, ref_id = ?, ref_label = ? WHERE id = ?")->execute([$row['type'] ?? null, $row['id'] ?? null, $row['label'] ?? null, $r['id']]);
    return ['ok' => true];
}

// ---------------------------------------------------------------------
//  حذف «فقط برای من»، پاک کردنِ تاریخچه، قطعِ گفتگو (مایگریشن ۰۲۳)
// ---------------------------------------------------------------------
function chat_viewer($actor) { return $actor['kind'] . ':' . intval($actor['id']); }
// طرفِ actor در این گفتگو
function chat_side($actor, $type) {
    if ($type === 'S') return 'STAFF:' . intval($actor['id']);
    return $actor['kind'] === 'STAFF' ? 'OURS' : 'CLIENT';
}
// پیام‌هایی که این actor می‌بیند (بدونِ پنهان‌شده‌ها برای خودش یا برای همه)
function chat_visible_rows($pdo, $actor, $type, $id) {
    $rows = chat_raw_rows($pdo, $actor, $type, $id);
    if (!chat_state_ready($pdo) || !$rows) return $rows;
    $st = $pdo->prepare("SELECT msg_id, upto_id FROM chat_hidden WHERE thread = ? AND viewer IN (?, '*')");
    $st->execute([chat_thread_id($type, $id, $actor['id']), chat_viewer($actor)]);
    $ids = []; $upto = 0;
    foreach ($st->fetchAll() as $h) { if ($h['msg_id']) $ids[intval($h['msg_id'])] = true; if ($h['upto_id']) $upto = max($upto, intval($h['upto_id'])); }
    if (!$ids && !$upto) return $rows;
    return array_values(array_filter($rows, fn($r) => $r['id'] > $upto && !isset($ids[$r['id']])));
}
// پاک کردنِ تاریخچه برای هر دو طرف: همکارانِ داخلی (گفتگوی خودشان) و مدیر کل
function chat_can_clear_both($actor, $type) {
    if ($actor['kind'] !== 'STAFF') return false;
    return $type === 'S' || ($actor['role'] ?? '') === 'ADMIN';
}
// قطع/وصلِ گفتگو: کاربرانِ پنل (در گفتگوی کارکنان و شرکت‌ها، طرفِ «بیمه با ما»)؛ همکاران در گفتگوی دونفره
function chat_can_close($actor, $type) { return $actor['kind'] === 'STAFF'; }

function chat_state_out($pdo, $actor, $type, $id) {
    if (!chat_state_ready($pdo)) return null;
    $side = chat_side($actor, $type);
    $s = chat_state_get($pdo, chat_thread_id($type, $id, $actor['id']));
    $out = ['mode' => $s['closed_mode'] ?? null, 'by_me' => $s && $s['closed_side'] === $side, 'can_close' => chat_can_close($actor, $type),
            'can_clear_both' => chat_can_clear_both($actor, $type), 'can_send' => true, 'text' => ''];
    if (!$s) return $out;
    $out['can_send'] = $s['closed_mode'] !== 'BOTH' && $s['closed_side'] === $side;
    // وصلِ دوباره: همان طرفی که قطع کرده (یا مدیر کل)
    $out['can_reopen'] = chat_can_close($actor, $type) && ($out['by_me'] || ($actor['role'] ?? '') === 'ADMIN');
    $when = $s['closed_at'] ? ' (' . chat_jdate(strtotime($s['closed_at'])) . ')' : '';
    if ($s['closed_mode'] === 'BOTH') $out['text'] = ($out['by_me'] ? 'این گفتگو را دوطرفه قطع کرده‌اید' : 'این گفتگو بسته شده') . $when . '؛ هیچ‌کدام از دو طرف نمی‌توانند پیام بفرستند.';
    elseif ($out['by_me']) $out['text'] = 'این گفتگو را یک‌طرفه قطع کرده‌اید' . $when . '؛ طرفِ مقابل نمی‌تواند پیام بفرستد ولی شما می‌توانید.';
    else $out['text'] = $actor['kind'] === 'STAFF' && $type === 'S' ? 'این همکار گفتگو را قطع کرده؛ فعلاً نمی‌توانید پیام بفرستید.' : CHAT_CLOSED_CLIENT_MSG;
    return $out;
}

// چند پیام را «فقط برای من» پنهان می‌کند
function chat_hide($pdo, $actor, $type, $id, $ids) {
    if (!chat_state_ready($pdo)) return ['ok' => false, 'error' => CHAT_STATE_MSG];
    $mine = [];
    foreach (chat_raw_rows($pdo, $actor, $type, $id) as $r) $mine[$r['id']] = true;
    $ins = $pdo->prepare("INSERT INTO chat_hidden (viewer, thread, msg_id) VALUES (?, ?, ?)");
    $n = 0;
    foreach (array_unique(array_map('intval', (array)$ids)) as $mid) if (isset($mine[$mid])) { $ins->execute([chat_viewer($actor), chat_thread_id($type, $id, $actor['id']), $mid]); $n++; }
    return $n ? ['ok' => true, 'count' => $n] : ['ok' => false, 'error' => 'پیامی انتخاب نشده.'];
}
// حذف برای هر دو طرف (یک یا چند پیام) - فقط پیام‌هایی که اجازه‌ی حذفشان هست
function chat_delete_many($pdo, $actor, $type, $id, $ids) {
    $rows = [];
    foreach (chat_raw_rows($pdo, $actor, $type, $id) as $r) $rows[$r['id']] = $r;
    $ok = 0; $denied = 0;
    foreach (array_unique(array_map('intval', (array)$ids)) as $mid) {
        $r = $rows[$mid] ?? null;
        if (!$r || $r['deleted']) continue;
        if (!chat_can_modify($actor, $type, $r)) { $denied++; continue; }
        chat_mark_deleted($pdo, $actor, $type, [$mid]);
        $ok++;
    }
    if (!$ok) return ['ok' => false, 'error' => $denied ? 'فقط پیام‌های خودتان را می‌توانید برای هر دو طرف حذف کنید.' : 'پیامی پیدا نشد.'];
    return ['ok' => true, 'count' => $ok, 'denied' => $denied];
}
// پاک کردنِ کلِ تاریخچه: برای من، یا برای هر دو طرف
function chat_clear($pdo, $actor, $type, $id, $scope) {
    if (!chat_state_ready($pdo)) return ['ok' => false, 'error' => CHAT_STATE_MSG];
    if ($scope === 'both' && !chat_can_clear_both($actor, $type)) return ['ok' => false, 'error' => 'پاک کردنِ تاریخچه برای هر دو طرف فقط از دستِ مدیر کل برمی‌آید.'];
    $rows = chat_raw_rows($pdo, $actor, $type, $id);
    if (!$rows) return ['ok' => true];
    $max = max(array_column($rows, 'id'));
    chat_mark_read($pdo, $actor, $type, $id);
    $pdo->prepare("INSERT INTO chat_hidden (viewer, thread, upto_id) VALUES (?, ?, ?)")
        ->execute([$scope === 'both' ? '*' : chat_viewer($actor), chat_thread_id($type, $id, $actor['id']), $max]);
    return ['ok' => true];
}
// قطعِ گفتگو: ONE (یک‌طرفه) / BOTH (دوطرفه) / OPEN (وصلِ دوباره)
function chat_close($pdo, $actor, $type, $id, $mode) {
    if (!chat_state_ready($pdo)) return ['ok' => false, 'error' => CHAT_STATE_MSG];
    if (!chat_can_close($actor, $type)) return ['ok' => false, 'error' => 'قطعِ این گفتگو از دستِ شما برنمی‌آید.'];
    $thread = chat_thread_id($type, $id, $actor['id']);
    if ($mode === 'OPEN') {
        $st = chat_state_out($pdo, $actor, $type, $id);
        if ($st['mode'] && empty($st['can_reopen'])) return ['ok' => false, 'error' => 'فقط کسی که گفتگو را قطع کرده (یا مدیر کل) می‌تواند آن را دوباره وصل کند.'];
        $pdo->prepare("DELETE FROM chat_state WHERE thread = ?")->execute([$thread]);
        return ['ok' => true];
    }
    if (!in_array($mode, ['ONE', 'BOTH'], true)) return ['ok' => false, 'error' => 'نوعِ قطع نامعتبر است.'];
    $pdo->prepare("REPLACE INTO chat_state (thread, closed_mode, closed_side, closed_by, closed_at) VALUES (?, ?, ?, ?, NOW())")
        ->execute([$thread, $mode, chat_side($actor, $type), mb_substr((string)$actor['name'], 0, 150)]);
    return ['ok' => true];
}

// تعدادِ پیام‌های خوانده‌نشده (برای نشانِ منو)
function chat_unread_total($pdo, $actor) {
    $n = 0;
    if ($actor['kind'] === 'STAFF') {
        $caps = chat_staff_caps($actor['role']);
        if ($caps['person']) $n += intval($pdo->query("SELECT COUNT(*) FROM ticket_messages WHERE sender_type = 'CUSTOMER' AND COALESCE(is_read, '0') IN ('0', '') AND deleted_at IS NULL")->fetchColumn());
        $cScope = chat_company_scope($pdo, $actor);
        if ($cScope) $n += intval($pdo->query("SELECT COUNT(*) FROM company_chat_messages WHERE sender_type = 'COMPANY' AND is_read = 0 AND deleted_at IS NULL AND " . chat_scope_sql($cScope, 'company_id'))->fetchColumn());
        $bl = chat_staff_blocked_ids($pdo, $actor['id']);
        $st = $pdo->prepare("SELECT COUNT(*) FROM staff_chat_messages WHERE to_user_id = ? AND is_read = 0 AND deleted_at IS NULL" . ($bl ? " AND from_user_id NOT IN (" . implode(',', array_map('intval', $bl)) . ")" : ''));
        $st->execute([intval($actor['id'])]);
        $n += intval($st->fetchColumn());
    } elseif ($actor['kind'] === 'PERSON') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ticket_messages tm JOIN tickets t ON t.id = tm.ticket_id WHERE t.person_id = ? AND tm.sender_type <> 'CUSTOMER' AND COALESCE(tm.is_read, '0') IN ('0', '') AND tm.deleted_at IS NULL");
        $st->execute([intval($actor['id'])]);
        $n = intval($st->fetchColumn());
    } else {
        $ids = array_map('intval', $actor['company_ids']) ?: [0];
        $n = intval($pdo->query("SELECT COUNT(*) FROM company_chat_messages WHERE sender_type = 'ADMIN' AND is_read = 0 AND deleted_at IS NULL AND company_id IN (" . implode(',', $ids) . ")")->fetchColumn());
    }
    return $n;
}

// ---------------------------------------------------------------------
//  درگاهِ مشترکِ اکشن‌ها (پنل، وب‌اپ، پنل شرکت‌ها همه از این استفاده می‌کنند)
//  $key برای کاربرانِ وب‌اپ و شرکت‌ها از سمتِ سرور تعیین می‌شود
// ---------------------------------------------------------------------
function chat_dispatch($pdo, $actor, $action, array $data, $files = []) {
    // ---- حضور و پروفایل (به مایگریشنِ ۰۲۱ نیاز ندارد) ----
    if ($action === 'chat_offline') { presence_off($pdo, $actor['kind'], $actor['id']); return ['ok' => true]; }
    presence_touch($pdo, $actor['kind'], $actor['id']);   // هر درخواستِ پیام‌رسان یعنی «کاربر الان این‌جاست»
    if ($action === 'chat_ping') return ['ok' => true, 'unread' => chat_ready($pdo) ? chat_unread_total($pdo, $actor) : 0];
    if ($action === 'chat_me') {
        $me = prof_get($pdo, $actor['kind'], $actor['id']);
        return ['ok' => true, 'name' => $actor['name'], 'avatar' => $me['avatar'] ?? null, 'ready' => prof_ready($pdo)];
    }
    if ($action === 'chat_set_avatar') {   // عکسِ پروفایلِ خودم: یکی از آماده‌ها، بارگذاری، یا حذف
        if (!prof_ready($pdo)) return ['ok' => false, 'error' => PROF_SCHEMA_MSG];
        $value = (string)($data['avatar'] ?? '');
        if (!empty($files['avatar'])) {
            $saved = prof_avatar_store($files['avatar'], strtolower($actor['kind']) . '_' . intval($actor['id']));
            if (!$saved['ok']) return $saved;
            $value = $saved['path'];
        }
        return prof_set_avatar($pdo, $actor['kind'], $actor['id'], $value);
    }
    // پروفایل: کارتِ یک نفر (از روی کلیدِ گفتگو) و ویرایشِ پروفایلِ خودم (یا هر همکار، برای مدیر کل)
    if ($action === 'chat_profile') return cprof_card($pdo, $actor, (string)($data['key'] ?? ''));
    if ($action === 'chat_profile_save') {
        if ($actor['kind'] !== 'STAFF') return ['ok' => false, 'error' => 'دسترسی ندارید.'];
        $uid = intval($data['user_id'] ?? 0) ?: intval($actor['id']);
        if ($uid !== intval($actor['id']) && ($actor['role'] ?? '') !== 'ADMIN') return ['ok' => false, 'error' => 'فقط پروفایلِ خودتان را می‌توانید ویرایش کنید.'];
        $r = cprof_save($pdo, $uid, (array)($data['profile'] ?? []));
        return empty($r['ok']) ? $r : cprof_card($pdo, $actor, 'S:' . $uid);
    }
    if ($action === 'chat_avatar_upload') { // فقط مدیر: عکس برای فرمِ ساخت/ویرایشِ کاربر (مسیرش در همان فرم ذخیره می‌شود)
        if ($actor['kind'] !== 'STAFF' || ($actor['role'] ?? '') !== 'ADMIN') return ['ok' => false, 'error' => 'دسترسی ندارید.'];
        if (!prof_ready($pdo)) return ['ok' => false, 'error' => PROF_SCHEMA_MSG];
        return prof_avatar_store($files['avatar'] ?? null, 'u');
    }

    if (!chat_ready($pdo)) return ['ok' => false, 'error' => CHAT_SCHEMA_MSG];
    if ($action === 'chat_threads') {
        if ($actor['kind'] !== 'STAFF') return ['ok' => false, 'error' => 'دسترسی ندارید.'];
        $threads = chat_threads($pdo, $actor, $data['q'] ?? '');
        $tk = chat_typing_keys($pdo, $actor);
        foreach ($threads as &$th) $th['typing'] = isset($tk[$th['key']]);
        unset($th);
        return ['ok' => true, 'threads' => $threads, 'caps' => ['company' => (bool)chat_company_scope($pdo, $actor)] + chat_staff_caps($actor['role']) + ['admin' => ($actor['role'] ?? '') === 'ADMIN']];
    }
    if ($action === 'chat_contacts') {
        if ($actor['kind'] !== 'STAFF') return ['ok' => false, 'error' => 'دسترسی ندارید.'];
        return ['ok' => true, 'contacts' => chat_contacts($pdo, $actor, $data['q'] ?? '')];
    }
    if ($action === 'chat_unread') return ['ok' => true, 'unread' => chat_unread_total($pdo, $actor)];

    [$type, $id] = chat_parse_key($data['key'] ?? '');
    if (!chat_can($pdo, $actor, $type, $id)) return ['ok' => false, 'error' => 'به این گفتگو دسترسی ندارید.'];

    switch ($action) {
        case 'chat_open':      // سرِ گفتگو + درخواست‌ها + پیام‌ها
            chat_mark_read($pdo, $actor, $type, $id);
            return ['ok' => true, 'head' => chat_head($pdo, $actor, $type, $id), 'refs' => $type === 'T' ? [] : chat_refs($pdo, $type, $id),
                    'messages' => chat_messages($pdo, $actor, $type, $id), 'typing' => chat_other_typing($pdo, $actor, $type, $id),
                    'state' => chat_state_out($pdo, $actor, $type, $id)];
        case 'chat_poll':
            chat_mark_read($pdo, $actor, $type, $id);
            return ['ok' => true, 'messages' => chat_messages($pdo, $actor, $type, $id), 'typing' => chat_other_typing($pdo, $actor, $type, $id),
                    'presence' => chat_peer_presence($pdo, $actor, $type, $id), 'state' => chat_state_out($pdo, $actor, $type, $id)];
        case 'chat_send':
            $ref = $data['ref'] ?? null;
            if (is_string($ref)) $ref = json_decode($ref, true);
            chat_clear_typing($pdo, $actor, $type, $id);
            return chat_send($pdo, $actor, $type, $id, $data['text'] ?? '', $files['file'] ?? null, $data['reply_to'] ?? null, $ref);
        case 'chat_edit':    return chat_edit($pdo, $actor, $type, $id, $data['id'] ?? 0, $data['text'] ?? '');
        case 'chat_delete':  // یک یا چند پیام، برای هر دو طرف
            return isset($data['ids']) ? chat_delete_many($pdo, $actor, $type, $id, is_string($data['ids']) ? json_decode($data['ids'], true) : $data['ids'])
                                       : chat_delete($pdo, $actor, $type, $id, $data['id'] ?? 0);
        case 'chat_hide':    // فقط برای من
            return chat_hide($pdo, $actor, $type, $id, isset($data['ids']) ? (is_string($data['ids']) ? json_decode($data['ids'], true) : $data['ids']) : [$data['id'] ?? 0]);
        case 'chat_clear':   return chat_clear($pdo, $actor, $type, $id, ($data['scope'] ?? '') === 'both' ? 'both' : 'me');
        case 'chat_close':   return chat_close($pdo, $actor, $type, $id, strtoupper((string)($data['mode'] ?? '')));
        case 'chat_set_ref':
            $ref = $data['ref'] ?? null;
            if (is_string($ref)) $ref = json_decode($ref, true);
            return chat_set_ref($pdo, $actor, $type, $id, $data['id'] ?? 0, $ref ?: null);
        case 'chat_typing':  chat_set_typing($pdo, $actor, $type, $id); return ['ok' => true];
        case 'chat_react':   return chat_react($pdo, $actor, $type, $id, $data['id'] ?? 0, $data['emoji'] ?? '');
    }
    return ['ok' => false, 'error' => 'عملیات نامعتبر.'];
}
