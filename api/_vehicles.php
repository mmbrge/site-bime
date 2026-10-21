<?php
// فایل: api/_vehicles.php
// «پرونده‌ی خودرو»: هر پلاک/شاسی یک پرونده، از جمعِ همه‌ی منابع (بدونِ جدولِ تکراری):
//   C = ردیف‌های درخواستِ شرکتی، I = بایگانیِ وارداتی، P = پرونده‌های کارکنان، B = بردروی پاسارگاد
// پلاک (هسته) و شاسی (VIN) با هم گره می‌خورند؛ هر ردیفی که یکی از این دو را داشته باشد به همان خودرو می‌رسد.
// تصویرِ رخِ خودرو: عکسی که برای مدلِ خودرو بارگذاری شده (vh_model_images) یا طرحِ برداریِ هم‌رنگ بر اساسِ نوعِ خودرو.
// نیاز: _case_helpers.php، _company_helpers.php، finance_core.php، _import_archive.php، _bordereau.php

function vh_ensure($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ([
        "CREATE TABLE IF NOT EXISTS vh_model_images (id INT AUTO_INCREMENT PRIMARY KEY, key_norm VARCHAR(150) NOT NULL, label VARCHAR(150) NOT NULL, file_path VARCHAR(400) NOT NULL,
            created_by INT NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_key (key_norm))$T",
        "CREATE TABLE IF NOT EXISTS vh_notes (vkey VARCHAR(60) NOT NULL PRIMARY KEY, note TEXT NULL, updated_by VARCHAR(190) NULL, updated_at DATETIME NULL)$T",
    ] as $q) { try { $pdo->exec($q); } catch (Throwable $e) { error_log('[vh_ensure] ' . $e->getMessage()); } }
}
function vh_img_dir() { $d = __DIR__ . '/data/vehicle_models'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function vh_j($v) { return imp_jdate((string)$v); }
function vh_jn($j) { return preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', (string)$j, $m) ? intval($m[1] . $m[2] . $m[3]) : 0; }
function vh_days_left($j) {
    if (!preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', (string)$j, $m)) return null;
    return (int)floor((jalali_to_gregorian_ts(intval($m[1]), intval($m[2]), intval($m[3])) - strtotime(date('Y-m-d'))) / 86400);
}
function vh_pick(array $srcs, $keys) { foreach ($srcs as $s) foreach ((array)$keys as $k) if (isset($s[$k]) && is_scalar($s[$k]) && trim((string)$s[$k]) !== '') return trim((string)$s[$k]); return ''; }

// نوعِ بدنه برای تصویر: moto | truck | bus | minibus | pickup | van | suv | hatch | sedan
function vh_shape($car, $kind = '', $usage = '', $plate = '') {
    $t = mb_strtolower(str_replace(['ي', 'ك'], ['ی', 'ک'], $car . ' ' . $kind . ' ' . $usage));
    $has = function ($words) use ($t) { foreach ($words as $w) if (mb_strpos($t, $w) !== false) return true; return false; };
    $u = mb_strtolower(str_replace(['ي', 'ك'], ['ی', 'ک'], $kind . ' ' . $usage));
    $uHas = function ($words) use ($u) { foreach ($words as $w) if (mb_strpos($u, $w) !== false) return true; return false; };
    $passenger = $uHas(['سواری', 'شخصی', 'تاکسی', 'آژانس']);
    $cargo = $uHas(['بارکش', 'باری', 'کامیون', 'حمل بار']);
    if (($plate !== '' && function_exists('plate_is_moto') && plate_is_moto($plate)) || $has(['موتورسیکلت', 'موتور سیکلت', 'اسکوتر'])
        || (!$passenger && $has(['هوندا', 'یاماها', 'باجاج']))) return 'moto';
    // کاربری/نوعِ «سواری» → خودروی سواری (هاچ‌بک یا شاسی‌بلند فقط اگر نامِ خودرو این را بگوید)
    if ($passenger && !$cargo) {
        if ($has(['شاسی بلند', 'شاسی‌بلند', 'جیپ', 'لندکروز', 'پرادو', 'توسان', 'اسپورتیج', 'تیگو', 'هاوال', 'فونیکس', 'سانتافه', 'سورنتو', 'x22', 'x33', 'x55', 'j7', 'کی ام سی', 'ریگان', 'کوییک ار'])) return 'suv';
        if ($has(['پراید ۱۱۱', 'پراید 111', '۲۰۶', '206', 'تیبا ۲', 'تیبا 2', 'ساینا', 'کوییک', 'ریو', 'ساندرو', '۲۰۷', '207', 'برلیانس h2', 'mvm 110', 'ام وی ام ۱۱۰'])) return 'hatch';
        return 'sedan';
    }
    if ($has(['اتوبوس'])) return 'bus';
    if ($has(['مینی بوس', 'مینی‌بوس', 'مینیبوس', 'ون ', 'ون‌', 'هایس', 'میکروبوس'])) return $has(['مینی']) ? 'minibus' : 'van';
    if ($has(['کامیون', 'کشنده', 'تریلی', 'تریلر', 'اسکانیا', 'داف', 'هوو', 'ولوو اف', 'آمیکو', 'کمپرسی', 'میکسر', 'تانکر', 'یخچال'])) return 'truck';
    if ($has(['وانت', 'پادرا', 'آریسان', 'زامیاد', 'نیسان', 'مزدا ۲۰۰۰', 'مزدا 2000', 'کاپرا', 'تونلند', 'پیکاپ'])) return 'pickup';
    // کاربریِ «بارکش/باری» بدونِ نامِ وانت → کامیون
    if ($cargo) return 'truck';
    if ($has(['شاسی بلند', 'شاسی‌بلند', 'جیپ', 'لندکروز', 'پرادو', 'توسان', 'اسپورتیج', 'تیگو', 'هاوال', 'فونیکس', 'سانتافه', 'سورنتو', 'x22', 'x33', 'x55', 'j7', 'کی ام سی', 'ریگان', 'کوییک ار'])) return 'suv';
    if ($has(['پراید ۱۱۱', 'پراید 111', '۲۰۶', '206', 'تیبا ۲', 'تیبا 2', 'ساینا', 'کوییک', 'ریو', 'ساندرو', '۲۰۷', '207', 'برلیانس h2', 'mvm 110', 'ام وی ام ۱۱۰'])) return 'hatch';
    return 'sedan';
}
const VH_SHAPE_FA = ['moto' => 'موتورسیکلت', 'truck' => 'کامیون / سنگین', 'bus' => 'اتوبوس', 'minibus' => 'مینی‌بوس', 'pickup' => 'وانت', 'van' => 'ون', 'suv' => 'شاسی‌بلند', 'hatch' => 'هاچ‌بک', 'sedan' => 'سواری'];
function vh_color_hex($c) {
    $c = str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$c);
    foreach (['سفید' => '#f1f5f9', 'مشکی' => '#1f2937', 'نقره' => '#cbd5e1', 'نوک' => '#4b5563', 'خاکستری' => '#6b7280', 'طوسی' => '#9ca3af', 'سرمه' => '#1e3a8a', 'آبی' => '#2563eb',
              'قرمز' => '#dc2626', 'زرشکی' => '#7f1d1d', 'آلبالویی' => '#9f1239', 'سبز' => '#15803d', 'زرد' => '#facc15', 'نارنجی' => '#f97316', 'قهوه' => '#78350f',
              'بژ' => '#d6c7a1', 'کرم' => '#eadfc4', 'طلایی' => '#ca8a04', 'یشمی' => '#0f766e', 'بنفش' => '#6d28d9', 'مسی' => '#b45309'] as $k => $hex)
        if (mb_strpos($c, $k) !== false) return $hex;
    return '#94a3b8';
}
// طرحِ برداریِ نمای کناریِ خودرو (SVG) — هم در صفحه (با انیمیشن) و هم در PDF.
// الگو با جای‌خالیِ {C} (رنگِ بدنه) و {S} (رنگِ خط)؛ کلاس‌های vb/vw/vr/vl برای انیمیشنِ صفحه است و PDF نادیده‌شان می‌گیرد.
function vh_svg_tpl($shape) {
    $dark = '#1e293b'; $glass = '#bae6fd'; $lamp = '#fde68a'; $tail = '#ef4444';
    $wheel = function ($cx, $cy, $r) use ($dark) {
        $sp = '';
        foreach ([0, 60, 120] as $a) { $dx = round(cos(deg2rad($a)) * $r * .5, 1); $dy = round(sin(deg2rad($a)) * $r * .5, 1);
            $sp .= "<line x1='" . ($cx - $dx) . "' y1='" . ($cy - $dy) . "' x2='" . ($cx + $dx) . "' y2='" . ($cy + $dy) . "' stroke='#64748b' stroke-width='3'/>"; }
        return "<g class='vw'><circle cx='$cx' cy='$cy' r='$r' fill='#111827'/><circle cx='$cx' cy='$cy' r='" . round($r * .58, 1) . "' fill='#e2e8f0' stroke='#94a3b8' stroke-width='1.5'/>$sp<circle cx='$cx' cy='$cy' r='" . round($r * .16, 1) . "' fill='$dark'/></g>";
    };
    $arch = function ($cx, $cy, $r) use ($dark) { return "<circle cx='$cx' cy='$cy' r='" . ($r + 4) . "' fill='$dark'/>"; };
    $body = function ($d) { return "<path d='$d' fill='{C}' stroke='{S}' stroke-width='2' stroke-linejoin='round'/>"; };
    $win = function ($d) use ($glass) { return "<path d='$d' fill='$glass' stroke='{S}' stroke-width='1.5' stroke-linejoin='round'/>"; };
    $head = function ($x, $y, $w = 10, $h = 8) use ($lamp) { return "<rect class='vl' x='$x' y='$y' width='$w' height='$h' rx='3' fill='$lamp' stroke='{S}'/>"; };
    $back = function ($x, $y, $w = 6, $h = 9) use ($tail) { return "<rect x='$x' y='$y' width='$w' height='$h' rx='2' fill='$tail'/>"; };
    $line = function ($x1, $y1, $x2, $y2, $w = 1.5) { return "<line x1='$x1' y1='$y1' x2='$x2' y2='$y2' stroke='{S}' stroke-width='$w' stroke-opacity='.6'/>"; };
    $b = ''; $w = '';   // $b: بدنه (بالا و پایین می‌رود)، $w: چرخ‌ها
    switch ($shape) {
        case 'moto':
            $b = "<path d='M80 146 L128 112 L198 112 L240 146' stroke='$dark' stroke-width='6' fill='none' stroke-linejoin='round'/>"
               . "<path d='M126 102 Q138 84 174 86 L198 96 L192 114 L134 114 Z' fill='{C}' stroke='{S}' stroke-width='2'/>"
               . "<path d='M92 102 L130 98 L128 110 L96 112 Z' fill='$dark'/><line x1='204' y1='92' x2='240' y2='146' stroke='#64748b' stroke-width='5'/>"
               . "<line x1='196' y1='84' x2='214' y2='76' stroke='$dark' stroke-width='5' stroke-linecap='round'/><circle class='vl' cx='216' cy='94' r='7' fill='$lamp' stroke='{S}'/>"
               . "<rect x='92' y='126' width='42' height='7' rx='3' fill='#94a3b8'/>" . $back(84, 98, 8, 6);
            $w = $wheel(80, 144, 28) . $wheel(240, 144, 28);
            break;
        case 'truck':
            $b = "<rect x='18' y='38' width='192' height='104' rx='5' fill='#e2e8f0' stroke='{S}' stroke-width='2'/>"
               . "<rect x='18' y='108' width='192' height='10' fill='{C}' opacity='.9'/>" . $line(82, 42, 82, 138) . $line(146, 42, 146, 138)
               . "<rect x='14' y='140' width='286' height='9' rx='2' fill='$dark'/>" . $arch(60, 152, 20) . $arch(104, 152, 20) . $arch(262, 152, 20)
               . $body('M214 142 L214 58 Q214 48 224 48 L262 48 Q274 48 280 60 L294 94 Q298 100 298 110 L298 142 Q298 148 292 148 L214 148 Z')
               . $win('M224 92 L224 58 L260 58 Q268 58 272 66 L286 92 Z') . $line(224, 100, 224, 140) . $head(288, 112, 10, 10) . $back(18, 122, 6, 12)
               . "<rect x='290' y='132' width='10' height='10' rx='2' fill='$dark'/>";
            $w = $wheel(60, 152, 20) . $wheel(104, 152, 20) . $wheel(262, 152, 20);
            break;
        case 'bus': case 'minibus':
            $x0 = $shape === 'bus' ? 16 : 44; $n = $shape === 'bus' ? 6 : 4;
            $b = $arch($x0 + 52, 152, 20) . $arch(250, 152, 20)
               . $body("M$x0 140 L$x0 56 Q$x0 44 " . ($x0 + 12) . " 44 L276 44 Q290 44 294 58 L302 96 L302 140 Q302 148 294 148 L" . ($x0 + 8) . " 148 Q$x0 148 $x0 140 Z")
               . "<rect x='$x0' y='112' width='" . (302 - $x0) . "' height='8' fill='#0f172a' opacity='.25'/>";
            for ($i = 0; $i < $n; $i++) $b .= "<rect x='" . ($x0 + 14 + $i * 38) . "' y='56' width='32' height='36' rx='4' fill='$glass' stroke='{S}' stroke-width='1.5'/>";
            $b .= $win('M' . ($x0 + 14 + $n * 38) . ' 56 L280 56 Q288 56 290 64 L296 96 L' . ($x0 + 14 + $n * 38) . ' 96 Z')
               . "<rect x='" . ($x0 + 16 + $n * 38) . "' y='100' width='22' height='44' rx='3' fill='$glass' stroke='{S}' stroke-width='1.5' opacity='.85'/>"
               . $head(292, 116, 10, 10) . $back($x0, 104, 6, 12);
            $w = $wheel($x0 + 52, 152, 20) . $wheel(250, 152, 20);
            break;
        case 'van':
            $b = $arch(76, 150, 22) . $arch(246, 150, 22)
               . $body('M30 142 L30 62 Q30 50 44 50 L212 50 Q226 50 236 62 L268 98 L284 104 Q294 108 294 120 L294 142 Q294 148 288 148 L36 148 Q30 148 30 142 Z')
               . $win('M42 96 L42 62 L96 62 L96 96 Z') . $win('M104 96 L104 62 L158 62 L158 96 Z') . $win('M166 96 L166 62 L208 62 L208 96 Z')
               . $win('M216 96 L216 62 Q226 62 232 70 L254 96 Z') . $line(162, 100, 162, 140) . $head(284, 112) . $back(30, 104);
            $w = $wheel(76, 150, 22) . $wheel(246, 150, 22);
            break;
        case 'pickup':
            $b = $arch(78, 148, 22) . $arch(244, 148, 22)
               . $body('M30 104 L172 104 L172 146 L36 146 Q30 146 30 140 Z') . "<rect x='30' y='100' width='142' height='6' rx='2' fill='{S}' opacity='.5'/>"
               . $body('M170 146 L170 70 Q170 62 178 62 L222 62 Q232 62 238 72 L254 100 L282 106 Q292 110 292 122 L292 140 Q292 146 286 146 Z')
               . $win('M180 98 L180 70 L218 70 Q226 70 230 78 L244 98 Z') . $line(206, 102, 206, 140) . $head(282, 114) . $back(30, 110);
            $w = $wheel(78, 148, 22) . $wheel(244, 148, 22);
            break;
        case 'suv':
            $b = "<rect x='60' y='54' width='140' height='4' rx='2' fill='$dark'/>" . $arch(80, 150, 24) . $arch(240, 150, 24)
               . $body('M30 142 L30 82 Q30 62 50 61 L206 60 Q218 60 226 70 L250 98 L282 104 Q292 108 292 120 L292 142 Q292 148 286 148 L36 148 Q30 148 30 142 Z')
               . $win('M42 96 L42 72 Q42 68 48 68 L126 68 L126 96 Z') . $win('M134 96 L134 68 L204 68 Q212 68 218 76 L238 96 Z')
               . $line(130, 100, 130, 140) . $head(282, 112) . $back(30, 100);
            $w = $wheel(80, 150, 24) . $wheel(240, 150, 24);
            break;
        case 'hatch':
            $b = $arch(80, 148, 22) . $arch(240, 148, 22)
               . $body('M34 140 L34 108 Q36 96 50 92 L72 74 Q78 70 88 70 L196 70 Q208 70 216 78 L244 104 L280 110 Q290 114 290 126 L290 140 Q290 146 284 146 L40 146 Q34 146 34 140 Z')
               . $win('M58 102 L78 78 L130 78 L130 102 Z') . $win('M138 102 L138 78 L196 78 Q204 78 210 84 L232 102 Z')
               . $line(134, 106, 134, 140) . $head(280, 116) . $back(34, 104);
            $w = $wheel(80, 148, 22) . $wheel(240, 148, 22);
            break;
        default:   // sedan
            $b = $arch(78, 148, 22) . $arch(242, 148, 22)
               . $body('M28 140 L28 118 Q30 108 44 106 L92 102 L122 74 Q128 68 140 68 L200 68 Q212 68 220 76 L246 102 L280 108 Q292 112 292 124 L292 140 Q292 146 286 146 L34 146 Q28 146 28 140 Z')
               . $win('M104 102 L128 76 L168 76 L168 102 Z') . $win('M176 102 L176 76 L204 76 Q212 76 216 80 L236 102 Z')
               . $line(172, 106, 172, 140) . $head(282, 114) . $back(28, 112);
            $w = $wheel(78, 148, 22) . $wheel(242, 148, 22);
    }
    return "<line class='vr' x1='0' y1='178' x2='320' y2='178' stroke='#cbd5e1' stroke-width='3' stroke-dasharray='18 12'/>"
         . "<ellipse cx='160' cy='172' rx='150' ry='7' fill='#0f172a' fill-opacity='0.16'/><g class='vb'>$b</g>$w";
}
function vh_svg_stroke($hex) { return in_array($hex, ['#f1f5f9', '#eadfc4', '#d6c7a1', '#cbd5e1', '#facc15'], true) ? '#64748b' : '#334155'; }
function vh_svg($shape, $color) {
    $c = vh_color_hex($color);
    return "<svg xmlns='http://www.w3.org/2000/svg' width='320' height='200' viewBox='0 0 320 200'>" . str_replace(['{C}', '{S}'], [$c, vh_svg_stroke($c)], vh_svg_tpl($shape)) . '</svg>';
}
// همه‌ی الگوها برای صفحه (تصویرِ کوچکِ فهرست‌ها همین‌ها را با رنگِ هر خودرو پر می‌کند)
function vh_svg_templates() { $o = []; foreach (array_keys(VH_SHAPE_FA) as $k) $o[$k] = vh_svg_tpl($k); return $o; }
// عکسِ بارگذاری‌شده برای مدل (بلندترین نامی که داخلِ نامِ خودرو هست)
function vh_model_image($pdo, $car) {
    static $all = null;
    if ($all === null) { $all = []; try { foreach ($pdo->query("SELECT id, key_norm, label, file_path FROM vh_model_images") as $r) $all[] = $r; } catch (Throwable $e) {} usort($all, function ($a, $b) { return mb_strlen($b['key_norm']) <=> mb_strlen($a['key_norm']); }); }
    $n = brd_norm($car);
    if ($n === '') return null;
    foreach ($all as $r) if ($r['key_norm'] !== '' && mb_strpos($n, $r['key_norm']) !== false && is_file($r['file_path'])) return $r;
    return null;
}

// همه‌ی ردیف‌ها از همه‌ی منابع (شکلِ یکسان). $scope: ['company_ids' => [...]] برای پنلِ شرکت‌ها
function vh_records($pdo, array $scope = []) {
    $root = dirname(__DIR__);
    $rel = function ($p) use ($root) { return $p ? ltrim(str_replace($root, '', (string)$p), '/') : null; };
    $cids = isset($scope['company_ids']) ? array_values(array_filter(array_map('intval', (array)$scope['company_ids']))) : null;
    if ($cids !== null && !$cids) return [];
    $recs = [];
    // C / I: ردیف‌های شرکتی و وارداتی (همه‌ی وضعیت‌ها به جز لغوشده)
    $w = "crp.status <> 'CANCELLED'" . ($cids !== null ? ' AND cr.company_id IN (' . implode(',', $cids) . ')' : '');
    $st = $pdo->query("SELECT crp.*, cr.request_kind, cr.is_import, cr.company_id, cr.created_at AS req_at, c.name AS company_name
                         FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id WHERE $w");
    while ($r = $st->fetch()) {
        $ii = issue_info_decode($r['issue_info']); $oc = json_decode((string)$r['ocr_extracted_data'], true) ?: []; $im = json_decode((string)($r['import_json'] ?? ''), true) ?: [];
        $S = [$ii, $oc, $im];
        $plate = company_plate_display($r['plate_p1'], $r['plate_p2'], $r['plate_letter'], $r['plate_p4']) ?: '';
        $issued = $r['status'] === 'ISSUED';
        $recs[] = ['src' => !empty($r['is_import']) ? 'I' : 'C', 'id' => (int)$r['id'], 'issued' => $issued, 'status' => $r['status'], 'kind' => $r['request_kind'],
            'plate' => $plate, 'plate_core' => $plate ? p2e_digits(plate_core($plate)) : '', 'vin' => brd_vin_key($r['chassis_no'] ?: vh_pick($S, ['vin', 'chassis_no'])),
            'vin_raw' => $r['chassis_no'] ?: vh_pick($S, ['vin', 'chassis_no']), 'engine' => $r['engine_no'] ?: vh_pick($S, 'engine_no'),
            'car' => $r['car_name'] ?: trim(vh_pick($S, 'car_system') . ' ' . vh_pick($S, ['car_type', 'car_tip'])), 'car_kind' => vh_pick($S, ['car_kind']),
            'model_year' => vh_pick($S, ['car_model_year', 'model_year']), 'color' => vh_pick($S, ['car_color', 'color']), 'usage' => vh_pick($S, ['car_usage', 'usage']),
            'type' => $r['insurance_type'], 'policy' => $issued ? ($r['policy_number'] ?: ($r['import_policy_number'] ?? '')) : ($r['import_policy_number'] ?? ''),
            'issue_date' => vh_j($r['policy_issue_date'] ?: (($r['import_issue_date'] ?? '') ?: vh_pick($S, 'issue_date'))) ?: ($r['issued_at'] ? vh_j(jalali_from_gregorian_ts_dotted(strtotime($r['issued_at']))) : ''),
            'start_date' => vh_j(vh_pick($S, 'start_date')), 'end_date' => vh_j(vh_pick($S, 'end_date')),
            'premium' => (int)$r['total_premium'], 'car_value' => (int)$r['car_value'], 'liability' => (int)$r['liability_limit'],
            'insured' => vh_pick($S, 'insured_name') ?: $r['company_name'], 'insured_id' => vh_pick($S, ['insured_national_id', 'national_id']), 'owner' => vh_pick($S, 'owner_name'),
            'company_id' => (int)$r['company_id'], 'company' => $r['company_name'], 'person_id' => null, 'person' => '',
            'file' => $rel($r['issued_file_path']), 'folder' => $rel($r['folder_path'] ?? null), 'at' => $r['issued_at'] ?: $r['req_at'], 'request_id' => (int)$r['request_id']];
    }
    // P: پرونده‌های کارکنان
    if ($cids === null) {
        try {
            $st = $pdo->query("SELECT pc.*, per.full_name, per.national_code, per.company_id AS per_company, co.name AS employer
                                 FROM policy_cases pc LEFT JOIN persons per ON per.id = pc.person_id LEFT JOIN companies co ON co.id = per.company_id
                                WHERE COALESCE(pc.status, '') NOT IN ('WITHDRAWN', 'CANCELLED') AND (pc.plate IS NOT NULL AND pc.plate <> '' OR pc.chassis_num IS NOT NULL AND pc.chassis_num <> '' OR pc.vin IS NOT NULL AND pc.vin <> '')");
            while ($r = $st->fetch()) {
                $ii = issue_info_decode($r['issue_info']); $oc = json_decode((string)$r['ocr_extracted_data'], true) ?: []; $S = [$ii, $oc];
                $issued = $r['status'] === 'ISSUED';
                $recs[] = ['src' => 'P', 'id' => (int)$r['id'], 'issued' => $issued, 'status' => $r['status'], 'kind' => 'NEW_POLICY',
                    'plate' => (string)$r['plate'], 'plate_core' => $r['plate'] ? p2e_digits(plate_core($r['plate'])) : '', 'vin' => brd_vin_key($r['chassis_num'] ?: $r['vin']),
                    'vin_raw' => $r['chassis_num'] ?: $r['vin'], 'engine' => $r['engine_num'], 'car' => trim(($r['car_system'] ?? '') . ' ' . ($r['car_type'] ?? '')), 'car_kind' => '',
                    'model_year' => $r['car_model_year'], 'color' => $r['car_color'], 'usage' => $r['car_usage'], 'type' => $r['insurance_type'], 'policy' => $issued ? $r['policy_number'] : '',
                    'issue_date' => vh_j($r['policy_issue_date'] ?: vh_pick($S, 'issue_date')) ?: ($r['issued_at'] ? vh_j(jalali_from_gregorian_ts_dotted(strtotime($r['issued_at']))) : ''),
                    'start_date' => vh_j(vh_pick($S, 'start_date')), 'end_date' => vh_j(vh_pick($S, 'end_date')),
                    'premium' => (int)$r['total_premium'], 'car_value' => (int)$r['car_value'], 'liability' => (int)preg_replace('/\D/', '', (string)$r['liability_limit']),
                    'insured' => $r['insured_name'] ?: $r['full_name'], 'insured_id' => $r['insured_national_id'] ?: $r['national_code'], 'owner' => vh_pick($S, 'owner_name'),
                    'company_id' => $r['per_company'] ? (int)$r['per_company'] : null, 'company' => $r['employer'] ?: '', 'person_id' => $r['person_id'] ? (int)$r['person_id'] : null,
                    'person' => (string)$r['full_name'], 'file' => $rel($r['issued_file_path']), 'folder' => $rel($r['folder_path'] ?? null), 'at' => $r['issued_at'] ?: $r['created_at'], 'request_id' => null];
            }
        } catch (Throwable $e) { error_log('[vh personnel] ' . $e->getMessage()); }
    }
    // B: بردرو
    foreach (brd_rows($pdo, $cids !== null ? ['company_ids_in' => $cids] : []) as $b) {
        if ($cids !== null && !in_array((int)$b['company_id'], $cids, true)) continue;
        $recs[] = ['src' => 'B', 'id' => $b['id'], 'issued' => true, 'status' => $b['status'], 'kind' => 'NEW_POLICY', 'plate' => $b['plate'], 'plate_core' => (string)$b['plate_core'],
            'vin' => brd_vin_key($b['vin']), 'vin_raw' => $b['vin'], 'engine' => $b['engine'], 'car' => $b['car'], 'car_kind' => $b['car_kind'], 'model_year' => $b['model_year'],
            'color' => $b['color'], 'usage' => $b['usage'], 'type' => $b['type'], 'policy' => $b['policy'], 'issue_date' => $b['issue_date'], 'start_date' => $b['start_date'],
            'end_date' => $b['end_date'], 'premium' => $b['premium'], 'car_value' => $b['car_value'], 'liability' => $b['liability'], 'insured' => $b['insured'], 'insured_id' => $b['insured_id'],
            'owner' => $b['owner_name'], 'company_id' => $b['company_id'], 'company' => $b['company_name'] ?: $b['group'], 'person_id' => $b['person_id'], 'person' => (string)$b['person_name'],
            'file' => null, 'folder' => $b['folder'], 'at' => null, 'request_id' => null, 'brd' => $b];
    }
    // انقضای تخمینی (یک سال بعد از شروع/صدور) و روزِ مانده
    foreach ($recs as &$r) {
        $r['end_est'] = false;
        if ($r['issued'] && !$r['end_date'] && ($r['start_date'] || $r['issue_date'])) {
            [$y, $m, $d] = array_map('intval', explode('/', $r['start_date'] ?: $r['issue_date']));
            $r['end_date'] = sprintf('%04d/%02d/%02d', $y + 1, $m, min($d, company_jalali_month_len($y + 1, $m))); $r['end_est'] = true;
        }
        $r['days_left'] = $r['issued'] && $r['end_date'] ? vh_days_left($r['end_date']) : null;
    }
    unset($r);
    return $recs;
}

// گره زدنِ ردیف‌ها به خودروها (پلاک یا شاسیِ مشترک)
function vh_group(array $recs) {
    $parent = [];
    $find = function ($x) use (&$parent, &$find) { while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; } return $x; };
    $keysOf = function ($r) { $k = []; if ($r['plate_core'] !== '' && mb_strlen($r['plate_core']) >= 5) $k[] = 'p:' . $r['plate_core']; if ($r['vin'] !== '') $k[] = 'v:' . $r['vin']; return $k; };
    foreach ($recs as $i => $r) {
        $ks = $keysOf($r);
        if (!$ks) continue;
        foreach ($ks as $k) if (!isset($parent[$k])) $parent[$k] = $k;
        for ($j = 1; $j < count($ks); $j++) { $a = $find($ks[0]); $b = $find($ks[$j]); if ($a !== $b) $parent[$b] = $a; }
    }
    $groups = [];
    foreach ($recs as $i => $r) { $ks = $keysOf($r); if ($ks) $groups[$find($ks[0])][] = $i; }
    return $groups;
}

// خلاصه‌ی یک خودرو از ردیف‌هایش
function vh_summary($pdo, array $rs) {
    usort($rs, function ($a, $b) { return [vh_jn($b['issue_date']), (string)$b['at']] <=> [vh_jn($a['issue_date']), (string)$a['at']]; });
    $f = function ($k) use ($rs) { foreach ($rs as $r) if (trim((string)($r[$k] ?? '')) !== '') return trim((string)$r[$k]); return ''; };
    $vin = $f('vin'); $plateCore = $f('plate_core');
    $key = $vin !== '' ? 'v:' . $vin : 'p:' . $plateCore;
    $issued = array_values(array_filter($rs, function ($r) { return $r['issued'] && $r['status'] !== 'CANCELLED'; }));
    $active = []; $next = null;
    foreach ($issued as $r) if ($r['days_left'] !== null && $r['days_left'] >= 0) { $active[$r['type']] = true; if ($next === null || $r['days_left'] < $next['days_left']) $next = $r; }
    $lastByType = [];
    foreach ($issued as $r) if (!isset($lastByType[$r['type']])) $lastByType[$r['type']] = $r;
    $car = $f('car'); $plate = $f('plate');
    $shape = vh_shape($car, $f('car_kind'), $f('usage'), $plate);
    $srcs = array_values(array_unique(array_map(function ($r) { return $r['src']; }, $rs)));
    return ['key' => $key, 'plate' => $plate, 'plate_core' => $plateCore, 'vin' => $f('vin_raw'), 'engine' => $f('engine'), 'car' => $car, 'model_year' => $f('model_year'),
            'color' => $f('color'), 'usage' => $f('usage'), 'shape' => $shape, 'shape_fa' => VH_SHAPE_FA[$shape], 'owner' => $f('owner') ?: $f('insured'), 'insured' => $f('insured'),
            'company' => $f('company'), 'company_id' => (function () use ($rs) { foreach ($rs as $r) if (!empty($r['company_id'])) return (int)$r['company_id']; return null; })(),
            'person' => $f('person'), 'person_id' => (function () use ($rs) { foreach ($rs as $r) if (!empty($r['person_id'])) return (int)$r['person_id']; return null; })(),
            'sources' => $srcs, 'records' => count($rs), 'policies' => count($issued), 'open_requests' => count(array_filter($rs, function ($r) { return !$r['issued'] && $r['src'] !== 'B'; })),
            'has_body' => isset($lastByType['BODY']), 'has_third' => isset($lastByType['THIRDPARTY']),
            'body_end' => isset($lastByType['BODY']) ? $lastByType['BODY']['end_date'] : '', 'body_left' => isset($lastByType['BODY']) ? $lastByType['BODY']['days_left'] : null,
            'third_end' => isset($lastByType['THIRDPARTY']) ? $lastByType['THIRDPARTY']['end_date'] : '', 'third_left' => isset($lastByType['THIRDPARTY']) ? $lastByType['THIRDPARTY']['days_left'] : null,
            'next_end' => $next ? $next['end_date'] : '', 'next_left' => $next ? $next['days_left'] : null, 'next_type' => $next ? insurance_type_fa($next['type']) : '',
            'last_policy' => $issued ? $issued[0]['policy'] : '', 'premium_total' => array_sum(array_map(function ($r) { return (int)$r['premium']; }, $issued))];
}
function vh_list($pdo, array $scope = []) {
    $recs = vh_records($pdo, $scope);
    $out = [];
    foreach (vh_group($recs) as $idx) $out[] = vh_summary($pdo, array_map(function ($i) use ($recs) { return $recs[$i]; }, $idx));
    usort($out, function ($a, $b) { $x = $a['next_left'] ?? 99999; $y = $b['next_left'] ?? 99999; return $x <=> $y ?: strcmp($a['plate'], $b['plate']); });
    return $out;
}

// پرونده‌ی کامل: خلاصه، ردیف‌ها (بیمه‌نامه‌ها و درخواست‌ها)، مدارک، اقساط، الحاقیه‌ها، تصویر
function vh_detail($pdo, $key, array $scope = []) {
    $recs = vh_records($pdo, $scope);
    $groups = vh_group($recs);
    $hit = null;
    foreach ($groups as $idx) foreach ($idx as $i) {
        $r = $recs[$i];
        if (($r['vin'] !== '' && $key === 'v:' . $r['vin']) || ($r['plate_core'] !== '' && $key === 'p:' . $r['plate_core'])) { $hit = $idx; break 2; }
    }
    if (!$hit) return null;
    $rs = array_map(function ($i) use ($recs) { return $recs[$i]; }, $hit);
    $sum = vh_summary($pdo, $rs);
    usort($rs, function ($a, $b) { return [vh_jn($b['issue_date']), (string)$b['at']] <=> [vh_jn($a['issue_date']), (string)$a['at']]; });
    $root = dirname(__DIR__);
    // مدارک
    $docs = [];
    $cIds = array_map(function ($r) { return $r['id']; }, array_filter($rs, function ($r) { return in_array($r['src'], ['C', 'I'], true); }));
    $pIds = array_map(function ($r) { return $r['id']; }, array_filter($rs, function ($r) { return $r['src'] === 'P'; }));
    if ($cIds) foreach ($pdo->query("SELECT id, plate_id, doc_type, file_path, orig_name, uploaded_at, assigned_at FROM company_documents WHERE plate_id IN (" . implode(',', $cIds) . ") AND status = 'ASSIGNED' ORDER BY id DESC") as $d) {
        if (!$d['file_path'] || is_dir($root . '/' . $d['file_path'])) continue;
        $docs[] = ['label' => company_doc_type_label($d['doc_type']), 'file' => ltrim($d['file_path'], '/'), 'name' => $d['orig_name'] ?: basename($d['file_path']), 'src' => 'C', 'row' => (int)$d['plate_id'],
                   'at' => ($d['assigned_at'] ?: $d['uploaded_at']) ? jalali_from_gregorian_ts_dotted(strtotime($d['assigned_at'] ?: $d['uploaded_at'])) : ''];
    }
    if ($pIds) foreach ($pdo->query("SELECT * FROM case_documents WHERE case_id IN (" . implode(',', $pIds) . ") ORDER BY id DESC") as $d) {
        if (!$d['file_path']) continue;
        $docs[] = ['label' => $d['doc_label'] ?: $d['doc_key'], 'file' => ltrim(str_replace($root, '', $d['file_path']), '/'), 'name' => basename($d['file_path']), 'src' => 'P', 'row' => (int)$d['case_id'],
                   'at' => !empty($d['created_at']) ? jalali_from_gregorian_ts_dotted(strtotime($d['created_at'])) : ''];
    }
    foreach ($rs as $r) if ($r['file']) $docs[] = ['label' => 'بیمه‌نامه‌ی ' . insurance_type_fa($r['type']) . ' ' . $r['policy'], 'file' => $r['file'], 'name' => basename($r['file']), 'src' => $r['src'], 'row' => $r['id'], 'at' => $r['issue_date'], 'policy' => true];
    // اقساط
    $insts = [];
    $instQ = function ($table, $col, $allocTable, $ids, $src) use ($pdo, &$insts) {
        if (!$ids) return;
        try {
            foreach ($pdo->query("SELECT i.id, i.$col AS rid, i.inst_number, i.amount, i.due_jalali, i.due_date, (SELECT COALESCE(SUM(a.amount), 0) FROM $allocTable a WHERE a.installment_id = i.id) AS paid
                                    FROM $table i WHERE i.$col IN (" . implode(',', $ids) . ") ORDER BY i.$col, i.inst_number") as $i) {
                $paid = (int)$i['paid']; $amt = (int)$i['amount'];
                $over = $paid < $amt && $i['due_date'] && strtotime($i['due_date']) < strtotime('today');
                $insts[] = ['src' => $src, 'row' => (int)$i['rid'], 'n' => (int)$i['inst_number'], 'amount' => $amt, 'paid' => $paid, 'due' => $i['due_jalali'],
                            'state' => $paid >= $amt && $amt > 0 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : ($over ? 'OVERDUE' : 'UNPAID')),
                            'delay' => $over ? (int)floor((strtotime('today') - strtotime($i['due_date'])) / 86400) : 0];
            }
        } catch (Throwable $e) { error_log('[vh insts] ' . $e->getMessage()); }
    };
    $instQ('company_installments', 'plate_id', 'company_payment_allocations', array_map(function ($r) { return $r['id']; }, array_filter($rs, function ($r) { return $r['src'] === 'C' && $r['issued']; })), 'C');
    $instQ('policy_installments', 'case_id', 'payment_allocations', array_map(function ($r) { return $r['id']; }, array_filter($rs, function ($r) { return $r['src'] === 'P' && $r['issued']; })), 'P');
    // الحاقیه‌های صادره‌های سامانه
    $endo = [];
    $pols = array_values(array_filter(array_unique(array_map(function ($r) { return (string)$r['policy']; }, array_filter($rs, function ($r) { return $r['src'] !== 'B'; })))));
    if ($pols) {
        try {
            $in = implode(',', array_map(function ($p) use ($pdo) { return $pdo->quote($p); }, $pols));
            foreach ($pdo->query("SELECT policy_number, endorse_no, kind, reason, issue_date, new_end_date, gross FROM policy_endorsements WHERE policy_number IN ($in) ORDER BY id") as $e)
                $endo[] = ['policy' => $e['policy_number'], 'no' => $e['endorse_no'], 'kind' => $e['kind'], 'reason' => $e['reason'], 'date' => $e['issue_date'], 'new_end' => $e['new_end_date'], 'amount' => (int)$e['gross']];
        } catch (Throwable $e) {}
    }
    // مالک‌ها / بیمه‌گذارها در طولِ زمان
    $owners = [];
    foreach ($rs as $r) {
        $name = $r['owner'] ?: $r['insured'];
        if ($name === '') continue;
        $k = brd_norm($name);
        if (!isset($owners[$k])) $owners[$k] = ['name' => $name, 'insured_id' => $r['insured_id'], 'company' => $r['company'], 'person_id' => $r['person_id'], 'from' => $r['issue_date'], 'to' => $r['issue_date'], 'n' => 0];
        $owners[$k]['n']++;
        if ($r['issue_date'] && (!$owners[$k]['from'] || vh_jn($r['issue_date']) < vh_jn($owners[$k]['from']))) $owners[$k]['from'] = $r['issue_date'];
        if ($r['issue_date'] && vh_jn($r['issue_date']) > vh_jn($owners[$k]['to'])) $owners[$k]['to'] = $r['issue_date'];
    }
    $note = null;
    try { $n = $pdo->prepare("SELECT note, updated_by, updated_at FROM vh_notes WHERE vkey = ?"); $n->execute([$sum['key']]); $note = $n->fetch() ?: null; } catch (Throwable $e) {}
    $img = vh_model_image($pdo, $sum['car']);
    $srcFa = ['C' => 'شرکتی', 'I' => 'وارداتی', 'P' => 'کارکنان', 'B' => 'بردرو'];
    return ['summary' => $sum, 'svg' => vh_svg($sum['shape'], $sum['color']), 'model_image' => $img ? ['id' => (int)$img['id'], 'label' => $img['label']] : null,
            'records' => array_map(function ($r) use ($srcFa) {
                $o = $r; unset($o['brd']);
                $o['src_fa'] = $srcFa[$r['src']]; $o['type_fa'] = insurance_type_fa($r['type']);
                $o['status_fa'] = $r['src'] === 'B' ? ($r['brd']['state_fa'] ?? '') : ($r['issued'] ? 'صادر شده' : (company_status_fa($r['status']) ?: $r['status']));
                if ($r['src'] === 'B') $o['endorsements'] = $r['brd']['endorsements'];
                return $o;
            }, $rs),
            'docs' => $docs, 'installments' => $insts, 'endorsements' => $endo, 'owners' => array_values($owners), 'note' => $note];
}
if (!function_exists('company_status_fa')) {
    function company_status_fa($s) {
        return ['PENDING' => 'منتظرِ مدارک', 'DOCS_PENDING' => 'منتظرِ مدارک', 'READY_FOR_ISSUE' => 'آماده‌ی صدور', 'WITH_BOSS' => 'نزدِ مدیر', 'IN_ISSUANCE' => 'در حالِ صدور',
                'ISSUED' => 'صادر شده', 'CANCELLED' => 'لغو شده', 'REJECTED' => 'رد شده'][$s] ?? '';
    }
}

// گزارشِ PDFِ زیبای یک خودرو
function vh_report_pdf($pdo, array $d, $dest) {
    if (!defined('K_PATH_CACHE')) { $c = rtrim(sys_get_temp_dir(), '/') . '/bime_tcpdf_cache/'; @mkdir($c, 0775, true); define('K_PATH_CACHE', $c); }
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $s = $d['summary'];
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12); $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddFont('vazir', '', 'vazir.php'); $pdf->AddFont('vazir', 'B', 'vazirb.php');
    $pdf->setRTL(true); $pdf->SetFont('vazir', '', 9);
    $pdf->AddPage();
    $e = function ($v) { return htmlspecialchars(fa_digits((string)$v), ENT_QUOTES, 'UTF-8'); };
    $money = function ($v) use ($e) { return $v ? $e(number_format((int)$v)) . ' ریال' : '—'; };
    // سربرگ
    $pdf->SetFillColor(30, 58, 138); $pdf->Rect(0, 0, 210, 46, 'F');
    $pdf->SetFillColor(14, 116, 144); $pdf->Rect(0, 40, 210, 6, 'F');
    $img = $d['model_image'] ? vh_model_image($pdo, $s['car']) : null;
    $pdf->setRTL(false);   // در حالتِ راست‌چین TCPDF مختصاتِ عکس را وارونه می‌کند
    $pdf->SetFillColor(255, 255, 255); $pdf->RoundedRect(12, 5, 58, 36, 4, '1111', 'F');
    if ($img) { try { $pdf->Image($img['file_path'], 13, 6, 56, 34, '', '', '', true, 200, '', false, false, 0, 'CM'); } catch (Throwable $ex) { $img = null; } }
    if (!$img) {
        // TCPDF بدونِ viewBox طرح را به اندازه‌ی کادر نمی‌برد (۳۲۰ واحد ≈ ۱۱۳ میلی‌متر)؛ برای کادرِ ۵۰ میلی‌متری با گروهِ کوچک‌شده می‌کشیم
        $inner = preg_replace('/^<svg[^>]*>|<\/svg>$/', '', $d['svg']);
        $inner = preg_replace("/<g class='[^']*'>|<\\/g>/", '', $inner);   // گروه‌های انیمیشن به کارِ PDF نمی‌آیند
        try { $pdf->ImageSVG('@' . "<svg xmlns='http://www.w3.org/2000/svg' width='320' height='200'><g transform='scale(0.390625)'>$inner</g></svg>", 16, 8, 50, 31); } catch (Throwable $ex) {}
    }
    $pdf->setRTL(true);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->writeHTMLCell(125, 0, 12, 8, '<b style="font-size:17pt">پرونده‌ی خودرو</b><br/><span style="font-size:12pt">' . $e($s['car'] ?: 'خودرو') . ($s['model_year'] ? ' · مدل ' . $e($s['model_year']) : '') . '</span><br/>'
        . '<span style="font-size:10pt">پلاک: ' . $e($s['plate'] ?: '—') . ' · شاسی: ' . $e($s['vin'] ?: '—') . '</span><br/><span style="font-size:8.5pt;color:#c7d2fe">تاریخِ گزارش: ' . $e(jalali_from_gregorian_ts_dotted(time())) . '</span>', 0, 1, false, true, 'R');
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetY(52);
    $box = function ($title, $rows) use ($e) {
        $h = '<table cellpadding="4" style="border:1px solid #c7d2fe;"><tr><td colspan="4" style="background-color:#eef2ff;color:#3730a3;font-weight:bold;font-size:10pt;">' . $title . '</td></tr>';
        for ($i = 0; $i < count($rows); $i += 2) {
            $h .= '<tr>';
            for ($j = 0; $j < 2; $j++) { $r = $rows[$i + $j] ?? ['', '']; $h .= '<td width="17%" style="color:#64748b;font-size:8.5pt">' . $r[0] . '</td><td width="33%" style="font-size:9pt"><b>' . $e($r[1] === '' ? '—' : $r[1]) . '</b></td>'; }
            $h .= '</tr>';
        }
        return $h . '</table><br/>';
    };
    $html = $box('مشخصاتِ خودرو', [['پلاک', $s['plate']], ['شماره شاسی', $s['vin']], ['شماره موتور', $s['engine']], ['نام خودرو', $s['car']], ['مدل', $s['model_year']],
                                    ['رنگ', $s['color']], ['کاربری', $s['usage']], ['نوع', $s['shape_fa']]]);
    $html .= $box('مالک و بیمه‌گذار', [['مالک / بیمه‌گذار', $s['owner']], ['شرکت', $s['company']], ['پرسنل', $s['person']], ['تعداد بیمه‌نامه', $s['policies']],
                                       ['بیمه‌ی بدنه تا', $s['body_end']], ['بیمه‌ی ثالث تا', $s['third_end']]]);
    // بیمه‌نامه‌ها
    $html .= '<table cellpadding="4" style="font-size:8pt;"><tr><td colspan="7" style="background-color:#eef2ff;color:#3730a3;font-weight:bold;font-size:10pt;">بیمه‌نامه‌ها و درخواست‌ها</td></tr>'
           . '<tr style="background-color:#f1f5f9;font-weight:bold;"><td width="9%">منبع</td><td width="9%">نوع</td><td width="24%">شماره بیمه‌نامه</td><td width="12%">صدور</td><td width="12%">انقضا</td><td width="16%">حق بیمه</td><td width="18%">بیمه‌گذار</td></tr>';
    foreach ($d['records'] as $i => $r) {
        $html .= '<tr style="background-color:' . ($i % 2 ? '#f8fafc' : '#ffffff') . ';"><td>' . $e($r['src_fa']) . '</td><td>' . $e($r['type_fa']) . '</td><td>' . $e($r['policy'] ?: $r['status_fa']) . '</td>'
               . '<td>' . $e($r['issue_date']) . '</td><td>' . $e($r['end_date']) . ($r['end_est'] ? '*' : '') . '</td><td>' . ($r['premium'] ? $e(number_format($r['premium'])) : '—') . '</td><td>' . $e($r['insured']) . '</td></tr>';
    }
    $html .= '</table><br/>';
    if ($d['installments']) {
        $over = array_filter($d['installments'], function ($i) { return $i['state'] === 'OVERDUE'; });
        $rem = array_sum(array_map(function ($i) { return max(0, $i['amount'] - $i['paid']); }, $d['installments']));
        $html .= $box('اقساط', [['تعداد اقساط', count($d['installments'])], ['معوق', count($over)], ['مانده', $rem ? number_format($rem) . ' ریال' : '۰'], ['', '']]);
    }
    if ($d['docs']) {
        $html .= '<table cellpadding="3" style="font-size:8pt;"><tr><td colspan="2" style="background-color:#eef2ff;color:#3730a3;font-weight:bold;font-size:10pt;">مدارکِ موجود (' . $e(count($d['docs'])) . ')</td></tr>';
        foreach (array_slice($d['docs'], 0, 40) as $doc) $html .= '<tr><td width="40%">' . $e($doc['label']) . '</td><td width="60%" style="color:#64748b">' . $e($doc['at']) . '</td></tr>';
        $html .= '</table><br/>';
    }
    if (!empty($d['note']['note'])) $html .= $box('یادداشت', [['', $d['note']['note']], ['', '']]);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($dest, 'F');
    return is_file($dest);
}
