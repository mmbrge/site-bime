<?php
// فایل: api/personnel_actions.php
// «پرونده‌ی پرسنل»: فهرست، پرونده‌ی هر شخص، ویرایش، عکس، نقل‌وانتقال، خانواده، ورودِ لیستِ پرسنلی، PDF و اکسل.
session_start();
header('Content-Type: application/json; charset=utf-8');
require '../config/db.php';
require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__);
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require __DIR__ . '/finance_core.php';
require __DIR__ . '/_import_archive.php';
require __DIR__ . '/_bordereau.php';
require __DIR__ . '/_vehicles.php';
require __DIR__ . '/_personnel.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
if (($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'دسترسی به «پرونده‌ی پرسنل» ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']);
company_ensure_issue_info_cols($pdo);
imp_ensure($pdo);
brd_ensure($pdo);
vh_ensure($pdo);
per_ensure($pdo);
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
$by = (string)($_SESSION['full_name'] ?? '');
$tmpRoot = dirname(__DIR__) . '/tmp_ocr/roster';

try {
    if ($action === 'list') {
        $companies = $pdo->query("SELECT id, name FROM companies WHERE COALESCE(is_import, 0) = 0 ORDER BY name")->fetchAll();
        $batches = $pdo->query("SELECT * FROM per_roster_batches ORDER BY id DESC LIMIT 30")->fetchAll();
        $out(['ok' => true, 'rows' => per_list($pdo), 'companies' => $companies, 'batches' => $batches]);
    }
    if ($action === 'detail' || $action === 'report_pdf') {
        @set_time_limit(120);
        $d = per_detail($pdo, intval($data['id'] ?? 0));
        if (!$d) $out(['ok' => false, 'error' => 'شخص پیدا نشد.']);
        if ($action === 'detail') $out(['ok' => true] + $d);
        $dest = rtrim(sys_get_temp_dir(), '/') . '/per_' . bin2hex(random_bytes(5)) . '.pdf';
        if (!per_report_pdf($pdo, $d, $dest)) $out(['ok' => false, 'error' => 'ساختِ PDF ممکن نشد.']);
        $name = sanitize_folder_name('پرونده پرسنل ' . $d['person']['name'] . ' - ' . str_replace('/', '.', jalali_from_gregorian_ts_dotted(time())));
        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=\"person.pdf\"; filename*=UTF-8''" . rawurlencode($name . '.pdf'));
        header('Content-Length: ' . filesize($dest));
        readfile($dest); @unlink($dest);
        exit;
    }
    if ($action === 'photo_get') {
        $st = $pdo->prepare("SELECT photo_path FROM per_profiles WHERE person_id = ?");
        $st->execute([intval($data['id'] ?? 0)]);
        $p = $st->fetchColumn();
        if (!$p || !is_file($p)) { http_response_code(404); exit; }
        header_remove('Content-Type');
        header('Content-Type: ' . (preg_match('/\.png$/i', $p) ? 'image/png' : (preg_match('/\.webp$/i', $p) ? 'image/webp' : 'image/jpeg')));
        header('Cache-Control: private, max-age=3600');
        readfile($p);
        exit;
    }
    if ($action === 'photo') {
        $id = intval($data['id'] ?? 0);
        $f = $_FILES['file'] ?? null;
        if (!empty($data['remove'])) {
            $st = $pdo->prepare("SELECT photo_path FROM per_profiles WHERE person_id = ?"); $st->execute([$id]);
            if (($p = $st->fetchColumn())) @unlink($p);
            $pdo->prepare("UPDATE per_profiles SET photo_path = NULL WHERE person_id = ?")->execute([$id]);
            $out(['ok' => true]);
        }
        if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'عکس دریافت نشد.']);
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !@getimagesize($f['tmp_name'])) $out(['ok' => false, 'error' => 'فایل باید عکس (JPG، PNG یا WEBP) باشد.']);
        if (($f['size'] ?? 0) > 5 * 1048576) $out(['ok' => false, 'error' => 'حجمِ عکس بیش از ۵ مگابایت است.']);
        $dest = per_photo_dir() . '/p' . $id . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        foreach (glob(per_photo_dir() . '/p' . $id . '.*') ?: [] as $old) @unlink($old);
        if (!move_uploaded_file($f['tmp_name'], $dest)) $out(['ok' => false, 'error' => 'ذخیره‌ی عکس ممکن نشد.']);
        $pdo->prepare("INSERT INTO per_profiles (person_id, photo_path, updated_by, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), updated_at = NOW()")
            ->execute([$id, $dest, mb_substr($by, 0, 190)]);
        $out(['ok' => true]);
    }
    if ($action === 'save') $out(per_save($pdo, intval($data['id'] ?? 0), (array)($data['person'] ?? []), $by));
    if ($action === 'transfer') $out(per_transfer($pdo, intval($data['id'] ?? 0), intval($data['company_id'] ?? 0) ?: null, (string)($data['date'] ?? ''), 'MANUAL', (string)($data['note'] ?? ''), $by));
    if ($action === 'transfer_delete') {
        $pdo->prepare("DELETE FROM per_transfers WHERE id = ? AND person_id = ?")->execute([intval($data['transfer_id'] ?? 0), intval($data['id'] ?? 0)]);
        $out(['ok' => true]);   // شرکتِ فعلی عوض نمی‌شود؛ فقط سابقه حذف می‌شود
    }
    if ($action === 'family_save') {
        $f = (array)($data['member'] ?? []);
        $name = trim((string)($f['name'] ?? ''));
        if ($name === '') $out(['ok' => false, 'error' => 'نامِ عضوِ خانواده را بنویسید.']);
        $vals = [mb_substr($name, 0, 190), mb_substr(trim((string)($f['relation'] ?? '')), 0, 40) ?: null, preg_replace('/\D/', '', p2e_digits((string)($f['nid'] ?? ''))) ?: null,
                 imp_jdate($f['birth'] ?? '') ?: null, mb_substr(p2e_digits(trim((string)($f['mobile'] ?? ''))), 0, 30) ?: null, mb_substr(trim((string)($f['note'] ?? '')), 0, 300) ?: null];
        if (!empty($f['id'])) $pdo->prepare("UPDATE per_family SET full_name = ?, relation = ?, national_code = ?, birth_date = ?, mobile = ?, note = ? WHERE id = ? AND person_id = ?")->execute(array_merge($vals, [intval($f['id']), intval($data['id'] ?? 0)]));
        else $pdo->prepare("INSERT INTO per_family (full_name, relation, national_code, birth_date, mobile, note, person_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")->execute(array_merge($vals, [intval($data['id'] ?? 0)]));
        $out(['ok' => true]);
    }
    if ($action === 'family_delete') {
        $pdo->prepare("DELETE FROM per_family WHERE id = ? AND person_id = ?")->execute([intval($data['member_id'] ?? 0), intval($data['id'] ?? 0)]);
        $out(['ok' => true]);
    }
    // ---- لیستِ پرسنلی: پیش‌نمایش و ثبت ----
    if ($action === 'roster_preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'فایلِ اکسل دریافت نشد.']);
        $ext = upload_ext($f['name'] ?? '');
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) $out(['ok' => false, 'error' => 'فایل باید اکسل (xlsx) یا CSV باشد.']);
        @mkdir($tmpRoot, 0775, true);
        foreach (glob($tmpRoot . '/*') ?: [] as $old) if (is_file($old) && filemtime($old) < time() - 86400) @unlink($old);
        $token = bin2hex(random_bytes(8));
        $src = $tmpRoot . '/' . $token . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $src)) $out(['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.']);
        $res = per_roster_parse($pdo, $src, intval($data['company_id'] ?? 0) ?: null);
        @unlink($src);
        if (isset($res['error'])) $out(['ok' => false, 'error' => $res['error']]);
        file_put_contents($tmpRoot . '/' . $token . '.json', json_encode(['name' => $f['name'], 'rows' => $res['rows']], JSON_UNESCAPED_UNICODE));
        $c = ['NEW' => 0, 'MOVE' => 0, 'SAME' => 0, 'ERR' => 0];
        foreach ($res['rows'] as $r) $c[$r['action']]++;
        $out(['ok' => true, 'token' => $token, 'name' => $f['name'], 'rows' => $res['rows'], 'counts' => $c]);
    }
    if ($action === 'roster_commit') {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($data['token'] ?? ''));
        $file = $tmpRoot . '/' . $token . '.json';
        $meta = $token && is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!$meta) $out(['ok' => false, 'error' => 'پیش‌نمایش منقضی شده؛ فایل را دوباره انتخاب کنید.']);
        if (!imp_jdate($data['date'] ?? '')) $out(['ok' => false, 'error' => 'تاریخِ انتقال / لیست را وارد کنید.']);
        @set_time_limit(300);
        $res = per_roster_commit($pdo, $meta['rows'], $meta['name'] ?? '', (string)$data['date'], $by);
        if (!empty($res['ok'])) @unlink($file);
        $out($res);
    }
    if ($action === 'export_xlsx') {
        require_once __DIR__ . '/_xlsx_writer.php';
        $ids = json_decode((string)($data['ids'] ?? '[]'), true);
        $rows = per_list($pdo);
        if (is_array($ids) && $ids) { $m = []; foreach ($rows as $r) $m[$r['id']] = $r; $rows = array_values(array_filter(array_map(function ($i) use ($m) { return $m[intval($i)] ?? null; }, $ids))); }
        $headers = ['#', 'نام', 'کد ملی', 'کد پرسنلی', 'موبایل', 'شرکتِ فعلی', 'آخرین انتقال', 'خودرو', 'بیمه‌ی اتومبیل', 'بیمه‌ی عمر', 'درمان', 'اقساطِ معوق', 'ربات'];
        $xr = [];
        foreach ($rows as $i => $r) $xr[] = [$i + 1, $r['name'], $r['nid'], $r['pcode'], $r['mobile'], $r['company'], $r['last_transfer'] ? fa_digits($r['last_transfer']['date']) . ' → ' . $r['last_transfer']['to'] : '',
                                             $r['cars'], $r['auto'], $r['life'], $r['health'], $r['overdue'], $r['on_bot'] ? 'بله' : ''];
        $path = xlsx_build($headers, $xr, 'پرسنل', [0, 7, 8, 9, 10, 11]);
        header_remove('Content-Type');
        xlsx_send($path, 'پرونده پرسنل ' . fa_digits(str_replace('/', '-', jalali_from_gregorian_ts_dotted(time()))) . '.xlsx');
        exit;
    }
    $out(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[personnel_actions] ' . $action . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
