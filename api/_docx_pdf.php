<?php
// فایل: api/_docx_pdf.php
// تبدیلِ Word (docx) به PDF کاملاً با PHP (TCPDF؛ بدونِ LibreOffice و بدونِ پایتون).
// برای صورتحساب‌ها، رسیدها، فیش‌ها و هر قالبِ Wordِ دیگری که پر و بایگانی می‌شود. هدف: همان چیزی که در Word طراحی شده.
//   - لایه‌ها مثلِ Word: سربرگ/پاصفحه زیرِ همه، شیءهای «پشتِ متن» زیرِ متن، کادرها و شکل‌های «جلوی متن» رویِ همه
//   - سربرگِ تصویری (DrawingML و VMLِ قدیمی، واترمارک)، سربرگِ جدا برای صفحه‌ی اول، کادر و رنگِ زمینه‌ی صفحه
//   - کادرهای متنی، شکل‌ها (مستطیل/گرد/بیضی/خط با رنگ و کادر)، گروه‌ها، عکس‌های درون‌خطی و شناور
//   - پاراگراف‌ها (راست‌به‌چپ، تراز، فاصله‌ها، تورفتگی، کادرِ پاراگراف، توقف‌گاه‌های Tab با نقطه‌چین)،
//     اندازه/پررنگ/رنگ/زیرخط/هایلایت، سبک‌های Word و رنگ/قلمِ پوسته (theme)، فهرستِ شماره‌دار، شکستِ صفحه
//   - جدول‌ها (ادغامِ افقی/عمودی، کادر، رنگِ زمینه، فاصله‌ی داخلیِ خانه‌ها، ارتفاعِ سطر، سطرِ عنوانِ تکراری، جدولِ تودرتو)
//   - شماره‌ی صفحه (PAGE / NUMPAGES)
//   - قلم‌ها: قلمِ جاسازی‌شده در خودِ فایلِ Word (Word: Options → Save → Embed fonts) یا قلم‌هایی که در
//     «تنظیماتِ گزارشِ بازدید ← قلم‌ها» بارگذاری شده‌اند (نامِ قلم در Word => TTF)؛ وگرنه وزیر.
//
//   docx_to_pdf($docxAbs, $pdfAbs, $opts = []) => true/false      ($opts['fontMap'] اختیاری؛ پیش‌فرض از جدولِ report_fonts)

if (!defined('K_PATH_CACHE')) {
    $dxCache = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/';
    if (!is_dir($dxCache)) @mkdir($dxCache, 0775, true);
    define('K_PATH_CACHE', is_dir($dxCache) ? $dxCache : rtrim(sys_get_temp_dir(), '/') . '/');
}
require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';

class DocxPdfDoc extends TCPDF {
    public $dxHeader = null; public $dxFooter = null; public $dxHdrEnd = [];
    public function __construct($o, $u, $f) { parent::__construct($o, $u, $f, true, 'UTF-8', false); $this->tcpdflink = false; }
    public function Header() { if ($this->dxHeader) call_user_func($this->dxHeader, $this); $this->dxHdrEnd[$this->page] = $this->pagelen[$this->page] ?? 0; }
    public function Footer() { if ($this->dxFooter) call_user_func($this->dxFooter, $this); }
    // مسیرِ برداری: [['M',x,y], ['L',x,y], ['C',x1,y1,x2,y2,x3,y3], ['Z']] (میلی‌متر) و عملگر: f | S | B | W n
    public function dxPath(array $cmds, $op) {
        foreach ($cmds as $c) {
            if ($c[0] === 'M') $this->_outPoint($c[1], $c[2]);
            elseif ($c[0] === 'L') $this->_outLine($c[1], $c[2]);
            elseif ($c[0] === 'C') $this->_outCurve($c[1], $c[2], $c[3], $c[4], $c[5], $c[6]);
            elseif ($c[0] === 'Z') $this->_out('h');
        }
        $this->_out($op);
    }
    // پُر کردنِ مسیر با رنگِ تدریجی (stops: [[offset 0..1, [r,g,b]], ...]، coords در مربعِ واحدِ جعبه)
    public function dxGradientPath(array $cmds, array $box, array $stops, array $coords) {
        if ($this->state != 2 || count($stops) < 2) return;
        $this->_outSaveGraphicsState();
        $this->dxPath($cmds, 'W n');
        [$x, $y, $w, $h] = $box;
        $this->_out(sprintf('%F 0 0 %F %F %F cm', $w * $this->k, $h * $this->k, $x * $this->k, ($this->h - ($y + $h)) * $this->k));
        $this->Gradient(2, $coords, array_map(function ($s) { return ['color' => $s[1], 'offset' => $s[0], 'exponent' => 1]; }, $stops), [], false);
    }
    // آنچه $fn می‌کشد زیرِ متنِ صفحه (بعد از سرصفحه) قرار می‌گیرد — برای شیءهای «پشتِ متن»
    public function dxUnder($page, callable $fn) {
        $cur = $this->page;
        $this->setPage($page);
        $before = (string)$this->getPageBuffer($page);
        $this->SetTextColor(0); $this->SetDrawColor(0); $this->SetFillColor(255);
        $fn($this);
        $after = (string)$this->getPageBuffer($page);
        if (strlen($after) > strlen($before) && strncmp($after, $before, strlen($before)) === 0) {
            $pos = min(strlen($before), $this->dxHdrEnd[$page] ?? 0);
            $this->setPageBuffer($page, substr($before, 0, $pos) . "q\n" . substr($after, strlen($before)) . "\nQ\n" . substr($before, $pos));
        }
        $this->setPage($cur);
    }
}

class DocxToPdf
{
    const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const NS = ['w' => self::W, 'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
                'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture', 'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape', 'wpg' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingGroup',
                'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006', 'v' => 'urn:schemas-microsoft-com:vml', 'o' => 'urn:schemas-microsoft-com:office:office'];
    const TAB_MARK = '&nbsp;&nbsp;&nbsp;&nbsp;';
    public $fontScale = 0.92;           // وزیر از قلم‌های رایجِ Word (نازنین، لوتوس، …) درشت‌تر است
    public $fontMap = [];               // نامِ قلم در Word => ['family', 'regular' (php), 'bold' (php|null)] در report_assets/fonts
    private $zip, $styles = [], $resolved = [], $defP = [], $defR = [], $numFmt = [], $numCount = [], $tmpDir, $imgSeq = 0;
    private $pdf, $pg = [], $docRtl = true, $footH = 6, $defTab = 12.7;
    private $theme = ['clr' => [], 'font' => []], $fonts = [], $embFonts = [];
    private $under = [], $over = [], $pageBg = null, $pgBorders = null, $imgCache = [], $meas = null, $measN = 0;

    // ---------------- کمکی‌ها ----------------
    private function xp(DOMDocument $d) { $x = new DOMXPath($d); foreach (self::NS as $k => $u) $x->registerNamespace($k, $u); return $x; }
    private function dom($xml) {
        if ($xml === false || $xml === '' || $xml === null) return null;
        libxml_use_internal_errors(true);
        $d = new DOMDocument();
        return $d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE) ? $d : null;
    }
    private static function wv(?DOMElement $e, $attr = 'val') { return $e ? $e->getAttributeNS(self::W, $attr) : null; }
    private static function on(?DOMElement $e) {
        if (!$e) return null;
        $v = $e->getAttributeNS(self::W, 'val');
        return !in_array(strtolower((string)$v), ['0', 'false', 'off', 'none'], true);
    }
    private function child(?DOMElement $e, $name) {
        if (!$e) return null;
        foreach ($e->childNodes as $c) if ($c instanceof DOMElement && $c->namespaceURI === self::W && $c->localName === $name) return $c;
        return null;
    }
    private static function kid(?DOMElement $e, $ns, $name) {
        if (!$e) return null;
        foreach ($e->childNodes as $c) if ($c instanceof DOMElement && $c->namespaceURI === $ns && $c->localName === $name) return $c;
        return null;
    }
    private static function mm($twips) { return floatval($twips) / 56.6929; }
    private static function emu($v) { return floatval($v) / 36000; }
    private static function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
    private static function hex($c) { return $c && $c !== 'auto' && preg_match('/^[0-9A-Fa-f]{6}$/', $c) ? '#' . strtoupper($c) : null; }
    private static function rgb($hex) { $h = ltrim((string)$hex, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; }
    private static function toHex(array $c) { return sprintf('#%02X%02X%02X', max(0, min(255, round($c[0]))), max(0, min(255, round($c[1]))), max(0, min(255, round($c[2])))); }

    // ---------------- پوسته (رنگ‌ها و قلم‌های theme) ----------------
    private function loadTheme() {
        $d = $this->dom($this->zip->getFromName('word/theme/theme1.xml'));
        if (!$d) return;
        $x = $this->xp($d);
        foreach ($x->query('//a:clrScheme/*') as $c) {
            $s = $x->query('a:srgbClr/@val|a:sysClr/@lastClr', $c)->item(0);
            if ($s) $this->theme['clr'][$c->localName] = '#' . strtoupper($s->nodeValue);
        }
        foreach (['major', 'minor'] as $k) {
            $f = $x->query('//a:fontScheme/a:' . $k . 'Font')->item(0);
            if (!$f) continue;
            $lat = $x->query('a:latin/@typeface', $f)->item(0); $cs = $x->query('a:cs/@typeface', $f)->item(0); $ar = $x->query('a:font[@script="Arab"]/@typeface', $f)->item(0);
            $this->theme['font'][$k] = ['latin' => $lat ? $lat->nodeValue : '', 'cs' => ($cs && $cs->nodeValue !== '') ? $cs->nodeValue : ($ar ? $ar->nodeValue : '')];
        }
    }
    private function themeFont($t) {
        if (!$t) return '';
        $k = stripos($t, 'major') === 0 ? 'major' : 'minor';
        return (string)($this->theme['font'][$k][stripos($t, 'bidi') !== false ? 'cs' : 'latin'] ?? '');
    }
    // رنگِ DrawingML (srgb/scheme/sys/prst + روشن/تیره‌کردن) => [#hex, alpha]
    private function dmlColor(?DOMElement $p) {
        if (!$p) return [null, 1];
        $c = null; $hex = null;
        foreach ($p->childNodes as $n) if ($n instanceof DOMElement && in_array($n->localName, ['srgbClr', 'schemeClr', 'sysClr', 'prstClr', 'scrgbClr'], true)) { $c = $n; break; }
        if (!$c) return [null, 1];
        if ($c->localName === 'srgbClr') $hex = '#' . $c->getAttribute('val');
        elseif ($c->localName === 'sysClr') $hex = '#' . ($c->getAttribute('lastClr') ?: ($c->getAttribute('val') === 'window' ? 'FFFFFF' : '000000'));
        elseif ($c->localName === 'prstClr') $hex = ['black' => '#000000', 'white' => '#FFFFFF', 'red' => '#FF0000', 'blue' => '#0000FF', 'green' => '#008000', 'yellow' => '#FFFF00', 'gray' => '#808080'][$c->getAttribute('val')] ?? '#000000';
        elseif ($c->localName === 'schemeClr') {
            $v = $c->getAttribute('val');
            $v = ['tx1' => 'dk1', 'bg1' => 'lt1', 'tx2' => 'dk2', 'bg2' => 'lt2', 'phClr' => 'accent1'][$v] ?? $v;
            $hex = $this->theme['clr'][$v] ?? (in_array($v, ['dk1', 'dk2'], true) ? '#000000' : (in_array($v, ['lt1', 'lt2'], true) ? '#FFFFFF' : '#4472C4'));
        } else $hex = '#000000';
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) return [null, 1];
        $rgb = self::rgb($hex); $alpha = 1;
        foreach ($c->childNodes as $m) {
            if (!($m instanceof DOMElement)) continue;
            $v = intval($m->getAttribute('val')) / 100000;
            switch ($m->localName) {
                case 'alpha': $alpha = $v; break;
                case 'shade': $rgb = array_map(function ($k) use ($v) { return $k * $v; }, $rgb); break;
                case 'tint': $rgb = array_map(function ($k) use ($v) { return $k + (255 - $k) * (1 - $v); }, $rgb); break;
                case 'lumMod': case 'lumOff':
                    [$h, $s, $l] = self::hsl($rgb);
                    $l = $m->localName === 'lumMod' ? $l * $v : min(1, $l + $v);
                    $rgb = self::fromHsl($h, $s, $l); break;
            }
        }
        return [self::toHex($rgb), $alpha];
    }
    private static function hsl(array $c) {
        [$r, $g, $b] = [$c[0] / 255, $c[1] / 255, $c[2] / 255];
        $mx = max($r, $g, $b); $mn = min($r, $g, $b); $l = ($mx + $mn) / 2; $d = $mx - $mn;
        if ($d == 0) return [0, 0, $l];
        $s = $l > .5 ? $d / (2 - $mx - $mn) : $d / ($mx + $mn);
        $h = $mx == $r ? fmod(($g - $b) / $d + 6, 6) : ($mx == $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4);
        return [$h / 6, $s, $l];
    }
    private static function fromHsl($h, $s, $l) {
        if ($s == 0) return [$l * 255, $l * 255, $l * 255];
        $q = $l < .5 ? $l * (1 + $s) : $l + $s - $l * $s; $p = 2 * $l - $q;
        $f = function ($t) use ($p, $q) { if ($t < 0) $t += 1; if ($t > 1) $t -= 1; if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t; if ($t < .5) return $q; if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6; return $p; };
        return [$f($h + 1 / 3) * 255, $f($h) * 255, $f($h - 1 / 3) * 255];
    }

    // ---------------- قلم‌ها ----------------
    private static function fnorm($n) { return preg_replace('/[\s_\-]+/u', '', mb_strtolower(trim((string)$n))); }
    private function fontCacheDir() {
        $d = K_PATH_CACHE . 'dxfonts/';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }
    // قلم‌های جاسازی‌شده در docx (obfuscated: ۳۲ بایتِ اول با کلیدِ GUID)
    private function loadEmbeddedFonts() {
        $d = $this->dom($this->zip->getFromName('word/fontTable.xml'));
        if (!$d) return;
        $x = $this->xp($d);
        $rels = $this->rels('word/fontTable.xml');
        foreach ($x->query('/w:fonts/w:font') as $f) {
            $name = $f->getAttributeNS(self::W, 'name');
            foreach (['embedRegular' => 'R', 'embedBold' => 'B'] as $tag => $st) {
                $e = $this->child($f, $tag);
                if (!$e) continue;
                $t = $rels[$e->getAttributeNS(self::NS['r'], 'id')]['target'] ?? null;
                $bin = $t ? $this->zip->getFromName($t) : false;
                $key = str_replace(['{', '}', '-'], '', $e->getAttributeNS(self::W, 'fontKey'));
                if ($bin === false || strlen($bin) < 64) continue;
                if (strlen($key) === 32) {
                    $kb = '';
                    for ($i = 30; $i >= 0; $i -= 2) $kb .= chr(hexdec(substr($key, $i, 2)));
                    for ($i = 0; $i < 32; $i++) $bin[$i] = $bin[$i] ^ $kb[$i % 16];
                }
                if (in_array(substr($bin, 0, 4), ["\x00\x01\x00\x00", 'true'], true)) $this->embFonts[self::fnorm($name)][$st] = $bin;   // فقط TrueType
            }
        }
    }
    private static function fontDef($php) {
        if (!is_file($php)) return null;
        $r = (function ($p) { $cw = null; $desc = null; include $p; return [$cw, $desc]; })($php);
        return is_array($r[0]) ? $r : null;
    }
    // نامِ قلم در Word => ['fam', 'cw', 'lh', 'bold' (دارد یا نه), 'ar' (شکل‌های چسبانِ عربی دارد)] یا null (وزیر)
    private function fontFor($name) {
        $k = self::fnorm($name);
        if ($k === '' || in_array($k, ['vazir', 'vazirmatn'], true)) return null;
        if (array_key_exists($k, $this->fonts)) return $this->fonts[$k];
        $this->fonts[$k] = null;
        $files = [];
        if (isset($this->embFonts[$k])) {
            $dir = $this->fontCacheDir();
            foreach ($this->embFonts[$k] as $st => $bin) {
                $nm = 'dx' . substr(md5($bin), 0, 14);
                if (!is_file($dir . $nm . '.php')) {
                    $tmp = $dir . $nm . '.ttf';
                    @file_put_contents($tmp, $bin);
                    try { $got = TCPDF_FONTS::addTTFfont($tmp, 'TrueTypeUnicode', '', 32, $dir); } catch (Throwable $e) { $got = false; }
                    @unlink($tmp);
                    if (!$got) continue;
                    if ($got !== $nm) $nm = $got;
                }
                $files[$st] = $dir . $nm . '.php';
            }
        }
        if (!isset($files['R'])) foreach ($this->fontMap as $wn => $f) {
            if (self::fnorm($wn) !== $k || empty($f['regular'])) continue;
            $base = dirname(__DIR__) . '/report_assets/fonts/';
            if (is_file($base . $f['regular'])) { $files['R'] = $base . $f['regular']; if (!empty($f['bold']) && is_file($base . $f['bold'])) $files['B'] = $base . $f['bold']; }
            break;
        }
        if (!isset($files['R'])) return null;
        $def = self::fontDef($files['R']);
        if (!$def) return null;
        $fam = preg_replace('/[^a-z0-9]/', '', strtolower(basename($files['R'], '.php')));
        try {
            $this->pdf->AddFont($fam, '', $files['R']);
            $this->pdf->AddFont($fam, 'B', $files['B'] ?? $files['R']);
        } catch (Throwable $e) { return null; }
        $lh = (isset($def[1]['Ascent'], $def[1]['Descent']) ? (abs($def[1]['Ascent']) + abs($def[1]['Descent'])) / 1000 : 1.25);
        $cw = $def[0];
        return $this->fonts[$k] = ['fam' => $fam, 'cw' => $cw, 'lh' => max(1.0, min(2.2, $lh)), 'bold' => isset($files['B']), 'file' => $files['R'], 'fileB' => $files['B'] ?? $files['R'],
                                   'ar' => isset($cw[0xFEE7], $cw[0xFEEB], $cw[0xFE8E], $cw[0xFEDF])];
    }
    // همه‌ی نویسه‌های متن در قلم هست؟ (قلمِ جاسازی‌شده‌ی ناقص => وزیر)
    private static function covers(array $f, $text) {
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text) && !$f['ar']) return false;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $o = mb_ord($ch, 'UTF-8');
            if ($o < 33 || ($o >= 0x0600 && $o <= 0x06FF) || ($o >= 0x200B && $o <= 0x200F) || $o === 0xA0) continue;
            if (!isset($f['cw'][$o])) return false;
        }
        return true;
    }

    // ---------------- سبک‌ها ----------------
    private function parsePPr(?DOMElement $pPr) {
        $o = [];
        if (!$pPr) return $o;
        if ($j = $this->child($pPr, 'jc')) $o['jc'] = self::wv($j);
        if ($b = $this->child($pPr, 'bidi')) $o['bidi'] = self::on($b);
        if ($s = $this->child($pPr, 'spacing')) {
            foreach (['before', 'after', 'line', 'lineRule'] as $k) { $v = $s->getAttributeNS(self::W, $k); if ($v !== '') $o[$k] = $v; }
            if ($s->getAttributeNS(self::W, 'beforeAutospacing') === '1') $o['before'] = 100;
            if ($s->getAttributeNS(self::W, 'afterAutospacing') === '1') $o['after'] = 100;
        }
        if ($i = $this->child($pPr, 'ind')) {
            $g = function ($a, $b) use ($i) { $v = $i->getAttributeNS(self::W, $a); return $v !== '' ? $v : $i->getAttributeNS(self::W, $b); };
            if (($v = $g('start', 'left')) !== '') $o['indS'] = intval($v);
            if (($v = $g('end', 'right')) !== '') $o['indE'] = intval($v);
            if (($v = $i->getAttributeNS(self::W, 'firstLine')) !== '') $o['indF'] = intval($v);
            if (($v = $i->getAttributeNS(self::W, 'hanging')) !== '') $o['indF'] = -intval($v);
        }
        if ($pb = $this->child($pPr, 'pageBreakBefore')) $o['pbb'] = self::on($pb);
        if ($n = $this->child($pPr, 'numPr')) { $o['numId'] = self::wv($this->child($n, 'numId')); $o['ilvl'] = intval(self::wv($this->child($n, 'ilvl'))); }
        if ($sh = $this->child($pPr, 'shd')) $o['shd'] = $sh->getAttributeNS(self::W, 'fill');
        if ($bd = $this->child($pPr, 'pBdr')) foreach (['top', 'bottom', 'left', 'right'] as $k) { $v = $this->borderOf($this->child($bd, $k)); if ($v) $o['bdr'][$k] = $v; }
        if ($tb = $this->child($pPr, 'tabs')) {
            $o['tabs'] = [];
            foreach ($tb->childNodes as $t) if ($t instanceof DOMElement && $t->localName === 'tab')
                $o['tabs'][] = ['val' => $t->getAttributeNS(self::W, 'val'), 'pos' => self::mm(intval($t->getAttributeNS(self::W, 'pos'))), 'leader' => $t->getAttributeNS(self::W, 'leader')];
        }
        return $o;
    }
    private function parseRPr(?DOMElement $rPr) {
        $o = [];
        if (!$rPr) return $o;
        foreach (['b', 'bCs', 'i', 'iCs', 'rtl', 'cs', 'caps', 'strike', 'vanish'] as $k) if ($e = $this->child($rPr, $k)) $o[$k] = self::on($e);
        if ($e = $this->child($rPr, 'sz')) $o['sz'] = intval(self::wv($e));
        if ($e = $this->child($rPr, 'szCs')) $o['szCs'] = intval(self::wv($e));
        if ($e = $this->child($rPr, 'color')) {
            $v = self::wv($e); $th = $e->getAttributeNS(self::W, 'themeColor');
            if ($th && isset($this->theme['clr'][['text1' => 'dk1', 'background1' => 'lt1', 'text2' => 'dk2', 'background2' => 'lt2'][$th] ?? $th])) $v = ltrim($this->theme['clr'][['text1' => 'dk1', 'background1' => 'lt1', 'text2' => 'dk2', 'background2' => 'lt2'][$th] ?? $th], '#');
            $o['color'] = $v;
        }
        if ($e = $this->child($rPr, 'u')) $o['u'] = self::wv($e) !== 'none';
        if ($e = $this->child($rPr, 'highlight')) $o['hl'] = self::wv($e);
        if ($e = $this->child($rPr, 'shd')) $o['shd'] = $e->getAttributeNS(self::W, 'fill');
        if ($e = $this->child($rPr, 'vertAlign')) $o['va'] = self::wv($e);
        if ($e = $this->child($rPr, 'rFonts')) {
            $a = $e->getAttributeNS(self::W, 'ascii') ?: $this->themeFont($e->getAttributeNS(self::W, 'asciiTheme'));
            $c = $e->getAttributeNS(self::W, 'cs') ?: $this->themeFont($e->getAttributeNS(self::W, 'cstheme'));
            if ($a !== '') $o['fa'] = $a;
            if ($c !== '') $o['fc'] = $c;
        }
        return $o;
    }
    private function loadStyles() {
        $d = $this->dom($this->zip->getFromName('word/styles.xml'));
        if (!$d) return;
        $x = $this->xp($d);
        $this->defP = $this->parsePPr($x->query('/w:styles/w:docDefaults/w:pPrDefault/w:pPr')->item(0));
        $this->defR = $this->parseRPr($x->query('/w:styles/w:docDefaults/w:rPrDefault/w:rPr')->item(0));
        foreach ($x->query('/w:styles/w:style') as $s) {
            $id = $s->getAttributeNS(self::W, 'styleId');
            $this->styles[$id] = ['type' => $s->getAttributeNS(self::W, 'type'), 'default' => $s->getAttributeNS(self::W, 'default') === '1',
                                  'based' => self::wv($this->child($s, 'basedOn')), 'p' => $this->parsePPr($this->child($s, 'pPr')), 'r' => $this->parseRPr($this->child($s, 'rPr')),
                                  'tblBorders' => $x->query('w:tblPr/w:tblBorders', $s)->item(0), 'tblCellMar' => $x->query('w:tblPr/w:tblCellMar', $s)->item(0)];
        }
    }
    private function style($id, $depth = 0) {
        if ($id === null || $id === '' || !isset($this->styles[$id]) || $depth > 12) return ['p' => [], 'r' => [], 'tblBorders' => null, 'tblCellMar' => null];
        if (isset($this->resolved[$id])) return $this->resolved[$id];
        $s = $this->styles[$id];
        $b = $this->style($s['based'], $depth + 1);
        return $this->resolved[$id] = ['p' => array_merge($b['p'], $s['p']), 'r' => array_merge($b['r'], $s['r']), 'tblBorders' => $s['tblBorders'] ?: $b['tblBorders'],
                                       'tblCellMar' => $s['tblCellMar'] ?: $b['tblCellMar']];
    }
    private function defaultStyle($type) { foreach ($this->styles as $id => $s) if ($s['type'] === $type && $s['default']) return $id; return null; }
    private function loadNumbering() {
        $d = $this->dom($this->zip->getFromName('word/numbering.xml'));
        if (!$d) return;
        $x = $this->xp($d);
        $abs = [];
        foreach ($x->query('/w:numbering/w:abstractNum') as $a) {
            $aid = $a->getAttributeNS(self::W, 'abstractNumId');
            foreach ($x->query('w:lvl', $a) as $l) $abs[$aid][intval($l->getAttributeNS(self::W, 'ilvl'))] = [self::wv($this->child($l, 'numFmt')), self::wv($this->child($l, 'lvlText'))];
        }
        foreach ($x->query('/w:numbering/w:num') as $n) $this->numFmt[$n->getAttributeNS(self::W, 'numId')] = $abs[self::wv($this->child($n, 'abstractNumId'))] ?? [];
    }
    private function loadSettings() {
        $d = $this->dom($this->zip->getFromName('word/settings.xml'));
        if (!$d) return;
        $x = $this->xp($d);
        $t = $x->query('/w:settings/w:defaultTabStop')->item(0);
        if ($t && intval(self::wv($t)) > 0) $this->defTab = self::mm(intval(self::wv($t)));
    }

    // ---------------- رسانه‌ها ----------------
    private function rels($part) {
        $dir = dirname($part); $base = basename($part);
        $d = $this->dom($this->zip->getFromName($dir . '/_rels/' . $base . '.rels'));
        $o = [];
        if (!$d) return $o;
        foreach ($d->getElementsByTagName('Relationship') as $r) {
            $t = $r->getAttribute('Target');
            $o[$r->getAttribute('Id')] = ['target' => $r->getAttribute('TargetMode') === 'External' || $t === '' ? null : ($t[0] === '/' ? ltrim($t, '/') : $this->normPath($dir . '/' . $t)), 'type' => $r->getAttribute('Type')];
        }
        return $o;
    }
    private function normPath($p) { $o = []; foreach (explode('/', $p) as $s) { if ($s === '..') array_pop($o); elseif ($s !== '.' && $s !== '') $o[] = $s; } return implode('/', $o); }
    private function image($rid, array $rels) {
        $t = $rels[$rid]['target'] ?? null;
        if (!$t) return null;
        $ext = strtolower(pathinfo($t, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) return null;   // EMF/WMF پشتیبانی نمی‌شود
        if (isset($this->imgCache[$t])) return $this->imgCache[$t];   // یک فایل => TCPDF یک بار پردازش می‌کند
        $bin = $this->zip->getFromName($t);
        if ($bin === false) return null;
        $f = $this->tmpDir . '/img' . (++$this->imgSeq) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        file_put_contents($f, $bin);
        return $this->imgCache[$t] = $f;
    }

    // ---------------- متن ----------------
    // ارقام همان‌طور که در فایل نوشته شده‌اند (مثلِ Word و LibreOffice)؛ با persianDigits = true در متنِ راست‌چین فارسی می‌شوند
    public $persianDigits = false;
    private function digits($t, $persian) {
        if (!$persian || !$this->persianDigits || $t === '') return $t;
        return preg_replace_callback('/\S+/u', function ($m) {
            return preg_match('/[A-Za-z]/', $m[0]) ? $m[0] : strtr($m[0], ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        }, $t);
    }
    // پاراگراف => [html, props, anchors]؛ props['segs'] = تکه‌های بینِ Tabها (برای چیدن روی توقف‌گاه‌ها)
    private function paragraph(DOMElement $p, DOMXPath $x, array $rels, $inCell = false) {
        $ps = $this->style(self::wv($x->query('w:pPr/w:pStyle', $p)->item(0)) ?: $this->defaultStyle('paragraph'));
        $direct = $this->parsePPr($x->query('w:pPr', $p)->item(0));
        $pp = array_merge($this->defP, $ps['p'], $direct);
        $tabs = [];
        foreach (array_merge($this->defP['tabs'] ?? [], $ps['p']['tabs'] ?? [], $direct['tabs'] ?? []) as $t) {
            if ($t['val'] === 'clear') { $tabs = array_values(array_filter($tabs, function ($o) use ($t) { return abs($o['pos'] - $t['pos']) > 0.3; })); continue; }
            if ($t['val'] !== 'bar' && $t['val'] !== 'num') $tabs[] = $t;
        }
        usort($tabs, function ($a, $b) { return $a['pos'] <=> $b['pos']; });
        $bidi = array_key_exists('bidi', $pp) ? (bool)$pp['bidi'] : false;
        $baseR = array_merge($this->defR, $ps['r']);
        $segs = ['']; $anchors = []; $maxPt = 0; $lh = 0; $pageBreak = false; $fld = null; $lastKey = null;
        // شماره‌گذاری / گلوله
        if (!empty($pp['numId']) && isset($this->numFmt[$pp['numId']])) {
            $lv = $pp['ilvl'] ?? 0; $f = $this->numFmt[$pp['numId']][$lv] ?? ['bullet', '•'];
            $k = $pp['numId'] . ':' . $lv;
            $this->numCount[$k] = ($this->numCount[$k] ?? 0) + 1;
            foreach (array_keys($this->numCount) as $kk) if (strpos($kk, $pp['numId'] . ':') === 0 && intval(explode(':', $kk)[1]) > $lv) unset($this->numCount[$kk]);
            $lbl = $f[0] === 'bullet' ? '•' : str_replace('%' . ($lv + 1), (string)$this->numCount[$k], (string)($f[1] ?: '%' . ($lv + 1) . '.'));
            $lbl = preg_replace('/%\d/', '', $lbl);
            if ($f[0] !== 'none') $segs[0] .= ($bidi ? "\u{200F}" : '') . $this->esc($this->digits($lbl, $bidi)) . ($bidi ? "\u{200F}" : '') . '&nbsp;&nbsp;';
        }
        foreach ($this->runs($p, $x) as $r) {
            if (is_string($r)) {   // فیلدِ سادهِ شماره‌ی صفحه
                $segs[count($segs) - 1] .= $r === 'NUMPAGES' ? $this->pdf->getAliasNbPages() : $this->pdf->getAliasNumPage();
                $lastKey = null;
                continue;
            }
            $rs = array_merge($baseR, $this->style(self::wv($x->query('w:rPr/w:rStyle', $r)->item(0)))['r'], $this->parseRPr($x->query('w:rPr', $r)->item(0)));
            if (!empty($rs['vanish'])) continue;
            $plain = '';
            foreach ($x->query('w:t', $r) as $t) $plain .= $t->textContent;
            $isAr = preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FEFF}]/u', $plain) || !empty($rs['rtl']) || !empty($rs['cs']);
            $rtl = $isAr || $bidi;
            $szH = $isAr ? ($rs['szCs'] ?? $rs['sz'] ?? 22) : ($rs['sz'] ?? $rs['szCs'] ?? 22);
            $bold = $isAr ? !empty($rs['bCs'] ?? $rs['b'] ?? false) || !empty($rs['b']) : !empty($rs['b']);
            // قلم: قلمِ Word اگر در دسترس است و همه‌ی نویسه‌ها را دارد، وگرنه وزیر
            $fn = $isAr ? ($rs['fc'] ?? $rs['fa'] ?? '') : ($rs['fa'] ?? $rs['fc'] ?? '');
            $font = $fn !== '' ? $this->fontFor($fn) : null;
            $shown = $this->digits(!empty($rs['caps']) ? mb_strtoupper($plain) : $plain, $rtl);
            if ($font && !self::covers($font, $shown)) $font = null;
            $pt = round($szH / 2 * ($font ? 1 : $this->fontScale), 1);
            $st = 'font-size:' . $pt . 'pt;' . ($font ? 'font-family:' . $font['fam'] . ';' : '');
            if (($c = self::hex($rs['color'] ?? null))) $st .= 'color:' . $c . ';';
            if (!empty($rs['u'])) $st .= 'text-decoration:underline;';
            if (!empty($rs['strike'])) $st .= 'text-decoration:line-through;';
            $bg = self::hex($rs['shd'] ?? null) ?: ['yellow' => '#FFFF00', 'green' => '#00FF00', 'cyan' => '#00FFFF', 'lightGray' => '#D3D3D3', 'red' => '#FF0000', 'magenta' => '#FF00FF', 'darkGray' => '#A9A9A9', 'blue' => '#0000FF'][$rs['hl'] ?? ''] ?? null;
            if ($bg) $st .= 'background-color:' . $bg . ';';
            if (($rs['va'] ?? '') === 'superscript' || ($rs['va'] ?? '') === 'subscript') $st .= 'font-size:' . round($pt * 0.65, 1) . 'pt;';
            $txt = '';
            // runهای پشتِ‌سرهم با قالبِ یکسان یک تکه می‌شوند (جهتِ متن مثلِ Word یک‌جا حساب شود؛ «{{ name }}» وارونه نشود)
            $emit = function () use (&$txt, &$segs, &$maxPt, &$lh, &$lastKey, $st, $bold, $font, $pt) {
                if ($txt === '') return;
                $maxPt = max($maxPt, $pt); $lh = max($lh, $font ? $font['lh'] : 1.25);
                $open = $bold ? ($font && !$font['bold'] ? '<b stroke="0.18">' : '<b>') : '';
                $close = ($bold ? '</b>' : '') . '</span>';
                $i = count($segs) - 1; $key = $st . '|' . $open;
                if ($lastKey === $key && substr($segs[$i], -strlen($close)) === $close) $segs[$i] = substr($segs[$i], 0, -strlen($close)) . $txt . $close;
                else $segs[$i] .= '<span style="' . $st . '">' . $open . $txt . $close;
                $lastKey = $key;
                $txt = '';
            };
            foreach ($r->childNodes as $c) {
                if (!($c instanceof DOMElement)) continue;
                $ln = $c->localName;
                // فیلدهای پیچیده: شماره‌ی صفحه به‌جای مقدارِ ذخیره‌شده؛ بقیه همان مقدارِ ذخیره‌شده
                if ($ln === 'fldChar') {
                    $ft = $c->getAttributeNS(self::W, 'fldCharType');
                    if ($ft === 'begin') $fld = ['instr' => '', 'res' => false];
                    elseif ($ft === 'separate' && $fld) $fld['res'] = true;
                    elseif ($ft === 'end' && $fld) {
                        if (preg_match('/^\s*(PAGE|NUMPAGES|SECTIONPAGES)\b/i', $fld['instr'], $m)) $txt .= strtoupper($m[1]) === 'PAGE' ? $this->pdf->getAliasNumPage() : $this->pdf->getAliasNbPages();
                        $fld = null;
                    }
                    continue;
                }
                if ($ln === 'instrText') { if ($fld) $fld['instr'] .= $c->textContent; continue; }
                if ($fld && (!$fld['res'] || preg_match('/^\s*(PAGE|NUMPAGES|SECTIONPAGES)\b/i', $fld['instr']))) continue;
                if ($ln === 't') {
                    $tt = $this->esc($this->digits(!empty($rs['caps']) ? mb_strtoupper($c->textContent) : $c->textContent, $rtl));
                    // متنِ لاتینِ بدونِ پرچمِ rtl در پاراگرافِ راست‌چین: مثلِ Word چپ‌به‌راست ({{ name }} وارونه نشود)
                    if ($bidi && !$isAr && empty($rs['rtl']) && preg_match('/[A-Za-z0-9]/', $tt)) $tt = "\u{200E}" . $tt . "\u{200E}";
                    $txt .= $tt;
                }
                elseif ($ln === 'tab' || $ln === 'ptab') { $emit(); $segs[] = ''; $lastKey = null; }
                elseif ($ln === 'br') { if ($c->getAttributeNS(self::W, 'type') === 'page') $pageBreak = true; else $txt .= '<br />'; }
                elseif ($ln === 'cr') $txt .= '<br />';
                elseif ($ln === 'noBreakHyphen') $txt .= '-';
                elseif ($ln === 'sym') $txt .= '&#x' . $c->getAttributeNS(self::W, 'char') . ';';
                elseif ($ln === 'drawing' || $ln === 'AlternateContent' || $ln === 'pict' || $ln === 'object') {
                    foreach ($this->drawingsOf($c, $x, $rels) as $o) {
                        if ($o['inline'] || $inCell) {
                            $img = count($o['items']) === 1 && !empty($o['items'][0]['img']) ? $o['items'][0]['img'] : null;
                            if ($img) { $emit(); $lastKey = null; $segs[count($segs) - 1] .= '<img src="' . $this->esc($img) . '" width="' . round($o['w'], 2) . 'mm" height="' . round($o['h'], 2) . 'mm" />'; $maxPt = max($maxPt, 1); }
                            elseif ($inCell && !$o['inline']) $anchors[] = $o;
                        } else $anchors[] = $o;
                    }
                }
            }
            $emit();
        }
        if ($maxPt == 0) $maxPt = round((($bidi ? ($baseR['szCs'] ?? $baseR['sz'] ?? 22) : ($baseR['sz'] ?? 22)) / 2) * $this->fontScale, 1);
        $pp['bidi'] = $bidi; $pp['pt'] = $maxPt; $pp['pageBreak'] = $pageBreak; $pp['lh'] = $lh ?: 1.25;
        $pp['align'] = $this->align($pp['jc'] ?? '', $bidi);
        $pp['tabsAt'] = $tabs;
        $pp['segs'] = count($segs) > 1 ? $segs : null;
        return [implode(self::TAB_MARK, $segs), $pp, $anchors];
    }
    // ضریبِ فاصله‌ی سطرها مثلِ Word (چندبرابر / دقیق / حداقل)
    private static function lineRatio(array $pp) {
        $line = intval($pp['line'] ?? 240); $rule = $pp['lineRule'] ?? 'auto';
        if ($rule === 'auto') return max(0.8, $pp['lh'] * max(0.6, $line / 240));
        $r = ($line / 20) / max(4, $pp['pt']);
        return $rule === 'exact' ? max(0.6, min(4, $r)) : max($pp['lh'], min(4, $r));
    }
    private function align($jc, $bidi) {
        if ($jc === 'center') return 'center';
        if ($jc === 'both' || $jc === 'distribute') return 'justify';
        if ($bidi) return in_array($jc, ['right', 'end'], true) ? 'left' : 'right';
        return in_array($jc, ['right', 'end'], true) ? 'right' : 'left';
    }
    // همه‌ی runهای پاراگراف به ترتیب (داخلِ لینک، فیلد، کنترل‌های محتوا، تگ‌های هوشمند)؛ فیلدِ سادهِ PAGE => رشته
    private function runs(DOMElement $p, DOMXPath $x) {
        $o = [];
        $walk = function ($n) use (&$walk, &$o) {
            foreach ($n->childNodes as $c) {
                if (!($c instanceof DOMElement)) continue;
                if ($c->namespaceURI === self::W && $c->localName === 'r') $o[] = $c;
                elseif ($c->namespaceURI === self::W && $c->localName === 'fldSimple' && preg_match('/^\s*(PAGE|NUMPAGES|SECTIONPAGES)\b/i', $c->getAttributeNS(self::W, 'instr'), $m)) $o[] = strtoupper($m[1]) === 'PAGE' ? 'PAGE' : 'NUMPAGES';
                elseif ($c->namespaceURI === self::W && in_array($c->localName, ['hyperlink', 'fldSimple', 'smartTag', 'customXml', 'sdt', 'sdtContent', 'ins', 'moveTo', 'dir', 'bdo'], true)) $walk($c);
                elseif ($c->namespaceURI === self::NS['mc'] && $c->localName === 'AlternateContent') { $ch = self::kid($c, self::NS['mc'], 'Choice') ?: self::kid($c, self::NS['mc'], 'Fallback'); if ($ch) $walk($ch); }
            }
        };
        $walk($p);
        return $o;
    }

    // ---------------- عکس‌ها، کادرها و شکل‌ها ----------------
    // شیءهای یک run: DrawingML (فقط mc:Choice تا تکراری نشود) یا VMLِ قدیمی
    private function drawingsOf(DOMElement $c, DOMXPath $x, array $rels) {
        $out = [];
        if ($c->localName === 'AlternateContent') {
            $ch = self::kid($c, self::NS['mc'], 'Choice');
            $dr = $ch ? $x->query('w:drawing/wp:inline|w:drawing/wp:anchor', $ch) : null;
            if ($dr && $dr->length) { foreach ($dr as $d) if (($o = $this->drawing($d, $x, $rels))) $out[] = $o; return $out; }
            $fb = self::kid($c, self::NS['mc'], 'Fallback');
            if ($fb) foreach ($fb->childNodes as $n) if ($n instanceof DOMElement) $out = array_merge($out, $this->drawingsOf($n, $x, $rels));
            return $out;
        }
        if ($c->localName === 'drawing') { foreach ($x->query('wp:inline|wp:anchor', $c) as $d) if (($o = $this->drawing($d, $x, $rels))) $out[] = $o; return $out; }
        // VML: w:pict / w:object
        foreach ($c->childNodes as $n) if ($n instanceof DOMElement && $n->namespaceURI === self::NS['v'] && in_array($n->localName, ['shape', 'rect', 'roundrect', 'oval', 'line', 'group', 'image'], true)) {
            if (($o = $this->vml($n, $x, $rels))) $out[] = $o;
        }
        return $out;
    }
    // شیءِ DrawingML => [inline, w, h, behind, items[], relH/offH/alH, relV/offV/alV]
    private function drawing(DOMElement $dr, DOMXPath $x, array $rels) {
        $ext = $x->query('wp:extent', $dr)->item(0);
        if (!$ext) return null;
        $w = self::emu($ext->getAttribute('cx')); $h = self::emu($ext->getAttribute('cy'));
        $o = ['inline' => $dr->localName === 'inline', 'w' => $w, 'h' => $h, 'behind' => $dr->getAttribute('behindDoc') === '1', 'items' => [], 'z' => intval($dr->getAttribute('relativeHeight'))];
        $gd = $x->query('a:graphic/a:graphicData', $dr)->item(0);
        if ($gd) foreach ($gd->childNodes as $n) {
            if (!($n instanceof DOMElement)) continue;
            $this->dmlItem($n, $x, $rels, [0, 0, $w, $h], $o['items']);
        }
        if (!$o['items']) return null;
        if (!$o['inline']) {
            foreach (['H' => 'positionH', 'V' => 'positionV'] as $k => $tag) {
                $pn = $x->query('wp:' . $tag, $dr)->item(0);
                $o['rel' . $k] = $pn ? $pn->getAttribute('relativeFrom') : 'page';
                $off = $pn ? $x->query('wp:posOffset', $pn)->item(0) : null;
                $al = $pn ? $x->query('wp:align', $pn)->item(0) : null;
                $o['off' . $k] = $off ? self::emu($off->textContent) : 0;
                $o['al' . $k] = $al ? trim($al->textContent) : null;
            }
        }
        return $o;
    }
    // یک عضو (عکس / شکل / کادرِ متنی / گروه) در جعبه‌ی [x, y, w, h] میلی‌متر
    private function dmlItem(DOMElement $n, DOMXPath $x, array $rels, array $box, array &$items) {
        $ln = $n->localName;
        if ($ln === 'pic') {
            $blip = $x->query('.//a:blip', $n)->item(0);
            $img = $blip ? $this->image($blip->getAttributeNS(self::NS['r'], 'embed'), $rels) : null;
            if (!$img) return;
            $it = ['x' => $box[0], 'y' => $box[1], 'w' => $box[2], 'h' => $box[3], 'img' => $img, 'alpha' => $this->blipAlpha($blip, $x)];
            $sr = $x->query('pic:blipFill/a:srcRect', $n)->item(0);
            if ($sr) $it['crop'] = array_map(function ($k) use ($sr) { return intval($sr->getAttribute($k)) / 100000; }, ['l', 't', 'r', 'b']);
            $xf = $x->query('pic:spPr/a:xfrm', $n)->item(0);
            if ($xf && intval($xf->getAttribute('rot'))) $it['rot'] = intval($xf->getAttribute('rot')) / 60000;
            $items[] = $it;
            return;
        }
        if ($ln === 'wsp') { $this->shapeItem($n, $x, $rels, $box, $items); return; }
        if ($ln === 'wgp' || $ln === 'grpSp') {
            $xf = $x->query('wpg:grpSpPr/a:xfrm', $n)->item(0);
            $chO = $xf ? $x->query('a:chOff', $xf)->item(0) : null; $chE = $xf ? $x->query('a:chExt', $xf)->item(0) : null;
            $cx = $chO ? self::emu($chO->getAttribute('x')) : 0; $cy = $chO ? self::emu($chO->getAttribute('y')) : 0;
            $cw = $chE ? max(0.01, self::emu($chE->getAttribute('cx'))) : $box[2]; $chh = $chE ? max(0.01, self::emu($chE->getAttribute('cy'))) : $box[3];
            $sx = $box[2] / $cw; $sy = $box[3] / $chh;
            foreach ($n->childNodes as $c) {
                if (!($c instanceof DOMElement) || !in_array($c->localName, ['wsp', 'pic', 'grpSp'], true)) continue;
                $cxf = $x->query('wps:spPr/a:xfrm|pic:spPr/a:xfrm|wpg:grpSpPr/a:xfrm', $c)->item(0);
                $off = $cxf ? $x->query('a:off', $cxf)->item(0) : null; $ex = $cxf ? $x->query('a:ext', $cxf)->item(0) : null;
                if (!$off || !$ex) continue;
                $this->dmlItem($c, $x, $rels, [$box[0] + (self::emu($off->getAttribute('x')) - $cx) * $sx, $box[1] + (self::emu($off->getAttribute('y')) - $cy) * $sy,
                                               self::emu($ex->getAttribute('cx')) * $sx, self::emu($ex->getAttribute('cy')) * $sy], $items);
            }
        }
    }
    private function blipAlpha(?DOMElement $blip, DOMXPath $x) {
        $a = $blip ? $x->query('a:alphaModFix', $blip)->item(0) : null;
        return $a ? max(0.05, min(1, intval($a->getAttribute('amt')) / 100000)) : 1;
    }
    private function shapeItem(DOMElement $wsp, DOMXPath $x, array $rels, array $box, array &$items) {
        $sp = $x->query('wps:spPr', $wsp)->item(0);
        $style = $x->query('wps:style', $wsp)->item(0);
        $geom = ($g = $x->query('a:prstGeom', $sp)->item(0)) ? $g->getAttribute('prst') : 'rect';
        $xf = $x->query('a:xfrm', $sp)->item(0);
        $it = ['x' => $box[0], 'y' => $box[1], 'w' => $box[2], 'h' => $box[3], 'geom' => $geom, 'fill' => null, 'falpha' => 1, 'line' => null,
               'flipH' => $xf && $xf->getAttribute('flipH') === '1', 'flipV' => $xf && $xf->getAttribute('flipV') === '1', 'rot' => $xf ? intval($xf->getAttribute('rot')) / 60000 : 0];
        if (($cg = $x->query('a:custGeom', $sp)->item(0))) $it['paths'] = $this->custGeom($cg, $x, $box[2], $box[3]);
        // پُر کردن
        $fillEl = null; $noFill = false;
        foreach ($sp ? $sp->childNodes : [] as $c) if ($c instanceof DOMElement) {
            if ($c->localName === 'noFill') $noFill = true;
            elseif (in_array($c->localName, ['solidFill', 'gradFill', 'blipFill', 'pattFill'], true)) $fillEl = $c;
        }
        if ($fillEl && $fillEl->localName === 'blipFill') {
            $blip = $x->query('a:blip', $fillEl)->item(0);
            if ($blip && ($img = $this->image($blip->getAttributeNS(self::NS['r'], 'embed'), $rels))) $it['img'] = $img;
        } elseif ($fillEl) {
            $src = $fillEl->localName === 'gradFill' ? $x->query('a:gsLst/a:gs', $fillEl)->item(0) : ($fillEl->localName === 'pattFill' ? $x->query('a:fgClr', $fillEl)->item(0) : $fillEl);
            [$it['fill'], $it['falpha']] = $this->dmlColor($src);
            if ($fillEl->localName === 'gradFill') {
                $stops = [];
                foreach ($x->query('a:gsLst/a:gs', $fillEl) as $gs) { [$gc] = $this->dmlColor($gs); if ($gc) $stops[] = [max(0, min(1, intval($gs->getAttribute('pos')) / 100000)), self::rgb($gc)]; }
                usort($stops, function ($a, $b) { return $a[0] <=> $b[0]; });
                $lin = $x->query('a:lin', $fillEl)->item(0);
                if (count($stops) >= 2) $it['grad'] = ['stops' => $stops, 'ang' => $lin ? intval($lin->getAttribute('ang')) / 60000 : 90];
            }
        } elseif (!$noFill && $style && ($fr = $x->query('a:fillRef', $style)->item(0)) && intval($fr->getAttribute('idx')) > 0) [$it['fill'], $it['falpha']] = $this->dmlColor($fr);
        // خطِ دور
        $lnEl = $sp ? $x->query('a:ln', $sp)->item(0) : null;
        $lnRef = $style ? $x->query('a:lnRef', $style)->item(0) : null;
        $lw = $lnEl && $lnEl->getAttribute('w') !== '' ? self::emu($lnEl->getAttribute('w')) : 0.26;
        if ($lnEl && $x->query('a:noFill', $lnEl)->length) $it['line'] = null;
        elseif ($lnEl && ($sf = $x->query('a:solidFill', $lnEl)->item(0))) { [$lc] = $this->dmlColor($sf); if ($lc) $it['line'] = [$lc, $lw]; }
        elseif ($lnRef && intval($lnRef->getAttribute('idx')) > 0) { [$lc] = $this->dmlColor($lnRef); if ($lc) $it['line'] = [$lc, $lw]; }
        // متنِ داخلِ کادر
        $tb = $x->query('wps:txbx/w:txbxContent', $wsp)->item(0);
        if ($tb) {
            $bp = $x->query('wps:bodyPr', $wsp)->item(0);
            $ins = function ($a, $d) use ($bp) { $v = $bp ? $bp->getAttribute($a) : ''; return $v === '' ? $d : self::emu($v); };
            $it['ins'] = [$ins('lIns', 2.54), $ins('tIns', 1.27), $ins('rIns', 2.54), $ins('bIns', 1.27)];
            $it['va'] = $bp ? ($bp->getAttribute('anchor') ?: 't') : 't';
            $it['upright'] = $bp && $bp->getAttribute('upright') === '1';
            $it['html'] = $this->blocksHtml($tb, $x, $rels, max(5, $box[2] - $it['ins'][0] - $it['ins'][2]));
            if ($style && ($fr = $x->query('a:fontRef', $style)->item(0)) && ($fc = $this->dmlColor($fr)[0]) && strpos($it['html'], 'color:') === false) $it['color'] = $fc;
        }
        if (empty($it['img']) && !$it['fill'] && !$it['line'] && empty($it['html'])) return;
        $items[] = $it;
    }
    // شکلِ آزاد (منحنی‌های سربرگ) => [['cmds' => [...], 'fill' => bool, 'stroke' => bool], ...] در مختصاتِ داخلِ جعبه (میلی‌متر)
    private function custGeom(DOMElement $cg, DOMXPath $x, $w, $h) {
        $out = [];
        foreach ($x->query('a:pathLst/a:path', $cg) as $p) {
            $pw = floatval($p->getAttribute('w')); $ph = floatval($p->getAttribute('h'));
            $sx = $pw > 0 ? $w / $pw : 1 / 36000; $sy = $ph > 0 ? $h / $ph : 1 / 36000;
            $cmds = []; $cur = [0, 0]; $ok = true;
            $pt = function (DOMElement $e) use ($sx, $sy, &$ok) {
                $a = $e->getAttribute('x'); $b = $e->getAttribute('y');
                if (!is_numeric($a) || !is_numeric($b)) { $ok = false; return [0, 0]; }
                return [floatval($a) * $sx, floatval($b) * $sy];
            };
            foreach ($p->childNodes as $c) {
                if (!($c instanceof DOMElement)) continue;
                $pts = [];
                foreach ($c->childNodes as $q) if ($q instanceof DOMElement && $q->localName === 'pt') $pts[] = $pt($q);
                switch ($c->localName) {
                    case 'moveTo': if ($pts) { $cmds[] = ['M', $pts[0][0], $pts[0][1]]; $cur = $pts[0]; } break;
                    case 'lnTo': if ($pts) { $cmds[] = ['L', $pts[0][0], $pts[0][1]]; $cur = $pts[0]; } break;
                    case 'cubicBezTo': if (count($pts) === 3) { $cmds[] = ['C', $pts[0][0], $pts[0][1], $pts[1][0], $pts[1][1], $pts[2][0], $pts[2][1]]; $cur = $pts[2]; } break;
                    case 'quadBezTo': if (count($pts) === 2) {
                        $q1 = $pts[0]; $p2 = $pts[1];
                        $cmds[] = ['C', $cur[0] + 2 / 3 * ($q1[0] - $cur[0]), $cur[1] + 2 / 3 * ($q1[1] - $cur[1]), $p2[0] + 2 / 3 * ($q1[0] - $p2[0]), $p2[1] + 2 / 3 * ($q1[1] - $p2[1]), $p2[0], $p2[1]];
                        $cur = $p2; } break;
                    case 'arcTo':
                        $wr = $c->getAttribute('wR'); $hr = $c->getAttribute('hR'); $st = $c->getAttribute('stAng'); $sw = $c->getAttribute('swAng');
                        if (!is_numeric($wr) || !is_numeric($hr) || !is_numeric($st) || !is_numeric($sw)) { $ok = false; break; }
                        [$cmdsA, $cur] = self::arc($cur, floatval($wr) * $sx, floatval($hr) * $sy, floatval($st) / 60000, floatval($sw) / 60000);
                        $cmds = array_merge($cmds, $cmdsA); break;
                    case 'close': $cmds[] = ['Z']; break;
                }
                if (!$ok) break;
            }
            if ($ok && $cmds) $out[] = ['cmds' => $cmds, 'fill' => $p->getAttribute('fill') !== 'none', 'stroke' => !in_array($p->getAttribute('stroke'), ['0', 'false'], true)];
        }
        return $out ?: null;
    }
    // کمانِ بیضی از نقطه‌ی فعلی (زاویه‌ها به درجه، ساعتگرد) => [فرمان‌ها، نقطه‌ی پایانی]
    private static function arc(array $cur, $wr, $hr, $st, $sw) {
        $a0 = deg2rad($st);
        $cx = $cur[0] - $wr * cos($a0); $cy = $cur[1] - $hr * sin($a0);
        $n = max(1, (int)ceil(abs($sw) / 90)); $d = deg2rad($sw) / $n; $cmds = [];
        for ($i = 0; $i < $n; $i++) {
            $t1 = $a0 + $d * $i; $t2 = $t1 + $d; $k = 4 / 3 * tan($d / 4);
            $p1 = [$cx + $wr * cos($t1), $cy + $hr * sin($t1)]; $p2 = [$cx + $wr * cos($t2), $cy + $hr * sin($t2)];
            $cmds[] = ['C', $p1[0] - $k * $wr * sin($t1), $p1[1] + $k * $hr * cos($t1), $p2[0] + $k * $wr * sin($t2), $p2[1] - $k * $hr * cos($t2), $p2[0], $p2[1]];
        }
        return [$cmds, [$cx + $wr * cos($a0 + deg2rad($sw)), $cy + $hr * sin($a0 + deg2rad($sw))]];
    }
    // شکل‌های آماده => مسیر (مختصاتِ مطلق)
    private static function geomPath($geom, $x, $y, $w, $h) {
        $L = function ($pts) { $c = [['M', $pts[0][0], $pts[0][1]]]; for ($i = 1; $i < count($pts); $i++) $c[] = ['L', $pts[$i][0], $pts[$i][1]]; $c[] = ['Z']; return $c; };
        switch ($geom) {
            case 'ellipse': case 'flowChartConnector':
                $k = 0.5523; $rx = $w / 2; $ry = $h / 2; $cx = $x + $rx; $cy = $y + $ry;
                return [['M', $cx + $rx, $cy], ['C', $cx + $rx, $cy + $k * $ry, $cx + $k * $rx, $cy + $ry, $cx, $cy + $ry], ['C', $cx - $k * $rx, $cy + $ry, $cx - $rx, $cy + $k * $ry, $cx - $rx, $cy],
                        ['C', $cx - $rx, $cy - $k * $ry, $cx - $k * $rx, $cy - $ry, $cx, $cy - $ry], ['C', $cx + $k * $rx, $cy - $ry, $cx + $rx, $cy - $k * $ry, $cx + $rx, $cy], ['Z']];
            case 'roundRect': case 'flowChartAlternateProcess':
                $r = min($w, $h) * 0.1667; $k = 0.5523 * $r;
                return [['M', $x + $r, $y], ['L', $x + $w - $r, $y], ['C', $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r], ['L', $x + $w, $y + $h - $r],
                        ['C', $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h], ['L', $x + $r, $y + $h], ['C', $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r],
                        ['L', $x, $y + $r], ['C', $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y], ['Z']];
            case 'diamond': case 'flowChartDecision': return $L([[$x + $w / 2, $y], [$x + $w, $y + $h / 2], [$x + $w / 2, $y + $h], [$x, $y + $h / 2]]);
            case 'triangle': return $L([[$x + $w / 2, $y], [$x + $w, $y + $h], [$x, $y + $h]]);
            case 'rtTriangle': return $L([[$x, $y], [$x + $w, $y + $h], [$x, $y + $h]]);
            case 'parallelogram': return $L([[$x + $w * .25, $y], [$x + $w, $y], [$x + $w * .75, $y + $h], [$x, $y + $h]]);
            case 'trapezoid': return $L([[$x + $w * .25, $y], [$x + $w * .75, $y], [$x + $w, $y + $h], [$x, $y + $h]]);
            case 'hexagon': return $L([[$x + $w * .25, $y], [$x + $w * .75, $y], [$x + $w, $y + $h / 2], [$x + $w * .75, $y + $h], [$x + $w * .25, $y + $h], [$x, $y + $h / 2]]);
            case 'homePlate': return $L([[$x, $y], [$x + $w * .8, $y], [$x + $w, $y + $h / 2], [$x + $w * .8, $y + $h], [$x, $y + $h]]);
            default: return $L([[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]]);
        }
    }
    // VML (قالب‌های قدیمیِ Word: سربرگِ تصویری، واترمارک، کادرِ متنی)
    private function vml(DOMElement $el, DOMXPath $x, array $rels) {
        $st = [];
        foreach (explode(';', $el->getAttribute('style')) as $kv) { $p = explode(':', $kv, 2); if (count($p) === 2) $st[strtolower(trim($p[0]))] = trim($p[1]); }
        $len = function ($v) {
            if (!preg_match('/^(-?[\d.]+)\s*(pt|in|cm|mm|px|emu)?$/i', trim((string)$v), $m)) return 0;
            $n = floatval($m[1]);
            switch (strtolower($m[2] ?? '')) { case 'in': return $n * 25.4; case 'cm': return $n * 10; case 'mm': return $n; case 'px': return $n * 25.4 / 96; case 'emu': return $n / 36000; default: return $n * 25.4 / 72; }
        };
        $w = $len($st['width'] ?? 0); $h = $len($st['height'] ?? 0);
        if ($w <= 0 || $h <= 0) return null;
        $abs = ($st['position'] ?? '') === 'absolute';
        $o = ['inline' => !$abs, 'w' => $w, 'h' => $h, 'behind' => intval($st['z-index'] ?? 0) < 0, 'items' => [], 'z' => intval($st['z-index'] ?? 0)];
        $col = function ($v, $def) {
            $v = strtolower(trim(preg_split('/\s+/', (string)$v)[0] ?? ''));
            if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v, $m)) return '#' . strtoupper(strlen($m[1]) === 3 ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1]);
            return ['black' => '#000000', 'white' => '#FFFFFF', 'red' => '#FF0000', 'blue' => '#0000FF', 'green' => '#008000', 'yellow' => '#FFFF00', 'gray' => '#808080', 'silver' => '#C0C0C0', 'navy' => '#000080'][$v] ?? $def;
        };
        $it = ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'geom' => $el->localName === 'oval' ? 'ellipse' : ($el->localName === 'roundrect' ? 'roundRect' : 'rect'), 'fill' => null, 'falpha' => 1, 'line' => null];
        $imd = $x->query('v:imagedata', $el)->item(0);
        if ($imd) {
            $rid = $imd->getAttributeNS(self::NS['r'], 'id') ?: $imd->getAttributeNS(self::NS['o'], 'relid');
            if ($rid && ($img = $this->image($rid, $rels))) { $it['img'] = $img; if ($imd->getAttribute('gain') !== '' || $imd->getAttribute('blacklevel') !== '') $it['alpha'] = 0.35; }
        }
        $tb = $x->query('v:textbox/w:txbxContent', $el)->item(0);
        $isTb = (bool)$tb;
        if ($tb) {
            $in = array_map($len, array_pad(explode(',', (string)$x->query('v:textbox', $el)->item(0)->getAttribute('inset')), 4, ''));
            $it['ins'] = [$in[0] ?: 2.54, $in[1] ?: 1.27, $in[2] ?: 2.54, $in[3] ?: 1.27];
            $it['va'] = ['middle' => 'ctr', 'bottom' => 'b'][$st['v-text-anchor'] ?? ''] ?? 't';
            $it['html'] = $this->blocksHtml($tb, $x, $rels, max(5, $w - $it['ins'][0] - $it['ins'][2]));
        }
        if (empty($it['img']) && $el->getAttribute('filled') !== 'f' && $el->getAttribute('filled') !== 'false' && ($el->getAttribute('fillcolor') !== '' || $isTb)) $it['fill'] = $col($el->getAttribute('fillcolor'), '#FFFFFF');
        if (empty($it['img']) && $el->getAttribute('stroked') !== 'f' && $el->getAttribute('stroked') !== 'false' && ($el->localName !== 'shape' || $isTb || $el->getAttribute('strokecolor') !== ''))
            $it['line'] = [$col($el->getAttribute('strokecolor'), '#000000'), $len($el->getAttribute('strokeweight') ?: '0.75pt') ?: 0.26];
        if (empty($it['img']) && !$it['fill'] && !$it['line'] && empty($it['html'])) return null;
        $o['items'][] = $it;
        if ($abs) {
            $rel = function ($v, $vert) { $v = strtolower((string)$v); if ($v === 'page') return 'page'; if ($v === 'margin') return 'margin'; return $vert ? ($v === 'line' ? 'line' : 'paragraph') : 'column'; };
            $o['relH'] = $rel($st['mso-position-horizontal-relative'] ?? '', false); $o['relV'] = $rel($st['mso-position-vertical-relative'] ?? '', true);
            $o['offH'] = $len($st['margin-left'] ?? ($st['left'] ?? 0)); $o['offV'] = $len($st['margin-top'] ?? ($st['top'] ?? 0));
            $ah = strtolower($st['mso-position-horizontal'] ?? ''); $av = strtolower($st['mso-position-vertical'] ?? '');
            $o['alH'] = in_array($ah, ['center', 'left', 'right', 'inside', 'outside'], true) ? $ah : null;
            $o['alV'] = in_array($av, ['center', 'top', 'bottom'], true) ? $av : null;
        }
        return $o;
    }
    // جای واقعیِ یک شیءِ شناور روی صفحه (میلی‌متر از چپ/بالا)
    private function place(array $o, $paraY = null) {
        $g = $this->pg;
        switch ($o['relH'] ?? 'page') {
            case 'page': $refX = [0, $g['w']]; break;
            case 'leftMargin': $refX = [0, $g['ml']]; break;
            case 'rightMargin': $refX = [$g['w'] - $g['mr'], $g['w']]; break;
            default: $refX = [$g['ml'], $g['w'] - $g['mr']];
        }
        switch ($o['relV'] ?? 'page') {
            case 'page': $refY = [0, $g['h']]; break;
            case 'topMargin': $refY = [0, $g['mt']]; break;
            case 'bottomMargin': $refY = [$g['h'] - $g['mb'], $g['h']]; break;
            case 'paragraph': case 'line': $refY = [$paraY ?? $g['mt'], $g['h'] - $g['mb']]; break;
            default: $refY = [$g['mt'], $g['h'] - $g['mb']];
        }
        $alH = $o['alH'] ?? null; $alV = $o['alV'] ?? null;
        if ($alH) $xx = $alH === 'center' ? ($refX[0] + $refX[1] - $o['w']) / 2 : (in_array($alH, ['right', 'outside'], true) ? $refX[1] - $o['w'] : $refX[0]);
        else $xx = $refX[0] + ($o['offH'] ?? 0);
        if ($alV) $yy = $alV === 'center' ? ($refY[0] + $refY[1] - $o['h']) / 2 : ($alV === 'bottom' ? $refY[1] - $o['h'] : $refY[0]);
        else $yy = $refY[0] + ($o['offV'] ?? 0);
        return [$xx, $yy];
    }
    public function drawAnchor(DocxPdfDoc $pdf, array $o, $paraY = null) {
        [$xx, $yy] = $this->place($o, $paraY);
        $auto = $pdf->getAutoPageBreak(); $bm = $pdf->getBreakMargin(); $rtl = $pdf->getRTL();
        $pdf->SetAutoPageBreak(false, 0); $pdf->setRTL(false);
        foreach ($o['items'] as $it) $this->drawItem($pdf, $it, $xx + $it['x'], $yy + $it['y']);
        $pdf->setRTL($rtl); $pdf->SetAutoPageBreak($auto, $bm);
    }
    private function drawItem(DocxPdfDoc $pdf, array $it, $x0, $y0) {
        $w = $it['w']; $h = $it['h'];
        $rot = fmod($it['rot'] ?? 0, 360);
        if (abs($rot) > 0.01) { $pdf->StartTransform(); $pdf->Rotate(-$rot, $x0 + $w / 2, $y0 + $h / 2); }
        if (!empty($it['img'])) {
            if (($it['alpha'] ?? 1) < 1) $pdf->setAlpha($it['alpha']);
            try {
                $c = $it['crop'] ?? null;
                if ($c && array_sum($c) > 0.001 && $c[0] + $c[2] < 0.99 && $c[1] + $c[3] < 0.99) {
                    // عکسِ بریده‌شده در Word: کلِ عکس بزرگ‌تر کشیده و به جعبه بریده می‌شود
                    $fw = $w / (1 - $c[0] - $c[2]); $fh = $h / (1 - $c[1] - $c[3]);
                    $pdf->StartTransform(); $pdf->Rect($x0, $y0, $w, $h, 'CNZ');
                    $pdf->Image($it['img'], $x0 - $c[0] * $fw, $y0 - $c[1] * $fh, $fw, $fh, '', '', '', false, 300, '', false, false, 0, false, false, false);
                    $pdf->StopTransform();
                } else $pdf->Image($it['img'], $x0, $y0, $w, $h, '', '', '', false, 300, '', false, false, 0, false, false, false);
            } catch (Throwable $e) {}
            if (($it['alpha'] ?? 1) < 1) $pdf->setAlpha(1);
        } elseif (!empty($it['fill']) || !empty($it['line']) || !empty($it['grad'])) {
            if (in_array($it['geom'], ['line', 'straightConnector1', 'bentConnector2', 'curvedConnector3'], true) && empty($it['paths'])) {
                $ls = $it['line'] ? ['width' => max(0.05, $it['line'][1]), 'color' => self::rgb($it['line'][0]), 'dash' => 0, 'cap' => 'butt', 'join' => 'miter'] : [];
                if ($it['line']) { $xa = !empty($it['flipH']) ? $x0 + $w : $x0; $ya = !empty($it['flipV']) ? $y0 + $h : $y0; $pdf->Line($xa, $ya, $x0 + $w - ($xa - $x0), $y0 + $h - ($ya - $y0), $ls); }
            } else {
                // مسیر: شکلِ آزاد یا شکلِ آماده؛ وارونگیِ افقی/عمودی هم اعمال می‌شود
                $paths = [];
                if (!empty($it['paths'])) foreach ($it['paths'] as $p) {
                    $cm = [];
                    foreach ($p['cmds'] as $c) {
                        if ($c[0] === 'Z') { $cm[] = $c; continue; }
                        $q = [$c[0]];
                        for ($i = 1; $i < count($c); $i += 2) { $q[] = $x0 + (!empty($it['flipH']) ? $w - $c[$i] : $c[$i]); $q[] = $y0 + (!empty($it['flipV']) ? $h - $c[$i + 1] : $c[$i + 1]); }
                        $cm[] = $q;
                    }
                    $paths[] = ['cmds' => $cm, 'fill' => $p['fill'], 'stroke' => $p['stroke']];
                } else $paths[] = ['cmds' => self::geomPath($it['geom'], $x0, $y0, $w, $h), 'fill' => true, 'stroke' => true];
                if ($it['falpha'] < 1) $pdf->setAlpha(1, 'Normal', $it['falpha']);
                foreach ($paths as $p) {
                    if ($p['fill'] && !empty($it['grad'])) {
                        $a = deg2rad($it['grad']['ang']);
                        $pdf->dxGradientPath($p['cmds'], [$x0, $y0, $w, $h], $it['grad']['stops'], [.5 - .5 * cos($a), .5 + .5 * sin($a), .5 + .5 * cos($a), .5 - .5 * sin($a)]);
                    } elseif ($p['fill'] && $it['fill']) { $pdf->SetFillColorArray(self::rgb($it['fill'])); $pdf->dxPath($p['cmds'], 'f'); }
                }
                if ($it['falpha'] < 1) $pdf->setAlpha(1);
                if ($it['line']) {
                    $pdf->SetDrawColorArray(self::rgb($it['line'][0])); $pdf->SetLineWidth(max(0.05, $it['line'][1]));
                    foreach ($paths as $p) if ($p['stroke']) $pdf->dxPath($p['cmds'], 'S');
                }
            }
        }
        if (!empty($it['html'])) {
            if (!empty($it['upright']) && abs($rot) > 0.01) { $pdf->StopTransform(); $rot = 0; }
            $in = $it['ins'] ?? [0, 0, 0, 0];
            $iw = max(2, $w - $in[0] - $in[2]); $ix = $x0 + $in[0]; $iy = $y0 + $in[1];
            $html = !empty($it['color']) ? '<div style="color:' . $it['color'] . ';">' . $it['html'] . '</div>' : $it['html'];
            // Word کلمه را نمی‌شکند و از کادر بیرون می‌زند؛ کادرِ خیلی باریک پهن‌تر می‌شود (راست‌چین: به سمتِ چپ)
            $need = $this->longestWord($html);
            if ($need > $iw) { $grow = $need - $iw; $iw = $need; $ix -= $this->docRtl ? $grow : (strpos($html, 'text-align:center') !== false ? $grow / 2 : 0); }
            $pdf->setRTL($this->docRtl);
            $pdf->SetFont('vazir', '', 10);
            $pdf->setCellHeightRatio(1.25);
            if (($it['va'] ?? 't') !== 't') {
                $room = $h - $in[1] - $in[3] - $this->htmlHeight($html, $iw);
                if ($room > 0) $iy += $it['va'] === 'ctr' ? $room / 2 : $room;
            }
            $pdf->writeHTMLCell($iw, 0, $this->docRtl ? $this->pg['w'] - $ix - $iw : $ix, $iy, $html, 0, 0, false, true, '', true);
            $pdf->setRTL(false);
        }
        if (abs($rot) > 0.01) $pdf->StopTransform();
    }
    // ارتفاعِ یک تکه HTML در پهنای $w (روی سندِ جداگانه‌ی سبک؛ بدونِ transaction که کلِ سند را کپی می‌کند)
    private function htmlHeight($html, $w) {
        try {
            if (!$this->meas || $this->measN++ > 40) {
                $m = new DocxPdfDoc('P', 'mm', [max(60, $w + 20), 1000]);
                $m->setPrintHeader(false); $m->setPrintFooter(false); $m->SetAutoPageBreak(false, 0); $m->setCellPaddings(0, 0, 0, 0);
                $m->setHtmlVSpace(['p' => [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]], 'div' => [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]], 'table' => [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]]]);
                $m->AddFont('vazir', '', 'vazir.php'); $m->AddFont('vazir', 'B', 'vazirb.php');
                foreach ($this->fonts as $f) if ($f) { $m->AddFont($f['fam'], '', $f['file']); $m->AddFont($f['fam'], 'B', $f['fileB']); }
                $this->meas = $m; $this->measN = 0;
            }
            $m = $this->meas;
            if ($w + 10 > $m->getPageWidth()) { $this->meas = null; $this->measN = 0; return $this->htmlHeight($html, $w); }
            $m->AddPage();
            $m->setRTL($this->docRtl); $m->SetFont('vazir', '', 10); $m->setCellHeightRatio(1.25);
            $m->writeHTMLCell($w, 0, 5, 5, $html, 0, 1, false, true, '', true);
            return max(0, $m->GetY() - 5);
        } catch (Throwable $e) { return 0; }
    }
    // پهنای بلندترین کلمه‌ی متنِ یک کادر (میلی‌متر)
    private function longestWord($html) {
        $max = 0;
        if (!preg_match_all('/<span style="([^"]*)">(.*?)<\/span>/su', $html, $m, PREG_SET_ORDER)) return 0;
        foreach ($m as $sp) {
            $pt = preg_match('/font-size:([\d.]+)pt/', $sp[1], $q) ? floatval($q[1]) : 10;
            $fam = preg_match('/font-family:([a-z0-9]+)/', $sp[1], $q) ? $q[1] : 'vazir';
            $txt = html_entity_decode(strip_tags($sp[2]), ENT_QUOTES, 'UTF-8');
            foreach (preg_split('/[\s\x{00A0}]+/u', $txt, -1, PREG_SPLIT_NO_EMPTY) as $wd) {
                try { $max = max($max, $this->pdf->GetStringWidth($wd, $fam, strpos($sp[2], '<b') !== false ? 'B' : '', $pt) * 1.03 + 0.4); } catch (Throwable $e) {}
            }
        }
        return $max;
    }

    // ---------------- جدول ----------------
    private function borderOf(?DOMElement $b) {
        if (!$b) return null;
        $v = $b->getAttributeNS(self::W, 'val');
        if ($v === '' || in_array($v, ['nil', 'none'], true)) return false;
        $sz = max(0.25, intval($b->getAttributeNS(self::W, 'sz')) / 8);
        if (in_array($v, ['double', 'thinThickSmallGap', 'thickThinSmallGap', 'triple'], true)) $sz = max($sz * 2, 1);
        return round($sz, 2) . 'pt ' . (in_array($v, ['dashed', 'dashSmallGap', 'dotDash', 'dotDotDash'], true) ? 'dashed' : ($v === 'dotted' ? 'dotted' : 'solid')) . ' ' . (self::hex($b->getAttributeNS(self::W, 'color')) ?: '#000000');
    }
    private function cellMar(?DOMElement $m, array $def) {
        if (!$m) return $def;
        foreach (['top' => 0, 'bottom' => 2, 'left' => 3, 'right' => 1, 'start' => 3, 'end' => 1] as $k => $i) {
            $e = $this->child($m, $k);
            if ($e && $e->getAttributeNS(self::W, 'type') !== 'nil' && $e->getAttributeNS(self::W, 'w') !== '') $def[$i] = self::mm(intval($e->getAttributeNS(self::W, 'w')));
        }
        return $def;
    }
    private function table(DOMElement $tbl, DOMXPath $x, array $rels, $availW) {
        $tblPr = $x->query('w:tblPr', $tbl)->item(0);
        $ts = $this->style(self::wv($x->query('w:tblPr/w:tblStyle', $tbl)->item(0)));
        $tb = $x->query('w:tblBorders', $tblPr)->item(0) ?: $ts['tblBorders'];
        $tbB = [];
        foreach (['top', 'bottom', 'left', 'right', 'start', 'end', 'insideH', 'insideV'] as $k) $tbB[$k] = $tb ? $this->borderOf($this->child($tb, $k)) : null;
        $bidiVisual = (bool)$x->query('w:bidiVisual', $tblPr)->length;
        $mar = $this->cellMar($x->query('w:tblCellMar', $tblPr)->item(0), $this->cellMar($ts['tblCellMar'], [0, 1.9, 0, 1.9]));   // top, right, bottom, left
        $grid = [];
        foreach ($x->query('w:tblGrid/w:gridCol', $tbl) as $gc) $grid[] = max(1, intval($gc->getAttributeNS(self::W, 'w')));
        $sumTw = array_sum($grid) ?: 1;
        $tblW = min($availW, self::mm($sumTw));
        $jc = self::wv($x->query('w:jc', $tblPr)->item(0));
        // ردیف‌ها و ادغامِ عمودی
        $rows = []; $hdrRows = 0; $rowH = [];
        foreach ($x->query('w:tr', $tbl) as $ri => $tr) {
            $ci = 0; $cells = [];
            $before = intval(self::wv($x->query('w:trPr/w:gridBefore', $tr)->item(0)));
            if ($before) { $cells[] = ['skip' => true, 'span' => $before, 'ci' => 0]; $ci = $before; }
            foreach ($x->query('w:tc', $tr) as $tc) {
                $span = max(1, intval(self::wv($x->query('w:tcPr/w:gridSpan', $tc)->item(0)) ?: 1));
                $vm = $x->query('w:tcPr/w:vMerge', $tc)->item(0);
                $cells[] = ['tc' => $tc, 'span' => $span, 'ci' => $ci, 'vm' => $vm ? ($vm->getAttributeNS(self::W, 'val') === 'restart' ? 'restart' : 'continue') : null];
                $ci += $span;
            }
            if ($x->query('w:trPr/w:tblHeader', $tr)->length && $ri === $hdrRows) $hdrRows++;
            $th = $x->query('w:trPr/w:trHeight', $tr)->item(0);
            $rowH[] = $th ? self::mm(intval($th->getAttributeNS(self::W, 'val'))) : 0;
            $rows[] = $cells;
        }
        // فاصله‌ی داخلیِ خانه‌ها (TCPDF فقط در سطحِ جدول می‌پذیرد)
        $html = '<table cellspacing="0" width="' . round($tblW, 2) . 'mm"' . ($jc === 'center' ? ' align="center"' : '')
              . sprintf(' style="padding-top:%.2fmm;padding-right:%.2fmm;padding-bottom:%.2fmm;padding-left:%.2fmm;">', $mar[0], $mar[1], $mar[2], $mar[3]);
        foreach ($rows as $ri => $cells) {
            if ($ri === 0 && $hdrRows) $html .= '<thead>';
            $html .= '<tr nobr="true">';
            foreach ($cells as $c) {
                if (!empty($c['skip'])) { $w = 0; for ($k = 0; $k < $c['span']; $k++) $w += $grid[$k] ?? 0; $html .= '<td width="' . round($w / $sumTw * 100, 3) . '%"></td>'; continue; }
                if ($c['vm'] === 'continue') continue;
                $rs = 1;
                if ($c['vm'] === 'restart') for ($k = $ri + 1; $k < count($rows); $k++) {
                    $hit = null; foreach ($rows[$k] as $cc) if (($cc['ci'] ?? -1) === $c['ci']) { $hit = $cc; break; }
                    if ($hit && ($hit['vm'] ?? null) === 'continue') $rs++; else break;
                }
                $w = 0; for ($k = $c['ci']; $k < $c['ci'] + $c['span']; $k++) $w += $grid[$k] ?? 0;
                $tcPr = $x->query('w:tcPr', $c['tc'])->item(0);
                $cb = $x->query('w:tcBorders', $tcPr)->item(0);
                $edge = function ($side, $inside) use ($cb, $tbB) {
                    $own = $cb ? $this->child($cb, $side) : null;
                    if (!$own && in_array($side, ['left', 'right'], true)) $own = $cb ? $this->child($cb, $side === 'left' ? 'start' : 'end') : null;
                    if ($own) return $this->borderOf($own);
                    $t = $tbB[$inside] ?? null;
                    if ($t === null && in_array($side, ['left', 'right'], true)) $t = $tbB[$side] ?? $tbB[$side === 'left' ? 'start' : 'end'] ?? null;
                    if ($t === null) $t = $tbB[$side] ?? null;
                    return $t;
                };
                $cm = $this->cellMar($x->query('w:tcMar', $tcPr)->item(0), $mar);
                $st = '';
                foreach (['top' => 'insideH', 'bottom' => 'insideH', 'left' => 'insideV', 'right' => 'insideV'] as $side => $ins) {
                    $bv = $edge($side, $ins);
                    if ($bv) $st .= 'border-' . $side . ':' . $bv . ';';
                }
                $shd = $x->query('w:shd', $tcPr)->item(0);
                $fill = self::hex($shd ? $shd->getAttributeNS(self::W, 'fill') : null);
                if ($fill) $st .= 'background-color:' . $fill . ';';
                $inner = $this->blocksHtml($c['tc'], $x, $rels, max(5, $tblW * $w / $sumTw - $cm[1] - $cm[3]));
                $hAttr = $rowH[$ri] > 0 && $rs === 1 ? ' height="' . round($rowH[$ri], 2) . 'mm"' : '';
                $html .= '<td width="' . round($w / $sumTw * 100, 3) . '%"' . ($c['span'] > 1 ? ' colspan="' . $c['span'] . '"' : '') . ($rs > 1 ? ' rowspan="' . $rs . '"' : '') . $hAttr . ' style="' . $st . '">' . ($inner === '' ? '&nbsp;' : $inner) . '</td>';
            }
            $html .= '</tr>';
            if ($hdrRows && $ri === $hdrRows - 1) $html .= '</thead>';
        }
        $html .= '</table>';
        return [$html, $bidiVisual];
    }
    // محتوای یک خانه‌ی جدول یا کادرِ متنی (پاراگراف‌ها و جدول‌های تودرتو) به HTML
    private function blocksHtml(DOMElement $parent, DOMXPath $x, array $rels, $availW) {
        $h = '';
        foreach ($parent->childNodes as $n) {
            if (!($n instanceof DOMElement) || $n->namespaceURI !== self::W) continue;
            if ($n->localName === 'p') {
                [$ph, $pp] = $this->paragraph($n, $x, $rels, true);
                if ($pp['segs']) $ph = $this->tabbed($pp, $availW, true) ?? $ph;
                $h .= '<div style="' . $this->paraCss($pp) . 'line-height:' . round(self::lineRatio($pp), 3) . ';"' . ($pp['bidi'] ? ' dir="rtl"' : '') . '>' . ($ph === '' ? '&nbsp;' : $ph) . '</div>';
                $after = self::mm($pp['after'] ?? 0);
                if ($after > 0.8 && $n->nextSibling) $h .= '<div style="font-size:' . round($after * 2.835 / 1.25, 1) . 'pt;line-height:1;">&nbsp;</div>';
            } elseif ($n->localName === 'tbl') { [$th] = $this->table($n, $x, $rels, $availW); $h .= $th; }
            elseif ($n->localName === 'sdt') { $c = $x->query('w:sdtContent', $n)->item(0); if ($c) $h .= $this->blocksHtml($c, $x, $rels, $availW); }
        }
        return $h;
    }
    private function paraCss(array $pp) {
        $st = 'text-align:' . $pp['align'] . ';font-size:' . $pp['pt'] . 'pt;';
        if (($bg = self::hex($pp['shd'] ?? null))) $st .= 'background-color:' . $bg . ';';
        foreach ($pp['bdr'] ?? [] as $k => $v) $st .= 'border-' . $k . ':' . $v . ';';
        if (!empty($pp['bdr']['bottom'])) $st .= 'padding-bottom:0.6mm;';
        if (!empty($pp['bdr']['top'])) $st .= 'padding-top:0.6mm;';
        return $st;
    }
    // پاراگرافِ Tabدار => جدولِ یک‌سطری با ستون‌ها روی توقف‌گاه‌ها (راست‌چین: از راست)
    private function tabbed(array $pp, $W, $inCell = false) {
        $segs = $pp['segs'];
        if (!$segs || $W < 10) return null;
        $pdf = $this->pdf;
        $wOf = function ($html) use ($pdf, $pp) {
            $t = html_entity_decode(strip_tags(str_replace(['<br />', '<br>'], ' ', $html)), ENT_QUOTES, 'UTF-8');
            if (trim($t) === '') return 0;
            $fam = preg_match('/font-family:([a-z0-9]+)/', $html, $m) ? $m[1] : 'vazir';
            try { return $pdf->GetStringWidth($t, $fam, strpos($html, '<b') !== false ? 'B' : '', $pp['pt']) * 1.04 + 0.6; } catch (Throwable $e) { return mb_strlen($t) * $pp['pt'] * 0.2; }
        };
        $stops = $pp['tabsAt'];
        $cells = []; $cursor = $wOf($segs[0]);
        $cells[] = ['s' => 0, 'html' => $segs[0]];
        for ($i = 1; $i < count($segs); $i++) {
            $w = $wOf($segs[$i]);
            $stop = null;
            foreach ($stops as $t) if ($t['pos'] > $cursor + 0.3) { $stop = $t; break; }
            if (!$stop) $stop = ['val' => 'start', 'pos' => (floor($cursor / $this->defTab) + 1) * $this->defTab, 'leader' => ''];
            $pos = min($stop['pos'], $W);
            // left/right در Word منطقی‌اند (left = ابتدای سطر؛ در راست‌چین یعنی راست)
            $type = in_array($stop['val'], ['end', 'right', 'decimal'], true) ? 'end' : ($stop['val'] === 'center' ? 'center' : 'start');
            if ($type === 'end') { $s = max($cursor, $pos - $w); $e = $pos; }
            elseif ($type === 'center') { $s = max($cursor, $pos - $w / 2); $e = $s + $w; }
            else { $s = max($cursor, $pos); $e = $s + $w; }
            if (in_array($stop['leader'], ['dot', 'hyphen', 'underscore', 'middleDot'], true) && $s - $cursor > 2) {
                $ch = ['dot' => '.', 'hyphen' => '-', 'underscore' => '_', 'middleDot' => '·'][$stop['leader']];
                try { $cwid = max(0.3, $pdf->GetStringWidth($ch, 'vazir', '', $pp['pt'])); } catch (Throwable $e2) { $cwid = 1; }
                $cells[] = ['s' => $cursor, 'html' => '<span style="font-size:' . $pp['pt'] . 'pt;">' . str_repeat($ch, max(0, intval(($s - $cursor - 1) / $cwid))) . '</span>', 'lead' => true];
            }
            $cells[] = ['s' => $s, 'html' => $segs[$i]];
            $cursor = $e;
        }
        $html = '<table cellspacing="0" cellpadding="0" width="' . round($W, 2) . 'mm"><tr>';
        $side = $pp['bidi'] ? 'right' : 'left';
        for ($i = 0; $i < count($cells); $i++) {
            $cw = ($i + 1 < count($cells) ? $cells[$i + 1]['s'] : $W) - $cells[$i]['s'];
            if ($cw < 0.2) continue;
            $al = !empty($cells[$i]['lead']) ? 'center' : ($i === 0 && count($cells) === 1 ? $pp['align'] : $side);
            $html .= '<td width="' . round($cw, 2) . 'mm" style="text-align:' . $al . ';">' . ($cells[$i]['html'] === '' ? '&nbsp;' : $cells[$i]['html']) . '</td>';
        }
        return $html . '</tr></table>';
    }

    // ---------------- سرصفحه / پاصفحه ----------------
    private function hfPart($ref, array $docRels) {
        if (!$ref) return null;
        $t = $docRels[$ref]['target'] ?? null;
        if (!$t) return null;
        $d = $this->dom($this->zip->getFromName($t));
        if (!$d) return null;
        $x = $this->xp($d);
        $rels = $this->rels($t);
        $html = ''; $anchors = [];
        $W = $this->pg['w'] - $this->pg['ml'] - $this->pg['mr'];
        $walk = function (DOMElement $root) use (&$walk, &$html, &$anchors, $x, $rels, $W) {
            foreach ($root->childNodes as $n) {
                if (!($n instanceof DOMElement) || $n->namespaceURI !== self::W) continue;
                if ($n->localName === 'p') {
                    [$ph, $pp, $an] = $this->paragraph($n, $x, $rels);
                    $anchors = array_merge($anchors, $an);
                    if ($pp['segs']) $ph = $this->tabbed($pp, $W - self::mm(max(0, $pp['indS'] ?? 0)) - self::mm(max(0, $pp['indE'] ?? 0))) ?? $ph;
                    $vis = trim(str_replace('&nbsp;', '', strip_tags($ph, '<img>'))) !== '';
                    if ($vis || !empty($pp['bdr'])) $html .= '<div style="' . $this->paraCss($pp) . 'line-height:' . round(self::lineRatio($pp), 3) . ';"' . ($pp['bidi'] ? ' dir="rtl"' : '') . '>' . ($vis ? $ph : '&nbsp;') . '</div>';
                } elseif ($n->localName === 'tbl') { [$th] = $this->table($n, $x, $rels, $W); $html .= $th; }
                elseif ($n->localName === 'sdt') { $c = $x->query('w:sdtContent', $n)->item(0); if ($c) $walk($c); }
            }
        };
        $walk($d->documentElement);
        $html = str_replace(['{PAGE}', '{NUMPAGES}'], '', $html);
        return ['html' => $html, 'anchors' => $anchors];
    }

    // ---------------- تبدیل ----------------
    public function convert($docx, $destPdf) {
        if (!class_exists('ZipArchive')) return false;
        $this->zip = new ZipArchive();
        if ($this->zip->open($docx) !== true) return false;
        $this->tmpDir = rtrim(sys_get_temp_dir(), '/') . '/bime_docx_' . bin2hex(random_bytes(5));
        @mkdir($this->tmpDir, 0700, true);
        try {
            $d = $this->dom($this->zip->getFromName('word/document.xml'));
            if (!$d) return false;
            $x = $this->xp($d);
            $this->loadTheme(); $this->loadStyles(); $this->loadNumbering(); $this->loadSettings(); $this->loadEmbeddedFonts();
            $rels = $this->rels('word/document.xml');
            $body = $x->query('/w:document/w:body')->item(0);
            if (!$body) return false;
            $sect = $x->query('w:sectPr', $body)->item(0);
            $pgSz = $sect ? $x->query('w:pgSz', $sect)->item(0) : null;
            $pgMar = $sect ? $x->query('w:pgMar', $sect)->item(0) : null;
            $W = $pgSz ? self::mm($pgSz->getAttributeNS(self::W, 'w')) : 210; $H = $pgSz ? self::mm($pgSz->getAttributeNS(self::W, 'h')) : 297;
            if ($W < 50 || $H < 50) { $W = 210; $H = 297; }
            $m = function ($k, $def) use ($pgMar) { $v = $pgMar ? $pgMar->getAttributeNS(self::W, $k) : ''; return $v === '' ? $def : abs(self::mm($v)); };
            $this->pg = ['w' => $W, 'h' => $H, 'mt' => $m('top', 25.4), 'mb' => $m('bottom', 25.4), 'ml' => $m('left', 25.4), 'mr' => $m('right', 25.4), 'hd' => $m('header', 12.5), 'fd' => $m('footer', 12.5)];
            $this->docRtl = $sect ? (bool)$x->query('w:bidi', $sect)->length : false;
            $nBidi = $x->query('w:p/w:pPr/w:bidi', $body)->length; $nP = max(1, $x->query('w:p', $body)->length);
            if ($nBidi * 2 >= $nP) $this->docRtl = true;
            // رنگِ زمینه و کادرِ صفحه
            $bg = $x->query('/w:document/w:background')->item(0);
            if ($bg && ($c = self::hex($bg->getAttributeNS(self::W, 'color'))) && $c !== '#FFFFFF') $this->pageBg = $c;
            if ($sect && ($pb = $x->query('w:pgBorders', $sect)->item(0))) {
                $fromText = $pb->getAttributeNS(self::W, 'offsetFrom') !== 'page';
                foreach (['top', 'left', 'bottom', 'right'] as $k) {
                    $e = $this->child($pb, $k);
                    $bv = $this->borderOf($e);
                    if (!$bv) continue;
                    preg_match('/^([\d.]+)pt \S+ (#[0-9A-F]{6})$/', $bv, $mm);
                    $this->pgBorders[$k] = ['w' => floatval($mm[1] ?? 0.5) * 0.3528, 'c' => $mm[2] ?? '#000000', 'sp' => intval($e->getAttributeNS(self::W, 'space')) * 0.3528, 'text' => $fromText];
                }
            }

            $pdf = new DocxPdfDoc($W > $H ? 'L' : 'P', 'mm', [$W, $H]);
            $this->pdf = $pdf;
            $pdf->SetCreator('بیمه با ما'); $pdf->SetAuthor('بیمه با ما');
            $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
            $pdf->SetFont('vazir', '', 11);
            $pdf->SetMargins($this->pg['ml'], $this->pg['mt'], $this->pg['mr']);
            $pdf->SetAutoPageBreak(true, $this->pg['mb']);
            $pdf->setCellPaddings(0, 0, 0, 0);
            $pdf->setHtmlVSpace(['p' => [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]], 'div' => [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]], 'table' => [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]]]);
            // سرصفحه و پاصفحه (صفحه‌ی اولِ جدا، اگر titlePg)
            $titlePg = $sect && $x->query('w:titlePg', $sect)->length && self::on($x->query('w:titlePg', $sect)->item(0));
            $hdr = $sect ? $this->hfPart($this->refOf($x, $sect, 'headerReference', 'default'), $rels) : null;
            $ftr = $sect ? $this->hfPart($this->refOf($x, $sect, 'footerReference', 'default'), $rels) : null;
            $hdr1 = $titlePg ? $this->hfPart($this->refOf($x, $sect, 'headerReference', 'first', false), $rels) : $hdr;
            $ftr1 = $titlePg ? $this->hfPart($this->refOf($x, $sect, 'footerReference', 'first', false), $rels) : $ftr;
            $self = $this;
            // ارتفاعِ تقریبیِ پاصفحه (خط‌ها و عکس‌ها) تا متنِ صفحه زیرِ آن نرود
            foreach ([$ftr, $ftr1] as $f) if ($f && $f['html'] !== '') {
                $lines = max(1, preg_match_all('/<div|<br|<tr/i', $f['html']));
                $imgH = 0; if (preg_match_all('/<img[^>]*height="([\d.]+)mm"/', $f['html'], $im)) $imgH = max(array_map('floatval', $im[1]));
                $this->footH = max($this->footH, $lines * 5.2, $imgH + 1);
            }
            if ($ftr || $ftr1) $pdf->SetAutoPageBreak(true, max($this->pg['mb'], $this->pg['fd'] + $this->footH + 1));
            $pdf->setPrintHeader(true); $pdf->setPrintFooter((bool)($ftr || $ftr1));
            $pdf->SetHeaderMargin(0); $pdf->SetFooterMargin($this->pg['fd']);
            $pdf->dxHeader = function (DocxPdfDoc $p) use ($hdr, $hdr1, $self) { $self->pageDecor($p); $h = $p->getPage() === 1 ? $hdr1 : $hdr; if ($h) $self->drawHeaderFooter($p, $h, true); };
            $pdf->dxFooter = function (DocxPdfDoc $p) use ($ftr, $ftr1, $self) { $f = $p->getPage() === 1 ? $ftr1 : $ftr; if ($f) $self->drawHeaderFooter($p, $f, false); };
            $pdf->AddPage();
            $contentW = $W - $this->pg['ml'] - $this->pg['mr'];
            foreach ($body->childNodes as $n) $this->block($n, $x, $rels, $contentW);
            // لایه‌ها: «پشتِ متن» زیرِ متنِ همان صفحه، «جلوی متن» روی همه (به ترتیبِ z)
            usort($this->under, function ($a, $b) { return $a[1]['z'] <=> $b[1]['z']; });
            usort($this->over, function ($a, $b) { return $a[1]['z'] <=> $b[1]['z']; });
            foreach ($this->under as [$pg, $a, $py]) $pdf->dxUnder($pg, function (DocxPdfDoc $p) use ($self, $a, $py) { $self->drawAnchor($p, $a, $py); });
            $last = $pdf->getNumPages();
            foreach ($this->over as [$pg, $a, $py]) { $pdf->setPage($pg); $this->drawAnchor($pdf, $a, $py); }
            $pdf->setPage($last);
            $pdf->lastPage();
            $pdf->Output($destPdf, 'F');
            return is_file($destPdf) && filesize($destPdf) > 0;
        } catch (Throwable $e) {
            error_log('[docx_to_pdf] ' . $e->getMessage() . ' @' . $e->getLine());
            return false;
        } finally {
            $this->zip->close();
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($this->tmpDir);
        }
    }
    private function refOf(DOMXPath $x, DOMElement $sect, $tag, $type, $fallback = true) {
        $def = null;
        foreach ($x->query('w:' . $tag, $sect) as $r) {
            $t = $r->getAttributeNS(self::W, 'type') ?: 'default';
            if ($t === $type) return $r->getAttributeNS(self::NS['r'], 'id');
            if ($t === 'default') $def = $r->getAttributeNS(self::NS['r'], 'id');
        }
        return $fallback ? $def : null;
    }
    // رنگِ زمینه و کادرِ صفحه (زیرِ همه‌چیز)
    public function pageDecor(DocxPdfDoc $p) {
        if ($this->pageBg) $p->Rect(0, 0, $this->pg['w'], $this->pg['h'], 'F', [], self::rgb($this->pageBg));
        if ($this->pgBorders) {
            $g = $this->pg; $b = $this->pgBorders;
            $edge = function ($k, $inner) use ($b) { return isset($b[$k]) ? ($b[$k]['text'] ? $inner - $b[$k]['sp'] : $b[$k]['sp']) : null; };
            $t = $edge('top', $g['mt']); $l = $edge('left', $g['ml']); $bt = isset($b['bottom']) ? ($b['bottom']['text'] ? $g['h'] - $g['mb'] + $b['bottom']['sp'] : $g['h'] - $b['bottom']['sp']) : null;
            $r = isset($b['right']) ? ($b['right']['text'] ? $g['w'] - $g['mr'] + $b['right']['sp'] : $g['w'] - $b['right']['sp']) : null;
            $y1 = $t ?? 0; $y2 = $bt ?? $g['h']; $x1 = $l ?? 0; $x2 = $r ?? $g['w'];
            foreach (['top' => [$x1, $y1, $x2, $y1], 'bottom' => [$x1, $y2, $x2, $y2], 'left' => [$x1, $y1, $x1, $y2], 'right' => [$x2, $y1, $x2, $y2]] as $k => $ln)
                if (isset($b[$k])) $p->Line($ln[0], $ln[1], $ln[2], $ln[3], ['width' => max(0.1, $b[$k]['w']), 'color' => self::rgb($b[$k]['c'])]);
        }
    }
    public function drawHeaderFooter(DocxPdfDoc $p, array $hf, $isHeader) {
        $auto = $p->getAutoPageBreak(); $bm = $p->getBreakMargin(); $rtl = $p->getRTL();
        $p->SetAutoPageBreak(false, 0);
        usort($hf['anchors'], function ($a, $b) { return ($a['behind'] ? -1 : 1) <=> ($b['behind'] ? -1 : 1) ?: $a['z'] <=> $b['z']; });
        foreach ($hf['anchors'] as $a) $this->drawAnchor($p, $a, $isHeader ? $this->pg['hd'] : $this->pg['h'] - $this->pg['fd'] - $this->footH);
        if ($hf['html'] !== '') {
            $p->setRTL($this->docRtl);
            $p->SetFont('vazir', '', 10);
            $p->setCellHeightRatio(1.25);
            $w = $this->pg['w'] - $this->pg['ml'] - $this->pg['mr'];
            $y = $isHeader ? $this->pg['hd'] : $this->pg['h'] - $this->pg['fd'] - $this->footH;
            $p->writeHTMLCell($w, 0, $this->docRtl ? $this->pg['mr'] : $this->pg['ml'], $y, $hf['html'], 0, 1, false, true, '', true);
            // سرصفحه‌ای که از حاشیه‌ی بالا بلندتر است متن را پایین‌تر می‌برد (مثلِ Word)
            if ($isHeader) $p->SetTopMargin(max($this->pg['mt'], $p->GetY() + 1.5));
        } elseif ($isHeader) $p->SetTopMargin($this->pg['mt']);
        $p->setRTL($rtl); $p->SetAutoPageBreak($auto, $bm);
    }
    private function block($n, DOMXPath $x, array $rels, $contentW) {
        if (!($n instanceof DOMElement) || $n->namespaceURI !== self::W) return;
        $pdf = $this->pdf;
        if ($n->localName === 'sdt') { $c = $x->query('w:sdtContent', $n)->item(0); if ($c) foreach ($c->childNodes as $cc) $this->block($cc, $x, $rels, $contentW); return; }
        if ($n->localName === 'tbl') {
            [$html, $bidiVisual] = $this->table($n, $x, $rels, $contentW);
            // شیءهای شناورِ داخلِ خانه‌های جدول (کادرها/عکس‌های شناور) روی صفحه
            $tblY = $pdf->GetY(); $tblPage = $pdf->getPage();
            $pdf->setRTL($bidiVisual);
            $pdf->SetFont('vazir', '', 10);
            $pdf->setCellHeightRatio(1.25);
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->setRTL(false);
            foreach ($x->query('.//w:p[not(ancestor::w:txbxContent)]', $n) as $p) foreach ($this->runs($p, $x) as $r) if ($r instanceof DOMElement) foreach ($r->childNodes as $c)
                if ($c instanceof DOMElement && in_array($c->localName, ['drawing', 'AlternateContent', 'pict'], true))
                    foreach ($this->drawingsOf($c, $x, $rels) as $o) if (!$o['inline']) { if ($o['behind']) $this->under[] = [$tblPage, $o, $tblY]; else $this->over[] = [$tblPage, $o, $tblY]; }
            return;
        }
        if ($n->localName !== 'p') return;
        [$html, $pp, $anchors] = $this->paragraph($n, $x, $rels);
        if (!empty($pp['pbb'])) $pdf->AddPage();
        $before = self::mm($pp['before'] ?? 0); $after = self::mm($pp['after'] ?? 0);
        if ($before > 0) $pdf->SetY($pdf->GetY() + $before);
        $ratio = self::lineRatio($pp);
        $pdf->setCellHeightRatio($ratio);
        $sL = self::mm(max(0, $pp['indS'] ?? 0)); $sE = self::mm(max(0, $pp['indE'] ?? 0));
        $rtl = $pp['bidi'];
        $pdf->setRTL($rtl);
        $ml = $this->pg['ml'] + ($rtl ? $sE : $sL); $mr = $this->pg['mr'] + ($rtl ? $sL : $sE);
        $pdf->SetLeftMargin($ml); $pdf->SetRightMargin($mr);
        $pdf->SetX($rtl ? $mr : $ml);
        $paraY = $pdf->GetY(); $paraPage = $pdf->getPage();
        foreach ($anchors as $a) { if ($a['behind']) $this->under[] = [$paraPage, $a, $paraY]; else $this->over[] = [$paraPage, $a, $paraY]; }
        if ($pp['segs']) $html = $this->tabbed($pp, $contentW - $sL - $sE) ?? $html;
        $indF = self::mm($pp['indF'] ?? 0);
        $style = $this->paraCss($pp) . ($indF > 0 && !$pp['segs'] ? 'text-indent:' . round($indF, 2) . 'mm;' : '');
        $pdf->SetFont('vazir', '', $pp['pt']);
        if ($html === '' && empty($pp['bdr'])) $pdf->Ln($pp['pt'] * 0.3528 * $ratio);
        else $pdf->writeHTML('<div style="' . $style . '">' . ($html === '' ? '&nbsp;' : $html) . '</div>', true, false, true, false, '');
        $pdf->SetLeftMargin($this->pg['ml']); $pdf->SetRightMargin($this->pg['mr']);
        $pdf->setRTL(false);
        if ($after > 0) $pdf->SetY($pdf->GetY() + $after);
        if ($pp['pageBreak']) $pdf->AddPage();
        // بخشِ تازه (sectPr داخلِ پاراگراف) => صفحه‌ی تازه
        if ($x->query('w:pPr/w:sectPr', $n)->length) $pdf->AddPage();
    }
}

function docx_to_pdf($docxAbs, $pdfAbs, $opts = []) {
    @set_time_limit(180);
    if (function_exists('rpt_memory_floor')) rpt_memory_floor(512); else { $lim = ini_get('memory_limit'); if ($lim !== '-1' && intval($lim) < 512) @ini_set('memory_limit', '512M'); }
    $c = new DocxToPdf();
    if (isset($opts['fontMap'])) $c->fontMap = (array)$opts['fontMap'];
    elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        // همان قلم‌هایی که برای گزارشِ بازدید بارگذاری شده‌اند (نامِ قلم در Word => TTF)
        try { foreach ($GLOBALS['pdo']->query("SELECT word_name, family, regular_file, bold_file FROM report_fonts")->fetchAll(PDO::FETCH_ASSOC) as $f)
            $c->fontMap[$f['word_name']] = ['family' => $f['family'], 'regular' => $f['regular_file'], 'bold' => $f['bold_file']]; } catch (Throwable $e) {}
    }
    return $c->convert($docxAbs, $pdfAbs);
}
