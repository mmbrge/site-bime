<?php
// فایل: api/_inquiry.php
// «استعلامات»: شخصِ حقیقی، شخصِ حقیقی + کد پستی، کد پستی (املاک و اسکان) از راهِ سامانه‌ی ریتونِ بیمه پاسارگاد،
// و شخصِ حقوقی: اول از فهرستِ اشخاصِ حقوقیِ همان سامانه (LOV)، اگر نتیجه نداد از s.api.ir (api/_apis.php).
// استعلامِ سنهاب (سوابقِ بیمه‌نامه) جداست: api/_sanhab.php (همین جدول، kind = sanhab). همان گام‌های ابزارِ PasargadFlow (ورود به فرمِ پیش‌نویس ← loadCodeMelli ←
// «استعلام شخص حقیقی» ← افزودن به «لیست طرف قرارداد» ← «استعلامِ کد پستی»)، با این تفاوت‌ها:
//   - هر استعلام یک ردیف در inq_requests (تاریخچه، نتیجه، تایم‌لاین)، و صفحه‌ی هر گام در پوشه‌ی خصوصیِ همان استعلام
//     (api/data/inq/jobs/{id}/stepN.html) فقط برای مدیر کل؛
//   - چون نشستِ پاسارگاد یکی است، در هر لحظه فقط یک استعلام اجرا می‌شود (قفل) و بقیه در صف می‌مانند؛
//   - نتیجه‌ی موفق تا چند روز (تنظیمات) دوباره استفاده می‌شود و سامانه دوباره صدا زده نمی‌شود؛
//   - نام کاربری و رمزِ سامانه رمزنگاری‌شده ذخیره می‌شود (کلید در فایلی خارج از پایگاه‌داده).
// جدول‌ها خودکار ساخته می‌شوند (inq_ensure).

const INQ_KINDS = ['person' => 'شخص حقیقی', 'person_postal' => 'شخص حقیقی + کد پستی', 'postal' => 'کد پستی', 'legal' => 'شخص حقوقی', 'sanhab' => 'سنهاب (سوابقِ بیمه‌نامه)'];
const INQ_ST_FA = ['QUEUED' => 'در صف', 'RUNNING' => 'در حال اجرا', 'OK' => 'موفق', 'WARN' => 'با هشدار', 'FAIL' => 'ناموفق'];
const INQ_DEFAULTS = ['inq_wait_sec' => '2', 'inq_cache_days' => '30', 'inq_postal_required' => '0', 'inq_miniapp_person' => '1', 'inq_miniapp_postal' => '1',
                      'inq_daily_limit' => '30', 'inq_enabled' => '1', 'inq_keep_minutes' => '60', 'inq_nid_daily_limit' => '2', 'inq_user_daily_limit' => '0', 'inq_postal_daily_limit' => '2',
                      'inq_legal_px' => '1', 'inq_sh_enabled' => '1', 'inq_sh_daily_limit' => '0'];

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
    private $listUrl, $formUrl, $lovInitUrl, $lovSearchUrl, $cookieFile, $jobDir;
    private $lastUrl = '';
    private $state = [];
    private $html = [];

    public function __construct($jobDir = '')
    {
        $this->listUrl = $this->core . '/cins/bimehshakhssalespishnevis/bimehShakhsSalesPishnevis.simplelist.html';
        $this->formUrl = $this->core . '/cins/bimehshakhssalespishnevis/bimehShakhsSalesPishnevis.simpleform.html';
        $this->lovInitUrl = $this->core . '/cins/shakhsHoghooghi.lov.html?shakhsInfoSelectable=true&moshakhasatInfoSelectable=true';
        $this->lovSearchUrl = $this->core . '/cins/shakhshoghooghi/shakhsHoghooghi.simplelov.html';
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
        return ['token' => $f['token'] ?? '—', 'noeShakhs' => $ns === '0' ? 'حقیقی (0)' : ($ns === '1' ? 'حقوقی (1)' : ($ns ?? '—')),
                'checkboxes' => ['isBimehgozar' => !empty($f['editform:isBimehgozar']), 'isTarafAsli' => !empty($f['editform:isTarafAsli']), 'isPardakhtKonandeh' => !empty($f['editform:isPardakhtKonandeh'])],
                'codeMelli' => !empty($f['shakhsHaghighi_codeMelli']) ? $f['shakhsHaghighi_codeMelli'] : (!empty($f['shakhsHoghooghi_shenasehMelli_hidden']) ? $f['shakhsHoghooghi_shenasehMelli_hidden'] : ($f['shakhsHaghighi_codeMelli_hidden'] ?? '—')),
                'shakhsId' => !empty($f['shakhsHaghighi_ID']) ? $f['shakhsHaghighi_ID'] : ($f['shakhsHoghooghi_ID'] ?? '—'),
                'birthHidden' => !empty($f['editform:shakhsHaghighi_tarikhTavalod']) ? $f['editform:shakhsHaghighi_tarikhTavalod'] : ($f['editform:shakhsHoghooghi_tarikhTasis'] ?? '—'),
                'birthYMD' => ($f['editform:shakhsHaghighi_tarikhTavalod_y'] ?? '') . '/' . ($f['editform:shakhsHaghighi_tarikhTavalod_m'] ?? '') . '/' . ($f['editform:shakhsHaghighi_tarikhTavalod_d'] ?? ''),
                'person' => $this->readPerson($html), 'tarafTable' => $this->inspectTarafTable($html, $nid),
                'codePostiInForm' => $f['moshakhasatOmumi_codePosti'] ?? '—', 'serverNotice' => $n['summaryText'], 'serverNoticeOk' => $n['hasSuccess'] ? true : ($n['hasError'] ? false : null)];
    }

    private function hasField($html, $name)
    {
        $q = preg_quote($name, '/');
        return (bool)(preg_match('/\b(name|id)=["\'](editform:|exampleform:)?' . $q . '["\']/i', $html));
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

    // =====================================================================
    //  شخصِ حقوقی (فهرستِ اشخاصِ حقوقیِ سامانه؛ همان گام‌های ابزارِ PasargadFlow) + گامِ ۵ِ اختیاری: کد پستی
    // =====================================================================
    private function operatorPrefix($opId)
    {
        $map = array(
            '1' => '0911', '2' => '0912', '3' => '0914', '4' => '0916', '5' => '0913',
            '6' => '0936', '7' => '0939', '8' => '0937', '9' => '0938', '10' => '0935',
            '11' => '0919', '12' => '0915', '13' => '0910', '14' => '0918', '15' => '0917',
            '16' => '0934', '17' => '0933', '18' => '0930', '19' => '0921', '20' => '0932',
            '21' => '0920', '22' => '0901', '42' => '0902', '62' => '0903', '63' => '0990',
            '82' => '0922', '102' => '0991', '122' => '0905', '142' => '0992', '162' => '0994',
            '182' => '0904', '222' => '0993', '242' => '0999', '262' => '0998', '282' => '0996',
            '302' => '0900', '322' => '0923', '342' => '0906',
        );
        $k = trim((string)$opId);
        return isset($map[$k]) ? $map[$k] : '';
    }

    private function tabaghebandiLabel($code)
    {
        $map = array(
            '4' => 'پیشفرض', '142' => 'نظامی', '102' => 'موسسه', '81' => 'مدارس',
            '63' => 'شهرداری', '203' => 'شعبه تامین اجتماعی', '3' => 'شعب بانکی',
            '162' => 'شرکت ملی', '182' => 'دولتی', '122' => 'دانشگاه',
            '101' => 'تعاونی', '202' => 'تامین اجتماعی', '1' => 'بیمه ها', '2' => 'بانک ها',
        );
        $k = trim((string)$code);
        return isset($map[$k]) ? $map[$k] : $k;
    }

    private function noeSherkatLabel($code)
    {
        $k = trim((string)$code);
        if ($k === '0') return 'دولتی';
        if ($k === '1') return 'غیر دولتی';
        return $k;
    }

    private function goroohHesabdariLabel($code)
    {
        $map = array(
            '1' => 'حقیقی:پیش فرض',
            '2' => 'حقوقی:پیش فرض',
            '3' => 'حقوقی:گروه مالی پاسارگاد',
        );
        $k = trim((string)$code);
        return isset($map[$k]) ? $map[$k] : $k;
    }

    private function melliatLabel($code)
    {
        $k = trim((string)$code);
        if ($k === '29' or $k === '') return 'ایران';
        return $k;
    }

    /** تجزیه آرگومان‌های یک تابع جاوااسکریپت مانند fillShakhsHoghooghiInfo(...) */
    private function parseJsArgs($argStr)
    {
        $args = array();
        $len  = strlen($argStr);
        $cur  = '';
        $inQ  = false;
        $qChr = '';
        $esc  = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $argStr[$i];
            if ($esc) {
                $cur .= $ch;
                $esc = false;
                continue;
            }
            if ($ch === '\\') {
                $esc = true;
                continue;
            }
            if ($inQ) {
                if ($ch === $qChr) {
                    $inQ = false;
                } else {
                    $cur .= $ch;
                }
                continue;
            }
            if ($ch === "'" or $ch === '"') {
                $inQ  = true;
                $qChr = $ch;
                continue;
            }
            if ($ch === ',') {
                $val = trim($cur);
                if (strtolower($val) === 'null' or strtolower($val) === 'undefined') $val = '';
                $args[] = $this->decodeHtml($val);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $val = trim($cur);
        if (strtolower($val) === 'null' or strtolower($val) === 'undefined') $val = '';
        $args[] = $this->decodeHtml($val);
        return $args;
    }

    /** استخراج اطلاعات شرکت از صفحه پاپ‌آپ shakhsHoghooghi.simplelov.html */
    private function parseLovLegalResult($html)
    {
        $info = array(
            'found'             => false,
            'id'                => '',
            'shomarehShenasai'  => '',
            'shenasehMelli'     => '',
            'shomarehSabt'      => '',
            'codeEghtesadi'     => '',
            'naam'              => '',
            'tabaghebandi'      => '4',
            'tarikhTasisDay'    => '',
            'tarikhTasisMonth'  => '',
            'tarikhTasisYear'   => '',
            'tarikhTasis'       => '',
            'noeSherkat'        => '',
            'melliatId'         => '29',
            'goroohHesabdariId' => '2',
            'telefon'           => '',
            'mobile'            => '',
            'operatorId'        => '',
            'fax'               => '',
            'email'             => '',
            'webSite'           => '',
            'codePosti'         => '',
            'address'           => '',
            'shahrDesc'         => '',
            'shahrId'           => '',
            'jsfSelectId'       => '',
        );

        // ۱. بررسی فراخوانی مستقیم fillShakhsHoghooghiInfo(...)
        if (preg_match_all('/fillShakhsHoghooghiInfo\s*\(([^)]+)\)/is', $html, $matches)) {
            foreach ($matches[1] as $argStr) {
                $args = $this->parseJsArgs($argStr);
                if (count($args) >= 6 && $args[0] !== 'id' && (is_numeric($args[0]) or is_numeric($args[2]))) {
                    $info['found']             = true;
                    $info['id']                = isset($args[0]) ? $args[0] : '';
                    $info['shomarehShenasai']  = isset($args[1]) ? $args[1] : '';
                    $info['shenasehMelli']     = isset($args[2]) ? $args[2] : '';
                    $info['shomarehSabt']      = isset($args[3]) ? $args[3] : '';
                    $info['codeEghtesadi']     = isset($args[4]) ? $args[4] : '';
                    $info['naam']              = isset($args[5]) ? $args[5] : '';
                    $info['tabaghebandi']      = isset($args[6]) && $args[6] !== '' ? $args[6] : '4';
                    $info['tarikhTasisDay']    = isset($args[7]) ? $args[7] : '';
                    $info['tarikhTasisMonth']  = isset($args[8]) ? $args[8] : '';
                    $info['tarikhTasisYear']   = isset($args[9]) ? $args[9] : '';
                    $info['noeSherkat']        = isset($args[10]) ? $args[10] : '';
                    $info['melliatId']         = isset($args[11]) && $args[11] !== '' ? $args[11] : '29';
                    $info['goroohHesabdariId'] = isset($args[12]) && $args[12] !== '' ? $args[12] : '2';
                    break;
                }
            }
        }

        // ۲. بررسی فراخوانی مستقیم fillMoshakhasatInfo(...)
        if (preg_match_all('/fillMoshakhasatInfo\s*\(([^)]+)\)/is', $html, $mMatches)) {
            foreach ($mMatches[1] as $argStr) {
                $args = $this->parseJsArgs($argStr);
                if (count($args) >= 7 && $args[0] !== 'prefix') {
                    $info['telefon']    = isset($args[1]) ? $args[1] : '';
                    $info['mobile']     = isset($args[2]) ? $args[2] : '';
                    $info['fax']        = isset($args[3]) ? $args[3] : '';
                    $info['email']      = isset($args[4]) ? $args[4] : '';
                    $info['codePosti']  = isset($args[5]) ? $args[5] : '';
                    $info['address']    = isset($args[6]) ? $args[6] : '';
                    $info['shahrDesc']  = isset($args[7]) ? $args[7] : '';
                    $info['shahrId']    = isset($args[8]) ? $args[8] : '';
                    $info['operatorId'] = isset($args[9]) ? $args[9] : '';
                    break;
                }
            }
        }

        // ۳. بررسی دکمه انتخاب در جدول exampleform:dataTable
        $tableChunk = $html;
        if (preg_match('/<tbody\b[^>]*\bid=["\']exampleform:dataTable:tbody_element["\'][^>]*>(.*?)<\/tbody>/is', $html, $tm)) {
            $tableChunk = $tm[1];
        } elseif (preg_match('/<table\b[^>]*\bid=["\']exampleform:dataTable["\'][^>]*>(.*?)<\/table>/is', $html, $tm)) {
            $tableChunk = $tm[1];
        }

        if (!$info['found']) {
            // بررسی onclickهای داخل جدول که تابع انتخاب (مثل select یا selectShakhsHoghooghi) را صدا می‌زنند
            if (preg_match_all('/onclick=["\']([^"\']+)["\']/is', $tableChunk, $ocMatches)) {
                foreach ($ocMatches[1] as $oc) {
                    if (stripos($oc, 'submitForm') !== false) {
                        if (preg_match('/submitForm\s*\(\s*["\']exampleform["\']\s*,\s*["\'](exampleform:dataTable:0:[^"\']+)["\']/i', $oc, $sm)) {
                            $info['jsfSelectId'] = $sm[1];
                        }
                        continue;
                    }
                    if (preg_match_all('/([a-zA-Z0-9_$.]+)\s*\(([^)]*)\)/s', $oc, $fnCalls, PREG_SET_ORDER)) {
                        foreach ($fnCalls as $fn) {
                            $fnName = strtolower($fn[1]);
                            if (strpos($fnName, 'close') !== false) continue;
                            $args = $this->parseJsArgs($fn[2]);
                            if (count($args) >= 6 && (is_numeric($args[0]) or is_numeric($args[2]))) {
                                $info['found']             = true;
                                $info['id']                = isset($args[0]) ? $args[0] : '';
                                $info['shomarehShenasai']  = isset($args[1]) ? $args[1] : '';
                                $info['shenasehMelli']     = isset($args[2]) ? $args[2] : '';
                                $info['shomarehSabt']      = isset($args[3]) ? $args[3] : '';
                                $info['codeEghtesadi']     = isset($args[4]) ? $args[4] : '';
                                $info['naam']              = isset($args[5]) ? $args[5] : '';
                                $info['tabaghebandi']      = isset($args[6]) && $args[6] !== '' ? $args[6] : '4';
                                $info['tarikhTasisDay']    = isset($args[7]) ? $args[7] : '';
                                $info['tarikhTasisMonth']  = isset($args[8]) ? $args[8] : '';
                                $info['tarikhTasisYear']   = isset($args[9]) ? $args[9] : '';
                                $info['noeSherkat']        = isset($args[10]) ? $args[10] : '';
                                $info['melliatId']         = isset($args[11]) && $args[11] !== '' ? $args[11] : '29';
                                $info['goroohHesabdariId'] = isset($args[12]) && $args[12] !== '' ? $args[12] : '2';
                                if (count($args) >= 20) {
                                    $info['telefon']    = isset($args[13]) ? $args[13] : '';
                                    $info['mobile']     = isset($args[14]) ? $args[14] : '';
                                    $info['fax']        = isset($args[15]) ? $args[15] : '';
                                    $info['email']      = isset($args[16]) ? $args[16] : '';
                                    $info['codePosti']  = isset($args[17]) ? $args[17] : '';
                                    $info['address']    = isset($args[18]) ? $args[18] : '';
                                    $info['shahrDesc']  = isset($args[19]) ? $args[19] : '';
                                    $info['shahrId']    = isset($args[20]) ? $args[20] : '';
                                    $info['operatorId'] = isset($args[21]) ? $args[21] : '';
                                }
                            } elseif (count($args) >= 7 && $args[0] === 'moshakhasatOmumi') {
                                $info['telefon']    = isset($args[1]) ? $args[1] : '';
                                $info['mobile']     = isset($args[2]) ? $args[2] : '';
                                $info['fax']        = isset($args[3]) ? $args[3] : '';
                                $info['email']      = isset($args[4]) ? $args[4] : '';
                                $info['codePosti']  = isset($args[5]) ? $args[5] : '';
                                $info['address']    = isset($args[6]) ? $args[6] : '';
                                $info['shahrDesc']  = isset($args[7]) ? $args[7] : '';
                                $info['shahrId']    = isset($args[8]) ? $args[8] : '';
                                $info['operatorId'] = isset($args[9]) ? $args[9] : '';
                            }
                        }
                    }
                    if ($info['found']) break;
                }
            }
        }

        // ۴. استخراج مکمل از متن ستون‌های جدول (در صورتی که برخی مقادیر در جدول نمایش داده شده باشند)
        if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableChunk, $trMatches) && !empty($trMatches[1])) {
            foreach ($trMatches[1] as $trHtml) {
                if (preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $trHtml, $tdMatches) && count($tdMatches[1]) >= 3) {
                    $col0 = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $tdMatches[1][0])));
                    $col1 = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $tdMatches[1][1])));
                    $col2 = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $tdMatches[1][2])));

                    if (preg_match('/\b(\d{10,11})\b/', $col1, $smMatch)) {
                        $info['found'] = true;
                        if ($info['shenasehMelli'] === '') $info['shenasehMelli'] = $smMatch[1];
                    }
                    $c1Lines = array_values(array_filter(array_map('trim', explode("\n", $col1))));
                    if (count($c1Lines) >= 2 && $info['shomarehSabt'] === '') $info['shomarehSabt'] = $c1Lines[1];
                    if (count($c1Lines) >= 3 && $info['codeEghtesadi'] === '') $info['codeEghtesadi'] = $c1Lines[2];

                    $c0Lines = array_values(array_filter(array_map('trim', explode("\n", $col0))));
                    if (count($c0Lines) >= 1 && $info['naam'] === '') $info['naam'] = $c0Lines[0];
                    if (count($c0Lines) >= 2 && $info['shomarehShenasai'] === '') $info['shomarehShenasai'] = $c0Lines[1];

                    if (preg_match('/(\d{4})\/(\d{1,2})\/(\d{1,2})/', $col2, $dtMatch)) {
                        if ($info['tarikhTasisYear'] === '') {
                            $info['tarikhTasisYear']  = $dtMatch[1];
                            $info['tarikhTasisMonth'] = $dtMatch[2];
                            $info['tarikhTasisDay']   = $dtMatch[3];
                        }
                    }
                    if ($info['noeSherkat'] === '') {
                        if (strpos($col2, 'غير دولتي') !== false or strpos($col2, 'غیر دولتی') !== false) {
                            $info['noeSherkat'] = '1';
                        } elseif (strpos($col2, 'دولتي') !== false or strpos($col2, 'دولتی') !== false) {
                            $info['noeSherkat'] = '0';
                        }
                    }
                    break;
                }
            }
            // خواندن نام کامل شرکت از متن دکمه انتخاب در ردیف دوم
            foreach ($trMatches[1] as $trHtml) {
                if (preg_match('/<(?:a|button)\b[^>]*>([^<]+)<\/(?:a|button)>/is', $trHtml, $btnTxt)) {
                    $t = trim($this->decodeHtml($btnTxt[1]));
                    if ($t !== '' && strpos($t, 'تعداد کل') === false) {
                        if ($info['naam'] === '' or strpos($info['naam'], '...') !== false) {
                            $info['naam'] = $t;
                        }
                    }
                } elseif (preg_match('/<input\b[^>]*\btype=["\']button["\'][^>]*\bvalue=["\']([^"\']+)["\']/is', $trHtml, $btnTxt)) {
                    $t = trim($this->decodeHtml($btnTxt[1]));
                    if ($t !== '' && ($info['naam'] === '' or strpos($info['naam'], '...') !== false)) {
                        $info['naam'] = $t;
                    }
                }
            }
        }

        if ($info['tarikhTasisYear'] !== '' && $info['tarikhTasisMonth'] !== '' && $info['tarikhTasisDay'] !== '') {
            $info['tarikhTasis'] = $info['tarikhTasisYear'] . '/' . sprintf('%02d', (int)$info['tarikhTasisMonth']) . '/' . sprintf('%02d', (int)$info['tarikhTasisDay']);
        }

        return $info;
    }

    /** خواندن کامل تمام فیلدهای شخص حقوقی و مشخصات ارتباطی از فرم اصلی editform */
    private function readLegalEntityFromEditForm($html, $lovInfo = array())
    {
        $f = $this->fields($html, 'editform');

        $get = function ($keys, $fallback = '') use ($f, $html) {
            foreach ($keys as $k) {
                if (isset($f[$k]) && trim($f[$k]) !== '') return trim($f[$k]);
                if (preg_match('/<input\b[^>]*\b(?:name|id)=["\']' . preg_quote($k, '/') . '["\'][^>]*>/is', $html, $tm)) {
                    if (preg_match('/\bvalue=["\']([^"\']*)["\']/i', $tm[0], $vm)) {
                        $v = trim($this->decodeHtml($vm[1]));
                        if ($v !== '') return $v;
                    }
                }
            }
            return $fallback;
        };

        $id               = $get(array('shakhsHoghooghi_ID'), isset($lovInfo['id']) ? $lovInfo['id'] : '');
        $shenasehMelli    = $get(array('shakhsHoghooghi_shenasehMelli_hidden', 'shakhsHoghooghi_shenasehMelli'), isset($lovInfo['shenasehMelli']) ? $lovInfo['shenasehMelli'] : '');
        $shomarehShenasai = $get(array('shakhsHoghooghi_shomarehShenasai_hidden', 'shakhsHoghooghi_shomarehShenasai'), isset($lovInfo['shomarehShenasai']) ? $lovInfo['shomarehShenasai'] : '');
        $naam             = $get(array('shakhsHoghooghi_naam_hidden', 'shakhsHoghooghi_naam'), isset($lovInfo['naam']) ? $lovInfo['naam'] : '');
        $shomarehSabt     = $get(array('shakhsHoghooghi_shomarehSabt_hidden', 'shakhsHoghooghi_shomarehSabt'), isset($lovInfo['shomarehSabt']) ? $lovInfo['shomarehSabt'] : '');
        $codeEghtesadi    = $get(array('shakhsHoghooghi_codeEghtesadi_hidden', 'shakhsHoghooghi_codeEghtesadi'), isset($lovInfo['codeEghtesadi']) ? $lovInfo['codeEghtesadi'] : '');
        $tabaghebandiCode = $get(array('shakhsHoghooghi_tabaghebandi_hidden', 'shakhsHoghooghi_tabaghebandi'), isset($lovInfo['tabaghebandi']) ? $lovInfo['tabaghebandi'] : '4');
        $noeSherkatCode   = $get(array('shakhsHoghooghi_noeSherkat_hidden', 'shakhsHoghooghi_noeSherkat'), isset($lovInfo['noeSherkat']) ? $lovInfo['noeSherkat'] : '');
        $tarikhTasis      = $get(array('editform:shakhsHoghooghi_tarikhTasis'), isset($lovInfo['tarikhTasis']) ? $lovInfo['tarikhTasis'] : '');
        if ($tarikhTasis === '//' or $tarikhTasis === ';HIJRI') {
            $tarikhTasis = isset($lovInfo['tarikhTasis']) ? $lovInfo['tarikhTasis'] : '';
        }
        $melliatCode      = $get(array('shakhsHoghooghi_melliat_hidden', 'shakhsHoghooghi_melliat'), isset($lovInfo['melliatId']) ? $lovInfo['melliatId'] : '29');
        $goroohCode       = $get(array('shakhsHoghooghi_goroohHesabdari_hidden', 'shakhsHoghooghi_goroohHesabdari'), isset($lovInfo['goroohHesabdariId']) ? $lovInfo['goroohHesabdariId'] : '2');

        $shahrDesc = $get(array('editform:moshakhasatOmumi_shahr.desc', 'editform:tarafBimeh_shahr.desc'), isset($lovInfo['shahrDesc']) ? $lovInfo['shahrDesc'] : '');
        $shahrId   = $get(array('editform:moshakhasatOmumi_shahr', 'editform:tarafBimeh_shahr'), isset($lovInfo['shahrId']) ? $lovInfo['shahrId'] : '');
        $telefon   = $get(array('moshakhasatOmumi_telefon', 'shomarehTelephoneTarafGharardad'), isset($lovInfo['telefon']) ? $lovInfo['telefon'] : '');
        $mobNum    = $get(array('moshakhasatOmumi_mobile_number', 'shomarehMobileTarafGharardad_number'), isset($lovInfo['mobile']) ? $lovInfo['mobile'] : '');
        $mobOp     = $get(array('moshakhasatOmumi_mobile_operator', 'shomarehMobileTarafGharardad_operator'), isset($lovInfo['operatorId']) ? $lovInfo['operatorId'] : '');
        $fax       = $get(array('moshakhasatOmumi_fax'), isset($lovInfo['fax']) ? $lovInfo['fax'] : '');
        $webSite   = $get(array('moshakhasatOmumi_webSite'), isset($lovInfo['webSite']) ? $lovInfo['webSite'] : '');
        $email     = $get(array('moshakhasatOmumi_email'), isset($lovInfo['email']) ? $lovInfo['email'] : '');
        $codePosti = $get(array('moshakhasatOmumi_codePosti', 'tarafGharardad_codePosti'), isset($lovInfo['codePosti']) ? $lovInfo['codePosti'] : '');
        $address   = $get(array('moshakhasatOmumi_address', 'tarafGharardad_address'), isset($lovInfo['address']) ? $lovInfo['address'] : '');

        $prefix = $this->operatorPrefix($mobOp);
        $fullMobile = $mobNum;
        if ($mobNum !== '' && strlen($mobNum) <= 8 && $prefix !== '') {
            $fullMobile = $prefix . $mobNum;
        }

        return array(
            'id'               => $id,
            'naam'             => $naam,
            'shenasehMelli'    => $shenasehMelli,
            'shomarehShenasai' => $shomarehShenasai,
            'shomarehSabt'     => $shomarehSabt,
            'codeEghtesadi'    => $codeEghtesadi,
            'tabaghebandi'     => $this->tabaghebandiLabel($tabaghebandiCode),
            'noeSherkat'       => $this->noeSherkatLabel($noeSherkatCode),
            'tarikhTasis'      => $tarikhTasis,
            'melliat'          => $this->melliatLabel($melliatCode),
            'goroohHesabdari'  => $this->goroohHesabdariLabel($goroohHesabCode = $goroohCode),
            'shahr'            => $shahrDesc,
            'shahrId'          => $shahrId,
            'telefon'          => $telefon,
            'mobile'           => $fullMobile,
            'fax'              => $fax,
            'webSite'          => $webSite,
            'email'            => $email,
            'codePosti'        => $codePosti,
            'address'          => $address,
        );
    }

    /** اجرای گام‌به‌گام استعلام اشخاص حقوقی (۴ گام) */
    public function runLegalStep($step, $legalQuery)
    {
        $tStart = microtime(true);
        $state  = $this->state;

        $qShenaseh  = isset($legalQuery['shenasehMelli']) ? trim((string)$legalQuery['shenasehMelli']) : '';
        $qNaam      = isset($legalQuery['naam']) ? trim((string)$legalQuery['naam']) : '';
        $qEghtesadi = isset($legalQuery['codeEghtesadi']) ? trim((string)$legalQuery['codeEghtesadi']) : '';

        // ==================== گام ۱: باز کردن لیست و کلیک روی «ایجاد» ====================
        if ($step === 1) {
            $list = $this->http('GET', $this->listUrl);
            if ($this->loggedOut($list['body'])) {
                return array('ok' => false, 'error' => 'need_login');
            }
            if (strpos($list['body'], 'exampleform:create') === false) {
                return array(
                    'ok'     => false,
                    'step'   => 1,
                    'status' => 'bad',
                    'title'  => '۱. ورود به فرم ایجاد پیش‌نویس',
                    'detail' => 'دکمه exampleform:create در صفحه لیست پیدا نشد',
                    'time'   => round(microtime(true) - $tStart, 1) . ' ثانیه',
                );
            }

            $r = $this->submit($this->listUrl, $list['body'], array(), 'exampleform:create', $this->listUrl);
            if ($this->loggedOut($r['body'])) {
                return array('ok' => false, 'error' => 'need_login');
            }
            $html = $r['body'];
            $this->saveStepHtml(1, $html);

            if (!$this->hasField($html, 'shakhsHoghooghi_shenasehMelli') && !$this->hasField($html, 'shakhsHaghighi_codeMelli')) {
                return array(
                    'ok'     => false,
                    'step'   => 1,
                    'status' => 'bad',
                    'title'  => '۱. ورود به فرم ایجاد پیش‌نویس',
                    'detail' => 'فرم ایجاد پیش‌نویس باز نشد',
                    'time'   => round(microtime(true) - $tStart, 1) . ' ثانیه',
                );
            }

            $this->state = array('legalQuery' => $legalQuery, 'lastStep' => 1);

            $live = $this->inspectLivePage($html);
            return array(
                'ok'     => true,
                'step'   => 1,
                'status' => 'ok',
                'title'  => '۱. باز کردن لیست و کلیک روی «ایجاد»',
                'detail' => "فرم ایجاد پیش‌نویس باز شد (توکن: {$live['token']})",
                'time'   => round(microtime(true) - $tStart, 1) . ' ثانیه',
                'live'   => $live,
            );
        }

        // ==================== گام ۲: انتخاب «حقوقی»، زدن ۳ تیک و باز کردن پنجره فهرست اشخاص حقوقی ====================
        if ($step === 2) {
            $rLov = $this->http('GET', $this->lovInitUrl, array(), $this->formUrl);
            if ($this->loggedOut($rLov['body'])) {
                return array('ok' => false, 'error' => 'need_login');
            }
            $lovHtml = $rLov['body'];
            if (!$this->hasField($lovHtml, 'shenasehMelli')) {
                $rLov2 = $this->http('GET', $this->lovSearchUrl . '?shakhsInfoSelectable=true&moshakhasatInfoSelectable=true', array(), $this->lovInitUrl);
                if (!$this->loggedOut($rLov2['body']) && $this->hasField($rLov2['body'], 'shenasehMelli')) {
                    $lovHtml = $rLov2['body'];
                }
            }

            $this->saveStepHtml(2, $lovHtml);

            if (!$this->hasField($lovHtml, 'shenasehMelli')) {
                return array(
                    'ok'      => false,
                    'step'    => 2,
                    'status'  => 'bad',
                    'title'   => '۲. باز کردن پنجره فهرست اشخاص حقوقی',
                    'detail'  => 'پنجره فهرست اشخاص حقوقی (shakhsHoghooghi.lov.html) لود نشد',
                    'message' => 'پنجره فهرست اشخاص حقوقی باز نشد',
                    'time'    => round(microtime(true) - $tStart, 1) . ' ثانیه',
                );
            }

            $state['lastStep'] = 2;
            $this->state = $state;

            return array(
                'ok'     => true,
                'step'   => 2,
                'status' => 'ok',
                'title'  => '۲. انتخاب شخص «حقوقی» + تیک‌ها + باز کردن پنجره فهرست اشخاص حقوقی',
                'detail' => 'نوع شخص روی «حقوقی» تنظیم شد، هر ۳ تیک فعال شد و پنجره جستجوی اشخاص حقوقی باز گردید',
                'time'   => round(microtime(true) - $tStart, 1) . ' ثانیه',
            );
        }

        // ==================== گام ۳: باز کردن نوار جستجو (fa-search-plus) و کلیک روی دکمه «جستجو» ====================
        if ($step === 3) {
            $lovHtml = (string)($this->html[2] ?? '');

            $searchOverrides = array(
                'entity'                         => 'shakhsHoghooghi',
                'naam'                           => $qNaam,
                'code'                           => '',
                'codeShobeh'                     => '',
                'shomarehSabt'                   => '',
                'exampleform:noeShenasehMaaliat' => '',
                'codeEghtesadi'                  => $qEghtesadi,
                'shenasehMelli'                  => $qShenaseh,
                'shomarehEghtesadi'              => '',
                'exampleform:tabaghebandi'       => '',
                'exampleform:pedar.desc'         => '',
                'exampleform:pedar'              => '',
                'exampleform:noeSherkat'         => '',
                'filesHidden'                    => '',
                'filesId'                        => '@(item.Value.Id)',
                'autoScroll'                     => '0,0',
                'exampleform:tarikhSabt_ge'      => ';HIJRI',
                'exampleform:tarikhSabt_ge_y'    => '',
                'exampleform:tarikhSabt_ge_m'    => '',
                'exampleform:tarikhSabt_ge_d'    => '',
                'exampleform:tarikhSabt_le'      => ';HIJRI',
                'exampleform:tarikhSabt_le_y'    => '',
                'exampleform:tarikhSabt_le_m'    => '',
                'exampleform:tarikhSabt_le_d'    => '',
                'exampleform:dataTable:goto'     => '1',
            );

            $r = $this->submit($this->lovSearchUrl, $lovHtml, $searchOverrides, 'exampleform:search', $this->lovInitUrl);
            if ($this->loggedOut($r['body'])) {
                return array('ok' => false, 'error' => 'need_login');
            }
            $resHtml = $r['body'];
            $parsed  = $this->parseLovLegalResult($resHtml);

            // اگر کد اقتصادی وارد شده و در فیلد codeEghtesadi نتیجه‌ای نیامد، فیلد shomarehEghtesadi را هم امتحان می‌کنیم
            if (!$parsed['found'] && empty($parsed['jsfSelectId']) && $qEghtesadi !== '') {
                $searchOverrides['codeEghtesadi']     = '';
                $searchOverrides['shomarehEghtesadi'] = $qEghtesadi;
                $r2 = $this->submit($this->lovSearchUrl, $resHtml, $searchOverrides, 'exampleform:search', $this->lovSearchUrl);
                if (!$this->loggedOut($r2['body'])) {
                    $resHtml = $r2['body'];
                    $parsed  = $this->parseLovLegalResult($resHtml);
                }
            }

            // اگر دکمه انتخاب ردیف اول از نوع اکشن JSF بود، آن را هم ارسال می‌کنیم تا اسکریپت پرکننده برگردد
            if (!$parsed['found'] && !empty($parsed['jsfSelectId'])) {
                $rSel = $this->submit($this->lovSearchUrl, $resHtml, array(), $parsed['jsfSelectId'], $this->lovSearchUrl);
                if (!$this->loggedOut($rSel['body'])) {
                    $resHtml = $rSel['body'];
                    $parsed2 = $this->parseLovLegalResult($resHtml);
                    if ($parsed2['found']) $parsed = $parsed2;
                }
            }

            $this->saveStepHtml(3, $resHtml);

            if (!$parsed['found']) {
                $notices = $this->parseNotices($resHtml);
                $msg = 'هیچ شخص حقوقی با مشخصات وارد شده در فهرست پاسارگاد یافت نشد';
                if ($notices['hasError']) $msg .= ' (' . implode(' - ', $notices['errors']) . ')';
                return array(
                    'ok'      => false,
                    'step'    => 3,
                    'status'  => 'bad',
                    'title'   => '۳. جستجوی شخص حقوقی در فهرست',
                    'detail'  => $msg,
                    'message' => $msg,
                    'time'    => round(microtime(true) - $tStart, 1) . ' ثانیه',
                );
            }

            $state['lovParsed'] = $parsed;
            $state['lastStep']  = 3;
            $this->state = $state;

            return array(
                'ok'     => true,
                'step'   => 3,
                'status' => 'ok',
                'title'  => '۳. جستجوی شخص حقوقی در پنجره فهرست',
                'detail' => "ردیف شرکت یافت شد: «{$parsed['naam']}» (شناسه ملی: {$parsed['shenasehMelli']} - شماره ثبت: {$parsed['shomarehSabt']})",
                'time'   => round(microtime(true) - $tStart, 1) . ' ثانیه',
            );
        }

        // ==================== گام ۴: کلیک روی دکمه نام شرکت، انتقال به فرم اصلی و استخراج کامل اطلاعات ====================
        if ($step === 4) {
            $editHtml = (string)($this->html[1] ?? '');
            $lp       = isset($state['lovParsed']) ? $state['lovParsed'] : array();

            $tasisVal = ';HIJRI';
            if (!empty($lp['tarikhTasisYear']) && !empty($lp['tarikhTasisMonth']) && !empty($lp['tarikhTasisDay'])) {
                $tasisVal = $lp['tarikhTasisYear'] . '/' . $lp['tarikhTasisMonth'] . '/' . $lp['tarikhTasisDay'];
            }

            // شبیه‌سازی دقیق fillShakhsHoghooghiInfo و fillMoshakhasatInfo روی فرم اصلی editform
            $legalFormFields = array(
                'editform:noeShakhs'                           => '1',
                'editform:noeTarafGharardad'                   => '',
                'editform:isBimehgozar'                        => 'true',
                'editform:isTarafAsli'                         => 'true',
                'editform:isPardakhtKonandeh'                  => 'true',
                'shakhsHoghooghi_ID'                           => isset($lp['id']) ? $lp['id'] : '',
                'shakhsHoghooghi_shenasehMelli_hidden'         => isset($lp['shenasehMelli']) ? $lp['shenasehMelli'] : '',
                'shakhsHoghooghi_shomarehShenasai_hidden'      => isset($lp['shomarehShenasai']) ? $lp['shomarehShenasai'] : '',
                'shakhsHoghooghi_naam_hidden'                  => isset($lp['naam']) ? $lp['naam'] : '',
                'shakhsHoghooghi_shomarehSabt_hidden'          => isset($lp['shomarehSabt']) ? $lp['shomarehSabt'] : '',
                'shakhsHoghooghi_codeEghtesadi_hidden'         => isset($lp['codeEghtesadi']) ? $lp['codeEghtesadi'] : '',
                'shakhsHoghooghi_tabaghebandi_hidden'          => isset($lp['tabaghebandi']) && $lp['tabaghebandi'] !== '' ? $lp['tabaghebandi'] : '4',
                'shakhsHoghooghi_noeSherkat_hidden'            => isset($lp['noeSherkat']) ? $lp['noeSherkat'] : '',
                'editform:shakhsHoghooghi_tarikhTasis'         => $tasisVal,
                'shakhsHoghooghi_melliat_hidden'               => isset($lp['melliatId']) && $lp['melliatId'] !== '' ? $lp['melliatId'] : '29',
                'shakhsHoghooghi_goroohHesabdari_hidden'       => isset($lp['goroohHesabdariId']) && $lp['goroohHesabdariId'] !== '' ? $lp['goroohHesabdariId'] : '2',
                'editform:moshakhasatOmumi_shahr'              => isset($lp['shahrId']) ? $lp['shahrId'] : '',
                'editform:moshakhasatOmumi_shahr.desc'         => isset($lp['shahrDesc']) ? $lp['shahrDesc'] : '',
                'moshakhasatOmumi_telefon'                     => isset($lp['telefon']) ? $lp['telefon'] : '',
                'moshakhasatOmumi_mobile_number'               => isset($lp['mobile']) ? $lp['mobile'] : '',
                'moshakhasatOmumi_mobile_operator'             => isset($lp['operatorId']) ? $lp['operatorId'] : '',
                'moshakhasatOmumi_fax'                         => isset($lp['fax']) ? $lp['fax'] : '',
                'moshakhasatOmumi_email'                       => isset($lp['email']) ? $lp['email'] : '',
                'moshakhasatOmumi_codePosti'                   => isset($lp['codePosti']) ? $lp['codePosti'] : '',
                'moshakhasatOmumi_address'                     => isset($lp['address']) ? $lp['address'] : '',
            );

            $r = $this->submit($this->formUrl, $editHtml, $legalFormFields, 'editform:addTotarafGharardadList', $this->formUrl);
            if ($this->loggedOut($r['body'])) {
                return array('ok' => false, 'error' => 'need_login');
            }
            $finalHtml = $r['body'];
            $this->saveStepHtml(4, $finalHtml);

            $company = $this->readLegalEntityFromEditForm($finalHtml, $lp);
            $this->state['company'] = $company;
            $notices = $this->parseNotices($finalHtml);
            $live    = $this->inspectLivePage($finalHtml, $company['shenasehMelli']);

            return array(
                'ok'      => true,
                'step'    => 4,
                'status'  => $notices['hasWarning'] ? 'warn' : 'ok',
                'title'   => '۴. انتخاب دکمه نام شرکت و استخراج کامل اطلاعات ثبتی و ارتباطی',
                'detail'  => "اطلاعات کامل شرکت «{$company['naam']}» در فیلدهای فرم نشست و استخراج گردید (کد پستی: " . ($company['codePosti'] ? $company['codePosti'] : '—') . " - شهر: " . ($company['shahr'] ? $company['shahr'] : '—') . ")",
                'time'    => round(microtime(true) - $tStart, 1) . ' ثانیه',
                'live'    => $live,
                'company' => $company,
            );
        }

        // ==================== گام ۵ (اختیاری): استعلامِ کد پستیِ شخصِ حقوقی از املاک و اسکان ====================
        if ($step === 5) {
            $html = (string)($this->html[4] ?? '');
            $comp = isset($state['company']) ? $state['company'] : array();
            $target = isset($legalQuery['postal']) && $legalQuery['postal'] !== '' ? $legalQuery['postal'] : (isset($comp['codePosti']) ? $comp['codePosti'] : '');
            $btn = $this->findPostalButton($html);
            $r = $this->submit($this->formUrl, $html, array('editform:noeShakhs' => '1', 'moshakhasatOmumi_codePosti' => $target), $btn, $this->formUrl);
            if ($this->loggedOut($r['body'])) {
                return array('ok' => false, 'error' => 'need_login');
            }
            $html5 = $r['body'];
            $this->saveStepHtml(5, $html5);
            $n = $this->parseNotices($html5);
            $valid = null; $msg = '';
            if ($n['hasSuccess']) { $valid = true; $msg = implode(' - ', $n['messages']); }
            elseif ($n['hasError']) { $valid = false; $msg = implode(' - ', $n['errors']); }
            elseif ($n['hasWarning']) {
                $msg = implode(' - ', $n['warnings']);
                if (strpos($msg, 'ثبت شده') !== false && strpos($msg, 'نشده') === false) $valid = true;
                elseif (strpos($msg, 'نشده') !== false || strpos($msg, 'ندارد') !== false || strpos($msg, 'نامعتبر') !== false) $valid = false;
            }
            return array(
                'ok'      => true,
                'step'    => 5,
                'status'  => $valid === true ? 'ok' : ($valid === false ? 'bad' : 'warn'),
                'title'   => '۵. استعلام کد پستی شخص حقوقی',
                'detail'  => "کد پستی {$target} استعلام شد - پاسخ سامانه: " . $n['summaryText'],
                'time'    => round(microtime(true) - $tStart, 1) . ' ثانیه',
                'company' => $comp,
                'postal'  => array('valid' => $valid, 'message' => $msg !== '' ? $msg : ($valid === true ? 'کد پستی در املاک و اسکان ثبت شده است' : ($valid === false ? 'کد پستی در املاک و اسکان ثبت نشده است' : 'پاسخی از سامانه دریافت نشد'))),
            );
        }

        return array('ok' => false, 'message' => 'گام نامعتبر است');
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
    if (!isset(INQ_KINDS[$kind]) || $kind === 'sanhab') return ['ok' => false, 'error' => 'نوعِ استعلام نامعتبر است.'];
    if ($kind === 'legal') {
        $c = inq_digits($in['company_nid'] ?? ($in['national_id'] ?? ''));
        if (strlen($c) !== 11) return ['ok' => false, 'error' => 'شناسه‌ی ملیِ شخصِ حقوقی باید ۱۱ رقم باشد.', 'field' => 'national_id'];
        $postal = inq_digits($in['postal'] ?? '');
        if ($postal !== '' && strlen($postal) !== 10) return ['ok' => false, 'error' => 'کد پستی باید دقیقاً ۱۰ رقم باشد (یا خالی بماند).', 'field' => 'postal'];
        return ['ok' => true, 'national_id' => $c, 'birth' => null, 'postal' => $postal !== '' ? $postal : null];
    }
    $nid = inq_norm_nid($in['national_id'] ?? '');
    if (!inq_nid_ok($nid)) return ['ok' => false, 'error' => 'کد ملی نامعتبر است.', 'field' => 'national_id'];
    $birth = inq_norm_birth($in['birth'] ?? '');
    if (!$birth) return ['ok' => false, 'error' => 'تاریخ تولد نامعتبر است (مثل ۱۳۷۰/۰۵/۱۲).', 'field' => 'birth'];
    $postal = inq_digits($in['postal'] ?? '');
    if ($kind !== 'person' && strlen($postal) !== 10) return ['ok' => false, 'error' => 'کد پستی باید دقیقاً ۱۰ رقم باشد.', 'field' => 'postal'];
    return ['ok' => true, 'national_id' => $nid, 'birth' => $birth, 'postal' => $kind === 'person' ? null : $postal];
}

// =====================================================================
//  سقف‌های روزانه: هر کد ملی (همه‌ی کاربران روی هم) و هر کاربرِ پنل (پیش‌فرضِ کلی یا عددِ خودِ کاربر)
//  نتیجه‌ی ذخیره‌شده (کش) حساب نمی‌شود چون سامانه صدا زده نمی‌شود. ۰ = بدونِ سقف. مدیر کل سقف ندارد.
// =====================================================================
function inq_limits_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['users', 'company_portal_users'] as $t) foreach (['inq_daily_limit' => 'SMALLINT NULL', 'inq_reset_at' => 'DATETIME NULL'] as $c => $def) {
        try { if (!$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); }
        catch (Throwable $e) { error_log('[inq_limits_ensure] ' . $e->getMessage()); }
    }
    try { if (!$pdo->query("SHOW INDEX FROM inq_requests WHERE Key_name = 'idx_user'")->fetch()) $pdo->exec("ALTER TABLE inq_requests ADD INDEX idx_user (user_type, user_id, created_at)"); }
    catch (Throwable $e) { error_log('[inq_limits_ensure idx] ' . $e->getMessage()); }
}
function inq_limit_value($v) {
    $v = is_string($v) ? inq_digits($v) : $v;
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return max(0, min(10000, (int)$v));
}
function inq_user_table($type) { return $type === 'COMPANY' ? 'company_portal_users' : ($type === 'STAFF' ? 'users' : null); }
// سقف و مصرفِ امروزِ یک کاربرِ پنل => [limit (مؤثر؛ ۰ = بدونِ سقف), own (عددِ خودِ کاربر یا null), default, used, admin]
function inq_user_limit_info($pdo, $type, $id) {
    inq_ensure($pdo); inq_limits_ensure($pdo);
    $def = (int)inq_limit_value(inq_get_setting($pdo, 'inq_user_daily_limit'));
    $o = ['limit' => 0, 'own' => null, 'default' => $def, 'used' => 0, 'admin' => false];
    $t = inq_user_table($type);
    if (!$t || !$id) return $o;
    try {
        $st = $pdo->prepare("SELECT inq_daily_limit, inq_reset_at" . ($t === 'users' ? ', role' : '') . " FROM `$t` WHERE id = ?");
        $st->execute([intval($id)]);
        $u = $st->fetch() ?: [];
    } catch (Throwable $e) { $u = []; }
    $o['admin'] = ($u['role'] ?? '') === 'ADMIN';
    $o['own'] = inq_limit_value($u['inq_daily_limit'] ?? null);
    $o['limit'] = $o['admin'] ? 0 : ($o['own'] !== null ? $o['own'] : $def);
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM inq_requests WHERE user_type = ? AND user_id = ? AND kind <> 'sanhab' AND cached_from IS NULL AND created_at >= CURDATE() AND created_at > ?");
        $st->execute([$type, intval($id), !empty($u['inq_reset_at']) ? $u['inq_reset_at'] : '1970-01-01 00:00:00']);
        $o['used'] = intval($st->fetchColumn());
    } catch (Throwable $e) {}
    return $o;
}
function inq_user_set_limit($pdo, $type, $id, $v) {
    inq_limits_ensure($pdo);
    if (!($t = inq_user_table($type))) return;
    try { $pdo->prepare("UPDATE `$t` SET inq_daily_limit = ? WHERE id = ?")->execute([inq_limit_value($v), intval($id)]); } catch (Throwable $e) {}
}
// «صفر کردنِ شمارشِ امروز»: استعلام‌های قبل از این لحظه برای این کاربر حساب نمی‌شوند
function inq_user_reset($pdo, $type, $id) {
    inq_limits_ensure($pdo);
    if (!($t = inq_user_table($type))) return;
    try { $pdo->prepare("UPDATE `$t` SET inq_reset_at = NOW() WHERE id = ?")->execute([intval($id)]); } catch (Throwable $e) {}
}
// مصرفِ امروزِ همه‌ی کاربرانِ یک نوع (برای فهرستِ کاربران) => [id => n]
function inq_users_used_today($pdo, $type) {
    if (!($t = inq_user_table($type))) return [];
    inq_ensure($pdo); inq_limits_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT u.id, (SELECT COUNT(*) FROM inq_requests r WHERE r.user_type = ? AND r.user_id = u.id AND r.kind <> 'sanhab' AND r.cached_from IS NULL
                                                  AND r.created_at >= CURDATE() AND (u.inq_reset_at IS NULL OR r.created_at > u.inq_reset_at)) AS n
                               FROM `$t` u WHERE EXISTS (SELECT 1 FROM inq_requests r2 WHERE r2.user_type = ? AND r2.user_id = u.id AND r2.created_at >= CURDATE())");
        $st->execute([$type, $type]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (Throwable $e) { return []; }
}
// صفر کردنِ سقفِ یک کد ملی یا کد پستی (مدیر کل): استعلام‌های پیش از این لحظه برای آن حساب نمی‌شوند
function inq_resets_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS inq_limit_resets (k VARCHAR(16) NOT NULL PRIMARY KEY, reset_at DATETIME NOT NULL, reset_by VARCHAR(190) NULL)
                      ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Throwable $e) { error_log('[inq_resets_ensure] ' . $e->getMessage()); }
}
function inq_reset_since($pdo, $k) {
    inq_resets_ensure($pdo);
    try { $st = $pdo->prepare("SELECT reset_at FROM inq_limit_resets WHERE k = ?"); $st->execute([$k]); $v = $st->fetchColumn(); } catch (Throwable $e) { $v = false; }
    return $v ?: '1970-01-01 00:00:00';
}
function inq_limit_reset($pdo, $k, $by = '') {
    inq_resets_ensure($pdo);
    $pdo->prepare("INSERT INTO inq_limit_resets (k, reset_at, reset_by) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE reset_at = NOW(), reset_by = VALUES(reset_by)")->execute([$k, mb_substr((string)$by, 0, 190)]);
}
function inq_nid_used_today($pdo, $nid) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM inq_requests WHERE national_id = ? AND kind NOT IN ('legal', 'sanhab') AND cached_from IS NULL AND created_at >= CURDATE() AND created_at > ?");
    $st->execute([$nid, inq_reset_since($pdo, 'n:' . $nid)]);
    return intval($st->fetchColumn());
}
function inq_postal_used_today($pdo, $postal) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM inq_requests WHERE postal = ? AND kind IN ('postal', 'person_postal') AND cached_from IS NULL AND created_at >= CURDATE() AND created_at > ?");
    $st->execute([$postal, inq_reset_since($pdo, 'p:' . $postal)]);
    return intval($st->fetchColumn());
}
// کد پستیِ تأییدشده‌ی قبلیِ یک کد ملی (برای پر کردنِ خودکار؛ کاربر می‌تواند عوضش کند)
function inq_known_postal($pdo, $nid) {
    $nid = inq_norm_nid($nid);
    if (!inq_nid_ok($nid)) return null;
    inq_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT postal, finished_at FROM inq_requests WHERE national_id = ? AND kind IN ('postal', 'person_postal') AND postal_valid = 1 AND postal IS NOT NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$nid]);
        $r = $st->fetch();
    } catch (Throwable $e) { $r = null; }
    return $r ? ['postal' => $r['postal'], 'date' => jdate_short($r['finished_at'])] : null;
}

// نام‌هایی که سامانه با «ي/ك» عربی و فاصله‌های نامرتب برمی‌گرداند => فارسیِ یکدست
function inq_fa_norm($s) {
    $s = (string)$s;
    if ($s === '') return $s;
    $s = strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه‌ی', 'ؤ' => 'و', 'إ' => 'ا', 'أ' => 'ا', 'ٱ' => 'ا', 'ـ' => '',
                    '٠' => '۰', '١' => '۱', '٢' => '۲', '٣' => '۳', '٤' => '۴', '٥' => '۵', '٦' => '۶', '٧' => '۷', '٨' => '۸', '٩' => '۹']);
    $s = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $s);            // اعراب
    $s = preg_replace('/\x{200C}+/u', "\u{200C}", $s);                            // نیم‌فاصله‌ی تکراری
    $s = preg_replace('/\x{200C}?[ \t\x{00A0}]+\x{200C}?/u', ' ', $s);              // نیم‌فاصله کنارِ فاصله => فاصله
    return trim(preg_replace('/\s+/u', ' ', $s));
}
function inq_norm_person($p) {
    if (!is_array($p)) return $p;
    foreach (['name', 'family', 'fatherName', 'father'] as $k) if (isset($p[$k]) && is_string($p[$k])) $p[$k] = inq_fa_norm($p[$k]);
    return $p;
}

// نتیجه‌ی تازه‌ی همین استعلام (تا چند روز) برای استفاده‌ی دوباره
function inq_find_cached($pdo, $kind, $nid, $birth, $postal) {
    $days = max(0, intval(inq_get_setting($pdo, 'inq_cache_days')));
    if ($days <= 0) return null;
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    if ($kind === 'legal') {
        $st = $pdo->prepare("SELECT * FROM inq_requests WHERE kind = 'legal' AND national_id = ? AND COALESCE(postal, '') = ? AND status = 'OK' AND finished_at >= ? ORDER BY id DESC LIMIT 1");
        $st->execute([$nid, (string)$postal, $since]); return $st->fetch() ?: null;
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
    $c = $useCache ? inq_find_cached($pdo, $kind, $v['national_id'], $v['birth'], $v['postal']) : null;
    if (!$c) {
        // همین استعلام قبلاً ساخته شده و منتظرِ کپچا مانده (کاربر انصراف داده بود): همان دوباره استفاده می‌شود و سقف را دوباره مصرف نمی‌کند
        if (!$batchId) {
            $st = $pdo->prepare("SELECT id FROM inq_requests WHERE status = 'QUEUED' AND batch_id IS NULL AND kind = ? AND national_id = ? AND COALESCE(birth, '') = ? AND COALESCE(postal, '') = ?
                                   AND user_type = ? AND COALESCE(user_id, 0) = ? AND created_at >= CURDATE() ORDER BY id DESC LIMIT 1");
            $st->execute([$kind, $v['national_id'], (string)$v['birth'], (string)$v['postal'], $actor[0], intval($actor[1])]);
            if (($old = $st->fetchColumn())) return ['ok' => true, 'id' => intval($old), 'cached' => false];
        }
        if ($kind !== 'legal' && ($lim = (int)inq_limit_value(inq_get_setting($pdo, 'inq_nid_daily_limit'))) > 0 && inq_nid_used_today($pdo, $v['national_id']) >= $lim)
            return ['ok' => false, 'limit' => 'nid', 'error' => 'سقفِ استعلامِ امروزِ این کد ملی (' . $lim . ' بار) پر شده است؛ فردا دوباره امتحان کنید یا اطلاعات را دستی وارد کنید.', 'field' => 'national_id'];
        if (in_array($kind, ['postal', 'person_postal'], true) && ($lim = (int)inq_limit_value(inq_get_setting($pdo, 'inq_postal_daily_limit'))) > 0 && inq_postal_used_today($pdo, $v['postal']) >= $lim)
            return ['ok' => false, 'limit' => 'postal', 'error' => 'سقفِ استعلامِ امروزِ این کد پستی (' . $lim . ' بار) پر شده است؛ فردا دوباره امتحان کنید.', 'field' => 'postal'];
        if (in_array($actor[0], ['STAFF', 'COMPANY'], true)) {
            $li = inq_user_limit_info($pdo, $actor[0], $actor[1]);
            if ($li['limit'] > 0 && $li['used'] >= $li['limit'])
                return ['ok' => false, 'limit' => 'user', 'error' => 'سقفِ استعلامِ امروزِ شما (' . $li['limit'] . ' استعلام) پر شده است؛ برای افزایش به مدیر کل اطلاع دهید.'];
        }
    }
    $pdo->prepare("INSERT INTO inq_requests (kind, national_id, birth, postal, status, source, batch_id, row_no, user_type, user_id, user_name, created_at) VALUES (?, ?, ?, ?, 'QUEUED', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$kind, $v['national_id'], $v['birth'], $v['postal'], $source, $batchId, $rowNo, $actor[0], $actor[1] ?: null, mb_substr((string)$actor[2], 0, 190), $now]);
    $id = intval($pdo->lastInsertId());
    if ($c) {
        $p = isset($c['person']) ? $c['person'] : $c; $z = isset($c['postal']) ? $c['postal'] : $c;
        $res = json_decode((string)($p['result_json'] ?? ''), true) ?: [];
        if ($kind === 'person_postal') $res['postal'] = (json_decode((string)$z['result_json'], true) ?: [])['postal'] ?? ['valid' => $z['postal_valid'] === null ? null : (bool)$z['postal_valid'], 'message' => $z['postal_text']];
        $status = $kind === 'person' || $kind === 'legal' ? 'OK' : ($z['postal_valid'] === null ? 'WARN' : ((int)$z['postal_valid'] === 1 ? 'OK' : 'FAIL'));
        if ($kind === 'legal') { $z = $p; }
        $pdo->prepare("UPDATE inq_requests SET status = ?, notice = ?, person_name = ?, father_name = ?, postal_valid = ?, postal_text = ?, result_json = ?, cached_from = ?, started_at = ?, finished_at = ?, ms = 0 WHERE id = ?")
            ->execute([$status, 'از نتیجه‌ی استعلامِ قبلی (' . jdate_short($p['finished_at'] ?? $z['finished_at']) . ')', $kind === 'postal' ? null : ($p['person_name'] ?? null), $kind === 'postal' ? null : ($p['father_name'] ?? null),
                       $kind === 'person' ? null : $z['postal_valid'], $kind === 'person' ? null : $z['postal_text'],
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
        $person = inq_norm_person($person);
        if (isset($res['person'])) $res['person'] = inq_norm_person($res['person']);
        if ($status === 'OK' && $person && isset($person['name'])) $notice = trim(inq_fa_norm($notice));
        $pdo->prepare("UPDATE inq_requests SET status = ?, notice = ?, result_json = ?, person_name = ?, father_name = ?, postal_valid = ?, postal_text = ?, finished_at = NOW(), ms = ? WHERE id = ?")
            ->execute([$status, mb_substr((string)$notice, 0, 600), json_encode($res, JSON_UNESCAPED_UNICODE),
                       $person ? mb_substr(trim(($person['name'] ?? '') . ' ' . ($person['family'] ?? '')), 0, 190) ?: null : null, $person ? (($person['fatherName'] ?? '') ?: null) : null,
                       $postal ? ($postal['valid'] === null ? null : ($postal['valid'] ? 1 : 0)) : null, $postal ? mb_substr((string)$postal['message'], 0, 600) : null,
                       (int)round((microtime(true) - $t0) * 1000), $id]);
        return ['ok' => true, 'status' => $status];
    };
    if (inq_get_setting($pdo, 'inq_enabled') !== '1') return $finish('FAIL', 'استعلام‌ها در تنظیمات خاموش است.');
    if ($row['kind'] === 'sanhab') return $finish('FAIL', 'استعلامِ سنهاب با کپچای خودش انجام می‌شود.');
    $prev = json_decode((string)($row['result_json'] ?? ''), true) ?: [];

    // شخصِ حقوقی از s.api.ir (وقتی پاسارگاد نتیجه نداد، یا کاربر «بدونِ پاسارگاد» را زد)
    $legalApi = function ($pxNote = '', array $tl = []) use ($pdo, $row, $finish) {
        require_once __DIR__ . '/_apis.php';
        if (!sapi_ready($pdo, 'sapi_company')) return $finish('FAIL', $pxNote !== '' ? $pxNote : 'کلیدِ s.api.ir در «تنظیمات ← کلیدهای API» وارد نشده است.', ['timeline' => $tl]);
        $r = sapi_company_info($pdo, $row['national_id']);
        if (!$r['ok']) return $finish('FAIL', ($pxNote !== '' ? $pxNote . ' / ' : '') . ($r['error'] ?? 'خطا'), ['raw' => $r['raw'] ?? '', 'timeline' => $tl]);
        $co = $r['company'] + ['source' => 'api'];
        return $finish('OK', ($co['name'] ?: 'شرکت') . ($co['active'] === false ? ' (غیرفعال)' : '') . ' — از سرویسِ API' . ($pxNote !== '' ? ' (پاسارگاد: ' . $pxNote . ')' : ''),
                       ['company' => $co, 'timeline' => $tl, 'via' => 'api'], ['name' => $co['name'], 'family' => '']);
    };
    if ($row['kind'] === 'legal' && (!empty($prev['via_api']) || inq_get_setting($pdo, 'inq_legal_px') !== '1' || inq_creds($pdo)['user'] === '' || !function_exists('curl_init'))) return $legalApi();

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
        $legal = $row['kind'] === 'legal';
        [$y, $m, $d] = $legal ? [0, 0, 0] : array_map('intval', explode('/', $row['birth']));
        $birth = ['y' => $y, 'm' => $m, 'd' => $d];
        $max = $legal ? ($row['postal'] ? 5 : 4) : ($row['kind'] === 'person' ? 3 : 5);
        $company = null; $lpostal = null;
        $wait = max(0, min(9, intval(inq_get_setting($pdo, 'inq_wait_sec'))));
        $px = new InqPasargad($jobDir);
        $last = null; $person = null;
        for ($s = 1; $s <= $max; $s++) {
            $pdo->prepare("UPDATE inq_requests SET notice = ? WHERE id = ?")->execute(['گام ' . $s . ' از ' . $max . '…', $id]);
            $r = $legal ? $px->runLegalStep($s, ['shenasehMelli' => $row['national_id'], 'naam' => '', 'codeEghtesadi' => '', 'postal' => (string)$row['postal']])
                        : $px->runStep($s, $row['national_id'], $birth, (string)$row['postal']);
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
            if (!empty($r['company']) && !empty($r['company']['naam'])) $company = $r['company'];
            if (!empty($r['postal'])) $lpostal = $r['postal'];
            if (empty($r['ok'])) {
                if (($r['error'] ?? '') === 'down') inq_px_mark('down');
                $msg = $r['message'] ?? ($r['detail'] ?? 'خطا در اجرای مرحله');
                if ($legal) { inq_unlock($lk); $lk = null; return $legalApi($msg, $tl); }
                return $finish('FAIL', $msg, ['timeline' => $tl, 'failed_step' => $s, 'birth_mismatch' => !empty($r['birth_mismatch'])], $person);
            }
            $last = $r;
            if ($s < $max && $wait > 0) sleep($wait);
        }
        inq_px_mark('logged_in');
        if ($legal) {
            $co = inq_px_company($company ?: []);
            if ($co['name'] === '') { inq_unlock($lk); $lk = null; return $legalApi('نامِ شرکت از پاسارگاد خوانده نشد', $tl); }
            $res = ['timeline' => $tl, 'company' => $co, 'via' => 'pasargad'];
            if ($lpostal) $res['postal'] = $lpostal;
            $st = $lpostal ? ($lpostal['valid'] === false ? 'WARN' : 'OK') : 'OK';
            return $finish($st, $co['name'] . ($lpostal ? ' — کد پستی: ' . $lpostal['message'] : ''), $res, ['name' => $co['name'], 'family' => ''], $lpostal);
        }
        $res = ['timeline' => $tl, 'person' => $person];
        if ($row['kind'] === 'person') return $finish($person ? 'OK' : 'WARN', $person ? trim($person['name'] . ' ' . $person['family']) : 'مشخصات خوانده نشد', $res, $person);
        $z = $last['postal'] ?? ['valid' => null, 'message' => 'پاسخی دریافت نشد'];
        $res['postal'] = $z;
        $st2 = $z['valid'] === true ? 'OK' : ($z['valid'] === false ? 'FAIL' : 'WARN');
        return $finish($st2, $z['message'], $res, $person, $z);
    } catch (Throwable $e) {
        return $finish('FAIL', 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 200), ['timeline' => $tl]);
    } finally {
        if ($lk) inq_unlock($lk);
    }
}

// شرکتِ خوانده‌شده از پاسارگاد => همان شکلِ خروجیِ s.api.ir (+ فیلدهای بیشتر)
function inq_px_company(array $c) {
    $t = function ($k) use ($c) { return inq_fa_norm(trim((string)($c[$k] ?? ''))); };
    $digits = function ($k) use ($c) { return inq_digits($c[$k] ?? ''); };
    return ['name' => $t('naam'), 'company_type' => trim($t('noeSherkat') . ($t('tabaghebandi') !== '' && $t('tabaghebandi') !== 'پیشفرض' ? ' · ' . $t('tabaghebandi') : '')),
            'national_id' => $digits('shenasehMelli'), 'register_number' => $digits('shomarehSabt'), 'register_date' => inq_digits_fa_date($c['tarikhTasis'] ?? ''), 'active' => null,
            'address' => $t('address'), 'postal_code' => $digits('codePosti'), 'province' => '', 'city' => $t('shahr'), 'end_date' => '',
            'economic_code' => $digits('codeEghtesadi'), 'shenasai' => $digits('shomarehShenasai'), 'phone' => $digits('telefon'), 'mobile' => $digits('mobile'),
            'fax' => $digits('fax'), 'email' => trim((string)($c['email'] ?? '')), 'website' => trim((string)($c['webSite'] ?? '')), 'category' => $t('tabaghebandi'),
            'ownership' => $t('noeSherkat'), 'px_id' => (string)($c['id'] ?? ''), 'source' => 'pasargad'];
}
function inq_digits_fa_date($s) {
    $s = trim((string)$s);
    if ($s === '' || $s === '//' || strpos($s, 'HIJRI') !== false) return '';
    $p = array_values(array_filter(preg_split('/\D+/', strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])), 'strlen'));
    return count($p) === 3 ? sprintf('%04d/%02d/%02d', (int)$p[0], (int)$p[1], (int)$p[2]) : $s;
}

// استعلام‌هایی که بیشتر از ۱۰ دقیقه «در حال اجرا» مانده‌اند (قطعِ درخواست) دوباره به صف برمی‌گردند
function inq_requeue_stale($pdo) {
    try { $pdo->exec("UPDATE inq_requests SET status = 'QUEUED', notice = 'دوباره در صف' WHERE status = 'RUNNING' AND started_at < (NOW() - INTERVAL 10 MINUTE)"); } catch (Throwable $e) {}
}

// خروجیِ یک استعلام برای مرورگر؛ جزئیات (تایم‌لاین، صفحه‌ی گام‌ها، پیام‌های خامِ سامانه) فقط برای مدیر کل
function inq_out(array $r, $full = false) {
    $res = json_decode((string)($r['result_json'] ?? ''), true) ?: [];
    $tl = json_decode((string)($r['timeline_json'] ?? ''), true) ?: ($res['timeline'] ?? []);
    $p = inq_norm_person($res['person'] ?? null);
    $r['person_name'] = $r['person_name'] !== null ? inq_fa_norm($r['person_name']) : null;
    $r['father_name'] = $r['father_name'] !== null ? inq_fa_norm($r['father_name']) : null;
    if (in_array($r['status'], ['OK', 'WARN'], true) && $r['kind'] !== 'postal') $r['notice'] = inq_fa_norm($r['notice']);
    $o = ['id' => intval($r['id']), 'kind' => $r['kind'], 'kind_fa' => INQ_KINDS[$r['kind']] ?? $r['kind'], 'national_id' => $r['national_id'], 'birth' => $r['birth'], 'postal' => $r['postal'],
          'status' => $r['status'], 'status_fa' => INQ_ST_FA[$r['status']] ?? $r['status'], 'done' => in_array($r['status'], ['OK', 'WARN', 'FAIL'], true),
          'person' => $p ? ['name' => $p['name'] ?? '', 'family' => $p['family'] ?? '', 'father' => $p['fatherName'] ?? '', 'gender' => $p['gender'] ?? ''] : null,
          'person_name' => $r['person_name'], 'father_name' => $r['father_name'],
          'postal_valid' => $r['postal_valid'] === null ? null : (bool)$r['postal_valid'], 'postal_text' => $r['postal_text'], 'company' => $res['company'] ?? null, 'via' => $res['via'] ?? null,
          'notice' => $r['notice'], 'cached' => !empty($r['cached_from']), 'source' => $r['source'], 'user_name' => $r['user_name'], 'batch_id' => $r['batch_id'] ? intval($r['batch_id']) : null, 'row_no' => $r['row_no'],
          'created' => $r['created_at'], 'created_j' => jdate_short($r['created_at']) . ' ' . substr((string)$r['created_at'], 11, 5), 'ms' => $r['ms'] !== null ? intval($r['ms']) : null,
          'step' => count($tl), 'steps' => $r['kind'] === 'person' ? 3 : ($r['kind'] === 'legal' ? ($r['postal'] ? 5 : 4) : ($r['kind'] === 'sanhab' ? 1 : 5)), 'need_login' => !empty($res['need_login']), 'birth_mismatch' => !empty($res['birth_mismatch'])];
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
    $w = "status = 'QUEUED' AND kind <> 'sanhab' AND batch_id IS NOT NULL" . ($batchId ? " AND batch_id = " . intval($batchId) : '') . " AND batch_id IN (SELECT id FROM inq_batches WHERE status = 'RUNNING')";
    $id = $pdo->query("SELECT id FROM inq_requests WHERE $w ORDER BY id LIMIT 1")->fetchColumn();
    $ran = null;
    if ($id) {
        $kind = $pdo->query("SELECT kind FROM inq_requests WHERE id = " . intval($id))->fetchColumn();
        if (($kind !== 'legal' || (inq_get_setting($pdo, 'inq_legal_px') === '1' && inq_creds($pdo)['user'] !== '')) && inq_px_status($pdo) === 'logged_out') return ['ran' => null, 'need_captcha' => true, 'batch' => $batchId ? inq_batch_info($pdo, $batchId) : null];
        $r = inq_execute($pdo, intval($id));
        if (!empty($r['need_captcha'])) return ['ran' => null, 'need_captcha' => true, 'batch' => $batchId ? inq_batch_info($pdo, $batchId) : null];
        $ran = intval($id);
    }
    // گروه‌هایی که چیزی در صف ندارند تمام می‌شوند
    $pdo->exec("UPDATE inq_batches b SET status = 'DONE', finished_at = NOW() WHERE status = 'RUNNING' AND kind <> 'sanhab' AND NOT EXISTS (SELECT 1 FROM inq_requests r WHERE r.batch_id = b.id AND r.status IN ('QUEUED', 'RUNNING'))");
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
