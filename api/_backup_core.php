<?php
// فایل: api/_backup_core.php
// پشتیبان‌گیری کامل و بازیابی (هم‌روش با BackupManager / DatabaseResetManager برنامه‌ی ویندوزی ماموت):
//   - همه‌ی جدول‌های دیتابیس (ساختار + همه‌ی ردیف‌ها) در database.sql
//   - همه‌ی فایل‌های داده: بایگانی (مدارک، بیمه‌نامه‌ها، صورتحساب‌ها، فیش‌ها، اکسل‌ها، گزارش‌های بازدید)،
//     موقت‌ها، صف OCR، قالب‌های Word صورتحساب و گزارش، قلم‌ها و الگوریتم‌های گزارش
//   - manifest.json: تاریخ، تعداد ردیف هر جدول و تعداد فایل‌ها (برای بررسی سالم‌بودن)
// خروجی یک فایل ZIP است در backup/<تاریخ شمسی همان روز>/ که از «تنظیمات سیستم ← پشتیبان‌گیری» دوباره
// ایمپورت می‌شود و همه‌چیز را ریزبه‌ریز برمی‌گرداند. database.sql را جداگانه هم می‌شود در phpMyAdmin ایمپورت کرد.
// رمز دیتابیس (config/) هرگز داخل بکاپ نمی‌رود.
require_once __DIR__ . '/_case_helpers.php';

const BK_VERSION = 1;

function bk_site_root() { return dirname(__DIR__); }
function bk_root() { return bk_site_root() . '/backup'; }

// پوشه‌هایی از روت سایت که داده‌اند و در بکاپ کامل می‌آیند
function bk_file_roots() {
    return ['Archive', 'موقت', 'بایگانی', 'tmp_ocr', 'tmp_recon', 'queue', 'tmpl_invoice',
            'report_assets/templates', 'report_assets/layouts', 'report_assets/fonts', 'report_assets/parsers', 'Image'];
}

// پوشه‌هایی که پیش از بازیابی کاملاً خالی می‌شوند (همان‌هایی که «حذف اطلاعات» هم پاک می‌کند)؛
// بقیه (قالب‌ها، قلم‌ها، الگوریتم‌ها) فقط بازنویسی/اضافه می‌شوند
function bk_wipe_roots() {
    return ['Archive/بایگانی', 'Archive/مالی', 'موقت', 'بایگانی', 'tmp_ocr', 'tmp_recon',
            'queue/pending', 'queue/done', 'queue/case_uploads', 'queue/attachments'];
}

function bk_prepare_root() {
    $root = bk_root();
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new Exception('ساخت پوشه‌ی backup ممکن نشد (دسترسی نوشتن روی روت سایت را بررسی کنید).');
    }
    // پوشه‌ی بکاپ نباید از بیرون قابل دانلود باشد
    if (!is_file($root . '/.htaccess')) {
        @file_put_contents($root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n");
    }
    if (!is_file($root . '/index.html')) @file_put_contents($root . '/index.html', '');
    return $root;
}

function bk_long_task() {
    @set_time_limit(0);
    @ignore_user_abort(true);
    $mem = ini_get('memory_limit');
    if ($mem !== '-1' && intval($mem) < 512 && stripos((string)$mem, 'G') === false) @ini_set('memory_limit', '512M');
}

function bk_today_folder() {
    [$jy, $jm, $jd] = jalali_from_gregorian_ts(time());
    return sprintf('%04d.%02d.%02d', $jy, $jm, $jd);
}

function bk_tables($pdo) {
    $out = [];
    foreach ($pdo->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM) as $r) {
        if (strtoupper($r[1] ?? 'BASE TABLE') === 'BASE TABLE') $out[] = $r[0];
    }
    return $out;
}

// ---------------------------------------------------------------------
//  دامپ دیتابیس: هر دستور در یک خط (مقادیر با quote، پس هیچ خط‌شکستگی خامی داخل دستورها نیست)
// ---------------------------------------------------------------------
function bk_dump_database($pdo, $sqlPath) {
    $fh = fopen($sqlPath, 'wb');
    if (!$fh) throw new Exception('ساخت فایل موقت دیتابیس ممکن نشد.');
    $counts = [];
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    fwrite($fh, "-- بکاپ کامل دیتابیس «بیمه با ما» ({$dbName}) - " . jalali_from_gregorian_ts_dotted(time()) . ' ' . date('H:i') . "\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n");
    $prevTz = $pdo->query("SELECT @@session.time_zone")->fetchColumn();
    $pdo->exec("SET time_zone = '+00:00'");
    try {
        foreach (bk_tables($pdo) as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS $q;\n");
            // SHOW CREATE TABLE چندخطی است؛ خط‌شکستگی‌ها (فقط بیرون از رشته‌ها هستند) به فاصله تبدیل می‌شوند.
            // (از regex بدون u استفاده نمی‌شود چون \s بایت‌های حروف فارسی مثل 0x85 را هم می‌گیرد)
            fwrite($fh, str_replace(["\r\n", "\n", "\r"], ' ', $create) . ";\n");

            // ستون‌های باینری به‌صورت hex نوشته می‌شوند
            $binary = [];
            foreach ($pdo->query("SHOW COLUMNS FROM $q")->fetchAll() as $c) {
                $binary[$c['Field']] = (bool)preg_match('/blob|binary/i', $c['Type']);
            }
            $n = 0; $batch = []; $batchLen = 0; $cols = null;
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $st = $pdo->query("SELECT * FROM $q");
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = '(' . implode(',', array_map(function ($c) { return '`' . str_replace('`', '``', $c) . '`'; }, array_keys($row))) . ')';
                }
                $vals = [];
                foreach ($row as $k => $v) {
                    if ($v === null) $vals[] = 'NULL';
                    elseif (!empty($binary[$k])) $vals[] = $v === '' ? "''" : '0x' . bin2hex($v);
                    else $vals[] = $pdo->quote((string)$v);
                }
                $line = '(' . implode(',', $vals) . ')';
                $batch[] = $line; $batchLen += strlen($line); $n++;
                if (count($batch) >= 200 || $batchLen > 700000) {
                    fwrite($fh, "INSERT INTO $q $cols VALUES " . implode(',', $batch) . ";\n");
                    $batch = []; $batchLen = 0;
                }
            }
            $st->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            if ($batch) fwrite($fh, "INSERT INTO $q $cols VALUES " . implode(',', $batch) . ";\n");
            $counts[$table] = $n;
        }
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);
        try { $pdo->exec("SET time_zone = " . $pdo->quote($prevTz)); } catch (Throwable $e) {}
    }
    return $counts;
}

// فهرست همه‌ی فایل‌های داده (مسیر نسبی => مسیر کامل)
function bk_collect_files() {
    $site = bk_site_root();
    $out = [];
    foreach (bk_file_roots() as $rel) {
        $dir = $site . '/' . $rel;
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $full = $f->getPathname();
            $r = ltrim(str_replace('\\', '/', substr($full, strlen($site))), '/');
            if (strpos($r, '__pycache__/') !== false || substr($r, -4) === '.pyc') continue;
            $out[$r] = $full;
        }
    }
    return $out;
}

function bk_unique_name($dir, $tag) {
    [$jy, $jm, $jd] = jalali_from_gregorian_ts(time());
    $base = sprintf('bime-backup_%04d%02d%02d-%s', $jy, $jm, $jd, date('Hi'));
    // بخش تصادفی: حتی اگر وب‌سرور .htaccess را نخواند، آدرس فایل قابل حدس نیست
    return $dir . '/' . $base . ($tag ? '_' . $tag : '') . '_' . bin2hex(random_bytes(4)) . '.zip';
}

// ---------------------------------------------------------------------
//  ساخت بکاپ. $mode: full (دیتابیس + فایل‌ها) | db (فقط دیتابیس)
//  $tag: برچسب لاتین نام فایل (manual / before-reset / before-restore)
// ---------------------------------------------------------------------
function bk_create($pdo, $mode = 'full', $tag = 'manual', $note = '', $userName = '') {
    bk_long_task();
    if (!class_exists('ZipArchive')) throw new Exception('افزونه‌ی ZipArchive روی PHP هاست فعال نیست.');
    $root = bk_prepare_root();
    $dir = $root . '/' . bk_today_folder();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new Exception('ساخت پوشه‌ی بکاپ امروز ممکن نشد.');

    $zipPath = bk_unique_name($dir, $tag);
    $sqlTmp = $zipPath . '.sql.tmp';
    try {
        $counts = bk_dump_database($pdo, $sqlTmp);
        $files = $mode === 'db' ? [] : bk_collect_files();
        $bytes = 0;
        foreach ($files as $full) $bytes += (int)@filesize($full);

        $manifest = [
            'app' => 'bime-site', 'version' => BK_VERSION, 'mode' => $mode, 'tag' => $tag, 'note' => $note,
            'created_by' => $userName, 'created_at' => date('c'),
            'created_jalali' => jalali_from_gregorian_ts_dotted(time()) . ' ' . date('H:i'),
            'tables' => $counts, 'rows' => array_sum($counts),
            'files' => count($files), 'files_bytes' => $bytes, 'sql_bytes' => filesize($sqlTmp),
            'roots' => $mode === 'db' ? [] : bk_file_roots(),
        ];

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception('ساخت فایل ZIP ممکن نشد.');
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFile($sqlTmp, 'database.sql');
        $store = ['jpg','jpeg','png','webp','gif','pdf','zip','docx','xlsx','rar','7z','mp4','heic'];
        foreach ($files as $rel => $full) {
            $name = 'files/' . $rel;
            $zip->addFile($full, $name);
            if (method_exists($zip, 'setCompressionName') && in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), $store, true)) {
                @$zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
        }
        if (!$zip->close()) throw new Exception('نوشتن فایل ZIP کامل نشد (فضای هاست را بررسی کنید).');

        // بررسی سالم‌بودن: فایل دوباره باز و شمارش می‌شود
        $chk = new ZipArchive();
        if ($chk->open($zipPath) !== true) throw new Exception('فایل بکاپ ساخته‌شده خوانا نیست.');
        $ok = $chk->numFiles === count($files) + 2 && $chk->statName('database.sql') && $chk->statName('database.sql')['size'] === $manifest['sql_bytes'];
        $chk->close();
        if (!$ok) throw new Exception('بکاپ کامل نیست (تعداد فایل‌های داخل ZIP با اطلاعات همخوانی ندارد).');
    } catch (Throwable $e) {
        @unlink($zipPath);
        @unlink($sqlTmp);
        if (is_dir($dir) && count(scandir($dir)) <= 2) @rmdir($dir);
        throw ($e instanceof Exception ? $e : new Exception($e->getMessage()));
    }
    @unlink($sqlTmp);
    return ['path' => $zipPath, 'rel' => bk_rel($zipPath), 'size' => filesize($zipPath), 'manifest' => $manifest];
}

function bk_rel($path) { return ltrim(str_replace('\\', '/', substr($path, strlen(bk_root()))), '/'); }

// مسیر امنِ یک بکاپ از روی نام نسبی (جلوگیری از ../)
function bk_resolve($rel) {
    $rel = str_replace('\\', '/', (string)$rel);
    if ($rel === '' || strpos($rel, '..') !== false || substr($rel, -4) !== '.zip') return null;
    $full = bk_root() . '/' . ltrim($rel, '/');
    $real = realpath($full);
    $root = realpath(bk_root());
    if (!$real || !$root || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) return null;
    return $real;
}

function bk_read_manifest($zipPath) {
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) return null;
    $m = $z->getFromName('manifest.json');
    $z->close();
    $m = $m ? json_decode($m, true) : null;
    return is_array($m) ? $m : null;
}

function bk_list() {
    $root = bk_root();
    $out = [];
    if (!is_dir($root)) return $out;
    foreach (scandir($root) as $day) {
        if ($day[0] === '.' || !is_dir("$root/$day")) continue;
        foreach (scandir("$root/$day") as $f) {
            if (substr($f, -4) !== '.zip') continue;
            $p = "$root/$day/$f";
            $m = bk_read_manifest($p) ?: [];
            $out[] = [
                'rel' => "$day/$f", 'day' => $day, 'name' => $f, 'size' => filesize($p), 'mtime' => filemtime($p),
                'mode' => $m['mode'] ?? '?', 'tag' => $m['tag'] ?? '', 'note' => $m['note'] ?? '',
                'created_jalali' => $m['created_jalali'] ?? '', 'created_by' => $m['created_by'] ?? '',
                'rows' => $m['rows'] ?? null, 'files' => $m['files'] ?? null, 'valid' => !empty($m) && ($m['app'] ?? '') === 'bime-site',
            ];
        }
    }
    usort($out, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    return $out;
}

// $skip: نامِ زیرپوشه‌هایی که در همین سطح دست نمی‌خورند
function bk_rrmdir_contents($dir, array $skip = []) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..' || $item === '.gitkeep' || in_array($item, $skip, true)) continue;
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) { bk_rrmdir_contents($path); @rmdir($path); }
        else { @unlink($path); }
    }
}

// ---------------------------------------------------------------------
//  بازیابی از یک فایل بکاپ: اول دیتابیس (همه‌ی جدول‌ها دقیقاً مثل زمان بکاپ)، بعد فایل‌ها
// ---------------------------------------------------------------------
function bk_restore($pdo, $zipPath, $withFiles = true) {
    bk_long_task();
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new Exception('فایل ZIP باز نشد یا خراب است.');
    $m = json_decode((string)$zip->getFromName('manifest.json'), true);
    if (!is_array($m) || ($m['app'] ?? '') !== 'bime-site' || !$zip->statName('database.sql')) {
        $zip->close();
        throw new Exception('این فایل، بکاپِ همین سامانه نیست (manifest.json یا database.sql پیدا نشد).');
    }

    // ۱) دیتابیس - database.sql خط‌به‌خط اجرا می‌شود (هر خط یک دستور کامل است)
    $stream = $zip->getStream('database.sql');
    if (!$stream) { $zip->close(); throw new Exception('خواندن database.sql ممکن نشد.'); }
    $executed = 0; $buf = '';
    $prevTz = $pdo->query("SELECT @@session.time_zone")->fetchColumn();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    try {
        while (($line = fgets($stream)) !== false) {
            $buf .= $line;
            if (substr(rtrim($buf, "\r\n"), -1) !== ';') continue;   // دستورِ ناتمام (فقط در دامپ‌های دستی)
            $sql = trim($buf); $buf = '';
            if ($sql === '' || strpos($sql, '--') === 0) continue;
            $pdo->exec($sql);
            $executed++;
        }
    } catch (Throwable $e) {
        fclose($stream); $zip->close();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        throw new Exception('بازیابی دیتابیس در میانه متوقف شد: ' . $e->getMessage());
    }
    fclose($stream);
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    try { $pdo->exec("SET time_zone = " . $pdo->quote($prevTz)); } catch (Throwable $e) {}

    // بررسی تعداد ردیف‌ها
    $mismatch = [];
    foreach (($m['tables'] ?? []) as $t => $n) {
        try {
            $c = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
            if ($c !== (int)$n) $mismatch[] = "$t ($c از $n)";
        } catch (Throwable $e) { $mismatch[] = "$t (ساخته نشد)"; }
    }

    // ۲) فایل‌ها
    $restored = 0; $failed = 0;
    if ($withFiles && ($m['mode'] ?? '') !== 'db') {
        $site = bk_site_root();
        foreach (bk_wipe_roots() as $rel) bk_rrmdir_contents($site . '/' . $rel);
        $allowed = bk_file_roots();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'files/') !== 0 || substr($name, -1) === '/') continue;
            $rel = substr($name, 6);
            if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || strpos($rel, ':') !== false) { $failed++; continue; }
            $ok = false;
            foreach ($allowed as $a) if (strpos($rel, $a . '/') === 0) { $ok = true; break; }
            if (!$ok) { $failed++; continue; }
            $dest = $site . '/' . $rel;
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0775, true);
            $in = $zip->getStream($name);
            $out = $in ? @fopen($dest, 'wb') : false;
            if ($in && $out) { stream_copy_to_stream($in, $out); fclose($out); fclose($in); $restored++; }
            else { if ($in) fclose($in); $failed++; }
        }
        // پوشه‌های اصلی حتی اگر خالی بودند ساخته شوند
        foreach ([archive_root($site) . '/بایگانی صادره', archive_root($site) . '/بایگانی کسر از حقوق',
                  archive_root($site) . '/بایگانی شرکتی', archive_root($site) . '/بایگانی وارداتی', archive_root($site) . '/بایگانی گزارشات بازدید',
                  temp_archive_root($site), temp_finance_root($site), finance_root($site)] as $d) @mkdir($d, 0775, true);
    }
    $zip->close();

    return ['statements' => $executed, 'tables' => count($m['tables'] ?? []), 'rows' => $m['rows'] ?? 0,
            'files' => $restored, 'files_failed' => $failed, 'mismatch' => $mismatch,
            'created_jalali' => $m['created_jalali'] ?? '', 'mode' => $m['mode'] ?? ''];
}

// =====================================================================
//  بخش‌های «حذف اطلاعات»: هر بخش جدول‌ها، پوشه‌ها و کارهای جانبیِ خودش را دارد.
//  تنظیمات (سیستم، ربات‌ها، مالی، قالب‌های صورتحساب، تنظیمات گزارش بازدید) هیچ‌وقت پاک نمی‌شوند.
//  جدولی که در هیچ بخشی نیست (مثلاً جدولِ تازه‌ای که بعداً اضافه شود) مالِ بخشِ «other» است.
// =====================================================================
function bk_reset_keep_tables() {
    return ['users', 'system_settings', 'finance_settings', 'invoice_templates',
            'report_categories', 'report_fields', 'report_fonts', 'report_visitors', 'report_visitor_categories',
            'report_insureds', 'report_insured_categories', 'life_templates'];
}
function bk_reset_sections() {
    return [
        'personnel' => ['label' => 'درخواست‌های کارکنان و بازدید سلامت',
            'desc' => 'اشخاص، معرفی‌نامه‌ها، پرونده‌ها و مدارک، بازدیدهای سلامت، تاریخچه‌ی بررسی‌ها و بایگانیِ صادره/کسر از حقوق/سایر مدارک',
            'tables' => ['persons', 'person_vehicles', 'introductions', 'policy_cases', 'case_documents', 'health_inspections', 'health_attempts', 'insurance_requests', 'review_log']],
        'companies' => ['label' => 'شرکت‌ها و درخواست‌های شرکتی',
            'desc' => 'شرکت‌ها، درخواست‌ها و ردیف‌ها، مدارک، کاربرانِ شرکت‌ها، چت و ربات شرکت‌ها، بایگانیِ شرکتی و بایگانیِ وارداتی',
            'tables' => ['companies', 'company_requests', 'company_request_plates', 'company_documents', 'company_portal_users', 'company_portal_user_companies',
                         'company_bot_state', 'company_chat_messages', 'bot_known_groups', 'import_batches']],
        'finance' => ['label' => 'مالی',
            'desc' => 'اقساط، دریافت‌ها و تخصیص‌ها، چک‌ها، صورتحساب‌ها، دوره‌ها، تسویه‌ها و مغایرت‌ها (شماره‌ی صورتحساب از اول)',
            'tables' => ['billing_periods', 'cheques', 'company_installments', 'company_payments', 'company_payment_allocations', 'invoices', 'invoice_lines',
                         'payments', 'payment_allocations', 'policy_installments', 'pasargad_settlements', 'pasargad_settlement_lines', 'reconciliations',
                         'fin_receipts', 'fin_receipt_lines']],
        'visit_reports' => ['label' => 'گزارش‌های بازدید',
            'desc' => 'گزارش‌های صادرشده با عکس‌ها و نسخه‌های قبلی، دفترِ اکسل و بایگانیِ گزارشات (شماره‌ی گزارش از اول؛ تنظیمات و قالب‌ها می‌مانند)',
            'tables' => ['visit_reports', 'visit_report_photos', 'visit_report_versions']],
        'life' => ['label' => 'بیمه عمر',
            'desc' => 'بیمه‌نامه‌ها و اقساطِ عمر، پرداخت‌ها، تماس‌ها و یادداشت‌ها، سابقه‌ی ورودِ اکسل و بایگانیِ بیمه عمر (قالب‌های رسید و تنظیمِ ستون‌ها می‌مانند)',
            'tables' => ['life_policies', 'life_installments', 'life_payments', 'life_notes', 'life_imports']],
        'messages' => ['label' => 'پیام‌ها، اعلان‌ها و لاگ‌ها',
            'desc' => 'تیکت‌ها، چت داخلی، اعلان‌ها، لاگ‌های ورود و کارها، کدهای ورود و درخواست‌های بازیابی رمز',
            'tables' => ['messages', 'staff_chat_messages', 'tickets', 'ticket_messages', 'admin_notifications', 'app_notifications', 'user_notifications',
                         'notification_cursors', 'audit_logs', 'login_logs', 'login_otps', 'phone_otps', 'password_reset_requests', 'bot_sessions', 'webapp_sessions']],
        'users' => ['label' => 'کاربرانِ داخلی (به‌جز مدیر کل)',
            'desc' => 'کارشناسان صدور و مالی و همکارانِ بیمه با ما (پنل عادی و پارسیان)؛ حساب‌های مدیر کل همیشه می‌مانند',
            'tables' => []],
        'other' => ['label' => 'صف پردازش و فایل‌های موقت',
            'desc' => 'صفِ OCR و فایل‌های رسیده از ربات/مینی‌اپ و هر جدولِ دیگری که در بخش‌های بالا نیست',
            'tables' => ['processing_queue']],
    ];
}
// جدول‌های هر بخش در همین دیتابیس (جدولِ ناشناخته => other)
function bk_reset_section_tables($pdo) {
    $secs = bk_reset_sections();
    $known = [];
    foreach ($secs as $k => $s) foreach ($s['tables'] as $t) $known[$t] = $k;
    $out = array_fill_keys(array_keys($secs), []);
    foreach (bk_tables($pdo) as $t) {
        if (in_array($t, bk_reset_keep_tables(), true)) continue;
        $out[$known[$t] ?? 'other'][] = $t;
    }
    return $out;
}
// پاک کردنِ بخش‌های انتخاب‌شده. $full: حذفِ کامل (کلِ بایگانی و موقت‌ها، همان رفتارِ قبلی)
function bk_reset_run($pdo, array $targets, $full, $userId) {
    $siteRoot = bk_site_root();
    $byTable = bk_reset_section_tables($pdo);
    $wipe = function ($t) use ($pdo) {
        $q = '`' . str_replace('`', '``', $t) . '`';
        try { $pdo->exec("TRUNCATE TABLE $q"); } catch (Throwable $e) { try { $pdo->exec("DELETE FROM $q"); } catch (Throwable $e2) {} }
    };
    $try = function ($sql) use ($pdo) { try { $pdo->exec($sql); } catch (Throwable $e) { /* جدول/ستون هنوز نیست */ } };
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($targets as $sec) foreach ($byTable[$sec] ?? [] as $t) {
        // کاربرانِ شرکت‌ها با DELETE تا شناسه از اول شروع نشود (نشستِ بازِ کاربرِ پاک‌شده روی کاربرِ تازه ننشیند)
        if ($t === 'company_portal_users') $try("DELETE FROM `company_portal_users`"); else $wipe($t);
    }
    $has = fn($s) => in_array($s, $targets, true);
    if ($has('personnel')) {
        $try("UPDATE `report_insureds` SET `source` = 'MANUAL', `source_id` = NULL WHERE `source` = 'PERSON'");
        if (!$has('visit_reports')) $try("UPDATE `visit_reports` SET `case_id` = NULL, `health_inspection_id` = NULL");
    }
    if ($has('companies')) {
        $try("UPDATE `report_insureds` SET `source` = 'MANUAL', `source_id` = NULL WHERE `source` = 'COMPANY'");
        if (!$has('visit_reports')) $try("UPDATE `visit_reports` SET `company_plate_id` = NULL");
    }
    if ($has('finance')) $try("DELETE FROM `finance_settings` WHERE `setting_key` IN ('invoice_counter', 'invoice_counter_year')");
    if ($has('visit_reports')) $try("DELETE FROM `system_settings` WHERE `setting_key` LIKE 'report\\_no\\_last\\_%'");
    if ($has('users')) {
        $try("DELETE FROM `users` WHERE `role` <> 'ADMIN'");
        $try("DELETE FROM `users` WHERE COALESCE(`is_deleted`, 0) = 1 AND `id` <> " . intval($userId));
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // فایل‌ها
    $arch = archive_root($siteRoot); $tmp = temp_archive_root($siteRoot);
    if ($full) {
        foreach (['/Archive/بایگانی', '/Archive/مالی', '/موقت', '/بایگانی', '/tmp_ocr', '/tmp_recon',
                  '/queue/pending', '/queue/done', '/queue/case_uploads', '/queue/attachments'] as $rel) bk_rrmdir_contents($siteRoot . $rel);
    } else {
        if ($has('personnel')) {
            foreach (['/بایگانی صادره', '/بایگانی کسر از حقوق', '/سایر مدارک'] as $d) bk_rrmdir_contents($arch . $d);
            bk_rrmdir_contents($tmp, ['شرکت‌ها', 'چت شرکت‌ها', 'چت داخلی']);
        }
        if ($has('companies')) { bk_rrmdir_contents($arch . '/بایگانی شرکتی'); bk_rrmdir_contents($arch . '/بایگانی وارداتی'); bk_rrmdir_contents($tmp . '/شرکت‌ها'); bk_rrmdir_contents($tmp . '/چت شرکت‌ها'); }
        if ($has('finance')) { bk_rrmdir_contents(finance_root($siteRoot)); bk_rrmdir_contents(temp_finance_root($siteRoot)); }
        if ($has('visit_reports')) bk_rrmdir_contents($arch . '/بایگانی گزارشات بازدید');
        if ($has('life')) bk_rrmdir_contents($arch . '/بایگانی بیمه عمر');
        if ($has('messages')) bk_rrmdir_contents($tmp . '/چت داخلی');
        if ($has('other')) foreach (['/بایگانی', '/tmp_ocr', '/tmp_recon', '/queue/pending', '/queue/done', '/queue/case_uploads', '/queue/attachments'] as $rel) bk_rrmdir_contents($siteRoot . $rel);
    }
    if ($full || $has('messages')) @file_put_contents($siteRoot . '/queue/bale_debug.log', '');
    // پوشه‌های اصلیِ بایگانی دوباره ساخته می‌شوند تا خالی ولی مرتب دیده شوند
    foreach ([$arch . '/بایگانی صادره', $arch . '/بایگانی کسر از حقوق', $arch . '/سایر مدارک', $arch . '/بایگانی شرکتی', $arch . '/بایگانی وارداتی', $arch . '/بایگانی گزارشات بازدید',
              $tmp, temp_finance_root($siteRoot), finance_root($siteRoot)] as $dir) @mkdir($dir, 0775, true);
}
