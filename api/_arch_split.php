<?php
// فایل: _arch_split.php
// «مرتب‌سازیِ بایگانی بر اساسِ بیمه‌گر» (یک‌بار، فقط مدیر کل، از «بایگانی فایل‌ها»):
//   ۱. محتوای فعلیِ «بایگانی صادره»، «بایگانی شرکتی»، «بایگانی شخصی» و «مالی» (همه پاسارگادی‌اند) به زیرپوشه‌ی
//      «بایگانی پاسارگاد» همان بخش می‌رود (rename روی همان دیسک؛ هیچ فایلی کپی/پاک نمی‌شود).
//   ۲. همه‌ی مسیرهای ذخیره‌شده در پایگاهِ داده (همه‌ی ستون‌های متنی، هم مسیرِ ساده و هم داخلِ JSON) به‌روز می‌شوند.
//   ۳. تنظیمِ arch_insurer_split روشن می‌شود تا از این به بعد پاسارگاد هم مستقیم در «بایگانی پاسارگاد» بنشیند.
//   ۴. ردیف‌های درخواست‌های «ایران» (پوشه‌ی ردیف، نامه، مدارکِ سطحِ درخواست و کپیِ صادره) به «بایگانی ایران» می‌روند.
// دوباره‌اجراکردن بی‌خطر است (مسیرهای «بایگانی پاسارگاد/ایران» دوباره عوض نمی‌شوند).
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_company_helpers.php';

function arch_split_sections() { return ['بایگانی صادره', 'بایگانی شرکتی', 'بایگانی شخصی', 'مالی']; }

// چهار شکلِ ذخیره‌ی یک تکه‌مسیر: ساده، JSON با «\/»، JSON با \uXXXX (با و بی «\/»)
function arch_split_variants($s) {
    $out = [$s];
    foreach ([JSON_UNESCAPED_UNICODE, 0, JSON_UNESCAPED_SLASHES] as $f) $out[] = substr(json_encode($s, $f), 1, -1);
    return array_values(array_unique($out));
}

// ستون‌های متنیِ همه‌ی جدول‌ها با کلیدِ اصلیِ تک‌ستونی
function arch_split_columns($pdo) {
    $pk = [];
    foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_KEY = 'PRI'") as $r) $pk[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
    $out = [];
    foreach ($pdo->query("SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
                           WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')") as $r) {
        $t = $r['TABLE_NAME'];
        if (count($pk[$t] ?? []) !== 1 || $t === 'system_settings' && $r['COLUMN_NAME'] === 'setting_key') continue;
        $out[] = [$t, $pk[$t][0], $r['COLUMN_NAME']];
    }
    return $out;
}

// $fn(string): string روی همه‌ی مقدارهایی که $needle دارند. خروجی: تعدادِ ردیف‌های عوض‌شده (یا عوض‌شدنی در $dry)
function arch_split_db_transform($pdo, callable $fn, $needle, $dry = false) {
    $n = 0;
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
    foreach (arch_split_columns($pdo) as [$t, $pk, $c]) {
        $last = null;
        while (true) {
            try {
                $sql = "SELECT `$pk` AS k, `$c` AS v FROM `$t` WHERE `$c` LIKE ?" . ($last !== null ? " AND `$pk` > ?" : '') . " ORDER BY `$pk` LIMIT 500";
                $st = $pdo->prepare($sql);
                $st->execute($last !== null ? [$like, $last] : [$like]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { break; }
            if (!$rows) break;
            foreach ($rows as $r) {
                $last = $r['k'];
                $v = (string)$r['v'];
                $nv = $fn($v);
                if ($nv === $v) continue;
                $n++;
                if (!$dry) { try { $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$pk` = ?")->execute([$nv, $r['k']]); } catch (Throwable $e) { error_log('[arch split db] ' . $t . '.' . $c . ': ' . $e->getMessage()); } }
            }
            if (count($rows) < 500) break;
        }
    }
    return $n;
}

// «Archive/بایگانی/{بخش}/X» => «Archive/بایگانی/{بخش}/بایگانی پاسارگاد/X» (مگر X خودش پوشه‌ی بیمه‌گر باشد)
function arch_split_pasargad_fn() {
    $rules = [];
    foreach (arch_split_sections() as $sec) {
        $pre = arch_split_variants('Archive/بایگانی/' . $sec . '/');
        $pas = arch_split_variants('بایگانی پاسارگاد/');
        $ira = arch_split_variants('بایگانی ایران/');
        foreach ($pre as $i => $p) {
            $rx = '~' . preg_quote($p, '~') . '(?!' . preg_quote($pas[$i] ?? $pas[0], '~') . '|' . preg_quote($ira[$i] ?? $ira[0], '~') . ')~u';
            $rules[] = [$rx, $p . ($pas[$i] ?? $pas[0])];
        }
    }
    return function ($v) use ($rules) {
        foreach ($rules as [$rx, $rep]) { $nv = @preg_replace_callback($rx, function () use ($rep) { return $rep; }, $v); if (is_string($nv)) $v = $nv; }
        return $v;
    };
}

// جابه‌جاییِ یک پوشه/فایل (ادغام اگر مقصد هست)
function arch_split_move($src, $dst) {
    if (!file_exists($src)) return false;
    if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0775, true);
    if (is_dir($src)) return archive_move_dir($src, $dst);
    if (file_exists($dst)) return false;
    return @rename($src, $dst);
}

// ردیف‌ها/نامه/مدارکِ درخواست‌های ایران که هنوز زیرِ «بایگانی پاسارگاد» (یا مسیرِ قدیمی) هستند => [[قدیم، جدید], ...] (مسیرهای مطلق)
function arch_split_iran_moves($pdo, $siteRoot) {
    $moves = [];
    $arch = archive_root($siteRoot);
    $map = function ($abs) use ($arch) {
        foreach (['بایگانی شرکتی', 'بایگانی شخصی', 'بایگانی صادره', 'مالی'] as $sec) {
            foreach (['/بایگانی پاسارگاد/', '/'] as $from) {
                $p = $arch . '/' . $sec . $from;
                if (strpos($abs, $p) !== 0) continue;
                $rest = substr($abs, strlen($p));
                if ($from === '/' && preg_match('~^بایگانی (پاسارگاد|ایران)/~u', $rest)) continue;
                return $arch . '/' . $sec . '/بایگانی ایران/' . $rest;
            }
        }
        return null;
    };
    $abs = function ($p) use ($siteRoot) { $p = (string)$p; return $p === '' ? '' : ($p[0] === '/' ? $p : $siteRoot . '/' . ltrim($p, '/')); };
    try {
        $st = $pdo->query("SELECT crp.id, crp.folder_path, crp.status, crp.folder_status, crp.insurance_type, crp.policy_issue_date, crp.issued_at, cr.request_kind
                             FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE cr.insurer = 'IRAN'");
        foreach ($st as $r) {
            $f = $abs($r['folder_path']);
            if ($f && ($to = $map($f)) && file_exists($f)) $moves[] = [$f, $to];
            if ($f && $r['status'] === 'ISSUED' && company_kind_goes_to_sadere($r['request_kind'] ?: 'NEW_POLICY')) {
                $ts = policy_issue_ts((string)$r['policy_issue_date'], $r['issued_at'] ? strtotime($r['issued_at']) : time());
                foreach (['', '/بایگانی پاسارگاد'] as $ins) {
                    [$jy, $jm, ] = jalali_from_gregorian_ts($ts);
                    $sd = $arch . '/بایگانی صادره' . $ins . '/' . $jy . '/' . jalali_month_name($jm) . '/' . insurance_type_fa($r['insurance_type']) . '/' . basename($f);
                    if (is_dir($sd)) $moves[] = [$sd, build_sadere_base_path($siteRoot, $ts, $r['insurance_type'], 'IRAN') . '/' . basename($f)];
                }
            }
        }
    } catch (Throwable $e) { error_log('[arch split iran] ' . $e->getMessage()); }
    foreach (["SELECT letter_file_path AS p FROM company_requests WHERE insurer = 'IRAN' AND letter_file_path IS NOT NULL AND letter_file_path <> ''",
              "SELECT d.file_path AS p FROM company_documents d JOIN company_requests cr ON cr.id = d.request_id WHERE cr.insurer = 'IRAN' AND d.plate_id IS NULL AND d.file_path IS NOT NULL"] as $sql) {
        try { foreach ($pdo->query($sql) as $r) { $f = $abs($r['p']); if (is_file($f) && ($to = $map($f))) $moves[] = [$f, $to]; } } catch (Throwable $e) {}
    }
    // تکراری‌ها و مواردی که داخلِ یک پوشه‌ی جابه‌جاشونده‌ی دیگرند
    $uniq = [];
    foreach ($moves as $m) $uniq[$m[0]] = $m;
    $dirs = array_filter(array_keys($uniq), 'is_dir');
    foreach (array_keys($uniq) as $k) foreach ($dirs as $d) if ($k !== $d && strpos($k, $d . '/') === 0) { unset($uniq[$k]); break; }
    return array_values($uniq);
}

// مسیرِ مطلق => مسیرِ نسبی از ریشه‌ی سایت (برای جایگزینیِ زیررشته‌ای که هم مسیرهای مطلق و هم نسبی را می‌گیرد)
function arch_split_rel($siteRoot, $abs) { $r = rtrim($siteRoot, '/') . '/'; return strpos($abs, $r) === 0 ? substr($abs, strlen($r)) : $abs; }

function arch_split_plan($pdo, $siteRoot) {
    $arch = archive_root($siteRoot);
    $top = [];
    foreach (arch_split_sections() as $sec) {
        $n = 0;
        foreach ((@scandir($arch . '/' . $sec) ?: []) as $f) {
            if ($f === '.' || $f === '..' || $f === '' || $f[0] === '.' || in_array($f, arch_insurer_dirs(), true)) continue;
            $n++;
        }
        $top[$sec] = $n;
    }
    $dbRows = arch_split_db_transform($pdo, arch_split_pasargad_fn(), 'Archive', true);
    $iran = arch_split_iran_moves($pdo, $siteRoot);
    return ['ok' => true, 'done' => arch_split_on(true), 'top' => $top, 'db_rows' => $dbRows, 'iran' => count($iran),
            'iran_list' => array_slice(array_map(function ($m) use ($arch) { return substr($m[0], strlen($arch) + 1); }, $iran), 0, 80)];
}

function arch_split_run($pdo, $siteRoot) {
    @set_time_limit(0);
    $lock = @fopen(sys_get_temp_dir() . '/arch_split_' . md5($siteRoot) . '.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => false, 'error' => 'مرتب‌سازی همین الان در حالِ اجراست.'];
    $arch = archive_root($siteRoot);
    $moved = 0; $failed = [];
    // ۱. فایل‌ها
    foreach (arch_split_sections() as $sec) {
        $base = $arch . '/' . $sec;
        if (!is_dir($base)) { @mkdir($base . '/بایگانی پاسارگاد', 0775, true); continue; }
        @mkdir($base . '/بایگانی پاسارگاد', 0775, true);
        foreach ((@scandir($base) ?: []) as $f) {
            if ($f === '.' || $f === '..' || $f === '' || $f[0] === '.' || in_array($f, arch_insurer_dirs(), true)) continue;
            if (arch_split_move($base . '/' . $f, $base . '/بایگانی پاسارگاد/' . $f)) $moved++; else $failed[] = $sec . '/' . $f;
        }
    }
    // ۲. پایگاهِ داده
    $dbRows = arch_split_db_transform($pdo, arch_split_pasargad_fn(), 'Archive');
    // ۳. روشن کردنِ تفکیک
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('arch_insurer_split', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
    arch_split_on(true);
    // ۴. درخواست‌های ایران
    $iranMoves = arch_split_iran_moves($pdo, $siteRoot);
    $pairs = []; $iran = 0;
    foreach ($iranMoves as [$from, $to]) {
        if (!arch_split_move($from, $to)) { $failed[] = substr($from, strlen($arch) + 1); continue; }
        $iran++;
        $pairs[] = [arch_split_rel($siteRoot, $from), arch_split_rel($siteRoot, $to)];
    }
    if ($pairs) {
        // جایگزینی فقط با مرزِ مسیر (بعدش «/»، «\/»، «"» یا پایانِ مقدار) تا «پوشه‌ی الف» در «پوشه‌ی الف۲» عوض نشود
        $rules = [];
        foreach ($pairs as [$o, $n]) {
            $vo = arch_split_variants($o); $vn = arch_split_variants($n);
            foreach ($vo as $i => $x) $rules[] = ['~' . preg_quote($x, '~') . '(?=/|\\\\|"|$)~u', $vn[$i] ?? $vn[0]];
        }
        $dbRows += arch_split_db_transform($pdo, function ($v) use ($rules) {
            foreach ($rules as [$rx, $rep]) { $nv = @preg_replace_callback($rx, function () use ($rep) { return $rep; }, $v); if (is_string($nv)) $v = $nv; }
            return $v;
        }, 'Archive');
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return ['ok' => true, 'moved' => $moved, 'iran' => $iran, 'db_rows' => $dbRows, 'failed' => array_slice($failed, 0, 50)];
}
