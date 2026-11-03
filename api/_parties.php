<?php
// فایل: api/_parties.php
// «اشخاص و شرکت‌ها»: یک دفترِ مرکزی از هر شخصِ حقیقی (کد ملیِ ۱۰ رقمی) و حقوقی (شناسه‌ی ملیِ ۱۱ رقمی) که جایی در سایت ثبت شده:
//   بیمه‌گذار/مالک/ذینفعِ ردیف‌ها، فرمِ صدور، استعلام‌ها، تعریفِ شرکت‌ها و پرسنل.
// قاعده: آخرین اطلاعاتِ واردشده (غیرِ خالی) برنده است؛ مقدارِ خالی چیزی را پاک نمی‌کند.
// در فرمِ صدور (تکی و گروهی) از بیمه‌نامه فقط کد/شناسه‌ی ملی برداشته می‌شود و نام، تلفن، کد پستی و نشانی از همین دفتر می‌آید؛
// اگر این شخص/شرکت هنوز اطلاعاتی ندارد، همان خوانده‌شده از بیمه‌نامه می‌نشیند (و از دفعه‌ی بعد همین دفتر).

function parties_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS parties (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nid VARCHAR(20) NOT NULL,
            kind VARCHAR(8) NOT NULL DEFAULT 'PERSON',
            name VARCHAR(190) NULL, father VARCHAR(120) NULL, birth VARCHAR(10) NULL,
            phone VARCHAR(20) NULL, mobile VARCHAR(20) NULL, postal_code VARCHAR(10) NULL, address VARCHAR(400) NULL,
            economic_code VARCHAR(20) NULL, register_no VARCHAR(30) NULL, note TEXT NULL,
            source VARCHAR(40) NULL, updated_by INT NULL, updated_at DATETIME NULL, created_at DATETIME NULL,
            UNIQUE KEY uq_party_nid (nid), KEY k_party_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('[parties_ensure] ' . $e->getMessage()); }
}

function parties_digits($v) {
    return preg_replace('/\D/', '', strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                                                        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
}
// کد ملی (۱۰) یا شناسه‌ی ملی (۱۱)؛ کد ملیِ ۸-۹ رقمی (صفرِ اولِ افتاده) کامل می‌شود. نامعتبر => ''
function parties_nid($v) {
    $d = parties_digits($v);
    if (strlen($d) >= 8 && strlen($d) <= 9) $d = str_pad($d, 10, '0', STR_PAD_LEFT);
    if (strlen($d) === 10 && !preg_match('/^(\d)\1{9}$/', $d)) return $d;
    if (strlen($d) === 11) return $d;
    return '';
}
function parties_kind($nid) { return strlen((string)$nid) === 11 ? 'COMPANY' : 'PERSON'; }

const PARTY_FIELDS = ['name', 'father', 'birth', 'phone', 'mobile', 'postal_code', 'address', 'economic_code', 'register_no'];

// ثبت/به‌روزرسانی (آخرین اطلاعاتِ غیرِ خالی برنده است). $d: name, father, birth, phone, mobile, postal_code, address, economic_code, register_no
function parties_save($pdo, $nid, array $d, $source = '', $userId = null) {
    $nid = parties_nid($nid);
    if ($nid === '') return false;
    parties_ensure($pdo);
    $clean = [];
    foreach (PARTY_FIELDS as $k) {
        $v = trim(preg_replace('/\s+/u', ' ', (string)($d[$k] ?? '')));
        if ($v === '' || $v === '0') continue;
        if (in_array($k, ['phone', 'mobile', 'postal_code', 'economic_code'], true)) $v = parties_digits($v);
        if ($k === 'postal_code' && strlen($v) !== 10) continue;
        if (($k === 'phone' || $k === 'mobile') && strlen($v) < 7) continue;
        if ($k === 'name' && mb_strlen($v) < 2) continue;
        $clean[$k] = mb_substr($v, 0, $k === 'address' ? 400 : ($k === 'name' ? 190 : 120));
    }
    if (!$clean) return false;
    try {
        $st = $pdo->prepare("SELECT * FROM parties WHERE nid = ?");
        $st->execute([$nid]);
        $old = $st->fetch();
        if ($old) {
            $set = []; $args = [];
            foreach ($clean as $k => $v) if ((string)$old[$k] !== $v) { $set[] = "`$k` = ?"; $args[] = $v; }
            if (!$set) return true;
            array_push($set, 'source = ?', 'updated_by = ?', 'updated_at = NOW()');
            array_push($args, mb_substr((string)$source, 0, 40), $userId, $nid);
            $pdo->prepare("UPDATE parties SET " . implode(', ', $set) . " WHERE nid = ?")->execute($args);
        } else {
            $cols = array_keys($clean);
            $pdo->prepare("INSERT INTO parties (nid, kind, `" . implode('`, `', $cols) . "`, source, updated_by, updated_at, created_at) VALUES (?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, NOW(), NOW())")
                ->execute(array_merge([$nid, parties_kind($nid)], array_values($clean), [mb_substr((string)$source, 0, 40), $userId]));
        }
        return true;
    } catch (Throwable $e) { error_log('[parties_save] ' . $e->getMessage()); return false; }
}

// «اطلاعاتِ صدور» (insured_* / owner_* / beneficiary*) => دفتر
function parties_record_issue_info($pdo, array $ii, $source = 'issue', $userId = null) {
    $n = 0;
    if (!empty($ii['insured_national_id']))
        $n += parties_save($pdo, $ii['insured_national_id'], ['name' => $ii['insured_name'] ?? '', 'phone' => $ii['insured_phone'] ?? '', 'postal_code' => $ii['insured_postal_code'] ?? '',
                                                            'address' => $ii['insured_address'] ?? '', 'birth' => $ii['insured_birth_date'] ?? ''], $source, $userId) ? 1 : 0;
    if (!empty($ii['owner_national_id']))
        $n += parties_save($pdo, $ii['owner_national_id'], ['name' => $ii['owner_name'] ?? '', 'phone' => $ii['owner_phone'] ?? '', 'address' => $ii['owner_address'] ?? ''], $source, $userId) ? 1 : 0;
    if (!empty($ii['beneficiary_national_id']))
        $n += parties_save($pdo, $ii['beneficiary_national_id'], ['name' => $ii['beneficiary'] ?? ''], $source, $userId) ? 1 : 0;
    return $n;
}

// آخرین اطلاعاتِ یک کد/شناسه‌ی ملی: دفتر، بعد تعریفِ شرکت‌ها، پرسنل، آخرین ردیفِ صادره/درخواست و استعلام‌ها
// خروجی: null یا ['nid','kind','name','father','birth','phone','mobile','postal_code','address','economic_code','register_no','source','updated_at','person_id','company_id']
function parties_get($pdo, $nid) {
    $nid = parties_nid($nid);
    if ($nid === '') return null;
    parties_ensure($pdo);
    $o = ['nid' => $nid, 'kind' => parties_kind($nid), 'source' => '', 'updated_at' => null, 'person_id' => null, 'company_id' => null];
    foreach (PARTY_FIELDS as $k) $o[$k] = '';
    $fill = function (array $d, $src) use (&$o) {
        $any = false;
        foreach (PARTY_FIELDS as $k) if ($o[$k] === '' && trim((string)($d[$k] ?? '')) !== '') { $o[$k] = trim((string)$d[$k]); $any = true; }
        if ($any && $o['source'] === '') $o['source'] = $src;
    };
    try {
        $st = $pdo->prepare("SELECT * FROM parties WHERE nid = ?");
        $st->execute([$nid]);
        if ($r = $st->fetch()) { $fill($r, (string)($r['source'] ?: 'registry')); $o['updated_at'] = $r['updated_at']; }
    } catch (Throwable $e) {}
    // شرکتِ تعریف‌شده (شناسه‌ی ملی یا کد اقتصادی)
    try {
        $st = $pdo->prepare("SELECT id, name, phone, address, postal_code, economic_code, register_number FROM companies WHERE national_id = ? OR economic_code = ? ORDER BY (national_id = ?) DESC, id DESC LIMIT 1");
        $st->execute([$nid, $nid, $nid]);
        if ($c = $st->fetch()) { $o['company_id'] = (int)$c['id']; $fill(['name' => $c['name'], 'phone' => $c['phone'], 'address' => $c['address'], 'postal_code' => $c['postal_code'], 'economic_code' => $c['economic_code'], 'register_no' => $c['register_number']], 'company'); }
    } catch (Throwable $e) {}
    // پرسنل
    if (strlen($nid) === 10) {
        try {
            $st = $pdo->prepare("SELECT id, full_name, mobile_number FROM persons WHERE national_code = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$nid]);
            if ($p = $st->fetch()) { $o['person_id'] = (int)$p['id']; $fill(['name' => $p['full_name'], 'mobile' => $p['mobile_number']], 'personnel'); }
        } catch (Throwable $e) {}
    }
    // آخرین «اطلاعاتِ صدور»ی که این کد/شناسه در آن هست (ردیف‌های شرکتی و درخواست‌های کارکنان)
    $q = '"' . $nid . '"';
    foreach (['insured' => ['insured_name', 'insured_phone', 'insured_postal_code', 'insured_address'], 'owner' => ['owner_name', 'owner_phone', null, 'owner_address']] as $role => $keys) {
        try {
            $st = $pdo->prepare("SELECT issue_info FROM company_request_plates WHERE issue_info LIKE ? ORDER BY id DESC LIMIT 5");
            $st->execute(['%"' . $role . '_national_id":' . $q . '%']);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $raw) {
                $ii = json_decode((string)$raw, true) ?: [];
                $fill(['name' => $ii[$keys[0]] ?? '', 'phone' => $ii[$keys[1]] ?? '', 'postal_code' => $keys[2] ? ($ii[$keys[2]] ?? '') : '', 'address' => $ii[$keys[3]] ?? ''], 'issue');
            }
        } catch (Throwable $e) {}
    }
    try {
        $st = $pdo->prepare("SELECT insured_name, insured_phone, insured_address FROM policy_cases WHERE insured_national_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$nid]);
        if ($c = $st->fetch()) $fill(['name' => $c['insured_name'], 'phone' => $c['insured_phone'], 'address' => $c['insured_address']], 'case');
    } catch (Throwable $e) {}
    // استعلام‌ها (نام، نامِ پدر، تولد، کد پستیِ تأییدشده)
    try {
        $st = $pdo->prepare("SELECT person_name, father_name, birth, postal, postal_valid FROM inq_requests WHERE national_id = ? AND status = 'OK' ORDER BY id DESC LIMIT 3");
        $st->execute([$nid]);
        foreach ($st->fetchAll() as $r) $fill(['name' => $r['person_name'], 'father' => $r['father_name'], 'birth' => $r['birth'], 'postal_code' => !empty($r['postal_valid']) ? $r['postal'] : ''], 'inquiry');
    } catch (Throwable $e) {}
    $has = false; foreach (PARTY_FIELDS as $k) if ($o[$k] !== '') { $has = true; break; }
    return $has || $o['person_id'] || $o['company_id'] ? $o : null;
}

// فیلدهای فرمِ صدور (insured_name/national_id/phone/postal_code/address، owner_*، beneficiary*): کد/شناسه‌ی ملی از بیمه‌نامه،
// بقیه از دفتر (اگر هست؛ وگرنه همان خوانده‌شده از بیمه‌نامه). خروجی: [$fields, $changes]
function parties_fill_policy_fields($pdo, array $f) {
    $chg = [];
    $map = [
        'national_id' => ['name' => 'insured_name', 'phone' => 'phone', 'postal_code' => 'postal_code', 'address' => 'address'],
        'owner_national_id' => ['name' => 'owner_name', 'phone' => 'owner_phone', 'address' => 'owner_address'],
        'beneficiary_national_id' => ['name' => 'beneficiary'],
    ];
    foreach ($map as $nk => $fields) {
        $p = !empty($f[$nk]) ? parties_get($pdo, $f[$nk]) : null;
        if (!$p) continue;
        foreach ($fields as $pk => $fk) {
            $v = $p[$pk] !== '' ? $p[$pk] : ($pk === 'phone' ? $p['mobile'] : '');
            if ($v === '' || trim((string)($f[$fk] ?? '')) === $v) continue;
            $chg[] = ['field' => $fk, 'old' => (string)($f[$fk] ?? ''), 'new' => $v];
            $f[$fk] = $v;
        }
    }
    return [$f, $chg];
}

// خروجیِ عمومیِ یک شخص/شرکت برای فرم‌ها (فقط فیلدهای لازم)
function parties_public($p) {
    if (!$p) return null;
    $src = ['registry' => 'آخرین اطلاعاتِ ثبت‌شده', 'issue' => 'صدورِ قبلی', 'issue_info' => 'اطلاعاتِ صدور', 'company' => 'تعریفِ شرکت', 'personnel' => 'پرونده‌ی پرسنل',
            'case' => 'درخواستِ کارکنان', 'inquiry' => 'استعلام', 'party' => 'مالک/بیمه‌گذارِ درخواست', 'manual' => 'پرونده‌ی اشخاص و شرکت‌ها'];
    return ['nid' => $p['nid'], 'kind' => $p['kind'], 'name' => $p['name'], 'father' => $p['father'], 'birth' => $p['birth'], 'phone' => $p['phone'] ?: $p['mobile'], 'mobile' => $p['mobile'],
            'postal_code' => $p['postal_code'], 'address' => $p['address'], 'economic_code' => $p['economic_code'], 'register_no' => $p['register_no'],
            'source' => $p['source'], 'source_fa' => $src[$p['source']] ?? ($p['source'] ?: ''), 'person_id' => $p['person_id'], 'company_id' => $p['company_id']];
}
