<?php
// فایل: api/_zerokm.php
// خودروهای صفر کیلومتر (فاکتور فروش): شرکت اکسلِ خودروها + یک PDF با چند فاکتورِ پشتِ سرِ هم می‌فرستد.
//  - اکسل با هر چیدمانی خوانده می‌شود (سرستون‌ها از روی نام: شاسی، موتور، اتاق، مشتری، نوع محصول، ارزش)
//  - PDF صفحه‌به‌صفحه جدا و هر صفحه با vehicle_doc_reader.py خوانده می‌شود (هر درخواست یک صفحه)
//  - در مرورگر با اکسل تطبیق داده می‌شود (شاسی، بعد موتور، بعد تقریبی) و با ثبتِ درخواست برای هر خودرو یک ردیف
//    ساخته و فاکتورش روی همان ردیف بایگانی می‌شود؛ شماره موتور/شاسی بدونِ . * - و فاصله ذخیره می‌شود.
require_once __DIR__ . '/_pdf_split.php';
require_once __DIR__ . '/_vdoc.php';

function zk_owner($companyUserId) { return 'zk' . intval($companyUserId); }

// اکسلِ خودروها => [{chassis, engine, engine_raw, cab, customer, product, value}]
function zk_parse_excel($path) {
    if (!function_exists('fin_read_spreadsheet')) require_once __DIR__ . '/finance_core.php';
    $rows = fin_read_spreadsheet($path);
    if (!is_array($rows) || !$rows) return ['ok' => false, 'error' => 'اکسل خوانده نشد یا خالی است.'];
    $syn = ['chassis' => '/شاسی|chassis|vin|شناسایی/iu', 'engine' => '/موتور|engine/iu', 'cab' => '/اتاق|cab/iu',
            'customer' => '/مشتری|خریدار|مالک|customer|buyer|نام/iu', 'product' => '/محصول|خودرو|نوع|مدل|product|model|vehicle/iu',
            'value' => '/ارزش|قیمت|مبلغ|price|value/iu', 'national_id' => '/کد\s*ملی|شناسه\s*ملی|national/iu'];
    $hdr = null; $map = [];
    foreach (array_slice($rows, 0, 12, true) as $ri => $r) {
        $m = [];
        foreach ((array)$r as $ci => $cell) {
            $t = trim((string)$cell);
            if ($t === '' || mb_strlen($t) > 40) continue;
            foreach ($syn as $k => $rx) if (!isset($m[$k]) && preg_match($rx, $t)) {
                if ($k === 'customer' && preg_match($syn['chassis'], $t)) continue;
                if ($k === 'product' && (preg_match('/شاسی|موتور|مشتری/u', $t))) continue;
                $m[$k] = $ci; break;
            }
        }
        if (isset($m['chassis']) || isset($m['engine'])) { $hdr = $ri; $map = $m; break; }
    }
    if ($hdr === null) return ['ok' => false, 'error' => 'ستونِ «شماره شاسی» یا «شماره موتور» در اکسل پیدا نشد.'];
    $out = [];
    foreach (array_slice($rows, $hdr + 1) as $r) {
        $g = function ($k) use ($r, $map) { return isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : ''; };
        $ch = vdoc_clean_code($g('chassis')); $en = vdoc_clean_code($g('engine'));
        if ($ch === '' && $en === '') continue;
        $out[] = ['chassis' => $ch, 'engine' => $en, 'engine_raw' => $g('engine'), 'cab' => vdoc_clean_code($g('cab')),
                  'customer' => $g('customer'), 'product' => $g('product'), 'national_id' => preg_replace('/\D/', '', p2e_digits($g('national_id'))),
                  'value' => preg_replace('/\D/', '', p2e_digits($g('value')))];
    }
    if (!$out) return ['ok' => false, 'error' => 'در اکسل ردیفی با شماره شاسی یا موتور نبود.'];
    return ['ok' => true, 'rows' => $out, 'columns' => array_keys($map)];
}

// خواندنِ یک صفحه از PDFِ فاکتورها (نتیجه کنارِ صفحه نگه داشته می‌شود تا هنگامِ ثبت دوباره خوانده نشود)
function zk_read_page($pdo, $siteRoot, $token, $owner, $n) {
    $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
    if (!$p) return ['ok' => false, 'error' => 'صفحه پیدا نشد (شاید زمانِ فایل گذشته است؛ دوباره بارگذاری کنید).'];
    $cache = dirname($p) . sprintf('/r%03d.json', $n);
    if (is_file($cache)) { $j = json_decode((string)file_get_contents($cache), true); if (is_array($j)) return $j; }
    $r = vdoc_run($pdo, $p);
    if (!empty($r['ok'])) @file_put_contents($cache, json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r;
}
function zk_page_result($siteRoot, $token, $owner, $n) {
    $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
    if (!$p) return null;
    $j = json_decode((string)@file_get_contents(dirname($p) . sprintf('/r%03d.json', $n)), true);
    return is_array($j) ? $j : null;
}

// فاکتورِ صفحه‌ی n روی ردیف: کپی در پوشه‌ی ردیف + مدرکِ «فاکتور فروش» + اطلاعاتِ خریدار در «اطلاعات صدور»
function zk_attach_page($pdo, $siteRoot, $token, $owner, $n, $plateId, $companyId, $requestId, $userId, $extra = []) {
    $p = pdf_split_job_page_path($siteRoot, $token, $owner, $n, 'pdf');
    if (!$p) return false;
    if (!function_exists('company_unassigned_temp_path')) require_once __DIR__ . '/_company_helpers.php';
    $st = $pdo->prepare("SELECT name FROM companies WHERE id = ?"); $st->execute([$companyId]);
    $tmpDir = company_unassigned_temp_path($siteRoot, $st->fetchColumn() ?: 'نامشخص');
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $dest = unique_dest_path($tmpDir . '/فاکتور فروش - صفحه ' . intval($n) . '.pdf');
    if (!@copy($p, $dest)) return false;
    $res = zk_page_result($siteRoot, $token, $owner, $n);
    vdoc_ensure($pdo);
    $rel = ltrim(str_replace($siteRoot, '', $dest), '/');
    if ($plateId) {
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status, assigned_at, ai_status, ai_json)
                       VALUES (?, ?, ?, ?, ?, ?, 'SUPPORTING_DOC', 'sales_invoice', 'ASSIGNED', NOW(), 'AUTO', ?)")
            ->execute([$companyId, $requestId, $plateId, $userId, $rel, 'فاکتور فروش - صفحه ' . intval($n) . '.pdf', $res ? json_encode($res, JSON_UNESCAPED_UNICODE) : null]);
        $docId = intval($pdo->lastInsertId());
        company_place_document($pdo, $siteRoot, $docId, $plateId, 'sales_invoice');
        $d = array_merge((array)($res['data'] ?? []), array_filter($extra, 'strlen'));
        vdoc_apply_to_row($pdo, $plateId, $d);
        company_sync_plate_status($pdo, $plateId);
        return $docId;
    }
    // فاکتوری که با هیچ خودرویی تطبیق نخورد: در صندوقِ مدارک با همان نتیجه‌ی شناسایی (برای بررسیِ کارشناس)
    $pdo->prepare("INSERT INTO company_documents (company_id, request_id, uploaded_by, file_path, orig_name, file_kind, doc_type, status, ai_status, ai_json)
                   VALUES (?, ?, ?, ?, ?, 'SUPPORTING_DOC', 'sales_invoice', 'UNASSIGNED', ?, ?)")
        ->execute([$companyId, $requestId, $userId, $rel, 'فاکتور فروش - صفحه ' . intval($n) . '.pdf', $res ? 'PARTIAL' : 'PENDING', $res ? json_encode($res, JSON_UNESCAPED_UNICODE) : null]);
    return intval($pdo->lastInsertId());
}
