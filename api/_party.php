<?php
// فایل: api/_party.php
// «مالک / بیمه‌گذار / ذینفع»ِ هر ردیفِ درخواستِ شرکتی.
//   COMPANY      : خودرو به نامِ خودِ شرکت است  => مالک و بیمه‌گذار هر دو شرکت
//   BENEFICIARY  : خودرو به نامِ شخص/شرکتِ دیگری است و شرکتِ درخواست‌دهنده بیمه‌گذار می‌شود => آن شخص «ذینفع»
//   OWNER        : خودرو به نامِ شخص/شرکتِ دیگری است و بیمه‌گذار هم همان مالک می‌شود
// برای هر ردیف جدا (party_mode / party_json) و برای کلِ درخواست (پیش‌فرضِ ردیف‌های بی‌تنظیم) تعیین می‌شود؛
// ستون‌ها خودکار و افزودنی ساخته می‌شوند (ردیف‌های قبلی بی‌تنظیم = «خودِ شرکت»، مثلِ رفتارِ قبلی).

const PARTY_MODES = [
    'COMPANY' => 'مالک و بیمه‌گذار: خودِ شرکت',
    'BENEFICIARY' => 'به نامِ دیگری (ذینفع) · بیمه‌گذار: شرکت',
    'OWNER' => 'به نامِ دیگری · بیمه‌گذار: همان مالک',
];
const PARTY_MODES_SHORT = ['COMPANY' => 'خودِ شرکت', 'BENEFICIARY' => 'ذینفع', 'OWNER' => 'مالک = بیمه‌گذار'];

function party_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['company_request_plates', 'company_requests'] as $t) {
        foreach (['party_mode' => 'VARCHAR(16) NULL', 'party_json' => 'TEXT NULL'] as $c => $def) {
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
function party_set_rows($pdo, array $plateIds, $mode, $party = false) {
    party_ensure($pdo);
    $mode = party_valid_mode($mode);
    if (!$mode || !$plateIds) return 0;
    $n = 0;
    foreach (array_unique(array_map('intval', $plateIds)) as $pid) {
        if ($pid <= 0) continue;
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

function party_set_request_default($pdo, $requestId, $mode, $party = null) {
    party_ensure($pdo);
    $mode = party_valid_mode($mode);
    if (!$mode) return;
    $pj = $mode === 'COMPANY' ? null : party_clean($party);
    $pdo->prepare("UPDATE company_requests SET party_mode = ?, party_json = ? WHERE id = ?")
        ->execute([$mode, $pj ? json_encode($pj, JSON_UNESCAPED_UNICODE) : null, intval($requestId)]);
}

// ورودیِ فرم (ثبتِ درخواست): هر ردیف party_mode/party اختیاری؛ وگرنه پیش‌فرضِ کلی
function party_apply_from_input($pdo, $plateId, array $rowIn, $defMode = null, $defParty = null) {
    $mode = party_valid_mode($rowIn['party_mode'] ?? '') ?: party_valid_mode($defMode);
    if (!$mode) return;
    $party = $rowIn['party'] ?? null;
    if (!party_clean($party)) $party = $defParty;
    party_set_rows($pdo, [$plateId], $mode, $party);
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
    if ($mode === 'COMPANY') {
        $owner = $co; $insured = $co; $ben = null;
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
            'other' => $other, 'owner' => $owner, 'insured' => $insured, 'beneficiary' => $ben, 'doc_owner' => $docOwner, 'mismatch' => $mismatch];
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
    $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.party_mode AS req_party_mode, cr.party_json AS req_party_json
                           FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
    $st->execute([intval($plateId)]);
    $r = $st->fetch();
    if (!$r) return;
    $res = party_resolve($r, party_company_row($pdo, $r['company_id']), ['party_mode' => $r['req_party_mode'], 'party_json' => $r['req_party_json']]);
    $info = json_decode((string)($r['issue_info'] ?? ''), true) ?: [];
    $ch = false;
    foreach (party_issue_fields($res) as $k => $v) {
        if (strpos($k, 'insured_') === 0) continue;   // بیمه‌گذار همان است که در خودِ بیمه‌نامه خوانده شد
        if (trim((string)($info[$k] ?? '')) === '' && $v !== '') { $info[$k] = $v; $ch = true; }
    }
    if ($ch) $pdo->prepare("UPDATE company_request_plates SET issue_info = ? WHERE id = ?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), intval($plateId)]);
}
