<?php
// فایل: api/_pdf_tools.php
// ابزارهای PDF با PHP (جایگزینِ api/py/pdf_tools.py؛ همان ورودی و همان خروجیِ JSON)
//   pdftool_run($op, $outDir, array $params, array $files)
//   op: merge | extract | split | compress | conditions | keep_first | pages | rotate
//   «toimg» (عکسِ صفحه‌ها) و پیش‌نمایشِ صفحه‌ها در مرورگر (pdf.js) ساخته می‌شوند؛ این‌جا فقط پیامِ مناسب برمی‌گردد.
require_once __DIR__ . '/_pdf_text.php';

function pdftool_parse_ranges($spec, $n) {
    $spec = trim((string)$spec);
    $spec = strtr($spec, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '،' => ',', '–' => '-']);
    if ($spec === '') return range(0, $n - 1);
    $pages = [];
    foreach (explode(',', $spec) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (strpos($part, '-') !== false) {
            [$a, $b] = explode('-', $part, 2);
            $a = trim($a) !== '' ? (int)$a : 1;
            $b = trim($b) !== '' ? (int)$b : $n;
            if ($a > $b) [$a, $b] = [$b, $a];
            for ($p = $a - 1; $p < $b; $p++) $pages[] = $p;
        } else $pages[] = (int)$part - 1;
    }
    $pages = array_values(array_filter($pages, function ($p) use ($n) { return $p >= 0 && $p < $n; }));
    if (!$pages) throw new InvalidArgumentException('صفحه‌ای در این بازه نیست (فایل ' . $n . ' صفحه دارد).');
    return $pages;
}

// عکس (jpg/png/webp/…) => PDFِ یک‌صفحه‌ای (اندازه‌ی صفحه = اندازه‌ی عکس با ۷۲dpi، حداکثر A4 در ۱۵۰dpi)
function pdftool_image_to_pdf($img, $dest) {
    $info = @getimagesize($img);
    if (!$info) throw new RuntimeException('عکس خوانده نشد.');
    $src = $img;
    if (!in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        $gd = @imagecreatefromstring((string)file_get_contents($img));
        if (!$gd) throw new RuntimeException('این نوع عکس پشتیبانی نمی‌شود.');
        $src = $dest . '.jpg';
        imagejpeg($gd, $src, 90);
        imagedestroy($gd);
        $info = getimagesize($src);
    }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $w = $info[0] * 72 / 150; $h = $info[1] * 72 / 150;
    $pdf = new TCPDF($w > $h ? 'L' : 'P', 'pt', [$w, $h], true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); $pdf->SetMargins(0, 0, 0); $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddPage();
    $pdf->Image($src, 0, 0, $w, $h, '', '', '', false, 300, '', false, false, 0);
    $pdf->Output($dest, 'F');
    if ($src !== $img) @unlink($src);
}

// فایل‌های ورودی => PdfDoc (عکس‌ها اول PDF می‌شوند)
function pdftool_open_all(array $files, $outDir) {
    $docs = [];
    foreach ($files as $k => $f) {
        $h = (string)@file_get_contents($f, false, null, 0, 5);
        if ($h !== '%PDF-') {
            $tmp = $outDir . '/.img_' . $k . '.pdf';
            pdftool_image_to_pdf($f, $tmp);
            $f = $tmp;
        }
        $docs[] = PdfDoc::open($f);
    }
    return $docs;
}

function pdftool_run($op, $outDir, array $params, array $files) {
    $base = trim((string)($params['base'] ?? '')) ?: 'خروجی';
    if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
    $outDir = realpath($outDir) ?: $outDir;
    $files = array_map(function ($f) { return realpath($f) ?: $f; }, $files);
    @set_time_limit(300);
    try { $docs = pdftool_open_all($files, $outDir); }
    catch (Throwable $e) { return ['ok' => false, 'error' => strpos($e->getMessage(), 'رمز') !== false ? 'فایلِ رمزدار را نمی‌شود پردازش کرد؛ اول رمزش را بردارید.' : 'یکی از فایل‌ها PDFِ سالم نیست.', 'debug' => mb_substr($e->getMessage(), 0, 200)]; }
    try {
        switch ($op) {
            case 'merge': {
                $w = new PdfWriter();
                foreach ($docs as $d) for ($p = 0; $p < $d->pageCount(); $p++) $w->addPage($d, $p);
                $path = $outDir . '/out.pdf'; $w->save($path);
                return ['ok' => true, 'file' => 'out.pdf', 'name' => $base . ' (ادغام).pdf', 'pages' => $w->count(), 'size' => filesize($path)];
            }
            case 'extract': {
                $d = $docs[0];
                $pages = pdftool_parse_ranges($params['pages'] ?? '', $d->pageCount());
                $w = new PdfWriter(); foreach ($pages as $p) $w->addPage($d, $p);
                $path = $outDir . '/out.pdf'; $w->save($path);
                return ['ok' => true, 'file' => 'out.pdf', 'name' => $base . ' (صفحه‌های انتخابی).pdf', 'pages' => $w->count(), 'size' => filesize($path)];
            }
            case 'split': {
                $d = $docs[0];
                $pages = pdftool_parse_ranges($params['pages'] ?? '', $d->pageCount());
                $path = $outDir . '/out.zip';
                $z = new ZipArchive();
                if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('ساختِ ZIP ممکن نشد.');
                foreach ($pages as $p) { $w = new PdfWriter(); $w->addPage($d, $p); $z->addFromString($base . ' - صفحه ' . ($p + 1) . '.pdf', $w->output()); }
                $z->close();
                return ['ok' => true, 'file' => 'out.zip', 'name' => $base . ' (صفحه‌به‌صفحه).zip', 'pages' => count($pages), 'size' => filesize($path)];
            }
            case 'compress': {
                $d = $docs[0];
                $before = filesize($files[0]);
                $q = max(20, min(95, (int)($params['quality'] ?? 60) ?: 60));
                $dpi = max(50, min(300, (int)($params['dpi'] ?? 110) ?: 110));
                $w = new PdfWriter();
                $w->imageQuality = $q; $w->imageMaxSide = max(600, (int)round(1200 * $dpi / 110));
                for ($p = 0; $p < $d->pageCount(); $p++) $w->addPage($d, $p);
                $path = $outDir . '/out.pdf'; $w->save($path);
                $after = filesize($path);
                if ($after >= $before) { copy($files[0], $path); $after = $before; }
                return ['ok' => true, 'file' => 'out.pdf', 'name' => $base . ' (کم‌حجم).pdf', 'pages' => $d->pageCount(), 'size' => $after, 'before' => $before];
            }
            case 'toimg':
                return ['ok' => false, 'error' => 'تبدیلِ صفحه به عکس در مرورگر انجام می‌شود؛ صفحه را یک بار تازه کنید.'];
            case 'conditions': {
                $d = $docs[0];
                $n = $d->pageCount();
                if ($n < 2) return ['ok' => false, 'error' => 'این بیمه‌نامه فقط یک صفحه است و صفحه‌ی شرایط ندارد.', 'pages' => $n];
                $pt = new PdfText($d);
                $head = function ($p) use ($pt) {
                    try { $t = pdft_nfkc($pt->text($p)); } catch (Throwable $e) { $t = ''; }
                    $t = strtr($t, ["\u{064A}" => "\u{06CC}", "\u{0643}" => "\u{06A9}", "\u{200C}" => ' ']);
                    return mb_substr((string)preg_replace('~\s+~u', '', $t), 0, 60);
                };
                $h1 = $head(0);
                $start = 1;
                if (mb_strlen($h1) >= 20) while ($start < $n - 1 && $head($start) === $h1) $start++;
                $w = new PdfWriter(); for ($p = $start; $p < $n; $p++) $w->addPage($d, $p);
                $path = $outDir . '/out.pdf'; $w->save($path);
                return ['ok' => true, 'file' => 'out.pdf', 'pages' => $w->count(), 'from' => $start + 1, 'total' => $n, 'detected' => $start > 1];
            }
            case 'keep_first': {
                $d = $docs[0];
                $n = $d->pageCount();
                $k = max(1, min(50, (int)($params['n'] ?? 1) ?: 1));
                if ($n <= $k) return ['ok' => true, 'total' => $n, 'kept' => $n, 'rest' => 0];
                $w = new PdfWriter(); for ($p = 0; $p < $k; $p++) $w->addPage($d, $p); $w->save($outDir . '/head.pdf');
                $w = new PdfWriter(); for ($p = $k; $p < $n; $p++) $w->addPage($d, $p); $w->save($outDir . '/rest.pdf');
                return ['ok' => true, 'total' => $n, 'kept' => $k, 'rest' => $n - $k];
            }
            case 'pages': {
                $d = $docs[0];
                $n = $d->pageCount();
                $mx = max(1, min(200, (int)($params['max'] ?? 60) ?: 60));
                if ($n > $mx) return ['ok' => false, 'error' => 'فایل ' . $n . ' صفحه دارد؛ بیشتر از ' . $mx . ' صفحه جدا نمی‌شود.', 'pages' => $n];
                $out = [];
                if (empty($params['count_only']) && ($n > 1 || !empty($params['force']))) {
                    for ($p = 0; $p < $n; $p++) { $w = new PdfWriter(); $w->addPage($d, $p); $name = sprintf('p%03d.pdf', $p + 1); $w->save($outDir . '/' . $name); $out[] = $name; }
                }
                return ['ok' => true, 'pages' => $n, 'files' => $out, 'thumbs' => []];
            }
            case 'rotate': {
                $d = $docs[0];
                $pages = pdftool_parse_ranges($params['pages'] ?? '', $d->pageCount());
                $ang = (int)($params['angle'] ?? 90);
                if (!in_array($ang, [90, 180, 270], true)) $ang = 90;
                $sel = array_flip($pages);
                $w = new PdfWriter(); for ($p = 0; $p < $d->pageCount(); $p++) $w->addPage($d, $p, isset($sel[$p]) ? $ang : 0);
                $path = $outDir . '/out.pdf'; $w->save($path);
                return ['ok' => true, 'file' => 'out.pdf', 'name' => $base . ' (چرخیده).pdf', 'pages' => $d->pageCount(), 'size' => filesize($path)];
            }
        }
        return ['ok' => false, 'error' => 'عملیاتِ نامعتبر.'];
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'پردازشِ PDF ممکن نشد.', 'debug' => mb_substr($e->getMessage(), 0, 300)];
    }
}
