<?php
// فایل: api/_xlsx_writer.php
// نویسنده‌ی سبکِ فایل اکسل (xlsx) بدون هیچ کتابخانه‌ی بیرونی.
// xlsx در واقع یک فایل zip از چند XML است؛ همان‌طور که fin_read_spreadsheet برای
// *خواندن* مستقیم XML را باز می‌کند، اینجا هم برای *نوشتن* مستقیم XML ساخته می‌شود.
//
// طبق خواسته‌ی کارفرما: فونت همه‌ی سلول‌ها B Nazanin، عنوان‌ها بولد و بقیه عادی،
// و خودِ شیت راست‌به‌چپ.

function xlsx_esc($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// آیا این مقدار را به‌صورت عدد بنویسیم؟ (رشته‌هایی مثل شماره بیمه‌نامه یا کد ملی
// عمداً متن می‌مانند تا صفرِ ابتدایی‌شان نپرد و اکسل آن‌ها را تاریخ نکند)
function xlsx_is_number($v) {
    return is_int($v) || is_float($v) || (is_string($v) && $v !== '' && preg_match('/^-?\d{1,12}(\.\d+)?$/', $v));
}

// $headers: آرایه‌ی عنوان ستون‌ها
// $rows: آرایه‌ای از آرایه‌های هم‌طولِ عنوان‌ها
// $numericCols: اندیسِ ستون‌هایی که باید عدد باشند (برای جمع‌زدن در اکسل)
function xlsx_build($headers, $rows, $sheetName = 'گزارش', $numericCols = []) {
    if (!class_exists('ZipArchive')) return null;

    $fontName = 'B Nazanin';
    $colCount = count($headers);

    // ---- عرضِ ستون‌ها بر اساس بلندترین محتوا (تقریبی ولی به‌دردبخور) ----
    $widths = [];
    foreach ($headers as $i => $h) $widths[$i] = mb_strlen((string)$h) + 4;
    foreach ($rows as $r) {
        foreach (array_values($r) as $i => $c) {
            $len = mb_strlen((string)$c) + 3;
            if (!isset($widths[$i]) || $len > $widths[$i]) $widths[$i] = min($len, 55);
        }
    }
    $colsXml = '<cols>';
    foreach ($widths as $i => $w) $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . round($w, 1) . '" customWidth="1"/>';
    $colsXml .= '</cols>';

    // ---- سلول‌ها ----
    $colLetter = function ($n) {
        $s = '';
        for ($n = $n + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + (($n - 1) % 26)) . $s;
        return $s;
    };

    $sheetRows = '';
    // ردیفِ عنوان‌ها (استایل ۱ = بولد)
    $sheetRows .= '<row r="1" ht="22" customHeight="1">';
    foreach ($headers as $i => $h) {
        $sheetRows .= '<c r="' . $colLetter($i) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc($h) . '</t></is></c>';
    }
    $sheetRows .= '</row>';

    $rowNum = 1;
    foreach ($rows as $r) {
        $rowNum++;
        $sheetRows .= '<row r="' . $rowNum . '">';
        foreach (array_values($r) as $i => $v) {
            $ref = $colLetter($i) . $rowNum;
            if (in_array($i, $numericCols, true) && xlsx_is_number($v)) {
                $sheetRows .= '<c r="' . $ref . '" s="2"><v>' . (0 + $v) . '</v></c>';
            } else {
                $sheetRows .= '<c r="' . $ref . '" s="2" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc($v) . '</t></is></c>';
            }
        }
        $sheetRows .= '</row>';
    }

    $lastCell = $colLetter(max(0, $colCount - 1)) . max(1, $rowNum);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . $colsXml
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastCell . '"/>'
        . '</worksheet>';

    // ---- استایل‌ها: فونت B Nazanin برای همه، عنوان‌ها بولد ----
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="' . xlsx_esc($fontName) . '"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="' . xlsx_esc($fontName) . '"/></font>'
        . '<font><sz val="11"/><name val="' . xlsx_esc($fontName) . '"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right>'
        . '<top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        // عنوان: بولد، سفید روی آبی، وسط‌چین
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="center" vertical="center" wrapText="1" readingOrder="2"/></xf>'
        // مقدار: عادی، راست‌چین
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="right" vertical="center" readingOrder="2"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_esc(mb_substr($sheetName, 0, 28)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $path = sys_get_temp_dir() . '/' . uniqid('xlsx_') . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    return $path;
}

// فرستادنِ فایل ساخته‌شده به مرورگر و پاک‌کردنش
function xlsx_send($path, $downloadName) {
    if (!$path || !is_file($path)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'ساخت فایل اکسل ممکن نشد (ZipArchive در دسترس نیست).'], JSON_UNESCAPED_UNICODE);
        return;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    // اسمِ فارسی: filename* (RFC 5987) تا همه‌ی مرورگرها درست نشانش بدهند، و یک اسمِ لاتینِ پشتیبان
    $name = str_replace(['"', '/', '\\'], ['', '-', '-'], $downloadName);
    header("Content-Disposition: attachment; filename=\"report.xlsx\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
}
