<?php
// فایل: api/_i2pdf.php
// موتورِ «i2pdf» برای «پردازش فایل هوشمند» — همان کاری که در سایتِ www.i2pdf.com/pdf-ocr دستی انجام می‌شود، از روی هاست:
//   ۱) باز کردنِ صفحه‌ی ابزار (کوکی‌ها)            ۲) آماده کردنِ PDFِ تک‌صفحه (عکس ← PDF)
//   ۳) زبانِ تشخیص (Persian / English)             ۴) «I'm not a robot» (ALTCHA = اثباتِ کار؛ خودکار حل می‌شود، کپچای تصویری نیست)
//   ۵) Start و انتظار تا «Your result is ready.»   ۶) گرفتنِ فایلِ نتیجه (txt / Simple PDF / Word) و متنِ آن
// خودِ درخواستِ «پردازش» از روی cURLی ساخته می‌شود که مدیر کل یک بار از DevTools ← Network کپی و در تنظیمات می‌چسباند
// (آدرس، سرتیترها و نامِ فیلدها از همان خوانده می‌شود؛ فایل، زبان، قالب و پاسخِ ALTCHA هر بار عوض می‌شوند).
// پیشرفتِ هر گام در یک فایلِ موقت نوشته می‌شود تا پنل تایم‌لاین و زمانِ سپری‌شده را نشان دهد (so_progress در _smart_ocr.php).

const I2_PAGE_URL = 'https://www.i2pdf.com/pdf-ocr';
const I2_FORMATS = ['txt' => 'Editable text (.txt)', 'simple_pdf' => 'Simple PDF (text only)', 'docx' => 'Word document (.docx)', 'pdf' => 'Searchable PDF'];
const I2_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36';

// ---------- خواندنِ «Copy as cURL (bash)»ِ کروم ----------
// آرگومان‌های شِل: '...'، $'...' (با \r\n و \xHH)، "..." و ادامه‌ی خط با «\»
function i2_shell_args($s) {
    $s = str_replace(["\\\r\n", "\\\n"], ' ', (string)$s);
    $out = []; $i = 0; $n = strlen($s);
    while ($i < $n) {
        while ($i < $n && ctype_space($s[$i])) $i++;
        if ($i >= $n) break;
        $tok = '';
        while ($i < $n && !ctype_space($s[$i])) {
            $c = $s[$i];
            if ($c === "'") { $j = strpos($s, "'", $i + 1); if ($j === false) $j = $n; $tok .= substr($s, $i + 1, $j - $i - 1); $i = $j + 1; }
            elseif ($c === '$' && ($s[$i + 1] ?? '') === "'") {
                $i += 2;
                while ($i < $n && $s[$i] !== "'") {
                    if ($s[$i] === '\\' && $i + 1 < $n) {
                        $e = $s[$i + 1]; $i += 2;
                        if ($e === 'n') $tok .= "\n"; elseif ($e === 'r') $tok .= "\r"; elseif ($e === 't') $tok .= "\t"; elseif ($e === '0') $tok .= "\0";
                        elseif ($e === 'x' && preg_match('/^[0-9a-fA-F]{1,2}/', substr($s, $i, 2), $m)) { $tok .= chr(hexdec($m[0])); $i += strlen($m[0]); }
                        elseif ($e === 'u' && preg_match('/^[0-9a-fA-F]{4}/', substr($s, $i, 4), $m)) { $tok .= mb_chr(hexdec($m[0]), 'UTF-8'); $i += 4; }
                        else $tok .= $e;
                    } else { $tok .= $s[$i]; $i++; }
                }
                $i++;
            }
            elseif ($c === '"') {
                $i++;
                while ($i < $n && $s[$i] !== '"') { if ($s[$i] === '\\' && $i + 1 < $n) { $tok .= $s[$i + 1]; $i += 2; } else { $tok .= $s[$i]; $i++; } }
                $i++;
            }
            elseif ($c === '\\' && $i + 1 < $n) { $tok .= $s[$i + 1]; $i += 2; }
            else { $tok .= $c; $i++; }
        }
        $out[] = $tok;
    }
    return $out;
}
// => ['url','method','headers'=>[k=>v],'cookie','fields'=>[[name,value,filename,ctype]],'body','kind'=>multipart|form|json|none]
function i2_parse_curl($curl) {
    $a = i2_shell_args($curl);
    $r = ['url' => '', 'method' => '', 'headers' => [], 'cookie' => '', 'fields' => [], 'body' => '', 'kind' => 'none'];
    for ($i = 0; $i < count($a); $i++) {
        $t = $a[$i]; $v = $a[$i + 1] ?? '';
        if ($t === 'curl') continue;
        if ($t === '--url') { $r['url'] = $v; $i++; }
        elseif ($t === '-X' || $t === '--request') { $r['method'] = strtoupper($v); $i++; }
        elseif ($t === '-H' || $t === '--header') { $p = strpos($v, ':'); if ($p !== false) $r['headers'][strtolower(trim(substr($v, 0, $p)))] = trim(substr($v, $p + 1)); $i++; }
        elseif ($t === '-b' || $t === '--cookie') { $r['cookie'] = $v; $i++; }
        elseif (in_array($t, ['--data-raw', '--data', '-d', '--data-binary', '--data-urlencode'], true)) { $r['body'] .= ($r['body'] !== '' ? '&' : '') . $v; $i++; }
        elseif ($t === '-F' || $t === '--form') {
            $p = strpos($v, '=');
            if ($p !== false) { $nm = substr($v, 0, $p); $val = substr($v, $p + 1); $r['fields'][] = $val !== '' && $val[0] === '@' ? [$nm, '', basename(explode(';', substr($val, 1))[0]), 'application/pdf'] : [$nm, $val, null, null]; }
            $i++; $r['kind'] = 'multipart';
        }
        elseif ($r['url'] === '' && preg_match('~^https?://~i', $t)) $r['url'] = $t;
    }
    if (isset($r['headers']['cookie']) && $r['cookie'] === '') $r['cookie'] = $r['headers']['cookie'];
    unset($r['headers']['cookie'], $r['headers']['content-length']);
    $ct = $r['headers']['content-type'] ?? '';
    if ($r['kind'] !== 'multipart' && $r['body'] !== '') {
        if (stripos($ct, 'multipart/form-data') !== false || preg_match('/^-{2,}\S+\r?\n/', $r['body'])) {
            $r['kind'] = 'multipart';
            $bd = preg_match('/boundary=([^;\s]+)/i', $ct, $m) ? trim($m[1], '"') : (preg_match('/^--(\S+)/', $r['body'], $m2) ? $m2[1] : '');
            foreach ($bd !== '' ? explode('--' . $bd, $r['body']) : [] as $part) {
                $part = ltrim($part, "\r\n");
                if ($part === '' || strpos($part, '--') === 0) continue;
                $sp = preg_split("/\r?\n\r?\n/", $part, 2);
                $hd = $sp[0]; $val = isset($sp[1]) ? preg_replace("/\r?\n$/", '', $sp[1]) : '';
                if (!preg_match('/name="([^"]*)"/i', $hd, $nm)) continue;
                $fn = preg_match('/filename="([^"]*)"/i', $hd, $f) ? $f[1] : null;
                $pct = preg_match('/Content-Type:\s*([^\r\n]+)/i', $hd, $c) ? trim($c[1]) : null;
                $r['fields'][] = [$nm[1], $fn !== null ? '' : $val, $fn, $pct];
            }
        } elseif (stripos($ct, 'json') !== false || (strlen($r['body']) && $r['body'][0] === '{')) $r['kind'] = 'json';
        else { $r['kind'] = 'form'; parse_str($r['body'], $kv); foreach ($kv as $k => $v) $r['fields'][] = [$k, is_array($v) ? json_encode($v) : (string)$v, null, null]; }
    }
    if ($r['method'] === '') $r['method'] = ($r['body'] !== '' || $r['fields']) ? 'POST' : 'GET';
    unset($r['headers']['content-type']);   // مرزِ تازه را curl خودش می‌سازد
    return $r;
}

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
    return ['curl' => (string)($c['curl'] ?? ''), 'challenge_url' => trim((string)($c['challenge_url'] ?? '')), 'page_url' => trim((string)($c['page_url'] ?? '')) ?: I2_PAGE_URL,
            'lang_fa' => trim((string)($c['lang_fa'] ?? '')) ?: 'fas', 'lang_en' => trim((string)($c['lang_en'] ?? '')) ?: 'eng',
            'format' => isset(I2_FORMATS[$c['format'] ?? '']) ? $c['format'] : 'txt', 'timeout' => max(30, min(280, intval($c['timeout'] ?? 150) ?: 150))];
}
function i2_ready($pdo) { $c = i2_conf($pdo); return trim($c['curl']) !== '' && i2_parse_curl($c['curl'])['url'] !== '' && function_exists('curl_init'); }

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

// یک صفحه: $pdf = بایت‌های PDFِ تک‌صفحه؛ $prog(step, label) برای تایم‌لاین
function i2_page($pdo, $pdf, $lang, $fmt, callable $prog) {
    $c = i2_conf($pdo);
    if (!i2_ready($pdo)) return ['ok' => false, 'error' => 'i2pdf: درخواستِ «پردازش» (cURL) در تنظیمات چسبانده نشده است.'];
    $tpl = i2_parse_curl($c['curl']);
    $fmt = isset(I2_FORMATS[$fmt]) ? $fmt : $c['format'];
    $code = $lang === 'eng' ? $c['lang_en'] : $c['lang_fa'];
    $t0 = microtime(true);
    $jar = tempnam(sys_get_temp_dir(), 'i2c');
    if ($tpl['cookie'] !== '') {   // کوکی‌های cURL (برای همان دامنه)
        $host = parse_url($tpl['url'], PHP_URL_HOST);
        foreach (explode(';', $tpl['cookie']) as $ck) { $kv = array_map('trim', explode('=', $ck, 2)); if (count($kv) === 2 && $kv[0] !== '') @file_put_contents($jar, "$host\tFALSE\t/\tTRUE\t0\t{$kv[0]}\t{$kv[1]}\n", FILE_APPEND); }
    }
    $base = ['User-Agent' => I2_UA, 'Accept-Language' => 'en-GB,en;q=0.9'];
    try {
        $prog('open', 'باز کردنِ i2pdf.com/pdf-ocr');
        $pg = i2_http('GET', $c['page_url'], $base + ['Accept' => 'text/html'], $jar, null, 30);
        if ($pg['http'] < 200 || $pg['http'] >= 400) return ['ok' => false, 'error' => 'i2pdf: صفحه باز نشد (' . ($pg['error'] ?: 'HTTP ' . $pg['http']) . ')'];
        $prog('upload', 'Upload Files — PDFِ تک‌صفحه آماده شد (' . max(1, round(strlen($pdf) / 1024)) . ' KB)');
        $prog('lang', 'Recognition language: ' . ($lang === 'eng' ? 'English' : 'Persian') . ' · Download format: ' . I2_FORMATS[$fmt]);
        // ALTCHA: آدرسِ چالش از تنظیمات یا از خودِ صفحه (challengeurl / challengejson)
        $payload = null;
        $chUrl = $c['challenge_url'];
        $chJson = null;
        if ($chUrl === '' && preg_match('/challengeurl\s*[=:]\s*["\']([^"\']+)["\']/i', $pg['body'], $m)) $chUrl = html_entity_decode($m[1]);
        if ($chUrl === '' && preg_match('/challengejson\s*=\s*([\'"])(.+?)\1/is', $pg['body'], $m)) $chJson = json_decode(html_entity_decode($m[2]), true);
        $needAltcha = $chUrl !== '' || $chJson || array_filter($tpl['fields'], function ($f) { return stripos($f[0], 'altcha') !== false; }) || stripos($tpl['body'], 'altcha') !== false;
        if ($needAltcha) {
            $prog('robot', "I'm not a robot — در حالِ تأیید…");
            if (!$chJson && $chUrl !== '') { $cr = i2_http('GET', i2_abs($chUrl, $c['page_url']), $base + ['Accept' => 'application/json', 'Referer' => $c['page_url']], $jar, null, 20); $chJson = json_decode($cr['body'], true); }
            if (is_array($chJson)) $payload = i2_altcha_solve($chJson);
            if (!$payload) return ['ok' => false, 'error' => 'i2pdf: تأییدِ «I\'m not a robot» انجام نشد' . ($chUrl === '' && !$chJson ? ' (آدرسِ چالش پیدا نشد؛ در تنظیمات «آدرسِ چالشِ ALTCHA» را بگذارید)' : '') . '.'];
            usleep(800000);
            $prog('robot_ok', "I'm not a robot — تأیید شد");
        }
        // ساختنِ درخواستِ پردازش از روی cURL
        $set = function ($name, $val) use ($code, $fmt, $payload) {
            $l = strtolower($name);
            if (strpos($l, 'altcha') !== false) return $payload !== null ? $payload : $val;
            if (preg_match('/lang/', $l)) return $code;
            if (preg_match('/format|output|type$/', $l) && isset(I2_FORMATS[$val])) return $fmt;
            if (preg_match('/^pages?$|page_range|pages\[?\]?/', $l)) return '1';
            return $val;
        };
        $prog('process', 'Start — در حالِ پردازش');
        $hdr = array_diff_key($tpl['headers'], ['content-type' => 1, 'content-length' => 1, 'cookie' => 1, 'accept-encoding' => 1]);
        $hdr = array_merge(['user-agent' => I2_UA], $hdr);
        if ($tpl['kind'] === 'json') {
            $j = json_decode($tpl['body'], true) ?: [];
            array_walk_recursive($j, function (&$v, $k) use ($set, $pdf) { if (is_string($k) && preg_match('/file|pdf|data/i', $k) && is_string($v) && strlen($v) > 200) $v = base64_encode($pdf); elseif (is_string($k)) $v = $set($k, $v); });
            $res = i2_http($tpl['method'], $tpl['url'], $hdr + ['content-type' => 'application/json'], $jar, json_encode($j), $c['timeout']);
        } else {
            $tmpPdf = tempnam(sys_get_temp_dir(), 'i2p') . '.pdf';
            file_put_contents($tmpPdf, $pdf);
            $post = []; $hasFile = false; $hasAlt = false;
            foreach ($tpl['fields'] as $f) {
                if ($f[2] !== null) { $post[$f[0]] = new CURLFile($tmpPdf, 'application/pdf', 'page.pdf'); $hasFile = true; continue; }
                if (stripos($f[0], 'altcha') !== false) $hasAlt = true;
                $post[$f[0]] = $set($f[0], $f[1]);
            }
            if (!$hasFile) $post['file'] = new CURLFile($tmpPdf, 'application/pdf', 'page.pdf');
            if ($payload !== null && !$hasAlt) $post['altcha'] = $payload;
            $res = i2_http($tpl['method'] === 'GET' ? 'POST' : $tpl['method'], $tpl['url'], $hdr, $jar, $post, $c['timeout']);
            @unlink($tmpPdf);
        }
        if ($res['error'] !== '' || $res['http'] < 200 || $res['http'] >= 300) {
            $msg = trim(mb_substr(strip_tags((string)$res['body']), 0, 200));
            return ['ok' => false, 'error' => 'i2pdf: پردازش انجام نشد (' . ($res['error'] ?: 'HTTP ' . $res['http']) . ($msg !== '' ? ' — ' . $msg : '') . ')'];
        }
        // پاسخ: خودِ فایل، یا JSON با متن/آدرسِ دانلود
        $bytes = $res['body']; $text = null;
        if (stripos($res['type'], 'json') !== false) {
            $j = json_decode($bytes, true) ?: [];
            $find = function ($keys) use ($j) { $hit = null; array_walk_recursive($j, function ($v, $k) use ($keys, &$hit) { if ($hit === null && is_string($k) && in_array(strtolower($k), $keys, true) && is_string($v) && $v !== '') $hit = $v; }); return $hit; };
            if (($u = $find(['url', 'download_url', 'download', 'file_url', 'result_url', 'href'])) !== null) {
                $prog('download', 'دریافتِ فایلِ نتیجه');
                $bytes = i2_http('GET', i2_abs($u, $tpl['url']), $base, $jar, null, 60)['body'];
            } elseif (($tx = $find(['text', 'result', 'content', 'ocr_text'])) !== null) { $text = $tx; $bytes = ''; }
            else return ['ok' => false, 'error' => 'i2pdf: پاسخِ ناشناخته — ' . mb_substr($res['body'], 0, 200)];
        }
        if ($text === null) {
            $real = substr($bytes, 0, 5) === '%PDF-' ? 'pdf' : (substr($bytes, 0, 2) === 'PK' ? 'docx' : 'txt');
            $text = i2_text_of($bytes, $real === 'pdf' ? 'simple_pdf' : $real);
            $fmt = $real === 'pdf' ? ($fmt === 'pdf' ? 'pdf' : 'simple_pdf') : $real;
        }
        $ms = intval((microtime(true) - $t0) * 1000);
        $prog('done', 'Your result is ready.');
        $out = ['ok' => trim($text) !== '', 'text' => trim($text), 'ms' => $ms, 'engine' => 'i2pdf', 'format' => $fmt, 'error' => trim($text) === '' ? 'i2pdf متنی برنگرداند.' : ''];
        if ($bytes !== '' && $fmt !== 'txt') {   // فایلِ اصلیِ i2pdf برای دانلود
            $id = bin2hex(random_bytes(8));
            @file_put_contents(so_tmp_dir() . "/i2_$id." . ($fmt === 'docx' ? 'docx' : 'pdf'), $bytes);
            $out['file'] = $id . '.' . ($fmt === 'docx' ? 'docx' : 'pdf');
        }
        return $out;
    } finally { @unlink($jar); }
}
