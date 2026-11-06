<?php
// فایل: api/ext_actions.php
// بخشِ «افزونه‌ها»ی پنل: دانلودِ دستیارِ بیمه باما (برای کسی که دسترسیِ دانلود دارد)، و برای مدیر:
// نام‌کاربریِ هر کاربر در هر بخش (اقساط / درگاهِ آتی)، دستگاه‌ها، فعالیت‌ها (چه کسی، کی، چه کرد)، اعلان‌ها و تنظیمات.
// منطق: api/_ext.php ؛ وب‌سرویسِ خودِ افزونه: api/ext_api.php
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_ext.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
$data = (json_decode(file_get_contents('php://input'), true) ?: []) + $_POST + $_GET;
$action = (string)($data['action'] ?? '');
$me = intval($_SESSION['user_id']);
$real = perm_real_role();
$isAdmin = $real === 'ADMIN' && !perm_is_elevated();
$can = function ($page, $op = 'view') use ($pdo, $isAdmin) { return $isAdmin || perm_current_can($pdo, $page, $op); };
if (function_exists('sess_release')) sess_release(); else session_write_close();
ext_ensure($pdo);

$canInst = $can('ext-inst'); $canAti = $can('ext-ati'); $canAdmin = $can('ext-admin'); $canEdit = $can('ext-admin', 'edit');
$need = function ($ok) use ($out) { if (!$ok) $out(['ok' => false, 'error' => 'به این بخش دسترسی ندارید.']); };

try {
    if ($action === 'info') {
        $need($canInst || $canAti || $canAdmin);
        $acc = ext_accounts_of($pdo, $me);
        $out(['ok' => true, 'version' => ext_version(), 'ext_id' => ext_id(), 'changelog' => ext_settings($pdo)['changelog'], 'modules' => EXT_MODULES,
              'can' => ['inst' => $canInst, 'ati' => $canAti, 'admin' => $canAdmin, 'edit' => $canEdit, 'download' => $canInst || $canAti || $canAdmin],
              'mine' => ['inst' => $acc['inst']['username'] ?? '', 'ati' => $acc['ati']['username'] ?? '']]);
    }
    if ($action === 'download') {
        $need($canInst || $canAti || $canAdmin);
        ext_log($pdo, $me, null, null, 'دانلودِ افزونه از پنل', ['version' => ext_version()]);
        ext_send_package();
        exit;
    }

    // ---- مدیریت ----
    if ($action === 'users') {
        $need($canAdmin);
        $users = $pdo->query("SELECT id, full_name, role FROM users WHERE COALESCE(is_deleted, 0) = 0 AND role NOT IN ('PARSIAN') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        $acc = []; foreach ($pdo->query("SELECT * FROM ext_accounts") as $a) $acc[(int)$a['user_id']][$a['module']] = ['username' => $a['username'], 'blocked' => (bool)$a['is_blocked'], 'allowed' => json_decode((string)$a['allowed_json'], true) ?: []];
        $dev = []; foreach ($pdo->query("SELECT user_id, COUNT(*) n, MAX(last_seen) seen, MAX(version) ver, MAX(tunnel) tun FROM ext_devices WHERE revoked = 0 GROUP BY user_id") as $d) $dev[(int)$d['user_id']] = $d;
        $out(['ok' => true, 'users' => array_map(function ($u) use ($acc, $dev) {
            $d = $dev[(int)$u['id']] ?? null;
            return ['id' => (int)$u['id'], 'name' => $u['full_name'], 'role' => $u['role'], 'acc' => $acc[(int)$u['id']] ?? (object)[], 'devices' => $d ? (int)$d['n'] : 0,
                    'seen' => $d && $d['seen'] ? jalali_from_gregorian_ts_dotted(strtotime($d['seen'])) . ' ' . date('H:i', strtotime($d['seen'])) : '', 'online' => $d && $d['seen'] && time() - strtotime($d['seen']) < 180, 'version' => $d['ver'] ?? '', 'tunnel' => $d && (int)$d['tun']];
        }, $users), 'contracts' => array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name']]; }, ext_contracts($pdo)), 'sites' => ext_settings($pdo)['ati']['sites']]);
    }
    // نام‌کاربریِ یک بخش برای یک کاربر (از صفحه‌ی افزونه‌ها یا «دسترسی‌ها»ی همان کاربر)
    if ($action === 'account_get') {
        $need($canAdmin || $can('staff-users', 'edit'));
        $acc = ext_accounts_of($pdo, intval($data['user_id'] ?? 0));
        $out(['ok' => true, 'acc' => array_map(function ($a) { return ['username' => $a['username'], 'blocked' => (bool)$a['is_blocked'], 'allowed' => $a['allowed']]; }, $acc), 'modules' => EXT_MODULES, 'can_edit' => $canEdit || $isAdmin]);
    }
    if ($action === 'account_save') {
        $need($canEdit || $isAdmin);
        $uid = intval($data['user_id'] ?? 0);
        if (!$uid) $out(['ok' => false, 'error' => 'کاربر نامعتبر.']);
        $res = [];
        foreach (EXT_MODULES as $m => $label) {
            if (!isset($data[$m]) || !is_array($data[$m])) continue;
            $x = $data[$m];
            $r = ext_account_save($pdo, $uid, $m, (string)($x['username'] ?? ''), !empty($x['blocked']), isset($x['allowed']) ? (array)$x['allowed'] : null);
            if (empty($r['ok'])) $out(['ok' => false, 'error' => $label . ': ' . ($r['error'] ?? 'ذخیره نشد.')]);
            ext_log($pdo, $uid, null, $m, empty($r['removed']) ? 'نام‌کاربری تعیین شد' : 'نام‌کاربری برداشته شد', ['by' => (string)($_SESSION['full_name'] ?? $me), 'username' => (string)($x['username'] ?? ''), 'blocked' => !empty($x['blocked'])]);
            $res[$m] = $r;
        }
        $out(['ok' => true, 'saved' => $res]);
    }
    if ($action === 'devices') {
        $need($canAdmin);
        $w = ''; $p = [];
        if (!empty($data['user_id'])) { $w = 'WHERE d.user_id = ?'; $p[] = intval($data['user_id']); }
        $st = $pdo->prepare("SELECT d.*, u.full_name FROM ext_devices d LEFT JOIN users u ON u.id = d.user_id $w ORDER BY d.revoked ASC, d.last_seen DESC LIMIT 300");
        $st->execute($p);
        $out(['ok' => true, 'devices' => array_map(function ($d) {
            return ['id' => (int)$d['id'], 'user' => $d['full_name'], 'user_id' => (int)$d['user_id'], 'login' => $d['login_name'], 'version' => $d['version'], 'ip' => $d['ip'], 'bridge' => (bool)$d['bridge_online'], 'tunnel' => (bool)$d['tunnel'],
                    'vpn_ip' => $d['vpn_ip'], 'revoked' => (bool)$d['revoked'], 'online' => !$d['revoked'] && $d['last_seen'] && time() - strtotime($d['last_seen']) < 180,
                    'seen' => $d['last_seen'] ? jalali_from_gregorian_ts_dotted(strtotime($d['last_seen'])) . ' ' . date('H:i', strtotime($d['last_seen'])) : '', 'at' => $d['created_at'] ? jalali_from_gregorian_ts_dotted(strtotime($d['created_at'])) : ''];
        }, $st->fetchAll(PDO::FETCH_ASSOC))]);
    }
    if ($action === 'device_revoke') {
        $need($canEdit);
        $pdo->prepare("UPDATE ext_devices SET revoked = 1 WHERE id = ?")->execute([intval($data['id'] ?? 0)]);
        $out(['ok' => true]);
    }
    // فعالیت‌ها: چه کسی، کی، از کدام دستگاه، در کدام بخش، چه کرد
    if ($action === 'logs' || $action === 'logs_export') {
        $need($canAdmin);
        $w = []; $p = [];
        if (!empty($data['user_id'])) { $w[] = 'l.user_id = ?'; $p[] = intval($data['user_id']); }
        if (isset(EXT_MODULES[$data['module'] ?? ''])) { $w[] = 'l.module = ?'; $p[] = $data['module']; }
        if (($q = trim((string)($data['q'] ?? ''))) !== '') { $w[] = '(l.event LIKE ? OR l.detail LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $j = trim(p2e_digits_ext((string)($data[$k] ?? '')));
            if (preg_match('#^(1[34]\d{2})/(\d{1,2})/(\d{1,2})$#', $j, $m)) { $ts = jalali_to_gregorian_ts((int)$m[1], (int)$m[2], (int)$m[3]); $w[] = "l.created_at $op ?"; $p[] = date('Y-m-d', $ts) . ($op === '>=' ? ' 00:00:00' : ' 23:59:59'); }
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $page = max(0, intval($data['page'] ?? 0)); $lim = $action === 'logs_export' ? 20000 : 100;
        $st = $pdo->prepare("SELECT l.*, u.full_name, d.login_name FROM ext_logs l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN ext_devices d ON d.id = l.device_id $where ORDER BY l.id DESC LIMIT $lim OFFSET " . ($page * $lim));
        $st->execute($p);
        $rows = array_map(function ($l) {
            $det = json_decode((string)$l['detail'], true);
            $txt = is_array($det) ? implode(' · ', array_filter(array_map(function ($k, $v) {
                $fa = ['draftNo' => 'پیش‌نویس', 'contractName' => 'قرارداد', 'paymentType' => 'نوع', 'totalAmount' => 'مبلغ', 'numInstallments' => 'تعدادِ قسط', 'issueDate' => 'صدور', 'title' => 'سامانه', 'url' => 'نشانی', 'ip' => 'IP', 'username' => 'نام‌کاربری', 'version' => 'نسخه', 'by' => 'توسطِ'][$k] ?? $k;
                if (is_array($v) || $v === '' || $v === null || $k === 'contractId' || $k === 'code') return '';
                if ($k === 'totalAmount') $v = number_format((float)$v);
                if (is_bool($v)) $v = $v ? 'بله' : 'خیر';
                return $fa . ': ' . $v;
            }, array_keys($det), $det))) : (string)$l['detail'];
            return ['id' => (int)$l['id'], 'user' => $l['full_name'] ?: '—', 'login' => $l['login_name'], 'module' => $l['module'], 'event' => $l['event'], 'detail' => $txt, 'ip' => $l['ip'], 'version' => $l['version'],
                    'at' => jalali_from_gregorian_ts_dotted(strtotime($l['created_at'])) . ' ' . date('H:i:s', strtotime($l['created_at']))];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        if ($action === 'logs') $out(['ok' => true, 'logs' => $rows, 'more' => count($rows) === $lim]);
        require_once __DIR__ . '/_xlsx_writer.php';
        $path = xlsx_build_book([['name' => 'فعالیت‌های افزونه', 'headers' => ['زمان', 'کاربر', 'نام‌کاربریِ افزونه', 'بخش', 'کار', 'جزئیات', 'IP', 'نسخه'],
                                  'rows' => array_map(function ($r) { return [$r['at'], $r['user'], $r['login'], EXT_MODULES[$r['module']] ?? '', $r['event'], $r['detail'], $r['ip'], $r['version']]; }, $rows)]]);
        header_remove('Content-Type');
        xlsx_send($path, 'فعالیت‌های افزونه ' . str_replace('/', '-', jalali_from_gregorian_ts_dotted(time())) . '.xlsx');
        exit;
    }
    if ($action === 'notifs') {
        $need($canAdmin);
        $out(['ok' => true, 'notifs' => $pdo->query("SELECT n.*, u.full_name FROM ext_notifs n LEFT JOIN users u ON u.id = n.user_id ORDER BY n.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'notif_save') {
        $need($canEdit);
        $t = ext_clean($data['title'] ?? '', 190); if ($t === '') $out(['ok' => false, 'error' => 'عنوان را بنویسید.']);
        $m = in_array($data['module'] ?? '', ['all', 'inst', 'ati'], true) ? $data['module'] : 'all';
        $pdo->prepare("INSERT INTO ext_notifs (module, user_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())")->execute([$m, intval($data['user_id'] ?? 0) ?: null, $t, ext_clean($data['message'] ?? '', 2000)]);
        $out(['ok' => true]);
    }
    if ($action === 'notif_delete') {
        $need($canEdit);
        $pdo->prepare("DELETE FROM ext_notifs WHERE id = ?")->execute([intval($data['id'] ?? 0)]);
        $out(['ok' => true]);
    }
    if ($action === 'settings_get') {
        $need($canAdmin);
        if (!function_exists('fin_contracts_ensure')) require_once __DIR__ . '/finance_core.php';
        $fc = []; try { fin_contracts_ensure($pdo); $fc = $pdo->query("SELECT id, name, contract_no, pay_type, installment_count, is_active FROM fin_contracts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        $out(['ok' => true, 'settings' => ext_settings_public($pdo), 'fin_contracts' => $fc, 'can_edit' => $canEdit]);
    }
    if ($action === 'settings_save') {
        $need($canEdit);
        $r = ext_settings_save($pdo, $data);
        ext_log($pdo, $me, null, null, 'تنظیماتِ افزونه ذخیره شد', ['by' => (string)($_SESSION['full_name'] ?? $me)]);
        $out($r);
    }
    $out(['ok' => false, 'error' => 'درخواست نامعتبر.']);
} catch (Throwable $e) {
    error_log('[ext_actions] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . mb_substr($e->getMessage(), 0, 200)]);
}
