<?php
// فایل: api/_smart_ocr.php
// «پردازش فایل هوشمند» (استعلامات): متنِ PDF/عکس، صفحه‌به‌صفحه.
//   مرورگر هر صفحه را به عکس تبدیل می‌کند (pdf.js) و صفحه‌ها هم‌زمان (چندتا با هم) به این‌جا می‌آیند؛
//   موتورها به ترتیب: ۱) هوشِ مصنوعیِ تصویری (GapGPT یا هر سرویسِ سازگار با OpenAI — بهترین کیفیت برای فارسی)
//                     ۲) OCR.space با زبانِ فارسی (کلیدِ خودتان در «کلیدهای API»)
//                     ۳) اگر هیچ‌کدام نبود/جواب نداد: خودِ مرورگر (Tesseract) — کندتر
//   نتیجه در تاریخچه‌ی استعلامات (kind = ocr) می‌ماند و خروجیِ TXT، Word و PDF دارد.
require_once __DIR__ . '/_apis.php';
require_once __DIR__ . '/_vdoc_ai.php';

const SO_ENGINES = ['i2pdf' => 'i2pdf.com', 'ai' => 'هوشِ مصنوعی (GapGPT)', 'ocrspace' => 'OCR.space', 'browser' => 'مرورگر (Tesseract)'];

function so_ocrspace_conf($pdo) {
    $c = api_cfg($pdo)['ocr_space'] ?? [];
    return ['token' => trim((string)($c['token'] ?? '')), 'on' => ($c['enabled'] ?? '1') !== '0', 'engine' => in_array($c['engine'] ?? '', ['1', '2', '3'], true) ? $c['engine'] : '1'];
}
// اولویت و روشن/خاموشِ موتورها (تنظیماتِ استعلامات ← پردازش فایل هوشمند): order = ترتیب، on = روشن‌ها
function so_cfg($pdo) {
    $c = api_cfg($pdo)['smart_ocr'] ?? [];
    $order = array_values(array_unique(array_filter((array)($c['order'] ?? []), function ($e) { return isset(SO_ENGINES[$e]); })));
    foreach (['i2pdf', 'ai', 'ocrspace', 'browser'] as $e) if (!in_array($e, $order, true)) $order[] = $e;
    $on = [];
    foreach (SO_ENGINES as $e => $l) $on[$e] = isset($c['on'][$e]) ? !empty($c['on'][$e]) : true;
    return ['order' => $order, 'on' => $on, 'auto' => ($c['auto'] ?? 1) ? true : false, 'format' => in_array($c['format'] ?? '', ['txt', 'simple_pdf', 'docx', 'pdf'], true) ? $c['format'] : 'txt', 'conc' => max(1, min(6, intval($c['conc'] ?? 3) ?: 3))];
}
function so_save_cfg($pdo, array $in) {
    $all = api_cfg($pdo);
    $c = $all['smart_ocr'] ?? [];
    if (isset($in['order'])) $c['order'] = array_values(array_filter((array)$in['order'], function ($e) { return isset(SO_ENGINES[$e]); }));
    if (isset($in['on'])) foreach (SO_ENGINES as $e => $l) $c['on'][$e] = !empty($in['on'][$e]) ? 1 : 0;
    if (isset($in['format'])) $c['format'] = (string)$in['format'];
    if (isset($in['conc'])) $c['conc'] = intval($in['conc']);
    if (isset($in['auto'])) $c['auto'] = !empty($in['auto']) ? 1 : 0;
    if (isset($in['prompts']) && is_array($in['prompts'])) {
        require_once __DIR__ . '/_doc_smart.php';
        foreach (ds_types() as $t => $x) if (array_key_exists($t, $in['prompts'])) {
            $v = trim((string)$in['prompts'][$t]);
            // همان پرامپتِ پیش‌فرض = چیزی ذخیره نشود (تا بهبودهای بعدیِ پیش‌فرض برسد)
            if ($v === '' || $v === trim($x[1])) unset($c['prompts'][$t]); else $c['prompts'][$t] = mb_substr($v, 0, 8000);
        }
    }
    // پرامپت‌های اختصاصیِ یک شرکت: ['id' => company_id, 'prompts' => [نوع => متن]] — خالی یا برابر با پرامپتِ کلی = حذفِ اختصاصی
    if (isset($in['company_prompts']['id']) && is_array($in['company_prompts']['prompts'] ?? null)) {
        require_once __DIR__ . '/_doc_smart.php';
        $cid = (string)intval($in['company_prompts']['id']);
        foreach (ds_types() as $t => $x) if (array_key_exists($t, $in['company_prompts']['prompts'])) {
            $v = trim((string)$in['company_prompts']['prompts'][$t]);
            $base = trim((string)($c['prompts'][$t] ?? '')) ?: trim($x[1]);
            if ($v === '' || $v === $base) unset($c['company_prompts'][$cid][$t]); else $c['company_prompts'][$cid][$t] = mb_substr($v, 0, 8000);
        }
        if (empty($c['company_prompts'][$cid])) unset($c['company_prompts'][$cid]);
    }
    $i2 = $c['i2pdf'] ?? [];
    foreach (['page_url', 'lang_fa', 'lang_en', 'engine', 'layout', 'format', 'timeout'] as $k) if (isset($in['i2pdf'][$k])) $i2[$k] = trim((string)$in['i2pdf'][$k]);
    $c['i2pdf'] = $i2;
    $all['smart_ocr'] = $c;
    api_save_cfg($pdo, $all);
}
// موتورهای آماده (تنظیم‌شده و روشن)
function so_engines($pdo) {
    require_once __DIR__ . '/_i2pdf.php';
    $o = so_ocrspace_conf($pdo); $c = so_cfg($pdo);
    $ready = ['i2pdf' => i2_ready($pdo), 'ai' => vdoc_ai_ready($pdo), 'ocrspace' => $o['on'] && $o['token'] !== '' && function_exists('curl_init'), 'browser' => true];
    foreach ($ready as $e => $v) $ready[$e] = $v && $c['on'][$e];
    return $ready;
}
// ترتیبِ موتورهای روشن و آماده (برای مرورگر: اگر «مرورگر» اول باشد، اصلاً سراغِ سرور نمی‌رود)
function so_order($pdo) {
    $av = so_engines($pdo);
    return array_values(array_filter(so_cfg($pdo)['order'], function ($e) use ($av) { return !empty($av[$e]); }));
}

// پوشه‌ی موقت (پیشرفتِ گام‌ها و فایل‌های نتیجه‌ی i2pdf)
function so_tmp_dir() {
    if (function_exists('inq_dir')) return inq_dir('tmp');
    $d = __DIR__ . '/data/inq';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_dir($d . '/tmp')) @mkdir($d . '/tmp', 0700, true);
    return $d . '/tmp';
}
// تایم‌لاینِ یک صفحه: پنل هر ثانیه می‌خواند (ocr_progress)
function so_progress($token, $step, $label) {
    if (!preg_match('/^[a-z0-9]{8,40}$/', (string)$token)) return;
    $f = so_tmp_dir() . "/ocrp_$token.json";
    $j = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    if (empty($j['t0'])) $j['t0'] = microtime(true);
    $j['steps'][] = ['step' => $step, 'label' => $label, 'at' => round(microtime(true) - $j['t0'], 1)];
    @file_put_contents($f, json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function so_progress_read($token) {
    if (!preg_match('/^[a-z0-9]{8,40}$/', (string)$token)) return null;
    $f = so_tmp_dir() . "/ocrp_$token.json";
    if (!is_file($f)) return ['steps' => []];
    $j = json_decode((string)@file_get_contents($f), true) ?: [];
    return ['steps' => $j['steps'] ?? [], 'elapsed' => !empty($j['t0']) ? round(microtime(true) - $j['t0'], 1) : 0];
}
// عکسِ صفحه ← PDFِ تک‌صفحه (برای i2pdf که PDF می‌گیرد)
function so_image_pdf($dataUrl) {
    if (!preg_match('~^data:image/(jpeg|png|webp);base64,(.+)$~s', (string)$dataUrl, $m)) return null;
    $bin = base64_decode($m[2]);
    $sz = @getimagesizefromstring($bin);
    if (!$sz) return null;
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    // ۲۰۰ نقطه در اینچ: اندازه‌ی صفحه همان اندازه‌ی عکس
    $w = $sz[0] * 25.4 / 200; $h = $sz[1] * 25.4 / 200;
    $pdf = new TCPDF($w > $h ? 'L' : 'P', 'mm', [$w, $h], true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); $pdf->SetMargins(0, 0, 0); $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddPage();
    $pdf->Image('@' . $bin, 0, 0, $w, $h, strtoupper($m[1] === 'jpeg' ? 'JPG' : $m[1]), '', '', false, 200);
    return $pdf->Output('', 'S');
}

const SO_AI_PROMPT = 'You are a precise OCR engine. Transcribe ALL text visible in this page image exactly as written, in natural reading order (right-to-left for Persian/Arabic). '
    . 'Preserve line breaks and paragraph breaks. For tables, write each table row on its own line and separate cells with " | ". '
    . 'Keep Persian text in Persian script and keep digits exactly as they appear (Persian or Latin). Do not translate, correct, summarize, explain or add anything, and do not use markdown. '
    . 'If a part is unreadable write [ناخوانا]. Output only the transcription.';

// یک صفحه با هوشِ مصنوعیِ تصویری
function so_ai_page($pdo, $dataUrl, $lang) {
    $c = vdoc_ai_conf($pdo);
    $t0 = microtime(true);
    $hint = $lang === 'eng' ? ' The page is mostly English.' : ($lang === 'fas' ? ' The page is mostly Persian (Farsi).' : '');
    $body = ['model' => $c['model'], 'temperature' => 0, 'max_tokens' => 4096, 'messages' => [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => SO_AI_PROMPT . $hint],
        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']],
    ]]]];
    $res = sms_http('POST', $c['base'] . '/chat/completions', json_encode($body, JSON_UNESCAPED_UNICODE),
                    ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $c['token']], max(60, $c['timeout']));
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($res['error'] !== '' || $res['http'] < 200 || $res['http'] >= 300) {
        $j = json_decode((string)$res['body'], true);
        $msg = is_array($j) ? (string)($j['error']['message'] ?? ($j['message'] ?? '')) : '';
        return ['ok' => false, 'ms' => $ms, 'error' => 'هوشِ مصنوعی: ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http'] . ($msg !== '' ? ' — ' . mb_substr($msg, 0, 160) : ''))];
    }
    $j = json_decode((string)$res['body'], true);
    $m = $j['choices'][0]['message']['content'] ?? '';
    $txt = is_array($m) ? implode('', array_map(function ($p) { return (string)($p['text'] ?? ''); }, $m)) : (string)$m;
    $txt = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($txt)));
    return ['ok' => $txt !== '', 'text' => $txt, 'ms' => $ms, 'engine' => 'ai', 'error' => $txt === '' ? 'هوشِ مصنوعی متنی برنگرداند.' : ''];
}

// یک صفحه با OCR.space (زبانِ per برای فارسی؛ موتورِ ۱ فارسی را پشتیبانی می‌کند)
// PDFِ تک‌صفحه‌ای که مرورگر ساخته (base64) ← بایت‌ها (حداکثر ۱۵ مگابایت، فقط PDF)
function so_pdf_param($b64) {
    if (!is_string($b64) || $b64 === '' || strlen($b64) > 21 * 1024 * 1024) return '';
    $b = base64_decode($b64, true);
    return ($b !== false && substr($b, 0, 5) === '%PDF-') ? $b : '';
}
// یک صفحه با OCR.space. فارسی: OCR.space کدِ «per» را در همه‌ی حساب‌ها/موتورها نمی‌پذیرد (E201)،
//   پس به ترتیب امتحان می‌شود: per (موتورِ ۱) ← auto (موتورِ ۲، تشخیصِ خودکار) ← ara (موتورِ ۱، خطِ عربی/فارسی)
function so_ocrspace_page($pdo, $dataUrl, $lang) {
    $o = so_ocrspace_conf($pdo);
    $t0 = microtime(true);
    $isPdf = strpos((string)$dataUrl, 'data:application/pdf') === 0;
    $tries = $lang === 'eng' ? [['eng', $o['engine']]] : [['per', '1'], ['auto', '2'], ['ara', '1']];
    $last = 'OCR.space: خطا';
    foreach ($tries as [$lc, $engine]) {
        $fields = ['base64Image' => $dataUrl, 'filetype' => $isPdf ? 'PDF' : 'JPG', 'language' => $lc, 'isOverlayRequired' => 'false', 'scale' => 'true',
                   'detectOrientation' => 'true', 'OCREngine' => $engine];
        $ch = curl_init('https://api.ocr.space/parse/image');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_HTTPHEADER => ['apikey: ' . $o['token']],
                                CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 20]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $j = json_decode((string)$body, true);
        if (!is_array($j)) { $last = 'OCR.space: ' . ($err ?: 'پاسخِ نامعتبر'); break; }
        if (!empty($j['IsErroredOnProcessing'])) {
            $last = 'OCR.space: ' . implode(' ', (array)($j['ErrorMessage'] ?? ['خطا']));
            if (preg_match('/E201|language/i', $last)) continue;   // کدِ زبان پذیرفته نشد: کدِ بعدی
            break;
        }
        $txt = trim(implode("\n", array_map(function ($r) { return (string)($r['ParsedText'] ?? ''); }, (array)($j['ParsedResults'] ?? []))));
        $txt = preg_replace("/\r\n?/", "\n", $txt);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        return ['ok' => $txt !== '', 'text' => $txt, 'ms' => $ms, 'engine' => 'ocrspace', 'lang_code' => $lc, 'error' => $txt === '' ? 'OCR.space متنی پیدا نکرد.' : ''];
    }
    return ['ok' => false, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'error' => $last];
}

// یک صفحه: engine = auto (ترتیبِ تنظیمات) | i2pdf | ai | ocrspace
//   $opt: format (قالبِ خروجیِ i2pdf)، ptoken (برای تایم‌لاین)، pdf (PDFِ تک‌صفحه‌ی آماده، اختیاری)
function so_page($pdo, $dataUrl, $lang, $engine = 'auto', array $opt = []) {
    $pdfIn = !empty($opt['pdf']) && substr((string)$opt['pdf'], 0, 5) === '%PDF-' ? (string)$opt['pdf'] : '';
    if ($pdfIn === '' && !preg_match('~^data:image/(jpeg|png|webp);base64,~', (string)$dataUrl)) return ['ok' => false, 'error' => 'عکس یا PDFِ صفحه نرسید.'];
    if (strlen((string)$dataUrl) > 12 * 1024 * 1024) return ['ok' => false, 'error' => 'عکسِ صفحه خیلی بزرگ است.'];
    // عکسِ صفحه برای هوشِ مصنوعی وقتی فقط PDF رسیده (اسکن/عکس داخلِ PDF یک JPEG است)
    $imgOf = function () use (&$dataUrl, $pdfIn) {
        if (preg_match('~^data:image/~', (string)$dataUrl) || $pdfIn === '') return (string)$dataUrl;
        $tmp = tempnam(sys_get_temp_dir(), 'sop') . '.pdf'; file_put_contents($tmp, $pdfIn);
        $x = vdoc_ai_page_image($tmp, 1); @unlink($tmp);
        return $dataUrl = $x ? 'data:' . $x['mime'] . ';base64,' . base64_encode($x['bytes']) : '';
    };
    if (mt_rand(1, 30) === 1) foreach (array_merge(glob(so_tmp_dir() . '/ocrp_*') ?: [], glob(so_tmp_dir() . '/i2_*') ?: []) as $old) if (@filemtime($old) < time() - 86400) @unlink($old);
    $av = so_engines($pdo);
    $cfg = so_cfg($pdo);
    $tok = (string)($opt['ptoken'] ?? '');
    $prog = function ($step, $label) use ($tok) { so_progress($tok, $step, $label); };
    if (isset(SO_ENGINES[$engine]) && $engine !== 'browser') $order = [$engine];
    else { $order = []; foreach ($cfg['order'] as $e) { if ($e === 'browser') break; $order[] = $e; } }   // موتورهای سروریِ پیش از «مرورگر»
    $errs = [];
    foreach ($order as $e) {
        if (empty($av[$e])) { if ($engine === $e) $errs[] = SO_ENGINES[$e] . ' تنظیم نشده یا خاموش است.'; continue; }
        $prog('engine', 'موتور: ' . SO_ENGINES[$e]);
        if ($e === 'i2pdf') {
            require_once __DIR__ . '/_i2pdf.php';
            $pdf = $pdfIn !== '' ? $pdfIn : so_image_pdf($dataUrl);
            $r = $pdf ? i2_page($pdo, $pdf, $lang, (string)($opt['format'] ?? $cfg['format']), $prog) : ['ok' => false, 'error' => 'i2pdf: ساختِ PDFِ صفحه ممکن نشد.'];
        } elseif ($e === 'ai') {
            $im = $imgOf();
            $r = $im !== '' ? so_ai_page($pdo, $im, $lang) : ['ok' => false, 'error' => 'هوشِ مصنوعی: عکسِ صفحه از PDF بیرون کشیده نشد.'];
        } else {
            // OCR.space: اول همان PDFِ باکیفیت، وگرنه عکس
            $r = so_ocrspace_page($pdo, $pdfIn !== '' ? 'data:application/pdf;base64,' . base64_encode($pdfIn) : $dataUrl, $lang);
        }
        if ($r['ok']) { if ($e !== 'i2pdf') $prog('done', 'Your result is ready. (' . SO_ENGINES[$e] . ')'); return $r + ['engine_fa' => SO_ENGINES[$e], 'text_verbatim' => $r['text']]; }
        $prog('fail', $r['error']);
        $errs[] = $r['error'];
    }
    $browser = !empty($av['browser']);
    return ['ok' => false, 'browser' => $browser, 'error' => $errs ? implode(' / ', $errs) : ($browser ? 'موتورِ سروری تنظیم نشده؛ متن در خودِ مرورگر خوانده می‌شود.' : 'هیچ موتورِ روشن و تنظیم‌شده‌ای نیست.')];
}

// OCRِ یک صفحه از فایلِ روی هاست (بدونِ مرورگر): برای پردازشِ خودکارِ مدارکِ شرکت‌ها، ثبتِ دستی و مدارکِ پرسنلی
//   i2pdf ← PDFِ تک‌صفحه؛ هوشِ مصنوعی/OCR.space ← عکسِ صفحه (اسکن‌ها یک JPEGِ تمام‌صفحه‌اند) یا خودِ PDF برای OCR.space
function so_file_text($pdo, $abs, $page = 1, $lang = 'fas') {
    if (!is_file($abs)) return ['ok' => false, 'error' => 'فایل پیدا نشد.'];
    $av = so_engines($pdo);
    $order = array_values(array_filter(so_cfg($pdo)['order'], function ($e) use ($av) { return $e !== 'browser' && !empty($av[$e]); }));
    if (!$order) return ['ok' => false, 'error' => 'هیچ موتورِ OCRِ سروری روشن و تنظیم نشده است.'];
    $isPdf = upload_ext($abs) === 'pdf';
    $img = null; $pdf = null;
    $getImg = function () use (&$img, $abs, $page, $isPdf) {
        if ($img !== null) return $img;
        $img = '';
        if ($isPdf) { $x = vdoc_ai_page_image($abs, $page); if ($x) $img = 'data:' . $x['mime'] . ';base64,' . base64_encode($x['bytes']); }
        else { $b = @file_get_contents($abs); $mime = function_exists('mime_content_type') ? (string)@mime_content_type($abs) : ''; if ($b !== false && strlen($b) < 9 * 1024 * 1024) $img = 'data:' . (preg_match('~^image/(jpeg|png|webp)$~', $mime) ? $mime : 'image/jpeg') . ';base64,' . base64_encode($b); }
        return $img;
    };
    $getPdf = function () use (&$pdf, $abs, $page, $isPdf, $getImg) {
        if ($pdf !== null) return $pdf;
        $pdf = '';
        if ($isPdf) {
            require_once __DIR__ . '/_pdf.php';
            if (intval(pdfx_page_count($abs)) <= 1) $pdf = (string)@file_get_contents($abs);
            else { $tmp = tempnam(sys_get_temp_dir(), 'sop') . '.pdf'; try { pdfx_extract($abs, [$page - 1], $tmp); $pdf = (string)@file_get_contents($tmp); } catch (Throwable $e) {} @unlink($tmp); }
        } elseif (($im = $getImg()) !== '') $pdf = (string)so_image_pdf($im);
        return $pdf;
    };
    $errs = [];
    foreach ($order as $e) {
        if ($e === 'i2pdf') {
            if (($p = $getPdf()) === '') { $errs[] = 'i2pdf: PDFِ صفحه ساخته نشد.'; continue; }
            require_once __DIR__ . '/_i2pdf.php';
            $r = i2_page($pdo, $p, $lang, 'txt', function () {});
        } elseif ($e === 'ai') {
            if (($im = $getImg()) === '') { $errs[] = 'هوشِ مصنوعی: عکسِ صفحه بیرون کشیده نشد.'; continue; }
            $r = so_ai_page($pdo, $im, $lang);
        } else {
            // اول PDFِ باکیفیت (عکس هم اول PDF می‌شود)، وگرنه عکس
            $src = ($p = $getPdf()) !== '' && strlen($p) < 9 * 1024 * 1024 ? 'data:application/pdf;base64,' . base64_encode($p) : $getImg();
            if ($src === '') { $errs[] = 'OCR.space: فایلِ صفحه آماده نشد.'; continue; }
            $r = so_ocrspace_page($pdo, $src, $lang);
        }
        if (!empty($r['ok'])) return ['ok' => true, 'text' => $r['text'], 'engine' => $e, 'ms' => $r['ms'] ?? 0];
        $errs[] = $r['error'] ?? ($e . ': خطا');
    }
    return ['ok' => false, 'error' => implode(' / ', $errs)];
}

// ذخیره در تاریخچه (یا به‌روزرسانیِ متن‌های ویرایش‌شده)
function so_save($pdo, $id, $name, $lang, array $pages) {
    inq_ensure($pdo);
    $clean = [];
    $okId = function ($id) { return preg_match('/^[a-f0-9]{16}\.(pdf|docx)$/', (string)$id) ? (string)$id : ''; };
    foreach ($pages as $p) {
        // فایل‌های اصلیِ i2pdf (Word و PDF) از پوشه‌ی موقت به پوشه‌ی ماندگار
        $files = [];
        foreach ((array)($p['files'] ?? []) as $ext => $fid) {   // نامِ متغیر نباید $id باشد (شناسه‌ی رکورد)
            if (!in_array($ext, ['docx', 'pdf'], true) || ($fid = $okId($fid)) === '') continue;
            if (is_file(so_tmp_dir() . '/i2_' . $fid)) @rename(so_tmp_dir() . '/i2_' . $fid, inq_dir('ocr') . '/' . $fid);
            if (is_file(inq_dir('ocr') . '/' . $fid)) $files[$ext] = $fid;
        }
        $clean[] = ['n' => intval($p['n'] ?? 0), 'text' => mb_substr((string)($p['text'] ?? ''), 0, 200000), 'engine' => (string)($p['engine'] ?? ''), 'ms' => intval($p['ms'] ?? 0), 'files' => $files];
    }
    usort($clean, function ($a, $b) { return $a['n'] - $b['n']; });
    $all = trim(implode("\n", array_column($clean, 'text')));
    $notice = mb_substr(preg_replace('/\s+/u', ' ', $all), 0, 600);
    $json = json_encode(['pages' => $clean, 'lang' => $lang], JSON_UNESCAPED_UNICODE);
    $name = mb_substr(trim((string)$name) ?: 'فایل', 0, 190);
    if ($id) {
        $pdo->prepare("UPDATE inq_requests SET result_json = ?, notice = ?, query_text = ? WHERE id = ? AND kind = 'ocr'")->execute([$json, $notice, $name, intval($id)]);
        return intval($id);
    }
    $actor = inq_actor_from_session();
    $ms = array_sum(array_column($clean, 'ms'));
    $pdo->prepare("INSERT INTO inq_requests (kind, status, notice, result_json, source, user_type, user_id, user_name, created_at, started_at, finished_at, ms, query_text)
                   VALUES ('ocr', ?, ?, ?, 'panel', ?, ?, ?, NOW(), NOW(), NOW(), ?, ?)")
        ->execute([$all !== '' ? 'OK' : 'WARN', $notice, $json, $actor[0], $actor[1] ?: null, mb_substr((string)$actor[2], 0, 190), $ms, $name]);
    return intval($pdo->lastInsertId());
}
function so_pages_of(array $row) {
    $res = json_decode((string)($row['result_json'] ?? ''), true) ?: [];
    $pages = array_map(function ($p) { return $p + ['text_verbatim' => (string)($p['text'] ?? '')]; }, $res['pages'] ?? []);
    return [$pages, $res['lang'] ?? 'fas'];
}
const SO_RTL_RE = '/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

// Word (.docx) — هر خط یک پاراگراف؛ خطِ فارسی راست‌به‌چپ، خطِ لاتین چپ‌به‌راست؛ هر صفحه از صفحه‌ی تازه
function so_docx(array $pages, $title, $dest) {
    if (!class_exists('ZipArchive')) return false;
    $x = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $para = function ($text, $bold = false, $size = 24) use ($x) {
        $rtl = preg_match(SO_RTL_RE, $text) || trim($text) === '';
        return '<w:p><w:pPr>' . ($rtl ? '<w:bidi/><w:jc w:val="right"/>' : '<w:jc w:val="left"/>') . '<w:spacing w:after="60"/></w:pPr><w:r><w:rPr>' . ($rtl ? '<w:rtl/>' : '')
             . '<w:rFonts w:ascii="Tahoma" w:hAnsi="Tahoma" w:cs="Tahoma"/>' . ($bold ? '<w:b/><w:bCs/>' : '') . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>'
             . '<w:t xml:space="preserve">' . $x($text) . '</w:t></w:r></w:p>';
    };
    $body = '';
    foreach (array_values($pages) as $i => $p) {
        if ($i) $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        if (count($pages) > 1) $body .= $para('صفحه‌ی ' . $p['n'], true, 22);
        foreach (preg_split('/\n/u', (string)$p['text']) as $ln) $body .= $para($ln);
    }
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
         . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="709" w:footer="709" w:gutter="0"/><w:bidi/></w:sectPr></w:body></w:document>';
    $z = new ZipArchive();
    if ($z->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
    $z->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . $x($title) . '</dc:title><dc:creator>بیمه با ما</dc:creator></cp:coreProperties>');
    $z->addFromString('word/document.xml', $doc);
    $z->close();
    return is_file($dest);
}

// PDF — فونتِ وزیر؛ خطِ فارسی راست‌به‌چپ و لاتین چپ‌به‌راست
function so_pdf(array $pages, $title, $dest) {
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('بیمه با ما'); $pdf->SetTitle($title);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(true); $pdf->setFooterFont(['vazir', '', 7]);
    $pdf->SetMargins(14, 12, 14); $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->setRTL(true); $pdf->SetFont('vazir', '', 10);
    foreach (array_values($pages) as $p) {
        $pdf->AddPage();
        $h = count($pages) > 1 ? '<p style="color:#64748b;font-size:8pt;border-bottom:0.3px solid #cbd5e1">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' — صفحه‌ی ' . intval($p['n']) . '</p>' : '';
        foreach (preg_split('/\n/u', (string)$p['text']) as $ln) {
            $e = htmlspecialchars($ln, ENT_QUOTES, 'UTF-8');
            $h .= trim($ln) === '' ? '<br>' : (preg_match(SO_RTL_RE, $ln) ? '<p style="line-height:1.7">' . $e . '</p>' : '<p dir="ltr" style="text-align:left;line-height:1.5">' . $e . '</p>');
        }
        $pdf->writeHTML($h, true, false, true, false, '');
    }
    $pdf->Output($dest, 'F');
    return is_file($dest);
}
