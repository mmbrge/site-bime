<?php
// فایل: api/lt_actions.php
// API «اتوماسیونِ نامه‌ها» در پنل: کارتابل و دفترِ نامه‌ها، نوشتن/ویرایش، صدور با شماره‌ی خودکار، ارسال به کارتابلِ شرکت،
// ارجاع، پیوست، PDF و Word (و بارگذاریِ نسخه‌ی Word/امضاشده)، قالب‌ها، امضاها، دسته‌ها و تنظیماتِ شماره و سربرگ.
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_lt.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_xlsx_writer.php';

$out = function ($a) { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$fail = function ($m) use ($out) { $out(['ok' => false, 'error' => $m]); };
if (empty($_SESSION['user_id']) || session_name() === 'bime_company_portal') { http_response_code(401); $fail('ابتدا وارد پنل شوید.'); }
$uid = intval($_SESSION['user_id']);

function lt_perm_set($pdo) {
    static $p = null;
    if ($p !== null) return $p;
    if (perm_real_role() === 'ADMIN') return $p = 'ALL';
    $c = perm_user_custom($pdo, $_SESSION['user_id']);
    return $p = ($c !== null ? $c : perm_role_defaults(perm_real_role()));
}
function lt_can($pdo, $spec) { $p = lt_perm_set($pdo); return $p === 'ALL' || perm_allows($p, $spec); }

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
$need = [
    'bootstrap' => 'lt-box:view|lt-settings:view', 'list' => 'lt-box:view', 'export' => 'lt-box:export', 'detail' => 'lt-box:view', 'preview_html' => 'lt-box:view',
    'pdf' => 'lt-box:view', 'docx' => 'lt-box:view', 'file' => 'lt-box:view', 'letterhead_img' => 'lt-box:view|lt-settings:view', 'signer_img' => 'lt-box:view|lt-settings:view',
    'draft_preview' => 'lt-box:create|lt-box:edit', 'save' => 'lt-box:create|lt-box:edit', 'issue' => 'lt-box:create', 'send' => 'lt-box:create', 'recall' => 'lt-box:edit',
    'set_status' => 'lt-box:edit|lt-box:create', 'delete' => 'lt-box:delete', 'file_upload' => 'lt-box:create|lt-box:edit', 'file_delete' => 'lt-box:edit|lt-box:delete',
    'word_upload' => 'lt-box:create|lt-box:edit', 'ref_add' => 'lt-box:view', 'ref_done' => 'lt-box:view', 'preview_number' => 'lt-box:view|lt-settings:view',
    'tpl_get' => 'lt-box:view', 'tpl_save' => 'lt-settings:edit|lt-box:create', 'tpl_delete' => 'lt-settings:edit',
    'settings_get' => 'lt-settings:view', 'settings_save' => 'lt-settings:edit', 'categories_save' => 'lt-settings:edit', 'signers_save' => 'lt-settings:edit',
    'signer_image' => 'lt-settings:edit', 'letterhead_upload' => 'lt-settings:edit', 'counter_set' => 'lt-settings:edit',
];
if (!isset($need[$action])) $fail('درخواست نامعتبر است.');
if (!lt_can($pdo, $need[$action])) $fail('شما به این بخش دسترسی ندارید. از مدیر کل بخواهید دسترسی‌تان را تنظیم کند.');
lt_ensure($pdo);
$S = lt_settings($pdo);
$canSecret = lt_can($pdo, 'lt-secret:view');
$canEdit = lt_can($pdo, 'lt-box:edit');

// دیدنِ نامه‌های محرمانه/سرّی: دسترسیِ «نامه‌های محرمانه»، یا نویسنده، امضاکننده یا ارجاع‌گیرنده
function lt_vis_sql($uid, $canSecret, $canEdit) {
    $mine = "(l.created_by = $uid OR l.signer_id IN (SELECT id FROM lt_signers WHERE user_id = $uid) OR EXISTS (SELECT 1 FROM lt_refs r WHERE r.letter_id = l.id AND r.to_user = $uid))";
    $w = $canSecret ? '1' : "(l.conf = 'normal' OR $mine)";
    // پیش‌نویسِ دیگران فقط برای کسی که «ویرایش» دارد
    if (!$canEdit) $w .= " AND (l.status <> 'DRAFT' OR $mine)";
    return $w;
}
function lt_get_visible($pdo, $id, $uid, $canSecret, $canEdit) {
    $st = $pdo->prepare("SELECT l.*, c.name AS company_name FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id WHERE l.id = ? AND l.is_deleted = 0 AND " . lt_vis_sql($uid, $canSecret, $canEdit));
    $st->execute([intval($id)]);
    $L = $st->fetch();
    if (!$L) throw new RuntimeException('نامه پیدا نشد یا به آن دسترسی ندارید.');
    return $L;
}
function lt_send_file($abs, $name, $inline = false) {
    if (!$abs || !is_file($abs)) { http_response_code(404); echo 'فایل پیدا نشد.'; exit; }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
              'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
              'zip' => 'application/zip', 'txt' => 'text/plain; charset=utf-8'];
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"file.$ext\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=60');
    readfile($abs);
    exit;
}
function lt_send_bin($bin, $name, $mime, $inline = false) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"letter\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: private, no-store');
    echo $bin;
    exit;
}
// فیلدهای فرم => ستون‌ها
function lt_fields_from($pdo, array $d, array $S) {
    $dir = in_array($d['direction'] ?? '', ['OUT', 'IN', 'INT'], true) ? $d['direction'] : 'OUT';
    [$lj, $lg] = lt_jdate($d['letter_j'] ?? '');
    if (!$lj) { $lj = lt_today_j(); $lg = date('Y-m-d'); }
    [$rj, $rg] = lt_jdate($d['reply_due_j'] ?? '');
    [$ej] = lt_jdate($d['ext_j'] ?? '');
    $cid = intval($d['company_id'] ?? 0) ?: null;
    if ($cid) { $st = $pdo->prepare("SELECT id FROM companies WHERE id = ?"); $st->execute([$cid]); if (!$st->fetchColumn()) $cid = null; }
    $cat = intval($d['category_id'] ?? 0) ?: null;
    $sg = intval($d['signer_id'] ?? 0) ?: null;
    $t = function ($k, $n) use ($d) { $v = trim((string)($d[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };
    $f = [
        'direction' => $dir, 'category_id' => $cat, 'subject' => mb_substr(trim((string)($d['subject'] ?? '')), 0, 300), 'company_id' => $cid,
        'party_name' => $t('party_name', 190), 'party_person' => $t('party_person', 190), 'party_title' => $t('party_title', 190), 'cc' => $t('cc', 3000),
        'letter_j' => $lj, 'letter_g' => $lg, 'ext_number' => $t('ext_number', 60), 'ext_j' => $ej,
        'priority' => array_key_exists($d['priority'] ?? '', LT_PRIORITY) ? $d['priority'] : 'normal', 'conf' => array_key_exists($d['conf'] ?? '', LT_CONF) ? $d['conf'] : 'normal',
        'letterhead' => !empty($d['letterhead']) ? 1 : 0, 'body_html' => lt_clean_html($d['body_html'] ?? ''), 'attach_note' => $t('attach_note', 190), 'signer_id' => $sg,
        'needs_reply' => !empty($d['needs_reply']) ? 1 : 0, 'reply_due_j' => $rj, 'reply_due_g' => $rg, 'tags' => $t('tags', 300),
        'parent_id' => intval($d['parent_id'] ?? 0) ?: null,
    ];
    if ($f['needs_reply'] && !$f['reply_due_j'] && $S['reply_days'] > 0) {
        $ts = strtotime($lg . ' +' . intval($S['reply_days']) . ' days');
        [$y, $m, $dd] = jalali_from_gregorian_ts($ts);
        $f['reply_due_j'] = sprintf('%04d/%02d/%02d', $y, $m, $dd); $f['reply_due_g'] = date('Y-m-d', $ts);
    }
    return $f;
}
function lt_notify_company($pdo, array $L, array $S) {
    if (!$S['notify_bot'] || !$L['company_id']) return;
    $txt = "📨 نامه‌ی جدید از «بیمه با ما»\n\nشماره: " . lt_fa($L['number']) . "\nتاریخ: " . lt_fa($L['letter_j']) . "\nموضوع: " . $L['subject']
         . ($L['priority'] !== 'normal' ? "\nفوریت: 🔴 " . LT_PRIORITY[$L['priority']] : '') . ($L['needs_reply'] ? "\nنیاز به پاسخ" . ($L['reply_due_j'] ? ' تا ' . lt_fa($L['reply_due_j']) : '') : '')
         . "\n\nبرای دیدنِ نامه و پاسخ، به بخشِ «اتوماسیون نامه‌ها» در پنلِ شرکت بروید.";
    try { cbot_notify_company($pdo, intval($L['company_id']), $txt); } catch (Throwable $e) { error_log('[lt_notify_company] ' . $e->getMessage()); }
}
function lt_do_send($pdo, array $L, array $S) {
    if (!$L['company_id']) throw new RuntimeException('برای ارسال به کارتابلِ شرکت، گیرنده باید یکی از شرکت‌های ثبت‌شده باشد.');
    if (!$S['portal_enabled']) throw new RuntimeException('کارتابلِ شرکت‌ها در تنظیماتِ نامه‌ها خاموش است.');
    $pdo->prepare("UPDATE lt_letters SET to_portal = 1, status = IF(status IN ('ISSUED', 'DRAFT'), 'SENT', status), sent_at = COALESCE(sent_at, NOW()) WHERE id = ?")->execute([$L['id']]);
    lt_log($pdo, $L['id'], 'send', 'ارسال به کارتابلِ شرکت «' . ($L['company_name'] ?? '') . '»');
    $L = lt_letter($pdo, $L['id']);
    lt_notify_company($pdo, $L, $S);
    return $L;
}

try {
switch ($action) {
case 'bootstrap':
    $perms = [];
    foreach (perm_pages() as $k => $v) if (strpos($k, 'lt-') === 0) { $p = lt_perm_set($pdo); $perms[$k] = $p === 'ALL' ? $v[1] : array_values(array_intersect($v[1], (array)($p[$k] ?? []))); }
    $companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
    $staff = $pdo->query("SELECT id, full_name AS name, role FROM users WHERE COALESCE(is_deleted, 0) = 0 ORDER BY full_name")->fetchAll();
    $tpls = $pdo->query("SELECT id, title, category_id, subject FROM lt_templates ORDER BY sort, id")->fetchAll();
    $sv = $S; $sv['has_letterhead'] = (bool)lt_abs($S['letterhead']);
    $out(['ok' => true, 'perms' => $perms, 'uid' => $uid, 'today_j' => lt_today_j(), 'settings' => $sv, 'categories' => lt_categories($pdo), 'signers' => lt_signers($pdo),
          'templates' => array_map(function ($t) { return ['id' => intval($t['id']), 'title' => $t['title'], 'category_id' => $t['category_id'] ? intval($t['category_id']) : null, 'subject' => $t['subject']]; }, $tpls),
          'companies' => array_map(function ($c) { return ['id' => intval($c['id']), 'name' => $c['name']]; }, $companies),
          'staff' => array_map(function ($u) { return ['id' => intval($u['id']), 'name' => $u['name'], 'role' => $u['role']]; }, $staff),
          'dirs' => LT_DIR, 'priorities' => LT_PRIORITY, 'confs' => LT_CONF, 'statuses' => LT_STATUS, 'tokens' => LT_TOKENS, 'can_secret' => $canSecret]);

case 'list':
case 'export':
    $w = ["l.is_deleted = 0", lt_vis_sql($uid, $canSecret, $canEdit)]; $p = [];
    $folder = (string)($data['folder'] ?? 'all');
    if ($folder === 'out') $w[] = "l.direction = 'OUT' AND l.status <> 'DRAFT'";
    elseif ($folder === 'in') $w[] = "l.direction = 'IN'";
    elseif ($folder === 'int') $w[] = "l.direction = 'INT' AND l.status <> 'DRAFT'";
    elseif ($folder === 'draft') $w[] = "l.status = 'DRAFT'";
    elseif ($folder === 'refs') $w[] = "EXISTS (SELECT 1 FROM lt_refs r WHERE r.letter_id = l.id AND r.to_user = $uid AND r.done_at IS NULL)";
    elseif ($folder === 'reply') $w[] = "l.needs_reply = 1 AND l.status NOT IN ('REPLIED', 'ARCHIVED', 'DRAFT')";
    elseif ($folder === 'new_in') $w[] = "l.direction = 'IN' AND l.status = 'NEW'";
    elseif ($folder === 'urgent') $w[] = "l.priority <> 'normal' AND l.status NOT IN ('ARCHIVED')";
    elseif ($folder === 'archived') $w[] = "l.status = 'ARCHIVED'";
    elseif ($folder === 'mine') $w[] = "l.created_by = $uid";
    if ($folder !== 'archived' && $folder !== 'all' && $folder !== 'mine') $w[] = "l.status <> 'ARCHIVED'";
    $q = trim(p2e_digits((string)($data['q'] ?? '')));
    if ($q !== '') { $w[] = "(l.number LIKE ? OR l.subject LIKE ? OR l.party_name LIKE ? OR l.party_person LIKE ? OR c.name LIKE ? OR l.ext_number LIKE ? OR l.body_html LIKE ? OR l.tags LIKE ?)"; $lk = '%' . $q . '%'; array_push($p, $lk, $lk, $lk, $lk, $lk, $lk, $lk, $lk); }
    foreach (['category_id', 'company_id'] as $k) if (intval($data[$k] ?? 0)) $w[] = "l.$k = " . intval($data[$k]);
    if (!empty($data['priority']) && isset(LT_PRIORITY[$data['priority']])) { $w[] = "l.priority = ?"; $p[] = $data['priority']; }
    if (!empty($data['conf']) && isset(LT_CONF[$data['conf']])) { $w[] = "l.conf = ?"; $p[] = $data['conf']; }
    if (!empty($data['status']) && isset(LT_STATUS[$data['status']])) { $w[] = "l.status = ?"; $p[] = $data['status']; }
    if (!empty($data['direction']) && isset(LT_DIR[$data['direction']])) { $w[] = "l.direction = ?"; $p[] = $data['direction']; }
    [, $fg] = lt_jdate($data['from_j'] ?? ''); [, $tg] = lt_jdate($data['to_j'] ?? '');
    if ($fg) { $w[] = "l.letter_g >= ?"; $p[] = $fg; }
    if ($tg) { $w[] = "l.letter_g <= ?"; $p[] = $tg; }
    $where = implode(' AND ', $w);
    $sql = "SELECT l.*, c.name AS company_name, u.full_name AS created_by_name, pu.full_name AS portal_user_name,
                   (SELECT COUNT(*) FROM lt_files f WHERE f.letter_id = l.id) AS files_n, (SELECT COUNT(*) FROM lt_letters x WHERE x.parent_id = l.id AND x.is_deleted = 0) AS replies_n
              FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id LEFT JOIN users u ON u.id = l.created_by LEFT JOIN company_portal_users pu ON pu.id = l.portal_user_id
             WHERE $where ORDER BY COALESCE(l.issued_at, l.created_at) DESC, l.id DESC";
    if ($action === 'export') {
        $st = $pdo->prepare($sql); $st->execute($p);
        $cats = []; foreach (lt_categories($pdo) as $c) $cats[$c['id']] = $c['name'];
        $rows = array_map(function ($r) use ($cats, $pdo) {
            return [$r['number'] ?: '—', $r['letter_j'], LT_DIR[$r['direction']] ?? '', $cats[$r['category_id']] ?? '', $r['subject'], lt_party_line($r), $r['party_person'], $r['ext_number'], $r['ext_j'],
                    LT_PRIORITY[$r['priority']] ?? '', LT_CONF[$r['conf']] ?? '', LT_STATUS[$r['status']] ?? '', $r['attach_note'], $r['needs_reply'] ? ('بله' . ($r['reply_due_j'] ? ' تا ' . $r['reply_due_j'] : '')) : 'خیر',
                    $r['portal_user_name'] ? ($r['portal_user_name'] . ' (کاربرِ شرکت)') : $r['created_by_name'], lt_dt_j($r['created_at']), lt_dt_j($r['issued_at']), $r['issued_by'] ? lt_user_name($pdo, $r['issued_by']) : '', lt_dt_j($r['sent_at']), lt_dt_j($r['seen_at'])];
        }, $st->fetchAll());
        $path = xlsx_build(['شماره', 'تاریخ', 'نوع', 'دسته', 'موضوع', 'شرکت / طرف', 'شخص', 'شماره‌ی نامه‌ی طرف', 'تاریخِ نامه‌ی طرف', 'فوریت', 'محرمانگی', 'وضعیت', 'پیوست', 'نیاز به پاسخ',
                            'ثبت‌کننده', 'زمانِ ثبت', 'زمانِ صدور', 'صادرکننده', 'ارسال به شرکت', 'دیده‌شده توسط شرکت'], $rows, 'دفتر نامه‌ها');
        xlsx_send($path, 'دفتر اندیکاتور نامه‌ها - ' . lt_today_j() . '.xlsx');
        exit;
    }
    $page = max(1, intval($data['page'] ?? 1)); $per = 50;
    $st = $pdo->prepare("SELECT COUNT(*) FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id WHERE $where"); $st->execute($p); $total = intval($st->fetchColumn());
    $st = $pdo->prepare($sql . " LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($p);
    $rows = array_map(function ($r) use ($pdo) { return lt_out($r, $pdo); }, $st->fetchAll());
    // شمارنده‌های پوشه‌ها و کارت‌ها
    $vis = lt_vis_sql($uid, $canSecret, $canEdit);
    [$jy, $jm] = jalali_from_gregorian_ts(time());
    $ms = date('Y-m-d', jalali_to_gregorian_ts($jy, $jm, 1));
    $cnt = $pdo->query("SELECT
            SUM(l.status = 'DRAFT') draft, SUM(l.direction = 'IN' AND l.status = 'NEW') new_in,
            SUM(l.needs_reply = 1 AND l.status NOT IN ('REPLIED', 'ARCHIVED', 'DRAFT')) reply,
            SUM(l.needs_reply = 1 AND l.status NOT IN ('REPLIED', 'ARCHIVED', 'DRAFT') AND l.reply_due_g < CURDATE()) overdue,
            SUM(l.priority <> 'normal' AND l.status NOT IN ('ARCHIVED')) urgent,
            SUM(l.direction = 'OUT' AND l.status <> 'DRAFT' AND l.letter_g >= '$ms') out_month, SUM(l.direction = 'IN' AND l.letter_g >= '$ms') in_month,
            SUM(l.status = 'ARCHIVED') archived
            FROM lt_letters l WHERE l.is_deleted = 0 AND $vis")->fetch();
    $refs = intval($pdo->query("SELECT COUNT(DISTINCT r.letter_id) FROM lt_refs r JOIN lt_letters l ON l.id = r.letter_id WHERE l.is_deleted = 0 AND r.to_user = $uid AND r.done_at IS NULL")->fetchColumn());
    $counts = array_map('intval', (array)$cnt) + ['refs' => $refs];
    $counts['refs'] = $refs;
    $out(['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $counts]);

case 'detail':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    $o = lt_out($L, $pdo);
    $o['body_html'] = $L['body_html'];
    if ($L['portal_user_id']) $o['portal_user'] = lt_portal_name($pdo, $L['portal_user_id']);
    $files = $pdo->prepare("SELECT f.*, u.full_name uname, pu.full_name pname FROM lt_files f LEFT JOIN users u ON u.id = f.uploaded_by LEFT JOIN company_portal_users pu ON pu.id = f.uploaded_by_portal WHERE f.letter_id = ? ORDER BY f.id");
    $files->execute([$L['id']]);
    $o['files'] = array_map(function ($f) { return ['id' => intval($f['id']), 'kind' => $f['kind'], 'name' => $f['orig_name'], 'size' => intval($f['size']), 'by' => $f['uname'] ?: ($f['pname'] ? $f['pname'] . ' (شرکت)' : ''), 'at' => lt_dt_j($f['created_at'])]; }, $files->fetchAll());
    $log = $pdo->prepare("SELECT * FROM lt_log WHERE letter_id = ? ORDER BY id DESC LIMIT 100");
    $log->execute([$L['id']]);
    $o['log'] = array_map(function ($r) { return ['action' => $r['action'], 'detail' => $r['detail'], 'by' => $r['actor_name'] . ($r['actor_type'] === 'PORTAL' ? ' (شرکت)' : ''), 'at' => lt_dt_j($r['created_at']), 'ip' => $r['ip']]; }, $log->fetchAll());
    $refs = $pdo->prepare("SELECT r.*, a.full_name fname, b.full_name tname FROM lt_refs r LEFT JOIN users a ON a.id = r.from_user LEFT JOIN users b ON b.id = r.to_user WHERE r.letter_id = ? ORDER BY r.id");
    $refs->execute([$L['id']]);
    $o['refs'] = array_map(function ($r) use ($uid) { return ['id' => intval($r['id']), 'from' => $r['fname'], 'to' => $r['tname'], 'to_id' => intval($r['to_user']), 'note' => $r['note'], 'due_j' => $r['due_j'],
        'at' => lt_dt_j($r['created_at']), 'seen' => lt_dt_j($r['seen_at']), 'done' => lt_dt_j($r['done_at']), 'done_note' => $r['done_note'], 'mine' => intval($r['to_user']) === $uid]; }, $refs->fetchAll());
    $pdo->prepare("UPDATE lt_refs SET seen_at = NOW() WHERE letter_id = ? AND to_user = ? AND seen_at IS NULL")->execute([$L['id'], $uid]);
    // رشته‌ی نامه: والد و پاسخ‌ها
    $thread = [];
    $rootId = $L['parent_id'] ?: $L['id'];
    $st = $pdo->prepare("SELECT l.*, c.name AS company_name, pu.full_name AS portal_user_name FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id LEFT JOIN company_portal_users pu ON pu.id = l.portal_user_id
                          WHERE l.is_deleted = 0 AND (l.id = ? OR l.parent_id = ?) AND " . lt_vis_sql($uid, $canSecret, $canEdit) . " ORDER BY COALESCE(l.issued_at, l.created_at), l.id");
    $st->execute([$rootId, $rootId]);
    foreach ($st->fetchAll() as $r) { $x = lt_out($r, $pdo); $x['plain'] = mb_substr(lt_plain($r['body_html']), 0, 400); $thread[] = $x; }
    $o['thread'] = $thread;
    $out(['ok' => true, 'letter' => $o]);

case 'preview_html':
case 'draft_preview':
    if ($action === 'draft_preview') {
        $L = lt_fields_from($pdo, $data, $S) + ['id' => 0, 'number' => null, 'status' => 'DRAFT', 'company_name' => null];
        if (intval($data['id'] ?? 0)) { $old = lt_get_visible($pdo, $data['id'], $uid, $canSecret, $canEdit); $L['id'] = intval($old['id']); $L['number'] = $old['number']; $L['status'] = $old['status']; }
        if ($L['company_id']) { $st = $pdo->prepare("SELECT name FROM companies WHERE id = ?"); $st->execute([$L['company_id']]); $L['company_name'] = $st->fetchColumn(); }
    }
    else $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    if (($data['format'] ?? '') === 'pdf') { lt_send_bin(lt_pdf($pdo, $L, 'S'), 'پیش‌نمایش.pdf', 'application/pdf', true); }
    $out(['ok' => true, 'html' => lt_compose_html($pdo, $L, 'web'), 'number' => $L['number'], 'letter_j' => $L['letter_j']]);

case 'pdf':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    lt_log($pdo, $L['id'], 'pdf', 'دریافتِ PDF');
    lt_send_bin(lt_pdf($pdo, $L, 'S'), lt_safe_name(($L['number'] ?: 'پیش‌نویس') . ' - ' . $L['subject']) . '.pdf', 'application/pdf', !empty($data['inline']));

case 'docx':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    // اگر نسخه‌ی نهاییِ Word بارگذاری شده، همان (مگر «fresh» بخواهند)
    if ($L['word_path'] && empty($data['fresh']) && ($abs = lt_abs($L['word_path']))) lt_send_file($abs, lt_safe_name(($L['number'] ?: 'پیش‌نویس') . ' - ' . $L['subject']) . '.docx');
    lt_log($pdo, $L['id'], 'docx', 'دریافتِ فایلِ Word برای ویرایش');
    lt_send_bin(lt_docx($pdo, $L), lt_safe_name(($L['number'] ?: 'پیش‌نویس') . ' - ' . $L['subject']) . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

case 'file':
    $st = $pdo->prepare("SELECT * FROM lt_files WHERE id = ?");
    $st->execute([intval($data['id'] ?? 0)]);
    $f = $st->fetch();
    if (!$f) throw new RuntimeException('فایل پیدا نشد.');
    lt_get_visible($pdo, $f['letter_id'], $uid, $canSecret, $canEdit);
    lt_send_file(lt_abs($f['path']), $f['orig_name'], !empty($data['inline']));

case 'letterhead_img':
    lt_send_file(lt_abs($S['letterhead']), 'letterhead.' . pathinfo((string)$S['letterhead'], PATHINFO_EXTENSION), true);

case 'signer_img':
    $st = $pdo->prepare("SELECT image_path FROM lt_signers WHERE id = ?");
    $st->execute([intval($data['id'] ?? 0)]);
    lt_send_file(lt_abs($st->fetchColumn()), 'sign.png', true);

case 'save':
    $id = intval($data['id'] ?? 0);
    $f = lt_fields_from($pdo, $data, $S);
    if ($f['subject'] === '') throw new RuntimeException('موضوعِ نامه را بنویسید.');
    if ($f['direction'] !== 'IN' && !$f['company_id'] && !$f['party_name'] && !$f['party_person']) throw new RuntimeException('گیرنده را مشخص کنید (شرکت یا نام).');
    if ($f['direction'] === 'IN' && !$f['company_id'] && !$f['party_name']) throw new RuntimeException('فرستنده‌ی نامه‌ی وارده را مشخص کنید.');
    if ($id) {
        $L = lt_get_visible($pdo, $id, $uid, $canSecret, $canEdit);
        if ($L['status'] !== 'DRAFT' && !$canEdit) throw new RuntimeException('ویرایشِ نامه‌ی صادرشده دسترسیِ «ویرایش» می‌خواهد.');
        if ($L['status'] === 'DRAFT' && intval($L['created_by']) !== $uid && !$canEdit) throw new RuntimeException('فقط پیش‌نویس‌های خودتان را می‌توانید ویرایش کنید.');
        if ($L['number']) unset($f['direction']);   // نوعِ نامه‌ی شماره‌خورده عوض نمی‌شود
        $set = []; $vals = [];
        foreach ($f as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
        // شماره‌ی دستی (فقط با دسترسیِ ویرایش و فقط برای نامه‌ی شماره‌خورده)
        if ($canEdit && $L['number'] && isset($data['number']) && trim(p2e_digits((string)$data['number'])) !== '' && trim(p2e_digits((string)$data['number'])) !== $L['number']) {
            $nn = mb_substr(trim(p2e_digits((string)$data['number'])), 0, 60);
            $st = $pdo->prepare("SELECT COUNT(*) FROM lt_letters WHERE number = ? AND id <> ?"); $st->execute([$nn, $id]);
            if (intval($st->fetchColumn())) throw new RuntimeException('این شماره قبلاً برای نامه‌ی دیگری ثبت شده است.');
            $set[] = 'number = ?'; $vals[] = $nn;
            lt_log($pdo, $id, 'renumber', 'تغییرِ شماره از ' . $L['number'] . ' به ' . $nn);
        }
        $set[] = 'updated_by = ?'; $vals[] = $uid; $set[] = 'updated_at = NOW()';
        $vals[] = $id;
        $pdo->prepare("UPDATE lt_letters SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
        lt_log($pdo, $id, 'edit', $L['status'] === 'DRAFT' ? 'ذخیره‌ی پیش‌نویس' : 'ویرایشِ نامه پس از صدور');
    } else {
        if (!lt_can($pdo, 'lt-box:create')) throw new RuntimeException('برای نوشتنِ نامه دسترسیِ «ایجاد» لازم است.');
        $f['status'] = $f['direction'] === 'IN' ? 'NEW' : 'DRAFT';
        $f['created_by'] = $uid;
        $cols = array_keys($f);
        $pdo->prepare("INSERT INTO lt_letters (" . implode(', ', $cols) . ", created_at) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", NOW())")->execute(array_values($f));
        $id = intval($pdo->lastInsertId());
        lt_log($pdo, $id, 'create', $f['direction'] === 'IN' ? 'ثبتِ نامه‌ی وارده' : 'ایجادِ پیش‌نویس');
        // نامه‌ی وارده همان لحظه شماره‌ی وارده می‌گیرد
        if ($f['direction'] === 'IN') {
            [$num, $seq] = lt_next_number($pdo, 'IN', $f['category_id'], $f['letter_g']);
            $pdo->prepare("UPDATE lt_letters SET number = ?, seq = ?, issued_by = ?, issued_at = NOW() WHERE id = ?")->execute([$num, $seq, $uid, $id]);
            lt_log($pdo, $id, 'issue', 'شماره‌ی وارده: ' . $num);
            if ($f['parent_id']) { $pdo->prepare("UPDATE lt_letters SET status = 'REPLIED' WHERE id = ? AND status IN ('ISSUED', 'SENT', 'SEEN')")->execute([$f['parent_id']]); lt_log($pdo, $f['parent_id'], 'reply', 'پاسخ ثبت شد: ' . $num); }
        }
    }
    foreach (lt_files_from('files') as $file) {
        $L = lt_letter($pdo, $id);
        $abs = lt_store_upload($file, lt_letter_dir($L), 'پیوست');
        $pdo->prepare("INSERT INTO lt_files (letter_id, kind, orig_name, path, size, uploaded_by, created_at) VALUES (?, 'ATTACH', ?, ?, ?, ?, NOW())")->execute([$id, $file['name'], lt_rel($abs), filesize($abs), $uid]);
    }
    $L = lt_letter($pdo, $id);
    // «صدور» و «ارسال» در همان درخواست
    if (!empty($data['then']) && in_array($data['then'], ['issue', 'send'], true) && $L['status'] === 'DRAFT') {
        if (!lt_can($pdo, 'lt-box:create')) throw new RuntimeException('صدورِ نامه دسترسیِ «ایجاد» می‌خواهد.');
        [$num, $seq] = lt_next_number($pdo, $L['direction'], $L['category_id'], $L['letter_g']);
        $pdo->prepare("UPDATE lt_letters SET number = ?, seq = ?, status = 'ISSUED', issued_by = ?, issued_at = NOW() WHERE id = ?")->execute([$num, $seq, $uid, $id]);
        lt_log($pdo, $id, 'issue', 'صدور با شماره‌ی ' . $num);
        $L = lt_letter($pdo, $id);
    }
    if (($data['then'] ?? '') === 'send' && $L['status'] !== 'DRAFT') $L = lt_do_send($pdo, $L, $S);
    $out(['ok' => true, 'id' => $id, 'letter' => lt_out($L, $pdo)]);

case 'issue':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    if ($L['status'] !== 'DRAFT') throw new RuntimeException('این نامه قبلاً صادر شده است.');
    [$num, $seq] = lt_next_number($pdo, $L['direction'], $L['category_id'], $L['letter_g']);
    $pdo->prepare("UPDATE lt_letters SET number = ?, seq = ?, status = 'ISSUED', issued_by = ?, issued_at = NOW() WHERE id = ?")->execute([$num, $seq, $uid, $L['id']]);
    lt_log($pdo, $L['id'], 'issue', 'صدور با شماره‌ی ' . $num);
    $L = lt_letter($pdo, $L['id']);
    if (!empty($data['send'])) $L = lt_do_send($pdo, $L, $S);
    $out(['ok' => true, 'letter' => lt_out($L, $pdo)]);

case 'send':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    if ($L['status'] === 'DRAFT') throw new RuntimeException('اول نامه را صادر کنید تا شماره بگیرد.');
    if ($L['direction'] !== 'OUT') throw new RuntimeException('فقط نامه‌ی صادره به کارتابلِ شرکت فرستاده می‌شود.');
    $L = lt_do_send($pdo, $L, $S);
    $out(['ok' => true, 'letter' => lt_out($L, $pdo)]);

case 'recall':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    $pdo->prepare("UPDATE lt_letters SET to_portal = 0, status = IF(status IN ('SENT', 'SEEN'), 'ISSUED', status) WHERE id = ?")->execute([$L['id']]);
    lt_log($pdo, $L['id'], 'recall', 'برداشتن از کارتابلِ شرکت');
    $out(['ok' => true]);

case 'set_status':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    $to = (string)($data['status'] ?? '');
    $okTo = $L['direction'] === 'IN' ? ['NEW', 'REVIEWED', 'ARCHIVED'] : ['ISSUED', 'SENT', 'SEEN', 'REPLIED', 'ARCHIVED'];
    if ($L['status'] === 'DRAFT' || !in_array($to, $okTo, true)) throw new RuntimeException('این تغییرِ وضعیت مجاز نیست.');
    $pdo->prepare("UPDATE lt_letters SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([$to, $uid, $L['id']]);
    lt_log($pdo, $L['id'], 'status', 'وضعیت: ' . (LT_STATUS[$L['status']] ?? $L['status']) . ' ← ' . LT_STATUS[$to]);
    $out(['ok' => true]);

case 'delete':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    $pdo->prepare("UPDATE lt_letters SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE id = ?")->execute([$uid, $L['id']]);
    lt_log($pdo, $L['id'], 'delete', 'حذفِ نامه');
    if (function_exists('sec_event')) sec_event($pdo, 'ADMIN_ACTION', 'حذفِ نامه‌ی ' . ($L['number'] ?: '#' . $L['id']) . ' - ' . $L['subject']);
    $out(['ok' => true]);

case 'file_upload':
case 'word_upload':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    $kind = $action === 'word_upload' ? 'WORD' : (in_array($data['kind'] ?? '', ['SIGNED'], true) ? 'SIGNED' : 'ATTACH');
    $files = lt_files_from('files') ?: lt_files_from('file');
    if (!$files) throw new RuntimeException('فایلی انتخاب نشده است.');
    $saved = 0; $synced = false;
    foreach ($files as $file) {
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($kind === 'WORD' && $ext !== 'docx') throw new RuntimeException('نسخه‌ی Word باید با پسوندِ docx ذخیره شده باشد.');
        if ($kind === 'SIGNED' && !in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) throw new RuntimeException('نسخه‌ی امضاشده باید PDF یا تصویر باشد.');
        $abs = lt_store_upload($file, lt_letter_dir($L), $kind === 'WORD' ? 'نسخه‌ی ورد' : ($kind === 'SIGNED' ? 'نسخه‌ی امضاشده' : 'پیوست'));
        $pdo->prepare("INSERT INTO lt_files (letter_id, kind, orig_name, path, size, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")->execute([$L['id'], $kind, $file['name'], lt_rel($abs), filesize($abs), $uid]);
        if ($kind === 'WORD') {
            $pdo->prepare("UPDATE lt_letters SET word_path = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([lt_rel($abs), $uid, $L['id']]);
            if (!empty($data['sync'])) {
                $GLOBALS['LT_BASE_PT'] = $S['font_size'];
                $html = lt_docx_to_html($abs);
                if ($html !== null && $html !== '') { $pdo->prepare("UPDATE lt_letters SET body_html = ? WHERE id = ?")->execute([$html, $L['id']]); $synced = true; }
            }
            lt_log($pdo, $L['id'], 'word', 'بارگذاریِ نسخه‌ی Word' . ($synced ? ' و به‌روزرسانیِ متنِ نامه از روی آن' : ''));
        } elseif ($kind === 'SIGNED') {
            $pdo->prepare("UPDATE lt_letters SET signed_path = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([lt_rel($abs), $uid, $L['id']]);
            lt_log($pdo, $L['id'], 'signed', 'بارگذاریِ نسخه‌ی امضاشده/اسکن');
        } else lt_log($pdo, $L['id'], 'attach', 'افزودنِ پیوست: ' . $file['name']);
        $saved++;
    }
    $out(['ok' => true, 'saved' => $saved, 'synced' => $synced]);

case 'file_delete':
    $st = $pdo->prepare("SELECT * FROM lt_files WHERE id = ?");
    $st->execute([intval($data['id'] ?? 0)]);
    $f = $st->fetch();
    if (!$f) throw new RuntimeException('فایل پیدا نشد.');
    $L = lt_get_visible($pdo, $f['letter_id'], $uid, $canSecret, $canEdit);
    if ($f['uploaded_by_portal']) throw new RuntimeException('فایلِ ارسالیِ شرکت حذف نمی‌شود.');
    $pdo->prepare("DELETE FROM lt_files WHERE id = ?")->execute([$f['id']]);
    if ($abs = lt_abs($f['path'])) @unlink($abs);
    if ($f['kind'] === 'WORD' && $L['word_path'] === $f['path']) $pdo->prepare("UPDATE lt_letters SET word_path = NULL WHERE id = ?")->execute([$L['id']]);
    if ($f['kind'] === 'SIGNED' && $L['signed_path'] === $f['path']) $pdo->prepare("UPDATE lt_letters SET signed_path = NULL WHERE id = ?")->execute([$L['id']]);
    lt_log($pdo, $L['id'], 'file_delete', 'حذفِ فایل: ' . $f['orig_name']);
    $out(['ok' => true]);

case 'ref_add':
    $L = lt_get_visible($pdo, $data['id'] ?? 0, $uid, $canSecret, $canEdit);
    $to = array_values(array_unique(array_filter(array_map('intval', (array)($data['to'] ?? [])))));
    if (!$to) throw new RuntimeException('حداقل یک همکار را برای ارجاع انتخاب کنید.');
    [$dj] = lt_jdate($data['due_j'] ?? '');
    $note = mb_substr(trim((string)($data['note'] ?? '')), 0, 1000);
    $ins = $pdo->prepare("INSERT INTO lt_refs (letter_id, from_user, to_user, note, due_j, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $names = [];
    foreach ($to as $t) { $ins->execute([$L['id'], $uid, $t, $note ?: null, $dj]); $names[] = lt_user_name($pdo, $t); }
    lt_log($pdo, $L['id'], 'ref', 'ارجاع به ' . implode('، ', $names) . ($note ? ' - ' . $note : ''));
    // اعلان در ربات (اگر همکار به ربات متصل است)
    try {
        $st = $pdo->prepare("SELECT bale_chat_id FROM users WHERE id = ? AND bale_chat_id IS NOT NULL AND bot_linked_at IS NOT NULL");
        foreach ($to as $t) { $st->execute([$t]); if ($c = $st->fetchColumn()) cbot_send($pdo, $c, "📨 نامه‌ای به شما ارجاع شد\n\nاز: " . lt_user_name($pdo, $uid) . "\nموضوع: " . $L['subject'] . ($L['number'] ? "\nشماره: " . lt_fa($L['number']) : '') . ($note ? "\nدستور: " . $note : '') . ($dj ? "\nمهلت: " . lt_fa($dj) : '')); }
    } catch (Throwable $e) {}
    $out(['ok' => true]);

case 'ref_done':
    $st = $pdo->prepare("SELECT * FROM lt_refs WHERE id = ?");
    $st->execute([intval($data['ref_id'] ?? 0)]);
    $r = $st->fetch();
    if (!$r || (intval($r['to_user']) !== $uid && !$canEdit)) throw new RuntimeException('این ارجاع مالِ شما نیست.');
    $note = mb_substr(trim((string)($data['note'] ?? '')), 0, 1000);
    $pdo->prepare("UPDATE lt_refs SET done_at = NOW(), done_note = ? WHERE id = ?")->execute([$note ?: null, $r['id']]);
    lt_log($pdo, $r['letter_id'], 'ref_done', 'اقدامِ ارجاع انجام شد' . ($note ? ': ' . $note : ''));
    $out(['ok' => true]);

case 'preview_number':
    $dir = in_array($data['direction'] ?? '', ['OUT', 'IN', 'INT'], true) ? $data['direction'] : 'OUT';
    $tmp = $S;
    foreach (['pattern_out', 'pattern_in', 'pattern_int', 'fix', 'seq_digits', 'seq_start', 'seq_scope'] as $k) if (isset($data[$k]) && $data[$k] !== '') $tmp[$k] = $k === 'seq_digits' || $k === 'seq_start' ? max(1, intval(p2e_digits((string)$data[$k]))) : (string)$data[$k];
    $out(['ok' => true] + lt_preview_number($pdo, $dir, intval($data['category_id'] ?? 0), $tmp));

case 'tpl_get':
    $st = $pdo->prepare("SELECT * FROM lt_templates WHERE id = ?");
    $st->execute([intval($data['id'] ?? 0)]);
    $t = $st->fetch();
    if (!$t) throw new RuntimeException('قالب پیدا نشد.');
    $out(['ok' => true, 'template' => ['id' => intval($t['id']), 'title' => $t['title'], 'category_id' => $t['category_id'] ? intval($t['category_id']) : null, 'subject' => $t['subject'], 'body_html' => $t['body_html']]]);

case 'tpl_save':
    $id = intval($data['id'] ?? 0);
    $title = mb_substr(trim((string)($data['title'] ?? '')), 0, 190);
    if ($title === '') throw new RuntimeException('نامِ قالب را بنویسید.');
    $vals = [$title, intval($data['category_id'] ?? 0) ?: null, mb_substr(trim((string)($data['subject'] ?? '')), 0, 300), lt_clean_html($data['body_html'] ?? '')];
    if ($id) {
        if (!lt_can($pdo, 'lt-settings:edit')) throw new RuntimeException('ویرایشِ قالب‌ها دسترسیِ تنظیمات می‌خواهد.');
        $pdo->prepare("UPDATE lt_templates SET title = ?, category_id = ?, subject = ?, body_html = ? WHERE id = ?")->execute(array_merge($vals, [$id]));
    } else { $pdo->prepare("INSERT INTO lt_templates (title, category_id, subject, body_html, sort, created_by, created_at) VALUES (?, ?, ?, ?, 99, ?, NOW())")->execute(array_merge($vals, [$uid])); $id = intval($pdo->lastInsertId()); }
    $out(['ok' => true, 'id' => $id]);

case 'tpl_delete':
    $pdo->prepare("DELETE FROM lt_templates WHERE id = ?")->execute([intval($data['id'] ?? 0)]);
    $out(['ok' => true]);

case 'settings_get':
    $counters = $pdo->query("SELECT k, v FROM lt_counters ORDER BY k")->fetchAll();
    $sv = $S; $sv['has_letterhead'] = (bool)lt_abs($S['letterhead']);
    $tpls = $pdo->query("SELECT * FROM lt_templates ORDER BY sort, id")->fetchAll();
    $out(['ok' => true, 'settings' => $sv, 'categories' => lt_categories($pdo), 'signers' => lt_signers($pdo), 'templates' => $tpls,
          'counters' => array_map(function ($c) { return ['k' => $c['k'], 'v' => intval($c['v'])]; }, $counters),
          'staff' => array_map(function ($u) { return ['id' => intval($u['id']), 'name' => $u['full_name']]; }, $pdo->query("SELECT id, full_name FROM users WHERE COALESCE(is_deleted, 0) = 0 ORDER BY full_name")->fetchAll()),
          'preview' => ['OUT' => lt_preview_number($pdo, 'OUT', 0), 'IN' => lt_preview_number($pdo, 'IN', 0), 'INT' => lt_preview_number($pdo, 'INT', 0)]]);

case 'settings_save':
    $in = (array)($data['settings'] ?? []);
    unset($in['letterhead']);
    $s = lt_settings_save($pdo, $in);
    $out(['ok' => true, 'settings' => $s]);

case 'counter_set':
    $k = mb_substr(trim((string)($data['k'] ?? '')), 0, 80);
    if (!preg_match('/^(OUT|IN|INT)(:[\d\-:]+)?$/', $k)) throw new RuntimeException('کلیدِ شمارنده نامعتبر است.');
    $v = max(0, intval(p2e_digits((string)($data['v'] ?? 0))));
    $pdo->prepare("INSERT INTO lt_counters (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]);
    if (function_exists('sec_event')) sec_event($pdo, 'ADMIN_ACTION', "شمارنده‌ی نامه‌ها «$k» = $v");
    $out(['ok' => true]);

case 'categories_save':
    $keep = [];
    foreach ((array)($data['categories'] ?? []) as $i => $c) {
        $name = mb_substr(trim((string)($c['name'] ?? '')), 0, 120);
        if ($name === '') continue;
        $code = mb_substr(trim(p2e_digits((string)($c['code'] ?? '0'))), 0, 10) ?: '0';
        $vals = [$name, $code, preg_match('/^#[0-9a-f]{6}$/i', $c['color'] ?? '') ? $c['color'] : '#475569', mb_substr((string)($c['icon'] ?? 'fa-envelope'), 0, 40), $i + 1, !empty($c['active']) ? 1 : 0, !empty($c['portal']) ? 1 : 0];
        if (intval($c['id'] ?? 0)) { $pdo->prepare("UPDATE lt_categories SET name = ?, code = ?, color = ?, icon = ?, sort = ?, active = ?, portal = ? WHERE id = ?")->execute(array_merge($vals, [intval($c['id'])])); $keep[] = intval($c['id']); }
        else { $pdo->prepare("INSERT INTO lt_categories (name, code, color, icon, sort, active, portal) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute($vals); $keep[] = intval($pdo->lastInsertId()); }
    }
    // دسته‌ای که نامه دارد پاک نمی‌شود، فقط غیرفعال
    foreach (lt_categories($pdo) as $c) if (!in_array($c['id'], $keep, true)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM lt_letters WHERE category_id = ?"); $st->execute([$c['id']]);
        if (intval($st->fetchColumn())) $pdo->prepare("UPDATE lt_categories SET active = 0 WHERE id = ?")->execute([$c['id']]);
        else $pdo->prepare("DELETE FROM lt_categories WHERE id = ?")->execute([$c['id']]);
    }
    $out(['ok' => true, 'categories' => lt_categories($pdo)]);

case 'signers_save':
    $keep = [];
    foreach ((array)($data['signers'] ?? []) as $i => $g) {
        $name = mb_substr(trim((string)($g['name'] ?? '')), 0, 190);
        if ($name === '') continue;
        $vals = [intval($g['user_id'] ?? 0) ?: null, $name, mb_substr(trim((string)($g['title'] ?? '')), 0, 190) ?: null, $i + 1, !empty($g['active']) ? 1 : 0];
        if (intval($g['id'] ?? 0)) { $pdo->prepare("UPDATE lt_signers SET user_id = ?, name = ?, title = ?, sort = ?, active = ? WHERE id = ?")->execute(array_merge($vals, [intval($g['id'])])); $keep[] = intval($g['id']); }
        else { $pdo->prepare("INSERT INTO lt_signers (user_id, name, title, sort, active) VALUES (?, ?, ?, ?, ?)")->execute($vals); $keep[] = intval($pdo->lastInsertId()); }
    }
    foreach (lt_signers($pdo) as $g) if (!in_array($g['id'], $keep, true)) $pdo->prepare("UPDATE lt_signers SET active = 0 WHERE id = ?")->execute([$g['id']]);
    $out(['ok' => true, 'signers' => lt_signers($pdo)]);

case 'signer_image':
case 'letterhead_upload':
    $isLh = $action === 'letterhead_upload';
    $sid = intval($data['id'] ?? 0);
    if (!$isLh) { $st = $pdo->prepare("SELECT * FROM lt_signers WHERE id = ?"); $st->execute([$sid]); if (!$st->fetch()) throw new RuntimeException('امضاکننده پیدا نشد (اول ذخیره کنید).'); }
    if (!empty($data['remove'])) {
        if ($isLh) { if ($a = lt_abs($S['letterhead'])) @unlink($a); lt_settings_save($pdo, ['letterhead' => '']); }
        else { $pdo->prepare("UPDATE lt_signers SET image_path = NULL WHERE id = ?")->execute([$sid]); }
        $out(['ok' => true]);
    }
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('تصویر دریافت نشد.');
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) throw new RuntimeException('فقط تصویرِ PNG یا JPG.');
    if ($f['size'] > 8 * 1024 * 1024) throw new RuntimeException('حجمِ تصویر بیشتر از ۸ مگابایت است.');
    $dir = lt_root() . '/_تنظیمات';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $abs = $dir . '/' . ($isLh ? 'سربرگ-' . date('YmdHis') : 'امضا-' . $sid . '-' . date('YmdHis')) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $abs)) throw new RuntimeException('ذخیره‌ی تصویر ناموفق بود.');
    if ($isLh) { if ($old = lt_abs($S['letterhead'])) @unlink($old); lt_settings_save($pdo, ['letterhead' => lt_rel($abs)]); }
    else $pdo->prepare("UPDATE lt_signers SET image_path = ? WHERE id = ?")->execute([lt_rel($abs), $sid]);
    $out(['ok' => true, 'ratio' => round($info[1] / max(1, $info[0]), 3)]);
}
} catch (PDOException $e) {
    error_log('[lt_actions] ' . $e->getMessage());
    $fail('خطای پایگاهِ داده. دوباره تلاش کنید.');
} catch (RuntimeException $e) {
    $fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[lt_actions] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $fail('خطای داخلی در بخشِ نامه‌ها. دوباره تلاش کنید.');
}
