<?php
// فایل: api/_row_autolink.php
// مدارکِ خودکارِ هر ردیفِ درخواستِ شرکتی: اگر همین خودرو (پلاک، شاسی/VIN یا موتور) قبلاً در درخواست‌های همین شرکت
// (یا ردیفِ هم‌خانواده‌ی همین درخواست، مثلاً ثالث ↔ بدنه) مدرک یا بیمه‌نامه‌ی صادره داشته، همان روی این ردیف می‌نشیند.
//   - فقط کسری‌های چک‌لیست پر می‌شود (مدرکِ موجود عوض نمی‌شود)
//   - مدرکِ خودکار با ستونِ auto_from (src:id) علامت می‌خورد و با «حذفِ خودکار» کنار می‌رود و دیگر برنمی‌گردد (auto_dismiss)
// نیاز: _case_helpers، _company_helpers، _import_archive، _bordereau، _vehicles، _registry_link

function ral_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM company_documents LIKE 'auto_from'")->fetch()) $pdo->exec("ALTER TABLE company_documents ADD COLUMN auto_from VARCHAR(40) NULL");
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM company_request_plates") as $c) $cols[$c['Field']] = true;
        if (!isset($cols['auto_dismiss'])) $pdo->exec("ALTER TABLE company_request_plates ADD COLUMN auto_dismiss TEXT NULL");
        if (!isset($cols['auto_checked_at'])) $pdo->exec("ALTER TABLE company_request_plates ADD COLUMN auto_checked_at DATETIME NULL");
    } catch (Throwable $e) { error_log('[row autolink schema] ' . $e->getMessage()); }
}

function ral_dismissed($row) { $a = json_decode((string)($row['auto_dismiss'] ?? ''), true); return is_array($a) ? $a : []; }

// یک ردیف: مدارکِ لازم و کسری را از سوابقِ خودرو برمی‌دارد؛ تعدادِ مدارکِ نشسته را برمی‌گرداند
function ral_link_plate($pdo, $siteRoot, $plateId, $actorId = null) {
    ral_ensure($pdo);
    $st = $pdo->prepare("SELECT crp.*, cr.company_id, cr.request_kind, cr.is_import FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
    $st->execute([intval($plateId)]);
    $p = $st->fetch();
    if (!$p) return 0;
    $pdo->prepare("UPDATE company_request_plates SET auto_checked_at = NOW() WHERE id = ?")->execute([$p['id']]);
    if (in_array($p['status'], ['ISSUED', 'CANCELLED'], true) || !empty($p['is_import']) || ($p['request_kind'] ?? 'NEW_POLICY') !== 'NEW_POLICY') return 0;
    $st = $pdo->prepare("SELECT doc_type FROM company_documents WHERE plate_id = ? AND status = 'ASSIGNED'");
    $st->execute([$p['id']]);
    $types = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
    $need = [];
    foreach (company_plate_checklist($p['insurance_type'], (bool)$p['skip_health_inspection'], $types, $p['has_prev_body'] ?? null, 'NEW_POLICY', !empty($p['is_new_vehicle']), $p['claim_status'] ?? null) as $it) {
        if ($it['satisfied'] || !empty($it['internal'])) continue;
        foreach ($it['upload_types'] as $t) $need[$t] = true;
    }
    if (!empty($need['car_card_front']) || !empty($need['ownership_doc'])) $need['car_card_or_title'] = true;
    if (!$need) return 0;
    $v = reg_lookup_vehicle($pdo, ['p1' => $p['plate_p1'], 'p2' => $p['plate_p2'], 'letter' => $p['plate_letter'], 'p4' => $p['plate_p4'],
                                   'vin' => ($p['vin'] ?? '') ?: $p['chassis_no'], 'engine' => $p['engine_no'], 'exclude_plate_id' => $p['id']], ['company_ids' => [(int)$p['company_id']], 'cache' => true]);
    if (!$v || empty($v['docs'])) return 0;
    $dis = ral_dismissed($p);
    $n = 0; $got = [];
    // اول سند، بعد کارت (کارت یا سند کافی است؛ اگر سند آمد کارت لازم نیست)
    $docs = $v['docs'];
    usort($docs, function ($a, $b) { $o = ['ownership_doc' => 0, 'car_card_or_title' => 1, 'car_card_front' => 2, 'car_card_back' => 3]; return ($o[$a['type']] ?? 5) <=> ($o[$b['type']] ?? 5); });
    $rootOf = $pdo->prepare("SELECT auto_from FROM company_documents WHERE id = ?");
    foreach ($docs as $d) {
        $key = $d['src'] . ':' . $d['id'];
        if (empty($need[$d['type']]) || isset($got[$d['type']]) || in_array($d['type'], $types, true) || in_array($key, $dis, true)) continue;
        // کپیِ خودکارِ ردیفِ دیگر => منبعِ اصلی (تا «برداشته‌شده» از راهِ کپی‌ها برنگردد)
        if ($d['src'] === 'C') { $rootOf->execute([$d['id']]); $rt = (string)$rootOf->fetchColumn(); if ($rt !== '') { if (in_array($rt, $dis, true)) continue; $key = $rt; } }
        if (in_array($d['type'], ['car_card_front', 'car_card_back'], true) && (isset($got['ownership_doc']) || isset($got['car_card_or_title']))) continue;
        if (in_array($d['type'], ['ownership_doc', 'car_card_or_title'], true) && (isset($got['car_card_front']) || isset($got['ownership_doc']) || isset($got['car_card_or_title']))) continue;
        try {
            $r = reg_attach_to_plate($pdo, $siteRoot, $p['id'], $d['src'], $d['id'], $d['type'], null, $actorId);
        } catch (Throwable $e) { error_log('[row autolink] ' . $e->getMessage()); continue; }
        if (empty($r['ok'])) continue;
        $pdo->prepare("UPDATE company_documents SET auto_from = ? WHERE id = ?")->execute([$key, $r['doc_id']]);
        $got[$d['type']] = true;
        $n++;
    }
    if ($n) company_sync_plate_status($pdo, $p['id']);
    return $n;
}

// همه‌ی ردیف‌های بازِ یک درخواست (فقط بررسی‌نشده‌ها، مگر $all) با سقفِ زمان
function ral_link_request($pdo, $siteRoot, $requestId, $actorId = null, $all = false, $budget = 6.0) {
    ral_ensure($pdo);
    $t0 = microtime(true);
    $st = $pdo->prepare("SELECT id FROM company_request_plates WHERE request_id = ? AND status NOT IN ('ISSUED', 'CANCELLED')" . ($all ? '' : ' AND auto_checked_at IS NULL') . " ORDER BY id");
    $st->execute([intval($requestId)]);
    $n = 0; $rows = 0; $left = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        if (microtime(true) - $t0 > $budget) { $left++; continue; }
        try { $n += ral_link_plate($pdo, $siteRoot, $pid, $actorId); $rows++; } catch (Throwable $e) { error_log('[row autolink] ' . $e->getMessage()); }
    }
    return ['attached' => $n, 'rows' => $rows, 'left' => $left];
}

// برداشتنِ مدرکِ خودکار: فایلِ کپی حذف و منبعش برای همین ردیف کنار گذاشته می‌شود
function ral_remove($pdo, $siteRoot, $docId) {
    ral_ensure($pdo);
    $st = $pdo->prepare("SELECT d.id, d.plate_id, d.file_path, d.auto_from, crp.auto_dismiss, crp.status FROM company_documents d JOIN company_request_plates crp ON crp.id = d.plate_id WHERE d.id = ?");
    $st->execute([intval($docId)]);
    $d = $st->fetch();
    if (!$d || !$d['auto_from']) return ['ok' => false, 'error' => 'این مدرک خودکار نیامده است.'];
    if ($d['status'] === 'ISSUED') return ['ok' => false, 'error' => 'این ردیف صادر شده است.'];
    $dis = ral_dismissed($d);
    if (!in_array($d['auto_from'], $dis, true)) $dis[] = $d['auto_from'];
    $pdo->prepare("UPDATE company_request_plates SET auto_dismiss = ? WHERE id = ?")->execute([json_encode(array_slice($dis, -60)), $d['plate_id']]);
    $root = realpath($siteRoot);
    $abs = realpath($siteRoot . '/' . ltrim((string)$d['file_path'], '/'));
    if ($abs && is_file($abs) && strpos($abs, $root . '/') === 0) @unlink($abs);
    $pdo->prepare("DELETE FROM company_documents WHERE id = ?")->execute([$d['id']]);
    company_sync_plate_status($pdo, $d['plate_id']);
    return ['ok' => true];
}

// همه‌ی نسخه‌های مدارکِ همین خودرو در سوابقِ همین شرکت (برای «مدارکِ قبلی»)
function ral_history($pdo, $plateId) {
    $st = $pdo->prepare("SELECT crp.*, cr.company_id FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
    $st->execute([intval($plateId)]);
    $p = $st->fetch();
    if (!$p) return [];
    $recs = vh_records($pdo, ['company_ids' => [(int)$p['company_id']]]);
    $core = p2e_digits(plate_core(company_plate_display($p['plate_p1'], $p['plate_p2'], $p['plate_letter'], $p['plate_p4']) ?: ''));
    $vin = brd_vin_key(($p['vin'] ?? '') ?: $p['chassis_no']);
    $ids = [];
    foreach (vh_group($recs) as $idx) {
        $hit = false;
        foreach ($idx as $i) { $r = $recs[$i]; if (($vin !== '' && $r['vin'] === $vin) || (mb_strlen($core) >= 5 && $r['plate_core'] === $core) || (in_array($r['src'], ['C', 'I'], true) && $r['id'] === (int)$p['id'])) { $hit = true; break; } }
        if (!$hit) continue;
        foreach ($idx as $i) if (in_array($recs[$i]['src'], ['C', 'I'], true)) $ids[] = (int)$recs[$i]['id'];
        break;
    }
    $ids[] = (int)$p['id'];
    $ids = array_values(array_unique($ids));
    $out = [];
    $q = $pdo->query("SELECT d.id, d.plate_id, d.request_id, d.doc_type, d.doc_label, d.file_path, d.orig_name, d.uploaded_at, d.auto_from, crp.insurance_type, crp.status
                        FROM company_documents d JOIN company_request_plates crp ON crp.id = d.plate_id
                       WHERE d.plate_id IN (" . implode(',', $ids) . ") AND d.status = 'ASSIGNED' ORDER BY d.id DESC LIMIT 200");
    $root = dirname(__DIR__);
    foreach ($q as $d) {
        if (!$d['file_path'] || !file_exists($root . '/' . ltrim($d['file_path'], '/'))) continue;
        $out[] = ['id' => (int)$d['id'], 'type' => $d['doc_type'], 'label' => ($d['doc_label'] ?? '') ?: company_doc_type_label($d['doc_type']), 'file' => ltrim($d['file_path'], '/'),
                  'is_dir' => is_dir($root . '/' . ltrim($d['file_path'], '/')), 'request_id' => (int)$d['request_id'], 'this_row' => (int)$d['plate_id'] === (int)$p['id'],
                  'kind' => insurance_type_fa($d['insurance_type']), 'auto' => !empty($d['auto_from']), 'at' => $d['uploaded_at'] ? jalali_from_gregorian_ts_dotted(strtotime($d['uploaded_at'])) : ''];
    }
    return $out;
}
