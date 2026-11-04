<?php
// فایل: api/_psg_premium.php
// «استعلامِ حق بیمه‌ی بدنه‌ی پاسارگاد» با همان نشستِ سرور: فرمِ «استعلام حق بیمه» (bimehBadanehEstelam) از خودِ سامانه خوانده می‌شود
// (برچسب‌ها، گزینه‌ها، پوشش‌ها و مقدارهای پیش‌فرض از همان صفحه؛ شناسه‌های پویای JSF مهم نیستند)، کاربر پر می‌کند،
// «استعلام حق بیمه» زده و جدولِ «جزئیات حق بیمه» برمی‌گردد؛ «چاپ استعلام حق بیمه» فایلِ PDFِ خودِ سامانه را می‌گیرد.
// فقط استعلام است؛ هیچ پیش‌نویس/بیمه‌نامه‌ای ساخته نمی‌شود.
require_once __DIR__ . '/_inquiry.php';

const PSGP_EDIT = '/cins/bimehBadanehEstelam.edit.html';
const PSGP_FORM = '/cins/bimehbadanehestelam/bimehBadanehEstelam.simpleform.html';

function psgp_dir() { return inq_dir('psg_prem'); }
function psgp_text($h) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<script\b.*?</script>#is', ' ', (string)$h)), ENT_QUOTES, 'UTF-8'))); }

class PsgPremium extends InqPasargad
{
    public function notices($html) { return $this->parseNotices($html); }
    public function isOut($html) { return $this->loggedOut($html); }
    public function open() {
        $r = $this->http('GET', $this->core . PSGP_EDIT);
        if ($r['body'] === '' && $r['error'] !== '') return ['ok' => false, 'error' => 'سامانه‌ی پاسارگاد در دسترس نیست (' . $r['error'] . ').'];
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        if (strpos($r['body'], 'editform') === false) return ['ok' => false, 'error' => 'صفحه‌ی «استعلام حق بیمه‌ی بدنه» باز نشد (شاید دسترسیِ این کاربر بسته است).'];
        return ['ok' => true, 'html' => $r['body']];
    }
    // همه‌ی خانه‌ها با پشتیبانیِ چند مقدار (چند چک‌باکسِ هم‌نام مثلِ tarhListCheckBox)
    public function multiFields($html) {
        $form = $this->sliceFormHtml($html, 'editform');
        $f = [];
        if (!preg_match_all('/<(input|select|textarea)\b([^>]*)>/is', $form, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $f;
        foreach ($mm as $m) {
            $tag = strtolower($m[1][0]); $a = $m[2][0]; $end = $m[0][1] + strlen($m[0][0]);
            if (preg_match('/\bdisabled\b/i', $a) || !preg_match('/\bname=["\']([^"\']+)["\']/i', $a, $nm)) continue;
            $n = $this->decodeHtml($nm[1]);
            if ($tag === 'input') {
                $t = preg_match('/\btype=["\']([^"\']+)["\']/i', $a, $tm) ? strtolower($tm[1]) : 'text';
                if (in_array($t, ['submit', 'button', 'file', 'image', 'reset'], true)) continue;
                if (in_array($t, ['checkbox', 'radio'], true) && !preg_match('/\bchecked\b/i', $a)) continue;
                $v = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $a, $vm) ? $this->decodeHtml($vm[1]) : ($t === 'checkbox' ? 'true' : '');
                if ($t === 'checkbox' && isset($f[$n])) { $f[$n] = array_merge((array)$f[$n], [$v]); continue; }
                $f[$n] = $v;
            } elseif ($tag === 'select') {
                $close = stripos($form, '</select>', $end); $inner = $close !== false ? substr($form, $end, $close - $end) : '';
                $sel = null; $first = null;
                if (preg_match_all('/<option\b([^>]*)>/is', $inner, $om)) foreach ($om[1] as $oa) { $v = preg_match('/\bvalue=["\']([^"\']*)["\']/i', $oa, $vm) ? $this->decodeHtml($vm[1]) : ''; if ($first === null) $first = $v; if (preg_match('/\bselected\b/i', $oa)) { $sel = $v; break; } }
                if ($sel !== null || $first !== null) $f[$n] = $sel ?? $first;
            } else { $close = stripos($form, '</textarea>', $end); $f[$n] = $this->decodeHtml($close !== false ? substr($form, $end, $close - $end) : ''); }
        }
        return $f;
    }
    // ساختارِ فرم برای نمایش: [{name, label, type, options, value, row?}] — از ردیف‌های «برچسب | محتوا»
    public function schema($html) {
        $form = $this->sliceFormHtml($html, 'editform');
        $vals = $this->multiFields($html);
        $out = []; $seen = [];
        if (preg_match_all('#<td class="label">(.*?)</td>\s*<td class="content">(.*?)</td>#is', $form, $mm, PREG_SET_ORDER)) foreach ($mm as $m) {
            $label = trim(preg_replace('/\s+/u', ' ', psgp_text($m[1])));
            $c = $m[2];
            if (preg_match('/<select\b[^>]*\bname=["\']([^"\']+)["\'][^>]*>(.*?)<\/select>/is', $c, $s)) {
                $n = $this->decodeHtml($s[1]); if (isset($seen[$n])) continue; $seen[$n] = 1;
                $opts = []; if (preg_match_all('/<option\b[^>]*\bvalue=["\']([^"\']*)["\'][^>]*>([^<]*)/is', $s[2], $om, PREG_SET_ORDER)) foreach ($om as $o) $opts[] = [$this->decodeHtml($o[1]), trim($this->decodeHtml($o[2]))];
                $out[] = ['name' => $n, 'label' => $label, 'type' => 'select', 'options' => $opts, 'value' => (string)($vals[$n] ?? '')];
            } elseif (preg_match('/name=["\']([^"\']+)\.desc["\']/i', $c, $l) && strpos($c, 'lov_href') !== false) {
                $n = $this->decodeHtml($l[1]); if (isset($seen[$n])) continue; $seen[$n] = 1;
                $lov = preg_match("/lov_href='([^']+)'/", $c, $lh) ? $lh[1] : '';
                $out[] = ['name' => $n, 'label' => $label, 'type' => 'lov', 'lov' => $lov, 'value' => (string)($vals[$n] ?? ''), 'desc' => (string)($vals[$n . '.desc'] ?? '')];
            } elseif (preg_match_all('/<input\b[^>]*\btype=["\']text["\'][^>]*\bname=["\']([^"\']+)["\'][^>]*>|<input\b[^>]*\bname=["\']([^"\']+)["\'][^>]*\btype=["\']text["\'][^>]*>/is', $c, $im, PREG_SET_ORDER)) {
                foreach ($im as $x) {
                    $n = $this->decodeHtml($x[1] !== '' ? $x[1] : $x[2]);
                    if (isset($seen[$n]) || preg_match('/_(y|m|d)$/', $n)) continue; $seen[$n] = 1;
                    $base = preg_replace('/_(y|m|d)$/', '', $n);
                    $isDate = isset($vals[$n . '_y']) || preg_match('/tarikh/i', $n);
                    $out[] = ['name' => $n, 'label' => $label, 'type' => $isDate ? 'date' : 'text', 'value' => (string)($vals[$n] ?? '')];
                }
                // تاریخ‌ها: خانه‌ی پنهانِ اصلی + _y/_m/_d
                if (preg_match('/name=["\']([^"\']+)_y["\']/i', $c, $dy) && !isset($seen[$dy[1]])) { $n = $this->decodeHtml($dy[1]); $seen[$n] = 1; $out[] = ['name' => $n, 'label' => $label, 'type' => 'date', 'value' => (string)($vals[$n] ?? '')]; }
            }
        }
        // پوشش‌ها: هر ردیفِ جدول دو پوشش دارد (چک‌باکس | نام | تخفیف/سطح)؛ خانه‌به‌خانه خوانده می‌شود
        $cov = [];
        if (preg_match_all('#<tr\b[^>]*>((?:(?!</tr>).)*?:checkBoxes(?:(?!</tr>).)*)</tr>#is', $form, $cm)) foreach ($cm[1] as $row) {
            if (!preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $row, $tds)) continue;
            $cur = null;
            foreach ($tds[1] as $td) {
                if (preg_match('/name="(editform:[^"]*?:\d+:checkBoxes)"/', $td, $cb)) { if ($cur) $cov[] = $cur; $n = $this->decodeHtml($cb[1]); $cur = ['name' => $n, 'label' => '', 'checked' => isset($vals[$n]), 'select' => null]; continue; }
                if (!$cur) continue;
                if (preg_match('/<select\b[^>]*\bname=["\']([^"\']+)["\'][^>]*>(.*?)<\/select>/is', $td, $sl)) {
                    $opts = []; if (preg_match_all('/<option\b[^>]*\bvalue=["\']([^"\']*)["\'][^>]*>([^<]*)/is', $sl[2], $om, PREG_SET_ORDER)) foreach ($om as $o) $opts[] = [$this->decodeHtml($o[1]), trim($this->decodeHtml($o[2]))];
                    $sn = $this->decodeHtml($sl[1]); $cur['select'] = ['name' => $sn, 'options' => $opts, 'value' => (string)($vals[$sn] ?? '')];
                    continue;
                }
                $t = psgp_text($td); if ($t !== '') $cur['label'] = trim($cur['label'] . ' ' . mb_substr($t, 0, 120));
            }
            if ($cur) $cov[] = $cur;
        }
        // نوعِ وسیله‌ی نقلیه (پنهان) بر اساسِ «مورد استفاده»: نقشه‌ی خودِ صفحه
        $veh = [];
        if (preg_match_all("/noeVasilehNaghliehMap_hm_h(\d+)_hm\[(\d+)\]\s*=\s*'([^']*)'/u", $html, $vm, PREG_SET_ORDER)) foreach ($vm as $v) $veh[$v[1]][] = [$v[2], json_decode('"' . $v[3] . '"') ?? $v[3]];
        $buttons = [];
        if (preg_match_all("/submitForm\('editform','(editform:[^']+)'\);?\"[^>]*>\s*([^<]{2,40})/u", $html, $bm, PREG_SET_ORDER)) foreach ($bm as $b) $buttons[trim($b[2])] = $b[1];
        return ['fields' => $out, 'coverages' => $cov, 'vehicle_map' => $veh, 'values' => $vals, 'buttons' => $buttons];
    }
    // فرمِ urlencoded با پشتیبانیِ چند مقدار
    public function post($html, array $vals, $clicked) {
        $f = $this->multiFields($html);
        foreach ($vals as $k => $v) { if ($v === null) unset($f[$k]); else $f[$k] = is_array($v) ? array_map('strval', $v) : (string)$v; }
        $vs = $f['javax.faces.ViewState'] ?? null;
        unset($f['editform_SUBMIT'], $f['javax.faces.ViewState'], $f['editform:_idcl'], $f['editform:_link_hidden_']);
        $f['editform_SUBMIT'] = '1';
        if ($vs !== null) $f['javax.faces.ViewState'] = $vs;
        $f['editform:_idcl'] = $clicked;
        $q = [];
        foreach ($f as $k => $v) foreach ((array)$v as $x) $q[] = rawurlencode($k) . '=' . rawurlencode((string)$x);
        return $this->http('POST', $this->core . PSGP_FORM, implode('&', $q), $this->core . PSGP_EDIT, false, ['Content-Type: application/x-www-form-urlencoded']);
    }
    // جستجو در فهرستِ «تیپ» (یا هر LOV): ردیف‌هایی که select(id, desc) را صدا می‌زنند
    public function lov($lovPath, $q) {
        if (!preg_match('#^/cins/[A-Za-z0-9_]+\.lov\.html#', $lovPath)) return ['ok' => false, 'error' => 'فهرستِ نامعتبر.'];
        $r = $this->http('GET', $this->core . $lovPath);
        if ($this->loggedOut($r['body'])) return ['ok' => false, 'need_login' => true];
        $html = $r['body'];
        $fid = preg_match('/<form\b[^>]*\bid=["\']([^"\']+)["\']/i', $html, $fm) ? $fm[1] : 'exampleform';
        $act = preg_match('/<form\b[^>]*\baction=["\']([^"\']+)["\']/i', $html, $am) ? $this->decodeHtml($am[1]) : '';
        $f = $this->fields($html, $fid);
        // اولین خانه‌ی متنیِ «عنوان/نام/شرح» برای جستجو
        $target = null;
        foreach (array_keys($f) as $k) if (preg_match('/(onvan|naam|name|desc|sharh|title|tip)/i', $k) && !preg_match('/ViewState|_SUBMIT|_idcl|goto|token/i', $k)) { $target = $k; break; }
        if ($target === null) foreach (array_keys($f) as $k) if (preg_match('/^' . preg_quote($fid, '/') . ':/', $k) && !preg_match('/ViewState|_SUBMIT|_idcl|goto/i', $k)) { $target = $k; break; }
        $btn = preg_match("/submitForm\('" . preg_quote($fid, '/') . "','([^']*search[^']*)'/i", $html, $bm) ? $bm[1] : $fid . ':search';
        if ($target !== null && $q !== '') {
            $f[$target] = $q;
            $vs = $f['javax.faces.ViewState'] ?? null; unset($f['javax.faces.ViewState']);
            $f[$fid . '_SUBMIT'] = '1'; if ($vs !== null) $f['javax.faces.ViewState'] = $vs; $f[$fid . ':_idcl'] = $btn;
            $url = $act !== '' ? (strpos($act, 'http') === 0 ? $act : $this->core . $act) : $this->core . $lovPath;
            $r2 = $this->http('POST', $url, $f, $this->core . $lovPath, true);
            if ($this->loggedOut($r2['body'])) return ['ok' => false, 'need_login' => true];
            $html = $r2['body'];
        }
        $items = [];
        if (preg_match_all('/(?:opener\.|window\.opener\.|parent\.)?select\s*\(\s*([^)]*)\)/i', $html, $sm)) foreach ($sm[1] as $a) {
            $args = $this->parseJsArgs($a);
            if (count($args) >= 2 && preg_match('/^\d+$/', (string)$args[0]) && $args[1] !== '') $items[(string)$args[0]] = ['id' => (string)$args[0], 'desc' => (string)$args[1]];
        }
        return ['ok' => true, 'items' => array_values(array_slice($items, 0, 60)), 'searched' => $target !== null];
    }
    public function report($file) {
        if (!preg_match('/^[A-Za-z0-9_\-\.]+\.pdf$/', $file)) return null;
        $r = $this->http('GET', $this->core . '/cins/bimehReportServlet?printFileName=' . rawurlencode($file), [], $this->core . PSGP_FORM, false, ['Accept: application/pdf,*/*']);
        return strpos((string)$r['body'], '%PDF') === 0 ? $r['body'] : null;
    }
}

// جدول‌های «جزئیات حق بیمه» (بعد از استعلام): [[خانه‌ها…], …]
function psgp_result_tables($html) {
    $p = mb_strpos($html, 'جزئیات حق بیمه');
    if ($p === false) $p = mb_strpos($html, 'حق بیمه خالص');
    if ($p === false) return [];
    $seg = mb_substr($html, max(0, $p - 400), 60000);
    $tables = [];
    if (preg_match_all('#<table\b.*?</table>#is', $seg, $tm)) foreach ($tm[0] as $t) {
        $rows = [];
        if (preg_match_all('#<tr\b.*?</tr>#is', $t, $rm)) foreach ($rm[0] as $tr) {
            if (preg_match_all('#<t[hd]\b[^>]*>(.*?)</t[hd]>#is', $tr, $cm)) { $cells = array_map('psgp_text', $cm[1]); if (array_filter($cells, 'strlen')) $rows[] = $cells; }
        }
        if ($rows && preg_match('/\d{1,3}(,\d{3})+/', implode(' ', array_merge(...$rows)))) $tables[] = $rows;
        if (count($tables) >= 4) break;
    }
    return $tables;
}

function psgp_lock() { return inq_lock(45); }
function psgp_state_file($uid) { return psgp_dir() . '/u' . intval($uid) . '.html'; }

function psgp_boot($pdo, $uid) {
    $lk = psgp_lock();
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است؛ چند ثانیه‌ی دیگر.'];
    try {
        @set_time_limit(120);
        $cls = $GLOBALS['PSGP_CLASS'] ?? 'PsgPremium'; $c = new $cls();   // (آزمون: کلاسِ جایگزین)
        $o = $c->open();
        if (!empty($o['need_login'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'اول وارد سامانه‌ی پاسارگاد شوید.']; }
        if (empty($o['ok'])) return $o;
        @file_put_contents(psgp_state_file($uid), $o['html']);
        @file_put_contents(psgp_state_file($uid) . '.slot', (string)px_slot());
        $s = $c->schema($o['html']);
        unset($s['values']);
        if (function_exists('inq_touch_activity')) inq_touch_activity();
        return ['ok' => true] + $s;
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
}
function psgp_lock_user($uid) {
    $sf = psgp_state_file($uid) . '.slot';
    return px_pool_size() > 1 && is_file($sf) ? inq_lock_slot(intval(file_get_contents($sf)), 45) : inq_lock(45);
}
// استعلام (+ چاپ اگر خواسته شد)
function psgp_calc($pdo, $uid, array $vals, $print = true) {
    $sf = psgp_state_file($uid);
    if (!is_file($sf) || filemtime($sf) < time() - 1800) return ['ok' => false, 'expired' => true, 'error' => 'فرم کهنه شده؛ دوباره باز کنید.'];
    $lk = psgp_lock_user($uid);
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است؛ چند ثانیه‌ی دیگر.'];
    try {
        @set_time_limit(200);
        $cls = $GLOBALS['PSGP_CLASS'] ?? 'PsgPremium'; $c = new $cls();   // (آزمون: کلاسِ جایگزین)
        $html = (string)file_get_contents($sf);
        $s = $c->schema($html);
        $calcId = null; $printId = null;
        foreach ($s['buttons'] as $lab => $id) { if (mb_strpos($lab, 'چاپ') !== false) $printId = $id; elseif (mb_strpos($lab, 'استعلام حق بیمه') !== false) $calcId = $id; }
        if (!$calcId) return ['ok' => false, 'error' => 'دکمه‌ی «استعلام حق بیمه» در صفحه پیدا نشد.'];
        // تاریخ‌ها: «۱۴۰۵/۰۸/۱۳» ← خانه‌ی پنهان + _y/_m/_d
        foreach ($vals as $k => $v) if (is_string($v) && preg_match('#^(1[34]\d\d)/(\d{1,2})/(\d{1,2})$#', p2e_digits($v), $m) && (array_key_exists($k . '_y', $s['values']))) {
            $vals[$k] = sprintf('%s/%02d/%02d;HIJRI', $m[1], $m[2], $m[3]); $vals[$k . '_y'] = $m[1]; $vals[$k . '_m'] = sprintf('%02d', $m[2]); $vals[$k . '_d'] = sprintf('%02d', $m[3]);
        }
        // چک‌باکس‌های پوشش: تیک‌نخورده‌ها حذف
        foreach ($s['coverages'] as $cv) if (array_key_exists($cv['name'], $vals) && !$vals[$cv['name']]) $vals[$cv['name']] = null; elseif (!empty($vals[$cv['name']])) $vals[$cv['name']] = 'true';
        $r = $c->post($html, $vals, $calcId);
        if ($c->isOut($r['body'])) { inq_px_mark('logged_out'); return ['ok' => false, 'need_login' => true, 'error' => 'نشستِ سامانه بسته شد.']; }
        $body = $r['body'];
        $n = $c->notices($body);
        // هشدارِ زرد: یک بار دیگر (مثلِ رفتارِ خودِ سامانه)
        if (!psgp_result_tables($body) && $n['onlyWarning']) { $r = $c->post($body, $vals, $calcId); $body = $r['body']; $n = $c->notices($body); }
        @file_put_contents($sf, $body);
        $tables = psgp_result_tables($body);
        $out = ['ok' => (bool)$tables, 'tables' => $tables, 'warnings' => $n['warnings'], 'errors' => $n['errors'], 'messages' => $n['messages']];
        if (!$tables) $out['error'] = $n['errors'] ? implode(' — ', $n['errors']) : 'جوابِ استعلام در صفحه پیدا نشد' . ($n['warnings'] ? ' (' . implode(' — ', $n['warnings']) . ')' : '') . '.';
        if ($tables && $print && $printId) {
            $p = $c->post($body, $vals, $printId);
            if (preg_match('/printFileName=([A-Za-z0-9_\-\.]+\.pdf)/', (string)$p['body'], $pm) && ($pdf = $c->report($pm[1]))) {
                $tok = bin2hex(random_bytes(8));
                @file_put_contents(psgp_dir() . '/p_' . intval($uid) . '_' . $tok . '.pdf', $pdf);
                $out['pdf'] = $tok;
                foreach (glob(psgp_dir() . '/p_*.pdf') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
            } else $out['pdf_error'] = 'فایلِ چاپ از سامانه نیامد.';
            if (!empty($p['body']) && strpos($p['body'], 'editform') !== false) @file_put_contents($sf, $p['body']);
        }
        if (function_exists('inq_touch_activity')) inq_touch_activity();
        return $out;
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
}
function psgp_lov($pdo, $uid, $lov, $q) {
    $lk = psgp_lock_user($uid);
    if (!$lk) return ['ok' => false, 'error' => 'نشستِ پاسارگاد مشغول است.'];
    try { @set_time_limit(90); $cls = $GLOBALS['PSGP_CLASS'] ?? 'PsgPremium'; $c = new $cls(); $r = $c->lov((string)$lov, trim((string)$q)); if (!empty($r['need_login'])) inq_px_mark('logged_out'); return $r; }
    catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای داخلی: ' . mb_substr($e->getMessage(), 0, 160)]; }
    finally { inq_unlock($lk); }
}
