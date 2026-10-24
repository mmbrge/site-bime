<?php
// فایل: api/_i2pdf.php
// موتورِ «i2pdf» برای «پردازش فایل هوشمند» — همان مسیری که سایتِ www.i2pdf.com/pdf-ocr در مرورگر طی می‌کند (از روی HARِ واقعی):
//   ۱) GET /pdf-ocr                       ← کوکیِ نشست + X-CSRF-Token (در صفحه) + شماره‌ی build (نامِ pdf-ocr-editor.<build>.js)
//   ۲) POST /api/altcha  verification_action=challenge, scope=pdf-ocr, input_hash, options   ← {context, challenge}
//   ۳) حلِ ALTCHA (اثباتِ کار؛ همان «I'm not a robot») و POST /api/altcha verification_action=verify, context, payload ← {grant}
//   ۴) POST /api/pdf-ocr  فایلِ PDFِ تک‌صفحه + options + grant (+ سرتیترهای x-i2pdf-*)
//   ۵) پاسخ multipart: metadata، text (ocr.txt)، document (ocr.docx)، pdf (ocr.pdf) — همه‌ی قالب‌ها با هم (download_formats=all)
// نامِ فیلدهای فرمِ آپلود از خودِ اسکریپتِ سایت (‎.append("…")‎) خوانده می‌شود تا با تغییرِ سایت هم کار کند؛
// «آزمایشِ اتصال» در تنظیمات همه‌ی گام‌ها و یافته‌ها را گزارش می‌کند.
// پیشرفتِ هر گام برای تایم‌لاینِ پنل: so_progress در _smart_ocr.php

const I2_PAGE_URL = 'https://www.i2pdf.com/pdf-ocr';
const I2_FORMATS = ['txt' => 'Editable text (.txt)', 'simple_pdf' => 'Simple PDF (text only)', 'docx' => 'Word document (.docx)', 'pdf' => 'Searchable PDF'];
const I2_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36';

// ---------- ALTCHA (I'm not a robot): پیدا کردنِ عددی که hash(salt + n) = challenge ----------
function i2_altcha_solve(array $ch, $maxMs = 20000) {
    $algo = strtolower(str_replace('-', '', (string)($ch['algorithm'] ?? 'SHA-256')));
    if (!in_array($algo, ['sha1', 'sha256', 'sha512'], true)) return null;
    $target = strtolower((string)($ch['challenge'] ?? '')); $salt = (string)($ch['salt'] ?? '');
    if ($target === '' || $salt === '') return null;
    $max = intval($ch['maxnumber'] ?? ($ch['maxNumber'] ?? 1000000)) ?: 1000000;
    $t0 = microtime(true);
    for ($n = 0; $n <= $max; $n++) {
        if (hash($algo, $salt . $n) === $target) {
            $took = intval((microtime(true) - $t0) * 1000);
            return base64_encode(json_encode(['algorithm' => $ch['algorithm'] ?? 'SHA-256', 'challenge' => $ch['challenge'], 'number' => $n, 'salt' => $salt,
                                              'signature' => $ch['signature'] ?? '', 'took' => $took]));
        }
        if (($n & 0x3FFF) === 0 && (microtime(true) - $t0) * 1000 > $maxMs) return null;
    }
    return null;
}

function i2_conf($pdo) {
    $c = (api_cfg($pdo)['smart_ocr'] ?? [])['i2pdf'] ?? [];
    return ['page_url' => trim((string)($c['page_url'] ?? '')) ?: I2_PAGE_URL,
            'lang_fa' => trim((string)($c['lang_fa'] ?? '')) ?: 'fas', 'lang_en' => trim((string)($c['lang_en'] ?? '')) ?: 'eng',
            'engine' => trim((string)($c['engine'] ?? '')) ?: 'future', 'layout' => trim((string)($c['layout'] ?? '')) ?: '6',
            'format' => isset(I2_FORMATS[$c['format'] ?? '']) ? $c['format'] : 'txt', 'timeout' => max(30, min(280, intval($c['timeout'] ?? 150) ?: 150))];
}
function i2_ready($pdo) { return function_exists('curl_init') && class_exists('CURLFile'); }

function i2_http($method, $url, $headers, $jar, $body = null, $timeout = 60) {
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 25,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $h, CURLOPT_ENCODING => '', CURLOPT_HEADER => true]);
    if ($method !== 'GET') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method); curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '' : $body); }
    $resp = curl_exec($ch);
    $err = curl_error($ch); $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)); $hs = intval(curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $raw = $resp === false ? '' : (string)$resp;
    $hdr = substr($raw, 0, $hs);
    return ['http' => $code, 'error' => $err, 'type' => $ct, 'url' => $final, 'headers' => $hdr, 'body' => substr($raw, $hs)];
}
function i2_abs($u, $base) {
    if ($u === '' || preg_match('~^https?://~i', $u)) return $u;
    if (substr($u, 0, 2) === '//') return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $u;
    $p = parse_url($base); $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    return $u[0] === '/' ? $root . $u : $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $u;
}

// متنِ فایلِ نتیجه
function i2_text_of($bytes, $fmt) {
    if ($fmt === 'txt') return trim(preg_replace("/\r\n?/", "\n", preg_replace('/^\xEF\xBB\xBF/', '', $bytes)));
    $tmp = tempnam(sys_get_temp_dir(), 'i2') . ($fmt === 'docx' ? '.docx' : '.pdf');
    file_put_contents($tmp, $bytes);
    $t = '';
    try {
        if ($fmt === 'docx' && class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($tmp) === true) {
                $x = (string)$z->getFromName('word/document.xml'); $z->close();
                $x = preg_replace(['~</w:p>~', '~<w:tab/>~', '~<w:br[^>]*/>~'], ["\n", "\t", "\n"], $x);
                $t = html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        } elseif ($fmt !== 'docx') {
            require_once __DIR__ . '/_pdf_text.php';
            $t = implode("\n\n", pdfx_text($tmp));
        }
    } catch (Throwable $e) { error_log('[i2pdf text] ' . $e->getMessage()); }
    @unlink($tmp);
    return trim($t);
}

// پاسخِ multipart ← [name => ['filename', 'type', 'body']]
function i2_multipart($body, $ctype) {
    if (!preg_match('/boundary="?([^";]+)"?/i', (string)$ctype, $m)) return [];
    $out = [];
    foreach (explode('--' . $m[1], (string)$body) as $part) {
        if ($part === '' || strpos($part, '--') === 0) continue;
        $part = preg_replace('/^\r?\n/', '', $part);
        $sp = preg_split("/\r?\n\r?\n/", $part, 2);
        if (count($sp) < 2 || !preg_match('/name="([^"]*)"/i', $sp[0], $nm)) continue;
        $out[$nm[1]] = ['filename' => preg_match('/filename="([^"]*)"/i', $sp[0], $f) ? $f[1] : '', 'type' => preg_match('/Content-Type:\s*([^\r\n]+)/i', $sp[0], $t) ? trim($t[1]) : '',
                        'body' => preg_replace("/\r?\n$/", '', $sp[1])];
    }
    return $out;
}
// یافته‌های صفحه: توکنِ CSRF، build، آدرسِ اسکریپت
//   توکن: متا تگ‌ها (هر نامی که csrf/xsrf/token دارد)، تنظیماتِ درونِ صفحه ("csrf…": "…")، data-*csrf*، کوکیِ csrf/xsrf،
//   نامِ متا یا کلیدی که خودِ اسکریپتِ سایت می‌خواند ($jsHints)، و در آخر رشته‌ی ۶۴ رقمیِ هگزِ نزدیکِ کلمه‌ی csrf/token
function i2_discover_page($html, $pageUrl, $respHeaders = '', array $jsHints = []) {
    $d = ['csrf' => '', 'csrf_src' => '', 'build' => '', 'js' => '', 'site_js' => '', 'meta_names' => [], 'cookie_names' => []];
    $set = function ($v, $src) use (&$d) { $v = html_entity_decode(trim((string)$v)); if ($d['csrf'] === '' && strlen($v) >= 16 && strlen($v) <= 200 && !preg_match('/\s/', $v)) { $d['csrf'] = $v; $d['csrf_src'] = $src; } };
    // متا تگ‌ها
    preg_match_all('/<meta\b[^>]*>/i', $html, $ms);
    $metas = [];
    foreach ($ms[0] as $tag) {
        $nm = preg_match('/\b(?:name|property|http-equiv|id)\s*=\s*["\']([^"\']+)/i', $tag, $a) ? $a[1] : '';
        $ct = preg_match('/\bcontent\s*=\s*["\']([^"\']*)/i', $tag, $b) ? $b[1] : '';
        if ($nm !== '') { $metas[strtolower($nm)] = $ct; $d['meta_names'][] = $nm; }
    }
    foreach ($jsHints['meta'] ?? [] as $nm) if (isset($metas[strtolower($nm)])) $set($metas[strtolower($nm)], 'meta:' . $nm);
    foreach ($metas as $nm => $ct) if (preg_match('/csrf|xsrf/i', $nm)) $set($ct, 'meta:' . $nm);
    // ویژگی‌ها و تنظیماتِ درونِ صفحه
    foreach ($jsHints['keys'] ?? [] as $k) if (preg_match('/["\']?' . preg_quote($k, '/') . '["\']?\s*[:=]\s*["\']([A-Za-z0-9+\/=_.-]{16,200})["\']/', $html, $m)) $set($m[1], 'key:' . $k);
    if (preg_match('/\bdata-[\w-]*(?:csrf|xsrf)[\w-]*\s*=\s*["\']([^"\']+)/i', $html, $m)) $set($m[1], 'data-attr');
    if (preg_match('/["\']?[\w$.-]*(?:csrf|xsrf)[\w$.-]*["\']?\s*[:=]\s*["\']([A-Za-z0-9+\/=_.-]{16,200})["\']/i', $html, $m)) $set($m[1], 'inline');
    if (preg_match('/name\s*=\s*["\'][\w-]*(?:csrf|xsrf|token)[\w-]*["\'][^>]*value\s*=\s*["\']([^"\']+)/i', $html, $m)) $set($m[1], 'input');
    if (preg_match('/value\s*=\s*["\']([^"\']{16,})["\'][^>]*name\s*=\s*["\'][\w-]*(?:csrf|xsrf|token)/i', $html, $m)) $set($m[1], 'input');
    // کوکی‌ها
    preg_match_all('/^set-cookie:\s*([^=;\s]+)=([^;\r\n]*)/im', (string)$respHeaders, $cs, PREG_SET_ORDER);
    foreach ($cs as $c) { $d['cookie_names'][] = $c[1]; if (preg_match('/csrf|xsrf/i', $c[1])) $set(urldecode($c[2]), 'cookie:' . $c[1]); }
    foreach ($jsHints['cookie'] ?? [] as $cn) foreach ($cs as $c) if (strcasecmp($c[1], $cn) === 0) $set(urldecode($c[2]), 'cookie:' . $cn);
    // آخرین راه: هگزِ ۶۴ رقمیِ نزدیکِ csrf/token
    if ($d['csrf'] === '' && preg_match_all('/csrf|xsrf|token/i', $html, $hits, PREG_OFFSET_CAPTURE)) {
        foreach ($hits[0] as $h) { $win = substr($html, max(0, $h[1] - 250), 500); if (preg_match('/\b([a-f0-9]{64})\b/i', $win, $m)) { $set($m[1], 'near:' . $h[0]); break; } }
    }
    if (preg_match('~(/assets/build/pdf-ocr-editor\.([a-f0-9]{6,})\.js)~i', $html, $m)) { $d['js'] = i2_abs($m[1], $pageUrl); $d['build'] = $m[2]; }
    elseif (preg_match('~src=["\']([^"\']*pdf-ocr[^"\']*\.js)~i', $html, $m)) { $d['js'] = i2_abs(html_entity_decode($m[1]), $pageUrl); if (preg_match('/\.([a-f0-9]{6,})\.js/', $m[1], $b)) $d['build'] = $b[1]; }
    if (preg_match('~(/assets/build/site\.[a-f0-9]{6,}\.js)~i', $html, $m)) $d['site_js'] = i2_abs($m[1], $pageUrl);
    $d['meta_names'] = array_slice(array_values(array_unique($d['meta_names'])), 0, 30);
    $d['cookie_names'] = array_values(array_unique($d['cookie_names']));
    // برای گزارش: اطرافِ «csrf» در صفحه
    $d['html_hints'] = [];
    if (preg_match_all('/csrf|xsrf/i', $html, $hh, PREG_OFFSET_CAPTURE)) foreach (array_slice($hh[0], 0, 4) as $h) $d['html_hints'][] = substr($html, max(0, $h[1] - 120), 260);
    return $d;
}
// نامِ فیلدهای فرم و محلِ توکن در اسکریپت‌های سایت (یک روز در پوشه‌ی موقت نگه داشته می‌شود)
function i2_discover_js(array $urls, $jar, array $hdr) {
    $urls = array_values(array_filter($urls));
    if (!$urls) return ['fields' => [], 'hash_hint' => '', 'meta' => [], 'keys' => [], 'cookie' => [], 'csrf_hints' => []];
    $cache = so_tmp_dir() . '/i2js_' . md5(implode('|', $urls)) . '.json';
    if (is_file($cache) && filemtime($cache) > time() - 86400 && is_array($j = json_decode((string)file_get_contents($cache), true)) && ($j['v'] ?? 0) === 4) return $j;
    $js = ''; $size = 0;
    foreach ($urls as $u) { $r = i2_http('GET', $u, $hdr + ['Accept' => '*/*'], $jar, null, 30); if ($r['http'] === 200) { $js .= "\n" . $r['body']; $size += strlen($r['body']); } }
    preg_match_all('/\.(?:append|set)\(\s*["\']([A-Za-z0-9_\[\]-]{2,40})["\']/', $js, $m);
    $hint = ($p = strpos($js, 'input_hash')) !== false ? substr($js, max(0, $p - 500), 1000) : '';
    $meta = []; $keys = []; $cookie = []; $hints = [];
    if (preg_match_all('/csrf|xsrf/i', $js, $hh, PREG_OFFSET_CAPTURE)) {
        foreach ($hh[0] as $i => $h) {
            $win = substr($js, max(0, $h[1] - 300), 600);
            if ($i < 4) $hints[] = $win;
            if (preg_match_all('/meta\[\s*(?:name|property)\s*=\s*\\\\?["\']?([\w:.-]+)/i', $win, $mm)) $meta = array_merge($meta, $mm[1]);
            if (preg_match_all('/(?:dataset\.|getAttribute\(\s*["\']data-)([\w-]*(?:csrf|xsrf|token)[\w-]*)/i', $win, $mm)) $keys = array_merge($keys, $mm[1]);
            if (preg_match_all('/["\']([\w-]*(?:csrf|xsrf)[\w-]*)["\']/i', $win, $mm)) $keys = array_merge($keys, $mm[1]);
            if (preg_match_all('/cookie[^;]{0,80}?["\']([\w-]*(?:csrf|xsrf)[\w-]*)["\']/i', $win, $mm)) $cookie = array_merge($cookie, $mm[1]);
        }
    }
    preg_match_all('~["\'`](/api/[\w/-]*(?:csrf|xsrf|token|session|bootstrap|init)[\w/-]*)["\'`]~i', $js, $ap);
    $up = [];
    foreach (['append("file"', 'altcha_grant', '/api/pdf-ocr', 'X-I2PDF-Sequence'] as $needle) {
        $off = 0; $k = 0;
        while ($k < 2 && ($p = strpos($js, $needle, $off)) !== false) { $up[] = '[' . $needle . '] ' . substr($js, max(0, $p - 700), 1400); $off = $p + strlen($needle); $k++; }
    }
    // تابعی که input_hash را می‌سازد (برای گزارش): ...Promise.all([x(), NAME(h)]) ... input_hash:T
    $hashFn = '';
    if (preg_match('/Promise\.all\(\[\w+\(\),\s*(\w+)\(/', $hint, $hm)) {
        $nm = $hm[1];
        if (preg_match('/(?:async\s+)?function\s+' . preg_quote($nm, '/') . '\s*\(|[,;\s]' . preg_quote($nm, '/') . '\s*=\s*(?:async\s*)?(?:function|\()/', $js, $fm, PREG_OFFSET_CAPTURE)) $hashFn = $nm . ': ' . substr($js, $fm[0][1], 700);
    }
    $j = ['v' => 4, 'hash_fn' => $hashFn, 'upload_hints' => $up, 'apis' => array_values(array_unique($ap[1] ?? [])), 'fields' => array_values(array_unique($m[1] ?? [])), 'hash_hint' => $hint, 'size' => $size, 'meta' => array_values(array_unique($meta)),
          'keys' => array_values(array_unique($keys)), 'cookie' => array_values(array_unique($cookie)), 'csrf_hints' => $hints];
    if ($js !== '') @file_put_contents($cache, json_encode($j));
    return $j;
}
function i2_hex($n) { return bin2hex(random_bytes($n)); }

// یک صفحه: $pdf = بایت‌های PDFِ تک‌صفحه؛ $prog(step, label) برای تایم‌لاین؛ $dbg (اختیاری) گزارشِ کامل برای «آزمایشِ اتصال»
function i2_page($pdo, $pdf, $lang, $fmt, callable $prog, &$dbg = null) {
    $c = i2_conf($pdo);
    $dbg = [];
    $fmt = isset(I2_FORMATS[$fmt]) ? $fmt : $c['format'];
    $code = $lang === 'eng' ? $c['lang_en'] : $c['lang_fa'];
    $t0 = microtime(true);
    $jar = tempnam(sys_get_temp_dir(), 'i2c');
    $p = parse_url($c['page_url']); $base = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'www.i2pdf.com') . (isset($p['port']) ? ':' . $p['port'] : '');
    $hdr = ['User-Agent' => I2_UA, 'Accept-Language' => 'en-GB,en;q=0.9,fa-IR;q=0.8,fa;q=0.7', 'Origin' => $base, 'Referer' => $base . '/', 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'];
    $fail = function ($msg, $r = null) use (&$dbg) {
        if ($r) $dbg['last_http'] = ['http' => $r['http'], 'type' => $r['type'], 'error' => $r['error'], 'body' => mb_substr((string)$r['body'], 0, 600)];
        return ['ok' => false, 'error' => 'i2pdf: ' . $msg];
    };
    try {
        // ۱) صفحه‌ی ابزار
        $prog('open', 'باز کردنِ i2pdf.com/pdf-ocr');
        // اتصالِ هاست به i2pdf گاهی کُند است: یک بار دیگر امتحان می‌شود
        for ($try = 0; $try < 2; $try++) {
            $pg = i2_http('GET', $c['page_url'], ['User-Agent' => I2_UA, 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => $hdr['Accept-Language']], $jar, null, 45);
            if ($pg['http'] >= 200 && $pg['http'] < 400) break;
            if ($try === 0) { $prog('open', 'باز کردنِ i2pdf.com/pdf-ocr — تلاشِ دوباره'); sleep(2); }
        }
        $dbg['page'] = ['http' => $pg['http'], 'url' => $pg['url'], 'size' => strlen($pg['body'])];
        if ($pg['http'] < 200 || $pg['http'] >= 400) return $fail('صفحه باز نشد (' . ($pg['error'] ?: 'HTTP ' . $pg['http']) . ')', $pg);
        $d0 = i2_discover_page($pg['body'], $pg['url'] ?: $c['page_url'], $pg['headers']);
        $jsInfo = i2_discover_js([$d0['js'], $d0['site_js']], $jar, $hdr);
        $d = i2_discover_page($pg['body'], $pg['url'] ?: $c['page_url'], $pg['headers'], $jsInfo);
        $dbg['csrf_found'] = $d['csrf'] !== ''; $dbg['csrf_src'] = $d['csrf_src']; $dbg['build'] = $d['build']; $dbg['js'] = $d['js'];
        $dbg['js_fields'] = $jsInfo['fields']; $dbg['js_size'] = $jsInfo['size'] ?? 0; $dbg['js_meta'] = $jsInfo['meta']; $dbg['js_keys'] = $jsInfo['keys']; $dbg['js_cookie'] = $jsInfo['cookie'];
        // توکن از یک API هنگامِ بارگذاریِ صفحه (اگر اسکریپت چنین آدرسی دارد، یا آدرس‌های رایج)
        if ($d['csrf'] === '') {
            foreach (array_slice(array_unique(array_merge($jsInfo['apis'] ?? [], ['/api/csrf', '/api/csrf-token', '/api/session'])), 0, 6) as $ep) {
                $tr = i2_http('GET', $base . $ep, $hdr + ['Accept' => 'application/json'], $jar, null, 15);
                $tj = json_decode($tr['body'], true);
                if ($tr['http'] === 200 && is_array($tj)) {
                    array_walk_recursive($tj, function ($v, $k) use (&$d, $ep) { if ($d['csrf'] === '' && is_string($k) && preg_match('/csrf|xsrf|^token$/i', $k) && is_string($v) && strlen($v) >= 16) { $d['csrf'] = $v; $d['csrf_src'] = 'api:' . $ep; } });
                }
                if ($d['csrf'] === '' && preg_match('/^set-cookie:\s*([\w-]*(?:csrf|xsrf)[\w-]*)=([^;\r\n]+)/im', (string)$tr['headers'], $cm)) { $d['csrf'] = urldecode($cm[2]); $d['csrf_src'] = 'cookie:' . $cm[1]; }
                if ($d['csrf'] !== '') break;
            }
            $dbg['csrf_src'] = $d['csrf_src']; $dbg['csrf_found'] = $d['csrf'] !== ''; $dbg['js_apis'] = $jsInfo['apis'] ?? [];
        }
        if ($d['csrf'] === '') {
            $dbg['meta_names'] = $d['meta_names']; $dbg['cookie_names'] = $d['cookie_names'];
            $dbg['html_hints'] = $d['html_hints']; $dbg['js_hints'] = array_map(function ($x) { return substr($x, 0, 600); }, $jsInfo['csrf_hints']);
            return $fail('توکنِ CSRF در صفحه پیدا نشد؛ «گزارشِ فنی» را بفرستید تا محلش اضافه شود.');
        }
        $hdr['X-CSRF-Token'] = $d['csrf'];
        $use = function ($ev) use ($base, $hdr, $jar) { i2_http('POST', $base . '/api/tool-usage', $hdr + ['Content-Type' => 'application/json', 'Accept' => '*/*'], $jar, json_encode(['app' => 'pdf-ocr', 'event' => $ev, 'id' => i2_hex(16)]), 10); };
        $use('view');

        // ۲) فایل و گزینه‌ها (همان ترتیب و شکلِ مرورگر)
        $prog('upload', 'Upload Files — PDFِ تک‌صفحه آماده شد (' . max(1, round(strlen($pdf) / 1024)) . ' KB)');
        $prog('lang', 'Recognition language: ' . ($lang === 'eng' ? 'English' : 'Persian') . ' · Download format: ' . I2_FORMATS[$fmt]);
        $hash = hash('sha256', $pdf);
        $options = json_encode(['pages' => '1', 'language' => $code, 'engine' => $c['engine'], 'layout' => $c['layout'], 'format' => $fmt,
                                'area' => '{"x":0,"y":0,"width":1,"height":1}', 'download_formats' => 'all'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $use('action');

        // ۳) I'm not a robot (ALTCHA): چالش ← حل ← تأیید ← grant (برای هر تلاشِ آپلود یک grantِ تازه)
        $getGrant = function () use ($base, $hdr, $jar, $hash, $options, &$dbg) {
            $ch = i2_http('POST', $base . '/api/altcha', $hdr + ['Accept' => '*/*'], $jar, ['verification_action' => 'challenge', 'scope' => 'pdf-ocr', 'input_hash' => $hash, 'options' => $options], 30);
            $cj = json_decode($ch['body'], true);
            $dbg['altcha_challenge'] = ['http' => $ch['http'], 'has_context' => !empty($cj['context']), 'has_challenge' => !empty($cj['challenge'])];
            if ($ch['http'] !== 200 || empty($cj['challenge']) || empty($cj['context'])) return ['err' => 'چالشِ «I\'m not a robot» گرفته نشد (HTTP ' . $ch['http'] . ').', 'res' => $ch];
            $payload = i2_altcha_solve($cj['challenge']);
            if (!$payload) return ['err' => 'حلِ «I\'m not a robot» ممکن نشد.'];
            $vf = i2_http('POST', $base . '/api/altcha', $hdr + ['Accept' => '*/*'], $jar, ['verification_action' => 'verify', 'scope' => 'pdf-ocr', 'context' => $cj['context'], 'payload' => $payload], 30);
            $vj = json_decode($vf['body'], true);
            $dbg['altcha_verify'] = ['http' => $vf['http'], 'has_grant' => !empty($vj['grant'])];
            if ($vf['http'] !== 200 || empty($vj['grant'])) return ['err' => '«I\'m not a robot» تأیید نشد (HTTP ' . $vf['http'] . ').', 'res' => $vf];
            return ['context' => $cj['context'], 'grant' => $vj['grant']];
        };
        $prog('robot', "I'm not a robot — در حالِ تأیید…");
        $g = $getGrant();
        if (isset($g['err'])) return $fail($g['err'], $g['res'] ?? null);
        $prog('robot_ok', "I'm not a robot — تأیید شد");

        // ۴) Start: آپلود و پردازش — شکلِ فرم از اسکریپتِ سایت: files[] + گزینه‌ها به‌صورتِ فیلدهای جدا + grant
        //    اگر سرور «invalid_options/…» داد، شکل‌های دیگر هم (هر بار با grantِ تازه) امتحان می‌شوند
        $prog('process', 'Start — در حالِ پردازش');
        $tmpPdf = tempnam(sys_get_temp_dir(), 'i2p') . '.pdf';
        file_put_contents($tmpPdf, $pdf);
        $F = $jsInfo['fields'];
        $optArr = json_decode($options, true);
        $sep = $optArr;   // گزینه‌ها جدا: pages, language, engine, layout, format, area, download_formats
        // شکلِ اسکریپتِ سایت: Object.entries({...options, altcha_context, altcha_grant}) ← فیلدهای جدا، و بعد files[]
        // ترتیب از گزارش‌های سرورِ واقعی: files[] ← upload_missing (فایل را نمی‌بیند)، file + فیلدهای اضافه ← invalid_options
        //   پس اول دقیقاً شکلِ مرورگر: file + گزینه‌ها + altcha_context + altcha_grant (بدونِ هیچ فیلدِ دیگری)
        $variants = [['file', 'exact'], ['file', 'json'], ['file', 'exact+hash'], ['files[]', 'exact']];
        $dbg['attempts'] = [];
        $res = null;
        foreach ($variants as $vi => [$fileField, $mode]) {
            if ($vi > 0) { $g = $getGrant(); if (isset($g['err'])) break; }
            $post = $mode === 'json' ? ['options' => $options] : $sep;
            $post += ['altcha_context' => $g['context'], 'altcha_grant' => $g['grant']];
            if ($mode === 'exact+hash') $post['input_hash'] = $hash;
            $post[$fileField] = new CURLFile($tmpPdf, 'application/pdf', 'document.pdf');
            $res = i2_http('POST', $base . '/api/pdf-ocr', $hdr + ['Accept' => '*/*', 'X-I2PDF-Attempt' => i2_hex(16), 'X-I2PDF-Build' => $d['build'], 'X-I2PDF-Case' => i2_hex(16), 'X-I2PDF-Sequence' => (string)($vi + 1)],
                           $jar, $post, $c['timeout']);
            $ej = json_decode($res['body'], true);
            $dbg['attempts'][] = ['file_field' => $fileField, 'mode' => $mode, 'fields' => array_keys($post), 'http' => $res['http'], 'error' => is_array($ej) ? ($ej['error'] ?? '') : '', 'reason' => is_array($ej) ? ($ej['reason'] ?? '') : ''];
            if ($res['http'] === 200 || $res['error'] !== '') break;
            // فقط خطاهای مربوط به شکلِ فرم/تأیید را با شکلِ دیگر تکرار کن
            if (!is_array($ej) || !preg_match('/option|field|file|missing|invalid|altcha|verif|grant/i', (string)($ej['error'] ?? ''))) break;
        }
        @unlink($tmpPdf);
        $dbg['upload'] = ['http' => $res['http'], 'type' => $res['type'], 'size' => strlen($res['body'])];
        if ($res['error'] !== '' || $res['http'] !== 200) {
            $dbg['js_hash_fn'] = $jsInfo['hash_fn'] ?? '';
            $dbg['js_upload_hints'] = $jsInfo['upload_hints'] ?? [];
            $j = json_decode($res['body'], true);
            $msg = is_array($j) ? (string)($j['error'] ?? ($j['message'] ?? '')) : trim(mb_substr(strip_tags($res['body']), 0, 160));
            return $fail('پردازش انجام نشد (' . ($res['error'] ?: 'HTTP ' . $res['http']) . ($msg !== '' ? ' — ' . $msg : '') . ')', $res);
        }

        // ۵) نتیجه: text + document + pdf
        $parts = stripos($res['type'], 'multipart') !== false ? i2_multipart($res['body'], $res['type']) : [];
        if (!$parts && stripos($res['type'], 'json') !== false && is_array($rj = json_decode($res['body'], true)) && isset($rj['text']) && is_string($rj['text']))
            $parts = ['text' => ['filename' => 'ocr.txt', 'type' => 'text/plain', 'body' => $rj['text']]];
        $dbg['parts'] = array_keys($parts);
        $text = isset($parts['text']) ? i2_text_of($parts['text']['body'], 'txt') : '';
        if ($text === '' && isset($parts['document'])) $text = i2_text_of($parts['document']['body'], 'docx');
        if ($text === '' && !$parts && stripos($res['type'], 'text/plain') !== false) $text = i2_text_of($res['body'], 'txt');
        $files = [];
        foreach (['document' => 'docx', 'pdf' => 'pdf'] as $k => $ext) {
            if (empty($parts[$k]['body'])) continue;
            $id = i2_hex(8) . '.' . $ext;
            if (@file_put_contents(so_tmp_dir() . '/i2_' . $id, $parts[$k]['body'])) $files[$ext] = $id;
        }
        $use('result');
        $ms = intval((microtime(true) - $t0) * 1000);
        $prog('done', 'Your result is ready.');
        $meta = isset($parts['metadata']) ? (json_decode($parts['metadata']['body'], true) ?: []) : [];
        return ['ok' => $text !== '', 'text' => $text, 'ms' => $ms, 'engine' => 'i2pdf', 'format' => $fmt, 'rtl' => !empty($meta['rtl']), 'files' => $files,
                'file' => $files[$fmt === 'docx' ? 'docx' : 'pdf'] ?? ($files['docx'] ?? ''), 'error' => $text === '' ? 'i2pdf متنی برنگرداند.' : ''];
    } finally { @unlink($jar); }
}

// «آزمایشِ اتصال» (تنظیمات): یک صفحه‌ی نمونه با متنِ فارسی و انگلیسی، همه‌ی گام‌ها و یافته‌ها
function i2_test($pdo) {
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $t = new TCPDF('P', 'mm', 'A5', true, 'UTF-8', false);
    $t->setPrintHeader(false); $t->setPrintFooter(false); $t->AddPage();
    $t->AddFont('vazir', '', 'vazir.php'); $t->SetFont('vazir', '', 22); $t->setRTL(true);
    $t->Write(14, "آزمایش پردازش فایل بیمه با ما\n"); $t->setRTL(false); $t->SetFont('helvetica', '', 20); $t->Write(14, "i2pdf test 12345");
    $steps = [];
    $r = i2_page($pdo, $t->Output('', 'S'), 'fas', 'txt', function ($s, $l) use (&$steps) { $steps[] = $l; }, $dbg);
    return ['ok' => true, 'result' => ['ok' => $r['ok'], 'error' => $r['error'] ?? '', 'text' => mb_substr((string)($r['text'] ?? ''), 0, 400), 'ms' => $r['ms'] ?? 0, 'files' => array_keys($r['files'] ?? [])],
            'steps' => $steps, 'debug' => $dbg];
}
