<?php
// فایل: api/_bordereau_iran.php
// «بردروی ایران»: خروجی‌های اکسلِ سامانه‌ی بیمه ایران => همان ساختارِ «بردروی پاسارگاد».
//   - بدنه‌ی صادره (HullPolicyNoteXls) و ثالثِ صادره (tariff-statistics-report، با ردیف‌های پرداختِ زیرِ هر بیمه‌نامه)
//     به جدولی با سرستون‌های پاسارگاد تبدیل و با همان imp_parse_excel خوانده می‌شوند (همه‌ی قاعده‌ها و کنترل‌ها یکی است).
//   - الحاقیه‌های بدنه (EndorsementNoteXls) و ثالث (tariff-statistics-report با «نوع الحاقی») روی ردیفِ همان بیمه‌نامه
//     در بردرو (brd_endorsements) می‌نشینند؛ تکراری‌ها (همان شماره و تاریخِ الحاقیه) دوباره ثبت نمی‌شوند.
// نیاز: _bordereau.php و _import_archive.php پیش از این فایل.

function bri_h($s) {
    $s = str_replace(['ي', 'ك', '‌', "\xC2\xA0"], ['ی', 'ک', ' ', ' '], (string)$s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
// موبایلِ ده‌رقمیِ بی‌صفر (9123751846) => 09123751846
function bri_mob($v) { $d = preg_replace('/\D/', '', p2e_digits((string)$v)); return strlen($d) === 10 && $d[0] === '9' ? '0' . $d : trim((string)$v); }
function bri_num($v) { $d = preg_replace('/[^\d\-]/', '', p2e_digits((string)$v)); return $d === '' || $d === '-' ? 0 : intval($d); }

// «17ج816 ایران88»، «831ط12» + «ایران22»، «673ه32 - ایران 44» => «816ج17 - ایران - 88» (همان شکلی که imp_parse_plate می‌خواند)
function bri_plate($raw, $iranCode = '') {
    $s = p2e_digits(bri_h($raw . ' ' . $iranCode));
    if ($s === '' || preg_match('/نامشخص|null/ui', $s) && !preg_match('/\d{3}/', $s)) return '';
    $p1 = '';
    if (preg_match('/ایران\s*-?\s*(\d{2})/u', $s, $m)) { $p1 = $m[1]; $s = str_replace($m[0], ' ', $s); }
    if (!preg_match('/(\d{2,3})\s*([^\d\s\-()]{1,4})\s*(\d{2,3})/u', $s, $m)) return '';
    [$a, $letter, $b] = [$m[1], $m[2], $m[3]];
    if (strlen($a) === 3 && strlen($b) === 2) { $p2 = $a; $p4 = $b; }
    elseif (strlen($a) === 2 && strlen($b) === 3) { $p2 = $b; $p4 = $a; }
    else return '';
    if ($p1 === '' && preg_match('/(?<!\d)(\d{2})(?!\d)/', str_replace($m[0], ' ', $s), $mm)) $p1 = $mm[1];
    return $p1 === '' ? '' : $p2 . $letter . $p4 . ' - ایران - ' . $p1;
}

// نوعِ فایل از روی سرستون‌ها => ['kind' => BODY|THIRDPARTY|ENDO_BODY|ENDO_THIRD, 'head' => i, 'idx' => [نام => ستون], 'rows' => جدول]
function bri_detect($table) {
    foreach (array_slice($table, 0, 8, true) as $i => $row) {
        $idx = [];
        foreach ((array)$row as $c => $cell) { $h = bri_h($cell); if ($h !== '' && !isset($idx[$h])) $idx[$h] = $c; }
        $has = function ($h) use ($idx) { return isset($idx[bri_h($h)]); };
        if (!$has('شماره بیمه نامه')) continue;
        if ($has('نام الحاقیه') || $has('نسخه الحاقیه')) return ['kind' => 'ENDO_BODY', 'head' => $i, 'idx' => $idx];
        if ($has('نوع الحاقی') || $has('شماره الحاقیه')) return ['kind' => 'ENDO_THIRD', 'head' => $i, 'idx' => $idx];
        if ($has('ارزش مورد بیمه') || $has('حق بیمه پایه')) return ['kind' => 'BODY', 'head' => $i, 'idx' => $idx];
        if ($has('تعهد مالی') || $has('تعهد جانی')) return ['kind' => 'THIRDPARTY', 'head' => $i, 'idx' => $idx];
    }
    return null;
}

const BRI_BODY_COVERS = ['منطقه آزاد (غیر کیش و قشم)', 'منطق آزاد (کیش و قشم)', 'حذف فرانشیز', 'جنگ', 'خارج کشور', 'نوسان قیمت 100%', 'نوسان قیمت 25%',
    'نوسان قیمت 50% (جدید)', 'نوسان قیمت 50% (قدیم)', 'سرقت درجای کلیه قطعات', 'سرقت درجا', 'بلایای طبیعی', 'شکست شیشه', 'تعمیرگاه', 'مواد شیمیایی', 'میخ'];

// جدولِ ایران => جدول با سرستون‌های پاسارگاد (imp_excel_columns)
function bri_to_pasargad_table(array $table, array $det) {
    $idx = $det['idx'];
    $g = function ($cells, $h) use ($idx) { $k = bri_h($h); return isset($idx[$k]) ? trim((string)($cells[$idx[$k]] ?? '')) : ''; };
    $cols = ['شماره بیمه نامه', 'نوع بیمه نامه', 'بیمه گذار', 'نوع بیمه گذار', 'کد ملی بیمه گذار', 'موبایل', 'تلفن', 'آدرس', 'تاریخ صدور', 'تاریخ شروع', 'تاریخ انقضا',
             'شماره انتظامی', 'شماره شاسی', 'شماره موتور', 'نوع وسیله نقلیه', 'سیستم خودرو', 'تیپ وسیله نقلیه', 'سال ساخت وسیله نقلیه', 'مورد استفاده وسیله نقلیه',
             'ظرفیت وسیله نقلیه', 'حق بیمه', 'شماره بیمه مرکزی', 'طرف قرارداد', 'شماره قرارداد', 'بیمه گر قبلی', 'شماره بیمه نامه قبلی', 'تاریخ انقضای قبلی',
             'صفر کیلومتر', 'ارزش وسیله نقلیه', 'نوع پوشش اضافی', 'تعداد سال عدم خسارت', 'تعهد مالی', 'تعهد جانی', 'تعهد سرنشین', 'درصد تخفیف عدم خسارت ثالث', 'نام مالک'];
    $out = [$cols];
    $body = $det['kind'] === 'BODY';
    $rows = array_slice($table, $det['head'] + 1);
    foreach ($rows as $n => $cells) {
        $pol = $g($cells, 'شماره بیمه نامه');
        if ($pol !== '' && !preg_match('~\d+\s*/\s*\d+~', p2e_digits($pol))) continue;   // ردیفِ جمع/پانویس
        if ($pol === '') {
            // ثالث: ردیفِ پرداختِ زیرِ بیمه‌نامه‌ی قبلی (مبلغ/تاریخ/شناسه) - در جمعِ حق بیمه محاسبه شده؛ ردیفِ جدا نیست
            continue;
        }
        if ($body) {
            $covers = [];
            foreach (BRI_BODY_COVERS as $cv) if (bri_num($g($cells, $cv)) > 0) $covers[] = $cv;
            $prevIns = $g($cells, 'شرکت بیمه گر قبلی');
            $r = [$pol, 'بدنه', $g($cells, 'نام بیمه گزار'), $g($cells, 'نوع بیمه گزار'), $g($cells, 'کد ملی'), bri_mob($g($cells, 'تلفن همراه')), $g($cells, 'تلفن'),
                  $g($cells, 'نشانی بیمه گزار'), $g($cells, 'تاریخ صدور'), $g($cells, 'تاریخ شروع'), $g($cells, 'تاریخ پایان'),
                  bri_plate($g($cells, 'شماره انتظامی'), $g($cells, 'کد انتظامی')), $g($cells, 'شماره شاسی'), $g($cells, 'شماره موتور'), $g($cells, 'نوع وسیله'),
                  $g($cells, 'سیستم'), '', $g($cells, 'سال ساخت'), $g($cells, 'مورد استفاده'), '', (string)bri_num($g($cells, 'کل حق بیمه')), $g($cells, 'کد یکتا'),
                  $g($cells, 'عنوان قرارداد'), $g($cells, 'شماره قرارداد'), $prevIns, $g($cells, 'شماره بیمه بدنه قبلی'), $g($cells, 'تاریخ انقضا بیمه بدنه قبلی'),
                  $prevIns === '' && $g($cells, 'شماره بیمه بدنه قبلی') === '' ? '' : 'خیر', (string)bri_num($g($cells, 'ارزش مورد بیمه')), implode('، ', $covers),
                  $g($cells, 'سالهای عدم خسارت بیمه بدنه'), '', '', '', '', $g($cells, 'نام ذینفع')];
        } else {
            $prev = $g($cells, 'بیمه نامه قبلی');
            $zero = mb_strpos(bri_h($prev), 'صفر') !== false;
            // جمعِ حق بیمه = ثالث + مازاد + حوادث + مالیات + مالیات سلامت + عوارض + جریمه (همان «مبلغ پرداختی»)
            $prem = 0;
            foreach (['ثالث', 'مازاد', 'حوادث', 'مالیات', 'مالیات سلامت', 'عوارض', 'جریمه دیرکرد'] as $pc) $prem += max(0, bri_num($g($cells, $pc)));
            if (!$prem) { for ($k = $n + 1; isset($rows[$k]) && $g($rows[$k], 'شماره بیمه نامه') === ''; $k++) $prem += max(0, bri_num($g($rows[$k], 'مبلغ پرداختی'))); }
            $start = $g($cells, 'تاریخ شروع بیمه نامه');
            $r = [$pol, 'ثالث', $g($cells, 'نام بیمه گزار'), '', $g($cells, 'کد ملی بیمه گذار'), bri_mob($g($cells, 'شماره همراه')), bri_mob($g($cells, 'شماره تلفن')), $g($cells, 'آدرس'),
                  // خروجیِ ثالثِ ایران ستونِ «تاریخ صدور» ندارد: تاریخِ شروع به‌جایش
                  $g($cells, 'تاریخ صدور') ?: $start, $start, $g($cells, 'تاریخ پایان بیمه نامه'),
                  bri_plate($g($cells, 'شماره انتظامی')), $g($cells, 'شماره شاسی'), $g($cells, 'شماره موتور'), $g($cells, 'نوع خودرو'), $g($cells, 'نوع سیستم'),
                  $g($cells, 'تیپ خودرو'), '', $g($cells, 'مورد استفاده'), $g($cells, 'ظرفیت'), (string)$prem, $g($cells, 'کد برچسب بیمه نامه'), '', '',
                  $zero ? '' : $prev, $zero ? '' : $g($cells, 'شماره بیمه نامه قبلی'), $zero ? '' : $g($cells, 'خاتمه قبلی'), $zero ? 'بله' : '', '', '', '',
                  (string)bri_num($g($cells, 'تعهد مالی')), (string)bri_num($g($cells, 'تعهد جانی')), (string)bri_num($g($cells, 'تعهد حوادث')), $g($cells, 'سال تخفیف'), ''];
        }
        $out[] = $r;
    }
    return $out;
}

// الحاقیه‌ها => [['policy','no','kind','date','amount','note','line'], ...]
function bri_endorsements(array $table, array $det) {
    $idx = $det['idx'];
    $g = function ($cells, $h) use ($idx) { $k = bri_h($h); return isset($idx[$k]) ? trim((string)($cells[$idx[$k]] ?? '')) : ''; };
    $out = [];
    foreach (array_slice($table, $det['head'] + 1) as $n => $cells) {
        $pol = trim(p2e_digits($g($cells, 'شماره بیمه نامه')));
        if ($pol === '' || !preg_match('~\d+\s*/\s*\d+~', $pol)) continue;   // ردیفِ جمع/پانویس
        if ($det['kind'] === 'ENDO_BODY') {
            $type = bri_h($g($cells, 'نوع حق بیمه')); $amount = bri_num($g($cells, 'کل حق بیمه'));
            $no = $g($cells, 'نسخه الحاقیه'); $date = imp_jdate($g($cells, 'تاریخ صدور الحاقیه'));
            $note = trim(bri_h($g($cells, 'نام الحاقیه')) . ($g($cells, 'درخواست كننده') !== '' ? ' (درخواست: ' . bri_h($g($cells, 'درخواست كننده')) . ')' : ''));
            $ins = bri_h($g($cells, 'نام بیمه گزار'));
        } else {
            $type = bri_h($g($cells, 'نوع الحاقی')); $amount = bri_num($g($cells, 'حق بیمه اضافی/ برگشتی'));
            $no = $g($cells, 'شماره الحاقیه'); $date = imp_jdate($g($cells, 'تاریخ صدور'));
            $note = bri_h($g($cells, 'علت صدور'));
            $ins = bri_h($g($cells, 'نام بیمه گزار'));
        }
        $kind = mb_strpos($type, 'برگشت') !== false ? 'REFUND' : (mb_strpos($type, 'اضاف') !== false && $amount ? 'ADD' : (preg_match('/فسخ|ابطال/u', $note) ? 'CANCEL' : 'CHANGE'));
        $out[] = ['line' => $det['head'] + $n + 2, 'policy' => $pol, 'no' => p2e_digits($no), 'kind' => $kind, 'date' => $date, 'amount' => abs($amount), 'note' => $note,
                  'insured' => $ins, 'type' => $det['kind'] === 'ENDO_BODY' ? 'BODY' : 'THIRDPARTY'];
    }
    return $out;
}

// خواندنِ فایلِ ایران. خروجی: مثلِ brd_parse (rows) یا ['endorsements' => [...]] یا ['error' => ...]
function bri_parse($pdo, $path) {
    $table = fin_read_spreadsheet($path);
    if (!$table || count($table) < 2) return ['error' => 'فایل خوانده نشد یا ردیفی ندارد.'];
    $det = bri_detect($table);
    if (!$det) return ['error' => 'این فایل شبیهِ خروجی‌های بیمه ایران نیست (بدنه‌ی صادره، ثالثِ صادره، الحاقیه‌های بدنه یا ثالث).'];
    if ($det['kind'] === 'ENDO_BODY' || $det['kind'] === 'ENDO_THIRD') {
        $list = bri_endorsements($table, $det);
        $find = $pdo->prepare("SELECT id, insured FROM brd_rows WHERE policy_number = ? LIMIT 1");
        $dup = $pdo->prepare("SELECT COUNT(*) FROM brd_endorsements WHERE row_id = ? AND COALESCE(endorse_no, '') = ? AND COALESCE(date_j, '') = ?");
        foreach ($list as &$e) {
            $find->execute([$e['policy']]);
            $row = $find->fetch();
            $e['row_id'] = $row ? (int)$row['id'] : null;
            $e['exists'] = false;
            if ($row) { $dup->execute([$row['id'], (string)$e['no'], (string)$e['date']]); $e['exists'] = (int)$dup->fetchColumn() > 0; }
            $e['ok'] = $row && !$e['exists'];
            $e['error'] = !$row ? 'این بیمه‌نامه در بردروی ایران نیست (اول خروجیِ صادره‌ها را وارد کنید).' : ($e['exists'] ? 'قبلاً ثبت شده.' : '');
        }
        unset($e);
        return ['endorsements' => $list, 'kind' => $det['kind']];
    }
    $p = imp_parse_excel($pdo, $path, bri_to_pasargad_table($table, $det), 'ایران');
    if (isset($p['error'])) return $p;
    $p['kind'] = $det['kind'];
    return $p;
}

function bri_commit_endorsements($pdo, array $list, array $actor, $lines = null) {
    $only = is_array($lines) ? array_flip(array_map('intval', $lines)) : null;
    $n = 0; $skip = 0;
    foreach ($list as $e) {
        if (empty($e['ok']) || ($only !== null && !isset($only[$e['line']]))) { $skip++; continue; }
        // مستقیم (نه brd_add_endorsement): الحاقیه‌ی «بدونِ بار مالی» هم باید ثبت شود
        try {
            $pdo->prepare("INSERT INTO brd_endorsements (row_id, kind, endorse_no, date_j, amount, note, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$e['row_id'], $e['kind'], mb_substr((string)$e['no'], 0, 60) ?: null, $e['date'] ?: null, intval($e['amount']) ?: null,
                           mb_substr('[اکسلِ بیمه ایران] ' . (string)$e['note'], 0, 600), $actor['id'] ?: null, mb_substr((string)$actor['name'], 0, 190)]);
            if ($e['kind'] === 'CANCEL') $pdo->prepare("UPDATE brd_rows SET status = 'CANCELLED', updated_at = NOW() WHERE id = ?")->execute([$e['row_id']]);
            $n++;
        } catch (Throwable $ex) { error_log('[bri endo] ' . $ex->getMessage()); $skip++; }
    }
    return ['ok' => true, 'new' => $n, 'updated' => 0, 'skipped' => $skip];
}
