<?php
// فایل: api/_trash.php
// «حذف‌شده‌ها» (تنظیمات): هر «حذفِ کامل» (api/_purge.php) در پوشه‌ی بسته‌ی _trash/<نام> نگه داشته می‌شود:
//   manifest.json  چه چیزی، کی، توسطِ چه کسی، فهرستِ فایل‌های منتقل‌شده
//   rows.json      همه‌ی ردیف‌های پایگاه داده (با همان شناسه‌ها) و اتصال‌هایی که برداشته شد
//   files/...      فایل‌ها و پوشه‌ها با همان مسیرِ نسبی به ریشه‌ی سایت
// برگرداندن: ردیف‌ها با همان شناسه‌ها برمی‌گردند (اول والدها)، اتصال‌ها و سهمیه‌ی معرفی‌نامه دوباره برقرار می‌شود و
// فایل‌ها سرِ جای قبلی‌شان می‌روند. اگر شناسه‌ای در این فاصله استفاده شده باشد، هیچ چیز برنمی‌گردد و خطا گفته می‌شود.
// پاک کردنِ دائمی: همان پوشه از روی هاست پاک می‌شود و دیگر برگشت ندارد.

function trash_base($siteRoot) { return rtrim($siteRoot, '/') . '/_trash'; }
function trash_dir($siteRoot, $name) {
    $name = basename((string)$name);
    if ($name === '' || $name[0] === '.' || !preg_match('/^[A-Za-z0-9_\-]+$/', $name)) return null;
    $d = trash_base($siteRoot) . '/' . $name;
    return is_dir($d) && is_file($d . '/manifest.json') ? $d : null;
}
function trash_size($dir) {
    $n = 0;
    try { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $n += $f->getSize(); } catch (Throwable $e) {}
    return $n;
}
function trash_kind_fa($label) {
    $k = preg_replace('/_\d+$/', '', (string)$label);
    return ['case' => 'درخواستِ کارکنان', 'intro' => 'معرفی‌نامه', 'creq' => 'درخواستِ شرکتی', 'cplate' => 'ردیفِ شرکتی (بیمه‌نامه)'][$k] ?? $k;
}

function trash_list($pdo, $siteRoot) {
    $base = trash_base($siteRoot);
    $out = [];
    if (!is_dir($base)) return $out;
    $users = [];
    try { $users = $pdo->query("SELECT id, full_name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}
    foreach (scandir($base) as $n) {
        if ($n === '.' || $n === '..' || !is_dir("$base/$n") || !is_file("$base/$n/manifest.json")) continue;
        $m = json_decode((string)file_get_contents("$base/$n/manifest.json"), true) ?: [];
        $rows = json_decode((string)@file_get_contents("$base/$n/rows.json"), true) ?: [];
        $cnt = [];
        foreach (($rows['rows'] ?? []) as $t => $list) $cnt[$t] = count($list);
        $at = strtotime($m['at'] ?? '') ?: filemtime("$base/$n");
        [$y, $mo, $d] = jalali_from_gregorian_ts($at);
        $out[] = ['name' => $n, 'what' => trash_kind_fa($m['what'] ?? ''), 'summary' => (string)($m['summary'] ?? $n), 'by' => $users[$m['by'] ?? 0] ?? '',
                  'at' => date('Y-m-d H:i:s', $at), 'at_j' => sprintf('%04d/%02d/%02d %s', $y, $mo, $d, date('H:i', $at)), 'files' => count($m['files'] ?? []),
                  'size' => trash_size("$base/$n"), 'counts' => $cnt, 'restored_at' => $m['restored_at'] ?? null,
                  'restored_j' => !empty($m['restored_at']) ? (function ($t) { [$a, $b, $c] = jalali_from_gregorian_ts($t); return sprintf('%04d/%02d/%02d %s', $a, $b, $c, date('H:i', $t)); })(strtotime($m['restored_at'])) : null,
                  'restore_note' => $m['restore_note'] ?? null];
    }
    usort($out, function ($a, $b) { return strcmp($b['at'], $a['at']); });
    return $out;
}

// ترتیبِ برگرداندن: والدها اول (جدولی که این‌جا نیست، به ترتیبِ خودِ فایل بعد از این‌ها می‌آید)
const TRASH_ORDER = ['introductions', 'company_requests', 'company_request_plates', 'policy_cases', 'insurance_requests', 'legacy_items',
                     'policy_installments', 'company_installments', 'case_documents', 'company_documents', 'health_inspections', 'health_attempts',
                     'webapp_sessions', 'review_log', 'policy_endorsements', 'payment_allocations', 'company_payment_allocations',
                     'pasargad_settlement_lines', 'fin_receipt_lines'];

function trash_cols($pdo, $table) {
    static $c = [];
    if (!isset($c[$table])) { try { $c[$table] = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $c[$table] = []; } }
    return $c[$table];
}

// فایل/پوشه را از سطل سرِ جایش برمی‌گرداند؛ اگر مقصد وجود دارد، فقط چیزهایی که آن‌جا نیست کپی می‌شود
function trash_move_back($src, $dst) {
    if (!file_exists($src)) return false;
    if (!file_exists($dst)) {
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0777, true);
        if (@rename($src, $dst)) return true;
    }
    if (is_dir($src)) {
        if (!is_dir($dst)) @mkdir($dst, 0777, true);
        foreach (scandir($src) as $f) if ($f !== '.' && $f !== '..') trash_move_back("$src/$f", "$dst/$f");
        @rmdir($src);
        return true;
    }
    if (!file_exists($dst) && @copy($src, $dst)) { @unlink($src); return true; }
    return false;
}

function trash_restore($pdo, $siteRoot, $name, $userId) {
    $dir = trash_dir($siteRoot, $name);
    if (!$dir) return ['ok' => false, 'error' => 'مورد پیدا نشد.'];
    $m = json_decode((string)file_get_contents("$dir/manifest.json"), true) ?: [];
    if (!empty($m['restored_at'])) return ['ok' => false, 'error' => 'این مورد قبلاً برگردانده شده است.'];
    $data = json_decode((string)@file_get_contents("$dir/rows.json"), true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'فایلِ اطلاعاتِ این مورد خوانده نشد.'];
    $rows = (array)($data['rows'] ?? []);
    $tables = array_values(array_unique(array_merge(array_values(array_filter(TRASH_ORDER, function ($t) use ($rows) { return isset($rows[$t]); })), array_keys($rows))));

    // شناسه‌ای که در این فاصله استفاده شده = برگرداندن ممکن نیست (چیزی بازنویسی نمی‌شود)
    $conf = [];
    foreach ($tables as $t) {
        $cols = trash_cols($pdo, $t);
        if (!$cols) return ['ok' => false, 'error' => "جدولِ «{$t}» دیگر در پایگاه داده نیست."];
        if (!in_array('id', $cols, true)) continue;
        $q = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE id = ?");
        foreach ($rows[$t] as $r) if (isset($r['id'])) { $q->execute([$r['id']]); if ($q->fetchColumn()) $conf[] = "$t#" . $r['id']; }
    }
    if ($conf) return ['ok' => false, 'error' => 'برگرداندن ممکن نیست: این شناسه‌ها در این فاصله دوباره استفاده شده‌اند: ' . implode('، ', array_slice($conf, 0, 8)) . (count($conf) > 8 ? ' …' : '')];

    $skipped = []; $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($tables as $t) {
            $cols = trash_cols($pdo, $t);
            foreach ($rows[$t] as $r) {
                // تخصیصِ دریافت: اگر آن دریافت در این فاصله به قسطِ دیگری داده شده و جا ندارد، این تخصیص برنمی‌گردد
                if (in_array($t, ['payment_allocations', 'company_payment_allocations'], true) && isset($r['payment_id'], $r['amount'])) {
                    $pt = $t === 'payment_allocations' ? 'payments' : 'company_payments';
                    try {
                        $amt = $pdo->query("SELECT amount FROM `$pt` WHERE id = " . intval($r['payment_id']))->fetchColumn();
                        $used = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM `$t` WHERE payment_id = " . intval($r['payment_id']))->fetchColumn();
                        if ($amt === false) { $skipped[] = 'تخصیصِ دریافتِ #' . $r['payment_id'] . ' (دریافت حذف شده)'; continue; }
                        if ($used + (float)$r['amount'] > (float)$amt + 0.5) { $skipped[] = 'تخصیصِ دریافتِ #' . $r['payment_id'] . ' (دریافت به قسطِ دیگری داده شده)'; continue; }
                    } catch (Throwable $e) {}
                }
                $keys = array_values(array_filter(array_keys($r), function ($k) use ($cols) { return in_array($k, $cols, true); }));
                if (!$keys) continue;
                $pdo->prepare("INSERT INTO `$t` (`" . implode('`, `', $keys) . "`) VALUES (" . implode(', ', array_fill(0, count($keys), '?')) . ")")
                    ->execute(array_map(function ($k) use ($r) { return $r[$k]; }, $keys));
                $n++;
            }
        }
        foreach ((array)($data['unlinked'] ?? []) as $u) {
            if (!preg_match('/^\w+$/', (string)$u['table']) || !preg_match('/^\w+$/', (string)$u['col'])) continue;
            try { $pdo->prepare("UPDATE `{$u['table']}` SET `{$u['col']}` = ? WHERE id = ? AND `{$u['col']}` IS NULL")->execute([$u['value'], $u['id']]); } catch (Throwable $e) {}
        }
        // سهمیه‌ی معرفی‌نامه: هر درخواستِ برگشته (به‌جز «خارج از فاز») دوباره یک سهمیه می‌گیرد
        foreach ($rows['policy_cases'] ?? [] as $c) {
            if (!empty($c['introduction_id']) && ($c['status'] ?? '') !== 'WITHDRAWN')
                $pdo->prepare("UPDATE introductions SET used_quota = COALESCE(used_quota, 0) + 1 WHERE id = ?")->execute([$c['introduction_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[trash_restore] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'برگرداندن ناموفق بود (هیچ چیز تغییر نکرد): ' . $e->getMessage()];
    }

    // فایل‌ها سرِ جای قبلی
    $root = rtrim($siteRoot, '/'); $files = 0;
    foreach ((array)($m['files'] ?? []) as $rel) {
        $rel = ltrim((string)$rel, '/');
        if ($rel === '' || strpos($rel, '..') !== false) continue;
        if (trash_move_back("$dir/files/$rel", "$root/$rel")) $files++;
    }
    // حق بیمه‌ی نهایی و وضعیتِ الحاقیه‌ها، پوشه و پیامِ گروهِ معرفی‌نامه
    $linked = 0;
    try {
        if (!function_exists('endo_recompute')) require_once __DIR__ . '/_endorse.php';
        $refs = [];
        foreach ($rows['policy_endorsements'] ?? [] as $e) {
            $refs[$e['source'] . ':' . $e['ref_id']] = [$e['source'], (int)$e['ref_id']];
            if (!empty($e['request_plate_id'])) $linked++;
        }
        foreach ($rows['policy_cases'] ?? [] as $c) $refs['P:' . $c['id']] = ['P', (int)$c['id']];
        foreach ($rows['company_request_plates'] ?? [] as $p) $refs['C:' . $p['id']] = ['C', (int)$p['id']];
        foreach ($refs as [$s, $id]) endo_recompute($pdo, $s, $id);
    } catch (Throwable $e) { error_log('[trash_restore endo] ' . $e->getMessage()); }
    $intros = [];
    foreach ($rows['policy_cases'] ?? [] as $c) if (!empty($c['introduction_id'])) $intros[(int)$c['introduction_id']] = true;
    foreach ($rows['introductions'] ?? [] as $i) $intros[(int)$i['id']] = true;
    foreach (array_keys($intros) as $iid) {
        try { if (function_exists('sync_intro_folder')) sync_intro_folder($pdo, $siteRoot, $iid); } catch (Throwable $e) {}
        try { if (function_exists('sync_intro_group_message')) sync_intro_group_message($pdo, $iid); } catch (Throwable $e) {}
    }
    $note = [];
    if ($skipped) $note[] = 'برنگشت: ' . implode('، ', array_slice($skipped, 0, 5));
    if ($linked && preg_match('/^(cplate|creq)_/', (string)($m['what'] ?? ''))) $note[] = 'اثرِ مالیِ ' . fa_digits($linked) . ' الحاقیه/فسخِ مرتبط دوباره اعمال نشد؛ از «الحاقیه‌ها» بررسی کنید';
    $m['restored_at'] = date('Y-m-d H:i:s');
    $m['restored_by'] = $userId;
    $m['restore_note'] = implode(' — ', $note) ?: null;
    @file_put_contents("$dir/manifest.json", json_encode($m, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'RESTORE', ?, 0, ?)")
            ->execute([$userId, (string)($m['what'] ?? ''), mb_substr(($m['summary'] ?? '') . ' ← ' . basename($dir), 0, 900)]);
    } catch (Throwable $e) {}
    return ['ok' => true, 'message' => ($m['summary'] ?? 'مورد') . ' برگردانده شد (' . fa_digits($n) . ' ردیفِ اطلاعات، ' . fa_digits($files) . ' فایل/پوشه).' . ($note ? ' ' . implode(' — ', $note) . '.' : '')];
}

function trash_rrmdir($d) {
    if (!is_dir($d)) return @unlink($d);
    foreach (scandir($d) as $f) if ($f !== '.' && $f !== '..') trash_rrmdir("$d/$f");
    return @rmdir($d);
}
function trash_purge($pdo, $siteRoot, $name, $userId) {
    $dir = trash_dir($siteRoot, $name);
    if (!$dir) return ['ok' => false, 'error' => 'مورد پیدا نشد.'];
    $m = json_decode((string)file_get_contents("$dir/manifest.json"), true) ?: [];
    trash_rrmdir($dir);
    if (is_dir($dir)) return ['ok' => false, 'error' => 'پاک کردنِ کاملِ پوشه ممکن نشد (دسترسیِ فایل روی هاست).'];
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, 'PURGE_FOREVER', ?, 0, ?)")
            ->execute([$userId, (string)($m['what'] ?? ''), mb_substr((string)($m['summary'] ?? $name), 0, 900)]);
    } catch (Throwable $e) {}
    return ['ok' => true, 'message' => 'برای همیشه پاک شد.'];
}
