<?php
// فایل: api/_proposal.php
// «پیشنهاد / پیش‌نویسِ بیمه‌نامه» در صدورِ کارکنان: خواندن با api/_proposal_reader.php، بایگانی کنارِ مدارکِ درخواست
// و نگه‌داشتن در policy_cases.proposal_json (ستون خودکار ساخته می‌شود؛ به داده‌های قبلی دست نمی‌زند).
// از این اطلاعات: فرمِ صدور پیش‌پر می‌شود، فایلِ بیمه‌نامه با آن مقایسه می‌شود و بعد از صدور در جزئیات دیده می‌شود.

function proposal_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try { if (!$pdo->query("SHOW COLUMNS FROM policy_cases LIKE 'proposal_json'")->fetch()) $pdo->exec("ALTER TABLE policy_cases ADD COLUMN proposal_json MEDIUMTEXT NULL"); }
    catch (Throwable $e) { error_log('[proposal_ensure] ' . $e->getMessage()); }
}

function proposal_get($pdo, $caseId) {
    proposal_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT proposal_json FROM policy_cases WHERE id = ?");
        $st->execute([intval($caseId)]);
        $j = json_decode((string)$st->fetchColumn(), true);
        return is_array($j) ? $j : null;
    } catch (Throwable $e) { return null; }
}

// اجرای خواننده (PHP: api/_proposal_reader.php؛ قبلاً api/py/proposal_reader.py)
function proposal_run($pdo, $absPdf) {
    require_once __DIR__ . '/_proposal_reader.php';
    try { return prp_read($absPdf); }
    catch (Throwable $e) { error_log('[proposal_run] ' . $e->getMessage()); return ['ok' => false, 'error' => 'خواندنِ پیشنهاد ممکن نشد.', 'debug' => mb_substr($e->getMessage(), 0, 300)]; }
}

// نسبتِ بیمه‌گذار و مالک با پرسنلِ معرفی‌نامه از روی پیشنهاد:
//   self_owner   = بیمه‌گذار خودِ پرسنل و مالک هم خودش
//   self_relative= بیمه‌گذار خودِ پرسنل، مالک یکی از بستگان (نامِ مالک = «ذینفع»)
//   relative     = بیمه‌گذار خودِ بستگان (نسبت را کارشناس انتخاب می‌کند)
function proposal_relation(array $d, array $holder) {
    $nid = preg_replace('/\D/', '', (string)($d['national_id'] ?? ''));
    $hn = preg_replace('/\D/', '', (string)($holder['national_code'] ?? ''));
    $insuredIsHolder = $nid !== '' ? $nid === $hn : (trim((string)($d['insured_name'] ?? '')) === trim((string)($holder['full_name'] ?? '')));
    // «پیش‌نویس» سؤالِ «بیمه‌گذار مالک است؟» ندارد: اگر ذینفعی جدا از بیمه‌گذار دارد، مالک همان ذینفع است
    $ben = trim((string)($d['beneficiary'] ?? ''));
    $isOwner = isset($d['insured_is_owner']) ? (bool)$d['insured_is_owner'] : ($ben === '' || $ben === trim((string)($d['insured_name'] ?? '')));
    if ($insuredIsHolder && $isOwner) return ['mode' => 'self_owner', 'owner_name' => $d['insured_name'] ?? ''];
    if ($insuredIsHolder) return ['mode' => 'self_relative', 'owner_name' => $d['beneficiary'] ?? '', 'owner_gender' => $d['beneficiary_gender'] ?? ''];
    return ['mode' => 'relative', 'owner_name' => $isOwner ? ($d['insured_name'] ?? '') : ($d['beneficiary'] ?? ''), 'insured_gender' => $d['insured_gender'] ?? ''];
}

// =====================================================================
//  «پیشنهادِ گروهی» برای درخواست‌های شرکتی: یک PDF با چند پیشنهاد/پیش‌نویس => هر صفحه خوانده و با ردیفِ همان درخواست
//  (شاسی، موتور یا پلاک) تطبیق داده می‌شود؛ روی ردیف در company_request_plates.proposal_json می‌نشیند و فایلش در پوشه‌ی ردیف.
//  فرمِ صدورِ همان ردیف با آن پیش‌پر می‌شود (مثلِ کارکنان). ردیفِ صادرشده دست نمی‌خورد.
// =====================================================================
function cproposal_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try { if (!$pdo->query("SHOW COLUMNS FROM company_request_plates LIKE 'proposal_json'")->fetch()) $pdo->exec("ALTER TABLE company_request_plates ADD COLUMN proposal_json MEDIUMTEXT NULL"); }
    catch (Throwable $e) { error_log('[cproposal_ensure] ' . $e->getMessage()); }
}

// ردیفِ همین درخواست که با داده‌ی پیشنهاد می‌خواند (اول شاسی، بعد موتور، بعد پلاک) => [row, 'شاسی'|'موتور'|'پلاک'] یا [null, '']
function cproposal_match($pdo, $requestId, array $d) {
    if (!function_exists('dup_clean')) require_once __DIR__ . '/_dup.php';
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ? ORDER BY id");
    $st->execute([intval($requestId)]);
    $rows = $st->fetchAll();
    $vin = dup_clean($d['vin'] ?? ''); $eng = dup_clean($d['engine_no'] ?? ''); $pk = dup_plate_key($d['plate'] ?? '');
    $type = '';
    $t = (string)($d['ins_type'] ?? '');
    if (preg_match('/بدنه/u', $t)) $type = 'BODY'; elseif (preg_match('/ثالث/u', $t)) $type = 'THIRDPARTY';
    $pick = function ($list) use ($type) {
        if (!$list) return null;
        if ($type) foreach ($list as $r) if ($r['insurance_type'] === $type) return $r;
        return count($list) === 1 || !$type ? $list[0] : null;
    };
    if (strlen($vin) >= 6 && ($r = $pick(array_values(array_filter($rows, fn($r) => in_array($vin, [dup_clean($r['chassis_no'] ?? ''), dup_clean($r['vin'] ?? '')], true)))))) return [$r, 'شاسی'];
    if (strlen($eng) >= 6 && ($r = $pick(array_values(array_filter($rows, fn($r) => dup_clean($r['engine_no'] ?? '') === $eng))))) return [$r, 'موتور'];
    if ($pk !== '' && ($r = $pick(array_values(array_filter($rows, fn($r) => dup_plate_key_parts($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) === $pk))))) return [$r, 'پلاک'];
    return [null, ''];
}

// صفحه‌ی خوانده‌شده را روی ردیف می‌نشاند: کپی در پوشه‌ی ردیف «(پیشنهاد) ...» + proposal_json (نسخه‌ی قبلی «(قبلی)» می‌شود)
function cproposal_attach($pdo, $siteRoot, $plateId, $pagePdf, array $r, $userId) {
    cproposal_ensure($pdo);
    if (!function_exists('dup_clean')) require_once __DIR__ . '/_dup.php';
    if (!function_exists('ensure_plate_folder')) require_once __DIR__ . '/_company_helpers.php';
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE id = ?");
    $st->execute([intval($plateId)]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    if ($row['status'] === 'ISSUED') return ['ok' => false, 'error' => 'این ردیف صادر شده است.'];
    $kindFa = ($r['kind'] ?? '') === 'proposal' ? 'پیشنهاد' : 'پیش‌نویس';
    $file = null;
    $folder = ensure_plate_folder($pdo, $siteRoot, intval($plateId));
    if ($folder && is_file($pagePdf)) {
        $old = json_decode((string)($row['proposal_json'] ?? ''), true);
        if (is_array($old) && !empty($old['file']) && is_file($siteRoot . '/' . $old['file'])) { $oa = $siteRoot . '/' . $old['file']; @rename($oa, unique_dest_path(dirname($oa) . '/(قبلی) ' . basename($oa))); }
        $dest = unique_dest_path($folder . '/' . sanitize_folder_name('(' . $kindFa . ') ' . company_row_label($row)) . '.pdf');
        if (@copy($pagePdf, $dest)) $file = ltrim(str_replace($siteRoot, '', $dest), '/');
    }
    $prop = ['kind' => $r['kind'] ?? 'proposal', 'kind_fa' => $kindFa, 'data' => $r['data'] ?? [], 'missing' => $r['missing'] ?? [], 'file' => $file, 'at' => date('Y-m-d H:i:s'), 'by' => $userId];
    $pdo->prepare("UPDATE company_request_plates SET proposal_json = ? WHERE id = ?")->execute([json_encode($prop, JSON_UNESCAPED_UNICODE), intval($plateId)]);
    // خانه‌های خالیِ ردیف (شاسی/موتور/نام خودرو) از روی پیشنهاد پر می‌شوند؛ مقدارِ موجود دست نمی‌خورد
    $d = $r['data'] ?? [];
    // شماره موتورِ «پیشنهاد» گاهی بریده است: اگر مقدارِ تازه با مقدارِ موجود شروع شود و بلندتر باشد، کامل‌تر جایگزین می‌شود
    $newEng = dup_clean($d['engine_no'] ?? ''); $curEng = dup_clean($row['engine_no'] ?? '');
    $eng = ($curEng === '' || (strlen($newEng) > strlen($curEng) && strpos($newEng, $curEng) === 0)) ? $newEng : '';
    $pdo->prepare("UPDATE company_request_plates SET chassis_no = COALESCE(NULLIF(chassis_no, ''), NULLIF(?, '')), engine_no = COALESCE(NULLIF(?, ''), engine_no) WHERE id = ?")
        ->execute([dup_clean($d['vin'] ?? ''), $eng, intval($plateId)]);
    return ['ok' => true, 'file' => $file, 'label' => company_row_label($row), 'type' => $row['insurance_type']];
}
