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
// $protectPassword: اگر داده شود، شیت قفل می‌شود (فقط خواندنی، مثل دفترِ گزارشاتِ برنامه‌ی ویندوزی)
function xlsx_build($headers, $rows, $sheetName = 'گزارش', $numericCols = [], $protectPassword = null) {
    if (!class_exists('ZipArchive')) return null;

    // مسیرِ فایل/پوشه روی سرور در هیچ خروجیِ اکسلی نمی‌آید (ستون‌هایی مثلِ «مسیر پوشه» / «آدرس فایل» حذف می‌شوند)
    $drop = [];
    foreach (array_values($headers) as $ci => $h) if (preg_match('/مسیر\s*(پوشه|فایل)|آدرس\s*(فایل|پوشه)|file\s*path|folder/iu', (string)$h)) $drop[] = $ci;
    if ($drop) {
        $keep = array_values(array_diff(array_keys(array_values($headers)), $drop));
        $headers = array_values(array_intersect_key(array_values($headers), array_flip($keep)));
        $rows = array_map(fn($r) => array_values(array_intersect_key(array_values($r), array_flip($keep))), $rows);
        $numericCols = array_values(array_filter(array_map(fn($c) => array_search($c, $keep, true), (array)$numericCols), fn($c) => $c !== false));
    }

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
        . ($protectPassword !== null ? '<sheetProtection' . ($protectPassword !== '' ? ' password="' . xlsx_legacy_password_hash($protectPassword) . '"' : '')
            . ' sheet="1" objects="1" scenarios="1" autoFilter="0" sort="0"/>' : '')
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

// هشِ ۱۶ بیتیِ قدیمیِ اکسل برای رمزِ قفلِ شیت (همان که اکسل برای sheetProtection password می‌خواهد)
function xlsx_legacy_password_hash($pwd) {
    $hash = 0;
    $chars = array_reverse(str_split((string)$pwd));
    foreach ($chars as $ch) {
        $hash = ((($hash >> 14) & 0x01) | (($hash << 1) & 0x7FFF)) ^ ord($ch);
    }
    $hash = ((($hash >> 14) & 0x01) | (($hash << 1) & 0x7FFF)) ^ strlen((string)$pwd) ^ 0xCE4B;
    return strtoupper(dechex($hash));
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

// ---------------------------------------------------------------------
//  کتابِ چندشیتی با سلول‌های ادغام‌شده، عددِ سه‌رقمی و سایه‌ی گروه‌ها (برای دفترهای ماه‌به‌ماه)
//  $sheets: [['name' => ..., 'headers' => [...], 'rows' => [[...]], 'numeric' => [ستون‌ها], 'merges' => [[ردیف۱, ستون۱, ردیف۲, ستون۲], ...] (ردیف‌های داده از ۰),
//             'shade' => [ردیف‌های داده‌ای که سایه بخورند], 'title' => عنوانِ بالای جدول (اختیاری)]]
// ---------------------------------------------------------------------
function xlsx_build_book(array $sheets) {
    if (!class_exists('ZipArchive') || !$sheets) return null;
    $font = 'B Nazanin';
    $L = function ($n) { $s = ''; for ($n = $n + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + (($n - 1) % 26)) . $s; return $s; };
    $sheetXml = [];
    foreach (array_values($sheets) as $si => $sh) {
        $headers = array_values($sh['headers']); $rows = array_values($sh['rows'] ?? []); $num = (array)($sh['numeric'] ?? []);
        $shade = array_flip((array)($sh['shade'] ?? [])); $off = !empty($sh['title']) ? 2 : 1;   // ردیفِ عنوان‌ها
        $w = [];
        foreach ($headers as $i => $h) $w[$i] = min(40, mb_strlen((string)$h) + 4);
        foreach ($rows as $r) foreach (array_values($r) as $i => $c) { $len = mb_strlen(is_int($c) ? number_format($c) : (string)$c) + 3; if (!isset($w[$i]) || $len > $w[$i]) $w[$i] = min($len, 45); }
        $cols = '<cols>'; foreach ($w as $i => $x) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . round(max(8, $x), 1) . '" customWidth="1"/>'; $cols .= '</cols>';
        $xml = '';
        if (!empty($sh['title'])) $xml .= '<row r="1" ht="28" customHeight="1"><c r="A1" s="4" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc($sh['title']) . '</t></is></c></row>';
        $xml .= '<row r="' . $off . '" ht="34" customHeight="1">';
        foreach ($headers as $i => $h) $xml .= '<c r="' . $L($i) . $off . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc($h) . '</t></is></c>';
        $xml .= '</row>';
        foreach ($rows as $ri => $r) {
            $rn = $ri + $off + 1; $sd = isset($shade[$ri]);
            $xml .= '<row r="' . $rn . '">';
            foreach (array_values($r) as $i => $v) {
                $ref = $L($i) . $rn;
                if ($v === null || $v === '') { $xml .= '<c r="' . $ref . '" s="' . ($sd ? 5 : 2) . '"/>'; continue; }
                if (in_array($i, $num, true) && xlsx_is_number($v)) $xml .= '<c r="' . $ref . '" s="' . ($sd ? 6 : 3) . '"><v>' . (0 + $v) . '</v></c>';
                else $xml .= '<c r="' . $ref . '" s="' . ($sd ? 5 : 2) . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $mg = [];
        if (!empty($sh['title'])) $mg[] = 'A1:' . $L(max(0, count($headers) - 1)) . '1';
        foreach ((array)($sh['merges'] ?? []) as $m) if ($m[2] > $m[0] || $m[3] > $m[1]) $mg[] = $L($m[1]) . ($m[0] + $off + 1) . ':' . $L($m[3]) . ($m[2] + $off + 1);
        $sheetXml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView rightToLeft="1"' . ($si === 0 ? ' tabSelected="1"' : '') . ' workbookViewId="0"><pane ySplit="' . $off . '" topLeftCell="A' . ($off + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/>' . $cols . '<sheetData>' . $xml . '</sheetData>'
            . ($mg ? '<mergeCells count="' . count($mg) . '">' . implode('', array_map(function ($x) { return '<mergeCell ref="' . $x . '"/>'; }, $mg)) . '</mergeCells>' : '')
            . '</worksheet>';
    }
    $al = '<alignment horizontal="center" vertical="center" wrapText="1" readingOrder="2"/>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
        . '<fonts count="4"><font><sz val="11"/><name val="' . $font . '"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="' . $font . '"/></font>'
        . '<font><sz val="11"/><name val="' . $font . '"/></font><font><b/><sz val="14"/><color rgb="FF065F46"/><name val="' . $font . '"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF047857"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF0FDF4"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFBFCFC8"/></left><right style="thin"><color rgb="FFBFCFC8"/></right>'
        . '<top style="thin"><color rgb="FFBFCFC8"/></top><bottom style="thin"><color rgb="FFBFCFC8"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $al . '</xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">' . $al . '</xf>'
        . '<xf numFmtId="164" fontId="2" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1">' . $al . '</xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">' . $al . '</xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $al . '</xf>'
        . '<xf numFmtId="164" fontId="2" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $al . '</xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $wbS = ''; $rels = ''; $ct = ''; $used = [];
    foreach (array_values($sheets) as $i => $sh) {
        $nm = mb_substr(trim(preg_replace('/[\\\\\/\?\*\[\]:]+/u', ' ', (string)$sh['name'])), 0, 30) ?: ('Sheet' . ($i + 1));
        while (isset($used[$nm])) $nm = mb_substr($nm, 0, 27) . ' ' . ($i + 1);
        $used[$nm] = 1;
        $wbS .= '<sheet name="' . xlsx_esc($nm) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $n = count($sheets);
    $path = sys_get_temp_dir() . '/' . uniqid('xlsx_') . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $ct
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbS . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
        . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', $styles);
    foreach ($sheetXml as $i => $x) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $x);
    $zip->close();
    return $path;
}

// خواندنِ همه‌ی شیت‌های یک xlsx/csv => [نامِ شیت => [ردیف => [ستون => مقدار]]] (ردیف‌های خالی حذف می‌شوند)
function xlsx_read_sheets($path, $ext = null) {
    $ext = strtolower($ext ?: pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $rows = [];
        if (($h = fopen($path, 'r')) !== false) {
            $first = fgets($h); rewind($h);
            if (substr((string)$first, 0, 3) === "\xEF\xBB\xBF") fseek($h, 3);
            while (($r = fgetcsv($h, 0, ',')) !== false) $rows[] = $r;
            fclose($h);
        }
        return ['CSV' => $rows];
    }
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) foreach ($m[1] as $si) { preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm); $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'); }
    $names = []; $relMap = [];
    $wb = (string)$zip->getFromName('xl/workbook.xml'); $rels = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
    if (preg_match_all('/<Relationship\b[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER)) foreach ($rm as $r) $relMap[$r[1]] = $r[2];
    if (preg_match_all('/<Relationship\b[^>]*Target="([^"]+)"[^>]*Id="([^"]+)"/', $rels, $rm, PREG_SET_ORDER)) foreach ($rm as $r) $relMap[$r[2]] = $relMap[$r[2]] ?? $r[1];
    if (preg_match_all('/<sheet\b[^>]*name="([^"]*)"[^>]*r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER)) foreach ($sm as $s) {
        $t = $relMap[$s[2]] ?? ''; if ($t === '') continue;
        $t = ltrim(str_replace('../', '', $t), '/'); if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
        $names[html_entity_decode($s[1], ENT_QUOTES | ENT_XML1, 'UTF-8')] = $t;
    }
    if (!$names) for ($i = 0; $i < $zip->numFiles; $i++) { $n = $zip->getNameIndex($i); if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) $names[basename($n, '.xml')] = $n; }
    $out = [];
    foreach ($names as $name => $file) {
        $xml = $zip->getFromName($file);
        if ($xml === false) continue;
        $rows = [];
        if (preg_match_all('/<row\b[^>]*?(?:\/>|>(.*?)<\/row>)/s', $xml, $rmx)) foreach ($rmx[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', (string)$rowXml, $cm, PREG_SET_ORDER)) foreach ($cm as $cell) {
                $attr = $cell[1]; $inner = $cell[2] ?? ''; $col = count($cells);
                if (preg_match('/r="([A-Z]+)\d+"/', $attr, $rr)) { $col = 0; for ($k = 0; $k < strlen($rr[1]); $k++) $col = $col * 26 + (ord($rr[1][$k]) - 64); $col--; }
                $val = '';
                if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) $val = $vm[1];
                elseif (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm2)) $val = implode('', $tm2[1]);
                $val = strpos($attr, 't="s"') !== false ? ($shared[intval($val)] ?? '') : html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (trim((string)$val) !== '') $cells[$col] = $val;   // سلول‌های خالیِ قالب‌دار (تا ستونِ XFD) حافظه را پر نکنند
            }
            if ($cells) { $mx = max(array_keys($cells)); $norm = []; for ($k = 0; $k <= $mx; $k++) $norm[$k] = $cells[$k] ?? ''; $rows[] = $norm; }
        }
        $out[$name] = $rows;
    }
    $zip->close();
    return $out;
}
