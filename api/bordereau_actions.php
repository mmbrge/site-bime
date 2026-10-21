<?php
// فایل: api/bordereau_actions.php
// «بردروی پاسارگاد»: ورودِ اکسل (پیش‌نمایش ← ثبت)، فهرست، الحاقیه، پیگیریِ تمدید، حذف و خروجیِ اکسل/PDF (کلی یا هر شرکت).
// منطق: api/_bordereau.php — به مالی و صادره‌ها وصل نیست.
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

$out = function ($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['user_id'])) $out(['ok' => false, 'error' => 'ابتدا وارد پنل شوید.']);
if (($_SESSION['role'] ?? '') !== 'ADMIN') $out(['ok' => false, 'error' => 'دسترسی به «بردروی پاسارگاد» ندارید؛ از مدیر کل بخواهید در «دسترسی‌ها» بازش کند.']);
brd_ensure($pdo);
companies_extra_ensure($pdo);
$isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = ($isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST) + $_GET;
$action = (string)($data['action'] ?? '');
$actor = ['id' => intval($_SESSION['user_id']), 'name' => (string)($_SESSION['full_name'] ?? '')];
$tmpRoot = dirname(__DIR__) . '/tmp_ocr/bordereau';
$pick = function ($data) use ($pdo) {
    $f = [];
    if (!empty($data['company_id'])) $f['company_id'] = intval($data['company_id']);
    $ids = json_decode((string)($data['ids'] ?? ''), true);
    if (is_array($ids) && $ids) $f['ids'] = $ids;
    return $f;
};

try {
    if ($action === 'boot') {
        $batches = $pdo->query("SELECT * FROM brd_batches ORDER BY id DESC LIMIT 100")->fetchAll();
        foreach ($batches as &$b) $b['at'] = jalali_from_gregorian_ts_dotted(strtotime($b['created_at']));
        unset($b);
        $companies = $pdo->query("SELECT id, name FROM companies WHERE COALESCE(is_import, 0) = 0 ORDER BY name")->fetchAll();
        $out(['ok' => true, 'batches' => $batches, 'companies' => $companies, 'endorse_kinds' => BRD_ENDORSE_KINDS, 'channels' => BRD_CHANNELS]);
    }
    if ($action === 'list') $out(['ok' => true, 'rows' => brd_rows($pdo, $pick($data))]);

    // ---- پیش‌نمایش (چیزی ثبت نمی‌شود) ----
    if ($action === 'preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'فایلِ اکسل دریافت نشد.']);
        $ext = upload_ext($f['name'] ?? '');
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) $out(['ok' => false, 'error' => 'فایل باید اکسل (xlsx) یا CSV باشد.']);
        if (($f['size'] ?? 0) > 30 * 1048576) $out(['ok' => false, 'error' => 'حجمِ فایل بیش از ۳۰ مگابایت است.']);
        @mkdir($tmpRoot, 0775, true);
        foreach (glob($tmpRoot . '/*') ?: [] as $old) if (is_file($old) && filemtime($old) < time() - 86400) @unlink($old);
        $token = bin2hex(random_bytes(8));
        $src = $tmpRoot . '/' . $token . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $src)) $out(['ok' => false, 'error' => 'ذخیره‌ی فایل ممکن نشد.']);
        $res = brd_parse($pdo, $src);
        @unlink($src);
        if (isset($res['error'])) $out(['ok' => false, 'error' => $res['error']]);
        file_put_contents($tmpRoot . '/' . $token . '.json', json_encode(['name' => $f['name'], 'rows' => $res['rows']], JSON_UNESCAPED_UNICODE));
        $okN = count(array_filter($res['rows'], function ($r) { return $r['ok']; }));
        $exN = count(array_filter($res['rows'], function ($r) { return $r['ok'] && $r['exists']; }));
        $slim = array_map(function ($r) { return ['line' => $r['line'], 'ok' => $r['ok'], 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'exists' => $r['exists'],
                                                  'type_fa' => $r['type_fa'], 'policy' => $r['policy'], 'issue' => $r['issue'], 'end' => $r['pf']['end_date'] ?? '', 'insured' => $r['insured'],
                                                  'group' => ($r['pf']['contract_party'] ?? '') ?: $r['insured'], 'car' => $r['car_name'], 'vin' => $r['vin'],
                                                  'plate' => $r['plate'] ? company_plate_display($r['plate']['p1'], $r['plate']['p2'], $r['plate']['letter'], $r['plate']['p4']) : '',
                                                  'premium' => $r['premium']]; }, $res['rows']);
        $out(['ok' => true, 'token' => $token, 'name' => $f['name'], 'rows' => $slim, 'counts' => ['total' => count($res['rows']), 'ok' => $okN, 'bad' => count($res['rows']) - $okN, 'update' => $exN, 'new' => $okN - $exN]]);
    }
    if ($action === 'commit') {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($data['token'] ?? ''));
        $file = $tmpRoot . '/' . $token . '.json';
        $meta = $token && is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!$meta) $out(['ok' => false, 'error' => 'پیش‌نمایش منقضی شده؛ فایل را دوباره انتخاب کنید.']);
        @set_time_limit(300);
        $res = brd_commit($pdo, $meta['rows'], $meta['name'] ?? '', $actor, isset($data['lines']) ? (array)$data['lines'] : null);
        if (!empty($res['ok'])) @unlink($file);
        $out($res);
    }

    // ---- الحاقیه (چندبخشی، با فایلِ اختیاری) و پیگیریِ تمدید ----
    if ($action === 'endorse') {
        $r = brd_add_endorsement($pdo, intval($data['id'] ?? 0), $data, $actor, $_FILES['file'] ?? null);
        if ($r['ok']) $r['row'] = brd_rows($pdo, ['ids' => [intval($data['id'])]])[0] ?? null;
        $out($r);
    }
    if ($action === 'endorse_delete') {
        $e = $pdo->prepare("SELECT * FROM brd_endorsements WHERE id = ?");
        $e->execute([intval($data['endorse_id'] ?? 0)]);
        $row = $e->fetch();
        if (!$row) $out(['ok' => false, 'error' => 'الحاقیه پیدا نشد.']);
        $pdo->prepare("DELETE FROM brd_endorsements WHERE id = ?")->execute([$row['id']]);
        if ($row['kind'] === 'CANCEL') {
            $left = $pdo->prepare("SELECT COUNT(*) FROM brd_endorsements WHERE row_id = ? AND kind = 'CANCEL'");
            $left->execute([$row['row_id']]);
            if (!intval($left->fetchColumn())) $pdo->prepare("UPDATE brd_rows SET status = 'ACTIVE' WHERE id = ?")->execute([$row['row_id']]);
        }
        $out(['ok' => true, 'row' => brd_rows($pdo, ['ids' => [intval($row['row_id'])]])[0] ?? null]);
    }
    if ($action === 'note') {
        $ch = (string)($data['channel'] ?? '');
        if (!isset(BRD_CHANNELS[$ch])) $out(['ok' => false, 'error' => 'روشِ پیگیری را انتخاب کنید.']);
        $ids = array_filter(array_map('intval', (array)($data['ids'] ?? [$data['id'] ?? 0])));
        if (!$ids) $out(['ok' => false, 'error' => 'ردیفی انتخاب نشده.']);
        $ins = $pdo->prepare("INSERT INTO brd_notes (row_id, channel, note, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($ids as $id) $ins->execute([$id, $ch, mb_substr(trim((string)($data['note'] ?? '')), 0, 600) ?: null, $actor['id'], mb_substr($actor['name'], 0, 190)]);
        $out(['ok' => true, 'rows' => brd_rows($pdo, ['ids' => $ids])]);
    }
    if ($action === 'set_company') {
        $id = intval($data['id'] ?? 0); $cid = intval($data['company_id'] ?? 0) ?: null;
        $name = null;
        if ($cid) { $g = $pdo->prepare("SELECT name FROM companies WHERE id = ?"); $g->execute([$cid]); $name = $g->fetchColumn(); if (!$name) $out(['ok' => false, 'error' => 'شرکت پیدا نشد.']); }
        $pdo->prepare("UPDATE brd_rows SET company_id = ?, group_name = COALESCE(?, group_name), updated_at = NOW() WHERE id = ?")->execute([$cid, $name ?: null, $id]);
        brd_ensure_folder($pdo, dirname(__DIR__), $id);
        $out(['ok' => true, 'row' => brd_rows($pdo, ['ids' => [$id]])[0] ?? null]);
    }
    if ($action === 'delete') {
        $ids = array_filter(array_map('intval', (array)($data['ids'] ?? [])));
        if (!$ids) $out(['ok' => false, 'error' => 'ردیفی انتخاب نشده.']);
        $in = implode(',', $ids);
        $pdo->exec("DELETE FROM brd_endorsements WHERE row_id IN ($in)");
        $pdo->exec("DELETE FROM brd_notes WHERE row_id IN ($in)");
        $pdo->exec("DELETE FROM brd_rows WHERE id IN ($in)");
        $out(['ok' => true, 'deleted' => count($ids)]);   // پوشه‌های بایگانی دست نمی‌خورند
    }

    // ---- خروجی: اکسل یا PDF (کلی، یک شرکت، یا ردیف‌های انتخاب‌شده به همان ترتیب) ----
    if ($action === 'export_xlsx' || $action === 'export_pdf') {
        $f = $pick($data);
        $rows = brd_rows($pdo, $f);
        if (!empty($f['ids'])) { $order = array_flip(array_map('intval', $f['ids'])); usort($rows, function ($a, $b) use ($order) { return ($order[$a['id']] ?? 0) <=> ($order[$b['id']] ?? 0); }); }
        if (!$rows) $out(['ok' => false, 'error' => 'ردیفی برای خروجی نیست.']);
        $title = trim((string)($data['title'] ?? '')) ?: (!empty($f['company_id']) ? ($rows[0]['company_name'] ?: $rows[0]['group']) : 'بردروی پاسارگاد');
        $name = sanitize_folder_name('بردرو - ' . $title . ' - ' . str_replace('/', '.', jalali_from_gregorian_ts_dotted(time())));
        if ($action === 'export_xlsx') {
            $path = brd_export_xlsx($rows, $title);
            header_remove('Content-Type');
            xlsx_send($path, $name . '.xlsx');
            exit;
        }
        $dest = rtrim(sys_get_temp_dir(), '/') . '/brd_' . bin2hex(random_bytes(5)) . '.pdf';
        if (!brd_export_pdf($rows, 'بردروی پاسارگاد · ' . $title, $dest)) $out(['ok' => false, 'error' => 'ساختِ PDF ممکن نشد.']);
        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=\"bordereau.pdf\"; filename*=UTF-8''" . rawurlencode($name . '.pdf'));
        header('Content-Length: ' . filesize($dest));
        readfile($dest);
        @unlink($dest);
        exit;
    }

    $out(['ok' => false, 'error' => 'عملیات نامعتبر است.']);
} catch (Throwable $e) {
    error_log('[bordereau_actions] ' . $action . ': ' . $e->getMessage() . ' @' . $e->getLine());
    $out(['ok' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
