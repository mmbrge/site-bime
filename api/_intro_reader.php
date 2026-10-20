<?php
// فایل: api/_intro_reader.php
// خواندنِ «معرفی‌نامه‌ی پرسنلی» از PDFِ متنی با PHP (جایگزینِ api/py/intro_reader.py؛ همان قاعده‌ها)
//   ir_read_pdf($abs) => ['ok'=>true, 'has_text'=>bool, 'is_intro'=>bool, 'score'=>int, 'data'=>[...]] | ['ok'=>false, 'error'=>...]
require_once __DIR__ . '/_policy_reader.php';

const IR_ALIASES = [['ویانا', 'گروه ویانا']];
const IR_GENERIC = '^(?:این|همین|آن)\s*(?:شرکت|مجموعه|سازمان|گروه)$|^(?:شرکت|مجموعه|سازمان)$';
const IR_END = '(?:\s*ب\s*ل\s*ا\s*مانع|\s+(?:می\s*باشد|می\s*باشند|است|هستند|که|و\s+(?:کد|شماره))(?=\s|$|[.،])|\s*[.،])';

function ir_norm($t) {
    if ($t === null || $t === '') return '';
    $t = pdft_nfkc((string)$t);
    $t = strtr($t, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'ـ' => '']);
    $t = pb_sub('[\x{064B}-\x{0652}\x{0670}]', '', $t);
    $t = strtr($t, ["\u{200C}" => ' ', "\u{200F}" => ' ', "\u{200E}" => ' ']);
    $t = strtr($t, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    $t = pb_sub('\s+', ' ', $t);
    return pb_ustrip($t);
}

function ir_clean_name($s) {
    $s = pb_sub('[^\x{0600}-\x{06FF}\s]', ' ', (string)$s);
    $s = pb_ustrip(pb_sub('\s+', ' ', $s));
    return pb_sub('^(?:آقای|آقا|خانم|سرکار|جناب)\s+', '', $s);
}

function ir_clean_company($s) {
    $s = pb_sub('\s+', ' ', pb_trim(pb_ustrip((string)$s), ' .،,:؛-'));
    $s = pb_sub('^(?:شرکت|موسسه|مؤسسه)\s+', '', $s);
    $s = pb_sub('\s+(?:شرکت)$', '', $s);
    return pb_ustrip($s);
}

function ir_alias($s) {
    foreach (IR_ALIASES as [$k, $v]) if (mb_strpos($s, $k) !== false) return $v;
    return $s;
}

function ir_body_company($t) {
    foreach (['شاغل\s*در\s*(.{2,80}?)' . IR_END, '(?:کارمند|پرسنل|کارکنان|همکار)\s*(?:محترم\s*)?(?:شرکت|مجموعه|گروه)\s+(.{2,60}?)' . IR_END] as $pat) {
        foreach (pb_all($pat, $t) as $m) {
            $raw = pb_ustrip($m[1]);
            $c = ir_clean_company($raw);
            if ($c === '' || pb_m(IR_GENERIC, $raw) || pb_m(IR_GENERIC, $c) || pb_m('^(?:این|همین|آن)\b', $c)) continue;
            if (mb_strlen($c) > 50 || pb_m('\d', $c)) continue;
            return ir_alias($c);
        }
    }
    return '';
}

function ir_sign_company($t) {
    $p = mb_strpos($t, 'با تشکر');
    $tail = $p !== false ? mb_substr($t, $p) : mb_substr($t, -250);
    $comp = pb_m('((?:گروه|شرکت|هلدینگ|موسسه)\s+[\x{0600}-\x{06FF} ]{2,50})', $tail, $m2) ? ir_clean_company($m2[1]) : '';
    $comp = pb_ustrip(pb_sub('\s+(?:مدیر|سرمایه|انسانی|معاونت|امور|واحد|منابع)(?:\s.*)?$', '', $comp));
    if (pb_m(IR_GENERIC, $comp) || in_array($comp, ['شرکت', 'گروه'], true)) $comp = '';
    $hasAlias = false;
    foreach (IR_ALIASES as [$k, $v]) if (mb_strpos($comp, $k) !== false) $hasAlias = true;
    if ($comp === '' || !$hasAlias) {
        foreach (IR_ALIASES as [$k, $v]) if (mb_strpos($tail, $k) !== false) return $v;
    }
    return $comp !== '' ? ir_alias($comp) : '';
}

function ir_extract($text) {
    $t = ir_norm($text);
    $score = 0;
    foreach (['پرسنلی', 'شاغل', 'لامانع', 'خانم', 'کد ملی', 'سرمایه انسانی', 'صدور بیمه', 'ثالث/بدنه', 'کارگزاری'] as $k) if (mb_strpos($t, $k) !== false) $score++;
    $d = ['ins_type' => 'ناشناخته', 'insured_name' => '', 'national_id' => '', 'personnel_id' => '', 'company_name' => '', 'letter_date' => '', 'letter_no' => ''];
    $isIntro = (mb_strpos($t, 'پرسنلی') !== false && (mb_strpos($t, 'شاغل') !== false || mb_strpos($t, 'لامانع') !== false)) || $score >= 4;
    if (pb_m('(?:آقا(?:ی)?\s*/\s*خانم|خانم\s*/\s*آقا(?:ی)?|آقای|خانم)\s+(.{2,60}?)\s+(?:(?:به|با)\s+)?شماره\s*(?:ی\s*)?پرسنلی', $t, $m)) $d['insured_name'] = ir_clean_name($m[1]);
    if (pb_m('پرسنلی\s*[:]?\s*(\d{3,12})', $t, $m)) $d['personnel_id'] = $m[1];
    if (pb_m('(?:کد|شماره)\s*ملی\s*[:]?\s*(\d{8,10})', $t, $m)) $d['national_id'] = str_pad($m[1], 10, '0', STR_PAD_LEFT);
    $comp = ir_body_company($t);
    $src = $comp !== '' ? 'text' : '';
    if ($comp === '') { $comp = ir_sign_company($t); $src = $comp !== '' ? 'signature' : ''; }
    $d['company_src'] = $src;
    $d['company_name'] = $comp;
    if (pb_m('تاریخ\s*[:]?\s*(\d{1,4})\s*/\s*(\d{1,2})\s*/\s*(\d{1,4})', $t, $m) || pb_m('(\d{1,4})\s*/\s*(\d{1,2})\s*/\s*(\d{1,4})\s*[:]?\s*تاریخ', $t, $m)) {
        [$a, $b, $c] = [$m[1], $m[2], $m[3]];
        [$y, $md, $dd] = strlen($c) === 4 ? [$c, $b, $a] : [$a, $b, $c];
        if (strlen($y) === 4) $d['letter_date'] = sprintf('%s/%02d/%02d', $y, (int)$md, (int)$dd);
    }
    if (pb_m('شماره\s*[:]?\s*(\d{1,4}\s*[-–—]\s*\d{3,10}\s*[-–—]\s*\d{1,4})', $t, $m) || pb_m('(\d{1,4}\s*[-–—]\s*\d{3,10}\s*[-–—]\s*\d{1,4})\s*[:]?\s*شماره', $t, $m)) {
        $d['letter_no'] = pb_sub('[–—]', '-', pb_sub('\s+', '', $m[1]));
    }
    if ($isIntro) $d['ins_type'] = 'معرفی‌نامه';
    return [$isIntro, $score, $d];
}

function ir_read_pdf($abs) {
    try {
        $doc = PdfDoc::open($abs);
        $pt = new PdfText($doc);
        $parts = [];
        for ($i = 0, $n = min($doc->pageCount(), 20); $i < $n; $i++) $parts[] = $pt->text($i);
        $text = implode(' ', $parts);
        if (mb_strlen(ir_norm($text)) < 40) return ['ok' => true, 'has_text' => false, 'is_intro' => false, 'score' => 0, 'data' => []];
        [$isIntro, $score, $d] = ir_extract($text);
        return ['ok' => true, 'has_text' => true, 'is_intro' => $isIntro, 'score' => $score, 'data' => $d];
    } catch (Throwable $e) { return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)]; }
}
