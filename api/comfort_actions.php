<?php
// فایل: api/comfort_actions.php
// «امکاناتِ رفاهی» (comfort.js): برای کاربرانِ پنل و (اگر مدیر روشن کرده باشد) کاربرانِ پنلِ شرکت‌ها (?portal=1).
// پخش‌کننده‌ی موسیقی و کتابخانه، کارهای امروز، متن‌های آماده، وضعیت، تنظیماتِ شخصی، جستجوی سراسری، پیامِ صبح‌بخیر،
// و بخشِ مدیر: کتابخانه‌ی پنل (آپلودِ تکی و زیپ)، آهنگ‌های کاربران، سقفِ حجم، مسدودها و دسترسیِ هر نفر.
if (($_GET['portal'] ?? '') === '1') session_name('bime_company_portal');
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php';
require_once __DIR__ . '/_comfort.php';

function cfo($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$A = cf_actor();
if (!$A) { http_response_code(401); cfo(['ok' => false, 'error' => 'ابتدا وارد شوید.', 'session_expired' => true]); }
if ($A[0] === 'C') {
    // کاربرِ شرکتِ غیرفعال/حذف‌شده
    $st = $pdo->prepare("SELECT is_active, is_deleted FROM company_portal_users WHERE id = ?");
    $st->execute([$A[1]]);
    $cu = $st->fetch();
    if (!$cu || empty($cu['is_active']) || !empty($cu['is_deleted'])) cfo(['ok' => false, 'error' => 'حساب غیرفعال است.']);
}
cf_ensure($pdo);
$raw = json_decode(file_get_contents('php://input'), true) ?: [];
$data = $raw + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
[$AT, $AID] = [$A[0], $A[1]];
// آپلود/باز کردنِ زیپ طول می‌کشد؛ قفلِ نشست آزاد می‌شود تا بقیه‌ی صفحه‌های پنل در این فاصله کُند نشوند
if (in_array($action, ['up_chunk', 'zip_step', 'up_jobs', 'up_cancel', 'up_ack'], true) && session_status() === PHP_SESSION_ACTIVE) session_write_close();
$F = cf_features($pdo, $AT, $AID);
$isAdmin = cf_is_admin($A);
$need = function ($k) use ($F) { if (empty($F[$k])) cfo(['ok' => false, 'error' => 'این امکان برای شما فعال نیست.', 'disabled' => true]); };
$jd = function ($s) { // «۱۴۰۵/۰۷/۱۲» → تاریخِ میلادی
    if (!preg_match('/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', trim(p2e_digits((string)$s)), $m)) return null;
    $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]));
    return $ts ? date('Y-m-d', $ts) : null;
};
$j = function ($g) { if (!$g) return ''; [$y, $m, $d] = jalali_from_gregorian_ts(strtotime(substr($g, 0, 10) . ' 12:00:00')); return sprintf('%04d/%02d/%02d', $y, $m, $d); };
// دسترسیِ صفحه‌ای کاربرِ پنل (برای جستجو و خلاصه‌ی صبح)
$canPage = function ($page) use ($pdo, $A) {
    if ($A[0] !== 'S') return false;
    if ($A[3] === 'ADMIN') return true;
    $p = perm_user_custom($pdo, $A[1]);
    if ($p === null) $p = perm_role_defaults($A[3]);
    return in_array('view', $p[$page] ?? [], true);
};

$todoOut = function ($r) use ($j) {
    return ['id' => intval($r['id']), 'text' => $r['text'], 'due' => $r['due_date'] ? $j($r['due_date']) : '', 'due_g' => $r['due_date'],
            'remind_at' => $r['remind_at'] ? strtotime($r['remind_at']) : 0, 'remind_j' => $r['remind_at'] ? $j($r['remind_at']) . ' ' . substr($r['remind_at'], 11, 5) : '',
            'reminded' => (bool)$r['reminded'], 'done' => $r['done_at'] !== null, 'done_at' => $r['done_at']];
};
$todos = function () use ($pdo, $AT, $AID, $todoOut) {
    // انجام‌شده‌های بیش از ۷ روز پیش دیگر نشان داده نمی‌شوند
    $st = $pdo->prepare("SELECT * FROM cf_todos WHERE actor_type = ? AND actor_id = ? AND (done_at IS NULL OR done_at >= NOW() - INTERVAL 7 DAY)
                         ORDER BY done_at IS NOT NULL, COALESCE(due_date, '9999-12-31'), id DESC LIMIT 300");
    $st->execute([$AT, $AID]);
    return array_map($todoOut, $st->fetchAll());
};
$canned = function () use ($pdo, $AT, $AID) {
    $st = $pdo->prepare("SELECT * FROM cf_canned WHERE is_global = 1 OR (actor_type = ? AND actor_id = ?) ORDER BY is_global DESC, title");
    $st->execute([$AT, $AID]);
    return array_map(function ($r) use ($AT, $AID) { return ['id' => intval($r['id']), 'title' => $r['title'], 'body' => $r['body'], 'global' => (bool)$r['is_global'],
        'mine' => $r['actor_type'] === $AT && intval($r['actor_id']) === $AID]; }, $st->fetchAll());
};
$myStatus = function () use ($pdo, $AT, $AID) {
    $t = cf_status_table($AT);
    if (!$t) return null;
    try { $st = $pdo->prepare("SELECT cf_status FROM `$t` WHERE id = ?"); $st->execute([$AID]); return cf_status_decode($st->fetchColumn()); } catch (Throwable $e) { return null; }
};

try {
    // ================= شروع =================
    if ($action === 'boot') {
        [$jy, $md] = cf_today_md();
        $out = ['ok' => true, 'kind' => $AT, 'name' => $A[2], 'is_admin' => $isAdmin, 'features' => $F, 'catalog' => array_map(function ($f) { return $f[0]; }, CF_FEATURES),
                'prefs' => cf_prefs($pdo, $AT, $AID), 'genres' => cf_genres($pdo), 'focus_genres' => CF_FOCUS_GENRES, 'today' => date('Y-m-d'), 'now' => time(),
                'today_j' => sprintf('%04d/%s', $jy, $md), 'occasion' => CF_OCCASIONS[$md] ?? null, 'statuses' => array_map(function ($s) { return ['label' => $s[0], 'icon' => $s[1]]; }, CF_STATUS)];
        if (!empty($F['status']) || !empty($F['dnd'])) $out['status'] = $myStatus();
        if (!empty($F['todo']) || !empty($F['morning'])) $out['todos'] = $todos();
        if (!empty($F['canned'])) $out['canned'] = $canned();
        if (!empty($F['music_upload'])) $out['quota'] = cf_quota_state($pdo, $AT, $AID);
        if ($AT === 'S' && !empty($F['birthdays'])) $out['birthdays'] = cf_birthdays($pdo);
        if ($AT === 'S') {
            $out['work_hours'] = ['start' => (string)cf_setting($pdo, 'work_start', '08:00'), 'end' => (string)cf_setting($pdo, 'work_end', '16:00'), 'offdays' => (string)cf_setting($pdo, 'work_offdays', '5')];
        }
        cfo($out);
    }
    if ($action === 'prefs_save') {
        cfo(['ok' => true, 'prefs' => cf_prefs_save($pdo, $AT, $AID, (array)($data['prefs'] ?? []))]);
    }
    if ($action === 'status_set') {
        if (empty($F['status']) && empty($F['dnd'])) $need('status');
        cfo(['ok' => true, 'status' => cf_status_set($pdo, $AT, $AID, (string)($data['code'] ?? ''), (string)($data['text'] ?? ''), intval($data['until'] ?? 0))]);
    }

    // ================= موسیقی =================
    if ($action === 'music_list') {
        $need('music');
        $st = $pdo->prepare("SELECT * FROM cf_tracks WHERE owner_type = 'P' OR (owner_type = ? AND owner_id = ?) ORDER BY owner_type = 'P' DESC, genre, artist, title");
        $st->execute([$AT, $AID]);
        $tracks = array_map(function ($r) use ($pdo) { return cf_track_out($pdo, $r); }, $st->fetchAll());
        // قانون: آهنگی که هر کاربر آپلود می‌کند فقط برای خودش و مدیر قابلِ دیدن و پخش است تا مدیر «انتشار برای همه» بزند
        $pending = 0;
        if ($isAdmin) {
            $st = $pdo->prepare("SELECT * FROM cf_tracks WHERE owner_type <> 'P' AND NOT (owner_type = ? AND owner_id = ?) ORDER BY id DESC LIMIT 1000");
            $st->execute([$AT, $AID]);
            foreach ($st->fetchAll() as $r) { $tracks[] = ['owner' => 'other', 'owner_name' => cf_actor_name($pdo, $r['owner_type'], $r['owner_id'])] + cf_track_out($pdo, $r); $pending++; }
        }
        $st = $pdo->prepare("SELECT track_id FROM cf_likes WHERE actor_type = ? AND actor_id = ?");
        $st->execute([$AT, $AID]);
        $likes = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $st = $pdo->prepare("SELECT * FROM cf_playlists WHERE actor_type = ? AND actor_id = ? ORDER BY name");
        $st->execute([$AT, $AID]);
        $pls = array_map(function ($r) { return ['id' => intval($r['id']), 'name' => $r['name'], 'tracks' => array_values(array_filter(array_map('intval', explode(',', (string)$r['track_ids']))))]; }, $st->fetchAll());
        cfo(['ok' => true, 'tracks' => $tracks, 'likes' => $likes, 'playlists' => $pls, 'genres' => cf_genres($pdo),
             'quota' => !empty($F['music_upload']) ? cf_quota_state($pdo, $AT, $AID) : null, 'can_upload' => !empty($F['music_upload']),
             'is_admin' => $isAdmin, 'pending' => $pending]);
    }
    // مدیر: آهنگ‌های کاربران برای همه پخش شوند
    if ($action === 'music_publish') {
        if (!$isAdmin) cfo(['ok' => false, 'error' => 'فقط مدیر کل.']);
        $n = 0;
        foreach (array_filter(array_map('intval', (array)($data['ids'] ?? [$data['id'] ?? 0]))) as $id) if (cf_publish_track($pdo, $id)) $n++;
        cfo(['ok' => true, 'published' => $n]);
    }
    if ($action === 'stream') {
        if (empty($F['music']) && !$isAdmin) { http_response_code(403); exit; }
        $st = $pdo->prepare("SELECT * FROM cf_tracks WHERE id = ?");
        $st->execute([intval($data['id'] ?? 0)]);
        $t = $st->fetch();
        if (!$t || !($t['owner_type'] === 'P' || ($t['owner_type'] === $AT && intval($t['owner_id']) === $AID) || $isAdmin)) { http_response_code(404); exit; }
        $path = cf_music_path($t['file']);
        if (!is_file($path)) { http_response_code(404); exit; }
        session_write_close();   // پخشِ طولانی نباید بقیه‌ی درخواست‌های همین کاربر را معطل کند
        while (ob_get_level()) ob_end_clean();
        @set_time_limit(0);
        $size = filesize($path); $start = 0; $end = $size - 1;
        header('Content-Type: ' . $t['mime']);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - intval($m[2])); }
            else { $start = intval($m[1]); if ($m[2] !== '') $end = min($end, intval($m[2])); }
            if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh) && !connection_aborted()) { $buf = fread($fh, min(262144, $left)); echo $buf; flush(); $left -= strlen($buf); }
        fclose($fh);
        exit;
    }
    if ($action === 'played') {
        $need('music');
        $pdo->prepare("UPDATE cf_tracks SET plays = plays + 1, duration = COALESCE(duration, ?) WHERE id = ?")->execute([intval($data['duration'] ?? 0) ?: null, intval($data['id'] ?? 0)]);
        cfo(['ok' => true]);
    }
    if ($action === 'like') {
        $need('music');
        $id = intval($data['id'] ?? 0);
        if (!empty($data['on'])) $pdo->prepare("INSERT IGNORE INTO cf_likes (actor_type, actor_id, track_id, created_at) VALUES (?, ?, ?, NOW())")->execute([$AT, $AID, $id]);
        else $pdo->prepare("DELETE FROM cf_likes WHERE actor_type = ? AND actor_id = ? AND track_id = ?")->execute([$AT, $AID, $id]);
        cfo(['ok' => true]);
    }
    if ($action === 'pl_save') {
        $need('music');
        $name = trim(mb_substr((string)($data['name'] ?? ''), 0, 120));
        if ($name === '') cfo(['ok' => false, 'error' => 'نامِ پلی‌لیست را وارد کنید.']);
        $ids = implode(',', array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($data['tracks'] ?? []))))), 0, 500));
        $id = intval($data['id'] ?? 0);
        if ($id) $pdo->prepare("UPDATE cf_playlists SET name = ?, track_ids = ? WHERE id = ? AND actor_type = ? AND actor_id = ?")->execute([$name, $ids, $id, $AT, $AID]);
        else { $pdo->prepare("INSERT INTO cf_playlists (actor_type, actor_id, name, track_ids, created_at) VALUES (?, ?, ?, ?, NOW())")->execute([$AT, $AID, $name, $ids]); $id = intval($pdo->lastInsertId()); }
        cfo(['ok' => true, 'id' => $id]);
    }
    if ($action === 'pl_delete') {
        $need('music');
        $pdo->prepare("DELETE FROM cf_playlists WHERE id = ? AND actor_type = ? AND actor_id = ?")->execute([intval($data['id'] ?? 0), $AT, $AID]);
        cfo(['ok' => true]);
    }
    // آهنگِ خودم: ویرایشِ نام/ژانر و حذف
    if ($action === 'my_track_update' || $action === 'my_track_delete') {
        $need('music');
        $id = intval($data['id'] ?? 0);
        $st = $pdo->prepare("SELECT id FROM cf_tracks WHERE id = ? AND owner_type = ? AND owner_id = ?");
        $st->execute([$id, $AT, $AID]);
        if (!$st->fetchColumn()) cfo(['ok' => false, 'error' => 'این آهنگ مالِ شما نیست.']);
        if ($action === 'my_track_delete') { cf_delete_track($pdo, $id, false); cfo(['ok' => true, 'quota' => cf_quota_state($pdo, $AT, $AID)]); }
        $pdo->prepare("UPDATE cf_tracks SET title = ?, artist = ?, genre = ? WHERE id = ?")
            ->execute([mb_substr(trim((string)($data['title'] ?? '')), 0, 200) ?: 'بی‌نام', mb_substr(trim((string)($data['artist'] ?? '')), 0, 160) ?: null, mb_substr(trim((string)($data['genre'] ?? '')), 0, 60) ?: 'سایر', $id]);
        cfo(['ok' => true]);
    }

    // ================= آپلودِ تکه‌تکه با قابلیتِ ادامه =================
    // هر تکه با offset می‌آید؛ اگر با حجمِ فایلِ نیمه‌کاره نخواند، سرور حجمِ واقعی را برمی‌گرداند تا مرورگر از همان‌جا ادامه دهد
    // (رفتن به صفحه‌ی دیگر / قطعِ شبکه / تازه‌سازیِ صفحه). زیپ بعد از رسیدن کامل، قدم‌به‌قدم باز می‌شود (zip_step) تا پیشرفت دیده شود
    // و درخواستِ طولانی قطع نشود.
    $upDir = cf_music_path() . '/_tmp';
    $upFile = function ($uid, $ext) use ($upDir, $AT, $AID) { return $upDir . '/' . $AT . $AID . '_' . $uid . '.' . $ext; };
    $upUid = function () use ($data) {
        $uid = (string)($data['upload_id'] ?? '');
        if (!preg_match('/^[a-z0-9]{8,40}$/', $uid)) cfo(['ok' => false, 'error' => 'شناسه‌ی آپلود نامعتبر است.']);
        return $uid;
    };
    $upJson = function ($path) { $j = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null; return is_array($j) ? $j : null; };
    $upClean = function ($uid) use ($upFile) { foreach (['part', 'meta', 'job'] as $x) @unlink($upFile($uid, $x)); };
    // گزارشِ پایانِ آپلود تا یک روز می‌ماند: اگر کاربر وسطِ کار صفحه را بست/تازه کرد، با برگشتن نتیجه را می‌بیند (up_ack پاکش می‌کند)
    $upFinish = function ($uid, $name, $added, $skipped, $errors, $target = 'panel') use ($upFile, $upClean) {
        $upClean($uid);
        @file_put_contents($upFile($uid, 'done'), json_encode(['name' => $name, 'target' => $target, 'added' => $added, 'skipped' => $skipped, 'errors' => $errors, 'at' => time()], JSON_UNESCAPED_UNICODE));
    };
    $upCanTarget = function ($target) use ($isAdmin, $need) {
        if ($target === 'panel' && !$isAdmin) cfo(['ok' => false, 'error' => 'آپلود در کتابخانه‌ی پنل فقط کارِ مدیر کل است.']);
        if ($target === 'mine') $need('music_upload');
    };
    // یک ورودیِ زیپ را به آهنگ تبدیل می‌کند؛ ژانر: انتخابِ مدیر، یا اگر «خودکار» بود نامِ پوشه‌ی داخلِ زیپ، وگرنه برچسبِ آهنگ
    $trackMax = $isAdmin ? CF_MAX_TRACK_ADMIN : CF_MAX_TRACK;   // سقفِ هر آهنگ: مدیرِ کل ۱۵۰، بقیه ۶۰ مگابایت
    $zipEntry = function (ZipArchive $z, $i, array &$job, array &$res, array $genres) use ($pdo, $AT, $AID, $upDir, $trackMax) {
        $stt = $z->statIndex($i, ZipArchive::FL_ENC_RAW);
        $en = (string)$stt['name'];
        if (!mb_check_encoding($en, 'UTF-8')) { $u = $z->getNameIndex($i); $en = mb_check_encoding((string)$u, 'UTF-8') ? $u : @mb_convert_encoding($en, 'UTF-8', 'CP1256'); }
        if (substr($en, -1) === '/' || strpos($en, '__MACOSX') !== false) return;
        $base = basename($en);
        if ($base === '' || $base[0] === '.') return;
        $e2 = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (!isset(CF_AUDIO[$e2])) { if (!in_array($e2, ['jpg', 'jpeg', 'png', 'txt', 'nfo', 'url', 'm3u', 'db', 'ini', 'lrc'], true)) $res['skipped'][] = '«' . $base . '» فایلِ صوتی نیست.'; return; }
        $job['audio_done'] = intval($job['audio_done'] ?? 0) + 1;
        if ($stt['size'] > $trackMax) { $res['errors'][] = '«' . $base . '» بیشتر از ' . cf_max_mb_fa($trackMax) . ' مگابایت است.'; return; }
        $tmp = $upDir . '/z_' . $job['uid'] . '_' . $i . '.' . $e2;
        $src = $z->getStream($z->getNameIndex($i));
        if (!$src) { $res['errors'][] = '«' . $base . '» خوانده نشد.'; return; }
        $dst = fopen($tmp, 'wb'); stream_copy_to_stream($src, $dst, $trackMax + 1); fclose($dst); fclose($src);
        $g = $job['genre'];
        if ($g === '' || $g === 'auto') {
            $dir = trim(dirname($en), './');
            $folder = $dir !== '' ? cf_clean(basename($dir)) : '';
            if ($folder !== '' && mb_strlen($folder) <= 40) {
                $g = $folder;
                if (!in_array($g, $genres, true) && !in_array($g, $job['new_genres'], true)) $job['new_genres'][] = $g;
            } else $g = 'auto';
        }
        $r = cf_add_track($pdo, $tmp, $base, $job['owner'], [$AT, $AID], $g, $trackMax);
        @unlink($tmp);
        if ($r['ok']) $res['added'][] = $r['track']; elseif (!empty($r['skip'])) $res['skipped'][] = $r['error']; else $res['errors'][] = $r['error'];
    };

    if ($action === 'up_chunk') {
        $target = ($data['target'] ?? '') === 'panel' ? 'panel' : 'mine';
        $upCanTarget($target);
        $uid = $upUid();
        $name = basename(str_replace('\\', '/', (string)($data['name'] ?? '')));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isZip = $ext === 'zip';
        if ($isZip && !$isAdmin) cfo(['ok' => false, 'error' => 'آپلودِ زیپ فقط برای مدیر کل است؛ آهنگ‌ها را تکی انتخاب کنید.']);
        if (!$isZip && !isset(CF_AUDIO[$ext])) cfo(['ok' => false, 'error' => 'فقط فایلِ صوتی (MP3، M4A، OGG، WAV، FLAC…) ' . ($isAdmin ? 'یا ZIP ' : '') . 'قبول می‌شود.']);
        $idx = intval($data['index'] ?? 0); $total = intval($data['total'] ?? 1);
        $off = isset($data['offset']) && $data['offset'] !== '' ? intval($data['offset']) : null;
        $f = $_FILES['chunk'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) cfo(['ok' => false, 'error' => 'تکه‌ی فایل نرسید؛ دوباره امتحان کنید.']);
        if ($f['size'] > 3 * 1024 * 1024) cfo(['ok' => false, 'error' => 'تکه‌ی فایل بزرگ است.']);
        $max = $isZip ? CF_MAX_ZIP : $trackMax;
        $size = intval($data['size'] ?? 0);
        if ($size > $max) cfo(['ok' => false, 'error' => $isZip ? 'زیپ بزرگ‌تر از ۱ گیگابایت است.' : 'هر آهنگ حداکثر ' . cf_max_mb_fa($trackMax) . ' مگابایت.']);
        $part = $upFile($uid, 'part');
        if ($idx === 0 && !$off) {
            $upClean($uid);
            @file_put_contents($upFile($uid, 'meta'), json_encode(['name' => $name, 'size' => $size, 'target' => $target, 'genre' => (string)($data['genre'] ?? ''),
                                                                 'lm' => (string)($data['lm'] ?? ''), 'at' => time()], JSON_UNESCAPED_UNICODE));
        } elseif (!is_file($part)) cfo(['ok' => false, 'resync' => true, 'have' => 0, 'error' => 'آپلود از اول شروع نشده.']);
        clearstatcache();
        $have = is_file($part) ? filesize($part) : 0;
        if ($off !== null && $have !== $off) cfo(['ok' => false, 'resync' => true, 'have' => $have, 'error' => 'جای ادامه‌ی آپلود عوض شده.']);
        $out = fopen($part, 'ab');
        fwrite($out, file_get_contents($f['tmp_name']));
        fclose($out);
        clearstatcache();
        if (filesize($part) > $max) { $upClean($uid); cfo(['ok' => false, 'error' => $isZip ? 'زیپ بزرگ‌تر از ۱ گیگابایت است.' : 'هر آهنگ حداکثر ' . cf_max_mb_fa($trackMax) . ' مگابایت.']); }
        if ($idx + 1 < $total) cfo(['ok' => true, 'next' => $idx + 1, 'have' => filesize($part)]);
        // تکه‌ی آخر
        @set_time_limit(0);
        $owner = $target === 'panel' ? ['P', 0] : [$AT, $AID];
        $genre = trim((string)($data['genre'] ?? ''));
        if (!$isZip) {
            $res = ['added' => [], 'skipped' => [], 'errors' => []];
            $r = cf_add_track($pdo, $part, $name, $owner, [$AT, $AID], $genre, $trackMax);
            if ($r['ok']) $res['added'][] = $r['track']; elseif (!empty($r['skip'])) $res['skipped'][] = $r['error']; else $res['errors'][] = $r['error'];
            $upFinish($uid, $name, count($res['added']), count($res['skipped']), count($res['errors']), $target);
            cfo(['ok' => true, 'done' => true] + $res + ['quota' => $owner[0] !== 'P' ? cf_quota_state($pdo, $AT, $AID) : null]);
        }
        $z = new ZipArchive();
        if ($z->open($part) !== true) { $upClean($uid); cfo(['ok' => false, 'error' => 'فایلِ زیپ خوانده نشد.']); }
        $audio = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $nm = (string)$z->getNameIndex($i);
            if (substr($nm, -1) !== '/' && strpos($nm, '__MACOSX') === false && isset(CF_AUDIO[strtolower(pathinfo($nm, PATHINFO_EXTENSION))])) $audio++;
        }
        $n = min($z->numFiles, 3000);
        $z->close();
        $job = ['uid' => $uid, 'name' => $name, 'genre' => $genre, 'owner' => $owner, 'target' => $target, 'pos' => 0, 'n' => $n, 'audio' => $audio, 'audio_done' => 0,
                'new_genres' => [], 'added' => 0, 'skipped' => 0, 'errors' => 0];
        @file_put_contents($upFile($uid, 'job'), json_encode($job, JSON_UNESCAPED_UNICODE));
        cfo(['ok' => true, 'zip' => true, 'zip_total' => $audio]);
    }
    if ($action === 'zip_step') {
        $uid = $upUid();
        $job = $upJson($upFile($uid, 'job'));
        if (!$job) cfo(['ok' => false, 'gone' => true, 'error' => 'کارِ باز کردنِ این زیپ پیدا نشد (تمام یا لغو شده).']);
        $upCanTarget($job['target']);
        @set_time_limit(120);
        $z = new ZipArchive();
        if ($z->open($upFile($uid, 'part')) !== true) { $upClean($uid); cfo(['ok' => false, 'gone' => true, 'error' => 'فایلِ زیپ خوانده نشد.']); }
        $genres = cf_genres($pdo);
        $res = ['added' => [], 'skipped' => [], 'errors' => []];
        $t0 = microtime(true);
        while ($job['pos'] < $job['n'] && microtime(true) - $t0 < 6) {
            $zipEntry($z, $job['pos'], $job, $res, $genres);
            $job['pos']++;
        }
        $z->close();
        $job['added'] += count($res['added']); $job['skipped'] += count($res['skipped']); $job['errors'] += count($res['errors']);
        $done = $job['pos'] >= $job['n'];
        if ($job['new_genres']) {
            $cur = cf_genres($pdo);
            $add = array_values(array_diff($job['new_genres'], $cur));
            if ($add) cf_setting_set($pdo, 'cf_genres', json_encode(array_values(array_merge($cur, $add)), JSON_UNESCAPED_UNICODE));
        }
        if ($done) $upFinish($uid, $job['name'], $job['added'], $job['skipped'], $job['errors'], $job['target']); else @file_put_contents($upFile($uid, 'job'), json_encode($job, JSON_UNESCAPED_UNICODE));
        cfo(['ok' => true, 'done' => $done, 'zip_pos' => $job['audio_done'], 'zip_total' => $job['audio']] + $res
            + ['quota' => $done && $job['owner'][0] !== 'P' ? cf_quota_state($pdo, $AT, $AID) : null]);
    }
    // آپلودهای نیمه‌کاره‌ی همین کاربر (برای نشان دادنِ پیشرفت بعد از برگشت به صفحه)
    if ($action === 'up_jobs') {
        $out = [];
        foreach ((array)glob($upDir . '/' . $AT . $AID . '_*.meta') as $mf) {
            if (!preg_match('/_([a-z0-9]{8,40})\.meta$/', $mf, $m)) continue;
            $uid = $m[1]; $meta = $upJson($mf) ?: [];
            if (filemtime($mf) < time() - 2 * 86400 && @filemtime($upFile($uid, 'part')) < time() - 2 * 86400) { $upClean($uid); continue; }   // رهاشده
            $job = $upJson($upFile($uid, 'job'));
            clearstatcache();
            $out[] = ['uid' => $uid, 'name' => $meta['name'] ?? '', 'size' => intval($meta['size'] ?? 0), 'lm' => $meta['lm'] ?? '', 'target' => $meta['target'] ?? 'mine',
                      'genre' => $meta['genre'] ?? '', 'have' => is_file($upFile($uid, 'part')) ? filesize($upFile($uid, 'part')) : 0,
                      'phase' => $job ? 'zip' : 'upload', 'zip_pos' => $job ? intval($job['audio_done']) : 0, 'zip_total' => $job ? intval($job['audio']) : 0];
        }
        foreach ((array)glob($upDir . '/' . $AT . $AID . '_*.done') as $df) {
            if (!preg_match('/_([a-z0-9]{8,40})\.done$/', $df, $m)) continue;
            if (filemtime($df) < time() - 86400) { @unlink($df); continue; }
            $dn = $upJson($df) ?: [];
            $out[] = ['uid' => $m[1], 'name' => $dn['name'] ?? '', 'size' => 0, 'phase' => 'done', 'target' => $dn['target'] ?? 'panel', 'added' => intval($dn['added'] ?? 0),
                      'skipped' => intval($dn['skipped'] ?? 0), 'errors' => intval($dn['errors'] ?? 0)];
        }
        cfo(['ok' => true, 'jobs' => $out]);
    }
    if ($action === 'up_cancel') { $u = $upUid(); $upClean($u); @unlink($upFile($u, 'done')); cfo(['ok' => true]); }
    if ($action === 'up_ack') { @unlink($upFile($upUid(), 'done')); cfo(['ok' => true]); }

    // ================= کارهای امروز =================
    if ($action === 'todo_list') { $need('todo'); cfo(['ok' => true, 'todos' => $todos()]); }
    if ($action === 'todo_save') {
        $need('todo');
        $text = trim(mb_substr((string)($data['text'] ?? ''), 0, 500));
        if ($text === '') cfo(['ok' => false, 'error' => 'متنِ کار را بنویسید.']);
        $due = ($data['due'] ?? '') !== '' ? $jd($data['due']) : null;
        if (($data['due'] ?? '') !== '' && !$due) cfo(['ok' => false, 'error' => 'تاریخ را درست وارد کنید (مثل ۱۴۰۵/۰۷/۱۲).']);
        $rem = null;
        $rt = trim(p2e_digits((string)($data['remind_time'] ?? '')));
        if ($rt !== '') {
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', $rt, $m) || $m[1] > 23 || $m[2] > 59) cfo(['ok' => false, 'error' => 'ساعتِ یادآور را درست وارد کنید (مثل ۱۴:۳۰).']);
            $rem = ($due ?: date('Y-m-d')) . sprintf(' %02d:%02d:00', $m[1], $m[2]);
        }
        $id = intval($data['id'] ?? 0);
        if ($id) $pdo->prepare("UPDATE cf_todos SET text = ?, due_date = ?, remind_at = ?, reminded = IF(remind_at <=> ?, reminded, 0) WHERE id = ? AND actor_type = ? AND actor_id = ?")->execute([$text, $due, $rem, $rem, $id, $AT, $AID]);
        else $pdo->prepare("INSERT INTO cf_todos (actor_type, actor_id, text, due_date, remind_at, created_at) VALUES (?, ?, ?, ?, ?, NOW())")->execute([$AT, $AID, $text, $due, $rem]);
        cfo(['ok' => true, 'todos' => $todos()]);
    }
    if ($action === 'todo_done') {
        $need('todo');
        $id = intval($data['id'] ?? 0);
        $done = !empty($data['done']);
        $st = $pdo->prepare("SELECT * FROM cf_todos WHERE id = ? AND actor_type = ? AND actor_id = ?");
        $st->execute([$id, $AT, $AID]);
        $t = $st->fetch();
        if (!$t) cfo(['ok' => false, 'error' => 'پیدا نشد.']);
        $pdo->prepare("UPDATE cf_todos SET done_at = " . ($done ? 'NOW()' : 'NULL') . " WHERE id = ?")->execute([$id]);
        $logged = false;
        // کارِ انجام‌شده در «شرحِ کارهای امروز»ِ کارکرد من هم می‌نشیند
        if ($done && $AT === 'S' && $t['done_at'] === null) {
            try {
                require_once __DIR__ . '/_work.php';
                wk_ensure($pdo);
                $today = date('Y-m-d');
                $st = $pdo->prepare("SELECT tasks FROM work_days WHERE user_id = ? AND wdate = ?");
                $st->execute([$AID, $today]);
                $tasks = json_decode((string)$st->fetchColumn(), true) ?: [];
                $title = mb_substr($t['text'], 0, 300);
                if (!in_array($title, array_column($tasks, 'title'), true) && count($tasks) < 60) {
                    $tasks[] = ['from' => date('H:i'), 'to' => '', 'title' => $title];
                    $pdo->prepare("INSERT INTO work_days (user_id, wdate, tasks, updated_at, updated_by) VALUES (?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE tasks = VALUES(tasks), updated_at = NOW()")
                        ->execute([$AID, $today, json_encode($tasks, JSON_UNESCAPED_UNICODE), $AID]);
                    $logged = true;
                }
            } catch (Throwable $e) { error_log('[comfort todo→work] ' . $e->getMessage()); }
        }
        cfo(['ok' => true, 'logged' => $logged, 'todos' => $todos()]);
    }
    if ($action === 'todo_delete') {
        $need('todo');
        $pdo->prepare("DELETE FROM cf_todos WHERE id = ? AND actor_type = ? AND actor_id = ?")->execute([intval($data['id'] ?? 0), $AT, $AID]);
        cfo(['ok' => true, 'todos' => $todos()]);
    }
    if ($action === 'todo_reminded') {
        $pdo->prepare("UPDATE cf_todos SET reminded = 1 WHERE id = ? AND actor_type = ? AND actor_id = ?")->execute([intval($data['id'] ?? 0), $AT, $AID]);
        cfo(['ok' => true]);
    }

    // ================= متن‌های آماده =================
    if ($action === 'canned_list') { $need('canned'); cfo(['ok' => true, 'canned' => $canned()]); }
    if ($action === 'canned_save') {
        $need('canned');
        $title = trim(mb_substr((string)($data['title'] ?? ''), 0, 120));
        $body = trim(mb_substr((string)($data['body'] ?? ''), 0, 3000));
        if ($title === '' || $body === '') cfo(['ok' => false, 'error' => 'عنوان و متن را بنویسید.']);
        $global = !empty($data['global']) && $isAdmin ? 1 : 0;
        $id = intval($data['id'] ?? 0);
        if ($id) {
            $st = $pdo->prepare("SELECT actor_type, actor_id, is_global FROM cf_canned WHERE id = ?");
            $st->execute([$id]);
            $c = $st->fetch();
            if (!$c || !(($c['actor_type'] === $AT && intval($c['actor_id']) === $AID) || ($c['is_global'] && $isAdmin))) cfo(['ok' => false, 'error' => 'اجازه‌ی ویرایشِ این متن را ندارید.']);
            $pdo->prepare("UPDATE cf_canned SET title = ?, body = ?, is_global = ? WHERE id = ?")->execute([$title, $body, $global, $id]);
        } else $pdo->prepare("INSERT INTO cf_canned (actor_type, actor_id, title, body, is_global, created_at) VALUES (?, ?, ?, ?, ?, NOW())")->execute([$AT, $AID, $title, $body, $global]);
        cfo(['ok' => true, 'canned' => $canned()]);
    }
    if ($action === 'canned_delete') {
        $need('canned');
        $id = intval($data['id'] ?? 0);
        $pdo->prepare("DELETE FROM cf_canned WHERE id = ? AND ((actor_type = ? AND actor_id = ? AND is_global = 0) OR (is_global = 1 AND ? = 1))")->execute([$id, $AT, $AID, $isAdmin ? 1 : 0]);
        cfo(['ok' => true, 'canned' => $canned()]);
    }

    // ================= جستجوی سراسری =================
    if ($action === 'search') {
        $need('search');
        $q = trim(p2e_digits(str_replace(['ي', 'ك'], ['ی', 'ک'], (string)($data['q'] ?? ''))));
        if (mb_strlen($q) < 2) cfo(['ok' => true, 'results' => []]);
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $res = [];
        $add = function ($group, $icon, $title, $sub, $open) use (&$res) { if (count($res) < 60) $res[] = ['group' => $group, 'icon' => $icon, 'title' => $title, 'sub' => $sub, 'open' => $open]; };
        // پلاک: عددِ سه‌رقمی + حرف (مثلِ «۲۲۲ ب» یا «۱۲ب۲۲۲۳۳»)
        $plate3 = null; $plateL = null;
        if (preg_match('/(\d{2})?\s*([آ-ی])\s*(\d{3})/u', $q, $m) || preg_match('/(\d{3})\s*([آ-ی])/u', $q, $m2)) {
            if (!empty($m)) { $plateL = $m[2]; $plate3 = $m[3]; } else { $plate3 = $m2[1]; $plateL = $m2[2]; }
        }
        if ($canPage('cases')) {
            $st = $pdo->prepare("SELECT c.id, c.unique_code, c.insured_name, c.plate, c.policy_number, c.status, p.full_name holder, p.national_code
                                 FROM policy_cases c LEFT JOIN persons p ON p.id = c.person_id
                                 WHERE c.unique_code LIKE ? OR c.policy_number LIKE ? OR c.insured_name LIKE ? OR c.insured_national_id LIKE ? OR c.chassis_num LIKE ? OR c.vin LIKE ?
                                    OR c.central_unique_code LIKE ? OR p.full_name LIKE ? OR p.national_code LIKE ? OR p.mobile_number LIKE ? OR c.plate LIKE ?" . ($plate3 ? " OR c.plate LIKE ?" : '') . "
                                 ORDER BY c.id DESC LIMIT 12");
            $args = array_fill(0, 11, $like);
            if ($plate3) $args[] = '%' . $plate3 . ' ' . $plateL . '%';
            $st->execute($args);
            foreach ($st->fetchAll() as $r) $add('پرونده‌های کارکنان', 'fa-folder-open', trim(($r['insured_name'] ?: $r['holder']) . ' · ' . $r['unique_code']),
                trim(($r['plate'] ? fa_digits($r['plate']) . ' · ' : '') . ($r['policy_number'] ? 'بیمه‌نامه ' . $r['policy_number'] . ' · ' : '') . ($r['national_code'] ? 'کد ملی ' . fa_digits($r['national_code']) : '')),
                ['fn' => 'openCase', 'args' => [intval($r['id'])]]);
        }
        if ($canPage('companies-requests') || $canPage('issue-queue') || $canPage('issued-list')) {
            $sql = "SELECT pl.id, pl.request_id, pl.plate_p1, pl.plate_letter, pl.plate_p2, pl.plate_p4, pl.chassis_no, pl.policy_number, pl.car_name, co.name company
                    FROM company_request_plates pl JOIN company_requests r ON r.id = pl.request_id LEFT JOIN companies co ON co.id = r.company_id
                    WHERE pl.chassis_no LIKE ? OR pl.vin LIKE ? OR pl.policy_number LIKE ? OR pl.engine_no LIKE ? OR CONCAT(pl.plate_p1, pl.plate_letter, pl.plate_p2, pl.plate_p4) LIKE ?"
                 . ($plate3 ? " OR (pl.plate_p2 = ? AND pl.plate_letter = ?)" : '') . " ORDER BY pl.id DESC LIMIT 12";
            $args = [$like, $like, $like, $like, '%' . preg_replace('/\s+/u', '', $q) . '%'];
            if ($plate3) { $args[] = $plate3; $args[] = $plateL; }
            $st = $pdo->prepare($sql);
            $st->execute($args);
            foreach ($st->fetchAll() as $r) $add('ردیف‌های شرکتی', 'fa-car', trim(($r['company'] ?: 'شرکت') . ($r['car_name'] ? ' · ' . $r['car_name'] : '')),
                trim(($r['plate_p2'] ? fa_digits($r['plate_p1'] . ' ' . $r['plate_letter'] . ' ' . $r['plate_p2'] . ' - ' . $r['plate_p4']) . ' · ' : '') . ($r['chassis_no'] ? 'شاسی ' . $r['chassis_no'] . ' · ' : '') . ($r['policy_number'] ? 'بیمه‌نامه ' . $r['policy_number'] . ' · ' : '') . 'درخواست #' . fa_digits($r['request_id'])),
                ['fn' => 'openCompanyRequestDetail', 'args' => [intval($r['request_id'])]]);
            $rid = preg_match('/^#?(\d{1,7})$/', $q, $mm) ? intval($mm[1]) : 0;
            $st = $pdo->prepare("SELECT r.id, r.ref_code, r.request_kind, r.created_at, co.name FROM company_requests r LEFT JOIN companies co ON co.id = r.company_id
                                 WHERE r.id = ? OR r.ref_code LIKE ? OR co.name LIKE ? ORDER BY r.id DESC LIMIT 8");
            $st->execute([$rid, $like, $like]);
            foreach ($st->fetchAll() as $r) $add('درخواست‌های شرکتی', 'fa-file-lines', 'درخواست #' . fa_digits($r['id']) . ' · ' . ($r['name'] ?: ''), ($r['ref_code'] ? 'کد پیگیری ' . $r['ref_code'] . ' · ' : '') . fa_digits($j($r['created_at'])),
                ['fn' => 'openCompanyRequestDetail', 'args' => [intval($r['id'])]]);
        }
        if ($canPage('companies-manage')) {
            require_once __DIR__ . '/_import_schema.php'; imp_ensure($pdo);
            $st = $pdo->prepare("SELECT id, name, phone FROM companies WHERE is_import = 0 AND (name LIKE ? OR economic_code LIKE ? OR phone LIKE ?) ORDER BY name LIMIT 6");
            $st->execute([$like, $like, $like]);
            foreach ($st->fetchAll() as $r) $add('شرکت‌ها', 'fa-building', $r['name'], $r['phone'] ? 'تلفن ' . fa_digits($r['phone']) : '', ['fn' => 'switchTab', 'args' => ['companies-manage'], 'then' => ['fn' => 'editCompany', 'args' => [intval($r['id'])]]]);
        }
        if ($canPage('users')) {
            $st = $pdo->prepare("SELECT id, full_name, national_code, personnel_code, mobile_number FROM persons WHERE full_name LIKE ? OR national_code LIKE ? OR personnel_code LIKE ? OR mobile_number LIKE ? ORDER BY id DESC LIMIT 8");
            $st->execute([$like, $like, $like, $like]);
            foreach ($st->fetchAll() as $r) $add('کارکنان (کاربران ربات)', 'fa-user', $r['full_name'], trim(($r['national_code'] ? 'کد ملی ' . fa_digits($r['national_code']) : '') . ($r['personnel_code'] ? ' · کد پرسنلی ' . fa_digits($r['personnel_code']) : '') . ($r['mobile_number'] ? ' · ' . fa_digits($r['mobile_number']) : '')),
                ['fn' => 'switchTab', 'args' => ['users'], 'search' => $r['full_name']]);
        }
        if ($canPage('vr-list')) {
            try {
                $st = $pdo->prepare("SELECT id, report_no, insured_name, plate_display, category_name FROM visit_reports WHERE deleted_at IS NULL AND (report_no LIKE ? OR insured_name LIKE ? OR chassis_no LIKE ? OR national_id LIKE ? OR plate_display LIKE ?" . ($plate3 ? " OR (plate_p2 = ? AND plate_letter = ?)" : '') . ") ORDER BY id DESC LIMIT 8");
                $args = [$like, $like, $like, $like, $like];
                if ($plate3) { $args[] = $plate3; $args[] = $plateL; }
                $st->execute($args);
                foreach ($st->fetchAll() as $r) $add('گزارش‌های بازدید', 'fa-clipboard-check', $r['report_no'] . ' · ' . $r['insured_name'], trim(($r['category_name'] ?: '') . ($r['plate_display'] ? ' · ' . fa_digits($r['plate_display']) : '')), ['fn' => 'VR.openDetail', 'args' => [intval($r['id'])]]);
            } catch (Throwable $e) {}
        }
        cfo(['ok' => true, 'results' => $res]);
    }

    // ================= پیامِ صبح‌بخیر =================
    if ($action === 'morning') {
        $need('morning');
        [$jy, $md] = cf_today_md();
        $out = ['ok' => true, 'name' => $A[2], 'today_j' => sprintf('%04d/%s', $jy, $md), 'occasion' => CF_OCCASIONS[$md] ?? null];
        $all = !empty($F['todo']) ? $todos() : [];
        $today = date('Y-m-d');
        $out['todos'] = array_values(array_filter($all, function ($t) use ($today) { return !$t['done'] && (!$t['due_g'] || $t['due_g'] <= $today); }));
        if ($AT === 'S') {
            if (!empty($F['birthdays'])) $out['birthdays'] = array_values(array_filter(cf_birthdays($pdo), function ($b) { return $b['in_days'] <= 3; }));
            try {
                require_once __DIR__ . '/_work.php';
                $b = wk_balance($pdo, $AID);
                $out['leave'] = $b['balance_fa'];
            } catch (Throwable $e) {}
            if ($canPage('fin-installments')) {
                try {
                    $due = function ($tbl, $alloc, $fk) use ($pdo) {
                        $st = $pdo->query("SELECT SUM(i.due_date = CURDATE()) today, SUM(i.due_date < CURDATE()) overdue, SUM(IF(i.due_date = CURDATE(), i.amount, 0)) today_amount
                                           FROM `$tbl` i WHERE i.due_date <= CURDATE() AND COALESCE(i.settled_to_pasargad, 0) = 0
                                           AND i.amount > COALESCE((SELECT SUM(a.amount) FROM `$alloc` a WHERE a.installment_id = i.id), 0)");
                        return $st->fetch() ?: [];
                    };
                    $p = $due('policy_installments', 'payment_allocations', 'case_id');
                    $c = $due('company_installments', 'company_payment_allocations', 'plate_id');
                    $out['installments'] = ['today' => intval($p['today'] ?? 0) + intval($c['today'] ?? 0), 'overdue' => intval($p['overdue'] ?? 0) + intval($c['overdue'] ?? 0),
                                            'today_amount' => intval($p['today_amount'] ?? 0) + intval($c['today_amount'] ?? 0)];
                } catch (Throwable $e) { error_log('[comfort morning] ' . $e->getMessage()); }
            }
        }
        cfo($out);
    }

    // ================= مدیر کل =================
    if (strpos($action, 'lib_') === 0 || strpos($action, 'quota') === 0 || strpos($action, 'access_') === 0) {
        if (!$isAdmin) cfo(['ok' => false, 'error' => 'فقط مدیر کل.']);
        if ($action === 'lib_list') {
            $w = []; $p = [];
            $own = $data['owner'] ?? 'all';
            if ($own === 'panel') $w[] = "owner_type = 'P'"; elseif ($own === 'user') $w[] = "owner_type <> 'P'";
            if (($data['genre'] ?? '') !== '') { $w[] = 'genre = ?'; $p[] = $data['genre']; }
            if (trim((string)($data['q'] ?? '')) !== '') { $w[] = '(title LIKE ? OR artist LIKE ? OR orig_name LIKE ?)'; $l = '%' . trim($data['q']) . '%'; array_push($p, $l, $l, $l); }
            if (($data['uploader'] ?? '') !== '' && preg_match('/^([SC])(\d+)$/', $data['uploader'], $m)) { $w[] = 'owner_type = ? AND owner_id = ?'; array_push($p, $m[1], intval($m[2])); }
            $st = $pdo->prepare("SELECT * FROM cf_tracks" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 2000");
            $st->execute($p);
            $rows = array_map(function ($r) use ($pdo) { return cf_track_out($pdo, $r) + ['owner_name' => cf_actor_name($pdo, $r['owner_type'], $r['owner_id']), 'orig_name' => $r['orig_name']]; }, $st->fetchAll());
            $sum = $pdo->query("SELECT owner_type = 'P' AS panel, COUNT(*) n, COALESCE(SUM(size), 0) s FROM cf_tracks GROUP BY owner_type = 'P'")->fetchAll();
            $stat = ['panel' => ['n' => 0, 's' => 0], 'user' => ['n' => 0, 's' => 0]];
            foreach ($sum as $s) $stat[$s['panel'] ? 'panel' : 'user'] = ['n' => intval($s['n']), 's' => intval($s['s'])];
            cfo(['ok' => true, 'tracks' => $rows, 'genres' => cf_genres($pdo), 'quota_mb' => intval(cf_quota_bytes($pdo) / 1048576), 'stat' => $stat,
                 'blocked' => intval($pdo->query("SELECT COUNT(*) FROM cf_blocked")->fetchColumn())]);
        }
        if ($action === 'lib_update') {
            $id = intval($data['id'] ?? 0);
            $sets = ['title = ?', 'artist = ?', 'genre = ?'];
            $vals = [mb_substr(trim((string)($data['title'] ?? '')), 0, 200) ?: 'بی‌نام', mb_substr(trim((string)($data['artist'] ?? '')), 0, 160) ?: null, mb_substr(trim((string)($data['genre'] ?? '')), 0, 60) ?: 'سایر'];
            $vals[] = $id;
            $pdo->prepare("UPDATE cf_tracks SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            // «انتشار برای همه»: انتقالِ آهنگِ کاربر به کتابخانه‌ی پنل
            if (!empty($data['to_panel'])) cf_publish_track($pdo, $id);
            cfo(['ok' => true]);
        }
        if ($action === 'lib_delete') {
            $ids = array_filter(array_map('intval', (array)($data['ids'] ?? [$data['id'] ?? 0])));
            $n = 0;
            foreach ($ids as $id) if (cf_delete_track($pdo, $id, true, $AID)) $n++;
            cfo(['ok' => true, 'deleted' => $n]);
        }
        if ($action === 'lib_blocked') {
            cfo(['ok' => true, 'rows' => array_map(function ($r) use ($j) { return ['sha1' => $r['sha1'], 'title' => $r['title'], 'at' => $j($r['blocked_at'])]; },
                $pdo->query("SELECT * FROM cf_blocked ORDER BY blocked_at DESC LIMIT 500")->fetchAll())]);
        }
        if ($action === 'lib_unblock') {
            $pdo->prepare("DELETE FROM cf_blocked WHERE sha1 = ?")->execute([(string)($data['sha1'] ?? '')]);
            cfo(['ok' => true]);
        }
        if ($action === 'lib_settings') {
            $g = array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 40); }, (array)($data['genres'] ?? [])))));
            if (!$g) cfo(['ok' => false, 'error' => 'دست‌کم یک ژانر لازم است.']);
            cf_setting_set($pdo, 'cf_genres', json_encode($g, JSON_UNESCAPED_UNICODE));
            $mb = intval(p2e_digits((string)($data['quota_mb'] ?? 250)));
            cf_setting_set($pdo, 'cf_quota_mb', (string)max(10, min(10240, $mb)));
            cfo(['ok' => true, 'genres' => $g]);
        }
        if ($action === 'quotas') {
            $rows = $pdo->query("SELECT owner_type, owner_id, COUNT(*) n, SUM(size) s FROM cf_tracks WHERE owner_type <> 'P' GROUP BY owner_type, owner_id ORDER BY s DESC")->fetchAll();
            cfo(['ok' => true, 'rows' => array_map(function ($r) use ($pdo, $j) {
                $q = cf_quota_state($pdo, $r['owner_type'], $r['owner_id']);
                return ['type' => $r['owner_type'], 'id' => intval($r['owner_id']), 'name' => cf_actor_name($pdo, $r['owner_type'], $r['owner_id']), 'kind' => $r['owner_type'] === 'C' ? 'کاربرِ شرکت' : 'کاربرِ پنل',
                        'count' => intval($r['n']), 'total' => $q['total'], 'used' => $q['used'], 'limit' => $q['limit'], 'reset_at' => $q['reset_at'] ? $j($q['reset_at']) : ''];
            }, $rows)]);
        }
        if ($action === 'quota_reset') {
            $t = ($data['type'] ?? '') === 'C' ? 'C' : 'S';
            $id = intval($data['id'] ?? 0);
            $q = cf_quota_state($pdo, $t, $id);
            $pdo->prepare("INSERT INTO cf_quota (actor_type, actor_id, base_bytes, reset_at, reset_by) VALUES (?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE base_bytes = VALUES(base_bytes), reset_at = NOW(), reset_by = VALUES(reset_by)")
                ->execute([$t, $id, $q['total'], $AID]);
            cfo(['ok' => true]);
        }
        if ($action === 'access_get') {
            $t = ($data['type'] ?? '') === 'C' ? 'C' : 'S';
            $id = intval($data['id'] ?? 0);
            $cur = cf_features($pdo, $t, $id);
            $ov = cf_overrides($pdo, $t, $id);
            $items = [];
            foreach (cf_feature_keys($t) as $k) $items[] = ['key' => $k, 'title' => CF_FEATURES[$k][0], 'on' => !empty($cur[$k]) || ($k === 'music_upload' && !empty($ov[$k])), 'default' => $t === 'S', 'changed' => array_key_exists($k, $ov)];
            cfo(['ok' => true, 'type' => $t, 'id' => $id, 'items' => $items]);
        }
        if ($action === 'access_save') {
            $t = ($data['type'] ?? '') === 'C' ? 'C' : 'S';
            cf_set_overrides($pdo, $t, intval($data['id'] ?? 0), (array)($data['features'] ?? []));
            cfo(['ok' => true, 'features' => cf_features($pdo, $t, intval($data['id'] ?? 0))]);
        }
    }

    cfo(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[comfort_actions] ' . $e->getMessage() . ' @' . $e->getLine());
    cfo(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
