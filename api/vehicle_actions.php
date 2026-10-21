<?php
// فایل: api/vehicle_actions.php
// «پرونده‌ی خودروها»: فهرست، پرونده‌ی هر پلاک، گزارشِ PDF، اکسلِ فهرست، عکسِ مدل‌ها و یادداشت.
// پنلِ ما (مدیر کل / دسترسیِ «پرونده‌ی خودروها») همه‌ی خودروها؛ پنلِ شرکت‌ها (?portal=1) فقط خودروهای شرکت‌های خودش.
header('Content-Type: application/json; charset=utf-8');
$portal = !empty($_GET['portal']);
if (!$portal) session_start();
require '../config/db.php';
if (!$portal) { require_once __DIR__ . '/_perm.php'; perm_gate($pdo, __FILE__); }
require __DIR__ . '/_case_helpers.php';
require __DIR__ . '/_company_helpers.php';
require_once __DIR__ . '/_auth_helpers.php';
require __DIR__ . '/finance_core.php';
require __DIR__ . '/_import_archive.php';
require __DIR__ . '/_bordereau.php';
require __DIR__ . '/_vehicles.php';

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
$scope = [];
if ($portal) {
    $sess = require_company_portal_session($pdo);
    $scope = ['company_ids' => $sess['company_ids']];
    $actorName = (string)$sess['full_name'];
} else {
    if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
    if (($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'دسترسی به «پرونده‌ی خودروها» ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']);
    $actorName = (string)($_SESSION['full_name'] ?? '');
}
company_ensure_issue_info_cols($pdo);
imp_ensure($pdo);
brd_ensure($pdo);
vh_ensure($pdo);
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
$editable = !$portal;

try {
    if ($action === 'list') {
        @set_time_limit(120);
        $rows = vh_list($pdo, $scope);
        $companies = [];
        foreach ($rows as $r) if ($r['company_id']) $companies[$r['company_id']] = $r['company'];
        asort($companies);
        $out(['ok' => true, 'rows' => $rows, 'companies' => array_map(function ($id, $n) { return ['id' => $id, 'name' => $n]; }, array_keys($companies), $companies),
              'shapes' => VH_SHAPE_FA, 'tpl_svg' => json_encode(vh_svg_templates()), 'editable' => $editable]);
    }
    if ($action === 'detail' || $action === 'report_pdf') {
        $key = (string)($data['key'] ?? '');
        if (!preg_match('/^[pv]:/', $key)) $out(['ok' => false, 'error' => 'خودرو مشخص نیست.']);
        $d = vh_detail($pdo, $key, $scope);
        if (!$d) $out(['ok' => false, 'error' => 'خودرو پیدا نشد.']);
        if ($portal) {   // پنلِ شرکت‌ها: بدونِ یادداشتِ داخلی و جزئیاتِ ردیف‌های کارکنان
            $d['note'] = null;
            $d['records'] = array_values(array_filter($d['records'], function ($r) { return $r['src'] !== 'P'; }));
        }
        if ($action === 'detail') $out(['ok' => true, 'editable' => $editable, 'tpl_svg' => json_encode(vh_svg_templates())] + $d);
        $dest = rtrim(sys_get_temp_dir(), '/') . '/vh_' . bin2hex(random_bytes(5)) . '.pdf';
        if (!vh_report_pdf($pdo, $d, $dest)) $out(['ok' => false, 'error' => 'ساختِ PDF ممکن نشد.']);
        $name = sanitize_folder_name('پرونده خودرو ' . (plate_for_filename($d['summary']['plate']) ?: $d['summary']['vin']) . ' - ' . str_replace('/', '.', jalali_from_gregorian_ts_dotted(time())));
        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=\"vehicle.pdf\"; filename*=UTF-8''" . rawurlencode($name . '.pdf'));
        header('Content-Length: ' . filesize($dest));
        readfile($dest); @unlink($dest);
        exit;
    }
    if ($action === 'export_xlsx') {
        require_once __DIR__ . '/_xlsx_writer.php';
        $keys = json_decode((string)($data['keys'] ?? '[]'), true);
        $rows = vh_list($pdo, $scope);
        if (is_array($keys) && $keys) { $m = []; foreach ($rows as $r) $m[$r['key']] = $r; $rows = array_values(array_filter(array_map(function ($k) use ($m) { return $m[$k] ?? null; }, $keys))); }
        $headers = ['#', 'پلاک', 'شماره شاسی', 'شماره موتور', 'خودرو', 'مدل', 'رنگ', 'کاربری', 'نوع', 'مالک / بیمه‌گذار', 'شرکت', 'پرسنل', 'تعداد بیمه‌نامه', 'بدنه تا', 'ثالث تا', 'نزدیک‌ترین انقضا (روز)', 'آخرین بیمه‌نامه'];
        $xr = [];
        foreach ($rows as $i => $r) $xr[] = [$i + 1, $r['plate'], $r['vin'], $r['engine'], $r['car'], $r['model_year'], $r['color'], $r['usage'], $r['shape_fa'], $r['owner'], $r['company'], $r['person'],
                                             $r['policies'], fa_digits($r['body_end']), fa_digits($r['third_end']), $r['next_left'] === null ? '' : $r['next_left'], $r['last_policy']];
        $path = xlsx_build($headers, $xr, 'خودروها', [0, 12, 15]);
        header_remove('Content-Type');
        xlsx_send($path, 'پرونده خودروها ' . fa_digits(str_replace('/', '-', jalali_from_gregorian_ts_dotted(time()))) . '.xlsx');
        exit;
    }
    // عکسِ مدلِ خودرو (برای همه‌ی خودروهایی که نامشان این مدل را دارد)
    if ($action === 'model_img') {
        $st = $pdo->prepare("SELECT file_path FROM vh_model_images WHERE id = ?");
        $st->execute([intval($data['id'] ?? 0)]);
        $p = $st->fetchColumn();
        if (!$p || !is_file($p)) { http_response_code(404); exit; }
        header_remove('Content-Type');
        header('Content-Type: ' . (preg_match('/\.png$/i', $p) ? 'image/png' : (preg_match('/\.webp$/i', $p) ? 'image/webp' : 'image/jpeg')));
        header('Cache-Control: private, max-age=86400');
        readfile($p);
        exit;
    }
    if (!$editable) $out(['ok' => false, 'error' => 'این عملیات در پنلِ شرکت‌ها نیست.']);
    if ($action === 'model_image') {
        $label = trim((string)($data['label'] ?? ''));
        $key = brd_norm($label);
        if (mb_strlen($key) < 2) $out(['ok' => false, 'error' => 'نامِ مدل را بنویسید (مثلاً «پژو 405» یا «پراید»).']);
        $f = $_FILES['file'] ?? null;
        if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'عکس دریافت نشد.']);
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !@getimagesize($f['tmp_name'])) $out(['ok' => false, 'error' => 'فایل باید عکس (JPG، PNG یا WEBP) باشد.']);
        if (($f['size'] ?? 0) > 5 * 1048576) $out(['ok' => false, 'error' => 'حجمِ عکس بیش از ۵ مگابایت است.']);
        $dest = vh_img_dir() . '/' . substr(md5($key), 0, 16) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        foreach (glob(vh_img_dir() . '/' . substr(md5($key), 0, 16) . '.*') ?: [] as $old) @unlink($old);
        if (!move_uploaded_file($f['tmp_name'], $dest)) $out(['ok' => false, 'error' => 'ذخیره‌ی عکس ممکن نشد.']);
        $pdo->prepare("INSERT INTO vh_model_images (key_norm, label, file_path, created_by, created_at) VALUES (?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE label = VALUES(label), file_path = VALUES(file_path), created_at = NOW()")
            ->execute([$key, mb_substr($label, 0, 150), $dest, intval($_SESSION['user_id'] ?? 0) ?: null]);
        $out(['ok' => true]);
    }
    if ($action === 'model_image_delete') {
        $st = $pdo->prepare("SELECT file_path FROM vh_model_images WHERE id = ?");
        $st->execute([intval($data['id'] ?? 0)]);
        if (($p = $st->fetchColumn())) @unlink($p);
        $pdo->prepare("DELETE FROM vh_model_images WHERE id = ?")->execute([intval($data['id'] ?? 0)]);
        $out(['ok' => true]);
    }
    if ($action === 'models') $out(['ok' => true, 'models' => $pdo->query("SELECT id, label FROM vh_model_images ORDER BY label")->fetchAll()]);
    if ($action === 'note') {
        $key = (string)($data['key'] ?? '');
        if (!preg_match('/^[pv]:/', $key)) $out(['ok' => false, 'error' => 'خودرو مشخص نیست.']);
        $pdo->prepare("INSERT INTO vh_notes (vkey, note, updated_by, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE note = VALUES(note), updated_by = VALUES(updated_by), updated_at = NOW()")
            ->execute([mb_substr($key, 0, 60), mb_substr(trim((string)($data['note'] ?? '')), 0, 5000), mb_substr($actorName, 0, 190)]);
        $out(['ok' => true]);
    }
    $out(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[vehicle_actions] ' . $action . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
