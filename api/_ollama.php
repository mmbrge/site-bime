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

function ol_cfg($pdo) {
    $c = api_cfg($pdo)['ollama'] ?? [];
    $b = function ($k, $d) use ($c) { return array_key_exists($k, $c) ? !empty($c[$k]) : $d; };
    return [
        'enabled' => $b('enabled', false),
        'token' => trim((string)($c['token'] ?? '')),
        'ocr_model' => trim((string)($c['ocr_model'] ?? '')),        // خواندنِ متنِ عکس (موتورِ دومِ OCR بعد از i2OCR)
        'text_model' => trim((string)($c['text_model'] ?? '')),      // استخراجِ فیلدها از متن
        'vision_model' => trim((string)($c['vision_model'] ?? '')),  // بررسیِ کامل با خودِ عکس
        'use_ocr' => $b('use_ocr', true),
        'use_extract' => $b('use_extract', true),
        'use_vision' => $b('use_vision', true),
        'auto_vision' => $b('auto_vision', true),                    // فیلدِ لازمی پیدا نشد => خودکار با عکس
        'fallback' => $b('fallback', false),                         // سیستمِ داخلی خاموش بود => سرویسِ آنلاین (GapGPT)
        'wait' => max(10, min(240, intval($c['wait'] ?? 120) ?: 120)),
        'num_ctx' => max(2048, min(32768, intval($c['num_ctx'] ?? 8192) ?: 8192)),
    ];
}
function ol_save_cfg($pdo, array $in) {
    $all = api_cfg($pdo);
    $c = $all['ollama'] ?? [];
    foreach (['enabled', 'use_ocr', 'use_extract', 'use_vision', 'auto_vision', 'fallback'] as $k) if (array_key_exists($k, $in)) $c[$k] = !empty($in[$k]) ? 1 : 0;
    foreach (['ocr_model', 'text_model', 'vision_model'] as $k) if (array_key_exists($k, $in)) $c[$k] = mb_substr(trim((string)$in[$k]), 0, 120);
    foreach (['wait', 'num_ctx'] as $k) if (array_key_exists($k, $in)) $c[$k] = intval($in[$k]);
    if (!empty($in['new_token']) || trim((string)($c['token'] ?? '')) === '') $c['token'] = bin2hex(random_bytes(24));
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
        // کشِ متنِ OCRِ هر صفحه (برای اینکه مرحله‌های بعدی و پردازشِ دوباره، OCR را تکرار نکنند)
        $pdo->exec("CREATE TABLE IF NOT EXISTS doc_ocr_cache (hash CHAR(40) NOT NULL, page INT NOT NULL, text MEDIUMTEXT NULL, engine VARCHAR(20) NULL, created_at DATETIME NULL,
            PRIMARY KEY (hash, page)) $o");
    } catch (Throwable $e) { error_log('[ol_ensure] ' . $e->getMessage()); }
}

// وضعیتِ برنامه‌ی ویندوزی (آخرین تماس، مدل‌های نصب‌شده)
function ol_worker($pdo) {
    $w = json_decode((string)sms_get($pdo, 'ollama_worker', '{}'), true) ?: [];
    $at = intval($w['at'] ?? 0);
    return ['at' => $at, 'ago' => $at ? time() - $at : null, 'online' => $at && time() - $at < 90, 'models' => array_values((array)($w['models'] ?? [])),
            'host' => (string)($w['host'] ?? ''), 'version' => (string)($w['version'] ?? ''), 'ollama' => !empty($w['ollama'])];
}
function ol_heartbeat($pdo, array $in) {
    $models = array_values(array_filter(array_map(function ($m) { return mb_substr(trim((string)$m), 0, 120); }, (array)($in['models'] ?? []))));
    sms_set($pdo, 'ollama_worker', json_encode(['at' => time(), 'models' => array_slice($models, 0, 60), 'host' => mb_substr((string)($in['host'] ?? ''), 0, 80),
                                                'version' => mb_substr((string)($in['version'] ?? ''), 0, 20), 'ollama' => !empty($in['ollama'])], JSON_UNESCAPED_UNICODE));
}
// برای این کار (ocr | extract | vision) از سیستمِ داخلی استفاده شود؟ (روشن، مدلِ همان کار، و اگر «جایگزین» روشن است: برنامه‌ی ویندوزی آنلاین)
function ol_use($pdo, $what) {
    $c = ol_cfg($pdo);
    $model = $c[['ocr' => 'ocr_model', 'extract' => 'text_model', 'vision' => 'vision_model'][$what] ?? ''] ?? '';
    if (!$c['enabled'] || $c['token'] === '' || $model === '' || empty($c['use_' . $what])) return false;
    if ($c['fallback'] && !ol_worker($pdo)['online']) return false;   // خاموش است => سرویسِ آنلاین
    return true;
}

// زمینه‌ی درخواست‌ها (کدام مدرک منتظرِ جواب است) و «منتظر نمان» (داخلِ پاسخِ خودِ برنامه‌ی ویندوزی)
function ol_ctx($set = null) { static $c = ''; if ($set !== null) $c = (string)$set; return $c; }
function ol_nowait($set = null) { static $n = false; if ($set !== null) $n = (bool)$set; return $n; }

// یک درخواستِ گفتگو به Ollama (/api/chat): $messages = [[role, content, images => [base64...]], ...]
//   خروجی: ok + text | pending (در صف) | error
function ol_call($pdo, $purpose, $model, array $messages, array $opt = [], $wait = null) {
    ol_ensure($pdo);
    $c = ol_cfg($pdo);
    if (!$c['enabled'] || $c['token'] === '') return ['ok' => false, 'error' => 'سیستمِ داخلی (Ollama) در تنظیمات روشن نیست.'];
    $payload = ['model' => $model, 'messages' => $messages, 'stream' => false, 'keep_alive' => '30m',
                'options' => ['temperature' => $opt['temperature'] ?? 0, 'num_ctx' => intval($opt['num_ctx'] ?? $c['num_ctx'])]];
    if (!empty($opt['json'])) $payload['format'] = 'json';
    if (!empty($opt['num_predict'])) $payload['options']['num_predict'] = intval($opt['num_predict']);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hash = sha1($json);
    $ctx = ol_ctx();
    $wait = ol_nowait() ? 0 : ($wait === null ? $c['wait'] : intval($wait));
    $st = $pdo->prepare("SELECT id, status, result, error, ctx, tokens, ms, finished_at FROM ollama_jobs WHERE hash = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$hash]);
    $j = $st->fetch();
    // جوابِ خطای قدیمی‌تر از ۱۰ دقیقه دوباره امتحان می‌شود
    if ($j && $j['status'] === 'FAIL' && strtotime((string)$j['finished_at']) < time() - 600) $j = null;
    if ($j && $j['status'] === 'DONE') return ['ok' => true, 'text' => (string)$j['result'], 'tokens' => intval($j['tokens']), 'ms' => intval($j['ms']), 'cached' => true, 'job_id' => intval($j['id'])];
    if ($j && $j['status'] === 'FAIL') return ['ok' => false, 'error' => 'سیستمِ داخلی: ' . ($j['error'] ?: 'خطا'), 'job_id' => intval($j['id'])];
    if ($j) {
        $id = intval($j['id']);
        if ($ctx !== '' && strpos(',' . $j['ctx'] . ',', ',' . $ctx . ',') === false)
            $pdo->prepare("UPDATE ollama_jobs SET ctx = LEFT(TRIM(BOTH ',' FROM CONCAT(COALESCE(ctx, ''), ',', ?)), 500) WHERE id = ?")->execute([$ctx, $id]);
    } else {
        $pdo->prepare("INSERT INTO ollama_jobs (hash, purpose, model, payload, ctx, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$hash, mb_substr($purpose, 0, 12), mb_substr($model, 0, 120), $json, $ctx !== '' ? $ctx : null]);
        $id = intval($pdo->lastInsertId());
    }
    $until = microtime(true) + $wait;
    $q = $pdo->prepare("SELECT status, result, error, tokens, ms FROM ollama_jobs WHERE id = ?");
    while (true) {
        $q->execute([$id]);
        $r = $q->fetch();
        if ($r && $r['status'] === 'DONE') return ['ok' => true, 'text' => (string)$r['result'], 'tokens' => intval($r['tokens']), 'ms' => intval($r['ms']), 'job_id' => $id];
        if ($r && $r['status'] === 'FAIL') return ['ok' => false, 'error' => 'سیستمِ داخلی: ' . ($r['error'] ?: 'خطا'), 'job_id' => $id];
        if (microtime(true) >= $until) break;
        usleep(700000);
    }
    $w = ol_worker($pdo);
    return ['ok' => false, 'pending' => true, 'job_id' => $id,
            'error' => $w['online'] ? 'در صفِ سیستمِ داخلی (Ollama) است؛ جوابش که برسد خودکار روی همین مدرک می‌نشیند.'
                                    : 'سیستمِ داخلی (Ollama) الان در دسترس نیست' . ($w['at'] ? ' (آخرین اتصال ' . ol_ago_fa($w['ago']) . ')' : '') . '؛ کار در صف ماند و وقتی برنامه‌ی ویندوزی وصل شد انجام می‌شود.'];
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
// کارِ بعدی (اول گفتگوهای هوشیکس، بعد مدارک)؛ کارِ «در حال اجرا»ی ماندگار (بیش از ۲۰ دقیقه) دوباره در صف
function ol_claim($pdo) {
    ol_ensure($pdo);
    try { $pdo->exec("UPDATE ollama_jobs SET status = 'QUEUED', started_at = NULL WHERE status = 'RUNNING' AND started_at < (NOW() - INTERVAL 20 MINUTE)"); } catch (Throwable $e) {}
    for ($i = 0; $i < 3; $i++) {
        $id = $pdo->query("SELECT id FROM ollama_jobs WHERE status = 'QUEUED' ORDER BY purpose = 'chat' DESC, id LIMIT 1")->fetchColumn();
        if (!$id) return null;
        $up = $pdo->prepare("UPDATE ollama_jobs SET status = 'RUNNING', started_at = NOW() WHERE id = ? AND status = 'QUEUED'");
        $up->execute([$id]);
        if ($up->rowCount()) {
            $st = $pdo->prepare("SELECT id, purpose, model FROM ollama_jobs WHERE id = ?");
            $st->execute([$id]);
            return $st->fetch();
        }
    }
    return null;
}
function ol_payload($pdo, $id) {
    $st = $pdo->prepare("SELECT payload FROM ollama_jobs WHERE id = ? AND status = 'RUNNING'");
    $st->execute([intval($id)]);
    $p = $st->fetchColumn();
    return $p === false ? null : (string)$p;
}
// جواب رسید: ذخیره، و مدارکی که منتظرِ همین جواب بودند دوباره پردازش می‌شوند (بدونِ انتظار؛ کارهای بعدی فقط صف می‌شوند)
function ol_complete($pdo, $siteRoot, $id, $ok, $text, $error, $ms, $tokens) {
    $st = $pdo->prepare("SELECT ctx FROM ollama_jobs WHERE id = ?");
    $st->execute([intval($id)]);
    $ctx = $st->fetchColumn();
    if ($ctx === false) return 0;
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
// آمارِ صف (برای تنظیمات)
function ol_stats($pdo) {
    ol_ensure($pdo);
    $o = ['queued' => 0, 'running' => 0, 'done_today' => 0, 'fail_today' => 0];
    try {
        foreach ($pdo->query("SELECT status, COUNT(*) n FROM ollama_jobs WHERE status IN ('QUEUED', 'RUNNING') GROUP BY status") as $r) $o[strtolower($r['status'])] = intval($r['n']);
        foreach ($pdo->query("SELECT status, COUNT(*) n FROM ollama_jobs WHERE status IN ('DONE', 'FAIL') AND finished_at >= CURDATE() GROUP BY status") as $r) $o[$r['status'] === 'DONE' ? 'done_today' : 'fail_today'] = intval($r['n']);
    } catch (Throwable $e) {}
    return $o;
}
