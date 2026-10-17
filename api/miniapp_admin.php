<?php
// فایل: api/miniapp_admin.php
// پنل (فقط مدیر کل): ساعتِ کاریِ ثبتِ درخواستِ آنلاین + لاگِ استعلام‌های حق بیمه (جزئیات، پیام به کاربر در ربات/پنلِ کاربری)
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../config/db.php';
require __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_order_hours.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'فقط مدیر کل.']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? '');
$uid = intval($_SESSION['user_id']);

try {
    // ---------------- ساعتِ کاری ----------------
    if ($action === 'hours_get') $out(['ok' => true, 'cfg' => oh_get($pdo), 'status' => oh_status($pdo)]);
    if ($action === 'hours_save') { $cfg = oh_save($pdo, (array)($in['cfg'] ?? [])); $out(['ok' => true, 'cfg' => $cfg, 'status' => oh_status($pdo)]); }

    // ---------------- گزارشِ خطاهای مینی‌اپ (از گوشیِ کاربر - webapp/diag.js → api/webapp_client_log.php) ----------------
    if ($action === 'client_errors' || $action === 'client_errors_clear') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS webapp_client_errors (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            person_id INT NULL, page VARCHAR(190) NULL, ua VARCHAR(400) NULL, errors_text TEXT NULL, info_json TEXT NULL, ip VARCHAR(45) NULL,
            KEY k_created (created_at)) DEFAULT CHARSET=utf8mb4");
        if ($action === 'client_errors_clear') { $pdo->exec("DELETE FROM webapp_client_errors"); $out(['ok' => true]); }
        $rows = $pdo->query("SELECT e.*, p.full_name AS person_name, p.mobile_number FROM webapp_client_errors e LEFT JOIN persons p ON p.id = e.person_id ORDER BY e.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            [$y, $m, $d] = jalali_from_gregorian_ts(strtotime($r['created_at']));
            $r['created_j'] = sprintf('%04d/%02d/%02d', $y, $m, $d) . ' ' . date('H:i:s', strtotime($r['created_at']));
            $r['info'] = json_decode((string)$r['info_json'], true) ?: [];
            unset($r['info_json'], $r['ip']);
        }
        unset($r);
        $out(['ok' => true, 'rows' => $rows]);
    }

    // ---------------- استعلام‌ها ----------------
    if (in_array($action, ['inq_list', 'inq_get', 'inq_reply', 'inq_status'], true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS premium_inquiries (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, person_id INT NULL, bale_user_id BIGINT NULL,
            user_name VARCHAR(190) NULL, vehicle_kind VARCHAR(10) NULL, vehicle_group VARCHAR(120) NULL, vehicle_title VARCHAR(190) NULL, category_code TINYINT NULL, inputs_json MEDIUMTEXT NULL,
            result_json MEDIUMTEXT NULL, grand_total BIGINT NULL, status VARCHAR(16) NOT NULL DEFAULT 'DONE', user_note VARCHAR(500) NULL, accurate_at DATETIME NULL, reply_text TEXT NULL,
            replied_at DATETIME NULL, replied_by INT NULL, KEY k_created (created_at), KEY k_status (status), KEY k_person (person_id)) DEFAULT CHARSET=utf8mb4");
    }
    $jd = function ($dt) { if (!$dt) return ''; [$y, $m, $d] = jalali_from_gregorian_ts(strtotime($dt)); return sprintf('%04d/%02d/%02d', $y, $m, $d) . ' ' . date('H:i', strtotime($dt)); };
    $row = function ($r) use ($jd) {
        $r['inputs'] = json_decode((string)$r['inputs_json'], true) ?: [];
        $res = json_decode((string)$r['result_json'], true) ?: null;
        $r['result'] = $res ? ['grand_total' => $res['grand_total'] ?? null, 'net_total' => $res['net_total'] ?? null, 'tax' => $res['tax'] ?? null, 'category_name' => $res['category_name'] ?? null,
                               'thirdparty_percent' => $res['thirdparty_percent'] ?? null, 'driver_percent' => $res['driver_percent'] ?? null, 'days_late' => $res['days_late'] ?? null,
                               'breakdown' => $res['breakdown'] ?? null] : null;
        unset($r['inputs_json'], $r['result_json']);
        $r['created_j'] = $jd($r['created_at']); $r['accurate_j'] = $jd($r['accurate_at']); $r['replied_j'] = $jd($r['replied_at']);
        $r['can_message'] = !empty($r['person_id']) || !empty($r['bale_user_id']);
        return $r;
    };
    if ($action === 'inq_list') {
        $w = ['1=1']; $a = [];
        $f = (string)($in['filter'] ?? '');
        if ($f === 'ACCURATE') $w[] = "q.status = 'ACCURATE'";
        elseif ($f === 'NO_RATE') $w[] = "q.status = 'NO_RATE'";
        elseif ($f === 'ANSWERED') $w[] = "q.status = 'ANSWERED'";
        elseif ($f === 'GUEST') $w[] = 'q.person_id IS NULL';
        $qq = trim((string)($in['q'] ?? ''));
        if ($qq !== '') { $w[] = '(q.user_name LIKE ? OR q.vehicle_title LIKE ? OR p.national_code LIKE ? OR p.mobile_number LIKE ? OR q.id = ?)'; $l = '%' . $qq . '%'; array_push($a, $l, $l, $l, $l, intval($qq)); }
        $page = max(1, intval($in['page'] ?? 1)); $per = 30;
        $st = $pdo->prepare("SELECT COUNT(*) FROM premium_inquiries q LEFT JOIN persons p ON p.id = q.person_id WHERE " . implode(' AND ', $w)); $st->execute($a);
        $total = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT q.*, p.full_name AS person_name, p.national_code, p.mobile_number FROM premium_inquiries q LEFT JOIN persons p ON p.id = q.person_id
                             WHERE " . implode(' AND ', $w) . " ORDER BY (q.status = 'ACCURATE') DESC, q.id DESC LIMIT " . (($page - 1) * $per) . ", $per");
        $st->execute($a);
        $cnt = $pdo->query("SELECT status, COUNT(*) n FROM premium_inquiries GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $today = (int)$pdo->query("SELECT COUNT(*) FROM premium_inquiries WHERE DATE(created_at) = CURDATE()")->fetchColumn();
        $out(['ok' => true, 'rows' => array_map($row, $st->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $cnt, 'today' => $today]);
    }
    if ($action === 'inq_get') {
        $st = $pdo->prepare("SELECT q.*, p.full_name AS person_name, p.national_code, p.mobile_number FROM premium_inquiries q LEFT JOIN persons p ON p.id = q.person_id WHERE q.id = ?");
        $st->execute([intval($in['id'] ?? 0)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) $out(['ok' => false, 'error' => 'پیدا نشد.']);
        $out(['ok' => true, 'row' => $row($r)]);
    }
    if ($action === 'inq_status') {
        $s = in_array($in['status'] ?? '', ['DONE', 'ACCURATE', 'ANSWERED', 'NO_RATE'], true) ? $in['status'] : 'DONE';
        $pdo->prepare("UPDATE premium_inquiries SET status = ? WHERE id = ?")->execute([$s, intval($in['id'] ?? 0)]);
        $out(['ok' => true]);
    }
    // پیام به کاربر: کاربرِ واردشده در گفتگوی پنلِ کاربری (و خبرش در ربات)؛ مهمان مستقیم در ربات بله
    if ($action === 'inq_reply') {
        $st = $pdo->prepare("SELECT * FROM premium_inquiries WHERE id = ?");
        $st->execute([intval($in['id'] ?? 0)]);
        $q = $st->fetch();
        if (!$q) $out(['ok' => false, 'error' => 'پیدا نشد.']);
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') $out(['ok' => false, 'error' => 'متنِ پیام را بنویسید.']);
        $amount = preg_replace('/\D/', '', strtr((string)($in['amount'] ?? ''), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
        $msg = "💰 پاسخِ استعلامِ حق بیمه (شماره‌ی " . $q['id'] . ")\n" . ($amount !== '' ? '✅ مبلغِ استعلامِ دقیق: ' . number_format((int)$amount) . " ریال\n" : '') . "\n" . $text;
        $sent = false; $via = '';
        if ($q['person_id']) {
            try {
                require_once __DIR__ . '/_company_helpers.php';
                require_once __DIR__ . '/_auth_helpers.php';
                require_once __DIR__ . '/_chat_core.php';
                $u = $pdo->query("SELECT full_name, role FROM users WHERE id = " . $uid)->fetch();
                if (chat_ready($pdo)) {
                    $r = chat_send($pdo, ['kind' => 'STAFF', 'id' => $uid, 'name' => $u['full_name'] ?? 'کارشناس', 'role' => $u['role'] ?? 'ADMIN'], 'P', (int)$q['person_id'], $msg, null, null, null);
                    $sent = !empty($r['ok']); $via = 'پنلِ کاربری و ربات';
                }
            } catch (Throwable $e) { error_log('[inq_reply chat] ' . $e->getMessage()); }
        }
        if (!$sent && $q['bale_user_id']) {
            $sent = (bool)bale_curl_call('sendMessage', ['chat_id' => $q['bale_user_id'], 'text' => $msg], bale_get_token($pdo));
            $via = 'ربات بله';
        }
        if (!$sent) $out(['ok' => false, 'error' => $q['person_id'] || $q['bale_user_id'] ? 'ارسال ممکن نشد (کاربر ربات را مسدود کرده یا اتصال برقرار نشد).' : 'این کاربر شناسه‌ی بله ندارد (استعلام بیرون از ربات گرفته شده).']);
        $pdo->prepare("UPDATE premium_inquiries SET status = 'ANSWERED', reply_text = ?, replied_at = NOW(), replied_by = ? WHERE id = ?")->execute([$msg, $uid, $q['id']]);
        $out(['ok' => true, 'via' => $via]);
    }
    $out(['ok' => false, 'error' => 'اکشن نامعتبر.']);
} catch (Throwable $e) {
    error_log('[miniapp_admin] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
