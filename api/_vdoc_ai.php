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
kind: "sales_invoice" (سند/فاکتور فروش وسیله نقلیه از کارخانه یا نمایندگی), "ownership_doc" (سند مالکیت / برگ سبز), "car_card" (کارت خودرو), "policy" (بیمه‌نامه), "national_card" (کارت ملی) or "unknown".
Fields (omit when not visible; write all digits as Latin 0-9; keep Persian text in Persian):
vin (شماره شاسی / VIN), chassis_no (شماره شاسی as written when it differs from the VIN), engine_no (شماره موتور) — for these three return ONLY Latin capital letters and digits: remove every * - / \\ . _ | ( ) # and space even if they appear in the document (e.g. "MC11.44-50*250817216967*" → "MC114450250817216967", "NAAP-41FE/5PJ.123456" → "NAAP41FE5PJ123456"),
owner_name (نام خریدار / مالک; for a company the company name), national_id (کد ملی or شناسه ملی of that owner, 10 or 11 digits),
car_system (سیستم / برند), car_tip (تیپ), usage (نوع کاربری), car_name (vehicle name as written, e.g. "کامیون کشنده پیلسان C7H"),
model_year (مدل), color (رنگ), fuel (نوع سوخت), capacity (ظرفیت), cylinders (تعداد سیلندر), axles (تعداد محور), wheels (تعداد چرخ), cab_no (شماره اتاق),
plate as four parts: plate_two (2 digits next to the letter), plate_letter (the Persian letter), plate_three (3 digits), plate_iran (2 digits next to «ایران»);
car card back: card_serial (شماره مسلسل), car_kind (نوع), country (کشور سازنده); owner_kind ("COMPANY" for a company/organisation or an 11-digit national id, else "PERSON"), father_name, birth_date (تاریخ تولد/ثبت),
mobile (تلفن همراه), phone, postal_code (کد پستی), address (آدرس محل سکونت; for a company the legal-owner address),
doc_no (شماره سند / فاکتور), doc_date (تاریخ, Jalali YYYY/MM/DD), economic_code (کد اقتصادی of the seller if shown), seller (فروشنده).
For a policy (بیمه‌نامه): policy_number (not the proposal number), unique_code, insurer, policy_type ("BODY"/"THIRDPARTY"), issue_date, start_date, end_date, insured_name, insured_national_id, insured_postal_code, insured_address,
car_value (sum insured, digits), premium, liability, no_claim_years, no_claim_percent, covers. For a national card (کارت ملی, kind "national_card"): national_id, first_name, last_name, father_name, birth_date, card_expiry.
Labels may be misspelled by the scan («سبستم»=سیستم, «نیپ»=تیپ). Remove «(6 دانگ)» from owner names. VIN is 17 characters without spaces.
TXT;

function vdoc_ai_read($pdo, $path, $pageNo = 1) {
    $c = vdoc_ai_conf($pdo);
    if (!vdoc_ai_ready($pdo)) return ['ok' => false, 'scan' => true, 'error' => 'این صفحه اسکن است؛ سرویسِ «خواندنِ اسکن (هوش مصنوعی)» تنظیم نشده — اطلاعات را دستی وارد کنید.', 'engines' => []];
    $img = vdoc_ai_page_image($path, $pageNo);
    if (!$img) return ['ok' => false, 'scan' => true, 'error' => 'عکسِ این صفحه از PDF بیرون کشیده نشد؛ اطلاعات را دستی وارد کنید.', 'engines' => []];
    if (strlen($img['bytes']) > 12 * 1024 * 1024) return ['ok' => false, 'scan' => true, 'error' => 'عکسِ صفحه برای خواندن خیلی بزرگ است.', 'engines' => []];
    $body = ['model' => $c['model'], 'temperature' => 0, 'max_tokens' => 1600, 'messages' => [['role' => 'user', 'content' => [
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
    $kind = in_array($o['kind'] ?? '', ['sales_invoice', 'ownership_doc', 'car_card', 'policy', 'national_card'], true) ? $o['kind'] : 'unknown';
    $code = function ($v) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p2e_digits((string)$v))); };
    $txt = function ($v) { return trim(preg_replace('/\s+/u', ' ', p2e_digits(str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$v)))); };
    $d = [];
    foreach (['owner_name', 'father_name', 'car_system', 'car_tip', 'car_kind', 'usage', 'car_name', 'color', 'fuel', 'address', 'seller', 'plate', 'country', 'share', 'first_name', 'last_name',
              'insured_name', 'insured_address', 'covers', 'prev_policy_number', 'file_no'] as $k) if (($v = $txt($o[$k] ?? '')) !== '') $d[$k] = mb_substr($v, 0, 400);
    // ظرفیت با واحد («7 نفر»، «25 تن»)
    if (($v = $txt($o['capacity'] ?? '')) !== '') $d['capacity'] = mb_substr($v, 0, 40);
    if (in_array(strtoupper((string)($o['owner_kind'] ?? '')), ['COMPANY', 'PERSON'], true)) $d['owner_kind'] = strtoupper((string)$o['owner_kind']);
    foreach (['national_id', 'model_year', 'cylinders', 'axles', 'wheels', 'mobile', 'phone', 'postal_code', 'economic_code', 'register_no', 'card_serial',
              'insured_national_id', 'insured_postal_code', 'insured_phone', 'unique_code', 'no_claim_years', 'no_claim_percent'] as $k) {
        $v = preg_replace('/[^\d]/', '', p2e_digits((string)($o[$k] ?? '')));
        if ($v !== '') $d[$k] = $v;
    }
    // شماره شاسی و VIN جدا (پرامپت‌های تازه)؛ اگر فقط یکی آمد، همان برای هر دو (شناسه‌ی خودرو)
    $vinIn = $code($o['vin'] ?? ''); $chIn = $code($o['chassis_no'] ?? '');
    if (strlen($chIn) >= 5) $d['chassis_no'] = $chIn;
    if (strlen($vinIn) >= 6) $d['vin'] = $vinIn;
    if (empty($d['vin']) && !empty($d['chassis_no'])) $d['vin'] = $d['chassis_no'];
    if (empty($d['chassis_no']) && !empty($d['vin'])) $d['chassis_no'] = $d['vin'];
    if (($v = $code($o['engine_no'] ?? '')) !== '' && strlen($v) >= 5) $d['engine_no'] = $v;
    if (($v = $code($o['cab_no'] ?? '')) !== '') $d['cab_no'] = $v;
    if (($v = $txt($o['doc_no'] ?? '')) !== '') $d['doc_no'] = $v;
    foreach (['doc_date', 'birth_date', 'card_expiry', 'issue_date'] as $k) if (!empty($o[$k]) && preg_match('/(1[34]\d\d)\D+(\d{1,2})\D+(\d{1,2})/', p2e_digits((string)$o[$k]), $m)) $d[$k] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    // کد ملیِ ۸-۹ رقمی (صفرهای اولِ افتاده)؛ شناسه‌ی ملیِ ۱۱ رقمی یعنی شرکت
    if (!empty($d['national_id']) && strlen($d['national_id']) >= 8 && strlen($d['national_id']) < 10) $d['national_id'] = str_pad($d['national_id'], 10, '0', STR_PAD_LEFT);
    if (empty($d['owner_kind']) && !empty($d['national_id'])) $d['owner_kind'] = strlen($d['national_id']) === 11 ? 'COMPANY' : 'PERSON';
    if (empty($d['owner_name']) && (!empty($d['first_name']) || !empty($d['last_name']))) $d['owner_name'] = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
    if (!empty($d['owner_name'])) $d['owner_name'] = trim(preg_replace('/[\(\[]?\s*\d+\s*دانگ\s*[\)\]]?/u', '', $d['owner_name']));
    if (empty($d['car_name']) && (!empty($d['car_system']) || !empty($d['car_tip']))) $d['car_name'] = car_join([$d['car_system'] ?? '', $d['car_tip'] ?? '']);
    // پلاکِ چهاربخشی (پرامپت‌های پردازشِ هوشمند): plate_parts برای نشاندن روی ردیف + متنِ پلاک
    $pp = ['two' => preg_replace('/\D/', '', p2e_digits((string)($o['plate_two'] ?? ''))), 'letter' => $txt($o['plate_letter'] ?? ''),
           'three' => preg_replace('/\D/', '', p2e_digits((string)($o['plate_three'] ?? ''))), 'iran' => preg_replace('/\D/', '', p2e_digits((string)($o['plate_iran'] ?? '')))];
    if (in_array($pp['letter'], ['آ', 'ا'], true)) $pp['letter'] = 'الف';
    if (strlen($pp['two']) === 2 && strlen($pp['three']) === 3 && strlen($pp['iran']) === 2 && $pp['letter'] !== '') {
        $d['plate_parts'] = $pp;
        $d['plate'] = $pp['iran'] . 'ایران - ' . $pp['three'] . ' ' . $pp['letter'] . ' ' . $pp['two'];
    }
    // بیمه‌نامه
    if (($v = $txt($o['policy_number'] ?? '')) !== '') $d['policy_number'] = mb_substr($v, 0, 60);
    if (($v = $txt($o['insurer'] ?? '')) !== '') $d['insurer'] = mb_substr($v, 0, 120);
    if (!empty($o['policy_type'])) $d['policy_type'] = stripos((string)$o['policy_type'], 'BODY') !== false || mb_strpos((string)$o['policy_type'], 'بدنه') !== false ? 'BODY' : 'THIRDPARTY';
    foreach (['start_date', 'end_date'] as $k) if (!empty($o[$k]) && preg_match('/(1[34]\d\d)\D+(\d{1,2})\D+(\d{1,2})/', p2e_digits((string)$o[$k]), $m)) $d[$k] = sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]);
    foreach (['liability', 'car_value', 'premium'] as $k) if (($v = preg_replace('/\D/', '', p2e_digits((string)($o[$k] ?? '')))) !== '') $d[$k] = $v;
    if ($kind === 'unknown' && !empty($d['vin']) && !empty($d['engine_no'])) $kind = !empty($d['plate']) ? 'ownership_doc' : 'sales_invoice';
    if ($kind === 'national_card') { unset($d['vin'], $d['chassis_no'], $d['engine_no']); }
    $req = VDR_REQUIRED[$kind] ?? [];
    $missing = [];
    foreach ($req as [$kk, $fa]) if (empty($d[$kk])) $missing[] = $fa;
    $ident = !empty($d['vin']) || !empty($d['engine_no']);
    $status = ($req && !$missing) ? 'full' : (($ident || $kind !== 'unknown') ? 'partial' : 'none');
    return ['ok' => true, 'kind' => $kind, 'kind_fa' => VDR_KIND_FA[$kind], 'status' => $status, 'data' => $d, 'missing' => $missing, 'engines' => ['ai'], 'barcodes' => []];
}
