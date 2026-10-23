<?php
// فایل: api/_party.php
// «مالک / بیمه‌گذار / ذینفع»ِ هر ردیفِ درخواستِ شرکتی.
//   COMPANY      : خودرو به نامِ خودِ شرکت است  => مالک و بیمه‌گذار هر دو شرکت
//   BENEFICIARY  : خودرو به نامِ شخص/شرکتِ دیگری است و شرکتِ درخواست‌دهنده بیمه‌گذار می‌شود => آن شخص «ذینفع»
//   OWNER        : خودرو به نامِ شخص/شرکتِ دیگری است و بیمه‌گذار هم همان مالک می‌شود
//   SPLIT        : مالک (= ذینفع) یک شخص/شرکت و بیمه‌گذار شخص/شرکتِ دیگری (insured_json)؛ شرکتِ درخواست‌دهنده فقط نامه را داده
// برای هر ردیف جدا (party_mode / party_json) و برای کلِ درخواست (پیش‌فرضِ ردیف‌های بی‌تنظیم) تعیین می‌شود؛
// ستون‌ها خودکار و افزودنی ساخته می‌شوند (ردیف‌های قبلی بی‌تنظیم = «خودِ شرکت»، مثلِ رفتارِ قبلی).

const PARTY_MODES = [
    'COMPANY' => 'مالک و بیمه‌گذار: خودِ شرکت',
    'BENEFICIARY' => 'به نامِ دیگری (ذینفع) · بیمه‌گذار: شرکت',
    'OWNER' => 'به نامِ دیگری · بیمه‌گذار: همان مالک',
    'SPLIT' => 'مالک و ذینفع: یک شخص/شرکت · بیمه‌گذار: شخص/شرکتِ دیگر',
];
const PARTY_MODES_SHORT = ['COMPANY' => 'خودِ شرکت', 'BENEFICIARY' => 'ذینفع', 'OWNER' => 'مالک = بیمه‌گذار', 'SPLIT' => 'بیمه‌گذار جدا'];

function party_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['company_request_plates', 'company_requests'] as $t) {
        foreach (['party_mode' => 'VARCHAR(16) NULL', 'party_json' => 'TEXT NULL', 'insured_json' => 'TEXT NULL'] as $c => $def) {
            try { if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); }
            catch (Throwable $e) { error_log('[party_ensure] ' . $e->getMessage()); }
        }
    }
}

function party_valid_mode($m) {
    $m = strtoupper(trim((string)$m));
    return isset(PARTY_MODES[$m]) ? $m : null;
}

// اطلاعاتِ «شخص/شرکتِ دیگر» (مالک): kind = PERSON | COMPANY
function party_clean($in) {
    if (is_string($in)) $in = json_decode($in, true);
    if (!is_array($in)) return null;
    $d = function ($v) { return trim(strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'])); };
    $out = [
        'kind' => strtoupper((string)($in['kind'] ?? '')) === 'COMPANY' ? 'COMPANY' : 'PERSON',
        'name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 190),
        'national_id' => mb_substr(preg_replace('/[^0-9A-Za-z]/', '', $d($in['national_id'] ?? '')), 0, 20),
        'phone' => mb_substr(preg_replace('/[^0-9+]/', '', $d($in['phone'] ?? '')), 0, 20),
        'postal_code' => mb_substr(preg_replace('/\D/', '', $d($in['postal_code'] ?? '')), 0, 10),
        'address' => mb_substr(trim((string)($in['address'] ?? '')), 0, 400),
    ];
    if ($out['name'] === '' && $out['national_id'] === '') return null;
    // شناسه‌ی ملیِ ۱۱ رقمی یعنی شخصِ حقوقی
    if (strlen($out['national_id']) === 11 && empty($in['kind'])) $out['kind'] = 'COMPANY';
    return $out;
}

// ذخیره روی ردیف‌ها؛ $party === false یعنی «اطلاعاتِ شخص دست نخورد» (فقط نوع عوض شود)
// $insured: بیمه‌گذارِ جدا (فقط حالتِ SPLIT)؛ false = دست نخورد. حالت‌های دیگر بیمه‌گذارِ جدا را پاک می‌کنند.
function party_set_rows($pdo, array $plateIds, $mode, $party = false, $insured = false) {
    party_ensure($pdo);
    $mode = party_valid_mode($mode);
    if (!$mode || !$plateIds) return 0;
    $n = 0;
    foreach (array_unique(array_map('intval', $plateIds)) as $pid) {
        if ($pid <= 0) continue;
        if ($mode !== 'SPLIT') $pdo->prepare("UPDATE company_request_plates SET insured_json = NULL WHERE id = ?")->execute([$pid]);
        elseif ($insured !== false) { $ij = party_clean($insured); $pdo->prepare("UPDATE company_request_plates SET insured_json = ? WHERE id = ?")->execute([$ij ? json_encode($ij, JSON_UNESCAPED_UNICODE) : null, $pid]); }
        if ($party === false) {
            $st = $pdo->prepare("UPDATE company_request_plates SET party_mode = ? WHERE id = ?");
            $st->execute([$mode, $pid]);
        } else {
            $pj = $mode === 'COMPANY' ? null : party_clean($party);
            $st = $pdo->prepare("UPDATE company_request_plates SET party_mode = ?, party_json = ? WHERE id = ?");
            $st->execute([$mode, $pj ? json_encode($pj, JSON_UNESCAPED_UNICODE) : null, $pid]);
        }
        $n++;
    }
    return $n;
}

// بعد از تغییرِ مالک/بیمه‌گذار (کلیِ درخواست یا چند ردیف): «اطلاعات برای صدور» (issue_info) هم با همان مقادیرِ تازه هم‌سان می‌شود
// تا پنلِ صدور و همه‌ی بخش‌ها اطلاعاتِ آخر را نشان دهند (ردیف‌های صادرشده دست نمی‌خورند)
function party_sync_issue_info($pdo, array $plateIds) {
    $plateIds = array_values(array_filter(array_unique(array_map('intval', $plateIds))));
    if (!$plateIds) return;
    $in = implode(',', array_fill(0, count($plateIds), '?'));
    $st = $pdo->prepare("SELECT crp.id, crp.status, crp.party_mode, crp.party_json, crp.insured_json, crp.issue_info, cr.party_mode AS req_party_mode, cr.party_json AS req_party_json, cr.insured_json AS req_insured_json,
                                c.name, c.national_id, c.economic_code, c.phone, c.address
                           FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE crp.id IN ($in)");
    $st->execute($plateIds);
    $up = $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?");
    foreach ($st->fetchAll() as $r) {
        if ($r['status'] === 'ISSUED') continue;
        $ii = json_decode((string)$r['issue_info'], true) ?: [];
        $old = $ii;
        $pr = party_resolve($r, $r, ['party_mode' => $r['req_party_mode'], 'party_json' => $r['req_party_json'], 'insured_json' => $r['req_insured_json']]);
        $set = function ($prefix, $p) use (&$ii) {
            $map = ['name' => 'name', 'national_id' => 'national_id', 'phone' => 'phone', 'address' => 'address', 'postal_code' => 'postal_code'];
            foreach ($map as $k => $f) {
                $v = trim((string)($p[$f] ?? ''));
                if ($k === 'national_id' && $v === '' && !empty($p['economic_code'])) $v = trim((string)$p['economic_code']);
                if ($v !== '') $ii[$prefix . $k] = $v; else unset($ii[$prefix . $k]);
            }
        };
        $set('insured_', $pr['insured'] ?: []);
        if (!empty($pr['other'])) { $set('owner_', $pr['other']); unset($ii['owner_postal_code']); }
        if ($pr['beneficiary']) { $ii['beneficiary'] = (string)($pr['beneficiary']['name'] ?? ''); $ii['beneficiary_national_id'] = (string)($pr['beneficiary']['national_id'] ?? ''); }
        else unset($ii['beneficiary'], $ii['beneficiary_national_id']);
        $ii = array_filter($ii, function ($v) { return $v !== '' && $v !== null; });
        if ($ii != $old) $up->execute([$ii ? json_encode($ii, JSON_UNESCAPED_UNICODE) : null, $r['id']]);
    }
}

function party_set_request_default($pdo, $requestId, $mode, $party = null, $insured = null) {
    party_ensure($pdo);
    $mode = party_valid_mode($mode);
    if (!$mode) return;
    $pj = $mode === 'COMPANY' ? null : party_clean($party);
    $ij = $mode === 'SPLIT' ? party_clean($insured) : null;
    $pdo->prepare("UPDATE company_requests SET party_mode = ?, party_json = ?, insured_json = ? WHERE id = ?")
        ->execute([$mode, $pj ? json_encode($pj, JSON_UNESCAPED_UNICODE) : null, $ij ? json_encode($ij, JSON_UNESCAPED_UNICODE) : null, intval($requestId)]);
}

// ورودیِ فرم (ثبتِ درخواست): هر ردیف party_mode/party اختیاری؛ وگرنه پیش‌فرضِ کلی
function party_apply_from_input($pdo, $plateId, array $rowIn, $defMode = null, $defParty = null, $defInsured = null) {
    $mode = party_valid_mode($rowIn['party_mode'] ?? '') ?: party_valid_mode($defMode);
    if (!$mode) return;
    $party = $rowIn['party'] ?? null;
    if (!party_clean($party)) $party = $defParty;
    $insured = $rowIn['insured'] ?? null;
    if (!party_clean($insured)) $insured = $defInsured;
    party_set_rows($pdo, [$plateId], $mode, $party, $mode === 'SPLIT' ? $insured : false);
}

// نقش‌های نهاییِ یک ردیف: ['mode','mode_fa','owner','insured','beneficiary','mismatch']
//   $plate: ردیف (با party_mode, party_json, issue_info) ؛ $company: ['name','economic_code','phone','address']
//   $req: درخواست (party_mode, party_json) برای پیش‌فرض
function party_resolve(array $plate, array $company, array $req = []) {
    $mode = party_valid_mode($plate['party_mode'] ?? '') ?: party_valid_mode($req['party_mode'] ?? '') ?: 'COMPANY';
    $explicit = (bool)party_valid_mode($plate['party_mode'] ?? '');   // false = طبقِ انتخابِ کلیِ درخواست
    $other = party_clean($plate['party_json'] ?? null) ?: (party_valid_mode($plate['party_mode'] ?? '') ? null : party_clean($req['party_json'] ?? null));
    $ii = json_decode((string)($plate['issue_info'] ?? ''), true) ?: [];
    // مالکِ خوانده‌شده از کارت/سند/فاکتور (اگر اطلاعاتِ شخص وارد نشده)
    $docOwner = party_clean(['name' => $ii['owner_name'] ?? '', 'national_id' => $ii['owner_national_id'] ?? '', 'phone' => $ii['owner_phone'] ?? '', 'address' => $ii['owner_address'] ?? '']);
    $co = ['kind' => 'COMPANY', 'name' => (string)($company['name'] ?? ''), 'national_id' => (string)($company['national_id'] ?? ''),
           'economic_code' => (string)($company['economic_code'] ?? ''), 'phone' => (string)($company['phone'] ?? ''), 'address' => (string)($company['address'] ?? ''), 'postal_code' => ''];
    // بیمه‌گذارِ جدا (SPLIT): ردیف، وگرنه پیش‌فرضِ درخواست
    $insOther = party_clean($plate['insured_json'] ?? null) ?: (party_valid_mode($plate['party_mode'] ?? '') ? null : party_clean($req['insured_json'] ?? null));
    if ($mode === 'COMPANY') {
        $owner = $co; $insured = $co; $ben = null;
    } elseif ($mode === 'SPLIT') {
        $owner = $other ?: $docOwner ?: ['kind' => 'PERSON', 'name' => '', 'national_id' => '', 'phone' => '', 'address' => '', 'postal_code' => ''];
        $insured = $insOther ?: ['kind' => 'PERSON', 'name' => '', 'national_id' => '', 'phone' => '', 'address' => '', 'postal_code' => ''];
        $ben = $owner;
    } else {
        $o = $other ?: $docOwner ?: ['kind' => 'PERSON', 'name' => '', 'national_id' => '', 'phone' => '', 'address' => '', 'postal_code' => ''];
        $owner = $o;
        $insured = $mode === 'OWNER' ? $o : $co;
        $ben = $mode === 'BENEFICIARY' ? $o : null;
    }
    // هشدار: مالکِ مدرک با «خودِ شرکت» نمی‌خواند (احتمالاً باید «ذینفع» یا «مالک = بیمه‌گذار» باشد)
    $norm = function ($s) { return preg_replace('/[\s\x{200c}\-ـ«»"\'().،,]+/u', '', str_replace(['ي', 'ك', 'شرکت', 'سهامی', 'خاص', 'عام'], ['ی', 'ک', '', '', '', ''], (string)$s)); };
    $mismatch = false;
    if ($docOwner && $mode === 'COMPANY' && $docOwner['name'] !== '' && $co['name'] !== '') {
        $a = $norm($docOwner['name']); $b = $norm($co['name']);
        $mismatch = $a !== '' && $b !== '' && mb_strpos($a, $b) === false && mb_strpos($b, $a) === false;
    }
    return ['mode' => $mode, 'mode_fa' => PARTY_MODES[$mode], 'mode_short' => PARTY_MODES_SHORT[$mode], 'explicit' => $explicit,
            'other' => $other, 'insured_other' => $mode === 'SPLIT' ? $insOther : null, 'owner' => $owner, 'insured' => $insured, 'beneficiary' => $ben, 'doc_owner' => $docOwner, 'mismatch' => $mismatch];
}

// برای فهرست‌ها: شرکتِ ردیف‌ها یک‌بار خوانده می‌شود
function party_company_row($pdo, $companyId) {
    static $cache = [];
    $companyId = intval($companyId);
    if (!isset($cache[$companyId])) {
        $st = $pdo->prepare("SELECT name, economic_code, phone, address FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $cache[$companyId] = $st->fetch() ?: [];
    }
    return $cache[$companyId];
}

// نقش‌ها => فیلدهای «اطلاعات صدور» (برای پیش‌پر کردنِ فرمِ صدور)
function party_issue_fields(array $res) {
    $o = $res['owner']; $i = $res['insured']; $b = $res['beneficiary'];
    $id = function ($p) { return (string)(($p['national_id'] ?? '') ?: ($p['economic_code'] ?? '')); };
    return array_filter([
        'insured_name' => $i['name'] ?? '', 'insured_national_id' => $id($i), 'insured_phone' => $i['phone'] ?? '',
        'insured_address' => $i['address'] ?? '', 'insured_postal_code' => $i['postal_code'] ?? '',
        'owner_name' => $o['name'] ?? '', 'owner_national_id' => $id($o), 'owner_phone' => $o['phone'] ?? '', 'owner_address' => $o['address'] ?? '',
        'beneficiary' => $b['name'] ?? '', 'beneficiary_national_id' => $b ? $id($b) : '',
    ], 'strlen');
}

// بعد از صدور (گروهی یا تکی): مالک/ذینفعِ ردیف اگر در «اطلاعات صدور» خالی است، از نوعِ مالکیت پر می‌شود (مقدارِ موجود دست نمی‌خورد)
function party_fill_issue_info($pdo, $plateId) {
    party_ensure($pdo);
    $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.party_mode AS req_party_mode, cr.party_json AS req_party_json, cr.insured_json AS req_insured_json
                           FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
    $st->execute([intval($plateId)]);
    $r = $st->fetch();
    if (!$r) return;
    $res = party_resolve($r, party_company_row($pdo, $r['company_id']), ['party_mode' => $r['req_party_mode'], 'party_json' => $r['req_party_json'], 'insured_json' => $r['req_insured_json']]);
    $info = json_decode((string)($r['issue_info'] ?? ''), true) ?: [];
    $ch = false;
    foreach (party_issue_fields($res) as $k => $v) {
        if (strpos($k, 'insured_') === 0) continue;   // بیمه‌گذار همان است که در خودِ بیمه‌نامه خوانده شد
        if (trim((string)($info[$k] ?? '')) === '' && $v !== '') { $info[$k] = $v; $ch = true; }
    }
    if ($ch) $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), intval($plateId)]);
}

// =====================================================================
//  کارکنان: معرفی‌نامه همیشه به نامِ پرسنل است؛ مالک/بیمه‌گذار سه حالت دارد (همان کدهای «پیشنهاد»):
//   self_owner    بیمه‌گذار و مالک: خودِ پرسنل
//   self_relative بیمه‌گذار: خودِ پرسنل · مالک: همسر/بستگان (مالک = ذینفع)
//   relative      بیمه‌گذار و مالک: بستگانِ پرسنل
// =====================================================================
const PPARTY_MODES = [
    'self_owner' => 'بیمه‌گذار و مالک: خودِ پرسنل',
    'self_relative' => 'بیمه‌گذار: خودِ پرسنل · مالک (ذینفع): بستگان',
    'relative' => 'بیمه‌گذار و مالک: بستگانِ پرسنل',
];
function pparty_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['party_mode' => 'VARCHAR(16) NULL', 'party_json' => 'TEXT NULL'] as $c => $def) {
        try { if (!$pdo->query("SHOW COLUMNS FROM policy_cases LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE policy_cases ADD COLUMN `$c` $def"); }
        catch (Throwable $e) { error_log('[pparty_ensure] ' . $e->getMessage()); }
    }
}
function pparty_valid_mode($m) { $m = trim((string)$m); return isset(PPARTY_MODES[$m]) ? $m : null; }
// ورودیِ فرم => {mode, owner:{name,national_id,relation}}
function pparty_clean($in) {
    if (is_string($in)) $in = json_decode($in, true);
    if (!is_array($in)) return null;
    $mode = pparty_valid_mode($in['mode'] ?? '');
    if (!$mode) return null;
    $d = function ($v) { return preg_replace('/\D/', '', strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])); };
    return ['mode' => $mode,
            'name' => mb_substr(trim((string)($in['owner_name'] ?? ($in['name'] ?? ''))), 0, 120),
            'national_id' => mb_substr($d($in['owner_national_id'] ?? ($in['national_id'] ?? '')), 0, 11),
            'relation' => mb_substr(trim((string)($in['owner_relation'] ?? ($in['relation'] ?? ''))), 0, 30)];
}
// $case: ردیفِ پرونده (با holder_name/holder_national_code)، $propRel: نتیجه‌ی proposal_relation (اختیاری)
function pparty_resolve(array $case, $propRel = null) {
    $stored = pparty_clean($case['party_json'] ?? null);
    $mode = pparty_valid_mode($case['party_mode'] ?? '') ?: ($propRel['mode'] ?? null);
    $rel = trim((string)($case['insured_relationship'] ?? ''));
    if (!$mode) $mode = ($rel !== '' && $rel !== 'خودم') ? 'relative' : 'self_owner';
    $holder = ['name' => (string)($case['holder_name'] ?? ''), 'national_id' => (string)($case['holder_national_code'] ?? ''), 'relation' => 'خودِ پرسنل'];
    $insuredRel = ['name' => (string)($case['insured_name'] ?? ''), 'national_id' => (string)($case['insured_national_id'] ?? ''), 'relation' => ($rel !== 'خودم' ? $rel : '')];
    if ($mode === 'self_owner') { $ins = $holder; $own = $holder; }
    elseif ($mode === 'self_relative') {
        $ins = $holder;
        $own = ['name' => ($stored['name'] ?? '') ?: ($propRel['owner_name'] ?? ''), 'national_id' => $stored['national_id'] ?? '', 'relation' => $stored['relation'] ?? ''];
    } else {
        $ins = ($rel !== '' && $rel !== 'خودم') ? $insuredRel : ['name' => $stored['name'] ?? '', 'national_id' => $stored['national_id'] ?? '', 'relation' => $stored['relation'] ?? ''];
        if (!empty($stored['name']) && empty($ins['name'])) $ins['name'] = $stored['name'];
        $own = $ins;
    }
    return ['mode' => $mode, 'mode_fa' => PPARTY_MODES[$mode], 'explicit' => (bool)pparty_valid_mode($case['party_mode'] ?? ''),
            'insured' => $ins, 'owner' => $own, 'beneficiary' => $mode === 'self_relative' ? $own : null, 'holder' => $holder];
}
function pparty_save($pdo, $caseId, $in) {
    pparty_ensure($pdo);
    $p = pparty_clean($in);
    if (!$p) return false;
    $pdo->prepare("UPDATE policy_cases SET party_mode = ?, party_json = ? WHERE id = ?")
        ->execute([$p['mode'], $p['mode'] === 'self_owner' ? null : json_encode($p, JSON_UNESCAPED_UNICODE), intval($caseId)]);
    return $p;
}
