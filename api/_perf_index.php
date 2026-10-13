<?php
// فایل: api/_perf_index.php
// ایندکس‌های جدول‌های پرکاربرد (پرونده‌ها، اقساط، پرداخت‌ها، مدارک، اشخاص، بازدید، گفتگو): بدونِ ایندکس هر جستجو کلِ جدول
// را می‌خواند و با بیشتر شدنِ داده و چند کاربرِ هم‌زمان، صفحه‌ها دیر باز می‌شوند. فقط ایندکسی ساخته می‌شود که نیست
// (اگر ایندکسی با همان ستونِ اول از قبل هست، کاری نمی‌شود). داده‌ها دست نمی‌خورند. یک بار اجرا می‌شود
// (نشانِ sec_perf_idx در system_settings) و با قفلِ MySQL دو درخواست هم‌زمان با هم اجرایش نمی‌کنند.

const PERF_INDEXES = [
    // [جدول، نامِ ایندکس، ستون‌ها]
    ['policy_cases', 'ix_pc_person', 'person_id'],
    ['policy_cases', 'ix_pc_intro', 'introduction_id'],
    ['policy_cases', 'ix_pc_status', 'status'],
    ['policy_installments', 'ix_pi_case', 'case_id'],
    ['payment_allocations', 'ix_pa_inst', 'installment_id'],
    ['payment_allocations', 'ix_pa_pay', 'payment_id'],
    ['pasargad_settlement_lines', 'ix_psl_inst', 'installment_id'],
    ['pasargad_settlement_lines', 'ix_psl_set', 'settlement_id'],
    ['case_documents', 'ix_cd_case', 'case_id'],
    ['introductions', 'ix_intro_person', 'person_id'],
    ['persons', 'ix_persons_nid', 'national_code'],
    ['persons', 'ix_persons_company', 'company_id'],
    ['persons', 'ix_persons_chat', 'bale_chat_id'],
    ['health_inspections', 'ix_hi_case', 'case_id'],
    ['health_inspections', 'ix_hi_person', 'person_id'],
    ['review_log', 'ix_rl_case', 'case_id'],
    ['review_log', 'ix_rl_insp', 'inspection_id'],
    ['ticket_messages', 'ix_tm_ticket', 'ticket_id'],
    ['tickets', 'ix_tickets_person', 'person_id'],
    ['company_installments', 'ix_ci_plate', 'plate_id'],
    ['company_documents', 'ix_cdoc_plate', 'plate_id'],
    ['company_requests', 'ix_cr_company', 'company_id'],
    ['visit_reports', 'ix_vr_case', 'case_id'],
    ['visit_reports', 'ix_vr_plate', 'company_plate_id'],
    ['payments', 'ix_pay_company', 'company_id'],
];

function perf_index_ensure($pdo) {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try {
        if (!(int)$pdo->query("SELECT GET_LOCK('bime_perf_idx', 0)")->fetchColumn()) return;   // درخواستِ دیگری همین الان می‌سازد
        $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $have = [];
        $st = $pdo->prepare("SELECT table_name, column_name FROM information_schema.statistics WHERE table_schema = ? AND seq_in_index = 1");
        $st->execute([$db]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $c]) $have[strtolower($t) . '.' . strtolower($c)] = true;
        $cols = [];
        $st = $pdo->prepare("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ?");
        $st->execute([$db]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $c]) $cols[strtolower($t) . '.' . strtolower($c)] = true;
        foreach (PERF_INDEXES as [$t, $name, $col]) {
            $k = strtolower($t) . '.' . strtolower($col);
            if (!isset($cols[$k]) || isset($have[$k])) continue;   // جدول/ستون نیست، یا ایندکس از قبل هست
            try { $pdo->exec("ALTER TABLE `$t` ADD INDEX `$name` (`$col`)"); }
            catch (Throwable $e) { error_log('[perf_index] ' . $t . '.' . $col . ': ' . $e->getMessage()); }
        }
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('sec_perf_idx', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
        $pdo->query("SELECT RELEASE_LOCK('bime_perf_idx')");
    } catch (Throwable $e) { error_log('[perf_index_ensure] ' . $e->getMessage()); }
}
