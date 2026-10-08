<?php
// فایل: api/_lt.php
// «اتوماسیونِ نامه‌ها»: نامه‌های صادره/وارده/داخلی با شماره‌ی خودکار (الگوی قابلِ تنظیم)، سربرگ، دسته‌بندی (کدِ نوع)،
// فوریت و محرمانگی، ارجاع، پیوست، قالب، امضا، خروجیِ PDF و Word (و برگرداندنِ نسخه‌ی Word به سامانه)،
// و کارتابلِ شرکت‌ها در پنلِ شرکت (دیدنِ نامه و پاسخ با متن و فایل). همه‌ی جدول‌ها خودکار ساخته می‌شوند.

require_once __DIR__ . '/_case_helpers.php';

const LT_DIR = ['OUT' => 'صادره', 'IN' => 'وارده', 'INT' => 'داخلی'];
const LT_PRIORITY = ['normal' => 'عادی', 'urgent' => 'فوری', 'very_urgent' => 'خیلی فوری'];
const LT_CONF = ['normal' => 'عادی', 'confidential' => 'محرمانه', 'secret' => 'سرّی'];
const LT_STATUS = [
    'DRAFT' => 'پیش‌نویس', 'ISSUED' => 'صادر شده', 'SENT' => 'ارسال به کارتابلِ شرکت', 'SEEN' => 'دیده شد', 'REPLIED' => 'پاسخ داده شد',
    'NEW' => 'وارده‌ی جدید', 'REVIEWED' => 'بررسی شد', 'ARCHIVED' => 'بایگانی',
];
const LT_TOKENS = [
    '{FIX}' => 'عددِ ثابت', '{JY2}' => 'دو رقمِ آخرِ سالِ شمسی', '{JY4}' => 'سالِ شمسی (۴ رقم)', '{GY2}' => 'دو رقمِ آخرِ سالِ میلادی',
    '{GY4}' => 'سالِ میلادی (۴ رقم)', '{JM}' => 'ماهِ شمسی (۲ رقم)', '{GM}' => 'ماهِ میلادی (۲ رقم)', '{TYPE}' => 'کدِ نوعِ نامه (دسته)', '{SEQ}' => 'شماره‌ی ترتیبی',
];

function lt_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS lt_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, code VARCHAR(10) NOT NULL DEFAULT '0', color VARCHAR(10) NULL,
            icon VARCHAR(40) NULL, sort INT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, portal TINYINT NOT NULL DEFAULT 1) $o",
        "CREATE TABLE IF NOT EXISTS lt_letters (id INT AUTO_INCREMENT PRIMARY KEY, direction CHAR(3) NOT NULL DEFAULT 'OUT', number VARCHAR(60) NULL, seq INT NULL, category_id INT NULL,
            subject VARCHAR(300) NOT NULL DEFAULT '', company_id INT NULL, party_name VARCHAR(190) NULL, party_person VARCHAR(190) NULL, party_title VARCHAR(190) NULL, cc TEXT NULL,
            letter_j CHAR(10) NULL, letter_g DATE NULL, ext_number VARCHAR(60) NULL, ext_j CHAR(10) NULL, priority VARCHAR(12) NOT NULL DEFAULT 'normal', conf VARCHAR(12) NOT NULL DEFAULT 'normal',
            letterhead TINYINT NOT NULL DEFAULT 1, body_html MEDIUMTEXT NULL, attach_note VARCHAR(190) NULL, signer_id INT NULL, status VARCHAR(12) NOT NULL DEFAULT 'DRAFT',
            parent_id INT NULL, needs_reply TINYINT NOT NULL DEFAULT 0, reply_due_j CHAR(10) NULL, reply_due_g DATE NULL, to_portal TINYINT NOT NULL DEFAULT 0, word_path VARCHAR(400) NULL,
            signed_path VARCHAR(400) NULL, tags VARCHAR(300) NULL, created_by INT NULL, created_at DATETIME NULL, issued_by INT NULL, issued_at DATETIME NULL, sent_at DATETIME NULL,
            seen_at DATETIME NULL, seen_by_portal INT NULL, portal_user_id INT NULL, updated_by INT NULL, updated_at DATETIME NULL, is_deleted TINYINT NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL, deleted_by INT NULL, UNIQUE KEY u_number (number), KEY k_company (company_id), KEY k_parent (parent_id), KEY k_status (status), KEY k_date (letter_g)) $o",
        "CREATE TABLE IF NOT EXISTS lt_files (id INT AUTO_INCREMENT PRIMARY KEY, letter_id INT NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'ATTACH', orig_name VARCHAR(255) NOT NULL,
            path VARCHAR(400) NOT NULL, size INT NOT NULL DEFAULT 0, uploaded_by INT NULL, uploaded_by_portal INT NULL, created_at DATETIME NULL, KEY k_letter (letter_id)) $o",
        "CREATE TABLE IF NOT EXISTS lt_log (id INT AUTO_INCREMENT PRIMARY KEY, letter_id INT NOT NULL, actor_type VARCHAR(8) NOT NULL DEFAULT 'STAFF', actor_id INT NULL, actor_name VARCHAR(190) NULL,
            action VARCHAR(30) NOT NULL, detail VARCHAR(600) NULL, ip VARCHAR(64) NULL, created_at DATETIME NULL, KEY k_letter (letter_id)) $o",
        "CREATE TABLE IF NOT EXISTS lt_refs (id INT AUTO_INCREMENT PRIMARY KEY, letter_id INT NOT NULL, from_user INT NULL, to_user INT NOT NULL, note VARCHAR(1000) NULL, due_j CHAR(10) NULL,
            created_at DATETIME NULL, seen_at DATETIME NULL, done_at DATETIME NULL, done_note VARCHAR(1000) NULL, KEY k_letter (letter_id), KEY k_to (to_user, done_at)) $o",
        "CREATE TABLE IF NOT EXISTS lt_templates (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL, category_id INT NULL, subject VARCHAR(300) NULL, body_html MEDIUMTEXT NULL,
            sort INT NOT NULL DEFAULT 0, created_by INT NULL, created_at DATETIME NULL) $o",
        "CREATE TABLE IF NOT EXISTS lt_signers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, name VARCHAR(190) NOT NULL, title VARCHAR(190) NULL, image_path VARCHAR(400) NULL,
            sort INT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1) $o",
        "CREATE TABLE IF NOT EXISTS lt_counters (k VARCHAR(80) NOT NULL PRIMARY KEY, v INT NOT NULL DEFAULT 0) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[lt_ensure] ' . $e->getMessage()); } }
    try {
        if (!intval($pdo->query("SELECT COUNT(*) FROM lt_categories")->fetchColumn())) {
            $ins = $pdo->prepare("INSERT INTO lt_categories (name, code, color, icon, sort, active, portal) VALUES (?, ?, ?, ?, ?, 1, 1)");
            foreach ([['عمومی', '1', '#475569', 'fa-envelope'], ['ثالث و بدنه', '2', '#2563eb', 'fa-car'], ['بیمه عمر', '3', '#db2777', 'fa-heart-pulse'],
                      ['درمان تکمیلی', '4', '#059669', 'fa-stethoscope'], ['مالی', '5', '#d97706', 'fa-coins'], ['کارکنان (کسر از حقوق)', '6', '#7c3aed', 'fa-id-card'],
                      ['آتش‌سوزی و مهندسی', '7', '#dc2626', 'fa-fire']] as $k => $c) $ins->execute([$c[0], $c[1], $c[2], $c[3], $k + 1]);
        }
        if (!intval($pdo->query("SELECT COUNT(*) FROM lt_templates")->fetchColumn())) {
            $ins = $pdo->prepare("INSERT INTO lt_templates (title, category_id, subject, body_html, sort, created_at) VALUES (?, NULL, ?, ?, ?, NOW())");
            $ins->execute(['نامه‌ی عمومی', '', '<p>با سلام و احترام،</p><p>احتراماً، </p><p>پیشاپیش از همکاری و مساعدتِ جنابعالی سپاسگزاریم.</p>', 1]);
            $ins->execute(['اعلامِ تمدیدِ بیمه‌نامه', 'اعلامِ سررسید و تمدیدِ بیمه‌نامه‌ها', '<p>با سلام و احترام،</p><p>احتراماً، به استحضار می‌رساند بیمه‌نامه‌های آن شرکتِ محترم به‌زودی منقضی می‌شوند. خواهشمند است جهتِ تمدید و جلوگیری از وقفه در پوشش، مدارک و اطلاعاتِ لازم را تا تاریخِ ........ ارسال فرمایید.</p><p>با تشکر</p>', 2]);
            $ins->execute(['درخواستِ مدارک', 'درخواستِ تکمیلِ مدارک', '<p>با سلام و احترام،</p><p>احتراماً، جهتِ تکمیلِ پرونده‌ی {شرکت}، ارسالِ مدارکِ زیر ضروری است:</p><ul><li>........</li><li>........</li></ul><p>خواهشمند است دستور فرمایید در اسرعِ وقت اقدام شود.</p>', 3]);
            $ins->execute(['اعلامِ بدهی و اقساط', 'یادآوریِ اقساطِ سررسیدشده', '<p>با سلام و احترام،</p><p>احتراماً، به استحضار می‌رساند مبلغِ ........ ریال بابتِ اقساطِ سررسیدشده‌ی بیمه‌نامه‌های آن شرکت تاکنون پرداخت نشده است. خواهشمند است دستور فرمایید نسبت به تسویه اقدام شود.</p>', 4]);
        }
    } catch (Throwable $e) { error_log('[lt_seed] ' . $e->getMessage()); }
}

// ---------------------------------------------------------------------
//  تنظیمات
// ---------------------------------------------------------------------
function lt_defaults() {
    return [
        'pattern_out' => '{FIX}/{JY2}{TYPE}/{SEQ}', 'pattern_in' => 'و/{JY2}/{SEQ}', 'pattern_int' => 'د/{JY2}/{SEQ}',
        'fix' => '110', 'seq_digits' => 4, 'seq_start' => 1, 'seq_scope' => 'year',   // year | year_type | month | never
        'fa_digits' => 1,
        'letterhead' => '', 'letterhead_pages' => 'all',   // all | first
        'm_top' => 48, 'm_bottom' => 30, 'm_right' => 20, 'm_left' => 20,
        'show_fields' => 1, 'field_labels' => 1, 'f_x' => 14, 'f_y' => 18, 'f_gap' => 6.5, 'f_size' => 10,
        'font_size' => 12, 'line_height' => 1.9, 'word_font' => 'B Nazanin', 'footer_text' => '', 'page_numbers' => 1,
        'auto_head' => 1, 'default_signer' => 0, 'portal_enabled' => 1, 'notify_bot' => 1, 'reply_days' => 7,
        'default_letterhead' => 1, 'stamp_priority' => 1,
    ];
}
function lt_settings($pdo) {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'lt_settings'");
    $st->execute();
    $s = json_decode((string)$st->fetchColumn(), true);
    return array_merge(lt_defaults(), is_array($s) ? $s : []);
}
function lt_settings_save($pdo, array $in) {
    $s = lt_settings($pdo);
    $d = lt_defaults();
    foreach (['pattern_out', 'pattern_in', 'pattern_int'] as $k) if (array_key_exists($k, $in)) {
        $v = mb_substr(trim((string)$in[$k]), 0, 80);
        if ($v !== '' && strpos($v, '{SEQ}') === false) throw new RuntimeException('الگوی شماره باید {SEQ} (شماره‌ی ترتیبی) را داشته باشد تا شماره‌ها تکراری نشوند.');
        $s[$k] = $v ?: $d[$k];
    }
    if (array_key_exists('fix', $in)) $s['fix'] = mb_substr(p2e_digits(trim((string)$in['fix'])), 0, 20);
    if (array_key_exists('seq_digits', $in)) $s['seq_digits'] = max(1, min(8, intval(p2e_digits((string)$in['seq_digits']))));
    if (array_key_exists('seq_start', $in)) $s['seq_start'] = max(1, intval(p2e_digits((string)$in['seq_start'])));
    if (array_key_exists('seq_scope', $in)) $s['seq_scope'] = in_array($in['seq_scope'], ['year', 'year_type', 'month', 'never'], true) ? $in['seq_scope'] : 'year';
    if (array_key_exists('letterhead_pages', $in)) $s['letterhead_pages'] = $in['letterhead_pages'] === 'first' ? 'first' : 'all';
    foreach (['m_top' => [0, 150], 'm_bottom' => [0, 120], 'm_right' => [0, 80], 'm_left' => [0, 80], 'f_x' => [0, 190], 'f_y' => [0, 280], 'f_gap' => [3, 20],
              'f_size' => [7, 18], 'font_size' => [8, 22], 'line_height' => [1, 3], 'reply_days' => [0, 365]] as $k => [$a, $b])
        if (array_key_exists($k, $in)) $s[$k] = max($a, min($b, floatval(p2e_digits((string)$in[$k]))));
    foreach (['fa_digits', 'show_fields', 'field_labels', 'page_numbers', 'auto_head', 'portal_enabled', 'notify_bot', 'default_letterhead', 'stamp_priority'] as $k)
        if (array_key_exists($k, $in)) $s[$k] = !empty($in[$k]) ? 1 : 0;
    if (array_key_exists('word_font', $in)) $s['word_font'] = mb_substr(trim((string)$in['word_font']), 0, 60) ?: 'B Nazanin';
    if (array_key_exists('footer_text', $in)) $s['footer_text'] = mb_substr(trim((string)$in['footer_text']), 0, 300);
    if (array_key_exists('default_signer', $in)) $s['default_signer'] = intval($in['default_signer']);
    if (array_key_exists('letterhead', $in)) $s['letterhead'] = (string)$in['letterhead'];
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('lt_settings', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
    return $s;
}

// ---------------------------------------------------------------------
//  ابزارها
// ---------------------------------------------------------------------
function lt_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function lt_today_j() { [$y, $m, $d] = jalali_from_gregorian_ts(time()); return sprintf('%04d/%02d/%02d', $y, $m, $d); }
function lt_jdate($v) {
    $s = p2e_digits(trim((string)$v));
    if (!preg_match('/^(1[34]\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $s, $m)) return [null, null];
    if (intval($m[2]) < 1 || intval($m[2]) > 12 || intval($m[3]) < 1 || intval($m[3]) > 31) return [null, null];
    $ts = jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]));
    return [sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]), $ts ? date('Y-m-d', $ts) : null];
}
function lt_dt_j($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    [$y, $m, $d] = jalali_from_gregorian_ts($ts);
    return sprintf('%04d/%02d/%02d %s', $y, $m, $d, date('H:i', $ts));
}
function lt_root() { return archive_root(dirname(__DIR__)) . '/بایگانی نامه‌ها'; }
function lt_rel($abs) { $r = dirname(__DIR__) . '/'; return strpos($abs, $r) === 0 ? substr($abs, strlen($r)) : $abs; }
function lt_abs($rel) {
    if (!$rel) return null;
    $abs = realpath(dirname(__DIR__) . '/' . $rel);
    $root = realpath(dirname(__DIR__) . '/Archive');
    return ($abs && $root && strpos($abs, $root . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}
function lt_safe_name($s) {
    $s = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', (string)$s));
    return mb_substr(preg_replace('/\s+/u', ' ', $s), 0, 90) ?: 'file';
}
// پوشه‌ی هر نامه: بایگانی نامه‌ها/۱۴۰۵/صادره/۱۲۳
function lt_letter_dir(array $L) {
    $jy = $L['letter_j'] ? substr($L['letter_j'], 0, 4) : substr(lt_today_j(), 0, 4);
    $dir = lt_root() . '/' . $jy . '/' . (LT_DIR[$L['direction']] ?? 'سایر') . '/' . intval($L['id']);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
// بارگذاریِ فایل با پسوندهای مجاز
function lt_store_upload(array $f, $dir, $prefix = '') {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('فایل دریافت نشد.');
    if ($f['size'] > 25 * 1024 * 1024) throw new RuntimeException('حجمِ فایل بیشتر از ۲۵ مگابایت است.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'txt', 'rar'], true)) throw new RuntimeException('نوعِ فایل مجاز نیست (PDF، تصویر، Word، Excel یا ZIP).');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $base = lt_safe_name(pathinfo((string)$f['name'], PATHINFO_FILENAME));
    $name = ($prefix !== '' ? $prefix . ' - ' : '') . $base . '.' . $ext;
    $i = 1;
    while (file_exists($dir . '/' . $name)) $name = ($prefix !== '' ? $prefix . ' - ' : '') . $base . ' (' . (++$i) . ').' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('ذخیره‌ی فایل ناموفق بود.');
    return $dir . '/' . $name;
}
// فایل‌های چندتایی از $_FILES['files'] (یا تکی)
function lt_files_from($key) {
    $out = [];
    if (empty($_FILES[$key])) return $out;
    $F = $_FILES[$key];
    if (is_array($F['name'])) { foreach ($F['name'] as $i => $n) if (($F['error'][$i] ?? 4) !== UPLOAD_ERR_NO_FILE) $out[] = ['name' => $n, 'tmp_name' => $F['tmp_name'][$i], 'error' => $F['error'][$i], 'size' => $F['size'][$i]]; }
    elseif (($F['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) $out[] = $F;
    return $out;
}

function lt_categories($pdo, $activeOnly = false) {
    $o = [];
    foreach ($pdo->query("SELECT * FROM lt_categories" . ($activeOnly ? " WHERE active = 1" : '') . " ORDER BY sort, id") as $c) {
        foreach (['id', 'sort', 'active', 'portal'] as $k) $c[$k] = intval($c[$k]);
        $o[] = $c;
    }
    return $o;
}
function lt_category($pdo, $id) { foreach (lt_categories($pdo) as $c) if ($c['id'] === intval($id)) return $c; return null; }
function lt_signers($pdo, $activeOnly = false) {
    $o = [];
    foreach ($pdo->query("SELECT * FROM lt_signers" . ($activeOnly ? " WHERE active = 1" : '') . " ORDER BY sort, id") as $s) {
        foreach (['id', 'sort', 'active'] as $k) $s[$k] = intval($s[$k]);
        $s['user_id'] = $s['user_id'] ? intval($s['user_id']) : null;
        $s['has_image'] = (bool)lt_abs($s['image_path']);
        $o[] = $s;
    }
    return $o;
}
function lt_letter($pdo, $id) {
    $st = $pdo->prepare("SELECT l.*, c.name AS company_name FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id WHERE l.id = ? AND l.is_deleted = 0");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function lt_user_name($pdo, $id) {
    static $c = [];
    $id = intval($id);
    if (!$id) return '';
    if (!isset($c[$id])) { $st = $pdo->prepare("SELECT full_name FROM users WHERE id = ?"); $st->execute([$id]); $c[$id] = (string)($st->fetchColumn() ?: ''); }
    return $c[$id];
}
function lt_portal_name($pdo, $id) {
    $st = $pdo->prepare("SELECT full_name FROM company_portal_users WHERE id = ?");
    $st->execute([intval($id)]);
    return (string)($st->fetchColumn() ?: '');
}
function lt_log($pdo, $letterId, $action, $detail = '', $actorType = 'STAFF', $actorId = null, $actorName = null) {
    try {
        if ($actorId === null) $actorId = intval($_SESSION['user_id'] ?? 0) ?: null;
        if ($actorName === null) $actorName = $actorType === 'STAFF' ? lt_user_name($pdo, $actorId) : lt_portal_name($pdo, $actorId);
        $pdo->prepare("INSERT INTO lt_log (letter_id, actor_type, actor_id, actor_name, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([intval($letterId), $actorType, $actorId, mb_substr((string)$actorName, 0, 190), $action, mb_substr((string)$detail, 0, 600), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64)]);
    } catch (Throwable $e) { error_log('[lt_log] ' . $e->getMessage()); }
}

// ---------------------------------------------------------------------
//  شماره‌گذاری
// ---------------------------------------------------------------------
function lt_format_number($pattern, array $s, $code, $jy, $jm, $gy, $gm, $seq) {
    return strtr($pattern, [
        '{FIX}' => (string)$s['fix'], '{JY2}' => sprintf('%02d', $jy % 100), '{JY4}' => (string)$jy, '{GY2}' => sprintf('%02d', $gy % 100), '{GY4}' => (string)$gy,
        '{JM}' => sprintf('%02d', $jm), '{GM}' => sprintf('%02d', $gm), '{TYPE}' => (string)$code, '{SEQ}' => str_pad((string)$seq, intval($s['seq_digits']), '0', STR_PAD_LEFT),
    ]);
}
function lt_counter_key(array $s, $dir, $code, $jy, $jm) {
    $k = $dir;
    if ($s['seq_scope'] === 'year') $k .= ':' . $jy;
    elseif ($s['seq_scope'] === 'year_type') $k .= ':' . $jy . ':' . $code;
    elseif ($s['seq_scope'] === 'month') $k .= ':' . $jy . '-' . $jm;
    return $k;
}
// شماره‌ی بعدی (اتمی؛ دو نفر هم‌زمان شماره‌ی تکراری نمی‌گیرند)
function lt_next_number($pdo, $dir, $categoryId, $letterG = null) {
    $s = lt_settings($pdo);
    $cat = $categoryId ? lt_category($pdo, $categoryId) : null;
    $code = $cat ? $cat['code'] : '0';
    $ts = $letterG ? strtotime($letterG . ' 12:00:00') : time();
    [$jy, $jm] = jalali_from_gregorian_ts($ts);
    $gy = intval(date('Y', $ts)); $gm = intval(date('n', $ts));
    $pattern = $dir === 'IN' ? $s['pattern_in'] : ($dir === 'INT' ? $s['pattern_int'] : $s['pattern_out']);
    $key = lt_counter_key($s, $dir, $code, $jy, $jm);
    for ($try = 0; $try < 50; $try++) {
        $pdo->prepare("INSERT INTO lt_counters (k, v) VALUES (?, LAST_INSERT_ID(?)) ON DUPLICATE KEY UPDATE v = LAST_INSERT_ID(v + 1)")->execute([$key, intval($s['seq_start'])]);
        $seq = intval($pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn());
        $num = lt_format_number($pattern, $s, $code, $jy, $jm, $gy, $gm, $seq);
        $st = $pdo->prepare("SELECT COUNT(*) FROM lt_letters WHERE number = ?");
        $st->execute([$num]);
        if (!intval($st->fetchColumn())) return [$num, $seq];
    }
    throw new RuntimeException('ساختِ شماره‌ی یکتا ممکن نشد؛ الگوی شماره را بررسی کنید.');
}
// پیش‌نمایشِ شماره‌ی بعدی (بدونِ مصرفِ شمارنده)
function lt_preview_number($pdo, $dir, $categoryId, ?array $s = null) {
    $s = $s ?: lt_settings($pdo);
    $cat = $categoryId ? lt_category($pdo, $categoryId) : null;
    $code = $cat ? $cat['code'] : '0';
    [$jy, $jm] = jalali_from_gregorian_ts(time());
    $key = lt_counter_key($s, $dir, $code, $jy, $jm);
    $st = $pdo->prepare("SELECT v FROM lt_counters WHERE k = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    $next = $v === false ? intval($s['seq_start']) : intval($v) + 1;
    $pattern = $dir === 'IN' ? $s['pattern_in'] : ($dir === 'INT' ? $s['pattern_int'] : $s['pattern_out']);
    return ['number' => lt_format_number($pattern, $s, $code, $jy, $jm, intval(date('Y')), intval(date('n')), $next), 'key' => $key, 'next' => $next];
}

// ---------------------------------------------------------------------
//  پاک‌سازیِ HTMLِ ویرایشگر (فقط قالب‌بندیِ متن؛ بدونِ اسکریپت، لینک و تصویر)
// ---------------------------------------------------------------------
function lt_font_pt($v) {
    $v = strtolower(trim($v));
    $kw = ['xx-small' => 8, 'x-small' => 9, 'small' => 10.5, 'medium' => 12, 'large' => 14, 'x-large' => 18, 'xx-large' => 24, 'xxx-large' => 32, 'smaller' => 10, 'larger' => 14];
    if (isset($kw[$v])) return $kw[$v];
    if (preg_match('/^(\d+(?:\.\d+)?)(px|pt|em|rem|%)?$/', $v, $m)) {
        $n = floatval($m[1]); $u = $m[2] ?? 'px';
        $pt = $u === 'pt' ? $n : ($u === 'px' ? $n * 0.75 : ($u === '%' ? 12 * $n / 100 : 12 * $n));
        return max(6, min(48, round($pt * 2) / 2));
    }
    return null;
}
function lt_clean_style($style) {
    $out = [];
    foreach (explode(';', (string)$style) as $decl) {
        if (strpos($decl, ':') === false) continue;
        [$p, $v] = array_map('trim', explode(':', $decl, 2));
        $p = strtolower($p); $vl = strtolower($v);
        if (preg_match('/expression|url\s*\(|javascript/i', $v)) continue;
        if ($p === 'text-align' && in_array($vl, ['left', 'right', 'center', 'justify'], true)) $out[] = "text-align:$vl";
        elseif (in_array($p, ['color', 'background-color'], true) && preg_match('/^(#[0-9a-f]{3,8}|rgba?\([\d\s,.%]+\)|[a-z]{3,20})$/i', $v)) {
            if ($p === 'background-color' && in_array($vl, ['transparent', 'initial', 'inherit', 'rgba(0, 0, 0, 0)'], true)) continue;
            $out[] = "$p:" . lt_css_color($v);
        }
        elseif ($p === 'font-size' && ($pt = lt_font_pt($vl)) !== null) $out[] = "font-size:{$pt}pt";
        elseif ($p === 'font-weight' && preg_match('/^(bold|bolder|[6-9]00)$/', $vl)) $out[] = 'font-weight:bold';
        elseif ($p === 'font-style' && $vl === 'italic') $out[] = 'font-style:italic';
        elseif ($p === 'text-decoration' || $p === 'text-decoration-line') { if (strpos($vl, 'underline') !== false) $out[] = 'text-decoration:underline'; elseif (strpos($vl, 'line-through') !== false) $out[] = 'text-decoration:line-through'; }
        elseif ($p === 'width' && preg_match('/^\d{1,3}(\.\d+)?%$/', $vl)) $out[] = "width:$vl";
    }
    return implode(';', array_unique($out));
}
function lt_css_color($v) {
    $v = trim($v);
    if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $v, $m)) return sprintf('#%02x%02x%02x', min(255, $m[1]), min(255, $m[2]), min(255, $m[3]));
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $v, $m)) return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    if (preg_match('/^#[0-9a-f]{6}/i', $v)) return strtolower(substr($v, 0, 7));
    $named = ['black' => '#000000', 'white' => '#ffffff', 'red' => '#ff0000', 'blue' => '#0000ff', 'green' => '#008000', 'gray' => '#808080', 'grey' => '#808080',
              'navy' => '#000080', 'maroon' => '#800000', 'orange' => '#ffa500', 'yellow' => '#ffff00', 'purple' => '#800080'];
    return $named[strtolower($v)] ?? '#000000';
}
function lt_clean_html($html) {
    $html = trim((string)$html);
    if ($html === '') return '';
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="ltroot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $root = (new DOMXPath($doc))->query('//div[@id="ltroot"]')->item(0);
    if (!$root) return '';
    $allowed = ['p', 'div', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'span', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'h2', 'h3', 'h4', 'hr', 'sub', 'sup', 'blockquote'];
    $drop = ['script', 'style', 'iframe', 'object', 'embed', 'img', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'video', 'audio', 'head', 'title'];
    $walk = function (DOMNode $n) use (&$walk, $allowed, $drop, $doc) {
        $kids = [];
        foreach ($n->childNodes as $c) $kids[] = $c;
        foreach ($kids as $c) {
            if ($c->nodeType === XML_COMMENT_NODE || $c->nodeType === XML_PI_NODE) { $n->removeChild($c); continue; }
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($c->nodeName);
            if (in_array($tag, $drop, true)) { $n->removeChild($c); continue; }
            if ($tag === 'font') {   // <font color size> => span
                $sp = $doc->createElement('span');
                $st = [];
                if ($c->getAttribute('color')) $st[] = 'color:' . $c->getAttribute('color');
                if ($c->getAttribute('size')) { $map = [1 => 8, 2 => 10, 3 => 12, 4 => 14, 5 => 18, 6 => 24, 7 => 32]; $st[] = 'font-size:' . ($map[intval($c->getAttribute('size'))] ?? 12) . 'pt'; }
                if ($st) $sp->setAttribute('style', implode(';', $st));
                while ($c->firstChild) $sp->appendChild($c->firstChild);
                $n->replaceChild($sp, $c);
                $c = $sp; $tag = 'span';
            }
            if (!in_array($tag, $allowed, true)) {   // تگِ ناشناخته: فقط محتوایش می‌ماند
                $walk($c);
                while ($c->firstChild) $n->insertBefore($c->firstChild, $c);
                $n->removeChild($c);
                continue;
            }
            $keep = [];
            foreach (['style', 'colspan', 'rowspan', 'align'] as $a) if ($c->hasAttribute($a)) $keep[$a] = $c->getAttribute($a);
            while ($c->attributes->length) $c->removeAttribute($c->attributes->item(0)->nodeName);
            $style = lt_clean_style($keep['style'] ?? '');
            if (!empty($keep['align']) && in_array(strtolower($keep['align']), ['left', 'right', 'center', 'justify'], true) && strpos($style, 'text-align') === false)
                $style = trim($style . ';text-align:' . strtolower($keep['align']), ';');
            if ($style !== '') $c->setAttribute('style', $style);
            foreach (['colspan', 'rowspan'] as $a) if (!empty($keep[$a]) && intval($keep[$a]) > 1) $c->setAttribute($a, (string)min(20, intval($keep[$a])));
            $walk($c);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim($out);
}
// متنِ سادهٔ نامه (شکستِ سطرها می‌ماند)
function lt_plain($html) {
    $t = html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>', '</li>', '</tr>'], ["\n", "\n", "\n", "\n", "\n"], (string)$html)), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace(["/[ \t\x{00A0}]+/u", "/ *\n */u", "/\n{3,}/u"], [' ', "\n", "\n\n"], $t));
}

// ---------------------------------------------------------------------
//  ساختِ متنِ کاملِ نامه (سرِ نامه + متن + امضا + رونوشت) - مشترک بین پیش‌نمایش، PDF و Word
// ---------------------------------------------------------------------
function lt_party_line(array $L) { return trim((string)($L['company_name'] ?: $L['party_name'])); }
function lt_vars(array $L, array $s) {
    $num = $L['number'] ? ($s['fa_digits'] ? lt_fa($L['number']) : $L['number']) : '..........';
    return ['{شماره}' => $num, '{تاریخ}' => lt_fa($L['letter_j'] ?: lt_today_j()), '{امروز}' => lt_fa(lt_today_j()), '{شرکت}' => lt_party_line($L), '{گیرنده}' => (string)$L['party_person'],
            '{سمت}' => (string)$L['party_title'], '{موضوع}' => (string)$L['subject'], '{پیوست}' => (string)($L['attach_note'] ?: 'ندارد')];
}
function lt_head_html(array $L) {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $party = lt_party_line($L);
    $lines = [];
    if ($L['direction'] === 'IN') return '';
    if (trim((string)$L['party_person']) !== '') {
        $lines[] = '<b>' . $h($L['party_person']) . '</b>';
        $t = trim((string)$L['party_title']);
        if ($t !== '' || $party !== '') $lines[] = $h(trim(($t !== '' ? $t . ' محترم ' : '') . $party));
    } elseif ($party !== '') {
        $t = trim((string)$L['party_title']);
        $lines[] = '<b>' . $h(($t !== '' ? $t . ' محترم ' : '') . $party) . '</b>';
    }
    $out = $lines ? '<p>' . implode('<br>', $lines) . '</p>' : '';
    if (trim((string)$L['subject']) !== '') $out .= '<p><b>موضوع: ' . $h($L['subject']) . '</b></p>';
    return $out;
}
function lt_sign_html($pdo, array $L, $forPdf = true) {
    if (empty($L['signer_id'])) return '';
    $st = $pdo->prepare("SELECT * FROM lt_signers WHERE id = ?");
    $st->execute([intval($L['signer_id'])]);
    $sg = $st->fetch();
    if (!$sg) return '';
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $img = lt_abs($sg['image_path']);
    $imgHtml = '';
    if ($img && in_array($L['status'], ['ISSUED', 'SENT', 'SEEN', 'REPLIED', 'ARCHIVED', 'REVIEWED'], true)) {
        // $forPdf: true = PDF (مسیرِ فایل)، 'web' = پیش‌نمایشِ پنل (نشانیِ API)، false = Word
        $imgHtml = $forPdf === 'web' ? '<br><img src="api/lt_actions.php?action=signer_img&amp;id=' . intval($sg['id']) . '" style="height:16mm">'
                 : ($forPdf ? '<br><img src="' . $h($img) . '" height="60">' : '<br><img data-lt-path="' . $h($img) . '">');
    }
    return '<table width="100%" cellpadding="0" cellspacing="0" data-lt-sign="1"><tr><td width="55%"></td><td width="45%" style="text-align:center"><b>' . $h($sg['name']) . '</b>'
         . ($sg['title'] ? '<br>' . $h($sg['title']) : '') . $imgHtml . '</td></tr></table>';
}
function lt_cc_html(array $L) {
    $cc = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$L['cc']))));
    if (!$cc) return '';
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    return '<p style="font-size:9.5pt"><b>رونوشت:</b><br>' . implode('<br>', array_map(function ($c) use ($h) { return '- ' . $h($c); }, $cc)) . '</p>';
}
function lt_compose_html($pdo, array $L, $forPdf = true) {
    $s = lt_settings($pdo);
    $vars = lt_vars($L, $s);
    $esc = array_map(function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }, $vars);
    $body = strtr((string)$L['body_html'], $esc);
    return ($s['auto_head'] ? lt_head_html($L) : '') . $body . lt_sign_html($pdo, $L, $forPdf) . lt_cc_html($L);
}

// ---------------------------------------------------------------------
//  PDF (TCPDF): سربرگ پشتِ صفحه، شماره/تاریخ/پیوست در جای تعیین‌شده، مهرِ فوری/محرمانه
// ---------------------------------------------------------------------
function lt_pdf($pdo, array $L, $dest = 'S') {
    require_once __DIR__ . '/_lt_pdf.php';
    $s = lt_settings($pdo);
    $pdf = new LtPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('بیمه با ما'); $pdf->SetAuthor('بیمه با ما'); $pdf->SetTitle(($L['number'] ? $L['number'] . ' - ' : '') . $L['subject']);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $bg = (!empty($L['letterhead']) && $s['letterhead']) ? lt_abs($s['letterhead']) : null;
    $pdf->ltBg = $bg; $pdf->ltBgFirst = $s['letterhead_pages'] === 'first'; $pdf->ltFooter = (string)$s['footer_text']; $pdf->ltPageNo = !empty($s['page_numbers']);
    $pdf->setPrintHeader(true); $pdf->setPrintFooter(true);
    $pdf->SetMargins(floatval($s['m_left']), floatval($s['m_top']), floatval($s['m_right']));
    $pdf->SetHeaderMargin(0); $pdf->SetFooterMargin(12);
    $pdf->SetAutoPageBreak(true, floatval($s['m_bottom']));
    $pdf->setCellHeightRatio(floatval($s['line_height']));
    $pdf->AddPage();
    // شماره/تاریخ/پیوست
    if ($s['show_fields']) {
        $num = $L['number'] ? ($s['fa_digits'] ? lt_fa($L['number']) : $L['number']) : 'پیش‌نویس';
        $rows = [['شماره', "\u{200E}" . $num], ['تاریخ', lt_fa($L['letter_j'] ?: lt_today_j())], ['پیوست', (string)($L['attach_note'] ?: 'ندارد')]];
        $y = floatval($s['f_y']);
        foreach ($rows as [$lab, $val]) {
            $pdf->setRTL(true);
            $pdf->SetFont('vazir', '', floatval($s['f_size']));
            $pdf->SetTextColor(15, 23, 42);
            $txt = $s['field_labels'] ? $lab . ': ' . $val : $val;
            $w = 60;
            $pdf->SetXY(210 - (floatval($s['f_x']) + $w), $y);   // در حالتِ RTL، X از راست است
            $pdf->Cell($w, 5, $txt, 0, 0, 'R');
            $pdf->setRTL(false);
            $y += floatval($s['f_gap']);
        }
    }
    // مهرِ فوریت/محرمانگی (گوشه‌ی بالا-راست)
    if ($s['stamp_priority']) {
        $stamps = [];
        if ($L['priority'] !== 'normal') $stamps[] = [LT_PRIORITY[$L['priority']] ?? '', [220, 38, 38]];
        if ($L['conf'] !== 'normal') $stamps[] = [LT_CONF[$L['conf']] ?? '', [124, 58, 237]];
        $x = 210 - floatval($s['m_right']) - 26; $y = max(8, floatval($s['m_top']) - 14);
        foreach ($stamps as [$t, $c]) {
            $pdf->SetDrawColor(...$c); $pdf->SetTextColor(...$c); $pdf->SetLineWidth(0.6);
            $pdf->RoundedRect($x, $y, 26, 8, 2, '1111', 'D');
            $pdf->SetFont('vazir', 'B', 10); $pdf->setRTL(true); $pdf->SetXY(210 - ($x + 26), $y + 0.6); $pdf->Cell(26, 7, $t, 0, 0, 'C'); $pdf->setRTL(false);
            $x -= 29;
        }
    }
    $pdf->SetTextColor(15, 23, 42);
    $pdf->setRTL(true);
    $pdf->SetFont('vazir', '', floatval($s['font_size']));
    $pdf->SetXY(floatval($s['m_right']), floatval($s['m_top']));
    $html = lt_compose_html($pdo, $L, true);
    // جدول‌های متن در PDF کادر و عرضِ کامل داشته باشند (جدولِ امضا نه)
    $html = preg_replace('/<table(?![^>]*data-lt-sign)([^>]*)>/i', '<table border="1" cellpadding="4" cellspacing="0" width="100%"$1>', $html);
    // TCPDF: پاراگراف‌ها بدونِ فاصله‌ی اضافه و جهتِ راست‌به‌چپ
    $pdf->setHtmlVSpace(['p' => [['h' => 0, 'n' => 0], ['h' => 1.6, 'n' => 1]], 'ul' => [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]], 'ol' => [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]]]);
    $pdf->writeHTML('<div style="text-align:justify">' . $html . '</div>', true, false, true, false, 'R');
    $pdf->setRTL(false);
    return $pdf->Output(lt_safe_name(($L['number'] ?: 'پیش‌نویس') . ' ' . $L['subject']) . '.pdf', $dest);
}

// ---------------------------------------------------------------------
//  Word (DOCX): همان محتوا با سربرگ پشتِ متن و شماره/تاریخ/پیوست در سرصفحه؛ متنِ اصلی بینِ نشانکِ LT_BODY
//  تا بعد از ویرایش در Word و بارگذاری، دوباره به سامانه برگردد
// ---------------------------------------------------------------------
function lt_x($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
class LtDocx {
    public $font; public $size; public $media = []; public $rels = []; private $rid = 10; private $pid = 1;
    public function __construct($font, $sizePt) { $this->font = $font; $this->size = intval(round($sizePt * 2)); }
    public function rPr(array $f) {
        $o = '<w:rPr><w:rFonts w:ascii="' . lt_x($this->font) . '" w:hAnsi="' . lt_x($this->font) . '" w:cs="' . lt_x($this->font) . '"/>';
        if (!empty($f['b'])) $o .= '<w:b/><w:bCs/>';
        if (!empty($f['i'])) $o .= '<w:i/><w:iCs/>';
        if (!empty($f['s'])) $o .= '<w:strike/>';
        if (!empty($f['color'])) $o .= '<w:color w:val="' . ltrim($f['color'], '#') . '"/>';
        $sz = !empty($f['size']) ? intval(round($f['size'] * 2)) : $this->size;
        $o .= '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>';
        if (!empty($f['u'])) $o .= '<w:u w:val="single"/>';
        if (!empty($f['bg'])) $o .= '<w:shd w:val="clear" w:color="auto" w:fill="' . ltrim($f['bg'], '#') . '"/>';
        if (!empty($f['va'])) $o .= '<w:vertAlign w:val="' . $f['va'] . '"/>';
        return $o . '<w:rtl/></w:rPr>';
    }
    public function run($text, array $f) {
        $parts = explode("\n", $text);
        $o = '';
        foreach ($parts as $i => $p) {
            if ($i) $o .= '<w:r>' . $this->rPr($f) . '<w:br/></w:r>';
            if ($p !== '') $o .= '<w:r>' . $this->rPr($f) . '<w:t xml:space="preserve">' . lt_x($p) . '</w:t></w:r>';
        }
        return $o;
    }
    // در پاراگرافِ راست‌به‌چپ، Word «left/right» را ابتدا/انتهای سطر حساب می‌کند
    public function pPr($align, $extra = '') {
        $jc = ['center' => 'center', 'justify' => 'both', 'left' => 'right', 'right' => 'left'][$align] ?? 'both';
        return '<w:pPr><w:bidi/><w:spacing w:after="80" w:line="' . intval(240 * 1.15) . '" w:lineRule="auto"/><w:jc w:val="' . $jc . '"/>' . $extra . '<w:rPr><w:rtl/></w:rPr></w:pPr>';
    }
    public function image($abs, $wMm = null, $hMm = null, $anchorBehind = false) {
        $info = @getimagesize($abs);
        if (!$info) return '';
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_GIF ? 'gif' : 'jpeg');
        $name = 'image' . (count($this->media) + 1) . '.' . $ext;
        $this->media[$name] = $abs;
        $rid = 'rId' . (++$this->rid);
        $this->rels[$rid] = 'media/' . $name;
        if ($hMm && !$wMm) $wMm = $hMm * $info[0] / max(1, $info[1]);
        if ($wMm && !$hMm) $hMm = $wMm * $info[1] / max(1, $info[0]);
        $cx = intval($wMm * 36000); $cy = intval($hMm * 36000); $id = ++$this->pid;
        $graphic = '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="' . $name . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic>';
        if ($anchorBehind) {
            return '<w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="0" behindDoc="1" locked="1" layoutInCell="1" allowOverlap="1">'
                . '<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionH><wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV>'
                . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/><wp:docPr id="' . $id . '" name="سربرگ"/><wp:cNvGraphicFramePr/>' . $graphic . '</wp:anchor></w:drawing></w:r>';
        }
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="' . $name . '"/><wp:cNvGraphicFramePr/>' . $graphic . '</wp:inline></w:drawing></w:r>';
    }
    // ---- تبدیلِ HTMLِ پاک‌شده به OOXML ----
    public function fromHtml($html) {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="ltroot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $root = (new DOMXPath($doc))->query('//div[@id="ltroot"]')->item(0);
        return $root ? $this->blocks($root, 'justify') : '';
    }
    private function styleOf(DOMElement $e) {
        $o = [];
        foreach (explode(';', (string)$e->getAttribute('style')) as $d) if (strpos($d, ':') !== false) { [$k, $v] = array_map('trim', explode(':', $d, 2)); $o[strtolower($k)] = $v; }
        return $o;
    }
    private function inlineFmt(DOMElement $e, array $f) {
        $t = strtolower($e->nodeName);
        if (in_array($t, ['b', 'strong', 'th', 'h2', 'h3', 'h4'], true)) $f['b'] = 1;
        if (in_array($t, ['i', 'em'], true)) $f['i'] = 1;
        if ($t === 'u') $f['u'] = 1;
        if (in_array($t, ['s', 'strike'], true)) $f['s'] = 1;
        if ($t === 'sub') $f['va'] = 'subscript';
        if ($t === 'sup') $f['va'] = 'superscript';
        if ($t === 'h2') $f['size'] = 16; if ($t === 'h3') $f['size'] = 14; if ($t === 'h4') $f['size'] = 13;
        $st = $this->styleOf($e);
        if (!empty($st['font-weight'])) $f['b'] = 1;
        if (!empty($st['font-style'])) $f['i'] = 1;
        if (($st['text-decoration'] ?? '') === 'underline') $f['u'] = 1;
        if (($st['text-decoration'] ?? '') === 'line-through') $f['s'] = 1;
        if (!empty($st['color']) && preg_match('/^#[0-9a-f]{6}$/i', $st['color'])) $f['color'] = $st['color'];
        if (!empty($st['background-color']) && preg_match('/^#[0-9a-f]{6}$/i', $st['background-color'])) $f['bg'] = $st['background-color'];
        if (!empty($st['font-size']) && preg_match('/^([\d.]+)pt$/', $st['font-size'], $m)) $f['size'] = floatval($m[1]);
        return $f;
    }
    private function inlines(DOMNode $n, array $f) {
        $o = '';
        foreach ($n->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) { $txt = preg_replace('/[ \t\r\n]+/u', ' ', $c->nodeValue); if ($txt !== '') $o .= $this->run($txt, $f); continue; }
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            $t = strtolower($c->nodeName);
            if ($t === 'br') { $o .= '<w:r><w:br/></w:r>'; continue; }
            if ($t === 'img') { $p = $c->getAttribute('data-lt-path'); if ($p && is_file($p)) $o .= $this->image($p, null, 18); continue; }
            $o .= $this->inlines($c, $this->inlineFmt($c, $f));
        }
        return $o;
    }
    // سطرِ «تراز از دو طرف» که با شکستنِ دستیِ خط تمام شود در Word کشیده می‌شود؛ چنین پاراگرافی راست‌چین می‌شود
    private function para($inner, $align, $extra = '') { if ($align === 'justify' && strpos($inner, '<w:br/>') !== false) $align = 'right'; return '<w:p>' . $this->pPr($align, $extra) . $inner . '</w:p>'; }
    private function isBlock($t) { return in_array($t, ['p', 'div', 'ul', 'ol', 'li', 'table', 'h2', 'h3', 'h4', 'hr', 'blockquote'], true); }
    public function blocks(DOMNode $n, $align, array $f = [], $prefix = '') {
        $o = ''; $buf = '';
        $flush = function () use (&$buf, &$o, $align) { if (trim(strip_tags($buf)) !== '' || strpos($buf, '<w:br/>') !== false || strpos($buf, '<w:drawing>') !== false) $o .= $this->para($buf, $align); $buf = ''; };
        foreach ($n->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) { $txt = preg_replace('/[ \t\r\n]+/u', ' ', $c->nodeValue); if (trim($txt) !== '') $buf .= $this->run($txt, $f); continue; }
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            $t = strtolower($c->nodeName);
            if (!$this->isBlock($t)) {
                if ($t === 'br') $buf .= '<w:r><w:br/></w:r>';
                elseif ($t === 'img') { $p = $c->getAttribute('data-lt-path'); if ($p && is_file($p)) $buf .= $this->image($p, null, 18); }
                else $buf .= $this->inlines($c, $this->inlineFmt($c, $f));
                continue;
            }
            $flush();
            $st = $this->styleOf($c);
            $al = $st['text-align'] ?? $align;
            if ($t === 'hr') { $o .= '<w:p><w:pPr><w:bidi/><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="94A3B8"/></w:pBdr></w:pPr></w:p>'; continue; }
            if ($t === 'table') { $o .= $this->table($c, $f); continue; }
            if ($t === 'ul' || $t === 'ol') {
                $i = 0;
                foreach ($c->childNodes as $li) {
                    if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') continue;
                    $mark = $t === 'ol' ? lt_fa(++$i) . '. ' : '• ';
                    $o .= $this->para($this->run($mark, $f) . $this->inlines($li, $f), $al, '<w:ind w:right="360"/>');
                }
                continue;
            }
            $ff = in_array($t, ['h2', 'h3', 'h4'], true) ? $this->inlineFmt($c, $f) : $f;
            $hasBlock = false;
            foreach ($c->childNodes as $cc) if ($cc->nodeType === XML_ELEMENT_NODE && $this->isBlock(strtolower($cc->nodeName))) { $hasBlock = true; break; }
            if ($hasBlock) $o .= $this->blocks($c, $al, $ff);
            else { $in = $this->inlines($c, $ff); $o .= $this->para($in !== '' ? $in : '', $al); }
        }
        $flush();
        return $o;
    }
    private function table(DOMElement $tbl, array $f) {
        $rows = [];
        foreach ((new DOMXPath($tbl->ownerDocument))->query('./tr|./thead/tr|./tbody/tr', $tbl) as $tr) $rows[] = $tr;
        if (!$rows) return '';
        $isSign = $tbl->getAttribute('data-lt-sign') === '1';
        $cols = 0;
        foreach ($rows as $tr) { $n = 0; foreach ($tr->childNodes as $td) if ($td->nodeType === XML_ELEMENT_NODE) $n += max(1, intval($td->getAttribute('colspan'))); $cols = max($cols, $n); }
        $border = $isSign ? 'none' : 'single';
        $o = '<w:tbl><w:tblPr><w:bidiVisual/><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $b) $o .= '<w:' . $b . ' w:val="' . $border . '" w:sz="4" w:space="0" w:color="94A3B8"/>';
        $o .= '</w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        for ($i = 0; $i < $cols; $i++) $o .= '<w:gridCol w:w="' . intval(9000 / max(1, $cols)) . '"/>';
        $o .= '</w:tblGrid>';
        foreach ($rows as $tr) {
            $o .= '<w:tr>';
            foreach ($tr->childNodes as $td) {
                if ($td->nodeType !== XML_ELEMENT_NODE) continue;
                $span = max(1, intval($td->getAttribute('colspan')));
                $st = $this->styleOf($td);
                $w = isset($st['width']) && preg_match('/^([\d.]+)%$/', $st['width'], $m) ? intval(floatval($m[1]) * 50) : intval(5000 * $span / max(1, $cols));
                if ($td->getAttribute('width') && preg_match('/^(\d+)%$/', $td->getAttribute('width'), $m)) $w = intval($m[1]) * 50;
                $o .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="pct"/>' . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '') . '</w:tcPr>';
                $al = $st['text-align'] ?? ($isSign ? 'center' : 'right');
                $ff = strtolower($td->nodeName) === 'th' ? $f + ['b' => 1] : $f;
                $inner = $this->blocks($td, $al, $ff);
                $o .= ($inner !== '' ? $inner : '<w:p>' . $this->pPr($al) . '</w:p>') . '</w:tc>';
            }
            $o .= '</w:tr>';
        }
        return $o . '</w:tbl>';
    }
}
function lt_docx($pdo, array $L) {
    $s = lt_settings($pdo);
    $D = new LtDocx($s['word_font'] ?: 'B Nazanin', floatval($s['font_size']));
    $tw = function ($mm) { return intval(round($mm * 56.6929)); };
    // سرِ نامه (بیرون از نشانک) + متن (داخلِ نشانک) + امضا و رونوشت
    $head = $s['auto_head'] ? $D->fromHtml(lt_head_html($L)) : '';
    $vars = array_map(function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }, lt_vars($L, $s));
    $body = $D->fromHtml(strtr((string)$L['body_html'], $vars));
    if ($body === '') $body = '<w:p>' . $D->pPr('justify') . '</w:p>';
    // سرصفحه: سربرگ پشتِ متن + جدولِ شناورِ شماره/تاریخ/پیوست + مهرِ فوریت
    $bg = (!empty($L['letterhead']) && $s['letterhead']) ? lt_abs($s['letterhead']) : null;
    $hdrBg = $bg ? '<w:p><w:pPr><w:bidi/><w:spacing w:after="0"/></w:pPr>' . $D->image($bg, 210, 297, true) . '</w:p>' : '';
    $fields = '';
    if ($s['show_fields']) {
        $num = $L['number'] ? ($s['fa_digits'] ? lt_fa($L['number']) : $L['number']) : 'پیش‌نویس';
        $fs = floatval($s['f_size']);
        $fields .= '<w:tbl><w:tblPr><w:tblpPr w:leftFromText="0" w:rightFromText="0" w:vertAnchor="page" w:horzAnchor="page" w:tblpX="' . $tw(floatval($s['f_x'])) . '" w:tblpY="' . $tw(floatval($s['f_y'])) . '"/>'
                 . '<w:bidiVisual/><w:tblW w:w="' . $tw(55) . '" w:type="dxa"/><w:tblBorders><w:top w:val="none"/><w:left w:val="none"/><w:bottom w:val="none"/><w:right w:val="none"/><w:insideH w:val="none"/><w:insideV w:val="none"/></w:tblBorders>'
                 . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . ($s['field_labels'] ? '<w:gridCol w:w="' . $tw(14) . '"/>' : '') . '<w:gridCol w:w="' . $tw($s['field_labels'] ? 41 : 55) . '"/></w:tblGrid>';
        foreach ([['شماره:', $num], ['تاریخ:', lt_fa($L['letter_j'] ?: lt_today_j())], ['پیوست:', (string)($L['attach_note'] ?: 'ندارد')]] as [$lab, $val]) {
            $fields .= '<w:tr><w:trPr><w:trHeight w:val="' . $tw(floatval($s['f_gap'])) . '" w:hRule="exact"/></w:trPr>';
            if ($s['field_labels']) $fields .= '<w:tc><w:tcPr><w:tcW w:w="' . $tw(14) . '" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:bidi/><w:spacing w:after="0"/></w:pPr>' . $D->run($lab, ['size' => $fs]) . '</w:p></w:tc>';
            $fields .= '<w:tc><w:tcPr><w:tcW w:w="' . $tw($s['field_labels'] ? 41 : 55) . '" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:bidi/><w:spacing w:after="0"/></w:pPr>' . $D->run($val, ['size' => $fs, 'b' => 1]) . '</w:p></w:tc></w:tr>';
        }
        $fields .= '</w:tbl><w:p><w:pPr><w:spacing w:after="0"/><w:rPr><w:sz w:val="2"/></w:rPr></w:pPr></w:p>';
    }
    $stamp = '';
    if ($s['stamp_priority'] && ($L['priority'] !== 'normal' || $L['conf'] !== 'normal')) {
        $t = trim(($L['priority'] !== 'normal' ? LT_PRIORITY[$L['priority']] : '') . '   ' . ($L['conf'] !== 'normal' ? LT_CONF[$L['conf']] : ''));
        $stamp = '<w:p><w:pPr><w:bidi/><w:spacing w:after="0"/></w:pPr>' . $D->run($t, ['b' => 1, 'color' => '#dc2626', 'size' => 11]) . '</w:p>';
    }
    $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . lt_docx_ns() . '>' . $hdrBg . $fields . $stamp . '</w:hdr>';
    $ftrTxt = trim((string)$s['footer_text']);
    $ftr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . lt_docx_ns() . '><w:p>' . $D->pPr('center') . ($ftrTxt !== '' ? $D->run($ftrTxt, ['size' => 8, 'color' => '#64748b']) : '')
         . ($s['page_numbers'] ? $D->run(($ftrTxt !== '' ? '   ' : '') . 'صفحه‌ی ', ['size' => 8, 'color' => '#64748b']) . '<w:r>' . $D->rPr(['size' => 8]) . '<w:fldChar w:fldCharType="begin"/></w:r><w:r>' . $D->rPr(['size' => 8]) . '<w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r>' . $D->rPr(['size' => 8]) . '<w:fldChar w:fldCharType="end"/></w:r>' : '')
         . '</w:p></w:ftr>';
    $hdrRels = $D->rels;   // تصویرِ سربرگ مالِ سرصفحه است؛ تصاویرِ متن (امضا) جدا برای document.xml ثبت می‌شوند
    $D->rels = [];
    $tail2 = $D->fromHtml(lt_sign_html($pdo, $L, false) . lt_cc_html($L));
    $bodyXml = $head . '<w:bookmarkStart w:id="0" w:name="LT_BODY"/>' . $body . '<w:bookmarkEnd w:id="0"/>' . $tail2;
    $sect = '<w:sectPr><w:headerReference w:type="default" r:id="rIdH1"/><w:footerReference w:type="default" r:id="rIdF1"/><w:pgSz w:w="11906" w:h="16838"/>'
          . '<w:pgMar w:top="' . $tw(floatval($s['m_top'])) . '" w:right="' . $tw(floatval($s['m_right'])) . '" w:bottom="' . $tw(floatval($s['m_bottom'])) . '" w:left="' . $tw(floatval($s['m_left'])) . '" w:header="0" w:footer="' . $tw(8) . '" w:gutter="0"/>'
          . '<w:bidi/></w:sectPr>';
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . lt_docx_ns() . '><w:body>' . $bodyXml . $sect . '</w:body></w:document>';
    $rel = function (array $rels, array $extra = []) {
        $o = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($extra as $e) $o .= $e;
        foreach ($rels as $id => $t) $o .= '<Relationship Id="' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="' . $t . '"/>';
        return $o . '</Relationships>';
    };
    $docRels = $rel($D->rels, ['<Relationship Id="rIdS1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
                               '<Relationship Id="rIdSet" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>',
                               '<Relationship Id="rIdH1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>',
                               '<Relationship Id="rIdF1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>']);
    $f = lt_x($D->font); $sz = intval(round(floatval($s['font_size']) * 2));
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="' . $f . '" w:hAnsi="' . $f . '" w:cs="' . $f . '" w:eastAsia="' . $f . '"/><w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/><w:lang w:val="fa-IR" w:bidi="fa-IR"/><w:rtl/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:bidi/><w:spacing w:after="80" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:bidi/></w:pPr><w:rPr><w:rtl/></w:rPr></w:style></w:styles>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:defaultTabStop w:val="720"/><w:themeFontLang w:val="en-US" w:bidi="fa-IR"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>';
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/><Default Extension="gif" ContentType="image/gif"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
        . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>';
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
          . '<dc:title>' . lt_x($L['subject']) . '</dc:title><dc:creator>بیمه با ما</dc:creator><cp:keywords>LT:' . intval($L['id']) . '</cp:keywords></cp:coreProperties>';
    $tmp = tempnam(sys_get_temp_dir(), 'ltd');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', $ct);
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
    $z->addFromString('docProps/core.xml', $core);
    $z->addFromString('word/document.xml', $document);
    $z->addFromString('word/styles.xml', $styles);
    $z->addFromString('word/settings.xml', $settings);
    $z->addFromString('word/header1.xml', $hdr);
    $z->addFromString('word/footer1.xml', $ftr);
    $z->addFromString('word/_rels/document.xml.rels', $docRels);
    $z->addFromString('word/_rels/header1.xml.rels', $rel($hdrRels));
    foreach ($D->media as $name => $abs) $z->addFile($abs, 'word/media/' . $name);
    $z->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
function lt_docx_ns() {
    return 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
         . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
         . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
}
// متنِ نامه از فایلِ Word (فقط بخشِ داخلِ نشانکِ LT_BODY، اگر بود) => HTML ساده
function lt_docx_to_html($path) {
    $z = new ZipArchive();
    if ($z->open($path) !== true) return null;
    $xml = $z->getFromName('word/document.xml');
    $z->close();
    if (!$xml) return null;
    $doc = new DOMDocument();
    if (!@$doc->loadXML($xml, LIBXML_NONET)) return null;
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $body = $xp->query('//w:body')->item(0);
    if (!$body) return null;
    $inside = $xp->query('//w:bookmarkStart[@w:name="LT_BODY"]')->length > 0 ? false : null;   // null = کلِ سند
    $out = '';
    $baseSz = intval(round(floatval($GLOBALS['LT_BASE_PT'] ?? 12) * 2));
    $paraHtml = function (DOMElement $p) use ($xp, $baseSz) {
        $h = '';
        foreach ($xp->query('.//w:r', $p) as $r) {
            $t = '';
            foreach ($r->childNodes as $c) {
                if ($c->localName === 't') $t .= htmlspecialchars($c->textContent, ENT_QUOTES, 'UTF-8');
                elseif ($c->localName === 'br') $t .= '<br>';
                elseif ($c->localName === 'tab') $t .= ' ';
            }
            if ($t === '') continue;
            $b = $xp->query('./w:rPr/w:b|./w:rPr/w:bCs', $r)->length > 0;
            $u = $xp->query('./w:rPr/w:u', $r)->length > 0;
            $i = $xp->query('./w:rPr/w:i|./w:rPr/w:iCs', $r)->length > 0;
            if ($u) $t = '<u>' . $t . '</u>'; if ($i) $t = '<i>' . $t . '</i>'; if ($b) $t = '<b>' . $t . '</b>';
            $st = [];
            $col = $xp->query('./w:rPr/w:color/@w:val', $r)->item(0);
            if ($col && preg_match('/^[0-9a-f]{6}$/i', $col->nodeValue) && strtolower($col->nodeValue) !== '000000') $st[] = 'color:#' . strtolower($col->nodeValue);
            $sz = $xp->query('./w:rPr/w:szCs/@w:val|./w:rPr/w:sz/@w:val', $r)->item(0);
            if ($sz && intval($sz->nodeValue) && abs(intval($sz->nodeValue) - $baseSz) > 1) $st[] = 'font-size:' . (intval($sz->nodeValue) / 2) . 'pt';
            if ($st) $t = '<span style="' . implode(';', $st) . '">' . $t . '</span>';
            $h .= $t;
        }
        $jc = $xp->query('./w:pPr/w:jc/@w:val', $p)->item(0);
        $al = $jc ? ['center' => 'center', 'both' => 'justify', 'right' => 'left', 'end' => 'left'][$jc->nodeValue] ?? '' : '';
        return '<p' . ($al ? ' style="text-align:' . $al . '"' : '') . '>' . ($h !== '' ? $h : '<br>') . '</p>';
    };
    $walk = function (DOMNode $n) use (&$walk, &$inside, &$out, $xp, $paraHtml) {
        foreach ($n->childNodes as $c) {
            if (!($c instanceof DOMElement)) continue;
            if ($c->localName === 'bookmarkStart' && $c->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'name') === 'LT_BODY') { $inside = true; continue; }
            if ($c->localName === 'bookmarkEnd' && $inside === true && $c->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'id') === '0') { $inside = false; continue; }
            if ($c->localName === 'p') {
                // نشانک ممکن است داخلِ پاراگراف باشد
                foreach ($xp->query('./w:bookmarkStart[@w:name="LT_BODY"]', $c) as $_) $inside = true;
                if ($inside !== false) $out .= $paraHtml($c);
                foreach ($xp->query('./w:bookmarkEnd[@w:id="0"]', $c) as $_) if ($inside === true) $inside = false;
            } elseif ($c->localName === 'tbl') {
                if ($inside === false) continue;
                $t = '<table border="1" cellpadding="4" width="100%">';
                foreach ($xp->query('./w:tr', $c) as $tr) {
                    $t .= '<tr>';
                    foreach ($xp->query('./w:tc', $tr) as $tc) { $cell = ''; foreach ($xp->query('./w:p', $tc) as $p) $cell .= $paraHtml($p); $t .= '<td>' . $cell . '</td>'; }
                    $t .= '</tr>';
                }
                $out .= $t . '</table>';
            } elseif ($c->localName !== 'sectPr') $walk($c);
        }
    };
    $walk($body);
    return lt_clean_html($out);
}

// ---------------------------------------------------------------------
//  خروجیِ یک نامه برای فهرست/جزئیات
// ---------------------------------------------------------------------
function lt_out(array $r, $pdo = null) {
    return [
        'id' => intval($r['id']), 'direction' => $r['direction'], 'direction_fa' => LT_DIR[$r['direction']] ?? '', 'number' => $r['number'], 'subject' => $r['subject'],
        'category_id' => $r['category_id'] ? intval($r['category_id']) : null, 'company_id' => $r['company_id'] ? intval($r['company_id']) : null,
        'company_name' => $r['company_name'] ?? null, 'party_name' => $r['party_name'], 'party_person' => $r['party_person'], 'party_title' => $r['party_title'], 'party' => lt_party_line($r),
        'cc' => $r['cc'], 'letter_j' => $r['letter_j'], 'ext_number' => $r['ext_number'], 'ext_j' => $r['ext_j'], 'priority' => $r['priority'], 'conf' => $r['conf'],
        'letterhead' => intval($r['letterhead']), 'attach_note' => $r['attach_note'], 'signer_id' => $r['signer_id'] ? intval($r['signer_id']) : null, 'status' => $r['status'],
        'status_fa' => LT_STATUS[$r['status']] ?? $r['status'], 'parent_id' => $r['parent_id'] ? intval($r['parent_id']) : null, 'needs_reply' => intval($r['needs_reply']),
        'reply_due_j' => $r['reply_due_j'], 'overdue' => intval($r['needs_reply']) && $r['reply_due_g'] && $r['reply_due_g'] < date('Y-m-d') && !in_array($r['status'], ['REPLIED', 'ARCHIVED'], true),
        'to_portal' => intval($r['to_portal']), 'has_word' => !empty($r['word_path']), 'has_signed' => !empty($r['signed_path']), 'tags' => $r['tags'],
        'created_by' => $r['created_by'] ? intval($r['created_by']) : null, 'created_by_name' => $r['created_by_name'] ?? ($pdo && $r['created_by'] ? lt_user_name($pdo, $r['created_by']) : ''),
        'portal_user' => $r['portal_user_name'] ?? null, 'created_at_j' => lt_dt_j($r['created_at']), 'issued_at_j' => lt_dt_j($r['issued_at']), 'issued_by_name' => $pdo && $r['issued_by'] ? lt_user_name($pdo, $r['issued_by']) : '',
        'sent_at_j' => lt_dt_j($r['sent_at']), 'seen_at_j' => lt_dt_j($r['seen_at']), 'updated_at_j' => lt_dt_j($r['updated_at']),
        'files_n' => isset($r['files_n']) ? intval($r['files_n']) : null, 'replies_n' => isset($r['replies_n']) ? intval($r['replies_n']) : null,
    ];
}

// ---------------------------------------------------------------------
//  رویدادها برای زنگوله‌ی اعلان‌ها
// ---------------------------------------------------------------------
function lt_staff_events($pdo, $since, $until, $userId) {
    $ev = [];
    try {
        $pdo->query("SELECT 1 FROM lt_letters LIMIT 0");
    } catch (Throwable $e) { return $ev; }
    require_once __DIR__ . '/_perm.php';
    $role = perm_real_role();
    $p = $role === 'ADMIN' ? 'ALL' : (perm_user_custom($pdo, $userId) ?? perm_role_defaults($role));
    $can = $p === 'ALL' || perm_allows($p, 'lt-box:view');
    try {
        if ($can) {
            $st = $pdo->prepare("SELECT l.id, l.number, l.subject, l.created_at, c.name cname FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id
                                  WHERE l.portal_user_id IS NOT NULL AND l.is_deleted = 0 AND l.created_at > ? AND l.created_at <= ? ORDER BY l.created_at DESC LIMIT 20");
            $st->execute([$since, $until]);
            foreach ($st->fetchAll() as $r)
                $ev[] = ['type' => 'letter', 'title' => 'نامه‌ی وارده از ' . ($r['cname'] ?: 'شرکت'), 'body' => mb_substr($r['subject'], 0, 90) . ($r['number'] ? ' (' . lt_fa($r['number']) . ')' : ''), 'at' => $r['created_at'], 'tab' => 'lt-box'];
        }
        $st = $pdo->prepare("SELECT r.created_at, r.note, l.subject, l.number, u.full_name FROM lt_refs r JOIN lt_letters l ON l.id = r.letter_id LEFT JOIN users u ON u.id = r.from_user
                              WHERE r.to_user = ? AND r.created_at > ? AND r.created_at <= ? AND l.is_deleted = 0 ORDER BY r.created_at DESC LIMIT 20");
        $st->execute([$userId, $since, $until]);
        foreach ($st->fetchAll() as $r)
            $ev[] = ['type' => 'letter_ref', 'title' => 'ارجاعِ نامه از ' . ($r['full_name'] ?: 'همکار'), 'body' => mb_substr($r['subject'], 0, 80) . ($r['note'] ? ' - ' . mb_substr($r['note'], 0, 60) : ''), 'at' => $r['created_at'], 'tab' => 'lt-box'];
    } catch (Throwable $e) { error_log('[lt_staff_events] ' . $e->getMessage()); }
    return $ev;
}
function lt_portal_events($pdo, $since, $until, array $companyIds) {
    $ev = [];
    if (!$companyIds) return $ev;
    try {
        $ph = implode(',', array_fill(0, count($companyIds), '?'));
        $st = $pdo->prepare("SELECT number, subject, sent_at, priority FROM lt_letters WHERE to_portal = 1 AND is_deleted = 0 AND sent_at > ? AND sent_at <= ? AND company_id IN ($ph) ORDER BY sent_at DESC LIMIT 20");
        $st->execute(array_merge([$since, $until], $companyIds));
        foreach ($st->fetchAll() as $r)
            $ev[] = ['type' => 'letter', 'title' => ($r['priority'] !== 'normal' ? '🔴 ' . LT_PRIORITY[$r['priority']] . ' - ' : '') . 'نامه‌ی جدید از بیمه با ما', 'body' => mb_substr($r['subject'], 0, 90) . ' (' . lt_fa($r['number']) . ')', 'at' => $r['sent_at'], 'tab' => 'letters'];
    } catch (Throwable $e) { /* جدول هنوز ساخته نشده */ }
    return $ev;
}
