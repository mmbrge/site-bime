<?php
// فایل: api/_comfort.php
// «امکاناتِ رفاهی» برای راحتیِ کاربران: پخش‌کننده‌ی موسیقی (کتابخانه‌ی پنل + آهنگ‌های شخصی)، کارهای امروز و یادآور،
// یادآورِ سلامت و تایمرِ تمرکز، «مزاحم نشوید»، وضعیتِ من، متن‌های آماده‌ی گفتگو، ابزارهای سریع، ظاهرِ شخصی،
// تولد و مناسبت‌ها، پیامِ صبح‌بخیر و جستجوی سراسری.
// دسترسی: برای کاربرانِ پنل پیش‌فرض همه روشن، برای کاربرانِ شرکت‌ها پیش‌فرض خاموش؛ مدیر کل برای هر نفر روشن/خاموش می‌کند.
// همه‌ی جدول‌ها خودکار ساخته می‌شوند.

require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_profile_core.php';   // CF_STATUS و prof_status_decode (وضعیتِ من)

// کلید => [عنوان، کجا معنا دارد: both | staff]
const CF_FEATURES = [
    'music'        => ['پخش‌کننده‌ی موسیقی', 'both'],
    'music_upload' => ['آپلودِ آهنگِ شخصی (تا سقفِ حجم)', 'both'],
    'todo'         => ['کارهای امروزِ من و یادآور', 'both'],
    'health'       => ['یادآورِ سلامت و تایمرِ تمرکز', 'both'],
    'dnd'          => ['حالتِ «مزاحم نشوید»', 'both'],
    'status'       => ['وضعیتِ من (در جلسه، ناهار، …)', 'both'],
    'canned'       => ['متن‌های آماده‌ی گفتگو', 'both'],
    'tools'        => ['ابزارهای سریع (ریال/تومان، تاریخ، اقساط)', 'both'],
    'theme'        => ['ظاهرِ شخصی (تاریک، اندازه‌ی متن، رنگ)', 'both'],
    'morning'      => ['پیامِ صبح‌بخیر و خلاصه‌ی روز', 'both'],
    'birthdays'    => ['تولدِ همکاران و مناسبت‌ها', 'staff'],
    'search'       => ['جستجوی سراسری (Ctrl+K) و میانبرها', 'staff'],
];
const CF_DEFAULT_GENRES = ['ملایم', 'شاد', 'بی‌کلام', 'پاپ ایرانی', 'سنتی', 'خارجی', 'کلاسیک', 'طبیعت و آرامش', 'سایر'];
const CF_FOCUS_GENRES = ['بی‌کلام', 'کلاسیک', 'طبیعت و آرامش'];
const CF_AUDIO = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg',
                  'wav' => 'audio/wav', 'flac' => 'audio/flac', 'webm' => 'audio/webm'];
const CF_MUSIC_DIR = 'uploads/music';
const CF_MAX_TRACK = 60 * 1024 * 1024;      // هر آهنگ حداکثر ۶۰ مگابایت
const CF_MAX_ZIP = 1024 * 1024 * 1024;      // هر زیپِ مدیر حداکثر ۱ گیگابایت

function cf_root() { return dirname(__DIR__); }
function cf_music_path($file = '') { return cf_root() . '/' . CF_MUSIC_DIR . ($file !== '' ? '/' . basename($file) : ''); }

function cf_col_exists($pdo, $table, $col) {
    try { $pdo->query("SELECT `$col` FROM `$table` LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}
function cf_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_access (actor_type CHAR(1) NOT NULL, actor_id INT NOT NULL, overrides TEXT NULL, updated_at DATETIME NULL, PRIMARY KEY (actor_type, actor_id))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_prefs (actor_type CHAR(1) NOT NULL, actor_id INT NOT NULL, prefs MEDIUMTEXT NULL, updated_at DATETIME NULL, PRIMARY KEY (actor_type, actor_id))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_tracks (
        id INT AUTO_INCREMENT PRIMARY KEY, owner_type CHAR(1) NOT NULL, owner_id INT NOT NULL DEFAULT 0,
        uploader_type CHAR(1) NULL, uploader_id INT NULL, title VARCHAR(200) NOT NULL, artist VARCHAR(160) NULL, genre VARCHAR(60) NULL, album VARCHAR(160) NULL,
        orig_name VARCHAR(255) NULL, file VARCHAR(80) NOT NULL, mime VARCHAR(40) NOT NULL, size BIGINT NOT NULL DEFAULT 0, sha1 CHAR(40) NOT NULL,
        duration INT NULL, plays INT NOT NULL DEFAULT 0, created_at DATETIME NULL,
        KEY idx_owner (owner_type, owner_id), KEY idx_sha (sha1), KEY idx_genre (genre))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_blocked (sha1 CHAR(40) NOT NULL PRIMARY KEY, title VARCHAR(255) NULL, blocked_at DATETIME NULL, blocked_by INT NULL)$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_quota (actor_type CHAR(1) NOT NULL, actor_id INT NOT NULL, base_bytes BIGINT NOT NULL DEFAULT 0, reset_at DATETIME NULL, reset_by INT NULL, PRIMARY KEY (actor_type, actor_id))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_likes (actor_type CHAR(1) NOT NULL, actor_id INT NOT NULL, track_id INT NOT NULL, created_at DATETIME NULL, PRIMARY KEY (actor_type, actor_id, track_id))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_playlists (id INT AUTO_INCREMENT PRIMARY KEY, actor_type CHAR(1) NOT NULL, actor_id INT NOT NULL, name VARCHAR(120) NOT NULL, track_ids TEXT NULL, created_at DATETIME NULL, KEY idx_actor (actor_type, actor_id))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_todos (id INT AUTO_INCREMENT PRIMARY KEY, actor_type CHAR(1) NOT NULL, actor_id INT NOT NULL, text VARCHAR(500) NOT NULL,
        due_date DATE NULL, remind_at DATETIME NULL, reminded TINYINT(1) NOT NULL DEFAULT 0, done_at DATETIME NULL, created_at DATETIME NULL, KEY idx_actor (actor_type, actor_id))$T");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cf_canned (id INT AUTO_INCREMENT PRIMARY KEY, actor_type CHAR(1) NOT NULL, actor_id INT NOT NULL DEFAULT 0, title VARCHAR(120) NOT NULL,
        body TEXT NOT NULL, is_global TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NULL, KEY idx_actor (actor_type, actor_id))$T");
    // وضعیتِ من: کنارِ عکس و «آنلاین» در گفتگو و فهرستِ کاربران
    foreach (['users', 'company_portal_users', 'persons'] as $t) {
        try { if (!cf_col_exists($pdo, $t, 'cf_status')) $pdo->exec("ALTER TABLE `$t` ADD COLUMN cf_status VARCHAR(300) NULL"); } catch (Throwable $e) {}
    }
    $dir = cf_music_path();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir . '/_tmp')) @mkdir($dir . '/_tmp', 0755, true);
    // آهنگ‌ها فقط از راهِ «stream» (با بررسیِ دسترسی) پخش می‌شوند، نه با آدرسِ مستقیم
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "# موسیقی فقط از api/comfort_actions.php?action=stream پخش می‌شود\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\nOptions -Indexes\n");
    }
}

// ---------------- کاربر ----------------
// [نوع (S پنل / C شرکت)، شناسه، نام، نقش]
function cf_actor() {
    if (session_status() !== PHP_SESSION_ACTIVE) return null;
    if (session_name() === 'bime_company_portal') {
        return !empty($_SESSION['company_user_id']) ? ['C', intval($_SESSION['company_user_id']), (string)($_SESSION['company_user_full_name'] ?? ''), 'COMPANY'] : null;
    }
    if (empty($_SESSION['user_id'])) return null;
    $role = function_exists('perm_real_role') ? perm_real_role() : ($_SESSION['role'] ?? '');
    return ['S', intval($_SESSION['user_id']), (string)($_SESSION['full_name'] ?? ''), $role];
}
function cf_is_admin($a) { return $a && $a[0] === 'S' && $a[3] === 'ADMIN'; }
function cf_actor_name($pdo, $type, $id) {
    static $c = [];
    $k = $type . $id;
    if (isset($c[$k])) return $c[$k];
    if ($type === 'P') return $c[$k] = 'کتابخانه‌ی پنل';
    try {
        $st = $pdo->prepare($type === 'C' ? "SELECT full_name FROM company_portal_users WHERE id = ?" : "SELECT full_name FROM users WHERE id = ?");
        $st->execute([intval($id)]);
        return $c[$k] = (string)($st->fetchColumn() ?: '#' . $id);
    } catch (Throwable $e) { return $c[$k] = '#' . $id; }
}

// ---------------- دسترسی ----------------
function cf_feature_keys($type) {
    return array_keys(array_filter(CF_FEATURES, function ($f) use ($type) { return $type === 'S' || $f[1] === 'both'; }));
}
function cf_overrides($pdo, $type, $id) {
    cf_ensure($pdo);
    $st = $pdo->prepare("SELECT overrides FROM cf_access WHERE actor_type = ? AND actor_id = ?");
    $st->execute([$type, intval($id)]);
    return json_decode((string)$st->fetchColumn(), true) ?: [];
}
// امکاناتِ روشنِ یک نفر: پیش‌فرض (پنل همه روشن، شرکت همه خاموش) + تغییرهای مدیر
function cf_features($pdo, $type, $id) {
    $ov = cf_overrides($pdo, $type, $id);
    $out = [];
    foreach (cf_feature_keys($type) as $k) $out[$k] = array_key_exists($k, $ov) ? (bool)$ov[$k] : $type === 'S';
    if (empty($out['music'])) $out['music_upload'] = false;
    return $out;
}
function cf_set_overrides($pdo, $type, $id, array $features) {
    cf_ensure($pdo);
    $keys = cf_feature_keys($type);
    $ov = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $features)) continue;
        $on = !empty($features[$k]);
        if ($on !== ($type === 'S')) $ov[$k] = $on ? 1 : 0;   // فقط آنچه با پیش‌فرض فرق دارد ذخیره می‌شود
    }
    $pdo->prepare("INSERT INTO cf_access (actor_type, actor_id, overrides, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE overrides = VALUES(overrides), updated_at = NOW()")
        ->execute([$type, intval($id), $ov ? json_encode($ov) : null]);
}

// ---------------- تنظیماتِ شخصی ----------------
function cf_prefs($pdo, $type, $id) {
    cf_ensure($pdo);
    $st = $pdo->prepare("SELECT prefs FROM cf_prefs WHERE actor_type = ? AND actor_id = ?");
    $st->execute([$type, intval($id)]);
    return json_decode((string)$st->fetchColumn(), true) ?: [];
}
function cf_prefs_save($pdo, $type, $id, array $in) {
    $p = cf_prefs($pdo, $type, $id);
    $clip = function ($v, $lo, $hi, $def) { $v = is_numeric($v) ? 0 + $v : $def; return max($lo, min($hi, $v)); };
    if (isset($in['music']) && is_array($in['music'])) {
        $m = $in['music'];
        $p['music'] = ['source' => in_array($m['source'] ?? '', ['both', 'mine', 'panel'], true) ? $m['source'] : 'both',
                       'genres' => array_values(array_slice(array_filter(array_map(function ($g) { return mb_substr(trim((string)$g), 0, 60); }, (array)($m['genres'] ?? []))), 0, 40)),
                       'focus' => !empty($m['focus']), 'shuffle' => !empty($m['shuffle']), 'repeat' => in_array($m['repeat'] ?? '', ['off', 'all', 'one'], true) ? $m['repeat'] : 'all',
                       'vol' => $clip($m['vol'] ?? 0.7, 0, 1, 0.7), 'playlist' => intval($m['playlist'] ?? 0), 'liked' => !empty($m['liked'])];
    }
    if (isset($in['theme']) && is_array($in['theme'])) {
        $t = $in['theme'];
        $p['theme'] = ['mode' => in_array($t['mode'] ?? '', ['light', 'dark', 'auto'], true) ? $t['mode'] : 'light', 'font' => intval($clip($t['font'] ?? 100, 85, 130, 100)),
                       'accent' => preg_match('/^#[0-9a-f]{6}$/i', (string)($t['accent'] ?? '')) ? strtolower($t['accent']) : ''];
    }
    if (isset($in['health']) && is_array($in['health'])) {
        $h = $in['health'];
        $one = function ($x, $def) use ($clip) { $x = (array)$x; return ['on' => !empty($x['on']), 'min' => intval($clip($x['min'] ?? $def, 10, 240, $def))]; };
        $p['health'] = ['water' => $one($h['water'] ?? [], 60), 'stretch' => $one($h['stretch'] ?? [], 50), 'eyes' => $one($h['eyes'] ?? [], 20),
                        'work_hours' => !empty($h['work_hours']), 'sound' => !empty($h['sound'])];
    }
    if (isset($in['pomo']) && is_array($in['pomo'])) {
        $p['pomo'] = ['work' => intval($clip($in['pomo']['work'] ?? 25, 5, 120, 25)), 'brk' => intval($clip($in['pomo']['brk'] ?? 5, 1, 60, 5)), 'long' => intval($clip($in['pomo']['long'] ?? 15, 5, 60, 15))];
    }
    if (array_key_exists('dnd_until', $in)) $p['dnd_until'] = intval($in['dnd_until']) > time() ? intval($in['dnd_until']) : 0;
    if (isset($in['birth']) && is_array($in['birth'])) {
        $md = trim(p2e_digits((string)($in['birth']['md'] ?? '')));
        $p['birth'] = preg_match('/^(\d{1,2})[\/\-.](\d{1,2})$/', $md, $m) && $m[1] >= 1 && $m[1] <= 12 && $m[2] >= 1 && $m[2] <= 31
            ? ['md' => sprintf('%02d/%02d', $m[1], $m[2]), 'show' => !empty($in['birth']['show'])] : null;
    }
    if (isset($in['notify']) && is_array($in['notify'])) $p['notify'] = ['browser' => !empty($in['notify']['browser'])];
    if (isset($in['morning_seen'])) $p['morning_seen'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['morning_seen']) ? $in['morning_seen'] : null;
    $pdo->prepare("INSERT INTO cf_prefs (actor_type, actor_id, prefs, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs), updated_at = NOW()")
        ->execute([$type, intval($id), json_encode($p, JSON_UNESCAPED_UNICODE)]);
    return $p;
}

// ---------------- وضعیتِ من ----------------
function cf_status_table($type) { return ['S' => 'users', 'C' => 'company_portal_users'][$type] ?? null; }
function cf_status_set($pdo, $type, $id, $code, $text, $until) {
    $t = cf_status_table($type);
    if (!$t) return null;
    $code = isset(CF_STATUS[$code]) ? $code : '';
    $val = $code && $code !== 'ready' ? json_encode(['c' => $code, 't' => mb_substr(trim((string)$text), 0, 80), 'u' => intval($until) > time() ? intval($until) : 0], JSON_UNESCAPED_UNICODE) : null;
    $pdo->prepare("UPDATE `$t` SET cf_status = ? WHERE id = ?")->execute([$val, intval($id)]);
    return cf_status_decode($val);
}
function cf_status_decode($raw) { return prof_status_decode($raw); }

// ---------------- نام و برچسبِ آهنگ ----------------
// «دانلود آهنگ علی_رضایی - باران (320) [www.site.com]» → خواننده «علی رضایی»، عنوان «باران»
function cf_clean($s) {
    $s = preg_replace('/\.(mp3|m4a|aac|ogg|oga|opus|wav|flac|webm)$/i', '', (string)$s);
    $s = str_replace(['_', '–', '—', '|'], [' ', '-', '-', '-'], $s);
    $s = preg_replace('/[\(\[\{][^\)\]\}]*(\d{2,3}\s*k?bps|\b(128|192|256|320)\b|kbps|download|دانلود|کیفیت|www|\.com|\.ir)[^\)\]\}]*[\)\]\}]/iu', '', $s);
    $s = preg_replace('~https?://[^\s\[\]\(\)]+|www\.[^\s\[\]\(\)]+|[^\s\[\]\(\)]+\.(com|ir|net|org|info|me)\b~iu', '', $s);
    $s = preg_replace('/@\w+/u', '', $s);
    $s = preg_replace('/[\(\[\{]\s*[\)\]\}]/u', '', $s);
    $s = preg_replace('/\b(128|192|256|320)\s*(k|kbps)?\b/iu', '', $s);
    $s = preg_replace('/^\s*(دانلود\s+)?(آهنگ|موزیک|ترانه)\s+(جدید\s+)?/u', '', $s);
    $s = preg_replace('/\s{2,}/u', ' ', $s);
    $s = trim($s, " \t\n\r\0\x0B-.");
    $s = rtrim(ltrim($s, ')]} '), '([{ ');   // پرانتزِ بی‌جفت در دو سرِ نام
    return trim($s, " -.");
}
function cf_split_name($name) {
    $c = cf_clean($name);
    if (preg_match('/^(.{2,80}?)\s+-\s+(.{1,160})$/u', $c, $m)) return [trim($m[1]), trim($m[2])];
    return ['', $c !== '' ? $c : 'بی‌نام'];
}
// برچسبِ ID3 (نسخه‌ی ۲ و ۱) از فایلِ MP3
function cf_id3($path) {
    $out = [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return $out;
    $head = fread($fh, 10);
    if (strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
        $ver = ord($head[3]);
        $size = (ord($head[6]) << 21) | (ord($head[7]) << 14) | (ord($head[8]) << 7) | ord($head[9]);
        $data = fread($fh, min($size, 2 * 1024 * 1024));
        $pos = 0; $len = strlen($data);
        $map = $ver >= 3 ? ['TIT2' => 'title', 'TPE1' => 'artist', 'TCON' => 'genre', 'TALB' => 'album'] : ['TT2' => 'title', 'TP1' => 'artist', 'TCO' => 'genre', 'TAL' => 'album'];
        $idLen = $ver >= 3 ? 4 : 3; $hdLen = $ver >= 3 ? 10 : 6;
        while ($pos + $hdLen < $len) {
            $id = substr($data, $pos, $idLen);
            if (!preg_match('/^[A-Z0-9]+$/', $id)) break;
            if ($ver >= 3) {
                $b = substr($data, $pos + 4, 4);
                $fs = $ver === 4 ? ((ord($b[0]) << 21) | (ord($b[1]) << 14) | (ord($b[2]) << 7) | ord($b[3])) : unpack('N', $b)[1];
            } else {
                $b = substr($data, $pos + 3, 3);
                $fs = (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
            }
            if ($fs <= 0 || $pos + $hdLen + $fs > $len) break;
            if (isset($map[$id])) {
                $raw = substr($data, $pos + $hdLen, $fs);
                $enc = ord($raw[0]); $txt = substr($raw, 1);
                if ($enc === 1 || $enc === 2) {
                    $from = $enc === 1 ? 'UTF-16' : 'UTF-16BE';
                    $txt = @mb_convert_encoding($txt, 'UTF-8', $from);
                } elseif ($enc === 0) {
                    $txt = mb_check_encoding($txt, 'UTF-8') ? $txt : @mb_convert_encoding($txt, 'UTF-8', 'Windows-1256');
                }
                $txt = trim(str_replace("\0", ' ', (string)$txt));
                if ($txt !== '') $out[$map[$id]] = $txt;
            }
            $pos += $hdLen + $fs;
        }
    }
    if (count($out) < 2) {
        // ID3v1 در ۱۲۸ بایتِ آخر
        fseek($fh, -128, SEEK_END);
        $t = fread($fh, 128);
        if (strlen($t) === 128 && substr($t, 0, 3) === 'TAG') {
            $f = function ($s) { $s = trim(str_replace("\0", '', $s)); return mb_check_encoding($s, 'UTF-8') ? $s : @mb_convert_encoding($s, 'UTF-8', 'Windows-1256'); };
            if (empty($out['title'])) $out['title'] = $f(substr($t, 3, 30));
            if (empty($out['artist'])) $out['artist'] = $f(substr($t, 33, 30));
            if (empty($out['album'])) $out['album'] = $f(substr($t, 63, 30));
        }
    }
    fclose($fh);
    // ژانرِ عددی مثلِ «(13)» به کار نمی‌آید
    if (isset($out['genre']) && preg_match('/^\(?\d+\)?$/', $out['genre'])) unset($out['genre']);
    return array_filter($out, function ($v) { return trim((string)$v) !== ''; });
}
function cf_sniff_audio($path, $ext) {
    $h = (string)@file_get_contents($path, false, null, 0, 16);
    if ($h === '') return false;
    switch ($ext) {
        case 'mp3': return substr($h, 0, 3) === 'ID3' || (ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0);
        case 'm4a': return substr($h, 4, 4) === 'ftyp';
        case 'aac': return substr($h, 0, 3) === 'ID3' || (ord($h[0]) === 0xFF && (ord($h[1]) & 0xF0) === 0xF0) || substr($h, 0, 4) === 'ADIF';
        case 'ogg': case 'oga': case 'opus': return substr($h, 0, 4) === 'OggS';
        case 'wav': return substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WAVE';
        case 'flac': return substr($h, 0, 4) === 'fLaC' || substr($h, 0, 3) === 'ID3';
        case 'webm': return substr($h, 0, 4) === "\x1A\x45\xDF\xA3";
    }
    return false;
}

// ---------------- ژانرها و سقفِ حجم ----------------
function cf_setting($pdo, $key, $def = null) {
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $st->execute([$key]); $v = $st->fetchColumn(); return $v === false ? $def : $v; }
    catch (Throwable $e) { return $def; }
}
function cf_setting_set($pdo, $key, $val) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $val]);
}
function cf_genres($pdo) {
    $g = json_decode((string)cf_setting($pdo, 'cf_genres', ''), true);
    return is_array($g) && $g ? array_values($g) : CF_DEFAULT_GENRES;
}
function cf_quota_bytes($pdo) { return max(10, intval(cf_setting($pdo, 'cf_quota_mb', 250))) * 1024 * 1024; }
// مصرفِ هر نفر = حجمِ آهنگ‌هایش منهای مقداری که مدیر «ریست» کرده (آهنگ‌ها پاک نمی‌شوند)
function cf_quota_state($pdo, $type, $id) {
    cf_ensure($pdo);
    $st = $pdo->prepare("SELECT COALESCE(SUM(size), 0) FROM cf_tracks WHERE owner_type = ? AND owner_id = ?");
    $st->execute([$type, intval($id)]);
    $total = intval($st->fetchColumn());
    $st = $pdo->prepare("SELECT base_bytes, reset_at FROM cf_quota WHERE actor_type = ? AND actor_id = ?");
    $st->execute([$type, intval($id)]);
    $q = $st->fetch() ?: ['base_bytes' => 0, 'reset_at' => null];
    $limit = cf_quota_bytes($pdo);
    $used = max(0, $total - intval($q['base_bytes']));
    return ['total' => $total, 'used' => $used, 'limit' => $limit, 'left' => max(0, $limit - $used), 'reset_at' => $q['reset_at']];
}

// ---------------- افزودنِ آهنگ ----------------
// $owner: ['P', 0] برای کتابخانه‌ی پنل یا [نوع، شناسه] برای آهنگِ شخصی. خروجی: ['ok'=>true,'track'=>..] یا ['ok'=>false,'error'=>..,'skip'=>true]
function cf_add_track($pdo, $srcPath, $origName, array $owner, array $uploader, $genre = '') {
    cf_ensure($pdo);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!isset(CF_AUDIO[$ext])) return ['ok' => false, 'skip' => true, 'error' => 'قالبِ فایل پشتیبانی نمی‌شود (' . $origName . ').'];
    $size = filesize($srcPath);
    if ($size <= 1024) return ['ok' => false, 'skip' => true, 'error' => 'فایل خالی است (' . $origName . ').'];
    if ($size > CF_MAX_TRACK) return ['ok' => false, 'error' => 'حجمِ «' . $origName . '» بیشتر از ۶۰ مگابایت است.'];
    if (!cf_sniff_audio($srcPath, $ext)) return ['ok' => false, 'error' => '«' . $origName . '» فایلِ صوتیِ معتبر نیست.'];
    $sha = sha1_file($srcPath);
    $st = $pdo->prepare("SELECT title FROM cf_blocked WHERE sha1 = ?");
    $st->execute([$sha]);
    if ($st->fetchColumn() !== false) return ['ok' => false, 'error' => '«' . $origName . '» توسطِ مدیر حذف و مسدود شده و دوباره قابلِ آپلود نیست.'];
    $st = $pdo->prepare("SELECT owner_type, owner_id, title FROM cf_tracks WHERE sha1 = ?");
    $st->execute([$sha]);
    foreach ($st->fetchAll() as $d) {
        if ($d['owner_type'] === $owner[0] && intval($d['owner_id']) === intval($owner[1])) return ['ok' => false, 'skip' => true, 'dup' => true, 'error' => '«' . $origName . '» قبلاً آپلود شده است.'];
        if ($d['owner_type'] === 'P' && $owner[0] !== 'P') return ['ok' => false, 'skip' => true, 'dup' => true, 'error' => '«' . $d['title'] . '» در کتابخانه‌ی پنل هست؛ لازم نیست آپلودش کنید.'];
    }
    if ($owner[0] !== 'P') {
        $q = cf_quota_state($pdo, $owner[0], $owner[1]);
        if ($size > $q['left']) return ['ok' => false, 'quota' => true, 'error' => 'سقفِ حجمِ آپلودِ شما (' . intval($q['limit'] / 1048576) . ' مگابایت) پر شده است؛ برای افزایش به مدیر بگویید.'];
    }
    $tag = $ext === 'mp3' ? cf_id3($srcPath) : [];
    [$fa, $ft] = cf_split_name(basename($origName));
    $artist = cf_clean($tag['artist'] ?? '') ?: $fa;
    $title = cf_clean($tag['title'] ?? '') ?: $ft;
    // اگر برچسب فقط عنوان داشت و نامِ فایل «خواننده - عنوان» بود
    if ($artist === '' && $fa !== '') $artist = $fa;
    if ($title === $artist && $ft !== '') $title = $ft;
    $genre = trim((string)$genre);
    if ($genre === '' || $genre === 'auto') $genre = cf_match_genre($pdo, $tag['genre'] ?? '');
    $file = $sha . '.' . $ext;
    $dest = cf_music_path($file);
    if (!is_file($dest) && !@copy($srcPath, $dest)) return ['ok' => false, 'error' => 'فایل ذخیره نشد (دسترسیِ نوشتن در uploads/music).'];
    $pdo->prepare("INSERT INTO cf_tracks (owner_type, owner_id, uploader_type, uploader_id, title, artist, genre, album, orig_name, file, mime, size, sha1, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$owner[0], intval($owner[1]), $uploader[0], intval($uploader[1]), mb_substr($title, 0, 200), mb_substr($artist, 0, 160) ?: null, mb_substr($genre, 0, 60),
                   mb_substr($tag['album'] ?? '', 0, 160) ?: null, mb_substr($origName, 0, 255), $file, CF_AUDIO[$ext], $size, $sha]);
    return ['ok' => true, 'track' => cf_track_out($pdo, ['id' => $pdo->lastInsertId(), 'owner_type' => $owner[0], 'owner_id' => $owner[1], 'title' => $title, 'artist' => $artist,
                                                        'genre' => $genre, 'size' => $size, 'mime' => CF_AUDIO[$ext], 'duration' => null, 'uploader_type' => $uploader[0], 'uploader_id' => $uploader[1], 'created_at' => date('Y-m-d H:i:s')])];
}
// ژانرِ برچسب را به یکی از ژانرهای پنل نزدیک می‌کند
function cf_match_genre($pdo, $g) {
    $g = mb_strtolower(trim((string)$g));
    $list = cf_genres($pdo);
    if ($g === '') return in_array('سایر', $list, true) ? 'سایر' : end($list);
    foreach ($list as $x) if (mb_strtolower($x) === $g) return $x;
    $map = ['pop' => 'خارجی', 'rock' => 'خارجی', 'jazz' => 'خارجی', 'classical' => 'کلاسیک', 'instrumental' => 'بی‌کلام', 'ambient' => 'طبیعت و آرامش',
            'new age' => 'طبیعت و آرامش', 'soundtrack' => 'بی‌کلام', 'persian' => 'پاپ ایرانی', 'iranian' => 'پاپ ایرانی', 'traditional' => 'سنتی', 'سنتی' => 'سنتی', 'پاپ' => 'پاپ ایرانی'];
    foreach ($map as $k => $v) if (mb_strpos($g, $k) !== false && in_array($v, $list, true)) return $v;
    return in_array('سایر', $list, true) ? 'سایر' : end($list);
}
function cf_track_out($pdo, $r) {
    return ['id' => intval($r['id']), 'title' => (string)$r['title'], 'artist' => (string)($r['artist'] ?? ''), 'genre' => (string)($r['genre'] ?? ''),
            'owner' => $r['owner_type'] === 'P' ? 'panel' : 'user', 'owner_type' => $r['owner_type'], 'owner_id' => intval($r['owner_id']),
            'size' => intval($r['size']), 'mime' => $r['mime'], 'duration' => $r['duration'] !== null ? intval($r['duration']) : null,
            'uploader' => $r['uploader_type'] ? cf_actor_name($pdo, $r['uploader_type'], $r['uploader_id']) : '', 'created_at' => $r['created_at'] ?? null,
            'plays' => intval($r['plays'] ?? 0)];
}
// حذفِ کاملِ یک آهنگ؛ $block: دیگر قابلِ آپلود نباشد (حذف توسطِ مدیر)
function cf_delete_track($pdo, $id, $block, $by = null) {
    $st = $pdo->prepare("SELECT * FROM cf_tracks WHERE id = ?");
    $st->execute([intval($id)]);
    $t = $st->fetch();
    if (!$t) return false;
    if ($block) {
        // همه‌ی نسخه‌های همین فایل (پنل و شخصی) پاک و مسدود می‌شوند
        $pdo->prepare("INSERT IGNORE INTO cf_blocked (sha1, title, blocked_at, blocked_by) VALUES (?, ?, NOW(), ?)")->execute([$t['sha1'], trim(($t['artist'] ? $t['artist'] . ' - ' : '') . $t['title']), $by]);
        $ids = $pdo->prepare("SELECT id FROM cf_tracks WHERE sha1 = ?");
        $ids->execute([$t['sha1']]);
        $all = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    } else $all = [intval($t['id'])];
    $in = implode(',', $all);
    $pdo->exec("DELETE FROM cf_tracks WHERE id IN ($in)");
    $pdo->exec("DELETE FROM cf_likes WHERE track_id IN ($in)");
    $left = $pdo->prepare("SELECT COUNT(*) FROM cf_tracks WHERE sha1 = ?");
    $left->execute([$t['sha1']]);
    if (!intval($left->fetchColumn())) @unlink(cf_music_path($t['file']));
    return true;
}

// ---------------- تولد و مناسبت‌ها ----------------
const CF_OCCASIONS = ['01/01' => 'آغازِ نوروز', '01/02' => 'تعطیلاتِ نوروز', '01/03' => 'تعطیلاتِ نوروز', '01/04' => 'تعطیلاتِ نوروز', '01/12' => 'روزِ جمهوریِ اسلامی',
                      '01/13' => 'روزِ طبیعت', '02/25' => 'روزِ بزرگداشتِ فردوسی', '03/14' => 'رحلتِ امام خمینی', '03/15' => 'قیامِ ۱۵ خرداد', '07/08' => 'روزِ بزرگداشتِ مولوی',
                      '07/20' => 'روزِ بزرگداشتِ حافظ', '09/30' => 'شبِ یلدا', '10/01' => 'آغازِ زمستان', '11/22' => 'پیروزیِ انقلاب اسلامی', '12/29' => 'روزِ ملی شدنِ صنعتِ نفت',
                      '06/31' => 'آخرین روزِ تابستان', '04/01' => 'آغازِ تابستان', '07/01' => 'آغازِ پاییز', '02/12' => 'روزِ معلم', '08/13' => 'روزِ دانش‌آموز'];
function cf_today_md() { [$y, $m, $d] = jalali_from_gregorian_ts(time()); return [$y, sprintf('%02d/%02d', $m, $d)]; }
// تولدهای امروز و ۷ روزِ آینده (فقط کسانی که اجازه‌ی نمایش داده‌اند)
function cf_birthdays($pdo) {
    cf_ensure($pdo);
    $rows = $pdo->query("SELECT p.actor_id, p.prefs, u.full_name FROM cf_prefs p JOIN users u ON u.id = p.actor_id WHERE p.actor_type = 'S'" . (cf_col_exists($pdo, 'users', 'is_deleted') ? " AND COALESCE(u.is_deleted, 0) = 0" : ''))->fetchAll();
    $out = [];
    for ($i = 0; $i < 8; $i++) {
        [, $m, $d] = jalali_from_gregorian_ts(time() + $i * 86400);
        $md = sprintf('%02d/%02d', $m, $d);
        foreach ($rows as $r) {
            $p = json_decode((string)$r['prefs'], true) ?: [];
            if (!empty($p['birth']['md']) && $p['birth']['md'] === $md && !empty($p['birth']['show'])) $out[] = ['user_id' => intval($r['actor_id']), 'name' => $r['full_name'], 'md' => $md, 'in_days' => $i];
        }
    }
    return $out;
}
