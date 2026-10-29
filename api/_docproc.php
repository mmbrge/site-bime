<?php
// فایل: api/_docproc.php
// پردازشِ یک مدرک با سه روش و نگه‌داشتنِ نتیجه‌ی هر روش (درخواست‌های شرکتی، پنلِ صدور، پرونده‌های کارکنان):
//   smart = «پردازش هوشمند»: متنِ فایل ← قاعده ← هوشِ مصنوعیِ متنی ← (اگر فیلدِ لازمی نماند) عکس
//   slow  = «پردازش دقیق (کند)»: همان، به‌اضافه‌ی بررسیِ کامل با خودِ عکس (سیستمِ داخلی یا «خواندنِ اسکن»)
//   fast  = «پردازش دقیق (سریع)»: خودِ عکس با هوشِ مصنوعیِ بیرونی و «مدلِ پردازشِ دقیقِ سریع» (فقط فیلدها، بدونِ توضیح)
// هر مدرک با هر روش یک بار پردازش می‌شود؛ دوباره زدنِ همان روش همان نتیجه را نشان می‌دهد (روش‌های دیگر آزاد است).
// نتیجه‌ها با ردیف/پرونده مقایسه می‌شوند؛ مقدارها دستی ویرایش‌پذیرند و همه‌ی اختلاف‌ها یا انتخاب‌شده‌ها روی ردیف می‌نشیند.
// جدول: doc_proc_results (ref = cd:شناسه‌ی مدرکِ شرکتی | kd:شناسه‌ی مدرکِ پرونده | f:هشِ مسیرِ فایل)

const DP_METHODS = ['smart' => 'پردازش هوشمند', 'slow' => 'پردازش دقیق (کند)', 'fast' => 'پردازش دقیق (سریع)'];

function dp_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS doc_proc_results (
        id INT AUTO_INCREMENT PRIMARY KEY, ref VARCHAR(80) NOT NULL, method VARCHAR(12) NOT NULL, status VARCHAR(12) NOT NULL,
        kind VARCHAR(30) NULL, kind_fa VARCHAR(80) NULL, data_json MEDIUMTEXT NULL, edited_json TEXT NULL, engines VARCHAR(120) NULL,
        error VARCHAR(600) NULL, ms INT NULL, user_id INT NULL, user_name VARCHAR(120) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_ref_method (ref, method), KEY idx_ref (ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function dp_ref_file($path) { return 'f:' . substr(sha1((string)$path), 0, 24); }
function dp_get($pdo, $ref, $method = null) {
    dp_ensure($pdo);
    if ($method !== null) { $st = $pdo->prepare("SELECT * FROM doc_proc_results WHERE ref = ? AND method = ?"); $st->execute([$ref, $method]); return $st->fetch() ?: null; }
    $st = $pdo->prepare("SELECT * FROM doc_proc_results WHERE ref = ?"); $st->execute([$ref]);
    $o = []; foreach ($st->fetchAll() as $r) $o[$r['method']] = $r;
    return $o;
}
function dp_save($pdo, $ref, $method, $status, array $res, $ms = 0, $error = '') {
    dp_ensure($pdo);
    $uid = intval($_SESSION['user_id'] ?? 0) ?: null;
    $un = mb_substr((string)($_SESSION['full_name'] ?? ''), 0, 120);
    $pdo->prepare("INSERT INTO doc_proc_results (ref, method, status, kind, kind_fa, data_json, edited_json, engines, error, ms, user_id, user_name, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE status = VALUES(status), kind = VALUES(kind), kind_fa = VALUES(kind_fa), data_json = VALUES(data_json), edited_json = NULL,
                       engines = VALUES(engines), error = VALUES(error), ms = VALUES(ms), user_id = VALUES(user_id), user_name = VALUES(user_name), created_at = NOW(), updated_at = NOW()")
        ->execute([$ref, $method, $status, mb_substr((string)($res['kind'] ?? ''), 0, 30), mb_substr((string)($res['kind_fa'] ?? ''), 0, 80),
                   json_encode((array)($res['data'] ?? []), JSON_UNESCAPED_UNICODE), mb_substr(implode(',', (array)($res['engines'] ?? [])), 0, 120),
                   mb_substr((string)$error, 0, 600), intval($ms), $uid, $un]);
}
// ویرایش‌های دستیِ یک نتیجه (field => مقدار) کنارِ همان نتیجه نگه داشته می‌شود
function dp_save_edits($pdo, $ref, $method, array $vals) {
    $row = dp_get($pdo, $ref, $method);
    if (!$row) return;
    $e = json_decode((string)($row['edited_json'] ?? ''), true) ?: [];
    foreach ($vals as $k => $v) $e[(string)$k] = mb_substr(trim((string)$v), 0, 250);
    $pdo->prepare("UPDATE doc_proc_results SET edited_json = ?, updated_at = NOW() WHERE id = ?")->execute([json_encode($e, JSON_UNESCAPED_UNICODE), intval($row['id'])]);
}
// خانه‌های شناسه‌ای (شاسی/موتور/پلاک) که ردیف دارد ولی با مدرک (یا اصلاحِ کاربر) نمی‌خواند
function dp_mismatch(array $cmp) {
    $o = [];
    foreach ($cmp as $x) {
        if (!in_array($x['field'], DP_ID_FIELDS, true) || !empty($x['same']) || $x['row'] === '') continue;
        $v = isset($x['edited']) ? (string)$x['edited'] : (string)$x['doc'];
        if ($v === '' || !empty($x['unread']) && !isset($x['edited'])) continue;
        $o[] = ['field' => $x['field'], 'label' => $x['label'], 'row' => $x['row'], 'doc' => $v];
    }
    return $o;
}
// فقط اصلاحِ «خوانده‌شده»ها (پلاک/شاسی/... که اشتباه خوانده شده) بدونِ دست زدن به ردیف؛ پلاک باید خوانا باشد
function dp_clean_edits(array $vals) {
    require_once __DIR__ . '/_vdoc.php';
    $o = [];
    foreach ($vals as $k => $v) {
        if (!is_scalar($v)) continue;
        $k = (string)$k; $v = trim((string)$v);
        if ($v === '') continue;
        if ($k === '__plate') {
            $key = dp_plate_key($v);
            if ($key === '') throw new RuntimeException('پلاکِ «' . $v . '» خوانا نیست؛ مثلِ «۲۱ایران - ۷۱۱ د ۱۶» بنویسید.');
            [$a, $b, $c, $e] = explode('|', $key); $v = dp_fmt_plate($e, $c, $b, $a);
        } elseif (in_array($k, ['__chassis', '__engine'], true)) $v = vdoc_clean_code($v);
        $o[$k] = $v;
    }
    return $o;
}
// بعد از اصلاح/نشاندن: اگر دیگر اختلافِ شناسه‌ای نماند، «خودروی دیگر؟» برداشته می‌شود (این روش و، برای مدرکِ ردیف، خودِ مدرک و جفتش)
function dp_resolve_mismatch($pdo, $ref, $method, callable $compare) {
    $v = dp_views($pdo, $ref, $compare)['methods'][$method] ?? null;
    if (!$v || !in_array($v['status'], ['OK', 'MISMATCH'], true)) return null;
    $ok = !$v['mismatch'];
    if ($ok && $v['status'] === 'MISMATCH') {
        $pdo->prepare("UPDATE doc_proc_results SET status = 'OK' WHERE ref = ? AND method = ?")->execute([$ref, $method]);
        if (strpos($ref, 'cd:') === 0) {
            $doc = dp_company_doc($pdo, intval(substr($ref, 3)));
            $ids = [intval(substr($ref, 3))];
            if ($doc && ($pair = dp_company_pair($pdo, $doc))) $ids[] = intval(is_array($pair) ? $pair['id'] : $pair);
            foreach ($ids as $i) $pdo->prepare("UPDATE company_documents SET ai_status = 'AUTO' WHERE id = ? AND ai_status = 'MISMATCH'")->execute([$i]);
        }
    } elseif (!$ok && $v['status'] === 'OK') {
        $pdo->prepare("UPDATE doc_proc_results SET status = 'MISMATCH' WHERE ref = ? AND method = ?")->execute([$ref, $method]);
    }
    return $ok;
}
// روش‌های در دسترس (و دلیلِ نبودن)
function dp_available($pdo) {
    require_once __DIR__ . '/_vdoc_ai.php';
    require_once __DIR__ . '/_ollama.php';
    $online = vdoc_ai_ready($pdo);
    $vision = ol_use($pdo, 'vision') || (ol_online_ok($pdo, 'vision') && $online);
    $c = vdoc_ai_conf($pdo);
    return ['smart' => ['ok' => true],
            'slow' => $vision ? ['ok' => true] : ['ok' => false, 'why' => 'بررسی با عکس تنظیم نشده (سیستمِ داخلی با مدلِ تصویری، یا «خواندنِ اسکن (هوش مصنوعی)»).'],
            'fast' => $online ? ['ok' => true, 'model' => $c['fast_model']] : ['ok' => false, 'why' => 'هوشِ مصنوعیِ بیرونی (کلیدهای API ← «خواندنِ اسکن (هوش مصنوعی)») تنظیم یا روشن نیست.']];
}

// ---------------- خواندن (بدونِ نشاندن روی ردیف) ----------------
// برای فایل‌هایی که مدرکِ شرکتیِ ردیف نیستند (پرونده‌های کارکنان، بیمه‌نامه‌ی صادرشده)
function dp_read_file($pdo, array $absList, $method, $docType, $companyId) {
    require_once __DIR__ . '/_vdoc.php';
    require_once __DIR__ . '/_doc_smart.php';
    require_once __DIR__ . '/_vdoc_ai.php';
    $absList = array_values(array_filter($absList, 'is_file'));
    if (!$absList) return ['ok' => false, 'error' => 'فایلِ مدرک روی هاست پیدا نشد.'];
    if ($method === 'fast') return vdoc_ai_fast_read($pdo, $absList);
    $res = null;
    if (ds_smart_available($pdo)) {
        $texts = [];
        foreach ($absList as $i => $abs) {
            $t = ds_page_text($pdo, $abs, 1);
            if (!empty($t['pending'])) return ['ok' => false, 'pending' => true, 'error' => (string)$t['error']];
            if (!empty($t['ok'])) $texts[] = (count($absList) > 1 ? '=== صفحه/روی ' . ($i + 1) . " ===\n" : '') . trim((string)$t['text']);
        }
        if ($texts) {
            $x = ds_extract($pdo, ds_type_of_doc((string)$docType) ?: 'auto', ds_clean_text(implode("\n\n", $texts)), intval($companyId));
            if (!empty($x['pending'])) return ['ok' => false, 'pending' => true, 'error' => (string)$x['error']];
            if (!empty($x['ok']) && !empty($x['result'])) $res = $x['result'];
        }
    }
    if (!$res) {
        $r = vdoc_run($pdo, $absList[0], 1, 280, []);
        if (!empty($r['pending'])) return ['ok' => false, 'pending' => true, 'error' => $r['error'] ?? ''];
        if (!empty($r['ok'])) $res = $r;
    }
    if ($method === 'slow') {
        // بررسیِ کامل با عکس: هر فایل جدا، جاهای خالی از عکس
        if (!$res) $res = ['ok' => true, 'kind' => 'unknown', 'kind_fa' => '', 'data' => [], 'need' => []];
        foreach ($absList as $abs) {
            $res = vdoc_vision_stage($pdo, $abs, $res, true);
            if (!empty($res['vision_pending'])) return ['ok' => false, 'pending' => true, 'error' => $res['vision_error'] ?: 'بررسی با عکس در صفِ سیستمِ داخلی است.'];
        }
        if (empty($res['data']) && !empty($res['vision_error'])) return ['ok' => false, 'error' => $res['vision_error']];
    } elseif ($res) $res = vdoc_vision_stage($pdo, $absList[0], $res, false);
    if (!$res || empty($res['data'])) return ['ok' => false, 'error' => 'اطلاعاتی از این مدرک خوانده نشد.'];
    $res['ok'] = true;
    return $res;
}

// ---------------- مدرکِ ردیفِ شرکتی (درخواست‌ها و پنلِ صدور) ----------------
function dp_company_doc($pdo, $docId) {
    $st = $pdo->prepare("SELECT cd.*, crp.status AS row_status FROM company_documents cd LEFT JOIN company_request_plates crp ON crp.id = cd.plate_id WHERE cd.id = ?");
    $st->execute([intval($docId)]);
    return $st->fetch() ?: null;
}
// روی/پشتِ دیگرِ کارتِ همین ردیف (با هم خوانده می‌شوند؛ نتیجه روی هر دو ثبت می‌شود)
function dp_company_pair($pdo, array $doc) {
    if (!in_array($doc['doc_type'], ['car_card_front', 'car_card_back'], true) || !$doc['plate_id']) return null;
    $q = $pdo->prepare("SELECT * FROM company_documents WHERE plate_id = ? AND status = 'ASSIGNED' AND doc_type = ? ORDER BY id DESC LIMIT 1");
    $q->execute([$doc['plate_id'], $doc['doc_type'] === 'car_card_front' ? 'car_card_back' : 'car_card_front']);
    return $q->fetch() ?: null;
}
// اجرای یک روش روی مدرکِ ردیف؛ اگر قبلاً با همین روش پردازش شده، همان نتیجه (cached) برمی‌گردد
function dp_row_doc($pdo, $siteRoot, $docId, $method, $force = false) {
    require_once __DIR__ . '/_vdoc.php';
    if (!isset(DP_METHODS[$method])) return ['ok' => false, 'error' => 'روشِ پردازش نامعتبر است.'];
    $doc = dp_company_doc($pdo, $docId);
    if (!$doc || !$doc['plate_id']) return ['ok' => false, 'error' => 'مدرک روی ردیفی ننشسته است.'];
    $ref = 'cd:' . intval($docId);
    $have = dp_get($pdo, $ref, $method);
    dp_wait_resolve($pdo, $doc, $have);
    if ($have && in_array($have['status'], ['OK', 'MISMATCH'], true) && !$force) return ['ok' => true, 'cached' => true, 'doc_id' => intval($docId), 'method' => $method];
    $av = dp_available($pdo)[$method];
    if (empty($av['ok'])) return ['ok' => false, 'error' => $av['why']];
    $t0 = microtime(true);
    if ($method === 'fast') {
        $pair = dp_company_pair($pdo, $doc);
        $paths = [$siteRoot . '/' . ltrim((string)$doc['file_path'], '/')];
        if ($pair) {
            $pp = $siteRoot . '/' . ltrim((string)$pair['file_path'], '/');
            $paths = $doc['doc_type'] === 'car_card_front' ? [$paths[0], $pp] : [$pp, $paths[0]];
        }
        require_once __DIR__ . '/_vdoc_ai.php';
        $pre = vdoc_ai_fast_read($pdo, $paths);
        if (empty($pre['ok'])) { dp_save($pdo, $ref, $method, 'FAIL', [], intval((microtime(true) - $t0) * 1000), $pre['error'] ?? 'خطا'); return ['ok' => false, 'error' => $pre['error'] ?? 'خوانده نشد.', 'method' => $method]; }
        $r = vdoc_process_row_doc($pdo, $siteRoot, $docId, $force, false, $pre);
    } else {
        $r = vdoc_process_row_doc($pdo, $siteRoot, $docId, $force, $method === 'slow');
    }
    $ms = intval((microtime(true) - $t0) * 1000);
    if (!empty($r['pending'])) { dp_save($pdo, $ref, $method, 'WAIT', [], $ms, $r['error'] ?? ''); return $r + ['method' => $method]; }
    if (empty($r['ok'])) { dp_save($pdo, $ref, $method, 'FAIL', [], $ms, $r['error'] ?? 'خطا'); return $r + ['method' => $method]; }
    $doc = dp_company_doc($pdo, $docId);
    [$d, $kind] = vdoc_doc_saved_data($doc);
    $aj = json_decode((string)($doc['ai_json'] ?? ''), true) ?: [];
    $res = ['kind' => $kind, 'kind_fa' => (string)($aj['kind_fa'] ?? ($r['kind_fa'] ?? '')), 'data' => $d, 'engines' => (array)($aj['engines'] ?? ($method === 'fast' ? ['fast'] : []))];
    $status = ($r['status'] ?? '') === 'MISMATCH' ? 'MISMATCH' : 'OK';
    dp_save($pdo, $ref, $method, $status, $res, $ms);
    if (!empty($r['pair'])) dp_save($pdo, 'cd:' . intval($r['pair']), $method, $status, $res, $ms);
    return $r + ['method' => $method];
}
// «در صف» (سیستمِ داخلی): وقتی جوابش رسید و روی مدرک نشست، نتیجه‌ی همان روش از روی مدرک ثبت می‌شود
function dp_wait_resolve($pdo, array $doc, &$have) {
    if (!$have || $have['status'] !== 'WAIT') return;
    if (in_array((string)$doc['ai_status'], ['WAIT', 'PENDING', 'RUNNING'], true) || empty($doc['ai_at']) || strtotime((string)$doc['ai_at']) < strtotime((string)$have['created_at'])) return;
    [$d, $kind] = vdoc_doc_saved_data($doc);
    if (!$d) return;
    $aj = json_decode((string)($doc['ai_json'] ?? ''), true) ?: [];
    dp_save($pdo, $have['ref'], $have['method'], $doc['ai_status'] === 'MISMATCH' ? 'MISMATCH' : 'OK', ['kind' => $kind, 'kind_fa' => (string)($aj['kind_fa'] ?? ''), 'data' => $d, 'engines' => (array)($aj['engines'] ?? [])], intval($have['ms']));
    $have = dp_get($pdo, $have['ref'], $have['method']);
}

// ---------------- مقایسه و نشاندن: ردیفِ شرکتی ----------------
function dp_fmt_plate($two, $letter, $three, $iran) { return trim("$iran" . 'ایران - ' . "$three $letter $two"); }
// شاسی/موتور/پلاک: «شناسه‌ی خودرو»؛ اختلافشان یعنی مدرک شاید مالِ خودروی دیگری است
const DP_ID_FIELDS = ['__chassis', '__engine', '__plate'];
// کلیدِ مقایسه‌ی پلاک (ایران|سه‌رقمی|حرف|دورقمی) از هر نوشتاری؛ '' = خوانا نیست
function dp_plate_key($t) {
    if (!function_exists('p2e_digits')) require_once __DIR__ . '/_case_helpers.php';
    if (!function_exists('company_parse_plate_text')) require_once __DIR__ . '/_company_helpers.php';
    $pp = company_parse_plate_text((string)$t);
    if (!$pp || !preg_match('/^\d{2}$/', (string)$pp['p1']) || !preg_match('/^\d{3}$/', (string)$pp['p2']) || !preg_match('/^\d{2}$/', (string)$pp['p4'])) return '';
    return $pp['p1'] . '|' . $pp['p2'] . '|' . strtr(trim((string)$pp['letter']), ['ي' => 'ی', 'ك' => 'ک', 'الف' => 'ا']) . '|' . $pp['p4'];
}
function dp_plate_same($a, $b) { $ka = dp_plate_key($a); return $ka !== '' && $ka === dp_plate_key($b); }
// خانه‌ی «پلاک» همیشه برای مدرکِ خودرو (حتی اگر خوانده نشد: unread، تا دستی نوشته شود)
function dp_plate_item($rowTxt, array $d, $kind) {
    $pp = $d['plate_parts'] ?? null; $doc = '';
    if (is_array($pp) && !empty($pp['letter']) && preg_match('/^\d{3}$/', (string)($pp['three'] ?? '')) && preg_match('/^\d{2}$/', (string)($pp['iran'] ?? '')))
        $doc = dp_fmt_plate($pp['two'] ?? '', $pp['letter'], $pp['three'], $pp['iran']);
    if (dp_plate_key($doc) === '') {
        $raw = trim((string)(is_scalar($d['plate'] ?? null) ? $d['plate'] : ''));
        $doc = $raw;
        if ($raw !== '' && ($k = dp_plate_key($raw)) !== '') { [$a, $b, $c, $e] = explode('|', $k); $doc = dp_fmt_plate($e, $c, $b, $a); }
    }
    if ($doc === '' && !in_array($kind, ['car_card', 'ownership_doc', 'policy'], true)) return null;
    $rowTxt = trim((string)$rowTxt);
    $unread = dp_plate_key($doc) === '';
    return ['field' => '__plate', 'label' => 'پلاک', 'row' => $rowTxt, 'doc' => $doc, 'same' => !$unread && $rowTxt !== '' && dp_plate_same($rowTxt, $doc)] + ($unread ? ['unread' => true] : []);
}
// فهرستِ خانه‌ها: [field, label, row (مقدارِ فعلیِ ردیف), doc (مقدارِ مدرک), same]
function dp_compare_company($pdo, $plateId, array $d, $kind) {
    require_once __DIR__ . '/_vdoc.php';
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?"); $st->execute([$plateId]);
    $r = $st->fetch();
    if (!$r) return [];
    $info = issue_info_decode($r['issue_info'] ?? null);
    $lab = vdoc_fill_labels(); $out = [];
    $add = function ($field, $label, $row, $doc, $code = false) use (&$out) {
        $row = trim((string)$row); $doc = trim((string)$doc);
        if ($doc === '') return;
        $same = $row !== '' && ($code ? vdoc_code_like($row, $doc) === true : vdoc_same_value($row, $doc));
        $out[] = ['field' => $field, 'label' => $label, 'row' => $row, 'doc' => $doc, 'same' => $same];
    };
    if ($kind !== 'national_card') {
        $add('__chassis', 'شماره شاسی / VIN', ($r['chassis_no'] ?? '') ?: ($r['vin'] ?? ''), vdoc_clean_code(($d['chassis_no'] ?? '') ?: ($d['vin'] ?? '')), true);
        $add('__engine', 'شماره موتور', $r['engine_no'] ?? '', vdoc_clean_code($d['engine_no'] ?? ''), true);
        if (!function_exists('company_plate_display')) require_once __DIR__ . '/_company_helpers.php';
        $rowPlate = trim((string)($r['plate_p2'] ?? '')) !== '' ? (string)company_plate_display($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) : '';
        if ($kind !== 'sales_invoice' && ($pi = dp_plate_item($rowPlate, $d, $kind))) $out[] = $pi;
    }
    foreach (vdoc_field_map($kind, $d) as $from => $to) {
        if (in_array($to, ['engine_no', 'vin', 'chassis_no'], true)) continue;
        $add($to, $lab[$to] ?? $to, $info[$to] ?? '', is_scalar($d[$from] ?? null) ? mb_substr((string)$d[$from], 0, 250) : '');
    }
    return $out;
}
// مقدارهای داده‌شده (field => value) روی ردیف (همان‌ها، حتی اگر خانه پر باشد)
function dp_apply_company($pdo, $plateId, array $vals) {
    require_once __DIR__ . '/_vdoc.php';
    if (!function_exists('company_parse_plate_text')) require_once __DIR__ . '/_company_helpers.php';
    $st = $pdo->prepare("SELECT issue_info FROM company_request_plates WHERE id = ?"); $st->execute([$plateId]);
    $info = issue_info_decode($st->fetchColumn()); $infoCh = false; $done = [];
    $lab = vdoc_fill_labels();
    $allowed = array_flip(array_values(vdoc_field_map('car_card', []) + vdoc_field_map('policy', []) + ['x1' => 'car_kind', 'x2' => 'car_usage', 'x3' => 'owner_phone']));
    foreach ($vals as $field => $v) {
        $field = (string)$field; $v = trim((string)$v);
        if ($v === '') continue;
        if ($field === '__chassis') {
            $c = vdoc_clean_code($v);
            $pdo->prepare("UPDATE company_request_plates SET chassis_no = ?, vin = ? WHERE id = ?")->execute([$c, strlen($c) === 17 ? $c : null, $plateId]);
            foreach (['chassis_no', 'vin'] as $k) if (isset($info[$k])) { $info[$k] = $c; $infoCh = true; }
            $done['شماره شاسی / VIN'] = $c;
        } elseif ($field === '__engine') {
            $c = vdoc_clean_code($v);
            $pdo->prepare("UPDATE company_request_plates SET engine_no = ? WHERE id = ?")->execute([$c, $plateId]);
            if (isset($info['engine_no'])) { $info['engine_no'] = $c; $infoCh = true; }
            $done['شماره موتور'] = $c;
        } elseif ($field === '__plate') {
            $pp = company_parse_plate_text($v);
            if (!$pp) throw new RuntimeException('پلاکِ «' . $v . '» خوانا نیست؛ مثلِ «۲۱ایران - ۷۱۱ د ۱۶» بنویسید.');
            $pdo->prepare("UPDATE company_request_plates SET plate_p4 = ?, plate_letter = ?, plate_p2 = ?, plate_p1 = ? WHERE id = ?")->execute([$pp['p4'], $pp['letter'], $pp['p2'], $pp['p1'], $plateId]);
            $done['پلاک'] = company_plate_display($pp['p1'], $pp['p2'], $pp['letter'], $pp['p4']);
        } elseif (isset($allowed[$field]) || isset($lab[$field])) {
            if (in_array($field, ['engine_no', 'vin', 'chassis_no', 'plate_p2', 'party_mode', 'is_new_vehicle'], true)) continue;
            $info[$field] = mb_substr($v, 0, 250); $infoCh = true;
            $done[$lab[$field] ?? $field] = $v;
        }
    }
    if ($infoCh) $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), $plateId]);
    return $done;
}

// ---------------- مقایسه و نشاندن: پرونده‌ی کارکنان ----------------
// ستون‌های خودِ پرونده (اگر هست) اولویت دارند؛ بقیه در اطلاعاتِ صدور (issue_info)
const DP_CASE_COLS = ['insured_name', 'insured_national_id', 'insured_phone', 'insured_postal_code', 'insured_address', 'car_system', 'car_type', 'car_model_year', 'car_color', 'car_usage'];
function dp_case_map($kind, $issued = false) {
    $map = ['car_system' => 'car_system', 'car_tip' => 'car_type', 'model_year' => 'car_model_year', 'color' => 'car_color', 'usage' => 'car_usage', 'capacity' => 'car_capacity', 'cylinders' => 'car_cylinders'];
    if (in_array($kind, ['car_card', 'ownership_doc', 'sales_invoice'], true)) $map += ['owner_name' => 'owner_name', 'national_id' => 'owner_national_id', 'mobile' => 'owner_phone', 'address' => 'owner_address'];
    if ($kind === 'policy' && !$issued) $map += ['policy_number' => 'prev_policy_number', 'insurer' => 'prev_insurer', 'end_date' => 'prev_expiry'];
    if ($kind === 'national_card') $map = ['owner_name' => 'owner_name', 'national_id' => 'owner_national_id', 'father_name' => 'owner_father', 'birth_date' => 'owner_birth_date'];
    if ($kind === 'personnel') $map = ['insured_name' => 'insured_name', 'national_id' => 'insured_national_id'];
    return $map;
}
function dp_case_labels() {
    return ['car_system' => 'سیستم', 'car_type' => 'تیپ', 'car_model_year' => 'مدل', 'car_color' => 'رنگ', 'car_usage' => 'کاربری', 'car_capacity' => 'ظرفیت', 'car_cylinders' => 'تعداد سیلندر',
            'owner_name' => 'نام مالک', 'owner_national_id' => 'کد ملیِ مالک', 'owner_phone' => 'تلفنِ مالک', 'owner_address' => 'نشانیِ مالک', 'owner_father' => 'نام پدر', 'owner_birth_date' => 'تاریخ تولد',
            'prev_policy_number' => 'بیمه‌نامه‌ی قبلی', 'prev_insurer' => 'بیمه‌گرِ قبلی', 'prev_expiry' => 'انقضای بیمه‌نامه‌ی قبلی', 'insured_name' => 'نامِ بیمه‌گذار', 'insured_national_id' => 'کد ملیِ بیمه‌گذار'];
}
function dp_compare_case($pdo, $caseId, array $d, $kind, $issued = false) {
    require_once __DIR__ . '/_vdoc.php';
    $st = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?"); $st->execute([$caseId]);
    $c = $st->fetch();
    if (!$c) return [];
    $ii = issue_info_decode($c['issue_info'] ?? null); $lab = dp_case_labels(); $out = [];
    $add = function ($field, $label, $row, $doc, $code = false) use (&$out) {
        $row = trim((string)$row); $doc = trim((string)$doc);
        if ($doc === '') return;
        $out[] = ['field' => $field, 'label' => $label, 'row' => $row, 'doc' => $doc, 'same' => $row !== '' && ($code ? vdoc_code_like($row, $doc) === true : vdoc_same_value($row, $doc))];
    };
    if ($kind !== 'national_card' && $kind !== 'personnel') {
        $add('__chassis', 'شماره شاسی / VIN', ($c['chassis_num'] ?? '') ?: ($c['vin'] ?? ''), vdoc_clean_code(($d['chassis_no'] ?? '') ?: ($d['vin'] ?? '')), true);
        $add('__engine', 'شماره موتور', $c['engine_num'] ?? '', vdoc_clean_code($d['engine_no'] ?? ''), true);
        if ($kind !== 'sales_invoice' && ($pi = dp_plate_item($c['plate'] ?? '', $d, $kind))) $out[] = $pi;
    }
    foreach (dp_case_map($kind, $issued) as $from => $to) {
        $row = (in_array($to, DP_CASE_COLS, true) && trim((string)($c[$to] ?? '')) !== '') ? $c[$to] : ($ii[$to] ?? '');
        $add($to, $lab[$to] ?? $to, $row, is_scalar($d[$from] ?? null) ? mb_substr((string)$d[$from], 0, 250) : '');
    }
    return $out;
}
function dp_apply_case($pdo, $caseId, array $vals) {
    require_once __DIR__ . '/_vdoc.php';
    $st = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?"); $st->execute([$caseId]);
    $c = $st->fetch();
    if (!$c) return [];
    $ii = issue_info_decode($c['issue_info'] ?? null); $iiCh = false; $done = []; $lab = dp_case_labels();
    foreach ($vals as $field => $v) {
        $field = (string)$field; $v = trim((string)$v);
        if ($v === '') continue;
        if ($field === '__chassis') { $x = vdoc_clean_code($v); $pdo->prepare("UPDATE policy_cases SET chassis_num = ?, vin = ? WHERE id = ?")->execute([$x, strlen($x) === 17 ? $x : ($c['vin'] ?? null), $caseId]); $done['شماره شاسی / VIN'] = $x; }
        elseif ($field === '__engine') { $x = vdoc_clean_code($v); $pdo->prepare("UPDATE policy_cases SET engine_num = ? WHERE id = ?")->execute([$x, $caseId]); $done['شماره موتور'] = $x; }
        elseif ($field === '__plate') {
            if (!function_exists('company_parse_plate_text')) require_once __DIR__ . '/_company_helpers.php';
            $pp = company_parse_plate_text($v);
            if (!$pp) throw new RuntimeException('پلاکِ «' . $v . '» خوانا نیست؛ مثلِ «۲۱ایران - ۷۱۱ د ۱۶» بنویسید.');
            $txt = company_plate_display($pp['p1'], $pp['p2'], $pp['letter'], $pp['p4']);
            $pdo->prepare("UPDATE policy_cases SET plate = ? WHERE id = ?")->execute([$txt, $caseId]); $done['پلاک'] = $txt;
        } elseif (isset($lab[$field])) {
            if (in_array($field, DP_CASE_COLS, true) && array_key_exists($field, $c)) $pdo->prepare("UPDATE policy_cases SET `$field` = ? WHERE id = ?")->execute([mb_substr($v, 0, 250), $caseId]);
            $ii[$field] = mb_substr($v, 0, 250); $iiCh = true;
            $done[$lab[$field]] = $v;
        }
    }
    if ($iiCh) $pdo->prepare("UPDATE policy_cases SET issue_info = ? WHERE id = ?")->execute([json_encode($ii, JSON_UNESCAPED_UNICODE), $caseId]);
    return $done;
}

// ---------------- خروجی برای صفحه ----------------
// همه‌ی روش‌های یک مدرک با مقایسه: ['methods' => [smart|slow|fast => نتیجه یا null], 'available' => …]
function dp_views($pdo, $ref, callable $compare) {
    require_once __DIR__ . '/_vdoc.php';
    $rows = dp_get($pdo, $ref);
    $out = [];
    foreach (DP_METHODS as $m => $fa) {
        $r = $rows[$m] ?? null;
        if (!$r) { $out[$m] = null; continue; }
        $d = json_decode((string)($r['data_json'] ?? ''), true) ?: [];
        $edited = json_decode((string)($r['edited_json'] ?? ''), true) ?: [];
        $cmp = in_array($r['status'], ['OK', 'MISMATCH'], true) && $d ? $compare($d, (string)$r['kind']) : [];
        // مقدارِ ویرایش‌شده‌ی کاربر ملاک است: «یکی» یعنی ردیف با همان مقدارِ ویرایش‌شده می‌خواند
        $norm = function ($v) { return preg_replace('/\s+/u', ' ', trim(strtr((string)$v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', 'ي' => 'ی', 'ك' => 'ک', "\u{200c}" => ' ']))); };
        foreach ($cmp as &$x) {
            if (!isset($edited[$x['field']])) continue;
            $x['edited'] = (string)$edited[$x['field']];
            if ($x['field'] === '__plate') { $x['same'] = $x['row'] !== '' && dp_plate_same($x['row'], $x['edited']); unset($x['unread']); }
            elseif (in_array($x['field'], DP_ID_FIELDS, true)) $x['same'] = $x['row'] !== '' && vdoc_code_like($x['row'], $x['edited']) === true;
            else $x['same'] = $x['row'] !== '' && ($norm($x['row']) === $norm($x['edited']) || vdoc_same_value($x['row'], $x['edited']));
        }
        unset($x);
        $used = array_flip(array_map(function ($x) { return $x['field']; }, $cmp));
        $other = vdoc_fields_fa((string)$r['kind'], $d);
        $out[$m] = ['method' => $m, 'method_fa' => $fa, 'status' => $r['status'], 'kind' => (string)$r['kind'], 'kind_fa' => (string)$r['kind_fa'],
                    'at' => function_exists('jalali_from_gregorian_ts_dotted') ? jalali_from_gregorian_ts_dotted(strtotime($r['updated_at'])) . ' ' . date('H:i', strtotime($r['updated_at'])) : (string)$r['updated_at'],
                    'user' => (string)$r['user_name'], 'ms' => intval($r['ms']), 'engines' => array_values(array_filter(explode(',', (string)$r['engines']))), 'error' => (string)$r['error'],
                    'compare' => $cmp, 'other' => $other, 'n_diff' => count(array_filter($cmp, function ($x) { return !$x['same']; })), 'mismatch' => dp_mismatch($cmp)];
    }
    return ['methods' => $out, 'available' => dp_available($pdo), 'method_names' => DP_METHODS];
}
