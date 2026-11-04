<?php
// فایل: psgx/index.php
// «پروکسیِ آزمایشیِ سامانه‌ی پاسارگاد» — فقط مدیر کل. خودِ سایتِ core.pasargadinsurance.ir از راهِ سرورِ ما باز می‌شود:
//   psgx/index.php/~o/cins/...  نشستِ جدا (خودتان با کدِ تصویر وارد می‌شوید)
//   psgx/index.php/~s/cins/...  نشستِ سرور (همان که استعلام‌ها/پیش‌نویس‌ها دارند؛ بدونِ ورودِ دوباره) — «خروج» نزنید، نشستِ سرور بسته می‌شود
// کوکیِ پاسارگاد فقط روی سرور است و به مرورگر نمی‌رود. فقط همین یک میزبان (بدونِ SSRF). آزمایشی است: اگر صفحه‌ای درست نیامد، گزارش دهید.
// (‌.user.ini و .htaccess همین پوشه enable_post_data_reading را خاموش می‌کنند تا فرم‌های multipart خام برسند)
ini_set('display_errors', '0');
const PSGX_HOST = 'core.pasargadinsurance.ir';
session_start();
$role = (string)($_SESSION['role'] ?? '');
$uid = intval($_SESSION['user_id'] ?? 0);
session_write_close();
if (!$uid || $role !== 'ADMIN') { http_response_code(403); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><meta charset="utf-8"><body style="font-family:tahoma;direction:rtl;padding:40px">فقط مدیر کل (بعد از ورود به پنل) به پروکسیِ آزمایشی دسترسی دارد.</body>'; exit; }

require_once dirname(__DIR__) . '/api/_inquiry.php';

// مسیر: PATH_INFO (index.php/~o/cins/...) یا ?__u=/~o/cins/...?a=b (هاست‌هایی که PATH_INFO ندارند)
$usePi = isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] !== '';
// مسیرِ جاافتاده: صفحه‌ای که با /cins/... مستقیم از دامنه‌ی ما خواسته شد (cins/.htaccess ← این‌جا)؛ نشست از کوکیِ psgx_m
if (isset($_GET['__fb'])) {
    $m = ($_COOKIE['psgx_m'] ?? 'o') === 's' ? 's' : 'o';
    $path = '/~' . $m . '/cins/' . ltrim((string)$_GET['__fb'], '/');
    $tq = preg_replace('/(^|&)__fb=[^&]*/', '', (string)($_SERVER['QUERY_STRING'] ?? '')); $tq = ltrim($tq, '&');
    $usePi = true; $_SERVER['PATH_INFO'] = $path;
}
elseif ($usePi) { $path = (string)$_SERVER['PATH_INFO']; $tq = (string)($_SERVER['QUERY_STRING'] ?? ''); }
else {
    $u = (string)($_GET['__u'] ?? '');
    $path = (string)(parse_url($u, PHP_URL_PATH) ?? ''); $tq = (string)(parse_url($u, PHP_URL_QUERY) ?? '');
    $rest = $_GET; unset($rest['__u']);
    if ($rest) $tq .= ($tq !== '' ? '&' : '') . http_build_query($rest);   // فرمِ GET که فیلدهایش را به نشانی می‌چسباند
}
if ($path === '' || $path === '/') $path = '/~o/cins/';
if (!preg_match('#^/~[os](?:/|$)#', $path)) $path = '/~o' . ($path[0] === '/' ? '' : '/') . $path;
$mode = $path[2]; $tpath = (string)substr($path, 3); if ($tpath === '') $tpath = '/';
if ($tpath[0] !== '/' || strpos($tpath, '..') !== false || preg_match('#[\r\n\x5c]#', $tpath . $tq)) { http_response_code(400); exit('bad path'); }
$self = (string)$_SERVER['SCRIPT_NAME'];
$base = $self . '/~' . $mode;
$target = 'https://' . PSGX_HOST . $tpath . ($tq !== '' ? '?' . $tq : '');

$jar = $mode === 's' ? px_dir(0) . '/session.cookies' : inq_dir('psgx') . '/session.cookies';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// کشِ فایل‌های ثابت (js/css/تصویر/فونت) یک روزه — بارِ کمتر روی هاست و پاسارگاد
$static = $method === 'GET' && preg_match('#\.(?:js|css|png|gif|jpe?g|svg|woff2?|ttf|eot|ico)(?:\.html)?$#i', parse_url($target, PHP_URL_PATH) ?: '') && strpos($tpath, 'captcha') === false && strpos($tpath, '/rest/') === false;
$cdir = inq_dir('psgx/cache');
$ckey = $cdir . '/' . sha1($target);
if ($static && is_file($ckey . '.b') && filemtime($ckey . '.b') > time() - 86400) {
    $h = json_decode((string)@file_get_contents($ckey . '.h'), true) ?: [];
    header('Content-Type: ' . ($h['ct'] ?? 'application/octet-stream'));
    header('Cache-Control: private, max-age=3600');
    readfile($ckey . '.b');
    exit;
}

// بدنه: خام (php://input) — اگر هاست enable_post_data_reading را خاموش نکرد و multipart بود، از $_POST/$_FILES دوباره ساخته می‌شود
$body = null; $reqCt = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (!in_array($method, ['GET', 'HEAD'], true)) {
    $raw = (string)file_get_contents('php://input');
    if ($raw === '' && ($_POST || $_FILES)) {
        $fix = function ($k) { return preg_replace('/^javax_faces_/', 'javax.faces.', (string)$k); };   // PHP نقطه را «_» می‌کند
        $body = [];
        $flat = function ($arr, $prefix = '') use (&$flat, &$body, $fix) { foreach ($arr as $k => $v) { $n = $prefix === '' ? $fix($k) : $prefix . '[' . $k . ']'; if (is_array($v)) $flat($v, $n); else $body[$n] = (string)$v; } };
        $flat($_POST);
        foreach ($_FILES as $k => $f) if (is_string($f['tmp_name'] ?? null) && is_uploaded_file($f['tmp_name'])) $body[$fix($k)] = new CURLFile($f['tmp_name'], (string)$f['type'], (string)$f['name']);
        $reqCt = '';   // curl خودش multipart می‌سازد
    } else $body = $raw;
}

$fwd = [];
foreach (['HTTP_ACCEPT' => 'Accept', 'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language', 'HTTP_X_REQUESTED_WITH' => 'X-Requested-With', 'HTTP_FACES_REQUEST' => 'Faces-Request'] as $sk => $hn)
    if (!empty($_SERVER[$sk])) $fwd[] = $hn . ': ' . $_SERVER[$sk];
if ($reqCt !== '' && is_string($body)) $fwd[] = 'Content-Type: ' . $reqCt;
if (!in_array($method, ['GET', 'HEAD'], true)) $fwd[] = 'Origin: https://' . PSGX_HOST;
$fwd[] = 'Expect:';
// Referer: نشانیِ پروکسی ← نشانیِ اصلی
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($ref !== '' && ($p = strpos($ref, $self . '/~')) !== false) $ref = 'https://' . PSGX_HOST . substr($ref, $p + strlen($self) + 3);
elseif ($ref !== '' && preg_match('/[?&]__u=([^&]+)/', $ref, $rm)) $ref = 'https://' . PSGX_HOST . preg_replace('#^/~[os]#', '', urldecode($rm[1]));
else $ref = '';

$lk = null;
if ($mode === 's') { $lk = inq_lock(20); if (!$lk) { http_response_code(503); header('Content-Type: text/html; charset=utf-8'); echo '<meta charset="utf-8"><body style="font-family:tahoma;direction:rtl;padding:30px">نشستِ سرور مشغولِ استعلام/پیش‌نویس است؛ چند ثانیه‌ی دیگر صفحه را تازه کنید.</body>'; exit; } }
$respH = [];
$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false, CURLOPT_ENCODING => '',
    CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => $fwd, CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_USERAGENT => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
    CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respH) { $t = trim($line); if ($t !== '' && strpos($t, ':') !== false) { [$k, $v] = explode(':', $t, 2); $respH[strtolower(trim($k))][] = trim($v); } return strlen($line); },
]);
if ($ref !== '') curl_setopt($ch, CURLOPT_REFERER, $ref);
if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
$out = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
@chmod($jar, 0600);
if ($lk) inq_unlock($lk);
if ($out === false) { http_response_code(502); header('Content-Type: text/html; charset=utf-8'); echo '<meta charset="utf-8"><body style="font-family:tahoma;direction:rtl;padding:30px">سامانه‌ی پاسارگاد از سرور در دسترس نیست: ' . htmlspecialchars($err) . '</body>'; exit; }
if ($mode === 's' && function_exists('inq_touch_activity')) inq_touch_activity();

// نشانی‌ها ← پروکسی
$mk = function ($p) use ($base, $self, $usePi, $mode) { return $usePi ? $base . $p : $self . '?__u=' . rawurlencode('/~' . $mode . $p); };
$rewrite = function ($s, $html) use ($mk, $self) {
    $own = preg_quote(ltrim($self, '/'), '#');
    $s = preg_replace_callback('#(?:https?:)?//' . preg_quote(PSGX_HOST, '#') . '(?::\d+)?(/[^\s"\'<>)]*)?#i', function ($x) use ($mk) { return $mk($x[1] ?? '/'); }, $s);
    // نشانی‌های ریشه‌دار (/cins/...) در ویژگی‌ها، رشته‌های JS و url() در CSS
    // (یک بار: هر جا که نشانیِ اصلی بود؛ نشانی‌های بازنویسی‌شده‌ی بالا دوباره دست نمی‌خورند)
    $s = preg_replace_callback('#(["\'(=]\s*)(/(?!' . $own . ')(?:cins(?=["\'/?\#])[^"\'\s)<>]*))#', function ($x) use ($mk) { return $x[1] . $mk($x[2]); }, $s);
    if ($html) $s = preg_replace_callback('#\b(src|href|action)=(["\'])(/(?!/)(?!cins/)(?!' . $own . ')[^"\']*)\2#i', function ($x) use ($mk) { return $x[1] . '=' . $x[2] . $mk($x[3]) . $x[2]; }, $s);
    return $s;
};

http_response_code($code ?: 200);
header('Set-Cookie: psgx_m=' . $mode . '; Path=/; HttpOnly; SameSite=Lax');
if (!empty($respH['location'][0])) {
    $loc = $respH['location'][0];
    if (preg_match('#^(?:https?:)?//' . preg_quote(PSGX_HOST, '#') . '(/.*)?$#i', $loc, $lm)) $loc = $mk($lm[1] ?? '/');
    elseif ($loc !== '' && $loc[0] === '/') $loc = $mk($loc);
    elseif (!preg_match('#^[a-z]+:#i', $loc)) $loc = $mk(rtrim(dirname($tpath), '/') . '/' . $loc);
    header('Location: ' . $loc);
}
$ct = (string)($respH['content-type'][0] ?? '');
if ($ct !== '') header('Content-Type: ' . $ct);
if (!empty($respH['content-disposition'][0])) header('Content-Disposition: ' . $respH['content-disposition'][0]);
header('X-Robots-Tag: noindex');
$isHtml = stripos($ct, 'html') !== false; $isText = $isHtml || preg_match('#css|javascript|json|xml|text/#i', $ct);
if ($isText) {
    $out = $rewrite($out, $isHtml);
    // نشانی‌هایی که اسکریپت‌های خودِ سامانه در مرورگر می‌سازند (XHR، fetch، iframe، پنجره‌ی تازه، تغییرِ src/href/action) هم از پروکسی بروند
    if ($isHtml) {
        $pre = $usePi ? $base : $self . '?__u=' . rawurlencode('/~' . $mode);
        $shim = '<script>(function(){var B=' . json_encode($pre) . ',Q=' . ($usePi ? 'false' : 'true') . ',H=/^https?:\/\/' . preg_quote(PSGX_HOST, '/') . '/i;'
              . 'function fx(u){if(typeof u!=="string"||!u)return u;if(u.indexOf(B)===0||u.indexOf(' . json_encode($self) . ')===0)return u;if(H.test(u))u=u.replace(/^https?:\/\/[^\/]+/,"");'
              . 'if(u.charAt(0)==="/"&&u.charAt(1)!=="/")return Q?B+encodeURIComponent(u):B+u;return u;}window.__psgxFix=fx;'
              . 'var xo=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u){arguments[1]=fx(u);return xo.apply(this,arguments);};'
              . 'if(window.fetch){var f0=window.fetch;window.fetch=function(u,o){return f0.call(this,typeof u==="string"?fx(u):u,o);};}'
              . 'var wo=window.open;window.open=function(u){if(arguments.length)arguments[0]=fx(u);return wo.apply(this,arguments);};'
              . 'var sa=Element.prototype.setAttribute;Element.prototype.setAttribute=function(n,v){if(/^(src|href|action)$/i.test(n))v=fx(String(v));return sa.call(this,n,v);};'
              . '[[window.HTMLIFrameElement,"src"],[window.HTMLFrameElement,"src"],[window.HTMLScriptElement,"src"],[window.HTMLImageElement,"src"],[window.HTMLAnchorElement,"href"],[window.HTMLFormElement,"action"],[window.HTMLLinkElement,"href"]].forEach(function(p){'
              . 'if(!p[0])return;var d=Object.getOwnPropertyDescriptor(p[0].prototype,p[1]);if(!d||!d.set)return;Object.defineProperty(p[0].prototype,p[1],{get:d.get,set:function(v){d.set.call(this,fx(String(v)));},configurable:true});});'
              . 'try{var la=Location.prototype.assign,lr=Location.prototype.replace;Location.prototype.assign=function(u){return la.call(this,fx(u));};Location.prototype.replace=function(u){return lr.call(this,fx(u));};}catch(e){}'
              . '})();</script>';
        if (preg_match('/<head\b[^>]*>/i', $out, $hm, PREG_OFFSET_CAPTURE)) $out = substr($out, 0, $hm[0][1] + strlen($hm[0][0])) . $shim . substr($out, $hm[0][1] + strlen($hm[0][0]));
        elseif (stripos($out, '<html') !== false || stripos($out, '<body') !== false) $out = $shim . $out;
    }
    if ($isHtml && stripos($out, '</body>') !== false) {
        $tag = '<div id="psgx-tag" style="position:fixed;left:8px;bottom:8px;z-index:2147483647;background:' . ($mode === 's' ? '#b45309' : '#0f766e') . ';color:#fff;font:12px tahoma;padding:5px 10px;border-radius:9px;opacity:.85;direction:rtl;pointer-events:none">'
             . 'پروکسیِ آزمایشیِ سایت بیمه — ' . ($mode === 's' ? 'نشستِ سرور (خروج نزنید)' : 'نشستِ جدا') . '</div>';
        $out = preg_replace('#</body>#i', $tag . '</body>', $out, 1);
    }
    header('Cache-Control: no-store');
} else header('Cache-Control: private, max-age=600');
if ($static && $code === 200 && strlen($out) < 8 * 1048576) { @file_put_contents($ckey . '.b', $out); @file_put_contents($ckey . '.h', json_encode(['ct' => $ct])); }
echo $out;
