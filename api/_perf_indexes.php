<?php
// فایل: api/_perf_indexes.php
// شاخص‌های (INDEX) دیتابیس برای ستون‌هایی که صفحه‌ها مدام رویشان جستجو/فیلتر می‌کنند (درخواست‌ها، ردیف‌ها، مدارک، پرونده‌ها،
// اعلان‌ها، کارکرد، گفتگو …). بدونِ شاخص، با بزرگ شدنِ داده هر بار کلِ جدول خوانده می‌شود و صفحه‌ها کُند باز می‌شوند.
//  - فقط «افزودن» است (هیچ داده‌ای تغییر نمی‌کند)؛ اگر جدول/ستون نبود یا شاخصِ مشابه بود، رد می‌شود
//  - یک بار (با نسخه) و در پس‌زمینه، بعد از فرستادنِ صفحه به کاربر اجرا می‌شود (perf_indexes_maybe)
const PERF_IDX_VERSION = 'perf_idx_v1';

function perf_index_list() {
    return [
        ['company_request_plates', ['request_id', 'status']], ['company_request_plates', ['status']], ['company_request_plates', ['issued_at']],
        ['company_request_plates', ['expiry_date']], ['company_request_plates', ['plate_p2']], ['company_request_plates', ['chassis_no']], ['company_request_plates', ['vin']],
        ['company_request_plates', ['engine_no']], ['company_request_plates', ['policy_number']],
        ['company_requests', ['company_id', 'status']], ['company_requests', ['status']], ['company_requests', ['created_at']], ['company_requests', ['is_import']],
        ['company_documents', ['request_id']], ['company_documents', ['plate_id', 'status']], ['company_documents', ['status']], ['company_documents', ['company_id']],
        ['company_installments', ['plate_id']], ['company_installments', ['due_date']],
        ['policy_cases', ['introduction_id']], ['policy_cases', ['status']], ['policy_cases', ['person_id']], ['policy_cases', ['issued_at']],
        ['policy_cases', ['insured_national_id']], ['policy_cases', ['plate']], ['policy_cases', ['policy_number']],
        ['policy_installments', ['case_id']], ['policy_installments', ['due_date']],
        ['insurance_requests', ['introduction_id']], ['introductions', ['person_id']], ['introductions', ['status']],
        ['case_documents', ['case_id']], ['health_inspections', ['case_id']], ['health_inspections', ['status']],
        ['persons', ['national_code']], ['persons', ['company_id']], ['persons', ['mobile_number']],
        ['user_notifications', ['user_id', 'id']], ['user_notifications', ['created_at']],
        ['work_activity', ['user_id', 'at']], ['work_presence', ['user_id']],
        ['login_logs', ['user_type', 'user_id']], ['login_logs', ['created_at']],
        ['hx_messages', ['user_id', 'created_at']], ['company_chat_messages', ['company_id']],
        ['st_tasks', ['assignee_id', 'status']], ['sec_devices', ['user_type', 'user_id']], ['security_events', ['created_at']],
        ['visit_reports', ['company_plate_id']], ['visit_reports', ['case_id']], ['visit_reports', ['health_inspection_id']],
        ['invoices', ['company_id']], ['payments', ['company_id']], ['inq_requests', ['created_at']], ['inq_requests', ['user_id']],
        ['company_portal_user_companies', ['company_id']], ['brd_rows', ['batch_id']], ['renewal_notices', ['created_at']],
    ];
}

function perf_indexes_run($pdo, $budget = 240) {
    $t0 = microtime(true);
    $done = 0; $skipped = 0;
    $tables = [];
    try { foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM) as $r) $tables[strtolower($r[0])] = $r[0]; } catch (Throwable $e) { return ['ok' => false]; }
    $colsOf = []; $idxOf = [];
    foreach (perf_index_list() as [$t, $cols]) {
        if (microtime(true) - $t0 > $budget) return ['ok' => false, 'done' => $done, 'partial' => true];
        $tn = $tables[strtolower($t)] ?? null;
        if (!$tn) { $skipped++; continue; }
        if (!isset($colsOf[$tn])) {
            $colsOf[$tn] = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `$tn`")->fetchAll() as $c) $colsOf[$tn][strtolower($c['Field'])] = $c;
            $idxOf[$tn] = [];
            foreach ($pdo->query("SHOW INDEX FROM `$tn`")->fetchAll() as $ix) $idxOf[$tn][$ix['Key_name']][(int)$ix['Seq_in_index']] = strtolower($ix['Column_name']);
        }
        $ok = true; $parts = [];
        foreach ($cols as $c) {
            $col = $colsOf[$tn][strtolower($c)] ?? null;
            if (!$col) { $ok = false; break; }
            $type = strtolower($col['Type']);
            if (preg_match('/text|blob|json/', $type)) { $ok = false; break; }   // ستونِ متنیِ بزرگ شاخص نمی‌گیرد
            $parts[] = '`' . $col['Field'] . '`' . (preg_match('/char\((\d+)\)/', $type, $m) && intval($m[1]) > 64 ? '(64)' : '');
        }
        if (!$ok) { $skipped++; continue; }
        // شاخصی که با همین ستون‌ها (به همین ترتیب) شروع می‌شود هست؟
        $want = array_map('strtolower', $cols); $has = false;
        foreach ($idxOf[$tn] as $seq) { ksort($seq); if (array_slice(array_values($seq), 0, count($want)) === $want) { $has = true; break; } }
        if ($has) { $skipped++; continue; }
        $name = substr('ix_p_' . implode('_', $want), 0, 60);
        try {
            $pdo->exec("ALTER TABLE `$tn` ADD INDEX `$name` (" . implode(', ', $parts) . ")");
            $idxOf[$tn][$name] = array_combine(range(1, count($want)), $want);
            $done++;
        } catch (Throwable $e) { error_log('[perf index ' . $tn . '] ' . $e->getMessage()); $skipped++; }
    }
    return ['ok' => true, 'done' => $done, 'skipped' => $skipped];
}

// یک بار برای هر نسخه، در پس‌زمینه (بعد از فرستادنِ صفحه)
function perf_indexes_maybe($pdo) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([PERF_IDX_VERSION]);
        if ($st->fetchColumn()) return;
    } catch (Throwable $e) { return; }
    register_shutdown_function(function () use ($pdo) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // درخواست‌های بعدیِ همین کاربر پشتِ قفلِ نشست نمانند
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true); @set_time_limit(300);
        try {
            if (!(int)$pdo->query("SELECT GET_LOCK('bime_perf_idx', 0)")->fetchColumn()) return;
            $r = perf_indexes_run($pdo);
            if (!empty($r['ok'])) $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                                     ->execute([PERF_IDX_VERSION, date('Y-m-d H:i:s') . ' +' . intval($r['done'])]);
            $pdo->query("SELECT RELEASE_LOCK('bime_perf_idx')");
        } catch (Throwable $e) { error_log('[perf indexes] ' . $e->getMessage()); }
    });
}
