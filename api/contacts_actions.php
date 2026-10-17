<?php
// فایل: api/contacts_actions.php
// «بانک مخاطبین»: همه‌ی کارکنانِ شرکت‌ها (جدولِ persons) با فیلتر، ویرایش، ورود از اکسل (پیش‌نمایش ← ثبت)، خروجیِ اکسل با فیلتر،
// اکسلِ نمونه و لاگِ کدهای تأییدِ (OTP) ارسالی برای هر شخص.
// ورود از اکسل هیچ داده‌ای را پاک نمی‌کند: خانه‌ی خالیِ اکسل = همان مقدارِ فعلی؛ پیش‌فرض فقط خانه‌های خالیِ سایت پر می‌شود.
ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);   // دسترسیِ سفارشیِ کاربر (صفحه به صفحه)
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_sms.php';
require_once __DIR__ . '/_contacts.php';   // چند شماره برای هر شخص + پیامِ همگانی

$out = function ($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'دسترسی ندارید.']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? ($_POST['action'] ?? ''));

// ستون‌های اکسلِ ورودی: کلید ← نام‌های قابلِ قبولِ سرستون
const CT_COLS = [
    'national_code' => ['کد ملی', 'کدملی', 'شماره ملی', 'national code', 'national_code'],
    'full_name' => ['نام و نام خانوادگی', 'نام کامل', 'نام', 'full name', 'name'],
    'mobile' => ['موبایل', 'شماره موبایل', 'تلفن همراه', 'همراه', 'شماره تماس', 'mobile', 'phone'],
    'company' => ['شرکت', 'نام شرکت', 'company'],
    'personnel_code' => ['کد پرسنلی', 'شماره پرسنلی', 'personnel code'],
];

function ct_norm_head($s) { return mb_strtolower(trim(preg_replace('/[\s\x{200C}_\-*:]+/u', ' ', str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$s)))); }
function ct_nid($s) {
    $d = preg_replace('/\D/', '', p2e_digits((string)$s));
    if ($d === '' || strlen($d) > 10 || strlen($d) < 8) return '';
    return str_pad($d, 10, '0', STR_PAD_LEFT);
}
// کنترلِ رقمِ کد ملی (فقط هشدار؛ برخی کدهای قدیمی معتبر نیستند)
function ct_nid_valid($n) {
    if (!preg_match('/^\d{10}$/', $n) || preg_match('/^(\d)\1{9}$/', $n)) return false;
    $s = 0; for ($i = 0; $i < 9; $i++) $s += (int)$n[$i] * (10 - $i);
    $r = $s % 11; $c = (int)$n[9];
    return ($r < 2 && $c === $r) || ($r >= 2 && $c === 11 - $r);
}
function ct_jd($dt) { if (!$dt) return ''; [$y, $m, $d] = jalali_from_gregorian_ts(strtotime($dt)); return sprintf('%04d/%02d/%02d', $y, $m, $d); }

// شرکت بدونِ ساختن (برای پیش‌نمایش): همان نام، یا تنها شرکتی که نامِ ساده‌شده‌اش یکی است
function ct_company_match($pdo, $name, &$cache) {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return null;
    if (array_key_exists($name, $cache)) return $cache[$name];
    if (!isset($cache['__all'])) $cache['__all'] = $pdo->query("SELECT id, name FROM companies WHERE COALESCE(is_import, 0) = 0")->fetchAll(PDO::FETCH_ASSOC);
    $hit = null; $key = company_name_key($name); $keyHits = [];
    foreach ($cache['__all'] as $c) {
        if ($c['name'] === $name) { $hit = ['id' => (int)$c['id'], 'name' => $c['name']]; break; }
        if ($key !== '' && company_name_key($c['name']) === $key) $keyHits[] = ['id' => (int)$c['id'], 'name' => $c['name']];
    }
    if (!$hit && count($keyHits) === 1) $hit = $keyHits[0];
    return $cache[$name] = $hit;
}

// شرطِ فیلترها (برای فهرست و خروجی)
function ct_where($pdo, array $f, array &$a) {
    $w = ["p.national_code IS NOT NULL", "p.national_code <> ''"];
    $q = trim(p2e_digits((string)($f['q'] ?? '')));
    if ($q !== '') {
        $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $w[] = '(p.full_name LIKE ? OR p.national_code LIKE ? OR p.mobile_number LIKE ? OR p.personnel_code LIKE ? OR p.bot_phone LIKE ?)';
        array_push($a, $l, $l, $l, $l, $l);
    }
    $co = (string)($f['company'] ?? '');
    if ($co === 'none') $w[] = '(p.company_id IS NULL OR p.company_id = 0)';
    elseif ($co !== '' && intval($co)) { $w[] = 'p.company_id = ?'; $a[] = intval($co); }
    $ph = (string)($f['phone'] ?? '');
    if ($ph === 'has') $w[] = "(p.mobile_number IS NOT NULL AND p.mobile_number <> '')";
    elseif ($ph === 'none') $w[] = "(p.mobile_number IS NULL OR p.mobile_number = '')";
    $bot = (string)($f['bot'] ?? '');
    if ($bot === 'linked') $w[] = 'p.bale_chat_id IS NOT NULL';
    elseif ($bot === 'unlinked') $w[] = 'p.bale_chat_id IS NULL';
    $intro = (string)($f['intro'] ?? '');
    if ($intro === 'has') $w[] = 'EXISTS (SELECT 1 FROM introductions i WHERE i.person_id = p.id)';
    elseif ($intro === 'none') $w[] = 'NOT EXISTS (SELECT 1 FROM introductions i WHERE i.person_id = p.id)';
    // کد ملی‌ای که بیش از یک ردیف در سایت دارد
    if ((string)($f['dup'] ?? '') === '1') $w[] = "p.national_code IN (SELECT national_code FROM persons WHERE national_code <> '' GROUP BY national_code HAVING COUNT(*) > 1)";
    return implode(' AND ', $w);
}
const CT_SELECT = "SELECT p.id, p.national_code, p.full_name, p.mobile_number, p.personnel_code, p.company_id, c.name AS company_name, p.bale_chat_id, p.bot_phone, p.bot_linked_at,
        p.created_at, p.saved_profile,
        (SELECT COUNT(*) FROM introductions i WHERE i.person_id = p.id) AS intro_count,
        (SELECT MAX(i.created_at) FROM introductions i WHERE i.person_id = p.id) AS last_intro_at,
        (SELECT CONCAT(i.used_quota, '/', i.max_quota) FROM introductions i WHERE i.person_id = p.id ORDER BY i.created_at DESC LIMIT 1) AS quota,
        (SELECT COUNT(*) FROM person_phones pp WHERE pp.person_id = p.id) AS phone_count,
        (SELECT COUNT(*) FROM persons d WHERE d.national_code = p.national_code) AS nid_rows
    FROM persons p LEFT JOIN companies c ON c.id = p.company_id";

try {
    otp_ensure($pdo);
    ct_phones_ensure($pdo);
    ct_bc_ensure($pdo);

    if ($action === 'meta') {
        $cos = $pdo->query("SELECT c.id, c.name, COUNT(p.id) n FROM companies c LEFT JOIN persons p ON p.company_id = c.id WHERE COALESCE(c.is_import, 0) = 0 GROUP BY c.id, c.name ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
        $tot = $pdo->query("SELECT COUNT(*) n, SUM(mobile_number IS NOT NULL AND mobile_number <> '') phone, SUM(bale_chat_id IS NOT NULL) bot, SUM(company_id IS NULL OR company_id = 0) nocompany
                            FROM persons WHERE national_code IS NOT NULL AND national_code <> ''")->fetch(PDO::FETCH_ASSOC);
        $out(['ok' => true, 'companies' => $cos, 'totals' => $tot]);
    }

    if ($action === 'list') {
        $a = [];
        $where = ct_where($pdo, $in, $a);
        $page = max(1, intval($in['page'] ?? 1)); $per = min(200, max(10, intval($in['per'] ?? 50)));
        $st = $pdo->prepare("SELECT COUNT(*) FROM persons p WHERE $where"); $st->execute($a);
        $total = (int)$st->fetchColumn();
        $st = $pdo->prepare(CT_SELECT . " WHERE $where ORDER BY p.id DESC LIMIT " . (($page - 1) * $per) . ", $per");
        $st->execute($a);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // آخرین کدِ تأییدِ هر شخص
        $ids = array_column($rows, 'id');
        $last = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT o.person_id, o.status, o.send_ok, o.created_at FROM otp_codes o JOIN (SELECT person_id, MAX(id) mid FROM otp_codes WHERE person_id IN ($ph) GROUP BY person_id) t ON t.mid = o.id");
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) $last[$o['person_id']] = $o;
        }
        foreach ($rows as &$r) {
            $r['last_intro_j'] = ct_jd($r['last_intro_at']); $r['created_j'] = ct_jd($r['created_at']);
            $r['bot_linked'] = $r['bale_chat_id'] !== null;
            $o = $last[$r['id']] ?? null;
            $r['otp'] = $o ? ['status' => $o['status'], 'status_fa' => OTP_STATUS_FA[$o['status']] ?? $o['status'], 'send_ok' => (int)$o['send_ok'], 'at' => ct_jd($o['created_at']) . ' ' . date('H:i', strtotime($o['created_at']))] : null;
            unset($r['saved_profile'], $r['bale_chat_id']);
        }
        unset($r);
        $out(['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
    }

    if ($action === 'get') {
        $id = intval($in['id'] ?? 0);
        $st = $pdo->prepare(CT_SELECT . " WHERE p.id = ?"); $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) $out(['ok' => false, 'error' => 'شخص پیدا نشد.']);
        $sp = json_decode((string)$r['saved_profile'], true) ?: [];
        $r['profile'] = $sp['خودم'] ?? null;
        $r['bot_linked'] = $r['bale_chat_id'] !== null;
        $r['bot_linked_j'] = ct_jd($r['bot_linked_at']); $r['created_j'] = ct_jd($r['created_at']); $r['last_intro_j'] = ct_jd($r['last_intro_at']);
        unset($r['saved_profile'], $r['bale_chat_id']);
        $r['otp_logs'] = otp_log_rows($pdo, 'person_id = ? OR (person_id IS NULL AND national_code = ?)', [$id, $r['national_code']], 100);
        $r['phones'] = ct_phone_list($pdo, $id);
        $out(['ok' => true, 'person' => $r]);
    }

    if ($action === 'update') {
        $id = intval($in['id'] ?? 0);
        $st = $pdo->prepare("SELECT id, national_code FROM persons WHERE id = ?"); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) $out(['ok' => false, 'error' => 'شخص پیدا نشد.']);
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['full_name'] ?? '')));
        $mobRaw = trim((string)($in['mobile_number'] ?? ''));
        $mob = $mobRaw === '' ? '' : sms_norm_phone($mobRaw);
        if ($mobRaw !== '' && $mob === '') $out(['ok' => false, 'error' => 'شماره‌ی موبایل معتبر نیست (۱۱ رقم، با ۰۹).']);
        if ($mob !== '') {
            $q = $pdo->prepare("SELECT national_code, full_name FROM persons WHERE mobile_number = ? AND id <> ? LIMIT 1"); $q->execute([$mob, $id]);
            if ($o = $q->fetch()) $out(['ok' => false, 'error' => 'این شماره برای شخصِ دیگری ثبت است: ' . ($o['full_name'] ?: '') . ' (' . $o['national_code'] . ')']);
        }
        $cid = intval($in['company_id'] ?? 0) ?: null;
        $pdo->prepare("UPDATE persons SET full_name = ?, personnel_code = ?, company_id = ? WHERE id = ?")
            ->execute([mb_substr($name, 0, 100), mb_substr(trim(p2e_digits((string)($in['personnel_code'] ?? ''))), 0, 20) ?: null, $cid, $id]);
        // شماره‌ی تازه پیش‌فرض می‌شود و قبلی در فهرستِ شماره‌ها می‌ماند؛ خالی = بدونِ شماره‌ی پیش‌فرض (فهرست می‌ماند)
        if ($mob !== '') ct_phone_add($pdo, $id, $mob, 'PANEL', true);
        else { ct_phone_list($pdo, $id); $pdo->prepare("UPDATE persons SET mobile_number = NULL WHERE id = ?")->execute([$id]); }
        $out(['ok' => true]);
    }

    // ---------------- شماره‌های یک شخص ----------------
    if (in_array($action, ['phone_default', 'phone_remove', 'phone_add'], true)) {
        $id = intval($in['id'] ?? 0);
        if (!$pdo->query("SELECT COUNT(*) FROM persons WHERE id = " . $id)->fetchColumn()) $out(['ok' => false, 'error' => 'شخص پیدا نشد.']);
        if ($action === 'phone_add') {
            $mob = sms_norm_phone((string)($in['phone'] ?? ''));
            if ($mob === '') $out(['ok' => false, 'error' => 'شماره‌ی موبایل معتبر نیست (۱۱ رقم، با ۰۹).']);
            $q = $pdo->prepare("SELECT national_code, full_name FROM persons WHERE mobile_number = ? AND id <> ? LIMIT 1"); $q->execute([$mob, $id]);
            if ($o = $q->fetch()) $out(['ok' => false, 'error' => 'این شماره شماره‌ی پیش‌فرضِ شخصِ دیگری است: ' . ($o['full_name'] ?: '') . ' (' . $o['national_code'] . ')']);
            ct_phone_add($pdo, $id, $mob, 'PANEL', !empty($in['make_default']));
            $out(['ok' => true, 'phones' => ct_phone_list($pdo, $id)]);
        }
        $st = $pdo->prepare("SELECT phone FROM person_phones WHERE id = ? AND person_id = ?");
        $st->execute([intval($in['phone_id'] ?? 0), $id]);
        $ph = $st->fetchColumn();
        if ($ph === false) $out(['ok' => false, 'error' => 'شماره پیدا نشد.']);
        $cur = (string)$pdo->query("SELECT mobile_number FROM persons WHERE id = " . $id)->fetchColumn();
        if ($action === 'phone_default') {
            $q = $pdo->prepare("SELECT national_code, full_name FROM persons WHERE mobile_number = ? AND id <> ? LIMIT 1"); $q->execute([$ph, $id]);
            if ($o = $q->fetch()) $out(['ok' => false, 'error' => 'این شماره الان شماره‌ی پیش‌فرضِ شخصِ دیگری است: ' . ($o['full_name'] ?: '') . ' (' . $o['national_code'] . ')']);
            ct_phone_add($pdo, $id, $ph, 'PANEL', true);
        } else {
            if ($ph === $cur) $out(['ok' => false, 'error' => 'شماره‌ی پیش‌فرض حذف نمی‌شود؛ اول شماره‌ی دیگری را پیش‌فرض کنید.']);
            $pdo->prepare("DELETE FROM person_phones WHERE id = ? AND person_id = ?")->execute([intval($in['phone_id']), $id]);
        }
        $out(['ok' => true, 'phones' => ct_phone_list($pdo, $id)]);
    }

    // ---------------- آمارِ بالای صفحه ----------------
    if ($action === 'stats') {
        $base = "FROM persons p WHERE p.national_code IS NOT NULL AND p.national_code <> ''";
        $t = $pdo->query("SELECT COUNT(*) persons, COUNT(DISTINCT p.national_code) nids, SUM(p.mobile_number IS NOT NULL AND p.mobile_number <> '') with_phone,
                                 SUM(p.bale_chat_id IS NOT NULL) bot, SUM(p.company_id IS NULL OR p.company_id = 0) nocompany,
                                 SUM(EXISTS (SELECT 1 FROM introductions i WHERE i.person_id = p.id)) with_intro $base")->fetch(PDO::FETCH_ASSOC);
        $t['phones'] = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT phone FROM person_phones UNION SELECT mobile_number FROM persons WHERE mobile_number IS NOT NULL AND mobile_number <> '') x")->fetchColumn();
        $t['multi_phone'] = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT person_id FROM person_phones GROUP BY person_id HAVING COUNT(*) > 1) x")->fetchColumn();
        $t['dup_nids'] = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT national_code FROM persons WHERE national_code <> '' GROUP BY national_code HAVING COUNT(*) > 1) x")->fetchColumn();
        $bc = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(sent), 0) sent, COALESCE(SUM(failed), 0) failed FROM contact_broadcasts")->fetch(PDO::FETCH_ASSOC);
        $otp = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(send_ok = 1), 0) sent, COALESCE(SUM(status = 'VERIFIED'), 0) verified FROM otp_codes")->fetch(PDO::FETCH_ASSOC);
        $cos = $pdo->query("SELECT c.id, c.name, COUNT(p.id) persons, SUM(p.mobile_number IS NOT NULL AND p.mobile_number <> '') with_phone, SUM(p.bale_chat_id IS NOT NULL) bot,
                                   SUM(EXISTS (SELECT 1 FROM introductions i WHERE i.person_id = p.id)) with_intro
                            FROM companies c JOIN persons p ON p.company_id = c.id AND p.national_code <> '' WHERE COALESCE(c.is_import, 0) = 0
                            GROUP BY c.id, c.name ORDER BY persons DESC, c.name")->fetchAll(PDO::FETCH_ASSOC);
        $out(['ok' => true, 'totals' => $t, 'broadcasts' => $bc, 'otp' => $otp, 'companies' => $cos]);
    }

    // ---------------- پیامِ همگانی (به کسانی که در ربات هستند) ----------------
    // گیرنده‌ها: همان فیلترهای فهرست + فقط متصل به ربات. ارسال تکه‌تکه (bc_step) تا درخواست طولانی نشود.
    if ($action === 'bc_count' || $action === 'bc_create') {
        $f = (array)($in['filters'] ?? []); $f['bot'] = 'linked';
        $a = [];
        $where = ct_where($pdo, $f, $a);
        if ($action === 'bc_count') { $st = $pdo->prepare("SELECT COUNT(*) FROM persons p WHERE $where"); $st->execute($a); $out(['ok' => true, 'count' => (int)$st->fetchColumn()]); }
        $text = trim((string)($in['text'] ?? ''));
        if (mb_strlen($text) < 2) $out(['ok' => false, 'error' => 'متنِ پیام را بنویسید.']);
        if (mb_strlen($text) > 3500) $out(['ok' => false, 'error' => 'متنِ پیام خیلی بلند است (حداکثر ۳۵۰۰ نویسه).']);
        if (!bale_get_token($pdo)) $out(['ok' => false, 'error' => 'توکنِ رباتِ بله در تنظیمات ثبت نشده است.']);
        $st = $pdo->prepare("SELECT p.id, p.bale_chat_id, p.full_name, p.national_code, c.name AS company FROM persons p LEFT JOIN companies c ON c.id = p.company_id WHERE $where");
        $st->execute($a);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) $out(['ok' => false, 'error' => 'با این فیلترها کسی در ربات نیست.']);
        $pdo->prepare("INSERT INTO contact_broadcasts (created_by, text, filters_json, total) VALUES (?, ?, ?, ?)")
            ->execute([intval($_SESSION['user_id']), $text, json_encode($f, JSON_UNESCAPED_UNICODE), count($rows)]);
        $bid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO contact_broadcast_recipients (broadcast_id, person_id, chat_id, full_name, national_code, company) VALUES (?, ?, ?, ?, ?, ?)");
        $seen = [];
        foreach ($rows as $r) { if (isset($seen[$r['bale_chat_id']])) continue; $seen[$r['bale_chat_id']] = 1; $ins->execute([$bid, $r['id'], (string)$r['bale_chat_id'], $r['full_name'], $r['national_code'], $r['company']]); }
        $pdo->prepare("UPDATE contact_broadcasts SET total = ? WHERE id = ?")->execute([count($seen), $bid]);
        $out(['ok' => true, 'id' => $bid, 'total' => count($seen)]);
    }
    if ($action === 'bc_step') {
        $bid = intval($in['id'] ?? 0);
        $b = $pdo->query("SELECT * FROM contact_broadcasts WHERE id = " . $bid)->fetch(PDO::FETCH_ASSOC);
        if (!$b) $out(['ok' => false, 'error' => 'پیام پیدا نشد.']);
        if ($b['status'] === 'RUNNING') {
            $token = bale_get_token($pdo);
            @set_time_limit(120);
            $list = $pdo->query("SELECT * FROM contact_broadcast_recipients WHERE broadcast_id = $bid AND status = 'PENDING' ORDER BY id LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
            $up = $pdo->prepare("UPDATE contact_broadcast_recipients SET status = ?, error = ?, message_id = ?, sent_at = NOW() WHERE id = ?");
            foreach ($list as $r) {
                $name = trim((string)$r['full_name']) ?: 'همکار گرامی';
                $res = ct_bot_send($token, $r['chat_id'], strtr($b['text'], ['{name}' => $name, '{company}' => (string)$r['company']]));
                $up->execute([$res['ok'] ? 'SENT' : 'FAILED', $res['ok'] ? null : $res['error'], $res['message_id'] ?? null, $r['id']]);
                usleep(60000);   // سقفِ ارسالِ بله
            }
            $c = $pdo->query("SELECT SUM(status = 'SENT') s, SUM(status = 'FAILED') f, SUM(status = 'PENDING') p FROM contact_broadcast_recipients WHERE broadcast_id = $bid")->fetch(PDO::FETCH_ASSOC);
            $done = (int)$c['p'] === 0;
            $pdo->prepare("UPDATE contact_broadcasts SET sent = ?, failed = ?, status = IF(?, 'DONE', status), finished_at = IF(?, NOW(), finished_at) WHERE id = ?")
                ->execute([(int)$c['s'], (int)$c['f'], $done ? 1 : 0, $done ? 1 : 0, $bid]);
            $b = $pdo->query("SELECT * FROM contact_broadcasts WHERE id = " . $bid)->fetch(PDO::FETCH_ASSOC);
        }
        $out(['ok' => true, 'broadcast' => $b]);
    }
    if ($action === 'bc_cancel') {
        $bid = intval($in['id'] ?? 0);
        $pdo->prepare("UPDATE contact_broadcast_recipients SET status = 'CANCELED' WHERE broadcast_id = ? AND status = 'PENDING'")->execute([$bid]);
        $pdo->prepare("UPDATE contact_broadcasts SET status = 'CANCELED', finished_at = NOW() WHERE id = ? AND status = 'RUNNING'")->execute([$bid]);
        $out(['ok' => true]);
    }
    if ($action === 'bc_list') {
        $rows = $pdo->query("SELECT b.*, u.full_name AS by_name FROM contact_broadcasts b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['created_j'] = ct_jd($r['created_at']) . ' ' . date('H:i', strtotime($r['created_at'])); $r['filters'] = json_decode((string)$r['filters_json'], true) ?: []; unset($r['filters_json']); }
        unset($r);
        $out(['ok' => true, 'rows' => $rows]);
    }
    if ($action === 'bc_export') {
        require_once __DIR__ . '/_xlsx_writer.php';
        $bid = intval($_GET['id'] ?? 0);
        $b = $pdo->query("SELECT * FROM contact_broadcasts WHERE id = " . $bid)->fetch(PDO::FETCH_ASSOC);
        if (!$b) $out(['ok' => false, 'error' => 'پیام پیدا نشد.']);
        $stFa = ['SENT' => 'ارسال شد', 'FAILED' => 'ناموفق', 'PENDING' => 'در صف', 'CANCELED' => 'لغو شد'];
        $rows = [];
        foreach ($pdo->query("SELECT * FROM contact_broadcast_recipients WHERE broadcast_id = $bid ORDER BY id") as $i => $r)
            $rows[] = [$i + 1, $r['full_name'], $r['national_code'], $r['company'], $stFa[$r['status']] ?? $r['status'], $r['sent_at'] ? ct_jd($r['sent_at']) . ' ' . date('H:i:s', strtotime($r['sent_at'])) : '', (string)$r['error']];
        $rows[] = []; $rows[] = ['', 'متنِ پیام:', $b['text']];
        $path = xlsx_build(['ردیف', 'نام', 'کد ملی', 'شرکت', 'وضعیت', 'زمانِ ارسال', 'خطا'], $rows, 'گزارش ارسال', [0]);
        xlsx_send($path, 'گزارش پیام همگانی ' . $bid . ' - ' . str_replace('/', '-', ct_jd($b['created_at'])) . '.xlsx');
        exit;
    }

    // ---------------- اکسلِ نمونه ----------------
    if ($action === 'sample') {
        require_once __DIR__ . '/_xlsx_writer.php';
        $path = xlsx_build(['کد ملی', 'نام و نام خانوادگی', 'موبایل', 'شرکت', 'کد پرسنلی'], [
            ['0012345678', 'علی نمونه', '09120000000', 'شرکت نمونه', '1001'],
            ['0098765432', 'مریم نمونه', '09350000000', 'شرکت نمونه', ''],
        ], 'بانک مخاطبین');
        xlsx_send($path, 'نمونه‌ی اکسلِ بانک مخاطبین.xlsx');
        exit;
    }

    // ---------------- خروجیِ اکسل با همان فیلترها ----------------
    if ($action === 'export') {
        require_once __DIR__ . '/_xlsx_writer.php';
        $f = $_GET; $a = [];
        $where = ct_where($pdo, $f, $a);
        $st = $pdo->prepare(CT_SELECT . " WHERE $where ORDER BY c.name, p.full_name");
        $st->execute($a);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sp = (json_decode((string)$r['saved_profile'], true) ?: [])['خودم'] ?? [];
            $rows[] = [$r['national_code'], $r['full_name'], $r['mobile_number'], $r['company_name'] ?: '', $r['personnel_code'] ?: '', (int)$r['intro_count'],
                       ct_jd($r['last_intro_at']), $r['quota'] ?: '', $r['bale_chat_id'] !== null ? 'متصل' : 'متصل نیست', $r['bot_phone'] ?: '',
                       $sp['birth_date'] ?? '', $sp['phone'] ?? '', $sp['postal_code'] ?? '', $sp['address'] ?? '', ct_jd($r['created_at'])];
        }
        $path = xlsx_build(['کد ملی', 'نام و نام خانوادگی', 'موبایل', 'شرکت', 'کد پرسنلی', 'تعداد معرفی‌نامه', 'آخرین معرفی‌نامه', 'سهمیه (استفاده/کل)',
                            'ربات بله', 'شماره‌ی ربات', 'تاریخ تولد', 'تلفن', 'کد پستی', 'آدرس', 'تاریخ ثبت'], $rows, 'بانک مخاطبین', [5]);
        $co = '';
        if (intval($f['company'] ?? 0)) { $q = $pdo->prepare("SELECT name FROM companies WHERE id = ?"); $q->execute([intval($f['company'])]); $co = ' - ' . $q->fetchColumn(); }
        [$y, $m, $d] = jalali_from_gregorian_ts(time());
        xlsx_send($path, sprintf('بانک مخاطبین%s - %04d-%02d-%02d.xlsx', $co, $y, $m, $d));
        exit;
    }

    // ---------------- ورود از اکسل: پیش‌نمایش ----------------
    if ($action === 'import_preview') {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) $out(['ok' => false, 'error' => 'فایلی دریافت نشد.']);
        require_once __DIR__ . '/finance_core.php';
        $rows = fin_read_xlsx_fallback($_FILES['file']['tmp_name']) ?: [];
        if (count($rows) < 2) $out(['ok' => false, 'error' => 'اکسل خوانده نشد یا ردیفی ندارد (فرمتِ xlsx، سرستون در ردیفِ اول).']);
        $head = array_map('ct_norm_head', array_values($rows[0]));
        $map = [];
        foreach (CT_COLS as $k => $names) foreach ($head as $i => $h) if (in_array($h, array_map('ct_norm_head', $names), true)) { $map[$k] = $i; break; }
        if (!isset($map['national_code'])) $out(['ok' => false, 'error' => 'ستونِ «کد ملی» در ردیفِ اولِ اکسل پیدا نشد. از اکسلِ نمونه استفاده کنید.']);
        $exist = $pdo->prepare("SELECT p.id, p.full_name, p.mobile_number, p.personnel_code, p.company_id, c.name AS company_name FROM persons p LEFT JOIN companies c ON c.id = p.company_id WHERE p.national_code = ?");
        $phoneOwner = $pdo->prepare("SELECT national_code FROM persons WHERE mobile_number = ? AND national_code <> ? LIMIT 1");
        $histQ = $pdo->prepare("SELECT 1 FROM person_phones WHERE person_id = ? AND phone = ? LIMIT 1");
        $inHist = function ($pid, $ph) use ($histQ) { $histQ->execute([$pid, $ph]); return (bool)$histQ->fetchColumn(); };
        $cache = []; $seen = []; $seenPhone = []; $items = [];
        $cnt = ['new' => 0, 'update' => 0, 'same' => 0, 'error' => 0, 'new_company' => 0];
        $newCos = [];
        foreach (array_slice($rows, 1) as $ri => $r) {
            $r = array_values($r);
            $cell = function ($k) use ($r, $map) { return isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : ''; };
            $rawNid = $cell('national_code');
            if ($rawNid === '' && implode('', array_map('trim', array_map('strval', $r))) === '') continue;   // ردیفِ خالی
            $it = ['row' => $ri + 2, 'national_code' => ct_nid($rawNid), 'full_name' => trim(preg_replace('/\s+/u', ' ', $cell('full_name'))),
                   'mobile' => '', 'company' => trim(preg_replace('/\s+/u', ' ', $cell('company'))), 'personnel_code' => trim(p2e_digits($cell('personnel_code'))), 'warn' => [], 'action' => ''];
            if ($it['national_code'] === '') { $it['action'] = 'error'; $it['warn'][] = 'کد ملی نامعتبر'; $it['raw_nid'] = $rawNid; $cnt['error']++; $items[] = $it; continue; }
            if (isset($seen[$it['national_code']])) { $it['action'] = 'error'; $it['warn'][] = 'تکراری در همین فایل (ردیفِ ' . $seen[$it['national_code']] . ')'; $cnt['error']++; $items[] = $it; continue; }
            $seen[$it['national_code']] = $it['row'];
            if (!ct_nid_valid($it['national_code'])) $it['warn'][] = 'رقمِ کنترلِ کد ملی درست نیست';
            $mr = $cell('mobile');
            if ($mr !== '') {
                $m = sms_norm_phone($mr);
                if ($m === '') $it['warn'][] = 'موبایلِ نامعتبر (وارد نمی‌شود)';
                else {
                    $phoneOwner->execute([$m, $it['national_code']]);
                    if ($o = $phoneOwner->fetchColumn()) $it['warn'][] = 'موبایل برای کد ملیِ دیگری (' . $o . ') ثبت است (وارد نمی‌شود)';
                    elseif (isset($seenPhone[$m])) $it['warn'][] = 'موبایل در همین فایل برای ردیفِ ' . $seenPhone[$m] . ' هم آمده (وارد نمی‌شود)';
                    else { $it['mobile'] = $m; $seenPhone[$m] = $it['row']; }
                }
            }
            if ($it['company'] !== '') {
                $c = ct_company_match($pdo, $it['company'], $cache);
                if ($c) $it['company_match'] = $c['name'];
                else { $it['warn'][] = 'شرکتِ تازه ساخته می‌شود'; if (!isset($newCos[$it['company']])) { $newCos[$it['company']] = 1; $cnt['new_company']++; } }
            }
            $exist->execute([$it['national_code']]);
            $e = $exist->fetch(PDO::FETCH_ASSOC);
            if (!$e) { $it['action'] = 'new'; $cnt['new']++; }
            else {
                $it['person_id'] = (int)$e['id'];
                $it['current'] = ['full_name' => $e['full_name'], 'mobile' => $e['mobile_number'], 'personnel_code' => $e['personnel_code'], 'company' => $e['company_name']];
                // در چه فیلدهایی فرق دارد (خانه‌ی خالیِ اکسل = بدونِ تغییر)
                $diff = [];
                if ($it['full_name'] !== '' && $it['full_name'] !== (string)$e['full_name']) $diff[] = 'full_name';
                if ($it['mobile'] !== '' && $it['mobile'] === (string)$e['mobile_number']) $it['dup_phone'] = 1;   // کد ملی و شماره هر دو تکراری
                elseif ($it['mobile'] !== '' && $inHist($e['id'], $it['mobile'])) {
                    // شماره‌ی قدیمیِ همین شخص (مثلاً اکسلِ قدیمی دوباره وارد شده): پیش‌فرض عوض نمی‌شود
                    $it['dup_phone'] = 1;
                    $it['warn'][] = 'این شماره قبلاً در فهرستِ شماره‌های همین شخص هست؛ پیش‌فرض عوض نمی‌شود (از پنجره‌ی شخص می‌شود پیش‌فرضش کرد)';
                    $it['mobile'] = '';
                } elseif ($it['mobile'] !== '') {
                    $diff[] = 'mobile';
                    if ((string)$e['mobile_number'] !== '') $it['warn'][] = 'شماره‌ی تازه پیش‌فرض می‌شود؛ شماره‌ی قبلی (' . $e['mobile_number'] . ') در فهرستِ شماره‌های این شخص می‌ماند';
                }
                if ($it['personnel_code'] !== '' && $it['personnel_code'] !== (string)$e['personnel_code']) $diff[] = 'personnel_code';
                if ($it['company'] !== '' && ($it['company_match'] ?? '') !== (string)$e['company_name']) $diff[] = 'company';
                $it['diff'] = $diff;
                $it['action'] = $diff ? 'update' : 'same';
                $cnt[$diff ? 'update' : 'same']++;
            }
            $items[] = $it;
        }
        if (!$items) $out(['ok' => false, 'error' => 'ردیفی برای ورود پیدا نشد.']);
        $token = bin2hex(random_bytes(12));
        file_put_contents(sys_get_temp_dir() . '/contacts_import_' . $token . '.json', json_encode(['uid' => $_SESSION['user_id'], 'items' => $items], JSON_UNESCAPED_UNICODE));
        $out(['ok' => true, 'token' => $token, 'counts' => $cnt, 'items' => array_slice($items, 0, 500), 'total' => count($items), 'cols' => array_keys($map)]);
    }

    // ---------------- ورود از اکسل: ثبت ----------------
    // mode = fill (فقط خانه‌های خالیِ سایت پر می‌شود؛ پیش‌فرض) | overwrite (مقدارِ اکسل جایگزین می‌شود؛ خانه‌ی خالیِ اکسل باز هم بی‌اثر است)
    if ($action === 'import_commit') {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($in['token'] ?? ''));
        $file = sys_get_temp_dir() . '/contacts_import_' . $token . '.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!$data || (string)$data['uid'] !== (string)$_SESSION['user_id']) $out(['ok' => false, 'error' => 'پیش‌نمایش منقضی شده است؛ فایل را دوباره انتخاب کنید.']);
        $overwrite = ($in['mode'] ?? 'fill') === 'overwrite';
        $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'companies' => 0];
        $pdo->beginTransaction();
        try {
            $coBefore = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
            $sel = $pdo->prepare("SELECT id, full_name, mobile_number, personnel_code, company_id FROM persons WHERE national_code = ? FOR UPDATE");
            $phoneOwner = $pdo->prepare("SELECT id FROM persons WHERE mobile_number = ? AND national_code <> ? LIMIT 1");
            foreach ($data['items'] as $it) {
                if (!in_array($it['action'], ['new', 'update'], true)) { $res['skipped']++; continue; }
                $cid = $it['company'] !== '' ? company_find_or_create_intro($pdo, $it['company']) : null;
                $mob = $it['mobile'];
                if ($mob !== '') { $phoneOwner->execute([$mob, $it['national_code']]); if ($phoneOwner->fetchColumn()) $mob = ''; }   // در این فاصله کسِ دیگری گرفته
                $sel->execute([$it['national_code']]);
                $e = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$e) {
                    $pdo->prepare("INSERT INTO persons (national_code, full_name, mobile_number, personnel_code, company_id, conversation_state) VALUES (?, ?, ?, ?, ?, 'START')")
                        ->execute([$it['national_code'], mb_substr($it['full_name'], 0, 100), $mob ?: null, mb_substr($it['personnel_code'], 0, 20) ?: null, $cid]);
                    if ($mob !== '') ct_phone_add($pdo, (int)$pdo->lastInsertId(), $mob, 'IMPORT', true);
                    $res['created']++;
                    continue;
                }
                $set = []; $args = [];
                $put = function ($col, $new, $cur) use (&$set, &$args, $overwrite) {
                    if ($new === '' || $new === null) return;
                    if ((string)$cur === (string)$new) return;
                    if (!$overwrite && trim((string)$cur) !== '' && (string)$cur !== '0') return;
                    $set[] = "$col = ?"; $args[] = $new;
                };
                $put('full_name', mb_substr($it['full_name'], 0, 100), $e['full_name']);
                // شماره‌ی تازه در هر حالت پیش‌فرض می‌شود و شماره‌ی قبلی در فهرستِ شماره‌ها می‌ماند
                $phoneChanged = $mob !== '' && $mob !== (string)$e['mobile_number'];
                if ($phoneChanged) ct_phone_add($pdo, (int)$e['id'], $mob, 'IMPORT', true);
                $put('personnel_code', mb_substr($it['personnel_code'], 0, 20), $e['personnel_code']);
                $put('company_id', $cid, $e['company_id']);
                if ($set) {
                    $args[] = $e['id'];
                    $pdo->prepare("UPDATE persons SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
                }
                if ($set || $phoneChanged) $res['updated']++; else $res['skipped']++;
            }
            $res['companies'] = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn() - $coBefore;
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        @unlink($file);
        $out(['ok' => true, 'result' => $res]);
    }

    $out(['ok' => false, 'error' => 'عملیات نامعتبر.']);
} catch (Throwable $e) {
    error_log('[contacts_actions] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
