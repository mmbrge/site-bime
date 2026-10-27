<?php
// فایل: api/_pdf.php
// موتورِ PDF با PHPِ خالص (بدونِ پایتون، بدونِ برنامه‌ی بیرونی): خواندنِ ساختارِ PDF (جدولِ xref معمولی و فشرده، اشیای فشرده،
// رمزگذاریِ استاندارد RC4/AES)، شمارِ صفحه‌ها، و ساختنِ PDFِ تازه از صفحه‌های دلخواهِ یک یا چند PDF (جدا کردن، ادغام، چرخاندن).
// خواندنِ متن با مختصات: api/_pdf_text.php
//
// نمایشِ اشیا در PHP:  null/bool/int/float همان؛ نام (/Type) = رشته‌ی PHP؛ رشته‌ی PDF = PdfStr؛ آرایه = آرایه‌ی PHP (list)؛
// دیکشنری = PdfDict (->v)؛ ارجاع = PdfRef؛ جریان = PdfStream (->dict، ->raw داده‌ی رمزگشایی‌شده ولی فشرده)

class PdfStr { public $s; function __construct($s) { $this->s = $s; } }
class PdfRef { public $n, $g; function __construct($n, $g = 0) { $this->n = (int)$n; $this->g = (int)$g; } }
class PdfDict {
    public $v;
    function __construct(array $v = []) { $this->v = $v; }
    function get($k, $def = null) { return array_key_exists($k, $this->v) ? $this->v[$k] : $def; }
}
class PdfRaw { public $s; function __construct($s) { $this->s = (string)$s; } }   // متنِ خامِ PDF (ارجاع به شیءِ تازه‌ی نویسنده)
class PdfStream { public $dict, $raw, $n = 0, $g = 0; function __construct(PdfDict $d, $raw) { $this->dict = $d; $this->raw = $raw; } }
class PdfException extends Exception {}

class PdfDoc {
    public $data;              // بایت‌های فایل
    public $xref = [];         // n => ['t'=>1,'off'=>..,'g'=>..] | ['t'=>2,'stm'=>..,'idx'=>..]
    public $trailer;           // PdfDict
    private $cache = [];
    private $objStm = [];      // n => [n => value]
    private $crypt = null;     // ['key'=>.., 'v'=>.., 'r'=>.., 'aes'=>bool, 'aes256'=>bool, 'encMeta'=>bool]
    private $pages = null;
    public $repaired = false;

    static function open($path) {
        if (!is_file($path)) throw new PdfException('فایل پیدا نشد.');
        $d = file_get_contents($path);
        if ($d === false || $d === '') throw new PdfException('فایل خوانده نشد.');
        return new PdfDoc($d);
    }

    function __construct($data) {
        $this->data = $data;
        $p = strpos($data, '%PDF-');
        if ($p === false || $p > 1024) throw new PdfException('این فایل PDF نیست.');
        try {
            $this->readXref();
        } catch (Throwable $e) {
            $this->rebuildXref();
        }
        if (!$this->trailer || !$this->trailer->get('Root')) $this->rebuildXref();
        $this->setupCrypt();
        $root = $this->resolve($this->trailer->get('Root'));
        if (!$root instanceof PdfDict || !$this->resolve($root->get('Pages'))) {
            if (!$this->repaired) { $this->rebuildXref(); $this->cache = []; $this->objStm = []; $this->setupCrypt(); }
        }
    }

    // ---------------- xref ----------------
    private function readXref() {
        $tail = substr($this->data, -2048);
        if (!preg_match_all('/startxref\s+(\d+)/', $tail, $m)) throw new PdfException('startxref');
        $off = (int)end($m[1]);
        $seen = [];
        $first = true;
        while ($off > 0 && !isset($seen[$off])) {
            $seen[$off] = 1;
            $tr = $this->readXrefAt($off);
            if ($first) { $this->trailer = $tr; $first = false; }
            // جدولِ ترکیبی (XRefStm)
            $xs = $tr->get('XRefStm');
            if (is_int($xs) && !isset($seen[$xs])) { $seen[$xs] = 1; $this->readXrefAt($xs); }
            $prev = $tr->get('Prev');
            $off = is_int($prev) ? $prev : (is_float($prev) ? (int)$prev : 0);
        }
        if (!$this->xref) throw new PdfException('xref خالی');
    }

    private function readXrefAt($off) {
        $len = strlen($this->data);
        if ($off >= $len) throw new PdfException('xref offset');
        $i = $off;
        $this->skipWs($i);
        if (substr($this->data, $i, 4) === 'xref') {
            $i += 4;
            while (true) {
                $this->skipWs($i);
                if (substr($this->data, $i, 7) === 'trailer') { $i += 7; break; }
                if (!preg_match('/\G(\d+)\s+(\d+)/', $this->data, $mm, 0, $i)) throw new PdfException('xref section');
                $start = (int)$mm[1]; $cnt = (int)$mm[2];
                $i += strlen($mm[0]);
                $this->skipWs($i);
                for ($k = 0; $k < $cnt; $k++) {
                    if (!preg_match('/\G(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->data, $e, 0, $i)) { $this->skipWs($i); if (!preg_match('/\G(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->data, $e, 0, $i)) throw new PdfException('xref entry'); }
                    $i += strlen($e[0]);
                    while ($i < $len && strpos(" \r\n", $this->data[$i]) !== false) $i++;
                    $n = $start + $k;
                    if (!isset($this->xref[$n]) && $e[3] === 'n') $this->xref[$n] = ['t' => 1, 'off' => (int)$e[1], 'g' => (int)$e[2]];
                    elseif (!isset($this->xref[$n])) $this->xref[$n] = ['t' => 0];
                }
            }
            $tr = $this->parseAt($i);
            if (!$tr instanceof PdfDict) throw new PdfException('trailer');
            return $tr;
        }
        // جریانِ xref (PDF 1.5+)
        $obj = $this->parseIndirectAt($off, $n0);
        if (!$obj instanceof PdfStream || $obj->dict->get('Type') !== 'XRef') throw new PdfException('xref stream');
        $d = $obj->dict;
        $w = $d->get('W');
        $size = (int)$d->get('Size', 0);
        $idx = $d->get('Index', [0, $size]);
        $raw = $this->decodeStream($obj, false);
        $w0 = (int)$w[0]; $w1 = (int)$w[1]; $w2 = (int)$w[2];
        $rl = $w0 + $w1 + $w2;
        $p = 0;
        for ($s = 0; $s + 1 < count($idx); $s += 2) {
            $start = (int)$idx[$s]; $cnt = (int)$idx[$s + 1];
            for ($k = 0; $k < $cnt; $k++) {
                if ($p + $rl > strlen($raw)) break 2;
                $f0 = $w0 ? $this->beInt(substr($raw, $p, $w0)) : 1;
                $f1 = $this->beInt(substr($raw, $p + $w0, $w1));
                $f2 = $w2 ? $this->beInt(substr($raw, $p + $w0 + $w1, $w2)) : 0;
                $p += $rl;
                $n = $start + $k;
                if (isset($this->xref[$n])) continue;
                if ($f0 === 1) $this->xref[$n] = ['t' => 1, 'off' => $f1, 'g' => $f2];
                elseif ($f0 === 2) $this->xref[$n] = ['t' => 2, 'stm' => $f1, 'idx' => $f2];
                else $this->xref[$n] = ['t' => 0];
            }
        }
        return $d;
    }

    private function beInt($s) { $v = 0; for ($i = 0, $l = strlen($s); $i < $l; $i++) $v = $v * 256 + ord($s[$i]); return $v; }

    // بازسازیِ xref از روی متنِ فایل (برای PDFهای آسیب‌دیده)
    private function rebuildXref() {
        $this->repaired = true;
        $this->xref = [];
        $this->cache = [];
        $this->objStm = [];
        if (preg_match_all('/(?<![0-9])(\d{1,10})\s+(\d{1,5})\s+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $k => $mm) $this->xref[(int)$mm[0]] = ['t' => 1, 'off' => $m[0][$k][1], 'g' => (int)$m[2][$k][0]];
        }
        $tr = null;
        if (preg_match_all('/trailer\s*<</', $this->data, $tm, PREG_OFFSET_CAPTURE)) {
            for ($k = count($tm[0]) - 1; $k >= 0 && !$tr; $k--) {
                try { $i = $tm[0][$k][1] + 7; $t = $this->parseAt($i); if ($t instanceof PdfDict && $t->get('Root')) $tr = $t; } catch (Throwable $e) {}
            }
        }
        // اشیای داخلِ ObjStm و پیدا کردنِ Catalog
        $root = null;
        foreach (array_keys($this->xref) as $n) {
            try { $o = $this->getObj($n); } catch (Throwable $e) { continue; }
            if ($o instanceof PdfStream && $o->dict->get('Type') === 'ObjStm') {
                try { $this->loadObjStm($n); foreach (array_keys($this->objStm[$n]) as $on) if (!isset($this->xref[$on])) $this->xref[$on] = ['t' => 2, 'stm' => $n, 'idx' => -1]; } catch (Throwable $e) {}
            }
            if ($o instanceof PdfStream && $o->dict->get('Type') === 'XRef' && !$tr) $tr = $o->dict;
            if ($o instanceof PdfDict && $o->get('Type') === 'Catalog') $root = new PdfRef($n, $this->xref[$n]['g'] ?? 0);
        }
        if (!$tr) $tr = new PdfDict([]);
        if ($root && !$tr->get('Root')) $tr->v['Root'] = $root;
        if (!$tr->get('Root') && $root === null) {
            foreach (array_keys($this->xref) as $n) { try { $o = $this->getObj($n); } catch (Throwable $e) { continue; } if ($o instanceof PdfDict && $o->get('Type') === 'Catalog') { $tr->v['Root'] = new PdfRef($n); break; } }
        }
        $this->trailer = $tr;
        if (!$tr->get('Root')) throw new PdfException('ساختارِ این PDF خراب است و خوانده نمی‌شود.');
    }

    // ---------------- رمزگذاری (Standard Security Handler) ----------------
    private function setupCrypt() {
        $enc = $this->trailer->get('Encrypt');
        if ($enc === null) return;
        $encNum = $enc instanceof PdfRef ? $enc->n : -1;
        $e = $this->resolve($enc);
        if (!$e instanceof PdfDict) return;
        if ($e->get('Filter') !== 'Standard') throw new PdfException('این PDF با روشِ رمزگذاریِ ناشناخته قفل شده است.');
        $v = (int)$e->get('V', 0); $r = (int)$e->get('R', 0);
        $o = $this->strVal($e->get('O')); $u = $this->strVal($e->get('U'));
        $pp = (int)$e->get('P', 0); $p32 = pack('V', $pp & 0xFFFFFFFF);
        $idArr = $this->resolve($this->trailer->get('ID'));
        $id0 = is_array($idArr) && isset($idArr[0]) ? $this->strVal($this->resolve($idArr[0])) : '';
        $encMeta = $e->get('EncryptMetadata', true) !== false;
        $aes = false; $aes256 = false;
        $len = (int)$e->get('Length', 40);
        if ($v >= 4) {
            $cf = $this->resolve($e->get('CF'));
            $stmf = $e->get('StmF', 'Identity');
            $cfd = $cf instanceof PdfDict ? $this->resolve($cf->get($stmf)) : null;
            $cfm = $cfd instanceof PdfDict ? $cfd->get('CFM') : 'None';
            if ($cfm === 'AESV2') $aes = true;
            if ($cfm === 'AESV3') { $aes = true; $aes256 = true; }
            if ($cfd instanceof PdfDict && $cfd->get('Length')) { $l = (int)$cfd->get('Length'); $len = $l <= 32 ? $l * 8 : $l; }
            if ($v == 4 && $len < 40) $len = 128;
        }
        if ($r >= 5) {
            $key = $this->r6Key('', $o, $u, $this->strVal($e->get('OE')), $this->strVal($e->get('UE')), $r);
            if ($key === null) throw new PdfException('این PDF رمزِ عبور دارد و بدونِ رمز باز نمی‌شود.');
            $this->crypt = ['key' => $key, 'v' => $v, 'r' => $r, 'aes' => true, 'aes256' => true, 'encMeta' => $encMeta, 'num' => $encNum];
            return;
        }
        $pad = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
        $n = $r == 2 ? 5 : intval($len / 8);
        $h = md5($pad . substr($o, 0, 32) . $p32 . $id0 . (($r >= 4 && !$encMeta) ? "\xFF\xFF\xFF\xFF" : ''), true);
        if ($r >= 3) for ($k = 0; $k < 50; $k++) $h = md5(substr($h, 0, $n), true);
        $key = substr($h, 0, $n);
        // بررسیِ رمزِ خالیِ کاربر
        if ($r == 2) $ok = substr(self::rc4($key, $pad), 0, 16) === substr($u, 0, 16) || true;
        else {
            $x = md5($pad . $id0, true);
            $x = self::rc4($key, $x);
            for ($k = 1; $k <= 19; $k++) { $kk = ''; for ($j = 0; $j < strlen($key); $j++) $kk .= chr(ord($key[$j]) ^ $k); $x = self::rc4($kk, $x); }
            $ok = substr($x, 0, 16) === substr($u, 0, 16);
            if (!$ok) {
                // شاید فقط رمزِ مالک (همان رمزِ کاربرِ خالی) — بسیاری از فایل‌ها با رمزِ خالی هم باز می‌شوند؛ ادامه بده
            }
        }
        $this->crypt = ['key' => $key, 'v' => $v, 'r' => $r, 'aes' => $aes, 'aes256' => false, 'encMeta' => $encMeta, 'num' => $encNum];
    }

    private function r6Key($pw, $o, $u, $oe, $ue, $r) {
        $uValSalt = substr($u, 32, 8); $uKeySalt = substr($u, 40, 8);
        $h = $r == 5 ? hash('sha256', $pw . $uValSalt, true) : $this->r6Hash($pw, $uValSalt, '');
        if ($h !== substr($u, 0, 32)) {
            // شاید رمزِ مالک خالی باشد
            $oValSalt = substr($o, 32, 8); $oKeySalt = substr($o, 40, 8);
            $h2 = $r == 5 ? hash('sha256', $pw . $oValSalt . substr($u, 0, 48), true) : $this->r6Hash($pw, $oValSalt, substr($u, 0, 48));
            if ($h2 !== substr($o, 0, 32)) return null;
            $ik = $r == 5 ? hash('sha256', $pw . $oKeySalt . substr($u, 0, 48), true) : $this->r6Hash($pw, $oKeySalt, substr($u, 0, 48));
            return openssl_decrypt($oe, 'aes-256-cbc', $ik, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        }
        $ik = $r == 5 ? hash('sha256', $pw . $uKeySalt, true) : $this->r6Hash($pw, $uKeySalt, '');
        return openssl_decrypt($ue, 'aes-256-cbc', $ik, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
    }

    private function r6Hash($pw, $salt, $udata) {
        $k = hash('sha256', $pw . $salt . $udata, true);
        $i = 0;
        while (true) {
            $k1 = str_repeat($pw . $k . $udata, 64);
            $e = openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $sum = 0; for ($j = 0; $j < 16; $j++) $sum += ord($e[$j]);
            $m = $sum % 3;
            $k = hash($m === 0 ? 'sha256' : ($m === 1 ? 'sha384' : 'sha512'), $e, true);
            $i++;
            if ($i >= 64 && ord($e[strlen($e) - 1]) <= $i - 32) break;
        }
        return substr($k, 0, 32);
    }

    static function rc4($key, $data) {
        $s = range(0, 255); $j = 0; $kl = strlen($key);
        if ($kl === 0) return $data;
        for ($i = 0; $i < 256; $i++) { $j = ($j + $s[$i] + ord($key[$i % $kl])) & 255; $t = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $t; }
        $i = $j = 0; $out = '';
        for ($y = 0, $l = strlen($data); $y < $l; $y++) {
            $i = ($i + 1) & 255; $j = ($j + $s[$i]) & 255; $t = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $t;
            $out .= chr(ord($data[$y]) ^ $s[($s[$i] + $s[$j]) & 255]);
        }
        return $out;
    }

    private function objKey($n, $g) {
        $c = $this->crypt;
        if ($c['aes256']) return $c['key'];
        $k = $c['key'] . substr(pack('V', $n), 0, 3) . substr(pack('V', $g), 0, 2) . ($c['aes'] ? 'sAlT' : '');
        return substr(md5($k, true), 0, min(16, strlen($c['key']) + 5));
    }

    private function decryptBytes($s, $n, $g) {
        if (!$this->crypt || $s === '') return $s;
        $key = $this->objKey($n, $g);
        if ($this->crypt['aes']) {
            if (strlen($s) < 32) return strlen($s) < 16 ? '' : (string)openssl_decrypt(substr($s, 16), $this->crypt['aes256'] ? 'aes-256-cbc' : 'aes-128-cbc', $key, OPENSSL_RAW_DATA, substr($s, 0, 16));
            $r = openssl_decrypt(substr($s, 16), $this->crypt['aes256'] ? 'aes-256-cbc' : 'aes-128-cbc', $key, OPENSSL_RAW_DATA, substr($s, 0, 16));
            if ($r === false) $r = (string)openssl_decrypt(substr($s, 16), $this->crypt['aes256'] ? 'aes-256-cbc' : 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($s, 0, 16));
            return $r;
        }
        return self::rc4($key, $s);
    }

    private function decryptValue($v, $n, $g) {
        if ($v instanceof PdfStr) return new PdfStr($this->decryptBytes($v->s, $n, $g));
        if ($v instanceof PdfDict) { foreach ($v->v as $k => $x) $v->v[$k] = $this->decryptValue($x, $n, $g); return $v; }
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = $this->decryptValue($x, $n, $g); return $v; }
        return $v;
    }

    function isEncrypted() { return $this->crypt !== null; }

    // ---------------- خواندنِ اشیا ----------------
    function resolve($v, $depth = 0) {
        while ($v instanceof PdfRef && $depth < 32) { $v = $this->getObj($v->n); $depth++; }
        return $v;
    }

    function getObj($n) {
        if (array_key_exists($n, $this->cache)) return $this->cache[$n];
        $this->cache[$n] = null;   // جلوگیری از حلقه
        $e = $this->xref[$n] ?? null;
        $val = null;
        if ($e && $e['t'] === 1) {
            try {
                $val = $this->parseIndirectAt($e['off'], $gotN, $n);
                if ($gotN !== null && $gotN !== $n) throw new PdfException('mismatch');
            } catch (Throwable $ex) {
                // آفستِ اشتباه: جستجوی «n g obj» در فایل
                if (preg_match('/(?<![0-9])' . $n . '\s+' . ($e['g'] ?? 0) . '\s+obj\b/', $this->data, $mm, PREG_OFFSET_CAPTURE)) {
                    try { $val = $this->parseIndirectAt($mm[0][1], $gotN, $n); } catch (Throwable $e2) { $val = null; }
                }
            }
        } elseif ($e && $e['t'] === 2) {
            try { $this->loadObjStm($e['stm']); $val = $this->objStm[$e['stm']][$n] ?? null; } catch (Throwable $ex) { $val = null; }
        }
        if (count($this->cache) > 4000) $this->cache = array_slice($this->cache, -2000, null, true);
        $this->cache[$n] = $val;
        return $val;
    }

    private function loadObjStm($sn) {
        if (isset($this->objStm[$sn])) return;
        $this->objStm[$sn] = [];
        $s = $this->getObj($sn);
        if (!$s instanceof PdfStream) return;
        $data = $this->decodeStream($s);
        $nn = (int)$s->dict->get('N', 0); $first = (int)$s->dict->get('First', 0);
        $hdr = substr($data, 0, $first);
        $nums = preg_split('/\s+/', trim($hdr));
        $res = [];
        for ($k = 0; $k + 1 < count($nums) && $k / 2 < $nn; $k += 2) {
            $on = (int)$nums[$k]; $oo = (int)$nums[$k + 1];
            try { $p = $first + $oo; $res[$on] = $this->parseValue($data, $p); } catch (Throwable $e) { $res[$on] = null; }
        }
        $this->objStm[$sn] = $res;
    }

    private function parseIndirectAt($off, &$num = null, $expect = null) {
        $i = $off;
        $this->skipWs($i);
        if (!preg_match('/\G(\d+)\s+(\d+)\s+obj/', $this->data, $m, 0, $i)) throw new PdfException('obj header at ' . $off);
        $num = (int)$m[1]; $gen = (int)$m[2];
        $i += strlen($m[0]);
        $val = $this->parseValue($this->data, $i);
        $this->skipWs($i);
        if ($val instanceof PdfDict && substr($this->data, $i, 6) === 'stream') {
            $i += 6;
            if (($this->data[$i] ?? '') === "\r") $i++;
            if (($this->data[$i] ?? '') === "\n") $i++;
            $len = $val->get('Length');
            if ($len instanceof PdfRef) { $lv = $this->getObj($len->n); $len = is_numeric($lv) ? (int)$lv : null; }
            $len = is_numeric($len) ? (int)$len : null;
            if ($len === null || $len < 0 || substr($this->data, $i + $len, 40) === false || !preg_match('/^\s*endstream/', substr($this->data, $i + $len, 40))) {
                $e = strpos($this->data, 'endstream', $i);
                if ($e === false) $e = strlen($this->data);
                $len = $e - $i;
                while ($len > 0 && strpos("\r\n", $this->data[$i + $len - 1]) !== false) $len--;
            }
            $raw = substr($this->data, $i, $len);
            $st = new PdfStream($val, $raw);
            $st->n = $num; $st->g = $gen;
            if ($this->crypt && $num !== ($this->crypt['num'] ?? -1)) {
                $type = $val->get('Type');
                if ($type !== 'XRef' && !($type === 'Metadata' && !$this->crypt['encMeta']) && !$this->identityCrypt($val)) $st->raw = $this->decryptBytes($raw, $num, $gen);
                $st->dict = $this->decryptValue($val, $num, $gen);
            }
            return $st;
        }
        if ($this->crypt && $num !== ($this->crypt['num'] ?? -1)) $val = $this->decryptValue($val, $num, $gen);
        return $val;
    }

    private function identityCrypt(PdfDict $d) {
        $f = $d->get('Filter');
        $f = is_array($f) ? $f : [$f];
        return in_array('Crypt', $f, true);
    }

    function skipWs(&$i, $s = null) {
        if ($s === null) $s = &$this->data;
        $l = strlen($s);
        while ($i < $l) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0") { $i++; continue; }
            if ($c === '%') { while ($i < $l && $s[$i] !== "\n" && $s[$i] !== "\r") $i++; continue; }
            break;
        }
    }

    function parseAt(&$i) { return $this->parseValue($this->data, $i); }

    // تجزیه‌ی یک مقدار از رشته‌ی $s از جایگاهِ $i (به‌روز می‌شود)
    function parseValue(&$s, &$i, $depth = 0) {
        if ($depth > 200) throw new PdfException('nesting');
        $this->skipWs($i, $s);
        $l = strlen($s);
        if ($i >= $l) throw new PdfException('eof');
        $c = $s[$i];
        if ($c === '/') {
            $j = $i + 1;
            while ($j < $l && strpos(" \t\r\n\f\0/[]<>(){}%", $s[$j]) === false) $j++;
            $name = substr($s, $i + 1, $j - $i - 1);
            $i = $j;
            if (strpos($name, '#') !== false) $name = preg_replace_callback('/#([0-9A-Fa-f]{2})/', function ($m) { return chr(hexdec($m[1])); }, $name);
            return $name;
        }
        if ($c === '<') {
            if (($s[$i + 1] ?? '') === '<') {
                $i += 2;
                $d = [];
                while (true) {
                    $this->skipWs($i, $s);
                    if ($i >= $l) break;
                    if ($s[$i] === '>' && ($s[$i + 1] ?? '') === '>') { $i += 2; break; }
                    $k = $this->parseValue($s, $i, $depth + 1);
                    if (!is_string($k)) { if ($k === null && $i >= $l) break; continue; }
                    $this->skipWs($i, $s);
                    if ($s[$i] === '>' && ($s[$i + 1] ?? '') === '>') { $d[$k] = null; $i += 2; break; }
                    $d[$k] = $this->parseValue($s, $i, $depth + 1);
                }
                return new PdfDict($d);
            }
            $e = strpos($s, '>', $i);
            if ($e === false) $e = $l;
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $e - $i - 1));
            if (strlen($hex) % 2) $hex .= '0';
            $i = $e + 1;
            return new PdfStr((string)hex2bin($hex));
        }
        if ($c === '(') return new PdfStr($this->parseLiteral($s, $i));
        if ($c === '[') {
            $i++;
            $a = [];
            while (true) {
                $this->skipWs($i, $s);
                if ($i >= $l) break;
                if ($s[$i] === ']') { $i++; break; }
                $a[] = $this->parseValue($s, $i, $depth + 1);
            }
            return $a;
        }
        if (($c >= '0' && $c <= '9') || $c === '-' || $c === '+' || $c === '.') {
            if (preg_match('/\G(\d+)\s+(\d+)\s+R(?![A-Za-z])/', $s, $m, 0, $i)) { $i += strlen($m[0]); return new PdfRef($m[1], $m[2]); }
            $j = $i + 1;
            while ($j < $l && strpos('0123456789.-+', $s[$j]) !== false) $j++;
            $num = substr($s, $i, $j - $i);
            $i = $j;
            if (strpos($num, '.') !== false) return (float)$num;
            return (int)$num;
        }
        // کلمه‌ها: true/false/null/...
        $j = $i;
        while ($j < $l && strpos(" \t\r\n\f\0/[]<>(){}%", $s[$j]) === false) $j++;
        $w = substr($s, $i, $j - $i);
        $i = max($j, $i + 1);
        if ($w === 'true') return true;
        if ($w === 'false') return false;
        return null;
    }

    function parseLiteral(&$s, &$i) {
        $l = strlen($s);
        $i++;
        $depth = 1; $out = '';
        while ($i < $l) {
            $c = $s[$i];
            if ($c === '\\') {
                $i++;
                $n = $s[$i] ?? '';
                if ($n === 'n') $out .= "\n"; elseif ($n === 'r') $out .= "\r"; elseif ($n === 't') $out .= "\t";
                elseif ($n === 'b') $out .= "\x08"; elseif ($n === 'f') $out .= "\f";
                elseif ($n === "\r") { if (($s[$i + 1] ?? '') === "\n") $i++; }
                elseif ($n === "\n") {}
                elseif ($n >= '0' && $n <= '7') {
                    $o = $n;
                    if (($s[$i + 1] ?? '') >= '0' && ($s[$i + 1] ?? '') <= '7') { $o .= $s[++$i]; if (($s[$i + 1] ?? '') >= '0' && ($s[$i + 1] ?? '') <= '7') $o .= $s[++$i]; }
                    $out .= chr(octdec($o) & 255);
                } else $out .= $n;
                $i++;
                continue;
            }
            if ($c === '(') $depth++;
            elseif ($c === ')') { $depth--; if ($depth === 0) { $i++; break; } }
            $out .= $c;
            $i++;
        }
        return $out;
    }

    function strVal($v) { $v = $this->resolve($v); return $v instanceof PdfStr ? $v->s : (is_string($v) ? $v : ''); }

    // ---------------- فیلترها ----------------
    function decodeStream(PdfStream $st, $resolveParams = true) {
        $data = $st->raw;
        $f = $this->resolve($st->dict->get('Filter'));
        $p = $this->resolve($st->dict->get('DecodeParms', $st->dict->get('DP')));
        if ($f === null) return $data;
        $fs = is_array($f) ? $f : [$f];
        $ps = is_array($p) ? $p : [$p];
        foreach ($fs as $k => $name) {
            $name = $this->resolve($name);
            $pr = $this->resolve($ps[$k] ?? null);
            if ($name === 'FlateDecode' || $name === 'Fl') $data = $this->predict(self::inflate($data), $pr);
            elseif ($name === 'LZWDecode' || $name === 'LZW') $data = $this->predict(self::lzw($data, $pr instanceof PdfDict ? (int)$pr->get('EarlyChange', 1) : 1), $pr);
            elseif ($name === 'ASCIIHexDecode' || $name === 'AHx') { $h = preg_replace('/[^0-9A-Fa-f]/', '', strstr($data, '>', true) !== false ? strstr($data, '>', true) : $data); if (strlen($h) % 2) $h .= '0'; $data = (string)hex2bin($h); }
            elseif ($name === 'ASCII85Decode' || $name === 'A85') $data = self::a85($data);
            elseif ($name === 'RunLengthDecode' || $name === 'RL') $data = self::rld($data);
            elseif ($name === 'Crypt') {}
            else break;   // DCT/JPX/CCITT/JBIG2: داده‌ی تصویر؛ برای متن لازم نیست
        }
        return $data;
    }

    static function inflate($d) {
        if ($d === '') return '';
        $r = @gzuncompress($d);
        if ($r !== false) return $r;
        $r = @gzinflate(substr($d, 2));
        if ($r !== false) return $r;
        $r = @gzinflate($d);
        if ($r !== false) return $r;
        // جریانِ ناقص: تا جایی که می‌شود
        if (function_exists('inflate_init')) {
            $ctx = @inflate_init(ZLIB_ENCODING_DEFLATE);
            $out = '';
            if ($ctx) { $body = substr($d, 2); for ($o = 0; $o < strlen($body); $o += 1024) { $x = @inflate_add($ctx, substr($body, $o, 1024), ZLIB_SYNC_FLUSH); if ($x === false) break; $out .= $x; } }
            return $out;
        }
        return '';
    }

    private function predict($data, $p) {
        if (!$p instanceof PdfDict) return $data;
        $pred = (int)$this->resolve($p->get('Predictor', 1));
        if ($pred < 2) return $data;
        $colors = (int)$this->resolve($p->get('Colors', 1)) ?: 1;
        $bpc = (int)$this->resolve($p->get('BitsPerComponent', 8)) ?: 8;
        $cols = (int)$this->resolve($p->get('Columns', 1)) ?: 1;
        $bpp = max(1, intval(($colors * $bpc + 7) / 8));
        $rowLen = intval(($colors * $bpc * $cols + 7) / 8);
        if ($pred === 2) {
            $out = '';
            for ($o = 0; $o < strlen($data); $o += $rowLen) {
                $row = substr($data, $o, $rowLen);
                for ($x = $bpp; $x < strlen($row); $x++) $row[$x] = chr((ord($row[$x]) + ord($row[$x - $bpp])) & 255);
                $out .= $row;
            }
            return $out;
        }
        $out = ''; $prev = str_repeat("\0", $rowLen);
        $l = strlen($data);
        for ($o = 0; $o < $l; $o += $rowLen + 1) {
            $type = ord($data[$o]);
            $row = substr($data, $o + 1, $rowLen);
            if (strlen($row) < $rowLen) $row = str_pad($row, $rowLen, "\0");
            $cur = $row;
            switch ($type) {
                case 1: for ($x = $bpp; $x < $rowLen; $x++) $cur[$x] = chr((ord($row[$x]) + ord($cur[$x - $bpp])) & 255); break;
                case 2: for ($x = 0; $x < $rowLen; $x++) $cur[$x] = chr((ord($row[$x]) + ord($prev[$x])) & 255); break;
                case 3: for ($x = 0; $x < $rowLen; $x++) { $a = $x >= $bpp ? ord($cur[$x - $bpp]) : 0; $cur[$x] = chr((ord($row[$x]) + (($a + ord($prev[$x])) >> 1)) & 255); } break;
                case 4:
                    for ($x = 0; $x < $rowLen; $x++) {
                        $a = $x >= $bpp ? ord($cur[$x - $bpp]) : 0; $b = ord($prev[$x]); $cc = $x >= $bpp ? ord($prev[$x - $bpp]) : 0;
                        $pp = $a + $b - $cc; $pa = abs($pp - $a); $pb = abs($pp - $b); $pc = abs($pp - $cc);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $cc);
                        $cur[$x] = chr((ord($row[$x]) + $pr) & 255);
                    }
                    break;
            }
            $out .= $cur; $prev = $cur;
        }
        return $out;
    }

    static function lzw($data, $early = 1) {
        $out = ''; $dict = []; $next = 258; $bits = 9; $buf = 0; $nb = 0; $prev = null;
        for ($k = 0; $k < 256; $k++) $dict[$k] = chr($k);
        $l = strlen($data);
        for ($i = 0; $i < $l; $i++) {
            $buf = ($buf << 8) | ord($data[$i]); $nb += 8;
            while ($nb >= $bits) {
                $code = ($buf >> ($nb - $bits)) & ((1 << $bits) - 1);
                $nb -= $bits; $buf &= (1 << $nb) - 1;
                if ($code === 256) { $dict = array_slice($dict, 0, 256); $next = 258; $bits = 9; $prev = null; continue; }
                if ($code === 257) return $out;
                if ($prev === null) { $w = $dict[$code] ?? ''; $out .= $w; $prev = $w; continue; }
                $w = isset($dict[$code]) ? $dict[$code] : $prev . $prev[0];
                $out .= $w;
                $dict[$next++] = $prev . $w[0];
                $prev = $w;
                if ($next + $early >= (1 << $bits) && $bits < 12) $bits++;
            }
        }
        return $out;
    }

    static function a85($d) {
        $d = preg_replace('/\s+/', '', $d);
        if (substr($d, 0, 2) === '<~') $d = substr($d, 2);
        $e = strpos($d, '~>'); if ($e !== false) $d = substr($d, 0, $e);
        $out = ''; $tuple = []; $l = strlen($d);
        for ($i = 0; $i < $l; $i++) {
            $c = $d[$i];
            if ($c === 'z' && !$tuple) { $out .= "\0\0\0\0"; continue; }
            $tuple[] = ord($c) - 33;
            if (count($tuple) === 5) { $v = 0; foreach ($tuple as $t) $v = $v * 85 + $t; $out .= pack('N', $v & 0xFFFFFFFF); $tuple = []; }
        }
        if ($tuple) { $n = count($tuple); while (count($tuple) < 5) $tuple[] = 84; $v = 0; foreach ($tuple as $t) $v = $v * 85 + $t; $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $n - 1); }
        return $out;
    }

    static function rld($d) {
        $out = ''; $i = 0; $l = strlen($d);
        while ($i < $l) { $n = ord($d[$i++]); if ($n === 128) break; if ($n < 128) { $out .= substr($d, $i, $n + 1); $i += $n + 1; } else { $out .= str_repeat($d[$i] ?? '', 257 - $n); $i++; } }
        return $out;
    }

    // ---------------- صفحه‌ها ----------------
    // هر صفحه: ['ref'=>PdfRef|null, 'dict'=>PdfDict, 'res'=>Resources, 'box'=>[x0,y0,x1,y1], 'rot'=>0|90|180|270]
    function pages() {
        if ($this->pages !== null) return $this->pages;
        $this->pages = [];
        $root = $this->resolve($this->trailer->get('Root'));
        if (!$root instanceof PdfDict) throw new PdfException('Catalog');
        $this->walkPages($root->get('Pages'), [], 0, []);
        return $this->pages;
    }

    private function walkPages($ref, array $inh, $depth, array $seen) {
        if ($depth > 60) return;
        if ($ref instanceof PdfRef) { if (isset($seen[$ref->n])) return; $seen[$ref->n] = 1; }
        $node = $this->resolve($ref);
        if ($node instanceof PdfStream) $node = $node->dict;
        if (!$node instanceof PdfDict) return;
        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $k) if ($node->get($k) !== null) $inh[$k] = $node->get($k);
        $kids = $this->resolve($node->get('Kids'));
        $type = $node->get('Type');
        if ($type === 'Pages' || (is_array($kids) && $type !== 'Page')) {
            if (is_array($kids)) foreach ($kids as $k) $this->walkPages($k, $inh, $depth + 1, $seen);
            return;
        }
        $mb = $this->numArr($inh['MediaBox'] ?? null) ?: [0, 0, 612, 792];
        $cb = $this->numArr($inh['CropBox'] ?? null) ?: $mb;
        $box = [max($mb[0], min($cb[0], $cb[2])), max($mb[1], min($cb[1], $cb[3])), min($mb[2], max($cb[0], $cb[2])), min($mb[3], max($cb[1], $cb[3]))];
        if ($box[2] - $box[0] < 1 || $box[3] - $box[1] < 1) $box = $mb;
        $rot = ((int)$this->resolve($inh['Rotate'] ?? 0)) % 360; if ($rot < 0) $rot += 360;
        $this->pages[] = ['ref' => $ref instanceof PdfRef ? $ref : null, 'dict' => $node, 'res' => $this->resolve($inh['Resources'] ?? null), 'box' => $box, 'mbox' => $mb, 'rot' => $rot, 'inh' => $inh];
    }

    function numArr($v) {
        $v = $this->resolve($v);
        if (!is_array($v) || count($v) < 4) return null;
        $o = [];
        foreach (array_slice($v, 0, 4) as $x) { $x = $this->resolve($x); $o[] = is_numeric($x) ? (float)$x : 0.0; }
        return [min($o[0], $o[2]), min($o[1], $o[3]), max($o[0], $o[2]), max($o[1], $o[3])];
    }

    function pageCount() { return count($this->pages()); }

    // محتوای صفحه (همه‌ی جریان‌های Contents پشتِ هم)
    function pageContent($pageIdx) {
        $pg = $this->pages()[$pageIdx] ?? null;
        if (!$pg) return '';
        $c = $this->resolve($pg['dict']->get('Contents'));
        $parts = [];
        if ($c instanceof PdfStream) $parts[] = $this->decodeStream($c);
        elseif (is_array($c)) foreach ($c as $x) { $x = $this->resolve($x); if ($x instanceof PdfStream) $parts[] = $this->decodeStream($x); }
        return implode("\n", $parts);
    }
}

// ---------------- نوشتنِ PDFِ تازه از صفحه‌های انتخابی ----------------
class PdfWriter {
    private $objs = [];          // num => serialized body (بدونِ n g obj)
    private $map = [];           // "docid:n" => newNum
    private $next = 1;
    private $pageNums = [];
    private $pagesNum;
    public $imageQuality = 0;    // >0: عکس‌های JPEGِ داخلِ PDF با این کیفیت دوباره ذخیره می‌شوند (کم‌حجم کردن)
    public $imageMaxSide = 0;    // >0: ضلعِ بزرگِ عکس حداکثر این‌قدر پیکسل

    function __construct() { $this->pagesNum = $this->next++; }

    // کم‌حجم کردنِ یک عکسِ JPEG (فقط RGB/خاکستری، بدونِ ماسک)؛ null یعنی دست نخورد
    private function shrinkJpeg(array &$dv, $raw, PdfDoc $doc) {
        if (!$this->imageQuality || !function_exists('imagecreatefromstring')) return null;
        $f = $doc->resolve($dv['Filter'] ?? null);
        if (is_array($f)) $f = count($f) === 1 ? $doc->resolve($f[0]) : null;
        if ($f !== 'DCTDecode' || strlen($raw) < 60000) return null;
        $cs = $doc->resolve($dv['ColorSpace'] ?? null);
        if ($cs === 'DeviceCMYK' || (is_array($cs) && ($doc->resolve($cs[0] ?? null) === 'ICCBased') && ($icc = $doc->resolve($cs[1] ?? null)) instanceof PdfStream && (int)$icc->dict->get('N') === 4)) return null;
        $im = @imagecreatefromstring($raw);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im);
        $mx = $this->imageMaxSide;
        if ($mx && max($w, $h) > $mx && !isset($dv['SMask']) && !isset($dv['Mask'])) {
            $r = $mx / max($w, $h);
            $nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r));
            $im2 = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($im2, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($im); $im = $im2; $w = $nw; $h = $nh;
        }
        ob_start(); imagejpeg($im, null, $this->imageQuality); $out = ob_get_clean();
        imagedestroy($im);
        if ($out === '' || strlen($out) >= strlen($raw)) return null;
        $dv['Width'] = $w; $dv['Height'] = $h; $dv['BitsPerComponent'] = 8;
        $dv['ColorSpace'] = (is_string($cs) && $cs === 'DeviceGray') ? 'DeviceGray' : 'DeviceRGB';
        unset($dv['DecodeParms'], $dv['Decode']);
        $dv['Filter'] = 'DCTDecode';
        return $out;
    }

    // شیءِ خامِ تازه (برای مهر: عکس و محتوای اضافه)
    private function rawObj($dict, $data = null) {
        $n = $this->next++;
        $this->objs[$n] = $data === null ? $dict : (substr($dict, 0, -2) . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream");
        return $n;
    }
    // عکسِ PNG (با شفافیت) => XObject؛ خروجی: ['num', 'w', 'h'] برای addPage(..., $stamp)
    function addPngImage($pngPath, $maxSide = 900) {
        if (!function_exists('imagecreatefrompng')) return null;
        $im = @imagecreatefrompng($pngPath);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im);
        if (max($w, $h) > $maxSide) {
            $r = $maxSide / max($w, $h); $nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r));
            $im2 = imagecreatetruecolor($nw, $nh); imagealphablending($im2, false); imagesavealpha($im2, true);
            imagecopyresampled($im2, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($im); $im = $im2; $w = $nw; $h = $nh;
        } elseif (!imageistruecolor($im)) { imagepalettetotruecolor($im); }
        $rgb = ''; $alpha = '';
        for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $rgb .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255);
            $alpha .= chr(255 - (int)round((($c >> 24) & 127) * 255 / 127));
        }
        imagedestroy($im);
        $sm = $this->rawObj("<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode >>", gzcompress($alpha, 6));
        $num = $this->rawObj("<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /SMask $sm 0 R >>", gzcompress($rgb, 6));
        return ['num' => $num, 'w' => $w, 'h' => $h];
    }

    // صفحه‌ی $idx از $doc را اضافه می‌کند؛ $rotate: چرخشِ اضافه (۰/۹۰/۱۸۰/۲۷۰)
    //   $stamp: ['num','w','h'] از addPngImage + اختیاری 'ratio' (عرضِ مهر نسبت به عرضِ صفحه) و 'margin' (نسبت) — پایینِ سمتِ چپِ صفحه‌ی دیده‌شده
    function addPage(PdfDoc $doc, $idx, $rotate = 0, $stamp = null) {
        $pages = $doc->pages();
        if (!isset($pages[$idx])) throw new PdfException('صفحه وجود ندارد.');
        $pg = $pages[$idx];
        $d = clone $pg['dict'];
        $v = $d->v;
        unset($v['Parent'], $v['Annots'], $v['B'], $v['StructParents'], $v['PieceInfo'], $v['Metadata'], $v['Thumb']);
        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $k) if (!array_key_exists($k, $v) && isset($pg['inh'][$k])) $v[$k] = $pg['inh'][$k];
        if (!isset($v['MediaBox'])) $v['MediaBox'] = [0, 0, 612, 792];
        $rot = (($pg['rot'] + (int)$rotate) % 360 + 360) % 360;
        if ($rot) $v['Rotate'] = $rot; else unset($v['Rotate']);
        $v['Type'] = 'Page';
        if ($stamp) $this->stampPage($doc, $v, $rot, $stamp);
        $num = $this->next++;
        $this->pageNums[] = $num;
        $body = $this->ser(new PdfDict($v), $doc, ['Parent' => true], 0);
        $body = substr($body, 0, -2) . ' /Parent ' . $this->pagesNum . ' 0 R >>';
        $this->objs[$num] = $body;
        return $num;
    }

    private function stampPage(PdfDoc $doc, array &$v, $rot, array $st) {
        $box = $doc->numArr($v['CropBox'] ?? $v['MediaBox']);
        if (count($box) < 4) $box = [0, 0, 612, 792];
        [$x0, $y0, $x1, $y1] = [min($box[0], $box[2]), min($box[1], $box[3]), max($box[0], $box[2]), max($box[1], $box[3])];
        $W = $x1 - $x0; $H = $y1 - $y0;
        $visW = ($rot === 90 || $rot === 270) ? $H : $W;
        $w = $visW * ($st['ratio'] ?? 0.24); $h = $w * $st['h'] / max(1, $st['w']); $m = $visW * ($st['margin'] ?? 0.035);
        // ماتریسِ نشاندنِ عکس در گوشه‌ی پایین-چپِ صفحه‌ی دیده‌شده (با درنظر گرفتنِ /Rotate)
        if ($rot === 90) $cm = [0, $w, -$h, 0, $x1 - $m, $y0 + $m];
        elseif ($rot === 180) $cm = [-$w, 0, 0, -$h, $x1 - $m, $y1 - $m];
        elseif ($rot === 270) $cm = [0, -$w, $h, 0, $x0 + $m, $y1 - $m];
        else $cm = [$w, 0, 0, $h, $x0 + $m, $y0 + $m];
        $f = function ($n) { return rtrim(rtrim(sprintf('%.4F', $n), '0'), '.') ?: '0'; };
        $pre = $this->rawObj('<< >>', "q\n");
        $post = $this->rawObj('<< >>', "Q\nq " . implode(' ', array_map($f, $cm)) . " cm /BimeStamp Do Q\n");
        $c = $doc->resolve($v['Contents'] ?? null);
        $items = is_array($c) ? $c : (isset($v['Contents']) ? [$v['Contents']] : []);
        $v['Contents'] = array_merge([new PdfRaw($pre . ' 0 R')], $items, [new PdfRaw($post . ' 0 R')]);
        $res = $doc->resolve($v['Resources'] ?? null);
        $rv = $res instanceof PdfDict ? $res->v : [];
        $xo = $doc->resolve($rv['XObject'] ?? null);
        $xv = $xo instanceof PdfDict ? $xo->v : [];
        $xv['BimeStamp'] = new PdfRaw($st['num'] . ' 0 R');
        $rv['XObject'] = new PdfDict($xv);
        $v['Resources'] = new PdfDict($rv);
    }

    private function key(PdfDoc $doc, $n) { return spl_object_id($doc) . ':' . $n; }

    private function copyRef(PdfDoc $doc, PdfRef $r) {
        $k = $this->key($doc, $r->n);
        if (isset($this->map[$k])) return $this->map[$k];
        $num = $this->next++;
        $this->map[$k] = $num;
        $o = $doc->getObj($r->n);
        if ($o instanceof PdfStream) {
            $dv = $o->dict->v;
            unset($dv['Length'], $dv['DL']);
            // اگر سند رمزگذاری داشت، داده‌ی رمزگشایی‌شده با همان فیلترهای فشرده‌سازی نوشته می‌شود
            if (isset($dv['Filter']) && is_array($dv['Filter']) && in_array('Crypt', $dv['Filter'], true)) unset($dv['Filter'], $dv['DecodeParms']);
            if ($o->dict->get('Type') === 'Page' || $o->dict->get('Type') === 'Pages') { $this->objs[$num] = 'null'; return $num; }
            $raw = $o->raw;
            if ($this->imageQuality && $o->dict->get('Subtype') === 'Image') { $nr = $this->shrinkJpeg($dv, $raw, $doc); if ($nr !== null) $raw = $nr; }
            $dict = $this->ser(new PdfDict($dv), $doc, [], 1);
            $dict = substr($dict, 0, -2) . ' /Length ' . strlen($raw) . ' >>';
            $this->objs[$num] = $dict . "\nstream\n" . $raw . "\nendstream";
        } else {
            // ارجاع به صفحه/درختِ صفحه‌های دیگر (مثلاً از لینک‌ها) کپی نمی‌شود
            if ($o instanceof PdfDict && in_array($o->get('Type'), ['Page', 'Pages'], true)) { $this->objs[$num] = 'null'; return $num; }
            $this->objs[$num] = $this->ser($o, $doc, [], 1);
        }
        return $num;
    }

    private function ser($v, PdfDoc $doc, array $skip, $depth) {
        if ($depth > 100) return 'null';
        if ($v === null) return 'null';
        if ($v === true) return 'true';
        if ($v === false) return 'false';
        if (is_int($v)) return (string)$v;
        if (is_float($v)) { $s = rtrim(rtrim(sprintf('%.5F', $v), '0'), '.'); return $s === '' || $s === '-' || $s === '-0' ? '0' : $s; }
        if (is_string($v)) return '/' . preg_replace_callback('/[^\x21-\x7E]|[#\/\[\]<>(){}%]/', function ($m) { return sprintf('#%02X', ord($m[0])); }, $v);
        if ($v instanceof PdfStr) return '<' . bin2hex($v->s) . '>';
        if ($v instanceof PdfRaw) return $v->s;
        if ($v instanceof PdfRef) return $this->copyRef($doc, $v) . ' 0 R';
        if ($v instanceof PdfDict) {
            $o = '<<';
            foreach ($v->v as $k => $x) { if (isset($skip[$k])) continue; $o .= ' ' . $this->ser((string)$k, $doc, [], $depth + 1) . ' ' . $this->ser($x, $doc, [], $depth + 1); }
            return $o . ' >>';
        }
        if (is_array($v)) { $o = '['; foreach ($v as $x) $o .= $this->ser($x, $doc, [], $depth + 1) . ' '; return rtrim($o) . ']'; }
        if ($v instanceof PdfStream) return 'null';
        return 'null';
    }

    function count() { return count($this->pageNums); }

    function output() {
        $kids = implode(' ', array_map(function ($n) { return $n . ' 0 R'; }, $this->pageNums));
        $this->objs[$this->pagesNum] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($this->pageNums) . ' >>';
        $cat = $this->next++;
        $this->objs[$cat] = '<< /Type /Catalog /Pages ' . $this->pagesNum . ' 0 R >>';
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        ksort($this->objs);
        foreach ($this->objs as $n => $body) { $offs[$n] = strlen($out); $out .= $n . " 0 obj\n" . $body . "\nendobj\n"; }
        $xref = strlen($out);
        $max = $this->next;
        $out .= "xref\n0 " . $max . "\n0000000000 65535 f \n";
        for ($n = 1; $n < $max; $n++) $out .= isset($offs[$n]) ? sprintf("%010d 00000 n \n", $offs[$n]) : "0000000000 65535 f \n";
        $out .= "trailer\n<< /Size " . $max . ' /Root ' . $cat . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    function save($path) { return file_put_contents($path, $this->output()) !== false; }
}

// ---------------- کمک‌تابع‌های ساده برای بقیه‌ی سایت ----------------

// مهرِ PNG (بی‌زمینه) پایینِ سمتِ چپِ صفحه‌ها؛ $pages = 'all' یا 'last'
function pdfx_stamp($abs, $pngPath, $dest, $pages = 'all', $ratio = 0.24) {
    try {
        $doc = PdfDoc::open($abs);
        $w = new PdfWriter();
        $img = $w->addPngImage($pngPath);
        if (!$img) return false;
        $img['ratio'] = $ratio;
        $n = $doc->pageCount();
        for ($i = 0; $i < $n; $i++) $w->addPage($doc, $i, 0, ($pages === 'all' || $i === $n - 1) ? $img : null);
        return $w->save($dest);
    } catch (Throwable $e) { error_log('[pdfx_stamp] ' . $e->getMessage()); return false; }
}

// شمارِ صفحه‌ها (null = خوانده نشد)
function pdfx_page_count($abs) {
    try { return PdfDoc::open($abs)->pageCount(); } catch (Throwable $e) { return null; }
}

// صفحه‌های $pages (شماره‌ها از ۰) از $abs را در $dest می‌نویسد؛ $rot: [idx => درجه] برای چرخاندن
function pdfx_extract($abs, array $pages, $dest, array $rot = []) {
    $doc = PdfDoc::open($abs);
    $w = new PdfWriter();
    $n = $doc->pageCount();
    foreach ($pages as $p) { $p = (int)$p; if ($p >= 0 && $p < $n) $w->addPage($doc, $p, (int)($rot[$p] ?? 0)); }
    if (!$w->count()) throw new PdfException('هیچ صفحه‌ای انتخاب نشد.');
    if (!$w->save($dest)) throw new PdfException('نوشتنِ فایل ممکن نشد.');
    return $w->count();
}

// ادغام: [[مسیر, [صفحه‌ها]|null], ...]
function pdfx_merge(array $items, $dest) {
    $w = new PdfWriter();
    $docs = [];
    foreach ($items as $it) {
        [$abs, $pages] = [$it[0], $it[1] ?? null];
        $doc = PdfDoc::open($abs);
        $docs[] = $doc;
        $n = $doc->pageCount();
        foreach ($pages === null ? range(0, $n - 1) : $pages as $p) if ($p >= 0 && $p < $n) $w->addPage($doc, (int)$p, (int)($it[2][$p] ?? 0));
    }
    if (!$w->count()) throw new PdfException('هیچ صفحه‌ای نبود.');
    if (!$w->save($dest)) throw new PdfException('نوشتنِ فایل ممکن نشد.');
    return $w->count();
}

// هر صفحه یک PDF؛ خروجی: فهرستِ مسیرها
function pdfx_split_each($abs, $outDir, $max = 500, $nameFn = null) {
    $doc = PdfDoc::open($abs);
    $n = min($doc->pageCount(), $max);
    if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
    $files = [];
    for ($p = 0; $p < $n; $p++) {
        $w = new PdfWriter();
        $w->addPage($doc, $p);
        $f = rtrim($outDir, '/') . '/' . ($nameFn ? $nameFn($p, $n) : sprintf('p%03d.pdf', $p + 1));
        $w->save($f);
        $files[] = $f;
    }
    return $files;
}
