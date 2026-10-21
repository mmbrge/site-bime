<?php
// فایل: api/_inquiry.php
// «استعلامات»: شخصِ حقیقی، شخصِ حقیقی + کد پستی، کد پستی (املاک و اسکان) از راهِ سامانه‌ی ریتونِ بیمه پاسارگاد،
// و شخصِ حقوقی از s.api.ir (api/_apis.php). همان گام‌های ابزارِ PasargadFlow (ورود به فرمِ پیش‌نویس ← loadCodeMelli ←
// «استعلام شخص حقیقی» ← افزودن به «لیست طرف قرارداد» ← «استعلامِ کد پستی»)، با این تفاوت‌ها:
//   - هر استعلام یک ردیف در inq_requests (تاریخچه، نتیجه، تایم‌لاین)، و صفحه‌ی هر گام در پوشه‌ی خصوصیِ همان استعلام
//     (api/data/inq/jobs/{id}/stepN.html) فقط برای مدیر کل؛
//   - چون نشستِ پاسارگاد یکی است، در هر لحظه فقط یک استعلام اجرا می‌شود (قفل) و بقیه در صف می‌مانند؛
//   - نتیجه‌ی موفق تا چند روز (تنظیمات) دوباره استفاده می‌شود و سامانه دوباره صدا زده نمی‌شود؛
//   - نام کاربری و رمزِ سامانه رمزنگاری‌شده ذخیره می‌شود (کلید در فایلی خارج از پایگاه‌داده).
// جدول‌ها خودکار ساخته می‌شوند (inq_ensure).

const INQ_KINDS = ['person' => 'شخص حقیقی', 'person_postal' => 'شخص حقیقی + کد پستی', 'postal' => 'کد پستی', 'legal' => 'شخص حقوقی'];
const INQ_ST_FA = ['QUEUED' => 'در صف', 'RUNNING' => 'در حال اجرا', 'OK' => 'موفق', 'WARN' => 'با هشدار', 'FAIL' => 'ناموفق'];
const INQ_DEFAULTS = ['inq_wait_sec' => '2', 'inq_cache_days' => '30', 'inq_postal_required' => '0', 'inq_miniapp_person' => '1', 'inq_miniapp_postal' => '1',
                      'inq_daily_limit' => '30', 'inq_enabled' => '1', 'inq_keep_minutes' => '60'];

function inq_dir($sub = '') {
    $d = __DIR__ . '/data/inq';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    if ($sub !== '') { $d .= '/' . $sub; if (!is_dir($d)) @mkdir($d, 0700, true); }
    return $d;
}

function inq_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS inq_requests (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(16) NOT NULL, national_id VARCHAR(11) NULL, birth VARCHAR(10) NULL, postal VARCHAR(10) NULL,
            status VARCHAR(8) NOT NULL DEFAULT 'QUEUED', notice VARCHAR(600) NULL, person_name VARCHAR(190) NULL, father_name VARCHAR(120) NULL,
            postal_valid TINYINT NULL, postal_text VARCHAR(600) NULL, result_json MEDIUMTEXT NULL, timeline_json MEDIUMTEXT NULL,
            source VARCHAR(12) NOT NULL DEFAULT 'panel', batch_id INT NULL, row_no INT NULL, cached_from BIGINT NULL,
            user_type VARCHAR(8) NULL, user_id INT NULL, user_name VARCHAR(190) NULL, ms INT NULL,
            created_at DATETIME NOT NULL, started_at DATETIME NULL, finished_at DATETIME NULL,
            KEY idx_nid (national_id), KEY idx_postal (postal), KEY idx_batch (batch_id, status), KEY idx_status (status), KEY idx_created (created_at))$T",
        "CREATE TABLE IF NOT EXISTS inq_batches (
            id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL, kind VARCHAR(16) NOT NULL, file_name VARCHAR(255) NULL,
            total INT NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'RUNNING', user_id INT NULL, user_name VARCHAR(190) NULL,
            created_at DATETIME NOT NULL, finished_at DATETIME NULL, KEY idx_created (created_at))$T",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[inq_ensure] ' . $e->getMessage()); } }
}

function inq_get_setting($pdo, $k) {
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $st->execute([$k]); $v = $st->fetchColumn(); }
    catch (Throwable $e) { $v = false; }
    return ($v === false || $v === null || $v === '') ? (INQ_DEFAULTS[$k] ?? '') : (string)$v;
}
function inq_set_setting($pdo, $k, $v) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, (string)$v]);
}
function inq_settings($pdo) { $o = []; foreach (INQ_DEFAULTS as $k => $v) $o[$k] = inq_get_setting($pdo, $k); return $o; }

// ---- رمزنگاریِ رمزِ سامانه (کلید در فایلِ خصوصی، نه در پایگاه‌داده) ----
function inq_key() {
    $f = inq_dir() . '/.key';
    $k = is_file($f) ? (string)@file_get_contents($f) : '';
    if (strlen($k) !== 64) { $k = bin2hex(random_bytes(32)); @file_put_contents($f, $k); @chmod($f, 0600); }
    return hex2bin($k);
}
function inq_enc($plain) {
    if ($plain === '') return '';
    if (!function_exists('openssl_encrypt')) return 'b64:' . base64_encode($plain);
    $iv = random_bytes(16);
    return 'v1:' . base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', inq_key(), OPENSSL_RAW_DATA, $iv));
}
function inq_dec($enc) {
    $enc = (string)$enc;
    if (strpos($enc, 'b64:') === 0) return (string)base64_decode(substr($enc, 4));
    if (strpos($enc, 'v1:') !== 0 || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode(substr($enc, 3));
    if (strlen($raw) < 17) return '';
    $d = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', inq_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $d === false ? '' : $d;
}
function inq_creds($pdo) { return ['user' => inq_get_setting($pdo, 'inq_px_user'), 'pass' => inq_dec(inq_get_setting($pdo, 'inq_px_pass'))]; }

// ---- اعتبارسنجی ----
function inq_digits($s) { return preg_replace('/\D/', '', strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])); }
function inq_nid_ok($c) {
    if (!preg_match('/^\d{10}$/', $c) || preg_match('/^(\d)\1{9}$/', $c)) return false;
    $s = 0;
    for ($i = 0; $i < 9; $i++) $s += ((int)$c[$i]) * (10 - $i);
    $r = $s % 11;
    return $r < 2 ? (int)$c[9] === $r : (int)$c[9] === 11 - $r;
}
function inq_norm_nid($s) { $s = inq_digits($s); if (strlen($s) >= 8 && strlen($s) < 10) $s = str_pad($s, 10, '0', STR_PAD_LEFT); return $s; }
// تاریخِ تولد به «YYYY/MM/DD» (۱۳۵۲/۰۵/۰۴، 1352-5-4، 13520504، 520504)
function inq_norm_birth($s) {
    $t = strtr(trim((string)$s), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    $p = array_values(array_filter(preg_split('/\D+/', $t), 'strlen'));
    if (count($p) === 3) { [$y, $m, $d] = array_map('intval', $p); if ($y < 100 && $d > 1300) { $x = $y; $y = $d; $d = $x; } if ($y > 0 && $y < 100) $y += 1300; }
    else { $g = preg_replace('/\D/', '', $t); if (strlen($g) === 8) { $y = (int)substr($g, 0, 4); $m = (int)substr($g, 4, 2); $d = (int)substr($g, 6, 2); } elseif (strlen($g) === 6) { $y = 1300 + (int)substr($g, 0, 2); $m = (int)substr($g, 2, 2); $d = (int)substr($g, 4, 2); } else return null; }
    if ($y < 1250 || $y > 1420 || $m < 1 || $m > 12 || $d < 1 || $d > 31) return null;
    return sprintf('%04d/%02d/%02d', $y, $m, $d);
}

// =====================================================================
//  گام‌های سامانه‌ی پاسارگاد (منطقِ ابزارِ PasargadFlow، بدونِ تغییر در قاعده‌ها)
// =====================================================================
class InqPasargad
{
    private $sso = 'https://sso.pasargadinsurance.ir';
    private $core = 'https://core.pasargadinsurance.ir';
    private $listUrl, $formUrl, $cookieFile, $jobDir;
    private $lastUrl = '';
    private $state = [];
    private $html = [];

    public function __construct($jobDir = '')
    {
        $this->listUrl = $this->core . '/cins/bimehshakhssalespishnevis/bimehShakhsSalesPishnevis.simplelist.html';
        $this->formUrl = $this->core . '/cins/bimehshakhssalespishnevis/bimehShakhsSalesPishnevis.simpleform.html';
        $this->cookieFile = inq_dir() . '/session.cookies';
        $this->jobDir = $jobDir;
    }

    private function saveStepHtml($n, $html)
    {
        $this->html[$n] = $html;
        if ($this->jobDir !== '') @file_put_contents($this->jobDir . '/step' . (int)$n . '.html', $html);
    }

    private function http($method, $url, $fields = [], $referer = '', $multipart = true, $extraHeaders = [])
    {
        $ch = curl_init($url);
        $headers = array_merge(['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', 'Accept-Language: en-GB,en;q=0.9,fa-IR;q=0.8,fa;q=0.7',
                                'Cache-Control: max-age=0', 'Connection: keep-alive', 'Upgrade-Insecure-Requests: 1'], $extraHeaders);
        if ($method === 'POST') { $p = parse_url($url); if (!empty($p['scheme']) && !empty($p['host'])) $headers[] = 'Origin: ' . $p['scheme'] . '://' . $p['host']; }
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10, CURLOPT_COOKIEJAR => $this->cookieFile, CURLOPT_COOKIEFILE => $this->cookieFile,
                 CURLOPT_TIMEOUT => 55, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => $headers,
                 CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'];
        $ref = $referer ?: $this->lastUrl;
        if ($ref) $opts[CURLOPT_REFERER] = $ref;
        if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $multipart ? $fields : http_build_query($fields); }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $eff = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($eff !== '' && strpos($url, '/rest/') === false) $this->lastUrl = $eff;
        $res = ['body' => $body === false ? '' : $body, 'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'url' => $eff, 'error' => curl_error($ch)];
        curl_close($ch);
        @chmod($this->cookieFile, 0600);
        return $res;
    }

    private function httpJson($url, $referer = '')
    {
        $r = $this->http('GET', $url, [], $referer ?: $this->formUrl, false, ['Accept: application/json, text/javascript, */*; q=0.01', 'X-Requested-With: XMLHttpRequest']);
        if ($r['code'] !== 200 || $r['body'] === '') return null;
        $d = json_decode($r['body'], true);
        return is_array($d) ? $d : null;
    }

    private function sliceFormHtml($html, $formId)
    {
        $q = preg_quote($formId, '/');
        if (preg_match('/<form\b[^>]*\bid=["\']' . $q . '["\'][^>]*>/is', $html, $m, PREG_OFFSET_CAPTURE) || preg_match('/<form\b[^>]*\bname=["\']' . $q . '["\'][^>]*>/is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $end = stripos($html, '</form>', $start);
            return $end !== false ? substr($html, $start, $end - $start + 7) : substr($html, $start);
        }
        return $html;
    }
    private function decodeHtml($s) { return html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8'); }

    private function fields($html, $formId = 'editform')
    {
        $formHtml = $this->sliceFormHtml($html, $formId);
        $f = [];
        if (!preg_match_all('/<(input|select|textarea)\b([^>]*)>/is', $formHtml, $matches, PREG_SET_ORDER + PREG_OFFSET_CAPTURE)) return $f;
        foreach ($matches as $m) {
            $tag = strtolower($m[1][0]); $attrs = $m[2][0]; $end = $m[0][1] + strlen($m[0][0]);
            if (preg_match('/\bdisabled\b/i', $attrs) || !preg_match('/\bname=["\']([^"\']+)["\']/i', $attrs, $nm)) continue;
            $name = $this->decodeHtml($nm[1]);
            if ($tag === 'input') {
                $type = preg_match('/\btype=["\']([^"\']+)["\']/i', $attrs, $tm) ? strtolower($tm[1]) : 'text';
                if (in_array($type, ['submit', 'button', 'file', 'image', 'reset'], true)) continue;
                if (in_array($type, ['checkbox', 'radio'], true) && !preg_match('/\bchecked\b/i', $attrs)) continue;
                $f[$name] = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $attrs, $vm) ? $this->decodeHtml($vm[1]) : ($type === 'checkbox' ? 'true' : '');
            } elseif ($tag === 'select') {
                $close = stripos($formHtml, '</select>', $end);
                $inner = $close !== false ? substr($formHtml, $end, $close - $end) : '';
                $multi = preg_match('/\bmultiple\b/i', $attrs) === 1;
                $sel = null; $first = null;
                if (preg_match_all('/<option\b([^>]*)>/is', $inner, $om)) foreach ($om[1] as $oa) {
                    $val = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $oa, $vm) ? $this->decodeHtml($vm[1]) : '';
                    if ($first === null) $first = $val;
                    if (preg_match('/\bselected\b/i', $oa)) { $sel = $val; break; }
                }
                if ($multi) { if ($sel !== null) $f[$name] = $sel; }
                elseif ($sel !== null || $first !== null) $f[$name] = $sel !== null ? $sel : $first;
            } else {
                $close = stripos($formHtml, '</textarea>', $end);
                $f[$name] = $this->decodeHtml($close !== false ? substr($formHtml, $end, $close - $end) : '');
            }
        }
        return $f;
    }

    private function submit($url, $html, $overrides, $clickedId, $referer = '')
    {
        $formId = strpos($clickedId, ':') !== false ? explode(':', $clickedId, 2)[0] : 'editform';
        $f = $this->fields($html, $formId);
        foreach ($overrides as $k => $v) { if ($v === null) unset($f[$k]); else $f[$k] = (string)$v; }
        if (array_key_exists('autoScroll', $f) && $f['autoScroll'] === '') $f['autoScroll'] = '0,0';
        $vs = $f['javax.faces.ViewState'] ?? null;
        unset($f[$formId . '_SUBMIT'], $f['javax.faces.ViewState'], $f[$formId . ':_idcl'], $f[$formId . ':_link_hidden_']);
        $f[$formId . '_SUBMIT'] = '1';
        if ($vs !== null) $f['javax.faces.ViewState'] = $vs;
        $f[$formId . ':_idcl'] = $clickedId;
        return $this->http('POST', $url, $f, $referer ?: $url, true);
    }

    private function callLoadCodeMelli($nid)
    {
        $json = $this->httpJson($this->core . '/cins/rest/shakhsHaghighi/getByCodeMelli/' . rawurlencode($nid) . '/29/-1', $this->formUrl);
        if (!is_array($json) || empty($json['success'])) return ['found' => false, 'fields' => [], 'person' => null, 'dbBirthYmd' => ''];
        $str = function ($k, $def = '') use ($json) { if (!isset($json[$k]) || $json[$k] === null) return $def; $v = trim((string)$json[$k]); return $v === '-1' ? $def : $v; };
        $nn = function ($k, $allowed = null, $def = '') use ($json) {
            if (!isset($json[$k]) || $json[$k] === null || !is_numeric($json[$k])) return $def;
            $i = (int)$json[$k]; if ($i < 0) return $def; $s = (string)$i;
            return is_array($allowed) && !in_array($s, $allowed, true) ? $def : $s;
        };
        $dbBirth = '';
        if (!empty($json['tarikhTavalod'])) {
            $p = explode('/', (string)$json['tarikhTavalod']);
            if (count($p) === 3) $dbBirth = sprintf('%04d/%02d/%02d', (int)$p[0], (int)$p[1], (int)explode(' ', trim($p[2]))[0]);
        }
        $sh = $str('shomarehShenasnameh');
        if ($sh === '' && $str('naam') !== '') $sh = '0';
        $jensiat = $nn('jensiat', ['0', '1'], '0'); $taahol = $nn('vaziatTaahol', ['0', '1', '2', '3'], ''); $madrak = $nn('madrakTahsili', ['0', '1', '2', '3', '4', '5', '6'], '');
        $hayat = $nn('vaziatHayat', ['0', '1'], '0'); $hesab = $nn('goroohHesabdari', ['1', '2', '3'], '1'); $melliat = $nn('melliat', null, '29');
        if ($melliat === '' || $melliat === '0') $melliat = '29';
        $eg = $nn('shomarehEghtesadi', null, ''); if ($eg === '0') $eg = '';
        $fields = [
            'editform:noeShakhs' => '0', 'editform:noeTarafGharardad' => '', 'editform:isBimehgozar' => 'true', 'editform:isTarafAsli' => 'true', 'editform:isPardakhtKonandeh' => 'true',
            'shakhsHaghighi_ID' => $str('id'), 'shakhsHaghighi_codeMelli_hidden' => $nid, 'shakhsHaghighi_codeMelli' => $nid,
            'shakhsHaghighi_codeFida_hidden' => $str('codeFida'), 'shakhsHaghighi_codeFida' => $str('codeFida'),
            'shakhsHaghighi_naam_hidden' => $str('naam'), 'shakhsHaghighi_naam' => $str('naam'),
            'shakhsHaghighi_naamKhanevadegi_hidden' => $str('naamkhanevadegi'), 'shakhsHaghighi_naamKhanevadegi' => $str('naamkhanevadegi'),
            'shakhsHaghighi_naamPedar_hidden' => $str('naamPedar'), 'shakhsHaghighi_naamPedar' => $str('naamPedar'),
            'shakhsHaghighi_shomarehShenasnameh_hidden' => $sh, 'shakhsHaghighi_shomarehShenasnameh' => $sh,
            'shakhsHaghighi_mahalSodoor_hidden' => $str('mahalSodoor'), 'shakhsHaghighi_mahalSodoor' => $str('mahalSodoor'),
            'shakhsHaghighi_mahalTavalod_hidden' => $str('mahalTavalod'), 'shakhsHaghighi_mahalTavalod' => $str('mahalTavalod'),
            'shakhsHaghighi_vaziatTaahol_hidden' => $taahol, 'shakhsHaghighi_vaziatTaahol' => $taahol,
            'shakhsHaghighi_madrakTahsili_hidden' => $madrak, 'shakhsHaghighi_madrakTahsili' => $madrak,
            'shakhsHaghighi_jensiat_hidden' => $jensiat, 'shakhsHaghighi_jensiat' => $jensiat,
            'shakhsHaghighi_shomarehShenasai_hidden' => $str('shomarehShenasai'),
            'shakhsHaghighi_melliat_hidden' => $melliat, 'shakhsHaghighi_melliat' => $melliat,
            'shakhsHaghighi_goroohHesabdari_hidden' => $hesab, 'shakhsHaghighi_goroohHesabdari' => $hesab,
            'shakhsHaghighi_vaziatHayat_hidden' => $hayat, 'shakhsHaghighi_vaziatHayat' => $hayat,
            'editform:shakhsHaghighi_tarikhFot' => '//', 'editform:shakhsHaghighi_tarikhFot_y' => '', 'editform:shakhsHaghighi_tarikhFot_m' => '', 'editform:shakhsHaghighi_tarikhFot_d' => '',
            'shakhsHaghighi_shoghl_hidden' => '', 'shakhsHaghighi_shoghl' => null,
            'shakhs_shomarehEghtesadi_hidden' => $eg, 'shakhs_shomarehEghtesadi' => $eg,
        ];
        if (!empty($json['moshakhasatErtebati']) && is_array($json['moshakhasatErtebati'])) {
            $m = $json['moshakhasatErtebati'];
            $g = function ($k) use ($m) { return isset($m[$k]) && $m[$k] !== null ? (string)$m[$k] : ''; };
            $fields['moshakhasatOmumi_telefon'] = $g('telefon'); $fields['moshakhasatOmumi_mobile_number'] = $g('mobile');
            $fields['moshakhasatOmumi_mobile_operator'] = isset($m['operatorId']) && $m['operatorId'] !== null && (int)$m['operatorId'] > 0 ? (string)$m['operatorId'] : '';
            $fields['moshakhasatOmumi_fax'] = $g('fax'); $fields['moshakhasatOmumi_email'] = $g('email'); $fields['moshakhasatOmumi_codePosti'] = $g('codePosti'); $fields['moshakhasatOmumi_address'] = $g('address');
            if (!empty($m['shahrId']) && (int)$m['shahrId'] > 0) $fields['editform:moshakhasatOmumi_shahr'] = (string)$m['shahrId'];
            if (!empty($m['shahrDesc'])) $fields['editform:moshakhasatOmumi_shahr.desc'] = (string)$m['shahrDesc'];
        }
        $person = ['id' => $str('id'), 'name' => $str('naam'), 'family' => $str('naamkhanevadegi'), 'fatherName' => $str('naamPedar'), 'gender' => $jensiat === '0' ? 'مرد' : ($jensiat === '1' ? 'زن' : '')];
        return ['found' => $person['name'] !== '' && $person['family'] !== '', 'fields' => $fields, 'person' => $person, 'dbBirthYmd' => $dbBirth];
    }

    private function inspectTarafTable($html, $nid = '')
    {
        if (preg_match('/<tbody\b[^>]*\bid=["\']editform:TarafGharardad:tbody_element["\'][^>]*>(.*?)<\/tbody>/is', $html, $m)) {
            $tb = trim($m[1]); $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($tb)));
            return ['added' => $tb !== '' && (stripos($tb, '<tr') !== false || ($nid !== '' && strpos($tb, $nid) !== false)), 'text' => $plain ?: 'جدول خالی است'];
        }
        return ['added' => false, 'text' => 'جدول در صفحه یافت نشد'];
    }

    // پیام‌های سبز (messageList)، هشدارهای زرد (warningList) و خطاهای قرمز (errorList)
    private function parseNotices($html)
    {
        $msg = []; $warn = []; $err = [];
        foreach (['success-notif' => 'msg', 'warning-notif' => 'warn', 'danger-notif' => 'err', 'error-notif' => 'err'] as $cls => $to)
            if (preg_match_all('/<li[^>]*class="[^"]*' . $cls . '[^"]*"[^>]*>.*?<span class="txt">([^<]*)<\/span>/is', $html, $m)) foreach ($m[1] as $t) if (trim($t) !== '') ${$to}[] = trim($t);
        if (preg_match('/var\s+errorList\s*=\s*\[(.*?)\]\s*var\s+messageList\s*=\s*\[(.*?)\]\s*var\s+warningList\s*=\s*\[(.*?)\](.*?)build\s*\(\s*messageList\s*,\s*errorList\s*,\s*warningList\s*\)/is', $html, $sm)) {
            $js = $sm[4];
            if (preg_match_all('/messageList\s*\.push\s*\(\s*["\']([^"\']+)["\']\s*\)/u', $js, $mm)) foreach ($mm[1] as $t) if (trim($t) !== '') $msg[] = trim($t);
            if (preg_match_all('/warningList\s*\.push\s*\(\s*["\']([^"\']+)["\']\s*\)/u', $js, $mm)) foreach ($mm[1] as $t) if (trim($t) !== '') $warn[] = trim($t);
            if (preg_match_all('/errorList\s*\.push\s*\(\s*["\']([^"\']+)["\']\s*\)/u', $js, $mm)) foreach ($mm[1] as $t) if (trim($t) !== '') $err[] = trim($t);
        }
        $msg = array_values(array_unique($msg)); $warn = array_values(array_unique($warn)); $err = array_values(array_unique($err));
        $parts = [];
        if ($msg) $parts[] = '🟢 ' . implode(' - ', $msg);
        if ($warn) $parts[] = '🟡 هشدار: ' . implode(' - ', $warn);
        if ($err) $parts[] = '🔴 خطا: ' . implode(' - ', $err);
        return ['messages' => $msg, 'warnings' => $warn, 'errors' => $err, 'hasSuccess' => (bool)$msg, 'hasWarning' => (bool)$warn, 'hasError' => (bool)$err,
                'onlyWarning' => $warn && !$err && !$msg, 'summaryText' => $parts ? implode(' / ', $parts) : 'بدون پیام/خطا'];
    }

    private function inspectLivePage($html, $nid = '')
    {
        $f = $this->fields($html, 'editform');
        $n = $this->parseNotices($html);
        $ns = $f['editform:noeShakhs'] ?? null;
        return ['token' => $f['token'] ?? '—', 'noeShakhs' => $ns === '0' ? 'حقیقی (0)' : ($ns ?? '—'),
                'checkboxes' => ['isBimehgozar' => !empty($f['editform:isBimehgozar']), 'isTarafAsli' => !empty($f['editform:isTarafAsli']), 'isPardakhtKonandeh' => !empty($f['editform:isPardakhtKonandeh'])],
                'codeMelli' => !empty($f['shakhsHaghighi_codeMelli']) ? $f['shakhsHaghighi_codeMelli'] : ($f['shakhsHaghighi_codeMelli_hidden'] ?? '—'),
                'shakhsId' => $f['shakhsHaghighi_ID'] ?? '—', 'birthHidden' => $f['editform:shakhsHaghighi_tarikhTavalod'] ?? '—',
                'birthYMD' => ($f['editform:shakhsHaghighi_tarikhTavalod_y'] ?? '') . '/' . ($f['editform:shakhsHaghighi_tarikhTavalod_m'] ?? '') . '/' . ($f['editform:shakhsHaghighi_tarikhTavalod_d'] ?? ''),
                'person' => $this->readPerson($html), 'tarafTable' => $this->inspectTarafTable($html, $nid),
                'codePostiInForm' => $f['moshakhasatOmumi_codePosti'] ?? '—', 'serverNotice' => $n['summaryText'], 'serverNoticeOk' => $n['hasSuccess'] ? true : ($n['hasError'] ? false : null)];
    }

    private function hasField($html, $name)
    {
        $q = preg_quote($name, '/');
        return (bool)(preg_match('/\b(name|id)=["\'](editform:)?' . $q . '["\']/i', $html));
    }
    private function loggedOut($html) { return $html === '' || strpos($html, 'ssoLoginForm') !== false; }

    public function getCaptcha()
    {
        $r = $this->http('GET', $this->sso . '/login');
        if (preg_match('/id="captcha-imgId"[^>]*src="(data:image\/png;base64,[^"]+)"/i', $r['body'], $m)) return ['ok' => true, 'image' => $m[1]];
        if ($r['body'] === '') return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد در دسترس نیست' . ($r['error'] ? ' (' . $r['error'] . ')' : '') . '.'];
        return ['ok' => true, 'loggedIn' => !$this->loggedOut($r['body'])];
    }

    public function login($user, $pass, $captcha)
    {
        $r = $this->http('POST', $this->sso . '/login', ['loginType' => 'SSO_USER', 'login_method_sub_type' => 'PASSWORD', 'representativeUsername' => '', 'delegationFlow' => '', 'delegationRequestId' => '',
                                                         'username' => $user, 'password' => $pass, 'j_captcha' => $captcha], $this->sso . '/login', true);
        if ($this->loggedOut($r['body'])) {
            $msg = preg_match('/id="message"[^>]*>([^<]*)/i', $r['body'], $m) ? trim($m[1]) : '';
            // صفحه‌ی خطا خودش کپچای تازه دارد؛ همان را نشان می‌دهیم (درخواستِ دیگری کپچا را دوباره عوض می‌کند)
            $img = preg_match('/id="captcha-imgId"[^>]*src="(data:image\/png;base64,[^"]+)"/i', $r['body'], $mm) ? $mm[1] : null;
            return ['ok' => false, 'error' => $msg ?: 'نام کاربری، رمز یا کپچا اشتباه است.', 'image' => $img];
        }
        $r2 = $this->http('GET', $this->core);
        if ($this->loggedOut($r2['body'])) return ['ok' => false, 'error' => 'ورود به پنل ناموفق بود؛ دوباره تلاش کنید.'];
        return ['ok' => true];
    }

    public function status()
    {
        $r = $this->http('GET', $this->listUrl);
        if ($r['body'] === '' && $r['error'] !== '') return 'down';
        return $this->loggedOut($r['body']) ? 'logged_out' : 'logged_in';
    }

    private function t0() { return microtime(true); }
    private function took($t) { return round(microtime(true) - $t, 1) . ' ثانیه'; }

    // یک گام (۱ تا ۵)؛ وضعیتِ بینِ گام‌ها در حافظه‌ی همین شیء نگه داشته می‌شود
    public function runStep($step, $nid, array $birth, $postal)
    {
        $t = $this->t0();
        $y = sprintf('%04d', (int)$birth['y']); $mo = sprintf('%02d', (int)$birth['m']); $d = sprintf('%02d', (int)$birth['d']);
        $ymd = "$y/$mo/$d";
        if ($step === 1) {
            $list = $this->http('GET', $this->listUrl);
            if ($list['body'] === '' && $list['error'] !== '') return ['ok' => false, 'error' => 'down', 'step' => 1, 'status' => 'bad', 'title' => '۱. ورود به فرم ایجاد پیش‌نویس', 'detail' => 'سامانه در دسترس نیست: ' . $list['error'], 'time' => $this->took($t)];
            if ($this->loggedOut($list['body'])) return ['ok' => false, 'error' => 'need_login'];
            if (strpos($list['body'], 'exampleform:create') === false) return ['ok' => false, 'step' => 1, 'status' => 'bad', 'title' => '۱. ورود به فرم ایجاد پیش‌نویس', 'detail' => 'دکمه exampleform:create در صفحه لیست پیدا نشد', 'time' => $this->took($t)];
            $r = $this->submit($this->listUrl, $list['body'], [], 'exampleform:create', $this->listUrl);
            if ($this->loggedOut($r['body'])) return ['ok' => false, 'error' => 'need_login'];
            $html = $r['body'];
            $this->saveStepHtml(1, $html);
            $n = $this->parseNotices($html);
            if (!$this->hasField($html, 'shakhsHaghighi_codeMelli'))
                return ['ok' => false, 'step' => 1, 'status' => 'bad', 'title' => '۱. ورود به فرم ایجاد پیش‌نویس', 'detail' => 'فرم ایجاد پیش‌نویس باز نشد (' . $n['summaryText'] . ')', 'time' => $this->took($t), 'live' => $this->inspectLivePage($html, $nid)];
            $this->state = ['lastStep' => 1];
            $live = $this->inspectLivePage($html, $nid);
            return ['ok' => true, 'step' => 1, 'status' => $n['hasWarning'] ? 'warn' : 'ok', 'title' => '۱. باز کردن لیست و کلیک روی «ایجاد»',
                    'detail' => "فرم ایجاد پیش‌نویس باز شد (توکن: {$live['token']})" . ($n['hasWarning'] ? ' - ' . $n['summaryText'] : ''), 'time' => $this->took($t), 'live' => $live];
        }
        if ($step === 2) {
            $html = (string)($this->html[1] ?? '');
            $cm = $this->callLoadCodeMelli($nid);
            $base = array_merge([
                'editform:noeShakhs' => '0', 'editform:noeTarafGharardad' => '', 'editform:isBimehgozar' => 'true', 'editform:isTarafAsli' => 'true', 'editform:isPardakhtKonandeh' => 'true',
                'shakhsHaghighi_codeMelli_hidden' => $nid, 'shakhsHaghighi_codeMelli' => $nid, 'shakhsHaghighi_melliat_hidden' => '29', 'shakhsHaghighi_melliat' => '29',
                'shakhsHaghighi_vaziatHayat_hidden' => '0', 'shakhsHaghighi_vaziatHayat' => '0', 'shakhsHaghighi_goroohHesabdari_hidden' => '1', 'shakhsHaghighi_goroohHesabdari' => '1',
                'editform:shakhsHaghighi_tarikhFot' => '//', 'shakhsHaghighi_shoghl' => null,
            ], $cm['fields'], ['editform:shakhsHaghighi_tarikhTavalod' => $ymd . ';undefined', 'editform:shakhsHaghighi_tarikhTavalod_y' => $y,
                               'editform:shakhsHaghighi_tarikhTavalod_m' => $mo, 'editform:shakhsHaghighi_tarikhTavalod_d' => $d]);
            $this->state['cm'] = $cm; $this->state['base'] = $base; $this->state['lastStep'] = 2;
            $this->saveStepHtml(2, $html);
            $live = $this->inspectLivePage($html, $nid);
            if ($cm['found']) { $live['person'] = $cm['person']; $live['shakhsId'] = $cm['person']['id']; }
            $live['codeMelli'] = $nid; $live['birthYMD'] = $ymd; $live['birthHidden'] = $ymd . ';undefined';
            $p = $cm['person'];
            return ['ok' => true, 'step' => 2, 'status' => $cm['found'] ? 'ok' : 'warn', 'title' => '۲. تنظیم شخص حقیقی + تیک‌ها + لود کد ملی (loadCodeMelli)',
                    'detail' => $cm['found'] ? "کد ملی در دیتابیس پاسارگاد یافت شد: {$p['name']} {$p['family']} (ID: {$p['id']} - تولد ثبت‌شده: {$cm['dbBirthYmd']}) -> تاریخ تولد روی {$ymd} تنظیم شد"
                                            : "کد ملی سابقه قبلی در پاسارگاد نداشت؛ تاریخ تولد {$ymd} برای استعلام از ثبت احوال تنظیم شد",
                    'time' => $this->took($t), 'live' => $live];
        }
        if ($step === 3) {
            $html = (string)($this->html[2] ?? '');
            $cm = $this->state['cm'] ?? ['found' => false, 'person' => null, 'dbBirthYmd' => ''];
            $base = $this->state['base'] ?? [];
            $r = $this->submit($this->formUrl, $html, $base, 'editform:estelamShakhsHaghighi', $this->listUrl);
            if ($this->loggedOut($r['body'])) return ['ok' => false, 'error' => 'need_login'];
            $html = $r['body']; $person = $this->readPerson($html); $n = $this->parseNotices($html);
            // فقط هشدارِ زرد و نام خالی: یک بار دیگر «استعلام» زده می‌شود
            if ((!$person['name'] || !$person['family']) && !$cm['found'] && $n['onlyWarning']) {
                $r2 = $this->submit($this->formUrl, $html, $base, 'editform:estelamShakhsHaghighi', $this->formUrl);
                if (!$this->loggedOut($r2['body'])) { $html = $r2['body']; $person = $this->readPerson($html); $n = $this->parseNotices($html); }
            }
            $this->saveStepHtml(3, $html);
            if ($person['name'] !== '' && $person['family'] !== '') {
                $this->state['base'] = array_merge($base, $this->extractPersonFields($html, $nid, $ymd, $y, $mo, $d));
                if (empty($person['id']) && !empty($cm['person']['id'])) $person['id'] = $cm['person']['id'];
                $this->state['person'] = $person;
                $we = $n['hasWarning'] || $n['hasError'];
                return ['ok' => true, 'step' => 3, 'status' => $we ? 'warn' : 'ok', 'title' => '۳. استعلام شخص حقیقی',
                        'detail' => "مشخصات شخص در فرم آماده است: {$person['name']} {$person['family']} (فرزند {$person['fatherName']})" . ($we ? " - ادامه با وجود پیام سامانه ({$n['summaryText']})" : ''),
                        'time' => $this->took($t), 'live' => $this->inspectLivePage($html, $nid), 'person' => $person];
            }
            if (!empty($cm['found'])) {
                if ($cm['dbBirthYmd'] !== '' && $cm['dbBirthYmd'] !== $ymd) {
                    $msg = "تاریخ تولد وارد شده ({$ymd}) با تاریخ تولد ثبت‌شده‌ی این کد ملی مطابقت ندارد";
                    return ['ok' => false, 'step' => 3, 'status' => 'bad', 'title' => '۳. استعلام شخص حقیقی', 'detail' => $msg . " (ثبت‌شده: {$cm['dbBirthYmd']})", 'message' => $msg, 'time' => $this->took($t), 'live' => $this->inspectLivePage($html, $nid), 'birth_mismatch' => true];
                }
                $person = $cm['person']; $this->state['person'] = $person;
                $live = $this->inspectLivePage($html, $nid); $live['person'] = $person;
                return ['ok' => true, 'step' => 3, 'status' => 'warn', 'title' => '۳. استعلام شخص حقیقی (ادامه با سابقه پاسارگاد)',
                        'detail' => "مشخصات از سابقه پاسارگاد تایید شد ({$person['name']} {$person['family']}) و از هشدار/خطای ثبت احوال عبور کرد ({$n['summaryText']})", 'time' => $this->took($t), 'live' => $live, 'person' => $person];
            }
            $em = $n['errors'] ? implode(' - ', $n['errors']) : ($n['warnings'] ? implode(' - ', $n['warnings']) : 'اطلاعات شخص اشتباه است');
            return ['ok' => false, 'step' => 3, 'status' => 'bad', 'title' => '۳. استعلام شخص حقیقی', 'detail' => $em, 'message' => $em, 'time' => $this->took($t), 'live' => $this->inspectLivePage($html, $nid)];
        }
        if ($step === 4) {
            $html = (string)($this->html[3] ?? '');
            $base = $this->state['base'] ?? [];
            $r = $this->submit($this->formUrl, $html, $base, 'editform:addTotarafGharardadList', $this->formUrl);
            if ($this->loggedOut($r['body'])) return ['ok' => false, 'error' => 'need_login'];
            $html = $r['body']; $taraf = $this->inspectTarafTable($html, $nid);
            if (!$taraf['added']) {
                $r2 = $this->submit($this->formUrl, $html, $base, 'editform:addTotarafGharardadList', $this->formUrl);
                if (!$this->loggedOut($r2['body'])) { $html = $r2['body']; $taraf = $this->inspectTarafTable($html, $nid); }
            }
            // خطای ثبت احوال فرم را ریست کرده بود: فرمِ تازه و افزودنِ مستقیم
            if (!$taraf['added'] && !empty($this->state['cm']['found'])) {
                $list = $this->http('GET', $this->listUrl);
                if (!$this->loggedOut($list['body'])) {
                    $rc = $this->submit($this->listUrl, $list['body'], [], 'exampleform:create', $this->listUrl);
                    if (!$this->loggedOut($rc['body']) && $this->hasField($rc['body'], 'shakhsHaghighi_codeMelli')) {
                        $ra = $this->submit($this->formUrl, $rc['body'], $base, 'editform:addTotarafGharardadList', $this->listUrl);
                        if (!$this->loggedOut($ra['body'])) {
                            $html = $ra['body']; $taraf = $this->inspectTarafTable($html, $nid);
                            if (!$taraf['added']) { $rb = $this->submit($this->formUrl, $html, $base, 'editform:addTotarafGharardadList', $this->formUrl); if (!$this->loggedOut($rb['body'])) { $html = $rb['body']; $taraf = $this->inspectTarafTable($html, $nid); } }
                        }
                    }
                }
            }
            $this->saveStepHtml(4, $html);
            $live = $this->inspectLivePage($html, $nid); $n = $this->parseNotices($html);
            if ($taraf['added'] || $n['onlyWarning'])
                return ['ok' => true, 'step' => 4, 'status' => ($taraf['added'] && !$n['hasWarning']) ? 'ok' : 'warn', 'title' => '۴. افزودن به جدول «لیست طرف قرارداد»',
                        'detail' => ($taraf['added'] ? "ردیف بیمه‌گذار اصلی با موفقیت به جدول اضافه شد: «{$taraf['text']}»" : 'هشدار زرد دریافت شد؛ ادامه به گام استعلام کد پستی') . ($n['hasWarning'] ? " ({$n['summaryText']})" : ''),
                        'time' => $this->took($t), 'live' => $live];
            $err = 'بیمه‌گذار به جدول طرف قرارداد اضافه نشد (' . $n['summaryText'] . ')';
            return ['ok' => false, 'step' => 4, 'status' => 'bad', 'title' => '۴. افزودن به جدول «لیست طرف قرارداد»', 'detail' => $err, 'message' => $err, 'time' => $this->took($t), 'live' => $live];
        }
        if ($step === 5) {
            $html = (string)($this->html[4] ?? '');
            $btn = $this->findPostalButton($html);
            $ov = ['editform:noeShakhs' => '0', 'editform:isBimehgozar' => 'true', 'editform:isTarafAsli' => 'true', 'editform:isPardakhtKonandeh' => 'true', 'moshakhasatOmumi_codePosti' => $postal];
            $r = $this->submit($this->formUrl, $html, $ov, $btn, $this->formUrl);
            if ($this->loggedOut($r['body'])) return ['ok' => false, 'error' => 'need_login'];
            $html = $r['body']; $n = $this->parseNotices($html);
            if ($n['onlyWarning']) {
                $r2 = $this->submit($this->formUrl, $html, $ov, $this->findPostalButton($html), $this->formUrl);
                if (!$this->loggedOut($r2['body'])) { $n2 = $this->parseNotices($r2['body']); if ($n2['hasSuccess'] || $n2['hasError'] || $n2['hasWarning']) { $html = $r2['body']; $n = $n2; } }
            }
            $this->saveStepHtml(5, $html);
            $valid = null; $msg = '';
            if ($n['hasSuccess']) { $valid = true; $msg = implode(' - ', $n['messages']) . ($n['hasWarning'] ? ' (هشدار: ' . implode(' - ', $n['warnings']) . ')' : ''); }
            elseif ($n['hasError']) { $valid = false; $msg = implode(' - ', $n['errors']); }
            elseif ($n['hasWarning']) {
                $w = implode(' - ', $n['warnings']); $msg = $w;
                if (strpos($w, 'ثبت شده') !== false && strpos($w, 'نشده') === false) $valid = true;
                elseif (strpos($w, 'نشده') !== false || strpos($w, 'ندارد') !== false || strpos($w, 'نامعتبر') !== false) $valid = false;
            }
            $live = $this->inspectLivePage($html, $nid);
            return ['ok' => true, 'step' => 5, 'status' => $valid === true ? 'ok' : ($valid === false ? 'bad' : 'warn'), 'title' => '۵. استعلام کد پستی از املاک و اسکان',
                    'detail' => "دکمه {$btn} برای کد پستی {$postal} زده شد - پاسخ سامانه: " . $n['summaryText'], 'time' => $this->took($t), 'live' => $live,
                    'person' => $this->state['person'] ?? $live['person'],
                    'postal' => ['valid' => $valid, 'message' => $msg !== '' ? $msg : ($valid === true ? 'کد پستی در املاک و اسکان ثبت شده است' : ($valid === false ? 'کد پستی در املاک و اسکان ثبت نشده است' : 'پاسخی از سامانه دریافت نشد'))]];
        }
        return ['ok' => false, 'message' => 'گام نامعتبر است'];
    }

    private function extractPersonFields($html, $nid, $ymd, $y, $m, $d)
    {
        $f = $this->fields($html, 'editform');
        $g = function ($k, $def = '') use ($f) { if (isset($f[$k]) && $f[$k] !== '') return $f[$k]; if (isset($f[$k . '_hidden']) && $f[$k . '_hidden'] !== '') return $f[$k . '_hidden']; return $def; };
        $naam = $g('shakhsHaghighi_naam'); $fam = $g('shakhsHaghighi_naamKhanevadegi'); $pedar = $g('shakhsHaghighi_naamPedar'); $sh = $g('shakhsHaghighi_shomarehShenasnameh', '0'); $j = $g('shakhsHaghighi_jensiat', '0');
        return ['editform:noeShakhs' => '0', 'editform:isBimehgozar' => 'true', 'editform:isTarafAsli' => 'true', 'editform:isPardakhtKonandeh' => 'true',
                'shakhsHaghighi_ID' => $g('shakhsHaghighi_ID'), 'shakhsHaghighi_codeMelli_hidden' => $nid, 'shakhsHaghighi_codeMelli' => $nid,
                'shakhsHaghighi_naam_hidden' => $naam, 'shakhsHaghighi_naam' => $naam, 'shakhsHaghighi_naamKhanevadegi_hidden' => $fam, 'shakhsHaghighi_naamKhanevadegi' => $fam,
                'shakhsHaghighi_naamPedar_hidden' => $pedar, 'shakhsHaghighi_naamPedar' => $pedar, 'shakhsHaghighi_shomarehShenasnameh_hidden' => $sh, 'shakhsHaghighi_shomarehShenasnameh' => $sh,
                'shakhsHaghighi_jensiat_hidden' => $j, 'shakhsHaghighi_jensiat' => $j, 'shakhsHaghighi_melliat_hidden' => '29', 'shakhsHaghighi_melliat' => '29',
                'shakhsHaghighi_vaziatHayat_hidden' => '0', 'shakhsHaghighi_vaziatHayat' => '0', 'shakhsHaghighi_goroohHesabdari_hidden' => '1', 'shakhsHaghighi_goroohHesabdari' => '1',
                'editform:shakhsHaghighi_tarikhTavalod' => $ymd . ';undefined', 'editform:shakhsHaghighi_tarikhTavalod_y' => $y, 'editform:shakhsHaghighi_tarikhTavalod_m' => $m, 'editform:shakhsHaghighi_tarikhTavalod_d' => $d];
    }

    private function findPostalButton($html)
    {
        $pos = strpos($html, 'name="moshakhasatOmumi_codePosti"');
        if ($pos === false) $pos = strpos($html, "name='moshakhasatOmumi_codePosti'");
        if ($pos !== false && preg_match('/(editform:j_id_jsp_\d+_\d+pc\d+)/i', substr($html, $pos, 800), $bm)) return $bm[1];
        return 'editform:j_id_jsp_348538676_37pc11';
    }

    private function readPerson($html)
    {
        $f = $this->fields($html, 'editform');
        $get = function ($b) use ($f, $html) {
            foreach ([$b, $b . '_hidden', 'editform:' . $b, 'editform:' . $b . '_hidden'] as $k) if (isset($f[$k]) && trim($f[$k]) !== '') return trim($f[$k]);
            foreach ([$b, $b . '_hidden'] as $fld) if (preg_match('/<input\b[^>]*\bname=["\']' . preg_quote($fld, '/') . '["\'][^>]*>/is', $html, $tm) && preg_match('/\bvalue=["\']([^"\']*)["\']/i', $tm[0], $vm)) { $v = trim($this->decodeHtml($vm[1])); if ($v !== '') return $v; }
            return '';
        };
        $g = $get('shakhsHaghighi_jensiat');
        return ['id' => $get('shakhsHaghighi_ID'), 'name' => $get('shakhsHaghighi_naam'), 'family' => $get('shakhsHaghighi_naamKhanevadegi'), 'fatherName' => $get('shakhsHaghighi_naamPedar'),
                'gender' => $g === '0' ? 'مرد' : ($g === '1' ? 'زن' : '')];
    }
}

// =====================================================================
//  قفلِ نشستِ مشترک: در هر لحظه فقط یک استعلام با سامانه کار می‌کند
// =====================================================================
function inq_lock($waitSec = 150, $onWait = null) {
    $fh = @fopen(inq_dir() . '/session.lock', 'c');
    if (!$fh) return null;
    $t0 = time(); $told = false;
    while (!flock($fh, LOCK_EX | LOCK_NB)) {
        if (time() - $t0 >= $waitSec) { fclose($fh); return null; }
        if (!$told && $onWait) { $onWait(); $told = true; }
        usleep(700000);
    }
    return $fh;
}
function inq_unlock($fh) { if ($fh) { flock($fh, LOCK_UN); fclose($fh); } }

// وضعیتِ اتصال (با کشِ کوتاه تا هر صفحه یک درخواست به پاسارگاد نزند)
function inq_px_status($pdo, $fresh = false) {
    $f = inq_dir() . '/status.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (inq_captcha_pending()) return 'logged_out';
    if (!$fresh && is_array($c) && time() - intval($c['t'] ?? 0) < 60) return $c['s'];
    $lk = inq_lock(5);
    if (!$lk) return is_array($c) ? $c['s'] : 'busy';
    try { $s = (new InqPasargad())->status(); } finally { inq_unlock($lk); }
    @file_put_contents($f, json_encode(['t' => time(), 's' => $s]));
    return $s;
}
function inq_px_mark($s) { @file_put_contents(inq_dir() . '/status.json', json_encode(['t' => time(), 's' => $s])); }

// آخرین کار با سامانه (استعلام یا ورود): cron فقط تا «inq_keep_minutes» دقیقه بعد از آن نشست را باز نگه می‌دارد
function inq_touch_activity() { @file_put_contents(inq_dir() . '/activity.txt', (string)time()); }
function inq_last_activity() { $f = inq_dir() . '/activity.txt'; return is_file($f) ? intval(@file_get_contents($f)) : 0; }
function inq_notify_logout($pdo) { /* هر کاربر خودش کپچا را می‌زند؛ پیامی به مدیر لازم نیست */ }

// ---- کپچا برای همه‌ی کاربران: نام کاربری و رمز از تنظیمات (مدیر کل)، فقط متنِ تصویر را کاربر می‌زند ----
// کپچای سامانه به نشست بسته است و با هر بار باز شدنِ صفحه‌ی ورود عوض می‌شود؛ پس از نشان دادنِ تصویر تا زدنِ کد
// هیچ درخواستی (بررسیِ وضعیت، keepalive، استعلام) نباید به سامانه برود، وگرنه کدِ کاربر همیشه «اشتباه» می‌شود.
const INQ_CAPTCHA_TTL = 300;
function inq_captcha_pending() {
    $f = inq_dir() . '/captcha.json';
    if (!is_file($f)) return false;
    $c = json_decode((string)@file_get_contents($f), true);
    return is_array($c) && !empty($c['image']) && time() - intval($c['t'] ?? 0) < INQ_CAPTCHA_TTL;
}
function inq_captcha_save($image) {
    if ($image) @file_put_contents(inq_dir() . '/captcha.json', json_encode(['t' => time(), 'image' => $image]));
    inq_px_mark('logged_out');
}
// همه‌ی کسانی که هم‌زمان به کپچا رسیده‌اند همان یک تصویر را می‌بینند (کپچای سامانه به نشستِ مشترک بسته است)
function inq_captcha_get($pdo, $refresh = false) {
    $f = inq_dir() . '/captcha.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!$refresh && is_array($c) && time() - intval($c['t'] ?? 0) < INQ_CAPTCHA_TTL && !empty($c['image'])) return ['ok' => true, 'image' => $c['image']];
    $lk = inq_lock(25);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه مشغولِ استعلامِ دیگری است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    try { $r = (new InqPasargad())->getCaptcha(); } finally { inq_unlock($lk); }
    if (!empty($r['loggedIn'])) { inq_px_mark('logged_in'); @unlink($f); return ['ok' => true, 'logged_in' => true]; }
    if (!empty($r['image'])) inq_captcha_save($r['image']);
    return $r;
}
function inq_captcha_login($pdo, $text) {
    $cr = inq_creds($pdo);
    if ($cr['user'] === '' || $cr['pass'] === '') return ['ok' => false, 'error' => 'نام کاربری و رمزِ سامانه‌ی پاسارگاد هنوز در «استعلامات ← تنظیمات» وارد نشده؛ به مدیر کل اطلاع دهید.'];
    $text = trim(strtr((string)$text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
    if ($text === '') return ['ok' => false, 'error' => 'متنِ تصویرِ امنیتی را بنویسید.'];
    $lk = inq_lock(25);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه مشغولِ استعلامِ دیگری است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    // کسِ دیگری همین چند لحظه پیش وارد شده بود؟ (بدونِ درخواست به سامانه؛ بررسیِ وضعیت کپچا را عوض می‌کرد)
    $sf = inq_dir() . '/status.json';
    $sc = is_file($sf) ? json_decode((string)@file_get_contents($sf), true) : null;
    if (!is_file(inq_dir() . '/captcha.json') && is_array($sc) && ($sc['s'] ?? '') === 'logged_in' && time() - intval($sc['t'] ?? 0) < 120) { inq_unlock($lk); return ['ok' => true]; }
    try { $r = (new InqPasargad())->login($cr['user'], $cr['pass'], $text); } finally { inq_unlock($lk); }
    if ($r['ok']) { @unlink(inq_dir() . '/captcha.json'); inq_px_mark('logged_in'); inq_touch_activity(); return ['ok' => true]; }
    if (!empty($r['image'])) { inq_captcha_save($r['image']); $img = $r['image']; }
    else { @unlink(inq_dir() . '/captcha.json'); $n = inq_captcha_get($pdo, true); $img = $n['image'] ?? null; }
    return ['ok' => false, 'error' => $r['error'] ?? 'کد اشتباه بود؛ دوباره بزنید.', 'image' => $img];
}

// =====================================================================
//  ثبت، صف، اجرا و تاریخچه
// =====================================================================
function inq_actor_from_session() {
    if (session_name() === 'bime_company_portal') return ['COMPANY', intval($_SESSION['company_user_id'] ?? 0), (string)($_SESSION['company_user_full_name'] ?? '')];
    return ['STAFF', intval($_SESSION['user_id'] ?? 0), (string)($_SESSION['full_name'] ?? '')];
}

// ورودی => [ok, fields] یا [ok=false, error]
function inq_validate($kind, array $in) {
    if (!isset(INQ_KINDS[$kind])) return ['ok' => false, 'error' => 'نوعِ استعلام نامعتبر است.'];
    if ($kind === 'legal') {
        $c = inq_digits($in['company_nid'] ?? ($in['national_id'] ?? ''));
        if (strlen($c) !== 11) return ['ok' => false, 'error' => 'شناسه‌ی ملیِ شخصِ حقوقی باید ۱۱ رقم باشد.', 'field' => 'national_id'];
        return ['ok' => true, 'national_id' => $c, 'birth' => null, 'postal' => null];
    }
    $nid = inq_norm_nid($in['national_id'] ?? '');
    if (!inq_nid_ok($nid)) return ['ok' => false, 'error' => 'کد ملی نامعتبر است.', 'field' => 'national_id'];
    $birth = inq_norm_birth($in['birth'] ?? '');
    if (!$birth) return ['ok' => false, 'error' => 'تاریخ تولد نامعتبر است (مثل ۱۳۷۰/۰۵/۱۲).', 'field' => 'birth'];
    $postal = inq_digits($in['postal'] ?? '');
    if ($kind !== 'person' && strlen($postal) !== 10) return ['ok' => false, 'error' => 'کد پستی باید دقیقاً ۱۰ رقم باشد.', 'field' => 'postal'];
    return ['ok' => true, 'national_id' => $nid, 'birth' => $birth, 'postal' => $kind === 'person' ? null : $postal];
}

// نتیجه‌ی تازه‌ی همین استعلام (تا چند روز) برای استفاده‌ی دوباره
function inq_find_cached($pdo, $kind, $nid, $birth, $postal) {
    $days = max(0, intval(inq_get_setting($pdo, 'inq_cache_days')));
    if ($days <= 0) return null;
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    if ($kind === 'legal') {
        $st = $pdo->prepare("SELECT * FROM inq_requests WHERE kind = 'legal' AND national_id = ? AND status = 'OK' AND finished_at >= ? ORDER BY id DESC LIMIT 1");
        $st->execute([$nid, $since]); return $st->fetch() ?: null;
    }
    // شخص: همان کد ملی و تاریخ تولد، از هر استعلامِ موفقی که مشخصات را داده
    $pRow = null;
    if ($kind !== 'postal' || true) {
        $st = $pdo->prepare("SELECT * FROM inq_requests WHERE kind IN ('person', 'person_postal', 'postal') AND national_id = ? AND birth = ? AND status IN ('OK', 'WARN') AND person_name IS NOT NULL AND finished_at >= ? ORDER BY id DESC LIMIT 1");
        $st->execute([$nid, $birth, $since]); $pRow = $st->fetch() ?: null;
    }
    if ($kind === 'person') return $pRow;
    // کد پستی: نتیجه‌ی قطعیِ همان کد پستی (به شخص وابسته نیست)
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE kind IN ('person_postal', 'postal') AND postal = ? AND postal_valid IS NOT NULL AND finished_at >= ? ORDER BY id DESC LIMIT 1");
    $st->execute([$postal, $since]); $zRow = $st->fetch() ?: null;
    if (!$zRow) return null;
    if ($kind === 'postal') return $zRow;
    return $pRow ? ['person' => $pRow, 'postal' => $zRow] : null;
}

// ساختِ استعلام (اگر نتیجه‌ی تازه هست، همان‌جا تمام می‌شود). خروجی: id، cached
function inq_create($pdo, $kind, array $in, $source = 'panel', array $actor = null, $batchId = null, $rowNo = null, $useCache = true) {
    inq_ensure($pdo);
    $v = inq_validate($kind, $in);
    if (!$v['ok']) return $v;
    $actor = $actor ?: inq_actor_from_session();
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO inq_requests (kind, national_id, birth, postal, status, source, batch_id, row_no, user_type, user_id, user_name, created_at) VALUES (?, ?, ?, ?, 'QUEUED', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$kind, $v['national_id'], $v['birth'], $v['postal'], $source, $batchId, $rowNo, $actor[0], $actor[1] ?: null, mb_substr((string)$actor[2], 0, 190), $now]);
    $id = intval($pdo->lastInsertId());
    $c = $useCache ? inq_find_cached($pdo, $kind, $v['national_id'], $v['birth'], $v['postal']) : null;
    if ($c) {
        $p = isset($c['person']) ? $c['person'] : $c; $z = isset($c['postal']) ? $c['postal'] : $c;
        $res = json_decode((string)($p['result_json'] ?? ''), true) ?: [];
        if ($kind === 'person_postal') $res['postal'] = (json_decode((string)$z['result_json'], true) ?: [])['postal'] ?? ['valid' => $z['postal_valid'] === null ? null : (bool)$z['postal_valid'], 'message' => $z['postal_text']];
        $status = $kind === 'person' || $kind === 'legal' ? 'OK' : ($z['postal_valid'] === null ? 'WARN' : ((int)$z['postal_valid'] === 1 ? 'OK' : 'FAIL'));
        $pdo->prepare("UPDATE inq_requests SET status = ?, notice = ?, person_name = ?, father_name = ?, postal_valid = ?, postal_text = ?, result_json = ?, cached_from = ?, started_at = ?, finished_at = ?, ms = 0 WHERE id = ?")
            ->execute([$status, 'از نتیجه‌ی استعلامِ قبلی (' . jdate_short($p['finished_at'] ?? $z['finished_at']) . ')', $kind === 'postal' ? null : ($p['person_name'] ?? null), $kind === 'postal' ? null : ($p['father_name'] ?? null),
                       $kind === 'person' || $kind === 'legal' ? null : $z['postal_valid'], $kind === 'person' || $kind === 'legal' ? null : $z['postal_text'],
                       json_encode($res, JSON_UNESCAPED_UNICODE), intval($p['id'] ?? $z['id']), $now, $now, $id]);
        return ['ok' => true, 'id' => $id, 'cached' => true];
    }
    return ['ok' => true, 'id' => $id, 'cached' => false];
}
function jdate_short($dt) {
    if (!$dt) return '';
    if (function_exists('jalali_from_gregorian_ts_dotted')) return str_replace('.', '/', jalali_from_gregorian_ts_dotted(strtotime($dt)));
    return substr((string)$dt, 0, 10);
}

function inq_timeline_push($pdo, $id, array &$tl, array $item, $notice = null) {
    $tl[] = $item;
    $pdo->prepare("UPDATE inq_requests SET timeline_json = ?" . ($notice !== null ? ", notice = ?" : '') . " WHERE id = ?")
        ->execute($notice !== null ? [json_encode($tl, JSON_UNESCAPED_UNICODE), mb_substr($notice, 0, 600), $id] : [json_encode($tl, JSON_UNESCAPED_UNICODE), $id]);
}

// اجرای یک استعلامِ در صف (برای این درخواست؛ هم‌زمان با این، مرورگر با inq_get پیشرفت را می‌خواند)
function inq_execute($pdo, $id) {
    inq_ensure($pdo);
    @set_time_limit(300); @ignore_user_abort(true);
    $st = $pdo->prepare("UPDATE inq_requests SET status = 'RUNNING', started_at = NOW() WHERE id = ? AND status = 'QUEUED'");
    $st->execute([$id]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'این استعلام در صف نیست (قبلاً اجرا شده).'];
    $row = $pdo->query("SELECT * FROM inq_requests WHERE id = " . intval($id))->fetch();
    $t0 = microtime(true);
    $finish = function ($status, $notice, array $res = [], $person = null, $postal = null) use ($pdo, $id, $t0) {
        $pdo->prepare("UPDATE inq_requests SET status = ?, notice = ?, result_json = ?, person_name = ?, father_name = ?, postal_valid = ?, postal_text = ?, finished_at = NOW(), ms = ? WHERE id = ?")
            ->execute([$status, mb_substr((string)$notice, 0, 600), json_encode($res, JSON_UNESCAPED_UNICODE),
                       $person ? mb_substr(trim(($person['name'] ?? '') . ' ' . ($person['family'] ?? '')), 0, 190) ?: null : null, $person ? (($person['fatherName'] ?? '') ?: null) : null,
                       $postal ? ($postal['valid'] === null ? null : ($postal['valid'] ? 1 : 0)) : null, $postal ? mb_substr((string)$postal['message'], 0, 600) : null,
                       (int)round((microtime(true) - $t0) * 1000), $id]);
        return ['ok' => true, 'status' => $status];
    };
    if (inq_get_setting($pdo, 'inq_enabled') !== '1') return $finish('FAIL', 'استعلام‌ها در تنظیمات خاموش است.');

    if ($row['kind'] === 'legal') {
        require_once __DIR__ . '/_apis.php';
        if (!sapi_ready($pdo, 'sapi_company')) return $finish('FAIL', 'کلیدِ s.api.ir در «تنظیمات ← کلیدهای API» وارد نشده است.');
        $r = sapi_company_info($pdo, $row['national_id']);
        if (!$r['ok']) return $finish('FAIL', $r['error'] ?? 'خطا', ['raw' => $r['raw'] ?? '']);
        $co = $r['company'];
        return $finish('OK', ($co['name'] ?: 'شرکت') . ($co['active'] === false ? ' (غیرفعال)' : ''), ['company' => $co], ['name' => $co['name'], 'family' => '']);
    }

    if (!function_exists('curl_init')) return $finish('FAIL', 'افزونه‌ی curl روی سرور فعال نیست.');
    $needCaptcha = function () use ($pdo, $id) {
        $pdo->prepare("UPDATE inq_requests SET status = 'QUEUED', notice = 'برای ادامه، کدِ تصویرِ امنیتی (کپچا) لازم است.', timeline_json = NULL, started_at = NULL WHERE id = ?")->execute([$id]);
        return ['ok' => false, 'need_captcha' => true];
    };
    if (inq_captcha_pending()) return $needCaptcha();
    $jobDir = inq_dir('jobs/' . $id);
    $tl = [];
    $lk = inq_lock(90, function () use ($pdo, $id) { $pdo->prepare("UPDATE inq_requests SET notice = 'در صف (استعلامِ دیگری در حالِ اجراست)…' WHERE id = ?")->execute([$id]); });
    if (!$lk) return $finish('FAIL', 'سامانه مشغولِ استعلام‌های دیگر بود؛ کمی بعد دوباره امتحان کنید.');
    try {
        [$y, $m, $d] = array_map('intval', explode('/', $row['birth']));
        $birth = ['y' => $y, 'm' => $m, 'd' => $d];
        $max = $row['kind'] === 'person' ? 3 : 5;
        $wait = max(0, min(9, intval(inq_get_setting($pdo, 'inq_wait_sec'))));
        $px = new InqPasargad($jobDir);
        $last = null; $person = null;
        for ($s = 1; $s <= $max; $s++) {
            $pdo->prepare("UPDATE inq_requests SET notice = ? WHERE id = ?")->execute(['گام ' . $s . ' از ' . $max . '…', $id]);
            $r = $px->runStep($s, $row['national_id'], $birth, (string)$row['postal']);
            if (($r['error'] ?? '') === 'need_login') {
                // نشست بسته است: تازه‌ترین کپچا همین‌جا گرفته می‌شود و استعلام در صف می‌ماند تا کاربر کد را بزند
                $c = $px->getCaptcha();
                if (!empty($c['image'])) inq_captcha_save($c['image']); else inq_px_mark('logged_out');
                return $needCaptcha();
            }
            if (!empty($r['ok'])) inq_touch_activity();
            inq_timeline_push($pdo, $id, $tl, ['step' => $s, 'status' => $r['status'] ?? ($r['ok'] ? 'ok' : 'bad'), 'title' => $r['title'] ?? ('گام ' . $s), 'detail' => $r['detail'] ?? '', 'time' => $r['time'] ?? '',
                                              'live' => $r['live'] ?? null]);
            if (!empty($r['person']) && !empty($r['person']['name'])) $person = $r['person'];
            if (empty($r['ok'])) {
                if (($r['error'] ?? '') === 'down') inq_px_mark('down');
                $msg = $r['message'] ?? ($r['detail'] ?? 'خطا در اجرای مرحله');
                return $finish('FAIL', $msg, ['timeline' => $tl, 'failed_step' => $s, 'birth_mismatch' => !empty($r['birth_mismatch'])], $person);
            }
            $last = $r;
            if ($s < $max && $wait > 0) sleep($wait);
        }
        inq_px_mark('logged_in');
        $res = ['timeline' => $tl, 'person' => $person];
        if ($row['kind'] === 'person') return $finish($person ? 'OK' : 'WARN', $person ? trim($person['name'] . ' ' . $person['family']) : 'مشخصات خوانده نشد', $res, $person);
        $z = $last['postal'] ?? ['valid' => null, 'message' => 'پاسخی دریافت نشد'];
        $res['postal'] = $z;
        $st2 = $z['valid'] === true ? 'OK' : ($z['valid'] === false ? 'FAIL' : 'WARN');
        return $finish($st2, $z['message'], $res, $person, $z);
    } catch (Throwable $e) {
        return $finish('FAIL', 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 200), ['timeline' => $tl]);
    } finally {
        inq_unlock($lk);
    }
}

// استعلام‌هایی که بیشتر از ۱۰ دقیقه «در حال اجرا» مانده‌اند (قطعِ درخواست) دوباره به صف برمی‌گردند
function inq_requeue_stale($pdo) {
    try { $pdo->exec("UPDATE inq_requests SET status = 'QUEUED', notice = 'دوباره در صف' WHERE status = 'RUNNING' AND started_at < (NOW() - INTERVAL 10 MINUTE)"); } catch (Throwable $e) {}
}

// خروجیِ یک استعلام برای مرورگر؛ جزئیات (تایم‌لاین، صفحه‌ی گام‌ها، پیام‌های خامِ سامانه) فقط برای مدیر کل
function inq_out(array $r, $full = false) {
    $res = json_decode((string)($r['result_json'] ?? ''), true) ?: [];
    $tl = json_decode((string)($r['timeline_json'] ?? ''), true) ?: ($res['timeline'] ?? []);
    $p = $res['person'] ?? null;
    $o = ['id' => intval($r['id']), 'kind' => $r['kind'], 'kind_fa' => INQ_KINDS[$r['kind']] ?? $r['kind'], 'national_id' => $r['national_id'], 'birth' => $r['birth'], 'postal' => $r['postal'],
          'status' => $r['status'], 'status_fa' => INQ_ST_FA[$r['status']] ?? $r['status'], 'done' => in_array($r['status'], ['OK', 'WARN', 'FAIL'], true),
          'person' => $p ? ['name' => $p['name'] ?? '', 'family' => $p['family'] ?? '', 'father' => $p['fatherName'] ?? '', 'gender' => $p['gender'] ?? ''] : null,
          'person_name' => $r['person_name'], 'father_name' => $r['father_name'],
          'postal_valid' => $r['postal_valid'] === null ? null : (bool)$r['postal_valid'], 'postal_text' => $r['postal_text'], 'company' => $res['company'] ?? null,
          'notice' => $r['notice'], 'cached' => !empty($r['cached_from']), 'source' => $r['source'], 'user_name' => $r['user_name'], 'batch_id' => $r['batch_id'] ? intval($r['batch_id']) : null, 'row_no' => $r['row_no'],
          'created' => $r['created_at'], 'created_j' => jdate_short($r['created_at']) . ' ' . substr((string)$r['created_at'], 11, 5), 'ms' => $r['ms'] !== null ? intval($r['ms']) : null,
          'step' => count($tl), 'steps' => $r['kind'] === 'person' ? 3 : ($r['kind'] === 'legal' ? 1 : 5), 'need_login' => !empty($res['need_login']), 'birth_mismatch' => !empty($res['birth_mismatch'])];
    if ($full) {
        $o['timeline'] = $tl;
        $dir = __DIR__ . '/data/inq/jobs/' . intval($r['id']);
        $o['step_pages'] = [];
        for ($i = 1; $i <= 5; $i++) if (is_file($dir . '/step' . $i . '.html')) $o['step_pages'][] = $i;
        $o['company_raw'] = $res['raw'] ?? null;
    } else {
        // برای کاربرانِ دیگر فقط مرحله‌ی کلی (بدونِ متنِ سامانه)
        $o['timeline'] = array_map(function ($x) { return ['step' => $x['step'] ?? 0, 'status' => $x['status'] ?? '', 'title' => preg_replace('/\s*\(.*$/u', '', (string)($x['title'] ?? ''))]; }, $tl);
    }
    return $o;
}
function inq_get($pdo, $id) {
    inq_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE id = ?");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
// صفحه‌ی ذخیره‌شده‌ی یک گام (فقط مدیر کل؛ در iframeِ sandbox)
function inq_step_html($id, $n) {
    $f = __DIR__ . '/data/inq/jobs/' . intval($id) . '/step' . intval($n) . '.html';
    if (!is_file($f)) return '<h3 style="font-family:tahoma;direction:rtl">صفحه‌ی این گام ذخیره نشده است.</h3>';
    $html = (string)file_get_contents($f);
    return stripos($html, '<head>') !== false ? preg_replace('/<head>/i', '<head><base href="https://core.pasargadinsurance.ir/">', $html, 1) : $html;
}

// ---- استعلامِ گروهی ----
function inq_batch_create($pdo, $kind, $title, array $rows, $fileName = '') {
    inq_ensure($pdo);
    $actor = inq_actor_from_session();
    $pdo->prepare("INSERT INTO inq_batches (title, kind, file_name, total, status, user_id, user_name, created_at) VALUES (?, ?, ?, 0, 'RUNNING', ?, ?, NOW())")
        ->execute([mb_substr($title ?: 'استعلامِ گروهی', 0, 190), $kind, mb_substr($fileName, 0, 255), $actor[1] ?: null, mb_substr($actor[2], 0, 190)]);
    $bid = intval($pdo->lastInsertId());
    $ok = 0; $bad = [];
    foreach (array_values($rows) as $i => $r) {
        $c = inq_create($pdo, $kind, (array)$r, 'group', $actor, $bid, $i + 1);
        if ($c['ok']) $ok++; else $bad[] = ['row' => $i + 1, 'error' => $c['error']];
    }
    $pdo->prepare("UPDATE inq_batches SET total = ? WHERE id = ?")->execute([$ok, $bid]);
    if (!$ok) $pdo->prepare("UPDATE inq_batches SET status = 'DONE', finished_at = NOW() WHERE id = ?")->execute([$bid]);
    return ['ok' => true, 'batch_id' => $bid, 'queued' => $ok, 'invalid' => $bad];
}
// یک ردیفِ بعدیِ صف (از مرورگر یا cron)؛ خروجی: وضعیتِ گروه
function inq_batch_tick($pdo, $batchId = null) {
    inq_ensure($pdo);
    inq_requeue_stale($pdo);
    $w = "status = 'QUEUED' AND batch_id IS NOT NULL" . ($batchId ? " AND batch_id = " . intval($batchId) : '') . " AND batch_id IN (SELECT id FROM inq_batches WHERE status = 'RUNNING')";
    $id = $pdo->query("SELECT id FROM inq_requests WHERE $w ORDER BY id LIMIT 1")->fetchColumn();
    $ran = null;
    if ($id) {
        $kind = $pdo->query("SELECT kind FROM inq_requests WHERE id = " . intval($id))->fetchColumn();
        if ($kind !== 'legal' && inq_px_status($pdo) === 'logged_out') return ['ran' => null, 'need_captcha' => true, 'batch' => $batchId ? inq_batch_info($pdo, $batchId) : null];
        $r = inq_execute($pdo, intval($id));
        if (!empty($r['need_captcha'])) return ['ran' => null, 'need_captcha' => true, 'batch' => $batchId ? inq_batch_info($pdo, $batchId) : null];
        $ran = intval($id);
    }
    // گروه‌هایی که چیزی در صف ندارند تمام می‌شوند
    $pdo->exec("UPDATE inq_batches b SET status = 'DONE', finished_at = NOW() WHERE status = 'RUNNING' AND NOT EXISTS (SELECT 1 FROM inq_requests r WHERE r.batch_id = b.id AND r.status IN ('QUEUED', 'RUNNING'))");
    return ['ran' => $ran, 'batch' => $batchId ? inq_batch_info($pdo, $batchId) : null];
}
function inq_batch_info($pdo, $bid) {
    $b = $pdo->query("SELECT * FROM inq_batches WHERE id = " . intval($bid))->fetch();
    if (!$b) return null;
    $c = ['QUEUED' => 0, 'RUNNING' => 0, 'OK' => 0, 'WARN' => 0, 'FAIL' => 0];
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM inq_requests WHERE batch_id = " . intval($bid) . " GROUP BY status")->fetchAll() as $r) $c[$r['status']] = intval($r['n']);
    $done = $c['OK'] + $c['WARN'] + $c['FAIL'];
    return ['id' => intval($b['id']), 'title' => $b['title'], 'kind' => $b['kind'], 'kind_fa' => INQ_KINDS[$b['kind']] ?? $b['kind'], 'file_name' => $b['file_name'], 'status' => $b['status'],
            'total' => intval($b['total']), 'done' => $done, 'counts' => $c, 'user_name' => $b['user_name'], 'created_j' => jdate_short($b['created_at']) . ' ' . substr((string)$b['created_at'], 11, 5)];
}

// ستون‌های اکسلِ گروهی => فیلدها (با نامِ سرستون، ترتیب مهم نیست)
function inq_excel_rows($path, $kind) {
    if (!function_exists('fin_read_spreadsheet')) require_once __DIR__ . '/finance_core.php';
    $t = fin_read_spreadsheet($path);
    if (!$t) return ['ok' => false, 'error' => 'فایل خوانده نشد (xlsx یا csv).'];
    $norm = function ($h) { return preg_replace('/\s+/u', '', str_replace(['ي', 'ك', "\xE2\x80\x8C"], ['ی', 'ک', ''], mb_strtolower(trim((string)$h)))); };
    $map = ['national_id' => ['کدملی', 'کد ملی', 'شماره ملی', 'nationalid', 'nid', 'شناسه ملی', 'شناسه‌ملی', 'شناسهملی'], 'birth' => ['تاریختولد', 'تولد', 'birth', 'birthdate'],
            'postal' => ['کدپستی', 'کد پستی', 'postal', 'postalcode', 'zip'], 'name' => ['نام', 'نامونامخانوادگی', 'name']];
    $look = []; foreach ($map as $f => $ns) foreach ($ns as $n) $look[$norm($n)] = $f;
    $hdr = -1; $cols = [];
    foreach (array_slice($t, 0, 15, true) as $i => $row) {
        $found = [];
        foreach ((array)$row as $c => $cell) { $k = $norm($cell); if ($k !== '' && isset($look[$k])) $found[$c] = $look[$k]; }
        if (isset(array_flip($found)['national_id'])) { $hdr = $i; $cols = $found; break; }
    }
    if ($hdr < 0) {   // بدونِ سرستون: ستون‌های ۱ تا ۳ = کد ملی، تاریخ تولد، کد پستی
        $cols = $kind === 'legal' ? [0 => 'national_id'] : [0 => 'national_id', 1 => 'birth', 2 => 'postal'];
    }
    $out = [];
    foreach (array_slice($t, $hdr + 1) as $row) {
        $r = [];
        foreach ($cols as $c => $f) $r[$f] = trim((string)($row[$c] ?? ''));
        if (($r['national_id'] ?? '') === '') continue;
        if ($kind === 'legal') $r['company_nid'] = $r['national_id'];
        $out[] = $r;
        if (count($out) >= 2000) break;
    }
    return $out ? ['ok' => true, 'rows' => $out] : ['ok' => false, 'error' => 'هیچ ردیفی با کد ملی پیدا نشد.'];
}
