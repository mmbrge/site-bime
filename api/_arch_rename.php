<?php
// فایل: api/_arch_rename.php
// تغییرِ نام در بایگانی بعد از ویرایشِ اطلاعات (نامِ شخص/بیمه‌گذار، شرکت، پلاک، شماره‌ی بیمه‌نامه، VIN):
// در پوشه‌هایی که داده می‌شود، هر پوشه و فایلی که نامش «قدیمی» را دارد به «تازه» تغییر نام می‌دهد (پوشه‌های داخلی اول)
// و همه‌ی مسیرهای ذخیره‌شده در پایگاه داده (پرونده‌ها، مدارک، بازدید، معرفی‌نامه، ردیف‌ها و مدارکِ شرکتی، الحاقیه‌ها)
// با نامِ تازه هماهنگ می‌شوند. هیچ فایلی پاک یا بازنویسی نمی‌شود؛ اگر نامِ مقصد از قبل باشد، شماره می‌خورد.

// مسیرهای ذخیره‌شده: همان archive_repath، به‌اضافه‌ی جدول‌های شرکتی و الحاقیه
function arch_repath($pdo, $siteRoot, $oldAbs, $newAbs) {
    if (function_exists('archive_repath')) archive_repath($pdo, $siteRoot, $oldAbs, $newAbs);
    $root = rtrim($siteRoot, '/') . '/';
    $pairs = [[$oldAbs, $newAbs]];
    if (strpos($oldAbs, $root) === 0 && strpos($newAbs, $root) === 0) $pairs[] = [substr($oldAbs, strlen($root)), substr($newAbs, strlen($root))];
    $cols = [['company_request_plates', 'folder_path'], ['company_request_plates', 'issued_file_path'], ['company_documents', 'file_path'],
             ['policy_endorsements', 'file_path'], ['company_requests', 'letter_file_path']];
    foreach ($pairs as [$o, $n]) foreach ($cols as [$t, $c]) {
        try {
            $pdo->prepare("UPDATE `$t` SET `$c` = CONCAT(?, SUBSTRING(`$c`, CHAR_LENGTH(?) + 1)) WHERE `$c` = ? OR LEFT(`$c`, CHAR_LENGTH(?)) = ?")
                ->execute([$n, $o, $o, $o . '/', $o . '/']);
        } catch (Throwable $e) {}
    }
}

// نگاشتِ «قدیمی => تازه» برای نام‌گذاری: همان پاک‌سازیِ نامِ پوشه/فایل روی هر دو؛ مقدارهای خیلی کوتاه
// (کمتر از ۴ حرف) کنار گذاشته می‌شوند تا جای اشتباهی عوض نشود
function arch_name_map(array $pairs) {
    $out = [];
    foreach ($pairs as [$old, $new]) {
        $o = sanitize_folder_name((string)$old); $n = sanitize_folder_name((string)$new);
        if ($o === '' || $n === '' || $o === $n || mb_strlen($o) < 4) continue;
        $out[$o] = $n;
    }
    // نامِ بلندتر اول (تا بخشی از یک نامِ بلندتر زودتر عوض نشود)
    uksort($out, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    return $out;
}

function arch_apply_map($name, array $map) {
    foreach ($map as $o => $n) if (mb_strpos($name, $o) !== false) $name = str_replace($o, $n, $name);
    return $name;
}

// $roots: پوشه‌ها یا فایل‌ها. $withRoot = خودِ ریشه هم تغییر نام بدهد. خروجی: فهرستِ [قدیمی، تازه]
function arch_token_rename($pdo, $siteRoot, array $roots, array $map, $withRoot = true) {
    $done = [];
    if (!$map) return $done;
    $walk = function ($dir) use (&$walk, $map, $pdo, $siteRoot, &$done) {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            if (is_dir($p)) $walk($p);
            $nn = arch_apply_map($f, $map);
            if ($nn === $f) continue;
            $dst = $dir . '/' . $nn;
            if (file_exists($dst)) $dst = unique_dest_path($dst);
            if (@rename($p, $dst)) { arch_repath($pdo, $siteRoot, $p, $dst); $done[] = [$p, $dst]; }
        }
    };
    foreach (array_unique(array_filter($roots)) as $r) {
        if (!file_exists($r)) continue;
        if (is_dir($r)) $walk($r);
        if (!$withRoot) continue;
        $nn = arch_apply_map(basename($r), $map);
        if ($nn === basename($r)) continue;
        $dst = dirname($r) . '/' . $nn;
        if (file_exists($dst)) {
            if (is_dir($r) && is_dir($dst) && function_exists('archive_move_dir')) { archive_move_dir($r, $dst); arch_repath($pdo, $siteRoot, $r, $dst); $done[] = [$r, $dst]; }
            continue;
        }
        if (@rename($r, $dst)) { arch_repath($pdo, $siteRoot, $r, $dst); $done[] = [$r, $dst]; }
    }
    return $done;
}

// بعد از جابه‌جاییِ ماهِ معرفی‌نامه (مثلاً تاریخ عقب و دوباره جلو رفت): اگر در یک ماه دو پوشه از همین معرفی‌نامه مانده باشد،
// محتوای اضافی در پوشه‌ی اصلی ادغام می‌شود (چیزی بازنویسی نمی‌شود) و پوشه‌ی خالی برداشته می‌شود
function intro_dedupe_month_folders($pdo, $siteRoot, $introId) {
    $st = $pdo->prepare("SELECT i.folder_path, p.full_name, p.national_code, p.personnel_code, c.name AS company_name
                           FROM introductions i JOIN persons p ON i.person_id = p.id LEFT JOIN companies c ON p.company_id = c.id WHERE i.id = ?");
    $st->execute([$introId]);
    $I = $st->fetch();
    if (!$I) return 0;
    $plain = build_intro_folder_name($I['full_name'], $I['national_code'], $I['personnel_code'], $I['company_name']);
    $bases = [$plain];
    for ($m = 1; $m <= 12; $m++) $bases[] = sanitize_folder_name('(' . jalali_month_name($m) . ') ' . $plain);
    $isMine = function ($f) use ($bases) {
        foreach ($bases as $b) {
            if ($f === $b) return true;
            if (strpos($f, $b . NAME_SEP) === 0 && preg_match('/^\d+$/', substr($f, strlen($b . NAME_SEP)))) return true;
        }
        return false;
    };
    $used = function ($path) use ($pdo) {
        foreach ([['policy_cases', 'folder_path'], ['introductions', 'folder_path']] as [$t, $c]) {
            $q = $pdo->prepare("SELECT 1 FROM `$t` WHERE `$c` = ? OR LEFT(`$c`, CHAR_LENGTH(?)) = ? LIMIT 1");
            $q->execute([$path, $path . '/', $path . '/']);
            if ($q->fetchColumn()) return true;
        }
        return false;
    };
    $kasr = archive_root($siteRoot) . '/بایگانی کسر از حقوق';
    $merged = 0;
    if (!is_dir($kasr)) return 0;
    foreach (scandir($kasr) as $y) {
        if (!preg_match('/^\d{4}$/', $y)) continue;
        foreach (scandir("$kasr/$y") ?: [] as $mo) {
            $md = "$kasr/$y/$mo";
            if ($mo === '.' || $mo === '..' || !is_dir($md)) continue;
            $mine = array_values(array_filter(scandir($md) ?: [], function ($f) use ($md, $isMine) { return $f !== '.' && $f !== '..' && is_dir("$md/$f") && $isMine($f); }));
            if (count($mine) < 2) continue;
            $keep = null;
            foreach ($mine as $f) if ("$md/$f" === $I['folder_path']) $keep = $f;
            if (!$keep) foreach ($mine as $f) if ($used("$md/$f")) { $keep = $f; break; }
            if (!$keep) $keep = $mine[0];
            foreach ($mine as $f) {
                if ($f === $keep || $used("$md/$f")) continue;
                archive_move_dir("$md/$f", "$md/$keep");
                arch_repath($pdo, $siteRoot, "$md/$f", "$md/$keep");
                $merged++;
            }
        }
    }
    return $merged;
}
