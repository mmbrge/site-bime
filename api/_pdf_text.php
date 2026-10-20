<?php
// فایل: api/_pdf_text.php
// خواندنِ متنِ PDF با مختصات (جایگزینِ page.get_text و get_drawings در PyMuPDF) با PHPِ خالص.
// چیدمانِ خط‌ها و تکه‌ها و برگرداندنِ متنِ راست‌به‌چپ همان قاعده‌های MuPDF است (stext-device.c)، تا خواننده‌هایی که برای
// خروجیِ PyMuPDF نوشته شده بودند (بیمه‌نامه، الحاقیه، پیشنهاد، معرفی‌نامه، گزارشِ بازدید) همان نتیجه را بگیرند.
//   $t = new PdfText(PdfDoc::open($path));
//   $t->dict($i)   => ['width','height','blocks'=>[['bbox','lines'=>[['bbox','dir','spans'=>[['text','bbox','size','font','origin','color']]]]]]]
//   $t->text($i)   => متنِ ساده‌ی صفحه (مثلِ page.get_text())
//   $t->words($i)  => [[x0,y0,x1,y1,word,block,line,wordNo], ...]
//   $t->drawings($i) => [['rect'=>[x0,y0,x1,y1],'type'=>'f|s|fs','fill'=>[r,g,b]|null,'color'=>[r,g,b]|null], ...]
require_once __DIR__ . '/_pdf.php';

const PDFT_PARAGRAPH_DIST = 1.5;
const PDFT_SPACE_DIST = 0.15;
const PDFT_SPACE_MAX_DIST = 0.8;
const PDFT_BASE_MAX_DIST = 0.8;
const PDFT_FAKE_BOLD_MAX_DIST = 0.1;

// متریکِ ۱۴ فونتِ پایه (همان فونت‌های جایگزینِ MuPDF) برای فونت‌هایی که داخلِ فایل نیستند: [ascender, descender, پهنای ۳۲..۱۲۶]
const PDFT_STD14 = [
    'Helvetica' => [1.075, -0.299, '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584'],
    'Helvetica-Bold' => [1.07, -0.307, '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584'],
    'Times-Roman' => [1.053, -0.281, '250,333,408,500,500,833,778,180,333,333,500,564,250,333,250,278,500,500,500,500,500,500,500,500,500,500,278,278,564,564,564,444,921,722,667,667,722,611,556,722,722,333,389,722,611,889,722,722,556,722,667,556,611,722,722,944,722,722,611,333,278,333,469,500,333,444,500,444,500,444,333,500,500,278,278,500,278,778,500,500,500,500,333,389,278,500,500,722,500,500,444,480,200,480,541'],
    'Times-Bold' => [1.044, -0.341, '250,333,555,500,500,1000,833,278,333,333,500,570,250,333,250,278,500,500,500,500,500,500,500,500,500,500,333,333,570,570,570,500,930,722,667,722,722,667,611,778,778,389,500,778,667,944,722,778,611,778,722,556,667,722,722,1000,722,722,667,333,278,333,581,500,333,500,556,444,556,444,333,500,556,278,333,556,278,833,556,500,556,556,444,389,333,556,500,722,500,500,444,394,220,394,520'],
    'Courier' => [0.932, -0.317, ''],
];
function pdft_std14_name($base) {
    $b = strtolower($base);
    $bold = (bool)preg_match('/bold|black|heavy/', $b);
    if (preg_match('/courier|mono/', $b)) return 'Courier';
    if (preg_match('/times|serif|roman|georgia|garamond|book/', $b) && strpos($b, 'sans') === false) return $bold ? 'Times-Bold' : 'Times-Roman';
    return $bold ? 'Helvetica-Bold' : 'Helvetica';
}

// اجتماعِ دو مستطیل مثلِ fz_union_rect: مستطیلِ تهی (بی‌پهنا یا بی‌بلندی) نادیده گرفته می‌شود
function pdft_union($a, $b) {
    $ea = $a === null || $a[0] >= $a[2] || $a[1] >= $a[3];
    $eb = $b === null || $b[0] >= $b[2] || $b[1] >= $b[3];
    if ($eb) return $a;
    if ($ea) return $b;
    return [min($a[0], $b[0]), min($a[1], $b[1]), max($a[2], $b[2]), max($a[3], $b[3])];
}
function pdft_empty($r) { return $r === null || $r[0] >= $r[2] || $r[1] >= $r[3]; }

function pdft_mul(array $a, array $b) {   // a × b
    return [$a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2], $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5]];
}
function pdft_pt(array $m, $x, $y) { return [$x * $m[0] + $y * $m[2] + $m[4], $x * $m[1] + $y * $m[3] + $m[5]]; }
function pdft_vec(array $m, $x, $y) { return [$x * $m[0] + $y * $m[2], $x * $m[1] + $y * $m[3]]; }

// کلاسِ دوسویه‌ی یونیکد (فقط آن‌چه MuPDF برای حدسِ جهت لازم دارد): L R AL EN ES ET AN CS NSM BN B S WS ON
function pdft_bidi_class($c) {
    if ($c < 0x80) {
        if ($c >= 0x30 && $c <= 0x39) return 'EN';
        if (($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A)) return 'L';
        if ($c === 0x20 || $c === 0x0C) return 'WS';
        if ($c === 0x09 || $c === 0x0B || $c === 0x1F) return 'S';
        if ($c === 0x0A || $c === 0x0D || ($c >= 0x1C && $c <= 0x1E)) return 'B';
        if ($c < 0x20 || $c === 0x7F) return 'BN';
        if ($c === 0x23 || $c === 0x24 || $c === 0x25) return 'ET';
        if ($c === 0x2B || $c === 0x2D) return 'ES';
        if ($c === 0x2C || $c === 0x2E || $c === 0x2F || $c === 0x3A) return 'CS';
        return 'ON';
    }
    if ($c < 0x100) {
        if ($c === 0xA0) return 'CS';
        if ($c >= 0xA2 && $c <= 0xA5) return 'ET';
        if ($c === 0xB0 || $c === 0xB1) return 'ET';
        if ($c === 0xB2 || $c === 0xB3 || $c === 0xB9) return 'EN';
        if ($c === 0xAD) return 'BN';
        if ($c === 0xAA || $c === 0xB5 || $c === 0xBA || ($c >= 0xC0 && $c !== 0xD7 && $c !== 0xF7)) return 'L';
        return 'ON';
    }
    if ($c >= 0x0590 && $c <= 0x05FF) return ($c >= 0x0591 && $c <= 0x05C7 && !in_array($c, [0x05BE, 0x05C0, 0x05C3, 0x05C6], true)) ? 'NSM' : 'R';
    if ($c >= 0x0600 && $c <= 0x06FF) {
        if ($c <= 0x0605) return 'AN';
        if ($c === 0x0606 || $c === 0x0607 || $c === 0x060E || $c === 0x060F || $c === 0x06DE || $c === 0x06E9) return 'ON';
        if ($c === 0x0609 || $c === 0x060A || $c === 0x066A) return 'ET';
        if ($c === 0x060C) return 'CS';
        if (($c >= 0x0610 && $c <= 0x061A) || ($c >= 0x064B && $c <= 0x065F) || $c === 0x0670 || ($c >= 0x06D6 && $c <= 0x06DC) || ($c >= 0x06DF && $c <= 0x06E4) || $c === 0x06E7 || $c === 0x06E8 || ($c >= 0x06EA && $c <= 0x06ED)) return 'NSM';
        if (($c >= 0x0660 && $c <= 0x0669) || $c === 0x066B || $c === 0x066C || $c === 0x06DD) return 'AN';
        if ($c >= 0x06F0 && $c <= 0x06F9) return 'EN';
        return 'AL';
    }
    if (($c >= 0x0700 && $c <= 0x08FF)) return ($c >= 0x08D3 && $c <= 0x08FF) ? 'NSM' : 'AL';
    if ($c >= 0xFB1D && $c <= 0xFB4F) return $c === 0xFB1E ? 'NSM' : ($c === 0xFB29 ? 'ES' : 'R');
    if ($c >= 0xFB50 && $c <= 0xFDFF) return ($c === 0xFD3E || $c === 0xFD3F) ? 'ON' : 'AL';
    if ($c >= 0xFE70 && $c <= 0xFEFE) return 'AL';
    if ($c === 0xFEFF || ($c >= 0x200B && $c <= 0x200D) || ($c >= 0x2060 && $c <= 0x206F)) return 'BN';
    if ($c >= 0xFFF9 && $c <= 0xFFFD) return $c <= 0xFFFB ? 'BN' : 'ON';
    if ($c === 0x200E) return 'L';
    if ($c === 0x200F) return 'R';
    if (($c >= 0x2000 && $c <= 0x200A) || $c === 0x2028 || $c === 0x205F || $c === 0x3000 || $c === 0x1680) return 'WS';
    if ($c === 0x2029) return 'B';
    if ($c === 0x202F || $c === 0x2044 || ($c >= 0xFE50 && $c <= 0xFE55 && $c !== 0xFE51 && $c !== 0xFE53)) return 'CS';
    if (($c >= 0x2030 && $c <= 0x2034) || ($c >= 0x20A0 && $c <= 0x20CF) || $c === 0x2212 || $c === 0x2213) return $c === 0x2212 ? 'ES' : 'ET';
    if (($c >= 0x2070 && $c <= 0x2079 && $c !== 0x2071 && $c !== 0x2072 && $c !== 0x2073) || ($c >= 0x2080 && $c <= 0x2089) || ($c >= 0x2488 && $c <= 0x249B) || ($c >= 0xFF10 && $c <= 0xFF19)) return 'EN';
    if ($c === 0x207A || $c === 0x207B || $c === 0x208A || $c === 0x208B || $c === 0xFF0B || $c === 0xFF0D) return 'ES';
    if (($c >= 0x0300 && $c <= 0x036F) || ($c >= 0x0483 && $c <= 0x0489) || ($c >= 0x20D0 && $c <= 0x20F0) || ($c >= 0xFE20 && $c <= 0xFE2F)) return 'NSM';
    if (($c >= 0x2010 && $c <= 0x2027) || ($c >= 0x2035 && $c <= 0x205E) || ($c >= 0x2190 && $c <= 0x2BFF) || ($c >= 0x3001 && $c <= 0x3004)) return 'ON';
    return 'L';
}

function pdft_is_mn($c) {
    return ($c >= 0x0300 && $c <= 0x036F) || ($c >= 0x0610 && $c <= 0x061A) || ($c >= 0x064B && $c <= 0x065F) || $c === 0x0670
        || ($c >= 0x06D6 && $c <= 0x06DC) || ($c >= 0x06DF && $c <= 0x06E4) || $c === 0x06E7 || $c === 0x06E8 || ($c >= 0x06EA && $c <= 0x06ED)
        || ($c >= 0x0591 && $c <= 0x05BD) || ($c >= 0x08D3 && $c <= 0x08FF && $c !== 0x08E2) || ($c >= 0x20D0 && $c <= 0x20DC) || ($c >= 0xFE20 && $c <= 0xFE2F);
}

function pdft_utf8($cp) { $r = mb_chr($cp, 'UTF-8'); return $r === false ? '' : $r; }

// NFKC برای حروفِ «نمایشیِ» عربی/فارسی (ﻓﺮﯾﻤﺎ => فریما) و چند نشانه‌ی دیگر — بدونِ نیاز به افزونه‌ی intl
function pdft_nfkc($s) {
    static $map = null;
    if ($s === '' || $s === null) return (string)$s;
    if (class_exists('Normalizer')) { $n = Normalizer::normalize($s, Normalizer::FORM_KC); if ($n !== false && $n !== null) return $n; }
    if ($map === null) {
        $map = [];
        $raw = @file_get_contents(__DIR__ . '/data/nfkc_fa.txt');
        foreach (explode(',', trim((string)$raw)) as $pair) {
            if (strpos($pair, ':') === false) continue;
            [$k, $v] = explode(':', $pair, 2);
            $o = '';
            foreach (str_split($v, 4) as $h) $o .= pdft_utf8(hexdec($h));
            $map[pdft_utf8(hexdec($k))] = $o;
        }
    }
    return strtr($s, $map);
}

// ------------------------------------------------------------------ فونت
class PdfTFont {
    public $name = '', $type = 'Type1', $cid = false, $wmode = 0;
    public $w = [], $dw = 1000, $first = 0, $missing = 0;
    public $toUni = [];          // کد => رشته‌ی utf8
    public $enc = [];            // کد => رشته (فونت‌های ساده، از Encoding)
    public $cmap = null;         // ['ranges'=>[[len, lo, hi]], 'map'=>[code=>cid], 'rng'=>[[lo,hi,cid,len]]]
    public $gidUni = null;       // cid/gid => یونیکد (از جدولِ cmapِ فونتِ داخلی)
    public $fm = null;           // FontMatrix برای Type3
    public $asc = 0.8, $desc = -0.2;
    public $bold = false;
    public $key = '';

    // بایت‌ها => [[code, cid, len], ...]
    function codes($s) {
        $out = [];
        $l = strlen($s);
        if (!$this->cid) { for ($i = 0; $i < $l; $i++) { $c = ord($s[$i]); $out[] = [$c, $c, 1]; } return $out; }
        if ($this->cmap === null) {
            for ($i = 0; $i + 1 < $l; $i += 2) { $c = (ord($s[$i]) << 8) | ord($s[$i + 1]); $out[] = [$c, $c, 2]; }
            if ($l % 2) $out[] = [ord($s[$l - 1]), ord($s[$l - 1]), 1];
            return $out;
        }
        $i = 0;
        while ($i < $l) {
            $done = false;
            for ($n = 1; $n <= 4 && $i + $n <= $l; $n++) {
                $c = 0; for ($k = 0; $k < $n; $k++) $c = ($c << 8) | ord($s[$i + $k]);
                foreach ($this->cmap['ranges'] as $r) {
                    if ($r[0] === $n && $c >= $r[1] && $c <= $r[2]) {
                        $out[] = [$c, $this->mapCid($c, $n), $n];
                        $i += $n; $done = true; break 2;
                    }
                }
            }
            if (!$done) { $c = ord($s[$i]); $out[] = [$c, $this->mapCid($c, 1), 1]; $i++; }
        }
        return $out;
    }

    private function mapCid($c, $n) {
        if (isset($this->cmap['map'][$c])) return $this->cmap['map'][$c];
        foreach ($this->cmap['rng'] as $r) if ($c >= $r[0] && $c <= $r[1]) return $r[2] + ($c - $r[0]);
        return $this->cmap['identity'] ? $c : 0;
    }

    function width($cid) {
        if ($this->cid) return $this->w[$cid] ?? $this->dw;
        if (isset($this->w[$cid])) return $this->w[$cid];
        return $this->missing ?: ($this->type === 'Type3' ? 0 : 500);
    }

    function unicode($code, $cid, &$isCid = false) {
        $isCid = false;
        if (isset($this->toUni[$code])) {
            $u = $this->toUni[$code];
            $cp = mb_ord($u, 'UTF-8');
            if (mb_strlen($u, 'UTF-8') === 1 && $cp >= 8 && $cp <= 13) return ' ';
            if (!(mb_strlen($u, 'UTF-8') === 1 && ($cp < 32 || ($cp >= 127 && $cp < 160)))) return $u;
        }
        if (!$this->cid && isset($this->enc[$code])) return $this->enc[$code];
        if ($this->gidUni !== null && isset($this->gidUni[$cid])) return $this->gidUni[$cid];
        // MuPDF با TEXT_CID_FOR_UNKNOWN_UNICODE خودِ CID را به‌جای نویسه‌ی ناشناخته می‌گذارد
        if ($cid > 0) { $isCid = true; return pdft_utf8($cid); }
        return '';
    }
}

class PdfText {
    /** @var PdfDoc */
    public $doc;
    private $fonts = [];
    private $glyphNames = null;

    // وضعیتِ ساختِ متن (stext)
    private $blocks, $curBlock, $curLine, $pen, $lagPen, $lagSize, $lastChar, $lastBidi, $start, $maybeBullet, $bidi;
    private $chars;              // نویسه‌های جاری (برای تشخیصِ پررنگیِ تقلبی)
    private $drawings, $path, $pathPts, $gs, $gstack, $box, $clipBox, $mcStack;
    private $depth = 0;
    private $cidFlag = false;   // FZ_STEXT_UNICODE_IS_CID (تا پایانِ همان تکه‌ی fz_text)
    private $tos = [], $tosMode = null, $last = ['valid' => false];
    private $pendingClip = false;
    private $ops = 0;
    public $maxOps = 3000000;

    function __construct(PdfDoc $doc) { $this->doc = $doc; }

    // ماتریسِ صفحه => فضای دستگاه (مبدأ بالا-چپ، y رو به پایین). مثلِ PyMuPDF مختصاتِ متن بدونِ چرخشِ صفحه است؛
    // فقط پهنا/بلندیِ گزارش‌شده (page.rect) با چرخش جابه‌جا می‌شود.
    function pageMatrix($idx, &$w = null, &$h = null, &$uw = null, &$uh = null) {
        $pg = $this->doc->pages()[$idx];
        [$x0, $y0, $x1, $y1] = $pg['box'];
        $uw = $x1 - $x0; $uh = $y1 - $y0;
        if ($pg['rot'] === 90 || $pg['rot'] === 270) { $w = $uh; $h = $uw; } else { $w = $uw; $h = $uh; }
        return [1, 0, 0, -1, -$x0, $y1];
    }

    // ---------------------------------------------------------- اجرای صفحه
    private function run($idx) {
        $this->blocks = []; $this->curBlock = null; $this->curLine = null;
        $this->pen = [0, 0]; $this->lagPen = [0, 0]; $this->lagSize = 0; $this->lastChar = 0; $this->lastBidi = 0; $this->start = [0, 0];
        $this->maybeBullet = false; $this->bidi = 0;
        $this->drawings = []; $this->path = []; $this->pathPts = [];
        $this->mcStack = [];
        $this->ops = 0;
        $pg = $this->doc->pages()[$idx];
        $ctm = $this->pageMatrix($idx, $w, $h, $uw, $uh);
        $this->box = [0, 0, $uw, $uh];
        $this->cidFlag = false; $this->tos = []; $this->tosMode = null; $this->last = ['valid' => false];
        $this->gs = ['ctm' => $ctm, 'font' => null, 'size' => 0, 'tc' => 0, 'tw' => 0, 'tz' => 1, 'tl' => 0, 'ts' => 0, 'tr' => 0,
                     'fill' => [0, 0, 0], 'stroke' => [0, 0, 0], 'lw' => 1, 'fcs' => 1, 'scs' => 1, 'clip' => [0, 0, $uw, $uh]];
        $this->pendingClip = false;
        $this->gstack = [];
        $res = $pg['res'] instanceof PdfDict ? $pg['res'] : new PdfDict([]);
        $this->exec($this->doc->pageContent($idx), $res);
        $this->flushText();
        $this->fixup();
        return [$w, $h];
    }

    private function resDict($res, $cat) {
        if (!$res instanceof PdfDict) return null;
        $d = $this->doc->resolve($res->get($cat));
        return $d instanceof PdfDict ? $d : null;
    }

    // تجزیه‌ی جریانِ محتوا و اجرای عملگرها
    private function exec($data, PdfDict $res) {
        if ($data === '' || $this->depth > 12) return;
        $this->depth++;
        $re = '~\s*(?:(?<s>\((?:[^()\\\\]++|\\\\.|(?&s))*\))|(<<)|(>>)|(<[0-9A-Fa-f\s]*>)|([\[\]{}])|(/[^\s/\[\]<>(){}%]*)|([+\-]?(?:\d+\.?\d*|\.\d+))|(%[^\r\n]*)|([^\s/\[\]<>(){}%]+))~s';
        $st = [];
        $pos = 0;
        $len = strlen($data);
        while ($pos < $len) {
            if (!preg_match_all($re, $data, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $pos)) break;
            $restart = false;
            foreach ($mm as $m) {
                $tokEnd = $m[0][1] + strlen($m[0][0]);
                if (isset($m['s']) && $m['s'][1] >= 0 && $m['s'][0] !== '') { $st[] = new PdfStr($this->unescape(substr($m['s'][0], 1, -1))); continue; }
                if (isset($m[2]) && $m[2][1] >= 0 && $m[2][0] !== '') { $st[] = '<<'; continue; }
                if (isset($m[3]) && $m[3][1] >= 0 && $m[3][0] !== '') { $st[] = $this->closeDict($st); continue; }
                if (isset($m[4]) && $m[4][1] >= 0 && $m[4][0] !== '') { $h = preg_replace('/\s+/', '', substr($m[4][0], 1, -1)); if (strlen($h) % 2) $h .= '0'; $st[] = new PdfStr((string)hex2bin($h)); continue; }
                if (isset($m[5]) && $m[5][1] >= 0 && $m[5][0] !== '') {
                    $t = $m[5][0];
                    if ($t === '[') $st[] = '['; elseif ($t === ']') { $a = []; while ($st) { $x = array_pop($st); if ($x === '[') break; array_unshift($a, $x); } $st[] = $a; }
                    continue;
                }
                if (isset($m[6]) && $m[6][1] >= 0 && $m[6][0] !== '') { $st[] = new PdfTName(substr($m[6][0], 1)); continue; }
                if (isset($m[7]) && $m[7][1] >= 0 && $m[7][0] !== '') { $st[] = (float)$m[7][0]; continue; }
                if (isset($m[8]) && $m[8][1] >= 0 && $m[8][0] !== '') continue;
                if (!isset($m[9]) || $m[9][1] < 0) continue;
                $op = $m[9][0];
                if (++$this->ops > $this->maxOps) { $this->depth--; return; }
                if ($op === 'BI') {
                    // تصویرِ درون‌خطی: تا «EI» رد شو
                    $e = $this->skipInlineImage($data, $tokEnd);
                    $st = [];
                    $pos = $e;
                    $restart = true;
                    break;
                }
                if ($op === 'true') { $st[] = true; continue; }
                if ($op === 'false') { $st[] = false; continue; }
                if ($op === 'null') { $st[] = null; continue; }
                $this->op($op, $st, $res);
                $st = [];
            }
            if (!$restart) break;
        }
        $this->depth--;
    }

    private function closeDict(array &$st) {
        $items = [];
        while ($st) { $x = array_pop($st); if ($x === '<<') break; array_unshift($items, $x); }
        $d = [];
        for ($i = 0; $i + 1 < count($items); $i += 2) { $k = $items[$i]; if ($k instanceof PdfTName) $d[$k->n] = $items[$i + 1] instanceof PdfTName ? $items[$i + 1]->n : $items[$i + 1]; }
        return new PdfDict($d);
    }

    private function skipInlineImage($data, $p) {
        $id = strpos($data, 'ID', $p);
        if ($id === false) return strlen($data);
        $i = $id + 2;
        $l = strlen($data);
        while ($i < $l) {
            $e = strpos($data, 'EI', $i);
            if ($e === false) return $l;
            $before = $data[$e - 1] ?? ' '; $after = $data[$e + 2] ?? ' ';
            if (ctype_space($before) && ($after === '' || ctype_space($after) || $e + 2 >= $l)) return $e + 2;
            $i = $e + 2;
        }
        return $l;
    }

    private function unescape($s) {
        if (strpos($s, '\\') === false) return $s;
        $i = -1;
        $w = '(' . $s . ')';
        $p = 0;
        return $this->doc->parseLiteral($w, $p);
    }

    private function num($v) { return is_numeric($v) ? (float)$v : 0.0; }

    private function op($op, array $a, PdfDict $res) {
        $g = &$this->gs;
        switch ($op) {
            case 'q': $this->gstack[] = $this->gs; if (count($this->gstack) > 200) array_shift($this->gstack); return;
            case 'Q': $this->flushText(); if ($this->gstack) $this->gs = array_pop($this->gstack); return;
            case 'cm': $this->flushText(); if (count($a) >= 6) $g['ctm'] = pdft_mul(array_map([$this, 'num'], array_slice($a, -6)), $g['ctm']); return;
            case 'BT': $g['tm'] = [1, 0, 0, 1, 0, 0]; $g['tlm'] = $g['tm']; $this->bidi = 0; return;
            case 'ET': $this->flushText(); return;
            case 'Tf':
                if (count($a) >= 2) { $nm = $a[count($a) - 2]; $g['size'] = $this->num(end($a)); $g['font'] = $this->loadFont($res, $nm instanceof PdfTName ? $nm->n : (string)$nm); }
                return;
            case 'Tc': $g['tc'] = $this->num(end($a)); return;
            case 'Tw': $g['tw'] = $this->num(end($a)); return;
            case 'Tz': $g['tz'] = $this->num(end($a)) / 100; return;
            case 'TL': $g['tl'] = $this->num(end($a)); return;
            case 'Ts': $g['ts'] = $this->num(end($a)); return;
            case 'Tr': $g['tr'] = (int)$this->num(end($a)); return;
            case 'Td': if (count($a) >= 2) { $this->td($this->num($a[count($a) - 2]), $this->num(end($a))); } return;
            case 'TD': if (count($a) >= 2) { $ty = $this->num(end($a)); $g['tl'] = -$ty; $this->td($this->num($a[count($a) - 2]), $ty); } return;
            case 'Tm': if (count($a) >= 6) { $g['tm'] = array_map([$this, 'num'], array_slice($a, -6)); $g['tlm'] = $g['tm']; } return;
            case 'T*': $this->td(0, -$g['tl']); return;
            case 'Tj': if ($a && end($a) instanceof PdfStr) $this->show(end($a)->s); return;
            case "'": $this->td(0, -$g['tl']); if ($a && end($a) instanceof PdfStr) $this->show(end($a)->s); return;
            case '"': if (count($a) >= 3) { $g['tw'] = $this->num($a[0]); $g['tc'] = $this->num($a[1]); } $this->td(0, -$g['tl']); if ($a && end($a) instanceof PdfStr) $this->show(end($a)->s); return;
            case 'TJ':
                $arr = end($a);
                if (!is_array($arr)) return;
                foreach ($arr as $x) {
                    if ($x instanceof PdfStr) $this->show($x->s);
                    elseif (is_numeric($x) && isset($g['tm'])) {
                        $f = $g['font'];
                        if ($f && $f->wmode) $g['tm'] = pdft_mul([1, 0, 0, 1, 0, -$x / 1000 * $g['size']], $g['tm']);
                        else $g['tm'] = pdft_mul([1, 0, 0, 1, -$x / 1000 * $g['size'] * $g['tz'], 0], $g['tm']);
                    }
                }
                return;
            case 'Do':
                $this->flushText();
                $nm = end($a);
                $xo = $this->resDict($res, 'XObject');
                if (!$xo || !$nm instanceof PdfTName) return;
                $o = $this->doc->resolve($xo->get($nm->n));
                if (!$o instanceof PdfStream || $o->dict->get('Subtype') !== 'Form') return;
                $saved = $this->gs;
                $mtx = $this->doc->resolve($o->dict->get('Matrix'));
                if (is_array($mtx) && count($mtx) === 6) $this->gs['ctm'] = pdft_mul(array_map(function ($v) { return is_numeric($v) ? (float)$v : 0.0; }, array_map([$this->doc, 'resolve'], $mtx)), $this->gs['ctm']);
                $r2 = $this->doc->resolve($o->dict->get('Resources'));
                $this->exec($this->doc->decodeStream($o), $r2 instanceof PdfDict ? $r2 : $res);
                $this->gs = $saved;
                return;
            case 'gs':
                $this->flushText();
                $nm = end($a);
                $eg = $this->resDict($res, 'ExtGState');
                if (!$eg || !$nm instanceof PdfTName) return;
                $d = $this->doc->resolve($eg->get($nm->n));
                if ($d instanceof PdfDict) {
                    $fa = $this->doc->resolve($d->get('Font'));
                    if (is_array($fa) && count($fa) >= 2) { $g['font'] = $this->loadFontObj($fa[0]); $g['size'] = $this->num($this->doc->resolve($fa[1])); }
                    if ($d->get('LW') !== null) $g['lw'] = $this->num($this->doc->resolve($d->get('LW')));
                }
                return;
            case 'BDC':
                $p = end($a);
                $at = null;
                if ($p instanceof PdfTName) { $pr = $this->resDict($res, 'Properties'); $p = $pr ? $this->doc->resolve($pr->get($p->n)) : null; }
                if ($p instanceof PdfDict) { $v = $this->doc->resolve($p->get('ActualText')); if ($v instanceof PdfStr) $at = $this->decodeTextString($v->s); }
                $this->beginMarked($at);
                return;
            case 'BMC': $this->mcStack[] = null; return;
            case 'EMC': $this->endMarked(); return;
            // ---- مسیرها (برای drawings)
            case 'w': $this->flushText(); $g['lw'] = $this->num(end($a)); return;
            case 'j': case 'J': case 'M': case 'd': case 'ri': $this->flushText(); return;
            case 'm': if (count($a) >= 2) { $this->path[] = ['m', [pdft_pt($g['ctm'], $this->num($a[0]), $this->num($a[1]))]]; } return;
            case 'l': if (count($a) >= 2) { $this->path[] = ['l', [pdft_pt($g['ctm'], $this->num($a[0]), $this->num($a[1]))]]; } return;
            case 'c': if (count($a) >= 6) { $this->path[] = ['c', [pdft_pt($g['ctm'], $this->num($a[0]), $this->num($a[1])), pdft_pt($g['ctm'], $this->num($a[2]), $this->num($a[3])), pdft_pt($g['ctm'], $this->num($a[4]), $this->num($a[5]))]]; } return;
            case 'v': case 'y': if (count($a) >= 4) { $this->path[] = ['c', [pdft_pt($g['ctm'], $this->num($a[0]), $this->num($a[1])), pdft_pt($g['ctm'], $this->num($a[2]), $this->num($a[3]))]]; } return;
            case 'h': $this->path[] = ['h', []]; return;
            case 're':
                if (count($a) >= 4) {
                    [$x, $y, $w, $h] = array_map([$this, 'num'], array_slice($a, -4));
                    $this->path[] = ['re', [pdft_pt($g['ctm'], $x, $y), pdft_pt($g['ctm'], $x + $w, $y), pdft_pt($g['ctm'], $x + $w, $y + $h), pdft_pt($g['ctm'], $x, $y + $h)]];
                }
                return;
            case 'S': case 's': $this->paint('s'); return;
            case 'f': case 'F': case 'f*': $this->paint('f'); return;
            case 'B': case 'B*': case 'b': case 'b*': $this->paint('fs'); return;
            case 'n': $this->applyClip(); $this->path = []; return;
            case 'W': case 'W*': $this->flushText(); $this->pendingClip = true; return;
            // ---- رنگ‌ها
            case 'g': $this->flushText(); $v = $this->num(end($a)); $g['fill'] = [$v, $v, $v]; return;
            case 'G': $this->flushText(); $v = $this->num(end($a)); $g['stroke'] = [$v, $v, $v]; return;
            case 'rg': $this->flushText(); if (count($a) >= 3) $g['fill'] = array_map([$this, 'num'], array_slice($a, -3)); return;
            case 'RG': $this->flushText(); if (count($a) >= 3) $g['stroke'] = array_map([$this, 'num'], array_slice($a, -3)); return;
            case 'k': $this->flushText(); if (count($a) >= 4) $g['fill'] = $this->cmyk(array_slice($a, -4)); return;
            case 'K': $this->flushText(); if (count($a) >= 4) $g['stroke'] = $this->cmyk(array_slice($a, -4)); return;
            case 'cs': $this->flushText(); $g['fill'] = [0, 0, 0]; return;
            case 'CS': $this->flushText(); $g['stroke'] = [0, 0, 0]; return;
            case 'sc': case 'scn': $this->flushText(); $g['fill'] = $this->anyColor($a); return;
            case 'SC': case 'SCN': $this->flushText(); $g['stroke'] = $this->anyColor($a); return;
        }
    }

    private function cmyk($a) { [$c, $m, $y, $k] = array_map([$this, 'num'], $a); return [(1 - min(1, $c + $k)), (1 - min(1, $m + $k)), (1 - min(1, $y + $k))]; }
    private function anyColor($a) {
        $n = array_values(array_filter($a, 'is_numeric'));
        if (count($n) >= 4) return $this->cmyk(array_slice($n, 0, 4));
        if (count($n) === 3) return array_map('floatval', $n);
        if (count($n) >= 1) { $v = (float)$n[0]; return [$v, $v, $v]; }
        return [0, 0, 0];
    }

    // مسیرِ برش (W n): کادرِ مسیر با برشِ فعلی اشتراک گرفته می‌شود (برای کنار گذاشتنِ متنِ بیرون از برش مثلِ MuPDF)
    private function applyClip() {
        if (!$this->pendingClip) return;
        $this->pendingClip = false;
        if (!$this->path) return;
        $xs = []; $ys = [];
        foreach ($this->path as $it) foreach ($it[1] as $p) { $xs[] = $p[0]; $ys[] = $p[1]; }
        if (!$xs) return;
        $c = $this->gs['clip'];
        $this->gs['clip'] = [max($c[0], min($xs)), max($c[1], min($ys)), min($c[2], max($xs)), min($c[3], max($ys))];
    }

    private function paint($type) {
        $this->applyClip();
        if (!$this->path) return;
        $xs = []; $ys = [];
        foreach ($this->path as $it) foreach ($it[1] as $p) { $xs[] = $p[0]; $ys[] = $p[1]; }
        if ($xs) {
            // تکه‌های مسیر مثلِ items ِ get_drawings: ['l', p1, p2] | ['re', [x0,y0,x1,y1]] | ['c', p1, p2]
            $segs = []; $cur = null; $start = null;
            foreach ($this->path as $it) {
                if ($it[0] === 'm') { $cur = $start = $it[1][0]; }
                elseif ($it[0] === 'l') { if ($cur !== null) $segs[] = ['l', $cur, $it[1][0]]; $cur = $it[1][0]; }
                elseif ($it[0] === 'c') { $end = end($it[1]); if ($cur !== null) $segs[] = ['c', $cur, $end]; $cur = $end; }
                elseif ($it[0] === 'h') { if ($cur !== null && $start !== null && $cur !== $start) $segs[] = ['l', $cur, $start]; $cur = $start; }
                elseif ($it[0] === 're') {
                    $rx = array_column($it[1], 0); $ry = array_column($it[1], 1);
                    $segs[] = ['re', [min($rx), min($ry), max($rx), max($ry)]];
                    $cur = $start = $it[1][0];
                }
            }
            $this->drawings[] = ['rect' => [min($xs), min($ys), max($xs), max($ys)], 'type' => $type,
                                 'fill' => strpos($type, 'f') !== false ? $this->gs['fill'] : null,
                                 'color' => strpos($type, 's') !== false ? $this->gs['stroke'] : null,
                                 'width' => $this->gs['lw'], 'items' => array_map(function ($it) { return $it[0]; }, $this->path), 'segs' => $segs];
        }
        $this->path = [];
    }

    private function td($tx, $ty) {
        $g = &$this->gs;
        if (!isset($g['tlm'])) { $g['tlm'] = [1, 0, 0, 1, 0, 0]; }
        $g['tlm'] = pdft_mul([1, 0, 0, 1, $tx, $ty], $g['tlm']);
        $g['tm'] = $g['tlm'];
    }

    private function decodeTextString($s) {
        if (substr($s, 0, 2) === "\xFE\xFF") return (string)mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
        if (substr($s, 0, 3) === "\xEF\xBB\xBF") return substr($s, 3);
        return (string)mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }

    // نمایشِ یک رشته با فونتِ جاری
    private function show($bytes) {
        $g = &$this->gs;
        $f = $g['font'];
        if (!$f) return;
        if (!isset($g['tm'])) { $g['tm'] = [1, 0, 0, 1, 0, 0]; $g['tlm'] = $g['tm']; }
        if ($this->tosMode !== null && $this->tosMode !== $g['tr']) $this->flushText();
        $this->tosMode = $g['tr'];
        $size = $g['size']; $scale = $g['tz'];
        foreach ($f->codes($bytes) as [$code, $cid, $nb]) {
            $w = $f->width($cid);
            $w0 = $f->type === 'Type3' && $f->fm ? $w * $f->fm[0] : $w * 0.001;
            if ($f->wmode) {
                $tsm = [$size * $scale, 0, 0, $size, -500 * 0.001 * $size * $scale, $g['ts'] - 880 * $size * 0.001];
            } else $tsm = [$size * $scale, 0, 0, $size, 0, $g['ts']];
            $trm = pdft_mul(pdft_mul($tsm, $g['tm']), $g['ctm']);
            $isCid = false;
            $u = $f->unicode($code, $cid, $isCid);
            $chars = $u === '' || $isCid ? [0xFFFD] : array_map(function ($c) { return mb_ord($c, 'UTF-8'); }, preg_split('//u', $u, -1, PREG_SPLIT_NO_EMPTY));
            // حدسِ جهت از اولین نویسه (pdf_show_char)
            $this->bidi = $this->guessBidi($chars[0], $this->bidi);
            // تکه‌ی fz_text: فونت، جهت و ماتریسِ یکسان
            $key = $f->key . '|' . $f->wmode . '|' . $this->bidi . '|' . round($trm[0], 5) . ',' . round($trm[1], 5) . ',' . round($trm[2], 5) . ',' . round($trm[3], 5);
            $n = count($this->tos);
            if (!$n || $this->tos[$n - 1]['key'] !== $key) { $this->tos[] = ['key' => $key, 'font' => $f, 'bidi' => $this->bidi, 'items' => []]; $n++; }
            // FZ_STEXT_CLIP: نویسه‌ای که کاملاً بیرون از برش و صفحه است کنار گذاشته می‌شود (کادرِ تقریبیِ خودِ شکل)
            $clip = $this->gs['clip'];
            $gx = []; $gy = [];
            foreach ([[0, -0.2], [max($w0, 0.3), -0.2], [0, 0.7], [max($w0, 0.3), 0.7]] as $pt) { $q = pdft_pt($trm, $pt[0], $pt[1]); $gx[] = $q[0]; $gy[] = $q[1]; }
            if (min($gx) >= $clip[2] || max($gx) <= $clip[0] || min($gy) >= $clip[3] || max($gy) <= $clip[1]) $this->tos[$n - 1]['clipped'] = true;
            $this->tos[$n - 1]['items'][] = ['ucs' => $chars[0], 'cid' => $cid, 'gid' => $cid, 'trm' => $trm, 'adv' => $w0,
                                             'out' => min($gx) >= $clip[2] || max($gx) <= $clip[0] || min($gy) >= $clip[3] || max($gy) <= $clip[1]];
            for ($k = 1; $k < count($chars); $k++) $this->tos[$n - 1]['items'][] = ['ucs' => $chars[$k], 'cid' => -1, 'gid' => -1, 'trm' => $trm, 'adv' => 0, 'out' => end($this->tos[$n - 1]['items'])['out']];
            if ($f->wmode) $ty = $w0 * $size + $g['tc'] + ($code === 32 && $nb === 1 ? $g['tw'] : 0);
            else $tx = ($w0 * $size + $g['tc'] + ($code === 32 && $nb === 1 ? $g['tw'] : 0)) * $scale;
            $g['tm'] = $f->wmode ? pdft_mul([1, 0, 0, 1, 0, -$ty], $g['tm']) : pdft_mul([1, 0, 0, 1, $tx, 0], $g['tm']);
        }
    }

    // pdf_flush_text: تکه‌های جمع‌شده به سازنده‌ی متن فرستاده می‌شوند (با درنظرگرفتنِ ActualText)
    private function flushText() {
        if (!$this->tos) { $this->tosMode = null; return; }
        $spans = $this->tos;
        $this->tos = [];
        $this->tosMode = null;
        foreach ($spans as $sp) {
            $mt = $this->findActual();
            if ($mt !== null) $this->extractActual($sp, $mt);
            else $this->extractItems($sp, 0, count($sp['items']));
        }
    }

    private function findActual() {
        for ($k = count($this->mcStack) - 1; $k >= 0; $k--) if ($this->mcStack[$k] !== null && $this->mcStack[$k]['actual']) return $k;
        return null;
    }

    // do_extract
    private function extractItems(array $sp, $start, $end) {
        $cidFlag = false;
        for ($i = $start; $i < $end; $i++) {
            $it = $sp['items'][$i];
            if (!empty($it['out'])) { $this->last['clipped'] = true; continue; }
            $this->last = ['trm' => $it['trm'], 'bidi' => $sp['bidi'], 'font' => $sp['font'], 'wmode' => $sp['font']->wmode, 'valid' => true];
            $adv = $it['gid'] >= 0 ? $it['adv'] : 0;
            $u = $it['ucs'];
            if ($u === 0xFFFD && $it['cid'] > 0) { $u = $it['cid']; $cidFlag = true; }
            $this->cidFlag = $cidFlag;
            $this->addChar($sp['font'], $u, $it['gid'], $it['trm'], $adv, $sp['font']->wmode, $sp['bidi']);
        }
        $this->cidFlag = false;
    }

    // do_extract_within_actualtext
    private function extractActual(array $sp, $mk) {
        $text = preg_split('//u', $this->mcStack[$mk]['text'], -1, PREG_SPLIT_NO_EMPTY);
        $text = array_map(function ($c) { return mb_ord($c, 'UTF-8'); }, $text);
        $z = count($text);
        if ($z === 0) return;
        $items = $sp['items']; $len = count($items);
        $start = 0;
        while ($start < $len && $text && $items[$start]['ucs'] === $text[0]) { array_shift($text); $start++; }
        $z = count($text);
        if ($start) $this->extractItems($sp, 0, $start);
        if ($start === $len) { $this->mcStack[$mk]['text'] = $this->runesToStr($text); return; }
        $end = $len;
        while ($end > $start && $z > 0 && $items[$end - 1]['ucs'] === $text[$z - 1]) { $end--; $z--; }
        $pos = 0;
        for ($i = $start; $i < $end; $i++) {
            $it = $items[$i];
            if (!empty($it['out'])) { $this->last['clipped'] = true; continue; }
            $rune = -1;
            if ($i < $z && $pos < count($text)) $rune = $text[$pos++];   // همان شرطِ MuPDF (i < z)
            $this->last = ['trm' => $it['trm'], 'bidi' => $sp['bidi'], 'font' => $sp['font'], 'wmode' => $sp['font']->wmode, 'valid' => true];
            $adv = $it['gid'] >= 0 ? $it['adv'] : 0;
            if ($rune !== -1) $this->addChar($sp['font'], $rune, $it['gid'], $it['trm'], $adv, $sp['font']->wmode, $sp['bidi']);
        }
        $rest = array_slice($text, $pos);
        if ($end === $len) { $this->mcStack[$mk]['text'] = $this->runesToStr($rest); return; }
        // پسوندِ مشترک پیدا شد: باقیِ متن همین‌جا فرستاده می‌شود و بعد پسوند
        $this->flushActualRunes(array_slice($rest, 0, max(0, strlen($this->runesToStr($rest)) - ($len - $end))));   // MuPDF با طولِ بایتیِ UTF-8 می‌شمارد
        $this->extractItems($sp, $end, $len);
        $this->mcStack[$mk]['text'] = '';
    }

    private function runesToStr(array $r) { $o = ''; foreach ($r as $c) $o .= pdft_utf8($c); return $o; }

    // flush_actualtext
    private function flushActualRunes(array $runes) {
        if (!$runes || empty($this->last['valid']) || !empty($this->last['clipped'])) return;
        $glyph = -2;
        foreach ($runes as $r) {
            $this->addChar($this->last['font'], $r, $glyph, $this->last['trm'], 0, $this->last['wmode'], $this->last['bidi']);
            $glyph = -1;
        }
    }

    private function beginMarked($at) {
        $this->flushText();
        if ($at !== null) {
            $mk = $this->findActual();
            if ($mk !== null) $this->flushActualRunes(array_map(function ($c) { return mb_ord($c, 'UTF-8'); }, preg_split('//u', $this->mcStack[$mk]['text'], -1, PREG_SPLIT_NO_EMPTY)));
            $this->last['valid'] = false;
        }
        $this->mcStack[] = $at === null ? null : ['actual' => true, 'text' => $at];
    }

    private function endMarked() {
        if (!$this->mcStack) return;
        $top = end($this->mcStack);
        if ($top === null) { array_pop($this->mcStack); return; }
        $this->flushText();
        $top = array_pop($this->mcStack);
        if (!empty($this->last['valid'])) {
            $this->last['trm'][4] = $this->pen[0]; $this->last['trm'][5] = $this->pen[1];
            $this->flushActualRunes(array_map(function ($c) { return mb_ord($c, 'UTF-8'); }, preg_split('//u', $top['text'], -1, PREG_SPLIT_NO_EMPTY)));
            $this->last['valid'] = false;
        }
    }

    private function guessBidi($c, $cur) {
        switch (pdft_bidi_class($c)) {
            case 'L': return 0;
            case 'R': case 'AL': return 1;
            case 'EN': case 'ES': case 'ET': return 0;
            case 'AN': return 1;
            case 'CS': $this->flushText(); case 'NSM': case 'BN': case 'B': case 'S': case 'WS': case 'ON': return $cur;
        }
        return 0;
    }

    // ---------------------------------------------------------- همان fz_add_stext_char_imp
    private function mayAddSpace($last) { return $last !== 32 && ($last < 0x700 || ($last >= 0x2000 && $last <= 0x20CF)); }

    private function addChar(PdfTFont $font, $c, $glyph, array $trm, $adv, $wmode, $bidiLevel = 0) {
        if ($c === -1) return;
        $bidi = $bidiLevel & 1;
        $dir = $wmode ? pdft_vec($trm, 0, -1) : pdft_vec($trm, 1, 0);
        $dl = sqrt($dir[0] * $dir[0] + $dir[1] * $dir[1]) ?: 1;
        $ndir = [$dir[0] / $dl, $dir[1] / $dl];
        $size = sqrt(abs($trm[0] * $trm[3] - $trm[1] * $trm[2])) ?: 0.0001;
        $lagSize = $this->lagSize > 0 ? min($this->lagSize, $size) : $size;
        if (!$wmode) { $p = [$trm[4], $trm[5]]; $q = [$trm[4] + $adv * $dir[0], $trm[5] + $adv * $dir[1]]; }
        else { $p = [$trm[4] - $adv * $dir[0], $trm[5] - $adv * $dir[1]]; $q = [$trm[4], $trm[5]]; }
        $curLine = $this->curLine;
        $newPara = false; $newLine = true; $addSpace = 0;
        if ($curLine && ($glyph === -1 || pdft_is_mn($c))) {
            $this->pushChar($this->curLine, $font, $c, $this->pen, $this->pen, $trm, $size, $bidi, $glyph);
            $this->lastBidi = $bidi; $this->lastChar = $c;
            return;
        }
        if (!$curLine || $curLine['wmode'] !== $wmode || ($ndir[0] * $curLine['dir'][0] + $ndir[1] * $curLine['dir'][1]) < 0.999) {
            $newPara = true; $newLine = true;
        } else {
            $dist = hypot($p[0] - $this->lagPen[0], $p[1] - $this->lagPen[1]) / $size;
            if ($dist < PDFT_FAKE_BOLD_MAX_DIST && $c === $this->lastChar && $glyph >= 0) return;
            $dx = $p[0] - $this->pen[0]; $dy = $p[1] - $this->pen[1];
            $spacing = ($ndir[0] * $dx + $ndir[1] * $dy) / $size;
            $baseOff = (-$ndir[1] * $dx + $ndir[0] * $dy) / $lagSize;
            if (abs($baseOff) < PDFT_BASE_MAX_DIST) {
                if (($bidi & 1) !== ($this->lastBidi & 1)) $newLine = false;
                elseif ($bidi & 1) {
                    $ldx = $p[0] - $this->lagPen[0]; $ldy = $p[1] - $this->lagPen[1];
                    $logical = ($ndir[0] * $ldx + $ndir[1] * $ldy) / $size + $adv;
                    if (abs($logical) < PDFT_SPACE_DIST) $newLine = false;
                    elseif (abs($spacing) < PDFT_SPACE_DIST) { $bidi = 3; $newLine = false; }
                    elseif ($logical < 0 && $logical > -PDFT_SPACE_MAX_DIST) { if (!$wmode && $this->mayAddSpace($this->lastChar)) $addSpace = 1; $newLine = false; }
                    elseif ($spacing < 0 && $spacing > -PDFT_SPACE_MAX_DIST) $newLine = false;
                    elseif ($spacing > 0 && $spacing < PDFT_SPACE_MAX_DIST) { $bidi = 3; if (!$wmode && $this->mayAddSpace($this->lastChar)) $addSpace = 1 + ($spacing > PDFT_SPACE_DIST * 2 ? 1 : 0); $newLine = false; }
                    else $newLine = true;
                } else {
                    if (abs($spacing) < PDFT_SPACE_DIST) $newLine = false;
                    elseif ($spacing < 0 && $spacing > -PDFT_SPACE_MAX_DIST) $newLine = false;
                    elseif ($spacing > 0 && $spacing < PDFT_SPACE_MAX_DIST) { if (!$wmode && $this->mayAddSpace($this->lastChar)) $addSpace = 1 + ($spacing > PDFT_SPACE_DIST * 2 ? 1 : 0); $newLine = false; }
                    else $newLine = true;
                }
            } elseif (abs($baseOff) <= PDFT_PARAGRAPH_DIST) {
                if (!$wmode && $curLine) {
                    $ds = ($ndir[0] * ($p[0] - $this->start[0]) + $ndir[1] * ($p[1] - $this->start[1])) / $size;
                    if ($ds > 0.5 && !$this->maybeBullet) $newPara = true;
                }
                $newLine = true;
            } else { $newPara = true; $newLine = true; }
        }
        if ($newPara || $this->curBlock === null) {
            $this->blocks[] = ['lines' => []];
            $this->curBlock = count($this->blocks) - 1;
            $curLine = null;
        }
        if ($newLine || !$curLine) {
            $this->blocks[$this->curBlock]['lines'][] = ['dir' => $ndir, 'wmode' => $wmode, 'chars' => []];
            $li = count($this->blocks[$this->curBlock]['lines']) - 1;
            $this->curLine = &$this->blocks[$this->curBlock]['lines'][$li];
            $curLine = $this->curLine;
            $this->start = $p;
            $this->maybeBullet = $glyph === -2 ? true : $this->plausibleBullet($c);
        }
        if ($glyph === -2) $glyph = -1;
        if ($c !== 32 && $addSpace) $this->pushChar($this->curLine, $font, 32, $this->pen, $p, $trm, $size, $bidi, -1, true);
        $this->pushChar($this->curLine, $font, $c, $p, $q, $trm, $size, $bidi, $glyph);
        $this->lastChar = $c; $this->lastBidi = $bidi;
        $this->lagPen = $p; $this->lagSize = $size; $this->pen = $q;
    }

    private function plausibleBullet($c) {
        return in_array($c, [0x2A, 0xB7, 0x2022, 0x2023, 0x2043, 0x204C, 0x204D, 0x2219, 0x25C9, 0x25CB, 0x25CF, 0x25D8, 0x25E6, 0xFFFD], true) || ($c >= 0x30 && $c <= 0x39);
    }

    private function charBox(PdfTFont $font, array $trm, $p, $q, $glyph) {
        // همان JM_char_quad در PyMuPDF: ascender/descenderِ نامعقول اصلاح می‌شود
        $asc = $font->asc; $dsc = $font->desc;
        if ($asc < 1e-3) { $dsc = -0.1; $asc = 0.9; }
        elseif ($asc - $dsc < 1) { $ad = $asc - $dsc + 1.2e-7; $dsc = $dsc / $ad; $asc = $asc / $ad; }
        $a = pdft_vec($trm, 0, $asc); $d = pdft_vec($trm, 0, $dsc);
        $xs = [$p[0] + $d[0], $p[0] + $a[0], $q[0] + $d[0], $q[0] + $a[0]];
        $ys = [$p[1] + $d[1], $p[1] + $a[1], $q[1] + $d[1], $q[1] + $a[1]];
        return [min($xs), min($ys), max($xs), max($ys)];
    }

    private function pushChar(&$line, PdfTFont $font, $c, $p, $q, array $trm, $size, $bidi, $glyph, $synthetic = false) {
        $line['chars'][] = ['c' => $c, 'o' => $p, 'bbox' => $this->charBox($font, $trm, $p, $q, $glyph), 'size' => $size, 'font' => $font->name,
                            'fk' => $font->key, 'bidi' => $bidi, 'color' => $this->gs['fill'], 'bold' => $font->bold, 'syn' => $synthetic, 'cidf' => $this->cidFlag];
    }

    // fixup_bboxes_and_bidi: خط‌هایی که «نمایشی» نوشته شده‌اند (bidi=3) تکه‌های راست‌به‌چپ‌شان برعکس می‌شود
    private function fixup() {
        unset($this->curLine);
        $this->curLine = null;
        foreach ($this->blocks as &$b) {
            foreach ($b['lines'] as &$l) {
                $re = false;
                foreach ($l['chars'] as $ch) if ($ch['bidi'] === 3) { $re = true; break; }
                if ($re) {
                    $out = []; $n = count($l['chars']); $i = 0;
                    while ($i < $n) {
                        if ($l['chars'][$i]['bidi']) {
                            $j = $i;
                            while ($j + 1 < $n && $l['chars'][$j + 1]['bidi']) $j++;
                            for ($k = $j; $k >= $i; $k--) $out[] = $l['chars'][$k];
                            $i = $j + 1;
                        } else { $out[] = $l['chars'][$i]; $i++; }
                    }
                    $l['chars'] = $out;
                }
            }
            unset($l);
        }
        unset($b);
    }

    // ---------------------------------------------------------- خروجی‌ها
    function dict($idx) {
        [$w, $h] = $this->run($idx);
        $blocks = [];
        foreach ($this->blocks as $b) {
            $lines = [];
            foreach ($b['lines'] as $l) {
                if (!$l['chars']) continue;
                $spans = [];
                $cur = null;
                $fy = $l['chars'][0]['o'][1];
                $horiz = !$l['wmode'] && abs($l['dir'][0] - 1) < 1e-6 && abs($l['dir'][1]) < 1e-6;
                foreach ($l['chars'] as $ch) {
                    if (!$this->overlaps($ch['bbox'])) continue;
                    $sup = $horiz && $ch['o'][1] < $fy - $ch['size'] * 0.1 ? 1 : 0;
                    $sig = $ch['font'] . '|' . $ch['size'] . '|' . implode(',', array_map(function ($v) { return round($v * 255); }, $ch['color'])) . '|' . ($ch['bold'] ? 1 : 0) . '|' . $sup . '|' . ($ch['cidf'] ? 1 : 0);
                    if ($cur === null || $cur['sig'] !== $sig) {
                        if ($cur !== null) $spans[] = $cur;
                        $cur = ['sig' => $sig, 'text' => '', 'bbox' => $ch['bbox'], 'size' => $ch['size'], 'font' => $ch['font'], 'origin' => $ch['o'], 'color' => $ch['color'], 'chars' => []];
                    }
                    $cur['text'] .= pdft_utf8($ch['c']);
                    $cur['bbox'] = pdft_union($cur['bbox'], $ch['bbox']);
                    $cur['chars'][] = ['c' => pdft_utf8($ch['c']), 'bbox' => $ch['bbox'], 'origin' => $ch['o']];
                }
                if ($cur !== null) $spans[] = $cur;
                if (!$spans) continue;
                $lb = null;
                foreach ($spans as &$sp) { unset($sp['sig']); $lb = $lb === null ? $sp['bbox'] : pdft_union($lb, $sp['bbox']); }
                unset($sp);
                $lines[] = ['bbox' => $lb, 'dir' => $l['dir'], 'wmode' => $l['wmode'], 'spans' => $spans];
            }
            if (!$lines) continue;
            $bb = $lines[0]['bbox'];
            foreach ($lines as $l) $bb = [min($bb[0], $l['bbox'][0]), min($bb[1], $l['bbox'][1]), max($bb[2], $l['bbox'][2]), max($bb[3], $l['bbox'][3])];
            $blocks[] = ['type' => 0, 'bbox' => $bb, 'lines' => $lines];
        }
        return ['width' => $w, 'height' => $h, 'blocks' => $blocks];
    }

    private function overlaps($r) {
        $b = $this->box;
        return !($r[0] >= $b[2] || $r[1] >= $b[3] || $r[2] <= $b[0] || $r[3] <= $b[1]);
    }

    function text($idx) {
        $d = $this->dict($idx);
        $o = '';
        foreach ($d['blocks'] as $b) {
            foreach ($b['lines'] as $l) {
                $t = ''; foreach ($l['spans'] as $s) $t .= $s['text'];
                if ($t !== '' && substr($t, -1) !== "\n") $t .= "\n";
                $o .= $t;
            }
        }
        return $o;
    }

    function words($idx, $dict = null) {
        $d = $dict ?: $this->dict($idx);
        $out = [];
        foreach ($d['blocks'] as $bn => $b) {
            foreach ($b['lines'] as $ln => $l) {
                $wn = 0; $cur = ''; $bb = null;
                $flush = function () use (&$cur, &$bb, &$out, &$wn, $bn, $ln) {
                    if ($cur !== '' && !pdft_empty($bb)) { $out[] = [$bb[0], $bb[1], $bb[2], $bb[3], $cur, $bn, $ln, $wn++]; }
                    $cur = ''; $bb = null;
                };
                $lastRtl = false;
                foreach ($l['spans'] as $s) foreach ($s['chars'] as $ch) {
                    // همان extractWORDS در PyMuPDF: جداکننده = هر نویسه‌ی ≤ ۳۲، فاصله‌ی نشکن، نشانه‌های جهت؛ تغییرِ راست‌به‌چپ هم کلمه را می‌شکند
                    $cp = mb_ord($ch['c'], 'UTF-8');
                    if ($cur === '' && $cp === 0x200D) continue;
                    $delim = $cp <= 32 || $cp === 160 || ($cp >= 0x202A && $cp <= 0x202E);
                    $rtl = $cp >= 0x590 && $cp <= 0x900;
                    if ($delim || $rtl !== $lastRtl) {
                        if ($cur === '' && $delim) continue;
                        $flush();
                        if ($delim) continue;
                    }
                    $lastRtl = $rtl;
                    $cur .= $ch['c'];
                    $bb = pdft_union($bb, $ch['bbox']);
                }
                $flush();
            }
        }
        return $out;
    }

    function drawings($idx) { $this->run($idx); return $this->drawings; }

    // تکه‌ها با متنِ عادی‌شده (همان page_spans در خواننده‌های پایتونی)
    function spans($idx, $normalize = true) {
        $d = $this->dict($idx);
        $out = [];
        foreach ($d['blocks'] as $b) foreach ($b['lines'] as $l) foreach ($l['spans'] as $sp) {
            $t = $normalize ? trim(pdf_norm_text($sp['text'])) : $sp['text'];
            if ($t === '') continue;
            $out[] = ['t' => $t, 'x0' => $sp['bbox'][0], 'y0' => $sp['bbox'][1], 'x1' => $sp['bbox'][2], 'y1' => $sp['bbox'][3], 'size' => $sp['size']];
        }
        return $out;
    }

    // ---------------------------------------------------------- بارگذاریِ فونت
    private function loadFont($res, $name) {
        $fd = $this->resDict($res, 'Font');
        if (!$fd) return $this->fallbackFont();
        return $this->loadFontObj($fd->get($name)) ?: $this->fallbackFont();
    }

    private function fallbackFont() {
        if (!isset($this->fonts['__fb'])) { $f = new PdfTFont(); $f->name = 'Helvetica'; $f->key = '__fb'; $this->fonts['__fb'] = $f; }
        return $this->fonts['__fb'];
    }

    private function loadFontObj($ref) {
        $key = $ref instanceof PdfRef ? 'r' . $ref->n : 'd' . md5(serialize($ref));
        if (isset($this->fonts[$key])) return $this->fonts[$key];
        $d = $this->doc->resolve($ref);
        if (!$d instanceof PdfDict) return null;
        $f = new PdfTFont();
        $f->key = $key;
        $f->type = (string)$d->get('Subtype', 'Type1');
        $base = (string)$this->doc->resolve($d->get('BaseFont', ''));
        $f->name = preg_replace('/^[A-Z]{6}\+/', '', $base) ?: $f->type;
        $f->bold = (bool)preg_match('/bold|black|heavy/i', $base);
        $desc = null;
        if ($f->type === 'Type0') {
            $f->cid = true;
            $df = $this->doc->resolve($d->get('DescendantFonts'));
            $cf = is_array($df) && $df ? $this->doc->resolve($df[0]) : null;
            if ($cf instanceof PdfDict) {
                $f->dw = (float)$this->doc->resolve($cf->get('DW', 1000));
                $W = $this->doc->resolve($cf->get('W'));
                if (is_array($W)) {
                    $i = 0; $n = count($W);
                    while ($i < $n) {
                        $c0 = (int)$this->doc->resolve($W[$i]);
                        $nx = $this->doc->resolve($W[$i + 1] ?? null);
                        if (is_array($nx)) { foreach ($nx as $k => $wv) $f->w[$c0 + $k] = (float)$this->doc->resolve($wv); $i += 2; }
                        else { $c1 = (int)$nx; $wv = (float)$this->doc->resolve($W[$i + 2] ?? 0); if ($c1 - $c0 < 70000) for ($c = $c0; $c <= $c1; $c++) $f->w[$c] = $wv; $i += 3; }
                    }
                }
                $desc = $this->doc->resolve($cf->get('FontDescriptor'));
            }
            $enc = $this->doc->resolve($d->get('Encoding'));
            if ($enc instanceof PdfStream) { $f->cmap = $this->parseCidCmap($this->doc->decodeStream($enc)); if (preg_match('/\/WMode\s+1/', $this->doc->decodeStream($enc))) $f->wmode = 1; }
            elseif (is_string($enc) && substr($enc, -2) === '-V') $f->wmode = 1;
            // نبودِ ToUnicode: نگاشتِ gid => یونیکد از جدولِ cmapِ فونتِ TrueTypeِ داخلی
            if (!$d->get('ToUnicode') && $desc instanceof PdfDict && $cf instanceof PdfDict) {
                $ff = $this->doc->resolve($desc->get('FontFile2'));
                if ($ff instanceof PdfStream) {
                    $g2u = $this->ttfGidToUni($this->doc->decodeStream($ff));
                    if ($g2u) {
                        $map = $this->doc->resolve($cf->get('CIDToGIDMap'));
                        if ($map instanceof PdfStream) {
                            $m = $this->doc->decodeStream($map); $f->gidUni = [];
                            for ($c = 0, $l = intval(strlen($m) / 2); $c < $l; $c++) { $gid = (ord($m[2 * $c]) << 8) | ord($m[2 * $c + 1]); if (isset($g2u[$gid])) $f->gidUni[$c] = $g2u[$gid]; }
                        } else $f->gidUni = $g2u;
                    }
                }
            }
        } else {
            $f->first = (int)$this->doc->resolve($d->get('FirstChar', 0));
            $W = $this->doc->resolve($d->get('Widths'));
            if (is_array($W)) foreach ($W as $k => $wv) $f->w[$f->first + $k] = (float)$this->doc->resolve($wv);
            $desc = $this->doc->resolve($d->get('FontDescriptor'));
            if ($f->type === 'Type3') {
                $fm = $this->doc->resolve($d->get('FontMatrix'));
                $f->fm = is_array($fm) && count($fm) === 6 ? array_map(function ($v) { return (float)$this->doc->resolve($v); }, $fm) : [0.001, 0, 0, 0.001, 0, 0];
            }
            if (!$W && !$desc) $this->std14Widths($f, $base);
            $f->enc = $this->simpleEncoding($d, $base);
        }
        $this->fontMetrics($f, $desc, $base);
        if ($desc instanceof PdfDict) {
            $f->missing = (float)$this->doc->resolve($desc->get('MissingWidth', 0));
            $fl = (int)$this->doc->resolve($desc->get('Flags', 0));
            if (($fl & 262144) || (float)$this->doc->resolve($desc->get('FontWeight', 0)) >= 600) $f->bold = true;
        } elseif ($f->type === 'Type3') {
            $bb = $this->doc->numArr($d->get('FontBBox'));
            if ($bb && $f->fm && $f->fm[3]) { $f->asc = $bb[3] * $f->fm[3]; $f->desc = $bb[1] * $f->fm[3]; if ($f->asc <= 0) $f->asc = 0.8; if ($f->desc >= 0) $f->desc = -0.2; }
        }
        $tu = $this->doc->resolve($d->get('ToUnicode'));
        if ($tu instanceof PdfStream) $f->toUni = $this->parseToUnicode($this->doc->decodeStream($tu));
        $this->fonts[$key] = $f;
        return $f;
    }

    private function std14Widths(PdfTFont $f, $base) {
        $m = PDFT_STD14[pdft_std14_name($base)];
        if ($m[2] === '') { $f->missing = 600; return; }
        foreach (explode(',', $m[2]) as $k => $w) $f->w[32 + $k] = (float)$w;
        $f->missing = 500;
    }

    // ascender/descender مثلِ MuPDF (pdf_load_font_descriptor): اولویت با Ascent/Descentِ FontDescriptor، بعد جدولِ hheaِ فونتِ
    // TrueTypeِ داخلی، وگرنه ۰٫۸/−۰٫۲؛ فونتِ ساده‌ی بدونِ FontDescriptor => متریکِ فونتِ پایه (Nimbus)
    private function fontMetrics(PdfTFont $f, $desc, $base) {
        if (!$desc instanceof PdfDict) {
            if ($f->type !== 'Type3') { $m = PDFT_STD14[pdft_std14_name($base)]; $f->asc = $m[0]; $f->desc = $m[1]; }
            return;
        }
        $a = 800.0; $d = -200.0;
        $ff = $this->doc->resolve($desc->get('FontFile2'));
        if ($ff instanceof PdfStream) {
            $ttf = $this->doc->decodeStream($ff);
            $hhea = $this->ttfTable($ttf, 'hhea'); $head = $this->ttfTable($ttf, 'head');
            if ($hhea !== null && $head !== null && strlen($hhea) >= 8 && strlen($head) >= 20) {
                $upm = unpack('n', substr($head, 18, 2))[1] ?: 1000;
                $ha = unpack('n', substr($hhea, 4, 2))[1]; $hd = unpack('n', substr($hhea, 6, 2))[1];
                if ($ha > 32767) $ha -= 65536; if ($hd > 32767) $hd -= 65536;
                $a = 1000 * $ha / $upm; $d = 1000 * $hd / $upm;
            }
        }
        $va = $this->doc->resolve($desc->get('Ascent')); $vd = $this->doc->resolve($desc->get('Descent'));
        if (is_numeric($va)) $a = (float)$va;
        if (is_numeric($vd)) $d = (float)$vd;
        if ($d > 0) $d = -$d;
        if ($a <= 0 || $a > 8000 || $d < -3000) { $f->asc = 0.8; $f->desc = -0.2; }
        else { $f->asc = $a / 1000; $f->desc = $d / 1000; }
    }

    private function ttfTable($ttf, $tag) {
        if (strlen($ttf) < 12) return null;
        $num = unpack('n', substr($ttf, 4, 2))[1];
        for ($i = 0; $i < $num && $i < 64; $i++) {
            $rec = substr($ttf, 12 + 16 * $i, 16);
            if (substr($rec, 0, 4) === $tag) { $o = unpack('N', substr($rec, 8, 4))[1]; $l = unpack('N', substr($rec, 12, 4))[1]; return substr($ttf, $o, $l); }
        }
        return null;
    }

    private function hexInt($h) { $h = preg_replace('/[^0-9A-Fa-f]/', '', $h); return $h === '' ? 0 : hexdec($h); }
    private function hexUtf8($h) {
        $h = preg_replace('/[^0-9A-Fa-f]/', '', $h);
        if ($h === '') return '';
        if (strlen($h) % 4) $h = str_pad($h, intval((strlen($h) + 3) / 4) * 4, '0', STR_PAD_LEFT);
        $r = @mb_convert_encoding((string)hex2bin($h), 'UTF-8', 'UTF-16BE');
        return $r === false ? '' : $r;
    }

    function parseToUnicode($s) {
        $map = [];
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $s, $mm)) foreach ($mm[1] as $blk) {
            if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*<([0-9A-Fa-f\s]*)>/', $blk, $e, PREG_SET_ORDER)) foreach ($e as $x) $map[$this->hexInt($x[1])] = $this->hexUtf8($x[2]);
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $s, $mm)) foreach ($mm[1] as $blk) {
            if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*<([0-9A-Fa-f\s]+)>\s*(<[0-9A-Fa-f\s]*>|\[[^\]]*\])/', $blk, $e, PREG_SET_ORDER)) foreach ($e as $x) {
                $lo = $this->hexInt($x[1]); $hi = $this->hexInt($x[2]);
                if ($hi < $lo || $hi - $lo > 65535) continue;
                if ($x[3][0] === '[') {
                    preg_match_all('/<([0-9A-Fa-f\s]*)>/', $x[3], $arr);
                    foreach ($arr[1] as $k => $h) if ($lo + $k <= $hi) $map[$lo + $k] = $this->hexUtf8($h);
                } else {
                    $h = preg_replace('/[^0-9A-Fa-f]/', '', substr($x[3], 1, -1));
                    if (strlen($h) % 4) $h = str_pad($h, intval((strlen($h) + 3) / 4) * 4, '0', STR_PAD_LEFT);
                    $prefix = substr($h, 0, -4); $last = hexdec(substr($h, -4));
                    for ($c = $lo; $c <= $hi; $c++) {
                        $v = $last + ($c - $lo);
                        $map[$c] = $this->hexUtf8($prefix . sprintf('%04X', $v & 0xFFFF));
                    }
                }
            }
        }
        return $map;
    }

    private function parseCidCmap($s) {
        $cm = ['ranges' => [], 'map' => [], 'rng' => [], 'identity' => false];
        if (preg_match_all('/begincodespacerange(.*?)endcodespacerange/s', $s, $mm)) foreach ($mm[1] as $blk) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $e, PREG_SET_ORDER)) foreach ($e as $x) $cm['ranges'][] = [intval(strlen($x[1]) / 2), hexdec($x[1]), hexdec($x[2])];
        }
        if (preg_match_all('/begincidrange(.*?)endcidrange/s', $s, $mm)) foreach ($mm[1] as $blk) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(\d+)/', $blk, $e, PREG_SET_ORDER)) foreach ($e as $x) $cm['rng'][] = [hexdec($x[1]), hexdec($x[2]), (int)$x[3]];
        }
        if (preg_match_all('/begincidchar(.*?)endcidchar/s', $s, $mm)) foreach ($mm[1] as $blk) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*(\d+)/', $blk, $e, PREG_SET_ORDER)) foreach ($e as $x) $cm['map'][hexdec($x[1])] = (int)$x[2];
        }
        if (!$cm['ranges']) $cm['ranges'][] = [2, 0, 0xFFFF];
        if (!$cm['rng'] && !$cm['map']) $cm['identity'] = true;
        return $cm;
    }

    // جدولِ cmapِ فونتِ TrueType => [gid => نویسه]
    private function ttfGidToUni($ttf) {
        if (strlen($ttf) < 12) return [];
        $num = unpack('n', substr($ttf, 4, 2))[1];
        $off = null;
        for ($i = 0; $i < $num; $i++) { $rec = substr($ttf, 12 + 16 * $i, 16); if (substr($rec, 0, 4) === 'cmap') { $off = unpack('N', substr($rec, 8, 4))[1]; break; } }
        if ($off === null) return [];
        $n = unpack('n', substr($ttf, $off + 2, 2))[1];
        $best = null;
        for ($i = 0; $i < $n; $i++) {
            $r = unpack('npid/neid/Noff', substr($ttf, $off + 4 + 8 * $i, 8));
            if (($r['pid'] === 3 && ($r['eid'] === 1 || $r['eid'] === 10)) || $r['pid'] === 0) { $best = $off + $r['off']; if ($r['eid'] === 10) break; }
        }
        if ($best === null) return [];
        $fmt = unpack('n', substr($ttf, $best, 2))[1];
        $out = [];
        if ($fmt === 4) {
            $segX2 = unpack('n', substr($ttf, $best + 6, 2))[1];
            $seg = $segX2 / 2;
            $ends = array_values(unpack('n*', substr($ttf, $best + 14, $segX2)));
            $starts = array_values(unpack('n*', substr($ttf, $best + 16 + $segX2, $segX2)));
            $deltas = array_values(unpack('n*', substr($ttf, $best + 16 + 2 * $segX2, $segX2)));
            $roOff = $best + 16 + 3 * $segX2;
            $ros = array_values(unpack('n*', substr($ttf, $roOff, $segX2)));
            for ($s = 0; $s < $seg; $s++) {
                if ($ends[$s] - $starts[$s] > 20000) continue;
                for ($c = $starts[$s]; $c <= $ends[$s] && $c !== 0xFFFF; $c++) {
                    if ($ros[$s] === 0) $g = ($c + $deltas[$s]) & 0xFFFF;
                    else { $gp = $roOff + 2 * $s + $ros[$s] + 2 * ($c - $starts[$s]); $g = unpack('n', substr($ttf, $gp, 2) ?: "\0\0")[1]; if ($g) $g = ($g + $deltas[$s]) & 0xFFFF; }
                    if ($g && !isset($out[$g])) $out[$g] = pdft_utf8($c);
                }
            }
        } elseif ($fmt === 12) {
            $ng = unpack('N', substr($ttf, $best + 12, 4))[1];
            for ($i = 0; $i < $ng && $i < 5000; $i++) {
                $r = unpack('Ns/Ne/Ng', substr($ttf, $best + 16 + 12 * $i, 12));
                if ($r['e'] - $r['s'] > 20000) continue;
                for ($c = $r['s']; $c <= $r['e']; $c++) { $g = $r['g'] + $c - $r['s']; if (!isset($out[$g])) $out[$g] = pdft_utf8($c); }
            }
        }
        return $out;
    }

    private function simpleEncoding(PdfDict $d, $base) {
        $enc = $this->doc->resolve($d->get('Encoding'));
        $baseEnc = 'WinAnsiEncoding'; $diff = null;
        if (is_string($enc)) $baseEnc = $enc;
        elseif ($enc instanceof PdfDict) { $baseEnc = (string)$enc->get('BaseEncoding', 'WinAnsiEncoding'); $diff = $this->doc->resolve($enc->get('Differences')); }
        $map = [];
        $cs = $baseEnc === 'MacRomanEncoding' ? 'MACINTOSH' : 'CP1252';
        if (preg_match('/Symbol|Dingbat/i', $base) && !$enc) { for ($c = 32; $c < 256; $c++) $map[$c] = pdft_utf8($c); }
        else for ($c = 32; $c < 256; $c++) { $u = @iconv($cs, 'UTF-8//IGNORE', chr($c)); $map[$c] = ($u === false || $u === '') ? pdft_utf8($c) : $u; }
        if (is_array($diff)) {
            $code = 0;
            foreach ($diff as $x) {
                $x = $this->doc->resolve($x);
                if (is_int($x) || is_float($x)) { $code = (int)$x; continue; }
                if (is_string($x)) { $u = $this->glyphUni($x); if ($u !== '') $map[$code] = $u; $code++; }
            }
        }
        return $map;
    }

    private function glyphUni($n) {
        if (preg_match('/^uni((?:[0-9A-Fa-f]{4})+)$/', $n, $m)) { $o = ''; foreach (str_split($m[1], 4) as $h) $o .= pdft_utf8(hexdec($h)); return $o; }
        if (preg_match('/^u([0-9A-Fa-f]{4,6})$/', $n, $m)) return pdft_utf8(hexdec($m[1]));
        if ($this->glyphNames === null) {
            $g = ['space' => ' ', 'exclam' => '!', 'quotedbl' => '"', 'numbersign' => '#', 'dollar' => '$', 'percent' => '%', 'ampersand' => '&', 'quotesingle' => "'", 'quoteright' => "\u{2019}", 'quoteleft' => "\u{2018}",
                  'parenleft' => '(', 'parenright' => ')', 'asterisk' => '*', 'plus' => '+', 'comma' => ',', 'hyphen' => '-', 'minus' => "\u{2212}", 'period' => '.', 'slash' => '/', 'colon' => ':', 'semicolon' => ';',
                  'less' => '<', 'equal' => '=', 'greater' => '>', 'question' => '?', 'at' => '@', 'bracketleft' => '[', 'backslash' => '\\', 'bracketright' => ']', 'asciicircum' => '^', 'underscore' => '_',
                  'grave' => '`', 'braceleft' => '{', 'bar' => '|', 'braceright' => '}', 'asciitilde' => '~', 'bullet' => "\u{2022}", 'endash' => "\u{2013}", 'emdash' => "\u{2014}", 'quotedblleft' => "\u{201C}",
                  'quotedblright' => "\u{201D}", 'ellipsis' => "\u{2026}", 'degree' => "\u{B0}", 'nbspace' => "\u{A0}", 'nonbreakingspace' => "\u{A0}",
                  'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9'];
            foreach (range('A', 'Z') as $c) $g[$c] = $c;
            foreach (range('a', 'z') as $c) $g[$c] = $c;
            $this->glyphNames = $g;
        }
        $base = preg_replace('/\..*$/', '', $n);
        return $this->glyphNames[$base] ?? '';
    }
}

class PdfTName { public $n; function __construct($n) { $this->n = $n; } }

// همان normalize_text ِ خواننده‌های پایتونی: NFKC، ی/ک عربی، نیم‌فاصله => فاصله، حذفِ کشیده، رقم‌های فارسی/عربی => انگلیسی
function pdf_norm_text($t) {
    if ($t === null || $t === '') return '';
    $t = pdft_nfkc($t);
    $t = strtr($t, ['ي' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ', 'ـ' => '',
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    return $t;
}

// متنِ همه‌ی صفحه‌ها (یا $maxPages صفحه‌ی اول)
function pdfx_text($abs, $maxPages = 0) {
    $doc = PdfDoc::open($abs);
    $t = new PdfText($doc);
    $n = $doc->pageCount();
    if ($maxPages > 0) $n = min($n, $maxPages);
    $o = [];
    for ($i = 0; $i < $n; $i++) $o[] = $t->text($i);
    return $o;
}
