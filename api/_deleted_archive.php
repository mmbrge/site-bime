<?php
// فایل: api/_deleted_archive.php
// «بایگانی حذف‌شده‌ها»: وقتی درخواستِ شرکتی (یا یک ردیفش) با «حذف» پاک می‌شود، پوشه‌ها و مدارکش در بایگانیِ اصلی نمی‌مانند؛
// با همان مسیرِ قبلی به «Archive/بایگانی/بایگانی حذف شده‌ها/…» منتقل می‌شوند (هیچ فایلی پاک نمی‌شود؛ فقط مدیر کل می‌بیند).
// درخواست‌هایی که قبلاً حذف شده‌اند هم یک بار (پس‌زمینه) پیدا و منتقل می‌شوند: فقط پوشه‌ای که هیچ درخواست/ردیف/مدرکِ
// زنده‌ای در پایگاه داده به آن (یا چیزی داخلش) اشاره نمی‌کند. پوشه‌ی «مالی» (فیش‌ها) هرگز جابه‌جا نمی‌شود.

const DELARC_NAME = 'بایگانی حذف شده‌ها';
const DELARC_MIGRATE_KEY = 'delarc_migrated_v1';

function delarc_root($siteRoot) { return archive_root($siteRoot) . '/' . DELARC_NAME; }

// مسیرِ نسبی به «Archive/بایگانی» (برای مقایسه؛ مستقل از اینکه در دیتابیس مطلق ذخیره شده یا نسبی) یا null
function delarc_rel($p) {
    $p = str_replace('\\', '/', trim((string)$p));
    if ($p === '') return null;
    $k = mb_strpos($p, 'Archive/بایگانی/');
    if ($k === false) return null;
    $rel = trim(mb_substr($p, $k + mb_strlen('Archive/بایگانی/')), '/');
    return $rel === '' ? null : $rel;
}

// یک فایل/پوشه از بایگانیِ اصلی => همان مسیر زیرِ «بایگانی حذف شده‌ها». خروجی: مسیرِ مقصد یا null
function delarc_move_one($siteRoot, $abs, $minDirDepth = 4) {
    $root = realpath(archive_root($siteRoot));
    $real = realpath($abs);
    if (!$root || !$real || strpos($real, $root . '/') !== 0) return null;
    $rel = substr($real, strlen($root) + 1);
    if (mb_strpos($rel, DELARC_NAME . '/') === 0 || $rel === DELARC_NAME) return null;   // خودش در حذف‌شده‌هاست
    $depth = count(array_filter(explode('/', $rel), 'strlen'));
    if (is_dir($real) && $depth < $minDirDepth) return null;   // ریشه، سال، شرکت یا ماه هرگز جابه‌جا نمی‌شود
    if (!is_dir($real) && $depth < 2) return null;
    $dst = delarc_root($siteRoot) . '/' . $rel;
    if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
    if (file_exists($dst)) {
        if (is_dir($real)) { $i = 2; while (file_exists($dst . ' (' . $i . ')')) $i++; $dst .= ' (' . $i . ')'; }
        else $dst = unique_dest_path($dst);
    }
    if (@rename($real, $dst)) { delarc_prune_empty(dirname($real), $root); return $dst; }
    if (is_dir($real) && function_exists('copy_dir_recursive')) {
        copy_dir_recursive($real, $dst);
        if (function_exists('archive_rrmdir')) archive_rrmdir($real);
        delarc_prune_empty(dirname($real), $root);
        return $dst;
    }
    if (is_file($real) && @copy($real, $dst)) { @unlink($real); delarc_prune_empty(dirname($real), $root); return $dst; }
    return null;
}
// پوشه‌های خالی‌شده (تا سطحِ «ماه»؛ سال و شرکت می‌مانند)
function delarc_prune_empty($dir, $root) {
    for ($i = 0; $i < 3; $i++) {
        $real = realpath($dir);
        if (!$real || strpos($real, $root . '/') !== 0) return;
        $depth = count(array_filter(explode('/', substr($real, strlen($root) + 1)), 'strlen'));
        if ($depth <= 3) return;
        $it = @scandir($real);
        if ($it === false || count(array_diff($it, ['.', '..'])) > 0) return;
        if (!@rmdir($real)) return;
        $dir = dirname($real);
    }
}

// پیش از حذفِ یک درخواستِ شرکتی (یا فقط چند ردیفش): مسیرهای بایگانی‌اش جمع می‌شود؛ بعد از حذف delarc_move_paths
function delarc_collect_company($pdo, $requestId, array $plateIds = null) {
    $paths = [];
    $whereP = $plateIds === null ? 'request_id = ?' : 'id IN (' . (implode(',', array_map('intval', $plateIds)) ?: '0') . ')';
    $st = $pdo->prepare("SELECT folder_path, issued_file_path FROM company_request_plates WHERE $whereP");
    $st->execute($plateIds === null ? [intval($requestId)] : []);
    foreach ($st->fetchAll() as $r) foreach ($r as $v) if ($v) $paths[] = $v;
    if ($plateIds === null) {
        $st = $pdo->prepare("SELECT file_path FROM company_documents WHERE request_id = ?");
        $st->execute([intval($requestId)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) if ($v) $paths[] = $v;
        try {
            if ($pdo->query("SHOW COLUMNS FROM company_requests LIKE 'letter_file_path'")->fetch()) {
                $st = $pdo->prepare("SELECT letter_file_path FROM company_requests WHERE id = ?");
                $st->execute([intval($requestId)]);
                if ($v = $st->fetchColumn()) $paths[] = $v;
            }
        } catch (Throwable $e) {}
    } elseif ($plateIds) {
        foreach ($pdo->query("SELECT file_path FROM company_documents WHERE plate_id IN (" . implode(',', array_map('intval', $plateIds)) . ")")->fetchAll(PDO::FETCH_COLUMN) as $v) if ($v) $paths[] = $v;
    }
    return array_values(array_unique($paths));
}
// مسیری که هنوز درخواست/ردیف/مدرکِ زنده‌ای به آن (یا داخلش) اشاره می‌کند جابه‌جا نمی‌شود (مثلاً پوشه‌ی مشترکِ دو ردیف)
function delarc_is_live($pdo, $rel) {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], 'Archive/بایگانی/' . $rel);
    try {
        $st = $pdo->prepare("SELECT 1 FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                              WHERE crp.folder_path LIKE ? OR crp.folder_path LIKE ? OR crp.issued_file_path LIKE ? OR crp.issued_file_path LIKE ? LIMIT 1");
        $st->execute([$like, $like . '/%', $like, $like . '/%']);
        if ($st->fetchColumn()) return true;
        $st = $pdo->prepare("SELECT 1 FROM company_documents d JOIN company_requests cr ON cr.id = d.request_id WHERE d.file_path LIKE ? OR d.file_path LIKE ? LIMIT 1");
        $st->execute([$like, $like . '/%']);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) { return true; }   // نامعلوم => دست نمی‌زنیم
    return false;
}
function delarc_move_paths($siteRoot, array $paths, $pdo = null) {
    // پوشه‌ها اول (فایل‌های داخلشان همراهشان می‌روند)، بعد فایل‌های تکی
    $abs = [];
    foreach ($paths as $p) {
        $rel = delarc_rel($p);
        if ($rel === null || mb_strpos($rel, '/مالی/') !== false) continue;
        $abs[archive_root($siteRoot) . '/' . $rel] = true;
    }
    $abs = array_keys($abs);
    usort($abs, function ($a, $b) { return strlen($a) - strlen($b); });
    $n = 0;
    foreach ($abs as $a) {
        if (!file_exists($a)) continue;
        if ($pdo && delarc_is_live($pdo, substr($a, strlen(archive_root($siteRoot)) + 1))) continue;
        if (delarc_move_one($siteRoot, $a)) $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------
//  یک‌بار: پوشه‌های درخواست‌هایی که قبلاً حذف شده‌اند (بایگانی شرکتی / شخصی / وارداتی)
//  ساختار: {ریشه}/{سال}/{شرکت|ماه}/{ماه|بیمه‌گذار}/{تاریخ}/{نوع}/{پوشه‌ی ردیف}
// ---------------------------------------------------------------------
function delarc_live_refs($pdo) {
    $set = [];
    $add = function ($p) use (&$set) {
        $rel = delarc_rel($p);
        if ($rel === null) return;
        $parts = explode('/', $rel); $acc = '';
        foreach ($parts as $x) { $acc = $acc === '' ? $x : $acc . '/' . $x; $set[$acc] = true; }
    };
    $q = function ($sql) use ($pdo, $add) { try { foreach ($pdo->query($sql) as $r) foreach ($r as $v) if ($v) $add($v); } catch (Throwable $e) { error_log('[delarc refs] ' . $e->getMessage()); throw $e; } };
    $q("SELECT crp.folder_path, crp.issued_file_path FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id");
    $q("SELECT d.file_path FROM company_documents d JOIN company_requests cr ON cr.id = d.request_id");
    $q("SELECT d.file_path FROM company_documents d JOIN company_request_plates crp ON crp.id = d.plate_id JOIN company_requests cr ON cr.id = crp.request_id");
    if ($pdo->query("SHOW COLUMNS FROM company_requests LIKE 'letter_file_path'")->fetch()) $q("SELECT letter_file_path FROM company_requests WHERE letter_file_path IS NOT NULL");
    return $set;
}
function delarc_migrate($pdo, $siteRoot, $budget = 120.0) {
    $t0 = microtime(true);
    $refs = delarc_live_refs($pdo);
    if (count($refs) === 0) {
        // پایگاه داده هیچ درخواستی ندارد (یا خوانده نشد): برای احتیاط کاری نمی‌کنیم
        $n = (int)$pdo->query("SELECT COUNT(*) FROM company_requests")->fetchColumn();
        if ($n === 0) return ['ok' => true, 'moved' => 0];
        return ['ok' => false, 'moved' => 0];
    }
    $root = realpath(archive_root($siteRoot));
    if (!$root) return ['ok' => true, 'moved' => 0];
    $moved = 0; $done = true;
    foreach (['بایگانی شرکتی', 'بایگانی شخصی', 'بایگانی وارداتی'] as $top) {
        $base = $root . '/' . $top;
        if (!is_dir($base)) continue;
        // سطحِ «تاریخِ درخواست» = عمقِ ۴ زیرِ ریشه‌ی بخش
        $level = [$base];
        for ($d = 0; $d < 4; $d++) {
            $next = [];
            foreach ($level as $dir) foreach ((array)@scandir($dir) as $f) {
                if ($f === '.' || $f === '..' || $f === '' || $f[0] === '.' || $f === 'مالی') continue;
                if (is_dir($dir . '/' . $f)) $next[] = $dir . '/' . $f;
            }
            $level = $next;
        }
        foreach ($level as $dateDir) {
            if (microtime(true) - $t0 > $budget) { $done = false; break 2; }
            $relDate = substr($dateDir, strlen($root) + 1);
            if (!isset($refs[$relDate])) {
                // هیچ چیزِ زنده‌ای در این تاریخ نیست: همه‌چیزش جز «مالی»
                foreach ((array)@scandir($dateDir) as $f) {
                    if ($f === '.' || $f === '..' || $f === 'مالی' || $f === '' || $f[0] === '.') continue;
                    if (delarc_move_one($siteRoot, $dateDir . '/' . $f, 5)) $moved++;
                }
                continue;
            }
            // فقط پوشه‌های ردیفیِ بی‌صاحب (نوع/ردیف)
            foreach ((array)@scandir($dateDir) as $k) {
                if ($k === '.' || $k === '..' || $k === 'مالی' || $k === '' || $k[0] === '.' || !is_dir($dateDir . '/' . $k)) continue;
                $kindDir = $dateDir . '/' . $k;
                if (!isset($refs[$relDate . '/' . $k])) { if (delarc_move_one($siteRoot, $kindDir, 5)) $moved++; continue; }
                foreach ((array)@scandir($kindDir) as $pf) {
                    if ($pf === '.' || $pf === '..' || $pf === '' || $pf[0] === '.' || !is_dir($kindDir . '/' . $pf)) continue;
                    if (!isset($refs[$relDate . '/' . $k . '/' . $pf]) && delarc_move_one($siteRoot, $kindDir . '/' . $pf, 6)) $moved++;
                }
            }
        }
    }
    return ['ok' => $done, 'moved' => $moved];
}
// یک بار، در پس‌زمینه بعد از فرستادنِ صفحه (مثلِ شاخص‌های دیتابیس)
function delarc_migrate_maybe($pdo, $siteRoot) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([DELARC_MIGRATE_KEY]);
        if ($st->fetchColumn()) return;
    } catch (Throwable $e) { return; }
    register_shutdown_function(function () use ($pdo, $siteRoot) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true); @set_time_limit(300);
        try {
            require_once __DIR__ . '/_case_helpers.php';
            if (!(int)$pdo->query("SELECT GET_LOCK('bime_delarc', 0)")->fetchColumn()) return;
            $r = delarc_migrate($pdo, $siteRoot);
            if (!empty($r['ok'])) $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                                     ->execute([DELARC_MIGRATE_KEY, date('Y-m-d H:i:s') . ' +' . intval($r['moved'])]);
            $pdo->query("SELECT RELEASE_LOCK('bime_delarc')");
        } catch (Throwable $e) { error_log('[delarc migrate] ' . $e->getMessage()); }
    });
}
