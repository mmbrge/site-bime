<?php
// فایل: api/_psg_issue.php
// «پنلِ صدورِ پاسارگاد» (فازِ ۱): ساختِ پیش‌نویسِ ثالث/بدنه در سامانه‌ی پاسارگاد از روی ردیفِ درخواست، با همان نشستِ سرور.
//   اولویت ۱) «کپی از پیش‌نویسِ نمونه»: شماره‌ی پیش‌نویسِ هم‌رشته‌ی همان شرکت (دستی یا پیدا شده از فهرستِ پیش‌نویس‌ها) کپی می‌شود
//             — بیمه‌گذار، پوشش‌ها، تخفیف‌ها و تنظیمات از نمونه می‌آید؛ فقط مشخصاتِ خودروی همین ردیف (و خانه‌های خالی از الگو) پر می‌شود.
//   اولویت ۲) «الگو»: اگر نمونه نبود، فرمِ «ایجاد» با الگوی ذخیره‌شده‌ی همان شرکت/رشته (از آخرین ثبتِ موفق) از ابتدا پر می‌شود.
//   همیشه دو مرحله: «آماده‌سازی» (فرم روی سرور پر می‌شود و فهرستِ تغییرات + پیام‌های سامانه نشان داده می‌شود؛ چیزی ثبت نمی‌شود)
//   و «ثبت» (فقط با تأییدِ کاربر). قاعده‌های ثبت:
//     🟡 هشدارِ زرد  ← همان مقادیر دوباره «ثبت» (چند بار)
//     🔴 خطای قرمز  ← توقف و نمایشِ خطا؛ فقط برای «اطلاعات پایه فعال برای نوع وسیله نقلیه اتاق بار …» نوعِ اتاقِ بار با جایگزین‌ها امتحان
//                     می‌شود و بعد از ثبت، «برگرداندن به نوعِ اصلی» (اصلاحِ پیش‌نویس) پیشنهاد می‌شود
//   بعد از ثبت: شماره‌ی پیش‌نویس روی ردیف («پیش‌نویس شد»)، اعلان، و بارگذاریِ مدارکِ ردیف روی همان پیش‌نویس (بارگذاری فایل).
// نیاز: _inquiry.php (InqPasargad و قفلِ نشست)، _psg_drafts.php، _company_helpers.php

require_once __DIR__ . '/_inquiry.php';
require_once __DIR__ . '/_psg_drafts.php';
require_once __DIR__ . '/_psg_lov.php';
function psgi_s($v) { return is_array($v) ? implode(',', $v) : (string)$v; }

const PSGI_KINDS = [
    'third' => ['label' => 'ثالث', 'list' => '/cins/bimehShakhsSalesPishnevis.list.html', 'post' => '/cins/bimehshakhssalespishnevis/bimehShakhsSalesPishnevis.simplelist.html',
                'form' => '/cins/bimehshakhssalespishnevis/bimehShakhsSalesPishnevis.simpleform.html', 'bean' => 'bimehShakhsSalesManager'],
    'body'  => ['label' => 'بدنه', 'list' => '/cins/bimehBadanehPishnevis.list.html', 'post' => '/cins/bimehbadanehpishnevis/bimehBadanehPishnevis.simplelist.html',
                'form' => '/cins/bimehbadanehpishnevis/bimehBadanehPishnevis.simpleform.html', 'bean' => 'bimehBadanehManager'],
];
// خانه‌هایی که مالِ «همین ردیف»اند و هرگز در الگو نمی‌روند
const PSGI_ROW_KEYS = ['shomarehEntezami', 'shomarehEntezami1', 'shomarehEntezami2', 'shomarehEntezami3', 'seryalPelak', 'seryalPelak_inputHidden', 'vin', 'shomarehShasi', 'shomarehMotor',
                       'salSakht', 'rang', 'rang_inputHidden', 'arzeshVasilehNaghliyeh', 'shomarehBimehnamehGhabli', 'codeYektaBimehGhabli', 'token', 'autoScroll', 'javax.faces.ViewState',
                       'editform:_idcl', 'editform_SUBMIT', 'editform:tarikhShoroo', 'editform:tarikhShoroo_y', 'editform:tarikhShoroo_m', 'editform:tarikhShoroo_d',
                       'editform:tarikhPayan', 'editform:tarikhPayan_y', 'editform:tarikhPayan_m', 'editform:tarikhPayan_d', 'editform:tarikhPishnahadNameh',
                       'editform:tarikhPishnahadNameh_y', 'editform:tarikhPishnahadNameh_m', 'editform:tarikhPishnahadNameh_d', 'editform:tarikhShorooGhabli', 'editform:tarikhShorooGhabli_y',
                       'editform:tarikhShorooGhabli_m', 'editform:tarikhShorooGhabli_d', 'editform:tarikhEnghezaGhabli', 'editform:tarikhEnghezaGhabli_y', 'editform:tarikhEnghezaGhabli_m',
                       'editform:tarikhEnghezaGhabli_d', 'editform:scanMadarek'];
// جایگزین‌های «اتاق بار» وقتی سامانه برای نوعِ اصلی «اطلاعات پایه فعال» ندارد (به همین ترتیب؛ آنچه قبلاً جواب داده اول)
const PSGI_CARGO_ALTS = ['22', '45', '44', '21', '35', '13', '24', '46'];
const PSGI_FIELD_FA = ['shomarehEntezami1' => 'پلاک (سه رقم)', 'shomarehEntezami2' => 'پلاک (حرف)', 'shomarehEntezami3' => 'پلاک (دو رقم)', 'seryalPelak' => 'سریالِ پلاک (ایران)',
                       'vin' => 'VIN', 'shomarehShasi' => 'شماره شاسی', 'shomarehMotor' => 'شماره موتور', 'salSakht' => 'سال ساخت', 'rang' => 'رنگ', 'arzeshVasilehNaghliyeh' => 'ارزشِ خودرو',
                       'shomarehBimehnamehGhabli' => 'شماره بیمه‌نامه‌ی قبلی', 'otaghBar' => 'اتاق بار', 'noePelak' => 'نوع پلاک', 'moredEstefadehKhodro' => 'موردِ استفاده',
                       'editform:nerkhTip' => 'تیپ', 'editform:nerkhTip.desc' => 'تیپ', 'editform:tarikhShoroo' => 'تاریخ شروع', 'editform:tarikhPayan' => 'تاریخ پایان',
                       'editform:tarikhPishnahadNameh' => 'تاریخ پیشنهاد'];

function psgi_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS psg_issue_jobs (id INT AUTO_INCREMENT PRIMARY KEY, plate_id INT NULL, request_id INT NULL, company_id INT NULL, kind VARCHAR(6) NOT NULL,
            mode VARCHAR(10) NOT NULL, sample_no VARCHAR(60) NULL, status VARCHAR(12) NOT NULL DEFAULT 'PREPARED', draft_no VARCHAR(60) NULL, slot INT NOT NULL DEFAULT 0,
            notices_json MEDIUMTEXT NULL, changes_json MEDIUMTEXT NULL, cargo_used VARCHAR(12) NULL, cargo_orig VARCHAR(12) NULL, message VARCHAR(600) NULL,
            docs_json MEDIUMTEXT NULL, user_id INT NULL, user_name VARCHAR(190) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, finished_at DATETIME NULL,
            KEY idx_plate (plate_id), KEY idx_req (request_id), KEY idx_done (status, finished_at))$T",
        "CREATE TABLE IF NOT EXISTS psg_issue_templates (id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, kind VARCHAR(6) NOT NULL, car_key VARCHAR(120) NOT NULL DEFAULT '',
            fields_json MEDIUMTEXT NOT NULL, source_draft VARCHAR(60) NULL, updated_by VARCHAR(190) NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_t (company_id, kind, car_key))$T",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[psgi_ensure] ' . $e->getMessage()); } }
    try {
        if (!$pdo->query("SHOW COLUMNS FROM company_request_plates LIKE 'psg_draft_no'")->fetch())
            $pdo->exec("ALTER TABLE company_request_plates ADD COLUMN psg_draft_no VARCHAR(60) NULL, ADD COLUMN psg_draft_at DATETIME NULL, ADD COLUMN psg_draft_job INT NULL");
    } catch (Throwable $e) { error_log('[psgi_ensure cols] ' . $e->getMessage()); }
}
function psgi_party_key($k) { return (bool)preg_match('/^(?:editform:)?(?:shakhsHaghighi_|shakhsHoghooghi_|shakhs_|moshakhasatOmumi_|isBimehgozar|isTarafAsli|isPardakhtKonandeh|noeShakhs|noeTarafGharardad)|^editform:(?:TarafGharardad|isBimehgozar|isTarafAsli|isPardakhtKonandeh|noeShakhs|noeTarafGharardad)|^tarafDovvom/', $k); }
function psgi_dir() { $d = inq_dir('psg_issue'); return $d; }
function psgi_fa_norm($s) { return trim(preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك', 'ى', "\u{200C}"], ['ی', 'ک', 'ی', ' '], (string)$s))); }
function psgi_car_key($s) { return mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', psgi_fa_norm(mb_strtolower((string)$s))), 0, 120); }
function psgi_kind_of($insType) { return $insType === 'BODY' ? 'body' : 'third'; }

class PsgIssue extends PsgDrafts
{
    public $log = [];
    protected function url($kind, $k) { return $this->core . PSGI_KINDS[$kind][$k]; }
    public function notices($html) { return $this->parseNotices($html); }
    public function formFields($html) { return psgc_fields($html, 'editform'); }
    // ارسالِ فرم مثلِ مرورگر: چند مقدار (چند «طرح» با یک نام) و multipart/urlencoded طبقِ خودِ فرم
    protected function submit($url, $html, $overrides, $clickedId, $referer = '')
    {
        $formId = strpos($clickedId, ':') !== false ? explode(':', $clickedId, 2)[0] : 'editform';
        $f = psgc_fields($html, $formId);
        foreach ($overrides as $k => $v) { if ($v === null || $v === []) unset($f[$k]); else $f[$k] = is_array($v) ? array_values(array_map('strval', $v)) : (string)$v; }
        if (array_key_exists('autoScroll', $f) && $f['autoScroll'] === '') $f['autoScroll'] = '0,0';
        $vs = $f['javax.faces.ViewState'] ?? null;
        unset($f[$formId . '_SUBMIT'], $f['javax.faces.ViewState'], $f[$formId . ':_idcl'], $f[$formId . ':_link_hidden_']);
        $f[$formId . '_SUBMIT'] = '1';
        if ($vs !== null) $f['javax.faces.ViewState'] = $vs;
        $f[$formId . ':_idcl'] = $clickedId;
        $tag = psgc_form($html, $formId)['tag'];
        [$body, $ct] = psgc_encode($f, $tag === '' || stripos($tag, 'multipart') !== false);
        return $this->http('POST', $url, $body, $referer ?: $url, true, [$ct]);
    }
    public function loggedOutPub($html) { return $this->loggedOut($html); }
    public function isForm($html) { return strpos($html, 'editform:save') !== false && strpos($html, 'editform') !== false; }
    public function isList($html) { return strpos($html, 'exampleform') !== false && strpos($html, 'editform:save') === false; }

    // گزینه‌های یک <select>: [value => label]
    public function options($html, $name) {
        $o = [];
        if (!preg_match('/<select\b[^>]*\bname=["\']' . preg_quote($name, '/') . '["\'][^>]*>(.*?)<\/select>/is', $html, $m)) return $o;
        if (preg_match_all('/<option\b[^>]*\bvalue=["\']([^"\']*)["\'][^>]*>([^<]*)/is', $m[1], $mm, PREG_SET_ORDER)) foreach ($mm as $x) $o[$this->decodeHtml($x[1])] = trim($this->decodeHtml($x[2]));
        return $o;
    }
    // برچسبِ فارسیِ هر خانه (از <label for>) برای نمایشِ تغییرات
    public function labels($html) {
        $l = [];
        if (preg_match_all('/<label\b[^>]*\bfor=["\']([^"\']+)["\'][^>]*>(.*?)<\/label>/is', $html, $mm, PREG_SET_ORDER))
            foreach ($mm as $x) { $t = trim(preg_replace('/\s+/u', ' ', strip_tags($this->decodeHtml($x[2])))); if ($t !== '') $l[$this->decodeHtml($x[1])] = $t; }
        return $l;
    }

    // فهرستِ پیش‌نویس‌ها؛ اگر شماره داده شود فقط همان (جستجوی «شماره پیش نویس» از ۱۴۰۰/۰۱/۰۱)
    public function openList($kind, $draftNo = '') {
        $r = $this->http('GET', $this->url($kind, 'list'));
        if ($r['body'] === '' && $r['error'] !== '') return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد در دسترس نیست (' . $r['error'] . ').'];
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        if (strpos($r['body'], 'exampleform') === false) return ['ok' => false, 'error' => 'صفحه‌ی پیش‌نویس‌ها باز نشد.'];
        if ($draftNo === '') return ['ok' => true, 'html' => $r['body']];
        $ov = ['exampleform:gharardadBimeh_shomarehPishnevis' => $draftNo, 'exampleform:tarikhSodoor_ge' => '1400/01/01;HIJRI', 'exampleform:tarikhSodoor_ge_y' => '1400',
               'exampleform:tarikhSodoor_ge_m' => '01', 'exampleform:tarikhSodoor_ge_d' => '01', 'exampleform:fieldset:dataTable:goto' => '1'];
        $r2 = $this->submit($this->url($kind, 'post'), $r['body'], $ov, 'exampleform:search', $this->url($kind, 'list'));
        if ($this->loggedOut($r2['body'])) return ['ok' => false, 'need_login' => true];
        if (strpos($r2['body'], 'exampleform') === false) return ['ok' => false, 'error' => 'جستجوی پیش‌نویس انجام نشد.'];
        return ['ok' => true, 'html' => $r2['body']];
    }
    public function rowIndex($html, $kind, $draftNo) {
        foreach (psgd_parse_rows($html, $kind) as $i => $r) if (trim($r['draft_no']) === trim($draftNo)) return $i;
        return null;
    }
    // یک دکمه‌ی ردیفِ فهرست: copyPishnevis | editCommand | editMadarek | viewCommand
    public function rowAction($kind, $draftNo, $act) {
        $l = $this->openList($kind, $draftNo);
        if (empty($l['ok'])) return $l;
        $i = $this->rowIndex($l['html'], $kind, $draftNo);
        if ($i === null) return ['ok' => false, 'error' => 'پیش‌نویسِ ' . $draftNo . ' در سامانه پیدا نشد.'];
        $id = 'exampleform:fieldset:dataTable:' . $i . ':' . $act;
        if (strpos($l['html'], $id) === false) return ['ok' => false, 'error' => 'این کار برای پیش‌نویسِ ' . $draftNo . ' در سامانه در دسترس نیست (شاید مرحله‌اش گذشته).'];
        $r = $this->submit($this->url($kind, 'post'), $l['html'], [], $id, $this->url($kind, 'post'));
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        return ['ok' => true, 'html' => $r['body'], 'list' => $l['html']];
    }
    public function openCreate($kind) {
        $l = $this->openList($kind);
        if (empty($l['ok'])) return $l;
        if (strpos($l['html'], 'exampleform:create') === false) return ['ok' => false, 'error' => 'دکمه‌ی «ایجاد» در سامانه نیست.'];
        $r = $this->submit($this->url($kind, 'post'), $l['html'], [], 'exampleform:create', $this->url($kind, 'list'));
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        if (!$this->isForm($r['body'])) return ['ok' => false, 'error' => 'فرمِ ایجادِ پیش‌نویس باز نشد.'];
        return ['ok' => true, 'html' => $r['body']];
    }
    // یک کلیکِ میانی روی فرم (مثلاً «استعلام بیمه‌نامه‌ی قبلی»، حذف/افزودنِ طرف قرارداد)
    public function click($kind, $html, array $ov, $id) {
        $r = $this->submit($this->url($kind, 'form'), $html, $ov, $id, $this->url($kind, 'form'));
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        return ['ok' => $this->isForm($r['body']), 'html' => $r['body'], 'error' => $this->isForm($r['body']) ? '' : 'فرم بعد از «' . $id . '» برنگشت.'];
    }
    // طرف قرارداد = بیمه‌گذارِ حقیقی با کد ملی (بارگذاری از سوابقِ سامانه + افزودن به لیست)
    public function setPersonParty($kind, $html, $nid) {
        $this->formUrl = $this->url($kind, 'form');
        $cm = $this->callLoadCodeMelli($nid);
        if (!$cm['found']) return ['ok' => false, 'error' => 'کد ملیِ ' . $nid . ' در سوابقِ سامانه‌ی پاسارگاد نیست؛ یک بار «استعلامِ شخص» بزنید یا پیش‌نویسِ نمونه‌ی همین بیمه‌گذار را بدهید.'];
        if (preg_match('/editform:TarafGharardad:0:delete/', $html)) {
            $d = $this->click($kind, $html, [], 'editform:TarafGharardad:0:delete');
            if (empty($d['ok'])) return $d;
            $html = $d['html'];
        }
        return $this->click($kind, $html, $cm['fields'], 'editform:addTotarafGharardadList');
    }

    // «ثبت» با قاعده‌ها؛ $cargoAlts: جایگزین‌های اتاقِ بار (فقط برای خطای اتاقِ بار)
    public function save($kind, $html, array $ov, array $cargoAlts = [], $maxTry = 7) {
        $notes = []; $cargoUsed = null; $tried = [];
        for ($t = 1; $t <= $maxTry; $t++) {
            $r = $this->submit($this->url($kind, 'form'), $html, $ov, 'editform:save', $this->url($kind, 'form'));
            if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true, 'notes' => $notes];
            $body = $r['body'];
            if ($body === '') return ['ok' => false, 'error' => 'جوابی از سامانه نیامد' . ($r['error'] ? ' (' . $r['error'] . ')' : '') . '.', 'notes' => $notes];
            $n = $this->parseNotices($body);
            $notes[] = ['try' => $t, 'warnings' => $n['warnings'], 'errors' => $n['errors'], 'messages' => $n['messages']];
            if ($this->isList($body)) return ['ok' => true, 'list' => $body, 'notes' => $notes, 'cargo_used' => $cargoUsed, 'tries' => $t];
            if ($n['errors']) {
                $cargoErr = (bool)preg_match('/اتاق\s*بار|اطاق\s*بار/u', implode(' ', $n['errors']));
                $f = $this->fields($body, 'editform');
                if ($cargoErr && array_key_exists('otaghBar', $f)) {
                    $tried[(string)($ov['otaghBar'] ?? $f['otaghBar'])] = 1;
                    $next = null; foreach ($cargoAlts as $c) if (!isset($tried[(string)$c])) { $next = (string)$c; break; }
                    if ($next !== null) { $ov['otaghBar'] = $next; $cargoUsed = $next; $html = $body; continue; }
                }
                return ['ok' => false, 'red' => true, 'error' => implode(' — ', $n['errors']), 'notes' => $notes, 'html' => $body];
            }
            // فقط هشدارِ زرد (یا بدونِ پیام): همان مقادیر دوباره
            $html = $body;
        }
        return ['ok' => false, 'error' => 'بعد از ' . fa_num_s($maxTry) . ' بار «ثبت»، پیش‌نویس ثبت نشد (آخرین پیام: ' . implode(' — ', end($notes)['warnings'] ?? []) . ').', 'notes' => $notes, 'html' => $html];
    }

    // بارگذاریِ فایل روی پیش‌نویس («بارگذاری فایل»)
    public function upload($kind, $draftNo, array $files) {
        $a = $this->rowAction($kind, $draftNo, 'editMadarek');
        if (empty($a['ok'])) return $a;
        if (!preg_match('#uploadScanMadarek\?bean=(\w+)&(?:amp;)?entityId=(\d+)#', $a['html'], $m)) return ['ok' => false, 'error' => 'صفحه‌ی «بارگذاری فایل» شناخته نشد.'];
        $url = $this->core . '/cins/uploadScanMadarek?bean=' . $m[1] . '&entityId=' . $m[2] . '&action=POST';
        $out = [];
        foreach ($files as $f) {
            if (!is_file($f['path'])) { $out[] = ['name' => $f['name'], 'ok' => false, 'error' => 'فایل روی سرور نیست']; continue; }
            $mime = function_exists('mime_content_type') ? (mime_content_type($f['path']) ?: 'application/octet-stream') : 'application/octet-stream';
            $r = $this->http('POST', $url, ['file.0' => new CURLFile($f['path'], $mime, $f['name'])], $this->url($kind, 'post'), true, ['Accept: application/json, text/javascript, */*; q=0.01', 'X-Requested-With: XMLHttpRequest']);
            $j = json_decode((string)$r['body'], true);
            $out[] = ['name' => $f['name'], 'ok' => is_array($j) && !empty($j[0]['name']), 'error' => is_array($j) ? '' : mb_substr(strip_tags((string)$r['body']), 0, 120)];
        }
        return ['ok' => true, 'files' => $out];
    }
}
function fa_num_s($n) { return strtr((string)$n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }

// ---- داده‌ی ردیف ← خانه‌های فرمِ پاسارگاد ----
function psgi_row($pdo, $plateId) {
    if (!function_exists('issue_info_decode')) require_once __DIR__ . '/_company_helpers.php';
    $st = $pdo->prepare("SELECT p.*, r.company_id, r.insurer, r.insured_json AS req_insured_json, c.name AS company_name, c.national_id AS company_nid, c.economic_code
                         FROM company_request_plates p JOIN company_requests r ON r.id = p.request_id JOIN companies c ON c.id = r.company_id WHERE p.id = ?");
    $st->execute([intval($plateId)]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['_info'] = issue_info_decode($r['issue_info'] ?? null);
    // سال/رنگِ خوانده‌شده از مدارک اگر در اطلاعاتِ صدور نیست
    $ocr = json_decode((string)($r['ocr_extracted_data'] ?? ''), true) ?: [];
    foreach (['car_model_year' => 'model_year', 'car_color' => 'color', 'prev_policy_number' => 'prev_policy_number'] as $to => $from)
        if (trim((string)($r['_info'][$to] ?? '')) === '' && trim((string)($ocr[$from] ?? '')) !== '') $r['_info'][$to] = (string)$ocr[$from];
    $ins = json_decode((string)($r['insured_json'] ?: $r['req_insured_json']), true) ?: [];
    $nid = preg_replace('/\D/', '', p2e_digits((string)($ins['national_id'] ?? $ins['nid'] ?? '')));
    if ($nid === '') $nid = preg_replace('/\D/', '', p2e_digits((string)$r['company_nid']));
    $r['_insured_nid'] = $nid;
    $r['_insured_name'] = (string)($ins['name'] ?? $ins['full_name'] ?? $r['company_name']);
    return $r;
}
function psgi_jdate_parts($ts) { [$y, $m, $d] = jalali_from_gregorian_ts($ts); return [sprintf('%04d', $y), sprintf('%02d', $m), sprintf('%02d', $d)]; }
// overrides برای مشخصاتِ خودرو/تاریخ‌ها؛ $html برای پیدا کردنِ گزینه‌ها (حرفِ پلاک، سریال، رنگ)
function psgi_row_overrides(PsgIssue $c, $html, array $row, $kind) {
    $f = $c->formFields($html);
    $ov = []; $miss = [];
    $has = function ($k) use ($f, $html) { return array_key_exists($k, $f) || strpos($html, 'name="' . $k . '"') !== false; };
    $p2 = trim(p2e_digits((string)$row['plate_p2'])); $p4 = trim(p2e_digits((string)$row['plate_p4'])); $p1 = trim(p2e_digits((string)$row['plate_p1'])); $let = psgi_fa_norm($row['plate_letter']);
    if ($p2 !== '' && $p4 !== '' && $has('shomarehEntezami1')) {
        $ov['shomarehEntezami1'] = $p2; $ov['shomarehEntezami3'] = $p4;
        $lid = null; foreach ($c->options($html, 'shomarehEntezami2') as $v => $l) if ($v !== '' && psgi_fa_norm($l) === $let) { $lid = $v; break; }
        if ($lid !== null) { $ov['shomarehEntezami2'] = $lid; $ov['shomarehEntezami'] = $p2 . $let . $p4; } else $miss[] = 'حرفِ پلاک «' . $let . '» در فهرستِ سامانه نیست';
        if ($p1 !== '') {
            $sid = null; foreach ($c->options($html, 'seryalPelak') as $v => $l) if ($v !== '' && preg_match('/^0*' . intval($p1) . '\s*\(ایران\)/u', psgi_fa_norm($l))) { $sid = $v; break; }
            if ($sid !== null) $ov['seryalPelak'] = $sid; else $miss[] = 'سریالِ پلاکِ «' . $p1 . '»';
        }
    }
    $info = $row['_info'];
    $chassis = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$row['chassis_no']));
    $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($row['vin'] ?: '')));
    if ($chassis !== '' && $has('shomarehShasi')) $ov['shomarehShasi'] = $chassis;
    // VIN: خودِ VIN؛ اگر نیست و شاسی ۱۷ نویسه‌ای (همان VIN) است، شاسی
    if ($has('vin') && ($vin !== '' || strlen($chassis) === 17)) $ov['vin'] = $vin !== '' ? $vin : $chassis;
    $eng = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$row['engine_no']));
    if ($eng !== '' && $has('shomarehMotor')) $ov['shomarehMotor'] = $eng;
    $yr = preg_replace('/\D/', '', p2e_digits((string)($info['car_model_year'] ?? '')));
    if (strlen($yr) === 4 && $has('salSakht')) $ov['salSakht'] = $yr;
    $col = psgi_fa_norm($info['car_color'] ?? '');
    if ($col !== '' && $has('rang')) {
        $best = null;
        foreach ($c->options($html, 'rang') as $v => $l) { if ($v === '') continue; $ln = psgi_fa_norm($l); if ($ln === $col) { $best = $v; break; } if ($best === null && mb_strpos($ln, $col) === 0) $best = $v; }
        if ($best !== null) $ov['rang'] = $best; else $miss[] = 'رنگِ «' . $col . '»';
    }
    if ($kind === 'body' && intval($row['car_value']) > 0 && $has('arzeshVasilehNaghliyeh')) $ov['arzeshVasilehNaghliyeh'] = number_format(intval($row['car_value']));
    // تاریخ‌ها: از امروز تا یک سال بعد (بیمه‌نامه‌ی تازه)
    [$y, $m, $d] = psgi_jdate_parts(time());
    if ($has('editform:tarikhShoroo')) $ov += ['editform:tarikhShoroo' => "$y/$m/$d 00:00:00;HIJRI", 'editform:tarikhShoroo_y' => $y, 'editform:tarikhShoroo_m' => $m, 'editform:tarikhShoroo_d' => $d];
    if ($has('editform:tarikhPayan')) { $y2 = sprintf('%04d', intval($y) + 1); $ov += ['editform:tarikhPayan' => "$y2/$m/$d;HIJRI", 'editform:tarikhPayan_y' => $y2, 'editform:tarikhPayan_m' => $m, 'editform:tarikhPayan_d' => $d]; }
    if ($has('editform:tarikhPishnahadNameh')) $ov += ['editform:tarikhPishnahadNameh' => "$y/$m/$d 00:00:00;HIJRI", 'editform:tarikhPishnahadNameh_y' => $y, 'editform:tarikhPishnahadNameh_m' => $m, 'editform:tarikhPishnahadNameh_d' => $d];
    $prev = trim(p2e_digits((string)($info['prev_policy_number'] ?? '') ?: (string)$row['ref_policy_number']));
    return ['ov' => $ov, 'miss' => $miss, 'prev' => $prev];
}

// الگو: آخرین مقادیرِ موفقِ همان شرکت/رشته (و خودرو اگر هست)
function psgi_template_get($pdo, $companyId, $kind, $carName) {
    psgi_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM psg_issue_templates WHERE company_id = ? AND kind = ? AND car_key IN (?, '') ORDER BY (car_key = '') ASC, updated_at DESC LIMIT 1");
    $st->execute([intval($companyId), $kind, psgi_car_key($carName)]);
    $t = $st->fetch();
    return $t ? ['fields' => json_decode((string)$t['fields_json'], true) ?: [], 'car_key' => $t['car_key'], 'source' => $t['source_draft'], 'at' => $t['updated_at']] : null;
}
function psgi_template_save($pdo, $companyId, $kind, $carName, array $fields, $draftNo, $by, array $exclude = []) {
    psgi_ensure($pdo);
    $skip = array_flip(array_merge(PSGI_ROW_KEYS, $exclude));
    $keep = [];
    foreach ($fields as $k => $v) { if (isset($skip[$k]) || strpos($k, 'exampleform') === 0 || preg_match('/_inputHidden$/', $k) && isset($skip[substr($k, 0, -12)])) continue; $keep[$k] = is_array($v) ? array_values(array_map('strval', $v)) : (string)$v; }
    $q = $pdo->prepare("INSERT INTO psg_issue_templates (company_id, kind, car_key, fields_json, source_draft, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE fields_json = VALUES(fields_json), source_draft = VALUES(source_draft), updated_by = VALUES(updated_by), updated_at = NOW()");
    foreach (array_unique([psgi_car_key($carName), '']) as $ck) $q->execute([intval($companyId), $kind, $ck, json_encode($keep, JSON_UNESCAPED_UNICODE), $draftNo, mb_substr((string)$by, 0, 190)]);
}
// پیش‌نویسِ نمونه‌ی هم‌رشته‌ی همین شرکت (از فهرستِ پیش‌نویس‌های خوانده‌شده)؛ همان خودرو اول
function psgi_suggest_sample($pdo, array $row, $kind) {
    psgd_ensure($pdo);
    $name = psgi_fa_norm($row['_insured_name'] ?: $row['company_name']);
    if (mb_strlen($name) < 3) return null;
    $st = $pdo->prepare("SELECT draft_no, insured, car, created_j FROM psg_drafts WHERE kind = ? AND stale = 0 ORDER BY created_j DESC, id DESC LIMIT 3000");
    $st->execute([$kind]);
    $car = psgi_car_key($row['car_name']); $best = null;
    foreach ($st->fetchAll() as $d) {
        $in = psgi_fa_norm($d['insured']);
        if ($in === '' || (mb_strpos($in, $name) === false && mb_strpos($name, $in) === false)) continue;
        if ($car !== '' && psgi_car_key($d['car']) !== '' && (mb_strpos(psgi_car_key($d['car']), $car) !== false || mb_strpos($car, psgi_car_key($d['car'])) !== false)) return $d + ['same_car' => true];
        if ($best === null) $best = $d + ['same_car' => false];
    }
    return $best;
}
function psgi_cargo_alts($pdo, $tip) {
    $alts = PSGI_CARGO_ALTS;
    try { $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $st->execute(['psgi_cargo_' . preg_replace('/\D/', '', (string)$tip)]); $ok = (string)$st->fetchColumn(); if ($ok !== '') array_unshift($alts, $ok); } catch (Throwable $e) {}
    return array_values(array_unique($alts));
}
function psgi_cargo_learn($pdo, $tip, $val) {
    if ($tip === '' || $val === '') return;
    try { $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute(['psgi_cargo_' . preg_replace('/\D/', '', (string)$tip), (string)$val]); } catch (Throwable $e) {}
}

function psgi_job($pdo, $id) { $st = $pdo->prepare("SELECT * FROM psg_issue_jobs WHERE id = ?"); $st->execute([intval($id)]); return $st->fetch() ?: null; }
function psgi_job_out($j) {
    if (!$j) return null;
    return ['id' => (int)$j['id'], 'plate_id' => (int)$j['plate_id'], 'kind' => $j['kind'], 'mode' => $j['mode'], 'sample' => $j['sample_no'], 'status' => $j['status'], 'draft_no' => $j['draft_no'],
            'notices' => json_decode((string)$j['notices_json'], true) ?: [], 'changes' => json_decode((string)$j['changes_json'], true) ?: [], 'cargo_used' => $j['cargo_used'], 'cargo_orig' => $j['cargo_orig'],
            'message' => $j['message'], 'docs' => json_decode((string)$j['docs_json'], true) ?: [], 'by' => $j['user_name'], 'at' => psgd_jdt($j['created_at']), 'done_at' => psgd_jdt($j['finished_at'])];
}
function psgi_lock_for($slot = null, $wait = 60) { return $slot !== null && px_pool_size() > 1 ? inq_lock_slot(intval($slot), $wait) : inq_lock($wait); }

// ---- آماده‌سازی: فرم روی سرور پر می‌شود و فهرستِ تغییرات برمی‌گردد (چیزی ثبت نمی‌شود) ----
function psgi_prepare($pdo, $plateId, array $opt, array $actor) {
    psgi_ensure($pdo);
    $row = psgi_row($pdo, $plateId);
    if (!$row) return ['ok' => false, 'error' => 'ردیف پیدا نشد.'];
    if (($row['insurer'] ?: 'PASARGAD') !== 'PASARGAD') return ['ok' => false, 'error' => 'بیمه‌گرِ این درخواست پاسارگاد نیست.'];
    if (!empty($row['psg_draft_no']) && empty($opt['again'])) return ['ok' => false, 'exists' => $row['psg_draft_no'], 'error' => 'برای این ردیف قبلاً پیش‌نویسِ ' . $row['psg_draft_no'] . ' ثبت شده.'];
    return psgi_prepare_row($pdo, $row, $opt, $actor);
}
// ساختِ پیش‌نویس بدونِ ردیفِ درخواست (از بخشِ «پیش‌نویس‌ها»): اطلاعات دستی؛ بعد از ثبت اگر با ردیفی یکی بود خودش وصل می‌شود
function psgi_manual_row($pdo, array $d) {
    $kind = ($d['kind'] ?? '') === 'body' ? 'body' : 'third';
    $cid = intval($d['company_id'] ?? 0); $cname = '';
    if ($cid) { try { $q = $pdo->prepare("SELECT name, national_id FROM companies WHERE id = ?"); $q->execute([$cid]); if ($c = $q->fetch()) { $cname = $c['name']; if (empty($d['insured_nid'])) $d['insured_nid'] = $c['national_id']; } } catch (Throwable $e) {} }
    $cl = function ($k) use ($d) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)($d[$k] ?? '')))); };
    return ['id' => 0, 'request_id' => null, 'company_id' => $cid, 'company_name' => $cname, 'insurer' => 'PASARGAD', 'insurance_type' => $kind === 'body' ? 'BODY' : 'THIRDPARTY',
            'car_name' => trim((string)($d['car_name'] ?? '')), 'plate_p1' => p2e_digits(trim((string)($d['p1'] ?? ''))), 'plate_p2' => p2e_digits(trim((string)($d['p2'] ?? ''))),
            'plate_letter' => trim((string)($d['letter'] ?? '')), 'plate_p4' => p2e_digits(trim((string)($d['p4'] ?? ''))), 'chassis_no' => $cl('chassis'), 'vin' => $cl('vin'), 'engine_no' => $cl('engine'),
            'car_value' => intval(preg_replace('/\D/', '', p2e_digits((string)($d['value'] ?? '')))), 'ref_policy_number' => trim(p2e_digits((string)($d['prev'] ?? ''))),
            '_info' => ['car_model_year' => p2e_digits(trim((string)($d['year'] ?? ''))), 'car_color' => trim((string)($d['color'] ?? ''))],
            '_insured_nid' => preg_replace('/\D/', '', p2e_digits((string)($d['insured_nid'] ?? ''))), '_insured_name' => $cname, 'psg_draft_no' => null];
}
function psgi_prepare_row($pdo, array $row, array $opt, array $actor) {
    psgi_ensure($pdo);
    $plateId = intval($row['id'] ?? 0);
    $kind = psgi_kind_of($row['insurance_type']);
    $sample = trim(p2e_digits((string)($opt['sample'] ?? '')));
    $mode = $sample !== '' ? 'copy' : 'template';
    $tpl = psgi_template_get($pdo, $row['company_id'], $kind, $row['car_name']);
    if ($mode === 'template' && !$tpl) return ['ok' => false, 'error' => 'برای این شرکت/رشته هنوز الگو نیست؛ شماره‌ی یک پیش‌نویسِ نمونه (هم‌رشته‌ی همین شرکت) بدهید تا از رویش کپی شود و الگو یاد گرفته شود.'];
    $lk = psgi_lock_for(null, 60);
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    $slot = px_slot();
    try {
        @set_time_limit(240);
        $cls = $GLOBALS['PSGI_CLASS'] ?? 'PsgIssue'; $c = new $cls();   // (آزمون: کلاسِ جایگزین)
        // کپی: اول خودِ نمونه (فقط خواندن، بدونِ ثبت) تا مشخصاتِ نوعِ خودرو/تیپ/اتاقِ بار… که «کپی» نمی‌آورد، از آن برداشته شود
        $sampleF = [];
        if ($mode === 'copy') {
            $v = $c->rowAction($kind, $sample, 'editCommand');
            if (!empty($v['need_login'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید.']; }
            if (empty($v['ok'])) return ['ok' => false, 'error' => $v['error'] ?? 'پیش‌نویسِ نمونه باز نشد.'];
            if ($c->isForm($v['html'])) $sampleF = $c->formFields($v['html']);
        }
        $o = $mode === 'copy' ? $c->rowAction($kind, $sample, 'copyPishnevis') : $c->openCreate($kind);
        if (!empty($o['need_login'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید.']; }
        if (empty($o['ok'])) return ['ok' => false, 'error' => $o['error'] ?? 'فرم باز نشد.'];
        $html = $o['html'];
        if (!$c->isForm($html)) return ['ok' => false, 'error' => 'فرمِ پیش‌نویس باز نشد (' . $c->notices($html)['summaryText'] . ').'];
        $before = $c->formFields($html);
        $notes = [];
        // بیمه‌گذار: کپی => همان بیمه‌گذارِ نمونه؛ اگر کد ملی/شناسه‌ی ردیف در جدولِ طرف قرارداد نیست و حقیقی است => عوض
        $nid = $row['_insured_nid'];
        $tarafTxt = preg_match('/<tbody\b[^>]*id=["\']editform:TarafGharardad:tbody_element["\'][^>]*>(.*?)<\/tbody>/is', $html, $tm) ? strip_tags($tm[1]) : '';
        $partyOk = $nid !== '' && strpos(p2e_digits($tarafTxt), $nid) !== false;
        if (!$partyOk && strlen($nid) === 10) {
            $p = $c->setPersonParty($kind, $html, $nid);
            if (empty($p['ok'])) return ['ok' => false, 'error' => $p['error'] ?? 'بیمه‌گذار عوض نشد.'];
            $html = $p['html']; $notes[] = 'بیمه‌گذار با کد ملیِ ' . $nid . ' جایگزین شد.';
        } elseif (!$partyOk && $mode === 'template' && !empty($tpl['fields']['shakhsHoghooghi_ID'])) {
            $pf = array_intersect_key($tpl['fields'], array_flip(array_filter(array_keys($tpl['fields']), function ($k) { return strpos($k, 'shakhsHoghooghi_') === 0 || strpos($k, 'moshakhasatOmumi_') === 0 || in_array($k, ['editform:noeShakhs', 'editform:isBimehgozar', 'editform:isTarafAsli', 'editform:isPardakhtKonandeh', 'editform:shakhsHoghooghi_tarikhTasis', 'editform:moshakhasatOmumi_shahr', 'editform:moshakhasatOmumi_shahr.desc'], true); })));
            $p = $c->click($kind, $html, $pf, 'editform:addTotarafGharardadList');
            if (empty($p['ok'])) return ['ok' => false, 'error' => 'بیمه‌گذارِ الگو به فرم اضافه نشد.'];
            $html = $p['html']; $notes[] = 'بیمه‌گذارِ حقوقی از الگو اضافه شد.';
        } elseif (!$partyOk && $nid !== '') $notes[] = '⚠️ شناسه‌ی بیمه‌گذارِ ردیف (' . $nid . ') در جدولِ طرف قراردادِ ' . ($mode === 'copy' ? 'نمونه' : 'فرم') . ' دیده نشد؛ بیمه‌گذارِ نمونه می‌ماند — بررسی کنید.';
        $ro = psgi_row_overrides($c, $html, $row, $kind);
        $ov = $ro['ov'];
        // الگو: کپی => فقط خانه‌های خالی؛ ایجاد => همه‌ی خانه‌های الگو (غیر از خانه‌های ردیف)
        // (منبع: الگوی یادگرفته، و در کپی خودِ نمونه؛ خانه‌های بیمه‌گذار و خانه‌های مخصوصِ ردیف هرگز)
        $cur = $c->formFields($html);
        $src = array_merge($sampleF, $tpl ? $tpl['fields'] : []);
        $rowKeys = array_flip(PSGI_ROW_KEYS);
        foreach ($src as $k => $v) {
            if (isset($ov[$k]) || isset($rowKeys[$k]) || psgi_party_key($k) || psgi_s($v) === '' || psgi_s($v) === ';HIJRI') continue;
            // «طرح»‌ها (چک‌باکسِ چندتایی) ممکن است در فرمِ تازه تیک نخورده باشند و اصلاً در خانه‌ها نیایند
            if ($k === 'tarhListCheckBox') { if ($mode !== 'copy' || !isset($cur[$k])) $ov[$k] = (array)$v; continue; }
            if (!array_key_exists($k, $cur) || psgi_s($v) === psgi_s($cur[$k])) continue;
            if ($mode === 'copy' && trim(psgi_s($cur[$k])) !== '' && trim(psgi_s($cur[$k])) !== ';HIJRI') continue;
            $ov[$k] = is_array($v) ? $v : (string)$v;
        }
        // بیمه‌نامه‌ی قبلی (ثالث): شماره ← «استعلام» تا تاریخ‌ها/تخفیف‌ها از سامانه بیاید
        if ($ro['prev'] !== '' && array_key_exists('shomarehBimehnamehGhabli', $cur) && strpos($html, 'editform:estelamBimehGhabliByShomareh') !== false) {
            $e = $c->click($kind, $html, $ov + ['shomarehBimehnamehGhabli' => $ro['prev']], 'editform:estelamBimehGhabliByShomareh');
            if (!empty($e['ok'])) { $html = $e['html']; $n = $c->notices($html); $notes[] = 'استعلامِ بیمه‌نامه‌ی قبلیِ ' . $ro['prev'] . ': ' . $n['summaryText']; }
            else $notes[] = '⚠️ استعلامِ بیمه‌نامه‌ی قبلی انجام نشد.';
            $ov['shomarehBimehnamehGhabli'] = $ro['prev'];
        }
        foreach ($ro['miss'] as $m) $notes[] = '⚠️ پیدا نشد: ' . $m . ' (در سامانه دستی انتخاب شود یا در الگو بماند).';
        // فهرستِ تغییرات برای نمایش
        $labels = $c->labels($html); $after = $c->formFields($html);
        $changes = [];
        foreach ($ov as $k => $v) {
            if (preg_match('/_(y|m|d)$/', $k) || in_array($k, ['shomarehEntezami'], true)) continue;
            $old = psgi_s($before[$k] ?? '');
            if ($old === psgi_s($v) && !in_array($k, PSGI_ROW_KEYS, true)) continue;
            if ($k === 'tarhListCheckBox') continue;   // طرح‌ها جدا نمایش داده می‌شوند
            $disp = psgi_s($v); $oldDisp = $old;
            foreach (['shomarehEntezami2', 'seryalPelak', 'rang', 'otaghBar', 'noePelak', 'moredEstefadehKhodro'] as $sk) if ($k === $sk) { $op = $c->options($html, $sk); $disp = $op[$v] ?? $v; $oldDisp = $op[$old] ?? $old; }
            $changes[] = ['key' => $k, 'label' => PSGI_FIELD_FA[$k] ?? ($labels[$k] ?? $labels['editform:' . $k] ?? $k), 'old' => $oldDisp, 'new' => $disp, 'row' => in_array($k, PSGI_ROW_KEYS, true)];
        }
        $pre = $c->notices($html);
        $pdo->prepare("INSERT INTO psg_issue_jobs (plate_id, request_id, company_id, kind, mode, sample_no, status, slot, notices_json, changes_json, cargo_orig, user_id, user_name, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, 'PREPARED', ?, ?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$plateId, $row['request_id'], $row['company_id'], $kind, $mode, $sample ?: null, $slot, json_encode(['notes' => $notes, 'warnings' => $pre['warnings'], 'errors' => $pre['errors'], 'lovs' => psgi_form_lovs($html, $ov), 'plans' => psgi_form_plans($html, $ov)], JSON_UNESCAPED_UNICODE),
                       json_encode($changes, JSON_UNESCAPED_UNICODE), $after['otaghBar'] ?? ($ov['otaghBar'] ?? null), $actor['id'] ?: null, mb_substr((string)$actor['name'], 0, 190)]);
        $jid = (int)$pdo->lastInsertId();
        @file_put_contents(psgi_dir() . '/job_' . $jid . '.html', $html);
        @file_put_contents(psgi_dir() . '/job_' . $jid . '.json', json_encode(['ov' => $ov, 'tip' => $after['tip_inputHidden'] ?? ($after['editform:nerkhTip'] ?? ''), 'company_id' => intval($row['company_id']), 'car_name' => (string)$row['car_name']], JSON_UNESCAPED_UNICODE));
        if (function_exists('inq_touch_activity')) inq_touch_activity();
        return ['ok' => true, 'job' => psgi_job_out(psgi_job($pdo, $jid)), 'template' => $tpl ? ['source' => $tpl['source'], 'at' => $tpl['at']] : null];
    } catch (Throwable $e) {
        error_log('[psgi_prepare] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)];
    } finally { inq_unlock($lk); }
}

// ---- ثبت (بعد از تأییدِ کاربر) ----
function psgi_submit($pdo, $jobId, array $opt, array $actor) {
    psgi_ensure($pdo);
    $j = psgi_job($pdo, $jobId);
    if (!$j) return ['ok' => false, 'error' => 'کار پیدا نشد.'];
    if ($j['status'] === 'DONE') return ['ok' => true, 'job' => psgi_job_out($j)];
    if ($j['status'] !== 'PREPARED') return ['ok' => false, 'error' => 'این آماده‌سازی دیگر معتبر نیست؛ دوباره «آماده‌سازی» بزنید.'];
    $hf = psgi_dir() . '/job_' . (int)$j['id'] . '.html'; $jf = psgi_dir() . '/job_' . (int)$j['id'] . '.json';
    if (!is_file($hf) || filemtime($hf) < time() - 1800) return ['ok' => false, 'error' => 'آماده‌سازی کهنه شده (بیش از ۳۰ دقیقه)؛ دوباره «آماده‌سازی» بزنید.'];
    $st = json_decode((string)@file_get_contents($jf), true) ?: [];
    $ov = (array)($st['ov'] ?? []);
    // خانه‌هایی که کاربر در پیش‌نمایش عوض کرد
    // (خانه‌های پیش‌نمایش، انتخاب‌های فهرست‌دار و طرح‌ها؛ فقط خانه‌هایی که در خودِ فرم هستند)
    $formKeys = psgc_fields((string)@file_get_contents($hf), 'editform');
    $extraOk = ['tarhListCheckBox', 'noeVasilehNaghleyeh', 'noeVasilehNaghleyeh_inputHidden', 'tip_inputHidden', 'moshakhasatKhodroSales', 'moshakhasatKhodroBadaneh', 'olguyeMaali', 'otaghBar_inputHidden'];
    foreach ((array)($opt['edits'] ?? []) as $k => $v) {
        if (!is_string($k) || !preg_match('/^[A-Za-z0-9_:.\-]{1,140}$/', $k) || psgi_party_key($k)) continue;
        if (!array_key_exists($k, $ov) && !array_key_exists($k, $formKeys) && !in_array($k, $extraOk, true) && !preg_match('/\.desc$/', $k)) continue;
        $ov[$k] = is_array($v) ? array_values(array_map('strval', $v)) : (string)$v;
    }
    $up = $pdo->prepare("UPDATE psg_issue_jobs SET status = 'RUNNING', updated_at = NOW() WHERE id = ? AND status = 'PREPARED'");
    $up->execute([$j['id']]);
    if (!$up->rowCount()) return ['ok' => false, 'error' => 'هم‌زمان در حالِ ثبت است.'];
    $fin = function ($status, $msg, array $extra = []) use ($pdo, $j) {
        $sets = ['status = ?', 'message = ?', 'finished_at = NOW()', 'updated_at = NOW()']; $vals = [$status, $msg ? mb_substr($msg, 0, 600) : null];
        foreach ($extra as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
        $vals[] = $j['id'];
        $pdo->prepare("UPDATE psg_issue_jobs SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        @unlink(psgi_dir() . '/job_' . (int)$j['id'] . '.html');
    };
    $lk = psgi_lock_for((int)$j['slot'], 90);
    if (!$lk) { $pdo->prepare("UPDATE psg_issue_jobs SET status = 'PREPARED' WHERE id = ?")->execute([$j['id']]); return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است؛ چند ثانیه‌ی دیگر دوباره «ثبت» بزنید.']; }
    try {
        @set_time_limit(280);
        $row = psgi_row($pdo, $j['plate_id']);
        $cls = $GLOBALS['PSGI_CLASS'] ?? 'PsgIssue'; $c = new $cls();   // (آزمون: کلاسِ جایگزین)
        $alts = psgi_cargo_alts($pdo, $st['tip'] ?? '');
        $r = $c->save($j['kind'], (string)file_get_contents($hf), $ov, $alts);
        $notes = json_decode((string)$j['notices_json'], true) ?: [];
        $notes['save'] = $r['notes'] ?? [];
        if (!empty($r['need_login'])) { inq_px_mark('logged_out'); $fin('LOGIN', 'نشستِ سامانه بسته شد.', ['notices_json' => json_encode($notes, JSON_UNESCAPED_UNICODE)]); return ['ok' => false, 'need_login' => true, 'error' => 'نشستِ سامانه بسته شد؛ وارد شوید و دوباره «آماده‌سازی» بزنید.']; }
        if (empty($r['ok'])) { $fin('ERROR', $r['error'] ?? 'ثبت نشد.', ['notices_json' => json_encode($notes, JSON_UNESCAPED_UNICODE)]); return ['ok' => false, 'red' => !empty($r['red']), 'error' => $r['error'] ?? 'ثبت نشد.', 'job' => psgi_job_out(psgi_job($pdo, $j['id']))]; }
        // شماره‌ی پیش‌نویسِ تازه: ردیفی از فهرست با همین شاسی/موتور (وگرنه اولین ردیفِ فهرست)
        $rows = psgd_parse_rows($r['list'], $j['kind']);
        $ch = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($ov['shomarehShasi'] ?? $ov['vin'] ?? ''))); $en = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($ov['shomarehMotor'] ?? '')));
        $no = null;
        foreach ($rows as $x) { $xc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$x['chassis_no'])); $xe = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$x['engine_no']));
            if (($ch !== '' && $xc === $ch) || ($en !== '' && $xe === $en)) { $no = $x['draft_no']; break; } }
        if ($no === null && $rows) $no = $rows[0]['draft_no'];
        if ($rows) psgd_save_rows($pdo, $j['kind'], null, $rows);
        if (!empty($r['cargo_used'])) psgi_cargo_learn($pdo, $st['tip'] ?? '', $r['cargo_used']);
        // الگو از همین ثبتِ موفق (خانه‌هایی که کاربر کنار گذاشت در الگو نمی‌روند)
        psgi_template_save($pdo, $row ? $row['company_id'] : intval($st['company_id'] ?? 0), $j['kind'], $row ? $row['car_name'] : (string)($st['car_name'] ?? ''), array_merge($c->formFields((string)file_get_contents($hf)), $ov), $no, $actor['name'], (array)($opt['exclude'] ?? []));
        $fin('DONE', !empty($r['cargo_used']) ? 'با اتاقِ بارِ جایگزین ثبت شد' : null, ['draft_no' => $no, 'cargo_used' => $r['cargo_used'] ?? null, 'notices_json' => json_encode($notes, JSON_UNESCAPED_UNICODE)]);
        if ($no && $j['plate_id']) $pdo->prepare("UPDATE company_request_plates SET psg_draft_no = ?, psg_draft_at = NOW(), psg_draft_job = ? WHERE id = ?")->execute([$no, $j['id'], $j['plate_id']]);
        // پیش‌نویسِ بدونِ ردیف (ساخته‌شده از «پیش‌نویس‌ها»): اگر با یک ردیفِ بازِ درخواست (شاسی/موتور) یکی است، خودش وصل می‌شود
        elseif ($no) { $pid = psgd_autolink($pdo, $j['kind'], $no, (string)($ov['shomarehShasi'] ?? $ov['vin'] ?? ''), (string)($ov['shomarehMotor'] ?? ''), (int)$j['id']); if ($pid) { $pdo->prepare("UPDATE psg_issue_jobs j JOIN company_request_plates p ON p.id = ? JOIN company_requests r ON r.id = p.request_id SET j.plate_id = p.id, j.request_id = p.request_id, j.company_id = r.company_id WHERE j.id = ?")->execute([$pid, $j['id']]); } }
        if (function_exists('inq_touch_activity')) inq_touch_activity();
        return ['ok' => true, 'job' => psgi_job_out(psgi_job($pdo, $j['id']))];
    } catch (Throwable $e) {
        error_log('[psgi_submit] ' . $e->getMessage());
        $fin('ERROR', 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160));
        return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)];
    } finally { inq_unlock($lk); }
}

// ---- اصلاحِ پیش‌نویس (هم در پاسارگاد) — مثلاً برگرداندنِ اتاقِ بار به نوعِ اصلی، یا تغییرِ یک خانه ----
function psgi_edit($pdo, $kind, $draftNo, array $ov) {
    if (!isset(PSGI_KINDS[$kind]) || $draftNo === '' || !$ov) return ['ok' => false, 'error' => 'اطلاعاتِ اصلاح ناقص است.'];
    $lk = psgi_lock_for(null, 60);
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است.'];
    try {
        @set_time_limit(240);
        $cls = $GLOBALS['PSGI_CLASS'] ?? 'PsgIssue'; $c = new $cls();   // (آزمون: کلاسِ جایگزین)
        $a = $c->rowAction($kind, $draftNo, 'editCommand');
        if (!empty($a['need_login'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید.']; }
        if (empty($a['ok']) || !$c->isForm($a['html'])) return ['ok' => false, 'error' => $a['error'] ?? 'فرمِ اصلاح باز نشد.'];
        $r = $c->save($kind, $a['html'], $ov, []);
        if (empty($r['ok'])) return ['ok' => false, 'red' => !empty($r['red']), 'error' => $r['error'] ?? 'اصلاح ثبت نشد.', 'notes' => $r['notes'] ?? []];
        if ($rows = psgd_parse_rows($r['list'], $kind)) psgd_save_rows($pdo, $kind, null, $rows);
        return ['ok' => true, 'notes' => $r['notes'] ?? []];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
}

// ---- مدارکِ ردیف روی پیش‌نویس ----
function psgi_upload_docs($pdo, $jobId, array $docIds = []) {
    $j = psgi_job($pdo, $jobId);
    if (!$j || !$j['draft_no']) return ['ok' => false, 'error' => 'پیش‌نویس هنوز ثبت نشده.'];
    $siteRoot = dirname(__DIR__);
    $st = $pdo->prepare("SELECT id, file_path, orig_name, doc_type FROM company_documents WHERE plate_id = ? AND status = 'ASSIGNED'" . ($docIds ? " AND id IN (" . implode(',', array_map('intval', $docIds)) . ")" : ''));
    $st->execute([$j['plate_id']]);
    $done = json_decode((string)$j['docs_json'], true) ?: [];
    $files = [];
    if (!function_exists('company_doc_types')) require_once __DIR__ . '/_company_helpers.php';
    $types = company_doc_types();
    foreach ($st->fetchAll() as $d) {
        if (!empty($done[$d['id']]['ok'])) continue;
        $abs = $siteRoot . '/' . ltrim($d['file_path'], '/');
        $nm = ($types[$d['doc_type']] ?? 'مدرک') . '.' . (pathinfo($abs, PATHINFO_EXTENSION) ?: 'pdf');
        $files[] = ['id' => (int)$d['id'], 'path' => $abs, 'name' => $nm];
    }
    if (!$files) return ['ok' => true, 'files' => [], 'note' => 'مدرکِ تازه‌ای برای بارگذاری نیست.'];
    $lk = psgi_lock_for(null, 60);
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است.'];
    try {
        @set_time_limit(280);
        $cls = $GLOBALS['PSGI_CLASS'] ?? 'PsgIssue'; $c = new $cls();   // (آزمون: کلاسِ جایگزین)
        $r = $c->upload($j['kind'], $j['draft_no'], $files);
        if (!empty($r['need_login'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید.']; }
        if (empty($r['ok'])) return $r;
        foreach ($r['files'] as $i => $f) $done[$files[$i]['id']] = ['ok' => $f['ok'], 'name' => $f['name'], 'error' => $f['error'], 'at' => date('Y-m-d H:i')];
        $pdo->prepare("UPDATE psg_issue_jobs SET docs_json = ?, updated_at = NOW() WHERE id = ?")->execute([json_encode($done, JSON_UNESCAPED_UNICODE), $j['id']]);
        return ['ok' => true, 'files' => $r['files']];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
}

// وضعیتِ ردیف(ها) برای نمایش در جدول‌ها: plate_id => [draft_no, at, job]
function psgi_row_states($pdo, array $plateIds) {
    $ids = array_filter(array_map('intval', $plateIds));
    if (!$ids) return [];
    psgi_ensure($pdo);
    $o = [];
    try { foreach ($pdo->query("SELECT id, psg_draft_no, psg_draft_at, psg_draft_job FROM company_request_plates WHERE psg_draft_no IS NOT NULL AND id IN (" . implode(',', $ids) . ")") as $r)
        $o[(int)$r['id']] = ['draft_no' => $r['psg_draft_no'], 'at' => psgd_jdt($r['psg_draft_at']), 'job' => (int)$r['psg_draft_job']]; } catch (Throwable $e) {}
    return $o;
}

// خانه‌های فهرست‌دارِ مهمِ فرمِ پیش‌نویس (تیپ، تعهدات موافقت‌نامه، نماینده/کارگزار، کشور، امضاکننده…) با مقدارِ نهایی
function psgi_form_lovs($html, array $ov) {
    $o = [];
    foreach (psgc_lovs($html) as $l) {
        if (preg_match('/shakhsHaghighi_|shakhsHoghooghi_|tarafBimeh_|VNEnteghal|mahalSodoor|moshakhasatOmumi_shahr|shobehBaank/i', $l['name'])) continue;
        if (isset($ov[$l['name']])) { $l['value'] = psgi_s($ov[$l['name']]); $l['desc'] = psgi_s($ov[$l['name'] . '.desc'] ?? $l['desc']); }
        $o[] = $l;
    }
    return $o;
}
function psgi_form_plans($html, array $ov) {
    $p = psgc_plans($html);
    if (isset($ov['tarhListCheckBox'])) { $sel = array_flip(array_map('strval', (array)$ov['tarhListCheckBox'])); foreach ($p as &$x) $x['checked'] = isset($sel[$x['value']]); unset($x); }
    return $p;
}
