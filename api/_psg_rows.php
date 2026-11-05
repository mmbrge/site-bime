<?php
// فایل: api/_psg_rows.php
// کار روی یک ردیفِ فهرست‌های پاسارگاد (پیش‌نویس‌ها و صادره‌ها) از داخلِ پنل:
//   - چاپ‌ها (PDFِ خودِ سامانه): پیش‌نویس، فرمِ پیشنهاد، فیشِ نقدی، قبضِ رسید، بیمه‌نامه‌ی صادره
//   - «باز کردن در سایتِ بیمه‌گر»: مشاهده، اصلاح، تعیینِ وضعیتِ مالی، بارگذاریِ فایل، استعلامِ مالی — صفحه با نشستِ سرور ساخته
//     و در پروکسی (psgx، نشستِ سرور) نشان داده می‌شود تا کاربر همان‌جا ادامه دهد
// دکمه‌های هر ردیف شناسه‌ی پویا دارند؛ با برچسب/عنوانشان پیدا می‌شوند.
require_once __DIR__ . '/_inquiry.php';
require_once __DIR__ . '/_psg_drafts.php';

const PSGR_PRINTS = [
    'draft'    => ['label' => 'چاپِ پیش‌نویس', 'rx' => '/چاپ\s*پیش\s*نویس/u'],
    'proposal' => ['label' => 'چاپِ فرمِ پیشنهاد', 'rx' => '/چاپ\s*فرم\s*پیشنهاد/u'],
    'cash'     => ['label' => 'چاپِ فیشِ نقدی', 'rx' => '/فيش\s*نقدي|فیش\s*نقدی/u'],
    'receipt'  => ['label' => 'چاپِ قبضِ رسید', 'rx' => '/قبض\s*رسید/u'],
    'policy'   => ['label' => 'چاپِ بیمه‌نامه', 'rx' => '/^\s*چاپ\s*$|چاپ\s*وضعیت\s*بیمه\s*نامه|چاپ\s*بیمه\s*نامه/u'],
];
// فقط «باز کردنِ صفحه» (هیچ‌کدام چیزی ثبت نمی‌کند تا کاربر خودش در سامانه دکمه بزند)
const PSGR_OPENS = [
    'view'    => ['label' => 'مشاهده‌ی جزئیات', 'id' => 'viewCommand'],
    'edit'    => ['label' => 'اصلاحِ پیش‌نویس', 'id' => 'editCommand'],
    'upload'  => ['label' => 'بارگذاریِ فایل', 'id' => 'editMadarek'],
    'finance' => ['label' => 'تعیینِ وضعیتِ مالی', 'rx' => '/تعیین\s*وضعیت\s*مالی/u'],
    'fin_inq' => ['label' => 'استعلامِ مالی', 'rx' => '/استعلام\s*مالی/u'],
];

class PsgRows extends PsgDrafts
{
    public function isOut($h) { return $this->loggedOut($h); }
    // فهرست با فیلترِ همین شماره (پیش‌نویس یا بیمه‌نامه)
    public function listFor($kind, $no) {
        $k = PSGD_KINDS[$kind];
        $r = $this->http('GET', $this->core . $k['list']);
        if ($r['body'] === '' && $r['error'] !== '') return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد در دسترس نیست (' . $r['error'] . ').'];
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        if (strpos($r['body'], 'exampleform') === false) return ['ok' => false, 'error' => 'صفحه‌ی فهرست باز نشد.'];
        $f = psgd_is_issued($kind) ? 'exampleform:gharardadBimeh_shomarehBimehNameh' : 'exampleform:gharardadBimeh_shomarehPishnevis';
        $ov = [$f => $no, 'exampleform:tarikhSodoor_ge' => '1400/01/01;HIJRI', 'exampleform:tarikhSodoor_ge_y' => '1400', 'exampleform:tarikhSodoor_ge_m' => '01', 'exampleform:tarikhSodoor_ge_d' => '01',
               'exampleform:fieldset:dataTable:goto' => '1'];
        $r2 = $this->submit($this->core . $k['post'], $r['body'], $ov, 'exampleform:search', $this->core . $k['list']);
        if ($this->loggedOut($r2['body'])) return ['ok' => false, 'need_login' => true];
        if (strpos($r2['body'], 'exampleform') === false) return ['ok' => false, 'error' => 'جستجو در سامانه انجام نشد.'];
        $i = null; foreach (psgd_parse_rows($r2['body'], $kind) as $n => $row) if (trim($row['draft_no']) === trim($no)) { $i = $n; break; }
        if ($i === null) return ['ok' => false, 'error' => 'ردیفِ ' . $no . ' در سامانه پیدا نشد.'];
        return ['ok' => true, 'html' => $r2['body'], 'i' => $i];
    }
    // شناسه‌ی دکمه‌ی ردیف با برچسب/عنوان (یا شناسه‌ی ثابت)
    public function actionId($html, $i, array $spec) {
        if (!empty($spec['id'])) { $id = "exampleform:fieldset:dataTable:$i:" . $spec['id']; return strpos($html, $id) !== false ? $id : null; }
        if (preg_match_all("#<a\\b[^>]*submitForm\\('exampleform','(exampleform:fieldset:dataTable:$i:[^']+)'\\)[^>]*>(.*?)</a>#is", $html, $mm, PREG_SET_ORDER)) foreach ($mm as $m) {
            $title = preg_match('/\btitle="([^"]*)"/', $m[0], $t) ? html_entity_decode($t[1], ENT_QUOTES, 'UTF-8') : '';
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
            if (preg_match($spec['rx'], $title) || preg_match($spec['rx'], $text)) return $m[1];
        }
        return null;
    }
    public function run($kind, $no, array $spec) {
        $l = $this->listFor($kind, $no);
        if (empty($l['ok'])) return $l;
        $id = $this->actionId($l['html'], $l['i'], $spec);
        if (!$id) return ['ok' => false, 'error' => '«' . $spec['label'] . '» برای این ردیف در سامانه نیست (شاید مرحله‌اش نیست).'];
        $k = PSGD_KINDS[$kind];
        $r = $this->submit($this->core . $k['post'], $l['html'], [], $id, $this->core . $k['post']);
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        return ['ok' => true, 'html' => $r['body'], 'url' => $r['url'] ?: $this->core . $k['post']];
    }
    public function pdf($printFile) {
        $r = $this->http('GET', $this->core . '/cins/bimehReportServlet?printFileName=' . rawurlencode($printFile), [], '', false, ['Accept: application/pdf,*/*']);
        return strpos((string)$r['body'], '%PDF') === 0 ? $r['body'] : null;
    }
}

function psgr_dir() { return inq_dir('psg_rows'); }
// چاپ => ['ok', 'token', 'name'] (فایل در پوشه‌ی خصوصی؛ با prem_pdf‌مانندِ «pdf» در psg_drafts_actions گرفته می‌شود)
function psgr_print($pdo, $uid, $kind, $no, $what) {
    if (!isset(PSGD_KINDS[$kind]) || !isset(PSGR_PRINTS[$what]) || $no === '') return ['ok' => false, 'error' => 'درخواستِ چاپ نامعتبر است.'];
    $lk = inq_lock(60);
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است؛ چند ثانیه‌ی دیگر.'];
    try {
        @set_time_limit(180);
        $cls = $GLOBALS['PSGR_CLASS'] ?? 'PsgRows'; $c = new $cls();   // (آزمون: کلاسِ جایگزین)
        $r = $c->run($kind, $no, PSGR_PRINTS[$what]);
        if (!empty($r['need_login'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید.']; }
        if (empty($r['ok'])) return $r;
        if (!preg_match('/printFileName=([A-Za-z0-9_\-\.]+\.pdf)/', (string)$r['html'], $m)) {
            return ['ok' => false, 'error' => 'سامانه فایلِ چاپ نداد' . (preg_match("/errorList\.push\(['\"]([^'\"]+)/u", (string)$r['html'], $e) ? ': ' . $e[1] : '.')];
        }
        $pdf = $c->pdf($m[1]);
        if (!$pdf) return ['ok' => false, 'error' => 'فایلِ PDF از سامانه نیامد.'];
        $tok = bin2hex(random_bytes(8));
        @file_put_contents(psgr_dir() . '/p_' . intval($uid) . '_' . $tok . '.pdf', $pdf);
        foreach (glob(psgr_dir() . '/p_*.pdf') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
        if (function_exists('inq_touch_activity')) inq_touch_activity();
        $name = PSGR_PRINTS[$what]['label'] . ' ' . str_replace('/', '-', $no) . '.pdf';
        return ['ok' => true, 'token' => $tok, 'name' => $name];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
}
function psgr_pdf_path($uid, $tok) { $tok = preg_replace('/[^a-f0-9]/', '', (string)$tok); $f = psgr_dir() . '/p_' . intval($uid) . '_' . $tok . '.pdf'; return $tok !== '' && is_file($f) ? $f : null; }

// باز کردنِ صفحه‌ی یک ردیف در پروکسی (نشستِ سرور = نشستِ ۰ تا صفحه با همان نشست ادامه پیدا کند)
function psgr_proxy_open($pdo, $kind, $no, $act) {
    if (!isset(PSGD_KINDS[$kind]) || !isset(PSGR_OPENS[$act]) || $no === '') return ['ok' => false, 'error' => 'درخواستِ نامعتبر.'];
    $lk = inq_lock_slot(0, 60);
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ سرور مشغول است؛ چند ثانیه‌ی دیگر.'];
    try {
        @set_time_limit(180);
        $cls = $GLOBALS['PSGR_CLASS'] ?? 'PsgRows'; $c = new $cls();   // (آزمون: کلاسِ جایگزین)
        $r = $c->run($kind, $no, PSGR_OPENS[$act]);
        if (!empty($r['need_login'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید (نشستِ ۱).']; }
        if (empty($r['ok'])) return $r;
        $tok = bin2hex(random_bytes(8));
        $path = (string)(parse_url($r['url'], PHP_URL_PATH) ?: PSGD_KINDS[$kind]['post']);
        @file_put_contents(inq_dir('psgx') . '/view_' . $tok . '.html', json_encode(['path' => $path, 'html' => $r['html'], 't' => time()], JSON_UNESCAPED_UNICODE));
        foreach (glob(inq_dir('psgx') . '/view_*.html') ?: [] as $old) if (filemtime($old) < time() - 3600) @unlink($old);
        return ['ok' => true, 'url' => 'psgx/index.php/~s/__view/' . $tok];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
}

// خروجیِ اکسلِ همه‌ی ردیف‌ها (پیش‌نویس‌ها یا صادره‌ها)
function psgr_export($pdo, array $kinds, $withStale = false) {
    require_once __DIR__ . '/_xlsx_writer.php';
    $sheets = [];
    foreach ($kinds as $kind) {
        if (!isset(PSGD_KINDS[$kind])) continue;
        $L = psgd_list($pdo, $kind, $withStale)['rows'];
        $body = psgd_base($kind) === 'body';
        $h = [psgd_is_issued($kind) ? 'شماره بیمه‌نامه' : 'شماره پیش‌نویس', 'بیمه‌گذار', 'مرحله', 'تاریخ صدور', 'شروع', 'انقضا', 'خودرو', 'پلاک', 'مورد استفاده', 'شماره موتور', 'شماره شاسی',
              $body ? 'ارزش / مبلغ' : 'تعهد مالی', $body ? 'موافقت‌نامه' : 'تعهد جانی', $body ? 'معرف / کارشناسی' : 'تعهد حوادث راننده', 'حق بیمه', 'خالص', 'واحد صدور', 'ناظر', 'کاربر', 'ردیفِ سایت', 'قدیمی'];
        $rows = [];
        foreach ($L as $r) $rows[] = [$r['no'], $r['insured'], $r['state'], $r['created'], $r['start'], $r['end'], $r['car'], $r['plate'], $r['usage'], $r['engine'], $r['chassis'],
                                      $r['f1'], $r['f2'], $r['f3'], $r['premium'], $r['net'], $r['unit'], $r['supervisor'], $r['user'], $r['site'] ? 'درخواست #' . $r['site']['request'] : '', $r['stale'] ? 'بله' : ''];
        $sheets[] = ['name' => mb_substr(PSGD_KINDS[$kind]['label'], 0, 28), 'headers' => $h, 'rows' => $rows, 'numeric' => [14, 15]];
    }
    return $sheets ? xlsx_build_book($sheets) : null;
}
