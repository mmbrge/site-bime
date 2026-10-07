<?php
// فایل: api/lt_portal_actions.php
// «اتوماسیون نامه‌ها» در پنلِ شرکت: نامه‌هایی که برای شرکت(های) این کاربر فرستاده شده را می‌بیند (PDF، پیوست، نسخه‌ی امضاشده)
// و فقط می‌تواند پاسخ بدهد: متن + فایل + دسته‌بندی. پاسخ در سامانه‌ی ما یک «نامه‌ی وارده» با شماره‌ی وارده می‌شود.
require '../config/db.php';
require_once __DIR__ . '/_case_helpers.php';
require_once __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require_once __DIR__ . '/_lt.php';

$session = require_company_portal_session($pdo);
$cids = array_map('intval', $session['company_ids']);
$puid = intval($session['company_user_id']);
$out = function ($a) { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$fail = function ($m) use ($out) { $out(['ok' => false, 'error' => $m]); };
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');

lt_ensure($pdo);
$S = lt_settings($pdo);
if (!$S['portal_enabled']) $fail('بخشِ اتوماسیونِ نامه‌ها فعلاً فعال نیست.');
if (!$cids) $fail('حسابِ شما به هیچ شرکتی وصل نیست.');
$ph = implode(',', array_fill(0, count($cids), '?'));

// نامه‌ای که برای یکی از شرکت‌های همین کاربر فرستاده شده (یا پاسخی که خودِ شرکت داده)
function ltp_letter($pdo, $id, array $cids, $ph) {
    $st = $pdo->prepare("SELECT l.*, c.name AS company_name FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id
                          WHERE l.id = ? AND l.is_deleted = 0 AND l.company_id IN ($ph) AND ((l.direction = 'OUT' AND l.to_portal = 1) OR (l.direction = 'IN' AND l.portal_user_id IS NOT NULL))");
    $st->execute(array_merge([intval($id)], $cids));
    $L = $st->fetch();
    if (!$L) throw new RuntimeException('نامه پیدا نشد.');
    return $L;
}
function ltp_send($abs, $name, $inline) {
    if (!$abs || !is_file($abs)) { http_response_code(404); echo 'فایل پیدا نشد.'; exit; }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
              'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip' => 'application/zip'];
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"file.$ext\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}
function ltp_out(array $r) {
    return ['id' => intval($r['id']), 'direction' => $r['direction'], 'number' => $r['number'], 'subject' => $r['subject'], 'letter_j' => $r['letter_j'], 'company_name' => $r['company_name'],
            'category_id' => $r['category_id'] ? intval($r['category_id']) : null, 'priority' => $r['priority'], 'priority_fa' => LT_PRIORITY[$r['priority']] ?? '', 'conf' => $r['conf'],
            'conf_fa' => LT_CONF[$r['conf']] ?? '', 'status' => $r['status'],
            'status_fa' => $r['direction'] === 'IN' ? ($r['status'] === 'NEW' ? 'رسیده به بیمه با ما' : 'بررسی شد') : ($r['status'] === 'SENT' ? 'جدید' : ($r['status'] === 'SEEN' ? 'دیده شد' : ($r['status'] === 'REPLIED' ? 'پاسخ داده شد' : (LT_STATUS[$r['status']] ?? '')))),
            'unread' => $r['direction'] === 'OUT' && !$r['seen_at'], 'needs_reply' => intval($r['needs_reply']), 'reply_due_j' => $r['reply_due_j'],
            'overdue' => intval($r['needs_reply']) && $r['reply_due_g'] && $r['reply_due_g'] < date('Y-m-d') && !in_array($r['status'], ['REPLIED', 'ARCHIVED'], true),
            'sent_at_j' => lt_dt_j($r['sent_at'] ?: $r['created_at']), 'seen_at_j' => lt_dt_j($r['seen_at']), 'parent_id' => $r['parent_id'] ? intval($r['parent_id']) : null,
            'attach_note' => $r['attach_note'], 'replies_n' => isset($r['replies_n']) ? intval($r['replies_n']) : 0, 'files_n' => isset($r['files_n']) ? intval($r['files_n']) : 0, 'has_signed' => !empty($r['signed_path'])];
}

try {
switch ($action) {
case 'bootstrap':
    $cats = array_values(array_filter(lt_categories($pdo, true), function ($c) { return $c['portal']; }));
    $st = $pdo->prepare("SELECT COUNT(*) FROM lt_letters WHERE is_deleted = 0 AND direction = 'OUT' AND to_portal = 1 AND seen_at IS NULL AND company_id IN ($ph)");
    $st->execute($cids);
    $out(['ok' => true, 'categories' => array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'icon' => $c['icon']]; }, $cats),
          'all_categories' => array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'icon' => $c['icon']]; }, lt_categories($pdo)),
          'unread' => intval($st->fetchColumn()), 'companies' => $session['companies']]);

case 'unread':
    $st = $pdo->prepare("SELECT COUNT(*) FROM lt_letters WHERE is_deleted = 0 AND direction = 'OUT' AND to_portal = 1 AND seen_at IS NULL AND company_id IN ($ph)");
    $st->execute($cids);
    $out(['ok' => true, 'unread' => intval($st->fetchColumn())]);

case 'list':
    $w = ["l.is_deleted = 0", "l.company_id IN ($ph)", "l.direction = 'OUT'", "l.to_portal = 1"]; $p = $cids;
    $f = (string)($data['filter'] ?? 'all');
    if ($f === 'new') $w[] = "l.seen_at IS NULL";
    elseif ($f === 'reply') $w[] = "l.needs_reply = 1 AND l.status NOT IN ('REPLIED', 'ARCHIVED')";
    elseif ($f === 'replied') $w[] = "l.status = 'REPLIED'";
    elseif ($f === 'urgent') $w[] = "l.priority <> 'normal'";
    if (intval($data['company_id'] ?? 0) && in_array(intval($data['company_id']), $cids, true)) $w[] = "l.company_id = " . intval($data['company_id']);
    if (intval($data['category_id'] ?? 0)) $w[] = "l.category_id = " . intval($data['category_id']);
    $q = trim(p2e_digits((string)($data['q'] ?? '')));
    if ($q !== '') { $w[] = "(l.number LIKE ? OR l.subject LIKE ?)"; $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%'; }
    $st = $pdo->prepare("SELECT l.*, c.name AS company_name, (SELECT COUNT(*) FROM lt_letters x WHERE x.parent_id = l.id AND x.is_deleted = 0 AND x.portal_user_id IS NOT NULL) replies_n,
                                (SELECT COUNT(*) FROM lt_files f WHERE f.letter_id = l.id AND f.kind IN ('ATTACH', 'SIGNED')) files_n
                           FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id WHERE " . implode(' AND ', $w) . " ORDER BY l.sent_at DESC, l.id DESC LIMIT 300");
    $st->execute($p);
    $rows = array_map('ltp_out', $st->fetchAll());
    $st = $pdo->prepare("SELECT SUM(seen_at IS NULL) n_new, SUM(needs_reply = 1 AND status NOT IN ('REPLIED', 'ARCHIVED')) n_reply, SUM(status = 'REPLIED') n_replied, SUM(priority <> 'normal') n_urgent, COUNT(*) n_all
                           FROM lt_letters WHERE is_deleted = 0 AND direction = 'OUT' AND to_portal = 1 AND company_id IN ($ph)");
    $st->execute($cids);
    $out(['ok' => true, 'rows' => $rows, 'counts' => array_map('intval', (array)$st->fetch())]);

case 'detail':
    $L = ltp_letter($pdo, $data['id'] ?? 0, $cids, $ph);
    if ($L['direction'] === 'OUT' && !$L['seen_at']) {
        $pdo->prepare("UPDATE lt_letters SET seen_at = NOW(), seen_by_portal = ?, status = IF(status = 'SENT', 'SEEN', status) WHERE id = ?")->execute([$puid, $L['id']]);
        lt_log($pdo, $L['id'], 'seen', 'شرکت نامه را دید', 'PORTAL', $puid);
        $L = ltp_letter($pdo, $L['id'], $cids, $ph);
    }
    $o = ltp_out($L);
    $o['html'] = lt_compose_html($pdo, $L, false);
    $o['html'] = preg_replace('/<img[^>]*>/i', '', $o['html']);   // تصویرِ امضا فقط در PDF
    $st = $pdo->prepare("SELECT id, kind, orig_name, size, created_at, uploaded_by_portal FROM lt_files WHERE letter_id = ? AND kind IN ('ATTACH', 'SIGNED', 'REPLY') ORDER BY id");
    $st->execute([$L['id']]);
    $o['files'] = array_map(function ($f) { return ['id' => intval($f['id']), 'kind' => $f['kind'], 'name' => $f['orig_name'], 'size' => intval($f['size']), 'mine' => !empty($f['uploaded_by_portal']), 'at' => lt_dt_j($f['created_at'])]; }, $st->fetchAll());
    // پاسخ‌های شرکت به همین نامه
    $root = $L['direction'] === 'OUT' ? $L['id'] : $L['parent_id'];
    $st = $pdo->prepare("SELECT l.*, c.name AS company_name, pu.full_name AS pname FROM lt_letters l LEFT JOIN companies c ON c.id = l.company_id LEFT JOIN company_portal_users pu ON pu.id = l.portal_user_id
                          WHERE l.parent_id = ? AND l.is_deleted = 0 AND l.company_id IN ($ph) AND ((l.direction = 'IN' AND l.portal_user_id IS NOT NULL) OR (l.direction = 'OUT' AND l.to_portal = 1)) ORDER BY l.id");
    $st->execute(array_merge([$root], $cids));
    $replies = [];
    $fst = $pdo->prepare("SELECT id, orig_name, size FROM lt_files WHERE letter_id = ? ORDER BY id");
    foreach ($st->fetchAll() as $r) {
        $x = ltp_out($r); $x['by'] = $r['pname'] ?: 'بیمه با ما'; $x['text'] = lt_plain($r['body_html']); $x['created_at_j'] = lt_dt_j($r['created_at']);
        $fst->execute([$r['id']]);
        $x['files'] = array_map(function ($f) { return ['id' => intval($f['id']), 'name' => $f['orig_name'], 'size' => intval($f['size'])]; }, $fst->fetchAll());
        $replies[] = $x;
    }
    $o['replies'] = $replies;
    $out(['ok' => true, 'letter' => $o]);

case 'pdf':
    $L = ltp_letter($pdo, $data['id'] ?? 0, $cids, $ph);
    if ($L['direction'] !== 'OUT') throw new RuntimeException('PDF فقط برای نامه‌های بیمه با ما است.');
    lt_log($pdo, $L['id'], 'pdf', 'دریافتِ PDF توسطِ شرکت', 'PORTAL', $puid);
    $bin = lt_pdf($pdo, $L, 'S');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($data['inline']) ? 'inline' : 'attachment') . "; filename=\"letter.pdf\"; filename*=UTF-8''" . rawurlencode(lt_safe_name('نامه ' . $L['number'] . ' - ' . $L['subject']) . '.pdf'));
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;

case 'signed':
    $L = ltp_letter($pdo, $data['id'] ?? 0, $cids, $ph);
    ltp_send(lt_abs($L['signed_path']), lt_safe_name('نامه ' . $L['number'] . ' - نسخه‌ی امضاشده') . '.' . pathinfo((string)$L['signed_path'], PATHINFO_EXTENSION), !empty($data['inline']));

case 'file':
    $st = $pdo->prepare("SELECT * FROM lt_files WHERE id = ?");
    $st->execute([intval($data['id'] ?? 0)]);
    $f = $st->fetch();
    if (!$f || !in_array($f['kind'], ['ATTACH', 'SIGNED', 'REPLY'], true)) throw new RuntimeException('فایل پیدا نشد.');
    ltp_letter($pdo, $f['letter_id'], $cids, $ph);
    ltp_send(lt_abs($f['path']), $f['orig_name'], !empty($data['inline']));

case 'reply':
    $P = ltp_letter($pdo, $data['parent_id'] ?? 0, $cids, $ph);
    if ($P['direction'] !== 'OUT') throw new RuntimeException('فقط به نامه‌های بیمه با ما می‌توانید پاسخ بدهید.');
    $text = trim((string)($data['text'] ?? ''));
    $files = lt_files_from('files');
    if ($text === '' && !$files) throw new RuntimeException('متنِ پاسخ را بنویسید یا فایلِ نامه را بارگذاری کنید.');
    if (mb_strlen($text) > 20000) throw new RuntimeException('متنِ پاسخ خیلی طولانی است.');
    if (count($files) > 10) throw new RuntimeException('حداکثر ۱۰ فایل.');
    $cat = intval($data['category_id'] ?? 0) ?: intval($P['category_id']) ?: null;
    if ($cat) { $c = lt_category($pdo, $cat); if (!$c || !$c['active']) $cat = intval($P['category_id']) ?: null; }
    $subject = mb_substr(trim((string)($data['subject'] ?? '')), 0, 300) ?: ('پاسخ به نامه‌ی ' . lt_fa($P['number']) . ' - ' . $P['subject']);
    $body = $text === '' ? '' : implode('', array_map(function ($l) { return '<p>' . ($l === '' ? '<br>' : htmlspecialchars($l, ENT_QUOTES, 'UTF-8')) . '</p>'; }, preg_split('/\r?\n/', $text)));
    [$extJ] = lt_jdate($data['ext_j'] ?? '');
    $pdo->prepare("INSERT INTO lt_letters (direction, category_id, subject, company_id, party_person, letter_j, letter_g, ext_number, ext_j, priority, conf, letterhead, body_html, status, parent_id, portal_user_id, created_at)
                   VALUES ('IN', ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, 'normal', 0, ?, 'NEW', ?, ?, NOW())")
        ->execute([$cat, $subject, $P['company_id'], mb_substr($session['full_name'] ?: lt_portal_name($pdo, $puid), 0, 190), lt_today_j(), mb_substr(trim(p2e_digits((string)($data['ext_number'] ?? ''))), 0, 60) ?: null, $extJ,
                   ($data['priority'] ?? '') === 'urgent' ? 'urgent' : 'normal', $body, $P['id'], $puid]);
    $id = intval($pdo->lastInsertId());
    [$num, $seq] = lt_next_number($pdo, 'IN', $cat, date('Y-m-d'));
    $pdo->prepare("UPDATE lt_letters SET number = ?, seq = ?, issued_at = NOW() WHERE id = ?")->execute([$num, $seq, $id]);
    $R = lt_letter($pdo, $id);
    foreach ($files as $file) {
        $abs = lt_store_upload($file, lt_letter_dir($R), 'پاسخِ شرکت');
        $pdo->prepare("INSERT INTO lt_files (letter_id, kind, orig_name, path, size, uploaded_by_portal, created_at) VALUES (?, 'REPLY', ?, ?, ?, ?, NOW())")->execute([$id, $file['name'], lt_rel($abs), filesize($abs), $puid]);
    }
    lt_log($pdo, $id, 'create', 'پاسخِ شرکت از پنلِ شرکت (شماره‌ی وارده ' . $num . ')', 'PORTAL', $puid);
    $pdo->prepare("UPDATE lt_letters SET status = IF(status IN ('SENT', 'SEEN', 'ISSUED'), 'REPLIED', status) WHERE id = ?")->execute([$P['id']]);
    lt_log($pdo, $P['id'], 'reply', 'شرکت پاسخ داد - شماره‌ی وارده ' . $num, 'PORTAL', $puid);
    // اعلان به مدیرانِ متصل به ربات
    try {
        cbot_notify_staff($pdo, ['ADMIN'], "📩 پاسخِ شرکت به نامه\n\nشرکت: " . ($P['company_name'] ?? '') . "\nدر پاسخ به: " . lt_fa($P['number']) . ' - ' . $P['subject'] . "\nشماره‌ی وارده: " . lt_fa($num)
            . ($text !== '' ? "\n\n" . mb_substr($text, 0, 300) : '') . (count($files) ? "\n📎 " . lt_fa(count($files)) . ' فایل' : ''));
    } catch (Throwable $e) {}
    $out(['ok' => true, 'id' => $id, 'number' => $num]);
}
$fail('درخواست نامعتبر است.');
} catch (PDOException $e) {
    error_log('[lt_portal_actions] ' . $e->getMessage());
    $fail('خطای پایگاهِ داده. دوباره تلاش کنید.');
} catch (RuntimeException $e) {
    $fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[lt_portal_actions] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $fail('خطای داخلی. دوباره تلاش کنید.');
}
