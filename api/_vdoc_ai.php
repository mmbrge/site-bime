<?php
// فایل: api/_vdoc_ai.php
// خواندنِ مدارکِ اسکن‌شده (عکس یا PDFِ بدونِ متن) با یک سرویسِ هوشِ مصنوعیِ تصویری سازگار با OpenAI (chat/completions)
// — فقط اگر در «تنظیمات ← کلیدهای API ← خواندنِ اسکن (هوش مصنوعی)» آدرس و کلید وارد شده باشد؛ وگرنه مثلِ قبل «اسکن است، دستی وارد کنید».
// خروجی هم‌شکلِ vdr_read: ['ok','kind','kind_fa','status','data','missing','engines' => ['ai']]
require_once __DIR__ . '/_apis.php';

function vdoc_ai_conf($pdo) {
    $c = api_cfg($pdo)['ai_vision'] ?? [];
    // سرویس‌دهنده‌ی GapGPT: آدرس، کلید و مدل از کارتِ GapGPT
    if (($c['provider'] ?? '') === 'gapgpt') {
        $g = gapgpt_conf($pdo);
        return ['base' => $g['base'], 'token' => $g['token'], 'model' => trim((string)($c['model'] ?? '')) ?: $g['model'], 'timeout' => $g['timeout'], 'on' => ($c['enabled'] ?? '1') !== '0'];
    }
    $base = rtrim(trim((string)($c['base_url'] ?? '')), '/');
    $token = preg_replace('/^Bearer\s+/i', '', trim((string)($c['token'] ?? '')));
    return ['base' => $base, 'token' => $token, 'model' => trim((string)($c['model'] ?? '')) ?: 'gpt-4o-mini',
            'timeout' => max(15, min(180, intval($c['timeout'] ?? 60) ?: 60)), 'on' => ($c['enabled'] ?? '1') !== '0'];
}
function vdoc_ai_ready($pdo) { $c = vdoc_ai_conf($pdo); return $c['on'] && $c['base'] !== '' && $c['token'] !== '' && function_exists('curl_init'); }

// تصویرِ یک صفحه: فایلِ عکس همان‌طور؛ PDF => بزرگ‌ترین عکسِ JPEGِ آن صفحه (اسکن‌ها معمولاً یک عکسِ تمام‌صفحه‌اند)
function vdoc_ai_page_image($path, $pageNo = 1) {
    $h = (string)@file_get_contents($path, false, null, 0, 5);
    if ($h !== '%PDF-') {
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($path) : '';
        if (!preg_match('~^image/(jpeg|png|webp|gif)$~', $mime)) $mime = 'image/jpeg';
        $b = @file_get_contents($path);
        return $b ? ['mime' => $mime, 'bytes' => $b] : null;
    }
    require_once __DIR__ . '/_pdf.php';
    try {
        $doc = PdfDoc::open($path);
        $pg = $doc->pages()[max(0, $pageNo - 1)] ?? null;
        if (!$pg) return null;
        $best = null;
        $scan = function ($res, $depth) use (&$scan, &$best, $doc) {
            $res = $doc->resolve($res);
            if (!$res instanceof PdfDict || $depth > 3) return;
            $xo = $doc->resolve($res->get('XObject'));
            if (!$xo instanceof PdfDict) return;
            foreach ($xo->v as $ref) {
                $st = $doc->resolve($ref);
                if (!$st instanceof PdfStream) continue;
                $sub = $doc->resolve($st->dict->get('Subtype'));
                if ($sub === 'Form') { $scan($st->dict->get('Resources'), $depth + 1); continue; }
                if ($sub !== 'Image') continue;
                $f = $doc->resolve($st->dict->get('Filter'));
                $fs = array_map([$doc, 'resolve'], is_array($f) ? $f : [$f]);
                if (end($fs) !== 'DCTDecode' && end($fs) !== 'DCT') continue;
                $area = (int)$doc->resolve($st->dict->get('Width')) * (int)$doc->resolve($st->dict->get('Height'));
                if (!$best || $area > $best['area']) $best = ['area' => $area, 'st' => $st];
            }
        };
        $scan($pg['res'], 0);
        if (!$best) return null;
        $bytes = $doc->decodeStream($best['st']);   // فیلترهای پیش از DCT باز می‌شوند؛ خودِ JPEG دست نمی‌خورد
        return substr($bytes, 0, 2) === "\xFF\xD8" ? ['mime' => 'image/jpeg', 'bytes' => $bytes] : null;
    } catch (Throwable $e) { error_log('[vdoc_ai image] ' . $e->getMessage()); return null; }
}

const VDOC_AI_PROMPT = <<<TXT
This is a scanned Iranian vehicle document (Persian). Identify it and extract fields. Reply with ONE JSON object only, no prose.
kind: "sales_invoice" (سند/فاکتور فروش وسیله نقلیه از کارخانه یا نمایندگی), "ownership_doc" (سند مالکیت / برگ سبز), "car_card" (کارت خودرو), "policy" (بیمه‌نامه) or "unknown".
Fields (omit when not visible; write all digits as Latin 0-9; keep Persian text in Persian):
vin (شماره شاسی / VIN, Latin letters and digits only), engine_no (شماره موتور, Latin letters and digits only, drop * . - and spaces),
owner_name (نام خریدار / مالک; for a company the company name), national_id (کد ملی or شناسه ملی of that owner, 10 or 11 digits),
car_system (سیستم / برند), car_tip (تیپ), usage (نوع کاربری), car_name (vehicle name as written, e.g. "کامیون کشنده پیلسان C7H"),
model_year (مدل), color (رنگ), fuel (نوع سوخت), capacity (ظرفیت), cylinders (تعداد سیلندر), axles (تعداد محور), wheels (تعداد چرخ), cab_no (شماره اتاق),
plate (if a plate is written, as "NNایران - NNN X NN"), mobile (تلفن همراه), phone, postal_code (کد پستی), address (آدرس محل سکونت),
doc_no (شماره سند / فاکتور), doc_date (تاریخ, Jalali YYYY/MM/DD), economic_code (کد اقتصادی of the seller if shown), seller (فروشنده).
TXT;

function vdoc_ai_read($pdo, $path, $pageNo = 1) {
    $c = vdoc_ai_conf($pdo);
    if (!vdoc_ai_ready($pdo)) return ['ok' => false, 'scan' => true, 'error' => 'این صفحه اسکن است؛ سرویسِ «خواندنِ اسکن (هوش مصنوعی)» تنظیم نشده — اطلاعات را دستی وارد کنید.', 'engines' => []];
    $img = vdoc_ai_page_image($path, $pageNo);
    if (!$img) return ['ok' => false, 'scan' => true, 'error' => 'عکسِ این صفحه از PDF بیرون کشیده نشد؛ اطلاعات را دستی وارد کنید.', 'engines' => []];
    if (strlen($img['bytes']) > 12 * 1024 * 1024) return ['ok' => false, 'scan' => true, 'error' => 'عکسِ صفحه برای خواندن خیلی بزرگ است.', 'engines' => []];
    $body = ['model' => $c['model'], 'temperature' => 0, 'max_tokens' => 900, 'messages' => [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => VDOC_AI_PROMPT],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $img['mime'] . ';base64,' . base64_encode($img['bytes'])]],
    ]]]];
    $res = sms_http('POST', $c['base'] . '/chat/completions', json_encode($body, JSON_UNESCAPED_UNICODE),
                    ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $c['token']], $c['timeout']);
    if ($res['error'] !== '' || $res['http'] < 200 || $res['http'] >= 300) {
        $j = json_decode((string)$res['body'], true);
        $msg = is_array($j) ? (string)($j['error']['message'] ?? ($j['message'] ?? '')) : '';
        return ['ok' => false, 'scan' => true, 'engines' => ['ai'], 'error' => 'سرویسِ خواندنِ اسکن پاسخ نداد' . ($res['error'] !== '' ? ': ' . $res['error'] : ' (HTTP ' . $res['http'] . ($msg !== '' ? ' — ' . mb_substr($msg, 0, 160) : '') . ')') . '؛ اطلاعات را دستی وارد کنید.'];
    }
    $j = json_decode((string)$res['body'], true);
    $txt = (string)($j['choices'][0]['message']['content'] ?? '');
    if (is_array($j['choices'][0]['message']['content'] ?? null)) $txt = implode('', array_map(function ($p) { return (string)($p['text'] ?? ''); }, $j['choices'][0]['message']['content']));
    $out = preg_match('/\{[\s\S]*\}/u', $txt, $m) ? json_decode($m[0], true) : null;
    if (!is_array($out)) return ['ok' => false, 'scan' => true, 'engines' => ['ai'], 'error' => 'پاسخِ سرویسِ خواندنِ اسکن قابلِ استفاده نبود؛ اطلاعات را دستی وارد کنید.'];
    return vdoc_ai_normalize($out);
}

// پاسخِ سرویس => همان ساختارِ خواننده‌ی متنی
function vdoc_ai_normalize(array $o) {
    require_once __DIR__ . '/_vdoc_reader.php';
    $kind = in_array($o['kind'] ?? '', ['sales_invoice', 'ownership_doc', 'car_card', 'policy'], true) ? $o['kind'] : 'unknown';
    $code = function ($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); };
    $txt = function ($v) { return trim(preg_replace('/\s+/u', ' ', p2e_digits(str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$v)))); };
    $d = [];
    foreach (['owner_name', 'car_system', 'car_tip', 'usage', 'car_name', 'color', 'fuel', 'address', 'seller', 'plate'] as $k) if (($v = $txt($o[$k] ?? '')) !== '') $d[$k] = $v;
    foreach (['national_id', 'model_year', 'capacity', 'cylinders', 'axles', 'wheels', 'mobile', 'phone', 'postal_code', 'economic_code'] as $k) {
        $v = preg_replace('/[^\d]/', '', p2e_digits((string)($o[$k] ?? '')));
        if ($v !== '') $d[$k] = $v;
    }
    if (($v = $code($o['vin'] ?? '')) !== '' && strlen($v) >= 6) { $d['vin'] = $v; $d['chassis_no'] = $v; }
    if (($v = $code($o['engine_no'] ?? '')) !== '' && strlen($v) >= 5) $d['engine_no'] = $v;
    if (($v = $code($o['cab_no'] ?? '')) !== '') $d['cab_no'] = $v;
    if (($v = $txt($o['doc_no'] ?? '')) !== '') $d['doc_no'] = $v;
    if (!empty($o['doc_date']) && preg_match('/(1[34]\d\d)\D+(\d{1,2})\D+(\d{1,2})/', p2e_digits((string)$o['doc_date']), $m)) $d['doc_date'] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    if (empty($d['car_name']) && (!empty($d['car_system']) || !empty($d['car_tip']))) $d['car_name'] = car_join([$d['car_system'] ?? '', $d['car_tip'] ?? '']);
    if ($kind === 'unknown' && !empty($d['vin']) && !empty($d['engine_no'])) $kind = !empty($d['plate']) ? 'ownership_doc' : 'sales_invoice';
    $req = VDR_REQUIRED[$kind] ?? [];
    $missing = [];
    foreach ($req as [$kk, $fa]) if (empty($d[$kk])) $missing[] = $fa;
    $ident = !empty($d['vin']) || !empty($d['engine_no']);
    $status = ($req && !$missing) ? 'full' : (($ident || $kind !== 'unknown') ? 'partial' : 'none');
    return ['ok' => true, 'kind' => $kind, 'kind_fa' => VDR_KIND_FA[$kind], 'status' => $status, 'data' => $d, 'missing' => $missing, 'engines' => ['ai'], 'barcodes' => []];
}
