<?php
// فایل: api/_footer.php
// فوترِ پایینِ صفحه‌ها (پنل و صفحه‌ی ورود): «طراحی و توسعه» + «حقوق و نسخه» — متن، رنگ و نمایش/پنهان از «تنظیمات ← فوتر»
// ذخیره در system_settings با کلیدِ footer_cfg؛ اگر چیزی ذخیره نشده باشد همان فوترِ پیش‌فرضِ قبلی نشان داده می‌شود.

function footer_defaults($target) {
    $login = $target === 'login';
    return [
        'show_credit' => 1, 'credit_label' => $login ? 'طراحی و اجرا :' : 'طراحی و توسعه سیستم :', 'credit_name' => 'گروه توسعه بهیکس', 'credit_sub' => '(MMBehzadi)',
        'credit_link' => '',
        'show_copy' => 1, 'copy_text' => $login ? 'تمامی حقوق مادی و معنوی محفوظ می‌باشد - ۲۰۲۶ ©' : 'تمامی حقوق مادی و معنوی سیستم محفوظ می‌باشد - ۲۰۲۶ ©',
        'show_version' => 1, 'version_text' => '',   // خالی = نسخه‌ی جاریِ سایت
        // رنگ‌ها: خالی = رنگِ پیش‌فرضِ همان صفحه
        'label_color' => '', 'name_color' => '', 'sub_color' => '', 'copy_color' => '', 'box_bg' => '', 'box_border' => '',
    ];
}

function footer_cfg($pdo) {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'footer_cfg'");
        $st->execute();
        $c = json_decode((string)$st->fetchColumn(), true) ?: [];
    } catch (Throwable $e) {}
    return $c;
}

function footer_get($pdo, $target) {
    $target = $target === 'login' ? 'login' : 'panel';
    $saved = footer_cfg($pdo)[$target] ?? [];
    $d = footer_defaults($target);
    foreach ($d as $k => $v) if (array_key_exists($k, $saved)) $d[$k] = is_int($v) ? (int)!empty($saved[$k]) : (string)$saved[$k];
    return $d;
}

function footer_clean_color($v) {
    $v = trim((string)$v);
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '';
}

function footer_save($pdo, array $in) {
    $out = [];
    foreach (['panel', 'login'] as $t) {
        $src = is_array($in[$t] ?? null) ? $in[$t] : [];
        $row = [];
        foreach (footer_defaults($t) as $k => $v) {
            if (!array_key_exists($k, $src)) { $row[$k] = $v; continue; }
            if (is_int($v)) $row[$k] = !empty($src[$k]) ? 1 : 0;
            elseif (substr($k, -6) === '_color' || in_array($k, ['box_bg', 'box_border'], true)) $row[$k] = footer_clean_color($src[$k]);
            elseif ($k === 'credit_link') { $u = trim((string)$src[$k]); $row[$k] = preg_match('~^https?://[^\s"<>]+$~i', $u) ? mb_substr($u, 0, 300) : ''; }
            else $row[$k] = mb_substr(trim(strip_tags((string)$src[$k])), 0, 200);
        }
        $out[$t] = $row;
    }
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('footer_cfg', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([json_encode($out, JSON_UNESCAPED_UNICODE)]);
    return $out;
}

// HTMLِ فوتر؛ $version = نسخه‌ی جاریِ همان صفحه (مثلاً «Version:1.4.0»)، $cls = کلاس‌های پیش‌فرضِ همان صفحه
function footer_html($pdo, $target, $version, array $cls = []) {
    $f = footer_get($pdo, $target);
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $sty = function (array $p) { $p = array_filter($p, function ($v) { return $v !== ''; }); return $p ? ' style="' . implode(';', array_map(function ($k, $v) { return $k . ':' . $v; }, array_keys($p), $p)) . '"' : ''; };
    $html = '';
    if ($f['show_credit'] && ($f['credit_label'] !== '' || $f['credit_name'] !== '' || $f['credit_sub'] !== '')) {
        $name = $h($f['credit_name']);
        if ($f['credit_link'] !== '') $name = '<a href="' . $h($f['credit_link']) . '" target="_blank" rel="noopener" style="color:inherit">' . $name . '</a>';
        $html .= '<div class="footer-box group/author hover-target relative"' . $sty(['background' => $f['box_bg'], 'border-color' => $f['box_border']]) . '>'
            . ($f['credit_label'] !== '' ? '<span' . $sty(['color' => $f['label_color']]) . '>' . $h($f['credit_label']) . '</span>' : '')
            . ($f['credit_name'] !== '' ? '<span class="' . ($cls['name'] ?? 'text-blue-600') . ' font-black mr-1 relative inline-block cursor-none"' . $sty(['color' => $f['name_color']]) . '>' . $name . '</span>' : '')
            . ($f['credit_sub'] !== '' ? '<span class="' . ($cls['sub'] ?? 'text-slate-400') . ' text-xs ml-1" style="font-family: Arial, sans-serif;' . ($f['sub_color'] !== '' ? 'color:' . $f['sub_color'] : '') . '">' . $h($f['credit_sub']) . '</span>' : '')
            . '</div>';
    }
    $ver = $f['show_version'] ? ($f['version_text'] !== '' ? $f['version_text'] : $version) : '';
    if (($f['show_copy'] && $f['copy_text'] !== '') || $ver !== '') {
        $html .= '<p class="' . ($cls['copy'] ?? 'text-slate-500 text-[10px]') . '"' . $sty(['color' => $f['copy_color']]) . '>'
            . ($f['show_copy'] ? $h($f['copy_text']) : '')
            . ($ver !== '' ? ' <span dir="ltr" style="font-family: Arial, sans-serif;">- ' . $h($ver) . '</span>' : '') . '</p>';
    }
    return $html;
}
