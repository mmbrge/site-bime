<?php
// فایل: api/visit_report_settings.php
// «تنظیمات گزارش» (فقط مدیر کل) - از داخلِ api/visit_reports.php برای action‌های set_* صدا زده می‌شود.
// هر چیزی که در برنامه‌ی ویندوزی در fields_config.xlsx و پوشه‌ی Config بود اینجا قابل‌مدیریت است:
//   انواع گزارش (نام، بیمه‌گر، برچسب‌ها و پسوندهای سالم/خسارتی، علامتِ تیک، فعال/غیرفعال، ترتیب، دسترسی)
//   قالبِ Word (آپلود، بررسیِ متغیرها، پیش‌نمایش، جابه‌جاییِ دقیقِ کادرها)، الگوریتمِ استخراج (.py)
//   فیلدها (نوع، گزینه‌ها، حداکثر کاراکتر، ستونِ اکسل، کلیدهای پارسر، بخش، شرطِ نمایش، تیک‌ها، به‌حروف، ...)
//   بازدیدکننده‌ها و بیمه‌گذاران (برای هر نوع گزارش)، قلم‌ها، مسیرِ پایتون، رمزِ اکسل، تاریخچه
if (!isset($pdo, $user) || !$user['is_admin']) exit;

$ROLES = ['ADMIN' => 'مدیر کل', 'OPERATOR' => 'کارشناس صدور', 'FINANCE' => 'کارشناس مالی', 'COMPANY_LIAISON' => 'همکار بیمه با ما', 'PARSIAN' => 'کاربر پارسیان'];

function vrs_template_info($pdo, $cat, $siteRoot) {
    $tpl = $cat['template_path'] ? $siteRoot . '/' . ltrim($cat['template_path'], '/') : null;
    $exists = $tpl && is_file($tpl);
    $vars = $exists ? rpt_template_vars($tpl) : [];
    $fields = vr_fields($pdo, $cat['id']);
    $expected = vr_expected_vars($cat, $fields);
    // فیلدهایی که خودشان لازم نیست در قالب باشند: گزینه‌هایی که با «تیک» نشان داده می‌شوند و تیک‌هایی که فقط شرطِ نمایشِ فیلدهای دیگرند
    $optional = ['plate_display', 'date_shamsi', 'date_shamsi_slash', 'issuer_name', 'report_no', 'arzesh_words'];
    foreach ($fields as $f) {
        if ($f['tick_map']) $optional[] = $f['field_key'];
        // متغیرهای توضیحِ قطعه (..._t) اختیاری‌اند؛ هر کدام لازم بود در قالب گذاشته می‌شود
        if ($f['field_type'] === 'PartsStatus') foreach ($expected as $ev) if (substr($ev, -2) === '_t') $optional[] = $ev;
        foreach ($fields as $g) if ($g['show_if'] && preg_match('/^\s*' . preg_quote($f['field_key'], '/') . '\s*!?=/', $g['show_if'])) $optional[] = $f['field_key'];
    }
    return ['exists' => $exists, 'orig_name' => $cat['template_orig_name'], 'vars' => $vars,
            'unfilled' => array_values(array_diff($vars, $expected)),       // در قالب هست ولی هیچ فیلدی پُرش نمی‌کند
            'unused' => array_values(array_diff($expected, $vars, $optional)), // فیلد هست ولی جایی در قالب ندارد
            'updated_at' => $cat['layout_updated_at']];
}
function vrs_parser_info($cat, $siteRoot) {
    $p = $cat['parser_path'] ? $siteRoot . '/' . ltrim($cat['parser_path'], '/') : null;
    return ['exists' => $p && is_file($p), 'orig_name' => $cat['parser_orig_name'], 'path' => $cat['parser_path']];
}
function vrs_cat_out($pdo, $c, $siteRoot) {
    $fields = vr_fields($pdo, $c['id']);
    foreach ($fields as &$f) $f['tick_map'] = $f['tick_map'] ? json_encode($f['tick_map'], JSON_UNESCAPED_UNICODE) : '';
    unset($f);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM visit_reports WHERE category_id = ?");
    $cnt->execute([$c['id']]);
    return ['id' => intval($c['id']), 'name' => $c['name'], 'insurer' => $c['insurer'], 'ok_suffix' => $c['ok_suffix'], 'bad_suffix' => $c['bad_suffix'],
            'ok_label' => $c['ok_label'], 'bad_label' => $c['bad_label'], 'tick_mark' => $c['tick_mark'], 'is_active' => intval($c['is_active']),
            'sort_order' => intval($c['sort_order']), 'access' => vr_access($c), 'options' => json_decode($c['options_json'] ?? '', true) ?: new stdClass(),
            'template' => vrs_template_info($pdo, $c, $siteRoot), 'parser' => vrs_parser_info($c, $siteRoot), 'fields' => $fields, 'reports' => intval($cnt->fetchColumn())];
}
function vrs_cat($pdo, $id) {
    $c = vr_category($pdo, $id);
    if (!$c) vr_fail('نوع گزارش پیدا نشد.');
    return $c;
}
function vrs_audit($pdo, $user, $note) { vr_audit($pdo, $user['id'], 'VR_SETTINGS', 0, $note); }
function vrs_upload($key, array $exts) {
    $f = $_FILES[$key] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) vr_fail('فایل دریافت نشد (شاید حجمش زیاد است).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) vr_fail('پسوندِ فایل باید ' . implode(' یا ', $exts) . ' باشد.');
    return $f + ['ext' => $ext];
}
function vrs_set_categories($pdo, $table, $col, $id, $cats) {
    $pdo->prepare("DELETE FROM $table WHERE $col = ?")->execute([$id]);
    foreach (array_unique(array_map('intval', (array)$cats)) as $c) if ($c > 0) $pdo->prepare("INSERT INTO $table ($col, category_id) VALUES (?, ?)")->execute([$id, $c]);
}
// «شخصی=plate_personal; عمومی=plate_public» یا JSON => آرایه
function vrs_parse_tick_map($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    $j = json_decode($s, true);
    if (is_array($j)) $map = $j;
    else {
        $map = [];
        foreach (preg_split('/[;\n]+/u', $s) as $pair) {
            if (strpos($pair, '=') === false) continue;
            [$k, $v] = array_map('trim', explode('=', $pair, 2));
            if ($k !== '' && $v !== '') $map[$k] = $v;
        }
    }
    foreach ($map as $k => $v) if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string)$v)) vr_fail("نامِ متغیرِ تیک برای «{$k}» فقط باید حروف و عدد لاتین و _ باشد.");
    return $map ? json_encode($map, JSON_UNESCAPED_UNICODE) : null;
}

$a = $action;

if ($a === 'set_bootstrap') {
    $cats = array_map(fn($c) => vrs_cat_out($pdo, $c, $siteRoot), $pdo->query("SELECT * FROM report_categories ORDER BY sort_order, id")->fetchAll());
    $visitors = [];
    foreach ($pdo->query("SELECT v.*, GROUP_CONCAT(vc.category_id) AS cats FROM report_visitors v LEFT JOIN report_visitor_categories vc ON vc.visitor_id = v.id GROUP BY v.id ORDER BY v.is_active DESC, v.name")->fetchAll() as $v)
        $visitors[] = ['id' => intval($v['id']), 'name' => $v['name'], 'is_active' => intval($v['is_active']), 'categories' => $v['cats'] ? array_map('intval', explode(',', $v['cats'])) : []];
    $insureds = [];
    foreach ($pdo->query("SELECT i.*, GROUP_CONCAT(ic.category_id) AS cats FROM report_insureds i LEFT JOIN report_insured_categories ic ON ic.insured_id = i.id GROUP BY i.id ORDER BY i.is_active DESC, i.name")->fetchAll() as $v)
        $insureds[] = ['id' => intval($v['id']), 'name' => $v['name'], 'national_id' => $v['national_id'], 'phone' => $v['phone'], 'address' => $v['address'], 'source' => $v['source'],
                       'source_id' => $v['source_id'] ? intval($v['source_id']) : null, 'is_active' => intval($v['is_active']), 'categories' => $v['cats'] ? array_map('intval', explode(',', $v['cats'])) : []];
    $fonts = $pdo->query("SELECT id, word_name, family, regular_file, bold_file, created_at FROM report_fonts ORDER BY word_name")->fetchAll();
    $users = $pdo->query("SELECT id, full_name, role FROM users" . (auth_schema_ready($pdo) ? " WHERE COALESCE(is_deleted, 0) = 0" : '') . " ORDER BY full_name")->fetchAll();
    // قلم‌هایی که قالب‌ها استفاده کرده‌اند (تا مدیر بداند کدام را آپلود کند)
    $wordFonts = [];
    foreach ($pdo->query("SELECT layout_json FROM report_categories WHERE layout_json IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $lj) {
        foreach ((json_decode($lj, true)['items'] ?? []) as $it) foreach ($it['paras'] ?? [] as $p) foreach ($p['runs'] ?? [] as $r) if (!empty($r['font'])) $wordFonts[$r['font']] = true;
    }
    vr_out(['ok' => true, 'categories' => $cats, 'visitors' => $visitors, 'insureds' => $insureds, 'fonts' => $fonts, 'word_fonts' => array_keys($wordFonts),
            'users' => $users, 'roles' => $ROLES, 'insurers' => vr_insurers(), 'field_types' => vr_field_types(), 'excel_columns' => vr_excel_columns(),
            'settings' => ['python_path' => vr_python_path($pdo), 'excel_password' => vr_setting($pdo, 'report_excel_password', '12345')],
            'archive_path' => ltrim(str_replace(archive_root($siteRoot), '', vr_archive_root($siteRoot)), '/')]);
}

// ---- انواع گزارش ----
if ($a === 'set_cat_save') {
    $id = intval($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') vr_fail('نام نوع گزارش را وارد کنید.');
    $ins = array_key_exists($data['insurer'] ?? '', vr_insurers()) ? $data['insurer'] : 'OTHER';
    $suffix = fn($v, $d) => preg_match('/^[A-Za-z0-9]{1,6}$/', (string)$v) ? $v : $d;
    $okS = $suffix($data['ok_suffix'] ?? '', 's'); $badS = $suffix($data['bad_suffix'] ?? '', 'k');
    if ($okS === $badS) vr_fail('پسوندِ «سالم» و «خسارتی» نباید یکی باشد.');
    $access = ['roles' => array_values(array_intersect(array_keys($ROLES), (array)($data['access']['roles'] ?? []))),
               'users' => array_values(array_unique(array_map('intval', (array)($data['access']['users'] ?? []))))];
    $optsIn = (array)($data['options'] ?? []);
    $opts = ['font_scale' => max(0.5, min(1.5, floatval($optsIn['font_scale'] ?? 1) ?: 1)), 'line_height' => max(0.8, min(2, floatval($optsIn['line_height'] ?? 1.1) ?: 1.1)),
             'description' => mb_substr(trim((string)($optsIn['description'] ?? '')), 0, 500)];
    $cols = ['name' => $name, 'insurer' => $ins, 'ok_suffix' => $okS, 'bad_suffix' => $badS,
             'ok_label' => mb_substr(trim((string)($data['ok_label'] ?? '')) ?: 'سالم', 0, 40), 'bad_label' => mb_substr(trim((string)($data['bad_label'] ?? '')) ?: 'خسارتی', 0, 40),
             'tick_mark' => mb_substr(trim((string)($data['tick_mark'] ?? '')) ?: '✔', 0, 4), 'is_active' => empty($data['is_active']) ? 0 : 1,
             'sort_order' => intval($data['sort_order'] ?? 0), 'access_json' => json_encode($access), 'options_json' => json_encode($opts, JSON_UNESCAPED_UNICODE)];
    if ($id) {
        vrs_cat($pdo, $id);
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
        $pdo->prepare("UPDATE report_categories SET $set, updated_at = NOW() WHERE id = ?")->execute([...array_values($cols), $id]);
    } else {
        $pdo->prepare("INSERT INTO report_categories (`" . implode('`, `', array_keys($cols)) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")->execute(array_values($cols));
        $id = intval($pdo->lastInsertId());
    }
    vrs_audit($pdo, $user, "نوع گزارش «{$name}» ذخیره شد");
    vr_out(['ok' => true, 'category' => vrs_cat_out($pdo, vr_category($pdo, $id), $siteRoot)]);
}

if ($a === 'set_cat_duplicate') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    $cols = ['name' => $c['name'] . ' (کپی)', 'insurer' => $c['insurer'], 'template_path' => null, 'template_orig_name' => $c['template_orig_name'],
             'parser_path' => $c['parser_path'], 'parser_orig_name' => $c['parser_orig_name'], 'ok_suffix' => $c['ok_suffix'], 'bad_suffix' => $c['bad_suffix'],
             'ok_label' => $c['ok_label'], 'bad_label' => $c['bad_label'], 'tick_mark' => $c['tick_mark'], 'access_json' => json_encode(['roles' => ['ADMIN'], 'users' => []]),
             'options_json' => $c['options_json'], 'sort_order' => intval($c['sort_order']) + 1, 'is_active' => 0];
    $pdo->prepare("INSERT INTO report_categories (`" . implode('`, `', array_keys($cols)) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")->execute(array_values($cols));
    $newId = intval($pdo->lastInsertId());
    // قالب کپی می‌شود تا قالبِ نوعِ تازه جدا قابلِ تغییر باشد
    $src = $c['template_path'] ? $siteRoot . '/' . ltrim($c['template_path'], '/') : null;
    if ($src && is_file($src)) {
        $rel = 'report_assets/templates/cat' . $newId . '_' . basename($src);
        if (@copy($src, $siteRoot . '/' . $rel)) $pdo->prepare("UPDATE report_categories SET template_path = ? WHERE id = ?")->execute([$rel, $newId]);
    }
    $pdo->prepare("INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, default_value, required, width, help, sort_order)
                   SELECT ?, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, default_value, required, width, help, sort_order
                     FROM report_fields WHERE category_id = ?")->execute([$newId, $c['id']]);
    $pdo->prepare("INSERT INTO report_visitor_categories (visitor_id, category_id) SELECT visitor_id, ? FROM report_visitor_categories WHERE category_id = ?")->execute([$newId, $c['id']]);
    vrs_audit($pdo, $user, "نوع گزارش «{$c['name']}» کپی شد");
    vr_out(['ok' => true, 'id' => $newId]);
}

if ($a === 'set_cat_delete') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM visit_reports WHERE category_id = ?");
    $cnt->execute([$c['id']]);
    if ($cnt->fetchColumn() > 0) vr_fail('برای این نوع گزارش، گزارشِ صادرشده وجود دارد؛ به‌جای حذف، آن را «غیرفعال» کنید.');
    $pdo->prepare("DELETE FROM report_fields WHERE category_id = ?")->execute([$c['id']]);
    $pdo->prepare("DELETE FROM report_visitor_categories WHERE category_id = ?")->execute([$c['id']]);
    $pdo->prepare("DELETE FROM report_insured_categories WHERE category_id = ?")->execute([$c['id']]);
    $pdo->prepare("DELETE FROM report_categories WHERE id = ?")->execute([$c['id']]);
    $dir = vr_asset_dir($c['id']);
    if (is_dir($dir)) { foreach (glob($dir . '/*') as $f) @unlink($f); @rmdir($dir); }
    vrs_audit($pdo, $user, "نوع گزارش «{$c['name']}» حذف شد");
    vr_out(['ok' => true]);
}

// ---- قالب Word ----
if ($a === 'set_template_upload') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    $f = vrs_upload('file', ['docx']);
    $z = new ZipArchive();
    if ($z->open($f['tmp_name']) !== true || $z->locateName('word/document.xml') === false) vr_fail('این فایل یک قالبِ Word (docx) معتبر نیست.');
    $z->close();
    $base = preg_replace('/[^\p{L}\p{N}_.\- ]+/u', '_', pathinfo($f['name'], PATHINFO_FILENAME)) ?: 'template';
    $rel = 'report_assets/templates/cat' . $c['id'] . '_' . $base . '.docx';
    $abs = $siteRoot . '/' . $rel;
    // قالبِ قبلیِ همین نوع (اگر نوعِ دیگری از آن استفاده نمی‌کند) با (old) کنار می‌رود
    if ($c['template_path']) {
        $old = $siteRoot . '/' . ltrim($c['template_path'], '/');
        $used = $pdo->prepare("SELECT COUNT(*) FROM report_categories WHERE template_path = ? AND id <> ?");
        $used->execute([$c['template_path'], $c['id']]);
        if (is_file($old) && !$used->fetchColumn()) @rename($old, vr_old_name($old));
    }
    if (is_file($abs)) @rename($abs, vr_old_name($abs));
    if (!move_uploaded_file($f['tmp_name'], $abs)) vr_fail('ذخیره‌ی قالب ممکن نشد.');
    $pdo->prepare("UPDATE report_categories SET template_path = ?, template_orig_name = ?, updated_at = NOW() WHERE id = ?")->execute([$rel, $f['name'], $c['id']]);
    $c = vr_category($pdo, $c['id']);
    try { [$layout] = vr_layout($pdo, $c, true); } catch (Throwable $e) { vr_fail('قالب ذخیره شد ولی خوانده نشد: ' . $e->getMessage()); }
    vrs_audit($pdo, $user, "قالب Word برای «{$c['name']}» بارگذاری شد ({$f['name']})");
    vr_out(['ok' => true, 'template' => vrs_template_info($pdo, vr_category($pdo, $c['id']), $siteRoot), 'warnings' => $layout['warnings'] ?? [], 'pages' => $layout['pages'] ?? 1]);
}

if ($a === 'set_template_reanalyze') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    [$layout] = vr_layout($pdo, $c, true);
    vr_out(['ok' => true, 'template' => vrs_template_info($pdo, vr_category($pdo, $c['id']), $siteRoot), 'warnings' => $layout['warnings'] ?? []]);
}

if ($a === 'set_template_preview') {
    $c = vrs_cat($pdo, $_GET['id'] ?? 0);
    $fields = vr_fields($pdo, $c['id']);
    $form = vr_sample_form($fields);
    $values = vr_build_values($c, $fields, $form, ['date' => jalali_from_gregorian_ts_dotted(time()), 'issuer_name' => $user['name'], 'report_no' => 'VR-نمونه']);
    $values = vr_sample_all_ticks($c, $fields, $values);
    if (!empty($_GET['names'])) foreach ($values as $k => &$v) if ($v !== '' && !in_array($v, [$c['tick_mark'] ?: '✔'], true)) $v = $k;
    unset($v);
    [$layout, $assetDir] = vr_layout($pdo, $c);
    $tmp = sys_get_temp_dir() . '/vr_prev_' . bin2hex(random_bytes(6)) . '.pdf';
    rpt_render_pdf($layout, $assetDir, $values, $tmp, vr_render_opts($pdo, $c, ['debug' => !empty($_GET['debug'])]));
    header('Content-Type: application/pdf');
    header("Content-Disposition: inline; filename=\"preview.pdf\"");
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

if ($a === 'set_template_download') {
    $c = vrs_cat($pdo, $_GET['id'] ?? 0);
    $abs = $c['template_path'] ? $siteRoot . '/' . ltrim($c['template_path'], '/') : null;
    vr_send_file($abs, $c['template_orig_name'] ?: basename((string)$abs));
}

// کادرهای متنیِ قالب (برای جابه‌جاییِ دقیق بدونِ Word)
if ($a === 'set_layout_boxes') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    [$layout] = vr_layout($pdo, $c);
    $boxes = [];
    foreach ($layout['items'] as $i => $it) {
        if ($it['type'] !== 'box') continue;
        $text = trim(implode(' ', array_map(fn($p) => $p['text'] ?? '', $it['paras'] ?? [])));
        if ($text === '') continue;
        $hasVars = strpos($text, '{{') !== false;
        if (!$hasVars && empty($data['all'])) continue;
        $boxes[] = ['index' => $i, 'page' => $it['page'] + 1, 'text' => mb_substr($text, 0, 120), 'x' => round($it['x'], 1), 'y' => round($it['y'], 1),
                    'w' => round($it['w'], 1), 'h' => round($it['h'], 1), 'dx' => $it['dx'] ?? 0, 'dy' => $it['dy'] ?? 0, 'hidden' => !empty($it['hidden'])];
    }
    vr_out(['ok' => true, 'boxes' => $boxes, 'page' => $layout['page'], 'pages' => $layout['pages']]);
}
// ویرایشگرِ گرافیکیِ قالب: همه‌ی عکس‌ها (پس‌زمینه‌ی فرم) و کادرهای متنیِ همه‌ی صفحه‌ها
if ($a === 'set_layout_editor') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    [$layout] = vr_layout($pdo, $c);
    $items = [];
    foreach ($layout['items'] as $i => $it) {
        $base = ['index' => $i, 'type' => $it['type'], 'page' => $it['page'], 'x' => round($it['x'], 2), 'y' => round($it['y'], 2),
                 'w' => round($it['w'], 2), 'h' => round($it['h'], 2), 'dx' => floatval($it['dx'] ?? 0), 'dy' => floatval($it['dy'] ?? 0), 'hidden' => !empty($it['hidden'])];
        if ($it['type'] === 'image') {
            $items[] = $base + ['file' => $it['file'], 'crop' => $it['crop'] ?? [0, 0, 0, 0], 'behind' => !empty($it['behind'])];
            continue;
        }
        $orig = implode("\n", array_map(fn($p) => $p['text'] ?? '', $it['paras'] ?? []));
        $size = null; $font = '';
        foreach ($it['paras'] ?? [] as $p) foreach ($p['runs'] as $r) if ($size === null && trim($r['t']) !== '') { $size = $r['size'] ?: ($p['size'] ?: ($layout['defaults']['size'] ?? 11)); $font = $r['font']; }
        $items[] = $base + ['rot' => $it['rot'] ?? 0, 'orig' => $orig, 'text' => $it['text'] ?? null, 'font' => $it['font'] ?? '', 'fsize' => $it['fsize'] ?? null,
                            'size' => $size ?: ($layout['defaults']['size'] ?? 11), 'wfont' => $font, 'align' => $it['paras'][0]['align'] ?? 'right',
                            'has_vars' => strpos($orig . ($it['text'] ?? ''), '{{') !== false, 'empty' => trim($orig) === '',
                            'anchor' => $it['anchor'] ?? 't', 'ins' => $it['ins'] ?? [0, 0, 0, 0],
                            // شکل‌های بی‌متن (دایره/مربعِ توپُر، خط‌دور) هم در ویرایشگر انتخاب و جابه‌جا می‌شوند
                            'fill' => $it['fill'] ?? null, 'line' => $it['line'] ?? null, 'geom' => $it['geom'] ?? 'rect', 'showif' => $it['showif'] ?? '', 'clone' => !empty($it['clone']),
                            // متنی که واقعاً چاپ می‌شود (متنِ سفید در Word عمداً دیده نمی‌شود)
                            'printed' => implode("\n", array_map(fn($p) => implode('', array_map(fn($r) => ($r['color'] ?? '') === '#FFFFFF' ? '' : $r['t'], $p['runs'] ?? [])), $it['paras'] ?? []))];
    }
    $o = json_decode($c['options_json'] ?? '', true) ?: [];
    // داده‌ی نمونه برای حالتِ «نمایش با نمونه» + آخرین گزارش‌های واقعیِ همین نوع (برای نمونه‌ی واقعی)
    $fields = vr_fields($pdo, $c['id']);
    $sample = vr_sample_all_ticks($c, $fields, vr_build_values($c, $fields, vr_sample_form($fields), ['date' => jalali_from_gregorian_ts_dotted(time()), 'issuer_name' => $user['name'], 'report_no' => 'VR-نمونه']));
    $rs = $pdo->prepare("SELECT id, report_no, insured_name, plate_display FROM visit_reports WHERE category_id = ? AND status = 'ACTIVE' ORDER BY id DESC LIMIT 20");
    $rs->execute([$c['id']]);
    vr_out(['ok' => true, 'page' => $layout['page'], 'pages' => $layout['pages'], 'items' => $items, 'fonts' => vr_font_choices($pdo),
            'font_all' => $o['font_all'] ?? '', 'vars' => vr_expected_vars($c, $fields), 'sample' => $sample, 'reports' => $rs->fetchAll()]);
}
// مقدارهای یک گزارشِ واقعی برای نمایش در ویرایشگر
if ($a === 'set_layout_sample') {
    $st = $pdo->prepare("SELECT data_json FROM visit_reports WHERE id = ? AND category_id = ?");
    $st->execute([intval($data['report_id'] ?? 0), intval($data['id'] ?? 0)]);
    $j = $st->fetchColumn();
    if (!$j) vr_fail('گزارش پیدا نشد.');
    vr_out(['ok' => true, 'values' => json_decode($j, true) ?: []]);
}
// عکس‌های پس‌زمینه‌ی قالب برای ویرایشگر (پوشه‌ی report_assets از بیرون بسته است)
if ($a === 'set_layout_asset') {
    $c = vrs_cat($pdo, $_GET['id'] ?? 0);
    $abs = vr_asset_dir($c['id']) . '/' . basename((string)($_GET['file'] ?? ''));
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!is_file($abs) || !in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'], true)) { http_response_code(404); exit; }
    header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp'][$ext]);
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}
// کپی‌کردنِ یک کادر/شکل (مثلاً دایره‌ی توپُرِ «خوب» برای گزینه‌های «متوسط» و «بد») یا حذفِ کپی
if ($a === 'set_layout_clone') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    [$layout] = vr_layout($pdo, $c);
    // کادرِ تازه: متن/متغیر، تیک، یا دایره‌ی توپُر (در قالبِ Word نیست؛ فقط در PDF کشیده می‌شود)
    if (!empty($data['new'])) {
        $kind = in_array($data['new'], ['text', 'tick', 'dot'], true) ? $data['new'] : 'text';
        $var = trim((string)($data['var'] ?? ''));
        if ($var !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,60}$/', $var)) vr_fail('نامِ متغیر فقط حروف و عدد لاتین و _ است.');
        $page = max(0, min(intval($layout['pages']) - 1, intval($data['page'] ?? 0)));
        $size = $kind === 'tick' ? 11 : 10;
        $w = $kind === 'text' ? 110 : ($kind === 'tick' ? 14 : 9);
        $h = $kind === 'text' ? 18 : ($kind === 'tick' ? 14 : 9);
        $x = max(0, min($layout['page']['w'] - $w, floatval($data['x'] ?? 250)));
        $y = max(0, min($layout['page']['h'] - $h, floatval($data['y'] ?? 300)));
        $text = $kind === 'dot' ? '' : ($var !== '' ? '{{ ' . $var . ' }}' : trim((string)($data['text'] ?? 'متن')));
        $item = ['page' => $page, 'z' => 999999999, 'behind' => false, 'type' => 'box', 'x' => round($x, 2), 'y' => round($y, 2), 'w' => $w, 'h' => $h,
                 'rot' => 0, 'vert' => 'horz', 'ins' => [0.5, 0.5, 0.5, 0.5], 'anchor' => 'ctr',
                 'fill' => $kind === 'dot' ? '#000000' : null, 'line' => null, 'lineW' => 0, 'geom' => $kind === 'dot' ? 'ellipse' : 'rect',
                 'paras' => $text === '' ? [] : [['align' => 'center', 'bidi' => true, 'size' => $size,
                     'runs' => [['t' => $text, 'size' => $size, 'b' => false, 'color' => '#000000', 'font' => '']],
                     'text' => $text, 'has_vars' => strpos($text, '{{') !== false]],
                 'dx' => 0, 'dy' => 0, 'clone' => 1, 'id' => 'n' . bin2hex(random_bytes(3))];
        if ($kind === 'dot' && $var !== '') $item['showif'] = $var;
        $layout['items'][] = $item;
        $pdo->prepare("UPDATE report_categories SET layout_json = ? WHERE id = ?")->execute([json_encode($layout, JSON_UNESCAPED_UNICODE), $c['id']]);
        vrs_audit($pdo, $user, "کادرِ تازه در قالبِ «{$c['name']}» اضافه شد");
        vr_out(['ok' => true, 'index' => count($layout['items']) - 1]);
    }
    $i = intval($data['index'] ?? -1);
    if (!isset($layout['items'][$i]) || $layout['items'][$i]['type'] !== 'box') vr_fail('کادر پیدا نشد.');
    if (!empty($data['delete'])) {
        if (empty($layout['items'][$i]['clone'])) vr_fail('فقط کادرهای کپی‌شده حذف می‌شوند؛ کادرِ قالب را می‌توانید «پنهان» کنید.');
        array_splice($layout['items'], $i, 1);
        $newIndex = null;
    } else {
        $n = $layout['items'][$i];
        $n['clone'] = 1;
        $n['dx'] = round(floatval($n['dx'] ?? 0) + 14, 2);
        unset($n['hidden'], $n['showif']);
        $layout['items'][] = $n;
        $newIndex = count($layout['items']) - 1;
    }
    $pdo->prepare("UPDATE report_categories SET layout_json = ? WHERE id = ?")->execute([json_encode($layout, JSON_UNESCAPED_UNICODE), $c['id']]);
    vrs_audit($pdo, $user, "کادری در قالبِ «{$c['name']}» " . (!empty($data['delete']) ? 'حذف' : 'کپی') . ' شد');
    vr_out(['ok' => true, 'index' => $newIndex]);
}
// اعمالِ تنظیم‌های ویرایشگر (جابه‌جایی، پنهان، قلم، اندازه، متن، شرطِ نمایش) روی چیدمان - هم برای ذخیره و هم برای «خروجی دقیق»
function vrs_apply_adjust(array &$layout, array $items, array $fonts) {
    $n = 0;
    foreach ($items as $adj) {
        $i = intval($adj['index'] ?? -1);
        if (!isset($layout['items'][$i])) continue;
        $it = &$layout['items'][$i];
        $it['dx'] = round(max(-600, min(600, floatval($adj['dx'] ?? 0))), 2);
        $it['dy'] = round(max(-900, min(900, floatval($adj['dy'] ?? 0))), 2);
        if (!empty($adj['hidden'])) $it['hidden'] = 1; else unset($it['hidden']);
        if ($it['type'] === 'box') {
            if (array_key_exists('font', $adj)) { $f = trim((string)$adj['font']); if ($f !== '' && in_array($f, $fonts, true)) $it['font'] = $f; else unset($it['font']); }
            if (array_key_exists('showif', $adj)) {
                $sv = trim((string)$adj['showif']);
                if ($sv !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,60}$/', $sv)) $it['showif'] = $sv; else unset($it['showif']);
            }
            if (array_key_exists('fsize', $adj)) { $z = floatval($adj['fsize']); if ($z >= 3 && $z <= 72) $it['fsize'] = round($z, 1); else unset($it['fsize']); }
            if (array_key_exists('text', $adj)) {
                $t = $adj['text'];
                if ($t === null || trim((string)$t) === '' || (string)$t === ($adj['orig'] ?? null)) unset($it['text']);
                else $it['text'] = mb_substr(str_replace("\r", '', (string)$t), 0, 1000);
            }
        }
        unset($it);
        $n++;
    }
    return $n;
}

// «خروجی دقیق»: همین صفحه با تنظیم‌های ذخیره‌نشده‌ی ویرایشگر، دقیقاً با موتورِ ساختِ PDF کشیده و عکس می‌شود
if ($a === 'set_layout_render') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    [$layout, $assetDir] = vr_layout($pdo, $c);
    $fonts = array_column(vr_font_choices($pdo), 'value');
    vrs_apply_adjust($layout, (array)($data['items'] ?? []), $fonts);
    $page = max(0, min(intval($layout['pages']) - 1, intval($data['page'] ?? 0)));
    $one = $layout;
    $one['items'] = [];
    foreach ($layout['items'] as $it) if ($it['page'] === $page) { $it['page'] = 0; $one['items'][] = $it; }
    $one['pages'] = 1;
    $fields = vr_fields($pdo, $c['id']);
    $values = null;
    if (!empty($data['report_id'])) {
        $st = $pdo->prepare("SELECT data_json FROM visit_reports WHERE id = ? AND category_id = ?");
        $st->execute([intval($data['report_id']), $c['id']]);
        $values = json_decode((string)$st->fetchColumn(), true) ?: null;
    }
    if (!$values) $values = vr_sample_all_ticks($c, $fields, vr_build_values($c, $fields, vr_sample_form($fields), ['date' => jalali_from_gregorian_ts_dotted(time()), 'issuer_name' => $user['name'], 'report_no' => 'VR-نمونه']));
    $extra = [];
    if (array_key_exists('font_all', $data)) $extra['fontAll'] = in_array((string)$data['font_all'], $fonts, true) ? (string)$data['font_all'] : '';
    $tmp = sys_get_temp_dir() . '/vr_edit_' . bin2hex(random_bytes(6));
    rpt_render_pdf($one, $assetDir, $values, $tmp . '.pdf', vr_render_opts($pdo, $c, $extra));
    $zoom = max(0.5, min(4, floatval($data['zoom'] ?? 2)));
    $png = null;
    if (function_exists('proc_open')) {
        $py = vr_python_path($pdo);
        $env = ['PYTHONIOENCODING' => 'utf8', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => vr_home_dir($pdo), 'PYTHONWARNINGS' => 'ignore'];
        $proc = @proc_open([$py, $siteRoot . '/report_assets/pdf_page_png.py', $tmp . '.pdf', '0', (string)$zoom, $tmp . '.png'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (is_resource($proc)) {
            $start = time();
            while (($s = proc_get_status($proc)) && $s['running'] && time() - $start < 25) usleep(40000);
            if (!empty($s['running'])) proc_terminate($proc, 9);
            @fclose($pipes[1]); @fclose($pipes[2]); proc_close($proc);
            if (is_file($tmp . '.png') && filesize($tmp . '.png') > 0) $png = base64_encode(file_get_contents($tmp . '.png'));
        }
    }
    // اگر پایتون/PyMuPDF روی هاست نبود، خودِ PDF فرستاده می‌شود تا مرورگر (pdf.js) آن را بکشد
    $out = ['ok' => true, 'png' => $png, 'pdf' => $png ? null : base64_encode((string)@file_get_contents($tmp . '.pdf'))];
    @unlink($tmp . '.pdf'); @unlink($tmp . '.png');
    vr_out($out);
}
if ($a === 'set_layout_adjust') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    [$layout] = vr_layout($pdo, $c);
    $fonts = array_column(vr_font_choices($pdo), 'value');
    $n = vrs_apply_adjust($layout, (array)($data['items'] ?? []), $fonts);
    $pdo->prepare("UPDATE report_categories SET layout_json = ? WHERE id = ?")->execute([json_encode($layout, JSON_UNESCAPED_UNICODE), $c['id']]);
    if (array_key_exists('font_all', $data)) {
        $o = json_decode($c['options_json'] ?? '', true) ?: [];
        $fa = trim((string)$data['font_all']);
        $o['font_all'] = in_array($fa, $fonts, true) ? $fa : '';
        $pdo->prepare("UPDATE report_categories SET options_json = ? WHERE id = ?")->execute([json_encode($o, JSON_UNESCAPED_UNICODE), $c['id']]);
    }
    vrs_audit($pdo, $user, "کادرهای قالبِ «{$c['name']}» در ویرایشگر تنظیم شد");
    vr_out(['ok' => true, 'saved' => $n]);
}

// ---- الگوریتمِ استخراج (فایل‌های .py با همان نامِ اصلی، همه در یک پوشه) ----
if ($a === 'set_parser_upload') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    $f = vrs_upload('file', ['py']);
    $name = basename($f['name']);
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.py$/', $name)) vr_fail('نامِ فایلِ پارسر فقط باید حروف و عدد لاتین و _ باشد (مثل template_car.py) چون پارسرها با همین نام همدیگر را صدا می‌زنند.');
    if ($name === 'parser_runner.py') vr_fail('این نام رزرو شده است.');
    $src = file_get_contents($f['tmp_name']);
    if (strpos($src, 'def parse_pdf_fields') === false) vr_fail('در این فایل تابعِ parse_pdf_fields(raw_text) پیدا نشد.');
    $dir = $siteRoot . '/report_assets/parsers';
    @mkdir($dir, 0775, true);
    $abs = $dir . '/' . $name;
    $tmpCheck = sys_get_temp_dir() . '/vr_chk_' . bin2hex(random_bytes(4)) . '.py';
    copy($f['tmp_name'], $tmpCheck);
    $chk = @shell_exec('HOME=' . escapeshellarg(vr_home_dir($pdo)) . ' ' . escapeshellarg(vr_python_path($pdo)) . ' -m py_compile ' . escapeshellarg($tmpCheck) . ' 2>&1');
    @unlink($tmpCheck);
    if (trim((string)$chk) !== '') vr_fail('فایلِ پایتون خطای نگارشی دارد:' . "\n" . mb_substr(trim($chk), -800));
    $shared = [];
    $st = $pdo->prepare("SELECT name FROM report_categories WHERE parser_path = ? AND id <> ?");
    $st->execute(['report_assets/parsers/' . $name, $c['id']]);
    $shared = $st->fetchAll(PDO::FETCH_COLUMN);
    if (is_file($abs)) @rename($abs, vr_old_name($abs));
    if (!move_uploaded_file($f['tmp_name'], $abs)) vr_fail('ذخیره‌ی فایل ممکن نشد.');
    $pdo->prepare("UPDATE report_categories SET parser_path = ?, parser_orig_name = ?, updated_at = NOW() WHERE id = ?")->execute(['report_assets/parsers/' . $name, $name, $c['id']]);
    vrs_audit($pdo, $user, "الگوریتم استخراج برای «{$c['name']}» بارگذاری شد ({$name})");
    vr_out(['ok' => true, 'parser' => vrs_parser_info(vr_category($pdo, $c['id']), $siteRoot), 'shared_with' => $shared]);
}
if ($a === 'set_parser_select') {
    // استفاده از یکی از فایل‌هایی که از قبل در پوشه‌ی پارسرها هست (یا هیچ‌کدام = فقط پارسرِ عمومی)
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    $name = basename((string)($data['name'] ?? ''));
    if ($name !== '' && !is_file($siteRoot . '/report_assets/parsers/' . $name)) vr_fail('این فایل در پوشه‌ی پارسرها نیست.');
    $pdo->prepare("UPDATE report_categories SET parser_path = ?, parser_orig_name = ? WHERE id = ?")->execute([$name ? 'report_assets/parsers/' . $name : null, $name ?: null, $c['id']]);
    vrs_audit($pdo, $user, "الگوریتم استخراجِ «{$c['name']}»: " . ($name ?: 'فقط عمومی'));
    vr_out(['ok' => true]);
}
if ($a === 'set_parser_files') {
    $files = [];
    foreach (glob($siteRoot . '/report_assets/parsers/*.py') ?: [] as $p) if (strpos(basename($p), '(old') === false) $files[] = basename($p);
    vr_out(['ok' => true, 'files' => $files]);
}
if ($a === 'set_parser_download') {
    $c = vrs_cat($pdo, $_GET['id'] ?? 0);
    $abs = $c['parser_path'] ? $siteRoot . '/' . ltrim($c['parser_path'], '/') : null;
    vr_send_file($abs, basename((string)$abs));
}
if ($a === 'set_parser_test') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    $f = vrs_upload('file', ['pdf']);
    $tmp = sys_get_temp_dir() . '/vr_test_' . bin2hex(random_bytes(6)) . '.pdf';
    move_uploaded_file($f['tmp_name'], $tmp);
    $parser = $c['parser_path'] ? $siteRoot . '/' . ltrim($c['parser_path'], '/') : null;
    $t0 = microtime(true);
    $res = vr_run_parser($pdo, $tmp, ($parser && is_file($parser)) ? $parser : null);
    @unlink($tmp);
    $res['seconds'] = round(microtime(true) - $t0, 2);
    if (!empty($res['ok'])) $res['form'] = vr_map_parsed($res['data'] ?? [], vr_fields($pdo, $c['id']), vr_visitors($pdo, $c['id']));
    vr_out($res + ['ok' => false]);
}
if ($a === 'set_python_check') {
    $py = vr_python_path($pdo);
    $envPrefix = 'HOME=' . escapeshellarg(vr_home_dir($pdo)) . ' PYTHONWARNINGS=ignore PYTHONIOENCODING=utf8 ';
    $out = @shell_exec($envPrefix . escapeshellarg($py) . ' -c ' . escapeshellarg('import sys, warnings; warnings.simplefilter("ignore"); print("Python " + sys.version.split()[0])
for m, label in (("pymupdf", "PyMuPDF (لازم)"), ("pdfplumber", "pdfplumber (اختیاری)"), ("PyPDF2", "PyPDF2 (اختیاری)")):
    try:
        __import__(m); print(label + ": OK")
    except Exception:
        if m == "pymupdf":
            try:
                import fitz; print(label + ": OK"); continue
            except Exception: pass
        print(label + ": -")') . ' 2>&1');
    vr_out(['ok' => $out !== null && strpos((string)$out, 'PyMuPDF (لازم): OK') !== false, 'output' => trim((string)$out), 'python' => $py]);
}

// ---- فیلدها (جایگزینِ fields_config.xlsx) ----
if ($a === 'set_fields_save') {
    $c = vrs_cat($pdo, $data['id'] ?? 0);
    $types = vr_field_types();
    $rows = []; $keys = [];
    foreach (array_values((array)($data['fields'] ?? [])) as $i => $f) {
        $key = trim((string)($f['field_key'] ?? ''));
        $label = trim((string)($f['label'] ?? ''));
        $type = (string)($f['field_type'] ?? 'Entry');
        if ($key === '' && $label === '') continue;
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)) vr_fail("نامِ متغیرِ ردیف " . ($i + 1) . " («{$key}») فقط باید حروف و عدد لاتین و _ باشد و با حرف شروع شود.");
        if (isset($keys[$key])) vr_fail("نامِ متغیرِ «{$key}» تکراری است.");
        if ($label === '') vr_fail("برچسبِ فیلدِ «{$key}» را وارد کنید.");
        if (!isset($types[$type])) vr_fail("نوعِ فیلدِ «{$label}» نامعتبر است.");
        $keys[$key] = true;
        $opt = trim((string)($f['options'] ?? ''));
        if ($type === 'PartsStatus' && !vr_parts_list(['options' => $opt])) vr_fail("برای «{$label}» فهرستِ قطعات را به شکلِ «c1|شیشه جلو; c2|سپر جلو» وارد کنید.");
        if ($type === 'Combobox' && !vr_combo_options(['options' => $opt])) vr_fail("برای «{$label}» گزینه‌ها را (با ویرگول جدا) وارد کنید.");
        $showIf = trim((string)($f['show_if'] ?? ''));
        if ($showIf !== '' && !preg_match('/^\s*[A-Za-z0-9_]+\s*!?=\s*.*$/u', $showIf)) vr_fail("شرطِ نمایشِ «{$label}» باید مثلِ has_yadak=1 یا plate_type=سایر باشد.");
        $wv = trim((string)($f['words_var'] ?? ''));
        if ($wv !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $wv)) vr_fail("متغیرِ «به حروفِ» «{$label}» نامعتبر است.");
        $rows[] = [$c['id'], $key, mb_substr($label, 0, 150), $type, $opt !== '' ? $opt : null, ($mc = intval($f['max_chars'] ?? 0)) > 0 ? $mc : null,
                   ($ec = trim((string)($f['excel_column'] ?? ''))) !== '' ? mb_substr($ec, 0, 60) : null,
                   ($pk = trim((string)($f['parser_keys'] ?? ''))) !== '' ? mb_substr($pk, 0, 255) : null,
                   ($sec = trim((string)($f['section'] ?? ''))) !== '' ? mb_substr($sec, 0, 100) : null, $showIf !== '' ? $showIf : null,
                   vrs_parse_tick_map($f['tick_map'] ?? ''), $wv !== '' ? $wv : null,
                   ($dv = trim((string)($f['default_value'] ?? ''))) !== '' ? mb_substr($dv, 0, 255) : null, empty($f['required']) ? 0 : 1,
                   in_array($f['width'] ?? '', ['full', 'half', 'third'], true) ? $f['width'] : null,
                   ($hp = trim((string)($f['help'] ?? ''))) !== '' ? mb_substr($hp, 0, 255) : null, ($i + 1) * 10];
    }
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM report_fields WHERE category_id = ?")->execute([$c['id']]);
    $ins = $pdo->prepare("INSERT INTO report_fields (category_id, field_key, label, field_type, options, max_chars, excel_column, parser_keys, section, show_if, tick_map, words_var, default_value, required, width, help, sort_order)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) $ins->execute($r);
    $pdo->commit();
    vrs_audit($pdo, $user, "فیلدهای «{$c['name']}» ذخیره شد (" . count($rows) . ' فیلد)');
    vr_out(['ok' => true, 'category' => vrs_cat_out($pdo, vr_category($pdo, $c['id']), $siteRoot)]);
}

// ---- بازدیدکننده‌ها ----
if ($a === 'set_visitor_save') {
    $id = intval($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') vr_fail('نام بازدیدکننده را وارد کنید.');
    if ($id) $pdo->prepare("UPDATE report_visitors SET name = ?, is_active = ? WHERE id = ?")->execute([$name, empty($data['is_active']) ? 0 : 1, $id]);
    else { $pdo->prepare("INSERT INTO report_visitors (name, is_active) VALUES (?, ?)")->execute([$name, isset($data['is_active']) && !$data['is_active'] ? 0 : 1]); $id = intval($pdo->lastInsertId()); }
    vrs_set_categories($pdo, 'report_visitor_categories', 'visitor_id', $id, $data['categories'] ?? []);
    vrs_audit($pdo, $user, "بازدیدکننده «{$name}» ذخیره شد");
    vr_out(['ok' => true, 'id' => $id]);
}
if ($a === 'set_visitor_delete') {
    $id = intval($data['id'] ?? 0);
    $pdo->prepare("DELETE FROM report_visitor_categories WHERE visitor_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM report_visitors WHERE id = ?")->execute([$id]);
    vrs_audit($pdo, $user, "بازدیدکننده #{$id} حذف شد");
    vr_out(['ok' => true]);
}

// ---- بیمه‌گذاران (از صفر، یا از اشخاص/شرکت‌های موجود) ----
if ($a === 'set_insured_search') {
    $q = trim(p2e_digits((string)($data['q'] ?? '')));
    if (mb_strlen($q) < 2) vr_out(['ok' => true, 'results' => []]);
    $like = '%' . $q . '%';
    $out = [];
    $st = $pdo->prepare("SELECT id, full_name, national_code, mobile_number FROM persons WHERE full_name LIKE ? OR national_code LIKE ? OR mobile_number LIKE ? ORDER BY full_name LIMIT 15");
    $st->execute([$like, $like, $like]);
    foreach ($st->fetchAll() as $p) $out[] = ['source' => 'PERSON', 'source_id' => intval($p['id']), 'name' => $p['full_name'], 'national_id' => $p['national_code'], 'phone' => $p['mobile_number'], 'address' => null, 'kind' => 'شخص'];
    $st = $pdo->prepare("SELECT id, name, economic_code, phone, address FROM companies WHERE name LIKE ? OR economic_code LIKE ? ORDER BY name LIMIT 15");
    $st->execute([$like, $like]);
    foreach ($st->fetchAll() as $c) $out[] = ['source' => 'COMPANY', 'source_id' => intval($c['id']), 'name' => $c['name'], 'national_id' => $c['economic_code'], 'phone' => $c['phone'], 'address' => $c['address'], 'kind' => 'شرکت'];
    // بیمه‌گذارانِ درخواست‌های کارکنان (کسی که بیمه‌گذار است ولی خودش کاربرِ ربات نیست)
    $st = $pdo->prepare("SELECT MAX(id) AS id, insured_name, insured_national_id, insured_phone, MAX(insured_address) AS insured_address FROM policy_cases
                          WHERE insured_name IS NOT NULL AND (insured_name LIKE ? OR insured_national_id LIKE ?) GROUP BY insured_name, insured_national_id, insured_phone LIMIT 10");
    $st->execute([$like, $like]);
    foreach ($st->fetchAll() as $c) $out[] = ['source' => 'MANUAL', 'source_id' => null, 'name' => $c['insured_name'], 'national_id' => $c['insured_national_id'], 'phone' => $c['insured_phone'], 'address' => $c['insured_address'], 'kind' => 'بیمه‌گذارِ درخواست'];
    vr_out(['ok' => true, 'results' => $out]);
}
if ($a === 'set_insured_save') {
    $id = intval($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') vr_fail('نام بیمه‌گذار را وارد کنید.');
    $src = in_array($data['source'] ?? '', ['MANUAL', 'PERSON', 'COMPANY'], true) ? $data['source'] : 'MANUAL';
    $vals = [$name, ($v = trim(p2e_digits((string)($data['national_id'] ?? '')))) !== '' ? $v : null, ($v = trim(p2e_digits((string)($data['phone'] ?? '')))) !== '' ? $v : null,
             ($v = trim((string)($data['address'] ?? ''))) !== '' ? $v : null, $src, $src === 'MANUAL' ? null : (intval($data['source_id'] ?? 0) ?: null), isset($data['is_active']) && !$data['is_active'] ? 0 : 1];
    if ($id) $pdo->prepare("UPDATE report_insureds SET name = ?, national_id = ?, phone = ?, address = ?, source = ?, source_id = ?, is_active = ? WHERE id = ?")->execute([...$vals, $id]);
    else { $pdo->prepare("INSERT INTO report_insureds (name, national_id, phone, address, source, source_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute($vals); $id = intval($pdo->lastInsertId()); }
    vrs_set_categories($pdo, 'report_insured_categories', 'insured_id', $id, $data['categories'] ?? []);
    vrs_audit($pdo, $user, "بیمه‌گذار «{$name}» ذخیره شد");
    vr_out(['ok' => true, 'id' => $id]);
}
if ($a === 'set_insured_delete') {
    $id = intval($data['id'] ?? 0);
    $pdo->prepare("DELETE FROM report_insured_categories WHERE insured_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM report_insureds WHERE id = ?")->execute([$id]);
    vrs_audit($pdo, $user, "بیمه‌گذار #{$id} حذف شد");
    vr_out(['ok' => true]);
}

// ---- قلم‌ها: نامِ قلم در Word => فایل TTF ----
if ($a === 'set_font_upload') {
    $word = trim((string)($data['word_name'] ?? ''));
    if ($word === '') vr_fail('نامِ قلم (همان نامی که در Word انتخاب شده) را وارد کنید.');
    $reg = vrs_upload('regular', ['ttf', 'otf']);
    $dir = rpt_fonts_dir();
    @mkdir($dir, 0775, true);
    require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
    $mk = function ($f, $suffix) use ($dir, $word) {
        $base = 'rf' . substr(md5($word), 0, 8) . $suffix;
        $tmp = sys_get_temp_dir() . '/' . $base . '.' . $f['ext'];
        copy($f['tmp_name'], $tmp);
        $name = TCPDF_FONTS::addTTFfont($tmp, 'TrueTypeUnicode', '', 32, $dir . '/');
        @unlink($tmp);
        return $name ?: null;
    };
    $regName = $mk($reg, '');
    if (!$regName) vr_fail('این فایلِ قلم قابلِ تبدیل نبود (فایل TTF سالم لازم است).');
    $boldName = null;
    if (!empty($_FILES['bold']['tmp_name'])) $boldName = $mk(vrs_upload('bold', ['ttf', 'otf']), 'b');
    $family = $regName;
    $pdo->prepare("INSERT INTO report_fonts (word_name, family, regular_file, bold_file) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE family = VALUES(family), regular_file = VALUES(regular_file), bold_file = VALUES(bold_file)")
        ->execute([$word, $family, $regName . '.php', $boldName ? $boldName . '.php' : null]);
    vrs_audit($pdo, $user, "قلم «{$word}» بارگذاری شد");
    vr_out(['ok' => true]);
}
if ($a === 'set_font_delete') {
    $id = intval($data['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM report_fonts WHERE id = ?");
    $st->execute([$id]);
    if ($f = $st->fetch()) {
        foreach ([$f['regular_file'], $f['bold_file']] as $php) if ($php) { $b = rpt_fonts_dir() . '/' . basename($php, '.php'); foreach (['.php', '.z', '.ctg.z'] as $e) @unlink($b . $e); }
        $pdo->prepare("DELETE FROM report_fonts WHERE id = ?")->execute([$id]);
        vrs_audit($pdo, $user, "قلم «{$f['word_name']}» حذف شد");
    }
    vr_out(['ok' => true]);
}

// ---- تنظیماتِ عمومی ----
if ($a === 'set_options_save') {
    $py = trim((string)($data['python_path'] ?? ''));
    $pwd = trim((string)($data['excel_password'] ?? ''));
    if ($py !== '') vr_set_setting($pdo, 'report_python_path', $py);
    if ($pwd !== '') { vr_set_setting($pdo, 'report_excel_password', $pwd); vr_write_register($pdo, $siteRoot); }
    vrs_audit($pdo, $user, 'تنظیمات عمومی گزارش ذخیره شد');
    vr_out(['ok' => true]);
}

// ---- تاریخچه‌ی کارها ----
if ($a === 'set_logs') {
    $w = ["a.target_table = 'visit_reports'"]; $p = [];
    if (!empty($data['type'])) { $w[] = 'a.action_type = ?'; $p[] = (string)$data['type']; }
    if (($q = trim((string)($data['q'] ?? ''))) !== '') { $w[] = '(a.new_value LIKE ? OR u.full_name LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
    $st = $pdo->prepare("SELECT a.id, a.action_type, a.target_id, a.new_value, a.created_at, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                          WHERE " . implode(' AND ', $w) . " ORDER BY a.id DESC LIMIT 300");
    $st->execute($p);
    $acts = vr_audit_actions();
    vr_out(['ok' => true, 'types' => $acts, 'logs' => array_map(fn($l) => ['id' => intval($l['id']), 'type' => $l['action_type'], 'action' => $acts[$l['action_type']] ?? $l['action_type'],
        'report_id' => intval($l['target_id']) ?: null, 'note' => $l['new_value'], 'by' => $l['full_name'],
        'at' => jalali_from_gregorian_ts_dotted(strtotime($l['created_at'])) . ' ' . date('H:i', strtotime($l['created_at']))], $st->fetchAll())]);
}

vr_fail('عملیات نامعتبر است.');
