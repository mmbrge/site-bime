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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
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
function i2_discover_page($html, $pageUrl) {
    $d = ['csrf' => '', 'build' => '', 'js' => ''];
    foreach (['/<meta[^>]+name=["\']csrf[-_]?token["\'][^>]*content=["\']([^"\']+)/i', '/<meta[^>]+content=["\']([^"\']+)["\'][^>]*name=["\']csrf[-_]?token/i',
              '/csrf[-_]?token["\']?\s*[:=]\s*["\']([A-Za-z0-9+\/=_-]{20,})/i', '/data-csrf(?:-token)?=["\']([^"\']+)/i', '/name=["\']_?csrf[-_]?(?:token)?["\'][^>]*value=["\']([^"\']+)/i'] as $re)
        if ($d['csrf'] === '' && preg_match($re, $html, $m)) $d['csrf'] = html_entity_decode($m[1]);
    if (preg_match('~(/assets/build/pdf-ocr-editor\.([a-f0-9]{6,})\.js)~i', $html, $m)) { $d['js'] = i2_abs($m[1], $pageUrl); $d['build'] = $m[2]; }
    elseif (preg_match('~src=["\']([^"\']*pdf-ocr[^"\']*\.js)~i', $html, $m)) { $d['js'] = i2_abs(html_entity_decode($m[1]), $pageUrl); if (preg_match('/\.([a-f0-9]{6,})\.js/', $m[1], $b)) $d['build'] = $b[1]; }
    return $d;
}
// نامِ فیلدهای فرم در اسکریپتِ سایت (یک روز در پوشه‌ی موقت نگه داشته می‌شود)
function i2_discover_js($url, $jar, array $hdr) {
    if ($url === '') return ['fields' => [], 'hash_hint' => ''];
    $cache = so_tmp_dir() . '/i2js_' . md5($url) . '.json';
    if (is_file($cache) && filemtime($cache) > time() - 86400 && is_array($j = json_decode((string)file_get_contents($cache), true))) return $j;
    $r = i2_http('GET', $url, $hdr + ['Accept' => '*/*'], $jar, null, 30);
    $js = $r['http'] === 200 ? $r['body'] : '';
    preg_match_all('/\.(?:append|set)\(\s*["\']([A-Za-z0-9_\[\]-]{2,40})["\']/', $js, $m);
    $hint = '';
    if (($p = strpos($js, 'input_hash')) !== false) $hint = substr($js, max(0, $p - 500), 1000);
    $j = ['fields' => array_values(array_unique($m[1] ?? [])), 'hash_hint' => $hint, 'size' => strlen($js)];
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
        $pg = i2_http('GET', $c['page_url'], ['User-Agent' => I2_UA, 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => $hdr['Accept-Language']], $jar, null, 30);
        $dbg['page'] = ['http' => $pg['http'], 'url' => $pg['url'], 'size' => strlen($pg['body'])];
        if ($pg['http'] < 200 || $pg['http'] >= 400) return $fail('صفحه باز نشد (' . ($pg['error'] ?: 'HTTP ' . $pg['http']) . ')', $pg);
        $d = i2_discover_page($pg['body'], $pg['url'] ?: $c['page_url']);
        $dbg['csrf_found'] = $d['csrf'] !== ''; $dbg['build'] = $d['build']; $dbg['js'] = $d['js'];
        if ($d['csrf'] === '') return $fail('توکنِ CSRF در صفحه پیدا نشد (ساختارِ سایت عوض شده؟ «آزمایشِ اتصال» را بفرستید).');
        $hdr['X-CSRF-Token'] = $d['csrf'];
        $jsInfo = i2_discover_js($d['js'], $jar, $hdr);
        $dbg['js_fields'] = $jsInfo['fields']; $dbg['js_size'] = $jsInfo['size'] ?? 0;
        $use = function ($ev) use ($base, $hdr, $jar) { i2_http('POST', $base . '/api/tool-usage', $hdr + ['Content-Type' => 'application/json', 'Accept' => '*/*'], $jar, json_encode(['app' => 'pdf-ocr', 'event' => $ev, 'id' => i2_hex(16)]), 10); };
        $use('view');

        // ۲) فایل و گزینه‌ها (همان ترتیب و شکلِ مرورگر)
        $prog('upload', 'Upload Files — PDFِ تک‌صفحه آماده شد (' . max(1, round(strlen($pdf) / 1024)) . ' KB)');
        $prog('lang', 'Recognition language: ' . ($lang === 'eng' ? 'English' : 'Persian') . ' · Download format: ' . I2_FORMATS[$fmt]);
        $hash = hash('sha256', $pdf);
        $options = json_encode(['pages' => '1', 'language' => $code, 'engine' => $c['engine'], 'layout' => $c['layout'], 'format' => $fmt,
                                'area' => '{"x":0,"y":0,"width":1,"height":1}', 'download_formats' => 'all'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $use('action');

        // ۳) I'm not a robot (ALTCHA)
        $prog('robot', "I'm not a robot — در حالِ تأیید…");
        $ch = i2_http('POST', $base . '/api/altcha', $hdr + ['Accept' => '*/*'], $jar, ['verification_action' => 'challenge', 'scope' => 'pdf-ocr', 'input_hash' => $hash, 'options' => $options], 30);
        $cj = json_decode($ch['body'], true);
        $dbg['altcha_challenge'] = ['http' => $ch['http'], 'has_context' => !empty($cj['context']), 'has_challenge' => !empty($cj['challenge'])];
        if ($ch['http'] !== 200 || empty($cj['challenge']) || empty($cj['context'])) return $fail('چالشِ «I\'m not a robot» گرفته نشد (HTTP ' . $ch['http'] . ').', $ch);
        $payload = i2_altcha_solve($cj['challenge']);
        if (!$payload) return $fail('حلِ «I\'m not a robot» ممکن نشد.');
        $vf = i2_http('POST', $base . '/api/altcha', $hdr + ['Accept' => '*/*'], $jar, ['verification_action' => 'verify', 'scope' => 'pdf-ocr', 'context' => $cj['context'], 'payload' => $payload], 30);
        $vj = json_decode($vf['body'], true);
        $dbg['altcha_verify'] = ['http' => $vf['http'], 'has_grant' => !empty($vj['grant'])];
        if ($vf['http'] !== 200 || empty($vj['grant'])) return $fail('«I\'m not a robot» تأیید نشد (HTTP ' . $vf['http'] . ').', $vf);
        $prog('robot_ok', "I'm not a robot — تأیید شد");

        // ۴) Start: آپلود و پردازش
        $prog('process', 'Start — در حالِ پردازش');
        $tmpPdf = tempnam(sys_get_temp_dir(), 'i2p') . '.pdf';
        file_put_contents($tmpPdf, $pdf);
        $F = $jsInfo['fields'];
        $fileField = 'file';
        foreach (['file', 'pdf', 'document', 'upload', 'input', 'files[]', 'files', 'pdf_file'] as $cand) if (in_array($cand, $F, true)) { $fileField = $cand; break; }
        $post = [$fileField => new CURLFile($tmpPdf, 'application/pdf', 'document.pdf'), 'options' => $options, 'grant' => $vj['grant'], 'input_hash' => $hash, 'scope' => 'pdf-ocr', 'context' => $cj['context']];
        // فیلدهای دیگری که اسکریپتِ سایت می‌فرستد و مقدارشان معلوم است
        $known = ['pages' => '1', 'language' => $code, 'lang' => $code, 'format' => $fmt, 'engine' => $c['engine'], 'layout' => $c['layout'], 'download_formats' => 'all', 'tool' => 'pdf-ocr', 'app' => 'pdf-ocr'];
        foreach ($F as $f) {
            if (isset($post[$f]) || in_array($f, ['verification_action', 'payload'], true)) continue;
            if (isset($known[$f])) $post[$f] = $known[$f];
            elseif (stripos($f, 'grant') !== false || stripos($f, 'verification') !== false || stripos($f, 'altcha') !== false) $post[$f] = $vj['grant'];
        }
        $dbg['upload_fields'] = array_keys($post);
        $res = i2_http('POST', $base . '/api/pdf-ocr', $hdr + ['Accept' => '*/*', 'x-i2pdf-attempt' => i2_hex(16), 'x-i2pdf-build' => $d['build'], 'x-i2pdf-case' => i2_hex(16), 'x-i2pdf-sequence' => '1'],
                       $jar, $post, $c['timeout']);
        @unlink($tmpPdf);
        $dbg['upload'] = ['http' => $res['http'], 'type' => $res['type'], 'size' => strlen($res['body'])];
        if ($res['error'] !== '' || $res['http'] !== 200) {
            $j = json_decode($res['body'], true);
            $msg = is_array($j) ? (string)($j['error'] ?? ($j['message'] ?? '')) : trim(mb_substr(strip_tags($res['body']), 0, 160));
            return $fail('پردازش انجام نشد (' . ($res['error'] ?: 'HTTP ' . $res['http']) . ($msg !== '' ? ' — ' . $msg : '') . ')', $res);
        }

        // ۵) نتیجه: text + document + pdf
        $parts = stripos($res['type'], 'multipart') !== false ? i2_multipart($res['body'], $res['type']) : [];
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
