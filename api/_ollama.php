<?php
// فایل: api/_ollama.php
// «سیستمِ داخلی (Ollama)»: هوشِ مصنوعیِ آفلاین روی کامپیوترِ دفتر.
//   سایت (آنلاین) مستقیم به آن کامپیوتر وصل نمی‌شود؛ برنامه‌ی ویندوزیِ tools/ollama-worker (PowerShell) هر چند ثانیه از
//   api/ollama_worker.php «کارِ بعدی» را می‌گیرد، روی Ollamaِ همان کامپیوتر اجرا می‌کند و جواب را برمی‌گرداند.
//   پس پورتی باز نمی‌شود، IP ثابت لازم نیست و Ollama فقط روی خودِ آن کامپیوتر در دسترس است.
//  - جدولِ ollama_jobs: هر درخواست یک ردیف (hash = همان درخواست؛ جوابِ آماده دوباره ساخته نمی‌شود)
//  - ol_call: درخواست را صف می‌کند و تا «wait» ثانیه منتظرِ جواب می‌ماند؛ اگر نرسید pending برمی‌گرداند و کار در صف می‌ماند.
//    وقتی جواب رسید (ol_complete)، مدارکِ منتظرِ همان درخواست (ctx: doc:ID) دوباره پردازش می‌شوند — همه از کش، پس سریع.
// تنظیمات در api_cfg['ollama']؛ جدول خودکار ساخته می‌شود.
require_once __DIR__ . '/_apis.php';

// کارهایی که اولویتِ «سیستم + مدل» دارند (گفتگوی هوشیکس مدلش را خودِ کاربر انتخاب می‌کند)
const OL_TASKS = ['ocr' => 'خواندنِ متنِ عکس (OCR)', 'extract' => 'استخراجِ فیلدها از متن', 'vision' => 'بررسیِ کامل با عکس'];

// تنظیمات: چند «سیستم» (کامپیوتر؛ هر کدام کلیدِ خودش) + برای هر کار فهرستِ اولویت‌دارِ [سیستم، مدل] (سیستمِ خالی = هر سیستمی که آن مدل را دارد)
//   تنظیماتِ نسخه‌ی قبل (یک کلید و یک مدل برای هر کار) خودکار «سیستمِ ۱» و اولویت‌هایش می‌شود؛ برنامه‌ی ویندوزیِ نصب‌شده با همان کلید کار می‌کند.
function ol_cfg($pdo) {
    $c = api_cfg($pdo)['ollama'] ?? [];
    $b = function ($k, $d) use ($c) { return array_key_exists($k, $c) ? !empty($c[$k]) : $d; };
    $nodes = [];
    if (isset($c['nodes']) && is_array($c['nodes'])) {
        foreach ($c['nodes'] as $n) {
            $id = preg_replace('/[^a-z0-9]/', '', (string)($n['id'] ?? ''));
            $tok = trim((string)($n['token'] ?? ''));
            if ($id === '' || $tok === '' || isset($nodes[$id])) continue;
            $nodes[$id] = ['id' => $id, 'name' => trim((string)($n['name'] ?? '')) ?: 'سیستمِ ' . (count($nodes) + 1), 'token' => $tok, 'enabled' => !array_key_exists('enabled', $n) || !empty($n['enabled'])];
        }
    } elseif (trim((string)($c['token'] ?? '')) !== '') {
        $nodes['n1'] = ['id' => 'n1', 'name' => 'کامپیوترِ دفتر', 'token' => trim((string)$c['token']), 'enabled' => true];
    }
    $routes = [];
    foreach (OL_TASKS as $t => $l) {
        $list = [];
        if (isset($c['routes']) && is_array($c['routes'])) {
            foreach ((array)($c['routes'][$t] ?? []) as $r) {
                $m = mb_substr(trim((string)($r['model'] ?? '')), 0, 120);
                $n = preg_replace('/[^a-z0-9]/', '', (string)($r['node'] ?? ''));
                if ($m !== '' && ($n === '' || isset($nodes[$n]))) $list[] = ['node' => $n, 'model' => $m];
            }
        } else {
            $m = trim((string)($c[['ocr' => 'ocr_model', 'extract' => 'text_model', 'vision' => 'vision_model'][$t]] ?? ''));
            if ($m !== '') $list[] = ['node' => '', 'model' => $m];
        }
        $routes[$t] = array_slice($list, 0, 10);
    }
    return [
        'enabled' => $b('enabled', false),
        'nodes' => $nodes,
        'routes' => $routes,
        'use_ocr' => $b('use_ocr', true),                            // موتورِ دومِ OCR بعد از i2OCR
        'use_extract' => $b('use_extract', true),
        'use_vision' => $b('use_vision', true),
        'auto_vision' => $b('auto_vision', true),                    // فیلدِ لازمی پیدا نشد => خودکار با عکس
        'fallback' => $b('fallback', false),                         // هیچ سیستمی وصل نبود => سرویسِ آنلاین (GapGPT)
        'spread' => $b('spread', true),                              // سیستمِ اولویتِ بالاتر مشغول بود => سیستمِ بعدی انجام دهد
        'wait' => max(10, min(240, intval($c['wait'] ?? 120) ?: 120)),
        'num_ctx' => max(2048, min(32768, intval($c['num_ctx'] ?? 8192) ?: 8192)),
    ];
}
function ol_new_id(array $nodes) { do { $id = 'n' . bin2hex(random_bytes(3)); } while (isset($nodes[$id])); return $id; }
// ذخیره: nodes = [{id (خالی = سیستمِ تازه), name, enabled}] (سیستمی که در فهرست نیست حذف می‌شود)، routes = {کار: [{node, model}]}، new_token = idِ سیستم
function ol_save_cfg($pdo, array $in) {
    $cur = ol_cfg($pdo);
    $all = api_cfg($pdo);
    $c = $all['ollama'] ?? [];
    foreach (['enabled', 'use_ocr', 'use_extract', 'use_vision', 'auto_vision', 'fallback', 'spread'] as $k) if (array_key_exists($k, $in)) $c[$k] = !empty($in[$k]) ? 1 : 0;
    foreach (['wait', 'num_ctx'] as $k) if (array_key_exists($k, $in)) $c[$k] = intval($in[$k]);
    $nodes = $cur['nodes'];
    if (isset($in['nodes']) && is_array($in['nodes'])) {
        $next = [];
        foreach ($in['nodes'] as $n) {
            if (!is_array($n)) continue;
            $id = preg_replace('/[^a-z0-9]/', '', (string)($n['id'] ?? ''));
            $old = $id !== '' && isset($nodes[$id]) ? $nodes[$id] : null;
            if (!$old) $id = ol_new_id($nodes + $next);
            $next[$id] = ['id' => $id, 'name' => mb_substr(trim((string)($n['name'] ?? '')), 0, 60) ?: ($old['name'] ?? 'سیستمِ ' . (count($next) + 1)),
                          'token' => $old['token'] ?? bin2hex(random_bytes(24)), 'enabled' => !array_key_exists('enabled', $n) || !empty($n['enabled'])];
        }
        $nodes = $next;
    }
    if (!empty($in['add_node'])) { $id = ol_new_id($nodes); $nodes[$id] = ['id' => $id, 'name' => mb_substr(trim((string)$in['add_node']), 0, 60) ?: 'سیستمِ ' . (count($nodes) + 1), 'token' => bin2hex(random_bytes(24)), 'enabled' => true]; }
    if (!empty($in['new_token']) && isset($nodes[(string)$in['new_token']])) $nodes[(string)$in['new_token']]['token'] = bin2hex(random_bytes(24));
    $routes = $cur['routes'];
    if (isset($in['routes']) && is_array($in['routes'])) foreach (OL_TASKS as $t => $l) if (isset($in['routes'][$t])) {
        $routes[$t] = [];
        foreach ((array)$in['routes'][$t] as $r) {
            if (!is_array($r)) continue;
            $m = mb_substr(trim((string)($r['model'] ?? '')), 0, 120);
            $n = preg_replace('/[^a-z0-9]/', '', (string)($r['node'] ?? ''));
            if ($m !== '') $routes[$t][] = ['node' => $n, 'model' => $m];
        }
    }
    foreach ($routes as $t => $list) $routes[$t] = array_values(array_filter($list, function ($r) use ($nodes) { return $r['node'] === '' || isset($nodes[$r['node']]); }));
    $c['nodes'] = array_values($nodes);
    $c['routes'] = $routes;
    unset($c['token'], $c['ocr_model'], $c['text_model'], $c['vision_model']);   // به nodes و routes منتقل شد
    $all['ollama'] = $c;
    api_save_cfg($pdo, $all);
    return ol_cfg($pdo);
}

function ol_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $o = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ollama_jobs (id INT AUTO_INCREMENT PRIMARY KEY, hash CHAR(40) NOT NULL, purpose VARCHAR(12) NOT NULL, model VARCHAR(120) NOT NULL,
            payload LONGTEXT NULL, status VARCHAR(10) NOT NULL DEFAULT 'QUEUED', result MEDIUMTEXT NULL, error VARCHAR(500) NULL, ctx VARCHAR(500) NULL,
            tokens INT NOT NULL DEFAULT 0, ms INT NOT NULL DEFAULT 0, created_at DATETIME NULL, started_at DATETIME NULL, finished_at DATETIME NULL,
            KEY k_status (status, id), KEY k_hash (hash)) $o");
        // node = سیستمی که کار را گرفت؛ cands = فهرستِ اولویت‌دارِ [سیستم، مدل] برای همین کار
        if (!$pdo->query("SHOW COLUMNS FROM ollama_jobs LIKE 'node'")->fetch()) $pdo->exec("ALTER TABLE ollama_jobs ADD COLUMN node VARCHAR(16) NULL");
        if (!$pdo->query("SHOW COLUMNS FROM ollama_jobs LIKE 'cands'")->fetch()) $pdo->exec("ALTER TABLE ollama_jobs ADD COLUMN cands TEXT NULL");
        // کشِ متنِ OCRِ هر صفحه (برای اینکه مرحله‌های بعدی و پردازشِ دوباره، OCR را تکرار نکنند)
        $pdo->exec("CREATE TABLE IF NOT EXISTS doc_ocr_cache (hash CHAR(40) NOT NULL, page INT NOT NULL, text MEDIUMTEXT NULL, engine VARCHAR(20) NULL, created_at DATETIME NULL,
            PRIMARY KEY (hash, page)) $o");
    } catch (Throwable $e) { error_log('[ol_ensure] ' . $e->getMessage()); }
}

// وضعیتِ برنامه‌ی ویندوزیِ هر سیستم (آخرین تماس، مدل‌های نصب‌شده) — سیستمِ ۱ِ مهاجرت‌شده، وضعیتِ قدیمی (ollama_worker) را هم می‌خواند
function ol_node_state($pdo, $id) {
    $w = json_decode((string)sms_get($pdo, 'ollama_node_' . $id, ''), true);
    if (!is_array($w) && $id === 'n1') $w = json_decode((string)sms_get($pdo, 'ollama_worker', '{}'), true);
    $w = is_array($w) ? $w : [];
    $at = intval($w['at'] ?? 0);
    $blocked = (string)($w['blocked'] ?? '');
    return ['at' => $at, 'ago' => $at ? time() - $at : null, 'online' => $at && time() - $at < 90 && $blocked === '', 'reached' => $at && time() - $at < 90, 'blocked' => $blocked,
            'models' => array_values((array)($w['models'] ?? [])), 'host' => (string)($w['host'] ?? ''), 'version' => (string)($w['version'] ?? ''), 'ollama' => !empty($w['ollama'])];
}
// همه‌ی سیستم‌ها با وضعیت (busy = تعدادِ کارِ در حالِ اجرا)؛ کلید فقط با ۴ نویسه‌ی آخر
function ol_nodes($pdo, array $c = null) {
    $c = $c ?: ol_cfg($pdo);
    $busy = [];
    try { ol_ensure($pdo); foreach ($pdo->query("SELECT node, COUNT(*) n FROM ollama_jobs WHERE status = 'RUNNING' AND node IS NOT NULL GROUP BY node") as $r) $busy[$r['node']] = intval($r['n']); } catch (Throwable $e) {}
    $out = [];
    foreach ($c['nodes'] as $id => $n) {
        $s = ol_node_state($pdo, $id);
        $out[$id] = ['id' => $id, 'name' => $n['name'], 'enabled' => $n['enabled'], 'busy' => $busy[$id] ?? 0,
                     'token_mask' => substr($n['token'], 0, 6) . '…' . substr($n['token'], -4)] + $s + ['ago_fa' => ol_ago_fa($s['ago'])];
    }
    return $out;
}
// برای سازگاری: وضعیتِ کلی (یکی وصل باشد = وصل؛ مدل‌ها = همه‌ی مدل‌های سیستم‌های روشن)
function ol_worker($pdo) {
    $best = ['at' => 0, 'ago' => null, 'online' => false, 'models' => [], 'host' => '', 'version' => '', 'ollama' => false];
    foreach (ol_nodes($pdo) as $n) {
        if (!$n['enabled']) continue;
        $best['models'] = array_values(array_unique(array_merge($best['models'], $n['models'])));
        if ($n['at'] > $best['at']) { $best['at'] = $n['at']; $best['ago'] = $n['ago']; $best['host'] = $n['host']; $best['version'] = $n['version']; }
        $best['online'] = $best['online'] || $n['online'];
        $best['ollama'] = $best['ollama'] || ($n['online'] && $n['ollama']);
    }
    return $best;
}
// سیستمی که این کلید را دارد (برنامه‌ی ویندوزی خودش را فقط با کلید معرفی می‌کند)
function ol_node_by_token($pdo, $tok) {
    $tok = (string)$tok;
    if ($tok === '') return null;
    foreach (ol_cfg($pdo)['nodes'] as $n) if (hash_equals($n['token'], $tok)) return $n;
    return null;
}
function ol_heartbeat($pdo, $nodeId, array $in) {
    $models = array_values(array_filter(array_map(function ($m) { return mb_substr(trim((string)$m), 0, 120); }, (array)($in['models'] ?? []))));
    sms_set($pdo, 'ollama_node_' . $nodeId, json_encode(['at' => time(), 'models' => array_slice($models, 0, 60), 'host' => mb_substr((string)($in['host'] ?? ''), 0, 80),
                                                       'version' => mb_substr((string)($in['version'] ?? ''), 0, 20), 'ollama' => !empty($in['ollama']),
                                                       'blocked' => (string)($in['blocked'] ?? '')], JSON_UNESCAPED_UNICODE));
}
// مدلِ نصب‌شده؟ («llama3» = «llama3:latest»)
function ol_has_model(array $models, $m) {
    $m = strtolower(trim((string)$m));
    foreach ($models as $x) {
        $x = strtolower(trim((string)$x));
        if ($x === $m || $x === $m . ':latest' || $x . ':latest' === $m) return true;
    }
    return false;
}
// فهرستِ اولویت‌دارِ [سیستم، مدل] برای یک کار (یا یک مدلِ مشخص، مثلاً گفتگوی هوشیکس / آزمایش)؛ سیستم‌های خاموش کنار می‌روند
function ol_cands(array $c, $purpose, $model = null, $node = null) {
    $list = $model !== null && $model !== '' ? [['node' => (string)$node, 'model' => (string)$model]] : ($c['routes'][$purpose] ?? []);
    $on = array_filter($c['nodes'], function ($n) { return $n['enabled']; });
    return array_values(array_filter($list, function ($r) use ($on) { return $r['node'] === '' ? (bool)$on : isset($on[$r['node']]); }));
}
// چرا این کار با سیستمِ داخلی انجام نمی‌شود؟ (متنِ دقیق + جای درست‌کردنش؛ خالی = مشکلی نیست)
function ol_why($pdo, $purpose, $model = null, $node = null) {
    $c = ol_cfg($pdo);
    $where = 'استعلامات ← تنظیمات ← پردازش فایل هوشمند ← «سیستمِ داخلی (Ollama)»';
    if (!$c['enabled']) return 'سیستمِ داخلی (Ollama) در تنظیمات خاموش است — ' . $where . ': تیکِ «سیستمِ داخلی روشن باشد» را بزنید (همان لحظه ذخیره می‌شود).';
    if (!array_filter($c['nodes'], function ($n) { return $n['enabled']; })) return 'هیچ سیستمی (کامپیوتری) برای سیستمِ داخلی اضافه یا روشن نشده — ' . $where . ': «افزودنِ سیستم» را بزنید و برنامه‌ی ویندوزِ همان سیستم را دانلود و نصب کنید.';
    if ($node !== null && $node !== '' && empty($c['nodes'][$node]['enabled'])) return 'این سیستم پیدا نشد یا در تنظیمات خاموش است.';
    if (!ol_cands($c, $purpose, $model, $node)) return 'برای «' . (OL_TASKS[$purpose] ?? $purpose) . '» هیچ مدلی در «اولویت‌ها»ی سیستمِ داخلی تعیین نشده — ' . $where . '.';
    return '';
}
// روشن و دست‌کم یک سیستمِ روشن (برای هوشیکس)
function ol_enabled($pdo) { $c = ol_cfg($pdo); return $c['enabled'] && (bool)array_filter($c['nodes'], function ($n) { return $n['enabled']; }); }
// یکی از نامزدهای این کار الان وصل است و مدل را دارد؟
function ol_any_online($pdo, array $cands, array $st = null) {
    $st = $st ?: ol_nodes($pdo);
    foreach ($cands as $r) foreach ($st as $id => $n)
        if ($n['enabled'] && $n['online'] && ($r['node'] === '' || $r['node'] === $id) && ol_has_model($n['models'], $r['model'])) return true;
    return false;
}
// سیستمِ داخلی برای این کار تنظیم شده؟ (روشن + تیکِ همین کار + سیستم و مدل در اولویت‌ها) — اگر نه، سرویسِ آنلاین مجاز است
function ol_wants($pdo, $what) { $c = ol_cfg($pdo); return !empty($c['use_' . $what]) && ol_why($pdo, $what) === ''; }
// سرویسِ آنلاین (GapGPT / خواندنِ اسکن) برای این کار مجاز است؟ (سیستمِ داخلی برایش تنظیم نشده، یا «جایگزین» روشن است)
function ol_online_ok($pdo, $what) { return !ol_wants($pdo, $what) || ol_cfg($pdo)['fallback']; }
// برای این کار (ocr | extract | vision) از سیستمِ داخلی استفاده شود؟ (روشن، مدل در اولویت‌ها، و اگر «جایگزین» روشن است: یکی از سیستم‌ها وصل)
function ol_use($pdo, $what) {
    $c = ol_cfg($pdo);
    if (!ol_wants($pdo, $what)) return false;
    if ($c['fallback'] && !ol_any_online($pdo, ol_cands($c, $what))) return false;   // هیچ‌کدام وصل نیست => سرویسِ آنلاین
    return true;
}

// زمینه‌ی درخواست‌ها (کدام مدرک منتظرِ جواب است) و «منتظر نمان» (داخلِ پاسخِ خودِ برنامه‌ی ویندوزی)
function ol_ctx($set = null) { static $c = ''; if ($set !== null) $c = (string)$set; return $c; }
function ol_nowait($set = null) { static $n = false; if ($set !== null) $n = (bool)$set; return $n; }

// یک درخواستِ گفتگو به Ollama (/api/chat): $messages = [[role, content, images => [base64...]], ...]
//   $model = null => مدل و سیستم طبقِ «اولویت‌ها»ی همین کار؛ مدلِ مشخص => هر سیستمی که آن را دارد ($opt['node'] = فقط همان سیستم، $opt['prefer'] = اول همان سیستم)
//   خروجی: ok + text | pending (در صف) | error
function ol_call($pdo, $purpose, $model, array $messages, array $opt = [], $wait = null) {
    ol_ensure($pdo);
    $c = ol_cfg($pdo);
    $node = isset($opt['node']) ? (string)$opt['node'] : null;
    $why = ol_why($pdo, $purpose, $model, $node);
    if ($why !== '') return ['ok' => false, 'error' => $why];
    $cands = ol_cands($c, $purpose, $model, $node);
    // ترجیحِ یک سیستم (مثلاً گفتگوی هوشیکس روی همان سیستمی می‌ماند که شروعش کرد)؛ اگر وصل نبود، بقیه
    if (!empty($opt['prefer']) && ($node === null || $node === '') && $model !== null && $model !== '') {
        $p = ol_cands($c, $purpose, $model, (string)$opt['prefer']);
        if ($p) $cands = array_merge($p, $cands);
    }
    $payload = ['model' => $cands[0]['model'], 'messages' => $messages, 'stream' => false, 'keep_alive' => '30m',
                'options' => ['temperature' => $opt['temperature'] ?? 0, 'num_ctx' => intval($opt['num_ctx'] ?? $c['num_ctx'])]];
    if (!empty($opt['json'])) $payload['format'] = 'json';
    if (!empty($opt['num_predict'])) $payload['options']['num_predict'] = intval($opt['num_predict']);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $cj = json_encode(array_map(function ($r) { return [$r['node'], $r['model']]; }, $cands), JSON_UNESCAPED_UNICODE);
    $hash = sha1($json . '|' . $cj);
    $ctx = ol_ctx();
    $wait = ol_nowait() ? 0 : ($wait === null ? $c['wait'] : intval($wait));
    $st = $pdo->prepare("SELECT id, status, result, error, ctx, tokens, ms, finished_at, node FROM ollama_jobs WHERE hash = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$hash]);
    $j = $st->fetch();
    // جوابِ خطای قدیمی‌تر از ۱۰ دقیقه دوباره امتحان می‌شود
    if ($j && $j['status'] === 'FAIL' && strtotime((string)$j['finished_at']) < time() - 600) $j = null;
    if ($j && $j['status'] === 'DONE') return ['ok' => true, 'text' => (string)$j['result'], 'tokens' => intval($j['tokens']), 'ms' => intval($j['ms']), 'cached' => true, 'job_id' => intval($j['id']), 'node' => (string)$j['node']];
    if ($j && $j['status'] === 'FAIL') return ['ok' => false, 'error' => 'سیستمِ داخلی: ' . ($j['error'] ?: 'خطا'), 'job_id' => intval($j['id'])];
    if ($j) {
        $id = intval($j['id']);
        if ($ctx !== '' && strpos(',' . $j['ctx'] . ',', ',' . $ctx . ',') === false)
            $pdo->prepare("UPDATE ollama_jobs SET ctx = LEFT(TRIM(BOTH ',' FROM CONCAT(COALESCE(ctx, ''), ',', ?)), 500) WHERE id = ?")->execute([$ctx, $id]);
    } else {
        $pdo->prepare("INSERT INTO ollama_jobs (hash, purpose, model, payload, cands, ctx, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$hash, mb_substr($purpose, 0, 12), mb_substr($cands[0]['model'], 0, 120), $json, $cj, $ctx !== '' ? $ctx : null]);
        $id = intval($pdo->lastInsertId());
    }
    $until = microtime(true) + $wait;
    $q = $pdo->prepare("SELECT status, result, error, tokens, ms, node FROM ollama_jobs WHERE id = ?");
    while (true) {
        $q->execute([$id]);
        $r = $q->fetch();
        if ($r && $r['status'] === 'DONE') return ['ok' => true, 'text' => (string)$r['result'], 'tokens' => intval($r['tokens']), 'ms' => intval($r['ms']), 'job_id' => $id, 'node' => (string)$r['node']];
        if ($r && $r['status'] === 'FAIL') return ['ok' => false, 'error' => 'سیستمِ داخلی: ' . ($r['error'] ?: 'خطا'), 'job_id' => $id];
        if (microtime(true) >= $until) break;
        usleep(700000);
    }
    $st = ol_nodes($pdo, $c);
    $online = array_filter($st, function ($n) { return $n['enabled'] && $n['online']; });
    if (ol_any_online($pdo, $cands, $st)) $msg = 'در صفِ سیستمِ داخلی (Ollama) است؛ جوابش که برسد خودکار روی همین مدرک می‌نشیند.';
    elseif ($online) $msg = 'هیچ‌کدام از سیستم‌های وصل، مدلِ «' . implode('» / «', array_unique(array_column($cands, 'model'))) . '» را نصب ندارند (یا نامِ مدل در «اولویت‌ها» با نامِ نصب‌شده یکی نیست)؛ کار در صف ماند.';
    else {
        $last = 0; foreach ($st as $n) if ($n['enabled']) $last = max($last, $n['at']);
        $msg = 'هیچ سیستمی از سیستمِ داخلی (Ollama) الان وصل نیست' . ($last ? ' (آخرین اتصال ' . ol_ago_fa(time() - $last) . ')' : ' (برنامه‌ی ویندوزی هنوز هیچ‌وقت وصل نشده)') . '؛ کار در صف ماند و وقتی برنامه‌ی ویندوزی وصل شد انجام می‌شود.';
    }
    return ['ok' => false, 'pending' => true, 'job_id' => $id, 'error' => $msg];
}
function ol_ago_fa($s) {
    if ($s === null) return '—';
    if ($s < 90) return 'لحظاتی پیش';
    if ($s < 3600) return intval($s / 60) . ' دقیقه پیش';
    if ($s < 86400) return intval($s / 3600) . ' ساعت پیش';
    return intval($s / 86400) . ' روز پیش';
}
// عکس برای Ollama: بزرگ‌ترین ضلع حداکثر ۱۶۰۰ پیکسل (روی پردازنده، عکسِ بزرگ‌تر خیلی کندتر است و دقیق‌تر هم نمی‌شود) => base64ِ JPEG
function ol_image_b64($bytes, $max = 1600) {
    $bytes = (string)$bytes;
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($bytes))) {
        $w = imagesx($im); $h = imagesy($im);
        if (max($w, $h) > $max) {
            $k = $max / max($w, $h);
            $nw = max(1, intval($w * $k)); $nh = max(1, intval($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagejpeg($dst, null, 88); $bytes = (string)ob_get_clean();
            imagedestroy($dst);
        }
        imagedestroy($im);
    }
    return base64_encode($bytes);
}
// متنِ JSON از جواب (مثلِ ds_json_extract)
function ol_text_json($text) {
    if (!function_exists('ds_json_extract')) require_once __DIR__ . '/_doc_smart.php';
    return ds_json_extract((string)$text);
}

// ---- سمتِ برنامه‌ی ویندوزی ----
// کارِ بعدی برای این سیستم (اول گفتگوهای هوشیکس، بعد مدارک)؛ کارِ «در حال اجرا»ی ماندگار (بیش از ۲۰ دقیقه) دوباره در صف
//   هر کار فهرستِ اولویت‌دارِ [سیستم، مدل] دارد: این سیستم آن را می‌گیرد اگر نامزدِ بالاتری که مدل را دارد وصل نیست
//   (یا با «پخشِ کار»، مشغول است). اگر هیچ سیستمی مدل را گزارش نکرده، اولین نامزدِ متناسب امتحان می‌کند (خطای Ollama دیده شود، نه صفِ بی‌پایان).
function ol_pick(array $job, $me, array $st, $spread) {
    $cands = json_decode((string)($job['cands'] ?? ''), true);
    if (!is_array($cands) || !$cands) $cands = [['', (string)$job['model']]];   // کارهای قبل از چندسیستمی
    $any = null;
    foreach ($cands as $cd) {
        $cn = (string)($cd[0] ?? ''); $m = (string)($cd[1] ?? '');
        if ($m === '') continue;
        foreach ($st as $id => $n) {
            if (!$n['enabled'] || ($cn !== '' && $cn !== $id)) continue;
            if ($any === null && $id === $me) $any = $m;
            if (!ol_has_model($n['models'], $m)) continue;
            if ($id === $me) return $m;
            if ($n['online'] && (!$spread || !$n['busy'])) return null;   // سیستمِ بهتری آماده است
        }
    }
    // هیچ‌کدام از نامزدها مدل را ندارد => اولین نامزدِ این سیستم امتحان کند
    foreach ($cands as $cd) foreach ($st as $id => $n) if ($n['enabled'] && $n['online'] && ol_has_model($n['models'], (string)($cd[1] ?? ''))) return null;
    return $any;
}
function ol_claim($pdo, $me) {
    ol_ensure($pdo);
    try { $pdo->exec("UPDATE ollama_jobs SET status = 'QUEUED', started_at = NULL, node = NULL WHERE status = 'RUNNING' AND started_at < (NOW() - INTERVAL 20 MINUTE)"); } catch (Throwable $e) {}
    $c = ol_cfg($pdo);
    $st = ol_nodes($pdo, $c);
    if (isset($st[$me])) { $st[$me]['online'] = true; $st[$me]['busy'] = 0; }
    $rows = $pdo->query("SELECT id, purpose, model, cands FROM ollama_jobs WHERE status = 'QUEUED' ORDER BY purpose = 'chat' DESC, id LIMIT 60")->fetchAll();
    foreach ($rows as $j) {
        $m = ol_pick($j, $me, $st, $c['spread']);
        if ($m === null) continue;
        $up = $pdo->prepare("UPDATE ollama_jobs SET status = 'RUNNING', started_at = NOW(), node = ?, model = ? WHERE id = ? AND status = 'QUEUED'");
        $up->execute([$me, mb_substr($m, 0, 120), $j['id']]);
        if ($up->rowCount()) return ['id' => intval($j['id']), 'purpose' => $j['purpose'], 'model' => $m];
    }
    return null;
}
// بدنه‌ی درخواست برای همین سیستم (اگر مدلِ این سیستم با مدلِ اول فرق دارد، فقط نامِ مدل عوض می‌شود)
function ol_payload($pdo, $id, $me) {
    $st = $pdo->prepare("SELECT payload, model FROM ollama_jobs WHERE id = ? AND status = 'RUNNING' AND (node = ? OR node IS NULL)");
    $st->execute([intval($id), (string)$me]);
    $r = $st->fetch();
    if (!$r || $r['payload'] === null) return null;
    $p = (string)$r['payload'];
    if (preg_match('/^\{"model":("(?:[^"\\\\]|\\\\.)*")/', $p, $m) && json_decode($m[1]) !== $r['model'])
        $p = '{"model":' . json_encode((string)$r['model'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . substr($p, strlen($m[0]));
    return $p;
}
// جواب رسید: ذخیره، و مدارکی که منتظرِ همین جواب بودند دوباره پردازش می‌شوند (بدونِ انتظار؛ کارهای بعدی فقط صف می‌شوند)
function ol_complete($pdo, $siteRoot, $id, $ok, $text, $error, $ms, $tokens, $me = null) {
    $st = $pdo->prepare("SELECT ctx, node FROM ollama_jobs WHERE id = ?");
    $st->execute([intval($id)]);
    $j = $st->fetch();
    if (!$j) return 0;
    if ($me !== null && $j['node'] !== null && $j['node'] !== (string)$me) return 0;   // این کار به سیستمِ دیگری داده شده
    $ctx = $j['ctx'];
    $pdo->prepare("UPDATE ollama_jobs SET status = ?, result = ?, error = ?, ms = ?, tokens = ?, finished_at = NOW(), payload = NULL WHERE id = ?")
        ->execute([$ok ? 'DONE' : 'FAIL', $ok ? (string)$text : null, $ok ? null : mb_substr((string)$error, 0, 500), intval($ms), intval($tokens), intval($id)]);
    $n = 0;
    ol_nowait(true);
    foreach (array_unique(array_filter(explode(',', (string)$ctx))) as $c) {
        if (!preg_match('/^doc:(\d+)$/', $c, $m)) continue;
        try { ol_resume_doc($pdo, $siteRoot, intval($m[1])); $n++; } catch (Throwable $e) { error_log('[ol_resume] ' . $e->getMessage()); }
    }
    ol_nowait(false);
    // پاک‌سازیِ کارهای قدیمی (جوابِ مدارک در خودِ مدرک ذخیره شده)
    if (mt_rand(1, 40) === 1) try { $pdo->exec("DELETE FROM ollama_jobs WHERE status IN ('DONE', 'FAIL') AND finished_at < (NOW() - INTERVAL 14 DAY)"); } catch (Throwable $e) {}
    return $n;
}
// ادامه‌ی پردازشِ یک مدرک بعد از رسیدنِ جواب: در صندوق => همان مسیرِ صندوق؛ روی ردیف => «پردازش»ِ ردیف
function ol_resume_doc($pdo, $siteRoot, $docId) {
    foreach (['_case_helpers', '_company_helpers', 'finance_core', '_vdoc'] as $f) require_once __DIR__ . '/' . $f . '.php';
    $st = $pdo->prepare("SELECT id, status, plate_id, file_kind, ai_status, ai_json FROM company_documents WHERE id = ?");
    $st->execute([$docId]);
    $d = $st->fetch();
    if (!$d || $d['file_kind'] !== 'SUPPORTING_DOC') return;
    // فقط مدرکی که منتظرِ همین جواب مانده (در صف، یا «بررسی با عکس» در صف)؛ نه مدرکی که درخواستِ اصلی هنوز رویش کار می‌کند
    $aj = json_decode((string)$d['ai_json'], true) ?: [];
    if ($d['ai_status'] !== 'WAIT' && empty($aj['vision_pending']) && empty($aj['ai_pending'])) return;
    if ($d['status'] === 'UNASSIGNED') { vdoc_queue($pdo, [$docId], true, true); vdoc_process_company_doc($pdo, $siteRoot, $docId); }
    elseif ($d['status'] === 'ASSIGNED' && $d['plate_id']) vdoc_process_row_doc($pdo, $siteRoot, $docId);
}
// آمارِ صف (برای تنظیمات)؛ by_node = انجام‌شده‌ی امروزِ هر سیستم
function ol_stats($pdo) {
    ol_ensure($pdo);
    $o = ['queued' => 0, 'running' => 0, 'done_today' => 0, 'fail_today' => 0, 'by_node' => []];
    try {
        foreach ($pdo->query("SELECT status, COUNT(*) n FROM ollama_jobs WHERE status IN ('QUEUED', 'RUNNING') GROUP BY status") as $r) $o[strtolower($r['status'])] = intval($r['n']);
        foreach ($pdo->query("SELECT status, COUNT(*) n FROM ollama_jobs WHERE status IN ('DONE', 'FAIL') AND finished_at >= CURDATE() GROUP BY status") as $r) $o[$r['status'] === 'DONE' ? 'done_today' : 'fail_today'] = intval($r['n']);
        foreach ($pdo->query("SELECT node, COUNT(*) n FROM ollama_jobs WHERE status = 'DONE' AND finished_at >= CURDATE() AND node IS NOT NULL GROUP BY node") as $r) $o['by_node'][$r['node']] = intval($r['n']);
    } catch (Throwable $e) {}
    return $o;
}
