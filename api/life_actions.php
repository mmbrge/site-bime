<?php
// فایل: api/life_actions.php
// API بخشِ «بیمه عمر»: داشبورد، بیمه‌نامه‌ها و اقساط، پرداخت و رسید، تماس و یادداشت، ورودِ اکسل، خروجی، بایگانی و تنظیمات.
// دسترسی: مدیر کل همه‌چیز؛ بقیه طبقِ دسترسیِ سفارشی (اگر دارند) یا پیش‌فرضِ نقش («کاربر بیمه عمر»).
session_start();
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require_once __DIR__ . '/_life.php';
require_once __DIR__ . '/finance_core.php';
require_once __DIR__ . '/_xlsx_writer.php';
require_once __DIR__ . '/_life_bot.php';

$out = function ($a) { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$fail = function ($msg) use ($out) { $out(['ok' => false, 'error' => $msg]); };
if (empty($_SESSION['user_id']) || session_name() === 'bime_company_portal') { http_response_code(401); $fail('ابتدا وارد پنل شوید.'); }
$uid = intval($_SESSION['user_id']);

// دسترسی‌های بیمه‌ی عمرِ همین کاربر
function life_perm_set($pdo) {
    static $p = null;
    if ($p !== null) return $p;
    $role = perm_real_role();
    if ($role === 'ADMIN') return $p = 'ALL';
    $c = perm_user_custom($pdo, $_SESSION['user_id']);
    return $p = ($c !== null ? $c : perm_role_defaults($role));
}
function life_can($pdo, $spec) {
    $p = life_perm_set($pdo);
    return $p === 'ALL' || perm_allows($p, $spec);
}
function life_my_perms($pdo) {
    $o = [];
    foreach (perm_pages() as $k => $v) if (strpos($k, 'life-') === 0) {
        $p = life_perm_set($pdo);
        $o[$k] = $p === 'ALL' ? $v[1] : array_values(array_intersect($v[1], (array)($p[$k] ?? [])));
    }
    return $o;
}

$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
if (($data['follower'] ?? '') === 'me') $data['follower'] = $uid;   // فیلترِ «پیگیری‌های من»

// اکشن => دسترسیِ لازم
$need = [
    'bootstrap' => 'life-dash:view|life-policies:view|life-import:view|life-archive:view|life-settings:view',
    'stats' => 'life-dash:view', 'list' => 'life-policies:view', 'detail' => 'life-policies:view', 'export_columns' => 'life-policies:view',
    'policy_save' => 'life-policies:edit', 'policy_delete' => 'life-policies:delete', 'export' => 'life-policies:export',
    'purge_candidates' => 'life-policies:delete', 'purge' => 'life-policies:delete',
    'phones_save' => 'life-calls:create|life-policies:edit', 'follow_toggle' => 'life-calls:create|life-pay:create|life-policies:edit',
    'followers_save' => 'life-policies:edit', 'staff_options' => 'life-policies:view',
    'note_add' => 'life-calls:create', 'note_delete' => 'life-calls:delete',
    'inst_save' => 'life-pay:edit', 'inst_add' => 'life-pay:create', 'inst_delete' => 'life-policies:delete',
    'pay_add' => 'life-pay:create', 'pay_void' => 'life-pay:delete', 'inst_file' => 'life-pay:view|life-archive:view',
    'inst_file_upload' => 'life-pay:create', 'inst_file_delete' => 'life-pay:delete',
    'receipt_docx' => 'life-pay:view', 'receipt_html' => 'life-pay:view', 'receipt_pdf' => 'life-pay:view',
    'templates' => 'life-pay:view|life-settings:view', 'tpl_upload' => 'life-settings:edit', 'tpl_save' => 'life-settings:edit',
    'tpl_delete' => 'life-settings:edit', 'tpl_sample' => 'life-settings:view', 'tpl_file' => 'life-settings:view',
    'colmap_get' => 'life-settings:view|life-import:view', 'colmap_save' => 'life-settings:edit', 'colmap_headers' => 'life-settings:edit',
    'import_preview' => 'life-import:create', 'import_commit' => 'life-import:create', 'imports_list' => 'life-import:view',
    'bot_get' => 'life-settings:view', 'bot_save_token' => 'life-settings:edit', 'bot_save_settings' => 'life-settings:edit', 'bot_run' => 'life-settings:edit',
    'bot_remind' => 'life-calls:create',
    'archive_list' => 'life-archive:view', 'archive_file' => 'life-archive:view', 'archive_zip' => 'life-archive:export',
];
if (!isset($need[$action])) $fail('درخواست نامعتبر است.');
if (!life_can($pdo, $need[$action])) {
    $first = explode('|', $need[$action])[0];
    [$pg, $op] = explode(':', $first);
    $fail('شما به این عملیات دسترسی ندارید («' . (perm_pages()[$pg][0] ?? $pg) . '» - ' . (PERM_OPS[$op] ?? $op) . '). از مدیر کل بخواهید دسترسی‌تان را تنظیم کند.');
}
life_ensure($pdo);
lbot_ensure($pdo);
if ($action === 'bootstrap') lbot_lazy_reminders($pdo);   // یادآوری‌های ربات اگر کرون تنظیم نشده باشد

// فایلِ آپلودی => [tmp, نام, پسوند] یا خطا
function life_upload($key, array $exts, $maxMb = 20) {
    if (empty($_FILES[$key]) || !is_uploaded_file($_FILES[$key]['tmp_name'] ?? '')) return ['error' => 'فایلی انتخاب نشده است.'];
    $f = $_FILES[$key];
    if ($f['error'] !== UPLOAD_ERR_OK) return ['error' => 'بارگذاری ناقص ماند؛ دوباره امتحان کنید.'];
    if ($f['size'] > $maxMb * 1048576) return ['error' => 'حجمِ فایل بیش از ' . life_fa($maxMb) . ' مگابایت است.'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) return ['error' => 'نوعِ فایل مجاز نیست (' . implode('، ', $exts) . ').'];
    return ['tmp' => $f['tmp_name'], 'name' => $f['name'], 'ext' => $ext];
}
const LIFE_DOC_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'txt'];
function life_send_file($abs, $name = null, $inline = false) {
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'txt' => 'text/plain; charset=utf-8',
              'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip' => 'application/zip'];
    $name = $name ?: basename($abs);
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'txt'], true) ? 'inline' : 'attachment') . "; filename=\"file.$ext\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}
// مسیرِ امن داخلِ بایگانیِ بیمه عمر
function life_safe_path($rel) {
    $root = realpath(life_archive_root());
    if (!$root) return null;
    $rel = trim(str_replace('\\', '/', (string)$rel), '/');
    if (preg_match('#(^|/)\.\.(/|$)#', $rel)) return null;
    $abs = realpath($root . ($rel !== '' ? '/' . $rel : ''));
    if (!$abs || ($abs !== $root && strpos($abs, $root . '/') !== 0)) return null;
    return $abs;
}
function life_inst_row($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM life_installments WHERE id = ?");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function life_inst_dir_of($pdo, $inst) { return life_inst_dir(life_policy_row($pdo, $inst['policy_id']), $inst); }
function life_unique_file($dir, $base, $ext) {
    $base = sanitize_folder_name($base);
    $p = $dir . '/' . $base . '.' . $ext; $k = 2;
    while (file_exists($p)) $p = $dir . '/' . $base . ' (' . $k++ . ').' . $ext;
    return $p;
}

switch ($action) {
// ---------------------------------------------------------------------
case 'bootstrap':
    $out(['ok' => true, 'perms' => life_my_perms($pdo), 'is_admin' => perm_real_role() === 'ADMIN', 'me' => $uid, 'today_j' => life_today_j(), 'pdf_ok' => fin_pdf_converter_available(), 'pay_methods' => LIFE_PAY_METHODS,
          'call_results' => LIFE_CALL_RESULTS, 'status_fa' => LIFE_STATUS_FA, 'templates' => life_can($pdo, 'life-pay:view|life-settings:view') ? life_templates($pdo) : [],
          'export_columns' => array_map(function ($v) { return ['label' => $v[0], 'level' => $v[1]]; }, life_export_columns()),
          'lists' => ['pay_methods' => $pdo->query("SELECT DISTINCT pay_method FROM life_policies WHERE pay_method IS NOT NULL AND pay_method <> '' ORDER BY pay_method")->fetchAll(PDO::FETCH_COLUMN),
                      'policy_status' => $pdo->query("SELECT DISTINCT policy_status FROM life_policies WHERE policy_status IS NOT NULL AND policy_status <> '' ORDER BY policy_status")->fetchAll(PDO::FETCH_COLUMN)]]);

case 'stats':
    $out(['ok' => true] + life_stats($pdo, $data));

case 'list':
    $out(['ok' => true] + life_list($pdo, $data, $data['page'] ?? 1, $data['per'] ?? 30));

case 'detail':
    $d = life_detail($pdo, intval($data['id'] ?? 0));
    if (!$d) $fail('بیمه‌نامه پیدا نشد.');
    // بیمه‌گذار به ربات وصل است؟ (کد ملی + یکی از شماره‌های همین بیمه‌نامه)
    $d['policy']['bot_linked'] = 0;
    if ($d['policy']['holder_nid']) {
        $st = $pdo->prepare("SELECT phone FROM life_bot_links WHERE kind = 'CUSTOMER' AND nid = ?");
        $st->execute([$d['policy']['holder_nid']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ph) if (in_array($ph, array_column($d['policy']['phones_list'], 'phone'), true)) $d['policy']['bot_linked']++;
    }
    $d['policy']['bot_ready'] = lbot_token($pdo) !== '';
    $out(['ok' => true] + $d);

case 'export_columns':
    $out(['ok' => true, 'columns' => life_export_columns()]);

case 'export':
    $mode = ($data['mode'] ?? '') === 'inst' ? 'inst' : 'policy';
    $cols = is_array($data['cols'] ?? null) ? $data['cols'] : array_filter(explode(',', (string)($data['cols'] ?? '')));
    $f = $data;
    if (!empty($data['ids']) && !is_array($data['ids'])) $f['ids'] = explode(',', (string)$data['ids']);
    [$headers, $rows, $numeric] = life_export($pdo, $f, $mode, $cols);
    $path = xlsx_build($headers, $rows, $mode === 'inst' ? 'اقساط بیمه عمر' : 'بیمه‌نامه‌های عمر', $numeric);
    xlsx_send($path, ($mode === 'inst' ? 'اقساط بیمه عمر' : 'بیمه‌نامه‌های عمر') . ' - ' . str_replace('/', '-', life_today_j()) . '.xlsx');
    exit;

// ---------------------------------------------------------------------
case 'policy_save':
    $id = intval($data['id'] ?? 0);
    $p = life_policy_row($pdo, $id);
    if (!$p) $fail('بیمه‌نامه پیدا نشد.');
    $fields = ['holder_name' => 'life_clean', 'holder_nid' => 'life_nid', 'insured_name' => 'life_clean', 'insured_nid' => 'life_nid', 'pay_method' => 'life_clean',
               'policy_status' => 'life_clean', 'note' => 'trim', 'variant' => 'life_clean', 'field_name' => 'life_clean'];
    $set = []; $args = []; $changed = [];
    foreach ($fields as $k => $fn) {
        if (!array_key_exists($k, $data)) continue;
        $v = $fn((string)$data[$k]);
        if ($v === (string)$p[$k]) continue;
        $set[] = "$k = ?"; $args[] = $v === '' ? null : $v; $changed[] = LIFE_POLICY_FIELDS[$k] ?? ($k === 'note' ? 'یادداشت' : $k);
    }
    if (array_key_exists('issue_j', $data)) {
        [$j, $g] = life_jdate($data['issue_j']);
        if ($j && $j !== $p['issue_j']) { $set[] = 'issue_j = ?'; $args[] = $j; $set[] = 'issue_g = ?'; $args[] = $g; $changed[] = 'تاریخ صدور'; }
    }
    if ($set) {
        $oldDir = life_policy_dir($p);
        $args[] = $id;
        $pdo->prepare("UPDATE life_policies SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ?")->execute($args);
        life_sys_note($pdo, $id, 'ویرایشِ اطلاعات: ' . implode('، ', $changed), $uid);
        // اگر نام/کد ملی/تاریخِ صدور عوض شد پوشه‌ی بایگانی هم جابه‌جا می‌شود
        $newDir = life_policy_dir(life_policy_row($pdo, $id));
        if ($newDir !== $oldDir && is_dir($oldDir) && !file_exists($newDir)) { life_mkdir(dirname($newDir)); @rename($oldDir, $newDir); }
        life_write_archive($pdo, $id);
    }
    $out(['ok' => true]);

case 'phones_save':
    $id = intval($data['id'] ?? 0);
    $p = life_policy_row($pdo, $id);
    if (!$p) $fail('بیمه‌نامه پیدا نشد.');
    $main = life_mobile($data['holder_mobile'] ?? $p['holder_mobile']);
    if ($main !== '' && !preg_match('/^0\d{9,10}$/', $main)) $fail('شماره‌ی موبایل درست نیست (مثل ۰۹۱۲۱۲۳۴۵۶۷).');
    $lines = [];
    foreach ((array)($data['phones'] ?? []) as $ph) {
        $num = life_mobile(is_array($ph) ? ($ph['phone'] ?? '') : $ph);
        $label = trim(str_replace(['|', ',', '،', "\n"], ' ', is_array($ph) ? (string)($ph['label'] ?? '') : ''));
        if ($num === '' || $num === $main) continue;
        if (!preg_match('/^0\d{6,11}$/', $num)) $fail('شماره‌ی «' . life_fa($num) . '» درست نیست.');
        $lines[] = $num . ($label !== '' ? '|' . mb_substr($label, 0, 40) : '');
    }
    $phones = implode("\n", array_unique($lines));
    $pdo->prepare("UPDATE life_policies SET holder_mobile = ?, phones = ?, updated_at = NOW() WHERE id = ?")->execute([$main ?: null, $phones ?: null, $id]);
    if ($main !== (string)$p['holder_mobile'] || $phones !== (string)$p['phones']) life_sys_note($pdo, $id, 'شماره‌های تماس به‌روز شد' . ($main ? ' (موبایل: ' . $main . ')' : '') . '.', $uid);
    life_write_archive($pdo, $id);
    $out(['ok' => true, 'phones' => life_phone_list(life_policy_row($pdo, $id))]);

case 'policy_delete':
    $id = intval($data['id'] ?? 0);
    if (!life_delete_policy($pdo, $id, !empty($data['with_archive']) || !isset($data['with_archive']))) $fail('بیمه‌نامه پیدا نشد.');
    $out(['ok' => true]);

case 'purge_candidates':
    $out(['ok' => true, 'rows' => life_purge_candidates($pdo)]);

case 'purge':
    $ids = array_filter(array_map('intval', (array)($data['ids'] ?? [])));
    if (!$ids) $fail('هیچ بیمه‌نامه‌ای انتخاب نشده است.');
    $allowed = array_column(life_purge_candidates($pdo), 'id');
    $n = 0;
    foreach ($ids as $id) if (in_array($id, $allowed, true) && life_delete_policy($pdo, $id, true)) $n++;
    $out(['ok' => true, 'deleted' => $n, 'skipped' => count($ids) - $n]);

// ---------------------------------------------------------------------
case 'note_add':
    $id = intval($data['policy_id'] ?? 0);
    $p = life_policy_row($pdo, $id);
    if (!$p) $fail('بیمه‌نامه پیدا نشد.');
    $kind = ($data['kind'] ?? 'call') === 'note' ? 'note' : 'call';
    $text = trim((string)($data['text'] ?? ''));
    $result = trim((string)($data['result'] ?? ''));
    if ($kind === 'call' && $result === '' && $text === '') $fail('نتیجه‌ی تماس یا توضیح را بنویسید.');
    if ($kind === 'note' && $text === '') $fail('متنِ یادداشت خالی است.');
    // تاریخ و ساعتِ تماس (پیش‌فرض: همین حالا)
    $at = date('Y-m-d H:i:s');
    if (!empty($data['date_j'])) {
        [$j, $g] = life_jdate($data['date_j']);
        if (!$j) $fail('تاریخِ تماس درست نیست (۱۴۰۵/۰۷/۱۴).');
        $tm = preg_match('/^(\d{1,2}):(\d{2})$/', p2e_digits(trim((string)($data['time'] ?? ''))), $m) ? sprintf('%02d:%02d:00', min(23, $m[1]), min(59, $m[2])) : date('H:i:s');
        $at = $g . ' ' . $tm;
    }
    $nextJ = null; $nextG = null;
    if (!empty($data['next_j'])) { [$nextJ, $nextG] = life_jdate($data['next_j']); if (!$nextJ) $fail('تاریخِ پیگیریِ بعدی درست نیست.'); }
    $phone = life_mobile($data['phone'] ?? '') ?: null;
    $instId = intval($data['installment_id'] ?? 0) ?: null;
    if ($instId) { $i = life_inst_row($pdo, $instId); if (!$i || intval($i['policy_id']) !== $id) $instId = null; }
    $pdo->prepare("INSERT INTO life_notes (policy_id, installment_id, kind, at, phone, result, next_j, next_g, text, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$id, $instId, $kind, $at, $phone, $result ?: null, $nextJ, $nextG, $text ?: null, $uid]);
    // شماره‌ای که با آن تماس گرفته شد، اگر هنوز ثبت نشده، به شماره‌های بیمه‌نامه اضافه می‌شود
    if ($phone && !empty($data['save_phone'])) {
        $list = array_column(life_phone_list($p), 'phone');
        if (!in_array($phone, $list, true)) {
            if (!$p['holder_mobile']) $pdo->prepare("UPDATE life_policies SET holder_mobile = ? WHERE id = ?")->execute([$phone, $id]);
            else $pdo->prepare("UPDATE life_policies SET phones = TRIM(BOTH '\n' FROM CONCAT(COALESCE(phones, ''), '\n', ?)) WHERE id = ?")->execute([$phone, $id]);
        }
    }
    $pdo->prepare("UPDATE life_policies SET updated_at = NOW() WHERE id = ?")->execute([$id]);
    life_follow($pdo, $id, $uid);
    life_write_archive($pdo, $id);
    $out(['ok' => true]);

case 'follow_toggle':
    $id = intval($data['id'] ?? 0);
    if (!life_policy_row($pdo, $id)) $fail('بیمه‌نامه پیدا نشد.');
    $st = $pdo->prepare("SELECT COUNT(*) FROM life_followers WHERE policy_id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    if (intval($st->fetchColumn())) { $pdo->prepare("DELETE FROM life_followers WHERE policy_id = ? AND user_id = ?")->execute([$id, $uid]); $on = false; }
    else { life_follow($pdo, $id, $uid, 0); $on = true; }
    life_sys_note($pdo, $id, life_user_name($pdo, $uid) . ($on ? ' پیگیریِ این بیمه‌نامه را بر عهده گرفت.' : ' از پیگیری‌کنندگان خارج شد.'), $uid);
    $out(['ok' => true, 'following' => $on, 'followers' => life_followers($pdo, $id)]);

case 'followers_save':
    $id = intval($data['id'] ?? 0);
    if (!life_policy_row($pdo, $id)) $fail('بیمه‌نامه پیدا نشد.');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['user_ids'] ?? [])))));
    $before = array_column(life_followers($pdo, $id), 'name', 'id');
    $pdo->prepare("DELETE FROM life_followers WHERE policy_id = ?" . ($ids ? " AND user_id NOT IN (" . implode(',', $ids) . ")" : ''))->execute([$id]);
    foreach ($ids as $x) life_follow($pdo, $id, $x, 0, $uid);
    $after = life_followers($pdo, $id);
    $names = array_column($after, 'name', 'id');
    $add = array_diff_key($names, $before); $rm = array_diff_key($before, $names);
    if ($add || $rm) life_sys_note($pdo, $id, 'پیگیری‌کنندگان: ' . trim(($add ? 'افزوده: ' . implode('، ', $add) : '') . ($add && $rm ? ' · ' : '') . ($rm ? 'برداشته: ' . implode('، ', $rm) : '')), $uid);
    $out(['ok' => true, 'followers' => $after]);

case 'staff_options':
    // کاربرانی که به بخشِ عمر دسترسی دارند (برای تعیینِ پیگیری‌کننده و فیلتر)
    $o = [];
    foreach ($pdo->query("SELECT id, full_name, role, perm_json FROM users WHERE COALESCE(is_deleted, 0) = 0 ORDER BY full_name")->fetchAll() as $u) {
        $ok = $u['role'] === 'ADMIN' || $u['role'] === 'LIFE';
        if (!$ok && $u['perm_json']) { $pp = json_decode((string)$u['perm_json'], true); $ok = is_array($pp) && !empty($pp['life-policies']); }
        if ($ok) $o[] = ['id' => intval($u['id']), 'name' => $u['full_name']];
    }
    $out(['ok' => true, 'users' => $o, 'me' => $uid]);

case 'note_delete':
    $st = $pdo->prepare("SELECT * FROM life_notes WHERE id = ?");
    $st->execute([intval($data['id'] ?? 0)]);
    $n = $st->fetch();
    if (!$n || $n['kind'] === 'sys') $fail('یادداشت پیدا نشد.');
    if (intval($n['created_by']) !== $uid && perm_real_role() !== 'ADMIN') $fail('فقط ثبت‌کننده یا مدیر کل می‌تواند این یادداشت را حذف کند.');
    $pdo->prepare("DELETE FROM life_notes WHERE id = ?")->execute([$n['id']]);
    life_write_archive($pdo, intval($n['policy_id']));
    $out(['ok' => true]);

// ---------------------------------------------------------------------
case 'inst_add':
    $id = intval($data['policy_id'] ?? 0);
    if (!life_policy_row($pdo, $id)) $fail('بیمه‌نامه پیدا نشد.');
    $n = life_int($data['inst_no'] ?? 0);
    $amount = life_int($data['amount'] ?? 0);
    [$j, $g] = life_jdate($data['due_j'] ?? '');
    if ($n <= 0 || $amount <= 0 || !$j) $fail('شماره‌ی قسط، مبلغ و سررسید لازم است.');
    $st = $pdo->prepare("SELECT COUNT(*) FROM life_installments WHERE policy_id = ? AND inst_no = ?");
    $st->execute([$id, $n]);
    if (intval($st->fetchColumn())) $fail('قسطِ ' . life_fa($n) . ' برای این بیمه‌نامه قبلاً ثبت شده است.');
    $pdo->prepare("INSERT INTO life_installments (policy_id, inst_no, due_j, due_g, amount, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())")
        ->execute([$id, $n, $j, $g, $amount, trim((string)($data['note'] ?? '')) ?: null]);
    life_sys_note($pdo, $id, 'قسطِ ' . $n . ' دستی اضافه شد (' . number_format($amount) . ' ریال، سررسید ' . $j . ').', $uid);
    life_write_archive($pdo, $id);
    $out(['ok' => true]);

case 'inst_save':
    $i = life_inst_row($pdo, $data['id'] ?? 0);
    if (!$i) $fail('قسط پیدا نشد.');
    $set = []; $args = []; $ch = [];
    if (isset($data['amount'])) { $a = life_int($data['amount']); if ($a <= 0) $fail('مبلغ درست نیست.'); if ($a !== intval($i['amount'])) { $set[] = 'amount = ?'; $args[] = $a; $ch[] = 'مبلغ ' . number_format($a); } }
    if (!empty($data['due_j'])) { [$j, $g] = life_jdate($data['due_j']); if (!$j) $fail('سررسید درست نیست.'); if ($j !== $i['due_j']) { $set[] = 'due_j = ?'; $args[] = $j; $set[] = 'due_g = ?'; $args[] = $g; $ch[] = 'سررسید ' . $j; } }
    if (array_key_exists('note', $data)) { $set[] = 'note = ?'; $args[] = trim((string)$data['note']) ?: null; }
    if (array_key_exists('cleared', $data)) {
        $c = !empty($data['cleared']) ? 1 : 0;
        if ($c !== intval($i['cleared'])) { $set[] = 'cleared = ?'; $args[] = $c; $set[] = 'cleared_note = ?'; $args[] = $c ? (trim((string)($data['cleared_note'] ?? '')) ?: 'تسویه‌ی دستی') : null; $ch[] = $c ? 'تسویه‌شده' : 'لغوِ تسویه'; }
    }
    if ($set) {
        $args[] = $i['id'];
        $pdo->prepare("UPDATE life_installments SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ?")->execute($args);
        if ($ch) life_sys_note($pdo, intval($i['policy_id']), 'قسطِ ' . $i['inst_no'] . ': ' . implode('، ', $ch), $uid, intval($i['id']));
        // تغییرِ سررسید => نامِ پوشه‌ی قسط هم عوض می‌شود
        $old = life_inst_dir_of($pdo, $i); $new = life_inst_dir_of($pdo, life_inst_row($pdo, $i['id']));
        if ($old !== $new && is_dir($old) && !file_exists($new)) @rename($old, $new);
        life_write_archive($pdo, intval($i['policy_id']));
    }
    $out(['ok' => true]);

case 'inst_delete':
    $i = life_inst_row($pdo, $data['id'] ?? 0);
    if (!$i) $fail('قسط پیدا نشد.');
    $c = $pdo->prepare("SELECT COUNT(*) FROM life_payments WHERE installment_id = ? AND voided_at IS NULL");
    $c->execute([$i['id']]);
    if (intval($c->fetchColumn())) $fail('این قسط پرداختِ ثبت‌شده دارد؛ اول پرداخت‌ها را باطل کنید.');
    $dir = life_inst_dir_of($pdo, $i);
    $pdo->prepare("DELETE FROM life_payments WHERE installment_id = ?")->execute([$i['id']]);
    $pdo->prepare("UPDATE life_notes SET installment_id = NULL WHERE installment_id = ?")->execute([$i['id']]);
    $pdo->prepare("DELETE FROM life_installments WHERE id = ?")->execute([$i['id']]);
    life_sys_note($pdo, intval($i['policy_id']), 'قسطِ ' . $i['inst_no'] . ' حذف شد.', $uid);
    if (is_dir($dir) && strpos($dir, life_archive_root()) === 0) life_rrmdir($dir);
    life_write_archive($pdo, intval($i['policy_id']));
    $out(['ok' => true]);

case 'pay_add':
    $i = life_inst_row($pdo, $data['installment_id'] ?? 0);
    if (!$i) $fail('قسط پیدا نشد.');
    $amount = life_int($data['amount'] ?? 0);
    if ($amount <= 0) $fail('مبلغِ پرداخت را وارد کنید.');
    [$j, $g] = life_jdate($data['paid_j'] ?? '');
    if (!$j) $fail('تاریخِ پرداخت درست نیست (۱۴۰۵/۰۷/۱۴).');
    $method = trim((string)($data['method'] ?? ''));
    if (!in_array($method, LIFE_PAY_METHODS, true)) $method = 'سایر';
    $file = null;
    if (!empty($_FILES['file']['name'])) {
        $u = life_upload('file', LIFE_DOC_EXTS);
        if (isset($u['error'])) $fail($u['error']);
        $dir = life_inst_dir_of($pdo, $i);
        if (!life_mkdir($dir)) $fail('پوشه‌ی بایگانیِ قسط ساخته نشد.');
        $dest = life_unique_file($dir, 'فیش پرداخت - ' . str_replace('/', '.', $j) . ' - ' . number_format($amount), $u['ext']);
        if (!move_uploaded_file($u['tmp'], $dest)) $fail('ذخیره‌ی فایل ممکن نشد.');
        $file = life_rel($dest);
    }
    $pdo->prepare("INSERT INTO life_payments (installment_id, policy_id, amount, paid_j, paid_g, method, ref_no, note, file_path, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$i['id'], $i['policy_id'], $amount, $j, $g, $method, trim(p2e_digits((string)($data['ref_no'] ?? ''))) ?: null, trim((string)($data['note'] ?? '')) ?: null, $file, $uid]);
    $payId = intval($pdo->lastInsertId());
    life_refresh_paid($pdo, intval($i['id']));
    life_follow($pdo, intval($i['policy_id']), $uid);
    life_sys_note($pdo, intval($i['policy_id']), 'پرداختِ ' . number_format($amount) . ' ریال برای قسطِ ' . $i['inst_no'] . ' ثبت شد (' . $method . ', ' . $j . ').', $uid, intval($i['id']));
    life_write_archive($pdo, intval($i['policy_id']));
    $out(['ok' => true, 'payment_id' => $payId]);

case 'pay_void':
    $st = $pdo->prepare("SELECT * FROM life_payments WHERE id = ?");
    $st->execute([intval($data['id'] ?? 0)]);
    $py = $st->fetch();
    if (!$py || $py['voided_at']) $fail('پرداخت پیدا نشد یا قبلاً باطل شده است.');
    $pdo->prepare("UPDATE life_payments SET voided_at = NOW(), voided_by = ? WHERE id = ?")->execute([$uid, $py['id']]);
    life_refresh_paid($pdo, intval($py['installment_id']));
    $i = life_inst_row($pdo, $py['installment_id']);
    life_sys_note($pdo, intval($py['policy_id']), 'پرداختِ ' . number_format($py['amount']) . ' ریالِ قسطِ ' . ($i['inst_no'] ?? '') . ' باطل شد' . (trim((string)($data['reason'] ?? '')) !== '' ? ': ' . trim($data['reason']) : '') . '.', $uid, intval($py['installment_id']));
    life_write_archive($pdo, intval($py['policy_id']));
    $out(['ok' => true]);

case 'inst_file':
    $i = life_inst_row($pdo, $data['id'] ?? 0);
    if (!$i) { http_response_code(404); $fail('قسط پیدا نشد.'); }
    $dir = realpath(life_inst_dir_of($pdo, $i));
    $abs = $dir ? realpath($dir . '/' . basename((string)($data['name'] ?? ''))) : false;
    if (!$abs || strpos($abs, $dir . '/') !== 0 || !is_file($abs)) { http_response_code(404); $fail('فایل پیدا نشد.'); }
    life_send_file($abs, null, !empty($data['inline']));

case 'inst_file_upload':
    $i = life_inst_row($pdo, $data['id'] ?? 0);
    if (!$i) $fail('قسط پیدا نشد.');
    $u = life_upload('file', LIFE_DOC_EXTS);
    if (isset($u['error'])) $fail($u['error']);
    $dir = life_inst_dir_of($pdo, $i);
    if (!life_mkdir($dir)) $fail('پوشه‌ی بایگانیِ قسط ساخته نشد.');
    $title = trim((string)($data['title'] ?? '')) ?: pathinfo($u['name'], PATHINFO_FILENAME);
    $dest = life_unique_file($dir, mb_substr($title, 0, 80), $u['ext']);
    if (!move_uploaded_file($u['tmp'], $dest)) $fail('ذخیره‌ی فایل ممکن نشد.');
    life_sys_note($pdo, intval($i['policy_id']), 'فایلِ «' . basename($dest) . '» به قسطِ ' . $i['inst_no'] . ' اضافه شد.', $uid, intval($i['id']));
    life_write_archive($pdo, intval($i['policy_id']));
    $out(['ok' => true, 'name' => basename($dest)]);

case 'inst_file_delete':
    $i = life_inst_row($pdo, $data['id'] ?? 0);
    if (!$i) $fail('قسط پیدا نشد.');
    $dir = realpath(life_inst_dir_of($pdo, $i));
    $name = basename((string)($data['name'] ?? ''));
    $abs = $dir ? realpath($dir . '/' . $name) : false;
    if (!$abs || strpos($abs, $dir . '/') !== 0 || !is_file($abs) || $name === 'اطلاعات قسط.txt') $fail('فایل پیدا نشد.');
    @unlink($abs);
    $pdo->prepare("UPDATE life_payments SET file_path = NULL WHERE installment_id = ? AND file_path = ?")->execute([$i['id'], life_rel($abs)]);
    life_sys_note($pdo, intval($i['policy_id']), 'فایلِ «' . $name . '» از قسطِ ' . $i['inst_no'] . ' حذف شد.', $uid, intval($i['id']));
    life_write_archive($pdo, intval($i['policy_id']));
    $out(['ok' => true]);

// ---------------------------------------------------------------------
case 'receipt_html':
case 'receipt_docx':
case 'receipt_pdf':
    $r = life_receipt_data($pdo, intval($data['installment_id'] ?? 0), intval($data['payment_id'] ?? 0), $uid);
    if (!$r) { http_response_code(404); $fail('قسط پیدا نشد.'); }
    $tpl = life_template_row($pdo, intval($data['template_id'] ?? 0));
    if (!$tpl) $fail('قالبی تعریف نشده است.');
    if (isset($data['footer']) && trim((string)$data['footer']) !== '') $tpl['footer_text'] = (string)$data['footer'];   // متنِ همین رسید
    if ($action === 'receipt_html') {
        if ($tpl['file_path']) {
            // قالبِ Word: همان فایلِ پرشده به‌شکلِ HTML نشان داده می‌شود
            $docx = life_receipt_docx($pdo, $r, $tpl);
            $html = $docx ? fin_docx_to_html($docx) : '';
            if ($docx) @unlink($docx);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>رسید قسط</title><style>body{font-family:Vazir,Tahoma;background:#eef2f7;padding:16px}.pg{max-width:820px;margin:auto;background:#fff;padding:28px;border-radius:16px}@media print{body{background:#fff;padding:0}.pg{padding:0}button{display:none}}</style></head><body><div style="text-align:left;max-width:820px;margin:0 auto 10px"><button onclick="print()">چاپ</button></div><div class="pg">' . $html . '</div></body></html>';
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo life_receipt_html($r, $tpl);
        exit;
    }
    $docx = life_receipt_docx($pdo, $r, $tpl);
    if (!$docx) $fail('ساختِ رسید ممکن نشد.');
    life_sys_note($pdo, intval($r['p']['id']), 'رسیدِ قسطِ ' . $r['inst']['inst_no'] . ' صادر شد (' . $tpl['name'] . ').', $uid, intval($r['inst']['id']));
    if ($action === 'receipt_pdf') {
        $pdf = preg_replace('/\.docx$/', '.pdf', $docx);
        $ok = fin_docx_to_pdf($docx, dirname($docx), $pdf, life_root());
        if ($ok && is_file($pdf)) life_send_file($pdf);
    }
    life_send_file($docx);

case 'templates':
    $out(['ok' => true, 'templates' => life_templates($pdo), 'codes' => life_receipt_codes()]);

case 'tpl_upload':
    $u = life_upload('file', ['docx'], 10);
    if (isset($u['error'])) $fail($u['error']);
    $name = trim((string)($data['name'] ?? '')) ?: pathinfo($u['name'], PATHINFO_FILENAME);
    $dest = life_tpl_dir() . '/tpl_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.docx';
    if (!move_uploaded_file($u['tmp'], $dest)) $fail('ذخیره‌ی قالب ممکن نشد.');
    // کدهای قالب
    $codes = [];
    $zip = new ZipArchive();
    if ($zip->open($dest) === true) {
        foreach (fin_docx_parts($zip) as $part) {
            $txt = strip_tags(fin_docx_heal_codes((string)$zip->getFromName($part)));
            if (preg_match_all('/\{\{([^{}]{1,60})\}\}/u', $txt, $m)) $codes = array_merge($codes, $m[1]);
        }
        $zip->close();
    } else { @unlink($dest); $fail('فایلِ Word خوانده نشد.'); }
    $codes = array_values(array_unique($codes));
    $unknown = array_values(array_diff($codes, array_keys(life_receipt_codes())));
    $pdo->prepare("INSERT INTO life_templates (name, file_path, footer_text, is_default, created_at, created_by) VALUES (?, ?, ?, 0, NOW(), ?)")
        ->execute([mb_substr($name, 0, 190), life_rel($dest), trim((string)($data['footer_text'] ?? '')), $uid]);
    $out(['ok' => true, 'codes' => $codes, 'unknown' => $unknown, 'templates' => life_templates($pdo)]);

case 'tpl_save':
    $id = intval($data['id'] ?? 0);
    $t = life_template_row($pdo, $id);
    if (!$t || intval($t['id']) !== $id) $fail('قالب پیدا نشد.');
    $pdo->prepare("UPDATE life_templates SET name = ?, footer_text = ? WHERE id = ?")->execute([mb_substr(trim((string)($data['name'] ?? $t['name'])) ?: $t['name'], 0, 190), (string)($data['footer_text'] ?? $t['footer_text']), $id]);
    if (!empty($data['is_default'])) { $pdo->exec("UPDATE life_templates SET is_default = 0"); $pdo->prepare("UPDATE life_templates SET is_default = 1 WHERE id = ?")->execute([$id]); }
    $out(['ok' => true, 'templates' => life_templates($pdo)]);

case 'tpl_delete':
    $id = intval($data['id'] ?? 0);
    $t = life_template_row($pdo, $id);
    if (!$t || intval($t['id']) !== $id) $fail('قالب پیدا نشد.');
    if ($t['file_path'] === null) $fail('قالبِ پیش‌فرضِ سامانه حذف نمی‌شود (متنش را می‌توانید عوض کنید).');
    $abs = life_root() . '/' . $t['file_path'];
    if (is_file($abs) && strpos(realpath($abs), realpath(life_tpl_dir())) === 0) @unlink($abs);
    $pdo->prepare("DELETE FROM life_templates WHERE id = ?")->execute([$id]);
    if ($t['is_default']) $pdo->exec("UPDATE life_templates SET is_default = 1 WHERE file_path IS NULL");
    $out(['ok' => true, 'templates' => life_templates($pdo)]);

case 'tpl_sample':
    $p = life_tpl_dir() . '/_sample.docx';
    if (!life_builtin_docx($p)) $fail('ساختِ فایلِ نمونه ممکن نشد.');
    life_send_file($p, 'قالب رسید قسط بیمه عمر (نمونه).docx');

case 'tpl_file':
    $t = life_template_row($pdo, intval($data['id'] ?? 0));
    if (!$t || !$t['file_path'] || !is_file(life_root() . '/' . $t['file_path'])) $fail('فایلِ قالب پیدا نشد.');
    life_send_file(life_root() . '/' . $t['file_path'], $t['name'] . '.docx');

// ---------------------------------------------------------------------
case 'colmap_get':
    $map = life_colmap($pdo);
    $o = [];
    foreach ($map as $k => [$fa, $names, $req]) $o[] = ['key' => $k, 'label' => $fa, 'names' => $names, 'required' => $req, 'default' => life_default_colmap()[$k][1]];
    $out(['ok' => true, 'columns' => $o]);

case 'colmap_save':
    $cols = (array)($data['columns'] ?? []);
    $def = life_default_colmap();
    $save = [];
    foreach ($cols as $k => $names) {
        if (!isset($def[$k])) continue;
        $names = array_values(array_unique(array_filter(array_map(function ($s) { return mb_substr(trim((string)$s), 0, 80); }, (array)$names), 'strlen')));
        if ($names && $names !== $def[$k][1]) $save[$k] = $names;
    }
    life_setting_set($pdo, 'life_colmap', $save ? json_encode($save, JSON_UNESCAPED_UNICODE) : '');
    $out(['ok' => true]);

case 'colmap_headers':
    $u = life_upload('file', ['xlsx', 'csv'], 30);
    if (isset($u['error'])) $fail($u['error']);
    $tmp = life_tmp_dir() . '/hdr_' . bin2hex(random_bytes(6)) . '.' . $u['ext'];
    move_uploaded_file($u['tmp'], $tmp);
    $h = life_file_headers($tmp);
    @unlink($tmp);
    // پیشنهادِ خودکار: هر سرستون به کدام کلید می‌خورد
    $lookup = [];
    foreach (life_colmap($pdo) as $k => [$fa, $names]) foreach ($names as $n) $lookup[life_norm_h($n)] = $k;
    $match = [];
    foreach ($h['headers'] as $hd) $match[] = ['header' => $hd, 'key' => $lookup[life_norm_h($hd)] ?? null];
    $out(['ok' => true, 'sheet' => $h['sheet'], 'headers' => $match]);

case 'import_preview':
    $u = life_upload('file', ['xlsx', 'csv'], 40);
    if (isset($u['error'])) $fail($u['error']);
    $tmp = life_tmp_dir() . '/up_' . bin2hex(random_bytes(6)) . '.' . $u['ext'];
    if (!move_uploaded_file($u['tmp'], $tmp)) $fail('ذخیره‌ی موقتِ فایل ممکن نشد.');
    @set_time_limit(180);
    $res = life_import_preview($pdo, $tmp, $u['name'], !empty($data['drop_first']) && $data['drop_first'] !== '0');
    @unlink($tmp);
    if (isset($res['error'])) $fail($res['error']);
    $out(['ok' => true] + $res);

case 'import_commit':
    @set_time_limit(300);
    $dec = is_array($data['decisions'] ?? null) ? $data['decisions'] : (json_decode((string)($data['decisions'] ?? ''), true) ?: []);
    $res = life_import_commit($pdo, (string)($data['token'] ?? ''), $dec, $uid);
    if (isset($res['error'])) $fail($res['error']);
    $out(['ok' => true] + $res);

case 'imports_list':
    $rows = [];
    foreach ($pdo->query("SELECT * FROM life_imports ORDER BY id DESC LIMIT 30") as $r) {
        $rows[] = ['id' => intval($r['id']), 'file_name' => $r['file_name'], 'at_j' => life_g2j(substr($r['created_at'], 0, 10)) . ' ' . substr($r['created_at'], 11, 5),
                   'by' => life_user_name($pdo, $r['created_by']), 'range_from' => $r['range_from'], 'range_to' => $r['range_to'], 'stats' => json_decode((string)$r['stats_json'], true) ?: []];
    }
    $out(['ok' => true, 'rows' => $rows]);

// ---------------------------------------------------------------------
//  ربات بله‌ی بیمه عمر
// ---------------------------------------------------------------------
case 'bot_get':
    $s = lbot_settings($pdo);
    $tok = lbot_token($pdo);
    $c = $pdo->query("SELECT kind, COUNT(*) n FROM life_bot_links GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
    $last = intval(life_setting($pdo, 'life_bot_last_run', 0));
    $rm = $pdo->query("SELECT kind, COUNT(*) n FROM life_reminders WHERE sent_on >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out(['ok' => true, 'settings' => array_diff_key($s, ['cron_key' => 1]), 'has_token' => $tok !== '',
          'token_masked' => $tok !== '' ? substr($tok, 0, 6) . '…' . substr($tok, -4) : '', 'username' => life_setting($pdo, 'life_bot_username', ''),
          'secure' => life_setting($pdo, 'life_bot_hook_key', '') !== '', 'customers' => intval($c['CUSTOMER'] ?? 0), 'staff' => intval($c['STAFF'] ?? 0),
          'cron_url' => $base . '/life_cron.php?key=' . $s['cron_key'], 'last_run' => $last ? life_g2j(date('Y-m-d', $last)) . ' ' . date('H:i', $last) : '',
          'sent_30' => $rm]);

case 'bot_save_token':
    $token = trim((string)($data['token'] ?? ''));
    if (!preg_match('/^\d+:[A-Za-z0-9_\-]{10,}$/', $token)) $fail('توکن درست نیست (شکلِ ۱۲۳۴۵:ABC... از @BotFather بله).');
    life_setting_set($pdo, 'life_bot_token', $token);
    $hook = sec_set_webhook($pdo, 'life', $token);
    $me = lbot_api($pdo, 'getMe', []);
    if (!empty($me['username'])) life_setting_set($pdo, 'life_bot_username', $me['username']);
    $out(['ok' => true, 'webhook' => !empty($hook['ok']), 'webhook_error' => $hook['error'] ?? null, 'username' => $me['username'] ?? null]);

case 'bot_save_settings':
    lbot_settings_save($pdo, (array)($data['settings'] ?? []));
    $out(['ok' => true]);

case 'bot_run':
    @set_time_limit(300);
    $out(['ok' => true] + lbot_run_reminders($pdo, true));

case 'bot_remind':
    $p = life_policy_row($pdo, intval($data['id'] ?? 0));
    if (!$p) $fail('بیمه‌نامه پیدا نشد.');
    if (lbot_token($pdo) === '') $fail('ربات بله‌ی بیمه عمر هنوز راه‌اندازی نشده است (تنظیمات ← ربات بله).');
    $d = life_detail($pdo, intval($p['id']));
    $target = null;
    foreach ($d['insts'] as $i) if ($i['rem'] > 0 && (!$target || ($i['days'] ?? -999) > 0)) { $target = $i; if (($i['days'] ?? 0) > 0) break; }
    if (!empty($data['installment_id'])) foreach ($d['insts'] as $i) if ($i['id'] === intval($data['installment_id'])) $target = $i;
    if (!$target || $target['rem'] <= 0) $fail('قسطِ پرداخت‌نشده‌ای نیست.');
    $n = lbot_remind_inst($pdo, $p, $target, 'manual', $target['days'] > 0 ? life_fa($target['days']) . ' روز از سررسیدش گذشته و هنوز پرداخت نشده است' : 'در تاریخِ ' . life_fa($target['due_j']) . ' سررسید می‌شود', $uid);
    if (!$n) $fail('بیمه‌گذار هنوز به ربات وصل نشده است؛ لینکِ ربات را برایش بفرستید تا با شماره و کد ملی وارد شود.');
    life_follow($pdo, intval($p['id']), $uid);
    $out(['ok' => true, 'sent' => $n]);

// ---------------------------------------------------------------------
case 'archive_list':
    life_mkdir(life_archive_root());
    $abs = life_safe_path($data['path'] ?? '');
    if (!$abs || !is_dir($abs)) $fail('پوشه پیدا نشد.');
    $root = realpath(life_archive_root());
    $rel = ltrim(substr($abs, strlen($root)), '/');
    $q = trim((string)($data['q'] ?? ''));
    $items = [];
    if ($q !== '') {
        // جست‌وجو در نامِ پوشه‌های بیمه‌نامه (سال/ماه/بیمه‌نامه)
        $q = p2e_digits($q);
        foreach ((array)glob($root . '/*/*/*', GLOB_ONLYDIR) as $d) {
            if (mb_stripos(p2e_digits(basename($d)), $q) === false) continue;
            $items[] = ['name' => basename($d), 'dir' => true, 'path' => ltrim(substr($d, strlen($root)), '/'), 'mtime' => filemtime($d), 'where' => basename(dirname(dirname($d))) . ' / ' . basename(dirname($d))];
            if (count($items) >= 200) break;
        }
    } else {
        foreach (scandir($abs) as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.') continue;
            $p = $abs . '/' . $f;
            $items[] = ['name' => $f, 'dir' => is_dir($p), 'path' => ltrim(($rel !== '' ? $rel . '/' : '') . $f, '/'), 'size' => is_file($p) ? filesize($p) : null, 'mtime' => filemtime($p),
                        'count' => is_dir($p) ? max(0, count(scandir($p)) - 2) : null];
        }
        usort($items, function ($a, $b) { return $b['dir'] <=> $a['dir'] ?: strnatcmp($a['name'], $b['name']); });
    }
    foreach ($items as &$it) { [$jy, $jm, $jd] = jalali_from_gregorian_ts($it['mtime']); $it['mtime_j'] = sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $it['mtime'])); }
    unset($it);
    $out(['ok' => true, 'path' => $rel, 'items' => $items]);

case 'archive_file':
    $abs = life_safe_path($data['path'] ?? '');
    if (!$abs || !is_file($abs)) { http_response_code(404); $fail('فایل پیدا نشد.'); }
    life_send_file($abs, null, !empty($data['inline']));

case 'archive_zip':
    $abs = life_safe_path($data['path'] ?? '');
    if (!$abs || !is_dir($abs)) $fail('پوشه پیدا نشد.');
    @set_time_limit(300);
    $zipPath = life_tmp_dir() . '/zip_' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) $fail('ساختِ فایلِ زیپ ممکن نشد.');
    $base = dirname($abs);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $local = ltrim(substr($f->getPathname(), strlen($base)), '/');
        if ($f->isDir()) $zip->addEmptyDir($local); else $zip->addFile($f->getPathname(), $local);
    }
    $zip->close();
    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"archive.zip\"; filename*=UTF-8''" . rawurlencode(basename($abs) . '.zip'));
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}
$fail('درخواست نامعتبر است.');
