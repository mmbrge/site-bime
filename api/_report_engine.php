<?php
// فایل: api/_report_engine.php
// موتورِ ساختِ گزارش بازدید، کاملاً روی خودِ هاست (بدون Word، بدون LibreOffice، بدون ویندوز).
//
// قالب‌های گزارش (مثل قالب‌های پاسارگاد و پارسیان) در Word این‌طور ساخته شده‌اند: یک «عکسِ کاملِ
// فرم» پشت صفحه، و مقدارها داخل کادرهای متنیِ شناور که دقیقاً روی خانه‌های فرم نشسته‌اند.
// پس به‌جای تبدیلِ Word به PDF (که بدون خودِ Word درست درنمی‌آید)، هندسه‌ی همان فایل Word را
// می‌خوانیم و PDF را خودمان می‌سازیم:
//   ۱) rpt_analyze_docx  : فایل Word را باز می‌کند و «نقشه‌ی قالب» (layout) می‌سازد: اندازه‌ی صفحه،
//                          عکس‌ها (با جای دقیق)، کادرهای متنی (جا، اندازه‌ی قلم، رنگ، ترازبندی، متن
//                          همراه با {{ متغیرها }}) و گروه‌ها. عکس‌ها در پوشه‌ی دارایی‌های قالب ذخیره می‌شوند.
//   ۲) rpt_render_pdf    : با TCPDF (کتابخانه‌ی PHP، کنار سایت در lib/tcpdf) عکسِ فرم را می‌گذارد و
//                          متن‌ها را با قلم وزیر، راست‌به‌چپ و با شکلِ درستِ حروف فارسی روی آن می‌نویسد.
//   ۳) rpt_fill_docx     : نسخه‌ی Word پرشده را هم (مثل برنامه‌ی ویندوزی) می‌سازد.
// نقشه‌ی قالب قابل تنظیمِ دستی هم هست (جابه‌جاییِ هر کادر - offsets) تا اگر جایی چند میلی‌متر
// جابه‌جا بود، از صفحه‌ی تنظیمات اصلاح شود.

const RPT_EMU_PT = 12700.0;
const RPT_NS = [
    'w'   => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
    'wp'  => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
    'a'   => 'http://schemas.openxmlformats.org/drawingml/2006/main',
    'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
    'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
    'wpg' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingGroup',
    'mc'  => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
    'r'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    'v'   => 'urn:schemas-microsoft-com:vml',
];

// ---------------------------------------------------------------------
//  کمکی‌های عمومی
// ---------------------------------------------------------------------

// Word گاهی {{ name }} را بین چند تکه (run) می‌شکند؛ همان کاری که docxtpl می‌کند: تکه‌ها را
// داخلِ آکولادها به‌هم می‌چسبانیم تا هر متغیر یک‌جا بماند (ساختار XML سالم می‌ماند).
function rpt_merge_placeholders($xml) {
    $xml = preg_replace('/(?<=\{)(<[^>]*>)+(?=\{)|(?<=\})(<[^>]*>)+(?=\})/', '', $xml);
    return preg_replace_callback('/\{\{(?:(?!\}\}).)*/s', fn($m) => preg_replace('/<[^>]*>/', '', $m[0]), $xml);
}

// فهرستِ همه‌ی متغیرهای {{ ... }} یک قالب Word
function rpt_template_vars($docxPath) {
    $z = new ZipArchive();
    if ($z->open($docxPath) !== true) return [];
    $vars = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (!preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $n)) continue;
        $xml = rpt_merge_placeholders($z->getFromIndex($i));
        $txt = preg_replace('/<[^>]+>/', '', $xml);
        if (preg_match_all('/\{\{\s*([A-Za-z0-9_\x{0600}-\x{06FF}]+)\s*\}\}/u', $txt, $m)) $vars = array_merge($vars, $m[1]);
    }
    $z->close();
    return array_values(array_unique($vars));
}

// ---------------------------------------------------------------------
//  ۱) خواندنِ هندسه‌ی قالب Word
// ---------------------------------------------------------------------
function rpt_analyze_docx($docxPath, $assetDir) {
    $z = new ZipArchive();
    if ($z->open($docxPath) !== true) throw new RuntimeException('فایل Word باز نشد (خراب است یا docx نیست).');
    $docXml = $z->getFromName('word/document.xml');
    if ($docXml === false) throw new RuntimeException('فایل Word ساختار درستی ندارد (document.xml پیدا نشد).');
    $relsXml = (string)$z->getFromName('word/_rels/document.xml.rels');
    $rels = [];
    if (preg_match_all('/<Relationship\b[^>]*>/', $relsXml, $mm)) {
        foreach ($mm[0] as $tag) {
            if (preg_match('/Id="([^"]+)"/', $tag, $a) && preg_match('/Target="([^"]+)"/', $tag, $b)) $rels[$a[1]] = $b[1];
        }
    }
    $defaults = ['size' => 11.0, 'font' => ''];
    $styles = (string)$z->getFromName('word/styles.xml');
    if ($styles && preg_match('/<w:rPrDefault>.*?<\/w:rPrDefault>/s', $styles, $rd)) {
        if (preg_match('/<w:sz w:val="(\d+)"/', $rd[0], $s)) $defaults['size'] = intval($s[1]) / 2;
        if (preg_match('/<w:rFonts [^>]*w:cs="([^"]+)"/', $rd[0], $f) || preg_match('/<w:rFonts [^>]*w:ascii="([^"]+)"/', $rd[0], $f)) $defaults['font'] = $f[1];
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML(rpt_merge_placeholders($docXml), LIBXML_PARSEHUGE)) throw new RuntimeException('XML قالب Word خوانده نشد.');
    $xp = new DOMXPath($dom);
    foreach (RPT_NS as $p => $u) $xp->registerNamespace($p, $u);

    // اندازه و حاشیه‌ی صفحه (آخرین sectPr)
    $sect = $xp->query('//w:body/w:sectPr')->item(0) ?: $xp->query('//w:sectPr')->item(0);
    $W = 595.3; $H = 841.9; $mar = ['l' => 72, 't' => 72, 'r' => 72, 'b' => 72];
    if ($sect) {
        $pg = $xp->query('w:pgSz', $sect)->item(0);
        if ($pg) { $W = intval($pg->getAttributeNS(RPT_NS['w'], 'w')) / 20; $H = intval($pg->getAttributeNS(RPT_NS['w'], 'h')) / 20; }
        $pm = $xp->query('w:pgMar', $sect)->item(0);
        if ($pm) foreach (['l' => 'left', 't' => 'top', 'r' => 'right', 'b' => 'bottom'] as $k => $a) $mar[$k] = intval($pm->getAttributeNS(RPT_NS['w'], $a)) / 20;
    }

    $ctx = (object)['page' => 0, 'justBroke' => true, 'items' => [], 'warnings' => [], 'xp' => $xp, 'rels' => $rels, 'zip' => $z,
                    'assetDir' => $assetDir, 'W' => $W, 'H' => $H, 'mar' => $mar, 'defaults' => $defaults, 'savedImages' => []];
    if (!is_dir($assetDir)) @mkdir($assetDir, 0775, true);
    rpt_walk_body($xp->query('//w:body')->item(0), $ctx);
    $z->close();

    $pages = 1;
    foreach ($ctx->items as $it) $pages = max($pages, $it['page'] + 1);
    usort($ctx->items, fn($a, $b) => [$a['page'], $a['behind'] ? 0 : 1, $a['z']] <=> [$b['page'], $b['behind'] ? 0 : 1, $b['z']]);
    foreach ($ctx->items as $i => &$it) $it['id'] = 'i' . ($i + 1);
    unset($it);
    return ['version' => 2, 'page' => ['w' => round($W, 2), 'h' => round($H, 2), 'margins' => $mar], 'pages' => $pages,
            'defaults' => $defaults, 'items' => $ctx->items, 'warnings' => array_values(array_unique($ctx->warnings))];
}

// پیمایشِ بدنه به ترتیبِ سند، با شمارشِ صفحه‌ها (شکستِ صفحه‌ی دستی یا شکستی که Word ثبت کرده)
function rpt_walk_body($node, $ctx) {
    foreach ($node->childNodes as $ch) {
        if (!($ch instanceof DOMElement)) continue;
        $ns = $ch->namespaceURI; $ln = $ch->localName;
        if ($ns === RPT_NS['mc'] && $ln === 'Fallback') continue;           // نسخه‌ی قدیمیِ VML همان شکل - تکراری است
        if ($ns === RPT_NS['w'] && $ln === 'txbxContent') continue;         // متنِ داخلِ کادرها جدا خوانده می‌شود
        if ($ns === RPT_NS['w'] && $ln === 'p') {
            $ppr = $ctx->xp->query('w:pPr/w:pageBreakBefore', $ch)->item(0);
            if ($ppr && !$ctx->justBroke) { $ctx->page++; $ctx->justBroke = true; }
        }
        if ($ns === RPT_NS['w'] && $ln === 'br' && $ch->getAttributeNS(RPT_NS['w'], 'type') === 'page') { $ctx->page++; $ctx->justBroke = true; continue; }
        if ($ns === RPT_NS['w'] && $ln === 'lastRenderedPageBreak') { if (!$ctx->justBroke) { $ctx->page++; $ctx->justBroke = true; } continue; }
        if ($ns === RPT_NS['w'] && $ln === 't' && trim($ch->textContent) !== '') $ctx->justBroke = false;
        if ($ns === RPT_NS['wp'] && $ln === 'anchor') { rpt_anchor($ch, $ctx); $ctx->justBroke = false; continue; }
        if ($ns === RPT_NS['wp'] && $ln === 'inline') {
            // تصویرِ داخلِ متنِ اصلیِ صفحه (نه شناور) - تقریبی در گوشه‌ی بالای حاشیه
            rpt_inline_in_body($ch, $ctx);
            continue;
        }
        // پایانِ یک بخش با «صفحه‌ی بعد»
        if ($ns === RPT_NS['w'] && $ln === 'sectPr' && $ch->parentNode && $ch->parentNode->localName === 'pPr') {
            rpt_walk_body($ch, $ctx);
            $type = $ctx->xp->query('w:type', $ch)->item(0);
            if (!$type || in_array($type->getAttributeNS(RPT_NS['w'], 'val'), ['nextPage', 'oddPage', 'evenPage'], true)) { $ctx->page++; $ctx->justBroke = true; }
            continue;
        }
        rpt_walk_body($ch, $ctx);
    }
}

function rpt_emu($v) { return intval($v) / RPT_EMU_PT; }

function rpt_anchor(DOMElement $a, $ctx) {
    $xp = $ctx->xp;
    $ext = $xp->query('wp:extent', $a)->item(0);
    if (!$ext) return;
    $w = rpt_emu($ext->getAttribute('cx')); $h = rpt_emu($ext->getAttribute('cy'));
    $x = rpt_axis_pos($xp->query('wp:positionH', $a)->item(0), $w, $ctx, 'h');
    $y = rpt_axis_pos($xp->query('wp:positionV', $a)->item(0), $h, $ctx, 'v');
    $z = intval($a->getAttribute('relativeHeight'));
    $behind = $a->getAttribute('behindDoc') === '1';
    $gd = $xp->query('a:graphic/a:graphicData', $a)->item(0);
    if (!$gd) return;
    $base = ['page' => $ctx->page, 'z' => $z, 'behind' => $behind];
    foreach ($gd->childNodes as $g) {
        if (!($g instanceof DOMElement)) continue;
        if ($g->namespaceURI === RPT_NS['wps'] && $g->localName === 'wsp') rpt_shape($g, $x, $y, $w, $h, $base, $ctx);
        elseif ($g->namespaceURI === RPT_NS['wpg'] && $g->localName === 'wgp') rpt_group($g, $x, $y, $w, $h, $base, $ctx);
        elseif ($g->namespaceURI === RPT_NS['pic'] && $g->localName === 'pic') rpt_picture($g, $x, $y, $w, $h, $base, $ctx);
    }
}

// جایگاهِ افقی/عمودیِ یک شکلِ شناور نسبت به مرجعش (صفحه، حاشیه، ستون، پاراگراف)
function rpt_axis_pos($node, $size, $ctx, $axis) {
    $W = $ctx->W; $H = $ctx->H; $m = $ctx->mar;
    if (!$node) return $axis === 'h' ? $m['l'] : $m['t'];
    $rel = $node->getAttribute('relativeFrom');
    if ($axis === 'h') {
        // (بدون match تا روی PHP 7.4 هاست هم اجرا شود)
        switch ($rel) {
            case 'page': $ref = [0, $W]; break;
            case 'leftMargin': case 'insideMargin': $ref = [0, $m['l']]; break;
            case 'rightMargin': case 'outsideMargin': $ref = [$W - $m['r'], $W]; break;
            default: $ref = [$m['l'], $W - $m['r']];   // margin, column, character
        }
    } else {
        switch ($rel) {
            case 'page': $ref = [0, $H]; break;
            case 'topMargin': $ref = [0, $m['t']]; break;
            case 'bottomMargin': $ref = [$H - $m['b'], $H]; break;
            default: $ref = [$m['t'], $H - $m['b']];   // margin, paragraph, line (فرض: پاراگرافِ لنگر بالای صفحه است)
        }
    }
    $off = null; $align = null;
    foreach ($node->childNodes as $c) {
        if (!($c instanceof DOMElement)) continue;
        if ($c->localName === 'posOffset') $off = rpt_emu(trim($c->textContent));
        if ($c->localName === 'align') $align = trim($c->textContent);
    }
    if ($off !== null) return $ref[0] + $off;
    if (in_array($align, ['right', 'bottom', 'outside'], true)) return $ref[1] - $size;
    if ($align === 'center') return ($ref[0] + $ref[1] - $size) / 2;
    return $ref[0];
}

function rpt_inline_in_body(DOMElement $in, $ctx) {
    $xp = $ctx->xp;
    $ext = $xp->query('wp:extent', $in)->item(0);
    $pic = $xp->query('a:graphic/a:graphicData/pic:pic', $in)->item(0);
    if (!$ext || !$pic) return;
    $w = rpt_emu($ext->getAttribute('cx')); $h = rpt_emu($ext->getAttribute('cy'));
    rpt_picture($pic, $ctx->mar['l'], $ctx->mar['t'], $w, $h, ['page' => $ctx->page, 'z' => 0, 'behind' => true], $ctx);
}

// رنگِ srgbClr یک گره (fill یا ln)
function rpt_color($node, $ctx) {
    if (!$node) return null;
    $c = $ctx->xp->query('.//a:srgbClr', $node)->item(0);
    if ($c) return '#' . strtoupper($c->getAttribute('val'));
    $s = $ctx->xp->query('.//a:schemeClr', $node)->item(0);
    if ($s) return ['bg1' => '#FFFFFF', 'lt1' => '#FFFFFF', 'tx1' => '#000000', 'dk1' => '#000000'][$s->getAttribute('val')] ?? '#4472C4';
    return null;
}

function rpt_shape(DOMElement $sp, $x, $y, $w, $h, $base, $ctx, $rotDeg = 0) {
    $xp = $ctx->xp;
    $spPr = $xp->query('wps:spPr', $sp)->item(0);
    $fill = null; $line = null; $lineW = 0; $geom = 'rect';
    if ($spPr) {
        $pg = $xp->query('a:prstGeom', $spPr)->item(0);
        if ($pg && $pg->getAttribute('prst')) $geom = $pg->getAttribute('prst');
        $xfrm = $xp->query('a:xfrm', $spPr)->item(0);
        if ($xfrm && $xfrm->getAttribute('rot')) $rotDeg += intval($xfrm->getAttribute('rot')) / 60000;
        $sf = $xp->query('a:solidFill', $spPr)->item(0);
        if ($sf) $fill = rpt_color($sf, $ctx);
        $ln = $xp->query('a:ln', $spPr)->item(0);
        if ($ln && !$xp->query('a:noFill', $ln)->item(0)) {
            $lf = $xp->query('a:solidFill', $ln)->item(0);
            if ($lf) { $line = rpt_color($lf, $ctx); $lineW = $ln->getAttribute('w') ? rpt_emu($ln->getAttribute('w')) : 0.75; }
        }
    }
    $body = $xp->query('wps:bodyPr', $sp)->item(0);
    $ins = [7.2, 3.6, 7.2, 3.6];
    $anchor = 't'; $vert = 'horz';
    if ($body) {
        foreach (['lIns' => 0, 'tIns' => 1, 'rIns' => 2, 'bIns' => 3] as $k => $i) if ($body->hasAttribute($k)) $ins[$i] = rpt_emu($body->getAttribute($k));
        if ($body->getAttribute('anchor')) $anchor = $body->getAttribute('anchor');
        if ($body->getAttribute('vert')) $vert = $body->getAttribute('vert');
    }
    $txbx = $xp->query('wps:txbx/w:txbxContent', $sp)->item(0);
    $paras = [];
    if ($txbx) {
        $cursor = $y + $ins[1];
        foreach ($xp->query('w:p', $txbx) as $p) {
            $para = rpt_paragraph($p, $ctx);
            // تصویرهای داخلِ متنِ کادر (مثلاً عکسِ کاملِ فرم که داخل یک کادر گذاشته شده)
            $pH = 0;
            foreach ($xp->query('.//wp:inline', $p) as $in) {
                $ext = $xp->query('wp:extent', $in)->item(0);
                $pic = $xp->query('a:graphic/a:graphicData/pic:pic', $in)->item(0);
                if (!$ext || !$pic) continue;
                $iw = rpt_emu($ext->getAttribute('cx')); $ih = rpt_emu($ext->getAttribute('cy'));
                rpt_picture($pic, $x + $ins[0], $cursor, $iw, $ih, ['page' => $base['page'], 'z' => $base['z'] - 1, 'behind' => $base['behind']], $ctx);
                $pH = max($pH, $ih);
            }
            // شکل‌های شناورِ داخلِ کادر (نسبت به خودِ کادر)
            foreach ($xp->query('.//wp:anchor', $p) as $an) {
                $sub = clone $ctx; $sub->mar = ['l' => $x + $ins[0], 't' => $y + $ins[1], 'r' => $ctx->W - ($x + $w - $ins[2]), 'b' => $ctx->H - ($y + $h - $ins[3])];
                $sub->items = &$ctx->items;
                rpt_anchor($an, $sub);
            }
            $cursor += max($pH, ($para['size'] ?? $ctx->defaults['size']) * 1.2);
            if ($para['text'] !== '' || $para['has_vars']) $paras[] = $para;
        }
    }
    if (!$paras && !$fill && !$line) return;
    $ctx->items[] = $base + ['type' => 'box', 'x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2),
        'rot' => round($rotDeg, 2), 'vert' => $vert, 'ins' => array_map(fn($v) => round($v, 2), $ins), 'anchor' => $anchor,
        'fill' => $fill, 'line' => $line, 'lineW' => round($lineW, 2), 'geom' => $geom, 'paras' => $paras, 'dx' => 0, 'dy' => 0];
}

// یک پاراگراف: ترازبندی، راست‌به‌چپ بودن و تکه‌ها (با اندازه، ضخامت و رنگِ قلم)
function rpt_paragraph(DOMElement $p, $ctx) {
    $xp = $ctx->xp;
    $jc = $xp->query('w:pPr/w:jc', $p)->item(0);
    $bidi = $xp->query('w:pPr/w:bidi', $p)->item(0);
    $isBidi = $bidi && $bidi->getAttributeNS(RPT_NS['w'], 'val') !== '0';
    $markSz = $xp->query('w:pPr/w:rPr/w:sz', $p)->item(0);
    $runs = []; $text = ''; $maxSize = 0;
    foreach ($xp->query('w:r | w:hyperlink/w:r | w:ins/w:r | w:smartTag/w:r', $p) as $r) {
        // تکه‌هایی که داخلِ یک شکلِ دیگرند (کادرِ تو در تو) را این‌جا حساب نکن
        $anc = $r->parentNode; $nested = false;
        while ($anc && $anc !== $p) { if ($anc->localName === 'txbxContent') { $nested = true; break; } $anc = $anc->parentNode; }
        if ($nested) continue;
        $rPr = $xp->query('w:rPr', $r)->item(0);
        $size = null; $bold = false; $color = null; $font = null; $vanish = false;
        if ($rPr) {
            $sz = $xp->query('w:szCs', $rPr)->item(0) ?: $xp->query('w:sz', $rPr)->item(0);
            if ($sz) $size = intval($sz->getAttributeNS(RPT_NS['w'], 'val')) / 2;
            $b = $xp->query('w:b | w:bCs', $rPr)->item(0);
            if ($b) $bold = $b->getAttributeNS(RPT_NS['w'], 'val') !== '0' && $b->getAttributeNS(RPT_NS['w'], 'val') !== 'false';
            $c = $xp->query('w:color', $rPr)->item(0);
            if ($c && $c->getAttributeNS(RPT_NS['w'], 'val') !== 'auto') $color = '#' . strtoupper($c->getAttributeNS(RPT_NS['w'], 'val'));
            $f = $xp->query('w:rFonts', $rPr)->item(0);
            if ($f) $font = $f->getAttributeNS(RPT_NS['w'], 'cs') ?: $f->getAttributeNS(RPT_NS['w'], 'ascii') ?: null;
            if ($xp->query('w:vanish', $rPr)->item(0)) $vanish = true;
        }
        if ($vanish) continue;
        $t = '';
        foreach ($r->childNodes as $c) {
            if (!($c instanceof DOMElement)) continue;
            if ($c->localName === 't') $t .= $c->textContent;
            elseif ($c->localName === 'tab') $t .= '    ';
            elseif ($c->localName === 'br') $t .= "\n";
            elseif ($c->localName === 'sym') {
                $code = strtoupper($c->getAttributeNS(RPT_NS['w'], 'char'));
                $t .= in_array($code, ['F0FC', 'F0FE', 'F052', 'F050'], true) ? '✔' : '';
            }
        }
        if ($t === '') continue;
        $runs[] = ['t' => $t, 'size' => $size, 'b' => $bold, 'color' => $color, 'font' => $font];
        $text .= $t;
        if ($size) $maxSize = max($maxSize, $size);
    }
    if (!$maxSize && $markSz) $maxSize = intval($markSz->getAttributeNS(RPT_NS['w'], 'val')) / 2;
    $align = $jc ? $jc->getAttributeNS(RPT_NS['w'], 'val') : '';
    return ['align' => $align, 'bidi' => $isBidi, 'runs' => $runs, 'text' => trim($text) === '' ? '' : $text,
            'has_vars' => strpos($text, '{{') !== false, 'size' => $maxSize ?: null];
}

function rpt_group(DOMElement $g, $x, $y, $w, $h, $base, $ctx) {
    $xp = $ctx->xp;
    $xf = $xp->query('wpg:grpSpPr/a:xfrm', $g)->item(0);
    $off = [0, 0]; $ext = [1, 1]; $chOff = [0, 0]; $chExt = [1, 1];
    if ($xf) {
        $o = $xp->query('a:off', $xf)->item(0); $e = $xp->query('a:ext', $xf)->item(0);
        $co = $xp->query('a:chOff', $xf)->item(0); $ce = $xp->query('a:chExt', $xf)->item(0);
        if ($o) $off = [intval($o->getAttribute('x')), intval($o->getAttribute('y'))];
        if ($e) $ext = [max(1, intval($e->getAttribute('cx'))), max(1, intval($e->getAttribute('cy')))];
        if ($co) $chOff = [intval($co->getAttribute('x')), intval($co->getAttribute('y'))];
        if ($ce) $chExt = [max(1, intval($ce->getAttribute('cx'))), max(1, intval($ce->getAttribute('cy')))];
    }
    // در سطحِ بالا جای گروه را همان لنگر تعیین می‌کند؛ مقیاسِ فرزندان از chExt به اندازه‌ی واقعی
    $sx = $w / rpt_emu($chExt[0]); $sy = $h / rpt_emu($chExt[1]);
    foreach ($g->childNodes as $ch) {
        if (!($ch instanceof DOMElement)) continue;
        $cxf = $xp->query('.//a:xfrm', $ch)->item(0);
        if (!$cxf) continue;
        $o = $xp->query('a:off', $cxf)->item(0); $e = $xp->query('a:ext', $cxf)->item(0);
        if (!$o || !$e) continue;
        $cx = $x + (rpt_emu($o->getAttribute('x')) - rpt_emu($chOff[0])) * $sx;
        $cy = $y + (rpt_emu($o->getAttribute('y')) - rpt_emu($chOff[1])) * $sy;
        $cw = rpt_emu($e->getAttribute('cx')) * $sx; $chh = rpt_emu($e->getAttribute('cy')) * $sy;
        if ($ch->namespaceURI === RPT_NS['wps'] && $ch->localName === 'wsp') rpt_shape($ch, $cx, $cy, $cw, $chh, $base, $ctx);
        elseif ($ch->namespaceURI === RPT_NS['pic'] && $ch->localName === 'pic') rpt_picture($ch, $cx, $cy, $cw, $chh, $base, $ctx);
        elseif ($ch->namespaceURI === RPT_NS['wpg'] && $ch->localName === 'grpSp') {
            // گروهِ تو در تو: همان منطق، با ساختارِ grpSpPr
            $inner = $ch; $inner->setAttribute('data-nested', '1');
            rpt_group_nested($inner, $cx, $cy, $cw, $chh, $base, $ctx);
        }
    }
}
function rpt_group_nested(DOMElement $g, $x, $y, $w, $h, $base, $ctx) {
    // ساختارِ گروهِ تو در تو مثل گروهِ اصلی است، فقط grpSpPr زیرِ wpg:grpSpPr است
    rpt_group($g, $x, $y, $w, $h, $base, $ctx);
}

// تصویر: فایل را از قالب بیرون می‌آورد (یک بار) و جایش را ثبت می‌کند
function rpt_picture(DOMElement $pic, $x, $y, $w, $h, $base, $ctx) {
    $xp = $ctx->xp;
    $blip = $xp->query('.//a:blip', $pic)->item(0);
    if (!$blip) return;
    $rid = $blip->getAttributeNS(RPT_NS['r'], 'embed');
    $target = $ctx->rels[$rid] ?? null;
    if (!$target) return;
    $zipName = 'word/' . ltrim(preg_replace('#^\./#', '', $target), '/');
    $saved = rpt_save_image($zipName, $ctx);
    if (!$saved) return;
    $crop = [0, 0, 0, 0];
    $sr = $xp->query('.//a:srcRect', $pic)->item(0);
    if ($sr) foreach (['l' => 0, 't' => 1, 'r' => 2, 'b' => 3] as $k => $i) if ($sr->getAttribute($k)) $crop[$i] = intval($sr->getAttribute($k)) / 100000;
    $ctx->items[] = $base + ['type' => 'image', 'x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2),
                             'file' => $saved, 'crop' => $crop, 'dx' => 0, 'dy' => 0];
}

function rpt_save_image($zipName, $ctx) {
    if (isset($ctx->savedImages[$zipName])) return $ctx->savedImages[$zipName];
    $data = $ctx->zip->getFromName($zipName);
    if ($data === false) { $ctx->savedImages[$zipName] = null; return null; }
    $ext = strtolower(pathinfo($zipName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
        $ctx->warnings[] = "تصویرِ {$ext} در قالب پشتیبانی نمی‌شود و نمایش داده نمی‌شود (" . basename($zipName) . '). بهتر است در Word به PNG یا JPG تبدیل شود.';
        $ctx->savedImages[$zipName] = null; return null;
    }
    $name = 'img_' . substr(md5($zipName), 0, 10) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = rtrim($ctx->assetDir, '/') . '/' . $name;
    file_put_contents($dest, $data);
    // PNGِ بدونِ شفافیت را بدونِ کانالِ آلفا ذخیره می‌کنیم تا ساختِ PDF سریع و بدون GD باشد
    if ($ext === 'png' && function_exists('imagecreatefrompng')) rpt_png_strip_opaque_alpha($dest);
    $ctx->savedImages[$zipName] = $name;
    return $name;
}

function rpt_png_strip_opaque_alpha($path) {
    $info = @getimagesize($path);
    if (!$info) return;
    $im = @imagecreatefrompng($path);
    if (!$im) return;
    $w = imagesx($im); $h = imagesy($im);
    $step = max(1, intval(sqrt($w * $h / 40000)));   // نمونه‌برداری (تا روی عکس‌های بزرگ هم سریع باشد)
    $hasAlpha = false;
    for ($yy = 0; $yy < $h && !$hasAlpha; $yy += $step) for ($xx = 0; $xx < $w; $xx += $step) {
        if (((imagecolorat($im, $xx, $yy) >> 24) & 0x7F) > 0) { $hasAlpha = true; break; }
    }
    if ($hasAlpha) { imagedestroy($im); return; }
    $out = imagecreatetruecolor($w, $h);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopy($out, $im, 0, 0, 0, 0, $w, $h);
    imagepng($out, $path, 6);
    imagedestroy($im); imagedestroy($out);
}

// ---------------------------------------------------------------------
//  ۲) ساختِ PDF با TCPDF
// ---------------------------------------------------------------------
require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';

class RptPdf extends TCPDF {
    public function __construct() {
        parent::__construct('P', 'pt', 'A4', true, 'UTF-8', false);
        $this->tcpdflink = false;
        $this->setPrintHeader(false); $this->setPrintFooter(false);
        $this->SetMargins(0, 0, 0); $this->SetAutoPageBreak(false, 0);
        $this->setCellPaddings(0, 0, 0, 0); $this->setCellMargins(0, 0, 0, 0);
        $this->SetCreator('بیمه با ما'); $this->SetAuthor('بیمه با ما');
    }
}

// قلم‌ها: وزیر (همراه سایت) + قلم‌هایی که مدیر در تنظیمات گزارش آپلود کرده (نام قلمِ Word => فایل TCPDF)
function rpt_fonts_dir() { return dirname(__DIR__) . '/report_assets/fonts'; }
function rpt_register_fonts(RptPdf $pdf, array $fontMap) {
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    foreach ($fontMap as $wordName => $def) {
        foreach (['' => 'regular', 'B' => 'bold'] as $style => $k) {
            // اگر نسخه‌ی Bold آپلود نشده، همان Regular برای متنِ Bold هم استفاده می‌شود
            $file = !empty($def[$k]) ? $def[$k] : ($def['regular'] ?? null);
            if ($file && is_file(rpt_fonts_dir() . '/' . $file)) {
                try { $pdf->AddFont($def['family'], $style, rpt_fonts_dir() . '/' . $file); } catch (Throwable $e) {}
            }
        }
    }
}

// نویسه‌هایی که یک قلم ندارد (مثل ✔ در وزیر) با قلمِ DejaVu نوشته می‌شوند
function rpt_font_has_char($fontFile, $code) {
    static $cache = [];
    if (!isset($cache[$fontFile])) {
        $cw = [];
        $path = is_file($fontFile) ? $fontFile : dirname(__DIR__) . '/lib/tcpdf/fonts/' . $fontFile;
        if (is_file($path)) { include $path; }
        $cache[$fontFile] = $cw;
    }
    if ($code < 0x20 || ($code >= 0x200B && $code <= 0x200F)) return true;
    if ($code >= 0x0600 && $code <= 0x06FF) return true;   // حروف پایه؛ TCPDF خودش به شکل‌های نمایشی تبدیل می‌کند
    return isset($cache[$fontFile][$code]);
}

function rpt_html_escape($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

// قلمِ Word => قلمِ ساختِ PDF. قلم‌های فارسی (B Yekan، B Nazanin، ...) => وزیر (یا قلمی که مدیر آپلود کرده)؛
// قلم‌های لاتین (Arial Narrow، Times، ...) => برای حروف و عددهای لاتین Helvetica (نسخه‌ی Narrow فشرده‌تر)
function rpt_font_for($wordFont, $fontMap) {
    $wf = trim((string)$wordFont);
    if ($wf !== '' && isset($fontMap[$wf]) && !empty($fontMap[$wf]['family'])) {
        return ['family' => $fontMap[$wf]['family'], 'file' => rpt_fonts_dir() . '/' . $fontMap[$wf]['regular'], 'latin' => null, 'stretch' => 100];
    }
    $latinFonts = ['arial', 'arial narrow', 'times new roman', 'calibri', 'tahoma', 'verdana', 'segoe ui', 'cambria', 'courier new', 'garamond', 'helvetica'];
    $isLatin = in_array(mb_strtolower($wf), $latinFonts, true) || stripos($wf, 'narrow') !== false;
    return ['family' => 'vazir', 'file' => 'vazir.php', 'latin' => $isLatin ? 'helvetica' : null, 'stretch' => stripos($wf, 'narrow') !== false ? 82 : 100];
}

// قلمِ لاتین است؟ (Arial، Times، Calibri، ... و نسخه‌های Narrow)؛ بقیه (وزیر، B Nazanin، قلم‌های آپلودیِ فارسی) فارسی‌اند
function rpt_font_is_latin($name) {
    $n = mb_strtolower(trim((string)$name));
    if ($n === '') return false;
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $n) || preg_match('/^(b |iran|vazir|yekan|nazanin|titr|lotus|mitra|zar|koodak|sahel|shabnam|samim|traffic|homa|roya|yas|kamran|compset|nika)/u', $n)) return false;
    return (bool)preg_match('/arial|helvetica|times|calibri|tahoma|verdana|segoe|cambria|courier|garamond|georgia|consolas|narrow|roboto|open sans|century|book antiqua|trebuchet/u', $n);
}

// رقم‌های متن را فارسی (۰-۹) یا لاتین (0-9) می‌کند؛ کلمه‌ای که حرفِ لاتین دارد (مثل شماره شاسی) در حالتِ فارسی دست نمی‌خورد
function rpt_localize_digits($text, $persian) {
    $text = (string)$text;
    if ($text === '') return $text;
    return preg_replace_callback('/\S+/u', function ($m) use ($persian) {
        $w = $m[0];
        if ($persian) {
            if (preg_match('/[A-Za-z]/', $w)) return $w;
            return strtr($w, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        }
        if (preg_match('/[\x{0621}-\x{064A}\x{067E}-\x{06D3}]/u', $w)) return $w;
        return strtr($w, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                          '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }, $text);
}

function rpt_fill_text($t, $values) {
    return preg_replace_callback('/\{\{\s*([A-Za-z0-9_\x{0600}-\x{06FF}]+)\s*\}\}/u', fn($m) => (string)($values[$m[1]] ?? ''), (string)$t);
}

// متنِ یک پاراگراف را با مقدارهای واقعی پر می‌کند و به «بخش‌ها»یی با قلمِ مناسب می‌شکند:
// فارسی (وزیر/قلمِ آپلودی)، لاتینِ قلم‌های لاتین (Helvetica)، و نمادها (DejaVu)
function rpt_para_segments($para, $values, $opts) {
    $segs = [];
    // اگر کلِ متنِ پاراگراف فقط یک حرفِ فارسی باشد (مثل حرفِ پلاک)، TCPDF آن را نمی‌کشد؛ یک فاصله اضافه می‌کنیم
    $whole = '';
    foreach ($para['runs'] as $r) if ($r['color'] !== '#FFFFFF') $whole .= rpt_fill_text($r['t'], $values);
    $single = (mb_strlen(trim($whole)) === 1 && preg_match('/[\x{0600}-\x{06FF}]/u', trim($whole)));
    foreach ($para['runs'] as $r) {
        if ($r['color'] === '#FFFFFF') continue;    // متنِ سفید در Word عمداً دیده نمی‌شود
        $text = rpt_fill_text($r['t'], $values);
        if ($single && trim($text) !== '') $text = trim($text) . ' ';
        if ($text === '') continue;
        // اندازه/قلمِ اختصاصیِ همین کادر (از ویرایشگرِ قالب) یا قلمِ یکسان برای همه، بر قلمِ Word مقدم است
        $size = !empty($opts['boxSize']) ? floatval($opts['boxSize']) : max(4, ($r['size'] ?: ($para['size'] ?: $opts['defaultSize'])) * ($opts['fontScale'] ?? 1));
        $fontName = !empty($opts['boxFont']) ? $opts['boxFont'] : (!empty($opts['fontAll']) ? $opts['fontAll'] : $r['font']);
        $f = rpt_font_for($fontName, $opts['fontMap']);
        // عددها مطابقِ قلم: قلمِ فارسی (وزیر، B Nazanin، ...) => ۰-۹ فارسی؛ قلمِ لاتین (Arial، ...) => 0-9
        $text = rpt_localize_digits($text, !rpt_font_is_latin($fontName));
        $color = $r['color'] ?: '#000000';
        $cur = null; $buf = '';
        // ضخامتِ اختصاصیِ کادر (از ویرایشگر) بر ضخامتِ متنِ Word مقدم است
        $bold = isset($opts['boxBold']) ? (bool)$opts['boxBold'] : (bool)$r['b'];
        $push = function () use (&$segs, &$buf, &$cur, $bold, $size, $color, $f) {
            if ($buf === '') return;
            $segs[] = ['t' => $buf, 'family' => $cur, 'b' => $bold && $cur !== 'dejavusans', 'size' => $size, 'color' => $color, 'stretch' => $cur === 'helvetica' ? $f['stretch'] : 100];
            $buf = '';
        };
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $c) {
            $code = mb_ord($c, 'UTF-8');
            if ($code >= 0x21 && $code <= 0x7E && $f['latin']) $fam = $f['latin'];
            elseif ($c === ' ' || $c === "\n" || rpt_font_has_char($f['file'], $code)) $fam = ($cur !== null && ($c === ' ' || $c === "\n")) ? $cur : $f['family'];
            else $fam = 'dejavusans';
            if ($fam !== $cur) { $push(); $cur = $fam; }
            $buf .= $c;
        }
        $push();
    }
    return $segs;
}

// فقط متغیرها (بدون متنِ ثابت) خالی‌اند؟ - پاراگرافی که فقط متغیرِ خالی دارد کشیده نمی‌شود
function rpt_paragraph_is_empty($para, $values) {
    $t = '';
    foreach ($para['runs'] as $r) if ($r['color'] !== '#FFFFFF') $t .= rpt_fill_text($r['t'], $values);
    return trim($t) === '';
}

// پهنای بلندترین خط و بلندترین «کلمه» (تکه‌ی بدون فاصله) در یک پاراگراف
function rpt_segments_metrics(RptPdf $pdf, array $segs) {
    $lines = [0]; $li = 0; $tokW = 0; $tokLen = 0; $maxTokW = 0; $maxTokLen = 0;
    foreach ($segs as $s) {
        $k = $s['stretch'] / 100;
        foreach (preg_split('//u', $s['t'], -1, PREG_SPLIT_NO_EMPTY) as $c) {
            if ($c === "\n") { $li++; $lines[$li] = 0; $tokW = 0; $tokLen = 0; continue; }
            $cw = $pdf->GetStringWidth($c, $s['family'], $s['b'] ? 'B' : '', $s['size']) * $k;
            $lines[$li] += $cw;
            if ($c === ' ') { $tokW = 0; $tokLen = 0; continue; }
            $tokW += $cw; $tokLen++;
            if ($tokW > $maxTokW) { $maxTokW = $tokW; $maxTokLen = $tokLen; }
        }
    }
    return ['line' => max($lines), 'token' => $maxTokW, 'tokenLen' => $maxTokLen];
}

function rpt_segments_html(array $segs, $align) {
    $inner = '';
    foreach ($segs as $s) {
        $st = "font-family:{$s['family']};font-size:{$s['size']}pt;color:{$s['color']};" . ($s['b'] ? 'font-weight:bold;' : 'font-weight:normal;')
            . ($s['stretch'] != 100 ? "font-stretch:{$s['stretch']}%;" : '');
        $inner .= '<span style="' . $st . '">' . str_replace("\n", '<br />', rpt_html_escape($s['t'])) . '</span>';
    }
    return '<p style="text-align:' . $align . ';margin:0;padding:0">' . $inner . '</p>';
}

// ترازبندیِ Word برای پاراگرافِ راست‌به‌چپ: start/left یعنی آغازِ خط (سمتِ راست)
function rpt_align($jc, $bidi) {
    if ($jc === 'center') return 'center';
    if ($jc === 'both' || $jc === 'distribute') return $bidi ? 'right' : 'justify';
    if ($bidi) return in_array($jc, ['right', 'end'], true) ? 'left' : 'right';
    return in_array($jc, ['right', 'end'], true) ? 'right' : ($jc === '' ? 'right' : 'left');
}

// $values: مقدار هر متغیر؛ $opts: ['fontMap' => ..., 'fontScale' => 1, 'defaultSize' => ..., 'debug' => false]
function rpt_render_pdf(array $layout, $assetDir, array $values, $destPdf, array $opts = []) {
    $opts += ['fontMap' => [], 'fontScale' => 1.0, 'lineHeight' => 1.1, 'debug' => false, 'defaultSize' => $layout['defaults']['size'] ?? 11];
    $pdf = new RptPdf();
    rpt_register_fonts($pdf, $opts['fontMap']);
    $pdf->setCellHeightRatio($opts['lineHeight']);
    $W = $layout['page']['w']; $H = $layout['page']['h'];
    $byPage = [];
    foreach ($layout['items'] as $it) if (empty($it['hidden'])) $byPage[$it['page']][] = $it;
    for ($p = 0; $p < max(1, intval($layout['pages'])); $p++) {
        $pdf->AddPage($W > $H ? 'L' : 'P', [$W, $H]);
        // کادرهایی که متغیر دارند (پلاک، نام، ...) آخر کشیده می‌شوند تا هیچ تصویر یا شکلِ جلوتری رویشان را نپوشاند
        $items = $byPage[$p] ?? [];
        $hasVar = function ($it) {
            if ($it['type'] === 'image') return false;
            if (isset($it['text']) && strpos((string)$it['text'], '{{') !== false) return true;
            foreach ($it['paras'] ?? [] as $pp) foreach ($pp['runs'] ?? [] as $r) if (strpos((string)$r['t'], '{{') !== false) return true;
            return false;
        };
        $late = array_filter($items, $hasVar);
        $items = array_merge(array_diff_key($items, $late), $late);
        foreach ($items as $it) {
            $x = $it['x'] + ($it['dx'] ?? 0); $y = $it['y'] + ($it['dy'] ?? 0);
            if ($it['type'] === 'image') rpt_draw_image($pdf, $it, $x, $y, $assetDir);
            else rpt_draw_box($pdf, $it, $x, $y, $values, $opts, $W);
        }
    }
    $pdf->Output($destPdf, 'F');
    return is_file($destPdf);
}

function rpt_hex_rgb($hex) {
    $hex = ltrim((string)$hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function rpt_draw_image(RptPdf $pdf, $it, $x, $y, $assetDir) {
    $file = rtrim($assetDir, '/') . '/' . $it['file'];
    if (!is_file($file)) return;
    [$cl, $ct, $cr, $cb] = $it['crop'] ?? [0, 0, 0, 0];
    $fw = $it['w'] / max(0.01, 1 - $cl - $cr); $fh = $it['h'] / max(0.01, 1 - $ct - $cb);
    $pdf->StartTransform();
    $pdf->Rect($x, $y, $it['w'], $it['h'], 'CNZ');
    try { $pdf->Image($file, $x - $cl * $fw, $y - $ct * $fh, $fw, $fh, '', '', '', false, 300); } catch (Throwable $e) {}
    $pdf->StopTransform();
}

// پاراگراف‌های یک کادر؛ اگر در ویرایشگرِ قالب متنِ کادر (مثلاً متغیرش) عوض شده باشد، همان متن با
// سبکِ اولین تکه‌ی متنِ اصلیِ کادر (اندازه، ضخامت، رنگ، قلم و چینش) جایش می‌نشیند
function rpt_box_paras($box) {
    if (!isset($box['text']) || $box['text'] === null || $box['text'] === '') return $box['paras'];
    $p0 = $box['paras'][0] ?? ['align' => 'right', 'bidi' => true, 'size' => null, 'runs' => []];
    $r0 = null;
    foreach ($box['paras'] as $p) foreach ($p['runs'] as $r) if (!$r0 && $r['color'] !== '#FFFFFF' && trim($r['t']) !== '') $r0 = $r;
    $r0 = $r0 ?: ['size' => $p0['size'] ?? null, 'b' => false, 'color' => '#000000', 'font' => ''];
    $out = [];
    foreach (preg_split('/\r?\n/', (string)$box['text']) as $line) {
        $out[] = ['align' => $p0['align'] ?? 'right', 'bidi' => $p0['bidi'] ?? true, 'size' => $p0['size'] ?? null,
                  'runs' => [['t' => $line, 'size' => $r0['size'], 'b' => $r0['b'], 'color' => $r0['color'] === '#FFFFFF' ? '#000000' : $r0['color'], 'font' => $r0['font']]],
                  'text' => $line, 'has_vars' => strpos($line, '{{') !== false];
    }
    return $out;
}

function rpt_draw_box(RptPdf $pdf, $box, $x, $y, $values, $opts, $pageW) {
    // «نمایش فقط وقتی این متغیر پر/تیک است» (از ویرایشگرِ قالب)؛ مثلاً دایره‌ی توپُرِ «خوب» فقط برای وضعیتِ خوب
    if (!empty($box['showif']) && trim((string)($values[$box['showif']] ?? '')) === '') return;
    $w = $box['w']; $h = $box['h'];
    $rot = $box['rot'] ?? 0;
    $vert = $box['vert'] ?? 'horz';
    if (in_array($vert, ['vert', 'eaVert', 'mongolianVert', 'wordArtVert'], true)) $rot += 90;
    if ($vert === 'vert270') $rot += 270;
    $cx = $x + $w / 2; $cy = $y + $h / 2;
    $transformed = false;
    if (abs(fmod($rot, 360)) > 0.01) { $pdf->StartTransform(); $pdf->Rotate(-$rot, $cx, $cy); $transformed = true; }
    // در چرخشِ ۹۰/۲۷۰ درجه، عرض و ارتفاعِ متن جابه‌جا می‌شود
    if ((int)round(fmod($rot + 360, 180)) === 90) { [$w, $h] = [$h, $w]; $x = $cx - $w / 2; $y = $cy - $h / 2; }

    if (!empty($box['fill']) || !empty($box['line'])) {
        $style = (!empty($box['fill']) ? 'F' : '') . (!empty($box['line']) ? 'D' : '');
        $lineStyle = !empty($box['line']) ? ['all' => ['width' => $box['lineW'] ?: 0.75, 'color' => rpt_hex_rgb($box['line'])]] : [];
        $fillRgb = !empty($box['fill']) ? rpt_hex_rgb($box['fill']) : [];
        $geom = $box['geom'] ?? 'rect';
        if ($geom === 'ellipse') $pdf->Ellipse($x + $w / 2, $y + $h / 2, $w / 2, $h / 2, 0, 0, 360, $style, $lineStyle, $fillRgb);
        elseif ($geom === 'roundRect') $pdf->RoundedRect($x, $y, $w, $h, min($w, $h) / 6, '1111', $style, $lineStyle, $fillRgb);
        else $pdf->Rect($x, $y, $w, $h, $style, $lineStyle, $fillRgb);
    }
    if ($opts['debug']) $pdf->Rect($x, $y, $w, $h, 'D', ['all' => ['width' => 0.3, 'color' => [230, 0, 0]]]);

    [$il, $it, $ir, $ib] = $box['ins'];
    $iw = max(2, $w - $il - $ir); $ih = max(2, $h - $it - $ib);
    $paras = []; $alignFirst = 'right'; $expand = 0; $shrink = 1.0;
    if (!empty($box['font'])) $opts['boxFont'] = $box['font'];
    if (!empty($box['fsize'])) $opts['boxSize'] = floatval($box['fsize']);
    if (isset($box['bold']) && $box['bold'] !== '' && $box['bold'] !== null) $opts['boxBold'] = (int)$box['bold'];
    foreach (rpt_box_paras($box) as $para) {
        if (rpt_paragraph_is_empty($para, $values)) { $paras[] = null; continue; }
        $segs = rpt_para_segments($para, $values, $opts);
        if (!$segs) continue;
        $align = rpt_align($para['align'], $para['bidi']);
        if (!array_filter($paras)) $alignFirst = $align;
        $m = rpt_segments_metrics($pdf, $segs);
        // مثل Word: نماد یا حرفِ تکی که جا نمی‌شود بیرون می‌ریزد (کادر پهن‌تر می‌شود)؛ کلمه‌ی بلندِ بی‌فاصله
        // (مثل شماره شاسی) کمی کوچک‌تر نوشته می‌شود تا در یک خط بماند؛ چند کلمه هم به خطِ بعد می‌رود
        if ($m['token'] > $iw) {
            if ($m['tokenLen'] <= 2) $expand = max($expand, $m['token'] + 1.5 - $iw);
            else $shrink = min($shrink, max(0.6, ($iw - 0.5) / $m['token']));
        }
        $paras[] = [$segs, $align];
    }
    if (array_filter($paras)) {
        $html = '';
        foreach ($paras as $pp) {
            if ($pp === null) { $html .= '<p style="font-size:1pt;line-height:1;margin:0">&nbsp;</p>'; continue; }
            [$segs, $align] = $pp;
            if ($shrink < 1) foreach ($segs as &$sg) { $sg['size'] = round($sg['size'] * $shrink, 2); } unset($sg);
            $html .= rpt_segments_html($segs, $align);
        }
        $tx = $x + $il;
        if ($expand > 0) {
            $tx -= ['center' => $expand / 2, 'right' => $expand][$alignFirst] ?? 0;
            $iw += $expand;
        }
        $pdf->startTransaction();
        $pdf->setRTL(true);
        $pdf->writeHTMLCell($iw, 0, $pageW - ($tx + $iw), $y + $it, $html, 0, 1, false, true, '', true);
        $textH = $pdf->GetY() - ($y + $it);
        $pdf->rollbackTransaction(true);
        $offY = ['ctr' => ($ih - $textH) / 2, 'b' => $ih - $textH][$box['anchor'] ?? 't'] ?? 0;
        $pdf->setRTL(true);
        $pdf->writeHTMLCell($iw, 0, $pageW - ($tx + $iw), $y + $it + $offY, $html, 0, 1, false, true, '', true);
        $pdf->setRTL(false);
    }
    if ($transformed) $pdf->StopTransform();
}

// ---------------------------------------------------------------------
//  ۳) نسخه‌ی Word پرشده (مثل برنامه‌ی ویندوزی)
// ---------------------------------------------------------------------
function rpt_fill_docx($tplPath, $destPath, array $values, array $opts = []) {
    if (!@copy($tplPath, $destPath)) return false;
    $z = new ZipArchive();
    if ($z->open($destPath) !== true) return false;
    $fontAll = trim((string)($opts['fontAll'] ?? ''));
    $fill = function ($xml, $persian) use ($values) {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_\x{0600}-\x{06FF}]+)\s*\}\}/u', function ($m) use ($values, $persian) {
            $raw = (string)($values[$m[1]] ?? '');
            if ($persian !== null) $raw = rpt_localize_digits($raw, $persian);
            $v = htmlspecialchars($raw, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            return str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', $v);
        }, $xml);
    };
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (!preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $n)) continue;
        $xml = rpt_merge_placeholders($z->getFromIndex($i));
        // هر run جدا: عددهای مقدار مطابقِ قلمِ همان run (یا «قلمِ همه‌ی کادرها») فارسی یا لاتین نوشته می‌شوند
        $xml = preg_replace_callback('#<w:r\b[^>]*>(?:(?!</w:r>).)*</w:r>#s', function ($m) use ($fill, $fontAll) {
            $run = $m[0];
            if (strpos($run, '{{') === false) return $run;
            $font = $fontAll;
            if ($font === '' && preg_match('#<w:rFonts\b([^>]*)/?>#', $run, $rf)) {
                foreach (['w:cs', 'w:ascii', 'w:hAnsi'] as $attr) {
                    if (preg_match('#' . $attr . '="([^"]+)"#', $rf[1], $fm)) { $font = $fm[1]; break; }
                }
            }
            return $fill($run, $font === '' ? null : !rpt_font_is_latin($font));
        }, $xml);
        $xml = $fill($xml, null);   // جا مانده‌ها (بیرون از run)
        $z->addFromString($n, $xml);
    }
    return $z->close();
}
