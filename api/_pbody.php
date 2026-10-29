<?php
// فایل: api/_pbody.php
// «استعلام بدنه بیمه مرکزی» و «استعلام خسارت» از سامانه‌ی پاسارگاد (core.pasargadinsurance.ir) — فقط پاسارگاد.
//  - همان نشستِ ورودِ استعلاماتِ پاسارگاد (کپچای ورود، api/_inquiry.php) استفاده می‌شود
//  - آدرسِ صفحه‌ها از منوی سامانه پیدا می‌شود (یا مدیر کل در تنظیمات می‌گذارد)؛ خانه‌ها از روی «برچسبِ» فارسیِ کنارشان پر می‌شوند
//  - هر مرحله (HTMLِ صفحه) در api/data/inq/pbody/<job>/ ذخیره می‌شود تا اگر سامانه عوض شد، بشود دقیق اصلاحش کرد
//  - نتیجه مثلِ سنهاب در inq_requests (kind = pbody / pdamage) می‌ماند؛ خروجیِ اکسل، PDFِ سایت، چاپِ خودِ سامانه
//  - استعلامِ خسارت: PDFِ سامانه (اگر خسارت دارد) یا «تصویرِ صفحه» (خسارتی ثبت نشده) با شماره بیمه‌نامه، شاسی و موتورِ جستجو
//  - گروهی (با اکسل): ردیف‌ها در inq_requests با batch_id و وضعیتِ QUEUED می‌مانند و inq_batch_tick یکی‌یکی (از پنل یا cron در پس‌زمینه)
//    با pb_batch_run اجرا می‌کند؛ خروجیِ اکسلِ گروه و ZIPِ همه‌ی فایل‌های خسارت
require_once __DIR__ . '/_inquiry.php';

const PB_TYPES = ['unique' => 'کد یکتا', 'print' => 'شماره چاپی', 'vin' => 'vin', 'engine' => 'شماره موتور', 'chassis' => 'شماره شاسی',
                  'chassis_engine' => 'شماره شاسی و موتور', 'plate' => 'پلاک ملی'];
const PB_INS = ['both' => 'هردو', 'body' => 'بدنه', 'third' => 'ثالث'];
// برچسب‌های نتیجه‌ی استعلامِ بدنه => کلید
const PB_LABELS = ['کد یکتا' => 'unique_code', 'نام شرکت' => 'company', 'شماره چاپی' => 'print_no', 'نام بیمه گذار' => 'insured', 'تاریخ صدور' => 'issue_date',
                   'تاریخ شروع' => 'start_date', 'تاریخ پایان' => 'end_date', 'دو رقم پلاک' => 'plate_2', 'حرف پلاک' => 'plate_letter', 'سه رقم پلاک' => 'plate_3',
                   'سریال پلاک' => 'plate_serial', 'شماره موتور' => 'engine', 'شماره شاسی' => 'chassis', 'نوع پلاک' => 'plate_kind', 'vin' => 'vin',
                   'تعداد سال تخفیف عدم خسارت' => 'no_loss_years', 'گروه خودرو' => 'car_group', 'سیستم خودرو' => 'car_system', 'کد یکتا بیمه نامه قبلی' => 'prev_unique_code',
                   'نوع خودرو' => 'car_type', 'مدل' => 'model', 'سال ساخت' => 'model_year', 'رنگ' => 'color', 'تیپ' => 'tip', 'نوع بیمه نامه' => 'policy_type'];
const PB_DMG_LABELS = ['کد یکتا پرونده' => 'case_code', 'تاریخ اعلام حادثه' => 'declare_date', 'تاریخ وقوع حادثه' => 'accident_date', 'تاریخ تشکیل پرونده' => 'file_date',
                       'تاریخ تکمیل پرونده' => 'complete_date', 'مبلغ خسارت' => 'amount', 'مبلغ پرداختی' => 'paid', 'نوع خسارت' => 'loss_type', 'وضعیت پرونده' => 'state'];

function pb_enabled($pdo) { return inq_get_setting($pdo, 'inq_pb_enabled') !== '0'; }
function pb_dir($sub = '') { return inq_dir('pbody' . ($sub !== '' ? '/' . $sub : '')); }
function pb_fa_norm($s) {
    $s = str_replace(['ي', 'ك', "\u{200C}", "\u{200F}", "\u{200E}", 'ۀ', 'ة', 'أ', 'إ'], ['ی', 'ک', '', '', '', 'ه', 'ه', 'ا', 'ا'], (string)$s);
    return mb_strtolower(preg_replace('/[\s:：*\-–_]+/u', '', $s), 'UTF-8');
}
function pb_en($s) { return strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                                              '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']); }
// شماره‌ی بیمه‌نامه بدونِ «/0»ی که سامانه آخرِ شماره‌ی چاپیِ بیمه‌نامه‌ی اصلی می‌گذارد (۲/۵۰۰۲۹/…/۴۵۰/0 => ۲/۵۰۰۲۹/…/۴۵۰)؛
// الحاقیه‌ها (/1، /2، …) دست نمی‌خورند
function pb_policy_no($s) { $s = trim((string)$s); return preg_match('#^(.+/.+)/0+$#', $s, $m) ? $m[1] : $s; }
function pb_up($s) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', pb_en($s))); }

// ورودیِ استعلامِ بدنه => ['ok', type, params, label]
function pb_validate(array $in) {
    $t = (string)($in['type'] ?? '');
    if (!isset(PB_TYPES[$t])) return ['ok' => false, 'error' => 'نوعِ استعلام را انتخاب کنید.'];
    $p = [];
    if ($t === 'unique') { $p['unique'] = preg_replace('/\D/', '', pb_en($in['unique'] ?? '')); if (strlen($p['unique']) < 5) return ['ok' => false, 'error' => 'کد یکتا را درست بنویسید.', 'field' => 'unique']; $label = 'کد یکتا ' . $p['unique']; }
    elseif ($t === 'print') { $p['print'] = trim(preg_replace('/\s+/', '', pb_en($in['print'] ?? ''))); if (strlen($p['print']) < 5) return ['ok' => false, 'error' => 'شماره چاپیِ بیمه‌نامه را بنویسید.', 'field' => 'print']; $label = 'شماره چاپی ' . $p['print']; }
    elseif ($t === 'plate') {
        foreach (['p1', 'p2', 'p4'] as $k) $p[$k] = preg_replace('/\D/', '', pb_en($in[$k] ?? ''));
        $p['letter'] = trim((string)($in['letter'] ?? ''));
        if (strlen($p['p1']) !== 2 || strlen($p['p2']) !== 3 || strlen($p['p4']) !== 2 || $p['letter'] === '') return ['ok' => false, 'error' => 'پلاک را کامل بنویسید (دو رقم، حرف، سه رقم، کد ایران).', 'field' => 'plate'];
        $label = "{$p['p1']}ایران - {$p['p2']} {$p['letter']} {$p['p4']}";
    } else {
        if (in_array($t, ['vin', 'chassis', 'chassis_engine'], true)) { $p['chassis'] = pb_up($in['chassis'] ?? ($in['vin'] ?? '')); if (strlen($p['chassis']) < 6) return ['ok' => false, 'error' => 'شماره شاسی / VIN را بنویسید.', 'field' => 'chassis']; }
        if (in_array($t, ['engine', 'chassis_engine'], true)) { $p['engine'] = pb_up($in['engine'] ?? ''); if (strlen($p['engine']) < 5) return ['ok' => false, 'error' => 'شماره موتور را بنویسید.', 'field' => 'engine']; }
        $label = ($t === 'vin' ? 'VIN ' : ($t === 'engine' ? 'موتور ' : 'شاسی ')) . ($p['chassis'] ?? $p['engine']) . ($t === 'chassis_engine' ? ' / موتور ' . $p['engine'] : '');
    }
    return ['ok' => true, 'type' => $t, 'params' => $p, 'label' => $label];
}

// ورودیِ استعلامِ خسارت
function pb_damage_validate(array $in) {
    $pol = pb_policy_no(preg_replace('/\s+/', '', pb_en($in['policy_no'] ?? '')));   // سامانه شماره را بدونِ «/0»ِ آخر می‌خواهد
    $ch = pb_up($in['chassis'] ?? ''); $en = pb_up($in['engine'] ?? '');
    if ($pol === '' && $ch === '' && $en === '') return ['ok' => false, 'error' => 'شماره بیمه‌نامه، شاسی یا موتور را بنویسید.'];
    $ins = isset(PB_INS[$in['ins'] ?? '']) ? $in['ins'] : 'both';
    $outp = in_array($in['out'] ?? '', ['pdf', 'shot'], true) ? $in['out'] : 'shot';
    return ['ok' => true, 'params' => ['policy_no' => $pol, 'chassis' => $ch, 'engine' => $en, 'ins' => $ins], 'out' => $outp,
            'label' => trim(($pol !== '' ? 'بیمه‌نامه ' . $pol : '') . ($ch !== '' ? ' · شاسی ' . $ch : '') . ($en !== '' ? ' · موتور ' . $en : ''), ' ·')];
}

// =====================================================================
//  گفتگو با سامانه (JSF): پیدا کردنِ صفحه، خانه‌ها از روی برچسب، دکمه از روی متن
// =====================================================================
class InqPasBody extends InqPasargad
{
    private $pdo;
    public $steps = [];

    public function __construct($pdo, $jobDir)
    {
        parent::__construct($jobDir);
        $this->pdo = $pdo;
    }
    private function step($name, $html)
    {
        $this->steps[] = $name;
        if ($this->jobDir !== '') @file_put_contents($this->jobDir . '/' . count($this->steps) . '-' . $name . '.html', $html);
    }
    private function abs($href, $base)
    {
        $href = html_entity_decode(trim((string)$href), ENT_QUOTES, 'UTF-8');
        if ($href === '' || $href[0] === '#' || stripos($href, 'javascript:') === 0) return '';
        if (preg_match('#^https?://#i', $href)) return $href;
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($href[0] === '/') return $root . $href;
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
        return $root . $dir . $href;
    }
    // مقدارِ یک ویژگی با هر دو نوع نقل‌قول (onclick="…('a','b')…")
    private static function attr($attrs, $n)
    {
        if (!preg_match('/(?<![\w\-:])' . preg_quote($n, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $m)) return null;
        return html_entity_decode(isset($m[2]) && $m[1] === '' ? $m[2] : $m[1], ENT_QUOTES, 'UTF-8');
    }
    private static function text($h) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', ' ', (string)$h)), ENT_QUOTES, 'UTF-8'))); }

    // ---- آدرسِ صفحه ----
    // سامانه‌ی پاسارگاد تک‌صفحه‌ای است (/cins/template/fa/index_fa.xhtml): با زدنِ منو آدرسِ مرورگر عوض نمی‌شود و صفحه داخلِ همان قاب
    // بار می‌شود. پس آدرسِ واقعیِ صفحه از خودِ منو پیدا می‌شود (پیوند، onclick، داده‌ی منو در اسکریپت، یا قاب/iframe) و نگه داشته می‌شود.
    //  - ترتیب: آدرسِ تنظیمات، آخرین آدرسی که کار کرد، جستجو در منو (resolvePage)
    const MENU_TEXT = ['body' => ['استعلام بدنه بیمه مرکزی', 'استعلام بدنه'], 'damage' => ['استعلام خسارت']];
    // آدرسِ صفحه از روی متنِ منو در HTML (پیوند/ویژگی‌ها/داده‌ی اسکریپت در نزدیکیِ همان متن)
    private function menuUrlIn($html, array $want, $base)
    {
        $variants = [];
        foreach ($want as $w) { $variants[] = $w; $variants[] = trim(json_encode($w), '"'); $variants[] = str_replace(' ', '&nbsp;', $w); }
        $urlRe = '/(?:href|src|url|data-url|data-href|data-src|link|path|action|page)\\\\?["\']?\s*[=:]\s*\\\\?["\']([^"\'\s<>]{3,300})\\\\?["\']|["\']((?:\/|\.\.\/|[A-Za-z0-9_\-]+\/)[A-Za-z0-9_\-\/.]*\.(?:xhtml|html|jsf|faces|jsp)(?:\?[^"\'\s<>]*)?)["\']/i';
        $best = null;
        foreach ($variants as $v) {
            $off = 0;
            while (($pos = strpos($html, $v, $off)) !== false) {
                $off = $pos + strlen($v);
                // فقط خودِ همین گزینه (نه «استعلام خسارتِ …» یا متنی که ادامه دارد)
                $after = substr($html, $off, 12);
                if (!preg_match('/^\s*(<|"|\'|\\\\|&|$)/', $after)) continue;
                $from = max(0, $pos - 900);
                $win = substr($html, $from, min(1500, strlen($html) - $from));
                if (!preg_match_all($urlRe, $win, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
                foreach ($mm as $m) {
                    $raw = isset($m[2]) && $m[2][1] >= 0 ? $m[2][0] : $m[1][0];
                    $at = $from + (isset($m[2]) && $m[2][1] >= 0 ? $m[2][1] : $m[1][1]);
                    $raw = str_replace('\\/', '/', html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
                    if ($raw === '' || $raw[0] === '#' || preg_match('/^(javascript|mailto|data|about):|\.(css|js|png|jpe?g|gif|svg|ico|woff2?)(\?|$)|index_fa\.xhtml/i', $raw)) continue;
                    $abs = $this->abs($raw, $base);
                    if ($abs === '' || stripos($abs, 'pasargadinsurance.ir') === false && strpos($abs, '127.0.0.1') === false) continue;
                    // فقط آدرسِ همان گزینه: میانِ آدرس و متن نباید مرزِ گزینه‌ی دیگری باشد (تگِ li/a یا شیءِ {…} در داده‌ی منو)
                    $between = $at <= $pos ? substr($html, $at, $pos - $at) : substr($html, $pos, $at - $pos);
                    if ($at <= $pos ? preg_match('/\}|<\/a>|<\/li>|<li\b/i', $between) : preg_match('/\{|<li\b|<a\b|<\/li>/i', $between)) continue;
                    // نزدیک‌ترین آدرس به متن؛ آدرسِ پیش از متن (داخلِ همان تگ) اولویت دارد
                    $d = $at <= $pos ? $pos - $at : ($at - $pos) * 2 + 50;
                    if (!$best || $d < $best[0]) $best = [$d, $abs];
                }
            }
            if ($best) break;
        }
        return $best ? $best[1] : '';
    }
    private function discover($kind, array $starts)
    {
        $want = self::MENU_TEXT[$kind];
        $seen = []; $queue = array_merge($starts, [$this->core . '/cins/template/fa/index_fa.xhtml', $this->core, $this->core . '/cins/']);
        $hops = 0;
        while ($queue && $hops < 14) {
            $url = array_shift($queue);
            if (isset($seen[$url])) continue;
            $seen[$url] = 1; $hops++;
            $r = $this->http('GET', $url);
            if ($r['body'] === '') continue;
            if ($this->loggedOut($r['body'])) return 'need_login';
            $this->step('menu', $r['body']);
            $base = $r['url'] ?: $url;
            $u = $this->menuUrlIn($r['body'], $want, $base);
            if ($u !== '') return $u;
            // قاب‌ها، اسکریپت/داده‌ی منو و زیرمنوهای «خودرو / سناریو خاص / استعلام»
            if (preg_match_all('/<(?:iframe|frame)\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $r['body'], $fm)) foreach ($fm[1] as $f) { $a = $this->abs($f, $base); if ($a !== '') $queue[] = $a; }
            if (preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']*(?:menu|nav|tree)[^"\']*)["\']/i', $r['body'], $sm)) foreach ($sm[1] as $f) { $a = $this->abs($f, $base); if ($a !== '') $queue[] = $a; }
            if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $r['body'], $m, PREG_SET_ORDER)) foreach ($m as $x) {
                if (!preg_match('/^(خودرو|سناریوخاص|استعلام|استعلامات)$/u', pb_fa_norm(self::text($x[2])))) continue;
                $h = (string)self::attr($x[1], 'href');
                $a = $this->abs($h, $base);
                if ($a !== '' && !isset($seen[$a])) $queue[] = $a;
            }
        }
        return '';
    }
    // بازکردنِ صفحه‌ی استعلام با همه‌ی راه‌ها؛ هر آدرسی که واقعاً صفحه را باز کرد نگه داشته می‌شود (_auto) تا اگر تنظیم بعداً
    // پاک/عوض شد یا پیدا کردن از منو نشد، همان آدرسِ سالم استفاده شود:
    //   ۱) آدرسِ تنظیمات (اگر خودِ صفحه باشد)  ۲) آخرین آدرسِ سالم  ۳) جستجوی دوباره در منو (شروع از آدرسِ تنظیمات اگر صفحه‌ی اصلی است)
    // خروجی: ['r' => پاسخ, 'url' => آدرس] یا ['error' => need_login|not_found|down]
    private function resolvePage($kind, array $mustHave)
    {
        $key = $kind === 'damage' ? 'inq_pb_damage_url' : 'inq_pb_body_url';
        $cfg = trim((string)inq_get_setting($this->pdo, $key));
        $auto = trim((string)inq_get_setting($this->pdo, $key . '_auto'));
        $tried = []; $last = null;
        $try = function ($u) use (&$tried, &$last, $mustHave, $key) {
            if ($u === '' || $u === 'need_login' || isset($tried[$u])) return null;
            $tried[$u] = 1;
            $r = $this->openPage($u, $mustHave);
            if (!empty($r['need_login'])) return 'need_login';
            if ((string)$r['body'] !== '') $last = $r;
            foreach ($mustHave as $t) if (strpos((string)$r['body'], $t) !== false) {
                if (trim((string)inq_get_setting($this->pdo, $key . '_auto')) !== $u) inq_set_setting($this->pdo, $key . '_auto', $u);
                return ['r' => $r, 'url' => $r['url'] ?: $u];
            }
            return null;
        };
        $cands = [];
        if ($cfg !== '') $cands[] = $cfg;          // آدرسِ صفحه‌ی اصلی هم امتحان می‌شود: شاید خودِ صفحه همان باشد
        $cands[] = $auto;
        foreach ($cands as $u) { $x = $try($u); if ($x === 'need_login') return ['error' => 'need_login']; if ($x) return $x; }
        $found = $this->discover($kind, $cfg !== '' ? [$cfg] : []);
        if ($found === 'need_login') return ['error' => 'need_login'];
        $x = $try($found);
        if ($x === 'need_login') return ['error' => 'need_login'];
        if ($x) return $x;
        if (!$tried) return ['error' => 'not_found'];          // نه آدرسی در تنظیمات، نه آدرسِ قبلی، نه در منو
        if ($last === null) return ['error' => 'down'];        // آدرس بود ولی سامانه جواب نداد
        return ['error' => 'wrong_page', 'r' => $last];         // سامانه صفحه‌ی دیگری برگرداند
    }
    // صفحه‌ای که خودش داخلِ قاب (iframe) بار می‌شود
    private function openPage($url, array $mustHave)
    {
        $r = $this->http('GET', $url);
        if ($this->loggedOut($r['body'])) return ['need_login' => true];
        for ($i = 0; $i < 2 && $r['body'] !== ''; $i++) {
            $has = false; foreach ($mustHave as $t) if (strpos($r['body'], $t) !== false) { $has = true; break; }
            if ($has || !preg_match('/<(?:iframe|frame)\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $r['body'], $fm)) break;
            $a = $this->abs($fm[1], $r['url'] ?: $url);
            if ($a === '') break;
            $r2 = $this->http('GET', $a);
            if ($this->loggedOut($r2['body'])) return ['need_login' => true];
            $r = $r2;
        }
        return $r;
    }

    // ---- همه‌ی خانه‌های فرم‌های صفحه با برچسبِ کنارشان ----
    public function controls($html)
    {
        $labels = [];
        if (preg_match_all('/<label\b[^>]*\bfor=["\']([^"\']+)["\'][^>]*>(.*?)<\/label>/is', $html, $lm, PREG_SET_ORDER))
            foreach ($lm as $l) $labels[html_entity_decode($l[1], ENT_QUOTES, 'UTF-8')] = self::text($l[2]);
        $forms = [];
        if (preg_match_all('/<form\b[^>]*\b(?:id|name)=["\']([^"\']+)["\'][^>]*>/i', $html, $fm, PREG_OFFSET_CAPTURE)) foreach ($fm[1] as $f) $forms[] = [$f[1], $f[0]];
        $out = [];
        if (!preg_match_all('/<(input|select|textarea)\b([^>]*)>/is', $html, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $out;
        foreach ($mm as $m) {
            $tag = strtolower($m[1][0]); $attrs = $m[2][0]; $pos = $m[0][1]; $end = $pos + strlen($m[0][0]);
            $at = function ($n) use ($attrs) { return self::attr($attrs, $n); };
            $type = $tag === 'input' ? strtolower($at('type') ?: 'text') : $tag;
            if (in_array($type, ['hidden', 'file', 'image', 'reset'], true)) continue;
            $c = ['tag' => $tag, 'type' => $type, 'name' => $at('name'), 'id' => $at('id'), 'value' => $at('value'), 'disabled' => (bool)preg_match('/\bdisabled\b/i', $attrs),
                  'onchange' => $at('onchange'), 'onclick' => $at('onclick'), 'pos' => $pos, 'options' => [], 'form' => ''];
            foreach ($forms as $f) if ($f[1] < $pos) $c['form'] = $f[0];
            if ($tag === 'select') {
                $close = stripos($html, '</select>', $end);
                $inner = $close !== false ? substr($html, $end, $close - $end) : '';
                if (preg_match_all('/<option\b([^>]*)>(.*?)(?=<option\b|<\/select>|$)/is', $inner . '</select>', $om, PREG_SET_ORDER))
                    foreach ($om as $o) $c['options'][] = [preg_match('/\bvalue=["\']([^"\']*)["\']/i', $o[1], $vv) ? html_entity_decode($vv[1], ENT_QUOTES, 'UTF-8') : self::text($o[2]), self::text($o[2])];
            }
            $lab = ($c['id'] !== null && isset($labels[$c['id']])) ? $labels[$c['id']] : '';
            if ($lab === '') {
                // نزدیک‌ترین متنِ پیش از خانه (سلولِ برچسب)
                $before = substr($html, max(0, $pos - 900), min(900, $pos));
                $parts = preg_split('/<[^>]+>/', $before);
                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode($parts[$i], ENT_QUOTES, 'UTF-8')));
                    if ($t !== '' && $t !== '*' && !preg_match('/^[\/\-:]+$/', $t)) { $lab = $t; break; }
                }
            }
            $c['label'] = trim($lab, " \t*:");
            $c['nlabel'] = pb_fa_norm($c['label']);
            $out[] = $c;
        }
        return $out;
    }
    // خانه با برچسبِ دقیق (اول) یا شاملِ آن
    public function findCtl(array $ctls, array $labels, $tags = null)
    {
        foreach ([true, false] as $exact) foreach ($labels as $l) {
            $nl = pb_fa_norm($l);
            foreach ($ctls as $c) {
                if ($tags && !in_array($c['tag'], (array)$tags, true)) continue;
                if (in_array($c['type'], ['submit', 'button', 'checkbox', 'radio'], true) || !$c['name']) continue;
                if ($exact ? $c['nlabel'] === $nl : ($nl !== '' && mb_strpos($c['nlabel'], $nl) !== false && mb_strlen($c['nlabel']) <= mb_strlen($nl) + 4)) return $c;
            }
        }
        return null;
    }
    // گزینه‌ی select از روی متن (یا مقدار)
    public static function optionFor(array $c, $text, $prefix = false)
    {
        $nt = pb_fa_norm(pb_en($text));
        foreach ($c['options'] as $o) if (pb_fa_norm(pb_en($o[1])) === $nt || pb_fa_norm(pb_en($o[0])) === $nt) return $o[0];
        if ($prefix) foreach ($c['options'] as $o) { $ot = pb_fa_norm(pb_en($o[1])); if ($nt !== '' && strpos($ot, $nt) === 0) return $o[0]; }
        foreach ($c['options'] as $o) { $ot = pb_fa_norm(pb_en($o[1])); if ($nt !== '' && $ot !== '' && mb_strpos($ot, $nt) !== false) return $o[0]; }
        return null;
    }
    // دکمه / پیوندِ فرمانی از روی متن
    public function findButton($html, $regex)
    {
        $cands = [];
        if (preg_match_all('/<input\b([^>]*)>/is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) foreach ($m as $x) {
            $a = $x[1][0];
            if (!preg_match('/\btype=["\'](submit|button)["\']/i', $a)) continue;
            $val = (string)self::attr($a, 'value');
            $cands[] = ['text' => $val, 'attrs' => $a, 'pos' => $x[0][1], 'input' => true];
        }
        foreach (['button', 'a'] as $tg) if (preg_match_all('/<' . $tg . '\b([^>]*)>(.*?)<\/' . $tg . '>/is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE))
            foreach ($m as $x) $cands[] = ['text' => self::text($x[2][0]), 'attrs' => $x[1][0], 'pos' => $x[0][1], 'input' => false];
        usort($cands, function ($a, $b) { return $a['pos'] <=> $b['pos']; });
        foreach ($cands as $c) {
            if (!preg_match($regex, pb_fa_norm($c['text']))) continue;
            $a = $c['attrs'];
            $name = (string)self::attr($a, 'name'); $id = (string)self::attr($a, 'id'); $on = (string)self::attr($a, 'onclick');
            $b = ['text' => $c['text'], 'name' => $name, 'id' => $id, 'onclick' => $on, 'input' => $c['input'], 'params' => [], 'idcl' => '', 'form' => '', 'pos' => $c['pos']];
            if (preg_match("/(?:oamSubmitForm|submitForm)\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'/", $on, $x)) { $b['form'] = $x[1]; $b['idcl'] = $x[2]; }
            if (preg_match("/'parameters'\\s*:\\s*\\{([^}]*)\\}/", $on, $x) && preg_match_all("/'([^']+)'\\s*:\\s*'([^']*)'/", $x[1], $pp, PREG_SET_ORDER)) foreach ($pp as $q) $b['params'][$q[1]] = $q[2];
            if (preg_match("/A4J\\.AJAX\\.Submit\\(\\s*'[^']*'\\s*,\\s*'([^']+)'/", $on, $x)) $b['form'] = $x[1];
            if ($b['input'] && $name !== '') $b['params'][$name] = $c['text'];
            if (!$b['input'] && !$b['idcl'] && !$b['params'] && $id !== '') $b['idcl'] = $id;
            return $b;
        }
        return null;
    }
    // فرستادنِ فرم (JSF) با مقدارها و دکمه‌ی زده‌شده
    public function post($url, $html, $formId, array $vals, $btn = null)
    {
        if ($btn && $btn['form']) $formId = $btn['form'];
        if ($formId === '') $formId = 'editform';
        $f = $this->fields($html, $formId);
        foreach ($vals as $k => $v) { if ($v === null) unset($f[$k]); else $f[$k] = (string)$v; }
        $vs = $f['javax.faces.ViewState'] ?? null;
        if ($vs === null && preg_match('/name=["\']javax\.faces\.ViewState["\'][^>]*value=["\']([^"\']*)["\']/i', $html, $m)) $vs = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        unset($f[$formId . '_SUBMIT'], $f['javax.faces.ViewState'], $f[$formId . ':_idcl']);
        $f[$formId . '_SUBMIT'] = '1';
        if ($vs !== null) $f['javax.faces.ViewState'] = $vs;
        if ($btn) { foreach ($btn['params'] as $k => $v) $f[$k] = $v; if ($btn['idcl']) $f[$formId . ':_idcl'] = $btn['idcl']; }
        $action = $url;
        if (preg_match('/<form\b[^>]*\b(?:id|name)=["\']' . preg_quote($formId, '/') . '["\'][^>]*>/i', $html, $fm) && preg_match('/\baction=["\']([^"\']+)["\']/i', $fm[0], $am)) $action = $this->abs($am[1], $url) ?: $url;
        return $this->http('POST', $action, $f, $url, true);
    }
    // مقدار برای یک خانه (+ همزادِ «_hidden» که کامپوننت‌های سامانه دارند)
    private function setVal(array &$vals, $c, $v)
    {
        if (!$c) return;
        $vals[$c['name']] = $v;
        $vals[$c['name'] . '_hidden'] = $v;
    }
    private function onchangeButton($c)
    {
        $on = (string)($c['onchange'] ?? '');
        $b = ['text' => '', 'name' => '', 'id' => $c['id'], 'onclick' => $on, 'input' => false, 'params' => [], 'idcl' => '', 'form' => $c['form'], 'pos' => 0];
        if (preg_match("/(?:oamSubmitForm|submitForm)\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'/", $on, $x)) { $b['form'] = $x[1]; $b['idcl'] = $x[2]; }
        if (preg_match("/'parameters'\\s*:\\s*\\{([^}]*)\\}/", $on, $x) && preg_match_all("/'([^']+)'\\s*:\\s*'([^']*)'/", $x[1], $pp, PREG_SET_ORDER)) foreach ($pp as $q) $b['params'][$q[1]] = $q[2];
        return $b;
    }

    // فایلِ چاپ: سامانه PDF را مستقیم نمی‌دهد؛ پاسخ یک قاب (iframe) به bimehReportServlet?printFileName=XXXX.pdf دارد که باید جدا گرفته شود
    private function servletPdf($html, $referer)
    {
        if (strncmp((string)$html, '%PDF', 4) === 0) return $html;
        if (!preg_match_all('/bimehReportServlet\?printFileName=([A-Za-z0-9_.\-]+)/', (string)$html, $m)) return null;
        foreach (array_unique($m[1]) as $fn) {
            if (strtolower($fn) === 'null') continue;
            $r = $this->http('GET', $this->core . '/cins/bimehReportServlet?printFileName=' . rawurlencode($fn), [], $referer);
            if (strncmp((string)$r['body'], '%PDF', 4) === 0) return $r['body'];
        }
        return null;
    }
    // خطای گذرای سامانه: فقط «requestID: [...]» بدونِ هیچ پیامِ دیگر (دوباره زدن معمولاً درست می‌شود)
    private static function transient(array $n) { $all = array_merge($n['errors'], $n['warnings'], $n['messages']); return $all && !array_filter($all, function ($x) { return !preg_match('/^\s*requestID\s*:\s*\[?[0-9a-f\-]+\]?\s*$/i', $x); }); }

    // ---- استعلامِ بدنه ----
    public function runBody(array $v, $wantPrint = true)
    {
        $pg = $this->resolvePage('body', ['انواع استعلام']);
        if (($pg['error'] ?? '') === 'need_login') return ['ok' => false, 'error' => 'need_login'];
        if (($pg['error'] ?? '') === 'not_found') return ['ok' => false, 'error' => 'صفحه‌ی «استعلام بدنه بیمه مرکزی» در منوی سامانه پیدا نشد؛ مدیر کل آدرسش را در «استعلامات ← تنظیمات ← پاسارگاد» بگذارد (راهنما همان‌جاست).', 'debug' => true];
        if (($pg['error'] ?? '') === 'down') return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد در دسترس نیست؛ چند لحظه‌ی دیگر دوباره بزنید.'];
        if (isset($pg['error'])) { if (!empty($pg['r']['body'])) $this->step('body-open', $pg['r']['body']); return ['ok' => false, 'error' => 'صفحه‌ی «استعلام بدنه بیمه مرکزی» باز نشد (سامانه صفحه‌ی دیگری برگرداند)؛ یک بار دیگر بزنید. اگر تکرار شد، مدیر کل آدرسِ صفحه را در تنظیمات دوباره بگذارد.', 'debug' => true]; }
        $r = $pg['r']; $html = $r['body']; $url = $pg['url'];
        $this->step('body-open', $html);
        $ctls = $this->controls($html);
        $sel = $this->findCtl($ctls, ['انواع استعلام', 'نوع استعلام'], 'select');
        if (!$sel) return ['ok' => false, 'error' => 'خانه‌ی «انواع استعلام» در صفحه پیدا نشد (صفحه‌ی ذخیره‌شده برای بررسی نگه داشته شد).', 'debug' => true];
        $opt = self::optionFor($sel, PB_TYPES[$v['type']]);
        if ($opt === null) return ['ok' => false, 'error' => 'گزینه‌ی «' . PB_TYPES[$v['type']] . '» در «انواع استعلام» نیست.', 'debug' => true];
        $vals = [];
        $this->setVal($vals, $sel, $opt);
        // انتخابِ نوع: فقط اگر سامانه با آن فرم را دوباره می‌سازد (submitForm) فرستاده می‌شود؛ در صفحه‌ی فعلی فقط اسکریپتِ مرورگر است
        $ob = $sel['onchange'] ? $this->onchangeButton($sel) : null;
        if ($ob && ($ob['idcl'] || $ob['params'])) {
            $r2 = $this->post($url, $html, $sel['form'], $vals, $ob);
            if ($this->loggedOut($r2['body'])) return ['ok' => false, 'error' => 'need_login'];
            if (strlen($r2['body']) > 500 && stripos($r2['body'], '<form') !== false) { $html = $r2['body']; $this->step('body-type', $html); $ctls = $this->controls($html); }
        }
        $p = $v['params'];
        $fill = function ($labels, $val, $tags = null) use (&$vals, $ctls) { $c = $this->findCtl($ctls, $labels, $tags); if ($c) $this->setVal($vals, $c, $val); return (bool)$c; };
        $sel2 = $this->findCtl($ctls, ['انواع استعلام', 'نوع استعلام'], 'select');
        if ($sel2) $this->setVal($vals, $sel2, $opt);
        $miss = [];
        if (isset($p['unique']) && !$fill(['کد یکتا'], $p['unique'])) $miss[] = 'کد یکتا';
        if (isset($p['print']) && !$fill(['شماره چاپی'], $p['print'])) $miss[] = 'شماره چاپی';
        if ($v['type'] === 'vin' && !$fill(['vin', 'وی آی ان'], $p['chassis'])) $miss[] = 'vin';
        if (in_array($v['type'], ['chassis', 'chassis_engine'], true) && !$fill(['شماره شاسی'], $p['chassis'])) $miss[] = 'شماره شاسی';
        if (isset($p['engine']) && !$fill(['شماره موتور'], $p['engine'])) $miss[] = 'شماره موتور';
        if ($v['type'] === 'plate') {
            if (!$fill(['دو رقم پلاک'], $p['p4'])) $miss[] = 'دو رقم پلاک';
            if (!$fill(['سه رقم پلاک'], $p['p2'])) $miss[] = 'سه رقم پلاک';
            $lc = $this->findCtl($ctls, ['حرف پلاک']);
            if ($lc) $this->setVal($vals, $lc, $lc['tag'] === 'select' ? (self::optionFor($lc, $p['letter']) ?? $p['letter']) : $p['letter']); else $miss[] = 'حرف پلاک';
            $sc = $this->findCtl($ctls, ['سریال پلاک']);
            // سریال: اول دقیقاً «۷۲(ایران)»، بعد هر گزینه‌ای که با همان عدد شروع شود (مناطقِ آزاد: «۱۱(قشم)» …)
            if ($sc) $this->setVal($vals, $sc, $sc['tag'] === 'select' ? (self::optionFor($sc, $p['p1'] . '(ایران)') ?? self::optionFor($sc, $p['p1'], true) ?? $p['p1']) : $p['p1']); else $miss[] = 'سریال پلاک';
        }
        if ($miss) return ['ok' => false, 'error' => 'این خانه‌ها در صفحه‌ی سامانه پیدا نشد: ' . implode('، ', $miss), 'debug' => true];
        $btn = $this->findButton($html, '/^استعلام$/u');
        if (!$btn) return ['ok' => false, 'error' => 'دکمه‌ی «استعلام» در صفحه پیدا نشد.', 'debug' => true];
        // خطای گذرای سامانه («requestID: [...]» بدونِ پیامِ دیگر): تا دو بار دوباره
        for ($try = 0; $try < 3; $try++) {
            $r3 = $this->post($url, $html, $sel['form'], $vals, $btn);
            if ($this->loggedOut($r3['body'])) return ['ok' => false, 'error' => 'need_login'];
            $html3 = $r3['body'];
            $this->step('body-result', $html3);
            $n = $this->parseNotices($html3);
            $rows = self::parseRows($html3);
            if ($rows || !self::transient($n)) break;
            if (stripos($html3, '<form') !== false) { $html = $html3; $btn = $this->findButton($html, '/^استعلام$/u') ?: $btn; }
            usleep(700000);
        }
        // چاپِ خودِ سامانه (PDF): دکمه‌ی «چاپ» => فایل از bimehReportServlet؛ اگر بارِ اول نیامد یک بارِ دیگر
        $print = null;
        if ($wantPrint && $rows) {
            $cur = $html3; $curUrl = $r3['url'] ?: $url;
            for ($try = 0; $try < 2 && $print === null; $try++) {
                $pb = $this->findButton($cur, '/^چاپ$/u');
                if (!$pb) break;
                $r4 = $this->post($curUrl, $cur, $sel['form'], $vals, $pb);
                if ($this->loggedOut($r4['body'])) break;
                $pdf = $this->servletPdf($r4['body'], $curUrl);
                if ($pdf !== null) { if ($this->jobDir !== '') { @file_put_contents($this->jobDir . '/print.pdf', $pdf); $print = 'print.pdf'; } break; }
                if (strlen($r4['body']) > 200) { $this->step('body-print', $r4['body']); if (stripos($r4['body'], '<form') !== false) { $cur = $r4['body']; $curUrl = $r4['url'] ?: $curUrl; } }
                usleep(700000);
            }
        }
        $msg = self::transient($n) ? '' : implode(' - ', array_merge($n['errors'], $n['warnings'], $n['messages']));
        if (!$rows && self::transient($n)) return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد چند بار خطای موقت داد (' . implode(' ', $n['errors']) . ')؛ چند لحظه‌ی دیگر دوباره بزنید.'];
        if (!$rows) {
            $nf = $msg !== '' ? $msg : (preg_match('/(یافت نشد|موردی|ثبت نشده)[^<]{0,80}/u', self::text($html3), $mm) ? $mm[0] : 'نتیجه‌ای برنگشت.');
            return ['ok' => true, 'rows' => [], 'message' => $nf, 'not_found' => true];
        }
        return ['ok' => true, 'rows' => $rows, 'message' => $msg, 'print' => $print];
    }

    // ردیف‌های نتیجه («ردیف1»، «ردیف2» …)؛ جدیدترین (پایانِ دیرتر) اول
    //   هر ردیف: جفت‌خانه‌های «برچسب / مقدار» (td.label / td.content؛ مقدارِ خالی خالی می‌ماند)
    //   + جدول‌های زیرِ همان ردیف: «لیست الحاقیه» (endorsements) و «لیست خسارت» (damages)؛ هر خانه چند مقدار با <br> زیرِ هم
    const PB_SUB_LABELS = ['ردیف' => 'n', 'تاریخ اثر الحاقیه' => 'effect_date', 'نوع الحاقیه' => 'endorse_type', 'پلاک وسیله نقلیه' => 'plate_text'];
    public static function parseRows($html)
    {
        $html = preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', (string)$html);
        if (!preg_match_all('/<div[^>]*class="[^"]*formHeader[^"]*"[^>]*>\s*ردیف\s*(\d+)\s*<\/div>/u', $html, $hm, PREG_OFFSET_CAPTURE)) return self::finishRows(self::parseRowsLoose($html));
        $lab = []; foreach (PB_LABELS as $k => $v) $lab[pb_fa_norm($k)] = $v;
        $dl = []; foreach (PB_DMG_LABELS as $k => $v) $dl[pb_fa_norm($k)] = $v;
        $sl = []; foreach (self::PB_SUB_LABELS as $k => $v) $sl[pb_fa_norm($k)] = $v;
        $rows = [];
        foreach ($hm[0] as $k => $h0) {
            $from = $h0[1] + strlen($h0[0]);
            $blk = substr($html, $from, (isset($hm[0][$k + 1]) ? $hm[0][$k + 1][1] : strlen($html)) - $from);
            $row = ['radif' => (int)$hm[1][$k][0], 'damages' => [], 'endorsements' => []];
            // جدول‌های زیر جدا خوانده و از متنِ اصلی برداشته می‌شوند
            $main = $blk;
            if (preg_match_all('/<table\b[^>]*>(?:(?!<table\b).)*?<thead\b.*?<\/table>/is', $blk, $tm, PREG_OFFSET_CAPTURE)) {
                foreach ($tm[0] as $t) {
                    $before = self::text(substr($blk, max(0, $t[1] - 600), min(600, $t[1])));
                    $isDmg = preg_match('/خسارت/u', mb_substr($before, -40)) || stripos(substr($t[0], 0, 200), 'khesarat') !== false;
                    $items = self::subTable($t[0], $isDmg ? $dl + $lab + $sl : $lab + $sl + $dl);
                    if ($isDmg) $row['damages'] = array_merge($row['damages'], $items); else $row['endorsements'] = array_merge($row['endorsements'], $items);
                    $main = str_replace($t[0], ' ', $main);
                }
            }
            if (preg_match_all('/<td[^>]*class="[^"]*\blabel\b[^"]*"[^>]*>(.*?)<\/td>\s*<td[^>]*class="[^"]*\bcontent\b[^"]*"[^>]*>(.*?)<\/td>/is', $main, $pm, PREG_SET_ORDER)) {
                foreach ($pm as $p) {
                    $key = pb_fa_norm(preg_replace('/[:：]\s*$/u', '', self::text($p[1])));
                    if (isset($lab[$key]) && !isset($row[$lab[$key]])) $row[$lab[$key]] = self::text($p[2]);
                }
            }
            // «لیست خسارت» بدونِ جدولِ سربرگ‌دار: همان روشِ متنیِ قبلی
            if (!$row['damages'] && strpos($main, 'لیست خسارت') !== false) $row['damages'] = self::damagesLoose(substr($main, strpos($main, 'لیست خسارت')));
            if (count($row) > 3) $rows[] = $row;
        }
        return self::finishRows($rows);
    }
    // جدولِ سربرگ‌دار: هر ستون چند برچسب (با <br>) و هر خانه همان تعداد مقدار
    private static function subTable($t, array $map)
    {
        $split = function ($h) { return array_map(function ($x) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($x), ENT_QUOTES, 'UTF-8'))); }, preg_split('/<br\s*\/?>/i', (string)$h)); };
        $heads = [];
        if (preg_match('/<thead\b.*?<\/thead>/is', $t, $th) && preg_match_all('/<th\b[^>]*>(.*?)<\/th>/is', $th[0], $hc)) foreach ($hc[1] as $h) $heads[] = $split($h);
        $out = [];
        $body = preg_match('/<tbody\b.*?<\/tbody>/is', $t, $tb) ? $tb[0] : $t;
        if (!preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $body, $tr)) return $out;
        foreach ($tr[1] as $r) {
            if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $r, $tc)) continue;
            $o = [];
            foreach ($tc[1] as $ci => $cell) {
                $vals = $split($cell);
                foreach (($heads[$ci] ?? []) as $li => $l) {
                    $key = pb_fa_norm($l);
                    if (isset($map[$key]) && !isset($o[$map[$key]])) $o[$map[$key]] = (string)($vals[$li] ?? '');
                }
            }
            if (array_filter($o, 'strlen')) $out[] = $o;
        }
        return $out;
    }
    // تبدیل‌های نهایی: تاریخ‌ها لاتین، شماره‌ی چاپی بدونِ «/0»، پلاک به چهار بخش، مرتب‌سازی
    public static function finishRows(array $rows, $sort = true)
    {
        // «ي/ك»ِ عربیِ سامانه => فارسی (نامِ بیمه‌گر و … با فهرست‌های سایت جور شود)
        $fa = function ($v) { return is_string($v) ? trim(strtr($v, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه'])) : $v; };
        foreach ($rows as &$r) {
            foreach ($r as $k => $v) if (is_string($v)) $r[$k] = $fa($v);
            foreach (['endorsements', 'damages'] as $sub) foreach (($r[$sub] ?? []) as $i => $e) foreach ($e as $k => $v) $r[$sub][$i][$k] = $fa($v);
            foreach (['issue_date', 'start_date', 'end_date'] as $k) if (isset($r[$k])) $r[$k] = pb_en($r[$k]);
            if (!empty($r['print_no'])) { if (!isset($r['print_no_raw'])) $r['print_no_raw'] = $r['print_no']; $r['print_no'] = pb_policy_no(pb_en($r['print_no'])); }
            foreach (['unique_code', 'prev_unique_code', 'no_loss_years', 'plate_2', 'plate_3'] as $k) if (isset($r[$k])) $r[$k] = pb_en($r[$k]);
            $r['plate_parts'] = (!empty($r['plate_3']) && !empty($r['plate_2'])) ? ['p1' => preg_replace('/\D/', '', pb_en($r['plate_serial'] ?? '')), 'p2' => pb_en($r['plate_3']), 'letter' => trim((string)($r['plate_letter'] ?? '')), 'p4' => pb_en($r['plate_2'])] : null;
            // همان قالبِ پلاک در کلِ سایت: «۷۲ایران - ۳۵۸ ل ۲۷»
            $r['plate_fa'] = $r['plate_parts'] ? "{$r['plate_parts']['p1']}ایران - {$r['plate_parts']['p2']} {$r['plate_parts']['letter']} {$r['plate_parts']['p4']}" : '';
            foreach (($r['endorsements'] ?? []) as &$e) foreach (['issue_date', 'effect_date', 'print_no', 'unique_code', 'no_loss_years'] as $k) if (isset($e[$k])) $e[$k] = pb_en($e[$k]);
            unset($e);
        }
        unset($r);
        if ($sort) usort($rows, function ($a, $b) { return strcmp(pb_date_key($b['end_date'] ?? ''), pb_date_key($a['end_date'] ?? '')); });
        return $rows;
    }
    private static function tokens($seg)
    {
        $out = [];
        foreach (preg_split('/<[^>]+>/', $seg) as $t) { $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES, 'UTF-8'))); if ($t !== '') $out[] = $t; }
        return $out;
    }
    // خسارت‌ها از متنِ پشتِ سرِ هم (برچسب، مقدار، برچسب، …)
    private static function damagesLoose($seg)
    {
        $dl = []; foreach (PB_DMG_LABELS as $k => $v) $dl[pb_fa_norm($k)] = $v;
        $tk = self::tokens($seg); $n = count($tk); $out = []; $d = null;
        for ($i = 0; $i < $n; $i++) {
            $val = null; $key = pb_fa_norm($tk[$i]);
            if (preg_match('/^(.+?)\s*[:：]\s*(.*)$/u', $tk[$i], $kv)) { $key = pb_fa_norm($kv[1]); $val = trim($kv[2]); }
            if (!isset($dl[$key])) continue;
            if ($val === null || $val === '') $val = ($i + 1 < $n && !isset($dl[pb_fa_norm(preg_replace('/[:：].*$/u', '', $tk[$i + 1]))])) ? $tk[++$i] : '';
            if ($dl[$key] === 'case_code' && $d) { $out[] = $d; $d = null; }
            $d = $d ?: []; $d[$dl[$key]] = $val;
        }
        if ($d) $out[] = $d;
        return $out;
    }
    // روشِ قدیمی (متنی) برای صفحه‌ای که ساختارِ «ردیف/برچسب/مقدار» ندارد
    private static function parseRowsLoose($html)
    {
        $start = strpos($html, 'نتیجه استعلام');
        $tokens = self::tokens($start === false ? $html : substr($html, $start));
        $lab = []; foreach (PB_LABELS as $k => $v) $lab[pb_fa_norm($k)] = $v;
        $dl = []; foreach (PB_DMG_LABELS as $k => $v) $dl[pb_fa_norm($k)] = $v;
        $isLabel = function ($t) use ($lab, $dl) { $n = pb_fa_norm(preg_replace('/[:：].*$/u', '', $t)); return isset($lab[$n]) || isset($dl[$n]) || preg_match('/^ردیف\d+$/u', pb_fa_norm($t)) || preg_match('/^لیست/u', $n); };
        $rows = []; $cur = null; $dmgMode = false; $dmg = null;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i]; $nt = pb_fa_norm(pb_en($t));
            if (preg_match('/^ردیف(\d+)$/u', $nt, $m)) {
                if ($cur) { if ($dmg) $cur['damages'][] = $dmg; $rows[] = $cur; }
                $cur = ['radif' => (int)$m[1], 'damages' => []]; $dmgMode = false; $dmg = null; continue;
            }
            if (!$cur) continue;
            if ($nt === pb_fa_norm('لیست خسارت')) { $dmgMode = true; continue; }
            $val = null; $key = $nt;
            if (preg_match('/^(.+?)\s*[:：]\s*(.+)$/u', $t, $kv)) { $key = pb_fa_norm($kv[1]); $val = trim($kv[2]); }
            $map = $dmgMode && isset($dl[$key]) ? $dl : (isset($lab[$key]) ? $lab : (isset($dl[$key]) ? $dl : null));
            if (!$map) continue;
            if ($val === null) { $val = ($i + 1 < $n && !$isLabel($tokens[$i + 1])) ? $tokens[++$i] : ''; }
            if ($map === $dl) {
                $dmgMode = true;
                if ($dl[$key] === 'case_code') { if ($dmg) $cur['damages'][] = $dmg; $dmg = []; }
                if ($dmg === null) $dmg = [];
                $dmg[$dl[$key]] = $val;
            } elseif (!isset($cur[$lab[$key]])) $cur[$lab[$key]] = $val;
        }
        if ($cur) { if ($dmg) $cur['damages'][] = $dmg; $rows[] = $cur; }
        return array_values(array_filter($rows, function ($r) { return count($r) > 2; }));
    }

    // ---- استعلامِ خسارت ----
    public function runDamage(array $v)
    {
        $pg = $this->resolvePage('damage', ['فرمت فایل خروجی', 'فرمت فایل خروجي']);
        if (($pg['error'] ?? '') === 'need_login') return ['ok' => false, 'error' => 'need_login'];
        if (($pg['error'] ?? '') === 'not_found') return ['ok' => false, 'error' => 'صفحه‌ی «استعلام خسارت» در منوی سامانه پیدا نشد؛ مدیر کل آدرسش را در «استعلامات ← تنظیمات ← پاسارگاد» بگذارد (راهنما همان‌جاست).', 'debug' => true];
        if (($pg['error'] ?? '') === 'down') return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد در دسترس نیست؛ چند لحظه‌ی دیگر دوباره بزنید.'];
        if (isset($pg['error'])) { if (!empty($pg['r']['body'])) $this->step('damage-open', $pg['r']['body']); return ['ok' => false, 'error' => 'صفحه‌ی «استعلام خسارت» باز نشد (سامانه صفحه‌ی دیگری برگرداند)؛ یک بار دیگر بزنید. اگر تکرار شد، مدیر کل آدرسِ صفحه را در تنظیمات دوباره بگذارد.', 'debug' => true]; }
        $r = $pg['r']; $html = $r['body']; $url = $pg['url'];
        $this->step('damage-open', $html);
        $ctls = $this->controls($html);
        $p = $v['params'];
        $vals = []; $form = '';
        $fmt = $this->findCtl($ctls, ['فرمت فایل خروجی'], 'select');
        if ($fmt) { $this->setVal($vals, $fmt, self::optionFor($fmt, 'PDF') ?? 'PDF'); $form = $fmt['form']; }
        $pol = $this->findCtl($ctls, ['شماره بیمه نامه']);
        if ($pol && $p['policy_no'] !== '') { $this->setVal($vals, $pol, $p['policy_no']); $form = $form ?: $pol['form']; }
        $ins = $this->findCtl($ctls, ['نوع بیمه'], 'select');
        if ($ins) $this->setVal($vals, $ins, self::optionFor($ins, PB_INS[$p['ins']]) ?? self::optionFor($ins, 'هردو') ?? '');
        $ch = $this->findCtl($ctls, ['شماره شاسی']); if ($ch && $p['chassis'] !== '') { $this->setVal($vals, $ch, $p['chassis']); $form = $form ?: $ch['form']; }
        $en = $this->findCtl($ctls, ['شماره موتور']); if ($en && $p['engine'] !== '') { $this->setVal($vals, $en, $p['engine']); $form = $form ?: $en['form']; }
        if (!$pol && !$ch && !$en) return ['ok' => false, 'error' => 'خانه‌های «شماره بیمه نامه / شاسی / موتور» در صفحه‌ی استعلامِ خسارت پیدا نشد.', 'debug' => true];
        // دکمه‌ی صفحه: «تولید گزارش» (در نسخه‌های دیگر: استعلام/جستجو/گزارش)
        $btn = $this->findButton($html, '/^(تولیدگزارش|استعلام|جستجو|دریافتگزارش|گزارش|چاپ|دریافتفایل)$/u');
        if (!$btn) return ['ok' => false, 'error' => 'دکمه‌ی «تولید گزارش» در صفحه‌ی استعلامِ خسارت پیدا نشد.', 'debug' => true];
        $used = ['format' => $fmt ? 'PDF' : '', 'policy_no' => $p['policy_no'], 'ins' => PB_INS[$p['ins']], 'chassis' => $p['chassis'], 'engine' => $p['engine']];
        for ($try = 0; $try < 3; $try++) {
            $r2 = $this->post($url, $html, $form, $vals, $btn);
            if ($this->loggedOut($r2['body'])) return ['ok' => false, 'error' => 'need_login'];
            // خسارت دارد: فایلِ PDFِ سامانه (مستقیم یا از bimehReportServlet)
            $pdf = $this->servletPdf($r2['body'], $url);
            if ($pdf !== null) {
                if ($this->jobDir !== '') @file_put_contents($this->jobDir . '/damage.pdf', $pdf);
                return ['ok' => true, 'found' => true, 'pdf' => 'damage.pdf', 'message' => 'خسارت ثبت شده است (فایلِ PDFِ سامانه).', 'used' => $used];
            }
            $this->step('damage-result', $r2['body']);
            $n = $this->parseNotices($r2['body']);
            if (!self::transient($n)) break;
            if (stripos($r2['body'], '<form') !== false) { $html = $r2['body']; $btn = $this->findButton($html, '/^(تولیدگزارش|استعلام|جستجو|دریافتگزارش|گزارش|چاپ|دریافتفایل)$/u') ?: $btn; }
            usleep(700000);
        }
        if (self::transient($n)) return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد چند بار خطای موقت داد (' . implode(' ', $n['errors']) . ')؛ چند لحظه‌ی دیگر دوباره بزنید.'];
        $all = array_merge($n['errors'], $n['warnings'], $n['messages']);
        $txt = self::text($r2['body']);
        $msg = $all ? implode(' - ', $all) : (preg_match('/اطلاعات خسارتی[^.،<]{0,80}ثبت نشده است/u', $txt, $mm) ? $mm[0] : '');
        $noDmg = (bool)preg_match('/ثبت نشده|یافت نشد|وجود ندارد/u', $msg ?: $txt);
        // نتیجه‌ی صفحه‌ای (خسارت دارد ولی فایل نداد): ردیف‌های جدول
        if (!$noDmg) {
            $rows = self::parseRows($r2['body']);
            return ['ok' => true, 'found' => true, 'pdf' => null, 'rows' => $rows, 'message' => $msg ?: 'پاسخِ سامانه خوانده شد؛ فایلِ PDF نیامد.', 'used' => $used];
        }
        return ['ok' => true, 'found' => false, 'pdf' => null, 'message' => $msg ?: 'اطلاعات خسارتی با مشخصات درخواستی ثبت نشده است', 'used' => $used];
    }
}

function pb_date_key($d) {
    $d = pb_en((string)$d);
    return preg_match('/(1[34]\d\d)\D(\d{1,2})\D(\d{1,2})/', $d, $m) ? sprintf('%04d%02d%02d', $m[1], $m[2], $m[3]) : '';
}

// =====================================================================
//  «تصویرِ صفحه»ی استعلامِ خسارت (خسارتی ثبت نشده): همان چیدمانِ صفحه‌ی سامانه با مقدارهای جستجو
// =====================================================================
function pb_font($bold = false) {
    $f = pb_dir('font') . '/' . ($bold ? 'vazirb' : 'vazir') . '.ttf';
    if (!is_file($f)) {
        $z = dirname(__DIR__) . '/lib/tcpdf/fonts/' . ($bold ? 'vazirb' : 'vazir') . '.z';
        $d = is_file($z) ? @gzuncompress((string)file_get_contents($z)) : false;
        if ($d) @file_put_contents($f, $d);
    }
    return $f;
}
// متنِ فارسی برای GD: شکلِ حروف و ترتیبِ راست‌به‌چپ (همان موتورِ TCPDF)
function pb_gd_text($s) {
    if (!class_exists('TCPDF_FONTS')) {
        if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
        require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    }
    if (!preg_match('/[\x{0600}-\x{06FF}]/u', (string)$s)) return (string)$s;
    $font = ['subsetchars' => [], 'cw' => []];
    $arr = TCPDF_FONTS::UTF8StringToArray((string)$s, true, $font);
    $o = TCPDF_FONTS::utf8Bidi($arr, '', true, true, $font);
    return implode('', array_map(function ($c) { return mb_chr($c, 'UTF-8'); }, $o));
}
function pb_shot_png(array $used, $message, $dest, $footer = '') {
    if (!function_exists('imagettftext')) return false;
    $W = 1223; $H = 700;
    $im = imagecreatetruecolor($W, $H);
    $col = function ($hex) use ($im) { return imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))); };
    $F = pb_font(); $FB = pb_font(true);
    imagefill($im, 0, 0, $col('#eef0f3'));
    $rtext = function ($txt, $right, $y, $size, $c, $font = null) use ($im, $F) {   // راست‌چین تا $right
        $t = pb_gd_text($txt); $font = $font ?: $F;
        $bb = imagettfbbox($size, 0, $font, $t); $w = abs($bb[2] - $bb[0]);
        imagettftext($im, $size, 0, (int)($right - $w), (int)$y, $c, $font, $t);
    };
    $box = function ($x1, $y1, $x2, $y2, $fill, $border) use ($im) { imagefilledrectangle($im, $x1, $y1, $x2, $y2, $fill); imagerectangle($im, $x1, $y1, $x2, $y2, $border); };
    $txt = $col('#333333'); $lbl = $col('#555555'); $bd = $col('#cfd4da'); $white = $col('#ffffff'); $inBg = $col('#f8f9fa');
    // کارتِ پیام (بالا)
    imagefilledrectangle($im, 28, 14, $W - 28, 82, $col('#d5d8dc'));
    $box(24, 10, $W - 30, 78, $white, $col('#c8ccd1'));
    imagettftext($im, 10, 0, 30, 24, $col('#666666'), $FB, 'x');
    $box(44, 30, $W - 30, 56, $col('#f2dede'), $col('#dca7a7'));
    $rtext($message ?: 'اطلاعات خسارتی با مشخصات درخواستی ثبت نشده است', $W - 70, 48, 10.5, $col('#a94442'));
    imagettftext($im, 9, 0, $W - 48, 47, $col('#a94442'), $FB, 'x');
    imagefilledellipse($im, $W - 60, 43, 9, 9, $col('#a9a9a9'));
    // بخشِ اول: فرمِ جستجو
    $box(24, 92, $W - 30, 312, $white, $bd);
    // سه ستون (راست به چپ): برچسب تا x، خانه از x1 تا x2
    $cols = [[1175, 818, 1008], [750, 430, 620], [360, 40, 230]];
    $field = function ($ci, $y, $label, $value, $kind = 'text', $req = false) use ($cols, $box, $rtext, $im, $col, $txt, $lbl, $bd, $white, $inBg, $F) {
        [$lx, $x1, $x2] = $cols[$ci];
        $rtext($label, $lx, $y + 19, 9.5, $lbl);
        if ($req) imagettftext($im, 10, 0, $lx - 2 - (int)abs(imagettfbbox(9.5, 0, $F, pb_gd_text($label))[2]) - 10, $y + 20, $col('#d9534f'), $F, '*');
        $box($x1, $y + 2, $x2, $y + 30, $kind === 'date' ? $inBg : $white, $bd);
        if ($kind === 'select' || $kind === 'selectsm') {
            $cx = $x1 + 12; $cy = $y + 16;
            if ($kind === 'select') imagefilledpolygon($im, [$cx - 5, $cy - 3, $cx + 5, $cy - 3, $cx, $cy + 3], 3, $col('#333333'));
            else imagefilledellipse($im, $cx, $cy, 4, 4, $col('#333333'));
        }
        if ($kind === 'lov') { imagerectangle($im, $x1 + 10, $y + 10, $x1 + 20, $y + 20, $col('#337ab7')); imagefilledellipse($im, $x1 + 36, $y + 15, 10, 10, $col('#337ab7')); }
        if ($kind === 'date') { foreach ([[$x1 + 40, $x1 + 72], [$x1 + 82, $x1 + 106], [$x1 + 116, $x1 + 140]] as [$a, $b]) $box($a, $y + 7, $b, $y + 25, $white, $bd); imagefilledrectangle($im, $x1 + 10, $y + 10, $x1 + 20, $y + 21, $col('#337ab7')); }
        if ($value !== '' && $value !== null) $rtext($value, $x2 - 8, $y + 21, 9.5, $txt);
    };
    $y = 104;
    $field(0, $y, 'فرمت فایل خروجی', $used['format'] ?? 'PDF', 'selectsm', true);
    $y += 40;
    $field(0, $y, 'نوع جستجوی شماره بیمه نامه', '---', 'selectsm'); $field(1, $y, 'شماره بیمه نامه', $used['policy_no'] ?? ''); $field(2, $y, 'نوع بیمه', $used['ins'] ?? 'هردو', 'select');
    $y += 40;
    $field(0, $y, 'از تاریخ صدور بیمه نامه', '', 'date'); $field(1, $y, 'تا تاریخ صدور بیمه نامه', '', 'date'); $field(2, $y, 'تعهدات موافقت نامه', '', 'lov');
    $y += 40;
    $field(0, $y, 'واحد صدور', '', 'lov'); $field(1, $y, 'زیان دیده', '', 'lov'); $field(2, $y, 'بیمه گذار', '', 'lov');
    $y += 40;
    $field(0, $y, 'کد رهگیری حادثه خسارت', '');
    // بخشِ دوم: وسیله‌ی نقلیه‌ی بیمه‌نامه
    $sec = function ($y1, $y2, $title) use ($box, $im, $col, $rtext, $W, $bd, $white, $FB) {
        $box(24, $y1, $W - 30, $y2, $white, $bd);
        imagefilledrectangle($im, 25, $y1 + 1, $W - 31, $y1 + 48, $col('#f5f5f5'));
        imageline($im, 25, $y1 + 49, $W - 31, $y1 + 49, $bd);
        $rtext($title, $W - 78, $y1 + 31, 10.5, $col('#2a6496'), $FB);
        imagerectangle($im, $W - 72, $y1 + 18, $W - 62, $y1 + 28, $col('#2a6496'));
        imageline($im, $W - 52, $y1 + 21, $W - 47, $y1 + 26, $col('#2a6496')); imageline($im, $W - 47, $y1 + 26, $W - 42, $y1 + 21, $col('#2a6496'));
    };
    $sec(330, 612, 'اطلاعات وسیله نقلیه بیمه نامه');
    $y = 390;
    $field(0, $y, 'دو رقم شماره انتظامی', ''); $field(1, $y, 'حرف شماره انتظامی', '', 'lov'); $field(2, $y, 'سه رقم شماره انتظامی', '');
    $y += 40;
    $field(0, $y, 'سریال پلاک', '', 'lov'); $field(1, $y, 'شماره موتور', $used['engine'] ?? ''); $field(2, $y, 'شماره شاسی', $used['chassis'] ?? '');
    $y += 40;
    $field(0, $y, 'وی آی ان', ''); $field(1, $y, 'صفر کیلومتر است؟', '---', 'select'); $field(2, $y, 'تقویم وسیله نقلیه', '---', 'select');
    $y += 40;
    $field(0, $y, 'سال ساخت', ''); $field(1, $y, 'تیپ', '', 'lov'); $field(2, $y, 'رنگ خودرو', '---', 'select');
    $y += 40;
    $field(0, $y, 'اتاق بار', '', 'lov');
    // بخشِ سوم (فقط سرتیتر، مثلِ صفحه)
    $sec(628, 690, 'اطلاعات وسیله نقلیه زیاندیده');
    // پانویس: متنِ فارسی سمتِ راست، تاریخ و ساعت (لاتین) سمتِ چپ تا ترتیبشان به‌هم نریزد
    if ($footer !== '') {
        imagefilledrectangle($im, 0, $H - 18, $W, $H, $col('#e2e6ea'));
        $fp = explode('|', $footer, 2);
        $rtext(trim($fp[0]), $W - 12, $H - 5, 7.5, $col('#6c757d'));
        if (isset($fp[1])) imagettftext($im, 7.5, 0, 12, $H - 5, $col('#6c757d'), $F, trim($fp[1]));
    }
    $ok = imagepng($im, $dest);
    imagedestroy($im);
    return $ok;
}
// همان تصویر در یک صفحه‌ی PDF (وقتی «خروجیِ PDF» خواسته شده ولی سامانه PDF نداده؛ یعنی خسارتی ثبت نشده)
function pb_png_to_pdf($png, $dest) {
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); $pdf->SetMargins(6, 6, 6); $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->Image($png, 6, 6, 285, 0, 'PNG', '', '', true, 200, '', false, false, 0, 'CT');
    $pdf->Output($dest, 'F');
    return is_file($dest);
}

// =====================================================================
//  ثبت، تاریخچه و خروجی
// =====================================================================
function pb_job_dir() { $d = pb_dir('jobs/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3))); return $d; }
function pb_row_dir(array $r) {
    $res = json_decode((string)($r['result_json'] ?? ''), true) ?: [];
    $d = (string)($res['dir'] ?? '');
    if ($d === '' || !preg_match('#^jobs/[0-9A-Za-z\-]+$#', $d)) return '';
    $p = pb_dir() . '/' . $d;
    return is_dir($p) ? $p : '';
}
function pb_user_used_today($pdo, $type, $id) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM inq_requests WHERE kind IN ('pbody', 'pdamage') AND user_type = ? AND user_id = ? AND created_at >= CURDATE()");
    $st->execute([$type, intval($id)]);
    return intval($st->fetchColumn());
}
function pb_store($pdo, $kind, $status, $notice, $person, array $res, $label, array $ctx, $ms) {
    // ردیفِ استعلامِ گروهی: همان ردیفِ صف به‌روز می‌شود
    if (!empty($ctx['row_id'])) {
        $pdo->prepare("UPDATE inq_requests SET status = ?, notice = ?, person_name = ?, result_json = ?, finished_at = NOW(), ms = ?, query_text = ? WHERE id = ?")
            ->execute([$status, mb_substr((string)$notice, 0, 600), $person ? mb_substr($person, 0, 190) : null, json_encode($res, JSON_UNESCAPED_UNICODE), $ms, mb_substr($label, 0, 190), intval($ctx['row_id'])]);
        return intval($ctx['row_id']);
    }
    $actor = $ctx['actor'] ?? inq_actor_from_session();
    $refType = isset($ctx['ref_type']) && preg_match('/^[a-z_]{1,16}$/', (string)$ctx['ref_type']) ? (string)$ctx['ref_type'] : null;
    $pdo->prepare("INSERT INTO inq_requests (kind, national_id, status, notice, person_name, result_json, source, user_type, user_id, user_name, created_at, started_at, finished_at, ms, query_text, ref_type, ref_id)
                   VALUES (?, '', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), ?, ?, ?, ?)")
        ->execute([$kind, $status, mb_substr((string)$notice, 0, 600), $person ? mb_substr($person, 0, 190) : null, json_encode($res, JSON_UNESCAPED_UNICODE),
                   in_array($ctx['source'] ?? '', ['form', 'panel'], true) ? $ctx['source'] : 'panel', $actor[0], $actor[1] ?: null, mb_substr((string)$actor[2], 0, 190), $ms,
                   mb_substr($label, 0, 190), $refType, $refType ? intval($ctx['ref_id'] ?? 0) : null]);
    return intval($pdo->lastInsertId());
}
function pb_guard($pdo, $ctx) {
    if (!pb_enabled($pdo)) return ['ok' => false, 'error' => 'استعلامِ بدنه/خسارتِ پاسارگاد در تنظیمات خاموش است.'];
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'افزونه‌ی curl روی سرور فعال نیست.'];
    if (inq_captcha_pending()) return ['ok' => false, 'need_login' => true, 'error' => 'برای انجامِ استعلام، کدِ تصویرِ امنیتی را بزنید.'];
    $actor = $ctx['actor'] ?? inq_actor_from_session();
    if (empty($ctx['group']) && ($lim = (int)inq_limit_value(inq_get_setting($pdo, 'inq_pb_daily_limit'))) > 0) {
        $li = inq_user_limit_info($pdo, $actor[0], $actor[1]);
        if (!$li['admin'] && pb_user_used_today($pdo, $actor[0], $actor[1]) >= $lim)
            return ['ok' => false, 'error' => 'سقفِ استعلامِ بدنه/خسارتِ امروزِ شما (' . $lim . ') پر شده است؛ به مدیر کل اطلاع دهید.'];
    }
    return null;
}

// استعلامِ بدنه و ثبتِ آن
function pb_search($pdo, array $in, array $ctx = []) {
    inq_ensure($pdo);
    $v = pb_validate($in);
    if (!$v['ok']) return $v;
    if ($g = pb_guard($pdo, $ctx)) return $g;
    $lk = inq_lock(60);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد مشغولِ استعلامِ دیگری است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    $dir = pb_job_dir(); $t0 = microtime(true);
    try { @set_time_limit(180); $r = (new InqPasBody($pdo, $dir))->runBody($v, !isset($in['want_print']) || !empty($in['want_print'])); }
    catch (Throwable $e) { $r = ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
    if (($r['error'] ?? '') === 'need_login') { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'برای انجامِ استعلام، کدِ تصویرِ امنیتی را بزنید.']; }
    inq_px_mark('logged_in'); inq_touch_activity();
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $res = ['q' => ['type' => $v['type'], 'params' => $v['params']], 'dir' => substr($dir, strlen(pb_dir()) + 1)];
    if (empty($r['ok'])) {
        $res['pbody'] = ['rows' => [], 'message' => $r['error'] ?? 'خطا', 'debug' => !empty($r['debug'])];
        $id = pb_store($pdo, 'pbody', 'FAIL', $r['error'] ?? 'خطا', null, $res, $v['label'], $ctx, $ms);
        return ['ok' => false, 'error' => $r['error'] ?? 'استعلام انجام نشد.', 'id' => $id, 'debug' => !empty($r['debug'])];
    }
    $res['pbody'] = ['rows' => $r['rows'], 'message' => $r['message'] ?? '', 'print' => $r['print'] ?? null, 'not_found' => !empty($r['not_found'])];
    $first = $r['rows'][0] ?? [];
    $status = $r['rows'] ? 'OK' : 'WARN';
    $notice = $r['rows'] ? (count($r['rows']) . ' بیمه‌نامه' . (!empty($first['end_date']) ? ' · آخرین تا ' . $first['end_date'] : '') . (!empty($first['company']) ? ' · ' . $first['company'] : '')) : ($r['message'] ?? 'یافت نشد');
    $id = pb_store($pdo, 'pbody', $status, $notice, $first['insured'] ?? null, $res, $v['label'], $ctx, $ms);
    return ['ok' => true, 'item' => pb_out(inq_get($pdo, $id))];
}

// استعلامِ خسارت و ثبتِ آن؛ ctx.save = ['type' => C|P, 'id'] => فایل (با مهر) به‌عنوانِ «استعلام خسارت»ِ همان ردیف
function pb_damage($pdo, array $in, array $ctx = []) {
    inq_ensure($pdo);
    $v = pb_damage_validate($in);
    if (!$v['ok']) return $v;
    if ($g = pb_guard($pdo, $ctx)) return $g;
    $lk = inq_lock(60);
    if (!$lk) return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد مشغولِ استعلامِ دیگری است؛ چند ثانیه‌ی دیگر دوباره بزنید.'];
    $dir = pb_job_dir(); $t0 = microtime(true);
    try { @set_time_limit(180); $r = (new InqPasBody($pdo, $dir))->runDamage($v); }
    catch (Throwable $e) { $r = ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
    if (($r['error'] ?? '') === 'need_login') { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'برای انجامِ استعلام، کدِ تصویرِ امنیتی را بزنید.']; }
    inq_px_mark('logged_in'); inq_touch_activity();
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $res = ['q' => $v['params'] + ['out' => $v['out']], 'dir' => substr($dir, strlen(pb_dir()) + 1)];
    if (empty($r['ok'])) {
        $res['pdamage'] = ['message' => $r['error'] ?? 'خطا', 'debug' => !empty($r['debug'])];
        $id = pb_store($pdo, 'pdamage', 'FAIL', $r['error'] ?? 'خطا', null, $res, $v['label'], $ctx, $ms);
        return ['ok' => false, 'error' => $r['error'] ?? 'استعلام انجام نشد.', 'id' => $id, 'debug' => !empty($r['debug'])];
    }
    // «تصویرِ صفحه» وقتی خسارتی ثبت نشده (همیشه ساخته می‌شود تا بعداً هم بشود ذخیره‌اش کرد)
    $shot = null;
    if (!$r['found']) {
        $actor = $ctx['actor'] ?? inq_actor_from_session();
        [$jy, $jm, $jd] = jalali_from_gregorian_ts(time());
        $foot = 'سامانه‌ی پاسارگاد — استعلام خسارت' . ($actor[2] ? ' · ' . $actor[2] : '') . ' | ' . sprintf('%04d/%02d/%02d  %s', $jy, $jm, $jd, date('H:i'));
        if (pb_shot_png($r['used'], $r['message'], $dir . '/shot.png', $foot)) $shot = 'shot.png';
    }
    $res['pdamage'] = ['found' => $r['found'], 'message' => $r['message'], 'pdf' => $r['pdf'] ?? null, 'shot' => $shot, 'rows' => $r['rows'] ?? [], 'used' => $r['used']];
    $status = $r['found'] ? 'WARN' : 'OK';
    $id = pb_store($pdo, 'pdamage', $status, $r['found'] ? 'خسارت دارد' : 'عدمِ خسارت: ' . $r['message'], null, $res, $v['label'], $ctx, $ms);
    $out = ['ok' => true, 'item' => pb_out(inq_get($pdo, $id))];
    if (!empty($ctx['save'])) $out['saved'] = pb_save_doc($pdo, inq_get($pdo, $id), $ctx['save']['type'], $ctx['save']['id'], $v['out'], $ctx['actor'][1] ?? null);
    return $out;
}

// فایلِ خروجیِ استعلامِ خسارت => مدرکِ «استعلام خسارت»ِ ردیف/پرونده (با مهر). which: pdf | shot
function pb_save_doc($pdo, $row, $type, $id, $which, $userId = null) {
    require_once __DIR__ . '/_damage_inq.php';
    if (!$row || $row['kind'] !== 'pdamage') return ['ok' => false, 'error' => 'استعلامِ خسارت پیدا نشد.'];
    $dir = pb_row_dir($row);
    $res = json_decode((string)$row['result_json'], true) ?: [];
    $d = $res['pdamage'] ?? [];
    if ($dir === '') return ['ok' => false, 'error' => 'فایل‌های این استعلام پیدا نشد.'];
    $tmp = inq_dir('tmp') . '/' . bin2hex(random_bytes(6));
    if (!empty($d['pdf']) && is_file($dir . '/' . $d['pdf'])) { $tmp .= '.pdf'; @copy($dir . '/' . $d['pdf'], $tmp); }
    elseif (!empty($d['shot']) && is_file($dir . '/' . $d['shot'])) {
        if ($which === 'pdf') { $tmp .= '.pdf'; if (!pb_png_to_pdf($dir . '/' . $d['shot'], $tmp)) return ['ok' => false, 'error' => 'ساختِ PDF ممکن نشد.']; }
        else { $tmp .= '.png'; @copy($dir . '/' . $d['shot'], $tmp); }
    } else return ['ok' => false, 'error' => 'فایلی برای ذخیره نیست (سامانه PDF نداد و تصویری ساخته نشد).'];
    $r = dmg_save($pdo, $type === 'P' ? 'P' : 'C', intval($id), $tmp, $userId);
    if (is_file($tmp)) @unlink($tmp);
    return $r;
}

function pb_out($r) {
    if (!$r) return null;
    $o = inq_out($r, false);
    $res = json_decode((string)($r['result_json'] ?? ''), true) ?: [];
    $o['query'] = $r['query_text'] ?? '';
    $o['q'] = $res['q'] ?? [];
    $o['ref_type'] = $r['ref_type'] ?? null; $o['ref_id'] = isset($r['ref_id']) ? intval($r['ref_id']) : null;
    if ($r['kind'] === 'pbody') {
        $o['pbody'] = $res['pbody'] ?? null;
        // استعلام‌های قدیمیِ تاریخچه هم با همان قالبِ تازه (شماره بدونِ «/0»، پلاکِ سایت، «ی/ک»ِ فارسی)؛ داده‌ی ذخیره‌شده دست نمی‌خورد
        if (!empty($o['pbody']['rows']) && is_array($o['pbody']['rows'])) $o['pbody']['rows'] = InqPasBody::finishRows($o['pbody']['rows'], false);
        $o['pb_type_fa'] = PB_TYPES[$res['q']['type'] ?? ''] ?? '';
    }
    else { $o['pdamage'] = $res['pdamage'] ?? null; }
    return $o;
}
function pb_last($pdo, $refType, $refId, array $labels = []) {
    $w = []; $a = [];
    if ($refType && $refId) { $w[] = '(ref_type = ? AND ref_id = ?)'; $a[] = (string)$refType; $a[] = intval($refId); }
    $labels = array_values(array_filter($labels, 'strlen'));
    if ($labels) { $w[] = 'query_text IN (' . implode(',', array_fill(0, count($labels), '?')) . ')'; $a = array_merge($a, $labels); }
    if (!$w) return null;
    $st = $pdo->prepare("SELECT * FROM inq_requests WHERE kind = 'pbody' AND status IN ('OK', 'WARN') AND (" . implode(' OR ', $w) . ") ORDER BY id DESC LIMIT 1");
    $st->execute($a);
    return pb_out($st->fetch() ?: null);
}

// ---- خروجی‌ها ----
const PB_COLS = [['radif', 'ردیف'], ['company', 'بیمه‌گر'], ['unique_code', 'کد یکتا'], ['print_no', 'شماره چاپی'], ['insured', 'بیمه‌گذار'], ['issue_date', 'تاریخ صدور'],
                 ['start_date', 'شروع'], ['end_date', 'پایان'], ['plate_fa', 'پلاک'], ['plate_kind', 'نوع پلاک'], ['chassis', 'شاسی'], ['engine', 'موتور'], ['vin', 'VIN'],
                 ['car_group', 'گروه خودرو'], ['car_system', 'سیستم'], ['no_loss_years', 'سال تخفیف عدم خسارت'], ['prev_unique_code', 'کد یکتا بیمه‌نامه‌ی قبل']];
function pb_rows_of(array $r, array $sel) {
    $res = json_decode((string)($r['result_json'] ?? ''), true) ?: [];
    $rows = InqPasBody::finishRows((array)($res['pbody']['rows'] ?? []), false);
    if ($sel) $rows = array_values(array_filter($rows, function ($x, $i) use ($sel) { return in_array($i, $sel, true); }, ARRAY_FILTER_USE_BOTH));
    return [$res, $rows];
}
function pb_damage_text(array $x) {
    return implode(' | ', array_map(function ($d) { return trim('پرونده ' . ($d['case_code'] ?? '') . ' · وقوع ' . ($d['accident_date'] ?? '') . ' · اعلام ' . ($d['declare_date'] ?? '')); }, $x['damages'] ?? []));
}
function pb_export_xlsx(array $r, array $sel) {
    require_once __DIR__ . '/_xlsx_writer.php';
    [, $rows] = pb_rows_of($r, $sel);
    $out = [];
    foreach ($rows as $x) { $line = []; foreach (PB_COLS as [$k]) $line[] = (string)($x[$k] ?? ''); $line[] = count($x['damages'] ?? []); $line[] = pb_damage_text($x); $out[] = $line; }
    return xlsx_build(array_merge(array_map(function ($c) { return $c[1]; }, PB_COLS), ['تعداد خسارت', 'خسارت‌ها']), $out, 'بدنه بیمه مرکزی');
}
function pb_export_pdf(array $r, array $sel, $dest) {
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    [, $rows] = pb_rows_of($r, $sel);
    $fa = function ($v) { return strtr((string)$v, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); };
    $e = function ($v) use ($fa) { $v = trim((string)$v); return $v === '' ? '—' : htmlspecialchars($fa($v), ENT_QUOTES, 'UTF-8'); };
    $el = function ($v) { $v = trim((string)$v); return $v === '' ? '—' : htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('بیمه با ما'); $pdf->SetTitle('خروجیِ سایت — استعلام بدنه بیمه مرکزی');
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(true); $pdf->setFooterFont(['vazir', '', 7]); $pdf->setFooterMargin(6);
    $pdf->SetMargins(8, 8, 8); $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->setRTL(true); $pdf->SetFont('vazir', '', 8);
    $pdf->AddPage();
    $f = $rows[0] ?? [];
    $today = jalali_from_gregorian_ts(time()); $tk = sprintf('%04d%02d%02d', $today[0], $today[1], $today[2]);
    $active = $f && pb_date_key($f['end_date'] ?? '') >= $tk;
    $nd = array_sum(array_map(function ($x) { return count($x['damages'] ?? []); }, $rows));
    $h = '<table cellpadding="6" style="width:100%"><tr>
        <td width="60%" style="background-color:#134e4a;color:#ffffff"><span style="font-size:7.5pt;color:#99f6e4">خروجیِ سایت — بیمه با ما · سامانه‌ی پاسارگاد</span><br><b style="font-size:13pt">استعلامِ بدنه‌ی بیمه مرکزی</b><br>
            <span style="font-size:8pt">جستجو: ' . $e($r['query_text'] ?? '') . ' · تاریخِ استعلام: ' . $e(jdate_short($r['created_at'] ?? date('Y-m-d')) . ' ' . substr((string)($r['created_at'] ?? ''), 11, 5)) . ' · ' . $e(count($rows)) . ' بیمه‌نامه</span></td>
        <td width="20%" style="background-color:' . ($active ? '#0d9488' : '#64748b') . ';color:#ffffff;text-align:center"><b style="font-size:13pt">' . ($f ? ($active ? 'فعال' : 'منقضی') : '—') . '</b><br><span style="font-size:8pt">آخرین بیمه‌نامه تا ' . $e($f['end_date'] ?? '') . '</span></td>
        <td width="20%" style="background-color:' . ($nd ? '#e11d48' : '#059669') . ';color:#ffffff;text-align:center"><b style="font-size:13pt">' . ($nd ? $e($nd) . ' خسارت' : 'بدونِ خسارت') . '</b><br><span style="font-size:8pt">در همه‌ی بیمه‌نامه‌های بالا</span></td></tr></table>';
    $kv = function ($k, $v, $ltr = false) use ($e, $el) { return '<td style="background-color:#f8fafc;border:0.3px solid #e2e8f0"><span style="color:#64748b;font-size:7pt">' . $k . '</span><br><b style="font-size:9pt">' . ($ltr ? $el($v) : $e($v)) . '</b></td>'; };
    if ($f) $h .= '<table cellpadding="4" style="width:100%"><tr>' . $kv('بیمه‌گر', $f['company'] ?? '') . $kv('شماره چاپی', $f['print_no'] ?? '', true) . $kv('کد یکتا', $f['unique_code'] ?? '') . $kv('بیمه‌گذار', $f['insured'] ?? '')
        . $kv('شروع / پایان', ($f['start_date'] ?? '') . ' تا ' . ($f['end_date'] ?? '')) . $kv('سال تخفیف عدم خسارت', $f['no_loss_years'] ?? '') . '</tr><tr>'
        . $kv('پلاک', $f['plate_fa'] ?? '') . $kv('شاسی', $f['chassis'] ?? '', true) . $kv('موتور', $f['engine'] ?? '', true) . $kv('VIN', $f['vin'] ?? '', true) . $kv('خودرو', trim(($f['car_system'] ?? '') . ' ' . ($f['car_group'] ?? ''))) . $kv('کد یکتا بیمه‌نامه‌ی قبل', $f['prev_unique_code'] ?? '') . '</tr></table>';
    $th = function ($t, $w) { return '<th width="' . $w . '" style="background-color:#0f172a;color:#ffffff;font-size:7pt;text-align:center"><b>' . $t . '</b></th>'; };
    $h .= '<br><table cellpadding="3" style="width:100%"><thead><tr>' . $th('#', '3%') . $th('بیمه‌گر', '8%') . $th('کد یکتا', '8%') . $th('شماره چاپی', '13%') . $th('بیمه‌گذار', '13%') . $th('صدور', '6%')
        . $th('شروع', '6%') . $th('پایان', '6%') . $th('پلاک', '9%') . $th('شاسی / موتور', '14%') . $th('سال تخفیف', '5%') . $th('خسارت', '9%') . '</tr></thead><tbody>';
    foreach ($rows as $i => $x) {
        $bg = $i === 0 ? '#ecfeff' : ($i % 2 ? '#f8fafc' : '#ffffff');
        $td = function ($v, $w, $ltr = false) use ($bg) { return '<td width="' . $w . '" style="background-color:' . $bg . ';border-bottom:0.3px solid #e2e8f0;font-size:7pt;text-align:center"' . ($ltr ? ' dir="ltr"' : '') . '>' . $v . '</td>'; };
        $dn = count($x['damages'] ?? []);
        $h .= '<tr nobr="true">' . $td($e($i + 1), '3%') . $td('<b>' . $e($x['company'] ?? '') . '</b>', '8%') . $td($e($x['unique_code'] ?? ''), '8%') . $td($el($x['print_no'] ?? ''), '13%', true)
            . $td($e($x['insured'] ?? ''), '13%') . $td($e($x['issue_date'] ?? ''), '6%') . $td($e($x['start_date'] ?? ''), '6%') . $td('<b>' . $e($x['end_date'] ?? '') . '</b>', '6%')
            . $td($e($x['plate_fa'] ?? ''), '9%') . $td($el(($x['chassis'] ?? '') . ' / ' . ($x['engine'] ?? '')), '14%', true) . $td($e($x['no_loss_years'] ?? ''), '5%')
            . $td($dn ? '<span style="color:#be123c"><b>' . $e($dn) . ' پرونده</b></span>' : '<span style="color:#047857">ندارد</span>', '9%') . '</tr>';
        foreach (($x['damages'] ?? []) as $d)
            $h .= '<tr nobr="true"><td width="3%"></td><td colspan="11" width="97%" style="background-color:#fff1f2;font-size:6.8pt;color:#9f1239">خسارت: پرونده <b>' . $e($d['case_code'] ?? '') . '</b> · وقوع ' . $e($d['accident_date'] ?? '')
                . ' · اعلام ' . $e($d['declare_date'] ?? '') . ' · تشکیل ' . $e($d['file_date'] ?? '') . (!empty($d['complete_date']) ? ' · تکمیل ' . $e($d['complete_date']) : '') . '</td></tr>';
    }
    if (!$rows) $h .= '<tr><td colspan="12" style="text-align:center;color:#94a3b8">بیمه‌نامه‌ای پیدا نشد.</td></tr>';
    $h .= '</tbody></table><p style="font-size:6.5pt;color:#94a3b8">ترتیب: آخرین بیمه‌نامه (نزدیک‌ترین پایان به امروز) بالا · این گزارش از روی نتیجه‌ی «استعلام بدنه بیمه مرکزی» در سامانه‌ی پاسارگاد ساخته شده است.</p>';
    $pdf->writeHTML($h, true, false, true, false, '');
    $pdf->Output($dest, 'F');
    return is_file($dest);
}


// =====================================================================
//  استعلامِ گروهیِ بدنه و خسارت (اکسل)
// =====================================================================
// ستون‌های اکسل => ورودی‌ها. بدنه: ستونِ اول = مقدارِ جستجو (شاسی و موتور: دو ستون؛ پلاک: چهار ستون «ایران، سه‌رقمی، حرف، دورقمی» یا یک ستونِ کامل)
//   خسارت: شماره بیمه‌نامه، شاسی، موتور (با سرستون یا به همین ترتیب؛ هر کدام می‌تواند خالی باشد)
function pb_excel_rows($path, $kind, $type) {
    if (!function_exists('fin_read_spreadsheet')) require_once __DIR__ . '/finance_core.php';
    if (!function_exists('sh_parse_plate')) require_once __DIR__ . '/_sanhab.php';
    $t = array_values((array)fin_read_spreadsheet($path));
    if (!$t) return ['ok' => false, 'error' => 'فایل خوانده نشد (xlsx یا csv).'];
    $first = array_values(array_map(function ($x) { return pb_fa_norm((string)$x); }, (array)($t[0] ?? [])));
    $isHead = (bool)preg_match('/ایران|پلاک|حرف|شماره|بیمه|یکتا|چاپی|vin|شاسی|موتور|مقدار/u', implode(' ', $first));
    $cols = [0 => 'policy_no', 1 => 'chassis', 2 => 'engine'];
    if ($kind === 'pdamage' && $isHead) {
        $cols = [];
        foreach ($first as $i => $h) {
            if (preg_match('/بیمهنامه|بیمه‌نامه|policy/u', $h)) $cols[$i] = 'policy_no';
            elseif (preg_match('/شاسی|vin|chassis/u', $h)) $cols[$i] = 'chassis';
            elseif (preg_match('/موتور|engine/u', $h)) $cols[$i] = 'engine';
        }
        if (!$cols) $cols = [0 => 'policy_no', 1 => 'chassis', 2 => 'engine'];
    }
    $out = [];
    foreach (array_slice($t, $isHead ? 1 : 0) as $row) {
        $row = array_values(array_map(function ($x) { return trim((string)$x); }, (array)$row));
        if (!array_filter($row, 'strlen')) continue;
        if ($kind === 'pdamage') { $r = []; foreach ($cols as $i => $f) $r[$f] = $row[$i] ?? ''; }
        elseif ($type === 'plate') {
            if (count($row) >= 4 && ($row[1] ?? '') !== '' && ($row[2] ?? '') !== '') $r = ['p1' => $row[0], 'p2' => $row[1], 'letter' => $row[2], 'p4' => $row[3]];
            else { $pp = sh_parse_plate($row[0] ?? ''); if (!$pp) { $out[] = ['_bad' => $row[0] ?? '']; continue; } $r = ['p1' => $pp['plk1'], 'p2' => $pp['plk2'], 'letter' => $pp['plk3'], 'p4' => $pp['plk4']]; }
        } elseif ($type === 'chassis_engine') $r = ['chassis' => $row[0] ?? '', 'engine' => $row[1] ?? ''];
        else $r = [['unique' => 'unique', 'print' => 'print', 'vin' => 'chassis', 'chassis' => 'chassis', 'engine' => 'engine'][$type] ?? 'chassis' => $row[0] ?? ''];
        $out[] = $r;
        if (count($out) >= 1000) break;
    }
    return $out ? ['ok' => true, 'rows' => $out] : ['ok' => false, 'error' => 'هیچ ردیفی پیدا نشد.'];
}
// اعتبارسنجیِ یک ردیف => همان خروجیِ pb_validate / pb_damage_validate
function pb_batch_validate($kind, array $opt, array $r) {
    if (isset($r['_bad'])) return ['ok' => false, 'error' => 'پلاکِ «' . $r['_bad'] . '» شناخته نشد'];
    if ($kind === 'pdamage') return pb_damage_validate($r + ['ins' => $opt['ins'] ?? 'both', 'out' => $opt['out'] ?? 'pdf']);
    return pb_validate(['type' => $opt['type'] ?? ''] + $r);
}
function pb_batch_create($pdo, $kind, array $opt, $title, array $rows, $fileName = '') {
    inq_ensure($pdo);
    $actor = inq_actor_from_session();
    $pdo->prepare("INSERT INTO inq_batches (title, kind, file_name, total, status, user_id, user_name, created_at) VALUES (?, ?, ?, 0, 'RUNNING', ?, ?, NOW())")
        ->execute([mb_substr($title ?: ($kind === 'pdamage' ? 'استعلامِ گروهیِ خسارت' : 'استعلامِ گروهیِ بدنه'), 0, 190), $kind, mb_substr($fileName, 0, 255), $actor[1] ?: null, mb_substr($actor[2], 0, 190)]);
    $bid = intval($pdo->lastInsertId());
    $ok = 0; $bad = [];
    $ins = $pdo->prepare("INSERT INTO inq_requests (kind, national_id, status, result_json, source, batch_id, row_no, user_type, user_id, user_name, created_at, query_text)
                          VALUES (?, '', 'QUEUED', ?, 'group', ?, ?, ?, ?, ?, NOW(), ?)");
    foreach (array_values($rows) as $i => $r) {
        $v = pb_batch_validate($kind, $opt, (array)$r);
        if (!$v['ok']) { $bad[] = ['row' => $i + 1, 'error' => $v['error']]; continue; }
        $q = $kind === 'pdamage' ? $v['params'] + ['out' => $v['out']] : ['type' => $v['type'], 'params' => $v['params'], 'want_print' => !empty($opt['want_print'])];
        $ins->execute([$kind, json_encode(['q' => $q], JSON_UNESCAPED_UNICODE), $bid, $i + 1, $actor[0], $actor[1] ?: null, mb_substr($actor[2], 0, 190), $v['label']]);
        $ok++;
    }
    $pdo->prepare("UPDATE inq_batches SET total = ? WHERE id = ?")->execute([$ok, $bid]);
    if (!$ok) $pdo->prepare("UPDATE inq_batches SET status = 'DONE', finished_at = NOW() WHERE id = ?")->execute([$bid]);
    return ['ok' => true, 'batch_id' => $bid, 'queued' => $ok, 'invalid' => $bad];
}
// اجرای یک ردیفِ صف (از inq_batch_tick): ['ok'] | ['need_captcha'] | ['busy']
function pb_batch_run($pdo, $id) {
    $up = $pdo->prepare("UPDATE inq_requests SET status = 'RUNNING', started_at = NOW() WHERE id = ? AND status = 'QUEUED'");
    $up->execute([intval($id)]);
    if (!$up->rowCount()) return ['ok' => true];
    $row = inq_get($pdo, $id);
    $q = (json_decode((string)$row['result_json'], true) ?: [])['q'] ?? [];
    $ctx = ['source' => 'group', 'actor' => [$row['user_type'], $row['user_id'], (string)$row['user_name']], 'row_id' => intval($id), 'group' => true];
    $r = $row['kind'] === 'pdamage' ? pb_damage($pdo, $q, $ctx)
                                    : pb_search($pdo, ['type' => $q['type'] ?? ''] + (array)($q['params'] ?? []) + ['want_print' => !empty($q['want_print'])], $ctx);
    $back = function ($notice) use ($pdo, $id) { $pdo->prepare("UPDATE inq_requests SET status = 'QUEUED', started_at = NULL, notice = ? WHERE id = ? AND status = 'RUNNING'")->execute([$notice, intval($id)]); };
    if (!empty($r['need_login'])) { $back('منتظرِ ورود به سامانه'); return ['need_captcha' => true]; }
    if (empty($r['ok']) && empty($r['id']) && mb_strpos((string)($r['error'] ?? ''), 'مشغول') !== false) { $back('سامانه مشغول بود'); return ['busy' => true]; }
    // خطا پیش از رسیدن به سامانه (خاموش بودن، curl، ...) => ردیف ناموفق
    if (empty($r['ok']) && empty($r['id'])) $pdo->prepare("UPDATE inq_requests SET status = 'FAIL', notice = ?, finished_at = NOW() WHERE id = ?")->execute([mb_substr((string)($r['error'] ?? 'خطا'), 0, 600), intval($id)]);
    return ['ok' => true];
}
// نمونه‌ی اکسل
function pb_batch_sample($kind, $type) {
    require_once __DIR__ . '/_xlsx_writer.php';
    if ($kind === 'pdamage') return xlsx_build(['شماره بیمه‌نامه', 'شماره شاسی', 'شماره موتور'], [['1405/4210/31/123456', 'NAAP41FE0LJ123456', '139A0123456'], ['', 'NAAN01CA9JK654321', '']], 'استعلام خسارت');
    if ($type === 'plate') return xlsx_build(['ایران', 'سه‌رقمی', 'حرف', 'دورقمی'], [['21', '123', 'ب', '11'], ['78', '994', 'ط', '59']], 'پلاک');
    if ($type === 'chassis_engine') return xlsx_build(['شماره شاسی', 'شماره موتور'], [['NAAP41FE0LJ123456', '139A0123456']], 'شاسی و موتور');
    return xlsx_build([PB_TYPES[$type] ?? 'مقدار'], [[$type === 'unique' ? '1234567890' : ($type === 'print' ? '1405/4210/31/123456' : ($type === 'engine' ? '139A0123456' : 'NAAP41FE0LJ123456'))]], 'استعلام بدنه');
}
// خروجیِ اکسلِ یک گروه: بدنه => آخرین بیمه‌نامه‌ی هر ردیف + شیتِ همه‌ی بیمه‌نامه‌ها؛ خسارت => نتیجه و ریزِ خسارت‌ها
function pb_batch_export($pdo, $bid, array $rows, $kind) {
    require_once __DIR__ . '/_xlsx_writer.php';
    $st = function ($r) { return ['OK' => 'موفق', 'WARN' => $r['kind'] === 'pdamage' ? 'خسارت دارد' : 'یافت نشد', 'FAIL' => 'ناموفق', 'QUEUED' => 'در صف', 'RUNNING' => 'در حالِ اجرا'][$r['status']] ?? $r['status']; };
    if ($kind === 'pdamage') {
        $a = []; $b = [];
        foreach ($rows as $r) {
            $res = json_decode((string)$r['result_json'], true) ?: []; $d = $res['pdamage'] ?? []; $q = $res['q'] ?? [];
            $a[] = [$r['row_no'], $q['policy_no'] ?? '', $q['chassis'] ?? '', $q['engine'] ?? '', $st($r), isset($d['found']) ? ($d['found'] ? 'دارد' : 'ندارد') : '', count($d['rows'] ?? []), $d['message'] ?? $r['notice'], !empty($d['pdf']) ? 'PDFِ سامانه' : (!empty($d['shot']) ? 'تصویرِ صفحه' : '')];
            foreach (($d['rows'] ?? []) as $x) { $line = [$r['row_no'], $r['query_text']]; foreach (PB_DMG_LABELS as $k) $line[] = (string)($x[$k] ?? ''); $b[] = $line; }
        }
        return xlsx_build_book([['name' => 'نتیجه', 'headers' => ['ردیف', 'شماره بیمه‌نامه', 'شاسی', 'موتور', 'وضعیت', 'خسارت', 'تعدادِ پرونده', 'پیامِ سامانه', 'فایل'], 'rows' => $a],
                                ['name' => 'پرونده‌های خسارت', 'headers' => array_merge(['ردیف', 'جستجو'], array_keys(PB_DMG_LABELS)), 'rows' => $b]]);
    }
    $a = []; $b = [];
    foreach ($rows as $r) {
        $res = json_decode((string)$r['result_json'], true) ?: []; $pb = $res['pbody'] ?? []; $list = $pb['rows'] ?? []; $f = $list[0] ?? [];
        $line = [$r['row_no'], $r['query_text'], $st($r), count($list)];
        foreach (array_slice(PB_COLS, 1) as [$k]) $line[] = (string)($f[$k] ?? '');
        $line[] = array_sum(array_map(function ($x) { return count($x['damages'] ?? []); }, $list));
        $line[] = $r['notice'];
        $a[] = $line;
        foreach ($list as $i => $x) { $l = [$r['row_no'], $r['query_text'], $i + 1]; foreach (array_slice(PB_COLS, 1) as [$k]) $l[] = (string)($x[$k] ?? ''); $l[] = count($x['damages'] ?? []); $l[] = pb_damage_text($x); $b[] = $l; }
    }
    $h = array_map(function ($c) { return $c[1]; }, array_slice(PB_COLS, 1));
    return xlsx_build_book([['name' => 'آخرین بیمه‌نامه', 'headers' => array_merge(['ردیف', 'جستجو', 'وضعیت', 'تعدادِ بیمه‌نامه'], $h, ['خسارت‌ها', 'نتیجه']), 'rows' => $a],
                            ['name' => 'همه‌ی بیمه‌نامه‌ها', 'headers' => array_merge(['ردیف', 'جستجو', '#'], $h, ['تعداد خسارت', 'خسارت‌ها']), 'rows' => $b]]);
}
// ZIPِ همه‌ی فایل‌های یک گروه (خسارت: PDF یا تصویرِ صفحه؛ بدنه: چاپِ سامانه)
function pb_batch_zip(array $rows, $dest) {
    if (!class_exists('ZipArchive')) return 0;
    $z = new ZipArchive();
    if ($z->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return 0;
    $n = 0;
    foreach ($rows as $r) {
        $dir = pb_row_dir($r);
        if ($dir === '') continue;
        $res = json_decode((string)$r['result_json'], true) ?: [];
        $f = $r['kind'] === 'pdamage' ? ($res['pdamage']['pdf'] ?? ($res['pdamage']['shot'] ?? '')) : ($res['pbody']['print'] ?? '');
        if (!$f || !preg_match('/^[a-z]+\.(pdf|png)$/', $f) || !is_file($dir . '/' . $f)) continue;
        $name = sprintf('%03d', intval($r['row_no'])) . ' - ' . trim(preg_replace('/[\\\\\/:*?"<>|]+/u', '-', (string)$r['query_text'])) . '.' . pathinfo($f, PATHINFO_EXTENSION);
        $z->addFile($dir . '/' . $f, mb_substr($name, 0, 150));
        $n++;
    }
    $z->close();
    return $n;
}
