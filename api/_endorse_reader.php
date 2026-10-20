<?php
// فایل: api/_endorse_reader.php
// خواندنِ الحاقیه‌ی بیمه‌گر (پاسارگاد) و فایلِ الحاقیه‌های گروهی، و اسکنِ بایگانی‌های قبلی — با PHP
// (جایگزینِ api/py/endorse_reader.py، endorse_bundle.py و legacy_scan.py؛ همان قاعده‌ها)
//   er_read_file($abs)        => همان JSONِ endorse_reader.py
//   er_bundle($pdf, $outDir)  => همان JSONِ endorse_bundle.py
//   lgs_scan(array $files)    => همان JSONِ legacy_scan.py
require_once __DIR__ . '/_policy_reader.php';

const ER_POLICY_RX = '(\d{1,2}/\d{3,6}/\d{3,6}-\d{1,3}/1[34]\d{2}/\d{1,7})';

function er_label_value_dates(array $spans, $rx) {
    foreach ($spans as $sp) {
        if (pb_m($rx, $sp['t'])) {
            $ds = pb_dates_in($sp['t']);
            if ($ds) return $ds[0];
            foreach (pb_row_values($spans, $sp) as $v) { $ds = pb_dates_in($v['t']); if ($ds) return $ds[0]; }
        }
    }
    return '';
}

function er_label_amount(array $spans, $rx, $exclude = null) {
    foreach ($spans as $sp) {
        $t = $sp['t'];
        if (!pb_m($rx, $t) || ($exclude && pb_m($exclude, $t))) continue;
        $vals = pb_money_values($t);
        if (!$vals) foreach (pb_row_values($spans, $sp) as $v) { $vals = pb_money_values($v['t']); if ($vals) break; }
        if ($vals) return max($vals);
    }
    return 0;
}

function er_fix_value($v) {
    $v = pb_ustrip((string)$v);
    if (pb_m('[آ-ی]', $v) && pb_m('\d', $v) && !pb_m('\s', $v)) {
        $parts = array_map(function ($m) { return $m[0]; }, pb_all('\d+|[^\d]+', $v));
        $v = implode('', array_reverse($parts));
    }
    return $v;
}

function er_fix_suffix($v) {
    $v = pb_ustrip((string)$v);
    return pb_ustrip(str_replace("\x00", '(', str_replace('(', ')', str_replace(')', "\x00", $v))));
}

function er_fix_change_line($line) {
    $t = pb_ustrip(ltrim(pb_ustrip(pb_sub('\s+', ' ', $line)), '.'));
    $t = pb_ustrip(pb_sub('^\d{1,2}(?=\D)', '', $t));
    $sec = '';
    if (pb_m('^(.*?)-\s*([^-]+)$', $t, $m) && mb_strlen($m[2]) < 40) { $t = pb_ustrip($m[1]); $sec = pb_ustrip($m[2]); }
    if (pb_m('^(.*?)"\s*(تغییر\s*می\s*کند)(.*?)"\s*(.*?)"\s*به(.*?)"\s*(.+?)\s*:\s*از$', $t, $m)) {
        $to = pb_ustrip(er_fix_value($m[3]) . ' ' . er_fix_suffix($m[1]));
        $fr = pb_ustrip(er_fix_value($m[5]) . ' ' . er_fix_suffix($m[4]));
        $field = pb_ustrip($m[6]);
        $txt = $field . ': از «' . $fr . '» به «' . $to . '»';
        if (pb_full('[\s()\-]*', $fr)) $txt = $field . ': «' . $to . '» (قبلاً خالی بود)';
    } else {
        $txt = er_fix_suffix(trim($t, ' .'));
        $txt = pb_sub('از\s*"([^"]*)"\s*به\s*"([^"]*)"\s*تغییر\s*می\s*کند', 'از «$1» به «$2»', $txt);
    }
    $txt = pb_ustrip(str_replace(' :', ':', pb_sub('\s+', ' ', $txt)));
    return $sec !== '' ? $sec . ' - ' . $txt : $txt;
}

// یک الحاقیه از صفحه‌های $pages (شماره‌ها از ۰)
function er_read(PdfDoc $doc, PdfText $pt, $pages = null) {
    $idx = $pages !== null ? array_values($pages) : range(0, $doc->pageCount() - 1);
    $texts = [];
    foreach ($idx as $i) { try { $texts[] = pb_norm($pt->text($i)); } catch (Throwable $e) { $texts[] = ''; } }
    $full = implode("\n", $texts);
    if (mb_strlen(pb_ustrip($full)) < 40) return ['ok' => false, 'scan' => true, 'error' => 'فایل متن ندارد (اسکن/عکس است)؛ اطلاعاتِ الحاقیه را دستی وارد کنید.', 'pages' => count($idx)];
    if (mb_strpos($full, 'الحاقیه') === false) return ['ok' => false, 'error' => 'این فایل الحاقیه به نظر نمی‌رسد (کلمه‌ی «الحاقیه» در آن نیست).', 'pages' => count($idx)];

    $mainK = 0;
    foreach ($texts as $k => $tx) if (mb_strpos($tx, 'الحاقیه') !== false && mb_strpos($tx, 'اعلامیه آخرین وضعیت') === false) { $mainK = $k; break; }
    $t = $texts[$mainK];
    $spans = pb_page_spans($pt, $idx[$mainK]);
    $d = [];
    $d['policy_num'] = (pb_m(ER_POLICY_RX, $t, $m) || pb_m(ER_POLICY_RX, $full, $m)) ? $m[1] : '';

    $no = '';
    foreach ($spans as $sp) {
        if (pb_m('الحاقیه\s*شماره', $sp['t'])) {
            if (pb_m('الحاقیه\s*شماره\s*:?\s*(\d{1,3})(?!\d)', $sp['t'], $mm)) { $no = $mm[1]; break; }
            foreach (pb_row_values($spans, $sp) as $v) if (pb_full('\d{1,3}', pb_ustrip($v['t']))) { $no = pb_ustrip($v['t']); break; }
            if ($no !== '') break;
        }
    }
    if ($no === '') {
        $pq = preg_quote($d['policy_num'] !== '' ? $d['policy_num'] : '#', '~');
        if (pb_m('الحاقیه\s*شماره\s*:?\s*(\d{1,3})(?!\d)', $full, $mm) || pb_m('(?<![\d/])(\d{1,3})\s*\n\s*' . $pq, $t, $mm)) $no = $mm[1];
    }
    $d['endorse_no'] = $no;

    $hasMk = pb_m('الحاقیه\s*(اضافی|برگشتی|اصلاحی|فسخ)', $t, $mk);
    if (pb_m('فسخ', $t) && pb_m('ساقط|فسخ\s*بیمه\s*گذار|فسخ\s*از\s*طرف', $t)) $kind = 'CANCELLATION';
    elseif ($hasMk) $kind = ['اضافی' => 'ADDITIONAL', 'برگشتی' => 'REFUND', 'اصلاحی' => 'CORRECTIVE', 'فسخ' => 'CANCELLATION'][$mk[1]];
    elseif (pb_m('برگشتی', $t)) $kind = 'REFUND';
    elseif (pb_m('اضافی', $t)) $kind = 'ADDITIONAL';
    elseif (pb_m('اصلاحی', $t)) $kind = 'CORRECTIVE';
    else $kind = '';
    $d['kind'] = $kind;
    $d['kind_fa'] = ['CANCELLATION' => 'فسخ (برگشتی)', 'REFUND' => 'برگشتی', 'ADDITIONAL' => 'اضافی', 'CORRECTIVE' => 'اصلاحی'][$kind] ?? '';
    $d['insurance_type'] = mb_strpos($t, 'بیمه بدنه') !== false ? 'بدنه' : (mb_strpos($t, 'ثالث') !== false ? 'ثالث' : '');

    $d['issue_date'] = er_label_value_dates($spans, 'تاریخ\s*صدور');
    $eff = '';
    foreach (explode("\n", $t) as $line) {
        if (pb_m('عطف\s*به\s*درخواست|از\s*ساعت', $line)) { $ds = pb_dates_in($line); if ($ds) { $eff = $ds[0]; break; } }
    }
    $d['effect_date'] = $eff !== '' ? $eff : $d['issue_date'];
    $end = '';
    if (pb_m('تاریخ\s*پایان[^\n]*?(1[34]\d{2}/\d{1,2}/\d{1,2})[^\n]*?(1[34]\d{2}/\d{1,2}/\d{1,2})', $t, $mm)) {
        $a = pb_dates_in($mm[1])[0]; $b = pb_dates_in($mm[2])[0];
        $end = mb_strpos($t, 'افزایش') !== false ? max($a, $b) : $b;
    }
    if ($end === '') $end = er_label_value_dates($spans, 'تاریخ\s*پایان');
    $d['new_end_date'] = $kind !== 'CANCELLATION' ? $end : $d['effect_date'];

    $gross = 0;
    $lines = explode("\n", $t);
    foreach ($lines as $li => $line) {
        if (pb_m('به\s*حروف', $line)) {
            $rest = implode("\n", array_slice($lines, $li, 3));
            if (pb_m('([آ-ی\s‌]{6,}?)\s*ریال', $rest, $w)) $gross = pb_fa_words_to_int($w[1]);
            if ($gross) break;
        }
    }
    $labGross = er_label_amount($spans, 'حق\s*بیمه\s*(الحاقیه|برگشتی)', 'خالص|حروف');
    if (!$gross || ($labGross && abs($labGross - $gross) > 1000)) $gross = $labGross ?: $gross;
    $d['gross'] = $gross;
    $d['net'] = er_label_amount($spans, 'حق\s*بیمه\s*خالص');
    $d['tax'] = er_label_amount($spans, 'مالیات');
    $d['levy'] = er_label_amount($spans, 'عوارض');
    if (!$d['gross'] && $d['net']) $d['gross'] = $d['net'] + $d['tax'] + $d['levy'];

    $d['central_no'] = (pb_m('(?<!\d)(\d{11})(?!\d)\s*:?\s*شماره\s*بیمه\s*مرکزی', $t, $mm) || pb_m('شماره\s*بیمه\s*مرکزی\s*:?\s*(\d{11})', $t, $mm)) ? $mm[1] : '';
    $ins = '';
    foreach ($spans as $sp) if (pb_m('\(\s*1[34]\d{2}\s*-\s*\d+\s*\)\s*(.+)$', $sp['t'], $mm)) { $ins = pb_ustrip($mm[1]); break; }
    $d['insured'] = $ins;
    $d['description'] = pb_m('شرح\s*الحاقیه\s*:?\s*([^\n]+)', $t, $mm) ? trim(pb_ustrip($mm[1]), ' :.') : '';
    $changes = [];
    foreach (explode("\n", $t) as $line) {
        if (mb_strpos($line, 'تغییر کرده است') !== false) continue;
        if (pb_m('تغییر\s*می\s*کند|اضافه\s*می\s*شود|حذف\s*می\s*شود', $line) && mb_strlen($line) > 12) {
            $c = er_fix_change_line($line);
            if ($c !== '' && !in_array($c, $changes, true)) $changes[] = $c;
        }
    }
    $d['changes'] = array_slice($changes, 0, 30);
    if ($d['description'] === '') {
        foreach (['افزایش مدت بیمه نامه', 'کاهش مدت', 'تغییر مالکیت', 'اصلاح', 'فروش رفته'] as $kw) if (mb_strpos($t, $kw) !== false) { $d['description'] = $kw; break; }
    }
    $st = [];
    foreach ($texts as $tx) if (mb_strpos($tx, 'اعلامیه آخرین وضعیت') !== false) $st = array_merge($st, pb_statement_rows($tx));
    $missing = [];
    foreach (['policy_num', 'kind', 'issue_date', 'gross'] as $k) if (empty($d[$k])) $missing[] = $k;
    if ($kind === 'CORRECTIVE') $missing = array_values(array_diff($missing, ['gross']));
    return ['ok' => true, 'data' => $d, 'statement' => $st, 'pages' => count($texts), 'missing' => $missing];
}

function er_starts(PdfDoc $doc, PdfText $pt) {
    $st = [];
    for ($i = 0, $n = $doc->pageCount(); $i < $n; $i++) {
        try { $t = pb_norm($pt->text($i)); } catch (Throwable $e) { continue; }
        if (mb_strpos($t, 'الحاقیه') !== false && mb_strpos($t, 'اعلامیه آخرین وضعیت') === false && pb_m(ER_POLICY_RX, $t) && pb_m('الحاقیه\s*شماره|نوع\s*الحاقیه', $t)) $st[] = $i;
    }
    return $st;
}

function er_read_file($abs) {
    try { $doc = PdfDoc::open($abs); } catch (Throwable $e) { return ['ok' => false, 'error' => 'فایل PDF باز نشد.', 'debug' => mb_substr($e->getMessage(), 0, 200)]; }
    try {
        $pt = new PdfText($doc);
        $r = er_read($doc, $pt);
        $starts = er_starts($doc, $pt);
        if (count($starts) > 1) $r['multi'] = count($starts);
        return $r;
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای پیش‌بینی‌نشده در خواندنِ الحاقیه.', 'debug' => mb_substr($e->getMessage(), 0, 400)]; }
}

function er_bundle($src, $outdir) {
    if (!is_dir($outdir)) @mkdir($outdir, 0755, true);
    try { $doc = PdfDoc::open($src); } catch (Throwable $e) { return ['ok' => false, 'error' => 'فایل PDF باز نشد.', 'debug' => mb_substr($e->getMessage(), 0, 200)]; }
    @set_time_limit(600);
    try {
        $pt = new PdfText($doc);
        $n = $doc->pageCount();
        $starts = er_starts($doc, $pt);
        if (!$starts) {
            $r = er_read($doc, $pt);
            return ['ok' => false, 'pages' => $n, 'error' => $r['error'] ?? 'در این فایل الحاقیه‌ای پیدا نشد (صفحه‌ی اصلیِ الحاقیه با شماره‌ی بیمه‌نامه شناسایی نشد).', 'scan' => $r['scan'] ?? false];
        }
        $items = [];
        foreach ($starts as $k => $s) {
            $e = isset($starts[$k + 1]) ? $starts[$k + 1] - 1 : $n - 1;
            $fn = sprintf('seg_%03d.pdf', $k + 1);
            try { $w = new PdfWriter(); for ($p = $s; $p <= $e; $p++) $w->addPage($doc, $p); $w->save($outdir . '/' . $fn); }
            catch (Throwable $ex) { return ['ok' => false, 'error' => 'جدا کردنِ صفحه‌ها ممکن نشد.', 'debug' => mb_substr($ex->getMessage(), 0, 300)]; }
            $r = er_read($doc, $pt, range($s, $e));
            $items[] = ['page' => $s + 1, 'end' => $e + 1, 'file' => $fn, 'ok' => !empty($r['ok']), 'data' => $r['data'] ?? new stdClass(),
                        'statement' => $r['statement'] ?? [], 'missing' => $r['missing'] ?? [], 'error' => $r['error'] ?? null];
        }
        return ['ok' => true, 'pages' => $n, 'items' => $items];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطای پیش‌بینی‌نشده در خواندنِ فایلِ گروهی.', 'debug' => mb_substr($e->getMessage(), 0, 400)]; }
}

// «ورودِ بایگانی‌های قبلی»: شماره‌ی بیمه‌نامه، شاسی و پلاک از دو صفحه‌ی اولِ هر PDF
function lgs_scan(array $files) {
    $res = [];
    foreach ($files as $f) {
        $info = ['policies' => [], 'vins' => [], 'plates' => [], 'kind' => 'other', 'text' => false];
        try {
            $doc = PdfDoc::open($f);
            $pt = new PdfText($doc);
            $t = '';
            for ($i = 0; $i < min(2, $doc->pageCount()); $i++) $t .= $pt->text($i) . "\n";
            $t = pb_norm($t);
            $info['text'] = mb_strlen(pb_ustrip($t)) > 30;
            $info['policies'] = array_slice(array_values(array_unique(array_map(function ($m) { return $m[1]; }, pb_all(ER_POLICY_RX, $t)))), 0, 5);
            $vins = [];
            foreach (array_unique(array_map(function ($m) { return $m[1]; }, pb_all('(?<![A-Z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Z0-9])', mb_strtoupper($t)))) as $v) if (pb_m('[A-Z]', $v) && pb_m('\d', $v)) $vins[] = $v;
            $info['vins'] = array_slice($vins, 0, 3);
            $p = pb_parse_plate($t);
            if ($p !== '') $info['plates'] = [$p];
            if (mb_strpos(mb_substr($t, 0, 1500), 'الحاقیه') !== false) $info['kind'] = 'endorse';
            elseif (pb_m('اعلامیه\s*آخرین\s*وضعیت|سند\s*دریافت', mb_substr($t, 0, 800))) $info['kind'] = 'statement';
            elseif ($info['policies'] && pb_m('بیمه\s*نامه|بیمه‌نامه', mb_substr($t, 0, 1500))) $info['kind'] = 'policy';
        } catch (Throwable $e) { $info['error'] = mb_substr($e->getMessage(), 0, 120); }
        $res[$f] = $info;
    }
    return ['ok' => true, 'files' => $res];
}
