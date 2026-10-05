<?php
// فایل: api/_profile_core.php
// عکس پروفایل و «آنلاین / آخرین بازدید» برای هر سه نوع کاربر:
//   STAFF   => users                 (کاربرانِ پنل)
//   PERSON  => persons               (کارکنان در وب‌اپ)
//   COMPANY => company_portal_users  (کاربرانِ پنل شرکت‌ها)
// عکس: «preset:N» (یکی از عکس‌های آماده‌ی chat-ui.js) یا مسیرِ فایل در uploads/avatars/
// حضور: هر صفحه‌ی باز هر چند ثانیه last_seen_at را به‌روز می‌کند؛ بستنِ صفحه last_offline_at را می‌زند.
// «آنلاین» یعنی در PROF_ONLINE_SEC ثانیه‌ی اخیر دیده شده و بعدش صفحه را نبسته.

const PROF_ONLINE_SEC = 45;
const PROF_PRESETS = 16;
const PROF_SCHEMA_MSG = 'برای عکس پروفایل و «آخرین بازدید»، مایگریشن migrations/022_profile_presence.sql را در phpMyAdmin اجرا کنید.';

function prof_table($kind) {
    return ['STAFF' => 'users', 'PERSON' => 'persons', 'COMPANY' => 'company_portal_users'][$kind] ?? null;
}

function prof_ready($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        foreach (['users', 'persons', 'company_portal_users'] as $t) $pdo->query("SELECT avatar, last_seen_at, last_offline_at FROM `$t` LIMIT 0");
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

// ستون‌های عکس و حضور برای SELECT (اگر مایگریشن اجرا نشده، مقدارِ خالی برمی‌گردد و چیزی نمی‌شکند)
//   avatar، seen_ago (چند ثانیه پیش دیده شده) و is_online
// ستونِ «وضعیتِ من» (امکاناتِ رفاهی) اگر ساخته شده باشد
function prof_status_ready($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { foreach (['users', 'persons', 'company_portal_users'] as $t) $pdo->query("SELECT cf_status FROM `$t` LIMIT 0"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
function prof_cols($pdo, $a) {
    if (!prof_ready($pdo)) return "NULL AS avatar, NULL AS seen_ago, 0 AS is_online";
    $online = PROF_ONLINE_SEC;
    return "$a.avatar AS avatar, " . (prof_status_ready($pdo) ? "$a.cf_status AS cf_status," : "NULL AS cf_status,") . "
            TIMESTAMPDIFF(SECOND, GREATEST($a.last_seen_at, COALESCE($a.last_offline_at, $a.last_seen_at)), NOW()) AS seen_ago,
            ($a.last_seen_at IS NOT NULL AND $a.last_seen_at >= NOW() - INTERVAL $online SECOND
             AND ($a.last_offline_at IS NULL OR $a.last_offline_at < $a.last_seen_at)) AS is_online";
}

// خروجیِ یکسانِ حضور برای فرانت: online (bool) و ago (ثانیه؛ null یعنی هرگز)
function prof_presence($row) {
    if (!$row) return null;
    $ago = $row['seen_ago'] ?? null;
    return ['online' => !empty($row['is_online']), 'ago' => $ago === null ? null : max(0, intval($ago)), 'status' => prof_status_decode($row['cf_status'] ?? null)];
}

// «وضعیتِ من»: {code, text, icon, until} یا null (اگر تنظیم نشده یا زمانش گذشته)
const CF_STATUS = ['ready' => ['آماده', '🟢'], 'meeting' => ['در جلسه', '🗓️'], 'lunch' => ['ناهار', '🍽️'], 'remote' => ['دورکاری', '🏠'],
                   'busy' => ['مشغول', '⏳'], 'dnd' => ['مزاحم نشوید', '🔕'], 'away' => ['بیرون از دفتر', '🚶'], 'custom' => ['', '💬']];
function prof_status_decode($raw) {
    if ($raw === null || $raw === '') return null;
    $s = json_decode((string)$raw, true);
    if (!is_array($s) || empty($s['c']) || !isset(CF_STATUS[$s['c']])) return null;
    if (!empty($s['u']) && intval($s['u']) < time()) return null;
    $meta = CF_STATUS[$s['c']];
    return ['code' => $s['c'], 'text' => ($s['t'] ?? '') !== '' ? $s['t'] : $meta[0], 'icon' => $meta[1], 'until' => intval($s['u'] ?? 0)];
}

// «زنده‌ام»: صفحه‌ی باز هر چند ثانیه صدا می‌زند
function presence_touch($pdo, $kind, $id) {
    static $done = [];
    $t = prof_table($kind);
    if (!$t || !$id || !prof_ready($pdo) || isset($done[$kind . $id])) return;
    $done[$kind . $id] = true;
    try { $pdo->prepare("UPDATE `$t` SET last_seen_at = NOW() WHERE id = ?")->execute([intval($id)]); } catch (Throwable $e) {}
}
// بستنِ صفحه / خروج
function presence_off($pdo, $kind, $id) {
    $t = prof_table($kind);
    if (!$t || !$id || !prof_ready($pdo)) return;
    try { $pdo->prepare("UPDATE `$t` SET last_offline_at = NOW() WHERE id = ?")->execute([intval($id)]); } catch (Throwable $e) {}
}

// حضورِ یک کاربر
function prof_get($pdo, $kind, $id) {
    $t = prof_table($kind);
    if (!$t || !$id) return null;
    $st = $pdo->prepare("SELECT " . prof_cols($pdo, 'x') . " FROM `$t` x WHERE x.id = ?");
    $st->execute([intval($id)]);
    $r = $st->fetch();
    return $r ? ['avatar' => $r['avatar'] ?: null, 'presence' => prof_presence($r)] : null;
}

// حضورِ جمعیِ یک شرکت: اگر هر کدام از کاربرانش آنلاین باشد، آنلاین است
function prof_company_presence($pdo, $companyId) {
    if (!prof_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT " . prof_cols($pdo, 'x') . " FROM company_portal_users x
                          WHERE COALESCE(x.is_deleted, 0) = 0 AND (x.company_id = ? OR x.id IN (SELECT portal_user_id FROM company_portal_user_companies WHERE company_id = ?))");
    $st->execute([intval($companyId), intval($companyId)]);
    return prof_presence_merge($st->fetchAll());
}
// حضورِ جمعیِ کاربرانِ پنل با این نقش‌ها («پشتیبانی آنلاین است»)
function prof_staff_presence($pdo, array $roles) {
    if (!prof_ready($pdo) || !$roles) return null;
    $in = implode(',', array_fill(0, count($roles), '?'));
    $st = $pdo->prepare("SELECT " . prof_cols($pdo, 'x') . " FROM users x WHERE x.role IN ($in)");
    $st->execute($roles);
    return prof_presence_merge($st->fetchAll());
}
function prof_presence_merge(array $rows) {
    $online = false; $ago = null;
    foreach ($rows as $r) {
        if (!empty($r['is_online'])) $online = true;
        if ($r['seen_ago'] !== null) $ago = $ago === null ? intval($r['seen_ago']) : min($ago, intval($r['seen_ago']));
    }
    return ['online' => $online, 'ago' => $ago === null ? null : max(0, $ago)];
}

// مقدارِ مجازِ عکس: '' (حذف)، «preset:N»، یا فایلی که واقعاً در uploads/avatars هست
function prof_avatar_valid($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/^preset:(\d{1,2})$/', $v, $m) && intval($m[1]) >= 1 && intval($m[1]) <= PROF_PRESETS) return 'preset:' . intval($m[1]);
    if (preg_match('#^uploads/avatars/[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$#', $v) && is_file(dirname(__DIR__) . '/' . $v)) return $v;
    return false;
}

// ذخیره‌ی عکسِ بارگذاری‌شده (فقط عکسِ واقعی؛ مرورگر قبلش آن را مربع و کوچک می‌کند)
function prof_avatar_store($f, $prefix) {
    if (empty($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) return ['ok' => false, 'error' => 'عکسی دریافت نشد.'];
    if (($f['size'] ?? 0) > 3 * 1048576) return ['ok' => false, 'error' => 'حجمِ عکس بیشتر از ۳ مگابایت است.'];
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) return ['ok' => false, 'error' => 'فقط عکسِ JPG، PNG یا WEBP.'];
    $dir = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return ['ok' => false, 'error' => 'ساختِ پوشه‌ی عکس‌ها ممکن نشد.'];
    // هیچ اسکریپتی در این پوشه اجرا نشود
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n    Require all denied\n</FilesMatch>\n");
    $name = preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . date('ymdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) return ['ok' => false, 'error' => 'ذخیره‌ی عکس ممکن نشد.'];
    if (function_exists('compress_image_if_needed')) compress_image_if_needed($dir . '/' . $name, 400000, 640);
    return ['ok' => true, 'path' => 'uploads/avatars/' . $name];
}

// تنظیمِ عکسِ یک کاربر (عکسِ بارگذاری‌شده‌ی قبلی پاک می‌شود)
function prof_set_avatar($pdo, $kind, $id, $value) {
    $t = prof_table($kind);
    if (!$t || !prof_ready($pdo)) return ['ok' => false, 'error' => PROF_SCHEMA_MSG];
    $v = prof_avatar_valid($value);
    if ($v === false) return ['ok' => false, 'error' => 'عکسِ انتخاب‌شده معتبر نیست.'];
    $st = $pdo->prepare("SELECT avatar FROM `$t` WHERE id = ?");
    $st->execute([intval($id)]);
    $old = (string)$st->fetchColumn();
    $pdo->prepare("UPDATE `$t` SET avatar = ? WHERE id = ?")->execute([$v !== '' ? $v : null, intval($id)]);
    if ($old !== '' && $old !== $v && strpos($old, 'uploads/avatars/') === 0) @unlink(dirname(__DIR__) . '/' . $old);
    return ['ok' => true, 'avatar' => $v !== '' ? $v : null];
}
