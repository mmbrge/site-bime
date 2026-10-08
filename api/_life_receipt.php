<?php
// فایل: api/_life_receipt.php
// رسیدِ پرداختِ قسط: قالبِ پیش‌فرضِ سامانه (بدونِ فایل) یا قالبِ Wordِ بارگذاری‌شده.
// در رسید: مشخصاتِ بیمه‌نامه، جدولِ همه‌ی اقساطِ همان شخص (قسطِ انتخابی پررنگ)، متنِ زیرِ جدول (برای هر قالب جدا)
// و فیشِ قسطِ انتخابی با جزئیاتِ پرداخت.

function life_tpl_dir() { $d = life_root() . '/tmpl_invoice/life'; life_mkdir($d); return $d; }

// کدهای قابلِ استفاده در قالبِ Word
function life_receipt_codes() {
    return [
        'شماره_رسید' => 'شماره‌ی رسید', 'تاریخ_امروز' => 'تاریخِ صدورِ رسید', 'نام_بیمه_گذار' => 'نامِ بیمه‌گذار', 'کد_ملی' => 'کد ملیِ بیمه‌گذار', 'موبایل' => 'موبایل',
        'شماره_بیمه_نامه' => 'شماره‌ی بیمه‌نامه', 'بیمه_شده' => 'بیمه‌شده‌ی اصلی', 'تاریخ_صدور' => 'تاریخِ صدورِ بیمه‌نامه', 'روش_پرداخت_حق_بیمه' => 'ماهانه / سه‌ماهه / ...',
        'شناسه_واریز' => 'شناسه‌ی واریز', 'طرح' => 'طرح / واریانت', 'شماره_قسط' => 'شماره‌ی قسطِ انتخابی', 'سررسید' => 'سررسیدِ قسط', 'مبلغ_قسط' => 'مبلغِ قسط (ریال)',
        'مبلغ_پرداخت' => 'مبلغِ پرداخت‌شده (ریال)', 'مبلغ_پرداخت_حروف' => 'مبلغِ پرداخت به حروف', 'تاریخ_پرداخت' => 'تاریخِ پرداخت', 'روش_پرداخت' => 'کارت به کارت / ...',
        'شماره_پیگیری' => 'شماره‌ی پیگیری', 'مانده' => 'مانده‌ی همین قسط', 'مانده_کل' => 'مانده‌ی کلِ اقساط', 'جدول_اقساط' => 'جدولِ همه‌ی اقساط (کلِ پاراگراف با جدول جایگزین می‌شود)',
        'جدول_پرداخت' => 'جدولِ پرداخت‌های قسطِ انتخابی', 'متن' => 'متنِ زیرِ جدول (از تنظیماتِ همان قالب)', 'کاربر' => 'نامِ ثبت‌کننده',
    ];
}

function life_templates($pdo) {
    $out = [];
    foreach ($pdo->query("SELECT * FROM life_templates ORDER BY is_default DESC, id") as $t) {
        $out[] = ['id' => intval($t['id']), 'name' => $t['name'], 'builtin' => $t['file_path'] === null, 'footer_text' => (string)$t['footer_text'], 'is_default' => intval($t['is_default']),
                  'file' => $t['file_path'] ? basename($t['file_path']) : null];
    }
    return $out;
}
function life_template_row($pdo, $id) {
    if (intval($id) > 0) { $st = $pdo->prepare("SELECT * FROM life_templates WHERE id = ?"); $st->execute([intval($id)]); $t = $st->fetch(); if ($t) return $t; }
    return $pdo->query("SELECT * FROM life_templates ORDER BY is_default DESC, file_path IS NULL DESC, id LIMIT 1")->fetch() ?: null;
}

// داده‌ی رسید: بیمه‌نامه، قسطِ انتخابی، پرداخت (یا همه‌ی پرداخت‌های آن قسط) و همه‌ی اقساط
function life_receipt_data($pdo, $instId, $payId, $uid) {
    $st = $pdo->prepare("SELECT * FROM life_installments WHERE id = ?");
    $st->execute([intval($instId)]);
    $inst = $st->fetch();
    if (!$inst) return null;
    $d = life_detail($pdo, intval($inst['policy_id']));
    if (!$d) return null;
    $cur = null;
    foreach ($d['insts'] as $i) if ($i['id'] === intval($instId)) $cur = $i;
    $pays = array_values(array_filter($cur['payments'], function ($x) { return !$x['voided']; }));
    if ($payId) $pays = array_values(array_filter($pays, function ($x) use ($payId) { return $x['id'] === intval($payId); }));
    $paySum = array_sum(array_column($pays, 'amount'));
    if (!$pays && $cur['eff_paid'] > 0) $paySum = $cur['eff_paid'];   // پرداخت طبقِ اکسلِ بیمه‌گر یا تسویه
    $last = $pays ? $pays[count($pays) - 1] : null;
    return ['p' => $d['policy'], 'inst' => $cur, 'insts' => $d['insts'], 'pays' => $pays, 'pay_sum' => $paySum, 'last' => $last, 'sum' => $d['sum'],
            'no' => 'L' . intval($inst['policy_id']) . '-' . intval($cur['inst_no']) . ($last ? '-' . $last['id'] : ''), 'user' => life_user_name($pdo, $uid), 'today' => life_today_j()];
}
function life_receipt_vars(array $r, $footer) {
    $p = $r['p']; $i = $r['inst']; $l = $r['last'];
    $words = function_exists('fin_num_to_words') ? fin_num_to_words($r['pay_sum']) : '';
    return [
        'شماره_رسید' => $r['no'], 'تاریخ_امروز' => life_fa($r['today']), 'نام_بیمه_گذار' => $p['holder_name'], 'کد_ملی' => life_fa($p['holder_nid']), 'موبایل' => life_fa($p['holder_mobile']),
        'شماره_بیمه_نامه' => life_fa($p['policy_no']), 'بیمه_شده' => $p['insured_name'], 'تاریخ_صدور' => life_fa($p['issue_j']), 'روش_پرداخت_حق_بیمه' => $p['pay_method'],
        'شناسه_واریز' => life_fa($i['pay_id'] ?: $p['pay_id']), 'طرح' => trim($p['field_name'] . ' ' . $p['variant']), 'شماره_قسط' => life_fa($i['inst_no']), 'سررسید' => life_fa($i['due_j']),
        'مبلغ_قسط' => life_money($i['amount']), 'مبلغ_پرداخت' => life_money($r['pay_sum']), 'مبلغ_پرداخت_حروف' => $words ? $words . ' ریال' : '',
        'تاریخ_پرداخت' => $l ? life_fa($l['paid_j']) : '', 'روش_پرداخت' => $l ? $l['method'] : ($i['cleared'] ? 'تسویه نزدِ بیمه‌گر' : ($i['excel_collected'] ? 'وصول طبقِ گزارشِ بیمه‌گر' : '')),
        'شماره_پیگیری' => $l && $l['ref_no'] ? life_fa($l['ref_no']) : '', 'مانده' => life_money($i['rem']), 'مانده_کل' => life_money($r['sum']['rem']),
        'متن' => (string)$footer, 'کاربر' => $r['user'],
    ];
}

// جدولِ Word (راست‌به‌چپ، خطِ نازک، سطرِ انتخابی سایه‌دار)
function life_docx_table(array $headers, array $rows, array $widths, $hiRow = -1) {
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $b = '<w:top w:val="single" w:sz="4" w:color="64748B"/><w:left w:val="single" w:sz="4" w:color="64748B"/><w:bottom w:val="single" w:sz="4" w:color="64748B"/><w:right w:val="single" w:sz="4" w:color="64748B"/><w:insideH w:val="single" w:sz="4" w:color="94A3B8"/><w:insideV w:val="single" w:sz="4" w:color="94A3B8"/>';
    $x = '<w:tbl><w:tblPr><w:bidiVisual/><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:jc w:val="center"/><w:tblBorders>' . $b . '</w:tblBorders><w:tblCellMar><w:left w:w="60" w:type="dxa"/><w:right w:w="60" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
    foreach ($widths as $w) $x .= '<w:gridCol w:w="' . $w . '"/>';
    $x .= '</w:tblGrid>';
    $cell = function ($t, $w, $head, $fill) use ($esc) {
        $sz = $head ? 18 : 20;
        return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '<w:vAlign w:val="center"/></w:tcPr>'
             . '<w:p><w:pPr><w:bidi/><w:spacing w:before="40" w:after="40"/><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="' . ($head ? 'B Titr' : 'B Nazanin') . '" w:hint="cs"/>'
             . ($head ? '<w:b/><w:bCs/>' : '') . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/><w:rtl/></w:rPr><w:t xml:space="preserve">' . $esc($t) . '</w:t></w:r></w:p></w:tc>';
    };
    $x .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
    foreach ($headers as $k => $h) $x .= $cell($h, $widths[$k], true, 'E2E8F0');
    $x .= '</w:tr>';
    foreach ($rows as $ri => $row) {
        $x .= '<w:tr>';
        foreach ($row as $k => $v) $x .= $cell($v, $widths[$k], false, $ri === $hiRow ? 'DBEAFE' : '');
        $x .= '</w:tr>';
    }
    return $x . '</w:tbl>';
}
function life_receipt_tables(array $r) {
    $rows = []; $hi = -1;
    foreach ($r['insts'] as $k => $i) {
        if ($i['id'] === $r['inst']['id']) $hi = $k;
        $rows[] = [life_fa($i['inst_no']), life_fa($i['due_j']), life_money($i['amount']), life_money($i['eff_paid']), life_money($i['rem']), $i['status_fa']];
    }
    $t1 = life_docx_table(['قسط', 'سررسید', 'مبلغ قسط (ریال)', 'پرداختی (ریال)', 'مانده (ریال)', 'وضعیت'], $rows, [900, 1500, 1900, 1900, 1900, 1700], $hi);
    $prow = [];
    foreach ($r['pays'] as $x) $prow[] = [life_fa($x['paid_j']), life_money($x['amount']), $x['method'], life_fa($x['ref_no'] ?: '—'), $x['note'] ?: '—'];
    if (!$prow) $prow[] = ['—', life_money($r['pay_sum']), $r['inst']['cleared'] ? 'تسویه نزدِ بیمه‌گر' : 'طبقِ گزارشِ بیمه‌گر', '—', '—'];
    $t2 = life_docx_table(['تاریخ پرداخت', 'مبلغ (ریال)', 'روش', 'شماره پیگیری', 'توضیح'], $prow, [1600, 2000, 1900, 2100, 2200]);
    return [$t1, $t2];
}

// قالبِ پیش‌فرضِ سامانه به‌شکلِ docx (برای «دریافتِ قالبِ نمونه» و ساختِ رسید با قالبِ داخلی)
function life_builtin_docx($dest) {
    if (!class_exists('ZipArchive')) return false;
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $p = function ($text, $o = []) use ($esc) {
        $sz = $o['sz'] ?? 22; $font = $o['font'] ?? 'B Nazanin';
        return '<w:p><w:pPr><w:bidi/><w:spacing w:before="' . ($o['before'] ?? 0) . '" w:after="' . ($o['after'] ?? 60) . '"/><w:jc w:val="' . ($o['jc'] ?? 'right') . '"/>'
             . (!empty($o['box']) ? '<w:pBdr><w:top w:val="single" w:sz="6" w:color="2A78D6"/><w:bottom w:val="single" w:sz="6" w:color="2A78D6"/></w:pBdr>' : '') . '</w:pPr>'
             . ($text === '' ? '' : '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="' . $font . '" w:hint="cs"/>' . (!empty($o['b']) ? '<w:b/><w:bCs/>' : '')
             . (!empty($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '') . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/><w:rtl/></w:rPr><w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r>') . '</w:p>';
    };
    $body = $p('شماره‌ی رسید: {{شماره_رسید}}        تاریخ: {{تاریخ_امروز}}', ['jc' => 'left', 'sz' => 18, 'color' => '475569'])
          . $p('رسیدِ پرداختِ قسطِ بیمه‌نامه‌ی عمر', ['jc' => 'center', 'sz' => 32, 'b' => true, 'font' => 'B Titr', 'after' => 160])
          . $p('بیمه‌گذار: {{نام_بیمه_گذار}}    کد ملی: {{کد_ملی}}    موبایل: {{موبایل}}', ['sz' => 22, 'b' => true])
          . $p('شماره‌ی بیمه‌نامه: {{شماره_بیمه_نامه}}    تاریخ صدور: {{تاریخ_صدور}}    روش پرداخت: {{روش_پرداخت_حق_بیمه}}', ['sz' => 21])
          . $p('بیمه‌شده: {{بیمه_شده}}    شناسه‌ی واریز: {{شناسه_واریز}}', ['sz' => 21, 'after' => 160])
          . $p('اقساطِ بیمه‌نامه', ['sz' => 22, 'b' => true, 'font' => 'B Titr'])
          . $p('{{جدول_اقساط}}')
          . $p('{{متن}}', ['sz' => 21, 'before' => 160, 'after' => 200])
          . $p('فیشِ پرداختِ قسطِ {{شماره_قسط}}', ['sz' => 24, 'b' => true, 'font' => 'B Titr', 'box' => true, 'jc' => 'center'])
          . $p('سررسید: {{سررسید}}    مبلغِ قسط: {{مبلغ_قسط}} ریال    مانده: {{مانده}} ریال', ['sz' => 22])
          . $p('مبلغِ پرداخت‌شده: {{مبلغ_پرداخت}} ریال ({{مبلغ_پرداخت_حروف}})', ['sz' => 22, 'b' => true])
          . $p('تاریخِ پرداخت: {{تاریخ_پرداخت}}    روش: {{روش_پرداخت}}    شماره‌ی پیگیری: {{شماره_پیگیری}}', ['sz' => 22, 'after' => 120])
          . $p('{{جدول_پرداخت}}')
          . $p('ثبت‌کننده: {{کاربر}}', ['jc' => 'left', 'sz' => 20, 'before' => 300])
          . $p('مهر و امضا', ['jc' => 'left', 'sz' => 20, 'b' => true]);
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
         . $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000" w:header="600" w:footer="600" w:gutter="0"/><w:bidi/></w:sectPr></w:body></w:document>';
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
    $zip->addFromString('word/document.xml', $doc);
    $zip->close();
    return true;
}

// ساختِ docxِ رسید => مسیرِ فایل در پوشه‌ی همان قسط
function life_receipt_docx($pdo, array $r, array $tpl) {
    $dir = life_inst_dir(life_policy_row($pdo, $r['p']['id']), ['inst_no' => $r['inst']['inst_no'], 'due_j' => $r['inst']['due_j']]);
    if (!life_mkdir($dir)) return null;
    $src = $tpl['file_path'] ? life_root() . '/' . $tpl['file_path'] : null;
    if (!$src || !is_file($src)) { $src = life_tpl_dir() . '/_builtin.docx'; if (!life_builtin_docx($src)) return null; }
    $dest = $dir . '/' . sanitize_folder_name('رسید قسط ' . $r['inst']['inst_no'] . ' - ' . str_replace('/', '.', $r['today']) . ' - ' . date('His')) . '.docx';
    if (!@copy($src, $dest)) return null;
    $zip = new ZipArchive();
    if ($zip->open($dest) !== true) return null;
    $vars = life_receipt_vars($r, $tpl['footer_text']);
    [$t1, $t2] = life_receipt_tables($r);
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    foreach (fin_docx_parts($zip) as $part) {
        $xml = fin_docx_heal_codes((string)$zip->getFromName($part));
        if ($part === 'word/document.xml') {
            foreach (['{{جدول_اقساط}}' => $t1, '{{جدول_پرداخت}}' => $t2] as $code => $tbl) {
                if (strpos($xml, $code) === false) continue;
                $pat = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*' . preg_quote($code, '/') . '(?:(?!<\/w:p>).)*<\/w:p>/s';
                $xml = preg_match($pat, $xml) ? preg_replace($pat, str_replace(['\\', '$'], ['\\\\', '\$'], $tbl), $xml, 1) : str_replace($code, '', $xml);
            }
            // متنِ چندخطیِ زیرِ جدول: هر خط یک پاراگراف (با همان سبک)
            if (strpos($xml, '{{متن}}') !== false) {
                $lines = preg_split('/\r?\n/', (string)$vars['متن']);
                $xml = preg_replace_callback('/<w:p\b[^>]*>(?:(?!<\/w:p>).)*\{\{متن\}\}(?:(?!<\/w:p>).)*<\/w:p>/s', function ($m) use ($lines, $esc) {
                    $o = '';
                    foreach ($lines as $ln) $o .= str_replace('{{متن}}', $esc($ln), $m[0]);
                    return $o;
                }, $xml);
            }
        }
        foreach ($vars as $k => $v) $xml = str_replace('{{' . $k . '}}', $esc($v), $xml);
        $xml = str_replace(['{{جدول_اقساط}}', '{{جدول_پرداخت}}'], '', $xml);
        $zip->addFromString($part, $xml);
    }
    $zip->close();
    return $dest;
}

// نمایشِ HTMLِ چاپیِ رسید (برای قالبِ پیش‌فرض؛ همان ساختارِ docx)
function life_receipt_html(array $r, array $tpl) {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $v = life_receipt_vars($r, $tpl['footer_text']);
    $rows = '';
    foreach ($r['insts'] as $i) {
        $on = $i['id'] === $r['inst']['id'];
        $cls = ['PAID' => 'ok', 'PAID_US' => 'ok', 'PARTIAL' => 'part', 'OVERDUE' => 'bad', 'DUE' => 'due'][$i['status']];
        $rows .= '<tr' . ($on ? ' class="on"' : '') . '><td>' . life_fa($i['inst_no']) . ($on ? ' ◀' : '') . '</td><td>' . life_fa($i['due_j']) . '</td><td>' . life_money($i['amount']) . '</td><td>'
               . life_money($i['eff_paid']) . '</td><td>' . life_money($i['rem']) . '</td><td><span class="st ' . $cls . '">' . $h($i['status_fa']) . '</span></td></tr>';
    }
    $prow = '';
    foreach ($r['pays'] as $x) $prow .= '<tr><td>' . life_fa($x['paid_j']) . '</td><td>' . life_money($x['amount']) . '</td><td>' . $h($x['method']) . '</td><td>' . $h(life_fa($x['ref_no'] ?: '—')) . '</td><td>' . $h($x['note'] ?: '—') . '</td></tr>';
    if (!$prow) $prow = '<tr><td>—</td><td>' . life_money($r['pay_sum']) . '</td><td>' . $h($v['روش_پرداخت'] ?: '—') . '</td><td>—</td><td>—</td></tr>';
    $foot = nl2br($h($v['متن']));
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>رسید قسط ' . $h($v['شماره_قسط']) . ' - ' . $h($v['نام_بیمه_گذار']) . '</title>
<style>
@font-face{font-family:Vazir;src:url(../Font/Vazir-Regular.woff2) format("woff2");font-weight:400}
@font-face{font-family:Vazir;src:url(../Font/Vazir-Bold.woff2) format("woff2");font-weight:700}
*{box-sizing:border-box}body{font-family:Vazir,Vazirmatn,Tahoma,sans-serif;background:#eef2f7;margin:0;padding:24px 12px;color:#0f172a}
.pg{max-width:820px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 10px 40px -12px rgba(15,23,42,.25);padding:34px 36px}
.top{display:flex;justify-content:space-between;gap:12px;font-size:12px;color:#64748b}h1{font-size:22px;text-align:center;margin:14px 0 20px;color:#1e3a8a}
.info{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;font-size:13px}
.info b{color:#475569;font-weight:700;margin-left:6px}h2{font-size:14px;margin:20px 0 8px;color:#334155}
table{width:100%;border-collapse:collapse;font-size:12.5px}th,td{border:1px solid #cbd5e1;padding:7px 6px;text-align:center}th{background:#e2e8f0;font-weight:700}
tr.on td{background:#dbeafe;font-weight:700}.st{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}
.st.ok{background:#dcfce7;color:#166534}.st.part{background:#fef3c7;color:#92400e}.st.bad{background:#fee2e2;color:#991b1b}.st.due{background:#e0e7ff;color:#3730a3}
.foot{margin:16px 0;font-size:13px;line-height:2;color:#334155}.slip{border:2px dashed #2a78d6;border-radius:16px;padding:16px 18px;margin-top:18px}
.slip h3{margin:0 0 10px;text-align:center;color:#1d4ed8;font-size:16px}.slip .g{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px 14px;font-size:13px;margin-bottom:10px}
.big{font-size:15px;font-weight:700;color:#0f172a}.sign{display:flex;justify-content:space-between;margin-top:26px;font-size:12px;color:#475569}
.bar{max-width:820px;margin:0 auto 12px;display:flex;gap:8px;justify-content:flex-end}.bar button{font:inherit;font-size:13px;font-weight:700;border:0;border-radius:12px;padding:9px 16px;cursor:pointer;background:#1d4ed8;color:#fff}
.bar button.g{background:#fff;color:#334155;border:1px solid #cbd5e1}
@media(max-width:600px){.pg{padding:20px 14px;border-radius:14px}table{font-size:11px}th,td{padding:5px 3px}}
@media print{body{background:#fff;padding:0}.pg{box-shadow:none;border-radius:0;max-width:none}.bar{display:none}}
</style></head><body>
<div class="bar"><button class="g" onclick="window.close()">بستن</button><button onclick="window.print()">چاپ / ذخیره‌ی PDF</button></div>
<div class="pg"><div class="top"><span>شماره‌ی رسید: ' . $h($v['شماره_رسید']) . '</span><span>تاریخ: ' . $h($v['تاریخ_امروز']) . '</span></div>
<h1>رسیدِ پرداختِ قسطِ بیمه‌نامه‌ی عمر</h1>
<div class="info"><div><b>بیمه‌گذار:</b>' . $h($v['نام_بیمه_گذار']) . '</div><div><b>کد ملی:</b>' . $h($v['کد_ملی']) . '</div><div><b>موبایل:</b>' . $h($v['موبایل'] ?: '—') . '</div>
<div><b>شماره بیمه‌نامه:</b><bdi>' . $h($v['شماره_بیمه_نامه']) . '</bdi></div><div><b>تاریخ صدور:</b>' . $h($v['تاریخ_صدور']) . '</div><div><b>روش پرداخت:</b>' . $h($v['روش_پرداخت_حق_بیمه']) . '</div>
<div><b>بیمه‌شده:</b>' . $h($v['بیمه_شده'] ?: '—') . '</div><div><b>شناسه واریز:</b>' . $h($v['شناسه_واریز']) . '</div></div>
<h2>اقساطِ بیمه‌نامه</h2><table><thead><tr><th>قسط</th><th>سررسید</th><th>مبلغ قسط (ریال)</th><th>پرداختی (ریال)</th><th>مانده (ریال)</th><th>وضعیت</th></tr></thead><tbody>' . $rows . '</tbody></table>
' . ($foot !== '' ? '<div class="foot">' . $foot . '</div>' : '') . '
<div class="slip"><h3>فیشِ پرداختِ قسطِ ' . $h($v['شماره_قسط']) . '</h3><div class="g"><div><b>سررسید:</b> ' . $h($v['سررسید']) . '</div><div><b>مبلغ قسط:</b> ' . $h($v['مبلغ_قسط']) . ' ریال</div>
<div><b>مانده:</b> ' . $h($v['مانده']) . ' ریال</div><div><b>تاریخ پرداخت:</b> ' . $h($v['تاریخ_پرداخت'] ?: '—') . '</div><div><b>روش:</b> ' . $h($v['روش_پرداخت'] ?: '—') . '</div>
<div><b>شماره پیگیری:</b> ' . $h($v['شماره_پیگیری'] ?: '—') . '</div></div>
<div class="big">مبلغِ پرداخت‌شده: ' . $h($v['مبلغ_پرداخت']) . ' ریال' . ($v['مبلغ_پرداخت_حروف'] ? ' <small style="font-weight:400;color:#475569">(' . $h($v['مبلغ_پرداخت_حروف']) . ')</small>' : '') . '</div>
<h2 style="margin-top:12px">پرداخت‌ها</h2><table><thead><tr><th>تاریخ</th><th>مبلغ (ریال)</th><th>روش</th><th>شماره پیگیری</th><th>توضیح</th></tr></thead><tbody>' . $prow . '</tbody></table></div>
<div class="sign"><span>ثبت‌کننده: ' . $h($v['کاربر']) . '</span><span><b>مهر و امضا</b></span></div></div></body></html>';
}
