<?php
// فایل: api/_psg_lov.php
// ابزارهای مشترکِ فرم‌های پاسارگاد + «فهرستِ انتخاب» (LOV — همان دکمه‌ی مربعِ فلش‌دارِ کنارِ تیپ، تعهدات موافقت‌نامه، نماینده/کارگزار…):
//   - psgc_fields()     همه‌ی خانه‌های فرم با چند مقدار (چک‌باکس‌های هم‌نام مثلِ «طرح»‌ها: tarhListCheckBox)
//   - psgc_encode()     بدنه‌ی POST (multipart یا urlencoded) با چند مقدار
//   - psgc_lovs()/psgc_plans()  خانه‌های فهرست‌دار و «طرح»‌های یک فرم برای نمایش
//   - PsgLov            باز کردنِ صفحه‌ی فهرست، جستجو با خانه‌های خودِ آن صفحه، صفحه‌ی بعد، و نتیجه‌ها (هر ردیف: window.opener.fn(args))
require_once __DIR__ . '/_inquiry.php';

function psgc_dec($s) { return html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8'); }
function psgc_txt($h) { return trim(preg_replace('/\s+/u', ' ', psgc_dec(strip_tags(preg_replace('#<script\b.*?</script>#is', ' ', (string)$h))))); }
function psgc_form($html, $formId) {
    $q = preg_quote($formId, '/');
    if (preg_match('/<form\b[^>]*\b(?:id|name)=["\']' . $q . '["\'][^>]*>/is', $html, $m, PREG_OFFSET_CAPTURE)) {
        $st = $m[0][1]; $en = stripos($html, '</form>', $st);
        return ['tag' => $m[0][0], 'html' => $en !== false ? substr($html, $st, $en - $st + 7) : substr($html, $st)];
    }
    return ['tag' => '', 'html' => $html];
}
// خانه‌ها با چند مقدار: [name => string|string[]]
function psgc_fields($html, $formId = 'editform') {
    $form = psgc_form($html, $formId)['html'];
    $f = [];
    if (!preg_match_all('/<(input|select|textarea)\b([^>]*)>/is', $form, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $f;
    foreach ($mm as $m) {
        $tag = strtolower($m[1][0]); $a = $m[2][0]; $end = $m[0][1] + strlen($m[0][0]);
        if (preg_match('/\bdisabled\b/i', $a) || !preg_match('/\bname=["\']([^"\']+)["\']/i', $a, $nm)) continue;
        $n = psgc_dec($nm[1]);
        if ($tag === 'input') {
            $t = preg_match('/\btype=["\']([^"\']+)["\']/i', $a, $tm) ? strtolower($tm[1]) : 'text';
            if (in_array($t, ['submit', 'button', 'file', 'image', 'reset'], true)) continue;
            if (in_array($t, ['checkbox', 'radio'], true) && !preg_match('/\bchecked\b/i', $a)) continue;
            $v = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $a, $vm) ? psgc_dec($vm[1]) : ($t === 'checkbox' ? 'true' : '');
            if ($t === 'checkbox' && isset($f[$n])) { $f[$n] = array_merge((array)$f[$n], [$v]); continue; }
            $f[$n] = $v;
        } elseif ($tag === 'select') {
            $close = stripos($form, '</select>', $end); $inner = $close !== false ? substr($form, $end, $close - $end) : '';
            $sel = null; $first = null;
            if (preg_match_all('/<option\b([^>]*)>/is', $inner, $om)) foreach ($om[1] as $oa) { $v = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $oa, $vm) ? psgc_dec($vm[1]) : ''; if ($first === null) $first = $v; if (preg_match('/\bselected\b/i', $oa)) { $sel = $v; break; } }
            if ($sel !== null || $first !== null) $f[$n] = $sel ?? $first;
        } else { $close = stripos($form, '</textarea>', $end); $f[$n] = psgc_dec($close !== false ? substr($form, $end, $close - $end) : ''); }
    }
    return $f;
}
// بدنه‌ی POST با چند مقدار => [body, contentTypeHeader]
function psgc_encode(array $f, $multipart) {
    if (!$multipart) {
        $q = []; foreach ($f as $k => $v) foreach ((array)$v as $x) $q[] = rawurlencode($k) . '=' . rawurlencode((string)$x);
        return [implode('&', $q), 'Content-Type: application/x-www-form-urlencoded'];
    }
    $b = '----PsgFormBoundary' . bin2hex(random_bytes(8)); $out = '';
    foreach ($f as $k => $v) foreach ((array)$v as $x) $out .= "--$b\r\nContent-Disposition: form-data; name=\"" . str_replace('"', '%22', $k) . "\"\r\n\r\n" . $x . "\r\n";
    return [$out . "--$b--\r\n", 'Content-Type: multipart/form-data; boundary=' . $b];
}
// خانه‌های فهرست‌دارِ یک فرم: [{name, label, lov, value, desc}]
function psgc_lovs($html, $formId = 'editform') {
    $form = psgc_form($html, $formId)['html'];
    $vals = psgc_fields($html, $formId);
    $out = []; $seen = [];
    if (preg_match_all("#inputname='([^']+)';javascript:lov_href='([^']+)'#", $form, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) foreach ($mm as $m) {
        $n = psgc_dec($m[1][0]); if (isset($seen[$n])) continue; $seen[$n] = 1;
        // برچسب: آخرین td.label پیش از این خانه
        $before = substr($form, max(0, $m[0][1] - 4000), min(4000, $m[0][1]));
        $label = preg_match_all('#<td class="label">(.*?)</td>#is', $before, $lm) ? psgc_txt(end($lm[1])) : $n;
        $out[] = ['name' => $n, 'label' => $label ?: $n, 'lov' => psgc_dec($m[2][0]), 'value' => (string)(is_array($vals[$n] ?? '') ? '' : ($vals[$n] ?? '')), 'desc' => (string)(is_array($vals[$n . '.desc'] ?? '') ? '' : ($vals[$n . '.desc'] ?? ''))];
    }
    return $out;
}
// «طرح»‌ها: [{value, label, checked}]
function psgc_plans($html, $name = 'tarhListCheckBox') {
    $o = [];
    if (preg_match_all('#<label>\s*<input\b([^>]*\bname=["\']' . preg_quote($name, '#') . '["\'][^>]*)>(.*?)</label>#is', $html, $mm, PREG_SET_ORDER)) foreach ($mm as $m) {
        if (!preg_match('/\bvalue=["\']([^"\']*)["\']/i', $m[1], $v)) continue;
        $o[] = ['value' => psgc_dec($v[1]), 'label' => trim(str_replace("\u{00A0}", ' ', psgc_txt($m[2]))), 'checked' => (bool)preg_match('/\bchecked\b/i', $m[1])];
    }
    return $o;
}

class PsgLov extends InqPasargad
{
    public function isOut($h) { return $this->loggedOut($h); }
    public function get($path) { return $this->http('GET', $this->core . $path); }
    public function args($s) { return $this->parseJsArgs($s); }
    // ساختارِ جستجوی صفحه‌ی فهرست
    public function schema($html) {
        $fm = psgc_form($html, 'exampleform');
        $form = $fm['html']; $vals = psgc_fields($html, 'exampleform');
        $fields = []; $seen = [];
        if (preg_match_all('#<td class="label">(.*?)</td>\s*<td class="content">(.*?)</td>#is', $form, $mm, PREG_SET_ORDER)) foreach ($mm as $m) {
            $label = psgc_txt($m[1]); $c = $m[2];
            if ($label === '' || stripos($c, 'type="file"') !== false) continue;
            if (preg_match('/<select\b[^>]*\bname=["\']([^"\']+)["\'][^>]*>(.*?)<\/select>/is', $c, $s)) {
                $n = psgc_dec($s[1]); if (isset($seen[$n])) continue; $seen[$n] = 1;
                $opts = []; if (preg_match_all('/<option\b[^>]*\bvalue=["\']([^"\']*)["\'][^>]*>([^<]*)/is', $s[2], $om, PREG_SET_ORDER)) foreach ($om as $o) $opts[] = [psgc_dec($o[1]), trim(psgc_dec($o[2]))];
                $fields[] = ['name' => $n, 'label' => $label, 'type' => 'select', 'options' => $opts, 'value' => (string)($vals[$n] ?? '')];
            } elseif (preg_match('/<input\b[^>]*\bname=["\']([^"\']+)["\'][^>]*>/is', $c, $i) && stripos($i[0], 'type="hidden"') === false && strpos($c, 'lov_href') === false) {
                $n = psgc_dec($i[1]); if (isset($seen[$n]) || preg_match('/_(y|m|d)$/', $n)) continue; $seen[$n] = 1;
                $fields[] = ['name' => $n, 'label' => $label, 'type' => preg_match('/tarikh/i', $n) ? 'date' : 'text', 'value' => (string)($vals[$n] ?? '')];
            }
        }
        return ['fields' => $fields, 'action' => preg_match('/\baction=["\']([^"\']+)["\']/i', $fm['tag'], $a) ? psgc_dec($a[1]) : '',
                'multipart' => stripos($fm['tag'], 'multipart') !== false];
    }
    // نتیجه‌ها: هر لینکِ window.opener.fn(args) یک ردیف؛ متنِ ردیف = خانه‌های پیش از آن لینک
    public function results($html) {
        $tb = preg_match('#<table[^>]*id="exampleform:dataTable"[^>]*>(.*)</table>#is', $html, $m) ? $m[1] : $html;
        $rows = []; $last = 0;
        if (preg_match_all('/window\.opener\.(\w+)\s*\(((?:\'[^\']*\'|"[^"]*"|[^)\'"])*)\)/', $tb, $lm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) foreach ($lm as $x) {
            $args = $this->parseJsArgs($x[2][0]);
            $seg = substr($tb, $last, $x[0][1] - $last); $last = $x[0][1] + strlen($x[0][0]);
            if (!array_filter($args, 'strlen')) continue;   // select('','') = «خالی کردن»
            $cells = [];
            if (preg_match_all('#<td\b[^>]*>(.*?)(?=<td\b|</tr>|$)#is', $seg, $cm)) foreach ($cm[1] as $c) { $t = psgc_txt(preg_replace('#<br\s*/?>#i', ' · ', $c)); if ($t !== '' && mb_strlen($t) < 300) $cells[] = $t; }
            $rows[] = ['fn' => $x[1][0], 'args' => array_map('strval', $args), 'cells' => array_slice($cells, -6), 'title' => (string)($args[1] ?? $args[0])];
        }
        $pg = ['next' => strpos($html, 'exampleform:dataTable:dataTablenext') !== false, 'prev' => strpos($html, 'exampleform:dataTable:dataTableprevious') !== false,
               'cur' => preg_match('/class="currentPage"[^>]*>\s*<a[^>]*>\s*(\d+)/', $html, $cp) ? (int)$cp[1] : 1];
        return ['rows' => $rows, 'pager' => $pg];
    }
    public function submitLov($html, array $vals, $clicked) {
        $s = $this->schema($html);
        $f = psgc_fields($html, 'exampleform');
        foreach ($vals as $k => $v) if (is_string($k)) $f[$k] = (string)$v;
        $vs = $f['javax.faces.ViewState'] ?? null;
        unset($f['exampleform_SUBMIT'], $f['javax.faces.ViewState'], $f['exampleform:_idcl']);
        $f['exampleform_SUBMIT'] = '1'; if ($vs !== null) $f['javax.faces.ViewState'] = $vs; $f['exampleform:_idcl'] = $clicked;
        if ($clicked === 'exampleform:dataTable:dataTablenext' || $clicked === 'exampleform:dataTable:dataTableprevious') $f['exampleform:dataTable'] = $clicked === 'exampleform:dataTable:dataTablenext' ? 'next' : 'previous';
        [$body, $ct] = psgc_encode($f, $s['multipart']);
        $url = $s['action'] !== '' ? $this->core . $s['action'] : $this->core . '/cins/';
        return $this->http('POST', $url, $body, $url, true, [$ct]);   // (بدنه‌ی آماده؛ multipart=true یعنی همان رشته فرستاده شود)
    }
}

function psgl_ok_path($p) { return (bool)preg_match('#^/cins/[A-Za-z0-9_]+\.lov\.html(\?[A-Za-z0-9_=&%.\-]*)?$#', (string)$p); }
function psgl_file($uid, $path) { return inq_dir('psg_lov') . '/u' . intval($uid) . '_' . md5((string)$path) . '.html'; }
// باز کردن (و اگر q دادند، جستجو) => {fields, rows, pager}
function psgl_run($pdo, $uid, $path, $op, array $vals = []) {
    if (!psgl_ok_path($path)) return ['ok' => false, 'error' => 'فهرستِ نامعتبر.'];
    $lk = inq_lock(40);
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است؛ چند ثانیه‌ی دیگر.'];
    try {
        @set_time_limit(90);
        $c = new PsgLov();
        $f = psgl_file($uid, $path);
        $html = ($op !== 'open' && is_file($f) && filemtime($f) > time() - 1800) ? (string)file_get_contents($f) : '';
        if ($html === '') {
            $r = $c->get($path);
            if ($c->isOut($r['body'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید.']; }
            if (strpos($r['body'], 'exampleform') === false) return ['ok' => false, 'error' => 'صفحه‌ی فهرست باز نشد' . ($r['error'] ? ' (' . $r['error'] . ')' : '') . '.'];
            $html = $r['body'];
        }
        if ($op === 'search' || $op === 'next' || $op === 'prev') {
            $btn = $op === 'next' ? 'exampleform:dataTable:dataTablenext' : ($op === 'prev' ? 'exampleform:dataTable:dataTableprevious' : 'exampleform:search');
            $r = $c->submitLov($html, $op === 'search' ? $vals : [], $btn);
            if ($c->isOut($r['body'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'نشستِ سامانه بسته شد.']; }
            if (strpos((string)$r['body'], 'exampleform') !== false) $html = $r['body'];
        }
        @file_put_contents($f, $html);
        foreach (glob(inq_dir('psg_lov') . '/*.html') ?: [] as $old) if (filemtime($old) < time() - 7200) @unlink($old);
        if (function_exists('inq_touch_activity')) inq_touch_activity();
        return ['ok' => true] + $c->schema($html) + $c->results($html);
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
}
// انتخابِ یک ردیف ← خانه‌های فرمِ اصلی (همان کاری که تابعِ window.opener.* در خودِ سامانه می‌کند)
function psgl_apply($fn, array $a, $target) {
    $o = [];
    if (preg_match('/^selectTip/i', $fn)) {
        $o['editform:nerkhTip'] = $a[0] ?? ''; $o['editform:nerkhTip.desc'] = $a[1] ?? ''; $o['tip_inputHidden'] = $a[0] ?? '';
        if (($a[2] ?? '') !== '') { $o['moshakhasatKhodroSales'] = $a[2]; $o['moshakhasatKhodroBadaneh'] = $a[2]; }
        if (($a[3] ?? '') !== '') { $o['noeVasilehNaghleyeh'] = $a[3]; $o['noeVasilehNaghleyeh_inputHidden'] = $a[3]; }
        if (isset($a[4]) && $a[4] !== 'true') { $o['otaghBar'] = ''; $o['otaghBar_inputHidden'] = ''; }
    } elseif (strcasecmp($fn, 'selectOlguyeMaali') === 0) {
        $o[$target] = $a[0] ?? ''; $o[$target . '.desc'] = $a[1] ?? '';
        if (($a[2] ?? '') !== '') $o['olguyeMaali'] = $a[2];
    } else { $o[$target] = $a[0] ?? ''; $o[$target . '.desc'] = $a[1] ?? ''; }
    return $o;
}

// نشستِ جدای پروکسیِ آزمایشی (psgx ← «نشستِ جدا») هم زنده بماند: یک درخواستِ سبک با همان کوکی
class PsgPing extends InqPasargad
{
    public function __construct($jar) { parent::__construct(); $this->cookieFile = $jar; }
}
function psgl_ping_proxy() {
    $jar = inq_dir('psgx') . '/session.cookies';
    if (!is_file($jar) || filesize($jar) < 20 || filemtime($jar) < time() - 3 * 86400) return null;
    try { return (new PsgPing($jar))->status(); } catch (Throwable $e) { return null; }
}
