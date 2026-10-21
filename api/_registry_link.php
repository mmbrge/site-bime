<?php
// فایل: api/_registry_link.php
// اتصالِ «پرونده‌ی خودروها و اشخاص» به فرم‌های ثبتِ درخواست: با نوشتنِ پلاک، شاسی، موتور، کد ملی یا نام،
// اگر همین خودرو/شخص قبلاً در سامانه هست، اطلاعاتش (و مدارکِ خودرو) پیشنهاد می‌شود تا با یک دکمه در درخواستِ تازه بنشیند.
// نیاز: _case_helpers، _company_helpers، _import_archive، _bordereau، _vehicles

// مدارکِ قابلِ انتقال به درخواستِ تازه (مدارکِ خودِ خودرو؛ نه نامه و بازدید)
const REG_DOC_TYPES = ['car_card_front', 'car_card_back', 'ownership_doc', 'car_card_or_title', 'sales_invoice', 'new_car_card', 'new_ownership_doc', 'prev_third_policy', 'prev_body_policy'];
// کلیدِ مدارکِ کارکنان => نوعِ مدرکِ شرکتی
const REG_CASE_KEY_MAP = ['car_card_front' => 'car_card_front', 'car_card_back' => 'car_card_back', 'ownership_doc' => 'ownership_doc', 'prev_body_insurance' => 'prev_body_policy',
                          'prev_third_insurance' => 'prev_third_policy', 'prev_third_policy' => 'prev_third_policy', 'prev_body_policy' => 'prev_body_policy', 'sales_invoice' => 'sales_invoice'];

function reg_engine_key($v) { $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); return strlen($v) >= 5 ? $v : ''; }

// $q: plate (نمایشی) یا p1/p2/letter/p4 (قالبِ ردیفِ شرکتی)، vin، engine؛ $scope مثلِ vh_records (پنلِ شرکت: company_ids)
function reg_lookup_vehicle($pdo, array $q, array $scope = []) {
    $plate = trim((string)($q['plate'] ?? ''));
    if ($plate === '' && (trim((string)($q['p1'] ?? '')) !== '' || trim((string)($q['p2'] ?? '')) !== '')) $plate = company_plate_display($q['p1'] ?? '', $q['p2'] ?? '', $q['letter'] ?? '', $q['p4'] ?? '') ?: '';
    $core = $plate !== '' ? p2e_digits(plate_core($plate)) : '';
    if (mb_strlen($core) < 5) $core = '';
    $vin = brd_vin_key($q['vin'] ?? '');
    $eng = reg_engine_key($q['engine'] ?? '');
    if ($core === '' && $vin === '' && $eng === '') return null;
    $recs = vh_records($pdo, $scope);
    $hit = null; $match = '';
    foreach (vh_group($recs) as $idx) {
        foreach ($idx as $i) {
            $r = $recs[$i];
            if ($vin !== '' && $r['vin'] === $vin) { $match = 'شماره شاسی'; }
            elseif ($core !== '' && $r['plate_core'] === $core) { $match = 'پلاک'; }
            elseif ($eng !== '' && reg_engine_key($r['engine']) === $eng) { $match = 'شماره موتور'; }
            else continue;
            $hit = $idx; break 2;
        }
    }
    if (!$hit) return null;
    $rs = array_map(function ($i) use ($recs) { return $recs[$i]; }, $hit);
    // ردیفی که خودِ فرم الان ویرایشش می‌کند حساب نمی‌شود
    if (!empty($q['exclude_plate_id'])) $rs = array_values(array_filter($rs, function ($r) use ($q) { return !(in_array($r['src'], ['C', 'I'], true) && $r['id'] === intval($q['exclude_plate_id'])); }));
    if (!$rs) return null;
    $sum = vh_summary($pdo, $rs);
    usort($rs, function ($a, $b) { return [vh_jn($b['issue_date']), (string)$b['at']] <=> [vh_jn($a['issue_date']), (string)$a['at']]; });
    $pick = function ($k) use ($rs) { foreach ($rs as $r) if (trim((string)($r[$k] ?? '')) !== '') return trim((string)$r[$k]); return ''; };
    // آخرین «اطلاعات صدور»ِ ثبت‌شده (برای سیستم/تیپ/رنگ/کاربری و مالک)
    $ii = [];
    foreach ($rs as $r) {
        if ($r['src'] === 'C' || $r['src'] === 'I') { $st = $pdo->prepare("SELECT issue_info, car_type FROM company_request_plates WHERE id = ?"); $st->execute([$r['id']]); $x = $st->fetch(); if ($x) { $ii = issue_info_decode($x['issue_info']) + ['car_type' => $x['car_type'] ?? '']; } }
        elseif ($r['src'] === 'P') { $st = $pdo->prepare("SELECT issue_info, car_system, car_type FROM policy_cases WHERE id = ?"); $st->execute([$r['id']]); $x = $st->fetch(); if ($x) { $ii = issue_info_decode($x['issue_info'] ?? null) + ['car_system' => $x['car_system'], 'car_type' => $x['car_type']]; } }
        if ($ii) break;
    }
    $plateParts = vr_plate_parts_safe($sum['plate']);
    $fill = array_filter([
        'plate' => $sum['plate'], 'plate_p1' => $plateParts['iran'], 'plate_p2' => $plateParts['p2'], 'plate_letter' => $plateParts['letter'], 'plate_p4' => $plateParts['p1'],
        'chassis_no' => $sum['vin'], 'engine_no' => $sum['engine'], 'car_name' => $sum['car'], 'car_system' => $ii['car_system'] ?? '', 'car_type' => $ii['car_type'] ?? '',
        'model_year' => $sum['model_year'], 'color' => $sum['color'], 'usage' => $sum['usage'],
        'owner_name' => ($ii['owner_name'] ?? '') ?: $pick('owner'), 'owner_national_id' => $ii['owner_national_id'] ?? '',
    ], function ($v) { return trim((string)$v) !== ''; });
    // مدارکِ خودرو (آخرین نسخه‌ی هر نوع) + آخرین بیمه‌نامه‌ی صادره‌ی هر نوع به‌عنوانِ «بیمه‌نامه‌ی قبل»
    $root = dirname(__DIR__);
    $docs = []; $seen = [];
    $cIds = array_map(function ($r) { return $r['id']; }, array_filter($rs, function ($r) { return in_array($r['src'], ['C', 'I'], true); }));
    $pIds = array_map(function ($r) { return $r['id']; }, array_filter($rs, function ($r) { return $r['src'] === 'P'; }));
    if ($cIds) foreach ($pdo->query("SELECT id, plate_id, doc_type, file_path, uploaded_at FROM company_documents WHERE plate_id IN (" . implode(',', array_map('intval', $cIds)) . ") AND status = 'ASSIGNED' ORDER BY id DESC") as $d) {
        if (!in_array($d['doc_type'], REG_DOC_TYPES, true) || isset($seen[$d['doc_type']]) || !$d['file_path'] || !is_file($root . '/' . ltrim($d['file_path'], '/'))) continue;
        $seen[$d['doc_type']] = true;
        $docs[] = ['src' => 'C', 'id' => (int)$d['id'], 'type' => $d['doc_type'], 'label' => company_doc_type_label($d['doc_type']), 'file' => ltrim($d['file_path'], '/'),
                   'at' => $d['uploaded_at'] ? jalali_from_gregorian_ts_dotted(strtotime($d['uploaded_at'])) : ''];
    }
    if ($pIds && !isset($scope['company_ids'])) foreach ($pdo->query("SELECT * FROM case_documents WHERE case_id IN (" . implode(',', array_map('intval', $pIds)) . ") AND status <> 'REJECTED' ORDER BY id DESC") as $d) {
        $t = REG_CASE_KEY_MAP[$d['doc_key'] ?? ''] ?? null;
        if (!$t || isset($seen[$t]) || !$d['file_path'] || !is_file(health_abs_path($root, $d['file_path']))) continue;
        $seen[$t] = true;
        $docs[] = ['src' => 'P', 'id' => (int)$d['id'], 'type' => $t, 'label' => company_doc_type_label($t), 'file' => ltrim(str_replace($root, '', $d['file_path']), '/'), 'at' => ''];
    }
    foreach ($rs as $r) {
        if (!$r['issued'] || !$r['file'] || !in_array($r['src'], ['C', 'I', 'P'], true) || ($r['src'] === 'P' && isset($scope['company_ids']))) continue;
        $t = $r['type'] === 'BODY' ? 'prev_body_policy' : 'prev_third_policy';
        if (isset($seen['issued_' . $t]) || !is_file($root . '/' . $r['file'])) continue;
        $seen['issued_' . $t] = true;
        $docs[] = ['src' => $r['src'] === 'P' ? 'IP' : 'IC', 'id' => $r['id'], 'type' => $t, 'label' => 'بیمه‌نامه‌ی ' . insurance_type_fa($r['type']) . ' قبلی (' . $r['policy'] . ')', 'file' => $r['file'], 'at' => $r['issue_date']];
    }
    $last = null;
    foreach ($rs as $r) if ($r['issued']) { $last = ['type' => insurance_type_fa($r['type']), 'policy' => $r['policy'], 'issue_date' => $r['issue_date'], 'end_date' => $r['end_date'], 'company' => $r['company']]; break; }
    $open = array_values(array_map(function ($r) { return ['src' => $r['src'], 'type' => insurance_type_fa($r['type']), 'status' => $r['status'], 'company' => $r['company'], 'request_id' => $r['request_id'] ?? null]; },
        array_filter($rs, function ($r) { return !$r['issued'] && in_array($r['src'], ['C', 'P'], true) && !in_array($r['status'], ['CANCELLED', 'REJECTED', 'WITHDRAWN'], true); })));
    return ['key' => $sum['key'], 'match' => $match, 'summary' => ['plate' => $sum['plate'], 'car' => $sum['car'], 'model_year' => $sum['model_year'], 'color' => $sum['color'],
            'owner' => $sum['owner'], 'company' => $sum['company'], 'person' => $sum['person'], 'policies' => $sum['policies'], 'body_end' => $sum['body_end'], 'third_end' => $sum['third_end']],
            'fill' => $fill, 'docs' => $docs, 'last' => $last, 'open' => array_slice($open, 0, 5)];
}
// پلاکِ نمایشیِ «{ایران}ایران - {سه رقم} {حرف} {دو رقم}» => اجزا (همان قالبِ ردیف‌های شرکتی: p1 = ایران، p4 = دو رقمِ چپ)
function vr_plate_parts_safe($plate) {
    $p = p2e_digits((string)$plate);
    if (preg_match('/(\d{2})\s*ایران\s*-\s*(\d{3})\s*(\S+)\s*(\d{2})/u', $p, $m)) return ['iran' => $m[1], 'p2' => $m[2], 'letter' => $m[3], 'p1' => $m[4]];
    return ['iran' => '', 'p2' => '', 'letter' => '', 'p1' => ''];
}

// شخص با کد ملی (یا چند پیشنهاد با نام): پرسنل، پروفایل، آخرین مشخصاتِ بیمه‌گذار و کد پستیِ استعلام‌شده
function reg_lookup_person($pdo, $nid, $name = '') {
    $nid = preg_replace('/\D/', '', p2e_digits((string)$nid));
    $out = ['person' => null, 'suggest' => []];
    if (strlen($nid) === 10) {
        $st = $pdo->prepare("SELECT p.*, c.name AS company_name FROM persons p LEFT JOIN companies c ON c.id = p.company_id WHERE p.national_code = ? ORDER BY p.id DESC LIMIT 1");
        $st->execute([$nid]);
        $p = $st->fetch();
        $last = null;
        try {
            $st = $pdo->prepare("SELECT insured_name, insured_national_id, insured_phone, insured_address, issue_info, plate FROM policy_cases WHERE insured_national_id = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$nid]);
            $last = $st->fetch() ?: null;
        } catch (Throwable $e) {}
        $ii = $last ? issue_info_decode($last['issue_info'] ?? null) : [];
        $prof = [];
        if ($p) { try { $st = $pdo->prepare("SELECT * FROM per_profiles WHERE person_id = ?"); $st->execute([$p['id']]); $prof = $st->fetch() ?: []; } catch (Throwable $e) {} }
        $postal = function_exists('inq_known_postal') ? inq_known_postal($pdo, $nid) : null;
        if ($p || $last) {
            $out['person'] = array_filter([
                'id' => $p ? (int)$p['id'] : null, 'full_name' => $p['full_name'] ?? ($last['insured_name'] ?? ''), 'national_code' => $nid,
                'personnel_code' => $p['personnel_code'] ?? '', 'mobile' => ($p['mobile_number'] ?? '') ?: ($last['insured_phone'] ?? ''), 'company' => $p['company_name'] ?? '',
                'birth' => ($prof['birth_date'] ?? '') ?: ($ii['insured_birth'] ?? ''), 'father' => $prof['father_name'] ?? '',
                'postal_code' => (is_array($postal) ? ($postal['postal'] ?? '') : '') ?: (($ii['insured_postal_code'] ?? '') ?: ($prof['postal_code'] ?? '')),
                'address' => ($last['insured_address'] ?? '') ?: ($prof['address'] ?? ''), 'last_plate' => $last['plate'] ?? '',
            ], function ($v) { return $v !== null && trim((string)$v) !== ''; });
        }
    } elseif (mb_strlen(trim((string)$name)) >= 3) {
        $st = $pdo->prepare("SELECT p.id, p.full_name, p.national_code, p.personnel_code, c.name AS company_name FROM persons p LEFT JOIN companies c ON c.id = p.company_id WHERE p.full_name LIKE ? ORDER BY p.id DESC LIMIT 6");
        $st->execute(['%' . str_replace(['ي', 'ك'], ['ی', 'ک'], trim($name)) . '%']);
        $out['suggest'] = $st->fetchAll();
    }
    return $out;
}

// مسیرِ مطلقِ فایلِ یک مدرکِ پیشنهادی (و شرکتِ صاحبش، برای بررسیِ دسترسیِ پنلِ شرکت)
function reg_doc_source($pdo, $siteRoot, $src, $id) {
    $id = intval($id);
    if ($src === 'C') { $st = $pdo->prepare("SELECT file_path, company_id FROM company_documents WHERE id = ? AND status = 'ASSIGNED'"); $st->execute([$id]); $r = $st->fetch(); return $r ? [$siteRoot . '/' . ltrim($r['file_path'], '/'), (int)$r['company_id']] : null; }
    if ($src === 'IC') { $st = $pdo->prepare("SELECT crp.issued_file_path, cr.company_id FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ? AND crp.status = 'ISSUED'"); $st->execute([$id]); $r = $st->fetch(); return $r && $r['issued_file_path'] ? [$siteRoot . '/' . ltrim($r['issued_file_path'], '/'), (int)$r['company_id']] : null; }
    if ($src === 'P') { $st = $pdo->prepare("SELECT file_path FROM case_documents WHERE id = ?"); $st->execute([$id]); $r = $st->fetch(); return $r ? [health_abs_path($siteRoot, $r['file_path']), null] : null; }
    if ($src === 'IP') { $st = $pdo->prepare("SELECT issued_file_path FROM policy_cases WHERE id = ? AND status = 'ISSUED'"); $st->execute([$id]); $r = $st->fetch(); return $r && $r['issued_file_path'] ? [health_abs_path($siteRoot, $r['issued_file_path']), null] : null; }
    return null;
}

// یک مدرکِ پیشنهادی روی ردیفِ شرکتیِ تازه (کپیِ فایل، نام‌گذاری و پوشه‌ی استانداردِ همان ردیف)
//   $allowedCompanyIds: پنلِ شرکت‌ها => فقط مدارکِ شرکت‌های خودش؛ null = پنلِ ما
function reg_attach_to_plate($pdo, $siteRoot, $plateId, $src, $id, $type, $allowedCompanyIds = null, $actorId = null) {
    if (!in_array($type, REG_DOC_TYPES, true)) return ['ok' => false, 'error' => 'نوعِ مدرک مجاز نیست.'];
    if ($allowedCompanyIds !== null && !in_array($src, ['C', 'IC'], true)) return ['ok' => false, 'error' => 'دسترسی ندارید.'];
    $s = reg_doc_source($pdo, $siteRoot, $src, $id);
    $root = realpath($siteRoot);
    $abs = $s ? realpath($s[0]) : false;
    if (!$abs || !is_file($abs) || strpos($abs, $root . '/') !== 0) return ['ok' => false, 'error' => 'فایلِ مدرک پیدا نشد.'];
    if ($allowedCompanyIds !== null && !in_array((int)$s[1], array_map('intval', $allowedCompanyIds), true)) return ['ok' => false, 'error' => 'دسترسی ندارید.'];
    $st = $pdo->prepare("SELECT crp.id, crp.request_id, cr.company_id FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
    $st->execute([intval($plateId)]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    if ($allowedCompanyIds !== null && !in_array((int)$row['company_id'], array_map('intval', $allowedCompanyIds), true)) return ['ok' => false, 'error' => 'دسترسی ندارید.'];
    $folder = ensure_plate_folder($pdo, $siteRoot, $row['id']);
    if (!$folder) return ['ok' => false, 'error' => 'پوشه‌ی ردیف ساخته نشد.'];
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'pdf';
    $tmp = unique_dest_path($folder . '/' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext);
    if (!@copy($abs, $tmp)) return ['ok' => false, 'error' => 'کپیِ فایل ممکن نشد.'];
    try {
        $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, file_path, orig_name, file_kind, doc_type, status, assigned_by, assigned_at)
                       VALUES (?, ?, ?, ?, ?, 'SUPPORTING_DOC', ?, 'ASSIGNED', ?, NOW())")
            ->execute([$row['company_id'], $row['request_id'], $row['id'], ltrim(str_replace($siteRoot, '', $tmp), '/'), basename($abs), $type, $actorId]);
    } catch (Throwable $e) { @unlink($tmp); throw $e; }   // فایلِ کپی‌شده بی‌صاحب نمی‌ماند
    $docId = (int)$pdo->lastInsertId();
    company_place_document($pdo, $siteRoot, $docId, $row['id'], $type);
    return ['ok' => true, 'doc_id' => $docId];
}

// همان، روی درخواستِ تازه‌ی کارکنان (case_documents با کلیدِ همان مدرک)
function reg_attach_to_case($pdo, $siteRoot, $caseId, $src, $id, $type, $actorId = null) {
    $keyMap = ['prev_body_policy' => 'prev_body_insurance', 'prev_third_policy' => 'prev_third_insurance'];
    $s = reg_doc_source($pdo, $siteRoot, $src, $id);
    $root = realpath($siteRoot);
    $abs = $s ? realpath($s[0]) : false;
    if (!$abs || !is_file($abs) || strpos($abs, $root . '/') !== 0) return ['ok' => false, 'error' => 'فایلِ مدرک پیدا نشد.'];
    $st = $pdo->prepare("SELECT id FROM policy_cases WHERE id = ?");
    $st->execute([intval($caseId)]);
    if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'پرونده پیدا نشد.'];
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'pdf';
    $tmp = sys_get_temp_dir() . '/reg_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@copy($abs, $tmp)) return ['ok' => false, 'error' => 'کپیِ فایل ممکن نشد.'];
    $key = $keyMap[$type] ?? $type;
    if (!in_array($type, REG_DOC_TYPES, true)) { @unlink($tmp); return ['ok' => false, 'error' => 'نوعِ مدرک مجاز نیست.']; }
    return admin_store_case_doc_file($pdo, $siteRoot, intval($caseId), $key, company_doc_type_label($type), $tmp, $ext, $actorId, false);
}
