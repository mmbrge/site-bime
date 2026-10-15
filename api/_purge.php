<?php
// فایل: api/_purge.php
// «حذفِ کامل» (فقط مدیر کل، با رمزِ خودش): معرفی‌نامه، درخواستِ کارکنان یا درخواستِ شرکتی با همه‌ی وابستگی‌ها -
// مدارک، بازدید، اقساط و تخصیصِ دریافت/پرداخت، الحاقیه‌ها، پوشه‌ها و کپی‌هایش در بایگانی (صادره، ماه‌های کسر از حقوق)
// و شمارِ معرفی‌نامه. هیچ چیز واقعاً پاک نمی‌شود: فایل‌ها به «_trash/…/files» منتقل و ردیف‌های پایگاه داده در
// «_trash/…/rows.json» نوشته می‌شوند تا اگر اشتباهی شد قابلِ برگشت باشد (پوشه‌ی _trash از وب بسته است).
// پرداخت‌ها/دریافت‌های ثبت‌شده (خودِ پول) و صورتحساب‌های صادرشده نمی‌مانند بی‌صاحب: پرداخت می‌ماند و فقط تخصیصش
// به قسط‌های حذف‌شده برداشته می‌شود؛ صورتحساب‌ها (سندِ ارسال‌شده به شرکت) دست نمی‌خورند. گزارشِ بازدید هم می‌ماند و
// فقط اتصالش به این درخواست برداشته می‌شود.

function purge_begin($siteRoot, $label) {
    $base = rtrim($siteRoot, '/') . '/_trash';
    if (!is_dir($base)) @mkdir($base, 0777, true);
    if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    if (!is_file($base . '/index.html')) @file_put_contents($base . '/index.html', '');
    $dir = $base . '/' . date('Y-m-d_His') . '_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $label);
    $d = $dir; $i = 1;
    while (file_exists($d)) $d = $dir . '_' . (++$i);
    @mkdir($d, 0777, true);
    return ['dir' => $d, 'root' => rtrim($siteRoot, '/'), 'rows' => [], 'unlinked' => [], 'paths' => [], 'moved' => [], 'label' => $label];
}

// ردیف‌ها را در سطل نگه می‌دارد و بعد حذف می‌کند (جدولی که نیست بی‌صدا رد می‌شود)
function purge_take($pdo, array &$T, $table, $where, array $args = []) {
    try {
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE $where");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;
        foreach ($rows as $r) $T['rows'][$table][] = $r;
        $pdo->prepare("DELETE FROM `$table` WHERE $where")->execute($args);
        return count($rows);
    } catch (Throwable $e) { return 0; }
}
// اتصال را برمی‌دارد (ردیف می‌ماند)؛ مقدارِ قبلی برای برگشت ثبت می‌شود
function purge_unlink($pdo, array &$T, $table, $col, $where, array $args = []) {
    try {
        $st = $pdo->prepare("SELECT id, `$col` AS v FROM `$table` WHERE $where");
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $T['unlinked'][] = ['table' => $table, 'id' => $r['id'], 'col' => $col, 'value' => $r['v']];
        $pdo->prepare("UPDATE `$table` SET `$col` = NULL WHERE $where")->execute($args);
    } catch (Throwable $e) {}
}
function purge_ids_in(array $ids) { return implode(',', array_map('intval', $ids)) ?: '0'; }

// مسیری که باید به سطل برود (فایل یا پوشه)؛ بعد از commit منتقل می‌شود
function purge_path(array &$T, $p) {
    $p = trim((string)$p);
    if ($p === '') return;
    if ($p[0] !== '/') $p = $T['root'] . '/' . ltrim($p, '/');
    $T['paths'][$p] = true;
}
// فقط چیزی که داخلِ بایگانی/موقت است جابه‌جا می‌شود، و پوشه فقط وقتی به اندازه‌ی کافی پایین است که ریشه، سال، ماه
// یا پوشه‌ی یک شرکت نباشد (پوشه زیرِ Archive دست‌کم ۵ سطح، زیرِ موقت ۴ سطح؛ فایل دست‌کم ۲ سطح)
function purge_safe(array $T, $abs) {
    $real = realpath($abs);
    if (!$real) return false;
    foreach (['/Archive' => 5, '/موقت' => 4] as $sub => $minDir) {
        $root = realpath($T['root'] . $sub);
        if (!$root || strpos($real, $root . '/') !== 0) continue;
        $depth = count(array_filter(explode('/', substr($real, strlen($root) + 1)), 'strlen'));
        return $depth >= (is_dir($real) ? $minDir : 2) ? $real : false;   // فایلِ تکی (مسیرش از پایگاه داده) به سطل می‌رود و برگشت‌پذیر است
    }
    return false;
}
function purge_move_files(array &$T) {
    // پوشه‌ها اول (فایل‌های داخلشان همراهشان می‌روند)، بعد فایل‌های تکی
    $paths = array_keys($T['paths']);
    usort($paths, function ($a, $b) { return strlen($a) - strlen($b); });
    foreach ($paths as $p) {
        if (!file_exists($p)) continue;
        $real = purge_safe($T, $p);
        if (!$real) continue;
        $rel = ltrim(substr($real, strlen(realpath($T['root']))), '/');
        $dst = $T['dir'] . '/files/' . $rel;
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0777, true);
        if (@rename($real, $dst)) $T['moved'][] = $rel;
        elseif (is_dir($real)) { if (function_exists('copy_dir_recursive')) copy_dir_recursive($real, $dst); if (function_exists('archive_rrmdir')) archive_rrmdir($real); $T['moved'][] = $rel; }
        elseif (@copy($real, $dst)) { @unlink($real); $T['moved'][] = $rel; }
    }
}
function purge_finish($pdo, array &$T, $userId, $summary) {
    purge_move_files($T);
    @file_put_contents($T['dir'] . '/rows.json', json_encode(['rows' => $T['rows'], 'unlinked' => $T['unlinked']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @file_put_contents($T['dir'] . '/manifest.json', json_encode(['what' => $T['label'], 'summary' => $summary, 'by' => $userId, 'at' => date('Y-m-d H:i:s'),
        'files' => $T['moved']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'PURGE', ?, 0, ?)")
            ->execute([$userId, $T['label'], mb_substr($summary . ' → ' . basename($T['dir']), 0, 900)]);
    } catch (Throwable $e) {}
}

// همه‌ی پوشه‌های یک معرفی‌نامه در «بایگانی کسر از حقوق» (ماهِ معرفی‌نامه + ماه‌هایی که کپی دارد)
function purge_intro_month_folders($pdo, $siteRoot, $introId) {
    $st = $pdo->prepare("SELECT i.*, p.full_name, p.national_code, p.personnel_code, c.name AS company_name
                           FROM introductions i JOIN persons p ON i.person_id = p.id LEFT JOIN companies c ON p.company_id = c.id WHERE i.id = ?");
    $st->execute([$introId]);
    $intro = $st->fetch();
    if (!$intro) return [];
    $plain = build_intro_folder_name($intro['full_name'], $intro['national_code'], $intro['personnel_code'], $intro['company_name']);
    $bases = [$plain];
    for ($m = 1; $m <= 12; $m++) $bases[] = sanitize_folder_name('(' . jalali_month_name($m) . ') ' . $plain);
    $out = [];
    if (!empty($intro['folder_path']) && is_dir($intro['folder_path'])) $out[$intro['folder_path']] = true;
    $kasr = archive_root($siteRoot) . '/بایگانی کسر از حقوق';
    if (is_dir($kasr)) foreach (scandir($kasr) as $y) {
        if (!preg_match('/^\d{4}$/', $y) || !is_dir("$kasr/$y")) continue;
        foreach (scandir("$kasr/$y") as $mo) {
            if ($mo === '.' || $mo === '..' || !is_dir("$kasr/$y/$mo")) continue;
            $f = intro_find_month_folder("$kasr/$y/$mo", $bases);
            if ($f) $out[$f] = true;
        }
    }
    return array_keys($out);
}

// ---------------- درخواستِ کارکنان ----------------
function purge_case_collect($pdo, $siteRoot, array $case, array &$T) {
    $caseId = intval($case['id']);
    $n = ['installments' => 0, 'documents' => 0, 'endorsements' => 0];
    $inst = [];
    try { $st = $pdo->prepare("SELECT id FROM policy_installments WHERE case_id = ?"); $st->execute([$caseId]); $inst = $st->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    if ($inst) {
        $in = purge_ids_in($inst);
        purge_take($pdo, $T, 'payment_allocations', "installment_id IN ($in)");
        purge_take($pdo, $T, 'pasargad_settlement_lines', "installment_id IN ($in)");
        purge_take($pdo, $T, 'fin_receipt_lines', "source = 'P' AND installment_id IN ($in)");
    }
    $n['installments'] = purge_take($pdo, $T, 'policy_installments', 'case_id = ?', [$caseId]);
    // فایل‌ها
    try {
        $st = $pdo->prepare("SELECT file_path FROM case_documents WHERE case_id = ?"); $st->execute([$caseId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) purge_path($T, $p);
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT photos, photos_folder_path, report_file_path FROM health_inspections WHERE case_id = ?"); $st->execute([$caseId]);
        foreach ($st->fetchAll() as $r) {
            purge_path($T, $r['photos_folder_path']); purge_path($T, $r['report_file_path']);
            $ph = json_decode((string)($r['photos'] ?? ''), true);
            if (is_array($ph)) array_walk_recursive($ph, function ($v) use (&$T) { if (is_string($v) && preg_match('~[/\\\\]~', $v) && preg_match('/\.(jpe?g|png|webp|heic|pdf)$/i', $v)) purge_path($T, $v); });
        }
    } catch (Throwable $e) {}
    foreach (['folder_path', 'issued_file_path', 'pending_policy_temp_path', 'statement_file'] as $c) if (!empty($case[$c])) purge_path($T, $case[$c]);
    // کپیِ «بایگانی صادره» و کپی‌های همین درخواست در پوشه‌های ماه‌های دیگرِ معرفی‌نامه
    if (!empty($case['folder_path'])) {
        $bn = basename($case['folder_path']);
        if ($case['status'] === 'ISSUED') {
            $ts = policy_issue_ts($case['policy_issue_date'] ?? '', strtotime((string)$case['issued_at']) ?: time());
            purge_path($T, build_sadere_base_path($siteRoot, $ts, $case['insurance_type']) . '/' . $bn);
        }
        if (!empty($case['introduction_id'])) foreach (purge_intro_month_folders($pdo, $siteRoot, intval($case['introduction_id'])) as $mf) {
            if (is_dir($mf . '/' . $bn)) purge_path($T, $mf . '/' . $bn);
        }
    }
    // الحاقیه‌ها (فایل، بیمه‌نامه‌ی نهایی و کپی‌هایشان)
    $n['endorsements'] = purge_endo_of_policies($pdo, $siteRoot, "source = 'P' AND ref_id = ?", [$caseId], $T);
    $n['documents'] = purge_take($pdo, $T, 'case_documents', 'case_id = ?', [$caseId]);
    foreach (['health_inspections', 'health_attempts', 'webapp_sessions', 'review_log'] as $t) purge_take($pdo, $T, $t, 'case_id = ?', [$caseId]);
    purge_unlink($pdo, $T, 'visit_reports', 'case_id', 'case_id = ?', [$caseId]);
    purge_unlink($pdo, $T, 'app_notifications', 'related_case_id', 'related_case_id = ?', [$caseId]);
    try { $pdo->prepare("UPDATE persons SET active_case_id = NULL, flow_temp = NULL, conversation_state = 'MAIN_MENU' WHERE active_case_id = ?")->execute([$caseId]); } catch (Throwable $e) {}
    purge_take($pdo, $T, 'policy_cases', 'id = ?', [$caseId]);
    // سهمیه‌ی معرفی‌نامه (درخواستِ «خارج از فاز» قبلاً سهمیه را آزاد کرده)
    if (!empty($case['introduction_id']) && $case['status'] !== 'WITHDRAWN') {
        $pdo->prepare("UPDATE introductions SET used_quota = GREATEST(COALESCE(used_quota, 0) - 1, 0) WHERE id = ?")->execute([$case['introduction_id']]);
    }
    return $n;
}

function purge_policy_case($pdo, $siteRoot, $caseId, $userId) {
    $st = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $st->execute([intval($caseId)]);
    $case = $st->fetch();
    if (!$case) return ['ok' => false, 'error' => 'درخواست یافت نشد.'];
    $T = purge_begin($siteRoot, 'case_' . $case['id']);
    purge_endo_prepare($pdo);
    $pdo->beginTransaction();
    try { $n = purge_case_collect($pdo, $siteRoot, $case, $T); $pdo->commit(); }
    catch (Throwable $e) { $pdo->rollBack(); error_log('[purge_case] ' . $e->getMessage()); return ['ok' => false, 'error' => 'خطا در حذف: ' . $e->getMessage()]; }
    $summary = 'درخواستِ کارکنان ' . ($case['unique_code'] ?: $case['id']) . ' (' . $case['insured_name'] . ')';
    purge_finish($pdo, $T, $userId, $summary);
    if (!empty($case['introduction_id'])) {
        try { sync_intro_folder($pdo, $siteRoot, intval($case['introduction_id'])); } catch (Throwable $e) {}
        try { sync_intro_group_message($pdo, intval($case['introduction_id'])); } catch (Throwable $e) {}
    }
    return ['ok' => true, 'message' => $summary . ' کامل حذف شد (' . fa_digits($n['documents']) . ' مدرک، ' . fa_digits($n['installments']) . ' قسط' . ($n['endorsements'] ? '، ' . fa_digits($n['endorsements']) . ' الحاقیه' : '') . ').', 'trash' => basename($T['dir'])];
}

// ---------------- الحاقیه‌ها ----------------
// جدول/ستون‌های الحاقیه پیش از شروعِ تراکنش (CREATE/ALTER داخلِ تراکنش آن را بی‌صدا commit می‌کند)
function purge_endo_prepare($pdo) {
    if (!function_exists('endo_ensure')) require_once __DIR__ . '/_endorse.php';
    try { endo_ensure($pdo); } catch (Throwable $e) { error_log('[purge_endo_prepare] ' . $e->getMessage()); }
}
// فایل‌های یک الحاقیه: خودِ فایل، بیمه‌نامه‌ی نهایی (+شرایط/صفحه‌های اضافه) و کپی‌هایشان در صادره/ماه‌های کسر از حقوق
function purge_endo_files($pdo, $siteRoot, array $e, array &$T) {
    $names = [];
    if (!empty($e['file_path'])) { purge_path($T, $e['file_path']); $names[] = basename($e['file_path']); }
    if (!empty($e['final_file_path'])) {
        require_once __DIR__ . '/_pdf_split.php';
        $abs = $siteRoot . '/' . ltrim($e['final_file_path'], '/');
        foreach ([$abs, policy_conditions_path($abs), policy_extras_path($abs)] as $f) { purge_path($T, $f); $names[] = basename($f); }
    }
    if (!$names || !in_array($e['source'] ?? '', ['C', 'P'], true) || !function_exists('endo_policy_copies')) return;
    try {
        $pol = endo_load_policy($pdo, $e['source'], (int)$e['ref_id']);
        if ($pol) foreach (endo_policy_copies($pdo, $siteRoot, $e['source'], $pol['row']) as $d) foreach ($names as $n) if (is_file($d . '/' . $n)) purge_path($T, $d . '/' . $n);
    } catch (Throwable $ex) { error_log('[purge_endo_files] ' . $ex->getMessage()); }
}
// الحاقیه‌های بیمه‌نامه‌های حذف‌شده ($where روی policy_endorsements) => فایل‌ها به سطل و ردیف‌ها حذف
function purge_endo_of_policies($pdo, $siteRoot, $where, array $args, array &$T) {
    if (!function_exists('endo_ensure')) require_once __DIR__ . '/_endorse.php';
    try { $st = $pdo->prepare("SELECT * FROM policy_endorsements WHERE $where"); $st->execute($args); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return 0; }
    foreach ($rows as $e) purge_endo_files($pdo, $siteRoot, $e, $T);
    return purge_take($pdo, $T, 'policy_endorsements', $where, $args);
}
// الحاقیه‌هایی که «درخواستِ الحاقیه/فسخِ» همین ردیف‌ها روی بیمه‌نامه‌های دیگر ساخته: اثرِ مالی‌شان برگردانده می‌شود
function purge_endo_linked($pdo, $siteRoot, array $plateIds, array &$T) {
    if (!$plateIds) return 0;
    if (!function_exists('endo_ensure')) require_once __DIR__ . '/_endorse.php';
    $pin = purge_ids_in($plateIds);
    try { $rows = $pdo->query("SELECT * FROM policy_endorsements WHERE request_plate_id IN ($pin) AND NOT (source = 'C' AND ref_id IN ($pin))")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return 0; }
    $n = 0;
    foreach ($rows as $e) {
        $r = endo_revert_finance($pdo, $e);
        if (empty($r['ok'])) throw new RuntimeException('الحاقیه‌ی ' . ($e['endorse_no'] ?: '#' . $e['id']) . ' بیمه‌نامه‌ی ' . $e['policy_number'] . ': ' . ($r['error'] ?? 'برگرداندنِ اثرِ مالی ممکن نشد.'));
        purge_endo_files($pdo, $siteRoot, $e, $T);
        $n += purge_take($pdo, $T, 'policy_endorsements', 'id = ?', [(int)$e['id']]);
        endo_recompute($pdo, $e['source'], (int)$e['ref_id']);
    }
    return $n;
}

// ---------------- معرفی‌نامه (همراهِ همه‌ی درخواست‌هایش) ----------------
function purge_introduction($pdo, $siteRoot, $introId, $userId) {
    $introId = intval($introId);
    $st = $pdo->prepare("SELECT i.*, p.full_name FROM introductions i LEFT JOIN persons p ON p.id = i.person_id WHERE i.id = ?");
    $st->execute([$introId]);
    $intro = $st->fetch();
    if (!$intro) return ['ok' => false, 'error' => 'معرفی‌نامه یافت نشد.'];
    $T = purge_begin($siteRoot, 'intro_' . $introId);
    $folders = purge_intro_month_folders($pdo, $siteRoot, $introId);   // پیش از حذفِ ردیف‌ها
    $cases = 0; $docs = 0; $inst = 0; $endo = 0;
    purge_endo_prepare($pdo);
    $pdo->beginTransaction();
    try {
        $cs = $pdo->prepare("SELECT * FROM policy_cases WHERE introduction_id = ?");
        $cs->execute([$introId]);
        foreach ($cs->fetchAll() as $c) { $n = purge_case_collect($pdo, $siteRoot, $c, $T); $cases++; $docs += $n['documents']; $inst += $n['installments']; $endo += $n['endorsements']; }
        purge_take($pdo, $T, 'insurance_requests', 'introduction_id = ?', [$introId]);
        purge_take($pdo, $T, 'legacy_items', 'intro_id = ?', [$introId]);
        purge_take($pdo, $T, 'introductions', 'id = ?', [$introId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); error_log('[purge_intro] ' . $e->getMessage()); return ['ok' => false, 'error' => 'خطا در حذف: ' . $e->getMessage()]; }
    foreach ($folders as $f) purge_path($T, $f);
    if (!empty($intro['file_path']) && $intro['file_path'] !== 'بایگانی_قبلی') purge_path($T, $intro['file_path']);
    $summary = 'معرفی‌نامه‌ی ' . ($intro['full_name'] ?? '') . ' (#' . $introId . ')';
    purge_finish($pdo, $T, $userId, $summary);
    return ['ok' => true, 'message' => $summary . ' با ' . fa_digits($cases) . ' درخواست، ' . fa_digits($docs) . ' مدرک، ' . fa_digits($inst) . ' قسط' . ($endo ? ' و ' . fa_digits($endo) . ' الحاقیه' : '') . ' کامل حذف شد.', 'trash' => basename($T['dir'])];
}

// ---------------- درخواستِ شرکتی ----------------
function purge_company_request($pdo, $siteRoot, $requestId, $userId) {
    $requestId = intval($requestId);
    $st = $pdo->prepare("SELECT cr.*, c.name AS company_name FROM company_requests cr LEFT JOIN companies c ON c.id = cr.company_id WHERE cr.id = ?");
    $st->execute([$requestId]);
    $req = $st->fetch();
    if (!$req) return ['ok' => false, 'error' => 'درخواست یافت نشد.'];
    $T = purge_begin($siteRoot, 'creq_' . $requestId);
    $st = $pdo->prepare("SELECT * FROM company_request_plates WHERE request_id = ?");
    $st->execute([$requestId]);
    $plates = $st->fetchAll();
    $pids = array_map(function ($p) { return intval($p['id']); }, $plates);
    $pin = purge_ids_in($pids);
    $nInst = 0; $nDocs = 0; $nEndo = 0;
    purge_endo_prepare($pdo);
    $pdo->beginTransaction();
    try {
        $inst = [];
        try { $inst = $pdo->query("SELECT id FROM company_installments WHERE plate_id IN ($pin)")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
        if ($inst) {
            $in = purge_ids_in($inst);
            purge_take($pdo, $T, 'company_payment_allocations', "installment_id IN ($in)");
            purge_take($pdo, $T, 'fin_receipt_lines', "source = 'C' AND installment_id IN ($in)");
        }
        $nInst = purge_take($pdo, $T, 'company_installments', "plate_id IN ($pin)");
        // الحاقیه‌هایی که درخواست‌های الحاقیه/فسخِ همین ردیف‌ها روی بیمه‌نامه‌های دیگر ساخته‌اند (اثرِ مالی برمی‌گردد) و الحاقیه‌های خودِ این بیمه‌نامه‌ها
        $nEndo = purge_endo_linked($pdo, $siteRoot, $pids, $T);
        $nEndo += purge_endo_of_policies($pdo, $siteRoot, "source = 'C' AND ref_id IN ($pin)", [], $T);
        try {
            $q = $pdo->prepare("SELECT file_path FROM company_documents WHERE request_id = ? OR plate_id IN ($pin)"); $q->execute([$requestId]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) purge_path($T, $p);
        } catch (Throwable $e) {}
        $nDocs = purge_take($pdo, $T, 'company_documents', "request_id = ? OR plate_id IN ($pin)", [$requestId]);
        purge_unlink($pdo, $T, 'visit_reports', 'company_plate_id', "company_plate_id IN ($pin)");
        purge_take($pdo, $T, 'legacy_items', 'request_id = ?', [$requestId]);
        purge_take($pdo, $T, 'company_request_plates', 'request_id = ?', [$requestId]);
        purge_take($pdo, $T, 'company_requests', 'id = ?', [$requestId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); error_log('[purge_creq] ' . $e->getMessage()); return ['ok' => false, 'error' => 'خطا در حذف: ' . $e->getMessage()]; }
    foreach ($plates as $pl) {
        foreach (['folder_path', 'issued_file_path', 'pending_policy_temp_path', 'statement_file'] as $c) if (!empty($pl[$c])) purge_path($T, $pl[$c]);
        if ($pl['status'] === 'ISSUED' && !empty($pl['folder_path']) && company_kind_goes_to_sadere($req['request_kind'])) {
            $ts = policy_issue_ts($pl['policy_issue_date'] ?? '', strtotime((string)$pl['issued_at']) ?: time());
            purge_path($T, build_sadere_base_path($siteRoot, $ts, $pl['insurance_type']) . '/' . basename($pl['folder_path']));
        }
    }
    if (!empty($req['letter_file_path'])) purge_path($T, $req['letter_file_path']);
    $summary = 'درخواستِ شرکتیِ ' . ($req['ref_code'] ?: '#' . $requestId) . ' (' . ($req['company_name'] ?? '') . ')';
    purge_finish($pdo, $T, $userId, $summary);
    return ['ok' => true, 'message' => $summary . ' با ' . fa_digits(count($plates)) . ' ردیف، ' . fa_digits($nDocs) . ' مدرک، ' . fa_digits($nInst) . ' قسط' . ($nEndo ? ' و ' . fa_digits($nEndo) . ' الحاقیه' : '') . ' کامل حذف شد.', 'trash' => basename($T['dir'])];
}
