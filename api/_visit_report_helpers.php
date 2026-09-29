<?php
// فایل: api/_visit_report_helpers.php
// کمکی‌های ماژولِ «گزارش بازدید خودرو» (نسخه‌ی وبِ برنامه‌ی ویندوزیِ صدور گزارش بازدید):
// دسترسی‌ها، ساختِ مقدارهای قالب از روی فرم، نام‌گذاری و بایگانی، ساختِ PDF/Word، دفترِ اکسلِ
// گزارشات، و اتصال به بازدید سلامت / درخواست‌های کارکنان و شرکتی.
// پیش‌نیاز: _case_helpers.php و _company_helpers.php قبل از این فایل include شده باشند.
require_once __DIR__ . '/_report_engine.php';

const VR_DEFAULT_PYTHON = '/home/besiteir/virtualenv/ocr-paddle/3.11/bin/python3';

function vr_ready($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM visit_reports LIMIT 1"); $pdo->query("SELECT 1 FROM report_categories LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
const VR_SCHEMA_MSG = 'برای بخش گزارش بازدید، مایگریشن migrations/018_visit_reports.sql را در phpMyAdmin اجرا کنید.';

function vr_insurers() { return ['PASARGAD' => 'پاسارگاد', 'PARSIAN' => 'پارسیان', 'IRAN' => 'ایران', 'OTHER' => 'سایر']; }
function vr_insurer_fa($k) { return vr_insurers()[$k] ?? ($k ?: 'سایر'); }
function vr_field_types() {
    return ['Entry' => 'متن کوتاه', 'Textarea' => 'متن چندخطی', 'Number' => 'عدد', 'Money' => 'مبلغ (ریال)', 'Combobox' => 'انتخاب از لیست',
            'Checkbox' => 'تیک (بله/خیر)', 'Date' => 'تاریخ شمسی', 'Time' => 'ساعت', 'Visitor' => 'بازدیدکننده',
            'PartsStatus' => 'چک‌لیست قطعات (سالم/خسارتی)', 'DamageList' => 'مواضع آسیب‌دیده'];
}
// ستون‌هایی از رکوردِ گزارش که از روی «ستون اکسل» هر فیلد پر می‌شوند
function vr_excel_columns() {
    return ['نوع وسیله', 'سیستم/برند', 'تیپ', 'مدل/سال', 'رنگ', 'ظرفیت', 'مورد استفاده', 'شماره موتور', 'شماره شاسی', 'سیلندر',
            'ارزش وسیله', 'بیمه‌نامه سال قبل', 'تاریخ انقضا قبلی', 'شرکت بیمه قبلی', 'بازدید کننده'];
}

// ---------------------------------------------------------------------
//  کاربر و دسترسی
// ---------------------------------------------------------------------
function vr_current_user($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    $st = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ?" . (auth_schema_ready($pdo) ? " AND COALESCE(is_deleted, 0) = 0" : ''));
    $st->execute([intval($_SESSION['user_id'])]);
    $u = $st->fetch();
    if (!$u) return null;
    return ['id' => intval($u['id']), 'name' => $u['full_name'], 'role' => $u['role'], 'is_admin' => $u['role'] === 'ADMIN'];
}

function vr_access($cat) {
    $a = json_decode($cat['access_json'] ?? '', true);
    return ['roles' => array_values(array_filter((array)($a['roles'] ?? []))), 'users' => array_map('intval', (array)($a['users'] ?? []))];
}
// مدیر کل همه‌ی انواع را دارد؛ بقیه فقط نوع‌هایی که نقش یا خودشان در «دسترسی» آن انتخاب شده باشند
function vr_can_issue($cat, $user) {
    if (!$user) return false;
    if ($user['is_admin']) return true;
    if (!intval($cat['is_active'] ?? 1)) return false;
    $a = vr_access($cat);
    return in_array($user['role'], $a['roles'], true) || in_array($user['id'], $a['users'], true);
}
function vr_user_categories($pdo, $user, $activeOnly = true) {
    $rows = $pdo->query("SELECT * FROM report_categories " . ($activeOnly ? "WHERE is_active = 1 " : '') . "ORDER BY sort_order, id")->fetchAll();
    return array_values(array_filter($rows, fn($c) => vr_can_issue($c, $user)));
}
function vr_user_has_access($pdo, $user) {
    if (!$user || !vr_ready($pdo)) return false;
    if ($user['is_admin'] || $user['role'] === 'PARSIAN') return true;
    return count(vr_user_categories($pdo, $user)) > 0;
}

function vr_category($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM report_categories WHERE id = ?");
    $st->execute([intval($id)]);
    return $st->fetch() ?: null;
}
function vr_fields($pdo, $catId) {
    $st = $pdo->prepare("SELECT * FROM report_fields WHERE category_id = ? ORDER BY sort_order, id");
    $st->execute([intval($catId)]);
    $out = [];
    foreach ($st->fetchAll() as $f) {
        $f['tick_map'] = $f['tick_map'] ? (json_decode($f['tick_map'], true) ?: []) : [];
        $f['id'] = intval($f['id']);
        $f['required'] = intval($f['required']);
        $f['max_chars'] = $f['max_chars'] !== null ? intval($f['max_chars']) : null;
        $out[] = $f;
    }
    return $out;
}
// چک‌لیستِ قطعاتِ یک فیلد PartsStatus: «c1|شیشه جلو;c2|برف پاک کن ها;...»
function vr_parts_list($field) {
    $out = [];
    foreach (preg_split('/[;\n]+/u', (string)$field['options']) as $tok) {
        if (strpos($tok, '|') === false) continue;
        [$pid, $label] = array_map('trim', explode('|', $tok, 2));
        if ($pid !== '' && $label !== '') $out[] = ['id' => $pid, 'label' => $label];
    }
    return $out;
}
function vr_combo_options($field) {
    return array_values(array_filter(array_map('trim', preg_split('/[,،\n]+/u', (string)$field['options'])), fn($v) => $v !== ''));
}

function vr_visitors($pdo, $catId = null) {
    $rows = $pdo->query("SELECT v.*, GROUP_CONCAT(vc.category_id) AS cats FROM report_visitors v LEFT JOIN report_visitor_categories vc ON vc.visitor_id = v.id
                          WHERE v.is_active = 1 GROUP BY v.id ORDER BY v.name")->fetchAll();
    $out = [];
    foreach ($rows as $v) {
        $cats = $v['cats'] ? array_map('intval', explode(',', $v['cats'])) : [];
        if ($catId && $cats && !in_array(intval($catId), $cats, true)) continue;
        $out[] = ['id' => intval($v['id']), 'name' => $v['name'], 'categories' => $cats];
    }
    return $out;
}
function vr_insureds($pdo, $catId = null) {
    $rows = $pdo->query("SELECT i.*, GROUP_CONCAT(ic.category_id) AS cats FROM report_insureds i LEFT JOIN report_insured_categories ic ON ic.insured_id = i.id
                          WHERE i.is_active = 1 GROUP BY i.id ORDER BY i.name")->fetchAll();
    $out = [];
    foreach ($rows as $v) {
        $cats = $v['cats'] ? array_map('intval', explode(',', $v['cats'])) : [];
        if ($catId && $cats && !in_array(intval($catId), $cats, true)) continue;
        $out[] = ['id' => intval($v['id']), 'name' => $v['name'], 'national_id' => $v['national_id'], 'phone' => $v['phone'], 'address' => $v['address'],
                  'source' => $v['source'], 'source_id' => $v['source_id'] !== null ? intval($v['source_id']) : null, 'categories' => $cats];
    }
    return $out;
}

function vr_setting($pdo, $key, $default = null) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
function vr_set_setting($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

// ---------------------------------------------------------------------
//  نقشه‌ی قالب (layout) - با اولین استفاده یا بعد از آپلودِ قالبِ تازه ساخته می‌شود
// ---------------------------------------------------------------------
function vr_asset_dir($catId) { return dirname(__DIR__) . '/report_assets/layouts/cat_' . intval($catId); }

function vr_layout($pdo, $cat, $force = false) {
    $siteRoot = dirname(__DIR__);
    $tpl = $cat['template_path'] ? $siteRoot . '/' . ltrim($cat['template_path'], '/') : null;
    if (!$tpl || !is_file($tpl)) throw new RuntimeException('برای این نوع گزارش هنوز قالب Word بارگذاری نشده است (تنظیمات گزارش).');
    $assetDir = vr_asset_dir($cat['id']);
    $layout = (!$force && $cat['layout_json']) ? json_decode($cat['layout_json'], true) : null;
    // نسخه‌ی قدیمیِ چیدمان (بدونِ شکلِ کادرها، مثلاً دایره) یک بار دوباره خوانده می‌شود؛ تنظیم‌های دستی حفظ می‌شود
    if (!$layout || !is_dir($assetDir) || intval($layout['version'] ?? 1) < 2) {
        $old = $layout;
        $layout = rpt_analyze_docx($tpl, $assetDir);
        // جابه‌جایی‌های دستیِ قبلی (dx/dy/hidden) روی کادرهای هم‌جا حفظ می‌شود
        if ($old) $layout = vr_carry_adjustments($old, $layout);
        $pdo->prepare("UPDATE report_categories SET layout_json = ?, layout_updated_at = NOW() WHERE id = ?")
            ->execute([json_encode($layout, JSON_UNESCAPED_UNICODE), $cat['id']]);
    }
    return [$layout, $assetDir];
}
function vr_carry_adjustments($old, $new) {
    $key = fn($it) => $it['page'] . '|' . round($it['x']) . '|' . round($it['y']) . '|' . $it['type'];
    $map = [];
    $keys = ['font', 'fsize', 'text', 'showif', 'bold'];
    foreach ($old['items'] ?? [] as $it) {
        $has = ($it['dx'] ?? 0) || ($it['dy'] ?? 0) || !empty($it['hidden']);
        foreach ($keys as $k) if (isset($it[$k]) && $it[$k] !== '' && $it[$k] !== null) $has = true;
        if ($has) $map[$key($it)] = $it;
    }
    foreach ($new['items'] as &$it) {
        if (!isset($map[$key($it)])) continue;
        $o = $map[$key($it)];
        $it['dx'] = $o['dx'] ?? 0; $it['dy'] = $o['dy'] ?? 0;
        if (!empty($o['hidden'])) $it['hidden'] = 1;
        if ($it['type'] === 'box') foreach ($keys as $k) if (isset($o[$k]) && $o[$k] !== '' && $o[$k] !== null) $it[$k] = $o[$k];
    }
    unset($it);
    // کادرهای کپی‌شده در ویرایشگر (در قالبِ Word نیستند) با قالبِ تازه هم می‌مانند
    foreach ($old['items'] ?? [] as $it) if (!empty($it['clone'])) $new['items'][] = $it;
    return $new;
}
// تنظیماتِ ساختِ PDFِ یک نوع گزارش: قلم‌ها، ضریبِ اندازه، فاصله‌ی خطوط و «یک قلم برای همه‌ی کادرها»
function vr_render_opts($pdo, $cat, array $extra = []) {
    $o = json_decode($cat['options_json'] ?? '', true) ?: [];
    return $extra + ['fontMap' => vr_font_map($pdo), 'fontScale' => floatval($o['font_scale'] ?? 1) ?: 1,
                     'lineHeight' => floatval($o['line_height'] ?? 1.1) ?: 1.1, 'fontAll' => trim((string)($o['font_all'] ?? ''))];
}
// قلم‌هایی که در ویرایشگرِ قالب قابلِ انتخاب‌اند: قلم‌های آپلودی + قلم‌های همراهِ سایت
function vr_font_choices($pdo) {
    $out = [['value' => 'Vazir', 'label' => 'وزیر (پیش‌فرض فارسی)'], ['value' => 'Arial', 'label' => 'Helvetica (لاتین) + وزیر'], ['value' => 'Arial Narrow', 'label' => 'Helvetica فشرده (Arial Narrow) + وزیر']];
    foreach (array_keys(vr_font_map($pdo)) as $w) $out[] = ['value' => $w, 'label' => $w . ' (آپلودی)'];
    return $out;
}
function vr_font_map($pdo) {
    $map = [];
    try {
        foreach ($pdo->query("SELECT * FROM report_fonts")->fetchAll() as $f) {
            $map[$f['word_name']] = ['family' => $f['family'], 'regular' => $f['regular_file'], 'bold' => $f['bold_file']];
        }
    } catch (Throwable $e) {}
    return $map;
}

// ---------------------------------------------------------------------
//  عدد به حروف (همان خروجیِ برنامه‌ی ویندوزی)
// ---------------------------------------------------------------------
function vr_num_words($value) {
    $digits = preg_replace('/\D/', '', p2e_digits((string)$value));
    if ($digits === '') return '';
    $n = intval(ltrim($digits, '0') ?: '0');
    if ($n === 0) return 'صفر';
    $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hunds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $scales = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوادریلیون'];
    $three = function ($x) use ($ones, $teens, $tens, $hunds) {
        $p = [];
        if ($x >= 100) { $p[] = $hunds[intdiv($x, 100)]; $x %= 100; }
        if ($x >= 10 && $x <= 19) { $p[] = $teens[$x - 10]; $x = 0; }
        else { if ($x >= 20) { $p[] = $tens[intdiv($x, 10)]; $x %= 10; } if ($x) $p[] = $ones[$x]; }
        return implode(' و ', $p);
    };
    $groups = []; $i = 0;
    while ($n > 0) {
        $chunk = $n % 1000;
        if ($chunk) { $t = $three($chunk); if (!empty($scales[$i])) $t .= ' ' . $scales[$i]; $groups[] = $t; }
        $n = intdiv($n, 1000); $i++;
    }
    return implode(' و ', array_reverse($groups));
}
function vr_money($v) {
    $d = preg_replace('/\D/', '', p2e_digits((string)$v));
    return $d === '' ? '' : number_format((float)$d, 0, '.', ',');
}

// ---------------------------------------------------------------------
//  پلاک و تاریخ
// ---------------------------------------------------------------------
// پلاک طبق قراردادِ سایت: «{ایران}ایران - {سه‌رقمی} {حرف} {دورقمی}»
function vr_plate_display($p1, $letter, $p2, $iran) {
    $p1 = trim((string)$p1); $letter = trim((string)$letter); $p2 = trim((string)$p2); $iran = trim((string)$iran);
    if ($p1 === '' && $p2 === '' && $iran === '') return '';
    return "{$iran}ایران - {$p2} {$letter} {$p1}";
}
// «۱۴۰۵/۰۷/۰۶» یا «1405.7.6» => ['1405.07.06', timestamp]
function vr_parse_jalali($s) {
    $s = p2e_digits(trim((string)$s));
    if (!preg_match('/^(1[34]\d{2})\D+(\d{1,2})\D+(\d{1,2})$/', $s, $m)) return null;
    [$jy, $jm, $jd] = [intval($m[1]), intval($m[2]), intval($m[3])];
    if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;
    return [sprintf('%04d.%02d.%02d', $jy, $jm, $jd), jalali_to_gregorian_ts($jy, $jm, $jd)];
}
function vr_rounded_time() {
    $t = time(); $m = intval(date('i', $t)); $r = $m % 5;
    $t += ($r >= 3 ? (5 - $r) : -$r) * 60;
    return date('H:i', $t);
}

// ---------------------------------------------------------------------
//  ساختِ مقدارهای قالب از روی فرم (همان منطقِ generate_report برنامه‌ی ویندوزی، ولی تنظیم‌پذیر)
// ---------------------------------------------------------------------
// $form: ['plate' => [p1, letter, p2, iran], 'insured' => [...], 'fields' => [key => value], 'parts' => [pid => s|k],
//         'damages' => [[location, description], ...], 'report_date' => '1405.07.06']
function vr_field_visible($f, $fieldsValues) {
    if (!$f['show_if']) return true;
    if (!preg_match('/^\s*([A-Za-z0-9_]+)\s*(!?=)\s*(.*)$/u', $f['show_if'], $m)) return true;
    $v = trim((string)($fieldsValues[$m[1]] ?? ''));
    $want = trim($m[3]);
    $eq = ($want === '1') ? in_array($v, ['1', 'true', 'on', 'بله'], true) : $v === $want;
    return $m[2] === '=' ? $eq : !$eq;
}

function vr_build_values($cat, array $fields, array $form, array $meta) {
    $tick = $cat['tick_mark'] ?: '✔';
    $okS = $cat['ok_suffix'] ?: 's'; $badS = $cat['bad_suffix'] ?: 'k';
    $fv = $form['fields'] ?? [];
    $v = [];
    foreach ($fields as $f) {
        $k = $f['field_key'];
        $type = $f['field_type'];
        $visible = vr_field_visible($f, $fv);
        if ($type === 'PartsStatus') {
            foreach (vr_parts_list($f) as $p) {
                $st = ($form['parts'][$p['id']] ?? 's') === 'k' ? 'k' : 's';
                $v["{$p['id']}_{$okS}"] = $visible && $st === 's' ? $tick : '';
                $v["{$p['id']}_{$badS}"] = $visible && $st === 'k' ? $tick : '';
                // توضیحِ خسارتِ قطعه: c25_s_t و c25_k_t و c25_t (هر کدام که در قالب گذاشته شود)
                $note = ($visible && $st === 'k') ? trim((string)($form['part_notes'][$p['id']] ?? '')) : '';
                $v["{$p['id']}_{$okS}_t"] = $v["{$p['id']}_{$badS}_t"] = $v["{$p['id']}_t"] = $note;
            }
            continue;
        }
        if ($type === 'DamageList') {
            $n = max(1, intval($f['options'] ?: 5));
            $d = array_values($form['damages'] ?? []);
            for ($i = 1; $i <= $n; $i++) {
                $v["damage_location_{$i}"] = $visible ? trim((string)($d[$i - 1]['location'] ?? '')) : '';
                $v["damage_description_{$i}"] = $visible ? trim((string)($d[$i - 1]['description'] ?? '')) : '';
            }
            continue;
        }
        $val = $visible ? trim((string)($fv[$k] ?? '')) : '';
        if ($type === 'Money') $val = vr_money($val);
        if ($type === 'Checkbox') $val = in_array($val, ['1', 'true', 'on', 'بله'], true) ? $tick : '';
        $v[$k] = $val;
        if ($type === 'Money' && $f['words_var']) $v[$f['words_var']] = vr_num_words($val);
        // گزینه => متغیرِ تیک (مثلاً «شخصی» => plate_personal)
        foreach ($f['tick_map'] as $opt => $var) $v[$var] = ($visible && $val === $opt) ? $tick : '';
    }
    $ins = $form['insured'] ?? [];
    $pl = $form['plate'] ?? [];
    $v['name'] = trim((string)($ins['name'] ?? ''));
    $v['national_id'] = trim(p2e_digits((string)($ins['national_id'] ?? '')));
    $v['phone_bimeg'] = trim(p2e_digits((string)($ins['phone'] ?? '')));
    $v['addres_bimeg'] = trim((string)($ins['address'] ?? ''));
    $v['plate_part1'] = p2e_digits(trim((string)($pl['p1'] ?? '')));
    $v['plate_letter'] = trim((string)($pl['letter'] ?? ''));
    $v['plate_part2'] = p2e_digits(trim((string)($pl['p2'] ?? '')));
    $v['plate_part3'] = p2e_digits(trim((string)($pl['iran'] ?? '')));
    $v['plate_display'] = vr_plate_display($v['plate_part1'], $v['plate_letter'], $v['plate_part2'], $v['plate_part3']);
    $v['date_shamsi'] = $meta['date'];
    $v['date_shamsi_slash'] = str_replace('.', '/', $meta['date']);
    $v['issuer_name'] = $meta['issuer_name'] ?? '';
    $v['report_no'] = $meta['report_no'] ?? '';
    // اگر هیچ فیلدِ مبلغی «به حروف» تعریف نکرده، مثل برنامه‌ی ویندوزی arzesh_words از اولین مبلغ ساخته می‌شود
    if (!isset($v['arzesh_words'])) {
        foreach ($fields as $f) if ($f['field_type'] === 'Money' && ($v[$f['field_key']] ?? '') !== '') { $v['arzesh_words'] = vr_num_words($v[$f['field_key']]); break; }
    }
    return $v;
}

// ستون‌های قابل‌جستجوی رکورد، از روی «ستون اکسل» فیلدها
function vr_record_columns(array $fields, array $values) {
    $colMap = ['نوع وسیله' => 'vehicle_type', 'مدل/سال' => 'model_year', 'رنگ' => 'color', 'مورد استفاده' => 'usage_type',
               'شماره موتور' => 'engine_no', 'شماره شاسی' => 'chassis_no', 'ارزش وسیله' => 'car_value', 'بازدید کننده' => 'visitor_name'];
    $out = [];
    foreach ($fields as $f) {
        $col = $colMap[$f['excel_column'] ?? ''] ?? null;
        if (!$col || isset($out[$col])) continue;
        // ستون‌های جستجو با رقمِ لاتین ذخیره می‌شوند (در فرم رقم فارسی تایپ می‌شود)
        $val = $col === 'visitor_name' ? ($values[$f['field_key']] ?? '') : p2e_digits((string)($values[$f['field_key']] ?? ''));
        if ($col === 'car_value') $val = ($d = preg_replace('/\D/', '', (string)$val)) !== '' ? intval($d) : null;
        if ($val !== '' && $val !== null) $out[$col] = $val;
    }
    return $out;
}

// ---------------------------------------------------------------------
//  بایگانی و نام‌گذاری (طبقِ قراردادهای سایت)
//   Archive/بایگانی/بایگانی گزارشات بازدید/{سال}/{ماه}/{بیمه‌گر}/({تاریخ}) {پلاک} - {بیمه‌گذار}/
//     ({تاریخ})(گزارش بازدید خودرو) {پلاک}.pdf / .docx
//     ({تاریخ})(عکس‌های بازدید خودرو) {پلاک}/  و همان نام .zip
//   دفترِ گزارشات: Archive/بایگانی/بایگانی گزارشات بازدید/گزارشات_صادره.xlsx
// ---------------------------------------------------------------------
function vr_archive_root($siteRoot) { return archive_root($siteRoot) . '/بایگانی گزارشات بازدید'; }
function vr_trash_root($siteRoot) { return vr_archive_root($siteRoot) . '/حذف شده'; }

function vr_report_folder($siteRoot, $dateDot, $insurer, $plateDisplay, $insuredName) {
    [$jy, $jm] = array_map('intval', explode('.', $dateDot));
    $name = sanitize_folder_name(name_join(['(' . $dateDot . ') ' . (plate_for_filename($plateDisplay) ?: 'بدون‌پلاک'), $insuredName]));
    return vr_archive_root($siteRoot) . '/' . $jy . '/' . jalali_month_name($jm) . '/' . sanitize_folder_name(vr_insurer_fa($insurer)) . '/' . $name;
}
function vr_rel($siteRoot, $abs) { return $abs ? ltrim(str_replace($siteRoot, '', $abs), '/') : null; }
function vr_abs($siteRoot, $rel) { return $rel ? $siteRoot . '/' . ltrim($rel, '/') : null; }

// نامِ نسخه‌ی قبلی: «... (old).pdf»، «... (old 2).pdf»
function vr_old_name($absPath) {
    $ext = pathinfo($absPath, PATHINFO_EXTENSION);
    $base = substr($absPath, 0, -strlen($ext) - 1);
    $n = 1;
    do { $cand = $base . ($n > 1 ? " (old {$n})" : ' (old)') . '.' . $ext; $n++; } while (file_exists($cand));
    return $cand;
}

// شماره‌ی یکتا و پشت‌سرِهم برای هر سال؛ شمارنده جدا نگه داشته می‌شود تا شماره‌ی گزارشی که برای همیشه
// پاک شده هرگز دوباره داده نشود
function vr_next_report_no($pdo, $dateDot) {
    $jy = explode('.', $dateDot)[0];
    $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(report_no, ?) AS UNSIGNED)) FROM visit_reports WHERE report_no LIKE ?");
    $st->execute([strlen("VR{$jy}-") + 1, "VR{$jy}-%"]);
    $n = max(intval($st->fetchColumn()), intval(vr_setting($pdo, "report_no_last_{$jy}", 0))) + 1;
    vr_set_setting($pdo, "report_no_last_{$jy}", (string)$n);
    return sprintf('VR%s-%05d', $jy, $n);
}

// عکس‌ها: فشرده‌سازی، نام‌گذاری «(عکس ۱) پلاک.jpg» و ساختِ ZIP
function vr_photo_name($idx, $plateDisplay, $ext, $label = null) {
    $label = $label ?: 'عکس ' . $idx;
    return sanitize_folder_name('(' . $label . ') ' . ($plateDisplay ?: 'بدون‌پلاک')) . '.' . $ext;
}
function vr_rebuild_zip($siteRoot, $photosDir, $zipAbs) {
    if (is_file($zipAbs)) @unlink($zipAbs);
    if (!is_dir($photosDir)) return null;
    // عکس‌هایی که در ویرایش کنار گذاشته شده‌اند («(old)» در نامشان) در ZIP نمی‌آیند
    $files = array_values(array_filter(scandir($photosDir), fn($f) => is_file($photosDir . '/' . $f) && strpos($f, '(old') === false));
    if (!$files || !class_exists('ZipArchive')) return null;
    $z = new ZipArchive();
    if ($z->open($zipAbs, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;
    $inner = basename($photosDir);
    foreach ($files as $f) $z->addFile($photosDir . '/' . $f, $inner . '/' . $f);
    $z->close();
    return is_file($zipAbs) ? $zipAbs : null;
}

// ساختِ PDF و Word برای یک گزارش (در پوشه‌ی خودش)
function vr_render_files($pdo, $cat, array $values, $folderAbs, $dateDot, $plateDisplay) {
    [$layout, $assetDir] = vr_layout($pdo, $cat);
    if (!is_dir($folderAbs)) @mkdir($folderAbs, 0775, true);
    $pdf = $folderAbs . '/' . build_health_report_filename($dateDot, $plateDisplay, 'pdf');
    $docx = $folderAbs . '/' . build_health_report_filename($dateDot, $plateDisplay, 'docx');
    rpt_render_pdf($layout, $assetDir, $values, $pdf, vr_render_opts($pdo, $cat));
    $tpl = dirname(__DIR__) . '/' . ltrim($cat['template_path'], '/');
    $docxOk = rpt_fill_docx($tpl, $docx, $values, vr_render_opts($pdo, $cat));
    if (!is_file($pdf)) throw new RuntimeException('ساخت فایل PDF گزارش ممکن نشد.');
    return [$pdf, $docxOk ? $docx : null];
}

// ---------------------------------------------------------------------
//  دفترِ اکسلِ گزارشات (مثل گزارشات_صادره.xlsx برنامه‌ی ویندوزی، قفل‌شده)
// ---------------------------------------------------------------------
function vr_register_columns() {
    return ['ردیف', 'شماره گزارش', 'تاریخ گزارش', 'پلاک', 'نام بیمه‌گذار', 'کد ملی', 'تلفن', 'آدرس', 'نوع وسیله', 'مدل/سال', 'رنگ',
            'مورد استفاده', 'شماره موتور', 'شماره شاسی', 'ارزش وسیله', 'بازدید کننده', 'نوع گزارش', 'بیمه‌گر', 'کاربر صادر کننده',
            'زمان ثبت', 'نسخه', 'اتصال', 'مسیر پوشه'];
}
function vr_register_row($r, $i, $siteRoot) {
    $link = $r['health_inspection_id'] ? 'بازدید سلامت #' . $r['health_inspection_id'] : ($r['case_id'] ? 'درخواست کارکنان #' . $r['case_id'] : ($r['company_plate_id'] ? 'ردیف شرکتی #' . $r['company_plate_id'] : ''));
    return [$i, $r['report_no'], str_replace('.', '/', $r['report_date']), $r['plate_display'], $r['insured_name'], $r['national_id'], $r['phone'], $r['address'],
            $r['vehicle_type'], $r['model_year'], $r['color'], $r['usage_type'], $r['engine_no'], $r['chassis_no'],
            $r['car_value'] ? number_format((float)$r['car_value']) : '', $r['visitor_name'], $r['category_name'], vr_insurer_fa($r['insurer']),
            $r['issuer_name'], jalali_from_gregorian_ts_dotted(strtotime($r['created_at'])) . ' ' . date('H:i', strtotime($r['created_at'])),
            $r['version'], $link, $r['folder_path']];
}
function vr_write_register($pdo, $siteRoot) {
    if (!function_exists('xlsx_build')) require_once __DIR__ . '/_xlsx_writer.php';
    $rows = $pdo->query("SELECT * FROM visit_reports WHERE status = 'ACTIVE' ORDER BY id")->fetchAll();
    $out = []; $i = 0;
    foreach ($rows as $r) $out[] = vr_register_row($r, ++$i, $siteRoot);
    $tmp = xlsx_build(vr_register_columns(), $out, 'گزارشات صادره', [0, 20], vr_setting($pdo, 'report_excel_password', '12345'));
    if (!$tmp) return false;
    $root = vr_archive_root($siteRoot);
    if (!is_dir($root)) @mkdir($root, 0775, true);
    $dest = $root . '/گزارشات_صادره.xlsx';
    $ok = @copy($tmp, $dest);
    @unlink($tmp);
    return $ok;
}

// ---------------------------------------------------------------------
//  پارسرِ PDF (الگوریتم‌های استخراجِ پایتونی - همان فایل‌های .py برنامه‌ی ویندوزی)
// ---------------------------------------------------------------------
function vr_python_path($pdo) { return vr_setting($pdo, 'report_python_path', VR_DEFAULT_PYTHON); }
// پوشه‌ی خانگیِ کاربرِ هاست (مثلاً /home/besiteir). محیطِ پایتونِ سی‌پنل (CloudLinux) موقعِ اجرا متغیرِ HOME را
// لازم دارد و PHP آن را به برنامه‌ها نمی‌دهد؛ بدونش یک Traceback بی‌ضرر چاپ می‌شود.
function vr_home_dir($pdo) {
    $h = getenv('HOME');
    if ($h && is_dir($h)) return $h;
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) { $pw = @posix_getpwuid(posix_geteuid()); if (!empty($pw['dir']) && is_dir($pw['dir'])) return $pw['dir']; }
    if (preg_match('#^(/home\d*/[^/]+)/#', vr_python_path($pdo) . '/', $m) && is_dir($m[1])) return $m[1];
    if (preg_match('#^(/home\d*/[^/]+)/#', __DIR__ . '/', $m)) return $m[1];
    return sys_get_temp_dir();
}
function vr_run_parser($pdo, $pdfAbs, $parserAbs = null) {
    $py = vr_python_path($pdo);
    $runner = dirname(__DIR__) . '/report_assets/parser_runner.py';
    if (!function_exists('proc_open')) return ['ok' => false, 'error' => 'اجرای پایتون روی این سرور مجاز نیست (proc_open غیرفعال است).'];
    if (!is_file($py) && strpos($py, '/') !== false) return ['ok' => false, 'error' => "پایتون در مسیرِ تنظیم‌شده پیدا نشد: {$py} (از تنظیمات گزارش اصلاح کنید)."];
    $cmd = [$py, $runner, $pdfAbs];
    if ($parserAbs) $cmd[] = $parserAbs;
    $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => vr_home_dir($pdo), 'PYTHONWARNINGS' => 'ignore'];
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'اجرای پایتون ممکن نشد.'];
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $start = time();
    while (true) {
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        $s = proc_get_status($proc);
        if (!$s['running']) break;
        if (time() - $start > 45) { proc_terminate($proc, 9); return ['ok' => false, 'error' => 'استخراج بیش از حد طول کشید (بیش از ۴۵ ثانیه).']; }
        usleep(60000);
    }
    $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $line = trim((string)substr($out, strrpos("\n" . trim($out), "\n")));
    $j = json_decode($line, true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'خروجیِ پایتون خوانده نشد.', 'debug' => mb_substr(trim($err ?: $out), -1500)];
    return $j;
}

// خروجیِ پارسر => مقدارِ فیلدهای فرم (نام فیلد، یا یکی از «کلیدهای پارسر»ِ تعریف‌شده برای آن فیلد)
function vr_map_parsed(array $data, array $fields, array $visitors) {
    $pick = function (array $keys) use ($data) {
        foreach ($keys as $k) { $k = trim($k); if ($k !== '' && isset($data[$k]) && trim((string)$data[$k]) !== '') return trim((string)$data[$k]); }
        return '';
    };
    $out = ['plate' => ['p1' => $pick(['plate_part1']), 'letter' => $pick(['plate_letter']), 'p2' => $pick(['plate_part2']), 'iran' => $pick(['plate_part3'])],
            'insured' => ['name' => $pick(['name', 'bime_gozar']), 'national_id' => $pick(['national_id', 'kod_meli']),
                          'phone' => $pick(['phone_bimeg', 'phone_number', 'phone']), 'address' => $pick(['addres_bimeg', 'address'])],
            'fields' => []];
    foreach ($fields as $f) {
        if (in_array($f['field_type'], ['PartsStatus', 'DamageList'], true)) continue;
        $keys = array_merge([$f['field_key']], array_filter(array_map('trim', explode(',', (string)$f['parser_keys']))));
        $val = $pick($keys);
        if ($val === '') continue;
        if ($f['field_type'] === 'Visitor') {
            foreach ($visitors as $vis) if (mb_strpos($vis['name'], $val) !== false || mb_strpos($val, $vis['name']) !== false) { $val = $vis['name']; break; }
        }
        if ($f['field_type'] === 'Money') $val = vr_money($val);
        $out['fields'][$f['field_key']] = $val;
    }
    return $out;
}

// ---------------------------------------------------------------------
//  اتصال به بازدید سلامت / درخواستِ کارکنان / ردیفِ شرکتی
// ---------------------------------------------------------------------
function vr_link_health($pdo, $siteRoot, $report, $inspId, $userId) {
    $pdfAbs = vr_abs($siteRoot, $report['pdf_path']);
    $r = health_attach_report_and_finalize($pdo, $siteRoot, intval($inspId), ['local' => $pdfAbs], $userId);
    if (!$r['ok']) return $r;
    $st = $pdo->prepare("SELECT case_id FROM health_inspections WHERE id = ?");
    $st->execute([$inspId]);
    $caseId = $st->fetchColumn() ?: null;
    $pdo->prepare("UPDATE visit_reports SET health_inspection_id = ?, case_id = COALESCE(?, case_id), linked_at = NOW(), linked_by = ? WHERE id = ?")
        ->execute([$inspId, $caseId, $userId, $report['id']]);
    return $r + ['target' => 'health'];
}

function vr_link_case($pdo, $siteRoot, $report, $caseId, $userId) {
    $st = $pdo->prepare("SELECT * FROM policy_cases WHERE id = ?");
    $st->execute([$caseId]);
    $case = $st->fetch();
    if (!$case) return ['ok' => false, 'error' => 'درخواستِ کارکنان پیدا نشد.'];
    // اگر این درخواست بازدید سلامتی دارد که منتظرِ گزارش است، گزارش همان‌جا می‌نشیند (و بازدید تایید نهایی می‌شود)
    $h = $pdo->prepare("SELECT id FROM health_inspections WHERE case_id = ? AND status IN ('PHOTOS_APPROVED', 'APPROVED') ORDER BY id DESC LIMIT 1");
    $h->execute([$caseId]);
    if ($hid = $h->fetchColumn()) return vr_link_health($pdo, $siteRoot, $report, $hid, $userId);
    $dir = resolve_case_folder($pdo, $siteRoot, $case);
    if (!$dir || strpos($dir, $siteRoot . '/') !== 0) return ['ok' => false, 'error' => 'پوشه‌ی بایگانیِ این درخواست پیدا نشد.'];
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $target = $dir . '/' . build_health_report_filename($report['report_date'], $case['plate'] ?: $report['plate_display'], 'pdf');
    if (is_file($target)) @rename($target, vr_old_name($target));
    if (!@copy(vr_abs($siteRoot, $report['pdf_path']), $target)) return ['ok' => false, 'error' => 'کپیِ فایل گزارش در پوشه‌ی درخواست ممکن نشد.'];
    $zip = vr_abs($siteRoot, $report['zip_path']);
    if ($zip && is_file($zip)) @copy($zip, $dir . '/' . basename($zip));
    $pdo->prepare("UPDATE visit_reports SET case_id = ?, linked_at = NOW(), linked_by = ? WHERE id = ?")->execute([$caseId, $userId, $report['id']]);
    return ['ok' => true, 'target' => 'case', 'report' => vr_rel($siteRoot, $target)];
}

function vr_link_company_plate($pdo, $siteRoot, $report, $plateId, $userId) {
    $st = $pdo->prepare("SELECT crp.*, cr.company_id FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id WHERE crp.id = ?");
    $st->execute([$plateId]);
    $plate = $st->fetch();
    if (!$plate) return ['ok' => false, 'error' => 'ردیفِ شرکتی پیدا نشد.'];
    $dir = ensure_plate_folder($pdo, $siteRoot, $plateId);
    if (!$dir) return ['ok' => false, 'error' => 'پوشه‌ی این ردیف ساخته نشد.'];
    // گزارشِ قبلیِ همین ردیف (اگر بود) با (old) کنار گذاشته می‌شود
    $old = $pdo->prepare("SELECT id, file_path FROM company_documents WHERE plate_id = ? AND doc_type = 'health_report' AND status = 'ASSIGNED'");
    $old->execute([$plateId]);
    foreach ($old->fetchAll() as $o) {
        $abs = vr_abs($siteRoot, $o['file_path']);
        if ($abs && is_file($abs)) { $new = vr_old_name($abs); @rename($abs, $new); $pdo->prepare("UPDATE company_documents SET file_path = ?, status = 'REPLACED' WHERE id = ?")->execute([vr_rel($siteRoot, $new), $o['id']]); }
    }
    $tmpDest = unique_dest_path($dir . '/' . basename($report['pdf_path']));
    if (!@copy(vr_abs($siteRoot, $report['pdf_path']), $tmpDest)) return ['ok' => false, 'error' => 'کپیِ فایل گزارش در پوشه‌ی ردیف ممکن نشد.'];
    $pdo->prepare("INSERT INTO company_documents (company_id, request_id, plate_id, file_path, orig_name, file_kind, doc_type, status, assigned_by, assigned_at)
                   VALUES (?, ?, ?, ?, ?, 'SUPPORTING_DOC', 'health_report', 'ASSIGNED', ?, NOW())")
        ->execute([$plate['company_id'], $plate['request_id'], $plateId, vr_rel($siteRoot, $tmpDest), basename($report['pdf_path']), $userId]);
    $docId = $pdo->lastInsertId();
    $rel = company_place_document($pdo, $siteRoot, $docId, $plateId, 'health_report');
    company_sync_plate_status($pdo, $plateId);
    $pdo->prepare("UPDATE visit_reports SET company_plate_id = ?, linked_at = NOW(), linked_by = ? WHERE id = ?")->execute([$plateId, $userId, $report['id']]);
    return ['ok' => true, 'target' => 'company', 'report' => $rel];
}

// بعد از ویرایشِ گزارش، نسخه‌ی تازه به همان جایی که وصل بود هم می‌رود
function vr_relink($pdo, $siteRoot, $report, $userId) {
    if ($report['health_inspection_id']) return vr_link_health($pdo, $siteRoot, $report, $report['health_inspection_id'], $userId);
    if ($report['company_plate_id']) return vr_link_company_plate($pdo, $siteRoot, $report, $report['company_plate_id'], $userId);
    if ($report['case_id']) return vr_link_case($pdo, $siteRoot, $report, $report['case_id'], $userId);
    return null;
}

// پیشنهادِ اتصال: درخواست‌ها/بازدیدهایی با همین پلاک یا همین شماره شاسی
function vr_match_candidates($pdo, $report) {
    $core = plate_core($report['plate_display'] ?? '');
    $chassis = strtoupper(trim((string)($report['chassis_no'] ?? '')));
    $out = [];
    // بازدیدهای سلامت (کارکنان یا پلاکِ آزاد)
    $st = $pdo->query("SELECT hi.id, hi.status, hi.inspection_number, hi.created_at, COALESCE(pc.plate, hi.free_plate) AS plate, pc.insured_name, pc.unique_code,
                              COALESCE(pc.chassis_num, pc.vin) AS chassis, p.full_name
                         FROM health_inspections hi LEFT JOIN policy_cases pc ON pc.id = hi.case_id LEFT JOIN persons p ON p.id = COALESCE(pc.person_id, hi.person_id)
                        ORDER BY hi.id DESC LIMIT 800");
    foreach ($st->fetchAll() as $h) {
        $m = ($core && plate_core($h['plate']) === $core) || ($chassis && strtoupper((string)$h['chassis']) === $chassis);
        if ($m) $out[] = ['type' => 'health', 'id' => intval($h['id']), 'title' => 'بازدید سلامت شماره ' . $h['inspection_number'] . ($h['unique_code'] ? ' · ' . $h['unique_code'] : ''),
                          'subtitle' => trim(($h['insured_name'] ?: $h['full_name']) . ' · ' . $h['plate']), 'status' => $h['status'],
                          'ready' => in_array($h['status'], ['PHOTOS_APPROVED', 'APPROVED'], true)];
    }
    // درخواست‌های کارکنان
    $st = $pdo->query("SELECT id, unique_code, plate, insured_name, status, COALESCE(chassis_num, vin) AS chassis FROM policy_cases WHERE status NOT IN ('WITHDRAWN') ORDER BY id DESC LIMIT 1500");
    foreach ($st->fetchAll() as $c) {
        $m = ($core && plate_core($c['plate']) === $core) || ($chassis && strtoupper((string)$c['chassis']) === $chassis);
        if ($m) $out[] = ['type' => 'case', 'id' => intval($c['id']), 'title' => 'درخواست کارکنان ' . $c['unique_code'], 'subtitle' => trim($c['insured_name'] . ' · ' . $c['plate']),
                          'status' => $c['status'], 'ready' => true];
    }
    // ردیف‌های شرکتی
    $st = $pdo->query("SELECT crp.id, crp.plate_p1, crp.plate_p2, crp.plate_letter, crp.plate_p4, crp.chassis_no, crp.status, crp.request_id, c.name AS company_name
                         FROM company_request_plates crp JOIN company_requests cr ON cr.id = crp.request_id JOIN companies c ON c.id = cr.company_id
                        WHERE crp.status <> 'CANCELLED' ORDER BY crp.id DESC LIMIT 3000");
    foreach ($st->fetchAll() as $p) {
        $disp = company_plate_display($p['plate_p1'], $p['plate_p2'], $p['plate_letter'], $p['plate_p4']);
        $m = ($core && $disp && plate_core($disp) === $core) || ($chassis && strtoupper((string)$p['chassis_no']) === $chassis);
        if ($m) $out[] = ['type' => 'company', 'id' => intval($p['id']), 'title' => 'ردیف شرکتی · ' . $p['company_name'] . ' · درخواست #' . $p['request_id'],
                          'subtitle' => $disp ?: $p['chassis_no'], 'status' => $p['status'], 'ready' => true];
    }
    return $out;
}

// ---------------------------------------------------------------------
//  کمکی‌های API
// ---------------------------------------------------------------------
// «68ایران - 711 د 16» => ['p1' => 16, 'letter' => د, 'p2' => 711, 'iran' => 68]
function vr_plate_split($plate) {
    $s = p2e_digits(str_replace(["\xE2\x80\x8E", "\xE2\x80\x8F"], '', (string)$plate));
    if (preg_match('/(\d{2})\s*ایران\s*-?\s*(\d{3})\s*([^\d\s-]+)\s*(\d{2})/u', $s, $m)) return ['p1' => $m[4], 'letter' => $m[3], 'p2' => $m[2], 'iran' => $m[1]];
    if (preg_match('/(\d{2})\s*([^\d\s-]+)\s*(\d{3})\s*-?\s*ایران\s*(\d{2})/u', $s, $m)) return ['p1' => $m[1], 'letter' => $m[2], 'p2' => $m[3], 'iran' => $m[4]];
    return ['p1' => '', 'letter' => '', 'p2' => '', 'iran' => ''];
}

// مدیر کل همه‌ی گزارش‌ها را می‌بیند؛ بقیه فقط گزارش‌هایی که خودشان صادر کرده‌اند (و نوعش هنوز برایشان مجاز است)
function vr_report_visible($pdo, $r, $user) {
    if (!$r || !$user) return false;
    if ($user['is_admin']) return true;
    if (intval($r['issuer_user_id']) !== $user['id'] || $r['status'] !== 'ACTIVE') return false;
    $cat = vr_category($pdo, $r['category_id']);
    return $cat && vr_can_issue($cat, $user);
}

function vr_audit($pdo, $userId, $action, $targetId, $note) {
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, target_table, target_id, new_value) VALUES (?, ?, 'visit_reports', ?, ?)")
            ->execute([$userId, mb_substr($action, 0, 20), intval($targetId), mb_substr((string)$note, 0, 2000)]);
    } catch (Throwable $e) { error_log('[vr_audit] ' . $e->getMessage()); }
}
function vr_audit_actions() {
    return ['VR_ISSUE' => 'صدور گزارش', 'VR_EDIT' => 'ویرایش گزارش', 'VR_DELETE' => 'حذف گزارش', 'VR_RESTORE' => 'بازگردانی گزارش',
            'VR_LINK' => 'اتصال', 'VR_UNLINK' => 'قطع اتصال', 'VR_PARSE' => 'استخراج از PDF', 'VR_DOWNLOAD' => 'دانلود', 'VR_SETTINGS' => 'تغییر تنظیمات'];
}

// پوشه‌ای که هنوز وجود ندارد: «...»، «... (2)»، ...
function vr_unique_dir($dir) {
    if (!file_exists($dir)) return $dir;
    $n = 2;
    while (file_exists("{$dir} ({$n})")) $n++;
    return "{$dir} ({$n})";
}

// متغیرهایی که فیلدهای یک نوع گزارش برای قالب می‌سازند (برای مقایسه با {{ }}های قالب)
function vr_expected_vars($cat, array $fields) {
    $okS = $cat['ok_suffix'] ?: 's'; $badS = $cat['bad_suffix'] ?: 'k';
    $v = ['name', 'national_id', 'phone_bimeg', 'addres_bimeg', 'plate_part1', 'plate_letter', 'plate_part2', 'plate_part3', 'plate_display',
          'date_shamsi', 'date_shamsi_slash', 'issuer_name', 'report_no', 'arzesh_words'];
    foreach ($fields as $f) {
        if ($f['field_type'] === 'PartsStatus') { foreach (vr_parts_list($f) as $p) { $v[] = "{$p['id']}_{$okS}"; $v[] = "{$p['id']}_{$badS}"; $v[] = "{$p['id']}_{$okS}_t"; $v[] = "{$p['id']}_{$badS}_t"; $v[] = "{$p['id']}_t"; } continue; }
        if ($f['field_type'] === 'DamageList') { $n = max(1, intval($f['options'] ?: 5)); for ($i = 1; $i <= $n; $i++) { $v[] = "damage_location_{$i}"; $v[] = "damage_description_{$i}"; } continue; }
        $v[] = $f['field_key'];
        if ($f['words_var']) $v[] = $f['words_var'];
        foreach ($f['tick_map'] as $var) $v[] = $var;
    }
    return array_values(array_unique($v));
}

// در «نمونه»ی ویرایشگر و پیش‌نمایش، همه‌ی تیک‌ها زده می‌شوند (سالم و خسارتیِ همه‌ی ردیف‌ها، همه‌ی گزینه‌های
// تیک‌دار و چک‌باکس‌ها) تا جای همه‌ی تیک‌ها روی فرم دیده و تنظیم شود
function vr_sample_all_ticks($cat, array $fields, array $values) {
    $tick = $cat['tick_mark'] ?: '✔';
    $okS = $cat['ok_suffix'] ?: 's'; $badS = $cat['bad_suffix'] ?: 'k';
    foreach ($fields as $f) {
        if ($f['field_type'] === 'PartsStatus') {
            foreach (vr_parts_list($f) as $p) { $values["{$p['id']}_{$okS}"] = $tick; $values["{$p['id']}_{$badS}"] = $tick; }
            continue;
        }
        if ($f['field_type'] === 'Checkbox') $values[$f['field_key']] = $tick;
        foreach ($f['tick_map'] as $var) $values[$var] = $tick;
    }
    return $values;
}

// فرمِ نمونه برای پیش‌نمایشِ قالب در تنظیمات
function vr_sample_form(array $fields) {
    $form = ['plate' => ['p1' => '12', 'letter' => 'ب', 'p2' => '345', 'iran' => '67'],
             'insured' => ['name' => 'نام بیمه‌گذار نمونه', 'national_id' => '0012345678', 'phone' => '09120000000', 'address' => 'تهران، خیابان نمونه، پلاک ۱'],
             'fields' => [], 'parts' => [], 'damages' => []];
    foreach ($fields as $f) {
        $k = $f['field_key'];
        switch ($f['field_type']) {
            case 'Money': $form['fields'][$k] = '1250000000'; break;
            case 'Number': $form['fields'][$k] = '4'; break;
            case 'Time': $form['fields'][$k] = '10:30'; break;
            case 'Date': $form['fields'][$k] = '1405/01/01'; break;
            case 'Checkbox': $form['fields'][$k] = '1'; break;
            case 'Visitor': $form['fields'][$k] = 'بازدیدکننده نمونه'; break;
            case 'Combobox': $form['fields'][$k] = vr_combo_options($f)[0] ?? ''; break;
            case 'PartsStatus': foreach (vr_parts_list($f) as $i => $p) { $form['parts'][$p['id']] = $i % 4 === 3 ? 'k' : 's'; if ($i % 4 === 3) $form['part_notes'][$p['id']] = 'خط و خش'; } break;
            case 'DamageList': $form['damages'] = [['location' => 'گلگیر جلو راست', 'description' => 'خط و خش'], ['location' => 'سپر عقب', 'description' => 'فرورفتگی']]; break;
            default:
                // نمونه‌ی واقع‌گرا برای ستون‌های شناخته‌شده، وگرنه برچسبِ فیلد
                $samples = ['نوع وسیله' => 'سواری پژو ۲۰۶ تیپ ۵', 'سیستم/برند' => 'ایران خودرو', 'رنگ' => 'سفید روغنی', 'مدل/سال' => '1401',
                            'شماره شاسی' => 'NAAP13FE9KJ123456', 'شماره موتور' => '165A0123456', 'مورد استفاده' => 'شخصی', 'سیلندر' => '4', 'ظرفیت' => '5 نفر'];
                $form['fields'][$k] = $samples[$f['excel_column'] ?? ''] ?? ($f['max_chars'] && $f['max_chars'] <= 4 ? str_repeat('9', min(4, $f['max_chars'])) : $f['label']);
        }
    }
    return $form;
}

// ردیفِ فهرست برای کلاینت
function vr_row_out($r) {
    return ['id' => intval($r['id']), 'report_no' => $r['report_no'], 'category_id' => intval($r['category_id']), 'category_name' => $r['category_name'],
            'insurer' => $r['insurer'], 'insurer_fa' => vr_insurer_fa($r['insurer']), 'status' => $r['status'], 'report_date' => $r['report_date'],
            'plate_display' => $r['plate_display'], 'insured_name' => $r['insured_name'], 'national_id' => $r['national_id'], 'phone' => $r['phone'],
            'vehicle_type' => $r['vehicle_type'], 'model_year' => $r['model_year'], 'chassis_no' => $r['chassis_no'], 'car_value' => $r['car_value'],
            'visitor_name' => $r['visitor_name'], 'issuer_name' => $r['issuer_name'], 'issuer_user_id' => intval($r['issuer_user_id']),
            'photos_count' => intval($r['photos_count']), 'version' => intval($r['version']),
            'health_inspection_id' => $r['health_inspection_id'] ? intval($r['health_inspection_id']) : null,
            'case_id' => $r['case_id'] ? intval($r['case_id']) : null, 'company_plate_id' => $r['company_plate_id'] ? intval($r['company_plate_id']) : null,
            'created_at' => $r['created_at'], 'created_jalali' => jalali_from_gregorian_ts_dotted(strtotime($r['created_at'])) . ' ' . date('H:i', strtotime($r['created_at'])),
            'updated_at' => $r['updated_at'], 'has_docx' => !empty($r['docx_path']), 'has_zip' => !empty($r['zip_path'])];
}
