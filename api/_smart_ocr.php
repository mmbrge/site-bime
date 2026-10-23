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

const SO_ENGINES = ['ai' => 'هوشِ مصنوعی (GapGPT)', 'ocrspace' => 'OCR.space', 'browser' => 'مرورگر (Tesseract)'];

function so_ocrspace_conf($pdo) {
    $c = api_cfg($pdo)['ocr_space'] ?? [];
    return ['token' => trim((string)($c['token'] ?? '')), 'on' => ($c['enabled'] ?? '1') !== '0', 'engine' => in_array($c['engine'] ?? '', ['1', '2', '3'], true) ? $c['engine'] : '1'];
}
// موتورهای آماده
function so_engines($pdo) {
    $o = so_ocrspace_conf($pdo);
    return ['ai' => vdoc_ai_ready($pdo), 'ocrspace' => $o['on'] && $o['token'] !== '' && function_exists('curl_init'), 'browser' => true];
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
function so_ocrspace_page($pdo, $dataUrl, $lang) {
    $o = so_ocrspace_conf($pdo);
    $t0 = microtime(true);
    $fields = ['base64Image' => $dataUrl, 'language' => $lang === 'eng' ? 'eng' : 'per', 'isOverlayRequired' => 'false', 'scale' => 'true', 'detectOrientation' => 'true',
               'OCREngine' => $lang === 'eng' ? $o['engine'] : '1'];
    $ch = curl_init('https://api.ocr.space/parse/image');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_HTTPHEADER => ['apikey: ' . $o['token']],
                            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 12]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $j = json_decode((string)$body, true);
    if (!is_array($j)) return ['ok' => false, 'ms' => $ms, 'error' => 'OCR.space: ' . ($err ?: 'پاسخِ نامعتبر')];
    if (!empty($j['IsErroredOnProcessing'])) return ['ok' => false, 'ms' => $ms, 'error' => 'OCR.space: ' . implode(' ', (array)($j['ErrorMessage'] ?? ['خطا']))];
    $txt = trim(implode("\n", array_map(function ($r) { return (string)($r['ParsedText'] ?? ''); }, (array)($j['ParsedResults'] ?? []))));
    $txt = preg_replace("/\r\n?/", "\n", $txt);
    return ['ok' => $txt !== '', 'text' => $txt, 'ms' => $ms, 'engine' => 'ocrspace', 'error' => $txt === '' ? 'OCR.space متنی پیدا نکرد.' : ''];
}

// یک صفحه: engine = auto | ai | ocrspace
function so_page($pdo, $dataUrl, $lang, $engine = 'auto') {
    if (!preg_match('~^data:image/(jpeg|png|webp);base64,~', (string)$dataUrl)) return ['ok' => false, 'error' => 'عکسِ صفحه نرسید.'];
    if (strlen($dataUrl) > 9 * 1024 * 1024) return ['ok' => false, 'error' => 'عکسِ صفحه خیلی بزرگ است.'];
    $av = so_engines($pdo);
    $order = $engine === 'ai' ? ['ai'] : ($engine === 'ocrspace' ? ['ocrspace'] : ['ai', 'ocrspace']);
    $errs = [];
    foreach ($order as $e) {
        if (empty($av[$e])) { if ($engine === $e) $errs[] = SO_ENGINES[$e] . ' تنظیم نشده است.'; continue; }
        $r = $e === 'ai' ? so_ai_page($pdo, $dataUrl, $lang) : so_ocrspace_page($pdo, $dataUrl, $lang);
        if ($r['ok']) return $r + ['engine_fa' => SO_ENGINES[$e]];
        $errs[] = $r['error'];
    }
    return ['ok' => false, 'browser' => true, 'error' => $errs ? implode(' / ', $errs) : 'موتورِ سروری تنظیم نشده؛ متن در خودِ مرورگر خوانده می‌شود.'];
}

// ذخیره در تاریخچه (یا به‌روزرسانیِ متن‌های ویرایش‌شده)
function so_save($pdo, $id, $name, $lang, array $pages) {
    inq_ensure($pdo);
    $clean = [];
    foreach ($pages as $p) $clean[] = ['n' => intval($p['n'] ?? 0), 'text' => mb_substr((string)($p['text'] ?? ''), 0, 200000), 'engine' => (string)($p['engine'] ?? ''), 'ms' => intval($p['ms'] ?? 0)];
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
    return [$res['pages'] ?? [], $res['lang'] ?? 'fas'];
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
