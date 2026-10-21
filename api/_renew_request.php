<?php
// فایل: api/_renew_request.php
// «درخواستِ تمدید» از روی یک بیمه‌نامه‌ی قبلی (شرکتی، وارداتی یا ردیفِ بردرو): درخواستِ صدورِ تازه برای همان شرکت با
// همان مشخصات ساخته می‌شود و مدارکِ موجود (کارت/سند/فاکتور و خودِ بیمه‌نامه‌ی قبلی به‌عنوانِ «بیمه‌نامه‌ی قبل») مستقیم روی ردیف می‌نشیند.
//   بدنه: «بدونِ خسارت» => گزارش عدم خسارت لازم، بازدید نه؛ «خسارت داشته» => بازدیدِ سلامت دوباره لازم
//   ثالث: همان مدارکِ قبلی
// نیاز: _case_helpers، _company_helpers، finance_core، _import_archive، _bordereau، _vehicles، _registry_link، _party

// یک ردیفِ مبدأ => مشخصاتِ لازم برای ردیفِ تازه (یا null)
function rnr_source($pdo, $src, $id) {
    $id = intval($id);
    if ($src === 'C' || $src === 'I') {
        $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.insurer, cr.party_mode AS req_party_mode, cr.party_json AS req_party_json FROM company_request_plates crp
                               JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ? AND crp.status = 'ISSUED'");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        $ii = issue_info_decode($r['issue_info']); $oc = json_decode((string)$r['ocr_extracted_data'], true) ?: []; $im = json_decode((string)($r['import_json'] ?? ''), true) ?: [];
        $pick = function ($keys) use ($ii, $oc, $im) { return vh_pick([$ii, $oc, $im], $keys); };
        return ['company_id' => (int)$r['company_id'], 'insurer' => $r['insurer'] ?: 'PASARGAD', 'type' => $r['insurance_type'],
                'p1' => $r['plate_p1'], 'p2' => $r['plate_p2'], 'letter' => $r['plate_letter'], 'p4' => $r['plate_p4'], 'vehicle_kind' => $r['vehicle_kind'] ?? '',
                'chassis' => $r['chassis_no'] ?: ($r['vin'] ?: $pick(['vin', 'chassis_no'])), 'engine' => $r['engine_no'] ?: $pick('engine_no'),
                'car_name' => $r['car_name'] ?: trim($pick('car_system') . ' ' . $pick(['car_type', 'car_tip'])), 'car_type' => $r['car_type'] ?? '',
                'car_value' => (int)$r['car_value'], 'liability' => (int)$r['liability_limit'], 'coverages' => $r['selected_coverages'] ?? null,
                'policy' => $r['policy_number'] ?: ($r['import_policy_number'] ?? ''), 'end' => vh_j($pick('end_date')), 'start' => vh_j($pick('start_date')),
                'issue' => vh_j($r['policy_issue_date'] ?: (($r['import_issue_date'] ?? '') ?: $pick('issue_date'))),
                'party_mode' => $r['party_mode'] ?? null, 'party_json' => $r['party_json'] ?? null, 'issue_info' => $ii, 'file_src' => ['IC', (int)$r['id']], 'label' => 'بیمه‌نامه‌ی ' . ($r['policy_number'] ?: '')];
    }
    if ($src === 'B') {
        $st = $pdo->prepare("SELECT * FROM brd_rows WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        if (!$r['company_id']) return ['error' => 'ردیفِ بردروی «' . $r['policy_number'] . '» به شرکتی وصل نیست؛ اول از «اتصال به شرکت» شرکتش را تعیین کنید.'];
        return ['company_id' => (int)$r['company_id'], 'insurer' => 'PASARGAD', 'type' => in_array($r['insurance_type'], ['BODY', 'THIRDPARTY'], true) ? $r['insurance_type'] : 'THIRDPARTY',
                'p1' => $r['plate_p1'], 'p2' => $r['plate_p2'], 'letter' => $r['plate_letter'], 'p4' => $r['plate_p4'], 'vehicle_kind' => $r['plate_letter'] === 'موتور' ? 'MOTOR' : '',
                'chassis' => $r['vin'], 'engine' => $r['engine_no'], 'car_name' => $r['car_name'] ?: trim($r['car_system'] . ' ' . $r['car_tip']), 'car_type' => $r['car_tip'] ?? '',
                'car_value' => (int)$r['car_value'], 'liability' => (int)$r['liability'], 'coverages' => null, 'policy' => $r['policy_number'],
                'end' => $r['end_date'], 'start' => $r['start_date'], 'issue' => $r['issue_date'], 'party_mode' => null, 'party_json' => null,
                'issue_info' => array_filter(['insured_name' => $r['insured'], 'insured_national_id' => $r['insured_id'], 'insured_phone' => $r['phone'], 'insured_postal_code' => $r['postal_code'],
                                              'insured_address' => $r['address'], 'owner_name' => $r['owner_name'], 'car_system' => $r['car_system'], 'car_type' => $r['car_tip'], 'car_kind' => $r['car_kind'],
                                              'car_model_year' => $r['model_year'], 'car_color' => $r['color'], 'car_usage' => $r['car_usage']], function ($v) { return trim((string)$v) !== ''; }),
                'file_src' => null, 'label' => 'بردروی ' . $r['policy_number']];
    }
    return null;
}

// $items: [['src' => C|I|B, 'id' => .., 'type' => BODY|THIRDPARTY (اختیاری), 'claim' => NO_CLAIM|CLAIM (بدنه)], ...]
// خروجی: ['created' => [['request_id', 'plate_id', 'company']], 'errors' => [...]]
function rnr_create($pdo, $siteRoot, array $items, $actorId = null) {
    company_claim_ensure($pdo); company_ensure_coverage_column($pdo); company_ensure_car_type($pdo); company_ensure_issue_info_cols($pdo); party_ensure($pdo); company_defaults_ensure($pdo);
    $errors = []; $groups = [];
    foreach (array_slice($items, 0, 200) as $it) {
        $s = rnr_source($pdo, (string)($it['src'] ?? ''), intval($it['id'] ?? 0));
        if (!$s) { $errors[] = 'ردیف ' . ($it['src'] ?? '') . ':' . intval($it['id'] ?? 0) . ' پیدا نشد یا صادرشده نیست.'; continue; }
        if (!empty($s['error'])) { $errors[] = $s['error']; continue; }
        $s['type'] = in_array($it['type'] ?? '', ['BODY', 'THIRDPARTY'], true) ? $it['type'] : $s['type'];
        $s['claim'] = $s['type'] === 'BODY' ? (($it['claim'] ?? '') === 'CLAIM' ? 'CLAIM' : 'NO_CLAIM') : null;
        // درخواستِ بازِ دیگری برای همین خودرو و همین نوع؟
        $core = brd_plate_core($s['p1'], $s['p2'], $s['letter'], $s['p4']); $vin = brd_vin_key($s['chassis']);
        $open = $pdo->prepare("SELECT crp.id, crp.request_id, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id
                                WHERE crp.insurance_type = ? AND COALESCE(crp.status, 'PENDING') NOT IN ('ISSUED', 'CANCELLED') AND cr.request_kind = 'NEW_POLICY' AND cr.is_import = 0");
        $open->execute([$s['type']]);
        $dup = null;
        foreach ($open->fetchAll() as $o) { if (($core !== '' && brd_plate_core($o['plate_p1'], $o['plate_p2'], $o['plate_letter'], $o['plate_p4']) === $core) || ($vin !== '' && brd_vin_key($o['chassis_no']) === $vin)) { $dup = $o; break; } }
        if ($dup) { $errors[] = $s['label'] . ': درخواستِ بازِ دیگری برای همین خودرو هست (درخواست #' . $dup['request_id'] . ')؛ ساخته نشد.'; continue; }
        $groups[$s['company_id'] . '|' . $s['insurer']][] = $s;
    }
    $created = [];
    foreach ($groups as $rows) {
        $first = $rows[0];
        $cname = (string)$pdo->query("SELECT name FROM companies WHERE id = " . intval($first['company_id']))->fetchColumn();
        $counts = ['THIRDPARTY' => 0, 'BODY' => 0, 'ENDORSEMENT' => 0, 'CANCELLATION' => 0];
        foreach ($rows as $s) $counts[$s['type']]++;
        $pdo->prepare("INSERT INTO company_requests (company_id, submitted_by, request_text, insurer, request_kind, requested_counts, status) VALUES (?, NULL, ?, ?, 'NEW_POLICY', ?, 'NEW')")
            ->execute([$first['company_id'], 'تمدیدِ ' . count($rows) . ' بیمه‌نامه (ساخته‌شده از «اعلام تمدید/بردرو»)', $first['insurer'], json_encode(company_normalize_requested_counts($counts))]);
        $reqId = (int)$pdo->lastInsertId();
        ref_code_of($pdo, 'company_requests', $reqId);
        foreach ($rows as $s) {
            $endG = $s['end'] && preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', p2e_digits(str_replace('.', '/', $s['end'])), $m) ? date('Y-m-d', jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3]))) : null;
            $ii = $s['issue_info'];
            foreach (['policy_number', 'premium', 'issue_date', 'start_date', 'end_date', 'central_unique_code', 'prop_check', 'note'] as $k) unset($ii[$k]);
            $ii['prev_policy_number'] = $s['policy']; $ii['prev_insurer'] = $s['insurer'] === 'IRAN' ? 'ایران' : 'پاسارگاد';
            $pdo->prepare("INSERT INTO company_request_plates (request_id, plate_p1, plate_p2, plate_letter, plate_p4, chassis_no, engine_no, is_new_vehicle, car_value, liability_limit,
                             insurance_type, expiry_date, skip_health_inspection, car_name, car_type, has_prev_body, row_note, issue_info, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')")
                ->execute([$reqId, $s['p1'] ?: null, $s['p2'] ?: null, $s['letter'] ?: null, $s['p4'] ?: null, $s['chassis'] ?: null, $s['engine'] ?: null,
                           $s['type'] === 'BODY' ? ($s['car_value'] ?: null) : null, $s['type'] === 'THIRDPARTY' ? ($s['liability'] ?: null) : null,
                           $s['type'], $endG, $s['claim'] === 'CLAIM' ? 0 : 1, $s['car_name'] ?: null, mb_substr((string)$s['car_type'], 0, 120) ?: null,
                           $s['type'] === 'BODY' ? 'YES' : null, 'تمدیدِ ' . $s['label'] . ($s['claim'] === 'NO_CLAIM' ? ' · بدونِ خسارت' : ($s['claim'] === 'CLAIM' ? ' · خسارت داشته (بازدید لازم)' : '')),
                           $ii ? json_encode($ii, JSON_UNESCAPED_UNICODE) : null]);
            $pid = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE company_request_plates SET claim_status = ?, selected_coverages = ? WHERE id = ?")->execute([$s['claim'], $s['type'] === 'BODY' ? $s['coverages'] : null, $pid]);
            if ($s['party_mode']) { try { $pdo->prepare("UPDATE company_request_plates SET party_mode = ?, party_json = ? WHERE id = ?")->execute([$s['party_mode'], $s['party_json'], $pid]); } catch (Throwable $e) {} }
            if (!empty($s['vehicle_kind'])) company_set_vehicle_kind($pdo, $pid, $s['vehicle_kind']);
            company_apply_defaults_to_rows($pdo, $reqId, [$pid]);
            ensure_plate_folder($pdo, $siteRoot, $pid);
            // مدارک: خودِ بیمه‌نامه‌ی قبلی + مدارکِ خودرو از پرونده‌ی خودرو
            $att = 0;
            $prevType = $s['type'] === 'BODY' ? 'prev_body_policy' : 'prev_third_policy';
            if ($s['file_src']) { $r = reg_attach_to_plate($pdo, $siteRoot, $pid, $s['file_src'][0], $s['file_src'][1], $prevType, null, $actorId); if (!empty($r['ok'])) $att++; }
            try {
                $v = reg_lookup_vehicle($pdo, ['p1' => $s['p1'], 'p2' => $s['p2'], 'letter' => $s['letter'], 'p4' => $s['p4'], 'vin' => $s['chassis'], 'engine' => $s['engine'], 'exclude_plate_id' => $pid]);
                $seen = [];
                foreach ($v ? $v['docs'] : [] as $d) {
                    if (in_array($d['type'], ['prev_body_policy', 'prev_third_policy'], true) && ($d['type'] !== $prevType || $s['file_src'])) continue;   // بیمه‌نامه‌ی قبلی از خودِ ردیفِ مبدأ آمد
                    if (isset($seen[$d['type']])) continue;
                    $seen[$d['type']] = true;
                    $r = reg_attach_to_plate($pdo, $siteRoot, $pid, $d['src'], $d['id'], $d['type'], null, $actorId);
                    if (!empty($r['ok'])) $att++;
                }
            } catch (Throwable $e) { error_log('[rnr docs] ' . $e->getMessage()); }
            company_sync_plate_status($pdo, $pid);
            $created[] = ['request_id' => $reqId, 'plate_id' => $pid, 'company' => $cname, 'docs' => $att, 'label' => $s['label']];
        }
    }
    return ['created' => $created, 'errors' => $errors];
}
