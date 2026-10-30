<?php
// فایل: api/_md_lists.php
// «درمان و عمرِ گروهی» — بیمه‌شدگان و لیست‌ها:
//   • md_members: هر نفر (اصلی یا خانواده) در یک شرکت برای درمان یا عمر، با ماهِ شروع و ماهِ پایان (start_p / end_p به شکلِ YYYY/MM؛ end_p = اولین ماهی که دیگر حساب نمی‌شود)
//   • md_lists: لیستِ شروعِ قرارداد / ماهانه / افزایشی / کاهشی هر شرکت؛ پیش‌نمایشِ تفاوت‌ها، اعمال، و ابطال (برگرداندنِ همه‌ی تغییرات با md_list_ops)
//   • انتقال: کسی که در شرکتِ دیگری فعال است و در لیستِ این شرکت می‌آید => پایان در شرکتِ قبلی و شروع در این شرکت + ثبت در پرونده‌ی شخص (per_transfers)
//   • خروجیِ «ورودی به پاسارگاد» (xls یا xlsx) با قالب‌های بیمه‌گر و وضعیتِ جدا برای افزایشی/کاهشی
// ورود و خروج از ابتدای ماه حساب می‌شود؛ تاریخ‌ها از خودِ اکسل خوانده می‌شوند.

require_once __DIR__ . '/_md.php';

const MD_LIST_TYPES = ['START' => 'لیستِ شروعِ قرارداد', 'MONTHLY' => 'لیستِ ماهانه (کامل)', 'ADD' => 'لیستِ افزایشی', 'REMOVE' => 'لیستِ کاهشی'];
const MD_REL_CODES = [1 => 'اصلی', 2 => 'همسر', 3 => 'فرزند', 4 => 'پدر', 5 => 'مادر'];
const MD_PAS_STATUS = ['NONE' => 'واردِ پاسارگاد نشده', 'EXPORTED' => 'ورود شده به پاسارگاد', 'DONE' => 'تأییدِ بیمه‌گر'];
const MD_MONTHS = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

function md_ops_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    md_ensure($pdo);
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS md_members (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, kind VARCHAR(8) NOT NULL DEFAULT 'HEALTH',
            nid VARCHAR(10) NOT NULL, main_nid VARCHAR(10) NOT NULL, rel TINYINT NOT NULL DEFAULT 1, first_name VARCHAR(120) NULL, last_name VARCHAR(150) NULL,
            father VARCHAR(120) NULL, gender TINYINT NULL, birth_j CHAR(10) NULL, id_no VARCHAR(20) NULL, mobile VARCHAR(20) NULL, sheba VARCHAR(34) NULL, bank VARCHAR(80) NULL,
            personnel_no VARCHAR(40) NULL, plan_id INT NULL, life_amount BIGINT NULL, start_j CHAR(10) NULL, start_p CHAR(7) NOT NULL, end_j CHAR(10) NULL, end_p CHAR(7) NULL,
            list_id INT NULL, end_list_id INT NULL, note VARCHAR(400) NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            KEY k_co (company_id, kind, start_p), KEY k_nid (nid), KEY k_main (main_nid), KEY k_list (list_id)) $o",
        "CREATE TABLE IF NOT EXISTS md_lists (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, kind VARCHAR(8) NOT NULL DEFAULT 'HEALTH', type VARCHAR(8) NOT NULL,
            period CHAR(7) NOT NULL, contract_id INT NULL, file_name VARCHAR(250) NULL, file_rel VARCHAR(600) NULL, rows_n INT NOT NULL DEFAULT 0,
            add_n INT NOT NULL DEFAULT 0, rem_n INT NOT NULL DEFAULT 0, chg_n INT NOT NULL DEFAULT 0, trans_n INT NOT NULL DEFAULT 0, warn_n INT NOT NULL DEFAULT 0,
            status VARCHAR(8) NOT NULL DEFAULT 'DRAFT', pas_add VARCHAR(8) NOT NULL DEFAULT 'NONE', pas_rem VARCHAR(8) NOT NULL DEFAULT 'NONE',
            pas_add_at DATETIME NULL, pas_rem_at DATETIME NULL, pas_log TEXT NULL, payload LONGTEXT NULL, note VARCHAR(500) NULL,
            created_by INT NULL, created_name VARCHAR(190) NULL, created_at DATETIME NULL, applied_at DATETIME NULL, voided_at DATETIME NULL, void_reason VARCHAR(400) NULL,
            KEY k_co (company_id, period), KEY k_status (status)) $o",
        // ردِ هر تغییرِ یک لیست (برای ابطالِ دقیق)
        "CREATE TABLE IF NOT EXISTS md_list_ops (id INT AUTO_INCREMENT PRIMARY KEY, list_id INT NOT NULL, member_id INT NOT NULL, op VARCHAR(8) NOT NULL, prev TEXT NULL, KEY k_list (list_id)) $o",
        "CREATE TABLE IF NOT EXISTS md_transfers (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(8) NOT NULL, nid VARCHAR(10) NOT NULL, name VARCHAR(250) NULL,
            from_company_id INT NULL, to_company_id INT NULL, period CHAR(7) NOT NULL, date_j CHAR(10) NULL, list_id INT NULL, created_at DATETIME NULL, KEY k_nid (nid), KEY k_list (list_id)) $o",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[md_ops_ensure] ' . $e->getMessage()); } }
}

// ---------------- تنظیمات ----------------
function md_settings_defaults() {
    return [
        'exit_rounding' => 'same',          // same: ماهِ تاریخِ خروج حساب نمی‌شود | next: ماهِ خروج هم حساب می‌شود
        'child_max_age' => 25, 'create_persons' => 1,
        // ثابت‌های فایلِ «ورودی به پاسارگاد»
        'pas_insurer_first' => 'tamin-ejtemaee', 'pas_gold_card' => '1', 'pas_booklet' => '1', 'pas_takafol' => '0', 'pas_status' => '2', 'pas_warning' => '0',
        'pas_nationality' => 'Iran', 'pas_letter_no' => '', 'pas_letter_j' => '', 'pas_cover_code' => '',
        // صورت‌حساب
        'no_format' => '{yy}/{n}', 'no_pad' => 3, 'due_days' => 10, 'remind_days' => 5,
        'intro_health' => 'با سلام و احترام' . "\n" . 'صورت حسابِ پرداختِ حق بیمه درمان گروهی به شرحِ زیر ارسال می‌گردد:',
        'intro_life' => 'با سلام و احترام' . "\n" . 'صورت حسابِ پرداختِ حق بیمه عمر و حادثه به شرحِ زیر ارسال می‌گردد:',
        'outro' => 'خواهشمند است دستور فرمایید در اسرعِ وقت نسبت به پرداختِ مبلغِ فوق اقدام و فیشِ واریزی را ارسال فرمایید.',
        'sheba' => '', 'account_name' => '', 'email' => '', 'signer' => '', 'letterhead' => '', 'signature' => '', 'm_top' => 52, 'm_side' => 18, 'font_size' => 11,
        'receipt_groups' => [], 'receipt_ack' => 1,
    ];
}
function md_settings($pdo) {
    static $c = null;
    if ($c !== null) return $c;
    $v = null;
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'md_settings'"); $st->execute(); $v = json_decode((string)$st->fetchColumn(), true); } catch (Throwable $e) {}
    return $c = array_merge(md_settings_defaults(), is_array($v) ? $v : []);
}
function md_settings_save($pdo, array $in) {
    $cur = md_settings($pdo); $def = md_settings_defaults();
    foreach ($def as $k => $d) {
        if (!array_key_exists($k, $in) || in_array($k, ['letterhead', 'signature'], true)) continue;
        if ($k === 'receipt_groups') {
            $g = [];
            foreach ((array)$in[$k] as $x) { $id = preg_replace('/[^0-9\-]/', '', md_digits((string)($x['chat_id'] ?? ''))); if ($id !== '' && !isset($g[$id])) $g[$id] = ['chat_id' => $id, 'title' => mb_substr(trim((string)($x['title'] ?? '')), 0, 120)]; }
            $cur[$k] = array_values($g);
        } elseif (is_int($d)) $cur[$k] = (int)md_digits((string)$in[$k]);
        else $cur[$k] = mb_substr(trim((string)$in[$k]), 0, 3000);
    }
    if (!in_array($cur['exit_rounding'], ['same', 'next'], true)) $cur['exit_rounding'] = 'same';
    md_settings_store($pdo, $cur);
}
function md_settings_store($pdo, array $cur) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('md_settings', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([json_encode($cur, JSON_UNESCAPED_UNICODE)]);
}

// ---------------- کمک‌ها ----------------
function md_digits($s) { return strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']); }
function md_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function md_norm($s) { return trim(preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك', 'ة', "\u{200C}", "\u{200F}", "\u{200E}", 'ۀ', 'أ', 'إ'], ['ی', 'ک', 'ه', ' ', '', '', 'ه', 'ا', 'ا'], md_digits((string)$s)))); }
function md_today_j() { if (!function_exists('jalali_from_gregorian_ts')) require_once __DIR__ . '/_case_helpers.php'; [$y, $m, $d] = jalali_from_gregorian_ts(time()); return sprintf('%04d/%02d/%02d', $y, $m, $d); }
function md_period_now() { return substr(md_today_j(), 0, 7); }
function md_period_ok($p) { return is_string($p) && preg_match('~^1[34]\d\d/(0[1-9]|1[0-2])$~', $p); }
function md_period_of($j) { return $j ? substr($j, 0, 7) : null; }
function md_period_add($p, $n) { [$y, $m] = array_map('intval', explode('/', $p)); $m += $n; while ($m > 12) { $m -= 12; $y++; } while ($m < 1) { $m += 12; $y--; } return sprintf('%04d/%02d', $y, $m); }
function md_period_fa($p) { if (!md_period_ok($p)) return (string)$p; [$y, $m] = array_map('intval', explode('/', $p)); return MD_MONTHS[$m] . ' ' . md_fa($y); }
function md_period_range($from, $to, $max = 60) { $o = []; for ($p = $from; $p <= $to && count($o) < $max; $p = md_period_add($p, 1)) $o[] = $p; return $o; }
// تاریخِ شمسیِ اکسل: ۱۴۰۴/۰۸/۰۱، 1404-8-1، 14040801
function md_jdate_loose($v) {
    $s = trim(md_digits((string)$v));
    if (preg_match('~^(1[34]\d\d)(\d\d)(\d\d)$~', $s, $m)) $s = "$m[1]/$m[2]/$m[3]";
    return md_jdate($s)[0];
}
// کدِ ملی: فقط رقم، صفرهای اولِ حذف‌شده در اکسل برمی‌گردد (۸ یا ۹ رقم => ۱۰ رقم)
function md_nid($v) {
    $s = preg_replace('/\D/', '', md_digits((string)$v));
    if ($s === '' || strlen($s) > 10 || strlen($s) < 6) return '';
    return str_pad($s, 10, '0', STR_PAD_LEFT);
}
function md_nid_valid($n) {
    if (!preg_match('/^\d{10}$/', $n) || preg_match('/^(\d)\1{9}$/', $n)) return false;
    $s = 0; for ($i = 0; $i < 9; $i++) $s += (int)$n[$i] * (10 - $i);
    $r = $s % 11; $c = (int)$n[9];
    return ($r < 2 && $c === $r) || ($r >= 2 && $c === 11 - $r);
}
function md_rel_code($v) {
    $s = md_norm($v);
    if (preg_match('/^[1-5]$/', $s)) return (int)$s;
    if (preg_match('/اصل/u', $s)) return 1;
    if (preg_match('/همسر|زوج/u', $s)) return 2;
    if (preg_match('/فرزند|پسر|دختر/u', $s)) return 3;
    if (preg_match('/پدر/u', $s)) return 4;
    if (preg_match('/مادر/u', $s)) return 5;
    return 0;
}
function md_rel_key($code) { return $code == 1 ? 'main' : ($code == 2 ? 'spouse' : ($code == 3 ? 'child' : 'parent')); }
function md_gender($v) { $s = md_norm($v); if (preg_match('/زن|مونث|خانم|^1$/u', $s)) return 1; if (preg_match('/مرد|مذکر|آقا|^0$/u', $s)) return 0; return null; }
function md_sheba($v) { $s = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', md_digits((string)$v))); if (preg_match('/^IR(\d{24})$/', $s, $m)) return $m[1]; return preg_match('/^\d{24}$/', $s) ? $s : ''; }
function md_company_dir($companyName, $period = null) {
    $d = md_archive_root() . '/' . md_safe_name($companyName ?: 'بدون شرکت') . ($period ? '/' . str_replace('/', '-', $period) . ' ' . md_period_fa($period) : '');
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function md_rel_path($abs) { $r = md_archive_root() . '/'; return strpos($abs, $r) === 0 ? substr($abs, strlen($r)) : $abs; }
function md_abs_path($rel) { return (strpos((string)$rel, '..') === false && $rel !== '') ? md_archive_root() . '/' . $rel : null; }
function md_unique($dir, $base, $ext) { $base = md_safe_name($base); $p = "$dir/$base.$ext"; $n = 2; while (is_file($p)) $p = $dir . '/' . $base . ' (' . $n++ . ').' . $ext; return $p; }
function md_company_names($pdo) { $o = []; foreach ($pdo->query("SELECT id, name FROM companies") as $r) $o[(int)$r['id']] = $r['name']; return $o; }
function md_user_name() { return mb_substr((string)($_SESSION['full_name'] ?? ''), 0, 190); }

// شماره‌ی طرحِ هر طرحِ قرارداد: کدِ عددی (اگر هست) وگرنه ترتیبِ طرح
function md_plan_index($pdo) {
    static $c = null;
    if ($c !== null) return $c;
    $c = ['byId' => [], 'byContract' => []];
    $pos = [];
    foreach ($pdo->query("SELECT p.*, c.kind FROM md_plans p JOIN md_contracts c ON c.id = p.contract_id WHERE p.is_deleted = 0 ORDER BY p.contract_id, p.sort, p.id") as $p) {
        $cid = (int)$p['contract_id']; $pos[$cid] = ($pos[$cid] ?? 0) + 1;
        $no = preg_match('/^\d+$/', md_digits(trim((string)$p['code']))) ? (int)md_digits(trim($p['code'])) : $pos[$cid];
        $p['no'] = $no;
        $c['byId'][(int)$p['id']] = $p;
        $c['byContract'][$cid][$no] = (int)$p['id'];
    }
    return $c;
}
function md_plan_reset() { /* بعد از ذخیره‌ی قرارداد */ }

// ---------------- خواندنِ لیستِ اکسل (ستون‌ها از روی عنوان) ----------------
function md_list_cols() {
    return [
        'nid' => '/^(کد\s*ملی|کدملی|شماره\s*ملی)(\s*بیمه\s*شده)?$/u', 'main_nid' => '/کد\s*ملی.*اصلی|کد\s*ملی\s*سرپرست|کد\s*ملی\s*تحت\s*تکفل/u', 'main_ref' => '/^بیمه\s*شده\s*اصلی$/u',
        'first' => '/^نام$/u', 'last' => '/^نام\s*خانوادگی$/u', 'full' => '/^نام\s*و\s*نام\s*خانوادگی$/u', 'father' => '/^نام\s*پدر$/u',
        'gender' => '/^جنسیت/u', 'birth' => '/^تاریخ\s*تولد/u', 'id_no' => '/^شماره\s*شناسنامه/u', 'rel' => '/^(کد\s*)?نسبت/u',
        'plan_code' => '/^کد\s*گروه(\s*بیمه\s*شدگان)?$/u', 'plan' => '/^طرح$|^گروه(\s*\(\s*نوع\s*طرح\s*\))?$|^نوع\s*طرح$|^گروه\s*بیمه\s*شدگان$|^flag$|flag/iu',
        'start' => '/تاریخ\s*شروع\s*پوشش|تاریخ.*(استخدام|انتقال)|^تاریخ\s*اثر|^تاریخ\s*شروع$|^تاریخ\s*ورود/u', 'end' => '/تاریخ\s*پایان\s*پوشش|^تاریخ\s*پایان$|^تاریخ\s*خروج/u',
        'status' => '/^وضعیت$|^وضعیت\s*بیمه\s*شده$/u', 'mobile' => '/تلفن\s*همراه|شماره\s*تماس|شماره\s*تلفن|موبایل|همراه/u',
        'sheba' => '/شبا/u', 'bank' => '/^(نام\s*)?بانک$/u', 'personnel' => '/(کد|شماره)\s*پرسنلی/u', 'company' => '/^نام\s*شرکت|^شرکت$/u', 'reason' => '/^علت/u',
        'life_amount' => '/^سرمایه|مبلغ\s*(بیمه\s*)?عمر|سرمایه\s*فوت/u',
    ];
}
function md_list_detect(array $rows) {
    $cols = md_list_cols(); $best = -1; $hr = 0; $map = [];
    foreach (array_slice($rows, 0, 12, true) as $i => $r) {
        $m = []; $n = 0;
        foreach ($r as $ci => $h) {
            $h = md_norm($h); if ($h === '') continue;
            foreach ($cols as $k => $re) if (!isset($m[$k]) && preg_match($re, $h)) { $m[$k] = $ci; $n++; break; }
        }
        if ($n > $best) { $best = $n; $hr = $i; $map = $m; }
    }
    return ['header_row' => $hr, 'map' => $map, 'headers' => array_map('md_norm', $rows[$hr] ?? []), 'score' => $best];
}
// ردیف‌های اکسل => افراد (با هشدارها). $planMap: شماره‌ی طرح => plan_id برای قراردادِ مؤثرِ شرکت
function md_list_items(array $rows, array $det, array $planMap = []) {
    $M = $det['map']; $hr = (int)$det['header_row'];
    $g = function ($r, $k) use ($M) { return isset($M[$k]) ? trim((string)($r[$M[$k]] ?? '')) : ''; };
    $items = []; $lastMain = '';
    foreach ($rows as $i => $r) {
        if ($i <= $hr) continue;
        $nid = md_nid($g($r, 'nid'));
        $first = md_norm($g($r, 'first')); $last = md_norm($g($r, 'last'));
        if ($first === '' && $last === '' && ($full = md_norm($g($r, 'full'))) !== '') { $sp = explode(' ', $full, 2); $first = $sp[0]; $last = $sp[1] ?? ''; }
        if ($nid === '') continue;   // ردیفِ راهنما/خالی
        $w = [];
        if (!md_nid_valid($nid)) $w[] = 'کدِ ملیِ نامعتبر';
        $rel = md_rel_code($g($r, 'rel'));
        $mainRaw = $g($r, 'main_nid') !== '' ? $g($r, 'main_nid') : $g($r, 'main_ref');
        $main = md_nid($mainRaw);
        if (!$rel) $rel = ($main === '' || $main === $nid) ? 1 : 3;
        if ($rel === 1) { $main = $nid; $lastMain = $nid; }
        elseif ($main === '' || $main === $nid) { if ($lastMain !== '') { $main = $lastMain; $w[] = 'بیمه‌شده‌ی اصلی از ردیفِ بالاتر فرض شد'; } else { $main = $nid; $rel = 1; $w[] = 'بیمه‌شده‌ی اصلی معلوم نبود؛ خودش اصلی فرض شد'; } }
        $planRaw = $g($r, 'plan_code') !== '' ? $g($r, 'plan_code') : $g($r, 'plan');
        $planNo = preg_match('/\d+/', md_digits($planRaw), $pm) ? (int)$pm[0] : 0;
        $planId = $planNo && isset($planMap[$planNo]) ? $planMap[$planNo] : null;
        if ($planRaw !== '' && $planNo && !$planId && $planMap) $w[] = 'طرحِ ' . md_fa($planNo) . ' در قرارداد نیست';
        $st = md_norm($g($r, 'status'));
        $inactive = $st !== '' && preg_match('/مستعفی|خروج|غیر\s*فعال|لغو|ابطال|اخراج|فوت/u', $st);
        $start = md_jdate_loose($g($r, 'start')); $end = md_jdate_loose($g($r, 'end'));
        $life = $g($r, 'life_amount') !== '' ? md_money($g($r, 'life_amount')) : null;
        $items[] = ['row' => $i + 1, 'nid' => $nid, 'main_nid' => $main, 'rel' => $rel, 'first_name' => mb_substr($first, 0, 120), 'last_name' => mb_substr($last, 0, 150),
                    'father' => mb_substr(md_norm($g($r, 'father')), 0, 120), 'gender' => md_gender($g($r, 'gender')), 'birth_j' => md_jdate_loose($g($r, 'birth')),
                    'id_no' => mb_substr(preg_replace('/\D/', '', md_digits($g($r, 'id_no'))), 0, 20), 'mobile' => mb_substr(preg_replace('/\D/', '', md_digits($g($r, 'mobile'))), 0, 20),
                    'sheba' => md_sheba($g($r, 'sheba')), 'bank' => mb_substr(md_norm($g($r, 'bank')), 0, 80), 'personnel_no' => mb_substr(md_norm($g($r, 'personnel')), 0, 40),
                    'plan_no' => $planNo ?: null, 'plan_id' => $planId, 'start_j' => $start, 'end_j' => $end, 'inactive' => (bool)$inactive, 'status' => $st,
                    'company' => md_norm($g($r, 'company')), 'reason' => md_norm($g($r, 'reason')), 'life_amount' => $life ?: null, 'warn' => $w];
    }
    return $items;
}

// ---------------- اعضا ----------------
function md_member_row(array $m, $names = []) {
    return ['id' => (int)$m['id'], 'company_id' => (int)$m['company_id'], 'company' => $names[(int)$m['company_id']] ?? '', 'kind' => $m['kind'], 'nid' => $m['nid'], 'main_nid' => $m['main_nid'],
            'rel' => (int)$m['rel'], 'rel_fa' => MD_REL_CODES[(int)$m['rel']] ?? '', 'first_name' => (string)$m['first_name'], 'last_name' => (string)$m['last_name'],
            'name' => trim($m['first_name'] . ' ' . $m['last_name']), 'father' => (string)$m['father'], 'gender' => $m['gender'] === null ? null : (int)$m['gender'], 'birth_j' => (string)$m['birth_j'],
            'id_no' => (string)$m['id_no'], 'mobile' => (string)$m['mobile'], 'sheba' => (string)$m['sheba'], 'bank' => (string)$m['bank'], 'personnel_no' => (string)$m['personnel_no'],
            'plan_id' => $m['plan_id'] ? (int)$m['plan_id'] : null, 'life_amount' => $m['life_amount'] === null ? null : (int)$m['life_amount'],
            'start_j' => (string)$m['start_j'], 'start_p' => $m['start_p'], 'end_j' => (string)$m['end_j'], 'end_p' => $m['end_p'], 'list_id' => $m['list_id'] ? (int)$m['list_id'] : null, 'note' => (string)$m['note']];
}
// اعضای فعالِ یک شرکت در یک ماه (کلید = کدِ ملی)
function md_active($pdo, $companyId, $kind, $period) {
    $st = $pdo->prepare("SELECT * FROM md_members WHERE company_id = ? AND kind = ? AND start_p <= ? AND (end_p IS NULL OR end_p > ?)");
    $st->execute([(int)$companyId, $kind, $period, $period]);
    $o = []; foreach ($st->fetchAll() as $m) $o[$m['nid']] = $m;
    return $o;
}
// عضویتِ فعالِ یک کدِ ملی در شرکت‌های دیگر
function md_active_elsewhere($pdo, $nid, $kind, $period, $exceptCompany) {
    $st = $pdo->prepare("SELECT * FROM md_members WHERE nid = ? AND kind = ? AND company_id <> ? AND start_p <= ? AND (end_p IS NULL OR end_p > ?) ORDER BY start_p DESC LIMIT 1");
    $st->execute([$nid, $kind, (int)$exceptCompany, $period, $period]);
    return $st->fetch() ?: null;
}
function md_members_list($pdo, array $f) {
    md_ops_ensure($pdo);
    $w = ['1=1']; $a = [];
    if (!empty($f['company_id'])) { $w[] = 'm.company_id = ?'; $a[] = (int)$f['company_id']; }
    if (!empty($f['kind']) && isset(MD_KINDS[$f['kind']])) { $w[] = 'm.kind = ?'; $a[] = $f['kind']; }
    $p = md_period_ok($f['period'] ?? '') ? $f['period'] : md_period_now();
    $state = (string)($f['state'] ?? 'active');
    if ($state === 'active') { $w[] = 'm.start_p <= ? AND (m.end_p IS NULL OR m.end_p > ?)'; array_push($a, $p, $p); }
    elseif ($state === 'ended') { $w[] = 'm.end_p IS NOT NULL AND m.end_p <= ?'; $a[] = $p; }
    elseif ($state === 'future') { $w[] = 'm.start_p > ?'; $a[] = $p; }
    if (!empty($f['plan_id'])) { $w[] = 'm.plan_id = ?'; $a[] = (int)$f['plan_id']; }
    if (!empty($f['rel'])) { $w[] = ((int)$f['rel'] === 1 ? 'm.rel = 1' : 'm.rel > 1'); }
    if (($q = md_norm($f['q'] ?? '')) !== '') { $w[] = '(m.nid LIKE ? OR m.main_nid LIKE ? OR CONCAT(m.first_name, " ", m.last_name) LIKE ? OR m.personnel_no LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%", "%$q%"); }
    $lim = max(1, min(5000, (int)($f['limit'] ?? 500)));
    $st = $pdo->prepare("SELECT SQL_CALC_FOUND_ROWS m.* FROM md_members m WHERE " . implode(' AND ', $w) . " ORDER BY m.company_id, m.main_nid, m.rel, m.id LIMIT $lim");
    $st->execute($a);
    $rows = $st->fetchAll();
    $total = (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
    $names = md_company_names($pdo); $pi = md_plan_index($pdo);
    $out = array_map(function ($m) use ($names, $pi) { $r = md_member_row($m, $names); $r['plan'] = $r['plan_id'] && isset($pi['byId'][$r['plan_id']]) ? $pi['byId'][$r['plan_id']]['name'] : ''; return $r; }, $rows);
    return ['items' => $out, 'total' => $total, 'period' => $p];
}
// تاریخچه‌ی یک نفر و خانواده‌اش (برای پرونده)
function md_person_history($pdo, $nid) {
    md_ops_ensure($pdo);
    $nid = md_nid($nid); if ($nid === '') return ['members' => [], 'transfers' => [], 'family' => []];
    $st = $pdo->prepare("SELECT * FROM md_members WHERE nid = ? OR main_nid = ? ORDER BY start_p DESC, rel");
    $st->execute([$nid, $nid]);
    $names = md_company_names($pdo); $pi = md_plan_index($pdo);
    $mem = array_map(function ($m) use ($names, $pi) { $r = md_member_row($m, $names); $r['plan'] = $r['plan_id'] && isset($pi['byId'][$r['plan_id']]) ? $pi['byId'][$r['plan_id']]['name'] : ''; return $r; }, $st->fetchAll());
    $tr = $pdo->prepare("SELECT * FROM md_transfers WHERE nid = ? ORDER BY period DESC, id DESC"); $tr->execute([$nid]);
    $trs = array_map(function ($t) use ($names) { return ['kind' => $t['kind'], 'from' => $names[(int)$t['from_company_id']] ?? '', 'to' => $names[(int)$t['to_company_id']] ?? '', 'period' => $t['period'], 'period_fa' => md_period_fa($t['period']), 'date_j' => (string)$t['date_j']]; }, $tr->fetchAll());
    $fam = [];
    foreach ($mem as $m) if ($m['main_nid'] === $nid && $m['nid'] !== $nid && !isset($fam[$m['nid']])) $fam[$m['nid']] = ['nid' => $m['nid'], 'name' => $m['name'], 'rel' => $m['rel_fa'], 'birth_j' => $m['birth_j'], 'active' => $m['end_p'] === null || $m['end_p'] > md_period_now()];
    return ['members' => $mem, 'transfers' => $trs, 'family' => array_values($fam)];
}

// ---------------- پرونده‌ی شخص (جدولِ persons) ----------------
function md_person_find($pdo, $nid) { $st = $pdo->prepare("SELECT id, company_id FROM persons WHERE national_code = ? ORDER BY id LIMIT 1"); $st->execute([$nid]); return $st->fetch() ?: null; }
// ساخت (اگر نیست) و ثبتِ انتقال در پرونده‌ی پرسنل
function md_person_link($pdo, $nid, $name, $toCompanyId, $dateJ, $mobile = '', $pcode = '') {
    if (!md_nid_valid($nid) && strlen($nid) !== 10) return null;
    $p = md_person_find($pdo, $nid);
    if (!$p) {
        if (!(int)md_settings($pdo)['create_persons']) return null;
        $pdo->prepare("INSERT INTO persons (national_code, full_name, company_id, mobile_number, personnel_code) VALUES (?, ?, ?, ?, ?)")
            ->execute([$nid, mb_substr($name, 0, 190) ?: null, $toCompanyId ?: null, $mobile ?: null, $pcode ?: null]);
        return (int)$pdo->lastInsertId();
    }
    $from = $p['company_id'] ? (int)$p['company_id'] : null;
    if ($toCompanyId && $from !== (int)$toCompanyId) {
        if (function_exists('per_ensure') || (file_exists(__DIR__ . '/_personnel.php') && (require_once __DIR__ . '/_personnel.php'))) per_ensure($pdo);
        $names = md_company_names($pdo);
        $pdo->prepare("INSERT INTO per_transfers (person_id, from_company_id, to_company_id, from_name, to_name, date_j, source, note, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, 'MD', ?, ?, NOW())")
            ->execute([(int)$p['id'], $from, (int)$toCompanyId, $names[$from] ?? null, $names[(int)$toCompanyId] ?? null, $dateJ ?: md_today_j(), 'از لیستِ درمان و عمر', md_user_name()]);
        if (!function_exists('pc_origin_freeze')) require_once __DIR__ . '/_case_helpers.php';
        pc_origin_freeze($pdo, (int)$p['id']);   // اقساطِ خودروی قبلی با «شرکتِ ایجاد» می‌مانند
        $pdo->prepare("UPDATE persons SET company_id = ? WHERE id = ?")->execute([(int)$toCompanyId, (int)$p['id']]);
    }
    return (int)$p['id'];
}

// ---------------- لیست‌ها: پیش‌نمایشِ تفاوت ----------------
// $L: ردیفِ md_lists (با payload['items'])؛ خروجی: عملیات‌هایی که «اعمال» انجام می‌دهد
function md_list_diff($pdo, array $L, array $items) {
    $S = md_settings($pdo);
    $cid = (int)$L['company_id']; $kind = $L['kind']; $P = $L['period'];
    $names = md_company_names($pdo);
    $ops = ['add' => [], 'remove' => [], 'change' => [], 'transfer' => [], 'skip' => [], 'warn' => []];
    $exitP = function ($j) use ($S, $P) { if (!$j) return $P; $p = md_period_of($j); return $S['exit_rounding'] === 'next' ? md_period_add($p, 1) : $p; };
    $startP = function ($j) use ($P) { return $j ? md_period_of($j) : $P; };
    $seen = [];
    foreach ($items as $it) {
        if (isset($seen[$it['nid']])) { $ops['warn'][] = ['nid' => $it['nid'], 'name' => trim($it['first_name'] . ' ' . $it['last_name']), 'msg' => 'دو بار در لیست آمده (ردیفِ ' . md_fa($it['row']) . ')']; continue; }
        $seen[$it['nid']] = 1;
        foreach ($it['warn'] as $w) $ops['warn'][] = ['nid' => $it['nid'], 'name' => trim($it['first_name'] . ' ' . $it['last_name']), 'msg' => $w];
    }
    if ($L['type'] === 'REMOVE') {
        $act = md_active($pdo, $cid, $kind, $P);
        // کسانی که در ماه‌های بعد شروع شده‌اند هم (لیستِ کاهشی ممکن است با تأخیر بیاید)
        // پایانِ دیرتری که قبلاً ثبت شده (مثلاً از لیستِ بیمه‌گر) هم جلو کشیده می‌شود
        $any = $pdo->prepare("SELECT * FROM md_members WHERE company_id = ? AND kind = ? AND (end_p IS NULL OR end_p > ?) AND (nid = ? OR main_nid = ?)");
        foreach ($items as $it) {
            $ep = $exitP($it['start_j'] ?: $it['end_j']);
            $any->execute([$cid, $kind, $ep, $it['nid'], $it['rel'] == 1 ? $it['nid'] : '__']);
            $rows = $any->fetchAll();
            if (!$rows) { $ops['skip'][] = ['nid' => $it['nid'], 'name' => trim($it['first_name'] . ' ' . $it['last_name']), 'msg' => 'در این شرکت فعال نیست']; continue; }
            foreach ($rows as $m) $ops['remove'][] = ['member_id' => (int)$m['id'], 'nid' => $m['nid'], 'name' => trim($m['first_name'] . ' ' . $m['last_name']), 'rel' => (int)$m['rel'], 'end_p' => max($ep, $m['start_p']), 'end_j' => $it['start_j'] ?: $it['end_j']];
        }
        return $ops;
    }
    $act = md_active($pdo, $cid, $kind, $P);
    $listed = [];
    foreach ($items as $it) {
        // شروع: افزایشی => ماهِ تاریخِ شروع (یا ماهِ لیست)؛ شروع/ماهانه => ماهِ لیست مگر تاریخِ شروعِ دیرتری در فایل باشد
        $sp = $L['type'] === 'ADD' ? $startP($it['start_j']) : max($P, $it['start_j'] ? md_period_of($it['start_j']) : $P);
        $endP = $it['end_j'] ? $exitP($it['end_j']) : null;
        if (($endP !== null && $endP <= max($sp, $P)) || ($it['inactive'] && $endP === null)) { if (isset($act[$it['nid']]) && $L['type'] !== 'ADD') $listed[$it['nid']] = 'gone'; continue; }
        if (isset($listed[$it['nid']])) continue;
        $listed[$it['nid']] = 1;
        $name = trim($it['first_name'] . ' ' . $it['last_name']);
        $it['end_p'] = $endP;
        if (isset($act[$it['nid']])) {
            $m = $act[$it['nid']]; $chg = [];
            if ($it['plan_id'] && (int)$m['plan_id'] !== (int)$it['plan_id']) $chg['plan_id'] = $it['plan_id'];
            if ((int)$m['rel'] !== (int)$it['rel']) $chg['rel'] = $it['rel'];
            if ($m['main_nid'] !== $it['main_nid']) $chg['main_nid'] = $it['main_nid'];
            if ($it['life_amount'] && (int)$m['life_amount'] !== (int)$it['life_amount']) $chg['life_amount'] = $it['life_amount'];
            if ($chg) $ops['change'][] = ['member_id' => (int)$m['id'], 'nid' => $it['nid'], 'name' => $name, 'set' => $chg, 'item' => $it, 'from_p' => $P];
            elseif ($L['type'] === 'ADD') $ops['skip'][] = ['nid' => $it['nid'], 'name' => $name, 'msg' => 'از قبل در همین شرکت فعال است'];
            continue;
        }
        $else = md_active_elsewhere($pdo, $it['nid'], $kind, $sp, $cid);
        if ($else) $ops['transfer'][] = ['nid' => $it['nid'], 'name' => $name, 'rel' => $it['rel'], 'from_company_id' => (int)$else['company_id'], 'from' => $names[(int)$else['company_id']] ?? '', 'member_id' => (int)$else['id'], 'start_p' => $sp, 'item' => $it];
        else $ops['add'][] = ['nid' => $it['nid'], 'name' => $name, 'rel' => $it['rel'], 'start_p' => $sp, 'item' => $it];
    }
    if ($L['type'] === 'START' || $L['type'] === 'MONTHLY')
        foreach ($act as $nid => $m) if (!isset($listed[$nid]) || $listed[$nid] === 'gone') $ops['remove'][] = ['member_id' => (int)$m['id'], 'nid' => $nid, 'name' => trim($m['first_name'] . ' ' . $m['last_name']), 'rel' => (int)$m['rel'], 'end_p' => max($P, $m['start_p']), 'end_j' => null];
    return $ops;
}
function md_list_counts(array $ops) { return ['add' => count($ops['add']), 'rem' => count($ops['remove']), 'chg' => count($ops['change']), 'trans' => count($ops['transfer']), 'warn' => count($ops['warn']) + count($ops['skip'])]; }

// ---------------- بارگذاری => پیش‌نویسِ لیست ----------------
function md_list_create($pdo, $companyId, $kind, $type, $period, $tmpPath, $fileName, $ext, $sheet = null, array $mapOverride = []) {
    md_ops_ensure($pdo);
    if (!isset(MD_LIST_TYPES[$type])) throw new RuntimeException('نوعِ لیست نامعتبر است.');
    if (!isset(MD_KINDS[$kind])) throw new RuntimeException('نوعِ بیمه نامعتبر است.');
    if (!md_period_ok($period)) throw new RuntimeException('ماه را درست انتخاب کنید.');
    $by = md_companies($pdo);
    if (!isset($by[(int)$companyId])) throw new RuntimeException('شرکت پیدا نشد.');
    $eff = md_effective($by, (int)$companyId);
    $contract = (int)($kind === 'HEALTH' ? $eff['health_contract_id'] : $eff['life_contract_id']) ?: null;
    if (!function_exists('xlsx_read_sheets')) require_once __DIR__ . '/_xlsx_writer.php';
    $sheets = xlsx_read_sheets($tmpPath, $ext);
    if (!$sheets) throw new RuntimeException('اکسل خوانده نشد.');
    // شیت: انتخابی، وگرنه شیتی که بیشترین ستونِ شناخته‌شده را دارد
    $bestS = null; $bestDet = null;
    foreach ($sheets as $sn => $rows) { if ($sheet !== null && $sheet !== '' && $sn !== $sheet) continue; $d = md_list_detect($rows); if (!$bestDet || $d['score'] > $bestDet['score']) { $bestS = $sn; $bestDet = $d; } }
    if (!$bestDet || !isset($bestDet['map']['nid'])) throw new RuntimeException('ستونِ «کد ملی» در فایل پیدا نشد؛ سرستون‌ها را بررسی کنید.');
    foreach ($mapOverride as $k => $v) { if ($v === '' || $v === null) unset($bestDet['map'][$k]); else $bestDet['map'][$k] = (int)$v; }
    $pi = md_plan_index($pdo);
    $items = md_list_items($sheets[$bestS], $bestDet, $contract ? ($pi['byContract'][$contract] ?? []) : []);
    if (!$items) throw new RuntimeException('ردیفی با کدِ ملی در فایل نبود.');
    // پیش‌فرضِ طرح: طرحِ پیش‌فرضِ شرکت
    $defPlan = (int)($kind === 'HEALTH' ? $eff['health_plan_id'] : $eff['life_plan_id']) ?: null;
    foreach ($items as &$it) if (!$it['plan_id'] && $defPlan) $it['plan_id'] = $defPlan;
    unset($it);
    // فایلِ اصلی در بایگانیِ همان شرکت و ماه
    $names = md_company_names($pdo);
    $dir = md_company_dir($names[(int)$companyId] ?? '', $period) . '/لیست‌ها';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $dest = md_unique($dir, MD_LIST_TYPES[$type] . ' - ' . MD_KINDS[$kind] . ' - ' . pathinfo($fileName, PATHINFO_FILENAME), $ext);
    if (!@copy($tmpPath, $dest)) $dest = null;
    $L = ['company_id' => (int)$companyId, 'kind' => $kind, 'type' => $type, 'period' => $period];
    $ops = md_list_diff($pdo, $L, $items);
    $n = md_list_counts($ops);
    $pdo->prepare("INSERT INTO md_lists (company_id, kind, type, period, contract_id, file_name, file_rel, rows_n, add_n, rem_n, chg_n, trans_n, warn_n, status, payload, created_by, created_name, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, ?, ?, NOW())")
        ->execute([(int)$companyId, $kind, $type, $period, $contract, mb_substr($fileName, 0, 250), $dest ? md_rel_path($dest) : null, count($items), $n['add'], $n['rem'], $n['chg'], $n['trans'], $n['warn'],
                   json_encode(['items' => $items, 'sheet' => $bestS, 'map' => $bestDet['map'], 'headers' => $bestDet['headers']], JSON_UNESCAPED_UNICODE), $_SESSION['user_id'] ?? null, md_user_name()]);
    $id = (int)$pdo->lastInsertId();
    md_log($pdo, 'LIST_UPLOAD', 'list:' . $id, MD_LIST_TYPES[$type] . ' · ' . ($names[(int)$companyId] ?? '') . ' · ' . md_period_fa($period) . ' · ' . count($items) . ' ردیف');
    return $id;
}
function md_list_get($pdo, $id) {
    md_ops_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM md_lists WHERE id = ?"); $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}
function md_list_out($pdo, array $L, $withOps = false) {
    $names = md_company_names($pdo);
    $o = ['id' => (int)$L['id'], 'company_id' => (int)$L['company_id'], 'company' => $names[(int)$L['company_id']] ?? '', 'kind' => $L['kind'], 'kind_fa' => MD_KINDS[$L['kind']] ?? '',
          'type' => $L['type'], 'type_fa' => MD_LIST_TYPES[$L['type']] ?? '', 'period' => $L['period'], 'period_fa' => md_period_fa($L['period']), 'file_name' => (string)$L['file_name'], 'has_file' => (bool)$L['file_rel'],
          'rows' => (int)$L['rows_n'], 'add' => (int)$L['add_n'], 'rem' => (int)$L['rem_n'], 'chg' => (int)$L['chg_n'], 'trans' => (int)$L['trans_n'], 'warn' => (int)$L['warn_n'],
          'status' => $L['status'], 'pas_add' => $L['pas_add'], 'pas_rem' => $L['pas_rem'], 'pas_add_at' => (string)$L['pas_add_at'], 'pas_rem_at' => (string)$L['pas_rem_at'],
          'created_name' => (string)$L['created_name'], 'created_at' => (string)$L['created_at'], 'applied_at' => (string)$L['applied_at'], 'void_reason' => (string)$L['void_reason'], 'note' => (string)$L['note']];
    if ($withOps) {
        $pl = json_decode((string)$L['payload'], true) ?: [];
        $o['ops'] = $L['status'] === 'DRAFT' ? md_list_diff($pdo, $L, $pl['items'] ?? []) : ($pl['applied'] ?? null);
        $o['headers'] = $pl['headers'] ?? []; $o['map'] = $pl['map'] ?? []; $o['sheet'] = $pl['sheet'] ?? '';
        $o['items_n'] = count($pl['items'] ?? []);
    }
    return $o;
}
function md_lists_list($pdo, array $f) {
    md_ops_ensure($pdo);
    $w = ['1=1']; $a = [];
    if (!empty($f['company_id'])) { $w[] = 'company_id = ?'; $a[] = (int)$f['company_id']; }
    if (!empty($f['kind'])) { $w[] = 'kind = ?'; $a[] = $f['kind']; }
    if (!empty($f['type'])) { $w[] = 'type = ?'; $a[] = $f['type']; }
    if (md_period_ok($f['period'] ?? '')) { $w[] = 'period = ?'; $a[] = $f['period']; }
    if (!empty($f['status'])) { $w[] = 'status = ?'; $a[] = $f['status']; }
    $st = $pdo->prepare("SELECT * FROM md_lists WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT 500");
    $st->execute($a);
    return array_map(function ($L) use ($pdo) { return md_list_out($pdo, $L); }, $st->fetchAll());
}

// ---------------- اعمالِ لیست ----------------
function md_member_insert($pdo, $listId, $companyId, $kind, array $it, $startP) {
    $pdo->prepare("INSERT INTO md_members (company_id, kind, nid, main_nid, rel, first_name, last_name, father, gender, birth_j, id_no, mobile, sheba, bank, personnel_no, plan_id, life_amount, start_j, start_p, list_id, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
        ->execute([(int)$companyId, $kind, $it['nid'], $it['main_nid'], (int)$it['rel'], $it['first_name'] ?: null, $it['last_name'] ?: null, $it['father'] ?: null, $it['gender'],
                   $it['birth_j'] ?: null, $it['id_no'] ?: null, $it['mobile'] ?: null, $it['sheba'] ?: null, $it['bank'] ?: null, $it['personnel_no'] ?: null, $it['plan_id'] ?: null,
                   $it['life_amount'] ?: null, $it['start_j'] ?: null, $startP, $listId]);
    $mid = (int)$pdo->lastInsertId();
    // پایانِ معلوم از همان فایل (مثلاً «مستعفی» با تاریخِ پایانِ پوشش)
    if (!empty($it['end_p']) && $it['end_p'] > $startP) $pdo->prepare("UPDATE md_members SET end_p = ?, end_j = ? WHERE id = ?")->execute([$it['end_p'], $it['end_j'] ?: null, $mid]);
    if ($listId) $pdo->prepare("INSERT INTO md_list_ops (list_id, member_id, op) VALUES (?, ?, 'ADD')")->execute([$listId, $mid]);
    return $mid;
}
function md_member_end($pdo, $listId, $memberId, $endP, $endJ = null) {
    $st = $pdo->prepare("SELECT end_p, end_j, end_list_id, start_p FROM md_members WHERE id = ?"); $st->execute([(int)$memberId]);
    $prev = $st->fetch(); if (!$prev) return;
    $endP = max($endP, $prev['start_p']);
    $pdo->prepare("UPDATE md_members SET end_p = ?, end_j = ?, end_list_id = ?, updated_at = NOW() WHERE id = ?")->execute([$endP, $endJ ?: null, $listId ?: null, (int)$memberId]);
    if ($listId) $pdo->prepare("INSERT INTO md_list_ops (list_id, member_id, op, prev) VALUES (?, ?, 'END', ?)")->execute([$listId, (int)$memberId, json_encode($prev)]);
}
function md_list_apply($pdo, $id) {
    md_ops_ensure($pdo);
    $L = md_list_get($pdo, $id);
    if (!$L) throw new RuntimeException('لیست پیدا نشد.');
    if ($L['status'] !== 'DRAFT') throw new RuntimeException('این لیست قبلاً اعمال یا ابطال شده.');
    $pl = json_decode((string)$L['payload'], true) ?: [];
    $ops = md_list_diff($pdo, $L, $pl['items'] ?? []);
    $cid = (int)$L['company_id']; $kind = $L['kind']; $lid = (int)$L['id'];
    $person = [];
    $pdo->beginTransaction();
    try {
        foreach ($ops['remove'] as $r) md_member_end($pdo, $lid, $r['member_id'], $r['end_p'], $r['end_j']);
        foreach ($ops['change'] as $c) {
            // تغییرِ طرح/نسبت از همین ماه: عضویتِ قبلی بسته و عضویتِ تازه باز می‌شود (حساب‌های ماه‌های قبل دست نمی‌خورد)
            $st = $pdo->prepare("SELECT * FROM md_members WHERE id = ?"); $st->execute([$c['member_id']]); $m = $st->fetch(); if (!$m) continue;
            if ($m['start_p'] >= $c['from_p']) {
                $pdo->prepare("INSERT INTO md_list_ops (list_id, member_id, op, prev) VALUES (?, ?, 'UPD', ?)")->execute([$lid, (int)$m['id'], json_encode($m)]);
                $set = []; $a = []; foreach ($c['set'] as $k => $v) { $set[] = "`$k` = ?"; $a[] = $v; }
                $a[] = (int)$m['id']; $pdo->prepare("UPDATE md_members SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ?")->execute($a);
            } else {
                md_member_end($pdo, $lid, (int)$m['id'], $c['from_p']);
                $it = array_merge(md_member_row($m), $c['set']);
                foreach ($c['item'] as $k => $v) if (in_array($k, ['first_name', 'last_name', 'father', 'birth_j', 'id_no', 'mobile', 'sheba', 'bank', 'personnel_no', 'gender'], true) && $v !== '' && $v !== null) $it[$k] = $v;
                $it['start_j'] = null;
                md_member_insert($pdo, $lid, $cid, $kind, $it, $c['from_p']);
            }
        }
        $inList = []; foreach ($pl['items'] ?? [] as $it) $inList[$it['nid']] = 1;
        foreach ($ops['transfer'] as $t) {
            md_member_end($pdo, $lid, $t['member_id'], $t['start_p'], $t['item']['start_j'] ?: null);
            // خانواده‌ی اصلیِ منتقل‌شده که در این لیست نیامده‌اند، در شرکتِ قبلی هم تمام می‌شوند
            if ((int)$t['rel'] === 1) {
                $fq = $pdo->prepare("SELECT id, nid FROM md_members WHERE company_id = ? AND kind = ? AND main_nid = ? AND nid <> ? AND start_p <= ? AND (end_p IS NULL OR end_p > ?)");
                $fq->execute([$t['from_company_id'], $kind, $t['nid'], $t['nid'], $t['start_p'], $t['start_p']]);
                foreach ($fq->fetchAll() as $f) if (!isset($inList[$f['nid']])) md_member_end($pdo, $lid, (int)$f['id'], $t['start_p']);
            }
            md_member_insert($pdo, $lid, $cid, $kind, $t['item'], $t['start_p']);
            $pdo->prepare("INSERT INTO md_transfers (kind, nid, name, from_company_id, to_company_id, period, date_j, list_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$kind, $t['nid'], mb_substr($t['name'], 0, 250), $t['from_company_id'], $cid, $t['start_p'], $t['item']['start_j'] ?: null, $lid]);
            if ((int)$t['rel'] === 1) $person[] = $t;
        }
        foreach ($ops['add'] as $a) { md_member_insert($pdo, $lid, $cid, $kind, $a['item'], $a['start_p']); if ((int)$a['rel'] === 1) $person[] = $a; }
        $n = md_list_counts($ops);
        $pl['applied'] = $ops;
        $pdo->prepare("UPDATE md_lists SET status = 'APPLIED', applied_at = NOW(), add_n = ?, rem_n = ?, chg_n = ?, trans_n = ?, warn_n = ?, payload = ? WHERE id = ?")
            ->execute([$n['add'], $n['rem'], $n['chg'], $n['trans'], $n['warn'], json_encode($pl, JSON_UNESCAPED_UNICODE), $lid]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    // پرونده‌ی شخص: بیمه‌شده‌های اصلیِ تازه و انتقالی
    foreach ($person as $p) { try { md_person_link($pdo, $p['nid'], $p['name'], $cid, $p['item']['start_j'] ?: ($L['period'] . '/01'), $p['item']['mobile'] ?? '', $p['item']['personnel_no'] ?? ''); } catch (Throwable $e) { error_log('[md person] ' . $e->getMessage()); } }
    md_log($pdo, 'LIST_APPLY', 'list:' . $lid, '+' . count($ops['add']) . ' −' . count($ops['remove']) . ' ↔' . count($ops['transfer']) . ' ✎' . count($ops['change']));
    return md_list_counts($ops);
}
// ابطال: همه‌ی تغییراتِ لیست برمی‌گردد (اعضای اضافه‌شده حذف، پایان‌ها برداشته، تغییرها برگردانده)
function md_list_void($pdo, $id, $reason) {
    md_ops_ensure($pdo);
    $L = md_list_get($pdo, $id);
    if (!$L) throw new RuntimeException('لیست پیدا نشد.');
    if ($L['status'] === 'VOID') throw new RuntimeException('این لیست قبلاً ابطال شده.');
    $pdo->beginTransaction();
    try {
        if ($L['status'] === 'APPLIED') {
            // اگر لیستِ بعدی روی همین افراد کار کرده، ابطال ممکن نیست (اول آن را باطل کنید)
            $later = $pdo->prepare("SELECT COUNT(*) FROM md_list_ops o JOIN md_lists l ON l.id = o.list_id WHERE l.status = 'APPLIED' AND o.list_id > ? AND o.member_id IN (SELECT member_id FROM md_list_ops WHERE list_id = ?)");
            $later->execute([(int)$id, (int)$id]);
            if ((int)$later->fetchColumn()) throw new RuntimeException('لیستِ دیگری بعد از این روی همین افراد اعمال شده؛ اول آن لیست را ابطال کنید.');
            $ops = $pdo->prepare("SELECT * FROM md_list_ops WHERE list_id = ? ORDER BY id DESC"); $ops->execute([(int)$id]);
            foreach ($ops->fetchAll() as $o) {
                if ($o['op'] === 'ADD') $pdo->prepare("DELETE FROM md_members WHERE id = ?")->execute([(int)$o['member_id']]);
                elseif ($o['op'] === 'END') { $p = json_decode((string)$o['prev'], true) ?: []; $pdo->prepare("UPDATE md_members SET end_p = ?, end_j = ?, end_list_id = ?, updated_at = NOW() WHERE id = ?")->execute([$p['end_p'] ?? null, $p['end_j'] ?? null, $p['end_list_id'] ?? null, (int)$o['member_id']]); }
                elseif ($o['op'] === 'UPD') {
                    $p = json_decode((string)$o['prev'], true) ?: [];
                    $pdo->prepare("UPDATE md_members SET plan_id = ?, rel = ?, main_nid = ?, life_amount = ?, updated_at = NOW() WHERE id = ?")->execute([$p['plan_id'] ?? null, $p['rel'] ?? 1, $p['main_nid'] ?? '', $p['life_amount'] ?? null, (int)$o['member_id']]);
                }
            }
            $pdo->prepare("DELETE FROM md_list_ops WHERE list_id = ?")->execute([(int)$id]);
            $pdo->prepare("DELETE FROM md_transfers WHERE list_id = ?")->execute([(int)$id]);
        }
        $pdo->prepare("UPDATE md_lists SET status = 'VOID', voided_at = NOW(), void_reason = ? WHERE id = ?")->execute([mb_substr(trim((string)$reason), 0, 400) ?: null, (int)$id]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    md_log($pdo, 'LIST_VOID', 'list:' . (int)$id, (string)$reason);
}

// ---------------- خروجیِ «ورودی به پاسارگاد» ----------------
const MD_PAS_HEALTH_HEAD = ['رديف', 'نام', 'نام خانوادگی', 'جنسیت', 'ملیت', 'کد ملی', 'تاریخ تولد', 'نام پدر', 'محل صدور', 'شماره شناسنامه', 'وضعیت تاهل', 'وضعیت تکفل', 'VIP', 'کد نسبت', 'بیمه شده اصلی', 'گروه',
    'بیمه گر اول', 'طبقه بندی شغلی', 'شهر محل خدمت', 'استان محل خدمت', 'کارت طلایی دارد؟', 'دفترچه درمان دارد؟', 'نوع دفترچه درمان', 'شماره نامه بیمه گذار', 'تاریخ نامه بیمه گذار', 'شماره همکاری',
    'شماره حساب', 'شماره شبا', 'شماره کارت', 'کد شعبه', 'کد بانک', 'واحد پولی', 'تاریخ شروع پوشش', 'تاریخ پایان پوشش', 'تاریخ شروع خسارت', 'تاریخ پایان خسارت', 'وضعیت بیمه شده', 'اعمال هشدار', 'شماره تلفن همراه'];
const MD_PAS_LIFE_HEAD = ['ردیف', 'نام', 'نام خانوادگی', 'نام پدر', 'محل صدور', 'شماره شناسنامه', 'تاریخ تولد', 'کد ملی', 'جنسیت', 'کد نسبت', 'كد ملي تحت تكفل', 'كد گروه', 'تاریخ شروع', 'تاریخ پایان', 'مليت',
    'شماره نامه بیمه گذار', 'تاریخ نامه بیمه گذار', 'مبلغ حقوق و مزایا', 'شماره تسهیلات', 'مبلغ تسهیلات', 'نوع تسهیلات', 'کد گروه شغلی'];
const MD_PAS_REMOVE_HEAD = ['ردیف', 'کدملی', 'تاریخ اثر'];
const MD_PAS_BANK_HEAD = ['ردیف', 'کد ملی', 'کد بانک', 'واحد پولی', 'کد شعبه', 'شماره حساب', 'شماره شبا', 'شماره کارت', 'شماره قرارداد'];

// افرادِ هر بخش از یک لیستِ اعمال‌شده: part = add (افزایشی/شروع/انتقالیِ ورودی) | remove (کاهشی) | bank
function md_pas_people($pdo, array $L, $part) {
    $pl = json_decode((string)$L['payload'], true) ?: [];
    $ops = $L['status'] === 'APPLIED' ? ($pl['applied'] ?? []) : md_list_diff($pdo, $L, $pl['items'] ?? []);
    if ($part === 'remove') return array_values($ops['remove'] ?? []);
    $o = [];
    foreach (array_merge($ops['add'] ?? [], $ops['transfer'] ?? []) as $x) $o[] = $x['item'] + ['start_p' => $x['start_p']];
    foreach ($ops['change'] ?? [] as $x) if (isset($x['set']['plan_id'])) $o[] = array_merge($x['item'], ['plan_id' => $x['set']['plan_id']]) + ['start_p' => $x['from_p']];
    // اصلی پیش از خانواده‌اش
    usort($o, function ($a, $b) { return strcmp($a['main_nid'], $b['main_nid']) ?: ($a['rel'] <=> $b['rel']); });
    return $o;
}
function md_pas_export($pdo, $listId, $part, $fmt) {
    md_ops_ensure($pdo);
    $L = md_list_get($pdo, $listId);
    if (!$L || $L['status'] === 'VOID') throw new RuntimeException('لیست پیدا نشد یا ابطال شده.');
    $S = md_settings($pdo); $pi = md_plan_index($pdo);
    $ct = $L['contract_id'] ? md_contract_get($pdo, (int)$L['contract_id']) : null;
    $people = md_pas_people($pdo, $L, $part === 'bank' ? 'add' : $part);
    if (!$people) throw new RuntimeException($part === 'remove' ? 'این لیست کاهشی ندارد.' : 'این لیست کسی برای ورود ندارد.');
    $fa = function ($j) { return (string)$j; };
    $planCode = function ($pid) use ($pi) { return $pid && isset($pi['byId'][$pid]) ? (string)$pi['byId'][$pid]['no'] : ''; };
    $sheets = [];
    if ($part === 'remove') {
        $rows = [MD_PAS_REMOVE_HEAD]; $i = 0;
        foreach ($people as $r) $rows[] = [(string)++$i, $r['nid'], $r['end_j'] ?: ($r['end_p'] . '/01')];
        $sheets[] = ['name' => 'Sheet1', 'rows' => $rows];
    } elseif ($part === 'bank') {
        $rows = [MD_PAS_BANK_HEAD]; $i = 0;
        foreach ($people as $r) if ($r['sheba']) $rows[] = [(string)++$i, $r['nid'], '', 'RLS', '', '', $r['sheba'], '', $ct ? (string)$ct['policy_no'] : ''];
        if ($i === 0) throw new RuntimeException('در این لیست شماره‌ی شبا ثبت نشده.');
        $sheets[] = ['name' => 'Sheet1', 'rows' => $rows];
    } elseif ($L['kind'] === 'LIFE') {
        $rows = [MD_PAS_LIFE_HEAD]; $cap = [['ردیف', 'سرمایه', 'کد پوشش', 'ردیف بیمه شده']]; $i = 0; $k = 0;
        foreach ($people as $r) {
            $i++;
            $rows[] = [(string)$i, $r['first_name'], $r['last_name'], $r['father'], '', $r['id_no'] ?: $r['nid'], $r['birth_j'], $r['nid'], $r['gender'] === null ? '' : (string)$r['gender'], (string)$r['rel'],
                       $r['rel'] > 1 ? $r['main_nid'] : '', $planCode($r['plan_id']), $r['start_j'] ?: ($r['start_p'] . '/01'), $ct ? $ct['end_j'] : '', $S['pas_nationality'],
                       $S['pas_letter_no'], $S['pas_letter_j'], '', '', '', '', ''];
            $amt = $r['life_amount'] ?: ($r['plan_id'] && isset($pi['byId'][$r['plan_id']]) ? (int)$pi['byId'][$r['plan_id']]['life_capital'] : 0);
            if ($amt) $cap[] = [(string)++$k, (string)$amt, $S['pas_cover_code'], (string)$i];
        }
        $sheets[] = ['name' => 'Sheet1', 'rows' => $rows]; $sheets[] = ['name' => 'Sheet2', 'rows' => $cap];
    } else {
        $rows = [MD_PAS_HEALTH_HEAD]; $i = 0;
        foreach ($people as $r) {
            $start = $r['start_j'] ?: ($r['start_p'] . '/01');
            $rows[] = [(string)++$i, $r['first_name'], $r['last_name'], $r['gender'] === null ? '' : (string)$r['gender'], $S['pas_nationality'], $r['nid'], $r['birth_j'], $r['father'], '',
                       $r['id_no'] ?: $r['nid'], '', $S['pas_takafol'], '', (string)$r['rel'], $r['rel'] > 1 ? $r['main_nid'] : '', $planCode($r['plan_id']), $S['pas_insurer_first'], '', '', '',
                       $S['pas_gold_card'], $S['pas_booklet'], '', $S['pas_letter_no'], $S['pas_letter_j'], '', '', $r['sheba'], '', '', '', $r['sheba'] ? 'RLS' : '', $start, '', $start, '',
                       $S['pas_status'], $S['pas_warning'], $r['mobile']];
        }
        $sheets[] = ['name' => 'Sheet1', 'rows' => $rows];
    }
    $fmt = $fmt === 'xls' ? 'xls' : 'xlsx';
    if ($fmt === 'xls') { require_once __DIR__ . '/_xls_writer.php'; $path = xls_build($sheets); }
    else {
        if (!function_exists('xlsx_build_book')) require_once __DIR__ . '/_xlsx_writer.php';
        $path = xlsx_build_book(array_map(function ($s) { $h = array_shift($s['rows']); return ['name' => $s['name'], 'headers' => $h, 'rows' => $s['rows']]; }, $sheets));
    }
    if (!$path) throw new RuntimeException('ساختِ فایل ممکن نشد.');
    $names = md_company_names($pdo);
    $label = ['add' => 'ورودی پاسارگاد - افزایشی', 'remove' => 'ورودی پاسارگاد - کاهشی', 'bank' => 'ورودی پاسارگاد - اطلاعات بانکی'][$part] . ' - ' . MD_KINDS[$L['kind']] . ' - ' . ($names[(int)$L['company_id']] ?? '') . ' - ' . str_replace('/', '-', $L['period']);
    if ($L['type'] === 'START' && $part === 'add') $label = str_replace('افزایشی', 'شروع قرارداد', $label);
    // نسخه‌ای در بایگانی + وضعیت
    $dir = md_company_dir($names[(int)$L['company_id']] ?? '', $L['period']) . '/ورودی پاسارگاد';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @copy($path, md_unique($dir, $label, $fmt));
    if ($part !== 'bank') {
        $col = $part === 'remove' ? 'pas_rem' : 'pas_add';
        $log = trim((string)$L['pas_log'] . "\n" . md_today_j() . ' ' . date('H:i') . ' · ' . md_user_name() . ' · ' . $label . '.' . $fmt);
        $pdo->prepare("UPDATE md_lists SET $col = IF($col = 'DONE', 'DONE', 'EXPORTED'), {$col}_at = NOW(), pas_log = ? WHERE id = ?")->execute([mb_substr($log, -4000), (int)$L['id']]);
    }
    md_log($pdo, 'PAS_EXPORT', 'list:' . (int)$L['id'], $label . '.' . $fmt);
    return ['path' => $path, 'name' => $label . '.' . $fmt, 'fmt' => $fmt];
}
// وضعیتِ «ورود به پاسارگاد» هر شرکت در یک ماه، جدا برای شروع / افزایشی / کاهشی
function md_pas_overview($pdo, $period, $kind = '') {
    md_ops_ensure($pdo);
    $a = [$period]; $k = '';
    if ($kind && isset(MD_KINDS[$kind])) { $k = ' AND kind = ?'; $a[] = $kind; }
    $st = $pdo->prepare("SELECT * FROM md_lists WHERE period = ? AND status = 'APPLIED'$k ORDER BY id");
    $st->execute($a);
    $names = md_company_names($pdo); $o = [];
    $rank = ['NONE' => 0, 'EXPORTED' => 1, 'DONE' => 2];
    foreach ($st->fetchAll() as $L) {
        $key = $L['company_id'] . '|' . $L['kind'];
        if (!isset($o[$key])) $o[$key] = ['company_id' => (int)$L['company_id'], 'company' => $names[(int)$L['company_id']] ?? '', 'kind' => $L['kind'], 'kind_fa' => MD_KINDS[$L['kind']],
                                         'start' => null, 'add' => null, 'remove' => null, 'lists' => []];
        $o[$key]['lists'][] = (int)$L['id'];
        $slot = function ($cur, $st) use ($rank) { return $cur === null ? $st : ($rank[$st] < $rank[$cur] ? $st : $cur); };   // بدترین وضعیت
        if ($L['type'] === 'START' && $L['add_n'] + $L['trans_n'] + $L['chg_n'] > 0) $o[$key]['start'] = $slot($o[$key]['start'], $L['pas_add']);
        if (in_array($L['type'], ['ADD', 'MONTHLY'], true) && $L['add_n'] + $L['trans_n'] + $L['chg_n'] > 0) $o[$key]['add'] = $slot($o[$key]['add'], $L['pas_add']);
        if (in_array($L['type'], ['REMOVE', 'MONTHLY', 'START'], true) && $L['rem_n'] > 0) $o[$key]['remove'] = $slot($o[$key]['remove'], $L['pas_rem']);
    }
    return array_values($o);
}
